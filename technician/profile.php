<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$pdo = db();
$staffId = (string)$_SESSION['staff_id'];
$error = '';
$success = '';

$stmt = $pdo->prepare('
    SELECT u.staff_id, u.full_name, u.email, u.role_id, u.password_hash, u.created_at, r.name AS role_name
    FROM users u
    JOIN role r ON r.id = u.role_id
    WHERE u.staff_id = ?
');
$stmt->execute([$staffId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header('Location: ../auth/logout.php');
    exit;
}

$full_name_val = $user['full_name'];
$email_val = $user['email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $current_pw = (string)($_POST['current_password'] ?? '');
    $new_pw = (string)($_POST['new_password'] ?? '');
    $confirm_pw = (string)($_POST['confirm_password'] ?? '');

    $full_name_val = $full_name;
    $email_val = $email;

    if ($full_name === '' || $email === '') {
        $error = 'Full name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $chk = $pdo->prepare('SELECT staff_id FROM users WHERE email = ? AND staff_id != ?');
        $chk->execute([$email, $staffId]);
        if ($chk->fetch()) {
            $error = 'That email is already in use by another account.';
        }
    }

    $changing_pw = ($new_pw !== '' || $confirm_pw !== '');
    if ($error === '' && $changing_pw) {
        if ($current_pw === '') {
            $error = 'Enter your current password to set a new one.';
        } elseif ($new_pw !== $confirm_pw) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new_pw) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif (!password_verify($current_pw, $user['password_hash']) && $current_pw !== $user['password_hash']) {
            $error = 'Current password is incorrect.';
        }
    }

    if ($error === '') {
        try {
            if ($changing_pw) {
                $hash = password_hash($new_pw, PASSWORD_DEFAULT);
                $up = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, password_hash = ? WHERE staff_id = ?');
                $up->execute([$full_name, $email, $hash, $staffId]);
            } else {
                $up = $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE staff_id = ?');
                $up->execute([$full_name, $email, $staffId]);
            }
            $_SESSION['user_name'] = $full_name;
            $success = 'Profile updated successfully.';
            $stmt->execute([$staffId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $full_name_val = $user['full_name'];
            $email_val = $user['email'];
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $error = 'That email is already in use.';
            } else {
                $error = 'Could not save changes. Try again.';
            }
        }
    }
}

$created = !empty($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - RCMP NIMS</title>
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
        }
        .blob { position: fixed; border-radius: 50%; filter: blur(90px); pointer-events: none; z-index: 0; }
        .blob-1 { width: 500px; height: 500px; background: rgba(37,99,235,0.06); top: -120px; right: -100px; }
        .blob-2 { width: 400px; height: 400px; background: rgba(124,58,237,0.05); bottom: -80px; left: -80px; }
        .sidebar {
            width: 280px; min-height: 100vh; background: var(--sidebar-bg);
            border-right: 1px solid var(--card-border);
            display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; bottom: 0;
            z-index: 100; box-shadow: 2px 0 20px rgba(15,23,42,0.06);
        }
        .nav-group { display: flex; flex-direction: column; gap: 0.25rem; }
        .sidebar-logo { padding: 1.5rem 1.75rem 1.25rem; border-bottom: 1px solid var(--card-border); text-align: center; }
        .sidebar-logo img { height: 42px; object-fit: contain; }
        .nav-menu { flex: 1; padding: 1.25rem 1rem; display: flex; flex-direction: column; gap: 0.25rem; overflow-y: auto; }
        .nav-item {
            display: flex; align-items: center; gap: 1rem;
            padding: 0.75rem 1.25rem; border-radius: 12px;
            color: var(--text-muted); text-decoration: none;
            font-weight: 500; font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .nav-item:hover, .nav-item.open { background: rgba(37,99,235,0.06); color: var(--primary); }
        .nav-item.active { background: rgba(37,99,235,0.1); color: var(--primary); font-weight: 600; }
        .nav-item i { font-size: 1.25rem; }
        .nav-dropdown { display: none; flex-direction: column; gap: 0.25rem; padding-left: 3.25rem; margin-top: -0.25rem; margin-bottom: 0.25rem; }
        .nav-dropdown.show { display: flex; }
        .nav-dropdown-item {
            padding: 0.6rem 1rem; border-radius: 8px;
            color: var(--text-muted); text-decoration: none;
            font-size: 0.85rem; font-weight: 500;
        }
        .nav-dropdown-item:hover { color: var(--primary); background: rgba(37,99,235,0.06); }
        .nav-dropdown-item.active { color: var(--primary); }
        .nav-item.open .chevron { transform: rotate(180deg); }
        .user-profile {
            padding: 1.25rem 1.75rem; border-top: 1px solid var(--card-border);
            display: flex; align-items: center; gap: 0.75rem;
            cursor: pointer; margin-top: auto;
        }
        .user-profile:hover { background: rgba(37,99,235,0.04); }
        .avatar {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif; font-weight: 700; color: #fff; font-size: 1rem;
        }
        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-size: 0.9rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role { font-size: 0.75rem; color: var(--primary); margin-top: 0.2rem; text-transform: uppercase; font-weight: 600; }
        .main-content {
            margin-left: 280px; flex: 1;
            padding: 2.5rem 3.5rem 5rem;
            max-width: calc(100vw - 280px);
            position: relative; z-index: 1;
        }
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--card-border); padding-bottom: 1.5rem;
        }
        .page-title h1 {
            font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700;
            display: flex; align-items: center; gap: 0.75rem;
        }
        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); margin-top: 0.25rem; font-size: 0.95rem; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 0.5rem;
            color: var(--text-muted); text-decoration: none; font-weight: 500;
            background: var(--glass-panel); padding: 0.6rem 1.2rem;
            border-radius: 12px; border: 1px solid var(--card-border);
        }
        .btn-back:hover { color: var(--primary); border-color: rgba(37,99,235,0.2); }
        .alert {
            padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.25rem;
            display: flex; align-items: center; gap: 0.75rem; font-size: 0.95rem;
        }
        .alert-error { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.25); color: #b91c1c; }
        .alert-success { background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.25); color: #047857; }
        .grid-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem 1.5rem;
        }
        @media (max-width: 900px) {
            .grid-form { grid-template-columns: 1fr; }
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
        }
        .card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 20px; padding: 2rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            margin-bottom: 1.75rem;
        }
        .card-title {
            font-family: 'Outfit', sans-serif; font-size: 1.15rem; font-weight: 700;
            display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1.25rem;
        }
        .card-title i { color: var(--primary); }
        .field label {
            display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-muted);
            margin-bottom: 0.45rem; text-transform: uppercase; letter-spacing: 0.03em;
        }
        .field input, .field .readonly {
            width: 100%; padding: 0.75rem 1rem;
            border: 1px solid var(--card-border); border-radius: 12px;
            font-size: 0.95rem; font-family: inherit; background: #fff;
        }
        .field input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }
        .field .readonly { background: var(--glass-panel); color: var(--text-muted); }
        .hint { font-size: 0.82rem; color: var(--text-muted); margin-top: 0.35rem; }
        .btn-row { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1.75rem; }
        .btn {
            padding: 0.75rem 1.75rem; border-radius: 12px; font-weight: 600; font-size: 0.95rem;
            cursor: pointer; border: none;
            display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff; box-shadow: 0 6px 20px rgba(37,99,235,0.3);
        }
        .btn-primary:hover { filter: brightness(1.05); }
        .divider { height: 1px; background: var(--card-border); margin: 1.5rem 0; }
    </style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div class="page-title">
            <h1><i class="ri-user-settings-line"></i> Your profile</h1>
            <p>Update your name, email, and password. Staff ID and role are managed by an administrator.</p>
        </div>
        <a href="dashboard.php" class="btn-back"><i class="ri-arrow-left-line"></i> Dashboard</a>
    </header>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="ri-checkbox-circle-line"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="card">
            <div class="card-title"><i class="ri-id-card-line"></i> Account</div>
            <div class="grid-form">
                <div class="field">
                    <label>Staff ID</label>
                    <div class="readonly"><?= htmlspecialchars($user['staff_id']) ?></div>
                </div>
                <div class="field">
                    <label>Role</label>
                    <div class="readonly"><?= htmlspecialchars(ucfirst($user['role_name'] ?? 'technician')) ?></div>
                </div>
                <div class="field">
                    <label>Member since</label>
                    <div class="readonly"><?= htmlspecialchars($created) ?></div>
                </div>
                <div class="field">
                    <label for="full_name">Full name</label>
                    <input id="full_name" name="full_name" required maxlength="128"
                           value="<?= htmlspecialchars($full_name_val) ?>">
                </div>
                <div class="field" style="grid-column: 1 / -1;">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" required maxlength="128"
                           value="<?= htmlspecialchars($email_val) ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title"><i class="ri-lock-password-line"></i> Change password</div>
            <p class="hint" style="margin-bottom:1rem;">Leave blank to keep your current password.</p>
            <div class="grid-form">
                <div class="field">
                    <label for="current_password">Current password</label>
                    <input id="current_password" name="current_password" type="password" autocomplete="current-password">
                </div>
                <div></div>
                <div class="field">
                    <label for="new_password">New password</label>
                    <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8">
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm new password</label>
                    <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8">
                </div>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary"><i class="ri-save-3-line"></i> Save changes</button>
            </div>
        </div>
    </form>
</main>

<script>
function toggleDropdown(el, e) {
    e.preventDefault();
    const g = el.closest('.nav-group');
    const d = g.querySelector('.nav-dropdown');
    el.classList.toggle('open');
    d.classList.toggle('show');
}
</script>
</body>
</html>
