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
