<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/settings_helpers.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT, `autoload` INTEGER NOT NULL DEFAULT 1)');
$resetSettings = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM settings');
    unset($GLOBALS['__jy_settings_autoload_cache']);
};
$putSetting = static function (string $key, string $value) use ($pdo): void {
    $statement = $pdo->prepare('INSERT INTO settings (`key`, `value`, `autoload`) VALUES (?, ?, 1)');
    $statement->execute([$key, $value]);
    unset($GLOBALS['__jy_settings_autoload_cache']);
};

$resetSettings();
$check(site_search_engine_indexing_allowed($pdo), 'search indexing is enabled by default');
$check(site_robots_txt_content($pdo, 'https://example.test') === "User-agent: *\nAllow: /\nSitemap: https://example.test/sitemap.xml\n",
    'empty configuration generates an allow policy with the canonical sitemap');

$resetSettings();
$putSetting('robots_txt_custom', "User-agent: ExampleBot\r\nDisallow: /private\r\n");
$check(site_robots_txt_content($pdo, 'https://example.test') === "User-agent: ExampleBot\nDisallow: /private\n",
    'custom crawler rules are preserved with normalized line endings');

$resetSettings();
$putSetting('search_engine_indexing', '0');
$putSetting('robots_txt_custom', "User-agent: *\nAllow: /\n");
$check(!site_search_engine_indexing_allowed($pdo)
    && site_robots_txt_content($pdo, 'https://example.test') === "User-agent: *\nDisallow: /\n",
    'disabled indexing forces the blocking Core policy over custom rules');

$check(settings_robots_txt_validation_error(str_repeat('a', 16385)) !== null
    && settings_robots_txt_validation_error("User-agent: *\nDisallow: /\0") !== null
    && settings_robots_txt_validation_error("User-agent: *\nAllow: /\n") === null,
    'robots rules reject oversized and control-character input while accepting bounded UTF-8 text');

$router = (string)file_get_contents($root . '/public/router.php');
$collectionHelpers = (string)file_get_contents($root . '/cfg/helpers/collection_helpers.php');
$contentRouteHelpers = (string)file_get_contents($root . '/cfg/helpers/content_route_helpers.php');
$layout = (string)file_get_contents($root . '/app/layout.php');
$settings = (string)file_get_contents($root . '/dashboard/admin/settings/site.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');

$check(str_contains($router, "if (\$pathTrimmed === 'robots.txt')")
    && str_contains($router, "['GET', 'HEAD']")
    && str_contains($router, "header('Content-Type: text/plain; charset=utf-8')")
    && str_contains($router, 'site_robots_txt_content($pdo'),
    'Core owns a plain-text GET and HEAD robots.txt route');
$check(str_contains($collectionHelpers, "\$path === 'robots.txt'")
    && str_contains($contentRouteHelpers, "\$path === 'robots.txt'"),
    'plugin rewrites and content routes cannot claim the Core robots.txt path');
$check(substr_count($layout, '<meta name="robots"') === 1
    && str_contains($layout, "header('X-Robots-Tag: ' . \$robotsMeta)")
    && str_contains($layout, 'site_search_engine_indexing_allowed($pdo)'),
    'frontend emits one centralized robots meta value and matching noindex header');

$searchSection = strpos($settings, '<!-- Search Engines -->');
$metaSection = strpos($settings, '<!-- Meta Tags -->');
$check($searchSection !== false && $metaSection !== false && $searchSection < $metaSection
    && str_contains($settings, 'name="search_engine_indexing"')
    && str_contains($settings, 'name="robots_txt_custom"'),
    'Site Settings places indexing and robots controls immediately above Meta Tags');

foreach ([
    'Search Engines',
    'Allow search engines to index this site',
    'When disabled, Core serves a blocking robots.txt policy and adds noindex,nofollow to public pages.',
    'Custom robots.txt rules',
    'Leave empty to generate a default policy that allows crawling and advertises /sitemap.xml.',
    'When indexing is disabled, the blocking Core policy overrides these custom rules.',
    'robots.txt guides cooperative crawlers and is not an access-control mechanism.',
    'View robots.txt',
    'Robots.txt must be valid UTF-8 text, contain no control characters, and not exceed 16 KiB.',
] as $source) {
    $escaped = str_replace("'", "''", $source);
    $check(substr_count($translations, "'{$escaped}'") >= 2, "robots UI translation coverage: {$source}");
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " robots.txt contract check(s) failed.\n");
    exit(1);
}
echo "Robots.txt contract passed.\n";
