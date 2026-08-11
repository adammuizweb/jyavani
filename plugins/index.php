<?php
declare(strict_types=1);
if (defined('PLUGIN_SYSTEM_LOADED')) return;
define('PLUGIN_SYSTEM_LOADED', true);
// Plugin Registry — Jyavani CMS Plugin System v2.0
// Loaded after bootstrap in dashboard/index.php

define('PLUGIN_PATH', __DIR__);
define('PLUGIN_DISABLED_JSON', BACKEND_PATH . '/var/plugins-disabled.json');

// --- Frontend Route Registry ---
$GLOBALS['_plugin_frontend_routes'] = [];
$GLOBALS['_plugin_requirement_diagnostics'] = [];
$GLOBALS['_plugin_last_error'] = '';

function plugin_current_jyavani_version(): string {
    static $version = null;
    if ($version !== null) return $version;
    $file = dirname(__DIR__) . '/VERSION';
    $version = is_file($file) ? trim((string)file_get_contents($file)) : '0.0.0';
    return preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) ? $version : '0.0.0';
}

function plugin_version_requirement_met(string $current, string $requirement): bool {
    $requirement = trim($requirement);
    if ($requirement === '') return true;
    if (preg_match('/\A(>=|<=|>|<|==|=)?\s*(\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?)\z/', $requirement, $match) !== 1) return false;
    return version_compare($current, $match[2], $match[1] === '' || $match[1] === '=' ? '>=' : $match[1]);
}

function plugin_canonical_version_requirement(string $requirement): ?string {
    $requirement = trim($requirement);
    if (preg_match('/\A(>=|<=|>|<|==|=)?\s*(\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?)\z/', $requirement, $match) !== 1) return null;
    $operator = $match[1] === '' || $match[1] === '=' ? '>=' : $match[1];
    return $operator . $match[2];
}

/** Normalize canonical and legacy top-level requirement metadata. */
function plugin_normalize_requirements(array $manifest): array {
    $errors = [];
    $value = $manifest['requires'] ?? [];
    $requires = is_array($value) ? $value : [];
    if (array_key_exists('requires', $manifest) && !is_array($value)) $errors[] = 'Invalid plugin requirements declaration';

    foreach (['jyavani_required' => 'jyavani', 'php_required' => 'php'] as $legacy => $canonical) {
        if (!array_key_exists($legacy, $manifest)) continue;
        $legacyValue = $manifest[$legacy];
        if (!is_string($legacyValue)) {
            $errors[] = 'Invalid ' . ($canonical === 'php' ? 'PHP' : 'Jyavani') . ' requirement';
            continue;
        }
        $legacyValue = trim($legacyValue);
        $canonicalValue = is_string($requires[$canonical] ?? null) ? plugin_canonical_version_requirement($requires[$canonical]) : null;
        $legacyCanonical = plugin_canonical_version_requirement($legacyValue);
        if (isset($requires[$canonical]) && ($canonicalValue === null || $legacyCanonical === null || $canonicalValue !== $legacyCanonical)) {
            $errors[] = 'Conflicting ' . ($canonical === 'php' ? 'PHP' : 'Jyavani') . ' requirements';
        } elseif (!isset($requires[$canonical]) && $legacyValue !== '') {
            $requires[$canonical] = $legacyValue;
        }
    }
    if (!array_key_exists('extensions', $requires) && array_key_exists('extensions_required', $manifest)) {
        $requires['extensions'] = $manifest['extensions_required'];
    }
    return ['requires' => $requires, 'errors' => array_values(array_unique($errors))];
}

/** Store packages must carry every advertised requirement unchanged. */
function plugin_package_requirement_errors(array $catalog, array $package): array {
    $catalogMeta = plugin_normalize_requirements($catalog);
    $packageMeta = plugin_normalize_requirements($package);
    $errors = array_merge($catalogMeta['errors'], $packageMeta['errors']);
    foreach (['jyavani', 'php'] as $key) {
        $advertised = $catalogMeta['requires'][$key] ?? null;
        $declared = $packageMeta['requires'][$key] ?? null;
        if ($advertised !== null && $advertised !== '' && (!is_string($declared)
            || plugin_canonical_version_requirement((string)$advertised) !== plugin_canonical_version_requirement($declared))) {
            $errors[] = 'Package ' . $key . ' requirement does not match the store catalog';
        }
    }
    $catalogExtensions = $catalogMeta['requires']['extensions'] ?? [];
    $packageExtensions = $packageMeta['requires']['extensions'] ?? [];
    if (is_string($catalogExtensions)) $catalogExtensions = array_filter(array_map('trim', explode(',', $catalogExtensions)));
    if (is_string($packageExtensions)) $packageExtensions = array_filter(array_map('trim', explode(',', $packageExtensions)));
    if (is_array($catalogExtensions) && $catalogExtensions !== []) {
        $normalize = static function (array $extensions): array {
            $result = [];
            foreach ($extensions as $key => $value) $result[(string)(is_int($key) ? $value : $key)] = is_int($key) ? '' : (string)$value;
            ksort($result, SORT_STRING);
            return $result;
        };
        $advertised = $normalize($catalogExtensions);
        $declared = is_array($packageExtensions) ? $normalize($packageExtensions) : [];
        foreach ($advertised as $extension => $minimum) {
            if (!array_key_exists($extension, $declared) || $declared[$extension] !== $minimum) {
                $errors[] = 'Package extension requirements do not match the store catalog';
                break;
            }
        }
    }
    return array_values(array_unique($errors));
}

/** Return requirement checks in the same shape as plugin setup checks. */
function plugin_requirement_checks(array $manifest): array {
    $metadata = plugin_normalize_requirements($manifest);
    $requires = $metadata['requires'];
    $checks = [];
    foreach ($metadata['errors'] as $error) $checks[] = ['label' => $error, 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
    $jyavani = is_string($requires['jyavani'] ?? null) ? trim($requires['jyavani']) : '';
    if (array_key_exists('jyavani', $requires) && !is_string($requires['jyavani'])) {
        $checks[] = ['label' => 'Invalid Jyavani requirement', 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
    }
    if ($jyavani !== '') {
        $checks[] = [
            'label' => 'Jyavani ' . $jyavani . ' (installed: ' . plugin_current_jyavani_version() . ')',
            'passed' => plugin_version_requirement_met(plugin_current_jyavani_version(), $jyavani),
            'command' => '', 'doc' => '', 'raw_output' => '',
        ];
    }
    $php = is_string($requires['php'] ?? null) ? trim($requires['php']) : '';
    if (array_key_exists('php', $requires) && !is_string($requires['php'])) {
        $checks[] = ['label' => 'Invalid PHP requirement', 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
    }
    if ($php !== '') {
        $checks[] = [
            'label' => 'PHP ' . $php . ' (installed: ' . PHP_VERSION . ')',
            'passed' => plugin_version_requirement_met(PHP_VERSION, $php),
            'command' => '', 'doc' => '', 'raw_output' => '',
        ];
    }
    $extensions = $requires['extensions'] ?? [];
    if (is_string($extensions)) $extensions = array_filter(array_map('trim', explode(',', $extensions)));
    if (!is_array($extensions)) {
        $checks[] = ['label' => 'Invalid PHP extension requirements', 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
        $extensions = [];
    }
    if (is_array($extensions)) {
        foreach ($extensions as $key => $value) {
            $extension = is_int($key) ? $value : $key;
            $minimum = is_int($key) ? '' : $value;
            if (!is_string($extension) || preg_match('/\A[a-zA-Z0-9_-]+\z/', $extension) !== 1) {
                $checks[] = ['label' => 'Invalid PHP extension requirement', 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
                continue;
            }
            $loaded = extension_loaded($extension);
            $passed = $loaded;
            if ($loaded && is_string($minimum) && trim($minimum) !== '') {
                $extensionVersion = phpversion($extension);
                $passed = is_string($extensionVersion) && plugin_version_requirement_met($extensionVersion, $minimum);
            }
            $checks[] = [
                'label' => 'PHP extension: ' . $extension . (is_string($minimum) && $minimum !== '' ? ' ' . $minimum : ''),
                'passed' => $passed, 'command' => '', 'doc' => '', 'raw_output' => '',
            ];
        }
    }
    return $checks;
}

function plugin_requirement_errors(array $manifest): array {
    return array_values(array_map(
        static fn(array $check): string => $check['label'],
        array_filter(plugin_requirement_checks($manifest), static fn(array $check): bool => !$check['passed'])
    ));
}

function plugin_requirements_error_message(array $manifest): string {
    $errors = plugin_requirement_errors($manifest);
    if ($errors === []) return '';
    $template = function_exists('__') ? __('Plugin requirements are not met: %s.') : 'Plugin requirements are not met: %s.';
    return sprintf($template, implode('; ', $errors));
}

function plugin_requirement_diagnostics(): array {
    plugins_active();
    return $GLOBALS['_plugin_requirement_diagnostics'];
}

function plugin_last_error(): string {
    return (string)($GLOBALS['_plugin_last_error'] ?? '');
}

function register_frontend_route(string $prefix, callable|string $handler): void {
    $GLOBALS['_plugin_frontend_routes'][$prefix] = $handler;
}

function get_frontend_routes(): array {
    return $GLOBALS['_plugin_frontend_routes'];
}

function match_frontend_route(string $prefix): callable|string|null {
    return $GLOBALS['_plugin_frontend_routes'][$prefix] ?? null;
}

// --- Plugin auto-loader: require plugin.php for each active plugin ---
function plugin_load_active(): void {
    $active = plugins_active();
    foreach ($active as $name => $p) {
        $mainFile = PLUGIN_PATH . '/' . $name . '/plugin.php';
        if (is_file($mainFile)) {
            try { @// suppress warnings for corrupt plugins
                require_once $mainFile;
            } catch (\Throwable $e) {
                error_log("[plugin-loader] Failed to load plugin '{$name}': {$e->getMessage()}");
                // Skip corrupt plugin, continue loading others
                continue;
            }
        }
    }
    do_action('plugins_loaded');
}

function plugin_manifest(string $name): ?array {
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) return null;
    $file = PLUGIN_PATH . '/' . $name . '/plugin.json';
    if (!is_file($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function plugin_safe_path(string $root, string $relative): ?string {
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative)) return null;
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

function plugin_sync_directory(string $directory): void {
    if (!function_exists('fsync')) return;
    $handle = @fopen($directory, 'rb');
    if (!is_resource($handle)) return;
    @fsync($handle);
    fclose($handle);
}

function plugin_static_copy(string $pluginDir, array $entries, array $oldEntries = []): array {
    $errors = [];
    $pluginName = basename($pluginDir);
    $validated = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) { $errors[] = 'Invalid static.copy entry.'; continue; }
        $from = is_string($entry['from'] ?? null) ? $entry['from'] : '';
        $to = is_string($entry['to'] ?? null) ? $entry['to'] : '';
        $source = plugin_safe_path($pluginDir, $from);
        $dest = plugin_static_path($pluginName, $to);
        if (!$source || !$dest || !is_file($source) || is_link($source) || is_link($dest)
            || (file_exists($dest) && !is_file($dest))) {
            $errors[] = 'Invalid static copy: ' . ($to !== '' ? $to : $from);
        } elseif (isset($validated[$dest])) {
            $errors[] = 'Duplicate static copy destination: ' . $to;
        } else {
            $validated[$dest] = ['source' => $source, 'dest' => $dest];
        }
    }
    $oldDestinations = [];
    foreach ($oldEntries as $entry) {
        $to = is_array($entry) && is_string($entry['to'] ?? null) ? $entry['to'] : '';
        $dest = plugin_static_path($pluginName, $to);
        if (!$dest || is_link($dest)) $errors[] = 'Invalid previous static copy: ' . $to;
        else $oldDestinations[$dest] = true;
    }
    if ($errors !== []) return ['copied' => 0, 'removed' => 0, 'failed' => count($errors), 'rollback_incomplete' => false, 'errors' => $errors];

    $changes = [];
    $copied = 0;
    $removed = 0;
    try {
        foreach ($validated as $item) {
            $destDir = dirname($item['dest']);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) throw new RuntimeException('Could not create static directory.');
            if (is_link($destDir) || is_link($item['dest'])) throw new RuntimeException('Static destination is a symlink.');
            $token = bin2hex(random_bytes(8));
            $temporary = $destDir . '/.plugin-copy-' . $token . '.tmp';
            $backup = $destDir . '/.plugin-copy-' . $token . '.bak';
            $input = @fopen($item['source'], 'rb');
            $output = @fopen($temporary, 'x+b');
            if (!$input || !$output || stream_copy_to_stream($input, $output) === false || !fflush($output)
                || (function_exists('fsync') && !fsync($output))) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                @unlink($temporary);
                throw new RuntimeException('Could not copy declared static file.');
            }
            fclose($input);
            fclose($output);
            @chmod($temporary, 0644);
            $hadExisting = is_file($item['dest']);
            $backupHash = $hadExisting ? hash_file('sha256', $item['dest']) : null;
            if ($hadExisting && !rename($item['dest'], $backup)) {
                @unlink($temporary);
                throw new RuntimeException('Could not back up existing static file.');
            }
            $changes[] = ['action' => 'publish', 'dest' => $item['dest'], 'backup' => $hadExisting ? $backup : null, 'backup_hash' => $backupHash];
            if (!rename($temporary, $item['dest'])) {
                @unlink($temporary);
                throw new RuntimeException('Could not publish declared static file.');
            }
            plugin_sync_directory($destDir);
            $copied++;
        }
        foreach (array_diff_key($oldDestinations, $validated) as $dest => $_unused) {
            if (!is_file($dest)) continue;
            $backup = dirname($dest) . '/.plugin-copy-' . bin2hex(random_bytes(8)) . '.bak';
            $backupHash = hash_file('sha256', $dest);
            if (!rename($dest, $backup)) throw new RuntimeException('Could not stage obsolete static file removal.');
            plugin_sync_directory(dirname($dest));
            $changes[] = ['action' => 'remove', 'dest' => $dest, 'backup' => $backup, 'backup_hash' => $backupHash];
            $removed++;
        }
        foreach ($changes as $change) {
            if ($change['backup'] !== null && is_file($change['backup']) && !@unlink($change['backup'])) {
                error_log('plugin static backup cleanup failed: ' . $change['backup']);
            }
        }
        return ['copied' => $copied, 'removed' => $removed, 'failed' => 0, 'rollback_incomplete' => false, 'errors' => []];
    } catch (Throwable $error) {
        $rollbackErrors = [];
        foreach (array_reverse($changes) as $change) {
            if ($change['action'] === 'publish' && (file_exists($change['dest']) || is_link($change['dest']))) {
                if (!@unlink($change['dest']) || file_exists($change['dest']) || is_link($change['dest'])) $rollbackErrors[] = 'Could not remove replacement ' . $change['dest'];
            }
            if ($change['backup'] !== null && is_file($change['backup'])) {
                $restored = @rename($change['backup'], $change['dest']);
                $restoredHash = $restored && is_file($change['dest']) ? hash_file('sha256', $change['dest']) : false;
                if (!$restored || !is_string($restoredHash) || !is_string($change['backup_hash'])
                    || !hash_equals($change['backup_hash'], $restoredHash)) {
                    $rollbackErrors[] = 'Could not verify restored content for ' . $change['dest'];
                } else {
                    plugin_sync_directory(dirname($change['dest']));
                }
            } elseif ($change['action'] === 'publish' && (file_exists($change['dest']) || is_link($change['dest']))) {
                $rollbackErrors[] = 'New destination remains after rollback ' . $change['dest'];
            }
        }
        foreach ($rollbackErrors as $rollbackError) error_log('plugin static copy rollback failed: ' . $rollbackError);
        $errors[] = $error->getMessage();
        if ($rollbackErrors !== []) $errors[] = 'Static asset rollback was incomplete: ' . implode('; ', $rollbackErrors);
        return ['copied' => 0, 'removed' => 0, 'failed' => max(1, count($entries)), 'rollback_incomplete' => $rollbackErrors !== [], 'errors' => $errors];
    }
}

function plugin_static_path(string $name, string $relative): ?string {
    $prefix = 'static/plugins/' . $name . '/';
    if (!str_starts_with($relative, $prefix)) return null;
    $public = defined('PUBLIC_PATH') ? PUBLIC_PATH : dirname(PLUGIN_PATH) . '/public';
    return plugin_safe_path($public, $relative);
}

function plugins_all(): array {
    $plugins = [];
    foreach (glob(PLUGIN_PATH . '/*/plugin.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        $folder = basename(dirname($file));
        if (is_array($data) && ($data['name'] ?? null) === $folder && preg_match('/\A[a-zA-Z0-9_-]+\z/', $folder) === 1) {
            $plugins[$data['name']] = $data;
        }
    }
    return $plugins;
}

function plugins_active(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $all = plugins_all();
    $disabled = [];
    if (is_file(PLUGIN_DISABLED_JSON)) {
        $d = json_decode(file_get_contents(PLUGIN_DISABLED_JSON), true);
        if (is_array($d)) $disabled = $d;
    }
    foreach ($all as $name => $p) {
        $requirementError = plugin_requirements_error_message($p);
        if ($requirementError !== '') {
            $GLOBALS['_plugin_requirement_diagnostics'][$name] = $requirementError;
            error_log("[plugin-loader] Plugin '{$name}' skipped: {$requirementError}");
        } elseif (!in_array($name, $disabled, true)) {
            $cache[$name] = $p;
        }
    }
    return $cache;
}

// --- Plugin Manager helpers ---

function plugin_enable(string $name): bool {
    $manifest = plugin_manifest($name);
    $error = $manifest ? plugin_requirements_error_message($manifest) : 'Plugin manifest is invalid.';
    if ($error !== '') {
        $GLOBALS['_plugin_last_error'] = $error;
        $GLOBALS['_plugin_requirement_diagnostics'][$name] = $error;
        return false;
    }
    $disabled = [];
    if (is_file(PLUGIN_DISABLED_JSON)) {
        $decoded = json_decode((string)file_get_contents(PLUGIN_DISABLED_JSON), true);
        if (is_array($decoded)) $disabled = $decoded;
    }
    $disabled = array_values(array_filter($disabled, fn($n) => $n !== $name));
    $ok = file_put_contents(PLUGIN_DISABLED_JSON, json_encode($disabled), LOCK_EX) !== false;
    $GLOBALS['_plugin_last_error'] = $ok ? '' : 'Failed to update plugin state.';
    return $ok;
}

function plugin_disable(string $name): bool {
    $disabled = [];
    if (is_file(PLUGIN_DISABLED_JSON)) {
        $decoded = json_decode((string)file_get_contents(PLUGIN_DISABLED_JSON), true);
        if (is_array($decoded)) $disabled = $decoded;
    }
    if (!in_array($name, $disabled, true)) {
        $disabled[] = $name;
    }
    $ok = file_put_contents(PLUGIN_DISABLED_JSON, json_encode($disabled), LOCK_EX) !== false;
    $GLOBALS['_plugin_last_error'] = $ok ? '' : 'Failed to update plugin state.';
    return $ok;
}

function plugin_is_active(string $name): bool {
    return array_key_exists($name, plugins_active());
}

// --- Route / Nav / Asset aggregation ---

function plugin_admin_routes(): array {
    $routes = [];
    foreach (plugins_active() as $name => $p) {
        $pages = $p['admin']['pages'] ?? [];
        $base = PLUGIN_PATH . '/' . $name;
        foreach ($pages as $r) {
            $route = $r['route'] ?? '';
            if ($route === '') continue;
            $routes[$route] = [
                'file' => $base . '/' . ($r['file'] ?? ''),
                'title' => $r['title'] ?? $route,
                'roles' => $r['roles'] ?? ['admin'],
                'hidden' => $r['hidden'] ?? false,
                'plugin' => $name,
            ];
        }
    }
    return $routes;
}

function plugin_nav_items(): array {
    $items = [];
    foreach (plugins_active() as $name => $p) {
        $nav = $p['admin']['nav'] ?? [];
        foreach ($nav as $n) {
            $n['plugin'] = $name;
            $items[] = $n;
        }
    }
    return $items;
}

function plugin_assets(): array {
    $assets = ['css' => [], 'js' => []];
    $coreDependencies = [
        'modal-helpers' => '/static/js/add/modal-helpers.js',
        'media-selector' => '/static/js/add/media-selector.js',
        'file-selector' => '/static/js/add/file-selector.js',
    ];
    foreach (plugins_active() as $name => $p) {
        $a = $p['assets'] ?? [];
        foreach (['css', 'js'] as $type) {
            foreach ($a[$type] ?? [] as $url) {
                $assets[$type][] = $url;
            }
        }
        foreach ($p['dependencies']['js'] ?? [] as $dependency) {
            if (is_string($dependency) && isset($coreDependencies[$dependency])) {
                $assets['js'][] = $coreDependencies[$dependency];
            }
        }
    }
    return ['css' => array_values(array_unique($assets['css'])), 'js' => array_values(array_unique($assets['js']))];
}

function plugin_resolve_route(string $route): ?array {
    $routes = plugin_admin_routes();
    return $routes[$route] ?? null;
}

/**
 * Enforce role-based access for a plugin admin route.
 * Fires the `plugin_page_roles` filter so plugins/themes can mutate the
 * required roles before the hardcoded guard is applied.
 */
function plugin_guard_route(PDO $pdo, array $route, bool $asJson = false): void {
    $roles = $route['roles'] ?? ['admin'];
    $roles = apply_filters('plugin_page_roles', $roles, $route);

    if (function_exists('adiwira_require_role')) {
        adiwira_require_role($pdo, $roles, $asJson);
    }
}

function plugin_include_file(string $file): bool {
    if (is_file($file) && is_readable($file)) {
        require $file;
        return true;
    }
    return false;
}

// --- HTML escape helper (used by plugin admin pages) ---
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

// --- Uninstall plugin with data-keep option ---
function plugin_uninstall(string $name, bool $keepData = true): bool {
    $pluginDir = PLUGIN_PATH . '/' . $name;
    if (!is_dir($pluginDir)) return false;

    // Fire uninstall hook for data cleanup (only if NOT keeping data)
    // Try-catch: jika plugin corrupt, hook mungkin tidak ter-register — skip saja
    if (!$keepData) {
        try {
            do_action('plugin_uninstall', $name);
        } catch (\Throwable $e) {
            error_log("[plugin] Uninstall hook failed for '{$name}': {$e->getMessage()}");
        }
    }

    // Delegate file deletion
    return plugin_delete($name);
}

// --- Delete plugin from disk ---
function plugin_delete(string $name): bool {
    $pluginDir = PLUGIN_PATH . '/' . $name;
    if (!is_dir($pluginDir)) return false;

    // Load manifest for static.copy paths
    $manifest = plugin_manifest($name);
    $errors = [];

    // Remove static.copy files first
    if ($manifest && isset($manifest['static']['copy'])) {
        $staticEntries = $manifest['static']['copy'];
        if (!is_array($staticEntries)) {
            $errors[] = 'Invalid static.copy declaration';
        } else {
            $destinations = [];
            foreach ($staticEntries as $entry) {
                $dest = is_array($entry) && is_string($entry['to'] ?? null) ? $entry['to'] : '';
                $abs = plugin_static_path($name, $dest);
                if (!$abs || is_link($abs)) {
                    $errors[] = 'Invalid static destination ' . $dest;
                } else {
                    $destinations[$abs] = $dest;
                }
            }
            if ($errors === []) {
                foreach ($destinations as $abs => $dest) {
                    if (is_file($abs) && (!@unlink($abs) || file_exists($abs))) $errors[] = 'Failed to remove ' . $dest;
                    $parent = dirname($abs);
                    if (is_dir($parent) && count(scandir($parent)) <= 2) @rmdir($parent);
                }
            }
        }
    }
    if ($errors !== []) {
        foreach ($errors as $error) error_log("[plugin_delete] {$error}");
        return false;
    }

    // Remove plugin directory recursively
    // Try PHP-based deletion first (may fail if permissions are restrictive)
    $phpDeleted = false;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $path = $f->getPathname();
            @chmod($path, $f->isDir() ? 0777 : 0666);
            if ($f->isLink() || !$f->isDir()) {
                @unlink($path);
            } else {
                @rmdir($path);
            }
        }
        @chmod($pluginDir, 0777);
        if (@rmdir($pluginDir)) {
            $phpDeleted = true;
        }
    } catch (\Throwable $e) {
        error_log("[plugin_delete] PHP deletion failed for '{$name}': {$e->getMessage()}");
    }

    // Fallback: CLI rm -rf if PHP deletion failed
    if (!$phpDeleted && is_dir($pluginDir)) {
        $escaped = escapeshellarg($pluginDir);
        $output = [];
        $rc = 0;
        exec("rm -rf {$escaped} 2>&1", $output, $rc);
        if ($rc !== 0 || is_dir($pluginDir)) {
            $errors[] = 'Failed to remove plugin directory (permission denied)';
            error_log("[plugin_delete] CLI rm -rf failed for '{$name}': " . implode("\n", $output));
        } else {
            $phpDeleted = true;
        }
    }

    if (!$phpDeleted) {
        $errors[] = 'Failed to remove plugin directory';
    }

    // Clean up disabled state
    if (is_file(PLUGIN_DISABLED_JSON)) {
        $disabled = json_decode(file_get_contents(PLUGIN_DISABLED_JSON), true) ?? [];
        $disabled = array_values(array_filter($disabled, fn($n) => $n !== $name));
        file_put_contents(PLUGIN_DISABLED_JSON, json_encode($disabled), LOCK_EX);
    }

    return empty($errors);
}

// --- Setup checks (for plugin detail page) ---
function plugin_checks(string $name): array {
    $manifest = plugin_manifest($name);
    if (!$manifest) return [];

    $results = plugin_requirement_checks($manifest);
    $pluginDir = PLUGIN_PATH . '/' . $name;
    foreach (($manifest['setup']['checks'] ?? []) as $i => $check) {
        $label = $check['label'] ?? 'Check ' . ($i + 1);
        $tip = $check['doc'] ?? '';
        $type = (string)($check['type'] ?? '');
        $path = plugin_safe_path($pluginDir, (string)($check['path'] ?? ''));
        $passed = match ($type) {
            'php_extension' => extension_loaded((string)($check['extension'] ?? '')),
            'file_exists' => $path !== null && is_file($path),
            'file_readable' => $path !== null && is_file($path) && is_readable($path),
            'file_writable' => $path !== null && is_file($path) && is_writable($path),
            'directory_exists' => $path !== null && is_dir($path),
            'directory_writable' => $path !== null && is_dir($path) && is_writable($path),
            default => false,
        };

        $results[] = [
            'label' => $label,
            'passed' => $passed,
            'command' => '',
            'doc' => $tip,
            'raw_output' => '',
        ];
    }

    return $results;
}
