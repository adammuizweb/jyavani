<?php
declare(strict_types=1);

// /adiwira/admin/bin/theme/restore.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/theme/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
}

$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

$uid  = (int)($identity['uid'] ?? 0);

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid ID.'));
}

$stmt = $pdo->prepare("
    SELECT id, created_by
    FROM posts
    WHERE id = :id
      AND type = 'theme'
      AND is_deleted = 1
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Theme tidak ditemukan di trash.'));
}

if (!user_can($pdo, $uid, 'core.theme_content.restore', ['owner_id' => (int)($row['created_by'] ?? 0)])) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

try {
    shortcode_collection_layout_content_mutation($pdo, static function () use ($pdo, $uid, $id): void {
        $pdo->beginTransaction();
        try {
            if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Theme actor permission lock failed.');
            $lock = $pdo->prepare("SELECT created_by, slug FROM posts WHERE id = :id AND type = 'theme' AND is_deleted = 1 FOR UPDATE");
            $lock->execute([':id' => $id]);
            $lockedTheme = $lock->fetch(PDO::FETCH_ASSOC);
            $ownerId = (int)($lockedTheme['created_by'] ?? 0);
            if ($ownerId <= 0 || !authorization_lock_owner_contexts($pdo, [$ownerId])
                || !user_can($pdo, $uid, 'core.theme_content.restore', ['owner_id' => $ownerId])) {
                throw new DomainException('Theme restore permission changed.');
            }
            $collision = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND type IN ('article', 'page', 'theme') AND is_deleted = 0 AND id != :id LIMIT 1 FOR UPDATE");
            $collision->execute([':slug' => (string)$lockedTheme['slug'], ':id' => $id]);
            if ($collision->fetchColumn()) throw new DomainException(__('A theme partial with this slug is already active.'));
            $stmt = $pdo->prepare("UPDATE posts SET is_deleted = 0, deleted_at = NULL, updated_at = NOW(), updated_by = :updated_by WHERE id = :id AND type = 'theme' AND is_deleted = 1 LIMIT 1");
            $stmt->execute([':id' => $id, ':updated_by' => $uid]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Theme restore did not affect exactly one row.');
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    });

    adiwira_redirect_with_flash($returnTo, 'success', __('Theme berhasil direstore.'));

} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    adiwira_redirect_with_flash($returnTo, 'error', $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('bin/theme/restore.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to restore theme.'));
}
