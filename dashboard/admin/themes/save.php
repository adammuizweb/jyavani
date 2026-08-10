<?php
declare(strict_types=1);

// /adiwira/admin/themes/save.php
ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$user_id, $user_role] = adiwira_require_editorial($pdo, true);
$isAdmin = ($user_role === 'admin');

if (!function_exists('adiwira_request_wants_json')) {
    function adiwira_request_wants_json(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

if (!function_exists('theme_save_success_response')) {
    function theme_save_success_response(string $message, string $redirect, array $extra = []): void {
        if (adiwira_request_wants_json()) {
            adiwira_json(array_merge([
                'ok' => true,
                'message' => $message,
            ], $extra), 200);
        }

        adiwira_flash_push('success', $message);
        header('Location: ' . $redirect, true, 302);
        exit;
    }
}

if (!function_exists('theme_save_error_response')) {
    function theme_save_error_response(array $errors, string $redirect, int $httpCode = 400): void {
        $errors = array_values(array_filter(array_map('strval', $errors)));
        if (!$errors) {
            $errors = ['Gagal menyimpan perubahan.'];
        }

        if (adiwira_request_wants_json()) {
            adiwira_json([
                'ok' => false,
                'errors' => $errors,
            ], $httpCode);
        }

        adiwira_redirect_with_flash($redirect, 'error', $errors[0]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    theme_save_error_response(['CSRF invalid'], ADMIN_BASE_PATH . '/?page=admin/themes/index', 419);
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

$id         = (int)($_POST['id'] ?? 0);
$save_nonce = (string)($_POST['save_nonce'] ?? '');
$title      = trim((string)($_POST['title'] ?? ''));
$slug_in    = trim((string)($_POST['slug'] ?? ''));
$publicPath = trim((string)($_POST['public_path'] ?? ''));
$content    = (string)($_POST['content'] ?? '');
$statusIn   = (string)($_POST['status'] ?? 'draft');
$status     = in_array($statusIn, ['draft','published','private'], true) ? $statusIn : 'draft';
$return_to  = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), ADMIN_BASE_PATH . '/?page=admin/themes/index')
    : ADMIN_BASE_PATH . '/?page=admin/themes/index';

$edit_return = ADMIN_BASE_PATH . '/?' . http_build_query([
    'page'      => 'admin/themes/edit',
    'id'        => $id,
    'return_to' => $return_to,
]);

$errors = [];

if ($id <= 0) {
    $errors[] = __('Invalid ID.');
}

$session_key   = 'theme_save_nonce_' . $id;
$session_nonce = $_SESSION[$session_key] ?? null;

if (!$session_nonce || $save_nonce === '' || !hash_equals((string)$session_nonce, (string)$save_nonce)) {
    $errors[] = __('Token penyimpanan tidak valid atau sudah digunakan (duplikat). Coba muat ulang halaman.');
}

if ($title === '') {
    $errors[] = __('Title is required.');
}

if (trim($content) === '') {
    $errors[] = __('Content is required.');
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
        $errors[] = __('Theme tidak ditemukan.');
    }
}

if ($publicPath !== '' && empty($errors)) {
    try {
        $publicPath = content_route_normalize_path($publicPath);
        $conflict = content_route_path_conflict($pdo, $publicPath, '', $id);
        if ($conflict !== null) $errors[] = __('Public path is already used or reserved.');
    } catch (Throwable $e) {
        $errors[] = __('Public path must contain lowercase letters, numbers, hyphens, underscores, and slashes only.');
    }
}

if (empty($errors)) {
    $s = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND id != :id LIMIT 1");
    $s->execute([':slug' => $slug, ':id' => $id]);
    if ($s->fetch()) {
        $errors[] = __('Slug sudah dipakai.');
    }
}

if (!empty($errors)) {
    theme_save_error_response($errors, $edit_return, 400);
}

unset($_SESSION[$session_key]);

try {
    $pdo->beginTransaction();
    $themeMeta = !empty($theme['meta']) ? json_decode($theme['meta'], true) : [];
    if (!is_array($themeMeta)) $themeMeta = [];
    $metaDescription = trim((string)($_POST['meta_description'] ?? ''));
    if ($metaDescription !== '') {
        $themeMeta['meta_tags']['description'] = $metaDescription;
    } else {
        unset($themeMeta['meta_tags']['description']);
        if (empty($themeMeta['meta_tags'])) {
            unset($themeMeta['meta_tags']);
        }
    }
    $finalMeta = !empty($themeMeta) ? json_encode($themeMeta, JSON_UNESCAPED_UNICODE) : null;

    $upd = $pdo->prepare("
        UPDATE posts
        SET title = :title,
            slug = :slug,
            content = :content,
            status = :status,
            meta = :meta,
            " . ($isAdmin ? "created_by = :author_id," : "") . "
            updated_at = NOW()
        WHERE id = :id
          AND type = 'theme'
          AND is_deleted = 0
          " . (!$isAdmin ? "AND created_by = :uid" : "") . "
        LIMIT 1
    ");

    $params = [
        ':title'   => $title,
        ':slug'    => $slug,
        ':content' => $content,
        ':status'  => $status,
        ':meta'    => $finalMeta,
        ':id'      => $id,
    ];
    if ($isAdmin) {
        $params[':author_id'] = !empty($_POST['created_by']) ? (int)$_POST['created_by'] : $user_id;
    }
    if (!$isAdmin) {
        $params[':uid'] = $user_id;
    }

    $ok = $upd->execute($params);
    if (!$ok) {
        throw new RuntimeException('DB error saat menyimpan.');
    }
    if ($publicPath === '') {
        content_route_delete_for_post($pdo, $id);
    } else {
        content_route_set_canonical($pdo, $id, $publicPath);
    }
    do_action('admin_theme_after_edit', $id, $pdo, $_POST);
    $pdo->commit();

    $stmt2 = $pdo->prepare("SELECT id, slug, title, status, updated_at FROM posts WHERE id = :id LIMIT 1");
    $stmt2->execute([':id' => $id]);
    $new = $stmt2->fetch(PDO::FETCH_ASSOC) ?: ($theme ?: []);

    $new_nonce = bin2hex(random_bytes(12));
    $_SESSION[$session_key] = $new_nonce;

    theme_save_success_response(__('Theme partial berhasil diperbarui.'), $return_to, [
        'theme' => [
            'id'     => (int)($new['id'] ?? $id),
            'slug'   => (string)($new['slug'] ?? $slug),
            'title'  => (string)($new['title'] ?? $title),
            'status' => (string)($new['status'] ?? $status),
        ],
        'updated_at' => (string)($new['updated_at'] ?? date('Y-m-d H:i:s')),
        'new_save_nonce' => $new_nonce,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('themes/save.php error: ' . $e->getMessage());
    theme_save_error_response(['Server error'], $edit_return, 500);
}
