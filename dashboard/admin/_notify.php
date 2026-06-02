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

            $clean[] = [
                'type'     => adiwira_normalize_toast_type((string)($item['type'] ?? 'info')),
                'title'    => isset($item['title']) ? (string)$item['title'] : null,
                'message'  => $message,
                'duration' => isset($item['duration']) ? (int)$item['duration'] : null,
            ];
        }

        return $clean;
    }
}

if (!function_exists('adiwira_safe_return_to')) {
    function adiwira_safe_return_to(?string $candidate, ?string $fallback = null): string
    {
        $fallback = trim((string)$fallback);
        if ($fallback === '') {
            $fallback = (defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira') . '/';
        }

        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return $fallback;
        }

        if (preg_match('#^(?:[a-z][a-z0-9+\-.]*:)?//#i', $candidate)) {
            return $fallback;
        }

        if ($candidate[0] !== '/') {
            return $fallback;
        }

        return $candidate;
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