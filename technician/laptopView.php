<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;
if ($assetId <= 0) { header('Location: laptop.php'); exit; }

$pdo = db();

// Allow quick status updates from this page (only status_id 1 or 2)
$statusFlash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    $postedAssetId = (int)($_POST['asset_id'] ?? 0);
    $newStatusId   = (int)($_POST['status_id'] ?? 0);
    $csrf          = (string)($_POST['csrf'] ?? '');

    $csrfOk = isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $csrf);
    if (!$csrfOk) {
        $statusFlash = 'Invalid session token. Please refresh and try again.';
    } elseif ($postedAssetId !== $assetId) {
        $statusFlash = 'Invalid asset selected.';
    } elseif ($newStatusId !== 1 && $newStatusId !== 2) {
        $statusFlash = 'Only status 1 or 2 can be changed from this page.';
    } else {
        $upd = $pdo->prepare("UPDATE laptop SET status_id = ? WHERE asset_id = ? LIMIT 1");
        $upd->execute([$newStatusId, $assetId]);
        header('Location: laptopView.php?asset_id=' . urlencode((string)$assetId) . '&status_updated=1');
        exit;
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$stmt = $pdo->prepare("SELECT l.*, s.name AS status_name FROM laptop l JOIN status s ON s.status_id = l.status_id WHERE l.asset_id = ? LIMIT 1");
$stmt->execute([$assetId]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$asset) { header('Location: laptop.php'); exit; }

$stmtAllowedStatus = $pdo->query("SELECT status_id, name FROM status WHERE status_id IN (1,2) ORDER BY status_id ASC");
$allowedStatuses = $stmtAllowedStatus ? $stmtAllowedStatus->fetchAll(PDO::FETCH_ASSOC) : [];

$stmtW = $pdo->prepare("SELECT warranty_id, warranty_start_date, warranty_end_date, warranty_remarks, created_at FROM warranty WHERE asset_type = 'laptop' AND asset_id = ? ORDER BY warranty_end_date DESC, warranty_id DESC LIMIT 1");
$stmtW->execute([$assetId]);
$warranty = $stmtW->fetch(PDO::FETCH_ASSOC) ?: null;

$inWarranty = false;
if ($warranty && !empty($warranty['warranty_start_date']) && !empty($warranty['warranty_end_date'])) {
    try {
        $today = new DateTimeImmutable('today');
        $ws = new DateTimeImmutable((string)$warranty['warranty_start_date']);
        $we = new DateTimeImmutable((string)$warranty['warranty_end_date']);
        $inWarranty = ($today >= $ws && $today <= $we);
    } catch (Throwable $e) { $inWarranty = false; }
}

$stmtHLatest = $pdo->prepare("
    SELECT h.handover_id, h.handover_date, h.handover_remarks,
           h.created_at AS handover_created_at,
           hs.handover_staff_id, hs.employee_no,
           st.full_name AS recipient_name, st.department AS recipient_dept
    FROM handover h
    LEFT JOIN handover_staff hs ON hs.handover_id = h.handover_id
      AND hs.handover_staff_id = (SELECT MAX(hs2.handover_staff_id) FROM handover_staff hs2 WHERE hs2.handover_id = h.handover_id)
    LEFT JOIN staff st ON st.employee_no = hs.employee_no
    WHERE h.asset_id = ?
    ORDER BY h.handover_date DESC, h.created_at DESC LIMIT 1
");
$stmtHLatest->execute([$assetId]);
$latestHandover = $stmtHLatest->fetch(PDO::FETCH_ASSOC) ?: null;

$events = [];
$events[] = ['type' => 'register', 'at' => (string)($asset['created_at'] ?? ''), 'title' => 'Asset registered', 'meta' => null, 'details' => null];

$stmtHTrail = $pdo->prepare("
    SELECT h.handover_id, h.handover_date, h.handover_remarks, h.staff_id AS technician_id, h.created_at AS handover_created_at,
           hs.handover_staff_id, hs.employee_no, st.full_name AS recipient_name, st.department AS recipient_dept,
           COALESCE(rS.return_id, rP.return_id) AS return_id,
           COALESCE(rS.return_date, rP.return_date) AS return_date,
           COALESCE(rS.return_place, rP.return_place) AS return_place,
           COALESCE(rS.condition, rP.condition) AS return_condition,
           COALESCE(rS.return_remarks, rP.return_remarks) AS return_remarks,
           COALESCE(rS.created_at, rP.created_at) AS return_created_at
    FROM handover h
    LEFT JOIN handover_staff hs ON hs.handover_id = h.handover_id
      AND hs.handover_staff_id = (SELECT MAX(hs2.handover_staff_id) FROM handover_staff hs2 WHERE hs2.handover_id = h.handover_id)
    LEFT JOIN staff st ON st.employee_no = hs.employee_no
    LEFT JOIN handover_return rS ON rS.handover_staff_id = hs.handover_staff_id
    LEFT JOIN handover_return rP ON rP.handover_id = h.handover_id
    WHERE h.asset_id = ?
    ORDER BY h.handover_date DESC, h.created_at DESC
");
$stmtHTrail->execute([$assetId]);
$handovers = $stmtHTrail->fetchAll(PDO::FETCH_ASSOC);

foreach ($handovers as $h) {
    $who = trim((string)($h['recipient_name'] ?? ''));
    if ($who === '') $who = ($h['employee_no'] ?? '') ? (string)$h['employee_no'] : '—';
    $dept = trim((string)($h['recipient_dept'] ?? ''));
    $meta = $dept !== '' ? ($who . ' • ' . $dept) : $who;
    $events[] = ['type' => 'handover', 'at' => (string)($h['handover_created_at'] ?? ''), 'title' => 'Handover recorded', 'meta' => $meta, 'details' => ($h['handover_remarks'] ?? null) ? (string)$h['handover_remarks'] : null, 'handover_id' => (int)$h['handover_id']];
    if (!empty($h['return_id'])) {
        $retMetaParts = [];
        if (!empty($h['return_place'])) $retMetaParts[] = (string)$h['return_place'];
        if (!empty($h['return_condition'])) $retMetaParts[] = 'Condition: ' . (string)$h['return_condition'];
        $events[] = ['type' => 'return', 'at' => (string)($h['return_created_at'] ?? ''), 'title' => 'Return recorded', 'meta' => $retMetaParts ? implode(' • ', $retMetaParts) : null, 'details' => ($h['return_remarks'] ?? null) ? (string)$h['return_remarks'] : null, 'handover_id' => (int)$h['handover_id']];
    }
}

$stmtWH = $pdo->prepare("SELECT warranty_id, warranty_start_date, warranty_end_date, warranty_remarks, created_at FROM warranty WHERE asset_type = 'laptop' AND asset_id = ? ORDER BY created_at DESC, warranty_id DESC");
$stmtWH->execute([$assetId]);
foreach ($stmtWH->fetchAll(PDO::FETCH_ASSOC) as $w) {
    $range = (!empty($w['warranty_start_date']) && !empty($w['warranty_end_date'])) ? (string)$w['warranty_start_date'] . ' → ' . (string)$w['warranty_end_date'] : null;
    $events[] = ['type' => 'warranty', 'at' => (string)($w['created_at'] ?? ''), 'title' => 'Warranty recorded', 'meta' => $range, 'details' => ($w['warranty_remarks'] ?? null) ? (string)$w['warranty_remarks'] : null];
}

$stmtC = $pdo->prepare("SELECT claim_id, claim_date, claim_time, issue_summary, claim_remarks, created_at FROM warranty_claim WHERE asset_id = ? ORDER BY created_at DESC, claim_id DESC");
$stmtC->execute([$assetId]);
foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $when = (string)($c['claim_date'] ?? '');
    if (!empty($c['claim_time'])) $when .= ' ' . (string)$c['claim_time'];
    $events[] = ['type' => 'claim', 'at' => (string)($c['created_at'] ?? ''), 'title' => 'Warranty claim logged', 'meta' => $when !== '' ? $when : null, 'details' => trim((string)($c['issue_summary'] ?? '')) . (($c['claim_remarks'] ?? null) ? ' — ' . (string)$c['claim_remarks'] : '')];
}

$stmtR = $pdo->prepare("SELECT repair_id, repair_date, completed_date, issue_summary, repair_remarks, created_at FROM repair WHERE asset_id = ? ORDER BY created_at DESC, repair_id DESC");
$stmtR->execute([$assetId]);
foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $meta = (string)($r['repair_date'] ?? '');
    if (!empty($r['completed_date'])) $meta .= ' → ' . (string)$r['completed_date'];
    $events[] = ['type' => 'repair', 'at' => (string)($r['created_at'] ?? ''), 'title' => 'Repair logged', 'meta' => trim($meta) !== '' ? $meta : null, 'details' => trim((string)($r['issue_summary'] ?? '')) . (($r['repair_remarks'] ?? null) ? ' — ' . (string)$r['repair_remarks'] : '')];
}

usort($events, function ($a, $b) {
    return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? ''));
});

$statusId = (int)($asset['status_id'] ?? 0);
$status_meta = [
    1 => ['icon'=>'ri-checkbox-circle-fill','cls'=>'s-active', 'colour'=>'#10b981','bg'=>'rgba(16,185,129,0.1)', 'border'=>'rgba(16,185,129,0.25)'],
    2 => ['icon'=>'ri-close-circle-fill',   'cls'=>'s-muted',  'colour'=>'#64748b','bg'=>'rgba(100,116,139,0.1)','border'=>'rgba(100,116,139,0.25)'],
    3 => ['icon'=>'ri-user-received-2-fill','cls'=>'s-blue',   'colour'=>'#2563eb','bg'=>'rgba(37,99,235,0.1)',  'border'=>'rgba(37,99,235,0.25)'],
    4 => ['icon'=>'ri-archive-fill',        'cls'=>'s-purple', 'colour'=>'#8b5cf6','bg'=>'rgba(139,92,246,0.1)', 'border'=>'rgba(139,92,246,0.25)'],
    5 => ['icon'=>'ri-tools-fill',          'cls'=>'s-amber',  'colour'=>'#f59e0b','bg'=>'rgba(245,158,11,0.1)', 'border'=>'rgba(245,158,11,0.25)'],
    6 => ['icon'=>'ri-alert-fill',          'cls'=>'s-red',    'colour'=>'#ef4444','bg'=>'rgba(239,68,68,0.1)',  'border'=>'rgba(239,68,68,0.25)'],
    7 => ['icon'=>'ri-delete-bin-fill',     'cls'=>'s-muted',  'colour'=>'#94a3b8','bg'=>'rgba(148,163,184,0.1)','border'=>'rgba(148,163,184,0.25)'],
    8 => ['icon'=>'ri-map-pin-line',        'cls'=>'s-orange', 'colour'=>'#f97316','bg'=>'rgba(249,115,22,0.1)', 'border'=>'rgba(249,115,22,0.25)'],
];
$sm = $status_meta[$statusId] ?? ['icon'=>'ri-question-line','cls'=>'s-muted','colour'=>'#64748b','bg'=>'rgba(100,116,139,0.1)','border'=>'rgba(100,116,139,0.25)'];

$fmtDate = function (?string $d): string {
    if (!$d) return '—';
    try { return (new DateTimeImmutable($d))->format('d M Y'); } catch (Throwable $e) { return $d; }
};
$fmtDateTime = function (?string $dt): string {
    if (!$dt) return '—';
    try { return (new DateTimeImmutable($dt))->format('d M Y, h:i A'); } catch (Throwable $e) { return $dt; }
};

$deviceName = trim((string)($asset['brand'] ?? '') . ' ' . (string)($asset['model'] ?? ''));
if ($deviceName === '') $deviceName = 'Unknown Device';

// Age from PO date until today
$ageText = '—';
if (!empty($asset['PO_DATE'])) {
    try {
        $po = new DateTimeImmutable((string)$asset['PO_DATE']);
        $today = new DateTimeImmutable('today');
        if ($po <= $today) {
            $diff = $po->diff($today);
            $parts = [];
            if ($diff->y > 0) $parts[] = $diff->y . 'y';
            if ($diff->m > 0) $parts[] = $diff->m . 'm';
            if ($parts === [] && $diff->d > 0) $parts[] = $diff->d . 'd';
            $ageText = $parts !== [] ? implode(' ', $parts) : '0d';
        } else {
            $ageText = '—';
        }
    } catch (Throwable $e) {
        $ageText = '—';
    }
}

$typeMeta = [
    'register' => ['i'=>'ri-add-circle-line',    'c'=>'var(--primary)',  'label'=>'Register', 'pill'=>'pill-blue'],
    'handover' => ['i'=>'ri-exchange-line',       'c'=>'var(--success)', 'label'=>'Handover',  'pill'=>'pill-green'],
    'return'   => ['i'=>'ri-arrow-go-back-line',  'c'=>'var(--warning)', 'label'=>'Return',    'pill'=>'pill-amber'],
    'warranty' => ['i'=>'ri-shield-check-line',   'c'=>'var(--primary)', 'label'=>'Warranty',  'pill'=>'pill-blue'],
    'claim'    => ['i'=>'ri-file-list-2-line',    'c'=>'var(--secondary)','label'=>'Claim',    'pill'=>'pill-sky'],
    'repair'   => ['i'=>'ri-tools-line',          'c'=>'var(--danger)',  'label'=>'Repair',    'pill'=>'pill-red'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset #<?= (int)$assetId ?> — <?= htmlspecialchars($deviceName) ?> — RCMP NIMS</title>
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
            --danger:        #ef4444;
            --warning:       #f59e0b;
            --bg:            #f1f5f9;
            --card-bg:       #ffffff;
            --card-border:   #e2e8f0;
            --text-main:     #0f172a;
            --text-muted:    #64748b;
            --glass:         #f8fafc;
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

        /* ── Layout ── */
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2rem 2.5rem 3.5rem;
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
        .page-header-left {}
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            margin-bottom: 0.55rem;
            transition: color 0.15s;
        }
        .back-link:hover { color: var(--primary); }
        .page-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.65rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }
        .asset-id-badge {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
            background: var(--glass);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 0.18rem 0.55rem;
            font-family: 'Outfit', sans-serif;
        }
        .page-subtitle { font-size: 0.875rem; color: var(--text-muted); margin-top: 0.3rem; }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-wrap: wrap;
            padding-top: 0.2rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.65rem 1.1rem;
            border-radius: 11px;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.88rem;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.18s;
            white-space: nowrap;
        }
        .btn-ghost {
            background: var(--card-bg);
            border: 1.5px solid var(--card-border);
            color: var(--text-muted);
        }
        .btn-ghost:hover { border-color: var(--primary); color: var(--primary); }
        .btn-action {
            background: linear-gradient(135deg, #021A54, #1e40af);
            color: #fff;
            box-shadow: 0 4px 14px rgba(2,26,84,0.25);
        }
        .btn-action:hover { filter: brightness(1.08); transform: translateY(-1px); }

        /* ── Cards ── */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(15,23,42,0.05);
        }
        .card-hd {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.9rem 1.25rem;
            border-bottom: 1px solid var(--card-border);
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        }
        .card-hd-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
        }
        .card-hd-title i { color: var(--primary); font-size: 1rem; }
        .card-bd { padding: 1.25rem; }

        /* ── Status badges ── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.32rem 0.75rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .s-active { background: rgba(16,185,129,0.1);  border-color: rgba(16,185,129,0.25); color: #047857; }
        .s-blue   { background: rgba(37,99,235,0.1);   border-color: rgba(37,99,235,0.25);  color: #1d4ed8; }
        .s-amber  { background: rgba(245,158,11,0.1);  border-color: rgba(245,158,11,0.25); color: #b45309; }
        .s-red    { background: rgba(239,68,68,0.1);   border-color: rgba(239,68,68,0.25);  color: #b91c1c; }
        .s-purple { background: rgba(139,92,246,0.1);  border-color: rgba(139,92,246,0.25); color: #6d28d9; }
        .s-muted  { background: rgba(100,116,139,0.1); border-color: rgba(100,116,139,0.2); color: #475569; }
        .s-orange { background: rgba(249,115,22,0.1);  border-color: rgba(249,115,22,0.25); color: #c2410c; }

        /* ── Main 2-col grid ── */
        .layout-grid {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 1.25rem;
            align-items: start;
            margin-bottom: 1.25rem;
        }
        @media (max-width: 1100px) { .layout-grid { grid-template-columns: 1fr; } }

        /* ── Hero identity block ── */
        .device-hero {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.1rem 1.25rem;
            border-bottom: 1px solid var(--card-border);
        }
        .device-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
            border: 1px solid <?= htmlspecialchars((string)$sm['border']) ?>;
            background: <?= htmlspecialchars((string)$sm['bg']) ?>;
            color: <?= htmlspecialchars((string)$sm['colour']) ?>;
        }
        .device-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }
        .device-sub {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem 0.75rem;
            margin-top: 0.3rem;
        }
        .device-sub-item {
            font-size: 0.78rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-weight: 500;
        }
        .device-sub-item i { font-size: 0.8rem; }
        .mono-val {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            color: var(--text-main);
            font-size: 0.82rem;
        }

        /* ── Tabbed sections inside main card ── */
        .tab-bar {
            display: flex;
            gap: 0;
            border-bottom: 1px solid var(--card-border);
            background: var(--glass);
            padding: 0 1.25rem;
            overflow-x: auto;
        }
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            border: none;
            background: none;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            white-space: nowrap;
            transition: color 0.15s, border-color 0.15s;
            font-family: 'Inter', sans-serif;
        }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-btn:hover:not(.active) { color: var(--text-main); }
        .tab-btn i { font-size: 0.9rem; }

        .tab-panel { display: none; padding: 1.25rem; }
        .tab-panel.active { display: block; }

        /* ── Field groups (key-value) ── */
        .kv-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        @media (max-width: 600px) { .kv-grid { grid-template-columns: 1fr; } }

        .kv-row {
            display: flex;
            flex-direction: column;
            padding: 0.7rem 0.85rem;
            border-bottom: 1px solid var(--card-border);
            border-right: 1px solid var(--card-border);
        }
        /* right column: no right border */
        .kv-row:nth-child(even) { border-right: none; }
        /* last two rows: no bottom border */
        .kv-row:nth-last-child(-n+2) { border-bottom: none; }
        /* if odd number of items, last row spans */
        .kv-row.full-row { grid-column: 1 / -1; border-right: none; }

        .kv-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-muted);
            margin-bottom: 0.22rem;
        }
        .kv-val {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-main);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .kv-val.wrap { white-space: normal; line-height: 1.5; }
        .kv-val.muted-val { color: var(--text-muted); font-weight: 400; font-style: italic; }

        /* ── Snapshot sidebar ── */
        .snapshot-list { display: flex; flex-direction: column; }
        .snapshot-row {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.85rem 1.1rem;
            border-bottom: 1px solid var(--card-border);
        }
        .snapshot-row:last-child { border-bottom: none; }
        .snapshot-icon {
            width: 32px; height: 32px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
            margin-top: 0.05rem;
        }
        .si-blue   { background: rgba(37,99,235,0.1);  color: var(--primary); }
        .si-green  { background: rgba(16,185,129,0.1); color: #047857; }
        .si-amber  { background: rgba(245,158,11,0.1); color: #b45309; }
        .snapshot-content {}
        .snapshot-label {
            font-size: 0.67rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            margin-bottom: 0.2rem;
        }
        .snapshot-value { font-size: 0.87rem; font-weight: 600; }
        .snapshot-sub {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.15rem;
            line-height: 1.4;
        }
        .warranty-active { color: #047857; font-weight: 700; font-size: 0.75rem; margin-top: 0.15rem; display: flex; align-items: center; gap: 0.25rem; }
        .warranty-expired { color: var(--danger); font-weight: 700; font-size: 0.75rem; margin-top: 0.15rem; display: flex; align-items: center; gap: 0.25rem; }

        /* ── Trail timeline ── */
        .trail-toolbar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid var(--card-border);
            background: var(--glass);
            flex-wrap: wrap;
        }
        .trail-count {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-left: auto;
        }
        .search-field {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: 1.5px solid var(--card-border);
            border-radius: 10px;
            padding: 0.5rem 0.8rem;
            background: #fff;
            flex: 1;
            min-width: 180px;
            max-width: 320px;
            transition: border-color 0.18s;
        }
        .search-field:focus-within { border-color: rgba(37,99,235,0.45); }
        .search-field i { color: var(--text-muted); font-size: 0.9rem; flex-shrink: 0; }
        .search-field input {
            border: none; background: none; outline: none;
            font-size: 0.85rem; color: var(--text-main); width: 100%;
            font-family: inherit;
        }
        .search-field input::placeholder { color: #94a3b8; }

        /* Timeline */
        .timeline { padding: 0.5rem 1.25rem 1.25rem; }
        .tl-item {
            display: grid;
            grid-template-columns: 28px 1fr;
            gap: 0 0.85rem;
            position: relative;
            padding-bottom: 1.25rem;
        }
        .tl-item:last-child { padding-bottom: 0; }
        .tl-item:not(:last-child) .tl-line::after {
            content: '';
            position: absolute;
            left: 13px;
            top: 28px;
            bottom: 0;
            width: 1px;
            background: var(--card-border);
        }
        .tl-dot-wrap { position: relative; z-index: 1; padding-top: 2px; }
        .tl-dot {
            width: 28px; height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            flex-shrink: 0;
            border: 2px solid var(--card-border);
            background: var(--card-bg);
        }
        .tl-dot.dot-register { border-color: rgba(37,99,235,0.35);  background: rgba(37,99,235,0.08);  color: var(--primary); }
        .tl-dot.dot-handover { border-color: rgba(16,185,129,0.35); background: rgba(16,185,129,0.08); color: #047857; }
        .tl-dot.dot-return   { border-color: rgba(245,158,11,0.35); background: rgba(245,158,11,0.08); color: #b45309; }
        .tl-dot.dot-warranty { border-color: rgba(37,99,235,0.35);  background: rgba(37,99,235,0.08);  color: var(--primary); }
        .tl-dot.dot-claim    { border-color: rgba(14,165,233,0.35); background: rgba(14,165,233,0.08); color: #0369a1; }
        .tl-dot.dot-repair   { border-color: rgba(239,68,68,0.35);  background: rgba(239,68,68,0.08);  color: #b91c1c; }

        .tl-body { padding-top: 3px; min-width: 0; }
        .tl-header { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.3rem; }
        .tl-title { font-weight: 700; font-size: 0.875rem; }
        .tl-pill {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.18rem 0.5rem;
            border-radius: 20px;
            border: 1px solid transparent;
        }
        .pill-blue   { background: rgba(37,99,235,0.09); border-color: rgba(37,99,235,0.2);  color: var(--primary-dark); }
        .pill-green  { background: rgba(16,185,129,0.09);border-color: rgba(16,185,129,0.2); color: #047857; }
        .pill-amber  { background: rgba(245,158,11,0.1); border-color: rgba(245,158,11,0.2); color: #b45309; }
        .pill-sky    { background: rgba(14,165,233,0.09);border-color: rgba(14,165,233,0.2); color: #0369a1; }
        .pill-red    { background: rgba(239,68,68,0.09); border-color: rgba(239,68,68,0.2);  color: #b91c1c; }

        .tl-time { font-size: 0.72rem; color: var(--text-muted); margin-left: auto; white-space: nowrap; }
        .tl-meta { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.18rem; }
        .tl-detail { font-size: 0.82rem; color: var(--text-main); line-height: 1.5; }

        .tl-line { position: relative; }

        .empty-trail {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-muted);
        }
        .empty-trail i { font-size: 2rem; opacity: 0.3; display: block; margin-bottom: 0.5rem; }

        /* ── Hidden rows (search filter) ── */
        .tl-item.hidden-tl { display: none; }

        /* ── Status dropdown ── */
        .status-form { display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-top:0.55rem; }
        .status-select {
            padding: 0.45rem 0.65rem;
            border-radius: 10px;
            border: 1.5px solid var(--card-border);
            background: #fff;
            color: var(--text-main);
            font-weight: 600;
            font-size: 0.82rem;
            outline: none;
        }
        .status-select:focus { border-color: rgba(37,99,235,0.45); box-shadow: 0 0 0 3px rgba(37,99,235,0.10); }
        .btn-mini {
            padding: 0.48rem 0.7rem;
            border-radius: 10px;
            font-size: 0.82rem;
        }
        .flash {
            margin-top: 0.55rem;
            font-size: 0.78rem;
            color: #b91c1c;
            font-weight: 600;
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">

    <!-- Page header -->
    <header class="page-header">
        <div class="page-header-left">
            <a class="back-link" href="laptop.php"><i class="ri-arrow-left-line"></i> Back to inventory</a>
            <h1 class="page-title">
                <?= htmlspecialchars($deviceName) ?>
                <span class="asset-id-badge">#<?= (int)$assetId ?></span>
            </h1>
            <p class="page-subtitle">Asset details, specifications, and activity history.</p>
        </div>
        <div class="header-actions">
            <?php if ($statusId === 1): ?>
                <a class="btn btn-action" href="handoverForm.php?asset_id=<?= urlencode((string)$assetId) ?>"><i class="ri-exchange-line"></i> Handover</a>
            <?php elseif ($statusId === 3): ?>
                <a class="btn btn-action" href="returnForm.php?asset_id=<?= urlencode((string)$assetId) ?>"><i class="ri-arrow-go-back-line"></i> Return</a>
            <?php elseif ($statusId === 5): ?>
                <a class="btn btn-action" href="warranty.php?asset_id=<?= urlencode((string)$assetId) ?>"><i class="ri-shield-check-line"></i> Warranty</a>
            <?php elseif ($statusId === 6): ?>
                <?php if ($inWarranty): ?>
                    <a class="btn btn-action" href="warranty.php?asset_id=<?= urlencode((string)$assetId) ?>"><i class="ri-shield-check-line"></i> Warranty</a>
                <?php else: ?>
                    <a class="btn btn-action" href="repair.php?asset_id=<?= urlencode((string)$assetId) ?>"><i class="ri-tools-line"></i> Repair</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <!-- Main 2-col layout -->
    <div class="layout-grid">

        <!-- ── Left: main asset card ── -->
        <div class="card">

            <!-- Device hero -->
            <div class="device-hero">
                <div class="device-icon"><i class="<?= htmlspecialchars((string)$sm['icon']) ?>"></i></div>
                <div>
                    <div class="device-name"><?= htmlspecialchars($deviceName) ?></div>
                    <div class="device-sub">
                        <span class="device-sub-item"><i class="ri-qr-code-line"></i> <span class="mono-val"><?= htmlspecialchars((string)$assetId) ?></span></span>
                        <span class="device-sub-item"><i class="ri-barcode-line"></i> <span class="mono-val"><?= htmlspecialchars((string)($asset['serial_num'] ?? '—')) ?></span></span>
                        <span class="device-sub-item"><i class="ri-price-tag-3-line"></i> <?= htmlspecialchars((string)($asset['category'] ?? '—')) ?></span>
                    </div>
                </div>
                <span class="status-badge <?= htmlspecialchars((string)$sm['cls']) ?>" style="margin-left:auto;flex-shrink:0">
                    <i class="<?= htmlspecialchars((string)$sm['icon']) ?>"></i>
                    <?= htmlspecialchars((string)($asset['status_name'] ?? '—')) ?>
                </span>
            </div>

            <!-- Tabs -->
            <div class="tab-bar" id="tabBar">
                <button class="tab-btn active" data-tab="specs"><i class="ri-cpu-line"></i> Specifications</button>
                <button class="tab-btn" data-tab="purchase"><i class="ri-shopping-bag-3-line"></i> Purchase</button>
                <button class="tab-btn" data-tab="meta"><i class="ri-time-line"></i> Record info</button>
            </div>

            <!-- Tab: Specifications -->
            <div class="tab-panel active" id="tab-specs">
                <div class="kv-grid">
                    <div class="kv-row">
                        <span class="kv-label">Brand</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['brand'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Model</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['model'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Part number</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['part_number'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Category</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['category'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Processor</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['processor'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Memory</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['memory'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Storage</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['storage'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">GPU</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['gpu'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row full-row">
                        <span class="kv-label">Operating system</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['os'] ?? '—')) ?></span>
                    </div>
                    <?php $remarks = trim((string)($asset['remarks'] ?? '')); if ($remarks !== ''): ?>
                    <div class="kv-row full-row">
                        <span class="kv-label">Remarks</span>
                        <span class="kv-val wrap"><?= htmlspecialchars($remarks) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab: Purchase -->
            <div class="tab-panel" id="tab-purchase">
                <div class="kv-grid">
                    <div class="kv-row">
                        <span class="kv-label">PO Date</span>
                        <span class="kv-val"><?= htmlspecialchars($fmtDate($asset['PO_DATE'] ?? null)) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">PO Number</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['PO_NUM'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">DO Date</span>
                        <span class="kv-val"><?= htmlspecialchars($fmtDate($asset['DO_DATE'] ?? null)) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">DO Number</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['DO_NUM'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Invoice Date</span>
                        <span class="kv-val"><?= htmlspecialchars($fmtDate($asset['INVOICE_DATE'] ?? null)) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Invoice Number</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['INVOICE_NUM'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row full-row">
                        <span class="kv-label">Purchase cost</span>
                        <span class="kv-val"><?= $asset['PURCHASE_COST'] !== null ? htmlspecialchars('RM ' . number_format((float)$asset['PURCHASE_COST'], 2)) : '—' ?></span>
                    </div>
                </div>
            </div>

            <!-- Tab: Record info -->
            <div class="tab-panel" id="tab-meta">
                <div class="kv-grid">
                    <div class="kv-row">
                        <span class="kv-label">Created</span>
                        <span class="kv-val"><?= htmlspecialchars($fmtDateTime($asset['created_at'] ?? null)) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Last updated</span>
                        <span class="kv-val"><?= htmlspecialchars($fmtDateTime($asset['updated_at'] ?? null)) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Asset ID</span>
                        <span class="kv-val"><?= htmlspecialchars((string)$assetId) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Serial number</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['serial_num'] ?? '—')) ?></span>
                    </div>
                </div>
            </div>

        </div><!-- /left card -->

        <!-- ── Right: snapshot sidebar ── -->
        <div class="card">
            <div class="card-hd">
                <div class="card-hd-title"><i class="ri-dashboard-line"></i> Snapshot</div>
            </div>
            <div class="snapshot-list">

                <!-- Status -->
                <div class="snapshot-row">
                    <div class="snapshot-icon si-blue"><i class="ri-pulse-line"></i></div>
                    <div class="snapshot-content">
                        <div class="snapshot-label">Current status</div>
                        <form class="status-form" method="POST" action="">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">
                            <select class="status-select" name="status_id" aria-label="Change status" id="statusSelect">
                                <?php foreach ($allowedStatuses as $st):
                                    $sid = (int)($st['status_id'] ?? 0);
                                    $sname = (string)($st['name'] ?? ('Status ' . $sid));
                                ?>
                                    <option value="<?= $sid ?>" <?= $sid === (int)($asset['status_id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sid . ' — ' . $sname) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php if (!empty($statusFlash)): ?>
                            <div class="flash"><?= htmlspecialchars($statusFlash) ?></div>
                        <?php elseif (!empty($_GET['status_updated'])): ?>
                            <div class="flash" style="color:#047857">Status updated.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Age -->
                <div class="snapshot-row">
                    <div class="snapshot-icon si-amber"><i class="ri-timer-line"></i></div>
                    <div class="snapshot-content">
                        <div class="snapshot-label">Laptop age</div>
                        <div class="snapshot-value"><?= htmlspecialchars($ageText) ?></div>
                        <div class="snapshot-sub">From PO date to today</div>
                    </div>
                </div>

                <!-- Latest handover -->
                <div class="snapshot-row">
                    <div class="snapshot-icon si-green"><i class="ri-exchange-line"></i></div>
                    <div class="snapshot-content">
                        <div class="snapshot-label">Latest handover</div>
                        <?php if ($latestHandover): ?>
                            <div class="snapshot-value"><?= htmlspecialchars($fmtDate($latestHandover['handover_date'] ?? null)) ?></div>
                            <div class="snapshot-sub">
                                <?= htmlspecialchars((string)($latestHandover['recipient_name'] ?? ($latestHandover['employee_no'] ?? '—'))) ?>
                                <?= !empty($latestHandover['recipient_dept']) ? ' · ' . htmlspecialchars((string)$latestHandover['recipient_dept']) : '' ?>
                            </div>
                        <?php else: ?>
                            <div class="snapshot-value" style="color:var(--text-muted);font-weight:400">No handover recorded</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Warranty -->
                <div class="snapshot-row">
                    <div class="snapshot-icon si-amber"><i class="ri-shield-check-line"></i></div>
                    <div class="snapshot-content">
                        <div class="snapshot-label">Warranty</div>
                        <?php if ($warranty): ?>
                            <div class="snapshot-value"><?= htmlspecialchars($fmtDate($warranty['warranty_start_date'] ?? null)) ?> → <?= htmlspecialchars($fmtDate($warranty['warranty_end_date'] ?? null)) ?></div>
                            <?php if ($inWarranty): ?>
                                <div class="warranty-active"><i class="ri-checkbox-circle-fill"></i> Active</div>
                            <?php else: ?>
                                <div class="warranty-expired"><i class="ri-close-circle-fill"></i> Expired</div>
                            <?php endif; ?>
                            <?php if (!empty($warranty['warranty_remarks'])): ?>
                                <div class="snapshot-sub"><?= htmlspecialchars((string)$warranty['warranty_remarks']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="snapshot-value" style="color:var(--text-muted);font-weight:400">No warranty on file</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Trail count -->
                <div class="snapshot-row">
                    <div class="snapshot-icon si-blue"><i class="ri-history-line"></i></div>
                    <div class="snapshot-content">
                        <div class="snapshot-label">Activity events</div>
                        <div class="snapshot-value"><?= count($events) ?> event<?= count($events) !== 1 ? 's' : '' ?> recorded</div>
                    </div>
                </div>

            </div>
        </div>

    </div><!-- /layout-grid -->

    <!-- ── Asset trails ── -->
    <div class="card">
        <div class="card-hd" style="padding:0.9rem 1.25rem">
            <div class="card-hd-title"><i class="ri-time-line"></i> Asset trails</div>
        </div>
        <div class="trail-toolbar">
            <div class="search-field">
                <i class="ri-search-line"></i>
                <input type="text" id="trailSearch" placeholder="Search by type, person, date…">
            </div>
            <span class="trail-count" id="trailCount"><?= count($events) ?> event<?= count($events) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (!$events): ?>
            <div class="empty-trail">
                <i class="ri-inbox-line"></i>
                No activity recorded for this asset.
            </div>
        <?php else: ?>
        <div class="timeline" id="timeline">
            <?php foreach ($events as $e):
                $tm  = $typeMeta[$e['type']] ?? ['i'=>'ri-record-circle-line','c'=>'var(--text-muted)','label'=>strtoupper((string)$e['type']),'pill'=>'pill-blue'];
                $metaTxt   = isset($e['meta'])    && $e['meta']    !== null && (string)$e['meta']    !== '' ? (string)$e['meta']    : null;
                $detailTxt = isset($e['details']) && $e['details'] !== null && trim((string)$e['details']) !== '' ? trim((string)$e['details']) : null;
                $dotClass  = 'dot-' . htmlspecialchars((string)$e['type']);
                $searchStr = strtolower((string)($e['title'] ?? '') . ' ' . ($metaTxt ?? '') . ' ' . ($detailTxt ?? '') . ' ' . ($e['type'] ?? '') . ' ' . ($e['at'] ?? ''));
            ?>
            <div class="tl-item tl-line" data-search="<?= htmlspecialchars($searchStr) ?>">
                <div class="tl-dot-wrap">
                    <div class="tl-dot <?= $dotClass ?>">
                        <i class="<?= htmlspecialchars((string)$tm['i']) ?>"></i>
                    </div>
                </div>
                <div class="tl-body">
                    <div class="tl-header">
                        <span class="tl-title"><?= htmlspecialchars((string)($e['title'] ?? '')) ?></span>
                        <span class="tl-pill <?= htmlspecialchars((string)$tm['pill']) ?>"><?= htmlspecialchars((string)$tm['label']) ?></span>
                        <span class="tl-time"><?= htmlspecialchars($fmtDateTime($e['at'] ?? null)) ?></span>
                    </div>
                    <?php if ($metaTxt): ?>
                        <div class="tl-meta"><?= htmlspecialchars($metaTxt) ?></div>
                    <?php endif; ?>
                    <?php if ($detailTxt): ?>
                        <div class="tl-detail"><?= htmlspecialchars($detailTxt) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div id="emptyTrail" style="display:none" class="empty-trail">
            <i class="ri-search-line"></i>
            No events match your search.
        </div>
        <?php endif; ?>
    </div>

</main>

<script>
// ── Tab switching ──
document.getElementById('tabBar')?.addEventListener('click', function (e) {
    var btn = e.target.closest('.tab-btn');
    if (!btn) return;
    var tab = btn.getAttribute('data-tab');
    document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
    document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
    btn.classList.add('active');
    var panel = document.getElementById('tab-' + tab);
    if (panel) panel.classList.add('active');
});

// ── Trail search ──
var trailSearchEl = document.getElementById('trailSearch');
var trailCountEl  = document.getElementById('trailCount');
var emptyTrailEl  = document.getElementById('emptyTrail');

if (trailSearchEl) {
    trailSearchEl.addEventListener('input', function () {
        var q = trailSearchEl.value.toLowerCase().trim();
        var items = document.querySelectorAll('#timeline .tl-item');
        var visible = 0;
        items.forEach(function (item) {
            var searchStr = (item.getAttribute('data-search') || '').toLowerCase();
            var show = q === '' || searchStr.includes(q);
            item.classList.toggle('hidden-tl', !show);
            if (show) visible++;
        });
        if (trailCountEl) trailCountEl.textContent = visible + (visible === 1 ? ' event' : ' events');
        if (emptyTrailEl) emptyTrailEl.style.display = visible === 0 ? 'block' : 'none';
    });
}

// ── Auto-save status dropdown ──
var statusSelect = document.getElementById('statusSelect');
if (statusSelect && statusSelect.form) {
    statusSelect.addEventListener('change', function () {
        statusSelect.form.submit();
    });
}
</script>
</body>
</html>