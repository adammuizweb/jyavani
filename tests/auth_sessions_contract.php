<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

require_once $root . '/cfg/helpers/auth_sessions.php';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    name TEXT,
    username TEXT,
    email TEXT,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    is_locked INTEGER NOT NULL DEFAULT 0
)');
$pdo->exec('CREATE TABLE user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_key_hash TEXT NOT NULL UNIQUE,
    user_id INTEGER NOT NULL,
    created_at_utc TEXT NOT NULL,
    last_seen_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NOT NULL,
    revoked_at_utc TEXT,
    revoked_by_user_id INTEGER,
    revoke_reason TEXT,
    ip_address BLOB,
    user_agent_hash TEXT,
    user_agent TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (revoked_by_user_id) REFERENCES users(id) ON DELETE SET NULL
)');
$pdo->exec("INSERT INTO users (id, name, username, email) VALUES
    (1, 'Contract User', 'contract-user', 'contract@example.test'),
    (2, 'Other User', 'other-user', 'other@example.test')");

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';

$hash1 = hash('sha256', 'contract-session-one');
auth_sessions_establish($pdo, 1, $hash1, 3600);
$active = auth_sessions_validate($pdo, 1, $hash1, 3600);
$check($active['ok'] === true && $active['reason'] === 'active', 'an established session validates for its exact user');
$check(auth_sessions_validate($pdo, 2, $hash1, 3600)['ok'] === false, 'a session cannot validate for another user');

$listed = auth_sessions_for_user($pdo, 1, $hash1);
$check(count($listed) === 1 && $listed[0]['active'] === true && $listed[0]['current'] === true,
    'session listing identifies active and current state');
$check($listed[0]['ip_address'] === '203.0.113.7'
    && auth_sessions_device_label($listed[0]['user_agent']) === 'Chrome / Linux',
    'session listing exposes bounded display metadata without a transport ID');
$recent = auth_sessions_recent($pdo, $hash1, 5);
$check(count($recent) === 1
    && $recent[0]['user_id'] === 1
    && $recent[0]['user_label'] === 'Contract User'
    && $recent[0]['current'] === true
    && !array_key_exists('email', $recent[0])
    && !array_key_exists('ip_address', $recent[0])
    && !array_key_exists('session_key_hash', $recent[0])
    && !array_key_exists('user_agent', $recent[0]),
    'cross-user recent login projection identifies users without exposing private session metadata');

$hash2 = hash('sha256', 'contract-session-two');
auth_sessions_establish($pdo, 1, $hash2, 3600);
$check(auth_sessions_revoke($pdo, $hash2, 1, 'manual', 2, false) === 0,
    'exact-session revocation enforces the expected owner');
$check(auth_sessions_revoke($pdo, $hash2, 1, 'manual', 1, false) === 1
    && auth_sessions_validate($pdo, 1, $hash2, 3600)['reason'] === 'inactive',
    'revocation makes a session invalid without deleting its recent-login record');

$hash3 = hash('sha256', 'contract-session-three');
auth_sessions_establish($pdo, 1, $hash3, 3600);
$pdo->exec('UPDATE users SET is_locked = 1 WHERE id = 1');
$check(auth_sessions_validate($pdo, 1, $hash3, 3600)['ok'] === false,
    'registry validation rejects a locked account');
$pdo->exec('UPDATE users SET is_locked = 0 WHERE id = 1');

$expiredHash = hash('sha256', 'contract-session-expired');
$old = auth_sessions_mysql_time(auth_sessions_now()->modify('-40 days'));
$expired = auth_sessions_mysql_time(auth_sessions_now()->modify('-40 days'));
$insert = $pdo->prepare(
    'INSERT INTO user_sessions
        (session_key_hash, user_id, created_at_utc, last_seen_at_utc, expires_at_utc)
     VALUES (?, 1, ?, ?, ?)'
);
$insert->execute([$expiredHash, $old, $old, $expired]);
$check(auth_sessions_validate($pdo, 1, $expiredHash, 3600)['reason'] === 'inactive',
    'expired registry rows cannot authenticate');

$transactionHash = hash('sha256', 'contract-session-transaction');
$pdo->beginTransaction();
auth_sessions_establish($pdo, 1, $transactionHash, 3600);
$stillOwned = $pdo->inTransaction();
$pdo->rollBack();
$lookup = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE session_key_hash = ?');
$lookup->execute([$transactionHash]);
$check($stillOwned && (int)$lookup->fetchColumn() === 0,
    'registry helpers preserve caller transaction ownership and rollback');

$keepHash = hash('sha256', 'contract-session-keep');
$removeHash = hash('sha256', 'contract-session-remove');
auth_sessions_establish($pdo, 1, $keepHash, 3600);
auth_sessions_establish($pdo, 1, $removeHash, 3600);
$revoked = auth_sessions_revoke_user($pdo, 1, 1, 'password_changed', $keepHash, false);
$rows = auth_sessions_for_user($pdo, 1, $keepHash);
$byHash = [];
foreach ($rows as $row) $byHash[$row['session_key_hash']] = $row;
$check($revoked >= 2 && $byHash[$keepHash]['active'] === true && $byHash[$removeHash]['active'] === false,
    'account-wide revocation can preserve only the current session');

for ($i = 0; $i < 35; $i++) {
    $historyHash = hash('sha256', 'contract-ended-history-' . $i);
    auth_sessions_establish($pdo, 1, $historyHash, 3600);
    auth_sessions_revoke($pdo, $historyHash, 1, 'manual', 1, false);
}
$boundedHistory = auth_sessions_for_user($pdo, 1, $keepHash, 30);
$allActive = auth_sessions_active_for_user($pdo, 1, $keepHash, 100);
$activeHashes = array_column($allActive, 'session_key_hash');
$check(count($boundedHistory) === 30 && in_array($keepHash, $activeHashes, true)
    && auth_sessions_active_count($pdo, 1) === 1,
    'active-session management is independent from bounded recent history');

$pruned = auth_sessions_prune($pdo, 100);
$lookup->execute([$expiredHash]);
$check($pruned >= 1 && (int)$lookup->fetchColumn() === 0, 'retention pruning removes stale session metadata');

$migration = (string)file_get_contents($root . '/schema/migrations/037-user-sessions.sql');
$defaultSchema = (string)file_get_contents($root . '/schema/default.sql');
$config = (string)file_get_contents($root . '/cfg/config.php');
$session = (string)file_get_contents($root . '/cfg/session.php');
$profile = (string)file_get_contents($root . '/dashboard/admin/profile/index.php');
$userSave = (string)file_get_contents($root . '/dashboard/admin/users/save.php');
$bulk = (string)file_get_contents($root . '/dashboard/admin/users/bulk_action.php');
$authorization = (string)file_get_contents($root . '/cfg/helpers/authorization.php');
$sessionPage = (string)file_get_contents($root . '/dashboard/admin/sessions/index.php');
$roleManager = (string)file_get_contents($root . '/dashboard/admin/users/roles/index.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');

$check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `user_sessions`')
    && str_contains($defaultSchema, 'CREATE TABLE IF NOT EXISTS `user_sessions`')
    && str_contains($migration, "'core.sessions.manage'")
    && str_contains($defaultSchema, "'core.sessions.manage'"),
    'upgrade and fresh-install schemas both define the registry and scoped permission');
$check(str_contains($config, "require_once __DIR__ . '/helpers/auth_sessions.php';"),
    'the registry helper is loaded through the application configuration');
$validationPosition = strpos($session, 'auth_sessions_validate(');
$rotationPosition = strpos($session, '// --- Periodic housekeeping');
$refreshPosition = strpos($session, '// --- Refresh cookie');
$check($validationPosition !== false && $rotationPosition !== false && $refreshPosition !== false
    && $validationPosition < $rotationPosition && $validationPosition < $refreshPosition,
    'persistent validation runs before transport rotation and cookie refresh');
$check(substr_count($session, 'session_destroy_local();') >= 4,
    'invalid and unavailable registry states clear raw local authentication identity');
$check(str_contains($session, 'auth_sessions_establish(')
    && str_contains($session, "throw new RuntimeException('Authenticated session registry is unavailable.')")
    && str_contains($session, 'session_destroy_persisted($authenticatedSessionId)')
    && str_contains($session, 'if (!$registryPdo->commit())')
    && !str_contains($session, 'session_id(), $user_id'),
    'login establishment and partial failures fail closed without persisting the PHP transport ID');
$check(str_contains($profile, "'password_changed'")
    && str_contains($userSave, "'password_reset'")
    && str_contains($authorization, "'account_deleted'")
    && str_contains($authorization, "'account_locked'")
    && str_contains($bulk, "'account_deleted'")
    && str_contains($bulk, "'account_locked'"),
    'password, lock, delete, and bulk security mutations revoke issued sessions');
$check(str_contains($sessionPage, "user_can(\$pdo, \$uid, 'core.sessions.manage'")
    && str_contains($sessionPage, 'adiwira_csrf_validate')
    && str_contains($sessionPage, 'authorization_lock_actor_permissions')
    && str_contains($sessionPage, 'authorization_lock_site_owner_actor')
    && str_contains($sessionPage, "\$sessionActor['is_site_owner'] !== true")
    && str_contains($profile, "user_can(\$pdo, \$uid, 'core.sessions.manage'")
    && str_contains($profile, 'authorization_lock_actor_permissions')
    && str_contains($roleManager, "'core.sessions.manage'"),
    'session management reauthorizes scoped and Site Owner access under lock and validates CSRF');
$check(str_contains($translations, "'Recent Login', 'Login Terbaru'")
    && str_contains($translations, "'Active Sessions', 'Aktive Sitzungen'"),
    'new session-management interface strings include Indonesian and German seeds');

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
