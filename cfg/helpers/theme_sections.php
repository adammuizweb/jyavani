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

if (defined('THEME_SECTIONS_INCLUDED')) {
    return;
}
define('THEME_SECTIONS_INCLUDED', true);

/**
 * Register a reusable section. Supported definition keys are label, description,
 * defaults, and fallback (a callable or static HTML string).
 */
function register_theme_section(string $name, array $definition = []): bool
{
    $name = strtolower(trim($name));
    if (!theme_section_name_is_valid($name)) return false;

    $GLOBALS['_theme_sections'][$name] = $definition;
    return true;
}

function theme_section_name_is_valid(string $name): bool
{
    return strlen($name) <= 120
        && preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $name) === 1;
}

function theme_section_definitions(): array
{
    $definitions = $GLOBALS['_theme_sections'] ?? [];
    $filtered = apply_filters('theme_section_definitions', $definitions);
    if (!is_array($filtered)) return [];

    $valid = [];
    foreach ($filtered as $name => $definition) {
        $name = strtolower(trim((string)$name));
        if (theme_section_name_is_valid($name) && is_array($definition)) {
            $valid[$name] = $definition;
        }
    }
    return $valid;
}

function theme_section_definition(string $name): array
{
    return theme_section_definitions()[strtolower(trim($name))] ?? [];
}

function theme_section_path_is_within(string $path, string $root): bool
{
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
}

/** Return a validated section directory owned by one installed theme. */
function theme_section_theme_directory(?PDO $pdo = null, bool $create = false, ?string $folder = null): ?string
{
    $themesRoot = realpath((string)VIEWS_BASE);
    if (!$themesRoot || !is_dir($themesRoot)) return null;

    $folder = $folder ?? (function_exists('get_active_theme_folder')
        ? get_active_theme_folder($pdo)
        : (function_exists('widget_active_theme_folder') ? widget_active_theme_folder($pdo) : DEFAULT_THEME_FOLDER));
    $folder = trim((string)$folder);
    if ($folder === '' || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/i', $folder) !== 1) return null;

    $themeRoot = realpath($themesRoot . DIRECTORY_SEPARATOR . $folder);
    if (!$themeRoot || !is_dir($themeRoot) || !theme_section_path_is_within($themeRoot, $themesRoot)) return null;

    $directory = $themeRoot . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'shortcodes' . DIRECTORY_SEPARATOR . 'section';
    if ($create && !is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return null;
    }

    $realDirectory = realpath($directory);
    if (!$realDirectory || !is_dir($realDirectory) || !theme_section_path_is_within($realDirectory, $themeRoot)) return null;
    return $realDirectory;
}

function theme_section_global_directory(): ?string
{
    $publicRoot = realpath((string)PUBLIC_PATH);
    if (!$publicRoot) return null;

    $directory = realpath($publicRoot . '/views/partials/shortcodes/section');
    if (!$directory || !is_dir($directory) || !theme_section_path_is_within($directory, $publicRoot)) return null;
    return $directory;
}

function theme_section_layout_directories(?PDO $pdo = null): array
{
    $directories = [];
    $active = theme_section_theme_directory($pdo);
    if ($active) $directories[] = $active;

    $default = theme_section_theme_directory($pdo, false, DEFAULT_THEME_FOLDER);
    if ($default && !in_array($default, $directories, true)) $directories[] = $default;

    $global = theme_section_global_directory();
    if ($global && !in_array($global, $directories, true)) $directories[] = $global;

    $filtered = apply_filters('theme_section_layout_directories', $directories, $pdo);
    if (!is_array($filtered)) return $directories;

    $allowedRoots = array_filter([$active, $default, $global]);
    $valid = [];
    foreach ($filtered as $directory) {
        $real = realpath((string)$directory);
        if (!$real || !is_dir($real)) continue;
        foreach ($allowedRoots as $root) {
            if (theme_section_path_is_within($real, $root)) {
                if (!in_array($real, $valid, true)) $valid[] = $real;
                break;
            }
        }
    }
    return $valid;
}

function theme_section_resolve_layout(string $name, ?PDO $pdo = null): ?string
{
    $name = strtolower(trim($name));
    if (!theme_section_name_is_valid($name)) return null;

    $directories = theme_section_layout_directories($pdo);
    $candidates = array_map(
        static fn(string $directory): string => $directory . DIRECTORY_SEPARATOR . $name . '.php',
        $directories
    );
    $filtered = apply_filters('theme_section_layout_candidates', $candidates, $name, $pdo);
    if (is_array($filtered)) $candidates = $filtered;

    foreach ($candidates as $candidate) {
        $real = realpath((string)$candidate);
        if (!$real || !is_file($real)) continue;
        foreach ($directories as $directory) {
            if (theme_section_path_is_within($real, $directory)) {
                $resolved = apply_filters('theme_section_resolved_layout', $real, $name, $pdo);
                $resolvedReal = is_string($resolved) ? realpath($resolved) : false;
                return $resolvedReal && is_file($resolvedReal) && theme_section_path_is_within($resolvedReal, $directory)
                    ? $resolvedReal
                    : $real;
            }
        }
    }
    return null;
}

function theme_section_default_label(string $name): string
{
    return ucwords(str_replace(['.', '_', '-'], ' ', $name));
}

function theme_section_safe_url(mixed $value): string
{
    $url = trim((string)$value);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) return '';
    if (preg_match('~^(?:[/?#]|\./|\.\./)~', $url)) return $url;

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme === null) return $url;
    if (!is_string($scheme)) return '';
    return in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true) ? $url : '';
}

function theme_section_semantic_fallback(string $name, array $attrs, array $definition): string
{
    $esc = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = trim((string)($attrs['title'] ?? $definition['label'] ?? theme_section_default_label($name)));
    $summary = trim((string)($attrs['summary'] ?? $attrs['text'] ?? $definition['description'] ?? ''));
    $url = theme_section_safe_url($attrs['url'] ?? '');
    $linkLabel = trim((string)($attrs['link_label'] ?? $attrs['cta_label'] ?? ''));

    $html = '<section class="theme-section theme-section--fallback" data-theme-section="' . $esc($name) . '">';
    if ($title !== '') $html .= '<h2>' . $esc($title) . '</h2>';
    if ($summary !== '') $html .= '<p>' . nl2br($esc($summary), false) . '</p>';
    if ($url !== '' && $linkLabel !== '') $html .= '<p><a href="' . $esc($url) . '">' . $esc($linkLabel) . '</a></p>';
    return $html . '</section>';
}

function render_theme_section(string $name, array $attrs = [], ?PDO $pdo = null, array $context = []): string
{
    $name = strtolower(trim($name));
    if (!theme_section_name_is_valid($name)) return '';

    static $renderStack = [];
    if (in_array($name, $renderStack, true) || count($renderStack) >= 20) return '';
    $renderStack[] = $name;

    try {
        $definition = theme_section_definition($name);
        $defaults = is_array($definition['defaults'] ?? null) ? $definition['defaults'] : [];
        $attrs = array_merge($defaults, $attrs);
        unset($attrs['name']);
        $filteredAttrs = apply_filters('theme_section_attrs', $attrs, $name, $definition, $context, $pdo);
        if (is_array($filteredAttrs)) $attrs = $filteredAttrs;

        do_action('theme_section_before_render', $name, $attrs, $context, $pdo);
        $layout = theme_section_resolve_layout($name, $pdo);
        if ($layout) {
            $esc = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeUrl = static fn(mixed $value): string => theme_section_safe_url($value);
            ob_start();
            try {
                (static function (string $__layout, array $__vars): void {
                    extract($__vars, EXTR_SKIP);
                    include $__layout;
                })($layout, [
                    'section' => $name,
                    'section_name' => $name,
                    'attrs' => $attrs,
                    'context' => $context,
                    'definition' => $definition,
                    'esc' => $esc,
                    'safe_url' => $safeUrl,
                    'pdo' => $pdo,
                ]);
                $html = (string)ob_get_clean();
            } catch (Throwable $e) {
                ob_end_clean();
                error_log('[theme_sections] render error for ' . $name . ': ' . $e->getMessage());
                $html = '';
            }
        } else {
            $fallback = $definition['fallback'] ?? null;
            if (is_callable($fallback)) {
                $html = (string)$fallback($attrs, $context, $pdo, $definition);
            } elseif (is_string($fallback) && $fallback !== '') {
                $html = $fallback;
            } else {
                $html = theme_section_semantic_fallback($name, $attrs, $definition);
            }
        }

        $html = (string)apply_filters('theme_section_html', $html, $name, $attrs, $context, $pdo, $layout);
        do_action('theme_section_after_render', $name, $html, $attrs, $context, $pdo);
        return $html;
    } catch (Throwable $e) {
        error_log('[theme_sections] error for ' . $name . ': ' . $e->getMessage());
        return '';
    } finally {
        array_pop($renderStack);
    }
}

if (function_exists('register_widget_shortcode_handler')) {
    register_widget_shortcode_handler(
        'theme_section',
        static function (PDO $pdo, array $attrs, array $context): string {
            $name = (string)($attrs['name'] ?? '');
            return render_theme_section($name, $attrs, $pdo, $context);
        },
        ['name' => '']
    );
}
