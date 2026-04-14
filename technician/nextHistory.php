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
    return match ($p) {
        'academic'       => 'Academic project / class',
        'official_event' => 'Official event',
        'club_society'   => 'Club / society activities',
        default          => $p,
    };
}

function fmt_dt(?string $d): string
{
    if (!$d) return '—';
    try { return (new DateTimeImmutable($d))->format('d M Y, h:i A'); } catch (Throwable $e) { return (string)$d; }
}

function fmt_date(?string $d): string
{
    if (!$d) return '—';
    try { return (new DateTimeImmutable($d))->format('d M Y'); } catch (Throwable $e) { return (string)$d; }
}

$q       = trim((string)($_GET['q'] ?? ''));
$from    = trim((string)($_GET['from'] ?? ''));
$to      = trim((string)($_GET['to'] ?? ''));
$fromOk  = $from === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from);
$toOk    = $to === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to);
$qLike   = '%' . $q . '%';

$rows = [];
$db_error = '';
try {
    $pdo = db();
    $hasAv = nextcheck_checkout_table_exists($pdo, 'av');

    $where = [];
    $args  = [];
    if ($q !== '') {
        $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR r.usage_location LIKE ? OR CAST(r.nexcheck_id AS CHAR) LIKE ?)';
        $args[] = $qLike; $args[] = $qLike; $args[] = $qLike; $args[] = $qLike;
    }
    if ($fromOk && $from !== '') {
        $where[] = 'DATE(r.created_at) >= ?';
        $args[] = $from;
    }
    if ($toOk && $to !== '') {
        $where[] = 'DATE(r.created_at) <= ?';
        $args[] = $to;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            r.nexcheck_id,
            r.borrow_date,
            r.return_date,
            r.program_type,
            r.usage_location,
            r.created_at,
            u.full_name AS requester_name,
            u.email     AS requester_email,
            (SELECT GROUP_CONCAT(DISTINCT tu.full_name ORDER BY tu.full_name SEPARATOR ', ')
                FROM nexcheck_assignment a
                JOIN users tu ON tu.staff_id = a.assigned_by
                WHERE a.nexcheck_id = r.nexcheck_id
            ) AS checkout_techs,
            (SELECT GROUP_CONCAT(DISTINCT ru.full_name ORDER BY ru.full_name SEPARATOR ', ')
                FROM nexcheck_assignment a
                JOIN users ru ON ru.staff_id = a.returned_by
                WHERE a.nexcheck_id = r.nexcheck_id AND a.returned_by IS NOT NULL
            ) AS return_techs,
            (SELECT GROUP_CONCAT(DISTINCT a.return_condition ORDER BY a.returned_at DESC SEPARATOR ' | ')
                FROM nexcheck_assignment a
                WHERE a.nexcheck_id = r.nexcheck_id
                  AND a.returned_at IS NOT NULL
                  AND a.return_condition IS NOT NULL
                  AND a.return_condition <> ''
            ) AS return_remarks,
            (SELECT COUNT(*) FROM nexcheck_request_item i WHERE i.nexcheck_id = r.nexcheck_id) AS item_count
        FROM nexcheck_request r
        JOIN users u ON u.staff_id = r.requested_by
        $whereSql
        ORDER BY r.created_at DESC
        LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $db_error = 'Could not load history.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History — NextCheck — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --bg: #f1f5f9;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass: #f8fafc;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); min-height: 100vh; display:flex; overflow-x:hidden; }
        .main-content { margin-left: 280px; flex: 1; padding: 2rem 2.5rem 3rem; max-width: calc(100vw - 280px); }
        @media (max-width: 900px) { .main-content { margin-left: 0; max-width: 100vw; padding: 1.25rem 1rem 2.5rem; } }

        .page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; }
        .page-title { font-family:'Outfit', sans-serif; font-size:1.75rem; font-weight:800; letter-spacing:-0.03em; }
        .page-subtitle { color: var(--text-muted); font-size:0.875rem; margin-top:0.3rem; line-height:1.5; }

        .btn-ghost { display:inline-flex; align-items:center; gap:0.45rem; padding:0.65rem 1.1rem; border-radius:12px; border:1.5px solid var(--card-border); background:var(--card-bg); color:var(--text-muted); font-weight:700; font-size:0.88rem; cursor:pointer; text-decoration:none; transition:all 0.2s; white-space:nowrap; }
        .btn-ghost:hover { border-color: var(--primary); color: var(--primary); }

        .card { background:var(--card-bg); border:1px solid var(--card-border); border-radius:20px; box-shadow:0 2px 12px rgba(15,23,42,0.06); overflow:hidden; }
        .toolbar { padding: 1rem 1.4rem; border-bottom: 1px solid var(--card-border); background: linear-gradient(135deg, #f8fafc, #f1f5f9); display:flex; gap:0.75rem; align-items:end; flex-wrap:wrap; }
        .tool { display:flex; flex-direction:column; gap:0.3rem; }
        .tool label { font-size:0.66rem; font-weight:800; text-transform:uppercase; letter-spacing:0.06em; color: var(--text-muted); }
        .in { padding: 0.55rem 0.85rem; border:1.5px solid var(--card-border); border-radius: 10px; font-family:inherit; font-size:0.85rem; background:#fff; min-width: 200px; }
        .in:focus { outline:none; border-color: rgba(37,99,235,0.45); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .in.date { min-width: 150px; }
        .toolbar .actions { margin-left:auto; display:flex; gap:0.6rem; flex-wrap:wrap; }

        .banner { margin: 1rem 0 0; padding:0.9rem 1.1rem; border-radius:14px; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.22); color:#b91c1c; font-weight:600; display:flex; gap:0.65rem; align-items:flex-start; }
        .banner i { margin-top: 0.05rem; }

        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.88rem; }
        th { text-align:left; padding:0.7rem 1.1rem; background:var(--glass); color:var(--text-muted); font-size:0.67rem; font-weight:800; letter-spacing:0.07em; text-transform:uppercase; border-bottom:1px solid var(--card-border); white-space:nowrap; }
        td { padding:0.95rem 1.1rem; border-bottom:1px solid var(--card-border); vertical-align:middle; }
        tr:last-child td { border-bottom:none; }

        .id { font-family:'Outfit', sans-serif; font-weight:800; }
        .muted { color: var(--text-muted); font-size: 0.78rem; margin-top:0.15rem; }
        .chips { display:flex; flex-wrap:wrap; gap:0.35rem; }
        .chip { display:inline-flex; align-items:center; gap:0.35rem; padding:0.22rem 0.6rem; border-radius:999px; font-size:0.72rem; font-weight:800; letter-spacing:0.02em; border:1px solid var(--card-border); background:#fff; }
        .chip.done { border-color: rgba(16,185,129,0.28); background: rgba(16,185,129,0.08); color:#047857; }
        .chip.warn { border-color: rgba(245,158,11,0.28); background: rgba(245,158,11,0.08); color:#92400e; }
        .chip.bad  { border-color: rgba(239,68,68,0.28); background: rgba(239,68,68,0.08); color:#b91c1c; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div>
            <h1 class="page-title">History</h1>
            <p class="page-subtitle">All NextCheck requests and their current progress (assignments, checkout, returns).</p>
        </div>
        <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center">
            <a class="btn-ghost" href="nextCheckout.php"><i class="ri-arrow-left-line"></i> Back</a>
            <a class="btn-ghost" href="nextAdd.php"><i class="ri-add-line"></i> Add items</a>
        </div>
    </header>

    <section class="card">
        <form class="toolbar" method="get" action="">
            <div class="tool">
                <label for="q">Search</label>
                <input class="in" id="q" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Name, email, location, request id">
            </div>
            <div class="tool">
                <label for="from">From</label>
                <input class="in date" type="date" id="from" name="from" value="<?= htmlspecialchars($fromOk ? $from : '') ?>">
            </div>
            <div class="tool">
                <label for="to">To</label>
                <input class="in date" type="date" id="to" name="to" value="<?= htmlspecialchars($toOk ? $to : '') ?>">
            </div>
            <div class="actions">
                <button class="btn-ghost" type="submit"><i class="ri-filter-3-line"></i> Apply</button>
                <a class="btn-ghost" href="nextHistory.php"><i class="ri-close-line"></i> Reset</a>
            </div>
        </form>

        <?php if ($db_error !== ''): ?>
            <div class="banner"><i class="ri-error-warning-line"></i> <?= htmlspecialchars($db_error) ?></div>
        <?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Requester</th>
                        <th>Borrow → Return</th>
                        <th>Program / Location</th>
                        <th>Technician</th>
                        <th>Submitted</th>
                        <th>Return remark</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" style="color:var(--text-muted);padding:1.4rem">No history rows found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $nid = (int)$r['nexcheck_id'];
                        $ic  = (int)($r['item_count'] ?? 0);
                    ?>
                    <tr>
                        <td><div class="id">#<?= $nid ?></div></td>
                        <td>
                            <div style="font-weight:700"><?= htmlspecialchars((string)$r['requester_name']) ?></div>
                            <div class="muted" title="<?= htmlspecialchars((string)$r['requester_email']) ?>"><?= htmlspecialchars((string)$r['requester_email']) ?></div>
                        </td>
                        <td style="white-space:nowrap">
                            <div style="font-weight:700"><?= htmlspecialchars(fmt_date((string)$r['borrow_date'])) ?> → <?= htmlspecialchars(fmt_date((string)$r['return_date'])) ?></div>
                            <div class="muted"><?= $ic ?> line<?= $ic !== 1 ? 's' : '' ?></div>
                        </td>
                        <td>
                            <div style="font-weight:700"><?= htmlspecialchars(nexcheck_format_program((string)$r['program_type'])) ?></div>
                            <div class="muted"><?= htmlspecialchars((string)$r['usage_location']) ?></div>
                        </td>
                        <td style="min-width:220px">
                            <div style="font-weight:700">
                                <?= htmlspecialchars((string)($r['checkout_techs'] ?? '—')) ?>
                            </div>
                            <div class="muted">Checkout</div>
                            <div style="margin-top:0.45rem;font-weight:700">
                                <?= htmlspecialchars((string)($r['return_techs'] ?? '—')) ?>
                            </div>
                            <div class="muted">Return</div>
                        </td>
                        <td style="white-space:nowrap">
                            <div style="font-weight:700"><?= htmlspecialchars(fmt_dt((string)$r['created_at'])) ?></div>
                        </td>
                        <td>
                            <?php
                                $rr = trim((string)($r['return_remarks'] ?? ''));
                                if ($rr === '') $rr = '—';
                                $rrShort = mb_substr($rr, 0, 140);
                                $rrMore  = mb_strlen($rr) > 140;
                            ?>
                            <div style="font-weight:600" title="<?= htmlspecialchars($rr) ?>">
                                <?= htmlspecialchars($rrShort) ?><?= $rrMore ? '…' : '' ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>