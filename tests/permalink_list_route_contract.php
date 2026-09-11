<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/lang_helpers.php';

function settings_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    return $GLOBALS['_permalink_list_route_settings'][$key] ?? $default;
}

require_once $root . '/cfg/helpers/permalink_helpers.php';

final class PermalinkListRouteContractPdo extends PDO
{
    public function __construct()
    {
    }
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new PermalinkListRouteContractPdo();
$GLOBALS['_permalink_list_route_settings'] = [
    'posts_list_path' => '/articles/',
    'pages_list_path' => 'pages',
];
$GLOBALS['__APP_DEFAULT_LOCALE'] = 'en';
set_locale('en');

$postFilterContext = null;
$postFilter = static function (mixed $path, mixed $connection) use (&$postFilterContext): string {
    $postFilterContext = [$path, $connection];
    return 'journal/latest';
};
add_filter('posts_list_path', $postFilter);
$check(get_posts_list_path($pdo) === 'journal/latest'
    && $postFilterContext === ['articles', $pdo],
    'post list path filters receive the stored path and active PDO instance');
$check(get_posts_list_base($pdo) === '/journal/latest/',
    'default-locale post list bases remain unprefixed');
remove_filter('posts_list_path', $postFilter);

$pageFilterContext = null;
$malformedPageFilter = static function (mixed $path, mixed $connection) use (&$pageFilterContext): string {
    $pageFilterContext = [$path, $connection];
    return 'Pages With Spaces';
};
add_filter('pages_list_path', $malformedPageFilter);
$check(get_pages_list_path($pdo) === 'pages'
    && $pageFilterContext === ['pages', $pdo],
    'malformed page list filter results fall back to the stored path');
remove_filter('pages_list_path', $malformedPageFilter);

$nonStringPostFilter = static fn(): array => ['journal'];
add_filter('posts_list_path', $nonStringPostFilter);
$check(get_posts_list_path($pdo) === 'articles',
    'non-string post list filter results fall back to the stored path');
remove_filter('posts_list_path', $nonStringPostFilter);

set_locale('de');
$check(get_posts_list_base($pdo) === '/de/articles/' && get_pages_list_base($pdo) === '/de/pages/',
    'active nondefault locales prefix configured post and page list bases');

$pageFilter = static fn(): string => 'library/archive';
add_filter('pages_list_path', $pageFilter);
$check(get_pages_list_path($pdo) === 'library/archive' && get_pages_list_base($pdo) === '/de/library/archive/',
    'valid page list filters feed the localized page list base');
remove_filter('pages_list_path', $pageFilter);

$disabledPostFilter = static fn(): string => '';
add_filter('posts_list_path', $disabledPostFilter);
$check(!is_posts_list_enabled($pdo) && get_posts_list_base($pdo) === '/de/artikel/',
    'an empty filtered post path disables the route and retains the localized fallback base');
remove_filter('posts_list_path', $disabledPostFilter);

$GLOBALS['_permalink_list_route_settings']['posts_list_path'] = '';
$GLOBALS['_permalink_list_route_settings']['pages_list_path'] = '';
$check(!is_posts_list_enabled($pdo) && !is_pages_list_enabled($pdo),
    'empty stored list paths retain existing disabled-route behavior');
$check(get_posts_list_base($pdo) === '/de/artikel/' && get_pages_list_base($pdo) === '/de/halaman/',
    'localized list bases retain existing fallbacks for empty stored paths');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
