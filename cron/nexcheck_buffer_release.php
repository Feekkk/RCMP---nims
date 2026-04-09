<?php

declare(strict_types=1);

/**
 * NextCheck: move assets from buffer (14) to pool (11) after 24h in buffer.
 *
 * Linux crontab (e.g. hourly):
 *   0 * * * * /usr/bin/php /path/to/nims/cron/nexcheck_buffer_release.php
 *
 * Windows Task Scheduler: program php.exe, argument:
 *   C:\laragon\www\nims\cron\nexcheck_buffer_release.php
 *
 * --dry-run : print counts only, no updates.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
require_once $root . '/config/database.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

const NEXCHECK_POOL_STATUS = 11;
const NEXCHECK_BUFFER_STATUS = 14;

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $s = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $s->execute([$table, $column]);
    return (bool) $s->fetchColumn();
}

function table_exists(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $s->execute([$table]);
    return (bool) $s->fetchColumn();
}

try {
    $pdo = db();
    if (!column_exists($pdo, 'laptop', 'nextcheck_buffer_since')) {
        fwrite(STDERR, "Run db/migrate_nextcheck_buffer_columns.sql first.\n");
        exit(1);
    }

    $where = 'status_id = ' . NEXCHECK_BUFFER_STATUS
        . ' AND nextcheck_buffer_since IS NOT NULL'
        . ' AND nextcheck_buffer_since <= DATE_SUB(NOW(), INTERVAL 1 DAY)';

    if ($dryRun) {
        $nL = (int) $pdo->query('SELECT COUNT(*) FROM laptop WHERE ' . $where)->fetchColumn();
        $nA = 0;
        if (table_exists($pdo, 'av') && column_exists($pdo, 'av', 'nextcheck_buffer_since')) {
            $nA = (int) $pdo->query('SELECT COUNT(*) FROM av WHERE ' . $where)->fetchColumn();
        }
        echo "Dry run: would release laptop={$nL} av={$nA}\n";
        exit(0);
    }

    $pdo->beginTransaction();
    $uL = $pdo->prepare(
        'UPDATE laptop SET status_id = :pool, nextcheck_buffer_since = NULL WHERE ' . $where
    );
    $uL->execute(['pool' => NEXCHECK_POOL_STATUS]);
    $releasedLaptop = $uL->rowCount();

    $releasedAv = 0;
    if (table_exists($pdo, 'av') && column_exists($pdo, 'av', 'nextcheck_buffer_since')) {
        $uA = $pdo->prepare(
            'UPDATE av SET status_id = :pool, nextcheck_buffer_since = NULL WHERE ' . $where
        );
        $uA->execute(['pool' => NEXCHECK_POOL_STATUS]);
        $releasedAv = $uA->rowCount();
    }

    $pdo->commit();
    echo date('c') . " Released buffer -> pool: laptop={$releasedLaptop} av={$releasedAv}\n";
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
