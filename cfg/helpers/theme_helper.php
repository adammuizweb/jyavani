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
require_once __DIR__ . '/package_archive.php';

if (!defined('THEME_DEBUG')) define('THEME_DEBUG', function_exists('app_debug_enabled') ? app_debug_enabled() : false);

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

function theme_slot_key_is_valid(string $key): bool {
    return $key !== '' && strlen($key) <= 100
        && preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $key) === 1;
}

function theme_slot_template_is_valid(string $template): bool {
    if ($template === '' || strlen($template) > 255 || str_contains($template, "\0") || str_contains($template, '\\')) return false;
    if ($template[0] === '/' || str_contains($template, '..') || !str_ends_with($template, '.php')) return false;
    return preg_match('#^[a-zA-Z0-9._-]+(?:/[a-zA-Z0-9._-]+)*\.php$#', $template) === 1;
}

function theme_extension_owner_is_valid(string $owner): bool {
    return $owner !== '' && strlen($owner) <= 100
        && preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $owner) === 1;
}

function theme_core_slot_definitions(): array {
    return [
        'header' => ['owner' => 'core', 'label' => 'Header', 'template' => 'header.php', 'bulk_assign' => true],
        'sidebar' => ['owner' => 'core', 'label' => 'Sidebar (complementary)', 'template' => 'sidebar.php', 'bulk_assign' => true],
        'main.homepage' => ['owner' => 'core', 'label' => 'Main - Homepage', 'template' => 'main/homepage.php', 'bulk_assign' => true],
        'main.search' => ['owner' => 'core', 'label' => 'Search results', 'template' => 'main/search.php', 'bulk_assign' => true],
        'list.post' => ['owner' => 'core', 'label' => 'List - Post', 'template' => 'main/list/post.php', 'bulk_assign' => true],
        'list.page' => ['owner' => 'core', 'label' => 'List - Page', 'template' => 'main/list/page.php', 'bulk_assign' => true],
        'list.category' => ['owner' => 'core', 'label' => 'List - Category', 'template' => 'main/list/category.php', 'bulk_assign' => true],
        'list.archive' => ['owner' => 'core', 'label' => 'List - Archive', 'template' => 'main/list/archive.php', 'bulk_assign' => true],
        'list.author' => ['owner' => 'core', 'label' => 'List - Author', 'template' => 'main/list/author.php', 'bulk_assign' => true],
        'single.post' => ['owner' => 'core', 'label' => 'Single - Post', 'template' => 'main/single/post.php', 'bulk_assign' => true],
        'single.page' => ['owner' => 'core', 'label' => 'Single - Page', 'template' => 'main/single/page.php', 'bulk_assign' => true],
        'index.category' => ['owner' => 'core', 'label' => 'Index - Category (parent list)', 'template' => 'main/index/category.php', 'bulk_assign' => true],
        'index.author' => ['owner' => 'core', 'label' => 'Index - Author (user list)', 'template' => 'main/index/author.php', 'bulk_assign' => true],
        'main.404' => ['owner' => 'core', 'label' => '404 - Not Found', 'template' => 'main/404.php', 'bulk_assign' => true],
        'footer' => ['owner' => 'core', 'label' => 'Footer', 'template' => 'footer.php', 'bulk_assign' => true],
    ];
}

function theme_slot_normalize_definition(string $key, mixed $definition): ?array {
    if (!theme_slot_key_is_valid($key) || !is_array($definition)) return null;
    $label = trim((string)($definition['label'] ?? ''));
    $template = trim((string)($definition['template'] ?? ''));
    $owner = trim((string)($definition['owner'] ?? ''));
    if ($label === '' || !theme_slot_template_is_valid($template) || !theme_extension_owner_is_valid($owner)) return null;
    return [
        'key' => $key,
        'owner' => $owner,
        'label' => $label,
        'template' => $template,
        'bulk_assign' => ($definition['bulk_assign'] ?? true) === true,
    ];
}

/** Register one immutable request-local extension slot. First declaration wins. */
function register_theme_slot(string $key, array $definition): bool {
    $normalized = theme_slot_normalize_definition($key, $definition);
    if ($normalized === null || isset(theme_core_slot_definitions()[$key])) return false;
    if (isset($GLOBALS['__jy_theme_slots'][$key])) return false;
    $GLOBALS['__jy_theme_slots'][$key] = $normalized;
    return true;
}

/** Return canonical definitions keyed by slot key. Filters may append, but not replace, definitions. */
function theme_slot_definitions(?PDO $pdo = null, array $context = []): array {
    $definitions = [];
    foreach (theme_core_slot_definitions() as $key => $definition) {
        $definitions[$key] = theme_slot_normalize_definition($key, $definition);
    }
    foreach (($GLOBALS['__jy_theme_slots'] ?? []) as $key => $definition) {
        if (isset($definitions[$key])) continue;
        $normalized = theme_slot_normalize_definition((string)$key, $definition);
        if ($normalized !== null) $definitions[$key] = $normalized;
    }

    $candidates = function_exists('apply_filters')
        ? apply_filters('theme_slot_definitions', $definitions, $pdo, $context)
        : $definitions;
    if (!is_array($candidates)) return $definitions;
    foreach ($candidates as $candidateKey => $candidate) {
        if (!is_array($candidate)) continue;
        $key = is_string($candidate['key'] ?? null)
            ? (string)$candidate['key']
            : (is_string($candidateKey) ? $candidateKey : '');
        if (isset($definitions[$key])) continue;
        $normalized = theme_slot_normalize_definition($key, $candidate);
        if ($normalized !== null) $definitions[$key] = $normalized;
    }
    return $definitions;
}

function theme_slot_definition(string $key, ?PDO $pdo = null, array $context = []): ?array {
    return theme_slot_definitions($pdo, $context)[$key] ?? null;
}

function theme_assignment_matches_definition(array $assignment, ?array $definition): bool {
    if ($definition === null) return false;
    $owner = (string)($definition['owner'] ?? '');
    $persistedOwner = $assignment['slot_owner'] ?? null;
    if ($owner === 'core' && ($persistedOwner === null || $persistedOwner === '')) return true;
    return is_string($persistedOwner) && hash_equals($owner, $persistedOwner);
}

function theme_bulk_assignment_can_update(?array $assignment, array $definition): bool {
    return $assignment === null || theme_assignment_matches_definition($assignment, $definition);
}

if (!function_exists('slot_to_file')) {
    function slot_to_file(string $slot_key, ?PDO $pdo = null): string {
        $definition = theme_slot_definition($slot_key, $pdo ?: get_pdo_from_global(), ['scope' => 'runtime']);
        return is_array($definition)
            ? (string)$definition['template']
            : '';
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

function theme_context_matches(?string $context, string $pattern): bool {
    if ($context === null || $context === '') return true;
    $pattern = trim($pattern);
    if ($pattern === '' || $pattern === '*') return true;
    if (str_ends_with($pattern, '*')) return str_starts_with($context, substr($pattern, 0, -1));
    return $pattern === $context;
}

function theme_context_list_matches(?string $context, mixed $patterns): bool {
    if ($context === null || $context === '') return true;
    if (is_string($patterns)) $patterns = [$patterns];
    if (!is_array($patterns)) return false;
    foreach ($patterns as $pattern) {
        if (is_string($pattern) && theme_context_matches($context, $pattern)) return true;
    }
    return false;
}

function theme_asset_entry_source(mixed $entry, ?string $context = null): ?string {
    if (is_string($entry)) return $entry !== '' ? $entry : null;
    if (!is_array($entry)) return null;

    $source = (string)($entry['src'] ?? '');
    if ($source === '') return null;
    if ($context !== null && $context !== '') {
        if (array_key_exists('contexts', $entry) && !theme_context_list_matches($context, $entry['contexts'])) return null;
        if (array_key_exists('exclude_contexts', $entry) && theme_context_list_matches($context, $entry['exclude_contexts'])) return null;
    }
    return $source;
}

function theme_manifest_asset_sources(array $manifest, string $type, ?string $context = null): array {
    $entries = $manifest[$type] ?? [];
    if (!is_array($entries)) return [];
    $sources = [];
    foreach ($entries as $entry) {
        $source = theme_asset_entry_source($entry, $context);
        if ($source !== null && !in_array($source, $sources, true)) $sources[] = $source;
    }
    return $sources;
}

function theme_manifest_has_asset(array $manifest, string $type, string $needle, ?string $context = null): bool {
    foreach (theme_manifest_asset_sources($manifest, $type, $context) as $source) {
        if (stripos($source, $needle) !== false) return true;
    }
    return false;
}

/** Filesystem manifests are authoritative; the DB copy is an installation snapshot. */
function installed_theme_manifest($pdoOrNull, string $folder): array {
    static $cache = [];
    if (array_key_exists($folder, $cache)) return $cache[$folder];

    $manifestPath = path_candidate(VIEWS_BASE, $folder, 'theme.json');
    if (is_file($manifestPath)) {
        $cache[$folder] = read_theme_manifest(path_candidate(VIEWS_BASE, $folder, ''));
        return $cache[$folder];
    }

    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $theme = $pdo ? get_theme_by_folder($pdo, $folder) : null;
    if ($theme && !empty($theme['manifest_json'])) {
        $manifest = json_decode((string)$theme['manifest_json'], true);
        if (is_array($manifest)) {
            $cache[$folder] = $manifest;
            return $manifest;
        }
    }
    $cache[$folder] = [];
    return $cache[$folder];
}

function theme_manifest_core_assets(array $manifest, ?string $context = null): array {
    $known = ['anime', 'quill', 'fonts', 'swiper'];
    if (!array_key_exists('core_assets', $manifest)) return $known;

    $config = $manifest['core_assets'];
    if (is_array($config) && array_is_list($config)) {
        $selected = $config;
    } elseif (is_array($config)) {
        $selected = is_array($config['default'] ?? null) ? $config['default'] : $known;
        $contexts = $config['contexts'] ?? [];
        if ($context !== null && is_array($contexts)) {
            foreach ($contexts as $pattern => $assets) {
                if (is_string($pattern) && is_array($assets) && theme_context_matches($context, $pattern)) {
                    $selected = $assets;
                    if ($pattern === $context) break;
                }
            }
        }
    } else {
        return $known;
    }

    return array_values(array_filter($known, static fn(string $name): bool => in_array($name, $selected, true)));
}

function resolve_theme_core_assets($pdoOrNull, array $folders, ?string $context = null): array {
    $known = ['anime', 'quill', 'fonts', 'swiper'];
    if ($folders === []) return $known;

    $enabled = [];
    foreach ($folders as $folder) {
        $manifest = installed_theme_manifest($pdoOrNull, (string)$folder);
        foreach (theme_manifest_core_assets($manifest, $context) as $asset) $enabled[$asset] = true;
    }
    return array_values(array_filter($known, static fn(string $name): bool => isset($enabled[$name])));
}

function theme_manifest_file_url(string $folder, string $relative, bool $cacheBust = false): ?string {
    $relative = normalize_relative_path($relative);
    if ($relative === '') return null;
    $themeRoot = realpath(path_candidate(VIEWS_BASE, $folder, ''));
    $publicRoot = realpath(PUBLIC_PATH);
    $file = realpath(path_candidate(VIEWS_BASE, $folder, $relative));
    if (!$themeRoot || !$publicRoot || !$file || !is_file($file)) return null;
    if (!str_starts_with($file, $themeRoot . DIRECTORY_SEPARATOR) || !str_starts_with($file, $publicRoot . DIRECTORY_SEPARATOR)) return null;

    $url = '/' . ltrim(str_replace('\\', '/', substr($file, strlen($publicRoot))), '/');
    if ($cacheBust) $url .= '?v=' . (@filemtime($file) ?: time());
    return $url;
}

function collect_theme_preloads($pdoOrNull, array $folders, ?string $context = null): array {
    $preloads = [];
    $seen = [];
    foreach ($folders as $folder) {
        $folder = (string)$folder;
        $manifest = installed_theme_manifest($pdoOrNull, $folder);
        $entries = $manifest['preloads'] ?? [];
        if (!is_array($entries)) continue;
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            if ($context !== null && $context !== '') {
                if (array_key_exists('contexts', $entry) && !theme_context_list_matches($context, $entry['contexts'])) continue;
                if (array_key_exists('exclude_contexts', $entry) && theme_context_list_matches($context, $entry['exclude_contexts'])) continue;
            }

            $as = (string)($entry['as'] ?? '');
            $href = theme_manifest_file_url(
                $folder,
                (string)($entry['href'] ?? ''),
                in_array($as, ['script', 'style'], true)
            );
            if ($href === null || !in_array($as, ['fetch', 'font', 'image', 'script', 'style'], true)) continue;
            $preload = ['rel' => 'preload', 'as' => $as, 'href' => $href];

            foreach (['type', 'media', 'imagesizes'] as $attribute) {
                if (isset($entry[$attribute]) && is_string($entry[$attribute]) && $entry[$attribute] !== '') {
                    $preload[$attribute] = $entry[$attribute];
                }
            }
            $priority = (string)($entry['fetchpriority'] ?? '');
            if (in_array($priority, ['high', 'low', 'auto'], true)) $preload['fetchpriority'] = $priority;
            $crossorigin = $entry['crossorigin'] ?? null;
            if ($crossorigin === true || $crossorigin === 'anonymous') $preload['crossorigin'] = 'anonymous';
            elseif ($crossorigin === 'use-credentials') $preload['crossorigin'] = 'use-credentials';

            $srcset = [];
            $srcsetEntries = $entry['imagesrcset'] ?? [];
            if (!is_array($srcsetEntries)) $srcsetEntries = [];
            foreach ($srcsetEntries as $candidate) {
                if (!is_array($candidate)) continue;
                $candidateHref = theme_manifest_file_url($folder, (string)($candidate['href'] ?? ''));
                $descriptor = (string)($candidate['descriptor'] ?? '');
                if ($candidateHref && preg_match('/^(?:\d+w|\d+(?:\.\d+)?x)$/', $descriptor)) {
                    $srcset[] = $candidateHref . ' ' . $descriptor;
                }
            }
            if ($srcset !== []) $preload['imagesrcset'] = implode(', ', $srcset);

            $key = $as . '|' . $href . '|' . ($preload['imagesrcset'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $preloads[] = $preload;
            }
        }
    }
    return $preloads;
}

function echo_theme_preloads($pdoOrNull, array $folders, ?string $context = null): void {
    foreach (collect_theme_preloads($pdoOrNull, $folders, $context) as $preload) {
        $attributes = [];
        foreach ($preload as $name => $value) {
            $attributes[] = $name . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
        }
        echo '<link ' . implode(' ', $attributes) . '>' . PHP_EOL;
    }
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

    // ensure default present — unless the active theme opts out via "standalone": true
    // (a standalone theme ships its own complete CSS and must not inherit default resets,
    //  which are unlayered and would beat Tailwind v4's @layer utilities)
    $default = defined('DEFAULT_THEME_FOLDER') ? DEFAULT_THEME_FOLDER : 'default';
    $skipDefault = false;
    if ($pdo) {
        try {
            $stmtA = $pdo->prepare("SELECT folder_name FROM themes WHERE is_active = 1 LIMIT 1");
            $stmtA->execute();
            $rA = $stmtA->fetch(PDO::FETCH_ASSOC);
            if ($rA && !empty($rA['folder_name'])) {
                $mA = read_theme_manifest(path_candidate(VIEWS_BASE, $rA['folder_name'], ''));
                $skipDefault = !empty($mA['standalone']);
            }
        } catch (Throwable $e) {}
    }
    if ($default && !$skipDefault) $folders[] = $default;

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
function collect_theme_asset_urls($pdoOrNull, array $folders, ?string $context = null): array {
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
        return $webPath . '?v=' . $v;
    };

    foreach ($folders as $folder) {
        $manifest = installed_theme_manifest($pdo, (string)$folder);

        // determine style/script lists for this folder
        $styles = !empty($manifest['styles']) && is_array($manifest['styles'])
            ? theme_manifest_asset_sources($manifest, 'styles', $context)
            : $styleCandidates;
        $scripts = !empty($manifest['scripts']) && is_array($manifest['scripts'])
            ? theme_manifest_asset_sources($manifest, 'scripts', $context)
            : $scriptCandidates;

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
function echo_relevant_theme_styles($pdoOrNull = null, ?array $slot_keys = null, ?string $context = null): void {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $folders = get_relevant_theme_folders($pdo, $slot_keys);
    $assets = collect_theme_asset_urls($pdo, $folders, $context);
    foreach ($assets['styles'] as $cssUrl) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }
}

/**
 * Echo <script> tags for relevant theme scripts (to be called before </body>).
 */
function echo_relevant_theme_scripts($pdoOrNull = null, ?array $slot_keys = null, ?string $context = null): void {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $folders = get_relevant_theme_folders($pdo, $slot_keys);
    $assets = collect_theme_asset_urls($pdo, $folders, $context);
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

    // Allow plugins to override template resolution (e.g., live theme preview).
    $resolved = apply_filters('resolve_template', null, $slot_key, $pdo);
    if ($resolved !== null) return $resolved;

    $definition = theme_slot_definition($slot_key, $pdo, ['scope' => 'runtime']);
    if ($definition === null) return ['type' => 'unavailable'];

    if ($pdo) {
        $assign = get_assignment($pdo, $slot_key);
        if ($assign) {
            if (!theme_assignment_matches_definition($assign, $definition)) return ['type' => 'unavailable'];
            if (!empty($assign['custom_post_id'])) {
                $post = get_post_by_id($pdo, (int)$assign['custom_post_id']);
                if ($post && (($post['type'] ?? '') === 'theme')) {
                    return ['type' => 'custom_post', 'post' => $post];
                }
            }
            if (!empty($assign['theme_id'])) {
                $theme = get_theme_by_id($pdo, (int)$assign['theme_id']);
                $theme_folder = $theme ? $theme['folder_name'] : null;
                $theme_file = (string)$definition['template'];
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
    $theme_file = slot_to_file($slot_key, $pdo);
    return ['type' => 'theme_file', 'theme_folder' => (string)$theme_folder, 'theme_file' => (string)$theme_file];
}

function resolve_theme_file_path(array $resolved): ?string {
    $folder = $resolved['theme_folder'] ?? DEFAULT_THEME_FOLDER;
    $file = $resolved['theme_file'] ?? '';
    $file_rel = normalize_relative_path((string)$file);
    $candidate = path_candidate(VIEWS_BASE, (string)$folder, $file_rel);
    $candidate_real = realpath($candidate);
    $base_real = realpath(VIEWS_BASE);

    if ($candidate_real && $base_real
        && str_starts_with($candidate_real, rtrim($base_real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        && is_file($candidate_real)) {
        return $candidate_real;
    }

    $fallback = path_candidate(VIEWS_BASE, DEFAULT_THEME_FOLDER, $file_rel);
    $fallback_real = realpath($fallback);
    if ($fallback_real && $base_real
        && str_starts_with($fallback_real, rtrim($base_real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        && is_file($fallback_real)) {
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

    // Preserve existence as well as value so nested renders cannot leak globals.
    $renderGlobals = [
        '__jy_render_theme_folder' => '__jy_theme_folder',
        '__jy_render_theme_source_folder' => '__jy_theme_source_folder',
        '__jy_render_slot_key' => '__jy_slot_key',
    ];
    $previousRenderGlobals = [];
    foreach ($renderGlobals as $globalKey => $contextKey) {
        $previousRenderGlobals[$globalKey] = [
            'exists' => array_key_exists($globalKey, $GLOBALS),
            'value' => $GLOBALS[$globalKey] ?? null,
        ];
        if (isset($context[$contextKey]) && is_string($context[$contextKey])) {
            $GLOBALS[$globalKey] = $context[$contextKey];
        }
    }

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
    } finally {
        foreach ($previousRenderGlobals as $globalKey => $previous) {
            if ($previous['exists']) {
                $GLOBALS[$globalKey] = $previous['value'];
            } else {
                unset($GLOBALS[$globalKey]);
            }
        }
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
        // Apply post adapters here because this layer owns the render context.
        $post = apply_filters('theme_slot_post_data', $resolved['post'], $slot_key, $pdo, $context);
        return render_custom_post_template(is_array($post) ? $post : $resolved['post'], $context);
    } elseif ($resolved['type'] === 'theme_file') {
        $path = resolve_theme_file_path($resolved);
        if ($path) {
            $context['__jy_theme_folder'] = $resolved['theme_folder'] ?? ($resolved['folder'] ?? DEFAULT_THEME_FOLDER);
            $viewsRoot = realpath(VIEWS_BASE);
            $relativePath = $viewsRoot !== false && str_starts_with($path, $viewsRoot . DIRECTORY_SEPARATOR)
                ? substr($path, strlen($viewsRoot) + 1)
                : '';
            $sourceFolder = $relativePath !== '' ? strtok($relativePath, DIRECTORY_SEPARATOR) : false;
            $context['__jy_theme_source_folder'] = is_string($sourceFolder) && $sourceFolder !== ''
                ? $sourceFolder
                : (string)$context['__jy_theme_folder'];
            $context['__jy_slot_key'] = $slot_key;
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
    if (!theme_slot_key_is_valid($slot_key)) throw new InvalidArgumentException('Invalid theme slot key.');
    $definition = theme_slot_definition($slot_key, $pdo, ['scope' => 'assignment']);
    if ($definition === null) throw new InvalidArgumentException('Unavailable theme slot.');

    $theme = get_theme_by_folder($pdo, $theme_folder);
    if (!$theme) throw new RuntimeException("Theme folder not found: $theme_folder");

    $theme_id = (int)$theme['id'];
    $file = (string)$definition['template'];
    if ($theme_file !== null && sanitize_theme_file_for_db($theme_file) !== $file) {
        throw new InvalidArgumentException('Theme template must match the registered slot definition.');
    }

    $sql = "INSERT INTO assignments (slot_key, slot_owner, theme_id, theme_file, custom_post_id, created_by)
            VALUES (?, ?, ?, ?, NULL, ?)
            ON DUPLICATE KEY UPDATE slot_owner = VALUES(slot_owner), theme_id = VALUES(theme_id), theme_file = VALUES(theme_file), custom_post_id = NULL, updated_at = CURRENT_TIMESTAMP";
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([$slot_key, $definition['owner'], $theme_id, $file, $by_user_id]);
    if ($ok) {
        db_cache_set('assignments', $slot_key, null);
    }
    return (bool)$ok;
}

function assign_custom_post_to_slot($pdoOrNull, string $slot_key, int $post_id, ?int $by_user_id = null): bool {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) throw new RuntimeException('PDO required for assign_custom_post_to_slot');
    if (!theme_slot_key_is_valid($slot_key)) throw new InvalidArgumentException('Invalid theme slot key.');
    $definition = theme_slot_definition($slot_key, $pdo, ['scope' => 'assignment']);
    if ($definition === null) throw new InvalidArgumentException('Unavailable theme slot.');

    $post = get_post_by_id($pdo, $post_id);
    if (!$post || (($post['type'] ?? '') !== 'theme')) {
        throw new RuntimeException("Custom theme post not found or not type='theme': $post_id");
    }

    $sql = "INSERT INTO assignments (slot_key, slot_owner, theme_id, theme_file, custom_post_id, created_by)
            VALUES (?, ?, NULL, NULL, ?, ?)
            ON DUPLICATE KEY UPDATE slot_owner = VALUES(slot_owner), custom_post_id = VALUES(custom_post_id), theme_id = NULL, theme_file = NULL, updated_at = CURRENT_TIMESTAMP";
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([$slot_key, $definition['owner'], $post_id, $by_user_id]);
    if ($ok) {
        db_cache_set('assignments', $slot_key, null);
    }
    return (bool)$ok;
}

function clear_assignment($pdoOrNull, string $slot_key): bool {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    if (!$pdo) throw new RuntimeException('PDO required for clear_assignment');
    if (!theme_slot_key_is_valid($slot_key)) throw new InvalidArgumentException('Invalid theme slot key.');
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
    $manifest = ['name' => basename($folderPath), 'description' => '', 'version' => '', 'author' => '', 'is_active' => false, 'screenshot' => '', 'color_mode' => 'both'];
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
            // color_mode: "light", "dark", or "both" (default)
            $cm = (string)($j['color_mode'] ?? 'both');
            $manifest['color_mode'] = in_array($cm, ['light', 'dark', 'both'], true) ? $cm : 'both';
            // optional frontend asset contract
            if (!empty($j['styles']) && is_array($j['styles'])) $manifest['styles'] = $j['styles'];
            if (!empty($j['scripts']) && is_array($j['scripts'])) $manifest['scripts'] = $j['scripts'];
            if (array_key_exists('core_assets', $j) && is_array($j['core_assets'])) $manifest['core_assets'] = $j['core_assets'];
            if (array_key_exists('preloads', $j) && is_array($j['preloads'])) $manifest['preloads'] = $j['preloads'];
            // store block for update checking
            if (!empty($j['store']) && is_array($j['store'])) $manifest['store'] = $j['store'];
            // standalone: skip default theme CSS/JS when this theme is active
            if (!empty($j['standalone'])) $manifest['standalone'] = true;
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

function theme_db_registration_matches(PDO $pdo, string $folderName, array $manifest): bool {
    try {
        $statement = $pdo->prepare('SELECT folder_name, manifest_json FROM themes WHERE folder_name = ? LIMIT 1');
        if (!$statement || !$statement->execute([$folderName])) return false;
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $registeredManifest = is_array($row) && is_string($row['manifest_json'] ?? null)
            ? json_decode($row['manifest_json'], true)
            : null;
        return is_array($row) && is_string($row['folder_name'] ?? null)
            && hash_equals($folderName, $row['folder_name'])
            && is_array($registeredManifest) && $registeredManifest === $manifest;
    } catch (Throwable) {
        return false;
    }
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

    $definitions = theme_slot_definitions($pdo);
    $default_slots = [];
    foreach ($definitions as $slot => $definition) {
        if (($definition['bulk_assign'] ?? true) === true) $default_slots[] = $slot;
    }
    $use_slots = $slots ?? $default_slots;

    $okAll = true;
    foreach ($use_slots as $slot) {
        try {
            $definition = $definitions[$slot] ?? null;
            if (!is_array($definition)) {
                $okAll = false;
                continue;
            }
            $assignment = get_assignment($pdo, $slot);
            if (!theme_bulk_assignment_can_update(is_array($assignment) ? $assignment : null, $definition)) continue;
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
    // Allow plugins to override the active theme folder (e.g., live theme preview).
    $folder = apply_filters('active_theme_folder', $folder, $pdo);

    $maybe = path_candidate(VIEWS_BASE, $folder, '');
    if (realpath($maybe) && is_dir(realpath($maybe))) {
        return $folder;
    }
    return DEFAULT_THEME_FOLDER;
}

/**
 * Get the color_mode of the active theme.
 * Returns "light", "dark", or "both" (default).
 */
function get_theme_color_mode($pdoOrNull = null): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $pdo = $pdoOrNull ?: get_pdo_from_global();
    $folder = get_active_theme_folder($pdo);
    $manifest = read_theme_manifest(path_candidate(VIEWS_BASE, $folder, ''));
    $cached = $manifest['color_mode'] ?? 'both';
    return $cached;
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

/** Global key shared by Core and extensions for theme/source lifecycle serialization. */
if (!defined('THEME_LIFECYCLE_LOCK_KEY')) define('THEME_LIFECYCLE_LOCK_KEY', '0-theme-lifecycle');

function theme_lifecycle_lock_keys(array $folders = []): array {
    return array_merge([(string)THEME_LIFECYCLE_LOCK_KEY], $folders);
}

/** Exact registered and on-disk folder keys affected by a registration scan. */
function theme_registration_lock_folders($pdoOrNull = null): array {
    $folders = [];
    foreach (get_registered_themes($pdoOrNull) as $theme) {
        if (is_string($theme['folder_name'] ?? null)) $folders[$theme['folder_name']] = true;
    }
    if (is_dir(VIEWS_BASE)) {
        foreach (glob(rtrim(VIEWS_BASE, '/\\') . DIRECTORY_SEPARATOR . '*') ?: [] as $directory) {
            if (is_dir($directory)) $folders[basename($directory)] = true;
        }
    }
    return array_keys($folders);
}

/** Acquire deterministic exact-name operation locks. Callers must release them in finally. */
function theme_operation_acquire(array $folders, int $mode = LOCK_EX, ?float $deadline = null): array {
    if (!defined('BACKEND_PATH')) {
        throw new RuntimeException('BACKEND_PATH is required for theme operation locks.');
    }
    if (!in_array($mode, [LOCK_SH, LOCK_EX], true)) {
        throw new InvalidArgumentException('Invalid theme operation lock mode.');
    }

    $normalized = [];
    foreach ($folders as $folder) {
        if (!is_string($folder) || $folder === '' || strlen($folder) > 255
            || in_array($folder, ['.', '..'], true) || str_contains($folder, '/') || str_contains($folder, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $folder) === 1) {
            throw new InvalidArgumentException('Invalid installed theme folder.');
        }
        $normalized[$folder] = true;
    }
    $normalized = array_keys($normalized);
    usort($normalized, static function (string $left, string $right): int {
        if ($left === THEME_LIFECYCLE_LOCK_KEY) return $right === THEME_LIFECYCLE_LOCK_KEY ? 0 : -1;
        if ($right === THEME_LIFECYCLE_LOCK_KEY) return 1;
        return strcmp($left, $right);
    });
    if ($normalized === []) throw new InvalidArgumentException('At least one theme folder is required.');

    $backend = rtrim((string)BACKEND_PATH, '/\\');
    $backendReal = realpath($backend);
    $varDir = $backend . DIRECTORY_SEPARATOR . 'var';
    if ($backendReal === false || is_link($backend) || is_link($varDir)) {
        throw new RuntimeException('Unsafe theme operation lock path.');
    }
    if (!is_dir($varDir) && !@mkdir($varDir, 0750, true) && !is_dir($varDir)) {
        throw new RuntimeException('Unable to create the theme operation lock parent directory.');
    }

    $lockDir = $varDir . DIRECTORY_SEPARATOR . 'theme-operation-locks';
    if (is_link($lockDir) || (file_exists($lockDir) && !is_dir($lockDir))) {
        throw new RuntimeException('Unsafe theme operation lock directory.');
    }
    if (is_dir($lockDir)) {
        clearstatcache(true, $lockDir);
        $existingLockDirStat = @lstat($lockDir);
        if (!is_array($existingLockDirStat) || (($existingLockDirStat['mode'] ?? 0) & 0002) !== 0) {
            throw new RuntimeException('Unsafe theme operation lock directory.');
        }
    }
    if (!is_dir($lockDir)) {
        $previousUmask = umask(0007);
        try {
            $created = @mkdir($lockDir, 02770);
        } finally {
            umask($previousUmask);
        }
        if (!$created && !is_dir($lockDir)) throw new RuntimeException('Unable to create the theme operation lock directory.');
    }
    @chmod($lockDir, 02770);
    clearstatcache(true, $lockDir);
    $lockDirStat = @lstat($lockDir);
    $lockDirReal = realpath($lockDir);
    if (!is_array($lockDirStat) || (($lockDirStat['mode'] ?? 0) & 0170000) !== 0040000
        || (($lockDirStat['mode'] ?? 0) & 02777) !== 02770 || is_link($lockDir)
        || $lockDirReal === false || !str_starts_with($lockDirReal, $backendReal . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Unsafe theme operation lock directory.');
    }

    $readerSuspended = false;
    if ($mode === LOCK_EX && in_array((string)THEME_LIFECYCLE_LOCK_KEY, $normalized, true)
        && theme_lifecycle_reader_is_active()) {
        theme_lifecycle_reader_suspend();
        $readerSuspended = true;
    }

    $heldKeys = $GLOBALS['_theme_operation_held_keys'] ?? [];
    foreach ($normalized as $folder) {
        if (isset($heldKeys[$folder])) {
            if ($readerSuspended) theme_lifecycle_reader_resume();
            throw new RuntimeException('Theme operation lock is already held by this request: ' . $folder);
        }
    }

    $locks = [];
    try {
        foreach ($normalized as $folder) {
            $path = $lockDir . DIRECTORY_SEPARATOR . hash('sha256', $folder) . '.lock';
            clearstatcache(true, $path);
            $before = @lstat($path);
            if (is_array($before)) {
                if ((($before['mode'] ?? 0) & 0170000) !== 0100000 || (($before['mode'] ?? 0) & 0777) !== 0660
                    || ($before['nlink'] ?? 0) !== 1 || is_link($path)) {
                    throw new RuntimeException('Unsafe theme operation lock file.');
                }
                $handle = @fopen($path, 'r+');
            } else {
                $previousUmask = umask(0007);
                try {
                    $handle = @fopen($path, 'x+');
                } finally {
                    umask($previousUmask);
                }
                // Another worker may create the file between lstat() and exclusive create.
                if (!is_resource($handle)) {
                    clearstatcache(true, $path);
                    $raced = @lstat($path);
                    if (is_array($raced) && (($raced['mode'] ?? 0) & 0170000) === 0100000
                        && (($raced['mode'] ?? 0) & 0777) === 0660 && ($raced['nlink'] ?? 0) === 1
                        && !is_link($path)) {
                        $handle = @fopen($path, 'r+');
                    }
                }
            }
            if (!is_resource($handle)) throw new RuntimeException('Unable to open the theme operation lock file.');

            $descriptor = @fstat($handle);
            clearstatcache(true, $path);
            $identity = @lstat($path);
            $regular = is_array($descriptor) && (($descriptor['mode'] ?? 0) & 0170000) === 0100000;
            $sameIdentity = is_array($identity)
                && ($descriptor['dev'] ?? null) === ($identity['dev'] ?? null)
                && ($descriptor['ino'] ?? null) === ($identity['ino'] ?? null);
            if (!$regular || !$sameIdentity || (($descriptor['mode'] ?? 0) & 0777) !== 0660
                || ($descriptor['nlink'] ?? 0) !== 1 || is_link($path)) {
                fclose($handle);
                throw new RuntimeException('Unsafe theme operation lock descriptor.');
            }
            $locked = false;
            do {
                $locked = flock($handle, $deadline === null ? $mode : ($mode | LOCK_NB));
                if ($locked || $deadline === null || microtime(true) >= $deadline) break;
                usleep(20000);
            } while (true);
            if (!$locked) {
                fclose($handle);
                throw new RuntimeException('Unable to acquire the theme operation lock.');
            }

            clearstatcache(true, $path);
            $lockedIdentity = @lstat($path);
            if (!is_array($lockedIdentity)
                || ($descriptor['dev'] ?? null) !== ($lockedIdentity['dev'] ?? null)
                || ($descriptor['ino'] ?? null) !== ($lockedIdentity['ino'] ?? null)
                || (($lockedIdentity['mode'] ?? 0) & 0170000) !== 0100000
                || (($lockedIdentity['mode'] ?? 0) & 0777) !== 0660 || ($lockedIdentity['nlink'] ?? 0) !== 1
                || is_link($path)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                throw new RuntimeException('Theme operation lock identity changed.');
            }
            $locks[] = $handle;
            $resourceId = get_resource_id($handle);
            $GLOBALS['_theme_operation_held_keys'][$folder] = $resourceId;
            $GLOBALS['_theme_operation_lock_keys'][$resourceId] = $folder;
            $GLOBALS['_theme_operation_lock_modes'][$resourceId] = $mode;
        }
        return $locks;
    } catch (Throwable $error) {
        theme_operation_release($locks);
        if ($readerSuspended) theme_lifecycle_reader_resume();
        throw $error;
    }
}

function theme_operation_release(array $locks, bool $resumeLifecycleReader = true): void {
    foreach (array_reverse($locks) as $lock) {
        if (!is_resource($lock)) continue;
        $resourceId = get_resource_id($lock);
        $folder = $GLOBALS['_theme_operation_lock_keys'][$resourceId] ?? null;
        @flock($lock, LOCK_UN);
        @fclose($lock);
        unset($GLOBALS['_theme_operation_lock_keys'][$resourceId]);
        unset($GLOBALS['_theme_operation_lock_modes'][$resourceId]);
        if (is_string($folder) && (($GLOBALS['_theme_operation_held_keys'][$folder] ?? null) === $resourceId)) {
            unset($GLOBALS['_theme_operation_held_keys'][$folder]);
        }
    }
    if ($resumeLifecycleReader && !isset($GLOBALS['_theme_operation_held_keys'][(string)THEME_LIFECYCLE_LOCK_KEY])) {
        theme_lifecycle_reader_resume();
    }
}

function theme_operation_holds_lock(string $key, ?int $mode = null): bool {
    $resourceId = $GLOBALS['_theme_operation_held_keys'][$key] ?? null;
    if (!is_int($resourceId)) return false;
    return $mode === null || ($GLOBALS['_theme_operation_lock_modes'][$resourceId] ?? null) === $mode;
}

/** Start the request-lifetime shared reader on the global lifecycle key. */
function theme_lifecycle_reader_start(): bool {
    if (theme_lifecycle_reader_is_active()) return true;
    if (($GLOBALS['_theme_lifecycle_reader']['suspended'] ?? false) === true) return true;

    $GLOBALS['_theme_lifecycle_reader']['enabled'] = true;
    $locks = theme_operation_acquire([(string)THEME_LIFECYCLE_LOCK_KEY], LOCK_SH);
    $GLOBALS['_theme_lifecycle_reader']['handle'] = $locks[0];
    if (($GLOBALS['_theme_lifecycle_reader']['shutdown_registered'] ?? false) !== true) {
        $GLOBALS['_theme_lifecycle_reader']['shutdown_registered'] = true;
        register_shutdown_function('theme_lifecycle_reader_stop');
    }
    return true;
}

function theme_lifecycle_reader_is_active(): bool {
    $handle = $GLOBALS['_theme_lifecycle_reader']['handle'] ?? null;
    return is_resource($handle)
        && theme_operation_holds_lock((string)THEME_LIFECYCLE_LOCK_KEY, LOCK_SH);
}

/** Release this request's shared reader before taking the global exclusive lock. */
function theme_lifecycle_reader_suspend(): void {
    if (!theme_lifecycle_reader_is_active()) return;
    $handle = $GLOBALS['_theme_lifecycle_reader']['handle'];
    $GLOBALS['_theme_lifecycle_reader']['handle'] = null;
    $GLOBALS['_theme_lifecycle_reader']['suspended'] = true;
    theme_operation_release([$handle], false);
}

function theme_lifecycle_reader_resume(): void {
    if (($GLOBALS['_theme_lifecycle_reader']['enabled'] ?? false) !== true
        || ($GLOBALS['_theme_lifecycle_reader']['suspended'] ?? false) !== true
        || isset($GLOBALS['_theme_operation_held_keys'][(string)THEME_LIFECYCLE_LOCK_KEY])) return;
    $GLOBALS['_theme_lifecycle_reader']['suspended'] = false;
    try {
        $locks = theme_operation_acquire([(string)THEME_LIFECYCLE_LOCK_KEY], LOCK_SH);
        $GLOBALS['_theme_lifecycle_reader']['handle'] = $locks[0];
    } catch (Throwable $error) {
        $GLOBALS['_theme_lifecycle_reader']['enabled'] = false;
        throw $error;
    }
}

function theme_lifecycle_reader_stop(): void {
    $handle = $GLOBALS['_theme_lifecycle_reader']['handle'] ?? null;
    $GLOBALS['_theme_lifecycle_reader']['enabled'] = false;
    $GLOBALS['_theme_lifecycle_reader']['suspended'] = false;
    $GLOBALS['_theme_lifecycle_reader']['handle'] = null;
    if (is_resource($handle)) theme_operation_release([$handle], false);
}

/**
 * Robust install_theme_from_zip replacement.
 * Returns array: success, message, folder, errors (array)
 */
function install_theme_from_zip($pdoOrNull, string $zipPath, bool $activate = false, ?int $by_user_id = null, ?string $expectedFolder = null): array {
    $pdo = $pdoOrNull ?: get_pdo_from_global();
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

    $validation = package_archive_validate($zip);
    if (!$validation['success']) {
        $zip->close();
        $ret['message'] = $validation['error'];
        return $ret;
    }
    $filePaths = [];
    foreach ($validation['entries'] as $entry) {
        if (!$entry['directory']) $filePaths[] = $entry['path'];
    }
    $packageRoot = null;
    $manifestSource = in_array('theme.json', $filePaths, true) ? 'theme.json' : null;
    if ($manifestSource === null && $filePaths !== []) {
        $firstSegments = [];
        $allWrapped = true;
        foreach ($filePaths as $path) {
            $parts = explode('/', $path, 2);
            if (count($parts) !== 2) { $allWrapped = false; break; }
            $firstSegments[$parts[0]] = true;
        }
        if ($allWrapped && count($firstSegments) === 1) {
            $packageRoot = (string)array_key_first($firstSegments);
            $candidate = $packageRoot . '/theme.json';
            if (in_array($candidate, $filePaths, true)) $manifestSource = $candidate;
        }
    }
    if ($manifestSource === null) {
        $zip->close();
        $ret['message'] = 'A valid theme.json is required at the package root.';
        return $ret;
    }

    $prefix = $packageRoot === null ? '' : $packageRoot . '/';
    $files = [];
    $expectedTypes = [];
    $hasPhpTemplate = false;
    foreach ($validation['entries'] as $entry) {
        $path = $entry['path'];
        if ($packageRoot !== null && $path === $packageRoot && $entry['directory']) continue;
        if ($prefix !== '' && !str_starts_with($path, $prefix)) {
            $zip->close();
            $ret['message'] = 'Theme package contains content outside its package root.';
            return $ret;
        }
        $relative = $prefix === '' ? $path : substr($path, strlen($prefix));
        if (!is_string($relative) || !package_safe_relative_path($relative)) {
            $zip->close();
            $ret['message'] = 'Theme package contains an invalid target path.';
            return $ret;
        }
        $expectedTypes[$relative] = $entry['directory'] ? 'dir' : 'file';
        $parent = dirname($relative);
        while ($parent !== '.') {
            $expectedTypes[$parent] = 'dir';
            $parent = dirname($parent);
        }
        if (!$entry['directory']) {
            $files[] = ['source' => $entry['source'], 'relative' => $relative];
            if (strtolower((string)pathinfo($relative, PATHINFO_EXTENSION)) === 'php') $hasPhpTemplate = true;
        }
    }
    if (!$hasPhpTemplate) {
        $zip->close();
        $ret['message'] = 'Theme package does not contain a PHP template.';
        return $ret;
    }

    $raw = $zip->getFromName($manifestSource);
    $manifestArray = is_string($raw) ? json_decode($raw, true) : null;

    $validExactFolder = static fn(string $folder): bool => strlen($folder) <= 128
        && preg_match('/\A[a-zA-Z0-9_-][a-zA-Z0-9._-]*\z/', $folder) === 1
        && !in_array($folder, ['.', '..'], true);
    if ($expectedFolder !== null && !$validExactFolder($expectedFolder)) {
        $zip->close();
        $ret['message'] = 'Invalid requested theme identity.';
        return $ret;
    }

    $finalFolder = null;
    if (is_array($manifestArray) && array_key_exists('folder', $manifestArray)) {
        $manifestFolder = $manifestArray['folder'];
        if (!is_string($manifestFolder) || !$validExactFolder($manifestFolder)
            || ($expectedFolder !== null && !hash_equals($expectedFolder, $manifestFolder))) {
            $zip->close();
            $ret['message'] = 'Theme package identity does not match the requested theme.';
            return $ret;
        }
        $finalFolder = $manifestFolder;
    } elseif ($expectedFolder !== null) {
        $finalFolder = $expectedFolder;
    } elseif ($packageRoot !== null) {
        $finalFolder = sanitize_folder_name($packageRoot);
    } else {
        $finalFolder = sanitize_folder_name(pathinfo($zipPath, PATHINFO_FILENAME));
    }

    if (!$validExactFolder($finalFolder)) {
        $zip->close();
        $ret['message'] = 'Invalid theme package identity.';
        return $ret;
    }

    if (!is_array($manifestArray)) {
        $zip->close();
        $ret['message'] = 'A valid theme.json is required.';
        return $ret;
    }
    $manifestErrors = validate_theme_manifest($manifestArray);
    if (!empty($manifestErrors)) {
        $zip->close();
        $ret['message'] = 'theme.json error: ' . implode('; ', $manifestErrors);
        return $ret;
    }

    try {
        $operationLocks = theme_operation_acquire(theme_lifecycle_lock_keys([$finalFolder]));
    } catch (Throwable $error) {
        $zip->close();
        $ret['message'] = 'Unable to lock theme installation: ' . $error->getMessage();
        return $ret;
    }

    $stage = null;
    try {
        $destFs = path_candidate(VIEWS_BASE, $finalFolder, '');
        $recoveryPaths = package_publication_recovery_paths($destFs);
        if ($recoveryPaths !== []) {
            $ret['message'] = 'A prior theme publication recovery artifact requires manual resolution. Inspect and restore or archive it before retrying: ' . basename($recoveryPaths[0]);
            $ret['recovery_paths'] = $recoveryPaths;
            return $ret;
        }
        if (file_exists($destFs) || is_link($destFs)) {
            $ret['message'] = "Theme folder already exists: {$finalFolder}";
            return $ret;
        }
        $parent = dirname($destFs);
        if (!is_dir($parent) || is_link($parent) || realpath($parent) !== $parent) {
            $ret['message'] = 'Theme parent directory is unavailable or unsafe.';
            return $ret;
        }
        $stage = package_private_directory($parent, 'theme-stage-' . $finalFolder);
        if ($stage === null) {
            $ret['message'] = 'Unable to create private same-parent theme staging.';
            return $ret;
        }
        $directories = array_keys(array_filter($expectedTypes, static fn(string $type): bool => $type === 'dir'));
        usort($directories, static fn(string $left, string $right): int => substr_count($left, '/') <=> substr_count($right, '/'));
        foreach ($directories as $directory) {
            $path = $stage . '/' . $directory;
            if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
                $ret['message'] = 'Unable to create the complete staged theme tree.';
                return $ret;
            }
        }
        if (!package_archive_extract_files($zip, $files, $stage)) {
            $ret['message'] = 'Unable to stream the validated theme package into private staging.';
            return $ret;
        }
        $stagedIdentity = package_tree_identity($stage);
        $stagedTypes = is_array($stagedIdentity)
            ? array_map(static fn(array $entry): string => $entry['type'], $stagedIdentity)
            : null;
        ksort($expectedTypes, SORT_STRING);
        if (!is_array($stagedTypes)) {
            $ret['message'] = 'Staged theme tree verification failed.';
            return $ret;
        }
        ksort($stagedTypes, SORT_STRING);
        $stagedManifestRaw = @file_get_contents($stage . '/theme.json');
        $stagedManifest = is_string($stagedManifestRaw) ? json_decode($stagedManifestRaw, true) : null;
        if ($stagedTypes !== $expectedTypes || !is_array($stagedManifest) || $stagedManifest !== $manifestArray) {
            $ret['message'] = 'Staged theme tree or manifest does not exactly match the validated package.';
            return $ret;
        }
        if (!package_chmod_tree($stage, 0755, 0644)) {
            $ret['message'] = 'Unable to set safe permissions on the staged theme.';
            return $ret;
        }
        $stagedIdentity = package_tree_identity($stage);
        if (!is_array($stagedIdentity)) {
            $ret['message'] = 'Staged theme identity verification failed.';
            return $ret;
        }
        foreach ($stagedIdentity as $entry) {
            $expectedMode = $entry['type'] === 'dir' ? 0755 : 0644;
            if (($entry['mode'] ?? null) !== $expectedMode) {
                $ret['message'] = 'Staged theme permissions failed verification.';
                return $ret;
            }
        }
        if (package_publication_recovery_paths($destFs) !== [] || file_exists($destFs) || is_link($destFs)
            || !package_tree_matches_identity($stage, $stagedIdentity)) {
            $ret['message'] = 'Theme publication state changed while staging; inspect recovery artifacts and retry.';
            return $ret;
        }
        if (!@rename($stage, $destFs)) {
            $targetComplete = package_tree_matches_identity($destFs, $stagedIdentity);
            $targetAbsent = !file_exists($destFs) && !is_link($destFs);
            $ret['message'] = $targetComplete
                ? 'Theme publication completed but its rename result was indeterminate; verify the installed theme before retrying.'
                : ($targetAbsent
                    ? 'Unable to publish the complete staged theme atomically; the target remains absent.'
                    : 'Theme publication target changed unexpectedly; manual inspection is required.');
            return $ret;
        }
        $stage = null;
        package_sync_directory($parent);
        if (!package_tree_matches_identity($destFs, $stagedIdentity)) {
            $ret['message'] = 'Published theme identity verification failed; preserve the target for manual inspection.';
            return $ret;
        }

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
        try {
            if (register_theme_in_db($pdo, $finalFolder, $manifestForDb, !empty($manifestForDb['is_active'])) !== true) {
                throw new RuntimeException('Theme registration returned an unsuccessful result.');
            }
        } catch (Throwable $e) {
            $exactRegistration = $pdo instanceof PDO && theme_db_registration_matches($pdo, $finalFolder, $manifestForDb);
            if ($exactRegistration && package_tree_matches_identity($destFs, $stagedIdentity)) {
                $ret['message'] = 'DB register result was indeterminate, but the exact registration and published theme are present. The theme was preserved; verify it before retrying. Cause: ' . $e->getMessage();
                return $ret;
            }
            $exactPublishedIdentity = package_tree_matches_identity($destFs, $stagedIdentity);
            $cleanupVerified = $exactPublishedIdentity && package_remove_tree($destFs)
                && !file_exists($destFs) && !is_link($destFs);
            $ret['message'] = 'DB register failed: ' . $e->getMessage();
            if (!$cleanupVerified) {
                $ret['message'] .= $exactPublishedIdentity
                    ? ' Exact published-tree cleanup could not be verified; inspect the theme target before retrying.'
                    : ' The theme target changed after publication and was preserved because exact cleanup identity could not be verified.';
            }
            return $ret;
        }

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

        $ret['success'] = true;
        $ret['message'] = 'Theme installed';
        $ret['folder'] = $finalFolder;
        $completedManifest = $manifestArray;
        $completedManifest['folder'] = $finalFolder;
        if (function_exists('do_action_isolated')) {
            foreach (do_action_isolated('theme_install_completed', $finalFolder, $completedManifest) as $hookError) {
                error_log('[theme_install_completed] ' . $hookError['message']);
            }
        }
        return $ret;
    } catch (Throwable $error) {
        $ret['message'] = 'Theme installation failed safely: ' . $error->getMessage();
        return $ret;
    } finally {
        $zip->close();
        if (is_string($stage) && $stage !== '') package_remove_tree($stage);
        theme_operation_release($operationLocks);
    }
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

function helper_tree_has_only_regular_files(string $directory): bool {
    if (!is_dir($directory) || is_link($directory)) return false;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) return false;
        }
    } catch (Throwable $e) {
        return false;
    }
    return true;
}

////////////////////////////////////////////////////////////////////////////////
// End of helper
////////////////////////////////////////////////////////////////////////////////
