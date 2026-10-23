<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/cfg/helpers/url_helpers.php');
$router = (string)file_get_contents($root . '/public/router.php');
$layout = (string)file_get_contents($root . '/app/layout.php');
$sitemap = (string)file_get_contents($root . '/app/controllers/SitemapController.php');
$canonicalControllers = array_map(
    static fn(string $file): string => (string)file_get_contents($root . '/app/controllers/' . $file),
    ['ArchiveController.php', 'AuthorController.php', 'PageController.php', 'PostController.php', 'SearchController.php', 'ThemeController.php', 'PluginStoreController.php']
);
$helperPath = $root . '/cfg/helpers/url_helpers.php';
require_once $helperPath;
$_SERVER['HTTP_HOST'] = 'attacker.example';
$fallbackOrigin = app_site_origin();
unset($_SERVER['HTTP_HOST']);
$checks = [
    str_contains($source, 'function app_site_origin(?PDO $pdo = null)')
        && str_contains($source, "settings_get(\$pdo, 'site_url', '')")
        && str_contains($source, "settings_get(\$pdo, 'site_host', '')"),
    str_contains($source, 'isset($parts[\'user\'])')
        && str_contains($source, 'isset($parts[\'fragment\'])'),
    $fallbackOrigin === 'http://localhost',
    str_contains($router, 'app_site_origin($pdo)') && !str_contains($router, '$host = $_SERVER[\'HTTP_HOST\']'),
    str_contains($layout, 'app_site_origin($pdo)') && !str_contains($layout, 'HTTP_HOST'),
    str_contains($sitemap, 'app_site_origin()'),
    !array_filter($canonicalControllers, static fn(string $source): bool => str_contains($source, 'HTTP_HOST')),
];

foreach ($checks as $index => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' trusted site origin check ' . ($index + 1) . PHP_EOL;
}
if (in_array(false, $checks, true)) exit(1);
echo "RESULT: ALL PASS\n";
