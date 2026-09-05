<?php
declare(strict_types=1);

if (!function_exists('adiwira_notify_session_ready')) {
    function adiwira_notify_session_ready(): void
    {
        if (function_exists('ensure_session_started')) {
            ensure_session_started(true);
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}

if (!function_exists('adiwira_normalize_toast_type')) {
    function adiwira_normalize_toast_type(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['success', 'warning', 'info', 'error'], true) ? $type : 'info';
    }
}

if (!function_exists('adiwira_flash_push')) {
    function adiwira_flash_push(string $type, string $message, array $extra = []): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        adiwira_notify_session_ready();

        $_SESSION['adiwira_flash_toast'] = $_SESSION['adiwira_flash_toast'] ?? [];
        $_SESSION['adiwira_flash_toast'][] = array_merge([
            'type'    => adiwira_normalize_toast_type($type),
            'message' => $message,
        ], $extra);
    }
}

if (!function_exists('adiwira_flash_push_many')) {
    function adiwira_flash_push_many(array $items): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            adiwira_flash_push(
                (string)($item['type'] ?? 'info'),
                (string)($item['message'] ?? ($item['text'] ?? '')),
                $item
            );
        }
    }
}

if (!function_exists('adiwira_undo_issue')) {
    function adiwira_undo_issue(string $kind, int $targetId, int $actorId, array $state, int $ttl = 30): ?string
    {
        $kind = trim($kind);
        if ($kind === '' || $targetId <= 0 || $actorId <= 0) {
            return null;
        }

        adiwira_notify_session_ready();
        $now = time();
        $actions = is_array($_SESSION['adiwira_undo_actions'] ?? null)
            ? $_SESSION['adiwira_undo_actions']
            : [];
        foreach ($actions as $key => $action) {
            if (!is_array($action) || (int)($action['expires_at'] ?? 0) < $now) {
                unset($actions[$key]);
            }
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            return null;
        }
        $actions[hash('sha256', $token)] = [
            'kind' => $kind,
            'target_id' => $targetId,
            'actor_id' => $actorId,
            'state' => $state,
            'expires_at' => $now + max(10, min(120, $ttl)),
        ];
        $_SESSION['adiwira_undo_actions'] = array_slice($actions, -20, null, true);
        return $token;
    }
}

if (!function_exists('adiwira_undo_get')) {
    function adiwira_undo_get(string $token, string $kind, int $actorId): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 || $actorId <= 0) {
            return null;
        }

        adiwira_notify_session_ready();
        $key = hash('sha256', $token);
        $action = $_SESSION['adiwira_undo_actions'][$key] ?? null;
        if (!is_array($action)
            || (int)($action['expires_at'] ?? 0) < time()
            || !hash_equals((string)($action['kind'] ?? ''), $kind)
            || (int)($action['actor_id'] ?? 0) !== $actorId) {
            unset($_SESSION['adiwira_undo_actions'][$key]);
            return null;
        }
        return $action;
    }
}

if (!function_exists('adiwira_undo_consume')) {
    function adiwira_undo_consume(string $token): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return;
        }
        adiwira_notify_session_ready();
        unset($_SESSION['adiwira_undo_actions'][hash('sha256', $token)]);
    }
}

if (!function_exists('adiwira_flash_pull')) {
    function adiwira_flash_pull(): array
    {
        adiwira_notify_session_ready();

        $items = $_SESSION['adiwira_flash_toast'] ?? [];
        unset($_SESSION['adiwira_flash_toast']);

        if (!is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $message = trim((string)($item['message'] ?? ($item['text'] ?? '')));
            if ($message === '') {
                continue;
            }

            $cleanItem = [
                'type'     => adiwira_normalize_toast_type((string)($item['type'] ?? 'info')),
                'title'    => isset($item['title']) ? (string)$item['title'] : null,
                'message'  => $message,
                'duration' => isset($item['duration']) ? (int)$item['duration'] : null,
            ];
            $action = $item['action'] ?? null;
            $request = is_array($action) ? ($action['request'] ?? null) : null;
            if (is_array($action) && is_array($request)) {
                $label = trim((string)($action['label'] ?? ''));
                $url = trim((string)($request['url'] ?? ''));
                $adminBase = '/' . trim(defined('ADMIN_BASE_PATH') ? (string)ADMIN_BASE_PATH : '/adiwira', '/');
                if ($label !== '' && str_starts_with($url, $adminBase . '/')) {
                    $body = [];
                    foreach ((array)($request['body'] ?? []) as $key => $value) {
                        if (is_string($key) && (is_string($value) || is_int($value))) {
                            $body[$key] = $value;
                        }
                    }
                    $cleanItem['action'] = [
                        'label' => $label,
                        'request' => [
                            'url' => $url,
                            'body' => $body,
                            'errorMessage' => (string)($request['errorMessage'] ?? __('Failed to restore user.')),
                        ],
                    ];
                }
            }
            $clean[] = $cleanItem;
        }

        return $clean;
    }
}

if (!function_exists('adiwira_safe_return_to')) {
    function adiwira_safe_return_to(mixed $candidate, mixed $fallback = null): string
    {
        $adminBase = '/' . trim(defined('ADMIN_BASE_PATH') ? (string)ADMIN_BASE_PATH : '/adiwira', '/');
        $default = $adminBase . '/';
        $normalize = static function (mixed $value) use ($adminBase): ?string {
            if (!is_string($value)) return null;
            $value = trim($value);
            if ($value === '' || $value[0] !== '/' || str_contains($value, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) return null;

            $decoded = $value;
            for ($i = 0; $i < 5; $i++) {
                $next = rawurldecode($decoded);
                if ($next === $decoded) break;
                $decoded = $next;
            }
            if (rawurldecode($decoded) !== $decoded) return null;
            if (str_contains($decoded, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
                || str_starts_with($decoded, '//')
                || preg_match('#^(?:[a-z][a-z0-9+\-.]*:)?//#i', $decoded) === 1) {
                return null;
            }

            $parts = parse_url($decoded);
            if (!is_array($parts) || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['port'])) return null;
            $path = $parts['path'] ?? '';
            if (!is_string($path)) return null;
            foreach (explode('/', $path) as $segment) {
                if ($segment === '.' || $segment === '..') return null;
            }
            $path = (string)preg_replace('#/{2,}#', '/', $path);
            if ($path !== $adminBase && !str_starts_with($path, $adminBase . '/')) return null;

            $normalized = $path;
            if (isset($parts['query']) && $parts['query'] !== '') $normalized .= '?' . $parts['query'];
            if (isset($parts['fragment']) && $parts['fragment'] !== '') $normalized .= '#' . $parts['fragment'];
            return $normalized;
        };

        $safeFallback = $normalize($fallback) ?? $default;
        return $normalize($candidate) ?? $safeFallback;
    }
}

if (!function_exists('adiwira_redirect_with_flash')) {
    function adiwira_redirect_with_flash(string $location, string $type, string $message, int $status = 302, array $extra = []): void
    {
        adiwira_flash_push($type, $message, $extra);

        if (!headers_sent()) {
            header('Location: ' . $location, true, $status);
            exit;
        }

        $safeLocation = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');
        echo '<script>window.location.href=' . json_encode($location, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safeLocation . '"></noscript>';
        exit;
    }
}

if (!function_exists('adiwira_bootstrap_toasts_script')) {
    function adiwira_bootstrap_toasts_script(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        $json = json_encode(
            array_values($items),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        if (!is_string($json) || $json === '') {
            return '';
        }

        return '<script>'
            . 'window.__NEWNOTIF_BOOTSTRAP__=window.__NEWNOTIF_BOOTSTRAP__||{};'
            . 'window.__NEWNOTIF_BOOTSTRAP__.toast=(window.__NEWNOTIF_BOOTSTRAP__.toast||[]).concat(' . $json . ');'
            . 'document.dispatchEvent(new CustomEvent("newnotif:toast-boot"));'
            . '</script>';
    }
}

if (!function_exists('adiwira_collect_query_toasts')) {
    function adiwira_collect_query_toasts(array $map = ['msg' => 'success', 'err' => 'error', 'warn' => 'warning', 'info' => 'info']): array
    {
        $items = [];

        foreach ($map as $queryKey => $type) {
            if (!isset($_GET[$queryKey])) {
                continue;
            }

            $value = $_GET[$queryKey];
            $values = is_array($value) ? $value : [$value];

            foreach ($values as $raw) {
                $message = trim(urldecode((string)$raw));
                if ($message === '') {
                    continue;
                }

                $items[] = [
                    'type'    => adiwira_normalize_toast_type((string)$type),
                    'message' => $message,
                ];
            }
        }

        return $items;
    }
}
