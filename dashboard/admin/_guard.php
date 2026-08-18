<?php
declare(strict_types=1);

// /adiwira/admin/_guard.php
require_once __DIR__ . '/../bootstrap.php';

// Resume session yang sudah ada saja.
// Jangan create session baru untuk guest pada endpoint admin.
if (function_exists('ensure_session_started')) {
    ensure_session_started(false);
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** JSON responder */
if (!function_exists('adiwira_json')) {
    function adiwira_json(array $payload, int $status = 200): void
    {
        $GLOBALS['__ADIWIRA_JSON_SENT'] = true;

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('adiwira_render_404')) {
    function adiwira_render_404(): void
    {
        http_response_code(404);
        require FRONTEND_404_PATH;
        exit;
    }
}

/**
 * Ambil identitas aktif dari DB.
 * DB adalah sumber kebenaran untuk authz:
 * - session harus valid via is_logged_in()
 * - user harus ada
 * - user tidak boleh is_deleted=1
 * - user tidak boleh is_locked=1
 */
if (!function_exists('adiwira_fetch_identity')) {
    function adiwira_fetch_identity(PDO $pdo): array
    {
        if (!function_exists('is_logged_in') || !is_logged_in()) {
            return [
                'ok'     => false,
                'uid'    => 0,
                'role'   => 'guest',
                'user'   => null,
                'reason' => 'guest',
            ];
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return [
                'ok'     => false,
                'uid'    => 0,
                'role'   => 'guest',
                'user'   => null,
                'reason' => 'guest',
            ];
        }

        try {
            $st = $pdo->prepare("
                SELECT id, email, username, name, role, is_site_owner, is_deleted, is_locked
                FROM users
                WHERE id = :id
                LIMIT 1
            ");
            $st->execute([':id' => $uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $row = null;
        }

        if (!$row) {
            return [
                'ok'     => false,
                'uid'    => 0,
                'role'   => 'guest',
                'user'   => null,
                'reason' => 'missing',
            ];
        }

        if ((int)($row['is_deleted'] ?? 0) === 1) {
            return [
                'ok'     => false,
                'uid'    => (int)$uid,
                'role'   => 'guest',
                'user'   => $row,
                'reason' => 'deleted',
            ];
        }

        if ((int)($row['is_locked'] ?? 0) === 1) {
            return [
                'ok'     => false,
                'uid'    => (int)$uid,
                'role'   => 'guest',
                'user'   => $row,
                'reason' => 'locked',
            ];
        }

        $storedRole = strtolower(trim((string)($row['role'] ?? 'none')));
        $role = function_exists('authorization_active_legacy_role')
            ? authorization_active_legacy_role($pdo, $uid)
            : $storedRole;
        if ($role === '') {
            $role = 'none';
        }
        if ($role !== $storedRole) {
            try {
                $syncRole = $pdo->prepare('UPDATE users SET role = :role, updated_at = NOW() WHERE id = :id');
                $syncRole->execute([':role' => $role, ':id' => $uid]);
                $row['role'] = $role;
            } catch (Throwable $e) {
                $role = 'none';
            }
        }

        // sinkronkan session agar UI stabil
        $_SESSION['user_role'] = $role;
        $_SESSION['user_role_checked_at'] = time();

        return [
            'ok'     => true,
            'uid'    => (int)$uid,
            'role'   => $role,
            'is_site_owner' => (int)($row['is_site_owner'] ?? 0) === 1,
            'user'   => $row,
            'reason' => 'active',
        ];
    }
}

if (!function_exists('adiwira_user')) {
    function adiwira_user(PDO $pdo): array
    {
        $identity = adiwira_fetch_identity($pdo);
        if (($identity['ok'] ?? false) !== true) {
            return [0, 'guest'];
        }

        return [
            (int)($identity['uid'] ?? 0),
            (string)($identity['role'] ?? 'guest'),
        ];
    }
}

if (!function_exists('adiwira_require_login')) {
    function adiwira_require_login(PDO $pdo, bool $asJson = true): array
    {
        $identity = adiwira_fetch_identity($pdo);

        if (($identity['ok'] ?? false) !== true) {
            $reason = (string)($identity['reason'] ?? 'guest');

            // Kalau session ada tapi user invalid/locked/deleted, paksa logout.
            if (in_array($reason, ['missing', 'deleted', 'locked'], true) && function_exists('logout_user')) {
                logout_user();
            }

            if ($asJson) {
                adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
            }

            adiwira_render_404();
        }

        return [
            (int)($identity['uid'] ?? 0),
            (string)($identity['role'] ?? 'guest'),
        ];
    }
}

if (!function_exists('adiwira_require_role')) {
    function adiwira_require_role(PDO $pdo, array $roles, bool $asJson = true): array
    {
        [$uid, $role] = adiwira_require_login($pdo, $asJson);

        $roles = array_values(array_filter(array_map(
            static fn($r) => strtolower(trim((string)$r)),
            $roles
        )));

        $identity = adiwira_fetch_identity($pdo);
        $isSiteOwner = ($identity['is_site_owner'] ?? false) === true;
        if (!$isSiteOwner && !in_array($role, $roles, true)) {
            if ($asJson) {
                adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
            }

            adiwira_render_404();
        }

        return [$uid, $role];
    }
}

if (!function_exists('adiwira_require_permission')) {
    function adiwira_require_permission(PDO $pdo, string $permission, bool $asJson = true, array $context = []): array
    {
        [$uid, $role] = adiwira_require_login($pdo, $asJson);
        if (!function_exists('user_can') || !user_can($pdo, $uid, $permission, $context)) {
            if ($asJson) {
                adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
            }
            adiwira_render_404();
        }

        return [$uid, $role];
    }
}

if (!function_exists('adiwira_authorize_resource')) {
    function adiwira_authorize_resource(
        PDO $pdo,
        string $permission,
        int $ownerId,
        bool $asJson = true
    ): array {
        return adiwira_require_permission($pdo, $permission, $asJson, ['owner_id' => $ownerId]);
    }
}

if (!function_exists('adiwira_require_permission_scope')) {
    function adiwira_require_permission_scope(PDO $pdo, string $permission, bool $asJson = true): array
    {
        [$uid, $role] = adiwira_require_login($pdo, $asJson);
        $scope = function_exists('user_permission_scope')
            ? user_permission_scope($pdo, $uid, $permission)
            : null;
        if ($scope === null) {
            if ($asJson) {
                adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
            }
            adiwira_render_404();
        }
        return [$uid, $role, $scope];
    }
}

if (!function_exists('adiwira_require_site_owner')) {
    function adiwira_require_site_owner(PDO $pdo, bool $asJson = true): array
    {
        [$uid, $role] = adiwira_require_login($pdo, $asJson);
        $actor = function_exists('authorization_actor') ? authorization_actor($pdo, $uid) : null;
        if ($actor === null || $actor['is_site_owner'] !== true) {
            if ($asJson) {
                adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
            }
            adiwira_render_404();
        }
        return [$uid, $role];
    }
}

if (!function_exists('adiwira_require_admin')) {
    function adiwira_require_admin(PDO $pdo, bool $asJson = true): array
    {
        return adiwira_require_role($pdo, ['admin'], $asJson);
    }
}

if (!function_exists('adiwira_csrf_validate')) {
    function adiwira_csrf_validate(?string $token): bool
    {
        $token = is_string($token) ? $token : '';
        if ($token === '') {
            return false;
        }

        return function_exists('csrf_check') ? (bool)csrf_check($token) : false;
    }
}

if (!function_exists('adiwira_require_editorial')) {
    function adiwira_require_editorial(PDO $pdo, bool $asJson = true): array
    {
        return adiwira_require_role($pdo, ['author', 'editor', 'admin'], $asJson);
    }
}

if (!function_exists('adiwira_is_navigate_request')) {
    function adiwira_is_navigate_request(): bool
    {
        $mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
        $dest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));

        if ($mode === 'navigate') {
            return true;
        }

        if ($dest === 'document') {
            return true;
        }

        return false;
    }
}

if (!function_exists('adiwira_cosmetic_404_on_direct_open')) {
    function adiwira_cosmetic_404_on_direct_open(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            return;
        }

        $mode   = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
        $dest   = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xrw    = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        $looksNavigate =
            ($mode === 'navigate') ||
            ($dest === 'document') ||
            ((strpos($accept, 'text/html') !== false) && ($xrw !== 'xmlhttprequest'));

        if ($looksNavigate) {
            adiwira_render_404();
        }
    }
}
