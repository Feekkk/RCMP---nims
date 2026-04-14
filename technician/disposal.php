<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

const DISPOSED_STATUS_ID = 7;

// AJAX lookup for UI (fetch real asset info by asset_id)
if (isset($_GET['lookup_asset_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim((string)$_GET['lookup_asset_id']);
    if ($id === '' || !ctype_digit($id)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid asset id']);
        exit;
    }
    try {
        $stmt = db()->prepare("
            SELECT l.asset_id, l.serial_num, l.brand, l.model, s.name AS status_name
            FROM laptop l
            JOIN status s ON s.status_id = l.status_id
            WHERE l.asset_id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Asset not found']);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'asset' => [
                'asset_id' => (int)$row['asset_id'],
                'serial' => (string)($row['serial_num'] ?? ''),
                'brand' => (string)($row['brand'] ?? ''),
                'model' => (string)($row['model'] ?? ''),
                'status' => (string)($row['status_name'] ?? '—'),
            ],
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Lookup failed']);
        exit;
    }
}

$suggest_q = isset($_GET['suggest_q']) ? trim((string)$_GET['suggest_q']) : '';
if ($suggest_q !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!preg_match('/^\d{1,20}$/', $suggest_q)) {
        echo json_encode(['ok' => true, 'items' => []]);
        exit;
    }
    try {
        $stmt = db()->prepare("
            SELECT l.asset_id, l.serial_num, l.brand, l.model, s.name AS status_name
            FROM laptop l
            JOIN status s ON s.status_id = l.status_id
            WHERE CAST(l.asset_id AS CHAR) LIKE CONCAT(?, '%')
            ORDER BY l.asset_id DESC
            LIMIT 8
        ");
        $stmt->execute([$suggest_q]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'asset_id' => (int)$r['asset_id'],
                'serial' => (string)($r['serial_num'] ?? ''),
                'brand' => (string)($r['brand'] ?? ''),
                'model' => (string)($r['model'] ?? ''),
                'status' => (string)($r['status_name'] ?? '—'),
            ];
        }
        echo json_encode(['ok' => true, 'items' => $items]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Suggest failed']);
        exit;
    }
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestedBy = trim((string)($_SESSION['staff_id'] ?? ''));
    $disposalDate = trim((string)($_POST['disposal_date'] ?? ''));
    $disposalTime = trim((string)($_POST['disposal_time'] ?? ''));
    $disposalRemarks = trim((string)($_POST['disposal_remarks'] ?? ''));
    $assetIdsJson = (string)($_POST['asset_ids'] ?? '[]');

    $assetIds = json_decode($assetIdsJson, true);
    if (!is_array($assetIds)) $assetIds = [];

    $assetIds = array_values(array_unique(array_filter(
        array_map(static function ($v): string {
            return (is_string($v) || is_int($v)) ? trim((string)$v) : '';
        }, $assetIds),
        static function ($v): bool {
            return $v !== '' && ctype_digit($v);
        }
    )));

    if ($requestedBy === '') {
        $error_message = 'Technician not found in session. Please log in again.';
    } elseif ($disposalDate === '') {
        $error_message = 'Disposal date is required.';
    } elseif (count($assetIds) === 0) {
        $error_message = 'Please add at least one Asset ID.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            // Validate assets exist and lock them
            $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
            $stmtLock = $pdo->prepare("
                SELECT asset_id
                FROM laptop
                WHERE asset_id IN ($placeholders)
                FOR UPDATE
            ");
            $stmtLock->execute(array_map('intval', $assetIds));
            $found = $stmtLock->fetchAll(PDO::FETCH_COLUMN, 0);
            $foundSet = array_flip(array_map('strval', $found));
            $missing = array_values(array_filter($assetIds, static function ($id) use ($foundSet): bool {
                return !isset($foundSet[$id]);
            }));
            if ($missing) {
                throw new RuntimeException('Asset not found: ' . implode(', ', $missing));
            }

            $stmtDisposal = $pdo->prepare("
                INSERT INTO disposal
                    (requested_by, disposal_date, disposal_time, disposal_remarks)
                VALUES
                    (:requested_by, :disposal_date, :disposal_time, :disposal_remarks)
            ");
            $stmtDisposal->execute([
                ':requested_by' => $requestedBy,
                ':disposal_date' => $disposalDate,
                ':disposal_time' => ($disposalTime !== '' ? $disposalTime : null),
                ':disposal_remarks' => ($disposalRemarks !== '' ? $disposalRemarks : null),
            ]);
            $disposalId = (int)$pdo->lastInsertId();

            $stmtItem = $pdo->prepare("
                INSERT INTO disposal_item
                    (disposal_id, asset_id, asset_type, item_remarks)
                VALUES
                    (:disposal_id, :asset_id, 'laptop', NULL)
            ");
            foreach ($assetIds as $id) {
                $stmtItem->execute([
                    ':disposal_id' => $disposalId,
                    ':asset_id' => (int)$id,
                ]);
            }

            // Set assets to Disposed
            $stmtUpdate = $pdo->prepare("
                UPDATE laptop
                SET status_id = ?
                WHERE asset_id IN ($placeholders)
            ");
            $stmtUpdate->execute(array_merge([DISPOSED_STATUS_ID], array_map('intval', $assetIds)));

            $pdo->commit();
            header('Location: laptop.php?status_id=' . (int)DISPOSED_STATUS_ID);
            exit;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disposal Form - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root{
            --primary:#2563eb;
            --secondary:#0ea5e9;
            --success:#10b981;
            --danger:#ef4444;
            --warning:#f59e0b;
            --bg:#f1f5f9;
            --card-bg:#ffffff;
            --card-border:#e2e8f0;
            --text-main:#0f172a;
            --text-muted:#64748b;
            --glass-panel:#f8fafc;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{
            font-family:'Inter',sans-serif;
            background:var(--bg);
            color:var(--text-main);
            min-height:100vh;
            display:flex;
            overflow-x:hidden;
        }
        .main-content{
            margin-left:280px;
            flex:1;
            padding:2rem 2.5rem;
            max-width:calc(100vw - 280px);
        }
        .page-header{
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap:1rem;
            padding-bottom:1.25rem;
            border-bottom:1px solid var(--card-border);
            margin-bottom:1.25rem;
        }
        .title h1{
            font-family:'Outfit',sans-serif;
            font-size:2rem;
            letter-spacing:-0.4px;
            display:flex;
            align-items:center;
            gap:0.75rem;
        }
        .title h1 i{color:var(--warning)}
        .title p{color:var(--text-muted);margin-top:0.35rem}

        .layout{
            display:grid;
            grid-template-columns: 1fr;
            gap:1.25rem;
            align-items:start;
        }

        .card{
            background:var(--card-bg);
            border:1px solid var(--card-border);
            border-radius:18px;
            box-shadow:0 10px 25px rgba(15,23,42,0.05);
        }
        .card-hd{
            padding:1.1rem 1.25rem;
            border-bottom:1px solid var(--card-border);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:1rem;
        }
        .card-hd h2{
            font-family:'Outfit',sans-serif;
            font-size:1.05rem;
            font-weight:800;
            display:flex;
            align-items:center;
            gap:0.6rem;
        }
        .card-hd h2 i{color:var(--primary)}
        .card-bd{padding:1.25rem}

        .search{
            display:flex;
            gap:0.75rem;
            flex-wrap:wrap;
            margin-bottom:0.75rem;
        }
        .search-box{
            flex:1;
            min-width:240px;
            position:relative;
        }
        .search-box i{
            position:absolute;
            left:1rem;
            top:50%;
            transform:translateY(-50%);
            color:var(--text-muted);
        }
        .input{
            width:100%;
            background:var(--glass-panel);
            border:1px solid var(--card-border);
            border-radius:14px;
            padding:0.85rem 1rem 0.85rem 2.75rem;
            outline:none;
            transition:all .2s ease;
        }
        .input:focus{
            background:#fff;
            border-color:rgba(37,99,235,0.35);
            box-shadow:0 0 0 4px rgba(37,99,235,0.10);
        }

        .suggest {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 8px);
            background: #fff;
            border: 1px solid var(--card-border);
            border-radius: 16px;
            box-shadow: 0 18px 40px rgba(15,23,42,0.12);
            overflow: hidden;
            z-index: 60;
            display: none;
        }
        .suggest.show { display: block; }
        .suggest-item {
            padding: 0.75rem 0.85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            cursor: pointer;
            border-top: 1px solid rgba(226,232,240,0.7);
        }
        .suggest-item:first-child { border-top: none; }
        .suggest-item:hover { background: rgba(37,99,235,0.06); }
        .suggest-left { min-width: 0; }
        .suggest-title { font-weight: 900; font-family: 'Outfit', sans-serif; }
        .suggest-sub { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 520px; }
        .suggest-pill {
            font-size: 0.7rem;
            font-weight: 900;
            letter-spacing: 0.45px;
            text-transform: uppercase;
            padding: 0.22rem 0.55rem;
            border-radius: 999px;
            border: 1px solid var(--card-border);
            background: var(--glass-panel);
            color: var(--text-muted);
            white-space: nowrap;
        }
        .btn{
            border:none;
            border-radius:14px;
            padding:0.85rem 1rem;
            font-weight:800;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:0.55rem;
            transition:all .2s ease;
            user-select:none;
        }
        .btn-ghost{
            background:transparent;
            border:1px solid var(--card-border);
            color:var(--text-muted);
        }
        .btn-ghost:hover{color:var(--primary);border-color:rgba(37,99,235,0.25);background:rgba(37,99,235,0.04)}
        .btn-primary{
            background:var(--primary);
            color:#fff;
            box-shadow:0 12px 24px rgba(37,99,235,0.20);
        }
        .btn-primary:hover{filter:brightness(0.98)}
        .btn-danger{
            background:rgba(239,68,68,0.10);
            color:var(--danger);
            border:1px solid rgba(239,68,68,0.25);
        }
        .btn-danger:hover{background:rgba(239,68,68,0.14)}

        .pill{
            font-size:0.72rem;
            font-weight:900;
            letter-spacing:0.5px;
            text-transform:uppercase;
            padding:0.28rem 0.6rem;
            border-radius:999px;
            border:1px solid transparent;
            white-space:nowrap;
        }
        .pill.ok{background:rgba(16,185,129,0.12);color:var(--success);border-color:rgba(16,185,129,0.25)}
        .pill.warn{background:rgba(245,158,11,0.12);color:var(--warning);border-color:rgba(245,158,11,0.25)}
        .pill.bad{background:rgba(239,68,68,0.12);color:var(--danger);border-color:rgba(239,68,68,0.25)}
        .pill.muted{background:rgba(100,116,139,0.12);color:var(--text-muted);border-color:rgba(100,116,139,0.25)}

        .selected-list{display:flex;flex-direction:column;gap:0.75rem}
        .selected-item{
            border:1px solid var(--card-border);
            border-radius:16px;
            padding:0.9rem 0.95rem;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:0.75rem;
            background:#fff;
        }
        .selected-left{min-width:0}
        .selected-title{font-weight:900;font-family:'Outfit',sans-serif}
        .selected-sub{color:var(--text-muted);font-size:0.85rem;margin-top:0.15rem}
        .icon-btn{
            width:38px;height:38px;border-radius:12px;
            display:inline-flex;align-items:center;justify-content:center;
            border:1px solid var(--card-border);
            background:var(--glass-panel);
            cursor:pointer;
            color:var(--text-muted);
            transition:all .2s ease;
            flex-shrink:0;
        }
        .icon-btn:hover{background:rgba(239,68,68,0.10);border-color:rgba(239,68,68,0.18);color:var(--danger)}

        .summary{
            margin-top:0.9rem;
            border-top:1px dashed var(--card-border);
            padding-top:0.9rem;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:1rem;
        }
        .summary strong{font-family:'Outfit',sans-serif}
        .muted{color:var(--text-muted)}

        .notice{
            background: rgba(37,99,235,0.08);
            border: 1px solid rgba(37,99,235,0.18);
            border-radius: 14px;
            padding: 0.85rem 1rem;
            color: var(--text-main);
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .notice i{color: var(--primary); margin-top: 0.15rem;}

        .alert{
            background: rgba(239,68,68,0.10);
            border: 1px solid rgba(239,68,68,0.22);
            border-radius: 14px;
            padding: 0.85rem 1rem;
            color: var(--danger);
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .form-grid{
            display:grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 0.9rem;
            margin-bottom: 1rem;
        }
        .field label{
            display:block;
            font-size: 0.75rem;
            font-weight: 900;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin: 0 0 0.45rem 0.1rem;
        }
        .input-plain{
            width:100%;
            background:var(--glass-panel);
            border:1px solid var(--card-border);
            border-radius:14px;
            padding:0.85rem 1rem;
            outline:none;
            transition:all .2s ease;
        }
        .input-plain:focus{
            background:#fff;
            border-color:rgba(37,99,235,0.35);
            box-shadow:0 0 0 4px rgba(37,99,235,0.10);
        }

        .form{
            margin-top:1rem;
            display:grid;
            grid-template-columns:1fr;
            gap:0.9rem;
        }
        .textarea{
            width:100%;
            min-height:110px;
            resize:vertical;
            background:var(--glass-panel);
            border:1px solid var(--card-border);
            border-radius:14px;
            padding:0.85rem 1rem;
            outline:none;
            font-family:'Inter',sans-serif;
        }
        .textarea:focus{
            background:#fff;
            border-color:rgba(37,99,235,0.35);
            box-shadow:0 0 0 4px rgba(37,99,235,0.10);
        }

        @media (max-width: 1100px){
            .main-content{padding:1.25rem 1.25rem}
        }
        @media (max-width: 900px){
            .main-content{margin-left:0;max-width:100vw}
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <div class="title">
                <h1><i class="ri-delete-bin-6-line"></i> Disposal Form</h1>
                <p>Select multiple assets like a checkout cart, then proceed to disposal details.</p>
            </div>
            <button class="btn btn-ghost" type="button" onclick="history.back()">
                <i class="ri-arrow-left-line"></i> Back
            </button>
        </header>

        <div class="layout">
            <section class="card">
                <div class="card-hd">
                    <h2><i class="ri-delete-bin-6-line"></i> Disposal form</h2>
                    <div class="muted" style="font-weight:700;font-size:0.9rem">
                        Selected: <span id="selectedCount">0</span>
                    </div>
                </div>
                <div class="card-bd">
                    <?php if (!empty($error_message)): ?>
                        <div class="alert"><?= htmlspecialchars($error_message) ?></div>
                    <?php endif; ?>

                    <div class="notice">
                        <i class="ri-information-line"></i>
                        <div>
                            <div style="font-weight:900;font-family:'Outfit',sans-serif">Disposal process</div>
                            <div class="muted" style="margin-top:0.1rem">Type Asset ID to see suggestions, click one, then press Add.</div>
                        </div>
                    </div>

                    <form method="post" action="">
                        <div class="form-grid">
                            <div class="field">
                                <label for="disposal_date">Disposal date</label>
                                <input id="disposal_date" name="disposal_date" class="input-plain" type="date" required
                                       value="<?= htmlspecialchars((string)($_POST['disposal_date'] ?? date('Y-m-d'))) ?>">
                            </div>
                            <div class="field">
                                <label for="disposal_time">Disposal time (optional)</label>
                                <input id="disposal_time" name="disposal_time" class="input-plain" type="time"
                                       value="<?= htmlspecialchars((string)($_POST['disposal_time'] ?? '')) ?>">
                            </div>
                        </div>

                    <div class="search">
                        <div class="search-box">
                            <i class="ri-barcode-line"></i>
                            <input id="assetIdInput" class="input" type="text" inputmode="numeric" placeholder="Enter Asset ID (e.g. 22260001) then press Add">
                            <div class="suggest" id="suggestBox" role="listbox" aria-label="Asset suggestions"></div>
                        </div>
                        <button class="btn btn-primary" type="button" id="addByIdBtn">
                            <i class="ri-add-line"></i> Add
                        </button>
                        <button class="btn btn-danger" type="button" id="clearSelected">
                            <i class="ri-close-circle-line"></i> Clear selected
                        </button>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-hd">
                    <h2><i class="ri-list-check-2"></i> Selected assets list</h2>
                    <div class="muted" style="font-weight:700;font-size:0.9rem">
                        Items: <span id="listCount">0</span>
                    </div>
                </div>
                <div class="card-bd">
                    <div id="listEmpty" class="muted" style="padding:0.25rem 0.1rem">
                        No assets selected yet. Enter an Asset ID above or tick items from the grid.
                    </div>
                    <div class="selected-list" id="selectedList" style="display:none"></div>

                    <div class="summary">
                        <div>
                            <div class="muted">Ready to proceed</div>
                            <strong><span id="listCount2">0</span> asset(s)</strong>
                        </div>
                        <button class="btn btn-primary" type="submit" id="proceedBtn" disabled>
                            <i class="ri-check-line"></i> Submit disposal
                        </button>
                    </div>

                    <div class="form">
                        <div>
                            <div class="muted" style="font-weight:800;letter-spacing:0.5px;text-transform:uppercase;font-size:0.75rem;margin-bottom:0.45rem">
                                Disposal notes
                            </div>
                            <textarea class="textarea" name="disposal_remarks" placeholder="Reason, vendor, reference number, notes..."><?= htmlspecialchars((string)($_POST['disposal_remarks'] ?? '')) ?></textarea>
                        </div>
                    </div>
                </div>
            </section>
            <input type="hidden" name="asset_ids" id="asset_ids" value="<?= htmlspecialchars((string)($_POST['asset_ids'] ?? '[]')) ?>">
            </form>
        </div>
    </main>

    <script>
        function toggleDropdown(element, event) {
            event.preventDefault();
            const group = element.closest('.nav-group');
            const dropdown = group?.querySelector('.nav-dropdown');
            element.classList.toggle('open');
            dropdown?.classList.toggle('show');
        }

        const assetIdInput = document.getElementById('assetIdInput');
        const addByIdBtn = document.getElementById('addByIdBtn');
        const clearSelected = document.getElementById('clearSelected');
        const proceedBtn = document.getElementById('proceedBtn');
        const hiddenAssetIds = document.getElementById('asset_ids');
        const selectedCount = document.getElementById('selectedCount');
        const listCount = document.getElementById('listCount');
        const listCount2 = document.getElementById('listCount2');
        const listEmpty = document.getElementById('listEmpty');
        const selectedList = document.getElementById('selectedList');

        const selected = new Map(); // asset_id -> data

        function pillClass(status) {
            if (['Active','Reserved','Non-active'].includes(status)) return 'ok';
            if (['Maintenance'].includes(status)) return 'warn';
            if (['Faulty','Lost','Disposed'].includes(status)) return 'bad';
            return 'muted';
        }

        function renderSelectedList() {
            const items = Array.from(selected.values());
            selectedCount.textContent = String(items.length);
            listCount.textContent = String(items.length);
            listCount2.textContent = String(items.length);
            proceedBtn.disabled = items.length === 0;
            if (hiddenAssetIds) hiddenAssetIds.value = JSON.stringify(items.map(x => String(x.asset_id)));

            if (items.length === 0) {
                listEmpty.style.display = '';
                selectedList.style.display = 'none';
                selectedList.innerHTML = '';
                return;
            }

            listEmpty.style.display = 'none';
            selectedList.style.display = 'flex';
            selectedList.innerHTML = items.map(it => {
                const title = `${it.brand} ${it.model}`.trim();
                return `
                    <div class="selected-item" data-asset-id="${it.asset_id}">
                        <div class="selected-left">
                            <div class="selected-title">${escapeHtml(title)}</div>
                            <div class="selected-sub">Asset ID: <strong>${escapeHtml(String(it.asset_id))}</strong> · SN: ${escapeHtml(it.serial)} · <span class="pill ${pillClass(it.status)}" style="margin-left:6px">${escapeHtml(it.status)}</span></div>
                        </div>
                        <button class="icon-btn" type="button" title="Remove" data-remove="${it.asset_id}">
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                `;
            }).join('');
        }

        function escapeHtml(s) {
            return String(s)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        selectedList.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-remove]');
            if (!btn) return;
            const id = btn.getAttribute('data-remove');
            if (!id) return;
            selected.delete(id);
            renderSelectedList();
        });

        clearSelected.addEventListener('click', () => {
            selected.clear();
            renderSelectedList();
        });

        proceedBtn.addEventListener('click', () => {
            // submit handled by HTML form
        });

        async function addByAssetId(assetId) {
            const id = String(assetId || '').trim();
            if (!id) return;
            if (!ctypeDigit(id)) {
                alert('Please enter a valid numeric Asset ID.');
                return;
            }
            const data = await lookupAsset(id);
            if (!data) return;
            selected.set(id, {
                asset_id: id,
                serial: data.serial || '—',
                brand: data.brand || '',
                model: data.model || '',
                status: data.status || '—',
            });
            assetIdInput.value = '';
            renderSelectedList();
        }

        function ctypeDigit(s) {
            return /^[0-9]+$/.test(String(s));
        }

        async function lookupAsset(id) {
            try {
                const res = await fetch(`disposal.php?lookup_asset_id=${encodeURIComponent(id)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store'
                });
                const data = await res.json();
                if (!data?.ok) {
                    alert(data?.error || 'Asset not found.');
                    return null;
                }
                return data.asset || null;
            } catch (e) {
                alert('Lookup failed.');
                return null;
            }
        }

        function renderSuggest(items) {
            if (!suggestBox) return;
            if (!items || items.length === 0) {
                suggestBox.classList.remove('show');
                suggestBox.innerHTML = '';
                return;
            }
            suggestBox.innerHTML = items.map(it => {
                const title = `${it.asset_id} · ${(it.brand || '').trim()} ${(it.model || '').trim()}`.trim();
                const sub = `SN: ${it.serial || '—'}`;
                return `
                    <div class="suggest-item" role="option" data-asset-id="${escapeHtml(String(it.asset_id))}">
                        <div class="suggest-left">
                            <div class="suggest-title">${escapeHtml(title)}</div>
                            <div class="suggest-sub">${escapeHtml(sub)}</div>
                        </div>
                        <span class="suggest-pill">${escapeHtml(it.status || '—')}</span>
                    </div>
                `;
            }).join('');
            suggestBox.classList.add('show');
        }

        let suggestTimer = null;
        async function fetchSuggest(term) {
            const t = String(term || '').trim();
            if (!ctypeDigit(t)) {
                renderSuggest([]);
                return;
            }
            try {
                const res = await fetch(`disposal.php?suggest_q=${encodeURIComponent(t)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store'
                });
                const data = await res.json();
                if (!data?.ok) {
                    renderSuggest([]);
                    return;
                }
                renderSuggest(data.items || []);
            } catch (e) {
                renderSuggest([]);
            }
        }

        addByIdBtn.addEventListener('click', () => addByAssetId(assetIdInput.value));
        assetIdInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                addByAssetId(assetIdInput.value);
            }
            if (e.key === 'Escape') {
                renderSuggest([]);
            }
        });

        const suggestBox = document.getElementById('suggestBox');
        assetIdInput.addEventListener('input', () => {
            if (suggestTimer) window.clearTimeout(suggestTimer);
            suggestTimer = window.setTimeout(() => fetchSuggest(assetIdInput.value), 160);
        });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.search-box')) renderSuggest([]);
        });
        suggestBox?.addEventListener('click', (e) => {
            const row = e.target.closest('[data-asset-id]');
            const id = row?.getAttribute('data-asset-id');
            if (!id) return;
            assetIdInput.value = id;
            renderSuggest([]);
            assetIdInput.focus();
        });

        // Restore selections after failed submit
        try {
            const raw = hiddenAssetIds?.value || '[]';
            const ids = JSON.parse(raw);
            if (Array.isArray(ids)) {
                ids.slice(0, 50).forEach(async (id) => {
                    const s = String(id || '').trim();
                    if (!ctypeDigit(s)) return;
                    const data = await lookupAsset(s);
                    if (!data) return;
                    selected.set(s, {
                        asset_id: s,
                        serial: data.serial || '—',
                        brand: data.brand || '',
                        model: data.model || '',
                        status: data.status || '—',
                    });
                    renderSelectedList();
                });
            }
        } catch (e) {}

        renderSelectedList();
    </script>
</body>
</html>

