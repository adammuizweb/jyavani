<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'notify' => (string)file_get_contents($root . '/dashboard/admin/_notify.php'),
    'delete' => (string)file_get_contents($root . '/dashboard/admin/users/delete.php'),
    'bulk_delete' => (string)file_get_contents($root . '/dashboard/admin/users/bulk_action.php'),
    'undo' => (string)file_get_contents($root . '/dashboard/admin/users/undo_delete.php'),
    'bulk_undo' => (string)file_get_contents($root . '/dashboard/admin/users/undo_bulk_delete.php'),
    'index' => (string)file_get_contents($root . '/dashboard/admin/users/index.php'),
    'style' => (string)file_get_contents($root . '/public/static/dashboard/css/style.css'),
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
    && str_contains($sources['delete'], "'audit_id' => (int)\$deleteAuditId")
    && str_contains($sources['delete'], "'core.users.delete', \$deleteAuditId")
    && str_contains($sources['delete'], "'label' => __('Undo')"), 'successful user deletion emits a state-bound Undo toast');
$auditCapture = strpos($sources['authorization'], '$auditId = (int)$pdo->lastInsertId();');
$savepointRelease = strpos($sources['authorization'], "\$pdo->exec('RELEASE SAVEPOINT ' . \$savepoint);", $auditCapture ?: 0);
$check(str_contains($sources['authorization'], '?int &$auditId = null')
    && $auditCapture !== false && $savepointRelease !== false && $auditCapture < $savepointRelease,
    'status mutations return the audit ID before releasing a caller-owned savepoint');
$check(str_contains($sources['authorization'], "\$action === 'delete' && (int)\$target['is_deleted'] === 1")
    && str_contains($sources['authorization'], "return 'missing';"), 'the locked user mutation rejects duplicate deletion before writing a new audit');
$check(str_contains($sources['undo'], 'FOR UPDATE')
    && str_contains($sources['undo'], "(int)\$user['is_deleted'] !== 1")
    && str_contains($sources['undo'], 'ORDER BY id DESC')
    && str_contains($sources['undo'], '$latestAuditId !== $expectedAuditId'), 'undo locks and compares the authoritative deletion audit state');
$check(str_contains($sources['undo'], "user_can(\$pdo, \$uid, 'core.users.restore'")
    && str_contains($sources['undo'], 'authorization_lock_site_owner_actor'), 'undo rechecks restore and Site Owner authorization');
$check(str_contains($sources['undo'], "authorization_audit(\$pdo, 'user.delete_undone'")
    && strpos($sources['undo'], 'adiwira_undo_consume($undoToken);', strpos($sources['undo'], '$pdo->commit();')) !== false
    && str_contains($sources['undo'], 'restore committed but notification failed'), 'undo is audited and treats post-commit notification errors as successful restoration');
$check(str_contains($sources['bulk_delete'], "authorization_audit(\$pdo, 'user.deleted'")
    && str_contains($sources['bulk_delete'], "adiwira_undo_issue('user.bulk_delete'")
    && str_contains($sources['bulk_delete'], 'count($ids) > 100')
    && str_contains($sources['bulk_delete'], "'url' => ADMIN_BASE_PATH . '/admin/users/undo_bulk_delete.php'")
    && str_contains($sources['bulk_delete'], "adiwira_redirect_with_flash(\$redirect, \$ok ? 'success' : 'error', \$message, 302, \$extra)"),
    'bulk deletion audits every target and preserves one Undo action through its redirect');
$check(str_contains($sources['bulk_undo'], "adiwira_undo_get(\$undoToken, 'user.bulk_delete'")
    && str_contains($sources['bulk_undo'], 'count($rawItems) <= 100')
    && str_contains($sources['bulk_undo'], 'ORDER BY id')
    && str_contains($sources['bulk_undo'], '(int)$latestAudit->fetchColumn() !== $expectedAudits[$id]'),
    'bulk Undo validates a bounded, locked, exact deletion set');
$check(str_contains($sources['bulk_undo'], "authorization_audit(\$pdo, 'user.delete_undone'")
    && str_contains($sources['bulk_undo'], "['bulk' => true]")
    && strpos($sources['bulk_undo'], 'adiwira_undo_consume($undoToken);', strpos($sources['bulk_undo'], '$pdo->commit();')) !== false
    && str_contains($sources['bulk_undo'], 'restore committed but notification failed'),
    'bulk Undo restores atomically and never reports post-commit notification errors as restore failures');
$bulkCommit = strpos($sources['bulk_delete'], '$pdo->commit();', strpos($sources['bulk_delete'], "if (\$action === 'delete')"));
$bulkNotifyTry = strpos($sources['bulk_delete'], 'try {', $bulkCommit ?: 0);
$check($bulkCommit !== false && $bulkNotifyTry !== false && $bulkCommit < $bulkNotifyTry
    && str_contains($sources['bulk_delete'], 'deletion committed but notification failed')
    && str_contains($sources['bulk_delete'], "adiwira_json(['ok' => true"),
    'bulk delete falls back to a successful response if Undo notification setup fails after commit');
$check(str_contains($sources['index'], 'autocomplete="username" hidden')
    && strpos($sources['index'], 'autocomplete="username" hidden') < strpos($sources['index'], 'autocomplete="current-password"'),
    'Site Owner password confirmation supplies its associated username for password managers');
$check(str_contains($sources['index'], "<option value=\"delete\"><?=_e('Move to trash')?>")
    && !str_contains($sources['index'], "_e('Delete (soft)')")
    && str_contains($sources['style'], '.users-toolbar .toolbar-add')
    && str_contains($sources['style'], 'align-self:center;'),
    'User Management labels soft deletion clearly and vertically centers its Add User action');
foreach (['Undo', 'This action can no longer be undone.', '%d user(s) restored.', 'You can select up to 100 users at a time.'] as $key) {
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
