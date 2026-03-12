<?php
session_start();
// Basic authentication check: ensures the user is logged in and is a technician (role_id 1)
if (!isset($_SESSION['staff_id']) || (int)$_SESSION['role_id'] !== 1) {
    header('Location: ../auth/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Dashboard - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --secondary: #0ea5e9;
            --accent: #f59e0b;
            --danger: #ef4444;
            --success: #10b981;
            --bg: #f1f5f9;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass-panel: #f8fafc;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            overflow-x: hidden;
            display: flex;
            min-height: 100vh;
        }

        .page-bg { display: none; }
        .bg-overlay { display: none; }
        .blob { display: none; }

        /* Decorative glowing orbs */
        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(100px);
            z-index: -1;
            opacity: 0.3;
            pointer-events: none;
        }

        .blob-1 {
            width: 400px; height: 400px; background: var(--primary);
            top: -100px; left: -100px;
        }

        .blob-2 {
            width: 350px; height: 350px; background: var(--secondary);
            bottom: -50px; right: 20%;
        }

        /* Sidebar Navigation */
        .sidebar {
            width: 280px;
            height: 100vh;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--card-border);
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            padding: 1.5rem;
            z-index: 100;
            box-shadow: 4px 0 20px rgba(15,23,42,0.06);
            transition: transform 0.3s ease;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--glass-border);
            margin-bottom: 2rem;
        }

        .sidebar-logo img {
            height: 45px;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.5));
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            flex: 1;
        }

        .nav-item {
            padding: 0.85rem 1.25rem;
            border-radius: 12px;
            color: var(--text-muted);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .nav-item:hover {
            color: var(--primary);
            background: rgba(37, 99, 235, 0.06);
        }

        .nav-item.active {
            color: var(--primary);
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: inset 3px 0 0 var(--primary);
        }

        .nav-item i {
            font-size: 1.25rem;
            color: inherit;
        }

        .nav-item.active i {
            color: var(--primary);
        }

        .user-profile {
            margin-top: auto;
            padding: 1rem;
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .user-profile:hover {
            background: rgba(37,99,235,0.06);
            border-color: rgba(37,99,235,0.2);
        }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            color: white;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
        }

        .user-info {
            flex: 1;
            overflow: hidden;
        }

        .user-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--primary);
            margin-top: 0.2rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* Main Content Layout */
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2.5rem 3.5rem;
            /* Container sizing for visual appeal */
            max-width: calc(100vw - 280px); 
        }

        /* Top Bar */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3rem;
            animation: fadeInDown 0.6s ease-out;
        }

        .greeting h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            letter-spacing: -0.5px;
            color: var(--text-main);
        }

        .greeting p {
            color: var(--text-muted);
            font-size: 1rem;
        }

        .actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 8px 15px -5px rgba(37, 99, 235, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 20px -5px rgba(37, 99, 235, 0.5);
            filter: brightness(1.1);
        }

        .header-icon-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .header-icon-btn:hover {
            background: rgba(37, 99, 235, 0.06);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .notification-dot {
            position: absolute;
            top: 10px;
            right: 12px;
            width: 8px;
            height: 8px;
            background: var(--danger);
            border-radius: 50%;
            box-shadow: 0 0 10px var(--danger);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2.5rem;
            animation: fadeInUp 0.8s ease-out;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 1.5rem;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            align-items: center;
            gap: 1.25rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 4px; height: 100%;
            background: linear-gradient(180deg, var(--primary), var(--secondary));
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: rgba(37,99,235,0.2);
            box-shadow: 0 8px 24px rgba(37,99,235,0.1);
        }

        .stat-card:hover::before { opacity: 1; }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            flex-shrink: 0;
            position: relative;
            z-index: 2;
        }

        .icon-blue { color: #3b82f6; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); }
        .icon-orange { color: #f59e0b; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); }
        .icon-green { color: #10b981; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); }
        .icon-purple { color: #8b5cf6; background: rgba(139, 92, 246, 0.15); border: 1px solid rgba(139, 92, 246, 0.3); }

        .stat-info {
            position: relative;
            z-index: 2;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            line-height: 1.1;
            color: var(--text-main);
            margin-bottom: 0.2rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Dashboard Content Layout */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            animation: fadeInUp 1s ease-out;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            display: flex;
            flex-direction: column;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--card-border);
        }

        .card-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            color: var(--text-main);
        }

        .card-title i {
            color: var(--primary);
        }

        .view-all {
            font-size: 0.85rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .view-all:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        /* Tables & Lists */
        .table-responsive {
            overflow-x: auto;
            flex: 1;
        }

        .recent-table {
            width: 100%;
            border-collapse: collapse;
        }

        .recent-table th {
            text-align: left;
            padding: 1rem;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--card-border);
        }

        .recent-table td {
            padding: 1.15rem 1rem;
            font-size: 0.95rem;
            border-bottom: 1px dashed var(--card-border);
            color: var(--text-main);
            vertical-align: middle;
        }

        .recent-table tr:last-child td {
            border-bottom: none;
        }

        .recent-table tbody tr {
            transition: background 0.3s ease;
        }

        .recent-table tbody tr:hover {
            background: rgba(37,99,235,0.03);
        }

        .ticket-id {
            font-family: 'Outfit', monospace;
            font-weight: 600;
            color: var(--secondary);
        }

        .badge {
            padding: 0.4rem 0.85rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .badge-pending { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-progress { background: rgba(14, 165, 233, 0.15); color: #0ea5e9; border: 1px solid rgba(14, 165, 233, 0.3); }
        .badge-resolved { background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-critical { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }

        .btn-action {
            padding: 0.4rem;
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-action:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            transform: translateY(-1px);
        }

        /* Maintenance Alerts List */
        .alerts-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .alert-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .alert-item:hover {
            background: rgba(37,99,235,0.04);
            border-color: rgba(37,99,235,0.15);
            transform: translateX(4px);
        }

        .alert-item::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
        }

        .alert-item.urgent::before { background: var(--danger); }
        .alert-item.warning::before { background: var(--accent); }
        .alert-item.info::before { background: var(--secondary); }

        .alert-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .alert-item.urgent .alert-icon { color: var(--danger); background: rgba(239, 68, 68, 0.15); }
        .alert-item.warning .alert-icon { color: var(--accent); background: rgba(245, 158, 11, 0.15); }
        .alert-item.info .alert-icon { color: var(--secondary); background: rgba(14, 165, 233, 0.15); }

        .alert-content { flex: 1; }
        .alert-content h4 { font-size: 0.95rem; margin-bottom: 0.2rem; font-weight: 600; color: var(--text-main); }
        .alert-content p { font-size: 0.8rem; color: var(--text-muted); line-height: 1.4; }

        .alert-meta {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: flex-start;
        }

        .alert-time { font-size: 0.75rem; color: var(--text-muted); font-weight: 500; margin-bottom: 0.5rem; }

        /* Animations */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .dashboard-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
            .topbar { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .actions { width: 100%; justify-content: flex-end; }
        }

        /* Sidebar Dropdown */
        .nav-dropdown {
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            padding-left: 3.25rem;
            margin-top: -0.25rem;
            margin-bottom: 0.25rem;
            animation: fadeInDown 0.3s ease-out;
        }

        .nav-dropdown.show {
            display: flex;
        }

        .nav-dropdown-item {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-dropdown-item::before {
            content: '';
            position: absolute;
            left: -1rem;
            top: 50%;
            width: 6px;
            height: 6px;
            background: var(--card-border);
            border-radius: 50%;
            transform: translateY(-50%);
            transition: all 0.3s ease;
        }

        .nav-dropdown-item:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-dropdown-item:hover::before {
            background: var(--secondary);
            box-shadow: 0 0 8px var(--secondary);
        }

        .nav-item.open .chevron {
            transform: rotate(180deg);
        }
    </style>
</head>
<body>

    <!-- Backgrounds -->
    <div class="page-bg"></div>
    <div class="bg-overlay"></div>
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <img src="../public/logo-nims.png" alt="RCMP NIMS">
        </div>

        <nav class="nav-menu">
            <a href="#" class="nav-item active">
                <i class="ri-dashboard-2-line"></i> Dashboard
            </a>
            <div class="nav-group">
                <a href="#" class="nav-item" onclick="toggleDropdown(this, event)" style="justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <i class="ri-macbook-line"></i> Inventory
                    </div>
                    <i class="ri-arrow-down-s-line chevron" style="transition: transform 0.3s ease; font-size: 1.2rem;"></i>
                </a>
                <div class="nav-dropdown">
                    <a href="../technician/laptop.php" class="nav-dropdown-item">Laptop</a>
                    <a href="#" class="nav-dropdown-item">AV</a>
                    <a href="#" class="nav-dropdown-item">Network</a>
                </div>
            </div>
            <a href="#" class="nav-item">
                <i class="ri-delete-bin-line"></i> Disposal
            </a>
            <a href="#" class="nav-item">
                <i class="ri-history-line"></i> History 
            </a>
            <a href="#" class="nav-item">
                <i class="ri-book-read-line"></i> User Manual 
            </a>
            <a href="#" class="nav-item">
                <i class="ri-user-settings-line"></i> Profile 
            </a>
        </nav>

        <div class="user-profile" onclick="window.location.href='../auth/logout.php'" title="Logout">
            <div class="avatar">
                <?php 
                    // Fallback initial
                    echo isset($_SESSION['user_name']) ? strtoupper($_SESSION['user_name'][0]) : 'T'; 
                ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Technician User'); ?></div>
                <div class="user-role">IT Technician</div>
            </div>
            <i class="ri-logout-box-r-line" style="color: var(--text-muted); font-size: 1.2rem;"></i>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        
        <!-- Topbar -->
        <header class="topbar">
            <div class="greeting">
                <h1>Hello, <?php echo isset($_SESSION['user_name']) ? explode(' ', htmlspecialchars($_SESSION['user_name']))[0] : 'Technician'; ?>! 👋</h1>
                <p>Here is what's happening with the UniKL RCMP IT infrastructure today.</p>
            </div>
            <div class="actions">
                <button class="header-icon-btn">
                    <i class="ri-search-2-line"></i>
                </button>
                <button class="header-icon-btn">
                    <i class="ri-notification-3-line"></i>
                    <span class="notification-dot"></span>
                </button>
                <a href="#" class="btn btn-primary">
                    <i class="ri-add-line"></i> Create Ticket
                </a>
            </div>
        </header>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue">
                    <i class="ri-ticket-line"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value">12</div>
                    <div class="stat-label">Assigned Tickets</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon icon-orange">
                    <i class="ri-time-line"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value">5</div>
                    <div class="stat-label">Pending Action</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon icon-green">
                    <i class="ri-check-double-line"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value">28</div>
                    <div class="stat-label">Resolved (This Week)</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-purple">
                    <i class="ri-server-network-line"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value">3</div>
                    <div class="stat-label">System Alerts</div>
                </div>
            </div>
        </div>

        <!-- Dashboard Analytics & Lists -->
        <div class="dashboard-grid">
            
            <!-- Recent Tickets -->
            <div class="glass-card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="ri-file-list-3-line"></i> Urgent Active Tickets
                    </h2>
                    <a href="#" class="view-all">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="recent-table">
                        <thead>
                            <tr>
                                <th>Ticket ID</th>
                                <th>Issue Context</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="ticket-id">TCK-8492</span></td>
                                <td>
                                    <div style="font-weight: 500; color: var(--text-main);">Projector Connectivity</div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">Reported by Lecturer Dr. Azman</div>
                                </td>
                                <td>LT 1, Block A</td>
                                <td><span class="badge badge-critical"><i class="ri-error-warning-line"></i> High</span></td>
                                <td>
                                    <button class="btn-action" title="View Details"><i class="ri-eye-line"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="ticket-id">TCK-8488</span></td>
                                <td>
                                    <div style="font-weight: 500; color: var(--text-main);">Network Switch Down</div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">NMS Auto-alert</div>
                                </td>
                                <td>Lab 3, Block B</td>
                                <td><span class="badge badge-progress"><i class="ri-loader-4-line"></i> In Progress</span></td>
                                <td>
                                    <button class="btn-action" title="View Details"><i class="ri-eye-line"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="ticket-id">TCK-8485</span></td>
                                <td>
                                    <div style="font-weight: 500; color: var(--text-main);">PC Blue Screen (BSOD)</div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">Lab PC-14</div>
                                </td>
                                <td>Library</td>
                                <td><span class="badge badge-pending"><i class="ri-time-line"></i> Pending</span></td>
                                <td>
                                    <button class="btn-action" title="View Details"><i class="ri-eye-line"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="ticket-id">TCK-8480</span></td>
                                <td>
                                    <div style="font-weight: 500; color: var(--text-main);">Software Installation Req.</div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">SPSS Version 28</div>
                                </td>
                                <td>Academic Office</td>
                                <td><span class="badge badge-pending"><i class="ri-time-line"></i> Pending</span></td>
                                <td>
                                    <button class="btn-action" title="View Details"><i class="ri-eye-line"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Maintenance & Alerts -->
            <div class="glass-card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="ri-shield-check-line"></i> System Health Alerts
                    </h2>
                </div>
                <div class="alerts-list">
                    
                    <div class="alert-item urgent">
                        <div class="alert-icon"><i class="ri-wifi-off-line"></i></div>
                        <div class="alert-content">
                            <h4>AP Offline</h4>
                            <p>Access Point AP-BlockC-02 is unreachable.</p>
                        </div>
                        <div class="alert-meta">
                            <span class="alert-time">10 mins ago</span>
                        </div>
                    </div>

                    <div class="alert-item warning">
                        <div class="alert-icon"><i class="ri-hard-drive-2-line"></i></div>
                        <div class="alert-content">
                            <h4>Storage Capacity High</h4>
                            <p>Core server DB volume at 88% capacity.</p>
                        </div>
                        <div class="alert-meta">
                            <span class="alert-time">2 hrs ago</span>
                        </div>
                    </div>

                    <div class="alert-item info">
                        <div class="alert-icon"><i class="ri-calendar-todo-fill"></i></div>
                        <div class="alert-content">
                            <h4>Scheduled Maintenance</h4>
                            <p>Monthly patching for Library endpoints.</p>
                        </div>
                        <div class="alert-meta">
                            <span class="alert-time">Tomorrow</span>
                        </div>
                    </div>

                    <div class="alert-item info">
                        <div class="alert-icon"><i class="ri-refresh-line"></i></div>
                        <div class="alert-content">
                            <h4>License Renewal Due</h4>
                            <p>Adobe Creative Cloud for Arts Faculty.</p>
                        </div>
                        <div class="alert-meta">
                            <span class="alert-time">In 3 days</span>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </main>

    <script>
        function toggleDropdown(element, event) {
            event.preventDefault();
            const group = element.closest('.nav-group');
            const dropdown = group.querySelector('.nav-dropdown');
            
            element.classList.toggle('open');
            dropdown.classList.toggle('show');
        }

        // Optional JS for interactivity
        document.querySelectorAll('.btn-action').forEach(btn => {
            btn.addEventListener('click', () => {
                // Add tiny ripple or transition
                let icon = btn.querySelector('i');
                const origIcon = icon.className;
                icon.className = 'ri-loader-4-line ri-spin';
                setTimeout(() => { icon.className = origIcon; }, 500);
            });
        });
    </script>
</body>
</html>
