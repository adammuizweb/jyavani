<?php
declare(strict_types=1);

// /adiwira/admin/themes/save.php
ob_start();
require_once __DIR__ . '/../_guard.php';

adiwira_cosmetic_404_on_direct_open();

[$user_id, $user_role] = adiwira_require_editorial($pdo, true);
$isAdmin = ($user_role === 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'errors' => ['Not found']], 404);
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!adiwira_csrf_validate(is_string($csrf) ? $csrf : '')) {
    adiwira_json(['ok' => false, 'errors' => ['CSRF invalid']], 419);
}

$id         = (int)($_POST['id'] ?? 0);
$save_nonce = (string)($_POST['save_nonce'] ?? '');
$errors     = [];

if ($id <= 0) {
    $errors[] = 'ID tidak valid.';
}

$session_key   = 'theme_save_nonce_' . $id;
$session_nonce = $_SESSION[$session_key] ?? null;

if (!$session_nonce || $save_nonce === '' || !hash_equals((string)$session_nonce, (string)$save_nonce)) {
    $errors[] = 'Token penyimpanan tidak valid atau sudah digunakan (duplikat). Coba muat ulang halaman.';
}

$title         = trim((string)($_POST['title'] ?? ''));
$slug_in       = trim((string)($_POST['slug'] ?? ''));
$partial_name  = trim((string)($_POST['partial_name'] ?? ''));
$render_mode   = ((string)($_POST['render_mode'] ?? 'html') === 'file') ? 'file' : 'html';
$content_input = (string)($_POST['content'] ?? '');
$file_identifier = trim((string)($_POST['file_identifier'] ?? ''));
$statusIn      = (string)($_POST['status'] ?? 'draft');
$status        = in_array($statusIn, ['draft','published','private'], true) ? $statusIn : 'draft';

if ($title === '') {
    $errors[] = 'Judul tidak boleh kosong.';
}

if (!function_exists('adiwira_slugify_theme')) {
    function adiwira_slugify_theme(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim((string)$text, '-');
        return $text !== '' ? $text : bin2hex(random_bytes(4));
    }
}

$slug = adiwira_slugify_theme($slug_in !== '' ? $slug_in : $title);

$theme = null;
if (empty($errors)) {
    $sql = "SELECT * FROM posts WHERE id = :id AND type = 'theme' AND is_deleted = 0";
    $params = [':id' => $id];

    if (!$isAdmin) {
        $sql .= " AND created_by = :uid";
        $params[':uid'] = $user_id;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $theme = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$theme) {
        $errors[] = 'Theme tidak ditemukan.';
    }
}

if (empty($errors)) {
    $s = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND id != :id LIMIT 1");
    $s->execute([':slug' => $slug, ':id' => $id]);
    if ($s->fetch()) {
        $errors[] = 'Slug sudah dipakai.';
    }
}

$metaArr = null;
$content = '';

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
            $metaArr = [
                'partial' => ($partial_name !== '' ? $partial_name : null),
                'file'    => $file_identifier_norm . '.php'
            ];
        }
    } else {
        if ($content_input === '') {
            $errors[] = 'Konten HTML tidak boleh kosong.';
        } else {
            $content = $content_input;
            $metaArr = [
                'partial' => ($partial_name !== '' ? $partial_name : null),
                'render'  => 'html'
            ];
        }
    }
}

if (!empty($errors)) {
    adiwira_json(['ok' => false, 'errors' => array_values($errors)], 400);
}

unset($_SESSION[$session_key]);

try {
    $has_meta = false;
    $colCheck = $pdo->prepare("SHOW COLUMNS FROM posts LIKE 'meta'");
    $colCheck->execute();
    if ($colCheck->fetch()) {
        $has_meta = true;
    }

    $sql = $has_meta
        ? "UPDATE posts
              SET title = :title,
                  slug = :slug,
                  content = :content,
                  meta = :meta,
                  status = :status,
                  updated_at = NOW()
            WHERE id = :id AND type = 'theme'"
        : "UPDATE posts
              SET title = :title,
                  slug = :slug,
                  content = :content,
                  status = :status,
                  updated_at = NOW()
            WHERE id = :id AND type = 'theme'";

    $params = [
        ':title'   => $title,
        ':slug'    => $slug,
        ':content' => $content,
        ':status'  => $status,
        ':id'      => $id,
    ];

    if (!$isAdmin) {
        $sql .= " AND created_by = :uid";
        $params[':uid'] = $user_id;
    }

    $sql .= " LIMIT 1";

    if ($has_meta) {
        $params[':meta'] = json_encode($metaArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $upd = $pdo->prepare($sql);
    $ok = $upd->execute($params);

    if (!$ok || $upd->rowCount() < 1) {
        throw new RuntimeException('DB error saat menyimpan.');
    }

    $stmt2 = $pdo->prepare("SELECT id, slug, title, status, updated_at FROM posts WHERE id = :id LIMIT 1");
    $stmt2->execute([':id' => $id]);
    $new = $stmt2->fetch(PDO::FETCH_ASSOC) ?: ($theme ?: []);

    $new_nonce = bin2hex(random_bytes(12));
    $_SESSION[$session_key] = $new_nonce;

    adiwira_json([
        'ok' => true,
        'message' => 'Selamat 🤲 Data sukses diperbarui! 🥳',
        'theme' => [
            'id'     => (int)($new['id'] ?? $id),
            'slug'   => (string)($new['slug'] ?? $slug),
            'title'  => (string)($new['title'] ?? $title),
            'status' => (string)($new['status'] ?? $status),
        ],
        'updated_at' => (string)($new['updated_at'] ?? date('Y-m-d H:i:s')),
        'new_save_nonce' => $new_nonce,
    ], 200);

} catch (Throwable $e) {
    if (defined('CORE_DEBUG') && CORE_DEBUG) {
        adiwira_json(['ok' => false, 'errors' => ['Server error'], 'detail' => $e->getMessage()], 500);
    }
    error_log('themes/save.php error: ' . $e->getMessage());
    adiwira_json(['ok' => false, 'errors' => ['Server error']], 500);
}