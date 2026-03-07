<?php
declare(strict_types=1);

// /adiwira/admin/_guard.php
require_once __DIR__ . '/../bootstrap.php';

// session
if (function_exists('ensure_session_started')) {
  ensure_session_started(true);
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/** JSON responder */
if (!function_exists('adiwira_json')) {
  function adiwira_json(array $payload, int $status = 200): void {
    $GLOBALS['__ADIWIRA_JSON_SENT'] = true;
    while (ob_get_level() > 0) { @ob_end_clean(); }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/**
 * Role resolver: DB adalah sumber kebenaran (menghindari stale dari helper/session).
 * Cache di session hanya untuk UI/performansi, tapi tetap bisa direfresh.
 */
if (!function_exists('adiwira_resolve_role_db')) {
  function adiwira_resolve_role_db(PDO $pdo, int $uid): string {
    $role = null;

    // cache TTL (biar ga query terus, tapi tetap aman dari stale)
    $ttl = 20; // detik
    $cachedRole = $_SESSION['user_role'] ?? null;
    $checkedAt  = (int)($_SESSION['user_role_checked_at'] ?? 0);

    if (is_string($cachedRole) && trim($cachedRole) !== '' && (time() - $checkedAt) < $ttl) {
      $role = $cachedRole;
    }

    if (!$role) {
      try {
        $st = $pdo->prepare("SELECT role FROM users WHERE id=:id AND is_deleted=0 LIMIT 1");
        $st->execute([':id' => $uid]);
        $dbRole = $st->fetchColumn();
        if (is_string($dbRole) && trim($dbRole) !== '') $role = $dbRole;
      } catch (Throwable $e) {
        // fallback (kalau DB error)
        $role = is_string($cachedRole) ? $cachedRole : null;
      }

      $role = is_string($role) ? strtolower(trim($role)) : 'guest';
      if ($role === '') $role = 'guest';

      $_SESSION['user_role'] = $role;
      $_SESSION['user_role_checked_at'] = time();
    }

    return $role;
  }
}

if (!function_exists('adiwira_user')) {
  function adiwira_user(PDO $pdo): array {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return [0, 'guest'];
    $role = adiwira_resolve_role_db($pdo, $uid);
    return [$uid, $role];
  }
}

if (!function_exists('adiwira_require_login')) {
  function adiwira_require_login(PDO $pdo, bool $asJson = true): array {
    [$uid, $role] = adiwira_user($pdo);
    if ($uid <= 0) {
      if ($asJson) adiwira_json(['ok'=>false,'error'=>'Not found'], 404);
      http_response_code(404);
      require __DIR__ . '/../../frontend_404.php';
      exit;
    }
    return [$uid, $role];
  }
}

if (!function_exists('adiwira_require_role')) {
  function adiwira_require_role(PDO $pdo, array $roles, bool $asJson = true): array {
    [$uid, $role] = adiwira_require_login($pdo, $asJson);
    $roles = array_map(fn($r) => strtolower(trim((string)$r)), $roles);

    if (!in_array($role, $roles, true)) {
      if ($asJson) adiwira_json(['ok'=>false,'error'=>'Not found'], 404);
      http_response_code(404);
      require __DIR__ . '/../../frontend_404.php';
      exit;
    }
    return [$uid, $role];
  }
}

if (!function_exists('adiwira_require_admin')) {
  function adiwira_require_admin(PDO $pdo, bool $asJson = true): array {
    return adiwira_require_role($pdo, ['admin'], $asJson);
  }
}

if (!function_exists('adiwira_csrf_validate')) {
  function adiwira_csrf_validate(?string $token): bool {
    $token = is_string($token) ? $token : '';
    if ($token === '') return false;
    return function_exists('csrf_check') ? (bool)csrf_check($token) : false;
  }
}

function adiwira_is_navigate_request(): bool {
  $mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
  $dest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
  if ($mode === 'navigate') return true;
  if ($dest === 'document') return true;
  return false;
}

function adiwira_cosmetic_404_on_direct_open(): void {
  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  if ($method !== 'GET') return;

  $mode   = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
  $dest   = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
  $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
  $xrw    = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

  $looksNavigate =
    ($mode === 'navigate') ||
    ($dest === 'document') ||
    ((strpos($accept, 'text/html') !== false) && ($xrw !== 'xmlhttprequest'));

  if ($looksNavigate) {
    http_response_code(404);
    require __DIR__ . '/../../frontend_404.php';
    exit;
  }
}