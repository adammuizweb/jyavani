<?php
// /adiwira/admin/posts/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../bootstrap.php';

// ensure session
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Detect if request is AJAX (XHR) or wants JSON
function is_ajax_request(): bool {
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return (strtolower($xrw) === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
}

// Unified responder: JSON for AJAX; otherwise set flash and redirect
function respond($ok, $message = '', $httpCode = 200, array $extra = [], $redirect = null) {
    $isAjax = is_ajax_request();

    if ($isAjax) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        $payload = array_merge(['ok' => (bool)$ok, 'message' => $message], $extra);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // flash + redirect
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'] = $_SESSION['flash'] ?? [];
    $_SESSION['flash'][] = ['type' => $ok ? 'success' : 'error', 'text' => $message];

    $base = $redirect ?? (rtrim(str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])))), '/')) . '/index.php?page=admin/posts/index';
    header('Location: ' . $base);
    exit;
}

// -------------------------
// AUTH + ROLE ENGINEERING
// -------------------------
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    respond(false, 'Akses ditolak: belum login.', 403);
}

$role = function_exists('current_user_role') ? (current_user_role($pdo) ?: null) : null;
$role = $role ?: ($_SESSION['user_role'] ?? 'guest');
$role = is_string($role) ? strtolower(trim($role)) : 'guest';
$_SESSION['user_role'] = $role;

if (!in_array($role, ['author','editor','admin'], true)) {
    respond(false, 'Akses ditolak.', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Method Not Allowed', 405);
}

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) {
    respond(false, 'CSRF token tidak valid.', 419);
}

// ids
$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond(false, 'Tidak ada artikel dipilih.', 400);
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
if (empty($ids)) {
    respond(false, 'ID artikel tidak valid.', 400);
}

// IMPORTANT: author+editor hanya boleh bulk post miliknya sendiri
if ($role !== 'admin') {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmtOwn = $pdo->prepare("SELECT id FROM posts WHERE id IN ($in) AND type='article' AND is_deleted=0 AND created_by=?");
    $stmtOwn->execute(array_merge($ids, [$uid]));
    $ownIds = $stmtOwn->fetchAll(PDO::FETCH_COLUMN, 0);
    $ids = array_values(array_filter(array_map('intval', $ownIds), fn($v) => $v > 0));

    if (empty($ids)) {
        respond(false, 'Tidak ada artikel yang boleh kamu ubah (bukan milikmu atau sudah dihapus).', 403);
    }
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond(false, 'Aksi bulk tidak dikenal.', 400);
}

try {
    $pdo->beginTransaction();

    // --------------------------------------------------------
    // DELETE (soft)
    // --------------------------------------------------------
    if ($action === 'delete') {
        $in = implode(',', array_fill(0, count($ids), '?'));

        $sql = "UPDATE posts
                SET is_deleted=1, deleted_at=NOW(), updated_at=NOW()
                WHERE id IN ($in) AND type='article' AND is_deleted=0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        // konsisten dengan delete.php kamu (hapus relasi kategori)
        $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);

        $pdo->commit();
        respond(true, "Berhasil menghapus {$affected} artikel.", 200, ['count' => $affected]);
    }

    // --------------------------------------------------------
    // CHANGE STATUS
    // --------------------------------------------------------
    if ($action === 'change_status') {
        $new_status = (string)($_POST['status'] ?? '');
        $allowed = ['draft', 'published', 'private'];
        if (!in_array($new_status, $allowed, true)) {
            $pdo->rollBack();
            respond(false, 'Status tidak valid.', 400);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE posts
                SET status=?, updated_at=NOW()
                WHERE id IN ($in) AND type='article' AND is_deleted=0";
        $params = array_merge([$new_status], $ids);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond(true, "Berhasil mengubah status {$affected} artikel menjadi {$new_status}.", 200, ['count' => $affected]);
    }

    // --------------------------------------------------------
    // CHANGE CATEGORIES
    // --------------------------------------------------------
    if ($action === 'change_categories') {
        $cat_ids = $_POST['categories'] ?? [];
        $cat_ids = array_values(array_filter(array_map('intval', (array)$cat_ids), fn($v) => $v > 0));

        if (empty($cat_ids)) {
            $pdo->rollBack();
            respond(false, 'Pilih minimal satu kategori.', 400);
        }

        // validate categories
        $inCats = implode(',', array_fill(0, count($cat_ids), '?'));
        $vstmt = $pdo->prepare("SELECT id FROM categories WHERE id IN ($inCats) AND is_deleted=0");
        $vstmt->execute($cat_ids);
        $found = $vstmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $cat_ids = array_values(array_intersect($cat_ids, array_map('intval', $found)));

        if (empty($cat_ids)) {
            $pdo->rollBack();
            respond(false, 'Kategori tidak valid.', 400);
        }

        $mode = (string)($_POST['cat_mode'] ?? 'add');
        if (!in_array($mode, ['add','remove','toggle'], true)) $mode = 'add';

        $post_ids = $ids;
        $placePost = implode(',', array_fill(0, count($post_ids), '?'));
        $placeCat  = implode(',', array_fill(0, count($cat_ids), '?'));

        // existing pairs
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
                    if (isset($existingSet[$pid]) && in_array($cid, $existingSet[$pid], true)) continue;
                    $values[] = $pid;
                    $values[] = $cid;
                    $values[] = $assigned_by;
                    $holders[] = "(?, ?, ?)";
                }
            }

            if (!empty($values)) {
                $sql = "INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES " . implode(',', $holders);
                $pdo->prepare($sql)->execute($values);
            }

            $pdo->prepare("UPDATE posts SET updated_at=NOW() WHERE id IN ($placePost) AND type='article' AND is_deleted=0")
                ->execute($post_ids);

            $pdo->commit();
            respond(true, 'Kategori berhasil ditambahkan ke ' . count($post_ids) . ' artikel (jika belum ada).', 200, ['count' => count($post_ids)]);
        }

        if ($mode === 'remove') {
            $sql = "DELETE FROM post_categories WHERE post_id IN ($placePost) AND category_id IN ($placeCat)";
            $pdo->prepare($sql)->execute(array_merge($post_ids, $cat_ids));

            $pdo->prepare("UPDATE posts SET updated_at=NOW() WHERE id IN ($placePost) AND type='article' AND is_deleted=0")
                ->execute($post_ids);

            $pdo->commit();
            respond(true, 'Kategori yang dipilih berhasil dihapus dari ' . count($post_ids) . ' artikel.', 200, ['count' => count($post_ids)]);
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
                        $holders[] = "(?, ?, ?)";
                    }
                }
            }

            $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($placePost) AND category_id IN ($placeCat)")
                ->execute(array_merge($post_ids, $cat_ids));

            if (!empty($toInsert)) {
                $pdo->prepare("INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES " . implode(',', $holders))
                    ->execute($toInsert);
            }

            $pdo->prepare("UPDATE posts SET updated_at=NOW() WHERE id IN ($placePost) AND type='article' AND is_deleted=0")
                ->execute($post_ids);

            $pdo->commit();
            respond(true, 'Operasi toggle kategori selesai untuk ' . count($post_ids) . ' artikel.', 200, ['count' => count($post_ids)]);
        }

        $pdo->rollBack();
        respond(false, 'Mode kategori tidak dikenal.', 400);
    }

    // --------------------------------------------------------
    // UNKNOWN ACTION
    // --------------------------------------------------------
    $pdo->rollBack();
    respond(false, 'Aksi bulk tidak dikenal.', 400);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('posts/bulk_action error: ' . $e->getMessage());
    respond(false, 'Terjadi kesalahan saat proses bulk action.', 500);
}