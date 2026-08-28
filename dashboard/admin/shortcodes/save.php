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
    ? adiwira_safe_return_to($_POST['return_to'] ?? null, $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$csrfInput = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrf = is_string($csrfInput) ? $csrfInput : '';
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'errors' => [__('Invalid CSRF token.')]], 419);
    exit;
}

$rawId = $_POST['id'] ?? 0;
$id = (is_string($rawId) || is_int($rawId)) && filter_var($rawId, FILTER_VALIDATE_INT) !== false ? max(0, (int)$rawId) : 0;
$save_nonce = is_string($_POST['save_nonce'] ?? null) ? $_POST['save_nonce'] : '';
$title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
$slug_in = is_string($_POST['slug'] ?? null) ? trim($_POST['slug']) : '';
$statusIn = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
$status     = in_array($statusIn, ['published','draft','private'], true) ? $statusIn : 'draft';
$config_json = is_string($_POST['config_json'] ?? null) ? $_POST['config_json'] : '';

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

try {
    $result = shortcode_collection_layout_with_lock($pdo, static function () use ($pdo, $errors, $config_json, $id, $isEdit, $isAdmin, $uid, $slug, $title, $status): array {
        $config = json_decode($config_json, true);
        if (!is_array($config)) {
            $errors[] = __('Invalid configuration format.');
            $config = [];
        } else {
            $allowProviderBinding = !$isEdit;
            $storedSource = null;
            $storedOwner = null;
            $storedConfig = [];
            $adoptionEligible = false;
            if ($isEdit) {
                $storedSql = "SELECT meta FROM posts WHERE id = :id AND type = 'sc_preset' AND is_deleted = 0";
                $storedParams = [':id' => $id];
                if (!$isAdmin) {
                    $storedSql .= ' AND created_by = :uid';
                    $storedParams[':uid'] = $uid;
                }
                $storedStmt = $pdo->prepare($storedSql . ' LIMIT 1');
                $storedStmt->execute($storedParams);
                $storedConfig = json_decode((string)($storedStmt->fetchColumn() ?: ''), true);
                if (!is_array($storedConfig)) $storedConfig = [];
                $storedSource = is_string($storedConfig['source'] ?? null) ? strtolower(trim($storedConfig['source'])) : 'posts';
                $storedOwner = is_string($storedConfig['source_owner'] ?? null) ? trim($storedConfig['source_owner']) : '';
                $submittedSource = is_string($config['source'] ?? null) ? strtolower(trim($config['source'])) : 'posts';
                $adoptionRequested = ($_POST['adopt_provider_owner'] ?? '') === '1';
                $adoptionEligible = shortcode_provider_adoption_allowed(
                    $storedConfig,
                    $config,
                    $isAdmin,
                    $adoptionRequested,
                    $pdo
                );
                if ($adoptionRequested && !$adoptionEligible) {
                    $errors[] = __('Provider adoption could not be confirmed. Reload the preset and try again.');
                }
                $allowProviderBinding = $adoptionEligible;
            }
            $context = [
                'id' => $id,
                'is_edit' => $isEdit,
                'is_admin' => $isAdmin,
                'user_id' => $uid,
                'post' => $_POST,
                'allow_provider_binding' => $allowProviderBinding,
            ];
            $postedPluginConfig = $_POST['preset_config'] ?? [];
            if (is_array($postedPluginConfig)) $config = array_replace_recursive($config, $postedPluginConfig);
            $config = shortcode_preset_normalize_source_transition(
                $config,
                $isEdit ? $storedConfig : null,
                $context,
                $pdo
            );
            $config = shortcode_preset_apply_source_defaults($config, $context, $pdo);
            $filteredConfig = apply_filters('shortcode_preset_config_before_save', $config, $context, $pdo);
            if (is_array($filteredConfig)) $config = $filteredConfig;
            $finalSource = is_string($config['source'] ?? null) ? strtolower(trim($config['source'])) : 'posts';
            $finalOwner = is_string($config['source_owner'] ?? null) ? trim($config['source_owner']) : '';
            if ($isEdit && $storedSource === $finalSource && $storedOwner === '' && $finalOwner !== '' && !$adoptionEligible) {
                $errors[] = __('Explicit provider adoption is required for this ownerless preset.');
            }
            $validation = shortcode_preset_validate_config($config, $isAdmin, $pdo, $context);
            $config = $validation['config'];
            $errors = array_merge($errors, $validation['errors']);
        }

        if ($errors === [] && shortcode_preset_slug_collision($pdo, $slug, $isEdit ? $id : 0) !== null) {
            $errors[] = sprintf(__('Widget name "%s" conflicts with an existing preset or registered widget.'), $slug);
        }
        if ($errors === [] && ($config['source'] ?? 'posts') === 'posts' && ($config['category'] ?? '') !== '') {
            $checkCategory = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug AND is_deleted = 0 LIMIT 1');
            $checkCategory->execute([':slug' => $config['category']]);
            if (!$checkCategory->fetchColumn()) $errors[] = __('Category not found.');
        }
        if ($errors === [] && ($config['author'] ?? null) !== null) {
            $checkAuthor = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_deleted = 0 AND is_locked = 0 LIMIT 1');
            $checkAuthor->execute([':id' => $config['author']]);
            if (!$checkAuthor->fetchColumn()) $errors[] = __('Author not found.');
        }
        if ($errors !== []) return ['errors' => $errors, 'config' => $config];

        $metaJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($isEdit) {
            $sql = "UPDATE posts SET title = :title, slug = :slug, meta = :meta, status = :status, updated_at = NOW() WHERE id = :id AND type = 'sc_preset' AND is_deleted = 0";
            $params = [':title' => $title, ':slug' => $slug, ':meta' => $metaJson, ':status' => $status, ':id' => $id];
            if (!$isAdmin) {
                $sql .= " AND created_by = :uid";
                $params[':uid'] = $uid;
            }
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
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
                if (!$exists->fetchColumn()) return ['errors' => [__('Preset not found or you are not allowed to edit it.')], 'config' => $config];
            }
            return ['errors' => [], 'config' => $config, 'id' => $id, 'event' => 'edit'];
        }

        $stmt = $pdo->prepare("INSERT INTO posts (title, slug, content, meta, type, status, created_by, created_at, updated_at) VALUES (:title, :slug, '', :meta, 'sc_preset', :status, :uid, NOW(), NOW())");
        $stmt->execute([':title' => $title, ':slug' => $slug, ':meta' => $metaJson, ':status' => $status, ':uid' => $uid]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Preset insert did not affect one row.');
        return ['errors' => [], 'config' => $config, 'id' => (int)$pdo->lastInsertId(), 'event' => 'add'];
    });
} catch (Throwable $e) {
    error_log('shortcodes/save.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($return_to, 'error', __('Failed to save preset.'));
}

if ($result['errors'] !== []) {
    $_SESSION['sc_add_nonce'] = bin2hex(random_bytes(12));
    if ($isEdit) $_SESSION[$session_key] = bin2hex(random_bytes(12));
    $redirect = $isEdit
        ? ADMIN_BASE_PATH . '/?' . http_build_query(['page' => 'admin/shortcodes/edit', 'id' => $id, 'return_to' => $return_to])
        : ADMIN_BASE_PATH . '/?' . http_build_query(['page' => 'admin/shortcodes/edit', 'return_to' => $return_to]);
    adiwira_redirect_with_flash($redirect, 'error', implode('; ', $result['errors']));
}

unset($_SESSION[$isEdit ? $session_key : 'sc_add_nonce']);
try {
    if ($result['event'] === 'edit') do_action('admin_shortcode_preset_after_edit', $id, $pdo, $_POST);
    else {
        $newId = $result['id'];
        do_action('admin_shortcode_preset_after_add', $newId, $pdo, $_POST);
    }
} catch (Throwable $hookError) {
    error_log('admin_shortcode_preset_after_' . $result['event'] . ' hook error: ' . $hookError->getMessage());
}
if ($result['event'] === 'edit') {
    adiwira_redirect_with_flash($return_to, 'success', __('Preset') . ' "' . $title . '" ' . __('updated successfully.'));
}
adiwira_redirect_with_flash($return_to, 'success', __('Preset') . ' "' . $title . '" ' . __('created successfully. Use') . ' widget(\'' . $slug . '\') ' . __('in sidebar.'));
