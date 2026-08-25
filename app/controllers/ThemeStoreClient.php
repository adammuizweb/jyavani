<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/cfg/helpers/package_archive.php';

class ThemeStoreClient
{
    private const STORE_BASE = 'https://jyavani.com/theme-store';
    private const UPDATE_TTL = 3600;
    private const MAX_PACKAGE_BYTES = 50 * 1024 * 1024;
    private const MAX_ARCHIVE_ENTRIES = 5000;
    private const MAX_ENTRY_BYTES = 64 * 1024 * 1024;
    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 256 * 1024 * 1024;
    private const MAX_COMPRESSION_RATIO = 200;
    private const MAX_DECISIONS = 64;
    private const MAX_ISSUES = 64;

    public static function checkUpdates(PDO $pdo): array
    {
        return self::checkUpdatesDetailed($pdo)['updates'];
    }

    public static function checkUpdatesDetailed(PDO $pdo, ?array $themes = null): array
    {
        self::loadThemeHelpers();
        $scanFolders = theme_registration_lock_folders($pdo);
        if (is_array($themes)) $scanFolders = array_merge($scanFolders, array_keys($themes));
        $scanLocks = theme_operation_acquire(theme_lifecycle_lock_keys($scanFolders));
        try {
            if ($themes === null) {
                if (function_exists('register_all_themes_from_fs')) register_all_themes_from_fs($pdo);
                $themes = self::scanInstalledThemes($pdo);
            }
            $transient = self::readTransient();
            $baselineGeneration = (string)($transient['generation'] ?? '');
            $baselineUpdates = is_array($transient['updates'] ?? null) ? $transient['updates'] : [];
            $baselineInstalledVersions = is_array($transient['installed_versions'] ?? null) ? $transient['installed_versions'] : [];
            $baselinePhysical = [];
            foreach (array_keys($themes) as $folder) {
                try {
                    $physical = self::readPhysicalManifest((string)$folder)['manifest'];
                    $baselinePhysical[(string)$folder] = hash('sha256', json_encode($physical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $themes[$folder] = $physical;
                } catch (Throwable $error) {
                    unset($themes[$folder]);
                }
            }
        } finally {
            theme_operation_release($scanLocks);
        }
        $updates = $baselineUpdates;
        $eligible = [];
        $observed = [];
        $fetched = 0;
        $failed = [];
        $now = time();

        foreach ($themes as $folder => $manifest) {
            $store = $manifest['store'] ?? null;
            if (!$store) continue;
            $eligible[$folder] = true;
            $storeUrl = rtrim($store['url'] ?? self::STORE_BASE, '/');
            $storeSlug = $store['slug'] ?? $folder;
            $currentVersion = $manifest['version'] ?? '0.0.0';

            $latest = self::fetchVersionInfo($storeUrl . '/' . $storeSlug . '/version.json');
            if ($latest === null || !is_string($latest['version'] ?? null) || trim($latest['version']) === '') {
                $failed[] = $folder;
                $observed[$folder] = false;
                if (isset($updates[$folder]) && is_array($updates[$folder])) $updates[$folder]['actionable'] = false;
                continue;
            }
            if (!is_string($latest['checksum'] ?? null) || preg_match('/^[a-f0-9]{64}$/i', $latest['checksum']) !== 1) {
                $failed[] = $folder;
                $observed[$folder] = false;
                if (isset($updates[$folder]) && is_array($updates[$folder])) $updates[$folder]['actionable'] = false;
                continue;
            }
            $fetched++;

            if (version_compare($latest['version'] ?? '0.0.0', $currentVersion, '>')) {
                $observed[$folder] = $updates[$folder] = [
                    'current_version' => $currentVersion,
                    'new_version' => $latest['version'],
                    'download_url' => $latest['download_url'] ?? ($storeUrl . '/download/' . $storeSlug . '/'),
                    'changelog' => $latest['changelog'] ?? '',
                    'zip_size' => $latest['zip_size'] ?? 0,
                    'php_required' => $latest['php_required'] ?? '',
                    'checksum' => $latest['checksum'] ?? '',
                    'checked_at' => $now,
                    'actionable' => true,
                ];
            } else {
                $observed[$folder] = null;
                unset($updates[$folder]);
            }
        }

        $updates = array_intersect_key($updates, $eligible);
        $state = $failed === [] ? 'ok' : ($fetched > 0 ? 'partial' : 'error');
        if ($eligible === []) $state = 'ok';
        $commitLocks = theme_operation_acquire(theme_lifecycle_lock_keys(array_keys($themes)));
        try {
            $currentPhysical = [];
            foreach (array_keys($themes) as $folder) {
                try {
                    $physical = self::readPhysicalManifest((string)$folder)['manifest'];
                    $currentPhysical[(string)$folder] = hash('sha256', json_encode($physical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                } catch (Throwable $error) {
                    $currentPhysical[(string)$folder] = null;
                }
            }
            $committed = self::mutateTransient(static function (array $current) use (
                $baselineGeneration, $baselineUpdates, $baselineInstalledVersions, $baselinePhysical, $currentPhysical,
                $eligible, $observed, $state, $failed, $now
            ): array {
            $currentUpdates = is_array($current['updates'] ?? null) ? $current['updates'] : [];
            $installedVersions = is_array($current['installed_versions'] ?? null) ? $current['installed_versions'] : [];
            $generationChanged = (string)($current['generation'] ?? '') !== $baselineGeneration;

            foreach ($eligible as $folder => $_) {
                if (!array_key_exists($folder, $observed)) continue;
                $result = $observed[$folder];
                $baseline = $baselineUpdates[$folder] ?? null;
                $present = $currentUpdates[$folder] ?? null;
                if (($currentPhysical[$folder] ?? null) === null) {
                    unset($currentUpdates[$folder]);
                    continue;
                }
                if (($currentPhysical[$folder] ?? null) !== ($baselinePhysical[$folder] ?? null)) {
                    if (!$generationChanged || $present === $baseline) unset($currentUpdates[$folder]);
                    continue;
                }
                if ($result === false) {
                    if (!$generationChanged || $present === $baseline) {
                        if (is_array($present)) $currentUpdates[$folder]['actionable'] = false;
                    }
                    continue;
                }
                if ($result === null) {
                    if (!$generationChanged || $present === $baseline) unset($currentUpdates[$folder]);
                    continue;
                }

                $candidate = $result;
                $installedVersion = is_string($installedVersions[$folder] ?? null) ? $installedVersions[$folder] : '';
                $installedDuringCheck = $generationChanged
                    && $installedVersion !== (is_string($baselineInstalledVersions[$folder] ?? null) ? $baselineInstalledVersions[$folder] : '');
                if ($installedDuringCheck && $installedVersion !== ''
                    && version_compare($installedVersion, (string)$candidate['current_version'], '>')) {
                    if (version_compare((string)$candidate['new_version'], $installedVersion, '>')) {
                        $candidate['current_version'] = $installedVersion;
                    } else {
                        if (!is_array($present)
                            || !version_compare((string)($present['new_version'] ?? ''), $installedVersion, '>')) {
                            unset($currentUpdates[$folder]);
                        }
                        continue;
                    }
                }
                if (is_array($present)) {
                    $comparison = version_compare((string)($present['new_version'] ?? ''), (string)$candidate['new_version']);
                    if ($comparison > 0 || ($comparison === 0 && $generationChanged && $present !== $baseline)) {
                        continue;
                    }
                }
                $currentUpdates[$folder] = $candidate;
            }

            $current['last_attempt'] = $now;
            if ($state !== 'error') $current['last_check'] = $now;
            $current['state'] = $state;
            $current['errors'] = $failed;
            if ($generationChanged) {
                foreach ($baselineUpdates as $folder => $baseline) {
                    if (!isset($eligible[$folder]) && ($currentUpdates[$folder] ?? null) === $baseline) {
                        unset($currentUpdates[$folder]);
                    }
                }
                $current['updates'] = $currentUpdates;
            } else {
                $current['updates'] = array_intersect_key($currentUpdates, $eligible);
            }
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

    public static function forgetInstalledTheme(string $folder): void
    {
        if (!self::validFolder($folder)) return;
        self::mutateTransient(static function (array $transient) use ($folder): array {
            unset($transient['updates'][$folder], $transient['installed_versions'][$folder]);
            return $transient;
        });
    }

    public static function preflightUpdate(PDO $pdo, string $folder, array $decisions = []): array
    {
        if (!self::validFolder($folder)) return self::safePreflightError('Invalid theme name.');
        $locks = [];
        try {
            self::loadThemeHelpers();
            $normalizedDecisions = self::normalizeDecisions($decisions);
            $locks = theme_operation_acquire(theme_lifecycle_lock_keys([$folder]));
            $update = self::actionableUpdate($folder);
            if ($update === null) {
                return self::safePreflightError('No update available for "' . $folder . '". Run "Check for Updates" first.');
            }
            $installed = self::readPhysicalManifest($folder);
            return array_merge(['success' => true], self::runPreflight($pdo, $folder, $update, $installed['manifest'], $normalizedDecisions));
        } catch (Throwable $error) {
            error_log('[theme-update-preflight] ' . $error->getMessage());
            return self::safePreflightError('Unable to validate this theme update safely.');
        } finally {
            if (function_exists('theme_operation_release')) theme_operation_release($locks);
        }
    }

    public static function applyUpdate(PDO $pdo, string $folderName, string $progressToken = '', array $decisions = []): array
    {
        if (!self::validFolder($folderName)) {
            if ($progressToken !== '') self::writeProgress($progressToken, 0, __('Invalid theme name.'), true, __('Invalid theme name.'));
            return ['success' => false, 'error' => 'Invalid theme name.'];
        }
        require_once __DIR__ . '/UpdateStatusController.php';
        $p = static function (int $pct, string $status) use ($progressToken): void {
            if ($progressToken !== '') self::writeProgress($progressToken, $pct, $status);
        };
        $fail = static function (string $error, ?string $code = null, array $extra = []) use ($progressToken): array {
            self::writeProgress($progressToken, 0, $error, true, $error);
            return array_merge(['success' => false, 'error' => $error], $code === null ? [] : ['code' => $code], $extra);
        };
        $locks = [];
        $tmpZip = null;
        $stage = null;
        $themeDir = null;
        $oldIdentity = null;
        $oldManifest = null;
        $rollbackPath = null;
        $swapped = false;
        try {
            self::loadThemeHelpers();
            $normalizedDecisions = self::normalizeDecisions($decisions);
            $locks = theme_operation_acquire(theme_lifecycle_lock_keys([$folderName]));
            $update = self::actionableUpdate($folderName);
            if ($update === null) return $fail('No update available for "' . $folderName . '". Run "Check for Updates" first.');
            $installedBefore = self::readPhysicalManifest($folderName);
            $themeDir = $installedBefore['directory'];
            $oldManifest = $installedBefore['manifest'];
            $oldVersion = (string)($oldManifest['version'] ?? '');
            if ($oldVersion === '' || !hash_equals((string)($update['current_version'] ?? ''), $oldVersion)) {
                return $fail(__('Installed theme version changed. Run "Check for Updates" again.'));
            }
            $preflight = self::runPreflight($pdo, $folderName, $update, $oldManifest, $normalizedDecisions);
            if (!$preflight['allowed']) {
                return $fail(__('Theme update requirements must be resolved before continuing.'), 'theme_update_preflight_required', ['issues' => $preflight['issues']]);
            }
            $oldIdentity = package_tree_identity($themeDir);
            if ($oldIdentity === null) return $fail(__('Installed theme tree contains unsupported entries.'));

            $p(18, __('Downloading update package...'));
            $tmpZip = self::downloadPackage((string)$update['download_url'], $p);
            if ($tmpZip === null) return $fail(__('Failed to download update from store.'));
            if (!is_string($update['checksum'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/i', (string)$update['checksum']) !== 1
                || !hash_equals(strtolower((string)$update['checksum']), (string)hash_file('sha256', $tmpZip))) {
                return $fail(__('Invalid update package.'));
            }
            $zip = new ZipArchive();
            if ($zip->open($tmpZip) !== true) return $fail(__('Invalid update package.'));
            $validation = package_archive_validate($zip);
            if (!$validation['success']) { $zip->close(); return $fail($validation['error']); }
            $manifestIndex = $zip->locateName('theme.json', ZipArchive::FL_NOCASE);
            if ($manifestIndex === false || (string)($zip->statIndex($manifestIndex)['name'] ?? '') !== 'theme.json') {
                $zip->close();
                return $fail(__('theme.json not found in package.'));
            }
            $manifestRaw = $zip->getFromIndex($manifestIndex);
            $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
            $identityMatches = is_array($manifest) && (!array_key_exists('folder', $manifest)
                || (is_string($manifest['folder']) && hash_equals($folderName, $manifest['folder'])));
            if (!is_array($manifest) || !$identityMatches || !is_string($manifest['version'] ?? null)
                || version_compare($manifest['version'], (string)$update['new_version'], '!=')
                || validate_theme_manifest($manifest) !== []) {
                $zip->close();
                return $fail(__('Invalid theme.json.'));
            }
            $files = [];
            $hasPhp = false;
            foreach ($validation['entries'] as $entry) {
                if ($entry['directory']) continue;
                $relative = $entry['path'];
                $relativeKey = strtolower($relative);
                if ($relativeKey === '.store.json' || $relativeKey === '.git' || str_starts_with($relativeKey, '.git/')) {
                    $zip->close();
                    return $fail(__('Invalid update package.'));
                }
                if (strtolower((string)pathinfo($relative, PATHINFO_EXTENSION)) === 'php') $hasPhp = true;
                $files[] = ['source' => $entry['source'], 'relative' => $relative];
            }
            if (!$hasPhp) { $zip->close(); return $fail(__('Invalid update package.')); }
            $stage = package_private_directory(dirname($themeDir), 'theme-stage-' . $folderName);
            if ($stage === null) { $zip->close(); return $fail(__('Failed to create theme staging directory.')); }
            $p(55, __('Installing update files to staging...'));
            $extracted = package_archive_extract_files($zip, $files, $stage);
            $zip->close();
            @unlink($tmpZip);
            $tmpZip = null;
            if (!$extracted || !package_chmod_tree($stage)
                || !package_copy_preserved_paths($themeDir, $stage, ['.store.json', '.git'])) {
                return $fail(__('Failed to build the complete theme staging tree.'));
            }
            $stagedRaw = @file_get_contents($stage . '/theme.json');
            $stagedManifest = is_string($stagedRaw) ? json_decode($stagedRaw, true) : null;
            if (!is_array($stagedManifest) || $stagedManifest !== $manifest || package_tree_identity($stage) === null) {
                return $fail(__('Staged theme verification failed.'));
            }
            $p(80, __('Publishing complete theme tree...'));
            $newIdentity = package_tree_identity($stage);
            if ($newIdentity === null) return $fail(__('Staged theme identity verification failed.'));
            $publication = package_guarded_publish($stage, $themeDir, $oldIdentity, $newIdentity);
            if (!$publication['success']) {
                $recovery = $publication['recovery_paths'][0] ?? null;
                return $fail($publication['restored']
                    ? __('Theme publication failed; exact old tree restored.')
                    : __('Theme publication failed closed. Recovery path: ') . ($recovery ?? __('unavailable')));
            }
            $rollbackPath = $publication['rollback_path'];
            $stage = null;
            $swapped = true;

            $rollback = static function (string $failure, string $code) use (
                $pdo, $folderName, $themeDir, &$rollbackPath, $oldIdentity, $oldManifest, $fail, &$swapped
            ): array {
                $restoration = is_string($rollbackPath)
                    ? package_guarded_rollback($themeDir, $rollbackPath, $oldIdentity)
                    : ['restored' => false, 'recovery_paths' => []];
                $filesRestored = ($restoration['restored'] ?? false) === true;
                if ($filesRestored) {
                    $rollbackPath = null;
                    $swapped = false;
                }
                $metadataRestored = false;
                if ($filesRestored) {
                    try { $metadataRestored = register_theme_in_db($pdo, $folderName, $oldManifest) === true; }
                    catch (Throwable $error) { error_log('[theme-update] Metadata rollback failed: ' . $error->getMessage()); }
                }
                if ($filesRestored && $metadataRestored) {
                    return $fail($failure . ' ' . __('Exact old theme tree and database metadata were restored.'), $code, [
                        'restored' => true, 'metadata_restored' => true,
                    ]);
                }
                $recovery = $restoration['recovery_paths'][0] ?? $rollbackPath;
                return $fail($failure . ' ' . __('Rollback was incomplete; a recovery artifact was preserved when possible.'), $code, [
                    'restored' => $filesRestored, 'metadata_restored' => $metadataRestored, 'recovery' => $recovery,
                ]);
            };

            $installedManifest = self::readPhysicalManifest($folderName)['manifest'];
            if (!hash_equals((string)$update['new_version'], (string)($installedManifest['version'] ?? ''))
                || package_tree_identity($themeDir) === null) {
                return $rollback(__('Updated theme verification failed.'), 'theme_update_verification_failed');
            }
            try {
                if (register_theme_in_db($pdo, $folderName, $installedManifest) !== true) throw new RuntimeException('Theme registration failed.');
            } catch (Throwable $error) {
                error_log('[theme-update] ' . $error->getMessage());
                return $rollback(__('Theme registration failed.'), 'theme_update_registration_failed');
            }
            $cleanupWarning = is_string($rollbackPath) && package_guarded_finalize($themeDir, $rollbackPath, $oldIdentity)
                ? ''
                : __('Exact old rollback-tree cleanup requires manual attention.');
            if ($cleanupWarning === '') {
                $rollbackPath = null;
                $swapped = false;
            }
            $metadataWarning = '';
            try {
                self::removeCachedUpdate($folderName, (string)$update['new_version']);
            } catch (Throwable $metadataError) {
                error_log('[theme-update] Unable to finalize update metadata: ' . $metadataError->getMessage());
                $metadataWarning = __('Update metadata cleanup requires another Check for Updates.');
            }
            if (function_exists('do_action_isolated')) {
                foreach (do_action_isolated('theme_update_completed', $folderName, $oldVersion, (string)$update['new_version'], $installedManifest) as $hookError) {
                    error_log('[theme_update_completed] ' . $hookError['message']);
                }
            }
            self::writeProgress($progressToken, 100, __('Done!'), true);
            $result = ['success' => true, 'new_version' => $update['new_version']];
            if ($cleanupWarning !== '' || $metadataWarning !== '') $result['warning'] = trim($cleanupWarning . ' ' . $metadataWarning);
            return $result;
        } catch (Throwable $error) {
            error_log('[theme-update] ' . $error->getMessage());
            if ($swapped && is_string($rollbackPath) && is_string($themeDir) && is_array($oldIdentity) && is_array($oldManifest)) {
                $restoration = package_guarded_rollback($themeDir, $rollbackPath, $oldIdentity);
                $restored = ($restoration['restored'] ?? false) === true;
                if ($restored) {
                    $rollbackPath = null;
                    $swapped = false;
                }
                $metadata = false;
                if ($restored) {
                    try { $metadata = register_theme_in_db($pdo, $folderName, $oldManifest) === true; }
                    catch (Throwable $metadataError) { error_log('[theme-update] Metadata rollback failed: ' . $metadataError->getMessage()); }
                }
                if ($restored && $metadata) {
                    return $fail(__('Theme update failed unexpectedly; exact old tree and metadata were restored.'), 'theme_update_unexpected_failure', ['restored' => true, 'metadata_restored' => true]);
                }
                $recovery = $restoration['recovery_paths'][0] ?? $rollbackPath;
                return $fail(__('Theme update failed and rollback was incomplete.'), 'theme_update_unexpected_failure', ['restored' => $restored, 'metadata_restored' => $metadata, 'recovery' => $recovery]);
            }
            return $fail(__('Theme update failed safely.'));
        } finally {
            if (is_string($tmpZip) && is_file($tmpZip)) @unlink($tmpZip);
            if (!$swapped && is_string($stage) && is_dir($stage)) package_remove_tree($stage);
            if ($locks !== []) theme_operation_release($locks);
        }
    }

    private static function validFolder(string $folder): bool
    {
        return strlen($folder) <= 128
            && preg_match('/\A[a-zA-Z0-9_-][a-zA-Z0-9._-]*\z/', $folder) === 1
            && !in_array($folder, ['.', '..'], true);
    }

    private static function loadThemeHelpers(): void
    {
        if (!function_exists('theme_operation_acquire')) {
            require_once dirname(__DIR__, 2) . '/cfg/helpers/theme_helper.php';
        }
    }

    private static function actionableUpdate(string $folder): ?array
    {
        require_once __DIR__ . '/UpdateStatusController.php';
        if (!UpdateStatusController::isUpdateActionable('themes', $folder)) return null;
        $updates = self::getCachedUpdates();
        return isset($updates[$folder]) && is_array($updates[$folder]) ? $updates[$folder] : null;
    }

    private static function readPhysicalManifest(string $folder): array
    {
        $themesRoot = realpath(rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR));
        $themeCandidate = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder;
        $directory = realpath($themeCandidate);
        if ($themesRoot === false || $directory === false || is_link($themeCandidate)
            || !str_starts_with($directory, $themesRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Theme directory not found.');
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'theme.json';
        $manifestReal = realpath($path);
        $size = @filesize($path);
        if ($manifestReal === false || is_link($path) || !is_file($path) || !is_int($size) || $size < 2 || $size > 1024 * 1024
            || !str_starts_with($manifestReal, $directory . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Invalid installed theme manifest.');
        }
        $raw = @file_get_contents($manifestReal);
        if (!is_string($raw)) throw new RuntimeException('Unable to read installed theme manifest.');
        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Invalid installed theme manifest.', 0, $error);
        }
        if (!is_array($manifest) || array_is_list($manifest)
            || !is_string($manifest['version'] ?? null) || trim($manifest['version']) === '') {
            throw new RuntimeException('Invalid installed theme manifest.');
        }
        $manifestFolder = $manifest['folder'] ?? $folder;
        if (!is_string($manifestFolder) || $manifestFolder !== $folder) {
            throw new RuntimeException('Installed theme identity does not match its folder.');
        }
        $manifest['folder'] = $folder;
        return ['directory' => $directory, 'manifest' => $manifest];
    }

    private static function normalizeDecisions(array $decisions): array
    {
        if (count($decisions) > self::MAX_DECISIONS || ($decisions !== [] && array_is_list($decisions))) {
            throw new InvalidArgumentException('Invalid theme update decisions.');
        }
        $normalized = [];
        foreach ($decisions as $issueId => $decision) {
            if (!is_string($issueId) || !self::validIdentifier($issueId) || !is_array($decision)
                || count($decision) !== 2 || !array_key_exists('choice', $decision) || !array_key_exists('state_token', $decision)
                || !is_string($decision['choice']) || !self::validIdentifier($decision['choice'])
                || !is_string($decision['state_token']) || strlen($decision['state_token']) > 64
                || ($decision['state_token'] !== '' && preg_match('/\A[a-f0-9]{64}\z/i', $decision['state_token']) !== 1)) {
                throw new InvalidArgumentException('Invalid theme update decisions.');
            }
            $normalized[$issueId] = [
                'choice' => $decision['choice'],
                'state_token' => strtolower($decision['state_token']),
            ];
        }
        return $normalized;
    }

    private static function runPreflight(PDO $pdo, string $folder, array $update, array $manifest, array $decisions): array
    {
        try {
            $state = ['schema' => 1, 'issues' => [], 'decisions' => $decisions];
            $previousIssues = [];
            $callbacks = $GLOBALS['_hooks']['filters']['theme_update_preflight'] ?? [];
            ksort($callbacks);
            foreach ($callbacks as $listeners) {
                foreach ($listeners as $listener) {
                    $candidate = call_user_func($listener, $state, $folder, $update, $manifest, $pdo);
                    $normalized = self::normalizePreflight($candidate, $decisions);
                    if (count($normalized['issues']) < count($previousIssues)
                        || array_slice($normalized['issues'], 0, count($previousIssues)) !== $previousIssues) {
                        throw new RuntimeException('Theme update preflight issues are not monotonic.');
                    }
                    $previousIssues = $normalized['issues'];
                    $state = ['schema' => 1, 'issues' => $previousIssues, 'decisions' => $decisions];
                }
            }
            return self::normalizePreflight($state, $decisions);
        } catch (Throwable $error) {
            error_log('[theme_update_preflight] ' . $error->getMessage());
            return ['allowed' => false, 'issues' => [self::invalidPreflightIssue()]];
        }
    }

    private static function normalizePreflight(mixed $filtered, array $decisions): array
    {
        if (!is_array($filtered) || ($filtered['schema'] ?? null) !== 1
            || !is_array($filtered['issues'] ?? null) || !array_is_list($filtered['issues'])
            || count($filtered['issues']) > self::MAX_ISSUES
            || !array_key_exists('decisions', $filtered)
            || self::normalizeDecisions(is_array($filtered['decisions']) ? $filtered['decisions'] : throw new RuntimeException('Malformed preflight decisions.')) !== $decisions) {
            throw new RuntimeException('Malformed theme update preflight output.');
        }

        $issues = [];
        $seen = [];
        foreach ($filtered['issues'] as $issue) {
            if (!is_array($issue) || !is_string($issue['id'] ?? null) || !self::validIdentifier($issue['id'])
                || isset($seen[$issue['id']]) || !is_string($issue['label'] ?? null)
                || !self::boundedString($issue['label'], 200, false)
                || !is_string($issue['message'] ?? null) || !self::boundedString($issue['message'], 2000, true)
                || !is_bool($issue['blocking'] ?? null) || !is_bool($issue['resolved'] ?? null)) {
                throw new RuntimeException('Malformed theme update preflight issue.');
            }
            $seen[$issue['id']] = true;
            $choices = self::normalizeChoices($issue['choices'] ?? []);
            $normalized = [
                'id' => $issue['id'],
                'label' => $issue['label'],
                'message' => $issue['message'],
                'blocking' => $issue['blocking'],
                'resolved' => $issue['resolved'],
                'choices' => $choices,
                'links' => self::normalizeLinks($issue['links'] ?? []),
                'details' => self::normalizeScalarMap($issue['details'] ?? [], 64, 4000),
            ];
            if (array_key_exists('state_token', $issue)) {
                if (!is_string($issue['state_token']) || preg_match('/\A[a-f0-9]{64}\z/i', $issue['state_token']) !== 1) {
                    throw new RuntimeException('Malformed theme update issue state token.');
                }
                $normalized['state_token'] = strtolower($issue['state_token']);
            }
            foreach ($choices as $choice) {
                if ($choice['destructive'] && !isset($normalized['state_token'])) {
                    throw new RuntimeException('Destructive theme update choices require a state token.');
                }
            }
            $issues[] = $normalized;
        }

        $allowed = true;
        foreach ($issues as $issue) {
            if ($issue['blocking'] && !$issue['resolved']) {
                $allowed = false;
                break;
            }
        }
        return ['allowed' => $allowed, 'issues' => $issues];
    }

    private static function normalizeChoices(mixed $choices): array
    {
        if (!is_array($choices) || !array_is_list($choices) || count($choices) > 20) {
            throw new RuntimeException('Malformed theme update choices.');
        }
        $result = [];
        $seen = [];
        foreach ($choices as $choice) {
            if (!is_array($choice) || !is_string($choice['id'] ?? null) || !self::validIdentifier($choice['id'])
                || isset($seen[$choice['id']]) || !is_string($choice['label'] ?? null)
                || !self::boundedString($choice['label'], 200, false) || !is_bool($choice['destructive'] ?? null)) {
                throw new RuntimeException('Malformed theme update choice.');
            }
            $seen[$choice['id']] = true;
            $result[] = ['id' => $choice['id'], 'label' => $choice['label'], 'destructive' => $choice['destructive']];
        }
        return $result;
    }

    private static function normalizeLinks(mixed $links): array
    {
        if (!is_array($links) || !array_is_list($links) || count($links) > 20) {
            throw new RuntimeException('Malformed theme update links.');
        }
        $result = [];
        foreach ($links as $link) {
            $url = $link['url'] ?? null;
            $method = $link['method'] ?? null;
            if (!is_array($link) || !is_string($link['label'] ?? null) || !self::boundedString($link['label'], 200, false)
                || !is_string($method) || !in_array($method, ['GET', 'POST'], true)
                || !is_string($url) || !self::safeRelativeUrl($url)) {
                throw new RuntimeException('Malformed theme update link.');
            }
            $result[] = [
                'label' => $link['label'],
                'method' => $method,
                'url' => $url,
                'params' => self::normalizeScalarMap($link['params'] ?? [], 32, 1000),
            ];
        }
        return $result;
    }

    private static function normalizeScalarMap(mixed $values, int $limit, int $stringLimit): array
    {
        if (!is_array($values) || count($values) > $limit || ($values !== [] && array_is_list($values))) {
            throw new RuntimeException('Malformed bounded scalar map.');
        }
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || !self::validIdentifier($key)
                || (!is_null($value) && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value))
                || (is_float($value) && !is_finite($value))
                || (is_string($value) && strlen($value) > $stringLimit)) {
                throw new RuntimeException('Malformed bounded scalar map.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private static function validIdentifier(string $value): bool
    {
        return strlen($value) <= 64 && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]*\z/', $value) === 1;
    }

    private static function boundedString(string $value, int $limit, bool $allowEmpty): bool
    {
        return strlen($value) <= $limit && ($allowEmpty || trim($value) !== '') && !str_contains($value, "\0");
    }

    private static function safeRelativeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 1000 || $url[0] !== '/' || str_starts_with($url, '//')
            || str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) return false;
        $parts = parse_url($url);
        return is_array($parts) && !isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass']);
    }

    private static function invalidPreflightIssue(): array
    {
        return [
            'id' => 'core_preflight_invalid',
            'label' => function_exists('__') ? __('Update Failed') : 'Update Failed',
            'message' => function_exists('__') ? __('The update requirements could not be validated safely.') : 'The update requirements could not be validated safely.',
            'blocking' => true,
            'resolved' => false,
            'choices' => [],
            'links' => [],
            'details' => [],
        ];
    }

    private static function safePreflightError(string $error): array
    {
        if (function_exists('__')) $error = __($error);
        return ['success' => false, 'allowed' => false, 'issues' => [], 'error' => $error];
    }

    private static function safeThemeTarget(string $root, string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')
            || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) return null;
        $parts = explode('/', $relative);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) return null;
        $base = realpath($root);
        if ($base === false) return null;
        $parentPath = dirname($base . '/' . $relative);
        while (!file_exists($parentPath) && dirname($parentPath) !== $parentPath) $parentPath = dirname($parentPath);
        $parent = realpath($parentPath);
        if ($parent === false || ($parent !== $base && !str_starts_with($parent, $base . DIRECTORY_SEPARATOR))) return null;
        return $base . '/' . $relative;
    }

    private static function zipEntryTypeIsSafe(ZipArchive $zip, int $index, bool $directory): bool
    {
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) return true;
        $type = ($attributes >> 16) & 0170000;
        return $type === 0 || $type === 0100000 || ($directory && $type === 0040000);
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

    public static function clearProgress(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return;
        $file = self::progressFile($token);
        if (is_file($file)) @unlink($file);
    }

    private static function transientFile(): string
    {
        $backend = defined('BACKEND_PATH') ? BACKEND_PATH : __DIR__ . '/../../cfg';
        return $backend . '/var/theme-update-transient.json';
    }

    private static function readTransient(): array
    {
        $file = self::transientFile();
        if (!is_file($file)) return ['last_check' => 0, 'generation' => '', 'updates' => [], 'installed_versions' => []];
        $data = json_decode((string)file_get_contents($file), true);
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
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create the theme update transient directory.');
        }
        $lock = fopen($file . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Unable to acquire the theme update transient lock.');
        }
        try {
            $current = self::readTransient();
            $next = $mutation($current);
            if (!is_array($next)) throw new RuntimeException('Invalid theme update transient mutation.');
            $next['generation'] = bin2hex(random_bytes(16));
            $temporary = $dir . '/.theme-update-transient-' . bin2hex(random_bytes(12)) . '.tmp';
            $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            $handle = fopen($temporary, 'x+b');
            if ($handle === false) throw new RuntimeException('Unable to create the theme update transient replacement.');
            $written = fwrite($handle, $json);
            $flushed = $written === strlen($json) && fflush($handle);
            $synced = $flushed && (!function_exists('fsync') || fsync($handle));
            fclose($handle);
            if (!$synced || !chmod($temporary, 0640) || !rename($temporary, $file)) {
                if (is_file($temporary)) @unlink($temporary);
                throw new RuntimeException('Unable to persist the theme update transient.');
            }
            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function removeCachedUpdate(string $folder, string $installedVersion): void
    {
        self::mutateTransient(static function (array $transient) use ($folder, $installedVersion): array {
            $recorded = is_string($transient['installed_versions'][$folder] ?? null)
                ? $transient['installed_versions'][$folder]
                : '';
            if ($recorded === '' || version_compare($installedVersion, $recorded, '>')) {
                $transient['installed_versions'][$folder] = $installedVersion;
            }
            $update = $transient['updates'][$folder] ?? null;
            if (is_array($update) && version_compare((string)($update['new_version'] ?? ''), $installedVersion, '>')) {
                $transient['updates'][$folder]['current_version'] = $installedVersion;
            } else {
                unset($transient['updates'][$folder]);
            }
            return $transient;
        });
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
        $progress(18, __('Downloading update package...'));
        return package_download($url, 'theme-update-', 'JyavaniCMS-ThemeUpdate', static function (int $downloaded, int $total) use ($progress): void {
            if ($total > 0) $progress(18 + (int)floor(17 * min(1, $downloaded / $total)), __('Downloading update package...'));
        });
    }

    private static function fetchVersionInfo(string $url): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'JyavaniCMS/2.0',
            ],
        ]);
        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    public static function scanInstalledThemes(PDO $pdo): array
    {
        $themes = [];
        $registered = get_registered_themes($pdo);
        foreach ($registered as $t) {
            $folder = $t['folder_name'] ?? '';
            if ($folder === '') continue;

            $manifest = [];
            try {
                $manifest = read_theme_manifest(path_candidate(VIEWS_BASE, $folder, ''));
            } catch (Throwable $e) {
                $manifest = [];
            }

            $themes[$folder] = $manifest;
        }
        return $themes;
    }
}
