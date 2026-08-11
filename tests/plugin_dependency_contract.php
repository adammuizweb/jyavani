<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/plugin-dependency-contract-' . getmypid() . '-' . bin2hex(random_bytes(4));
define('BACKEND_PATH', $fixture . '/cfg');
define('PUBLIC_PATH', $fixture . '/public');
define('PLUGIN_PATH', $fixture . '/plugins');
define('PLUGIN_DISABLED_JSON', BACKEND_PATH . '/var/plugins-disabled.json');
mkdir(BACKEND_PATH . '/var', 0775, true);
mkdir(PUBLIC_PATH, 0775, true);
mkdir(PLUGIN_PATH, 0775, true);
file_put_contents(PLUGIN_DISABLED_JSON, '[]');

require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/plugins/index.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$writePlugin = static function (string $name, string $version, array $requiredPlugins = [], bool $withMain = false): void {
    $directory = PLUGIN_PATH . '/' . $name;
    mkdir($directory, 0775, true);
    file_put_contents($directory . '/plugin.json', json_encode([
        'name' => $name,
        'version' => $version,
        'requires' => ['plugins' => $requiredPlugins],
    ], JSON_PRETTY_PRINT));
    if ($withMain) {
        file_put_contents($directory . '/plugin.php', '<?php $GLOBALS["contract_plugin_load_order"][] = ' . var_export($name, true) . ';');
    }
};

$writePlugin('dependency-base', '1.4.0', [], true);
$writePlugin('dependency-child', '1.0.0', ['dependency-base' => '^1.2.0'], true);
$writePlugin('dependency-incompatible', '1.0.0', ['dependency-base' => '>=2.0.0']);
$writePlugin('dependency-missing', '1.0.0', ['not-installed' => '>=1.0.0']);
$writePlugin('cycle-a', '1.0.0', ['cycle-b' => '>=1.0.0']);
$writePlugin('cycle-b', '1.0.0', ['cycle-a' => '>=1.0.0']);
$writePlugin('failure-base', '1.0.0', [], true);
file_put_contents(PLUGIN_PATH . '/failure-base/plugin.php', '<?php add_filter("service_worker_script", static fn(string $script): string => $script . "failed-entrypoint-contribution"); throw new RuntimeException("entrypoint contract failure");');
$writePlugin('failure-child', '1.0.0', ['failure-base' => '>=1.0.0'], true);

$active = plugins_active();
$check(array_keys($active) === ['dependency-base', 'dependency-child', 'failure-base', 'failure-child'], 'active plugins resolve in dependency-first order and invalid manifest graph branches are skipped');
$diagnostics = plugin_requirement_diagnostics();
$check(isset($diagnostics['dependency-incompatible']) && str_contains($diagnostics['dependency-incompatible'], 'does not satisfy'), 'incompatible dependency versions produce actionable diagnostics');
$check(isset($diagnostics['dependency-missing']) && str_contains($diagnostics['dependency-missing'], 'not installed'), 'missing dependencies produce actionable diagnostics');
$check(isset($diagnostics['cycle-a'], $diagnostics['cycle-b']) && str_contains($diagnostics['cycle-a'], 'cycle'), 'dependency cycles are isolated and diagnosed');

$GLOBALS['contract_plugin_load_order'] = [];
plugin_load_active();
$check($GLOBALS['contract_plugin_load_order'] === ['dependency-base', 'dependency-child'], 'plugin entrypoints load in resolved dependency order');
$loadDiagnostics = plugin_load_diagnostics();
$check(array_keys(plugins_active()) === ['dependency-base', 'dependency-child']
    && isset($loadDiagnostics['failure-base'], $loadDiagnostics['failure-child'])
    && str_contains($loadDiagnostics['failure-child'], 'failure-base'),
    'entrypoint failure removes the plugin and its runtime dependents from active state with diagnostics');

$check(!plugin_disable('dependency-base') && str_contains(plugin_last_error(), 'dependency-child'), 'deactivation is blocked while an active dependent exists');
$check(!plugin_delete('dependency-base') && is_dir(PLUGIN_PATH . '/dependency-base') && str_contains(plugin_last_error(), 'dependency-child'), 'deletion is blocked before filesystem changes while an active dependent exists');
$check(plugin_disable('dependency-child') && plugin_disable('dependency-base'), 'dependencies can be deactivated after their dependents');
$check(!plugin_enable('dependency-child') && str_contains(plugin_last_error(), 'inactive'), 'activation rejects an inactive dependency');
$check(plugin_enable('dependency-base') && plugin_enable('dependency-child'), 'activation succeeds after compatible dependencies are active');
$check(plugin_replacement_dependency_errors('dependency-base', '2.0.0') !== [], 'replacement versions that would break active dependents are rejected');
$check(plugin_order_names_by_dependencies(['dependency-child', 'dependency-base'], true) === ['dependency-base', 'dependency-child']
    && plugin_order_names_by_dependencies(['dependency-base', 'dependency-child'], false) === ['dependency-child', 'dependency-base'],
    'bulk activation is dependency-first while deactivation and uninstall are dependent-first');

$malformed = ['name' => 'bad-dependencies', 'requires' => ['plugins' => ['dependency-base']]];
$check(plugin_requirement_errors($malformed) !== [], 'requires.plugins must be an object mapping slugs to constraints');
$check(plugin_canonical_version_requirement('^1.2.0') === '^1.2.0'
    && plugin_version_requirement_met('1.9.0', '^1.2.0')
    && !plugin_version_requirement_met('2.0.0', '^1.2.0'), 'caret semver constraints are validated and enforced');
$check(plugin_package_requirement_errors(
    ['requires' => ['plugins' => ['dependency-base' => '^1.2.0']]],
    ['requires' => ['plugins' => ['dependency-base' => '>=1.0.0']]]
) !== [], 'store catalog and package plugin dependency contracts must match');
$check(plugin_package_requirement_errors(
    ['requires' => ['plugins' => ['dependency-base' => '^1.2.0']]],
    ['requires' => ['plugins' => []]]
) !== [] && plugin_package_requirement_errors(
    ['requires' => ['plugins' => []]],
    ['requires' => ['plugins' => ['dependency-base' => '^1.2.0']]]
) !== [], 'store/package plugin dependencies are compared in both directions');
$stagedManifest = ['name' => 'staged-contract', 'requires' => ['plugins' => ['not-installed' => '>=1.0.0']]];
$check(plugin_install_requirements_error_message($stagedManifest, false) === ''
    && plugin_install_requirements_error_message($stagedManifest, true) !== '',
    'install-only permits inactive staging while install-and-activate remains dependency-strict');
$check(plugin_install_requirements_error_message([
    'name' => 'bad-core-stage',
    'requires' => ['jyavani' => '>=999.0.0', 'plugins' => ['not-installed' => '>=1.0.0']],
], false) !== '', 'install-only still enforces Core, PHP, extension, and declaration requirements');

$writePlugin('icon-security-contract', '1.0.0');
$iconManifestPath = PLUGIN_PATH . '/icon-security-contract/plugin.json';
$iconManifest = json_decode((string)file_get_contents($iconManifestPath), true);
$iconManifest['icon'] = 'icon.png';
file_put_contents($iconManifestPath, json_encode($iconManifest, JSON_PRETTY_PRINT));
file_put_contents(PLUGIN_PATH . '/icon-security-contract/icon.png', 'image-contract');
file_put_contents(PLUGIN_PATH . '/icon-security-contract/vapid-keys.json', '{"private":"secret"}');
file_put_contents(PLUGIN_PATH . '/icon-security-contract/secret.php', '<?php return "secret";');
$declaredIcon = plugin_declared_icon_file('icon-security-contract', 'icon.png');
$check(is_array($declaredIcon) && $declaredIcon['mime'] === 'image/png'
    && plugin_declared_icon_file('icon-security-contract', 'vapid-keys.json') === null
    && plugin_declared_icon_file('icon-security-contract', 'secret.php') === null,
    'compatibility asset resolution serves only the declared safe image icon, never undeclared JSON or PHP');

$writePlugin('installer-contract', '1.0.0');
$installScript = PLUGIN_PATH . '/installer-contract/install.sh';
file_put_contents($installScript, "#!/bin/sh\nprintf installed > installer.marker\n");
chmod($installScript, 0755);
$installResult = plugin_run_install_script(PLUGIN_PATH . '/installer-contract');
$check($installResult['success'] && $installResult['ran'] && file_get_contents(PLUGIN_PATH . '/installer-contract/installer.marker') === 'installed', 'the shared installer executes the conventional install.sh in the plugin directory');
file_put_contents($installScript, "#!/bin/sh\ni=0\nwhile [ \"\$i\" -lt 100 ]; do\n  printf '0123456789012345678901234567890123456789012345678901234567890123'\n  i=\$((i + 1))\ndone\nexit 7\n");
chmod($installScript, 0755);
$cappedResult = plugin_run_install_script(PLUGIN_PATH . '/installer-contract', 5, 1024);
$check(!$cappedResult['success'] && $cappedResult['truncated'] && strlen($cappedResult['output']) <= 1024
    && str_contains($cappedResult['error'], 'may remain'), 'installer output is capped and failure wording preserves external-side-effect uncertainty');
$childMarker = PLUGIN_PATH . '/installer-contract/timeout-child.marker';
file_put_contents($installScript, "#!/bin/sh\n(sleep 2; printf child > " . escapeshellarg($childMarker) . ") &\nwhile :; do :; done\n");
chmod($installScript, 0755);
$timeoutResult = plugin_run_install_script(PLUGIN_PATH . '/installer-contract', 1, 1024);
usleep(1500000);
$check(!$timeoutResult['success'] && $timeoutResult['timed_out'] && str_contains($timeoutResult['error'], 'timed out'), 'installer timeout stops a long-running install.sh');
$processGroupSupported = function_exists('posix_kill') && (is_executable('/usr/bin/setsid') || is_executable('/bin/setsid'));
$check(!$processGroupSupported || ($timeoutResult['process_group'] && !file_exists($childMarker)), 'supported process-group timeout prevents spawned descendants from writing afterward');
$uploadSource = (string)file_get_contents($root . '/dashboard/admin/plugins/upload.php');
$browseSource = (string)file_get_contents($root . '/dashboard/admin/plugins/browse.php');
$updateSource = (string)file_get_contents($root . '/app/controllers/PluginStoreController.php');
$managerSource = (string)file_get_contents($root . '/dashboard/admin/plugins/index.php');
$check(str_contains($uploadSource, 'plugin_run_install_script($pluginDir)')
    && str_contains($browseSource, 'plugin_run_install_script($pluginDir)')
    && str_contains($updateSource, 'plugin_run_install_script($pluginDir)'),
    'ZIP upload, Store install, and Store update use the same install.sh runner');
$check(substr_count($updateSource, 'plugin_install_requirements_error_message($manifest, $strictPluginDependencies)') >= 1
    && str_contains($updateSource, 'plugin_install_requirements_error_message($packageManifest, $strictPluginDependencies)')
    && str_contains($updateSource, 'plugin_requirement_errors_without_plugin_state($requirementManifest)'),
    'update discovery, package validation, and post-extraction validation consistently honor active state');
$check(str_contains($managerSource, 'plugin_order_names_by_dependencies($pluginNames, true)')
    && str_contains($managerSource, "plugin_order_names_by_dependencies(\$pluginNames, false)"),
    'the plugin manager applies dependency ordering to bulk state changes');

$layout = (string)file_get_contents($root . '/app/layout.php');
$faviconBranch = strpos($layout, 'if ($faviconUrl !== \'\'):');
$appleFilter = strpos($layout, "apply_filters('apple_touch_icon_url'");
$manifestFilter = strpos($layout, "apply_filters('web_manifest_url'");
$themeColorFilter = strpos($layout, "apply_filters('theme_color'");
$appleOutput = strpos($layout, 'is_string($appleTouchIconUrl)');
$manifestOutput = strpos($layout, 'is_string($webManifestUrl)');
$check($appleFilter !== false && $manifestFilter !== false && $themeColorFilter !== false && $faviconBranch !== false
    && $appleFilter < $faviconBranch && $appleOutput > $faviconBranch && $manifestOutput > $faviconBranch,
    'web manifest, apple touch icon, and theme color are independently filterable');

$worker = core_service_worker_script();
$check(str_contains($worker, 'self.skipWaiting()') && str_contains($worker, 'self.clients.claim()')
    && !str_contains($worker, 'addEventListener("push"') && !str_contains($worker, 'failed-entrypoint-contribution'),
    'Core produces a lifecycle-only worker and excludes contributions from failed entrypoints');
add_filter('service_worker_script', static fn(string $script): string => $script . 'self.addEventListener("push", function () {});');
$check(str_contains(core_service_worker_script(), 'addEventListener("push"'), 'Core preserves service_worker_script contributions');
$router = (string)file_get_contents($root . '/public/router.php');
$check(str_contains($router, 'echo core_service_worker_script();')
    && strpos($router, "if (\$pathTrimmed === 'sw.js')") < strpos($router, "apply_filters('router_path'"),
    'the root /sw.js route always returns Core worker code before plugin path rewrites');
$check(str_contains($router, 'plugin_declared_icon_file($pluginName, $pluginFile)')
    && !str_contains($router, "mime_content_type(\$absFile)"),
    'plugin compatibility static route delegates to declared-icon validation with fixed MIME types');

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
