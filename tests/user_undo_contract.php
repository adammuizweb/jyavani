<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'notify' => (string)file_get_contents($root . '/dashboard/admin/_notify.php'),
    'delete' => (string)file_get_contents($root . '/dashboard/admin/users/delete.php'),
    'undo' => (string)file_get_contents($root . '/dashboard/admin/users/undo_delete.php'),
    'authorization' => (string)file_get_contents($root . '/cfg/helpers/authorization.php'),
    'translations' => (string)file_get_contents($root . '/schema/translations.sql'),
];
require_once $root . '/dashboard/admin/_notify.php';
$sessionStarted = session_status() === PHP_SESSION_ACTIVE || @session_start();
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($sources['notify'], 'random_bytes(32)')
    && str_contains($sources['notify'], "hash('sha256', \$token)")
    && str_contains($sources['notify'], "'expires_at'"), 'undo grants are random, hashed in session, and expiring');
$check(str_contains($sources['notify'], "'actor_id' => \$actorId")
    && str_contains($sources['notify'], "(int)(\$action['actor_id'] ?? 0) !== \$actorId"), 'undo grants are bound to the actor session');
$check(str_contains($sources['delete'], "adiwira_undo_issue('user.delete'")
    && str_contains($sources['delete'], "'audit_id' => \$deleteAuditId")
    && str_contains($sources['delete'], '(int)$pdo->lastInsertId()')
    && str_contains($sources['delete'], "'label' => __('Undo')"), 'successful user deletion emits a state-bound Undo toast');
$check(str_contains($sources['authorization'], "\$action === 'delete' && (int)\$target['is_deleted'] === 1")
    && str_contains($sources['authorization'], "return 'missing';"), 'the locked user mutation rejects duplicate deletion before writing a new audit');
$check(str_contains($sources['undo'], 'FOR UPDATE')
    && str_contains($sources['undo'], "(int)\$user['is_deleted'] !== 1")
    && str_contains($sources['undo'], 'ORDER BY id DESC')
    && str_contains($sources['undo'], '$latestAuditId !== $expectedAuditId'), 'undo locks and compares the authoritative deletion audit state');
$check(str_contains($sources['undo'], "user_can(\$pdo, \$uid, 'core.users.restore'")
    && str_contains($sources['undo'], 'authorization_lock_site_owner_actor'), 'undo rechecks restore and Site Owner authorization');
$check(str_contains($sources['undo'], "authorization_audit(\$pdo, 'user.delete_undone'")
    && strpos($sources['undo'], 'adiwira_undo_consume($undoToken);', strpos($sources['undo'], '$pdo->commit();')) !== false, 'undo is audited and consumed only after commit');
foreach (['Undo', 'This action can no longer be undone.'] as $key) {
    $quoted = preg_quote("('default', '" . str_replace("'", "''", $key) . "'", '/');
    $check(preg_match_all('/' . $quoted . '/', $sources['translations']) === 2, $key . ' has Indonesian and German translation seeds');
}

$check($sessionStarted, 'undo grant runtime test can use a session');
if ($sessionStarted) {
    $_SESSION['adiwira_undo_actions'] = [];
    $token = adiwira_undo_issue('user.delete', 42, 7, ['audit_id' => 99]);
    $grant = is_string($token) ? adiwira_undo_get($token, 'user.delete', 7) : null;
    $check(is_string($token) && strlen($token) === 64
        && (int)($grant['target_id'] ?? 0) === 42, 'issued undo grants resolve to their bound target');
    if (is_string($token)) {
        adiwira_undo_consume($token);
        $check(adiwira_undo_get($token, 'user.delete', 7) === null, 'consumed undo grants cannot be replayed');
    }
    $_SESSION['adiwira_undo_actions'] = [];
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " user undo contract check(s) failed.\n");
    exit(1);
}
echo "User undo contract passed ({$checks} checks).\n";
