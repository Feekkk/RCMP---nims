<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

const AV_STATUS_IDS = [1, 2, 3, 5, 6, 7, 8];
const AV_ASSET_ID_CLASS = 88;

function av_asset_id_year_bounds(int $twoDigitYear): array
{
    $base = AV_ASSET_ID_CLASS * 100000 + $twoDigitYear * 1000;
    return [$base + 1, $base + 999];
}

function next_av_asset_id(PDO $pdo): ?int
{
    $yy = (int) date('y');
    [$lo, $hi] = av_asset_id_year_bounds($yy);
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(asset_id), 0) FROM av WHERE asset_id BETWEEN :lo AND :hi');
    $stmt->execute([':lo' => $lo, ':hi' => $hi]);
    $max = (int) $stmt->fetchColumn();
    $next = $max < $lo ? $lo : $max + 1;
    return $next <= $hi ? $next : null;
}

function norm_csv_header(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return $s ?? '';
}

function av_csv_resolve_status(string $raw, array $statusById, array $nameToId): int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    if (ctype_digit($raw)) {
        $id = (int) $raw;
        return isset($statusById[$id]) ? $id : 0;
    }
    $key = strtolower($raw);
    return $nameToId[$key] ?? 0;
}

function av_csv_device_label(?string $category, ?string $brand, ?string $model): string
{
    $t = trim(implode(' ', array_filter([$category ?? '', $brand ?? '', $model ?? ''], static fn ($x) => $x !== '')));
    return $t !== '' ? $t : '—';
}

function av_csv_date_ymd(?string $raw): ?string
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $s = trim($raw);
    // Accept common CSV date formats, normalize to Y-m-d for DB.
    $formats = ['Y-m-d', 'd-m-y', 'd-m-Y'];
    foreach ($formats as $fmt) {
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
    $headers = [
        'asset_id', 'category', 'brand', 'model', 'serial_number',
        'po_date', 'po_num', 'do_date', 'do_num', 'invoice_date', 'invoice_no', 'purchase', 'status_id', 'remarks',
        'deployment_building', 'deployment_level', 'deployment_zone', 'deployment_date', 'deployment_remarks', 'deployment_staff_id',
    ];
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="av_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    fputcsv($out, [
        'LEGACY-001', 'Projector', 'Epson', 'EB-L200X', 'SN-AV-EX01',
        '2024-01-15', 'PO-2024-001', '2024-01-20', 'DO-2024-001',
        '2024-01-25', 'INV-2024-001', '3200.00', '1', 'Non-deploy — leave deployment columns empty',
        '', '', '', '', '', '',
    ]);
    fputcsv($out, [
        'LEGACY-002', 'Speaker', 'Bose', 'S1 Pro', 'SN-AV-DEP01',
        '', '', '', '', '', '', '', '3', 'Deployed with full location row',
        'Main Building', 'Level 2', 'Lab 3A', '2024-06-01', 'Wall-mounted',
        '',
    ]);
    fclose($out);
    exit;
}

$results = [];
$total_ok = 0;
$total_err = 0;
$total_dup = 0;
$processed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $processed = true;
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $results[] = ['row' => 0, 'status' => 'error', 'legacy' => '—', 'new_id' => '—', 'serial' => '—', 'device' => '—', 'msg' => 'File upload failed. Please try again.'];
        $total_err++;
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $results[] = ['row' => 0, 'status' => 'error', 'legacy' => '—', 'new_id' => '—', 'serial' => '—', 'device' => '—', 'msg' => 'Only .csv files are accepted.'];
        $total_err++;
    } else {
        try {
            $pdo = db();
        } catch (Throwable $e) {
            $pdo = null;
            $results[] = ['row' => 0, 'status' => 'error', 'legacy' => '—', 'new_id' => '—', 'serial' => '—', 'device' => '—', 'msg' => 'Database unavailable: ' . $e->getMessage()];
            $total_err++;
        }

        if ($pdo !== null) {
            $statusById = [];
            $nameToId = [];
            $stRows = $pdo->query(
                'SELECT status_id, name FROM status WHERE status_id IN (' . implode(',', array_map('intval', AV_STATUS_IDS)) . ') ORDER BY status_id'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($stRows as $r) {
                $id = (int) $r['status_id'];
                $statusById[$id] = true;
                $nameToId[strtolower(trim((string) $r['name']))] = $id;
            }

            $handle = fopen($file['tmp_name'], 'r');
            if ($handle === false) {
                $results[] = ['row' => 0, 'status' => 'error', 'legacy' => '—', 'new_id' => '—', 'serial' => '—', 'device' => '—', 'msg' => 'Could not read the uploaded CSV.'];
                $total_err++;
            } else {
                $header = fgetcsv($handle);
                if (!is_array($header) || $header === []) {
                    $results[] = ['row' => 0, 'status' => 'error', 'legacy' => '—', 'new_id' => '—', 'serial' => '—', 'device' => '—', 'msg' => 'CSV is empty.'];
                    $total_err++;
                } else {
                    $keys = array_map(static fn ($h) => norm_csv_header((string) $h), $header);
                    if ((!in_array('asset_id', $keys, true) && !in_array('asset_id_old', $keys, true)) || !in_array('status_id', $keys, true)) {
                        $results[] = ['row' => 0, 'status' => 'error', 'legacy' => '—', 'new_id' => '—', 'serial' => '—', 'device' => '—', 'msg' => 'CSV must include asset_id (legacy id) or asset_id_old, status_id, and serial columns.'];
                        $total_err++;
                    } elseif (!in_array('serial_number', $keys, true) && !in_array('serial_num', $keys, true)) {
                        $results[] = ['row' => 0, 'status' => 'error', 'legacy' => '—', 'new_id' => '—', 'serial' => '—', 'device' => '—', 'msg' => 'CSV must include serial_number (or serial_num).'];
                        $total_err++;
                    } else {
                        $keyToIndex = [];
                        foreach ($keys as $i => $k) {
                            if ($k !== '') {
                                $keyToIndex[$k] = $i;
                            }
                        }

                        $get = function (array $row, string $k) use ($keyToIndex): ?string {
                            if (!array_key_exists($k, $keyToIndex)) {
                                return null;
                            }
                            $idx = (int) $keyToIndex[$k];
                            if (!array_key_exists($idx, $row)) {
                                return null;
                            }
                            $v = trim((string) $row[$idx]);
                            return $v === '' ? null : $v;
                        };

                        $stmtA = $pdo->prepare('
                            INSERT INTO av (
                                asset_id, asset_id_old, category, brand, model, serial_num, status_id,
                                PO_DATE, PO_NUM, DO_DATE, DO_NUM,
                                INVOICE_DATE, INVOICE_NUM, PURCHASE_COST, remarks
                            ) VALUES (
                                :asset_id, :asset_id_old, :category, :brand, :model, :serial_num, :status_id,
                                :po_date, :po_num, :do_date, :do_num,
                                :invoice_date, :invoice_num, :purchase_cost, :remarks
                            )
                        ');

                        $stmtD = $pdo->prepare('
                            INSERT INTO av_deployment (
                                asset_id, building, level, zone,
                                deployment_date, deployment_remarks, staff_id
                            ) VALUES (
                                :asset_id, :building, :level, :zone,
                                :deployment_date, :deployment_remarks, :staff_id
                            )
                        ');

                        $stmtUserExists = $pdo->prepare('SELECT 1 FROM users WHERE staff_id = ? LIMIT 1');

                        $staffId = (string) ($_SESSION['staff_id'] ?? '');
                        $row_num = 1;

                        $pick = static function (array $row, callable $get, array $keys): ?string {
                            foreach ($keys as $k) {
                                $v = $get($row, $k);
                                if ($v !== null && $v !== '') {
                                    return $v;
                                }
                            }
                            return null;
                        };

                        while (($row = fgetcsv($handle)) !== false) {
                            $row_num++;
                            if (!is_array($row) || $row === []) {
                                continue;
                            }

                            $legacyRaw = $get($row, 'asset_id') ?? $get($row, 'asset_id_old');
                            $category = $get($row, 'category') ?? 'AV';
                            $brand = $get($row, 'brand');
                            $model = $get($row, 'model');
                            $serial_raw = $get($row, 'serial_number') ?? $get($row, 'serial_num');
                            $device = av_csv_device_label($category, $brand, $model);

                            if ($legacyRaw === null || $legacyRaw === '') {
                                $results[] = ['row' => $row_num, 'status' => 'error', 'legacy' => '—', 'new_id' => '—', 'serial' => $serial_raw ?? '—', 'device' => $device, 'msg' => 'Missing legacy asset_id (or asset_id_old).'];
                                $total_err++;
                                continue;
                            }
                            $asset_id_old = substr($legacyRaw, 0, 64);

                            $asset_id = next_av_asset_id($pdo);
                            if ($asset_id === null) {
                                $results[] = ['row' => $row_num, 'status' => 'error', 'legacy' => $asset_id_old, 'new_id' => '—', 'serial' => $serial_raw ?? '—', 'device' => $device, 'msg' => 'No AV asset IDs left for this calendar year (max 999).'];
                                $total_err++;
                                continue;
                            }

                            $status_id = (int) ($get($row, 'status_id') ?? 0);
                            if (($status_id < 1 || !isset($statusById[$status_id])) && in_array('status', $keys, true)) {
                                $status_id = av_csv_resolve_status($get($row, 'status') ?? '', $statusById, $nameToId);
                            }

                            if ($serial_raw === null || $serial_raw === '' || $status_id < 1 || !isset($statusById[$status_id])) {
                                $results[] = ['row' => $row_num, 'status' => 'error', 'legacy' => $asset_id_old, 'new_id' => '—', 'serial' => $serial_raw ?? '—', 'device' => $device, 'msg' => 'Missing serial_number or invalid status_id for AV.'];
                                $total_err++;
                                continue;
                            }

                            $po_date = $get($row, 'po_date');
                            $po_num = $get($row, 'po_num');
                            $do_date = $get($row, 'do_date');
                            $do_num = $get($row, 'do_num');
                            $invoice_date = $get($row, 'invoice_date');
                            $invoice_num = $get($row, 'invoice_no') ?? $get($row, 'invoice_num');
                            $remarks = $get($row, 'remarks');
                            $purRaw = $get($row, 'purchase');
                            $purchase_cost = null;
                            if ($purRaw !== null && $purRaw !== '') {
                                $normPur = str_replace([',', ' '], '', $purRaw);
                                $purchase_cost = is_numeric($normPur) ? (float) $normPur : null;
                            }

                            $deployBuilding = $pick($row, $get, ['deployment_building', 'deploy_building', 'building']);
                            $deployLevel = $pick($row, $get, ['deployment_level', 'deploy_level', 'level']);
                            $deployZone = $pick($row, $get, ['deployment_zone', 'deploy_zone', 'zone']);
                            $deployDateRaw = $pick($row, $get, ['deployment_date', 'deploy_date']);
                            $deployRemarks = $pick($row, $get, ['deployment_remarks', 'deploy_remarks']);
                            $deployStaffCsv = $pick($row, $get, ['deployment_staff_id', 'deploy_staff_id']);

                            $anyDepField = $deployBuilding !== null || $deployLevel !== null || $deployZone !== null
                                || $deployDateRaw !== null || $deployRemarks !== null || $deployStaffCsv !== null;

                            $deployDate = null;
                            if ($status_id === 3) {
                                if ($deployBuilding === null || trim((string) $deployBuilding) === '') {
                                    $results[] = ['row' => $row_num, 'status' => 'error', 'legacy' => $asset_id_old, 'new_id' => '—', 'serial' => $serial_raw ?? '—', 'device' => $device, 'msg' => 'Deploy (3) requires deployment_building.'];
                                    $total_err++;
                                    continue;
                                }
                                $deployBuilding = trim((string) $deployBuilding);
                                $deployLevel = $deployLevel !== null && trim((string) $deployLevel) !== '' ? trim((string) $deployLevel) : '-';
                                $deployZone = $deployZone !== null && trim((string) $deployZone) !== '' ? trim((string) $deployZone) : '-';

                                if ($deployDateRaw !== null && trim((string) $deployDateRaw) !== '') {
                                    $deployDate = av_csv_date_ymd($deployDateRaw);
                                    if ($deployDate === null) {
                                        $results[] = ['row' => $row_num, 'status' => 'error', 'legacy' => $asset_id_old, 'new_id' => '—', 'serial' => $serial_raw ?? '—', 'device' => $device, 'msg' => 'Deploy (3): deployment_date must be YYYY-MM-DD or DD-MM-YY.'];
                                        $total_err++;
                                        continue;
                                    }
                                } else {
                                    $deployDate = date('Y-m-d');
                                }

                                $depStaff = $deployStaffCsv !== null ? trim((string) $deployStaffCsv) : $staffId;
                                if ($depStaff === '') {
                                    $results[] = ['row' => $row_num, 'status' => 'error', 'legacy' => $asset_id_old, 'new_id' => '—', 'serial' => $serial_raw ?? '—', 'device' => $device, 'msg' => 'Deploy needs deployment_staff_id or importer session staff_id.'];
                                    $total_err++;
                                    continue;
                                }
                                $stmtUserExists->execute([$depStaff]);
                                if (!$stmtUserExists->fetchColumn()) {
                                    $results[] = ['row' => $row_num, 'status' => 'error', 'legacy' => $asset_id_old, 'new_id' => '—', 'serial' => $serial_raw ?? '—', 'device' => $device, 'msg' => 'deployment_staff_id not found in users.'];
                                    $total_err++;
                                    continue;
                                }
                            } elseif ($anyDepField) {
                                $results[] = ['row' => $row_num, 'status' => 'error', 'legacy' => $asset_id_old, 'new_id' => '—', 'serial' => $serial_raw ?? '—', 'device' => $device, 'msg' => 'Deployment columns only for status_id 3 (Deploy); remove them or set status to 3.'];
                                $total_err++;
                                continue;
                            }

                            try {
                                $pdo->beginTransaction();
                                $stmtA->execute([
                                    ':asset_id' => $asset_id,
                                    ':asset_id_old' => $asset_id_old,
                                    ':category' => $category,
                                    ':brand' => $brand,
                                    ':model' => $model,
                                    ':serial_num' => $serial_raw,
                                    ':status_id' => $status_id,
                                    ':po_date' => $po_date,
                                    ':po_num' => $po_num,
                                    ':do_date' => $do_date,
                                    ':do_num' => $do_num,
                                    ':invoice_date' => $invoice_date,
                                    ':invoice_num' => $invoice_num,
                                    ':purchase_cost' => $purchase_cost,
                                    ':remarks' => $remarks,
                                ]);

                                $okMsg = 'Imported successfully';
                                if ($status_id === 3) {
                                    $depStaff = $deployStaffCsv !== null ? trim((string) $deployStaffCsv) : $staffId;
                                    $stmtD->execute([
                                        ':asset_id' => $asset_id,
                                        ':building' => $deployBuilding,
                                        ':level' => $deployLevel,
                                        ':zone' => $deployZone,
                                        ':deployment_date' => $deployDate,
                                        ':deployment_remarks' => $deployRemarks,
                                        ':staff_id' => $depStaff,
                                    ]);
                                    $okMsg .= ' (+ deployment)';
                                }
                                $pdo->commit();
                                $results[] = ['row' => $row_num, 'status' => 'ok', 'legacy' => $asset_id_old, 'new_id' => (string) $asset_id, 'serial' => $serial_raw, 'device' => $device, 'msg' => $okMsg];
                                $total_ok++;
                            } catch (PDOException $e) {
                                if ($pdo->inTransaction()) {
                                    $pdo->rollBack();
                                }
                                $msg = $e->getMessage();
                                if ((string) $e->getCode() === '23000' && stripos($msg, 'Duplicate') !== false) {
                                    $results[] = ['row' => $row_num, 'status' => 'dup', 'legacy' => $asset_id_old, 'new_id' => (string) $asset_id, 'serial' => $serial_raw, 'device' => $device, 'msg' => 'Duplicate — skipped (asset_id or unique conflict).'];
                                    $total_dup++;
                                } else {
                                    $results[] = ['row' => $row_num, 'status' => 'error', 'legacy' => $asset_id_old, 'new_id' => (string) $asset_id, 'serial' => $serial_raw, 'device' => $device, 'msg' => 'DB error: ' . $msg];
                                    $total_err++;
                                }
                            }
                        }
                        fclose($handle);
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Import AV — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary:     #2563eb;
            --secondary:   #7c3aed;
            --success:     #10b981;
            --danger:      #ef4444;
            --warning:     #f59e0b;
            --bg:          #f0f4ff;
            --card-bg:     #ffffff;
            --sidebar-bg:  #ffffff;
            --text-main:   #0f172a;
            --text-muted:  #64748b;
            --card-border: #e2e8f0;
            --glass-panel: #f8faff;
            --input-bg:    #f8faff;
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

        .blob {
            position: fixed; border-radius: 50%; filter: blur(80px);
            pointer-events: none; z-index: 0;
        }
        .blob-1 { width:500px; height:500px; background:rgba(37,99,235,0.06); top:-100px; right:-100px; }
        .blob-2 { width:400px; height:400px; background:rgba(124,58,237,0.05); bottom:-80px; left:-80px; }

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
            animation: fadeInDown 0.5s ease-out;
        }
        .page-title h1 {
            font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700;
            color: var(--text-main); display: flex; align-items: center; gap: 0.75rem;
        }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); font-size: 0.95rem; margin-top: 0.25rem; max-width: 42rem; line-height: 1.45; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 0.5rem;
            color: var(--text-muted); text-decoration: none; font-weight: 500;
            background: var(--glass-panel); padding: 0.6rem 1.2rem;
            border-radius: 12px; border: 1px solid var(--card-border);
            transition: all 0.2s ease;
        }
        .btn-back:hover { color: var(--primary); background: rgba(37,99,235,0.06); border-color: rgba(37,99,235,0.2); transform: translateX(-3px); }

        .card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 20px; padding: 2rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            margin-bottom: 1.75rem;
            animation: fadeInUp 0.5s ease-out;
        }
        .card-title {
            font-family: 'Outfit', sans-serif; font-size: 1.1rem;
            font-weight: 700; color: var(--text-main);
            display: flex; align-items: center; gap: 0.6rem;
            margin-bottom: 1.25rem;
        }
        .card-title i { color: var(--primary); font-size: 1.3rem; }

        .upload-zone {
            border: 2.5px dashed var(--card-border);
            border-radius: 16px; padding: 3.5rem 2rem;
            text-align: center; transition: all 0.25s ease;
            cursor: pointer; background: var(--glass-panel);
            position: relative;
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: var(--primary);
            background: rgba(37,99,235,0.04);
        }
        .upload-zone input[type="file"] {
            position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
        }
        .upload-icon {
            font-size: 3.5rem; color: var(--primary);
            background: rgba(37,99,235,0.08); border-radius: 50%;
            width: 80px; height: 80px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
        }
        .upload-zone h3 { font-family: 'Outfit',sans-serif; font-size: 1.2rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.5rem; }
        .upload-zone p { color: var(--text-muted); font-size: 0.9rem; }
        .upload-zone .file-chosen { margin-top: 1rem; font-weight: 600; color: var(--primary); font-size: 0.95rem; }
        .upload-zone .btn-link { color: var(--primary); text-decoration: underline; cursor: pointer; }

        .template-bar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.25rem; background: rgba(37,99,235,0.04);
            border: 1px solid rgba(37,99,235,0.15); border-radius: 12px;
            margin-bottom: 1.5rem;
            gap: 1rem; flex-wrap: wrap;
        }
        .template-bar span { font-size: 0.9rem; color: var(--text-muted); }
        .template-bar strong { color: var(--text-main); }
        .btn-template {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: var(--primary); color: white;
            padding: 0.55rem 1.2rem; border-radius: 10px;
            text-decoration: none; font-size: 0.88rem; font-weight: 600;
            transition: all 0.2s ease;
        }
        .btn-template:hover { filter: brightness(1.1); transform: translateY(-1px); }

        .column-chips {
            display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;
        }
        .chip {
            background: var(--glass-panel); border: 1px solid var(--card-border);
            border-radius: 8px; padding: 0.25rem 0.7rem;
            font-size: 0.76rem; font-weight: 600; color: var(--text-muted);
            font-family: ui-monospace, monospace;
        }
        .chip.required { border-color: rgba(37,99,235,0.3); color: var(--primary); background: rgba(37,99,235,0.06); }

        .submit-bar {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 1.5rem; gap: 1rem; flex-wrap: wrap;
        }
        .file-info { font-size: 0.9rem; color: var(--text-muted); }
        .btn { padding: 0.7rem 1.8rem; border-radius: 12px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.25s ease; display: inline-flex; align-items: center; gap: 0.5rem; border: none; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; box-shadow: 0 6px 20px rgba(37,99,235,0.35); }
        .btn-primary:hover { transform: translateY(-2px); filter: brightness(1.08); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        .preview-wrapper { overflow-x: auto; border-radius: 12px; border: 1px solid var(--card-border); }
        .preview-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .preview-table thead { background: var(--glass-panel); }
        .preview-table th { padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--card-border); }
        .preview-table td { padding: 0.7rem 1rem; border-bottom: 1px solid rgba(226,232,240,0.5); color: var(--text-main); }
        .preview-table tbody tr:last-child td { border-bottom: none; }
        .preview-table tbody tr:hover { background: rgba(37,99,235,0.02); }
        .preview-count { font-size: 0.85rem; color: var(--text-muted); margin-top: 0.75rem; }

        .results-grid { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .result-stat {
            flex: 1; min-width: 100px; background: var(--card-bg);
            border: 1px solid var(--card-border); border-radius: 14px;
            padding: 1.1rem 1.5rem; text-align: center;
        }
        .result-stat .rs-num { font-family:'Outfit',sans-serif; font-size:2rem; font-weight:700; }
        .result-stat .rs-label { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); font-weight:600; margin-top:0.2rem; }
        .rs-ok .rs-num { color: var(--success); }
        .rs-err .rs-num { color: var(--danger); }
        .rs-dup .rs-num { color: var(--warning); }
        .rs-total .rs-num { color: var(--primary); }

        .result-row-ok  { background: rgba(16,185,129,0.04); }
        .result-row-dup { background: rgba(245,158,11,0.06); }
        .result-row-err { background: rgba(239,68,68,0.04); }
        .badge-ok  { background:rgba(16,185,129,0.12); color:var(--success);  padding:2px 10px; border-radius:20px; font-size:0.78rem; font-weight:600; }
        .badge-dup { background:rgba(245,158,11,0.15); color:#b45309; padding:2px 10px; border-radius:20px; font-size:0.78rem; font-weight:600; }
        .badge-err { background:rgba(239,68,68,0.12);  color:var(--danger);   padding:2px 10px; border-radius:20px; font-size:0.78rem; font-weight:600; }

        @keyframes fadeInDown { from { opacity:0; transform:translateY(-20px); } to { opacity:1; transform:translateY(0); } }
        @keyframes fadeInUp   { from { opacity:0; transform:translateY(20px); }  to { opacity:1; transform:translateY(0); } }
    </style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">

    <header class="page-header">
        <div class="page-title">
            <h1><i class="ri-file-upload-line"></i> Bulk Import AV</h1>
            <p>Upload a CSV to register multiple audio/visual assets. The <strong>asset_id</strong> column is the <strong>legacy</strong> id (stored as asset_id_old); the system assigns a new <strong>asset_id</strong> (88+YY+###) per row.</p>
        </div>
        <a href="av.php" class="btn-back"><i class="ri-arrow-left-line"></i> Back to AV inventory</a>
    </header>

    <?php if ($processed): ?>
    <div class="card">
        <div class="card-title"><i class="ri-bar-chart-2-line"></i> Import Summary</div>
        <div class="results-grid">
            <div class="result-stat rs-total">
                <div class="rs-num"><?= $total_ok + $total_err + $total_dup ?></div>
                <div class="rs-label">Total rows</div>
            </div>
            <div class="result-stat rs-ok">
                <div class="rs-num"><?= $total_ok ?></div>
                <div class="rs-label">Imported</div>
            </div>
            <div class="result-stat rs-dup">
                <div class="rs-num"><?= $total_dup ?></div>
                <div class="rs-label">Duplicates</div>
            </div>
            <div class="result-stat rs-err">
                <div class="rs-num"><?= $total_err ?></div>
                <div class="rs-label">Failed</div>
            </div>
        </div>

        <div class="preview-wrapper">
            <table class="preview-table">
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>Legacy ID</th>
                        <th>New asset ID</th>
                        <th>Serial</th>
                        <th>Device</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r):
                        $rowClass = $r['status'] === 'ok' ? 'ok' : ($r['status'] === 'dup' ? 'dup' : 'err');
                    ?>
                    <tr class="result-row-<?= $rowClass ?>">
                        <td>#<?= (int) $r['row'] ?></td>
                        <td><strong><?= htmlspecialchars((string) $r['legacy']) ?></strong></td>
                        <td><code style="font-weight:700;color:var(--primary);"><?= htmlspecialchars((string) $r['new_id']) ?></code></td>
                        <td><?= htmlspecialchars((string) $r['serial']) ?></td>
                        <td><?= htmlspecialchars((string) $r['device']) ?></td>
                        <td>
                            <?php if ($r['status'] === 'ok'): ?>
                                <span class="badge-ok">✓ Success</span>
                            <?php elseif ($r['status'] === 'dup'): ?>
                                <span class="badge-dup">⚠ Duplicate</span>
                            <?php else: ?>
                                <span class="badge-err">✗ Failed</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) $r['msg']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:1.5rem; display:flex; gap:1rem; flex-wrap:wrap;">
            <a href="avCSV.php" class="btn btn-primary"><i class="ri-upload-2-line"></i> Import another file</a>
            <a href="av.php" class="btn" style="background:var(--glass-panel);border:1px solid var(--card-border);color:var(--text-muted);"><i class="ri-list-check"></i> View AV inventory</a>
            <a href="avAdd.php" class="btn" style="background:var(--glass-panel);border:1px solid var(--card-border);color:var(--text-muted);"><i class="ri-add-line"></i> Single registration</a>
        </div>
    </div>

    <?php else: ?>

    <div class="template-bar">
        <span><strong>Need a template?</strong> Download the CSV with correct headers, a sample row, and notes. Legacy <code>asset_id</code> is required per row.</span>
        <a href="?download_template=1" class="btn-template"><i class="ri-download-2-line"></i> Download template</a>
    </div>

    <div class="card">
        <div class="card-title"><i class="ri-table-line"></i> Columns</div>
        <div class="column-chips">
            <span class="chip required">asset_id *</span>
            <span class="chip required">serial_number *</span>
            <span class="chip required">status_id *</span>
            <span class="chip">category</span>
            <span class="chip">brand</span>
            <span class="chip">model</span>
            <span class="chip">po_date</span>
            <span class="chip">po_num</span>
            <span class="chip">do_date</span>
            <span class="chip">do_num</span>
            <span class="chip">invoice_date</span>
            <span class="chip">invoice_no</span>
            <span class="chip">purchase</span>
            <span class="chip">remarks</span>
            <span class="chip">deployment_building</span>
            <span class="chip">deployment_level</span>
            <span class="chip">deployment_zone</span>
            <span class="chip">deployment_date</span>
            <span class="chip">deployment_remarks</span>
            <span class="chip">deployment_staff_id</span>
        </div>
        <p style="font-size:0.83rem;color:var(--text-muted);margin-top:0.5rem;">
            <i class="ri-information-line"></i>
            <code>asset_id</code> = previous/legacy id (saved as <code>asset_id_old</code>). You may use <code>asset_id_old</code> as the column name instead. Dates: <strong>YYYY-MM-DD</strong>.
            AV <code>status_id</code>: 1 Active, 2 Non-active, 3 Deploy, 5 Maintenance, 6 Faulty, 7 Disposed, 8 Lost. Optional <code>status</code> column resolves by name if present.
            <strong>Deploy (3)</strong> requires <code>deployment_building</code> only. If <code>deployment_level</code>, <code>deployment_zone</code>, or <code>deployment_date</code> are blank, the importer saves <code>-</code>, <code>-</code>, and today’s date. Optional <code>deployment_remarks</code> and <code>deployment_staff_id</code> (must exist in <code>users</code>, else the logged-in technician is used). Do not fill deployment columns unless status is 3.
        </p>
    </div>

    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <div class="card">
            <div class="card-title"><i class="ri-upload-cloud-2-line"></i> Upload CSV file</div>

            <div class="upload-zone" id="uploadZone">
                <input type="file" name="csv_file" id="csvFile" accept=".csv" required>
                <div class="upload-icon"><i class="ri-file-excel-2-line"></i></div>
                <h3>Drag &amp; drop your CSV here</h3>
                <p>or <span class="btn-link">click to browse</span> &nbsp;·&nbsp; <strong>.csv</strong> only</p>
                <div class="file-chosen" id="fileChosen" style="display:none;"></div>
            </div>

            <div id="previewSection" style="display:none; margin-top:1.5rem;">
                <div class="card-title" style="margin-bottom:1rem;"><i class="ri-eye-line"></i> Preview (first 5 rows)</div>
                <div class="preview-wrapper">
                    <table class="preview-table" id="previewTable">
                        <thead><tr id="previewHead"></tr></thead>
                        <tbody id="previewBody"></tbody>
                    </table>
                </div>
                <div class="preview-count" id="previewCount"></div>
            </div>

            <div class="submit-bar">
                <div class="file-info" id="fileInfoText">No file selected</div>
                <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                    <i class="ri-upload-2-line"></i> Import AV assets
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>

</main>

<script>
    function toggleDropdown(el, e) {
        e.preventDefault();
        const group = el.closest('.nav-group');
        const dropdown = group && group.querySelector('.nav-dropdown');
        if (!dropdown) return;
        el.classList.toggle('open');
        dropdown.classList.toggle('show');
    }

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
            if (f) {
                const dt = new DataTransfer();
                dt.items.add(f);
                fileInput.files = dt.files;
                handleFile(f);
            }
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files[0]) handleFile(fileInput.files[0]);
        });
    }

    function handleFile(file) {
        if (!file.name.toLowerCase().endsWith('.csv')) {
            chosen.style.display = 'block';
            chosen.style.color = 'var(--danger)';
            chosen.textContent = '✗ Only .csv files are accepted.';
            submitBtn.disabled = true;
            return;
        }
        chosen.style.display = 'block';
        chosen.style.color = 'var(--primary)';
        chosen.textContent = '✓ ' + file.name + ' · ' + (file.size / 1024).toFixed(1) + ' KB';
        fileInfo.textContent = file.name + ' selected';
        submitBtn.disabled = false;

        const reader = new FileReader();
        reader.onload = function (ev) {
            const lines = ev.target.result.trim().split('\n').filter(l => l.trim());
            if (lines.length < 2) return;

            const headers = parseCSVLine(lines[0]);
            const rows = lines.slice(1);
            const previewN = Math.min(5, rows.length);

            const head = document.getElementById('previewHead');
            head.innerHTML = headers.map(h => '<th>' + escapeHtml(h) + '</th>').join('');

            const body = document.getElementById('previewBody');
            body.innerHTML = '';
            for (let i = 0; i < previewN; i++) {
                const cells = parseCSVLine(rows[i]);
                body.innerHTML += '<tr>' + cells.map(c => '<td>' + (c ? escapeHtml(c) : '<span style="color:#cbd5e1">—</span>') + '</td>').join('') + '</tr>';
            }

            document.getElementById('previewCount').textContent =
                'Showing ' + previewN + ' of ' + rows.length + ' data row' + (rows.length !== 1 ? 's' : '') + ' (header excluded)';
            document.getElementById('previewSection').style.display = 'block';
        };
        reader.readAsText(file);
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function parseCSVLine(line) {
        const result = [];
        let cur = '', inQ = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (ch === '"') { inQ = !inQ; }
            else if (ch === ',' && !inQ) { result.push(cur.trim()); cur = ''; }
            else cur += ch;
        }
        result.push(cur.trim());
        return result;
    }
</script>
</body>
</html>
