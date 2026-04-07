<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)$_SESSION['role_id'] !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_faulty'], $_POST['asset_id'])) {
    $aid = (int)$_POST['asset_id'];
    if ($aid <= 0) {
        $_SESSION['laptop_flash'] = ['type' => 'error', 'msg' => 'Invalid asset.'];
    } else {
        $st = db()->prepare('UPDATE laptop SET status_id = 6 WHERE asset_id = ? AND status_id IN (1, 2)');
        $st->execute([$aid]);
        $_SESSION['laptop_flash'] = $st->rowCount() === 1
            ? ['type' => 'ok', 'msg' => 'Asset marked as Faulty.']
            : ['type' => 'error', 'msg' => 'Could not update (asset missing or not Active/Non-active).'];
    }
    $redir = 'laptop.php';
    if (isset($_POST['redirect_status']) && $_POST['redirect_status'] !== '' && is_numeric($_POST['redirect_status'])) {
        $redir .= '?status_id=' . (int)$_POST['redirect_status'];
    }
    header('Location: ' . $redir);
    exit;
}

$laptop_flash = $_SESSION['laptop_flash'] ?? null;
unset($_SESSION['laptop_flash']);

//  Active status filter (from URL ?status_id=N) 
$filter_status = isset($_GET['status_id']) && is_numeric($_GET['status_id'])
    ? (int)$_GET['status_id'] : null;

//  Status counts (all statuses, even zero)
$status_counts = db()->query("
    SELECT s.status_id, s.name, COUNT(l.asset_id) AS total
    FROM status s
    LEFT JOIN laptop l ON l.status_id = s.status_id
    WHERE s.status_id NOT IN (9, 10)
    GROUP BY s.status_id, s.name
    ORDER BY s.status_id
")->fetchAll();

// Total laptops
$total_laptops = (int)db()->query("SELECT COUNT(*) FROM laptop")->fetchColumn();

// Stock / Out-stock totals (for summary cards + filter dropdown)
$stock_ids = [1, 2, 4, 5, 6];     // Active, Non-active, Reserved, Maintenance, Faulty
$out_stock_ids = [3, 7, 8];       // Deploy, Disposed, Lost

$countsById = [];
$statusNameById = [];
foreach ($status_counts as $sc) {
    $sid = (int)$sc['status_id'];
    $countsById[$sid] = (int)$sc['total'];
    $statusNameById[$sid] = (string)$sc['name'];
}

$stock_total = 0;
foreach ($stock_ids as $sid) $stock_total += $countsById[$sid] ?? 0;
$out_total = 0;
foreach ($out_stock_ids as $sid) $out_total += $countsById[$sid] ?? 0;

//  Laptop list (+ warranty info for maintenance view)
$sql = "
    SELECT l.asset_id, l.serial_num, l.brand, l.model,
           l.category, l.processor, l.memory, l.os, l.storage,
           l.PO_DATE, l.status_id, s.name AS status_name,
           st.full_name AS assignee_name,
           st.department AS department,
           hs.employee_no AS assignee_employee_no,
           w.warranty_start_date,
           w.warranty_end_date
    FROM laptop l
    JOIN status s ON s.status_id = l.status_id
    LEFT JOIN handover h ON h.asset_id = l.asset_id
    LEFT JOIN handover_staff hs ON hs.handover_id = h.handover_id
    LEFT JOIN staff st ON st.employee_no = hs.employee_no
    LEFT JOIN warranty w ON w.asset_id = l.asset_id
        AND w.warranty_id = (
            SELECT w2.warranty_id FROM warranty w2
            WHERE w2.asset_id = l.asset_id AND w2.asset_type = 'laptop'
            ORDER BY w2.warranty_end_date DESC, w2.warranty_id DESC
            LIMIT 1
        )
";
$params = [];
if ($filter_status !== null) {
    $sql .= " WHERE l.status_id = :status_id";
    $params[':status_id'] = $filter_status;
}
$sql .= " ORDER BY l.asset_id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$laptops = $stmt->fetchAll();

// Status visual meta (icon, badge class, colour) 
$status_meta = [
    1 => ['icon'=>'ri-checkbox-circle-fill', 'cls'=>'badge-active',   'colour'=>'#10b981','bg'=>'rgba(16,185,129,0.1)',  'border'=>'rgba(16,185,129,0.3)'],
    2 => ['icon'=>'ri-close-circle-fill',    'cls'=>'badge-disposed', 'colour'=>'#64748b','bg'=>'rgba(100,116,139,0.1)','border'=>'rgba(100,116,139,0.3)'],
    3 => ['icon'=>'ri-user-received-2-fill', 'cls'=>'badge-progress', 'colour'=>'#2563eb','bg'=>'rgba(37,99,235,0.1)',  'border'=>'rgba(37,99,235,0.3)'],
    4 => ['icon'=>'ri-archive-fill',         'cls'=>'badge-reserve',  'colour'=>'#8b5cf6','bg'=>'rgba(139,92,246,0.1)', 'border'=>'rgba(139,92,246,0.3)'],
    5 => ['icon'=>'ri-tools-fill',           'cls'=>'badge-repair',   'colour'=>'#f59e0b','bg'=>'rgba(245,158,11,0.1)', 'border'=>'rgba(245,158,11,0.3)'],
    6 => ['icon'=>'ri-alert-fill',           'cls'=>'badge-disposed', 'colour'=>'#ef4444','bg'=>'rgba(239,68,68,0.1)',  'border'=>'rgba(239,68,68,0.3)'],
    7 => ['icon'=>'ri-delete-bin-fill',      'cls'=>'badge-disposed', 'colour'=>'#94a3b8','bg'=>'rgba(148,163,184,0.1)','border'=>'rgba(148,163,184,0.3)'],
    8 => ['icon'=>'ri-map-pin-line',         'cls'=>'badge-repair',   'colour'=>'#f97316','bg'=>'rgba(249,115,22,0.1)', 'border'=>'rgba(249,115,22,0.3)'],
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laptop Inventory - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --secondary: #0ea5e9;
            --accent: #f59e0b;
            --danger: #ef4444;
            --success: #10b981;
            --bg: #f1f5f9;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass-panel: #f8fafc;
            --glass-bg: #ffffff;
            --glass-border: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            overflow-x: hidden;
            display: flex;
            min-height: 100vh;
        }

        .page-bg { display: none; }
        .bg-overlay { display: none; }
        .blob { display: none; }

        /* Decorative glowing orbs */
        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(100px);
            z-index: -1;
            opacity: 0.3;
            pointer-events: none;
        }

        .blob-1 {
            width: 400px; height: 400px; background: var(--primary);
            top: -100px; left: -100px;
        }

        .blob-2 {
            width: 350px; height: 350px; background: var(--secondary);
            bottom: -50px; right: 20%;
        }

        /* Sidebar Navigation */
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
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.5));
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
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
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
        .nav-item.active i { color: var(--primary); }

        .user-profile {
            margin-top: auto;
            padding: 1rem;
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
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
            color: white;
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

        /* Sidebar Dropdown */
        .nav-dropdown {
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            padding-left: 3.25rem;
            margin-top: -0.25rem;
            margin-bottom: 0.25rem;
            animation: fadeInDown 0.3s ease-out;
        }

        /* Show this specific dropdown by default on this page */
        .nav-dropdown.show {
            display: flex;
        }

        .nav-dropdown-item {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-dropdown-item::before {
            content: '';
            position: absolute;
            left: -1rem;
            top: 50%;
            width: 6px;
            height: 6px;
            background: var(--glass-border);
            border-radius: 50%;
            transform: translateY(-50%);
            transition: all 0.3s ease;
        }

        .nav-dropdown-item:hover {
            color: var(--primary);
            background: rgba(37, 99, 235, 0.06);
        }

        .nav-dropdown-item:hover::before {
            background: var(--primary);
        }
        
        .nav-dropdown-item.active {
            color: var(--primary);
        }

        .nav-dropdown-item.active::before {
            background: var(--primary);
        }

        .nav-item.open .chevron {
            transform: rotate(180deg);
        }

        /* Main Content Layout */
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2.5rem 3.5rem;
            max-width: calc(100vw - 280px); 
        }

        /* Header Area */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 2rem;
            animation: fadeInDown 0.6s ease-out;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 1.5rem;
        }

        .page-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            letter-spacing: -0.5px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title h1 i {
            color: var(--primary);
        }

        .page-title p {
            color: var(--text-muted);
            font-size: 1rem;
        }

        /* Stock summary cards */
        .stock-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.65s ease-out;
        }
        .stock-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 1.15rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
        }
        .stock-left {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            min-width: 0;
        }
        .stock-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 1px solid transparent;
            font-size: 1.3rem;
        }
        .stock-icon.in { background: rgba(16,185,129,0.12); color: var(--success); border-color: rgba(16,185,129,0.22); }
        .stock-icon.out { background: rgba(37,99,235,0.12); color: var(--primary); border-color: rgba(37,99,235,0.22); }
        .stock-meta { min-width: 0; }
        .stock-label {
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .stock-sub {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-top: 0.15rem;
        }
        .stock-count {
            font-family: 'Outfit', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            color: var(--text-main);
        }

        /* Status Filter Cards */
        .status-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 1rem;
            margin-bottom: 1.75rem;
            animation: fadeInUp 0.65s ease-out;
        }

        .status-card {
            background: var(--card-bg);
            border: 2px solid var(--card-border);
            border-radius: 16px;
            padding: 1.1rem 1.25rem;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .status-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(15,23,42,0.08);
        }

        .status-card.active-filter {
            box-shadow: 0 4px 16px rgba(15,23,42,0.1);
        }

        .status-card-icon { font-size: 1.5rem; }
        .status-card-count {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1;
        }
        .status-card-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-card-all { border-color: var(--primary); }
        .status-card-all .status-card-icon { color: var(--primary); }
        .status-card-all.active-filter { background: rgba(37,99,235,0.06); }

        /* Filter dropdown (sub-filters moved to the Filter button) */
        .filter-dropdown {
            min-width: 320px;
            padding: 0.6rem;
        }
        .filter-section {
            padding: 0.35rem 0.35rem 0.25rem;
        }
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
        .filter-left {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            min-width: 0;
        }
        .filter-left span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .filter-count {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            color: var(--text-main);
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
        }

        /* Controls / Actions */

        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.7s ease-out;
            gap: 1rem;
        }

        .search-box {
            position: relative;
            flex: 1;
            max-width: 400px;
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
            transition: all 0.3s ease;
            outline: none;
        }

        .search-input:focus {
            background: #fff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.85rem 1.5rem;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 8px 15px -5px rgba(37, 99, 235, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 20px -5px rgba(37, 99, 235, 0.5);
            filter: brightness(1.1);
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
            transform: translateY(-2px);
        }

        /* Glass Table Card */
        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            animation: fadeInUp 0.9s ease-out;
            overflow: hidden;
        }

        /* Table Styles */
        .table-responsive { overflow-x: auto; width: 100%; }

        .data-table { width: 100%; border-collapse: collapse; white-space: nowrap; }

        .data-table th {
            text-align: left;
            padding: 1.25rem 1rem;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.8rem;
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

        .data-table tbody tr { transition: background 0.3s ease; }
        .data-table tbody tr:hover { background: rgba(37,99,235,0.03); }
        .data-table tr:last-child td { border-bottom: none; }

        .laptop-identity {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .laptop-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(14, 165, 233, 0.15);
            color: var(--secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            border: 1px solid rgba(14, 165, 233, 0.3);
        }

        .laptop-info h4 {
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.2rem;
            font-size: 0.95rem;
        }

        .laptop-info p {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-family: 'Outfit', monospace;
        }

        .badge {
            padding: 0.4rem 0.85rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .badge-active { background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-repair { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-disposed { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-reserve { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, 0.3); }

        .btn-action {
            padding: 0.5rem;
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 0.3rem;
            font-size: 1.1rem;
        }

        .btn-action.view:hover {
            background: var(--secondary);
            border-color: var(--secondary);
            color: white;
        }
        
        .btn-action.handover:hover {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .btn-action.return:hover {
            background: var(--accent);
            border-color: var(--accent);
            color: white;
        }

        .btn-action.warranty:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .btn-action.faulty:hover {
            background: var(--danger);
            border-color: var(--danger);
            color: white;
        }

        .btn-action.repair:hover {
            background: var(--accent);
            border-color: var(--accent);
            color: white;
        }

        .laptop-flash {
            margin: 0 0 1rem;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .laptop-flash.ok { background: rgba(16,185,129,0.12); color: #047857; border: 1px solid rgba(16,185,129,0.35); }
        .laptop-flash.err { background: rgba(239,68,68,0.1); color: #b91c1c; border: 1px solid rgba(239,68,68,0.3); }

        form.inline-action { display: inline; }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--card-border);
        }

        .page-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .page-btn:hover:not(:disabled) {
            background: rgba(37,99,235,0.08);
            color: var(--primary);
            border-color: rgba(37,99,235,0.2);
        }

        .page-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .page-info {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-right: auto;
        }

        /* Action Dropdowns */
        .dropdown-container {
            position: relative;
            display: inline-block;
        }

        .action-dropdown {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.5rem;
            min-width: 140px;
            box-shadow: 0 8px 25px rgba(15,23,42,0.12);
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            z-index: 50;
            animation: fadeInDown 0.2s ease-out forwards;
        }

        .action-dropdown.show {
            display: flex;
        }

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

        /* Animations */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .table-controls { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; }
        }
    </style>
</head>
<body>

    <!-- Backgrounds -->
    <div class="page-bg"></div>
    <div class="bg-overlay"></div>
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <!-- Sidebar -->
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        
        <!-- Header -->
        <header class="page-header">
            <div class="page-title">
                <h1><i class="ri-macbook-line"></i> Laptop Inventory</h1>
                <p>Manage, track, and monitor all registered campus laptops.</p>
            </div>
        </header>

        <?php if ($laptop_flash): ?>
            <div class="laptop-flash <?= $laptop_flash['type'] === 'ok' ? 'ok' : 'err' ?>">
                <?= htmlspecialchars($laptop_flash['msg'] ?? '', ENT_QUOTES) ?>
            </div>
        <?php endif; ?>

        <section class="stock-summary" aria-label="Stock summary">
            <div class="stock-card" title="In-stock: Active, Non-active, Reserved, Maintenance, Faulty">
                <div class="stock-left">
                    <div class="stock-icon in"><i class="ri-box-3-line"></i></div>
                    <div class="stock-meta">
                        <div class="stock-label">In-stock</div>
                        <div class="stock-sub">Active, Non-active, Reserved, Maintenance, Faulty</div>
                    </div>
                </div>
                <div class="stock-count"><?= (int)$stock_total ?></div>
            </div>
            <div class="stock-card" title="Out-stock: Deploy, Disposed, Lost">
                <div class="stock-left">
                    <div class="stock-icon out"><i class="ri-truck-line"></i></div>
                    <div class="stock-meta">
                        <div class="stock-label">Out-stock</div>
                        <div class="stock-sub">Deploy, Disposed, Lost</div>
                    </div>
                </div>
                <div class="stock-count"><?= (int)$out_total ?></div>
            </div>
        </section>

        <!-- Sub-filters moved into Filter dropdown (top-right) -->

        <!-- Tool & Search Bar -->
        <div class="table-controls">
            <div class="search-box">
                <i class="ri-search-2-line"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Search by Model, Serial No, or Assignee...">
            </div>
            <div class="action-buttons">
                <?php /* stock/out totals prepared above */ ?>

                <div class="dropdown-container">
                    <button class="btn btn-outline" title="Filter Records" onclick="toggleActionDropdown(this, event)">
                        <i class="ri-filter-3-line"></i>
                        <?= $filter_status === null ? 'Filter' : 'Filtered' ?>
                        <i class="ri-arrow-down-s-line" style="margin-left: 4px;"></i>
                    </button>
                    <div class="action-dropdown filter-dropdown">
                        <div class="filter-section">
                            <div class="filter-title"><i class="ri-macbook-line"></i> All Assets</div>
                            <a class="filter-item <?= $filter_status === null ? 'active' : '' ?>" href="laptop.php">
                                <span class="filter-left">
                                    <i class="ri-layout-grid-line" style="color: var(--primary)"></i>
                                    <span>All Assets</span>
                                </span>
                                <span class="filter-count"><?= (int)$total_laptops ?></span>
                            </a>
                        </div>

                        <div class="filter-section">
                            <div class="filter-title"><i class="ri-box-3-line" style="color: var(--success)"></i> Stock</div>
                            <?php foreach ($stock_ids as $sid):
                                $meta = $status_meta[$sid] ?? ['icon'=>'ri-question-line','colour'=>'#64748b'];
                                $active = ($filter_status === $sid);
                            ?>
                                <a class="filter-item <?= $active ? 'active' : '' ?>" href="laptop.php?status_id=<?= (int)$sid ?>">
                                    <span class="filter-left">
                                        <i class="<?= $meta['icon'] ?>" style="color: <?= htmlspecialchars((string)$meta['colour']) ?>"></i>
                                        <span><?= htmlspecialchars($statusNameById[$sid] ?? ('Status ' . $sid)) ?></span>
                                    </span>
                                    <span class="filter-count"><?= (int)($countsById[$sid] ?? 0) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <div class="filter-section">
                            <div class="filter-title"><i class="ri-truck-line" style="color: var(--primary)"></i> Out-stock</div>
                            <?php foreach ($out_stock_ids as $sid):
                                $meta = $status_meta[$sid] ?? ['icon'=>'ri-question-line','colour'=>'#64748b'];
                                $active = ($filter_status === $sid);
                            ?>
                                <a class="filter-item <?= $active ? 'active' : '' ?>" href="laptop.php?status_id=<?= (int)$sid ?>">
                                    <span class="filter-left">
                                        <i class="<?= $meta['icon'] ?>" style="color: <?= htmlspecialchars((string)$meta['colour']) ?>"></i>
                                        <span><?= htmlspecialchars($statusNameById[$sid] ?? ('Status ' . $sid)) ?></span>
                                    </span>
                                    <span class="filter-count"><?= (int)($countsById[$sid] ?? 0) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="dropdown-container">
                    <button class="btn btn-primary" onclick="toggleActionDropdown(this, event)">
                        <i class="ri-add-line"></i> Register Laptop <i class="ri-arrow-down-s-line" style="margin-left: 4px;"></i>
                    </button>
                    <div class="action-dropdown">
                        <a href="../technician/laptopAdd.php" class="action-dropdown-item"><i class="ri-macbook-line" style="color: var(--primary);"></i> Single Asset</a>
                        <a href="../technician/laptopCSV.php" class="action-dropdown-item"><i class="ri-stack-line" style="color: var(--secondary);"></i> Bulk Assets</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory Data Table -->
        <div class="glass-card">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Device Identity</th>
                            <?php if ($filter_status === 5): ?>
                                <th>Warranty Details</th>
                            <?php elseif ($filter_status === 3): ?>
                                <th>Department</th>
                                <th>Assigned To</th>
                                <th>Purchase Date</th>
                            <?php else: ?>
                                <th>Asset Information</th>
                                <th>Purchase Date</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="laptopTableBody">
                        <?php if (empty($laptops)): ?>
                        <tr>
                            <?php
                                $colspan = ($filter_status === 5) ? 4 : (($filter_status === 3) ? 6 : 5);
                            ?>
                            <td colspan="<?= (int)$colspan ?>" style="text-align:center; padding: 3rem; color: var(--text-muted);">
                                <i class="ri-inbox-line" style="font-size:2rem; display:block; margin-bottom:0.5rem;"></i>
                                No laptops found<?= $filter_status !== null ? ' for this status' : '' ?>.
                            </td>
                        </tr>
                        <?php else: foreach ($laptops as $row):
                            $sid   = (int)$row['status_id'];
                            $meta  = $status_meta[$sid] ?? ['icon'=>'ri-question-line','cls'=>'badge-active','colour'=>'#64748b','bg'=>'rgba(100,116,139,0.15)','border'=>'rgba(100,116,139,0.3)'];
                            $icon_style = "color:{$meta['colour']};background:{$meta['bg']};border:1px solid {$meta['border']};";
                            $device = trim(htmlspecialchars($row['brand'] ?? '') . ' ' . htmlspecialchars($row['model'] ?? ''));
                            if (!$device) $device = 'Unknown Device';
                            $assignee   = htmlspecialchars($row['assignee_name'] ?? '—');
                            $department = htmlspecialchars($row['department'] ?? '—');
                            $po_date    = $row['PO_DATE'] ? date('d M Y', strtotime($row['PO_DATE'])) : 'â€”';

                            $assetInfoParts = [];
                            if (!empty($row['category']))  $assetInfoParts[] = htmlspecialchars((string)$row['category']);
                            if (!empty($row['processor'])) $assetInfoParts[] = 'CPU: ' . htmlspecialchars((string)$row['processor']);
                            if (!empty($row['memory']))    $assetInfoParts[] = 'RAM: ' . htmlspecialchars((string)$row['memory']);
                            if (!empty($row['os']))        $assetInfoParts[] = 'OS: ' . htmlspecialchars((string)$row['os']);
                            if (!empty($row['storage']))   $assetInfoParts[] = 'Storage: ' . htmlspecialchars((string)$row['storage']);
                            $assetInfoText = $assetInfoParts ? implode(' • ', $assetInfoParts) : '—';

                            // Warranty display (for maintenance view)
                            $wStartRaw = $row['warranty_start_date'] ?? null;
                            $wEndRaw   = $row['warranty_end_date'] ?? null;
                            $warrantyText = 'No warranty record';
                            $inWarranty = false;
                            if ($wStartRaw && $wEndRaw) {
                                $today = new DateTimeImmutable('today');
                                $start = new DateTimeImmutable($wStartRaw);
                                $end   = new DateTimeImmutable($wEndRaw);
                                $inWarranty = ($today >= $start && $today <= $end);
                                $warrantyText = sprintf(
                                    '%s → %s%s',
                                    $start->format('d M Y'),
                                    $end->format('d M Y'),
                                    $inWarranty ? ' (Active)' : ' (Expired)'
                                );
                            }
                        ?>
                        <tr>
                            <td>
                                <div class="laptop-identity">
                                    <div class="laptop-icon" style="<?= $icon_style ?>"><i class="<?= $meta['icon'] ?>"></i></div>
                                    <div class="laptop-info">
                                        <h4><?= $device ?></h4>
                                        <p>SN: <?= htmlspecialchars($row['serial_num'] ?? 'â€”') ?> &bull; <?= htmlspecialchars($row['asset_id']) ?></p>
                                    </div>
                                </div>
                            </td>

                            <?php if ($filter_status === 5): ?>
                                <td>
                                    <span style="font-size:0.85rem; color: <?= $inWarranty ? '#16a34a' : 'var(--text-muted)' ?>;">
                                        <?= htmlspecialchars($warrantyText) ?>
                                    </span>
                                </td>
                            <?php elseif ($filter_status === 3): ?>
                                <td><?= $department ?></td>
                                <td><?= $assignee ?></td>
                                <td><?= $po_date ?></td>
                            <?php else: ?>
                                <td>
                                    <span style="font-size:0.85rem; color: var(--text-muted);">
                                        <?= $assetInfoText ?>
                                    </span>
                                </td>
                                <td><?= $po_date ?></td>
                            <?php endif; ?>
                            <td><span class="badge <?= $meta['cls'] ?>"><i class="<?= $meta['icon'] ?>"></i> <?= htmlspecialchars($row['status_name']) ?></span></td>
                            <td>
                                <button class="btn-action view" title="View Details"><i class="ri-eye-line"></i></button>
                                <?php if ($sid === 1 || $sid === 2): ?>
                                    <form method="post" class="inline-action" action="laptop.php<?= $filter_status !== null ? '?status_id=' . (int)$filter_status : '' ?>" onsubmit="return confirm('Mark this asset as Faulty?');">
                                        <input type="hidden" name="mark_faulty" value="1">
                                        <input type="hidden" name="asset_id" value="<?= (int)$row['asset_id'] ?>">
                                        <input type="hidden" name="redirect_status" value="<?= $filter_status === null ? '' : (string)(int)$filter_status ?>">
                                        <button type="submit" class="btn-action faulty" title="Mark as Faulty"><i class="ri-error-warning-line"></i></button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($sid === 1): ?>
                                    <a href="handoverForm.php?asset_id=<?= urlencode($row['asset_id']) ?>" class="btn-action handover" title="Handover Asset">
                                        <i class="ri-exchange-line"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ($sid === 3): ?>
                                    <a href="returnForm.php?asset_id=<?= urlencode($row['asset_id']) ?>" class="btn-action return" title="Return Asset">
                                        <i class="ri-arrow-go-back-line"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ($sid === 5): ?>
                                    <a href="warranty.php?asset_id=<?= urlencode($row['asset_id']) ?>" class="btn-action warranty" title="Warranty Claim">
                                        <i class="ri-shield-check-line"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ($sid === 6): ?>
                                    <?php if ($inWarranty): ?>
                                        <a href="warranty.php?asset_id=<?= urlencode((string)$row['asset_id']) ?>" class="btn-action warranty" title="Warranty claim (in warranty)">
                                            <i class="ri-shield-check-line"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="repair.php?asset_id=<?= urlencode((string)$row['asset_id']) ?>" class="btn-action repair" title="Log repair (no active warranty)">
                                            <i class="ri-tools-line"></i>
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div class="pagination">
                <div class="page-info">
                    Showing <strong><?= count($laptops) ?></strong> record<?= count($laptops) !== 1 ? 's' : '' ?><?= $filter_status !== null ? ' (filtered)' : '' ?>
                </div>
            </div>
        </div>

    </main>

    <script>
        // Client-side search
        document.getElementById('searchInput').addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('#laptopTableBody tr').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
            });
        });

        function toggleDropdown(element, event) {
            event.preventDefault();
            const group = element.closest('.nav-group');
            const dropdown = group.querySelector('.nav-dropdown');
            element.classList.toggle('open');
            dropdown.classList.toggle('show');
        }

        function toggleActionDropdown(element, event) {
            event.stopPropagation();
            const container = element.closest('.dropdown-container');
            const dropdown = container.querySelector('.action-dropdown');
            document.querySelectorAll('.action-dropdown.show').forEach(drop => {
                if (drop !== dropdown) drop.classList.remove('show');
            });
            dropdown.classList.toggle('show');
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.dropdown-container')) {
                document.querySelectorAll('.action-dropdown.show').forEach(drop => drop.classList.remove('show'));
            }
        });
    </script>
</body>
</html>
