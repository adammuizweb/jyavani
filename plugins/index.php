<?php
declare(strict_types=1);
// Plugin Registry — Jyavani CMS Plugin System v1.0
// Loaded after bootstrap in dashboard/index.php

define('PLUGIN_PATH', __DIR__);
define('PLUGIN_DISABLED_JSON', defined('BACKEND_PATH') ? BACKEND_PATH . '/var/plugins-disabled.json' : PLUGIN_PATH . '/disabled.json');

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
