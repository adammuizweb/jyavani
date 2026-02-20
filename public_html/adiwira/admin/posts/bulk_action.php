<?php
// /adiwira/admin/posts/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/bulk_succ.php'; // <== panggil helper sukses

$role = current_user_role($pdo) ?: 'guest';
if ($role === 'author') {
    http_response_code(403);
    exit('Akses ditolak: Anda tidak memiliki izin untuk melakukan bulk action.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$base = rtrim(str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])))), '/');

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) {
    header('Location: ' . $base . '/index.php?page=admin/posts/index&msg=' . urlencode('CSRF token tidak valid.'));
    exit;
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    header('Location: ' . $base . '/index.php?page=admin/posts/index&msg=' . urlencode('Tidak ada artikel dipilih.'));
    exit;
}

$ids = array_map('intval', $ids);
$ids = array_values(array_filter($ids, fn($v) => $v > 0));
if (empty($ids)) {
    header('Location: ' . $base . '/index.php?page=admin/posts/index&msg=' . urlencode('ID artikel tidak valid.'));
    exit;
}

$action = $_POST['action'] ?? '';

try {
    $pdo->beginTransaction();

    // --------------------------------------------------------
    // DELETE
    // --------------------------------------------------------
    if ($action === 'delete') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE posts SET is_deleted = 1, updated_at = NOW() WHERE id IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $pdo->commit();

        show_success_and_redirect(
            'Berhasil menghapus ' . $stmt->rowCount() . ' artikel.',
            $base . '/index.php?page=admin/posts/index'
        );
    }

    // --------------------------------------------------------
    // CHANGE STATUS
    // --------------------------------------------------------
    if ($action === 'change_status') {
        $new_status = $_POST['status'] ?? '';
        $allowed = ['draft', 'published', 'private'];
        if (!in_array($new_status, $allowed, true)) {
            $pdo->rollBack();
            header('Location: ' . $base . '/index.php?page=admin/posts/index&msg=' . urlencode('Status tidak valid.'));
            exit;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE posts SET status = ?, updated_at = NOW() WHERE id IN ($in)";
        $params = array_merge([$new_status], $ids);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pdo->commit();

        show_success_and_redirect(
            'Berhasil mengubah status ' . $stmt->rowCount() . ' artikel menjadi ' . $new_status . '.',
            $base . '/index.php?page=admin/posts/index'
        );
    }

    // --------------------------------------------------------
    // CHANGE CATEGORIES
    // --------------------------------------------------------
    if ($action === 'change_categories') {
        $cat_ids = $_POST['categories'] ?? [];
        $cat_ids = array_map('intval', (array)$cat_ids);
        $cat_ids = array_values(array_filter($cat_ids, fn($v) => $v > 0));
        if (empty($cat_ids)) {
            $pdo->rollBack();
            header('Location: ' . $base . '/index.php?page=admin/posts/index&msg=' . urlencode('Pilih minimal satu kategori.'));
            exit;
        }

        // validasi kategori
        $inCats = implode(',', array_fill(0, count($cat_ids), '?'));
        $vstmt = $pdo->prepare("SELECT id FROM categories WHERE id IN ($inCats) AND is_deleted = 0");
        $vstmt->execute($cat_ids);
        $found = $vstmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $cat_ids = array_values(array_intersect($cat_ids, array_map('intval', $found)));
        if (empty($cat_ids)) {
            $pdo->rollBack();
            header('Location: ' . $base . '/index.php?page=admin/posts/index&msg=' . urlencode('Kategori tidak valid.'));
            exit;
        }

        $mode = $_POST['cat_mode'] ?? 'add';
        if (!in_array($mode, ['add', 'remove', 'toggle'], true)) $mode = 'add';

        $post_ids = $ids;
        $placePost = implode(',', array_fill(0, count($post_ids), '?'));
        $placeCat = implode(',', array_fill(0, count($cat_ids), '?'));

        // ambil pasangan existing
        $selectExistingSql = "SELECT post_id, category_id FROM post_categories WHERE post_id IN ($placePost) AND category_id IN ($placeCat)";
        $selectStmt = $pdo->prepare($selectExistingSql);
        $selectStmt->execute(array_merge($post_ids, $cat_ids));
        $existingRows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
        $existingSet = [];
        foreach ($existingRows as $r) {
            $existingSet[$r['post_id']][] = (int)$r['category_id'];
        }

        $assigned_by = $_SESSION['user_id'] ?? null;

        // MODE: ADD
        if ($mode === 'add') {
            $values = [];
            $placeholders = [];
            foreach ($post_ids as $pid) {
                foreach ($cat_ids as $cid) {
                    if (isset($existingSet[$pid]) && in_array($cid, $existingSet[$pid], true)) continue;
                    $values[] = $pid;
                    $values[] = $cid;
                    $values[] = $assigned_by;
                    $placeholders[] = "(?, ?, ?)";
                }
            }

            if (!empty($values)) {
                $sql = "INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES " . implode(',', $placeholders);
                $pdo->prepare($sql)->execute($values);
            }

            $pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id IN ($placePost)")->execute($post_ids);
            $pdo->commit();

            show_success_and_redirect(
                'Kategori berhasil ditambahkan ke ' . count($post_ids) . ' artikel (jika belum ada).',
                $base . '/index.php?page=admin/posts/index'
            );
        }

        // MODE: REMOVE
        if ($mode === 'remove') {
            $sql = "DELETE FROM post_categories WHERE post_id IN ($placePost) AND category_id IN ($placeCat)";
            $pdo->prepare($sql)->execute(array_merge($post_ids, $cat_ids));

            $pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id IN ($placePost)")->execute($post_ids);
            $pdo->commit();

            show_success_and_redirect(
                'Kategori yang dipilih berhasil dihapus dari ' . count($post_ids) . ' artikel.',
                $base . '/index.php?page=admin/posts/index'
            );
        }

        // MODE: TOGGLE
        if ($mode === 'toggle') {
            $toInsert = [];
            $insertPlaceholders = [];
            foreach ($post_ids as $pid) {
                foreach ($cat_ids as $cid) {
                    if (!isset($existingSet[$pid]) || !in_array($cid, $existingSet[$pid], true)) {
                        $toInsert[] = $pid;
                        $toInsert[] = $cid;
                        $toInsert[] = $assigned_by;
                        $insertPlaceholders[] = "(?, ?, ?)";
                    }
                }
            }

            $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($placePost) AND category_id IN ($placeCat)")
                ->execute(array_merge($post_ids, $cat_ids));

            if (!empty($toInsert)) {
                $pdo->prepare(
                    "INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES " . implode(',', $insertPlaceholders)
                )->execute($toInsert);
            }

            $pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id IN ($placePost)")->execute($post_ids);
            $pdo->commit();

            show_success_and_redirect(
                'Operasi toggle kategori selesai untuk ' . count($post_ids) . ' artikel.',
                $base . '/index.php?page=admin/posts/index'
            );
        }
    }

    // --------------------------------------------------------
    // UNKNOWN ACTION
    // --------------------------------------------------------
    $pdo->rollBack();
    header('Location: ' . $base . '/index.php?page=admin/posts/index&msg=' . urlencode('Aksi bulk tidak dikenal.'));
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: ' . $base . '/index.php?page=admin/posts/index&msg=' . urlencode('Terjadi kesalahan saat proses bulk action.'));
    exit;
}
