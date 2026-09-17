<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$pages = [
    'dashboard home' => ['dashboard/theme/adiwira/part/views/home.php', 'class="dw-heading-title page-heading"'],
    'category add' => ['dashboard/admin/categories/add.php', '<h2 class="edit-heading"'],
    'media manager' => ['dashboard/admin/media/index.php', '<h2 class="page-heading page-heading--spaced"'],
    'file manager' => ['dashboard/admin/file/index.php', '<h2 class="page-heading page-heading--spaced"'],
    'theme add' => ['dashboard/admin/themes/add.php', '<h2 class="edit-heading"'],
    'theme customize' => ['dashboard/admin/themes/customize.php', '<h2 class="page-heading page-heading--compact"'],
    'theme assignments' => ['dashboard/admin/themes/assign.php', 'class="tm-title page-heading page-heading--compact"'],
    'theme browser' => ['dashboard/admin/themes/browse.php', 'class="pg-title page-heading page-heading--compact"'],
    'shortcode manager' => ['dashboard/admin/shortcodes/index.php', '<h2 class="page-heading page-heading--spaced"'],
    'sidebar settings' => ['dashboard/admin/settings/sidebar.php', '<h2 class="edit-heading edit-heading--compact"><?=_e(\'Sidebar\')?>'],
    'sidebar zones' => ['dashboard/admin/sidebar/index.php', '<h2 class="page-heading page-heading--compact"><?=_e(\'Sidebar Zones\')?>'],
    'menu manager' => ['dashboard/admin/menus/index.php', '<h2 class="page-heading page-heading--compact"'],
    'role manager' => ['dashboard/admin/users/roles/index.php', '<h1 class="page-heading"'],
    'plugin manager' => ['dashboard/admin/plugins/index.php', 'class="pg-title page-heading page-heading--compact"'],
    'plugin browser' => ['dashboard/admin/plugins/browse.php', 'class="pg-title page-heading page-heading--compact"'],
    'update manager' => ['dashboard/admin/update/index.php', 'class="pg-title page-heading"'],
];

$failures = [];
foreach ($pages as $label => [$file, $expected]) {
    $passed = str_contains($read($file), $expected);
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . ' uses the standard page-level heading treatment' . PHP_EOL;
    if (!$passed) $failures[] = $label;
}

$translations = $read('schema/translations.sql');
$translated = substr_count($translations, "'Sidebar Zones'") >= 2;
echo ($translated ? 'PASS' : 'FAIL') . ' Sidebar Zones has Indonesian and German translation seeds' . PHP_EOL;
if (!$translated) $failures[] = 'Sidebar Zones translations';

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " dashboard page heading contract check(s) failed.\n");
    exit(1);
}
echo "Dashboard page heading contract passed.\n";
