<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

const NETWORK_CSV_HEADERS = [
    'Asset ID', 'Serial Number', 'Status', 'Brand', 'Model', 'MAC address', 'IP address',
    'PO date', 'PO number', 'DO date', 'DO number', 'Invoice Date', 'Invoice Number',
    'Purchase cost', 'Remarks',
    'Deployment Building', 'Deployment Level', 'Deployment Zone', 'Deployment Date',
    'Deployment Remarks', 'Deployment Staff ID',
    'Warranty Start Date', 'Warranty End Date', 'Warranty Remarks',
];

function norm_header(string $h): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $h)));
}

/** Map normalized CSV header label → internal field key */
function network_csv_header_map(): array
{
    return [
        'asset id' => 'asset_id',
        'serial number' => 'serial_num',
        'status' => 'status',
        'brand' => 'brand',
        'model' => 'model',
        'mac address' => 'mac_address',
        'ip address' => 'ip_address',
        'po date' => 'po_date',
        'po number' => 'po_num',
        'do date' => 'do_date',
        'do number' => 'do_num',
        'invoice date' => 'invoice_date',
        'invoice number' => 'invoice_num',
        'purchase cost' => 'purchase_cost',
        'remarks' => 'remarks',
        'deployment building' => 'deployment_building',
        'deployment level' => 'deployment_level',
        'deployment zone' => 'deployment_zone',
        'deployment date' => 'deployment_date',
        'deployment remarks' => 'deployment_remarks',
        'deployment staff id' => 'deployment_staff_id',
        'warranty start date' => 'warranty_start_date',
        'warranty end date' => 'warranty_end_date',
        'warranty remarks' => 'warranty_remarks',
        'asset_id' => 'asset_id',
        'serial_num' => 'serial_num',
        'status_id' => 'status_id',
        'mac_address' => 'mac_address',
        'ip_address' => 'ip_address',
        'po_num' => 'po_num',
        'do_num' => 'do_num',
        'invoice_num' => 'invoice_num',
        'deployment_building' => 'deployment_building',
        'deployment_level' => 'deployment_level',
        'deployment_zone' => 'deployment_zone',
        'deployment_date' => 'deployment_date',
        'deployment_remarks' => 'deployment_remarks',
        'deployment_staff_id' => 'deployment_staff_id',
        'building' => 'deployment_building',
        'level' => 'deployment_level',
        'zone' => 'deployment_zone',
        'deploy_building' => 'deployment_building',
        'deploy_level' => 'deployment_level',
        'deploy_zone' => 'deployment_zone',
        'deploy_date' => 'deployment_date',
        'deploy_remarks' => 'deployment_remarks',
        'deploy_staff_id' => 'deployment_staff_id',
        'warranty_start_date' => 'warranty_start_date',
        'warranty_end_date' => 'warranty_end_date',
        'warranty_remarks' => 'warranty_remarks',
    ];
}

/** @return array<string,int> internal key => column index */
function network_csv_col_index(array $headerRow): array
{
    $map = network_csv_header_map();
    $idx = [];
    foreach ($headerRow as $i => $cell) {
        $k = $map[norm_header((string)$cell)] ?? null;
        if ($k !== null && !isset($idx[$k])) {
            $idx[$k] = $i;
        }
    }
    $positional = [
        'asset_id', 'serial_num', 'status', 'brand', 'model', 'mac_address', 'ip_address',
        'po_date', 'po_num', 'do_date', 'do_num', 'invoice_date', 'invoice_num',
        'purchase_cost', 'remarks',
        'deployment_building', 'deployment_level', 'deployment_zone', 'deployment_date',
        'deployment_remarks', 'deployment_staff_id',
        'warranty_start_date', 'warranty_end_date', 'warranty_remarks',
    ];
    if (!isset($idx['asset_id'], $idx['serial_num']) && count($headerRow) >= 24) {
        foreach ($positional as $j => $key) {
            if (!isset($idx[$key])) {
                $idx[$key] = $j;
            }
        }
    }
    if (isset($idx['status_id']) && !isset($idx['status'])) {
        $idx['status'] = $idx['status_id'];
    }

    return $idx;
}

function network_csv_cell(array $row, array $idx, string $key): ?string
{
    if (!isset($idx[$key])) {
        return null;
    }
    $i = $idx[$key];
    if (!isset($row[$i])) {
        return null;
    }
    $v = trim((string)$row[$i]);

    return $v === '' ? null : $v;
}

function network_normalize_mac(?string $mac): ?string
{
    if ($mac === null || $mac === '') {
        return null;
    }
    $s = (string)$mac;
    $norm = preg_replace('/[:\-\.\s]/', '', $s);
    if ($norm === null) {
        return null;
    }

    return (strlen($norm) === 12 && ctype_xdigit($norm)) ? $s : null;
}

function network_csv_date_ymd(?string $raw): ?string
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $s = trim($raw);
    foreach (['Y-m-d', 'd-m-y', 'd-m-Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d instanceof DateTime) {
            $errors = DateTime::getLastErrors();
            if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                return $d->format('Y-m-d');
            }
        }
    }

    return null;
}

if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="nims-network-assets-template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, NETWORK_CSV_HEADERS);
    fputcsv($out, [
        '24260001', 'SN-NET-EX01', 'Online', 'Cisco', 'Catalyst 9200',
        'AA:BB:CC:DD:EE:FF', '10.20.30.40', '2026-01-10', 'PO-2026-101',
        '2026-01-12', 'DO-2026-88', '2026-01-15', 'INV-2026-500', '8500.00',
        'IDF-2 stack member', '', '', '', '', '', '',
        '', '', '',
    ]);
    fputcsv($out, [
        '24260002', 'SN-NET-EX02', 'Deploy', 'Aruba', '2930F',
        '11:22:33:44:55:66', '10.20.30.41', '2026-02-01', 'PO-2026-102',
        '2026-02-05', 'DO-2026-89', '2026-02-08', 'INV-2026-501', '6200.00',
        'Deployed switch', 'AVICENNA', 'Level 2', 'Lab 3A', '2026-03-01', 'Mounted in rack', '',
        '2026-03-01', '2029-03-01', '3-year warranty',
    ]);
    fclose($out);
    exit;
}

$results = [];
$total_ok = 0;
$total_err = 0;
$processed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $results[] = ['row' => 0, 'status' => 'error', 'msg' => 'File upload failed.'];
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $results[] = ['row' => 0, 'status' => 'error', 'msg' => 'Only .csv files are accepted.'];
    } else {
        $pdo = db();
        $statusByName = [];
        foreach ($pdo->query('SELECT status_id, name FROM status') as $r) {
            $statusByName[norm_header($r['name'])] = (int)$r['status_id'];
        }

        $handle = fopen($file['tmp_name'], 'r');
        $header = fgetcsv($handle);
        if ($header === false) {
            $results[] = ['row' => 0, 'status' => 'error', 'msg' => 'Empty CSV.'];
            fclose($handle);
        } else {
            $idx = network_csv_col_index($header);
            $row_num = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $row_num++;
                if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) {
                    continue;
                }

                $assetRaw = network_csv_cell($row, $idx, 'asset_id');
                $serial = network_csv_cell($row, $idx, 'serial_num');
                $statusRaw = network_csv_cell($row, $idx, 'status');

                $assetTrim = $assetRaw !== null ? trim($assetRaw) : '';
                if ($assetRaw === null || $assetTrim === '' || !ctype_digit($assetTrim)) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'asset_id' => $assetRaw ?? '—', 'serial' => $serial ?? '—',
                        'brand' => '—', 'msg' => 'Missing or invalid Asset ID (must be numeric).',
                    ];
                    $total_err++;
                    continue;
                }
                $aid = (int)$assetTrim;

                // Serial Number is optional (network.serial_num allows NULL).

                $status_id = null;
                if ($statusRaw !== null && $statusRaw !== '') {
                    $st = trim($statusRaw);
                    if ($st !== '' && ctype_digit($st)) {
                        $status_id = (int)$st;
                    } else {
                        $lk = norm_header($st);
                        $status_id = $statusByName[$lk] ?? null;
                    }
                }
                if ($status_id === null) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                        'brand' => trim((network_csv_cell($row, $idx, 'brand') ?? '') . ' ' . (network_csv_cell($row, $idx, 'model') ?? '')),
                        'msg' => 'Status missing or unknown (use status ID or name e.g. Online, 9).',
                    ];
                    $total_err++;
                    continue;
                }
                $chk = $pdo->prepare('SELECT status_id FROM status WHERE status_id = ?');
                $chk->execute([$status_id]);
                if (!$chk->fetch()) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                        'brand' => trim((network_csv_cell($row, $idx, 'brand') ?? '') . ' ' . (network_csv_cell($row, $idx, 'model') ?? '')),
                        'msg' => 'Invalid status_id — not in status table.',
                    ];
                    $total_err++;
                    continue;
                }

                $brand = network_csv_cell($row, $idx, 'brand');
                $model = network_csv_cell($row, $idx, 'model');
                $mac = network_csv_cell($row, $idx, 'mac_address');
                if ($mac !== null && network_normalize_mac($mac) === null) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                        'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                        'msg' => 'Invalid MAC (expect 12 hex digits, optional separators).',
                    ];
                    $total_err++;
                    continue;
                }
                $ip = network_csv_cell($row, $idx, 'ip_address');
                if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                        'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                        'msg' => 'Invalid IP address.',
                    ];
                    $total_err++;
                    continue;
                }

                $po_date = network_csv_cell($row, $idx, 'po_date');
                $do_date = network_csv_cell($row, $idx, 'do_date');
                $inv_date = network_csv_cell($row, $idx, 'invoice_date');
                $pc = network_csv_cell($row, $idx, 'purchase_cost');
                $purchase_cost = ($pc !== null && is_numeric($pc)) ? (float)$pc : null;

                $depBuilding = network_csv_cell($row, $idx, 'deployment_building');
                $depLevel = network_csv_cell($row, $idx, 'deployment_level');
                $depZone = network_csv_cell($row, $idx, 'deployment_zone');
                $depDateRaw = network_csv_cell($row, $idx, 'deployment_date');
                $depRemarks = network_csv_cell($row, $idx, 'deployment_remarks');
                $depStaffCsv = network_csv_cell($row, $idx, 'deployment_staff_id');
                $depAny = $depBuilding !== null || $depLevel !== null || $depZone !== null || $depDateRaw !== null || $depRemarks !== null || $depStaffCsv !== null;

                $depDate = null;
                $depStaff = null;
                if ($status_id === 3) {
                    if ($depBuilding === null || trim((string) $depBuilding) === '') {
                        $results[] = [
                            'row' => $row_num, 'status' => 'error',
                            'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                            'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                            'msg' => 'Deploy (3) requires deployment_building.',
                        ];
                        $total_err++;
                        continue;
                    }
                    $depBuilding = trim((string) $depBuilding);
                    $depLevel = $depLevel !== null && trim((string) $depLevel) !== '' ? trim((string) $depLevel) : '-';
                    $depZone = $depZone !== null && trim((string) $depZone) !== '' ? trim((string) $depZone) : '-';

                    if ($depDateRaw !== null && trim((string) $depDateRaw) !== '') {
                        $depDate = network_csv_date_ymd($depDateRaw);
                        if ($depDate === null) {
                            $results[] = [
                                'row' => $row_num, 'status' => 'error',
                                'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                                'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                                'msg' => 'Deploy (3): deployment_date must be YYYY-MM-DD or DD-MM-YY.',
                            ];
                            $total_err++;
                            continue;
                        }
                    } else {
                        $depDate = date('Y-m-d');
                    }

                    $depStaff = $depStaffCsv ?? (string)($_SESSION['staff_id'] ?? '');
                    if ($depStaff === '') {
                        $results[] = [
                            'row' => $row_num, 'status' => 'error',
                            'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                            'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                            'msg' => 'Deploy needs deployment_staff_id or importer session staff_id.',
                        ];
                        $total_err++;
                        continue;
                    }
                    $staffChk = $pdo->prepare('SELECT 1 FROM users WHERE staff_id = ? LIMIT 1');
                    $staffChk->execute([$depStaff]);
                    if (!$staffChk->fetchColumn()) {
                        $results[] = [
                            'row' => $row_num, 'status' => 'error',
                            'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                            'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                            'msg' => 'deployment_staff_id not found in users.',
                        ];
                        $total_err++;
                        continue;
                    }
                } elseif ($depAny) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                        'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                        'msg' => 'Deployment columns are only allowed for status Deploy (3).',
                    ];
                    $total_err++;
                    continue;
                }

                $wStartRaw = network_csv_cell($row, $idx, 'warranty_start_date');
                $wEndRaw = network_csv_cell($row, $idx, 'warranty_end_date');
                $wRemarks = network_csv_cell($row, $idx, 'warranty_remarks');
                $anyWarranty = $wStartRaw !== null || $wEndRaw !== null || $wRemarks !== null;
                $wStart = null;
                $wEnd = null;
                if ($anyWarranty) {
                    if ($wStartRaw === null || $wEndRaw === null) {
                        $results[] = [
                            'row' => $row_num, 'status' => 'error',
                            'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                            'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                            'msg' => 'Warranty incomplete: provide both warranty_start_date and warranty_end_date (or leave all warranty fields empty).',
                        ];
                        $total_err++;
                        continue;
                    }
                    $wStart = network_csv_date_ymd($wStartRaw);
                    $wEnd = network_csv_date_ymd($wEndRaw);
                    if ($wStart === null || $wEnd === null) {
                        $results[] = [
                            'row' => $row_num, 'status' => 'error',
                            'asset_id' => (string)$aid, 'serial' => $serial ?? '—',
                            'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                            'msg' => 'Warranty dates must be YYYY-MM-DD or DD-MM-YY.',
                        ];
                        $total_err++;
                        continue;
                    }
                }

                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('
                        INSERT INTO network (
                            asset_id, serial_num, brand, model, mac_address, ip_address,
                            PO_DATE, PO_NUM, DO_DATE, DO_NUM,
                            INVOICE_DATE, INVOICE_NUM, PURCHASE_COST, status_id, remarks
                        ) VALUES (
                            :asset_id, :serial_num, :brand, :model, :mac_address, :ip_address,
                            :po_date, :po_num, :do_date, :do_num,
                            :invoice_date, :invoice_num, :purchase_cost, :status_id, :remarks
                        )
                    ');
                    $stmt->execute([
                        ':asset_id' => $aid,
                            ':serial_num' => $serial,
                        ':brand' => $brand,
                        ':model' => $model,
                        ':mac_address' => $mac,
                        ':ip_address' => $ip,
                        ':po_date' => $po_date,
                        ':po_num' => network_csv_cell($row, $idx, 'po_num'),
                        ':do_date' => $do_date,
                        ':do_num' => network_csv_cell($row, $idx, 'do_num'),
                        ':invoice_date' => $inv_date,
                        ':invoice_num' => network_csv_cell($row, $idx, 'invoice_num'),
                        ':purchase_cost' => $purchase_cost,
                        ':status_id' => $status_id,
                        ':remarks' => network_csv_cell($row, $idx, 'remarks'),
                    ]);
                    if ($status_id === 3) {
                        $stmtD = $pdo->prepare('
                            INSERT INTO network_deployment (
                                asset_id, building, level, zone, deployment_date, deployment_remarks, staff_id
                            ) VALUES (
                                :asset_id, :building, :level, :zone, :deployment_date, :deployment_remarks, :staff_id
                            )
                        ');
                        $stmtD->execute([
                            ':asset_id' => $aid,
                            ':building' => $depBuilding,
                            ':level' => $depLevel,
                            ':zone' => $depZone,
                            ':deployment_date' => $depDate,
                            ':deployment_remarks' => $depRemarks,
                            ':staff_id' => $depStaff,
                        ]);
                    }
                    if ($anyWarranty) {
                        $stmtW = $pdo->prepare('
                            INSERT INTO warranty (asset_id, asset_type, warranty_start_date, warranty_end_date, warranty_remarks)
                            VALUES (:asset_id, :asset_type, :start, :end, :remarks)
                        ');
                        $stmtW->execute([
                            ':asset_id' => $aid,
                            ':asset_type' => 'network',
                            ':start' => $wStart,
                            ':end' => $wEnd,
                            ':remarks' => $wRemarks,
                        ]);
                    }
                    $pdo->commit();
                    $results[] = [
                        'row' => $row_num, 'status' => 'ok',
                        'asset_id' => $aid, 'serial' => $serial ?? '—',
                        'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                        'msg' => 'Imported' . ($status_id === 3 ? ' (+ deployment)' : '') . ($anyWarranty ? ' (+ warranty)' : ''),
                    ];
                    $total_ok++;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $msg = str_contains($e->getMessage(), 'Duplicate')
                        ? 'Duplicate asset ID — skipped'
                        : 'DB error: ' . $e->getMessage();
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'asset_id' => $aid, 'serial' => $serial ?? '—',
                        'brand' => trim(($brand ?? '') . ' ' . ($model ?? '')),
                        'msg' => $msg,
                    ];
                    $total_err++;
                }
            }
            fclose($handle);
            $processed = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Import Network Assets - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --bg: #f0f4ff;
            --card-bg: #ffffff;
            --sidebar-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-border: #e2e8f0;
            --glass-panel: #f8faff;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .blob { position: fixed; border-radius: 50%; filter: blur(80px); pointer-events: none; z-index: 0; }
        .blob-1 { width: 500px; height: 500px; background: rgba(37,99,235,0.06); top: -100px; right: -100px; }
        .blob-2 { width: 400px; height: 400px; background: rgba(124,58,237,0.05); bottom: -80px; left: -80px; }

        .sidebar {
            width: 280px; min-height: 100vh;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--card-border);
            display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; bottom: 0;
            z-index: 100;
            box-shadow: 2px 0 20px rgba(15,23,42,0.06);
        }
        .sidebar-logo {
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem 1.75rem 1.25rem;
            border-bottom: 1px solid var(--card-border);
        }
        .sidebar-logo img { height: 42px; object-fit: contain; }
        .nav-group { display: flex; flex-direction: column; gap: 0.25rem; }
        .nav-menu { flex: 1; padding: 1.25rem 1rem; display: flex; flex-direction: column; gap: 0.25rem; overflow-y: auto; }
        .nav-item {
            display: flex; align-items: center; gap: 1rem;
            padding: 0.75rem 1.25rem; border-radius: 12px;
            color: var(--text-muted); text-decoration: none;
            font-weight: 500; font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .nav-item:hover, .nav-item.open { background: rgba(37,99,235,0.06); color: var(--primary); }
        .nav-item.active { background: rgba(37,99,235,0.1); color: var(--primary); font-weight: 600; }
        .nav-item i { font-size: 1.25rem; }
        .nav-dropdown { display: none; flex-direction: column; gap: 0.25rem; padding-left: 3.25rem; margin-top: -0.25rem; margin-bottom: 0.25rem; }
        .nav-dropdown.show { display: flex; }
        .nav-dropdown-item {
            padding: 0.6rem 1rem; border-radius: 8px;
            color: var(--text-muted); text-decoration: none;
            font-size: 0.85rem; font-weight: 500;
        }
        .nav-dropdown-item:hover { color: var(--primary); background: rgba(37,99,235,0.06); }
        .nav-dropdown-item.active { color: var(--primary); }
        .nav-item.open .chevron { transform: rotate(180deg); }
        .user-profile {
            padding: 1.25rem 1.75rem; border-top: 1px solid var(--card-border);
            display: flex; align-items: center; gap: 0.75rem;
            cursor: pointer; margin-top: auto;
        }
        .user-profile:hover { background: rgba(37,99,235,0.04); }
        .avatar {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif; font-weight: 700; color: #fff; font-size: 1rem;
        }
        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-size: 0.9rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role { font-size: 0.75rem; color: var(--primary); margin-top: 0.2rem; text-transform: uppercase; font-weight: 600; }

        .main-content {
            margin-left: 280px; flex: 1;
            padding: 2.5rem 3.5rem 5rem;
            max-width: calc(100vw - 280px);
            position: relative; z-index: 1;
        }
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--card-border); padding-bottom: 1.5rem;
        }
        .page-title h1 {
            font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700;
            display: flex; align-items: center; gap: 0.75rem;
        }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); margin-top: 0.25rem; font-size: 0.95rem; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 0.5rem;
            color: var(--text-muted); text-decoration: none; font-weight: 500;
            background: var(--glass-panel); padding: 0.6rem 1.2rem;
            border-radius: 12px; border: 1px solid var(--card-border);
        }
        .btn-back:hover { color: var(--primary); border-color: rgba(37,99,235,0.2); }

        .card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 20px; padding: 2rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            margin-bottom: 1.75rem;
        }
        .card-title {
            font-family: 'Outfit', sans-serif; font-size: 1.1rem; font-weight: 700;
            display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1.25rem;
        }
        .card-title i { color: var(--primary); font-size: 1.3rem; }

        .template-bar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.25rem; background: rgba(37,99,235,0.04);
            border: 1px solid rgba(37,99,235,0.15); border-radius: 12px;
            margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;
        }
        .template-bar span { font-size: 0.9rem; color: var(--text-muted); }
        .btn-template {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: var(--primary); color: #fff;
            padding: 0.55rem 1.2rem; border-radius: 10px;
            text-decoration: none; font-size: 0.88rem; font-weight: 600;
        }
        .btn-template:hover { filter: brightness(1.08); }

        .column-chips { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem; }
        .chip {
            background: var(--glass-panel); border: 1px solid var(--card-border);
            border-radius: 8px; padding: 0.25rem 0.7rem;
            font-size: 0.76rem; font-weight: 600; color: var(--text-muted);
        }
        .chip.required { border-color: rgba(37,99,235,0.3); color: var(--primary); background: rgba(37,99,235,0.06); }

        .upload-zone {
            border: 2.5px dashed var(--card-border);
            border-radius: 16px; padding: 3.5rem 2rem;
            text-align: center; cursor: pointer; background: var(--glass-panel);
            position: relative;
        }
        .upload-zone:hover, .upload-zone.dragover { border-color: var(--primary); background: rgba(37,99,235,0.04); }
        .upload-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
        .upload-icon {
            font-size: 3.5rem; color: var(--primary);
            background: rgba(37,99,235,0.08); border-radius: 50%;
            width: 80px; height: 80px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
        }
        .btn-link { color: var(--primary); text-decoration: underline; cursor: pointer; }

        .submit-bar { display: flex; align-items: center; justify-content: space-between; margin-top: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .btn {
            padding: 0.7rem 1.8rem; border-radius: 12px; font-weight: 600; font-size: 0.95rem;
            cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; box-shadow: 0 6px 20px rgba(37,99,235,0.35); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-secondary { background: var(--glass-panel); border: 1px solid var(--card-border); color: var(--text-muted); }

        .results-grid { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .result-stat {
            flex: 1; min-width: 120px; background: var(--card-bg);
            border: 1px solid var(--card-border); border-radius: 14px;
            padding: 1.1rem 1.5rem; text-align: center;
        }
        .result-stat .rs-num { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700; }
        .result-stat .rs-label { font-size: 0.78rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; margin-top: 0.2rem; }
        .rs-ok .rs-num { color: var(--success); }
        .rs-err .rs-num { color: var(--danger); }
        .rs-total .rs-num { color: var(--primary); }

        .preview-wrapper { overflow-x: auto; border-radius: 12px; border: 1px solid var(--card-border); }
        .preview-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .preview-table thead { background: var(--glass-panel); }
        .preview-table th, .preview-table td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid rgba(226,232,240,0.5); }
        .result-row-ok { background: rgba(16,185,129,0.04); }
        .result-row-err { background: rgba(239,68,68,0.04); }
        .badge-ok { background: rgba(16,185,129,0.12); color: var(--success); padding: 2px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }
        .badge-err { background: rgba(239,68,68,0.12); color: var(--danger); padding: 2px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
        }
    </style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div class="page-title">
            <h1><i class="ri-file-upload-line"></i> Bulk import network assets</h1>
            <p>CSV format matches <code>nims-network assets.csv</code> → <code>network</code> table.</p>
        </div>
        <a href="network.php" class="btn-back"><i class="ri-arrow-left-line"></i> Back to inventory</a>
    </header>

    <?php if ($processed): ?>
    <div class="card">
        <div class="card-title"><i class="ri-bar-chart-2-line"></i> Import summary</div>
        <div class="results-grid">
            <div class="result-stat rs-total"><div class="rs-num"><?= $total_ok + $total_err ?></div><div class="rs-label">Rows</div></div>
            <div class="result-stat rs-ok"><div class="rs-num"><?= $total_ok ?></div><div class="rs-label">Imported</div></div>
            <div class="result-stat rs-err"><div class="rs-num"><?= $total_err ?></div><div class="rs-label">Failed</div></div>
        </div>
        <div class="preview-wrapper">
            <table class="preview-table">
                <thead>
                    <tr>
                        <th>Row</th><th>Asset ID</th><th>Serial</th><th>Device</th><th>Result</th><th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr class="result-row-<?= $r['status'] ?>">
                        <td>#<?= (int)$r['row'] ?></td>
                        <td><strong><?= htmlspecialchars((string)($r['asset_id'] ?? '—')) ?></strong></td>
                        <td><?= htmlspecialchars((string)($r['serial'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($r['brand'] ?? '—')) ?></td>
                        <td><span class="badge-<?= $r['status'] === 'ok' ? 'ok' : 'err' ?>"><?= $r['status'] === 'ok' ? 'OK' : 'Fail' ?></span></td>
                        <td><?= htmlspecialchars($r['msg'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:1.5rem;display:flex;gap:1rem;flex-wrap:wrap;">
            <a href="networkCSV.php" class="btn btn-primary"><i class="ri-upload-2-line"></i> Import another</a>
            <a href="network.php" class="btn btn-secondary"><i class="ri-list-check"></i> View inventory</a>
        </div>
    </div>

    <?php else: ?>

    <div class="template-bar">
        <span><strong>Template</strong> uses the same headers as your sample CSV (Asset ID, Serial Number, Status, …).</span>
        <a href="?download_template=1" class="btn-template"><i class="ri-download-2-line"></i> Download template</a>
    </div>

    <div class="card">
        <div class="card-title"><i class="ri-table-line"></i> Columns</div>
        <div class="column-chips">
            <span class="chip required">Asset ID *</span>
            <span class="chip required">Status *</span>
            <span class="chip">Serial Number</span>
            <span class="chip">Brand</span>
            <span class="chip">Model</span>
            <span class="chip">MAC address</span>
            <span class="chip">IP address</span>
            <span class="chip">PO date / number</span>
            <span class="chip">DO date / number</span>
            <span class="chip">Invoice Date / Number</span>
            <span class="chip">Purchase cost</span>
            <span class="chip">Remarks</span>
            <span class="chip">Deployment Building</span>
            <span class="chip">Deployment Level</span>
            <span class="chip">Deployment Zone</span>
            <span class="chip">Deployment Date</span>
            <span class="chip">Deployment Remarks</span>
            <span class="chip">Deployment Staff ID</span>
            <span class="chip">Warranty Start Date</span>
            <span class="chip">Warranty End Date</span>
            <span class="chip">Warranty Remarks</span>
        </div>
        <p style="font-size:0.85rem;color:var(--text-muted);line-height:1.5;">
            <strong>Status</strong> may be a numeric <code>status_id</code> or a name from the <code>status</code> table (e.g. <code>Online</code>, <code>9</code>).
            Dates: <code>YYYY-MM-DD</code> (deployment date also accepts <code>DD-MM-YY</code>). MAC: 12 hex digits with optional separators.
            For status <strong>Deploy (3)</strong>, importer creates <code>network_deployment</code> and requires <code>Deployment Building</code> and <code>Deployment Staff ID</code> (or it falls back to the logged-in technician). If <code>Deployment Level</code>, <code>Deployment Zone</code>, or <code>Deployment Date</code> are blank, they default to <code>-</code>, <code>-</code>, and today.
            Warranty is optional: provide both <code>Warranty Start Date</code> and <code>Warranty End Date</code> (or leave both empty). Warranty dates accept <code>YYYY-MM-DD</code> or <code>DD-MM-YY</code>.
        </p>
    </div>

    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <div class="card">
            <div class="card-title"><i class="ri-upload-cloud-2-line"></i> Upload CSV</div>
            <div class="upload-zone" id="uploadZone">
                <input type="file" name="csv_file" id="csvFile" accept=".csv" required>
                <div class="upload-icon"><i class="ri-file-excel-2-line"></i></div>
                <h3 style="font-family:Outfit,sans-serif;font-size:1.15rem;margin-bottom:0.5rem;">Drag &amp; drop or browse</h3>
                <p style="color:var(--text-muted);font-size:0.9rem;">Accepts <strong>.csv</strong> only</p>
                <div id="fileChosen" style="display:none;margin-top:1rem;font-weight:600;color:var(--primary);"></div>
            </div>
            <div id="previewSection" style="display:none;margin-top:1.5rem;">
                <div class="card-title" style="margin-bottom:0.75rem;"><i class="ri-eye-line"></i> Preview (first 5 rows)</div>
                <div class="preview-wrapper">
                    <table class="preview-table"><thead><tr id="previewHead"></tr></thead><tbody id="previewBody"></tbody></table>
                </div>
                <p id="previewCount" style="margin-top:0.75rem;font-size:0.85rem;color:var(--text-muted);"></p>
            </div>
            <div class="submit-bar">
                <span id="fileInfoText" style="color:var(--text-muted);">No file selected</span>
                <button type="submit" class="btn btn-primary" id="submitBtn" disabled><i class="ri-upload-2-line"></i> Import</button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</main>

<script>
function toggleDropdown(el, e) {
    e.preventDefault();
    const g = el.closest('.nav-group');
    const d = g.querySelector('.nav-dropdown');
    el.classList.toggle('open');
    d.classList.toggle('show');
}
<?php if (!$processed): ?>
const zone = document.getElementById('uploadZone');
const fileInput = document.getElementById('csvFile');
const submitBtn = document.getElementById('submitBtn');
const chosen = document.getElementById('fileChosen');
const fileInfo = document.getElementById('fileInfoText');

if (zone) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('dragover');
        const f = e.dataTransfer.files[0];
        if (f) { fileInput.files = e.dataTransfer.files; handleFile(f); }
    });
    fileInput.addEventListener('change', () => { if (fileInput.files[0]) handleFile(fileInput.files[0]); });
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function handleFile(file) {
    if (!file.name.toLowerCase().endsWith('.csv')) {
        chosen.style.display = 'block';
        chosen.style.color = 'var(--danger)';
        chosen.textContent = '✗ Only .csv files.';
        submitBtn.disabled = true;
        return;
    }
    chosen.style.display = 'block';
    chosen.style.color = 'var(--primary)';
    chosen.textContent = '✓ ' + file.name + ' · ' + (file.size / 1024).toFixed(1) + ' KB';
    fileInfo.textContent = file.name + ' selected';
    submitBtn.disabled = false;
    const reader = new FileReader();
    reader.onload = function(ev) {
        const lines = ev.target.result.trim().split('\n').filter(l => l.trim());
        if (lines.length < 2) return;
        const headers = parseCSVLine(lines[0]);
        const rows = lines.slice(1);
        const n = Math.min(5, rows.length);
        document.getElementById('previewHead').innerHTML = headers.map(h => '<th>' + escHtml(h) + '</th>').join('');
        let body = '';
        for (let i = 0; i < n; i++) {
            const cells = parseCSVLine(rows[i]);
            body += '<tr>' + cells.map(c => {
                const v = (c === '' || c == null) ? '—' : c;
                return '<td>' + escHtml(v) + '</td>';
            }).join('') + '</tr>';
        }
        document.getElementById('previewBody').innerHTML = body;
        document.getElementById('previewCount').textContent = `Showing ${n} of ${rows.length} data row(s)`;
        document.getElementById('previewSection').style.display = 'block';
    };
    reader.readAsText(file);
}

function parseCSVLine(line) {
    const result = [];
    let cur = '', inQ = false;
    for (let i = 0; i < line.length; i++) {
        const ch = line[i];
        if (ch === '"') inQ = !inQ;
        else if (ch === ',' && !inQ) { result.push(cur.trim()); cur = ''; }
        else cur += ch;
    }
    result.push(cur.trim());
    return result;
}
<?php endif; ?>
</script>
</body>
</html>
