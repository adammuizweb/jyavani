<?php
declare(strict_types=1);

// /adiwira/admin/posts/delete.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../bin/_undo.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/posts/index';
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

$stmt = $pdo->prepare("\n    SELECT id, created_by\n    FROM posts\n    WHERE id = :id\n      AND type = 'article'\n      AND is_deleted = 0\n    LIMIT 1\n");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Article not found.'));
}

if (!user_can($pdo, $uid, 'core.posts.trash', ['owner_id' => (int)($post['created_by'] ?? 0)])) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

try {
    $pdo->beginTransaction();

    if (!authorization_lock_actor_permissions($pdo, $uid)) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
    }

    $lock = $pdo->prepare("SELECT created_by FROM posts WHERE id = :id AND type = 'article' AND is_deleted = 0 FOR UPDATE");
    $lock->execute([':id' => $id]);
    $lockedPost = $lock->fetch(PDO::FETCH_ASSOC);
    if (!authorization_lock_owner_contexts($pdo, [(int)($lockedPost['created_by'] ?? 0)])) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
    }
    if (!$lockedPost || !user_can($pdo, $uid, 'core.posts.trash', ['owner_id' => (int)($lockedPost['created_by'] ?? 0)])) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
    }

    $stmt = $pdo->prepare("\n        UPDATE posts\n        SET is_deleted = 1,\n            deleted_at = NOW(),\n            updated_at = NOW(),\n            updated_by = :updated_by\n        WHERE id = :id\n          AND type = 'article'\n          AND is_deleted = 0\n        LIMIT 1\n    ");
    $stmt->execute([':id' => $id, ':updated_by' => $uid]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Article deletion did not affect exactly one row.');
    }

    $categoryMap = adiwira_bin_post_category_map($pdo, [$id]);
    $auditId = adiwira_bin_record_audit($pdo, 'article', $id, $uid, 'article.trashed');
    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('posts/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete article.'));
}

$extra = [];
try {
    $action = adiwira_bin_issue_trash_undo($pdo, 'article', $uid, [[
        'id' => $id,
        'audit_id' => $auditId,
        'category_ids' => $categoryMap[$id] ?? [],
    ]]);
    if ($action !== null) {
        $extra['action'] = $action;
    }
} catch (Throwable $e) {
    error_log('posts/delete.php undo issuance error: ' . $e->getMessage());
}

try {
    do_action('admin_post_after_delete', $id, $pdo);
} catch (Throwable $e) {
    error_log('posts/delete.php post-delete hook error: ' . $e->getMessage());
}

try {
    adiwira_redirect_with_flash($returnTo, 'success', __('Article moved to trash successfully.'), 302, $extra);
} catch (Throwable $notifyError) {
    error_log('posts/delete.php deletion committed but notification failed: ' . $notifyError->getMessage());
    header('Location: ' . $returnTo, true, 302);
    exit;
}
