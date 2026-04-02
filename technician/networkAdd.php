<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

const NETWORK_STATUS_IDS = [3, 5, 6, 7, 8, 9, 10];

/** Deployment building dropdown — adjust list to match your campus. */
const BUILDING_OPTIONS = [
    'AVICENNA',
    'AL-ZAHRAWI',
    'AL-RAZI',
    'IBN-KHALDUN',
    'IBN-KHALDUN-B',
];

function next_network_asset_id(PDO $pdo): int
{
    $yy = date('y');
    $prefix = '24' . $yy;
    $stmt = $pdo->prepare('SELECT MAX(asset_id) FROM network WHERE asset_id LIKE ?');
    $stmt->execute([$prefix . '%']);
    $maxVal = (int)$stmt->fetchColumn();
    $nextSeq = ($maxVal === 0) ? 1 : ($maxVal % 10000) + 1;

    return (int)($prefix . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT));
}

$success_message = '';
$error_message = '';
$status_options = [];

try {
    $pdo = db();
    $in = implode(',', array_map('intval', NETWORK_STATUS_IDS));
    $status_options = $pdo->query(
        "SELECT status_id, name FROM status WHERE status_id IN ($in) ORDER BY status_id"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error_message = 'Database unavailable: ' . htmlspecialchars($e->getMessage());
}

$next_asset_id = 0;
if ($error_message === '' && isset($pdo)) {
    $next_asset_id = next_network_asset_id($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error_message === '') {
    $str = fn(string $k) => isset($_POST[$k]) && $_POST[$k] !== '' ? trim((string)$_POST[$k]) : null;
    $date = fn(string $k) => isset($_POST[$k]) && $_POST[$k] !== '' ? trim((string)$_POST[$k]) : null;
    $dec = fn(string $k) => isset($_POST[$k]) && $_POST[$k] !== '' ? (float)$_POST[$k] : null;
    $int = fn(string $k) => isset($_POST[$k]) && $_POST[$k] !== '' ? (int)$_POST[$k] : null;

    $asset_id = $int('asset_id') ?? $next_asset_id;
    $serial_num = $str('serial_num');
    $brand = $str('brand');
    $model = $str('model');
    $mac_address = $str('mac_address');
    $ip_address = $str('ip_address');
    $po_date = $date('po_date');
    $po_num = $str('po_num');
    $do_date = $date('do_date');
    $do_num = $str('do_num');
    $invoice_date = $date('invoice_date');
    $invoice_num = $str('invoice_num');
    $purchase_cost = $dec('purchase_cost');
    $status_id = $int('status_id');
    $remarks = $str('remarks');

    $deploy_building = $str('deployment_building');
    $deploy_level = $str('deployment_level');
    $deploy_zone = $str('deployment_zone');
    $deploy_date = $date('deployment_date');
    $deploy_remarks = $str('deployment_remarks');
    $session_staff_id = isset($_SESSION['staff_id']) ? trim((string)$_SESSION['staff_id']) : '';

    if (!$asset_id || !$serial_num || !$status_id) {
        $error_message = 'Asset ID, Serial number, and Status are required.';
    } elseif (!in_array($status_id, NETWORK_STATUS_IDS, true)) {
        $error_message = 'Invalid status for network assets.';
    } else {
        if ($mac_address !== null && $mac_address !== '') {
            $norm = preg_replace('/[:\-\.\s]/', '', (string)$mac_address);
            if ($norm === null || strlen($norm) !== 12 || !ctype_xdigit($norm)) {
                $error_message = 'MAC must be 12 hex digits (optional : - . between pairs).';
            }
        }
        if ($error_message === '' && $ip_address !== null && $ip_address !== '' && filter_var($ip_address, FILTER_VALIDATE_IP) === false) {
            $error_message = 'IP address is not valid.';
        }
        if ($error_message === '' && $status_id === 3) {
            if (!$session_staff_id) {
                $error_message = 'Session staff ID missing; log in again.';
            } elseif (!$deploy_building || !$deploy_level || !$deploy_zone || !$deploy_date) {
                $error_message = 'Deploy status requires building, level, zone, and deployment date.';
            }
        }
    }

    if ($error_message === '') {
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
                ':asset_id' => $asset_id,
                ':serial_num' => $serial_num,
                ':brand' => $brand,
                ':model' => $model,
                ':mac_address' => $mac_address,
                ':ip_address' => $ip_address,
                ':po_date' => $po_date,
                ':po_num' => $po_num,
                ':do_date' => $do_date,
                ':do_num' => $do_num,
                ':invoice_date' => $invoice_date,
                ':invoice_num' => $invoice_num,
                ':purchase_cost' => $purchase_cost,
                ':status_id' => $status_id,
                ':remarks' => $remarks,
            ]);
            if ($status_id === 3) {
                $stmtD = $pdo->prepare('
                    INSERT INTO network_deployment (
                        asset_id, building, level, zone,
                        deployment_date, deployment_remarks, staff_id
                    ) VALUES (
                        :asset_id, :building, :level, :zone,
                        :deployment_date, :deployment_remarks, :staff_id
                    )
                ');
                $stmtD->execute([
                    ':asset_id' => $asset_id,
                    ':building' => $deploy_building,
                    ':level' => $deploy_level,
                    ':zone' => $deploy_zone,
                    ':deployment_date' => $deploy_date,
                    ':deployment_remarks' => $deploy_remarks,
                    ':staff_id' => $session_staff_id,
                ]);
            }
            $pdo->commit();
            $success_message = $status_id === 3
                ? "Network asset {$asset_id} registered with deployment record."
                : "Network asset {$asset_id} registered.";
            $next_asset_id = next_network_asset_id($pdo);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e->getMessage();
            if (stripos($msg, 'network_deployment') !== false && (stripos($msg, "doesn't exist") !== false || stripos($msg, 'Unknown table') !== false)) {
                $error_message = 'Missing table network_deployment — run an updated db/schema.sql (or create network_deployment).';
            } elseif ((string)$e->getCode() === '23000') {
                if (stripos($msg, 'Duplicate') !== false) {
                    $error_message = "Asset ID {$asset_id} already exists.";
                } elseif (stripos($msg, 'foreign') !== false || stripos($msg, '1452') !== false) {
                    $error_message = 'Deployment failed: your staff ID must exist in the users table.';
                } else {
                    $error_message = 'Database error: ' . htmlspecialchars($msg);
                }
            } else {
                $error_message = 'Database error: ' . htmlspecialchars($msg);
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
    <title>Register Network Asset — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary:       #2563eb;
            --primary-light: #3b82f6;
            --primary-dark:  #1d4ed8;
            --secondary:     #0ea5e9;
            --danger:        #ef4444;
            --success:       #10b981;
            --warning:       #f59e0b;
            --bg:            #f1f5f9;
            --card-bg:       #ffffff;
            --card-border:   #e2e8f0;
            --text-main:     #0f172a;
            --text-muted:    #64748b;
            --glass:         #f8fafc;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2rem 2.5rem 4rem;
            max-width: calc(100vw - 280px);
        }
        @media (max-width: 900px) {
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.25rem 1rem 3rem; }
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.75rem;
        }
        .page-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        .page-subtitle { color: var(--text-muted); font-size: 0.875rem; margin-top: 0.3rem; }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.65rem 1.1rem;
            border-radius: 12px;
            border: 1.5px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-muted);
            font-weight: 700;
            font-size: 0.88rem;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .btn-back:hover { border-color: var(--primary); color: var(--primary); }

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border-radius: 14px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            line-height: 1.45;
        }
        .alert i { font-size: 1.2rem; flex-shrink: 0; margin-top: 0.05rem; }
        .alert-link { margin-left: auto; font-weight: 700; text-decoration: underline; color: inherit; white-space: nowrap; }
        .alert-success { background: rgba(16,185,129,0.1);  border: 1px solid rgba(16,185,129,0.3);  color: #047857; }
        .alert-error   { background: rgba(239,68,68,0.08);  border: 1px solid rgba(239,68,68,0.25);  color: #b91c1c; }

        .form-section {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 1.25rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.05);
            transition: opacity 0.3s;
        }

        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.4rem;
            border-bottom: 1px solid var(--card-border);
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            gap: 0.75rem;
        }
        .section-head-left {
            display: flex;
            align-items: center;
            gap: 0.7rem;
        }
        .section-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: rgba(37,99,235,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .section-icon i { font-size: 1rem; color: var(--primary); }
        .section-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.98rem;
        }
        .section-desc { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.1rem; }

        .badge-tag {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.65rem;
            border-radius: 20px;
            font-size: 0.67rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge-required { background: rgba(37,99,235,0.1);   color: var(--primary);   border: 1px solid rgba(37,99,235,0.2); }
        .badge-optional { background: rgba(100,116,139,0.1); color: var(--text-muted); border: 1px solid var(--card-border); }
        .badge-deploy   { background: rgba(245,158,11,0.1);  color: #b45309;           border: 1px solid rgba(245,158,11,0.25); }

        .section-body { padding: 1.4rem; }

        .section-disabled { opacity: 0.42; pointer-events: none; }
        .section-disabled .section-head { background: var(--glass); }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem 1.25rem;
        }
        @media (max-width: 1100px) { .form-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px)  { .form-grid { grid-template-columns: 1fr; } }

        .col-2 { grid-column: span 2; }
        .col-3 { grid-column: 1 / -1; }

        @media (max-width: 640px) {
            .col-2, .col-3 { grid-column: span 1; }
        }

        .field { display: flex; flex-direction: column; gap: 0.4rem; }

        .field-label {
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.35rem;
            flex-wrap: wrap;
        }
        .req { color: var(--danger); }
        .auto-tag {
            font-size: 0.63rem;
            font-weight: 700;
            background: rgba(37,99,235,0.08);
            color: var(--primary);
            border: 1px solid rgba(37,99,235,0.15);
            border-radius: 20px;
            padding: 0.08rem 0.45rem;
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            letter-spacing: 0.03em;
        }

        .field-input,
        .field-select,
        .field-textarea {
            width: 100%;
            padding: 0.68rem 0.9rem;
            border: 1.5px solid var(--card-border);
            border-radius: 11px;
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            color: var(--text-main);
            background: var(--glass);
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            outline: none;
        }
        .field-input::placeholder,
        .field-textarea::placeholder { color: #94a3b8; font-weight: 400; }
        .field-input:focus,
        .field-select:focus,
        .field-textarea:focus {
            border-color: rgba(37,99,235,0.5);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .field-input:disabled,
        .field-select:disabled { opacity: 0.5; cursor: not-allowed; }

        .field-input.auto {
            font-weight: 700;
            color: var(--primary);
            background: rgba(37,99,235,0.04);
            border-color: rgba(37,99,235,0.18);
            cursor: default;
        }

        .field-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2364748b'%3E%3Cpath d='M12 16L6 10H18L12 16Z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.85rem center;
            background-size: 1rem;
            padding-right: 2.2rem;
            cursor: pointer;
        }
        .field-select option { background: #fff; color: var(--text-main); }
        .field-textarea { resize: vertical; min-height: 100px; }

        .field-hint {
            font-size: 0.7rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .field-hint i { font-size: 0.75rem; }

        .deploy-notice {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.7rem 1rem;
            border-radius: 10px;
            background: rgba(245,158,11,0.08);
            border: 1px solid rgba(245,158,11,0.22);
            color: #92400e;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 1.1rem;
        }
        .deploy-notice i { font-size: 0.95rem; color: var(--warning); flex-shrink: 0; }

        .deploy-meta {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
            line-height: 1.5;
        }
        .deploy-meta code { font-size: 0.85em; background: var(--glass); padding: 0.1rem 0.35rem; border-radius: 6px; border: 1px solid var(--card-border); }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 0.85rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--card-border);
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.78rem 1.5rem;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.92rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #021A54, #1e40af);
            color: #fff;
            box-shadow: 0 4px 14px rgba(2,26,84,0.28);
        }
        .btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(2,26,84,0.35); }
        .btn-outline {
            background: var(--card-bg);
            border: 1.5px solid var(--card-border);
            color: var(--text-muted);
        }
        .btn-outline:hover { border-color: var(--danger); color: var(--danger); }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">

    <header class="page-header">
        <div>
            <h1 class="page-title">Register New Network Asset</h1>
            <p class="page-subtitle">Add switches, access points, or other network equipment to the inventory.</p>
        </div>
        <a href="network.php" class="btn-back">
            <i class="ri-arrow-left-line"></i> Back to Inventory
        </a>
    </header>

    <?php if ($success_message): ?>
    <div class="alert alert-success">
        <i class="ri-checkbox-circle-fill"></i>
        <span><?= htmlspecialchars($success_message) ?></span>
        <a href="network.php" class="alert-link">View Inventory →</a>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-error">
        <i class="ri-error-warning-fill"></i>
        <span><?= htmlspecialchars($error_message) ?></span>
    </div>
    <?php endif; ?>

    <?php if (!empty($status_options)): ?>
    <form method="post" action="" id="networkForm">

        <div class="form-section">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-icon"><i class="ri-router-line"></i></div>
                    <div>
                        <div class="section-title">Device Identity</div>
                        <div class="section-desc">Asset ID, serial, status, brand, model, MAC and IP</div>
                    </div>
                </div>
                <span class="badge-tag badge-required">Required</span>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="field">
                        <label class="field-label">
                            Asset ID <span class="req">*</span>
                            <span class="auto-tag"><i class="ri-magic-line"></i> Auto-generated</span>
                        </label>
                        <input type="number" name="asset_id" class="field-input auto" value="<?= (int)$next_asset_id ?>" readonly>
                        <span class="field-hint"><i class="ri-information-line"></i> 24 + year + sequence (e.g. 24260001); separate range from laptops</span>
                    </div>
                    <div class="field">
                        <label class="field-label" for="serial_num">Serial Number <span class="req">*</span></label>
                        <input type="text" id="serial_num" name="serial_num" class="field-input" required placeholder="e.g. device serial">
                    </div>
                    <div class="field">
                        <label class="field-label" for="status_id">System Status <span class="req">*</span></label>
                        <select id="status_id" name="status_id" class="field-select" required>
                            <option value="" disabled selected>Select status…</option>
                            <?php foreach ($status_options as $s): ?>
                            <option value="<?= (int)$s['status_id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= (int)$s['status_id'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="brand">Brand</label>
                        <input type="text" id="brand" name="brand" class="field-input" placeholder="e.g. Cisco">
                    </div>
                    <div class="field">
                        <label class="field-label" for="model">Model</label>
                        <input type="text" id="model" name="model" class="field-input" placeholder="e.g. Catalyst 9200">
                    </div>
                    <div class="field">
                        <label class="field-label" for="mac_address">MAC Address</label>
                        <input type="text" id="mac_address" name="mac_address" class="field-input" placeholder="e.g. AA:BB:CC:DD:EE:FF">
                    </div>
                    <div class="field">
                        <label class="field-label" for="ip_address">IP Address</label>
                        <input type="text" id="ip_address" name="ip_address" class="field-input" placeholder="e.g. 192.168.1.1">
                    </div>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-icon"><i class="ri-shopping-bag-3-line"></i></div>
                    <div>
                        <div class="section-title">Purchase Details</div>
                        <div class="section-desc">PO, delivery order, invoice and cost</div>
                    </div>
                </div>
                <span class="badge-tag badge-optional">Optional</span>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="field">
                        <label class="field-label" for="po_date">PO Date</label>
                        <input type="date" id="po_date" name="po_date" class="field-input">
                    </div>
                    <div class="field">
                        <label class="field-label" for="po_num">PO Number</label>
                        <input type="text" id="po_num" name="po_num" class="field-input" placeholder="e.g. PO-2026-001">
                    </div>
                    <div class="field">
                        <label class="field-label" for="do_date">DO Date</label>
                        <input type="date" id="do_date" name="do_date" class="field-input">
                    </div>
                    <div class="field">
                        <label class="field-label" for="do_num">DO Number</label>
                        <input type="text" id="do_num" name="do_num" class="field-input" placeholder="e.g. DO-2026-001">
                    </div>
                    <div class="field">
                        <label class="field-label" for="invoice_date">Invoice Date</label>
                        <input type="date" id="invoice_date" name="invoice_date" class="field-input">
                    </div>
                    <div class="field">
                        <label class="field-label" for="invoice_num">Invoice Number</label>
                        <input type="text" id="invoice_num" name="invoice_num" class="field-input" placeholder="e.g. INV-2026-001">
                    </div>
                    <div class="field">
                        <label class="field-label" for="purchase_cost">Purchase Cost (RM)</label>
                        <input type="number" step="0.01" id="purchase_cost" name="purchase_cost" class="field-input" placeholder="0.00">
                    </div>
                </div>
            </div>
        </div>

        <div class="form-section section-disabled" id="deploymentSection">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-icon" style="background:rgba(245,158,11,0.1)">
                        <i class="ri-map-pin-user-line" style="color:var(--warning)"></i>
                    </div>
                    <div>
                        <div class="section-title">Deployment Location</div>
                        <div class="section-desc">Required when status is Deploy (3)</div>
                    </div>
                </div>
                <span class="badge-tag badge-deploy">Deploy only</span>
            </div>
            <div class="section-body">
                <div class="deploy-notice">
                    <i class="ri-alert-line"></i>
                    Set status to <strong>Deploy</strong> above to unlock this section.
                </div>
                <p class="deploy-meta">
                    <strong>Recorded:</strong> <code>asset_id</code> = <span id="deployAssetEcho"><?= (int)$next_asset_id ?></span>,
                    <code>staff_id</code> = <code><?= htmlspecialchars($_SESSION['staff_id'] ?? '—') ?></code> (logged-in technician).
                </p>
                <div class="form-grid">
                    <div class="field">
                        <label class="field-label" for="deployment_building">Building <span class="req">*</span></label>
                        <select id="deployment_building" name="deployment_building" class="field-select deploy-input" disabled>
                            <option value="" selected disabled>Select building…</option>
                            <?php foreach (BUILDING_OPTIONS as $b): ?>
                            <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="deployment_level">Level <span class="req">*</span></label>
                        <input type="text" id="deployment_level" name="deployment_level" class="field-input deploy-input" placeholder="e.g. 3, G, Basement" disabled>
                    </div>
                    <div class="field">
                        <label class="field-label" for="deployment_zone">Zone <span class="req">*</span></label>
                        <input type="text" id="deployment_zone" name="deployment_zone" class="field-input deploy-input" placeholder="e.g. Data corner, IDF-2" disabled>
                    </div>
                    <div class="field">
                        <label class="field-label" for="deployment_date">Deployment Date <span class="req">*</span></label>
                        <input type="date" id="deployment_date" name="deployment_date" class="field-input deploy-input" disabled>
                    </div>
                    <div class="field col-3">
                        <label class="field-label" for="deployment_remarks">Deployment Remarks</label>
                        <textarea id="deployment_remarks" name="deployment_remarks" class="field-textarea deploy-input" rows="3" placeholder="e.g. Rack, port, VLAN…" disabled style="min-height:88px;"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-icon"><i class="ri-sticky-note-line"></i></div>
                    <div>
                        <div class="section-title">General Remarks</div>
                        <div class="section-desc">Additional notes about this asset</div>
                    </div>
                </div>
                <span class="badge-tag badge-optional">Optional</span>
            </div>
            <div class="section-body">
                <div class="field">
                    <label class="field-label" for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" class="field-textarea" rows="4"
                        placeholder="e.g. Location, rack, VLAN, warranty notes…"></textarea>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="reset" class="btn btn-outline">
                <i class="ri-refresh-line"></i> Clear Form
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="ri-save-3-line"></i> Register Network Asset
            </button>
        </div>
    </form>
    <?php elseif ($error_message === ''): ?>
    <div class="alert alert-error">
        <i class="ri-error-warning-fill"></i>
        <span>No valid statuses for network (IDs 3,5,6,7,8,9,10). Check <code>status</code> table.</span>
    </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function toggleDropdown(element, event) {
        event.preventDefault();
        var group = element.closest('.nav-group');
        var dropdown = group && group.querySelector('.nav-dropdown');
        element.classList.toggle('open');
        dropdown && dropdown.classList.toggle('show');
    }
    window.toggleDropdown = toggleDropdown;

    function syncDeploymentSection() {
        var sel = document.getElementById('status_id');
        var section = document.getElementById('deploymentSection');
        var inputs = document.querySelectorAll('.deploy-input');
        var deploy = sel && String(sel.value) === '3';
        if (!section) return;
        section.classList.toggle('section-disabled', !deploy);
        inputs.forEach(function (el) {
            el.disabled = !deploy;
            if (!deploy) {
                el.removeAttribute('required');
                if (el.tagName === 'SELECT') {
                    el.selectedIndex = 0;
                } else if (el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'date')) {
                    el.value = '';
                } else if (el.tagName === 'TEXTAREA') {
                    el.value = '';
                }
            } else {
                var req = ['deployment_building', 'deployment_level', 'deployment_zone', 'deployment_date'];
                if (req.indexOf(el.id) !== -1) {
                    el.setAttribute('required', 'required');
                }
            }
        });
    }

    function syncDeployAssetEcho() {
        var assetIn = document.querySelector('input[name="asset_id"]');
        var echo = document.getElementById('deployAssetEcho');
        if (assetIn && echo) echo.textContent = assetIn.value || '—';
    }

    var sel = document.getElementById('status_id');
    if (sel) {
        sel.addEventListener('change', syncDeploymentSection);
        syncDeploymentSection();
    }
    var assetIn = document.querySelector('input[name="asset_id"]');
    if (assetIn) {
        assetIn.addEventListener('input', syncDeployAssetEcho);
    }
    syncDeployAssetEcho();
    var form = document.getElementById('networkForm');
    if (form) {
        form.addEventListener('reset', function () {
            setTimeout(function () {
                syncDeploymentSection();
                syncDeployAssetEcho();
            }, 0);
        });
    }
});
</script>
</body>
</html>
