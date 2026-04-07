<?php
session_start();

if (!isset($_SESSION['staff_id']) || (int)$_SESSION['role_id'] !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once __DIR__ . '/../config/laptop_asset_id.php';

$pdoPreview    = db();
$next_desktop  = laptop_compute_next_asset_id($pdoPreview, '14');
$next_notebook = laptop_compute_next_asset_id($pdoPreview, '12');

$staffForHandover = [];
try {
    $staffForHandover = db()->query('SELECT employee_no, full_name, department FROM staff ORDER BY full_name')->fetchAll();
} catch (PDOException $e) {
    $staffForHandover = [];
}

$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $str  = fn($k) => isset($_POST[$k]) && $_POST[$k] !== '' ? trim($_POST[$k])  : null;
    $date = fn($k) => isset($_POST[$k]) && $_POST[$k] !== '' ? trim($_POST[$k])  : null;
    $dec  = fn($k) => isset($_POST[$k]) && $_POST[$k] !== '' ? (float)$_POST[$k] : null;
    $int  = fn($k) => isset($_POST[$k]) && $_POST[$k] !== '' ? (int)$_POST[$k]   : null;

    $serial_num    = $str('serial_num');
    $status_id     = $int('status_id');
    $brand         = $str('brand');
    $model         = $str('model');
    $part_number   = $str('part_number');
    $category      = $str('category');
    $processor     = $str('processor');
    $memory        = $str('memory');
    $storage       = $str('storage');
    $gpu           = $str('gpu');
    $os            = $str('os');
    $po_date       = $date('po_date');
    $po_num        = $str('po_num');
    $do_date       = $date('do_date');
    $do_num        = $str('do_num');
    $invoice_date  = $date('invoice_date');
    $invoice_num   = $str('invoice_num');
    $purchase_cost = $dec('purchase_cost');
    $remarks       = $str('remarks');

    $is_deploy      = ($status_id === 3);
    $ho_employee_no = $str('employee_no');
    $ho_date        = $date('handover_date');
    $ho_remarks     = $str('handover_remarks');
    $sessionStaff   = isset($_SESSION['staff_id']) ? trim((string)$_SESSION['staff_id']) : '';

    $w_start      = $date('warranty_start_date');
    $w_end        = $date('warranty_end_date');
    $w_remarks    = $str('warranty_remarks');
    $has_warranty = ($w_start !== null && $w_end !== null);

    if (!$serial_num || !$status_id) {
        $error_message = 'Serial Number and Status are required.';
    } elseif ($category === null || $category === '') {
        $error_message = 'Category is required (Asset ID is based on category).';
    } elseif (laptop_category_to_asset_prefix($category) === null) {
        $error_message = 'Invalid category for Asset ID generation.';
    } elseif ($is_deploy && (!$ho_employee_no || !$ho_date)) {
        $error_message = 'Handover details (assignee and date) are required for Deploy status.';
    } elseif ($is_deploy && $sessionStaff === '') {
        $error_message = 'Session staff ID missing; log in again to record a handover.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $prefix2  = laptop_category_to_asset_prefix($category);
            $asset_id = laptop_compute_next_asset_id($pdo, $prefix2);

            $stmt = $pdo->prepare("
                INSERT INTO laptop
                    (asset_id, serial_num, brand, model, category, part_number,
                     processor, memory, os, storage, gpu,
                     PO_DATE, PO_NUM, DO_DATE, DO_NUM,
                     INVOICE_DATE, INVOICE_NUM, PURCHASE_COST, status_id, remarks)
                VALUES
                    (:asset_id, :serial_num, :brand, :model, :category, :part_number,
                     :processor, :memory, :os, :storage, :gpu,
                     :po_date, :po_num, :do_date, :do_num,
                     :invoice_date, :invoice_num, :purchase_cost, :status_id, :remarks)
            ");
            $stmt->execute([
                ':asset_id' => $asset_id, ':serial_num' => $serial_num,
                ':brand' => $brand, ':model' => $model,
                ':category' => $category, ':part_number' => $part_number,
                ':processor' => $processor, ':memory' => $memory,
                ':os' => $os, ':storage' => $storage, ':gpu' => $gpu,
                ':po_date' => $po_date, ':po_num' => $po_num,
                ':do_date' => $do_date, ':do_num' => $do_num,
                ':invoice_date' => $invoice_date, ':invoice_num' => $invoice_num,
                ':purchase_cost' => $purchase_cost, ':status_id' => $status_id, ':remarks' => $remarks,
            ]);

            if ($is_deploy) {
                $stmt2 = $pdo->prepare("INSERT INTO handover (asset_id, staff_id, handover_date, handover_remarks) VALUES (:asset_id, :staff_id, :handover_date, :handover_remarks)");
                $stmt2->execute([':asset_id' => $asset_id, ':staff_id' => $sessionStaff, ':handover_date' => $ho_date, ':handover_remarks' => $ho_remarks]);
                $handover_id = (int)$pdo->lastInsertId();
                $stmt3 = $pdo->prepare('INSERT INTO handover_staff (employee_no, handover_id) VALUES (:employee_no, :handover_id)');
                $stmt3->execute([':employee_no' => $ho_employee_no, ':handover_id' => $handover_id]);
            }

            if ($has_warranty) {
                $stmt4 = $pdo->prepare("INSERT INTO warranty (asset_id, warranty_start_date, warranty_end_date, warranty_remarks) VALUES (:asset_id, :start, :end, :remarks)");
                $stmt4->execute([':asset_id' => $asset_id, ':start' => $w_start, ':end' => $w_end, ':remarks' => $w_remarks]);
            }

            $pdo->commit();
            $success_message = "Laptop (Asset ID: {$asset_id}) has been successfully registered!";

        } catch (\PDOException $e) {
            if (isset($pdo)) $pdo->rollBack();
            $error_message = $e->getCode() == 23000
                ? "Asset ID <strong>{$asset_id}</strong> already exists. Please use a unique Asset ID."
                : "Database error: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Laptop — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

    <style>
        :root {
            --primary:       #2563eb;
            --primary-light: #3b82f6;
            --primary-dark:  #1d4ed8;
            --secondary:     #0ea5e9;
            --danger:        #ef4444;
            --success:       #10b981;
            --warning:       #f59e0b;
            --bg:            #f1f5f9;
            --card-bg:       #ffffff;
            --card-border:   #e2e8f0;
            --text-main:     #0f172a;
            --text-muted:    #64748b;
            --glass:         #f8fafc;
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

        /* ── Main layout ── */
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2rem 2.5rem 4rem;
            max-width: calc(100vw - 280px);
        }
        @media (max-width: 900px) {
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.25rem 1rem 3rem; }
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
        .page-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        .page-subtitle { color: var(--text-muted); font-size: 0.875rem; margin-top: 0.3rem; }
        .btn-back {
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
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .btn-back:hover { border-color: var(--primary); color: var(--primary); }

        /* ── Alerts ── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border-radius: 14px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            line-height: 1.45;
        }
        .alert i { font-size: 1.2rem; flex-shrink: 0; margin-top: 0.05rem; }
        .alert-link { margin-left: auto; font-weight: 700; text-decoration: underline; color: inherit; white-space: nowrap; }
        .alert-success { background: rgba(16,185,129,0.1);  border: 1px solid rgba(16,185,129,0.3);  color: #047857; }
        .alert-error   { background: rgba(239,68,68,0.08);  border: 1px solid rgba(239,68,68,0.25);  color: #b91c1c; }

        /* ── Form section card ── */
        .form-section {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 1.25rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.05);
            transition: opacity 0.3s;
        }

        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.4rem;
            border-bottom: 1px solid var(--card-border);
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            gap: 0.75rem;
        }
        .section-head-left {
            display: flex;
            align-items: center;
            gap: 0.7rem;
        }
        .section-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: rgba(37,99,235,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .section-icon i { font-size: 1rem; color: var(--primary); }
        .section-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.98rem;
        }
        .section-desc { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.1rem; }

        .badge-tag {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.65rem;
            border-radius: 20px;
            font-size: 0.67rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge-required { background: rgba(37,99,235,0.1);   color: var(--primary);   border: 1px solid rgba(37,99,235,0.2); }
        .badge-optional { background: rgba(100,116,139,0.1); color: var(--text-muted); border: 1px solid var(--card-border); }
        .badge-deploy   { background: rgba(245,158,11,0.1);  color: #b45309;           border: 1px solid rgba(245,158,11,0.25); }

        .section-body { padding: 1.4rem; }

        /* ── Disabled section ── */
        .section-disabled { opacity: 0.42; pointer-events: none; }
        .section-disabled .section-head { background: var(--glass); }

        /* ── Form grid ── */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem 1.25rem;
        }
        @media (max-width: 1100px) { .form-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px)  { .form-grid { grid-template-columns: 1fr; } }

        .col-2 { grid-column: span 2; }
        .col-3 { grid-column: 1 / -1; }

        @media (max-width: 640px) {
            .col-2, .col-3 { grid-column: span 1; }
        }

        /* ── Field ── */
        .field { display: flex; flex-direction: column; gap: 0.4rem; }

        .field-label {
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.35rem;
            flex-wrap: wrap;
        }
        .req { color: var(--danger); }
        .auto-tag {
            font-size: 0.63rem;
            font-weight: 700;
            background: rgba(37,99,235,0.08);
            color: var(--primary);
            border: 1px solid rgba(37,99,235,0.15);
            border-radius: 20px;
            padding: 0.08rem 0.45rem;
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            letter-spacing: 0.03em;
        }

        .field-input,
        .field-select,
        .field-textarea {
            width: 100%;
            padding: 0.68rem 0.9rem;
            border: 1.5px solid var(--card-border);
            border-radius: 11px;
            font-family: 'Inter', sans-serif;
            font-size: 0.875rem;
            color: var(--text-main);
            background: var(--glass);
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            outline: none;
        }
        .field-input::placeholder,
        .field-textarea::placeholder { color: #94a3b8; font-weight: 400; }
        .field-input:focus,
        .field-select:focus,
        .field-textarea:focus {
            border-color: rgba(37,99,235,0.5);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .field-input:disabled,
        .field-select:disabled { opacity: 0.5; cursor: not-allowed; }

        .field-input.auto {
            font-weight: 700;
            color: var(--primary);
            background: rgba(37,99,235,0.04);
            border-color: rgba(37,99,235,0.18);
            cursor: default;
        }

        .field-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2364748b'%3E%3Cpath d='M12 16L6 10H18L12 16Z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.85rem center;
            background-size: 1rem;
            padding-right: 2.2rem;
            cursor: pointer;
        }
        .field-select option { background: #fff; color: var(--text-main); }
        .field-textarea { resize: vertical; min-height: 100px; }

        .field-hint {
            font-size: 0.7rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .field-hint i { font-size: 0.75rem; }
        .field-hint.err { color: #e11d48; }

        /* ── Deploy notice ── */
        .deploy-notice {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.7rem 1rem;
            border-radius: 10px;
            background: rgba(245,158,11,0.08);
            border: 1px solid rgba(245,158,11,0.22);
            color: #92400e;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 1.1rem;
        }
        .deploy-notice i { font-size: 0.95rem; color: var(--warning); flex-shrink: 0; }

        /* ── Form actions ── */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 0.85rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--card-border);
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.78rem 1.5rem;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.92rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #021A54, #1e40af);
            color: #fff;
            box-shadow: 0 4px 14px rgba(2,26,84,0.28);
        }
        .btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(2,26,84,0.35); }
        .btn-outline {
            background: var(--card-bg);
            border: 1.5px solid var(--card-border);
            color: var(--text-muted);
        }
        .btn-outline:hover { border-color: var(--danger); color: var(--danger); }
    </style>
</head>
<body>

<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">

    <!-- Page header -->
    <header class="page-header">
        <div>
            <h1 class="page-title">Register New Laptop</h1>
            <p class="page-subtitle">Fill in the asset details below to add a new device to the inventory.</p>
        </div>
        <a href="../technician/laptop.php" class="btn-back">
            <i class="ri-arrow-left-line"></i> Back to Inventory
        </a>
    </header>

    <!-- Alerts -->
    <?php if ($success_message): ?>
    <div class="alert alert-success">
        <i class="ri-checkbox-circle-fill"></i>
        <span><?= $success_message ?></span>
        <a href="laptop.php" class="alert-link">View Inventory →</a>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-error">
        <i class="ri-error-warning-fill"></i>
        <span><?= $error_message ?></span>
    </div>
    <?php endif; ?>

    <form action="" method="POST" id="laptopForm">

        <!-- ── 1. Device Identity ── -->
        <div class="form-section">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-icon"><i class="ri-qr-code-line"></i></div>
                    <div>
                        <div class="section-title">Device Identity</div>
                        <div class="section-desc">Category, asset ID, serial number and status</div>
                    </div>
                </div>
                <span class="badge-tag badge-required">Required</span>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="field">
                        <label class="field-label">Category <span class="req">*</span></label>
                        <select name="category" id="categorySelect" class="field-select" required>
                            <option value="" disabled selected>Select category…</option>
                            <option value="Desktop AIO">Desktop AIO</option>
                            <option value="Desktop IO">Desktop IO Sharing</option>
                            <option value="Notebook">Notebook</option>
                            <option value="Notebook Standby">Notebook Standby</option>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field-label">
                            Asset ID <span class="req">*</span>
                            <span class="auto-tag"><i class="ri-magic-line"></i> Auto-generated</span>
                        </label>
                        <input type="number" name="asset_id" id="asset_id_field"
                               class="field-input auto" value="" readonly
                               placeholder="Select a category first"
                               title="14YY### = Desktop · 12YY### = Notebook">
                        <span class="field-hint"><i class="ri-information-line"></i> Based on category and current year</span>
                    </div>

                    <div class="field">
                        <label class="field-label">Serial Number <span class="req">*</span></label>
                        <input type="text" name="serial_num" class="field-input" placeholder="e.g. PF1XZ9K" required>
                    </div>

                    <div class="field">
                        <label class="field-label">System Status <span class="req">*</span></label>
                        <select name="status_id" id="status_id" class="field-select" required>
                            <option value="" disabled selected>Select status…</option>
                            <option value="1">Active</option>
                            <option value="2">Non-active</option>
                            <option value="3">Deploy</option>
                            <option value="4">Reserved</option>
                            <option value="5">Maintenance</option>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field-label">Brand</label>
                        <input type="text" name="brand" class="field-input" placeholder="e.g. Lenovo">
                    </div>

                    <div class="field">
                        <label class="field-label">Model</label>
                        <input type="text" name="model" class="field-input" placeholder="e.g. ThinkPad T14">
                    </div>

                    <div class="field">
                        <label class="field-label">Part Number / Model No.</label>
                        <input type="text" name="part_number" class="field-input" placeholder="e.g. 20W0004UMY">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 2. Specifications ── -->
        <div class="form-section">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-icon"><i class="ri-cpu-line"></i></div>
                    <div>
                        <div class="section-title">Specifications</div>
                        <div class="section-desc">Processor, memory, storage and OS</div>
                    </div>
                </div>
                <span class="badge-tag badge-optional">Optional</span>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="field">
                        <label class="field-label">Processor (CPU)</label>
                        <input type="text" name="processor" class="field-input" placeholder="e.g. Intel Core i7-1165G7">
                    </div>
                    <div class="field">
                        <label class="field-label">Memory (RAM)</label>
                        <input type="text" name="memory" class="field-input" placeholder="e.g. 16GB DDR4">
                    </div>
                    <div class="field">
                        <label class="field-label">Storage</label>
                        <input type="text" name="storage" class="field-input" placeholder="e.g. 512GB NVMe SSD">
                    </div>
                    <div class="field">
                        <label class="field-label">Graphics (GPU)</label>
                        <input type="text" name="gpu" class="field-input" placeholder="e.g. Intel Iris Xe">
                    </div>
                    <div class="field">
                        <label class="field-label">Operating System</label>
                        <input type="text" name="os" class="field-input" placeholder="e.g. Windows 11 Pro">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 3. Purchase Details ── -->
        <div class="form-section">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-icon"><i class="ri-shopping-bag-3-line"></i></div>
                    <div>
                        <div class="section-title">Purchase Details</div>
                        <div class="section-desc">PO, delivery order, invoice and cost</div>
                    </div>
                </div>
                <span class="badge-tag badge-optional">Optional</span>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="field">
                        <label class="field-label">PO Date</label>
                        <input type="date" name="po_date" class="field-input">
                    </div>
                    <div class="field">
                        <label class="field-label">PO Number</label>
                        <input type="text" name="po_num" class="field-input" placeholder="e.g. PO-2024-001">
                    </div>
                    <div class="field">
                        <label class="field-label">DO Date</label>
                        <input type="date" name="do_date" class="field-input">
                    </div>
                    <div class="field">
                        <label class="field-label">DO Number</label>
                        <input type="text" name="do_num" class="field-input" placeholder="e.g. DO-2024-001">
                    </div>
                    <div class="field">
                        <label class="field-label">Invoice Date</label>
                        <input type="date" name="invoice_date" class="field-input">
                    </div>
                    <div class="field">
                        <label class="field-label">Invoice Number</label>
                        <input type="text" name="invoice_num" class="field-input" placeholder="e.g. INV-2024-001">
                    </div>
                    <div class="field">
                        <label class="field-label">Purchase Cost (RM)</label>
                        <input type="number" step="0.01" name="purchase_cost" class="field-input" placeholder="0.00">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 4. Handover (Deploy only) ── -->
        <div class="form-section section-disabled" id="handover_section">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-icon" style="background:rgba(245,158,11,0.1)">
                        <i class="ri-user-shared-line" style="color:var(--warning)"></i>
                    </div>
                    <div>
                        <div class="section-title">Handover Assignment</div>
                        <div class="section-desc">Required when status is set to Deploy</div>
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
                    <div class="field col-2">
                        <label class="field-label">Assign To (Staff Directory) <span class="req">*</span></label>
                        <select name="employee_no" class="field-select handover-input" disabled>
                            <option value="" disabled selected>Select employee…</option>
                            <?php foreach ($staffForHandover as $sm): ?>
                                <option value="<?= htmlspecialchars($sm['employee_no'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($sm['full_name']) ?>
                                    <?php if (!empty($sm['department'])): ?> — <?= htmlspecialchars($sm['department']) ?><?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($staffForHandover)): ?>
                            <span class="field-hint err"><i class="ri-error-warning-line"></i> No staff rows found. Admin must import the staff CSV first.</span>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="field-label">Handover Date <span class="req">*</span></label>
                        <input type="date" name="handover_date" class="field-input handover-input" disabled>
                    </div>

                    <div class="field col-2">
                        <label class="field-label">Handover Remarks</label>
                        <input type="text" name="handover_remarks" class="field-input handover-input"
                               placeholder="e.g. Charger and bag included" disabled>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 5. Warranty ── -->
        <div class="form-section">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-icon" style="background:rgba(16,185,129,0.1)">
                        <i class="ri-shield-check-line" style="color:var(--success)"></i>
                    </div>
                    <div>
                        <div class="section-title">Warranty Information</div>
                        <div class="section-desc">Coverage dates and provider details</div>
                    </div>
                </div>
                <span class="badge-tag badge-optional">Optional</span>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="field">
                        <label class="field-label">Warranty Start Date</label>
                        <input type="date" name="warranty_start_date" class="field-input">
                    </div>
                    <div class="field">
                        <label class="field-label">Warranty End Date</label>
                        <input type="date" name="warranty_end_date" class="field-input">
                    </div>
                    <div class="field">
                        <label class="field-label">Warranty Remarks / Provider</label>
                        <input type="text" name="warranty_remarks" class="field-input" placeholder="e.g. 3 Year On-Site Service">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 6. General Remarks ── -->
        <div class="form-section">
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-icon"><i class="ri-sticky-note-line"></i></div>
                    <div>
                        <div class="section-title">General Remarks</div>
                        <div class="section-desc">Any additional notes or observations about this device</div>
                    </div>
                </div>
                <span class="badge-tag badge-optional">Optional</span>
            </div>
            <div class="section-body">
                <div class="field">
                    <label class="field-label">Remarks</label>
                    <textarea name="remarks" class="field-textarea" rows="4"
                        placeholder="e.g. Keyboard key slightly sticky. Purchased under IT upgrade budget FY2024. Configured with standard IT image."></textarea>
                </div>
            </div>
        </div>

        <!-- ── Actions ── -->
        <div class="form-actions">
            <button type="reset" class="btn btn-outline">
                <i class="ri-refresh-line"></i> Clear Form
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="ri-save-3-line"></i> Register Laptop
            </button>
        </div>

    </form>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var nextByCategory = {
        'Desktop AIO':      <?= (int)$next_desktop ?>,
        'Desktop IO':       <?= (int)$next_desktop ?>,
        'Notebook':         <?= (int)$next_notebook ?>,
        'Notebook Standby': <?= (int)$next_notebook ?>,
    };

    var categorySelect  = document.getElementById('categorySelect');
    var assetIdField    = document.getElementById('asset_id_field');
    var statusSelect    = document.getElementById('status_id');
    var handoverSection = document.getElementById('handover_section');
    var handoverInputs  = document.querySelectorAll('.handover-input');

    function syncAssetId() {
        if (!categorySelect || !assetIdField) return;
        var v = categorySelect.value;
        assetIdField.value = (v && Object.prototype.hasOwnProperty.call(nextByCategory, v))
            ? String(nextByCategory[v]) : '';
    }
    categorySelect && categorySelect.addEventListener('change', syncAssetId);

    function toggleHandover() {
        var isDeploy = statusSelect && statusSelect.value === '3';
        handoverSection.classList.toggle('section-disabled', !isDeploy);
        handoverInputs.forEach(function (inp) {
            inp.disabled = !isDeploy;
            if (!isDeploy) inp.value = '';
        });
    }
    statusSelect && statusSelect.addEventListener('change', toggleHandover);
    toggleHandover();

    window.toggleDropdown = function (element, event) {
        event.preventDefault();
        var group    = element.closest('.nav-group');
        var dropdown = group && group.querySelector('.nav-dropdown');
        element.classList.toggle('open');
        dropdown && dropdown.classList.toggle('show');
    };
});
</script>
</body>
</html>