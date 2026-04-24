<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 2) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$pdo = db();
$dbError = '';

$fromRaw = (string)($_GET['from'] ?? '');
$toRaw = (string)($_GET['to'] ?? '');

$from = $fromRaw !== '' ? $fromRaw : date('Y-m-d', strtotime('-30 days'));
$to = $toRaw !== '' ? $toRaw : date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

if ($from > $to) {
    $tmp = $from;
    $from = $to;
    $to = $tmp;
}

$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';

function report_url(array $overrides = []): string
{
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
    }
    $s = http_build_query($q);
    return 'report.php' . ($s !== '' ? '?' . $s : '');
}

function report_csv(string $name): void
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$summary = [
    'assets_added' => 0,
    'handovers' => 0,
    'warranties' => 0,
    'nextcheck_requests' => 0,
];

$topBrands = [];
$recentEvents = [];

try {
    $assetsAdded = $pdo->prepare("
        SELECT SUM(c) FROM (
            SELECT COUNT(*) AS c FROM laptop WHERE created_at BETWEEN ? AND ?
            UNION ALL SELECT COUNT(*) FROM network WHERE created_at BETWEEN ? AND ?
            UNION ALL SELECT COUNT(*) FROM av WHERE created_at BETWEEN ? AND ?
        ) x
    ");
    $assetsAdded->execute([$fromDt, $toDt, $fromDt, $toDt, $fromDt, $toDt]);
    $summary['assets_added'] = (int)$assetsAdded->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM handover WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$fromDt, $toDt]);
    $summary['handovers'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM warranty WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$fromDt, $toDt]);
    $summary['warranties'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM nexcheck_request WHERE created_at BETWEEN ? AND ? AND rejected_at IS NULL");
    $stmt->execute([$fromDt, $toDt]);
    $summary['nextcheck_requests'] = (int)$stmt->fetchColumn();

    $brandSql = "
        SELECT brand, SUM(c) AS c FROM (
            SELECT COALESCE(NULLIF(TRIM(brand),''),'(Unknown)') AS brand, COUNT(*) AS c
            FROM laptop
            WHERE created_at BETWEEN ? AND ?
            GROUP BY brand
            UNION ALL
            SELECT COALESCE(NULLIF(TRIM(brand),''),'(Unknown)') AS brand, COUNT(*) AS c
            FROM network
            WHERE created_at BETWEEN ? AND ?
            GROUP BY brand
            UNION ALL
            SELECT COALESCE(NULLIF(TRIM(brand),''),'(Unknown)') AS brand, COUNT(*) AS c
            FROM av
            WHERE created_at BETWEEN ? AND ?
            GROUP BY brand
        ) u
        GROUP BY brand
        ORDER BY c DESC, brand ASC
        LIMIT 8
    ";
    $stmt = $pdo->prepare($brandSql);
    $stmt->execute([$fromDt, $toDt, $fromDt, $toDt, $fromDt, $toDt]);
    $topBrands = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recentSql = "
        SELECT kind, title, ts FROM (
            SELECT 'asset' AS kind, CONCAT('Laptop #', asset_id, ' · ', COALESCE(brand,''), ' ', COALESCE(model,'')) AS title, created_at AS ts
            FROM laptop
            UNION ALL
            SELECT 'asset', CONCAT('Network #', asset_id, ' · ', COALESCE(brand,''), ' ', COALESCE(model,'')), created_at
            FROM network
            UNION ALL
            SELECT 'asset', CONCAT('AV #', asset_id, ' · ', COALESCE(brand,''), ' ', COALESCE(model,'')), created_at
            FROM av
            UNION ALL
            SELECT 'handover', CONCAT('Handover #', handover_id, ' · laptop #', asset_id), created_at
            FROM handover
            UNION ALL
            SELECT 'warranty', CONCAT('Warranty (', asset_type, ') #', asset_id), created_at
            FROM warranty
            UNION ALL
            SELECT 'nextcheck', CONCAT('NexCheck request #', nexcheck_id), created_at
            FROM nexcheck_request
        ) z
        WHERE ts BETWEEN ? AND ?
        ORDER BY ts DESC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($recentSql);
    $stmt->execute([$fromDt, $toDt]);
    $recentEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$export = (string)($_GET['export'] ?? '');
if ($export !== '' && $dbError === '') {
    if ($export === 'assets') {
        report_csv('nims_assets_' . $from . '_to_' . $to . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['asset_class', 'asset_id', 'serial_num', 'brand', 'model', 'created_at']);

        $sql = "
            SELECT 'laptop' AS asset_class, asset_id, serial_num, brand, model, created_at FROM laptop WHERE created_at BETWEEN ? AND ?
            UNION ALL
            SELECT 'network', asset_id, serial_num, brand, model, created_at FROM network WHERE created_at BETWEEN ? AND ?
            UNION ALL
            SELECT 'av', asset_id, serial_num, brand, model, created_at FROM av WHERE created_at BETWEEN ? AND ?
            ORDER BY created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fromDt, $toDt, $fromDt, $toDt, $fromDt, $toDt]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                (string)$r['asset_class'],
                (string)$r['asset_id'],
                (string)($r['serial_num'] ?? ''),
                (string)($r['brand'] ?? ''),
                (string)($r['model'] ?? ''),
                (string)($r['created_at'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }

    if ($export === 'nextcheck') {
        report_csv('nims_nexcheck_' . $from . '_to_' . $to . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['nexcheck_id', 'requested_by', 'borrow_date', 'return_date', 'usage_location', 'created_at', 'rejected_at']);
        $stmt = $pdo->prepare("
            SELECT nexcheck_id, requested_by, borrow_date, return_date, usage_location, created_at, rejected_at
            FROM nexcheck_request
            WHERE created_at BETWEEN ? AND ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$fromDt, $toDt]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                (string)$r['nexcheck_id'],
                (string)$r['requested_by'],
                (string)($r['borrow_date'] ?? ''),
                (string)($r['return_date'] ?? ''),
                (string)($r['usage_location'] ?? ''),
                (string)($r['created_at'] ?? ''),
                (string)($r['rejected_at'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — Admin — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root{
            --primary:#2563eb;
            --secondary:#7c3aed;
            --success:#10b981;
            --warning:#f59e0b;
            --danger:#ef4444;
            --bg:#f0f4ff;
            --card-bg:#ffffff;
            --sidebar-bg:#ffffff;
            --text-main:#0f172a;
            --text-muted:#64748b;
            --card-border:#e2e8f0;
            --glass-panel:#f8faff;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text-main);display:flex;min-height:100vh;overflow-x:hidden;}
        .blob{position:fixed;border-radius:50%;filter:blur(90px);pointer-events:none;z-index:0;}
        .blob-1{width:520px;height:520px;background:rgba(37,99,235,0.06);top:-140px;right:-120px;}
        .blob-2{width:420px;height:420px;background:rgba(124,58,237,0.05);bottom:-90px;left:-90px;}
        .main-content{margin-left:280px;flex:1;padding:2.5rem 3.5rem 5rem;max-width:calc(100vw - 280px);position:relative;z-index:1;}
        .page-header{display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;padding-bottom:1.25rem;border-bottom:1px solid var(--card-border);}
        .page-title h1{font-family:'Outfit',sans-serif;font-size:2.1rem;font-weight:900;letter-spacing:-0.5px;display:flex;align-items:center;gap:0.75rem;}
        .page-title h1 i{color:var(--primary);}
        .page-title p{color:var(--text-muted);margin-top:0.35rem;max-width:52rem;line-height:1.45;}
        .alert-db{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#b91c1c;padding:0.85rem 1.1rem;border-radius:12px;font-size:0.9rem;margin-bottom:1.25rem;font-weight:600;}
        .card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:18px;box-shadow:0 2px 12px rgba(15,23,42,0.06);overflow:hidden;}
        .card-hd{padding:1.15rem 1.35rem;border-bottom:1px solid var(--card-border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;}
        .card-hd .t{font-family:'Outfit',sans-serif;font-weight:900;display:flex;align-items:center;gap:0.6rem;}
        .card-hd .t i{color:var(--primary);}
        .card-bd{padding:1.25rem 1.35rem;}
        .filters{display:flex;gap:0.75rem;align-items:end;flex-wrap:wrap;}
        label{font-size:0.78rem;font-weight:900;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);display:block;margin-bottom:0.35rem;}
        .input{height:42px;border-radius:12px;border:1px solid var(--card-border);background:var(--glass-panel);padding:0 0.9rem;font-weight:700;color:var(--text-main);min-width:220px;}
        .btn{height:42px;border-radius:12px;padding:0 1.05rem;border:1px solid var(--card-border);background:var(--glass-panel);color:var(--text-muted);font-weight:900;text-decoration:none;display:inline-flex;align-items:center;gap:0.45rem;cursor:pointer;}
        .btn:hover{border-color:rgba(37,99,235,0.25);background:rgba(37,99,235,0.06);color:var(--primary);}
        .btn-primary{border:none;background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;box-shadow:0 10px 22px rgba(37,99,235,0.25);}
        .btn-primary:hover{filter:brightness(1.06);color:#fff;background:linear-gradient(135deg,var(--primary),var(--secondary));}
        .grid{display:grid;grid-template-columns:repeat(4, minmax(0,1fr));gap:1.1rem;margin-top:1.1rem;}
        .stat{padding:1.1rem 1.15rem;border-radius:16px;border:1px solid var(--card-border);background:var(--card-bg);box-shadow:0 2px 12px rgba(15,23,42,0.06);display:flex;align-items:center;gap:0.85rem;}
        .ico{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;border:1px solid rgba(148,163,184,0.35);background:rgba(148,163,184,0.12);color:#475569;}
        .ico.blue{background:rgba(37,99,235,0.12);border-color:rgba(37,99,235,0.25);color:var(--primary);}
        .ico.green{background:rgba(16,185,129,0.12);border-color:rgba(16,185,129,0.25);color:var(--success);}
        .ico.amber{background:rgba(245,158,11,0.12);border-color:rgba(245,158,11,0.25);color:var(--warning);}
        .ico.violet{background:rgba(124,58,237,0.12);border-color:rgba(124,58,237,0.25);color:var(--secondary);}
        .num{font-family:'Outfit',sans-serif;font-weight:900;font-size:1.75rem;line-height:1;}
        .lbl{margin-top:0.2rem;font-size:0.78rem;color:var(--text-muted);font-weight:900;text-transform:uppercase;letter-spacing:0.06em;}
        .two{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;margin-top:1.1rem;}
        .list{list-style:none;}
        .list li{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:0.75rem 0;border-bottom:1px solid rgba(226,232,240,0.9);}
        .list li:last-child{border-bottom:none;}
        .muted{color:var(--text-muted);font-weight:700;font-size:0.9rem;}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono',monospace;}
        .evt{display:flex;flex-direction:column;gap:0.15rem;}
        .evt .k{font-weight:900;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);}
        .evt .t{font-weight:800;}
        @media (max-width:1100px){.grid{grid-template-columns:repeat(2, minmax(0,1fr));}.two{grid-template-columns:1fr;}}
        @media (max-width:900px){.sidebar{transform:translateX(-100%);width:260px;}.main-content{margin-left:0;max-width:100vw;padding:1.5rem;}}
    </style>
</head>
<body>
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <?php include __DIR__ . '/../components/sidebarAdmin.php'; ?>

    <main class="main-content">
        <?php if ($dbError): ?>
            <div class="alert-db" role="alert"><i class="ri-error-warning-line"></i> <?= htmlspecialchars($dbError) ?></div>
        <?php endif; ?>

        <header class="page-header">
            <div class="page-title">
                <h1><i class="ri-file-chart-line"></i> Reports</h1>
                <p>Generate quick summaries and export CSV for assets and NexCheck activity within a date range.</p>
            </div>
        </header>

        <section class="card" aria-label="Report filters">
            <div class="card-hd">
                <div class="t"><i class="ri-filter-2-line"></i> Date range</div>
                <div class="muted mono"><?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></div>
            </div>
            <div class="card-bd">
                <form class="filters" method="get" action="">
                    <div>
                        <label for="from">From</label>
                        <input class="input" id="from" type="date" name="from" value="<?= htmlspecialchars($from) ?>">
                    </div>
                    <div>
                        <label for="to">To</label>
                        <input class="input" id="to" type="date" name="to" value="<?= htmlspecialchars($to) ?>">
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="ri-refresh-line"></i> Apply</button>
                    <a class="btn" href="<?= htmlspecialchars(report_url(['from' => null, 'to' => null, 'export' => null])) ?>"><i class="ri-close-line"></i> Reset</a>
                </form>
            </div>
        </section>

        <section class="grid" aria-label="Report summary">
            <div class="stat">
                <div class="ico blue"><i class="ri-archive-line"></i></div>
                <div>
                    <div class="num"><?= (int)$summary['assets_added'] ?></div>
                    <div class="lbl">Assets added</div>
                </div>
            </div>
            <div class="stat">
                <div class="ico green"><i class="ri-hand-coin-line"></i></div>
                <div>
                    <div class="num"><?= (int)$summary['handovers'] ?></div>
                    <div class="lbl">Handovers</div>
                </div>
            </div>
            <div class="stat">
                <div class="ico amber"><i class="ri-shield-check-line"></i></div>
                <div>
                    <div class="num"><?= (int)$summary['warranties'] ?></div>
                    <div class="lbl">Warranties</div>
                </div>
            </div>
            <div class="stat">
                <div class="ico violet"><i class="ri-calendar-check-line"></i></div>
                <div>
                    <div class="num"><?= (int)$summary['nextcheck_requests'] ?></div>
                    <div class="lbl">NexCheck requests</div>
                </div>
            </div>
        </section>

        <section class="two" aria-label="Report details">
            <div class="card">
                <div class="card-hd">
                    <div class="t"><i class="ri-bar-chart-grouped-line"></i> Top brands (added)</div>
                    <a class="btn" href="<?= htmlspecialchars(report_url(['export' => 'assets'])) ?>"><i class="ri-download-2-line"></i> Export assets CSV</a>
                </div>
                <div class="card-bd">
                    <?php if (empty($topBrands)): ?>
                        <div class="muted">No brand data in this date range.</div>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($topBrands as $b): ?>
                                <li>
                                    <span class="mono"><?= htmlspecialchars((string)($b['brand'] ?? '')) ?></span>
                                    <strong><?= (int)($b['c'] ?? 0) ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-hd">
                    <div class="t"><i class="ri-timer-line"></i> Recent activity</div>
                    <a class="btn" href="<?= htmlspecialchars(report_url(['export' => 'nextcheck'])) ?>"><i class="ri-download-2-line"></i> Export NexCheck CSV</a>
                </div>
                <div class="card-bd">
                    <?php if (empty($recentEvents)): ?>
                        <div class="muted">No events in this date range.</div>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($recentEvents as $e): ?>
                                <li>
                                    <div class="evt">
                                        <div class="k"><?= htmlspecialchars((string)($e['kind'] ?? 'event')) ?></div>
                                        <div class="t"><?= htmlspecialchars((string)($e['title'] ?? '')) ?></div>
                                    </div>
                                    <span class="muted mono"><?= htmlspecialchars((string)($e['ts'] ?? '')) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
