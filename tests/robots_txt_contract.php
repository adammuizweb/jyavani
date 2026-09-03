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
$check(site_search_engines_enabled($pdo) && site_search_engine_indexing_allowed($pdo), 'Core search controls and indexing are enabled by default');
$check(site_robots_txt_content($pdo, 'https://example.test') === "User-agent: *\nAllow: /\nSitemap: https://example.test/sitemap.xml\n",
    'empty configuration generates an allow policy with the canonical sitemap');

$resetSettings();
$putSetting('robots_txt_custom', "User-agent: ExampleBot\r\nDisallow: /private\r\n");
$check(site_robots_txt_content($pdo, 'https://example.test') === "User-agent: ExampleBot\nDisallow: /private\n",
    'custom crawler rules are preserved with normalized line endings');

$resetSettings();
$putSetting('search_engine_indexing', '0');
$check(site_robots_txt_content($pdo, 'https://example.test') === "User-agent: *\nDisallow: /\n",
    'disallow policy generates a blocking fallback when custom rules are empty');
$putSetting('robots_txt_custom', "User-agent: *\nAllow: /\n");
$check(!site_search_engine_indexing_allowed($pdo)
    && site_robots_txt_content($pdo, 'https://example.test') === "User-agent: *\nAllow: /\n",
    'custom crawler rules remain intact while noindex policy is selected');

$resetSettings();
$putSetting('search_engines_enabled', '0');
$check(!site_search_engines_enabled($pdo), 'the master control can release Core robots output without changing saved policy or rules');

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

$check(str_contains($router, "if (\$pathTrimmed === 'robots.txt'")
    && str_contains($router, 'site_search_engines_enabled($pdo)')
    && str_contains($router, "['GET', 'HEAD']")
    && str_contains($router, "header('Content-Type: text/plain; charset=utf-8')")
    && str_contains($router, 'site_robots_txt_content($pdo'),
    'Core serves a plain-text GET and HEAD robots.txt route only while its controls are enabled');
$check(str_contains($collectionHelpers, "\$path === 'robots.txt'")
    && str_contains($contentRouteHelpers, "\$path === 'robots.txt'"),
    'plugin rewrites and content routes cannot claim the Core robots.txt path');
$check(substr_count($layout, '<meta name="robots"') === 1
    && str_contains($layout, "header('X-Robots-Tag: ' . \$robotsMeta)")
    && str_contains($layout, 'if ($searchEnginesEnabled):')
    && str_contains($layout, 'site_search_engine_indexing_allowed($pdo)'),
    'frontend conditionally emits one centralized robots meta value and matching noindex header');

$searchSection = strpos($settings, '<!-- Search Engines -->');
$metaSection = strpos($settings, '<!-- Meta Tags -->');
$check($searchSection !== false && $metaSection !== false && $searchSection < $metaSection
    && str_contains($settings, 'name="search_engines_enabled"')
    && str_contains($settings, 'name="search_engine_indexing"')
    && str_contains($settings, 'name="robots_txt_custom"')
    && str_contains($settings, "current !== normalized(allowTemplate)")
    && str_contains($settings, "current !== normalized(disallowTemplate)"),
    'Site Settings separates the master control, exclusive policy, and non-destructive custom rules above Meta Tags');

foreach ([
    'Search Engines',
    'Enable Core search engine controls',
    'When disabled, Core omits robots directives and leaves /robots.txt available to a physical file or plugin route.',
    'Indexing policy',
    'Allow Index',
    'Publishes index,follow and an allow-crawling fallback.',
    'Disallow Crawling',
    'Publishes noindex,nofollow and a disallow-crawling fallback.',
    'Custom robots.txt rules',
    'Leave empty to generate the fallback for the selected policy. Custom rules take precedence over that fallback, while the policy still controls the robots meta tag.',
    'Custom rules are preserved when the controls or policy change. A preset replaces only an empty or unchanged default template.',
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
