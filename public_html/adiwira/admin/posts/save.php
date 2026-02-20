<?php
// /adiwira/admin/posts/save.php
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

$user_id = (int)$_SESSION['user_id'];
$now = (new DateTime('now'))->format('Y-m-d H:i:s');
$errors = [];

// Ambil field dari POST
$id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$title       = trim((string)($_POST['title'] ?? ''));
$slug        = trim((string)($_POST['slug'] ?? ''));
$content     = $_POST['content'] ?? '';
$status      = in_array($_POST['status'] ?? 'draft', ['draft','published','private'], true) ? $_POST['status'] : 'draft';
$youtube     = trim((string)($_POST['youtube'] ?? '')) ?: null;
$thumbnail   = trim((string)($_POST['thumbnail'] ?? '')) ?: null;
$created_at  = trim((string)($_POST['created_at'] ?? ''));
$updated_at  = trim((string)($_POST['updated_at'] ?? ''));
$created_by  = isset($_POST['created_by']) ? (int)$_POST['created_by'] : $user_id; // bisa manual dari dropdown
$categories  = isset($_POST['categories']) ? (array)$_POST['categories'] : [];
$youtube     = trim((string)($_POST['youtube'] ?? '')) ?: null;

// batas panjang wajar (misal 512)
if ($youtube !== null && mb_strlen($youtube) > 512) {
    $errors[] = 'Link YouTube terlalu panjang.';
}
if ($youtube !== null) {
    // hanya izinkan http/https
    if (!preg_match('/^https?:\/\//i', $youtube)) {
        $errors[] = 'Link YouTube harus diawali http atau https.';
    }
    // domain dan pola dasar
    if (!preg_match('/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\//i', $youtube)) {
        $errors[] = 'Link YouTube tidak valid. Gunakan youtube.com atau youtu.be.';
    }
}

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
    $q = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND id != :id AND type = 'article' LIMIT 1");
    $q->execute([':slug' => $slug, ':id' => $id]);
    if ($q->fetch()) $errors[] = 'Slug sudah dipakai oleh posting lain.';
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
        // ambil data lama (agar created_at/by tetap jika tidak diubah)
        $s = $pdo->prepare("SELECT created_at, created_by FROM posts WHERE id = :id AND type = 'article' LIMIT 1");
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
        youtube = :youtube,
        thumbnail = :thumbnail,
        status = :status,
        created_by = :created_by,
        created_at = :created_at,
        updated_at = :updated_at
    WHERE id = :id AND type = 'article' LIMIT 1
");

// normalisasi konten
$content = normalize_links_in_html($content);

$ok = $upd->execute([
    ':title' => $title,
    ':slug' => $slug,
    ':content' => $content,
    ':youtube' => $youtube,
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
    INSERT INTO posts (title, slug, content, type, meta, youtube, thumbnail, status, created_by, created_at, updated_at)
    VALUES (:title, :slug, :content, 'article', NULL, :youtube, :thumbnail, :status, :created_by, :created_at, :updated_at)
");

// normalisasi konten
$content = normalize_links_in_html($content);

$ok = $ins->execute([
    ':title' => $title,
    ':slug' => $slug,
    ':content' => $content,
    ':youtube' => $youtube,
    ':thumbnail' => $thumbnail,
    ':status' => $status,
    ':created_by' => $created_by,
    ':created_at' => $final_created,
    ':updated_at' => $final_updated
]);
        $id = (int)$pdo->lastInsertId();
        if (!$ok) throw new RuntimeException('DB insert failed.');
    }

    // 🔄 Sinkronisasi kategori
    $pdo->prepare("DELETE FROM post_categories WHERE post_id = :pid")->execute([':pid' => $id]);
    if (!empty($categories)) {
        $insC = $pdo->prepare("INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES (:pid, :cid, :by)");
        foreach ($categories as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) $insC->execute([':pid' => $id, ':cid' => $cid, ':by' => $user_id]);
        }
    }

    // respon sukses
echo json_encode([
    'ok' => true,
    'message' => $id > 0 ? 'Posting diperbarui.' : 'Posting baru berhasil dibuat.',
    'post' => [
        'id' => $id,
        'slug' => $slug,
        'status' => $status,
        'created_by' => $created_by,
        'updated_at' => $now,
        'youtube' => $youtube // opsional
    ]
]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan posting: ' . $e->getMessage()]);
    exit;
}
