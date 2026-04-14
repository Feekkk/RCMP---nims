<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 2) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$staffId = trim((string)($_GET['staff_id'] ?? $_POST['staff_id'] ?? ''));
$ok = isset($_GET['saved']) && (string)$_GET['saved'] === '1';
$error = '';
$userRow = null;
$roles = [];

try {
    $pdo = db();
    $roles = $pdo->query('SELECT id, name FROM role ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    if ($staffId === '') {
        throw new RuntimeException('Missing staff_id.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
        $full = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $roleId = (int)($_POST['role_id'] ?? 0);
        $phone = trim((string)($_POST['phone'] ?? ''));
        $pwd = (string)($_POST['new_password'] ?? '');

        if ($full === '' || mb_strlen($full) < 2) throw new RuntimeException('Full name is required.');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Valid email is required.');
        if (!in_array($roleId, [1, 2, 3], true)) throw new RuntimeException('Invalid role.');

        $pdo->beginTransaction();
        try {
            if ($pwd !== '') {
                if (mb_strlen($pwd) < 6) throw new RuntimeException('Password must be at least 6 characters.');
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $stmtU = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role_id = ?, phone = ?, password_hash = ? WHERE staff_id = ?');
                $stmtU->execute([$full, $email, $roleId, $phone !== '' ? $phone : null, $hash, $staffId]);
            } else {
                $stmtU = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role_id = ?, phone = ? WHERE staff_id = ?');
                $stmtU->execute([$full, $email, $roleId, $phone !== '' ? $phone : null, $staffId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        header('Location: editUser.php?staff_id=' . urlencode($staffId) . '&saved=1');
        exit;
    }

    $stmt = $pdo->prepare('SELECT staff_id, full_name, email, role_id, phone, created_at, updated_at FROM users WHERE staff_id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) throw new RuntimeException('User not found.');
} catch (Throwable $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit user — Admin — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root{--primary:#2563eb;--secondary:#7c3aed;--bg:#f0f4ff;--card:#fff;--border:#e2e8f0;--text:#0f172a;--muted:#64748b;--glass:#f8faff;--success:#10b981;--danger:#ef4444;}
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;overflow-x:hidden}

        .sidebar{width:280px;min-height:100vh;background:var(--card);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;box-shadow:2px 0 20px rgba(15,23,42,0.06)}
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
        @media (max-width:900px){.sidebar{transform:translateX(-100%);width:260px}.main-content{margin-left:0;max-width:100vw;padding:1.25rem 1rem}}

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
        .actions{display:flex;gap:0.6rem;flex-wrap:wrap;margin-top:1rem}
        .btn{display:inline-flex;align-items:center;gap:0.45rem;padding:0.75rem 1.1rem;border-radius:12px;border:1.5px solid var(--border);background:#fff;color:var(--text);font-weight:800;text-decoration:none;cursor:pointer}
        .btn.primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;color:#fff}
        .hint{color:var(--muted);font-size:0.82rem;margin-top:0.4rem}
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarAdmin.php'; ?>
<main class="main-content">
    <div class="card">
        <div class="hd">
            <h1><i class="ri-user-settings-line"></i> Edit user</h1>
            <a class="btn" href="users.php"><i class="ri-arrow-left-line"></i> Back</a>
        </div>
        <div class="bd">
            <?php if ($ok): ?><div class="banner ok"><i class="ri-checkbox-circle-line"></i> Saved.</div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="banner err"><i class="ri-error-warning-line"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

            <?php if ($userRow): ?>
            <form method="post" action="">
                <input type="hidden" name="save_user" value="1">
                <input type="hidden" name="staff_id" value="<?= htmlspecialchars((string)$userRow['staff_id']) ?>">
                <div class="grid">
                    <div class="field">
                        <label>Staff ID</label>
                        <input value="<?= htmlspecialchars((string)$userRow['staff_id']) ?>" disabled>
                    </div>
                    <div class="field">
                        <label for="role_id">Role</label>
                        <select id="role_id" name="role_id" required>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= (int)$userRow['role_id'] === (int)$r['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$r['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="full_name">Full name</label>
                        <input id="full_name" name="full_name" value="<?= htmlspecialchars((string)$userRow['full_name']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" name="email" value="<?= htmlspecialchars((string)$userRow['email']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" value="<?= htmlspecialchars((string)($userRow['phone'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label for="new_password">New password (optional)</label>
                        <input id="new_password" name="new_password" type="password" autocomplete="new-password" placeholder="Leave blank to keep current">
                        <div class="hint">If set, the password will be replaced.</div>
                    </div>
                </div>
                <div class="actions">
                    <button class="btn primary" type="submit"><i class="ri-save-3-line"></i> Save</button>
                    <a class="btn" href="users.php"><i class="ri-close-line"></i> Cancel</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>