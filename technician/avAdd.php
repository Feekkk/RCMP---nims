<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

const AV_STATUS_IDS = [1, 2, 3, 5, 6, 7, 8];

/** AV asset_id = class (88) × 100000 + 2-digit year × 1000 + sequence 001–999 */
const AV_ASSET_ID_CLASS = 88;

/** Deployment building dropdown — adjust list to match your campus. */
const BUILDING_OPTIONS = [
    'AVICENNA',
    'AL-ZAHRAWI',
    'AL-RAZI',
    'IBN-KHALDUN',
    'IBN-KHALDUN-B',
];

function av_asset_id_year_bounds(int $twoDigitYear): array
{
    $base = AV_ASSET_ID_CLASS * 100000 + $twoDigitYear * 1000;
    return [$base + 1, $base + 999];
}

function next_av_asset_id(PDO $pdo): ?int
{
    $yy = (int) date('y');
    [$lo, $hi] = av_asset_id_year_bounds($yy);
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(asset_id), 0) FROM av WHERE asset_id BETWEEN :lo AND :hi');
    $stmt->execute([':lo' => $lo, ':hi' => $hi]);
    $max = (int) $stmt->fetchColumn();
    $next = $max < $lo ? $lo : $max + 1;
    return $next <= $hi ? $next : null;
}

function av_parse_asset_id_string(?string $s): int
{
    if ($s === null) {
        return 0;
    }
    $s = trim($s);
    if ($s === '') {
        return 0;
    }
    $digits = preg_replace('/\D/', '', $s);
    return $digits !== '' ? (int) $digits : 0;
}

$success_message = '';
$error_message = '';

$next_asset_id = null;
$status_options = [];

try {
    $pdo = db();
    $status_options = $pdo->query(
        'SELECT status_id, name FROM status WHERE status_id IN (' . implode(',', array_map('intval', AV_STATUS_IDS)) . ') ORDER BY status_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $next_asset_id = next_av_asset_id($pdo);
    if ($next_asset_id === null) {
        $error_message = 'AV asset ID sequence is full for this calendar year (maximum 999 per year).';
    }
} catch (Throwable $e) {
    $error_message = 'Database unavailable: ' . htmlspecialchars($e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error_message === '') {
        $pdo = db();
        $str = static function (string $k): ?string { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : null; };
        $int = static function (string $k): int { return isset($_POST[$k]) && $_POST[$k] !== '' ? (int)$_POST[$k] : 0; };
        $date = static function (string $k): ?string { return isset($_POST[$k]) && $_POST[$k] !== '' ? trim((string)$_POST[$k]) : null; };
        $dec = static function (string $k): ?float { return isset($_POST[$k]) && $_POST[$k] !== '' ? (float)$_POST[$k] : null; };

        $asset_id_old = $str('asset_id_old');
        if ($asset_id_old !== null && $asset_id_old === '') $asset_id_old = null;

        $category = $str('category') ?? '';
        $brand = $str('brand');
        $model = $str('model');
        $serial_num = $str('serial_num');
        $status_id = $int('status_id');

        $po_date = $date('po_date');
        $po_num = $str('po_num');
        $do_date = $date('do_date');
        $do_num = $str('do_num');
        $invoice_date = $date('invoice_date');
        $invoice_num = $str('invoice_num');
        $purchase_cost = $dec('purchase_cost');
        $remarks = $str('remarks');
        if ($remarks !== null && $remarks === '') $remarks = null;

        $deploy_building = $str('deployment_building');
        $deploy_level = $str('deployment_level');
        $deploy_zone = $str('deployment_zone');
        $deploy_date = $date('deployment_date');
        $deploy_remarks = $str('deployment_remarks');
        if ($deploy_remarks !== null && $deploy_remarks === '') $deploy_remarks = null;

        if ($serial_num === null || $serial_num === '' || $status_id < 1) {
            $error_message = 'Serial number and Status are required.';
        } elseif ($category === '') {
            $error_message = 'Category is required.';
        } elseif (!in_array($status_id, AV_STATUS_IDS, true)) {
            $error_message = 'Invalid status for AV assets.';
        } elseif ($status_id === 3) {
            if ($deploy_building === null || $deploy_building === '' || $deploy_level === null || $deploy_level === '' || $deploy_zone === null || $deploy_zone === '' || !$deploy_date) {
                $error_message = 'Deploy status requires building, level, zone, and deployment date.';
            }
        }

        if ($error_message === '') {
            try {
                $pdo->beginTransaction();
                $asset_id = next_av_asset_id($pdo);
                if ($asset_id === null) {
                    $pdo->rollBack();
                    $error_message = 'AV asset ID sequence is full for this calendar year (maximum 999 per year).';
                } else {
                $stmt = $pdo->prepare('
                    INSERT INTO av (
                        asset_id, asset_id_old, category, brand, model, serial_num,
                        status_id,
                        PO_DATE, PO_NUM, DO_DATE, DO_NUM,
                        INVOICE_DATE, INVOICE_NUM, PURCHASE_COST, remarks
                    ) VALUES (
                        :asset_id, :asset_id_old, :category, :brand, :model, :serial_num,
                        :status_id,
                        :po_date, :po_num, :do_date, :do_num,
                        :invoice_date, :invoice_num, :purchase_cost, :remarks
                    )
                ');
                $stmt->execute([
                    ':asset_id' => $asset_id,
                    ':asset_id_old' => $asset_id_old,
                    ':category' => $category,
                    ':brand' => $brand,
                    ':model' => $model,
                    ':serial_num' => $serial_num,
                    ':status_id' => $status_id,
                    ':po_date' => $po_date,
                    ':po_num' => $po_num,
                    ':do_date' => $do_date,
                    ':do_num' => $do_num,
                    ':invoice_date' => $invoice_date,
                    ':invoice_num' => $invoice_num,
                    ':purchase_cost' => $purchase_cost,
                    ':remarks' => $remarks,
                ]);

                if ($status_id === 3) {
                    $stmtD = $pdo->prepare('
                        INSERT INTO av_deployment (
                            asset_id, building, level, zone,
                            deployment_date, deployment_remarks, staff_id
                        ) VALUES (
                            :asset_id, :building, :level, :zone,
                            :deployment_date, :deployment_remarks, :staff_id
                        )
                    ');
                    $stmtD->execute([
                        ':asset_id' => $asset_id,
                        ':building' => (string)$deploy_building,
                        ':level' => (string)$deploy_level,
                        ':zone' => (string)$deploy_zone,
                        ':deployment_date' => $deploy_date,
                        ':deployment_remarks' => $deploy_remarks,
                        ':staff_id' => (string)$_SESSION['staff_id'],
                    ]);
                }

                $pdo->commit();
                $success_message = $status_id === 3
                    ? "AV asset {$asset_id} registered with deployment record."
                    : "AV asset {$asset_id} registered.";

                $next_asset_id = next_av_asset_id($pdo);
                if ($next_asset_id === null) {
                    $success_message .= ' No more AV IDs remain for this calendar year.';
                }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_message = ((string)$e->getCode() === '23000')
                    ? 'Asset ID already exists or constraint conflict.'
                    : 'Database error: ' . $e->getMessage();
            }
        }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register AV Asset — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #0ea5e9;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
            --bg: #f1f5f9;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass: #f8fafc;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
            padding: 2rem 2.5rem 4rem;
            max-width: calc(100vw - 280px);
        }
        @media (max-width: 900px) { .main-content { margin-left: 0; max-width: 100vw; padding: 1.25rem 1rem 3rem; } }

        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
            margin-bottom: 1.75rem;
        }
        .page-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 900;
            letter-spacing: -0.03em;
        }
        .page-subtitle { margin-top: 0.3rem; color: var(--text-muted); font-size: 0.9rem; line-height: 1.4; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 0.45rem;
            padding: 0.65rem 1.1rem;
            border-radius: 12px;
            border: 1.5px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-muted);
            font-weight: 700;
            font-size: 0.88rem;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-back:hover { border-color: var(--primary); color: var(--primary); }

        .alert {
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            padding: 1rem 1.1rem;
            border-radius: 14px;
            margin-bottom: 1.25rem;
            font-weight: 700;
        }
        .alert-success { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #047857; }
        .alert-error { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.25); color: #b91c1c; font-weight: 700; }

        .form-section {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 1.25rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.05);
        }
        .section-head {
            display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
            padding: 1rem 1.4rem;
            border-bottom: 1px solid var(--card-border);
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        }
        .section-head-left { display: flex; align-items: center; gap: 0.7rem; }
        .section-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: rgba(37,99,235,0.1);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .section-icon i { color: var(--primary); font-size: 1rem; }
        .section-title { font-family: 'Outfit', sans-serif; font-weight: 900; font-size: 0.98rem; }
        .section-desc { color: var(--text-muted); font-size: 0.75rem; margin-top: 0.1rem; line-height: 1.4; }
        .section-body { padding: 1.35rem; }
        .section-disabled { opacity: 0.42; pointer-events: none; }
        .badge-tag {
            padding: 0.2rem 0.65rem; border-radius: 20px;
            font-size: 0.67rem; font-weight: 800;
            letter-spacing: 0.05em; text-transform: uppercase; white-space: nowrap;
        }
        .badge-required { background: rgba(37,99,235,0.1); color: var(--primary); border: 1px solid rgba(37,99,235,0.2); }
        .badge-optional { background: rgba(100,116,139,0.1); color: var(--text-muted); border: 1px solid var(--card-border); }
        .badge-deploy { background: rgba(245,158,11,0.1); color: #92400e; border: 1px solid rgba(245,158,11,0.25); }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem 1.25rem;
        }
        @media (max-width: 1100px) { .form-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px) { .form-grid { grid-template-columns: 1fr; } }
        .col-2 { grid-column: span 2; }
        .col-3 { grid-column: 1 / -1; }
        @media (max-width: 640px) { .col-2, .col-3 { grid-column: span 1; } }

        .field { display: flex; flex-direction: column; gap: 0.45rem; }
        .field-label {
            font-size: 0.7rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;
        }
        .req { color: var(--danger); }

        .field-input, .field-select, .field-textarea {
            width: 100%;
            padding: 0.68rem 0.9rem;
            border: 1.5px solid var(--card-border);
            border-radius: 11px;
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            color: var(--text-main);
            background: var(--glass);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }
        .field-input:focus, .field-select:focus, .field-textarea:focus {
            border-color: rgba(37,99,235,0.5);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .field-textarea { resize: vertical; min-height: 95px; }
        .field-input:disabled, .field-select:disabled, .field-textarea:disabled { opacity: 0.5; cursor: not-allowed; }

        .deploy-notice {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            background: rgba(245,158,11,0.08);
            border: 1px solid rgba(245,158,11,0.22);
            color: #92400e;
            font-size: 0.82rem;
            font-weight: 800;
            margin-bottom: 1.1rem;
        }
        .deploy-notice i { color: var(--warning); font-size: 1rem; }

        .form-actions {
            display: flex; justify-content: flex-end; align-items: center;
            gap: 0.85rem;
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--card-border);
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.78rem 1.5rem;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 900;
            font-size: 0.92rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, #021A54, #1e40af); color: #fff; box-shadow: 0 4px 14px rgba(2,26,84,0.28); }
        .btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(2,26,84,0.35); }
        .btn-outline { background: var(--card-bg); border: 1.5px solid var(--card-border); color: var(--text-muted); }
        .btn-outline:hover { border-color: var(--danger); color: var(--danger); }

    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div>
            <h1 class="page-title">Register AV Asset</h1>
            <div class="page-subtitle">Add a new audio/visual asset to the inventory.</div>
        </div>
        <a href="av.php" class="btn-back">
            <i class="ri-arrow-left-line"></i> Back to AV inventory
        </a>
    </header>

    <?php if ($success_message !== ''): ?>
        <div class="alert alert-success">
            <i class="ri-checkbox-circle-fill"></i>
            <span><?= htmlspecialchars($success_message) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error_message !== ''): ?>
        <div class="alert alert-error">
            <i class="ri-error-warning-fill"></i>
            <span><?= htmlspecialchars($error_message) ?></span>
        </div>
    <?php endif; ?>

        <?php if ($error_message === '' && $status_options !== [] && $next_asset_id !== null): ?>
            <form method="post" action="" id="avForm">
                <div class="form-section">
                    <div class="section-head">
                        <div class="section-head-left">
                            <div class="section-icon"><i class="ri-eye-line"></i></div>
                            <div>
                                <div class="section-title">Device identity</div>
                                <div class="section-desc">Asset ID is the next integer (88 + 2-digit year + 3-digit sequence, e.g. 8826001). Serial, category, status, brand and model</div>
                            </div>
                        </div>
                        <span class="badge-tag badge-required">Required</span>
                    </div>
                    <div class="section-body">
                        <div class="form-grid">
                            <div class="field">
                                <label class="field-label">Next asset ID <span class="req">*</span></label>
                                <input type="number" class="field-input" value="<?= (int) $next_asset_id ?>" readonly tabindex="-1" aria-readonly="true">
                            </div>
                            <div class="field">
                                <label class="field-label">Asset ID old</label>
                                <input type="text" name="asset_id_old" class="field-input" placeholder="For older assets">
                            </div>
                            <div class="field">
                                <label class="field-label">Category <span class="req">*</span></label>
                                <input type="text" name="category" class="field-input" placeholder="e.g. Laptop, Projector" required>
                            </div>
                            <div class="field">
                                <label class="field-label">Serial number <span class="req">*</span></label>
                                <input type="text" name="serial_num" class="field-input" placeholder="e.g. SN123456" required>
                            </div>
                            <div class="field">
                                <label class="field-label">Status <span class="req">*</span></label>
                                <select name="status_id" id="status_id" class="field-select" required>
                                    <option value="" disabled selected>Select status…</option>
                                    <?php foreach ($status_options as $s): ?>
                                        <option value="<?= (int)$s['status_id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= (int)$s['status_id'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label class="field-label">Brand</label>
                                <input type="text" name="brand" class="field-input" placeholder="Optional">
                            </div>
                            <div class="field col-2">
                                <label class="field-label">Model</label>
                                <input type="text" name="model" class="field-input" placeholder="Optional">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-head">
                        <div class="section-head-left">
                            <div class="section-icon"><i class="ri-shopping-bag-3-line"></i></div>
                            <div>
                                <div class="section-title">Purchase details</div>
                                <div class="section-desc">PO, DO, invoice and purchase cost</div>
                            </div>
                        </div>
                        <span class="badge-tag badge-optional">Optional</span>
                    </div>
                    <div class="section-body">
                        <div class="form-grid">
                            <div class="field">
                                <label class="field-label">PO date</label>
                                <input type="date" name="po_date" class="field-input">
                            </div>
                            <div class="field">
                                <label class="field-label">PO number</label>
                                <input type="text" name="po_num" class="field-input" placeholder="Enter PO number">
                            </div>
                            <div class="field">
                                <label class="field-label">DO date</label>
                                <input type="date" name="do_date" class="field-input">
                            </div>
                            <div class="field">
                                <label class="field-label">DO number</label>
                                <input type="text" name="do_num" class="field-input" placeholder="Enter DO number">
                            </div>
                            <div class="field">
                                <label class="field-label">Invoice date</label>
                                <input type="date" name="invoice_date" class="field-input">
                            </div>
                            <div class="field">
                                <label class="field-label">Invoice number</label>
                                <input type="text" name="invoice_num" class="field-input" placeholder="Enter invoice number">
                            </div>
                            <div class="field col-2">
                                <label class="field-label">Purchase cost (RM)</label>
                                <input type="number" step="0.01" name="purchase_cost" class="field-input" placeholder="0.00">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section section-disabled" id="deploySection">
                    <div class="section-head">
                        <div class="section-head-left">
                            <div class="section-icon" style="background: rgba(245,158,11,0.12);">
                                <i class="ri-map-pin-user-line" style="color: var(--warning);"></i>
                            </div>
                            <div>
                                <div class="section-title">Deployment site</div>
                                <div class="section-desc">Required when status is Deploy (3)</div>
                            </div>
                        </div>
                        <span class="badge-tag badge-deploy">Deploy only</span>
                    </div>
                    <div class="section-body">
                        <div class="deploy-notice">
                            <i class="ri-alert-line"></i>
                            Set status to <strong>Deploy</strong> above to unlock this section.
                        </div>
                        <div class="form-grid">
                            <div class="field">
                                <label class="field-label">Building <span class="req">*</span></label>
                                <select name="deployment_building" id="deployment_building" class="field-select deploy-input" disabled>
                                    <option value="" disabled selected>Select building…</option>
                                    <?php foreach (BUILDING_OPTIONS as $b): ?>
                                        <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label class="field-label">Level <span class="req">*</span></label>
                                <input type="text" name="deployment_level" id="deployment_level" class="field-input deploy-input" placeholder="e.g. 3F, Basement" disabled>
                            </div>
                            <div class="field">
                                <label class="field-label">Zone <span class="req">*</span></label>
                                <input type="text" name="deployment_zone" id="deployment_zone" class="field-input deploy-input" placeholder="e.g. Lab 3" disabled>
                            </div>
                            <div class="field col-2">
                                <label class="field-label">Deployment date <span class="req">*</span></label>
                                <input type="date" name="deployment_date" id="deployment_date" class="field-input deploy-input" disabled>
                            </div>
                            <div class="field col-3">
                                <label class="field-label">Deployment remarks</label>
                                <textarea name="deployment_remarks" id="deployment_remarks" class="field-textarea deploy-input" disabled style="min-height:70px;"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-head">
                        <div class="section-head-left">
                            <div class="section-icon"><i class="ri-sticky-note-line"></i></div>
                            <div>
                                <div class="section-title">General remarks</div>
                                <div class="section-desc">Additional notes about this asset</div>
                            </div>
                        </div>
                        <span class="badge-tag badge-optional">Optional</span>
                    </div>
                    <div class="section-body">
                        <div class="field">
                            <label class="field-label" for="remarks">Remarks</label>
                            <textarea id="remarks" name="remarks" class="field-textarea" placeholder="Optional"></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="reset" class="btn btn-outline"><i class="ri-refresh-line"></i> Clear Form</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-3-line"></i> Register AV</button>
                </div>
            </form>
        <?php else: ?>
            <div class="alert alert-error">
                <i class="ri-error-warning-fill"></i>
                <span>No valid AV statuses found in <code>status</code> table. Check status IDs.</span>
            </div>
        <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function syncDeploymentSection() {
        var sel = document.getElementById('status_id');
        var section = document.getElementById('deploySection');
        if (!sel || !section) return;
        var deploy = String(sel.value) === '3';
        section.classList.toggle('section-disabled', !deploy);
        var inputs = document.querySelectorAll('.deploy-input');
        inputs.forEach(function (el) {
            el.disabled = !deploy;
            if (!deploy) {
                el.removeAttribute('required');
                if (el.tagName === 'SELECT') el.selectedIndex = 0;
                if (el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'date')) el.value = '';
                if (el.tagName === 'TEXTAREA') el.value = '';
            }
        });

        var requiredIds = ['deployment_building', 'deployment_level', 'deployment_zone', 'deployment_date'];
        if (deploy) {
            requiredIds.forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.setAttribute('required', 'required');
            });
        }
    }

    var sel = document.getElementById('status_id');
    if (sel) {
        sel.addEventListener('change', syncDeploymentSection);
        syncDeploymentSection();
    }
});
</script>
</body>
</html>
