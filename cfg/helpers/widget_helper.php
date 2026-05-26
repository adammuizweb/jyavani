<?php
declare(strict_types=1);

/**
 * widget_helper.php
 * - Widget rendering (view include) + data fetcher sederhana
 * - Shortcode expander: [[widget:recent_posts limit=5 title="Artikel Terbaru"]]
 * - helper milik sidebar 
 * Konvensi lokasi widget view:
 * 1) /views/themes/{active_theme}/widget/{name}.php
 * 2) /views/themes/{DEFAULT_THEME_FOLDER}/widget/{name}.php
 * 3) /views/widget/{name}.php    (GLOBAL)
 */

if (
    PHP_SAPI !== 'cli' &&
    realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__
) {
    http_response_code(404);
    require __DIR__ . '/../../public/frontend_404.php';
    exit;
}

if (defined('WIDGET_HELPER_INCLUDED')) {
    return;
}
define('WIDGET_HELPER_INCLUDED', true);

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', realpath(__DIR__) ?: __DIR__);
}

if (!defined('DEFAULT_THEME_FOLDER')) {
    define('DEFAULT_THEME_FOLDER', 'default');
}

if (!function_exists('widget_h')) {
    function widget_h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('widget_get_pdo')) {
    function widget_get_pdo(): ?PDO
    {
        global $pdo;
        return (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
    }
}

if (!function_exists('widget_active_theme_folder')) {
    function widget_active_theme_folder(?PDO $pdo = null): string
    {
        if (function_exists('get_active_theme_folder')) {
            try {
                return (string)get_active_theme_folder($pdo);
            } catch (Throwable $e) {
            }
        }

        $pdo = $pdo ?: widget_get_pdo();
        if ($pdo instanceof PDO) {
            try {
                $st = $pdo->query("SELECT folder_name FROM themes WHERE is_active = 1 LIMIT 1");
                $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
                if (!empty($r['folder_name'])) {
                    return (string)$r['folder_name'];
                }
            } catch (Throwable $e) {
            }
        }

        return DEFAULT_THEME_FOLDER;
    }
}

if (!function_exists('widget_find_view')) {
    function widget_find_view(string $name, ?PDO $pdo = null): ?string
    {
        $name = preg_replace('/[^a-z0-9_\-]/i', '', $name);
        if ($name === '') {
            return null;
        }

        $themesBase = defined('VIEWS_BASE')
            ? (string)VIEWS_BASE
            : (PUBLIC_PATH . '/views/themes');

        $active = widget_active_theme_folder($pdo);

        $candidates = [
            $themesBase . '/' . $active . '/widget/' . $name . '.php',
            $themesBase . '/' . DEFAULT_THEME_FOLDER . '/widget/' . $name . '.php',
            PUBLIC_PATH . '/views/widget/' . $name . '.php',
        ];

        foreach ($candidates as $p) {
            $real = realpath($p);
            if ($real && is_file($real)) {
                return $real;
            }
        }

        return null;
    }
}

if (!function_exists('render_widget')) {
    function render_widget(string $name, array $vars = [], ?PDO $pdo = null): string
    {
        $pdo = $pdo ?: widget_get_pdo();

        $path = widget_find_view($name, $pdo);
        if ($path) {
            $vars = array_merge([
                '__pdo' => $pdo,
                '__widget_name' => $name,
            ], $vars);

            ob_start();
            try {
                (function ($__path, $__vars) {
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

        // Fallback: registered shortcode-based handler
        if (
            isset($GLOBALS['_widget_shortcode_handlers'][$name])
            && $pdo instanceof PDO
        ) {
            $handler = $GLOBALS['_widget_shortcode_handlers'][$name];
            $attrs = array_merge($handler['defaults'], $vars);
            return ($handler['fn'])($pdo, $attrs, $vars);
        }

        return '';
    }
}

if (!function_exists('register_widget_shortcode_handler')) {
    function register_widget_shortcode_handler(
        string   $widgetName,
        callable $renderFn,
        array    $defaults = []
    ): void {
        $GLOBALS['_widget_shortcode_handlers'][$widgetName] = [
            'fn'       => $renderFn,
            'defaults' => $defaults,
        ];
    }
}

if (!function_exists('widget')) {
    function widget(string $name, array $vars = [], ?PDO $pdo = null): string
    {
        return render_widget($name, $vars, $pdo);
    }
}

if (!function_exists('widget_parse_attrs')) {
    function widget_parse_attrs(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $attrs = [];

        if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_\-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))/', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $k = $row[1];
                $v = $row[2] !== '' ? $row[2] : ($row[3] !== '' ? $row[3] : $row[4]);
                $attrs[$k] = $v;
            }
        }

        return $attrs;
    }
}

if (!function_exists('widget_expand_shortcodes')) {
    function widget_expand_shortcodes(string $html, ?PDO $pdo = null, array $context = []): string
    {
        if (strpos($html, '[[widget:') === false) {
            return $html;
        }

        return (string)preg_replace_callback(
            '/\[\[\s*widget:([a-z0-9_\-]+)\s*([^\]]*)\]\]/i',
            function ($m) use ($pdo, $context) {
                $name = (string)$m[1];
                $attrs = widget_parse_attrs((string)($m[2] ?? ''));
                $vars = array_merge($context, $attrs);
                return render_widget($name, $vars, $pdo);
            },
            $html
        );
    }
}

if (!function_exists('widget_fetch_recent_posts')) {
    function widget_fetch_recent_posts(PDO $pdo, int $limit = 5, string $type = 'article', ?int $created_by = null): array
    {
        $limit = max(1, min(50, $limit));
        $type = ($type === 'page') ? 'page' : 'article';

        $where = ["type = :type", "is_deleted = 0", "status = 'published'"];
        $params = [':type' => $type];

        if ($created_by !== null) {
            $where[] = "created_by = :uid";
            $params[':uid'] = (int)$created_by;
        }

        $sql = "SELECT id, title, slug, created_at, created_by
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
}

if (!function_exists('widget_fetch_pages')) {
    function widget_fetch_pages(PDO $pdo, int $limit = 20): array
    {
        return widget_fetch_recent_posts($pdo, $limit, 'page', null);
    }
}

if (!function_exists('widget_fetch_categories')) {
    function widget_fetch_categories(PDO $pdo, int $limit = 50, bool $only_parents = true): array
    {
        $limit = max(1, min(200, $limit));

        $sql = "SELECT id, name, slug, parent_id
                FROM categories
                WHERE is_deleted = 0";

        if ($only_parents) {
            $sql .= " AND parent_id IS NULL";
        }

        $sql .= " ORDER BY name ASC LIMIT :lim";

        $st = $pdo->prepare($sql);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

// Lazy-load shortcode-based widget handlers (post_cat_shortcode, post_list, etc.)
$___shortcodeHelper = __DIR__ . '/widget_shortcodes_p.php';
if (is_file($___shortcodeHelper)) {
    require_once $___shortcodeHelper;
}
unset($___shortcodeHelper);
