<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)$_SESSION['role_id'] !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$DEPLOY_STATUS_ID = 3;

$assetId = isset($_POST['asset_id'])
    ? trim((string)$_POST['asset_id'])
    : (isset($_GET['asset_id']) ? trim((string)$_GET['asset_id']) : '');

// Fetch laptop basic info (optional, for context)
$laptop = null;
if ($assetId !== '') {
    $stmt = db()->prepare('SELECT asset_id, brand, model, serial_num FROM laptop WHERE asset_id = ?');
    $stmt->execute([$assetId]);
    $laptop = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $str = fn(string $k): ?string => isset($_POST[$k]) && $_POST[$k] !== '' ? trim((string)$_POST[$k]) : null;
    $date = fn(string $k): ?string => isset($_POST[$k]) && $_POST[$k] !== '' ? trim((string)$_POST[$k]) : null;

    $asset_id = isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0;
    $receiver_staff_id = $str('receiver_staff_id') ?? '';
    $receiver_designation = $str('receiver_designation') ?? '';
    $handover_date = $date('handover_date');
    $handover_time = $str('handover_time') ?? '';
    $handover_place = trim((string)($str('handover_place') ?? ''));
    if ($handover_place === '') {
        $handover_place = 'ITD office';
    }

    $technician_staff_id = $str('technician_staff_id') ?? '';
    $session_staff_id = isset($_SESSION['staff_id']) ? trim((string)$_SESSION['staff_id']) : '';

    $receiver_email = $str('receiver_email') ?? '';
    $receiver_phone = $str('receiver_phone') ?? '';
    $receiver_name = $str('receiver_name') ?? '';

    $error_message = '';

    if (!$asset_id || !$handover_date) {
        $error_message = 'Date is required.';
    } elseif ($technician_staff_id === '' || $technician_staff_id !== $session_staff_id) {
        $error_message = 'Technician identity mismatch; please log in again.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $stmtAsset = $pdo->prepare('SELECT asset_id, status_id FROM laptop WHERE asset_id = ? LIMIT 1');
            $stmtAsset->execute([$asset_id]);
            $assetRow = $stmtAsset->fetch(PDO::FETCH_ASSOC);
            if (!$assetRow) {
                throw new RuntimeException('Asset not found.');
            }

            $receiverRow = null;
            $stmtReceiver = $pdo->prepare('
                SELECT employee_no, full_name, department, email, phone
                FROM staff
                WHERE employee_no = ?
                LIMIT 1
            ');
            if ($receiver_staff_id !== '') {
                $stmtReceiver->execute([$receiver_staff_id]);
                $receiverRow = $stmtReceiver->fetch(PDO::FETCH_ASSOC);
                if (!$receiverRow) {
                    if ($receiver_name === '') {
                        throw new RuntimeException(
                            'This Staff ID is not in the directory yet. Enter the receiver full name and optional details below, then submit to add them and record the handover.'
                        );
                    }
                    $stmtInsertStaff = $pdo->prepare('
                        INSERT INTO staff (employee_no, full_name, department, email, phone)
                        VALUES (:employee_no, :full_name, :department, :email, :phone)
                    ');
                    try {
                        $stmtInsertStaff->execute([
                            ':employee_no' => $receiver_staff_id,
                            ':full_name' => $receiver_name,
                            ':department' => $receiver_designation !== '' ? $receiver_designation : null,
                            ':email' => $receiver_email !== '' ? $receiver_email : null,
                            ':phone' => $receiver_phone !== '' ? $receiver_phone : null,
                        ]);
                    } catch (PDOException $e) {
                        $ei = $e->errorInfo ?? [];
                        $dup = ($ei[0] ?? '') === '23000' || (int)($ei[1] ?? 0) === 1062;
                        if (!$dup) {
                            throw $e;
                        }
                    }
                    $stmtReceiver->execute([$receiver_staff_id]);
                    $receiverRow = $stmtReceiver->fetch(PDO::FETCH_ASSOC);
                    if (!$receiverRow) {
                        throw new RuntimeException('Could not load the receiver after saving to the staff directory.');
                    }
                }
            }

            $handover_remarks = $handover_place;
            if ($handover_time !== '') {
                $handover_remarks .= ' | Time: ' . trim((string)$handover_time);
            }
            if ($receiver_name !== '') {
                $handover_remarks .= ' | Receiver: ' . trim($receiver_name);
            }
            if ($receiver_designation !== '') {
                $handover_remarks .= ' | Designation: ' . trim($receiver_designation);
            }
            if ($receiver_email !== '' || $receiver_phone !== '') {
                $handover_remarks .= ' | Receiver Contact: ' . trim($receiver_email) . ' / ' . trim($receiver_phone);
            }

            $stmtHandover = $pdo->prepare('
                INSERT INTO handover (asset_id, staff_id, handover_date, handover_remarks)
                VALUES (:asset_id, :staff_id, :handover_date, :handover_remarks)
            ');
            $stmtHandover->execute([
                ':asset_id' => $asset_id,
                ':staff_id' => $session_staff_id,
                ':handover_date' => $handover_date,
                ':handover_remarks' => $handover_remarks,
            ]);
            $handover_id = (int)$pdo->lastInsertId();

            if ($receiverRow !== null) {
                $stmtHandoverStaff = $pdo->prepare('
                    INSERT INTO handover_staff (employee_no, handover_id)
                    VALUES (:employee_no, :handover_id)
                ');
                $stmtHandoverStaff->execute([
                    ':employee_no' => $receiver_staff_id,
                    ':handover_id' => $handover_id,
                ]);
            }

            $stmtUpdateAsset = $pdo->prepare('
                UPDATE laptop
                SET status_id = :status_id
                WHERE asset_id = :asset_id
            ');
            $stmtUpdateAsset->execute([
                ':status_id' => $DEPLOY_STATUS_ID,
                ':asset_id' => $asset_id,
            ]);

            $pdo->commit();
            header('Location: laptop.php?status_id=' . $DEPLOY_STATUS_ID);
            exit;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

$DEFAULT_HANDOVER_PLACE = 'ITD office';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $field_handover_date = trim((string)($_POST['handover_date'] ?? ''));
    $p = trim((string)($_POST['handover_place'] ?? ''));
    $field_handover_place = $p !== '' ? $p : $DEFAULT_HANDOVER_PLACE;
    $field_handover_time = trim((string)($_POST['handover_time'] ?? ''));
    $field_receiver_staff_id = trim((string)($_POST['receiver_staff_id'] ?? ''));
    $field_receiver_name = trim((string)($_POST['receiver_name'] ?? ''));
    $field_receiver_designation = trim((string)($_POST['receiver_designation'] ?? ''));
    $field_receiver_email = trim((string)($_POST['receiver_email'] ?? ''));
    $field_receiver_phone = trim((string)($_POST['receiver_phone'] ?? ''));
} else {
    $field_handover_date = '';
    $field_handover_place = $DEFAULT_HANDOVER_PLACE;
    $field_handover_time = '';
    $field_receiver_staff_id = '';
    $field_receiver_name = '';
    $field_receiver_designation = '';
    $field_receiver_email = '';
    $field_receiver_phone = '';
}

// Staff directory lookup for receiver auto-fill
$staffForLookup = [];
try {
    $staffForLookup = db()->query("
        SELECT employee_no, full_name, department
        FROM staff
        ORDER BY full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $staffForLookup = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laptop Handover Form - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --secondary: #0ea5e9;
            --bg: #f1f5f9;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --input-bg: #f8fafc;
            --input-border: #cbd5e1;
            --glass-panel: #f8fafc;
            --glass-border: #e2e8f0;
            --danger: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
        }

        .sidebar {
            width: 280px;
            height: 100vh;
            background: #ffffff;
            border-right: 1px solid var(--card-border);
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            padding: 1.5rem;
            z-index: 100;
            box-shadow: 4px 0 20px rgba(15,23,42,0.06);
            transition: transform 0.3s ease;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--glass-border);
            margin-bottom: 2rem;
        }

        .sidebar-logo img {
            height: 45px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            flex: 1;
        }

        .nav-item {
            padding: 0.85rem 1.25rem;
            border-radius: 12px;
            color: var(--text-muted);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-item i {
            font-size: 1.25rem;
            color: inherit;
        }

        .nav-item:hover {
            color: var(--primary);
            background: rgba(37, 99, 235, 0.06);
        }

        .nav-item.active {
            color: var(--primary);
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: inset 3px 0 0 var(--primary);
        }

        .nav-dropdown {
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            padding-left: 3.25rem;
            margin-top: -0.25rem;
            margin-bottom: 0.25rem;
        }

        .nav-dropdown.show {
            display: flex;
        }

        .nav-dropdown-item {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-dropdown-item::before {
            content: '';
            position: absolute;
            left: -1rem;
            top: 50%;
            width: 6px;
            height: 6px;
            background: var(--card-border);
            border-radius: 50%;
            transform: translateY(-50%);
            transition: all 0.3s ease;
        }

        .nav-dropdown-item:hover,
        .nav-dropdown-item.active {
            color: var(--primary);
            background: rgba(37,99,235,0.05);
        }

        .nav-dropdown-item:hover::before,
        .nav-dropdown-item.active::before {
            background: var(--primary);
        }

        .nav-item.open .chevron {
            transform: rotate(180deg);
        }

        .user-profile {
            margin-top: auto;
            padding: 1rem;
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.25s ease;
            cursor: pointer;
        }

        .user-profile:hover {
            background: rgba(37,99,235,0.06);
            border-color: rgba(37,99,235,0.2);
        }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            color: #fff;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
        }

        .user-info {
            flex: 1;
            overflow: hidden;
        }

        .user-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--primary);
            margin-top: 0.2rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .main-content {
            margin-left: 280px;
            flex: 1;
            max-width: calc(100vw - 280px);
            padding: 2rem 2.5rem 3rem;
        }

        .wrapper {
            width: 100%;
            max-width: 1020px;
            margin: 0 auto;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .back-link:hover {
            color: var(--primary);
        }

        .card {
            background: var(--card-bg);
            border-radius: 24px;
            border: 1px solid var(--card-border);
            box-shadow: 0 8px 30px rgba(15,23,42,0.08);
            padding: 2.2rem 2.4rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.8rem;
        }

        .card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.7rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .card-title i {
            color: var(--primary);
            font-size: 1.6rem;
        }

        .card-subtitle {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        .device-summary {
            padding: 0.9rem 1.1rem;
            border-radius: 16px;
            background: var(--input-bg);
            border: 1px dashed var(--card-border);
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .device-summary strong {
            color: var(--text-main);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.5rem 2rem;
            margin-top: 1.8rem;
        }

        .section-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.75rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 0.45rem;
        }

        .form-control {
            width: 100%;
            background: var(--input-bg);
            border-radius: 12px;
            border: 1px solid var(--input-border);
            padding: 0.7rem 0.9rem;
            font-size: 0.95rem;
            color: var(--text-main);
            outline: none;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }

        .form-row-inline {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .form-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--card-border);
            gap: 1rem;
            flex-wrap: wrap;
        }

        .footer-note {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .btn {
            padding: 0.8rem 1.6rem;
            border-radius: 999px;
            border: none;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            transition: all 0.25s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            box-shadow: 0 10px 25px rgba(37,99,235,0.35);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(37,99,235,0.45);
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
        }

        .btn-ghost:hover {
            color: var(--primary);
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1rem; }
            .card {
                padding: 1.6rem 1.4rem;
            }
            .form-grid {
                grid-template-columns: minmax(0, 1fr);
            }
            .form-row-inline {
                grid-template-columns: minmax(0, 1fr);
            }
            .card-header {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
    <div class="wrapper">
        <a href="laptop.php" class="back-link">
            <i class="ri-arrow-left-line"></i> Back to Laptop Inventory
        </a>

        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">
                        <i class="ri-exchange-line"></i>
                        Laptop Handover Form
                    </div>
                    <p class="card-subtitle">
                        Record the handover details between technician and receiver for asset tracking.
                    </p>
                </div>
                <?php if ($laptop): ?>
                    <div class="device-summary">
                        <strong>Asset ID:</strong> <?= htmlspecialchars($laptop['asset_id']) ?> &nbsp;·&nbsp;
                        <strong>Device:</strong> <?= htmlspecialchars(trim(($laptop['brand'] ?? '') . ' ' . ($laptop['model'] ?? ''))) ?> &nbsp;·&nbsp;
                        <strong>SN:</strong> <?= htmlspecialchars($laptop['serial_num'] ?? '-') ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error" style="margin-bottom: 1.2rem; padding: 1rem 1.25rem; border-radius: 14px; border: 1px solid rgba(239,68,68,0.35); background: rgba(239,68,68,0.12); color: #ef4444; font-size: 0.95rem;">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <form action="" method="post">
                <input type="hidden" name="asset_id" value="<?= htmlspecialchars($assetId) ?>">

                <div class="form-grid">
                    <!-- Receiver info -->
                    <div>
                        <div class="section-title">Receiver Information</div>

                        <div class="form-group">
                            <label class="form-label" for="receiver_staff_id">Staff ID (Receiver)</label>
                            <input
                                type="text"
                                id="receiver_staff_id"
                                name="receiver_staff_id"
                                class="form-control"
                                placeholder="Optional — e.g. IT-12345"
                                list="staffDirectory"
                                autocomplete="off"
                                value="<?= htmlspecialchars($field_receiver_staff_id, ENT_QUOTES, 'UTF-8') ?>"
                            >
                            <datalist id="staffDirectory">
                                <?php foreach ($staffForLookup as $st): ?>
                                    <option value="<?= htmlspecialchars($st['employee_no'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($st['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($st['department'])): ?>
                                            — <?= htmlspecialchars($st['department'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </datalist>
                            <div id="receiver_lookup_status" style="margin-top: 0.4rem; font-size: 0.8rem; color: var(--text-muted);"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="receiver_name">Staff Name (Receiver)</label>
                            <input type="text" id="receiver_name" name="receiver_name" class="form-control" placeholder="Auto-filled from directory, or enter if new staff" value="<?= htmlspecialchars($field_receiver_name, ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="receiver_designation">Designation / Department (Receiver)</label>
                            <input type="text" id="receiver_designation" name="receiver_designation" class="form-control" placeholder="Optional" value="<?= htmlspecialchars($field_receiver_designation, ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="receiver_email">Email (Receiver)</label>
                            <input type="email" id="receiver_email" name="receiver_email" class="form-control" placeholder="Optional — editable if not in directory" value="<?= htmlspecialchars($field_receiver_email, ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="receiver_phone">Phone (Receiver)</label>
                            <input type="text" id="receiver_phone" name="receiver_phone" class="form-control" placeholder="Optional — editable if not in directory" value="<?= htmlspecialchars($field_receiver_phone, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <!-- Handover details + technician info -->
                    <div>
                        <div class="section-title">Handover Details</div>

                        <div class="form-row-inline">
                            <div class="form-group">
                                <label class="form-label" for="handover_date">Date</label>
                                <input type="date" id="handover_date" name="handover_date" class="form-control" value="<?= htmlspecialchars($field_handover_date, ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="handover_time">Time</label>
                                <input type="time" id="handover_time" name="handover_time" class="form-control" value="<?= htmlspecialchars($field_handover_time, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="handover_place">Place</label>
                            <input type="text" id="handover_place" name="handover_place" class="form-control" value="<?= htmlspecialchars($field_handover_place, ENT_QUOTES, 'UTF-8') ?>" placeholder="Defaults to ITD office if left blank">
                        </div>

                        <div class="section-title" style="margin-top: 1.25rem;">Handover By (Technician)</div>

                        <div class="form-group">
                            <label class="form-label" for="technician_name">Technician Name</label>
                            <input
                                type="text"
                                id="technician_name"
                                name="technician_name"
                                class="form-control"
                                value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>"
                                placeholder="Technician full name"
                                readonly
                            >
                        </div>

                        <input type="hidden" name="technician_staff_id" value="<?= htmlspecialchars($_SESSION['staff_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <div class="form-footer">
                    <div class="footer-note">
                        Only date and place are required. Place defaults to ITD office if cleared. If the receiver Staff ID is not in the directory, enter their full name (and optional contact) — they are added to staff on submit. Time is optional.
                    </div>
                    <div>
                        <button type="button" class="btn btn-ghost" onclick="window.location.href='laptop.php'">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-check-line"></i> Complete Handover
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
            dropdown.classList.toggle('show');
        }

        async function fetchReceiverStaff(employeeNo) {
            const res = await fetch(`getStaffDetails.php?employee_no=${encodeURIComponent(employeeNo)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });

            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                return { ok: false, error: 'Invalid server response' };
            }
        }

        function setReceiverLookupStatus(msg, isError, isInfo) {
            const status = document.getElementById('receiver_lookup_status');
            if (!status) return;
            status.textContent = msg || '';
            if (isError) status.style.color = 'var(--danger)';
            else if (isInfo) status.style.color = 'var(--primary)';
            else status.style.color = 'var(--text-muted)';
        }

        function fillReceiverFields(staff) {
            const nameEl = document.getElementById('receiver_name');
            const desEl = document.getElementById('receiver_designation');
            const emailEl = document.getElementById('receiver_email');
            const phoneEl = document.getElementById('receiver_phone');

            nameEl.value = staff.full_name ?? '';
            desEl.value = staff.department ?? '';
            emailEl.value = staff.email ?? '';
            phoneEl.value = staff.phone ?? '';
        }

        function clearReceiverFields() {
            const nameEl = document.getElementById('receiver_name');
            const desEl = document.getElementById('receiver_designation');
            const emailEl = document.getElementById('receiver_email');
            const phoneEl = document.getElementById('receiver_phone');

            nameEl.value = '';
            desEl.value = '';
            emailEl.value = '';
            phoneEl.value = '';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const staffIdEl = document.getElementById('receiver_staff_id');
            if (!staffIdEl) return;

            async function onReceiverStaffChange() {
                const employeeNo = (staffIdEl.value || '').trim();
                if (!employeeNo) {
                    clearReceiverFields();
                    setReceiverLookupStatus('', false);
                    return;
                }

                setReceiverLookupStatus('Looking up staff...', false);

                const data = await fetchReceiverStaff(employeeNo);
                if (!data || !data.ok) {
                    clearReceiverFields();
                    setReceiverLookupStatus(data?.error || 'Staff lookup failed.', true);
                    return;
                }

                if (!data.staff) {
                    setReceiverLookupStatus(
                        'Not in directory — enter full name and optional department, email, and phone below. They will be added to staff when you complete the handover.',
                        false,
                        true
                    );
                    return;
                }

                fillReceiverFields(data.staff);
                setReceiverLookupStatus('', false, false);
            }

            staffIdEl.addEventListener('change', onReceiverStaffChange);
            staffIdEl.addEventListener('blur', onReceiverStaffChange);
            staffIdEl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') onReceiverStaffChange();
            });
        });
    </script>
</body>
</html>
