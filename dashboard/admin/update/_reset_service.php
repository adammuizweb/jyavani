<?php
declare(strict_types=1);

/**
 * Restore CMS-managed presentation and access settings to their defaults.
 * Content, users, uploads, and plugin files are intentionally preserved.
 */
function cms_reinstall_hard_reset(PDO $pdo): array
{
    $messages = [];

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
    $zoneId = $pdo->query("SELECT id FROM sidebar_zones WHERE slug = 'main'")->fetchColumn();
    if ($zoneId) {
        $pdo->prepare("INSERT IGNORE INTO sidebar_zone_items (zone_id, type, title, config, ordering, active) VALUES
            (?, 'search', 'Search', '{\"title\":\"Search\",\"placeholder\":\"Search articles...\"}', 0, 1),
            (?, 'last_posts', 'Recent Articles', '{\"title\":\"Recent Articles\",\"limit\":5,\"type\":\"article\"}', 1, 1),
            (?, 'categories', 'Categories', '{\"title\":\"Categories\",\"limit\":30,\"only_parents\":true}', 2, 1)")->execute([$zoneId, $zoneId, $zoneId]);
        $messages[] = __('Sidebar items reset.');
    }

    $menuId = $pdo->query("SELECT id FROM menus WHERE slug = 'primary'")->fetchColumn();
    if ($menuId) {
        $pdo->exec("DELETE FROM menu_items WHERE menu_id = " . (int)$menuId);
        $pdo->prepare("INSERT INTO menu_items (menu_id, parent_id, sort_order, type, label, url, target_id, target_blank) VALUES
            (?, NULL, 0, 'custom', 'Home', '/', NULL, 0),
            (?, NULL, 1, 'category', 'Blog', NULL, 1, 0),
            (?, NULL, 2, 'category', 'Services', NULL, 2, 0)")->execute([$menuId, $menuId, $menuId]);
        $messages[] = __('Menu items reset.');
    }

    $pdo->exec("REPLACE INTO settings (`key`, `value`, `autoload`) VALUES
        ('admin_path', 'dashboard', 1),
        ('login_path', 'login', 1),
        ('register_path', 'register', 1)");
    $messages[] = __('Auth paths reset (admin: /dashboard/, login: /login/).');

    $pluginDir = dirname(DASH_PATH) . '/plugins';
    $pluginNames = [];
    foreach (glob($pluginDir . '/*/plugin.json') as $manifestFile) {
        $name = basename(dirname($manifestFile));
        if ($name !== '') $pluginNames[] = $name;
    }
    if ($pluginNames !== []) {
        $disabledFile = dirname(DASH_PATH) . '/cfg/var/plugins-disabled.json';
        file_put_contents($disabledFile, json_encode($pluginNames), LOCK_EX);
        $messages[] = count($pluginNames) . ' ' . __('plugins disabled') . '.';
    }

    $pdo->exec("REPLACE INTO settings (`key`, `value`, `autoload`) VALUES ('posts_per_page', '10', 1)");

    return $messages;
}
