<?php
// /adiwira/admin/pages/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/bulk_succ.php'; // helper show_success_and_redirect()

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
    header('Location: ' . $base . '/index.php?page=admin/pages/index&msg=' . urlencode('CSRF token tidak valid.'));
    exit;
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    header('Location: ' . $base . '/index.php?page=admin/pages/index&msg=' . urlencode('Tidak ada halaman dipilih.'));
    exit;
}

$ids = array_map('intval', $ids);
$ids = array_values(array_filter($ids, fn($v) => $v > 0));
if (empty($ids)) {
    header('Location: ' . $base . '/index.php?page=admin/pages/index&msg=' . urlencode('ID halaman tidak valid.'));
    exit;
}

$action = $_POST['action'] ?? '';

try {
    $pdo->beginTransaction();

    // --------------------------------------------------------
    // DELETE (soft)
    // --------------------------------------------------------
    if ($action === 'delete') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE posts SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW() WHERE id IN ($in) AND type = 'page'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);

        // remove any category links (defensive)
        $delStmt = $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)");
        $delStmt->execute($ids);

        $pdo->commit();

        show_success_and_redirect(
            'Berhasil menghapus ' . $stmt->rowCount() . ' halaman.',
            $base . '/index.php?page=admin/pages/index'
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
            header('Location: ' . $base . '/index.php?page=admin/pages/index&msg=' . urlencode('Status tidak valid.'));
            exit;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE posts SET status = ?, updated_at = NOW() WHERE id IN ($in) AND type = 'page'";
        $params = array_merge([$new_status], $ids);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $pdo->commit();

        show_success_and_redirect(
            'Berhasil mengubah status ' . $stmt->rowCount() . ' halaman menjadi ' . $new_status . '.',
            $base . '/index.php?page=admin/pages/index'
        );
    }
    
    // --------------------------------------------------------
// CHANGE AUTHOR
// --------------------------------------------------------
if ($action === 'change_author') {
    $author_id = (int)($_POST['author_id'] ?? 0);
    if ($author_id <= 0) {
        $pdo->rollBack();
        header('Location: ' . $base . '/index.php?page=admin/pages/index&msg=' . urlencode('Author tidak valid.'));
        exit;
    }

    // validasi author benar-benar ada & aktif
    $v = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_deleted = 0");
    $v->execute([$author_id]);
    if (!$v->fetchColumn()) {
        $pdo->rollBack();
        header('Location: ' . $base . '/index.php?page=admin/pages/index&msg=' . urlencode('Author tidak ditemukan.'));
        exit;
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE posts SET created_by = ?, updated_at = NOW() WHERE id IN ($in)";
    $params = array_merge([$author_id], $ids);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pdo->commit();

    show_success_and_redirect(
        'Author berhasil diubah untuk ' . $stmt->rowCount() . ' halaman.',
        $base . '/index.php?page=admin/pages/index'
    );
}


    // --------------------------------------------------------
    // UNKNOWN ACTION
    // --------------------------------------------------------
    $pdo->rollBack();
    header('Location: ' . $base . '/index.php?page=admin/pages/index&msg=' . urlencode('Aksi bulk tidak dikenal.'));
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: ' . $base . '/index.php?page=admin/pages/index&msg=' . urlencode('Terjadi kesalahan saat proses bulk action.'));
    exit;
}
