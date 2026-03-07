<?php
// sudah di panggil oleh bootsrtap /jyavani-cfg/helpers/author_helpers.php
// user dengan role author cuma bisa edit postsnya sendiri
function require_author_owns_post(array $post, PDO $pdo): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['user_role'] ?? null;
    if (!$role && $uid > 0) {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $uid]);
        $role = $stmt->fetchColumn();
        $_SESSION['user_role'] = $role;
    }
    if ($role === 'author' && (int)$post['created_by'] !== $uid) {
        http_response_code(403);
        exit('<p>Akses ditolak: Anda hanya dapat mengedit artikel buatan sendiri.</p>');
    }
}

// hanya boleh hapus tulisannya sendiri
function require_can_delete_post(array $post, PDO $pdo): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['user_role'] ?? null;

    if (!$role && $uid > 0) {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $uid]);
        $role = $stmt->fetchColumn();
        $_SESSION['user_role'] = $role;
    }

    if ($role === 'author' && (int)$post['created_by'] !== $uid) {
        http_response_code(403);
        exit('Akses ditolak: Anda hanya dapat menghapus artikel buatan sendiri.');
    }
}
