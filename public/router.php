<?php
// router.php
// define('DEV_LOCK_ENABLED', true);
// require_once __DIR__ . '/dev_lock.php';

// BOOTSTRAP
require_once __DIR__ . '/../app/bootstrap_core.php';
require_once __DIR__ . '/../app/bootstrap_theme.php';

// Load plugin system (hooks + registry + active plugin auto-loader)
require_once __DIR__ . '/../plugins/index.php';
plugin_load_active();

// Fire init action — plugins register routes, shortcodes, hooks here
do_action('init');

// normalize path
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$rawPath = rawurldecode($rawPath);
$pathTrimmed = trim($rawPath, " \t\n\r\0\x0B/");

// Allow plugins to rewrite the request path before route matching
// (e.g. locale prefix stripping for translated content routing)
$pathTrimmed = apply_filters('router_path', $pathTrimmed);

// Optional site-specific routes live outside the managed Core router.
$siteRouter = BACKEND_PATH . '/site-router.php';
if (is_file($siteRouter)) require $siteRouter;

// A Service Worker must be served from the origin root to control the whole site.
// Plugins append their worker event handlers through this filter.
if ($pathTrimmed === 'sw.js') {
    $script = apply_filters('service_worker_script', '');
    if ($script !== '') {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Service-Worker-Allowed: /');
        echo $script;
        exit;
    }
}

// homepage
if ($pathTrimmed === '') {
    $context_for_layout = 'home';
    require __DIR__ . '/index.php';
    exit;
}

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/');
$absFile = $docRoot . $rawPath;

// If running under PHP built-in web server, prefer to let it serve existing static files.
// Additionally, if the request is for a directory that contains an index.php, include it
// so directory-based admin pages like /melbu/ work under dev server.
if (php_sapi_name() === 'cli-server') {

    // 1) If exact file exists, let the built-in server serve it.
    if (is_file($absFile)) {
        return false; // built-in server will serve the file
    }

    // 2) If requested path is a directory and contains index.php, execute that index.
    if (is_dir($absFile) && is_file($absFile . '/index.php')) {
        // Execute the directory's index.php as a separate request.
        // This ensures admin pages placed under dashboard/.../index.php work.
        require $absFile . '/index.php';
        exit;
    }

    // Otherwise continue routing below (no file/directory match)
}

// For non-cli-server SAPIs (e.g. Apache), keep previous behavior:
// if file/dir exists but router got control for some reason, render 404/layout.
if (is_file($absFile) || is_dir($absFile)) {
    http_response_code(404);
    $context_for_layout = '404';
    require __DIR__ . '/../app/layout.php';
    exit;
}

// trailing slash — BUT skip requests that look like files (have an extension)
// i.e. /sitemap.xml  or /static/script.js should NOT be redirected to /sitemap.xml/
if (substr($rawPath, -1) !== '/') {
    // skip if request looks like a file with extension (contains dot after last slash)
    if (!preg_match('#/[^/]+\.[a-z0-9]{1,10}$#i', $rawPath)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        // Canonicalize the *requested* path (rawPath), not the plugin-rewritten
        // pathTrimmed — otherwise rewrites (e.g. locale prefixes) leak into the redirect
        $canonical = $scheme . '://' . $host . rtrim($rawPath, '/') . '/';
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        if ($qs !== '') $canonical .= '?' . $qs;
        header("HTTP/1.1 301 Moved Permanently");
        header("Location: " . $canonical);
        exit;
    }
    // otherwise it's a file-like request — don't redirect, let route handle it
}

// split
$segments = $pathTrimmed === '' ? [] : explode('/', $pathTrimmed);
$prefix = $segments[0] ?? '';

// CUSTOM LOGIN / REGISTER PATHS
$loginPath = function_exists('get_login_path') ? get_login_path($pdo) : 'adiwira/gerbank/melbu';
$registerPath = function_exists('get_register_path') ? get_register_path($pdo) : 'register';

if (function_exists('auth_path_matches')) {
    if (auth_path_matches($loginPath)) {
        require __DIR__ . '/../dashboard/gerbank/melbu/index.php';
        exit;
    }

    if (auth_path_matches($registerPath)) {
        require __DIR__ . '/../dashboard/gerbank/daptar/index.php';
        exit;
    }
}

// CUSTOM ADMIN PATH
$adminPath = function_exists('get_admin_path') ? get_admin_path($pdo) : '/adiwira';
$pathWithSlash = '/' . $pathTrimmed;
if ($pathWithSlash === $adminPath || strpos($pathWithSlash, $adminPath . '/') === 0) {
    require __DIR__ . '/../dashboard/index.php';
    exit;
}

// PRIVATE FILE STREAM + PDF VIEWER
if ($prefix === 'private') {
    require_once __DIR__ . '/../app/controllers/PrivateFileController.php';

    $action = $segments[1] ?? '';

    if ($action === 'file') {
        $subAction = $segments[2] ?? 'view';
        if ($subAction === '' || $subAction === 'view' || $subAction === 'stream') {
            PrivateFileController::stream($pdo);
            exit;
        }
        if ($subAction === 'preview') {
            PrivateFileController::pdfViewer($pdo);
            exit;
        }
    }

    if ($action === 'media') {
        $subAction = $segments[2] ?? 'view';
        if ($subAction === '' || $subAction === 'view' || $subAction === 'stream') {
            require_once __DIR__ . '/../app/controllers/PrivateMediaController.php';
            PrivateMediaController::view($pdo);
            exit;
        }
    }

    if ($action === 'pdf') {
        $subAction = $segments[2] ?? 'view';
        if ($subAction === '' || $subAction === 'view') {
            PrivateFileController::pdfViewer($pdo);
            exit;
        }
        if ($subAction === 'raw' || $subAction === 'stream') {
            PrivateFileController::stream($pdo);
            exit;
        }
    }

    http_response_code(404);
    require __DIR__ . '/../app/frontend_404.php';
    exit;
}

// PLUGIN STATIC — serve plugin icon/files from plugins/ (outside web root)
if ($prefix === 'plugins' && ($segments[1] ?? '') === 'static') {
    $pluginName = $segments[2] ?? '';
    $pluginFile = $segments[3] ?? '';
    if ($pluginName !== '' && $pluginFile !== '') {
        $pluginName = basename($pluginName);
        $pluginFile = basename($pluginFile);
        $absFile = realpath(PLUGIN_PATH . '/' . $pluginName . '/' . $pluginFile);
        $absBase = realpath(PLUGIN_PATH) . '/';
        if ($absFile !== false && str_starts_with($absFile, $absBase) && is_file($absFile)) {
            $mime = (function_exists('mime_content_type')) ? mime_content_type($absFile) : 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=86400');
            header('Content-Length: ' . filesize($absFile));
            readfile($absFile);
            exit;
        }
    }
    http_response_code(404);
    exit;
}

// AUTHOR
if ($prefix === 'author') {
    require_once __DIR__ . '/../app/controllers/AuthorController.php';

    if (($segments[2] ?? '') === 'page' && isset($segments[3])) collection_redirect_legacy_pagination();
    collection_redirect_legacy_query_pagination();
    $ident = $segments[1] ?? '';
    $page = (isset($segments[2], $segments[3]) && in_array($segments[2], ['p', 'page'], true)) ? (int)$segments[3] : 1;
    $q = trim((string)($_GET['q'] ?? ''));

    if ($ident === '') {
        AuthorController::listAuthors($pdo);
    } else {
        AuthorController::showAuthorPosts($pdo, $ident, $page, $q);
    }
    exit;
}

// CATEGORY — dynamic prefix from settings
$categoryRoutes = function_exists('get_category_routes') ? get_category_routes($pdo) : ['category'];
$categoryMatch = collection_match_route_base($pathTrimmed, $categoryRoutes);
if ($categoryMatch !== null) {
    require_once __DIR__ . '/../app/controllers/CategoryController.php';

    collection_redirect_legacy_query_pagination();

    // collect segments after category prefix
    $segmentsAfter = $categoryMatch['rest'] === '' ? [] : explode('/', $categoryMatch['rest']);
    $q = trim((string)($_GET['q'] ?? ''));

    // detect 'page' segment (e.g. /category/foo/page/2/ or /category/page/2/)
    $page = 1;
    foreach ($segmentsAfter as $idx => $seg) {
        if (in_array($seg, ['p', 'page'], true) && isset($segmentsAfter[$idx + 1]) && ctype_digit((string)$segmentsAfter[$idx + 1])) {
            if ($seg === 'page') collection_redirect_legacy_pagination();
            $page = (int)$segmentsAfter[$idx + 1];
            array_splice($segmentsAfter, $idx, 2);
            break;
        }
    }

    // rebuild slug from remaining segments (can be empty string)
    $slug = implode('/', $segmentsAfter);

    // fallback: ?page=N query param (used by inline fallback pagination links)
    $queryPage = $_GET['p'] ?? $_GET['page'] ?? null;
    if ($page === 1 && $queryPage !== null && ctype_digit((string)$queryPage)) {
        $page = (int)$queryPage;
    }

    // Preserve the resolved dynamic route for controllers and extensions.
    $context = collection_set_route_context([
        'route' => 'category',
        'path' => $pathTrimmed,
        'base' => $categoryMatch['base'],
        'slug' => rawurldecode($slug),
        'page' => $page,
        'query' => $q,
    ]);
    CategoryController::showCategory($pdo, (string)$context['slug'], (int)$context['page'], (string)$context['query'], $context);
    exit;
}

// --- Year/month archive routing (e.g. /2025/ or /2025/11/ or /2025/11/page/2/) ---
if (preg_match('/^\d{4}$/', $prefix)) {
    $segCount = count($segments);
    $tryResolver = false;

    if (function_exists('permalink_is_date_based') && permalink_is_date_based($pdo)) {
        $postStruct = get_permalink_structure($pdo, 'post');
        $structSegs = function_exists('permalink_structure_segment_count')
            ? permalink_structure_segment_count($postStruct)
            : 2;
        // If path segments match the structure's segment count, try the permalink resolver for a single post.
        // Otherwise it's an archive page (fewer segs -> year/month archive).
        $tryResolver = ($segCount === $structSegs);
    }

    if ($tryResolver) {
        // fall through to permalink_resolve in the fallback section
        // if resolver returns null, try archive as fallback
        $fallbackToArchive = true;
    } else {
        $fallbackToArchive = false;
        require_once __DIR__ . '/../app/controllers/ArchiveController.php';

        $year = (int)$segments[0];
        $month = isset($segments[1]) && preg_match('/^\d{1,2}$/', $segments[1]) ? (int)$segments[1] : null;

        // detect /page/2/ after month or year
        $page = 1;
        $pageIndex = $month ? 2 : 1;
        collection_redirect_legacy_query_pagination();
        if (isset($segments[$pageIndex]) && in_array($segments[$pageIndex], ['p', 'page'], true) && isset($segments[$pageIndex + 1])) {
            if ($segments[$pageIndex] === 'page') collection_redirect_legacy_pagination();
            $page = (int)$segments[$pageIndex + 1];
        } elseif (!empty($_GET['p']) || !empty($_GET['page'])) {
            $page = max(1, (int)($_GET['p'] ?? $_GET['page']));
        }

        ArchiveController::show($pdo, $year, $month, $page, null);
        exit;
    }
}

// SITEMAP routes (no htaccess needed)
// /sitemap.xml
// /sitemap_posts_1.xml
// /sitemap_pages_2.xml
if (preg_match('#^sitemap_([a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*)_(posts|pages|themes)_(\d+)\.xml$#', $pathTrimmed, $m)) {
    require_once __DIR__ . '/../app/controllers/SitemapController.php';
    if (SitemapController::renderLocale($pdo, $m[1], $m[2], max(1, (int)$m[3]))) exit;
}
if (preg_match('#^sitemap(?:_(posts|pages|themes)_(\d+))?\.xml$#', $pathTrimmed, $m)) {
    require_once __DIR__ . '/../app/controllers/SitemapController.php';
    // matches:
    // m[0] full, m[1] = 'posts' or 'pages' or null, m[2] = number or null
    if (empty($m[1])) {
        SitemapController::index($pdo); // sitemap.xml
    } else {
        $type = $m[1]; // 'posts' or 'pages'
        $num = isset($m[2]) ? max(1, (int)$m[2]) : 1;
        SitemapController::list($pdo, $type, $num);
    }
    exit;
}

// LIST ARTICLES — dynamic prefix from settings
$postsListRoutes = function_exists('get_posts_list_routes') ? get_posts_list_routes($pdo) : ['artikel'];
$postsListMatch = collection_match_route_base($pathTrimmed, $postsListRoutes);
if ($postsListMatch !== null) {
    require_once __DIR__ . '/../app/controllers/PostController.php';

    collection_redirect_legacy_query_pagination();
    $page = 1;
    if (!empty($_GET['p']) || !empty($_GET['page'])) {
        $page = max(1, (int)($_GET['p'] ?? $_GET['page']));
    } else {
        $segmentsAfter = $postsListMatch['rest'] === '' ? [] : explode('/', $postsListMatch['rest']);
        foreach ($segmentsAfter as $idx => $seg) {
            if (in_array($seg, ['p', 'page'], true) && isset($segmentsAfter[$idx + 1]) && ctype_digit((string)$segmentsAfter[$idx + 1])) {
                if ($seg === 'page') collection_redirect_legacy_pagination();
                $page = (int)$segmentsAfter[$idx + 1];
                break;
            }
        }
    }

    $q = trim((string)($_GET['q'] ?? ''));
    PostController::listArticles($pdo, $page, $q);
    exit;
}

// PAGES LIST — dynamic prefix from settings
$pagesListRoutes = function_exists('get_pages_list_routes') ? get_pages_list_routes($pdo) : ['halaman'];
$pagesListMatch = collection_match_route_base($pathTrimmed, $pagesListRoutes);
if ($pagesListMatch !== null) {
    require_once __DIR__ . '/../app/controllers/PageController.php';

    collection_redirect_legacy_query_pagination();
    $page = 1;
    $segmentsAfter = $pagesListMatch['rest'] === '' ? [] : explode('/', $pagesListMatch['rest']);
    foreach ($segmentsAfter as $idx => $seg) {
        if (in_array($seg, ['p', 'page'], true) && isset($segmentsAfter[$idx + 1]) && ctype_digit((string)$segmentsAfter[$idx + 1])) {
            if ($seg === 'page') collection_redirect_legacy_pagination();
            $page = (int)$segmentsAfter[$idx + 1];
            break;
        }
    }
    if (!empty($_GET['p']) || !empty($_GET['page'])) {
        $page = max(1, (int)($_GET['p'] ?? $_GET['page']));
    }

    $q = trim((string)($_GET['q'] ?? ''));
    PageController::listPages($pdo, $page, $q);
    exit;
}

// PLUGIN FRONTEND ROUTES — registered via register_frontend_route() in plugin.php files
if (function_exists('match_frontend_route')) {
    $pluginHandler = match_frontend_route($prefix);
    if ($pluginHandler !== null) {
        if (is_callable($pluginHandler)) {
            $pluginHandler($pdo);
        } elseif (is_string($pluginHandler) && is_file($pluginHandler)) {
            require $pluginHandler;
        }
        exit;
    }
}

// FALLBACK POST — try permalink resolver first, then direct slug lookup
require_once __DIR__ . '/../app/controllers/PostController.php';

$isLoggedIn = (function_exists('is_logged_in') && is_logged_in());

if (function_exists('permalink_resolve')) {
    $resolved = permalink_resolve($pdo, $pathTrimmed);
    if ($resolved) {
        $canonicalRoute = function_exists('content_route_find_canonical')
            ? content_route_find_canonical($pdo, (int)($resolved['id'] ?? 0))
            : null;
        $routeUrl = $canonicalRoute
            ? (($resolved['type'] ?? '') === 'page' && function_exists('get_page_permalink')
                ? get_page_permalink($resolved)
                : get_post_permalink($resolved))
            : null;
        if (is_string($routeUrl) && trim($routeUrl, '/') !== trim($pathTrimmed, '/')) {
            header('Location: ' . $routeUrl, true, 301);
            exit;
        }
        $type = $resolved['type'] ?? 'article';
        switch ($type) {
            case 'theme':
                require_once __DIR__ . '/../app/controllers/ThemeController.php';
                ThemeController::renderTheme($resolved);
                break;
            case 'page':
                require_once __DIR__ . '/../app/controllers/PageController.php';
                PageController::renderPage($pdo, (string)($resolved['slug'] ?? ''));
                break;
            default:
                PostController::renderArticle($resolved, $pdo);
                break;
        }
        exit;
    }
}

// If we fell through from a date-based year prefix, try archive as fallback
if (!empty($fallbackToArchive)) {
    require_once __DIR__ . '/../app/controllers/ArchiveController.php';

    $year = (int)$segments[0];
    $month = isset($segments[1]) && preg_match('/^\d{1,2}$/', $segments[1]) ? (int)$segments[1] : null;

    $page = 1;
    $pageIndex = $month ? 2 : 1;
    collection_redirect_legacy_query_pagination();
    if (isset($segments[$pageIndex]) && in_array($segments[$pageIndex], ['p', 'page'], true) && isset($segments[$pageIndex + 1])) {
        if ($segments[$pageIndex] === 'page') collection_redirect_legacy_pagination();
        $page = (int)$segments[$pageIndex + 1];
    } elseif (!empty($_GET['p']) || !empty($_GET['page'])) {
        $page = max(1, (int)($_GET['p'] ?? $_GET['page']));
    }

    ArchiveController::show($pdo, $year, $month, $page, null);
    exit;
}

// If category prefix is empty, try resolving path as root-level category
$catEnabled = function_exists('is_category_enabled') ? is_category_enabled($pdo) : true;
if (!$catEnabled && function_exists('resolve_category_from_path')) {
    $rootCategoryPath = $pathTrimmed;
    $page = 1;
    if (preg_match('#^(.*?)/(?:p|page)/(\d+)$#', $rootCategoryPath, $pageMatch)) {
        if (str_contains($rootCategoryPath, '/page/')) collection_redirect_legacy_pagination();
        $rootCategoryPath = $pageMatch[1];
        $page = max(1, (int)$pageMatch[2]);
    } elseif (!empty($_GET['p']) || !empty($_GET['page'])) {
        $page = max(1, (int)($_GET['p'] ?? $_GET['page']));
    }
    $catSlug = resolve_category_from_path($pdo, $rootCategoryPath);
    if ($catSlug !== null) {
        collection_redirect_legacy_query_pagination();
        require_once __DIR__ . '/../app/controllers/CategoryController.php';
        $q = trim((string)($_GET['q'] ?? ''));
        CategoryController::showCategory($pdo, $catSlug, $page, $q);
        exit;
    }
}

// Explicit canonical and historical content routes. These intentionally run
// after infrastructure, collections, plugins, and root categories.
if (function_exists('content_route_resolve')) {
    try {
        $routeLocale = (string)apply_filters('content_route_request_locale', '');
        $visibility = $isLoggedIn ? 'editorial' : 'public';
        $routed = content_route_resolve($pdo, $pathTrimmed, $routeLocale, $visibility);
    } catch (Throwable $e) {
        error_log('[content_routes] resolve error: ' . $e->getMessage());
        $routed = null;
    }
    if (is_array($routed)) {
        if (empty($routed['route_is_canonical']) && !empty($routed['canonical_path'])) {
            $target = content_route_public_path((string)$routed['canonical_path'], (string)($routed['route_locale'] ?? ''));
            $query = (string)($_SERVER['QUERY_STRING'] ?? '');
            if ($query !== '') $target .= '?' . $query;
            header('Location: ' . $target, true, 301);
            exit;
        }
        switch ((string)($routed['type'] ?? '')) {
            case 'theme':
                require_once __DIR__ . '/../app/controllers/ThemeController.php';
                ThemeController::renderTheme($routed);
                break;
            case 'page':
                require_once __DIR__ . '/../app/controllers/PageController.php';
                PageController::renderPage($pdo, (string)$routed['slug']);
                break;
            case 'article':
                PostController::renderArticle($routed, $pdo);
                break;
        }
        exit;
    }
}

/**
 * Last-chance extension redirect for unresolved frontend content paths.
 * The returned root-relative or absolute target may already contain a query;
 * the request query is then appended with the correct separator.
 */
$unresolvedRedirect = apply_filters('unresolved_content_redirect_url', '', $pathTrimmed, $pdo);
if (is_string($unresolvedRedirect) && $unresolvedRedirect !== '') {
    $target = url_append_query_string($unresolvedRedirect, (string)($_SERVER['QUERY_STRING'] ?? ''));
    if ($target !== '') {
        header('Location: ' . $target, true, 301);
        exit;
    }
}

// Fallback to direct slug lookup (backward compat)
PostController::dispatchBySlug($pathTrimmed, $pdo, $isLoggedIn);
