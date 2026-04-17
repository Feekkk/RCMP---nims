<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 2) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function addtech_error_message(PDOException $e): string
{
    $info = $e->errorInfo;
    $driverErr = isset($info[1]) ? (int)$info[1] : 0;
    $detail = (string)($info[2] ?? '');

    if ($driverErr === 1452) {
        return 'Technician role is missing in the database (role id 1). Run db/schema.sql roles insert.';
    }
    if ($driverErr === 1062) {
        if (stripos($detail, 'email') !== false) {
            return 'This email is already in use.';
        }
        return 'This Staff ID is already in use.';
    }
    return 'Could not create technician. Please try again.';
}

$msg = '';
$msgKind = 'error';

$form = [
    'full_name' => '',
    'staff_id' => '',
    'email' => '',
    'phone' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['full_name'] = trim((string)($_POST['full_name'] ?? ''));
    $form['staff_id'] = trim((string)($_POST['staff_id'] ?? ''));
    $form['email'] = strtolower(trim((string)($_POST['email'] ?? '')));
    $form['phone'] = trim((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($form['full_name'] === '' || $form['staff_id'] === '' || $form['email'] === '') {
        $msg = 'Full name, staff ID, and email are required.';
    } elseif (strlen($form['staff_id']) > 32) {
        $msg = 'Staff ID is too long (max 32 characters).';
    } elseif (strlen($form['full_name']) > 128) {
        $msg = 'Full name is too long (max 128 characters).';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.';
    } elseif ($form['phone'] !== '' && strlen(preg_replace('/\D/', '', $form['phone'])) < 8) {
        $msg = 'Please enter a valid phone number (at least 8 digits) or leave it blank.';
    } elseif (strlen($password) < 6) {
        $msg = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $msg = 'Password confirmation does not match.';
    } else {
        $pdo = db();
        $roleOk = $pdo->query('SELECT 1 FROM role WHERE id = 1 LIMIT 1')->fetchColumn();
        if (!$roleOk) {
            $msg = 'Technician role (id 1) is missing. Contact IT.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (staff_id, full_name, email, phone, password_hash, role_id) VALUES (?, ?, ?, ?, ?, 1)');
            try {
                $stmt->execute([$form['staff_id'], $form['full_name'], $form['email'], ($form['phone'] !== '' ? $form['phone'] : null), $hash]);
                header('Location: users.php?filter=technician&added=tech');
                exit;
            } catch (PDOException $e) {
                $msg = addtech_error_message($e);
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
    <title>Add Technician - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --bg: #f0f4ff;
            --card-bg: #ffffff;
            --sidebar-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-border: #e2e8f0;
            --glass-panel: #f8faff;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .blob { position: fixed; border-radius: 50%; filter: blur(90px); pointer-events: none; z-index: 0; }
        .blob-1 { width: 520px; height: 520px; background: rgba(37,99,235,0.06); top: -140px; right: -120px; }
        .blob-2 { width: 420px; height: 420px; background: rgba(124,58,237,0.05); bottom: -90px; left: -90px; }

        .main-content {
            margin-left: 280px; flex: 1;
            padding: 2.5rem 3.5rem 5rem;
            max-width: calc(100vw - 280px);
            position: relative; z-index: 1;
        }
        .page-header {
            display: flex; justify-content: space-between; align-items: flex-end;
            flex-wrap: wrap; gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--card-border);
        }
        .page-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.1rem;
            font-weight: 900;
            letter-spacing: -0.5px;
            display: flex; align-items: center; gap: 0.75rem;
        }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); margin-top: 0.35rem; max-width: 620px; line-height: 1.45; }
        .btn {
            padding: 0.75rem 1.2rem;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
        }
        .btn-outline {
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
        }
        .btn-outline:hover { color: var(--primary); border-color: rgba(37,99,235,0.25); background: rgba(37,99,235,0.06); }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.25);
        }
        .btn-primary:hover { transform: translateY(-2px); filter: brightness(1.06); }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            overflow: hidden;
            max-width: 820px;
        }
        .card-body { padding: 1.5rem 1.6rem; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-group { margin-bottom: 1rem; }
        .label {
            display:block;
            font-size: 0.72rem;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }
        .input {
            width: 100%;
            height: 2.9rem;
            padding: 0 0.95rem;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: var(--glass-panel);
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-main);
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .input:focus {
            background: #fff;
            border-color: rgba(37,99,235,0.45);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }
        .help { margin-top: 0.35rem; font-size: 0.83rem; color: var(--text-muted); font-weight: 600; line-height: 1.4; }
        .row-actions { display:flex; gap:0.75rem; justify-content:flex-end; align-items:center; margin-top: 0.5rem; flex-wrap:wrap; }

        .alert {
            padding: 0.9rem 1.15rem;
            border-radius: 12px;
            border: 1px solid rgba(239, 68, 68, 0.30);
            background: rgba(239, 68, 68, 0.10);
            color: #b91c1c;
            font-weight: 700;
            margin-bottom: 1rem;
            display:flex;
            gap:0.65rem;
            align-items:flex-start;
            line-height: 1.45;
        }
        .alert i { margin-top: 0.1rem; font-size: 1.2rem; }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
            .form-grid { grid-template-columns: 1fr; }
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
            <h1><i class="ri-tools-line"></i> Add Technician</h1>
            <p>Create a technician login account (role: <strong>Technician</strong>). The new user will appear in the People list.</p>
        </div>
        <a class="btn btn-outline" href="users.php"><i class="ri-arrow-left-line"></i> Back</a>
    </header>

    <?php if ($msg): ?>
        <div class="alert" role="alert"><i class="ri-error-warning-line"></i><span><?= htmlspecialchars($msg) ?></span></div>
    <?php endif; ?>

    <section class="card" aria-label="Add technician form">
        <div class="card-body">
            <form method="post" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="label" for="full_name">Full name</label>
                        <input class="input" id="full_name" name="full_name" type="text" required maxlength="128" value="<?= htmlspecialchars($form['full_name']) ?>" placeholder="Technician full name">
                    </div>
                    <div class="form-group">
                        <label class="label" for="staff_id">Staff ID</label>
                        <input class="input" id="staff_id" name="staff_id" type="text" required maxlength="32" value="<?= htmlspecialchars($form['staff_id']) ?>" placeholder="e.g. ITD001">
                        <div class="help">Must be unique (max 32 chars).</div>
                    </div>
                    <div class="form-group">
                        <label class="label" for="email">Email</label>
                        <input class="input" id="email" name="email" type="email" required maxlength="128" value="<?= htmlspecialchars($form['email']) ?>" placeholder="name@unikl.edu.my">
                    </div>
                    <div class="form-group">
                        <label class="label" for="phone">Phone (optional)</label>
                        <input class="input" id="phone" name="phone" type="tel" maxlength="64" value="<?= htmlspecialchars($form['phone']) ?>" placeholder="e.g. 012-345 6789">
                    </div>
                    <div class="form-group">
                        <label class="label" for="password">Password</label>
                        <input class="input" id="password" name="password" type="password" required minlength="6" placeholder="Min 6 characters" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label class="label" for="confirm_password">Confirm password</label>
                        <input class="input" id="confirm_password" name="confirm_password" type="password" required minlength="6" placeholder="Repeat password" autocomplete="new-password">
                    </div>
                </div>

                <div class="row-actions">
                    <a class="btn btn-outline" href="users.php"><i class="ri-close-line"></i> Cancel</a>
                    <button class="btn btn-primary" type="submit"><i class="ri-user-add-line"></i> Create technician</button>
                </div>
            </form>
        </div>
    </section>
</main>
</body>
</html>
