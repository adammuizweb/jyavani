<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/page/index';
$returnTo = adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
[$uid] = adiwira_require_login($pdo, true);
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) adiwira_redirect_with_flash($returnTo, 'error', __('Invalid ID.'));

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Page actor permission lock failed.');
    $lock = $pdo->prepare("SELECT created_by FROM posts WHERE id = :id AND type = 'page' AND is_deleted = 1 FOR UPDATE");
    $lock->execute([':id' => $id]);
    $page = $lock->fetch(PDO::FETCH_ASSOC);
    $ownerId = (int)($page['created_by'] ?? 0);
    if (!$page || !authorization_lock_owner_contexts($pdo, [$ownerId])) throw new DomainException('Page owner context lock failed.');
    if (!user_can($pdo, $uid, 'core.pages.purge', ['owner_id' => $ownerId])) throw new DomainException('Page purge permission changed.');
    $pdo->prepare('DELETE FROM post_categories WHERE post_id = :id')->execute([':id' => $id]);
    $pdo->prepare("DELETE FROM posts WHERE id = :id AND type = 'page' AND is_deleted = 1 LIMIT 1")->execute([':id' => $id]);
    $pdo->commit();
    adiwira_redirect_with_flash($returnTo, 'success', __('Page permanently deleted.'));
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('bin/page/delete_permanent.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to permanently delete page.'));
}
