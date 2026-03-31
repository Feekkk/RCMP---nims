<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$isDashboard = $currentPage === 'dashboard.php';
$isForm      = $currentPage === 'form.php';
$isHistory   = $currentPage === 'history.php';
$isProfile   = $currentPage === 'profile.php';
?>

<style>
    :root {
        --sidebar-bg: #021A54;
        --sidebar-border: rgba(255,255,255,0.10);
        --sidebar-text: rgba(255,255,255,0.86);
        --sidebar-muted: rgba(255,255,255,0.64);
        --sidebar-hover: rgba(255,255,255,0.08);
        --sidebar-active: rgba(255,255,255,0.12);
        --sidebar-accent: #38bdf8;
    }

    .sidebar-next {
        width: 280px;
        height: 100vh;
        background: var(--sidebar-bg);
        border-right: 1px solid var(--sidebar-border);
        position: fixed;
        top: 0;
        left: 0;
        display: flex;
        flex-direction: column;
        padding: 1.5rem;
        z-index: 100;
        box-shadow: 4px 0 20px rgba(2, 26, 84, 0.25);
        transition: transform 0.3s ease;
    }

    .sidebar-next .sidebar-logo {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid var(--sidebar-border);
        margin-bottom: 1.5rem;
        gap: 0.35rem;
    }

    .sidebar-next .sidebar-logo img {
        height: 44px;
        width: auto;
        max-width: 100%;
        object-fit: contain;
        filter: drop-shadow(0 6px 10px rgba(0,0,0,0.25));
    }

    .sidebar-next .sidebar-tag {
        font-family: 'Outfit', sans-serif;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--sidebar-accent);
    }

    .sidebar-next .nav-menu {
        display: flex;
        flex-direction: column;
        gap: 0.7rem;
        flex: 1;
    }

    .sidebar-next .nav-item {
        padding: 0.85rem 1.15rem;
        border-radius: 14px;
        color: var(--sidebar-text);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.9rem;
        font-weight: 600;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        user-select: none;
    }

    .sidebar-next .nav-item i {
        font-size: 1.25rem;
        color: var(--sidebar-muted);
    }

    .sidebar-next .nav-item:hover {
        background: var(--sidebar-hover);
        border-color: rgba(255,255,255,0.10);
    }

    .sidebar-next .nav-item:hover i { color: var(--sidebar-text); }

    .sidebar-next .nav-item.active {
        background: var(--sidebar-active);
        border-color: rgba(255,255,255,0.16);
        box-shadow: inset 3px 0 0 var(--sidebar-accent);
    }

    .sidebar-next .nav-item.active,
    .sidebar-next .nav-item.active i {
        color: #ffffff;
    }

    .sidebar-next .user-profile {
        margin-top: auto;
        padding: 1rem;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.10);
        border-radius: 16px;
        display: flex;
        align-items: center;
        gap: 1rem;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .sidebar-next .user-profile:hover {
        background: rgba(255,255,255,0.09);
        border-color: rgba(255,255,255,0.16);
    }

    .sidebar-next .avatar {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(56,189,248,0.95), rgba(14,165,233,0.85));
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Outfit', sans-serif;
        font-weight: 800;
        color: white;
        font-size: 1.05rem;
        box-shadow: 0 10px 18px rgba(0,0,0,0.18);
    }

    .sidebar-next .user-info { flex: 1; overflow: hidden; }
    .sidebar-next .user-name {
        font-size: 0.92rem;
        font-weight: 700;
        color: #ffffff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sidebar-next .user-role {
        font-size: 0.72rem;
        color: var(--sidebar-muted);
        margin-top: 0.18rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        font-weight: 700;
    }

    .sidebar-next .sidebar-copyright {
        padding: 0.85rem 1rem 1.1rem !important;
        text-align: center;
        font-size: 0.68rem;
        line-height: 1.45;
        color: rgba(255,255,255,0.55) !important;
        border-top: 1px solid var(--sidebar-border) !important;
        margin-top: 0.9rem;
    }

    @media (max-width: 900px) {
        .sidebar-next { transform: translateX(-100%); width: 260px; }
    }
</style>

<aside class="sidebar-next">
    <div class="sidebar-logo">
        <img src="../public/logo-nims.png" alt="RCMP NIMS">
        <span class="sidebar-tag">Checkout System</span>
    </div>

    <nav class="nav-menu">
        <a href="dashboard.php" class="nav-item <?= $isDashboard ? 'active' : '' ?>">
            <i class="ri-dashboard-2-line"></i> Dashboard
        </a>
        <a href="form.php" class="nav-item <?= $isForm ? 'active' : '' ?>">
            <i class="ri-file-edit-line"></i> Form
        </a>
        <a href="history.php" class="nav-item <?= $isHistory ? 'active' : '' ?>">
            <i class="ri-history-line"></i> History
        </a>
        <a href="profile.php" class="nav-item <?= $isProfile ? 'active' : '' ?>">
            <i class="ri-user-settings-line"></i> Profile
        </a>
    </nav>

    <div class="user-profile" onclick="window.location.href='../auth/logout.php'" title="Logout">
        <div class="avatar">
            <?php echo isset($_SESSION['user_name']) ? strtoupper($_SESSION['user_name'][0]) : 'U'; ?>
        </div>
        <div class="user-info">
            <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></div>
            <div class="user-role">NextCheck user</div>
        </div>
        <i class="ri-logout-box-r-line" style="color: rgba(255,255,255,0.70); font-size: 1.2rem;"></i>
    </div>

    <div class="sidebar-copyright">
        &copy; <?= (int)date('Y') ?> Universiti Kuala Lumpur RCMP. All rights reserved.
    </div>
</aside>
