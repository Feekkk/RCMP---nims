<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] !== 3)) {
    header('Location: ../auth/login.php');
    exit;
}

$userName = trim((string)($_SESSION['user_name'] ?? 'User'));
$staffId = (string)($_SESSION['staff_id'] ?? '');

require_once __DIR__ . '/../config/database.php';

$equipment_catalog = [
    ['id' => 'portable_speaker', 'name' => 'Portable Speaker', 'category' => 'Audio equipment', 'icon' => 'ri-volume-up-line'],
    ['id' => 'microphone', 'name' => 'Microphone', 'category' => 'Audio Visual accessories', 'icon' => 'ri-mic-line'],
    ['id' => 'pocket_mic', 'name' => 'Pocket Mic', 'category' => 'Audio Visual accessories', 'icon' => 'ri-mic-2-line'],
    ['id' => 'tripod', 'name' => 'Tripod', 'category' => 'Audio Visual accessories', 'icon' => 'ri-camera-line'],
    ['id' => 'laptop', 'name' => 'Laptop', 'category' => 'Computer', 'icon' => 'ri-laptop-line'],
    ['id' => 'projector', 'name' => 'Projector', 'category' => 'Visual equipment', 'icon' => 'ri-slideshow-line'],
    ['id' => 'video_camera', 'name' => 'Video Camera', 'category' => 'Visual equipment', 'icon' => 'ri-vidicon-line'],
    ['id' => 'webcam', 'name' => 'Webcam', 'category' => 'Visual equipment', 'icon' => 'ri-webcam-line'],
];
$equipment_ids = array_column($equipment_catalog, 'id');
$equipment_category_label = [];
foreach ($equipment_catalog as $eq) {
    $equipment_category_label[$eq['id']] = $eq['name'] . ' — ' . $eq['category'];
}

$form_error = '';
$values = [
    'borrow_date' => '',
    'return_date' => '',
    'program_type' => '',
    'usage_location' => '',
    'reason' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['borrow_date'] = trim((string)($_POST['borrow_date'] ?? ''));
    $values['return_date'] = trim((string)($_POST['return_date'] ?? ''));
    $values['program_type'] = trim((string)($_POST['program_type'] ?? ''));
    $values['usage_location'] = trim((string)($_POST['usage_location'] ?? ''));
    $values['reason'] = trim((string)($_POST['reason'] ?? ''));
    $qtyPost = $_POST['qty'] ?? [];
    if (!is_array($qtyPost)) {
        $qtyPost = [];
    }
    $lineItems = [];
    foreach ($equipment_ids as $eqId) {
        $q = isset($qtyPost[$eqId]) ? (int)$qtyPost[$eqId] : 0;
        if ($q < 1) {
            continue;
        }
        if ($q > 99) {
            $q = 99;
        }
        $lineItems[] = ['id' => $eqId, 'qty' => $q];
    }
    $accept = isset($_POST['accept_terms']) && (string)$_POST['accept_terms'] === '1';
    $valid_programs = ['academic', 'official_event', 'club_society'];

    if ($values['borrow_date'] === '' || $values['return_date'] === '') {
        $form_error = 'Please choose both borrow and return dates.';
    } elseif ($values['borrow_date'] > $values['return_date']) {
        $form_error = 'Return date must be on or after the borrow date.';
    } elseif (!in_array($values['program_type'], $valid_programs, true)) {
        $form_error = 'Please select a program type.';
    } elseif ($values['usage_location'] === '') {
        $form_error = 'Please enter the usage location.';
    } elseif (mb_strlen($values['reason']) < 5) {
        $form_error = 'Please enter a reason (at least a few words).';
    } elseif ($lineItems === []) {
        $form_error = 'Add at least one item to your request.';
    } elseif (!$accept) {
        $form_error = 'You must read and accept the terms and conditions.';
    }

    if ($form_error === '') {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('
                INSERT INTO nexcheck_request (
                    requested_by, borrow_date, return_date, program_type,
                    usage_location, reason, terms_accepted_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $staffId,
                $values['borrow_date'],
                $values['return_date'],
                $values['program_type'],
                $values['usage_location'],
                $values['reason'] !== '' ? $values['reason'] : null,
            ]);
            $nexcheckId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare('
                INSERT INTO nexcheck_request_item (nexcheck_id, category, quantity)
                VALUES (?, ?, ?)
            ');
            foreach ($lineItems as $row) {
                $label = $equipment_category_label[$row['id']] ?? $row['id'];
                for ($u = 0; $u < $row['qty']; $u++) {
                    $itemStmt->execute([$nexcheckId, $label, 1]);
                }
            }
            $pdo->commit();
            header('Location: form.php?submitted=1');
            exit;
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $form_error = 'Could not save your request. Please try again or contact support.';
        }
    }
}

$submitted_ok = isset($_GET['submitted']) && (string)$_GET['submitted'] === '1';

$initial_qty = [];
if ($form_error !== '' && isset($_POST['qty']) && is_array($_POST['qty'])) {
    foreach ($equipment_ids as $eqId) {
        $q = isset($_POST['qty'][$eqId]) ? (int)$_POST['qty'][$eqId] : 0;
        if ($q > 0) {
            $initial_qty[$eqId] = min(99, max(1, $q));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment request — NextCheck — RCMP NIMS</title>
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
            --secondary: #0ea5e9;
            --danger: #dc2626;
            --success: #10b981;
            --bg: #f1f5f9;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass: #f8fafc;
            --step-inactive: #cbd5e1;
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

        /* ── Sidebar ── */
        .nav-drawer-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 99;
        }
        body.nav-drawer-open .nav-drawer-backdrop { display: block; }
        @media (max-width: 900px) {
            body.nav-drawer-open .sidebar-next { transform: translateX(0); }
        }

        /* ── Main layout ── */
        .main-user {
            margin-left: 280px;
            flex: 1;
            max-width: calc(100vw - 280px);
            min-height: 100vh;
            padding: 2rem 2.5rem 3rem;
        }
        @media (max-width: 900px) {
            .main-user { margin-left: 0; max-width: 100vw; padding: 1.25rem 1rem 2.5rem; }
        }

        /* ── Top row ── */
        .top-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .menu-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            width: 44px; height: 44px;
            border-radius: 14px;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            cursor: pointer;
        }
        .menu-toggle i { font-size: 1.35rem; }
        @media (max-width: 900px) { .menu-toggle { display: inline-flex; } }
        .page-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.65rem;
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        .page-title span {
            display: block;
            font-size: 0.88rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        .link-back {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.88rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .link-back:hover { color: var(--primary); }

        /* ── Banner ── */
        .banner {
            border-radius: 14px;
            padding: 0.9rem 1.1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            font-size: 0.9rem;
            line-height: 1.45;
        }
        .banner-success {
            background: rgba(16,185,129,0.1);
            border: 1px solid rgba(16,185,129,0.28);
            color: #047857;
        }
        .banner-error {
            background: rgba(239,68,68,0.08);
            border: 1px solid rgba(239,68,68,0.22);
            color: #b91c1c;
        }

        /* ══════════════════════════════════════
           STEPPER PROGRESS BAR
        ══════════════════════════════════════ */
        .wizard-wrap {
            max-width: 780px;
            margin: 0 auto;
        }
        .stepper {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            position: relative;
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
            top: 20px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: var(--step-inactive);
            z-index: 0;
            transition: background 0.4s;
        }
        .stepper-item.done:not(:last-child)::after,
        .stepper-item.active:not(:last-child)::after {
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
        }
        .step-circle {
            width: 40px; height: 40px;
            border-radius: 50%;
            border: 2px solid var(--step-inactive);
            background: var(--card-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.9rem;
            color: var(--text-muted);
            transition: all 0.3s cubic-bezier(.4,0,.2,1);
            position: relative;
            z-index: 2;
        }
        .stepper-item.active .step-circle {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-color: var(--primary);
            color: #fff;
            box-shadow: 0 4px 16px rgba(37,99,235,0.35);
            transform: scale(1.1);
        }
        .stepper-item.done .step-circle {
            background: linear-gradient(135deg, #021A54, #1e40af);
            border-color: var(--primary-dark);
            color: #fff;
        }
        .step-label {
            margin-top: 0.5rem;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            text-align: center;
            white-space: nowrap;
        }
        .stepper-item.active .step-label { color: var(--primary); }
        .stepper-item.done .step-label { color: var(--primary-dark); }

        @media (max-width: 500px) {
            .step-label { display: none; }
            .step-circle { width: 32px; height: 32px; font-size: 0.8rem; }
        }

        /* ══════════════════════════════════════
           STEP PANELS
        ══════════════════════════════════════ */
        .step-panel {
            display: none;
            animation: fadeSlideIn 0.35s cubic-bezier(.4,0,.2,1);
        }
        .step-panel.active { display: block; }

        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Card ── */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
        }
        .card-hd {
            padding: 1.15rem 1.4rem;
            border-bottom: 1px solid var(--card-border);
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        }
        .card-hd i { color: var(--primary); font-size: 1.15rem; }
        .card-hd .card-subtitle {
            font-size: 0.78rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-left: auto;
        }
        .card-bd { padding: 1.5rem; }

        /* ── Fields ── */
        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.1rem 1.25rem;
        }
        @media (max-width: 600px) { .field-grid { grid-template-columns: 1fr; } }

        label.field { display: block; }
        label.field > span {
            display: block;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
        }
        .input, select.input, textarea.input {
            width: 100%;
            padding: 0.7rem 0.9rem;
            border: 1.5px solid var(--card-border);
            border-radius: 12px;
            font-family: inherit;
            font-size: 0.92rem;
            background: var(--glass);
            color: var(--text-main);
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            appearance: none;
        }
        .input:focus, select.input:focus, textarea.input:focus {
            outline: none;
            border-color: rgba(37,99,235,0.5);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        textarea.input { min-height: 110px; resize: vertical; }

        /* ── Program type radio cards ── */
        .program-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.85rem;
            margin-top: 0.25rem;
        }
        @media (max-width: 560px) { .program-grid { grid-template-columns: 1fr; } }

        .program-radio { display: none; }
        .program-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.6rem;
            padding: 1.1rem 0.75rem;
            border: 2px solid var(--card-border);
            border-radius: 16px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
            background: var(--glass);
            user-select: none;
        }
        .program-label i {
            font-size: 1.6rem;
            color: var(--text-muted);
            transition: color 0.2s;
        }
        .program-label .pl-name {
            font-weight: 700;
            font-size: 0.85rem;
        }
        .program-label .pl-desc {
            font-size: 0.72rem;
            color: var(--text-muted);
            line-height: 1.35;
        }
        .program-radio:checked + .program-label {
            border-color: var(--primary);
            background: rgba(37,99,235,0.06);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }
        .program-radio:checked + .program-label i { color: var(--primary); }

        /* ── Equipment grid ── */
        .equipment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
            gap: 0.75rem;
        }
        .eq-card {
            border: 2px solid var(--card-border);
            border-radius: 16px;
            padding: 1rem;
            padding-bottom: 0.85rem;
            background: var(--glass);
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 0.65rem;
            transition: all 0.2s cubic-bezier(.4,0,.2,1);
            position: relative;
            overflow: hidden;
        }
        .eq-card-main {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.55rem;
            position: relative;
            z-index: 1;
        }
        .eq-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(37,99,235,0.08), transparent);
            opacity: 0;
            transition: opacity 0.2s;
            pointer-events: none;
        }
        .eq-card:hover { border-color: rgba(37,99,235,0.3); transform: translateY(-1px); }
        .eq-card:hover::before { opacity: 1; }
        .eq-card.in-cart {
            border-color: var(--primary);
            background: rgba(37,99,235,0.06);
        }
        .eq-card.in-cart::before { opacity: 1; }
        .eq-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: rgba(37,99,235,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .eq-icon i { font-size: 1.1rem; color: var(--primary); }
        .eq-name { font-weight: 700; font-size: 0.88rem; }
        .eq-cat { font-size: 0.72rem; color: var(--text-muted); }
        .eq-check {
            position: absolute;
            top: 10px; right: 10px;
            width: 22px; height: 22px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transform: scale(0.6);
            transition: all 0.2s cubic-bezier(.4,0,.2,1);
            pointer-events: none;
            z-index: 2;
        }
        .eq-check i { font-size: 0.8rem; color: #fff; }
        .eq-card.in-cart .eq-check { opacity: 1; transform: scale(1); }
        .eq-qty-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            margin-top: auto;
            padding-top: 0.5rem;
            border-top: 1px solid var(--card-border);
            position: relative;
            z-index: 1;
        }
        .eq-card.in-cart .eq-qty-row { border-top-color: rgba(37,99,235,0.2); }
        .eq-qty-btn {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-main);
            font-size: 1.15rem;
            font-weight: 600;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s, border-color 0.15s;
        }
        .eq-qty-btn:hover {
            border-color: var(--primary);
            background: rgba(37,99,235,0.08);
        }
        .eq-qty-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .eq-qty-val {
            min-width: 2rem;
            text-align: center;
            font-weight: 800;
            font-size: 0.95rem;
            font-variant-numeric: tabular-nums;
        }

        /* ── Selected items summary ── */
        .selected-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            min-height: 36px;
            margin-top: 0.75rem;
        }
        .sel-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.5rem 0.35rem 0.65rem;
            background: rgba(37,99,235,0.1);
            border: 1px solid rgba(37,99,235,0.25);
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--primary-dark);
        }
        .sel-chip .chip-qty-btns {
            display: inline-flex;
            align-items: center;
            gap: 0.15rem;
            margin-left: 0.15rem;
        }
        .sel-chip .chip-qty-btns button {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            border: none;
            background: rgba(37,99,235,0.15);
            color: var(--primary-dark);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }
        .sel-chip .chip-qty-btns button:hover { background: rgba(37,99,235,0.28); }
        .sel-chip button {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            line-height: 1;
        }
        .sel-chip button i { font-size: 0.8rem; }
        .empty-chips {
            font-size: 0.82rem;
            color: var(--text-muted);
            padding: 0.2rem 0;
            font-style: italic;
        }

        /* ── Terms ── */
        .terms-box {
            max-height: 220px;
            overflow-y: auto;
            padding: 1rem 1.1rem;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: var(--glass);
            font-size: 0.84rem;
            line-height: 1.55;
            margin-bottom: 1.25rem;
        }
        .terms-box ul { margin: 0; padding-left: 1.15rem; }
        .terms-box li { margin-bottom: 0.55rem; }
        .terms-danger { color: var(--danger); font-weight: 600; }
        .check-terms {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            font-size: 0.88rem;
            line-height: 1.45;
            cursor: pointer;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            border: 2px solid var(--card-border);
            background: var(--glass);
            transition: all 0.2s;
        }
        .check-terms:has(input:checked) {
            border-color: var(--success);
            background: rgba(16,185,129,0.05);
        }
        .check-terms input { margin-top: 0.15rem; width: 18px; height: 18px; accent-color: var(--primary); flex-shrink: 0; }

        /* ── Review card ── */
        .review-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem;
        }
        @media (max-width: 560px) { .review-grid { grid-template-columns: 1fr; } }
        .review-field {
            background: var(--glass);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
        }
        .review-field .rf-label {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        .review-field .rf-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
        }
        .review-items {
            grid-column: 1 / -1;
            background: var(--glass);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
        }
        .review-item-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            margin-top: 0.35rem;
        }
        .review-item-badge .riv-qty {
            font-weight: 800;
            opacity: 0.85;
            margin-left: 0.25rem;
        }
        .review-item-badge {
            background: rgba(37,99,235,0.1);
            border: 1px solid rgba(37,99,235,0.2);
            color: var(--primary-dark);
            border-radius: 20px;
            padding: 0.25rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 600;
        }

        /* ── Navigation buttons ── */
        .step-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.75rem;
            gap: 1rem;
        }
        .btn-prev, .btn-next, .btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            border: none;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.92rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-prev {
            background: var(--card-bg);
            border: 1.5px solid var(--card-border);
            color: var(--text-muted);
        }
        .btn-prev:hover { border-color: var(--primary); color: var(--primary); }
        .btn-next {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff;
            box-shadow: 0 4px 14px rgba(37,99,235,0.3);
        }
        .btn-next:hover { filter: brightness(1.05); box-shadow: 0 6px 18px rgba(37,99,235,0.38); transform: translateY(-1px); }
        .btn-submit {
            background: linear-gradient(135deg, #021A54, #1e40af);
            color: #fff;
            box-shadow: 0 4px 14px rgba(2,26,84,0.3);
        }
        .btn-submit:hover:not(:disabled) { filter: brightness(1.08); transform: translateY(-1px); }
        .btn-submit:disabled { opacity: 0.45; cursor: not-allowed; transform: none !important; }
        .btn-prev.invisible { visibility: hidden; pointer-events: none; }

        .step-counter {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
        }
        .hidden-inputs { display: none; }
    </style>
</head>
<body>
    <div class="nav-drawer-backdrop" id="navDrawerBackdrop"></div>
    <?php include __DIR__ . '/../components/sidebarNext.php'; ?>

    <main class="main-user">
        <!-- Top row -->
        <div class="top-row">
            <div style="display:flex;align-items:center;gap:0.85rem">
                <button type="button" class="menu-toggle" id="navMenuToggle" aria-label="Open menu"><i class="ri-menu-3-line"></i></button>
                <div>
                    <h1 class="page-title">Equipment request<span>Complete all steps to submit your reservation</span></h1>
                </div>
            </div>
            <a class="link-back" href="dashboard.php"><i class="ri-arrow-left-line"></i> Dashboard</a>
        </div>

        <?php if ($submitted_ok): ?>
        <div class="banner banner-success" style="max-width:780px;margin:0 auto 1.5rem">
            <i class="ri-checkbox-circle-line" style="font-size:1.25rem;flex-shrink:0"></i>
            <div><strong>Request received.</strong> Your submission has been recorded. IT staff will review it shortly. Contact the IT department for status updates.</div>
        </div>
        <?php endif; ?>

        <?php if ($form_error !== ''): ?>
        <div class="banner banner-error" style="max-width:780px;margin:0 auto 1.5rem">
            <i class="ri-error-warning-line" style="font-size:1.25rem;flex-shrink:0"></i>
            <div><?= htmlspecialchars($form_error) ?></div>
        </div>
        <?php endif; ?>

        <div class="wizard-wrap">

            <!-- Stepper -->
            <div class="stepper" id="stepper">
                <div class="stepper-item active" data-step="1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Details</div>
                </div>
                <div class="stepper-item" data-step="2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Equipment</div>
                </div>
                <div class="stepper-item" data-step="3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Terms</div>
                </div>
                <div class="stepper-item" data-step="4">
                    <div class="step-circle"><i class="ri-check-line" style="font-size:1rem"></i></div>
                    <div class="step-label">Review</div>
                </div>
            </div>

            <form method="post" action="" id="requestForm" novalidate>
                <div class="hidden-inputs" id="cartHiddenInputs" aria-hidden="true"></div>

                <!-- ── STEP 1: Request Details ── -->
                <div class="step-panel active" id="step-1">
                    <div class="card">
                        <div class="card-hd">
                            <i class="ri-calendar-schedule-line"></i>
                            Request Details
                            <span class="card-subtitle">Step 1 of 4</span>
                        </div>
                        <div class="card-bd">
                            <div class="field-grid">
                                <label class="field">
                                    <span>Borrow date</span>
                                    <input class="input" type="date" name="borrow_date" id="borrowDate" required value="<?= htmlspecialchars($values['borrow_date']) ?>">
                                </label>
                                <label class="field">
                                    <span>Return date</span>
                                    <input class="input" type="date" name="return_date" id="returnDate" required value="<?= htmlspecialchars($values['return_date']) ?>">
                                </label>
                                <div style="grid-column:1/-1">
                                    <span style="display:block;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:0.75rem">Program type</span>
                                    <div class="program-grid">
                                        <div>
                                            <input type="radio" name="program_type" id="prog_academic" value="academic" class="program-radio" <?= $values['program_type'] === 'academic' ? 'checked' : '' ?>>
                                            <label for="prog_academic" class="program-label">
                                                <i class="ri-book-open-line"></i>
                                                <span class="pl-name">Academic</span>
                                                <span class="pl-desc">Class project or coursework</span>
                                            </label>
                                        </div>
                                        <div>
                                            <input type="radio" name="program_type" id="prog_event" value="official_event" class="program-radio" <?= $values['program_type'] === 'official_event' ? 'checked' : '' ?>>
                                            <label for="prog_event" class="program-label">
                                                <i class="ri-calendar-event-line"></i>
                                                <span class="pl-name">Official Event</span>
                                                <span class="pl-desc">University-sanctioned event</span>
                                            </label>
                                        </div>
                                        <div>
                                            <input type="radio" name="program_type" id="prog_club" value="club_society" class="program-radio" <?= $values['program_type'] === 'club_society' ? 'checked' : '' ?>>
                                            <label for="prog_club" class="program-label">
                                                <i class="ri-team-line"></i>
                                                <span class="pl-name">Club / Society</span>
                                                <span class="pl-desc">Student club activities</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <label class="field" style="grid-column:1/-1">
                                    <span>Usage location</span>
                                    <input class="input" type="text" name="usage_location" id="usageLocation" placeholder="e.g. Block A — Lab 3" required value="<?= htmlspecialchars($values['usage_location']) ?>">
                                </label>
                                <label class="field" style="grid-column:1/-1">
                                    <span>Reason for request</span>
                                    <textarea class="input" name="reason" id="reason" placeholder="Describe how you will use the equipment." required><?= htmlspecialchars($values['reason']) ?></textarea>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="step-nav">
                        <button type="button" class="btn-prev invisible"><i class="ri-arrow-left-line"></i> Back</button>
                        <span class="step-counter">Step 1 of 4</span>
                        <button type="button" class="btn-next" id="next1">Next <i class="ri-arrow-right-line"></i></button>
                    </div>
                </div>

                <!-- ── STEP 2: Select Equipment ── -->
                <div class="step-panel" id="step-2">
                    <div class="card">
                        <div class="card-hd">
                            <i class="ri-shopping-bag-3-line"></i>
                            Select Equipment
                            <span class="card-subtitle">Step 2 of 4</span>
                        </div>
                        <div class="card-bd">
                            <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:1.1rem;line-height:1.5">
                                Use <strong>+</strong> and <strong>−</strong> to set how many you need for each type. You can request more than one of the same category.
                            </p>
                            <div class="equipment-grid" id="equipmentGrid">
                                <?php foreach ($equipment_catalog as $eq): ?>
                                <div class="eq-card" data-eq-id="<?= htmlspecialchars($eq['id']) ?>">
                                    <div class="eq-check" aria-hidden="true"><i class="ri-check-line"></i></div>
                                    <div class="eq-card-main">
                                        <div class="eq-icon"><i class="<?= htmlspecialchars($eq['icon']) ?>"></i></div>
                                        <div class="eq-name"><?= htmlspecialchars($eq['name']) ?></div>
                                        <div class="eq-cat"><?= htmlspecialchars($eq['category']) ?></div>
                                    </div>
                                    <div class="eq-qty-row">
                                        <button type="button" class="eq-qty-btn" data-eq-delta="-1" aria-label="Decrease quantity">−</button>
                                        <span class="eq-qty-val" data-eq-qty-display>0</span>
                                        <button type="button" class="eq-qty-btn" data-eq-delta="1" aria-label="Increase quantity">+</button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--card-border)">
                                <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:0.5rem">Selected items</div>
                                <div class="selected-summary" id="selectedSummary">
                                    <span class="empty-chips">No items selected yet</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="step-nav">
                        <button type="button" class="btn-prev" data-go="1"><i class="ri-arrow-left-line"></i> Back</button>
                        <span class="step-counter">Step 2 of 4</span>
                        <button type="button" class="btn-next" id="next2">Next <i class="ri-arrow-right-line"></i></button>
                    </div>
                </div>

                <!-- ── STEP 3: Terms & Conditions ── -->
                <div class="step-panel" id="step-3">
                    <div class="card">
                        <div class="card-hd">
                            <i class="ri-file-shield-2-line"></i>
                            Terms and Conditions
                            <span class="card-subtitle">Step 3 of 4</span>
                        </div>
                        <div class="card-bd">
                            <div class="terms-box">
                                <ul>
                                    <li><strong>Eligibility:</strong> All equipment is available for reservation only to registered students and staff of UniKL with a valid ID.</li>
                                    <li><strong>Reservation duration:</strong> The duration of the reservation is as specified in your request.</li>
                                    <li><strong>Responsibility:</strong> The party making the reservation is fully responsible for the reserved equipment from the moment of collection until they are returned and checked in by a technician.</li>
                                    <li><strong>Condition of items:</strong> The reserving party must inspect the item(s) at the time of collection. Any existing damage must be reported immediately, or the reserving party may be held responsible.</li>
                                    <li class="terms-danger"><strong>Damage or loss:</strong> The reserving party will be held financially responsible for the full replacement cost of any lost, stolen, or damaged items (including all parts and accessories).</li>
                                    <li><strong>Late returns:</strong> Failure to return items by the specified return date will result in a fine and a temporary suspension of reservation privileges.</li>
                                    <li><strong>Purpose of use:</strong> Items are to be used for academic or official university purposes only, as specified in the reservation form.</li>
                                    <li><strong>Collection:</strong> Approved items must be collected within 24 hours of the &quot;Approved&quot; status being issued, or the reservation may be cancelled.</li>
                                </ul>
                            </div>
                            <label class="check-terms">
                                <input type="checkbox" name="accept_terms" id="acceptTerms" value="1" <?= isset($_POST['accept_terms']) ? 'checked' : '' ?>>
                                <span>I have read and agree to the terms and conditions above. This is required to proceed.</span>
                            </label>
                        </div>
                    </div>
                    <div class="step-nav">
                        <button type="button" class="btn-prev" data-go="2"><i class="ri-arrow-left-line"></i> Back</button>
                        <span class="step-counter">Step 3 of 4</span>
                        <button type="button" class="btn-next" id="next3" disabled>Review <i class="ri-arrow-right-line"></i></button>
                    </div>
                </div>

                <!-- ── STEP 4: Review & Submit ── -->
                <div class="step-panel" id="step-4">
                    <div class="card">
                        <div class="card-hd">
                            <i class="ri-eye-line"></i>
                            Review Your Request
                            <span class="card-subtitle">Step 4 of 4</span>
                        </div>
                        <div class="card-bd">
                            <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:1.25rem;line-height:1.5">
                                Please review your request before submitting. Go back to edit any details.
                            </p>
                            <div class="review-grid" id="reviewGrid">
                                <div class="review-field">
                                    <div class="rf-label">Borrow date</div>
                                    <div class="rf-value" id="rev-borrow">—</div>
                                </div>
                                <div class="review-field">
                                    <div class="rf-label">Return date</div>
                                    <div class="rf-value" id="rev-return">—</div>
                                </div>
                                <div class="review-field">
                                    <div class="rf-label">Program type</div>
                                    <div class="rf-value" id="rev-program">—</div>
                                </div>
                                <div class="review-field">
                                    <div class="rf-label">Usage location</div>
                                    <div class="rf-value" id="rev-location">—</div>
                                </div>
                                <div class="review-field" style="grid-column:1/-1">
                                    <div class="rf-label">Reason</div>
                                    <div class="rf-value" id="rev-reason" style="font-weight:400;font-size:0.88rem;line-height:1.5">—</div>
                                </div>
                                <div class="review-items">
                                    <div class="rf-label">Requested equipment</div>
                                    <div class="review-item-list" id="rev-items"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="step-nav">
                        <button type="button" class="btn-prev" data-go="3"><i class="ri-arrow-left-line"></i> Back</button>
                        <span class="step-counter">Step 4 of 4</span>
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <i class="ri-send-plane-fill"></i> Submit request
                        </button>
                    </div>
                </div>

            </form>
        </div>
    </main>

    <script>
    (function () {
        // ── Sidebar toggle ──
        var body = document.body;
        document.getElementById('navMenuToggle')?.addEventListener('click', function () {
            body.classList.toggle('nav-drawer-open');
        });
        document.getElementById('navDrawerBackdrop')?.addEventListener('click', function () {
            body.classList.remove('nav-drawer-open');
        });
        window.addEventListener('resize', function () {
            if (window.innerWidth > 900) body.classList.remove('nav-drawer-open');
        });
    })();

    (function () {
        var catalog = <?= json_encode($equipment_catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var byId = {};
        catalog.forEach(function (e) { byId[e.id] = e; });

        var MAX_QTY = 99;
        var cart = new Map();
        var currentStep = 1;
        var totalSteps = 4;

        // ── DOM refs ──
        var hiddenWrap   = document.getElementById('cartHiddenInputs');
        var acceptTerms  = document.getElementById('acceptTerms');
        var next3Btn     = document.getElementById('next3');
        var summaryWrap  = document.getElementById('selectedSummary');

        // Program label map
        var programLabels = {
            academic: 'Academic project / class',
            official_event: 'Official event',
            club_society: 'Club / society activities'
        };

        // ── Stepper ──
        function goToStep(n) {
            document.querySelectorAll('.step-panel').forEach(function (p, i) {
                p.classList.toggle('active', i + 1 === n);
            });
            document.querySelectorAll('.stepper-item').forEach(function (item, i) {
                var s = i + 1;
                item.classList.remove('active', 'done');
                if (s < n) item.classList.add('done');
                if (s === n) item.classList.add('active');
                // swap number ↔ check icon for done steps
                var circle = item.querySelector('.step-circle');
                if (s < n) {
                    circle.innerHTML = '<i class="ri-check-line" style="font-size:1rem"></i>';
                } else if (s === 4) {
                    circle.innerHTML = n === 4
                        ? '<i class="ri-check-line" style="font-size:1rem"></i>'
                        : '<i class="ri-check-line" style="font-size:1rem"></i>';
                    circle.innerHTML = '<i class="ri-check-line" style="font-size:1rem"></i>';
                } else {
                    circle.innerHTML = s;
                }
            });
            currentStep = n;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ── Step 1 validation ──
        document.getElementById('next1').addEventListener('click', function () {
            var bd = document.getElementById('borrowDate').value;
            var rd = document.getElementById('returnDate').value;
            var prog = document.querySelector('input[name="program_type"]:checked');
            var loc = document.getElementById('usageLocation').value.trim();
            var rsn = document.getElementById('reason').value.trim();

            if (!bd || !rd) { alert('Please choose both borrow and return dates.'); return; }
            if (bd > rd) { alert('Return date must be on or after the borrow date.'); return; }
            if (!prog) { alert('Please select a program type.'); return; }
            if (!loc) { alert('Please enter the usage location.'); return; }
            if (rsn.length < 5) { alert('Please enter a reason (at least a few words).'); return; }
            goToStep(2);
        });

        function cartTotalUnits() {
            var t = 0;
            cart.forEach(function (q) { t += q; });
            return t;
        }

        // ── Step 2 validation ──
        document.getElementById('next2').addEventListener('click', function () {
            if (cartTotalUnits() < 1) { alert('Please add at least one item to your request.'); return; }
            goToStep(3);
        });

        // ── Step 3 → Step 4: populate review ──
        document.getElementById('next3').addEventListener('click', function () {
            if (!acceptTerms.checked) return;
            populateReview();
            goToStep(4);
        });

        // ── Back buttons ──
        document.querySelectorAll('.btn-prev[data-go]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                goToStep(parseInt(btn.getAttribute('data-go')));
            });
        });

        // ── Terms checkbox enables next3 ──
        acceptTerms.addEventListener('change', function () {
            next3Btn.disabled = !acceptTerms.checked;
        });
        // init
        next3Btn.disabled = !acceptTerms.checked;

        function getQty(id) {
            return cart.get(id) || 0;
        }

        function setQty(id, q) {
            q = parseInt(q, 10);
            if (isNaN(q) || q < 1) {
                cart.delete(id);
            } else {
                cart.set(id, Math.min(MAX_QTY, q));
            }
            updateEquipmentCards();
            renderSummaryChips();
            syncHidden();
        }

        function addDelta(id, delta) {
            var q = getQty(id) + delta;
            setQty(id, q);
        }

        function syncHidden() {
            hiddenWrap.innerHTML = '';
            cart.forEach(function (q, id) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'qty[' + id + ']';
                inp.value = String(q);
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
                var e = byId[id];
                if (!e) return;
                var chip = document.createElement('span');
                chip.className = 'sel-chip';
                var wrap = document.createElement('span');
                wrap.textContent = e.name + ' × ' + qty;
                chip.appendChild(wrap);
                var btns = document.createElement('span');
                btns.className = 'chip-qty-btns';
                btns.innerHTML =
                    '<button type="button" data-chip-delta="-1" aria-label="Decrease"><i class="ri-subtract-line"></i></button>' +
                    '<button type="button" data-chip-delta="1" aria-label="Increase"><i class="ri-add-line"></i></button>' +
                    '<button type="button" data-chip-remove aria-label="Remove ' + escapeHtml(e.name) + '"><i class="ri-close-line"></i></button>';
                chip.appendChild(btns);
                btns.querySelector('[data-chip-delta="-1"]').addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    addDelta(id, -1);
                });
                btns.querySelector('[data-chip-delta="1"]').addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    addDelta(id, 1);
                });
                btns.querySelector('[data-chip-remove]').addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    setQty(id, 0);
                });
                summaryWrap.appendChild(chip);
            });
        }

        function updateEquipmentCards() {
            document.querySelectorAll('.eq-card').forEach(function (card) {
                var id = card.getAttribute('data-eq-id');
                var q = getQty(id);
                card.classList.toggle('in-cart', q > 0);
                var disp = card.querySelector('[data-eq-qty-display]');
                if (disp) disp.textContent = String(q);
                var minus = card.querySelector('[data-eq-delta="-1"]');
                var plus = card.querySelector('[data-eq-delta="1"]');
                if (minus) minus.disabled = q < 1;
                if (plus) plus.disabled = q >= MAX_QTY;
            });
        }

        function eventElement(ev) {
            var t = ev.target;
            return (t && t.nodeType === 1) ? t : (t && t.parentElement) || null;
        }

        document.querySelectorAll('.eq-card .eq-qty-btn').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var card = btn.closest('.eq-card');
                if (!card) return;
                var id = card.getAttribute('data-eq-id');
                var d = parseInt(btn.getAttribute('data-eq-delta'), 10);
                if (!id || (d !== 1 && d !== -1)) return;
                addDelta(id, d);
            });
        });

        var equipmentGridEl = document.getElementById('equipmentGrid');
        if (equipmentGridEl) {
            equipmentGridEl.addEventListener('click', function (ev) {
                var el = eventElement(ev);
                if (!el) return;
                if (el.closest('.eq-qty-btn')) return;
                var main = el.closest('.eq-card-main');
                if (!main) return;
                var card2 = main.closest('.eq-card');
                var id2 = card2 && card2.getAttribute('data-eq-id');
                if (id2 && getQty(id2) < MAX_QTY) {
                    addDelta(id2, 1);
                }
            });
        }

        // ── Populate review page ──
        function populateReview() {
            var bd = document.getElementById('borrowDate').value;
            var rd = document.getElementById('returnDate').value;
            var prog = document.querySelector('input[name="program_type"]:checked');
            var loc = document.getElementById('usageLocation').value.trim();
            var rsn = document.getElementById('reason').value.trim();

            document.getElementById('rev-borrow').textContent = bd || '—';
            document.getElementById('rev-return').textContent = rd || '—';
            document.getElementById('rev-program').textContent = prog ? (programLabels[prog.value] || prog.value) : '—';
            document.getElementById('rev-location').textContent = loc || '—';
            document.getElementById('rev-reason').textContent = rsn || '—';

            var revItems = document.getElementById('rev-items');
            revItems.innerHTML = '';
            if (cart.size === 0) {
                revItems.innerHTML = '<span style="font-size:0.82rem;color:var(--text-muted)">No items selected</span>';
            } else {
                cart.forEach(function (qty, id) {
                    var e = byId[id];
                    if (!e) return;
                    var badge = document.createElement('span');
                    badge.className = 'review-item-badge';
                    badge.appendChild(document.createTextNode(e.name));
                    var qspan = document.createElement('span');
                    qspan.className = 'riv-qty';
                    qspan.textContent = '×' + qty;
                    badge.appendChild(qspan);
                    revItems.appendChild(badge);
                });
            }
        }

        function escapeHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        var initialQty = <?= json_encode($initial_qty, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        Object.keys(initialQty).forEach(function (id) {
            var q = parseInt(initialQty[id], 10);
            if (q > 0 && byId[id]) cart.set(id, Math.min(MAX_QTY, q));
        });
        if (cart.size > 0) {
            renderSummaryChips();
            syncHidden();
        }
        updateEquipmentCards();

        // If there was a PHP-side error, jump to the relevant step
        <?php if ($form_error !== ''): ?>
        (function() {
            var err = <?= json_encode($form_error) ?>;
            if (err.includes('item')) { goToStep(2); }
            else if (err.includes('terms')) { goToStep(3); }
            else { goToStep(1); }
        })();
        <?php endif; ?>

    })();
    </script>
</body>
</html>