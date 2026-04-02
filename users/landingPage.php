<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] !== 3)) {
    header('Location: ../auth/login.php');
    exit;
}

$userName = trim((string)($_SESSION['user_name'] ?? 'User'));
$staffId  = (string)($_SESSION['staff_id'] ?? '');

require_once __DIR__ . '/../config/database.php';

$equipment_catalog = [
    ['id' => 'portable_speaker', 'name' => 'Portable Speaker',  'category' => 'Audio equipment',          'icon' => 'ri-volume-up-line'],
    ['id' => 'microphone',       'name' => 'Microphone',        'category' => 'Audio Visual accessories', 'icon' => 'ri-mic-line'],
    ['id' => 'pocket_mic',       'name' => 'Pocket Mic',        'category' => 'Audio Visual accessories', 'icon' => 'ri-mic-2-line'],
    ['id' => 'tripod',           'name' => 'Tripod',            'category' => 'Audio Visual accessories', 'icon' => 'ri-camera-line'],
    ['id' => 'laptop',           'name' => 'Laptop',            'category' => 'Computer',                 'icon' => 'ri-laptop-line'],
    ['id' => 'projector',        'name' => 'Projector',         'category' => 'Visual equipment',         'icon' => 'ri-slideshow-line'],
    ['id' => 'video_camera',     'name' => 'Video Camera',      'category' => 'Visual equipment',         'icon' => 'ri-vidicon-line'],
    ['id' => 'webcam',           'name' => 'Webcam',            'category' => 'Visual equipment',         'icon' => 'ri-webcam-line'],
];
$equipment_ids = array_column($equipment_catalog, 'id');
$equipment_category_label = [];
foreach ($equipment_catalog as $eq) {
    $equipment_category_label[$eq['id']] = $eq['name'] . ' — ' . $eq['category'];
}

$form_error = '';
$values = ['borrow_date' => '', 'return_date' => '', 'program_type' => '', 'usage_location' => '', 'reason' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['borrow_date']    = trim((string)($_POST['borrow_date']    ?? ''));
    $values['return_date']    = trim((string)($_POST['return_date']    ?? ''));
    $values['program_type']   = trim((string)($_POST['program_type']   ?? ''));
    $values['usage_location'] = trim((string)($_POST['usage_location'] ?? ''));
    $values['reason']         = trim((string)($_POST['reason']         ?? ''));
    $qtyPost = $_POST['qty'] ?? [];
    if (!is_array($qtyPost)) $qtyPost = [];
    $lineItems = [];
    foreach ($equipment_ids as $eqId) {
        $q = isset($qtyPost[$eqId]) ? (int)$qtyPost[$eqId] : 0;
        if ($q < 1) continue;
        $lineItems[] = ['id' => $eqId, 'qty' => min(99, $q)];
    }
    $accept = isset($_POST['accept_terms']) && (string)$_POST['accept_terms'] === '1';
    $valid_programs = ['academic', 'official_event', 'club_society'];

    if ($values['borrow_date'] === '' || $values['return_date'] === '')
        $form_error = 'Please choose both borrow and return dates.';
    elseif ($values['borrow_date'] > $values['return_date'])
        $form_error = 'Return date must be on or after the borrow date.';
    elseif (!in_array($values['program_type'], $valid_programs, true))
        $form_error = 'Please select a program type.';
    elseif ($values['usage_location'] === '')
        $form_error = 'Please enter the usage location.';
    elseif (mb_strlen($values['reason']) < 5)
        $form_error = 'Please enter a reason (at least a few words).';
    elseif ($lineItems === [])
        $form_error = 'Add at least one item to your request.';
    elseif (!$accept)
        $form_error = 'You must read and accept the terms and conditions.';

    if ($form_error === '') {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO nexcheck_request (requested_by, borrow_date, return_date, program_type, usage_location, reason, terms_accepted_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$staffId, $values['borrow_date'], $values['return_date'], $values['program_type'], $values['usage_location'], $values['reason'] !== '' ? $values['reason'] : null]);
            $nexcheckId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO nexcheck_request_item (nexcheck_id, category, quantity) VALUES (?, ?, ?)');
            foreach ($lineItems as $row) {
                $label = $equipment_category_label[$row['id']] ?? $row['id'];
                for ($u = 0; $u < $row['qty']; $u++) $itemStmt->execute([$nexcheckId, $label, 1]);
            }
            $pdo->commit();
            header('Location: landingPage.php?submitted=1');
            exit;
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $form_error = 'Could not save your request. Please try again or contact support.';
        }
    }
}

$submitted_ok = isset($_GET['submitted']) && (string)$_GET['submitted'] === '1';

$initial_qty = [];
if ($form_error !== '' && isset($_POST['qty']) && is_array($_POST['qty'])) {
    foreach ($equipment_ids as $eqId) {
        $q = isset($_POST['qty'][$eqId]) ? (int)$_POST['qty'][$eqId] : 0;
        if ($q > 0) $initial_qty[$eqId] = min(99, max(1, $q));
    }
}

$request_count = 0;
try {
    $stCnt = db()->prepare('SELECT COUNT(*) FROM nexcheck_request WHERE requested_by = ?');
    $stCnt->execute([$staffId]);
    $request_count = (int)$stCnt->fetchColumn();
} catch (Throwable $e) {}

$welcome_first = $userName;
if (preg_match('/^\S+/', $userName, $m)) $welcome_first = $m[0];

$user_initials = '';
foreach (preg_split('/\s+/', $userName) as $p) {
    if ($p === '') continue;
    $user_initials .= mb_strtoupper(mb_substr($p, 0, 1));
    if (mb_strlen($user_initials) >= 2) break;
}
if ($user_initials === '') $user_initials = mb_strtoupper(mb_substr($userName !== '' ? $userName : 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NextCheck — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary:       #2563eb;
            --primary-light: #3b82f6;
            --primary-dark:  #1d4ed8;
            --secondary:     #0ea5e9;
            --danger:        #dc2626;
            --success:       #10b981;
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
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            -webkit-text-size-adjust: 100%;
        }

        /* ════════════════════════════
           TOP NAV BAR
        ════════════════════════════ */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: var(--card-bg);
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0 1rem;
            height: 56px;
            padding-top: env(safe-area-inset-top);
        }
        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            text-decoration: none;
            color: inherit;
            flex-shrink: 0;
        }
        .topbar-brand img {
            height: 30px;
            width: auto;
            object-fit: contain;
        }
        .topbar-brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
        }
        .topbar-brand-text strong {
            font-family: 'Outfit', sans-serif;
            font-size: 0.85rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .topbar-brand-text small {
            font-size: 0.58rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--primary);
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-shrink: 0;
        }
        .topbar-avatar {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .topbar-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            line-height: 1.2;
        }
        .topbar-name {
            font-size: 0.78rem;
            font-weight: 700;
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .topbar-logout {
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--primary);
            text-decoration: none;
        }
        .topbar-logout:hover { text-decoration: underline; }

        /* Hide meta text on tiny screens */
        @media (max-width: 380px) { .topbar-meta { display: none; } }

        /* ════════════════════════════
           MAIN SCROLL AREA
        ════════════════════════════ */
        .lp-main {
            flex: 1;
            width: 100%;
            max-width: 560px;
            margin: 0 auto;
            padding: 1.1rem 0.9rem 2rem;
        }
        @media (min-width: 600px) {
            .lp-main { padding: 1.5rem 1.25rem 2.5rem; }
        }

        /* ════════════════════════════
           BANNERS
        ════════════════════════════ */
        .banner {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            padding: 0.85rem 1rem;
            border-radius: 14px;
            margin-bottom: 1.1rem;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        .banner i { font-size: 1.1rem; flex-shrink: 0; margin-top: 0.05rem; }
        .banner-success { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.28); color: #047857; }
        .banner-error   { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.22); color: #b91c1c; }

        /* ════════════════════════════
           WELCOME STRIP (compact)
        ════════════════════════════ */
        .welcome-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 0.9rem 1rem;
            margin-bottom: 1.1rem;
        }
        .welcome-left {}
        .welcome-kicker {
            font-size: 0.6rem;
            font-weight: 800;
            letter-spacing: 0.13em;
            text-transform: uppercase;
            color: var(--primary);
            margin-bottom: 0.2rem;
        }
        .welcome-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }
        .welcome-count {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        .btn-history {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 0.9rem;
            border-radius: 10px;
            background: rgba(37,99,235,0.08);
            border: 1px solid rgba(37,99,235,0.2);
            color: var(--primary);
            font-weight: 700;
            font-size: 0.78rem;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
            flex-shrink: 0;
            font-family: 'Outfit', sans-serif;
        }
        .btn-history:hover { background: var(--primary); color: #fff; }

        /* ════════════════════════════
           SECTION LABEL
        ════════════════════════════ */
        .section-label {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            margin-bottom: 0.65rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .section-label i { font-size: 0.9rem; color: var(--primary); }

        /* ════════════════════════════
           PILL STEPPER (horizontal compact)
        ════════════════════════════ */
        .stepper {
            display: flex;
            align-items: center;
            margin-bottom: 1.1rem;
            gap: 0;
        }
        .stepper-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
            z-index: 1;
        }
        .stepper-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 14px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: var(--card-border);
            z-index: 0;
            transition: background 0.35s;
        }
        .stepper-item.done:not(:last-child)::after { background: var(--primary); }
        .stepper-item.active:not(:last-child)::after { background: linear-gradient(90deg,var(--primary),var(--card-border)); }

        .step-dot {
            width: 28px; height: 28px;
            border-radius: 50%;
            border: 2px solid var(--card-border);
            background: var(--card-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.75rem;
            color: var(--text-muted);
            position: relative;
            z-index: 2;
            transition: all 0.25s cubic-bezier(.4,0,.2,1);
        }
        .stepper-item.active .step-dot {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            box-shadow: 0 0 0 4px rgba(37,99,235,0.15);
            transform: scale(1.12);
        }
        .stepper-item.done .step-dot {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: #fff;
        }
        .step-lbl {
            font-size: 0.58rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-top: 0.3rem;
            text-align: center;
        }
        .stepper-item.active .step-lbl { color: var(--primary); }
        .stepper-item.done .step-lbl   { color: var(--primary-dark); }

        /* ════════════════════════════
           STEP PANELS
        ════════════════════════════ */
        .step-panel { display: none; }
        .step-panel.active {
            display: block;
            animation: fadeUp 0.28s cubic-bezier(.4,0,.2,1);
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ════════════════════════════
           CARD
        ════════════════════════════ */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            overflow: hidden;
        }
        .card-hd {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--card-border);
            background: var(--glass);
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.92rem;
        }
        .card-hd i { color: var(--primary); font-size: 1rem; }
        .card-hd-step {
            margin-left: auto;
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .card-bd { padding: 1rem; }

        /* ════════════════════════════
           FORM FIELDS
        ════════════════════════════ */
        .field { display: flex; flex-direction: column; gap: 0.35rem; }
        .field + .field { margin-top: 0.85rem; }
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        @media (max-width: 380px) { .field-row { grid-template-columns: 1fr; } }

        .field-label {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
        }
        .input, select.input, textarea.input {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1.5px solid var(--card-border);
            border-radius: 11px;
            font-family: inherit;
            font-size: 1rem; /* 16px prevents iOS zoom */
            background: var(--glass);
            color: var(--text-main);
            appearance: none;
            outline: none;
            transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
        }
        .input:focus, select.input:focus, textarea.input:focus {
            border-color: rgba(37,99,235,0.5);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        textarea.input { min-height: 90px; resize: vertical; }
        select.input {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2364748b'%3E%3Cpath d='M12 16L6 10H18L12 16Z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1rem;
            padding-right: 2rem;
        }

        /* ════════════════════════════
           PROGRAM TYPE (compact horizontal)
        ════════════════════════════ */
        .program-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin-top: 0.35rem;
        }
        .program-radio { display: none; }
        .program-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.35rem;
            padding: 0.75rem 0.4rem;
            border: 1.5px solid var(--card-border);
            border-radius: 12px;
            cursor: pointer;
            text-align: center;
            background: var(--glass);
            user-select: none;
            transition: all 0.18s;
            -webkit-tap-highlight-color: transparent;
        }
        .program-label i { font-size: 1.3rem; color: var(--text-muted); transition: color 0.18s; }
        .pl-name { font-weight: 700; font-size: 0.72rem; line-height: 1.2; }
        .pl-desc { font-size: 0.62rem; color: var(--text-muted); line-height: 1.25; display: none; }
        @media (min-width: 420px) { .pl-desc { display: block; } }
        .program-radio:checked + .program-label {
            border-color: var(--primary);
            background: rgba(37,99,235,0.06);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .program-radio:checked + .program-label i { color: var(--primary); }

        /* ════════════════════════════
           EQUIPMENT LIST
        ════════════════════════════ */
        .equipment-list { display: flex; flex-direction: column; gap: 0.45rem; }
        .eq-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.65rem 0.75rem;
            border: 1.5px solid var(--card-border);
            border-radius: 12px;
            background: var(--glass);
            transition: border-color 0.18s, background 0.18s;
        }
        .eq-row:active { background: rgba(37,99,235,0.04); }
        .eq-row.in-cart {
            border-color: var(--primary);
            background: rgba(37,99,235,0.05);
        }
        .eq-row-main {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex: 1;
            min-width: 0;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        .eq-icon {
            width: 36px; height: 36px;
            border-radius: 9px;
            background: rgba(37,99,235,0.09);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .eq-icon i { font-size: 1rem; color: var(--primary); }
        .eq-row.in-cart .eq-icon { background: rgba(37,99,235,0.15); }
        .eq-name { font-weight: 700; font-size: 0.85rem; }
        .eq-cat  { font-size: 0.68rem; color: var(--text-muted); margin-top: 0.08rem; }

        .eq-row-actions {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            flex-shrink: 0;
        }
        .eq-qty-btn {
            width: 34px; height: 34px;
            border-radius: 9px;
            border: 1.5px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-muted);
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s;
            -webkit-tap-highlight-color: transparent;
        }
        .eq-qty-btn[data-eq-delta="1"] {
            color: var(--primary);
            border-color: rgba(37,99,235,0.3);
            background: rgba(37,99,235,0.07);
        }
        .eq-qty-btn[data-eq-delta="1"]:active:not(:disabled) { background: rgba(37,99,235,0.18); }
        .eq-qty-btn[data-eq-delta="-1"]:active:not(:disabled) { border-color: var(--danger); color: var(--danger); }
        .eq-qty-btn:disabled { opacity: 0.3; cursor: not-allowed; }
        .eq-qty-val {
            min-width: 1.5rem;
            text-align: center;
            font-weight: 800;
            font-size: 0.9rem;
            font-variant-numeric: tabular-nums;
            color: var(--text-main);
        }

        /* ════════════════════════════
           SELECTED SUMMARY (chips)
        ════════════════════════════ */
        .summary-section {
            margin-top: 0.85rem;
            padding-top: 0.85rem;
            border-top: 1px solid var(--card-border);
        }
        .summary-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-muted);
            margin-bottom: 0.45rem;
        }
        .selected-summary { display: flex; flex-wrap: wrap; gap: 0.4rem; min-height: 28px; }
        .sel-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.28rem 0.45rem 0.28rem 0.6rem;
            background: rgba(37,99,235,0.09);
            border: 1px solid rgba(37,99,235,0.22);
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary-dark);
        }
        .chip-qty-btns {
            display: inline-flex;
            align-items: center;
            gap: 0.1rem;
            margin-left: 0.1rem;
        }
        .chip-qty-btns button {
            width: 22px; height: 22px;
            border-radius: 6px;
            border: none;
            background: rgba(37,99,235,0.14);
            color: var(--primary-dark);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            -webkit-tap-highlight-color: transparent;
        }
        .chip-qty-btns button:active { background: rgba(37,99,235,0.28); }
        .empty-chips { font-size: 0.78rem; color: var(--text-muted); font-style: italic; }

        /* ════════════════════════════
           TERMS
        ════════════════════════════ */
        .terms-box {
            max-height: 200px;
            overflow-y: auto;
            padding: 0.85rem 0.95rem;
            border-radius: 11px;
            border: 1px solid var(--card-border);
            background: var(--glass);
            font-size: 0.82rem;
            line-height: 1.6;
            margin-bottom: 0.6rem;
            -webkit-overflow-scrolling: touch;
            scroll-behavior: smooth;
        }
        .terms-box ul { padding-left: 1.1rem; }
        .terms-box li { margin-bottom: 0.5rem; }
        .terms-danger { color: var(--danger); font-weight: 600; }

        .terms-hint {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            padding: 0.45rem 0.65rem;
            border-radius: 9px;
            background: rgba(37,99,235,0.05);
            border: 1px solid rgba(37,99,235,0.12);
            margin-bottom: 0.75rem;
            line-height: 1.45;
        }
        .terms-hint.is-done {
            color: #047857;
            background: rgba(16,185,129,0.07);
            border-color: rgba(16,185,129,0.2);
        }
        .check-terms {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            font-size: 0.85rem;
            line-height: 1.45;
            cursor: pointer;
            padding: 0.7rem 0.85rem;
            border-radius: 11px;
            border: 1.5px solid var(--card-border);
            background: var(--glass);
            transition: all 0.18s;
            -webkit-tap-highlight-color: transparent;
        }
        .check-terms:has(input:checked) {
            border-color: var(--success);
            background: rgba(16,185,129,0.05);
        }
        .check-terms input { width: 18px; height: 18px; accent-color: var(--primary); flex-shrink: 0; margin-top: 0.1rem; }

        /* ════════════════════════════
           REVIEW GRID
        ════════════════════════════ */
        .review-grid { display: flex; flex-direction: column; gap: 0.5rem; }
        .review-field {
            background: var(--glass);
            border: 1px solid var(--card-border);
            border-radius: 11px;
            padding: 0.65rem 0.85rem;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 0.5rem;
        }
        .rf-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            flex-shrink: 0;
            min-width: 80px;
        }
        .rf-value {
            font-size: 0.87rem;
            font-weight: 600;
            color: var(--text-main);
            text-align: right;
        }
        .review-items-wrap {
            background: var(--glass);
            border: 1px solid var(--card-border);
            border-radius: 11px;
            padding: 0.65rem 0.85rem;
        }
        .review-item-list { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.35rem; }
        .review-item-badge {
            background: rgba(37,99,235,0.09);
            border: 1px solid rgba(37,99,235,0.18);
            color: var(--primary-dark);
            border-radius: 20px;
            padding: 0.22rem 0.6rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .riv-qty { font-weight: 800; opacity: 0.8; margin-left: 0.2rem; }

        /* ════════════════════════════
           STEP NAV
        ════════════════════════════ */
        .step-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .btn-prev, .btn-next, .btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.7rem 1.25rem;
            border-radius: 11px;
            border: none;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all 0.18s;
            -webkit-tap-highlight-color: transparent;
            white-space: nowrap;
        }
        .btn-prev {
            background: var(--card-bg);
            border: 1.5px solid var(--card-border);
            color: var(--text-muted);
        }
        .btn-prev:active { color: var(--primary); border-color: var(--primary); }
        .btn-next {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff;
            box-shadow: 0 3px 12px rgba(37,99,235,0.3);
        }
        .btn-next:active:not(:disabled) { filter: brightness(0.95); }
        .btn-next:disabled { opacity: 0.45; cursor: not-allowed; }
        .btn-submit {
            background: linear-gradient(135deg, #021A54, #1e40af);
            color: #fff;
            box-shadow: 0 3px 12px rgba(2,26,84,0.3);
        }
        .btn-submit:active:not(:disabled) { filter: brightness(0.95); }
        .btn-submit:disabled { opacity: 0.45; cursor: not-allowed; }
        .btn-prev.invisible { visibility: hidden; pointer-events: none; }
        .step-counter { font-size: 0.72rem; color: var(--text-muted); font-weight: 600; }

        .hidden-inputs { display: none; }

        /* ════════════════════════════
           FOOTER
        ════════════════════════════ */
        .lp-footer {
            padding: 0.75rem 1rem;
            padding-bottom: calc(0.75rem + env(safe-area-inset-bottom));
            text-align: center;
            font-size: 0.7rem;
            color: var(--text-muted);
            border-top: 1px solid var(--card-border);
            background: var(--card-bg);
        }
    </style>
</head>
<body>

    <!-- ── Top bar ── -->
    <header class="topbar">
        <a class="topbar-brand" href="landingPage.php" aria-label="NextCheck home">
            <img src="../public/logo-nims.png" alt="">
            <div class="topbar-brand-text">
                <strong>RCMP NIMS</strong>
                <small>NextCheck</small>
            </div>
        </a>
        <div class="topbar-right">
            <div class="topbar-meta">
                <span class="topbar-name"><?= htmlspecialchars($userName) ?></span>
                <a class="topbar-logout" href="../auth/logout.php">Log out</a>
            </div>
            <div class="topbar-avatar" aria-hidden="true"><?= htmlspecialchars($user_initials) ?></div>
        </div>
    </header>

    <main class="lp-main">

        <!-- Banners -->
        <?php if ($submitted_ok): ?>
        <div class="banner banner-success">
            <i class="ri-checkbox-circle-line"></i>
            <div><strong>Request received.</strong> IT staff will review it shortly. Contact IT for status updates.</div>
        </div>
        <?php endif; ?>
        <?php if ($form_error !== ''): ?>
        <div class="banner banner-error">
            <i class="ri-error-warning-line"></i>
            <div><?= htmlspecialchars($form_error) ?></div>
        </div>
        <?php endif; ?>

        <!-- Welcome strip -->
        <div class="welcome-strip">
            <div class="welcome-left">
                <div class="welcome-kicker">NextCheck</div>
                <div class="welcome-name">Hi, <?= htmlspecialchars($welcome_first) ?></div>
                <div class="welcome-count">
                    <?php if ($request_count > 0): ?>
                        <?= (int)$request_count ?> request<?= $request_count !== 1 ? 's' : '' ?> on file
                    <?php else: ?>
                        No requests yet
                    <?php endif; ?>
                </div>
            </div>
            <a class="btn-history" href="viewHistory.php">
                <i class="ri-history-line"></i> History
            </a>
        </div>

        <!-- Form section label -->
        <div class="section-label">
            <i class="ri-file-edit-line"></i> New equipment request
        </div>

        <!-- Stepper -->
        <div class="stepper" id="stepper">
            <div class="stepper-item active" data-step="1">
                <div class="step-dot">1</div>
                <div class="step-lbl">Terms</div>
            </div>
            <div class="stepper-item" data-step="2">
                <div class="step-dot">2</div>
                <div class="step-lbl">Details</div>
            </div>
            <div class="stepper-item" data-step="3">
                <div class="step-dot">3</div>
                <div class="step-lbl">Items</div>
            </div>
            <div class="stepper-item" data-step="4">
                <div class="step-dot"><i class="ri-check-line" style="font-size:0.75rem"></i></div>
                <div class="step-lbl">Review</div>
            </div>
        </div>

        <form method="post" action="" id="requestForm" novalidate>
            <div class="hidden-inputs" id="cartHiddenInputs" aria-hidden="true"></div>

            <!-- ── STEP 1: Terms ── -->
            <div class="step-panel active" id="step-1">
                <div class="card">
                    <div class="card-hd">
                        <i class="ri-file-shield-2-line"></i>
                        Terms &amp; Conditions
                        <span class="card-hd-step">1 / 4</span>
                    </div>
                    <div class="card-bd">
                        <div class="terms-box" id="termsScrollBox" tabindex="0" role="region" aria-label="Terms and conditions">
                            <ul>
                                <li><strong>Eligibility:</strong> All equipment is available for reservation only to registered students and staff of UniKL with a valid ID.</li>
                                <li><strong>Reservation duration:</strong> The duration of the reservation is as specified in your request.</li>
                                <li><strong>Responsibility:</strong> The party making the reservation is fully responsible for the reserved equipment from the moment of collection until they are returned and checked in by a technician.</li>
                                <li><strong>Condition of items:</strong> The reserving party must inspect the item(s) at the time of collection. Any existing damage must be reported immediately, or the reserving party may be held responsible.</li>
                                <li class="terms-danger"><strong>Damage or loss:</strong> The reserving party will be held financially responsible for the full replacement cost of any lost, stolen, or damaged items.</li>
                                <li><strong>Late returns:</strong> Failure to return items by the specified return date will result in a fine and a temporary suspension of reservation privileges.</li>
                                <li><strong>Purpose of use:</strong> Items are to be used for academic or official university purposes only.</li>
                                <li><strong>Collection:</strong> Approved items must be collected within 24 hours of the "Approved" status, or the reservation may be cancelled.</li>
                            </ul>
                        </div>
                        <p class="terms-hint" id="termsScrollHint" aria-live="polite">
                            Scroll through the box above to read all terms before continuing.
                        </p>
                        <label class="check-terms">
                            <input type="checkbox" name="accept_terms" id="acceptTerms" value="1" <?= isset($_POST['accept_terms']) ? 'checked' : '' ?>>
                            <span>I have read and agree to the terms and conditions above.</span>
                        </label>
                    </div>
                </div>
                <div class="step-nav">
                    <button type="button" class="btn-prev invisible"><i class="ri-arrow-left-s-line"></i> Back</button>
                    <span class="step-counter">Step 1 of 4</span>
                    <button type="button" class="btn-next" id="next1" disabled>Next <i class="ri-arrow-right-s-line"></i></button>
                </div>
            </div>

            <!-- ── STEP 2: Request Details ── -->
            <div class="step-panel" id="step-2">
                <div class="card">
                    <div class="card-hd">
                        <i class="ri-calendar-schedule-line"></i>
                        Request Details
                        <span class="card-hd-step">2 / 4</span>
                    </div>
                    <div class="card-bd">
                        <div class="field-row">
                            <div class="field">
                                <label class="field-label" for="borrowDate">Borrow date</label>
                                <input class="input" type="date" name="borrow_date" id="borrowDate" required value="<?= htmlspecialchars($values['borrow_date']) ?>">
                            </div>
                            <div class="field">
                                <label class="field-label" for="returnDate">Return date</label>
                                <input class="input" type="date" name="return_date" id="returnDate" required value="<?= htmlspecialchars($values['return_date']) ?>">
                            </div>
                        </div>

                        <div class="field" style="margin-top:0.85rem">
                            <div class="field-label">Program type</div>
                            <div class="program-grid">
                                <div>
                                    <input type="radio" name="program_type" id="prog_academic" value="academic" class="program-radio" <?= $values['program_type'] === 'academic' ? 'checked' : '' ?>>
                                    <label for="prog_academic" class="program-label">
                                        <i class="ri-book-open-line"></i>
                                        <span class="pl-name">Academic</span>
                                        <span class="pl-desc">Class / coursework</span>
                                    </label>
                                </div>
                                <div>
                                    <input type="radio" name="program_type" id="prog_event" value="official_event" class="program-radio" <?= $values['program_type'] === 'official_event' ? 'checked' : '' ?>>
                                    <label for="prog_event" class="program-label">
                                        <i class="ri-calendar-event-line"></i>
                                        <span class="pl-name">Official Event</span>
                                        <span class="pl-desc">Uni-sanctioned</span>
                                    </label>
                                </div>
                                <div>
                                    <input type="radio" name="program_type" id="prog_club" value="club_society" class="program-radio" <?= $values['program_type'] === 'club_society' ? 'checked' : '' ?>>
                                    <label for="prog_club" class="program-label">
                                        <i class="ri-team-line"></i>
                                        <span class="pl-name">Club / Society</span>
                                        <span class="pl-desc">Student activities</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="field">
                            <label class="field-label" for="usageLocation">Usage location</label>
                            <input class="input" type="text" name="usage_location" id="usageLocation"
                                placeholder="e.g. Block A — Lab 3" required
                                value="<?= htmlspecialchars($values['usage_location']) ?>">
                        </div>
                        <div class="field">
                            <label class="field-label" for="reason">Reason</label>
                            <textarea class="input" name="reason" id="reason"
                                placeholder="Describe how you will use the equipment." required><?= htmlspecialchars($values['reason']) ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="step-nav">
                    <button type="button" class="btn-prev" data-go="1"><i class="ri-arrow-left-s-line"></i> Back</button>
                    <span class="step-counter">Step 2 of 4</span>
                    <button type="button" class="btn-next" id="next2">Next <i class="ri-arrow-right-s-line"></i></button>
                </div>
            </div>

            <!-- ── STEP 3: Select Equipment ── -->
            <div class="step-panel" id="step-3">
                <div class="card">
                    <div class="card-hd">
                        <i class="ri-shopping-bag-3-line"></i>
                        Select Equipment
                        <span class="card-hd-step">3 / 4</span>
                    </div>
                    <div class="card-bd">
                        <div class="equipment-list" id="equipmentGrid">
                            <?php foreach ($equipment_catalog as $eq): ?>
                            <div class="eq-row" data-eq-id="<?= htmlspecialchars($eq['id']) ?>">
                                <div class="eq-row-main">
                                    <div class="eq-icon"><i class="<?= htmlspecialchars($eq['icon']) ?>"></i></div>
                                    <div>
                                        <div class="eq-name"><?= htmlspecialchars($eq['name']) ?></div>
                                        <div class="eq-cat"><?= htmlspecialchars($eq['category']) ?></div>
                                    </div>
                                </div>
                                <div class="eq-row-actions">
                                    <button type="button" class="eq-qty-btn" data-eq-delta="-1" aria-label="Remove one">−</button>
                                    <span class="eq-qty-val" data-eq-qty-display>0</span>
                                    <button type="button" class="eq-qty-btn" data-eq-delta="1" aria-label="Add one">+</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="summary-section">
                            <div class="summary-label">Selected items</div>
                            <div class="selected-summary" id="selectedSummary">
                                <span class="empty-chips">No items selected yet</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="step-nav">
                    <button type="button" class="btn-prev" data-go="2"><i class="ri-arrow-left-s-line"></i> Back</button>
                    <span class="step-counter">Step 3 of 4</span>
                    <button type="button" class="btn-next" id="next3">Next <i class="ri-arrow-right-s-line"></i></button>
                </div>
            </div>

            <!-- ── STEP 4: Review & Submit ── -->
            <div class="step-panel" id="step-4">
                <div class="card">
                    <div class="card-hd">
                        <i class="ri-eye-line"></i>
                        Review &amp; Submit
                        <span class="card-hd-step">4 / 4</span>
                    </div>
                    <div class="card-bd">
                        <div class="review-grid" id="reviewGrid">
                            <div class="review-field">
                                <span class="rf-label">Borrow</span>
                                <span class="rf-value" id="rev-borrow">—</span>
                            </div>
                            <div class="review-field">
                                <span class="rf-label">Return</span>
                                <span class="rf-value" id="rev-return">—</span>
                            </div>
                            <div class="review-field">
                                <span class="rf-label">Program</span>
                                <span class="rf-value" id="rev-program">—</span>
                            </div>
                            <div class="review-field">
                                <span class="rf-label">Location</span>
                                <span class="rf-value" id="rev-location">—</span>
                            </div>
                            <div class="review-field" style="flex-direction:column;align-items:flex-start;gap:0.2rem">
                                <span class="rf-label">Reason</span>
                                <span class="rf-value" id="rev-reason" style="text-align:left;font-weight:400;font-size:0.84rem;line-height:1.5">—</span>
                            </div>
                            <div class="review-items-wrap">
                                <div class="rf-label">Equipment</div>
                                <div class="review-item-list" id="rev-items"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="step-nav">
                    <button type="button" class="btn-prev" data-go="3"><i class="ri-arrow-left-s-line"></i> Back</button>
                    <span class="step-counter">Step 4 of 4</span>
                    <button type="submit" class="btn-submit">
                        <i class="ri-send-plane-fill"></i> Submit
                    </button>
                </div>
            </div>

        </form>
    </main>

    <footer class="lp-footer">
        RCMP NIMS &mdash; NextCheck &copy; <?= date('Y') ?>
    </footer>

    <script>
    (function () {
        var MAX_QTY = 99;
        var catalog = <?= json_encode($equipment_catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var byId = {};
        catalog.forEach(function (e) { byId[e.id] = e; });

        var cart = new Map();
        var currentStep = 1;

        var hiddenWrap     = document.getElementById('cartHiddenInputs');
        var summaryWrap    = document.getElementById('selectedSummary');
        var acceptTerms    = document.getElementById('acceptTerms');
        var next1Btn       = document.getElementById('next1');
        var termsScrollBox = document.getElementById('termsScrollBox');
        var termsHint      = document.getElementById('termsScrollHint');

        var programLabels = {
            academic: 'Academic project / class',
            official_event: 'Official event',
            club_society: 'Club / society activities'
        };

        /* ── Stepper ── */
        function goToStep(n) {
            document.querySelectorAll('.step-panel').forEach(function (p, i) {
                p.classList.toggle('active', i + 1 === n);
            });
            document.querySelectorAll('.stepper-item').forEach(function (item, i) {
                var s = i + 1;
                item.classList.remove('active', 'done');
                if (s < n) item.classList.add('done');
                if (s === n) item.classList.add('active');
                var dot = item.querySelector('.step-dot');
                if (s < n) {
                    dot.innerHTML = '<i class="ri-check-line" style="font-size:0.75rem"></i>';
                } else if (s === 4) {
                    dot.innerHTML = '<i class="ri-check-line" style="font-size:0.75rem"></i>';
                } else {
                    dot.textContent = s;
                }
            });
            currentStep = n;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        /* ── Terms scroll detection ── */
        var termsRead = false;
        function measureTermsScrollRead() {
            if (!termsScrollBox) return;
            var threshold = termsScrollBox.scrollHeight - termsScrollBox.clientHeight - 10;
            if (!termsRead && termsScrollBox.scrollTop >= threshold) {
                termsRead = true;
                if (termsHint) {
                    termsHint.textContent = 'All terms read. You may now accept and continue.';
                    termsHint.classList.add('is-done');
                }
            }
            updateTermsNextState();
        }
        function updateTermsNextState() {
            if (next1Btn) next1Btn.disabled = !(termsRead && acceptTerms && acceptTerms.checked);
        }

        if (termsScrollBox) {
            termsScrollBox.addEventListener('scroll', measureTermsScrollRead, { passive: true });
            // also check if box is too short to scroll (all terms visible)
            setTimeout(measureTermsScrollRead, 100);
        }
        if (acceptTerms) acceptTerms.addEventListener('change', updateTermsNextState);

        /* ── Step 1 → 2 ── */
        if (next1Btn) next1Btn.addEventListener('click', function () {
            if (!acceptTerms.checked) { alert('Please accept the terms to continue.'); return; }
            goToStep(2);
        });

        /* ── Step 2 → 3 ── */
        document.getElementById('next2').addEventListener('click', function () {
            var bd   = document.getElementById('borrowDate').value;
            var rd   = document.getElementById('returnDate').value;
            var prog = document.querySelector('input[name="program_type"]:checked');
            var loc  = document.getElementById('usageLocation').value.trim();
            var rsn  = document.getElementById('reason').value.trim();
            if (!bd || !rd)     { alert('Please choose both borrow and return dates.'); return; }
            if (bd > rd)        { alert('Return date must be on or after the borrow date.'); return; }
            if (!prog)          { alert('Please select a program type.'); return; }
            if (!loc)           { alert('Please enter the usage location.'); return; }
            if (rsn.length < 5) { alert('Please enter a reason (at least a few words).'); return; }
            goToStep(3);
        });

        /* ── Step 3 → 4 ── */
        document.getElementById('next3').addEventListener('click', function () {
            if (cart.size === 0) { alert('Please add at least one item.'); return; }
            populateReview();
            goToStep(4);
        });

        /* ── Back buttons ── */
        document.querySelectorAll('.btn-prev[data-go]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                goToStep(parseInt(btn.getAttribute('data-go')));
            });
        });

        /* ── Cart helpers ── */
        function getQty(id) { return cart.get(id) || 0; }
        function setQty(id, q) {
            q = parseInt(q, 10);
            if (isNaN(q) || q < 1) { cart.delete(id); }
            else { cart.set(id, Math.min(MAX_QTY, q)); }
            updateEquipmentRows();
            renderSummaryChips();
            syncHidden();
        }
        function addDelta(id, d) { setQty(id, getQty(id) + d); }

        function syncHidden() {
            hiddenWrap.innerHTML = '';
            cart.forEach(function (q, id) {
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'qty[' + id + ']'; inp.value = String(q);
                hiddenWrap.appendChild(inp);
            });
        }

        function renderSummaryChips() {
            summaryWrap.innerHTML = '';
            if (cart.size === 0) {
                summaryWrap.innerHTML = '<span class="empty-chips">No items selected yet</span>';
                return;
            }
            cart.forEach(function (qty, id) {
                var e = byId[id]; if (!e) return;
                var chip = document.createElement('span');
                chip.className = 'sel-chip';
                var txt = document.createElement('span');
                txt.textContent = e.name + ' × ' + qty;
                chip.appendChild(txt);
                var btns = document.createElement('span');
                btns.className = 'chip-qty-btns';
                btns.innerHTML =
                    '<button type="button" data-d="-1" aria-label="Less"><i class="ri-subtract-line"></i></button>' +
                    '<button type="button" data-d="1"  aria-label="More"><i class="ri-add-line"></i></button>' +
                    '<button type="button" data-rm aria-label="Remove"><i class="ri-close-line"></i></button>';
                btns.querySelector('[data-d="-1"]').addEventListener('click', function (ev) { ev.stopPropagation(); addDelta(id, -1); });
                btns.querySelector('[data-d="1"]').addEventListener('click',  function (ev) { ev.stopPropagation(); addDelta(id,  1); });
                btns.querySelector('[data-rm]').addEventListener('click',     function (ev) { ev.stopPropagation(); setQty(id, 0); });
                chip.appendChild(btns);
                summaryWrap.appendChild(chip);
            });
        }

        function updateEquipmentRows() {
            document.querySelectorAll('.eq-row').forEach(function (row) {
                var id = row.getAttribute('data-eq-id');
                var q = getQty(id);
                row.classList.toggle('in-cart', q > 0);
                var disp = row.querySelector('[data-eq-qty-display]');
                if (disp) disp.textContent = String(q);
                var minus = row.querySelector('[data-eq-delta="-1"]');
                var plus  = row.querySelector('[data-eq-delta="1"]');
                if (minus) minus.disabled = q < 1;
                if (plus)  plus.disabled  = q >= MAX_QTY;
            });
        }

        /* Qty buttons */
        document.querySelectorAll('.eq-qty-btn').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault(); ev.stopPropagation();
                var row = btn.closest('.eq-row'); if (!row) return;
                var id = row.getAttribute('data-eq-id');
                var d  = parseInt(btn.getAttribute('data-eq-delta'), 10);
                if (!id || (d !== 1 && d !== -1)) return;
                addDelta(id, d);
            });
        });

        /* Tap row main area = +1 */
        var equipGrid = document.getElementById('equipmentGrid');
        if (equipGrid) {
            equipGrid.addEventListener('click', function (ev) {
                if (ev.target.closest('.eq-qty-btn')) return;
                var main = ev.target.closest('.eq-row-main');
                if (!main) return;
                var row = main.closest('.eq-row');
                var id  = row && row.getAttribute('data-eq-id');
                if (id && getQty(id) < MAX_QTY) addDelta(id, 1);
            });
        }

        /* ── Review ── */
        function populateReview() {
            var bd   = document.getElementById('borrowDate').value;
            var rd   = document.getElementById('returnDate').value;
            var prog = document.querySelector('input[name="program_type"]:checked');
            var loc  = document.getElementById('usageLocation').value.trim();
            var rsn  = document.getElementById('reason').value.trim();
            document.getElementById('rev-borrow').textContent  = bd  || '—';
            document.getElementById('rev-return').textContent  = rd  || '—';
            document.getElementById('rev-program').textContent = prog ? (programLabels[prog.value] || prog.value) : '—';
            document.getElementById('rev-location').textContent = loc || '—';
            document.getElementById('rev-reason').textContent  = rsn || '—';
            var revItems = document.getElementById('rev-items');
            revItems.innerHTML = '';
            if (cart.size === 0) {
                revItems.innerHTML = '<span style="font-size:0.78rem;color:var(--text-muted)">No items</span>';
            } else {
                cart.forEach(function (qty, id) {
                    var e = byId[id]; if (!e) return;
                    var badge = document.createElement('span');
                    badge.className = 'review-item-badge';
                    badge.appendChild(document.createTextNode(e.name));
                    var qs = document.createElement('span');
                    qs.className = 'riv-qty'; qs.textContent = '×' + qty;
                    badge.appendChild(qs);
                    revItems.appendChild(badge);
                });
            }
        }

        /* ── Restore from PHP error ── */
        var initialQty = <?= json_encode($initial_qty, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        Object.keys(initialQty).forEach(function (id) {
            var q = parseInt(initialQty[id], 10);
            if (q > 0 && byId[id]) cart.set(id, Math.min(MAX_QTY, q));
        });
        if (cart.size > 0) { renderSummaryChips(); syncHidden(); }
        updateEquipmentRows();
        measureTermsScrollRead();

        <?php if ($form_error !== ''): ?>
        (function() {
            var err = <?= json_encode($form_error) ?>;
            if (err.includes('item'))  { goToStep(3); }
            else if (err.includes('terms')) { goToStep(1); }
            else { goToStep(2); }
        })();
        <?php endif; ?>

    })();
    </script>
</body>
</html>