<?php
declare(strict_types=1);

/**
 * Stores a normalized collection route after routing has resolved dynamic site
 * settings. Extensions receive semantic data instead of parsing REQUEST_URI.
 */
function collection_set_route_context(array $context): array
{
    $context['route'] = (string)($context['route'] ?? '');
    $context['page'] = max(1, (int)($context['page'] ?? 1));
    $context['query'] = trim((string)($context['query'] ?? ''));
    $context['path'] = trim((string)($context['path'] ?? ''), '/');
    $filtered = apply_filters('collection_route_context', $context);
    if (is_array($filtered)) $context = $filtered;
    $GLOBALS['jy_collection_route_context'] = $context;
    return $context;
}

function collection_current_route_context(): ?array
{
    $context = $GLOBALS['jy_collection_route_context'] ?? null;
    return is_array($context) ? $context : null;
}

function collection_match_route_base(string $path, array $bases): ?array
{
    $path = trim($path, '/');
    $bases = array_values(array_unique(array_filter(array_map(fn($base) => trim((string)$base, '/'), $bases), fn($base) => $base !== '')));
    usort($bases, fn($left, $right) => strlen($right) <=> strlen($left));

    foreach ($bases as $base) {
        if ($path === $base) return ['base' => $base, 'rest' => ''];
        if (str_starts_with($path, $base . '/')) {
            return ['base' => $base, 'rest' => substr($path, strlen($base) + 1)];
        }
    }
    return null;
}

function collection_filter_item(array $item, string $type, array $context = []): array
{
    $filtered = apply_filters('collection_item', $item, $type, $context);
    return is_array($filtered) ? $filtered : $item;
}

function collection_filter_rows(array $rows, array $context = []): array
{
    $filtered = apply_filters('collection_rows', $rows, $context);
    return is_array($filtered) ? $filtered : $rows;
}

/**
 * Lets extensions constrain collection SQL consistently for count and rows.
 * Plugins may add prepared WHERE fragments and uniquely named parameters only.
 */
function collection_query_clauses(array $clauses, array $context = []): array
{
    $clauses = [
        'where' => is_array($clauses['where'] ?? null) ? $clauses['where'] : [],
        'params' => is_array($clauses['params'] ?? null) ? $clauses['params'] : [],
    ];
    $filtered = apply_filters('collection_query_clauses', $clauses, $context);
    if (!is_array($filtered)) return $clauses;

    $where = array_values(array_filter($filtered['where'] ?? [], fn($clause) => is_string($clause) && trim($clause) !== ''));
    $params = is_array($filtered['params'] ?? null) ? $filtered['params'] : [];
    return ['where' => $where, 'params' => $params];
}

function collection_url(string $url, string $type, array $context = []): string
{
    $filtered = apply_filters('collection_url', $url, $type, $context);
    return is_string($filtered) && $filtered !== '' ? $filtered : $url;
}

function collection_paginated_url(string $baseUrl, int $page = 1, string $query = '', array $context = []): string
{
    $base = trim($baseUrl, '/');
    $url = $base === '' ? '/' : '/' . $base . '/';
    $page = max(1, $page);
    if ($page > 1) {
        $url .= 'p/' . $page . '/';
    }
    if ($query !== '') {
        $url .= '?' . http_build_query(['q' => $query]);
    }
    return collection_url($url, 'pagination', $context + ['page' => $page, 'query' => $query]);
}

function get_category_permalink(PDO $pdo, array $category, int $page = 1, string $query = ''): string
{
    $segments = [];
    $current = $category;
    $visited = [];

    while (!empty($current['id']) && !in_array((int)$current['id'], $visited, true)) {
        $visited[] = (int)$current['id'];
        $slug = trim((string)($current['slug'] ?? ''));
        if ($slug === '') break;
        array_unshift($segments, rawurlencode($slug));

        $parentId = (int)($current['parent_id'] ?? 0);
        if ($parentId <= 0) break;
        $stmt = $pdo->prepare('SELECT id, parent_id, slug FROM categories WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$parent) break;
        $current = $parent;
    }

    $base = get_category_base($pdo);
    $url = rtrim($base, '/') . '/' . implode('/', $segments) . '/';
    return collection_paginated_url($url, $page, $query, [
        'route' => 'category',
        'category_id' => (int)($category['id'] ?? 0),
    ]);
}

function get_category_index_permalink(PDO $pdo): string
{
    return collection_url(get_category_base($pdo), 'category_index', ['route' => 'category_index']);
}

function collection_category_breadcrumbs(PDO $pdo, array $category, array $context = []): array
{
    $items = [];
    $current = $category;
    $visited = [];

    while (!empty($current['id']) && !in_array((int)$current['id'], $visited, true)) {
        $visited[] = (int)$current['id'];
        array_unshift($items, $current);

        $parentId = (int)($current['parent_id'] ?? 0);
        if ($parentId <= 0) break;
        $stmt = $pdo->prepare('SELECT id, parent_id, name, slug, description FROM categories WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$parent) break;
        $current = $parent;
    }

    $breadcrumbs = [];
    foreach ($items as $item) {
        $display = collection_filter_item($item, 'category', $context + [
            'scope' => 'category_breadcrumb',
            'category_id' => (int)$item['id'],
        ]);
        $display['url'] = get_category_permalink($pdo, $item);
        $breadcrumbs[] = $display;
    }
    return $breadcrumbs;
}
