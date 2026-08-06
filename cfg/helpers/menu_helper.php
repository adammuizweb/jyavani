<?php
declare(strict_types=1);

if (!function_exists('menu_get_all')) {
    function menu_get_all(PDO $pdo): array {
        static $cache = null;
        if ($cache === null) {
            try {
                $st = $pdo->query("SELECT * FROM menus ORDER BY is_default DESC, name ASC");
                $cache = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $cache = [];
            }
        }
        return $cache;
    }
}

if (!function_exists('menu_get_default')) {
    function menu_get_default(PDO $pdo): ?array {
        $all = menu_get_all($pdo);
        foreach ($all as $m) {
            if (!empty($m['is_default'])) return $m;
        }
        return $all[0] ?? null;
    }
}

if (!function_exists('menu_get_by_slug')) {
    function menu_get_by_slug(PDO $pdo, string $slug): ?array {
        $all = menu_get_all($pdo);
        foreach ($all as $m) {
            if ($m['slug'] === $slug) return $m;
        }
        return null;
    }
}

if (!function_exists('menu_invalidate_cache')) {
    function menu_invalidate_cache(): void {
        // Force re-fetch on next call (per-request static cache)
        // Call this after create/update/delete operations
    }
}

if (!function_exists('menu_get_items')) {
    function menu_get_items(PDO $pdo, int $menuId): array {
        static $cache = [];
        if (!isset($cache[$menuId])) {
            try {
                $st = $pdo->prepare("SELECT * FROM menu_items WHERE menu_id = :mid ORDER BY sort_order ASC, id ASC");
                $st->execute([':mid' => $menuId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $cache[$menuId] = $rows;
            } catch (Throwable $e) {
                $cache[$menuId] = [];
            }
        }
        return apply_filters('menu_items', $cache[$menuId], $menuId, $pdo);
    }
}

if (!function_exists('menu_build_tree')) {
    function menu_build_tree(PDO $pdo, int $menuId): array {
        $items = menu_get_items($pdo, $menuId);
        $byId = [];
        $children = [];

        foreach ($items as $item) {
            $id = (int)$item['id'];
            $pid = $item['parent_id'] !== null ? (int)$item['parent_id'] : 0;
            $item['children'] = [];
            $byId[$id] = $item;
            $children[$pid][] = $id;
        }

        $build = function(int $parentId = 0) use (&$byId, &$children, &$build): array {
            $result = [];
            if (!empty($children[$parentId])) {
                foreach ($children[$parentId] as $cid) {
                    if (isset($byId[$cid])) {
                        $node = $byId[$cid];
                        $node['children'] = $build($cid);
                        $result[] = $node;
                    }
                }
            }
            return $result;
        };

        return $build(0);
    }
}

if (!function_exists('menu_resolve_url')) {
    function menu_resolve_url(PDO $pdo, array $item, string $homeUrl = '/'): string {
        if (!empty($item['manual_url'])) {
            return (string)$item['manual_url'];
        }
        if ($item['type'] === 'custom') {
            return !empty($item['url']) ? $item['url'] : '#';
        }

        $targetId = (int)($item['target_id'] ?? 0);
        if ($targetId <= 0) return '#';

        static $urlCache = [];

        $cacheKey = $item['type'] . ':' . $targetId;
        if (isset($urlCache[$cacheKey])) {
            return $urlCache[$cacheKey];
        }

        try {
            switch ($item['type']) {
                case 'article':
                    $st = $pdo->prepare("SELECT slug, created_at FROM posts WHERE id = :id AND type = 'article' AND is_deleted = 0 LIMIT 1");
                    $st->execute([':id' => $targetId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    $urlCache[$cacheKey] = $row ? (function_exists('get_post_permalink') ? get_post_permalink($row) : '/' . rawurlencode($row['slug']) . '/') : '#';
                    break;

                case 'page':
                    $st = $pdo->prepare("SELECT slug, created_at FROM posts WHERE id = :id AND type = 'page' AND is_deleted = 0 LIMIT 1");
                    $st->execute([':id' => $targetId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    $urlCache[$cacheKey] = $row ? (function_exists('get_page_permalink') ? get_page_permalink($row) : '/' . rawurlencode($row['slug']) . '/') : '#';
                    break;

                case 'category':
                    $st = $pdo->prepare("SELECT slug FROM categories WHERE id = :id AND is_deleted = 0 LIMIT 1");
                    $st->execute([':id' => $targetId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    $catBase = (function_exists('get_category_path') && isset($GLOBALS['pdo'])) ? (($_cp = get_category_path($GLOBALS['pdo'])) !== '' ? '/' . $_cp . '/' : '/') : '/category/';
                    $urlCache[$cacheKey] = $row ? $catBase . rawurlencode($row['slug']) . '/' : '#';
                    break;

                case 'theme':
                    $st = $pdo->prepare("SELECT slug, created_at FROM posts WHERE id = :id AND type = 'theme' AND is_deleted = 0 LIMIT 1");
                    $st->execute([':id' => $targetId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    $urlCache[$cacheKey] = $row ? (function_exists('get_post_permalink') ? get_post_permalink($row) : '/' . rawurlencode($row['slug']) . '/') : '#';
                    break;

                default:
                    $urlCache[$cacheKey] = '#';
            }
        } catch (Throwable $e) {
            $urlCache[$cacheKey] = '#';
        }

        return $urlCache[$cacheKey];
    }
}

if (!function_exists('menu_render')) {
    function menu_render(PDO $pdo, string $menuSlug = 'primary', array $options = []): string {
        try {
            $menu = menu_get_by_slug($pdo, $menuSlug);
            if (!$menu) {
                $menu = menu_get_default($pdo);
            }
            if (!$menu) return '';

            $tree = menu_build_tree($pdo, (int)$menu['id']);
            if (empty($tree)) return '';

            $homeUrl = rtrim($options['home_url'] ?? '/', '/');
            $homeUrl = $homeUrl ?: '/';
            $menuClass = $options['menu_class'] ?? 'menu';
            $submenuClass = $options['submenu_class'] ?? 'submenu';
            $depth = $options['depth'] ?? 0;
            $ulAttr = $options['ul_attr'] ?? '';

            $html = menu_render_tree($pdo, $tree, $homeUrl, $menuClass, $submenuClass, 0, $depth, $ulAttr);
            return $html;
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('menu_render_tree')) {
    function menu_render_tree(PDO $pdo, array $items, string $homeUrl, string $menuClass, string $submenuClass, int $currentDepth, int $maxDepth, string $rootAttr = ''): string {
        if (empty($items)) return '';

        $isRoot = $currentDepth === 0;
        if ($isRoot) {
            $attrHtml = $rootAttr !== '' ? ' ' . $rootAttr : '';
            $html = '<ul class="' . htmlspecialchars($menuClass, ENT_QUOTES, 'UTF-8') . '"' . $attrHtml . '>';
        } else {
            $html = '<ul class="' . htmlspecialchars($submenuClass, ENT_QUOTES, 'UTF-8') . '">';
        }

        foreach ($items as $item) {
            if (!empty($item['hidden'])) continue;
            $label = htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $url = menu_resolve_url($pdo, $item, $homeUrl);
            $target = !empty($item['target_blank']) ? ' target="_blank" rel="noopener"' : '';
            $hasChildren = !empty($item['children']) && ($maxDepth <= 0 || $currentDepth < $maxDepth);
            $liClass = 'menu-item' . ($hasChildren ? ' has-child' : '');

            if ($hasChildren) {
                $html .= '<li class="' . $liClass . '">';
                $html .= '<div class="mobile-row">';
                $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="menu-link"' . $target . '>';
                $html .= $label;
                $html .= '<svg class="arrow-icon" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                $html .= '</a>';
                $html .= '<button class="mobile-toggle-btn">';
                $html .= '<svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                $html .= '</button>';
                $html .= '</div>';
                $html .= menu_render_tree($pdo, $item['children'], $homeUrl, $menuClass, $submenuClass, $currentDepth + 1, $maxDepth, '');
                $html .= '</li>';
            } else {
                $html .= '<li class="' . $liClass . '">';
                $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="menu-link"' . $target . '>' . $label . '</a>';
                $html .= '</li>';
            }
        }

        $html .= '</ul>';
        return $html;
    }
}
