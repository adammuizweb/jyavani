<?php
declare(strict_types=1);

// /adiwira/admin/pages/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], true);

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/pages/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond_pages_bulk')) {
    function respond_pages_bulk(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/pages/index';

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
    respond_pages_bulk(false, 'Method Not Allowed', 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond_pages_bulk(false, 'CSRF token tidak valid.', 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond_pages_bulk(false, 'Tidak ada halaman dipilih.', 400, [], $returnTo);
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) {
    respond_pages_bulk(false, 'ID halaman tidak valid.', 400, [], $returnTo);
}

// author/editor hanya boleh bulk page miliknya sendiri
if ($role !== 'admin') {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmtOwn = $pdo->prepare("
        SELECT id
        FROM posts
        WHERE id IN ($in)
          AND type = 'page'
          AND is_deleted = 0
          AND created_by = ?
    ");
    $stmtOwn->execute(array_merge($ids, [$uid]));
    $ownIds = $stmtOwn->fetchAll(PDO::FETCH_COLUMN, 0);
    $ids = array_values(array_filter(array_map('intval', $ownIds), fn($v) => $v > 0));

    if (empty($ids)) {
        respond_pages_bulk(false, 'Tidak ada halaman yang boleh kamu ubah.', 403, [], $returnTo);
    }
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond_pages_bulk(false, 'Aksi bulk tidak dikenal.', 400, [], $returnTo);
}

try {
    $pdo->beginTransaction();

    if ($action === 'delete') {
        $in = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare("
            UPDATE posts
            SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW()
            WHERE type = 'page' AND id IN ($in) AND is_deleted = 0
        ");
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);

        $pdo->commit();
        respond_pages_bulk(true, "Berhasil menghapus {$affected} halaman.", 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'change_status') {
        $new_status = (string)($_POST['status'] ?? '');
        $allowed = ['draft', 'published', 'private'];

        if (!in_array($new_status, $allowed, true)) {
            $pdo->rollBack();
            respond_pages_bulk(false, 'Status tidak valid.', 400, [], $returnTo);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$new_status], $ids);

        $stmt = $pdo->prepare("
            UPDATE posts
            SET status = ?, updated_at = NOW()
            WHERE type = 'page' AND id IN ($in) AND is_deleted = 0
        ");
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_pages_bulk(true, "Berhasil mengubah status {$affected} halaman menjadi {$new_status}.", 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'change_author') {
        if ($role !== 'admin') {
            $pdo->rollBack();
            respond_pages_bulk(false, 'Akses ditolak: hanya admin yang boleh mengubah author.', 403, [], $returnTo);
        }

        $author_id = (int)($_POST['author_id'] ?? 0);
        if ($author_id <= 0) {
            $pdo->rollBack();
            respond_pages_bulk(false, 'Author tidak valid.', 400, [], $returnTo);
        }

        $v = $pdo->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND is_deleted = 0
              AND is_locked = 0
            LIMIT 1
        ");
        $v->execute([$author_id]);
        if (!$v->fetchColumn()) {
            $pdo->rollBack();
            respond_pages_bulk(false, 'Author tidak ditemukan.', 400, [], $returnTo);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$author_id], $ids);

        $stmt = $pdo->prepare("
            UPDATE posts
            SET created_by = ?, updated_at = NOW()
            WHERE type = 'page' AND id IN ($in) AND is_deleted = 0
        ");
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_pages_bulk(true, "Author berhasil diubah untuk {$affected} halaman.", 200, ['count' => $affected], $returnTo);
    }

    $pdo->rollBack();
    respond_pages_bulk(false, 'Aksi bulk tidak dikenal.', 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('pages/bulk_action.php error: ' . $e->getMessage());
    respond_pages_bulk(false, 'Terjadi kesalahan saat proses bulk action.', 500, [], $returnTo);
}