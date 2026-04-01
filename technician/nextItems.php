<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

const NEXCHECK_POOL_STATUS = 11;
const NEXCHECK_ASSIGN_TARGET_STATUS = 13;
const NEXCHECK_RETURN_FROM_STATUS = 13;
const NEXCHECK_RETURN_TO_STATUS = 11;

$staffId = (string)($_SESSION['staff_id'] ?? '');

function nexcheck_format_program(string $p): string {
    return match ($p) {
        'academic'       => 'Academic project / class',
        'official_event' => 'Official event',
        'club_society'   => 'Club / society activities',
        default          => $p,
    };
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
    if (!is_array($conds)) {
        $conds = [];
    }
    if ($postNex < 1 || $postNex !== $nexcheckId) {
        $return_error = 'Invalid request.';
    } else {
        $toProcess = [];
        foreach ($conds as $aidKey => $text) {
            $aid = (int)$aidKey;
            $c   = trim((string)$text);
            if ($aid < 1 || $c === '' || mb_strlen($c) < 2) {
                continue;
            }
            if (mb_strlen($c) > 500) {
                $c = mb_substr($c, 0, 500);
            }
            $toProcess[$aid] = $c;
        }
        if ($toProcess === []) {
            $return_error = 'Enter a condition (at least 2 characters) for at least one checkout item to return.';
        } else {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $stmtLock = $pdo->prepare('
                    SELECT a.assignment_id, a.nexcheck_id, a.asset_id, a.returned_at, l.status_id AS laptop_status_id
                    FROM nexcheck_assignment a
                    INNER JOIN laptop l ON l.asset_id = a.asset_id
                    WHERE a.assignment_id = ?
                    FOR UPDATE
                ');
                $stmtUpA = $pdo->prepare('
                    UPDATE nexcheck_assignment
                    SET returned_at = NOW(), return_condition = ?, returned_by = ?
                    WHERE assignment_id = ? AND nexcheck_id = ? AND returned_at IS NULL
                ');
                $stmtUpL = $pdo->prepare('UPDATE laptop SET status_id = ? WHERE asset_id = ?');
                foreach ($toProcess as $assignId => $condText) {
                    $stmtLock->execute([$assignId]);
                    $row = $stmtLock->fetch(PDO::FETCH_ASSOC);
                    if (!$row || (int)$row['nexcheck_id'] !== $nexcheckId) {
                        throw new RuntimeException('Invalid assignment.');
                    }
                    if ($row['returned_at'] !== null && $row['returned_at'] !== '') {
                        throw new RuntimeException('Assignment #' . $assignId . ' was already returned.');
                    }
                    if ((int)$row['laptop_status_id'] !== NEXCHECK_RETURN_FROM_STATUS) {
                        throw new RuntimeException('Asset #' . (int)$row['asset_id'] . ' is not in checkout (status ' . NEXCHECK_RETURN_FROM_STATUS . ').');
                    }
                    $stmtUpA->execute([$condText, $staffId, $assignId, $nexcheckId]);
                    if ($stmtUpA->rowCount() !== 1) {
                        throw new RuntimeException('Could not update assignment #' . $assignId . '.');
                    }
                    $stmtUpL->execute([NEXCHECK_RETURN_TO_STATUS, (int)$row['asset_id']]);
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
                $stmtItem      = $pdo->prepare('SELECT request_item_id, nexcheck_id FROM nexcheck_request_item WHERE request_item_id = ? FOR UPDATE');
                $stmtHasAssign = $pdo->prepare('SELECT assignment_id FROM nexcheck_assignment WHERE request_item_id = ? FOR UPDATE');
                $stmtLaptop    = $pdo->prepare('SELECT asset_id, status_id FROM laptop WHERE asset_id = ? FOR UPDATE');
                $stmtIns       = $pdo->prepare('INSERT INTO nexcheck_assignment (nexcheck_id, request_item_id, asset_id, assigned_by, assigned_at, checkout_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
                $stmtUp        = $pdo->prepare('UPDATE laptop SET status_id = ? WHERE asset_id = ?');
                foreach ($pairs as $requestItemId => $assetId) {
                    $stmtItem->execute([$requestItemId]);
                    $itemRow = $stmtItem->fetch(PDO::FETCH_ASSOC);
                    if (!$itemRow || (int)$itemRow['nexcheck_id'] !== $nexcheckId) { throw new RuntimeException('Invalid line item.'); }
                    $stmtHasAssign->execute([$requestItemId]);
                    if ($stmtHasAssign->fetch()) { throw new RuntimeException('Line #' . $requestItemId . ' is already assigned.'); }
                    $stmtLaptop->execute([$assetId]);
                    $lap = $stmtLaptop->fetch(PDO::FETCH_ASSOC);
                    if (!$lap || (int)$lap['status_id'] !== NEXCHECK_POOL_STATUS) { throw new RuntimeException('Asset ' . $assetId . ' is not available.'); }
                    $stmtIns->execute([$nexcheckId, $requestItemId, $assetId, $staffId]);
                    $stmtUp->execute([NEXCHECK_ASSIGN_TARGET_STATUS, $assetId]);
                }
                $pdo->commit();
                header('Location: nextItems.php?nexcheck_id=' . $nexcheckId . '&saved=1');
                exit;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
                $form_error = ($e instanceof PDOException && $e->getCode() === '23000')
                    ? 'Duplicate assignment or constraint conflict.'
                    : $e->getMessage();
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

    $stmtI = $pdo->prepare('
        SELECT
            i.request_item_id, i.category, i.quantity,
            a.assignment_id, a.asset_id AS assigned_asset_id, a.assigned_at,
            a.returned_at, a.return_condition,
            l.serial_num AS assigned_serial, l.brand AS assigned_brand, l.model AS assigned_model,
            l.status_id AS laptop_status_id
        FROM nexcheck_request_item i
        LEFT JOIN nexcheck_assignment a ON a.request_item_id = i.request_item_id
        LEFT JOIN laptop l ON l.asset_id = a.asset_id
        WHERE i.nexcheck_id = ?
        ORDER BY i.request_item_id ASC
    ');
    $stmtI->execute([$nexcheckId]);
    $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

    $stmtP = $pdo->prepare('SELECT l.asset_id, l.serial_num, l.brand, l.model, l.category FROM laptop l WHERE l.status_id = ? ORDER BY l.category ASC, l.asset_id DESC');
    $stmtP->execute([NEXCHECK_POOL_STATUS]);
    $pool = $stmtP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $request = null; }

$needCount  = 0; $doneCount = 0; $returnableCount = 0;
foreach ($items as $it) {
    if (empty($it['assignment_id'])) {
        $needCount++;
    } else {
        $doneCount++;
        $hasRet = !empty($it['returned_at']);
        $sid    = isset($it['laptop_status_id']) ? (int)$it['laptop_status_id'] : 0;
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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-light: #3b82f6;
            --primary-dark: #1d4ed8;
            --secondary: #0ea5e9;
            --bg: #f1f5f9;
            --card-bg: #fff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass: #f8fafc;
            --success: #10b981;
            --danger: #dc2626;
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
        .page-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.65rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .request-badge {
            font-size: 0.95rem;
            font-weight: 700;
            padding: 0.2rem 0.65rem;
            background: rgba(37,99,235,0.1);
            border: 1px solid rgba(37,99,235,0.2);
            border-radius: 8px;
            color: var(--primary);
            letter-spacing: 0;
        }
        .page-header p { color: var(--text-muted); margin-top: 0.4rem; font-size: 0.88rem; line-height: 1.5; max-width: 560px; }
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
            font-family: 'Outfit', sans-serif;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-ghost:hover { color: var(--primary); border-color: rgba(37,99,235,0.3); }

        /* ── Banners ── */
        .banner {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            padding: 0.9rem 1.1rem;
            border-radius: 14px;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
            line-height: 1.45;
            font-weight: 500;
        }
        .banner i { font-size: 1.15rem; flex-shrink: 0; margin-top: 0.05rem; }
        .banner-error   { background: rgba(239,68,68,0.08);  border: 1px solid rgba(239,68,68,0.22);  color: #b91c1c; }
        .banner-success { background: rgba(16,185,129,0.1);  border: 1px solid rgba(16,185,129,0.28); color: #047857; }

        /* ── Progress bar ── */
        .progress-wrap {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 1.1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            flex-wrap: wrap;
            box-shadow: 0 2px 10px rgba(15,23,42,0.05);
        }
        .progress-stat { display: flex; flex-direction: column; align-items: center; min-width: 56px; }
        .ps-num {
            font-family: 'Outfit', sans-serif;
            font-size: 1.55rem;
            font-weight: 800;
            line-height: 1;
        }
        .ps-label {
            font-size: 0.67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-top: 0.2rem;
        }
        .ps-num.c-total { color: var(--primary); }
        .ps-num.c-done  { color: var(--success); }
        .ps-num.c-need  { color: var(--warning); }
        .ps-num.c-return { color: #7c3aed; }
        .divider-v { width: 1px; height: 38px; background: var(--card-border); flex-shrink: 0; }
        .progress-bar-wrap { flex: 1; min-width: 160px; display: flex; flex-direction: column; gap: 0.4rem; }
        .progress-bar-track { height: 8px; border-radius: 99px; background: var(--card-border); overflow: hidden; }
        .progress-bar-fill  { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--success), #34d399); transition: width 0.6s cubic-bezier(.4,0,.2,1); }
        .progress-bar-label { font-size: 0.75rem; font-weight: 600; color: var(--text-muted); }

        /* ── Info grid ── */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            align-items: start;
            margin-bottom: 1.25rem;
        }
        @media (max-width: 960px) { .grid-2 { grid-template-columns: 1fr; } }

        /* ── Cards ── */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            overflow: hidden;
        }
        .card-hd {
            padding: 1rem 1.4rem;
            border-bottom: 1px solid var(--card-border);
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.55rem;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        }
        .card-hd i { color: var(--primary); font-size: 1.05rem; }
        .card-hd .hd-extra { margin-left: auto; font-size: 0.75rem; font-weight: 600; color: var(--text-muted); font-family: 'Inter', sans-serif; }
        .card-bd { padding: 1.25rem 1.4rem; }

        /* ── Meta grid ── */
        .meta-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(175px, 1fr)); gap: 0.65rem; }
        .meta-cell {
            background: var(--glass);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.65rem 0.9rem;
        }
        .meta-cell.full { grid-column: 1 / -1; }
        .meta-cell dt { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin-bottom: 0.2rem; }
        .meta-cell dd { font-weight: 600; font-size: 0.88rem; color: var(--text-main); word-break: break-word; }
        .reason-box {
            margin-top: 0.75rem;
            padding: 0.8rem 1rem;
            background: var(--glass);
            border-radius: 12px;
            border: 1px solid var(--card-border);
            font-size: 0.85rem;
            line-height: 1.6;
        }
        .reason-label { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); display: block; margin-bottom: 0.4rem; }

        /* ── Pool card ── */
        .pool-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .pool-pill.ok   { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.25); color: #047857; }
        .pool-pill.warn { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3);  color: #92400e; }
        .pool-preview-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            padding: 0.35rem 0.65rem;
            background: var(--glass);
            border: 1px solid var(--card-border);
            border-radius: 8px;
        }

        /* ── Line items table ── */
        .tbl-header {
            display: grid;
            grid-template-columns: 2.25rem 1.1fr 1.8fr 6rem;
            gap: 1rem;
            padding: 0.6rem 1.4rem;
            background: var(--glass);
            border-bottom: 1px solid var(--card-border);
        }
        .tbl-header span { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); }
        @media (max-width: 700px) { .tbl-header { display: none; } }

        .line-row {
            display: grid;
            grid-template-columns: 2.25rem 1.1fr 1.8fr 6rem;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.4rem;
            border-bottom: 1px solid var(--card-border);
            transition: background 0.15s;
        }
        .line-row:last-child { border-bottom: none; }
        .line-row:hover { background: #fafbff; }
        @media (max-width: 700px) { .line-row { grid-template-columns: 1fr; gap: 0.65rem; } }

        .row-num {
            width: 2rem; height: 2rem;
            border-radius: 50%;
            background: rgba(37,99,235,0.08);
            border: 1.5px solid rgba(37,99,235,0.18);
            color: var(--primary);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        .item-cat-name { font-weight: 700; font-size: 0.9rem; }
        .item-cat-sub  { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.1rem; }

        .assigned-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 10px;
            background: rgba(16,185,129,0.09);
            border: 1px solid rgba(16,185,129,0.28);
            color: #047857;
            font-weight: 700;
            font-size: 0.82rem;
            flex-wrap: wrap;
        }
        .ap-serial { font-weight: 500; opacity: 0.8; font-size: 0.76rem; }

        .asset-select-wrap { width: 100%; max-width: 22rem; }
        .asset-select {
            width: 100%;
            padding: 0.6rem 2rem 0.6rem 0.9rem;
            border-radius: 12px;
            border: 1.5px solid var(--card-border);
            font-family: inherit;
            font-size: 0.86rem;
            font-weight: 500;
            background: #fff;
            color: var(--text-main);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.65rem center;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .asset-select:focus {
            outline: none;
            border-color: rgba(37,99,235,0.5);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .asset-select option:disabled { color: var(--text-muted); }
        .asset-select-hint { font-size: 0.7rem; color: var(--text-muted); margin-top: 0.35rem; display: flex; align-items: center; gap: 0.3rem; }

        /* Status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
        .dot.green { background: var(--success); }
        .dot.amber { background: var(--warning); }

        /* ── Form footer ── */
        .form-footer {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.4rem;
            border-top: 1px solid var(--card-border);
            background: var(--glass);
            flex-wrap: wrap;
        }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.4rem;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #021A54, #1e40af);
            color: #fff;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            font-size: 0.92rem;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(2,26,84,0.25);
            transition: all 0.2s;
        }
        .btn-primary:hover:not(:disabled) { filter: brightness(1.08); transform: translateY(-1px); }
        .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; box-shadow: none; transform: none; }
        .footer-note { font-size: 0.8rem; color: var(--text-muted); line-height: 1.5; }

        #nexcheck-return { scroll-margin-top: 1rem; }
        .return-row {
            display: grid;
            grid-template-columns: 1fr minmax(180px, 1.2fr);
            gap: 1rem;
            align-items: start;
            padding: 1rem 1.4rem;
            border-bottom: 1px solid var(--card-border);
        }
        .return-row:last-of-type { border-bottom: none; }
        @media (max-width: 700px) { .return-row { grid-template-columns: 1fr; } }
        .return-asset-label { font-weight: 700; font-size: 0.88rem; }
        .return-asset-sub { font-size: 0.76rem; color: var(--text-muted); margin-top: 0.25rem; }
        .cond-input {
            width: 100%;
            min-height: 4rem;
            padding: 0.65rem 0.85rem;
            border-radius: 10px;
            border: 1.5px solid var(--card-border);
            font-family: inherit;
            font-size: 0.84rem;
            resize: vertical;
        }
        .cond-input:focus {
            outline: none;
            border-color: rgba(37,99,235,0.45);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .status-badge.returned { color: #0369a1; }
        .status-badge.checkout { color: #7c3aed; }
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
            <p>Match each line to a pooled laptop (status <?= NEXCHECK_POOL_STATUS ?>). Saved assets are moved to Checkout status (<?= NEXCHECK_ASSIGN_TARGET_STATUS ?>).</p>
        </div>
        <a class="btn-ghost" href="nextCheckout.php"><i class="ri-arrow-left-line"></i> All requests</a>
    </header>

    <!-- Banners -->
    <?php if ($success): ?>
        <div class="banner banner-success">
            <i class="ri-checkbox-circle-line"></i>
            <div><strong>Assignments saved.</strong> The laptops have been moved to Checkout status.</div>
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
            <div><strong>Return recorded.</strong> Asset(s) are back in pool (status <?= NEXCHECK_RETURN_TO_STATUS ?>).</div>
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
        <div class="progress-stat" title="Assigned laptops still in checkout — not yet returned to pool">
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
                        <span class="pool-pill ok"><i class="ri-checkbox-circle-line"></i> <?= count($pool) ?> laptop<?= count($pool) !== 1 ? 's' : '' ?> available</span>
                    <?php else: ?>
                        <span class="pool-pill warn"><i class="ri-alert-line"></i> No laptops in pool</span>
                    <?php endif; ?>
                </div>
                <p style="font-size:0.82rem;color:var(--text-muted);line-height:1.55;margin-bottom:0.85rem">
                    Only <strong>Active (nextcheck)</strong> laptops appear in the assignment dropdowns below.
                    <?php if (count($pool) === 0): ?>
                        <a href="nextAdd.php" style="color:var(--primary);font-weight:700">Add stock →</a>
                    <?php endif; ?>
                </p>
                <?php if (count($pool) > 0): ?>
                    <div style="font-size:0.67rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:0.5rem">Pool preview</div>
                    <div style="display:flex;flex-direction:column;gap:0.35rem;max-height:155px;overflow-y:auto">
                        <?php foreach (array_slice($pool, 0, 7) as $p): ?>
                        <div class="pool-preview-item">
                            <i class="ri-laptop-line" style="color:var(--primary);font-size:0.9rem;flex-shrink:0"></i>
                            <span style="font-weight:700">#<?= (int)$p['asset_id'] ?></span>
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
                <span>Laptop assignment</span>
                <span>Status</span>
            </div>

            <!-- Rows -->
            <?php foreach ($items as $idx => $it):
                $rid        = (int)$it['request_item_id'];
                $hasA       = !empty($it['assignment_id']);
                $assignId   = $hasA ? (int)$it['assignment_id'] : 0;
                $brandModel = trim((string)$it['assigned_brand'] . ' ' . (string)$it['assigned_model']);
                $lapSid     = $hasA ? (int)($it['laptop_status_id'] ?? 0) : 0;
                $isReturned = $hasA && !empty($it['returned_at']);
            ?>
            <div class="line-row">
                <div class="row-num"><?= $idx + 1 ?></div>

                <div>
                    <div class="item-cat-name">
                        <i class="ri-laptop-line" style="color:var(--primary);margin-right:0.3rem"></i><?= htmlspecialchars((string)$it['category']) ?>
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
                            <select class="asset-select" name="assign[<?= $rid ?>]" id="assign-sel-<?= $rid ?>" aria-label="Select laptop for line <?= $idx + 1 ?>">
                                <option value="">— Select laptop —</option>
                                <?php foreach ($pool as $p):
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
                                Assets: status <?= NEXCHECK_POOL_STATUS ?> (<?= count($pool) ?> available)
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <?php if ($isReturned): ?>
                        <span class="status-badge returned"><span class="dot" style="background:#0ea5e9"></span>Returned</span>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.35rem;max-width:14rem"><?= htmlspecialchars(mb_substr((string)($it['return_condition'] ?? ''), 0, 80)) ?><?= mb_strlen((string)($it['return_condition'] ?? '')) > 80 ? '…' : '' ?></div>
                    <?php elseif ($hasA && $lapSid === NEXCHECK_RETURN_FROM_STATUS): ?>
                        <span class="status-badge checkout"><span class="dot" style="background:#8b5cf6"></span>Checkout (<?= NEXCHECK_RETURN_FROM_STATUS ?>)</span>
                    <?php elseif ($hasA): ?>
                        <span class="status-badge" style="color:#047857"><span class="dot green"></span>Assigned</span>
                    <?php else: ?>
                        <span class="status-badge" style="color:#92400e"><span class="dot amber"></span>Pending</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Footer action -->
            <?php if ($allDone): ?>
                <div class="form-footer">
                    <span style="display:inline-flex;align-items:center;gap:0.5rem;font-size:0.88rem;font-weight:700;color:#047857">
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
                            <strong style="color:#b45309"><i class="ri-alert-line"></i> No laptops in pool.</strong>
                            <a href="nextAdd.php" style="color:var(--primary);font-weight:700">Add items first →</a>
                        <?php else: ?>
                            Choose a laptop for each pending row, then save. Only rows with a selection are processed.
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
            Items still in status <strong><?= NEXCHECK_RETURN_FROM_STATUS ?></strong> (checkout). Describe condition, then save — laptops go back to pool (<strong><?= NEXCHECK_RETURN_TO_STATUS ?></strong>).
        </p>
        <form method="post" action="">
            <input type="hidden" name="save_returns" value="1">
            <input type="hidden" name="nexcheck_id" value="<?= (int)$nexcheckId ?>">
            <?php foreach ($items as $it):
                if (empty($it['assignment_id']) || !empty($it['returned_at'])) {
                    continue;
                }
                if ((int)($it['laptop_status_id'] ?? 0) !== NEXCHECK_RETURN_FROM_STATUS) {
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
                    <textarea class="cond-input" id="rc-<?= $aid ?>" name="return_cond[<?= $aid ?>]" placeholder="e.g. Good, all accessories present; or note any damage…" maxlength="500"></textarea>
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
})();
</script>
</body>
</html>