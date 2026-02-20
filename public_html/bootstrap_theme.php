<?php
declare(strict_types=1);

/**
 * bootstrap_theme.php
 *
 * Tanggung jawab:
 * - Mendefinisikan PUBLIC_PATH (web root) bila belum ada
 * - Load theme helper (jika ada) — non-fatal
 * - Menyediakan THEME_DEBUG constant
 * - Menyediakan shim-safe untuk fungsi presentasi jika perlu (render_assigned_slot)
 */

// Theme debug flag (override via constant jika diperlukan)
if (!defined('THEME_DEBUG')) {
    define('THEME_DEBUG', (getenv('THEME_DEBUG') === '1'));
}

// Define PUBLIC_PATH if not set (frontend root). PUBLIC_PATH harus menunjuk ke folder public root.
if (!defined('PUBLIC_PATH')) {
    // __DIR__ di sini diasumsikan berada di public root (index.php yang mem-include file ini juga harus di public root)
    define('PUBLIC_PATH', __DIR__);
}

// Helper debug logger for theme
if (!function_exists('theme_dbg')) {
    function theme_dbg(string $msg): void {
        if (THEME_DEBUG) {
            error_log("[bootstrap_theme] " . $msg);
        }
    }
}

theme_dbg("PUBLIC_PATH=" . PUBLIC_PATH);

// Attempt to load theme_helper.php from public root (non-fatal)
$theme_helper_path = rtrim(PUBLIC_PATH, '/\\') . DIRECTORY_SEPARATOR . 'theme_helper.php';
if (is_file($theme_helper_path)) {
    require_once $theme_helper_path;
    theme_dbg("theme_helper loaded from {$theme_helper_path}");
} else {
    theme_dbg("theme_helper NOT FOUND at {$theme_helper_path} (continuing)");
}

// Provide a minimal compatibility shim for render_assigned_slot if project expects it
if (!function_exists('render_assigned_slot')) {
    /**
     * Minimal shim: if theme_helper normally provides advanced rendering,
     * this avoids fatal errors when it's absent.
     *
     * Usage: render_assigned_slot($slotName, $default = null);
     */
    function render_assigned_slot(string $slotName, $default = null) {
        // If theme_helper provided a fuller implementation, this won't be used.
        // Keep behavior conservative: return default.
        return $default;
    }
    theme_dbg("render_assigned_slot shim installed");
}

// Provide small safety helpers used by templates
if (!function_exists('asset_url')) {
    function asset_url(string $path): string {
        // return path relative to PUBLIC_PATH; callers should echo/escape
        $trim = ltrim($path, '/');
        return '/' . $trim;
    }
}

theme_dbg("bootstrap_theme finished");
