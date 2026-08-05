<?php
declare(strict_types=1);

// /adiwira/admin/bin/article/restore.php
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
      AND type = 'article'
      AND is_deleted = 1
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Article not found in trash.'));
}

if ($role === 'author' && (int)($post['created_by'] ?? 0) !== $uid) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Role kamu tidak punya akses restore artikel ini.'));
}

try {
    $stmt = $pdo->prepare("
        UPDATE posts
        SET is_deleted = 0,
            deleted_at = NULL,
            updated_at = NOW()
        WHERE id = :id
          AND type = 'article'
          AND is_deleted = 1
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    adiwira_redirect_with_flash($returnTo, 'success', __('Article restored successfully.'));

} catch (Throwable $e) {
    error_log('bin/article/restore.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to restore article.'));
}