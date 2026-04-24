<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 2) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$pdo = db();
$dbError = '';
$chartJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE;
$adminName = trim((string)($_SESSION['user_name'] ?? ''));
$adminName = $adminName !== '' ? $adminName : trim((string)($_SESSION['staff_id'] ?? 'Admin'));

try {
    $counts = [
        'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'techs' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 1')->fetchColumn(),
        'admins' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 2')->fetchColumn(),
        'nextcheck_users' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 3')->fetchColumn(),
        'laptops' => (int)$pdo->query('SELECT COUNT(*) FROM laptop')->fetchColumn(),
        'networks' => (int)$pdo->query('SELECT COUNT(*) FROM network')->fetchColumn(),
        'av' => (int)$pdo->query('SELECT COUNT(*) FROM av')->fetchColumn(),
        'handovers' => (int)$pdo->query('SELECT COUNT(*) FROM handover')->fetchColumn(),
        'warranties' => (int)$pdo->query('SELECT COUNT(*) FROM warranty')->fetchColumn(),
    ];
    $counts['assets_total'] = $counts['laptops'] + $counts['networks'] + $counts['av'];

    $chartAssetStatus = ['labels' => [], 'data' => []];
    foreach ($pdo->query("
        SELECT s.name, COUNT(*) AS c
        FROM (
            SELECT status_id FROM laptop
            UNION ALL
            SELECT status_id FROM network
            UNION ALL
            SELECT status_id FROM av
        ) x
        JOIN status s ON s.status_id = x.status_id
        GROUP BY s.status_id, s.name
        ORDER BY c DESC, s.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chartAssetStatus['labels'][] = (string)$row['name'];
        $chartAssetStatus['data'][] = (int)$row['c'];
    }

    $countByStatusId = [];
    foreach ($pdo->query("
        SELECT x.status_id, COUNT(*) AS c
        FROM (
            SELECT status_id FROM laptop
            UNION ALL
            SELECT status_id FROM network
            UNION ALL
            SELECT status_id FROM av
        ) x
        GROUP BY x.status_id
    ")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $countByStatusId[(int)$row['status_id']] = (int)$row['c'];
    }

    $counts['deploy'] = (int)($countByStatusId[3] ?? 0);
    $counts['maintenance'] = (int)($countByStatusId[5] ?? 0);
    $counts['faulty'] = (int)($countByStatusId[6] ?? 0);
    $counts['disposed'] = (int)($countByStatusId[7] ?? 0);
    $counts['disposal_forms'] = (int)$pdo->query('SELECT COUNT(*) FROM disposal')->fetchColumn();

    $chartUsersByRole = [
        'labels' => ['Technician', 'Admin', 'NextCheck'],
        'data' => [(int)$counts['techs'], (int)$counts['admins'], (int)$counts['nextcheck_users']],
    ];

    $chartNextcheck = ['labels' => [], 'data' => []];
    foreach ($pdo->query("
        SELECT DATE_FORMAT(MIN(created_at), '%b %Y') AS m, COUNT(*) AS c, MIN(created_at) AS sort_key
        FROM nexcheck_request
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY YEAR(created_at), MONTH(created_at)
        ORDER BY sort_key ASC
    ")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chartNextcheck['labels'][] = (string)$row['m'];
        $chartNextcheck['data'][] = (int)$row['c'];
    }
    if ($chartNextcheck['labels'] === []) {
        $chartNextcheck = ['labels' => ['No data'], 'data' => [0]];
    }

    $recent = [];

    foreach ($pdo->query("
        SELECT l.asset_id, l.serial_num, CONCAT(COALESCE(l.brand,''),' ',COALESCE(l.model,'')) AS device,
               s.name AS status_name, l.created_at, 'Laptop registration' AS event_type
        FROM laptop l
        JOIN status s ON s.status_id = l.status_id
        ORDER BY l.created_at DESC
        LIMIT 5
    ")->fetchAll() as $r) { $recent[] = $r; }

    foreach ($pdo->query("
        SELECT n.asset_id, n.serial_num, CONCAT(COALESCE(n.brand,''),' ',COALESCE(n.model,'')) AS device,
               s.name AS status_name, n.created_at, 'Network registration' AS event_type
        FROM network n
        JOIN status s ON s.status_id = n.status_id
        ORDER BY n.created_at DESC
        LIMIT 5
    ")->fetchAll() as $r) { $recent[] = $r; }

    foreach ($pdo->query("
        SELECT a.asset_id, a.serial_num, CONCAT(COALESCE(a.brand,''),' ',COALESCE(a.model,'')) AS device,
               s.name AS status_name, a.created_at, 'AV registration' AS event_type
        FROM av a
        JOIN status s ON s.status_id = a.status_id
        ORDER BY a.created_at DESC
        LIMIT 5
    ")->fetchAll() as $r) { $recent[] = $r; }

    foreach ($pdo->query("
        SELECT h.asset_id, l.serial_num, CONCAT(COALESCE(l.brand,''),' ',COALESCE(l.model,'')) AS device,
               h.staff_id AS status_name, h.created_at, 'Handover' AS event_type
        FROM handover h
        JOIN laptop l ON l.asset_id = h.asset_id
        ORDER BY h.created_at DESC
        LIMIT 5
    ")->fetchAll() as $r) { $recent[] = $r; }

    usort($recent, static function ($a, $b): int {
        return strcmp($b['created_at'], $a['created_at']);
    });
    $recent = array_slice($recent, 0, 8);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
    $counts = [
        'users'=>0,'techs'=>0,'admins'=>0,'nextcheck_users'=>0,
        'laptops'=>0,'networks'=>0,'av'=>0,'assets_total'=>0,
        'handovers'=>0,'warranties'=>0,
        'deploy'=>0,'maintenance'=>0,'faulty'=>0,'disposed'=>0,'disposal_forms'=>0,
    ];
    $recent = [];
    $chartAssetStatus = ['labels' => ['No data'], 'data' => [0]];
    $chartUsersByRole = ['labels' => ['Technician', 'Admin', 'NextCheck'], 'data' => [0, 0, 0]];
    $chartNextcheck = ['labels' => ['No data'], 'data' => [0]];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --bg: #f0f4ff;
            --card-bg: #ffffff;
            --sidebar-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-border: #e2e8f0;
            --glass-panel: #f8faff;
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
        .blob { position: fixed; border-radius: 50%; filter: blur(90px); pointer-events: none; z-index: 0; }
        .blob-1 { width: 520px; height: 520px; background: rgba(37,99,235,0.06); top: -140px; right: -120px; }
        .blob-2 { width: 420px; height: 420px; background: rgba(124,58,237,0.05); bottom: -90px; left: -90px; }

        .sidebar {
            width: 280px; min-height: 100vh; background: var(--sidebar-bg);
            border-right: 1px solid var(--card-border);
            display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; bottom: 0;
            z-index: 100; box-shadow: 2px 0 20px rgba(15,23,42,0.06);
        }
        .sidebar-logo { padding: 1.5rem 1.75rem 1.25rem; border-bottom: 1px solid var(--card-border); text-align: center; }
        .sidebar-logo img { height: 42px; object-fit: contain; }
        .nav-menu { flex: 1; padding: 1.25rem 1rem; display: flex; flex-direction: column; gap: 0.25rem; overflow-y: auto; }
        .nav-item {
            display: flex; align-items: center; gap: 1rem;
            padding: 0.75rem 1.25rem; border-radius: 12px;
            color: var(--text-muted); text-decoration: none;
            font-weight: 600; font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .nav-item:hover { background: rgba(37,99,235,0.06); color: var(--primary); }
        .nav-item.active { background: rgba(37,99,235,0.1); color: var(--primary); }
        .nav-item i { font-size: 1.25rem; }
        .user-profile {
            padding: 1.25rem 1.75rem; border-top: 1px solid var(--card-border);
            display: flex; align-items: center; gap: 0.75rem;
            cursor: pointer; margin-top: auto;
        }
        .user-profile:hover { background: rgba(37,99,235,0.04); }
        .avatar {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif; font-weight: 800; color: #fff; font-size: 1rem;
        }
        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-size: 0.9rem; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role { font-size: 0.75rem; color: var(--primary); margin-top: 0.2rem; text-transform: uppercase; font-weight: 800; }

        .main-content {
            margin-left: 280px; flex: 1;
            padding: 2.5rem 3.5rem 5rem;
            max-width: calc(100vw - 280px);
            position: relative; z-index: 1;
        }
        .page-header {
            display: flex; justify-content: space-between; align-items: flex-end;
            margin-bottom: 1.75rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--card-border);
        }
        .page-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.1rem;
            font-weight: 900;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); margin-top: 0.35rem; }
        .dash-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            padding: 0.75rem 1.25rem;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.04);
        }
        .dash-topbar img { height: 42px; width: auto; object-fit: contain; }
        .dash-topbar .brand-mid {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--text-muted);
            letter-spacing: 0.02em;
        }

        .greeting-block {
            background: linear-gradient(135deg, rgba(37,99,235,0.12), rgba(124,58,237,0.08));
            border: 1px solid rgba(37,99,235,0.2);
            border-radius: 18px;
            padding: 1.35rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        .greeting-block h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.65rem;
            font-weight: 800;
            color: var(--text-main);
        }
        .greeting-block h1 span { color: var(--primary); }
        .greeting-block p { margin-top: 0.35rem; font-size: 0.9rem; color: var(--text-muted); max-width: 48rem; line-height: 1.5; }

        .alert-db {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #b91c1c;
            padding: 0.85rem 1.1rem;
            border-radius: 12px;
            font-size: 0.9rem;
            margin-bottom: 1.25rem;
            font-weight: 600;
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
        .icon-violet { background: rgba(124,58,237,0.12); color: var(--secondary); border: 1px solid rgba(124,58,237,0.25); }
        .icon-green { background: rgba(16,185,129,0.12); color: var(--success); border: 1px solid rgba(16,185,129,0.25); }
        .icon-amber { background: rgba(245,158,11,0.12); color: var(--warning); border: 1px solid rgba(245,158,11,0.25); }
        .stat-num { font-family:'Outfit',sans-serif; font-size: 1.9rem; font-weight: 900; line-height: 1; }
        .stat-label { margin-top: 0.25rem; font-size: 0.78rem; color: var(--text-muted); font-weight: 900; text-transform: uppercase; letter-spacing: 0.7px; }
        .stat-sub { margin-top: 0.35rem; font-size: 0.78rem; color: var(--text-muted); font-weight: 700; }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            overflow: hidden;
        }
        .mid-grid{
            display:grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 1.25rem;
            margin-bottom: 1.75rem;
            align-items: stretch;
        }
        .lower-grid{
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }
        @media (max-width: 1100px){
            .mid-grid, .lower-grid{ grid-template-columns: 1fr; }
        }
        .chart-wrap{ height: 310px; }
        canvas{ max-width:100%; }
        .card-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 900;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.55rem;
            margin-bottom: 1.1rem;
        }
        .card-title i { color: var(--primary); }

        .table-responsive { overflow-x: auto; width: 100%; }
        .data-table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        .data-table th {
            text-align: left;
            padding: 1.1rem 1rem;
            color: var(--text-muted);
            font-weight: 900;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--card-border);
        }
        .data-table td {
            padding: 1.1rem 1rem;
            font-size: 0.95rem;
            border-bottom: 1px dashed var(--card-border);
            color: var(--text-main);
            vertical-align: middle;
        }
        .data-table tbody tr:hover { background: rgba(37,99,235,0.03); }
        .data-table tr:last-child td { border-bottom: none; }
        .muted { color: var(--text-muted); font-weight: 700; font-size: 0.88rem; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace; font-size: 0.88rem; }
        .pill {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 900;
            border: 1px solid rgba(148,163,184,0.35);
            background: rgba(148,163,184,0.12);
            color: #475569;
        }
        .kv-table td:last-child{ text-align:right; font-weight:900; }
        .kv-table td:first-child{ font-weight:800; }
        .kv-note{ color: var(--text-muted); font-size:0.85rem; font-weight:700; margin-top:0.6rem; line-height:1.45; }

        @media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
        }
    </style>
</head>
<body>
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <?php include __DIR__ . '/../components/sidebarAdmin.php'; ?>

    <main class="main-content">
        <header class="dash-topbar" aria-label="Institution header">
            <span class="brand-mid">Nexcheck Inventory Management System · Admin</span>
            <img src="../public/unikl-official.png" alt="UniKL">
        </header>

        <section class="greeting-block">
            <h1>Hello, <span><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></span></h1>
            <p>Overview of users, assets, NexCheck requests, and recent activity across NIMS.</p>
        </section>

        <?php if ($dbError): ?>
            <div class="alert-db" role="alert">
                <i class="ri-error-warning-line"></i>
                Could not load dashboard data. <?= htmlspecialchars($dbError) ?>
            </div>
        <?php endif; ?>

        <section class="stats-grid" aria-label="Admin overview stats">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="ri-user-3-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$counts['users'] ?></div>
                    <div class="stat-label">Total users</div>
                    <div class="stat-sub"><?= (int)$counts['techs'] ?> tech • <?= (int)$counts['admins'] ?> admin • <?= (int)$counts['nextcheck_users'] ?> nexcheck</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-violet"><i class="ri-macbook-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$counts['laptops'] ?></div>
                    <div class="stat-label">Laptops</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="ri-router-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$counts['networks'] ?></div>
                    <div class="stat-label">Network assets</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-amber"><i class="ri-film-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$counts['av'] ?></div>
                    <div class="stat-label">Total AV</div>
                </div>
            </div>
        </section>

        <section class="mid-grid" aria-label="Admin dashboard middle panels">
            <div class="glass-card">
                <div class="card-title"><i class="ri-table-2"></i> Items list</div>
                <div class="table-responsive">
                    <table class="data-table kv-table">
                        <thead>
                            <tr>
                                <th>Items</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Total assets</td><td><?= (int)$counts['assets_total'] ?></td></tr>
                            <tr><td>Handover</td><td><?= (int)$counts['handovers'] ?></td></tr>
                            <tr><td>Deploy</td><td><?= (int)$counts['deploy'] ?></td></tr>
                            <tr><td>Faulty</td><td><?= (int)$counts['faulty'] ?></td></tr>
                            <tr><td>Maintenance</td><td><?= (int)$counts['maintenance'] ?></td></tr>
                            <tr><td>Disposal (forms)</td><td><?= (int)$counts['disposal_forms'] ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="kv-note">This summary combines Laptop, Network, and AV statuses based on the system status list.</div>
            </div>
            <div class="glass-card">
                <div class="card-title"><i class="ri-pie-chart-2-line"></i> Asset status</div>
                <div class="chart-wrap"><canvas id="chartAssetStatus"></canvas></div>
            </div>
        </section>

        <section class="lower-grid" aria-label="Admin dashboard graphs">
            <div class="glass-card">
                <div class="card-title"><i class="ri-line-chart-line"></i> NextCheck</div>
                <div class="chart-wrap"><canvas id="chartNextcheck"></canvas></div>
            </div>
            <div class="glass-card">
                <div class="card-title"><i class="ri-bar-chart-grouped-line"></i> Users</div>
                <div class="chart-wrap"><canvas id="chartUsersByRole"></canvas></div>
            </div>
        </section>

        <section class="glass-card" aria-label="Recent activity">
            <div class="card-title"><i class="ri-time-line"></i> Recent activity</div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Device</th>
                            <th>Asset ID</th>
                            <th>Serial</th>
                            <th>Status / Staff</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent)): ?>
                            <tr>
                                <td colspan="6" class="muted" style="text-align:center; padding: 2.5rem;">
                                    <i class="ri-inbox-line" style="font-size:2rem; display:block; margin-bottom:0.5rem; color:#cbd5e1;"></i>
                                    No recent events found.
                                </td>
                            </tr>
                        <?php else: foreach ($recent as $r):
                            $dev = trim((string)($r['device'] ?? '')) ?: '—';
                            $created = !empty($r['created_at']) ? date('d M Y, H:i', strtotime($r['created_at'])) : '—';
                        ?>
                            <tr>
                                <td><span class="pill"><?= htmlspecialchars($r['event_type'] ?? 'Event') ?></span></td>
                                <td><?= htmlspecialchars($dev) ?></td>
                                <td class="mono"><?= htmlspecialchars((string)($r['asset_id'] ?? '—')) ?></td>
                                <td class="mono"><?= htmlspecialchars((string)($r['serial_num'] ?? '—')) ?></td>
                                <td><?= htmlspecialchars((string)($r['status_name'] ?? '—')) ?></td>
                                <td class="muted"><?= htmlspecialchars($created) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <script>
        (function () {
            Chart.defaults.font.family = "'Inter', sans-serif";
            Chart.defaults.color = '#64748b';

            const blue  = 'rgba(37, 99, 235, 0.85)';
            const teal  = 'rgba(14, 165, 233, 0.85)';
            const amber = 'rgba(245, 158, 11, 0.9)';
            const green = 'rgba(16, 185, 129, 0.88)';
            const red   = 'rgba(239, 68, 68, 0.85)';
            const violet= 'rgba(124, 58, 237, 0.82)';

            const assetStatus = <?= json_encode($chartAssetStatus, $chartJsonFlags) ?>;
            const nextcheck = <?= json_encode($chartNextcheck, $chartJsonFlags) ?>;
            const usersByRole = <?= json_encode($chartUsersByRole, $chartJsonFlags) ?>;

            new Chart(document.getElementById('chartAssetStatus'), {
                type: 'doughnut',
                data: {
                    labels: assetStatus.labels,
                    datasets: [{
                        data: assetStatus.data,
                        backgroundColor: [blue, teal, amber, green, violet, red, 'rgba(148,163,184,0.85)'],
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 6
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });

            new Chart(document.getElementById('chartNextcheck'), {
                type: 'line',
                data: {
                    labels: nextcheck.labels,
                    datasets: [{
                        label: 'Requests',
                        data: nextcheck.data,
                        borderColor: violet,
                        backgroundColor: 'rgba(124,58,237,0.12)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });

            new Chart(document.getElementById('chartUsersByRole'), {
                type: 'bar',
                data: {
                    labels: usersByRole.labels,
                    datasets: [{
                        label: 'Users',
                        data: usersByRole.data,
                        backgroundColor: [blue, teal, amber],
                        borderRadius: 12
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        })();
    </script>
</body>
</html>