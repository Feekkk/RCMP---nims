<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function dashboard_asset_href(string $assetType, int $assetId): string
{
    switch ($assetType) {
        case 'laptop':
            return 'laptopView.php?asset_id=' . $assetId;
        case 'av':
            return 'avView.php?asset_id=' . $assetId;
        case 'network':
            return 'network.php';
        default:
            return 'laptopView.php?asset_id=' . $assetId;
    }
}

$dbError = false;
$stats = [
    'assets_total' => 0,
    'laptops'      => 0,
    'network'      => 0,
    'av'           => 0,
];
$chartAssetStatus = ['labels' => [], 'data' => []];
$chartTrend = ['labels' => [], 'handovers' => [], 'deployments' => [], 'nextcheck' => []];
$recentActivities = [];
$nexcheckEvents = [];
$nexcheckRequestList = [];

$techName = isset($_SESSION['user_name']) ? trim((string) $_SESSION['user_name']) : 'Technician';

try {
    $pdo = db();

    $stats['laptops'] = (int) $pdo->query('SELECT COUNT(*) FROM laptop')->fetchColumn();
    $stats['network'] = (int) $pdo->query('SELECT COUNT(*) FROM network')->fetchColumn();
    $stats['av'] = (int) $pdo->query('SELECT COUNT(*) FROM av')->fetchColumn();
    $stats['assets_total'] = $stats['laptops'] + $stats['network'] + $stats['av'];

    $statusRows = $pdo->query(
        'SELECT s.name, COUNT(*) AS c
         FROM (
             SELECT status_id FROM laptop
             UNION ALL SELECT status_id FROM network
             UNION ALL SELECT status_id FROM av
         ) u
         JOIN status s ON s.status_id = u.status_id
         GROUP BY s.status_id, s.name
         ORDER BY c DESC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $topN = 8;
    $other = 0;
    foreach ($statusRows as $i => $row) {
        $c = (int) $row['c'];
        if ($i < $topN) {
            $chartAssetStatus['labels'][] = (string) $row['name'];
            $chartAssetStatus['data'][] = $c;
        } else {
            $other += $c;
        }
    }
    if ($other > 0) {
        $chartAssetStatus['labels'][] = 'Other';
        $chartAssetStatus['data'][] = $other;
    }
    if ($chartAssetStatus['labels'] === []) {
        $chartAssetStatus = ['labels' => ['No assets'], 'data' => [0]];
    }

    $hoMonths = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM handover
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
         GROUP BY ym"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $depAv = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM av_deployment
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
         GROUP BY ym"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $depNet = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM network_deployment
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
         GROUP BY ym"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $ncMonths = $pdo->query(
        "SELECT DATE_FORMAT(checkout_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM nexcheck_assignment
         WHERE checkout_at IS NOT NULL
           AND checkout_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
         GROUP BY ym"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $start = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
    $chartTrend['labels'] = [];
    $chartTrend['handovers'] = [];
    $chartTrend['deployments'] = [];
    $chartTrend['nextcheck'] = [];
    for ($i = 0; $i < 6; $i++) {
        $m = $start->modify('+' . $i . ' months');
        $key = $m->format('Y-m');
        $chartTrend['labels'][] = $m->format('M Y');
        $chartTrend['handovers'][] = (int) ($hoMonths[$key] ?? 0);
        $chartTrend['deployments'][] = (int) ($depAv[$key] ?? 0) + (int) ($depNet[$key] ?? 0);
        $chartTrend['nextcheck'][] = (int) ($ncMonths[$key] ?? 0);
    }

    $recentSql = '
        SELECT kind, title, ts, asset_id, asset_type FROM (
            SELECT
                \'laptop_reg\' AS kind,
                CONCAT(\'Laptop: \', TRIM(CONCAT(IFNULL(l.brand, \'\'), \' \', IFNULL(l.model, \'\')))) AS title,
                l.created_at AS ts,
                l.asset_id,
                \'laptop\' AS asset_type
            FROM laptop l
            UNION ALL
            SELECT
                \'network_reg\',
                CONCAT(\'Network: \', TRIM(CONCAT(IFNULL(n.brand, \'\'), \' \', IFNULL(n.model, \'\')))),
                n.created_at,
                n.asset_id,
                \'network\'
            FROM network n
            UNION ALL
            SELECT
                \'av_reg\',
                CONCAT(\'AV: \', TRIM(CONCAT(IFNULL(a.category, \'\'), \' \', IFNULL(a.brand, \'\'), \' \', IFNULL(a.model, \'\')))),
                a.created_at,
                a.asset_id,
                \'av\'
            FROM av a
            UNION ALL
            SELECT
                \'handover\',
                CONCAT(\'Handover #\', h.handover_id, \' · laptop #\', h.asset_id),
                h.created_at,
                h.asset_id,
                \'laptop\'
            FROM handover h
            UNION ALL
            SELECT
                \'warranty\',
                CONCAT(\'Warranty (\', w.asset_type, \') #\', w.asset_id),
                w.created_at,
                w.asset_id,
                w.asset_type
            FROM warranty w
            UNION ALL
            SELECT
                \'repair\',
                CONCAT(\'Repair (\', r.asset_type, \'): \', LEFT(r.issue_summary, 80)),
                r.created_at,
                r.asset_id,
                r.asset_type
            FROM repair r
        ) u
        ORDER BY ts DESC
        LIMIT 10
    ';
    $recentActivities = $pdo->query($recentSql)->fetchAll(PDO::FETCH_ASSOC);

    // Calendar should show only request items + assigned items (exclude rejected requests).
    $stmtReqItems = $pdo->prepare(
        'SELECT r.nexcheck_id, r.borrow_date, r.return_date, r.usage_location, u.full_name AS requester_name,
                i.category, i.quantity
         FROM nexcheck_request r
         JOIN users u ON u.staff_id = r.requested_by
         JOIN nexcheck_request_item i ON i.nexcheck_id = r.nexcheck_id
         WHERE r.rejected_at IS NULL
           AND r.return_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           AND r.borrow_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY)
         ORDER BY r.borrow_date ASC, r.nexcheck_id DESC, i.request_item_id ASC
         LIMIT 600'
    );
    $stmtReqItems->execute();
    foreach ($stmtReqItems->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nid = (int)($r['nexcheck_id'] ?? 0);
        $bd = (string)($r['borrow_date'] ?? '');
        $rd = (string)($r['return_date'] ?? '');
        if ($nid < 1 || $bd === '' || $rd === '') continue;

        $who = trim((string)($r['requester_name'] ?? ''));
        $cat = trim((string)($r['category'] ?? 'Request item'));
        $qty = (int)($r['quantity'] ?? 1);
        $loc = trim((string)($r['usage_location'] ?? ''));

        $title = 'Request #' . $nid . ' · ' . $cat;
        if ($qty > 1) $title .= ' ×' . $qty;
        if ($who !== '') $title .= ' · ' . $who;
        if ($loc !== '') $title .= ' — ' . $loc;

        $nexcheckEvents[] = [
            'title'  => $title,
            'start'  => $bd,
            'end'    => (new DateTimeImmutable($rd))->modify('+1 day')->format('Y-m-d'),
            'allDay' => true,
            'url'    => 'nextItems.php?nexcheck_id=' . $nid,
            'color'  => '#2563eb',
        ];
    }

    $stmtAssigned = $pdo->prepare(
        'SELECT r.nexcheck_id, r.borrow_date, r.return_date, a.asset_id, a.returned_at, i.category
         FROM nexcheck_assignment a
         JOIN nexcheck_request r ON r.nexcheck_id = a.nexcheck_id
         LEFT JOIN nexcheck_request_item i ON i.request_item_id = a.request_item_id
         WHERE r.rejected_at IS NULL
           AND r.return_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           AND r.borrow_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY)
           AND a.returned_at IS NULL
         ORDER BY r.borrow_date ASC, r.nexcheck_id DESC, a.assignment_id DESC
         LIMIT 800'
    );
    $stmtAssigned->execute();
    foreach ($stmtAssigned->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nid = (int)($r['nexcheck_id'] ?? 0);
        $bd = (string)($r['borrow_date'] ?? '');
        $rd = (string)($r['return_date'] ?? '');
        $aid = (int)($r['asset_id'] ?? 0);
        if ($nid < 1 || $aid < 1 || $bd === '' || $rd === '') continue;

        $cat = trim((string)($r['category'] ?? 'Assigned item'));
        $title = 'Assigned #' . $aid . ($cat !== '' ? ' · ' . $cat : '') . ' · Req #' . $nid;

        $nexcheckEvents[] = [
            'title'  => $title,
            'start'  => $bd,
            'end'    => (new DateTimeImmutable($rd))->modify('+1 day')->format('Y-m-d'),
            'allDay' => true,
            'url'    => 'nextItems.php?nexcheck_id=' . $nid,
            'color'  => '#10b981',
        ];
    }

    $nexcheckRequestList = $pdo->query(
        'SELECT r.nexcheck_id, r.borrow_date, r.return_date, r.usage_location,
                u.full_name AS requester_name, r.rejected_at, r.created_at
         FROM nexcheck_request r
         JOIN users u ON u.staff_id = r.requested_by
         ORDER BY r.created_at DESC
         LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError = true;
    $recentActivities = [];
    $nexcheckEvents = [];
    $nexcheckRequestList = [];
}

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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../vendor/fullcalendar/fullcalendar/dist/fullcalendar.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.30.1/min/moment.min.js"></script>
    <script src="../vendor/fullcalendar/fullcalendar/dist/fullcalendar.min.js"></script>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #7c3aed;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg: #f0f4ff;
            --card: #ffffff;
            --border: #e2e8f0;
            --text: #0f172a;
            --muted: #64748b;
            --radius: 16px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            min-height: 100vh;
        }
        .blob { position: fixed; border-radius: 50%; filter: blur(80px); pointer-events: none; z-index: 0; }
        .blob-1 { width: 420px; height: 420px; background: rgba(37,99,235,0.08); top: -80px; right: -60px; }
        .blob-2 { width: 360px; height: 360px; background: rgba(124,58,237,0.06); bottom: -60px; left: -40px; }

        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 1.75rem 2.5rem 3rem;
            max-width: calc(100vw - 280px);
            position: relative;
            z-index: 1;
        }

        .dash-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            padding: 0.75rem 1.25rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.04);
        }
        .dash-topbar img { height: 42px; width: auto; object-fit: contain; }
        .dash-topbar .brand-mid {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--muted);
            letter-spacing: 0.02em;
        }

        .greeting-block {
            background: linear-gradient(135deg, rgba(37,99,235,0.12), rgba(124,58,237,0.08));
            border: 1px solid rgba(37,99,235,0.2);
            border-radius: var(--radius);
            padding: 1.35rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        .greeting-block h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.65rem;
            font-weight: 700;
            color: var(--text);
        }
        .greeting-block h1 span { color: var(--primary); }
        .greeting-block p { margin-top: 0.35rem; font-size: 0.9rem; color: var(--muted); max-width: 40rem; line-height: 1.5; }
        .greeting-meta { margin-top: 0.65rem; font-size: 0.8rem; color: var(--muted); }
        .greeting-meta strong { color: var(--warning); }

        .db-alert {
            background: rgba(239,68,68,0.08);
            border: 1px solid rgba(239,68,68,0.25);
            color: #b91c1c;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
        }

        .count-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 1.75rem;
        }
        .count-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem 1.35rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .count-card:hover {
            box-shadow: 0 8px 24px rgba(37,99,235,0.1);
            transform: translateY(-2px);
        }
        .count-card .ico {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }
        .ico-all { background: rgba(37,99,235,0.12); color: var(--primary); }
        .ico-lap { background: rgba(16,185,129,0.12); color: var(--success); }
        .ico-av { background: rgba(245,158,11,0.15); color: #d97706; }
        .ico-net { background: rgba(124,58,237,0.12); color: var(--secondary); }
        .count-card .num {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 700;
            line-height: 1.1;
            color: var(--text);
        }
        .count-card .lbl { font-size: 0.78rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 0.35rem; }

        .nexcheck-section {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(260px, 1fr);
            gap: 1.25rem;
            margin-bottom: 1.75rem;
            align-items: stretch;
        }
        .panel {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem 1.35rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.04);
        }
        .panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .panel-head h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text);
        }
        .panel-head h2 i { color: var(--primary); }
        .panel-head .sub { font-size: 0.8rem; color: var(--muted); margin-top: 0.25rem; max-width: 36rem; line-height: 1.45; }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 1.1rem;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-primary:hover { filter: brightness(1.06); }

        #nexcheckCalendar { min-height: 480px; }
        .fc { font-size: 0.85rem; }
        .fc-toolbar { margin-bottom: 0.65rem !important; }
        .fc button {
            border-radius: 8px !important;
            border: 1px solid var(--border) !important;
            background: var(--card) !important;
            color: var(--muted) !important;
            font-weight: 500 !important;
            font-family: 'Inter', sans-serif !important;
        }
        .fc button.fc-state-active {
            background: rgba(37,99,235,0.1) !important;
            border-color: rgba(37,99,235,0.35) !important;
            color: var(--primary) !important;
        }
        .fc-event { border-radius: 6px; border: none; font-size: 0.76rem; }

        .nc-list { list-style: none; max-height: 520px; overflow-y: auto; }
        .nc-list li { border-bottom: 1px solid var(--border); }
        .nc-list li:last-child { border-bottom: none; }
        .nc-list a {
            display: block;
            padding: 0.85rem 0.25rem;
            text-decoration: none;
            color: inherit;
            border-radius: 8px;
            transition: background 0.15s;
        }
        .nc-list a:hover { background: rgba(37,99,235,0.06); }
        .nc-list .rid { font-family: 'Outfit', sans-serif; font-weight: 700; color: var(--primary); font-size: 0.9rem; }
        .nc-list .who { font-size: 0.82rem; color: var(--text); margin-top: 0.2rem; }
        .nc-list .dates { font-size: 0.75rem; color: var(--muted); margin-top: 0.35rem; }
        .nc-list .rej { font-size: 0.72rem; color: var(--danger); margin-top: 0.35rem; font-weight: 600; }

        .charts-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }
        .chart-panel .chart-wrap { position: relative; height: 280px; margin-top: 0.5rem; }
        .chart-panel .cap { font-size: 0.78rem; color: var(--muted); margin-top: 0.35rem; line-height: 1.45; }

        .recent-section { margin-bottom: 2rem; }
        .recent-section .panel-head { margin-bottom: 0.85rem; }
        .recent-badge { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.2rem 0.55rem; border-radius: 6px; }
        .rb-reg { background: rgba(37,99,235,0.12); color: var(--primary); }
        .rb-ho { background: rgba(16,185,129,0.12); color: var(--success); }
        .rb-war { background: rgba(245,158,11,0.15); color: #b45309; }
        .rb-rep { background: rgba(124,58,237,0.12); color: #6d28d9; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
        .data-table th {
            text-align: left;
            padding: 0.65rem 0.75rem;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            font-weight: 700;
            border-bottom: 1px solid var(--border);
        }
        .data-table td { padding: 0.85rem 0.75rem; border-bottom: 1px solid rgba(226,232,240,0.8); vertical-align: middle; }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover { background: rgba(37,99,235,0.03); }
        .title-cell { font-weight: 500; max-width: 320px; }
        .muted { font-size: 0.78rem; color: var(--muted); }
        .btn-icon {
            width: 34px; height: 34px; border-radius: 8px;
            border: 1px solid var(--border);
            display: inline-flex; align-items: center; justify-content: center;
            color: var(--muted); text-decoration: none;
            transition: all 0.15s;
        }
        .btn-icon:hover { background: var(--primary); border-color: var(--primary); color: #fff; }

        @media (max-width: 1200px) {
            .count-cards { grid-template-columns: repeat(2, 1fr); }
            .nexcheck-section { grid-template-columns: 1fr; }
            .charts-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 900px) {
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.25rem; }
        }
    </style>
</head>
<body>
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <header class="dash-topbar" aria-label="Institution header">
        <span class="brand-mid">Nexcheck Inventory Management System · Technician</span>
        <img src="../public/unikl-official.png" alt="UniKL">
    </header>

    <?php if ($dbError): ?>
        <div class="db-alert">
            <i class="ri-error-warning-line"></i> Could not load dashboard data. Check the database connection and that <code>db/schema.sql</code> has been applied.
        </div>
    <?php endif; ?>

    <section class="greeting-block">
        <h1>Hello, <span><?= htmlspecialchars($techName !== '' ? $techName : 'Technician', ENT_QUOTES, 'UTF-8') ?></span></h1>
        <p>Overview of registered assets, NextCheck requests, activity trends, and your latest ten events.</p>
    </section>

    <div class="count-cards">
        <div class="count-card">
            <div class="ico ico-all"><i class="ri-stack-line"></i></div>
            <div>
                <div class="num"><?= number_format($stats['assets_total']) ?></div>
                <div class="lbl">Total asset registered</div>
            </div>
        </div>
        <div class="count-card">
            <div class="ico ico-lap"><i class="ri-macbook-line"></i></div>
            <div>
                <div class="num"><?= number_format($stats['laptops']) ?></div>
                <div class="lbl">Total laptop</div>
            </div>
        </div>
        <div class="count-card">
            <div class="ico ico-av"><i class="ri-mic-line"></i></div>
            <div>
                <div class="num"><?= number_format($stats['av']) ?></div>
                <div class="lbl">Total AV</div>
            </div>
        </div>
        <div class="count-card">
            <div class="ico ico-net"><i class="ri-router-line"></i></div>
            <div>
                <div class="num"><?= number_format($stats['network']) ?></div>
                <div class="lbl">Total network</div>
            </div>
        </div>
    </div>

    <section class="nexcheck-section" aria-label="NextCheck">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2><i class="ri-calendar-event-line"></i> Calendar requests</h2>
                    <p class="sub">Borrow and return windows from NextCheck. Click an event to open the request.</p>
                </div>
                <a href="nextCheckout.php" class="btn-primary"><i class="ri-hand-coin-line"></i> NextCheck queue</a>
            </div>
            <div id="nexcheckCalendar"></div>
        </div>
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2><i class="ri-file-list-3-line"></i> NextCheck requests</h2>
                    <p class="sub">Latest submissions (newest first).</p>
                </div>
            </div>
            <?php if (!$nexcheckRequestList): ?>
                <p class="muted" style="padding:0.5rem 0;">No requests yet.</p>
            <?php else: ?>
                <ul class="nc-list">
                    <?php foreach ($nexcheckRequestList as $nr):
                        $nid = (int) ($nr['nexcheck_id'] ?? 0);
                        $rej = $nr['rejected_at'] ?? null;
                        ?>
                        <li>
                            <a href="nextItems.php?nexcheck_id=<?= $nid ?>">
                                <div class="rid">#<?= $nid ?></div>
                                <div class="who"><?= htmlspecialchars((string) ($nr['requester_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="dates">
                                    <?= htmlspecialchars((string) ($nr['borrow_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    → <?= htmlspecialchars((string) ($nr['return_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($nr['usage_location'])): ?>
                                        · <?= htmlspecialchars((string) $nr['usage_location'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($rej): ?>
                                    <div class="rej">Rejected</div>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section class="charts-row" aria-label="Charts">
        <div class="panel chart-panel">
            <div class="panel-head" style="margin-bottom:0.5rem;">
                <div>
                    <h2><i class="ri-pie-chart-2-line"></i> Asset status</h2>
                    <p class="sub">All laptops, network, and AV grouped by status name (top categories).</p>
                </div>
            </div>
            <p class="cap">Doughnut view of live inventory distribution from <code>status</code>.</p>
            <div class="chart-wrap"><canvas id="chartAssetStatus"></canvas></div>
        </div>
        <div class="panel chart-panel">
            <div class="panel-head" style="margin-bottom:0.5rem;">
                <div>
                    <h2><i class="ri-line-chart-line"></i> Deploy / network / NextCheck &amp; handover</h2>
                    <p class="sub">Monthly counts: AV + network deployments, laptop handovers, NextCheck checkouts.</p>
                </div>
            </div>
            <div class="chart-wrap"><canvas id="chartTrendLine"></canvas></div>
        </div>
    </section>

    <section class="recent-section" aria-label="Recent activities">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2><i class="ri-history-line"></i> Recent activities</h2>
                    <p class="sub">Latest 10 events across registrations, handovers, warranty, and repairs.</p>
                </div>
                <a href="history.php" class="btn-primary" style="background:var(--card);color:var(--primary);border:1px solid var(--border);">Full history</a>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table">
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
                        <?php if (!$recentActivities): ?>
                            <tr><td colspan="5" class="muted" style="padding:1.25rem;">No activity yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentActivities as $row):
                                $kind = (string) $row['kind'];
                                $aid = (int) $row['asset_id'];
                                $at = (string) ($row['asset_type'] ?? 'laptop');
                                $href = dashboard_asset_href($at, $aid);
                                $ts = $row['ts'] ? date('M j, Y g:i a', strtotime((string) $row['ts'])) : '—';
                                if ($kind === 'laptop_reg' || strpos($kind, 'laptop') === 0) {
                                    $badge = ['cls' => 'rb-reg', 'label' => 'Laptop reg'];
                                } elseif ($kind === 'network_reg' || strpos($kind, 'network') === 0) {
                                    $badge = ['cls' => 'rb-reg', 'label' => 'Network reg'];
                                } elseif ($kind === 'av_reg') {
                                    $badge = ['cls' => 'rb-reg', 'label' => 'AV reg'];
                                } elseif ($kind === 'handover') {
                                    $badge = ['cls' => 'rb-ho', 'label' => 'Handover'];
                                } elseif ($kind === 'warranty') {
                                    $badge = ['cls' => 'rb-war', 'label' => 'Warranty'];
                                } elseif ($kind === 'repair') {
                                    $badge = ['cls' => 'rb-rep', 'label' => 'Repair'];
                                } else {
                                    $badge = ['cls' => 'rb-reg', 'label' => $kind];
                                }
                                ?>
                                <tr>
                                    <td><span class="recent-badge <?= $badge['cls'] ?>"><?= htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><div class="title-cell"><?= htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8') ?></div></td>
                                    <td><code style="font-size:0.82rem;">#<?= $aid ?></code> <span class="muted"><?= htmlspecialchars($at, ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td class="muted"><?= htmlspecialchars($ts, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><a class="btn-icon" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" title="Open"><i class="ri-external-link-line"></i></a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
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
            height: 480,
            fixedWeekCount: false,
            displayEventTime: false,
            header: { left: 'prev,next today', center: 'title', right: 'month,agendaWeek,agendaDay' },
            eventLimit: true,
            events: events
        });
    })();

    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#64748b';

    const blue = 'rgba(37, 99, 235, 0.9)';
    const teal = 'rgba(20, 184, 166, 0.85)';
    const amber = 'rgba(245, 158, 11, 0.9)';
    const violet = 'rgba(124, 58, 237, 0.88)';
    const green = 'rgba(16, 185, 129, 0.88)';
    const red = 'rgba(239, 68, 68, 0.85)';
    const palette = [blue, teal, amber, violet, green, red, 'rgba(100,116,139,0.75)', 'rgba(14,165,233,0.85)'];

    const statusData = <?= json_encode($chartAssetStatus, $chartJsonFlags) ?>;
    new Chart(document.getElementById('chartAssetStatus'), {
        type: 'doughnut',
        data: {
            labels: statusData.labels,
            datasets: [{
                data: statusData.data,
                backgroundColor: statusData.labels.map((_, i) => palette[i % palette.length]),
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'right' } }
        }
    });

    const trend = <?= json_encode($chartTrend, $chartJsonFlags) ?>;
    new Chart(document.getElementById('chartTrendLine'), {
        type: 'line',
        data: {
            labels: trend.labels,
            datasets: [
                {
                    label: 'Deployments (AV + network)',
                    data: trend.deployments,
                    borderColor: teal,
                    backgroundColor: 'rgba(20, 184, 166, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3
                },
                {
                    label: 'Handovers (laptop)',
                    data: trend.handovers,
                    borderColor: blue,
                    backgroundColor: 'rgba(37, 99, 235, 0.08)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3
                },
                {
                    label: 'NextCheck checkouts',
                    data: trend.nextcheck,
                    borderColor: violet,
                    backgroundColor: 'rgba(124, 58, 237, 0.08)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } }
            }
        }
    });
</script>
</body>
</html>
