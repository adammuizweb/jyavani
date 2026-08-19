<?php
// /adiwira/?
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Load guard helpers early so plugin routes can enforce role checks.
$guardFile = __DIR__ . '/admin/_guard.php';
if (is_file($guardFile)) {
    require_once $guardFile;
}

// Path guard: 404 if request URI doesn't match configured admin path
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$requestPath = '/' . trim($requestPath, '/');
if ($requestPath !== ADMIN_BASE_PATH && strpos($requestPath, ADMIN_BASE_PATH . '/') !== 0) {
    http_response_code(404);
    require FRONTEND_404_PATH;
    exit;
}

// Resume session yang sudah ada saja.
// Jangan create session baru untuk guest di halaman dashboard.
if (!headers_sent()) {
    if (function_exists('ensure_session_started')) {
        ensure_session_started(false);
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

if (function_exists('sess_dbg')) {
    sess_dbg("adiwira/?: request start");
}

// Gate 1: session valid?
try {
    if (!is_logged_in()) {
        http_response_code(404);
        require FRONTEND_404_PATH;
        exit;
    }
} catch (Throwable $e) {
    if (function_exists('sess_dbg')) {
        sess_dbg("adiwira/?: is_logged_in exception: " . $e->getMessage());
    }
    http_response_code(500);
    echo __('Internal error (see logs).');
    exit;
}

// Gate 2: user masih aktif di DB?
try {
    $status = current_user_status($pdo);

    if (($status['ok'] ?? false) !== true) {
        if (function_exists('sess_dbg')) {
            sess_dbg("adiwira/?: blocked user reason=" . ($status['reason'] ?? 'unknown'));
        }

        logout_user();

        http_response_code(404);
        require FRONTEND_404_PATH;
        exit;
    }

    $user = $status['user'];
} catch (Throwable $e) {
    if (function_exists('sess_dbg')) {
        sess_dbg("adiwira/?: current_user_status exception: " . $e->getMessage());
    }
    http_response_code(500);
    echo __('Internal error (user load failed).');
    exit;
}

// Gate 3: an active account still needs an explicit dashboard grant.
if (!function_exists('current_user_can') || !current_user_can($pdo, 'core.dashboard.access')) {
    http_response_code(404);
    require FRONTEND_404_PATH;
    exit;
}

// Load plugin code only after identity and dashboard authorization succeed.
$pluginLoader = __DIR__ . '/../plugins/index.php';
if (is_file($pluginLoader)) {
    require_once $pluginLoader;
    plugin_load_active();
    plugin_sync_permissions($pdo);
    do_action('admin_init');
}

// dashboard context
define('DASHBOARD_CONTEXT', true);

// Enforce admin UI locale separately from frontend content default locale
if (function_exists('set_locale') && function_exists('admin_ui_locale')) {
    set_locale(admin_ui_locale());
}

// sinkronkan role dari DB -> session
if (is_array($user)) {
    $role = function_exists('authorization_active_legacy_role')
        ? authorization_active_legacy_role($pdo, (int)($user['id'] ?? 0))
        : ($user['role'] ?? $user['user_role'] ?? null);
    if (is_string($role) && $role !== '') {
        $_SESSION['user_role'] = strtolower(trim($role));
        $user['role'] = strtolower(trim($role));
    }
}

// AJAX handler: page actions that return JSON (must run before layout)
$ajaxAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$ajaxPage = (string)($_GET['page'] ?? '');
if ($ajaxAction !== '' && $ajaxPage !== '') {
    // Check plugin AJAX routes first
    if (function_exists('plugin_resolve_route')) {
        $resolved = plugin_resolve_route($ajaxPage);
        if ($resolved && isset($resolved['file']) && is_file($resolved['file'])) {
            if (function_exists('plugin_guard_route')) {
                plugin_guard_route($pdo, $resolved, true);
            }
            require $resolved['file'];
            exit;
        }
    }
    // Then check normal dashboard file
    $ajaxFile = realpath(DASH_PATH . '/' . $ajaxPage . '.php');
    $dashRoot = realpath(DASH_PATH);
    if ($ajaxFile !== false && $dashRoot !== false && str_starts_with($ajaxFile, $dashRoot . DIRECTORY_SEPARATOR) && is_file($ajaxFile)) {
        require $ajaxFile;
    }
    exit;
}

// Direct file router: map request URI path to dashboard/plugin files
// Handles URLs like /admin/modal_img/list_modal.php or plugin routes
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$adminPrefix = ADMIN_BASE_PATH . '/';
if (strncmp($uriPath, $adminPrefix, strlen($adminPrefix)) === 0) {
    $relative = substr($uriPath, strlen($adminPrefix));
    if (is_string($relative) && $relative !== '' && preg_match('/\.php$/', $relative)) {
        // Check plugin routes first (plugins override dashboard files)
        if (function_exists('plugin_resolve_route')) {
            $route = preg_replace('/\.php$/', '', $relative);
            $resolved = plugin_resolve_route($route);
            if ($resolved && isset($resolved['file']) && is_file($resolved['file'])) {
                if (function_exists('plugin_guard_route')) {
                    plugin_guard_route($pdo, $resolved, false);
                }
                require $resolved['file'];
                exit;
            }
        }
        // Then check normal dashboard file
        $targetFile = DASH_PATH . '/' . $relative;
        if (is_file($targetFile)) {
            require $targetFile;
            exit;
        }
    }
}

// flash
$flash_success = null;
if (!empty($_SESSION['flash_success'])) {
    $flash_success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// render
require_once DASH_PATH . '/theme/adam/layout.php';
