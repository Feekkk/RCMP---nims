<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

const DEPLOY_STATUS_ID = 3;
const DEFAULT_RETURN_PLACE = 'ITD office';
const DEFAULT_RETURN_STATUS_ID = 9; // Online

$pdo = db();

$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : (isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0);

$error_message = '';
$success_message = '';

$asset = null;
$latestDeployment = null;

$statusOptions = [];
try {
    $statusOptions = $pdo->query("
        SELECT status_id, name
        FROM status
        WHERE status_id IN (5,6,7,8,9,10)
        ORDER BY status_id
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $statusOptions = [];
}

if ($assetId > 0) {
    $stAsset = $pdo->prepare("
        SELECT n.asset_id, n.brand, n.model, n.serial_num, n.ip_address, n.mac_address, n.status_id, s.name AS status_name
        FROM network n
        JOIN status s ON s.status_id = n.status_id
        WHERE n.asset_id = ?
        LIMIT 1
    ");
    $stAsset->execute([$assetId]);
    $asset = $stAsset->fetch(PDO::FETCH_ASSOC) ?: null;

    $stDep = $pdo->prepare("
        SELECT d.deployment_id, d.building, d.level, d.zone, d.deployment_date, d.deployment_remarks, u.full_name AS deployed_by_name
        FROM network_deployment d
        LEFT JOIN users u ON u.staff_id = d.staff_id
        WHERE d.asset_id = ?
        ORDER BY d.deployment_date DESC, d.deployment_id DESC
        LIMIT 1
    ");
    $stDep->execute([$assetId]);
    $latestDeployment = $stDep->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$asset) {
        $error_message = 'Asset not found.';
    } elseif ((int)$asset['status_id'] !== DEPLOY_STATUS_ID) {
        $error_message = 'Return can only be recorded when asset status is Deploy (3).';
    } elseif (!$latestDeployment) {
        $error_message = 'No deployment record found for this asset.';
    } else {
        $chk = $pdo->prepare('SELECT 1 FROM network_return WHERE deployment_id = ? LIMIT 1');
        $chk->execute([(int)$latestDeployment['deployment_id']]);
        if ($chk->fetchColumn()) {
            $error_message = 'Return has already been recorded for this deployment.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error_message === '') {
    $returnDate = trim((string)($_POST['return_date'] ?? ''));
    $returnTime = trim((string)($_POST['return_time'] ?? ''));
    $returnPlace = trim((string)($_POST['return_place'] ?? ''));
    if ($returnPlace === '') {
        $returnPlace = DEFAULT_RETURN_PLACE;
    }
    $condition = trim((string)($_POST['condition'] ?? ''));
    $returnRemarks = trim((string)($_POST['return_remarks'] ?? ''));
    $newStatusId = isset($_POST['new_status_id']) ? (int)$_POST['new_status_id'] : DEFAULT_RETURN_STATUS_ID;

    $sessionStaffId = trim((string)($_SESSION['staff_id'] ?? ''));

    if ($assetId <= 0) {
        $error_message = 'Invalid asset ID.';
    } elseif ($returnDate === '') {
        $error_message = 'Return date is required.';
    } elseif ($sessionStaffId === '') {
        $error_message = 'Technician not found in session. Please log in again.';
    } else {
        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare('SELECT asset_id, status_id FROM network WHERE asset_id = ? FOR UPDATE');
            $lock->execute([$assetId]);
            $rowLock = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$rowLock) {
                throw new RuntimeException('Asset not found.');
            }
            if ((int)$rowLock['status_id'] !== DEPLOY_STATUS_ID) {
                throw new RuntimeException('Asset is no longer in Deploy status.');
            }

            $stDep = $pdo->prepare("
                SELECT deployment_id
                FROM network_deployment
                WHERE asset_id = ?
                ORDER BY deployment_date DESC, deployment_id DESC
                LIMIT 1
            ");
            $stDep->execute([$assetId]);
            $rowDep = $stDep->fetch(PDO::FETCH_ASSOC);
            if (!$rowDep) {
                throw new RuntimeException('No deployment record found for this asset.');
            }
            $deploymentId = (int)$rowDep['deployment_id'];

            $dup = $pdo->prepare('SELECT 1 FROM network_return WHERE deployment_id = ? LIMIT 1');
            $dup->execute([$deploymentId]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('Return already recorded for this deployment.');
            }

            $allowedAfter = [5, 6, 7, 8, 9, 10];
            if (!in_array($newStatusId, $allowedAfter, true)) {
                $newStatusId = DEFAULT_RETURN_STATUS_ID;
            }

            $ins = $pdo->prepare('
                INSERT INTO network_return
                    (deployment_id, returned_by, return_date, return_time, return_place, `condition`, return_remarks)
                VALUES
                    (:deployment_id, :returned_by, :return_date, :return_time, :return_place, :condition, :return_remarks)
            ');
            $ins->execute([
                ':deployment_id' => $deploymentId,
                ':returned_by' => $sessionStaffId,
                ':return_date' => $returnDate,
                ':return_time' => $returnTime !== '' ? $returnTime : null,
                ':return_place' => $returnPlace !== '' ? $returnPlace : null,
                ':condition' => $condition !== '' ? $condition : null,
                ':return_remarks' => $returnRemarks !== '' ? $returnRemarks : null,
            ]);

            $upd = $pdo->prepare('UPDATE network SET status_id = :status_id WHERE asset_id = :asset_id');
            $upd->execute([
                ':status_id' => $newStatusId,
                ':asset_id' => $assetId,
            ]);

            $pdo->commit();
            $success_message = 'Return recorded and asset status updated.';

            $stAsset = $pdo->prepare("
                SELECT n.asset_id, n.brand, n.model, n.serial_num, n.ip_address, n.mac_address, n.status_id, s.name AS status_name
                FROM network n
                JOIN status s ON s.status_id = n.status_id
                WHERE n.asset_id = ?
                LIMIT 1
            ");
            $stAsset->execute([$assetId]);
            $asset = $stAsset->fetch(PDO::FETCH_ASSOC) ?: $asset;
            $error_message = '';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e->getMessage();
            if (stripos($msg, 'network_return') !== false && (stripos($msg, "doesn't exist") !== false || stripos($msg, 'Unknown table') !== false)) {
                $error_message = 'Missing table network_return — apply updated db/schema.sql.';
            } else {
                $error_message = $e instanceof RuntimeException ? $msg : ('Database error: ' . $msg);
            }
        }
    }
}

function network_device_name(array $a): string
{
    $t = trim(($a['brand'] ?? '') . ' ' . ($a['model'] ?? ''));
    return $t !== '' ? $t : 'Network device';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Network Asset — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root{
            --primary:#2563eb;--secondary:#0ea5e9;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;
            --bg:#f1f5f9;--card-bg:#ffffff;--card-border:#e2e8f0;--text:#0f172a;--muted:#64748b;--glass:#f8fafc;
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden}
        .main-content{margin-left:280px;flex:1;padding:2rem 2.5rem 4rem;max-width:calc(100vw - 280px)}
        @media (max-width:900px){.main-content{margin-left:0;max-width:100vw;padding:1.25rem 1rem 3rem}}
        .page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem}
        .page-title{font-family:'Outfit',sans-serif;font-size:1.75rem;font-weight:900;letter-spacing:-0.03em}
        .page-subtitle{margin-top:.3rem;color:var(--muted);font-size:.9rem;line-height:1.4}
        .btn-back{display:inline-flex;align-items:center;gap:.45rem;padding:.65rem 1.1rem;border-radius:12px;border:1.5px solid var(--card-border);background:var(--card-bg);color:var(--muted);font-weight:700;font-size:.88rem;text-decoration:none;white-space:nowrap}
        .btn-back:hover{border-color:var(--primary);color:var(--primary)}
        .alert{display:flex;gap:.75rem;align-items:flex-start;padding:1rem 1.1rem;border-radius:14px;margin-bottom:1.25rem;font-weight:700}
        .alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#047857}
        .alert-error{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#b91c1c}
        .card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:20px;overflow:hidden;margin-bottom:1.25rem;box-shadow:0 2px 12px rgba(15,23,42,.05)}
        .card-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:1rem 1.4rem;border-bottom:1px solid var(--card-border);background:linear-gradient(135deg,#f8fafc,#f1f5f9)}
        .head-left{display:flex;align-items:center;gap:.7rem}
        .icon{width:36px;height:36px;border-radius:10px;background:rgba(14,165,233,.10);display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .icon i{color:var(--secondary);font-size:1rem}
        .head-title{font-family:'Outfit',sans-serif;font-weight:900;font-size:.98rem}
        .head-desc{color:var(--muted);font-size:.75rem;margin-top:.1rem;line-height:1.4}
        .card-body{padding:1.35rem}
        .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem 1.25rem}
        @media (max-width:1100px){.grid{grid-template-columns:repeat(2,1fr)}}
        @media (max-width:640px){.grid{grid-template-columns:1fr}}
        .col-2{grid-column:span 2}
        .col-3{grid-column:1 / -1}
        @media (max-width:640px){.col-2,.col-3{grid-column:span 1}}
        .field{display:flex;flex-direction:column;gap:.45rem}
        .label{font-size:.7rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
        .req{color:var(--danger)}
        .input,.select,.textarea{
            width:100%;padding:.68rem .9rem;border:1.5px solid var(--card-border);border-radius:11px;
            font-family:'Inter',sans-serif;font-size:.875rem;color:var(--text);background:var(--glass);outline:none;
            transition:border-color .2s,box-shadow .2s,background .2s;
        }
        .textarea{resize:vertical;min-height:95px}
        .input:focus,.select:focus,.textarea:focus{border-color:rgba(37,99,235,.5);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
        .meta{margin-top:.65rem;color:var(--muted);font-size:.9rem;line-height:1.45}
        .meta code{background:var(--glass);border:1px solid var(--card-border);padding:2px 8px;border-radius:10px;font-weight:900;color:var(--primary)}
        .actions{display:flex;justify-content:flex-end;align-items:center;gap:.85rem;margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--card-border);flex-wrap:wrap}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.78rem 1.5rem;border-radius:12px;font-family:'Outfit',sans-serif;font-weight:900;font-size:.92rem;cursor:pointer;border:none;transition:all .2s;text-decoration:none}
        .btn-primary{background:linear-gradient(135deg,#021A54,#1e40af);color:#fff;box-shadow:0 4px 14px rgba(2,26,84,.28)}
        .btn-primary:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 6px 20px rgba(2,26,84,.35)}
        .btn-outline{background:var(--card-bg);border:1.5px solid var(--card-border);color:var(--muted)}
        .btn-outline:hover{border-color:var(--danger);color:var(--danger)}
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div>
            <h1 class="page-title">Return Network Asset</h1>
            <div class="page-subtitle">Record a return for the latest network deployment.</div>
        </div>
        <a href="network.php" class="btn-back"><i class="ri-arrow-left-line"></i> Back to network inventory</a>
    </header>

    <?php if ($success_message !== ''): ?>
        <div class="alert alert-success"><i class="ri-checkbox-circle-fill"></i><span><?= htmlspecialchars($success_message) ?></span></div>
    <?php endif; ?>
    <?php if ($error_message !== ''): ?>
        <div class="alert alert-error"><i class="ri-error-warning-fill"></i><span><?= htmlspecialchars($error_message) ?></span></div>
    <?php endif; ?>

    <?php if ($assetId <= 0): ?>
        <div class="alert alert-error"><i class="ri-error-warning-fill"></i><span>Missing asset_id.</span></div>
    <?php endif; ?>

    <?php if ($asset): ?>
        <div class="card">
            <div class="card-head">
                <div class="head-left">
                    <div class="icon"><i class="ri-router-line"></i></div>
                    <div>
                        <div class="head-title"><?= htmlspecialchars(network_device_name($asset)) ?></div>
                        <div class="head-desc">Asset ID <code><?= (int)$asset['asset_id'] ?></code> • SN <?= htmlspecialchars((string)($asset['serial_num'] ?? '—')) ?> • Status <?= htmlspecialchars((string)$asset['status_name']) ?></div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if ($latestDeployment): ?>
                    <div class="meta">
                        Latest deployment: <strong><?= htmlspecialchars((string)$latestDeployment['building']) ?></strong>,
                        <?= htmlspecialchars((string)$latestDeployment['level']) ?>,
                        <?= htmlspecialchars((string)$latestDeployment['zone']) ?>
                        on <strong><?= htmlspecialchars((string)$latestDeployment['deployment_date']) ?></strong>.
                        <?php if (!empty($latestDeployment['deployed_by_name'])): ?>
                            Deployed by <strong><?= htmlspecialchars((string)$latestDeployment['deployed_by_name']) ?></strong>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php
        $showForm = $asset && $latestDeployment && $success_message === '';
        if ($showForm) {
            $chk2 = $pdo->prepare('SELECT 1 FROM network_return WHERE deployment_id = ? LIMIT 1');
            $chk2->execute([(int)$latestDeployment['deployment_id']]);
            if ($chk2->fetchColumn()) {
                $showForm = false;
            }
        }
        if ($showForm && (int)($asset['status_id'] ?? 0) !== DEPLOY_STATUS_ID) {
            $showForm = false;
        }
    ?>
    <?php if ($showForm): ?>
        <form method="post" action="">
            <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
            <div class="card">
                <div class="card-head">
                    <div class="head-left">
                        <div class="icon"><i class="ri-inbox-unarchive-line"></i></div>
                        <div>
                            <div class="head-title">Return details</div>
                            <div class="head-desc">Saved into <code>network_return</code> and updates network status.</div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="grid">
                        <div class="field">
                            <label class="label">Return date <span class="req">*</span></label>
                            <input class="input" type="date" name="return_date" value="<?= htmlspecialchars((string)($_POST['return_date'] ?? date('Y-m-d'))) ?>" required>
                        </div>
                        <div class="field">
                            <label class="label">Return time</label>
                            <input class="input" type="time" name="return_time" value="<?= htmlspecialchars((string)($_POST['return_time'] ?? '')) ?>">
                        </div>
                        <div class="field">
                            <label class="label">Return place</label>
                            <input class="input" type="text" name="return_place" value="<?= htmlspecialchars((string)($_POST['return_place'] ?? DEFAULT_RETURN_PLACE)) ?>">
                        </div>
                        <div class="field">
                            <label class="label">Condition</label>
                            <input class="input" type="text" name="condition" placeholder="e.g. Good, Damaged" value="<?= htmlspecialchars((string)($_POST['condition'] ?? '')) ?>">
                        </div>
                        <div class="field col-2">
                            <label class="label">New status after return</label>
                            <select name="new_status_id" class="select">
                                <?php
                                    $selected = isset($_POST['new_status_id']) ? (int)$_POST['new_status_id'] : DEFAULT_RETURN_STATUS_ID;
                                    if ($statusOptions === []) {
                                        echo '<option value="' . DEFAULT_RETURN_STATUS_ID . '" selected>Online (9)</option>';
                                    } else {
                                        foreach ($statusOptions as $s) {
                                            $sid = (int)$s['status_id'];
                                            $sel = $sid === $selected ? 'selected' : '';
                                            echo '<option value="' . $sid . '" ' . $sel . '>' . htmlspecialchars((string)$s['name']) . ' (' . $sid . ')</option>';
                                        }
                                    }
                                ?>
                            </select>
                        </div>
                        <div class="field col-3">
                            <label class="label">Return remarks</label>
                            <textarea class="textarea" name="return_remarks"><?= htmlspecialchars((string)($_POST['return_remarks'] ?? '')) ?></textarea>
                        </div>
                    </div>

                    <div class="actions">
                        <a class="btn btn-outline" href="networkReturn.php?asset_id=<?= (int)$assetId ?>"><i class="ri-refresh-line"></i> Reset</a>
                        <button type="submit" class="btn btn-primary"><i class="ri-check-double-line"></i> Save return</button>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
