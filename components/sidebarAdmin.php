<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$isDashboard = $currentPage === 'dashboard.php';
$isUsers = $currentPage === 'users.php';
$isInventory = $currentPage === 'inventory.php';
$isReport = $currentPage === 'report.php';
?>

<style>
    :root {
        --sidebar-bg: var(--sidebar-bg, #ffffff);
        --sidebar-border: var(--card-border, #e2e8f0);
        --sidebar-text: var(--text-main, #0f172a);
        --sidebar-muted: var(--text-muted, #64748b);
        --sidebar-hover: rgba(37, 99, 235, 0.06);
        --sidebar-active: rgba(37, 99, 235, 0.10);
        --sidebar-accent: var(--primary, #2563eb);
    }

    .sidebar {
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
        box-shadow: 2px 0 20px rgba(15, 23, 42, 0.06);
    }

    .sidebar-logo {
        display: flex;
        align-items: center;
        justify-content: center;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid var(--sidebar-border);
        margin-bottom: 1.25rem;
    }

    .sidebar-logo img {
        height: 42px;
        width: auto;
        max-width: 100%;
        object-fit: contain;
        filter: drop-shadow(0 4px 10px rgba(15, 23, 42, 0.10));
    }

    .nav-menu {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        flex: 1;
        overflow-y: auto;
        padding: 0.25rem 0;
    }

    .nav-item {
        padding: 0.85rem 1rem;
        border-radius: 14px;
        color: var(--sidebar-muted);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.85rem;
        font-weight: 800;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        user-select: none;
    }

    .nav-item i {
        font-size: 1.25rem;
        color: var(--sidebar-muted);
        transition: color 0.2s ease;
    }

    .nav-item:hover {
        background: var(--sidebar-hover);
        color: var(--sidebar-accent);
        border-color: rgba(37, 99, 235, 0.18);
    }

    .nav-item:hover i { color: var(--sidebar-accent); }

    .nav-item.active {
        background: var(--sidebar-active);
        color: var(--sidebar-accent);
        border-color: rgba(37, 99, 235, 0.22);
        box-shadow: inset 3px 0 0 var(--sidebar-accent);
    }
    .nav-item.active i { color: var(--sidebar-accent); }

    .user-profile {
        margin-top: auto;
        padding: 1rem;
        background: rgba(37, 99, 235, 0.04);
        border: 1px solid rgba(37, 99, 235, 0.12);
        border-radius: 16px;
        display: flex;
        align-items: center;
        gap: 1rem;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .user-profile:hover {
        background: rgba(37, 99, 235, 0.06);
        border-color: rgba(37, 99, 235, 0.20);
    }

    .avatar {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        background: linear-gradient(135deg, var(--sidebar-accent), #7c3aed);
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Outfit', sans-serif;
        font-weight: 900;
        color: white;
        font-size: 1.05rem;
        box-shadow: 0 10px 18px rgba(15, 23, 42, 0.14);
        flex: 0 0 auto;
    }

    .user-info { flex: 1; overflow: hidden; }
    .user-name {
        font-size: 0.92rem;
        font-weight: 900;
        color: var(--sidebar-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .user-role {
        font-size: 0.72rem;
        color: var(--sidebar-muted);
        margin-top: 0.18rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        font-weight: 900;
    }

    .sidebar-copyright {
        padding: 0.85rem 1rem 1.1rem !important;
        text-align: center;
        font-size: 0.68rem;
        line-height: 1.45;
        color: var(--sidebar-muted) !important;
        border-top: 1px solid var(--sidebar-border) !important;
        margin-top: 0.9rem;
    }

    @media (max-width: 900px) {
        .sidebar { transform: translateX(-100%); width: 260px; }
    }
</style>

<aside class="sidebar">
    <div class="sidebar-logo">
        <img src="../public/logo-nims.png" alt="RCMP NIMS">
    </div>

    <nav class="nav-menu">
        <a href="dashboard.php" class="nav-item <?= $isDashboard ? 'active' : '' ?>">
            <i class="ri-dashboard-2-line"></i> Dashboard
        </a>
        <a href="inventory.php" class="nav-item <?= $isInventory ? 'active' : '' ?>">
            <i class="ri-archive-line"></i> Inventory
        </a>
        <a href="users.php" class="nav-item <?= $isUsers ? 'active' : '' ?>">
            <i class="ri-user-3-line"></i> Users
        </a>
        <a href="report.php" class="nav-item <?= $isReport ? 'active' : '' ?>">
            <i class="ri-file-chart-line"></i> Reports
        </a>
    </nav>

    <div class="user-profile" onclick="window.location.href='../auth/logout.php'" title="Logout">
        <div class="avatar">
            <?php echo isset($_SESSION['user_name']) ? strtoupper($_SESSION['user_name'][0]) : 'A'; ?>
        </div>
        <div class="user-info">
            <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin User'); ?></div>
            <div class="user-role">Administrator</div>
        </div>
        <i class="ri-logout-box-r-line" style="color: var(--text-muted); font-size: 1.2rem;"></i>
    </div>

    <div class="sidebar-copyright" style="padding: 0.85rem 1rem 1.1rem; text-align: center; font-size: 0.68rem; line-height: 1.45; color: var(--text-muted, #64748b); border-top: 1px solid var(--card-border, #e2e8f0);">
        &copy; <?= (int)date('Y') ?> Universiti Kuala Lumpur RCMP. All rights reserved.
    </div>
</aside>

