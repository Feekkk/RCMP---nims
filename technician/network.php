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
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 10;

$stats = [
    'total'        => 0,
    'online'       => 0,
    'offline'      => 0,
    'maint_faulty' => 0,
];
$assets = [];
$status_counts = [];
$filteredTotal = 0;
$totalPages = 1;
$offset = 0;
$dbError = false;

try {
    $pdo = db();
    $stats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM network')->fetchColumn();
    $stats['online'] = (int)$pdo->query('SELECT COUNT(*) FROM network WHERE status_id = 9')->fetchColumn();
    $stats['offline'] = (int)$pdo->query('SELECT COUNT(*) FROM network WHERE status_id = 10')->fetchColumn();
    $stats['maint_faulty'] = (int)$pdo->query(
        'SELECT COUNT(*) FROM network WHERE status_id IN (5, 6)'
    )->fetchColumn();
    $status_counts = $pdo->query("
        SELECT s.status_id, s.name, COUNT(n.asset_id) AS total
        FROM status s
        LEFT JOIN network n ON n.status_id = s.status_id
        WHERE s.status_id IN (3,5,6,7,8,9,10)
        GROUP BY s.status_id, s.name
        ORDER BY s.status_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $countSql = 'SELECT COUNT(*) FROM network n';
    $countParams = [];
    if ($filter_status !== null) {
        $countSql .= ' WHERE n.status_id = :status_id';
        $countParams[':status_id'] = $filter_status;
    }
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $filteredTotal = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($filteredTotal / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = '
        SELECT n.asset_id, n.serial_num, n.brand, n.model, n.mac_address, n.ip_address,
               n.status_id, n.remarks, s.name AS status_name
        FROM network n
        JOIN status s ON s.status_id = n.status_id
    ';
    $params = [];
    if ($filter_status !== null) {
        $sql .= ' WHERE n.status_id = :status_id';
        $params[':status_id'] = $filter_status;
    }
    $sql .= ' ORDER BY n.asset_id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError = true;
    $assets = [];
    $status_counts = [];
    $filteredTotal = 0;
    $totalPages = 1;
    $offset = 0;
}

function network_filter_status(int $statusId): string
{
    if ($statusId === 9) {
        return 'online';
    }
    if ($statusId === 10) {
        return 'offline';
    }
    return 'other';
}

function network_badge_class(int $statusId): string
{
    return match ($statusId) {
        9 => 'badge-online',
        10 => 'badge-offline',
        3 => 'badge-deploy',
        5 => 'badge-maint',
        6 => 'badge-faulty',
        7 => 'badge-disposed',
        8 => 'badge-lost',
        default => 'badge-unknown',
    };
}

$rowStart = $filteredTotal > 0 ? ($offset + 1) : 0;
$rowEnd = min($offset + count($assets), $filteredTotal);
$baseParams = [];
if ($filter_status !== null) {
    $baseParams['status_id'] = $filter_status;
}
$prevParams = $baseParams;
$prevParams['page'] = max(1, $page - 1);
$nextParams = $baseParams;
$nextParams['page'] = min($totalPages, $page + 1);
$prevHref = 'network.php?' . http_build_query($prevParams);
$nextHref = 'network.php?' . http_build_query($nextParams);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Inventory - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --secondary: #0ea5e9;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --bg: #f1f5f9;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass-panel: #f8fafc;
            --glass-border: #e2e8f0;
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
            transition: transform 0.3s ease;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--glass-border);
            margin-bottom: 2rem;
        }

        .sidebar-logo img {
            height: 45px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            flex: 1;
        }

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

        .nav-item:hover {
            color: var(--primary);
            background: rgba(37, 99, 235, 0.06);
        }

        .nav-item.active {
            color: var(--primary);
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: inset 3px 0 0 var(--primary);
        }

        .nav-item i { font-size: 1.25rem; color: inherit; }

        .nav-dropdown {
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            padding-left: 3.25rem;
            margin-top: -0.25rem;
            margin-bottom: 0.25rem;
        }

        .nav-dropdown.show { display: flex; }

        .nav-dropdown-item {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.25s ease;
            position: relative;
        }

        .nav-dropdown-item::before {
            content: '';
            position: absolute;
            left: -1rem;
            top: 50%;
            width: 6px;
            height: 6px;
            background: var(--card-border);
            border-radius: 50%;
            transform: translateY(-50%);
            transition: all 0.25s ease;
        }

        .nav-dropdown-item:hover {
            color: var(--primary);
            background: rgba(37,99,235,0.06);
        }

        .nav-dropdown-item:hover::before,
        .nav-dropdown-item.active::before {
            background: var(--primary);
        }

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
            transition: all 0.25s ease;
            cursor: pointer;
        }

        .user-profile:hover {
            background: rgba(37,99,235,0.06);
            border-color: rgba(37,99,235,0.2);
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
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
        }

        .user-info { flex: 1; overflow: hidden; }
        .user-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .user-role {
            font-size: 0.75rem;
            color: var(--primary);
            margin-top: 0.2rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2.5rem 3.5rem 5rem;
            max-width: calc(100vw - 280px);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 1.75rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--card-border);
        }

        .page-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.1rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); margin-top: 0.35rem; }

        .header-actions { display: flex; gap: 0.75rem; }
        .btn {
            padding: 0.75rem 1.2rem;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.25);
        }
        .btn-primary:hover { transform: translateY(-2px); filter: brightness(1.06); }
        .btn-outline {
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
        }
        .btn-outline:hover { color: var(--primary); border-color: rgba(37,99,235,0.25); background: rgba(37,99,235,0.06); }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

        .dropdown-container { position: relative; display: inline-block; }
        .action-dropdown {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.5rem;
            min-width: 180px;
            box-shadow: 0 8px 25px rgba(15,23,42,0.12);
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            z-index: 50;
        }
        .action-dropdown.show { display: flex; }
        .action-dropdown-item {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .action-dropdown-item:hover {
            background: rgba(37, 99, 235, 0.06);
            color: var(--primary);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .stat-icon {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; flex-shrink: 0;
        }
        .icon-blue { background: rgba(37,99,235,0.12); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); }
        .icon-green { background: rgba(16,185,129,0.12); color: var(--success); border: 1px solid rgba(16,185,129,0.25); }
        .icon-red { background: rgba(239,68,68,0.12); color: var(--danger); border: 1px solid rgba(239,68,68,0.25); }
        .icon-amber { background: rgba(245,158,11,0.12); color: var(--warning); border: 1px solid rgba(245,158,11,0.25); }
        .stat-num { font-family:'Outfit',sans-serif; font-size: 1.9rem; font-weight: 800; line-height: 1; }
        .stat-label { margin-top: 0.25rem; font-size: 0.78rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; }
        .stat-schema {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 0.35rem;
            line-height: 1.35;
            opacity: 0.9;
        }

        .alert-db {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #b91c1c;
            padding: 0.85rem 1.1rem;
            border-radius: 12px;
            font-size: 0.9rem;
            margin-bottom: 1.25rem;
            font-weight: 500;
        }

        /* Match laptop.php table list design */
        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .search-box {
            position: relative;
            flex: 1;
            max-width: 520px;
            min-width: 260px;
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
            color: var(--text-main);
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: all 0.25s ease;
            outline: none;
        }
        .search-input:focus {
            background: #fff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .action-buttons { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }

        /* Filter dropdown (status sub-filter) */
        .filter-dropdown { min-width: 320px; padding: 0.6rem; }
        .filter-section { padding: 0.35rem 0.35rem 0.25rem; }
        .filter-section + .filter-section {
            border-top: 1px solid var(--card-border);
            margin-top: 0.4rem;
            padding-top: 0.6rem;
        }
        .filter-title {
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin: 0.15rem 0.4rem 0.5rem;
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
            text-decoration: none;
            color: var(--text-muted);
            transition: all 0.2s ease;
            border: 1px solid transparent;
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
            font-weight: 800;
            color: var(--text-main);
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            overflow: hidden;
        }
        .table-responsive { overflow-x: auto; width: 100%; }
        .data-table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        .data-table th {
            text-align: left;
            padding: 1.25rem 1rem;
            color: var(--text-muted);
            font-weight: 800;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--card-border);
        }
        .data-table td {
            padding: 1.25rem 1rem;
            font-size: 0.95rem;
            border-bottom: 1px dashed var(--card-border);
            color: var(--text-main);
            vertical-align: middle;
        }
        .data-table tbody tr { transition: background 0.25s ease; }
        .data-table tbody tr:hover { background: rgba(37,99,235,0.03); }
        .data-table tr:last-child td { border-bottom: none; }

        .asset-cell { display: flex; align-items: center; gap: 0.8rem; }
        .asset-icon {
            width: 40px; height: 40px; border-radius: 12px;
            background: rgba(14,165,233,0.12);
            border: 1px solid rgba(14,165,233,0.25);
            display: flex; align-items: center; justify-content: center;
            color: var(--secondary);
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .asset-meta { min-width: 220px; }
        .asset-name { font-weight: 800; }
        .asset-sub { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.12rem; }

        .badge {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .badge-online { background: rgba(16,185,129,0.12); color: var(--success); border-color: rgba(16,185,129,0.25); }
        .badge-offline { background: rgba(239,68,68,0.12); color: var(--danger); border-color: rgba(239,68,68,0.25); }
        .badge-deploy { background: rgba(37,99,235,0.12); color: var(--primary); border-color: rgba(37,99,235,0.25); }
        .badge-maint { background: rgba(245,158,11,0.14); color: var(--warning); border-color: rgba(245,158,11,0.3); }
        .badge-faulty { background: rgba(239,68,68,0.14); color: var(--danger); border-color: rgba(239,68,68,0.3); }
        .badge-disposed { background: rgba(100,116,139,0.15); color: #64748b; border-color: rgba(100,116,139,0.35); }
        .badge-lost { background: rgba(249,115,22,0.12); color: #ea580c; border-color: rgba(249,115,22,0.28); }
        .badge-unknown { background: rgba(148,163,184,0.18); color: #64748b; border-color: rgba(148,163,184,0.35); }

        .row-actions { display: flex; gap: 0.4rem; }
        .icon-btn {
            width: 38px; height: 38px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: var(--glass-panel);
            color: var(--text-muted);
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .icon-btn:hover { color: var(--primary); border-color: rgba(37,99,235,0.25); background: rgba(37,99,235,0.06); transform: translateY(-1px); }

        .empty {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }
        .empty i { font-size: 3rem; display: block; margin-bottom: 0.75rem; color: #cbd5e1; }
        .empty h3 { font-family:'Outfit',sans-serif; color: var(--text-main); font-size: 1.25rem; margin-bottom: 0.4rem; }
        .empty p { max-width: 560px; margin: 0 auto; line-height: 1.5; }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding-top: 1.25rem;
            border-top: 1px solid rgba(226,232,240,0.6);
            margin-top: 1rem;
        }
        .page-info { color: var(--text-muted); font-size: 0.9rem; font-weight: 600; }
        .page-nav { display: inline-flex; align-items: center; gap: 0.5rem; }
        .page-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 0.85rem;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: var(--glass-panel);
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.88rem;
        }
        .page-btn:hover { border-color: rgba(37,99,235,0.2); color: var(--primary); background: rgba(37,99,235,0.06); }
        .page-btn.disabled { pointer-events: none; opacity: 0.45; }

        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .header-actions { width: 100%; }
            .table-controls { flex-direction: column; align-items: stretch; }
            .search-box { max-width: none; }
        }
    </style>
</head>
<body>
    
    <!-- Sidebar -->
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
        <?php if ($dbError): ?>
        <div class="alert-db" role="alert">
            <i class="ri-error-warning-line"></i>
            Could not load <code>network</code> data. Ensure <code>db/schema.sql</code> is applied (table <code>network</code> + <code>status</code>).
        </div>
        <?php endif; ?>

        <header class="page-header">
            <div class="page-title">
                <h1><i class="ri-router-line"></i> Network Inventory</h1>
                <p>View and manage registered network assets (switches, routers, APs, firewalls).</p>
            </div>
            <div class="header-actions">
                <div class="dropdown-container">
                    <button type="button" class="btn btn-primary" onclick="toggleRegisterDropdown(this, event)">
                        <i class="ri-add-line"></i> Register asset <i class="ri-arrow-down-s-line" style="margin-left:4px;"></i>
                    </button>
                    <div class="action-dropdown" id="registerDropdown" onclick="event.stopPropagation()">
                        <a href="networkAdd.php" class="action-dropdown-item"><i class="ri-router-line" style="color:var(--primary);"></i> Single asset</a>
                        <a href="networkCSV.php" class="action-dropdown-item"><i class="ri-stack-line" style="color:var(--secondary);"></i> Bulk assets</a>
                    </div>
                </div>
            </div>
        </header>

        <section class="stats-grid" aria-label="Network inventory summary">
            <div class="stat-card" title="All rows in network table">
                <div class="stat-icon icon-blue"><i class="ri-router-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$stats['total'] ?></div>
                    <div class="stat-label">Total network assets</div>
                </div>
            </div>
            <div class="stat-card" title="status table: Online">
                <div class="stat-icon icon-green"><i class="ri-wifi-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$stats['online'] ?></div>
                    <div class="stat-label">Online</div>
                </div>
            </div>
            <div class="stat-card" title="status table: Offline">
                <div class="stat-icon icon-red"><i class="ri-wifi-off-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$stats['offline'] ?></div>
                    <div class="stat-label">Offline</div>
                </div>
            </div>
            <div class="stat-card" title="Per network.status_id comment: Maintenance + Faulty">
                <div class="stat-icon icon-amber"><i class="ri-tools-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$stats['maint_faulty'] ?></div>
                    <div class="stat-label">Maintenance / Faulty</div>
                </div>
            </div>
        </section>

        <div class="table-controls">
            <div class="search-box">
                <i class="ri-search-2-line"></i>
                <input id="searchInput" class="search-input" type="text" placeholder="Search asset ID, serial, brand, model, IP, MAC, status, remarks...">
            </div>
            <div class="action-buttons">
                <?php
                    $stock_ids = [9, 10, 5, 6];   // Online, Offline, Maintenance, Faulty
                    $out_stock_ids = [3, 7, 8];   // Deploy, Disposed, Lost

                    $countsById = [];
                    $nameById = [];
                    foreach ($status_counts as $sc) {
                        $countsById[(int)$sc['status_id']] = (int)$sc['total'];
                        $nameById[(int)$sc['status_id']] = (string)$sc['name'];
                    }
                ?>

                <div class="dropdown-container">
                    <button class="btn btn-outline" type="button" title="Filter Records" onclick="toggleFilterDropdown(this, event)">
                        <i class="ri-filter-3-line"></i>
                        <?= $filter_status === null ? 'Filter' : 'Filtered' ?>
                        <i class="ri-arrow-down-s-line" style="margin-left: 4px;"></i>
                    </button>
                    <div class="action-dropdown filter-dropdown" onclick="event.stopPropagation()">
                        <div class="filter-section">
                            <div class="filter-title"><i class="ri-router-line"></i> All</div>
                            <a class="filter-item <?= $filter_status === null ? 'active' : '' ?>" href="network.php">
                                <span class="filter-left">
                                    <i class="ri-apps-line" style="color: var(--primary)"></i>
                                    <span>All assets</span>
                                </span>
                                <span class="filter-count"><?= (int)$stats['total'] ?></span>
                            </a>
                        </div>

                        <div class="filter-section">
                            <div class="filter-title"><i class="ri-box-3-line" style="color: var(--success)"></i> Stock</div>
                            <?php foreach ($stock_ids as $sid):
                                $active = ($filter_status === $sid);
                                $label = $nameById[$sid] ?? ('Status ' . $sid);
                                $icon = match ($sid) {
                                    9 => 'ri-wifi-line',
                                    10 => 'ri-wifi-off-line',
                                    5 => 'ri-tools-line',
                                    6 => 'ri-alert-line',
                                    default => 'ri-question-line',
                                };
                                $iconColor = match ($sid) {
                                    9 => 'var(--success)',
                                    10 => 'var(--danger)',
                                    5 => 'var(--warning)',
                                    6 => 'var(--danger)',
                                    default => 'var(--text-muted)',
                                };
                            ?>
                                <a class="filter-item <?= $active ? 'active' : '' ?>" href="network.php?status_id=<?= (int)$sid ?>">
                                    <span class="filter-left">
                                        <i class="<?= $icon ?>" style="color: <?= $iconColor ?>"></i>
                                        <span><?= htmlspecialchars($label) ?></span>
                                    </span>
                                    <span class="filter-count"><?= (int)($countsById[$sid] ?? 0) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <div class="filter-section">
                            <div class="filter-title"><i class="ri-truck-line" style="color: var(--primary)"></i> Out-stock</div>
                            <?php foreach ($out_stock_ids as $sid):
                                $active = ($filter_status === $sid);
                                $label = $nameById[$sid] ?? ('Status ' . $sid);
                                $icon = match ($sid) {
                                    3 => 'ri-user-received-2-line',
                                    7 => 'ri-delete-bin-line',
                                    8 => 'ri-map-pin-line',
                                    default => 'ri-question-line',
                                };
                            ?>
                                <a class="filter-item <?= $active ? 'active' : '' ?>" href="network.php?status_id=<?= (int)$sid ?>">
                                    <span class="filter-left">
                                        <i class="<?= $icon ?>" style="color: var(--primary)"></i>
                                        <span><?= htmlspecialchars($label) ?></span>
                                    </span>
                                    <span class="filter-count"><?= (int)($countsById[$sid] ?? 0) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="glass-card">
            <div class="table-responsive">
                <table id="assetTable" class="data-table">
                    <thead>
                    <tr>
                        <th>Device</th>
                        <th>Asset ID</th>
                        <th>IP address</th>
                        <th>MAC address</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody id="assetTbody">
                        <?php if (empty($assets)): ?>
                            <tr class="empty-row">
                                <td colspan="6">
                                    <div class="empty">
                                        <i class="ri-inbox-line"></i>
                                        <h3>No network assets yet</h3>
                                        <p>
                                            Add rows to the <code>network</code> table (<code>db/schema.sql</code>). Status must reference <code>status</code>
                                            (e.g. 9 Online, 10 Offline, 5 Maintenance, 6 Faulty, 3 Deploy, 7 Disposed, 8 Lost).
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: foreach ($assets as $row):
                            $sid = (int)$row['status_id'];
                            $filterKey = network_filter_status($sid);
                            $badgeCls = network_badge_class($sid);
                            $device = trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? ''));
                            if ($device === '') {
                                $device = 'Network device';
                            }
                        ?>
                            <tr class="network-row" data-filter-status="<?= htmlspecialchars($filterKey) ?>">
                                <td>
                                    <div class="asset-cell">
                                        <div class="asset-icon"><i class="ri-router-line"></i></div>
                                        <div class="asset-meta">
                                            <div class="asset-name"><?= htmlspecialchars($device) ?></div>
                                            <div class="asset-sub">SN: <?= htmlspecialchars($row['serial_num'] ?? '—') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><code style="background:var(--glass-panel);padding:2px 8px;border-radius:6px;font-weight:600;color:var(--primary);"><?= htmlspecialchars((string)$row['asset_id']) ?></code></td>
                                <td><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
                                <td style="font-family:ui-monospace,monospace;font-size:0.85rem;"><?= htmlspecialchars($row['mac_address'] ?? '—') ?></td>
                                <td>
                                    <span class="badge <?= $badgeCls ?>">
                                        <?= htmlspecialchars($row['status_name'] ?? '—') ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <div class="row-actions">
                                        <button type="button" class="icon-btn" title="View (soon)" disabled><i class="ri-eye-line"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination">
                <div class="page-info">
                    Showing <strong><?= (int)$rowStart ?>-<?= (int)$rowEnd ?></strong> of <strong><?= (int)$filteredTotal ?></strong> item(s)
                    &nbsp;•&nbsp; Page <strong><?= (int)$page ?></strong> / <strong><?= (int)$totalPages ?></strong>
                </div>
                <div class="page-nav">
                    <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars($prevHref) ?>">
                        <i class="ri-arrow-left-s-line"></i> Prev
                    </a>
                    <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars($nextHref) ?>">
                        Next <i class="ri-arrow-right-s-line"></i>
                    </a>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleDropdown(element, event) {
            event.preventDefault();
            const group = element.closest('.nav-group');
            const dropdown = group.querySelector('.nav-dropdown');
            element.classList.toggle('open');
            dropdown.classList.toggle('show');
        }

        function toggleRegisterDropdown(btn, event) {
            event.stopPropagation();
            const wrap = btn.closest('.dropdown-container');
            const drop = wrap.querySelector('.action-dropdown');
            document.querySelectorAll('.action-dropdown.show').forEach(d => {
                if (d !== drop) d.classList.remove('show');
            });
            drop.classList.toggle('show');
        }

        function toggleFilterDropdown(btn, event) {
            event.stopPropagation();
            const wrap = btn.closest('.dropdown-container');
            const drop = wrap.querySelector('.action-dropdown');
            document.querySelectorAll('.action-dropdown.show').forEach(d => {
                if (d !== drop) d.classList.remove('show');
            });
            drop.classList.toggle('show');
        }

        document.addEventListener('click', () => {
            document.querySelectorAll('.action-dropdown.show').forEach(d => d.classList.remove('show'));
        });

        const searchInput = document.getElementById('searchInput');
        const tbody = document.getElementById('assetTbody');

        function applyFilters() {
            const q = (searchInput.value || '').toLowerCase();
            const rows = Array.from(tbody.querySelectorAll('tr.network-row'));

            rows.forEach(row => {
                const textOk = row.innerText.toLowerCase().includes(q);
                row.style.display = textOk ? '' : 'none';
            });
        }

        searchInput.addEventListener('input', applyFilters);
    </script>
</body>
</html>