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

$xmlMethod = new ReflectionMethod(SitemapController::class, 'contentListXml');
$render = static fn(): string => $xmlMethod->invoke(null, $pdo, 'https://example.test');
$xml = $render();
$check(str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>')
    && str_contains($xml, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
    && substr_count($xml, '<url>') === 2,
    'content list renders a valid sitemap urlset independently of item counts');
$check(str_contains($xml, '<loc>https://example.test/journal/latest/</loc>')
    && str_contains($xml, '<loc>https://example.test/library/</loc>'),
    'enabled nested Post and Page list settings produce canonical landing URLs');

$GLOBALS['_sitemap_collection_settings']['pages_list_path'] = 'journal/latest';
$check(substr_count($render(), '<url>') === 1, 'identical configured collection landing URLs are emitted once');
$GLOBALS['_sitemap_collection_settings']['pages_list_path'] = 'library';

$GLOBALS['_sitemap_collection_settings']['posts_list_path'] = '';
$xml = $render();
$check(!str_contains($xml, '/journal/latest/') && str_contains($xml, '/library/') && substr_count($xml, '<url>') === 1,
    'a disabled Post list is omitted while the enabled Page list remains');
$GLOBALS['_sitemap_collection_settings']['pages_list_path'] = '';
$xml = $render();
$check(substr_count($xml, '<url>') === 0 && str_contains($xml, '<urlset') && str_ends_with($xml, '</urlset>'),
    'both disabled lists produce an empty valid urlset');

$GLOBALS['_sitemap_collection_settings'] = ['posts_list_path' => 'journal/latest', 'pages_list_path' => 'library'];
$nonArray = static fn(array $entries, PDO $activePdo, string $domain): string => 'invalid';
add_filter('sitemap_content_list_entries', $nonArray);
$xml = $render();
$check(substr_count($xml, '<url>') === 2 && str_contains($xml, '/journal/latest/') && str_contains($xml, '/library/'),
    'a non-array filter result fails closed to Core defaults');
remove_filter('sitemap_content_list_entries', $nonArray);

$filteredPdo = null;
$filteredDomain = null;
$malformed = static function (array $entries, PDO $activePdo, string $domain) use (&$filteredPdo, &$filteredDomain): array {
    $filteredPdo = $activePdo;
    $filteredDomain = $domain;
    return [
        ['loc' => 'https://example.test/journal/latest/'],
        ['loc' => 'HTTPS://EXAMPLE.TEST:443/journal/latest/'],
        ['loc' => 'https://example.test/search?q=a&b=c'],
        ['loc' => 'https://foreign.example/path/'],
        ['loc' => 'ftp://example.test/file'],
        ['loc' => 'https://user:pass@example.test/private'],
        ['loc' => 'https://example.test/path#fragment'],
        ['loc' => 'https://example.test/path%0Aother'],
        ['loc' => "https://example.test/path\nother"],
        ['loc' => "https://example.test/path\xFF"],
        ['loc' => str_repeat('x', 2049)],
        ['loc' => 123],
        'not-an-entry',
    ];
};
add_filter('sitemap_content_list_entries', $malformed);
$xml = $render();
remove_filter('sitemap_content_list_entries', $malformed);
$check($filteredPdo === $pdo && $filteredDomain === 'https://example.test',
    'content list filters receive entries, the active PDO, and domain');
$check(substr_count($xml, '<url>') === 2 && substr_count($xml, '/journal/latest/') === 1
    && !str_contains($xml, 'foreign.example') && !str_contains($xml, 'user:pass') && !str_contains($xml, '#fragment'),
    'entries are same-origin, scheme, credential, fragment, control, length, shape, and canonical-dedup validated');
$check(str_contains($xml, '<loc>https://example.test/search?q=a&amp;b=c</loc>'),
    'accepted locations are escaped as XML text');
if (function_exists('simplexml_load_string')) {
    $check(simplexml_load_string($xml) !== false, 'escaped content list output parses as XML');
}

$overLimit = static function (): array {
    $entries = array_fill(0, 50000, null);
    $entries[] = ['loc' => 'https://example.test/not-inspected/'];
    return $entries;
};
add_filter('sitemap_content_list_entries', $overLimit);
$check(!str_contains($render(), '/not-inspected/'), 'content list inspection is bounded to 50,000 entries');
remove_filter('sitemap_content_list_entries', $overLimit);

$controller = (string)file_get_contents($root . '/app/controllers/SitemapController.php');
$router = (string)file_get_contents($root . '/public/router.php');
$collectionHelpers = (string)file_get_contents($root . '/cfg/helpers/collection_helpers.php');
$contentRouteHelpers = (string)file_get_contents($root . '/cfg/helpers/content_route_helpers.php');
$check(substr_count($controller, "\$domain . '/content_list.xml'") === 1
    && substr_count($controller, 'collectionLandingUrl') === 0,
    'sitemap index has one fixed content-list entry and item sitemaps no longer carry collection landings');
$check(str_contains($router, "if (\$rawPath === '/content_list.xml')")
    && str_contains($router, 'SitemapController::contentList($pdo)'),
    'router dispatches only the exact content-list path');
$check(str_contains($collectionHelpers, "\$path === 'content_list.xml'")
    && str_contains($contentRouteHelpers, "\$path === 'content_list.xml'"),
    'content-list route is Core-owned and explicitly reserved from content routes');

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
