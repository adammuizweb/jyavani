<?php
declare(strict_types=1);

final class UpdateStatusController
{
    private const SCHEMA = 1;
    private const TTL = 3600;
    private const DEFAULT_CORE_URL = 'https://jyavani.com/download/latest/';

    public static function getSnapshot(): array
    {
        $snapshot = self::readSnapshot();
        $snapshot['stale'] = (int)($snapshot['expires_at'] ?? 0) <= time();
        return $snapshot;
    }

    public static function checkAll(PDO $pdo, ?string $coreUrl = null, array $providers = []): array
    {
        $coreUrl = trim((string)($coreUrl ?? self::DEFAULT_CORE_URL));
        if ($coreUrl === '' || filter_var($coreUrl, FILTER_VALIDATE_URL) === false
            || strtolower((string)parse_url($coreUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidArgumentException('The Core update URL must be a valid HTTPS URL.');
        }

        $file = self::snapshotFile();
        $beforeLock = self::readSnapshot();
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the update status directory.');
        }

        $lock = fopen($file . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Unable to acquire the update status lock.');
        }

        try {
            $previous = self::readSnapshot();
            $snapshotChanged = (int)($previous['checked_at'] ?? 0) > (int)($beforeLock['checked_at'] ?? 0)
                || (string)($previous['generation'] ?? '') !== (string)($beforeLock['generation'] ?? '');
            if ($snapshotChanged && ($previous['components']['core']['check_url'] ?? '') === $coreUrl) {
                $previous['stale'] = (int)($previous['expires_at'] ?? 0) <= time();
                return $previous;
            }
            $now = time();

            $core = self::checkCore($coreUrl, $previous['components']['core'] ?? [], $providers['core'] ?? null, $now);
            $plugins = self::checkPlugins($pdo, $previous['components']['plugins'] ?? [], $providers['plugins'] ?? null, $now);
            $themes = self::checkThemes($pdo, $previous['components']['themes'] ?? [], $providers['themes'] ?? null, $now);
            $states = [$core['state'], $plugins['state'], $themes['state']];
            $failed = count(array_filter($states, static fn(string $state): bool => $state !== 'ok'));

            $snapshot = [
                'schema' => self::SCHEMA,
                'checked_at' => $now,
                'expires_at' => $now + ($failed === 0 ? self::TTL : 300),
                'state' => $failed === 0 ? 'ok' : ($failed === count($states) ? 'error' : 'partial'),
                'components' => [
                    'core' => $core,
                    'plugins' => $plugins,
                    'themes' => $themes,
                ],
            ];
            $snapshot = self::withSummary($snapshot);
            self::writeSnapshot($snapshot);
            $snapshot['stale'] = false;
            return $snapshot;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function publicPayload(?array $snapshot = null): array
    {
        $snapshot ??= self::getSnapshot();
        $core = is_array($snapshot['components']['core'] ?? null) ? $snapshot['components']['core'] : [];
        $pluginUpdates = is_array($snapshot['components']['plugins']['updates'] ?? null) ? $snapshot['components']['plugins']['updates'] : [];
        $themeUpdates = is_array($snapshot['components']['themes']['updates'] ?? null) ? $snapshot['components']['themes']['updates'] : [];

        $plugins = [];
        foreach ($pluginUpdates as $name => $info) {
            if (!is_array($info)) continue;
            $plugins[] = [
                'name' => (string)$name,
                'current' => (string)($info['current_version'] ?? '?'),
                'latest' => (string)($info['new_version'] ?? '?'),
            ];
        }
        $themes = [];
        foreach ($themeUpdates as $folder => $info) {
            if (!is_array($info)) continue;
            $themes[] = [
                'name' => (string)$folder,
                'current' => (string)($info['current_version'] ?? '?'),
                'latest' => (string)($info['new_version'] ?? '?'),
            ];
        }

        return [
            'ok' => true,
            'state' => (string)($snapshot['state'] ?? 'unknown'),
            'stale' => ($snapshot['stale'] ?? true) === true,
            'checked_at' => (int)($snapshot['checked_at'] ?? 0),
            'total' => (int)($snapshot['total'] ?? 0),
            'cms' => [
                'state' => (string)($core['state'] ?? 'unknown'),
                'has_update' => ($core['has_update'] ?? false) === true,
                'current' => (string)($core['current'] ?? self::localVersion()),
                'latest' => (string)($core['latest'] ?? self::localVersion()),
            ],
            'critical_advisory' => is_array($core['critical_advisory'] ?? null) ? $core['critical_advisory'] : null,
            'plugins' => $plugins,
            'themes' => $themes,
            'failed_components' => array_values(array_filter([
                ($core['state'] ?? 'unknown') === 'ok' ? null : 'core',
                (($snapshot['components']['plugins']['state'] ?? 'unknown') === 'ok') ? null : 'plugins',
                (($snapshot['components']['themes']['state'] ?? 'unknown') === 'ok') ? null : 'themes',
            ])),
        ];
    }

    public static function getComponentUpdates(string $component): array
    {
        if (!in_array($component, ['plugins', 'themes'], true)) return [];
        $snapshot = self::getSnapshot();
        $updates = $snapshot['components'][$component]['updates'] ?? [];
        if (!is_array($updates)) return [];
        $componentFailed = ($snapshot['components'][$component]['state'] ?? 'unknown') === 'error';
        foreach ($updates as $name => &$update) {
            if (!is_array($update)) continue;
            if ($componentFailed || !self::snapshotUpdateActionable($snapshot, $component, (string)$name)) {
                $update['actionable'] = false;
            }
        }
        unset($update);
        return $updates;
    }

    public static function isUpdateActionable(string $component, string $name = '', ?string $targetVersion = null): bool
    {
        return self::snapshotUpdateActionable(self::getSnapshot(), $component, $name, $targetVersion);
    }

    public static function hydrateCoreSession(?array $snapshot = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        // A manually uploaded package owns its manifest/session pair until apply or cancel.
        if (is_string($_SESSION['cms_update_package'] ?? null) && $_SESSION['cms_update_package'] !== '') return;
        $snapshot ??= self::getSnapshot();
        $core = $snapshot['components']['core'] ?? null;
        if (!is_array($core) || ($core['state'] ?? 'unknown') === 'unknown') return;

        $_SESSION['cms_update_cache'] = [
            'has_update' => ($core['has_update'] ?? false) === true,
            'current' => (string)($core['current'] ?? self::localVersion()),
            'latest' => (string)($core['latest'] ?? self::localVersion()),
            'checked_at' => (int)($core['checked_at'] ?? $snapshot['checked_at'] ?? time()),
        ];
        if (is_array($core['critical_advisory'] ?? null)) {
            $_SESSION['cms_update_cache']['critical_advisory'] = $core['critical_advisory'];
        }

        if (self::snapshotUpdateActionable($snapshot, 'core')
            && is_array($core['remote'] ?? null)) {
            $_SESSION['cms_update_remote'] = $core['remote'];
            $_SESSION['cms_update_base_url'] = (string)($core['base_url'] ?? self::DEFAULT_CORE_URL);
        } else {
            unset($_SESSION['cms_update_remote'], $_SESSION['cms_update_base_url']);
        }
    }

    public static function removeUpdate(string $component, string $name = '', ?string $installedVersion = null): void
    {
        $file = self::snapshotFile();
        $directory = dirname($file);
        if (!is_dir($directory)) return;
        $lock = fopen($file . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            return;
        }
        try {
            $snapshot = self::readSnapshot();
            if ($component === 'core') {
                $version = $installedVersion ?: self::localVersion();
                $core = is_array($snapshot['components']['core'] ?? null) ? $snapshot['components']['core'] : [];
                if (version_compare((string)($core['latest'] ?? $version), $version, '>')) {
                    $snapshot['components']['core'] = array_replace($core, [
                        'state' => 'ok',
                        'checked_at' => time(),
                        'current' => $version,
                        'has_update' => true,
                        'error' => null,
                    ]);
                } else {
                    $snapshot['components']['core'] = [
                        'state' => 'ok',
                        'checked_at' => time(),
                        'current' => $version,
                        'latest' => $version,
                        'has_update' => false,
                        'actionable' => false,
                        'check_url' => (string)($core['check_url'] ?? self::DEFAULT_CORE_URL),
                        'base_url' => (string)($core['base_url'] ?? self::DEFAULT_CORE_URL),
                        'error' => null,
                    ];
                }
            } elseif (in_array($component, ['plugins', 'themes'], true) && $name !== '') {
                unset($snapshot['components'][$component]['updates'][$name]);
            }
            $snapshot = self::withSummary($snapshot);
            self::writeSnapshot($snapshot);
        } catch (Throwable $error) {
            error_log('[update-status] Unable to synchronize completed update: ' . $error->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function checkCore(string $url, array $previous, mixed $provider, int $now): array
    {
        $localVersion = self::localVersion();
        try {
            $remote = is_callable($provider) ? $provider($url, $localVersion) : self::fetchCore($url, $localVersion);
        } catch (Throwable $error) {
            $remote = null;
        }
        if (!is_array($remote) || !is_string($remote['version'] ?? null) || trim($remote['version']) === '') {
            return array_replace($previous, [
                'state' => 'error',
                'checked_at' => $now,
                'current' => $localVersion,
                'check_url' => $url,
                'actionable' => false,
                'error' => 'core_check_failed',
            ]);
        }

        $latest = trim((string)$remote['version']);
        $component = [
            'state' => 'ok',
            'checked_at' => $now,
            'current' => $localVersion,
            'latest' => $latest,
            'has_update' => version_compare($latest, $localVersion, '>'),
            'actionable' => true,
            'remote' => $remote,
            'check_url' => $url,
            'base_url' => str_ends_with(strtolower((string)parse_url($url, PHP_URL_PATH)), '.json') ? dirname($url) : $url,
            'error' => null,
        ];
        $advisory = $remote['critical_advisory'] ?? null;
        if (is_array($advisory) && ($advisory['severity'] ?? '') === 'critical'
            && is_string($advisory['id'] ?? null) && is_string($advisory['fixed_version'] ?? null)
            && version_compare($localVersion, $advisory['fixed_version'], '<')) {
            $component['critical_advisory'] = [
                'id' => $advisory['id'],
                'title' => (string)($advisory['title'] ?? 'Critical security update'),
                'message' => (string)($advisory['message'] ?? ''),
                'fixed_version' => $advisory['fixed_version'],
                'url' => (string)($advisory['url'] ?? ''),
            ];
        }
        return $component;
    }

    private static function checkPlugins(PDO $pdo, array $previous, mixed $provider, int $now): array
    {
        require_once __DIR__ . '/PluginStoreController.php';
        try {
            $result = is_callable($provider) ? $provider($pdo) : PluginStoreController::checkUpdatesDetailed($pdo);
            return self::normalizeCollectionResult($result, $previous, $now);
        } catch (Throwable $error) {
            return array_replace($previous, ['state' => 'error', 'checked_at' => $now, 'error' => 'plugin_check_failed']);
        }
    }

    private static function checkThemes(PDO $pdo, array $previous, mixed $provider, int $now): array
    {
        require_once __DIR__ . '/ThemeStoreClient.php';
        try {
            if (!is_callable($provider) && function_exists('register_all_themes_from_fs')) register_all_themes_from_fs($pdo);
            $result = is_callable($provider) ? $provider($pdo) : ThemeStoreClient::checkUpdatesDetailed($pdo);
            return self::normalizeCollectionResult($result, $previous, $now);
        } catch (Throwable $error) {
            return array_replace($previous, ['state' => 'error', 'checked_at' => $now, 'error' => 'theme_check_failed']);
        }
    }

    private static function normalizeCollectionResult(mixed $result, array $previous, int $now): array
    {
        if (!is_array($result) || !is_array($result['updates'] ?? null)) {
            return array_replace($previous, ['state' => 'error', 'checked_at' => $now, 'error' => 'invalid_check_result']);
        }
        $state = in_array($result['state'] ?? '', ['ok', 'partial', 'error'], true) ? $result['state'] : 'error';
        return [
            'state' => $state,
            'checked_at' => $now,
            'updates' => $result['updates'],
            'error' => $state === 'ok' ? null : 'remote_check_incomplete',
        ];
    }

    private static function fetchCore(string $url, string $localVersion): ?array
    {
        $checkUrl = str_ends_with(strtolower((string)parse_url($url, PHP_URL_PATH)), '.json')
            ? $url
            : $url . (str_contains($url, '?') ? '&' : '?') . 'format=json';
        $context = stream_context_create(['http' => [
            'timeout' => 15,
            'user_agent' => 'JyavaniCMS-Update/' . $localVersion,
        ]]);
        $json = @file_get_contents($checkUrl, false, $context);
        if ($json === false) return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private static function localVersion(): string
    {
        $path = dirname(__DIR__, 2) . '/version.json';
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '0.0.0';
    }

    private static function emptySnapshot(): array
    {
        $version = self::localVersion();
        return [
            'schema' => self::SCHEMA,
            'checked_at' => 0,
            'expires_at' => 0,
            'state' => 'unknown',
            'total' => 0,
            'generation' => '',
            'components' => [
                'core' => ['state' => 'unknown', 'current' => $version, 'latest' => $version, 'has_update' => false, 'error' => null],
                'plugins' => ['state' => 'unknown', 'updates' => [], 'error' => null],
                'themes' => ['state' => 'unknown', 'updates' => [], 'error' => null],
            ],
        ];
    }

    private static function withSummary(array $snapshot): array
    {
        $core = $snapshot['components']['core'] ?? [];
        $plugins = $snapshot['components']['plugins']['updates'] ?? [];
        $themes = $snapshot['components']['themes']['updates'] ?? [];
        $snapshot['total'] = (($core['has_update'] ?? false) === true ? 1 : 0)
            + (is_array($plugins) ? count($plugins) : 0)
            + (is_array($themes) ? count($themes) : 0);
        $snapshot['generation'] = hash('sha256', json_encode([
            $core['current'] ?? '', $core['latest'] ?? '', array_keys(is_array($plugins) ? $plugins : []), array_keys(is_array($themes) ? $themes : []),
        ], JSON_UNESCAPED_SLASHES));
        return $snapshot;
    }

    private static function snapshotUpdateActionable(array $snapshot, string $component, string $name = '', ?string $targetVersion = null): bool
    {
        $freshAfter = time() - self::TTL;
        if ($component === 'core') {
            $update = $snapshot['components']['core'] ?? null;
            if (!is_array($update) || ($update['state'] ?? 'unknown') !== 'ok'
                || ($update['has_update'] ?? false) !== true || ($update['actionable'] ?? false) !== true
                || (int)($update['checked_at'] ?? 0) < $freshAfter) {
                return false;
            }
            return $targetVersion === null || version_compare((string)($update['latest'] ?? ''), $targetVersion, '==');
        }
        if (!in_array($component, ['plugins', 'themes'], true) || $name === ''
            || ($snapshot['components'][$component]['state'] ?? 'unknown') === 'error') {
            return false;
        }
        $update = $snapshot['components'][$component]['updates'][$name] ?? null;
        if (!is_array($update) || ($update['actionable'] ?? false) !== true
            || (int)($update['checked_at'] ?? 0) < $freshAfter) {
            return false;
        }
        return $targetVersion === null || version_compare((string)($update['new_version'] ?? ''), $targetVersion, '==');
    }

    private static function snapshotFile(): string
    {
        if (defined('UPDATE_STATUS_FILE')) return (string)UPDATE_STATUS_FILE;
        $backend = defined('BACKEND_PATH') ? BACKEND_PATH : dirname(__DIR__, 2) . '/cfg';
        return $backend . '/var/update-status.json';
    }

    private static function readSnapshot(): array
    {
        $file = self::snapshotFile();
        if (!is_file($file)) return self::emptySnapshot();
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA || !is_array($data['components'] ?? null)) {
            return self::emptySnapshot();
        }
        return array_replace_recursive(self::emptySnapshot(), $data);
    }

    private static function writeSnapshot(array $snapshot): void
    {
        $file = self::snapshotFile();
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !chmod($temporary, 0644) || !rename($temporary, $file)) {
            if (is_file($temporary)) unlink($temporary);
            throw new RuntimeException('Unable to persist the update status snapshot.');
        }
    }
}
