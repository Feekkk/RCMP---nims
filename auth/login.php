<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staffId = trim($_POST['staff_id'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare('SELECT staff_id, password_hash, role_id FROM users WHERE staff_id = ?');
    $stmt->execute([$staffId]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['staff_id'] = $user['staff_id'];
        $_SESSION['role_id'] = $user['role_id'];

        if ((int)$user['role_id'] === 1) {
            header('Location: ../technician/dashboard.php');
        } else {
            header('Location: ../admin/dashboard.php');
        }
        exit;
    } else {
        $err = 'Invalid staff ID or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Staff Login - RCMP NIMS</title>
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
            --darker: #020617;
            --glass-bg: rgba(15, 23, 42, 0.65);
            --glass-border: rgba(255, 255, 255, 0.1);
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --input-bg: rgba(255, 255, 255, 0.05);
            --input-border: rgba(255, 255, 255, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--darker);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        /* Background Setup */
        .page-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            background-image: url('../public/bgm.png');
            background-size: cover;
            background-position: center;
        }

        .bg-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background: linear-gradient(135deg, rgba(2, 6, 23, 0.95) 0%, rgba(15, 23, 42, 0.8) 100%);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        /* Decorative glowing orbs */
        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            z-index: -1;
            opacity: 0.4;
        }

        .blob-1 {
            width: 400px;
            height: 400px;
            background: var(--primary);
            top: -100px;
            left: -100px;
        }

        .blob-2 {
            width: 350px;
            height: 350px;
            background: #8b5cf6;
            bottom: -50px;
            right: -50px;
        }

        /* Login Container */
        .auth-container {
            width: 100%;
            max-width: 420px;
            padding: 2.5rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), inset 0 1px 0 0 rgba(255,255,255,0.05);
            animation: fadeIn 0.6s ease-out forwards;
        }

        .auth-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .auth-logo {
            height: 60px;
            margin-bottom: 1.25rem;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.4));
        }

        .auth-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }

        .auth-subtitle {
            font-size: 0.95rem;
            color: var(--text-muted);
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .form-icon {
            position: absolute;
            left: 1rem;
            color: var(--text-muted);
            font-size: 1.2rem;
            pointer-events: none;
            transition: color 0.3s ease;
        }

        .password-toggle {
            position: absolute;
            right: 0.9rem;
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            font-size: 1.1rem;
        }

        .form-input {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 12px;
            padding: 0.85rem 1rem 0.85rem 3rem;
            color: white;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
            outline: none;
        }

        .form-input:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--secondary);
            box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.15);
        }

        .form-input:focus + .form-icon,
        .form-input:not(:placeholder-shown) + .form-icon {
            color: var(--secondary);
        }

        .role-selector {
            display: flex;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .role-option {
            flex: 1;
            text-align: center;
        }

        .role-option input[type="radio"] {
            display: none;
        }

        .role-label {
            display: block;
            padding: 0.75rem;
            color: var(--text-muted);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            border-right: 1px solid var(--input-border);
        }

        .role-option:last-child .role-label {
            border-right: none;
        }

        .role-option input[type="radio"]:checked + .role-label {
            background: rgba(37, 99, 235, 0.4); /* Transparent primary */
            color: white;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            font-size: 0.9rem;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .custom-checkbox {
            width: 18px;
            height: 18px;
            border: 1px solid var(--input-border);
            border-radius: 4px;
            background: var(--input-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .checkbox-wrapper input:checked + .custom-checkbox {
            background: var(--primary);
            border-color: var(--primary);
        }

        .checkbox-wrapper input:checked + .custom-checkbox::after {
            content: '\EB7A'; /* Remix icon check mark line */
            font-family: 'remixicon';
            color: white;
            font-size: 0.9rem;
        }

        .checkbox-wrapper input {
            display: none;
        }

        .text-link {
            color: var(--secondary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .text-link:hover {
            color: #38bdf8;
            text-decoration: underline;
        }

        .btn-submit {
            width: 100%;
            padding: 0.9rem;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 10px 20px -5px rgba(37, 99, 235, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px -5px rgba(37, 99, 235, 0.5);
            filter: brightness(1.1);
        }

        .auth-footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.95rem;
            color: var(--text-muted);
        }

        .back-home {
            position: absolute;
            top: 2rem;
            left: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            z-index: 10;
        }

        .back-home:hover {
            color: white;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 480px) {
            .auth-container {
                padding: 2rem;
                width: 90%;
            }
            .back-home {
                top: 1rem;
                left: 1rem;
            }
        }
    </style>
</head>
<body>

    <div class="page-bg"></div>
    <div class="bg-overlay"></div>
    
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <a href="../index.php" class="back-home">
        <i class="ri-arrow-left-line"></i> Back to Homepage
    </a>

    <div class="auth-container">
        <div class="auth-header">
            <img src="../public/logo-nims.png" alt="RCMP NIMS" class="auth-logo">
            <h1 class="auth-title">Staff Login</h1>
            <p class="auth-subtitle">Welcome back to the IT Control Center</p>
        </div>

        <?php if (isset($err)): ?><p style="color:#ef4444;margin-bottom:1rem;font-size:0.9rem;"><?= htmlspecialchars($err) ?></p><?php endif; ?>
        <?php if (!empty($_GET['registered'])): ?><p style="color:#10b981;margin-bottom:1rem;font-size:0.9rem;">Registration successful. Please log in.</p><?php endif; ?>
        <form action="" method="POST">
            <div class="form-group">
                <label class="form-label">Choose your role</label>
                <div class="role-selector">
                    <div class="role-option">
                        <input type="radio" id="role_technician" name="role" value="1" checked>
                        <label for="role_technician" class="role-label"><i class="ri-user-line" style="margin-right: 5px;"></i> Technician</label>
                    </div>
                    <div class="role-option">
                        <input type="radio" id="role_admin" name="role" value="2">
                        <label for="role_admin" class="role-label"><i class="ri-shield-user-line" style="margin-right: 5px;"></i> Administrator</label>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="staff_id">Staff ID</label>
                <div class="form-input-wrapper">
                    <input type="text" id="staff_id" name="staff_id" class="form-input" placeholder="e.g. IT-12345" required>
                    <i class="ri-user-line form-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <div class="form-input-wrapper">
                    <input type="password" id="password" name="password" class="form-input" placeholder="Enter your password" required>
                    <i class="ri-lock-line form-icon"></i>
                    <button type="button" class="password-toggle" data-target="password" aria-label="Toggle password visibility">
                        <i class="ri-eye-off-line"></i>
                    </button>
                </div>
            </div>

            <div class="form-options">
                <label class="checkbox-wrapper">
                    <input type="checkbox" name="remember">
                    <div class="custom-checkbox"></div>
                    <span style="color: var(--text-muted);">Remember me</span>
                </label>
            </div>

            <button type="submit" class="btn-submit">
                Access System <i class="ri-arrow-right-line"></i>
            </button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="register.php" class="text-link">Register here</a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.password-toggle').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetId = btn.getAttribute('data-target');
                    var input = document.getElementById(targetId);
                    if (!input) return;
                    var icon = btn.querySelector('i');
                    if (input.type === 'password') {
                        input.type = 'text';
                        if (icon) {
                            icon.classList.remove('ri-eye-off-line');
                            icon.classList.add('ri-eye-line');
                        }
                    } else {
                        input.type = 'password';
                        if (icon) {
                            icon.classList.remove('ri-eye-line');
                            icon.classList.add('ri-eye-off-line');
                        }
                    }
                });
            });
        });
    </script>

</body>
</html>
