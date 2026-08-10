<?php
// /lpmi-cfg/db.php
try {
    $dsn = "mysql:host=" . env('DB_HOST') .
           ";dbname=" . env('DB_NAME') .
           ";port=" . env('DB_PORT', 3306) .
           ";charset=utf8mb4";

    $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $waitTimeout = trim((string)env('DB_SESSION_WAIT_TIMEOUT', ''));
    if ($waitTimeout !== '') {
        if (!preg_match('/^[1-9][0-9]*$/', $waitTimeout)
            || filter_var($waitTimeout, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31536000]]) === false) {
            throw new RuntimeException('DB_SESSION_WAIT_TIMEOUT must be an integer between 1 and 31536000.');
        }
        $pdo->exec('SET SESSION wait_timeout = ' . $waitTimeout);
    }
} catch (Throwable $e) {
    error_log("[DB] Connection failed: " . $e->getMessage());

    $detail = function_exists('app_debug_enabled') && app_debug_enabled()
        ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        : '';

    $connectionFailed = function_exists('__') ? __('Database connection failed.') : 'Database connection failed.';
    $failedToConnect = function_exists('__') ? __('Failed to connect to database.') : 'Failed to connect to database.';
    $msg = $detail !== '' ? $connectionFailed . ' ' . $detail : $failedToConnect;

    http_response_code(500);
    exit($msg);
}
