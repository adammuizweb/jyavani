<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};

$router = (string)file_get_contents($root . '/public/router.php');
$htaccess = (string)file_get_contents($root . '/public/.htaccess');
$frontend404 = (string)file_get_contents($root . '/app/frontend_404.php');
$helpers = (string)file_get_contents($root . '/cfg/helpers/null_helpers.php');
$serverSetup = (string)file_get_contents($root . '/SERVER_SETUP.md');
$installer = (string)file_get_contents($root . '/public/pondasi/index.php');
$legacyDirectoryIndexes = [];
$staticIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/public/static', FilesystemIterator::SKIP_DOTS)
);
foreach ($staticIterator as $file) {
    if ($file->isFile() && $file->getFilename() === 'index.php') {
        $legacyDirectoryIndexes[] = $file->getPathname();
    }
}

$check(
    str_contains($router, "require __DIR__ . '/../app/frontend_404.php';"),
    'physical files and directories use the shared themed 404 renderer'
);
$check(
    str_contains($htaccess, 'RewriteCond %{REQUEST_FILENAME}/index.php -f')
        && !str_contains($htaccess, 'RewriteCond %{REQUEST_FILENAME} -f [OR]'),
    'Apache bypasses routing only for files and directories with an index.php'
);
$sensitiveRewrite = strpos($htaccess, 'RewriteRule ^(?:dev_lock\\.php|views/.*\\.php)$ router.php [L,QSA,NC]');
$dotfileRewrite = strpos($htaccess, 'RewriteRule (^|/)\\. router.php [L,QSA]');
$sensitiveExtensionRewrite = strpos($htaccess, 'RewriteRule \\.(?:env|ini|log|sh|sql|bak|dist|ya?ml|md)(?:/|$) router.php [L,QSA,NC]');
$physicalFileBypass = strpos($htaccess, 'RewriteCond %{REQUEST_FILENAME} -f');
$check(
    $sensitiveRewrite !== false
        && $dotfileRewrite !== false
        && $sensitiveExtensionRewrite !== false
        && $physicalFileBypass !== false
        && $sensitiveRewrite < $physicalFileBypass
        && $dotfileRewrite < $physicalFileBypass
        && $sensitiveExtensionRewrite < $physicalFileBypass,
    'Apache masks sensitive files and PHP views before physical-file handling'
);
$apacheHeaders = [
    'Strict-Transport-Security "max-age=300"',
    'Content-Security-Policy-Report-Only "default-src \'self\';',
    'X-Frame-Options "SAMEORIGIN"',
    'X-Content-Type-Options "nosniff"',
    'Referrer-Policy "strict-origin-when-cross-origin"',
    'Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()"',
];
$check(
    array_reduce($apacheHeaders, static fn(bool $present, string $header): bool => $present && str_contains($htaccess, 'Header always set ' . $header), true),
    'Apache emits Core browser security headers for static and dynamic responses'
);
$check(
    str_contains($serverSetup, 'location @jyavani_404')
        && str_contains($serverSetup, 'error_page 403 = @jyavani_404;')
        && str_contains($serverSetup, '/app/frontend_404.php'),
    'nginx directory denials use the pinned Core cosmetic 404 handler'
);
$check(
    str_contains($frontend404, "if (!function_exists('plugins_all'))")
        && str_contains($frontend404, "do_action('init');"),
    'direct 404 entrypoints complete the public plugin lifecycle'
);
$check(
    str_contains($helpers, "if (!function_exists('h'))") && str_contains($helpers, 'return e($v);'),
    'the public layout escape helper belongs to Core bootstrap'
);
$check(
    $legacyDirectoryIndexes === [],
    'static directories do not rely on legacy PHP index guards'
);
$check(
    str_contains($installer, "if (is_installed())")
        && str_contains($installer, "define('PUBLIC_PATH', \$publicDir)")
        && str_contains($installer, 'chdir($publicDir);')
        && str_contains($installer, "require \$publicDir . '/router.php';"),
    'an installed Pondasi endpoint enters the complete public routing lifecycle'
);

foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
if ($failures !== []) {
    fwrite(STDERR, count($failures) . " of {$checks} checks failed.\n");
    exit(1);
}

echo "Directory 404 contract passed ({$checks} checks).\n";
