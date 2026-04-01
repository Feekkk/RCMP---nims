<?php
// Shared technician sidebar for user pages
$currentPage = basename($_SERVER['PHP_SELF']);
$isDashboard = $currentPage === 'dashboard.php';
$isLaptop    = $currentPage === 'laptop.php';
$isAV        = $currentPage === 'av.php';
$isNetwork   = $currentPage === 'network.php';
$isNextCheck = str_starts_with($currentPage, 'nextcheck')
    || $currentPage === 'nextAdd.php'
    || $currentPage === 'nextListitem.php'
    || $currentPage === 'nextCheckout.php'
    || $currentPage === 'nextItems.php';
$isProfile   = $currentPage === 'profile.php';
?>

<style>
    /* Shared technician sidebar styling */
    :root {
        --sidebar-bg: #021A54;
        --sidebar-border: rgba(255,255,255,0.10);
        --sidebar-text: rgba(255,255,255,0.86);
        --sidebar-muted: rgba(255,255,255,0.64);
        --sidebar-hover: rgba(255,255,255,0.08);
        --sidebar-active: rgba(255,255,255,0.12);
        --sidebar-accent: #60a5fa;
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
        box-shadow: 4px 0 20px rgba(2, 26, 84, 0.25);
        transition: transform 0.3s ease;
    }

    .sidebar-logo {
        display: flex;
        align-items: center;
        justify-content: center;
        padding-bottom: 1.75rem;
        border-bottom: 1px solid var(--sidebar-border);
        margin-bottom: 1.75rem;
    }

    .sidebar-logo img {
        height: 44px;
        width: auto;
        max-width: 100%;
        object-fit: contain;
        filter: drop-shadow(0 6px 10px rgba(0,0,0,0.25));
    }

    .nav-menu {
        display: flex;
        flex-direction: column;
        gap: 0.7rem;
        flex: 1;
    }

    .nav-item {
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

    .nav-item i {
        font-size: 1.25rem;
        color: var(--sidebar-muted);
    }

    .nav-item:hover {
        background: var(--sidebar-hover);
        border-color: rgba(255,255,255,0.10);
    }

    .nav-item:hover i {
        color: var(--sidebar-text);
    }

    .nav-item.active {
        background: var(--sidebar-active);
        border-color: rgba(255,255,255,0.16);
        box-shadow: inset 3px 0 0 var(--sidebar-accent);
    }

    .nav-item.active,
    .nav-item.active i {
        color: #ffffff;
    }

    .nav-group .chevron {
        color: var(--sidebar-muted);
    }
    .nav-item.open .chevron {
        transform: rotate(180deg);
        color: #ffffff;
    }

    .nav-dropdown {
        display: none;
        flex-direction: column;
        gap: 0.35rem;
        padding-left: 3.1rem;
        margin-top: 0.2rem;
        margin-bottom: 0.2rem;
    }

    .nav-dropdown.show {
        display: flex;
    }

    .nav-dropdown-item {
        padding: 0.65rem 0.9rem;
        border-radius: 12px;
        color: var(--sidebar-muted);
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 600;
        transition: all 0.2s ease;
        border: 1px solid transparent;
    }

    .nav-dropdown-item:hover {
        background: rgba(255,255,255,0.07);
        color: #ffffff;
        border-color: rgba(255,255,255,0.10);
    }

    .nav-dropdown-item.active {
        background: rgba(96,165,250,0.14);
        color: #ffffff;
        border-color: rgba(96,165,250,0.30);
    }

    .user-profile {
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

    .user-profile:hover {
        background: rgba(255,255,255,0.09);
        border-color: rgba(255,255,255,0.16);
    }

    .avatar {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(96,165,250,0.95), rgba(14,165,233,0.85));
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Outfit', sans-serif;
        font-weight: 800;
        color: white;
        font-size: 1.05rem;
        box-shadow: 0 10px 18px rgba(0,0,0,0.18);
    }

    .user-info { flex: 1; overflow: hidden; }
    .user-name {
        font-size: 0.92rem;
        font-weight: 700;
        color: #ffffff;
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
        font-weight: 700;
    }

    .sidebar-copyright {
        padding: 0.85rem 1rem 1.1rem !important;
        text-align: center;
        font-size: 0.68rem;
        line-height: 1.45;
        color: rgba(255,255,255,0.55) !important;
        border-top: 1px solid var(--sidebar-border) !important;
        margin-top: 0.9rem;
    }

    /* Responsive */
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
        <div class="nav-group">
            <a href="#" class="nav-item <?= $isNextCheck ? 'open' : '' ?>" onclick="toggleDropdown(this, event)" style="justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <i class="ri-checkbox-multiple-line"></i> NextCheck
                </div>
                <i class="ri-arrow-down-s-line chevron" style="transition: transform 0.3s ease; font-size: 1.2rem;"></i>
            </a>
            <div class="nav-dropdown <?= $isNextCheck ? 'show' : '' ?>">
                <a href="../technician/nextAdd.php" class="nav-dropdown-item <?= $currentPage === 'nextAdd.php' ? 'active' : '' ?>">Add items</a>
                <a href="../technician/nextListitem.php" class="nav-dropdown-item <?= $currentPage === 'nextListitem.php' ? 'active' : '' ?>">List items</a>
                <a href="../technician/nextCheckout.php" class="nav-dropdown-item <?= ($currentPage === 'nextCheckout.php' || $currentPage === 'nextItems.php') ? 'active' : '' ?>">User requests</a>
            </div>
        </div>
        <a href="disposal.php" class="nav-item">
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
        <i class="ri-logout-box-r-line" style="color: rgba(255,255,255,0.70); font-size: 1.2rem;"></i>
    </div>

    <div class="sidebar-copyright">
        &copy; <?= (int)date('Y') ?> Universiti Kuala Lumpur RCMP. All rights reserved.
    </div>
</aside>

