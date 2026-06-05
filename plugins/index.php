<?php
declare(strict_types=1);
if (defined('PLUGIN_SYSTEM_LOADED')) return;
define('PLUGIN_SYSTEM_LOADED', true);
// Plugin Registry — Jyavani CMS Plugin System v2.0
// Loaded after bootstrap in dashboard/index.php

define('PLUGIN_PATH', __DIR__);
define('PLUGIN_DISABLED_JSON', defined('BACKEND_PATH') ? BACKEND_PATH . '/var/plugins-disabled.json' : PLUGIN_PATH . '/disabled.json');

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
            require_once $mainFile;
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

// --- Delete plugin from disk ---
function plugin_delete(string $name): bool {
    $pluginDir = PLUGIN_PATH . '/' . $name;
    if (!is_dir($pluginDir)) return false;

    // Load manifest for static.copy paths
    $manifest = plugin_manifest($name);
    $errors = [];

    // Remove static.copy files first
    if ($manifest && isset($manifest['static']['copy'])) {
        foreach ($manifest['static']['copy'] as $entry) {
            $dest = $entry['to'] ?? $entry['dest'] ?? '';
            if ($dest !== '') {
                $abs = (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(PLUGIN_PATH)) . '/' . ltrim($dest, '/');
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
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $path = $f->getPathname();
        if ($f->isLink() || !$f->isDir()) {
            @unlink($path);
        } else {
            @rmdir($path);
        }
    }
    if (!@rmdir($pluginDir)) {
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
    $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(PLUGIN_PATH);

    foreach ($manifest['setup']['checks'] as $i => $check) {
        $label = $check['label'] ?? 'Check ' . ($i + 1);
        $cmd = $check['check'] ?? '';
        $tip = $check['doc'] ?? '';
        $runCmd = $check['command'] ?? '';

        // Replace placeholders
        $expanded = str_replace(
            ['{plugin_dir}', '{project_root}'],
            [$pluginDir, $projectRoot],
            $cmd
        );

        // Evaluate check
        $passed = false;
        $output = null;
        if ($expanded !== '') {
            exec($expanded . ' 2>&1', $output, $exitCode);
            $passed = $exitCode === 0;
        }

        $results[] = [
            'label' => $label,
            'passed' => $passed,
            'command' => str_replace(['{plugin_dir}', '{project_root}'], [$pluginDir, $projectRoot], $runCmd),
            'doc' => $tip,
            'raw_output' => $output ? implode("\n", $output) : '',
        ];
    }

    return $results;
}
