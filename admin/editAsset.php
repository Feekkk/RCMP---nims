<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 2) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function admin_editasset_table(string $cls): ?string
{
    $cls = strtolower(trim($cls));
    switch ($cls) {
        case 'laptop':
            return 'laptop';
        case 'network':
            return 'network';
        case 'av':
            return 'av';
        default:
            return null;
    }
}

function admin_editasset_has_table(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

$assetClass = (string)($_GET['asset_class'] ?? $_POST['asset_class'] ?? '');
$assetId    = (int)($_GET['asset_id'] ?? $_POST['asset_id'] ?? 0);
$table      = admin_editasset_table($assetClass);

$error = '';
$ok = isset($_GET['saved']) && (string)$_GET['saved'] === '1';
$asset = null;
$statusOptions = [];

try {
    $pdo = db();
    $statusOptions = $pdo->query('SELECT status_id, name FROM status ORDER BY status_id ASC')->fetchAll(PDO::FETCH_ASSOC);

    if ($table === null || $assetId < 1) {
        throw new RuntimeException('Invalid asset.');
    }
    if (!admin_editasset_has_table($pdo, $table)) {
        throw new RuntimeException('Asset table not available.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_asset'])) {
        $statusId = (int)($_POST['status_id'] ?? 0);
        $brand  = trim((string)($_POST['brand'] ?? ''));
        $model  = trim((string)($_POST['model'] ?? ''));
        $serial = trim((string)($_POST['serial_num'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $mac = trim((string)($_POST['mac_address'] ?? ''));
        $ip  = trim((string)($_POST['ip_address'] ?? ''));

        if ($statusId < 1) {
            throw new RuntimeException('Select a valid status.');
        }

        if ($table === 'network') {
            $stmtU = $pdo->prepare('UPDATE network SET serial_num = ?, brand = ?, model = ?, mac_address = ?, ip_address = ?, status_id = ?, remarks = ? WHERE asset_id = ?');
            $stmtU->execute([$serial !== '' ? $serial : null, $brand !== '' ? $brand : null, $model !== '' ? $model : null, $mac !== '' ? $mac : null, $ip !== '' ? $ip : null, $statusId, $remarks !== '' ? $remarks : null, $assetId]);
        } else {
            // laptop + av share these columns
            $stmtU = $pdo->prepare("UPDATE `{$table}` SET serial_num = ?, brand = ?, model = ?, category = ?, status_id = ?, remarks = ? WHERE asset_id = ?");
            $stmtU->execute([$serial !== '' ? $serial : null, $brand !== '' ? $brand : null, $model !== '' ? $model : null, $category !== '' ? $category : null, $statusId, $remarks !== '' ? $remarks : null, $assetId]);
        }

        header('Location: editAsset.php?asset_class=' . urlencode($assetClass) . '&asset_id=' . $assetId . '&saved=1');
        exit;
    }

    if ($table === 'network') {
        $stmt = $pdo->prepare('SELECT asset_id, serial_num, brand, model, mac_address, ip_address, status_id, remarks, created_at, updated_at FROM network WHERE asset_id = ? LIMIT 1');
        $stmt->execute([$assetId]);
    } else {
        $stmt = $pdo->prepare("SELECT asset_id, serial_num, brand, model, category, status_id, remarks, created_at, updated_at FROM `{$table}` WHERE asset_id = ? LIMIT 1");
        $stmt->execute([$assetId]);
    }
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) {
        throw new RuntimeException('Asset not found.');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit asset — Admin — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root{--primary:#2563eb;--secondary:#7c3aed;--bg:#f0f4ff;--card:#fff;--border:#e2e8f0;--text:#0f172a;--muted:#64748b;--glass:#f8faff;--success:#10b981;--danger:#ef4444;}
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;overflow-x:hidden}

        /* Sidebar styles (same as other admin pages) */
        .sidebar{
            width:280px; min-height:100vh; background:var(--card);
            border-right:1px solid var(--border);
            display:flex; flex-direction:column;
            position:fixed; top:0; left:0; bottom:0;
            z-index:100; box-shadow:2px 0 20px rgba(15,23,42,0.06);
        }
        .sidebar-logo{padding:1.5rem 1.75rem 1.25rem;border-bottom:1px solid var(--border);text-align:center}
        .sidebar-logo img{height:42px;object-fit:contain}
        .nav-menu{flex:1;padding:1.25rem 1rem;display:flex;flex-direction:column;gap:0.25rem;overflow-y:auto}
        .nav-item{display:flex;align-items:center;gap:1rem;padding:0.75rem 1.25rem;border-radius:12px;color:var(--muted);text-decoration:none;font-weight:600;font-size:0.95rem;transition:all .2s ease}
        .nav-item:hover{background:rgba(37,99,235,0.06);color:var(--primary)}
        .nav-item.active{background:rgba(37,99,235,0.10);color:var(--primary)}
        .nav-item i{font-size:1.25rem}
        .user-profile{padding:1.25rem 1.75rem;border-top:1px solid var(--border);display:flex;align-items:center;gap:0.75rem;cursor:pointer;margin-top:auto}
        .user-profile:hover{background:rgba(37,99,235,0.04)}
        .avatar{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-weight:800;color:#fff;font-size:1rem}
        .user-info{flex:1;overflow:hidden}
        .user-name{font-size:0.9rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .user-role{font-size:0.75rem;color:var(--primary);margin-top:0.2rem;text-transform:uppercase;font-weight:800}

        .main-content{margin-left:280px;flex:1;padding:2rem 2.5rem;max-width:calc(100vw - 280px)}
        @media (max-width:900px){
            .sidebar{transform:translateX(-100%);width:260px}
            .main-content{margin-left:0;max-width:100vw;padding:1.25rem 1rem}
        }

        .card{background:var(--card);border:1px solid var(--border);border-radius:18px;box-shadow:0 2px 12px rgba(15,23,42,0.06);overflow:hidden}
        .hd{padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;background:linear-gradient(135deg,#f8fafc,#f1f5f9)}
        .hd h1{font-family:'Outfit',sans-serif;font-size:1.25rem;font-weight:800;display:flex;align-items:center;gap:0.6rem}
        .hd h1 i{color:var(--primary)}
        .bd{padding:1.25rem}
        .banner{padding:0.85rem 1rem;border-radius:14px;margin-bottom:1rem;font-weight:600;display:flex;gap:0.6rem;align-items:flex-start}
        .ok{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.26);color:#047857}
        .err{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.22);color:#b91c1c}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:0.85rem}
        @media (max-width:900px){.grid{grid-template-columns:1fr}}
        .field{display:flex;flex-direction:column;gap:0.35rem}
        label{font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)}
        input,select,textarea{padding:0.65rem 0.85rem;border:1.5px solid var(--border);border-radius:12px;background:#fff;font-family:inherit}
        textarea{min-height:110px;resize:vertical}
        .actions{display:flex;gap:0.6rem;flex-wrap:wrap;margin-top:1rem}
        .btn{display:inline-flex;align-items:center;gap:0.45rem;padding:0.75rem 1.1rem;border-radius:12px;border:1.5px solid var(--border);background:#fff;color:var(--text);font-weight:800;text-decoration:none;cursor:pointer}
        .btn.primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;color:#fff}
        .meta{color:var(--muted);font-size:0.82rem;margin-top:0.4rem}
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarAdmin.php'; ?>
<main class="main-content">
    <div class="card">
        <div class="hd">
            <h1><i class="ri-edit-2-line"></i> Edit asset</h1>
            <a class="btn" href="inventory.php"><i class="ri-arrow-left-line"></i> Back</a>
        </div>
        <div class="bd">
            <?php if ($ok): ?>
                <div class="banner ok"><i class="ri-checkbox-circle-line"></i> Saved.</div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="banner err"><i class="ri-error-warning-line"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($asset): ?>
            <form method="post" action="">
                <input type="hidden" name="save_asset" value="1">
                <input type="hidden" name="asset_class" value="<?= htmlspecialchars($assetClass) ?>">
                <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">

                <div class="grid">
                    <div class="field">
                        <label>Asset</label>
                        <input value="<?= htmlspecialchars(strtoupper($assetClass) . ' #' . (int)$asset['asset_id']) ?>" disabled>
                    </div>
                    <div class="field">
                        <label for="status_id">Status</label>
                        <select id="status_id" name="status_id" required>
                            <option value="">— Select status —</option>
                            <?php foreach ($statusOptions as $st): ?>
                                <option value="<?= (int)$st['status_id'] ?>" <?= (int)$asset['status_id'] === (int)$st['status_id'] ? 'selected' : '' ?>>
                                    <?= (int)$st['status_id'] ?> — <?= htmlspecialchars((string)$st['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="serial_num">Serial</label>
                        <input id="serial_num" name="serial_num" value="<?= htmlspecialchars((string)($asset['serial_num'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label for="brand">Brand</label>
                        <input id="brand" name="brand" value="<?= htmlspecialchars((string)($asset['brand'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label for="model">Model</label>
                        <input id="model" name="model" value="<?= htmlspecialchars((string)($asset['model'] ?? '')) ?>">
                    </div>

                    <?php if ($table === 'network'): ?>
                        <div class="field">
                            <label for="mac_address">MAC address</label>
                            <input id="mac_address" name="mac_address" value="<?= htmlspecialchars((string)($asset['mac_address'] ?? '')) ?>">
                        </div>
                        <div class="field">
                            <label for="ip_address">IP address</label>
                            <input id="ip_address" name="ip_address" value="<?= htmlspecialchars((string)($asset['ip_address'] ?? '')) ?>">
                        </div>
                    <?php else: ?>
                        <div class="field">
                            <label for="category">Category</label>
                            <input id="category" name="category" value="<?= htmlspecialchars((string)($asset['category'] ?? '')) ?>">
                        </div>
                    <?php endif; ?>

                    <div class="field" style="grid-column: 1 / -1">
                        <label for="remarks">Remarks</label>
                        <textarea id="remarks" name="remarks" maxlength="10000"><?= htmlspecialchars((string)($asset['remarks'] ?? '')) ?></textarea>
                        <div class="meta">Created: <?= htmlspecialchars((string)($asset['created_at'] ?? '—')) ?> · Updated: <?= htmlspecialchars((string)($asset['updated_at'] ?? '—')) ?></div>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn primary" type="submit"><i class="ri-save-3-line"></i> Save</button>
                    <a class="btn" href="inventory.php"><i class="ri-close-line"></i> Cancel</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>