<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 2) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

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
        SELECT id, employee_no, full_name, email, department, phone, created_at
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
        'name' => $u['full_name'],
        'identifier' => $u['staff_id'],
        'email' => $u['email'] ?? '',
        'notes' => 'System account',
        'since' => $u['created_at'] ?? null,
    ];
}
foreach ($technicians as $u) {
    $peopleRows[] = [
        'kind' => 'technician',
        'name' => $u['full_name'],
        'identifier' => $u['staff_id'],
        'email' => $u['email'] ?? '',
        'notes' => 'System account',
        'since' => $u['created_at'] ?? null,
    ];
}
if ($staffTableOk) {
    foreach ($staffDirectory as $s) {
        $ident = ($s['employee_no'] ?? '') !== '' ? $s['employee_no'] : ('#' . (int)$s['id']);
        $notesParts = array_filter([trim((string)($s['department'] ?? '')), trim((string)($s['phone'] ?? ''))], fn($x) => $x !== '');
        $peopleRows[] = [
            'kind' => 'staff',
            'name' => $s['full_name'],
            'identifier' => $ident,
            'email' => $s['email'] ?? '',
            'notes' => $notesParts ? implode(' · ', $notesParts) : '—',
            'since' => $s['created_at'] ?? null,
        ];
    }
}
usort($peopleRows, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$countAll = count($peopleRows);
$countAdmin = count($admins);
$countTech = count($technicians);
$countStaff = $staffTableOk ? count($staffDirectory) : 0;
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
        .filter-btn .ct {
            font-family: 'Outfit', sans-serif;
            font-size: 0.78rem;
            opacity: 0.85;
            font-weight: 900;
        }
        .table-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 700;
        }
        .table-meta strong { color: var(--text-main); }

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
        .data-table tr.is-hidden { display: none; }
        .mono { font-family: ui-monospace, monospace; font-size: 0.82rem; }
        .cell-main { font-weight: 800; color: var(--text-main); }
        .cell-sub { font-size: 0.78rem; color: var(--text-muted); margin-top: 0.15rem; font-weight: 600; }
        .type-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.72rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .type-pill.admin { background: rgba(239,68,68,0.1); color: #dc2626; border: 1px solid rgba(239,68,68,0.25); }
        .type-pill.technician { background: rgba(37,99,235,0.1); color: var(--primary); border: 1px solid rgba(37,99,235,0.22); }
        .type-pill.staff { background: rgba(16,185,129,0.1); color: #059669; border: 1px solid rgba(16,185,129,0.25); }
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

        <div id="uiNotice" class="ui-notice" role="status" aria-live="polite">
            <i class="ri-information-line"></i>
            <span id="uiNoticeText"></span>
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
                        <button type="button" class="action-dropdown-item" onclick="showUiNotice('Add technician — form wiring comes next (UI preview only).')">
                            <i class="ri-tools-line" style="color:var(--primary);"></i> Add technician
                        </button>
                        <div class="action-dropdown-hint">Directory</div>
                        <button type="button" class="action-dropdown-item" onclick="showUiNotice('Add staff (directory) — form wiring comes next (UI preview only).')">
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
                    <button type="button" class="filter-btn active" data-filter="all">All <span class="ct"><?= (int)$countAll ?></span></button>
                    <button type="button" class="filter-btn" data-filter="admin">Administrator <span class="ct"><?= (int)$countAdmin ?></span></button>
                    <button type="button" class="filter-btn" data-filter="technician">Technician <span class="ct"><?= (int)$countTech ?></span></button>
                    <button type="button" class="filter-btn" data-filter="staff">Staff <span class="ct"><?= (int)$countStaff ?></span></button>
                </div>
                <div class="table-meta">Showing <strong id="visibleCount"><?= (int)$countAll ?></strong> of <strong><?= (int)$countAll ?></strong></div>
            </div>
            <div class="table-responsive">
                <table class="data-table" id="peopleTable">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Name</th>
                            <th>ID</th>
                            <th>Email</th>
                            <th>Notes</th>
                            <th>Since</th>
                        </tr>
                    </thead>
                    <tbody id="peopleTbody">
                        <?php if (empty($peopleRows)): ?>
                            <tr><td colspan="6" class="empty-cell">No people to show.</td></tr>
                        <?php else: foreach ($peopleRows as $p):
                            $kind = $p['kind'];
                            $typeLabel = match ($kind) {
                                'admin' => 'Administrator',
                                'technician' => 'Technician',
                                'staff' => 'Staff',
                                default => $kind,
                            };
                            $since = !empty($p['since']) ? date('M j, Y', strtotime($p['since'])) : '—';
                        ?>
                            <tr data-kind="<?= htmlspecialchars($kind) ?>">
                                <td><span class="type-pill <?= htmlspecialchars($kind === 'technician' ? 'technician' : ($kind === 'staff' ? 'staff' : 'admin')) ?>"><?= htmlspecialchars($typeLabel) ?></span></td>
                                <td><div class="cell-main"><?= htmlspecialchars($p['name']) ?></div></td>
                                <td class="mono"><?= htmlspecialchars($p['identifier']) ?></td>
                                <td><?= htmlspecialchars($p['email'] !== '' ? $p['email'] : '—') ?></td>
                                <td><span class="cell-sub" style="display:block;max-width:280px;white-space:normal;"><?= htmlspecialchars($p['notes']) ?></span></td>
                                <td class="cell-sub"><?= htmlspecialchars($since) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
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

        (function () {
            const tbody = document.getElementById('peopleTbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr[data-kind]'));
            const filters = Array.from(document.querySelectorAll('.filter-btn[data-filter]'));
            const visibleEl = document.getElementById('visibleCount');
            function applyFilter(kind) {
                let n = 0;
                rows.forEach(r => {
                    const show = kind === 'all' || r.getAttribute('data-kind') === kind;
                    r.classList.toggle('is-hidden', !show);
                    if (show) n++;
                });
                if (visibleEl) visibleEl.textContent = String(n);
                filters.forEach(b => b.classList.toggle('active', b.getAttribute('data-filter') === kind));
            }
            filters.forEach(b => b.addEventListener('click', () => applyFilter(b.getAttribute('data-filter'))));
            applyFilter('all');
        })();
    </script>
</body>
</html>
