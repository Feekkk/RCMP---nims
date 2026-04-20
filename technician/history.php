<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)$_SESSION['role_id'] !== 1) {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../config/database.php';

$pdo = db();

const HISTORY_PER_PAGE = 20;

function history_av_device_label(?string $category, ?string $brand, ?string $model): string
{
    $t = trim(implode(' ', array_filter([$category ?? '', $brand ?? '', $model ?? ''], static function ($x): bool {
        return $x !== '';
    })));
    return $t !== '' ? $t : '—';
}

function history_build_query(array $overrides): string
{
    $base = [
        'type' => $overrides['type'] ?? null,
        'date' => $overrides['date'] ?? null,
        'q'    => $overrides['q'] ?? null,
        'page' => $overrides['page'] ?? null,
    ];
    $out = [];
    foreach ($base as $k => $v) {
        if ($v === null || $v === '' || ($v === '1' && $k === 'page')) {
            continue;
        }
        $out[$k] = $v;
    }
    return http_build_query($out);
}

$total_laptops  = (int) $pdo->query('SELECT COUNT(*) FROM laptop')->fetchColumn();
$total_network  = (int) $pdo->query('SELECT COUNT(*) FROM network')->fetchColumn();
$total_av       = (int) $pdo->query('SELECT COUNT(*) FROM av')->fetchColumn();
$total_assets   = $total_laptops + $total_network + $total_av;
$total_handovers = (int) $pdo->query('SELECT COUNT(*) FROM handover')->fetchColumn();
$total_warranty  = (int) $pdo->query('SELECT COUNT(*) FROM warranty')->fetchColumn();

$this_month = (int) $pdo->query('
    SELECT (
        (SELECT COUNT(*) FROM laptop WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()))
        + (SELECT COUNT(*) FROM network WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()))
        + (SELECT COUNT(*) FROM av WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()))
    )
')->fetchColumn();

$filter_type = $_GET['type'] ?? 'all';
$filter_date = $_GET['date'] ?? '';
$search_q    = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$page        = max(1, (int) ($_GET['page'] ?? 1));

$events = [];

if ($filter_type === 'all' || $filter_type === 'register') {
    $sql = 'SELECT l.asset_id, l.serial_num, l.brand, l.model, l.created_at, s.name AS status_name
            FROM laptop l
            JOIN status s ON s.status_id = l.status_id
            ORDER BY l.created_at DESC';
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $events[] = [
            'type'     => 'register',
            'subtype'  => 'laptop',
            'date'     => $r['created_at'],
            'asset_id' => $r['asset_id'],
            'device'   => trim(($r['brand'] ?? '') . ' ' . ($r['model'] ?? '')) ?: 'Unknown device',
            'serial'   => $r['serial_num'] ?? '—',
            'actor'    => '—',
            'dept'     => '—',
            'assign'   => $r['status_name'],
            'remarks'  => null,
        ];
    }

    $sql = 'SELECT n.asset_id, n.serial_num, n.brand, n.model, n.created_at, s.name AS status_name
            FROM network n
            JOIN status s ON s.status_id = n.status_id
            ORDER BY n.created_at DESC';
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $events[] = [
            'type'     => 'register',
            'subtype'  => 'network',
            'date'     => $r['created_at'],
            'asset_id' => $r['asset_id'],
            'device'   => trim(($r['brand'] ?? '') . ' ' . ($r['model'] ?? '')) ?: 'Unknown device',
            'serial'   => $r['serial_num'] ?? '—',
            'actor'    => '—',
            'dept'     => '—',
            'assign'   => $r['status_name'],
            'remarks'  => null,
        ];
    }

    $sql = 'SELECT a.asset_id, a.serial_num, a.brand, a.model, a.category, a.created_at, s.name AS status_name
            FROM av a
            JOIN status s ON s.status_id = a.status_id
            ORDER BY a.created_at DESC';
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $label = history_av_device_label($r['category'] ?? '', $r['brand'] ?? '', $r['model'] ?? '');
        $events[] = [
            'type'     => 'register',
            'subtype'  => 'av',
            'date'     => $r['created_at'],
            'asset_id' => $r['asset_id'],
            'device'   => $label,
            'serial'   => $r['serial_num'] ?? '—',
            'actor'    => '—',
            'dept'     => '—',
            'assign'   => $r['status_name'],
            'remarks'  => null,
        ];
    }
}

if ($filter_type === 'all' || $filter_type === 'handover') {
    $sql = 'SELECT
                h.handover_id, h.asset_id, h.staff_id, h.handover_date, h.handover_remarks,
                h.created_at,
                CONCAT(l.brand, \' \', l.model) AS device, l.serial_num,
                st.full_name AS recipient_name, st.department AS recipient_dept
            FROM handover h
            JOIN laptop l ON l.asset_id = h.asset_id
            LEFT JOIN handover_staff hs ON hs.handover_id = h.handover_id
            LEFT JOIN staff st ON st.employee_no = hs.employee_no
            ORDER BY h.created_at DESC';
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $events[] = [
            'type'     => 'handover',
            'date'     => $r['created_at'],
            'asset_id' => $r['asset_id'],
            'device'   => trim($r['device'] ?? '') ?: 'Unknown device',
            'serial'   => $r['serial_num'] ?? '—',
            'actor'    => $r['recipient_name'] ?? $r['staff_id'],
            'dept'     => $r['recipient_dept'] ?? '—',
            'assign'   => $r['recipient_dept'] ?? '—',
            'remarks'  => $r['handover_remarks'],
        ];
    }
}

if ($filter_type === 'all' || $filter_type === 'warranty') {
    $sql = 'SELECT
                w.warranty_id, w.asset_id, w.asset_type, w.warranty_start_date, w.warranty_end_date,
                w.warranty_remarks, w.created_at,
                CASE w.asset_type
                    WHEN \'laptop\' THEN CONCAT(l.brand, \' \', l.model)
                    WHEN \'network\' THEN CONCAT(n.brand, \' \', n.model)
                    WHEN \'av\' THEN CONCAT(IFNULL(av.category, \'\'), \' \', IFNULL(av.brand, \'\'), \' \', IFNULL(av.model, \'\'))
                END AS device,
                CASE w.asset_type
                    WHEN \'laptop\' THEN l.serial_num
                    WHEN \'network\' THEN n.serial_num
                    WHEN \'av\' THEN av.serial_num
                END AS serial_num
            FROM warranty w
            LEFT JOIN laptop l ON w.asset_type = \'laptop\' AND w.asset_id = l.asset_id
            LEFT JOIN network n ON w.asset_type = \'network\' AND w.asset_id = n.asset_id
            LEFT JOIN av av ON w.asset_type = \'av\' AND w.asset_id = av.asset_id
            ORDER BY w.created_at DESC';
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $device = trim(preg_replace('/\s+/', ' ', (string) ($r['device'] ?? ''))) ?: 'Unknown device';
        $events[] = [
            'type'     => 'warranty',
            'date'     => $r['created_at'],
            'asset_id' => $r['asset_id'],
            'device'   => $device,
            'serial'   => $r['serial_num'] ?? '—',
            'actor'    => strtoupper((string) ($r['asset_type'] ?? '')),
            'dept'     => '—',
            'assign'   => $r['warranty_start_date'] . ' → ' . $r['warranty_end_date'],
            'remarks'  => $r['warranty_remarks'],
        ];
    }
}

if ($filter_type === 'all' || $filter_type === 'repair') {
    $sql = 'SELECT
                r.repair_id, r.asset_id, r.asset_type, r.staff_id, r.repair_date, r.completed_date,
                r.issue_summary, r.repair_remarks, r.created_at,
                CASE r.asset_type
                    WHEN \'laptop\' THEN CONCAT(l.brand, \' \', l.model)
                    WHEN \'network\' THEN CONCAT(n.brand, \' \', n.model)
                    WHEN \'av\' THEN CONCAT(IFNULL(av.category, \'\'), \' \', IFNULL(av.brand, \'\'), \' \', IFNULL(av.model, \'\'))
                END AS device,
                CASE r.asset_type
                    WHEN \'laptop\' THEN l.serial_num
                    WHEN \'network\' THEN n.serial_num
                    WHEN \'av\' THEN av.serial_num
                END AS serial_num
            FROM repair r
            LEFT JOIN laptop l ON r.asset_type = \'laptop\' AND r.asset_id = l.asset_id
            LEFT JOIN network n ON r.asset_type = \'network\' AND r.asset_id = n.asset_id
            LEFT JOIN av av ON r.asset_type = \'av\' AND r.asset_id = av.asset_id
            ORDER BY r.created_at DESC';
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $device = trim(preg_replace('/\s+/', ' ', (string) ($r['device'] ?? ''))) ?: 'Unknown device';
        $events[] = [
            'type'     => 'repair',
            'date'     => $r['created_at'],
            'asset_id' => $r['asset_id'],
            'device'   => $device,
            'serial'   => $r['serial_num'] ?? '—',
            'actor'    => $r['staff_id'],
            'dept'     => '—',
            'assign'   => $r['issue_summary'] ?? '—',
            'remarks'  => $r['repair_remarks'],
        ];
    }
}

if ($filter_date !== '') {
    $events = array_values(array_filter($events, static function ($e) use ($filter_date): bool {
        return substr((string) $e['date'], 0, 7) === $filter_date;
    }));
}

if ($search_q !== '') {
    $q = strtolower($search_q);
    $events = array_values(array_filter($events, static function ($e) use ($q): bool {
        $hay = strtolower(implode(' ', [
            (string) ($e['device'] ?? ''),
            (string) ($e['serial'] ?? ''),
            (string) ($e['asset_id'] ?? ''),
            (string) ($e['actor'] ?? ''),
            (string) ($e['dept'] ?? ''),
            (string) ($e['assign'] ?? ''),
            (string) ($e['remarks'] ?? ''),
        ]));
        return strpos($hay, $q) !== false;
    }));
}

usort($events, static function ($a, $b): int {
    return strcmp((string) $b['date'], (string) $a['date']);
});

$total_events = count($events);
$total_pages  = max(1, (int) ceil($total_events / HISTORY_PER_PAGE));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * HISTORY_PER_PAGE;
$page_events = array_slice($events, $offset, HISTORY_PER_PAGE);

$from_n = $total_events === 0 ? 0 : $offset + 1;
$to_n   = min($offset + HISTORY_PER_PAGE, $total_events);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary:     #2563eb;
            --secondary:   #7c3aed;
            --success:     #10b981;
            --danger:      #ef4444;
            --warning:     #f59e0b;
            --bg:          #f0f4ff;
            --card-bg:     #ffffff;
            --sidebar-bg:  #ffffff;
            --text-main:   #0f172a;
            --text-muted:  #64748b;
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
        }

        .blob { position: fixed; border-radius: 50%; filter: blur(90px); pointer-events: none; z-index: 0; }
        .blob-1 { width: 500px; height: 500px; background: rgba(37,99,235,0.06);  top: -120px; right: -100px; }
        .blob-2 { width: 400px; height: 400px; background: rgba(124,58,237,0.05); bottom: -80px; left: -80px; }

        .main-content {
            margin-left: 280px; flex: 1;
            padding: 2.5rem 3.5rem 5rem;
            max-width: calc(100vw - 280px);
            position: relative; z-index: 1;
        }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--card-border); padding-bottom: 1.5rem;
            animation: fadeInDown 0.5s ease-out;
        }
        .page-title h1 {
            font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700;
            color: var(--text-main); display: flex; align-items: center; gap: 0.75rem;
        }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); font-size: 0.95rem; margin-top: 0.25rem; }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 2rem;
            animation: fadeInUp 0.5s ease-out;
        }
        .stat-card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 18px; padding: 1.4rem 1.5rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.05);
            display: flex; align-items: center; gap: 1rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(15,23,42,0.09); }
        .stat-icon {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; flex-shrink: 0;
        }
        .stat-num { font-family:'Outfit',sans-serif; font-size: 1.8rem; font-weight: 700; color: var(--text-main); line-height: 1; }
        .stat-label { font-size: 0.78rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; }
        .stat-sub { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.35rem; line-height: 1.35; }

        .controls-bar {
            display: flex; align-items: center; gap: 1rem;
            margin-bottom: 1.5rem; flex-wrap: wrap;
            animation: fadeInUp 0.55s ease-out;
        }
        .search-box {
            display: flex; align-items: center; gap: 0.6rem;
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 12px; padding: 0.6rem 1rem;
            flex: 1; min-width: 220px;
            transition: border-color 0.2s;
        }
        .search-box:focus-within { border-color: var(--primary); }
        .search-box i { color: var(--text-muted); font-size: 1.1rem; }
        .search-box input { border: none; background: none; outline: none; font-size: 0.9rem; color: var(--text-main); width: 100%; }
        .filter-group { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .filter-btn {
            padding: 0.55rem 1.1rem; border-radius: 10px; border: 1px solid var(--card-border);
            background: var(--card-bg); color: var(--text-muted);
            font-size: 0.85rem; font-weight: 600; cursor: pointer;
            transition: all 0.2s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem;
        }
        .filter-btn:hover { border-color: var(--primary); color: var(--primary); background: rgba(37,99,235,0.05); }
        .filter-btn.active { background: var(--primary); border-color: var(--primary); color: white; }
        .date-filter {
            padding: 0.55rem 1rem; border-radius: 10px; border: 1px solid var(--card-border);
            background: var(--card-bg); color: var(--text-main);
            font-size: 0.85rem; font-family: 'Inter', sans-serif;
            outline: none; cursor: pointer;
        }
        .date-filter:focus { border-color: var(--primary); }
        .btn-search {
            padding: 0.55rem 1rem; border-radius: 10px; border: none;
            background: var(--primary); color: white; font-weight: 600; font-size: 0.85rem;
            cursor: pointer;
        }
        .btn-search:hover { filter: brightness(1.08); }

        .glass-card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 20px; overflow: hidden;
            box-shadow: 0 2px 16px rgba(15,23,42,0.06);
            animation: fadeInUp 0.6s ease-out;
        }
        .table-responsive { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        .data-table thead { background: var(--glass-panel); }
        .data-table th {
            padding: 1rem 1.25rem; text-align: left;
            font-size: 0.75rem; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.6px;
            border-bottom: 1px solid var(--card-border); white-space: nowrap;
        }
        .data-table td {
            padding: 1rem 1.25rem; border-bottom: 1px solid rgba(226,232,240,0.6);
            color: var(--text-main); vertical-align: middle;
        }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover { background: rgba(37,99,235,0.02); }

        .event-badge {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.3rem 0.8rem; border-radius: 20px;
            font-size: 0.76rem; font-weight: 700; white-space: nowrap;
        }
        .event-register { background: rgba(37,99,235,0.1);  color: var(--primary); }
        .event-handover { background: rgba(16,185,129,0.1); color: var(--success); }
        .event-warranty { background: rgba(245,158,11,0.1); color: var(--warning); }
        .event-repair { background: rgba(124,58,237,0.12); color: #6d28d9; }

        .event-dot {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
        }
        .dot-register { background: rgba(37,99,235,0.1);  color: var(--primary); }
        .dot-handover { background: rgba(16,185,129,0.1); color: var(--success); }
        .dot-warranty { background: rgba(245,158,11,0.1); color: var(--warning); }
        .dot-repair { background: rgba(124,58,237,0.12); color: #6d28d9; }

        .device-cell { display: flex; align-items: center; gap: 0.75rem; }
        .device-info h4 { font-weight: 600; font-size: 0.9rem; color: var(--text-main); margin-bottom: 0.15rem; }
        .device-info p { font-size: 0.78rem; color: var(--text-muted); }

        .remarks-cell {
            max-width: 200px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
            color: var(--text-muted); font-style: italic; font-size: 0.83rem;
        }

        .table-footer {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 1rem;
            padding: 1rem 1.5rem; border-top: 1px solid var(--card-border);
            background: var(--glass-panel);
        }
        .table-footer span { font-size: 0.85rem; color: var(--text-muted); }
        .pagination { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .page-nav {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.5rem 1rem; border-radius: 10px;
            background: var(--card-bg); border: 1px solid var(--card-border);
            color: var(--text-main); font-size: 0.85rem; font-weight: 600;
            text-decoration: none; transition: all 0.2s;
        }
        .page-nav:hover:not(.disabled) { border-color: var(--primary); color: var(--primary); }
        .page-nav.disabled { opacity: 0.45; pointer-events: none; cursor: default; }

        .empty-state {
            text-align: center; padding: 4rem 2rem; color: var(--text-muted);
        }
        .empty-state i { font-size: 3rem; display: block; margin-bottom: 0.75rem; color: #cbd5e1; }
        .empty-state p { font-size: 0.95rem; }

        @keyframes fadeInDown { from { opacity:0; transform:translateY(-20px); } to { opacity:1; transform:translateY(0); } }
        @keyframes fadeInUp   { from { opacity:0; transform:translateY(20px);  } to { opacity:1; transform:translateY(0); } }
    </style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">

    <header class="page-header">
        <div class="page-title">
            <h1><i class="ri-history-line"></i> Activity History</h1>
            <p>Registrations (laptop, network, AV), handovers, warranty, and in-house repairs.</p>
        </div>
    </header>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(37,99,235,0.1);color:var(--primary);">
                <i class="ri-stack-line"></i>
            </div>
            <div class="stat-body">
                <div class="stat-num"><?= $total_assets ?></div>
                <div class="stat-label">Total inventory</div>
                <div class="stat-sub">Laptop <?= $total_laptops ?> · Network <?= $total_network ?> · AV <?= $total_av ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(16,185,129,0.1);color:var(--success);">
                <i class="ri-user-received-2-line"></i>
            </div>
            <div class="stat-body">
                <div class="stat-num"><?= $total_handovers ?></div>
                <div class="stat-label">Handovers</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(245,158,11,0.1);color:var(--warning);">
                <i class="ri-shield-check-line"></i>
            </div>
            <div class="stat-body">
                <div class="stat-num"><?= $total_warranty ?></div>
                <div class="stat-label">Warranty records</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(124,58,237,0.1);color:var(--secondary);">
                <i class="ri-calendar-check-line"></i>
            </div>
            <div class="stat-body">
                <div class="stat-num"><?= $this_month ?></div>
                <div class="stat-label">New this month</div>
                <div class="stat-sub">Laptop + network + AV registrations</div>
            </div>
        </div>
    </div>

    <form method="get" class="controls-bar" action="history.php" id="historyFilters">
        <input type="hidden" name="type" value="<?= htmlspecialchars($filter_type, ENT_QUOTES, 'UTF-8') ?>">
        <div class="search-box">
            <i class="ri-search-2-line"></i>
            <input type="search" name="q" value="<?= htmlspecialchars($search_q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search device, serial, asset ID, staff…" autocomplete="off">
        </div>
        <button type="submit" class="btn-search">Search</button>

        <div class="filter-group">
            <?php
            $qstr = $search_q !== '' ? ['q' => $search_q] : [];
            $dstr = $filter_date !== '' ? ['date' => $filter_date] : [];
            $base = array_merge(['type' => 'all'], $dstr, $qstr);
            ?>
            <a href="history.php?<?= htmlspecialchars(history_build_query(array_merge($base, ['type' => 'all'])), ENT_QUOTES, 'UTF-8') ?>"
               class="filter-btn <?= $filter_type === 'all' ? 'active' : '' ?>"><i class="ri-apps-line"></i> All</a>
            <a href="history.php?<?= htmlspecialchars(history_build_query(array_merge($base, ['type' => 'register'])), ENT_QUOTES, 'UTF-8') ?>"
               class="filter-btn <?= $filter_type === 'register' ? 'active' : '' ?>"><i class="ri-add-circle-line"></i> Registration</a>
            <a href="history.php?<?= htmlspecialchars(history_build_query(array_merge($base, ['type' => 'handover'])), ENT_QUOTES, 'UTF-8') ?>"
               class="filter-btn <?= $filter_type === 'handover' ? 'active' : '' ?>"><i class="ri-user-received-2-line"></i> Handover</a>
            <a href="history.php?<?= htmlspecialchars(history_build_query(array_merge($base, ['type' => 'warranty'])), ENT_QUOTES, 'UTF-8') ?>"
               class="filter-btn <?= $filter_type === 'warranty' ? 'active' : '' ?>"><i class="ri-shield-check-line"></i> Warranty</a>
            <a href="history.php?<?= htmlspecialchars(history_build_query(array_merge($base, ['type' => 'repair'])), ENT_QUOTES, 'UTF-8') ?>"
               class="filter-btn <?= $filter_type === 'repair' ? 'active' : '' ?>"><i class="ri-tools-line"></i> Repair</a>
        </div>

        <div style="display:flex;align-items:center;gap:0.5rem;">
            <input type="month" name="date" value="<?= htmlspecialchars($filter_date, ENT_QUOTES, 'UTF-8') ?>"
                   class="date-filter" title="Filter by month" onchange="document.getElementById('historyFilters').submit()">
            <?php if ($filter_date !== ''): ?>
            <a href="history.php?<?= htmlspecialchars(history_build_query(array_merge(['type' => $filter_type], $search_q !== '' ? ['q' => $search_q] : [])), ENT_QUOTES, 'UTF-8') ?>" class="filter-btn" title="Clear month"><i class="ri-close-line"></i></a>
            <?php endif; ?>
        </div>
    </form>

    <div class="glass-card">
        <div class="table-responsive">
            <table class="data-table" id="historyTable">
                <thead>
                    <tr>
                        <th>Event type</th>
                        <th>Device</th>
                        <th>Asset ID</th>
                        <th>Staff / info</th>
                        <th>Department</th>
                        <th>Assignment / period</th>
                        <th>Remarks</th>
                        <th>Date &amp; time</th>
                    </tr>
                </thead>
                <tbody id="historyBody">
                <?php if (empty($page_events)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="ri-inbox-line"></i>
                                <p>No activity records found<?= $filter_date !== '' ? ' for ' . date('F Y', strtotime($filter_date . '-01')) : '' ?>.</p>
                            </div>
                        </td>
                    </tr>
                <?php else:
                    foreach ($page_events as $e):
                    $type = (string) $e['type'];
                    switch ($type) {
                        case 'register':
                            $sub = (string) ($e['subtype'] ?? 'laptop');
                            $subLabel = $sub === 'network' ? 'Network' : ($sub === 'av' ? 'AV' : 'Laptop');
                            $type_meta = [
                                'label' => 'Registration · ' . $subLabel,
                                'icon'  => $sub === 'network' ? 'ri-router-line' : ($sub === 'av' ? 'ri-mic-line' : 'ri-macbook-line'),
                                'dot'   => 'dot-register',
                                'badge' => 'event-register',
                            ];
                            break;
                        case 'handover':
                            $type_meta = ['label' => 'Handover', 'icon' => 'ri-user-received-2-fill', 'dot' => 'dot-handover', 'badge' => 'event-handover'];
                            break;
                        case 'warranty':
                            $type_meta = ['label' => 'Warranty', 'icon' => 'ri-shield-check-fill', 'dot' => 'dot-warranty', 'badge' => 'event-warranty'];
                            break;
                        case 'repair':
                            $type_meta = ['label' => 'Repair', 'icon' => 'ri-tools-fill', 'dot' => 'dot-repair', 'badge' => 'event-repair'];
                            break;
                        default:
                            $type_meta = ['label' => $type, 'icon' => 'ri-file-line', 'dot' => 'dot-register', 'badge' => 'event-register'];
                    }
                    $dt = new DateTime((string) $e['date']);
                ?>
                    <tr>
                        <td>
                            <span class="event-badge <?= $type_meta['badge'] ?>">
                                <i class="<?= $type_meta['icon'] ?>"></i> <?= htmlspecialchars($type_meta['label'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td>
                            <div class="device-cell">
                                <div class="event-dot <?= $type_meta['dot'] ?>">
                                    <i class="<?= $type_meta['icon'] ?>"></i>
                                </div>
                                <div class="device-info">
                                    <h4><?= htmlspecialchars((string) $e['device'], ENT_QUOTES, 'UTF-8') ?></h4>
                                    <p>SN: <?= htmlspecialchars((string) $e['serial'], ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>
                        </td>
                        <td>
                            <code style="background:var(--glass-panel);padding:2px 8px;border-radius:6px;font-size:0.82rem;color:var(--primary);font-weight:600;">
                                <?= htmlspecialchars((string) $e['asset_id'], ENT_QUOTES, 'UTF-8') ?>
                            </code>
                        </td>
                        <td><?= htmlspecialchars((string) $e['actor'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $e['dept'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span style="font-size:0.83rem;color:var(--text-muted);">
                                <?= htmlspecialchars((string) $e['assign'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td>
                            <div class="remarks-cell" title="<?= htmlspecialchars((string) ($e['remarks'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <?php if (!empty($e['remarks'])): ?>
                                    <?= htmlspecialchars((string) $e['remarks'], ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="white-space:nowrap;">
                            <div style="font-weight:600;font-size:0.87rem;"><?= $dt->format('d M Y') ?></div>
                            <div style="font-size:0.77rem;color:var(--text-muted);"><?= $dt->format('H:i') ?></div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-footer">
            <span id="recordCount">
                <?php if ($total_events === 0): ?>
                    No records<?php if ($filter_type !== 'all'): ?> · filter: <strong><?= htmlspecialchars(ucfirst($filter_type), ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>
                <?php else: ?>
                    Showing <strong><?= $from_n ?>–<?= $to_n ?></strong> of <strong><?= $total_events ?></strong>
                    · page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong>
                    <?php if ($filter_type !== 'all'): ?> · <strong><?= htmlspecialchars(ucfirst($filter_type), ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>
                    <?php if ($filter_date !== ''): ?> · <strong><?= date('F Y', strtotime($filter_date . '-01')) ?></strong><?php endif; ?>
                    <?php if ($search_q !== ''): ?> · search: <strong><?= htmlspecialchars($search_q, ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>
                <?php endif; ?>
            </span>
            <div class="pagination">
                <?php
                $prev_q = history_build_query([
                    'type' => $filter_type,
                    'date' => $filter_date,
                    'q'    => $search_q,
                    'page' => $page > 2 ? (string) ($page - 1) : null,
                ]);
                $next_q = history_build_query([
                    'type' => $filter_type,
                    'date' => $filter_date,
                    'q'    => $search_q,
                    'page' => $page < $total_pages ? (string) ($page + 1) : null,
                ]);
                ?>
                <?php if ($page <= 1): ?>
                    <span class="page-nav disabled"><i class="ri-arrow-left-s-line"></i> Previous</span>
                <?php else: ?>
                    <a class="page-nav" href="history.php?<?= htmlspecialchars($prev_q, ENT_QUOTES, 'UTF-8') ?>"><i class="ri-arrow-left-s-line"></i> Previous</a>
                <?php endif; ?>
                <?php if ($page >= $total_pages || $total_events === 0): ?>
                    <span class="page-nav disabled">Next <i class="ri-arrow-right-s-line"></i></span>
                <?php else: ?>
                    <a class="page-nav" href="history.php?<?= htmlspecialchars($next_q, ENT_QUOTES, 'UTF-8') ?>">Next <i class="ri-arrow-right-s-line"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</main>

<script>
    function toggleDropdown(el, e) {
        e.preventDefault();
        const group = el.closest('.nav-group');
        const dropdown = group && group.querySelector('.nav-dropdown');
        if (!dropdown) return;
        el.classList.toggle('open');
        dropdown.classList.toggle('show');
    }
</script>
</body>
</html>
