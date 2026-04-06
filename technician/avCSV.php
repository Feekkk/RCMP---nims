<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

const AV_STATUS_IDS = [1, 2, 3, 5, 6, 7, 8];

function norm_csv_header(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return $s ?? '';
}

function av_parse_asset_id_string(?string $s): int
{
    if ($s === null) {
        return 0;
    }
    $s = trim($s);
    if ($s === '') {
        return 0;
    }
    $digits = preg_replace('/\D/', '', $s);
    return $digits !== '' ? (int) $digits : 0;
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

$success_message = '';
$error_message = '';

$template_href = '../nims%20-%20av%20assets%20template.csv';

try {
    $pdo = db();
} catch (Throwable $e) {
    $error_message = 'Database unavailable: ' . htmlspecialchars($e->getMessage());
    $pdo = null;
}

$statusById = [];
$nameToId = [];
if ($pdo !== null) {
    $rows = $pdo->query(
        'SELECT status_id, name FROM status WHERE status_id IN (' . implode(',', array_map('intval', AV_STATUS_IDS)) . ') ORDER BY status_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $id = (int) $r['status_id'];
        $statusById[$id] = true;
        $nameToId[strtolower(trim((string) $r['name']))] = $id;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error_message === '' && $pdo !== null) {
    if (!isset($_FILES['csv_file']) || (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error_message = 'Please upload a valid CSV file.';
    } else {
        $tmpPath = (string) $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmpPath, 'r');
        if ($handle === false) {
            $error_message = 'Could not read the uploaded CSV.';
        } else {
            $header = fgetcsv($handle);
            if (!is_array($header) || $header === []) {
                $error_message = 'CSV is empty.';
            } else {
                $keys = array_map(static fn ($h) => norm_csv_header((string) $h), $header);
                if (!in_array('asset_id', $keys, true) || !in_array('status_id', $keys, true)) {
                    $error_message = 'CSV must include headers from the template: asset_id, status_id, serial_number, …';
                } elseif (!in_array('serial_number', $keys, true) && !in_array('serial_num', $keys, true)) {
                    $error_message = 'CSV must include serial_number (or serial_num).';
                }
                if ($error_message === '') {
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

                    $staffId = (string) $_SESSION['staff_id'];
                    $ok = 0;
                    $dups = 0;
                    $fails = 0;
                    $today = date('Y-m-d');

                    while (($row = fgetcsv($handle)) !== false) {
                        if (!is_array($row) || $row === []) {
                            continue;
                        }

                        $asset_id = av_parse_asset_id_string($get($row, 'asset_id'));
                        if ($asset_id < 1) {
                            $fails++;
                            continue;
                        }

                        $category = $get($row, 'category') ?? 'AV';
                        $brand = $get($row, 'brand');
                        $model = $get($row, 'model');
                        $serial_raw = $get($row, 'serial_number') ?? $get($row, 'serial_num');
                        $status_id = (int) ($get($row, 'status_id') ?? 0);
                        if (($status_id < 1 || !isset($statusById[$status_id])) && in_array('status', $keys, true)) {
                            $status_id = av_csv_resolve_status($get($row, 'status') ?? '', $statusById, $nameToId);
                        }

                        if ($serial_raw === null || $serial_raw === '' || $status_id < 1 || !isset($statusById[$status_id])) {
                            $fails++;
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
                        $building = 'UNKNOWN';
                        $level = '-';
                        $zone = '-';

                        try {
                            $stmtA->execute([
                                ':asset_id' => $asset_id,
                                ':asset_id_old' => null,
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

                            if ($status_id === 3) {
                                $stmtD->execute([
                                    ':asset_id' => $asset_id,
                                    ':building' => $building,
                                    ':level' => $level,
                                    ':zone' => $zone,
                                    ':deployment_date' => $today,
                                    ':deployment_remarks' => null,
                                    ':staff_id' => $staffId,
                                ]);
                            }
                            $ok++;
                        } catch (PDOException $e) {
                            $msg = $e->getMessage();
                            if ((string) $e->getCode() === '23000' && stripos($msg, 'Duplicate') !== false) {
                                $dups++;
                            } else {
                                $fails++;
                            }
                        }
                    }
                    fclose($handle);

                    $success_message = "Import finished. OK: {$ok}, Duplicates: {$dups}, Failed rows: {$fails}.";
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
    <title>Import AV (CSV) — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #0ea5e9;
            --danger: #ef4444;
            --success: #10b981;
            --bg: #f1f5f9;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass: #f8fafc;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; }
        .main-content { margin-left: 280px; flex: 1; padding: 2.5rem 2.5rem 4rem; max-width: calc(100vw - 280px); }
        .page-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; margin-bottom: 1.75rem; padding-bottom: 1.25rem; border-bottom: 1px solid var(--card-border); }
        .page-title { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 800; letter-spacing: -0.3px; }
        .page-subtitle { color: var(--text-muted); margin-top: 0.35rem; font-size: 0.95rem; }
        .btn-back { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.55rem 1rem; border-radius: 10px; border: 1.5px solid var(--card-border); background: var(--card-bg); color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.88rem; }
        .btn-back:hover { border-color: var(--primary); color: var(--primary); }
        .alert { display: flex; align-items: flex-start; gap: 0.65rem; padding: 1rem 1.15rem; border-radius: 12px; margin-bottom: 1.25rem; font-size: 0.92rem; line-height: 1.45; }
        .alert-success { background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.28); color: #065f46; }
        .alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.25); color: #991b1b; }
        .form-section { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 18px; overflow: hidden; box-shadow: 0 2px 12px rgba(15,23,42,0.05); }
        .section-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1.15rem 1.35rem; background: var(--glass); border-bottom: 1px solid var(--card-border); }
        .section-head-left { display: flex; align-items: center; gap: 0.85rem; }
        .section-icon { width: 44px; height: 44px; border-radius: 12px; background: rgba(37,99,235,0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 1.35rem; }
        .section-title { font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 1.05rem; }
        .section-desc { color: var(--text-muted); font-size: 0.86rem; margin-top: 0.2rem; }
        .section-body { padding: 1.35rem 1.35rem 1.5rem; }
        .badge-tag { font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.35rem 0.65rem; border-radius: 8px; background: rgba(37,99,235,0.12); color: var(--primary); }
        .field { margin-bottom: 1.1rem; }
        .field-label { display: block; font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--primary); margin-bottom: 0.45rem; }
        .req { color: var(--danger); }
        .field-input { width: 100%; padding: 0.78rem 0.95rem; border: 1.5px solid var(--card-border); border-radius: 11px; font-size: 0.95rem; }
        .bulk-note { color: var(--text-muted); font-size: 0.85rem; line-height: 1.55; margin-top: 0.6rem; }
        .bulk-note code { font-size: 0.8rem; background: var(--glass); padding: 0.15rem 0.4rem; border-radius: 6px; }
        .file-input { width: 100%; padding: 0.85rem 0.9rem; border: 1.5px dashed rgba(226,232,240,1); border-radius: 11px; background: var(--glass); }
        .form-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; margin-top: 1.35rem; padding-top: 1.25rem; border-top: 1px solid var(--card-border); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.78rem 1.5rem; border-radius: 12px; font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 0.92rem; cursor: pointer; border: none; text-decoration: none; transition: all 0.2s; }
        .btn-primary { background: linear-gradient(135deg, #021A54, #1e40af); color: #fff; box-shadow: 0 4px 14px rgba(2,26,84,0.28); }
        .btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); }
        .btn-outline { background: var(--card-bg); border: 1.5px solid var(--card-border); color: var(--text-muted); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div>
            <h1 class="page-title">Import AV assets (CSV)</h1>
            <div class="page-subtitle">Use the same header row as <code>nims - av assets template.csv</code> (snake_case columns).</div>
        </div>
        <a href="av.php" class="btn-back"><i class="ri-arrow-left-line"></i> Back to AV inventory</a>
    </header>

    <?php if ($success_message !== ''): ?>
        <div class="alert alert-success">
            <i class="ri-checkbox-circle-fill"></i>
            <span><?= htmlspecialchars($success_message) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error_message !== ''): ?>
        <div class="alert alert-error">
            <i class="ri-error-warning-fill"></i>
            <span><?= htmlspecialchars($error_message) ?></span>
        </div>
    <?php endif; ?>

    <div class="form-section">
        <div class="section-head">
            <div class="section-head-left">
                <div class="section-icon"><i class="ri-upload-cloud-line"></i></div>
                <div>
                    <div class="section-title">CSV upload</div>
                    <div class="section-desc">Required columns match the template file.</div>
                </div>
            </div>
            <span class="badge-tag">Template</span>
        </div>
        <div class="section-body">
            <p class="bulk-note" style="margin-bottom:1rem;">
                <a class="btn btn-outline" style="padding:0.55rem 1rem;font-size:0.85rem;" href="<?= htmlspecialchars($template_href) ?>" download><i class="ri-download-line"></i> Download CSV template</a>
            </p>
            <form method="post" action="" enctype="multipart/form-data">
                <div class="field">
                    <label class="field-label" for="csv_file">CSV file <span class="req">*</span></label>
                    <input class="field-input file-input" id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required>
                    <div class="bulk-note">
                        Required: <code>asset_id</code>, <code>serial_number</code>, <code>status_id</code> (AV statuses 1,2,3,5,6,7,8). Optional: <code>category</code>, <code>brand</code>, <code>model</code>, <code>po_date</code>, <code>po_num</code>, <code>do_date</code>, <code>do_num</code>, <code>invoice_date</code>, <code>invoice_no</code>, <code>purchase</code>, <code>remarks</code>. Deploy (3): a deployment row is created with placeholder building/level/zone.
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="ri-upload-line"></i> Import</button>
                    <a href="avAdd.php" class="btn btn-outline">Single registration</a>
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>
