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
                $html .= _render_single_widget($pdo, $type, $config);
            } else {
                $html .= render_widget($type, $config, $pdo);
            }
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

    // Widget types intended for theme zones (header/footer). They are registered
    // through the regular sidebar widget system so they can also appear there.
    function theme_zone_default_widget_types(): array {
        return [
            'tz_logo' => [
                'label' => __('Site Logo'),
                'desc'  => __('Logo image with site title fallback. Reads theme_mod("logo") or theme default.'),
                'default_config' => ['use_logo' => true, 'show_title' => false],
            ],
            'tz_nav_menu' => [
                'label' => __('Navigation Menu'),
                'desc'  => __('Renders a menu from Menu Manager.'),
                'default_config' => ['menu' => 'primary', 'menu_class' => 'menu', 'depth' => 1],
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
            case 'tz_logo':
                $logo = function_exists('theme_mod') ? (string)theme_mod('logo', '') : '';
                $showTitle = !empty($config['show_title']);
                $useLogo = !empty($config['use_logo']);
                $customHtml = trim((string)($config['html'] ?? ''));
                $title = htmlspecialchars((string)$site['title'], ENT_QUOTES, 'UTF-8');
                $homeUrl = rtrim((string)($site['url'] ?? '/'), '/') . '/';
                if ($customHtml !== '') {
                    return $customHtml;
                }
                $out = '';
                if ($useLogo && $logo !== '') {
                    $out .= '<a href="' . htmlspecialchars($homeUrl, ENT_QUOTES) . '" class="tz-brand">';
                    $out .= '<img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt="' . $title . '" class="tz-logo" style="max-height:44px;">';
                    $out .= '</a>';
                } elseif ($useLogo || $showTitle) {
                    $out .= '<a href="' . htmlspecialchars($homeUrl, ENT_QUOTES) . '" class="tz-brand">' . $title . '</a>';
                }
                return $out;

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
