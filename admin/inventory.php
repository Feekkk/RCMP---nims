<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 2) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function admin_inventory_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->execute([$table]);
    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}

function admin_inventory_url(array $overrides = []): string
{
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) {
        if ($v === null || $v === '' || $v === 'all' || $v === 0 || $v === '0') {
            unset($q[$k]);
        }
    }
    $s = http_build_query($q);
    return 'inventory.php' . ($s !== '' ? '?' . $s : '');
}

$pdo = db();
$dbError = '';

$type = strtolower(trim((string)($_GET['type'] ?? 'all')));
if (!in_array($type, ['all', 'laptop', 'network', 'av'], true)) {
    $type = 'all';
}
$statusId = trim((string)($_GET['status_id'] ?? 'all'));
$statusId = $statusId === '' ? 'all' : $statusId;
if ($statusId !== 'all' && !ctype_digit($statusId)) {
    $statusId = 'all';
}
$statusIdInt = $statusId === 'all' ? null : (int)$statusId;

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 80) {
    $q = mb_substr($q, 0, 80);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$hasAv = false;
$statusOptions = [];
$counts = ['laptop' => 0, 'network' => 0, 'av' => 0, 'total' => 0];
$rows = [];
$totalRows = 0;

try {
    $hasAv = admin_inventory_table_exists($pdo, 'av');

    $statusOptions = $pdo->query('SELECT status_id, name FROM status ORDER BY status_id ASC')->fetchAll(PDO::FETCH_ASSOC);

    $counts['laptop'] = (int)$pdo->query('SELECT COUNT(*) FROM laptop')->fetchColumn();
    $counts['network'] = (int)$pdo->query('SELECT COUNT(*) FROM network')->fetchColumn();
    $counts['av'] = $hasAv ? (int)$pdo->query('SELECT COUNT(*) FROM av')->fetchColumn() : 0;
    $counts['total'] = $counts['laptop'] + $counts['network'] + $counts['av'];

    $parts = [];
    $params = [];

    $addPart = static function (
        array &$parts,
        array &$params,
        string $class,
        string $table,
        string $categoryExpr,
        array $searchCols
    ) use ($statusIdInt, $q): void {
        $where = [];
        $p = [];

        if ($statusIdInt !== null) {
            $where[] = 't.status_id = ?';
            $p[] = $statusIdInt;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $or = ['CAST(t.asset_id AS CHAR) LIKE ?'];
            $p[] = $like;
            foreach ($searchCols as $col) {
                $or[] = "COALESCE({$col},'') LIKE ?";
                $p[] = $like;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }

        $whereSql = $where !== [] ? (' AND ' . implode(' AND ', $where)) : '';

        $parts[] = "
            SELECT
                '{$class}' AS asset_class,
                t.asset_id,
                t.serial_num,
                t.brand,
                t.model,
                {$categoryExpr} AS category,
                t.status_id,
                s.name AS status_name,
                t.created_at
            FROM `{$table}` t
            JOIN status s ON s.status_id = t.status_id
            WHERE 1=1{$whereSql}
        ";
        $params = array_merge($params, $p);
    };

    if ($type === 'all' || $type === 'laptop') {
        $addPart($parts, $params, 'laptop', 'laptop', 't.category', ['t.serial_num', 't.brand', 't.model', 't.category']);
    }
    if ($type === 'all' || $type === 'network') {
        $addPart($parts, $params, 'network', 'network', 'NULL', ['t.serial_num', 't.brand', 't.model', 't.mac_address', 't.ip_address']);
    }
    if (($type === 'all' || $type === 'av') && $hasAv) {
        $addPart($parts, $params, 'av', 'av', 't.category', ['t.serial_num', 't.brand', 't.model', 't.category']);
    }

    if ($parts === []) {
        $rows = [];
        $totalRows = 0;
    } else {
        $union = implode("\n            UNION ALL\n", $parts);
        $countSql = "SELECT COUNT(*) FROM ({$union}) x";
        $stmtC = $pdo->prepare($countSql);
        $stmtC->execute($params);
        $totalRows = (int)$stmtC->fetchColumn();

        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        // MySQL native prepared statements reject placeholders in LIMIT/OFFSET in some configs.
        $limitSql = (int)$perPage;
        $offsetSql = (int)$offset;
        $sql = "SELECT * FROM ({$union}) x ORDER BY x.asset_id DESC LIMIT {$limitSql} OFFSET {$offsetSql}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

function admin_inventory_type_label(string $t): string
{
    return match ($t) {
        'laptop' => 'Laptop',
        'network' => 'Network',
        'av' => 'AV',
        default => 'All',
    };
}

function admin_inventory_type_pill(string $t): string
{
    return match ($t) {
        'laptop' => 'type-laptop',
        'network' => 'type-network',
        'av' => 'type-av',
        default => 'type-all',
    };
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory — Admin — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary:#2563eb;
            --secondary:#7c3aed;
            --success:#10b981;
            --danger:#ef4444;
            --warning:#f59e0b;
            --bg:#f0f4ff;
            --card-bg:#ffffff;
            --sidebar-bg:#ffffff;
            --text-main:#0f172a;
            --text-muted:#64748b;
            --card-border:#e2e8f0;
            --glass-panel:#f8faff;
        }
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        body{
            font-family:'Inter',sans-serif;
            background:var(--bg);
            color:var(--text-main);
            display:flex;
            min-height:100vh;
            overflow-x:hidden;
        }

        .sidebar{
            width:280px; min-height:100vh; background:var(--sidebar-bg);
            border-right:1px solid var(--card-border);
            display:flex; flex-direction:column;
            position:fixed; top:0; left:0; bottom:0;
            z-index:100; box-shadow:2px 0 20px rgba(15,23,42,0.06);
        }
        .sidebar-logo{padding:1.5rem 1.75rem 1.25rem; border-bottom:1px solid var(--card-border); text-align:center}
        .sidebar-logo img{height:42px; object-fit:contain}
        .nav-menu{flex:1; padding:1.25rem 1rem; display:flex; flex-direction:column; gap:0.25rem; overflow-y:auto}
        .nav-item{
            display:flex; align-items:center; gap:1rem;
            padding:0.75rem 1.25rem; border-radius:12px;
            color:var(--text-muted); text-decoration:none;
            font-weight:600; font-size:0.95rem;
            transition:all .2s ease;
        }
        .nav-item:hover{background:rgba(37,99,235,0.06); color:var(--primary)}
        .nav-item.active{background:rgba(37,99,235,0.10); color:var(--primary)}
        .nav-item i{font-size:1.25rem}
        .user-profile{
            padding:1.25rem 1.75rem; border-top:1px solid var(--card-border);
            display:flex; align-items:center; gap:0.75rem;
            cursor:pointer; margin-top:auto;
        }
        .user-profile:hover{background:rgba(37,99,235,0.04)}
        .avatar{
            width:38px; height:38px; border-radius:10px;
            background:linear-gradient(135deg, var(--primary), var(--secondary));
            display:flex; align-items:center; justify-content:center;
            font-family:'Outfit',sans-serif; font-weight:800; color:#fff; font-size:1rem;
        }
        .user-info{flex:1; overflow:hidden}
        .user-name{font-size:0.9rem; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
        .user-role{font-size:0.75rem; color:var(--primary); margin-top:0.2rem; text-transform:uppercase; font-weight:800}
        .sidebar-copyright{border-top:1px solid var(--card-border)}
        .main-content{
            margin-left:280px;
            flex:1;
            padding:2rem 2.5rem 2.75rem;
            max-width:calc(100vw - 280px);
            position:relative;
            z-index:1;
        }
        @media (max-width: 900px){
            .sidebar{transform:translateX(-100%); width:260px}
            .main-content{margin-left:0;max-width:100vw;padding:1.5rem; }
        }

        .page-header{
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap:1rem;
            flex-wrap:wrap;
            padding-bottom:1rem;
            border-bottom:1px solid var(--card-border);
            margin-bottom:1.15rem;
        }
        .title h1{
            font-family:'Outfit',sans-serif;
            font-size:1.85rem;
            letter-spacing:-0.4px;
            display:flex;
            align-items:center;
            gap:0.7rem;
        }
        .title h1 i{color:var(--secondary)}
        .title p{color:var(--text-muted);margin-top:0.35rem;max-width:52rem;line-height:1.45}

        .cards{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:0.85rem;
            margin:1rem 0 1.15rem;
        }
        @media (max-width: 1100px){ .cards{grid-template-columns:repeat(2,minmax(0,1fr))} }
        @media (max-width: 640px){ .cards{grid-template-columns:1fr} }
        .kpi{
            background:var(--card-bg);
            border:1px solid var(--card-border);
            border-radius:18px;
            box-shadow:0 10px 25px rgba(15,23,42,0.06);
            padding:1.05rem 1.1rem;
            display:flex;
            gap:0.85rem;
            align-items:center;
        }
        .kpi-ic{
            width:44px;height:44px;border-radius:14px;
            display:flex;align-items:center;justify-content:center;
            border:1px solid rgba(226,232,240,0.9);
            background:var(--glass-panel);
            flex-shrink:0;
            font-size:1.22rem;
            color:var(--primary);
        }
        .kpi--all .kpi-ic{color:var(--secondary)}
        .kpi--laptop .kpi-ic{color:var(--primary)}
        .kpi--network .kpi-ic{color:#059669}
        .kpi--av .kpi-ic{color:var(--warning)}
        .kpi-txt{min-width:0}
        .kpi-num{font-family:'Outfit',sans-serif;font-size:1.6rem;font-weight:900;line-height:1}
        .kpi-lbl{color:var(--text-muted);font-size:0.82rem;font-weight:700;margin-top:0.15rem}

        .card{
            background:var(--card-bg);
            border:1px solid var(--card-border);
            border-radius:18px;
            box-shadow:0 10px 25px rgba(15,23,42,0.06);
            overflow:hidden;
        }
        .card-hd{
            padding:1rem 1.25rem;
            border-bottom:1px solid var(--card-border);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:1rem;
            flex-wrap:wrap;
        }
        .card-hd h2{
            font-family:'Outfit',sans-serif;
            font-size:1.05rem;
            font-weight:900;
            display:flex;
            align-items:center;
            gap:0.6rem;
        }
        .card-hd h2 i{color:var(--primary)}
        .card-bd{padding:1.1rem 1.25rem}

        .filters{
            display:flex;
            gap:0.65rem;
            flex-wrap:wrap;
            align-items:center;
        }
        .filters .input{flex:0 0 auto}
        .filters .input[name="q"]{flex:1 1 360px; min-width:260px}
        .input{
            background:var(--glass-panel);
            border:1px solid var(--card-border);
            border-radius:14px;
            padding:0.72rem 0.9rem;
            outline:none;
            font-family:inherit;
            font-size:0.9rem;
            min-width:220px;
        }
        .input:focus{
            background:#fff;
            border-color:rgba(37,99,235,0.35);
            box-shadow:0 0 0 4px rgba(37,99,235,0.10);
        }
        .select{
            min-width:160px;
            appearance:none;
            padding-right:2.15rem;
            background-image:linear-gradient(45deg, transparent 50%, #64748b 50%),linear-gradient(135deg, #64748b 50%, transparent 50%),linear-gradient(to right, transparent, transparent);
            background-position:calc(100% - 18px) calc(1em + 2px), calc(100% - 13px) calc(1em + 2px), 100% 0;
            background-size:5px 5px, 5px 5px, 2.5em 2.5em;
            background-repeat:no-repeat;
        }
        .btn{
            border:none;
            border-radius:14px;
            padding:0.72rem 0.95rem;
            font-weight:900;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:0.55rem;
            transition:all .2s ease;
            user-select:none;
            text-decoration:none;
            font-family:inherit;
            font-size:0.88rem;
            white-space:nowrap;
        }
        .btn-primary{
            background:linear-gradient(135deg, var(--primary), #3b82f6);
            color:#fff;
            box-shadow:0 12px 24px rgba(37,99,235,0.18);
        }
        .btn-primary:hover{filter:brightness(0.98)}
        .btn-ghost{background:transparent;border:1px solid var(--card-border);color:var(--text-muted)}
        .btn-ghost:hover{color:var(--primary);border-color:rgba(37,99,235,0.25);background:rgba(37,99,235,0.04)}

        .pill{
            font-size:0.72rem;
            font-weight:900;
            letter-spacing:0.5px;
            text-transform:uppercase;
            padding:0.25rem 0.6rem;
            border-radius:999px;
            border:1px solid transparent;
            white-space:nowrap;
        }
        .pill.type-laptop{background:rgba(37,99,235,0.10);color:var(--primary);border-color:rgba(37,99,235,0.22)}
        .pill.type-av{background:rgba(245,158,11,0.12);color:#92400e;border-color:rgba(245,158,11,0.28)}
        .pill.type-network{background:rgba(5,150,105,0.12);color:#047857;border-color:rgba(5,150,105,0.25)}
        .pill.type-all{background:rgba(124,58,237,0.10);color:var(--secondary);border-color:rgba(124,58,237,0.22)}

        .table-wrap{overflow:auto;border-radius:14px;border:1px solid var(--card-border)}
        table{width:100%;border-collapse:separate;border-spacing:0;min-width:880px;background:#fff}
        thead th{
            text-align:left;
            font-size:0.7rem;
            letter-spacing:0.08em;
            text-transform:uppercase;
            color:var(--text-muted);
            background:var(--glass-panel);
            padding:0.75rem 0.85rem;
            border-bottom:1px solid var(--card-border);
            position:sticky; top:0; z-index:2;
        }
        tbody td{
            padding:0.78rem 0.85rem;
            border-bottom:1px solid rgba(226,232,240,0.8);
            vertical-align:top;
            font-size:0.9rem;
        }
        tbody tr:hover{background:rgba(37,99,235,0.03)}
        .cell-main{font-weight:900}
        .muted{color:var(--text-muted)}

        .banner{
            display:flex;
            align-items:flex-start;
            gap:0.65rem;
            padding:0.9rem 1.1rem;
            border-radius:14px;
            margin:0 0 1rem;
            font-size:0.9rem;
            line-height:1.45;
            font-weight:600;
            background:rgba(239,68,68,0.08);
            border:1px solid rgba(239,68,68,0.22);
            color:#b91c1c;
        }
        .banner i{font-size:1.15rem;flex-shrink:0;margin-top:0.05rem}

        .pager{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:0.75rem;
            flex-wrap:wrap;
            margin-top:0.95rem;
        }
        .pager .info{color:var(--text-muted);font-size:0.85rem;font-weight:700}
        .pager .btn{padding:0.6rem 0.8rem;border-radius:12px}
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarAdmin.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div class="title">
            <h1><i class="ri-archive-drawer-line"></i> Inventory</h1>
            <p>Unified view of <strong>laptop</strong>, <strong>network</strong>, and <strong>AV</strong> assets. Use filters to quickly find devices by ID, serial, model, category, or status.</p>
        </div>
    </header>

    <?php if ($dbError !== ''): ?>
        <div class="banner"><i class="ri-error-warning-line"></i><div><?= htmlspecialchars($dbError) ?></div></div>
    <?php endif; ?>

    <section class="cards" aria-label="Inventory totals">
        <div class="kpi kpi--all">
            <div class="kpi-ic"><i class="ri-stack-line"></i></div>
            <div class="kpi-txt">
                <div class="kpi-num"><?= (int)$counts['total'] ?></div>
                <div class="kpi-lbl">All assets</div>
            </div>
        </div>
        <div class="kpi kpi--laptop">
            <div class="kpi-ic"><i class="ri-laptop-line"></i></div>
            <div class="kpi-txt">
                <div class="kpi-num"><?= (int)$counts['laptop'] ?></div>
                <div class="kpi-lbl">Laptops</div>
            </div>
        </div>
        <div class="kpi kpi--network">
            <div class="kpi-ic"><i class="ri-router-line"></i></div>
            <div class="kpi-txt">
                <div class="kpi-num"><?= (int)$counts['network'] ?></div>
                <div class="kpi-lbl">Network</div>
            </div>
        </div>
        <div class="kpi kpi--av">
            <div class="kpi-ic"><i class="ri-film-line"></i></div>
            <div class="kpi-txt">
                <div class="kpi-num"><?= (int)$counts['av'] ?></div>
                <div class="kpi-lbl">AV</div>
            </div>
        </div>
    </section>

    <section class="card" aria-labelledby="inv-title">
        <div class="card-hd">
            <h2 id="inv-title"><i class="ri-search-line"></i> Browse assets</h2>
            <div class="muted" style="font-weight:800;font-size:0.85rem">
                Showing <strong><?= (int)count($rows) ?></strong> of <strong><?= (int)$totalRows ?></strong> · Page <strong><?= (int)$page ?></strong> / <strong><?= (int)$totalPages ?></strong>
            </div>
        </div>
        <div class="card-bd">
            <form class="filters" method="get" action="">
                <input class="input" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search asset id, serial, brand, model, category…" aria-label="Search inventory">
                <select class="input select" name="type" aria-label="Asset type">
                    <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All types</option>
                    <option value="laptop" <?= $type === 'laptop' ? 'selected' : '' ?>>Laptop</option>
                    <option value="network" <?= $type === 'network' ? 'selected' : '' ?>>Network</option>
                    <option value="av" <?= $type === 'av' ? 'selected' : '' ?> <?= !$hasAv ? 'disabled' : '' ?>>AV<?= !$hasAv ? ' (not available)' : '' ?></option>
                </select>
                <select class="input select" name="status_id" aria-label="Status">
                    <option value="all" <?= $statusId === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <?php foreach ($statusOptions as $st): ?>
                        <option value="<?= (int)$st['status_id'] ?>" <?= $statusIdInt === (int)$st['status_id'] ? 'selected' : '' ?>>
                            <?= (int)$st['status_id'] ?> — <?= htmlspecialchars((string)$st['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary" type="submit"><i class="ri-filter-3-line"></i> Apply</button>
                <a class="btn btn-ghost" href="inventory.php"><i class="ri-refresh-line"></i> Reset</a>
            </form>

            <div style="margin-top:0.95rem" class="table-wrap">
                <table aria-label="Inventory list">
                    <thead>
                    <tr>
                        <th style="width:120px">Type</th>
                        <th style="width:130px">Asset ID</th>
                        <th>Device</th>
                        <th style="width:200px">Serial</th>
                        <th style="width:210px">Category</th>
                        <th style="width:220px">Status</th>
                        <th style="width:170px">Created</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="7" class="muted" style="padding:1.2rem 0.9rem">
                                No assets match the current filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r):
                            $cls = (string)($r['asset_class'] ?? 'all');
                            $device = trim((string)($r['brand'] ?? '') . ' ' . (string)($r['model'] ?? ''));
                            $device = $device !== '' ? $device : admin_inventory_type_label($cls);
                            $cat = (string)($r['category'] ?? '');
                            $serial = (string)($r['serial_num'] ?? '');
                            $stName = (string)($r['status_name'] ?? '');
                            $created = (string)($r['created_at'] ?? '');
                        ?>
                            <tr>
                                <td><span class="pill <?= htmlspecialchars(admin_inventory_type_pill($cls)) ?>"><?= htmlspecialchars(admin_inventory_type_label($cls)) ?></span></td>
                                <td><span class="cell-main">#<?= (int)$r['asset_id'] ?></span></td>
                                <td>
                                    <div class="cell-main"><?= htmlspecialchars($device) ?></div>
                                    <div class="muted" style="font-size:0.82rem;margin-top:0.15rem">Model: <?= htmlspecialchars((string)($r['model'] ?? '—')) ?></div>
                                </td>
                                <td><?= htmlspecialchars($serial !== '' ? $serial : '—') ?></td>
                                <td><?= htmlspecialchars($cat !== '' ? $cat : '—') ?></td>
                                <td>
                                    <div class="cell-main"><?= htmlspecialchars($stName !== '' ? $stName : '—') ?></div>
                                    <div class="muted" style="font-size:0.82rem;margin-top:0.15rem">Status ID: <?= (int)($r['status_id'] ?? 0) ?></div>
                                </td>
                                <td class="muted"><?= htmlspecialchars($created !== '' ? $created : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pager" aria-label="Pagination">
                <div class="info">
                    Page <strong><?= (int)$page ?></strong> of <strong><?= (int)$totalPages ?></strong>
                </div>
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
                    <a class="btn btn-ghost" href="<?= htmlspecialchars(admin_inventory_url(['page' => max(1, $page - 1)])) ?>" <?= $page <= 1 ? 'aria-disabled="true" style="opacity:0.45;pointer-events:none"' : '' ?>>
                        <i class="ri-arrow-left-line"></i> Prev
                    </a>
                    <a class="btn btn-ghost" href="<?= htmlspecialchars(admin_inventory_url(['page' => min($totalPages, $page + 1)])) ?>" <?= $page >= $totalPages ? 'aria-disabled="true" style="opacity:0.45;pointer-events:none"' : '' ?>>
                        Next <i class="ri-arrow-right-line"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>
</main>
</body>
</html>

