<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 2) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function users_page_url(string $filter, int $page, ?string $q = null): string
{
    $params = [];
    if ($filter !== 'all') {
        $params['filter'] = $filter;
    }
    $search = trim((string)($q ?? ''));
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    $s = http_build_query($params);

    return 'users.php' . ($s !== '' ? '?' . $s : '');
}

$pdo = db();
$dbError = '';
$admins = [];
$technicians = [];
$staffDirectory = [];
$staffTableOk = true;

try {
    $admins = $pdo->query("
        SELECT staff_id, full_name, email, created_at
        FROM users
        WHERE role_id = 2
        ORDER BY full_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $technicians = $pdo->query("
        SELECT staff_id, full_name, email, created_at
        FROM users
        WHERE role_id = 1
        ORDER BY full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

try {
    $staffDirectory = $pdo->query("
        SELECT employee_no, full_name, email, department, phone, created_at
        FROM staff
        ORDER BY full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $staffTableOk = false;
    $staffDirectory = [];
}

$peopleRows = [];
foreach ($admins as $u) {
    $peopleRows[] = [
        'kind' => 'admin',
        'role_id' => 2,
        'full_name' => $u['full_name'],
        'staff_id' => $u['staff_id'],
        'employee_no' => '',
        'email' => $u['email'] ?? '',
        'department' => '',
        'phone' => '',
        'created_at' => $u['created_at'] ?? null,
    ];
}
foreach ($technicians as $u) {
    $peopleRows[] = [
        'kind' => 'technician',
        'role_id' => 1,
        'full_name' => $u['full_name'],
        'staff_id' => $u['staff_id'],
        'employee_no' => '',
        'email' => $u['email'] ?? '',
        'department' => '',
        'phone' => '',
        'created_at' => $u['created_at'] ?? null,
    ];
}
if ($staffTableOk) {
    foreach ($staffDirectory as $s) {
        $peopleRows[] = [
            'kind' => 'staff',
            'role_id' => null,
            'full_name' => $s['full_name'],
            'staff_id' => '',
            'employee_no' => (string)($s['employee_no'] ?? ''),
            'email' => $s['email'] ?? '',
            'department' => trim((string)($s['department'] ?? '')),
            'phone' => trim((string)($s['phone'] ?? '')),
            'created_at' => $s['created_at'] ?? null,
        ];
    }
}
usort($peopleRows, static function ($a, $b): int {
    return strcasecmp($a['full_name'], $b['full_name']);
});

$countAll = count($peopleRows);
$countAdmin = count($admins);
$countTech = count($technicians);
$countStaff = $staffTableOk ? count($staffDirectory) : 0;

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'admin', 'technician', 'staff'], true)) {
    $filter = 'all';
}
$rawQ = $_GET['q'] ?? '';
$searchQ = trim(is_array($rawQ) ? '' : (string)$rawQ);
if (mb_strlen($searchQ) > 80) {
    $searchQ = mb_substr($searchQ, 0, 80);
}
$filteredPeople = array_values(array_filter(
    $peopleRows,
    static function (array $p) use ($filter, $searchQ): bool {
        if ($filter !== 'all' && ($p['kind'] ?? '') !== $filter) return false;
        if ($searchQ === '') return true;
        $hay = strtolower(
            (string)($p['full_name'] ?? '') . ' ' .
            (string)($p['employee_no'] ?? '') . ' ' .
            (string)($p['staff_id'] ?? '') . ' ' .
            (string)($p['email'] ?? '') . ' ' .
            (string)($p['department'] ?? '') . ' ' .
            (string)($p['phone'] ?? '')
        );
        return strpos($hay, strtolower($searchQ)) !== false;
    }
));
$perPage = 10;
$totalFiltered = count($filteredPeople);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = max(1, (int)($_GET['page'] ?? 1));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pageRows = array_slice($filteredPeople, $offset, $perPage);
$showFrom = $totalFiltered === 0 ? 0 : $offset + 1;
$showTo = $totalFiltered === 0 ? 0 : min($offset + $perPage, $totalFiltered);

$flash = '';
$flashKind = 'info';
if (($_GET['added'] ?? '') === 'tech') {
    $flash = 'Technician account created successfully.';
} elseif (($_GET['err'] ?? '') === 'addtech') {
    $flashKind = 'warn';
    $flash = 'Could not create technician. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
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
            flex-wrap: wrap; gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--card-border);
        }
        .page-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.1rem;
            font-weight: 900;
            letter-spacing: -0.5px;
            display: flex; align-items: center; gap: 0.75rem;
        }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); margin-top: 0.35rem; max-width: 520px; line-height: 1.45; }
        .header-actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .btn {
            padding: 0.75rem 1.2rem;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
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

        .dropdown-container { position: relative; display: inline-block; }
        .action-dropdown {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.5rem;
            min-width: 220px;
            box-shadow: 0 8px 25px rgba(15,23,42,0.12);
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            z-index: 60;
        }
        .action-dropdown.show { display: flex; }
        .action-dropdown-item {
            width: 100%;
            text-align: left;
            padding: 0.65rem 1rem;
            border-radius: 8px;
            color: var(--text-main);
            background: none;
            border: none;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            font-family: inherit;
        }
        .action-dropdown-item:hover { background: rgba(37, 99, 235, 0.06); color: var(--primary); }
        .action-dropdown-hint {
            padding: 0.45rem 1rem 0.35rem;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
        }

        .ui-notice {
            display: none;
            align-items: flex-start;
            gap: 0.65rem;
            padding: 0.9rem 1.15rem;
            border-radius: 12px;
            background: rgba(37, 99, 235, 0.08);
            border: 1px solid rgba(37, 99, 235, 0.2);
            color: #1e40af;
            font-weight: 700;
            font-size: 0.9rem;
            margin-bottom: 1.25rem;
            line-height: 1.45;
        }
        .ui-notice.show { display: flex; }
        .ui-notice i { font-size: 1.2rem; flex-shrink: 0; margin-top: 0.1rem; }
        .ui-notice.warn { background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.35); color: #b45309; }

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
        .alert-warn {
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.35);
            color: #b45309;
            padding: 0.85rem 1.1rem;
            border-radius: 12px;
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
            font-weight: 600;
        }

        .people-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            overflow: hidden;
        }
        .card-toolbar {
            padding: 1.1rem 1.35rem;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: linear-gradient(180deg, rgba(248,250,255,0.85), var(--card-bg));
        }
        .toolbar-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 900;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toolbar-title i { color: var(--primary); }
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        .filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 1rem;
            border-radius: 999px;
            border: 1px solid var(--card-border);
            background: var(--glass-panel);
            color: var(--text-muted);
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .filter-btn:hover { border-color: rgba(37,99,235,0.28); color: var(--primary); background: rgba(37,99,235,0.06); }
        .filter-btn.active { background: var(--primary); border-color: var(--primary); color: #fff; }
        a.filter-btn { text-decoration: none; }
        .filter-btn .ct {
            font-family: 'Outfit', sans-serif;
            font-size: 0.78rem;
            opacity: 0.85;
            font-weight: 900;
        }
        .people-search {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
            flex: 1 1 min(100%, 380px);
            min-width: 0;
        }
        .people-search__input-wrap {
            position: relative;
            flex: 1 1 200px;
            min-width: 0;
        }
        .people-search__icon {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
            pointer-events: none;
        }
        .people-search__input {
            width: 100%;
            box-sizing: border-box;
            height: 2.75rem;
            padding: 0 0.85rem 0 2.55rem;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: var(--glass-panel);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .people-search__input::placeholder {
            color: var(--text-muted);
            font-weight: 600;
            opacity: 0.85;
        }
        .people-search__input:hover {
            border-color: rgba(37, 99, 235, 0.22);
        }
        .people-search__input:focus {
            outline: none;
            border-color: rgba(37, 99, 235, 0.45);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        .people-search__submit {
            height: 2.75rem;
            padding: 0 1.1rem;
            border-radius: 10px;
            font-size: 0.88rem;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.22);
        }
        .people-search__submit:hover {
            transform: translateY(-1px);
        }
        .people-search__reset {
            height: 2.75rem;
            padding: 0 1rem;
            border-radius: 10px;
            font-size: 0.88rem;
        }
        .table-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 700;
            text-align: right;
            line-height: 1.4;
        }
        .table-meta strong { color: var(--text-main); }
        .pager {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 0.75rem;
            padding: 1rem 1.35rem;
            border-top: 1px solid var(--card-border);
            background: var(--glass-panel);
        }
        .pager-info { font-size: 0.85rem; color: var(--text-muted); font-weight: 700; }
        .pager-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        a.pager-btn:hover { border-color: rgba(37,99,235,0.35); color: var(--primary); background: rgba(37,99,235,0.06); }
        .pager-btn.disabled {
            opacity: 0.45;
            pointer-events: none;
            cursor: not-allowed;
        }

        .table-responsive { overflow-x: auto; width: 100%; }
        .data-table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        .data-table th {
            text-align: left;
            padding: 0.85rem 1.1rem;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            font-weight: 900;
            border-bottom: 1px solid var(--card-border);
            background: var(--glass-panel);
        }
        .data-table td {
            padding: 0.95rem 1.1rem;
            font-size: 0.9rem;
            border-bottom: 1px dashed rgba(226,232,240,0.9);
            vertical-align: middle;
        }
        .data-table tbody tr { transition: background 0.2s ease; }
        .data-table tbody tr:hover { background: rgba(37,99,235,0.03); }
        .data-table tr:last-child td { border-bottom: none; }
        .mono { font-family: ui-monospace, monospace; font-size: 0.82rem; }
        .cell-main { font-weight: 800; color: var(--text-main); }
        .cell-sub { font-size: 0.78rem; color: var(--text-muted); margin-top: 0.15rem; font-weight: 600; }
        .muted { color: var(--text-muted); font-weight: 700; }
        .empty-cell {
            text-align: center;
            padding: 2.5rem 1rem !important;
            color: var(--text-muted);
            font-weight: 700;
            font-size: 0.9rem;
        }
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
        }
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
        <?php if (!$staffTableOk): ?>
            <div class="alert-warn" role="status">
                <i class="ri-database-2-line"></i>
                The <code>staff</code> directory table is not available yet. Apply <code>db/schema.sql</code> (section <code>CREATE TABLE staff</code>) to enable the Staff column.
            </div>
        <?php endif; ?>

        <div id="uiNotice" class="ui-notice <?= $flashKind === 'warn' ? 'warn' : '' ?> <?= $flash !== '' ? 'show' : '' ?>" role="status" aria-live="polite">
            <i class="ri-information-line"></i>
            <span id="uiNoticeText"><?= htmlspecialchars($flash) ?></span>
        </div>

        <header class="page-header">
            <div class="page-title">
                <h1><i class="ri-group-line"></i> People</h1>
                <p>Administrators and technicians are system accounts (<code>users</code>). Staff are directory records in <code>staff</code> (no login). Forms for “Add” are UI-only for now.</p>
            </div>
            <div class="header-actions">
                <div class="dropdown-container">
                    <button type="button" class="btn btn-primary" onclick="toggleAddMenu(this, event)">
                        <i class="ri-user-add-line"></i> Add person <i class="ri-arrow-down-s-line"></i>
                    </button>
                    <div class="action-dropdown" id="addPersonMenu" onclick="event.stopPropagation()">
                        <div class="action-dropdown-hint">Register account</div>
                        <button type="button" class="action-dropdown-item" onclick="window.location.href='addTech.php'">
                            <i class="ri-tools-line" style="color:var(--primary);"></i> Add technician
                        </button>
                        <div class="action-dropdown-hint">Directory</div>
                        <button type="button" class="action-dropdown-item" onclick="window.location.href='../admin/importStaff.php'">
                            <i class="ri-user-follow-line" style="color:var(--success);"></i> Add staff
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <section class="people-card" aria-label="All people">
            <div class="card-toolbar">
                <div class="toolbar-title"><i class="ri-table-line"></i> Directory</div>
                <div class="filter-bar" role="toolbar" aria-label="Filter by role">
                    <a class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>" href="<?= htmlspecialchars(users_page_url('all', 1, $searchQ)) ?>">All <span class="ct"><?= (int)$countAll ?></span></a>
                    <a class="filter-btn <?= $filter === 'admin' ? 'active' : '' ?>" href="<?= htmlspecialchars(users_page_url('admin', 1, $searchQ)) ?>">Administrator <span class="ct"><?= (int)$countAdmin ?></span></a>
                    <a class="filter-btn <?= $filter === 'technician' ? 'active' : '' ?>" href="<?= htmlspecialchars(users_page_url('technician', 1, $searchQ)) ?>">Technician <span class="ct"><?= (int)$countTech ?></span></a>
                    <a class="filter-btn <?= $filter === 'staff' ? 'active' : '' ?>" href="<?= htmlspecialchars(users_page_url('staff', 1, $searchQ)) ?>">Staff <span class="ct"><?= (int)$countStaff ?></span></a>
                </div>
                <form class="people-search" method="get" action="" role="search">
                    <?php if ($filter !== 'all'): ?>
                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                    <?php endif; ?>
                    <div class="people-search__input-wrap">
                        <i class="ri-search-2-line people-search__icon" aria-hidden="true"></i>
                        <input class="people-search__input" type="search" name="q" value="<?= htmlspecialchars($searchQ) ?>" placeholder="Search name, email, id…" autocomplete="off" enterkeyhint="search">
                    </div>
                    <button class="btn btn-primary people-search__submit" type="submit"><i class="ri-search-line" aria-hidden="true"></i> Search</button>
                    <a class="btn btn-outline people-search__reset" href="<?= htmlspecialchars(users_page_url($filter, 1, null)) ?>"><i class="ri-close-line" aria-hidden="true"></i> Reset</a>
                </form>
                <div class="table-meta">
                    <?php if ($totalFiltered > 0): ?>
                        Showing <strong><?= (int)$showFrom ?></strong>–<strong><?= (int)$showTo ?></strong> of <strong><?= (int)$totalFiltered ?></strong><br>
                        Page <strong><?= (int)$page ?></strong> of <strong><?= (int)$totalPages ?></strong> · <?= (int)$perPage ?> per page
                    <?php else: ?>
                        No rows in this view
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-responsive">
                <table class="data-table" id="peopleTable">
                    <thead>
                        <tr>
                            <th><span class="mono">full_name</span></th>
                            <th><span class="mono">employee_no</span></th>
                            <th><span class="mono">email</span></th>
                            <th><span class="mono">role</span></th>
                            <th><span class="mono">action</span></th>
                        </tr>
                    </thead>
                    <tbody id="peopleTbody">
                        <?php if ($totalFiltered === 0): ?>
                            <tr><td colspan="5" class="empty-cell">No people to show for this filter.</td></tr>
                        <?php else: foreach ($pageRows as $p):
                            $kind = $p['kind'] ?? '';
                            $roleDisp = $kind === 'admin' ? 'Administrator'
                                : ($kind === 'technician' ? 'Technician'
                                : ($kind === 'staff' ? 'Staff' : '—'));
                            $canEdit = ($kind === 'admin' || $kind === 'technician') && ($p['staff_id'] ?? '') !== '';
                        ?>
                            <tr>
                                <td><div class="cell-main"><?= htmlspecialchars($p['full_name']) ?></div></td>
                                <td class="mono"><?= $p['employee_no'] !== '' ? htmlspecialchars($p['employee_no']) : '—' ?></td>
                                <td><?= $p['email'] !== '' ? htmlspecialchars($p['email']) : '—' ?></td>
                                <td class="mono"><?= htmlspecialchars($roleDisp) ?></td>
                                <td>
                                    <?php if ($canEdit): ?>
                                        <a class="btn btn-outline" style="padding:0.55rem 0.85rem" href="editUser.php?staff_id=<?= urlencode((string)$p['staff_id']) ?>">
                                            <i class="ri-edit-2-line"></i> Edit
                                        </a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <nav class="pager" aria-label="Table pages">
                <?php if ($page > 1): ?>
                    <a class="pager-btn" href="<?= htmlspecialchars(users_page_url($filter, $page - 1, $searchQ)) ?>"><i class="ri-arrow-left-s-line"></i> Previous</a>
                <?php else: ?>
                    <span class="pager-btn disabled"><i class="ri-arrow-left-s-line"></i> Previous</span>
                <?php endif; ?>
                <span class="pager-info"><?= (int)$page ?> / <?= (int)$totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="pager-btn" href="<?= htmlspecialchars(users_page_url($filter, $page + 1, $searchQ)) ?>">Next <i class="ri-arrow-right-s-line"></i></a>
                <?php else: ?>
                    <span class="pager-btn disabled">Next <i class="ri-arrow-right-s-line"></i></span>
                <?php endif; ?>
            </nav>
        </section>
    </main>

    <script>
        function showUiNotice(msg) {
            const bar = document.getElementById('uiNotice');
            const t = document.getElementById('uiNoticeText');
            t.textContent = msg;
            bar.classList.add('show');
            document.getElementById('addPersonMenu').classList.remove('show');
        }
        function toggleAddMenu(btn, e) {
            e.stopPropagation();
            const drop = document.getElementById('addPersonMenu');
            document.querySelectorAll('.action-dropdown.show').forEach(d => { if (d !== drop) d.classList.remove('show'); });
            drop.classList.toggle('show');
        }
        document.addEventListener('click', () => {
            document.querySelectorAll('.action-dropdown.show').forEach(d => d.classList.remove('show'));
        });
    </script>
</body>
</html>
