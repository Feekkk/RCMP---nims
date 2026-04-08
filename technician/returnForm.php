<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

const DEPLOY_STATUS_ID = 3;
const DEFAULT_RETURN_STATUS_ID = 1; // Active
const DEFAULT_RETURN_PLACE = 'ITD office';

$pdo = db();

$handoverReturnHasPlaceColumn = false;
$handoverReturnHasConditionColumn = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM `handover_return` LIKE 'handover_id'");
    $handoverReturnHasPlaceColumn = (bool) $col->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $handoverReturnHasPlaceColumn = false;
}
try {
    $col = $pdo->query("SHOW COLUMNS FROM `handover_return` LIKE 'condition'");
    $handoverReturnHasConditionColumn = (bool) $col->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $handoverReturnHasConditionColumn = false;
}

$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : (isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0);

$error_message = '';

$laptop = null;
$latestHandover = null;
$latestRecipient = null;
$returnAlreadyRecorded = false;
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

// Latest handover for asset; recipient only if that handover has a staff row (else place-only handover)
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

    $stmtHandover = $pdo->prepare("
        SELECT handover_id, handover_date, handover_remarks
        FROM handover
        WHERE asset_id = ?
        ORDER BY handover_date DESC, created_at DESC
        LIMIT 1
    ");
    $stmtHandover->execute([$assetId]);
    $latestHandover = $stmtHandover->fetch(PDO::FETCH_ASSOC);

    if ($latestHandover) {
        $stmtRecipient = $pdo->prepare("
            SELECT
                hs.handover_staff_id,
                hs.employee_no,
                st.full_name AS recipient_name,
                st.department AS recipient_dept
            FROM handover_staff hs
            JOIN staff st ON st.employee_no = hs.employee_no
            WHERE hs.handover_id = ?
            ORDER BY hs.handover_staff_id DESC
            LIMIT 1
        ");
        $stmtRecipient->execute([(int)$latestHandover['handover_id']]);
        $latestRecipient = $stmtRecipient->fetch(PDO::FETCH_ASSOC);
    }

    if ($laptop && (int)$laptop['status_id'] !== DEPLOY_STATUS_ID) {
        $error_message = 'Return can only be recorded when asset status is Deploy.';
    } elseif ($laptop && (int)$laptop['status_id'] === DEPLOY_STATUS_ID && $latestHandover) {
        if ($latestRecipient) {
            $chk = $pdo->prepare('SELECT 1 FROM handover_return WHERE handover_staff_id = ? LIMIT 1');
            $chk->execute([(int)$latestRecipient['handover_staff_id']]);
            $returnAlreadyRecorded = (bool) $chk->fetchColumn();
        } elseif ($handoverReturnHasPlaceColumn) {
            $chk = $pdo->prepare('SELECT 1 FROM handover_return WHERE handover_id = ? AND handover_staff_id IS NULL LIMIT 1');
            $chk->execute([(int)$latestHandover['handover_id']]);
            $returnAlreadyRecorded = (bool) $chk->fetchColumn();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnDate = isset($_POST['return_date']) ? trim((string)$_POST['return_date']) : '';
    $returnTime = isset($_POST['return_time']) ? trim((string)$_POST['return_time']) : '';
    $returnPlace = trim((string)($_POST['return_place'] ?? ''));
    if ($returnPlace === '') {
        $returnPlace = DEFAULT_RETURN_PLACE;
    }
    $returnCondition = isset($_POST['return_condition']) ? trim((string)$_POST['return_condition']) : '';
    $returnRemarks = isset($_POST['return_remarks']) ? trim((string)$_POST['return_remarks']) : '';
    $returnStatusId = isset($_POST['return_status_id']) ? (int)$_POST['return_status_id'] : DEFAULT_RETURN_STATUS_ID;

    $sessionStaffId = isset($_SESSION['staff_id']) ? trim((string)$_SESSION['staff_id']) : '';

    if ($assetId <= 0) {
        $error_message = 'Invalid asset ID.';
    } elseif (!$laptop) {
        $error_message = 'Asset not found.';
    } elseif ((int)$laptop['status_id'] !== DEPLOY_STATUS_ID) {
        $error_message = 'Return can only be recorded when asset status is Deploy.';
    } elseif ($returnDate === '') {
        $error_message = 'Return date is required.';
    } elseif ($sessionStaffId === '') {
        $error_message = 'Technician not found in session. Please log in again.';
    } elseif (!$latestHandover) {
        $error_message = 'No handover record found for this asset.';
    } elseif (!$latestRecipient && !$handoverReturnHasPlaceColumn) {
        $error_message = 'Place-based returns need the database updated: run db/migrate_handover_return_place.sql (adds handover_id to handover_return).';
    } elseif ($returnAlreadyRecorded) {
        $error_message = 'Return has already been recorded for this deployment.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtLockLaptop = $pdo->prepare('SELECT status_id FROM laptop WHERE asset_id = ? FOR UPDATE');
            $stmtLockLaptop->execute([$assetId]);
            $rowLock = $stmtLockLaptop->fetch(PDO::FETCH_ASSOC);

            if (!$rowLock) {
                throw new RuntimeException('Asset not found.');
            }

            if ((int)$rowLock['status_id'] !== DEPLOY_STATUS_ID) {
                throw new RuntimeException('Asset is no longer in Deploy status.');
            }

            $stmtH = $pdo->prepare("
                SELECT handover_id
                FROM handover
                WHERE asset_id = ?
                ORDER BY handover_date DESC, created_at DESC
                LIMIT 1
            ");
            $stmtH->execute([$assetId]);
            $rowH = $stmtH->fetch(PDO::FETCH_ASSOC);
            if (!$rowH) {
                throw new RuntimeException('No handover record found for this asset.');
            }
            $handoverId = (int) $rowH['handover_id'];

            $stmtR = $pdo->prepare("
                SELECT handover_staff_id
                FROM handover_staff
                WHERE handover_id = ?
                ORDER BY handover_staff_id DESC
                LIMIT 1
            ");
            $stmtR->execute([$handoverId]);
            $rowR = $stmtR->fetch(PDO::FETCH_ASSOC);

            $handoverStaffId = $rowR ? (int) $rowR['handover_staff_id'] : null;

            if ($handoverStaffId !== null) {
                $dup = $pdo->prepare('SELECT 1 FROM handover_return WHERE handover_staff_id = ? LIMIT 1');
                $dup->execute([$handoverStaffId]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException('Return already recorded for this handover recipient.');
                }
            } elseif ($handoverReturnHasPlaceColumn) {
                $dup = $pdo->prepare('SELECT 1 FROM handover_return WHERE handover_id = ? AND handover_staff_id IS NULL LIMIT 1');
                $dup->execute([$handoverId]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException('Return already recorded for this place handover.');
                }
            }

            $newReturnId = 0;
            if ($handoverReturnHasPlaceColumn) {
                $stmtInsertReturn = $pdo->prepare('
                    INSERT INTO handover_return
                        (handover_staff_id, handover_id, returned_by, return_date, return_time, return_place, `condition`, return_remarks, return_status_id)
                    VALUES
                        (:handover_staff_id, :handover_id, :returned_by, :return_date, :return_time, :return_place, :condition, :return_remarks, :return_status_id)
                ');
                $stmtInsertReturn->execute([
                    ':handover_staff_id' => $handoverStaffId,
                    ':handover_id' => $handoverStaffId !== null ? null : $handoverId,
                    ':returned_by' => $sessionStaffId,
                    ':return_date' => $returnDate,
                    ':return_time' => $returnTime !== '' ? $returnTime : null,
                    ':return_place' => $returnPlace,
                    ':condition' => ($handoverReturnHasConditionColumn && $returnCondition !== '') ? $returnCondition : null,
                    ':return_remarks' => $returnRemarks !== '' ? $returnRemarks : null,
                    ':return_status_id' => $returnStatusId,
                ]);
                $newReturnId = (int) $pdo->lastInsertId();
            } else {
                $stmtInsertReturn = $pdo->prepare('
                    INSERT INTO handover_return
                        (handover_staff_id, returned_by, return_date, return_time, return_place, `condition`, return_remarks, return_status_id)
                    VALUES
                        (:handover_staff_id, :returned_by, :return_date, :return_time, :return_place, :condition, :return_remarks, :return_status_id)
                ');
                $stmtInsertReturn->execute([
                    ':handover_staff_id' => $handoverStaffId,
                    ':returned_by' => $sessionStaffId,
                    ':return_date' => $returnDate,
                    ':return_time' => $returnTime !== '' ? $returnTime : null,
                    ':return_place' => $returnPlace,
                    ':condition' => ($handoverReturnHasConditionColumn && $returnCondition !== '') ? $returnCondition : null,
                    ':return_remarks' => $returnRemarks !== '' ? $returnRemarks : null,
                    ':return_status_id' => $returnStatusId,
                ]);
                $newReturnId = (int) $pdo->lastInsertId();
            }

            $stmtUpdateAsset = $pdo->prepare('
                UPDATE laptop
                SET status_id = :status_id
                WHERE asset_id = :asset_id
            ');
            $stmtUpdateAsset->execute([
                ':status_id' => $returnStatusId,
                ':asset_id' => $assetId,
            ]);

            $pdo->commit();

            $flashType = 'ok';
            $flashMsg = 'Return recorded.';
            $stmtTech = $pdo->prepare('SELECT full_name, email FROM users WHERE staff_id = ? LIMIT 1');
            $stmtTech->execute([$sessionStaffId]);
            $techRow = $stmtTech->fetch(PDO::FETCH_ASSOC);
            $techEmail = trim((string) ($techRow['email'] ?? ''));
            $techName = trim((string) ($techRow['full_name'] ?? ''));
            if ($techEmail !== '' && filter_var($techEmail, FILTER_VALIDATE_EMAIL) && $newReturnId > 0) {
                try {
                    require_once __DIR__ . '/../services/returnPDF.php';
                    return_mail_pdf_to_technician(
                        $newReturnId,
                        $techEmail,
                        $techName !== '' ? $techName : $sessionStaffId
                    );
                    $flashMsg .= ' The return form PDF was emailed to you.';
                } catch (Throwable $mailEx) {
                    error_log('NIMS return PDF email failed: ' . $mailEx->getMessage());
                    $flashType = 'warning';
                    $detail = $mailEx->getMessage();
                    if (strlen($detail) > 220) {
                        $detail = substr($detail, 0, 217) . '...';
                    }
                    $flashMsg .= ' Could not email the PDF: ' . $detail;
                }
            } elseif ($techEmail !== '' && filter_var($techEmail, FILTER_VALIDATE_EMAIL) && $newReturnId <= 0) {
                $flashType = 'warning';
                $flashMsg .= ' Could not email the PDF (return record id missing — check database).';
            } else {
                $flashType = 'warning';
                $flashMsg .= ' No valid email on your profile — PDF was not sent.';
            }
            $_SESSION['laptop_flash'] = ['type' => $flashType, 'msg' => $flashMsg];

            header('Location: laptop.php?status_id=' . (int) $returnStatusId);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ((int) $e->getCode() === 23000) {
                $error_message = 'Return already recorded for this deployment.';
            } else {
                $error_message = 'Database error: ' . $e->getMessage();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

$placeReturnNeedsMigration = $latestHandover && !$latestRecipient && !$handoverReturnHasPlaceColumn;

$canSubmitReturn = $laptop
    && (int) $laptop['status_id'] === DEPLOY_STATUS_ID
    && $latestHandover
    && !$returnAlreadyRecorded
    && !$placeReturnNeedsMigration;

$fieldReturnDate = isset($_POST['return_date']) ? trim((string) $_POST['return_date']) : '';
$fieldReturnTime = isset($_POST['return_time']) ? trim((string) $_POST['return_time']) : '';
$rp = isset($_POST['return_place']) ? trim((string) $_POST['return_place']) : '';
$fieldReturnPlace = $rp !== '' ? $rp : DEFAULT_RETURN_PLACE;
$fieldReturnCondition = isset($_POST['return_condition']) ? trim((string) $_POST['return_condition']) : '';
$fieldReturnRemarks = isset($_POST['return_remarks']) ? trim((string) $_POST['return_remarks']) : '';
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

                    <div class="form-grid">
                        <div>
                            <div class="section-title">Deployment context</div>

                            <?php if ($returnAlreadyRecorded): ?>
                                <div class="form-group">
                                    <div class="alert" style="background:rgba(245,158,11,0.12);border-color:rgba(245,158,11,0.35);color:#f59e0b">
                                        A return is already recorded for this deployment.
                                    </div>
                                </div>
                            <?php elseif ($latestRecipient): ?>
                                <div class="form-group">
                                    <label class="form-label">Return from (staff)</label>
                                    <p class="footer-note" style="margin-bottom:0.75rem">Handover included a staff recipient.</p>
                                </div>
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
                            <?php elseif ($latestHandover && !$handoverReturnHasPlaceColumn): ?>
                                <div class="form-group">
                                    <div class="alert">
                                        This deployment is a <strong>place</strong> handover (no staff recipient). Your database table <code>handover_return</code> is missing column <code>handover_id</code>.
                                        Run <code>db/migrate_handover_return_place.sql</code> (adjust the <code>DROP FOREIGN KEY</code> name if needed), then reload this page.
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Handover date</label>
                                    <input class="form-control" value="<?= htmlspecialchars((string)$latestHandover['handover_date']) ?>" disabled>
                                </div>
                            <?php elseif ($latestHandover): ?>
                                <div class="form-group">
                                    <label class="form-label">Return from (place)</label>
                                    <p class="footer-note" style="margin-bottom:0.75rem">
                                        Latest handover had no staff recipient (deployed to a place). Record where the unit was collected and optional notes.
                                    </p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Handover date</label>
                                    <input class="form-control" value="<?= htmlspecialchars((string)$latestHandover['handover_date']) ?>" disabled>
                                </div>
                                <?php if (($latestHandover['handover_remarks'] ?? '') !== ''): ?>
                                    <div class="form-group">
                                        <label class="form-label">Handover notes</label>
                                        <textarea class="form-textarea" style="min-height:72px" disabled><?= htmlspecialchars((string)$latestHandover['handover_remarks']) ?></textarea>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="form-group">
                                    <div class="alert" style="background:rgba(245,158,11,0.12);border-color:rgba(245,158,11,0.35);color:#f59e0b">
                                        No handover record found for this asset.
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
                                        value="<?= htmlspecialchars($fieldReturnDate) ?>"
                                    >
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="return_time">Return Time</label>
                                    <input
                                        type="time"
                                        id="return_time"
                                        name="return_time"
                                        class="form-control"
                                        value="<?= htmlspecialchars($fieldReturnTime) ?>"
                                    >
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="return_place">Return place</label>
                                <input
                                    type="text"
                                    id="return_place"
                                    name="return_place"
                                    class="form-control"
                                    required
                                    placeholder="Defaults to <?= htmlspecialchars(DEFAULT_RETURN_PLACE) ?> if cleared"
                                    value="<?= htmlspecialchars($fieldReturnPlace) ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="return_condition">Asset condition</label>
                                <select id="return_condition" name="return_condition" class="form-select">
                                    <?php
                                        $cond = $fieldReturnCondition !== '' ? $fieldReturnCondition : 'Good';
                                        $opts = ['Good', 'Fair', 'Damaged', 'Missing accessories', 'Other'];
                                        foreach ($opts as $opt):
                                    ?>
                                        <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>" <?= $opt === $cond ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($opt) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$handoverReturnHasConditionColumn): ?>
                                    <div class="footer-note" style="margin-top:0.5rem;color:var(--warning)">
                                        Database column <code>handover_return.condition</code> not found yet. Run the updated <code>db/schema.sql</code> (or migration) to save this field.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="return_remarks">Asset remarks</label>
                                <textarea
                                    id="return_remarks"
                                    name="return_remarks"
                                    class="form-textarea"
                                    placeholder="Optional — condition, accessories, etc."
                                ><?= htmlspecialchars($fieldReturnRemarks) ?></textarea>
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
                            Logged in as: <strong><?= htmlspecialchars((string)($_SESSION['user_name'] ?? $_SESSION['staff_id'] ?? 'Technician')) ?></strong>.
                            Date and place are required; place defaults to <?= htmlspecialchars(DEFAULT_RETURN_PLACE) ?> if empty. Time and remarks are optional.
                        </div>
                        <div>
                            <button type="button" class="btn btn-ghost" onclick="window.location.href='laptop.php?status_id=<?= (int)DEPLOY_STATUS_ID ?>'">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" <?= $canSubmitReturn ? '' : 'disabled' ?>>
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

