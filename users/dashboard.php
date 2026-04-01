<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] !== 3)) {
    header('Location: ../auth/login.php');
    exit;
}

$userName = trim((string)($_SESSION['user_name'] ?? 'User'));
$firstName = $userName !== '' ? preg_split('/\s+/', $userName, 2)[0] : 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — NextCheck — RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #0ea5e9;
            --success: #10b981;
            --bg: #f1f5f9;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass: #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }

        .nav-drawer-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 99;
        }
        body.nav-drawer-open .nav-drawer-backdrop { display: block; }
        @media (max-width: 900px) {
            body.nav-drawer-open .sidebar-next {
                transform: translateX(0);
            }
        }

        .main-user {
            margin-left: 280px;
            flex: 1;
            max-width: calc(100vw - 280px);
            min-height: 100vh;
            padding: 2rem 2.5rem 2.5rem;
        }

        .top-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.75rem;
        }
        .menu-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 14px;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-main);
            cursor: pointer;
            flex-shrink: 0;
        }
        .menu-toggle i { font-size: 1.35rem; }
        .menu-toggle:hover {
            border-color: rgba(37, 99, 235, 0.25);
            color: var(--primary);
        }

        .page-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.2;
        }
        .page-title span {
            display: block;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-top: 0.35rem;
            letter-spacing: 0;
        }

        .hero {
            background: linear-gradient(135deg, #021A54 0%, #0c2d7a 48%, #1e40af 100%);
            border-radius: 20px;
            padding: 2rem 2.25rem;
            color: #fff;
            margin-bottom: 1.75rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 16px 40px rgba(2, 26, 84, 0.28);
        }
        .hero::after {
            content: '';
            position: absolute;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(56, 189, 248, 0.12);
            top: -80px;
            right: -60px;
            pointer-events: none;
        }
        .hero-inner { position: relative; z-index: 1; max-width: 36rem; }
        .hero h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.65rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }
        .hero p {
            font-size: 0.95rem;
            line-height: 1.55;
            color: rgba(255, 255, 255, 0.82);
            margin-bottom: 1.25rem;
        }
        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.65rem 1.15rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.88rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .btn:active { transform: scale(0.98); }
        .btn-primary {
            background: #fff;
            color: #021A54;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
        }
        .btn-primary:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.16);
        }
        .btn-ghost-light {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.22);
        }
        .btn-ghost-light:hover {
            background: rgba(255, 255, 255, 0.18);
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 1100px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            overflow: hidden;
        }
        .card-hd {
            padding: 1.1rem 1.35rem;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .card-hd h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card-hd h3 i { color: var(--primary); font-size: 1.2rem; }
        .card-bd { padding: 1.25rem 1.35rem; }

        .quick-links {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.85rem;
        }
        .ql {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            padding: 1rem 1.1rem;
            border-radius: 14px;
            border: 1px solid var(--card-border);
            background: var(--glass);
            text-decoration: none;
            color: inherit;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }
        .ql:hover {
            border-color: rgba(37, 99, 235, 0.22);
            background: #fff;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
        }
        .ql-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .ql-icon.blue { background: rgba(37, 99, 235, 0.12); color: var(--primary); }
        .ql-icon.sky { background: rgba(14, 165, 233, 0.12); color: var(--secondary); }
        .ql-icon.green { background: rgba(16, 185, 129, 0.12); color: var(--success); }
        .ql-text strong {
            display: block;
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        .ql-text span {
            font-size: 0.8rem;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .steps {
            list-style: none;
            counter-reset: s;
        }
        .steps li {
            position: relative;
            padding-left: 2.5rem;
            padding-bottom: 1.15rem;
            border-left: 2px solid var(--card-border);
            margin-left: 0.65rem;
        }
        .steps li:last-child {
            border-left-color: transparent;
            padding-bottom: 0;
        }
        .steps li::before {
            counter-increment: s;
            content: counter(s);
            position: absolute;
            left: -0.65rem;
            top: 0;
            width: 1.35rem;
            height: 1.35rem;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: translateX(-50%);
        }
        .steps li strong {
            display: block;
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }
        .steps li p {
            font-size: 0.84rem;
            color: var(--text-muted);
            line-height: 1.45;
        }

        .notice {
            display: flex;
            gap: 0.85rem;
            align-items: flex-start;
            padding: 1rem 1.15rem;
            border-radius: 14px;
            background: rgba(14, 165, 233, 0.08);
            border: 1px solid rgba(14, 165, 233, 0.2);
            font-size: 0.88rem;
            line-height: 1.5;
            color: var(--text-main);
        }
        .notice i {
            color: var(--secondary);
            font-size: 1.25rem;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }

        .contact-it {
            display: flex;
            gap: 0.85rem;
            align-items: flex-start;
            padding: 1rem 1.15rem;
            border-radius: 14px;
            background: rgba(245, 158, 11, 0.08);
            border: 1px solid rgba(245, 158, 11, 0.22);
            font-size: 0.88rem;
            line-height: 1.5;
            color: var(--text-main);
            margin-bottom: 1rem;
        }
        .contact-it i {
            color: #d97706;
            font-size: 1.25rem;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }
        .contact-it strong { color: #b45309; }

        @media (max-width: 900px) {
            .menu-toggle { display: inline-flex; }
            .main-user {
                margin-left: 0;
                max-width: 100vw;
                padding: 1.25rem 1.25rem 2rem;
            }
            .top-row { align-items: flex-start; }
            .page-title { font-size: 1.45rem; }
        }
    </style>
</head>
<body>
    <div class="nav-drawer-backdrop" id="navDrawerBackdrop" aria-hidden="true"></div>
    <?php include __DIR__ . '/../components/sidebarNext.php'; ?>

    <main class="main-user">
        <div class="top-row">
            <div style="display:flex;align-items:center;gap:0.85rem;min-width:0">
                <button type="button" class="menu-toggle" id="navMenuToggle" aria-label="Open menu">
                    <i class="ri-menu-3-line"></i>
                </button>
                <div>
                    <h1 class="page-title">Dashboard<span>Welcome back, <?= htmlspecialchars($firstName) ?></span></h1>
                </div>
            </div>
        </div>

        <section class="hero">
            <div class="hero-inner">
                <h2>NextCheck checkout</h2>
                <p>Submit equipment checkout details, track your history, and manage your profile from this portal.</p>
                <div class="hero-actions">
                    <a class="btn btn-primary" href="form.php"><i class="ri-file-edit-line"></i> Open form</a>
                    <a class="btn btn-ghost-light" href="history.php"><i class="ri-history-line"></i> View history</a>
                </div>
            </div>
        </section>

        <div class="grid-2">
            <section class="card">
                <div class="card-hd">
                    <h3><i class="ri-apps-2-line"></i> Quick links</h3>
                </div>
                <div class="card-bd">
                    <div class="quick-links">
                        <a class="ql" href="form.php">
                            <span class="ql-icon blue"><i class="ri-file-edit-line"></i></span>
                            <span class="ql-text">
                                <strong>Form</strong>
                                <span>Create or update a checkout request</span>
                            </span>
                        </a>
                        <a class="ql" href="history.php">
                            <span class="ql-icon sky"><i class="ri-history-line"></i></span>
                            <span class="ql-text">
                                <strong>History</strong>
                                <span>Past submissions and status</span>
                            </span>
                        </a>
                        <a class="ql" href="profile.php">
                            <span class="ql-icon green"><i class="ri-user-settings-line"></i></span>
                            <span class="ql-text">
                                <strong>Profile</strong>
                                <span>Your account details</span>
                            </span>
                        </a>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-hd">
                    <h3><i class="ri-guide-line"></i> How it works</h3>
                </div>
                <div class="card-bd">
                    <ol class="steps">
                        <li>
                            <strong>Fill the form</strong>
                            <p>Enter asset and checkout information as instructed by IT.</p>
                        </li>
                        <li>
                            <strong>Submit</strong>
                            <p>Your request is recorded for technician review.</p>
                        </li>
                        <li>
                            <strong>Follow up</strong>
                            <p>Check your history for updates</p>
                        </li>
                    </ol>
                </div>
            </section>
        </div>

        <div class="contact-it">
            <i class="ri-customer-service-2-line"></i>
            <div>
                <strong>Need help?</strong>
                For questions, account issues, or checkout support, please contact <strong>staff from the IT department</strong> (UniKL RCMP). They can assist with form problems, asset status, and next steps after you submit.
            </div>
        </div>

        <div class="notice">
            <i class="ri-information-line"></i>
            <div>
                Signed in as <strong><?= htmlspecialchars($userName) ?></strong>. Use the sidebar to move between pages, or tap your profile block to sign out.
            </div>
        </div>
    </main>

    <script>
        (function () {
            const body = document.body;
            const toggle = document.getElementById('navMenuToggle');
            const backdrop = document.getElementById('navDrawerBackdrop');
            function close() {
                body.classList.remove('nav-drawer-open');
            }
            toggle?.addEventListener('click', function () {
                body.classList.toggle('nav-drawer-open');
            });
            backdrop?.addEventListener('click', close);
            window.addEventListener('resize', function () {
                if (window.innerWidth > 900) close();
            });
        })();
    </script>
</body>
</html>
