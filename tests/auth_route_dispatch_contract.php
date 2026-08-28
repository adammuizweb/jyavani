<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$GLOBALS['_auth_route_dispatch_settings'] = [];
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    $settings = $GLOBALS['_auth_route_dispatch_settings'] ?? [];
    return is_array($settings) && array_key_exists($key, $settings) ? (string)$settings[$key] : $default;
}
require_once $root . '/cfg/helpers/auth_helpers.php';
require_once $root . '/cfg/helpers/collection_helpers.php';
final class AuthPathContractPdo extends PDO { public function __construct() {} }

$fixture = sys_get_temp_dir() . '/auth-route-dispatch-' . getmypid() . '-' . bin2hex(random_bytes(4));
$paths = [
    $fixture . '/app',
    $fixture . '/cfg/var',
    $fixture . '/dashboard/gerbank/melbu',
    $fixture . '/dashboard/gerbank/daptar',
    $fixture . '/plugins/installed',
    $fixture . '/public',
];
foreach ($paths as $path) {
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create fixture directory: ' . $path);
    }
}

$exportRoot = var_export($root, true);
$exportFixture = var_export($fixture, true);
file_put_contents($fixture . '/app/bootstrap_core.php', <<<PHP
<?php
define('BACKEND_PATH', {$exportFixture} . '/cfg');
define('PUBLIC_PATH', {$exportFixture} . '/public');
define('PLUGIN_PATH', {$exportFixture} . '/plugins/installed');
define('PLUGIN_DISABLED_JSON', BACKEND_PATH . '/var/plugins-disabled.json');
require_once {$exportRoot} . '/cfg/helpers/hooks.php';
require_once {$exportRoot} . '/cfg/helpers/auth_helpers.php';
require_once {$exportRoot} . '/cfg/helpers/collection_helpers.php';
require_once {$exportRoot} . '/cfg/helpers/url_helpers.php';
final class AuthRouteDispatchPdo extends PDO { public function __construct() {} }
function settings_get(PDO \$pdo, string \$key, ?string \$default = null): ?string {
    \$settings = \$GLOBALS['_auth_route_dispatch_settings'] ?? [];
    return is_array(\$settings) && array_key_exists(\$key, \$settings) ? (string)\$settings[\$key] : \$default;
}
\$pdo = new AuthRouteDispatchPdo();
PHP);
file_put_contents($fixture . '/app/bootstrap_theme.php', "<?php\n");
file_put_contents($fixture . '/plugins/index.php', <<<PHP
<?php
require_once {$exportRoot} . '/plugins/index.php';
register_frontend_route('account/sign-in', static function (PDO \$pdo): void { echo 'PLUGIN_LOGIN'; }, ['match' => 'exact']);
register_frontend_route('account/join', static function (PDO \$pdo): void { echo 'PLUGIN_REGISTER'; }, ['match' => 'exact']);
PHP);
file_put_contents($fixture . '/dashboard/gerbank/melbu/index.php', "<?php echo 'CORE_LOGIN'; exit;\n");
file_put_contents($fixture . '/dashboard/gerbank/daptar/index.php', "<?php echo 'CORE_REGISTER'; exit;\n");
file_put_contents($fixture . '/cfg/var/plugins-disabled.json', "[]\n");
copy($root . '/public/router.php', $fixture . '/public/router.php');

file_put_contents($fixture . '/runner.php', <<<'PHP'
<?php
declare(strict_types=1);
$uri = (string)($argv[1] ?? '/');
$GLOBALS['_auth_route_dispatch_settings'] = json_decode(base64_decode((string)($argv[3] ?? ''), true), true) ?: [];
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['QUERY_STRING'] = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
$_SERVER['REQUEST_METHOD'] = (string)($argv[2] ?? 'GET');
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/public';
$_SERVER['HTTP_HOST'] = 'contract.test';
register_shutdown_function(static function (): void {
    $status = http_response_code();
    echo '|STATUS:' . (is_int($status) ? $status : 200);
});
require __DIR__ . '/public/router.php';
PHP);

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$pdo = new AuthPathContractPdo();
$GLOBALS['_auth_route_dispatch_settings'] = [
    'login_path' => ' /account/sign-in/ ',
    'register_path' => "\t/account/join//\r\n",
];
$check(auth_normalize_configured_path(' /account/sign-in/ ') === 'account/sign-in'
    && router_core_path_is_owned($pdo, 'account/sign-in'),
    'noncanonical login settings normalize identically for the guard and classifier');
$check(auth_normalize_configured_path("\t/account/join//\r\n") === 'account/join'
    && router_core_path_is_owned($pdo, 'account/join'),
    'noncanonical register settings normalize identically for the guard and classifier');

$_SERVER['REQUEST_URI'] = '/account/sign-in-extra/';
$check(!auth_path_matches('account/sign-in')
    && !router_core_path_is_owned($pdo, 'account/sign-in-extra')
    && !router_core_path_is_owned($pdo, 'account/sign-in/child'),
    'auth paths remain exact and do not own segment-prefix boundaries');

$GLOBALS['_auth_route_dispatch_settings'] = [];
$check(get_login_path($pdo) === 'login' && get_register_path($pdo) === 'register'
    && router_core_path_is_owned($pdo, 'login') && router_core_path_is_owned($pdo, 'register'),
    'missing settings retain the legacy login and register defaults');

$GLOBALS['_auth_route_dispatch_settings'] = ['login_path' => '', 'register_path' => '/'];
$_SERVER['REQUEST_URI'] = '/';
$check(auth_normalize_configured_path('') === null && auth_normalize_configured_path('/') === null
    && !auth_path_matches('') && !auth_path_matches('/')
    && !router_core_path_is_owned($pdo, ''),
    'empty and root auth settings never claim the homepage');
$check(auth_normalize_configured_path("bad\0path") === null
    && auth_normalize_configured_path('bad\\path') === null
    && auth_normalize_configured_path('Uppercase') === null
    && auth_normalize_configured_path('encoded%2Fpath') === null,
    'configured auth path validation preserves the settings character contract');

$request = static function (string $uri, string $method = 'GET', array $settings = [
    'login_path' => 'account/sign-in',
    'register_path' => 'account/join',
]) use ($fixture): array {
    $encodedSettings = base64_encode((string)json_encode($settings));
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture . '/runner.php') . ' '
        . escapeshellarg($uri) . ' ' . escapeshellarg($method) . ' ' . escapeshellarg($encodedSettings);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixture);
    if (!is_resource($process)) return ['', 'Could not start router fixture.', 1];
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [(string)$stdout, (string)$stderr, proc_close($process)];
};

$cases = [
    ['/account/sign-in/?next=%2Fdashboard', 'CORE_LOGIN|STATUS:200', ['login_path' => 'account/sign-in', 'register_path' => 'account/join']],
    ['/account%2Fsign-in/?next=%2Fdashboard', 'CORE_LOGIN|STATUS:200', ['login_path' => ' /account/sign-in/ ', 'register_path' => 'account/join']],
    ['/account/join/?source=invite', 'CORE_REGISTER|STATUS:200', ['login_path' => 'account/sign-in', 'register_path' => 'account/join']],
    ['/account%2Fjoin/?source=invite', 'CORE_REGISTER|STATUS:200', ['login_path' => 'account/sign-in', 'register_path' => "\t/account/join//\r\n"]],
];
foreach ($cases as [$uri, $expected, $settings]) {
    [$stdout, $stderr, $status] = $request($uri, 'GET', $settings);
    $passed = $status === 0 && $stderr === '' && $stdout === $expected;
    $check($passed,
        $uri . ' dispatches to Core instead of the colliding plugin route'
        . ($passed ? '' : ' (status=' . $status . ', stdout=' . json_encode($stdout) . ', stderr=' . json_encode($stderr) . ')'));
}

[$redirectOutput, $redirectError, $redirectStatus] = $request('/account%2Fsign-in?next=%2Fdashboard');
$redirectPassed = $redirectStatus === 0 && $redirectError === '' && $redirectOutput === '|STATUS:301';
$check($redirectPassed,
    'encoded auth paths without a trailing slash retain the canonical redirect'
    . ($redirectPassed ? '' : ' (status=' . $redirectStatus . ', stdout=' . json_encode($redirectOutput) . ', stderr=' . json_encode($redirectError) . ')'));

[$postOutput, $postError, $postStatus] = $request('/account%2Fjoin?source=invite', 'POST');
$postPassed = $postStatus === 0 && $postError === '' && $postOutput === '|STATUS:308';
$check($postPassed,
    'encoded auth POST paths without a trailing slash retain the method-preserving redirect'
    . ($postPassed ? '' : ' (status=' . $postStatus . ', stdout=' . json_encode($postOutput) . ', stderr=' . json_encode($postError) . ')'));

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($target) && !is_link($target)) $remove($target);
        else @unlink($target);
    }
    @rmdir($path);
};
$remove($fixture);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
