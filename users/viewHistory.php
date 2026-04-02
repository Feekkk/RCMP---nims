<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] !== 3)) {
    header('Location: ../auth/login.php');
    exit;
}

$userName = trim((string)($_SESSION['user_name'] ?? 'User'));
$staffId = (string)($_SESSION['staff_id'] ?? '');

require_once __DIR__ . '/../config/database.php';

$program_labels = [
    'academic' => 'Academic',
    'official_event' => 'Official event',
    'club_society' => 'Club / society',
];

$requests = [];
$itemsByNex = [];
try {
    $pdo = db();
    $reqStmt = $pdo->prepare('
        SELECT nexcheck_id, borrow_date, return_date, program_type, usage_location, reason, created_at
        FROM nexcheck_request
        WHERE requested_by = ?
        ORDER BY created_at DESC
    ');
    $reqStmt->execute([$staffId]);
    $requests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
    $nids = array_map('intval', array_column($requests, 'nexcheck_id'));
    if ($nids !== []) {
        $ph = implode(',', array_fill(0, count($nids), '?'));
        $it = $pdo->prepare("
            SELECT nexcheck_id, category, COUNT(*) AS cnt
            FROM nexcheck_request_item
            WHERE nexcheck_id IN ($ph)
            GROUP BY nexcheck_id, category
            ORDER BY category
        ");
        $it->execute($nids);
        while ($row = $it->fetch(PDO::FETCH_ASSOC)) {
            $nid = (int)$row['nexcheck_id'];
            if (!isset($itemsByNex[$nid])) {
                $itemsByNex[$nid] = [];
            }
            $itemsByNex[$nid][] = $row;
        }
    }
} catch (Throwable $e) {
    $requests = [];
    $itemsByNex = [];
}

$user_initials = '';
foreach (preg_split('/\s+/', $userName) as $p) {
    if ($p === '') {
        continue;
    }
    $user_initials .= mb_strtoupper(mb_substr($p, 0, 1));
    if (mb_strlen($user_initials) >= 2) {
        break;
    }
}
if ($user_initials === '') {
    $user_initials = mb_strtoupper(mb_substr($userName !== '' ? $userName : 'U', 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request history — NextCheck — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-light: #3b82f6;
            --primary-dark: #1d4ed8;
            --bg: #f1f5f9;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass: #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        .vh-header {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 0.85rem;
            padding-top: max(0.55rem, env(safe-area-inset-top));
            background: var(--card-bg);
            border-bottom: 1px solid var(--card-border);
            box-shadow: 0 2px 14px rgba(15, 23, 42, 0.06);
        }
        .vh-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: var(--glass);
            color: var(--text-main);
            text-decoration: none;
            flex-shrink: 0;
            transition: background 0.15s, border-color 0.15s;
        }
        .vh-back:hover {
            border-color: rgba(37, 99, 235, 0.35);
            background: rgba(37, 99, 235, 0.06);
            color: var(--primary-dark);
        }
        .vh-back i { font-size: 1.35rem; }
        .vh-brand {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            min-width: 0;
            flex: 1;
            text-decoration: none;
            color: inherit;
        }
        .vh-brand img {
            height: 32px;
            width: auto;
            max-width: 100px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .vh-brand-text strong {
            font-family: 'Outfit', sans-serif;
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            display: block;
            line-height: 1.2;
        }
        .vh-brand-text small {
            font-size: 0.58rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--primary);
        }
        .vh-user {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            flex-shrink: 0;
        }
        .vh-avatar {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .vh-main {
            flex: 1;
            width: 100%;
            max-width: 780px;
            margin: 0 auto;
            padding: 1rem 1rem 1.5rem;
        }
        @media (min-width: 480px) {
            .vh-main { padding: 1.15rem 1.25rem 2rem; }
        }

        .vh-page-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.2rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .vh-page-title i { color: var(--primary); }
        .vh-page-lead {
            font-size: 0.86rem;
            color: var(--text-muted);
            line-height: 1.5;
            margin-bottom: 1.25rem;
        }

        .vh-empty {
            text-align: center;
            padding: 2rem 1.25rem;
            background: var(--card-bg);
            border: 1px dashed var(--card-border);
            border-radius: 18px;
            color: var(--text-muted);
            font-size: 0.9rem;
            line-height: 1.55;
        }
        .vh-empty i {
            font-size: 2.5rem;
            color: var(--card-border);
            display: block;
            margin-bottom: 0.75rem;
        }

        .vh-stack { display: flex; flex-direction: column; gap: 0.75rem; }
        .vh-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(15, 23, 42, 0.05);
        }
        .vh-card-hd {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.5rem;
            padding: 0.9rem 1rem;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-bottom: 1px solid var(--card-border);
        }
        .vh-card-id {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
            color: var(--primary-dark);
        }
        .vh-card-date {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-muted);
            text-align: right;
        }
        .vh-card-bd { padding: 1rem 1rem 1.05rem; }
        .vh-badge {
            display: inline-block;
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 0.28rem 0.55rem;
            border-radius: 8px;
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary-dark);
            margin-bottom: 0.65rem;
        }
        .vh-dl {
            display: grid;
            gap: 0.5rem 1rem;
            font-size: 0.82rem;
            margin-bottom: 0.75rem;
        }
        .vh-dl > div { display: grid; grid-template-columns: auto 1fr; gap: 0.35rem 0.65rem; align-items: start; }
        .vh-dl dt {
            font-weight: 700;
            color: var(--text-muted);
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .vh-dl dd { font-weight: 600; color: var(--text-main); }
        .vh-reason-label {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.3rem;
        }
        .vh-reason {
            font-size: 0.84rem;
            line-height: 1.5;
            color: var(--text-main);
            margin-bottom: 0.85rem;
        }
        .vh-items-label {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
        }
        .vh-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }
        .vh-chip {
            font-size: 0.76rem;
            font-weight: 600;
            padding: 0.35rem 0.65rem;
            border-radius: 20px;
            background: rgba(37, 99, 235, 0.08);
            border: 1px solid rgba(37, 99, 235, 0.2);
            color: var(--primary-dark);
        }

        .vh-footer {
            padding: 0.85rem 1rem calc(0.85rem + env(safe-area-inset-bottom));
            text-align: center;
            font-size: 0.72rem;
            color: var(--text-muted);
            border-top: 1px solid var(--card-border);
            background: var(--card-bg);
        }
        .vh-footer a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .vh-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <header class="vh-header">
        <a class="vh-back" href="landingPage.php" aria-label="Back to home"><i class="ri-arrow-left-s-line"></i></a>
        <a class="vh-brand" href="landingPage.php">
            <img src="../public/logo-nims.png" alt="">
            <div class="vh-brand-text">
                <strong>RCMP NIMS</strong>
                <small>NextCheck</small>
            </div>
        </a>
        <div class="vh-user">
            <div class="vh-avatar" aria-hidden="true"><?= htmlspecialchars($user_initials) ?></div>
        </div>
    </header>

    <main class="vh-main">
        <h1 class="vh-page-title" id="page-h1"><i class="ri-history-line" aria-hidden="true"></i> Request history</h1>
        <p class="vh-page-lead">Every equipment request you have submitted through NextCheck is listed below, newest first.</p>

        <?php if ($requests === []): ?>
        <div class="vh-empty" role="status">
            <i class="ri-inbox-2-line" aria-hidden="true"></i>
            <strong style="display:block;color:var(--text-main);margin-bottom:0.35rem">No requests yet</strong>
            When you submit a reservation from the home page, it will show up here with dates, location, and requested items.
        </div>
        <?php else: ?>
        <div class="vh-stack">
            <?php foreach ($requests as $r):
                $nid = (int)$r['nexcheck_id'];
                $pt = $program_labels[$r['program_type']] ?? (string)$r['program_type'];
                $submitted = $r['created_at'] ? date('j M Y, g:i a', strtotime((string)$r['created_at'])) : '—';
                $items = $itemsByNex[$nid] ?? [];
                ?>
            <article class="vh-card" aria-labelledby="req-<?= $nid ?>-title">
                <div class="vh-card-hd">
                    <span class="vh-card-id" id="req-<?= $nid ?>-title">Request #<?= $nid ?></span>
                    <span class="vh-card-date">Submitted<br><?= htmlspecialchars($submitted) ?></span>
                </div>
                <div class="vh-card-bd">
                    <span class="vh-badge"><?= htmlspecialchars($pt) ?></span>
                    <dl class="vh-dl">
                        <div><dt>Borrow</dt><dd><?= htmlspecialchars((string)$r['borrow_date']) ?></dd></div>
                        <div><dt>Return</dt><dd><?= htmlspecialchars((string)$r['return_date']) ?></dd></div>
                        <div><dt>Location</dt><dd><?= htmlspecialchars((string)$r['usage_location']) ?></dd></div>
                    </dl>
                    <?php if (trim((string)($r['reason'] ?? '')) !== ''): ?>
                    <p class="vh-reason-label">Reason</p>
                    <p class="vh-reason"><?= nl2br(htmlspecialchars((string)$r['reason'])) ?></p>
                    <?php endif; ?>
                    <p class="vh-items-label">Requested items</p>
                    <div class="vh-chips">
                        <?php if ($items === []): ?>
                        <span class="vh-chip" style="opacity:0.75">No line items recorded</span>
                        <?php else: ?>
                            <?php foreach ($items as $it):
                                $cnt = (int)$it['cnt'];
                                $label = (string)$it['category'];
                                ?>
                        <span class="vh-chip"><?= htmlspecialchars($label) ?><?= $cnt > 1 ? htmlspecialchars(' × ' . $cnt) : '' ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <footer class="vh-footer">
        <span style="display:block;margin-top:0.4rem">&copy; <?= (int)date('Y') ?> Universiti Kuala Lumpur RCMP</span>
    </footer>
</body>
</html>
