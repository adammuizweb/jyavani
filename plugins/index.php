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

function plugin_static_copy(string $pluginDir, array $entries): array {
    $public = defined('PUBLIC_PATH') ? PUBLIC_PATH : dirname(PLUGIN_PATH) . '/public';
    $copied = $failed = 0;
    foreach ($entries as $entry) {
        $source = plugin_safe_path($pluginDir, (string)($entry['from'] ?? ''));
        $dest = plugin_static_path(basename($pluginDir), (string)($entry['to'] ?? ''));
        if (!$source || !$dest || !is_file($source) || is_link($source)) { $failed++; continue; }
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) { $failed++; continue; }
        if (is_link($dest) || !@copy($source, $dest)) { $failed++; continue; }
        $copied++;
    }
    return compact('copied', 'failed');
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
        if (is_array($data) && !empty($data['name'])) {
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
        if (!in_array($name, $disabled, true)) {
            $cache[$name] = $p;
        }
    }
    return $cache;
}

// --- Plugin Manager helpers ---

function plugin_enable(string $name): bool {
    $disabled = [];
    if (is_file(PLUGIN_DISABLED_JSON)) {
        $disabled = json_decode(file_get_contents(PLUGIN_DISABLED_JSON), true) ?? [];
    }
    $disabled = array_values(array_filter($disabled, fn($n) => $n !== $name));
    return file_put_contents(PLUGIN_DISABLED_JSON, json_encode($disabled), LOCK_EX) !== false;
}

function plugin_disable(string $name): bool {
    $disabled = [];
    if (is_file(PLUGIN_DISABLED_JSON)) {
        $disabled = json_decode(file_get_contents(PLUGIN_DISABLED_JSON), true) ?? [];
    }
    if (!in_array($name, $disabled, true)) {
        $disabled[] = $name;
    }
    return file_put_contents(PLUGIN_DISABLED_JSON, json_encode($disabled), LOCK_EX) !== false;
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
    foreach (plugins_active() as $name => $p) {
        $a = $p['assets'] ?? [];
        foreach (['css', 'js'] as $type) {
            foreach ($a[$type] ?? [] as $url) {
                $assets[$type][] = $url;
            }
        }
    }
    return $assets;
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
        $publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : (dirname(PLUGIN_PATH) . '/public');
        foreach ($manifest['static']['copy'] as $entry) {
            $dest = $entry['to'] ?? $entry['dest'] ?? '';
            if ($dest !== '') {
                $abs = plugin_static_path($name, (string)$dest);
                if ($abs === null) continue;
                if (is_file($abs) && !@unlink($abs)) {
                    $errors[] = 'Failed to remove ' . $dest;
                }
                // Remove empty parent dirs
                $parent = dirname($abs);
                if (is_dir($parent) && count(scandir($parent)) <= 2) {
                    @rmdir($parent);
                }
            }
        }
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
    if (!$manifest || !isset($manifest['setup']['checks'])) return [];

    $results = [];
    $pluginDir = PLUGIN_PATH . '/' . $name;
    foreach ($manifest['setup']['checks'] as $i => $check) {
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
