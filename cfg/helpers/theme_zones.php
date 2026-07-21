<?php
declare(strict_types=1);

// Theme Zones — widget-area style header/footer/sidebar slots.
// Provides a lightweight alternative to hardcoded theme partials.

if (!function_exists('theme_zone_ensure_schema')) {

    function theme_zone_ensure_schema(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS theme_zone_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                theme_folder VARCHAR(100) NOT NULL DEFAULT '',
                zone_slug VARCHAR(50) NOT NULL,
                position VARCHAR(50) NOT NULL DEFAULT '',
                type VARCHAR(50) NOT NULL,
                title VARCHAR(200) NOT NULL DEFAULT '',
                config LONGTEXT NULL,
                ordering INT NOT NULL DEFAULT 0,
                active TINYINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_zone_order (zone_slug, ordering),
                KEY idx_zone_position (zone_slug, position, ordering),
                KEY idx_theme_zone (theme_folder, zone_slug, position, ordering)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $cols = $pdo->query("SHOW COLUMNS FROM theme_zone_items")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('theme_folder', $cols, true)) {
                $pdo->exec("ALTER TABLE theme_zone_items ADD COLUMN theme_folder VARCHAR(100) NOT NULL DEFAULT '' AFTER zone_slug");
                $pdo->exec("ALTER TABLE theme_zone_items ADD KEY idx_theme_zone (theme_folder, zone_slug, position, ordering)");
                $pdo->exec("UPDATE theme_zone_items t JOIN settings s ON s.`key` = 'active_theme' SET t.theme_folder = s.`value` WHERE t.theme_folder = ''");
            }
        } catch (Throwable $e) {
            error_log('[theme_zones] schema error: ' . $e->getMessage());
        }
    }

    function theme_zone_folder(PDO $pdo, ?string $folder = null): string {
        if (is_string($folder) && $folder !== '') return $folder;
        if (function_exists('get_active_theme_folder')) {
            $active = (string)get_active_theme_folder($pdo);
            if ($active !== '') return $active;
        }
        return 'default';
    }

    function theme_zone_items(PDO $pdo, string $zoneSlug, ?string $position = null, ?string $themeFolder = null, bool $activeOnly = true): array {
        theme_zone_ensure_schema($pdo);
        $themeFolder = theme_zone_folder($pdo, $themeFolder);
        $activeSql = $activeOnly ? ' AND active = 1' : '';
        try {
            if ($position === null) {
                $stmt = $pdo->prepare("SELECT * FROM theme_zone_items WHERE theme_folder = ? AND zone_slug = ?{$activeSql} ORDER BY position ASC, ordering ASC, id ASC");
                $stmt->execute([$themeFolder, $zoneSlug]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM theme_zone_items WHERE theme_folder = ? AND zone_slug = ? AND position = ?{$activeSql} ORDER BY ordering ASC, id ASC");
                $stmt->execute([$themeFolder, $zoneSlug, $position]);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[theme_zones] items error: ' . $e->getMessage());
            return [];
        }
    }

    function theme_zone_layout(string $folder): array {
        if (!defined('PUBLIC_PATH')) return [];
        $path = rtrim(PUBLIC_PATH, '/\\') . '/views/themes/' . $folder . '/theme.json';
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        $j = @json_decode($raw, true);
        if (!is_array($j) || empty($j['layout']) || !is_array($j['layout'])) return [];
        return $j['layout'];
    }

    // Temukan semua partial di main/ tema → ['list.post' => 'List Post', ...]
    // File top-level jadi 'main.{nama}', subfolder jadi '{dir}.{nama}', file _*.php di-skip.
    function theme_zone_discover_partials(string $folder): array {
        $out = [];
        if (!defined('PUBLIC_PATH')) return $out;
        $base = rtrim(PUBLIC_PATH, '/\\') . '/views/themes/' . $folder . '/main';
        if (!is_dir($base)) return $out;
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
            $name = $f->getFilename();
            if (str_starts_with($name, '_')) continue;
            $rel = ltrim(substr($f->getPathname(), strlen($base)), '/\\');
            $rel = substr($rel, 0, -4);
            $parts = preg_split('#[/\\\\]#', $rel);
            $slug = count($parts) === 1 ? 'main.' . $parts[0] : implode('.', $parts);
            $out[$slug] = ucwords(str_replace(['.', '_', '-'], ' ', $slug));
        }
        ksort($out);
        return $out;
    }

    // Konvensi positions untuk partial yang tidak declare layout di theme.json
    function theme_zone_partial_positions(string $slug): array {
        if (str_starts_with($slug, 'single.')) {
            return ['before_content' => __('Sebelum Konten'), 'after_content' => __('Sesudah Konten')];
        }
        if (str_starts_with($slug, 'list.') || str_starts_with($slug, 'index.') || $slug === 'main.search') {
            return ['before_loop' => __('Sebelum Daftar'), 'after_loop' => __('Sesudah Daftar')];
        }
        return ['before' => __('Sebelum'), 'after' => __('Sesudah')];
    }

    function theme_zone_render(PDO $pdo, string $zoneSlug, ?string $folder = null): string {
        $folder = theme_zone_folder($pdo, $folder);
        $items = theme_zone_items($pdo, $zoneSlug, null, $folder);
        if (empty($items)) return '';

        $layout = theme_zone_layout($folder)[$zoneSlug] ?? null;
        if (empty($layout) || empty($layout['positions']) || !is_array($layout['positions'])) {
            return theme_zone_render_items($pdo, $items);
        }

        $byPosition = [];
        foreach ($items as $item) {
            $pos = (string)($item['position'] ?? '');
            if ($pos === '') $pos = 'default';
            $byPosition[$pos][] = $item;
        }

        $safeSlug = htmlspecialchars($zoneSlug, ENT_QUOTES, 'UTF-8');
        $role = $zoneSlug === 'header' ? 'banner' : ($zoneSlug === 'footer' ? 'contentinfo' : 'region');
        $html = '<div class="theme-zone theme-zone-' . $safeSlug . '" role="' . $role . '" id="theme-zone-' . $safeSlug . '" data-theme-zone="' . $safeSlug . '">';

        foreach ($layout['positions'] as $posKey => $posDef) {
            $safePos = htmlspecialchars($posKey, ENT_QUOTES, 'UTF-8');
            $html .= '\n  \n  <div class="theme-zone-position theme-zone-position-' . $safePos . '" data-position="' . $safePos . '">';
            foreach ($byPosition[$posKey] ?? [] as $item) {
                $type = (string)($item['type'] ?? '');
                $config = json_decode((string)($item['config'] ?? '{}'), true) ?: [];
                if (function_exists('_render_single_widget')) {
                    $html .= _render_single_widget($pdo, $type, $config);
                } else {
                    $html .= render_widget($type, $config, $pdo);
                }
            }
            $html .= '\n  </div>';
        }

        $html .= '\n</div>';
        return $html;
    }

    function theme_zone_render_items(PDO $pdo, array $items): string {
        $html = '';
        foreach ($items as $item) {
            $type = (string)($item['type'] ?? '');
            $config = json_decode((string)($item['config'] ?? '{}'), true) ?: [];
            if (function_exists('_render_single_widget')) {
                $rendered = _render_single_widget($pdo, $type, $config);
            } else {
                $rendered = render_widget($type, $config, $pdo);
            }
            if (trim((string)$rendered) === '') continue;
            // Wrapper netral (tanpa inline style) — layout position diatur tema,
            // bukan core, supaya header/footer flex tidak rusak.
            $html .= '<div class="tz-gadget tz-gadget-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">' . $rendered . '</div>';
        }
        return $html;
    }

    function theme_zone_position_items(PDO $pdo, string $zoneSlug, string $position, ?string $folder = null, bool $activeOnly = true): array {
        return theme_zone_items($pdo, $zoneSlug, $position, $folder, $activeOnly);
    }

    function theme_zone_has_position(PDO $pdo, string $zoneSlug, string $position, ?string $folder = null): bool {
        return !empty(theme_zone_position_items($pdo, $zoneSlug, $position, $folder));
    }

    function theme_zone_render_position(PDO $pdo, string $zoneSlug, string $position, ?string $folder = null): string {
        $items = theme_zone_position_items($pdo, $zoneSlug, $position, $folder);
        if (empty($items)) return '';
        return theme_zone_render_items($pdo, $items);
    }

    // Jaringan sosial populer — SVG path dari Simple Icons (CC0).
    function theme_zone_social_networks(): array {
        return [
            'facebook'  => ['label' => 'Facebook',  'path' => 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z'],
            'x'         => ['label' => 'X / Twitter','path' => 'M14.234 10.162 22.977 0h-2.072l-7.591 8.824L7.251 0H.258l9.168 13.343L.258 24H2.33l8.016-9.318L16.749 24h6.993zm-2.837 3.299-.929-1.329L3.076 1.56h3.182l5.965 8.532.929 1.329 7.754 11.09h-3.182z'],
            'instagram' => ['label' => 'Instagram', 'path' => 'M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077'],
            'youtube'   => ['label' => 'YouTube',   'path' => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z'],
            'github'    => ['label' => 'GitHub',    'path' => 'M12 .5C5.7.5.9 5.3.9 11.6c0 4.7 3 8.7 7.2 10.1.5.1.7-.2.7-.5v-1.8c-2.9.6-3.5-1.2-3.5-1.2-.5-1.2-1.2-1.5-1.2-1.5-1-.7.1-.7.1-.7 1.1.1 1.7 1.1 1.7 1.1 1 .1 1.7.8 2.9.6.1-.7.4-1.2.8-1.5-2.3-.3-4.6-1.1-4.6-5 0-1.1.4-2 1.1-2.7-.1-.3-.5-1.5.1-3.1 0 0 .9-.3 3 .1.9-.3 1.8-.4 2.7-.4s1.8.1 2.7.4c2.1-.5 3-.1 3-.1.6 1.6.2 2.8.1 3.1.7.7 1.1 1.6 1.1 2.7 0 3.9-2.3 4.7-4.6 5 .4.3.7.9.7 1.8v2.6c0 .3.2.6.7.5 4.2-1.4 7.2-5.4 7.2-10.1C23 5.3 18.3.5 12 .5z'],
            'tiktok'    => ['label' => 'TikTok',    'path' => 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z'],
            'telegram'  => ['label' => 'Telegram',  'path' => 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z'],
            'whatsapp'  => ['label' => 'WhatsApp',  'path' => 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z'],
        ];
    }

    // Widget types intended for theme zones (header/footer). They are registered
    // through the regular sidebar widget system so they can also appear there.
    function theme_zone_default_widget_types(): array {
        return [
            'tz_image' => [
                'label' => __('Image'),
                'desc'  => __('Gambar dari Media Library (modal picker) atau URL langsung.'),
                'default_config' => ['src' => '', 'alt' => '', 'link' => '', 'max_width' => 0],
            ],
            'tz_nav_menu' => [
                'label' => __('Navigation Menu'),
                'desc'  => __('Renders a menu from Menu Manager.'),
                'default_config' => ['menu' => 'primary', 'menu_class' => 'menu', 'depth' => 1],
            ],
            'tz_social' => [
                'label' => __('Social Icons'),
                'desc'  => __('Pilih sosial media populer yang ingin ditampilkan (icon Simple Icons CC0).'),
                'default_config' => ['enabled' => ['x', 'github', 'instagram'], 'links' => []],
            ],
            'tz_pages' => [
                'label' => __('Page List'),
                'desc'  => __('Daftar link page dari Pages admin. Kosong = semua page published.'),
                'default_config' => ['pages' => [], 'list_class' => 'tz-pages'],
            ],
            'tz_richtext' => [
                'label' => __('Rich Text'),
                'desc'  => __('Konten rich text (Quill) — heading, bold, link, list, dll.'),
                'default_config' => ['html' => ''],
            ],
            'tz_sidebar_zone' => [
                'label' => __('Sidebar Zone'),
                'desc'  => __('Renders a sidebar zone from Sidebar Settings.'),
                'default_config' => ['zone' => ''],
            ],
            'tz_theme_toggle' => [
                'label' => __('Theme Toggle'),
                'desc'  => __('Light / Dark mode selector.'),
                'default_config' => ['label' => ''],
            ],
            'tz_lang_switcher' => [
                'label' => __('Language Switcher'),
                'desc'  => __('Language selector. Requires content-translation plugin.'),
                'default_config' => ['title' => ''],
            ],
            'tz_search' => [
                'label' => __('Search Field'),
                'desc'  => __('Article search form.'),
                'default_config' => ['placeholder' => __('Search...'), 'button' => false],
            ],
            'tz_html' => [
                'label' => __('Custom HTML'),
                'desc'  => __('Free HTML content.'),
                'default_config' => ['title' => '', 'html' => ''],
            ],
            'tz_post_author' => [
                'label' => __('Post Author'),
                'desc'  => __('Author box for single post. Reads the current post context.'),
                'default_config' => ['show_avatar' => true],
            ],
            'tz_post_meta' => [
                'label' => __('Post Meta'),
                'desc'  => __('Date / updated / read-time row for single post.'),
                'default_config' => ['show_date' => true, 'show_updated' => false, 'show_read_time' => true],
            ],
        ];
    }

    // Register zone widgets as sidebar widget types so render_widget() can dispatch them
    add_filter('sidebar_widget_types', function ($types) {
        if (!is_array($types)) $types = [];
        return array_merge($types, theme_zone_default_widget_types());
    });

    add_filter('render_sidebar_widget', function ($html, $type, $config, $pdo) {
        if (!$pdo instanceof PDO) return $html;
        $site = ['title' => 'Site', 'url' => '/'];
        if (isset($GLOBALS['site']) && is_array($GLOBALS['site'])) {
            $site = array_merge($site, $GLOBALS['site']);
        }

        switch ($type) {
            case 'tz_image':
                $src = trim((string)($config['src'] ?? ''));
                if ($src === '') return '';
                $alt = htmlspecialchars((string)($config['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
                $link = trim((string)($config['link'] ?? ''));
                $maxWidth = (int)($config['max_width'] ?? 0);
                $style = 'height:auto;';
                $style = 'max-width:' . ($maxWidth > 0 ? $maxWidth . 'px' : '100%') . ';height:auto;';
                $img = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" class="tz-image" style="' . $style . '">';
                if ($link !== '' && preg_match('#^(/|https?://)#i', $link)) {
                    return '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" class="tz-image-link">' . $img . '</a>';
                }
                return $img;

            case 'tz_nav_menu':
                $menu = (string)($config['menu'] ?? 'primary');
                if ($menu === '' || !function_exists('menu_render')) return '';
                $menuClass = (string)($config['menu_class'] ?? 'menu');
                $depth = (int)($config['depth'] ?? 1);
                $ulAttr = (string)($config['ul_attr'] ?? '');
                return menu_render($pdo, $menu, [
                    'menu_class' => $menuClass,
                    'submenu_class' => 'submenu',
                    'ul_attr' => $ulAttr,
                    'depth' => $depth,
                ]);

            case 'tz_social':
                $networks = theme_zone_social_networks();
                $enabled = (array)($config['enabled'] ?? ['x', 'github', 'instagram']);
                $links = (array)($config['links'] ?? []);
                $out = '';
                foreach ($enabled as $net) {
                    $net = (string)$net;
                    if (!isset($networks[$net])) continue;
                    $url = trim((string)($links[$net] ?? ''));
                    $href = $url !== '' ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '#';
                    $label = htmlspecialchars($networks[$net]['label'], ENT_QUOTES, 'UTF-8');
                    $out .= '<a class="social-btn tz-social-' . $net . '" href="' . $href . '" target="_blank" rel="noopener" aria-label="' . $label . '" title="' . $label . '">'
                        . '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="' . $networks[$net]['path'] . '" fill="currentColor"/></svg></a>';
                }
                if ($out === '') return '';
                return '<div class="tz-social social-icons" role="list">' . $out . '</div>';

            case 'tz_pages':
                $selected = array_filter(array_map('trim', (array)($config['pages'] ?? [])));
                try {
                    $rows = $pdo->query("SELECT title, slug FROM posts WHERE type = 'page' AND status = 'published' AND is_deleted = 0 ORDER BY title ASC")
                        ->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    return '';
                }
                if (!empty($selected)) {
                    $rows = array_values(array_filter($rows, fn($r) => in_array((string)$r['slug'], $selected, true)));
                    // urutkan sesuai urutan pilihan user
                    $order = array_flip($selected);
                    usort($rows, fn($a, $b) => ($order[(string)$a['slug']] ?? 999) <=> ($order[(string)$b['slug']] ?? 999));
                }
                if (empty($rows)) return '';
                $listClass = preg_replace('/[^a-zA-Z0-9_\- ]/', '', (string)($config['list_class'] ?? 'tz-pages'));
                $titleText = trim((string)($config['title'] ?? ''));
                $out = $titleText !== ''
                    ? '<div class="tz-html-title">' . htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') . '</div>'
                    : '';
                $out .= '<ul class="' . htmlspecialchars(trim($listClass), ENT_QUOTES, 'UTF-8') . '">';
                foreach ($rows as $r) {
                    $href = function_exists('get_page_permalink') ? get_page_permalink($r) : '/' . rawurlencode((string)$r['slug']) . '/';
                    $out .= '<li class="tz-page-item"><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)($r['title'] ?? $r['slug']), ENT_QUOTES, 'UTF-8') . '</a></li>';
                }
                return $out . '</ul>';

            case 'tz_richtext':
                $titleText = (string)($config['title'] ?? '');
                $htmlContent = (string)($config['html'] ?? '');
                if ($titleText !== '') {
                    return '<div class="tz-richtext"><div class="tz-richtext-title">' . htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') . '</div><div class="tz-richtext-content">' . $htmlContent . '</div></div>';
                }
                return '<div class="tz-richtext">' . $htmlContent . '</div>';

            case 'tz_sidebar_zone':
                $sbZone = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($config['zone'] ?? ''));
                if ($sbZone === '' || !function_exists('render_sidebar_widgets')) return '';
                return (string)render_sidebar_widgets($pdo, $sbZone);

            case 'tz_theme_toggle':
                $colorMode = function_exists('get_theme_color_mode') ? get_theme_color_mode() : 'both';
                if ($colorMode !== 'both') return '';
                return '<select class="tz-theme-toggle" data-jy-theme-toggle>
                    <option value="light">' . __('Light') . '</option>
                    <option value="dark">' . __('Dark') . '</option>
                </select>';

            case 'tz_lang_switcher':
                if (function_exists('ct_language_switcher')) {
                    return ct_language_switcher((string)($config['title'] ?? ''), 'select');
                }
                return '';

            case 'tz_search':
                $homeUrl = rtrim((string)($site['url'] ?? '/'), '/') . '/';
                $placeholder = htmlspecialchars((string)($config['placeholder'] ?? __('Search...')), ENT_QUOTES, 'UTF-8');
                $withButton = !empty($config['button']);
                $out = '<form method="get" action="' . htmlspecialchars($homeUrl, ENT_QUOTES) . '" class="tz-search">';
                $out .= '<input type="search" name="s" placeholder="' . $placeholder . '" class="tz-search-input">';
                if ($withButton) {
                    $out .= '<button type="submit" class="tz-search-btn">' . __('Search') . '</button>';
                }
                $out .= '</form>';
                return $out;

            case 'tz_html':
                $titleText = (string)($config['title'] ?? '');
                $htmlContent = (string)($config['html'] ?? '');
                if ($titleText !== '') {
                    return '<div class="tz-html"><div class="tz-html-title">' . htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') . '</div><div class="tz-html-content">' . $htmlContent . '</div></div>';
                }
                return $htmlContent;

            case 'tz_post_author':
                $post = (isset($GLOBALS['jy_current_post']) && is_array($GLOBALS['jy_current_post'])) ? $GLOBALS['jy_current_post'] : null;
                if (!$post) return '';
                $authorName = !empty($post['author_name']) ? (string)$post['author_name']
                    : (!empty($post['author_username']) ? (string)$post['author_username']
                    : (!empty($post['author_email']) ? (string)$post['author_email'] : __('Author')));
                $authorImg = !empty($post['author_img']) ? (string)$post['author_img'] : '';
                $authorSlug = !empty($post['author_username']) ? (string)$post['author_username'] : (!empty($post['author_id']) ? (string)$post['author_id'] : '');
                $authorUrl = $authorSlug !== '' ? '/author/' . rawurlencode($authorSlug) . '/' : '';
                $showAvatar = !array_key_exists('show_avatar', $config) || !empty($config['show_avatar']);
                $out = '<div class="tz-post-author">';
                if ($showAvatar) {
                    if ($authorImg !== '') {
                        $avatar = '<img src="' . htmlspecialchars($authorImg, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') . '" class="tz-author-avatar" style="width:48px;height:48px;border-radius:50%;object-fit:cover;">';
                    } else {
                        $avatar = '<span class="tz-author-avatar-placeholder" style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:50%;background:var(--accent,#0066ff);color:#fff;font-weight:700;">' . htmlspecialchars(strtoupper(substr($authorName, 0, 1)), ENT_QUOTES, 'UTF-8') . '</span>';
                    }
                    $out .= $authorUrl !== '' ? '<a href="' . htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') . '">' . $avatar . '</a>' : $avatar;
                }
                $out .= '<div class="tz-author-meta"><span class="tz-author-by">' . __('Written by') . '</span> ';
                $out .= $authorUrl !== ''
                    ? '<a class="tz-author-name" href="' . htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') . '</a>'
                    : '<span class="tz-author-name">' . htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') . '</span>';
                $out .= '</div></div>';
                return $out;

            case 'tz_post_meta':
                $post = (isset($GLOBALS['jy_current_post']) && is_array($GLOBALS['jy_current_post'])) ? $GLOBALS['jy_current_post'] : null;
                if (!$post) return '';
                $showDate = !array_key_exists('show_date', $config) || !empty($config['show_date']);
                $showUpdated = !empty($config['show_updated']);
                $showReadTime = !array_key_exists('show_read_time', $config) || !empty($config['show_read_time']);
                $parts = [];
                if ($showDate && !empty($post['created_at'])) {
                    $parts[] = '<time datetime="' . htmlspecialchars(date('c', strtotime((string)$post['created_at'])), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(date('d M Y', strtotime((string)$post['created_at'])), ENT_QUOTES, 'UTF-8') . '</time>';
                }
                if ($showUpdated && !empty($post['updated_at']) && !empty($post['created_at']) && $post['updated_at'] > $post['created_at']) {
                    $parts[] = '<span class="tz-meta-updated">' . __('Updated:') . ' ' . htmlspecialchars(date('d M Y', strtotime((string)$post['updated_at'])), ENT_QUOTES, 'UTF-8') . '</span>';
                }
                if ($showReadTime) {
                    $wordCount = str_word_count(function_exists('safe_strip_tags') ? safe_strip_tags((string)($post['content'] ?? '')) : strip_tags((string)($post['content'] ?? '')));
                    $readTime = max(1, (int)ceil($wordCount / 200));
                    $parts[] = '<span class="tz-meta-readtime">' . sprintf(__('%d min read'), $readTime) . '</span>';
                }
                if (empty($parts)) return '';
                return '<div class="tz-post-meta">' . implode(' <span class="tz-meta-sep">&middot;</span> ', $parts) . '</div>';
        }

        return $html;
    }, 10, 4);

    // ─── Admin CRUD ───

    function theme_zone_add_item(PDO $pdo, string $zoneSlug, string $type, array $config, string $title = '', string $position = '', ?string $themeFolder = null): bool {
        theme_zone_ensure_schema($pdo);
        $themeFolder = theme_zone_folder($pdo, $themeFolder);
        try {
            $maxStmt = $pdo->prepare("SELECT MAX(ordering) FROM theme_zone_items WHERE theme_folder = ? AND zone_slug = ? AND position = ?");
            $maxStmt->execute([$themeFolder, $zoneSlug, $position]);
            $maxOrder = (int)$maxStmt->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO theme_zone_items (theme_folder, zone_slug, position, type, title, config, ordering, active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            return $stmt->execute([$themeFolder, $zoneSlug, $position, $type, $title, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $maxOrder + 1]);
        } catch (Throwable $e) {
            error_log('[theme_zones] add error: ' . $e->getMessage());
            return false;
        }
    }

    function theme_zone_update_item(PDO $pdo, int $id, array $config, string $title = '', ?string $position = null): bool {
        theme_zone_ensure_schema($pdo);
        try {
            if ($position === null) {
                $stmt = $pdo->prepare("UPDATE theme_zone_items SET title = ?, config = ? WHERE id = ?");
                return $stmt->execute([$title, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
            }
            $stmt = $pdo->prepare("UPDATE theme_zone_items SET title = ?, config = ?, position = ? WHERE id = ?");
            return $stmt->execute([$title, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $position, $id]);
        } catch (Throwable $e) {
            error_log('[theme_zones] update error: ' . $e->getMessage());
            return false;
        }
    }

    function theme_zone_move_item(PDO $pdo, int $id, string $position): bool {
        return theme_zone_update_item($pdo, $id, [], '', $position);
    }

    function theme_zone_delete_item(PDO $pdo, int $id): bool {
        try {
            $stmt = $pdo->prepare("DELETE FROM theme_zone_items WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (Throwable $e) {
            error_log('[theme_zones] delete error: ' . $e->getMessage());
            return false;
        }
    }

    function theme_zone_set_order(PDO $pdo, int $id, int $order): bool {
        try {
            $stmt = $pdo->prepare("UPDATE theme_zone_items SET ordering = ? WHERE id = ?");
            return $stmt->execute([$order, $id]);
        } catch (Throwable $e) {
            error_log('[theme_zones] order error: ' . $e->getMessage());
            return false;
        }
    }

    function theme_zone_toggle_item(PDO $pdo, int $id, bool $active): bool {
        try {
            $stmt = $pdo->prepare("UPDATE theme_zone_items SET active = ? WHERE id = ?");
            return $stmt->execute([$active ? 1 : 0, $id]);
        } catch (Throwable $e) {
            error_log('[theme_zones] toggle error: ' . $e->getMessage());
            return false;
        }
    }
}
