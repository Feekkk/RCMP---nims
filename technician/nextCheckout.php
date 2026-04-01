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
        'academic' => 'Academic project / class',
        'official_event' => 'Official event',
        'club_society' => 'Club / society activities',
        default => $p,
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
            u.full_name AS requester_name,
            u.email AS requester_email,
            (SELECT COUNT(*) FROM nexcheck_request_item i WHERE i.nexcheck_id = r.nexcheck_id) AS item_count,
            (SELECT COUNT(*) FROM nexcheck_assignment a WHERE a.nexcheck_id = r.nexcheck_id) AS assigned_count
        FROM nexcheck_request r
        JOIN users u ON u.staff_id = r.requested_by
        ORDER BY r.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $db_error = 'Could not load requests. Ensure database tables exist (run db/schema.sql).';
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
            --primary: #2563eb;
            --secondary: #0ea5e9;
            --bg: #f1f5f9;
            --card-bg: #fff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass: #f8fafc;
            --success: #10b981;
            --warning: #f59e0b;
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
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2rem 2.5rem;
            max-width: calc(100vw - 280px);
        }
        @media (max-width: 900px) {
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.25rem; }
        }
        .page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--card-border);
            margin-bottom: 1.5rem;
        }
        .page-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }
        .page-header h1 i { color: var(--secondary); }
        .page-header p { color: var(--text-muted); margin-top: 0.35rem; font-size: 0.92rem; }
        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.65rem 1rem;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-muted);
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
        }
        .btn-ghost:hover { color: var(--primary); border-color: rgba(37,99,235,0.25); }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            box-shadow: 0 10px 25px rgba(15,23,42,0.05);
            overflow: hidden;
        }
        .card-hd {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--card-border);
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
        }
        .banner {
            padding: 0.85rem 1rem;
            border-radius: 14px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .banner-error {
            background: rgba(239,68,68,0.08);
            border: 1px solid rgba(239,68,68,0.22);
            color: #b91c1c;
        }
        .table-wrap { overflow-x: auto; }
        table.req-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        .req-table th {
            text-align: left;
            padding: 0.75rem 1rem;
            background: var(--glass);
            color: var(--text-muted);
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            border-bottom: 1px solid var(--card-border);
        }
        .req-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
        }
        .req-table tbody tr:last-child td { border-bottom: none; }
        .req-table tbody tr:hover td { background: rgba(14,165,233,0.04); }
        .cell-title { font-weight: 700; font-family: 'Outfit', sans-serif; }
        .cell-muted { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.2rem; }
        .pill {
            display: inline-flex;
            align-items: center;
            padding: 0.22rem 0.55rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .pill.open { background: rgba(245,158,11,0.12); color: #b45309; border: 1px solid rgba(245,158,11,0.25); }
        .pill.done { background: rgba(16,185,129,0.12); color: #047857; border: 1px solid rgba(16,185,129,0.25); }
        .pill.empty { background: rgba(100,116,139,0.1); color: var(--text-muted); border: 1px solid var(--card-border); }
        .link-row {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
        }
        .link-row:hover { text-decoration: underline; }
        .empty-state {
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: var(--text-muted);
        }
        .empty-state i { font-size: 2.5rem; opacity: 0.35; margin-bottom: 0.75rem; display: block; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div>
            <h1><i class="ri-file-list-3-line"></i> User requests</h1>
            <p>Equipment requests from NextCheck users. Assign pool laptops (status <strong>11</strong>) — they become <strong>Checkout (nextcheck)</strong> (<strong>13</strong>) when saved.</p>
        </div>
        <a class="btn-ghost" href="nextAdd.php"><i class="ri-arrow-left-line"></i> Add items</a>
    </header>

    <?php if ($db_error !== ''): ?>
        <div class="banner banner-error"><?= htmlspecialchars($db_error) ?></div>
    <?php endif; ?>

    <section class="card">
        <div class="card-hd"><i class="ri-inbox-line"></i> All requests</div>
        <?php if ($requests === [] && $db_error === ''): ?>
            <div class="empty-state">
                <i class="ri-inbox-archive-line"></i>
                No requests yet. Users submit from the NextCheck form.
            </div>
        <?php elseif ($requests !== []): ?>
            <div class="table-wrap">
                <table class="req-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Requester</th>
                            <th>Dates</th>
                            <th>Program / location</th>
                            <th>Progress</th>
                            <th>Submitted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $row):
                        $nid = (int)$row['nexcheck_id'];
                        $ic = (int)$row['item_count'];
                        $ac = (int)$row['assigned_count'];
                        if ($ic === 0) {
                            $pill = 'empty';
                            $plabel = 'No lines';
                        } elseif ($ac >= $ic) {
                            $pill = 'done';
                            $plabel = 'Assigned';
                        } else {
                            $pill = 'open';
                            $plabel = 'Needs assets';
                        }
                        ?>
                        <tr>
                            <td class="cell-title">#<?= $nid ?></td>
                            <td>
                                <div class="cell-title"><?= htmlspecialchars((string)$row['requester_name']) ?></div>
                                <div class="cell-muted"><?= htmlspecialchars((string)$row['requester_email']) ?></div>
                            </td>
                            <td>
                                <div><?= htmlspecialchars((string)$row['borrow_date']) ?> → <?= htmlspecialchars((string)$row['return_date']) ?></div>
                            </td>
                            <td>
                                <div class="cell-title"><?= htmlspecialchars(nexcheck_format_program((string)$row['program_type'])) ?></div>
                                <div class="cell-muted"><?= htmlspecialchars((string)$row['usage_location']) ?></div>
                            </td>
                            <td>
                                <span class="pill <?= $pill ?>"><?= htmlspecialchars($plabel) ?></span>
                                <div class="cell-muted" style="margin-top:0.35rem"><?= $ac ?> / <?= $ic ?> units</div>
                            </td>
                            <td class="cell-muted"><?= htmlspecialchars((string)$row['created_at']) ?></td>
                            <td>
                                <a class="link-row" href="nextItems.php?nexcheck_id=<?= $nid ?>">
                                    Assign <i class="ri-arrow-right-s-line"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
