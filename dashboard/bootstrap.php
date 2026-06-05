<?php
/**
 * ===============================================================
 *  Jyavani CMS © 2025 Adam Muiz
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
    $env_backend = getenv('BACKEND_PATH') ?: '';
    if ($env_backend !== '') {
        define('BACKEND_PATH', $env_backend);
    } else {
        $guess = realpath(__DIR__ . '/../cfg');
        if ($guess !== false) {
            define('BACKEND_PATH', $guess);
        } else {
            http_response_code(500);
            die('Backend path not configured.');
        }
    }
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
        // After moving admin out of public, the public root is one level up + public/
        $parent = realpath(__DIR__ . '/../public');
        $publicPath = $parent !== false ? $parent : (__DIR__ . '/../public');
    }

    define('PUBLIC_PATH', rtrim($publicPath, '/\\'));
}

if (!defined('FRONTEND_404_PATH')) {
    define('FRONTEND_404_PATH', dirname(__DIR__) . '/app/frontend_404.php');
}

// Define admin base URL path for internal links
if (!defined('ADMIN_BASE_PATH')) {
    $abp = 'adiwira';
    if (isset($pdo) && $pdo) {
        $abp_v = settings_get($pdo, 'admin_path', 'adiwira');
        if (is_string($abp_v) && $abp_v !== '') {
            $abp = $abp_v;
        }
    }
    define('ADMIN_BASE_PATH', '/' . trim($abp, '/'));
}

// Validate DB
if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    // Keep message concise for dev; in production DON'T expose DB details
    die("❌ " . __('Backend failed to bootstrap. Make sure database and environment configuration is correct.'));
}