<?php
declare(strict_types=1);

// /adiwira/admin/bin/page/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

adiwira_cosmetic_404_on_direct_open();

// bulk trash page hanya untuk editor + admin
[$uid, $role] = adiwira_require_role($pdo, ['editor', 'admin'], true);

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/page/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond_page_bin_bulk')) {
    function respond_page_bin_bulk(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/bin/page/index';

        if (is_ajax_request()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_merge([
                'ok' => $ok,
                'message' => $message,
                'redirect' => $redirect,
            ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        adiwira_redirect_with_flash($redirect, $ok ? 'success' : 'error', $message);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond_page_bin_bulk(false, 'Method Not Allowed', 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond_page_bin_bulk(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond_page_bin_bulk(false, __('No pages selected.'), 400, [], $returnTo);
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) {
    respond_page_bin_bulk(false, __('Invalid page ID.'), 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond_page_bin_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);
}

$in = implode(',', array_fill(0, count($ids), '?'));

try {
    $pdo->beginTransaction();

    if ($action === 'restore') {
        $sql = "UPDATE posts
                SET is_deleted = 0,
                    deleted_at = NULL,
                    updated_at = NOW()
                WHERE id IN ($in)
                  AND type = 'page'
                  AND is_deleted = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_page_bin_bulk(true, "Successfully restored  {$affected} page.", 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'delete_permanent') {
        $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);

        $sql = "DELETE FROM posts
                WHERE id IN ($in)
                  AND type = 'page'
                  AND is_deleted = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_page_bin_bulk(true, "Permanently deleted  {$affected} page.", 200, ['count' => $affected], $returnTo);
    }

    $pdo->rollBack();
    respond_page_bin_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('bin/page/bulk_action.php error: ' . $e->getMessage());
    respond_page_bin_bulk(false, __('An error occurred during bulk action.'), 500, [], $returnTo);
}