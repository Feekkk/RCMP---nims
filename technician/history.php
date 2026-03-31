<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)$_SESSION['role_id'] !== 1) {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../config/database.php';

$pdo = db();

// ── Summary stats ─────────────────────────────────────────────────────────────
$total_laptops   = (int)$pdo->query("SELECT COUNT(*) FROM laptop")->fetchColumn();
$total_handovers = (int)$pdo->query("SELECT COUNT(*) FROM handover")->fetchColumn();
$total_warranty  = (int)$pdo->query("SELECT COUNT(*) FROM warranty")->fetchColumn();
$this_month      = (int)$pdo->query("
    SELECT COUNT(*) FROM laptop
    WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())
")->fetchColumn();

// ── Filter ────────────────────────────────────────────────────────────────────
$filter_type = $_GET['type'] ?? 'all';   // all | register | handover | warranty
$filter_date = $_GET['date'] ?? '';       // YYYY-MM

// ── Build unified activity log ─────────────────────────────────────────────────
$events = [];

// 1. Laptop registrations
if ($filter_type === 'all' || $filter_type === 'register') {
    $sql = "SELECT
                l.asset_id, l.serial_num, l.brand, l.model, l.created_at,
                s.name AS status_name
            FROM laptop l
            JOIN status s ON s.status_id = l.status_id
            ORDER BY l.created_at DESC";
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $events[] = [
            'type'     => 'register',
            'date'     => $r['created_at'],
            'asset_id' => $r['asset_id'],
            'device'   => trim(($r['brand'] ?? '') . ' ' . ($r['model'] ?? '')) ?: 'Unknown Device',
            'serial'   => $r['serial_num'] ?? '—',
            'actor'    => '—',
            'dept'     => '—',
            'assign'   => $r['status_name'],
            'remarks'  => null,
        ];
    }
}

// 2. Handovers
if ($filter_type === 'all' || $filter_type === 'handover') {
    $sql = "SELECT
                h.handover_id, h.asset_id, h.staff_id, h.handover_date, h.handover_remarks,
                h.created_at,
                CONCAT(l.brand, ' ', l.model) AS device, l.serial_num,
                hs.assignment_type,
                st.full_name AS recipient_name, st.department AS recipient_dept
            FROM handover h
            JOIN laptop l ON l.asset_id = h.asset_id
            LEFT JOIN handover_staff hs ON hs.handover_id = h.handover_id
            LEFT JOIN staff st ON st.employee_no = hs.employee_no
            ORDER BY h.created_at DESC";
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $events[] = [
            'type'     => 'handover',
            'date'     => $r['created_at'],
            'asset_id' => $r['asset_id'],
            'device'   => trim($r['device']) ?: 'Unknown Device',
            'serial'   => $r['serial_num'] ?? '—',
            'actor'    => $r['recipient_name'] ?? $r['staff_id'],
            'dept'     => $r['recipient_dept'] ?? '—',
            'assign'   => $r['assignment_type'] ?? '—',
            'remarks'  => $r['handover_remarks'],
        ];
    }
}

// 3. Warranty records
if ($filter_type === 'all' || $filter_type === 'warranty') {
    $sql = "SELECT
                w.warranty_id, w.asset_id, w.warranty_start_date, w.warranty_end_date,
                w.warranty_remarks, w.created_at,
                CONCAT(l.brand, ' ', l.model) AS device, l.serial_num
            FROM warranty w
            JOIN laptop l ON l.asset_id = w.asset_id
            ORDER BY w.created_at DESC";
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $events[] = [
            'type'     => 'warranty',
            'date'     => $r['created_at'],
            'asset_id' => $r['asset_id'],
            'device'   => trim($r['device']) ?: 'Unknown Device',
            'serial'   => $r['serial_num'] ?? '—',
            'actor'    => '—',
            'dept'     => '—',
            'assign'   => $r['warranty_start_date'] . ' → ' . $r['warranty_end_date'],
            'remarks'  => $r['warranty_remarks'],
        ];
    }
}

// Apply month filter
if ($filter_date) {
    $events = array_filter($events, fn($e) => substr($e['date'], 0, 7) === $filter_date);
}

// Sort all events newest first
usort($events, fn($a, $b) => strcmp($b['date'], $a['date']));

$total_events = count($events);
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

        /* Decorative blobs */
        .blob { position: fixed; border-radius: 50%; filter: blur(90px); pointer-events: none; z-index: 0; }
        .blob-1 { width: 500px; height: 500px; background: rgba(37,99,235,0.06);  top: -120px; right: -100px; }
        .blob-2 { width: 400px; height: 400px; background: rgba(124,58,237,0.05); bottom: -80px; left: -80px; }

        /* ── Sidebar ── */
        .sidebar {
            width: 280px; min-height: 100vh; background: var(--sidebar-bg);
            border-right: 1px solid var(--card-border);
            display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; bottom: 0;
            z-index: 100; box-shadow: 2px 0 20px rgba(15,23,42,0.06);
        }
        .sidebar-logo { padding: 1.5rem 1.75rem 1.25rem; border-bottom: 1px solid var(--card-border); }
        .sidebar-logo img { height: 42px; object-fit: contain; }
        .nav-menu { flex: 1; padding: 1.25rem 1rem; display: flex; flex-direction: column; gap: 0.25rem; overflow-y: auto; }
        .nav-item {
            display: flex; align-items: center; gap: 1rem;
            padding: 0.75rem 1.25rem; border-radius: 12px;
            color: var(--text-muted); text-decoration: none;
            font-weight: 500; font-size: 0.95rem;
            transition: all 0.2s ease; cursor: pointer; border: none; background: none; width: 100%;
        }
        .nav-item:hover, .nav-item.open { background: rgba(37,99,235,0.06); color: var(--primary); }
        .nav-item.active { background: rgba(37,99,235,0.1); color: var(--primary); font-weight: 600; }
        .nav-item i { font-size: 1.25rem; }
        .nav-dropdown { display: none; flex-direction: column; gap: 0.25rem; padding-left: 3.25rem; margin-top: -0.25rem; margin-bottom: 0.25rem; }
        .nav-dropdown.show { display: flex; }
        .nav-dropdown-item {
            padding: 0.6rem 1rem; border-radius: 8px;
            color: var(--text-muted); text-decoration: none;
            font-size: 0.85rem; font-weight: 500;
            transition: all 0.2s ease; position: relative;
        }
        .nav-dropdown-item::before {
            content: ''; position: absolute; left: -1rem; top: 50%;
            width: 6px; height: 6px; background: var(--card-border);
            border-radius: 50%; transform: translateY(-50%); transition: all 0.2s ease;
        }
        .nav-dropdown-item:hover { color: var(--primary); background: rgba(37,99,235,0.06); }
        .nav-dropdown-item:hover::before { background: var(--primary); }
        .nav-dropdown-item.active { color: var(--primary); }
        .nav-dropdown-item.active::before { background: var(--primary); }
        .nav-item.open .chevron { transform: rotate(180deg); }
        .user-profile {
            padding: 1.25rem 1.75rem; border-top: 1px solid var(--card-border);
            display: flex; align-items: center; gap: 0.75rem;
            cursor: pointer; transition: background 0.2s;
        }
        .user-profile:hover { background: rgba(37,99,235,0.04); }
        .avatar {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif; font-weight: 700; color: white; font-size: 1rem;
        }
        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-size: 0.9rem; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role { font-size: 0.75rem; color: var(--primary); margin-top: 0.2rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }

        /* ── Main ── */
        .main-content {
            margin-left: 280px; flex: 1;
            padding: 2.5rem 3.5rem 5rem;
            max-width: calc(100vw - 280px);
            position: relative; z-index: 1;
        }

        /* ── Header ── */
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

        /* ── Stat Cards ── */
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
        .stat-body {}
        .stat-num { font-family:'Outfit',sans-serif; font-size: 1.8rem; font-weight: 700; color: var(--text-main); line-height: 1; }
        .stat-label { font-size: 0.78rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; }

        /* ── Controls bar ── */
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

        /* ── Activity Table ── */
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

        /* ── Event type badge ── */
        .event-badge {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.3rem 0.8rem; border-radius: 20px;
            font-size: 0.76rem; font-weight: 700; white-space: nowrap;
        }
        .event-register { background: rgba(37,99,235,0.1);  color: var(--primary); }
        .event-handover { background: rgba(16,185,129,0.1); color: var(--success); }
        .event-warranty { background: rgba(245,158,11,0.1); color: var(--warning); }

        /* ── Event icon dot (timeline-style) ── */
        .event-dot {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
        }
        .dot-register { background: rgba(37,99,235,0.1);  color: var(--primary); }
        .dot-handover { background: rgba(16,185,129,0.1); color: var(--success); }
        .dot-warranty { background: rgba(245,158,11,0.1); color: var(--warning); }

        .device-cell { display: flex; align-items: center; gap: 0.75rem; }
        .device-info h4 { font-weight: 600; font-size: 0.9rem; color: var(--text-main); margin-bottom: 0.15rem; }
        .device-info p { font-size: 0.78rem; color: var(--text-muted); }

        .remarks-cell {
            max-width: 200px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
            color: var(--text-muted); font-style: italic; font-size: 0.83rem;
        }

        /* ── Pagination / footer ── */
        .table-footer {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.5rem; border-top: 1px solid var(--card-border);
            background: var(--glass-panel);
        }
        .table-footer span { font-size: 0.85rem; color: var(--text-muted); }

        /* ── Empty state ── */
        .empty-state {
            text-align: center; padding: 4rem 2rem; color: var(--text-muted);
        }
        .empty-state i { font-size: 3rem; display: block; margin-bottom: 0.75rem; color: #cbd5e1; }
        .empty-state p { font-size: 0.95rem; }

        /* ── Animations ── */
        @keyframes fadeInDown { from { opacity:0; transform:translateY(-20px); } to { opacity:1; transform:translateY(0); } }
        @keyframes fadeInUp   { from { opacity:0; transform:translateY(20px);  } to { opacity:1; transform:translateY(0); } }
    </style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<!-- Sidebar -->
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<!-- Main Content -->
<main class="main-content">

    <!-- Header -->
    <header class="page-header">
        <div class="page-title">
            <h1><i class="ri-history-line"></i> Activity History</h1>
            <p>Full audit log of asset registrations, handovers, and warranty records.</p>
        </div>
    </header>

    <!-- Summary Stats -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(37,99,235,0.1);color:var(--primary);">
                <i class="ri-macbook-line"></i>
            </div>
            <div class="stat-body">
                <div class="stat-num"><?= $total_laptops ?></div>
                <div class="stat-label">Total Assets</div>
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
                <div class="stat-label">Warranty Records</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(124,58,237,0.1);color:var(--secondary);">
                <i class="ri-calendar-check-line"></i>
            </div>
            <div class="stat-body">
                <div class="stat-num"><?= $this_month ?></div>
                <div class="stat-label">Registered This Month</div>
            </div>
        </div>
    </div>

    <!-- Controls Bar -->
    <div class="controls-bar">
        <!-- Search -->
        <div class="search-box">
            <i class="ri-search-2-line"></i>
            <input type="text" id="searchInput" placeholder="Search device, serial, staff ID...">
        </div>

        <!-- Type filters -->
        <div class="filter-group">
            <a href="?type=all<?= $filter_date ? '&date='.$filter_date : '' ?>"
               class="filter-btn <?= $filter_type==='all'?'active':'' ?>">
                <i class="ri-apps-line"></i> All
            </a>
            <a href="?type=register<?= $filter_date ? '&date='.$filter_date : '' ?>"
               class="filter-btn <?= $filter_type==='register'?'active':'' ?>">
                <i class="ri-add-circle-line"></i> Registration
            </a>
            <a href="?type=handover<?= $filter_date ? '&date='.$filter_date : '' ?>"
               class="filter-btn <?= $filter_type==='handover'?'active':'' ?>">
                <i class="ri-user-received-2-line"></i> Handover
            </a>
            <a href="?type=warranty<?= $filter_date ? '&date='.$filter_date : '' ?>"
               class="filter-btn <?= $filter_type==='warranty'?'active':'' ?>">
                <i class="ri-shield-check-line"></i> Warranty
            </a>
        </div>

        <!-- Month picker -->
        <form method="GET" style="display:flex;align-items:center;gap:0.5rem;">
            <input type="hidden" name="type" value="<?= htmlspecialchars($filter_type) ?>">
            <input type="month" name="date" value="<?= htmlspecialchars($filter_date) ?>"
                   class="date-filter" onchange="this.form.submit()" title="Filter by month">
            <?php if ($filter_date): ?>
            <a href="?type=<?= $filter_type ?>" class="filter-btn" title="Clear date filter">
                <i class="ri-close-line"></i>
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Activity Table -->
    <div class="glass-card">
        <div class="table-responsive">
            <table class="data-table" id="historyTable">
                <thead>
                    <tr>
                        <th>Event Type</th>
                        <th>Device</th>
                        <th>Asset ID</th>
                        <th>Staff / Info</th>
                        <th>Department</th>
                        <th>Assignment / Period</th>
                        <th>Remarks</th>
                        <th>Date & Time</th>
                    </tr>
                </thead>
                <tbody id="historyBody">
                <?php if (empty($events)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="ri-inbox-line"></i>
                                <p>No activity records found<?= $filter_date ? ' for '.date('F Y', strtotime($filter_date.'-01')) : '' ?>.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($events as $e):
                    // Type meta
                    $type_meta = match($e['type']) {
                        'register' => ['label'=>'Registration', 'icon'=>'ri-add-circle-fill',      'dot'=>'dot-register', 'badge'=>'event-register'],
                        'handover' => ['label'=>'Handover',     'icon'=>'ri-user-received-2-fill', 'dot'=>'dot-handover', 'badge'=>'event-handover'],
                        'warranty' => ['label'=>'Warranty',     'icon'=>'ri-shield-check-fill',    'dot'=>'dot-warranty', 'badge'=>'event-warranty'],
                        default    => ['label'=>$e['type'],     'icon'=>'ri-file-line',            'dot'=>'dot-register', 'badge'=>'event-register'],
                    };
                    $dt = new DateTime($e['date']);
                ?>
                    <tr>
                        <td>
                            <span class="event-badge <?= $type_meta['badge'] ?>">
                                <i class="<?= $type_meta['icon'] ?>"></i> <?= $type_meta['label'] ?>
                            </span>
                        </td>
                        <td>
                            <div class="device-cell">
                                <div class="event-dot <?= $type_meta['dot'] ?>">
                                    <i class="<?= $type_meta['icon'] ?>"></i>
                                </div>
                                <div class="device-info">
                                    <h4><?= htmlspecialchars($e['device']) ?></h4>
                                    <p>SN: <?= htmlspecialchars($e['serial']) ?></p>
                                </div>
                            </div>
                        </td>
                        <td>
                            <code style="background:var(--glass-panel);padding:2px 8px;border-radius:6px;font-size:0.82rem;color:var(--primary);font-weight:600;">
                                <?= htmlspecialchars($e['asset_id']) ?>
                            </code>
                        </td>
                        <td><?= htmlspecialchars($e['actor']) ?></td>
                        <td><?= htmlspecialchars($e['dept']) ?></td>
                        <td>
                            <span style="font-size:0.83rem;color:var(--text-muted);">
                                <?= htmlspecialchars($e['assign']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="remarks-cell" title="<?= htmlspecialchars($e['remarks'] ?? '') ?>">
                                <?= $e['remarks'] ? htmlspecialchars($e['remarks']) : '<span style="color:#cbd5e1;">—</span>' ?>
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

        <!-- Table Footer -->
        <div class="table-footer">
            <span id="recordCount">
                Showing <strong><?= $total_events ?></strong> record<?= $total_events !== 1 ? 's' : '' ?>
                <?php if ($filter_type !== 'all'): ?> · filtered by <strong><?= ucfirst($filter_type) ?></strong><?php endif; ?>
                <?php if ($filter_date): ?> · <strong><?= date('F Y', strtotime($filter_date.'-01')) ?></strong><?php endif; ?>
            </span>
        </div>
    </div>

</main>

<script>
    // Sidebar dropdown
    function toggleDropdown(el, e) {
        e.preventDefault();
        const group    = el.closest('.nav-group');
        const dropdown = group.querySelector('.nav-dropdown');
        el.classList.toggle('open');
        dropdown.classList.toggle('show');
    }

    // Client-side search
    document.getElementById('searchInput').addEventListener('input', function () {
        const q    = this.value.toLowerCase();
        const rows = document.querySelectorAll('#historyBody tr');
        let visible = 0;
        rows.forEach(row => {
            const match = row.innerText.toLowerCase().includes(q);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        document.getElementById('recordCount').innerHTML =
            `Showing <strong>${visible}</strong> record${visible !== 1 ? 's' : ''}${q ? ' matching <em>"' + q + '"</em>' : ''}`;
    });
</script>
</body>
</html>
