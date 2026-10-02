<?php
declare(strict_types=1);

if (defined('SIDEBAR_HELPER_INCLUDED')) return;
define('SIDEBAR_HELPER_INCLUDED', true);

if (!function_exists('sidebar_zone_get_all')) {
    function sidebar_zone_get_all(PDO $pdo): array {
        static $cache = null;
        if ($cache === null) {
            try {
                $st = $pdo->query("SELECT * FROM sidebar_zones ORDER BY is_primary DESC, name ASC");
                $cache = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $cache = [];
            }
        }
        return $cache;
    }
}

if (!function_exists('sidebar_zone_get_primary')) {
    function sidebar_zone_get_primary(PDO $pdo): ?array {
        $all = sidebar_zone_get_all($pdo);
        foreach ($all as $z) {
            if (!empty($z['is_primary'])) return $z;
        }
        return $all[0] ?? null;
    }
}

if (!function_exists('sidebar_zone_get_by_slug')) {
    function sidebar_zone_get_by_slug(PDO $pdo, string $slug): ?array {
        $all = sidebar_zone_get_all($pdo);
        foreach ($all as $z) {
            if ($z['slug'] === $slug) return $z;
        }
        return null;
    }
}

if (!function_exists('sidebar_zone_get_by_id')) {
    function sidebar_zone_get_by_id(PDO $pdo, int $id): ?array {
        $all = sidebar_zone_get_all($pdo);
        foreach ($all as $z) {
            if ((int)$z['id'] === $id) return $z;
        }
        return null;
    }
}

if (!function_exists('sidebar_zone_invalidate_cache')) {
    function sidebar_zone_invalidate_cache(): void {}
}

if (!function_exists('sidebar_zone_get_items')) {
    function sidebar_zone_get_items(PDO $pdo, int $zoneId): array {
        static $cache = [];
        if (!isset($cache[$zoneId])) {
            try {
                $st = $pdo->prepare("SELECT * FROM sidebar_zone_items WHERE zone_id = :zid ORDER BY ordering ASC, id ASC");
                $st->execute([':zid' => $zoneId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as &$r) {
                    if ($r['config'] !== null && $r['config'] !== '') {
                        $decoded = json_decode($r['config'], true);
                        $r['config'] = is_array($decoded) ? $decoded : [];
                    } else {
                        $r['config'] = [];
                    }
                }
                $cache[$zoneId] = $rows;
            } catch (Throwable $e) {
                $cache[$zoneId] = [];
            }
        }
        return apply_filters('sidebar_zone_items', $cache[$zoneId], $zoneId, $pdo);
    }
}

if (!function_exists('sidebar_zone_has_items')) {
    function sidebar_zone_has_items(PDO $pdo, ?int $zoneId = null): bool {
        if ($zoneId === null) {
            $primary = sidebar_zone_get_primary($pdo);
            if (!$primary) return false;
            $zoneId = (int)$primary['id'];
        }
        $items = sidebar_zone_get_items($pdo, $zoneId);
        return !empty($items);
    }
}

function sidebar_lifecycle_capture_items(
    ResourceLifecycleDatabase $database,
    string $operation,
    array $lockedItems,
    array $context
): array {
    if ($operation !== 'delete') throw new InvalidArgumentException('Unsupported sidebar lifecycle operation.');
    $items = [];
    foreach ($lockedItems as $lockedItem) {
        $row = is_array($lockedItem['row'] ?? null) ? $lockedItem['row'] : null;
        if ($row === null || (int)($row['id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Invalid locked sidebar lifecycle item.');
        }
        $items[] = [
            'id' => (int)$row['id'],
            'before' => $row,
            'after' => null,
            'artifacts' => [],
        ];
    }
    return $items;
}

foreach (['sidebar_item', 'sidebar_zone'] as $sidebarLifecycleResource) {
    if (!isset($GLOBALS['_hooks']['lifecycle_resources'][$sidebarLifecycleResource])) {
        register_resource_lifecycle_provider($sidebarLifecycleResource, [
            'owner' => 'core',
            'capture' => 'sidebar_lifecycle_capture_items',
        ]);
    }
    $sidebarLifecycleProvider = $GLOBALS['_hooks']['lifecycle_resources'][$sidebarLifecycleResource] ?? null;
    if (!is_array($sidebarLifecycleProvider)
        || ($sidebarLifecycleProvider['owner'] ?? null) !== 'core'
        || ($sidebarLifecycleProvider['capture'] ?? null) !== 'sidebar_lifecycle_capture_items') {
        throw new LogicException('Unable to register Core sidebar lifecycle provider.');
    }
}
unset($sidebarLifecycleResource, $sidebarLifecycleProvider);

function sidebar_lifecycle_for_update(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

function sidebar_lifecycle_notify_committed(PDO $pdo, array $event): void
{
    foreach (resource_lifecycle_after_commit($pdo, $event) as $error) {
        error_log('[sidebar-lifecycle] Committed observer failed for ' . $event['resource'] . '.' . $event['operation']
            . ': ' . ($error['message'] ?? 'Unknown listener error'));
    }
}

function sidebar_delete_items_in_transaction(
    PDO $pdo,
    int $zoneId,
    array $itemIds,
    int $actorId,
    string $reason = 'item_delete'
): ?array {
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn(int $id): bool => $id > 0)));
    sort($itemIds, SORT_NUMERIC);
    if ($zoneId <= 0 || $itemIds === [] || count($itemIds) > 1000
        || !in_array($reason, ['item_delete', 'save_omitted'], true)) {
        throw new InvalidArgumentException('Invalid sidebar item deletion request.');
    }
    if (!$pdo->inTransaction()) throw new LogicException('Sidebar item deletion requires a caller-owned transaction.');

    $suffix = sidebar_lifecycle_for_update($pdo);
    $zoneStmt = $pdo->prepare('SELECT * FROM sidebar_zones WHERE id = ?' . $suffix);
    $zoneStmt->execute([$zoneId]);
    if (!$zoneStmt->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('Sidebar zone not found.');

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $itemStmt = $pdo->prepare("SELECT * FROM sidebar_zone_items WHERE zone_id = ? AND id IN ({$placeholders}) ORDER BY id" . $suffix);
    $itemStmt->execute(array_merge([$zoneId], $itemIds));
    $rows = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) return null;

    $event = resource_lifecycle_capture($pdo, 'sidebar_item', 'delete', array_map(
        static fn(array $row): array => ['row' => $row],
        $rows
    ), [
        'actor_id' => $actorId,
        'source' => 'core.sidebar_manager',
        'metadata' => ['zone_id' => $zoneId, 'reason' => $reason],
    ]);
    $lockedIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $deletePlaceholders = implode(',', array_fill(0, count($lockedIds), '?'));
    $delete = $pdo->prepare("DELETE FROM sidebar_zone_items WHERE zone_id = ? AND id IN ({$deletePlaceholders})");
    $delete->execute(array_merge([$zoneId], $lockedIds));
    if ($delete->rowCount() !== count($lockedIds)) throw new RuntimeException('Sidebar item state changed during deletion.');
    return resource_lifecycle_before_commit($pdo, $event, ['result' => ['affected' => count($lockedIds)]]);
}

function sidebar_delete_items(
    PDO $pdo,
    int $zoneId,
    array $itemIds,
    int $actorId,
    string $reason = 'item_delete'
): int {
    if ($pdo->inTransaction()) throw new LogicException('Sidebar item deletion owns its transaction.');
    $event = null;
    try {
        $pdo->beginTransaction();
        $event = sidebar_delete_items_in_transaction($pdo, $zoneId, $itemIds, $actorId, $reason);
        if (!$pdo->commit()) throw new RuntimeException('Unable to commit sidebar item deletion.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if ($event === null) return 0;
    sidebar_lifecycle_notify_committed($pdo, $event);
    return count($event['items']);
}

function sidebar_delete_zone(PDO $pdo, int $zoneId, int $actorId): array
{
    if ($zoneId <= 0) throw new InvalidArgumentException('Invalid sidebar zone deletion request.');
    if ($pdo->inTransaction()) throw new LogicException('Sidebar zone deletion owns its transaction.');
    $events = [];
    try {
        $pdo->beginTransaction();
        $suffix = sidebar_lifecycle_for_update($pdo);
        $zoneStmt = $pdo->prepare('SELECT * FROM sidebar_zones WHERE id = ?' . $suffix);
        $zoneStmt->execute([$zoneId]);
        $zone = $zoneStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($zone === null) throw new RuntimeException('Sidebar zone not found.');
        if (!empty($zone['is_primary'])) {
            $allZones = $pdo->query('SELECT id FROM sidebar_zones ORDER BY id' . $suffix);
            if (count($allZones->fetchAll(PDO::FETCH_COLUMN)) > 1) {
                throw new RuntimeException('Cannot delete the primary zone. Set another zone as primary first.');
            }
        }

        $itemsStmt = $pdo->prepare('SELECT * FROM sidebar_zone_items WHERE zone_id = ? ORDER BY id' . $suffix);
        $itemsStmt->execute([$zoneId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($items !== []) {
            $events[] = resource_lifecycle_capture($pdo, 'sidebar_item', 'delete', array_map(
                static fn(array $row): array => ['row' => $row],
                $items
            ), [
                'actor_id' => $actorId,
                'source' => 'core.sidebar_manager',
                'metadata' => ['zone_id' => $zoneId, 'reason' => 'zone_delete'],
            ]);
        }
        $zone['item_ids'] = array_map(static fn(array $row): int => (int)$row['id'], $items);
        $events[] = resource_lifecycle_capture($pdo, 'sidebar_zone', 'delete', [['row' => $zone]], [
            'actor_id' => $actorId,
            'source' => 'core.sidebar_manager',
            'metadata' => ['zone_id' => $zoneId, 'reason' => 'zone_delete'],
        ]);

        $deleteItems = $pdo->prepare('DELETE FROM sidebar_zone_items WHERE zone_id = ?');
        $deleteItems->execute([$zoneId]);
        if ($deleteItems->rowCount() !== count($items)) throw new RuntimeException('Sidebar item state changed during zone deletion.');
        $deleteZone = $pdo->prepare('DELETE FROM sidebar_zones WHERE id = ?');
        $deleteZone->execute([$zoneId]);
        if ($deleteZone->rowCount() !== 1) throw new RuntimeException('Sidebar zone state changed during deletion.');
        foreach ($events as &$event) {
            $event = resource_lifecycle_before_commit($pdo, $event, ['result' => ['affected' => count($event['items'])]]);
        }
        unset($event);
        if (!$pdo->commit()) throw new RuntimeException('Unable to commit sidebar zone deletion.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    foreach ($events as $event) sidebar_lifecycle_notify_committed($pdo, $event);
    return ['zone' => $zone, 'items' => $items];
}
