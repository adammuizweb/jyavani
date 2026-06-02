<?php
declare(strict_types=1);

// /adiwira/admin/bin/photo/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

adiwira_cosmetic_404_on_direct_open();

// bin photo tetap admin-only
[$uid, $role] = adiwira_require_role($pdo, ['admin'], true);

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/photo/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond_photo_bin_bulk')) {
    function respond_photo_bin_bulk(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/bin/photo/index';

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
    respond_photo_bin_bulk(false, 'Method Not Allowed', 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond_photo_bin_bulk(false, 'CSRF token tidak valid.', 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond_photo_bin_bulk(false, 'Tidak ada photo post dipilih.', 400, [], $returnTo);
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) {
    respond_photo_bin_bulk(false, 'ID photo post tidak valid.', 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond_photo_bin_bulk(false, 'Aksi bulk tidak dikenal.', 400, [], $returnTo);
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
                  AND type = 'photo'
                  AND is_deleted = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_photo_bin_bulk(true, "Berhasil restore {$affected} photo post.", 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'delete_permanent') {
        $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);
        $pdo->prepare("DELETE FROM post_media_items WHERE post_id IN ($in)")->execute($ids);

        $sql = "DELETE FROM posts
                WHERE id IN ($in)
                  AND type = 'photo'
                  AND is_deleted = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_photo_bin_bulk(true, "Berhasil hapus permanen {$affected} photo post.", 200, ['count' => $affected], $returnTo);
    }

    $pdo->rollBack();
    respond_photo_bin_bulk(false, 'Aksi bulk tidak dikenal.', 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('bin/photo/bulk_action.php error: ' . $e->getMessage());
    respond_photo_bin_bulk(false, 'Terjadi kesalahan saat proses bulk action.', 500, [], $returnTo);
}