<?php
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
    header('Location: ' . $base . '/index.php?page=admin/themes/index&msg=' . urlencode('CSRF token tidak valid.'));
    exit;
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    header('Location: ' . $base . '/index.php?page=admin/themes/index&msg=' . urlencode('Tidak ada theme dipilih.'));
    exit;
}

$ids = array_map('intval', $ids);
$ids = array_values(array_filter($ids, fn($v) => $v > 0));
if (empty($ids)) {
    header('Location: ' . $base . '/index.php?page=admin/themes/index&msg=' . urlencode('ID theme tidak valid.'));
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
        $sql = "UPDATE posts SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW() WHERE id IN ($in) AND type = 'theme'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);

        // jika ada tabel relasi yang perlu dihapus, lakukan di sini (misal file refs) — saat ini tidak ada

        $pdo->commit();

        show_success_and_redirect(
            'Berhasil menghapus ' . $stmt->rowCount() . ' theme partial.',
            $base . '/index.php?page=admin/themes/index'
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
            header('Location: ' . $base . '/index.php?page=admin/themes/index&msg=' . urlencode('Status tidak valid.'));
            exit;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        // params: new_status followed by ids
        $params = array_merge([$new_status], $ids);
        // build placeholders for ids only in WHERE IN
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE posts SET status = ?, updated_at = NOW() WHERE id IN ($placeholders) AND type = 'theme'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $pdo->commit();

        show_success_and_redirect(
            'Berhasil mengubah status ' . $stmt->rowCount() . ' theme menjadi ' . $new_status . '.',
            $base . '/index.php?page=admin/themes/index'
        );
    }

    // --------------------------------------------------------
    // UNKNOWN ACTION
    // --------------------------------------------------------
    $pdo->rollBack();
    header('Location: ' . $base . '/index.php?page=admin/themes/index&msg=' . urlencode('Aksi bulk tidak dikenal.'));
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: ' . $base . '/index.php?page=admin/themes/index&msg=' . urlencode('Terjadi kesalahan saat proses bulk action.'));
    exit;
}
