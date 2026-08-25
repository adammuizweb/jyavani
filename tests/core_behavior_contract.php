<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/collection_helpers.php';
require_once $root . '/cfg/helpers/url_helpers.php';
require_once $root . '/app/controllers/PluginStoreController.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(
    collection_legacy_path_pagination_url('/category/guides/page/3/?q=term&view=cards') === '/category/guides/p/3/?q=term&view=cards',
    'terminal legacy path pagination becomes canonical and preserves the query'
);
$check(
    collection_legacy_path_pagination_url('/category/guides/page/3/child/?q=term') === null,
    'nested page-number segments are not rewritten'
);
$check(
    collection_legacy_path_pagination_url('/2026/page/1/?view=compact') === '/2026/?view=compact',
    'legacy path page one canonicalizes to the collection base'
);
$check(
    collection_legacy_query_pagination_url('/articles/?page=4&q=term&tag=a&tag=b') === '/articles/p/4/?q=term&tag=a&tag=b',
    'legacy query pagination preserves remaining raw query parameters'
);
$check(
    collection_legacy_query_pagination_url('/articles/p/3/?page=9&q=term') === '/articles/p/3/?q=term',
    'an existing canonical path page wins over a legacy query page'
);
$check(
    collection_legacy_query_pagination_url('/articles/?p=3&page=4&q=term') === null,
    'canonical query pagination is not rewritten'
);
$check(
    url_append_query_string('/id/legacy/?source=alias', 'campaign=summer') === '/id/legacy/?source=alias&campaign=summer',
    'redirect targets with a query receive an ampersand'
);
$check(
    url_append_query_string('/id/legacy/#section', 'campaign=summer') === '/id/legacy/?campaign=summer#section',
    'request queries are inserted before redirect fragments'
);
$check(url_append_query_string("/unsafe\r\nX-Test: yes", 'a=1') === '', 'redirect targets reject header control bytes');
$check(url_path_is_file_like('/manifest.webmanifest') && url_path_is_file_like('/sw.js'), 'webmanifest and service-worker paths are recognized as file-like');

$router = (string)file_get_contents($root . '/public/router.php');
$css = (string)file_get_contents($root . '/public/static/vendor/quill/quill.snow.pub.css');
$themeStore = (string)file_get_contents($root . '/app/controllers/ThemeStoreClient.php');
$updateStatus = (string)file_get_contents($root . '/app/controllers/UpdateStatusController.php');
$updatesEndpoint = (string)file_get_contents($root . '/dashboard/admin/check_updates_ajax.php');
$updatesCaller = (string)file_get_contents($root . '/public/static/dashboard/js/update-notif.js');
$htaccess = (string)file_get_contents($root . '/public/.htaccess');
$serverSetup = (string)file_get_contents($root . '/SERVER_SETUP.md');
$login = (string)file_get_contents($root . '/dashboard/gerbank/melbu/index.php');
$loginCss = (string)file_get_contents($root . '/public/static/dashboard/css/login.css');
$debugHelpers = (string)file_get_contents($root . '/cfg/helpers/debug_helpers.php');
$dashboardMain = (string)file_get_contents($root . '/dashboard/theme/adam/part/main.php');
$dashboardCss = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$redirectionNavIcon = (string)file_get_contents($root . '/public/static/icons/lucide/corner-up-right.svg');

$check(substr_count($router, 'collection_redirect_legacy_query_pagination();') >= 7, 'all routed collection families canonicalize legacy query pagination');
$check(str_contains($router, "([a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*)_(posts|pages|themes)"), 'locale sitemap routes accept normalized BCP-style subtags');
$check(preg_match('#^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$#', 'pt-BR') === 1, 'pt-BR satisfies the locale sitemap contract');
$check(str_contains($router, "apply_filters('unresolved_content_redirect_url'"), 'router exposes the generic unresolved content redirect hook');
$check(strpos($router, "apply_filters('unresolved_content_redirect_url'") < strpos($router, 'PostController::dispatchBySlug'), 'unresolved redirect extensions run before the legacy 404 fallback');
$check(str_contains($router, 'url_append_query_string($unresolvedRedirect'), 'unresolved redirects use query-aware URL composition');
$check(str_contains($router, 'if (!url_path_is_file_like($rawPath))'), 'router canonicalization lets file-like webmanifest paths reach dynamic routes');
$check(str_contains($htaccess, '^(?:sw\.js|manifest\.webmanifest)$ router.php'), 'Apache routes exact root PWA endpoints before stale physical files');
$check(str_contains($serverSetup, 'location = /manifest.webmanifest') && str_contains($serverSetup, '$document_root/router.php'), 'nginx example routes the dynamic root manifest through Core');

$check(!preg_match('/^(?:h[1-6]|a)\s*(?:,|\{)/m', $css), 'public Quill heading and link rules have no bare theme selectors');
$check(str_contains($css, '.editor-content h1') && str_contains($css, '.editor-content a'), 'public Quill typography uses the generic editor-content scope');
$page = (string)file_get_contents($root . '/public/views/themes/default/main/single/page.php');
$post = (string)file_get_contents($root . '/public/views/themes/default/main/single/post.php');
$check(str_contains($page, 'editor-content') && str_contains($post, 'editor-content'), 'default theme marks rendered editor content');

$check(str_contains($themeStore, "!array_key_exists('folder', \$manifest)")
    && str_contains($themeStore, "hash_equals(\$folderName, \$manifest['folder'])"), 'theme update manifests use exact folder identity when declared and trusted requested identity when absent');
$check(str_contains($updateStatus, "'plugins' => \$plugins")
    && str_contains($updatesEndpoint, 'UpdateStatusController::publicPayload($snapshot)'), 'plugin update notifications export shared snapshot updates');
$check(str_contains($updatesCaller, 'data.plugins.forEach'), 'update notification caller renders exported plugin updates');
$check(str_contains($debugHelpers, 'if (!headers_sent())') && strpos($debugHelpers, 'http_response_code(500)') > strpos($debugHelpers, 'if (!headers_sent())'), 'fatal output never changes response headers after dashboard output starts');
$check(str_contains($dashboardMain, 'catch (Throwable $error)')
    && str_contains($dashboardMain, 'class="adam-main-error"') && str_contains($dashboardCss, '.adam-main-error'), 'dashboard page failures render a contained diagnostic inside main');
$check(str_contains($redirectionNavIcon, 'm15 14 5-5-5-5')
    && str_contains($redirectionNavIcon, 'M4 20v-7a4 4 0 0 1 4-4h12'), 'Core Lucide library provides the Redirection navigation icon');

foreach (['jy_login_head', 'jy_login_before_form', 'jy_login_form', 'jy_login_after_form', 'jy_login_footer'] as $hook) {
    $check(str_contains($login, "do_action('{$hook}'"), "login exposes {$hook}");
}
foreach (['jy_login_title', 'jy_login_logo_url', 'jy_login_logo_link'] as $filter) {
    $check(str_contains($login, "apply_filters('{$filter}'"), "login exposes {$filter}");
}
$check(str_contains($login, '/static/img/jyavani.svg') && str_contains($login, 'rel="icon"'), 'login uses the canonical Jyavani logo and favicon');
$check(str_contains($login, '/static/dashboard/css/login.css') && str_contains($loginCss, '@media (max-width: 480px)'), 'login loads its dedicated responsive stylesheet');
$check(str_contains($login, 'autocomplete="current-password"') && str_contains($login, 'name="csrf_token"'), 'login preserves password autocomplete and CSRF protection');

$downloadWithStream = new ReflectionMethod(PluginStoreController::class, 'downloadPackageWithStream');
$serveOnce = static function (int $status, string $body) use ($downloadWithStream): array {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if ($server === false) return [false, 'server: ' . $errorNumber . ' ' . $errorMessage];
    $address = stream_socket_get_name($server, false);
    $pid = pcntl_fork();
    if ($pid === -1) {
        fclose($server);
        return [false, 'fork failed'];
    }
    if ($pid === 0) {
        $connection = stream_socket_accept($server, 5);
        if (is_resource($connection)) {
            stream_set_timeout($connection, 5);
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
            }
            $reason = $status >= 200 && $status < 300 ? 'OK' : 'Error';
            fwrite($connection, "HTTP/1.1 {$status} {$reason}\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
            fclose($connection);
        }
        fclose($server);
        exit(0);
    }

    fclose($server);
    $tmp = tempnam(sys_get_temp_dir(), 'plugin-stream-contract-');
    if ($tmp === false) return [false, 'temp file failed'];
    $ok = $downloadWithStream->invoke(null, 'http://' . $address . '/package.zip', $tmp, static function (): void {});
    pcntl_waitpid($pid, $waitStatus);
    $contents = (string)file_get_contents($tmp);
    @unlink($tmp);
    return [$ok === true, $contents];
};

[$streamOk, $streamBody] = $serveOnce(200, 'contract-package');
$check($streamOk && $streamBody === 'contract-package', 'PHP-stream fallback accepts HTTP 2xx and verifies writes');
[$streamRejected] = $serveOnce(404, 'not-a-package');
$check(!$streamRejected, 'PHP-stream fallback rejects non-2xx responses');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
