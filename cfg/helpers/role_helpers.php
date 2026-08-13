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
    function require_login(?string $redirect = null): void
    {
        if ($redirect === null) {
            $redirect = (defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira') . '/index.php';
        }
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

if (!function_exists('content_access_scope_allows_role')) {
    function content_access_scope_allows_role(?string $role, string $scope): bool
    {
        $role = strtolower(trim((string)$role));
        $scope = strtolower(trim($scope));

        if (in_array($scope, ['employee', 'both'], true)) {
            $scope = 'editorial';
        }

        if ($scope === 'admin') {
            return $role === 'admin';
        }

        if ($scope === 'editorial') {
            return in_array($role, ['author', 'editor', 'admin'], true);
        }

        return false;
    }
}

if (!function_exists('content_access_scope_allows')) {
    function content_access_scope_allows(PDO $pdo, string $scope): bool
    {
        if (!function_exists('is_logged_in') || !is_logged_in()) {
            return false;
        }

        return content_access_scope_allows_role(current_user_role($pdo), $scope);
    }
}

if (!function_exists('content_access_scope_label')) {
    function content_access_scope_label(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if (in_array($scope, ['editorial', 'employee', 'both'], true)) {
            return function_exists('__') ? __('Content Team') : 'Content Team';
        }
        if ($scope === 'admin') {
            return function_exists('__') ? __('Administrator') : 'Administrator';
        }
        return function_exists('__') ? __('Public') : 'Public';
    }
}
