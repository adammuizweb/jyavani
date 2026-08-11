<?php
declare(strict_types=1);
if (defined('PLUGIN_SYSTEM_LOADED')) return;
define('PLUGIN_SYSTEM_LOADED', true);
// Plugin Registry — Jyavani CMS Plugin System v2.0
// Loaded after bootstrap in dashboard/index.php

if (!defined('PLUGIN_PATH')) define('PLUGIN_PATH', __DIR__);
if (!defined('PLUGIN_DISABLED_JSON')) define('PLUGIN_DISABLED_JSON', BACKEND_PATH . '/var/plugins-disabled.json');

// --- Frontend Route Registry ---
$GLOBALS['_plugin_frontend_routes'] = [];
$GLOBALS['_plugin_requirement_diagnostics'] = [];
$GLOBALS['_plugin_last_error'] = '';
$GLOBALS['_plugin_active_cache'] = null;
$GLOBALS['_plugin_load_diagnostics'] = [];
$GLOBALS['_plugin_loader_ran'] = false;

function plugin_message(string $message, mixed ...$values): string {
    $translated = function_exists('__') ? __($message) : $message;
    return $values === [] ? $translated : sprintf($translated, ...$values);
}

function plugin_current_jyavani_version(): string {
    static $version = null;
    if ($version !== null) return $version;
    $file = dirname(__DIR__) . '/VERSION';
    $version = is_file($file) ? trim((string)file_get_contents($file)) : '0.0.0';
    return preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) ? $version : '0.0.0';
}

function plugin_parse_version_constraint(string $requirement): ?array {
    $requirement = trim($requirement);
    if ($requirement === '') return [];
    $groups = preg_split('/\s*\|\|\s*/', $requirement);
    if (!is_array($groups) || in_array('', $groups, true)) return null;
    $parsed = [];
    foreach ($groups as $group) {
        $clauses = preg_split('/\s*,\s*|\s+(?=(?:\^|~|>=|<=|>|<|==|=)?\s*\d)/', trim($group));
        if (!is_array($clauses) || $clauses === [] || in_array('', $clauses, true)) return null;
        $parsedGroup = [];
        foreach ($clauses as $clause) {
            if (preg_match('/\A(\^|~|>=|<=|>|<|==|=)?\s*(\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?)\z/', $clause, $match) !== 1) return null;
            $parsedGroup[] = [$match[1] ?? '', $match[2]];
        }
        $parsed[] = $parsedGroup;
    }
    return $parsed;
}

function plugin_version_requirement_met(string $current, string $requirement): bool {
    if (preg_match('/\A\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?\z/', $current) !== 1) return false;
    $groups = plugin_parse_version_constraint($requirement);
    if ($groups === null) return false;
    if ($groups === []) return true;
    foreach ($groups as $clauses) {
        $passed = true;
        foreach ($clauses as [$operator, $version]) {
            if ($operator === '^' || $operator === '~') {
                $numbers = array_map('intval', explode('.', preg_split('/[-+]/', $version, 2)[0]));
                $numbers = array_pad($numbers, 3, 0);
                if ($operator === '^') {
                    if ($numbers[0] > 0) $upper = ($numbers[0] + 1) . '.0.0';
                    elseif ($numbers[1] > 0) $upper = '0.' . ($numbers[1] + 1) . '.0';
                    else $upper = '0.0.' . ($numbers[2] + 1);
                } else {
                    $upper = $numbers[0] . '.' . ($numbers[1] + 1) . '.0';
                }
                if (!version_compare($current, $version, '>=') || !version_compare($current, $upper, '<')) $passed = false;
            } else {
                $comparison = $operator === '' || $operator === '=' ? '>=' : $operator;
                if (!version_compare($current, $version, $comparison)) $passed = false;
            }
            if (!$passed) break;
        }
        if ($passed) return true;
    }
    return false;
}

function plugin_canonical_version_requirement(string $requirement): ?string {
    $groups = plugin_parse_version_constraint($requirement);
    if ($groups === null) return null;
    if ($groups === []) return '';
    $canonical = [];
    foreach ($groups as $clauses) {
        $values = [];
        foreach ($clauses as [$operator, $version]) {
            $values[] = ($operator === '' || $operator === '=' ? '>=' : $operator) . $version;
        }
        $canonical[] = implode(' ', $values);
    }
    return implode(' || ', $canonical);
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
    if (array_key_exists('plugins', $requires)) {
        $plugins = $requires['plugins'];
        if (!is_array($plugins) || ($plugins !== [] && array_is_list($plugins))) {
            $errors[] = plugin_message('Invalid plugin dependency declaration. Expected an object mapping plugin slugs to version constraints.');
        } else {
            foreach ($plugins as $slug => $constraint) {
                if (!is_string($slug) || preg_match('/\A[a-zA-Z0-9_-]+\z/', $slug) !== 1) {
                    $errors[] = plugin_message('Invalid plugin dependency name: %s.', (string)$slug);
                } elseif (!is_string($constraint) || trim($constraint) === '' || plugin_canonical_version_requirement($constraint) === null) {
                    $errors[] = plugin_message('Invalid version constraint for required plugin "%s".', $slug);
                }
            }
        }
    }
    return ['requires' => $requires, 'errors' => array_values(array_unique($errors))];
}

/** Store and package plugin dependency metadata must match exactly. */
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
    $catalogPlugins = is_array($catalogMeta['requires']['plugins'] ?? null) ? $catalogMeta['requires']['plugins'] : [];
    $packagePlugins = is_array($packageMeta['requires']['plugins'] ?? null) ? $packageMeta['requires']['plugins'] : [];
    foreach (array_unique(array_merge(array_keys($catalogPlugins), array_keys($packagePlugins))) as $slug) {
        if (!array_key_exists($slug, $catalogPlugins)) {
            $errors[] = plugin_message('Plugin dependency "%s" is declared by the package but missing from store metadata.', (string)$slug);
        } elseif (!array_key_exists($slug, $packagePlugins)) {
            $errors[] = plugin_message('Plugin dependency "%s" is declared by the store but missing from the package.', (string)$slug);
        } elseif (!is_string($catalogPlugins[$slug]) || !is_string($packagePlugins[$slug])
            || plugin_canonical_version_requirement($catalogPlugins[$slug]) !== plugin_canonical_version_requirement($packagePlugins[$slug])) {
            $errors[] = plugin_message('Plugin dependency "%s" has a package constraint that does not match store metadata.', (string)$slug);
        }
    }
    return array_values(array_unique($errors));
}

/** Return requirement checks in the same shape as plugin setup checks. */
function plugin_requirement_checks(array $manifest, bool $checkPluginState = true): array {
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
    if ($checkPluginState && is_array($requires['plugins'] ?? null)) {
        $all = plugins_all();
        $disabled = plugin_disabled_names();
        foreach ($requires['plugins'] as $slug => $constraint) {
            if (!is_string($slug) || preg_match('/\A[a-zA-Z0-9_-]+\z/', $slug) !== 1
                || !is_string($constraint) || plugin_canonical_version_requirement($constraint) === null) continue;
            $dependency = $all[$slug] ?? null;
            if (!is_array($dependency)) {
                $label = plugin_message('Required plugin "%s" is not installed.', $slug);
                $passed = false;
            } elseif (in_array($slug, $disabled, true)) {
                $label = plugin_message('Required plugin "%s" is inactive.', $slug);
                $passed = false;
            } else {
                $version = $dependency['version'] ?? null;
                $passed = is_string($version) && plugin_version_requirement_met($version, $constraint);
                $label = !is_string($version)
                    ? plugin_message('Required plugin "%s" has an invalid version.', $slug)
                    : plugin_message('Required plugin "%s" version %s does not satisfy %s.', $slug, $version, $constraint);
                if ($passed && !array_key_exists($slug, plugins_active())) {
                    $passed = false;
                    $label = plugin_message('Required plugin "%s" could not be loaded.', $slug);
                }
            }
            if (!$passed) $checks[] = ['label' => $label, 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
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
    return sprintf($template, rtrim(implode('; ', $errors), '.'));
}

function plugin_install_requirements_error_message(array $manifest, bool $activate): string {
    $errors = $activate
        ? plugin_requirement_errors($manifest)
        : plugin_requirement_errors_without_plugin_state($manifest);
    return plugin_requirements_error_message_from_errors($errors);
}

function plugin_requirement_diagnostics(): array {
    plugins_active();
    return $GLOBALS['_plugin_requirement_diagnostics'];
}

function plugin_load_diagnostics(): array {
    return $GLOBALS['_plugin_load_diagnostics'];
}

function plugin_disabled_names(): array {
    if (!is_file(PLUGIN_DISABLED_JSON)) return [];
    $disabled = json_decode((string)file_get_contents(PLUGIN_DISABLED_JSON), true);
    return is_array($disabled) ? array_values(array_filter($disabled, 'is_string')) : [];
}

function plugin_reset_runtime_cache(): void {
    $GLOBALS['_plugin_active_cache'] = null;
    $GLOBALS['_plugin_requirement_diagnostics'] = [];
    $GLOBALS['_plugin_load_diagnostics'] = [];
    $GLOBALS['_plugin_loader_ran'] = false;
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
    if ($GLOBALS['_plugin_loader_ran']) return;
    $GLOBALS['_plugin_loader_ran'] = true;
    $active = plugins_active();
    $loaded = [];
    $runtimeActive = [];
    foreach ($active as $name => $p) {
        $requires = plugin_normalize_requirements($p)['requires']['plugins'] ?? [];
        $unloadedDependencies = [];
        foreach (is_array($requires) ? array_keys($requires) : [] as $dependencyName) {
            if (!isset($loaded[$dependencyName])) $unloadedDependencies[] = $dependencyName;
        }
        if ($unloadedDependencies !== []) {
            $error = plugin_message(
                'Plugin "%s" was not loaded because these dependency entrypoints failed: %s.',
                $name,
                implode(', ', $unloadedDependencies)
            );
            $GLOBALS['_plugin_load_diagnostics'][$name] = $error;
            $GLOBALS['_plugin_requirement_diagnostics'][$name] = $error;
            error_log("[plugin-loader] Plugin '{$name}' skipped: {$error}");
            continue;
        }
        $mainFile = PLUGIN_PATH . '/' . $name . '/plugin.php';
        if (is_file($mainFile)) {
            $hooksBeforeLoad = $GLOBALS['_hooks'] ?? null;
            $routesBeforeLoad = $GLOBALS['_plugin_frontend_routes'];
            try {
                require_once $mainFile;
            } catch (\Throwable $e) {
                if (is_array($hooksBeforeLoad)) $GLOBALS['_hooks'] = $hooksBeforeLoad;
                $GLOBALS['_plugin_frontend_routes'] = $routesBeforeLoad;
                $error = plugin_message('Plugin "%s" entrypoint failed: %s.', $name, $e->getMessage());
                $GLOBALS['_plugin_load_diagnostics'][$name] = $error;
                $GLOBALS['_plugin_requirement_diagnostics'][$name] = $error;
                error_log("[plugin-loader] {$error}");
                continue;
            }
        }
        $loaded[$name] = true;
        $runtimeActive[$name] = $p;
    }
    $GLOBALS['_plugin_active_cache'] = $runtimeActive;
    do_action('plugins_loaded');
}

function plugin_manifest(string $name): ?array {
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) return null;
    $file = PLUGIN_PATH . '/' . $name . '/plugin.json';
    if (!is_file($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/** Resolve only the top-level image icon explicitly declared by plugin.json. */
function plugin_declared_icon_file(string $name, string $file): ?array {
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1 || $file === '' || str_contains($file, "\0")
        || str_contains($file, '\\') || basename($file) !== $file) return null;
    $manifest = plugin_manifest($name);
    $declared = is_array($manifest) && is_string($manifest['icon'] ?? null) ? $manifest['icon'] : '';
    if ($declared === '' || basename($declared) !== $declared || !hash_equals($declared, $file)) return null;
    $extension = strtolower((string)pathinfo($declared, PATHINFO_EXTENSION));
    $mimeTypes = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    if (!isset($mimeTypes[$extension])) return null;
    $pluginRoot = realpath(PLUGIN_PATH);
    $pluginDir = realpath(PLUGIN_PATH . '/' . $name);
    if ($pluginRoot === false || $pluginDir === false
        || ($pluginDir !== $pluginRoot && !str_starts_with($pluginDir, $pluginRoot . DIRECTORY_SEPARATOR))) return null;
    $path = plugin_safe_path($pluginDir, $declared);
    if ($path === null || !is_file($path) || is_link($path)) return null;
    return ['path' => $path, 'mime' => $mimeTypes[$extension]];
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

/** Execute only the conventional install.sh with bounded runtime and captured output. */
function plugin_run_install_script(string $pluginDir, ?int $timeoutSeconds = null, ?int $outputLimit = null): array {
    $root = realpath(PLUGIN_PATH);
    $directory = realpath($pluginDir);
    if ($root === false || $directory === false
        || ($directory !== $root && !str_starts_with($directory, $root . DIRECTORY_SEPARATOR))) {
        return ['success' => false, 'ran' => false, 'error' => plugin_message('Plugin install directory is invalid.'), 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    }
    $script = $directory . '/install.sh';
    if (!file_exists($script)) return ['success' => true, 'ran' => false, 'error' => '', 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    if (!is_file($script) || is_link($script)) {
        return ['success' => false, 'ran' => false, 'error' => plugin_message('Plugin install.sh is not a safe regular file.'), 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    }
    if (!function_exists('proc_open')) {
        return ['success' => false, 'ran' => false, 'error' => plugin_message('Plugin install.sh could not run because process execution is unavailable.'), 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    }
    if (!is_executable($script) && !@chmod($script, 0755)) {
        return ['success' => false, 'ran' => false, 'error' => plugin_message('Plugin install.sh is not executable.'), 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    }

    $timeoutEnvironment = getenv('PLUGIN_INSTALL_TIMEOUT_SECONDS');
    $limitEnvironment = getenv('PLUGIN_INSTALL_OUTPUT_LIMIT');
    $configuredTimeout = defined('PLUGIN_INSTALL_TIMEOUT_SECONDS')
        ? (int)PLUGIN_INSTALL_TIMEOUT_SECONDS
        : (is_string($timeoutEnvironment) && ctype_digit($timeoutEnvironment) ? (int)$timeoutEnvironment : 120);
    $configuredLimit = defined('PLUGIN_INSTALL_OUTPUT_LIMIT')
        ? (int)PLUGIN_INSTALL_OUTPUT_LIMIT
        : (is_string($limitEnvironment) && ctype_digit($limitEnvironment) ? (int)$limitEnvironment : 65536);
    $timeoutSeconds = max(1, min(900, $timeoutSeconds ?? $configuredTimeout));
    $outputLimit = max(1024, min(1048576, $outputLimit ?? $configuredLimit));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $setsid = null;
    foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $setsid = $candidate;
            break;
        }
    }
    $usesProcessGroup = $setsid !== null && function_exists('posix_kill');
    $command = $setsid !== null ? [$setsid, $script] : [$script];
    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes, $directory, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['success' => false, 'ran' => false, 'error' => plugin_message('Plugin install.sh process could not be started.'), 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $truncated = false;
    $timedOut = false;
    $exitCode = null;
    $initialStatus = proc_get_status($process);
    $processId = (int)($initialStatus['pid'] ?? 0);
    if ($processId <= 0) $usesProcessGroup = false;
    $deadline = microtime(true) + $timeoutSeconds;
    $capture = static function (string $chunk) use (&$output, &$truncated, $outputLimit): void {
        if ($chunk === '') return;
        $remaining = $outputLimit - strlen($output);
        if ($remaining > 0) $output .= substr($chunk, 0, $remaining);
        if (strlen($chunk) > max(0, $remaining)) $truncated = true;
    };

    while (true) {
        foreach ([1, 2] as $pipeIndex) {
            $chunk = stream_get_contents($pipes[$pipeIndex]);
            if (is_string($chunk)) $capture($chunk);
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int)$status['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            if ($usesProcessGroup) @posix_kill(-$processId, 15);
            else @proc_terminate($process);
            $graceDeadline = microtime(true) + 0.5;
            do {
                usleep(50000);
                foreach ([1, 2] as $pipeIndex) {
                    $chunk = stream_get_contents($pipes[$pipeIndex]);
                    if (is_string($chunk)) $capture($chunk);
                }
                $status = proc_get_status($process);
                $groupRunning = $usesProcessGroup && @posix_kill(-$processId, 0);
            } while (microtime(true) < $graceDeadline && ($status['running'] || $groupRunning));
            if ($usesProcessGroup) @posix_kill(-$processId, 9);
            elseif ($status['running']) @proc_terminate($process, 9);
            break;
        }
        usleep(50000);
    }
    foreach ([1, 2] as $pipeIndex) {
        $chunk = stream_get_contents($pipes[$pipeIndex]);
        if (is_string($chunk)) $capture($chunk);
        fclose($pipes[$pipeIndex]);
    }
    $closeCode = proc_close($process);
    if ($exitCode === null || $exitCode < 0) $exitCode = $closeCode;
    $detail = trim($output);
    if ($truncated) $detail .= ($detail === '' ? '' : "\n") . plugin_message('[installer output truncated]');

    if ($timedOut) {
        $error = plugin_message(
            'Plugin install.sh timed out after %s seconds. The process was stopped, but changes made by the script may remain.',
            (string)$timeoutSeconds
        );
        return ['success' => false, 'ran' => true, 'error' => $error . ($detail !== '' ? ' ' . $detail : ''), 'output' => $output, 'timed_out' => true, 'truncated' => $truncated, 'process_group' => $usesProcessGroup];
    }
    if ($exitCode !== 0) {
        $error = plugin_message(
            'Plugin install.sh failed with exit code %s. Changes made outside the plugin directory may remain.',
            (string)$exitCode
        );
        return ['success' => false, 'ran' => true, 'error' => $error . ($detail !== '' ? ' ' . $detail : ''), 'output' => $output, 'timed_out' => false, 'truncated' => $truncated, 'process_group' => $usesProcessGroup];
    }
    return ['success' => true, 'ran' => true, 'error' => '', 'output' => $output, 'timed_out' => false, 'truncated' => $truncated, 'process_group' => $usesProcessGroup];
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

/** Resolve enabled plugins into dependency-first order without loading invalid graph branches. */
function plugin_resolve_active_plugins(array $all, array $disabled): array {
    $ordered = [];
    $diagnostics = [];
    $state = [];
    $stack = [];
    $addDiagnostic = static function (string $name, string $message) use (&$diagnostics): void {
        if ($message === '') return;
        $diagnostics[$name] = isset($diagnostics[$name])
            ? $diagnostics[$name] . ' ' . $message
            : $message;
    };
    $visit = function (string $name) use (&$visit, &$ordered, &$diagnostics, &$state, &$stack, $all, $disabled, $addDiagnostic): bool {
        if (($state[$name] ?? 0) === 2) return isset($ordered[$name]);
        if (($state[$name] ?? 0) === 1) {
            $start = array_search($name, $stack, true);
            $cycle = array_slice($stack, $start === false ? 0 : $start);
            $cycle[] = $name;
            $message = plugin_message('Plugin dependency cycle detected: %s.', implode(' -> ', $cycle));
            foreach (array_unique($cycle) as $cycleName) $addDiagnostic($cycleName, $message);
            return false;
        }
        if (!isset($all[$name]) || in_array($name, $disabled, true)) return false;

        $state[$name] = 1;
        $stack[] = $name;
        $valid = true;
        $baseErrors = plugin_requirement_errors_without_plugin_state($all[$name]);
        if ($baseErrors !== []) {
            $addDiagnostic($name, plugin_requirements_error_message_from_errors($baseErrors));
            $valid = false;
        } else {
            $requires = plugin_normalize_requirements($all[$name])['requires']['plugins'] ?? [];
            foreach (is_array($requires) ? $requires : [] as $dependencyName => $constraint) {
                if (!is_string($dependencyName) || !is_string($constraint)) continue;
                if (!isset($all[$dependencyName])) {
                    $addDiagnostic($name, plugin_requirements_error_message_from_errors([
                        plugin_message('Required plugin "%s" is not installed.', $dependencyName),
                    ]));
                    $valid = false;
                    continue;
                }
                if (in_array($dependencyName, $disabled, true)) {
                    $addDiagnostic($name, plugin_requirements_error_message_from_errors([
                        plugin_message('Required plugin "%s" is inactive.', $dependencyName),
                    ]));
                    $valid = false;
                    continue;
                }
                $dependencyVersion = $all[$dependencyName]['version'] ?? null;
                if (!is_string($dependencyVersion) || !plugin_version_requirement_met($dependencyVersion, $constraint)) {
                    $error = !is_string($dependencyVersion)
                        ? plugin_message('Required plugin "%s" has an invalid version.', $dependencyName)
                        : plugin_message('Required plugin "%s" version %s does not satisfy %s.', $dependencyName, $dependencyVersion, $constraint);
                    $addDiagnostic($name, plugin_requirements_error_message_from_errors([$error]));
                    $valid = false;
                    continue;
                }
                if (!$visit($dependencyName)) {
                    $addDiagnostic($name, plugin_requirements_error_message_from_errors([
                        plugin_message('Required plugin "%s" could not be loaded.', $dependencyName),
                    ]));
                    $valid = false;
                }
            }
        }
        array_pop($stack);
        $state[$name] = 2;
        if ($valid && !isset($diagnostics[$name])) $ordered[$name] = $all[$name];
        return isset($ordered[$name]);
    };

    foreach ($all as $name => $_manifest) {
        if (!in_array($name, $disabled, true)) $visit($name);
    }
    return ['active' => $ordered, 'diagnostics' => $diagnostics];
}

/** Order a selected set for activation, or reverse it for removal/deactivation. */
function plugin_order_names_by_dependencies(array $names, bool $dependencyFirst = true): array {
    $selected = [];
    foreach ($names as $name) {
        if (is_string($name) && preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) === 1) $selected[$name] = true;
    }
    $all = plugins_all();
    $ordered = [];
    $state = [];
    $visit = function (string $name) use (&$visit, &$ordered, &$state, $selected, $all): void {
        if (($state[$name] ?? 0) !== 0) return;
        $state[$name] = 1;
        $requires = isset($all[$name]) ? (plugin_normalize_requirements($all[$name])['requires']['plugins'] ?? []) : [];
        foreach (is_array($requires) ? array_keys($requires) : [] as $dependencyName) {
            if (isset($selected[$dependencyName])) $visit($dependencyName);
        }
        $state[$name] = 2;
        $ordered[] = $name;
    };
    foreach (array_keys($selected) as $name) $visit($name);
    return $dependencyFirst ? $ordered : array_reverse($ordered);
}

function plugin_requirement_errors_without_plugin_state(array $manifest): array {
    return array_values(array_map(
        static fn(array $check): string => $check['label'],
        array_filter(plugin_requirement_checks($manifest, false), static fn(array $check): bool => !$check['passed'])
    ));
}

function plugin_requirements_error_message_from_errors(array $errors): string {
    if ($errors === []) return '';
    return plugin_message('Plugin requirements are not met: %s.', rtrim(implode('; ', $errors), '.'));
}

function plugins_active(): array {
    if (is_array($GLOBALS['_plugin_active_cache'])) return $GLOBALS['_plugin_active_cache'];
    $resolved = plugin_resolve_active_plugins(plugins_all(), plugin_disabled_names());
    $GLOBALS['_plugin_active_cache'] = $resolved['active'];
    $GLOBALS['_plugin_requirement_diagnostics'] = $resolved['diagnostics'];
    foreach ($resolved['diagnostics'] as $name => $error) error_log("[plugin-loader] Plugin '{$name}' skipped: {$error}");
    return $GLOBALS['_plugin_active_cache'];
}

// --- Plugin Manager helpers ---

function plugin_enable(string $name): bool {
    $manifest = plugin_manifest($name);
    if (!$manifest) {
        $GLOBALS['_plugin_last_error'] = plugin_message('Plugin manifest is invalid.');
        return false;
    }
    $disabled = plugin_disabled_names();
    $disabled = array_values(array_filter($disabled, fn($n) => $n !== $name));
    $resolved = plugin_resolve_active_plugins(plugins_all(), $disabled);
    if (!isset($resolved['active'][$name])) {
        $error = $resolved['diagnostics'][$name] ?? plugin_message('Plugin could not be activated because its dependencies are unavailable.');
        $GLOBALS['_plugin_last_error'] = $error;
        $GLOBALS['_plugin_requirement_diagnostics'][$name] = $error;
        return false;
    }
    $ok = file_put_contents(PLUGIN_DISABLED_JSON, json_encode($disabled), LOCK_EX) !== false;
    $GLOBALS['_plugin_last_error'] = $ok ? '' : plugin_message('Failed to update plugin state.');
    if ($ok) plugin_reset_runtime_cache();
    return $ok;
}

function plugin_active_dependents(string $name): array {
    $dependents = [];
    foreach (plugins_active() as $pluginName => $manifest) {
        if ($pluginName === $name) continue;
        $requires = plugin_normalize_requirements($manifest)['requires']['plugins'] ?? [];
        if (is_array($requires) && array_key_exists($name, $requires)) $dependents[] = $pluginName;
    }
    sort($dependents, SORT_STRING);
    return $dependents;
}

function plugin_replacement_dependency_errors(string $name, string $newVersion): array {
    if (preg_match('/\A\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?\z/', $newVersion) !== 1) {
        return [plugin_message('Plugin "%s" has an invalid replacement version.', $name)];
    }
    $errors = [];
    foreach (plugins_active() as $pluginName => $manifest) {
        if ($pluginName === $name) continue;
        $requires = plugin_normalize_requirements($manifest)['requires']['plugins'] ?? [];
        $constraint = is_array($requires) ? ($requires[$name] ?? null) : null;
        if (is_string($constraint) && !plugin_version_requirement_met($newVersion, $constraint)) {
            $errors[] = plugin_message(
                'Plugin "%s" requires %s %s, so %s cannot be installed.',
                $pluginName,
                $name,
                $constraint,
                $newVersion
            );
        }
    }
    return $errors;
}

function plugin_disable(string $name): bool {
    $dependents = plugin_active_dependents($name);
    if ($dependents !== []) {
        $GLOBALS['_plugin_last_error'] = plugin_message(
            'Plugin "%s" cannot be deactivated because these active plugins depend on it: %s.',
            $name,
            implode(', ', $dependents)
        );
        return false;
    }
    $disabled = plugin_disabled_names();
    if (!in_array($name, $disabled, true)) {
        $disabled[] = $name;
    }
    $ok = file_put_contents(PLUGIN_DISABLED_JSON, json_encode($disabled), LOCK_EX) !== false;
    $GLOBALS['_plugin_last_error'] = $ok ? '' : plugin_message('Failed to update plugin state.');
    if ($ok) plugin_reset_runtime_cache();
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

    $dependents = plugin_active_dependents($name);
    if ($dependents !== []) {
        $GLOBALS['_plugin_last_error'] = plugin_message(
            'Plugin "%s" cannot be uninstalled because these active plugins depend on it: %s.',
            $name,
            implode(', ', $dependents)
        );
        return false;
    }

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

    $dependents = plugin_active_dependents($name);
    if ($dependents !== []) {
        $GLOBALS['_plugin_last_error'] = plugin_message(
            'Plugin "%s" cannot be uninstalled because these active plugins depend on it: %s.',
            $name,
            implode(', ', $dependents)
        );
        return false;
    }

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
        $GLOBALS['_plugin_last_error'] = plugin_message('Failed to uninstall plugin.') . ' ' . implode('; ', $errors);
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
        $disabled = plugin_disabled_names();
        $disabled = array_values(array_filter($disabled, fn($n) => $n !== $name));
        file_put_contents(PLUGIN_DISABLED_JSON, json_encode($disabled), LOCK_EX);
    }
    $ok = empty($errors);
    $GLOBALS['_plugin_last_error'] = $ok ? '' : plugin_message('Failed to uninstall plugin.') . ' ' . implode('; ', $errors);
    if ($ok) plugin_reset_runtime_cache();
    return $ok;
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
