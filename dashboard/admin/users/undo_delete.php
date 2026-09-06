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
$undo = adiwira_undo_get($undoToken, 'user.delete', $uid);
if ($undo === null) {
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}

$id = (int)($undo['target_id'] ?? 0);
$expectedAuditId = (int)($undo['state']['audit_id'] ?? 0);
if ($id <= 0 || $expectedAuditId <= 0) {
    adiwira_undo_consume($undoToken);
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}

try {
    $pdo->beginTransaction();
    $actorIsSiteOwner = authorization_lock_site_owner_actor($pdo, $uid);
    $stmt = $pdo->prepare(
        'SELECT id, is_site_owner, is_deleted
         FROM users
         WHERE id = :id
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $auditStmt = $pdo->prepare(
        'SELECT id
         FROM authorization_audit_log
         WHERE subject_user_id = :id
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $auditStmt->execute([':id' => $id]);
    $latestAuditId = (int)$auditStmt->fetchColumn();

    if (!$user || (int)$user['is_deleted'] !== 1 || $latestAuditId !== $expectedAuditId) {
        $pdo->rollBack();
        adiwira_undo_consume($undoToken);
        adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
    }
    if (!user_can($pdo, $uid, 'core.users.restore', ['owner_id' => $id])) {
        $pdo->rollBack();
        adiwira_json(['ok' => false, 'error' => __('Access denied.')], 403);
    }
    if ((int)$user['is_site_owner'] === 1 && !$actorIsSiteOwner) {
        $pdo->rollBack();
        adiwira_json(['ok' => false, 'error' => __('Only a Site Owner can modify a Site Owner account.')], 403);
    }

    $restore = $pdo->prepare(
        'UPDATE users
         SET is_deleted = 0, updated_at = NOW()
         WHERE id = :id AND is_deleted = 1
         LIMIT 1'
    );
    $restore->execute([':id' => $id]);
    if ($restore->rowCount() !== 1) {
        throw new RuntimeException('Undo state changed before restore.');
    }
    if (!authorization_audit($pdo, 'user.delete_undone', $uid, $id, 'user', (string)$id)) {
        throw new RuntimeException('User undo audit failed.');
    }
    $pdo->commit();

    try {
        adiwira_undo_consume($undoToken);
        adiwira_flash_push('success', __('User berhasil direstore.'));
    } catch (Throwable $notifyError) {
        error_log('[users/undo_delete] restore committed but notification failed: ' . $notifyError->getMessage());
    }
    adiwira_json(['ok' => true, 'reload' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[users/undo_delete] ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => __('Failed to restore user.')], 500);
}
