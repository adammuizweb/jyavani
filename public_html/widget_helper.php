<?php
declare(strict_types=1);

/**
 * widget_helper.php
 * - Widget rendering (view include) + data fetcher sederhana
 * - Shortcode expander: [[widget:recent_posts limit=5 title="Artikel Terbaru"]]
 *
 * Konvensi lokasi widget view:
 * 1) /views/themes/{active_theme}/widget/{name}.php
 * 2) /views/themes/{DEFAULT_THEME_FOLDER}/widget/{name}.php
 * 3) /views/widget/{name}.php    (GLOBAL)
 */

if (defined('WIDGET_HELPER_INCLUDED')) return;
define('WIDGET_HELPER_INCLUDED', true);

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', realpath(__DIR__) ?: __DIR__);
}
if (!defined('DEFAULT_THEME_FOLDER')) {
    define('DEFAULT_THEME_FOLDER', 'default');
}

function widget_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function widget_get_pdo(): ?PDO {
    // pakai global $pdo (mengikuti pola theme_helper kamu)
    global $pdo;
    return (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
}

function widget_active_theme_folder(?PDO $pdo = null): string {
    // kalau theme_helper punya get_active_theme_folder(), gunakan
    if (function_exists('get_active_theme_folder')) {
        try { return (string)get_active_theme_folder($pdo); } catch (Throwable $e) {}
    }

    // fallback sederhana: baca DB themes.is_active
    $pdo = $pdo ?: widget_get_pdo();
    if ($pdo) {
        try {
            $st = $pdo->query("SELECT folder_name FROM themes WHERE is_active=1 LIMIT 1");
            $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
            if (!empty($r['folder_name'])) return (string)$r['folder_name'];
        } catch (Throwable $e) {}
    }
    return DEFAULT_THEME_FOLDER;
}

function widget_find_view(string $name, ?PDO $pdo = null): ?string {
    $name = preg_replace('/[^a-z0-9_\-]/i', '', $name);
    if ($name === '') return null;

    // base themes folder (ikut VIEWS_BASE kalau ada dari theme_helper)
    $themesBase = defined('VIEWS_BASE') ? (string)VIEWS_BASE : (PUBLIC_PATH . '/views/themes');

    $active = widget_active_theme_folder($pdo);
    $candidates = [
        $themesBase . '/' . $active . '/widget/' . $name . '.php',
        $themesBase . '/' . DEFAULT_THEME_FOLDER . '/widget/' . $name . '.php',
        PUBLIC_PATH . '/views/widget/' . $name . '.php', // global
    ];

    foreach ($candidates as $p) {
        $real = realpath($p);
        if ($real && is_file($real)) return $real;
    }
    return null;
}

/**
 * render_widget():
 * - include view widget dan return HTML
 */
function render_widget(string $name, array $vars = [], ?PDO $pdo = null): string {
    $pdo = $pdo ?: widget_get_pdo();

    $path = widget_find_view($name, $pdo);
    if (!$path) return '';

    // supply pdo + helper ke widget view
    $vars = array_merge([
        '__pdo' => $pdo,
        '__widget_name' => $name,
    ], $vars);

    ob_start();
    try {
        (function($__path, $__vars) {
            extract($__vars, EXTR_SKIP);
            include $__path;
        })($path, $vars);
    } catch (Throwable $e) {
        ob_end_clean();
        error_log('[widget_helper] render_widget error: ' . $e->getMessage());
        return '';
    }
    return (string)ob_get_clean();
}

/**
 * Alias singkat.
 */
function widget(string $name, array $vars = [], ?PDO $pdo = null): string {
    return render_widget($name, $vars, $pdo);
}

function widget_parse_attrs(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];

    $attrs = [];
    // key="value" | key='value' | key=value
    if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_\-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))/', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $k = $row[1];
            $v = $row[2] !== '' ? $row[2] : ($row[3] !== '' ? $row[3] : $row[4]);
            $attrs[$k] = $v;
        }
    }
    return $attrs;
}

/**
 * Expand shortcode:
 * [[widget:recent_posts limit=5 title="Artikel Terbaru"]]
 */
function widget_expand_shortcodes(string $html, ?PDO $pdo = null, array $context = []): string {
    if (strpos($html, '[[widget:') === false) return $html;

    return (string)preg_replace_callback(
        '/\[\[\s*widget:([a-z0-9_\-]+)\s*([^\]]*)\]\]/i',
        function($m) use ($pdo, $context) {
            $name = (string)$m[1];
            $attrs = widget_parse_attrs((string)($m[2] ?? ''));
            // merge context default (kalau widget butuh current user/category dsb)
            $vars = array_merge($context, $attrs);
            return render_widget($name, $vars, $pdo);
        },
        $html
    );
}

////////////////////////////////////////////////////////////////////////////////
// Data fetchers (dipakai widget views)
////////////////////////////////////////////////////////////////////////////////

function widget_fetch_recent_posts(PDO $pdo, int $limit = 5, string $type = 'article', ?int $created_by = null): array {
    $limit = max(1, min(50, $limit));
    $type = ($type === 'page') ? 'page' : 'article';

    $where = ["type = :type", "is_deleted = 0", "status = 'published'"];
    $params = [':type' => $type];

    if ($created_by !== null) {
        $where[] = "created_by = :uid";
        $params[':uid'] = (int)$created_by;
    }

    $sql = "SELECT id,title,slug,created_at,created_by
            FROM posts
            WHERE " . implode(' AND ', $where) . "
            ORDER BY created_at DESC
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function widget_fetch_pages(PDO $pdo, int $limit = 20): array {
    return widget_fetch_recent_posts($pdo, $limit, 'page', null);
}

function widget_fetch_categories(PDO $pdo, int $limit = 50, bool $only_parents = true): array {
    $limit = max(1, min(200, $limit));
    $sql = "SELECT id,name,slug,parent_id
            FROM categories
            WHERE is_deleted = 0";
    if ($only_parents) $sql .= " AND parent_id IS NULL";
    $sql .= " ORDER BY name ASC LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
