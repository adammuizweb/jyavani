<?php
declare(strict_types=1);

/**
 * Restore CMS-managed presentation and access settings to their defaults.
 * Content, users, uploads, and plugin files are intentionally preserved.
 */
function cms_reinstall_hard_reset_plugin_names(): array
{
    if (!function_exists('plugins_all')) {
        require_once dirname(DASH_PATH) . '/plugins/index.php';
    }
    $names = array_keys(plugins_all());
    $names = array_values(array_filter($names, static fn(mixed $name): bool => is_string($name)
        && preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) === 1));
    sort($names, SORT_STRING);
    return $names;
}

function cms_reinstall_hard_reset(PDO $pdo): array
{
    if ($pdo->inTransaction()) throw new RuntimeException(__('Hard reset requires transaction ownership.'));
    $messages = [];
    $pluginNames = cms_reinstall_hard_reset_plugin_names();
    if (!_plugin_write_disabled_names_already_locked($pluginNames)) {
        throw new RuntimeException(__('Failed to persist disabled plugin state.'));
    }
    plugin_reset_runtime_cache();
    if ($pluginNames !== []) $messages[] = count($pluginNames) . ' ' . __('plugins disabled') . '.';

    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE settings SET value = 'default' WHERE key = 'active_theme'");
        $messages[] = __('Theme reset to default.');

        $pdo->exec("UPDATE themes SET is_active = 0");
        $pdo->exec("UPDATE themes SET is_active = 1 WHERE id = 1 OR folder_name = 'default'");
        $pdo->exec("DELETE FROM assignments");
        $pdo->exec("INSERT INTO assignments (slot_key, theme_id, theme_file) VALUES
            ('header', 1, 'header.php'),
            ('footer', 1, 'footer.php'),
            ('sidebar', 1, 'sidebar.php'),
            ('main.homepage', 1, 'main/homepage.php')");
        $messages[] = __('Slot assignments reset.');

        $pdo->exec("DELETE FROM sidebar_zone_items");
        $zoneId = $pdo->query("SELECT id FROM sidebar_zones WHERE is_primary = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($zoneId) {
            $pdo->prepare("INSERT IGNORE INTO sidebar_zone_items (zone_id, type, title, config, ordering, active) VALUES
                (?, 'search', 'Cari', '{\"title\":\"Cari\",\"placeholder\":\"Cari artikel...\"}', 0, 1),
                (?, 'last_posts', 'Artikel Terbaru', '{\"title\":\"Artikel Terbaru\",\"limit\":5,\"type\":\"article\"}', 1, 1)")->execute([$zoneId, $zoneId]);
            $messages[] = __('Sidebar items reset.');
        }

        $menuId = $pdo->query("SELECT id FROM menus WHERE is_default = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($menuId) {
            $pdo->exec("DELETE FROM menu_items WHERE menu_id = " . (int)$menuId);
            $menuItem = $pdo->prepare("INSERT INTO menu_items (menu_id, parent_id, sort_order, type, label, url, target_id, target_blank)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
            $menuItem->execute([$menuId, null, 0, 'custom', 'Home', '/', null]);
            $menuItem->execute([$menuId, null, 1, 'category', 'Panduan', '', 1]);
            $menuItem->execute([$menuId, null, 2, 'category', 'Sistem', '', 4]);
            $systemItemId = (int)$pdo->lastInsertId();
            $menuItem->execute([$menuId, $systemItemId, 0, 'category', 'Keamanan', '', 2]);
            $menuItem->execute([$menuId, null, 3, 'category', 'Pengembangan', '', 3]);
            $messages[] = __('Menu items reset.');
        }

        $pdo->exec("REPLACE INTO settings (`key`, `value`, `autoload`) VALUES
            ('admin_path', 'dashboard', 1),
            ('login_path', 'login', 1),
            ('register_path', 'register', 1)");
        $messages[] = __('Auth paths reset (admin: /dashboard/, login: /login/).');

        $pdo->exec("REPLACE INTO settings (`key`, `value`, `autoload`) VALUES ('posts_per_page', '10', 1)");
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    return $messages;
}
