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
            $norm = preg_replace('/[:-.\s]/', '', $mac_address);
            if (strlen($norm) !== 12 || !ctype_xdigit($norm)) {
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
    <title>Register Network Asset - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #0ea5e9;
            --danger: #ef4444;
            --bg: #f1f5f9;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass-panel: #f8fafc;
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
        .sidebar {
            width: 280px;
            height: 100vh;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--card-border);
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            padding: 1.5rem;
            z-index: 100;
            box-shadow: 4px 0 20px rgba(15,23,42,0.06);
        }
        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--card-border);
            margin-bottom: 2rem;
        }
        .sidebar-logo img { height: 45px; }
        .nav-menu { display: flex; flex-direction: column; gap: 0.75rem; flex: 1; }
        .nav-item {
            padding: 0.85rem 1.25rem;
            border-radius: 12px;
            color: var(--text-muted);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 500;
            transition: all 0.25s ease;
        }
        .nav-item:hover { color: var(--primary); background: rgba(37,99,235,0.06); }
        .nav-item.active {
            color: var(--primary);
            background: rgba(37,99,235,0.1);
            border: 1px solid rgba(37,99,235,0.2);
            box-shadow: inset 3px 0 0 var(--primary);
        }
        .nav-item i { font-size: 1.25rem; }
        .nav-dropdown { display: none; flex-direction: column; gap: 0.25rem; padding-left: 3.25rem; margin-top: -0.25rem; margin-bottom: 0.25rem; }
        .nav-dropdown.show { display: flex; }
        .nav-dropdown-item {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .nav-dropdown-item:hover { color: var(--primary); background: rgba(37,99,235,0.06); }
        .nav-dropdown-item.active { color: var(--primary); }
        .nav-item.open .chevron { transform: rotate(180deg); }
        .user-profile {
            margin-top: auto;
            padding: 1rem;
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 1rem;
            cursor: pointer;
        }
        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            color: #fff;
            font-size: 1.1rem;
        }
        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-size: 0.9rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role { font-size: 0.75rem; color: var(--primary); margin-top: 0.2rem; text-transform: uppercase; font-weight: 600; }

        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2.5rem 3.5rem 5rem;
            max-width: calc(100vw - 280px);
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--card-border);
            flex-wrap: wrap;
            gap: 1rem;
        }
        .page-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); margin-top: 0.35rem; font-size: 0.95rem; }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            background: var(--glass-panel);
            padding: 0.6rem 1.2rem;
            border-radius: 12px;
            border: 1px solid var(--card-border);
        }
        .btn-back:hover { color: var(--primary); border-color: rgba(37,99,235,0.2); }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 14px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.95rem;
        }
        .alert-success { background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.35); color: #059669; }
        .alert-error { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.35); color: #dc2626; }

        .form-section {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2rem 2.25rem;
            margin-bottom: 1.75rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            position: relative;
            overflow: hidden;
        }
        .form-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            opacity: 0.75;
        }
        .section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .section-title i {
            color: var(--secondary);
            background: rgba(14,165,233,0.15);
            padding: 0.45rem;
            border-radius: 10px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.25rem 1.75rem;
        }
        .form-group { display: flex; flex-direction: column; gap: 0.45rem; }
        .form-label { font-size: 0.85rem; font-weight: 500; color: var(--text-muted); }
        .form-label span { color: var(--danger); }
        .hint { font-size: 0.78rem; color: var(--text-muted); margin-top: -0.2rem; }
        .form-input, .form-select, .form-textarea {
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.85rem 1.1rem;
            font-size: 0.95rem;
            font-family: inherit;
            color: var(--text-main);
            width: 100%;
            outline: none;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .form-input:read-only { cursor: default; background: rgba(37,99,235,0.06); font-weight: 700; color: var(--primary); }
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2364748b'%3E%3Cpath d='M12 16L6 10H18L12 16Z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1.2rem;
            cursor: pointer;
        }
        .form-textarea { min-height: 100px; resize: vertical; }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        .btn {
            padding: 0.95rem 1.75rem;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            box-shadow: 0 8px 20px rgba(37,99,235,0.35);
        }
        .btn-primary:hover { filter: brightness(1.05); transform: translateY(-1px); }
        .btn-outline {
            background: transparent;
            border: 1px solid var(--text-muted);
            color: var(--text-muted);
        }
        .btn-outline:hover { color: var(--primary); border-color: rgba(37,99,235,0.3); }

        .section-disabled {
            opacity: 0.45;
            pointer-events: none;
            max-height: 0;
            margin: 0;
            padding: 0;
            overflow: hidden;
            border: none;
            box-shadow: none;
        }
        .section-disabled::before { display: none; }
        .form-section:not(.section-disabled) { transition: opacity 0.25s ease; }
        .badge-optional {
            font-size: 0.72rem;
            font-weight: 600;
            color: #94a3b8;
            background: rgba(148,163,184,0.15);
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            margin-left: 0.5rem;
        }

        @media (max-width: 1100px) {
            .form-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <div class="page-title">
                <h1><i class="ri-router-line"></i> Register network asset</h1>
                <p>Maps to <code>network</code> table — status IDs 9/10 Online/Offline, plus Deploy, Maintenance, Faulty, Disposed, Lost.</p>
            </div>
            <a href="network.php" class="btn-back"><i class="ri-arrow-left-line"></i> Back to inventory</a>
        </header>

        <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="ri-checkbox-circle-fill" style="font-size:1.25rem;"></i>
            <span><?= htmlspecialchars($success_message) ?> <a href="network.php" style="color:inherit;font-weight:700;">View list →</a></span>
        </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-error">
            <i class="ri-error-warning-fill" style="font-size:1.25rem;"></i>
            <span><?= htmlspecialchars($error_message) ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($status_options)): ?>
        <form method="post" action="">
            <div class="form-section">
                <h2 class="section-title"><i class="ri-cpu-line"></i> Device</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Asset ID <span>*</span></label>
                        <input type="number" name="asset_id" class="form-input" value="<?= (int)$next_asset_id ?>" readonly>
                        <p class="hint">Auto: 24 + year + sequence (e.g. 24260001). Separate range from laptop IDs.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="serial_num">Serial number <span>*</span></label>
                        <input type="text" id="serial_num" name="serial_num" class="form-input" required placeholder="Device serial">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="status_id">Status <span>*</span></label>
                        <select id="status_id" name="status_id" class="form-select" required>
                            <option value="" disabled selected>Select status</option>
                            <?php foreach ($status_options as $s): ?>
                            <option value="<?= (int)$s['status_id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= (int)$s['status_id'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="brand">Brand</label>
                        <input type="text" id="brand" name="brand" class="form-input" placeholder="e.g. Cisco">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="model">Model</label>
                        <input type="text" id="model" name="model" class="form-input" placeholder="e.g. Catalyst 9200">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mac_address">MAC address</label>
                        <input type="text" id="mac_address" name="mac_address" class="form-input" placeholder="AA:BB:CC:DD:EE:FF">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ip_address">IP address</label>
                        <input type="text" id="ip_address" name="ip_address" class="form-input" placeholder="192.168.1.1">
                    </div>
                </div>
            </div>

            <div class="form-section section-disabled" id="deploymentSection">
                <h2 class="section-title">
                    <i class="ri-map-pin-user-line"></i> Deployment
                    <span class="badge-optional">When status is Deploy (3)</span>
                </h2>
                <p class="hint" style="margin-bottom:1rem;">
                    <strong>Auto-filled:</strong> <code>asset_id</code> = <span id="deployAssetEcho"><?= (int)$next_asset_id ?></span>, <code>staff_id</code> = <code><?= htmlspecialchars($_SESSION['staff_id'] ?? '—') ?></code> (logged-in technician).
                </p>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="deployment_building">Building <span>*</span></label>
                        <select id="deployment_building" name="deployment_building" class="form-select deploy-input" disabled>
                            <option value="" selected disabled>Select building</option>
                            <?php foreach (BUILDING_OPTIONS as $b): ?>
                            <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="deployment_level">Level <span>*</span></label>
                        <input type="text" id="deployment_level" name="deployment_level" class="form-input deploy-input" placeholder="e.g. 3, G, Basement" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="deployment_zone">Zone <span>*</span></label>
                        <input type="text" id="deployment_zone" name="deployment_zone" class="form-input deploy-input" placeholder="e.g. Data corner, IDF-2" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="deployment_date">Deployment date <span>*</span></label>
                        <input type="date" id="deployment_date" name="deployment_date" class="form-input deploy-input" disabled>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label" for="deployment_remarks">Remarks <span class="badge-optional" style="margin-left:0;">Optional</span></label>
                        <textarea id="deployment_remarks" name="deployment_remarks" class="form-textarea deploy-input" rows="3" placeholder="Rack, port, VLAN…" disabled style="min-height:88px;"></textarea>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="section-title"><i class="ri-shopping-bag-3-line"></i> Purchase &amp; invoice</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="po_date">PO date</label>
                        <input type="date" id="po_date" name="po_date" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="po_num">PO number</label>
                        <input type="text" id="po_num" name="po_num" class="form-input" placeholder="PO-2026-001">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="do_date">DO date</label>
                        <input type="date" id="do_date" name="do_date" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="do_num">DO number</label>
                        <input type="text" id="do_num" name="do_num" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="invoice_date">Invoice date</label>
                        <input type="date" id="invoice_date" name="invoice_date" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="invoice_num">Invoice number</label>
                        <input type="text" id="invoice_num" name="invoice_num" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="purchase_cost">Purchase cost (RM)</label>
                        <input type="number" step="0.01" id="purchase_cost" name="purchase_cost" class="form-input" placeholder="0.00">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2 class="section-title"><i class="ri-sticky-note-line"></i> Remarks</h2>
                <div class="form-group">
                    <label class="form-label" for="remarks">Notes</label>
                    <textarea id="remarks" name="remarks" class="form-textarea" placeholder="Location, rack, VLAN, warranty notes…"></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="reset" class="btn btn-outline">Clear</button>
                <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save network asset</button>
            </div>
        </form>
        <?php elseif ($error_message === ''): ?>
        <div class="alert alert-error">No valid statuses for network (IDs 3,5,6,7,8,9,10). Check <code>status</code> table.</div>
        <?php endif; ?>
    </main>

    <script>
        function toggleDropdown(element, event) {
            event.preventDefault();
            const group = element.closest('.nav-group');
            const dropdown = group.querySelector('.nav-dropdown');
            element.classList.toggle('open');
            dropdown.classList.toggle('show');
        }

        function syncDeploymentSection() {
            const sel = document.getElementById('status_id');
            const section = document.getElementById('deploymentSection');
            const inputs = document.querySelectorAll('.deploy-input');
            const deploy = sel && String(sel.value) === '3';
            if (!section) return;
            section.classList.toggle('section-disabled', !deploy);
            inputs.forEach(el => {
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
                    const req = ['deployment_building', 'deployment_level', 'deployment_zone', 'deployment_date'];
                    if (req.includes(el.id)) {
                        el.setAttribute('required', 'required');
                    }
                }
            });
        }

        function syncDeployAssetEcho() {
            const assetIn = document.querySelector('input[name="asset_id"]');
            const echo = document.getElementById('deployAssetEcho');
            if (assetIn && echo) echo.textContent = assetIn.value || '—';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const sel = document.getElementById('status_id');
            if (sel) {
                sel.addEventListener('change', syncDeploymentSection);
                syncDeploymentSection();
            }
            const assetIn = document.querySelector('input[name="asset_id"]');
            assetIn?.addEventListener('input', syncDeployAssetEcho);
            syncDeployAssetEcho();
            document.querySelector('form')?.addEventListener('reset', () => {
                setTimeout(() => { syncDeploymentSection(); syncDeployAssetEcho(); }, 0);
            });
        });
    </script>
</body>
</html>
