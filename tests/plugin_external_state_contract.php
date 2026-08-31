<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-plugin-external-state-' . bin2hex(random_bytes(6));
mkdir($fixture . '/backend/var', 0750, true);
mkdir($fixture . '/plugins/sample', 0750, true);
mkdir($fixture . '/native', 0750, true);
mkdir($fixture . '/native/state', 02770);
chmod($fixture . '/native/state', 02770);
file_put_contents($fixture . '/plugins/sample/plugin.json', json_encode([
    'name' => 'sample',
    'version' => '1.0.0',
    'requires' => ['jyavani' => '>=2.3.91'],
]));

define('BACKEND_PATH', $fixture . '/backend');
define('PLUGIN_PATH', $fixture . '/plugins');
if (!defined('JYAVANI_VERSION')) define('JYAVANI_VERSION', '2.3.91');

$previousState = getenv('PLUGIN_DISABLED_JSON');
putenv('PLUGIN_DISABLED_JSON=' . $fixture . '/native/state/plugins-disabled.json');
require_once $root . '/plugins/index.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $removeTree($path . '/' . $entry);
    }
    @rmdir($path);
};

try {
    $check(PLUGIN_DISABLED_JSON === $fixture . '/native/state/plugins-disabled.json',
        'absolute environment path selects native plugin state storage');
    $check(plugin_disabled_names() === ['sample'],
        'a configured external state file that is missing fails closed');
    $check(_plugin_write_disabled_names_already_locked(['sample'])
        && plugin_disabled_names() === ['sample'],
        'external plugin state publishes and reads a complete validated list');
    $check((fileperms(PLUGIN_DISABLED_JSON) & 0777) === 0640,
        'external plugin state uses a non-world-writable file mode');
    chmod(dirname(PLUGIN_DISABLED_JSON), 02772);
    $check(plugin_disabled_names() === ['sample'] && !_plugin_write_disabled_names_already_locked([]),
        'world-writable plugin state directory fails closed');
} finally {
    if ($previousState === false) putenv('PLUGIN_DISABLED_JSON');
    else putenv('PLUGIN_DISABLED_JSON=' . $previousState);
    $removeTree($fixture);
}

echo "Checks: {$checks}, Failures: " . count($failures) . PHP_EOL;
exit($failures === [] ? 0 : 1);
