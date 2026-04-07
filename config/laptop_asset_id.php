<?php

declare(strict_types=1);

/**
 * Two-digit laptop asset id family prefix: 14 = Desktop AIO / Desktop IO, 12 = Notebook / Notebook Standby.
 * Matching is case-insensitive; runs of spaces are collapsed.
 */
function laptop_category_to_asset_prefix(?string $category): ?string
{
    if ($category === null || $category === '') {
        return null;
    }
    $k = strtolower(trim(preg_replace('/\s+/u', ' ', $category)));
    return match ($k) {
        'desktop aio', 'desktop io' => '14',
        'notebook', 'notebook standby' => '12',
        default => null,
    };
}

function laptop_compute_next_asset_id(PDO $pdo, string $twoCharCategoryPrefix): int
{
    $yy     = date('y');
    $prefix = $twoCharCategoryPrefix . $yy;
    $stmt   = $pdo->prepare('SELECT MAX(asset_id) FROM laptop WHERE asset_id LIKE ?');
    $stmt->execute([$prefix . '%']);
    $max_val = (int) $stmt->fetchColumn();
    if ($max_val === 0) {
        return (int) ($prefix . '001');
    }
    $plen = strlen($prefix);
    $next = (int) substr((string) $max_val, $plen) + 1;
    $pad  = max(3, strlen((string) $next));

    return (int) ($prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT));
}
