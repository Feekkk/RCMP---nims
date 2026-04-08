<?php

declare(strict_types=1);

require_once __DIR__ . '/../tcpdf/tcpdf.php';
require_once __DIR__ . '/../config/database.php';

const RETURN_ORG = 'UNIVERSITY KUALA LUMPUR ROYAL COLLEGE OF MEDICINE PERAK';
const RETURN_DEPT_LINE = 'INFORMATION TECHNOLOGY DEPARTMENT';
const RETURN_DOC_TITLE = 'RETURN FORM OF COMPANY\'S DESKTOP';

function return_logo_path(): string
{
    return __DIR__ . '/../public/unikl-logo.png';
}

function return_get(string $key, string $default = ''): string
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    $s = (string) $_GET[$key];
    $s = str_replace(["\0", "\r"], '', $s);
    return trim($s);
}

function return_app_key(): string
{
    $app = require __DIR__ . '/../config/app.php';
    return (string) ($app['app_key'] ?? 'dev-insecure-change-me');
}

function return_validate_sig(int $returnId, int $exp, string $sig): bool
{
    if ($returnId <= 0 || $exp <= 0 || $sig === '') {
        return false;
    }
    if ($exp < time()) {
        return false;
    }
    $payload = $returnId . '|' . $exp;
    $calc = hash_hmac('sha256', $payload, return_app_key());
    return hash_equals($calc, $sig);
}

/** @return array<string, mixed> */
function return_fetch_data(int $returnId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT
            hr.return_id,
            hr.return_date,
            hr.return_time,
            hr.return_place,
            hr.`condition` AS asset_condition,
            hr.return_remarks,
            hr.return_status_id,
            h.handover_id,
            h.asset_id,
            l.brand,
            l.model,
            l.serial_num,
            l.category,
            st.full_name AS recipient_name,
            st.employee_no AS employee_no,
            st.department AS department,
            rs.name AS return_status_name
        FROM handover_return hr
        LEFT JOIN handover_staff hs ON hs.handover_staff_id = hr.handover_staff_id
        INNER JOIN handover h ON h.handover_id = COALESCE(hs.handover_id, hr.handover_id)
        INNER JOIN laptop l ON l.asset_id = h.asset_id
        LEFT JOIN staff st ON st.employee_no = hs.employee_no
        LEFT JOIN status rs ON rs.status_id = hr.return_status_id
        WHERE hr.return_id = :rid
        LIMIT 1
    ');
    $stmt->execute([':rid' => $returnId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Return record not found.');
    }
    return $row;
}

function return_draw_header(TCPDF $pdf): void
{
    $lm = $pdf->getMargins()['left'];
    $y0 = $pdf->GetY();
    if ($y0 < 14) {
        $y0 = 14;
        $pdf->SetY($y0);
    }

    $path = return_logo_path();
    $logoH = 0.0;
    if (is_readable($path)) {
        $logoW = 22.0;
        $pdf->Image($path, $lm, $y0, $logoW, 0, 'PNG', '', '', true, 300, '', false, false, 0, false, false, false);
        $logoH = $pdf->getImageRBY() - $y0;
        if ($logoH <= 0) {
            $logoH = 24.0;
        }
    }

    $pdf->SetXY($lm, $y0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->MultiCell(0, 4.5, RETURN_ORG, 0, 'C', false, 1, null, null, true, 0, false, true, 0, 'M', false);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->MultiCell(0, 4.5, RETURN_DEPT_LINE, 0, 'C', false, 1, null, null, true, 0, false, true, 0, 'M', false);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->MultiCell(0, 5.5, RETURN_DOC_TITLE, 0, 'C', false, 1, null, null, true, 0, false, true, 0, 'M', false);

    $below = $y0 + $logoH + 3;
    if ($pdf->GetY() < $below) {
        $pdf->SetY($below);
    }
    $pdf->Ln(4);
}

function return_item_label(?string $category, int $assetId): string
{
    $c = $category !== null ? trim($category) : '';
    $type = 'Desktop / Laptop';
    if ($c !== '') {
        $lower = strtolower($c);
        if (str_contains($lower, 'desktop')) {
            $type = 'Desktop';
        } elseif (str_contains($lower, 'laptop') || str_contains($lower, 'notebook')) {
            $type = 'Laptop';
        } else {
            $type = $c;
        }
    }
    return $type . ' (refer asset ID ' . $assetId . ')';
}

function return_condition_cell(?string $condition): string
{
    $c = $condition !== null ? trim($condition) : '';
    if ($c === '') {
        return 'OK';
    }
    $lower = strtolower($c);
    if (str_contains($lower, 'damage')) {
        return 'Damage';
    }
    return 'OK';
}

function return_status_cell(int $statusId, ?string $statusName): string
{
    if ($statusId === 8) {
        return 'MISSING';
    }
    $n = $statusName !== null ? trim($statusName) : '';
    if ($n !== '' && stripos($n, 'lost') !== false) {
        return 'MISSING';
    }
    return 'RETURN';
}

function return_build_pdf(array $data): TCPDF
{
    $name = (string) ($data['recipient_name'] ?? '');
    $emp = (string) ($data['employee_no'] ?? '');
    $dept = (string) ($data['department'] ?? '');
    $designation = '';
    $brand = (string) ($data['brand'] ?? '');
    $model = (string) ($data['model'] ?? '');
    $sn = (string) ($data['serial_num'] ?? '');
    $assetId = (int) ($data['asset_id'] ?? 0);
    $category = isset($data['category']) ? (string) $data['category'] : null;
    $remarks = (string) ($data['return_remarks'] ?? '');
    $assetCondition = isset($data['asset_condition']) ? (string) $data['asset_condition'] : null;
    $returnStatusId = (int) ($data['return_status_id'] ?? 0);
    $returnStatusName = isset($data['return_status_name']) ? (string) $data['return_status_name'] : null;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetCreator('NIMS');
    $pdf->SetTitle('Return Form');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->setCellHeightRatio(1.12);

    $pdf->AddPage();
    return_draw_header($pdf);

    $lm = $pdf->getMargins()['left'];
    $rm = $pdf->getMargins()['right'];
    $w = $pdf->getPageWidth() - $lm - $rm;

    $pdf->SetFont('helvetica', 'B', 9);
    $colW = $w / 4;
    $hRow = 9.0;
    $pdf->Cell($colW, $hRow, 'Name', 1, 0, 'C');
    $pdf->Cell($colW, $hRow, 'Staff ID', 1, 0, 'C');
    $pdf->Cell($colW, $hRow, 'Designation', 1, 0, 'C');
    $pdf->Cell($colW, $hRow, 'Department', 1, 1, 'C');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell($colW, $hRow, $name !== '' ? $name : '—', 1, 0, 'C');
    $pdf->Cell($colW, $hRow, $emp !== '' ? $emp : '—', 1, 0, 'C');
    $pdf->Cell($colW, $hRow, $designation !== '' ? $designation : '—', 1, 0, 'C');
    $pdf->Cell($colW, $hRow, $dept !== '' ? $dept : '—', 1, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, '1. Asset Information\'s', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Ln(1);

    $itemLine = return_item_label($category, $assetId);
    $lines = [
        'i. Item Name:' => $itemLine,
        'ii. Brand Name:' => $brand,
        'iii. Model Name:' => $model,
        'iv. Serial Number:' => $sn,
        'v. Asset ID:' => (string) $assetId,
    ];
    foreach ($lines as $label => $val) {
        $pdf->SetX($lm + 4);
        $pdf->Cell(42, 6, $label, 0, 0, 'L');
        $pdf->MultiCell(0, 6, $val !== '' ? $val : '—', 0, 'L');
    }
    $pdf->Ln(4);

    $condMain = return_condition_cell($assetCondition);
    $statMain = return_status_cell($returnStatusId, $returnStatusName);
    $bullets = '• Desktop<br/>• Hard disk<br/>• Monitor<br/>• Mouse<br/>• Keyboard';
    $remarksHtml = $remarks !== '' ? nl2br(htmlspecialchars($remarks, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '&nbsp;';

    $html = '<table border="1" cellpadding="3" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:10pt;">'
        . '<tr>'
        . '<td width="52%" align="left">' . $bullets . '</td>'
        . '<td width="24%" align="center" valign="middle"><b>' . htmlspecialchars($condMain, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</b></td>'
        . '<td width="24%" align="center" valign="middle"><b>' . htmlspecialchars($statMain, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</b></td>'
        . '</tr>'
        . '<tr>'
        . '<td align="left">• Remarks<br/>' . $remarksHtml . '</td>'
        . '<td></td><td></td>'
        . '</tr></table>';

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Ln(6);

    $displayName = $name !== '' ? htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '______________________________';
    $stmt2 = '\'I <b>' . $displayName . '</b>, with IC/Passport No: _______________________________ return the hardware/peripherals as stated above in good/adverse conditions and checked perfectly by IT representative.\'';
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, '2. Return Statement', 0, 1, 'L');
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTMLCell(0, 0, '', '', $stmt2, 0, 1, false, true, 'L', true);

    $pdf->SetY(-18);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 4, 'University Kuala Lumpur Royal College of Medicine Perak — Information Technology Department', 0, 1, 'C');

    return $pdf;
}

function return_mail_pdf_to_technician(int $returnId, string $toEmail, string $toName): void
{
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid technician email address.');
    }
    require_once __DIR__ . '/../config/mailer.php';
    $data = return_fetch_data($returnId);
    $pdf = return_build_pdf($data);
    $pdfBytes = $pdf->Output('', 'S');
    $assetId = (int) ($data['asset_id'] ?? 0);
    smtp_send_return_completion_pdf($toEmail, $toName, $pdfBytes, $assetId);
}

function return_is_direct_http_request(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script === '') {
        return false;
    }
    $main = realpath($script);
    $self = realpath(__FILE__);
    return $main !== false && $self !== false && strcasecmp($main, $self) === 0;
}

if (return_is_direct_http_request()) {
    $returnId = (int) return_get('return_id', '0');
    $exp = (int) return_get('exp', '0');
    $sig = return_get('sig', '');

    try {
        if (!return_validate_sig($returnId, $exp, $sig)) {
            throw new RuntimeException('Invalid or expired link.');
        }

        $data = return_fetch_data($returnId);
        $pdf = return_build_pdf($data);
        $pdfBytes = $pdf->Output('', 'S');
        $assetId = (string) ($data['asset_id'] ?? '');

        $dest = return_get('dest', 'I');
        if ($dest !== 'I' && $dest !== 'D') {
            $dest = 'I';
        }
        header('Content-Type: application/pdf');
        if ($dest === 'D') {
            header('Content-Disposition: attachment; filename="UNIKL_RCMP_Return_Form_' . $assetId . '.pdf"');
        } else {
            header('Content-Disposition: inline; filename="UNIKL_RCMP_Return_Form_' . $assetId . '.pdf"');
        }
        header('Content-Length: ' . strlen((string) $pdfBytes));
        echo $pdfBytes;
    } catch (Throwable $e) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error: ' . $e->getMessage();
    }
}
