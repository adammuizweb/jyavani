<?php
declare(strict_types=1);

function dashboard_home_notice_log_hook_errors(array $errors): void
{
    foreach ($errors as $error) {
        if (!is_array($error)) continue;
        error_log(sprintf(
            '[dashboard-home-notices] %s priority %d listener %d: %s',
            (string)($error['hook'] ?? 'unknown'),
            (int)($error['priority'] ?? 0),
            (int)($error['listener'] ?? 0),
            (string)($error['message'] ?? 'Listener failed.')
        ));
    }
}

function dashboard_home_notice_text(mixed $value, int $maximumBytes, bool $multiline = false): ?string
{
    if (!is_string($value)) return null;
    $value = trim($value);
    if ($value === '' || strlen($value) > $maximumBytes || preg_match('//u', $value) !== 1) return null;
    $controls = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
    return preg_match($controls, $value) ? null : $value;
}

/** Return a safe root-relative or HTTPS notice action URL. */
function dashboard_home_notice_action_url(mixed $value): ?array
{
    if (!is_string($value)) return null;
    $url = trim($value);
    if ($url === '' || strlen($url) > 2048 || str_contains($url, '\\')
        || preg_match('/[\x00-\x1F\x7F]/', $url)) return null;

    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) return null;
    if (str_starts_with($url, '/')) {
        if (str_starts_with($url, '//') || isset($parts['scheme']) || isset($parts['host'])
            || !str_starts_with((string)($parts['path'] ?? ''), '/')) return null;
        $decodedPath = (string)$parts['path'];
        for ($i = 0; $i < 5; $i++) {
            $next = rawurldecode($decodedPath);
            if (!str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $next)) return null;
            $segments = explode('/', $next);
            if (in_array('.', $segments, true) || in_array('..', $segments, true)) return null;
            if ($next === $decodedPath) break;
            $decodedPath = $next;
            if ($i === 4) return null;
        }
        return ['url' => $url, 'external' => false];
    }

    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) return null;
    return ['url' => $url, 'external' => true];
}

/** Normalize untrusted extension notice declarations into the schema Core renders. */
function dashboard_home_notices_normalize(mixed $items): array
{
    if (!is_array($items) || !array_is_list($items)) return [];

    $normalized = [];
    $seen = [];
    foreach ($items as $item) {
        if (count($normalized) >= 12) break;
        if (!is_array($item)) continue;

        $id = is_string($item['id'] ?? null) ? trim($item['id']) : '';
        $type = is_string($item['type'] ?? null) ? strtolower(trim($item['type'])) : 'info';
        $titleValue = $item['title'] ?? '';
        $title = is_string($titleValue) && trim($titleValue) === ''
            ? ''
            : dashboard_home_notice_text($titleValue, 160);
        $message = dashboard_home_notice_text($item['message'] ?? null, 2000, true);
        $revisionValue = $item['revision'] ?? '1';
        $revision = is_int($revisionValue) || is_string($revisionValue) ? trim((string)$revisionValue) : '';
        $dismissible = $item['dismissible'] ?? true;
        $iconValue = is_string($item['icon'] ?? null) ? trim($item['icon']) : '';
        $icon = in_array($iconValue, ['bell', 'circle-check', 'alert-triangle', 'circle-x', 'shield-check'], true)
            ? $iconValue
            : '';

        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/', $id) !== 1 || isset($seen[$id])
            || !in_array($type, ['info', 'success', 'warning', 'error'], true)
            || $title === null || $message === null
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/', $revision) !== 1
            || !is_bool($dismissible)) {
            continue;
        }

        $action = null;
        if (isset($item['action']) && is_array($item['action'])) {
            $label = dashboard_home_notice_text($item['action']['label'] ?? null, 80);
            $target = dashboard_home_notice_action_url($item['action']['url'] ?? null);
            if ($label !== null && $target !== null) {
                $action = ['label' => $label] + $target;
            }
        }

        $seen[$id] = true;
        $normalized[] = [
            'id' => $id,
            'revision' => $revision,
            'type' => $type,
            'icon' => $icon,
            'title' => $title,
            'message' => $message,
            'action' => $action,
            'dismissible' => $dismissible,
        ];
    }
    return $normalized;
}

/** Collect Core and extension notices while isolating invalid or throwing listeners. */
function dashboard_home_notices_collect(PDO $pdo, array $context, array $coreNotices = []): array
{
    $result = apply_filters_isolated(
        'dashboard_home_notices',
        $coreNotices,
        static fn(mixed $value): bool => is_array($value) && array_is_list($value),
        $context,
        $pdo
    );
    dashboard_home_notice_log_hook_errors($result['errors']);

    // Core notices are prepended again so an extension cannot silently remove them.
    $filtered = is_array($result['value']) ? $result['value'] : [];
    return dashboard_home_notices_normalize(array_merge($coreNotices, $filtered));
}

function dashboard_home_notices_render(array $items, array $context): string
{
    $items = dashboard_home_notices_normalize($items);
    if ($items === []) return '';

    $actorId = max(0, (int)($context['user_id'] ?? 0));
    $icons = [
        'info' => 'bell',
        'success' => 'circle-check',
        'warning' => 'alert-triangle',
        'error' => 'circle-x',
    ];
    $output = '<section class="dw-notices" id="dw-notices" data-user-id="' . $actorId . '">';
    foreach ($items as $item) {
        $type = $item['type'];
        $role = in_array($type, ['warning', 'error'], true) ? 'alert' : 'status';
        $icon = $item['icon'] !== '' ? $item['icon'] : $icons[$type];
        $output .= '<article class="dw-notice dw-notice--' . $type . '" role="' . $role
            . '" data-notice-id="' . htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8')
            . '" data-notice-revision="' . htmlspecialchars($item['revision'], ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="dw-notice-icon">' . svg_ico($icon) . '</span>'
            . '<div class="dw-notice-content">';
        if ($item['title'] !== '') {
            $output .= '<strong class="dw-notice-title">' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</strong>';
        }
        $output .= '<p class="dw-notice-message">' . htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') . '</p>';
        if (is_array($item['action'])) {
            $attributes = ' class="dw-notice-action" href="' . htmlspecialchars($item['action']['url'], ENT_QUOTES, 'UTF-8') . '"';
            if ($item['action']['external']) $attributes .= ' target="_blank" rel="noopener noreferrer"';
            $output .= '<a' . $attributes . '>' . htmlspecialchars($item['action']['label'], ENT_QUOTES, 'UTF-8') . '</a>';
        }
        $output .= '</div>';
        if ($item['dismissible']) {
            $closeLabel = htmlspecialchars(__('Close notification'), ENT_QUOTES, 'UTF-8');
            $output .= '<button type="button" class="dw-notice-dismiss" aria-label="' . $closeLabel
                . '" title="' . $closeLabel . '">' . svg_ico('x') . '</button>';
        }
        $output .= '</article>';
    }
    return $output . '</section>';
}
