<?php
// router.php
// define('DEV_LOCK_ENABLED', true);
// require_once __DIR__ . '/dev_lock.php';
// PRODUCTION SAFE MODE
// DEBUG: tampilkan fatal error sementara (letakkan di paling atas router.php)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err) {
        http_response_code(500);
        echo "<pre>FATAL: {$err['message']}\nFile: {$err['file']} : line {$err['line']}</pre>";
    }
});


// BOOTSTRAP
require_once __DIR__ . '/bootstrap_core.php';
require_once __DIR__ . '/bootstrap_theme.php';

// normalize path
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$rawPath = rawurldecode($rawPath);
$pathTrimmed = trim($rawPath, " \t\n\r\0\x0B/");

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
// so directory-based admin pages like /adiwira/gerbank/melbu/ work under dev server.
if (php_sapi_name() === 'cli-server') {

    // 1) If exact file exists, let the built-in server serve it.
    if (is_file($absFile)) {
        return false; // built-in server will serve the file
    }

    // 2) If requested path is a directory and contains index.php, execute that index.
    if (is_dir($absFile) && is_file($absFile . '/index.php')) {
        // Execute the directory's index.php as a separate request.
        // This ensures admin pages placed under public/adiwira/.../index.php work.
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
    require __DIR__ . '/layout.php';
    exit;
}

// trailing slash — BUT skip requests that look like files (have an extension)
// i.e. /sitemap.xml  or /static/script.js should NOT be redirected to /sitemap.xml/
if (substr($rawPath, -1) !== '/') {
    // skip if request looks like a file with extension (contains dot after last slash)
    if (!preg_match('#/[^/]+\.[a-z0-9]{1,6}$#i', $rawPath)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $canonical = $scheme . '://' . $host . '/' . $pathTrimmed . '/';
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

// AUTHOR
if ($prefix === 'author') {
    require_once __DIR__ . '/controllers/AuthorController.php';

    $ident = $segments[1] ?? '';
    $page = (isset($segments[2], $segments[3]) && $segments[2] === 'page') ? (int)$segments[3] : 1;    
    $q = trim((string)($_GET['q'] ?? ''));

    if ($ident === '') {
        AuthorController::listAuthors($pdo);
    } else {
        AuthorController::showAuthorPosts($pdo, $ident, $page, $q);
    }
    exit;
}

// CATEGORY
if ($prefix === 'category') {
    require_once __DIR__ . '/controllers/CategoryController.php';

    // collect segments after 'category'
    $segmentsAfter = array_slice($segments, 1);
    $q = trim((string)($_GET['q'] ?? ''));

    // detect 'page' segment (e.g. /category/foo/page/2/ or /category/page/2/)
    $page = 1;
    foreach ($segmentsAfter as $idx => $seg) {
        if ($seg === 'page' && isset($segmentsAfter[$idx + 1]) && ctype_digit((string)$segmentsAfter[$idx + 1])) {
            $page = (int)$segmentsAfter[$idx + 1];
            // remove the page segments so they are not part of slug
            array_splice($segmentsAfter, $idx, 2);
            break;
        }
    }

    // rebuild slug from remaining segments (can be empty string)
    $slug = implode('/', $segmentsAfter);

    // call controller (slug may be empty -> controller will render parent list)
    CategoryController::showCategory($pdo, rawurldecode($slug), $page, $q);
    exit;
}

// --- Year/month archive routing (e.g. /2025/ or /2025/11/ or /2025/11/page/2/) ---
if (preg_match('/^\d{4}$/', $prefix)) {
    // first segment is a 4-digit year
    require_once __DIR__ . '/controllers/ArchiveController.php';

    $year = (int)$segments[0];
    $month = isset($segments[1]) && preg_match('/^\d{1,2}$/', $segments[1]) ? (int)$segments[1] : null;

    // detect /page/2/ after month or year
    $page = 1;
    // possible positions for 'page' segment:
    // /2025/page/2/ -> segments[1] === 'page'
    // /2025/11/page/2/ -> segments[2] === 'page'
    $pageIndex = $month ? 2 : 1;
    if (isset($segments[$pageIndex]) && $segments[$pageIndex] === 'page' && isset($segments[$pageIndex + 1])) {
        $page = (int)$segments[$pageIndex + 1];
    } elseif (!empty($_GET['page'])) {
        $page = max(1, (int)$_GET['page']);
    }

    ArchiveController::show($pdo, $year, $month, $page, /*$basePathPrefix*/ null);
    exit;
}

// SITEMAP routes (no htaccess needed)
// /sitemap.xml
// /sitemap_posts_1.xml
// /sitemap_pages_2.xml
if (preg_match('#^sitemap(?:_(posts|pages)_(\d+))?\.xml$#', $pathTrimmed, $m)) {
    require_once __DIR__ . '/controllers/SitemapController.php';
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

// LIST ARTICLES (legacy /artikel/ or /posts/)
if ($prefix === 'artikel' || $prefix === 'posts') {
    require_once __DIR__ . '/controllers/PostController.php';

    // prefer query param ?page= but also support /artikel/page/2/ if present
    $page = 1;
    if (!empty($_GET['page'])) {
        $page = max(1, (int)$_GET['page']);
    } else {
        // detect /page/2/ in path segments: segments after prefix start at index 1
        $segmentsAfter = array_slice($segments, 1);
        foreach ($segmentsAfter as $idx => $seg) {
            if ($seg === 'page' && isset($segmentsAfter[$idx + 1]) && ctype_digit((string)$segmentsAfter[$idx + 1])) {
                $page = (int)$segmentsAfter[$idx + 1];
                break;
            }
        }
    }

    $q = trim((string)($_GET['q'] ?? ''));
    PostController::listArticles($pdo, $page, $q);
    exit;
}

// PAGES (list & single)
// /halaman/  -> listPages
// /halaman/page/2/ -> pagination
if ($prefix === 'halaman') {
    require_once __DIR__ . '/controllers/PageController.php';

    // detect page via /halaman/page/2/ or ?page=
    $page = 1;
    $segmentsAfter = array_slice($segments, 1);
    foreach ($segmentsAfter as $idx => $seg) {
        if ($seg === 'page' && isset($segmentsAfter[$idx + 1]) && ctype_digit((string)$segmentsAfter[$idx + 1])) {
            $page = (int)$segmentsAfter[$idx + 1];
            break;
        }
    }
    if (!empty($_GET['page'])) {
        $page = max(1, (int)$_GET['page']);
    }

    $q = trim((string)($_GET['q'] ?? ''));

    // show list of pages
    PageController::listPages($pdo, $page, $q);
    exit;
}

// GALLERY (PHOTO)
if ($prefix === 'gallery') {
    require_once __DIR__ . '/controllers/PhotoController.php';

    // ambil semua segmen setelah 'gallery'
    $pathSegs = array_slice($segments, 1);

    // pagination /page/N/
    $page = 1;
    $n = count($pathSegs);
    if ($n >= 2 && ($pathSegs[$n-2] ?? '') === 'page') {
        $page = max(1, (int)($pathSegs[$n-1] ?? 1));
        $pathSegs = array_slice($pathSegs, 0, $n-2);
    }

    $q = trim((string)($_GET['q'] ?? ''));

    // decode + bersihkan kosong
    $slugs = array_values(array_filter(array_map('rawurldecode', $pathSegs), fn($s)=>trim($s) !== ''));

    if (empty($slugs)) {
        // /gallery/
        PhotoController::index($pdo, '/gallery/', $q);
    } else {
        // /gallery/{parent}/{child}/.../
        PhotoController::showCategoryPath($pdo, $slugs, $page, $q, '/gallery/');
    }
    exit;
}

// FALLBACK POST
require_once __DIR__ . '/controllers/PostController.php';

$isLoggedIn = (function_exists('is_logged_in') && is_logged_in());
PostController::dispatchBySlug($pathTrimmed, $pdo, $isLoggedIn);