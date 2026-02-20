<?php
// layout.php (public) — sidebar + search/404 support
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// basic safety flag
if (!defined('APP_PUBLIC_LOADED')) {
    define('APP_PUBLIC_LOADED', true);
}

// load app bootstrap (adjust paths if needed)
require_once __DIR__ . '/bootstrap_core.php';
require_once __DIR__ . '/bootstrap_theme.php';

// Pastikan $pdo ada, tapi jangan menimpa $pdo yang sudah dibuat bootstrap_core.php
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = $GLOBALS['pdo'] ?? null;
}
if (!($pdo instanceof PDO)) {
    $pdo = null;
}

// 3) Ensure a context is set by the controller/router. Default to 'global'
$context_for_layout = $context_for_layout ?? 'global';

// 4) Page title / meta (override in controllers)
// Default dari DB (bisa dioverride controller via $page_title)
$db_default_title = ($pdo instanceof PDO && function_exists('settings_get'))
    ? (settings_get($pdo, 'site_title', 'Jyavani CMS v1') ?? 'Jyavani CMS v1')
    : 'Jyavani CMS';

$page_title = $page_title ?? $db_default_title;
// derive site url for context (used by template context)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// Host: biasanya selalu ada dari HTTP_HOST; DB dipakai sebagai fallback/kanonikal default
$db_default_host = ($pdo instanceof PDO && function_exists('settings_get'))
    ? (settings_get($pdo, 'site_host', 'v1.jyavani.com') ?? 'v1.jyavani.com')
    : 'v1.jyavani.com';

$host = $_SERVER['HTTP_HOST'] ?? $db_default_host;

// Optional: sanitasi host biar aman
$host = preg_replace('/[^a-z0-9\.\-:]/i', '', (string)$host);
$site_base_url = $scheme . '://' . $host;

// 5) Theme class (light/dark/system) from cookie
$themeClass = 'theme-light'; // default terang untuk first visit

if (!empty($_COOKIE['site-theme'])) {
    $ct = (string)$_COOKIE['site-theme'];
    if ($ct === 'dark') $themeClass = 'theme-dark';
    elseif ($ct === 'light') $themeClass = 'theme-light';
}

// ends_with helper
if (!function_exists('ends_with')) {
    function ends_with(string $haystack, string $needle): bool {
        if ($needle === '') return true;
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

/**
 * Determine the main slot for the current context.
 */
$main_slot = (function($ctx) {
    $ctx = $ctx ?? 'home';
    if ($ctx === '' || $ctx === 'home' || $ctx === 'global' || $ctx === 'homepage') {
        return 'main.homepage';
    } elseif (strpos($ctx, ':') !== false) {
        $parts = explode(':', $ctx, 2);
        $t = $parts[0] ?? '';
        $s = $parts[1] ?? '';
        return ($t !== '' && $s !== '') ? ($t . '.' . $s) : 'main.homepage';
    } elseif (strpos($ctx, '.') !== false) {
        return $ctx;
    } else {
        return 'main.' . $ctx;
    }
})($context_for_layout);
?><!doctype html>
<html lang="id"<?= $themeClass ? ' class="'.htmlspecialchars($themeClass, ENT_QUOTES, 'UTF-8').'"' : '' ?>>
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script src="/static/assets/js/main.js"></script>
  
<script>
// nav anime
  (function(){
    try{
      if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      document.documentElement.classList.add('nav-boot');
    }catch(e){}
  })();
</script>

  <!-- Favicons -->
  <link rel="shortcut icon" href="/static/img/favicon/favicon.ico">
  <link rel="icon" type="image/png" sizes="16x16" href="/static/img/favicon/favicon-16x16.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/static/img/favicon/favicon-32x32.png">
  <link rel="icon" href="/static/img/favicon/jyavani.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="/static/img/favicon/apple-touch-icon.png">
  <link rel="manifest" href="/static/img/favicon/site.webmanifest">
  
<meta name="theme-color" content="#ffffff">

<?php
// canonical URL
if (!empty($canonical_url)) {
    echo '<link rel="canonical" href="' . htmlspecialchars($canonical_url, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
} else {
    $currentPath = $_SERVER['REQUEST_URI'] ?? '/';
    $currentPathNoQs = preg_replace('/\?.*$/', '', $currentPath);
    if ($currentPathNoQs !== '/' && !ends_with($currentPathNoQs, '/')) {
        $currentPathNoQs .= '/';
    }
    $fallbackCanonical = $site_base_url . $currentPathNoQs;
    echo '<link rel="canonical" href="' . htmlspecialchars($fallbackCanonical, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
}
if (!empty($GLOBALS['rel_prev'])) echo '<link rel="prev" href="'.htmlspecialchars($GLOBALS['rel_prev'],ENT_QUOTES,'UTF-8').'">'.PHP_EOL;
if (!empty($GLOBALS['rel_next'])) echo '<link rel="next" href="'.htmlspecialchars($GLOBALS['rel_next'],ENT_QUOTES,'UTF-8').'">'.PHP_EOL;
if (!empty($GLOBALS['robots_meta'])) echo '<meta name="robots" content="'.htmlspecialchars($GLOBALS['robots_meta'],ENT_QUOTES,'UTF-8').'">'.PHP_EOL;
?>

<!-- CSS Global -->
<link rel="stylesheet" href="/static/assets/css/anime.css?v=12">
<link rel="stylesheet" href="/static/vendor/quill/quill.snow.pub.css">
<link rel="stylesheet" href="/static/assets/css/site.css?v=123">

  <!-- Google Fonts (optional) -->
  <link href="https://fonts.googleapis.com/css2?family=Comic+Neue:wght@300;400;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Taviraj:wght@600&display=swap" rel="stylesheet">

<?php
// THEME STYLES: attempt to load only styles declared/used on this page (header, main_slot, footer)
if (function_exists('echo_relevant_theme_styles')) {
    try {
        echo_relevant_theme_styles($pdo, ['header', $main_slot, 'footer']);
    } catch (Throwable $e) {
        if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[LAYOUT] echo_relevant_theme_styles error: ' . $e->getMessage());
        // fall through to fallback below
    }
}

// Fallback: if helper unavailable or produced nothing, try one candidate from default locations
if (!function_exists('echo_relevant_theme_styles')) {
    // ensure helper present
    if (!function_exists('theme_asset_url') && file_exists($maybe_helper)) {
        require_once $maybe_helper;
    }

    $theme_css_candidates = [
        'assets/css/style.css',
        'assets/css/main.css',
        'assets/css/site.css',
        'css/style.css', // legacy
    ];
    foreach ($theme_css_candidates as $c) {
        $url = function_exists('theme_asset_url') ? theme_asset_url($pdo, $c) : null;
        if ($url) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
            break;
        }
    }

    // optional print stylesheet fallback (legacy path)
    if (function_exists('theme_asset_url')) {
        $printUrl = theme_asset_url($pdo, 'assets/css/print.css') ?: theme_asset_url($pdo, 'css/print.css');
        if ($printUrl) echo '<link rel="stylesheet" href="'.htmlspecialchars($printUrl,ENT_QUOTES,'UTF-8').'" media="print">'.PHP_EOL;
    }
}
?>

</head>
<body>
<?php
// Build base context
$layout_data = $layout_data ?? [];
$base_context = array_merge(['context' => $context_for_layout, 'site' => ['url' => $site_base_url]], $layout_data);

// Header slot
try {
    echo render_slot($pdo, 'header', $base_context);
} catch (Throwable $e) {
    if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[LAYOUT] render_slot(header) error: ' . $e->getMessage());
}
?>

<main id="site-main" class="site-main" role="main">
  <div class="container">
<?php
// Main/content
if (!empty($content_html)) {
    echo $content_html;
} else {
    try {
        echo render_slot($pdo, $main_slot, $base_context);
    } catch (Throwable $e) {
        if (defined('THEME_DEBUG') && THEME_DEBUG) {
            error_log('[LAYOUT] render_slot(main) error: ' . $e->getMessage());
            echo '<div class="alert alert-warning">Main template error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }
}
?>
  </div>
</main>

<?php
// Footer slot
try {
    echo render_slot($pdo, 'footer', $base_context);
} catch (Throwable $e) {
    if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[LAYOUT] render_slot(footer) error: ' . $e->getMessage());
}
?>

<!-- small footer script area -->
<script src="/static/assets/js/anime.js?v=1"></script>
<script src="/static/assets/js/site.js?v=1"></script>

<?php
// THEME SCRIPTS: load scripts for themes actually used on this page
if (function_exists('echo_relevant_theme_scripts')) {
    try {
        echo_relevant_theme_scripts($pdo, ['header', $main_slot, 'footer']);
    } catch (Throwable $e) {
        if (defined('THEME_DEBUG') && THEME_DEBUG) error_log('[LAYOUT] echo_relevant_theme_scripts error: ' . $e->getMessage());
    }
} else {
    // fallback: try theme_asset_url candidates (ensure correct assets path)
    if (!function_exists('theme_asset_url') && file_exists($maybe_helper)) {
        require_once $maybe_helper;
    }
    $theme_js_candidates = [
        'assets/js/script.js',
        'assets/js/main.js',
        'assets/js/site.js',
        'js/script.js' // legacy fallback
    ];
    foreach ($theme_js_candidates as $c) {
        $url = function_exists('theme_asset_url') ? theme_asset_url($pdo, $c) : null;
        if ($url) {
            echo '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>' . PHP_EOL;
            break;
        }
    }
}
?>
</body>
</html>