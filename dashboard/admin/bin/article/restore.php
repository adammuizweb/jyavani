<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/article/index';
$returnTo = adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
[$uid] = adiwira_require_login($pdo, true);
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) adiwira_redirect_with_flash($returnTo, 'error', __('Invalid ID.'));

try {
    shortcode_collection_layout_content_mutation($pdo, static function () use ($pdo, $uid, $id): void {
        $pdo->beginTransaction();
        try {
            if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Article actor permission lock failed.');
            $lock = $pdo->prepare("SELECT created_by, status, slug FROM posts WHERE id = :id AND type = 'article' AND is_deleted = 1 FOR UPDATE");
            $lock->execute([':id' => $id]);
            $post = $lock->fetch(PDO::FETCH_ASSOC);
            $ownerId = (int)($post['created_by'] ?? 0);
            if (!$post || !authorization_lock_owner_contexts($pdo, [$ownerId])) throw new DomainException('Article owner context lock failed.');
            if (!user_can($pdo, $uid, 'core.posts.restore', ['owner_id' => $ownerId])) throw new DomainException('Article restore permission changed.');
            if ((string)$post['status'] !== 'draft' && !user_can($pdo, $uid, 'core.posts.publish', ['owner_id' => $ownerId])) {
                throw new DomainException('Article publish permission changed.');
            }
            $collision = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND type IN ('article', 'page', 'theme') AND is_deleted = 0 AND id != :id LIMIT 1 FOR UPDATE");
            $collision->execute([':slug' => (string)$post['slug'], ':id' => $id]);
            if ($collision->fetchColumn()) throw new InvalidArgumentException('Slug already used by active content.');
            $stmt = $pdo->prepare("UPDATE posts SET is_deleted = 0, deleted_at = NULL, updated_at = NOW() WHERE id = :id AND type = 'article' AND is_deleted = 1 LIMIT 1");
            $stmt->execute([':id' => $id]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    });
    adiwira_redirect_with_flash($returnTo, 'success', __('Article restored successfully.'));
} catch (InvalidArgumentException $e) {
    adiwira_redirect_with_flash($returnTo, 'error', __($e->getMessage()));
} catch (DomainException $e) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
} catch (Throwable $e) {
    error_log('bin/article/restore.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to restore article.'));
}
