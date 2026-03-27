<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

// UI-only page for now (no network_assets table yet).
$assets = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Inventory - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --secondary: #0ea5e9;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --bg: #f1f5f9;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --glass-panel: #f8fafc;
            --glass-border: #e2e8f0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

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
            width: auto;
            max-width: 100%;
            object-fit: contain;
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
            transition: all 0.25s ease;
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

        .nav-item i { font-size: 1.25rem; color: inherit; }

        .nav-dropdown {
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            padding-left: 3.25rem;
            margin-top: -0.25rem;
            margin-bottom: 0.25rem;
        }

        .nav-dropdown.show { display: flex; }

        .nav-dropdown-item {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.25s ease;
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
            transition: all 0.25s ease;
        }

        .nav-dropdown-item:hover {
            color: var(--primary);
            background: rgba(37,99,235,0.06);
        }

        .nav-dropdown-item:hover::before,
        .nav-dropdown-item.active::before {
            background: var(--primary);
        }

        .nav-dropdown-item.active { color: var(--primary); }
        .nav-item.open .chevron { transform: rotate(180deg); }

        .user-profile {
            margin-top: auto;
            padding: 1rem;
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.25s ease;
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
            color: #fff;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
        }

        .user-info { flex: 1; overflow: hidden; }
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

        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 2.5rem 3.5rem 5rem;
            max-width: calc(100vw - 280px);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 1.75rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--card-border);
        }

        .page-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.1rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title h1 i { color: var(--primary); }
        .page-title p { color: var(--text-muted); margin-top: 0.35rem; }

        .header-actions { display: flex; gap: 0.75rem; }
        .btn {
            padding: 0.75rem 1.2rem;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.25);
        }
        .btn-primary:hover { transform: translateY(-2px); filter: brightness(1.06); }
        .btn-outline {
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
        }
        .btn-outline:hover { color: var(--primary); border-color: rgba(37,99,235,0.25); background: rgba(37,99,235,0.06); }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 2px 12px rgba(15,23,42,0.06);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .stat-icon {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; flex-shrink: 0;
        }
        .icon-blue { background: rgba(37,99,235,0.12); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); }
        .icon-green { background: rgba(16,185,129,0.12); color: var(--success); border: 1px solid rgba(16,185,129,0.25); }
        .icon-red { background: rgba(239,68,68,0.12); color: var(--danger); border: 1px solid rgba(239,68,68,0.25); }
        .icon-amber { background: rgba(245,158,11,0.12); color: var(--warning); border: 1px solid rgba(245,158,11,0.25); }
        .stat-num { font-family:'Outfit',sans-serif; font-size: 1.9rem; font-weight: 800; line-height: 1; }
        .stat-label { margin-top: 0.25rem; font-size: 0.78rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; }

        .controls-bar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .search-box {
            flex: 1;
            min-width: 260px;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.65rem 1rem;
            transition: border-color 0.2s ease;
        }
        .search-box:focus-within { border-color: rgba(37,99,235,0.5); }
        .search-box i { color: var(--text-muted); font-size: 1.1rem; }
        .search-box input {
            border: none; outline: none; background: none;
            width: 100%;
            font-size: 0.92rem;
            color: var(--text-main);
        }

        .chip {
            background: var(--glass-panel);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            border-radius: 999px;
            padding: 0.55rem 0.9rem;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .chip:hover { border-color: rgba(37,99,235,0.25); color: var(--primary); background: rgba(37,99,235,0.06); }
        .chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }

        .table-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            box-shadow: 0 2px 16px rgba(15,23,42,0.06);
            overflow: hidden;
        }
        .table-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        .table-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }
        .table-title i { color: var(--primary); }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: var(--glass-panel); }
        th {
            text-align: left;
            padding: 1rem 1.25rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--card-border);
            white-space: nowrap;
        }
        td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid rgba(226,232,240,0.65);
            font-size: 0.92rem;
            color: var(--text-main);
            vertical-align: middle;
        }
        tbody tr:hover { background: rgba(37,99,235,0.02); }

        .asset-cell { display: flex; align-items: center; gap: 0.8rem; }
        .asset-icon {
            width: 40px; height: 40px; border-radius: 12px;
            background: rgba(14,165,233,0.12);
            border: 1px solid rgba(14,165,233,0.25);
            display: flex; align-items: center; justify-content: center;
            color: var(--secondary);
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .asset-meta { min-width: 220px; }
        .asset-name { font-weight: 800; }
        .asset-sub { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.12rem; }

        .badge {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .badge-online { background: rgba(16,185,129,0.12); color: var(--success); border-color: rgba(16,185,129,0.25); }
        .badge-offline { background: rgba(239,68,68,0.12); color: var(--danger); border-color: rgba(239,68,68,0.25); }
        .badge-unknown { background: rgba(148,163,184,0.18); color: #64748b; border-color: rgba(148,163,184,0.35); }

        .row-actions { display: flex; gap: 0.4rem; }
        .icon-btn {
            width: 38px; height: 38px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: var(--glass-panel);
            color: var(--text-muted);
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .icon-btn:hover { color: var(--primary); border-color: rgba(37,99,235,0.25); background: rgba(37,99,235,0.06); transform: translateY(-1px); }

        .empty {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }
        .empty i { font-size: 3rem; display: block; margin-bottom: 0.75rem; color: #cbd5e1; }
        .empty h3 { font-family:'Outfit',sans-serif; color: var(--text-main); font-size: 1.25rem; margin-bottom: 0.4rem; }
        .empty p { max-width: 560px; margin: 0 auto; line-height: 1.5; }

        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .main-content { margin-left: 0; max-width: 100vw; padding: 1.5rem; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .header-actions { width: 100%; }
        }
    </style>
</head>
<body>
    
    <!-- Sidebar -->
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <div class="page-title">
                <h1><i class="ri-router-line"></i> Network Inventory</h1>
                <p>View and manage registered network assets (switches, routers, APs, firewalls).</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-outline" type="button" disabled title="Coming soon">
                    <i class="ri-download-cloud-2-line"></i> Export
                </button>
                <button class="btn btn-primary" type="button" disabled title="Coming soon">
                    <i class="ri-add-line"></i> Register Asset
                </button>
            </div>
        </header>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="ri-router-line"></i></div>
                <div>
                    <div class="stat-num" id="statTotal">0</div>
                    <div class="stat-label">Total Assets</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="ri-wifi-line"></i></div>
                <div>
                    <div class="stat-num" id="statOnline">0</div>
                    <div class="stat-label">Online</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-red"><i class="ri-wifi-off-line"></i></div>
                <div>
                    <div class="stat-num" id="statOffline">0</div>
                    <div class="stat-label">Offline</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-amber"><i class="ri-alert-line"></i></div>
                <div>
                    <div class="stat-num" id="statAttention">0</div>
                    <div class="stat-label">Needs Attention</div>
                </div>
            </div>
        </section>

        <div class="controls-bar">
            <div class="search-box">
                <i class="ri-search-2-line"></i>
                <input id="searchInput" type="text" placeholder="Search by hostname, IP, MAC, location, or model...">
            </div>
            <button class="chip active" type="button" data-filter="all"><i class="ri-apps-line"></i> All</button>
            <button class="chip" type="button" data-filter="online"><i class="ri-wifi-line"></i> Online</button>
            <button class="chip" type="button" data-filter="offline"><i class="ri-wifi-off-line"></i> Offline</button>
        </div>

        <section class="table-card">
            <div class="table-header">
                <div class="table-title"><i class="ri-list-check-2"></i> Network Assets</div>
                <div style="color:var(--text-muted); font-weight:700; font-size:0.9rem;">
                    Showing <span id="rowCount">0</span> item(s)
                </div>
            </div>

            <div class="table-responsive">
                <table id="assetTable">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>Type</th>
                            <th>IP Address</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="assetTbody">
                        <?php if (empty($assets)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty">
                                        <i class="ri-inbox-line"></i>
                                        <h3>No network assets yet</h3>
                                        <p>
                                            This page UI is ready. Once you add a network assets table (or confirm the columns you want),
                                            we can wire this list to real data with filters, pagination, and status badges.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        function toggleDropdown(element, event) {
            event.preventDefault();
            const group = element.closest('.nav-group');
            const dropdown = group.querySelector('.nav-dropdown');
            element.classList.toggle('open');
            dropdown.classList.toggle('show');
        }

        const chips = Array.from(document.querySelectorAll('.chip[data-filter]'));
        const searchInput = document.getElementById('searchInput');
        const tbody = document.getElementById('assetTbody');
        const rowCount = document.getElementById('rowCount');

        function setActiveChip(filter) {
            chips.forEach(c => c.classList.toggle('active', c.dataset.filter === filter));
        }

        function updateCounts() {
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.querySelector('td'));
            const visible = rows.filter(r => r.style.display !== 'none');
            rowCount.textContent = visible.length.toString();
        }

        function applyFilters() {
            const active = chips.find(c => c.classList.contains('active'))?.dataset.filter || 'all';
            const q = (searchInput.value || '').toLowerCase();
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.dataset && r.dataset.status);

            rows.forEach(row => {
                const statusOk = active === 'all' ? true : row.dataset.status === active;
                const textOk = row.innerText.toLowerCase().includes(q);
                row.style.display = (statusOk && textOk) ? '' : 'none';
            });

            updateCounts();
        }

        chips.forEach(chip => chip.addEventListener('click', () => {
            setActiveChip(chip.dataset.filter);
            applyFilters();
        }));
        searchInput.addEventListener('input', applyFilters);

        updateCounts();
    </script>
</body>
</html>