<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$removed = [
    'cfg/helpers/datetime.php',
    'dashboard/gerbank/index.php',
    'dashboard/gerbank/melbu/index.php',
    'dashboard/gerbank/daptar/index.php',
    'public/sitemaps/sitemap_index.php',
    'public/sitemaps/sitemap_pages.php',
    'public/sitemaps/sitemap_posts.php',
    'public/static/dashboard/css/aside.css',
    'public/static/dashboard/css/notif.css',
    'public/static/dashboard/js/alerts.js',
    'public/static/dashboard/js/muiz-notify.js',
    'public/static/dashboard/js/notif.js',
];
foreach ($removed as $relative) {
    $check(!file_exists($root . '/' . $relative) && !is_link($root . '/' . $relative), $relative . ' remains removed');
}

$router = (string)file_get_contents($root . '/public/router.php');
$dashboard = (string)file_get_contents($root . '/dashboard/index.php');
$login = (string)file_get_contents($root . '/dashboard/auth/login/index.php');
$registration = (string)file_get_contents($root . '/dashboard/auth/register/index.php');
$authSettings = (string)file_get_contents($root . '/dashboard/admin/settings/auth.php');
$authHelpers = (string)file_get_contents($root . '/cfg/helpers/auth_helpers.php');
$dashboardCss = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$defaultSchema = (string)file_get_contents($root . '/schema/default.sql');
$loginAttemptMigration = (string)file_get_contents($root . '/schema/migrations/030-login-attempt-identity.sql');

$check(str_contains($router, "dashboard/auth/login/index.php")
    && str_contains($router, "dashboard/auth/register/index.php")
    && !str_contains($router, 'dashboard/gerbank/'),
    'router dispatches configurable public auth paths to fixed descriptive entrypoints');
$check(str_contains($dashboard, 'realpath(DASH_PATH')
    && str_contains($dashboard, 'rawurldecode($uriPath)')
    && str_contains($dashboard, "preg_match('/\\.php(?:\\/|$)/', \$relative)")
    && str_contains($dashboard, "str_ends_with(\$relative, '.php')")
    && str_contains($dashboard, 'require FRONTEND_404_PATH;'),
    'missing and path-info dashboard PHP routes fail closed instead of rendering dashboard home');
$check(str_contains($authHelpers, 'ON DUPLICATE KEY UPDATE')
    && str_contains($authHelpers, 'SUM(attempts) AS attempts')
    && str_contains($authHelpers, 'if ($attempts >= $maxAttempts && !is_blocked($attempt))')
    && str_contains($authHelpers, '$initialBlockedUntil = $maxAttempts === 1')
    && str_contains($defaultSchema, 'UNIQUE KEY `uq_login_attempt_email_ip` (`email`,`ip_address`)')
    && str_contains($loginAttemptMigration, 'ADD UNIQUE KEY `uq_login_attempt_email_ip`'),
    'brute-force attempts aggregate duplicate identities and block at the configured boundary');
$check(str_contains($loginAttemptMigration, 'SUM(`attempts`)')
    && str_contains($loginAttemptMigration, 'MAX(`last_attempt`)')
    && str_contains($loginAttemptMigration, 'MAX(`blocked_until`)')
    && str_contains($loginAttemptMigration, 'duplicate_attempt.`attempts` = 0'),
    'login-attempt migration preserves cumulative failures and remains stable if aggregation is retried');
$check(!str_contains($login . $registration, 'melbu_')
    && !str_contains($authSettings, "settings_get(\$pdo, 'login_slug'"),
    'auth runtime contains no legacy physical-name behavior or page-load path migration');
$check(!str_contains($login, 'wa.me/')
    && !str_contains($login, 'Contact Admin via WhatsApp')
    && !str_contains($login, 'Need help after several attempts?')
    && !str_contains($login, 'CAPTCHA not configured. Contact admin.'),
    'Core login contains no personal support destination or replacement support message');
$check(!str_contains($dashboardCss, '.muiz-toast')
    && !str_contains($dashboardCss, '--muiz-toast'),
    'retired Muiz notification styling is absent from the active dashboard bundle');
foreach (['Contact Admin via WhatsApp', 'Need help after several attempts?', 'CAPTCHA not configured. Contact admin.'] as $key) {
    $check(!str_contains($translations, "'" . $key . "'"), $key . ' has no stale translation seed');
}

$fixture = sys_get_temp_dir() . '/jyavani-dashboard-route-' . bin2hex(random_bytes(6));
$fixtureDashboard = $fixture . '/dashboard';
$fixtureTheme = $fixtureDashboard . '/theme/adiwira';
$fixtureScript = $fixture . '/request.php';
$removeFixture = static function (string $path) use (&$removeFixture): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') $removeFixture($path . '/' . $entry);
        }
        @rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) @unlink($path);
};

try {
    if (!mkdir($fixtureTheme, 0700, true)) throw new RuntimeException('Unable to create dashboard route fixture');
    copy($root . '/dashboard/index.php', $fixtureDashboard . '/index.php');
    file_put_contents($fixtureDashboard . '/bootstrap.php', <<<'PHP'
<?php
const ADMIN_BASE_PATH = '/dashboard';
const DASH_PATH = __DIR__;
const FRONTEND_404_PATH = __DIR__ . '/../404.php';
$pdo = new stdClass();
function ensure_session_started(bool $create): void {}
function is_logged_in(): bool { return true; }
function current_user_status($pdo): array { return ['ok' => true, 'user' => ['id' => 1, 'role' => 'admin']]; }
function current_user_can($pdo, string $permission): bool { return true; }
function admin_ui_locale(): string { return 'en'; }
function set_locale(string $locale): void {}
PHP
    );
    file_put_contents($fixture . '/404.php', '<?php echo "NOT_FOUND";');
    file_put_contents($fixtureTheme . '/layout.php', '<?php echo "LAYOUT";');
    file_put_contents($fixtureScript, <<<'PHP'
<?php
$_SERVER['REQUEST_URI'] = '/dashboard/admin/missing.php%2Ftail';
require __DIR__ . '/dashboard/index.php';
PHP
    );
    $command = [PHP_BINARY, $fixtureScript];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = '';
    $exitCode = -1;
    if (is_resource($process)) {
        $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
    }
    $check($exitCode === 0 && $output === 'NOT_FOUND', 'encoded dashboard PHP path info executes the fail-closed 404 branch');
} finally {
    $removeFixture($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Core cleanup contract check(s) failed.\n");
    exit(1);
}
echo "Core cleanup contract passed.\n";
