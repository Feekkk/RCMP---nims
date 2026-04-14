<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)$_SESSION['role_id'] !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once __DIR__ . '/../config/laptop_asset_id.php';

// ── CSV Template Download ─────────────────────────────────────────────────────
if (isset($_GET['download_template'])) {
    $headers = ['asset_id','category','serial_num','brand','model','part_number','processor',
                'memory','storage','gpu','os','po_date','po_num','do_date','do_num',
                'invoice_date','invoice_num','purchase_cost','status_id','remarks',
                'handover_date','handover_technician_staff_id','handover_remarks','recipient_employee_no',
                'warranty_start_date','warranty_end_date','warranty_remarks','handover_place'];
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="laptop_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    fputcsv($out, ['','Notebook','SN-EXAMPLE01','Lenovo','ThinkPad T14','20W0004UMY',
                   'Intel Core i7-1165G7','16GB DDR4','512GB NVMe SSD','Intel Iris Xe',
                   'Windows 11 Pro','2024-01-15','PO-2024-001','2024-01-20','DO-2024-001',
                   '2024-01-25','INV-2024-001','4500.00','1','Good condition',
                   '', '', '', '',
                   '2024-01-01','2027-01-01','3 Year on-site','']);
    fputcsv($out, ['','Desktop IO','SN-DEPLOY-PLACE','Lenovo','ThinkCentre M90','','','','','',
                   '','','','','','','','3','Deployed — no handover row',
                   '','','','',
                   '', '', '','']);
    fputcsv($out, ['','Notebook','SN-DEPLOY-STAFF','Lenovo','ThinkPad T14s','','','','','',
                   '','','','','','','','3','Deployed with handover',
                   '2024-06-15','TECH001','Lab checkout','EMP001',
                   '', '', '','Building A / Lab 3']);
    fclose($out);
    exit;
}

// ── Process CSV Upload ─────────────────────────────────────────────────────────
$results    = [];
$total_ok   = 0;
$total_err  = 0;
$processed  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $results[] = ['row' => 0, 'status' => 'error', 'msg' => 'File upload failed. Please try again.'];
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $results[] = ['row' => 0, 'status' => 'error', 'msg' => 'Only .csv files are accepted.'];
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        $header = fgetcsv($handle);

        $expected = ['asset_id','category','serial_num','brand','model','part_number','processor',
                     'memory','storage','gpu','os','po_date','po_num','do_date','do_num',
                     'invoice_date','invoice_num','purchase_cost','status_id','remarks',
                     'handover_date','handover_technician_staff_id','handover_remarks','recipient_employee_no',
                     'warranty_start_date','warranty_end_date','warranty_remarks','handover_place'];

        if ($header === false || $header === []) {
            $results[] = ['row' => 0, 'status' => 'error', 'msg' => 'CSV is empty or unreadable.'];
            fclose($handle);
            $processed = true;
        } else {
        $colIndex = [];
        foreach ($header as $hi => $hcell) {
            if ($hi === 0 && is_string($hcell)) {
                $hcell = preg_replace('/^\xEF\xBB\xBF/', '', $hcell);
            }
            $nk = strtolower(trim((string)$hcell));
            if ($nk !== '' && !array_key_exists($nk, $colIndex)) {
                $colIndex[$nk] = $hi;
            }
        }

        $row_num  = 1;
        $pdo      = db();
        $sessionStaffId = (string)($_SESSION['staff_id'] ?? '');

        $stmtUserExists = $pdo->prepare('SELECT 1 FROM users WHERE staff_id = ? LIMIT 1');
        $stmtStaffExists = $pdo->prepare('SELECT 1 FROM staff WHERE employee_no = ? LIMIT 1');

        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;
            if (count($row) < 2) continue; // skip completely empty lines

            $d = [];
            foreach ($expected as $i => $col) {
                $idx = $colIndex[strtolower($col)] ?? $i;
                $d[$col] = isset($row[$idx]) && trim((string)$row[$idx]) !== '' ? trim((string)$row[$idx]) : null;
            }

            $catTrim = isset($d['category']) ? trim((string)$d['category']) : '';
            $catPrefix = laptop_category_to_asset_prefix($catTrim !== '' ? $catTrim : null);
            if ($catTrim === '' || $catPrefix === null) {
                $results[] = ['row'=>$row_num, 'status'=>'error',
                    'asset_id'=>$d['asset_id'] ?? '—', 'serial'=>$d['serial_num']??'—',
                    'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                    'msg'=>'category is required after asset_id (Desktop AIO, Desktop IO, Notebook, or Notebook Standby — case-insensitive).'];
                $total_err++;
                continue;
            }

            $assetIdRaw = isset($d['asset_id']) && $d['asset_id'] !== null && trim((string)$d['asset_id']) !== ''
                ? trim((string)$d['asset_id']) : '';
            $generateAssetId = ($assetIdRaw === '');

            if (!$generateAssetId) {
                if (!preg_match('/^\d+$/', $assetIdRaw)) {
                    $results[] = ['row'=>$row_num, 'status'=>'error',
                        'asset_id'=>$assetIdRaw, 'serial'=>$d['serial_num']??'—',
                        'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                        'msg'=>'asset_id must be empty (auto) or a whole number.'];
                    $total_err++;
                    continue;
                }
                $aid = (int) $assetIdRaw;
                if ($aid <= 0) {
                    $results[] = ['row'=>$row_num, 'status'=>'error',
                        'asset_id'=>$assetIdRaw, 'serial'=>$d['serial_num']??'—',
                        'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                        'msg'=>'asset_id must be positive.'];
                    $total_err++;
                    continue;
                }
                if (substr((string) $aid, 0, strlen($catPrefix)) !== $catPrefix) {
                    $results[] = ['row'=>$row_num, 'status'=>'error',
                        'asset_id'=>$assetIdRaw, 'serial'=>$d['serial_num']??'—',
                        'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                        'msg'=>"asset_id must start with {$catPrefix} for category \"{$catTrim}\" (Desktop AIO/IO → 14…, Notebook/Standby → 12…)."];
                    $total_err++;
                    continue;
                }
            } else {
                $aid = null;
            }

            if (!$d['serial_num'] || !$d['status_id']) {
                $results[] = ['row'=>$row_num, 'status'=>'error',
                    'asset_id'=>$generateAssetId ? '(auto)' : $assetIdRaw, 'serial'=>$d['serial_num']??'—',
                    'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                    'msg'=>'Missing required field: serial_num or status_id'];
                $total_err++;
                continue;
            }

            $assetLabelErr = $generateAssetId ? '(auto)' : $assetIdRaw;

            $hoDate = $d['handover_date'] ?? null;
            $hoTech = isset($d['handover_technician_staff_id']) && trim((string)$d['handover_technician_staff_id']) !== ''
                ? trim((string)$d['handover_technician_staff_id']) : null;
            $hoRemarks = $d['handover_remarks'] ?? null;
            if ($hoRemarks !== null && trim((string)$hoRemarks) === '') {
                $hoRemarks = null;
            }
            $hoPlaceRaw = isset($d['handover_place']) && trim((string)$d['handover_place']) !== ''
                ? trim((string)$d['handover_place']) : null;
            $hoEmployee = isset($d['recipient_employee_no']) && trim((string)$d['recipient_employee_no']) !== ''
                ? trim((string)$d['recipient_employee_no']) : null;

            $hasHoDate = $hoDate !== null && trim((string)$hoDate) !== '';
            $isDeploy = (int)$d['status_id'] === 3;
            $needsHandover = $hasHoDate || $hoEmployee !== null;

            if ($hoEmployee !== null && !$hasHoDate) {
                $results[] = ['row'=>$row_num, 'status'=>'error',
                    'asset_id'=>$assetLabelErr, 'serial'=>$d['serial_num']??'—',
                    'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                    'msg'=>'handover_date is required when recipient_employee_no is set.'];
                $total_err++;
                continue;
            }
            $hoRemarksNonEmpty = $hoRemarks !== null && trim((string)$hoRemarks) !== '';
            if (!$hasHoDate && ($hoPlaceRaw !== null || $hoRemarksNonEmpty)) {
                $results[] = ['row'=>$row_num, 'status'=>'error',
                    'asset_id'=>$assetLabelErr, 'serial'=>$d['serial_num']??'—',
                    'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                    'msg'=>'handover_date is required when handover_place or handover_remarks is set.'];
                $total_err++;
                continue;
            }
            if (!$isDeploy && ($hasHoDate || $hoEmployee !== null) && (!$hasHoDate || $hoEmployee === null)) {
                $results[] = ['row'=>$row_num, 'status'=>'error',
                    'asset_id'=>$assetLabelErr, 'serial'=>$d['serial_num']??'—',
                    'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                    'msg'=>'Handover incomplete: provide handover_date and recipient_employee_no together (or leave both empty).'];
                $total_err++;
                continue;
            }

            $techStaffId = $hoTech ?? $sessionStaffId;
            if ($needsHandover && $techStaffId === '') {
                $results[] = ['row'=>$row_num, 'status'=>'error',
                    'asset_id'=>$assetLabelErr, 'serial'=>$d['serial_num']??'—',
                    'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                    'msg'=>'Handover needs handover_technician_staff_id or an importer session with staff_id.'];
                $total_err++;
                continue;
            }

            $mergedHoRemarks = null;
            if ($needsHandover) {
                $placePart = $hoPlaceRaw;
                if (($placePart === null || $placePart === '') && $hoEmployee === null && $isDeploy) {
                    $placePart = 'ITD office';
                }
                $parts = [];
                if ($placePart !== null && $placePart !== '') {
                    $parts[] = $placePart;
                }
                if ($hoRemarks !== null && trim((string)$hoRemarks) !== '') {
                    $parts[] = trim((string)$hoRemarks);
                }
                $mergedHoRemarks = $parts !== [] ? implode(' | ', $parts) : null;
            }

            if ($needsHandover) {
                $stmtUserExists->execute([$techStaffId]);
                if (!$stmtUserExists->fetchColumn()) {
                    $results[] = ['row'=>$row_num, 'status'=>'error',
                        'asset_id'=>$assetLabelErr, 'serial'=>$d['serial_num']??'—',
                        'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                        'msg'=>'handover_technician_staff_id not found in users.'];
                    $total_err++;
                    continue;
                }
                if ($hoEmployee !== null) {
                    $stmtStaffExists->execute([$hoEmployee]);
                    if (!$stmtStaffExists->fetchColumn()) {
                        $results[] = ['row'=>$row_num, 'status'=>'error',
                            'asset_id'=>$assetLabelErr, 'serial'=>$d['serial_num']??'—',
                            'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                            'msg'=>'recipient_employee_no not found in staff directory.'];
                        $total_err++;
                        continue;
                    }
                }
            }

            $wStart = isset($d['warranty_start_date']) && trim((string)$d['warranty_start_date']) !== ''
                ? trim((string)$d['warranty_start_date']) : null;
            $wEnd = isset($d['warranty_end_date']) && trim((string)$d['warranty_end_date']) !== ''
                ? trim((string)$d['warranty_end_date']) : null;
            $wRemarks = $d['warranty_remarks'] ?? null;
            if ($wRemarks !== null && trim((string)$wRemarks) === '') {
                $wRemarks = null;
            }
            $hasWStart = $wStart !== null;
            $hasWEnd = $wEnd !== null;
            if ($hasWStart xor $hasWEnd) {
                $results[] = ['row'=>$row_num, 'status'=>'error',
                    'asset_id'=>$assetLabelErr, 'serial'=>$d['serial_num']??'—',
                    'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                    'msg'=>'Warranty incomplete: provide both warranty_start_date and warranty_end_date (or leave both empty).'];
                $total_err++;
                continue;
            }
            $anyWarranty = $hasWStart && $hasWEnd;

            try {
                $pdo->beginTransaction();
                if ($aid === null) {
                    $aid = laptop_compute_next_asset_id($pdo, $catPrefix);
                }
                $stmt = $pdo->prepare("
                    INSERT INTO laptop
                        (asset_id, serial_num, brand, model, category, part_number,
                         processor, memory, os, storage, gpu,
                         PO_DATE, PO_NUM, DO_DATE, DO_NUM,
                         INVOICE_DATE, INVOICE_NUM, PURCHASE_COST, status_id, remarks)
                    VALUES
                        (:asset_id,:serial_num,:brand,:model,:category,:part_number,
                         :processor,:memory,:os,:storage,:gpu,
                         :po_date,:po_num,:do_date,:do_num,
                         :invoice_date,:invoice_num,:purchase_cost,:status_id,:remarks)
                ");
                $stmt->execute([
                    ':asset_id'      => $aid,
                    ':serial_num'    => $d['serial_num'],
                    ':brand'         => $d['brand'],
                    ':model'         => $d['model'],
                    ':category'      => $catTrim,
                    ':part_number'   => $d['part_number'],
                    ':processor'     => $d['processor'],
                    ':memory'        => $d['memory'],
                    ':os'            => $d['os'],
                    ':storage'       => $d['storage'],
                    ':gpu'           => $d['gpu'],
                    ':po_date'       => $d['po_date'],
                    ':po_num'        => $d['po_num'],
                    ':do_date'       => $d['do_date'],
                    ':do_num'        => $d['do_num'],
                    ':invoice_date'  => $d['invoice_date'],
                    ':invoice_num'   => $d['invoice_num'],
                    ':purchase_cost' => $d['purchase_cost'] !== null ? (float)$d['purchase_cost'] : null,
                    ':status_id'     => (int)$d['status_id'],
                    ':remarks'       => $d['remarks'],
                ]);

                $okParts = [];
                if ($needsHandover) {
                    $stmt2 = $pdo->prepare('INSERT INTO handover (asset_id, staff_id, handover_date, handover_remarks) VALUES (:asset_id, :staff_id, :handover_date, :handover_remarks)');
                    $stmt2->execute([
                        ':asset_id' => $aid,
                        ':staff_id' => $techStaffId,
                        ':handover_date' => trim((string)$hoDate),
                        ':handover_remarks' => $mergedHoRemarks,
                    ]);
                    $handover_id = (int) $pdo->lastInsertId();
                    if ($hoEmployee !== null) {
                        $stmt3 = $pdo->prepare('INSERT INTO handover_staff (employee_no, handover_id) VALUES (:employee_no, :handover_id)');
                        $stmt3->execute([
                            ':employee_no' => $hoEmployee,
                            ':handover_id' => $handover_id,
                        ]);
                        $okParts[] = 'handover+recipient';
                    } else {
                        $okParts[] = 'handover (place)';
                    }
                }
                if ($anyWarranty) {
                    $stmtW = $pdo->prepare('INSERT INTO warranty (asset_id, warranty_start_date, warranty_end_date, warranty_remarks) VALUES (:asset_id, :start, :end, :remarks)');
                    $stmtW->execute([
                        ':asset_id' => $aid,
                        ':start' => $wStart,
                        ':end' => $wEnd,
                        ':remarks' => $wRemarks,
                    ]);
                    $okParts[] = 'warranty';
                }
                $okMsg = 'Imported successfully' . ($okParts !== [] ? ' (+' . implode(', +', $okParts) . ')' : '');

                $pdo->commit();
                $results[] = ['row'=>$row_num, 'status'=>'ok',
                    'asset_id'=>$aid, 'serial'=>$d['serial_num'],
                    'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                    'msg'=>$okMsg];
                $total_ok++;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msg = str_contains($e->getMessage(), 'Duplicate')
                    ? 'Duplicate serial number or asset ID — skipped'
                    : 'DB error: '.$e->getMessage();
                $results[] = ['row'=>$row_num, 'status'=>'error',
                    'asset_id'=>$aid ?? $assetLabelErr, 'serial'=>$d['serial_num'],
                    'brand'=>trim(($d['brand']??'').' '.($d['model']??'')),
                    'msg'=>$msg];
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
    <title>Bulk Import Laptops - RCMP NIMS</title>
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

        /* ── Decorative blobs ── */
        .blob {
            position: fixed; border-radius: 50%; filter: blur(80px);
            pointer-events: none; z-index: 0;
        }
        .blob-1 { width:500px; height:500px; background:rgba(37,99,235,0.06); top:-100px; right:-100px; }
        .blob-2 { width:400px; height:400px; background:rgba(124,58,237,0.05); bottom:-80px; left:-80px; }

        /* ── Sidebar ── */
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
            padding: 1.5rem 1.75rem 1.25rem;
            border-bottom: 1px solid var(--card-border);
        }
        .sidebar-logo img { height: 42px; object-fit: contain; }
        .nav-menu { flex: 1; padding: 1.25rem 1rem; display: flex; flex-direction: column; gap: 0.25rem; overflow-y: auto; }
        .nav-item {
            display: flex; align-items: center; gap: 1rem;
            padding: 0.75rem 1.25rem; border-radius: 12px;
            color: var(--text-muted); text-decoration: none;
            font-weight: 500; font-size: 0.95rem;
            transition: all 0.2s ease; cursor: pointer;
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
            transition: all 0.2s ease; position: relative;
        }
        .nav-dropdown-item::before {
            content: ''; position: absolute; left: -1rem; top: 50%;
            width: 6px; height: 6px; background: var(--card-border);
            border-radius: 50%; transform: translateY(-50%); transition: all 0.2s ease;
        }
        .nav-dropdown-item:hover { color: var(--primary); background: rgba(37,99,235,0.06); }
        .nav-dropdown-item:hover::before { background: var(--primary); }
        .nav-dropdown-item.active { color: var(--primary); }
        .nav-dropdown-item.active::before { background: var(--primary); }
        .nav-item.open .chevron { transform: rotate(180deg); }
        .user-profile {
            padding: 1.25rem 1.75rem; border-top: 1px solid var(--card-border);
            display: flex; align-items: center; gap: 0.75rem;
            cursor: pointer; transition: background 0.2s;
        }
        .user-profile:hover { background: rgba(37,99,235,0.04); }
        .avatar {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif; font-weight: 700;
            color: white; font-size: 1rem;
        }
        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-size: 0.9rem; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role { font-size: 0.75rem; color: var(--primary); margin-top: 0.2rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }

        /* ── Main content ── */
        .main-content {
            margin-left: 280px; flex: 1;
            padding: 2.5rem 3.5rem 5rem;
            max-width: calc(100vw - 280px);
            position: relative; z-index: 1;
        }

        /* ── Page header ── */
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
        .page-title p { color: var(--text-muted); font-size: 0.95rem; margin-top: 0.25rem; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 0.5rem;
            color: var(--text-muted); text-decoration: none; font-weight: 500;
            background: var(--glass-panel); padding: 0.6rem 1.2rem;
            border-radius: 12px; border: 1px solid var(--card-border);
            transition: all 0.2s ease;
        }
        .btn-back:hover { color: var(--primary); background: rgba(37,99,235,0.06); border-color: rgba(37,99,235,0.2); transform: translateX(-3px); }

        /* ── Cards ── */
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

        /* ── Upload Zone ── */
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

        /* ── Template bar ── */
        .template-bar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.25rem; background: rgba(37,99,235,0.04);
            border: 1px solid rgba(37,99,235,0.15); border-radius: 12px;
            margin-bottom: 1.5rem;
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

        /* ── Column legend ── */
        .column-chips {
            display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;
        }
        .chip {
            background: var(--glass-panel); border: 1px solid var(--card-border);
            border-radius: 8px; padding: 0.25rem 0.7rem;
            font-size: 0.76rem; font-weight: 600; color: var(--text-muted);
            font-family: 'Inter', monospace;
        }
        .chip.required { border-color: rgba(37,99,235,0.3); color: var(--primary); background: rgba(37,99,235,0.06); }

        /* ── Submit bar ── */
        .submit-bar {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 1.5rem; gap: 1rem; flex-wrap: wrap;
        }
        .file-info { font-size: 0.9rem; color: var(--text-muted); }
        .btn { padding: 0.7rem 1.8rem; border-radius: 12px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.25s ease; display: inline-flex; align-items: center; gap: 0.5rem; border: none; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; box-shadow: 0 6px 20px rgba(37,99,235,0.35); }
        .btn-primary:hover { transform: translateY(-2px); filter: brightness(1.08); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        /* ── Preview table ── */
        .preview-wrapper { overflow-x: auto; border-radius: 12px; border: 1px solid var(--card-border); }
        .preview-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .preview-table thead { background: var(--glass-panel); }
        .preview-table th { padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--card-border); }
        .preview-table td { padding: 0.7rem 1rem; border-bottom: 1px solid rgba(226,232,240,0.5); color: var(--text-main); }
        .preview-table tbody tr:last-child td { border-bottom: none; }
        .preview-table tbody tr:hover { background: rgba(37,99,235,0.02); }
        .preview-count { font-size: 0.85rem; color: var(--text-muted); margin-top: 0.75rem; }

        /* ── Results ── */
        .results-grid { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .result-stat {
            flex: 1; min-width: 120px; background: var(--card-bg);
            border: 1px solid var(--card-border); border-radius: 14px;
            padding: 1.1rem 1.5rem; text-align: center;
        }
        .result-stat .rs-num { font-family:'Outfit',sans-serif; font-size:2rem; font-weight:700; }
        .result-stat .rs-label { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); font-weight:600; margin-top:0.2rem; }
        .rs-ok .rs-num { color: var(--success); }
        .rs-err .rs-num { color: var(--danger); }
        .rs-total .rs-num { color: var(--primary); }

        .result-row-ok  { background: rgba(16,185,129,0.04); }
        .result-row-err { background: rgba(239,68,68,0.04); }
        .badge-ok  { background:rgba(16,185,129,0.12); color:var(--success);  padding:2px 10px; border-radius:20px; font-size:0.78rem; font-weight:600; }
        .badge-err { background:rgba(239,68,68,0.12);  color:var(--danger);   padding:2px 10px; border-radius:20px; font-size:0.78rem; font-weight:600; }

        /* ── Animations ── */
        @keyframes fadeInDown { from { opacity:0; transform:translateY(-20px); } to { opacity:1; transform:translateY(0); } }
        @keyframes fadeInUp   { from { opacity:0; transform:translateY(20px); }  to { opacity:1; transform:translateY(0); } }
    </style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<!-- Sidebar -->
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<!-- Main Content -->
<main class="main-content">

    <!-- Header -->
    <header class="page-header">
        <div class="page-title">
            <h1><i class="ri-file-upload-line"></i> Bulk Import Laptops</h1>
            <p>Upload a CSV file to register laptops. Optional columns create a <strong>handover</strong>; <code>recipient_employee_no</code> adds a <code>handover_staff</code> row (omit for place-only deploy).</p>
        </div>
        <a href="laptop.php" class="btn-back"><i class="ri-arrow-left-line"></i> Back to Inventory</a>
    </header>

    <?php if ($processed): ?>
    <!-- ── Import Results ── -->
    <div class="card">
        <div class="card-title"><i class="ri-bar-chart-2-line"></i> Import Summary</div>
        <div class="results-grid">
            <div class="result-stat rs-total">
                <div class="rs-num"><?= $total_ok + $total_err ?></div>
                <div class="rs-label">Total Rows</div>
            </div>
            <div class="result-stat rs-ok">
                <div class="rs-num"><?= $total_ok ?></div>
                <div class="rs-label">Imported</div>
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
                        <th>Asset ID</th>
                        <th>Serial No</th>
                        <th>Device</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr class="result-row-<?= $r['status'] ?>">
                        <td>#<?= $r['row'] ?></td>
                        <td><strong><?= htmlspecialchars($r['asset_id'] ?? '—') ?></strong></td>
                        <td><?= htmlspecialchars($r['serial'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['brand'] ?? '—') ?></td>
                        <td><span class="badge-<?= $r['status'] ?>"><?= $r['status'] === 'ok' ? '✓ Success' : '✗ Failed' ?></span></td>
                        <td><?= htmlspecialchars($r['msg']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:1.5rem; display:flex; gap:1rem; flex-wrap:wrap;">
            <a href="laptopCSV.php" class="btn btn-primary"><i class="ri-upload-2-line"></i> Import Another File</a>
            <a href="laptop.php" class="btn" style="background:var(--glass-panel);border:1px solid var(--card-border);color:var(--text-muted);"><i class="ri-list-check"></i> View Inventory</a>
        </div>
    </div>

    <?php else: ?>
    <!-- ── Upload Form ── -->

    <!-- Template Download Bar -->
    <div class="template-bar">
        <span><strong>Need a template?</strong> Download the CSV template with the correct column headers and a sample row.</span>
        <a href="?download_template=1" class="btn-template"><i class="ri-download-2-line"></i> Download Template</a>
    </div>

    <div class="card">
        <div class="card-title"><i class="ri-table-line"></i> Required Columns</div>
        <div class="column-chips">
            <span class="chip">asset_id</span>
            <span class="chip required">category *</span>
            <span class="chip required">serial_num *</span>
            <span class="chip required">status_id *</span>
            <span class="chip">brand</span>
            <span class="chip">model</span>
            <span class="chip">part_number</span>
            <span class="chip">processor</span>
            <span class="chip">memory</span>
            <span class="chip">storage</span>
            <span class="chip">gpu</span>
            <span class="chip">os</span>
            <span class="chip">po_date</span>
            <span class="chip">po_num</span>
            <span class="chip">do_date</span>
            <span class="chip">do_num</span>
            <span class="chip">invoice_date</span>
            <span class="chip">invoice_num</span>
            <span class="chip">purchase_cost</span>
            <span class="chip">remarks</span>
            <span class="chip">handover_date</span>
            <span class="chip">handover_technician_staff_id</span>
            <span class="chip">handover_remarks</span>
            <span class="chip">recipient_employee_no</span>
            <span class="chip">warranty_start_date</span>
            <span class="chip">warranty_end_date</span>
            <span class="chip">warranty_remarks</span>
            <span class="chip">handover_place</span>
        </div>
        <p style="font-size:0.83rem;color:var(--text-muted);margin-top:0.5rem;">
            <i class="ri-information-line"></i>
            <strong>category</strong> is required in column 2: <code>Desktop AIO</code>, <code>Desktop IO</code>, <code>Notebook</code>, or <code>Notebook Standby</code> (any case). <strong>asset_id</strong> may be left blank to auto-assign the next id for that category (same rules as Register Laptop: Desktop → <code>14…</code>, Notebook → <code>12…</code> with current year in the number). If you set asset_id, it must start with the matching prefix. Dates: <strong>YYYY-MM-DD</strong>.
            Status IDs: 1=Active, 2=Non-active, 3=Deploy, 4=Reserved, 5=Maintenance, 6=Faulty, 7=Disposed, 8=Lost.
            First row must be headers. Columns are matched by name (case-insensitive); if a name is missing, that field falls back to column order in the template.
            <strong>Handover:</strong> fully optional for any status including <strong>Deploy (3)</strong> — leave handover columns empty to import deploy without a <code>handover</code> row. If you set <code>handover_date</code> (with or without recipient), a handover is created; <code>recipient_employee_no</code> is optional for place handover. <code>handover_date</code> is required when recipient is set. <code>handover_place</code> merges into remarks; deploy + handover without recipient and empty place defaults to <strong>ITD office</strong>.
            Rows go to <code>handover</code>; <code>handover_staff</code> only when recipient is set. <code>handover_technician_staff_id</code> is <code>users.staff_id</code> (defaults to you if empty). Recipient must exist in <code>staff</code> when provided.
            <code>handover_place</code> is the last column (after warranty fields) so older 27-column files still align by position.
            <strong>Warranty (optional):</strong> both <code>warranty_start_date</code> and <code>warranty_end_date</code> create a <code>warranty</code> row; <code>warranty_remarks</code> is optional.
        </p>
    </div>

    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <div class="card">
            <div class="card-title"><i class="ri-upload-cloud-2-line"></i> Upload CSV File</div>

            <div class="upload-zone" id="uploadZone">
                <input type="file" name="csv_file" id="csvFile" accept=".csv" required>
                <div class="upload-icon"><i class="ri-file-excel-2-line"></i></div>
                <h3>Drag & drop your CSV here</h3>
                <p>or <span class="btn-link">click to browse</span> &nbsp;·&nbsp; Accepts <strong>.csv</strong> files only</p>
                <div class="file-chosen" id="fileChosen" style="display:none;"></div>
            </div>

            <!-- Live Preview -->
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
                    <i class="ri-upload-2-line"></i> Import Laptops
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>

</main>

<script>
    // ── Sidebar dropdown ──────────────────────────────────────────────────────
    function toggleDropdown(el, e) {
        e.preventDefault();
        const group    = el.closest('.nav-group');
        const dropdown = group.querySelector('.nav-dropdown');
        el.classList.toggle('open');
        dropdown.classList.toggle('show');
    }

    // ── Drag & Drop + Preview ─────────────────────────────────────────────────
    const zone      = document.getElementById('uploadZone');
    const fileInput = document.getElementById('csvFile');
    const submitBtn = document.getElementById('submitBtn');
    const chosen    = document.getElementById('fileChosen');
    const fileInfo  = document.getElementById('fileInfoText');

    if (zone) {
        zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('dragover');
            const f = e.dataTransfer.files[0];
            if (f) { fileInput.files = e.dataTransfer.files; handleFile(f); }
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files[0]) handleFile(fileInput.files[0]);
        });
    }

    function handleFile(file) {
        if (!file.name.endsWith('.csv')) {
            chosen.style.display = 'block';
            chosen.style.color   = 'var(--danger)';
            chosen.textContent   = '✗ Only .csv files are accepted.';
            submitBtn.disabled   = true;
            return;
        }

        chosen.style.display = 'block';
        chosen.style.color   = 'var(--primary)';
        chosen.textContent   = '✓ ' + file.name + ' · ' + (file.size / 1024).toFixed(1) + ' KB';
        fileInfo.textContent = file.name + ' selected';
        submitBtn.disabled   = false;

        // Read & preview
        const reader = new FileReader();
        reader.onload = function(ev) {
            const lines = ev.target.result.trim().split('\n').filter(l => l.trim());
            if (lines.length < 2) return;

            const headers  = parseCSVLine(lines[0]);
            const rows     = lines.slice(1);
            const previewN = Math.min(5, rows.length);

            // Build header row
            const head = document.getElementById('previewHead');
            head.innerHTML = headers.map(h => `<th>${h}</th>`).join('');

            // Build body rows
            const body = document.getElementById('previewBody');
            body.innerHTML = '';
            for (let i = 0; i < previewN; i++) {
                const cells = parseCSVLine(rows[i]);
                body.innerHTML += '<tr>' + cells.map(c => `<td>${c || '<span style="color:#cbd5e1">—</span>'}</td>`).join('') + '</tr>';
            }

            document.getElementById('previewCount').textContent =
                `Showing ${previewN} of ${rows.length} data row${rows.length !== 1 ? 's' : ''} (header excluded)`;
            document.getElementById('previewSection').style.display = 'block';
        };
        reader.readAsText(file);
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
