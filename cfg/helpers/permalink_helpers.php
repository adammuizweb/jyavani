<?php
declare(strict_types=1);

function get_post_parent_category_slug(PDO $pdo, int $postId): string
{
    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.slug, c.parent_id
            FROM post_categories pc
            JOIN categories c ON c.id = pc.category_id
            WHERE pc.post_id = :pid AND c.is_deleted = 0
            LIMIT 1
        ");
        $stmt->execute([':pid' => $postId]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cat) {
            return '';
        }

        // Walk up to find the top-level parent
        $slug = $cat['slug'];
        $parentId = $cat['parent_id'];
        $visited = [(int)$cat['id']];
        while ($parentId !== null && $parentId !== 0) {
            if (in_array((int)$parentId, $visited, true)) {
                break;
            }
            $visited[] = (int)$parentId;
            $st = $pdo->prepare("SELECT id, slug, parent_id FROM categories WHERE id = :id AND is_deleted = 0 LIMIT 1");
            $st->execute([':id' => $parentId]);
            $parent = $st->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                break;
            }
            $slug = $parent['slug'];
            $parentId = $parent['parent_id'];
        }

        return rawurlencode($slug);
    } catch (Throwable $e) {
        error_log('[get_post_parent_category_slug] DB error: ' . $e->getMessage());
        return '';
    }
}

function get_permalink_structure(PDO $pdo, string $type = 'post'): string
{
    $key = $type === 'page' ? 'permalink_pages' : 'permalink_posts';
    $default = '/%slug%/';
    return settings_get($pdo, $key, $default) ?? $default;
}

function set_permalink_structure(PDO $pdo, string $type, string $structure): bool
{
    $key = $type === 'page' ? 'permalink_pages' : 'permalink_posts';
    return settings_set($pdo, $key, $structure, 1);
}

function get_post_permalink(array $post): string
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        return '/' . rawurlencode($post['slug'] ?? '') . '/';
    }

    $postId = (int)($post['id'] ?? 0);
    $routeUrl = $postId > 0 && function_exists('content_route_canonical_url')
        ? content_route_canonical_url($pdo, $postId)
        : null;
    if (is_string($routeUrl) && $routeUrl !== '') {
        $filtered = apply_filters('content_permalink', $routeUrl, $post, 'post');
        return is_string($filtered) && $filtered !== '' ? $filtered : $routeUrl;
    }

    $structure = get_permalink_structure($pdo, 'post');

    $slug = rawurlencode($post['slug'] ?? '');
    $created = $post['created_at'] ?? null;
    $year = $created ? date('Y', strtotime($created)) : '';
    $monthnum = $created ? date('m', strtotime($created)) : '';
    $day = $created ? date('d', strtotime($created)) : '';

    $cat = '';
    if (str_contains($structure, '%cat%')) {
        $postId = (int)($post['id'] ?? 0);
        if ($postId > 0) {
            $cat = get_post_parent_category_slug($pdo, $postId);
        }
    }

    $safe = sanitize_permalink_structure($structure);

    $url = str_replace(
        ['%year%', '%monthnum%', '%day%', '%slug%', '%cat%'],
        [$year, $monthnum, $day, $slug, $cat],
        $safe
    );

    if (!str_starts_with($url, '/')) {
        $url = '/' . $url;
    }
    if (!str_ends_with($url, '/')) {
        $url .= '/';
    }

    $filtered = apply_filters('content_permalink', $url, $post, 'post');
    return is_string($filtered) && $filtered !== '' ? $filtered : $url;
}

function get_page_permalink(array $page): string
{
    $slug = rawurlencode($page['slug'] ?? '');

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        return '/' . $slug . '/';
    }

    $pageId = (int)($page['id'] ?? 0);
    $routeUrl = $pageId > 0 && function_exists('content_route_canonical_url')
        ? content_route_canonical_url($pdo, $pageId)
        : null;
    if (is_string($routeUrl) && $routeUrl !== '') {
        $filtered = apply_filters('content_permalink', $routeUrl, $page, 'page');
        return is_string($filtered) && $filtered !== '' ? $filtered : $routeUrl;
    }

    $structure = get_permalink_structure($pdo, 'page');

    $created = $page['created_at'] ?? null;
    $year = $created ? date('Y', strtotime($created)) : '';
    $monthnum = $created ? date('m', strtotime($created)) : '';
    $day = $created ? date('d', strtotime($created)) : '';

    $safe = sanitize_permalink_structure($structure);

    $url = str_replace(
        ['%year%', '%monthnum%', '%day%', '%slug%'],
        [$year, $monthnum, $day, $slug],
        $safe
    );

    if (!str_starts_with($url, '/')) {
        $url = '/' . $url;
    }
    if (!str_ends_with($url, '/')) {
        $url .= '/';
    }

    $filtered = apply_filters('content_permalink', $url, $page, 'page');
    return is_string($filtered) && $filtered !== '' ? $filtered : $url;
}

function get_author_permalink(array $author, int $page = 1, string $query = ''): string
{
    $identifier = trim((string)($author['username'] ?? ''));
    if ($identifier === '') {
        $identifier = (string)($author['id'] ?? '');
    }
    if ($identifier === '') {
        return '/author/';
    }

    $url = '/author/' . rawurlencode($identifier) . '/';
    if ($page > 1) {
        $url .= 'p/' . $page . '/';
    }
    if ($query !== '') {
        $url .= '?' . http_build_query(['q' => $query]);
    }
    $filtered = apply_filters('author_permalink', $url, $author, $page, $query);
    return is_string($filtered) && $filtered !== '' ? $filtered : $url;
}

function permalink_match_path(string $path, string $structure): ?array
{
    $path = trim($path, '/');
    $structure = trim($structure, '/');

    $pathSegs = explode('/', $path);
    $structSegs = explode('/', $structure);

    if (count($pathSegs) !== count($structSegs)) {
        return null;
    }

    $params = [];
    foreach ($structSegs as $i => $seg) {
        if ($seg === '%year%') {
            if (!preg_match('/^\d{4}$/', $pathSegs[$i])) {
                return null;
            }
            $params['year'] = $pathSegs[$i];
        } elseif ($seg === '%monthnum%') {
            if (!preg_match('/^\d{1,2}$/', $pathSegs[$i])) {
                return null;
            }
            $month = (int)$pathSegs[$i];
            if ($month < 1 || $month > 12) {
                return null;
            }
            $params['monthnum'] = str_pad($pathSegs[$i], 2, '0', STR_PAD_LEFT);
        } elseif ($seg === '%day%') {
            if (!preg_match('/^\d{1,2}$/', $pathSegs[$i])) {
                return null;
            }
            $day = (int)$pathSegs[$i];
            if ($day < 1 || $day > 31) {
                return null;
            }
            $params['day'] = str_pad($pathSegs[$i], 2, '0', STR_PAD_LEFT);
        } elseif ($seg === '%slug%') {
            $params['slug'] = rawurldecode($pathSegs[$i]);
        } elseif ($seg === '%cat%') {
            $params['cat'] = rawurldecode($pathSegs[$i]);
        } else {
            if ($seg !== $pathSegs[$i]) {
                return null;
            }
        }
    }

    if (isset($params['year'], $params['monthnum'], $params['day'])
        && !checkdate((int)$params['monthnum'], (int)$params['day'], (int)$params['year'])
    ) {
        return null;
    }

    return $params;
}

function permalink_resolve(PDO $pdo, string $path): ?array
{
    $postStruct = get_permalink_structure($pdo, 'post');
    $params = permalink_match_path($path, $postStruct);

    if ($params && isset($params['slug'])) {
        $conditions = ["p.slug = :slug", "p.is_deleted = 0"];
        $bind = [':slug' => $params['slug']];

        if (isset($params['year'])) {
            $conditions[] = "YEAR(p.created_at) = :yr";
            $bind[':yr'] = (int)$params['year'];
        }
        if (isset($params['monthnum'])) {
            $conditions[] = "MONTH(p.created_at) = :mo";
            $bind[':mo'] = (int)$params['monthnum'];
        }
        if (isset($params['day'])) {
            $conditions[] = "DAY(p.created_at) = :dy";
            $bind[':dy'] = (int)$params['day'];
        }

        // If %cat% was matched, verify the category exists and filter by it
        if (isset($params['cat'])) {
            $catSlug = $params['cat'];
            try {
                // Find the top-level category by slug
                $catStmt = $pdo->prepare("SELECT id FROM categories WHERE slug = :slug AND is_deleted = 0 AND (parent_id IS NULL OR parent_id = 0) LIMIT 1");
                $catStmt->execute([':slug' => $catSlug]);
                $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);
                if (!$catRow) {
                    return null;
                }
                // Filter posts that belong to this category OR its descendants
                $catId = (int)$catRow['id'];
                $conditions[] = "p.id IN (
                    SELECT pc.post_id FROM post_categories pc
                    JOIN categories c ON c.id = pc.category_id
                    WHERE (c.id = :cat_id OR c.parent_id = :cat_id2) AND c.is_deleted = 0
                )";
                $bind[':cat_id'] = $catId;
                $bind[':cat_id2'] = $catId;
            } catch (Throwable $e) {
                error_log('[permalink_resolve] category lookup error: ' . $e->getMessage());
                return null;
            }
        }

        $where = implode(' AND ', $conditions);
        $isLoggedIn = !empty($_SESSION['user_id']);
        if (!$isLoggedIn) {
            $where .= " AND p.status = 'published'";
        }

        try {
            $sql = "SELECT * FROM posts p WHERE $where LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            error_log('[permalink_resolve] DB error: ' . $e->getMessage());
            return null;
        }
    }

    return null;
}

function permalink_is_date_based(PDO $pdo): bool
{
    $struct = get_permalink_structure($pdo, 'post');
    return str_contains($struct, '%year%');
}

define('PERMALINK_VALID_TOKENS', ['%year%', '%monthnum%', '%day%', '%slug%', '%cat%']);

function validate_permalink_structure(string $structure): bool
{
    if ($structure === '') {
        return false;
    }
    if (!str_contains($structure, '%slug%')) {
        return false;
    }
    // Check for unknown %...% tokens (typos like %years%, %slug%, etc.)
    if (preg_match_all('/%[a-z_]+%/i', $structure, $matches)) {
        foreach ($matches[0] as $token) {
            if (!in_array($token, PERMALINK_VALID_TOKENS, true)) {
                return false;
            }
        }
    }
    return true;
}

function sanitize_permalink_structure(string $structure): string
{
    // Strip unknown %...% tokens as safety net
    return preg_replace_callback('/%[a-z_]+%/i', function ($m) {
        return in_array($m[0], PERMALINK_VALID_TOKENS, true) ? $m[0] : '';
    }, $structure);
}

function permalink_structure_segment_count(string $structure): int
{
    $trimmed = trim($structure, '/');
    if ($trimmed === '') {
        return 1;
    }
    return count(explode('/', $trimmed));
}

function get_posts_list_path(PDO $pdo): string
{
    $path = settings_get($pdo, 'posts_list_path', 'artikel') ?? 'artikel';
    return trim($path, '/');
}

function get_pages_list_path(PDO $pdo): string
{
    $path = settings_get($pdo, 'pages_list_path', 'halaman') ?? 'halaman';
    return trim($path, '/');
}

function is_posts_list_enabled(PDO $pdo): bool
{
    $path = get_posts_list_path($pdo);
    return $path !== '';
}

function is_pages_list_enabled(PDO $pdo): bool
{
    $path = get_pages_list_path($pdo);
    return $path !== '';
}

function get_posts_list_base(PDO $pdo): string
{
    $path = get_posts_list_path($pdo);
    return $path !== '' ? '/' . $path . '/' : '/artikel/';
}

function get_pages_list_base(PDO $pdo): string
{
    $path = get_pages_list_path($pdo);
    return $path !== '' ? '/' . $path . '/' : '/halaman/';
}

function get_posts_list_routes(PDO $pdo): array
{
    $path = get_posts_list_path($pdo);
    if ($path === '') {
        return [];
    }
    $routes = [$path];
    if ($path !== 'posts') {
        $routes[] = 'posts';
    }
    return $routes;
}

function get_pages_list_routes(PDO $pdo): array
{
    $path = get_pages_list_path($pdo);
    if ($path === '') {
        return [];
    }
    return [$path];
}

function get_category_path(PDO $pdo): string
{
    $path = settings_get($pdo, 'category_path', 'category') ?? 'category';
    return trim($path, '/');
}

function is_category_enabled(PDO $pdo): bool
{
    return get_category_path($pdo) !== '';
}

function get_category_base(PDO $pdo): string
{
    $path = get_category_path($pdo);
    return $path !== '' ? '/' . $path . '/' : '/';
}

function get_category_routes(PDO $pdo): array
{
    $path = get_category_path($pdo);
    if ($path === '') {
        return [];
    }
    return [$path];
}

function resolve_category_from_path(PDO $pdo, string $path): ?string
{
    $parts = array_values(array_filter(array_map('trim', explode('/', $path)), fn($v) => $v !== ''));
    if (empty($parts)) {
        return null;
    }

    $parentId = null;
    foreach ($parts as $seg) {
        try {
            if ($parentId === null) {
                $sql = "SELECT id FROM categories WHERE slug = :slug AND is_deleted = 0 AND (parent_id IS NULL OR parent_id = 0) LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':slug' => $seg]);
            } else {
                $sql = "SELECT id FROM categories WHERE slug = :slug AND parent_id = :pid AND is_deleted = 0 LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':slug' => $seg, ':pid' => $parentId]);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $parentId = (int)$row['id'];
        } catch (Throwable $e) {
            error_log('[resolve_category_from_path] DB error: ' . $e->getMessage());
            return null;
        }
    }

    return implode('/', array_map('rawurlencode', $parts));
}
