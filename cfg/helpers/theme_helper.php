<?php
declare(strict_types=1);

if (
    PHP_SAPI !== 'cli' &&
    realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__
) {
    http_response_code(404);
    require __DIR__ . '/../../app/frontend_404.php';
    exit;
}

/**
 * theme_helper.php
 *
 * - Simplified: removed 'hero' concept, added 'sidebar', 'main.search', 'main.404'.
 * - Keeps install_theme_from_zip, manifest reading, registration, assignment helpers.
 *
 * Usage:
 *   require_once __DIR__ . '/theme_helper.php';
 */

if (defined('THEME_HELPER_INCLUDED')) {
    return;
}
define('THEME_HELPER_INCLUDED', true);

if (!defined('THEME_DEBUG')) define('THEME_DEBUG', true);
if (THEME_DEBUG) {
    @ini_set('display_errors', '1');
    @error_reporting(E_ALL);
}

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', realpath(__DIR__) ?: __DIR__);
}

/* VIEWS_BASE: single source of truth */
if (!defined('VIEWS_BASE')) {
    define(
        'VIEWS_BASE',
        realpath(PUBLIC_PATH . '/views/themes')
    );
}

if (!defined('DEFAULT_THEME_FOLDER')) {
    define('DEFAULT_THEME_FOLDER', 'default');
}

////////////////////////////////////////////////////////////////////////////////
// Safe strip_tags — removes <style>/<script> blocks before strip_tags
////////////////////////////////////////////////////////////////////////////////

if (!function_exists('safe_strip_tags')) {
    function safe_strip_tags(string $html): string {
        $text = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $text = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $text);
        return strip_tags($text);
    }
}

////////////////////////////////////////////////////////////////////////////////
// Utilities
////////////////////////////////////////////////////////////////////////////////

if (!function_exists('slot_to_file')) {
    function slot_to_file(string $slot_key): string {
        $map = [
            'header' => 'header.php',
            'footer' => 'footer.php',
            'sidebar' => 'sidebar.php',

            'main.homepage' => 'main/homepage.php',
            'main.search'   => 'main/search.php',
            'main.404'      => 'main/404.php',

            'list.post'     => 'main/list/post.php',
            'list.page'     => 'main/list/page.php',
            'list.category' => 'main/list/category.php',
            'list.archive'  => 'main/list/archive.php',
            'list.author'   => 'main/list/author.php',

            'single.post'   => 'main/single/post.php',
            'single.page'   => 'main/single/page.php',

            'index.category'=> 'main/index/category.php',
            'index.author'  => 'main/index/author.php',

        ];
        return $map[$slot_key] ?? (str_replace([':', '/'], '.', $slot_key) . '.php');
    }
}

function get_pdo_from_global(): ?PDO {
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    return null;
}

function normalize_relative_path(string $path): string {
    $path = ltrim($path, "/\\");
    if ($path === '') return '';
    $parts = preg_split('#[\\/\\\\]+#', $path, -1, PREG_SPLIT_NO_EMPTY);
    $stack = [];
    foreach ($parts as $part) {
        if ($part === '.' || $part === '') continue;
        if ($part === '..') {
            if (!empty($stack)) array_pop($stack);
            continue;
        }
        $stack[] = $part;
    }
    return implode(DIRECTORY_SEPARATOR, $stack);
}

function path_candidate(string $base, string $folder, string $relative): string {
    $base = rtrim($base, "/\\");
    $folder = trim($folder, "/\\");
    $relative = normalize_relative_path($relative);
    return $base . DIRECTORY_SEPARATOR . ($folder === '' ? '' : ($folder . DIRECTORY_SEPARATOR)) . $relative;
}

/**
 * Return array of theme folders that are relevant for this request.
 * - Collects: folders referenced in assignments (for slots), plus active theme, plus DEFAULT_THEME_FOLDER.
 * - $slot_keys optional: list of slots to consider (if null, we consider all assignments in DB).
 * Returns ordered unique array: [default, ...assigned..., active]
 */
function get_relevant_theme_folders($pdoOrNull, ?array $slot_keys = null): array {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $folders = [];

    // ensure default present
    $default = defined('DEFAULT_THEME_FOLDER') ? DEFAULT_THEME_FOLDER : 'default';
    if ($default) $folders[] = $default;

    if ($pdo) {
        // pull assignments optionally filtering by slot_keys
        if (is_array($slot_keys) && count($slot_keys) > 0) {
            $placeholders = implode(',', array_fill(0, count($slot_keys), '?'));
            $sql = "SELECT DISTINCT theme_id FROM assignments WHERE theme_id IS NOT NULL AND slot_key IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($slot_keys);
        } else {
            $stmt = $pdo->prepare("SELECT DISTINCT theme_id FROM assignments WHERE theme_id IS NOT NULL");
            $stmt->execute();
        }
        $themeIds = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($r['theme_id'])) $themeIds[] = (int)$r['theme_id'];
        }
        foreach ($themeIds as $tid) {
            $t = get_theme_by_id($pdo, $tid);
            if ($t && !empty($t['folder_name']) && !in_array($t['folder_name'], $folders, true)) {
                $folders[] = $t['folder_name'];
            }
        }

        // include active theme last (so its CSS can override earlier ones)
        try {
            $stmt2 = $pdo->prepare("SELECT folder_name FROM themes WHERE is_active = 1 LIMIT 1");
            $stmt2->execute();
            $r2 = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($r2 && !empty($r2['folder_name']) && !in_array($r2['folder_name'], $folders, true)) {
                $folders[] = $r2['folder_name'];
            }
        } catch (Throwable $e) {
            if (defined('THEME_DEBUG') && THEME_DEBUG) error_log("[THEME] get_relevant_theme_folders error: " . $e->getMessage());
        }
    }

    // ensure final fallback default exists once
    $folders = array_values(array_unique($folders, SORT_REGULAR));
    return $folders;
}

/**
 * Given folder names, collect styles/scripts URLs declared in manifest or fallback candidates.
 * Returns ['styles' => [...], 'scripts' => [...]] (unique, ordered).
 *
 * Important: this resolves assets *inside each folder explicitly*, so different themes with the
 * same relative filenames will yield different URLs (no accidental override by active theme).
 */
function collect_theme_asset_urls($pdoOrNull, array $folders): array {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $foundStyles = [];
    $foundScripts = [];
    $seen = ['styles' => [], 'scripts' => []];

    // fallback candidates if manifest doesn't declare assets (relative to theme folder)
    $styleCandidates = ['assets/css/style.css','assets/css/main.css','assets/css/site.css','css/style.css'];
    $scriptCandidates = ['assets/js/script.js','assets/js/main.js','assets/js/app.js','js/script.js'];

    // helper: convert an absolute FS path to a web URL (or null)
    $fs_to_url = function(string $fsPath) {
        $publicReal = realpath(PUBLIC_PATH) ?: null;
        $fsReal = realpath($fsPath) ?: null;
        if (!$publicReal || !$fsReal) return null;
        if (strpos($fsReal, $publicReal) !== 0) return null;
        $webPath = str_replace('\\', '/', substr($fsReal, strlen($publicReal)));
        if ($webPath === '' || $webPath[0] !== '/') $webPath = '/' . ltrim($webPath, '/');
        $v = @filemtime($fsReal) ?: time();
        return $webPath;
    };

    foreach ($folders as $folder) {
        // fetch manifest (DB snapshot preferred, fallback to FS)
        $manifest = [];
        $t = $pdo ? get_theme_by_folder($pdo, $folder) : null;
        if ($t && !empty($t['manifest_json'])) {
            $tmp = @json_decode($t['manifest_json'], true);
            if (is_array($tmp)) $manifest = $tmp;
        }
        try {
            $fsMan = read_theme_manifest(path_candidate(VIEWS_BASE, $folder, ''));
            if (is_array($fsMan)) $manifest = array_merge($fsMan, $manifest);
        } catch (Throwable $e) {
            // ignore
        }

        // determine style/script lists for this folder
        $styles = (!empty($manifest['styles']) && is_array($manifest['styles'])) ? $manifest['styles'] : $styleCandidates;
        $scripts = (!empty($manifest['scripts']) && is_array($manifest['scripts'])) ? $manifest['scripts'] : $scriptCandidates;

        // normalize and resolve each style relative to THIS folder
        foreach ($styles as $s) {
            $sRel = normalize_relative_path((string)$s);
            if ($sRel === '') continue;
            if (!preg_match('#^assets[\\/\\\\]#i', $sRel)) {
                // allow both 'css/foo.css' or 'assets/css/foo.css'
                if (preg_match('#^css[\\/\\\\]#i', $sRel) || preg_match('#^style#i', $sRel)) {
                    $sRel = 'assets' . DIRECTORY_SEPARATOR . $sRel;
                } else {
                    $sRel = $sRel; // leave as-is (developer might provide full relative path)
                }
            }
            $candidateFs = path_candidate(VIEWS_BASE, $folder, $sRel);
            $real = realpath($candidateFs);
            if ($real && is_file($real)) {
                $url = $fs_to_url($real);
                if ($url && !in_array($url, $seen['styles'], true)) {
                    $foundStyles[] = $url;
                    $seen['styles'][] = $url;
                }
            } else {
                // try alternative without 'assets/' in case manifest used non-assets path
                $altRel = normalize_relative_path((string)$s);
                $candidateAlt = path_candidate(VIEWS_BASE, $folder, $altRel);
                $realAlt = realpath($candidateAlt);
                if ($realAlt && is_file($realAlt)) {
                    $url = $fs_to_url($realAlt);
                    if ($url && !in_array($url, $seen['styles'], true)) {
                        $foundStyles[] = $url;
                        $seen['styles'][] = $url;
                    }
                }
            }
        }

        // same for scripts
        foreach ($scripts as $s) {
            $sRel = normalize_relative_path((string)$s);
            if ($sRel === '') continue;
            if (!preg_match('#^assets[\\/\\\\]#i', $sRel)) {
                if (preg_match('#^js[\\/\\\\]#i', $sRel) || preg_match('#^script#i', $sRel)) {
                    $sRel = 'assets' . DIRECTORY_SEPARATOR . $sRel;
                } else {
                    $sRel = $sRel;
                }
            }
            $candidateFs = path_candidate(VIEWS_BASE, $folder, $sRel);
            $real = realpath($candidateFs);
            if ($real && is_file($real)) {
                $url = $fs_to_url($real);
                if ($url && !in_array($url, $seen['scripts'], true)) {
                    $foundScripts[] = $url;
                    $seen['scripts'][] = $url;
                }
            } else {
                $altRel = normalize_relative_path((string)$s);
                $candidateAlt = path_candidate(VIEWS_BASE, $folder, $altRel);
                $realAlt = realpath($candidateAlt);
                if ($realAlt && is_file($realAlt)) {
                    $url = $fs_to_url($realAlt);
                    if ($url && !in_array($url, $seen['scripts'], true)) {
                        $foundScripts[] = $url;
                        $seen['scripts'][] = $url;
                    }
                }
            }
        }
    }

    return ['styles' => $foundStyles, 'scripts' => $foundScripts];
}

/**
 * Echo <link> tags for relevant theme styles (to be called in <head>).
 * $slot_keys optional: if you only render header+main+footer you can pass them to limit which assignments are considered.
 */
function echo_relevant_theme_styles($pdoOrNull = null, ?array $slot_keys = null): void {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $folders = get_relevant_theme_folders($pdo, $slot_keys);
    $assets = collect_theme_asset_urls($pdo, $folders);
    foreach ($assets['styles'] as $cssUrl) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }
}

/**
 * Echo <script> tags for relevant theme scripts (to be called before </body>).
 */
function echo_relevant_theme_scripts($pdoOrNull = null, ?array $slot_keys = null): void {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $folders = get_relevant_theme_folders($pdo, $slot_keys);
    $assets = collect_theme_asset_urls($pdo, $folders);
    foreach ($assets['scripts'] as $jsUrl) {
        echo '<script src="' . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8') . '"></script>' . PHP_EOL;
    }
}


////////////////////////////////////////////////////////////////////////////////
// Simple per-request cache (fixed invalidation)
////////////////////////////////////////////////////////////////////////////////
function &db_cache(): array {
    static $cache = [
        'assignments' => [],
        'themes_by_id' => [],
        'themes_by_folder' => [],
        'posts' => [],
    ];
    return $cache;
}

function db_cache_has(string $k, string $subkey): bool {
    $c = &db_cache();
    return isset($c[$k]) && isset($c[$k][$subkey]);
}

function db_cache_set(string $k, string $subkey, $value): void {
    $c = &db_cache();
    if (!isset($c[$k]) || !is_array($c[$k])) $c[$k] = [];
    if ($value === null) {
        if (isset($c[$k][$subkey])) {
            unset($c[$k][$subkey]);
        }
        return;
    }
    $c[$k][$subkey] = $value;
}

function db_cache_get(string $k, string $subkey) {
    $c = &db_cache();
    if (isset($c[$k]) && array_key_exists($subkey, $c[$k])) {
        return $c[$k][$subkey];
    }
    return null;
}

////////////////////////////////////////////////////////////////////////////////
// DB wrappers (unchanged)
////////////////////////////////////////////////////////////////////////////////
function get_assignment($pdoOrNull, string $slot_key) {
    if (db_cache_has('assignments', $slot_key)) {
        return db_cache_get('assignments', $slot_key);
    }
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) {
        db_cache_set('assignments', $slot_key, null);
        return null;
    }
    $sql = "SELECT * FROM assignments WHERE slot_key = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$slot_key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    db_cache_set('assignments', $slot_key, $row);
    return $row;
}

function get_theme_by_id($pdoOrNull, int $theme_id) {
    $key = (string)$theme_id;
    if (db_cache_has('themes_by_id', $key)) {
        return db_cache_get('themes_by_id', $key);
    }
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) {
        db_cache_set('themes_by_id', $key, null);
        return null;
    }
    $sql = "SELECT * FROM themes WHERE id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$theme_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    db_cache_set('themes_by_id', $key, $row);
    return $row;
}

function get_theme_by_folder($pdoOrNull, string $folder) {
    if (db_cache_has('themes_by_folder', $folder)) {
        return db_cache_get('themes_by_folder', $folder);
    }
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) {
        db_cache_set('themes_by_folder', $folder, null);
        return null;
    }
    $sql = "SELECT * FROM themes WHERE folder_name = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$folder]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    db_cache_set('themes_by_folder', $folder, $row);
    return $row;
}

function get_post_by_id($pdoOrNull, int $post_id) {
    $key = (string)$post_id;
    if (db_cache_has('posts', $key)) {
        return db_cache_get('posts', $key);
    }
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) {
        db_cache_set('posts', $key, null);
        return null;
    }
    $sql = "SELECT * FROM posts WHERE id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$post_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    db_cache_set('posts', $key, $row);
    return $row;
}

////////////////////////////////////////////////////////////////////////////////
// Resolve template (semantics preserved)
// - resolve_template checks assignments -> custom_post -> theme_file
// - resolve_theme_file_path attempts theme folder then default theme fallback
// - include_template_file isolates include with extracted context
////////////////////////////////////////////////////////////////////////////////

function resolve_template($pdoOrNull, string $slot_key): array {
    $pdo = $pdoOrNull ?: get_pdo_from_global();

    if ($pdo) {
        $assign = get_assignment($pdo, $slot_key);
        if ($assign) {
            if (!empty($assign['custom_post_id'])) {
                $post = get_post_by_id($pdo, (int)$assign['custom_post_id']);
                if ($post && (($post['type'] ?? '') === 'theme')) {
                    return ['type' => 'custom_post', 'post' => $post];
                }
            }
            if (!empty($assign['theme_id'])) {
                $theme = get_theme_by_id($pdo, (int)$assign['theme_id']);
                $theme_folder = $theme ? $theme['folder_name'] : null;
                $theme_file = ($assign['theme_file'] ?? '') ?: slot_to_file($slot_key);
                if ($theme_folder) {
                    return ['type' => 'theme_file', 'theme_folder' => (string)$theme_folder, 'theme_file' => (string)$theme_file];
                }
            }
        }
    }

    $site_theme = DEFAULT_THEME_FOLDER;
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT folder_name FROM themes WHERE is_active = 1 LIMIT 1");
            $stmt->execute();
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && !empty($r['folder_name'])) $site_theme = $r['folder_name'];
        } catch (Throwable $e) {
            if (THEME_DEBUG) error_log("[THEME] get active theme error: " . $e->getMessage());
        }
    }
    $theme_folder = $site_theme;
    if ($pdo) {
        $t = get_theme_by_folder($pdo, $site_theme);
        if ($t && !empty($t['folder_name'])) $theme_folder = $t['folder_name'];
    }
    $theme_file = slot_to_file($slot_key);
    return ['type' => 'theme_file', 'theme_folder' => (string)$theme_folder, 'theme_file' => (string)$theme_file];
}

function resolve_theme_file_path(array $resolved): ?string {
    $folder = $resolved['theme_folder'] ?? DEFAULT_THEME_FOLDER;
    $file = $resolved['theme_file'] ?? '';
    $file_rel = normalize_relative_path((string)$file);
    $candidate = path_candidate(VIEWS_BASE, (string)$folder, $file_rel);
    $candidate_real = realpath($candidate);
    $base_real = realpath(VIEWS_BASE);

    if ($candidate_real && $base_real && strpos($candidate_real, $base_real) === 0 && is_file($candidate_real)) {
        return $candidate_real;
    }

    $fallback = path_candidate(VIEWS_BASE, DEFAULT_THEME_FOLDER, $file_rel);
    $fallback_real = realpath($fallback);
    if ($fallback_real && $base_real && strpos($fallback_real, $base_real) === 0 && is_file($fallback_real)) {
        if (THEME_DEBUG) error_log("[THEME] using fallback for file={$file} (fallback={$fallback_real})");
        return $fallback_real;
    }

    if (THEME_DEBUG) {
        error_log("[THEME] resolve_theme_file_path failed candidate={$candidate} fallback={$fallback}");
    }
    return null;
}

function include_template_file(string $path, array $context = []): string {
    if (!is_file($path)) return '';
    global $pdo;
    $__ctx = $context;
    $__ctx['pdo'] = $pdo;
    ob_start();
    try {
        extract($__ctx, EXTR_SKIP);
        include $path;
    } catch (Throwable $e) {
        ob_end_clean();
        error_log('[THEME] include_template_file error: ' . $e->getMessage());
        return '';
    }
    $html = ob_get_clean();

if ($pdo instanceof PDO) {
    if (function_exists('widget_expand_shortcodes')) {
        $html = widget_expand_shortcodes((string)$html, $pdo, $context);
    }
    if (function_exists('post_cat_shortcode_expand')) {
        $html = post_cat_shortcode_expand((string)$html, $pdo, $context);
    }
    if (function_exists('private_file_shortcode_expand')) {
        $html = private_file_shortcode_expand((string)$html, $pdo, $context);
    }
    if (function_exists('video_shortcode_expand')) {
        $html = video_shortcode_expand((string)$html, $pdo);
    }
}

    return (string)$html;
}

function render_custom_post_template(array $post, array $context = []): string {
    $content = (string)($post['content'] ?? '');
    $ctx = $context;
    $ctx['post'] = $post;

    $result = preg_replace_callback('/\{\{\s*([^}\s]+(?:\.[^}\s]+)*)\s*\}\}/', function($m) use ($ctx) {
        $path = $m[1];
        $parts = explode('.', $path);
        $val = $ctx;
        foreach ($parts as $p) {
            if (is_array($val) && array_key_exists($p, $val)) {
                $val = $val[$p];
            } elseif (is_object($val) && isset($val->{$p})) {
                $val = $val->{$p};
            } else {
                return '';
            }
        }
        if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
            return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return '';
    }, $content);
    
if (function_exists('widget_expand_shortcodes')) {
    $pdo = get_pdo_from_global();
    $result = widget_expand_shortcodes((string)$result, $pdo, $ctx);
}

if (function_exists('post_cat_shortcode_expand')) {
    $pdo = get_pdo_from_global();
    if ($pdo instanceof PDO) {
        $result = post_cat_shortcode_expand((string)$result, $pdo, $ctx);
    }
}

if (function_exists('private_file_shortcode_expand')) {
    $pdo = get_pdo_from_global();
    if ($pdo instanceof PDO) {
        $result = private_file_shortcode_expand((string)$result, $pdo, $ctx);
    }
}

if (function_exists('video_shortcode_expand')) {
    $pdo = get_pdo_from_global();
    if ($pdo instanceof PDO) {
        $result = video_shortcode_expand((string)$result, $pdo);
    }
}

    return $result;
}

function render_slot($pdoOrNull, string $slot_key, array $context = []): string {
    $pdo = $pdoOrNull instanceof PDO ? $pdoOrNull : ($pdoOrNull ?: get_pdo_from_global());
    $resolved = resolve_template($pdo, $slot_key);

    if ($resolved['type'] === 'custom_post') {
        return render_custom_post_template($resolved['post'], $context);
    } elseif ($resolved['type'] === 'theme_file') {
        $path = resolve_theme_file_path($resolved);
        if ($path) {
            return include_template_file($path, $context);
        } else {
            if (THEME_DEBUG) error_log("[THEME] render_slot - file not found for slot={$slot_key}");
        }
    }
    return '';
}

/**
 * render_page:
 * - Chooses main slot based on context, including 'search' and '404' mappings.
 * - Renders header, the resolved main slot, and footer.
 * - Sidebar is intended to be rendered by the layout (layout.php) via render_slot('sidebar').
 */
function render_page($pdoOrNull = null, ?string $contextKey = 'home', array $data = []): void {
    $pdo = $pdoOrNull instanceof PDO ? $pdoOrNull : ($pdoOrNull ?: get_pdo_from_global());
    $ctx = $contextKey ?? 'home';
    $base_context = array_merge(['context' => $ctx, 'site' => ['url' => ($_SERVER['HTTP_HOST'] ?? '')]], $data);

    echo render_slot($pdo, 'header', $base_context);

    // Determine main slot
    $ctxLower = strtolower((string)$ctx);
    if ($ctxLower === '404' || $ctxLower === 'notfound' || $ctxLower === 'not-found') {
        $main_slot = 'main.404';
    } elseif ($ctxLower === 'search' || strpos($ctxLower, 'search') === 0) {
        $main_slot = 'main.search';
    } elseif ($ctx === '' || $ctx === 'home' || $ctx === 'global' || $ctx === 'homepage') {
        $main_slot = 'main.homepage';
    } elseif (strpos($ctx, ':') !== false) {
        $parts = explode(':', $ctx, 2);
        $t = $parts[0] ?? '';
        $s = $parts[1] ?? '';
        $main_slot = ($t !== '' && $s !== '') ? ($t . '.' . $s) : 'main.homepage';
    } elseif (strpos($ctx, '.') !== false) {
        $main_slot = $ctx;
    } else {
        $main_slot = 'main.' . $ctx;
    }

    echo render_slot($pdo, $main_slot, $base_context);
    echo render_slot($pdo, 'footer', $base_context);
}

////////////////////////////////////////////////////////////////////////////////
// Assignment functions (unchanged semantics)
////////////////////////////////////////////////////////////////////////////////

function sanitize_theme_file_for_db(string $theme_file): string {
    return normalize_relative_path($theme_file);
}

function assign_theme_to_slot($pdoOrNull, string $slot_key, string $theme_folder, ?string $theme_file = null, ?int $by_user_id = null): bool {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) throw new RuntimeException('PDO required for assign_theme_to_slot');

    $theme = get_theme_by_folder($pdo, $theme_folder);
    if (!$theme) throw new RuntimeException("Theme folder not found: $theme_folder");

    $theme_id = (int)$theme['id'];
    $file = $theme_file ? sanitize_theme_file_for_db($theme_file) : slot_to_file($slot_key);

    $sql = "INSERT INTO assignments (slot_key, theme_id, theme_file, custom_post_id, created_by)
            VALUES (?, ?, ?, NULL, ?)
            ON DUPLICATE KEY UPDATE theme_id = VALUES(theme_id), theme_file = VALUES(theme_file), custom_post_id = NULL, updated_at = CURRENT_TIMESTAMP";
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([$slot_key, $theme_id, $file, $by_user_id]);
    if ($ok) {
        db_cache_set('assignments', $slot_key, null);
    }
    return (bool)$ok;
}

function assign_custom_post_to_slot($pdoOrNull, string $slot_key, int $post_id, ?int $by_user_id = null): bool {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) throw new RuntimeException('PDO required for assign_custom_post_to_slot');

    $post = get_post_by_id($pdo, $post_id);
    if (!$post || (($post['type'] ?? '') !== 'theme')) {
        throw new RuntimeException("Custom theme post not found or not type='theme': $post_id");
    }

    $sql = "INSERT INTO assignments (slot_key, theme_id, theme_file, custom_post_id, created_by)
            VALUES (?, NULL, NULL, ?, ?)
            ON DUPLICATE KEY UPDATE custom_post_id = VALUES(custom_post_id), theme_id = NULL, theme_file = NULL, updated_at = CURRENT_TIMESTAMP";
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([$slot_key, $post_id, $by_user_id]);
    if ($ok) {
        db_cache_set('assignments', $slot_key, null);
    }
    return (bool)$ok;
}

function clear_assignment($pdoOrNull, string $slot_key): bool {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) throw new RuntimeException('PDO required for clear_assignment');
    $sql = "DELETE FROM assignments WHERE slot_key = ?";
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([$slot_key]);
    if ($ok) {
        db_cache_set('assignments', $slot_key, null);
    }
    return (bool)$ok;
}

////////////////////////////////////////////////////////////////////////////////
// Theme registration & activation (new behaviour)
////////////////////////////////////////////////////////////////////////////////

function read_theme_manifest(string $folderPath): array {
    $manifest = ['name' => basename($folderPath), 'description' => '', 'version' => '', 'author' => '', 'is_active' => false, 'screenshot' => ''];
    $path = rtrim($folderPath, "/\\") . DIRECTORY_SEPARATOR . 'theme.json';
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $j = @json_decode($raw, true);
        if (is_array($j)) {
            $manifest['name'] = $j['name'] ?? $manifest['name'];
            $manifest['description'] = $j['description'] ?? $manifest['description'];
            $manifest['version'] = $j['version'] ?? $manifest['version'];
            $manifest['author'] = $j['author'] ?? $manifest['author'];
            $manifest['is_active'] = !empty($j['is_active']);
            $manifest['screenshot'] = $j['screenshot'] ?? ($j['image'] ?? $manifest['screenshot']);
            // optionally include folder property if present
            if (isset($j['folder'])) $manifest['folder'] = $j['folder'];
            // optional arrays for assets
            if (!empty($j['styles']) && is_array($j['styles'])) $manifest['styles'] = $j['styles'];
            if (!empty($j['scripts']) && is_array($j['scripts'])) $manifest['scripts'] = $j['scripts'];
            // store block for update checking
            if (!empty($j['store']) && is_array($j['store'])) $manifest['store'] = $j['store'];
        }
    }
    return $manifest;
}

/**
 * Very small manifest validator — requires at least 'name' (string) and 'version' (semver-ish).
 */
function validate_theme_manifest(array $m): array {
    $errors = [];
    if (empty($m['name']) || !is_string($m['name'])) {
        $errors[] = 'Missing or invalid "name" in theme.json';
    }
    if (empty($m['version']) || !is_string($m['version'])) {
        $errors[] = 'Missing or invalid "version" in theme.json';
    } else {
        // simple semver-ish check (accepts 1.0.0 or 1.0)
        if (!preg_match('/^\d+\.\d+(\.\d+)?([-\+].+)?$/', $m['version'])) {
            // not fatal, but warn - still accept but add message
            if (THEME_DEBUG) error_log('[THEME] manifest version does not look semver-like: ' . $m['version']);
        }
    }
    return $errors;
}

function sanitize_folder_name(string $name): string {
    $n = trim((string)$name);
    $n = strtolower($n);
    // allow a-z0-9 . _ -
    $n = preg_replace('/[^a-z0-9._-]+/i', '-', $n);
    $n = trim($n, '-._');
    if ($n === '') $n = 'theme-' . bin2hex(random_bytes(4));
    return $n;
}

function register_theme_in_db($pdoOrNull, string $folderName, array $manifest = [], bool $is_active = false): bool {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) throw new RuntimeException('PDO required for register_theme_in_db');

    $displayName = $manifest['name'] ?? $folderName;
    $description = $manifest['description'] ?? '';
    $version = $manifest['version'] ?? '';
    $author = $manifest['author'] ?? '';
    $screenshot = $manifest['screenshot'] ?? null;
    $manifest_json = json_encode($manifest, JSON_UNESCAPED_UNICODE);

    $storeUrl = $manifest['store']['url'] ?? '';
    $storeSlug = $manifest['store']['slug'] ?? '';

    // Don't override is_system from manifest — it's a CMS-admin flag only
    $isSystemVal = 0;
    $prevActive = null;
    try {
        $existing = $pdo->prepare("SELECT is_system, is_active FROM themes WHERE folder_name = ? LIMIT 1");
        $existing->execute([$folderName]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $isSystemVal = (int)$row['is_system'];
            $prevActive = (int)$row['is_active'];
        }
    } catch (Throwable $e) {}

    // Preserve existing is_active unless manifest explicitly sets it
    if ($prevActive !== null && empty($manifest['is_active'])) {
        $is_active = (bool)$prevActive;
    }

    $sql = "INSERT INTO themes (folder_name, name, description, version, author, screenshot, manifest_json, is_active, store_url, store_slug, scanned_at)
            VALUES (:folder, :name, :desc, :version, :author, :screenshot, :manifest_json, :active, :storeUrl, :storeSlug, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
              name = VALUES(name),
              description = VALUES(description),
              version = VALUES(version),
              author = VALUES(author),
              screenshot = VALUES(screenshot),
              manifest_json = VALUES(manifest_json),
              store_url = VALUES(store_url),
              store_slug = VALUES(store_slug),
              scanned_at = CURRENT_TIMESTAMP,
              is_active = VALUES(is_active),
              updated_at = CURRENT_TIMESTAMP";
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([
        ':folder' => $folderName,
        ':name' => $displayName,
        ':desc' => $description,
        ':version' => $version,
        ':author' => $author,
        ':screenshot' => $screenshot,
        ':manifest_json' => $manifest_json,
        ':active' => $is_active ? 1 : 0,
        ':storeUrl' => $storeUrl,
        ':storeSlug' => $storeSlug,
    ]);

    // Preserve is_system across re-registrations
    if ($isSystemVal) {
        $pdo->prepare("UPDATE themes SET is_system = 1 WHERE folder_name = ? LIMIT 1")->execute([$folderName]);
    }

    db_cache_set('themes_by_folder', $folderName, null);
    return (bool)$ok;
}

function register_all_themes_from_fs($pdoOrNull = null): array {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $registered = [];
    $base = VIEWS_BASE;
    if (!is_dir($base)) return $registered;

    $dirs = array_filter(glob($base . '/*'), 'is_dir');
    foreach ($dirs as $d) {
        $folder = basename($d);
        $manifest = read_theme_manifest($d);
        try {
            register_theme_in_db($pdo, $folder, $manifest, !empty($manifest['is_active']));
            $registered[] = $folder;
            if (!empty($manifest['is_active'])) {
                try {
                    set_site_active_theme($pdo, $folder);
                } catch (Throwable $e) {
                    if (THEME_DEBUG) error_log("[THEME] set_site_active_theme failed for {$folder}: " . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            if (THEME_DEBUG) error_log("[THEME] register theme failed for {$folder}: " . $e->getMessage());
        }
    }

    db_cache_set('themes_by_folder', '', null);
    db_cache_set('themes_by_id', '', null);
    return $registered;
}

function get_registered_themes($pdoOrNull = null): array {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) return [];
    $sql = "SELECT * FROM themes ORDER BY is_active DESC, name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * set_site_active_theme:
 * - toggles themes.is_active flags inside a transaction
 * - does NOT touch any `settings` table
 */
function set_site_active_theme($pdoOrNull, string $folder): bool {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) throw new RuntimeException('PDO required for set_site_active_theme');

    $theme = get_theme_by_folder($pdo, $folder);
    if (!$theme) {
        $fsPath = path_candidate(VIEWS_BASE, $folder, '');
        if (is_dir($fsPath) || is_dir(realpath($fsPath))) {
            try {
                $manifest = read_theme_manifest($fsPath);
                register_theme_in_db($pdo, $folder, $manifest, false);
                $theme = get_theme_by_folder($pdo, $folder);
            } catch (Throwable $e) {
                if (defined('THEME_DEBUG') && THEME_DEBUG) error_log("[THEME] auto-register failed for {$folder}: " . $e->getMessage());
            }
        }
    }

    if (!$theme) {
        throw new RuntimeException("Unknown theme folder: {$folder}");
    }

    try {
        $pdo->beginTransaction();
        $stmt0 = $pdo->prepare("UPDATE themes SET is_active = 0 WHERE is_active = 1");
        $stmt0->execute();
        $stmt1 = $pdo->prepare("UPDATE themes SET is_active = 1 WHERE folder_name = ? LIMIT 1");
        $stmt1->execute([$folder]);
        $pdo->commit();

        db_cache_set('themes_by_folder', $folder, null);
        db_cache_set('themes_by_id', (string)$theme['id'], null);

        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function bulk_assign_theme($pdoOrNull, string $theme_folder, ?array $slots = null, ?int $by_user_id = null): bool {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) throw new RuntimeException('PDO required for bulk_assign_theme');

    $theme = get_theme_by_folder($pdo, $theme_folder);

    if (!$theme) {
        try {
            register_all_themes_from_fs($pdo);
        } catch (Throwable $e) {
            if (THEME_DEBUG) error_log("[THEME] register_all_themes_from_fs failed: " . $e->getMessage());
        }
        $theme = get_theme_by_folder($pdo, $theme_folder);
        if (!$theme) {
            throw new RuntimeException("Theme folder not found: $theme_folder");
        }
    }

    // Default slots updated: remove 'hero', add 'sidebar', 'main.search', 'main.404'
    $default_slots = [
        'header','footer','sidebar',
        'main.homepage','main.search','main.404',
        'list.post','list.page','list.category','list.archive','list.author',
        'single.post','single.page',
        'index.category','index.author'
    ];
    $use_slots = $slots ?? $default_slots;

    $okAll = true;
    foreach ($use_slots as $slot) {
        try {
            assign_theme_to_slot($pdo, $slot, $theme_folder, null, $by_user_id);
        } catch (Throwable $e) {
            $okAll = false;
            if (THEME_DEBUG) error_log("[THEME] bulk assign failed for slot {$slot}: " . $e->getMessage());
        }
    }
    return $okAll;
}

////////////////////////////////////////////////////////////////////////////////
// Asset helpers
////////////////////////////////////////////////////////////////////////////////

function get_active_theme_folder($pdoOrNull = null): string {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $folder = DEFAULT_THEME_FOLDER;
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT folder_name FROM themes WHERE is_active = 1 LIMIT 1");
            $stmt->execute();
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && !empty($r['folder_name'])) $folder = (string)$r['folder_name'];
        } catch (Throwable $e) {
            if (THEME_DEBUG) error_log("[THEME] get_active_theme_folder error: " . $e->getMessage());
        }
    }
    $maybe = path_candidate(VIEWS_BASE, $folder, '');
    if (realpath($maybe) && is_dir(realpath($maybe))) {
        return $folder;
    }
    return DEFAULT_THEME_FOLDER;
}

/**
 * Resolve candidate filesystem path for a theme asset.
 * Accepts either "assets/js/script.js" OR "js/script.js" OR "css/style.css".
 * Returns realpath to file or null.
 */
function theme_asset_path($pdoOrNull, string $assetRelative): ?string {
    $assetRel = normalize_relative_path($assetRelative);
    if ($assetRel === '') return null;

    // If caller provided a path that already begins with "assets/", don't add another "assets/".
    if (!preg_match('#^assets[\\/\\\\]#i', $assetRel)) {
        $assetRel = 'assets' . DIRECTORY_SEPARATOR . $assetRel;
    }

    $pdo = $pdoOrNull ?: get_pdo_from_global();

    // Build candidate folders list: include assigned/active/defaults — keep heuristic simple:
    $folders = [];
    // collect from assignments if possible (we'll prefer active + default)
    try {
        // active first (so it's tried first)
        $active = get_active_theme_folder($pdo);
        if ($active) $folders[] = $active;
    } catch (Throwable $e) {
        if (defined('THEME_DEBUG') && THEME_DEBUG) error_log("[THEME] theme_asset_path get_active_theme_folder: " . $e->getMessage());
    }

    // always try DEFAULT_THEME_FOLDER as fallback
    if (!in_array(DEFAULT_THEME_FOLDER, $folders, true)) $folders[] = DEFAULT_THEME_FOLDER;

    // Also attempt any folders referenced in assignments (so when header/main/footer from different themes)
    try {
        $assignmentFolders = get_relevant_theme_folders($pdo, null);
        foreach ($assignmentFolders as $af) {
            if (!in_array($af, $folders, true)) $folders[] = $af;
        }
    } catch (Throwable $e) {
        if (defined('THEME_DEBUG') && THEME_DEBUG) error_log("[THEME] theme_asset_path get_relevant_theme_folders: " . $e->getMessage());
    }

    foreach ($folders as $f) {
        $candidate = path_candidate(VIEWS_BASE, (string)$f, $assetRel);
        $real = realpath($candidate);
        if ($real && is_file($real)) {
            if (defined('THEME_DEBUG') && THEME_DEBUG) {
                error_log("[THEME] theme_asset_path FOUND asset={$assetRelative} -> {$real}");
            }
            return $real;
        } else {
            if (defined('THEME_DEBUG') && THEME_DEBUG) {
                error_log("[THEME] theme_asset_path check asset={$assetRelative} candidate={$candidate} real=" . ($real ?: 'NULL'));
            }
        }
    }

    if (defined('THEME_DEBUG') && THEME_DEBUG) {
        error_log("[THEME] theme_asset_path NOT FOUND for asset={$assetRelative}");
    }
    return null;
}

/**
 * Convert a filesystem asset path (returned by theme_asset_path) to a web URL (relative to PUBLIC_PATH).
 */
function theme_asset_url($pdoOrNull, string $assetRelative): ?string {
    $fs = theme_asset_path($pdoOrNull, $assetRelative);
    if (!$fs) return null;

    $publicReal = realpath(PUBLIC_PATH) ?: null;
    $fsReal = realpath($fs) ?: null;
    if (!$publicReal || !$fsReal) {
        if (defined('THEME_DEBUG') && THEME_DEBUG) error_log("[THEME] theme_asset_url missing realpath PUBLIC_PATH or fsReal");
        return null;
    }
    if (strpos($fsReal, $publicReal) !== 0) {
        if (defined('THEME_DEBUG') && THEME_DEBUG) error_log("[THEME] theme_asset_url fs not under PUBLIC_PATH: {$fsReal} not in {$publicReal}");
        return null;
    }

    $webPath = str_replace('\\', '/', substr($fsReal, strlen($publicReal)));
    if ($webPath === '' || $webPath[0] !== '/') $webPath = '/' . ltrim($webPath, '/');
    $v = @filemtime($fsReal) ?: time();
    return $webPath;
}

////////////////////////////////////////////////////////////////////////////////
// Install theme from zip (unchanged; included for completeness)
////////////////////////////////////////////////////////////////////////////////

/**
 * Robust install_theme_from_zip replacement.
 * Returns array: success, message, folder, errors (array)
 */
function install_theme_from_zip($pdoOrNull, string $zipPath, bool $activate = false, ?int $by_user_id = null): array {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $errors = [];
    $ret = ['success' => false, 'message' => '', 'folder' => null, 'errors' => []];

    if (!is_file($zipPath) || !is_readable($zipPath)) {
        $ret['message'] = __('Zip file not found or unreadable.');
        return $ret;
    }
    if (!class_exists('ZipArchive')) {
        $ret['message'] = 'Server tidak memiliki ZipArchive extension.';
        return $ret;
    }

    $zip = new ZipArchive();
    $openRes = $zip->open($zipPath);
    if ($openRes !== true) {
        $ret['message'] = 'File zip tidak valid atau korup (ZipArchive open error).';
        return $ret;
    }

    $topFolders = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === '') continue;
        $name = str_replace('\\', '/', $name);
        if (preg_match('#^(?:[A-Za-z]:|/|\\\\)#', $name) || strpos($name, '..') !== false) {
            $zip->close();
            $ret['message'] = 'Zip contains unsafe paths.';
            return $ret;
        }
        $parts = explode('/', rtrim($name, '/'));
        if (count($parts) && $parts[0] !== '') $topFolders[$parts[0]] = true;
    }
    $topFolders = array_keys($topFolders);

    // prepare extraction dir
    $rand = bin2hex(random_bytes(6));
    $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "theme_extract_{$rand}";
    if (!@mkdir($extractDir, 0700, true) && !is_dir($extractDir)) {
        $zip->close();
        $ret['message'] = 'Gagal membuat temporary directory.';
        return $ret;
    }

    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        helper_rrmdir($extractDir);
        $ret['message'] = 'Gagal mengekstrak zip.';
        return $ret;
    }
    $zip->close();

    // Determine final folder name:
    $zipBase = pathinfo($zipPath, PATHINFO_FILENAME);

    $singleTop = (count($topFolders) === 1) ? $topFolders[0] : null;
    $extractedRootCandidate = $singleTop ? $extractDir . DIRECTORY_SEPARATOR . $singleTop : $extractDir;

    $manifestFsPath = null;
    if ($singleTop && is_dir($extractDir . DIRECTORY_SEPARATOR . $singleTop)) {
        $manifestFsPath = $extractDir . DIRECTORY_SEPARATOR . $singleTop . DIRECTORY_SEPARATOR . 'theme.json';
    } else {
        $manifestFsPath = $extractDir . DIRECTORY_SEPARATOR . 'theme.json';
    }

    $manifestArray = null;
    if (is_file($manifestFsPath)) {
        $raw = @file_get_contents($manifestFsPath);
        $tmp = @json_decode($raw, true);
        if (is_array($tmp)) $manifestArray = $tmp;
    }

    $finalFolder = null;
    if (!empty($manifestArray['folder'])) {
        $finalFolder = sanitize_folder_name((string)$manifestArray['folder']);
    } elseif ($singleTop) {
        $finalFolder = sanitize_folder_name($singleTop);
    } else {
        $finalFolder = sanitize_folder_name($zipBase);
    }

    if (is_array($manifestArray)) {
        $manifestErrors = validate_theme_manifest($manifestArray);
        if (!empty($manifestErrors)) {
            helper_rrmdir($extractDir);
            $ret['message'] = 'theme.json error: ' . implode('; ', $manifestErrors);
            return $ret;
        }
    } else {
        if (defined('THEME_DEBUG') && THEME_DEBUG) {
            error_log("[THEME] install: no theme.json found inside uploaded zip (will continue).");
        }
    }

    $destFs = path_candidate(VIEWS_BASE, $finalFolder, '');
    if (is_dir($destFs)) {
        helper_rrmdir($extractDir);
        $ret['message'] = "Theme folder already exists: {$finalFolder}";
        return $ret;
    }

    if (!is_dir(dirname($destFs))) {
        if (!@mkdir(dirname($destFs), 0755, true) && !is_dir(dirname($destFs))) {
            helper_rrmdir($extractDir);
            $ret['message'] = 'Gagal membuat parent folder themes.';
            return $ret;
        }
    }

    $moved = false;
    try {
        if ($singleTop && @rename($extractDir . DIRECTORY_SEPARATOR . $singleTop, $destFs)) {
            @rmdir($extractDir);
            $moved = true;
        } else {
            if (!helper_recurse_copy($extractedRootCandidate, $destFs)) {
                helper_rrmdir($extractDir);
                helper_rrmdir($destFs);
                $ret['message'] = 'Gagal menyalin file tema ke direktori target (permission?).';
                return $ret;
            }
            helper_rrmdir($extractDir);
            $moved = true;
        }
    } catch (Throwable $e) {
        helper_rrmdir($extractDir);
        helper_rrmdir($destFs);
        $ret['message'] = 'Gagal memindahkan tema: ' . $e->getMessage();
        return $ret;
    }

    if (!$moved) {
        helper_rrmdir($extractDir);
        helper_rrmdir($destFs);
        $ret['message'] = 'Gagal memindahkan tema (unknown).';
        return $ret;
    }

    try {
        $manifestForDb = [
            'name' => $manifestArray['name'] ?? $finalFolder,
            'description' => $manifestArray['description'] ?? '',
            'version' => $manifestArray['version'] ?? '',
            'author' => $manifestArray['author'] ?? '',
            'screenshot' => $manifestArray['screenshot'] ?? null,
            'folder' => $finalFolder,
            'is_active' => !empty($manifestArray['is_active']),
            'store' => $manifestArray['store'] ?? [],
        ];
        register_theme_in_db($pdo, $finalFolder, $manifestForDb, !empty($manifestForDb['is_active']));
        if ($activate) {
            try {
                set_site_active_theme($pdo, $finalFolder);
                try {
                    $ok = bulk_assign_theme($pdo, $finalFolder, null, $by_user_id ?? 0);
                    if (!$ok && defined('THEME_DEBUG') && THEME_DEBUG) {
                        error_log("[THEME] bulk_assign_theme returned false for {$finalFolder}");
                    }
                } catch (Throwable $be) {
                    if (defined('THEME_DEBUG') && THEME_DEBUG) error_log("[THEME] bulk_assign_theme error: " . $be->getMessage());
                }
            } catch (Throwable $e) {
                if (defined('THEME_DEBUG') && THEME_DEBUG) error_log("[THEME] set_site_active_theme failed: " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        helper_rrmdir($destFs);
        $ret['message'] = 'DB register failed: ' . $e->getMessage();
        return $ret;
    }

    $ret['success'] = true;
    $ret['message'] = 'Theme installed';
    $ret['folder'] = $finalFolder;
    return $ret;
}

////////////////////////////////////////////////////////////////////////////////
// Small helpers used by installer
////////////////////////////////////////////////////////////////////////////////

function helper_rrmdir($dir) {
    if (!is_dir($dir)) return;
    try {
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) @rmdir($file->getRealPath()); else @unlink($file->getRealPath());
        }
        @rmdir($dir);
    } catch (Throwable $e) {
        if (THEME_DEBUG) error_log('[THEME] helper_rrmdir error: ' . $e->getMessage());
    }
}

function helper_recurse_copy($src, $dst) {
    $src = rtrim($src, '/\\');
    $dst = rtrim($dst, '/\\');
    if (!is_dir($src)) return false;
    if (!@mkdir($dst, 0755, true) && !is_dir($dst)) return false;
    $dir = opendir($src);
    if ($dir === false) return false;
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') continue;
        $srcFile = $src . DIRECTORY_SEPARATOR . $file;
        $dstFile = $dst . DIRECTORY_SEPARATOR . $file;
        if (is_dir($srcFile)) {
            if (!helper_recurse_copy($srcFile, $dstFile)) {
                closedir($dir);
                return false;
            }
        } else {
            if (!@copy($srcFile, $dstFile)) {
                closedir($dir);
                return false;
            }
        }
    }
    closedir($dir);
    return true;
}

////////////////////////////////////////////////////////////////////////////////
// End of helper
////////////////////////////////////////////////////////////////////////////////
