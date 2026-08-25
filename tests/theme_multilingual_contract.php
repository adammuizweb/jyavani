<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/lang_helpers.php';
require_once $root . '/cfg/helpers/theme_zones.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE ui_translations (scope TEXT, source TEXT, locale TEXT, value TEXT)');
$pdo->exec('CREATE TABLE theme_zone_items (id INTEGER PRIMARY KEY, theme_folder TEXT, zone_slug TEXT, position TEXT, type TEXT, title TEXT, config TEXT, ordering INTEGER, active INTEGER)');
$pdo->exec("INSERT INTO theme_zone_items VALUES (7, 'portfolio', 'footer', 'about', 'tz_html', 'About', '{\"title\":\"About\",\"html\":\"<p>Source</p>\"}', 1, 1)");
$GLOBALS['pdo'] = $pdo;

$localizedContext = null;
add_filter('localized_string', static function ($value, $source, $scope, $locale, $context) use (&$localizedContext) {
    if ($source !== 'Source string') return $value;
    $localizedContext = $context;
    return 'Translated string';
});
$GLOBALS['__jy_render_theme_source_folder'] = 'default';
$GLOBALS['__jy_render_theme_folder'] = 'portfolio';
$GLOBALS['__jy_render_slot_key'] = 'main.homepage';
set_locale('en');
$check(__('Source string') === 'Translated string'
    && ($localizedContext['theme_folder'] ?? null) === 'default'
    && ($localizedContext['requested_theme_folder'] ?? null) === 'portfolio'
    && ($localizedContext['slot_key'] ?? null) === 'main.homepage',
    'localized_string runs for English targets with physical and requested theme context');

set_locale('de');
$check(localized_home_url('/') === '/de/' && localized_path_url('/category/news/') === '/de/category/news/'
    && localized_path_url('/de/category/news/') === '/de/category/news/',
    'generic locale URL helpers prefix root and internal paths exactly once');
add_filter('content_default_locale', static fn(string $locale): string => 'id');
set_locale('id');
$check(localized_home_url('/') === '/' && localized_path_url('/artikel/') === '/artikel/',
    'locale URL helpers honor the filterable content default locale');
set_locale('de');

$filterContext = null;
add_filter('theme_zone_items', static function ($rows, $folder, $zone, $position, $activeOnly, $connection) use (&$filterContext) {
    $filterContext = [$folder, $zone, $position, $activeOnly, $connection instanceof PDO, (int)($rows[0]['id'] ?? 0)];
    return $rows;
});
$rows = theme_zone_items($pdo, 'footer', 'about', 'portfolio');
$check(count($rows) === 1 && $filterContext === ['portfolio', 'footer', 'about', true, true, 7],
    'bulk Theme Zone filter retains stable item identity and complete render context');

$htmlSchema = theme_zone_translatable_config('tz_html');
$searchSchema = theme_zone_translatable_config('tz_search');
$imageSchema = theme_zone_translatable_config('tz_image');
$check(array_keys($htmlSchema) === ['title', 'html'] && array_keys($searchSchema) === ['placeholder']
    && array_keys($imageSchema) === ['alt'] && !isset($searchSchema['button']) && !isset($imageSchema['link']),
    'widget registry declares human text without exposing URL or behavior keys');
$check(str_contains(theme_zone_localize_root_urls('<a href="/">Home</a><form action="/">'), 'href="/de/"')
    && str_contains(theme_zone_localize_root_urls('<a href="/">Home</a><form action="/">'), 'action="/de/"'),
    'stored Theme Zone HTML localizes only exact root link and form attributes');

$themeHelper = (string)file_get_contents($root . '/cfg/helpers/theme_helper.php');
$customize = (string)file_get_contents($root . '/dashboard/admin/themes/customize.php');
$zones = (string)file_get_contents($root . '/cfg/helpers/theme_zones.php');
$lang = (string)file_get_contents($root . '/cfg/helpers/lang_helpers.php');
$check(str_contains($themeHelper, '__jy_render_theme_source_folder') && str_contains($themeHelper, '$relativePath'),
    'slot renderer derives and scopes the physical theme source owner');
$check(str_contains($customize, "do_action('theme_zone_item_editor_actions'")
    && str_contains($customize, "'widget_definition' => \$typeInfo"),
    'Customize exposes a generic item action without owning translation UI');
$check(str_contains($zones, "do_action('theme_zone_item_before_delete'")
    && str_contains($zones, 'SELECT id FROM theme_zone_items WHERE id = ? LIMIT 1 FOR UPDATE')
    && str_contains($zones, '$pdo->beginTransaction()'),
    'Theme Zone deletion locks the source and runs owner cleanup in one transaction');
$check(preg_match('/(?<![A-Za-z0-9_])ct_[A-Za-z0-9_]+/', $zones . $lang . $themeHelper . $customize) !== 1,
    'Core multilingual contracts contain no Content Translation symbols');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
