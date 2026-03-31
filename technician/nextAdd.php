<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

/** @return 'laptop'|'av'|'network' */
function nextadd_asset_class(): string
{
    $c = strtolower(trim((string)($_GET['asset_class'] ?? $_GET['type'] ?? 'laptop')));
    return in_array($c, ['laptop', 'av', 'network'], true) ? $c : 'laptop';
}

if (isset($_GET['lookup_asset_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim((string)$_GET['lookup_asset_id']);
    $class = nextadd_asset_class();
    if ($id === '' || !ctype_digit($id)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid asset id']);
        exit;
    }
    try {
        $pdo = db();
        if ($class === 'network') {
            $stmt = $pdo->prepare("
                SELECT n.asset_id, n.serial_num, n.brand, n.model, s.name AS status_name
                FROM network n
                JOIN status s ON s.status_id = n.status_id
                WHERE n.asset_id = ?
                LIMIT 1
            ");
        } elseif ($class === 'av') {
            $stmt = $pdo->prepare("
                SELECT a.asset_id, a.serial_num, a.brand, a.model, s.name AS status_name
                FROM av a
                JOIN status s ON s.status_id = a.status_id
                WHERE a.asset_id = ?
                LIMIT 1
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT l.asset_id, l.serial_num, l.brand, l.model, s.name AS status_name
                FROM laptop l
                JOIN status s ON s.status_id = l.status_id
                WHERE l.asset_id = ?
                LIMIT 1
            ");
        }
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Asset not found']);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'asset' => [
                'asset_class' => $class,
                'asset_id' => (int)$row['asset_id'],
                'serial' => (string)($row['serial_num'] ?? ''),
                'brand' => (string)($row['brand'] ?? ''),
                'model' => (string)($row['model'] ?? ''),
                'status' => (string)($row['status_name'] ?? '—'),
            ],
        ]);
        exit;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if ($class === 'av' && (stripos($msg, 'av') !== false || stripos($msg, "doesn't exist") !== false || stripos($msg, 'Unknown table') !== false)) {
            echo json_encode(['ok' => false, 'error' => 'AV table is not set up in the database yet.']);
            exit;
        }
        echo json_encode(['ok' => false, 'error' => 'Lookup failed']);
        exit;
    }
}

$suggest_q = isset($_GET['suggest_q']) ? trim((string)$_GET['suggest_q']) : '';
if ($suggest_q !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $class = nextadd_asset_class();
    if (!preg_match('/^\d{1,20}$/', $suggest_q)) {
        echo json_encode(['ok' => true, 'items' => []]);
        exit;
    }
    try {
        $pdo = db();
        if ($class === 'network') {
            $stmt = $pdo->prepare("
                SELECT n.asset_id, n.serial_num, n.brand, n.model, s.name AS status_name
                FROM network n
                JOIN status s ON s.status_id = n.status_id
                WHERE CAST(n.asset_id AS CHAR) LIKE CONCAT(?, '%')
                ORDER BY n.asset_id DESC
                LIMIT 8
            ");
        } elseif ($class === 'av') {
            $stmt = $pdo->prepare("
                SELECT a.asset_id, a.serial_num, a.brand, a.model, s.name AS status_name
                FROM av a
                JOIN status s ON s.status_id = a.status_id
                WHERE CAST(a.asset_id AS CHAR) LIKE CONCAT(?, '%')
                ORDER BY a.asset_id DESC
                LIMIT 8
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT l.asset_id, l.serial_num, l.brand, l.model, s.name AS status_name
                FROM laptop l
                JOIN status s ON s.status_id = l.status_id
                WHERE CAST(l.asset_id AS CHAR) LIKE CONCAT(?, '%')
                ORDER BY l.asset_id DESC
                LIMIT 8
            ");
        }
        $stmt->execute([$suggest_q]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'asset_class' => $class,
                'asset_id' => (int)$r['asset_id'],
                'serial' => (string)($r['serial_num'] ?? ''),
                'brand' => (string)($r['brand'] ?? ''),
                'model' => (string)($r['model'] ?? ''),
                'status' => (string)($r['status_name'] ?? '—'),
            ];
        }
        echo json_encode(['ok' => true, 'items' => $items]);
        exit;
    } catch (Throwable $e) {
        if ($class === 'av') {
            echo json_encode(['ok' => true, 'items' => []]);
            exit;
        }
        echo json_encode(['ok' => false, 'error' => 'Suggest failed']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily checkout — NextCheck - RCMP NIMS</title>
    <link rel="icon" type="image/png" href="../public/rcmp.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        :root{
            --primary:#2563eb;
            --secondary:#0ea5e9;
            --success:#10b981;
            --danger:#ef4444;
            --warning:#f59e0b;
            --bg:#f1f5f9;
            --card-bg:#ffffff;
            --card-border:#e2e8f0;
            --text-main:#0f172a;
            --text-muted:#64748b;
            --glass-panel:#f8fafc;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{
            font-family:'Inter',sans-serif;
            background:var(--bg);
            color:var(--text-main);
            min-height:100vh;
            display:flex;
            overflow-x:hidden;
        }
        .main-content{
            margin-left:280px;
            flex:1;
            padding:2rem 2.5rem;
            max-width:calc(100vw - 280px);
        }
        .page-header{
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap:1rem;
            padding-bottom:1.25rem;
            border-bottom:1px solid var(--card-border);
            margin-bottom:1.25rem;
        }
        .title h1{
            font-family:'Outfit',sans-serif;
            font-size:2rem;
            letter-spacing:-0.4px;
            display:flex;
            align-items:center;
            gap:0.75rem;
        }
        .title h1 i{color:var(--secondary)}
        .title p{color:var(--text-muted);margin-top:0.35rem}

        .layout{
            display:grid;
            grid-template-columns: 1fr;
            gap:1.25rem;
            align-items:start;
        }

        .card{
            background:var(--card-bg);
            border:1px solid var(--card-border);
            border-radius:18px;
            box-shadow:0 10px 25px rgba(15,23,42,0.05);
        }
        .card-hd{
            padding:1.1rem 1.25rem;
            border-bottom:1px solid var(--card-border);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:1rem;
        }
        .card-hd h2{
            font-family:'Outfit',sans-serif;
            font-size:1.05rem;
            font-weight:800;
            display:flex;
            align-items:center;
            gap:0.6rem;
        }
        .card-hd h2 i{color:var(--primary)}
        .card-bd{padding:1.25rem}

        .stat-cards{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:1rem;
        }
        .stat-card{
            background:var(--card-bg);
            border:1px solid var(--card-border);
            border-radius:16px;
            padding:1.1rem 1.15rem;
            display:flex;
            align-items:center;
            gap:1rem;
            box-shadow:0 8px 22px rgba(15,23,42,0.06);
        }
        .stat-card-icon{
            width:48px;height:48px;
            border-radius:14px;
            display:flex;
            align-items:center;
            justify-content:center;
            flex-shrink:0;
            font-size:1.35rem;
        }
        .stat-card--laptop .stat-card-icon{
            background:rgba(37,99,235,0.10);
            border:1px solid rgba(37,99,235,0.22);
            color:var(--primary);
        }
        .stat-card--av .stat-card-icon{
            background:rgba(245,158,11,0.12);
            border:1px solid rgba(245,158,11,0.28);
            color:var(--warning);
        }
        .stat-card--network .stat-card-icon{
            background:rgba(16,185,129,0.12);
            border:1px solid rgba(16,185,129,0.28);
            color:var(--success);
        }
        .stat-card-text{display:flex;flex-direction:column;gap:0.2rem;min-width:0}
        .stat-card-value{
            font-family:'Outfit',sans-serif;
            font-size:1.65rem;
            font-weight:800;
            letter-spacing:-0.5px;
            color:var(--text-main);
            line-height:1.1;
        }
        .stat-card-label{
            font-size:0.84rem;
            font-weight:600;
            color:var(--text-muted);
        }

        .search{
            display:flex;
            gap:0.75rem;
            flex-wrap:wrap;
            margin-bottom:0.75rem;
        }
        .search-box{
            flex:1;
            min-width:240px;
            position:relative;
        }
        .search-box i{
            position:absolute;
            left:1rem;
            top:50%;
            transform:translateY(-50%);
            color:var(--text-muted);
        }
        .input{
            width:100%;
            background:var(--glass-panel);
            border:1px solid var(--card-border);
            border-radius:14px;
            padding:0.85rem 1rem 0.85rem 2.75rem;
            outline:none;
            transition:all .2s ease;
        }
        .input:focus{
            background:#fff;
            border-color:rgba(14,165,233,0.35);
            box-shadow:0 0 0 4px rgba(14,165,233,0.10);
        }

        .suggest {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 8px);
            background: #fff;
            border: 1px solid var(--card-border);
            border-radius: 16px;
            box-shadow: 0 18px 40px rgba(15,23,42,0.12);
            overflow: hidden;
            z-index: 60;
            display: none;
        }
        .suggest.show { display: block; }
        .suggest-item {
            padding: 0.75rem 0.85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            cursor: pointer;
            border-top: 1px solid rgba(226,232,240,0.7);
        }
        .suggest-item:first-child { border-top: none; }
        .suggest-item:hover { background: rgba(14,165,233,0.06); }
        .suggest-left { min-width: 0; }
        .suggest-title { font-weight: 900; font-family: 'Outfit', sans-serif; }
        .suggest-sub { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 520px; }
        .suggest-pill {
            font-size: 0.7rem;
            font-weight: 900;
            letter-spacing: 0.45px;
            text-transform: uppercase;
            padding: 0.22rem 0.55rem;
            border-radius: 999px;
            border: 1px solid var(--card-border);
            background: var(--glass-panel);
            color: var(--text-muted);
            white-space: nowrap;
        }
        .btn{
            border:none;
            border-radius:14px;
            padding:0.85rem 1rem;
            font-weight:800;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:0.55rem;
            transition:all .2s ease;
            user-select:none;
        }
        .btn-ghost{
            background:transparent;
            border:1px solid var(--card-border);
            color:var(--text-muted);
        }
        .btn-ghost:hover{color:var(--secondary);border-color:rgba(14,165,233,0.25);background:rgba(14,165,233,0.04)}
        .btn-primary{
            background:linear-gradient(135deg, var(--primary), var(--secondary));
            color:#fff;
            box-shadow:0 12px 24px rgba(37,99,235,0.18);
        }
        .btn-primary:hover{filter:brightness(0.98)}
        .btn-primary:disabled{
            opacity:0.45;
            cursor:not-allowed;
            filter:none;
            box-shadow:none;
        }
        .btn-danger{
            background:rgba(239,68,68,0.10);
            color:var(--danger);
            border:1px solid rgba(239,68,68,0.25);
        }
        .btn-danger:hover{background:rgba(239,68,68,0.14)}

        .pill{
            font-size:0.72rem;
            font-weight:900;
            letter-spacing:0.5px;
            text-transform:uppercase;
            padding:0.28rem 0.6rem;
            border-radius:999px;
            border:1px solid transparent;
            white-space:nowrap;
        }
        .pill.ok{background:rgba(16,185,129,0.12);color:var(--success);border-color:rgba(16,185,129,0.25)}
        .pill.warn{background:rgba(245,158,11,0.12);color:var(--warning);border-color:rgba(245,158,11,0.25)}
        .pill.bad{background:rgba(239,68,68,0.12);color:var(--danger);border-color:rgba(239,68,68,0.25)}
        .pill.muted{background:rgba(100,116,139,0.12);color:var(--text-muted);border-color:rgba(100,116,139,0.25)}
        .pill.nextcheck{background:rgba(14,165,233,0.12);color:#0369a1;border-color:rgba(14,165,233,0.28)}
        .pill.type-laptop{background:rgba(37,99,235,0.10);color:var(--primary);border-color:rgba(37,99,235,0.22)}
        .pill.type-av{background:rgba(139,92,246,0.12);color:#6d28d9;border-color:rgba(139,92,246,0.28)}
        .pill.type-network{background:rgba(5,150,105,0.12);color:#047857;border-color:rgba(5,150,105,0.25)}

        .asset-class-tabs{
            display:flex;
            flex-wrap:wrap;
            gap:0.5rem;
            margin-bottom:0.85rem;
        }
        .asset-class-tabs button{
            border:1px solid var(--card-border);
            background:var(--glass-panel);
            color:var(--text-muted);
            font-weight:800;
            font-size:0.82rem;
            padding:0.5rem 0.85rem;
            border-radius:999px;
            cursor:pointer;
            transition:all .2s ease;
            font-family:'Inter',sans-serif;
        }
        .asset-class-tabs button:hover{border-color:rgba(14,165,233,0.35);color:var(--text-main)}
        .asset-class-tabs button.active{
            background:rgba(37,99,235,0.12);
            border-color:rgba(37,99,235,0.35);
            color:var(--primary);
        }
        .asset-class-tabs button i{font-size:1rem;margin-right:0.35rem;vertical-align:-2px;opacity:0.9}

        .type-filters{
            display:flex;
            flex-wrap:wrap;
            gap:0.45rem;
            margin-bottom:0.85rem;
            align-items:center;
        }
        .type-filters .filter-label{
            font-size:0.72rem;
            font-weight:900;
            letter-spacing:0.55px;
            text-transform:uppercase;
            color:var(--text-muted);
            margin-right:0.35rem;
        }
        .type-filters button{
            border:1px solid var(--card-border);
            background:#fff;
            color:var(--text-muted);
            font-weight:800;
            font-size:0.8rem;
            padding:0.45rem 0.75rem;
            border-radius:12px;
            cursor:pointer;
            transition:all .2s ease;
            font-family:'Inter',sans-serif;
        }
        .type-filters button:hover{background:var(--glass-panel);color:var(--text-main)}
        .type-filters button.on{
            background:var(--text-main);
            color:#fff;
            border-color:var(--text-main);
        }

        .table-wrap{
            border:1px solid var(--card-border);
            border-radius:16px;
            overflow:hidden;
            background:#fff;
        }
        .checkout-table{
            width:100%;
            border-collapse:collapse;
            font-size:0.9rem;
        }
        .checkout-table th{
            text-align:left;
            padding:0.75rem 0.9rem;
            background:var(--glass-panel);
            color:var(--text-muted);
            font-size:0.7rem;
            font-weight:900;
            letter-spacing:0.55px;
            text-transform:uppercase;
            border-bottom:1px solid var(--card-border);
        }
        .checkout-table td{
            padding:0.75rem 0.9rem;
            border-bottom:1px solid var(--card-border);
            vertical-align:middle;
        }
        .checkout-table tbody tr:last-child td{border-bottom:none}
        .checkout-table tbody tr:hover td{background:rgba(14,165,233,0.04)}
        .checkout-table .cell-main{font-weight:700;font-family:'Outfit',sans-serif}
        .checkout-table .cell-muted{color:var(--text-muted);font-size:0.84rem;margin-top:0.12rem}
        .icon-btn{
            width:38px;height:38px;border-radius:12px;
            display:inline-flex;align-items:center;justify-content:center;
            border:1px solid var(--card-border);
            background:var(--glass-panel);
            cursor:pointer;
            color:var(--text-muted);
            transition:all .2s ease;
            flex-shrink:0;
        }
        .icon-btn:hover{background:rgba(239,68,68,0.10);border-color:rgba(239,68,68,0.18);color:var(--danger)}

        .summary{
            margin-top:0.9rem;
            border-top:1px dashed var(--card-border);
            padding-top:0.9rem;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:1rem;
            flex-wrap:wrap;
        }
        .summary strong{font-family:'Outfit',sans-serif}
        .muted{color:var(--text-muted)}

        .notice{
            background: rgba(14,165,233,0.08);
            border: 1px solid rgba(14,165,233,0.2);
            border-radius: 14px;
            padding: 0.85rem 1rem;
            color: var(--text-main);
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .notice i{color: var(--secondary); margin-top: 0.15rem;}

        .status-hint{
            font-size:0.85rem;
            margin-top:0.35rem;
            color:var(--text-muted);
        }
        .status-hint code{
            font-size:0.8rem;
            background:var(--glass-panel);
            padding:0.12rem 0.4rem;
            border-radius:6px;
            border:1px solid var(--card-border);
        }

        .footer-hint{
            margin-top:0.85rem;
            padding-top:0.85rem;
            border-top:1px solid var(--card-border);
            font-size:0.88rem;
            color:var(--text-muted);
            line-height:1.45;
        }

        @media (max-width: 1100px){
            .main-content{padding:1.25rem 1.25rem}
        }
        @media (max-width: 720px){
            .stat-cards{grid-template-columns:1fr}
        }
        @media (max-width: 900px){
            .main-content{margin-left:0;max-width:100vw}
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <div class="title">
                <h1><i class="ri-calendar-check-line"></i> Daily checkout</h1>
                <p>Pick Laptop, AV, or Network, search by Asset ID, then review the table below (filter by type).</p>
            </div>
            <button class="btn btn-ghost" type="button" onclick="history.back()">
                <i class="ri-arrow-left-line"></i> Back
            </button>
        </header>

        <div class="layout">
            <section class="card">
                <div class="card-hd">
                    <h2><i class="ri-barcode-line"></i> Add assets</h2>
                    <div class="muted" style="font-weight:700;font-size:0.9rem">
                        In list: <span id="selectedCount">0</span>
                    </div>
                </div>
                <div class="card-bd">
                    <div class="notice">
                        <i class="ri-information-line"></i>
                        <div>
                            <div style="font-weight:900;font-family:'Outfit',sans-serif">NextCheck checkout</div>
                            <div class="muted" style="margin-top:0.1rem">Suggestions appear as you type digits. Pick a row or enter the full ID and press Add.</div>
                            <div class="status-hint">Planned status after save: <code>Checkout (nextcheck)</code> — UI only for now.</div>
                        </div>
                    </div>

                    <div class="asset-class-tabs" role="tablist" aria-label="Asset type for search">
                        <button type="button" class="active" data-asset-class="laptop" id="tabClassLaptop"><i class="ri-macbook-line"></i> Laptop</button>
                        <button type="button" data-asset-class="av" id="tabClassAv"><i class="ri-film-line"></i> AV</button>
                        <button type="button" data-asset-class="network" id="tabClassNetwork"><i class="ri-router-line"></i> Network</button>
                    </div>
                    <div class="search">
                        <div class="search-box">
                            <i class="ri-search-line"></i>
                            <input id="assetIdInput" class="input" type="text" inputmode="numeric" autocomplete="off" placeholder="Asset ID (e.g. 22260001)" aria-label="Asset ID search">
                            <div class="suggest" id="suggestBox" role="listbox" aria-label="Asset suggestions"></div>
                        </div>
                        <button class="btn btn-primary" type="button" id="addByIdBtn">
                            <i class="ri-add-line"></i> Add
                        </button>
                        <button class="btn btn-danger" type="button" id="clearSelected">
                            <i class="ri-close-circle-line"></i> Clear list
                        </button>
                    </div>
                </div>
            </section>

            <div class="stat-cards" aria-label="Checkout totals by type">
                <div class="stat-card stat-card--laptop">
                    <div class="stat-card-icon" aria-hidden="true"><i class="ri-macbook-line"></i></div>
                    <div class="stat-card-text">
                        <span class="stat-card-value" id="countLaptop">0</span>
                        <span class="stat-card-label">Total Laptop</span>
                    </div>
                </div>
                <div class="stat-card stat-card--av">
                    <div class="stat-card-icon" aria-hidden="true"><i class="ri-film-line"></i></div>
                    <div class="stat-card-text">
                        <span class="stat-card-value" id="countAv">0</span>
                        <span class="stat-card-label">Total AV</span>
                    </div>
                </div>
                <div class="stat-card stat-card--network">
                    <div class="stat-card-icon" aria-hidden="true"><i class="ri-router-line"></i></div>
                    <div class="stat-card-text">
                        <span class="stat-card-value" id="countNetwork">0</span>
                        <span class="stat-card-label">Total Network</span>
                    </div>
                </div>
            </div>

            <section class="card">
                <div class="card-hd">
                    <h2><i class="ri-list-check-2"></i> Today’s checkout list</h2>
                    <div class="muted" style="font-weight:700;font-size:0.9rem;text-align:right">
                        <div>Total: <span id="listCount">0</span></div>
                        <div style="font-weight:600;font-size:0.82rem;margin-top:0.2rem" id="filterSummary"></div>
                    </div>
                </div>
                <div class="card-bd">
                    <div class="type-filters" role="group" aria-label="Filter list by type">
                        <span class="filter-label">Show</span>
                        <button type="button" class="on" data-filter="all">All</button>
                        <button type="button" data-filter="laptop">Laptop</button>
                        <button type="button" data-filter="av">AV</button>
                        <button type="button" data-filter="network">Network</button>
                    </div>
                    <div id="listEmpty" class="muted" style="padding:0.5rem 0.1rem 0.75rem">
                        No assets yet. Use the search field above to add items for daily checkout.
                    </div>
                    <div id="listEmptyFilter" class="muted" style="display:none;padding:0.5rem 0.1rem 0.75rem">
                        No items in this category. Switch filter or add assets above.
                    </div>
                    <div class="table-wrap" id="tableWrap" style="display:none">
                        <table class="checkout-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Asset ID</th>
                                    <th>Device</th>
                                    <th>Serial</th>
                                    <th>Status</th>
                                    <th style="width:52px"></th>
                                </tr>
                            </thead>
                            <tbody id="checkoutTableBody"></tbody>
                        </table>
                    </div>

                    <div class="summary">
                        <div>
                            <div class="muted">Total selected</div>
                            <strong><span id="listCount2">0</span> asset(s)</strong>
                        </div>
                        <button class="btn btn-primary" type="button" id="confirmCheckoutBtn" disabled title="Scrolls to implementation note until save is wired">
                            <i class="ri-shopping-bag-3-line"></i> Confirm checkout
                        </button>
                    </div>
                    <p class="footer-hint" id="checkoutFooterHint">
                        <strong style="color:var(--text-main)">UI preview:</strong> Confirm checkout is not wired to the database yet. <code style="font-size:0.8em">#asset_ids</code> is JSON like <code style="font-size:0.8em">[{&quot;asset_id&quot;:&quot;…&quot;,&quot;asset_class&quot;:&quot;laptop|av|network&quot;}]</code> for the save step (e.g. status <code style="font-size:0.8em">Checkout (nextcheck)</code>).
                    </p>
                    <input type="hidden" id="asset_ids" value="[]">
                </div>
            </section>
        </div>
    </main>

    <script>
        function toggleDropdown(element, event) {
            event.preventDefault();
            const group = element.closest('.nav-group');
            const dropdown = group?.querySelector('.nav-dropdown');
            element.classList.toggle('open');
            dropdown?.classList.toggle('show');
        }

        const assetIdInput = document.getElementById('assetIdInput');
        const addByIdBtn = document.getElementById('addByIdBtn');
        const clearSelected = document.getElementById('clearSelected');
        const confirmCheckoutBtn = document.getElementById('confirmCheckoutBtn');
        const hiddenAssetIds = document.getElementById('asset_ids');
        const selectedCount = document.getElementById('selectedCount');
        const listCount = document.getElementById('listCount');
        const listCount2 = document.getElementById('listCount2');
        const listEmpty = document.getElementById('listEmpty');
        const checkoutTableBody = document.getElementById('checkoutTableBody');
        const tableWrap = document.getElementById('tableWrap');
        const listEmptyFilter = document.getElementById('listEmptyFilter');
        const filterSummary = document.getElementById('filterSummary');
        const suggestBox = document.getElementById('suggestBox');
        const assetClassTabButtons = document.querySelectorAll('.asset-class-tabs [data-asset-class]');
        const filterButtons = document.querySelectorAll('.type-filters [data-filter]');
        const countLaptop = document.getElementById('countLaptop');
        const countAv = document.getElementById('countAv');
        const countNetwork = document.getElementById('countNetwork');

        let currentAssetClass = 'laptop';
        let listFilter = 'all';

        const selected = new Map();

        function rowKey(cls, id) {
            return `${cls}:${String(id)}`;
        }

        function typeLabel(cls) {
            if (cls === 'network') return 'Network';
            if (cls === 'av') return 'AV';
            return 'Laptop';
        }

        function typePillClass(cls) {
            if (cls === 'network') return 'type-network';
            if (cls === 'av') return 'type-av';
            return 'type-laptop';
        }

        function pillClass(status) {
            const s = String(status || '');
            if (s.includes('nextcheck')) return 'nextcheck';
            if (['Active','Reserved','Non-active','Online','Offline'].includes(s)) return 'ok';
            if (['Maintenance'].includes(s)) return 'warn';
            if (['Faulty','Lost','Disposed'].includes(s)) return 'bad';
            return 'muted';
        }

        function updateStatCards(items) {
            let nL = 0, nA = 0, nN = 0;
            for (const it of items) {
                const c = it.asset_class || 'laptop';
                if (c === 'network') nN++;
                else if (c === 'av') nA++;
                else nL++;
            }
            if (countLaptop) countLaptop.textContent = String(nL);
            if (countAv) countAv.textContent = String(nA);
            if (countNetwork) countNetwork.textContent = String(nN);
        }

        function renderSelectedList() {
            const items = Array.from(selected.values());
            selectedCount.textContent = String(items.length);
            listCount.textContent = String(items.length);
            listCount2.textContent = String(items.length);
            updateStatCards(items);
            confirmCheckoutBtn.disabled = items.length === 0;
            if (hiddenAssetIds) {
                hiddenAssetIds.value = JSON.stringify(items.map(x => ({
                    asset_id: String(x.asset_id),
                    asset_class: x.asset_class || 'laptop',
                })));
            }

            if (items.length === 0) {
                listEmpty.style.display = '';
                listEmptyFilter.style.display = 'none';
                tableWrap.style.display = 'none';
                checkoutTableBody.innerHTML = '';
                filterSummary.textContent = '';
                return;
            }

            listEmpty.style.display = 'none';
            const filtered = listFilter === 'all'
                ? items
                : items.filter((it) => (it.asset_class || 'laptop') === listFilter);

            filterSummary.textContent = listFilter === 'all'
                ? `Showing all ${items.length}`
                : `Showing ${filtered.length} of ${items.length}`;

            if (filtered.length === 0) {
                listEmptyFilter.style.display = '';
                tableWrap.style.display = 'none';
                checkoutTableBody.innerHTML = '';
                return;
            }

            listEmptyFilter.style.display = 'none';
            tableWrap.style.display = '';
            checkoutTableBody.innerHTML = filtered.map((it) => {
                const cls = it.asset_class || 'laptop';
                const title = `${it.brand} ${it.model}`.trim() || typeLabel(cls);
                const rk = rowKey(cls, it.asset_id);
                return `
                    <tr data-row-key="${escapeHtml(rk)}">
                        <td><span class="pill ${typePillClass(cls)}">${escapeHtml(typeLabel(cls))}</span></td>
                        <td><span class="cell-main">${escapeHtml(String(it.asset_id))}</span></td>
                        <td><div class="cell-main">${escapeHtml(title)}</div></td>
                        <td>${escapeHtml(it.serial || '—')}</td>
                        <td><span class="pill ${pillClass(it.status)}">${escapeHtml(it.status)}</span></td>
                        <td>
                            <button class="icon-btn" type="button" title="Remove" data-remove="${escapeHtml(rk)}">
                                <i class="ri-close-line"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function escapeHtml(s) {
            return String(s)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        checkoutTableBody.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-remove]');
            if (!btn) return;
            const key = btn.getAttribute('data-remove');
            if (!key) return;
            selected.delete(key);
            renderSelectedList();
        });

        assetClassTabButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const c = btn.getAttribute('data-asset-class');
                if (!c) return;
                currentAssetClass = c;
                assetClassTabButtons.forEach((b) => b.classList.toggle('active', b === btn));
                renderSuggest([]);
                fetchSuggest(assetIdInput.value);
            });
        });

        filterButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const f = btn.getAttribute('data-filter');
                if (!f) return;
                listFilter = f;
                filterButtons.forEach((b) => b.classList.toggle('on', b.getAttribute('data-filter') === f));
                renderSelectedList();
            });
        });

        clearSelected.addEventListener('click', () => {
            selected.clear();
            renderSelectedList();
        });

        const checkoutFooterHint = document.getElementById('checkoutFooterHint');
        confirmCheckoutBtn.addEventListener('click', () => {
            checkoutFooterHint?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });

        async function addByAssetId(assetId, classOverride) {
            const id = String(assetId || '').trim();
            if (!id) return;
            if (!ctypeDigit(id)) {
                alert('Please enter a valid numeric Asset ID.');
                return;
            }
            const cls = classOverride || currentAssetClass;
            const key = rowKey(cls, id);
            if (selected.has(key)) {
                assetIdInput.value = '';
                renderSuggest([]);
                return;
            }
            const data = await lookupAsset(id, cls);
            if (!data) return;
            const ac = data.asset_class || cls;
            selected.set(rowKey(ac, id), {
                asset_id: id,
                asset_class: ac,
                serial: data.serial || '—',
                brand: data.brand || '',
                model: data.model || '',
                status: data.status || '—',
            });
            assetIdInput.value = '';
            renderSuggest([]);
            renderSelectedList();
        }

        function ctypeDigit(s) {
            return /^[0-9]+$/.test(String(s));
        }

        async function lookupAsset(id, assetClass) {
            const ac = assetClass || currentAssetClass;
            try {
                const res = await fetch(`nextAdd.php?lookup_asset_id=${encodeURIComponent(id)}&asset_class=${encodeURIComponent(ac)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store'
                });
                const data = await res.json();
                if (!data?.ok) {
                    alert(data?.error || 'Asset not found.');
                    return null;
                }
                return data.asset || null;
            } catch (e) {
                alert('Lookup failed.');
                return null;
            }
        }

        function renderSuggest(items) {
            if (!items || items.length === 0) {
                suggestBox.classList.remove('show');
                suggestBox.innerHTML = '';
                return;
            }
            suggestBox.innerHTML = items.map(it => {
                const ac = it.asset_class || currentAssetClass;
                const title = `${it.asset_id} · ${(it.brand || '').trim()} ${(it.model || '').trim()}`.trim();
                const sub = `SN: ${it.serial || '—'} · ${typeLabel(ac)}`;
                return `
                    <div class="suggest-item" role="option" data-asset-id="${escapeHtml(String(it.asset_id))}" data-asset-class="${escapeHtml(ac)}">
                        <div class="suggest-left">
                            <div class="suggest-title">${escapeHtml(title)}</div>
                            <div class="suggest-sub">${escapeHtml(sub)}</div>
                        </div>
                        <span class="suggest-pill">${escapeHtml(it.status || '—')}</span>
                    </div>
                `;
            }).join('');
            suggestBox.classList.add('show');
        }

        let suggestTimer = null;
        async function fetchSuggest(term) {
            const t = String(term || '').trim();
            if (!ctypeDigit(t)) {
                renderSuggest([]);
                return;
            }
            try {
                const res = await fetch(`nextAdd.php?suggest_q=${encodeURIComponent(t)}&asset_class=${encodeURIComponent(currentAssetClass)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store'
                });
                const data = await res.json();
                if (!data?.ok) {
                    renderSuggest([]);
                    return;
                }
                renderSuggest(data.items || []);
            } catch (e) {
                renderSuggest([]);
            }
        }

        addByIdBtn.addEventListener('click', () => addByAssetId(assetIdInput.value));
        assetIdInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                addByAssetId(assetIdInput.value);
            }
            if (e.key === 'Escape') {
                renderSuggest([]);
            }
        });

        assetIdInput.addEventListener('input', () => {
            if (suggestTimer) window.clearTimeout(suggestTimer);
            suggestTimer = window.setTimeout(() => fetchSuggest(assetIdInput.value), 160);
        });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.search-box')) renderSuggest([]);
        });
        suggestBox?.addEventListener('click', (e) => {
            const row = e.target.closest('[data-asset-id]');
            const id = row?.getAttribute('data-asset-id');
            if (!id) return;
            const ac = row.getAttribute('data-asset-class') || currentAssetClass;
            renderSuggest([]);
            assetIdInput.focus();
            addByAssetId(id, ac);
        });

        renderSelectedList();
    </script>
</body>
</html>
