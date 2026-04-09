<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function dashboard_asset_href(string $assetType, int $assetId): string
{
    return match ($assetType) {
        'laptop' => 'laptopView.php?asset_id=' . $assetId,
        'av' => 'avView.php?asset_id=' . $assetId,
        'network' => 'network.php',
        default => 'laptopView.php?asset_id=' . $assetId,
    };
}

$dbError = false;
$stats = [
    'assets_total' => 0,
    'laptops' => 0,
    'network' => 0,
    'av' => 0,
    'open_repairs' => 0,
    'repairs_done_week' => 0,
    'nexcheck_out' => 0,
    'faulty_total' => 0,
    'network_offline' => 0,
    'warranty_expiring' => 0,
];
$chartAssetMix = ['labels' => ['Laptops', 'Network', 'AV'], 'data' => [0, 0, 0]];
$chartLaptopStatus = ['labels' => [], 'data' => []];
$chartNetworkStatus = ['labels' => [], 'data' => []];
$chartRepairsTrend = ['labels' => [], 'data' => []];
$recentRows = [];
$alertOffline = [];
$alertWarranties = [];
$nexcheckEvents = [];

try {
    $pdo = db();

    $stats['laptops'] = (int) $pdo->query('SELECT COUNT(*) FROM laptop')->fetchColumn();
    $stats['network'] = (int) $pdo->query('SELECT COUNT(*) FROM network')->fetchColumn();
    $stats['av'] = (int) $pdo->query('SELECT COUNT(*) FROM av')->fetchColumn();
    $stats['assets_total'] = $stats['laptops'] + $stats['network'] + $stats['av'];

    $stats['open_repairs'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM repair WHERE completed_date IS NULL'
    )->fetchColumn();

    $stats['repairs_done_week'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM repair
         WHERE completed_date IS NOT NULL
           AND completed_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)"
    )->fetchColumn();

    $stats['nexcheck_out'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM nexcheck_assignment
         WHERE checkout_at IS NOT NULL AND returned_at IS NULL'
    )->fetchColumn();

    $stats['faulty_total'] = (int) $pdo->query(
        'SELECT
            (SELECT COUNT(*) FROM laptop WHERE status_id = 6) +
            (SELECT COUNT(*) FROM network WHERE status_id = 6) +
            (SELECT COUNT(*) FROM av WHERE status_id = 6)'
    )->fetchColumn();

    $stats['network_offline'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM network WHERE status_id = 10'
    )->fetchColumn();

    $stats['warranty_expiring'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM warranty
         WHERE warranty_end_date >= CURDATE()
           AND warranty_end_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)'
    )->fetchColumn();

    $chartAssetMix['data'] = [$stats['laptops'], $stats['network'], $stats['av']];

    $laptopStatus = $pdo->query(
        'SELECT s.name, COUNT(l.asset_id) AS c
         FROM laptop l
         JOIN status s ON s.status_id = l.status_id
         GROUP BY s.status_id, s.name
         ORDER BY c DESC, s.name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($laptopStatus as $row) {
        $chartLaptopStatus['labels'][] = $row['name'];
        $chartLaptopStatus['data'][] = (int) $row['c'];
    }
    if ($chartLaptopStatus['labels'] === []) {
        $chartLaptopStatus = ['labels' => ['No laptops'], 'data' => [0]];
    }

    $netSt = $pdo->query(
        'SELECT s.name, COUNT(n.asset_id) AS c
         FROM network n
         JOIN status s ON s.status_id = n.status_id
         GROUP BY s.status_id, s.name
         ORDER BY c DESC, s.status_id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($netSt as $row) {
        $chartNetworkStatus['labels'][] = $row['name'];
        $chartNetworkStatus['data'][] = (int) $row['c'];
    }
    if ($chartNetworkStatus['labels'] === []) {
        $chartNetworkStatus = ['labels' => ['No network assets'], 'data' => [0]];
    }

    $repairMonths = $pdo->query(
        "SELECT DATE_FORMAT(completed_date, '%Y-%m') AS ym, COUNT(*) AS c
         FROM repair
         WHERE completed_date IS NOT NULL
           AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
         GROUP BY ym
         ORDER BY ym ASC"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $start = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
    $chartRepairsTrend['labels'] = [];
    $chartRepairsTrend['data'] = [];
    for ($i = 0; $i < 6; $i++) {
        $m = $start->modify('+' . $i . ' months');
        $key = $m->format('Y-m');
        $chartRepairsTrend['labels'][] = $m->format('M Y');
        $chartRepairsTrend['data'][] = (int) ($repairMonths[$key] ?? 0);
    }

    $recentSql = '
        SELECT kind, id, title, ts, asset_id, asset_type, extra FROM (
            SELECT
                \'repair\' AS kind,
                r.repair_id AS id,
                r.issue_summary AS title,
                r.created_at AS ts,
                r.asset_id,
                r.asset_type,
                CASE WHEN r.completed_date IS NULL THEN \'Open\' ELSE \'Closed\' END AS extra
            FROM repair r
            UNION ALL
            SELECT
                \'claim\',
                c.claim_id,
                c.issue_summary,
                c.created_at,
                c.asset_id,
                c.asset_type,
                \'Claim\'
            FROM warranty_claim c
        ) u
        ORDER BY ts DESC
        LIMIT 10
    ';
    $recentSt = $pdo->query($recentSql);
    $recentRows = $recentSt ? $recentSt->fetchAll(PDO::FETCH_ASSOC) : [];

    $alertOffline = $pdo->query(
        'SELECT asset_id, brand, model, ip_address, mac_address
         FROM network
         WHERE status_id = 10
         ORDER BY updated_at DESC
         LIMIT 6'
    )->fetchAll(PDO::FETCH_ASSOC);

    $alertWarranties = $pdo->query(
        'SELECT asset_id, asset_type, warranty_end_date,
                DATEDIFF(warranty_end_date, CURDATE()) AS days_left
         FROM warranty
         WHERE warranty_end_date >= CURDATE()
           AND warranty_end_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
         ORDER BY warranty_end_date ASC
         LIMIT 8'
    )->fetchAll(PDO::FETCH_ASSOC);

    // NextCheck calendar events (borrow/return window)
    $stmtNc = $pdo->prepare(
        'SELECT r.nexcheck_id, r.borrow_date, r.return_date, r.usage_location, u.full_name AS requester_name
         FROM nexcheck_request r
         JOIN users u ON u.staff_id = r.requested_by
         WHERE r.return_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           AND r.borrow_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY)
         ORDER BY r.borrow_date ASC, r.nexcheck_id DESC
         LIMIT 300'
    );
    $stmtNc->execute();
    foreach ($stmtNc->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nid = (int)($r['nexcheck_id'] ?? 0);
        $bd  = (string)($r['borrow_date'] ?? '');
        $rd  = (string)($r['return_date'] ?? '');
        if ($nid < 1 || $bd === '' || $rd === '') continue;
        $title = '#' . $nid . ' ' . trim((string)($r['requester_name'] ?? ''));
        $loc = trim((string)($r['usage_location'] ?? ''));
        if ($loc !== '') $title .= ' — ' . $loc;
        $nexcheckEvents[] = [
            'title' => $title,
            'start' => $bd,
            // FullCalendar v3: end is exclusive for all-day ranges.
            'end'   => (new DateTimeImmutable($rd))->modify('+1 day')->format('Y-m-d'),
            'allDay' => true,
            'url'   => 'nextItems.php?nexcheck_id=' . $nid,
        ];
    }
} catch (Throwable $e) {
    $dbError = true;
    $recentRows = [];
    $alertOffline = [];
    $alertWarranties = [];
    $nexcheckEvents = [];
}

$firstName = isset($_SESSION['user_name']) ? explode(' ', (string) $_SESSION['user_name'])[0] : 'Technician';
$attentionCount = $stats['faulty_total'] + $stats['network_offline'];
$chartJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Dashboard - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../vendor/fullcalendar/fullcalendar/dist/fullcalendar.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.30.1/min/moment.min.js"></script>
    <script src="../vendor/fullcalendar/fullcalendar/dist/fullcalendar.min.js"></script>
    <style>
        :root {
            --primary: #1a1a2e;
            --accent: #3b5bdb;
            --accent-soft: rgba(59, 91, 219, 0.08);
            --accent-border: rgba(59, 91, 219, 0.18);
            --success: #2f9e44;
            --danger: #c92a2a;
            --warning: #e67700;
            --bg: #f7f7f8;
            --card-bg: #ffffff;
            --card-border: #e8e8ec;
            --text-main: #111118;
            --text-muted: #6b6b7b;
            --text-faint: #a0a0b0;
            --divider: #ebebef;
            --radius: 10px;
            --radius-sm: 6px;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2.75rem 3rem 5rem;
            max-width: calc(100vw - 280px);
        }

        /* ── Topbar ── */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1.5rem;
            margin-bottom: 2.5rem;
            flex-wrap: wrap;
        }

        .greeting h1 {
            font-size: 1.6rem;
            font-weight: 600;
            letter-spacing: -0.025em;
            color: var(--text-main);
            line-height: 1.2;
        }

        .greeting p {
            color: var(--text-muted);
            margin-top: 0.4rem;
            font-size: 0.9rem;
            max-width: 38rem;
            line-height: 1.55;
            font-weight: 400;
        }

        .actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .header-icon-btn {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.15s ease;
            position: relative;
        }

        .header-icon-btn:hover {
            color: var(--accent);
            border-color: var(--accent-border);
            background: var(--accent-soft);
        }

        .notification-dot {
            position: absolute;
            top: 9px;
            right: 9px;
            width: 6px;
            height: 6px;
            background: var(--danger);
            border-radius: 50%;
            border: 1.5px solid var(--card-bg);
        }

        .btn {
            padding: 0.6rem 1.15rem;
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
            font-weight: 500;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: none;
            letter-spacing: -0.01em;
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
        }

        .btn-primary:hover {
            background: #2f4cc5;
        }

        /* ── Stats ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1px;
            margin-bottom: 2rem;
            background: var(--card-border);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 1.5rem 1.6rem;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            transition: background 0.15s;
        }

        .stat-card:hover { background: #fafafc; }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .icon-blue  { background: var(--accent-soft);              color: var(--accent);   }
        .icon-amber { background: rgba(230, 119, 0, 0.08);         color: var(--warning);  }
        .icon-green { background: rgba(47, 158, 68, 0.09);         color: var(--success);  }
        .icon-red   { background: rgba(201, 42, 42, 0.08);         color: var(--danger);   }

        .stat-body { min-width: 0; }

        .stat-value {
            font-family: 'DM Mono', monospace;
            font-size: 1.9rem;
            font-weight: 500;
            letter-spacing: -0.03em;
            line-height: 1;
            color: var(--text-main);
        }

        .stat-label {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 400;
            margin-top: 0.3rem;
            letter-spacing: 0.01em;
        }

        /* ── Charts ── */
        .charts-section {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        .chart-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            padding: 1.5rem;
        }

        .chart-card h3 {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .chart-card h3 i { font-size: 0.95rem; color: var(--accent); }

        .chart-card .sub {
            font-size: 0.78rem;
            color: var(--text-faint);
            margin-bottom: 1.25rem;
            font-weight: 400;
        }

        .chart-wrap {
            position: relative;
            height: 240px;
        }

        /* ── Bottom panels ── */
        .dashboard-bottom {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 1rem;
            align-items: start;
        }

        .panel {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            padding: 1.5rem;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.15rem;
            padding-bottom: 0.9rem;
            border-bottom: 1px solid var(--divider);
        }

        .panel-header h2 {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .panel-header h2 i { color: var(--accent); font-size: 0.95rem; }

        .view-all {
            font-size: 0.8rem;
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
            letter-spacing: -0.01em;
            transition: opacity 0.15s;
        }

        .view-all:hover { opacity: 0.7; }

        .db-alert {
            background: rgba(201, 42, 42, 0.05);
            border: 1px solid rgba(201, 42, 42, 0.2);
            color: var(--danger);
            padding: 0.8rem 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
            font-weight: 400;
        }

        /* ── Table ── */
        .recent-table {
            width: 100%;
            border-collapse: collapse;
        }

        .recent-table th {
            text-align: left;
            padding: 0 0.6rem 0.65rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-faint);
            font-weight: 600;
            border-bottom: 1px solid var(--divider);
        }

        .recent-table td {
            padding: 0.8rem 0.6rem;
            font-size: 0.86rem;
            border-bottom: 1px solid var(--divider);
            vertical-align: middle;
        }

        .recent-table tr:last-child td { border-bottom: none; }

        .recent-table tbody tr:hover td { background: #fafafc; }

        .kind-badge {
            font-size: 0.68rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.22rem 0.5rem;
            border-radius: 3px;
            display: inline-block;
        }

        .kind-repair { background: var(--accent-soft); color: var(--accent); }
        .kind-claim  { background: rgba(230, 119, 0, 0.08); color: var(--warning); }

        .title-cell  { font-weight: 500; color: var(--text-main); max-width: 260px; }
        .muted-small { font-size: 0.73rem; color: var(--text-faint); margin-top: 0.12rem; }

        .btn-icon {
            width: 30px;
            height: 30px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--card-border);
            background: transparent;
            color: var(--text-faint);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-icon:hover {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        /* ── Alerts ── */
        .alerts-list { display: flex; flex-direction: column; gap: 0.5rem; }

        .alert-item {
            display: flex;
            gap: 0.8rem;
            padding: 0.8rem 0.9rem;
            background: transparent;
            border: 1px solid var(--divider);
            border-radius: var(--radius-sm);
            align-items: flex-start;
            transition: border-color 0.15s;
        }

        .alert-item:hover { border-color: var(--accent-border); }
        .alert-item.urgent { border-left: 2px solid var(--danger); }
        .alert-item.warn   { border-left: 2px solid var(--warning); }

        .alert-icon {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .alert-item.urgent .alert-icon { background: rgba(201, 42, 42, 0.08); color: var(--danger); }
        .alert-item.warn   .alert-icon { background: rgba(230, 119, 0, 0.08);  color: var(--warning); }

        .alert-body { flex: 1; min-width: 0; }
        .alert-body h4 { font-size: 0.84rem; font-weight: 500; margin-bottom: 0.15rem; }
        .alert-body p  { font-size: 0.75rem; color: var(--text-muted); line-height: 1.45; }
        .alert-meta    { font-family: 'DM Mono', monospace; font-size: 0.7rem; color: var(--text-faint); font-weight: 400; white-space: nowrap; }

        /* ── Calendar ── */
        .calendar-card { margin-bottom: 1.75rem; }

        .calendar-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }

        .calendar-head h2 {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .calendar-head h2 i { color: var(--accent); font-size: 0.95rem; }
        .calendar-sub { color: var(--text-faint); font-size: 0.78rem; line-height: 1.45; margin-top: 0.2rem; }

        #nexcheckCalendar {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            padding: 1rem;
        }

        .fc { font-size: 0.86rem; }
        .fc-toolbar { margin-bottom: 0.75rem; }
        .fc button {
            border-radius: var(--radius-sm) !important;
            border: 1px solid var(--card-border) !important;
            background: var(--card-bg) !important;
            color: var(--text-muted) !important;
            font-weight: 500 !important;
            font-family: 'DM Sans', sans-serif !important;
            box-shadow: none !important;
        }
        .fc button.fc-state-active {
            background: var(--accent-soft) !important;
            border-color: var(--accent-border) !important;
            color: var(--accent) !important;
        }
        .fc-event { border-radius: 4px; border: none; padding: 2px 6px; font-size: 0.78rem; }

        /* ── Responsive ── */
        @media (max-width: 1200px) {
            .stats-grid        { grid-template-columns: repeat(2, 1fr); }
            .charts-section    { grid-template-columns: 1fr; }
            .dashboard-bottom  { grid-template-columns: 1fr; }
        }

        @media (max-width: 900px) {
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
        <?php if ($dbError): ?>
            <div class="db-alert">
                <i class="ri-error-warning-line"></i> Could not load dashboard data. Check the database connection and that <code>db/schema.sql</code> has been applied.
            </div>
        <?php endif; ?>

        <header class="topbar">
            <div class="greeting">
                <h1>Hello, <?= htmlspecialchars($firstName) ?></h1>
                <p>Inventory, repairs, and network health from NIMS — all figures below come from your live database.</p>
            </div>
            <div class="actions">
                <button type="button" class="header-icon-btn" title="Search" aria-label="Search">
                    <i class="ri-search-2-line"></i>
                </button>
                <button type="button" class="header-icon-btn" title="Alerts" aria-label="Alerts">
                    <i class="ri-notification-3-line"></i>
                    <?php if ($attentionCount > 0 || $stats['warranty_expiring'] > 0): ?>
                        <span class="notification-dot" aria-hidden="true"></span>
                    <?php endif; ?>
                </button>
            </div>
        </header>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="ri-stack-line"></i></div>
                <div class="stat-body">
                    <div class="stat-value"><?= number_format($stats['assets_total']) ?></div>
                    <div class="stat-label">Total registered assets</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-amber"><i class="ri-tools-line"></i></div>
                <div class="stat-body">
                    <div class="stat-value"><?= number_format($stats['open_repairs']) ?></div>
                    <div class="stat-label">Open repair records</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="ri-shopping-bag-3-line"></i></div>
                <div class="stat-body">
                    <div class="stat-value"><?= number_format($stats['nexcheck_out']) ?></div>
                    <div class="stat-label">NextCheck checked out</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-red"><i class="ri-alert-line"></i></div>
                <div class="stat-body">
                    <div class="stat-value"><?= number_format($attentionCount) ?></div>
                    <div class="stat-label">Faulty + offline network</div>
                </div>
            </div>
        </div>

        <section class="calendar-card" aria-label="NextCheck calendar">
            <div class="calendar-head">
                <div>
                    <h2><i class="ri-calendar-event-line"></i> NextCheck calendar</h2>
                    <div class="calendar-sub">Borrow/return windows from <code>nexcheck_request</code>. Click an event to open the request.</div>
                </div>
                <a href="nextCheckout.php" class="btn btn-primary" style="text-decoration:none">
                    <i class="ri-hand-coin-line"></i> NextCheck requests
                </a>
            </div>
            <div id="nexcheckCalendar"></div>
        </section>

        <section class="charts-section" aria-label="Analytics charts">
            <div class="chart-card">
                <h3><i class="ri-pie-chart-2-line"></i> Asset mix</h3>
                <p class="sub">Laptop, network, and AV record counts</p>
                <div class="chart-wrap"><canvas id="chartAssetMix"></canvas></div>
            </div>
            <div class="chart-card">
                <h3><i class="ri-bar-chart-horizontal-line"></i> Laptop by status</h3>
                <p class="sub">Distribution from <code>status</code> references</p>
                <div class="chart-wrap"><canvas id="chartLaptopBar"></canvas></div>
            </div>
            <div class="chart-card">
                <h3><i class="ri-line-chart-line"></i> Repairs completed</h3>
                <p class="sub">Monthly count where <code>completed_date</code> is set (last 6 months)</p>
                <div class="chart-wrap"><canvas id="chartRepairsLine"></canvas></div>
            </div>
            <div class="chart-card">
                <h3><i class="ri-router-line"></i> Network by status</h3>
                <p class="sub">Online, offline, deploy, maintenance, etc.</p>
                <div class="chart-wrap"><canvas id="chartNetworkDoughnut"></canvas></div>
            </div>
        </section>

        <div class="dashboard-bottom">
            <div class="panel">
                <div class="panel-header">
                    <h2><i class="ri-time-line"></i> Recent repairs &amp; claims</h2>
                    <a href="warranty.php" class="view-all">Warranty</a>
                </div>
                <div style="overflow-x:auto;">
                    <table class="recent-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Summary</th>
                                <th>Asset</th>
                                <th>When</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$recentRows): ?>
                                <tr>
                                    <td colspan="5" style="color:var(--text-muted);font-size:0.9rem;">No repair or warranty claim activity yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentRows as $row):
                                    $kind = $row['kind'];
                                    $aid = (int) $row['asset_id'];
                                    $at = (string) $row['asset_type'];
                                    $href = dashboard_asset_href($at, $aid);
                                    $ts = $row['ts'] ? date('M j, Y g:i a', strtotime((string) $row['ts'])) : '—';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($kind === 'repair'): ?>
                                                <span class="kind-badge kind-repair">Repair</span>
                                            <?php else: ?>
                                                <span class="kind-badge kind-claim">Claim</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="title-cell"><?= htmlspecialchars((string) $row['title']) ?></div>
                                            <div class="muted-small"><?= htmlspecialchars((string) $row['extra']) ?> · <?= htmlspecialchars($at) ?></div>
                                        </td>
                                        <td>#<?= $aid ?></td>
                                        <td style="font-size:0.82rem;color:var(--text-muted);"><?= htmlspecialchars($ts) ?></td>
                                        <td>
                                            <a class="btn-icon" href="<?= htmlspecialchars($href) ?>" title="Open asset"><i class="ri-external-link-line"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h2><i class="ri-shield-check-line"></i> Operational alerts</h2>
                    <a href="network.php" class="view-all">Network</a>
                </div>
                <div class="alerts-list">
                    <?php if (!$alertOffline && !$alertWarranties): ?>
                        <p style="color:var(--text-muted);font-size:0.9rem;">No offline devices or short-term warranty expiries in the current window.</p>
                    <?php endif; ?>
                    <?php foreach ($alertOffline as $dev):
                        $label = trim(implode(' ', array_filter([(string) $dev['brand'], (string) $dev['model']])));
                        if ($label === '') {
                            $label = 'Asset #' . (int) $dev['asset_id'];
                        }
                        $ip = (string) ($dev['ip_address'] ?? '');
                        ?>
                        <div class="alert-item urgent">
                            <div class="alert-icon"><i class="ri-wifi-off-line"></i></div>
                            <div class="alert-body">
                                <h4><?= htmlspecialchars($label) ?></h4>
                                <p>Network asset is <strong>Offline</strong> (status Offline). <?= $ip !== '' ? 'IP: ' . htmlspecialchars($ip) : 'ID #' . (int) $dev['asset_id'] ?>.</p>
                            </div>
                            <div class="alert-meta">#<?= (int) $dev['asset_id'] ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($alertWarranties as $w):
                        $days = (int) $w['days_left'];
                        $cls = $days <= 30 ? 'urgent' : 'warn';
                        $at = (string) $w['asset_type'];
                        $end = $w['warranty_end_date'] ? date('M j, Y', strtotime((string) $w['warranty_end_date'])) : '—';
                        ?>
                        <div class="alert-item <?= $cls ?>">
                            <div class="alert-icon"><i class="ri-calendar-close-line"></i></div>
                            <div class="alert-body">
                                <h4>Warranty ending · <?= htmlspecialchars(ucfirst($at)) ?> #<?= (int) $w['asset_id'] ?></h4>
                                <p>Ends <?= htmlspecialchars($end) ?> (<?= $days ?> day<?= $days === 1 ? '' : 's' ?> left).</p>
                            </div>
                            <div class="alert-meta"><?= $days ?>d</div>
                        </div>
                    <?php endforeach; ?>
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

        (function () {
            var events = <?= json_encode($nexcheckEvents, $chartJsonFlags) ?>;
            var el = document.getElementById('nexcheckCalendar');
            if (!el || typeof jQuery === 'undefined' || !jQuery.fn || !jQuery.fn.fullCalendar) return;
            jQuery(el).fullCalendar({
                height: 520,
                fixedWeekCount: false,
                displayEventTime: false,
                header: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'month,agendaWeek,agendaDay'
                },
                eventLimit: true,
                events: events
            });
        })();

        Chart.defaults.font.family = "'DM Sans', sans-serif";
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#6b6b7b';

        const teal   = 'rgba(32, 178, 170, 0.82)';
        const blue   = 'rgba(59, 91, 219, 0.85)';
        const amber  = 'rgba(230, 119, 0, 0.85)';
        const green  = 'rgba(47, 158, 68, 0.85)';
        const red    = 'rgba(201, 42, 42, 0.82)';
        const slate  = 'rgba(107, 107, 123, 0.6)';
        const violet = 'rgba(121, 80, 242, 0.82)';

        const assetMixData = <?= json_encode($chartAssetMix, $chartJsonFlags) ?>;
        const laptopData = <?= json_encode($chartLaptopStatus, $chartJsonFlags) ?>;
        const repairsTrend = <?= json_encode($chartRepairsTrend, $chartJsonFlags) ?>;
        const networkData = <?= json_encode($chartNetworkStatus, $chartJsonFlags) ?>;

        const palette = [blue, teal, amber, green, red, violet, slate];

        new Chart(document.getElementById('chartAssetMix'), {
            type: 'doughnut',
            data: {
                labels: assetMixData.labels,
                datasets: [{
                    data: assetMixData.data,
                    backgroundColor: [blue, teal, amber],
                    borderWidth: 2,
                    borderColor: '#f7f7f8',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        new Chart(document.getElementById('chartLaptopBar'), {
            type: 'bar',
            data: {
                labels: laptopData.labels,
                datasets: [{
                    label: 'Devices',
                    data: laptopData.data,
                    backgroundColor: laptopData.data.map((_, i) => palette[i % palette.length]),
                    borderRadius: 4,
                    maxBarThickness: 22
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 } },
                    y: { grid: { display: false } }
                }
            }
        });

        new Chart(document.getElementById('chartRepairsLine'), {
            type: 'line',
            data: {
                labels: repairsTrend.labels,
                datasets: [{
                    label: 'Completed',
                    data: repairsTrend.data,
                    borderColor: blue,
                    backgroundColor: 'rgba(37, 99, 235, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    pointBackgroundColor: blue
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });

        new Chart(document.getElementById('chartNetworkDoughnut'), {
            type: 'doughnut',
            data: {
                labels: networkData.labels,
                datasets: [{
                    data: networkData.data,
                    backgroundColor: networkData.labels.map((name, i) => {
                        const n = (name || '').toLowerCase();
                        if (n.includes('online')) return green;
                        if (n.includes('offline')) return red;
                        return palette[(i + 2) % palette.length];
                    }),
                    borderWidth: 2,
                    borderColor: '#f7f7f8',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    </script>
</body>
</html>