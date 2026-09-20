<?php
declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/dashboard/admin/themes/customize.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$signature = 'string $activePartial, int $uid, array $sidebarZones = [], array $pagesList = [], array $articleAuthors = [], array $categoriesList = [], array $themeContentList = []';
$check(str_contains($source, $signature), 'zone editor receives the authorized user ID explicitly');
$editorArguments = '$activePartial, $uid, $zones, $pagesList, $articleAuthors, $categoriesList, $themeContentList)';
$check(substr_count($source, $editorArguments) === 3, 'header, partial, and footer editors pass the authorized user ID and content picker data');
$check(str_contains($source, "'user_id' => \$uid"), 'extension editor actions receive the explicit user ID');
$check(str_contains($source, "(\$definition['addable'] ?? true) === true")
    && substr_count($source, "if (!isset(\$tzAddableWidgets[\$type]))") === 2,
    'Customize hides non-addable gadgets and rejects forged additions and defaults');
$check(str_contains($source, "\$type = \$storedItems[\$wid] ?? '';")
    && str_contains($source, "SELECT id, type FROM theme_zone_items WHERE theme_folder = ? AND zone_slug = ?"),
    'Customize preserves the stored gadget type during saves');
$check(str_contains($source, '$missingThemeContentIds = array_values(array_diff($selectedThemeContentIds, $loadedThemeContentIds));')
    && str_contains($source, 'array_diff($selectedItems, $availableItemIds)'),
    'Theme Content editing retains selected items outside the bounded picker window');
$check(str_contains($source, 'min(5, $cols)') && str_contains($source, '.tz-layout-grid[data-cols="5"]'), 'Customize supports five-column theme layouts');
$check(str_contains($source, '$customizerOnly = empty($themeLayout) && !empty($sections[\'main\'][\'fields\']);'), 'themes may expose main settings without declaring gadget zones');
$check(str_contains($source, '$discoveredPartials = !$customizerOnly && function_exists(\'theme_zone_discover_partials\')'), 'customizer-only themes do not expose implementation partials as gadget zones');
$check(str_contains($source, "<?php if (!\$customizerOnly): ?>\n          <?php \$sidebarOn"), 'customizer-only themes omit the unrelated sidebar panel');
$check(str_contains($source, "\$customizerOnly ? __('Save Changes') : __('Save Main Layout')"), 'customizer-only themes use settings-oriented save copy');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Theme Customize context contract check(s) failed.\n");
    exit(1);
}

echo "Theme Customize context contract passed.\n";
