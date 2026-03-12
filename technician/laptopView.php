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
    <title>Laptop Inventory - RCMP NIMS</title>
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
            --dark: #0f172a;
            --darker: #020617;
            --light: #f8fafc;
            --glass-bg: rgba(15, 23, 42, 0.65);
            --glass-border: rgba(255, 255, 255, 0.08);
            --glass-panel: rgba(255, 255, 255, 0.03);
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
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
            overflow-x: hidden;
            display: flex;
            min-height: 100vh;
        }

        /* Background Layers */
        .page-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: -2;
            background-image: url('../public/bgm.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        .bg-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: -1;
            background: linear-gradient(135deg, rgba(2, 6, 23, 0.95) 0%, rgba(15, 23, 42, 0.85) 100%);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

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
            background: rgba(15, 23, 42, 0.3);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border-right: 1px solid var(--glass-border);
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            padding: 1.5rem;
            z-index: 100;
            box-shadow: 5px 0 25px rgba(0,0,0,0.2);
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
            overflow: hidden;
        }

        .nav-item:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-item.active {
            color: white;
            background: linear-gradient(90deg, rgba(37, 99, 235, 0.2), transparent);
            border: 1px solid rgba(37, 99, 235, 0.3);
            box-shadow: inset 2px 0 0 var(--secondary);
        }

        .nav-item i {
            font-size: 1.25rem;
            color: inherit;
        }

        .nav-item.active i {
            color: var(--secondary);
        }

        .user-profile {
            margin-top: auto;
            padding: 1rem;
            background: var(--glass-panel);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .user-profile:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.15);
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
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--secondary);
            margin-top: 0.2rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
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

        /* Show this specific dropdown by default on this page */
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
            background: var(--glass-border);
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
        
        .nav-dropdown-item.active {
            color: white;
        }

        .nav-dropdown-item.active::before {
            background: var(--secondary);
            box-shadow: 0 0 8px var(--secondary);
        }

        .nav-item.open .chevron {
            transform: rotate(180deg);
        }

        /* Main Content Layout */
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2.5rem 3.5rem;
            max-width: calc(100vw - 280px); 
        }

        /* Header Area */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 2rem;
            animation: fadeInDown 0.6s ease-out;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            padding-bottom: 1.5rem;
        }

        .page-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            letter-spacing: -0.5px;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title h1 i {
            color: var(--secondary);
        }

        .page-title p {
            color: var(--text-muted);
            font-size: 1rem;
        }

        /* Controls / Actions */
        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.7s ease-out;
            gap: 1rem;
        }

        .search-box {
            position: relative;
            flex: 1;
            max-width: 400px;
        }

        .search-box i {
            position: absolute;
            left: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
        }

        .search-input {
            width: 100%;
            background: var(--glass-panel);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 0.85rem 1rem 0.85rem 3rem;
            color: white;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            outline: none;
        }

        .search-input:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.85rem 1.5rem;
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

        .btn-outline {
            background: var(--glass-panel);
            border: 1px solid var(--glass-border);
            color: white;
        }

        .btn-outline:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        /* Glass Table Card */
        .glass-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 1.75rem;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            animation: fadeInUp 0.9s ease-out;
            overflow: hidden;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            width: 100%;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
        }

        .data-table th {
            text-align: left;
            padding: 1.25rem 1rem;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .data-table td {
            padding: 1.25rem 1rem;
            font-size: 0.95rem;
            border-bottom: 1px dashed rgba(255,255,255,0.05);
            color: var(--text-main);
            vertical-align: middle;
        }

        .data-table tbody tr {
            transition: background 0.3s ease;
        }

        .data-table tbody tr:hover {
            background: rgba(255,255,255,0.03);
            border-radius: 8px;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .laptop-identity {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .laptop-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(14, 165, 233, 0.15);
            color: var(--secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            border: 1px solid rgba(14, 165, 233, 0.3);
        }

        .laptop-info h4 {
            font-weight: 600;
            color: white;
            margin-bottom: 0.2rem;
            font-size: 0.95rem;
        }

        .laptop-info p {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-family: 'Outfit', monospace;
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

        .badge-active { background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-repair { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-disposed { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-reserve { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, 0.3); }

        .btn-action {
            padding: 0.5rem;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--text-main);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 0.3rem;
            font-size: 1.1rem;
        }

        .btn-action.view:hover {
            background: var(--secondary);
            border-color: var(--secondary);
            color: white;
        }
        
        .btn-action.edit:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        .page-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--glass-panel);
            border: 1px solid var(--glass-border);
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .page-btn:hover:not(:disabled) {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .page-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .page-info {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-right: auto;
        }

        /* Action Dropdowns */
        .dropdown-container {
            position: relative;
            display: inline-block;
        }

        .action-dropdown {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            background: var(--darker);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 0.5rem;
            min-width: 140px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            z-index: 50;
            animation: fadeInDown 0.2s ease-out forwards;
        }

        .action-dropdown.show {
            display: flex;
        }

        .action-dropdown-item {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .action-dropdown-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }

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
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .table-controls { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; }
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
            <a href="dashboard.php" class="nav-item">
                <i class="ri-dashboard-2-line"></i> Dashboard
            </a>
            <div class="nav-group">
                <a href="#" class="nav-item open" onclick="toggleDropdown(this, event)" style="justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <i class="ri-macbook-line"></i> Inventory
                    </div>
                    <i class="ri-arrow-down-s-line chevron" style="transition: transform 0.3s ease; font-size: 1.2rem;"></i>
                </a>
                <div class="nav-dropdown show">
                    <a href="laptopView.php" class="nav-dropdown-item active">Laptop</a>
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
        
        <!-- Header -->
        <header class="page-header">
            <div class="page-title">
                <h1><i class="ri-macbook-line"></i> Laptop Inventory</h1>
                <p>Manage, track, and monitor all registered campus laptops.</p>
            </div>
        </header>

        <!-- Tool & Search Bar -->
        <div class="table-controls">
            <div class="search-box">
                <i class="ri-search-2-line"></i>
                <input type="text" class="search-input" placeholder="Search by Model, Serial No, or Assignee...">
            </div>
            <div class="action-buttons">
                <button class="btn btn-outline" title="Filter Records">
                    <i class="ri-filter-3-line"></i> Filter
                </button>
                <div class="dropdown-container">
                    <button class="btn btn-outline" onclick="toggleActionDropdown(this, event)">
                        <i class="ri-download-line"></i> Export <i class="ri-arrow-down-s-line" style="margin-left: 4px;"></i>
                    </button>
                    <div class="action-dropdown">
                        <a href="#" class="action-dropdown-item"><i class="ri-file-pdf-line" style="color: var(--danger);"></i> Export PDF</a>
                        <a href="#" class="action-dropdown-item"><i class="ri-file-excel-line" style="color: var(--success);"></i> Export CSV</a>
                    </div>
                </div>
                <div class="dropdown-container">
                    <button class="btn btn-primary" onclick="toggleActionDropdown(this, event)">
                        <i class="ri-add-line"></i> Register Laptop <i class="ri-arrow-down-s-line" style="margin-left: 4px;"></i>
                    </button>
                    <div class="action-dropdown">
                        <a href="#" class="action-dropdown-item"><i class="ri-macbook-line" style="color: var(--primary);"></i> Single Asset</a>
                        <a href="#" class="action-dropdown-item"><i class="ri-stack-line" style="color: var(--secondary);"></i> Bulk Assets</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory Data Table -->
        <div class="glass-card">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Device Identity</th>
                            <th>Department</th>
                            <th>Assigned To</th>
                            <th>Purchase Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="laptop-identity">
                                    <div class="laptop-icon"><i class="ri-macbook-fill"></i></div>
                                    <div class="laptop-info">
                                        <h4>Lenovo ThinkPad T14</h4>
                                        <p>SN: PF-192XKA &bull; RCMP-LTP-001</p>
                                    </div>
                                </div>
                            </td>
                            <td>Academic Dept</td>
                            <td>Dr. Azman Shah</td>
                            <td>12 Mar 2024</td>
                            <td><span class="badge badge-active"><i class="ri-checkbox-circle-fill"></i> Active</span></td>
                            <td>
                                <button class="btn-action view" title="View Logs"><i class="ri-eye-line"></i></button>
                                <button class="btn-action edit" title="Edit Device"><i class="ri-edit-line"></i></button>
                            </td>
                        </tr>
                        
                        <tr>
                            <td>
                                <div class="laptop-identity">
                                    <div class="laptop-icon" style="color: #94a3b8; background: rgba(148, 163, 184, 0.15); border-color: rgba(148, 163, 184, 0.3);"><i class="ri-macbook-line"></i></div>
                                    <div class="laptop-info">
                                        <h4>Dell Latitude 5420</h4>
                                        <p>SN: 8H7V2A1 &bull; RCMP-LTP-045</p>
                                    </div>
                                </div>
                            </td>
                            <td>Library Unit</td>
                            <td>Library Reserve</td>
                            <td>20 Jun 2023</td>
                            <td><span class="badge badge-reserve"><i class="ri-archive-fill"></i> Reserve</span></td>
                            <td>
                                <button class="btn-action view" title="View Logs"><i class="ri-eye-line"></i></button>
                                <button class="btn-action edit" title="Edit Device"><i class="ri-edit-line"></i></button>
                            </td>
                        </tr>

                        <tr>
                            <td>
                                <div class="laptop-identity">
                                    <div class="laptop-icon"><i class="ri-macbook-fill"></i></div>
                                    <div class="laptop-info">
                                        <h4>HP ProBook 450 G9</h4>
                                        <p>SN: 5CD2349XZ &bull; RCMP-LTP-088</p>
                                    </div>
                                </div>
                            </td>
                            <td>Administration</td>
                            <td>Siti Munirah</td>
                            <td>05 Jan 2025</td>
                            <td><span class="badge badge-active"><i class="ri-checkbox-circle-fill"></i> Active</span></td>
                            <td>
                                <button class="btn-action view" title="View Logs"><i class="ri-eye-line"></i></button>
                                <button class="btn-action edit" title="Edit Device"><i class="ri-edit-line"></i></button>
                            </td>
                        </tr>

                        <tr>
                            <td>
                                <div class="laptop-identity">
                                    <div class="laptop-icon" style="color: #f59e0b; background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.3);"><i class="ri-tools-fill"></i></div>
                                    <div class="laptop-info">
                                        <h4>MacBook Air M1</h4>
                                        <p>SN: FVFXC21QK &bull; RCMP-LTP-012</p>
                                    </div>
                                </div>
                            </td>
                            <td>Management</td>
                            <td>Dato' Ridzuan</td>
                            <td>10 Nov 2022</td>
                            <td><span class="badge badge-repair"><i class="ri-tools-fill"></i> In Repair</span></td>
                            <td>
                                <button class="btn-action view" title="View Logs"><i class="ri-eye-line"></i></button>
                                <button class="btn-action edit" title="Edit Device"><i class="ri-edit-line"></i></button>
                            </td>
                        </tr>

                        <tr>
                            <td>
                                <div class="laptop-identity">
                                    <div class="laptop-icon" style="color: #ef4444; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3);"><i class="ri-alert-fill"></i></div>
                                    <div class="laptop-info">
                                        <h4>Acer Extensa 15</h4>
                                        <p>SN: NXEGA2019 &bull; RCMP-LTP-105</p>
                                    </div>
                                </div>
                            </td>
                            <td>Student Affairs</td>
                            <td>-</td>
                            <td>15 Aug 2019</td>
                            <td><span class="badge badge-disposed"><i class="ri-delete-bin-fill"></i> Disposed</span></td>
                            <td>
                                <button class="btn-action view" title="View Logs"><i class="ri-eye-line"></i></button>
                                <button class="btn-action edit" title="Edit Device"><i class="ri-edit-line"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination Wrapper -->
            <div class="pagination">
                <div class="page-info">Showing 1 to 5 of 124 entries</div>
                <button class="page-btn" disabled><i class="ri-arrow-left-s-line"></i></button>
                <button class="page-btn active">1</button>
                <button class="page-btn">2</button>
                <button class="page-btn">3</button>
                <button class="page-btn">...</button>
                <button class="page-btn">25</button>
                <button class="page-btn"><i class="ri-arrow-right-s-line"></i></button>
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

        function toggleActionDropdown(element, event) {
            event.stopPropagation();
            const container = element.closest('.dropdown-container');
            const dropdown = container.querySelector('.action-dropdown');
            
            // Close other action dropdowns
            document.querySelectorAll('.action-dropdown.show').forEach(drop => {
                if (drop !== dropdown) {
                    drop.classList.remove('show');
                }
            });
            
            dropdown.classList.toggle('show');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.dropdown-container')) {
                document.querySelectorAll('.action-dropdown.show').forEach(drop => {
                    drop.classList.remove('show');
                });
            }
        });
    </script>
</body>
</html>
