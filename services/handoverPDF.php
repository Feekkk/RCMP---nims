<?php

declare(strict_types=1);

require_once __DIR__ . '/../tcpdf/tcpdf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';

const HANDOVER_ORG = 'UNIVERSITY KUALA LUMPUR ROYAL COLLEGE OF MEDICINE PERAK';

function handover_logo_path(): string
{
    return __DIR__ . '/../public/unikl-logo.png';
}

/** @param array{underline?: bool} $opts */
function handover_draw_header(TCPDF $pdf, string $docTitle = '', array $opts = []): void
{
    $path = handover_logo_path();
    if (!is_readable($path)) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(0, 4, '[Logo missing: public/unikl-logo.png]', 0, 'C');
        $pdf->Ln(2);
    } else {
        $logoW = 22.0;
        $x = ($pdf->getPageWidth() - $logoW) / 2;
        $yTop = 14.0;
        $pdf->Image($path, $x, $yTop, $logoW, 0, 'PNG', '', '', true, 300, '', false, false, 0, false, false, false);
        $ih = $pdf->getImageRBY() - $yTop;
        if ($ih <= 0) {
            $ih = 30.0;
        }
        $pdf->SetY($yTop + $ih + 3);
    }

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->MultiCell(0, 4.5, HANDOVER_ORG, 0, 'C', false, 1, null, null, true, 0, false, true, 0, 'M', false);

    if ($docTitle !== '') {
        if (!empty($opts['underline'])) {
            $pdf->SetFont('helvetica', 'BU', 11);
        } else {
            $pdf->SetFont('helvetica', 'B', 11);
        }
        $pdf->MultiCell(0, 5, $docTitle, 0, 'C', false, 1, null, null, true, 0, false, true, 0, 'M', false);
    }
    $pdf->Ln(5);
}

function handover_get(string $key, string $default = ''): string
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    $s = (string) $_GET[$key];
    $s = str_replace(["\0", "\r"], '', $s);
    return trim($s);
}

function handover_app_key(): string
{
    $app = require __DIR__ . '/../config/app.php';
    return (string) ($app['app_key'] ?? 'dev-insecure-change-me');
}

function handover_validate_sig(int $handoverId, string $employeeNo, int $exp, string $sig): bool
{
    if ($handoverId <= 0 || $employeeNo === '' || $exp <= 0 || $sig === '') {
        return false;
    }
    if ($exp < time()) {
        return false;
    }
    $payload = $handoverId . '|' . $employeeNo . '|' . $exp;
    $calc = hash_hmac('sha256', $payload, handover_app_key());
    return hash_equals($calc, $sig);
}

function handover_fetch_data(int $handoverId, string $employeeNo): array
{
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT
            h.handover_id,
            h.asset_id,
            h.staff_id AS technician_staff_id,
            h.handover_date,
            h.handover_remarks,
            u.full_name AS technician_name,
            u.email AS technician_email,
            l.brand,
            l.model,
            l.serial_num,
            s.employee_no AS receiver_employee_no,
            s.full_name AS receiver_name,
            s.department AS receiver_department,
            s.email AS receiver_email,
            s.phone AS receiver_phone
        FROM handover h
        JOIN users u ON u.staff_id = h.staff_id
        JOIN laptop l ON l.asset_id = h.asset_id
        JOIN handover_staff hs ON hs.handover_id = h.handover_id
        JOIN staff s ON s.employee_no = hs.employee_no
        WHERE h.handover_id = :hid
          AND s.employee_no = :emp
        LIMIT 1
    ');
    $stmt->execute([':hid' => $handoverId, ':emp' => $employeeNo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Invalid handover confirmation link.');
    }
    return $row;
}

// --- Page 1: Software compliance ---

function handover_page1(TCPDF $pdf): void
{
    $pdf->AddPage();
    handover_draw_header($pdf, 'EMPLOYEE SOFTWARE COMPLIANCE STATEMENT', ['underline' => true]);

    $pdf->SetFont('helvetica', '', 10);
    $w = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
    $pdf->MultiCell($w, 5, 'UNIVERSITI KUALA LUMPUR ROYAL COLLEGE OF MEDICINE PERAK (UNIKL RCMP) software policy regarding the use of computer software.', 0, 'L');
    $pdf->Ln(4);

    $points = [
        'Computer software licensed from outside companies (e.g., Microsoft Corporation, Adobe Systems Incorporated) is the property of those companies. UNIKL RCMP does not own such software and is not permitted to reproduce it without proper authorization from the licensor.',
        'Employees must use computer software only in accordance with the applicable license agreements and must not install or use unauthorized copies of software.',
        'Employees shall not download or upload unauthorized computer software through the Internet or by any other means.',
        'Employees must notify their supervisors immediately upon becoming aware of any misuse of software or IT equipment, including any vandalism of software authenticity stickers or labels.',
        'Under the Copyright Act 1987, infringement may result in fines ranging from RM2,000 to RM20,000 and imprisonment for a term not exceeding five (5) years. UNIKL RCMP may also take disciplinary action, including termination of employment, against offenders.',
        'Employees who are uncertain whether copying or use of software is permitted should consult their supervisors before proceeding.',
    ];
    $n = 1;
    foreach ($points as $p) {
        $pdf->MultiCell($w, 5, $n . '. ' . $p, 0, 'L');
        $pdf->Ln(1);
        $n++;
    }

    $pdf->Ln(3);
    $pdf->MultiCell($w, 5, 'I am fully aware of the software use policies of UNIKL RCMP and agree to uphold those policies', 0, 'L');
    $pdf->Ln(12);

    $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 75, $pdf->GetY());
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 4, 'Employee Signature', 0, 1, 'L');
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(42, 6, 'Employee Name:', 0, 0, 'L');
    $pdf->Cell(0, 6, '_________________________________________________________________', 0, 1, 'L');
    $pdf->Cell(42, 6, 'Employee Designation:', 0, 0, 'L');
    $pdf->Cell(0, 6, '_________________________________________________________________', 0, 1, 'L');
    $pdf->Cell(42, 6, 'Staff ID:', 0, 0, 'L');
    $pdf->Cell(0, 6, '_________________________________________________________________', 0, 1, 'L');
    $pdf->Cell(42, 6, 'Date:', 0, 0, 'L');
    $pdf->Cell(0, 6, '_________________________________________________________________', 0, 1, 'L');
}

// --- Page 2: Handing over asset ---

function handover_page2(TCPDF $pdf, array $data): void
{
    $to = (string) ($data['receiver_name'] ?? '');
    $assetId = (string) ($data['asset_id'] ?? '');
    $brand = (string) ($data['brand'] ?? '');
    $model = (string) ($data['model'] ?? '');
    $sn = (string) ($data['serial_num'] ?? '');
    $techName = (string) ($data['technician_name'] ?? '');
    $handoverDate = (string) ($data['handover_date'] ?? '');

    $pdf->AddPage();
    handover_draw_header($pdf, 'HANDING OVER OF COMPANY\'S NOTEBOOK/DESKTOP');

    $lm = $pdf->getMargins()['left'];
    $rm = $pdf->getMargins()['right'];
    $pageW = $pdf->getPageWidth();
    $usable = $pageW - $lm - $rm;
    $gap = 3.0;
    $w1 = $usable * 0.70;
    $w2 = $usable - $w1 - $gap;
    $y = $pdf->GetY();
    $h = 14.0;
    $r = 2.5;

    $pdf->RoundedRect($lm, $y, $w1, $h, $r, '1111', 'DF', [], [200, 230, 255]);
    $pdf->RoundedRect($lm + $w1 + $gap, $y, $w2, $h, $r, '1111', 'DF', [], [230, 230, 230]);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetXY($lm + 2, $y + 3.5);
    $pdf->Cell($w1 - 4, 5, 'To: ' . ($to !== '' ? $to : '___________________________________________________________________'), 0, 0, 'L');
    $pdf->SetXY($lm + $w1 + $gap + 2, $y + 3.5);
    $pdf->Cell($w2 - 4, 5, 'ASSET ID: ' . ($assetId !== '' ? $assetId : '____________'), 0, 0, 'L');
    $pdf->SetY($y + $h + 6);

    $pdf->SetFont('helvetica', '', 10);
    $w = $usable;
    $pdf->MultiCell($w, 5, 'I hereby hand over the following: -', 0, 'L');
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->MultiCell($w, 5, '1. Asset Information\'s', 0, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Ln(1);

    $rows = [
        ['Item Name', 'Notebook/Desktop'],
        ['Brand Name', $brand],
        ['Model Name', $model],
        ['Serial Number', $sn],
        ['Adapter', ''],
        ['Remark', ''],
    ];
    foreach ($rows as [$label, $val]) {
        $pdf->SetX($lm + 5);
        $pdf->Cell(38, 6, $label . ':', 0, 0, 'L');
        $pdf->Cell(0, 6, (string) $val, 'B', 1, 'L');
    }
    $pdf->Ln(2);
    $pdf->SetX($lm);
    $pdf->MultiCell($w, 5, 'to be used for your daily work.', 0, 'L');
    $pdf->Ln(4);

    $pdf->MultiCell($w, 5, 'Please comply with the following company\'s requirements: -', 0, 'L');
    $pdf->Ln(2);

    $req = [
        'i.' => 'To comply with Company Notebook/Desktop Usage Policy. (Please refer to it.rcmp.unikl.edu.my)',
        'ii.' => 'To use this Notebook/Desktop for working purposes only.',
        'iii.' => 'To use for teaching purposes and use at appropriate place only. (If related)',
        'iv.' => 'Installation of any unauthorized/illegal software into this Notebook/Desktop is strictly prohibited.',
        'v.' => 'Any request for repair due to mechanical defect must be forwarded to the IT Department by filling in the requisition form and subject to approval by the management.',
        'vi.' => 'The user is responsible for repairing or replacement cost of the damage or loss due to negligence or intentional misconduct.',
    ];
    foreach ($req as $rom => $txt) {
        $pdf->MultiCell($w, 5, $rom . ' ' . $txt, 0, 'L');
        $pdf->Ln(0.5);
    }

    $pdf->Ln(8);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Hand over by:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Ln(1);
    $pdf->Cell(38, 6, 'Name:', 0, 0, 'L');
    $pdf->Cell(0, 6, $techName, 'B', 1, 'L');
    $pdf->Cell(38, 6, 'Designation:', 0, 0, 'L');
    $pdf->Cell(0, 6, '', 'B', 1, 'L');

    $pdf->Cell(38, 6, 'Date:', 0, 0, 'L');
    $pdf->Cell(60, 6, $handoverDate, 'B', 0, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 6, 'Signature: _________________________________', 0, 1, 'R');
}

// --- Page 3: Receipt & liability ---

function handover_page3(TCPDF $pdf, array $data): void
{
    $receiverName = (string) ($data['receiver_name'] ?? '');
    $receiverDept = (string) ($data['receiver_department'] ?? '');
    $receiverEmp = (string) ($data['receiver_employee_no'] ?? '');

    $pdf->AddPage();
    handover_draw_header($pdf, '');

    $lm = $pdf->getMargins()['left'];
    $w = $pdf->getPageWidth() - $lm - $pdf->getMargins()['right'];

    $pdf->SetFont('helvetica', '', 10);
    $pdf->MultiCell($w, 5, 'I, received the above mentioned Notebook/Desktop in satisfactory condition and agree to abide by the UNIKL Royal College of Medicine Perak regulations on the usage of company\'s equipment.', 0, 'L');
    $pdf->Ln(5);

    $pdf->Cell(52, 6, 'Name:', 0, 0, 'L');
    $pdf->Cell(0, 6, $receiverName, 'B', 1, 'L');
    $pdf->Cell(52, 6, 'Designation:', 0, 0, 'L');
    $pdf->Cell(0, 6, $receiverDept, 'B', 1, 'L');
    $pdf->Cell(52, 6, 'Staff no/IC No:', 0, 0, 'L');
    $pdf->Cell(0, 6, $receiverEmp, 'B', 1, 'L');
    $pdf->Ln(6);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Liability Statement :-', 0, 1, 'L');
    $pdf->Ln(2);

    $pdf->SetFont('helvetica', 'B', 10);
    $liability = '\'I, ' . ($receiverName !== '' ? $receiverName : '_______________________________________________') . ' agree to pay all costs associated with damage to the above peripherals or its associated peripheral equipment. I also agree to pay for replacement cost of the equipment should it be lost or stolen.\'';
    $pdf->MultiCell($w, 5, $liability, 0, 'L');
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->MultiCell($w, 5, 'My signature below indicates my agreement with the above liability statement', 0, 'L');

    $pdf->Ln(28);
    $y = $pdf->GetY();
    $pdf->Line($lm, $y, $lm + 78, $y);
    $x2 = $pdf->getPageWidth() - $pdf->getMargins()['right'] - 55;
    $pdf->Line($x2, $y, $x2 + 48, $y);
    $pdf->Ln(0.5);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(78, 4, 'Signature', 0, 0, 'L');
    $pdf->Cell(0, 4, 'Date', 0, 1, 'R');
}

// --- Build PDF ---

$handoverId = (int) handover_get('handover_id', '0');
$employeeNo = handover_get('employee_no', '');
$exp = (int) handover_get('exp', '0');
$sig = handover_get('sig', '');

try {
    if (!handover_validate_sig($handoverId, $employeeNo, $exp, $sig)) {
        throw new RuntimeException('Invalid or expired confirmation link.');
    }

    $data = handover_fetch_data($handoverId, $employeeNo);

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetCreator('NIMS');
    $pdf->SetTitle('Handover Form');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->setCellHeightRatio(1.15);

    handover_page1($pdf);
    handover_page2($pdf, $data);
    handover_page3($pdf, $data);

    // Generate bytes and email back to receiver.
    $pdfBytes = $pdf->Output('', 'S');
    $toEmail = (string)($data['receiver_email'] ?? '');
    $toName = (string)($data['receiver_name'] ?? '');
    $assetId = (string)($data['asset_id'] ?? '');

    if ($toEmail !== '') {
        $subject = 'Handover Form PDF - Asset ' . $assetId;
        $body = "Hi " . ($toName !== '' ? $toName : 'Staff') . ",\n\n"
            . "Attached is your handover form PDF.\n\n"
            . "Thank you.\n";
        smtp_send_with_attachment($toEmail, $subject, $body, (string)$pdfBytes, 'UNIKL_RCMP_Handover_Form_' . $assetId . '.pdf');
    }

    // Also show/download in browser.
    $dest = handover_get('dest', 'I');
    if ($dest !== 'I' && $dest !== 'D') {
        $dest = 'I';
    }
    header('Content-Type: application/pdf');
    if ($dest === 'D') {
        header('Content-Disposition: attachment; filename="UNIKL_RCMP_Handover_Form.pdf"');
    } else {
        header('Content-Disposition: inline; filename="UNIKL_RCMP_Handover_Form.pdf"');
    }
    header('Content-Length: ' . strlen((string)$pdfBytes));
    echo $pdfBytes;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error: ' . $e->getMessage();
}
