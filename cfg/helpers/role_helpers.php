<?php
// /jyavani-cfg/helpers/role_helpers.php

if (!function_exists('current_user_id')) {
    function current_user_id(): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('current_user_role')) {
    function current_user_role(?PDO $pdo = null): ?string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $role = $_SESSION['user_role'] ?? null;
        $uid  = (int)($_SESSION['user_id'] ?? 0);

        if (!$role && $uid > 0 && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $uid]);
            $role = $stmt->fetchColumn() ?: null;
            $_SESSION['user_role'] = $role;
        }

        return is_string($role) && $role !== '' ? $role : null;
    }
}

if (!function_exists('require_login')) {
    function require_login(string $redirect = '/adiwira/login.php'): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            header('Location: ' . $redirect);
            exit;
        }
    }
}

if (!function_exists('require_role')) {
    // contoh: require_role(['admin','editor'])
    function require_role(array $allowed): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $role = $_SESSION['user_role'] ?? null;
        if (!$role || !in_array($role, $allowed, true)) {
            http_response_code(403);
            exit('<p>Akses ditolak: Anda tidak memiliki izin untuk melakukan tindakan ini.</p>');
        }
    }
}