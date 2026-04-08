<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$isDashboard = $currentPage === 'dashboard.php';
$isUsers = $currentPage === 'users.php';
$isInventory = $currentPage === 'inventory.php';
?>

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
        <a href="#" class="nav-item">
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

