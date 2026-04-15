<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;
if ($assetId <= 0) { header('Location: av.php'); exit; }

$pdo = db();

// Allow quick status updates from this page (only status_id 1 or 2)
$statusFlash = '';
$remarksFlash = '';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
        $upd = $pdo->prepare("UPDATE av SET status_id = ? WHERE asset_id = ? LIMIT 1");
        $upd->execute([$newStatusId, $assetId]);
        header('Location: avView.php?asset_id=' . urlencode((string)$assetId) . '&status_updated=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_remarks') {
    $postedAssetId = (int)($_POST['asset_id'] ?? 0);
    $csrf          = (string)($_POST['csrf'] ?? '');
    $newRemarks    = trim((string)($_POST['remarks'] ?? ''));

    $csrfOk = isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $csrf);
    if (!$csrfOk) {
        $remarksFlash = 'Invalid session token. Please refresh and try again.';
    } elseif ($postedAssetId !== $assetId) {
        $remarksFlash = 'Invalid asset selected.';
    } elseif (mb_strlen($newRemarks) > 1000) {
        $remarksFlash = 'Remarks is too long (max 1000 characters).';
    } else {
        $upd = $pdo->prepare("UPDATE av SET remarks = ? WHERE asset_id = ? LIMIT 1");
        $upd->execute([$newRemarks, $assetId]);
        header('Location: avView.php?asset_id=' . urlencode((string)$assetId) . '&remarks_updated=1');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT a.*, s.name AS status_name FROM av a JOIN status s ON s.status_id = a.status_id WHERE a.asset_id = ? LIMIT 1");
$stmt->execute([$assetId]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$asset) { header('Location: av.php'); exit; }

$stmtAllowedStatus = $pdo->query("SELECT status_id, name FROM status WHERE status_id IN (1,2) ORDER BY status_id ASC");
$allowedStatuses = $stmtAllowedStatus ? $stmtAllowedStatus->fetchAll(PDO::FETCH_ASSOC) : [];

$stmtW = $pdo->prepare("SELECT warranty_id, warranty_start_date, warranty_end_date, warranty_remarks, created_at FROM warranty WHERE asset_type = 'av' AND asset_id = ? ORDER BY warranty_end_date DESC, warranty_id DESC LIMIT 1");
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

$stmtDLatest = $pdo->prepare("
    SELECT d.deployment_id, d.deployment_date, d.building, d.level, d.zone, d.deployment_remarks,
           d.staff_id, u.full_name AS deployed_by_name, d.created_at
    FROM av_deployment d
    LEFT JOIN users u ON u.staff_id = d.staff_id
    WHERE d.asset_id = ?
    ORDER BY d.deployment_date DESC, d.created_at DESC, d.deployment_id DESC
    LIMIT 1
");
$stmtDLatest->execute([$assetId]);
$latestDeployment = $stmtDLatest->fetch(PDO::FETCH_ASSOC) ?: null;

$events = [];
$events[] = ['type' => 'register', 'at' => (string)($asset['created_at'] ?? ''), 'title' => 'Asset registered', 'meta' => null, 'details' => null];

$stmtDTrail = $pdo->prepare("
    SELECT
        d.deployment_id,
        d.deployment_date,
        d.building,
        d.level,
        d.zone,
        d.deployment_remarks,
        d.staff_id AS deployed_by,
        u.full_name AS deployed_by_name,
        d.created_at AS deployment_created_at,
        r.return_id,
        r.return_date,
        r.return_time,
        r.return_place,
        r.condition AS return_condition,
        r.return_remarks,
        r.returned_by,
        ur.full_name AS returned_by_name,
        r.created_at AS return_created_at
    FROM av_deployment d
    LEFT JOIN users u ON u.staff_id = d.staff_id
    LEFT JOIN av_return r ON r.deployment_id = d.deployment_id
    LEFT JOIN users ur ON ur.staff_id = r.returned_by
    WHERE d.asset_id = ?
    ORDER BY d.deployment_id DESC, r.return_id DESC
");
$stmtDTrail->execute([$assetId]);
$deployments = $stmtDTrail->fetchAll(PDO::FETCH_ASSOC);

foreach ($deployments as $d) {
    $loc = trim((string)($d['building'] ?? ''));
    $lvl = trim((string)($d['level'] ?? ''));
    $zon = trim((string)($d['zone'] ?? ''));
    $metaParts = array_values(array_filter([$loc, $lvl, $zon], static function ($v): bool { return $v !== ''; }));
    $meta = $metaParts ? implode(' • ', $metaParts) : null;
    $by = trim((string)($d['deployed_by_name'] ?? ''));
    if ($by === '') $by = (string)($d['deployed_by'] ?? '—');
    $details = ($d['deployment_remarks'] ?? null) ? (string)$d['deployment_remarks'] : null;
    $events[] = ['type' => 'deploy', 'at' => (string)($d['deployment_created_at'] ?? ''), 'title' => 'Deployment recorded', 'meta' => ($meta ? ($by . ' • ' . $meta) : $by), 'details' => $details, 'deployment_id' => (int)$d['deployment_id']];

    if (!empty($d['return_id'])) {
        $retMetaParts = [];
        if (!empty($d['return_place'])) $retMetaParts[] = (string)$d['return_place'];
        if (!empty($d['return_condition'])) $retMetaParts[] = 'Condition: ' . (string)$d['return_condition'];
        $rDetails = ($d['return_remarks'] ?? null) ? (string)$d['return_remarks'] : null;
        $events[] = ['type' => 'return', 'at' => (string)($d['return_created_at'] ?? ''), 'title' => 'Return recorded', 'meta' => $retMetaParts ? implode(' • ', $retMetaParts) : null, 'details' => $rDetails, 'deployment_id' => (int)$d['deployment_id']];
    }
}

$stmtWH = $pdo->prepare("SELECT warranty_id, warranty_start_date, warranty_end_date, warranty_remarks, created_at FROM warranty WHERE asset_type = 'av' AND asset_id = ? ORDER BY created_at DESC, warranty_id DESC");
$stmtWH->execute([$assetId]);
foreach ($stmtWH->fetchAll(PDO::FETCH_ASSOC) as $w) {
    $range = (!empty($w['warranty_start_date']) && !empty($w['warranty_end_date'])) ? (string)$w['warranty_start_date'] . ' → ' . (string)$w['warranty_end_date'] : null;
    $events[] = ['type' => 'warranty', 'at' => (string)($w['created_at'] ?? ''), 'title' => 'Warranty recorded', 'meta' => $range, 'details' => ($w['warranty_remarks'] ?? null) ? (string)$w['warranty_remarks'] : null];
}

$stmtC = $pdo->prepare("SELECT claim_id, claim_date, claim_time, issue_summary, claim_remarks, created_at FROM warranty_claim WHERE asset_type = 'av' AND asset_id = ? ORDER BY created_at DESC, claim_id DESC");
$stmtC->execute([$assetId]);
foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $when = (string)($c['claim_date'] ?? '');
    if (!empty($c['claim_time'])) $when .= ' ' . (string)$c['claim_time'];
    $events[] = ['type' => 'claim', 'at' => (string)($c['created_at'] ?? ''), 'title' => 'Warranty claim logged', 'meta' => $when !== '' ? $when : null, 'details' => trim((string)($c['issue_summary'] ?? '')) . (($c['claim_remarks'] ?? null) ? ' — ' . (string)$c['claim_remarks'] : '')];
}

$stmtR = $pdo->prepare("SELECT repair_id, repair_date, completed_date, issue_summary, repair_remarks, created_at FROM repair WHERE asset_type = 'av' AND asset_id = ? ORDER BY created_at DESC, repair_id DESC");
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

$deviceName = trim((string)($asset['category'] ?? '') . ' ' . (string)($asset['brand'] ?? '') . ' ' . (string)($asset['model'] ?? ''));
if ($deviceName === '') $deviceName = 'Unknown Device';

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
        }
    } catch (Throwable $e) {
        $ageText = '—';
    }
}

$typeMeta = [
    'register' => ['i'=>'ri-add-circle-line',    'c'=>'var(--primary)',  'label'=>'Register', 'pill'=>'pill-blue'],
    'deploy'   => ['i'=>'ri-truck-line',         'c'=>'var(--success)',  'label'=>'Deploy',   'pill'=>'pill-green'],
    'return'   => ['i'=>'ri-arrow-go-back-line', 'c'=>'var(--warning)',  'label'=>'Return',   'pill'=>'pill-amber'],
    'warranty' => ['i'=>'ri-shield-check-line',  'c'=>'var(--primary)',  'label'=>'Warranty', 'pill'=>'pill-blue'],
    'claim'    => ['i'=>'ri-file-list-2-line',   'c'=>'var(--secondary)','label'=>'Claim',    'pill'=>'pill-sky'],
    'repair'   => ['i'=>'ri-tools-line',         'c'=>'var(--danger)',   'label'=>'Repair',   'pill'=>'pill-red'],
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

        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2rem 2.5rem 3.5rem;
            max-width: calc(100vw - 280px);
        }
        @media (max-width: 900px) {
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.25rem 1rem 2.5rem; }
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.75rem;
        }
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
        .btn-action {
            background: linear-gradient(135deg, #021A54, #1e40af);
            color: #fff;
            box-shadow: 0 4px 14px rgba(2,26,84,0.25);
        }
        .btn-action:hover { filter: brightness(1.08); transform: translateY(-1px); }

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
        .s-muted  { background: rgba(100,116,139,0.1); border-color: rgba(100,116,139,0.2); color: #475569; }
        .s-orange { background: rgba(249,115,22,0.1);  border-color: rgba(249,115,22,0.25); color: #c2410c; }

        .layout-grid {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 1.25rem;
            align-items: start;
            margin-bottom: 1.25rem;
        }
        @media (max-width: 1100px) { .layout-grid { grid-template-columns: 1fr; } }

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
        .mono-val {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            color: var(--text-main);
            font-size: 0.82rem;
        }

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

        .tab-panel { display: none; padding: 1.25rem; }
        .tab-panel.active { display: block; }

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
        .kv-row:nth-child(even) { border-right: none; }
        .kv-row:nth-last-child(-n+2) { border-bottom: none; }
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
        .tl-dot.dot-deploy   { border-color: rgba(16,185,129,0.35); background: rgba(16,185,129,0.08); color: #047857; }
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

        .empty-trail {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-muted);
        }
        .empty-trail i { font-size: 2rem; opacity: 0.3; display: block; margin-bottom: 0.5rem; }

        .tl-item.hidden-tl { display: none; }

        /* ── Status dropdown / remarks editor ── */
        .status-form { display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-top:0.45rem; }
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
        .flash { margin-top: 0.55rem; font-size: 0.78rem; color: #b91c1c; font-weight: 600; }
        .remarks-textarea{
            width:100%;
            border: 1.5px solid var(--card-border);
            border-radius: 12px;
            padding: 0.7rem 0.85rem;
            font-size: 0.88rem;
            font-family: inherit;
            resize: vertical;
            min-height: 90px;
            outline: none;
            background: #fff;
        }
        .remarks-textarea:focus{ border-color: rgba(37,99,235,0.45); box-shadow: 0 0 0 3px rgba(37,99,235,0.10); }
        .remarks-actions{display:flex;align-items:center;gap:0.6rem;margin-top:0.6rem;flex-wrap:wrap;}
        .remarks-hint{font-size:0.75rem;color:var(--text-muted);font-weight:600;}
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">

    <header class="page-header">
        <div class="page-header-left">
            <a class="back-link" href="av.php"><i class="ri-arrow-left-line"></i> Back to inventory</a>
            <h1 class="page-title">
                <?= htmlspecialchars($deviceName) ?>
                <span class="asset-id-badge">#<?= (int)$assetId ?></span>
            </h1>
            <p class="page-subtitle">Asset details and activity history.</p>
        </div>
        <div class="header-actions">
            <?php if ($statusId === 1): ?>
                <a class="btn btn-action" href="avDeploy.php?asset_id=<?= urlencode((string)$assetId) ?>"><i class="ri-truck-line"></i> Deploy</a>
            <?php elseif ($statusId === 3): ?>
                <a class="btn btn-action" href="avReturn.php?asset_id=<?= urlencode((string)$assetId) ?>"><i class="ri-arrow-go-back-line"></i> Return</a>
            <?php elseif ($statusId === 6): ?>
                <?php if ($inWarranty): ?>
                    <a class="btn btn-action" href="warranty.php?asset_id=<?= urlencode((string)$assetId) ?>"><i class="ri-shield-check-line"></i> Warranty</a>
                <?php else: ?>
                    <a class="btn btn-action" href="repair.php?asset_id=<?= urlencode((string)$assetId) ?>"><i class="ri-tools-line"></i> Repair</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <div class="layout-grid">
        <div class="card">
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

            <div class="tab-bar" id="tabBar">
                <button class="tab-btn active" data-tab="specs"><i class="ri-file-list-3-line"></i> Details</button>
                <button class="tab-btn" data-tab="purchase"><i class="ri-shopping-bag-3-line"></i> Purchase</button>
                <button class="tab-btn" data-tab="meta"><i class="ri-time-line"></i> Record info</button>
            </div>

            <div class="tab-panel active" id="tab-specs">
                <div class="kv-grid">
                    <div class="kv-row">
                        <span class="kv-label">Category</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['category'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Brand</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['brand'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Model</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['model'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Serial number</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['serial_num'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Old asset ID</span>
                        <span class="kv-val"><?= htmlspecialchars((string)($asset['asset_id_old'] ?? '—')) ?></span>
                    </div>
                    <div class="kv-row">
                        <span class="kv-label">Remarks</span>
                        <form method="POST" action="" style="width:100%">
                            <input type="hidden" name="action" value="save_remarks">
                            <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">
                            <textarea class="remarks-textarea" name="remarks" maxlength="1000" placeholder="Add notes about this asset..."><?= htmlspecialchars((string)($asset['remarks'] ?? '')) ?></textarea>
                            <div class="remarks-actions">
                                <button type="submit" class="btn btn-action" style="padding:0.48rem 0.7rem;border-radius:10px;font-size:0.82rem"><i class="ri-save-3-line"></i> Save</button>
                                <span class="remarks-hint">Max 1000 characters</span>
                            </div>
                            <?php if (!empty($remarksFlash)): ?>
                                <div class="flash"><?= htmlspecialchars($remarksFlash) ?></div>
                            <?php elseif (!empty($_GET['remarks_updated'])): ?>
                                <div class="flash" style="color:#047857">Remarks updated.</div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

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
        </div>

        <div class="card">
            <div class="card-hd">
                <div class="card-hd-title"><i class="ri-dashboard-line"></i> Snapshot</div>
            </div>
            <div class="snapshot-list">
                <div class="snapshot-row">
                    <div class="snapshot-icon si-blue"><i class="ri-pulse-line"></i></div>
                    <div>
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

                <div class="snapshot-row">
                    <div class="snapshot-icon si-amber"><i class="ri-timer-line"></i></div>
                    <div>
                        <div class="snapshot-label">Asset age</div>
                        <div class="snapshot-value"><?= htmlspecialchars($ageText) ?></div>
                        <div class="snapshot-sub">From PO date to today</div>
                    </div>
                </div>

                <div class="snapshot-row">
                    <div class="snapshot-icon si-green"><i class="ri-truck-line"></i></div>
                    <div>
                        <div class="snapshot-label">Latest deployment</div>
                        <?php if ($latestDeployment): ?>
                            <div class="snapshot-value"><?= htmlspecialchars($fmtDate($latestDeployment['deployment_date'] ?? null)) ?></div>
                            <div class="snapshot-sub">
                                <?= htmlspecialchars(trim((string)($latestDeployment['building'] ?? '') . ' ' . (string)($latestDeployment['level'] ?? '') . ' ' . (string)($latestDeployment['zone'] ?? '')) ?: '—') ?>
                            </div>
                        <?php else: ?>
                            <div class="snapshot-value" style="color:var(--text-muted);font-weight:400">No deployment recorded</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="snapshot-row">
                    <div class="snapshot-icon si-amber"><i class="ri-shield-check-line"></i></div>
                    <div>
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

                <div class="snapshot-row">
                    <div class="snapshot-icon si-blue"><i class="ri-history-line"></i></div>
                    <div>
                        <div class="snapshot-label">Activity events</div>
                        <div class="snapshot-value"><?= count($events) ?> event<?= count($events) !== 1 ? 's' : '' ?> recorded</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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

var statusSelect = document.getElementById('statusSelect');
if (statusSelect && statusSelect.form) {
    statusSelect.addEventListener('change', function () {
        statusSelect.form.submit();
    });
}
</script>
</body>
</html>

