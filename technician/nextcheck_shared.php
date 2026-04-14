<?php

declare(strict_types=1);

const CHECKOUT_PIPELINE_STATUS_IDS = [11, 12, 13, 14];
const CHECKOUT_CONFIRM_TARGET_STATUS_ID = 11;
const CHECKOUT_MAX_SELECTION = 200;
const PIPELINE_REVERT_STATUS_LAPTOP_AV = 1;
const PIPELINE_REVERT_STATUS_NETWORK = 9;

function nextcheck_checkout_table_exists(PDO $pdo, string $table): bool
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

function nextcheck_fetch_pipeline_assets(PDO $pdo): array
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

    if (nextcheck_checkout_table_exists($pdo, 'av')) {
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

    usort($out, static function ($a, $b): int {
        return $b['asset_id'] <=> $a['asset_id'];
    });
    return $out;
}

/** @param int[] $ids */
function nextcheck_lock_and_update(PDO $pdo, string $table, array $ids, int $statusId): void
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

function nextcheck_pipeline_revert_one(PDO $pdo, string $class, int $assetId): void
{
    $class = strtolower(trim($class));
    if (!in_array($class, ['laptop', 'network', 'av'], true)) {
        throw new InvalidArgumentException('Invalid asset type');
    }
    if ($class === 'av' && !nextcheck_checkout_table_exists($pdo, 'av')) {
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
        if ($sid !== CHECKOUT_CONFIRM_TARGET_STATUS_ID) {
            throw new RuntimeException('Remove is only allowed for pool status (' . CHECKOUT_CONFIRM_TARGET_STATUS_ID . ')');
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
