<?php
declare(strict_types=1);

// /adiwira/admin/pages/delete.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/pages/index';
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
$role = (string)($identity['role'] ?? 'guest');

if (!in_array($role, ['author', 'editor', 'admin'], true)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

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

if (in_array($role, ['author', 'editor'], true) && (int)($page['created_by'] ?? 0) !== $uid) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied: you can only delete your own pages.'));
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE posts
        SET is_deleted = 1,
            deleted_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
          AND type = 'page'
          AND is_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :id")
        ->execute([':id' => $id]);

    $pdo->commit();

    do_action('admin_page_after_delete', $id, $pdo);

    adiwira_redirect_with_flash($returnTo, 'success', __('Page moved to trash successfully.'));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('pages/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete page.'));
}