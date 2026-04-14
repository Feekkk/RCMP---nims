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

try {
    $counts = [
        'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'techs' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 1')->fetchColumn(),
        'admins' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 2')->fetchColumn(),
        'laptops' => (int)$pdo->query('SELECT COUNT(*) FROM laptop')->fetchColumn(),
        'networks' => (int)$pdo->query('SELECT COUNT(*) FROM network')->fetchColumn(),
        'av' => (int)$pdo->query('SELECT COUNT(*) FROM av')->fetchColumn(),
        'handovers' => (int)$pdo->query('SELECT COUNT(*) FROM handover')->fetchColumn(),
        'warranties' => (int)$pdo->query('SELECT COUNT(*) FROM warranty')->fetchColumn(),
    ];
    $counts['assets_total'] = $counts['laptops'] + $counts['networks'] + $counts['av'];

    $chartAssetMix = ['labels' => ['Laptops', 'Network', 'AV'], 'data' => [$counts['laptops'], $counts['networks'], $counts['av']]];
    $chartLaptopStatus = ['labels' => [], 'data' => []];
    $chartNetworkStatus = ['labels' => [], 'data' => []];
    $chartAvStatus = ['labels' => [], 'data' => []];

    foreach ($pdo->query(
        'SELECT s.name, COUNT(l.asset_id) AS c
         FROM laptop l
         JOIN status s ON s.status_id = l.status_id
         GROUP BY s.status_id, s.name
         ORDER BY c DESC, s.name ASC'
    )->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chartLaptopStatus['labels'][] = (string)$row['name'];
        $chartLaptopStatus['data'][] = (int)$row['c'];
    }
    foreach ($pdo->query(
        'SELECT s.name, COUNT(n.asset_id) AS c
         FROM network n
         JOIN status s ON s.status_id = n.status_id
         GROUP BY s.status_id, s.name
         ORDER BY c DESC, s.name ASC'
    )->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chartNetworkStatus['labels'][] = (string)$row['name'];
        $chartNetworkStatus['data'][] = (int)$row['c'];
    }
    foreach ($pdo->query(
        'SELECT s.name, COUNT(a.asset_id) AS c
         FROM av a
         JOIN status s ON s.status_id = a.status_id
         GROUP BY s.status_id, s.name
         ORDER BY c DESC, s.name ASC'
    )->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chartAvStatus['labels'][] = (string)$row['name'];
        $chartAvStatus['data'][] = (int)$row['c'];
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
    $counts = ['users'=>0,'techs'=>0,'admins'=>0,'laptops'=>0,'networks'=>0,'av'=>0,'assets_total'=>0,'handovers'=>0,'warranties'=>0];
    $recent = [];
    $chartAssetMix = ['labels' => ['Laptops', 'Network', 'AV'], 'data' => [0, 0, 0]];
    $chartLaptopStatus = ['labels' => ['No data'], 'data' => [0]];
    $chartNetworkStatus = ['labels' => ['No data'], 'data' => [0]];
    $chartAvStatus = ['labels' => ['No data'], 'data' => [0]];
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
        .badge-pill {
            display: inline-flex; align-items: center; gap: 0.45rem;
            padding: 0.5rem 0.9rem;
            border-radius: 999px;
            font-weight: 800;
            font-size: 0.85rem;
            color: var(--primary);
            background: rgba(37,99,235,0.08);
            border: 1px solid rgba(37,99,235,0.18);
        }

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
        .charts-grid{
            display:grid;
            grid-template-columns: 1.1fr 1fr;
            gap:1.25rem;
            margin-bottom:1.75rem;
        }
        @media (max-width: 1100px){ .charts-grid{ grid-template-columns: 1fr; } }
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
        <?php if ($dbError): ?>
            <div class="alert-db" role="alert">
                <i class="ri-error-warning-line"></i>
                Could not load dashboard data. <?= htmlspecialchars($dbError) ?>
            </div>
        <?php endif; ?>

        <header class="page-header">
            <div class="page-title">
                <h1><i class="ri-shield-user-line"></i> Admin Dashboard</h1>
                <p>Overview of users and inventory across NIMS.</p>
            </div>
            <div class="badge-pill"><i class="ri-user-3-line"></i> <?= htmlspecialchars($_SESSION['staff_id']) ?></div>
        </header>

        <section class="stats-grid" aria-label="Admin overview stats">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="ri-user-3-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$counts['users'] ?></div>
                    <div class="stat-label">Total users</div>
                    <div class="stat-sub"><?= (int)$counts['techs'] ?> tech • <?= (int)$counts['admins'] ?> admin</div>
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
                <div class="stat-icon icon-amber"><i class="ri-exchange-line"></i></div>
                <div>
                    <div class="stat-num"><?= (int)$counts['handovers'] ?></div>
                    <div class="stat-label">Handovers</div>
                    <div class="stat-sub"><?= (int)$counts['warranties'] ?> warranties</div>
                </div>
            </div>
        </section>

        <section class="charts-grid" aria-label="Asset analytics charts">
            <div class="glass-card">
                <div class="card-title"><i class="ri-pie-chart-2-line"></i> Asset mix</div>
                <div class="chart-wrap"><canvas id="chartAssetMix"></canvas></div>
            </div>
            <div class="glass-card">
                <div class="card-title"><i class="ri-bar-chart-horizontal-line"></i> Laptop by status</div>
                <div class="chart-wrap"><canvas id="chartLaptopStatus"></canvas></div>
            </div>
            <div class="glass-card">
                <div class="card-title"><i class="ri-router-line"></i> Network by status</div>
                <div class="chart-wrap"><canvas id="chartNetworkStatus"></canvas></div>
            </div>
            <div class="glass-card">
                <div class="card-title"><i class="ri-film-line"></i> AV by status</div>
                <div class="chart-wrap"><canvas id="chartAvStatus"></canvas></div>
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

            const assetMix = <?= json_encode($chartAssetMix, $chartJsonFlags) ?>;
            const laptopSt = <?= json_encode($chartLaptopStatus, $chartJsonFlags) ?>;
            const networkSt= <?= json_encode($chartNetworkStatus, $chartJsonFlags) ?>;
            const avSt     = <?= json_encode($chartAvStatus, $chartJsonFlags) ?>;

            new Chart(document.getElementById('chartAssetMix'), {
                type: 'doughnut',
                data: {
                    labels: assetMix.labels,
                    datasets: [{
                        data: assetMix.data,
                        backgroundColor: [blue, teal, amber],
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 6
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });

            function barChart(elId, ds, color) {
                const el = document.getElementById(elId);
                if (!el) return;
                new Chart(el, {
                    type: 'bar',
                    data: {
                        labels: ds.labels,
                        datasets: [{ label: 'Count', data: ds.data, backgroundColor: color, borderRadius: 10 }]
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
            }

            barChart('chartLaptopStatus', laptopSt, blue);
            barChart('chartNetworkStatus', networkSt, violet);
            barChart('chartAvStatus', avSt, amber);
        })();
    </script>
</body>
</html>