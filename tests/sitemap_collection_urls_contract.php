<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/lang_helpers.php';

function settings_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    return $GLOBALS['_sitemap_collection_settings'][$key] ?? $default;
}

require_once $root . '/cfg/helpers/permalink_helpers.php';
require_once $root . '/app/controllers/SitemapController.php';

final class SitemapCollectionPdo extends PDO
{
    public function __construct() {}
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new SitemapCollectionPdo();
$GLOBALS['__APP_DEFAULT_LOCALE'] = 'en';
$GLOBALS['_sitemap_collection_settings'] = [
    'posts_list_path' => 'journal/latest',
    'pages_list_path' => 'library',
];
set_locale('en');

$method = new ReflectionMethod(SitemapController::class, 'collectionLandingUrl');
$check($method->invoke(null, $pdo, 'posts', 1, 'https://example.test') === 'https://example.test/journal/latest/',
    'first Post sitemap includes the configured collection landing URL');
$check($method->invoke(null, $pdo, 'pages', 1, 'https://example.test/') === 'https://example.test/library/',
    'first Page sitemap includes the configured collection landing URL');
$check($method->invoke(null, $pdo, 'posts', 2, 'https://example.test') === null,
    'later sitemap files do not repeat the collection landing URL');
$check($method->invoke(null, $pdo, 'themes', 1, 'https://example.test') === null,
    'Theme sitemaps have no collection landing URL');

$GLOBALS['_sitemap_collection_settings']['posts_list_path'] = '';
$GLOBALS['_sitemap_collection_settings']['pages_list_path'] = '';
$check($method->invoke(null, $pdo, 'posts', 1, 'https://example.test') === null
    && $method->invoke(null, $pdo, 'pages', 1, 'https://example.test') === null,
    'disabled collections are omitted from sitemaps');

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
