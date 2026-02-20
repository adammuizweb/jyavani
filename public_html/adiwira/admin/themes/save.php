<?php
// /adiwira/admin/themes/save.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'errors' => ['Kamu telah kehilangan sesi, silahkan login kembali!']
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'errors' => ['Method not allowed']]);
    exit;
}

// CSRF (jika ada)
$token = $_POST['csrf_token'] ?? '';
if (function_exists('csrf_check')) {
    if (!csrf_check($token)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'errors'=>['CSRF token tidak valid.']]);
        exit;
    }
}

$id = (int)($_POST['id'] ?? 0);
$save_nonce = $_POST['save_nonce'] ?? '';
$errors = [];

if ($id <= 0) $errors[] = 'ID tidak valid.';

// periksa nonce one-time (hindari double submit)
$session_key = 'theme_save_nonce_' . $id;
$session_nonce = $_SESSION[$session_key] ?? null;
if (!$session_nonce || !$save_nonce || !hash_equals((string)$session_nonce, (string)$save_nonce)) {
    $errors[] = 'Token penyimpanan tidak valid atau sudah digunakan (duplikat). Coba muat ulang halaman.';
}

// Ambil input lain
$title = trim((string)($_POST['title'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));
$partial_name = trim((string)($_POST['partial_name'] ?? ''));
$render_mode = ($_POST['render_mode'] ?? 'html') === 'file' ? 'file' : 'html';
$content_input = $_POST['content'] ?? '';
$file_identifier = trim((string)($_POST['file_identifier'] ?? ''));
$status = in_array($_POST['status'] ?? '', ['draft','published','private'], true) ? $_POST['status'] : 'draft';

if ($title === '') $errors[] = 'Judul tidak boleh kosong.';
if ($slug === '') {
    if (!function_exists('slugify')) {
        function slugify(string $text): string {
            $text = mb_strtolower($text, 'UTF-8');
            $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
            $text = preg_replace('/[-]{2,}/', '-', $text);
            $text = trim($text, '-');
            return $text ?: bin2hex(random_bytes(4));
        }
    }
    $slug = slugify($title);
} else {
    if (!function_exists('slugify')) {
        function slugify(string $text): string {
            $text = mb_strtolower($text, 'UTF-8');
            $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
            $text = preg_replace('/[-]{2,}/', '-', $text);
            $text = trim($text, '-');
            return $text ?: bin2hex(random_bytes(4));
        }
    }
    $slug = slugify($slug);
}

// early validation DB existence
if (empty($errors)) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id AND type = 'theme' AND is_deleted = 0 LIMIT 1");
    $stmt->execute([':id' => $id]);
    $theme = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$theme) $errors[] = 'Theme tidak ditemukan.';
}

// unique slug
if (empty($errors)) {
    $s = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND id != :id LIMIT 1");
    $s->execute([':slug' => $slug, ':id' => $id]);
    if ($s->fetch()) $errors[] = 'Slug sudah dipakai.';
}

// validate content / file identifier and build $content + $metaArr
$metaArr = null;
if (empty($errors)) {
    if ($render_mode === 'file') {
        $fi_raw = trim($file_identifier);
        $fi_raw = preg_replace('#^/+|/+$#', '', $fi_raw);
        if (stripos($fi_raw, 'partials/') !== false) $fi_raw = preg_replace('#.*partials/#i', '', $fi_raw);
        $fi_raw = preg_replace('#^theme/[^/]+/partials/#i', '', $fi_raw);
        $fi_raw = preg_replace('#\.php$#i', '', $fi_raw);
        $file_identifier_norm = trim($fi_raw, " \t\n\r\0\x0B/");
        if ($file_identifier_norm === '' || !preg_match('#^[a-z0-9_\-\/]+$#i', $file_identifier_norm)) {
            $errors[] = 'File identifier tidak valid.';
        } else {
            $content = $file_identifier_norm;
            $metaArr = ['partial' => $partial_name ?: null, 'file' => $file_identifier_norm . '.php'];
        }
    } else {
        if ($content_input === '') {
            $errors[] = 'Konten HTML tidak boleh kosong.';
        } else {
            $content = $content_input;
            $metaArr = ['partial' => $partial_name ?: null, 'render' => 'html'];
        }
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => array_values($errors)]);
    exit;
}

// invalidate the used nonce immediately to block reuse (even before DB update)
unset($_SESSION[$session_key]);

// DETECT apakah tabel posts punya kolom meta (jika tidak ada, jangan sertakan kolom meta di UPDATE)
try {
    $has_meta = false;
    $colCheck = $pdo->prepare("SHOW COLUMNS FROM posts LIKE 'meta'");
    $colCheck->execute();
    if ($colCheck->fetch()) $has_meta = true;

    // build SQL dinamis tergantung ada/tidaknya kolom meta
    if ($has_meta) {
        $sql = "UPDATE posts SET title = :title, slug = :slug, content = :content, meta = :meta, status = :status, updated_at = NOW() WHERE id = :id AND type = 'theme' LIMIT 1";
    } else {
        $sql = "UPDATE posts SET title = :title, slug = :slug, content = :content, status = :status, updated_at = NOW() WHERE id = :id AND type = 'theme' LIMIT 1";
    }

    $upd = $pdo->prepare($sql);
    $params = [
        ':title' => $title,
        ':slug' => $slug,
        ':content' => $content,
        ':status' => $status,
        ':id' => $id
    ];
    if ($has_meta) $params[':meta'] = json_encode($metaArr);

    $ok = $upd->execute($params);
    if (!$ok) throw new RuntimeException('DB error saat menyimpan.');

    // ambil fresh record
    $stmt2 = $pdo->prepare("SELECT id,slug,title,status,updated_at FROM posts WHERE id = :id LIMIT 1");
    $stmt2->execute([':id' => $id]);
    $new = $stmt2->fetch(PDO::FETCH_ASSOC) ?: $theme;

    // buat nonce baru untuk form berikutnya (agar user tetap bisa save lagi tanpa reload penuh)
    $new_nonce = bin2hex(random_bytes(12));
    $_SESSION[$session_key] = $new_nonce;

    echo json_encode([
        'ok' => true,
        'message' => 'Selamat 🤲 Data sukses diperbarui! 🥳',
        'theme' => [
            'id' => (int)$new['id'],
            'slug' => $new['slug'],
            'title' => $new['title'],
            'status' => $new['status'],
        ],
        'updated_at' => $new['updated_at'] ?? date('Y-m-d H:i:s'),
        'new_save_nonce' => $new_nonce
    ]);
    exit;

} catch (Throwable $e) {
    // jika exception, pastikan nonce baru tidak dibuat — tetap hapus token lama di atas
    http_response_code(500);
    echo json_encode(['ok' => false, 'errors' => ['Server error: ' . $e->getMessage()]]);
    exit;
}
