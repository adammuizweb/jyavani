<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-package-lifecycle-' . bin2hex(random_bytes(6));
mkdir($fixture . '/cfg/var', 0750, true);
mkdir($fixture . '/plugins', 0775, true);
mkdir($fixture . '/public', 0775, true);
define('BACKEND_PATH', $fixture . '/cfg');
define('PLUGIN_PATH', $fixture . '/plugins');
define('PUBLIC_PATH', $fixture . '/public');
define('PLUGIN_DISABLED_JSON', BACKEND_PATH . '/var/plugins-disabled.json');
file_put_contents(PLUGIN_DISABLED_JSON, "[]\n");

require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/theme_helper.php';
require_once $root . '/plugins/index.php';
require_once $root . '/app/controllers/PluginStoreController.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    }
    @rmdir($path);
};
$zipWith = static function (string $path, array $entries, array $types = []): void {
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
        if (isset($types[$name])) $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, ($types[$name] | 0777) << 16);
    }
    $zip->close();
};

try {
    $attacks = [
        'traversal' => [['plugin.json' => '{}', '../escape.php' => 'x'], []],
        'drive' => [['plugin.json' => '{}', 'C:/escape.php' => 'x'], []],
        'case collision' => [['plugin.json' => '{}', 'Code.php' => 'a', 'code.php' => 'b'], []],
        'symlink' => [['plugin.json' => '{}', 'link.php' => 'target'], ['link.php' => 0120000]],
        'special file' => [['plugin.json' => '{}', 'pipe' => 'x'], ['pipe' => 0010000]],
    ];
    foreach ($attacks as $label => [$entries, $types]) {
        $path = $fixture . '/' . str_replace(' ', '-', $label) . '.zip';
        $zipWith($path, $entries, $types);
        $zip = new ZipArchive();
        $zip->open($path);
        $result = package_archive_validate($zip);
        $zip->close();
        $check(!$result['success'], 'shared package validator rejects ' . $label);
    }
    $ratio = $fixture . '/ratio.zip';
    $zipWith($ratio, ['plugin.json' => '{}', 'bomb.php' => str_repeat('A', 2 * 1024 * 1024)]);
    $ratioZip = new ZipArchive();
    $ratioZip->open($ratio);
    $check(!package_archive_validate($ratioZip)['success'], 'shared package validator rejects extreme compression ratios');
    $ratioZip->close();

    $old = $fixture . '/old';
    $new = $fixture . '/new';
    mkdir($old, 0755);
    mkdir($new, 0755);
    file_put_contents($old . '/same.txt', 'old');
    mkdir($old . '/empty', 0755);
    file_put_contents($new . '/same.txt', 'new');
    $oldIdentity = package_tree_identity($old);
    $newIdentity = package_tree_identity($new);
    $publicationLocks = theme_operation_acquire(theme_lifecycle_lock_keys(['publication-contract']));
    $publication = package_guarded_publish($new, $old, $oldIdentity, $newIdentity);
    file_put_contents($old . '/failed-extra.txt', 'extra');
    $rollback = $publication['success']
        ? package_guarded_rollback($old, $publication['rollback_path'], $oldIdentity)
        : ['restored' => false];
    theme_operation_release($publicationLocks);
    $check($publication['success'] && $rollback['restored'] && package_tree_matches_identity($old, $oldIdentity)
        && !file_exists($old . '/failed-extra.txt'), 'guarded publication and rollback restore exact old hashes, modes, empty directories, and absence of extras');

    $staleStage = package_private_directory($fixture, 'stale-stage');
    file_put_contents($staleStage . '/same.txt', 'newer');
    $staleIdentity = package_tree_identity($staleStage);
    $stalePath = package_unique_publication_recovery_path($old, 'old');
    rename($old, $stalePath);
    $staleLocks = theme_operation_acquire(theme_lifecycle_lock_keys(['publication-contract']));
    $staleResult = package_guarded_publish($staleStage, $old, $oldIdentity, $staleIdentity);
    theme_operation_release($staleLocks);
    $check(!$staleResult['success'] && is_dir($stalePath) && !is_dir($old)
        && in_array($stalePath, $staleResult['recovery_paths'], true),
        'next publication detects a process-kill recovery path, fails closed, and preserves the known-good old tree');
    rename($stalePath, $old);
    package_remove_tree($staleStage);

    $manifest = ['name' => 'reconcile-contract', 'version' => '1.0.0'];
    mkdir(PLUGIN_PATH . '/reconcile-contract', 0755);
    file_put_contents(PLUGIN_PATH . '/reconcile-contract/plugin.json', json_encode($manifest));
    PluginStoreController::reconcileInstalledState('reconcile-contract');
    $readTransient = new ReflectionMethod(PluginStoreController::class, 'readTransient');
    $firstToken = (string)($readTransient->invoke(null)['reconciliations']['reconcile-contract'] ?? '');
    package_remove_tree(PLUGIN_PATH . '/reconcile-contract');
    mkdir(PLUGIN_PATH . '/reconcile-contract', 0755);
    file_put_contents(PLUGIN_PATH . '/reconcile-contract/plugin.json', json_encode($manifest));
    PluginStoreController::reconcileInstalledState('reconcile-contract');
    $secondToken = (string)($readTransient->invoke(null)['reconciliations']['reconcile-contract'] ?? '');
    $check($firstToken !== '' && $secondToken !== '' && !hash_equals($firstToken, $secondToken),
        'same-version uninstall/reinstall advances reconciliation identity and invalidates stale candidates');

    if (function_exists('pcntl_fork')) {
        $visibilityTarget = $fixture . '/visibility-tree';
        mkdir($visibilityTarget, 0755);
        file_put_contents($visibilityTarget . '/generation.txt', 'old');
        file_put_contents($visibilityTarget . '/old-only.txt', 'old');
        $visibilityOldIdentity = package_tree_identity($visibilityTarget);
        $oldReaderLocks = theme_operation_acquire([THEME_LIFECYCLE_LOCK_KEY], LOCK_SH);
        $oldReaderSawComplete = trim((string)file_get_contents($visibilityTarget . '/generation.txt')) === 'old'
            && is_file($visibilityTarget . '/old-only.txt') && !is_file($visibilityTarget . '/complete.txt');
        theme_operation_release($oldReaderLocks);
        $visibilityStage = package_private_directory($fixture, 'visibility-stage');
        file_put_contents($visibilityStage . '/generation.txt', 'new');
        file_put_contents($visibilityStage . '/complete.txt', 'complete');
        $visibilityNewIdentity = package_tree_identity($visibilityStage);
        $visibilityPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $visibilityPid = is_array($visibilityPair) ? pcntl_fork() : -1;
        if ($visibilityPid === 0) {
            fclose($visibilityPair[0]);
            $GLOBALS['_theme_operation_held_keys'] = [];
            $GLOBALS['_theme_operation_lock_keys'] = [];
            $GLOBALS['_theme_operation_lock_modes'] = [];
            trim((string)fgets($visibilityPair[1]));
            $readerLocks = theme_operation_acquire([THEME_LIFECYCLE_LOCK_KEY], LOCK_SH);
            $generation = is_file($visibilityTarget . '/generation.txt')
                ? trim((string)file_get_contents($visibilityTarget . '/generation.txt'))
                : 'missing';
            $complete = $generation === 'old'
                ? is_file($visibilityTarget . '/old-only.txt') && !is_file($visibilityTarget . '/complete.txt')
                : $generation === 'new' && is_file($visibilityTarget . '/complete.txt') && !is_file($visibilityTarget . '/old-only.txt');
            theme_operation_release($readerLocks);
            fwrite($visibilityPair[1], json_encode(['generation' => $generation, 'complete' => $complete]) . "\n");
            fclose($visibilityPair[1]);
            exit(0);
        }
        if ($visibilityPid > 0) {
            fclose($visibilityPair[1]);
            $writerLocks = theme_operation_acquire(theme_lifecycle_lock_keys(['visibility-contract']));
            fwrite($visibilityPair[0], "read\n");
            usleep(150000);
            stream_set_blocking($visibilityPair[0], false);
            $beforePublication = fread($visibilityPair[0], 4096);
            $visibilityPublication = package_guarded_publish(
                $visibilityStage,
                $visibilityTarget,
                $visibilityOldIdentity,
                $visibilityNewIdentity
            );
            usleep(150000);
            $afterPublication = fread($visibilityPair[0], 4096);
            $finalized = $visibilityPublication['success'] && package_guarded_finalize(
                $visibilityTarget,
                $visibilityPublication['rollback_path'],
                $visibilityOldIdentity
            );
            theme_operation_release($writerLocks);
            stream_set_blocking($visibilityPair[0], true);
            stream_set_timeout($visibilityPair[0], 5);
            $visibilityRead = json_decode(trim((string)fgets($visibilityPair[0])), true);
            fclose($visibilityPair[0]);
            pcntl_waitpid($visibilityPid, $visibilityStatus);
            $check($oldReaderSawComplete && $beforePublication === '' && $afterPublication === '' && $finalized
                && ($visibilityRead['generation'] ?? '') === 'new' && ($visibilityRead['complete'] ?? false) === true,
                'shared readers see a complete old tree or block throughout publication and then see the complete new tree');
        } else {
            $check(false, 'forked shared reader blocks throughout guarded two-rename publication and sees only the complete new tree');
        }

        $upgradePair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $upgradePid = is_array($upgradePair) ? pcntl_fork() : -1;
        if ($upgradePid === 0) {
            fclose($upgradePair[0]);
            $GLOBALS['_theme_operation_held_keys'] = [];
            $GLOBALS['_theme_operation_lock_keys'] = [];
            $GLOBALS['_theme_operation_lock_modes'] = [];
            $otherReader = theme_operation_acquire([THEME_LIFECYCLE_LOCK_KEY], LOCK_SH);
            fwrite($upgradePair[1], "held\n");
            usleep(300000);
            theme_operation_release($otherReader);
            fclose($upgradePair[1]);
            exit(0);
        }
        if ($upgradePid > 0) {
            fclose($upgradePair[1]);
            stream_set_timeout($upgradePair[0], 5);
            $otherHeld = trim((string)fgets($upgradePair[0])) === 'held';
            theme_lifecycle_reader_start();
            $upgradeStarted = microtime(true);
            $apiWriterLocks = plugin_lifecycle_locks('upgrade-contract');
            $upgradeElapsed = microtime(true) - $upgradeStarted;
            $upgraded = is_array($apiWriterLocks)
                && theme_operation_holds_lock(THEME_LIFECYCLE_LOCK_KEY, LOCK_EX)
                && !theme_lifecycle_reader_is_active();
            if (is_array($apiWriterLocks)) theme_operation_release($apiWriterLocks);
            $readerResumed = theme_lifecycle_reader_is_active();
            theme_lifecycle_reader_stop();
            fclose($upgradePair[0]);
            pcntl_waitpid($upgradePid, $upgradeStatus);
            $check($otherHeld && $upgraded && $readerResumed && $upgradeElapsed >= 0.20 && $upgradeElapsed < 5,
                'forked update API lock path releases its own reader, waits for other readers, and upgrades without deadlock');
        } else {
            $check(false, 'forked update API lock path releases its own reader, waits for other readers, and upgrades without deadlock');
        }

        $name = 'publication-contract';
        $marker = $fixture . '/incomplete-loaded.marker';
        $package = $fixture . '/publication.zip';
        $publicationManifest = ['name' => $name, 'version' => '1.0.0'];
        $zipWith($package, [
            'plugin.json' => json_encode($publicationManifest),
            'plugin.php' => '<?php file_put_contents(' . var_export($marker, true) . ', "loaded");',
        ]);
        $prepared = plugin_prepare_package_stage($package, $name, false);
        $locks = plugin_lifecycle_locks($name);
        $disabled = is_array($locks) && _plugin_mark_disabled_already_locked($name);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = is_array($pair) ? pcntl_fork() : -1;
        if ($pid === 0) {
            fclose($pair[0]);
            $GLOBALS['_theme_operation_held_keys'] = [];
            $GLOBALS['_theme_operation_lock_keys'] = [];
            plugin_reset_runtime_cache();
            fwrite($pair[1], "started\n");
            plugin_load_active();
            fwrite($pair[1], file_exists($marker) ? "loaded\n" : "safe\n");
            fclose($pair[1]);
            exit(0);
        }
        if ($pid > 0) {
            fclose($pair[1]);
            stream_set_timeout($pair[0], 5);
            $started = trim((string)fgets($pair[0])) === 'started';
            usleep(150000);
            $unpublishedSafe = !file_exists(PLUGIN_PATH . '/' . $name) && !file_exists($marker);
            $published = $disabled && ($prepared['success'] ?? false)
                && rename($prepared['stage'], PLUGIN_PATH . '/' . $name);
            usleep(150000);
            $publishedStillSafe = !file_exists($marker);
            theme_operation_release($locks);
            $result = trim((string)fgets($pair[0]));
            fclose($pair[0]);
            pcntl_waitpid($pid, $status);
            $check($started && $unpublishedSafe && $published && $publishedStillSafe && $result === 'safe',
                'forked discovery cannot load a staged or newly published incomplete plugin while lifecycle publication is paused');
        } else {
            if (is_array($locks)) theme_operation_release($locks);
            $check(false, 'forked discovery cannot load a staged or newly published incomplete plugin while lifecycle publication is paused');
        }
    } else {
        echo "SKIP multi-process publication contract requires pcntl\n";
    }

    $residualName = 'residual-install-contract';
    $residualPackage = $fixture . '/residual-install.zip';
    $zipWith($residualPackage, [
        'plugin.json' => json_encode(['name' => $residualName, 'version' => '1.0.0']),
        'plugin.php' => '<?php',
    ]);
    $residualPrepared = plugin_prepare_package_stage($residualPackage, $residualName, false);
    $residualStage = (string)($residualPrepared['stage'] ?? '');
    $pluginRecovery = package_unique_publication_recovery_path(PLUGIN_PATH . '/' . $residualName, 'old');
    mkdir($pluginRecovery, 0755);
    file_put_contents($pluginRecovery . '/preserved.marker', 'recovery');
    $disabledBeforeResidual = (string)file_get_contents(PLUGIN_DISABLED_JSON);
    $residualLocks = plugin_lifecycle_locks($residualName);
    $residualResult = is_array($residualLocks)
        ? plugin_publish_staged_install_already_locked($residualPrepared, false)
        : ['success' => false];
    if (is_array($residualLocks)) theme_operation_release($residualLocks);
    $check(!($residualResult['success'] ?? false)
        && str_contains((string)($residualResult['error'] ?? ''), 'Inspect and restore or archive')
        && (string)file_get_contents(PLUGIN_DISABLED_JSON) === $disabledBeforeResidual
        && !file_exists(PLUGIN_PATH . '/' . $residualName)
        && is_file($pluginRecovery . '/preserved.marker')
        && is_dir($residualStage),
        'fresh plugin publication detects residual recovery state under lock before disabled-state or filesystem mutation');
    package_remove_tree($residualStage);
    package_remove_tree($pluginRecovery);

    $failedName = 'failed-install-contract';
    $failedPackage = $fixture . '/failed-install.zip';
    $zipWith($failedPackage, [
        'plugin.json' => json_encode(['name' => $failedName, 'version' => '1.0.0']),
        'install.sh' => "#!/bin/sh\nexit 9\n",
    ]);
    $failedPrepared = plugin_prepare_package_stage($failedPackage, $failedName, false);
    $failedLocks = plugin_lifecycle_locks($failedName);
    $failedResult = is_array($failedLocks) ? plugin_publish_staged_install_already_locked($failedPrepared, false) : ['success' => false];
    if (is_array($failedLocks)) theme_operation_release($failedLocks);
    $check(!($failedResult['success'] ?? false) && in_array($failedName, plugin_disabled_names(), true)
        && is_dir(PLUGIN_PATH . '/' . $failedName), 'install.sh failure leaves the published plugin safely disabled for inspection');

    $activeName = 'activate-contract';
    $activePackage = $fixture . '/activate.zip';
    $zipWith($activePackage, [
        'plugin.json' => json_encode(['name' => $activeName, 'version' => '1.0.0']),
        'plugin.php' => '<?php $GLOBALS["activated_contract_loaded"] = true;',
    ]);
    $activePrepared = plugin_prepare_package_stage($activePackage, $activeName, true);
    $activeLocks = plugin_lifecycle_locks($activeName);
    $activeResult = is_array($activeLocks) ? plugin_publish_staged_install_already_locked($activePrepared, true) : ['success' => false];
    if (is_array($activeLocks)) theme_operation_release($activeLocks);
    $check(($activeResult['success'] ?? false) && !in_array($activeName, plugin_disabled_names(), true)
        && plugin_manifest($activeName) !== null, 'requested activation removes disabled state only after verified installation success');

    $controller = (string)file_get_contents($root . '/app/controllers/PluginStoreController.php');
    $check(str_contains($controller, "hash_equals((string)(\$update['current_version'] ?? ''), \$physical['version'])")
        && str_contains($controller, "hash_equals((string)(\$update['current_identity'] ?? ''), \$physical['identity'])")
        && str_contains($controller, "hash_equals((string)(\$update['current_reconciliation'] ?? ''), \$reconciliation)"),
        'plugin apply binds cached candidates to exact version, physical manifest identity, and reconciliation generation');

    $configSource = (string)file_get_contents($root . '/cfg/config.php');
    $themeHelperLoad = strpos($configSource, "require_once __DIR__ . '/helpers/theme_helper.php';");
    $requestReaderStart = strpos($configSource, 'theme_lifecycle_reader_start();');
    $laterHelperLoad = strpos($configSource, "require_once __DIR__ . '/helpers/theme_sections.php';");
    $check($themeHelperLoad !== false && $requestReaderStart !== false && $laterHelperLoad !== false
        && $themeHelperLoad < $requestReaderStart && $requestReaderStart < $laterHelperLoad,
        'normal public and dashboard bootstrap starts the lifecycle reader immediately after theme lock helpers load');

    $forbiddenTokens = ['F' . 'FI', 'rename' . 'at2'];
    $forbiddenReferences = [];
    $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($sources as $source) {
        if (!$source->isFile() || !in_array(strtolower($source->getExtension()), ['php', 'md'], true)
            || str_contains($source->getPathname(), DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) continue;
        $contents = (string)file_get_contents($source->getPathname());
        foreach ($forbiddenTokens as $token) {
            if (str_contains($contents, $token)) $forbiddenReferences[] = $source->getPathname();
        }
    }
    $check($forbiddenReferences === [], 'package lifecycle source has no optional foreign-call or platform syscall references');
} finally {
    $remove($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " package lifecycle checks failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
