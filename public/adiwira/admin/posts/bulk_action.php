<?php
declare(strict_types=1);

// /adiwira/admin/posts/bulk_action.php
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

$defaultReturnTo = '/adiwira/index.php?page=admin/posts/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond')) {
    function respond(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: '/adiwira/index.php?page=admin/posts/index';

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
    respond(false, 'Method Not Allowed', 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond(false, 'CSRF token tidak valid.', 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond(false, 'Tidak ada artikel dipilih.', 400, [], $returnTo);
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) {
    respond(false, 'ID artikel tidak valid.', 400, [], $returnTo);
}

if ($role !== 'admin') {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmtOwn = $pdo->prepare("\n        SELECT id\n        FROM posts\n        WHERE id IN ($in)\n          AND type = 'article'\n          AND is_deleted = 0\n          AND created_by = ?\n    ");
    $stmtOwn->execute(array_merge($ids, [$uid]));
    $ownIds = $stmtOwn->fetchAll(PDO::FETCH_COLUMN, 0);
    $ids = array_values(array_filter(array_map('intval', $ownIds), fn($v) => $v > 0));

    if (empty($ids)) {
        respond(false, 'Tidak ada artikel yang boleh kamu ubah.', 403, [], $returnTo);
    }
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond(false, 'Aksi bulk tidak dikenal.', 400, [], $returnTo);
}

try {
    $pdo->beginTransaction();

    if ($action === 'delete') {
        $in = implode(',', array_fill(0, count($ids), '?'));

        $sql = "UPDATE posts
                SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW()
                WHERE id IN ($in) AND type = 'article' AND is_deleted = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);

        $pdo->commit();
        respond(true, "Berhasil menghapus {$affected} artikel.", 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'change_status') {
        $new_status = (string)($_POST['status'] ?? '');
        $allowed = ['draft', 'published', 'private'];

        if (!in_array($new_status, $allowed, true)) {
            $pdo->rollBack();
            respond(false, 'Status tidak valid.', 400, [], $returnTo);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE posts
                SET status = ?, updated_at = NOW()
                WHERE id IN ($in) AND type = 'article' AND is_deleted = 0";
        $params = array_merge([$new_status], $ids);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond(true, "Berhasil mengubah status {$affected} artikel menjadi {$new_status}.", 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'change_categories') {
        $cat_ids = $_POST['categories'] ?? [];
        $cat_ids = array_values(array_filter(array_map('intval', (array)$cat_ids), fn($v) => $v > 0));

        if (empty($cat_ids)) {
            $pdo->rollBack();
            respond(false, 'Pilih minimal satu kategori.', 400, [], $returnTo);
        }

        $inCats = implode(',', array_fill(0, count($cat_ids), '?'));
        $vstmt = $pdo->prepare("SELECT id FROM categories WHERE id IN ($inCats) AND is_deleted = 0");
        $vstmt->execute($cat_ids);
        $found = $vstmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $cat_ids = array_values(array_intersect($cat_ids, array_map('intval', $found)));

        if (empty($cat_ids)) {
            $pdo->rollBack();
            respond(false, 'Kategori tidak valid.', 400, [], $returnTo);
        }

        $mode = (string)($_POST['cat_mode'] ?? 'add');
        if (!in_array($mode, ['add', 'remove', 'toggle'], true)) {
            $mode = 'add';
        }

        $post_ids = $ids;
        $placePost = implode(',', array_fill(0, count($post_ids), '?'));
        $placeCat  = implode(',', array_fill(0, count($cat_ids), '?'));

        $selectExistingSql = "SELECT post_id, category_id
                              FROM post_categories
                              WHERE post_id IN ($placePost) AND category_id IN ($placeCat)";
        $selectStmt = $pdo->prepare($selectExistingSql);
        $selectStmt->execute(array_merge($post_ids, $cat_ids));
        $existingRows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

        $existingSet = [];
        foreach ($existingRows as $r) {
            $existingSet[(int)$r['post_id']][] = (int)$r['category_id'];
        }

        $assigned_by = $uid;

        if ($mode === 'add') {
            $values = [];
            $holders = [];

            foreach ($post_ids as $pid) {
                foreach ($cat_ids as $cid) {
                    if (isset($existingSet[$pid]) && in_array($cid, $existingSet[$pid], true)) {
                        continue;
                    }
                    $values[] = $pid;
                    $values[] = $cid;
                    $values[] = $assigned_by;
                    $holders[] = '(?, ?, ?)';
                }
            }

            if (!empty($values)) {
                $sql = "INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES " . implode(',', $holders);
                $pdo->prepare($sql)->execute($values);
            }

            $pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id IN ($placePost) AND type = 'article' AND is_deleted = 0")
                ->execute($post_ids);

            $pdo->commit();
            respond(true, 'Kategori berhasil ditambahkan ke ' . count($post_ids) . ' artikel.', 200, ['count' => count($post_ids)], $returnTo);
        }

        if ($mode === 'remove') {
            $sql = "DELETE FROM post_categories WHERE post_id IN ($placePost) AND category_id IN ($placeCat)";
            $pdo->prepare($sql)->execute(array_merge($post_ids, $cat_ids));

            $pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id IN ($placePost) AND type = 'article' AND is_deleted = 0")
                ->execute($post_ids);

            $pdo->commit();
            respond(true, 'Kategori yang dipilih berhasil dihapus dari ' . count($post_ids) . ' artikel.', 200, ['count' => count($post_ids)], $returnTo);
        }

        if ($mode === 'toggle') {
            $toInsert = [];
            $holders = [];

            foreach ($post_ids as $pid) {
                foreach ($cat_ids as $cid) {
                    if (!isset($existingSet[$pid]) || !in_array($cid, $existingSet[$pid], true)) {
                        $toInsert[] = $pid;
                        $toInsert[] = $cid;
                        $toInsert[] = $assigned_by;
                        $holders[] = '(?, ?, ?)';
                    }
                }
            }

            $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($placePost) AND category_id IN ($placeCat)")
                ->execute(array_merge($post_ids, $cat_ids));

            if (!empty($toInsert)) {
                $pdo->prepare("INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES " . implode(',', $holders))
                    ->execute($toInsert);
            }

            $pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id IN ($placePost) AND type = 'article' AND is_deleted = 0")
                ->execute($post_ids);

            $pdo->commit();
            respond(true, 'Operasi toggle kategori selesai untuk ' . count($post_ids) . ' artikel.', 200, ['count' => count($post_ids)], $returnTo);
        }

        $pdo->rollBack();
        respond(false, 'Mode kategori tidak dikenal.', 400, [], $returnTo);
    }

    $pdo->rollBack();
    respond(false, 'Aksi bulk tidak dikenal.', 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('posts/bulk_action error: ' . $e->getMessage());
    respond(false, 'Terjadi kesalahan saat proses bulk action.', 500, [], $returnTo);
}