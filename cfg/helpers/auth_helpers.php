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
    $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE email = ? AND ip_address = ? LIMIT 1");
    $stmt->execute([$email, $ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * record_failed_attempt(PDO $pdo, string $email, string $ip): int
 * returns new attempts count
 */
function record_failed_attempt(PDO $pdo, string $email, string $ip): int {
    // try to acquire current row
    $stmt = $pdo->prepare("SELECT id, attempts FROM login_attempts WHERE email = ? AND ip_address = ? LIMIT 1");
    $stmt->execute([$email, $ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $attempts = (int)$row['attempts'] + 1;
        $blocked_until = null;
        if ($attempts >= 5) {
            // block for 15 minutes
            $blocked_until = date("Y-m-d H:i:s", time() + 15 * 60);
        }

        $upd = $pdo->prepare("UPDATE login_attempts SET attempts = ?, last_attempt = NOW(), blocked_until = ? WHERE id = ?");
        $upd->execute([$attempts, $blocked_until, $row['id']]);

        return $attempts;
    }

    // insert new
    $ins = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, attempts, last_attempt, blocked_until) VALUES (?, ?, 1, NOW(), NULL)");
    $ins->execute([$email, $ip]);
    return 1;
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
