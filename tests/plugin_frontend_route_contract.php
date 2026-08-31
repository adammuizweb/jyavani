<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/plugin-route-contract-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('BACKEND_PATH', $fixture . '/cfg');
define('PUBLIC_PATH', $fixture . '/public');
define('PLUGIN_PATH', $fixture . '/plugins');
define('PLUGIN_DISABLED_JSON', BACKEND_PATH . '/var/plugins-disabled.json');

require_once $root . '/cfg/helpers/hooks.php';
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    $paths = $GLOBALS['_plugin_route_core_paths'] ?? [];
    return match ($key) {
        'permalink_posts' => (string)($GLOBALS['_plugin_route_permalink_structure'] ?? '/%slug%/'),
        'category_path' => (string)(($paths['category'] ?? [])[0] ?? ''),
        'posts_list_path' => (string)(($paths['posts'] ?? [])[0] ?? ''),
        'pages_list_path' => (string)(($paths['pages'] ?? [])[0] ?? ''),
        default => $default,
    };
}
require_once $root . '/cfg/helpers/permalink_helpers.php';
require_once $root . '/cfg/helpers/collection_helpers.php';
require_once $root . '/plugins/index.php';

final class PluginFrontendRouteContractPdo extends PDO
{
    public function __construct() {}
}

$GLOBALS['_plugin_route_core_paths'] = [
    'admin' => '/admin/control-room',
    'login' => 'account/sign-in',
    'register' => 'account/join',
    'category' => ['topics'],
    'posts' => ['journal', 'posts'],
    'pages' => ['documents'],
];
function get_admin_path(PDO $pdo): string { return $GLOBALS['_plugin_route_core_paths']['admin']; }
function get_login_path(PDO $pdo): string { return $GLOBALS['_plugin_route_core_paths']['login']; }
function get_register_path(PDO $pdo): string { return $GLOBALS['_plugin_route_core_paths']['register']; }

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$reset = static function (): void {
    $GLOBALS['_plugin_frontend_routes'] = [];
    $GLOBALS['_plugin_frontend_route_definitions'] = [];
    $GLOBALS['_plugin_frontend_route_order'] = 0;
    $GLOBALS['_plugin_frontend_route_diagnostics'] = [];
    $GLOBALS['_plugin_frontend_routes_sealed'] = false;
    $GLOBALS['_plugin_frontend_init_running'] = false;
    $GLOBALS['__jy_frontend_init_fired'] = false;
};

$legacyHandler = static function (): void {};
$check(register_frontend_route('/shop/', $legacyHandler), 'legacy two-argument route registration succeeds');
$legacyBase = resolve_frontend_route('shop', 'DELETE');
$legacyChild = resolve_frontend_route('shop/orders/42', 'PATCH');
$check(($legacyBase['handler'] ?? null) === $legacyHandler && ($legacyChild['handler'] ?? null) === $legacyHandler,
    'legacy routes match the base and segment-boundary descendants for every method');
$check(resolve_frontend_route('shopping', 'GET') === null, 'prefix routes do not match partial path segments');
$check((get_frontend_routes()['shop'] ?? null) === $legacyHandler && match_frontend_route('/shop/') === $legacyHandler,
    'legacy route lookup APIs retain their path-to-handler shape');
$check(register_frontend_route('shop', $legacyHandler) && count(get_frontend_route_definitions()) === 1,
    'repeating an identical registration is an idempotent no-op');

$initCalls = 0;
$lateRegistration = null;
add_action('init', static function () use (&$initCalls, &$lateRegistration): void {
    $initCalls++;
    $lateRegistration = register_frontend_route('too-late', static function (): void {});
});
$GLOBALS['_plugin_frontend_routes_sealed'] = true;
plugin_run_frontend_init();
plugin_run_frontend_init();
$check($initCalls === 1, 'frontend init runs at most once during a request');
$check($lateRegistration === true && resolve_frontend_route('too-late', 'GET') !== null,
    'legacy routes registered by an init callback remain compatible');
$check(!register_frontend_route('too-late-direct', static function (): void {}),
    'route registration is sealed again after frontend init');

$reset();
$rootHandler = static function (): void {};
$check(!register_frontend_route('/', $rootHandler), 'root prefix registration is rejected');
$check(register_frontend_route('/', $rootHandler, ['match' => 'exact', 'methods' => ['GET']]), 'an exact root route can be registered');
$check((resolve_frontend_route('', 'GET')['handler'] ?? null) === $rootHandler
    && (resolve_frontend_route('/', 'HEAD')['handler'] ?? null) === $rootHandler,
    'exact root route resolves GET and its implicit HEAD method');
$check(resolve_frontend_route('child', 'GET') === null, 'exact root route does not capture descendants');

$reset();
$prefixHandler = static function (): void {};
$nestedHandler = static function (): void {};
$exactHandler = static function (): void {};
register_frontend_route('api', $prefixHandler);
register_frontend_route('api/orders', $nestedHandler);
register_frontend_route('api/orders', $exactHandler, ['match' => 'exact']);
$check((resolve_frontend_route('api/customers', 'GET')['handler'] ?? null) === $prefixHandler,
    'prefix routes match descendants');
$check((resolve_frontend_route('api/orders/42', 'GET')['handler'] ?? null) === $nestedHandler,
    'the longest matching prefix wins');
$check((resolve_frontend_route('api/orders', 'GET')['handler'] ?? null) === $exactHandler,
    'an exact route wins over a prefix at the same path');

$reset();
$getHandler = static function (): void {};
$postHandler = static function (): void {};
$check(register_frontend_route('account', $getHandler, ['match' => 'exact', 'methods' => ['get']])
    && register_frontend_route('account', $postHandler, ['match' => 'exact', 'methods' => ['POST']]),
    'disjoint method handlers can share an exact path and priority');
$check((resolve_frontend_route('account', 'HEAD')['handler'] ?? null) === $getHandler
    && (resolve_frontend_route('account', 'POST')['handler'] ?? null) === $postHandler,
    'method-aware resolution selects the compatible handler and treats GET as HEAD-capable');
$methodMismatch = resolve_frontend_route('account', 'PUT');
$check(($methodMismatch['method_allowed'] ?? true) === false
    && ($methodMismatch['allowed_methods'] ?? []) === ['GET', 'HEAD', 'POST'],
    'an owned path with no matching method returns a deterministic Allow set');

$reset();
$laterHandler = static function (): void {};
$priorityHandler = static function (): void {};
register_frontend_route('priority', $laterHandler, ['match' => 'exact', 'methods' => ['GET'], 'priority' => 20]);
register_frontend_route('priority', $priorityHandler, ['match' => 'exact', 'methods' => ['GET'], 'priority' => 5]);
$check((resolve_frontend_route('priority', 'GET')['handler'] ?? null) === $priorityHandler,
    'lower numeric priority wins among compatible routes with equal specificity');

$reset();
$firstHandler = static function (): void {};
$conflictingHandler = static function (): void {};
$check(register_frontend_route('collision', $firstHandler, ['methods' => ['GET']])
    && !register_frontend_route('collision', $conflictingHandler, ['methods' => ['HEAD']]),
    'overlapping methods at the same path, mode, and priority are rejected');
$check((resolve_frontend_route('collision', 'GET')['handler'] ?? null) === $firstHandler
    && count(get_frontend_route_diagnostics()) === 1,
    'a rejected collision preserves the first route and records a diagnostic');

$reset();
$thiefHandler = static function (): void {};
register_frontend_route('stolen', $thiefHandler, ['match' => 'exact']);
$pdo = new PluginFrontendRouteContractPdo();
$hostileFilter = static fn(string $path): string => 'stolen';
add_filter('router_path', $hostileFilter);
$protectedPaths = [
    'sw.js' => 'Core service worker path',
    'author/editor' => 'Core author path',
    'private/file/stream' => 'Core private file path',
    'static/dashboard/app.js' => 'Core static path',
    'sitemap.xml' => 'Core sitemap path',
    'sitemap_pt-BR_themes_2.xml' => 'localized Core sitemap path',
    'admin/control-room/settings' => 'configured admin path',
    'account/sign-in' => 'configured login path',
    'account/join' => 'configured register path',
    'journal/p/2' => 'configured post collection path',
    'documents/p/2' => 'configured page collection path',
    'topics/guides' => 'configured category collection path',
];
foreach ($protectedPaths as $protectedPath => $label) {
    $filteredPath = router_apply_path_filter($pdo, $protectedPath);
    $check($filteredPath === $protectedPath && resolve_frontend_route($filteredPath, 'GET') === null,
        'plugin path rewrites cannot steal the ' . $label);
}
$filteredPluginPath = router_apply_path_filter($pdo, 'extension-alias');
$check($filteredPluginPath === 'stolen'
    && (resolve_frontend_route($filteredPluginPath, 'GET')['handler'] ?? null) === $thiefHandler,
    'the hostile path filter is active for non-Core paths');

$yearMonthStructure = '/%year%/%monthnum%/%slug%/';
foreach (['1' => '01', '01' => '01', '12' => '12'] as $month => $normalizedMonth) {
    $matched = permalink_match_path('2026/' . $month . '/album', $yearMonthStructure);
    $check(($matched['monthnum'] ?? null) === $normalizedMonth,
        'the shared permalink parser accepts and normalizes a valid month: ' . $month);
}
foreach (['00', '13'] as $month) {
    $check(permalink_match_path('2026/' . $month . '/album', $yearMonthStructure) === null,
        'the shared permalink parser rejects an out-of-range month: ' . $month);
}
$yearMonthDayStructure = '/%year%/%monthnum%/%day%/%slug%/';
$invalidDatePaths = [
    '2026/02/00/album',
    '2026/02/32/album',
    '2026/02/30/album',
];
foreach ($invalidDatePaths as $invalidDatePath) {
    $check(permalink_match_path($invalidDatePath, $yearMonthDayStructure) === null,
        'the shared permalink parser rejects an invalid calendar path: ' . $invalidDatePath);
}
$leapMatch = permalink_match_path('2024/2/29/album', $yearMonthDayStructure);
$check(($leapMatch['monthnum'] ?? null) === '02' && ($leapMatch['day'] ?? null) === '29',
    'the shared permalink parser accepts and normalizes a valid leap day');
$dayBeforeMonthStructure = '/%year%/%day%/%monthnum%/%slug%/';
$check(permalink_match_path('2026/30/2/album', $dayBeforeMonthStructure) === null
    && permalink_match_path('2024/29/2/album', $dayBeforeMonthStructure) !== null,
    'calendar validation is independent of numeric token order');

$GLOBALS['_plugin_route_permalink_structure'] = '/%year%/%slug%/';
$yearSlugProtectedPaths = [
    '2026',
    '2026/8',
    '2026/08',
    '2026/p/2',
    '2026/page/2',
    '2026/8/p/2',
    '2026/08/page/2',
    '2026/08/album',
    '2026/album/extra',
];
foreach ($yearSlugProtectedPaths as $protectedPath) {
    $filteredPath = router_apply_path_filter($pdo, $protectedPath);
    $check($filteredPath === $protectedPath,
        'the hostile filter cannot steal a year/slug Core path: ' . $protectedPath);
}

$normalizeRouterPath = static function (string $uri): string {
    $rawPath = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
    return trim(rawurldecode($rawPath), " \t\n\r\0\x0B/");
};
foreach (['/2026%2Fp%2F2/', '/2026%2F08%2Fpage%2F3/'] as $encodedArchiveUri) {
    $normalizedPath = $normalizeRouterPath($encodedArchiveUri);
    $check(router_apply_path_filter($pdo, $normalizedPath) === $normalizedPath,
        'the hostile filter cannot steal an encoded-normalized archive path: ' . $encodedArchiveUri);
}

$GLOBALS['_plugin_route_permalink_structure'] = '/%year%/%monthnum%/%slug%/';
foreach (['1', '01', '12'] as $month) {
    $candidatePath = '2026/' . $month . '/album';
    $check(router_apply_path_filter($pdo, $candidatePath) === 'stolen',
        'the classifier exposes a valid year/month/slug candidate: ' . $candidatePath);
}
foreach (['00', '13'] as $month) {
    $protectedPath = '2026/' . $month . '/album';
    $check(router_apply_path_filter($pdo, $protectedPath) === $protectedPath,
        'the hostile filter cannot steal an out-of-range month path: ' . $protectedPath);
}
foreach (['2026/album', '2026/08/album/extra'] as $protectedPath) {
    $filteredPath = router_apply_path_filter($pdo, $protectedPath);
    $check($filteredPath === $protectedPath,
        'the hostile filter cannot steal a noncandidate year/month/slug Core path: ' . $protectedPath);
}
$check(router_apply_path_filter($pdo, '2026/not-a-month/album') === '2026/not-a-month/album',
    'the hostile filter cannot steal a path with an invalid month token');

$GLOBALS['_plugin_route_permalink_structure'] = $yearMonthDayStructure;
foreach ($invalidDatePaths as $protectedPath) {
    $check(router_apply_path_filter($pdo, $protectedPath) === $protectedPath,
        'the hostile filter cannot steal an invalid calendar path: ' . $protectedPath);
}
$check(router_apply_path_filter($pdo, '2024/2/29/album') === 'stolen',
    'the classifier exposes a valid leap-day content candidate');

$GLOBALS['_plugin_route_permalink_structure'] = $dayBeforeMonthStructure;
$check(router_apply_path_filter($pdo, '2026/30/2/album') === '2026/30/2/album',
    'the classifier protects an impossible date when day precedes month');
$check(router_apply_path_filter($pdo, '2024/29/2/album') === 'stolen',
    'the classifier exposes a valid leap day when day precedes month');

$GLOBALS['_plugin_route_permalink_structure'] = '/%year%/archive/%monthnum%/%slug%/';
$invalidStructuredPaths = [
    '2026/news/08/album',
    '2026/archive/not-a-month/album',
    '2026/archive/008/album',
    '2026/archive/08/album/extra',
];
foreach ($invalidStructuredPaths as $protectedPath) {
    $check(router_apply_path_filter($pdo, $protectedPath) === $protectedPath,
        'the hostile filter cannot steal a structurally invalid dated path: ' . $protectedPath);
}
$check(router_apply_path_filter($pdo, '2026/archive/08/album') === 'stolen',
    'a structurally valid literal year/month/slug path remains a provider candidate');

$GLOBALS['_plugin_route_permalink_structure'] = '/%slug%/';
foreach (['2026/album', '2026/08/album'] as $protectedPath) {
    $check(router_apply_path_filter($pdo, $protectedPath) === $protectedPath,
        'the hostile filter cannot steal a year-prefixed path under a non-date permalink structure: ' . $protectedPath);
}
$check(router_apply_path_filter($pdo, '202x/album') === 'stolen',
    'a malformed year prefix remains outside Core date ownership');
remove_filter('router_path', $hostileFilter);

$selectiveFilter = static fn(string $path): string => in_array($path, [
    '2026/album',
    '2026/08/album',
], true) ? 'stolen' : $path;
add_filter('router_path', $selectiveFilter);

$GLOBALS['_plugin_route_permalink_structure'] = '/%year%/%slug%/';
$yearSlugPluginPath = router_apply_path_filter($pdo, '2026/album');
$check($yearSlugPluginPath === 'stolen'
    && (resolve_frontend_route($yearSlugPluginPath, 'GET')['handler'] ?? null) === $thiefHandler,
    'a selective provider can handle a year/slug content candidate');
$check(router_apply_path_filter($pdo, '2026/08/album') === '2026/08/album',
    'a year/slug structure protects a noncandidate year/month/slug path');
$yearSlugCorePath = router_apply_path_filter($pdo, '2026/core-article');
$check($yearSlugCorePath === '2026/core-article' && resolve_frontend_route($yearSlugCorePath, 'GET') === null,
    'a declined year/slug article remains available to Core resolution');

$GLOBALS['_plugin_route_permalink_structure'] = '/%year%/%monthnum%/%slug%/';
$check(router_apply_path_filter($pdo, '2026/album') === '2026/album',
    'a year/month/slug structure protects a noncandidate year/slug path');
$yearMonthSlugPluginPath = router_apply_path_filter($pdo, '2026/08/album');
$check($yearMonthSlugPluginPath === 'stolen'
    && (resolve_frontend_route($yearMonthSlugPluginPath, 'GET')['handler'] ?? null) === $thiefHandler,
    'a selective provider can handle a year/month/slug content candidate');
$yearMonthSlugCorePath = router_apply_path_filter($pdo, '2026/08/core-article');
$check($yearMonthSlugCorePath === '2026/08/core-article'
    && resolve_frontend_route($yearMonthSlugCorePath, 'GET') === null,
    'a declined year/month/slug article remains available to Core resolution');
remove_filter('router_path', $selectiveFilter);

$reset();
$boundaryHandler = static function (): void {};
$boundaryPluginPaths = [
    'authors',
    'privateer',
    'static-site',
    'admin/control-roommate',
    'topics-extra',
    'journal-archive',
    'documents-old',
];
foreach ($boundaryPluginPaths as $pluginPath) {
    register_frontend_route($pluginPath, $boundaryHandler);
    $check(!router_core_path_is_owned($pdo, $pluginPath)
        && !router_core_path_is_owned($pdo, $pluginPath . '/child')
        && (resolve_frontend_route($pluginPath . '/child', 'GET')['handler'] ?? null) === $boundaryHandler,
        'Core route boundaries leave the legitimate plugin route unblocked: ' . $pluginPath);
}

$GLOBALS['_plugin_route_core_paths'] = [
    'admin' => '/dashboard',
    'login' => 'login',
    'register' => 'register',
    'category' => ['category'],
    'posts' => ['artikel', 'posts'],
    'pages' => ['halaman'],
];
$defaultCorePaths = ['dashboard', 'dashboard/settings', 'category/guides', 'artikel/p/2', 'posts/p/2', 'halaman/p/2'];
$check(array_reduce($defaultCorePaths,
    static fn(bool $owned, string $path): bool => $owned && router_core_path_is_owned($pdo, $path), true),
    'default admin and collection paths remain Core-owned');

$GLOBALS['_plugin_route_core_paths']['category'] = [];
$GLOBALS['_plugin_route_core_paths']['posts'] = [];
$GLOBALS['_plugin_route_core_paths']['pages'] = [];
$reset();
$disabledHandler = static function (): void {};
$disabledCollectionPaths = ['category', 'artikel', 'posts', 'halaman'];
foreach ($disabledCollectionPaths as $pluginPath) {
    register_frontend_route($pluginPath, $disabledHandler);
    $check(!router_core_path_is_owned($pdo, $pluginPath)
        && !router_core_path_is_owned($pdo, $pluginPath . '/child')
        && (resolve_frontend_route($pluginPath . '/child', 'GET')['handler'] ?? null) === $disabledHandler,
        'disabled collection paths remain available to plugins: ' . $pluginPath);
}

$reset();
$invalidHandler = static function (): void {};
$check(!register_frontend_route('../secret', $invalidHandler)
    && !register_frontend_route('bad//path', $invalidHandler)
    && !register_frontend_route('encoded/%2e%2e', $invalidHandler)
    && !register_frontend_route('query?x=1', $invalidHandler),
    'unsafe and malformed route paths are rejected');
$check(!register_frontend_route('valid', $invalidHandler, ['match' => 'wildcard'])
    && !register_frontend_route('valid', $invalidHandler, ['methods' => []])
    && !register_frontend_route('valid', $invalidHandler, ['priority' => '10'])
    && !register_frontend_route('valid', $invalidHandler, ['unknown' => true]),
    'invalid and unknown route options are rejected');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
