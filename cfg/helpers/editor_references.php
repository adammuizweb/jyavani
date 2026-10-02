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

if (defined('EDITOR_REFERENCES_INCLUDED')) return;
define('EDITOR_REFERENCES_INCLUDED', true);

function register_editor_reference_provider(string $id, callable $provider): bool
{
    $id = strtolower(trim($id));
    if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $id) !== 1) return false;
    if (isset($GLOBALS['_editor_reference_providers'][$id])) return false;
    $GLOBALS['_editor_reference_providers'][$id] = $provider;
    return true;
}

function editor_reference_text(mixed $value, int $maxBytes = 200): string
{
    $value = trim((string)$value);
    if ($value === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) return '';
    return strlen($value) <= $maxBytes ? $value : '';
}

function editor_reference_admin_url(mixed $value, string $adminBasePath): string
{
    $url = trim((string)$value);
    $base = '/' . trim($adminBasePath, '/');
    if ($url === '' || $base === '/' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) return '';
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) return '';

    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])
        || isset($parts['pass']) || isset($parts['port'])) return '';
    $path = (string)($parts['path'] ?? '');
    if ($path !== $base && !str_starts_with($path, $base . '/')) return '';
    $decodedPath = $path;
    for ($pass = 0; $pass < 4; $pass++) {
        $next = rawurldecode($decodedPath);
        if ($next === $decodedPath) break;
        $decodedPath = $next;
    }
    if (preg_match('/[\x00-\x1F\x7F\\\\]/', $decodedPath)) return '';
    foreach (explode('/', $decodedPath) as $segment) {
        if ($segment === '.' || $segment === '..') return '';
    }
    return $url;
}

function editor_reference_descriptor(array $input, string $adminBasePath): ?array
{
    $title = editor_reference_text($input['title'] ?? '', 240);
    $source = editor_reference_text($input['source'] ?? '', 160);
    $actionLabel = editor_reference_text($input['action_label'] ?? '', 160);
    $url = editor_reference_admin_url($input['url'] ?? '', $adminBasePath);
    if ($title === '') return null;
    if (($url === '') !== ($actionLabel === '')) return null;

    return [
        'title' => $title,
        'source' => $source,
        'action_label' => $actionLabel,
        'url' => $url,
    ];
}

function editor_reference_provider_config(string $id, mixed $input, string $adminBasePath): ?array
{
    if (!is_array($input) || !in_array($input['syntax'] ?? null, ['widget', 'shortcode'], true)) return null;
    $syntax = (string)$input['syntax'];
    $shortcode = strtolower(editor_reference_text($input['shortcode'] ?? '', 64));
    $attribute = editor_reference_text($input['attribute'] ?? '', 64);
    $label = editor_reference_text($input['label'] ?? '', 160);
    $valuePattern = editor_reference_text($input['value_pattern'] ?? '', 300);
    $normalize = ($input['normalize'] ?? null) === 'lowercase' ? 'lowercase' : 'exact';
    $trim = ($input['trim'] ?? false) === true;
    $maxBytes = filter_var($input['max_bytes'] ?? 160, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 1000],
    ]);
    if (!is_int($maxBytes)) $maxBytes = 160;
    if (preg_match('/\A[a-z0-9_-]+\z/', $shortcode) !== 1
        || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]*\z/', $attribute) !== 1
        || $label === '' || $valuePattern === '') return null;

    $entries = [];
    foreach (is_array($input['entries'] ?? null) ? $input['entries'] : [] as $value => $descriptor) {
        if (count($entries) >= 1000) break;
        if (!is_string($value)) continue;
        $value = editor_reference_text($value, $maxBytes);
        $normalized = is_array($descriptor) ? editor_reference_descriptor($descriptor, $adminBasePath) : null;
        if ($value !== '' && $normalized !== null) $entries[$value] = $normalized;
    }

    $fallback = is_array($input['fallback'] ?? null)
        ? editor_reference_descriptor($input['fallback'], $adminBasePath)
        : null;
    $fallbackParameter = editor_reference_text($input['fallback_parameter'] ?? '', 64);
    if ($fallback !== null && ($fallback['url'] === ''
        || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]*\z/', $fallbackParameter) !== 1)) {
        $fallback = null;
    }

    return [
        'id' => $id,
        'syntax' => $syntax,
        'shortcode' => $shortcode,
        'attribute' => $attribute,
        'label' => $label,
        'value_pattern' => $valuePattern,
        'normalize' => $normalize,
        'trim' => $trim,
        'max_bytes' => $maxBytes,
        'entries' => $entries,
        'fallback' => $fallback,
        'fallback_parameter' => $fallback !== null ? $fallbackParameter : '',
    ];
}

function editor_reference_configuration(PDO $pdo, array $context): array
{
    $adminBasePath = editor_reference_text($context['admin_base_path'] ?? '', 240);
    if ($adminBasePath === '' || !str_starts_with($adminBasePath, '/')) return [];

    $providers = [];
    foreach (($GLOBALS['_editor_reference_providers'] ?? []) as $id => $provider) {
        if (count($providers) >= 16 || !is_callable($provider)) break;
        try {
            $normalized = editor_reference_provider_config(
                (string)$id,
                $provider($pdo, $context),
                $adminBasePath
            );
            if ($normalized !== null) $providers[] = $normalized;
        } catch (Throwable $e) {
            error_log('[editor-references] provider failed: ' . (string)$id . ': ' . $e->getMessage());
        }
    }
    if ($providers === []) return [];

    return [
        'schema' => 1,
        'admin_base_path' => '/' . trim($adminBasePath, '/'),
        'providers' => $providers,
        'strings' => [
            'source' => function_exists('__') ? __('Source:') : 'Source:',
            'open_new_tab' => function_exists('__') ? __('Open in new tab') : 'Open in new tab',
            'shortcut' => function_exists('__')
                ? __('Ctrl/Cmd-click or press F12 to open this reference in a new tab.')
                : 'Ctrl/Cmd-click or press F12 to open this reference in a new tab.',
        ],
    ];
}
