<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/plugin-requirement-contract-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('BACKEND_PATH', $fixture . '/cfg');
define('PUBLIC_PATH', $fixture . '/public');
mkdir(BACKEND_PATH . '/var', 0775, true);
mkdir(PUBLIC_PATH, 0775, true);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/plugins/index.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$compatible = [
    'name' => 'contract-plugin',
    'requires' => ['jyavani' => '<=999.0.0', 'php' => '>=8.1', 'extensions' => ['json']],
];
$incompatible = [
    'name' => 'contract-plugin',
    'requires' => ['jyavani' => '>=999.0.0', 'php' => '>=99.0', 'extensions' => ['missing_contract_extension']],
];
$check(plugin_requirement_errors($compatible) === [], 'compatible Jyavani, PHP, and extension requirements pass');
$errors = plugin_requirement_errors($incompatible);
$check(count($errors) === 3 && str_contains(plugin_requirements_error_message($incompatible), 'requirements are not met'), 'incompatible Core, PHP, and extension requirements fail closed with diagnostics');
$check(!plugin_version_requirement_met(plugin_current_jyavani_version(), 'not-a-version'), 'malformed version requirements fail closed');
$check(plugin_requirement_errors(['name' => 'bad', 'requires' => ['jyavani' => ['2.0']]]) !== [], 'malformed declared requirements fail closed');
$check(plugin_requirement_errors(['name' => 'legacy', 'jyavani_required' => '<=999.0.0']) === [], 'top-level jyavani_required is normalized and enforced');
$check(plugin_normalize_requirements(['php_required' => '8.1', 'requires' => ['php' => '>=8.1']])['errors'] === [], 'bare and explicit minimum requirements are semantically equivalent');
$check(plugin_package_requirement_errors(
    ['php_required' => '8.1'],
    ['requires' => ['php' => '>=8.1']]
) === [], 'store metadata and package requirements compare canonical semantics');
$check(plugin_package_requirement_errors(
    ['requires' => ['jyavani' => '>=2.3.0', 'php' => '>=8.1']],
    ['requires' => ['jyavani' => '>=2.0.0', 'php' => '>=8.1']]
) !== [], 'store install rejects weaker or mismatched package requirements');

$pluginDir = $fixture . '/contract-plugin';
mkdir($pluginDir, 0775, true);
file_put_contents($pluginDir . '/asset.js', 'fresh asset');
$destination = PUBLIC_PATH . '/static/plugins/contract-plugin/asset.js';
$result = plugin_static_copy($pluginDir, [['from' => 'asset.js', 'to' => 'static/plugins/contract-plugin/asset.js']]);
$check($result['failed'] === 0 && file_get_contents($destination) === 'fresh asset', 'declared static copies publish the complete source');
file_put_contents($destination, 'retained asset');
$failed = plugin_static_copy($pluginDir, [
    ['from' => 'asset.js', 'to' => 'static/plugins/contract-plugin/asset.js'],
    ['from' => 'missing.js', 'to' => 'static/plugins/contract-plugin/missing.js'],
]);
$check($failed['failed'] > 0 && file_get_contents($destination) === 'retained asset', 'invalid declared static copies fail before replacing existing assets');
$oldDestination = PUBLIC_PATH . '/static/plugins/contract-plugin/obsolete.js';
file_put_contents($oldDestination, 'obsolete asset');
$updated = plugin_static_copy(
    $pluginDir,
    [['from' => 'asset.js', 'to' => 'static/plugins/contract-plugin/asset.js']],
    [['from' => 'old.js', 'to' => 'static/plugins/contract-plugin/obsolete.js']]
);
$check($updated['failed'] === 0 && $updated['removed'] === 1 && !file_exists($oldDestination), 'successful static update removes obsolete declared destinations');
file_put_contents($destination, 'rollback asset');
$overlongDirectory = str_repeat('x', 300);
$rollback = plugin_static_copy($pluginDir, [
    ['from' => 'asset.js', 'to' => 'static/plugins/contract-plugin/asset.js'],
    ['from' => 'asset.js', 'to' => 'static/plugins/contract-plugin/' . $overlongDirectory . '/asset.js'],
]);
$check($rollback['failed'] > 0 && $rollback['rollback_incomplete'] === false && file_get_contents($destination) === 'rollback asset', 'static-copy failure verifies and restores every previously published destination');

$registry = (string)file_get_contents($root . '/plugins/index.php');
$upload = (string)file_get_contents($root . '/dashboard/admin/plugins/upload.php');
$browse = (string)file_get_contents($root . '/dashboard/admin/plugins/browse.php');
$manager = (string)file_get_contents($root . '/dashboard/admin/plugins/index.php');
$updater = (string)file_get_contents($root . '/app/controllers/PluginStoreController.php');
$check(substr_count($registry, 'plugin_resolve_active_plugins(') >= 3
    && str_contains($registry, 'plugin_requirement_errors_without_plugin_state($all[$name])'),
    'active loading and activation share the dependency-aware requirement resolver');
$check(str_contains($upload, 'plugin_install_requirements_error_message($extractedManifest, $activatePlugin)')
    && str_contains($browse, 'plugin_install_requirements_error_message($extractedManifest, $activatePlugin)'),
    'ZIP upload and store install enforce activation-aware package requirements');
$check(str_contains($manager, 'plugin_last_error()') && str_contains($manager, 'plugin_requirement_diagnostics()'), 'plugin manager surfaces activation and existing incompatibility diagnostics');
$manifestCheck = strpos($updater, '$packageManifestRaw = $zip->getFromName');
$destructiveUpdate = strpos($updater, '// Hapus files lama');
$check($manifestCheck !== false && $destructiveUpdate !== false && $manifestCheck < $destructiveUpdate, 'plugin updater inspects package plugin.json before destructive replacement');
$check(str_contains($updater, "'jyavani_required'") && str_contains($updater, "'compatible' =>"), 'update discovery carries and enforces the Core requirement metadata');
$check(str_contains($updater, 'plugin_package_requirement_errors($advertisedManifest, $packageManifest)'), 'update package requirements must match advertised store metadata');
$check(str_contains($updater, "'checksum' => is_string(\$checksum) ? \$checksum : ''"), 'store version metadata publishes the package checksum required by the updater');
$check(str_contains($updater, 'plugin_static_copy($pluginDir, $staticCopy, $oldStaticCopy)') && str_contains($updater, "'rollback_incomplete'"), 'updates track old and new static destinations and report incomplete static rollback');
$check(str_contains($browse, 'plugin_package_requirement_errors($pluginData, $manifest)')
    && str_contains($browse, 'plugin_install_requirements_error_message($pluginData, $activatePlugin)'),
    'store initial install enforces catalog parity and activation-aware requirements');
$staticCleanup = strpos($registry, '// Remove plugin directory recursively');
$check(str_contains($registry, "if (is_file(\$abs) && (!@unlink(\$abs) || file_exists(\$abs)))") && strpos($registry, 'if ($errors !== [])', strpos($registry, 'function plugin_delete')) < $staticCleanup, 'uninstall verifies declared static removal and stops before plugin deletion when cleanup is incomplete');

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
