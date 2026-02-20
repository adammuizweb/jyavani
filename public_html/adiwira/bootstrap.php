<?php
/**
 * ===============================================================
 *  Adiwira Jyavani CMS © 2025 Adam Muiz
 *  All Rights Reserved.
 *  Unauthorized copying, modification, or redistribution is prohibited.
 * ===============================================================
 *
 *  Description:
 *  - Core bootstrap for Adiwira CMS
 *  - Provides theme, database, and session integration
 *
 *  Contact: az@adammuiz.com
 */
 
// adiwira/bootstrap.php — private bootstrap (admin / login required)
define('BACKEND_PATH', '/home/u528279701/v1-cfg');

// Load core config (env + db + helpers). NOTE: config.php no longer auto-load session.php
require_once BACKEND_PATH . '/config.php';

// Now explicitly load session handling (only for private bootstrap)
require_once BACKEND_PATH . '/session.php';

// Define dash path for includes
define('DASH_PATH', __DIR__);

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
    die("❌ Backend tidak berhasil di-bootstrap. Pastikan konfigurasi database dan environment sudah benar.");
}