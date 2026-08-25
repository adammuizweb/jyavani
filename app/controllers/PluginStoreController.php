<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/cfg/helpers/package_archive.php';
require_once dirname(__DIR__, 2) . '/cfg/helpers/update_metadata_http.php';

class PluginStoreController
{
    private const UPDATE_TTL = 3600;

    public static function reconcileInstalledState(string $name): bool
    {
        if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) return false;
        $physical = self::physicalPluginState($name);
        self::mutateTransient(static function (array $transient) use ($name, $physical): array {
            $transient['reconciliations'][$name] = bin2hex(random_bytes(16));
            if ($physical === null) {
                unset($transient['installed_versions'][$name]);
            } else {
                $transient['installed_versions'][$name] = $physical['version'];
            }
            unset($transient['updates'][$name]);
            return $transient;
        });
        return true;
    }

    public static function checkUpdates(PDO $pdo): array
    {
        return self::checkUpdatesDetailed($pdo)['updates'];
    }

    public static function checkUpdatesDetailed(PDO $pdo, ?float $deadline = null): array
    {
        $scanLocks = [];
        try {
            if (!function_exists('theme_operation_acquire')) require_once dirname(__DIR__, 2) . '/cfg/helpers/theme_helper.php';
            $scanLocks = self::discoveryLocks(theme_lifecycle_lock_keys(), $deadline);
            $transient = self::readTransient();
            $baselineGeneration = (string)($transient['generation'] ?? '');
            $baselineUpdates = is_array($transient['updates'] ?? null) ? $transient['updates'] : [];
            $baselineInstalledVersions = is_array($transient['installed_versions'] ?? null) ? $transient['installed_versions'] : [];
            $baselineReconciliations = is_array($transient['reconciliations'] ?? null) ? $transient['reconciliations'] : [];
            $plugins = function_exists('plugins_all') ? plugins_all() : [];
            $baselinePhysical = [];
            foreach (array_keys($plugins) as $name) $baselinePhysical[$name] = self::physicalPluginState((string)$name);
        } finally {
            if ($scanLocks !== []) theme_operation_release($scanLocks);
        }
        $updates = $baselineUpdates;
        $eligible = [];
        $observed = [];
        $fetched = 0;
        $failed = [];
        $now = time();
        $remainingEligible = 0;
        foreach ($plugins as $manifest) {
            if (($manifest['store'] ?? null)
                || str_starts_with((string)($manifest['plugin_uri'] ?? ''), 'https://jyavani.com/plugin/')) {
                $remainingEligible++;
            }
        }

        foreach ($plugins as $name => $manifest) {
            $store = $manifest['store'] ?? null;
            // Store packages published before manifest metadata existed can still
            // discover their first update from the official Jyavani store.
            if (!$store && str_starts_with((string)($manifest['plugin_uri'] ?? ''), 'https://jyavani.com/plugin/')) {
                $store = ['url' => 'https://jyavani.com/plugin-store'];
            }
            if (!$store) continue;
            $eligible[$name] = true;
            $storeUrl = rtrim($store['url'] ?? 'https://jyavani.com/plugin-store/', '/');
            $currentVersion = $manifest['version'] ?? '0.0.0';

            $itemDeadline = self::itemDeadline($deadline, $remainingEligible);
            $remainingEligible--;
            $latest = self::fetchVersionInfo($storeUrl . '/' . $name . '/version.json', $itemDeadline);
            if ($latest === null || !is_string($latest['version'] ?? null) || trim($latest['version']) === '') {
                $failed[] = $name;
                $observed[$name] = false;
                if (isset($updates[$name]) && is_array($updates[$name])) $updates[$name]['actionable'] = false;
                continue;
            }
            $fetched++;
            if (version_compare($latest['version'] ?? '0.0.0', $currentVersion, '>')) {
                $latestRequirements = is_array($latest['requires'] ?? null) ? $latest['requires'] : [];
                if (is_string($latest['jyavani_required'] ?? null) && $latest['jyavani_required'] !== '') {
                    $latestRequirements['jyavani'] = $latest['jyavani_required'];
                }
                if (is_string($latest['php_required'] ?? null) && $latest['php_required'] !== '') {
                    $latestRequirements['php'] = $latest['php_required'];
                }
                $requirementManifest = ['requires' => $latestRequirements];
                $strictPluginDependencies = function_exists('plugin_is_active') ? plugin_is_active($name) : true;
                if ($strictPluginDependencies && function_exists('plugin_requirement_errors')) {
                    $compatibilityErrors = plugin_requirement_errors($requirementManifest);
                } elseif (function_exists('plugin_requirement_errors_without_plugin_state')) {
                    $compatibilityErrors = plugin_requirement_errors_without_plugin_state($requirementManifest);
                } else {
                    $compatibilityErrors = [];
                }
                if (function_exists('plugin_replacement_dependency_errors')) {
                    $compatibilityErrors = array_merge(
                        $compatibilityErrors,
                        plugin_replacement_dependency_errors($name, (string)($latest['version'] ?? ''))
                    );
                }
                $observed[$name] = $updates[$name] = [
                    'current_version' => $currentVersion,
                    'current_identity' => (string)($baselinePhysical[$name]['identity'] ?? ''),
                    'current_reconciliation' => (string)($baselineReconciliations[$name] ?? ''),
                    'new_version' => $latest['version'],
                    'download_url' => $latest['download_url'] ?? ($storeUrl . '/download/' . $name . '/'),
                    'changelog' => $latest['changelog'] ?? '',
                    'zip_size' => $latest['zip_size'] ?? 0,
                    'php_required' => $latest['php_required'] ?? '',
                    'jyavani_required' => $latestRequirements['jyavani'] ?? '',
                    'requires' => $latestRequirements,
                    'compatible' => $compatibilityErrors === [],
                    'compatibility_errors' => $compatibilityErrors,
                    'checksum' => $latest['checksum'] ?? '',
                    'checked_at' => $now,
                    'actionable' => true,
                ];
            } else {
                $observed[$name] = null;
                unset($updates[$name]);
            }
        }

        $updates = array_intersect_key($updates, $eligible);
        $state = $failed === [] ? 'ok' : ($fetched > 0 ? 'partial' : 'error');
        if ($eligible === []) $state = 'ok';
        $commitLocks = self::discoveryLocks(theme_lifecycle_lock_keys(), $deadline);
        try {
            $currentPhysical = [];
            foreach (array_keys($plugins) as $name) $currentPhysical[$name] = self::physicalPluginState((string)$name);
            $committed = self::mutateTransient(static function (array $current) use (
                $baselineGeneration, $baselineUpdates, $baselineInstalledVersions, $baselineReconciliations,
                $baselinePhysical, $currentPhysical, $eligible, $observed, $state, $failed, $now
            ): array {
            $currentUpdates = is_array($current['updates'] ?? null) ? $current['updates'] : [];
            $installedVersions = is_array($current['installed_versions'] ?? null) ? $current['installed_versions'] : [];
            $reconciliations = is_array($current['reconciliations'] ?? null) ? $current['reconciliations'] : [];
            $generationChanged = (string)($current['generation'] ?? '') !== $baselineGeneration;
            foreach ($eligible as $name => $_) {
                if (!array_key_exists($name, $observed)) continue;
                $result = $observed[$name];
                $baseline = $baselineUpdates[$name] ?? null;
                $present = $currentUpdates[$name] ?? null;
                if (($currentPhysical[$name]['identity'] ?? null) !== ($baselinePhysical[$name]['identity'] ?? null)
                    || (string)($reconciliations[$name] ?? '') !== (string)($baselineReconciliations[$name] ?? '')) {
                    if (!$generationChanged || $present === $baseline) unset($currentUpdates[$name]);
                    continue;
                }
                if ($result === false) {
                    if (!$generationChanged || $present === $baseline) {
                        if (is_array($present)) $currentUpdates[$name]['actionable'] = false;
                    }
                    continue;
                }
                if ($result === null) {
                    if (!$generationChanged || $present === $baseline) unset($currentUpdates[$name]);
                    continue;
                }
                $candidate = $result;
                $installedVersion = is_string($installedVersions[$name] ?? null) ? $installedVersions[$name] : '';
                $installedDuringCheck = $generationChanged
                    && $installedVersion !== (is_string($baselineInstalledVersions[$name] ?? null) ? $baselineInstalledVersions[$name] : '');
                if ($installedDuringCheck && $installedVersion !== '') {
                    if (version_compare((string)$candidate['new_version'], $installedVersion, '>')) {
                        $candidate['current_version'] = $installedVersion;
                    } else {
                        if (!is_array($present)
                            || !version_compare((string)($present['new_version'] ?? ''), $installedVersion, '>')) {
                            unset($currentUpdates[$name]);
                        }
                        continue;
                    }
                }
                if (is_array($present)) {
                    $comparison = version_compare((string)($present['new_version'] ?? ''), (string)$candidate['new_version']);
                    if ($comparison > 0 || ($comparison === 0 && $generationChanged && $present !== $baseline)) continue;
                }
                $currentUpdates[$name] = $candidate;
            }
            $current['last_attempt'] = $now;
            if ($state !== 'error') $current['last_check'] = $now;
            $current['state'] = $state;
            $current['errors'] = $failed;
            $current['updates'] = $generationChanged ? $currentUpdates : array_intersect_key($currentUpdates, $eligible);
            return $current;
            });
        } finally {
            theme_operation_release($commitLocks);
        }

        return ['state' => $state, 'updates' => $committed['updates'] ?? [], 'errors' => $failed];
    }

    public static function getCachedUpdates(): array
    {
        $transient = self::readTransient();
        $updates = is_array($transient['updates'] ?? null) ? $transient['updates'] : [];
        $freshAfter = time() - self::UPDATE_TTL;
        return array_filter($updates, static fn(mixed $update): bool => is_array($update)
            && ($update['actionable'] ?? false) === true
            && (int)($update['checked_at'] ?? 0) >= $freshAfter);
    }

    public static function applyUpdate(PDO $pdo, string $name, string $progressToken = ''): array
    {
        if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) {
            return ['success' => false, 'error' => 'Invalid plugin name.'];
        }
        $operationLocks = function_exists('plugin_lifecycle_locks') ? plugin_lifecycle_locks($name) : null;
        if ($operationLocks === null) return ['success' => false, 'error' => 'Unable to lock plugin lifecycle operation.'];
        try {
            return self::applyUpdateAlreadyLocked($pdo, $name, $progressToken);
        } finally {
            if (function_exists('theme_operation_release')) theme_operation_release($operationLocks);
        }
    }

    private static function applyUpdateAlreadyLocked(PDO $pdo, string $name, string $progressToken): array
    {
        require_once __DIR__ . '/UpdateStatusController.php';
        if (!UpdateStatusController::isUpdateActionable('plugins', $name)) {
            $error = 'No update available for "' . $name . '". Run "Check for Updates" first.';
            if ($progressToken !== '') self::writeProgress($progressToken, 0, __('No updates available.'), true, $error);
            return ['success' => false, 'error' => $error];
        }
        $updates = self::getCachedUpdates();
        if (!isset($updates[$name])) {
            if ($progressToken !== '') {
                self::writeProgress($progressToken, 0, __('No updates available.'), true, __('No updates available. Run "Check for Updates" first.'));
            }
            return ['success' => false, 'error' => 'No update available for "' . $name . '". Run "Check for Updates" first.'];
        }

        $update = $updates[$name];
        $physical = self::physicalPluginState($name);
        $reconciliation = (string)(self::readTransient()['reconciliations'][$name] ?? '');
        if ($physical === null
            || !hash_equals((string)($update['current_version'] ?? ''), $physical['version'])
            || !hash_equals((string)($update['current_identity'] ?? ''), $physical['identity'])
            || !hash_equals((string)($update['current_reconciliation'] ?? ''), $reconciliation)) {
            $error = 'Installed plugin identity changed. Run "Check for Updates" again.';
            if ($progressToken !== '') self::writeProgress($progressToken, 0, $error, true, $error);
            return ['success' => false, 'error' => $error];
        }
        $strictPluginDependencies = function_exists('plugin_is_active') ? plugin_is_active($name) : true;
        $requirementManifest = ['requires' => is_array($update['requires'] ?? null) ? $update['requires'] : []];
        $currentCompatibilityErrors = $strictPluginDependencies && function_exists('plugin_requirement_errors')
            ? plugin_requirement_errors($requirementManifest)
            : (function_exists('plugin_requirement_errors_without_plugin_state')
                ? plugin_requirement_errors_without_plugin_state($requirementManifest)
                : []);
        if (function_exists('plugin_replacement_dependency_errors')) {
            $currentCompatibilityErrors = array_merge(
                $currentCompatibilityErrors,
                plugin_replacement_dependency_errors($name, (string)($update['new_version'] ?? ''))
            );
        }
        if ($currentCompatibilityErrors !== []) {
            $error = 'Plugin update requirements are not met: ' . implode('; ', $currentCompatibilityErrors) . '.';
            if ($progressToken !== '') self::writeProgress($progressToken, 0, $error, true, $error);
            return ['success' => false, 'error' => $error];
        }
        $pluginDir = (defined('PLUGIN_PATH') ? PLUGIN_PATH : __DIR__ . '/../../plugins') . '/' . $name;
        $pluginRoot = realpath(defined('PLUGIN_PATH') ? PLUGIN_PATH : __DIR__ . '/../../plugins');
        $pluginReal = realpath($pluginDir);
        if ($pluginRoot === false || $pluginReal === false || is_link($pluginDir)
            || !str_starts_with($pluginReal, $pluginRoot . DIRECTORY_SEPARATOR)) {
            if ($progressToken !== '') {
                self::writeProgress($progressToken, 0, 'Direktori plugin tidak ditemukan.', true, 'Direktori plugin tidak ditemukan.');
            }
            return ['success' => false, 'error' => 'Plugin directory not found.'];
        }
        $oldManifest = $physical['manifest'];
        $oldStaticValue = $oldManifest['static']['copy'] ?? [];
        if (!is_array($oldStaticValue)) {
            return ['success' => false, 'error' => 'Installed plugin static.copy is invalid; update cannot safely track old destinations.'];
        }
        $oldStaticCopy = $oldStaticValue;

        $p = function ($pct, $status) use ($progressToken) {
            if ($progressToken !== '') self::writeProgress($progressToken, $pct, $status);
        };

        $p(3, 'Memulai update...');

        $oldIdentity = package_tree_identity($pluginDir);
        if ($oldIdentity === null) return ['success' => false, 'error' => 'Installed plugin tree contains unsupported entries.'];

        $tmpZip = self::downloadPackage((string)$update['download_url'], $p);
        if ($tmpZip === null) {
            self::writeProgress($progressToken, 0, 'Gagal mengunduh update.', true, 'Gagal mengunduh update dari store.');
            return ['success' => false, 'error' => 'Gagal mengunduh update dari store.'];
        }
        if (empty($update['checksum']) || !hash_equals(strtolower((string)$update['checksum']), hash_file('sha256', $tmpZip))) {
            @unlink($tmpZip);
            self::writeProgress($progressToken, 0, 'Paket update tidak valid.', true, 'Plugin package integrity verification failed.');
            return ['success' => false, 'error' => 'Plugin package integrity verification failed.'];
        }

        $p(35, 'Unduhan selesai. Memverifikasi paket...');

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            unlink($tmpZip);
            self::writeProgress($progressToken, 0, 'Paket update tidak valid.', true, 'Paket update tidak valid.');
            return ['success' => false, 'error' => 'Paket update tidak valid.'];
        }

        $validation = package_archive_validate($zip);
        if (!$validation['success']) {
            $zip->close(); @unlink($tmpZip);
            return ['success' => false, 'error' => $validation['error']];
        }
        $rootManifest = $zip->locateName('plugin.json', ZipArchive::FL_NOCASE);
        $nestedManifest = $zip->locateName($name . '/plugin.json', ZipArchive::FL_NOCASE);
        if (($rootManifest === false) === ($nestedManifest === false)) {
            $zip->close(); @unlink($tmpZip);
            return ['success' => false, 'error' => 'Update package must contain exactly one plugin.json at the package root or requested plugin folder.'];
        }
        $prefix = $rootManifest !== false ? '' : $name . '/';
        $manifestEntry = $prefix . 'plugin.json';
        $packageManifestRaw = $zip->getFromName($manifestEntry);
        $packageManifest = $packageManifestRaw !== false ? json_decode($packageManifestRaw, true) : null;
        if (!is_array($packageManifest) || ($packageManifest['name'] ?? '') !== $name
            || !is_string($packageManifest['version'] ?? null)
            || version_compare($packageManifest['version'], (string)$update['new_version'], '!=')) {
            $zip->close(); @unlink($tmpZip);
            return ['success' => false, 'error' => 'Update package plugin.json is invalid or does not match the advertised plugin version.'];
        }
        $packageRequirementError = function_exists('plugin_install_requirements_error_message')
            ? plugin_install_requirements_error_message($packageManifest, $strictPluginDependencies)
            : '';
        if ($packageRequirementError !== '') {
            $zip->close(); @unlink($tmpZip);
            return ['success' => false, 'error' => $packageRequirementError];
        }
        if (function_exists('plugin_package_requirement_errors')) {
            $advertisedManifest = ['requires' => is_array($update['requires'] ?? null) ? $update['requires'] : []];
            $metadataErrors = plugin_package_requirement_errors($advertisedManifest, $packageManifest);
            if ($metadataErrors !== []) {
                $zip->close(); @unlink($tmpZip);
                return ['success' => false, 'error' => 'Update package requirements do not match store metadata: ' . implode('; ', $metadataErrors) . '.'];
            }
        }
        if (function_exists('plugin_replacement_dependency_errors')) {
            $replacementErrors = plugin_replacement_dependency_errors($name, (string)$packageManifest['version']);
            if ($replacementErrors !== []) {
                $zip->close(); @unlink($tmpZip);
                return ['success' => false, 'error' => implode('; ', $replacementErrors)];
            }
        }

        $filesToExtract = [];
        $logical = [];
        foreach ($validation['entries'] as $entry) {
            if ($entry['directory']) continue;
            if ($prefix !== '' && !str_starts_with($entry['source'], $prefix)) {
                $zip->close(); @unlink($tmpZip);
                return ['success' => false, 'error' => 'Update package contains files outside the requested plugin folder.'];
            }
            $relative = $prefix === '' ? $entry['path'] : substr($entry['path'], strlen($prefix));
            $key = strtolower($relative);
            if (!package_safe_relative_path($relative) || $key === '.store.json' || $key === '.git'
                || str_starts_with($key, '.git/') || isset($logical[$key])) {
                $zip->close(); @unlink($tmpZip);
                return ['success' => false, 'error' => 'Update package contains an invalid or duplicate logical target.'];
            }
            $logical[$key] = true;
            $filesToExtract[] = ['source' => $entry['source'], 'relative' => $relative];
        }

        $stage = package_private_directory(dirname($pluginDir), 'plugin-stage-' . $name);
        if ($stage === null) {
            $zip->close(); @unlink($tmpZip);
            return ['success' => false, 'error' => 'Unable to create a private plugin staging directory.'];
        }
        $p(55, 'Memasang file update ke staging...');
        $extracted = package_archive_extract_files($zip, $filesToExtract, $stage);
        $zip->close();
        @unlink($tmpZip);
        if (!$extracted || !self::chmodPluginTree($stage)
            || !package_copy_preserved_paths($pluginDir, $stage, ['.store.json', '.git'])) {
            package_remove_tree($stage);
            return ['success' => false, 'error' => 'Failed to build the complete plugin staging tree.'];
        }

        $p(70, __('Verifying plugin manifest...'));
        $manifestPath = $stage . '/plugin.json';
        $manifest = is_file($manifestPath) && !is_link($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
        if (!is_array($manifest) || ($manifest['name'] ?? '') !== $name
            || ($manifest['version'] ?? '') !== $update['new_version']
            || plugin_install_requirements_error_message($manifest, $strictPluginDependencies) !== '') {
            package_remove_tree($stage);
            return ['success' => false, 'error' => 'Staged plugin manifest failed verification.'];
        }
        $staticCopy = $manifest['static']['copy'] ?? [];
        if (!is_array($staticCopy)) {
            package_remove_tree($stage);
            return ['success' => false, 'error' => 'Plugin static.copy is invalid.'];
        }
        $newIdentity = package_tree_identity($stage);
        if ($newIdentity === null) {
            package_remove_tree($stage);
            return ['success' => false, 'error' => 'Staged plugin identity verification failed.'];
        }
        $publication = package_guarded_publish($stage, $pluginDir, $oldIdentity, $newIdentity);
        if (!$publication['success']) {
            if (is_dir($stage)) package_remove_tree($stage);
            $recovery = $publication['recovery_paths'][0] ?? null;
            return ['success' => false, 'error' => $publication['restored']
                ? 'Plugin publication failed; exact old tree restored.'
                : 'Plugin publication failed closed. Recovery path: ' . ($recovery ?? 'unavailable')];
        }
        $rollbackPath = $publication['rollback_path'];

        $rollback = static function () use (&$rollbackPath, $pluginDir, $oldIdentity, $name): array {
            $restoration = is_string($rollbackPath)
                ? package_guarded_rollback($pluginDir, $rollbackPath, $oldIdentity)
                : ['restored' => false, 'recovery_paths' => []];
            $restored = ($restoration['restored'] ?? false) === true;
            if ($restored) $rollbackPath = null;
            try { self::reconcileInstalledState($name); }
            catch (Throwable $reconciliationError) { error_log('[plugin-reconciliation] ' . $reconciliationError->getMessage()); }
            return ['restored' => $restored, 'recovery' => $restoration['recovery_paths'][0] ?? $rollbackPath];
        };

        try {
        if ($staticCopy !== [] || $oldStaticCopy !== []) {
            $p(90, __('Copying static files...'));
            $copyResult = plugin_static_copy($pluginDir, $staticCopy, $oldStaticCopy);
            if ($copyResult['failed'] > 0) {
                $restoration = $rollback();
                $staticRollbackFailed = ($copyResult['rollback_incomplete'] ?? false) === true;
                if ($staticRollbackFailed) {
                    return ['success' => false, 'error' => 'Declared static files failed and static asset rollback was incomplete. Manual recovery is required.'];
                }
                return ['success' => false, 'error' => $restoration['restored']
                    ? 'Declared static files failed to copy; exact old plugin tree restored.'
                    : 'Declared static files failed and exact rollback could not be verified. Recovery artifact: ' . ($restoration['recovery'] ?? 'unavailable')];
            }
        }

        $p(93, __('Running plugin installer...'));
        $installResult = plugin_run_install_script($pluginDir);
        if (!$installResult['success']) {
            $installError = $installResult['error'];
            $restoration = $rollback();
            $staticRestored = true;
            if ($restoration['restored'] && ($staticCopy !== [] || $oldStaticCopy !== [])) {
                $staticRestoreResult = plugin_static_copy($pluginDir, $oldStaticCopy, $staticCopy);
                $staticRestored = $staticRestoreResult['failed'] === 0;
            }
            $suffix = $restoration['restored'] && $staticRestored
                ? ' ' . __('Managed plugin files were restored, but install.sh changes outside the plugin directory may remain.')
                : ' ' . __('Managed plugin file restoration was incomplete, and install.sh changes outside the plugin directory may remain. Manual recovery is required.')
                    . ' Recovery artifact: ' . ($restoration['recovery'] ?? 'unavailable');
            return ['success' => false, 'error' => $installError . $suffix];
        }

        $installed = self::physicalPluginState($name);
        if ($installed === null || !hash_equals((string)$update['new_version'], $installed['version'])
            || package_tree_identity($pluginDir) === null) {
            $restoration = $rollback();
            return ['success' => false, 'error' => $restoration['restored']
                ? 'Post-install verification failed; exact old plugin tree restored.'
                : 'Post-install verification failed and exact rollback was incomplete. Recovery artifact: ' . ($restoration['recovery'] ?? 'unavailable')];
        }
        self::reconcileInstalledState($name);
        $cleanupWarning = is_string($rollbackPath) && package_guarded_finalize($pluginDir, $rollbackPath, $oldIdentity)
            ? ''
            : 'Exact old rollback-tree cleanup requires manual attention.';
        if ($cleanupWarning === '') $rollbackPath = null;
        } catch (Throwable $error) {
            $restoration = $rollback();
            return ['success' => false, 'error' => $restoration['restored']
                ? 'Plugin update failed unexpectedly; exact old plugin tree restored. ' . $error->getMessage()
                : 'Plugin update failed unexpectedly and exact rollback was incomplete. Recovery artifact: ' . ($restoration['recovery'] ?? 'unavailable')];
        }

        $p(95, 'Menyelesaikan...');
        $metadataWarning = '';
        try {
            self::removeCachedUpdate($name, (string)$update['new_version']);
        } catch (Throwable $metadataError) {
            error_log('[plugin-update] Unable to finalize reconciliation metadata: ' . $metadataError->getMessage());
            $metadataWarning = ' Update metadata cleanup requires another Check for Updates.';
        }
        if (function_exists('plugin_reset_runtime_cache')) plugin_reset_runtime_cache();

        self::writeProgress($progressToken, 100, 'Selesai!', true);

        $result = ['success' => true, 'new_version' => $update['new_version']];
        if ($cleanupWarning !== '' || $metadataWarning !== '') $result['warning'] = trim($cleanupWarning . $metadataWarning);
        return $result;
    }

    public static function readProgress(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $file = self::progressFile($token);
        if (!is_file($file)) return null;
        if (time() - filemtime($file) > 3600) {
            @unlink($file);
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private static function transientFile(): string
    {
        $backend = defined('BACKEND_PATH') ? BACKEND_PATH : __DIR__ . '/../../cfg';
        return $backend . '/var/plugin-update-transient.json';
    }

    private static function readTransient(): array
    {
        $file = self::transientFile();
        if (!is_file($file)) return ['last_check' => 0, 'generation' => '', 'updates' => [], 'installed_versions' => []];
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : ['last_check' => 0, 'generation' => '', 'updates' => [], 'installed_versions' => []];
    }

    private static function writeTransient(array $data): void
    {
        self::mutateTransient(static fn(array $current): array => $data);
    }

    private static function mutateTransient(callable $mutation): array
    {
        $file = self::transientFile();
        $dir = dirname($file);
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir))) {
            throw new RuntimeException('Unable to create the plugin update transient directory.');
        }
        $lock = fopen($file . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Unable to acquire the plugin update transient lock.');
        }
        try {
            $current = self::readTransient();
            $next = $mutation($current);
            if (!is_array($next)) throw new RuntimeException('Invalid plugin update transient mutation.');
            $next['generation'] = bin2hex(random_bytes(16));
            $temporary = $dir . '/.plugin-update-transient-' . bin2hex(random_bytes(12)) . '.tmp';
            $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            $handle = fopen($temporary, 'x+b');
            if ($handle === false) throw new RuntimeException('Unable to create the plugin update transient replacement.');
            $written = fwrite($handle, $json);
            $flushed = $written === strlen($json) && fflush($handle);
            $synced = $flushed && (!function_exists('fsync') || fsync($handle));
            fclose($handle);
            if (!$synced || !chmod($temporary, 0640) || !rename($temporary, $file)) {
                @unlink($temporary);
                throw new RuntimeException('Unable to persist the plugin update transient.');
            }
            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function removeCachedUpdate(string $name, string $installedVersion): void
    {
        self::mutateTransient(static function (array $transient) use ($name, $installedVersion): array {
            $recorded = is_string($transient['installed_versions'][$name] ?? null) ? $transient['installed_versions'][$name] : '';
            if ($recorded === '' || version_compare($installedVersion, $recorded, '>')) {
                $transient['installed_versions'][$name] = $installedVersion;
            }
            $update = $transient['updates'][$name] ?? null;
            if (is_array($update) && version_compare((string)($update['new_version'] ?? ''), $installedVersion, '>')) {
                $transient['updates'][$name]['current_version'] = $installedVersion;
            } else {
                unset($transient['updates'][$name]);
            }
            return $transient;
        });
    }

    private static function physicalPluginState(string $name): ?array
    {
        if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) return null;
        $root = realpath(defined('PLUGIN_PATH') ? PLUGIN_PATH : __DIR__ . '/../../plugins');
        $candidate = (defined('PLUGIN_PATH') ? PLUGIN_PATH : __DIR__ . '/../../plugins') . '/' . $name;
        $directory = realpath($candidate);
        $manifestPath = $directory !== false ? $directory . '/plugin.json' : '';
        $size = $manifestPath !== '' ? @filesize($manifestPath) : false;
        if ($root === false || $directory === false || is_link($candidate)
            || !str_starts_with($directory, $root . DIRECTORY_SEPARATOR) || !is_int($size) || $size < 2 || $size > 1024 * 1024
            || !is_file($manifestPath) || is_link($manifestPath)) return null;
        $raw = @file_get_contents($manifestPath);
        if (!is_string($raw)) return null;
        $manifest = json_decode($raw, true);
        $version = is_array($manifest) && is_string($manifest['version'] ?? null) ? trim($manifest['version']) : '';
        if (!is_array($manifest) || ($manifest['name'] ?? null) !== $name || $version === ''
            || (function_exists('plugin_manifest_contract_errors') && plugin_manifest_contract_errors($manifest) !== [])) return null;
        return ['directory' => $directory, 'manifest' => $manifest, 'version' => $version, 'identity' => hash('sha256', $raw)];
    }

    private static function chmodPluginTree(string $directory): bool
    {
        if (!is_dir($directory) || is_link($directory)) return false;
        $ok = @chmod($directory, 0775);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) return false;
            $mode = $entry->isDir() ? 0775 : (strtolower((string)pathinfo($entry->getFilename(), PATHINFO_EXTENSION)) === 'sh' ? 0775 : 0664);
            if (!@chmod($entry->getPathname(), $mode)) $ok = false;
        }
        return $ok;
    }

    public static function clearProgress(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return;
        $file = self::progressFile($token);
        if (is_file($file)) @unlink($file);
    }

    private static function progressFile(string $token): string
    {
        $backend = defined('BACKEND_PATH') ? BACKEND_PATH : __DIR__ . '/../../cfg';
        return $backend . '/var/update-progress-' . $token . '.json';
    }

    private static function writeProgress(string $token, int $percentage, string $status, bool $done = false, ?string $error = null): void
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) return;
        $file = self::progressFile($token);
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($file, json_encode([
            'percentage' => $percentage,
            'status' => $status,
            'done' => $done,
            'error' => $error,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private static function downloadPackage(string $url, callable $progress): ?string
    {
        $progress(18, 'Mengunduh paket update...');
        $tmp = package_download($url, 'plugin-update-', 'JyavaniCMS-PluginUpdate', static function (int $downloaded, int $total) use ($progress): void {
            if ($total > 0) $progress(18 + (int)floor(17 * min(1, $downloaded / $total)), 'Mengunduh paket update...');
        });
        if ($tmp === null) return null;
        $progress(35, 'Unduhan selesai. Memverifikasi paket...');
        return $tmp;
    }

    private static function downloadPackageWithStream(string $url, string $tmp, callable $progress): bool
    {
        $ctx = stream_context_create(['http' => [
            'timeout' => 120,
            'user_agent' => 'JyavaniCMS-PluginUpdate',
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
        ]]);
        $input = @fopen($url, 'rb', false, $ctx);
        if ($input === false) return false;

        $headers = (array)(stream_get_meta_data($input)['wrapper_data'] ?? []);
        $status = 0;
        $length = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string)$header, $match)) $status = (int)$match[1];
            if (preg_match('/^Content-Length:\s*(\d+)/i', (string)$header, $match)) $length = (int)$match[1];
        }
        if ($status < 200 || $status >= 300 || $length > PACKAGE_MAX_BYTES) { fclose($input); return false; }

        $output = @fopen($tmp, 'wb');
        if ($output === false) { fclose($input); return false; }
        $downloaded = 0;
        $ok = true;
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) { $ok = false; break; }
            if ($chunk === '') continue;
            $downloaded += strlen($chunk);
            if ($downloaded > PACKAGE_MAX_BYTES || fwrite($output, $chunk) !== strlen($chunk)) { $ok = false; break; }
            if ($length > 0) $progress(18 + (int)floor(17 * min(1, $downloaded / $length)), 'Mengunduh paket update...');
        }
        fclose($input);
        return fclose($output) && $ok && $downloaded > 0;
    }

    private static function fetchVersionInfo(string $url, ?float $deadline = null): ?array
    {
        return update_metadata_fetch_json($url, 'JyavaniCMS-PluginUpdate', $deadline);
    }

    private static function lockDeadline(?float $deadline): ?float
    {
        return $deadline === null ? null : min($deadline, microtime(true) + 0.5);
    }

    private static function discoveryLocks(array $keys, ?float $deadline): array
    {
        if (function_exists('theme_lifecycle_reader_is_active') && theme_lifecycle_reader_is_active()) return [];
        return theme_operation_acquire($keys, LOCK_SH, self::lockDeadline($deadline));
    }

    private static function itemDeadline(?float $deadline, int $remainingItems): ?float
    {
        if ($deadline === null || $remainingItems < 1) return $deadline;
        $now = microtime(true);
        return min($deadline, $now + max(0.0, $deadline - $now) / $remainingItems);
    }

    // ─── Store API endpoints (served by jyavani.com) ───

    public static function list(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $plugins = [];
        try {
            $stmt = $pdo->query(
                "SELECT p.name, p.title, p.description, p.excerpt, p.icon, p.banner,
                        p.php_required, p.homepage, p.github_url, p.total_downloads,
                        m.username, m.display_name,
                        pv.version, pv.changelog, pv.zip_file, pv.zip_size
                 FROM plugins p
                 LEFT JOIN members m ON p.member_id = m.id
                 LEFT JOIN plugin_versions pv ON pv.plugin_id = p.id AND pv.is_current = 1
                 WHERE p.status = 'approved' AND p.is_deleted = 0
                 ORDER BY p.total_downloads DESC, p.title ASC"
            );
            $rows = $stmt ? $stmt->fetchAll() : [];
            foreach ($rows as $row) {
                $name = $row['name'];
                $packageRequirements = self::packageRequirements((string)($row['zip_file'] ?? ''), $name);
                $plugins[] = [
                    'name' => $name,
                    'title' => $row['title'] ?: $name,
                    'version' => $row['version'] ?: '1.0.0',
                    'php_required' => $row['php_required'] ?: '8.1',
                    'description' => $row['description'] ?: $row['excerpt'] ?: '',
                    'author' => $row['display_name'] ?: $row['username'] ?: 'Jyavani',
                    'icon' => self::storeStaticUrl($name, (string)$row['icon']),
                    'banner' => self::storeStaticUrl($name, (string)$row['banner']),
                    'plugin_uri' => $row['homepage'] ?: '',
                    'download_url' => self::storeDownloadUrl($name),
                    'jyavani_required' => $packageRequirements['jyavani'] ?? '',
                    'requires' => $packageRequirements,
                ];
            }
        } catch (Throwable $e) {
            error_log('PluginStore list error: ' . $e->getMessage());
            // Return empty list on DB error rather than 500
        }
        echo json_encode([
            'store_name' => 'Jyavani Plugin Store',
            'plugins' => $plugins,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function download(PDO $pdo, string $name): void
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($name === '') {
            http_response_code(400);
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT p.id, pv.zip_file, pv.version
                 FROM plugins p
                 LEFT JOIN plugin_versions pv ON pv.plugin_id = p.id AND pv.is_current = 1
                 WHERE p.name = ? AND p.status = 'approved' AND p.is_deleted = 0
                 LIMIT 1"
            );
            $stmt->execute([$name]);
            $row = $stmt->fetch();
            if (!$row || empty($row['zip_file'])) {
                http_response_code(404);
                exit;
            }

            $file = __DIR__ . '/../../' . $row['zip_file'];
            if (!is_file($file)) {
                http_response_code(404);
                exit;
            }

            $pdo->prepare("UPDATE plugins SET total_downloads = total_downloads + 1 WHERE id = ?")->execute([$row['id']]);

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '-' . $row['version'] . '.zip"');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: no-store');
            readfile($file);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            exit;
        }
    }

    public static function versionInfo(PDO $pdo, string $name): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($name === '') {
            echo json_encode(['error' => 'Invalid plugin name.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT pv.version, pv.changelog, pv.zip_size, pv.php_required, pv.zip_file
                 FROM plugins p
                 LEFT JOIN plugin_versions pv ON pv.plugin_id = p.id AND pv.is_current = 1
                 WHERE p.name = ? AND p.status = 'approved' AND p.is_deleted = 0
                 LIMIT 1"
            );
            $stmt->execute([$name]);
            $row = $stmt->fetch();
            if (!$row || empty($row['version'])) {
                echo json_encode(['error' => 'Plugin not found.']);
                exit;
            }
            $packageRequirements = self::packageRequirements((string)($row['zip_file'] ?? ''), $name);
            $packagePath = __DIR__ . '/../../' . (string)($row['zip_file'] ?? '');
            $checksum = is_file($packagePath) ? hash_file('sha256', $packagePath) : false;
            echo json_encode([
                'version' => $row['version'],
                'download_url' => self::storeDownloadUrl($name),
                'changelog' => $row['changelog'] ?: '',
                'zip_size' => (int)($row['zip_size'] ?: 0),
                'php_required' => $row['php_required'] ?: '8.1',
                'jyavani_required' => $packageRequirements['jyavani'] ?? '',
                'requires' => $packageRequirements,
                'checksum' => is_string($checksum) ? $checksum : '',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['error' => 'Failed to read version info.']);
            exit;
        }
    }

    public static function serveStatic(PDO $pdo, string $name, string $file): void
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($name === '' || $file === '') {
            http_response_code(400);
            exit;
        }
        // Prevent path traversal
        $file = basename($file);
        $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'];
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            http_response_code(403);
            exit;
        }
        $path = __DIR__ . '/../../plugin-store/plugins/' . $name . '/' . $file;
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }
        $mimeTypes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }

    private static function storeDownloadUrl(string $name): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?: 'jyavani.com';
        return $scheme . '://' . $host . '/plugin-store/download/' . rawurlencode($name) . '/';
    }

    private static function packageRequirements(string $relativeZip, string $name): array
    {
        if ($relativeZip === '' || !class_exists('ZipArchive')) return [];
        $root = realpath(__DIR__ . '/../../');
        $path = $root ? realpath($root . '/' . ltrim($relativeZip, '/')) : false;
        if (!$root || !$path || ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR))) return [];
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return [];
        $raw = $zip->getFromName('plugin.json');
        if ($raw === false) $raw = $zip->getFromName($name . '/plugin.json');
        $zip->close();
        $manifest = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($manifest)) return [];
        if (function_exists('plugin_normalize_requirements')) {
            return plugin_normalize_requirements($manifest)['requires'];
        }
        $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
        if (!isset($requires['jyavani']) && is_string($manifest['jyavani_required'] ?? null)) $requires['jyavani'] = trim($manifest['jyavani_required']);
        if (!isset($requires['php']) && is_string($manifest['php_required'] ?? null)) $requires['php'] = trim($manifest['php_required']);
        return $requires;
    }

    private static function storeStaticUrl(string $name, string $file): string
    {
        if ($file === '') return '';
        $file = basename($file);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?: 'jyavani.com';
        return $scheme . '://' . $host . '/plugin-store/static/' . rawurlencode($name) . '/' . rawurlencode($file);
    }
}
