<?php
/**
 * ===============================================================
 *  Adiwira Jyavani CMS © 2025 Adam Muiz
 *  All Rights Reserved.
 *  Unauthorized copying, modification, or redistribution is prohibited.
 * ===============================================================
 *
 *  Description:
 *  - Core bootstrap for Adiwira CMS (admin area)
 *  - Provides theme, database, and session integration
 *
 *  Contact: az@adammuiz.com
 */

// Prevent repeated defines if this file is included more than once
if (!defined('BACKEND_PATH')) {
    // define backend path only if not already set by earlier bootstrap (bootstrap_core.php)
    define('BACKEND_PATH', '/home/adam/cms/jyavani/cfg');
}

// Load core config (env + db + helpers). Use require_once to avoid re-including.
require_once BACKEND_PATH . '/config.php';

// Now explicitly load session handling (only for private bootstrap)
// session.php should be safe: it won't forcibly start session unless requested.
$session_file = BACKEND_PATH . '/session.php';
if (is_file($session_file)) {
    require_once $session_file;
}

// Define dash path for includes
if (!defined('DASH_PATH')) {
    define('DASH_PATH', __DIR__);
}

if (!defined('PUBLIC_PATH')) {
    // allow explicit env override (deployment)
    $envPublic = getenv('PUBLIC_PATH') ?: '';

    if ($envPublic !== '' && realpath($envPublic) !== false) {
        $publicPath = realpath($envPublic);
    } else {
        // In your hosting layout the public root is the parent of /adiwira
        $parent = realpath(__DIR__ . '/..');
        $publicPath = $parent !== false ? $parent : (__DIR__ . '/..');
    }

    define('PUBLIC_PATH', rtrim($publicPath, '/\\'));
}

// Validate DB
if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    // Keep message concise for dev; in production DON'T expose DB details
    die("❌ Backend tidak berhasil di-bootstrap. Pastikan konfigurasi database dan environment sudah benar.");
}