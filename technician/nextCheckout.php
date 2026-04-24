<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/nextcheck_shared.php';

function nexcheck_format_program(string $p): string
{
    switch ($p) {
        case 'academic':
            return 'Academic project / class';
        case 'official_event':
            return 'Official event';
        case 'club_society':
            return 'Club / society activities';
        default:
            return $p;
    }
}

function nexcheck_program_icon(string $p): string
{
    switch ($p) {
        case 'academic':
            return 'ri-book-open-line';
        case 'official_event':
            return 'ri-calendar-event-line';
        case 'club_society':
            return 'ri-team-line';
        default:
            return 'ri-flag-line';
    }
}

function nexcheck_borrow_return_countdown(?string $borrowDateStr, ?string $returnDateStr): array
{
    $bad = ['use' => '—', 'ret' => '—', 'ret_class' => '', 'days_left' => null];
    if (!$borrowDateStr || !$returnDateStr) return $bad;
    $borrow = DateTimeImmutable::createFromFormat('Y-m-d', $borrowDateStr);
    $retDt  = DateTimeImmutable::createFromFormat('Y-m-d', $returnDateStr);
    if (!$borrow || !$retDt) return $bad;
    $today    = new DateTimeImmutable('today');
    $toBorrow = (int)$today->diff($borrow)->format('%r%a');
    $toReturn = (int)$today->diff($retDt)->format('%r%a');

    if ($toBorrow > 1)       $use = 'Starts in ' . $toBorrow . 'd';
    elseif ($toBorrow === 1) $use = 'Starts tomorrow';
    elseif ($toBorrow === 0) $use = 'Starts today';
    elseif ($toReturn >= 0)  $use = 'In use';
    else                     $use = 'Period ended';

    $retClass = '';
    if ($toReturn > 1)       $ret = 'In ' . $toReturn . ' days';
    elseif ($toReturn === 1) $ret = 'Tomorrow';
    elseif ($toReturn === 0) { $ret = 'Today'; $retClass = 'warn'; }
    else                     { $over = -$toReturn; $ret = $over . 'd overdue'; $retClass = 'danger'; }

    return ['use' => $use, 'ret' => $ret, 'ret_class' => $retClass, 'days_left' => $toReturn];
}

function fmt_date(?string $d): string {
    if (!$d) return '—';
    try { return (new DateTimeImmutable($d))->format('d M Y'); } catch (Throwable $e) { return $d; }
}

function get_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $init = '';
    foreach ($parts as $p) { if ($p) $init .= mb_strtoupper(mb_substr($p, 0, 1)); if (strlen($init) >= 2) break; }
    return $init ?: '?';
}

$requests = [];
$db_error = '';
try {
    $pdo = db();
    $retCheckout = 13;
    $avReturnable = nextcheck_checkout_table_exists($pdo, 'av')
        ? " OR EXISTS (SELECT 1 FROM av v WHERE v.asset_id = a.asset_id AND v.status_id = {$retCheckout})"
        : '';
    $requests = $pdo->query("
        SELECT
            r.nexcheck_id, r.requested_by, r.borrow_date, r.return_date,
            r.program_type, r.usage_location, r.reason, r.created_at,
            u.full_name AS requester_name, u.email AS requester_email,
            (SELECT COUNT(*) FROM nexcheck_request_item i WHERE i.nexcheck_id = r.nexcheck_id) AS item_count,
            (SELECT COUNT(*) FROM nexcheck_assignment  a WHERE a.nexcheck_id = r.nexcheck_id) AS assigned_count,
            (SELECT COUNT(*) FROM nexcheck_assignment a
                WHERE a.nexcheck_id = r.nexcheck_id AND a.returned_at IS NULL
                  AND (
                    EXISTS (SELECT 1 FROM laptop l WHERE l.asset_id = a.asset_id AND l.status_id = {$retCheckout})
                    {$avReturnable}
                  )) AS returnable_count
        FROM nexcheck_request r
        JOIN users u ON u.staff_id = r.requested_by
        WHERE r.rejected_at IS NULL
        ORDER BY r.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $db_error = 'Could not load requests. Ensure database tables exist (run db/schema.sql).';
}

// Hide completed/returned requests from this page (use History for those).
$requests = array_values(array_filter($requests, static function (array $r): bool {
    $ic  = (int)($r['item_count'] ?? 0);
    $ac  = (int)($r['assigned_count'] ?? 0);
    $ret = (int)($r['returnable_count'] ?? 0);
    if ($ic <= 0) return false;
    // Keep if still needs assignment, or has something currently in checkout.
    return $ac < $ic || $ret > 0;
}));

$reject_flash = isset($_GET['rejected']) && (string)$_GET['rejected'] === '1';

$total = count($requests);
$needs = $fullyAssigned = $returnPending = 0;
foreach ($requests as $r) {
    $ic = (int)$r['item_count']; $ac = (int)$r['assigned_count'];
    if ($ic > 0 && $ac >= $ic) $fullyAssigned++;
    elseif ($ic > 0 && $ac < $ic) $needs++;
    if ((int)($r['returnable_count'] ?? 0) > 0) $returnPending++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User requests - RCMP NIMS</title>
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
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); min-height: 100vh; display: flex; overflow-x: hidden; }

        /* ── Layout ── */
        .main-content { margin-left: 280px; flex: 1; padding: 2rem 2.5rem 3rem; max-width: calc(100vw - 280px); }
        @media (max-width: 900px) { .main-content { margin-left: 0; max-width: 100vw; padding: 1.25rem 1rem 2.5rem; } }

        /* ── Page header ── */
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.75rem; }
        .page-title { font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; letter-spacing: -0.03em; }
        .page-subtitle { color: var(--text-muted); font-size: 0.875rem; margin-top: 0.3rem; line-height: 1.5; }
        .btn-ghost { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.65rem 1.1rem; border-radius: 12px; border: 1.5px solid var(--card-border); background: var(--card-bg); color: var(--text-muted); font-weight: 700; font-size: 0.88rem; text-decoration: none; transition: all 0.2s; white-space: nowrap; }
        .btn-ghost:hover { border-color: var(--primary); color: var(--primary); }

        /* ── Stat cards ── */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        @media (max-width: 900px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
        .stat-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 16px; padding: 1.1rem 1.25rem; display: flex; align-items: center; gap: 0.9rem; box-shadow: 0 2px 8px rgba(15,23,42,0.05); transition: box-shadow 0.2s, transform 0.2s; }
        .stat-card:hover { box-shadow: 0 6px 20px rgba(15,23,42,0.09); transform: translateY(-1px); }
        .stat-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.2rem; }
        .stat-icon.blue  { background: rgba(37,99,235,0.1);  color: var(--primary); }
        .stat-icon.amber { background: rgba(245,158,11,0.1); color: #b45309; }
        .stat-icon.green { background: rgba(16,185,129,0.1); color: #047857; }
        .stat-icon.sky   { background: rgba(14,165,233,0.1); color: #0369a1; }
        .stat-val { font-family: 'Outfit', sans-serif; font-size: 1.55rem; font-weight: 800; line-height: 1; }
        .stat-label { font-size: 0.73rem; color: var(--text-muted); font-weight: 600; margin-top: 0.2rem; text-transform: uppercase; letter-spacing: 0.04em; }

        /* ── Banner ── */
        .banner { display: flex; align-items: flex-start; gap: 0.65rem; padding: 0.9rem 1.1rem; border-radius: 14px; margin-bottom: 1.25rem; font-size: 0.9rem; font-weight: 600; }
        .banner-error { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.22); color: #b91c1c; }
        .banner-success { background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.22); color: #047857; }

        /* ── Card & toolbar ── */
        .card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 20px; box-shadow: 0 2px 12px rgba(15,23,42,0.06); overflow: hidden; }
        .card-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: 1rem 1.4rem; border-bottom: 1px solid var(--card-border); background: linear-gradient(135deg, #f8fafc, #f1f5f9); }
        .card-toolbar-left { display: flex; align-items: center; gap: 0.6rem; font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 1rem; }
        .card-toolbar-left i { color: var(--primary); font-size: 1.1rem; }
        .count-badge { background: rgba(37,99,235,0.1); color: var(--primary); border: 1px solid rgba(37,99,235,0.2); border-radius: 20px; padding: 0.15rem 0.6rem; font-size: 0.75rem; font-weight: 700; }
        .toolbar-right { display: flex; align-items: center; gap: 0.65rem; }
        .search-wrap { position: relative; }
        .search-wrap i { position: absolute; left: 0.7rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.95rem; pointer-events: none; }
        .search-input { padding: 0.55rem 0.9rem 0.55rem 2.1rem; border: 1.5px solid var(--card-border); border-radius: 10px; font-family: inherit; font-size: 0.85rem; background: #fff; color: var(--text-main); width: 210px; transition: border-color 0.2s, box-shadow 0.2s; }
        .search-input::placeholder { color: #94a3b8; }
        .search-input:focus { outline: none; border-color: rgba(37,99,235,0.45); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .filter-select { padding: 0.55rem 0.8rem; border: 1.5px solid var(--card-border); border-radius: 10px; font-family: inherit; font-size: 0.83rem; font-weight: 600; background: #fff; color: var(--text-main); cursor: pointer; }
        .filter-select:focus { outline: none; border-color: rgba(37,99,235,0.45); }
        @media (max-width: 640px) { .search-input { width: 140px; } .toolbar-right { flex-wrap: wrap; } }

        /* ════════════════════════════════
           TABLE BASE
        ════════════════════════════════ */
        .table-wrap { overflow-x: auto; }
        table.req-table { width: 100%; border-collapse: collapse; }
        .req-table th {
            text-align: left; padding: 0.7rem 1rem;
            background: var(--glass); color: var(--text-muted);
            font-size: 0.64rem; font-weight: 800; letter-spacing: 0.07em;
            text-transform: uppercase; border-bottom: 1px solid var(--card-border); white-space: nowrap;
        }
        .req-table td { padding: 0.85rem 1rem; border-bottom: 1px solid var(--card-border); vertical-align: middle; }
        .req-table tbody tr:last-child td { border-bottom: none; }
        .req-table tbody tr { transition: background 0.15s; }
        .req-table tbody tr:hover td { background: rgba(37,99,235,0.022); }
        .req-table tbody tr.hidden-row { display: none; }

        /* ════════════════════════
           COL 1 — ID
        ════════════════════════ */
        .id-wrap {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 44px; height: 44px;
            border-radius: 12px;
            background: rgba(37,99,235,0.07);
            border: 1px solid rgba(37,99,235,0.15);
        }
        .id-hash { font-size: 0.55rem; font-weight: 800; color: var(--text-muted); letter-spacing: 0.04em; line-height: 1; }
        .id-num  { font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 0.92rem; color: var(--primary-dark); line-height: 1.1; }

        /* ════════════════════════
           COL 2 — Requester
        ════════════════════════ */
        .requester-cell { display: flex; align-items: center; gap: 0.65rem; }
        .req-avatar {
            width: 36px; height: 36px; border-radius: 10px;
            background: linear-gradient(135deg, #021A54, #1e40af);
            color: #fff; font-family: 'Outfit', sans-serif; font-weight: 800;
            font-size: 0.72rem; display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; letter-spacing: 0.02em;
        }
        .req-name  { font-weight: 700; font-size: 0.875rem; line-height: 1.25; }
        .req-email { color: var(--text-muted); font-size: 0.74rem; margin-top: 0.1rem; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* ════════════════════════
           COL 3 — Dates
        ════════════════════════ */
        .dates-cell { white-space: nowrap; min-width: 155px; }
        .dates-range { display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.3rem; }
        .date-chip {
            padding: 0.2rem 0.5rem; border-radius: 7px;
            font-size: 0.76rem; font-weight: 600; white-space: nowrap;
        }
        .date-chip.from { background: rgba(37,99,235,0.07);  border: 1px solid rgba(37,99,235,0.18);  color: var(--primary-dark); }
        .date-chip.to   { background: rgba(16,185,129,0.07); border: 1px solid rgba(16,185,129,0.18); color: #065f46; }
        .dates-arrow { color: var(--text-muted); font-size: 0.65rem; }
        .dates-duration { display: flex; align-items: center; gap: 0.25rem; font-size: 0.7rem; color: var(--text-muted); }
        .dates-duration i { font-size: 0.72rem; }

        /* ════════════════════════
           COL 4 — Program / Location
        ════════════════════════ */
        .program-cell { min-width: 170px; }
        .program-badge {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.25rem 0.6rem; border-radius: 7px;
            font-size: 0.72rem; font-weight: 700; margin-bottom: 0.35rem; white-space: nowrap;
        }
        .program-badge i { font-size: 0.78rem; }
        .prog-academic       { background: rgba(37,99,235,0.08);  color: var(--primary-dark); border: 1px solid rgba(37,99,235,0.2); }
        .prog-official_event { background: rgba(245,158,11,0.1);  color: #92400e;             border: 1px solid rgba(245,158,11,0.22); }
        .prog-club_society   { background: rgba(16,185,129,0.1);  color: #065f46;             border: 1px solid rgba(16,185,129,0.22); }
        .prog-default        { background: rgba(100,116,139,0.1); color: var(--text-muted);   border: 1px solid var(--card-border); }
        .location-row { display: flex; align-items: center; gap: 0.28rem; font-size: 0.77rem; color: var(--text-muted); }
        .location-row i { font-size: 0.78rem; flex-shrink: 0; }
        .location-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 155px; }

        /* ════════════════════════
           COL 5 — Assignment / Progress
        ════════════════════════ */
        .progress-cell { min-width: 145px; }
        .progress-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.4rem; }
        .pill { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.22rem 0.6rem; border-radius: 999px; font-size: 0.67rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }
        .pill.open  { background: rgba(245,158,11,0.12); color: #b45309;  border: 1px solid rgba(245,158,11,0.25); }
        .pill.done  { background: rgba(16,185,129,0.12); color: #047857;  border: 1px solid rgba(16,185,129,0.25); }
        .pill.empty { background: rgba(100,116,139,0.1); color: var(--text-muted); border: 1px solid var(--card-border); }
        .pill i { font-size: 0.68rem; }
        .progress-fraction { font-size: 0.72rem; color: var(--text-muted); font-weight: 700; font-variant-numeric: tabular-nums; }
        .mini-bar-bg { height: 5px; background: var(--card-border); border-radius: 99px; overflow: hidden; }
        .mini-bar-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--primary), var(--primary-light)); transition: width 0.4s; }
        .mini-bar-fill.complete { background: linear-gradient(90deg, var(--success), #34d399); }

        /* ════════════════════════
           COL 6 — Timeline / Countdown
        ════════════════════════ */
        .time-cell { min-width: 140px; }
        .time-block { display: flex; flex-direction: column; gap: 0.32rem; }
        .time-row-item { display: flex; align-items: center; gap: 0.4rem; }
        .time-label { font-size: 0.59rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); min-width: 2.8rem; flex-shrink: 0; }
        .time-chip {
            display: inline-flex; align-items: center; gap: 0.22rem;
            padding: 0.2rem 0.5rem; border-radius: 7px;
            font-size: 0.73rem; font-weight: 700;
            background: var(--glass); border: 1px solid var(--card-border);
            color: var(--text-main); white-space: nowrap;
        }
        .time-chip i { font-size: 0.72rem; }
        .time-chip.future { background: rgba(37,99,235,0.06);  border-color: rgba(37,99,235,0.18);  color: var(--primary-dark); }
        .time-chip.active { background: rgba(16,185,129,0.07); border-color: rgba(16,185,129,0.22); color: #065f46; }
        .time-chip.warn   { background: rgba(245,158,11,0.1);  border-color: rgba(245,158,11,0.28); color: #b45309; }
        .time-chip.danger { background: rgba(239,68,68,0.08);  border-color: rgba(239,68,68,0.25);  color: #b91c1c; }

        /* ════════════════════════
           COL 7 — Submitted
        ════════════════════════ */
        .submitted-cell { white-space: nowrap; min-width: 110px; }
        .submitted-date { font-size: 0.84rem; font-weight: 600; }
        .submitted-time { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.1rem; display: flex; align-items: center; gap: 0.2rem; }
        .submitted-time i { font-size: 0.7rem; }

        /* ════════════════════════
           COL 8 — Actions
        ════════════════════════ */
        .actions-cell { display: flex; flex-direction: column; align-items: flex-start; gap: 0.35rem; }
        .btn-action { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.8rem; border-radius: 9px; font-size: 0.78rem; font-weight: 700; text-decoration: none; transition: all 0.15s; white-space: nowrap; border: 1px solid transparent; cursor: pointer; font-family: inherit; }
        .btn-action.assign { background: rgba(37,99,235,0.09); color: var(--primary); border-color: rgba(37,99,235,0.2); }
        .btn-action.assign:hover { background: var(--primary); color: #fff; }
        .btn-action.ret { background: rgba(16,185,129,0.09); color: #047857; border-color: rgba(16,185,129,0.22); }
        .btn-action.ret:hover { background: var(--success); color: #fff; }

        /* ── Empty states ── */
        .empty-state { padding: 4rem 2rem; text-align: center; color: var(--text-muted); }
        .empty-icon { width: 64px; height: 64px; border-radius: 18px; background: rgba(37,99,235,0.07); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.1rem; }
        .empty-icon i { font-size: 1.75rem; color: rgba(37,99,235,0.4); }
        .empty-state h3 { font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 1.05rem; color: var(--text-main); margin-bottom: 0.4rem; }
        .empty-state p { font-size: 0.88rem; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">

    <header class="page-header">
        <div>
            <h1 class="page-title">User Requests</h1>
            <p class="page-subtitle">Equipment checkout requests from users. Assign pool laptop or Audio Visual and manage returns.</p>
        </div>
        <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center">
            <a class="btn-ghost" href="nextHistory.php"><i class="ri-history-line"></i> History</a>
            <a class="btn-ghost" href="nextAdd.php"><i class="ri-add-line"></i> Add items</a>
        </div>
    </header>

    <?php if ($db_error !== ''): ?>
        <div class="banner banner-error"><i class="ri-error-warning-line"></i> <?= htmlspecialchars($db_error) ?></div>
    <?php endif; ?>
    <?php if ($reject_flash): ?>
        <div class="banner banner-success"><i class="ri-checkbox-circle-line"></i> Request rejected and removed from the active queue.</div>
    <?php endif; ?>

    <div class="stats-row">
        <div class="stat-card"><div class="stat-icon blue"><i class="ri-file-list-3-line"></i></div><div><div class="stat-val"><?= $total ?></div><div class="stat-label">Total requests</div></div></div>
        <div class="stat-card"><div class="stat-icon amber"><i class="ri-time-line"></i></div><div><div class="stat-val"><?= $needs ?></div><div class="stat-label">Needs assets</div></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="ri-checkbox-circle-line"></i></div><div><div class="stat-val"><?= $fullyAssigned ?></div><div class="stat-label">Fully assigned</div></div></div>
        <div class="stat-card"><div class="stat-icon sky"><i class="ri-arrow-go-back-line"></i></div><div><div class="stat-val"><?= $returnPending ?></div><div class="stat-label">Return pending</div></div></div>
    </div>

    <section class="card">
        <div class="card-toolbar">
            <div class="card-toolbar-left">
                <i class="ri-inbox-line"></i> All requests
                <?php if ($total > 0): ?><span class="count-badge"><?= $total ?></span><?php endif; ?>
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
                            <th style="width:56px">ID</th>
                            <th>Requester</th>
                            <th>Dates</th>
                            <th>Program &amp; Location</th>
                            <th>Assignment</th>
                            <th>Timeline</th>
                            <th>Submitted</th>
                            <th style="width:112px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $row):
                        $nid  = (int)$row['nexcheck_id'];
                        $ic   = (int)$row['item_count'];
                        $ac   = (int)$row['assigned_count'];
                        $ret  = (int)($row['returnable_count'] ?? 0);
                        $pct  = $ic > 0 ? round(($ac / $ic) * 100) : 0;
                        $prog = (string)$row['program_type'];

                        if ($ic === 0)      { $pillClass = 'empty'; $pillLabel = 'No lines';     $pillIcon = 'ri-subtract-line'; }
                        elseif ($ac >= $ic) { $pillClass = 'done';  $pillLabel = 'Assigned';     $pillIcon = 'ri-check-line'; }
                        else               { $pillClass = 'open';  $pillLabel = 'Needs assets'; $pillIcon = 'ri-time-line'; }

                        $cd = nexcheck_borrow_return_countdown(
                            $row['borrow_date'] ? (string)$row['borrow_date'] : null,
                            $row['return_date']  ? (string)$row['return_date']  : null
                        );

                        // Duration
                        $durationDays = null;
                        if ($row['borrow_date'] && $row['return_date']) {
                            try {
                                $durationDays = (int)(new DateTimeImmutable((string)$row['borrow_date']))->diff(new DateTimeImmutable((string)$row['return_date']))->days;
                            } catch (Throwable $e) {}
                        }

                        // Submitted split
                        $createdStr   = (string)$row['created_at'];
                        $createdParts = explode(' ', $createdStr);
                        $createdDate  = fmt_date($createdParts[0] ?? null);
                        $createdTime  = isset($createdParts[1]) ? date('h:i A', strtotime($createdParts[1])) : '';

                        // Program badge class
                        switch ($prog) {
                            case 'academic':       $progClass = 'prog-academic'; break;
                            case 'official_event': $progClass = 'prog-official_event'; break;
                            case 'club_society':   $progClass = 'prog-club_society'; break;
                            default:               $progClass = 'prog-default'; break;
                        }

                        // Time chip styles
                        $useChip = '';
                        if (strpos((string)$cd['use'], 'In use') !== false) {
                            $useChip = 'active';
                        } elseif (strpos((string)$cd['use'], 'Starts') !== false) {
                            $useChip = 'future';
                        }

                        switch ((string)$cd['ret_class']) {
                            case 'warn':   $retChip = 'warn'; break;
                            case 'danger': $retChip = 'danger'; break;
                            default:       $retChip = ($cd['days_left'] !== null && $cd['days_left'] > 0 ? '' : ''); break;
                        }
                    ?>
                    <tr data-status="<?= $pillClass ?>"
                        data-search="<?= htmlspecialchars(strtolower((string)$row['requester_name'].' '.(string)$row['usage_location'].' '.(string)$row['requester_email'])) ?>">

                        <!-- COL 1: ID -->
                        <td>
                            <div class="id-wrap">
                                <span class="id-hash">ID</span>
                                <span class="id-num"><?= $nid ?></span>
                            </div>
                        </td>

                        <!-- COL 2: Requester -->
                        <td>
                            <div class="requester-cell">
                                <div class="req-avatar"><?= htmlspecialchars(get_initials((string)$row['requester_name'])) ?></div>
                                <div>
                                    <div class="req-name"><?= htmlspecialchars((string)$row['requester_name']) ?></div>
                                    <div class="req-email" title="<?= htmlspecialchars((string)$row['requester_email']) ?>"><?= htmlspecialchars((string)$row['requester_email']) ?></div>
                                </div>
                            </div>
                        </td>

                        <!-- COL 3: Dates -->
                        <td>
                            <div class="dates-cell">
                                <div class="dates-range">
                                    <span class="date-chip from"><?= htmlspecialchars(fmt_date((string)$row['borrow_date'])) ?></span>
                                    <span class="dates-arrow">→</span>
                                    <span class="date-chip to"><?= htmlspecialchars(fmt_date((string)$row['return_date'])) ?></span>
                                </div>
                                <?php if ($durationDays !== null): ?>
                                <div class="dates-duration">
                                    <i class="ri-calendar-2-line"></i>
                                    <?= $durationDays ?> day<?= $durationDays !== 1 ? 's' : '' ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- COL 4: Program / Location -->
                        <td>
                            <div class="program-cell">
                                <div class="program-badge <?= $progClass ?>">
                                    <i class="<?= htmlspecialchars(nexcheck_program_icon($prog)) ?>"></i>
                                    <?= htmlspecialchars(nexcheck_format_program($prog)) ?>
                                </div>
                                <div class="location-row">
                                    <i class="ri-map-pin-2-line"></i>
                                    <span class="location-text" title="<?= htmlspecialchars((string)$row['usage_location']) ?>"><?= htmlspecialchars((string)$row['usage_location']) ?></span>
                                </div>
                            </div>
                        </td>

                        <!-- COL 5: Assignment progress -->
                        <td class="progress-cell">
                            <div class="progress-header">
                                <span class="pill <?= $pillClass ?>">
                                    <i class="<?= $pillIcon ?>"></i> <?= htmlspecialchars($pillLabel) ?>
                                </span>
                                <span class="progress-fraction"><?= $ac ?>&thinsp;/&thinsp;<?= $ic ?></span>
                            </div>
                            <div class="mini-bar-bg">
                                <div class="mini-bar-fill <?= $pillClass === 'done' ? 'complete' : '' ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                        </td>

                        <!-- COL 6: Timeline countdown -->
                        <td class="time-cell">
                            <div class="time-block">
                                <div class="time-row-item">
                                    <span class="time-label">Use</span>
                                    <span class="time-chip <?= $useChip ?>">
                                        <i class="ri-play-circle-line"></i>
                                        <?= htmlspecialchars($cd['use']) ?>
                                    </span>
                                </div>
                                <div class="time-row-item">
                                    <span class="time-label">Return</span>
                                    <span class="time-chip <?= $retChip ?>">
                                        <i class="ri-corner-up-left-line"></i>
                                        <?= htmlspecialchars($cd['ret']) ?>
                                    </span>
                                </div>
                            </div>
                        </td>

                        <!-- COL 7: Submitted -->
                        <td class="submitted-cell">
                            <div class="submitted-date"><?= htmlspecialchars($createdDate) ?></div>
                            <?php if ($createdTime): ?>
                            <div class="submitted-time"><i class="ri-time-line"></i> <?= htmlspecialchars($createdTime) ?></div>
                            <?php endif; ?>
                        </td>

                        <!-- COL 8: Actions -->
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
    var tbody        = document.querySelector('#requestsTable tbody');
    if (!tbody) return;

    function filterRows() {
        var q      = searchInput  ? searchInput.value.trim().toLowerCase()  : '';
        var status = statusFilter ? statusFilter.value : '';
        var rows   = tbody.querySelectorAll('tr');
        var visible = 0;
        rows.forEach(function (tr) {
            var match = (q === '' || (tr.getAttribute('data-search') || '').includes(q))
                     && (status === '' || tr.getAttribute('data-status') === status);
            tr.classList.toggle('hidden-row', !match);
            if (match) visible++;
        });
        if (noResults) noResults.style.display = visible === 0 ? 'block' : 'none';
    }

    if (searchInput)  searchInput.addEventListener('input', filterRows);
    if (statusFilter) statusFilter.addEventListener('change', filterRows);
})();
</script>
</body>
</html>