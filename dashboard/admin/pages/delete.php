<?php
declare(strict_types=1);

// /adiwira/admin/pages/delete.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../bin/_undo.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/pages/index';
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

$stmt = $pdo->prepare("
    SELECT id, created_by
    FROM posts
    WHERE id = :id
      AND type = 'page'
      AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Page not found.'));
}

if (!user_can($pdo, $uid, 'core.pages.trash', ['owner_id' => (int)($page['created_by'] ?? 0)])) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
    }
    $lock = $pdo->prepare("SELECT created_by FROM posts WHERE id = :id AND type = 'page' AND is_deleted = 0 FOR UPDATE");
    $lock->execute([':id' => $id]);
    $lockedPage = $lock->fetch(PDO::FETCH_ASSOC);
    if (!authorization_lock_owner_contexts($pdo, [(int)($lockedPage['created_by'] ?? 0)])) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
    }
    if (!$lockedPage || !user_can($pdo, $uid, 'core.pages.trash', ['owner_id' => (int)$lockedPage['created_by']])) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
    }

    $stmt = $pdo->prepare("
        UPDATE posts
        SET is_deleted = 1,
            deleted_at = NOW(),
            updated_at = NOW(),
            updated_by = :updated_by
        WHERE id = :id
          AND type = 'page'
          AND is_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([':id' => $id, ':updated_by' => $uid]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Page deletion did not affect exactly one row.');
    }

    $categoryMap = adiwira_bin_post_category_map($pdo, [$id]);
    $auditId = adiwira_bin_record_audit($pdo, 'page', $id, $uid, 'page.trashed');
    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('pages/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete page.'));
}

$extra = [];
try {
    $action = adiwira_bin_issue_trash_undo($pdo, 'page', $uid, [[
        'id' => $id,
        'audit_id' => $auditId,
        'category_ids' => $categoryMap[$id] ?? [],
    ]]);
    if ($action !== null) {
        $extra['action'] = $action;
    }
} catch (Throwable $e) {
    error_log('pages/delete.php undo issuance error: ' . $e->getMessage());
}

try {
    do_action('admin_page_after_delete', $id, $pdo);
} catch (Throwable $e) {
    error_log('pages/delete.php post-delete hook error: ' . $e->getMessage());
}

try {
    adiwira_redirect_with_flash($returnTo, 'success', __('Page moved to trash successfully.'), 302, $extra);
} catch (Throwable $notifyError) {
    error_log('pages/delete.php deletion committed but notification failed: ' . $notifyError->getMessage());
    header('Location: ' . $returnTo, true, 302);
    exit;
}
