<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

const DEPLOY_STATUS_ID = 3;
const DEFAULT_RETURN_STATUS_ID = 1; // Active

$pdo = db();
$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : (isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0);

$error_message = '';

$laptop = null;
$latestRecipient = null;
$statusOptions = [];

try {
    // For the "return status" dropdown (exclude deploy and offline/online)
    $statusOptions = $pdo->query("
        SELECT status_id, name
        FROM status
        WHERE status_id IN (1,2,4,5,6,7,8)
        ORDER BY status_id
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $statusOptions = [];
}

// Fetch laptop + current recipient (who last received it in the latest handover)
if ($assetId > 0) {
    $stmtLaptop = $pdo->prepare("
        SELECT l.asset_id, l.serial_num, l.brand, l.model, l.status_id, s.name AS status_name
        FROM laptop l
        JOIN status s ON s.status_id = l.status_id
        WHERE l.asset_id = ?
        LIMIT 1
    ");
    $stmtLaptop->execute([$assetId]);
    $laptop = $stmtLaptop->fetch(PDO::FETCH_ASSOC);

    $stmtRecipient = $pdo->prepare("
        SELECT
            hs.handover_staff_id,
            hs.employee_no,
            st.full_name AS recipient_name,
            st.department AS recipient_dept
        FROM handover h
        JOIN handover_staff hs ON hs.handover_id = h.handover_id
        JOIN staff st ON st.employee_no = hs.employee_no
        WHERE h.asset_id = ?
        ORDER BY h.handover_date DESC, h.created_at DESC, hs.handover_staff_id DESC
        LIMIT 1
    ");
    $stmtRecipient->execute([$assetId]);
    $latestRecipient = $stmtRecipient->fetch(PDO::FETCH_ASSOC);

    if ($laptop && (int)$laptop['status_id'] !== DEPLOY_STATUS_ID) {
        $error_message = 'Return can only be recorded when asset status is Deploy.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnDate = isset($_POST['return_date']) ? trim((string)$_POST['return_date']) : '';
    $returnTime = isset($_POST['return_time']) ? trim((string)$_POST['return_time']) : '';
    $returnPlace = isset($_POST['return_place']) ? trim((string)$_POST['return_place']) : '';
    $returnRemarks = isset($_POST['return_remarks']) ? trim((string)$_POST['return_remarks']) : '';
    $returnStatusId = isset($_POST['return_status_id']) ? (int)$_POST['return_status_id'] : DEFAULT_RETURN_STATUS_ID;

    $sessionStaffId = isset($_SESSION['staff_id']) ? trim((string)$_SESSION['staff_id']) : '';

    if ($assetId <= 0) {
        $error_message = 'Invalid asset ID.';
    } elseif (!$laptop) {
        $error_message = 'Asset not found.';
    } elseif ((int)$laptop['status_id'] !== DEPLOY_STATUS_ID) {
        $error_message = 'Return can only be recorded when asset status is Deploy.';
    } elseif ($latestRecipient && empty($returnDate)) {
        $error_message = 'Missing return date.';
    } elseif ($latestRecipient && empty($returnTime)) {
        $error_message = 'Missing return time.';
    } elseif (empty($returnRemarks)) {
        $error_message = 'Missing return remarks.';
    } elseif ($sessionStaffId === '') {
        $error_message = 'Technician not found in session. Please log in again.';
    } elseif (!$latestRecipient) {
        $error_message = 'No handover recipient found for this asset.';
    } else {
        $handoverStaffId = (int)$latestRecipient['handover_staff_id'];

        try {
            $pdo->beginTransaction();

            // Re-check current state inside transaction
            // asset_id is the PK, so we don't need LIMIT. Use FOR UPDATE for concurrency safety.
            $stmtLockLaptop = $pdo->prepare('SELECT status_id FROM laptop WHERE asset_id = ? FOR UPDATE');
            $stmtLockLaptop->execute([$assetId]);
            $rowLock = $stmtLockLaptop->fetch(PDO::FETCH_ASSOC);

            if (!$rowLock) {
                throw new RuntimeException('Asset not found.');
            }

            if ((int)$rowLock['status_id'] !== DEPLOY_STATUS_ID) {
                throw new RuntimeException('Asset is no longer in Deploy status.');
            }

            // Insert return record (UNIQUE(handover_staff_id) will prevent duplicates)
            $stmtInsertReturn = $pdo->prepare("
                INSERT INTO handover_return
                    (handover_staff_id, returned_by, return_date, return_time, return_place, return_remarks, return_status_id)
                VALUES
                    (:handover_staff_id, :returned_by, :return_date, :return_time, :return_place, :return_remarks, :return_status_id)
            ");
            $stmtInsertReturn->execute([
                ':handover_staff_id' => $handoverStaffId,
                ':returned_by' => $sessionStaffId,
                ':return_date' => $returnDate,
                ':return_time' => $returnTime,
                ':return_place' => $returnPlace !== '' ? $returnPlace : null,
                ':return_remarks' => $returnRemarks,
                ':return_status_id' => $returnStatusId,
            ]);

            // Update asset status after return
            $stmtUpdateAsset = $pdo->prepare("
                UPDATE laptop
                SET status_id = :status_id
                WHERE asset_id = :asset_id
            ");
            $stmtUpdateAsset->execute([
                ':status_id' => $returnStatusId,
                ':asset_id' => $assetId,
            ]);

            $pdo->commit();
            header('Location: laptop.php?status_id=' . (int)$returnStatusId);
            exit;
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ((int)$e->getCode() === 23000) {
                $error_message = 'Return already recorded for this handover recipient.';
            } else {
                $error_message = 'Database error: ' . $e->getMessage();
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laptop Return Form - RCMP NIMS</title>
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
            --glass-panel:#f8fafc; --warning:#f59e0b;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{
            font-family:'Inter',sans-serif;background:var(--bg);color:var(--text-main);
            min-height:100vh;display:flex;overflow-x:hidden;
        }
        .main-content{margin-left:280px;max-width:100vw;padding:1rem 1.5rem;width:100%}
        .wrapper{max-width:1100px;margin:0 auto}
        .sidebar{width:280px;flex:0 0 280px;position:fixed;left:0;top:0;height:100vh;
            background:var(--glass-panel);border-right:1px solid var(--card-border);padding:1.5rem;z-index:100}
        .sidebar-logo{padding-bottom:2rem;border-bottom:1px solid var(--card-border);margin-bottom:2rem;display:flex;align-items:center;justify-content:center}
        .sidebar-logo img{height:45px}
        .nav-menu{display:flex;flex-direction:column;gap:.75rem;flex:1}
        .nav-item{padding:.85rem 1.25rem;border-radius:12px;color:var(--text-muted);text-decoration:none;display:flex;align-items:center;gap:1rem;font-weight:500;transition:all .3s}
        .nav-item:hover{color:var(--primary);background:rgba(37,99,235,0.06)}
        .nav-item.active{color:var(--primary);background:rgba(37,99,235,0.1);border:1px solid rgba(37,99,235,0.2);box-shadow:inset 3px 0 0 var(--primary)}
        .nav-dropdown{display:none;flex-direction:column;margin-left:1rem;gap:.4rem}
        .nav-dropdown.show{display:flex}
        .nav-dropdown-item{padding:.5rem .75rem;border-radius:10px;text-decoration:none;color:var(--text-muted)}
        .nav-dropdown-item.active{background:rgba(37,99,235,0.12);color:var(--primary)}

        .card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:16px;padding:1.8rem;box-shadow:0 10px 25px rgba(15,23,42,0.05)}
        .card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.2rem}
        .card-title{font-size:1.25rem;font-weight:800;display:flex;align-items:center;gap:.6rem}
        .card-subtitle{margin-top:.35rem;color:var(--text-muted);font-size:.95rem}
        .device-summary{color:var(--text-muted);font-size:.95rem}
        .alert{margin-bottom:1.2rem;padding:1rem 1.25rem;border-radius:14px;border:1px solid rgba(239,68,68,0.35);background:rgba(239,68,68,0.12);color:#ef4444;font-size:.95rem}

        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem}
        .section-title{font-weight:800;color:var(--text-main);margin-bottom:.75rem;border-bottom:1px solid var(--card-border);padding-bottom:.5rem}
        .form-group{margin-bottom:1.1rem}
        .form-label{display:block;font-size:.85rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:.5rem}
        .form-control, .form-select, .form-textarea{
            width:100%;background:var(--input-bg);border:1px solid var(--input-border);border-radius:12px;
            padding:.75rem .9rem;font-family:'Inter',sans-serif;color:var(--text-main);outline:none
        }
        .form-control:focus, .form-select:focus, .form-textarea:focus{border-color:var(--primary);box-shadow:0 0 0 4px rgba(37,99,235,0.08);background:#fff}
        .form-row-inline{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
        .form-textarea{min-height:120px;resize:vertical}

        .form-footer{margin-top:1.4rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .btn{border:none;border-radius:12px;padding:.75rem 1rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:.5rem}
        .btn-primary{background:var(--primary);color:#fff;box-shadow:0 10px 20px rgba(37,99,235,0.25)}
        .btn-primary:hover{filter:brightness(0.98)}
        .btn-ghost{background:transparent;color:var(--text-muted);border:1px solid var(--card-border)}
        .btn-ghost:hover{color:var(--primary);border-color:rgba(37,99,235,0.25)}
        .footer-note{color:var(--text-muted);font-size:.9rem}
        .back-link{display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--text-muted);margin-bottom:1rem}
        .back-link:hover{color:var(--primary)}

        @media (max-width: 980px){
            .main-content{margin-left:0;padding:1rem}
            .sidebar{transform:translateX(-100%)}
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
        <div class="wrapper">
            <a href="laptop.php?status_id=<?= (int)DEPLOY_STATUS_ID ?>" class="back-link">
                <i class="ri-arrow-left-line"></i> Back to Deploy Assets
            </a>

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">
                            <i class="ri-arrow-go-back-line"></i>
                            Laptop Return Form
                        </div>
                        <div class="card-subtitle">
                            Record the return details after deployment.
                        </div>
                    </div>
                    <?php if ($laptop): ?>
                        <div class="device-summary">
                            <strong>Asset ID:</strong> <?= htmlspecialchars((string)$laptop['asset_id']) ?>
                            &nbsp;·&nbsp;
                            <strong>Device:</strong> <?= htmlspecialchars(trim(($laptop['brand'] ?? '') . ' ' . ($laptop['model'] ?? '')) ?: 'Unknown Device') ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($error_message)): ?>
                    <div class="alert"><?= htmlspecialchars($error_message) ?></div>
                <?php endif; ?>

                <form action="" method="post">
                    <input type="hidden" name="asset_id" value="<?= htmlspecialchars((string)$assetId) ?>">
                    <?php if ($latestRecipient): ?>
                        <input type="hidden" name="handover_staff_id" value="<?= (int)$latestRecipient['handover_staff_id'] ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div>
                            <div class="section-title">Returned Recipient</div>

                            <?php if ($latestRecipient): ?>
                                <div class="form-group">
                                    <label class="form-label">Staff ID</label>
                                    <input class="form-control" value="<?= htmlspecialchars((string)$latestRecipient['employee_no']) ?>" disabled>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Full Name</label>
                                    <input class="form-control" value="<?= htmlspecialchars((string)$latestRecipient['recipient_name']) ?>" disabled>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Department</label>
                                    <input class="form-control" value="<?= htmlspecialchars((string)($latestRecipient['recipient_dept'] ?? '—')) ?>" disabled>
                                </div>
                            <?php else: ?>
                                <div class="form-group">
                                    <div class="alert" style="background:rgba(245,158,11,0.12);border-color:rgba(245,158,11,0.35);color:#f59e0b">
                                        No recipient found for this asset.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div>
                            <div class="section-title">Return Details</div>

                            <div class="form-row-inline">
                                <div class="form-group">
                                    <label class="form-label" for="return_date">Return Date</label>
                                    <input
                                        type="date"
                                        id="return_date"
                                        name="return_date"
                                        class="form-control"
                                        required
                                        value="<?= isset($_POST['return_date']) ? htmlspecialchars((string)$_POST['return_date']) : '' ?>"
                                    >
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="return_time">Return Time</label>
                                    <input
                                        type="time"
                                        id="return_time"
                                        name="return_time"
                                        class="form-control"
                                        required
                                        value="<?= isset($_POST['return_time']) ? htmlspecialchars((string)$_POST['return_time']) : '' ?>"
                                    >
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="return_place">Return Place (optional)</label>
                                <input
                                    type="text"
                                    id="return_place"
                                    name="return_place"
                                    class="form-control"
                                    placeholder="e.g. UniKL RCMP IT Counter"
                                    value="<?= isset($_POST['return_place']) ? htmlspecialchars((string)$_POST['return_place']) : '' ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="return_remarks">Asset Remarks (required)</label>
                                <textarea
                                    id="return_remarks"
                                    name="return_remarks"
                                    class="form-textarea"
                                    placeholder="e.g. Charger and bag included, screen OK"
                                    required><?= isset($_POST['return_remarks']) ? htmlspecialchars((string)$_POST['return_remarks']) : '' ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="return_status_id">Status After Return</label>
                                <select id="return_status_id" name="return_status_id" class="form-select">
                                    <?php
                                        $selectedReturnStatusId = isset($_POST['return_status_id']) ? (int)$_POST['return_status_id'] : DEFAULT_RETURN_STATUS_ID;
                                        foreach ($statusOptions as $st):
                                            $sidOpt = (int)$st['status_id'];
                                    ?>
                                        <option value="<?= $sidOpt ?>" <?= $sidOpt === $selectedReturnStatusId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string)$st['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-footer">
                        <div class="footer-note">
                            Logged in as: <strong><?= htmlspecialchars((string)($_SESSION['user_name'] ?? $_SESSION['staff_id'] ?? 'Technician')) ?></strong>
                        </div>
                        <div>
                            <button type="button" class="btn btn-ghost" onclick="window.location.href='laptop.php?status_id=<?= (int)DEPLOY_STATUS_ID ?>'">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="ri-check-line"></i> Complete Return
                            </button>
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

