<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

const DEPLOY_STATUS_ID = 3;
const AV_ALLOWED_DEPLOY_FROM = [1, 2, 5, 6]; // Active, Non-active, Maintenance, Faulty
const BUILDING_OPTIONS = [
    'AVICENNA',
    'AL-ZAHRAWI',
    'AL-RAZI',
    'IBN-KHALDUN',
    'IBN-KHALDUN-B',
];

$pdo = db();

$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : (isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0);

$success_message = '';
$error_message = '';

$asset = null;
$assetOptions = [];

try {
    $assetOptions = $pdo->query("
        SELECT a.asset_id, a.category, a.brand, a.model, a.serial_num, a.status_id, s.name AS status_name
        FROM av a
        JOIN status s ON s.status_id = a.status_id
        ORDER BY a.asset_id DESC
        LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $assetOptions = [];
    $error_message = 'Could not load AV assets. Ensure the database is available.';
}

if ($assetId > 0 && $error_message === '') {
    $st = $pdo->prepare("
        SELECT a.asset_id, a.category, a.brand, a.model, a.serial_num, a.status_id, s.name AS status_name
        FROM av a
        JOIN status s ON s.status_id = a.status_id
        WHERE a.asset_id = ?
        LIMIT 1
    ");
    $st->execute([$assetId]);
    $asset = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$asset) {
        $error_message = 'Asset not found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error_message === '') {
    $building = trim((string)($_POST['building'] ?? ''));
    $level = trim((string)($_POST['level'] ?? ''));
    $zone = trim((string)($_POST['zone'] ?? ''));
    $deployment_date = trim((string)($_POST['deployment_date'] ?? ''));
    $deployment_remarks = trim((string)($_POST['deployment_remarks'] ?? ''));
    if ($deployment_remarks === '') {
        $deployment_remarks = null;
    }

    $sessionStaffId = trim((string)($_SESSION['staff_id'] ?? ''));

    if ($assetId <= 0) {
        $error_message = 'Please select a valid asset.';
    } elseif ($building === '' || $level === '' || $zone === '' || $deployment_date === '') {
        $error_message = 'Building, level, zone, and deployment date are required.';
    } elseif ($sessionStaffId === '') {
        $error_message = 'Technician not found in session. Please log in again.';
    } else {
        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare('SELECT asset_id, status_id FROM av WHERE asset_id = ? FOR UPDATE');
            $lock->execute([$assetId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Asset not found.');
            }

            $currentStatus = (int)$row['status_id'];
            if ($currentStatus === DEPLOY_STATUS_ID) {
                throw new RuntimeException('This asset is already in Deploy status.');
            }
            if (!in_array($currentStatus, AV_ALLOWED_DEPLOY_FROM, true)) {
                throw new RuntimeException('Only Active, Non-active, Maintenance, or Faulty assets can be deployed.');
            }

            $ins = $pdo->prepare('
                INSERT INTO av_deployment
                    (asset_id, building, level, zone, deployment_date, deployment_remarks, staff_id)
                VALUES
                    (:asset_id, :building, :level, :zone, :deployment_date, :deployment_remarks, :staff_id)
            ');
            $ins->execute([
                ':asset_id' => $assetId,
                ':building' => $building,
                ':level' => $level,
                ':zone' => $zone,
                ':deployment_date' => $deployment_date,
                ':deployment_remarks' => $deployment_remarks,
                ':staff_id' => $sessionStaffId,
            ]);

            $upd = $pdo->prepare('UPDATE av SET status_id = :deploy WHERE asset_id = :asset_id');
            $upd->execute([
                ':deploy' => DEPLOY_STATUS_ID,
                ':asset_id' => $assetId,
            ]);

            $pdo->commit();
            $success_message = 'Deployment recorded and asset status updated to Deploy (3).';

            $st = $pdo->prepare("
                SELECT a.asset_id, a.category, a.brand, a.model, a.serial_num, a.status_id, s.name AS status_name
                FROM av a
                JOIN status s ON s.status_id = a.status_id
                WHERE a.asset_id = ?
                LIMIT 1
            ");
            $st->execute([$assetId]);
            $asset = $st->fetch(PDO::FETCH_ASSOC) ?: $asset;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = $e instanceof RuntimeException ? $e->getMessage() : ('Database error: ' . $e->getMessage());
        }
    }
}

function av_label(array $row): string
{
    $parts = [];
    if (!empty($row['asset_id'])) $parts[] = (string)$row['asset_id'];
    $name = trim((string)($row['category'] ?? '') . ' ' . (string)($row['brand'] ?? '') . ' ' . (string)($row['model'] ?? ''));
    if ($name !== '') $parts[] = $name;
    if (!empty($row['serial_num'])) $parts[] = 'SN ' . (string)$row['serial_num'];
    if (!empty($row['status_name'])) $parts[] = '[' . (string)$row['status_name'] . ']';
    return implode(' — ', $parts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deploy AV Asset — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root{
            --primary:#2563eb;--warning:#f59e0b;--danger:#ef4444;--success:#10b981;
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
        .icon{width:36px;height:36px;border-radius:10px;background:rgba(245,158,11,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .icon i{color:var(--warning);font-size:1rem}
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
        .meta{margin-top:.6rem;color:var(--muted);font-size:.88rem}
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
            <h1 class="page-title">Deploy AV Asset</h1>
            <div class="page-subtitle">Create a deployment record and set asset status to Deploy (3).</div>
        </div>
        <a href="av.php" class="btn-back"><i class="ri-arrow-left-line"></i> Back to AV inventory</a>
    </header>

    <?php if ($success_message !== ''): ?>
        <div class="alert alert-success"><i class="ri-checkbox-circle-fill"></i><span><?= htmlspecialchars($success_message) ?></span></div>
    <?php endif; ?>
    <?php if ($error_message !== ''): ?>
        <div class="alert alert-error"><i class="ri-error-warning-fill"></i><span><?= htmlspecialchars($error_message) ?></span></div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="card">
            <div class="card-head">
                <div class="head-left">
                    <div class="icon"><i class="ri-map-pin-user-line"></i></div>
                    <div>
                        <div class="head-title">Select asset</div>
                        <div class="head-desc">Only status 1, 2, 5, 6 can be deployed.</div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="grid">
                    <div class="field col-3">
                        <label class="label">Asset <span class="req">*</span></label>
                        <select name="asset_id" class="select" required>
                            <option value="" disabled <?= $assetId <= 0 ? 'selected' : '' ?>>Select asset…</option>
                            <?php foreach ($assetOptions as $opt): $aid = (int)$opt['asset_id']; ?>
                                <option value="<?= $aid ?>" <?= $aid === $assetId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(av_label($opt)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($asset): ?>
                            <div class="meta">
                                Current status: <strong><?= htmlspecialchars((string)$asset['status_name']) ?></strong>
                                &nbsp;•&nbsp; Asset ID: <code><?= (int)$asset['asset_id'] ?></code>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div class="head-left">
                    <div class="icon"><i class="ri-truck-line"></i></div>
                    <div>
                        <div class="head-title">Deployment details</div>
                        <div class="head-desc">Saved into <code>av_deployment</code>.</div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="grid">
                    <div class="field">
                        <label class="label">Building <span class="req">*</span></label>
                        <select name="building" class="select" required>
                            <option value="" disabled selected>Select building…</option>
                            <?php foreach (BUILDING_OPTIONS as $b): ?>
                                <option value="<?= htmlspecialchars($b) ?>" <?= (isset($_POST['building']) && (string)$_POST['building'] === $b) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label">Level <span class="req">*</span></label>
                        <input type="text" name="level" class="input" placeholder="e.g. 3F, Basement" value="<?= htmlspecialchars((string)($_POST['level'] ?? '')) ?>" required>
                    </div>
                    <div class="field">
                        <label class="label">Zone <span class="req">*</span></label>
                        <input type="text" name="zone" class="input" placeholder="e.g. Lab 3" value="<?= htmlspecialchars((string)($_POST['zone'] ?? '')) ?>" required>
                    </div>
                    <div class="field col-2">
                        <label class="label">Deployment date <span class="req">*</span></label>
                        <input type="date" name="deployment_date" class="input" value="<?= htmlspecialchars((string)($_POST['deployment_date'] ?? '')) ?>" required>
                    </div>
                    <div class="field col-3">
                        <label class="label">Deployment remarks</label>
                        <textarea name="deployment_remarks" class="textarea"><?= htmlspecialchars((string)($_POST['deployment_remarks'] ?? '')) ?></textarea>
                    </div>
                </div>

                <div class="actions">
                    <a class="btn btn-outline" href="avDeploy.php"><i class="ri-refresh-line"></i> Clear</a>
                    <button type="submit" class="btn btn-primary"><i class="ri-upload-2-line"></i> Deploy</button>
                </div>
            </div>
        </div>
    </form>
</main>
</body>
</html>
