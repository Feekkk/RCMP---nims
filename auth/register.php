<?php
session_start();
require_once __DIR__ . '/../config/database.php';

/** role.id for self-service NextCheck (checkout) accounts — see db/schema.sql */
const REGISTER_ROLE_ID_NEXTCHECK = 3;

function register_error_message(PDOException $e): string
{
    $info = $e->errorInfo;
    $driverErr = isset($info[1]) ? (int)$info[1] : 0;
    $detail = (string)($info[2] ?? '');

    if ($driverErr === 1452) {
        return 'The NextCheck role is missing in the database (role id 3). Run the latest db/schema.sql INSERT for roles, or ask IT.';
    }
    if ($driverErr === 1062) {
        if (stripos($detail, 'email') !== false || preg_match("/for key\\s+['`]?email['`]?/i", $detail)) {
            return 'This email is already registered. Sign in with User, or use a different email.';
        }
        return 'This Student / Staff ID is already in use. Choose a different ID.';
    }
    return 'Registration failed. Please try again.';
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['full_name'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $phone    = trim($_POST['phone'] ?? '');
    $staffId  = trim($_POST['staff_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $phone === '' || $staffId === '') {
        $msg = 'Full name, email, phone number, and Student / Staff ID are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.';
    } elseif (strlen(preg_replace('/\D/', '', $phone)) < 8) {
        $msg = 'Please enter a valid phone number (at least 8 digits).';
    } elseif (strlen($password) < 6) {
        $msg = 'Password must be at least 6 characters.';
    } elseif (strlen($staffId) > 32) {
        $msg = 'Student / Staff ID is too long (max 32 characters).';
    } else {
        $pdo = db();
        $roleOk = $pdo->query('SELECT 1 FROM role WHERE id = ' . (int)REGISTER_ROLE_ID_NEXTCHECK . ' LIMIT 1')->fetchColumn();
        if (!$roleOk) {
            $msg = 'NextCheck registration is not available: role id ' . REGISTER_ROLE_ID_NEXTCHECK . ' is missing. Contact IT.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (staff_id, full_name, email, phone, password_hash, role_id) VALUES (?, ?, ?, ?, ?, ?)');
            try {
                $stmt->execute([$staffId, $name, $email, $phone, $hash, REGISTER_ROLE_ID_NEXTCHECK]);
                header('Location: login.php?registered=1');
                exit;
            } catch (PDOException $e) {
                $msg = register_error_message($e);
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
    <title>NextCheck User Registration — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary:   #2563eb;
            --secondary: #0ea5e9;
            --purple:    #8b5cf6;
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
        body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text-main);min-height:100vh;display:flex;overflow-x:hidden;}

        .bg-layer{position:fixed;inset:0;z-index:0;background:radial-gradient(ellipse 65% 55% at 80% 15%,rgba(139,92,246,0.06) 0%,transparent 60%),radial-gradient(ellipse 60% 50% at 10% 80%,rgba(37,99,235,0.07) 0%,transparent 60%),linear-gradient(155deg,#eef4ff 0%,#f0f5ff 60%,#f3f0ff 100%);}
        .grid-layer{position:fixed;inset:0;z-index:1;background-image:linear-gradient(rgba(37,99,235,0.045) 1px,transparent 1px),linear-gradient(90deg,rgba(37,99,235,0.045) 1px,transparent 1px);background-size:56px 56px;animation:gridMove 24s linear infinite;}
        @keyframes gridMove{to{background-position:56px 56px;}}

        /* Split */
        .split-left{width:42%;display:flex;flex-direction:column;justify-content:center;padding:4rem 5%;position:relative;z-index:10;border-right:1px solid rgba(37,99,235,0.08);}
        .split-right{flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:3rem 5%;position:relative;z-index:10;overflow-y:auto;}

        /* Left */
        .brand-area{opacity:0;transform:translateY(24px);animation:riseIn 0.8s cubic-bezier(0.16,1,0.3,1) 0.05s forwards;}
        @keyframes riseIn{to{opacity:1;transform:translateY(0);}}
        .brand-logo{height:52px;object-fit:contain;filter:drop-shadow(0 2px 8px rgba(37,99,235,0.15));margin-bottom:2.5rem;transition:transform 0.4s cubic-bezier(0.34,1.56,0.64,1);}
        .brand-logo:hover{transform:scale(1.06);}
        .brand-eyebrow{font-family:var(--mono);font-size:0.7rem;letter-spacing:2.5px;text-transform:uppercase;color:var(--purple);margin-bottom:1rem;display:flex;align-items:center;gap:8px;}
        .eyebrow-line{height:1px;width:30px;background:var(--purple);opacity:0.4;}
        .brand-headline{font-size:3rem;font-weight:900;line-height:1;letter-spacing:-2px;margin-bottom:1rem;background:linear-gradient(135deg,#0f172a 40%,#8b5cf6 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
        .brand-sub{font-size:0.95rem;color:var(--text-muted);line-height:1.7;max-width:300px;margin-bottom:2.5rem;}

        /* Steps */
        .steps{display:flex;flex-direction:column;gap:0;}
        .step{display:flex;align-items:flex-start;gap:14px;opacity:0;transform:translateX(-14px);animation:slideR 0.55s cubic-bezier(0.16,1,0.3,1) forwards;}
        .step:nth-child(1){animation-delay:0.45s;} .step:nth-child(2){animation-delay:0.6s;} .step:nth-child(3){animation-delay:0.75s;}
        @keyframes slideR{to{opacity:1;transform:translateX(0);}}
        .step-connector{display:flex;flex-direction:column;align-items:center;}
        .step-num{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-size:0.72rem;font-weight:700;flex-shrink:0;}
        .step-line{width:1px;flex:1;min-height:28px;background:linear-gradient(to bottom,rgba(37,99,235,0.15),transparent);margin:4px 0;}
        .step:last-child .step-line{display:none;}
        .step-body{padding-bottom:1.25rem;}
        .step-title{font-size:0.88rem;font-weight:700;color:var(--text-main);margin-bottom:2px;}
        .step-desc{font-size:0.78rem;color:var(--text-muted);line-height:1.5;}

        /* Form card */
        .form-card{width:100%;max-width:440px;background:rgba(255,255,255,0.88);border:1px solid rgba(37,99,235,0.1);border-radius:24px;padding:2.25rem;backdrop-filter:blur(24px);box-shadow:0 24px 60px rgba(37,99,235,0.09),0 4px 16px rgba(37,99,235,0.05),inset 0 1px 0 rgba(255,255,255,1);position:relative;overflow:hidden;opacity:0;transform:translateY(32px) scale(0.98);animation:cardIn 0.9s cubic-bezier(0.16,1,0.3,1) 0.2s forwards;}
        .form-card::before{content:'';position:absolute;top:0;left:20%;right:20%;height:1px;background:linear-gradient(90deg,transparent,var(--purple),transparent);opacity:0.35;}
        @keyframes cardIn{to{opacity:1;transform:translateY(0) scale(1);}}

        .form-header{text-align:center;margin-bottom:1.75rem;}
        .form-title{font-size:1.45rem;font-weight:800;letter-spacing:-0.5px;color:var(--text-main);margin-bottom:0.3rem;}
        .form-sub{font-size:0.8rem;color:var(--text-muted);font-family:var(--mono);}

        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:0.9rem;}
        .form-group{margin-bottom:1rem;position:relative;}
        .form-label{display:block;font-family:var(--mono);font-size:0.67rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:0.45rem;}
        .input-wrap{position:relative;display:flex;align-items:center;}
        .input-icon{position:absolute;left:0.85rem;color:var(--text-subtle);font-size:0.95rem;pointer-events:none;transition:color 0.25s;z-index:1;}
        .form-input{width:100%;background:var(--input-bg);border:1px solid var(--input-border);border-radius:10px;padding:0.75rem 1rem 0.75rem 2.5rem;color:var(--text-main);font-family:'Outfit',sans-serif;font-size:0.9rem;transition:all 0.25s;outline:none;}
        .form-input::placeholder{color:var(--text-subtle);font-size:0.85rem;}
        .form-input:focus{background:white;border-color:rgba(139,92,246,0.4);box-shadow:0 0 0 3px rgba(139,92,246,0.1);}
        .form-input:focus~.input-icon{color:var(--purple);}

        /* Password strength */
        .strength-bar{display:flex;gap:4px;margin-top:6px;}
        .strength-seg{height:3px;flex:1;border-radius:2px;background:rgba(37,99,235,0.08);transition:background 0.4s;}
        .strength-label{font-family:var(--mono);font-size:0.65rem;color:var(--text-muted);margin-top:4px;text-align:right;transition:color 0.3s;}

        .pw-toggle{position:absolute;right:0.85rem;background:none;border:none;color:var(--text-subtle);cursor:pointer;font-size:0.95rem;padding:0;transition:color 0.2s;}
        .pw-toggle:hover{color:var(--text-main);}

        .alert{display:flex;align-items:flex-start;gap:10px;padding:0.75rem 1rem;border-radius:8px;font-size:0.82rem;margin-bottom:1rem;line-height:1.45;}
        .alert>i{flex-shrink:0;font-size:1.05rem;line-height:1.35;margin-top:0.1rem;}
        .alert-body{flex:1;min-width:0;font-family:var(--mono);}
        .alert-error{background:rgba(239,68,68,0.07);border:1px solid rgba(239,68,68,0.16);color:#dc2626;}

        .divider{display:flex;align-items:center;gap:10px;margin:0.5rem 0 1rem;font-family:var(--mono);font-size:0.65rem;color:var(--text-subtle);letter-spacing:1px;text-transform:uppercase;}
        .divider::before,.divider::after{content:'';flex:1;height:1px;background:rgba(37,99,235,0.1);}

        .btn-submit{width:100%;padding:0.85rem;border-radius:10px;border:none;background:linear-gradient(135deg,var(--purple),var(--primary));color:white;font-family:'Outfit',sans-serif;font-size:0.95rem;font-weight:700;cursor:pointer;transition:all 0.3s;box-shadow:0 6px 20px rgba(139,92,246,0.25);display:flex;align-items:center;justify-content:center;gap:8px;position:relative;overflow:hidden;margin-top:0.5rem;}
        .btn-submit::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.25),transparent);transition:left 0.5s;}
        .btn-submit:hover::before{left:100%;}
        .btn-submit:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(139,92,246,0.35);}

        .form-footer{text-align:center;margin-top:1.25rem;font-size:0.82rem;color:var(--text-muted);}
        .form-footer a{color:var(--primary);text-decoration:none;font-weight:600;transition:color 0.2s;}
        .form-footer a:hover{color:#1d4ed8;}

        .back-link{position:absolute;top:2rem;left:2rem;display:flex;align-items:center;gap:6px;color:var(--text-muted);text-decoration:none;font-size:0.8rem;font-family:var(--mono);letter-spacing:0.5px;transition:color 0.2s;z-index:20;}
        .back-link:hover{color:var(--primary);}

        .status-strip{position:fixed;bottom:1.5rem;left:0;right:0;display:flex;justify-content:center;gap:1.5rem;font-family:var(--mono);font-size:0.68rem;color:var(--text-muted);z-index:20;}
        .status-item{display:flex;align-items:center;gap:5px;}
        .s-dot{width:5px;height:5px;border-radius:50%;}

        @media(max-width:900px){.split-left{display:none;}.split-right{padding:2rem;}body{overflow-y:auto;}}
        @media(max-width:540px){.form-card{padding:1.75rem;}.form-row{grid-template-columns:1fr;}}
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
        <div class="brand-eyebrow"><span class="eyebrow-line"></span> NextCheck · Checkout users</div>
        <h1 class="brand-headline">Create your<br>User Account</h1>
        <p class="brand-sub">Self-registration is for NextCheck checkout users only. IT technicians and administrators are created by IT — use users login instead.</p>
        <div class="steps">
            <div class="step">
                <div class="step-connector">
                    <div class="step-num" style="background:rgba(139,92,246,0.1);color:#8b5cf6;">01</div>
                    <div class="step-line"></div>
                </div>
                <div class="step-body"><div class="step-title">Fill in your details</div><div class="step-desc">Full name, UniKL email, phone, and your Student / Staff ID — all required.</div></div>
            </div>
            <div class="step">
                <div class="step-connector">
                    <div class="step-num" style="background:rgba(37,99,235,0.1);color:#2563eb;">02</div>
                    <div class="step-line"></div>
                </div>
                <div class="step-body"><div class="step-title">Create a secure password</div><div class="step-desc">Minimum 6 characters. A strong password keeps assets secure.</div></div>
            </div>
            <div class="step">
                <div class="step-connector">
                    <div class="step-num" style="background:rgba(16,185,129,0.1);color:#10b981;">03</div>
                    <div class="step-line"></div>
                </div>
                <div class="step-body"><div class="step-title">Sign in as User</div><div class="step-desc">On the login page choose <strong>User</strong> to open your NextCheck dashboard.</div></div>
            </div>
        </div>
    </div>
</div>

<!-- Right -->
<div class="split-right">
    <div class="form-card">
        <div class="form-header">
            <div class="form-title">Create Account</div>
            <div class="form-sub">// rcmp-nims · nextcheck user only</div>
        </div>

        <?php if($msg): ?>
        <div class="alert alert-error"><i class="ri-error-warning-line"></i><span class="alert-body"><?= htmlspecialchars($msg) ?></span></div>
        <?php endif; ?>

        <form action="" method="POST" id="regForm">
            <div class="form-group">
                <label class="form-label" for="full_name">Full Name</label>
                <div class="input-wrap">
                    <input type="text" id="full_name" name="full_name" class="form-input" placeholder="As per your NRIC / Passport" required maxlength="128" value="<?= htmlspecialchars((string)($_POST['full_name'] ?? '')) ?>">
                    <i class="ri-user-smile-line input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="email">UniKL Email</label>
                <div class="input-wrap">
                    <input type="email" id="email" name="email" class="form-input" placeholder="name@unikl.edu.my" required maxlength="128" value="<?= htmlspecialchars((string)($_POST['email'] ?? '')) ?>">
                    <i class="ri-mail-line input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="phone">Phone Number</label>
                <div class="input-wrap">
                    <input type="tel" id="phone" name="phone" class="form-input" placeholder="e.g. 012-345 6789" required maxlength="64" autocomplete="tel" inputmode="tel" value="<?= htmlspecialchars((string)($_POST['phone'] ?? '')) ?>">
                    <i class="ri-phone-line input-icon"></i>
                </div>
            </div>

            <div class="divider">Account Credentials</div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="staff_id">Student / Staff ID</label>
                    <div class="input-wrap">
                        <input type="text" id="staff_id" name="staff_id" class="form-input" maxlength="32" placeholder="Your assigned ID" required autocomplete="username" value="<?= htmlspecialchars((string)($_POST['staff_id'] ?? '')) ?>">
                        <i class="ri-id-card-line input-icon"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password" class="form-input" placeholder="Min. 6 chars" required>
                        <i class="ri-lock-line input-icon"></i>
                        <button type="button" class="pw-toggle" data-target="password"><i class="ri-eye-off-line"></i></button>
                    </div>
                </div>
            </div>

            <div class="strength-bar" id="strengthBar">
                <div class="strength-seg" id="seg1"></div><div class="strength-seg" id="seg2"></div>
                <div class="strength-seg" id="seg3"></div><div class="strength-seg" id="seg4"></div>
            </div>
            <div class="strength-label" id="strengthLabel">Enter password</div>

            <button type="submit" class="btn-submit"><i class="ri-user-add-line"></i> Create Account</button>
        </form>
        <div class="form-footer">Already registered? <a href="login.php">Sign in</a> (choose <strong>User</strong>)</div>
    </div>
</div>

<div class="status-strip">
    <div class="status-item"><span class="s-dot" style="background:#10b981;box-shadow:0 0 6px #10b981;"></span>Systems Online</div>
    <div class="status-item"><span class="s-dot" style="background:#2563eb;box-shadow:0 0 6px #2563eb;"></span>DB Connected</div>
    <div class="status-item"><span class="s-dot" style="background:#f59e0b;box-shadow:0 0 6px #f59e0b;"></span>Secure Channel</div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('.pw-toggle').forEach(btn=>{
        btn.addEventListener('click',()=>{
            const input=document.getElementById(btn.dataset.target);
            const icon=btn.querySelector('i');
            if(input.type==='password'){input.type='text';icon.className='ri-eye-line';}
            else{input.type='password';icon.className='ri-eye-off-line';}
        });
    });
    document.querySelectorAll('.form-input').forEach(input=>{
        input.addEventListener('focus',()=>{ const ic=input.parentElement.querySelector('.input-icon'); if(ic)ic.style.color='var(--purple)'; });
        input.addEventListener('blur', ()=>{ const ic=input.parentElement.querySelector('.input-icon'); if(ic)ic.style.color=''; });
    });

    // Password strength
    const pwInput=document.getElementById('password');
    const segs=[document.getElementById('seg1'),document.getElementById('seg2'),document.getElementById('seg3'),document.getElementById('seg4')];
    const label=document.getElementById('strengthLabel');
    const colors=['#ef4444','#f59e0b','#2563eb','#10b981'];
    const labels=['Weak','Fair','Good','Strong'];
    pwInput.addEventListener('input',()=>{
        const val=pwInput.value; let score=0;
        if(val.length>=6)score++; if(val.length>=10)score++;
        if(/[A-Z]/.test(val)&&/[0-9]/.test(val))score++; if(/[^A-Za-z0-9]/.test(val))score++;
        segs.forEach((s,i)=>{ s.style.background=i<score?colors[Math.min(score-1,3)]:'rgba(37,99,235,0.08)'; });
        if(!val){label.textContent='Enter password';label.style.color='var(--text-muted)';}
        else{label.textContent=labels[Math.min(score-1,3)]||'Too weak';label.style.color=colors[Math.min(score-1,3)]||'#ef4444';}
    });

    const form=document.getElementById('regForm'),btn=document.querySelector('.btn-submit');
    form.addEventListener('submit',()=>{ btn.innerHTML='<i class="ri-loader-4-line" style="animation:spin 0.8s linear infinite"></i> Creating account…'; btn.style.opacity='0.8'; btn.disabled=true; });
});
</script>
</body>
</html>