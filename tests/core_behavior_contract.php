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

$router = (string)file_get_contents($root . '/public/router.php');
$css = (string)file_get_contents($root . '/public/static/vendor/quill/quill.snow.pub.css');
$themeStore = (string)file_get_contents($root . '/app/controllers/ThemeStoreClient.php');
$updatesEndpoint = (string)file_get_contents($root . '/dashboard/admin/check_updates_ajax.php');
$updatesCaller = (string)file_get_contents($root . '/public/static/dashboard/js/update-notif.js');

$check(substr_count($router, 'collection_redirect_legacy_query_pagination();') >= 7, 'all routed collection families canonicalize legacy query pagination');
$check(str_contains($router, "([a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*)_(posts|pages|themes)"), 'locale sitemap routes accept normalized BCP-style subtags');
$check(preg_match('#^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$#', 'pt-BR') === 1, 'pt-BR satisfies the locale sitemap contract');
$check(str_contains($router, "apply_filters('unresolved_content_redirect_url'"), 'router exposes the generic unresolved content redirect hook');
$check(strpos($router, "apply_filters('unresolved_content_redirect_url'") < strpos($router, 'PostController::dispatchBySlug'), 'unresolved redirect extensions run before the legacy 404 fallback');
$check(str_contains($router, 'url_append_query_string($unresolvedRedirect'), 'unresolved redirects use query-aware URL composition');

$check(!preg_match('/^(?:h[1-6]|a)\s*(?:,|\{)/m', $css), 'public Quill heading and link rules have no bare theme selectors');
$check(str_contains($css, '.editor-content h1') && str_contains($css, '.editor-content a'), 'public Quill typography uses the generic editor-content scope');
foreach (['default', 'adam'] as $theme) {
    $page = (string)file_get_contents($root . '/public/views/themes/' . $theme . '/main/single/page.php');
    $post = (string)file_get_contents($root . '/public/views/themes/' . $theme . '/main/single/post.php');
    $check(str_contains($page, 'editor-content') && str_contains($post, 'editor-content'), $theme . ' theme marks rendered editor content');
}

$check(str_contains($themeStore, "\$manifest['folder'] ?? \$manifest['name'] ?? ''"), 'theme update manifests remain folder-first');
$check(str_contains($updatesEndpoint, 'PluginStoreController::getCachedUpdates()'), 'plugin update notifications export cached plugin updates');
$check(str_contains($updatesCaller, 'data.plugins.forEach'), 'update notification caller renders exported plugin updates');

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
