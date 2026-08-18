<?php
declare(strict_types=1);

// /adiwira/admin/bin/article/delete_permanent.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/article/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
}

[$uid] = adiwira_require_login($pdo, false);

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
      AND type = 'article'
      AND is_deleted = 1
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Article not found in trash.'));
}

if (!user_can($pdo, $uid, 'core.posts.purge', ['owner_id' => (int)($post['created_by'] ?? 0)])) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

try {
    $pdo->beginTransaction();

    if (!authorization_lock_actor_permissions($pdo, $uid)) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
    }

    $lock = $pdo->prepare("SELECT created_by FROM posts WHERE id = :id AND type = 'article' AND is_deleted = 1 FOR UPDATE");
    $lock->execute([':id' => $id]);
    $lockedPost = $lock->fetch(PDO::FETCH_ASSOC);
    if (!authorization_lock_owner_contexts($pdo, [(int)($lockedPost['created_by'] ?? 0)])) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
    }
    if (!$lockedPost || !user_can($pdo, $uid, 'core.posts.purge', ['owner_id' => (int)($lockedPost['created_by'] ?? 0)])) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
    }

    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :id")
        ->execute([':id' => $id]);

    $pdo->prepare("
        DELETE FROM posts
        WHERE id = :id
          AND type = 'article'
          AND is_deleted = 1
        LIMIT 1
    ")->execute([':id' => $id]);

    $pdo->commit();

    adiwira_redirect_with_flash($returnTo, 'success', __('Article permanently deleted.'));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('bin/article/delete_permanent.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to permanently delete article.'));
}
