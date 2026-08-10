<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);
$isAdmin = ($role === 'admin');

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=presets';
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'errors' => [__('Invalid CSRF token.')]], 419);
    exit;
}

$id         = (int)($_POST['id'] ?? 0);
$save_nonce = (string)($_POST['save_nonce'] ?? '');
$title      = trim((string)($_POST['title'] ?? ''));
$slug_in    = trim((string)($_POST['slug'] ?? ''));
$statusIn   = (string)($_POST['status'] ?? 'draft');
$status     = in_array($statusIn, ['published','draft','private'], true) ? $statusIn : 'draft';
$config_json = (string)($_POST['config_json'] ?? '{}');

$errors = [];
$isEdit = $id > 0;

// Validate nonce
if ($isEdit) {
    $session_key = 'sc_save_nonce_' . $id;
    $session_nonce = $_SESSION[$session_key] ?? null;
    if (!$session_nonce || $save_nonce === '' || !hash_equals((string)$session_nonce, (string)$save_nonce)) {
        $errors[] = __('Save token invalid or already used (duplicate). Reload the page.');
    }
} else {
    $session_nonce = $_SESSION['sc_add_nonce'] ?? null;
    if (!$session_nonce || $save_nonce === '' || !hash_equals((string)$session_nonce, (string)$save_nonce)) {
        $errors[] = __('Save token invalid. Reload the page.');
    }
}

if ($title === '') {
    $errors[] = __('Preset name cannot be empty.');
} elseif ((function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) > 191) {
    $errors[] = __('Preset name is too long.');
}
if (!in_array($statusIn, ['published', 'draft', 'private'], true)) $errors[] = __('Invalid status.');

$slug = shortcode_preset_slugify($slug_in !== '' ? $slug_in : $title);
if (!shortcode_preset_slug_is_valid($slug)) {
    $errors[] = __('Widget name must contain lowercase letters, numbers, hyphens, or underscores only.');
}

// Decode config
$config = json_decode($config_json, true);
if (!is_array($config)) {
    $errors[] = __('Invalid configuration format.');
    $config = [];
} else {
    $config = array_merge(shortcode_preset_default_config($pdo), $config);
    $postedPluginConfig = $_POST['preset_config'] ?? [];
    if (is_array($postedPluginConfig)) {
        $config = array_replace_recursive($config, $postedPluginConfig);
    }
    $context = ['id' => $id, 'is_edit' => $isEdit, 'is_admin' => $isAdmin, 'user_id' => $uid, 'post' => $_POST];
    $filteredConfig = apply_filters('shortcode_preset_config_before_save', $config, $context, $pdo);
    if (is_array($filteredConfig)) $config = $filteredConfig;
    $validation = shortcode_preset_validate_config($config, $isAdmin, $pdo, $context);
    $config = $validation['config'];
    $errors = array_merge($errors, $validation['errors']);
}

// Check collisions with presets, registered shortcode handlers, and widget views.
if (empty($errors)) {
    if (shortcode_preset_slug_collision($pdo, $slug, $isEdit ? $id : 0) !== null) {
        $errors[] = sprintf(__('Widget name "%s" conflicts with an existing preset or registered widget.'), $slug);
    }
}

if (empty($errors) && ($config['source'] ?? 'posts') === 'posts' && (string)($config['category'] ?? '') !== '') {
    $checkCategory = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug AND is_deleted = 0 LIMIT 1');
    $checkCategory->execute([':slug' => (string)$config['category']]);
    if (!$checkCategory->fetchColumn()) $errors[] = __('Category not found.');
}
if (empty($errors) && ($config['author'] ?? null) !== null) {
    $checkAuthor = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_deleted = 0 AND is_locked = 0 LIMIT 1');
    $checkAuthor->execute([':id' => (int)$config['author']]);
    if (!$checkAuthor->fetchColumn()) $errors[] = __('Author not found.');
}

if (!empty($errors)) {
    unset($_SESSION['sc_add_nonce']);
    $_SESSION['sc_add_nonce'] = bin2hex(random_bytes(12));
    if ($isEdit) {
        $_SESSION[$session_key] = bin2hex(random_bytes(12));
    }
    $redirect = $isEdit
        ? ADMIN_BASE_PATH . '/?' . http_build_query(['page' => 'admin/shortcodes/edit', 'id' => $id, 'return_to' => $return_to])
        : ADMIN_BASE_PATH . '/?' . http_build_query(['page' => 'admin/shortcodes/edit', 'return_to' => $return_to]);
    adiwira_redirect_with_flash($redirect, 'error', implode('; ', $errors));
}

try {
    $metaJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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
        if ($stmt->rowCount() === 0) {
            $existsSql = "SELECT id FROM posts WHERE id = :id AND type = 'sc_preset' AND is_deleted = 0";
            $existsParams = [':id' => $id];
            if (!$isAdmin) {
                $existsSql .= ' AND created_by = :uid';
                $existsParams[':uid'] = $uid;
            }
            $exists = $pdo->prepare($existsSql . ' LIMIT 1');
            $exists->execute($existsParams);
            if (!$exists->fetchColumn()) {
                adiwira_redirect_with_flash($return_to, 'error', __('Preset not found or you are not allowed to edit it.'));
            }
        }

        try {
            do_action('admin_shortcode_preset_after_edit', $id, $pdo, $_POST);
        } catch (Throwable $hookError) {
            error_log('admin_shortcode_preset_after_edit hook error: ' . $hookError->getMessage());
        }
        adiwira_redirect_with_flash($return_to, 'success', __('Preset') . ' "' . $title . '" ' . __('updated successfully.'));
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
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Preset insert did not affect one row.');
        }

        $newId = (int)$pdo->lastInsertId();
        try {
            do_action('admin_shortcode_preset_after_add', $newId, $pdo, $_POST);
        } catch (Throwable $hookError) {
            error_log('admin_shortcode_preset_after_add hook error: ' . $hookError->getMessage());
        }
        adiwira_redirect_with_flash($return_to, 'success', __('Preset') . ' "' . $title . '" ' . __('created successfully. Use') . ' widget(\'' . $slug . '\') ' . __('in sidebar.'));
    }
} catch (Throwable $e) {
    error_log('shortcodes/save.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($return_to, 'error', __('Failed to save preset.'));
}
