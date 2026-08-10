<?php
declare(strict_types=1);

/**
 * bootstrap_theme.php
 */

if (!defined('THEME_DEBUG')) {
    define('THEME_DEBUG', function_exists('app_debug_enabled') ? app_debug_enabled() : false);
}

if (!defined('PUBLIC_PATH')) {
    $publicPath = realpath(__DIR__ . '/../public');
    define('PUBLIC_PATH', $publicPath ?: (__DIR__ . '/../public'));
}

if (!function_exists('theme_dbg')) {
    function theme_dbg(string $msg): void {
        if (THEME_DEBUG) {
            error_log("[bootstrap_theme] " . $msg);
        }
    }
}

theme_dbg("PUBLIC_PATH=" . PUBLIC_PATH);

$hooks_path = realpath(__DIR__ . '/../cfg/helpers/hooks.php') ?: (__DIR__ . '/../cfg/helpers/hooks.php');
if (!function_exists('apply_filters') && is_file($hooks_path)) {
    require_once $hooks_path;
}

$theme_helper_path = realpath(__DIR__ . '/../cfg/helpers/theme_helper.php') ?: (__DIR__ . '/../cfg/helpers/theme_helper.php');
if (is_file($theme_helper_path)) {
    require_once $theme_helper_path;
    theme_dbg("theme_helper loaded from {$theme_helper_path}");
} else {
    theme_dbg("theme_helper NOT FOUND at {$theme_helper_path} (continuing)");
}

$widget_helper_path = realpath(__DIR__ . '/../cfg/helpers/widget_helper.php') ?: (__DIR__ . '/../cfg/helpers/widget_helper.php');
if (is_file($widget_helper_path)) {
    require_once $widget_helper_path;
    theme_dbg("widget_helper loaded from {$widget_helper_path}");
} else {
    theme_dbg("widget_helper NOT FOUND at {$widget_helper_path} (continuing)");
}

$theme_sections_path = realpath(__DIR__ . '/../cfg/helpers/theme_sections.php') ?: (__DIR__ . '/../cfg/helpers/theme_sections.php');
if (is_file($theme_sections_path)) {
    require_once $theme_sections_path;
    theme_dbg("theme_sections loaded from {$theme_sections_path}");
}

if (!function_exists('render_assigned_slot')) {
    function render_assigned_slot(string $slotName, $default = null) {
        return $default;
    }
    theme_dbg("render_assigned_slot shim installed");
}

if (!function_exists('asset_url')) {
    function asset_url(string $path): string {
        $trim = ltrim($path, '/');
        return '/' . $trim;
    }
}

theme_dbg("bootstrap_theme finished");
