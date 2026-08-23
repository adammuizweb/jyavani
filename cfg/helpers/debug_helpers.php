<?php
declare(strict_types=1);

if (!function_exists('app_debug_enabled')) {
    function app_debug_enabled(): bool
    {
        static $debug = null;
        if ($debug !== null) {
            return $debug;
        }

        $raw = getenv('APP_DEBUG');
        $raw = is_string($raw) ? trim($raw) : '';

        $debug = in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        return $debug;
    }
}

if (!function_exists('app_configure_error_reporting')) {
    function app_configure_error_reporting(): void
    {
        $debug = app_debug_enabled();

        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('display_startup_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        if ($debug) {
            error_reporting(E_ALL);
        } else {
            error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
        }
    }
}

if (!function_exists('app_safe_fatal_output')) {
    function app_safe_fatal_output(array $err): void
    {
        if (!headers_sent()) {
            http_response_code(500);
        }

        $debug = app_debug_enabled();

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }

        if ($debug) {
            echo '<pre style="white-space:pre-wrap;padding:16px;font:14px/1.5 monospace">';
            echo 'FATAL: ' . htmlspecialchars((string)($err['message'] ?? ''), ENT_QUOTES, 'UTF-8') . "\n";
            echo 'File: ' . htmlspecialchars((string)($err['file'] ?? ''), ENT_QUOTES, 'UTF-8');
            echo ' : line ' . (int)($err['line'] ?? 0);
            echo '</pre>';
            return;
        }

        echo 'Internal Server Error';
    }
}

if (!function_exists('app_register_shutdown_handler')) {
    function app_register_shutdown_handler(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        register_shutdown_function(function (): void {
            $err = error_get_last();
            if (!$err) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array((int)$err['type'], $fatalTypes, true)) {
                return;
            }

            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            error_log(sprintf(
                '[FATAL] %s in %s:%d',
                (string)($err['message'] ?? ''),
                (string)($err['file'] ?? ''),
                (int)($err['line'] ?? 0)
            ));

            app_safe_fatal_output($err);
        });
    }
}
