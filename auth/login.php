<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$posted_role = ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['role'] ?? '') === 'user')) ? 'user' : 'staff';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['login'] ?? ''));
    $password = $_POST['password'] ?? '';
    $loginAs = $_POST['role'] ?? 'staff';
    $loginAs = ($loginAs === 'user') ? 'user' : 'staff';

    if ($identifier === '') {
        $err = $loginAs === 'user' ? 'Please enter your email.' : 'Please enter your Staff ID.';
    } else {
        $pdo = db();
        if ($loginAs === 'user') {
            $email = strtolower($identifier);
            $stmt = $pdo->prepare('SELECT staff_id, full_name, password_hash, role_id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
            $stmt->execute([$email]);
        } else {
            $stmt = $pdo->prepare('SELECT staff_id, full_name, password_hash, role_id FROM users WHERE staff_id = ? LIMIT 1');
            $stmt->execute([$identifier]);
        }
        $user = $stmt->fetch();
        $ok = $user && (password_verify($password, $user['password_hash']) || $password === $user['password_hash']);

        if (!$ok) {
            $err = $loginAs === 'user' ? 'Invalid email or password.' : 'Invalid Staff ID or password.';
        } else {
            $rid = (int)$user['role_id'];
            if ($loginAs === 'staff') {
                if ($rid !== 1 && $rid !== 2) {
                    $err = 'This account is a NextCheck user. Select User to sign in.';
                } else {
                    $_SESSION['staff_id'] = $user['staff_id'];
                    $_SESSION['role_id']  = $rid;
                    $_SESSION['user_name']= $user['full_name'] ?? '';
                    header('Location: ' . ($rid === 1 ? '../technician/dashboard.php' : '../admin/dashboard.php'));
                    exit;
                }
            } elseif ($rid !== 3) {
                $err = 'This account is for staff only. Select Staff to sign in.';
            } else {
                $_SESSION['staff_id'] = $user['staff_id'];
                $_SESSION['role_id']  = $rid;
                $_SESSION['user_name']= $user['full_name'] ?? '';
                header('Location: ../users/landingPage.php');
                exit;
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
    <title>Sign in — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary:   #2563eb;
            --secondary: #0ea5e9;
            --accent:    #f59e0b;
            --green:     #10b981;
            --bg:        #f0f5ff;
            --surface:   #ffffff;
            --card-border: rgba(37,99,235,0.1);
            --text-main:   #0f172a;
            --text-muted:  #64748b;
            --text-subtle: #94a3b8;
            --input-bg:    #f8faff;
            --input-border: rgba(37,99,235,0.14);
            --mono: 'JetBrains Mono', monospace;
        }
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
        html{scroll-behavior:smooth;}
        body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text-main);min-height:100vh;display:flex;overflow:hidden;}

        /* Background */
        .bg-layer{position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 80% 60% at 10% 5%,rgba(37,99,235,0.08) 0%,transparent 65%),radial-gradient(ellipse 70% 55% at 90% 90%,rgba(14,165,233,0.07) 0%,transparent 65%),linear-gradient(160deg,#eef4ff 0%,#f0f5ff 60%,#e9f4fd 100%);}
        .grid-layer{position:fixed;inset:0;z-index:1;background-image:linear-gradient(rgba(37,99,235,0.05) 1px,transparent 1px),linear-gradient(90deg,rgba(37,99,235,0.05) 1px,transparent 1px);background-size:56px 56px;animation:gridMove 22s linear infinite;}
        @keyframes gridMove{to{background-position:56px 56px;}}

        /* Split */
        .split-left{width:42%;display:flex;flex-direction:column;justify-content:center;padding:4rem 5%;position:relative;z-index:10;border-right:1px solid rgba(37,99,235,0.08);}
        .split-right{flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:4rem 5%;position:relative;z-index:10;}

        /* Left panel */
        .brand-area{opacity:0;transform:translateY(24px);animation:riseIn 0.8s cubic-bezier(0.16,1,0.3,1) 0.05s forwards;}
        @keyframes riseIn{to{opacity:1;transform:translateY(0);}}

        .brand-logo{height:52px;object-fit:contain;filter:drop-shadow(0 2px 8px rgba(37,99,235,0.15));margin-bottom:2.5rem;transition:transform 0.4s cubic-bezier(0.34,1.56,0.64,1);}
        .brand-logo:hover{transform:scale(1.06);}

        .brand-eyebrow{font-family:var(--mono);font-size:0.7rem;letter-spacing:2.5px;text-transform:uppercase;color:var(--primary);margin-bottom:1rem;display:flex;align-items:center;gap:8px;}
        .eyebrow-line{height:1px;width:30px;background:var(--primary);opacity:0.4;}

        .brand-headline{font-size:3.2rem;font-weight:900;line-height:1;letter-spacing:-2px;margin-bottom:1rem;background:linear-gradient(135deg,#0f172a 40%,#2563eb 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
        .brand-sub{font-size:0.95rem;color:var(--text-muted);line-height:1.7;max-width:300px;margin-bottom:3rem;}

        .info-cards{display:flex;flex-direction:column;gap:0.75rem;}
        .info-card{display:flex;align-items:center;gap:12px;padding:0.85rem 1.1rem;background:rgba(255,255,255,0.7);border:1px solid rgba(37,99,235,0.1);border-radius:12px;opacity:0;transform:translateX(-16px);animation:slideRight 0.6s cubic-bezier(0.16,1,0.3,1) forwards;box-shadow:0 2px 10px rgba(37,99,235,0.05);}
        .info-card:nth-child(1){animation-delay:0.5s;} .info-card:nth-child(2){animation-delay:0.65s;} .info-card:nth-child(3){animation-delay:0.8s;}
        @keyframes slideRight{to{opacity:1;transform:translateX(0);}}
        .info-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
        .info-text{font-size:0.82rem;color:var(--text-muted);line-height:1.4;}
        .info-text strong{color:var(--text-main);display:block;font-weight:600;font-size:0.88rem;}

        /* Form card */
        .form-card{width:100%;max-width:420px;background:rgba(255,255,255,0.85);border:1px solid rgba(37,99,235,0.1);border-radius:24px;padding:2.5rem;backdrop-filter:blur(24px);box-shadow:0 24px 60px rgba(37,99,235,0.1),0 4px 16px rgba(37,99,235,0.06),inset 0 1px 0 rgba(255,255,255,1);position:relative;overflow:hidden;opacity:0;transform:translateY(32px) scale(0.98);animation:cardIn 0.9s cubic-bezier(0.16,1,0.3,1) 0.2s forwards;}
        .form-card::before{content:'';position:absolute;top:0;left:20%;right:20%;height:1px;background:linear-gradient(90deg,transparent,var(--primary),transparent);opacity:0.35;}
        @keyframes cardIn{to{opacity:1;transform:translateY(0) scale(1);}}

        .form-header{text-align:center;margin-bottom:2rem;}
        .form-title{font-size:1.5rem;font-weight:800;letter-spacing:-0.5px;color:var(--text-main);margin-bottom:0.3rem;}
        .form-sub{font-size:0.8rem;color:var(--text-muted);font-family:var(--mono);}

        /* Role toggle */
        .role-toggle{display:flex;background:rgba(37,99,235,0.04);border:1px solid rgba(37,99,235,0.12);border-radius:10px;overflow:hidden;margin-bottom:1.75rem;}
        .role-opt{flex:1;}
        .role-opt input[type="radio"]{display:none;}
        .role-opt label{display:flex;align-items:center;justify-content:center;gap:6px;padding:0.65rem;font-size:0.83rem;font-weight:600;color:var(--text-muted);cursor:pointer;transition:all 0.25s;border-right:1px solid rgba(37,99,235,0.1);}
        .role-opt:last-child label{border-right:none;}
        .role-opt input:checked+label{background:linear-gradient(135deg,rgba(37,99,235,0.12),rgba(14,165,233,0.08));color:var(--primary);}

        /* Form groups */
        .form-group{margin-bottom:1.25rem;position:relative;}
        .form-label{display:block;font-family:var(--mono);font-size:0.68rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:0.5rem;}
        .input-wrap{position:relative;display:flex;align-items:center;}
        .input-icon{position:absolute;left:0.9rem;color:var(--text-subtle);font-size:1rem;pointer-events:none;transition:color 0.25s;z-index:1;}
        .form-input{width:100%;background:var(--input-bg);border:1px solid var(--input-border);border-radius:10px;padding:0.8rem 1rem 0.8rem 2.6rem;color:var(--text-main);font-family:'Outfit',sans-serif;font-size:0.95rem;transition:all 0.25s;outline:none;}
        .form-input::placeholder{color:var(--text-subtle);font-size:0.9rem;}
        .form-input:focus{background:white;border-color:rgba(37,99,235,0.4);box-shadow:0 0 0 3px rgba(37,99,235,0.1);}
        .form-input:focus~.input-icon{color:var(--primary);}
        .pw-toggle{position:absolute;right:0.85rem;background:none;border:none;color:var(--text-subtle);cursor:pointer;font-size:1rem;padding:0;transition:color 0.2s;}
        .pw-toggle:hover{color:var(--text-main);}

        /* Alert — icon + single text wrapper (avoid flex splitting each <strong> / text node) */
        .alert{display:flex;align-items:flex-start;gap:10px;padding:0.75rem 1rem;border-radius:8px;font-size:0.88rem;margin-bottom:1.25rem;line-height:1.45;}
        .alert>i{flex-shrink:0;font-size:1.05rem;line-height:1.35;margin-top:0.1rem;}
        .alert-body{flex:1;min-width:0;font-family:'Outfit',sans-serif;font-weight:500;}
        .alert-body strong{font-weight:700;}
        .alert-error{background:rgba(239,68,68,0.07);border:1px solid rgba(239,68,68,0.18);color:#dc2626;}
        .alert-error .alert-body{font-family:var(--mono);font-size:0.83rem;}
        .alert-success{background:rgba(16,185,129,0.07);border:1px solid rgba(16,185,129,0.18);color:#059669;}

        /* Options */
        .form-options{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;}
        .remember{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.82rem;color:var(--text-muted);}
        .remember input{display:none;}
        .checkmark{width:16px;height:16px;border-radius:4px;border:1px solid rgba(37,99,235,0.2);background:var(--input-bg);display:flex;align-items:center;justify-content:center;transition:all 0.2s;}
        .remember input:checked+.checkmark{background:var(--primary);border-color:var(--primary);}
        .remember input:checked+.checkmark::after{content:'';width:8px;height:5px;border-left:2px solid white;border-bottom:2px solid white;transform:rotate(-45deg) translate(1px,-1px);display:block;}

        /* Submit */
        .btn-submit{width:100%;padding:0.85rem;border-radius:10px;border:none;background:linear-gradient(135deg,var(--primary),var(--secondary));color:white;font-family:'Outfit',sans-serif;font-size:1rem;font-weight:700;cursor:pointer;transition:all 0.3s;box-shadow:0 6px 20px rgba(37,99,235,0.28);display:flex;align-items:center;justify-content:center;gap:8px;position:relative;overflow:hidden;}
        .btn-submit::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.25),transparent);transition:left 0.5s;}
        .btn-submit:hover::before{left:100%;}
        .btn-submit:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(37,99,235,0.36);}

        .form-footer{text-align:center;margin-top:1.5rem;font-size:0.82rem;color:var(--text-muted);}
        .form-footer a{color:var(--primary);text-decoration:none;font-weight:600;transition:color 0.2s;}
        .form-footer a:hover{color:var(--primary-hover);}

        /* Back link */
        .back-link{position:absolute;top:2rem;left:2rem;display:flex;align-items:center;gap:6px;color:var(--text-muted);text-decoration:none;font-size:0.8rem;font-family:var(--mono);letter-spacing:0.5px;transition:color 0.2s;z-index:20;}
        .back-link:hover{color:var(--primary);}

        /* Status strip */
        .status-strip{position:absolute;bottom:1.5rem;left:0;right:0;display:flex;justify-content:center;gap:1.5rem;font-family:var(--mono);font-size:0.68rem;color:var(--text-muted);z-index:20;}
        .status-item{display:flex;align-items:center;gap:5px;}
        .s-dot{width:5px;height:5px;border-radius:50%;}

        @media(max-width:900px){.split-left{display:none;}.split-right{padding:2rem;}body{overflow:auto;}}
        @media(max-width:480px){.form-card{padding:1.75rem;border-radius:20px;}}
        @keyframes spin{to{transform:rotate(360deg);}}
    </style>
</head>
<body>
<div class="bg-layer"></div>
<div class="grid-layer"></div>

<a href="../index.php" class="back-link"><i class="ri-arrow-left-line"></i> Back</a>

<!-- Left -->
<div class="split-left">
    <div class="brand-area">
        <img src="../public/logo-nims.png" alt="NIMS" class="brand-logo">
        <div class="brand-eyebrow"><span class="eyebrow-line"></span> UniKL RCMP IT Dept</div>
        <h1 class="brand-headline">Secure<br>Access<br>Portal</h1>
        <p class="brand-sub"><strong>Staff</strong> — technicians and administrators. <strong>User</strong> — NextCheck checkout accounts (register to create).</p>
        <div class="info-cards">
            <div class="info-card">
                <div class="info-icon" style="background:rgba(37,99,235,0.1);color:#2563eb;"><i class="ri-shield-keyhole-line"></i></div>
                <div class="info-text"><strong>Encrypted Sessions</strong>All data is secured end-to-end</div>
            </div>
            <div class="info-card">
                <div class="info-icon" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="ri-database-2-line"></i></div>
                <div class="info-text"><strong>1,542 Assets Online</strong>Live tracking across all departments</div>
            </div>
            <div class="info-card">
                <div class="info-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="ri-time-line"></i></div>
                <div class="info-text"><strong>24/7 Monitoring</strong>System health checked continuously</div>
            </div>
        </div>
    </div>
</div>

<!-- Right -->
<div class="split-right">
    <div class="form-card">
        <div class="form-header">
            <div class="form-title">Welcome Back</div>
            <div class="form-sub">// rcmp-nims · staff or nextcheck user</div>
        </div>

        <?php if(isset($err)): ?>
        <div class="alert alert-error"><i class="ri-error-warning-line"></i><span class="alert-body"><?= htmlspecialchars($err) ?></span></div>
        <?php endif; ?>
        <?php if(!empty($_GET['registered'])): ?>
        <div class="alert alert-success"><i class="ri-checkbox-circle-line"></i><span class="alert-body">Account created. Sign in as <strong>User</strong> with your <strong>email</strong> and password.</span></div>
        <?php endif; ?>

        <form action="" method="POST" id="loginForm">
            <div class="role-toggle">
                <div class="role-opt"><input type="radio" id="r_staff" name="role" value="staff" <?= $posted_role === 'staff' ? 'checked' : '' ?>><label for="r_staff"><i class="ri-team-line"></i> Staff</label></div>
                <div class="role-opt"><input type="radio" id="r_user" name="role" value="user" <?= $posted_role === 'user' ? 'checked' : '' ?>><label for="r_user"><i class="ri-user-smile-line"></i> User</label></div>
            </div>

            <div class="form-group">
                <label class="form-label" for="loginField" id="loginLabel"><?= $posted_role === 'user' ? 'Email' : 'Staff ID' ?></label>
                <div class="input-wrap">
                    <input type="<?= $posted_role === 'user' ? 'email' : 'text' ?>" id="loginField" name="login" class="form-input" value="<?= isset($_POST['login']) ? htmlspecialchars((string)$_POST['login']) : '' ?>" placeholder="<?= $posted_role === 'user' ? 'name@unikl.edu.my' : 'e.g. IT-12345' ?>" required autocomplete="<?= $posted_role === 'user' ? 'email' : 'username' ?>">
                    <i class="<?= $posted_role === 'user' ? 'ri-mail-line' : 'ri-id-card-line' ?> input-icon" id="loginInputIcon"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <div class="input-wrap">
                    <input type="password" id="password" name="password" class="form-input" placeholder="Enter your password" required>
                    <i class="ri-lock-line input-icon"></i>
                    <button type="button" class="pw-toggle" data-target="password"><i class="ri-eye-off-line"></i></button>
                </div>
            </div>

            <div class="form-options">
                <label class="remember">
                    <input type="checkbox" name="remember">
                    <div class="checkmark"></div>
                    Remember me
                </label>
            </div>

            <button type="submit" class="btn-submit"><i class="ri-login-circle-line"></i> Access System</button>
        </form>
        <div class="form-footer">NextCheck user without an account? <a href="register.php">Register</a></div>
    </div>
</div>

<div class="status-strip">
    <div class="status-item"><span class="s-dot" style="background:#10b981;box-shadow:0 0 6px #10b981;"></span>Systems Online</div>
    <div class="status-item"><span class="s-dot" style="background:#2563eb;box-shadow:0 0 6px #2563eb;"></span>DB Connected</div>
    <div class="status-item"><span class="s-dot" style="background:#f59e0b;box-shadow:0 0 6px #f59e0b;"></span>Secure Channel</div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
    const rStaff=document.getElementById('r_staff');
    const rUser=document.getElementById('r_user');
    const loginLabel=document.getElementById('loginLabel');
    const loginField=document.getElementById('loginField');
    const loginIcon=document.getElementById('loginInputIcon');
    function syncLoginRoleUI(){
        const user=rUser.checked;
        loginLabel.textContent=user?'Email':'Staff ID';
        loginField.type=user?'email':'text';
        loginField.placeholder=user?'name@unikl.edu.my':'e.g. IT-12345';
        loginField.autocomplete=user?'email':'username';
        loginIcon.className=(user?'ri-mail-line':'ri-id-card-line')+' input-icon';
    }
    rStaff.addEventListener('change',syncLoginRoleUI);
    rUser.addEventListener('change',syncLoginRoleUI);

    document.querySelectorAll('.pw-toggle').forEach(btn=>{
        btn.addEventListener('click',()=>{
            const input=document.getElementById(btn.dataset.target);
            const icon=btn.querySelector('i');
            if(input.type==='password'){input.type='text';icon.className='ri-eye-line';}
            else{input.type='password';icon.className='ri-eye-off-line';}
        });
    });
    document.querySelectorAll('.form-input').forEach(input=>{
        input.addEventListener('focus',()=>{ const ic=input.parentElement.querySelector('.input-icon'); if(ic)ic.style.color='var(--primary)'; });
        input.addEventListener('blur', ()=>{ const ic=input.parentElement.querySelector('.input-icon'); if(ic)ic.style.color=''; });
    });
    const form=document.getElementById('loginForm'),btn=document.querySelector('.btn-submit');
    form.addEventListener('submit',()=>{ btn.innerHTML='<i class="ri-loader-4-line" style="animation:spin 0.8s linear infinite"></i> Authenticating…'; btn.style.opacity='0.8'; btn.disabled=true; });
});
</script>
</body>
</html>