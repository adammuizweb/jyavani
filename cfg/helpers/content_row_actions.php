<?php
declare(strict_types=1);

if (!function_exists('content_row_actions_render')) {
    function content_row_actions_public_url(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || !str_starts_with($url, '/') || str_starts_with($url, '//')
            || str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url)) return null;
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !str_starts_with((string)($parts['path'] ?? ''), '/')) return null;
        $decodedPath = (string)$parts['path'];
        for ($i = 0; $i < 5; $i++) {
            $next = rawurldecode($decodedPath);
            if (!str_starts_with($next, '/') || str_starts_with($next, '//')
                || str_contains($next, '\\') || preg_match('/[\x00-\x1F\x7F]/', $next)) return null;
            $segments = explode('/', $next);
            if (in_array('.', $segments, true) || in_array('..', $segments, true)) return null;
            if ($next === $decodedPath) break;
            $decodedPath = $next;
            if ($i === 4) return null;
        }
        return $url;
    }

    /** Render validated non-destructive extension actions for a dashboard content row. */
    function content_row_actions_render(PDO $pdo, array $row, array $context): string
    {
        if (!function_exists('apply_filters') || !defined('ADMIN_BASE_PATH')) return '';

        $items = apply_filters('admin_content_row_actions', [], $row, $context, $pdo);
        if (!is_array($items) || !array_is_list($items)) return '';

        $basePath = rtrim((string)ADMIN_BASE_PATH, '/');
        $rendered = [];
        $keys = [];
        foreach ($items as $item) {
            if (count($rendered) >= 8) break;
            if (!is_array($item)) continue;
            $key = trim((string)($item['key'] ?? ''));
            $label = trim((string)($item['label'] ?? ''));
            $url = trim((string)($item['url'] ?? ''));
            $title = trim((string)($item['title'] ?? ''));
            if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/', $key) !== 1 || isset($keys[$key])
                || $label === '' || strlen($label) > 80 || preg_match('/[\x00-\x1F\x7F]/', $label)
                || $url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $url)
                || $title !== '' && (strlen($title) > 160 || preg_match('/[\x00-\x1F\x7F]/', $title))) {
                continue;
            }
            $parts = parse_url($url);
            if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || ($parts['path'] ?? '') !== $basePath . '/' || !isset($parts['query'])) {
                continue;
            }
            parse_str((string)$parts['query'], $query);
            $page = is_string($query['page'] ?? null) ? trim($query['page'], '/') : '';
            if (preg_match('/\A[a-z0-9_-]+(?:\/[a-z0-9_-]+)*\z/', $page) !== 1) continue;

            $keys[$key] = true;
            $attributes = ' class="adam-ubah" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
            if ($title !== '') $attributes .= ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
            $rendered[] = '<a' . $attributes . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        return implode('<span class="muted-divider">|</span>', $rendered);
    }
}
