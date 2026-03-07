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
