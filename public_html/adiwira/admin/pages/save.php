<?php
// /adiwira/admin/pages/save.php
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Hanya izinkan POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'errors' => ['Method not allowed']]);
    exit;
}

// Pastikan sesi & login aktif
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'errors' => ['Unauthorized']]);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$now = (new DateTime('now'))->format('Y-m-d H:i:s');
$errors = [];

// Ambil field dari POST
$id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$title       = trim((string)($_POST['title'] ?? ''));
$slug        = trim((string)($_POST['slug'] ?? ''));
$content     = $_POST['content'] ?? '';
$status      = in_array($_POST['status'] ?? 'draft', ['draft','published','private'], true) ? $_POST['status'] : 'draft';
$thumbnail   = trim((string)($_POST['thumbnail'] ?? '')) ?: null;
$created_at  = trim((string)($_POST['created_at'] ?? ''));
$updated_at  = trim((string)($_POST['updated_at'] ?? ''));
$created_by  = isset($_POST['created_by']) ? (int)$_POST['created_by'] : $user_id; // bisa manual dari dropdown

// ✅ Validasi user ID (pastikan id valid di tabel users)
if ($created_by) {
    $chk = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
    $chk->execute([':id' => $created_by]);
    if (!$chk->fetch()) $errors[] = 'Penulis tidak valid.';
}

// simple slugify fallback
if ($slug === '') {
    $slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', mb_strtolower($title ?: 'untitled'));
    $slug = trim($slug, '-');
}
$slug = preg_replace('/[-]{2,}/', '-', $slug);

// validasi dasar
if ($title === '') $errors[] = 'Judul tidak boleh kosong.';
if (trim(strip_tags($content)) === '') $errors[] = 'Konten tidak boleh kosong.';

// validasi slug unik (kecuali diri sendiri)
if (empty($errors)) {
    $q = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND id != :id AND type = 'page' LIMIT 1");
    $q->execute([':slug' => $slug, ':id' => $id]);
    if ($q->fetch()) $errors[] = 'Slug sudah dipakai oleh halaman lain.';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

// fungsi bantu parsing datetime
function parse_dt(string $s) {
    $s = trim($s);
    if ($s === '') return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $s);
    if ($d !== false) return $d->format('Y-m-d H:i:s');
    try {
        $d2 = new DateTime($s);
        return $d2->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

$created_at_parsed = parse_dt($created_at);
$updated_at_parsed = parse_dt($updated_at);

try {
    if ($id > 0) {
        // ambil data lama (agar created_at tetap jika tidak diubah)
        $s = $pdo->prepare("SELECT created_at, created_by FROM posts WHERE id = :id AND type = 'page' LIMIT 1");
        $s->execute([':id' => $id]);
        $existing = $s->fetch(PDO::FETCH_ASSOC) ?: [];

        $final_created = $created_at_parsed ?? ($existing['created_at'] ?? $now);
        $final_updated = $updated_at_parsed ?? $now;
        $final_creator = $created_by ?: ($existing['created_by'] ?? $user_id);

        $upd = $pdo->prepare("
            UPDATE posts
            SET title = :title,
                slug = :slug,
                content = :content,
                thumbnail = :thumbnail,
                status = :status,
                created_by = :created_by,
                created_at = :created_at,
                updated_at = :updated_at
            WHERE id = :id AND type = 'page' LIMIT 1
        ");
        
        // fix link saat user input domain saja
        $content = normalize_links_in_html($content);
        
        $ok = $upd->execute([
            ':title' => $title,
            ':slug' => $slug,
            ':content' => $content,
            ':thumbnail' => $thumbnail,
            ':status' => $status,
            ':created_by' => $final_creator,
            ':created_at' => $final_created,
            ':updated_at' => $final_updated,
            ':id' => $id
        ]);
        if (!$ok) throw new RuntimeException('DB update failed.');
    } else {
        // insert baru
        $final_created = $created_at_parsed ?? $now;
        $final_updated = $updated_at_parsed ?? $now;
        $ins = $pdo->prepare("
            INSERT INTO posts (title, slug, content, type, meta, thumbnail, status, created_by, created_at, updated_at)
            VALUES (:title, :slug, :content, 'page', NULL, :thumbnail, :status, :created_by, :created_at, :updated_at)
        ");
        
        // fix link saat user input domain saja
        $content = normalize_links_in_html($content);
        
        $ok = $ins->execute([
            ':title' => $title,
            ':slug' => $slug,
            ':content' => $content,
            ':thumbnail' => $thumbnail,
            ':status' => $status,
            ':created_by' => $created_by,
            ':created_at' => $final_created,
            ':updated_at' => $final_updated
        ]);
        $id = (int)$pdo->lastInsertId();
        if (!$ok) throw new RuntimeException('DB insert failed.');
    }

    // respon sukses
    echo json_encode([
        'ok' => true,
        'message' => $id > 0 ? 'Halaman diperbarui.' : 'Halaman baru berhasil dibuat.',
        'page' => [
            'id' => $id,
            'slug' => $slug,
            'status' => $status,
            'created_by' => $created_by,
            'updated_at' => $now
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan halaman: ' . $e->getMessage()]);
    exit;
}
