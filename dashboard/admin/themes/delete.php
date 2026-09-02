<?php
declare(strict_types=1);

// /adiwira/admin/themes/delete.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/themes/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
}

[$uid] = adiwira_require_login($pdo, true);

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid ID.'));
}

$stmt = $pdo->prepare("SELECT id, created_by FROM posts WHERE id = :id AND type = 'theme' AND is_deleted = 0 LIMIT 1");
$stmt->execute([':id' => $id]);
$theme = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$theme || !user_can($pdo, $uid, 'core.theme_content.delete', ['owner_id' => (int)($theme['created_by'] ?? 0)])) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Theme partial tidak ditemukan.'));
}

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Theme actor permission lock failed.');
    $lock = $pdo->prepare("SELECT created_by FROM posts WHERE id = :id AND type = 'theme' AND is_deleted = 0 FOR UPDATE");
    $lock->execute([':id' => $id]);
    $ownerId = (int)$lock->fetchColumn();
    if ($ownerId <= 0 || !authorization_lock_owner_contexts($pdo, [$ownerId])
        || !user_can($pdo, $uid, 'core.theme_content.delete', ['owner_id' => $ownerId])) {
        throw new DomainException('Theme delete permission changed.');
    }

    $pdo->prepare("
        UPDATE posts
        SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW(), updated_by = :updated_by
        WHERE id = :id AND type = 'theme' AND is_deleted = 0
        LIMIT 1
    ")->execute([':id' => $id, ':updated_by' => $uid]);

    $pdo->commit();
    adiwira_redirect_with_flash($returnTo, 'success', __('Theme partial berhasil dipindahkan ke trash.'));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('themes/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete theme partial.'));
}
