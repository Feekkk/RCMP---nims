<?php
// Shared technician sidebar for user pages
$currentPage = basename($_SERVER['PHP_SELF']);
$isDashboard = $currentPage === 'dashboard.php';
$isLaptop    = $currentPage === 'laptop.php';
$isAV        = $currentPage === 'av.php';
$isNetwork   = $currentPage === 'network.php';
$isProfile   = $currentPage === 'profile.php';
?>

<aside class="sidebar">
    <div class="sidebar-logo">
        <img src="../public/logo-nims.png" alt="RCMP NIMS">
    </div>

    <nav class="nav-menu">
        <a href="dashboard.php" class="nav-item <?= $isDashboard ? 'active' : '' ?>">
            <i class="ri-dashboard-2-line"></i> Dashboard
        </a>
        <div class="nav-group">
            <a href="#" class="nav-item <?= $isLaptop ? 'open' : '' ?>" onclick="toggleDropdown(this, event)" style="justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <i class="ri-macbook-line"></i> Inventory
                </div>
                <i class="ri-arrow-down-s-line chevron" style="transition: transform 0.3s ease; font-size: 1.2rem;"></i>
            </a>
            <div class="nav-dropdown <?= $isLaptop ? 'show' : '' ?>">
                <a href="../technician/laptop.php" class="nav-dropdown-item <?= $isLaptop ? 'active' : '' ?>">Laptop</a>
                <a href="../technician/av.php" class="nav-dropdown-item <?= $isAV ? 'active' : '' ?>">AV</a>
                <a href="../technician/network.php" class="nav-dropdown-item <?= $isNetwork ? 'active' : '' ?>">Network</a>
            </div>
        </div>
        <a href="#" class="nav-item">
            <i class="ri-delete-bin-line"></i> Disposal
        </a>
        <a href="history.php" class="nav-item">
            <i class="ri-history-line"></i> History 
        </a>
        <a href="#" class="nav-item">
            <i class="ri-book-read-line"></i> User Manual 
        </a>
        <a href="profile.php" class="nav-item <?= $isProfile ? 'active' : '' ?>">
            <i class="ri-user-settings-line"></i> Profile
        </a>
    </nav>

    <div class="user-profile" onclick="window.location.href='../auth/logout.php'" title="Logout">
        <div class="avatar">
            <?php 
                echo isset($_SESSION['user_name']) ? strtoupper($_SESSION['user_name'][0]) : 'T'; 
            ?>
        </div>
        <div class="user-info">
            <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Technician User'); ?></div>
            <div class="user-role">IT Technician</div>
        </div>
        <i class="ri-logout-box-r-line" style="color: var(--text-muted); font-size: 1.2rem;"></i>
    </div>

    <div class="sidebar-copyright" style="padding: 0.85rem 1rem 1.1rem; text-align: center; font-size: 0.68rem; line-height: 1.45; color: var(--text-muted, #64748b); border-top: 1px solid var(--card-border, #e2e8f0);">
        &copy; <?= (int)date('Y') ?> Universiti Kuala Lumpur RCMP. All rights reserved.
    </div>
</aside>

