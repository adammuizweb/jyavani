<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed.')], 405);
}

$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    adiwira_json(['ok' => false, 'error' => __('Access denied.')], 403);
}

$uid = (int)($identity['uid'] ?? 0);
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) {
    adiwira_json(['ok' => false, 'error' => __('Invalid CSRF token.')], 419);
}

$undoToken = (string)($_POST['undo_token'] ?? '');
$undo = adiwira_undo_get($undoToken, 'user.bulk_delete', $uid);
if ($undo === null) {
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}

$rawItems = $undo['state']['items'] ?? null;
$expectedAudits = [];
if (is_array($rawItems) && count($rawItems) >= 1 && count($rawItems) <= 100) {
    foreach ($rawItems as $item) {
        $id = is_array($item) ? (int)($item['id'] ?? 0) : 0;
        $auditId = is_array($item) ? (int)($item['audit_id'] ?? 0) : 0;
        if ($id <= 0 || $auditId <= 0 || isset($expectedAudits[$id])) {
            $expectedAudits = [];
            break;
        }
        $expectedAudits[$id] = $auditId;
    }
}
if ($expectedAudits === []) {
    adiwira_undo_consume($undoToken);
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}

$ids = array_keys($expectedAudits);
sort($ids, SORT_NUMERIC);

try {
    $pdo->beginTransaction();
    authorization_lock_site_owner_actor($pdo, $uid);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, is_site_owner, is_deleted
         FROM users
         WHERE id IN ($placeholders)
         ORDER BY id
         FOR UPDATE"
    );
    $stmt->execute($ids);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) !== count($ids)) {
        $pdo->rollBack();
        adiwira_undo_consume($undoToken);
        adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
    }

    $latestAudit = $pdo->prepare(
        'SELECT id
         FROM authorization_audit_log
         WHERE subject_user_id = :id
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE'
    );
    foreach ($users as $user) {
        $id = (int)$user['id'];
        $latestAudit->execute([':id' => $id]);
        if ((int)$user['is_site_owner'] === 1
            || (int)$user['is_deleted'] !== 1
            || (int)$latestAudit->fetchColumn() !== $expectedAudits[$id]) {
            $pdo->rollBack();
            adiwira_undo_consume($undoToken);
            adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
        }
        if (!user_can($pdo, $uid, 'core.users.restore', ['owner_id' => $id])) {
            $pdo->rollBack();
            adiwira_json(['ok' => false, 'error' => __('Access denied.')], 403);
        }
    }

    $restore = $pdo->prepare(
        "UPDATE users
         SET is_deleted = 0, updated_at = NOW()
         WHERE id IN ($placeholders) AND is_deleted = 1 AND is_site_owner = 0"
    );
    $restore->execute($ids);
    if ($restore->rowCount() !== count($ids)) {
        throw new RuntimeException('Bulk Undo state changed before restore.');
    }
    foreach ($ids as $id) {
        if (!authorization_audit($pdo, 'user.delete_undone', $uid, $id, 'user', (string)$id, ['bulk' => true])) {
            throw new RuntimeException('Bulk user undo audit failed for user ID ' . $id);
        }
    }
    $pdo->commit();

    try {
        adiwira_undo_consume($undoToken);
        adiwira_flash_push('success', sprintf(__('%d user(s) restored.'), count($ids)));
    } catch (Throwable $notifyError) {
        error_log('[users/undo_bulk_delete] restore committed but notification failed: ' . $notifyError->getMessage());
    }
    adiwira_json(['ok' => true, 'reload' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[users/undo_bulk_delete] ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => __('Failed to restore user.')], 500);
}
