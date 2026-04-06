<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$pdo = db();
$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : (isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0);

$error_message = '';
$success_message = '';

$asset = null;
$latestWarranty = null;
$recentRepairs = [];
$savedOk = isset($_GET['saved']) && $_GET['saved'] === '1';

function repair_warranty_is_active(array $w): bool
{
    $today = new DateTimeImmutable('today');
    $start = new DateTimeImmutable((string)$w['warranty_start_date']);
    $end = new DateTimeImmutable((string)$w['warranty_end_date']);
    return $today >= $start && $today <= $end;
}

if ($assetId > 0) {
    $stmtAsset = $pdo->prepare('                                                                                                             
        SELECT asset_id, brand, model, serial_num, status_id
        FROM laptop
        WHERE asset_id = ?
        LIMIT 1
    ');
    $stmtAsset->execute([$assetId]);
    $asset = $stmtAsset->fetch(PDO::FETCH_ASSOC);

    $stmtW = $pdo->prepare('
        SELECT warranty_id, warranty_start_date, warranty_end_date, warranty_remarks
        FROM warranty
        WHERE asset_id = ? AND asset_type = \'laptop\'
        ORDER BY warranty_end_date DESC, warranty_id DESC
        LIMIT 1
    ');
    $stmtW->execute([$assetId]);
    $latestWarranty = $stmtW->fetch(PDO::FETCH_ASSOC);

    $stmtR = $pdo->prepare('
        SELECT repair_id, repair_date, completed_date, issue_summary, repair_remarks, staff_id, created_at
        FROM repair
        WHERE asset_id = ?
        ORDER BY created_at DESC
        LIMIT 8
    ');
    $stmtR->execute([$assetId]);
    $recentRepairs = $stmtR->fetchAll(PDO::FETCH_ASSOC);

    if (!$asset) {
        $error_message = 'Asset not found.';
    } elseif (!$savedOk && (int)$asset['status_id'] !== 6) {
        $error_message = 'Repair log is only for assets in Faulty status. Open the asset from Laptop Inventory (Faulty filter).';
    } elseif (!$savedOk && $latestWarranty && repair_warranty_is_active($latestWarranty)) {
        $error_message = 'This asset still has an active vendor warranty. Use Warranty Claim instead.';
    }
}

if ($savedOk && $assetId > 0 && $asset) {
    $success_message = 'Repair record saved.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $repairDate = trim((string)($_POST['repair_date'] ?? ''));
    $completedDate = trim((string)($_POST['completed_date'] ?? ''));
    $issueSummary = trim((string)($_POST['issue_summary'] ?? ''));
    $repairRemarks = trim((string)($_POST['repair_remarks'] ?? ''));
    $staffId = trim((string)($_SESSION['staff_id'] ?? ''));

    if ($assetId <= 0) {
        $error_message = 'Invalid asset ID.';
    } elseif ($staffId === '') {
        $error_message = 'Technician not found in session. Please log in again.';
    } elseif ($repairDate === '') {
        $error_message = 'Missing repair date.';
    } elseif ($issueSummary === '') {
        $error_message = 'Missing issue summary.';
    } else {
        $stmtAsset2 = $pdo->prepare('SELECT status_id FROM laptop WHERE asset_id = ? LIMIT 1');
        $stmtAsset2->execute([$assetId]);
        $a2 = $stmtAsset2->fetch(PDO::FETCH_ASSOC);
        if (!$a2) {
            $error_message = 'Asset not found.';
        } elseif ((int)$a2['status_id'] !== 6) {
            $error_message = 'Asset is not in Faulty status.';
        } else {
            $stmtW2 = $pdo->prepare('
                SELECT warranty_start_date, warranty_end_date
                FROM warranty
                WHERE asset_id = ? AND asset_type = \'laptop\'
                ORDER BY warranty_end_date DESC, warranty_id DESC
                LIMIT 1
            ');
            $stmtW2->execute([$assetId]);
            $w2 = $stmtW2->fetch(PDO::FETCH_ASSOC);
            if ($w2 && repair_warranty_is_active($w2)) {
                $error_message = 'Active warranty — use Warranty Claim, not in-house repair.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmtIns = $pdo->prepare('
                        INSERT INTO repair
                            (asset_id, staff_id, repair_date, completed_date, issue_summary, repair_remarks)
                        VALUES
                            (:asset_id, :staff_id, :repair_date, :completed_date, :issue_summary, :repair_remarks)
                    ');
                    $stmtIns->execute([
                        ':asset_id' => $assetId,
                        ':staff_id' => $staffId,
                        ':repair_date' => $repairDate,
                        ':completed_date' => $completedDate !== '' ? $completedDate : null,
                        ':issue_summary' => $issueSummary,
                        ':repair_remarks' => $repairRemarks !== '' ? $repairRemarks : null,
                    ]);
                    $newStatus = $completedDate !== '' ? 1 : 5;
                    $stmtUp = $pdo->prepare('UPDATE laptop SET status_id = :sid WHERE asset_id = :aid AND status_id = 6');
                    $stmtUp->execute([':sid' => $newStatus, ':aid' => $assetId]);
                    $pdo->commit();
                    header('Location: repair.php?asset_id=' . (int)$assetId . '&saved=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error_message = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>In-house Repair - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root{
            --primary:#2563eb; --secondary:#0ea5e9; --accent:#f59e0b;
            --danger:#ef4444; --success:#10b981;
            --bg:#f1f5f9; --card-bg:#ffffff; --card-border:#e2e8f0;
            --text-main:#0f172a; --text-muted:#64748b; --input-bg:#f8fafc; --input-border:#cbd5e1;
            --glass-panel:#f8fafc;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text-main);min-height:100vh;display:flex;overflow-x:hidden}
        a{color:inherit}
        .sidebar{
            width:280px;height:100vh;background:var(--card-bg);
            border-right:1px solid var(--card-border);
            position:fixed;top:0;left:0;
            display:flex;flex-direction:column;padding:1.5rem;
            z-index:100;box-shadow:4px 0 20px rgba(15,23,42,0.06);
            transition:transform .3s ease;
        }
        .sidebar-logo{display:flex;align-items:center;justify-content:center;padding-bottom:2rem;border-bottom:1px solid var(--card-border);margin-bottom:2rem}
        .sidebar-logo img{height:45px;filter:drop-shadow(0 4px 6px rgba(0,0,0,0.35))}
        .nav-menu{display:flex;flex-direction:column;gap:.75rem;flex:1}
        .nav-item{
            padding:.85rem 1.25rem;border-radius:12px;color:var(--text-muted);
            text-decoration:none;display:flex;align-items:center;gap:1rem;
            font-weight:500;transition:all .3s cubic-bezier(.4,0,.2,1);
        }
        .nav-item:hover{color:var(--primary);background:rgba(37,99,235,0.06)}
        .nav-item.active{color:var(--primary);background:rgba(37,99,235,0.1);border:1px solid rgba(37,99,235,0.2);box-shadow:inset 3px 0 0 var(--primary)}
        .nav-item i{font-size:1.25rem;color:inherit}
        .nav-group .chevron{transition:transform .3s ease}
        .nav-item.open .chevron{transform:rotate(180deg)}
        .nav-dropdown{display:none;flex-direction:column;gap:.25rem;padding-left:3.25rem;margin-top:-.25rem}
        .nav-dropdown.show{display:flex}
        .nav-dropdown-item{
            padding:.65rem .95rem;border-radius:10px;color:var(--text-muted);
            text-decoration:none;font-size:.9rem;transition:all .2s ease;
        }
        .nav-dropdown-item:hover{background:rgba(37,99,235,0.08);color:var(--primary)}
        .nav-dropdown-item.active{background:rgba(37,99,235,0.12);color:var(--primary);font-weight:600}
        .user-profile{
            margin-top:auto;padding:1rem;background:var(--glass-panel);
            border:1px solid var(--card-border);border-radius:16px;
            display:flex;align-items:center;gap:1rem;cursor:pointer;
            transition:all .3s ease;
        }
        .user-profile:hover{background:rgba(37,99,235,0.06);border-color:rgba(37,99,235,0.2)}
        .avatar{
            width:42px;height:42px;border-radius:12px;
            background:linear-gradient(135deg,var(--primary),var(--secondary));
            display:flex;align-items:center;justify-content:center;
            font-family:'Outfit',sans-serif;font-weight:700;color:#fff;font-size:1.1rem;
            box-shadow:0 4px 10px rgba(37,99,235,0.3);
        }
        .user-info{flex:1;overflow:hidden}
        .user-name{font-size:.9rem;font-weight:600;color:var(--text-main);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .user-role{font-size:.75rem;color:var(--primary);margin-top:.2rem;text-transform:uppercase;letter-spacing:.5px;font-weight:600}
        .main-content{margin-left:280px;max-width:100vw;padding:1rem 1.5rem;width:100%}
        .wrapper{max-width:1100px;margin:0 auto}
        .card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:16px;padding:1.8rem;box-shadow:0 10px 25px rgba(15,23,42,0.05)}
        .card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.2rem}
        .card-title{font-size:1.25rem;font-weight:800;display:flex;align-items:center;gap:.6rem}
        .card-subtitle{margin-top:.35rem;color:var(--text-muted);font-size:.95rem}
        .device-summary{color:var(--text-muted);font-size:.95rem}
        .alert{margin-bottom:1.2rem;padding:1rem 1.25rem;border-radius:14px;border:1px solid rgba(239,68,68,0.35);background:rgba(239,68,68,0.12);color:#ef4444;font-size:.95rem}
        .okalert{margin-bottom:1.2rem;padding:1rem 1.25rem;border-radius:14px;border:1px solid rgba(16,185,129,0.35);background:rgba(16,185,129,0.12);color:#047857;font-size:.95rem}
        .hint{margin-bottom:1.2rem;padding:1rem 1.25rem;border-radius:14px;border:1px solid rgba(37,99,235,0.25);background:rgba(37,99,235,0.08);color:var(--text-main);font-size:.95rem}
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem}
        .section-title{font-weight:800;color:var(--text-main);margin-bottom:.75rem;border-bottom:1px solid var(--card-border);padding-bottom:.5rem}
        .form-group{margin-bottom:1.1rem}
        .form-label{display:block;font-size:.85rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:.5rem}
        .form-control,.form-textarea{width:100%;background:var(--input-bg);border:1px solid var(--input-border);border-radius:12px;padding:.75rem .9rem;font-family:'Inter',sans-serif;color:var(--text-main);outline:none}
        .form-control:focus,.form-textarea:focus{border-color:var(--primary);box-shadow:0 0 0 4px rgba(37,99,235,0.08);background:#fff}
        .form-row-inline{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
        .form-textarea{min-height:120px;resize:vertical}
        .form-footer{margin-top:1.4rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .btn{border:none;border-radius:12px;padding:.75rem 1rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:.5rem}
        .btn-primary{background:var(--primary);color:#fff;box-shadow:0 10px 20px rgba(37,99,235,0.25)}
        .btn-ghost{background:transparent;color:var(--text-muted);border:1px solid var(--card-border)}
        .btn-ghost:hover{color:var(--primary);border-color:rgba(37,99,235,0.25)}
        .back-link{display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--text-muted);margin-bottom:1rem}
        .back-link:hover{color:var(--primary)}
        table{width:100%;border-collapse:separate;border-spacing:0 10px}
        th{color:var(--text-muted);font-size:.8rem;text-transform:uppercase;letter-spacing:.4px;text-align:left;padding:.25rem .5rem}
        td{background:#fff;border:1px solid var(--card-border);padding:.75rem .75rem}
        tr td:first-child{border-top-left-radius:12px;border-bottom-left-radius:12px}
        tr td:last-child{border-top-right-radius:12px;border-bottom-right-radius:12px}
        @media (max-width: 980px){
            .sidebar{transform:translateX(-100%);width:260px}
            .main-content{margin-left:0;padding:1rem}
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
        <div class="wrapper">
            <a href="laptop.php?status_id=6" class="back-link"><i class="ri-arrow-left-line"></i> Back to Faulty laptops</a>

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><i class="ri-tools-line"></i> In-house repair (no warranty)</div>
                        <div class="card-subtitle">Log technician repair when vendor warranty does not apply or has expired.</div>
                    </div>
                    <?php if ($asset): ?>
                        <div class="device-summary">
                            <strong>Asset ID:</strong> <?= htmlspecialchars((string)$asset['asset_id']) ?>
                            &nbsp;·&nbsp;
                            <strong>Device:</strong> <?= htmlspecialchars(trim(($asset['brand'] ?? '') . ' ' . ($asset['model'] ?? '')) ?: 'Unknown Device') ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($assetId <= 0): ?>
                    <div class="hint">Open this page with <strong>?asset_id=</strong> from a Faulty asset in the laptop list.</div>
                <?php endif; ?>

                <?php if (!empty($success_message)): ?>
                    <div class="okalert"><?= htmlspecialchars($success_message) ?></div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="alert">
                        <?= htmlspecialchars($error_message) ?>
                        <?php if ($asset && $latestWarranty && repair_warranty_is_active($latestWarranty)): ?>
                            <div style="margin-top:.75rem">
                                <a href="warranty.php?asset_id=<?= (int)$assetId ?>" class="btn btn-primary" style="text-decoration:none;display:inline-flex">Open warranty claim</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php
                $formOk = $asset && (int)$asset['status_id'] === 6
                    && (!$latestWarranty || !repair_warranty_is_active($latestWarranty))
                    && empty($error_message)
                    && !$savedOk;
                ?>

                <?php if ($savedOk && $asset): ?>
                    <div class="hint">
                        Asset status was updated. <a href="laptop.php" style="color:var(--primary);font-weight:700">Back to laptop inventory</a>
                    </div>
                <?php endif; ?>

                <?php if ($formOk): ?>
                    <div class="hint">
                        Leave <strong>Completed date</strong> empty if work is ongoing — status becomes <strong>Maintenance</strong>.
                        If you set completed date, status returns to <strong>Active</strong>.
                    </div>
                <?php endif; ?>

                <form action="" method="post">
                    <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">

                    <div class="form-grid">
                        <div>
                            <div class="section-title">Repair details</div>
                            <div class="form-row-inline">
                                <div class="form-group">
                                    <label class="form-label" for="repair_date">Repair date</label>
                                    <input type="date" id="repair_date" name="repair_date" class="form-control" required
                                           value="<?= isset($_POST['repair_date']) ? htmlspecialchars((string)$_POST['repair_date']) : '' ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="completed_date">Completed date (optional)</label>
                                    <input type="date" id="completed_date" name="completed_date" class="form-control"
                                           value="<?= isset($_POST['completed_date']) ? htmlspecialchars((string)$_POST['completed_date']) : '' ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="issue_summary">Issue summary</label>
                                <input type="text" id="issue_summary" name="issue_summary" class="form-control" required
                                       placeholder="e.g. SSD replaced, fan cleaned"
                                       value="<?= isset($_POST['issue_summary']) ? htmlspecialchars((string)$_POST['issue_summary']) : '' ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="repair_remarks">Remarks (optional)</label>
                                <textarea id="repair_remarks" name="repair_remarks" class="form-textarea"
                                          placeholder="Parts used, tests performed..."><?= isset($_POST['repair_remarks']) ? htmlspecialchars((string)$_POST['repair_remarks']) : '' ?></textarea>
                            </div>
                        </div>

                        <div>
                            <div class="section-title">Recent in-house repairs</div>
                            <?php if (empty($recentRepairs)): ?>
                                <div class="hint">No repair records yet for this asset.</div>
                            <?php else: ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Issue</th>
                                            <th>By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentRepairs as $r): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string)$r['repair_date']) ?><?= !empty($r['completed_date']) ? ' → ' . htmlspecialchars((string)$r['completed_date']) : '' ?></td>
                                                <td><?= htmlspecialchars((string)$r['issue_summary']) ?></td>
                                                <td><?= htmlspecialchars((string)$r['staff_id']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-footer">
                        <div style="color:var(--text-muted);font-size:.9rem">
                            Logged in as: <strong><?= htmlspecialchars((string)($_SESSION['user_name'] ?? $_SESSION['staff_id'] ?? 'Technician')) ?></strong>
                        </div>
                        <div>
                            <button type="button" class="btn btn-ghost" onclick="window.location.href='laptop.php?status_id=6'">Cancel</button>
                            <?php if ($formOk): ?>
                                <button type="submit" class="btn btn-primary"><i class="ri-check-line"></i> Save repair</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        function toggleDropdown(element, event) {
            event.preventDefault();
            const group = element.closest('.nav-group');
            const dropdown = group.querySelector('.nav-dropdown');
            element.classList.toggle('open');
            if (dropdown) dropdown.classList.toggle('show');
        }
    </script>
</body>
</html>
