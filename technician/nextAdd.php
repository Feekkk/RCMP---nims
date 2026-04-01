<?php
session_start();
if (!isset($_SESSION['staff_id']) || (int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

/** @return 'laptop'|'av'|'network' */
function nextadd_asset_class_strict(): string
{
    $c = strtolower(trim((string)($_GET['asset_class'] ?? $_GET['type'] ?? 'laptop')));
    return in_array($c, ['laptop', 'av', 'network'], true) ? $c : 'laptop';
}

/** @return 'laptop'|'av'|'network'|'all' */
function nextadd_suggest_asset_class(): string
{
    $c = strtolower(trim((string)($_GET['asset_class'] ?? $_GET['type'] ?? 'all')));
    if ($c === 'all') {
        return 'all';
    }
    return in_array($c, ['laptop', 'av', 'network'], true) ? $c : 'all';
}

if (isset($_GET['lookup_asset_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim((string)$_GET['lookup_asset_id']);
    $class = nextadd_asset_class_strict();
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
    $class = nextadd_suggest_asset_class();
    if (!preg_match('/^\d{1,20}$/', $suggest_q)) {
        echo json_encode(['ok' => true, 'items' => []]);
        exit;
    }
    try {
        $pdo = db();
        $items = [];
        if ($class === 'all') {
            foreach (['laptop', 'network', 'av'] as $cls) {
                if ($cls === 'laptop') {
                    $stmt = $pdo->prepare("
                        SELECT l.asset_id, l.serial_num, l.brand, l.model, s.name AS status_name
                        FROM laptop l
                        JOIN status s ON s.status_id = l.status_id
                        WHERE CAST(l.asset_id AS CHAR) LIKE CONCAT(?, '%')
                        ORDER BY l.asset_id DESC
                        LIMIT 8
                    ");
                } elseif ($cls === 'network') {
                    $stmt = $pdo->prepare("
                        SELECT n.asset_id, n.serial_num, n.brand, n.model, s.name AS status_name
                        FROM network n
                        JOIN status s ON s.status_id = n.status_id
                        WHERE CAST(n.asset_id AS CHAR) LIKE CONCAT(?, '%')
                        ORDER BY n.asset_id DESC
                        LIMIT 8
                    ");
                } else {
                    $stmt = $pdo->prepare("
                        SELECT a.asset_id, a.serial_num, a.brand, a.model, s.name AS status_name
                        FROM av a
                        JOIN status s ON s.status_id = a.status_id
                        WHERE CAST(a.asset_id AS CHAR) LIKE CONCAT(?, '%')
                        ORDER BY a.asset_id DESC
                        LIMIT 8
                    ");
                }
                try {
                    $stmt->execute([$suggest_q]);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $items[] = [
                            'asset_class' => $cls,
                            'asset_id' => (int)$r['asset_id'],
                            'serial' => (string)($r['serial_num'] ?? ''),
                            'brand' => (string)($r['brand'] ?? ''),
                            'model' => (string)($r['model'] ?? ''),
                            'status' => (string)($r['status_name'] ?? '—'),
                        ];
                    }
                } catch (Throwable $e) {
                    if ($cls !== 'av') {
                        throw $e;
                    }
                }
            }
            usort($items, static fn ($a, $b) => $b['asset_id'] <=> $a['asset_id']);
            $items = array_slice($items, 0, 8);
        } elseif ($class === 'network') {
            $stmt = $pdo->prepare("
                SELECT n.asset_id, n.serial_num, n.brand, n.model, s.name AS status_name
                FROM network n
                JOIN status s ON s.status_id = n.status_id
                WHERE CAST(n.asset_id AS CHAR) LIKE CONCAT(?, '%')
                ORDER BY n.asset_id DESC
                LIMIT 8
            ");
            $stmt->execute([$suggest_q]);
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
        } elseif ($class === 'av') {
            $stmt = $pdo->prepare("
                SELECT a.asset_id, a.serial_num, a.brand, a.model, s.name AS status_name
                FROM av a
                JOIN status s ON s.status_id = a.status_id
                WHERE CAST(a.asset_id AS CHAR) LIKE CONCAT(?, '%')
                ORDER BY a.asset_id DESC
                LIMIT 8
            ");
            $stmt->execute([$suggest_q]);
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
        } else {
            $stmt = $pdo->prepare("
                SELECT l.asset_id, l.serial_num, l.brand, l.model, s.name AS status_name
                FROM laptop l
                JOIN status s ON s.status_id = l.status_id
                WHERE CAST(l.asset_id AS CHAR) LIKE CONCAT(?, '%')
                ORDER BY l.asset_id DESC
                LIMIT 8
            ");
            $stmt->execute([$suggest_q]);
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

const CHECKOUT_PIPELINE_STATUS_IDS = [11, 12, 13];
/** Active (nextcheck) — applied when technician confirms checkout */
const CHECKOUT_CONFIRM_TARGET_STATUS_ID = 11;
const CHECKOUT_MAX_SELECTION = 200;
/** Restored status when removed from pipeline (laptop / AV) */
const PIPELINE_REVERT_STATUS_LAPTOP_AV = 1;
/** Restored status when removed from pipeline (network) */
const PIPELINE_REVERT_STATUS_NETWORK = 9;

function checkout_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->execute([$table]);
    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}

/** Laptop / network / av rows whose status is Active, Pending, or Checkout (nextcheck) */
function checkout_fetch_pipeline_assets(PDO $pdo): array
{
    $ids = array_map('intval', CHECKOUT_PIPELINE_STATUS_IDS);
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $out = [];

    foreach (['laptop' => 'laptop', 'network' => 'network'] as $tbl => $cls) {
        $stmt = $pdo->prepare("
            SELECT t.asset_id, t.serial_num, t.brand, t.model, t.status_id, s.name AS status_name
            FROM `{$tbl}` t
            JOIN status s ON s.status_id = t.status_id
            WHERE t.status_id IN ($ph)
            ORDER BY t.asset_id DESC
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'asset_class' => $cls,
                'asset_id' => (int)$r['asset_id'],
                'serial' => (string)($r['serial_num'] ?? ''),
                'brand' => (string)($r['brand'] ?? ''),
                'model' => (string)($r['model'] ?? ''),
                'status_id' => (int)$r['status_id'],
                'status' => (string)($r['status_name'] ?? ''),
            ];
        }
    }

    if (checkout_table_exists($pdo, 'av')) {
        try {
            $stmt = $pdo->prepare("
                SELECT a.asset_id, a.serial_num, a.brand, a.model, a.status_id, s.name AS status_name
                FROM av a
                JOIN status s ON s.status_id = a.status_id
                WHERE a.status_id IN ($ph)
                ORDER BY a.asset_id DESC
            ");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = [
                    'asset_class' => 'av',
                    'asset_id' => (int)$r['asset_id'],
                    'serial' => (string)($r['serial_num'] ?? ''),
                    'brand' => (string)($r['brand'] ?? ''),
                    'model' => (string)($r['model'] ?? ''),
                    'status_id' => (int)$r['status_id'],
                    'status' => (string)($r['status_name'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
        }
    }

    usort($out, static fn ($a, $b) => $b['asset_id'] <=> $a['asset_id']);
    return $out;
}

/** @param int[] $ids */
function checkout_lock_and_update(PDO $pdo, string $table, array $ids, int $statusId): void
{
    if ($ids === []) {
        return;
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT asset_id FROM `{$table}` WHERE asset_id IN ($placeholders) FOR UPDATE");
    $stmt->execute($ids);
    $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    sort($ids);
    sort($found);
    if ($found !== $ids) {
        $missing = array_values(array_diff($ids, $found));
        throw new RuntimeException('Missing ' . $table . ' asset(s): ' . implode(', ', $missing));
    }
    $stmtU = $pdo->prepare("UPDATE `{$table}` SET status_id = ? WHERE asset_id IN ($placeholders)");
    $stmtU->execute(array_merge([(int)$statusId], $ids));
}

/** Revert one asset out of NextCheck pipeline (statuses 11–13 only). */
function pipeline_revert_one(PDO $pdo, string $class, int $assetId): void
{
    $class = strtolower(trim($class));
    if (!in_array($class, ['laptop', 'network', 'av'], true)) {
        throw new InvalidArgumentException('Invalid asset type');
    }
    if ($class === 'av' && !checkout_table_exists($pdo, 'av')) {
        throw new RuntimeException('AV inventory table is not available.');
    }
    $table = $class === 'network' ? 'network' : ($class === 'av' ? 'av' : 'laptop');
    $target = $class === 'network' ? PIPELINE_REVERT_STATUS_NETWORK : PIPELINE_REVERT_STATUS_LAPTOP_AV;
    $allowed = array_values(array_unique(array_map('intval', CHECKOUT_PIPELINE_STATUS_IDS)));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT status_id FROM `{$table}` WHERE asset_id = ? FOR UPDATE");
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Asset not found');
        }
        $sid = (int)$row['status_id'];
        if (!in_array($sid, $allowed, true)) {
            throw new RuntimeException('Asset is not in the NextCheck pipeline');
        }
        $stmtU = $pdo->prepare("UPDATE `{$table}` SET status_id = ? WHERE asset_id = ?");
        $stmtU->execute([(int)$target, $assetId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

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
        pipeline_revert_one($pdo, $class, $aid);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$checkout_error = '';
$success_checkout = isset($_GET['checkout_ok']) && (string)$_GET['checkout_ok'] === '1';
$initial_checkout_items = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_checkout'])) {
    $raw = (string)($_POST['asset_ids'] ?? '[]');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === []) {
        $checkout_error = 'No items in the list.';
    } elseif (count($decoded) > CHECKOUT_MAX_SELECTION) {
        $checkout_error = 'Too many items (max ' . CHECKOUT_MAX_SELECTION . ').';
    } else {
        $buckets = ['laptop' => [], 'network' => [], 'av' => []];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cls = strtolower(trim((string)($row['asset_class'] ?? 'laptop')));
            if (!in_array($cls, ['laptop', 'network', 'av'], true)) {
                $checkout_error = 'Invalid asset type in list.';
                break;
            }
            $aid = $row['asset_id'] ?? '';
            $aid = is_int($aid) ? (string)$aid : trim((string)$aid);
            if ($aid === '' || !ctype_digit($aid)) {
                $checkout_error = 'Invalid asset id in list.';
                break;
            }
            $buckets[$cls][] = (int)$aid;
        }
        foreach (array_keys($buckets) as $k) {
            $buckets[$k] = array_values(array_unique($buckets[$k]));
        }
        if ($checkout_error === '' && $buckets['av'] !== [] && !checkout_table_exists(db(), 'av')) {
            $checkout_error = 'AV inventory table is not available. Remove AV items or contact IT.';
        }
        if ($checkout_error === '') {
            $total = count($buckets['laptop']) + count($buckets['network']) + count($buckets['av']);
            if ($total === 0) {
                $checkout_error = 'No items in the list.';
            } else {
                $pdo = null;
                try {
                    $pdo = db();
                    $pdo->beginTransaction();
                    $target = CHECKOUT_CONFIRM_TARGET_STATUS_ID;
                    checkout_lock_and_update($pdo, 'laptop', $buckets['laptop'], $target);
                    checkout_lock_and_update($pdo, 'network', $buckets['network'], $target);
                    if ($buckets['av'] !== []) {
                        checkout_lock_and_update($pdo, 'av', $buckets['av'], $target);
                    }
                    $pdo->commit();
                    header('Location: nextAdd.php?checkout_ok=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo instanceof PDO && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $checkout_error = 'Could not update inventory: ' . $e->getMessage();
                }
            }
        }
    }
    if ($checkout_error !== '') {
        $tmp = json_decode((string)($_POST['asset_ids'] ?? '[]'), true);
        if (is_array($tmp)) {
            $initial_checkout_items = $tmp;
        }
    }
}

$pipeline_assets = [];
try {
    $pipeline_assets = checkout_fetch_pipeline_assets(db());
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
        .card-inset-section{
            margin-top:1.15rem;
            padding-top:1.15rem;
            border-top:1px solid var(--card-border);
        }
        .card-inset-head{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:1rem;
            flex-wrap:wrap;
            margin-bottom:0.65rem;
        }
        .card-subtitle{
            font-family:'Outfit',sans-serif;
            font-size:0.98rem;
            font-weight:800;
            display:flex;
            align-items:center;
            gap:0.45rem;
        }
        .card-subtitle i{color:var(--primary)}

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

        .pipeline-toolbar{
            display:flex;
            gap:0.75rem;
            flex-wrap:wrap;
            align-items:flex-start;
            margin-bottom:0.85rem;
        }
        .pipeline-search-box{flex:1;min-width:220px}
        .pipeline-filter-dropdown{position:relative;flex-shrink:0}
        .pipeline-filter-toggle{
            border-radius:14px;
            padding:0.85rem 1rem;
            font-weight:700;
            white-space:nowrap;
            display:inline-flex;
            align-items:center;
            gap:0.4rem;
        }
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
        @keyframes spin{to{transform:rotate(360deg)}}

        .banner{
            display:flex;
            align-items:flex-start;
            gap:10px;
            padding:0.85rem 1rem;
            border-radius:14px;
            font-size:0.9rem;
            line-height:1.45;
            margin-bottom:1rem;
            font-weight:600;
        }
        .banner>i{flex-shrink:0;margin-top:0.12rem;font-size:1.1rem}
        .banner-success{
            background:rgba(16,185,129,0.1);
            border:1px solid rgba(16,185,129,0.28);
            color:#047857;
        }
        .banner-error{
            background:rgba(239,68,68,0.08);
            border:1px solid rgba(239,68,68,0.22);
            color:#b91c1c;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../components/sidebarUser.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <div class="title">
                <h1><i class="ri-calendar-check-line"></i> Daily checkout</h1>
                <p>Search by Asset ID (laptop, network, or AV), then review the table below (filter by type).</p>
            </div>
            <button class="btn btn-ghost" type="button" onclick="history.back()">
                <i class="ri-arrow-left-line"></i> Back
            </button>
        </header>

        <?php if ($success_checkout): ?>
        <div class="banner banner-success"><i class="ri-checkbox-circle-line"></i><span>Checkout saved. Selected assets are now <strong>Active (nextcheck)</strong> (status id <?= (int)CHECKOUT_CONFIRM_TARGET_STATUS_ID ?>).</span></div>
        <?php endif; ?>
        <?php if ($checkout_error !== ''): ?>
        <div class="banner banner-error"><i class="ri-error-warning-line"></i><span><?= htmlspecialchars($checkout_error) ?></span></div>
        <?php endif; ?>

        <div class="layout">
            <section class="card">
                <div class="card-hd">
                    <h2><i class="ri-shopping-cart-2-line"></i> Add &amp; review</h2>
                    <div class="muted" style="font-weight:700;font-size:0.9rem">
                        In list: <span id="selectedCount">0</span>
                    </div>
                </div>
                <form method="post" action="" id="checkoutForm" autocomplete="off">
                <input type="hidden" name="confirm_checkout" value="1">
                <div class="card-bd">
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

                    <div class="card-inset-section">
                        <div class="card-inset-head">
                            <h3 class="card-subtitle"><i class="ri-list-check-2"></i> Today’s checkout list</h3>
                            <div class="muted" style="font-weight:700;font-size:0.88rem;text-align:right">
                                <div>Total: <span id="listCount">0</span></div>
                                <div style="font-weight:600;font-size:0.78rem;margin-top:0.15rem" id="filterSummary"></div>
                            </div>
                        </div>
                        <div class="type-filters checkout-type-filters" role="group" aria-label="Filter checkout list by type">
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
                            <button class="btn btn-primary" type="submit" id="confirmCheckoutBtn" disabled>
                                <i class="ri-shopping-bag-3-line"></i> Confirm checkout
                            </button>
                        </div>
                        <p class="footer-hint" id="checkoutFooterHint">
                            Submits the list with <code style="font-size:0.8em">Asset IDs</code> and updates each row to status id <code style="font-size:0.8em"><?= (int)CHECKOUT_CONFIRM_TARGET_STATUS_ID ?></code> in the correct inventory table.
                        </p>
                        <input type="hidden" name="asset_ids" id="asset_ids" value="<?= htmlspecialchars((string)($_POST['asset_ids'] ?? '[]')) ?>">
                    </div>
                </div>
                </form>
            </section>

            <p class="muted" style="font-size:0.78rem;margin:0 0 0.35rem;line-height:1.35">
                NextCheck assets totals by type (status <strong>11–13</strong>). Counts follow the visible rows after search and filters.
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
                    <h2 id="pipeline-title"><i class="ri-git-branch-line"></i> NextCheck pipeline</h2>
                    <div class="muted" style="font-weight:700;font-size:0.82rem;text-align:right">
                        <?= count($pipeline_assets) ?> in DB
                    </div>
                </div>
                <div class="card-bd">
                    <p class="muted" style="font-size:0.84rem;margin-bottom:0.75rem;line-height:1.45">
                        Inventory rows with status <strong>Active (nextcheck)</strong>, <strong>Pending (nextcheck)</strong>, or <strong>Checkout (nextcheck)</strong>.
                    </p>
                    <div class="pipeline-toolbar">
                        <div class="search-box pipeline-search-box">
                            <i class="ri-search-line"></i>
                            <input type="search" id="pipelineSearchInput" class="input" placeholder="Search asset ID, serial, device, status…" autocomplete="off" aria-label="Search pipeline table">
                        </div>
                        <div class="pipeline-filter-dropdown">
                            <button type="button" class="btn btn-ghost pipeline-filter-toggle" id="pipelineFilterBtn" aria-expanded="false" aria-controls="pipelineFilterPanel" aria-haspopup="dialog">
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
                                        <button type="button" class="btn btn-danger pipeline-remove-btn" style="padding:0.35rem 0.65rem;font-size:0.78rem;font-weight:600" data-pipeline-remove="1" data-asset-class="<?= htmlspecialchars($pcls) ?>" data-asset-id="<?= (int)$pa['asset_id'] ?>">Remove</button>
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
        const INITIAL_CHECKOUT_ITEMS = <?= json_encode($initial_checkout_items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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
        const filterButtons = document.querySelectorAll('.checkout-type-filters [data-filter]');
        const countLaptop = document.getElementById('countLaptop');
        const countAv = document.getElementById('countAv');
        const countNetwork = document.getElementById('countNetwork');

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

        function updatePipelineStatCards() {
            const rows = document.querySelectorAll('#pipelineTableBody tr[data-pl-row]');
            let nL = 0, nA = 0, nN = 0;
            rows.forEach((tr) => {
                if (tr.style.display === 'none') {
                    return;
                }
                const t = tr.getAttribute('data-asset-type') || 'laptop';
                if (t === 'network') {
                    nN++;
                } else if (t === 'av') {
                    nA++;
                } else {
                    nL++;
                }
            });
            if (countLaptop) countLaptop.textContent = String(nL);
            if (countAv) countAv.textContent = String(nA);
            if (countNetwork) countNetwork.textContent = String(nN);
        }

        function renderSelectedList() {
            const items = Array.from(selected.values());
            selectedCount.textContent = String(items.length);
            listCount.textContent = String(items.length);
            listCount2.textContent = String(items.length);
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

        document.getElementById('checkoutForm')?.addEventListener('submit', () => {
            renderSelectedList();
            confirmCheckoutBtn.innerHTML = '<i class="ri-loader-4-line" style="animation:spin 0.8s linear infinite"></i> Saving…';
        });

        async function addByAssetId(assetId, classOverride) {
            const id = String(assetId || '').trim();
            if (!id) return;
            if (!ctypeDigit(id)) {
                alert('Please enter a valid numeric Asset ID.');
                return;
            }
            const tryClasses = classOverride ? [classOverride] : ['laptop', 'network', 'av'];
            const quietLookup = tryClasses.length > 1;
            let data = null;
            let resolvedCls = 'laptop';
            for (const cls of tryClasses) {
                const d = await lookupAsset(id, cls, quietLookup);
                if (!d) continue;
                resolvedCls = d.asset_class || cls;
                const key = rowKey(resolvedCls, id);
                if (selected.has(key)) {
                    assetIdInput.value = '';
                    renderSuggest([]);
                    return;
                }
                data = d;
                break;
            }
            if (!data) {
                alert('Asset not found in laptop, network, or AV inventory.');
                return;
            }
            const ac = data.asset_class || resolvedCls;
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

        async function lookupAsset(id, assetClass, quiet) {
            const ac = assetClass || 'laptop';
            try {
                const res = await fetch(`nextAdd.php?lookup_asset_id=${encodeURIComponent(id)}&asset_class=${encodeURIComponent(ac)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store'
                });
                const data = await res.json();
                if (!data?.ok) {
                    if (!quiet) alert(data?.error || 'Asset not found.');
                    return null;
                }
                return data.asset || null;
            } catch (e) {
                if (!quiet) alert('Lookup failed.');
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
                const ac = it.asset_class || 'laptop';
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
                const res = await fetch(`nextAdd.php?suggest_q=${encodeURIComponent(t)}&asset_class=all`, {
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
            const ac = row.getAttribute('data-asset-class') || 'laptop';
            renderSuggest([]);
            assetIdInput.focus();
            addByAssetId(id, ac);
        });

        const pipelineSearchInput = document.getElementById('pipelineSearchInput');
        const pipelineStatusSelect = document.getElementById('pipelineStatusSelect');
        const pipelineTypeSelect = document.getElementById('pipelineTypeSelect');
        const pipelineFilterBtn = document.getElementById('pipelineFilterBtn');
        const pipelineFilterPanel = document.getElementById('pipelineFilterPanel');
        const pipelineFilterDropdown = document.querySelector('.pipeline-filter-dropdown');

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

        const plTableBody = document.getElementById('pipelineTableBody');
        plTableBody?.addEventListener('click', async (e) => {
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
                const res = await fetch('nextAdd.php', {
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

        (async function bootstrapCheckoutList() {
            if (!Array.isArray(INITIAL_CHECKOUT_ITEMS) || INITIAL_CHECKOUT_ITEMS.length === 0) {
                renderSelectedList();
                return;
            }
            for (const row of INITIAL_CHECKOUT_ITEMS) {
                const id = String(row.asset_id ?? '').trim();
                const cls = row.asset_class || 'laptop';
                if (!ctypeDigit(id)) continue;
                const data = await lookupAsset(id, cls, true);
                if (!data) continue;
                const ac = data.asset_class || cls;
                selected.set(rowKey(ac, id), {
                    asset_id: id,
                    asset_class: ac,
                    serial: data.serial || '—',
                    brand: data.brand || '',
                    model: data.model || '',
                    status: data.status || '—',
                });
            }
            renderSelectedList();
        })();
    </script>
</body>
</html>
