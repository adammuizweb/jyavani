<?php
declare(strict_types=1);

// /adiwira/admin/categories/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_role($pdo, ['editor', 'admin'], true);

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/categories/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond_categories_bulk')) {
    function respond_categories_bulk(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/categories/index';

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
    respond_categories_bulk(false, 'Method Not Allowed', 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond_categories_bulk(false, 'CSRF token tidak valid.', 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond_categories_bulk(false, 'Tidak ada kategori dipilih.', 400, [], $returnTo);
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) {
    respond_categories_bulk(false, 'ID kategori tidak valid.', 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond_categories_bulk(false, 'Aksi bulk tidak dikenal.', 400, [], $returnTo);
}

$in = implode(',', array_fill(0, count($ids), '?'));

try {
    $pdo->beginTransaction();

    if ($action === 'delete') {
        $chk = $pdo->prepare("
            SELECT DISTINCT parent_id
            FROM categories
            WHERE parent_id IN ($in)
              AND is_deleted = 0
        ");
        $chk->execute($ids);
        $badParents = $chk->fetchAll(PDO::FETCH_COLUMN, 0);

        if (!empty($badParents)) {
            $pdo->rollBack();
            respond_categories_bulk(
                false,
                'Gagal: ada kategori yang masih punya subkategori aktif. IDs: ' . implode(',', array_slice(array_map('intval', $badParents), 0, 30)),
                400,
                [],
                $returnTo
            );
        }

        $stmt = $pdo->prepare("
            UPDATE categories
            SET is_deleted = 1,
                deleted_at = NOW(),
                updated_at = NOW()
            WHERE id IN ($in)
              AND is_deleted = 0
        ");
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_categories_bulk(true, "Berhasil memindahkan {$affected} kategori ke trash.", 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'change_parent') {
        $parentRaw = $_POST['parent_id'] ?? null;
        if ($parentRaw === null || $parentRaw === '') {
            $pdo->rollBack();
            respond_categories_bulk(false, 'Parent wajib dipilih (atau pilih Tanpa Parent).', 400, [], $returnTo);
        }

        $parent = (int)$parentRaw;

        if ($parent > 0 && in_array($parent, $ids, true)) {
            $pdo->rollBack();
            respond_categories_bulk(false, 'Parent tidak boleh termasuk kategori yang dipilih.', 400, [], $returnTo);
        }

        if ($parent !== 0) {
            $v = $pdo->prepare("
                SELECT id
                FROM categories
                WHERE id = ?
                  AND is_deleted = 0
                LIMIT 1
            ");
            $v->execute([$parent]);
            if (!$v->fetchColumn()) {
                $pdo->rollBack();
                respond_categories_bulk(false, 'Parent kategori tidak ditemukan.', 400, [], $returnTo);
            }

            $allStmt = $pdo->prepare("
                SELECT id, parent_id
                FROM categories
                WHERE is_deleted = 0
            ");
            $allStmt->execute();
            $allCats = $allStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $children = [];
            foreach ($allCats as $c) {
                $pid = $c['parent_id'] === null ? 0 : (int)$c['parent_id'];
                $children[$pid][] = (int)$c['id'];
            }

            $collectDesc = function(int $start) use (&$children, &$collectDesc): array {
                $out = [];
                if (empty($children[$start])) return $out;
                foreach ($children[$start] as $cid) {
                    if (isset($out[$cid])) continue;
                    $out[$cid] = true;
                    foreach ($collectDesc($cid) as $k => $v) $out[$k] = $v;
                }
                return $out;
            };

            foreach ($ids as $cid) {
                $desc = $collectDesc($cid);
                if (isset($desc[$parent])) {
                    $pdo->rollBack();
                    respond_categories_bulk(false, 'Parent tidak valid: akan membentuk loop hirarki kategori.', 400, [], $returnTo);
                }
            }
        }

        if ($parent === 0) {
            $stmt = $pdo->prepare("
                UPDATE categories
                SET parent_id = NULL,
                    updated_at = NOW()
                WHERE id IN ($in)
                  AND is_deleted = 0
            ");
            $stmt->execute($ids);
        } else {
            $stmt = $pdo->prepare("
                UPDATE categories
                SET parent_id = ?,
                    updated_at = NOW()
                WHERE id IN ($in)
                  AND is_deleted = 0
            ");
            $stmt->execute(array_merge([$parent], $ids));
        }

        $affected = $stmt->rowCount();
        $pdo->commit();
        respond_categories_bulk(true, "Parent berhasil diubah untuk {$affected} kategori.", 200, ['count' => $affected], $returnTo);
    }

    $pdo->rollBack();
    respond_categories_bulk(false, 'Aksi bulk tidak dikenal.', 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('categories/bulk_action.php error: ' . $e->getMessage());
    respond_categories_bulk(false, 'Terjadi kesalahan saat proses bulk.', 500, [], $returnTo);
}