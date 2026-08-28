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
require_once $root . '/cfg/helpers/mail.php';
require_once $root . '/plugins/index.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['pdo'] = $pdo;

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
file_put_contents(PLUGIN_PATH . '/failure-base/plugin.php', '<?php add_filter("service_worker_script", static fn(string $script): string => $script . "failed-entrypoint-contribution"); register_frontend_route("failed-route", static function (): void {}, ["match" => "exact", "methods" => ["GET"]]); jy_mail_register_transport("failed_plugin_transport", static fn(): array => ["status" => "accepted"]); throw new RuntimeException("entrypoint contract failure");');
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
$check(!isset(jy_mail_transports()['failed_plugin_transport']), 'entrypoint failure rolls back plugin mail transport registration');
$check(resolve_frontend_route('failed-route', 'GET') === null, 'entrypoint failure rolls back enhanced frontend route registration');

$check(!plugin_disable('dependency-base') && str_contains(plugin_last_error(), 'dependency-child'), 'deactivation is blocked while an active dependent exists');
$check(!plugin_delete('dependency-base') && is_dir(PLUGIN_PATH . '/dependency-base') && str_contains(plugin_last_error(), 'dependency-child'), 'deletion is blocked before filesystem changes while an active dependent exists');
$denyDisable = static function (array $state, string $name, string $operation): array {
    return $name === 'dependency-child' && $operation === 'disable'
        ? ['allowed' => false, 'message' => 'Contract denied deactivation.']
        : $state;
};
add_filter('plugin_state_change_preflight', $denyDisable);
$check(!plugin_disable('dependency-child') && plugin_last_error() === 'Contract denied deactivation.', 'plugin disable enforces a valid generic state-change denial');
$reverseDenial = static fn(array $state): array => ['allowed' => true, 'message' => ''];
add_filter('plugin_state_change_preflight', $reverseDenial, 20);
$check(!plugin_disable('dependency-child') && plugin_last_error() === 'Contract denied deactivation.', 'later preflight listeners cannot reverse an earlier denial');
remove_filter('plugin_state_change_preflight', $reverseDenial, 20);
remove_filter('plugin_state_change_preflight', $denyDisable);
$malformedState = static fn(array $state): array => ['allowed' => 'yes', 'message' => ''];
add_filter('plugin_state_change_preflight', $malformedState);
$check(!plugin_disable('dependency-child') && str_contains(plugin_last_error(), 'denied'), 'malformed plugin state-change output fails closed');
remove_filter('plugin_state_change_preflight', $malformedState);
$throwingState = static function (array $state): array { throw new RuntimeException('preflight listener failed'); };
add_filter('plugin_state_change_preflight', $throwingState);
$check(!plugin_disable('dependency-child') && str_contains(plugin_last_error(), 'denied'), 'throwing plugin state-change listener fails closed');
remove_filter('plugin_state_change_preflight', $throwingState);
$check(plugin_disable('dependency-child') && plugin_disable('dependency-base'), 'dependencies can be deactivated after their dependents');
$check(!plugin_enable('dependency-child') && str_contains(plugin_last_error(), 'inactive'), 'activation rejects an inactive dependency');
$check(plugin_enable('dependency-base') && plugin_enable('dependency-child'), 'activation succeeds after compatible dependencies are active');
$writePlugin('self-modifying-activation', '1.0.0');
mkdir(PLUGIN_PATH . '/self-modifying-activation/migrations', 0775, true);
file_put_contents(PLUGIN_PATH . '/self-modifying-activation/migrations/0001-change.php', <<<'PHP'
<?php
return static function (PDO $pdo): void {
    file_put_contents(__FILE__, "\n// activation mutation\n", FILE_APPEND);
};
PHP);
$selfModifyingLocks = plugin_lifecycle_locks('self-modifying-activation');
$selfModifyingDisabled = is_array($selfModifyingLocks) && _plugin_mark_disabled_already_locked('self-modifying-activation');
if (is_array($selfModifyingLocks)) theme_operation_release($selfModifyingLocks);
plugin_reset_runtime_cache();
$selfModifyingActivation = plugin_enable('self-modifying-activation', $pdo);
$selfModifyingTracked = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'self-modifying-activation'")->fetchColumn();
$check($selfModifyingDisabled && !$selfModifyingActivation && $selfModifyingTracked === 0
    && in_array('self-modifying-activation', plugin_disabled_names(), true),
    'activation rejects a self-modifying migration before ledger or enabled-state mutation');
$heldLifecycle = plugin_lifecycle_locks('dependency-child');
$check(is_array($heldLifecycle) && !plugin_disable('dependency-child') && str_contains(plugin_last_error(), 'lock'), 'plugin lifecycle callback re-entry fails instead of waiting on its own global lock');
if (is_array($heldLifecycle)) theme_operation_release($heldLifecycle);
$check(plugin_replacement_dependency_errors('dependency-base', '2.0.0') !== [], 'replacement versions that would break active dependents are rejected');
$check(plugin_order_names_by_dependencies(['dependency-child', 'dependency-base'], true) === ['dependency-base', 'dependency-child']
    && plugin_order_names_by_dependencies(['dependency-base', 'dependency-child'], false) === ['dependency-child', 'dependency-base'],
    'bulk activation is dependency-first while deactivation and uninstall are dependent-first');

$writePlugin('preflight-delete', '1.0.0');
$denyDelete = static function (array $state, string $name, string $operation): array {
    return $name === 'preflight-delete' && $operation === 'delete'
        ? ['allowed' => false, 'message' => 'Contract denied deletion.']
        : $state;
};
add_filter('plugin_state_change_preflight', $denyDelete);
$check(!plugin_delete('preflight-delete') && is_dir(PLUGIN_PATH . '/preflight-delete')
    && plugin_last_error() === 'Contract denied deletion.', 'plugin delete denies before filesystem mutation');
$check(!plugin_uninstall('preflight-delete', false, $pdo) && is_dir(PLUGIN_PATH . '/preflight-delete'), 'plugin uninstall enforces delete preflight before uninstall listeners or files');
remove_filter('plugin_state_change_preflight', $denyDelete);
plugin_migrations_ensure_table($pdo);
$insertMigration = $pdo->prepare('INSERT INTO plugin_migrations (plugin_name, migration, checksum, applied_version) VALUES (?, ?, ?, ?)');

$writePlugin('not-loaded-complete-uninstall', '1.0.0');
$pdo->exec('CREATE TABLE not_loaded_complete_schema (id INTEGER PRIMARY KEY)');
$insertMigration->execute(['not-loaded-complete-uninstall', '0001-schema.sql', str_repeat('2', 64), '1.0.0']);
plugin_reset_runtime_cache();
$notLoadedWasActive = plugin_is_active('not-loaded-complete-uninstall');
$notLoadedResult = plugin_uninstall('not-loaded-complete-uninstall', false, $pdo);
$notLoadedLedger = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'not-loaded-complete-uninstall'")->fetchColumn();
$check($notLoadedWasActive && !$notLoadedResult && $notLoadedLedger === 1
    && in_array('not_loaded_complete_schema', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN), true)
    && is_dir(PLUGIN_PATH . '/not-loaded-complete-uninstall')
    && str_contains(plugin_last_error(), 'loaded successfully in this request'),
    'active complete uninstall is denied before mutation when its entrypoint has not loaded in this request');

$writePlugin('disabled-complete-uninstall', '1.0.0', [], true);
file_put_contents(PLUGIN_PATH . '/disabled-complete-uninstall/plugin.php', '<?php add_action("plugin_uninstall", static function (string $name): void { if ($name === "disabled-complete-uninstall") $GLOBALS["pdo"]->exec("DROP TABLE disabled_complete_schema"); });');
$pdo->exec('CREATE TABLE disabled_complete_schema (id INTEGER PRIMARY KEY)');
$insertMigration->execute(['disabled-complete-uninstall', '0001-schema.sql', str_repeat('c', 64), '1.0.0']);
plugin_reset_runtime_cache();
plugin_load_active();
$disabledCompleteLocks = plugin_lifecycle_locks('disabled-complete-uninstall');
$disabledCompleteMarked = is_array($disabledCompleteLocks) && _plugin_mark_disabled_already_locked('disabled-complete-uninstall');
if (is_array($disabledCompleteLocks)) theme_operation_release($disabledCompleteLocks);
$disabledCompleteResult = plugin_uninstall('disabled-complete-uninstall', false, $pdo);
$disabledCompleteLedger = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'disabled-complete-uninstall'")->fetchColumn();
$check($disabledCompleteMarked && !$disabledCompleteResult && $disabledCompleteLedger === 1
    && in_array('disabled_complete_schema', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN), true)
    && is_dir(PLUGIN_PATH . '/disabled-complete-uninstall')
    && str_contains(plugin_last_error(), 'Activate it first'),
    'disabled complete uninstall is denied before ledger, schema, or file mutation');

$writePlugin('residual-uninstall', '1.0.0', [], true);
file_put_contents(PLUGIN_PATH . '/residual-uninstall/plugin.php', '<?php add_action("plugin_uninstall", static function (string $name): void { if ($name === "residual-uninstall") $GLOBALS["pdo"]->exec("DROP TABLE residual_uninstall_schema"); });');
$pdo->exec('CREATE TABLE residual_uninstall_schema (id INTEGER PRIMARY KEY)');
$insertMigration->execute(['residual-uninstall', '0001-schema.sql', str_repeat('d', 64), '1.0.0']);
plugin_reset_runtime_cache();
plugin_load_active();
$residualUninstallPath = package_unique_publication_recovery_path(PLUGIN_PATH . '/residual-uninstall', 'old');
if (is_string($residualUninstallPath)) mkdir($residualUninstallPath, 0700);
$residualUninstallResult = plugin_uninstall('residual-uninstall', false, $pdo);
$residualUninstallLedger = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'residual-uninstall'")->fetchColumn();
$check(!$residualUninstallResult && $residualUninstallLedger === 1
    && in_array('residual_uninstall_schema', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN), true)
    && is_dir(PLUGIN_PATH . '/residual-uninstall'),
    'complete uninstall detects publication recovery paths before ledger or schema mutation');

$writePlugin('active-hook-uninstall', '1.0.0', [], true);
file_put_contents(PLUGIN_PATH . '/active-hook-uninstall/plugin.php', '<?php add_action("plugin_uninstall", static function (string $name): void { if ($name !== "active-hook-uninstall") return; $GLOBALS["active_hook_ledger"] = (int)$GLOBALS["pdo"]->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = \'active-hook-uninstall\'")->fetchColumn(); $GLOBALS["pdo"]->exec("DROP TABLE active_hook_schema"); });');
$pdo->exec('CREATE TABLE active_hook_schema (id INTEGER PRIMARY KEY)');
$insertMigration->execute(['active-hook-uninstall', '0001-schema.sql', str_repeat('e', 64), '1.0.0']);
plugin_reset_runtime_cache();
plugin_load_active();
$activeHookResult = plugin_uninstall('active-hook-uninstall', false, $pdo);
$check($activeHookResult && ($GLOBALS['active_hook_ledger'] ?? null) === 0
    && !in_array('active_hook_schema', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN), true)
    && !is_dir(PLUGIN_PATH . '/active-hook-uninstall'),
    'active complete uninstall executes its already-loaded cleanup hook and removes schema');

$writePlugin('active-no-hook-uninstall', '1.0.0');
$insertMigration->execute(['active-no-hook-uninstall', '0001-schema.sql', str_repeat('f', 64), '1.0.0']);
plugin_reset_runtime_cache();
plugin_load_active();
$activeNoHookResult = plugin_uninstall('active-no-hook-uninstall', false, $pdo);
$activeNoHookLedger = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'active-no-hook-uninstall'")->fetchColumn();
$check($activeNoHookResult && $activeNoHookLedger === 0 && !is_dir(PLUGIN_PATH . '/active-no-hook-uninstall'),
    'active successfully-loaded plugin with no cleanup hooks can uninstall completely');

$writePlugin('isolated-uninstall', '1.0.0');
mkdir(PLUGIN_PATH . '/isolated-uninstall/migrations', 0775, true);
$isolatedMigration = PLUGIN_PATH . '/isolated-uninstall/migrations/0001-schema.sql';
file_put_contents($isolatedMigration, 'CREATE TABLE isolated_uninstall_schema (id INTEGER PRIMARY KEY);');
$insertMigration->execute(['isolated-uninstall', '0001-schema.sql', hash_file('sha256', $isolatedMigration), '1.0.0']);
plugin_reset_runtime_cache();
plugin_load_active();
$uninstallCleanupCalls = [];
$throwingCleanup = static function (string $name): void {
    if ($name === 'isolated-uninstall') throw new RuntimeException('cleanup failed');
};
$continuingCleanup = static function (string $name) use (&$uninstallCleanupCalls): void {
    if ($name === 'isolated-uninstall') $uninstallCleanupCalls[] = $name;
};
add_action('plugin_uninstall', $throwingCleanup, 5);
add_action('plugin_uninstall', $continuingCleanup, 10);
$isolatedUninstall = plugin_uninstall('isolated-uninstall', false, $pdo);
$isolatedLedger = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'isolated-uninstall'")->fetchColumn();
$check(!$isolatedUninstall && $uninstallCleanupCalls === ['isolated-uninstall']
    && $isolatedLedger === 0 && is_dir(PLUGIN_PATH . '/isolated-uninstall')
    && in_array('isolated-uninstall', plugin_disabled_names(), true),
    'cleanup listener failure preserves inactive files with cleared history for safe retry');
remove_action('plugin_uninstall', $throwingCleanup, 5);
remove_action('plugin_uninstall', $continuingCleanup, 10);
$recoveredActivation = plugin_enable('isolated-uninstall', $pdo);
$check($recoveredActivation
    && in_array('isolated_uninstall_schema', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN), true)
    && plugin_uninstall('isolated-uninstall', false, $pdo),
    'failed cleanup can retry after activation replays cleared migration history');

$writePlugin('transaction-cleanup', '1.0.0');
$insertMigration->execute(['transaction-cleanup', '0001-schema.sql', str_repeat('1', 64), '1.0.0']);
plugin_reset_runtime_cache();
plugin_load_active();
$transactionCleanup = static function (string $name) use ($pdo): void {
    if ($name !== 'transaction-cleanup') return;
    $pdo->beginTransaction();
    $pdo->exec('CREATE TABLE transaction_cleanup_leak (id INTEGER PRIMARY KEY)');
};
add_action('plugin_uninstall', $transactionCleanup);
$transactionCleanupResult = plugin_uninstall('transaction-cleanup', false, $pdo);
remove_action('plugin_uninstall', $transactionCleanup);
$transactionCleanupLedger = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'transaction-cleanup'")->fetchColumn();
$check(!$transactionCleanupResult && !$pdo->inTransaction() && $transactionCleanupLedger === 0
    && !in_array('transaction_cleanup_leak', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN), true)
    && is_dir(PLUGIN_PATH . '/transaction-cleanup')
    && in_array('transaction-cleanup', plugin_disabled_names(), true),
    'cleanup listener transaction leakage is rolled back while inactive files and cleared history remain for retry');

$writePlugin('keep-data-uninstall', '1.0.0');
$insertMigration->execute(['keep-data-uninstall', '0001-schema.sql', str_repeat('b', 64), '1.0.0']);
$keepDataUninstall = plugin_uninstall('keep-data-uninstall', true, $pdo);
$keptLedger = (int)$pdo->query("SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name = 'keep-data-uninstall'")->fetchColumn();
$check($keepDataUninstall && $keptLedger === 1, 'keep-data uninstall retains migration history so reinstall does not replay retained schema');

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
$registry = (string)file_get_contents($root . '/plugins/index.php');
$check(str_contains($uploadSource, 'plugin_publish_staged_install_already_locked($prepared, $activatePlugin, $pdo)')
    && str_contains($browseSource, 'plugin_publish_staged_install_already_locked($prepared, $activatePlugin, $pdo)')
    && str_contains($registry, 'plugin_run_install_script($pluginDir)')
    && str_contains($updateSource, 'plugin_run_install_script($pluginDir)'),
    'ZIP upload, Store install, and Store update use the same install.sh runner');
$installPublishAt = strpos($registry, 'function plugin_publish_staged_install_already_locked');
$installMigrationAt = $installPublishAt === false ? false : strpos($registry, 'plugin_migrations_run_pending_already_locked(', $installPublishAt);
$installScriptAt = $installPublishAt === false ? false : strpos($registry, 'plugin_run_install_script($pluginDir)', $installPublishAt);
$installRevalidationAt = $installScriptAt === false ? false : strpos($registry, 'plugin_migrations_assert_complete_already_locked(', $installScriptAt);
$installFailureAt = $installPublishAt === false ? false : strpos($registry, "if (!\$installResult['success'])", $installPublishAt);
$updatePlanAt = strpos($updateSource, 'plugin_migrations_plan_already_locked(');
$updatePublishAt = strpos($updateSource, 'package_guarded_publish($stage, $pluginDir, $oldIdentity, $newIdentity)');
$updateMigrationAt = strpos($updateSource, 'plugin_migrations_run_pending_already_locked(');
$updateScriptAt = strpos($updateSource, 'plugin_run_install_script($pluginDir)');
$updateRevalidationAt = $updateScriptAt === false ? false : strpos($updateSource, 'plugin_migrations_assert_complete_already_locked(', $updateScriptAt);
$updateFailureAt = $updateScriptAt === false ? false : strpos($updateSource, "if (!\$installResult['success'])", $updateScriptAt);
$check($installMigrationAt !== false && $installScriptAt !== false && $installRevalidationAt !== false && $installFailureAt !== false
    && $installMigrationAt < $installScriptAt && $installScriptAt < $installRevalidationAt && $installRevalidationAt < $installFailureAt
    && $updatePlanAt !== false && $updatePublishAt !== false && $updatePlanAt < $updatePublishAt
    && $updateMigrationAt !== false && $updateScriptAt !== false && $updateRevalidationAt !== false && $updateFailureAt !== false
    && $updateMigrationAt < $updateScriptAt && $updateScriptAt < $updateRevalidationAt && $updateRevalidationAt < $updateFailureAt,
    'install and update validate and run fixed-directory migrations before installers and update publication is preflighted');
$updateDisableAt = strpos($updateSource, '_plugin_mark_disabled_already_locked($name)');
$updateEnableAt = strpos($updateSource, '_plugin_enable_already_locked($name, $pdo)');
$updateFinalizeAt = strpos($updateSource, 'package_guarded_finalize($pluginDir, $rollbackPath, $oldIdentity)');
$check($updateDisableAt !== false && $updateMigrationAt !== false && $updateDisableAt < $updateMigrationAt
    && $updateEnableAt !== false && $updateFinalizeAt !== false && $updateEnableAt < $updateFinalizeAt
    && str_contains($updateSource, 'Database changes may remain; the plugin is inactive.'),
    'updates disable before pending schema work, restore enabled state only at the end, and report irreversible database risk');
$check(str_contains($updateSource, '$staticRestored = $restoreStatic($restoration);')
    && str_contains($updateSource, 'Plugin file or static asset rollback failed; database changes remain and the plugin is inactive.'),
    'reactivation failure restores old static assets as well as the old plugin tree');
$installDisableAt = $installPublishAt === false ? false : strpos($registry, '_plugin_mark_disabled_already_locked($name)', $installPublishAt);
$installRenameAt = $installPublishAt === false ? false : strpos($registry, '@rename($stage, $pluginDir)', $installPublishAt);
$check(str_contains($uploadSource, '$installLocks = plugin_lifecycle_locks($pluginName)')
    && str_contains($browseSource, '$installLocks = plugin_lifecycle_locks($pluginName)')
    && str_contains($updateSource, '$operationLocks = function_exists(\'plugin_lifecycle_locks\')')
    && str_contains($registry, '_plugin_mark_disabled_already_locked($name)')
    && $installDisableAt !== false && $installRenameAt !== false && $installDisableAt < $installRenameAt,
    'plugin ZIP upload, Store install, and Store update hold one top-level global-first lifecycle lock and use already-locked state helpers');
$check(substr_count($updateSource, 'plugin_install_requirements_error_message($manifest, $strictPluginDependencies)') >= 1
    && str_contains($updateSource, 'plugin_install_requirements_error_message($packageManifest, $strictPluginDependencies)')
    && str_contains($updateSource, 'plugin_requirement_errors_without_plugin_state($requirementManifest)'),
    'update discovery, package validation, and post-extraction validation consistently honor active state');
$check(str_contains($managerSource, 'plugin_order_names_by_dependencies($pluginNames, true)')
    && str_contains($managerSource, "plugin_order_names_by_dependencies(\$pluginNames, false)"),
    'the plugin manager applies dependency ordering to bulk state changes');
$check(str_contains($managerSource, "'/plugins/icon/' . rawurlencode(\$name) . '/'")
    && !str_contains($managerSource, "'/plugins/static/' . rawurlencode(\$name)"),
    'plugin manager uses an extensionless icon URL that reaches Core behind static nginx rules');

$allInstalledNames = array_keys(plugins_all());
file_put_contents(PLUGIN_DISABLED_JSON, '{broken');
$check(plugin_disabled_names() === $allInstalledNames, 'malformed plugin state fails closed by disabling every installed plugin');
$stateLocks = plugin_lifecycle_locks('dependency-base');
$stateReset = is_array($stateLocks) && _plugin_write_disabled_names_already_locked([]);
if (is_array($stateLocks)) theme_operation_release($stateLocks);
$check($stateReset && trim((string)file_get_contents(PLUGIN_DISABLED_JSON)) === '[]', 'plugin state replacement publishes one complete validated JSON document');
$check(str_contains($registry, "fopen(\$temporary, 'x+b')") && str_contains($registry, 'fflush($handle)')
    && str_contains($registry, 'fsync($handle)') && str_contains($registry, 'rename($temporary, $file)'),
    'plugin disabled state uses same-directory exclusive flush/fsync/rename replacement');

require_once $root . '/app/controllers/PluginStoreController.php';
$writePluginTransient = new ReflectionMethod(PluginStoreController::class, 'writeTransient');
$removePluginUpdate = new ReflectionMethod(PluginStoreController::class, 'removeCachedUpdate');
$writePluginTransient->invoke(null, ['updates' => ['dependency-base' => [
    'current_version' => '1.0.0', 'new_version' => '3.0.0', 'actionable' => true, 'checked_at' => time(),
]]]);
$removePluginUpdate->invoke(null, 'dependency-base', '2.0.0');
$check((PluginStoreController::getCachedUpdates()['dependency-base']['new_version'] ?? '') === '3.0.0', 'plugin completion retains a genuinely newer discovered update');
$removePluginUpdate->invoke(null, 'dependency-base', '3.0.0');
$check(!isset(PluginStoreController::getCachedUpdates()['dependency-base']), 'plugin completion removes exactly the installed update generation');
$applyMethodAt = strpos($updateSource, 'private static function applyUpdateAlreadyLocked');
$check(str_contains($updateSource, 'package_guarded_publish($stage, $pluginDir, $oldIdentity, $newIdentity)')
    && str_contains($updateSource, 'package_guarded_rollback($pluginDir, $rollbackPath, $oldIdentity)')
    && str_contains($updateSource, 'package_guarded_finalize($pluginDir, $rollbackPath, $oldIdentity)')
    && $applyMethodAt !== false && !str_contains(substr($updateSource, $applyMethodAt, 15000), 'extractTo('),
    'plugin updates guard complete-tree publication, exact rollback, and final old-tree cleanup');
$firstPluginRecovery = package_private_directory(BACKEND_PATH . '/var', 'plugin-dependency-base-recovery');
file_put_contents($firstPluginRecovery . '/preserved.marker', 'recovery');
$secondPluginRecovery = package_private_directory(BACKEND_PATH . '/var', 'plugin-dependency-base-recovery');
$check($firstPluginRecovery !== $secondPluginRecovery && is_file($firstPluginRecovery . '/preserved.marker')
    && is_dir($secondPluginRecovery), 'plugin recovery attempts never reuse or overwrite a preserved artifact');

$layout = (string)file_get_contents($root . '/app/layout.php');
$faviconBranch = strpos($layout, 'if ($faviconUrl !== \'\'):');
$appleFilter = strpos($layout, "apply_filters('apple_touch_icon_url'");
$manifestFilter = strpos($layout, "apply_filters('web_manifest_url'");
$themeColorFilter = strpos($layout, "apply_filters('theme_color'");
$appleOutput = strpos($layout, 'is_string($appleTouchIconUrl)');
$manifestOutput = strpos($layout, 'is_string($webManifestUrl)');
$check($appleFilter !== false && $manifestFilter !== false && $themeColorFilter !== false && $faviconBranch !== false
    && $appleOutput !== false && $manifestOutput !== false
    && $appleFilter < $faviconBranch && $appleOutput > $faviconBranch && $manifestOutput > $faviconBranch,
    'web manifest, apple touch icon, and theme color are independently filterable');
$check(str_contains($layout, "apply_filters('web_manifest_url', '', \$pdo)"),
    'Core does not advertise an installable web manifest without a PWA plugin contribution');

$worker = core_service_worker_script();
$check(str_contains($worker, 'self.skipWaiting()') && str_contains($worker, 'self.clients.claim()')
    && !str_contains($worker, 'addEventListener("push"') && !str_contains($worker, 'failed-entrypoint-contribution'),
    'Core produces a lifecycle-only worker and excludes contributions from failed entrypoints');
add_filter('service_worker_script', static fn(string $script): string => $script . 'self.addEventListener("push", function () {});');
$check(str_contains(core_service_worker_script(), 'addEventListener("push"'), 'Core preserves service_worker_script contributions');
$router = (string)file_get_contents($root . '/public/router.php');
$workerRouteAt = strpos($router, "if (\$pathTrimmed === 'sw.js')");
$routerFilterAt = strpos($router, 'router_apply_path_filter($pdo, $pathTrimmed)');
$check(str_contains($router, 'echo core_service_worker_script();')
    && $workerRouteAt !== false && $routerFilterAt !== false && $workerRouteAt < $routerFilterAt,
    'the root /sw.js route always returns Core worker code before plugin path rewrites');
$check(str_contains($router, 'plugin_declared_icon_file($pluginName, $pluginFile)')
    && !str_contains($router, "mime_content_type(\$absFile)"),
    'plugin compatibility static route delegates to declared-icon validation with fixed MIME types');
$check(str_contains($router, "(\$segments[1] ?? '') === 'icon'")
    && str_contains($router, "is_string(\$manifest['icon'] ?? null)")
    && str_contains($router, 'plugin_declared_icon_file($pluginName, $pluginFile)'),
    'extensionless plugin icon route serves only the manifest-declared image');

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
