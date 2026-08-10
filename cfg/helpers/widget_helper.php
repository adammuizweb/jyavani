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
    require __DIR__ . '/../../app/frontend_404.php';
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
    function render_widget(string $name, array $vars = [], ?PDO $pdo = null, array $context = []): string
    {
        $pdo = $pdo ?: widget_get_pdo();

        $path = widget_find_view($name, $pdo);
        if ($path) {
            $vars = array_merge($context, [
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
            $handlerContext = array_merge($context, ['__widget_name' => $name]);
            try {
                return ($handler['fn'])($pdo, $attrs, $handlerContext);
            } catch (Throwable $e) {
                error_log('[widget_helper] render_widget shortcode handler error: ' . $e->getMessage());
                return '';
            }
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

        $pdo = $pdo ?: widget_get_pdo();
        if ($pdo instanceof PDO && function_exists('load_preset_widgets')) {
            load_preset_widgets($pdo);
        }

        return (string)preg_replace_callback(
            '/\[\[\s*widget:([a-z0-9_\-]+)\s*([^\]]*)\]\]/i',
            function ($m) use ($pdo, $context) {
                $name = (string)$m[1];
                $attrs = widget_parse_attrs((string)($m[2] ?? ''));
                return render_widget($name, $attrs, $pdo, $context);
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

        $sql = "SELECT id, title, slug, thumbnail, content, created_at, created_by
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

        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return apply_filters('widget_recent_posts', $items, $pdo, $type, $created_by);
    }
}

if (!function_exists('widget_first_image_from_content')) {
    function widget_first_image_from_content(?string $html): string {
        if (empty($html) || !class_exists('DOMDocument')) return '';
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) return '';
        $imgs = $doc->getElementsByTagName('img');
        foreach ($imgs as $img) {
            $src = trim((string)$img->getAttribute('src'));
            if ($src !== '') return $src;
        }
        return '';
    }
}

if (!function_exists('widget_format_date_id')) {
    function widget_format_date_id(?string $date): string {
        if (empty($date)) return '';
        $ts = strtotime($date);
        if (!$ts) return '';
        return function_exists('format_date_id') ? format_date_id($ts) : date('d M Y', $ts);
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

        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return apply_filters('widget_categories', $items, $pdo, $limit, $only_parents);
    }
}

if (!function_exists('_render_single_widget')) {
    function _render_single_widget(PDO $pdo, string $type, array $config): string
    {
        switch ($type) {
            case 'search':
                return render_widget('search_form', [
                    'title'       => (string)($config['title'] ?? 'Search'),
                    'placeholder' => (string)($config['placeholder'] ?? 'Search articles...'),
                    'button'      => (string)($config['button'] ?? 'Search'),
                ], $pdo);

            case 'last_posts':
                return render_widget('recent_posts', [
                    'title' => (string)($config['title'] ?? 'Artikel Terbaru'),
                    'limit' => max(1, min(50, (int)($config['limit'] ?? 5))),
                    'type'  => in_array($config['type'] ?? '', ['article', 'page'], true) ? (string)$config['type'] : 'article',
                ], $pdo);

            case 'editor_pick':
                $attrs = [
                    'title'  => (string)($config['title'] ?? 'Pilihan Editor'),
                    'limit'  => max(1, min(20, (int)($config['limit'] ?? 3))),
                    'layout' => in_array($config['layout'] ?? '', ['cards', 'list', 'card2', 'sliderpage'], true) ? (string)$config['layout'] : 'cards',
                    'source' => 'posts',
                    'type'   => in_array($config['type'] ?? '', ['article', 'page'], true) ? (string)$config['type'] : 'article',
                ];
                if (!empty($config['category'])) {
                    $attrs['category'] = (string)$config['category'];
                }
                if (!empty($config['random'])) {
                    $attrs['random'] = '1';
                }
                return render_widget('post_cat_shortcode', $attrs, $pdo);

            case 'html':
                $html = '<div class="w-box w-custom-html">';
                $titleText = (string)($config['title'] ?? '');
                if ($titleText !== '') {
                    $html .= '<div class="w-title">' . widget_h($titleText) . '</div>';
                }
                $html .= '<div class="w-html-content">' . ((string)($config['html'] ?? '')) . '</div>';
                $html .= '</div>';
                return $html;

            case 'categories':
                return render_widget('categories_list', [
                    'title'       => (string)($config['title'] ?? 'Kategori'),
                    'limit'       => max(1, min(200, (int)($config['limit'] ?? 30))),
                    'only_parents' => !empty($config['only_parents']),
                ], $pdo);

            case 'shortcode_preset':
                $slug = (string)($config['preset_slug'] ?? '');
                if ($slug === '') return '';
                $rendered = render_widget($slug, [], $pdo);
                if ($rendered === '') return '';
                $titleText = (string)($config['title'] ?? '');
                if ($titleText === '') return $rendered;
                return '<div class="w-box w-shortcode-preset">'
                    . '<div class="w-title">' . widget_h($titleText) . '</div>'
                    . $rendered
                    . '</div>';
        }
        return apply_filters('render_sidebar_widget', '', $type, $config, $pdo);
    }
}

if (!function_exists('render_sidebar_widgets')) {
    function render_sidebar_widgets(PDO $pdo, ?string $zoneSlug = null): string
    {
        require_once __DIR__ . '/sidebar_helper.php';

        if (!function_exists('sidebar_zone_get_all')) {
            return '';
        }

        $zones = sidebar_zone_get_all($pdo);
        if (empty($zones)) {
            // Fallback: check old settings-based widgets
            $stored = function_exists('settings_get') ? settings_get($pdo, 'sidebar_widgets') : null;
            if ($stored !== null && $stored !== '') {
                $oldWidgets = json_decode($stored, true);
                if (is_array($oldWidgets)) {
                    usort($oldWidgets, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                    $html = '';
                    foreach ($oldWidgets as $w) {
                        if (empty($w['active'])) continue;
                        try {
                            $html .= _render_single_widget($pdo, (string)($w['type'] ?? ''), (array)($w['config'] ?? []));
                        } catch (Throwable $e) {
                            error_log('[widget_helper] render_sidebar_widgets old item error: ' . $e->getMessage());
                        }
                    }
                    return $html;
                }
            }
            return '';
        }

        if ($zoneSlug !== null) {
            $zone = sidebar_zone_get_by_slug($pdo, $zoneSlug);
        } else {
            $zone = sidebar_zone_get_primary($pdo);
        }

        if (!$zone) return '';

        $items = sidebar_zone_get_items($pdo, (int)$zone['id']);
        if (empty($items)) return '';

        $html = '';
        foreach ($items as $item) {
            if (empty($item['active'])) continue;
        $config = is_array($item['config']) ? $item['config'] : [];
        if (!isset($config['title']) && !empty($item['title'])) {
            $config['title'] = (string)$item['title'];
        }
        try {
            $html .= _render_single_widget($pdo, (string)$item['type'], $config);
        } catch (Throwable $e) {
            error_log('[widget_helper] render_sidebar_widgets item error: ' . $e->getMessage());
        }
        }

        return $html;
    }
}

// Lazy-load shortcode-based widget handlers (post_cat_shortcode, post_list, etc.)
$___shortcodeHelper = __DIR__ . '/widget_shortcodes_p.php';
if (is_file($___shortcodeHelper)) {
    require_once $___shortcodeHelper;
}
unset($___shortcodeHelper);
