<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);
$isAdmin = ($role === 'admin');

if (!function_exists('slugify_sc')) {
    function slugify_sc(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        return trim((string)$text, '-') ?: bin2hex(random_bytes(4));
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'errors' => ['CSRF invalid']], 419);
    exit;
}

$id         = (int)($_POST['id'] ?? 0);
$save_nonce = (string)($_POST['save_nonce'] ?? '');
$title      = trim((string)($_POST['title'] ?? ''));
$slug_in    = trim((string)($_POST['slug'] ?? ''));
$statusIn   = (string)($_POST['status'] ?? 'draft');
$status     = in_array($statusIn, ['published','draft','private'], true) ? $statusIn : 'draft';
$config_json = (string)($_POST['config_json'] ?? '{}');
$return_to  = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=presets')
    : ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=presets';

$errors = [];
$isEdit = $id > 0;

// Validate nonce
if ($isEdit) {
    $session_key = 'sc_save_nonce_' . $id;
    $session_nonce = $_SESSION[$session_key] ?? null;
    if (!$session_nonce || $save_nonce === '' || !hash_equals((string)$session_nonce, (string)$save_nonce)) {
        $errors[] = __('Token penyimpanan tidak valid atau sudah digunakan (duplikat). Muat ulang halaman.');
    }
} else {
    $session_nonce = $_SESSION['sc_add_nonce'] ?? null;
    if (!$session_nonce || $save_nonce === '' || !hash_equals((string)$session_nonce, (string)$save_nonce)) {
        $errors[] = __('Token penyimpanan tidak valid. Muat ulang halaman.');
    }
}

if ($title === '') {
    $errors[] = __('Nama preset tidak boleh kosong.');
}

$slug = slugify_sc($slug_in !== '' ? $slug_in : $title);

// Decode config
$config = json_decode($config_json, true);
if (!is_array($config)) {
    $errors[] = __('Format konfigurasi tidak valid.');
}

// Check slug uniqueness
if (empty($errors)) {
    $s = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND type = 'sc_preset' AND is_deleted = 0" . ($isEdit ? " AND id != :id" : ""));
    $params = [':slug' => $slug];
    if ($isEdit) $params[':id'] = $id;
    $s->execute($params);
    if ($s->fetch()) {
        $errors[] = __('Widget name') . ' "' . $slug . '" ' . __('is already in use. Choose another name.');
    }
}

if (!empty($errors)) {
    unset($_SESSION['sc_add_nonce']);
    $_SESSION['sc_add_nonce'] = bin2hex(random_bytes(12));
    if ($isEdit) {
        $_SESSION[$session_key] = bin2hex(random_bytes(12));
    }
    $redirect = $isEdit
        ? ADMIN_BASE_PATH . '/?' . http_build_query(['page' => 'admin/shortcodes/edit', 'id' => $id, 'return_to' => $return_to])
        : ADMIN_BASE_PATH . '/?page=admin/shortcodes/edit';
    adiwira_redirect_with_flash($redirect, 'error', implode('; ', $errors));
}

try {
    $metaJson = json_encode($config, JSON_UNESCAPED_UNICODE);

    if ($isEdit) {
        unset($_SESSION[$session_key]);

        $sql = "UPDATE posts SET title = :title, slug = :slug, meta = :meta, status = :status, updated_at = NOW() WHERE id = :id AND type = 'sc_preset' AND is_deleted = 0";
        $params = [':title' => $title, ':slug' => $slug, ':meta' => $metaJson, ':status' => $status, ':id' => $id];
        if (!$isAdmin) {
            $sql .= " AND created_by = :uid";
            $params[':uid'] = $uid;
        }
        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        adiwira_redirect_with_flash($return_to, 'success', 'Preset "' . $title . '" berhasil diperbarui.');
    } else {
        unset($_SESSION['sc_add_nonce']);

        $stmt = $pdo->prepare("INSERT INTO posts (title, slug, content, meta, type, status, created_by, created_at, updated_at) VALUES (:title, :slug, '', :meta, 'sc_preset', :status, :uid, NOW(), NOW())");
        $stmt->execute([
            ':title' => $title,
            ':slug' => $slug,
            ':meta' => $metaJson,
            ':status' => $status,
            ':uid' => $uid,
        ]);

        adiwira_redirect_with_flash($return_to, 'success', 'Preset "' . $title . '" berhasil dibuat. Gunakan widget(\'' . $slug . '\') di sidebar.');
    }
} catch (Throwable $e) {
    error_log('shortcodes/save.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($return_to, 'error', 'Gagal menyimpan preset: ' . $e->getMessage());
}
