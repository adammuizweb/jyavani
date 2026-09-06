<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$router = (string)file_get_contents($root . '/public/router.php');
$notFound = (string)file_get_contents($root . '/app/frontend_404.php');
$setup = (string)file_get_contents($root . '/SERVER_SETUP.md');
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($router, "preg_match('#^/static/(?:img|files)(?:/|$)#', \$rawPath)")
    && str_contains($router, "require __DIR__ . '/../app/frontend_404.php';"),
    'missing managed public assets use the Core 404 fallback');
$check(str_contains($notFound, "header('Cache-Control: no-store, max-age=0');")
    && str_contains($notFound, "header('X-Content-Type-Options: nosniff');"),
    'themed 404 responses are not cached and disable MIME sniffing');
$check(str_contains($setup, 'location ^~ /static/img/')
    && str_contains($setup, 'location ^~ /static/files/')
    && substr_count($setup, 'try_files $uri @jyavani_404;') === 2,
    'nginx routes missing Media and File uploads to the themed 404 handler');
$check(strpos($setup, 'location ^~ /static/img/') < strpos($setup, 'location ~* ^/views/.*\.(?:css')
    && str_contains($setup, 'server_tokens off;')
    && str_contains($setup, 'more_clear_headers Server;'),
    'nginx guidance preserves location priority and documents server-header masking');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " static asset 404 contract check(s) failed.\n");
    exit(1);
}
echo "Static asset 404 contract passed ({$checks} checks).\n";
