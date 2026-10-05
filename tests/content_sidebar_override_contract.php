<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

require_once $root . '/cfg/helpers/settings_helpers.php';
require_once $root . '/cfg/helpers/editor_helpers.php';

$check(content_sidebar_override_normalize('left') === 'left'
    && content_sidebar_override_normalize('right') === 'right'
    && content_sidebar_override_normalize('hide') === 'hide'
    && content_sidebar_override_normalize('invalid') === ''
    && content_sidebar_override_normalize(['left']) === '',
    'content Sidebar overrides use one strict normalizer');

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT, autoload INTEGER NOT NULL DEFAULT 1)');
$pdo->exec("INSERT INTO settings (`key`, `value`, autoload) VALUES ('sidebar_enabled', '0', 1)");
unset($GLOBALS['__jy_settings_autoload_cache']);
$check(content_sidebar_overrides_enabled($pdo) === false,
    'content Sidebar controls follow an explicitly disabled global master');
$pdo->exec("UPDATE settings SET `value` = '1' WHERE `key` = 'sidebar_enabled'");
unset($GLOBALS['__jy_settings_autoload_cache']);
$check(content_sidebar_overrides_enabled($pdo) === true,
    'content Sidebar controls remain available when the global master is enabled');
$pdo->exec("DELETE FROM settings WHERE `key` = 'sidebar_enabled'");
unset($GLOBALS['__jy_settings_autoload_cache']);
$check(content_sidebar_overrides_enabled($pdo) === true,
    'missing global Sidebar state retains the enabled compatibility default');

$editors = [
    'article add' => $read('dashboard/admin/posts/add.php'),
    'article edit' => $read('dashboard/admin/posts/edit.php'),
    'page add' => $read('dashboard/admin/pages/add.php'),
    'page edit' => $read('dashboard/admin/pages/edit.php'),
];
foreach ($editors as $label => $source) {
    $check(str_contains($source, 'content_sidebar_overrides_enabled($pdo)')
        && str_contains($source, 'data-content-sidebar-override>')
        && str_contains($source, 'name="sidebar_override" class="inp inp-w100"')
        && str_contains($source, 'data-content-sidebar-override-preserved>'),
        $label . ' hides the visual override while preserving its normalized value');
}

foreach (['article add', 'page add'] as $label) {
    $source = $editors[$label];
    $check(str_contains($source, "\$sidebarOverrideValue = content_sidebar_override_normalize(\$_POST['sidebar_override'] ?? '')")
        && str_contains($source, "\$sidebarOverrideValue === 'right' ? 'selected' : '' ?>>")
        && str_contains($source, "\$sidebarOverrideValue === 'left' ? 'selected' : '' ?>>")
        && str_contains($source, "\$sidebarOverrideValue === 'hide' ? 'selected' : '' ?>>"),
        $label . ' restores valid submitted choices with well-formed options');
}

foreach (['article edit', 'page edit'] as $label) {
    $check(str_contains($editors[$label], "content_sidebar_override_normalize(\$_POST['sidebar_override'] ?? \$current_sidebar)"),
        $label . ' preserves stored or rejected-request override state');
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " content Sidebar override contract check(s) failed.\n");
    exit(1);
}

echo "Content Sidebar override contract passed ({$checks} checks).\n";
