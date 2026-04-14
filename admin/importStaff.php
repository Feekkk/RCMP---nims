<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 2) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

const STAFF_CSV_HEADERS = ['EMPLOYEE NUMBER', 'FULL NAME', 'DEPT', 'EMAIL', 'PHONE NUMBER'];

function staff_norm_header(string $h): string
{
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);

    return strtolower(trim(preg_replace('/\s+/', ' ', $h)));
}

/** @return list<string> */
function staff_csv_clean_header_row(array $row): array
{
    $out = [];
    foreach ($row as $i => $cell) {
        $v = (string)$cell;
        if ($i === 0) {
            $v = preg_replace('/^\xEF\xBB\xBF/', '', $v);
        }
        $out[] = trim($v);
    }

    return $out;
}

function staff_csv_header_map(): array
{
    return [
        'employee number' => 'employee_no',
        'employee no' => 'employee_no',
        'employee_no' => 'employee_no',
        'emp no' => 'employee_no',
        'emp #' => 'employee_no',
        'staff id' => 'employee_no',
        'staff no' => 'employee_no',
        'full name' => 'full_name',
        'fullname' => 'full_name',
        'name' => 'full_name',
        'dept' => 'department',
        'department' => 'department',
        'division' => 'department',
        'email' => 'email',
        'e-mail' => 'email',
        'email address' => 'email',
        'phone number' => 'phone',
        'phone' => 'phone',
        'mobile' => 'phone',
        'tel' => 'phone',
        'contact' => 'phone',
    ];
}

function staff_csv_pick_delimiter(string $line): string
{
    $comma = substr_count($line, ',');
    $semi = substr_count($line, ';');

    return ($semi > $comma) ? ';' : ',';
}

/** @return array<string,int> */
function staff_csv_build_col_index(array $headerRow): array
{
    $map = staff_csv_header_map();
    $idx = [];
    foreach ($headerRow as $i => $cell) {
        $k = $map[staff_norm_header((string)$cell)] ?? null;
        if ($k !== null && !isset($idx[$k])) {
            $idx[$k] = $i;
        }
    }
    $positional = ['employee_no', 'full_name', 'department', 'email', 'phone'];
    if (!isset($idx['employee_no'], $idx['full_name']) && count($headerRow) >= 5) {
        foreach ($positional as $j => $key) {
            if (!isset($idx[$key])) {
                $idx[$key] = $j;
            }
        }
    }

    return $idx;
}

function staff_csv_cell(array $row, array $idx, string $key): ?string
{
    if (!isset($idx[$key])) {
        return null;
    }
    $i = $idx[$key];
    if (!isset($row[$i])) {
        return null;
    }
    $v = trim((string)$row[$i]);
    return $v === '' ? null : $v;
}

if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="nims-rcmp staff list template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, STAFF_CSV_HEADERS);
    fputcsv($out, ['620001', 'Ahmad Bin Ali', 'Academic Affairs', 'ahmad@unikl.edu.my', '012-345 6789']);
    fclose($out);
    exit;
}

$results = [];
$total_ok = 0;
$total_err = 0;
$processed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $results[] = ['row' => 0, 'status' => 'error', 'msg' => 'File upload failed.'];
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $results[] = ['row' => 0, 'status' => 'error', 'msg' => 'Only .csv files are accepted.'];
    } else {
        $pdo = db();
        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false || $raw === '') {
            $results[] = ['row' => 0, 'status' => 'error', 'msg' => 'Could not read the file.'];
        } else {
            $raw = str_replace("\xEF\xBB\xBF", '', $raw);
            $lines = preg_split('/\r\n|\r|\n/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            if ($lines === [] || $lines === false) {
                $results[] = ['row' => 0, 'status' => 'error', 'msg' => 'Empty CSV.'];
            } else {
            $firstRaw = $lines[0];
            $delimiter = staff_csv_pick_delimiter($firstRaw);
            $header = staff_csv_clean_header_row(str_getcsv($firstRaw, $delimiter));
            $idx = staff_csv_build_col_index($header);
            $row_num = 1;
            $stmt = $pdo->prepare('
                INSERT INTO staff (employee_no, full_name, department, email, phone)
                VALUES (:employee_no, :full_name, :department, :email, :phone)
                ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    department = VALUES(department),
                    email = VALUES(email),
                    phone = VALUES(phone)
            ');

            for ($li = 1; $li < count($lines); $li++) {
                $row = str_getcsv($lines[$li], $delimiter);
                $row_num++;
                if (count(array_filter($row, static function ($c): bool { return trim((string)$c) !== ''; })) === 0) {
                    continue;
                }

                $emp = staff_csv_cell($row, $idx, 'employee_no');
                $name = staff_csv_cell($row, $idx, 'full_name');
                if ($emp === null || $name === null) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'employee_no' => $emp ?? '—', 'name' => $name ?? '—',
                        'msg' => 'Employee number and full name are required.',
                    ];
                    $total_err++;
                    continue;
                }
                if (strlen($emp) > 32) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'employee_no' => $emp, 'name' => $name,
                        'msg' => 'Employee number must be 32 characters or less.',
                    ];
                    $total_err++;
                    continue;
                }
                if (strlen($name) > 128) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'employee_no' => $emp, 'name' => $name,
                        'msg' => 'Full name must be 128 characters or less.',
                    ];
                    $total_err++;
                    continue;
                }

                $dept = staff_csv_cell($row, $idx, 'department');
                $email = staff_csv_cell($row, $idx, 'email');
                $phone = staff_csv_cell($row, $idx, 'phone');
                if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'employee_no' => $emp, 'name' => $name,
                        'msg' => 'Invalid email.',
                    ];
                    $total_err++;
                    continue;
                }
                if ($dept !== null && strlen($dept) > 128) {
                    $dept = substr($dept, 0, 128);
                }
                if ($phone !== null && strlen($phone) > 64) {
                    $phone = substr($phone, 0, 64);
                }

                try {
                    $stmt->execute([
                        ':employee_no' => $emp,
                        ':full_name' => $name,
                        ':department' => $dept,
                        ':email' => $email,
                        ':phone' => $phone,
                    ]);
                    $results[] = [
                        'row' => $row_num, 'status' => 'ok',
                        'employee_no' => $emp, 'name' => $name,
                        'msg' => 'Saved',
                    ];
                    $total_ok++;
                } catch (PDOException $e) {
                    $results[] = [
                        'row' => $row_num, 'status' => 'error',
                        'employee_no' => $emp, 'name' => $name,
                        'msg' => $e->getMessage(),
                    ];
                    $total_err++;
                }
            }
            $processed = true;
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
    <title>Import staff - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --bg: #f0f4ff;
            --card-bg: #ffffff;
            --sidebar-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-border: #e2e8f0;
            --glass: #f8faff;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); display: flex; min-height: 100vh; }
        .blob { position: fixed; border-radius: 50%; filter: blur(90px); pointer-events: none; z-index: 0; }
        .blob-1 { width: 480px; height: 480px; background: rgba(37,99,235,0.06); top: -120px; right: -100px; }
        .blob-2 { width: 380px; height: 380px; background: rgba(124,58,237,0.05); bottom: -80px; left: -80px; }
        .sidebar {
            width: 280px; min-height: 100vh; background: var(--sidebar-bg); border-right: 1px solid var(--card-border);
            position: fixed; top: 0; left: 0; bottom: 0; z-index: 100; display: flex; flex-direction: column;
            box-shadow: 2px 0 20px rgba(15,23,42,0.06);
        }
        .sidebar-logo { padding: 1.5rem; border-bottom: 1px solid var(--card-border); text-align: center; }
        .sidebar-logo img { height: 42px; object-fit: contain; }
        .nav-menu { flex: 1; padding: 1rem; display: flex; flex-direction: column; gap: 0.25rem; overflow-y: auto; }
        .nav-item {
            padding: 0.75rem 1.2rem; border-radius: 12px; color: var(--text-muted); text-decoration: none;
            font-weight: 600; display: flex; align-items: center; gap: 0.75rem;
        }
        .nav-item:hover { background: rgba(37,99,235,0.06); color: var(--primary); }
        .nav-item.active { background: rgba(37,99,235,0.1); color: var(--primary); }
        .user-profile {
            margin-top: auto; padding: 1.2rem 1.5rem; border-top: 1px solid var(--card-border);
            display: flex; align-items: center; gap: 0.75rem; cursor: pointer;
        }
        .avatar {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif;
        }
        .user-info { flex: 1; min-width: 0; }
        .user-name { font-weight: 800; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role { font-size: 0.72rem; color: var(--primary); font-weight: 800; text-transform: uppercase; margin-top: 0.15rem; }
        .sidebar-copyright { padding: 0.75rem 1rem 1rem; text-align: center; font-size: 0.65rem; color: var(--text-muted); border-top: 1px solid var(--card-border); }

        .main-content {
            margin-left: 280px; flex: 1; padding: 2.5rem 3rem 4rem; max-width: calc(100vw - 280px);
            position: relative; z-index: 1;
        }
        .page-header {
            display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 1rem;
            margin-bottom: 1.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid var(--card-border);
        }
        .page-title h1 { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 900; display: flex; align-items: center; gap: 0.6rem; }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); margin-top: 0.35rem; max-width: 640px; line-height: 1.5; font-size: 0.95rem; }
        .btn {
            display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.7rem 1.2rem;
            border-radius: 12px; font-weight: 700; font-size: 0.9rem; text-decoration: none; border: none;
            cursor: pointer; font-family: inherit;
        }
        .btn-outline { background: var(--glass); border: 1px solid var(--card-border); color: var(--text-muted); }
        .btn-outline:hover { color: var(--primary); border-color: rgba(37,99,235,0.25); }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; }
        .btn-primary:hover { filter: brightness(1.05); }
        .card {
            background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 18px;
            padding: 1.75rem; margin-bottom: 1.25rem; box-shadow: 0 2px 12px rgba(15,23,42,0.06);
        }
        .card h2 { font-family: 'Outfit', sans-serif; font-size: 1.05rem; font-weight: 900; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; }
        .info-box {
            background: rgba(37,99,235,0.06); border: 1px solid rgba(37,99,235,0.18); border-radius: 12px;
            padding: 1rem 1.15rem; font-size: 0.88rem; line-height: 1.5; color: #1e3a5f; margin-bottom: 1.25rem;
        }
        .chip-row { display: flex; flex-wrap: wrap; gap: 0.45rem; margin-bottom: 1rem; }
        .chip {
            background: var(--glass); border: 1px solid var(--card-border); border-radius: 8px;
            padding: 0.3rem 0.65rem; font-size: 0.76rem; font-weight: 700; color: var(--text-muted);
        }
        .chip.req { border-color: rgba(37,99,235,0.3); color: var(--primary); }
        .upload-zone {
            border: 2px dashed var(--card-border); border-radius: 16px; padding: 2.5rem; text-align: center;
            background: var(--glass); position: relative; cursor: pointer;
        }
        .upload-zone:hover { border-color: var(--primary); background: rgba(37,99,235,0.04); }
        .upload-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
        .upload-icon { font-size: 2.5rem; color: var(--primary); margin-bottom: 0.75rem; }
        .submit-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 1.25rem; flex-wrap: wrap; gap: 0.75rem; }
        .stats { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .stat {
            flex: 1; min-width: 100px; background: var(--glass); border: 1px solid var(--card-border);
            border-radius: 12px; padding: 1rem; text-align: center;
        }
        .stat .n { font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 900; }
        .stat .l { font-size: 0.7rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; margin-top: 0.2rem; }
        .stat.ok .n { color: var(--success); }
        .stat.err .n { color: var(--danger); }
        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        th { text-align: left; padding: 0.75rem; background: var(--glass); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); border-bottom: 1px solid var(--card-border); }
        td { padding: 0.75rem; border-bottom: 1px dashed rgba(226,232,240,0.8); }
        tr.ok { background: rgba(16,185,129,0.04); }
        tr.err { background: rgba(239,68,68,0.04); }
        .badge { padding: 0.2rem 0.55rem; border-radius: 999px; font-size: 0.72rem; font-weight: 800; }
        .badge-ok { background: rgba(16,185,129,0.15); color: var(--success); }
        .badge-err { background: rgba(239,68,68,0.15); color: var(--danger); }
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
        }
    </style>
</head>
<body>
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<?php include __DIR__ . '/../components/sidebarAdmin.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div class="page-title">
            <h1><i class="ri-team-line"></i> Import staff list</h1>
            <p>
                Upload your RCMP staff list as a CSV file. The layout should match your standard file
                <strong>nims-rcmp staff list.csv</strong> (first row = column titles, then one staff member per row).
                New employees are added; existing employee numbers are updated with the latest details from the file.
            </p>
        </div>
        <a href="users.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Back to Users</a>
    </header>

    <?php if ($processed): ?>
    <div class="stats">
        <div class="stat"><div class="n"><?= $total_ok + $total_err ?></div><div class="l">Rows</div></div>
        <div class="stat ok"><div class="n"><?= $total_ok ?></div><div class="l">Saved</div></div>
        <div class="stat err"><div class="n"><?= $total_err ?></div><div class="l">Failed</div></div>
    </div>
    <div class="card">
        <h2><i class="ri-list-check-2-line"></i> Results</h2>
        <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Row</th><th>Employee #</th><th>Name</th><th>Result</th><th>Message</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr class="<?= $r['status'] === 'ok' ? 'ok' : 'err' ?>">
                        <td>#<?= (int)$r['row'] ?></td>
                        <td><strong><?= htmlspecialchars((string)($r['employee_no'] ?? '—')) ?></strong></td>
                        <td><?= htmlspecialchars((string)($r['name'] ?? '—')) ?></td>
                        <td><span class="badge <?= $r['status'] === 'ok' ? 'badge-ok' : 'badge-err' ?>"><?= $r['status'] === 'ok' ? 'OK' : 'Fail' ?></span></td>
                        <td><?= htmlspecialchars($r['msg'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:1.25rem;display:flex;gap:0.75rem;flex-wrap:wrap;">
            <a href="importStaff.php" class="btn btn-primary"><i class="ri-upload-2-line"></i> Import another</a>
            <a href="users.php" class="btn btn-outline">View users</a>
        </div>
    </div>
    <?php else: ?>

    <div class="info-box">
        <strong>Tip for administrators</strong><br>
        After import, these people appear in the directory used for laptop assignments (Deploy).
        This upload only maintains the staff list itself, not individual handover records.
    </div>

    <div class="card">
        <h2><i class="ri-download-2-line"></i> File format</h2>
        <p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:0.75rem;line-height:1.5;">
            Row 1 must be the headings below (same wording as in <strong>nims-rcmp staff list.csv</strong>, or the same five columns in this order).
            UTF‑8 files and Excel-style comma separators are supported; semicolons are detected automatically when needed.
        </p>
        <div class="chip-row">
            <?php foreach (STAFF_CSV_HEADERS as $i => $h): ?>
                <span class="chip <?= $i < 2 ? 'req' : '' ?>"><?= htmlspecialchars($h) ?></span>
            <?php endforeach; ?>
        </div>
        <p style="color:var(--text-muted);font-size:0.85rem;margin-top:0.75rem;"><span class="chip req" style="display:inline;">Required</span> Employee number and full name. Dept, email, and phone may be left blank.</p>
        <a href="?download_template=1" class="btn btn-primary" style="margin-top:1rem;"><i class="ri-file-excel-2-line"></i> Download blank template</a>
    </div>

    <form method="POST" enctype="multipart/form-data" class="card">
        <h2><i class="ri-upload-cloud-2-line"></i> Upload CSV</h2>
        <div class="upload-zone">
            <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required>
            <div class="upload-icon"><i class="ri-file-upload-line"></i></div>
            <p style="font-weight:700;">Drag and drop here, or click to choose a file</p>
            <p style="color:var(--text-muted);font-size:0.88rem;margin-top:0.35rem;">Example filename: <code>nims-rcmp staff list.csv</code></p>
        </div>
        <div class="submit-bar">
            <span style="color:var(--text-muted);font-size:0.88rem;">If an employee number already exists, name, department, email, and phone are replaced with values from this file.</span>
            <button type="submit" class="btn btn-primary"><i class="ri-upload-2-line"></i> Run import</button>
        </div>
    </form>
    <?php endif; ?>
</main>
</body>
</html>
