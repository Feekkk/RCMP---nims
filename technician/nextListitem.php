<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/nextcheck_shared.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pipeline_revert'])) {
    header('Content-Type: application/json; charset=utf-8');
    $class = strtolower(trim((string)($_POST['asset_class'] ?? '')));
    $idRaw = trim((string)($_POST['asset_id'] ?? ''));
    if (!in_array($class, ['laptop', 'network', 'av'], true) || $idRaw === '' || !ctype_digit($idRaw)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $aid = (int)$idRaw;
    try {
        $pdo = db();
        nextcheck_pipeline_revert_one($pdo, $class, $aid);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$pipeline_assets = [];
try {
    $pipeline_assets = nextcheck_fetch_pipeline_assets(db());
} catch (Throwable $e) {
    $pipeline_assets = [];
}

$pipeline_by_class = ['laptop' => 0, 'av' => 0, 'network' => 0];
foreach ($pipeline_assets as $pa) {
    $c = (string)($pa['asset_class'] ?? '');
    if (isset($pipeline_by_class[$c])) {
        $pipeline_by_class[$c]++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>List items — NextCheck — RCMP NIMS</title>
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
            flex-wrap:wrap;
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
        .title p{color:var(--text-muted);margin-top:0.35rem;max-width:52rem;line-height:1.45}
        .layout{display:grid;grid-template-columns:1fr;gap:1.25rem;align-items:start}
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
            text-decoration:none;
            font-family:inherit;
            font-size:0.88rem;
        }
        .btn-ghost{
            background:transparent;
            border:1px solid var(--card-border);
            color:var(--text-muted);
        }
        .btn-ghost:hover{color:var(--secondary);border-color:rgba(14,165,233,0.25);background:rgba(14,165,233,0.04)}
        .btn-danger{
            background:rgba(239,68,68,0.10);
            color:var(--danger);
            border:1px solid rgba(239,68,68,0.25);
        }
        .btn-danger:hover{background:rgba(239,68,68,0.14)}
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
        .search-box{
            flex:1;
            min-width:220px;
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
            font-family:inherit;
            font-size:0.9rem;
        }
        .input:focus{
            background:#fff;
            border-color:rgba(14,165,233,0.35);
            box-shadow:0 0 0 4px rgba(14,165,233,0.10);
        }
        .pipeline-toolbar{
            display:flex;
            gap:0.75rem;
            flex-wrap:wrap;
            align-items:flex-start;
            margin-bottom:0.85rem;
        }
        .pipeline-filter-dropdown{position:relative;flex-shrink:0}
        .pipeline-filter-panel{
            position:absolute;
            top:calc(100% + 0.35rem);
            right:0;
            z-index:25;
            background:var(--card-bg);
            border:1px solid var(--card-border);
            border-radius:14px;
            padding:0.9rem 1rem;
            box-shadow:0 10px 28px rgba(15,23,42,0.12);
            min-width:240px;
            display:flex;
            flex-direction:column;
            gap:0.75rem;
        }
        .pipeline-filter-panel[hidden]{display:none!important}
        .pipeline-filter-field label{
            display:block;
            font-size:0.72rem;
            font-weight:800;
            letter-spacing:0.04em;
            text-transform:uppercase;
            color:var(--text-muted);
            margin-bottom:0.35rem;
        }
        .pipeline-select{
            padding:0.55rem 0.75rem;
            font-size:0.88rem;
            font-weight:600;
            width:100%;
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
        .pill.nextcheck{background:rgba(14,165,233,0.12);color:#0369a1;border-color:rgba(14,165,233,0.28)}
        .pill.type-laptop{background:rgba(37,99,235,0.10);color:var(--primary);border-color:rgba(37,99,235,0.22)}
        .pill.type-av{background:rgba(139,92,246,0.12);color:#6d28d9;border-color:rgba(139,92,246,0.28)}
        .pill.type-network{background:rgba(5,150,105,0.12);color:#047857;border-color:rgba(5,150,105,0.25)}
        .muted{color:var(--text-muted)}
        @media (max-width: 1100px){ .main-content{padding:1.25rem} }
        @media (max-width: 720px){ .stat-cards{grid-template-columns:1fr} }
        @media (max-width: 900px){ .main-content{margin-left:0;max-width:100vw} }
    </style>
</head>
<body>
<?php include __DIR__ . '/../components/sidebarUser.php'; ?>

<main class="main-content">
    <header class="page-header">
        <div class="title">
            <h1><i class="ri-list-check-2"></i> NextCheck pipeline</h1>
            <p>Assets in status <strong>(Active / Pending / Checkout)</strong>. Counts update when you filter the table.</p>
        </div>
        <a class="btn btn-ghost" href="nextAdd.php"><i class="ri-add-line"></i> Add items</a>
    </header>

    <div class="layout">
        <p class="muted" style="font-size:0.78rem;margin:0 0 0.35rem;line-height:1.35">
            Totals by type reflect <strong>visible rows</strong> after search and filters.
        </p>
        <div class="stat-cards" aria-label="NextCheck pipeline counts by asset type">
            <div class="stat-card stat-card--laptop">
                <div class="stat-card-icon" aria-hidden="true"><i class="ri-macbook-line"></i></div>
                <div class="stat-card-text">
                    <span class="stat-card-value" id="countLaptop"><?= (int)$pipeline_by_class['laptop'] ?></span>
                    <span class="stat-card-label">Laptop</span>
                </div>
            </div>
            <div class="stat-card stat-card--av">
                <div class="stat-card-icon" aria-hidden="true"><i class="ri-film-line"></i></div>
                <div class="stat-card-text">
                    <span class="stat-card-value" id="countAv"><?= (int)$pipeline_by_class['av'] ?></span>
                    <span class="stat-card-label">AV</span>
                </div>
            </div>
            <div class="stat-card stat-card--network">
                <div class="stat-card-icon" aria-hidden="true"><i class="ri-router-line"></i></div>
                <div class="stat-card-text">
                    <span class="stat-card-value" id="countNetwork"><?= (int)$pipeline_by_class['network'] ?></span>
                    <span class="stat-card-label">Network</span>
                </div>
            </div>
        </div>

        <section class="card" aria-labelledby="pipeline-title">
            <div class="card-hd">
                <h2 id="pipeline-title"><i class="ri-git-branch-line"></i> All pipeline rows</h2>
                <div class="muted" style="font-weight:700;font-size:0.82rem;text-align:right">
                    <?= count($pipeline_assets) ?> in DB
                </div>
            </div>
            <div class="card-bd">
                <div class="pipeline-toolbar">
                    <div class="search-box">
                        <i class="ri-search-line"></i>
                        <input type="search" id="pipelineSearchInput" class="input" placeholder="Search asset ID, serial, device, status…" autocomplete="off" aria-label="Search pipeline table">
                    </div>
                    <div class="pipeline-filter-dropdown">
                        <button type="button" class="btn btn-ghost" id="pipelineFilterBtn" aria-expanded="false" aria-controls="pipelineFilterPanel" aria-haspopup="dialog" style="white-space:nowrap">
                            <i class="ri-filter-3-line"></i> Filter
                        </button>
                        <div class="pipeline-filter-panel" id="pipelineFilterPanel" role="dialog" aria-label="Pipeline filters" hidden>
                            <div class="pipeline-filter-field">
                                <label for="pipelineStatusSelect">Status</label>
                                <select id="pipelineStatusSelect" class="input pipeline-select">
                                    <option value="all">All</option>
                                    <option value="11">Active</option>
                                    <option value="12">Pending</option>
                                    <option value="13">Checkout</option>
                                </select>
                            </div>
                            <div class="pipeline-filter-field">
                                <label for="pipelineTypeSelect">Type</label>
                                <select id="pipelineTypeSelect" class="input pipeline-select">
                                    <option value="all">All</option>
                                    <option value="laptop">Laptop</option>
                                    <option value="av">AV</option>
                                    <option value="network">Network</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="pipelineEmpty" class="muted" style="padding:0.35rem 0 0.65rem;<?= count($pipeline_assets) ? 'display:none' : '' ?>">
                    No assets recorded right now.
                </div>
                <div id="pipelineEmptyFilter" class="muted" style="display:none;padding:0.35rem 0 0.65rem">
                    Nothing matches the current filters.
                </div>
                <div class="table-wrap" id="pipelineTableWrap" style="<?= count($pipeline_assets) ? '' : 'display:none;' ?>margin-top:0.35rem">
                    <table class="checkout-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Asset ID</th>
                                <th>Device</th>
                                <th>Serial</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="pipelineTableBody">
                            <?php foreach ($pipeline_assets as $pa):
                                $pcls = $pa['asset_class'];
                                $ptl = $pcls === 'network' ? 'Network' : ($pcls === 'av' ? 'AV' : 'Laptop');
                                $ptp = $pcls === 'network' ? 'type-network' : ($pcls === 'av' ? 'type-av' : 'type-laptop');
                                $ptitle = trim(($pa['brand'] ?? '') . ' ' . ($pa['model'] ?? '')) ?: $ptl;
                                $sid = (int)$pa['status_id'];
                            ?>
                            <tr data-pl-row="1" data-status-id="<?= $sid ?>" data-asset-type="<?= htmlspecialchars($pcls) ?>" data-asset-id="<?= (int)$pa['asset_id'] ?>">
                                <td><span class="pill <?= htmlspecialchars($ptp) ?>"><?= htmlspecialchars($ptl) ?></span></td>
                                <td><span class="cell-main"><?= (int)$pa['asset_id'] ?></span></td>
                                <td><div class="cell-main"><?= htmlspecialchars($ptitle) ?></div></td>
                                <td><?= htmlspecialchars($pa['serial'] !== '' ? $pa['serial'] : '—') ?></td>
                                <td><span class="pill nextcheck"><?= htmlspecialchars($pa['status'] !== '' ? $pa['status'] : '—') ?></span></td>
                                <td>
                                    <?php if ($sid === CHECKOUT_CONFIRM_TARGET_STATUS_ID): ?>
                                    <button type="button" class="btn btn-danger pipeline-remove-btn" style="padding:0.35rem 0.65rem;font-size:0.78rem;font-weight:600" data-pipeline-remove="1" data-asset-class="<?= htmlspecialchars($pcls) ?>" data-asset-id="<?= (int)$pa['asset_id'] ?>">Remove</button>
                                    <?php else: ?>
                                    <span class="muted" title="Only pool (Active) assets can be removed from the pipeline">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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

const countLaptop = document.getElementById('countLaptop');
const countAv = document.getElementById('countAv');
const countNetwork = document.getElementById('countNetwork');
const pipelineSearchInput = document.getElementById('pipelineSearchInput');
const pipelineStatusSelect = document.getElementById('pipelineStatusSelect');
const pipelineTypeSelect = document.getElementById('pipelineTypeSelect');
const pipelineFilterBtn = document.getElementById('pipelineFilterBtn');
const pipelineFilterPanel = document.getElementById('pipelineFilterPanel');
const pipelineFilterDropdown = document.querySelector('.pipeline-filter-dropdown');

function updatePipelineStatCards() {
    const rows = document.querySelectorAll('#pipelineTableBody tr[data-pl-row]');
    let nL = 0, nA = 0, nN = 0;
    rows.forEach((tr) => {
        if (tr.style.display === 'none') return;
        const t = tr.getAttribute('data-asset-type') || 'laptop';
        if (t === 'network') nN++;
        else if (t === 'av') nA++;
        else nL++;
    });
    if (countLaptop) countLaptop.textContent = String(nL);
    if (countAv) countAv.textContent = String(nA);
    if (countNetwork) countNetwork.textContent = String(nN);
}

function applyPipelineFilters() {
    const tbody = document.getElementById('pipelineTableBody');
    if (!tbody) return;
    const plStatusFilter = pipelineStatusSelect?.value || 'all';
    const plTypeFilter = pipelineTypeSelect?.value || 'all';
    const q = String(pipelineSearchInput?.value || '').trim().toLowerCase();
    const rows = tbody.querySelectorAll('tr[data-pl-row]');
    const total = rows.length;
    let visible = 0;
    rows.forEach((tr) => {
        const sid = String(tr.getAttribute('data-status-id') || '');
        const typ = tr.getAttribute('data-asset-type') || 'laptop';
        const okS = plStatusFilter === 'all' || sid === plStatusFilter;
        const okT = plTypeFilter === 'all' || typ === plTypeFilter;
        const hay = tr.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
        const okQ = q === '' || hay.includes(q);
        const show = okS && okT && okQ;
        tr.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const empty = document.getElementById('pipelineEmpty');
    const emptyF = document.getElementById('pipelineEmptyFilter');
    const wrap = document.getElementById('pipelineTableWrap');
    if (total === 0) {
        if (empty) empty.style.display = '';
        if (emptyF) emptyF.style.display = 'none';
        if (wrap) wrap.style.display = 'none';
        return;
    }
    if (empty) empty.style.display = 'none';
    if (visible === 0) {
        if (emptyF) emptyF.style.display = '';
        if (wrap) wrap.style.display = 'none';
    } else {
        if (emptyF) emptyF.style.display = 'none';
        if (wrap) wrap.style.display = '';
    }
    updatePipelineStatCards();
}

pipelineStatusSelect?.addEventListener('change', () => applyPipelineFilters());
pipelineTypeSelect?.addEventListener('change', () => applyPipelineFilters());
pipelineSearchInput?.addEventListener('input', () => applyPipelineFilters());

pipelineFilterBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    if (!pipelineFilterPanel) return;
    const open = pipelineFilterPanel.hidden;
    pipelineFilterPanel.hidden = !open;
    pipelineFilterBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
});
pipelineFilterDropdown?.addEventListener('click', (e) => e.stopPropagation());
document.addEventListener('click', () => {
    if (pipelineFilterPanel && !pipelineFilterPanel.hidden) {
        pipelineFilterPanel.hidden = true;
        pipelineFilterBtn?.setAttribute('aria-expanded', 'false');
    }
});

applyPipelineFilters();

document.getElementById('pipelineTableBody')?.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-pipeline-remove]');
    if (!btn) return;
    const ac = btn.getAttribute('data-asset-class') || '';
    const aid = btn.getAttribute('data-asset-id') || '';
    const msg = ac === 'network'
        ? 'Remove this asset from the NextCheck pipeline and set network status back to 9?'
        : 'Remove this asset from the NextCheck pipeline and set status back to 1 (laptop / AV)?';
    if (!window.confirm(msg)) return;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.set('pipeline_revert', '1');
        fd.set('asset_class', ac);
        fd.set('asset_id', aid);
        const res = await fetch('nextListitem.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        });
        const data = await res.json();
        if (!data?.ok) {
            alert(data?.error || 'Could not update asset.');
            btn.disabled = false;
            return;
        }
        btn.closest('tr')?.remove();
        applyPipelineFilters();
    } catch (err) {
        alert('Request failed.');
        btn.disabled = false;
    }
});
</script>
</body>
</html>
