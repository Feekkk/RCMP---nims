<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/nextcheck_shared.php';

const NEXCHECK_POOL_STATUS = 11;
const NEXCHECK_BUFFER_STATUS = 14;
const NEXCHECK_ASSIGN_TARGET_STATUS = 13;
const NEXCHECK_RETURN_FROM_STATUS = 13;
const NEXCHECK_RETURN_TO_STATUS = 14;

$staffId = (string)($_SESSION['staff_id'] ?? '');

function nexcheck_format_program(string $p): string {
    return match ($p) {
        'academic'       => 'Academic project / class',
        'official_event' => 'Official event',
        'club_society'   => 'Club / society activities',
        default          => $p,
    };
}

/** Request line labels use "Name — Group" from users/form.php; only Laptop lines use the laptop table. */
function nexcheck_request_item_asset_class(string $category): string {
    $head = trim(explode('—', $category, 2)[0] ?? '');
    return strcasecmp($head, 'Laptop') === 0 ? 'laptop' : 'av';
}

$nexcheckId = isset($_GET['nexcheck_id']) ? (int)$_GET['nexcheck_id'] : 0;
if ($nexcheckId < 1) { header('Location: nextCheckout.php'); exit; }

$form_error   = '';
$return_error = '';
$success      = isset($_GET['saved']) && (string)$_GET['saved'] === '1';
$return_ok    = isset($_GET['returned']) && (string)$_GET['returned'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_returns'])) {
    $postNex = isset($_POST['nexcheck_id']) ? (int)$_POST['nexcheck_id'] : 0;
    $conds   = $_POST['return_cond'] ?? [];
    $remarks = $_POST['return_remark'] ?? [];
    if (!is_array($conds)) $conds = [];
    if (!is_array($remarks)) $remarks = [];
    if ($postNex < 1 || $postNex !== $nexcheckId) {
        $return_error = 'Invalid request.';
    } else {
        $toProcess = [];
        foreach ($conds as $aidKey => $choice) {
            $aid = (int)$aidKey;
            $ch  = strtolower(trim((string)$choice));
            if ($aid < 1 || $ch === '') {
                continue;
            }
            if ($ch === 'good') {
                $toProcess[$aid] = 'Good condition';
                continue;
            }
            if ($ch === 'bad') {
                $toProcess[$aid] = 'Bad condition';
                continue;
            }
            if ($ch === 'other' || $ch === 'others') {
                $rm = trim((string)($remarks[$aidKey] ?? ''));
                if (mb_strlen($rm) < 2) {
                    continue;
                }
                if (mb_strlen($rm) > 500) {
                    $rm = mb_substr($rm, 0, 500);
                }
                $toProcess[$aid] = 'Other: ' . $rm;
                continue;
            }
        }
        if ($toProcess === []) {
            $return_error = 'Select a return condition for at least one checkout item (Others requires a remark).';
        } else {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $stmtLock = $pdo->prepare('
                    SELECT a.assignment_id, a.nexcheck_id, a.asset_id, a.returned_at,
                           l.status_id AS laptop_status_id, v.status_id AS av_status_id
                    FROM nexcheck_assignment a
                    LEFT JOIN laptop l ON l.asset_id = a.asset_id
                    LEFT JOIN av v ON v.asset_id = a.asset_id
                    WHERE a.assignment_id = ?
                    FOR UPDATE
                ');
                $stmtUpA = $pdo->prepare('
                    UPDATE nexcheck_assignment
                    SET returned_at = NOW(), return_condition = ?, returned_by = ?
                    WHERE assignment_id = ? AND nexcheck_id = ? AND returned_at IS NULL
                ');
                $stmtUpL = $pdo->prepare('UPDATE laptop SET status_id = ?, nextcheck_buffer_since = NOW() WHERE asset_id = ?');
                $stmtUpV = $pdo->prepare('UPDATE av SET status_id = ?, nextcheck_buffer_since = NOW() WHERE asset_id = ?');
                foreach ($toProcess as $assignId => $condText) {
                    $stmtLock->execute([$assignId]);
                    $row = $stmtLock->fetch(PDO::FETCH_ASSOC);
                    if (!$row || (int)$row['nexcheck_id'] !== $nexcheckId) {
                        throw new RuntimeException('Invalid assignment.');
                    }
                    if ($row['returned_at'] !== null && $row['returned_at'] !== '') {
                        throw new RuntimeException('Assignment #' . $assignId . ' was already returned.');
                    }
                    $lSid = $row['laptop_status_id'] ?? null;
                    $vSid = $row['av_status_id'] ?? null;
                    $curSid = $lSid !== null && $lSid !== '' ? (int)$lSid : ($vSid !== null && $vSid !== '' ? (int)$vSid : null);
                    if ($curSid === null) {
                        throw new RuntimeException('Asset #' . (int)$row['asset_id'] . ' not found in laptop or AV inventory.');
                    }
                    if ($curSid !== NEXCHECK_RETURN_FROM_STATUS) {
                        throw new RuntimeException('Asset #' . (int)$row['asset_id'] . ' is not in checkout (status ' . NEXCHECK_RETURN_FROM_STATUS . ').');
                    }
                    $stmtUpA->execute([$condText, $staffId, $assignId, $nexcheckId]);
                    if ($stmtUpA->rowCount() !== 1) {
                        throw new RuntimeException('Could not update assignment #' . $assignId . '.');
                    }
                    if ($lSid !== null && $lSid !== '') {
                        $stmtUpL->execute([NEXCHECK_RETURN_TO_STATUS, (int)$row['asset_id']]);
                    } elseif ($vSid !== null && $vSid !== '') {
                        $stmtUpV->execute([NEXCHECK_RETURN_TO_STATUS, (int)$row['asset_id']]);
                    } else {
                        throw new RuntimeException('Could not resolve inventory table for asset #' . (int)$row['asset_id'] . '.');
                    }
                }
                $pdo->commit();
                header('Location: nextItems.php?nexcheck_id=' . $nexcheckId . '&returned=1#nexcheck-return');
                exit;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $return_error = $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assignments'])) {
    $postNex = isset($_POST['nexcheck_id']) ? (int)$_POST['nexcheck_id'] : 0;
    $assign  = $_POST['assign'] ?? [];
    if (!is_array($assign)) { $assign = []; }
    if ($postNex !== $nexcheckId || $postNex < 1) {
        $form_error = 'Invalid request.';
    } else {
        $pairs = [];
        foreach ($assign as $itemKey => $assetVal) {
            $rid = (int)$itemKey;
            $aid = (int)(is_string($assetVal) ? trim($assetVal) : $assetVal);
            if ($rid < 1 || $aid < 1) { continue; }
            $pairs[$rid] = $aid;
        }
        if ($pairs === []) {
            $form_error = 'Select at least one asset to assign.';
        } else {
            $usedAssets = [];
            foreach ($pairs as $aid) {
                if (isset($usedAssets[$aid])) { $form_error = 'Each asset can only be used once.'; break; }
                $usedAssets[$aid] = true;
            }
        }
        if ($form_error === '') {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $stmtItem      = $pdo->prepare('SELECT request_item_id, nexcheck_id, category FROM nexcheck_request_item WHERE request_item_id = ? FOR UPDATE');
                $stmtHasAssign = $pdo->prepare('SELECT assignment_id FROM nexcheck_assignment WHERE request_item_id = ? FOR UPDATE');
                $stmtLaptop    = $pdo->prepare('SELECT asset_id, status_id FROM laptop WHERE asset_id = ? FOR UPDATE');
                $stmtAv        = $pdo->prepare('SELECT asset_id, status_id FROM av WHERE asset_id = ? FOR UPDATE');
                $stmtIns       = $pdo->prepare('INSERT INTO nexcheck_assignment (nexcheck_id, request_item_id, asset_id, assigned_by, assigned_at, checkout_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
                $stmtUpL       = $pdo->prepare('UPDATE laptop SET status_id = ?, nextcheck_buffer_since = NULL WHERE asset_id = ?');
                $stmtUpV       = $pdo->prepare('UPDATE av SET status_id = ?, nextcheck_buffer_since = NULL WHERE asset_id = ?');
                foreach ($pairs as $requestItemId => $assetId) {
                    $stmtItem->execute([$requestItemId]);
                    $itemRow = $stmtItem->fetch(PDO::FETCH_ASSOC);
                    if (!$itemRow || (int)$itemRow['nexcheck_id'] !== $nexcheckId) { throw new RuntimeException('Invalid line item.'); }
                    $stmtHasAssign->execute([$requestItemId]);
                    if ($stmtHasAssign->fetch()) { throw new RuntimeException('Line #' . $requestItemId . ' is already assigned.'); }
                    $lineClass = nexcheck_request_item_asset_class((string)($itemRow['category'] ?? ''));
                    if ($lineClass === 'laptop') {
                        $stmtLaptop->execute([$assetId]);
                        $inv = $stmtLaptop->fetch(PDO::FETCH_ASSOC);
                        if (!$inv || (int)$inv['status_id'] !== NEXCHECK_POOL_STATUS) { throw new RuntimeException('Laptop asset ' . $assetId . ' is not available.'); }
                        $stmtIns->execute([$nexcheckId, $requestItemId, $assetId, $staffId]);
                        $stmtUpL->execute([NEXCHECK_ASSIGN_TARGET_STATUS, $assetId]);
                    } else {
                        if (!nextcheck_checkout_table_exists($pdo, 'av')) {
                            throw new RuntimeException('AV inventory is not available for this request line.');
                        }
                        $stmtAv->execute([$assetId]);
                        $inv = $stmtAv->fetch(PDO::FETCH_ASSOC);
                        if (!$inv || (int)$inv['status_id'] !== NEXCHECK_POOL_STATUS) { throw new RuntimeException('AV asset ' . $assetId . ' is not available.'); }
                        $stmtIns->execute([$nexcheckId, $requestItemId, $assetId, $staffId]);
                        $stmtUpV->execute([NEXCHECK_ASSIGN_TARGET_STATUS, $assetId]);
                    }
                }
                $pdo->commit();
                header('Location: nextItems.php?nexcheck_id=' . $nexcheckId . '&saved=1');
                exit;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
                if ($e instanceof PDOException && $e->getCode() === '23000') {
                    $em = $e->getMessage();
                    $form_error = (stripos($em, '1452') !== false || stripos($em, 'foreign key') !== false)
                        ? 'Assignment blocked by database rules (AV vs laptop). Run db/migrate_nexcheck_assignment_allow_av.sql on the server, then try again.'
                        : 'Duplicate assignment or constraint conflict.';
                } else {
                    $form_error = $e->getMessage();
                }
            }
        }
    }
}

$request = null; $items = []; $pool = [];
try {
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT r.*, u.full_name AS requester_name, u.email AS requester_email FROM nexcheck_request r JOIN users u ON u.staff_id = r.requested_by WHERE r.nexcheck_id = ? LIMIT 1');
    $stmt->execute([$nexcheckId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) { header('Location: nextCheckout.php'); exit; }

    $hasAvTable = nextcheck_checkout_table_exists($pdo, 'av');
    if ($hasAvTable) {
        $stmtI = $pdo->prepare('
            SELECT
                i.request_item_id, i.category, i.quantity,
                a.assignment_id, a.asset_id AS assigned_asset_id, a.assigned_at,
                a.returned_at, a.return_condition,
                COALESCE(l.serial_num, av.serial_num) AS assigned_serial,
                COALESCE(l.brand, av.brand) AS assigned_brand,
                COALESCE(l.model, av.model) AS assigned_model,
                COALESCE(l.status_id, av.status_id) AS asset_status_id
            FROM nexcheck_request_item i
            LEFT JOIN nexcheck_assignment a ON a.request_item_id = i.request_item_id
            LEFT JOIN laptop l ON l.asset_id = a.asset_id
            LEFT JOIN av av ON av.asset_id = a.asset_id
            WHERE i.nexcheck_id = ?
            ORDER BY i.request_item_id ASC
        ');
    } else {
        $stmtI = $pdo->prepare('
            SELECT
                i.request_item_id, i.category, i.quantity,
                a.assignment_id, a.asset_id AS assigned_asset_id, a.assigned_at,
                a.returned_at, a.return_condition,
                l.serial_num AS assigned_serial,
                l.brand AS assigned_brand,
                l.model AS assigned_model,
                l.status_id AS asset_status_id
            FROM nexcheck_request_item i
            LEFT JOIN nexcheck_assignment a ON a.request_item_id = i.request_item_id
            LEFT JOIN laptop l ON l.asset_id = a.asset_id
            WHERE i.nexcheck_id = ?
            ORDER BY i.request_item_id ASC
        ');
    }
    $stmtI->execute([$nexcheckId]);
    $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

    $pool = [];
    $stmtP = $pdo->prepare('SELECT l.asset_id, l.serial_num, l.brand, l.model, l.category FROM laptop l WHERE l.status_id = ? ORDER BY l.category ASC, l.asset_id DESC');
    $stmtP->execute([NEXCHECK_POOL_STATUS]);
    foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['asset_class'] = 'laptop';
        $pool[] = $row;
    }
    if (nextcheck_checkout_table_exists($pdo, 'av')) {
        $stmtA = $pdo->prepare('SELECT a.asset_id, a.serial_num, a.brand, a.model, a.category FROM av a WHERE a.status_id = ? ORDER BY a.category ASC, a.asset_id DESC');
        $stmtA->execute([NEXCHECK_POOL_STATUS]);
        foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['asset_class'] = 'av';
            $pool[] = $row;
        }
    }
} catch (Throwable $e) { $request = null; }

$poolLaptopCount = 0;
$poolAvCount     = 0;
foreach ($pool as $p) {
    if (($p['asset_class'] ?? '') === 'av') {
        $poolAvCount++;
    } else {
        $poolLaptopCount++;
    }
}

$needCount  = 0; $doneCount = 0; $returnableCount = 0;
foreach ($items as $it) {
    if (empty($it['assignment_id'])) {
        $needCount++;
    } else {
        $doneCount++;
        $hasRet = !empty($it['returned_at']);
        $sid    = isset($it['asset_status_id']) ? (int)$it['asset_status_id'] : 0;
        if (!$hasRet && $sid === NEXCHECK_RETURN_FROM_STATUS) {
            $returnableCount++;
        }
    }
}
$totalItems = count($items);
$allDone    = $needCount === 0 && $totalItems > 0;
$pct        = $totalItems > 0 ? round(($doneCount / $totalItems) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign items #<?= (int)$nexcheckId ?> — NextCheck</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #1a1a2e;
            --accent: #2f5bea;
            --accent-light: rgba(47,91,234,0.08);
            --bg: #f5f5f7;
            --card-bg: #ffffff;
            --card-border: #e8e8ed;
            --text-main: #1a1a1a;
            --text-muted: #8a8a9a;
            --text-sub: #5a5a6e;
            --success: #1c9e6e;
            --success-bg: rgba(28,158,110,0.07);
            --success-border: rgba(28,158,110,0.18);
            --danger: #c0392b;
            --danger-bg: rgba(192,57,43,0.06);
            --danger-border: rgba(192,57,43,0.18);
            --warning: #c07a00;
            --warning-bg: rgba(192,122,0,0.07);
            --purple: #5b4fcf;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2.5rem 3rem 4rem;
            max-width: calc(100vw - 280px);
        }
        @media (max-width: 900px) {
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem 1.25rem 3rem; }
        }

        /* ── Page header ── */
        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }
        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: -0.02em;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .page-header h1 i { color: var(--accent); font-size: 1.3rem; }
        .request-badge {
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.18rem 0.6rem;
            background: var(--accent-light);
            border: 1px solid rgba(47,91,234,0.18);
            border-radius: 6px;
            color: var(--accent);
            font-family: 'DM Mono', monospace;
            letter-spacing: 0.01em;
        }
        .page-header p { color: var(--text-muted); margin-top: 0.5rem; font-size: 0.875rem; line-height: 1.6; max-width: 520px; }
        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 1rem;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-sub);
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .btn-ghost:hover { color: var(--accent); border-color: rgba(47,91,234,0.25); background: var(--accent-light); }

        /* ── Banners ── */
        .banner {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            padding: 0.85rem 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            line-height: 1.5;
            font-weight: 400;
        }
        .banner i { font-size: 1rem; flex-shrink: 0; margin-top: 0.1rem; }
        .banner strong { font-weight: 600; }
        .banner-error   { background: var(--danger-bg); border: 1px solid var(--danger-border); color: var(--danger); }
        .banner-success { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success); }

        /* ── Progress bar ── */
        .progress-wrap {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 1.1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .progress-stat { display: flex; flex-direction: column; align-items: center; min-width: 48px; }
        .ps-num {
            font-size: 1.6rem;
            font-weight: 600;
            line-height: 1;
            letter-spacing: -0.03em;
        }
        .ps-label {
            font-size: 0.65rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        .ps-num.c-total { color: var(--text-main); }
        .ps-num.c-done  { color: var(--success); }
        .ps-num.c-need  { color: var(--warning); }
        .ps-num.c-return { color: var(--purple); }
        .divider-v { width: 1px; height: 32px; background: var(--card-border); flex-shrink: 0; }
        .progress-bar-wrap { flex: 1; min-width: 160px; display: flex; flex-direction: column; gap: 0.45rem; }
        .progress-bar-track { height: 4px; border-radius: 99px; background: var(--card-border); overflow: hidden; }
        .progress-bar-fill { height: 100%; border-radius: 99px; background: var(--success); transition: width 0.5s ease; }
        .progress-bar-label { font-size: 0.75rem; font-weight: 500; color: var(--text-muted); }

        /* ── Info grid ── */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            align-items: start;
            margin-bottom: 1rem;
        }
        @media (max-width: 960px) { .grid-2 { grid-template-columns: 1fr; } }

        /* ── Cards ── */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            overflow: hidden;
        }
        .card-hd {
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid var(--card-border);
            font-weight: 600;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-main);
        }
        .card-hd i { color: var(--accent); font-size: 1rem; }
        .card-hd .hd-extra { margin-left: auto; font-size: 0.75rem; font-weight: 500; color: var(--text-muted); }
        .card-bd { padding: 1.1rem 1.25rem; }

        /* ── Meta grid ── */
        .meta-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 0.5rem; }
        .meta-cell {
            background: var(--bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
        }
        .meta-cell.full { grid-column: 1 / -1; }
        .meta-cell dt { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.07em; color: var(--text-muted); margin-bottom: 0.25rem; }
        .meta-cell dd { font-weight: 500; font-size: 0.875rem; color: var(--text-main); word-break: break-word; }
        .reason-box {
            margin-top: 0.65rem;
            padding: 0.75rem 0.9rem;
            background: var(--bg);
            border-radius: 8px;
            border: 1px solid var(--card-border);
            font-size: 0.85rem;
            line-height: 1.6;
            color: var(--text-sub);
        }
        .reason-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.07em; color: var(--text-muted); display: block; margin-bottom: 0.35rem; }

        /* ── Pool card ── */
        .pool-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.65rem;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 500;
        }
        .pool-pill.ok   { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success); }
        .pool-pill.warn { background: var(--warning-bg); border: 1px solid rgba(192,122,0,0.2); color: var(--warning); }
        .pool-preview-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            padding: 0.3rem 0.6rem;
            background: var(--bg);
            border: 1px solid var(--card-border);
            border-radius: 6px;
            color: var(--text-sub);
        }

        /* ── Line items table ── */
        .tbl-header {
            display: grid;
            grid-template-columns: 2rem 1.1fr 1.8fr 6rem;
            gap: 1rem;
            padding: 0.55rem 1.25rem;
            background: var(--bg);
            border-bottom: 1px solid var(--card-border);
        }
        .tbl-header span { font-size: 0.63rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted); }
        @media (max-width: 700px) { .tbl-header { display: none; } }

        .line-row {
            display: grid;
            grid-template-columns: 2rem 1.1fr 1.8fr 6rem;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--card-border);
            transition: background 0.1s;
        }
        .line-row:last-child { border-bottom: none; }
        .line-row:hover { background: #fafafa; }
        @media (max-width: 700px) { .line-row { grid-template-columns: 1fr; gap: 0.6rem; } }

        .row-num {
            width: 1.75rem; height: 1.75rem;
            border-radius: 6px;
            background: var(--accent-light);
            color: var(--accent);
            display: flex; align-items: center; justify-content: center;
            font-weight: 600;
            font-size: 0.78rem;
            flex-shrink: 0;
            font-family: 'DM Mono', monospace;
        }
        .item-cat-name { font-weight: 500; font-size: 0.875rem; }
        .item-cat-sub  { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.1rem; font-family: 'DM Mono', monospace; }

        .assigned-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 7px;
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            color: var(--success);
            font-weight: 500;
            font-size: 0.8rem;
            flex-wrap: wrap;
        }
        .ap-serial { font-weight: 400; opacity: 0.75; font-size: 0.75rem; font-family: 'DM Mono', monospace; }

        .asset-select-wrap { width: 100%; max-width: 22rem; }
        .asset-select {
            width: 100%;
            padding: 0.55rem 2rem 0.55rem 0.85rem;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.85rem;
            font-weight: 400;
            background: var(--card-bg);
            color: var(--text-main);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238a8a9a' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.65rem center;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .asset-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(47,91,234,0.1);
        }
        .asset-select option:disabled { color: var(--text-muted); }
        .asset-select-hint { font-size: 0.7rem; color: var(--text-muted); margin-top: 0.3rem; display: flex; align-items: center; gap: 0.3rem; }

        /* Status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
        }
        .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
        .dot.green { background: var(--success); }
        .dot.amber { background: var(--warning); }

        /* ── Form footer ── */
        .form-footer {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.9rem 1.25rem;
            border-top: 1px solid var(--card-border);
            background: var(--bg);
            flex-wrap: wrap;
        }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.6rem 1.25rem;
            border: none;
            border-radius: 8px;
            background: var(--accent);
            color: #fff;
            font-weight: 600;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.15s ease;
            letter-spacing: -0.01em;
        }
        .btn-primary:hover:not(:disabled) { background: #2448d4; }
        .btn-primary:active:not(:disabled) { transform: scale(0.99); }
        .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
        .footer-note { font-size: 0.8rem; color: var(--text-muted); line-height: 1.5; }

        #nexcheck-return { scroll-margin-top: 1rem; }
        .return-row {
            display: grid;
            grid-template-columns: 1fr minmax(180px, 1.2fr);
            gap: 1rem;
            align-items: start;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--card-border);
        }
        .return-row:last-of-type { border-bottom: none; }
        @media (max-width: 700px) { .return-row { grid-template-columns: 1fr; } }
        .return-asset-label { font-weight: 500; font-size: 0.875rem; }
        .return-asset-sub { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem; font-family: 'DM Mono', monospace; }
        .cond-input {
            width: 100%;
            min-height: 4rem;
            padding: 0.6rem 0.8rem;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.85rem;
            resize: vertical;
            color: var(--text-main);
            background: var(--card-bg);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .cond-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(47,91,234,0.1);
        }
        .status-badge.returned { color: #0369a1; }
        .status-badge.checkout { color: var(--purple); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <?php if ($request === null): ?>
        <p style="color:var(--text-muted);margin-bottom:1rem">Request not found.</p>
        <a class="btn-ghost" href="nextCheckout.php"><i class="ri-arrow-left-line"></i> Back to list</a>
    <?php else: ?>

    <!-- Page header -->
    <header class="page-header">
        <div>
            <h1>
                <i class="ri-links-line" style="font-size:1.4rem;color:var(--primary)"></i>
                Assign Items
                <span class="request-badge">#<?= (int)$nexcheckId ?></span>
            </h1>
            <p>Match each line to a pooled asset (status <?= NEXCHECK_POOL_STATUS ?>): <strong>Laptop</strong> lines use the laptop table; other equipment uses <strong>AV</strong>. Saved rows move to Checkout (<?= NEXCHECK_ASSIGN_TARGET_STATUS ?>).</p>
        </div>
        <a class="btn-ghost" href="nextCheckout.php"><i class="ri-arrow-left-line"></i> All requests</a>
    </header>

    <!-- Banners -->
    <?php if ($success): ?>
        <div class="banner banner-success">
            <i class="ri-checkbox-circle-line"></i>
            <div><strong>Assignments saved.</strong> Assets are now in Checkout status.</div>
        </div>
    <?php endif; ?>
    <?php if ($form_error !== ''): ?>
        <div class="banner banner-error">
            <i class="ri-error-warning-line"></i>
            <div><?= htmlspecialchars($form_error) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($return_ok): ?>
        <div class="banner banner-success">
            <i class="ri-checkbox-circle-line"></i>
            <div><strong>Return recorded.</strong> Asset(s) are in buffer (status <?= NEXCHECK_BUFFER_STATUS ?>); after 24 hours they return to pool (<?= NEXCHECK_POOL_STATUS ?>) automatically if the cron job is scheduled.</div>
        </div>
    <?php endif; ?>
    <?php if ($return_error !== ''): ?>
        <div class="banner banner-error">
            <i class="ri-error-warning-line"></i>
            <div><?= htmlspecialchars($return_error) ?></div>
        </div>
    <?php endif; ?>

    <!-- Progress -->
    <?php if ($totalItems > 0): ?>
    <div class="progress-wrap">
        <div class="progress-stat">
            <span class="ps-num c-total"><?= $totalItems ?></span>
            <span class="ps-label">Total</span>
        </div>
        <div class="divider-v"></div>
        <div class="progress-stat">
            <span class="ps-num c-done"><?= $doneCount ?></span>
            <span class="ps-label">Assigned</span>
        </div>
        <div class="divider-v"></div>
        <div class="progress-stat" title="Assigned items still in checkout — not yet returned to pool">
            <span class="ps-num c-return"><?= $returnableCount ?></span>
            <span class="ps-label">To return</span>
        </div>
        <div class="divider-v"></div>
        <div class="progress-bar-wrap">
            <div class="progress-bar-track">
                <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="progress-bar-label">
                <?= $pct ?>% complete<?= $allDone ? ' — all lines assigned ✓' : '' ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Info grid -->
    <div class="grid-2">
        <!-- Requester details -->
        <div class="card">
            <div class="card-hd"><i class="ri-user-3-line"></i> Requester &amp; Details</div>
            <div class="card-bd">
                <dl class="meta-grid">
                    <div class="meta-cell">
                        <dt>Name</dt>
                        <dd><?= htmlspecialchars((string)$request['requester_name']) ?></dd>
                    </div>
                    <div class="meta-cell">
                        <dt>Email</dt>
                        <dd><?= htmlspecialchars((string)$request['requester_email']) ?></dd>
                    </div>
                    <div class="meta-cell">
                        <dt>Borrow date</dt>
                        <dd><?= htmlspecialchars((string)$request['borrow_date']) ?></dd>
                    </div>
                    <div class="meta-cell">
                        <dt>Return date</dt>
                        <dd><?= htmlspecialchars((string)$request['return_date']) ?></dd>
                    </div>
                    <div class="meta-cell full">
                        <dt>Program type</dt>
                        <dd><?= htmlspecialchars(nexcheck_format_program((string)$request['program_type'])) ?></dd>
                    </div>
                    <div class="meta-cell full">
                        <dt>Usage location</dt>
                        <dd><?= htmlspecialchars((string)$request['usage_location']) ?></dd>
                    </div>
                </dl>
                <?php if (trim((string)$request['reason']) !== ''): ?>
                    <div class="reason-box">
                        <span class="reason-label">Reason</span>
                        <?= nl2br(htmlspecialchars((string)$request['reason'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pool -->
        <div class="card">
            <div class="card-hd">
                <i class="ri-stack-line"></i> Available Pool
                <span class="hd-extra">Status <?= NEXCHECK_POOL_STATUS ?></span>
            </div>
            <div class="card-bd">
                <div style="margin-bottom:0.85rem">
                    <?php if (count($pool) > 0): ?>
                        <span class="pool-pill ok"><i class="ri-checkbox-circle-line"></i> <?= $poolLaptopCount ?> laptop<?= $poolLaptopCount !== 1 ? 's' : '' ?>, <?= $poolAvCount ?> AV — <?= count($pool) ?> total</span>
                    <?php else: ?>
                        <span class="pool-pill warn"><i class="ri-alert-line"></i> Nothing in pool (laptop + AV)</span>
                    <?php endif; ?>
                </div>
                <p style="font-size:0.82rem;color:var(--text-muted);line-height:1.55;margin-bottom:0.85rem">
                    Only <strong>pool (<?= NEXCHECK_POOL_STATUS ?>)</strong> laptop and AV assets appear in each line’s dropdown (matched to the line type).
                    <?php if (count($pool) === 0): ?>
                        <a href="nextAdd.php" style="color:var(--primary);font-weight:600">Add stock →</a>
                    <?php endif; ?>
                </p>
                <?php if (count($pool) > 0): ?>
                    <div style="font-size:0.67rem;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:0.5rem">Pool preview</div>
                    <div style="display:flex;flex-direction:column;gap:0.35rem;max-height:155px;overflow-y:auto">
                        <?php foreach (array_slice($pool, 0, 7) as $p): ?>
                        <div class="pool-preview-item">
                            <i class="<?= ($p['asset_class'] ?? '') === 'av' ? 'ri-film-line' : 'ri-laptop-line' ?>" style="color:<?= ($p['asset_class'] ?? '') === 'av' ? 'var(--warning)' : 'var(--primary)' ?>;font-size:0.9rem;flex-shrink:0"></i>
                            <span style="font-weight:600">#<?= (int)$p['asset_id'] ?></span>
                            <span style="color:var(--text-muted);font-size:0.78rem"><?= htmlspecialchars(trim((string)$p['brand'] . ' ' . (string)$p['model'])) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($pool) > 7): ?>
                            <div style="font-size:0.74rem;color:var(--text-muted);padding:0.15rem 0.4rem">+<?= count($pool) - 7 ?> more — see dropdown</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Line items & assignments -->
    <?php if ($items === []): ?>
        <div class="card">
            <div class="card-bd" style="text-align:center;padding:2.5rem;color:var(--text-muted)">
                <i class="ri-inbox-line" style="font-size:2.2rem;display:block;margin-bottom:0.6rem;opacity:0.5"></i>
                No line items on this request.
            </div>
        </div>
    <?php else: ?>
    <div class="card">
        <div class="card-hd">
            <i class="ri-list-check-3"></i> Line Items &amp; Assignments
            <span class="hd-extra"><?= $doneCount ?> / <?= $totalItems ?> assigned</span>
        </div>
        <form method="post" action="">
            <input type="hidden" name="save_assignments" value="1">
            <input type="hidden" name="nexcheck_id" value="<?= (int)$nexcheckId ?>">

            <!-- Column headers -->
            <div class="tbl-header">
                <span>#</span>
                <span>Category</span>
                <span>Asset assignment</span>
                <span>Status</span>
            </div>

            <!-- Rows -->
            <?php foreach ($items as $idx => $it):
                $rid        = (int)$it['request_item_id'];
                $hasA       = !empty($it['assignment_id']);
                $assignId   = $hasA ? (int)$it['assignment_id'] : 0;
                $brandModel = trim((string)$it['assigned_brand'] . ' ' . (string)$it['assigned_model']);
                $assetSid   = $hasA ? (int)($it['asset_status_id'] ?? 0) : 0;
                $isReturned = $hasA && !empty($it['returned_at']);
                $lineClass  = nexcheck_request_item_asset_class((string)($it['category'] ?? ''));
                $linePool   = array_values(array_filter($pool, static fn ($row) => ($row['asset_class'] ?? 'laptop') === $lineClass));
                $selLabel   = $lineClass === 'laptop' ? 'laptop' : 'AV';
            ?>
            <div class="line-row">
                <div class="row-num"><?= $idx + 1 ?></div>

                <div>
                    <div class="item-cat-name">
                        <i class="<?= $lineClass === 'av' ? 'ri-film-line' : 'ri-laptop-line' ?>" style="color:<?= $lineClass === 'av' ? 'var(--warning)' : 'var(--primary)' ?>;margin-right:0.3rem"></i><?= htmlspecialchars((string)$it['category']) ?>
                    </div>
                    <div class="item-cat-sub">Item #<?= $rid ?></div>
                </div>

                <div>
                    <?php if ($hasA): ?>
                        <span class="assigned-pill">
                            <i class="ri-checkbox-circle-fill"></i>
                            #<?= (int)$it['assigned_asset_id'] ?>
                            <?php if ($brandModel !== ''): ?><span>— <?= htmlspecialchars($brandModel) ?></span><?php endif; ?>
                            <?php if ((string)$it['assigned_serial'] !== ''): ?>
                                <span class="ap-serial">· <?= htmlspecialchars((string)$it['assigned_serial']) ?></span>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <div class="asset-select-wrap">
                            <select class="asset-select" name="assign[<?= $rid ?>]" id="assign-sel-<?= $rid ?>" aria-label="Select <?= htmlspecialchars($selLabel) ?> for line <?= $idx + 1 ?>">
                                <option value="">— Select <?= htmlspecialchars($selLabel) ?> —</option>
                                <?php foreach ($linePool as $p):
                                    $pAid = (int)$p['asset_id'];
                                    $optLabel = '#' . $pAid;
                                    $pBm = trim((string)($p['brand'] ?? '') . ' ' . (string)($p['model'] ?? ''));
                                    if ($pBm !== '') {
                                        $optLabel .= ' — ' . $pBm;
                                    }
                                    if ((string)($p['serial_num'] ?? '') !== '') {
                                        $optLabel .= ' · ' . (string)$p['serial_num'];
                                    }
                                    ?>
                                <option value="<?= $pAid ?>"><?= htmlspecialchars($optLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="asset-select-hint">
                                <i class="ri-information-line" style="color:var(--secondary)"></i>
                                <?= count($linePool) ?> in <?= htmlspecialchars($lineClass === 'av' ? 'AV' : 'Laptop') ?> pool (status <?= NEXCHECK_POOL_STATUS ?>)
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <?php if ($isReturned): ?>
                        <span class="status-badge returned"><span class="dot" style="background:#0ea5e9"></span>Returned</span>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.35rem;max-width:14rem"><?= htmlspecialchars(mb_substr((string)($it['return_condition'] ?? ''), 0, 80)) ?><?= mb_strlen((string)($it['return_condition'] ?? '')) > 80 ? '…' : '' ?></div>
                    <?php elseif ($hasA && $assetSid === NEXCHECK_RETURN_FROM_STATUS): ?>
                        <span class="status-badge checkout"><span class="dot" style="background:#8b5cf6"></span>Checkout (<?= NEXCHECK_RETURN_FROM_STATUS ?>)</span>
                    <?php elseif ($hasA): ?>
                        <span class="status-badge" style="color:var(--success)"><span class="dot green"></span>Assigned</span>
                    <?php else: ?>
                        <span class="status-badge" style="color:var(--warning)"><span class="dot amber"></span>Pending</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Footer action -->
            <?php if ($allDone): ?>
                <div class="form-footer">
                    <span style="display:inline-flex;align-items:center;gap:0.5rem;font-size:0.88rem;font-weight:600;color:var(--success)">
                        <i class="ri-check-double-line" style="font-size:1.1rem"></i>
                        All lines are assigned — nothing left to save.
                    </span>
                </div>
            <?php elseif ($needCount > 0): ?>
                <div class="form-footer">
                    <button class="btn-primary" type="submit">
                        <i class="ri-save-3-line"></i> Save assignments
                    </button>
                    <p class="footer-note">
                        <?php if (count($pool) === 0): ?>
                            <strong style="color:var(--warning)"><i class="ri-alert-line"></i> No assets in pool.</strong>
                            <a href="nextAdd.php" style="color:var(--primary);font-weight:600">Add items first →</a>
                        <?php else: ?>
                            Choose an asset for each pending row (type must match the line). Only rows with a selection are processed.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($returnableCount > 0): ?>
    <div class="card" id="nexcheck-return" style="margin-top:1.25rem">
        <div class="card-hd">
            <span><i class="ri-arrow-go-back-line"></i> Return equipment</span>
            <span class="hd-extra"><?= $returnableCount ?> in checkout</span>
        </div>
        <p style="padding:0.85rem 1.4rem 0;font-size:0.84rem;color:var(--text-muted);line-height:1.5">
            Items still in status <strong><?= NEXCHECK_RETURN_FROM_STATUS ?></strong> (checkout). Describe condition, then save — assets go to buffer (<strong><?= NEXCHECK_RETURN_TO_STATUS ?></strong>), then pool (<strong><?= NEXCHECK_POOL_STATUS ?></strong>) after 24h via cron.
        </p>
        <form method="post" action="">
            <input type="hidden" name="save_returns" value="1">
            <input type="hidden" name="nexcheck_id" value="<?= (int)$nexcheckId ?>">
            <?php foreach ($items as $it):
                if (empty($it['assignment_id']) || !empty($it['returned_at'])) {
                    continue;
                }
                if ((int)($it['asset_status_id'] ?? 0) !== NEXCHECK_RETURN_FROM_STATUS) {
                    continue;
                }
                $aid = (int)$it['assignment_id'];
                $bm  = trim((string)$it['assigned_brand'] . ' ' . (string)$it['assigned_model']);
                ?>
            <div class="return-row">
                <div>
                    <div class="return-asset-label">#<?= (int)$it['assigned_asset_id'] ?><?= $bm !== '' ? ' — ' . htmlspecialchars($bm) : '' ?></div>
                    <div class="return-asset-sub"><?= htmlspecialchars((string)$it['category']) ?> · item #<?= (int)$it['request_item_id'] ?></div>
                </div>
                <div>
                    <label class="item-cat-sub" for="rc-<?= $aid ?>" style="display:block;margin-bottom:0.35rem">Condition at return</label>
                    <select class="asset-select" id="rc-<?= $aid ?>" name="return_cond[<?= $aid ?>]" data-return-cond="1" data-remark-id="rr-<?= $aid ?>">
                        <option value="">— Select —</option>
                        <option value="good">Good condition</option>
                        <option value="bad">Bad condition</option>
                        <option value="other">Others</option>
                    </select>
                    <textarea class="cond-input" id="rr-<?= $aid ?>" name="return_remark[<?= $aid ?>]" placeholder="Remark (Others only)..." maxlength="500" style="display:none;margin-top:0.5rem"></textarea>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="form-footer">
                <button class="btn-primary" type="submit"><i class="ri-check-line"></i> Record return(s)</button>
                <p class="footer-note">Only rows with a filled condition are processed. You can return items in separate batches.</p>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php endif; ?>

    <?php endif; ?>
</main>

<script>
(function () {
    function syncAssignDropdowns() {
        var selects = document.querySelectorAll('select.asset-select');
        if (!selects.length) return;
        var taken = {};
        selects.forEach(function (s) {
            var v = s.value;
            if (v) taken[v] = (taken[v] || 0) + 1;
        });
        selects.forEach(function (s) {
            var mine = s.value;
            Array.prototype.forEach.call(s.options, function (opt) {
                if (!opt.value) return;
                var id = opt.value;
                var count = taken[id] || 0;
                opt.disabled = id !== mine && count > 0;
            });
        });
    }
    document.querySelectorAll('select.asset-select').forEach(function (s) {
        s.addEventListener('change', syncAssignDropdowns);
    });
    syncAssignDropdowns();

    function syncReturnRemarks() {
        document.querySelectorAll('select[data-return-cond]').forEach(function (sel) {
            var rid = sel.getAttribute('data-remark-id');
            if (!rid) return;
            var ta = document.getElementById(rid);
            if (!ta) return;
            var v = String(sel.value || '').toLowerCase();
            var show = v === 'other' || v === 'others';
            ta.style.display = show ? 'block' : 'none';
            if (!show) ta.value = '';
        });
    }
    document.querySelectorAll('select[data-return-cond]').forEach(function (s) {
        s.addEventListener('change', syncReturnRemarks);
    });
    syncReturnRemarks();
})();
</script>
</body>
</html>