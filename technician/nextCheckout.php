<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function nexcheck_format_program(string $p): string
{
    return match ($p) {
        'academic'       => 'Academic project / class',
        'official_event' => 'Official event',
        'club_society'   => 'Club / society activities',
        default          => $p,
    };
}

$requests = [];
$db_error = '';
try {
    $pdo = db();
    $requests = $pdo->query("
        SELECT
            r.nexcheck_id,
            r.requested_by,
            r.borrow_date,
            r.return_date,
            r.program_type,
            r.usage_location,
            r.reason,
            r.created_at,
            u.full_name  AS requester_name,
            u.email      AS requester_email,
            (SELECT COUNT(*) FROM nexcheck_request_item i WHERE i.nexcheck_id = r.nexcheck_id) AS item_count,
            (SELECT COUNT(*) FROM nexcheck_assignment  a WHERE a.nexcheck_id = r.nexcheck_id) AS assigned_count,
            (SELECT COUNT(*) FROM nexcheck_assignment  a
                INNER JOIN laptop l ON l.asset_id = a.asset_id
                WHERE a.nexcheck_id = r.nexcheck_id
                  AND a.returned_at IS NULL
                  AND l.status_id = 13) AS returnable_count
        FROM nexcheck_request r
        JOIN users u ON u.staff_id = r.requested_by
        ORDER BY r.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $db_error = 'Could not load requests. Ensure database tables exist (run db/schema.sql).';
}

// Summary counts
$total   = count($requests);
$needs   = 0;
$fullyAssigned = 0;
$returnPending = 0;
foreach ($requests as $r) {
    $ic  = (int)$r['item_count'];
    $ac  = (int)$r['assigned_count'];
    $ret = (int)($r['returnable_count'] ?? 0);
    if ($ic > 0 && $ac >= $ic) $fullyAssigned++;
    elseif ($ic > 0 && $ac < $ic) $needs++;
    if ($ret > 0) $returnPending++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User requests — NextCheck — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary:       #2563eb;
            --primary-light: #3b82f6;
            --primary-dark:  #1d4ed8;
            --secondary:     #0ea5e9;
            --success:       #10b981;
            --warning:       #f59e0b;
            --danger:        #ef4444;
            --bg:            #f1f5f9;
            --card-bg:       #ffffff;
            --card-border:   #e2e8f0;
            --text-main:     #0f172a;
            --text-muted:    #64748b;
            --glass:         #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }

        /* ── Main layout ── */
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2rem 2.5rem 3rem;
            max-width: calc(100vw - 280px);
        }
        @media (max-width: 900px) {
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.25rem 1rem 2.5rem; }
        }

        /* ── Page header ── */
        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.75rem;
        }
        .page-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--text-main);
        }
        .page-subtitle {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-top: 0.3rem;
            line-height: 1.5;
        }
        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.65rem 1.1rem;
            border-radius: 12px;
            border: 1.5px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-muted);
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-ghost:hover { border-color: var(--primary); color: var(--primary); }

        /* ── Summary stat cards ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 900px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .stats-row { grid-template-columns: 1fr 1fr; } }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1.1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.9rem;
            box-shadow: 0 2px 8px rgba(15,23,42,0.05);
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .stat-card:hover { box-shadow: 0 6px 20px rgba(15,23,42,0.09); transform: translateY(-1px); }
        .stat-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.2rem;
        }
        .stat-icon.blue   { background: rgba(37,99,235,0.1);  color: var(--primary); }
        .stat-icon.amber  { background: rgba(245,158,11,0.1); color: #b45309; }
        .stat-icon.green  { background: rgba(16,185,129,0.1); color: #047857; }
        .stat-icon.sky    { background: rgba(14,165,233,0.1); color: #0369a1; }
        .stat-val {
            font-family: 'Outfit', sans-serif;
            font-size: 1.55rem;
            font-weight: 800;
            line-height: 1;
            color: var(--text-main);
        }
        .stat-label {
            font-size: 0.73rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 0.2rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* ── Banner ── */
        .banner {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            padding: 0.9rem 1.1rem;
            border-radius: 14px;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .banner i { font-size: 1.1rem; flex-shrink: 0; }
        .banner-error { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.22); color: #b91c1c; }

        /* ── Main card ── */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            overflow: hidden;
        }
        .card-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            padding: 1rem 1.4rem;
            border-bottom: 1px solid var(--card-border);
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        }
        .card-toolbar-left {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1rem;
        }
        .card-toolbar-left i { color: var(--primary); font-size: 1.1rem; }
        .count-badge {
            background: rgba(37,99,235,0.1);
            color: var(--primary);
            border: 1px solid rgba(37,99,235,0.2);
            border-radius: 20px;
            padding: 0.15rem 0.6rem;
            font-size: 0.75rem;
            font-weight: 700;
        }

        /* Search + filter */
        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }
        .search-wrap {
            position: relative;
        }
        .search-wrap i {
            position: absolute;
            left: 0.7rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.95rem;
            pointer-events: none;
        }
        .search-input {
            padding: 0.55rem 0.9rem 0.55rem 2.1rem;
            border: 1.5px solid var(--card-border);
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.85rem;
            background: #fff;
            color: var(--text-main);
            width: 200px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .search-input::placeholder { color: #94a3b8; }
        .search-input:focus { outline: none; border-color: rgba(37,99,235,0.45); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }

        .filter-select {
            padding: 0.55rem 0.8rem;
            border: 1.5px solid var(--card-border);
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.83rem;
            font-weight: 600;
            background: #fff;
            color: var(--text-main);
            cursor: pointer;
            transition: border-color 0.2s;
        }
        .filter-select:focus { outline: none; border-color: rgba(37,99,235,0.45); }

        @media (max-width: 640px) {
            .search-input { width: 140px; }
            .toolbar-right { flex-wrap: wrap; }
        }

        /* ── Table ── */
        .table-wrap { overflow-x: auto; }
        table.req-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .req-table thead tr {
            position: sticky;
            top: 0;
        }
        .req-table th {
            text-align: left;
            padding: 0.7rem 1.1rem;
            background: var(--glass);
            color: var(--text-muted);
            font-size: 0.67rem;
            font-weight: 800;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            border-bottom: 1px solid var(--card-border);
            white-space: nowrap;
        }
        .req-table td {
            padding: 0.95rem 1.1rem;
            border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
        }
        .req-table tbody tr:last-child td { border-bottom: none; }
        .req-table tbody tr {
            transition: background 0.15s;
        }
        .req-table tbody tr:hover td { background: rgba(37,99,235,0.025); }

        /* ID cell */
        .cell-id {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
            color: var(--text-main);
            white-space: nowrap;
        }
        .cell-id-hash { color: var(--text-muted); font-weight: 600; }

        /* Requester cell */
        .requester-name { font-weight: 700; font-size: 0.88rem; }
        .requester-email {
            color: var(--text-muted);
            font-size: 0.78rem;
            margin-top: 0.15rem;
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Dates cell */
        .dates-wrap { white-space: nowrap; }
        .date-from { font-weight: 600; font-size: 0.85rem; }
        .date-arrow { color: var(--text-muted); margin: 0 0.3rem; font-size: 0.75rem; }
        .date-to { font-weight: 600; font-size: 0.85rem; }
        .date-duration {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 0.2rem;
        }

        /* Program cell */
        .program-name { font-weight: 600; font-size: 0.85rem; }
        .program-location {
            color: var(--text-muted);
            font-size: 0.78rem;
            margin-top: 0.15rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .program-location i { font-size: 0.8rem; }

        /* Progress cell */
        .progress-cell { min-width: 130px; }
        .progress-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.35rem;
            gap: 0.5rem;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.22rem 0.6rem;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }
        .pill.open  { background: rgba(245,158,11,0.12); color: #b45309;  border: 1px solid rgba(245,158,11,0.25); }
        .pill.done  { background: rgba(16,185,129,0.12); color: #047857;  border: 1px solid rgba(16,185,129,0.25); }
        .pill.empty { background: rgba(100,116,139,0.1); color: var(--text-muted); border: 1px solid var(--card-border); }
        .pill i { font-size: 0.7rem; }
        .progress-fraction {
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 600;
            white-space: nowrap;
        }
        .mini-bar-bg {
            height: 5px;
            background: var(--card-border);
            border-radius: 99px;
            overflow: hidden;
        }
        .mini-bar-fill {
            height: 100%;
            border-radius: 99px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            transition: width 0.4s;
        }
        .mini-bar-fill.complete { background: linear-gradient(90deg, var(--success), #34d399); }

        /* Submitted cell */
        .submitted-date { font-size: 0.83rem; font-weight: 600; white-space: nowrap; }
        .submitted-time { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.12rem; }

        /* Actions cell */
        .actions-cell {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.4rem;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.42rem 0.8rem;
            border-radius: 9px;
            font-size: 0.8rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.15s;
            white-space: nowrap;
            font-family: inherit;
            border: none;
            cursor: pointer;
        }
        .btn-action.assign {
            background: rgba(37,99,235,0.1);
            color: var(--primary);
            border: 1px solid rgba(37,99,235,0.2);
        }
        .btn-action.assign:hover { background: var(--primary); color: #fff; }
        .btn-action.ret {
            background: rgba(16,185,129,0.1);
            color: #047857;
            border: 1px solid rgba(16,185,129,0.22);
        }
        .btn-action.ret:hover { background: var(--success); color: #fff; }

        /* Empty state */
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
            color: var(--text-muted);
        }
        .empty-icon {
            width: 64px; height: 64px;
            border-radius: 18px;
            background: rgba(37,99,235,0.07);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.1rem;
        }
        .empty-icon i { font-size: 1.75rem; color: rgba(37,99,235,0.4); }
        .empty-state h3 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--text-main);
            margin-bottom: 0.4rem;
        }
        .empty-state p { font-size: 0.88rem; }

        /* Hidden rows (filter) */
        .req-table tbody tr.hidden-row { display: none; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">

    <!-- Header -->
    <header class="page-header">
        <div>
            <h1 class="page-title">User Requests</h1>
            <p class="page-subtitle">Equipment checkout requests from NextCheck users — assign pool laptops and manage returns.</p>
        </div>
        <a class="btn-ghost" href="nextAdd.php"><i class="ri-add-line"></i> Add items</a>
    </header>

    <!-- Error banner -->
    <?php if ($db_error !== ''): ?>
        <div class="banner banner-error"><i class="ri-error-warning-line"></i> <?= htmlspecialchars($db_error) ?></div>
    <?php endif; ?>

    <!-- Stat cards -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="ri-file-list-3-line"></i></div>
            <div>
                <div class="stat-val"><?= $total ?></div>
                <div class="stat-label">Total requests</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="ri-time-line"></i></div>
            <div>
                <div class="stat-val"><?= $needs ?></div>
                <div class="stat-label">Needs assets</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="ri-checkbox-circle-line"></i></div>
            <div>
                <div class="stat-val"><?= $fullyAssigned ?></div>
                <div class="stat-label">Fully assigned</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon sky"><i class="ri-arrow-go-back-line"></i></div>
            <div>
                <div class="stat-val"><?= $returnPending ?></div>
                <div class="stat-label">Return pending</div>
            </div>
        </div>
    </div>

    <!-- Requests table card -->
    <section class="card">
        <div class="card-toolbar">
            <div class="card-toolbar-left">
                <i class="ri-inbox-line"></i>
                All requests
                <?php if ($total > 0): ?>
                    <span class="count-badge"><?= $total ?></span>
                <?php endif; ?>
            </div>
            <?php if ($requests !== []): ?>
            <div class="toolbar-right">
                <div class="search-wrap">
                    <i class="ri-search-line"></i>
                    <input type="text" class="search-input" id="tableSearch" placeholder="Search name, location…">
                </div>
                <select class="filter-select" id="statusFilter">
                    <option value="">All statuses</option>
                    <option value="open">Needs assets</option>
                    <option value="done">Assigned</option>
                    <option value="empty">No lines</option>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($requests === [] && $db_error === ''): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="ri-inbox-archive-line"></i></div>
                <h3>No requests yet</h3>
                <p>Users submit equipment requests from the NextCheck form. They'll appear here.</p>
            </div>

        <?php elseif ($requests !== []): ?>
            <div class="table-wrap">
                <table class="req-table" id="requestsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Requester</th>
                            <th>Dates</th>
                            <th>Program / Location</th>
                            <th>Progress</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $row):
                        $nid = (int)$row['nexcheck_id'];
                        $ic  = (int)$row['item_count'];
                        $ac  = (int)$row['assigned_count'];
                        $ret = (int)($row['returnable_count'] ?? 0);
                        $pct = $ic > 0 ? round(($ac / $ic) * 100) : 0;

                        if ($ic === 0)          { $pillClass = 'empty'; $pillLabel = 'No lines';     $pillIcon = 'ri-subtract-line'; }
                        elseif ($ac >= $ic)     { $pillClass = 'done';  $pillLabel = 'Assigned';     $pillIcon = 'ri-check-line'; }
                        else                    { $pillClass = 'open';  $pillLabel = 'Needs assets'; $pillIcon = 'ri-time-line'; }

                        // Format dates
                        $bd = $row['borrow_date'];
                        $rd = $row['return_date'];
                        $createdAt = (string)$row['created_at'];
                        $dateParts = explode(' ', $createdAt);
                        $dateOnly  = $dateParts[0] ?? $createdAt;
                        $timeOnly  = $dateParts[1] ?? '';
                    ?>
                    <tr data-status="<?= $pillClass ?>"
                        data-search="<?= htmlspecialchars(strtolower((string)$row['requester_name'] . ' ' . (string)$row['usage_location'] . ' ' . (string)$row['requester_email'])) ?>">

                        <!-- ID -->
                        <td>
                            <span class="cell-id">
                                <span class="cell-id-hash">#</span><?= $nid ?>
                            </span>
                        </td>

                        <!-- Requester -->
                        <td>
                            <div class="requester-name"><?= htmlspecialchars((string)$row['requester_name']) ?></div>
                            <div class="requester-email" title="<?= htmlspecialchars((string)$row['requester_email']) ?>">
                                <?= htmlspecialchars((string)$row['requester_email']) ?>
                            </div>
                        </td>

                        <!-- Dates -->
                        <td>
                            <div class="dates-wrap">
                                <span class="date-from"><?= htmlspecialchars($bd) ?></span>
                                <span class="date-arrow">→</span>
                                <span class="date-to"><?= htmlspecialchars($rd) ?></span>
                            </div>
                        </td>

                        <!-- Program / location -->
                        <td>
                            <div class="program-name"><?= htmlspecialchars(nexcheck_format_program((string)$row['program_type'])) ?></div>
                            <div class="program-location">
                                <i class="ri-map-pin-2-line"></i>
                                <?= htmlspecialchars((string)$row['usage_location']) ?>
                            </div>
                        </td>

                        <!-- Progress -->
                        <td class="progress-cell">
                            <div class="progress-top">
                                <span class="pill <?= $pillClass ?>">
                                    <i class="<?= $pillIcon ?>"></i>
                                    <?= htmlspecialchars($pillLabel) ?>
                                </span>
                                <span class="progress-fraction"><?= $ac ?>/<?= $ic ?></span>
                            </div>
                            <div class="mini-bar-bg">
                                <div class="mini-bar-fill <?= $pillClass === 'done' ? 'complete' : '' ?>"
                                     style="width:<?= $pct ?>%"></div>
                            </div>
                        </td>

                        <!-- Submitted -->
                        <td>
                            <div class="submitted-date"><?= htmlspecialchars($dateOnly) ?></div>
                            <?php if ($timeOnly): ?>
                                <div class="submitted-time"><?= htmlspecialchars($timeOnly) ?></div>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td>
                            <div class="actions-cell">
                                <a class="btn-action assign" href="nextItems.php?nexcheck_id=<?= $nid ?>">
                                    <i class="ri-links-line"></i> Assign
                                </a>
                                <?php if ($ret > 0): ?>
                                <a class="btn-action ret" href="nextItems.php?nexcheck_id=<?= $nid ?>#nexcheck-return">
                                    <i class="ri-arrow-go-back-line"></i> Return (<?= $ret ?>)
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>

                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- No results row (JS-driven) -->
            <div id="noResults" style="display:none;padding:3rem 2rem;text-align:center;color:var(--text-muted)">
                <i class="ri-search-line" style="font-size:1.5rem;opacity:0.4;display:block;margin-bottom:0.5rem"></i>
                No requests match your search or filter.
            </div>
        <?php endif; ?>
    </section>

</main>

<script>
(function () {
    var searchInput  = document.getElementById('tableSearch');
    var statusFilter = document.getElementById('statusFilter');
    var noResults    = document.getElementById('noResults');
    var tbody = document.querySelector('#requestsTable tbody');
    if (!tbody) return;

    function filterRows() {
        var q      = (searchInput ? searchInput.value.trim().toLowerCase() : '');
        var status = (statusFilter ? statusFilter.value : '');
        var rows   = tbody.querySelectorAll('tr');
        var visible = 0;

        rows.forEach(function (tr) {
            var searchData = (tr.getAttribute('data-search') || '').toLowerCase();
            var rowStatus  = tr.getAttribute('data-status') || '';
            var matchQ      = q === '' || searchData.includes(q);
            var matchStatus = status === '' || rowStatus === status;

            if (matchQ && matchStatus) {
                tr.classList.remove('hidden-row');
                visible++;
            } else {
                tr.classList.add('hidden-row');
            }
        });

        if (noResults) noResults.style.display = visible === 0 ? 'block' : 'none';
    }

    if (searchInput)  searchInput.addEventListener('input', filterRows);
    if (statusFilter) statusFilter.addEventListener('change', filterRows);
})();
</script>
</body>
</html>