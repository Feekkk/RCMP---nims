<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$filter_status = isset($_GET['status_id']) && is_numeric($_GET['status_id'])
    ? (int)$_GET['status_id']
    : null;

$in_stock_ids = [1, 2, 5, 6];
$out_stock_ids = [3, 7, 8];
$filter_ids = array_values(array_unique(array_merge($in_stock_ids, $out_stock_ids)));

$stats = [
    'total' => 0,
    'in' => 0,
    'out' => 0,
];
$status_counts = [];
$statusNameById = [];

try {
    $pdo = db();

    $stats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM av')->fetchColumn();
    $stats['in'] = (int)$pdo->query('SELECT COUNT(*) FROM av WHERE status_id IN (' . implode(',', $in_stock_ids) . ')')->fetchColumn();
    $stats['out'] = (int)$pdo->query('SELECT COUNT(*) FROM av WHERE status_id IN (' . implode(',', $out_stock_ids) . ')')->fetchColumn();

    $placeholders = implode(',', array_map('intval', $filter_ids));
    $status_counts = $pdo->query("
        SELECT s.status_id, s.name, COUNT(a.asset_id) AS total
        FROM status s
        LEFT JOIN av a ON a.status_id = s.status_id
        WHERE s.status_id IN ($placeholders)
        GROUP BY s.status_id, s.name
        ORDER BY s.status_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($status_counts as $sc) {
        $sid = (int)$sc['status_id'];
        $statusNameById[$sid] = (string)$sc['name'];
    }
} catch (Throwable $e) {
    $stats = ['total' => 0, 'in' => 0, 'out' => 0];
    $status_counts = [];
    $statusNameById = [];
}

$assets = [];
$dbError = false;
try {
    $pdo = db();
    $sql = "
        SELECT
            a.asset_id,
            a.asset_id_old,
            a.category,
            a.brand,
            a.model,
            a.serial_num,
            a.status_id,
            s.name AS status_name,
            a.PO_DATE,
            a.PO_NUM,
            a.INVOICE_DATE,
            a.INVOICE_NUM,
            a.PURCHASE_COST,
            a.remarks,
            d.building,
            d.level,
            d.zone,
            d.deployment_date,
            u.full_name AS deployed_by_name
        FROM av a
        JOIN status s ON s.status_id = a.status_id
        LEFT JOIN (
            SELECT asset_id, MAX(deployment_id) AS deployment_id
            FROM av_deployment
            GROUP BY asset_id
        ) ld ON ld.asset_id = a.asset_id
        LEFT JOIN av_deployment d ON d.deployment_id = ld.deployment_id
        LEFT JOIN users u ON u.staff_id = d.staff_id
    ";
    $params = [];
    if ($filter_status !== null) {
        $sql .= " WHERE a.status_id = :status_id";
        $params[':status_id'] = $filter_status;
    }
    $sql .= " ORDER BY a.asset_id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError = true;
    $assets = [];
}

function av_badge_meta(int $statusId): array
{
    return match ($statusId) {
        1 => ['icon' => 'ri-checkbox-circle-fill', 'cls' => 'badge-active',   'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.12)', 'border' => 'rgba(16,185,129,0.25)'],
        2 => ['icon' => 'ri-remove-circle-fill',  'cls' => 'badge-nonactive','color' => '#64748b', 'bg' => 'rgba(100,116,139,0.12)', 'border' => 'rgba(100,116,139,0.25)'],
        3 => ['icon' => 'ri-user-received-2-fill','cls' => 'badge-deploy',  'color' => '#2563eb', 'bg' => 'rgba(37,99,235,0.12)', 'border' => 'rgba(37,99,235,0.25)'],
        5 => ['icon' => 'ri-tools-fill',          'cls' => 'badge-maint',   'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.14)', 'border' => 'rgba(245,158,11,0.3)'],
        6 => ['icon' => 'ri-alert-fill',          'cls' => 'badge-faulty',  'color' => '#ef4444', 'bg' => 'rgba(239,68,68,0.14)', 'border' => 'rgba(239,68,68,0.3)'],
        7 => ['icon' => 'ri-delete-bin-fill',    'cls' => 'badge-disposed','color' => '#94a3b8', 'bg' => 'rgba(148,163,184,0.18)', 'border' => 'rgba(148,163,184,0.35)'],
        8 => ['icon' => 'ri-map-pin-line',       'cls' => 'badge-lost',    'color' => '#f97316', 'bg' => 'rgba(249,115,22,0.12)', 'border' => 'rgba(249,115,22,0.28)'],
        default => ['icon' => 'ri-question-line', 'cls' => 'badge-unknown', 'color' => '#64748b', 'bg' => 'rgba(148,163,184,0.18)', 'border' => 'rgba(148,163,184,0.35)'],
    };
}

$searchPlaceholder = 'Search brand, model, serial, category, remarks...';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AV Inventory - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #0ea5e9;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --bg: #f1f5f9;
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
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2.5rem 2.5rem 4rem;
            max-width: calc(100vw - 280px);
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 1rem;
            margin-bottom: 1.75rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--card-border);
        }
        .page-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); margin-top: 0.35rem; font-size: 0.95rem; line-height: 1.4; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 1.1rem 1.2rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.05);
            display: flex;
            align-items: center;
            gap: 0.9rem;
        }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
            border: 1px solid transparent;
            flex-shrink: 0;
        }
        .stat-icon.in { background: rgba(16,185,129,0.12); color: var(--success); border-color: rgba(16,185,129,0.25); }
        .stat-icon.out { background: rgba(37,99,235,0.12); color: var(--primary); border-color: rgba(37,99,235,0.25); }
        .stat-icon.all { background: rgba(14,165,233,0.12); color: var(--secondary); border-color: rgba(14,165,233,0.25); }
        .stat-num { font-family: 'Outfit', sans-serif; font-weight: 900; font-size: 2rem; line-height: 1; }
        .stat-label { color: var(--text-muted); font-weight: 800; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.6px; margin-top: 0.25rem; }

        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }
        .search-box {
            position: relative;
            flex: 1;
            max-width: 520px;
            min-width: 240px;
        }
        .search-box i {
            position: absolute;
            left: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
        }
        .search-input {
            width: 100%;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.85rem 1rem 0.85rem 3rem;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s ease;
        }
        .search-input:focus {
            border-color: rgba(37,99,235,0.35);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.10);
            background: #fff;
        }

        .action-buttons { display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .btn {
            border: none;
            cursor: pointer;
            border-radius: 12px;
            padding: 0.8rem 1.2rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-outline {
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
        }
        .btn-outline:hover {
            background: rgba(37,99,235,0.06);
            border-color: rgba(37,99,235,0.2);
            color: var(--primary);
        }

        .dropdown-container { position: relative; display: inline-block; }
        .action-dropdown {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.6rem;
            min-width: 320px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.12);
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            z-index: 50;
        }
        .action-dropdown.show { display: flex; }
        .filter-section { padding: 0.35rem 0.35rem 0.25rem; }
        .filter-section + .filter-section {
            border-top: 1px solid var(--card-border);
            margin-top: 0.4rem;
            padding-top: 0.6rem;
        }
        .action-dropdown-item {
            padding: 0.6rem 0.8rem;
            border-radius: 10px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            transition: all 0.2s ease;
        }
        .action-dropdown-item:hover {
            background: rgba(37,99,235,0.06);
            color: var(--primary);
        }
        .filter-title {
            font-size: 0.75rem;
            font-weight: 900;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin: 0.1rem 0.4rem 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .filter-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.6rem 0.75rem;
            border-radius: 10px;
            color: var(--text-muted);
            text-decoration: none;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }
        .filter-item:hover {
            background: rgba(37,99,235,0.06);
            color: var(--primary);
            border-color: rgba(37,99,235,0.12);
        }
        .filter-item.active {
            background: rgba(37,99,235,0.10);
            color: var(--primary);
            border-color: rgba(37,99,235,0.20);
        }
        .filter-left { display: inline-flex; align-items: center; gap: 0.6rem; min-width: 0; }
        .filter-left span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .filter-count {
            font-family: 'Outfit', sans-serif;
            font-weight: 900;
            color: var(--text-main);
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
            flex-shrink: 0;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 1.3rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            overflow: hidden;
        }
        .table-responsive { overflow-x: auto; width: 100%; }
        .data-table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        .data-table th {
            text-align: left;
            padding: 1.1rem 0.9rem;
            color: var(--text-muted);
            font-weight: 900;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--card-border);
        }
        .data-table td {
            padding: 1.05rem 0.9rem;
            font-size: 0.95rem;
            border-bottom: 1px dashed rgba(226,232,240,0.9);
            vertical-align: middle;
        }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover { background: rgba(37,99,235,0.03); }

        .asset-cell { display: flex; align-items: center; gap: 0.8rem; }
        .asset-icon {
            width: 40px; height: 40px;
            border-radius: 12px;
            background: rgba(14,165,233,0.12);
            border: 1px solid rgba(14,165,233,0.25);
            display: flex; align-items: center; justify-content: center;
            color: var(--secondary);
            flex-shrink: 0;
            font-size: 1.2rem;
        }
        .asset-meta { min-width: 240px; }
        .asset-name { font-weight: 900; }
        .asset-sub { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.12rem; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.38rem 0.75rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 900;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .badge-active { background: rgba(16,185,129,0.12); color: #10b981; border-color: rgba(16,185,129,0.25); }
        .badge-nonactive { background: rgba(100,116,139,0.12); color: #64748b; border-color: rgba(100,116,139,0.25); }
        .badge-deploy { background: rgba(37,99,235,0.12); color: #2563eb; border-color: rgba(37,99,235,0.25); }
        .badge-maint { background: rgba(245,158,11,0.14); color: #f59e0b; border-color: rgba(245,158,11,0.3); }
        .badge-faulty { background: rgba(239,68,68,0.14); color: #ef4444; border-color: rgba(239,68,68,0.3); }
        .badge-disposed { background: rgba(148,163,184,0.18); color: #94a3b8; border-color: rgba(148,163,184,0.35); }
        .badge-lost { background: rgba(249,115,22,0.12); color: #f97316; border-color: rgba(249,115,22,0.28); }
        .badge-unknown { background: rgba(148,163,184,0.18); color: #64748b; border-color: rgba(148,163,184,0.35); }

        .row-actions { text-align: right; }
        .icon-btn {
            width: 38px; height: 38px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: var(--glass-panel);
            color: var(--text-muted);
            cursor: not-allowed;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            opacity: 0.6;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding-top: 1.1rem;
            border-top: 1px solid rgba(226,232,240,0.8);
            margin-top: 1rem;
        }
        .page-info { color: var(--text-muted); font-size: 0.9rem; font-weight: 700; }

        @media (max-width: 1100px) {
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
        }
        @media (max-width: 900px) {
            .action-dropdown { min-width: 280px; right: 0; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
        <?php if ($dbError): ?>
            <div style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #b91c1c; padding: 0.9rem 1.1rem; border-radius: 12px; margin-bottom: 1.25rem; font-weight: 600;">
                <i class="ri-error-warning-line"></i> Could not load AV data. Ensure <code>db/schema.sql</code> is applied.
            </div>
        <?php endif; ?>

        <header class="page-header">
            <div class="page-title">
                <h1><i class="ri-vidicon-line"></i> AV Inventory</h1>
                <p>View, search, and filter all registered audio/visual assets.</p>
            </div>
        </header>

        <section class="stats-grid" aria-label="Stock summary">
            <div class="stat-card">
                <div class="stat-icon all"><i class="ri-apps-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$stats['total'] ?></div>
                    <div class="stat-label">Total assets</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon in"><i class="ri-box-3-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$stats['in'] ?></div>
                    <div class="stat-label">In-stock</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon out"><i class="ri-truck-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$stats['out'] ?></div>
                    <div class="stat-label">Out-stock</div>
                </div>
            </div>
        </section>

        <div class="table-controls">
            <div class="search-box">
                <i class="ri-search-2-line"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="<?= htmlspecialchars($searchPlaceholder) ?>">
            </div>

            <div class="action-buttons">
                <?php
                    $countsByStatus = [];
                    foreach ($status_counts as $sc) {
                        $countsByStatus[(int)$sc['status_id']] = (int)$sc['total'];
                    }
                ?>
                <div class="dropdown-container">
                    <button class="btn btn-outline" type="button" onclick="toggleRegisterDropdown(this, event)">
                        <i class="ri-add-line"></i> Register asset
                        <i class="ri-arrow-down-s-line" style="margin-left:4px;"></i>
                    </button>
                    <div class="action-dropdown" id="registerDropdown" onclick="event.stopPropagation()">
                        <a href="avAdd.php" class="action-dropdown-item">
                            <i class="ri-macbook-line" style="color: var(--primary);"></i> Single asset
                        </a>
                        <a href="avCSV.php" class="action-dropdown-item">
                            <i class="ri-stack-line" style="color: var(--secondary);"></i> Import CSV
                        </a>
                    </div>
                </div>
                <div class="dropdown-container">
                    <button class="btn btn-outline" type="button" onclick="toggleFilterDropdown(this, event)">
                        <i class="ri-filter-3-line"></i>
                        <?= $filter_status === null ? 'Filter' : 'Filtered' ?>
                        <i class="ri-arrow-down-s-line" style="margin-left:4px;"></i>
                    </button>

                    <div class="action-dropdown filter-dropdown" id="filterDropdown" onclick="event.stopPropagation()">
                        <div class="filter-section">
                            <div class="filter-title"><i class="ri-apps-line" style="color: var(--primary)"></i> All Assets</div>
                            <a class="filter-item <?= $filter_status === null ? 'active' : '' ?>" href="av.php">
                                <span class="filter-left"><i class="ri-box-3-line" style="color: var(--primary)"></i><span>All</span></span>
                                <span class="filter-count"><?= (int)$stats['total'] ?></span>
                            </a>
                        </div>

                        <div class="filter-section">
                            <div class="filter-title"><i class="ri-box-3-line" style="color: var(--success)"></i> Stock</div>
                            <?php foreach ($in_stock_ids as $sid):
                                $active = ($filter_status === $sid);
                                $label = $statusNameById[$sid] ?? ('Status ' . $sid);
                                $meta = av_badge_meta($sid);
                            ?>
                                <a class="filter-item <?= $active ? 'active' : '' ?>" href="av.php?status_id=<?= (int)$sid ?>">
                                    <span class="filter-left">
                                        <i class="<?= htmlspecialchars($meta['icon']) ?>" style="color: <?= htmlspecialchars($meta['color']) ?>"></i>
                                        <span><?= htmlspecialchars($label) ?></span>
                                    </span>
                                    <span class="filter-count"><?= (int)($countsByStatus[$sid] ?? 0) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <div class="filter-section">
                            <div class="filter-title"><i class="ri-truck-line" style="color: var(--primary)"></i> Out-stock</div>
                            <?php foreach ($out_stock_ids as $sid):
                                $active = ($filter_status === $sid);
                                $label = $statusNameById[$sid] ?? ('Status ' . $sid);
                                $meta = av_badge_meta($sid);
                            ?>
                                <a class="filter-item <?= $active ? 'active' : '' ?>" href="av.php?status_id=<?= (int)$sid ?>">
                                    <span class="filter-left">
                                        <i class="<?= htmlspecialchars($meta['icon']) ?>" style="color: <?= htmlspecialchars($meta['color']) ?>"></i>
                                        <span><?= htmlspecialchars($label) ?></span>
                                    </span>
                                    <span class="filter-count"><?= (int)($countsByStatus[$sid] ?? 0) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="glass-card">
            <div class="table-responsive">
                <table class="data-table" id="assetTable">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Asset ID</th>
                            <th>Serial No</th>
                            <th>Deployed to</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="assetTbody">
                        <?php if (empty($assets)): ?>
                            <tr>
                                <td colspan="6">
                                    <div style="text-align:center; padding: 3.25rem 1rem; color: var(--text-muted);">
                                        <i class="ri-inbox-line" style="font-size:2rem; display:block; margin-bottom:0.6rem;"></i>
                                        No AV assets found<?= $filter_status !== null ? ' for this status' : '' ?>.
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assets as $row):
                                $sid = (int)$row['status_id'];
                                $meta = av_badge_meta($sid);
                                $deviceName = trim(($row['category'] ?? '') . ' ' . ($row['brand'] ?? '') . ' ' . ($row['model'] ?? ''));
                                if ($deviceName === '') $deviceName = 'AV Asset';
                                $deployTo = '—';
                                if ($sid === 3) {
                                    $parts = [];
                                    if (!empty($row['building'])) $parts[] = (string)$row['building'];
                                    if (!empty($row['level'])) $parts[] = (string)$row['level'];
                                    if (!empty($row['zone'])) $parts[] = (string)$row['zone'];
                                    $deployTo = $parts ? implode(' / ', $parts) : '—';
                                }
                            ?>
                                <tr class="av-row">
                                    <td>
                                        <div class="asset-cell">
                                            <div class="asset-icon"><i class="ri-vidicon-line"></i></div>
                                            <div class="asset-meta">
                                                <div class="asset-name"><?= htmlspecialchars($deviceName) ?></div>
                                                <div class="asset-sub">
                                                    PO: <?= !empty($row['PO_DATE']) ? htmlspecialchars(date('d M Y', strtotime((string)$row['PO_DATE']))) : '—' ?>
                                                    <?php if (!empty($row['asset_id_old'])): ?> &bull; Old: <?= htmlspecialchars((string)$row['asset_id_old']) ?><?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><code style="background:var(--glass-panel);padding:3px 8px;border-radius:8px;font-weight:900;color:var(--primary);"><?= htmlspecialchars((string)$row['asset_id']) ?></code></td>
                                    <td style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?= htmlspecialchars((string)($row['serial_num'] ?? '—')) ?></td>
                                    <td><?= htmlspecialchars($deployTo) ?></td>
                                    <td>
                                        <span class="badge <?= htmlspecialchars($meta['cls']) ?>"><i class="<?= htmlspecialchars($meta['icon']) ?>"></i> <?= htmlspecialchars((string)($row['status_name'] ?? '—')) ?></span>
                                    </td>
                                    <td class="row-actions">
                                        <button class="icon-btn" type="button" title="View (soon)" disabled><i class="ri-eye-line"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                <div class="page-info">
                    Showing <strong><span id="rowCount">0</span></strong> item(s)
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleFilterDropdown(button, event) {
            event.stopPropagation();
            const dropdown = document.getElementById('filterDropdown');
            dropdown.classList.toggle('show');
        }

        function toggleRegisterDropdown(button, event) {
            event.stopPropagation();
            const dropdown = document.getElementById('registerDropdown');
            dropdown && dropdown.classList.toggle('show');
        }

        document.addEventListener('click', () => {
            const dropdown = document.getElementById('filterDropdown');
            dropdown && dropdown.classList.remove('show');
            const reg = document.getElementById('registerDropdown');
            reg && reg.classList.remove('show');
        });

        const searchInput = document.getElementById('searchInput');
        const tbody = document.getElementById('assetTbody');
        const rowCount = document.getElementById('rowCount');

        function updateCounts() {
            const rows = Array.from(tbody.querySelectorAll('tr.av-row'));
            const visible = rows.filter(r => r.style.display !== 'none');
            rowCount.textContent = visible.length.toString();
        }

        function applyFilters() {
            const q = (searchInput.value || '').toLowerCase();
            const rows = Array.from(tbody.querySelectorAll('tr.av-row'));
            rows.forEach(row => {
                const textOk = row.innerText.toLowerCase().includes(q);
                row.style.display = textOk ? '' : 'none';
            });
            updateCounts();
        }

        searchInput && searchInput.addEventListener('input', applyFilters);
        updateCounts();
    </script>
</body>
</html>