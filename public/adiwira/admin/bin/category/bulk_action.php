<?php
declare(strict_types=1);

// /adiwira/admin/bin/category/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

adiwira_cosmetic_404_on_direct_open();

// bulk kategori hanya untuk editor + admin
[$uid, $role] = adiwira_require_role($pdo, ['editor', 'admin'], true);

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = '/adiwira/index.php?page=admin/bin/category/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond_category_bin_bulk')) {
    function respond_category_bin_bulk(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: '/adiwira/index.php?page=admin/bin/category/index';

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
    respond_category_bin_bulk(false, 'Method Not Allowed', 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond_category_bin_bulk(false, 'CSRF token tidak valid.', 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond_category_bin_bulk(false, 'Tidak ada kategori dipilih.', 400, [], $returnTo);
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) {
    respond_category_bin_bulk(false, 'ID kategori tidak valid.', 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond_category_bin_bulk(false, 'Aksi bulk tidak dikenal.', 400, [], $returnTo);
}

$in = implode(',', array_fill(0, count($ids), '?'));

try {
    $pdo->beginTransaction();

    if ($action === 'restore') {
        // restore + null-kan parent kalau parent masih deleted / parent hilang
        $sql = "
            UPDATE categories c
            LEFT JOIN categories p ON p.id = c.parent_id
            SET
              c.is_deleted = 0,
              c.deleted_at = NULL,
              c.parent_id = CASE
                WHEN c.parent_id IS NULL OR c.parent_id = 0 THEN NULL
                WHEN p.id IS NULL THEN NULL
                WHEN p.is_deleted = 1 THEN NULL
                ELSE c.parent_id
              END,
              c.updated_at = NOW()
            WHERE c.id IN ($in)
              AND c.is_deleted = 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_category_bin_bulk(true, "Berhasil restore {$affected} kategori.", 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'delete_permanent') {
        // blok kalau ada child, termasuk child yang ikut terpilih juga
        $chk = $pdo->prepare("
            SELECT parent_id, COUNT(*) AS cnt
            FROM categories
            WHERE parent_id IN ($in)
            GROUP BY parent_id
        ");
        $chk->execute($ids);
        $rows = $chk->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($rows)) {
            $bad = array_map(static fn($r) => (int)$r['parent_id'], $rows);
            $pdo->rollBack();
            respond_category_bin_bulk(
                false,
                'Gagal: beberapa kategori masih punya subkategori. IDs: ' . implode(',', array_slice($bad, 0, 20)),
                400,
                ['blocked_ids' => $bad],
                $returnTo
            );
        }

        $pdo->prepare("DELETE FROM post_categories WHERE category_id IN ($in)")->execute($ids);

        $sql = "DELETE FROM categories WHERE id IN ($in) AND is_deleted = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_category_bin_bulk(true, "Berhasil hapus permanen {$affected} kategori.", 200, ['count' => $affected], $returnTo);
    }

    $pdo->rollBack();
    respond_category_bin_bulk(false, 'Aksi bulk tidak dikenal.', 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('bin/category/bulk_action.php error: ' . $e->getMessage());
    respond_category_bin_bulk(false, 'Terjadi kesalahan saat proses bulk action.', 500, [], $returnTo);
}