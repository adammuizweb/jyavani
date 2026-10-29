<?php
// /adiwira/?
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Load guard helpers early so plugin routes can enforce role checks.
$guardFile = __DIR__ . '/admin/_guard.php';
if (is_file($guardFile)) {
    require_once $guardFile;
}

if (!function_exists('jy_dashboard_safe_file')) {
    function jy_dashboard_safe_file(string $root, string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')
            || str_starts_with($relative, '/')) return null;
        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') return null;
        }
        $rootReal = realpath($root);
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($candidate);
        $prefix = $rootReal !== false ? rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
        if ($prefix === '' || $resolved === false || !str_starts_with($resolved, $prefix)
            || !is_file($resolved) || !is_readable($resolved)) return null;

        $cursor = rtrim($root, '/\\');
        foreach ($parts as $part) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            if (is_link($cursor)) return null;
        }
        return $resolved;
    }
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

// Authenticated dashboard documents contain user-specific state and must not
// be restored from a stale browser/proxy cache.
if (!headers_sent()) {
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// Enforce admin UI locale separately from frontend content default locale.
if (function_exists('set_locale') && function_exists('admin_ui_locale')) {
    set_locale(admin_ui_locale());
}

// Load plugin and active-theme code only after dashboard authorization succeeds.
$pluginLoader = __DIR__ . '/../plugins/index.php';
if (is_file($pluginLoader)) {
    require_once $pluginLoader;
    plugin_load_active();
    theme_load_active_entrypoint($pdo);
    plugin_sync_permissions($pdo);
    do_action('admin_init');
}

// dashboard context
define('DASHBOARD_CONTEXT', true);

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

// Explicit non-HTML plugin responses must run before any dashboard markup.
$responsePage = trim((string)($_GET['page'] ?? ''), " \t\n\r\0\x0B/");
if ($responsePage !== '' && function_exists('plugin_resolve_route')) {
    $responseRoute = plugin_resolve_route($responsePage);
    $responseMode = is_array($responseRoute) && function_exists('plugin_route_response_mode')
        ? plugin_route_response_mode($responseRoute)
        : 'html';
    if (is_array($responseRoute) && $responseMode !== 'html'
        && isset($responseRoute['file']) && is_file($responseRoute['file'])) {
        if (function_exists('plugin_guard_route')) {
            plugin_guard_route($pdo, $responseRoute, $responseMode === 'json');
        }
        require $responseRoute['file'];
        exit;
    }
}

// Compatibility handler: page actions that return JSON or files before layout.
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
    $ajaxFile = jy_dashboard_safe_file(DASH_PATH, $ajaxPage . '.php');
    if ($ajaxFile !== null) {
        require $ajaxFile;
    }
    exit;
}

// Direct file router: map request URI path to dashboard/plugin files
// Handles URLs like /admin/modal_img/list_modal.php or plugin routes
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uriPath = is_string($uriPath) ? rawurldecode($uriPath) : '/';
$adminPrefix = ADMIN_BASE_PATH . '/';
if (strncmp($uriPath, $adminPrefix, strlen($adminPrefix)) === 0) {
    $relative = substr($uriPath, strlen($adminPrefix));
    if (is_string($relative) && $relative !== '' && preg_match('/\.php(?:\/|$)/', $relative)) {
        if (!str_ends_with($relative, '.php')) {
            http_response_code(404);
            require FRONTEND_404_PATH;
            exit;
        }
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
        // Then check normal dashboard file.
        $targetFile = jy_dashboard_safe_file(DASH_PATH, $relative);
        if ($targetFile !== null) {
            require $targetFile;
            exit;
        }
        http_response_code(404);
        require FRONTEND_404_PATH;
        exit;
    }
}

// flash
$flash_success = null;
if (!empty($_SESSION['flash_success'])) {
    $flash_success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// render
require_once DASH_PATH . '/theme/adiwira/layout.php';

$document = $GLOBALS['__JY_DASHBOARD_DOCUMENT'] ?? null;
if (is_array($document) && ($document['rendered'] ?? false) === true && function_exists('access_log_try_record_document')) {
    $responseStatus = http_response_code();
    if (!is_int($responseStatus) || $responseStatus < 100) $responseStatus = 200;
    access_log_try_record_document($pdo, [
        'surface' => 'dashboard',
        'event_key' => 'dashboard.page_viewed',
        'actor_user_id' => (int)$user['id'],
        'route' => (string)$document['route'],
        'page_title' => (string)$document['label'],
        'resource_type' => 'dashboard_route',
        'resource_id' => (string)$document['route'],
        'resource_label' => (string)$document['label'],
        'response_status' => $responseStatus,
    ]);
}
