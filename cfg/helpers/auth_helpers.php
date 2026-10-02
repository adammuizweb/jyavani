<?php
// /jyavani-cfg/helpers/auth_helpers.php
// Safe helper file — does NOT redeclare session functions.

if (!defined('APP_HELPERS')) {
    define('APP_HELPERS', true);
}

/**
 * is_blocked - simple helper
 * @param array|null $attempt
 * @return bool
 */
function is_blocked($attempt): bool {
    if (!$attempt) return false;
    if (empty($attempt['blocked_until'])) return false;
    $ts = strtotime($attempt['blocked_until']);
    if ($ts === false) return false;
    return $ts > time();
}

/**
 * get_login_attempt(PDO $pdo, string $email, string $ip): ?array
 */
function get_login_attempt(PDO $pdo, string $email, string $ip): ?array {
    $stmt = $pdo->prepare(
        'SELECT MIN(id) AS id, email, ip_address, SUM(attempts) AS attempts,
                MAX(last_attempt) AS last_attempt, MAX(blocked_until) AS blocked_until
         FROM login_attempts
         WHERE email = ? AND ip_address = ?
         GROUP BY email, ip_address
         LIMIT 1'
    );
    $stmt->execute([$email, $ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function auth_verify_recaptcha(string $secret, string $response, string $ip): bool {
    $secret = trim($secret);
    $response = trim($response);
    if ($secret === '' || $response === '') return false;

    $payload = ['secret' => $secret, 'response' => $response];
    $ip = trim($ip);
    if ($ip !== '') $payload['remoteip'] = $ip;
    $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 8,
            'header' => "Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n",
            'content' => $body,
        ],
    ]);
    $raw = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    $result = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($result) && ($result['success'] ?? false) === true;
}

function auth_login_user_by_email(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare(
        'SELECT id, email, password, name, role, is_deleted, is_locked FROM users WHERE email = :email LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * record_failed_attempt(PDO $pdo, string $email, string $ip, int $maxAttempts = 5, int $blockMinutes = 15): int
 * returns new attempts count
 */
function record_failed_attempt(
    PDO $pdo,
    string $email,
    string $ip,
    int $maxAttempts = 5,
    int $blockMinutes = 15
): int {
    $maxAttempts = max(1, min(100, $maxAttempts));
    $blockMinutes = max(1, min(1440, $blockMinutes));
    $blockedUntil = date('Y-m-d H:i:s', time() + $blockMinutes * 60);
    $initialBlockedUntil = $maxAttempts === 1 ? $blockedUntil : null;

    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (email, ip_address, attempts, last_attempt, blocked_until)
         VALUES (?, ?, 1, NOW(), ?)
         ON DUPLICATE KEY UPDATE
             blocked_until = IF(attempts + 1 >= ?, ?, NULL),
             attempts = attempts + 1,
             last_attempt = NOW()'
    );
    $stmt->execute([$email, $ip, $initialBlockedUntil, $maxAttempts, $blockedUntil]);

    $attempt = get_login_attempt($pdo, $email, $ip);
    $attempts = max(1, (int)($attempt['attempts'] ?? 1));
    if ($attempts >= $maxAttempts && !is_blocked($attempt)) {
        $block = $pdo->prepare(
            'UPDATE login_attempts SET blocked_until = ? WHERE email = ? AND ip_address = ?'
        );
        $block->execute([$blockedUntil, $email, $ip]);
    }

    return $attempts;
}

/**
 * reset_login_attempts(PDO $pdo, string $email, string $ip): void
 */
function reset_login_attempts(PDO $pdo, string $email, string $ip): void {
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE email = ? AND ip_address = ?");
    $stmt->execute([$email, $ip]);
}

/**
 * Normalize a stored login/register path without decoding it a second time.
 */
function auth_normalize_configured_path(string $path): ?string {
    $path = trim($path, " \t\n\r\0\x0B/");
    if ($path === '' || preg_match('/\A[a-z0-9_\/.-]+\z/D', $path) !== 1) return null;
    return $path;
}

/**
 * auth_path_matches — check if current request URI matches a configured path
 * Used for customizable login/register paths
 */
function auth_path_matches(string $configuredPath): bool {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($uri)) return false;

    // Match router.php: separate the query first, then decode the path exactly once.
    $uri = auth_normalize_configured_path(rawurldecode($uri));
    $configuredPath = auth_normalize_configured_path($configuredPath);
    return $uri !== null && $configuredPath !== null && $uri === $configuredPath;
}

/**
 * get_login_path — read login_path setting (default: 'login')
 */
function get_login_path(PDO $pdo): string {
    return settings_get($pdo, 'login_path', 'login') ?? 'login';
}

/**
 * get_admin_path — read admin_path setting (default: 'dashboard')
 */
function get_admin_path(PDO $pdo): string {
    $path = settings_get($pdo, 'admin_path', 'dashboard') ?? 'dashboard';
    return '/' . trim($path, '/');
}

/**
 * get_register_path — read register_path setting (default: 'register')
 */
function get_register_path(PDO $pdo): string {
    return settings_get($pdo, 'register_path', 'register') ?? 'register';
}
