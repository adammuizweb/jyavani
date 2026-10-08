<?php
declare(strict_types=1);

ob_start();
@ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_guard.php';

adiwira_cosmetic_404_on_direct_open();

$uploadState = [
    'workspace' => null,
    'publication_stage' => null,
    'target_path' => null,
    'published' => false,
    'committed' => false,
    'content_hash_locks' => [],
];
$cleanupUpload = static function (bool $removePublished = false) use (&$uploadState): void {
    if ($uploadState['content_hash_locks'] !== [] && function_exists('theme_operation_release')) {
        try {
            theme_operation_release($uploadState['content_hash_locks']);
        } catch (Throwable $error) {
            error_log('upload_image.php lock cleanup failed: ' . $error->getMessage());
        }
        $uploadState['content_hash_locks'] = [];
    }
    if (is_string($uploadState['publication_stage'])) {
        @unlink($uploadState['publication_stage']);
        $uploadState['publication_stage'] = null;
    }
    if (($removePublished || !$uploadState['committed']) && $uploadState['published']
        && is_string($uploadState['target_path'])) {
        @unlink($uploadState['target_path']);
        $uploadState['published'] = false;
    }
    media_upload_remove_workspace($uploadState['workspace']);
    $uploadState['workspace'] = null;
};

register_shutdown_function(static function () use (&$uploadState, $cleanupUpload): void {
    $cleanupUpload(false);
    $sent = (bool)($GLOBALS['__ADIWIRA_JSON_SENT'] ?? false);
    if ($sent) return;

    $err = error_get_last();
    if (!$err) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$err['type'], $fatalTypes, true)) return;

    error_log('upload_image.php fatal: ' . ($err['message'] ?? 'unknown') . ' in ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?'));
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    echo json_encode([
        'success' => false,
        'ok' => false,
        'error' => __('Server error'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

[$uid, $role] = adiwira_require_editorial($pdo, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Not found')], 404);
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!adiwira_csrf_validate(is_string($csrf) ? $csrf : '')) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('CSRF invalid')], 419);
}
if (!user_can($pdo, $uid, 'core.media.upload')) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Access denied.')], 403);
}
if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('File not found')], 400);
}

$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => sprintf(__('Upload error code: %d'), (int)$file['error'])], 400);
}
if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Invalid upload')], 400);
}

$auto_save = !empty($_POST['auto_save']) && in_array((string)$_POST['auto_save'], ['1', 'true', 'on'], true);

if (!function_exists('mdlib_has_column')) {
    function mdlib_has_column(PDO $pdo, string $column): bool
    {
        try {
            $st = $pdo->prepare("SELECT {$column} FROM media LIMIT 0");
            $st->execute();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
if (!function_exists('adiwira_media_private_base_dir')) {
    function adiwira_media_private_base_dir(): string
    {
        $appRoot = realpath(__DIR__ . '/../..');
        if ($appRoot === false) $appRoot = dirname(__DIR__, 2);
        return rtrim(str_replace('\\', '/', $appRoot), '/') . '/private_files';
    }
}
if (!function_exists('adiwira_media_normalize_choice')) {
    function adiwira_media_normalize_choice(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}

$hasPrivateCols = mdlib_has_column($pdo, 'visibility');
$hasContentHash = mdlib_has_column($pdo, 'content_hash');
$visibilityInput = $hasPrivateCols
    ? adiwira_media_normalize_choice((string)($_POST['visibility'] ?? 'auto'), ['auto', 'public', 'private'], 'auto')
    : 'public';
$accessScopeInput = $hasPrivateCols
    ? adiwira_media_normalize_choice((string)($_POST['access_scope'] ?? 'editorial'), ['public', 'editorial', 'admin'], 'editorial')
    : 'public';
$visibility = $visibilityInput === 'auto' ? 'public' : $visibilityInput;
if (!$hasPrivateCols) $visibility = 'public';
$storage_disk = $visibility === 'private' ? 'private' : 'public';
$access_scope = $visibility === 'private' ? $accessScopeInput : 'public';
if ($access_scope === 'public' && $visibility === 'private') $access_scope = 'editorial';
if ($visibility === 'private' && !$auto_save) {
    adiwira_json([
        'success' => false,
        'ok' => false,
        'error' => __('Private files require auto-save so a protected URL can be generated.'),
    ], 400);
}
$is_downloadable = array_key_exists('is_downloadable', $_POST)
    ? (in_array((string)$_POST['is_downloadable'], ['1', 'true', 'on', 'yes'], true) ? 1 : 0)
    : ($visibility === 'private' ? 0 : 1);

$mediaContext = media_picker_context_from_request($_POST, ['surface' => 'admin.media.upload']);
$extensionInput = media_extension_input($_POST);
$private_upload_base_dir = adiwira_media_private_base_dir();
$originalFilename = media_upload_original_name($file['name'] ?? 'upload');

try {
    $uploadState['workspace'] = media_upload_private_workspace($private_upload_base_dir);
    $stagePath = $uploadState['workspace'] . '/input';
    if (!move_uploaded_file((string)$file['tmp_name'], $stagePath) || !@chmod($stagePath, 0600)) {
        throw new RuntimeException('Unable to move uploaded image into private staging.');
    }
    $descriptor = media_upload_descriptor($stagePath, $originalFilename);
} catch (MediaUploadRejected $error) {
    $cleanupUpload(false);
    adiwira_json(['success' => false, 'ok' => false, 'error' => __($error->getMessage())], $error->status());
} catch (Throwable $error) {
    error_log('upload_image.php private staging failed: ' . $error->getMessage());
    $cleanupUpload(false);
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Failed to save file')], 500);
}

try {
    media_upload_preprocess($stagePath, $descriptor, $mediaContext, $extensionInput);
} catch (MediaUploadRejected $error) {
    $cleanupUpload(false);
    adiwira_json(['success' => false, 'ok' => false, 'error' => $error->getMessage()], $error->status());
} catch (Throwable $error) {
    error_log('upload_image.php media_upload_preprocess failed: ' . $error->getMessage());
    $cleanupUpload(false);
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Image preprocessing failed.')], 500);
}

try {
    $finalImage = media_upload_inspect_image($stagePath);
} catch (Throwable $error) {
    error_log('upload_image.php processed image validation failed: ' . $error->getMessage());
    $cleanupUpload(false);
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('The processed image is invalid.')], 422);
}

$mime = $finalImage['mime'];
$ext = $finalImage['ext'];
$size = $finalImage['size'];
$width = $finalImage['width'];
$height = $finalImage['height'];
$contentHash = $finalImage['sha256'];

try {
    $uploadState['content_hash_locks'] = function_exists('theme_operation_acquire')
        ? theme_operation_acquire(['media-content-' . $contentHash], LOCK_EX, microtime(true) + 10.0)
        : [];
} catch (Throwable $lockError) {
    error_log('upload_image.php identity lock failed: ' . $lockError->getMessage());
    $uploadState['content_hash_locks'] = [];
}
if ($uploadState['content_hash_locks'] === []) {
    $cleanupUpload(false);
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Unable to lock uploaded image identity.')], 503);
}

try {
    if ($auto_save && $hasContentHash) {
        $pdo->beginTransaction();
        if (!authorization_lock_actor_permissions($pdo, $uid)
            || !user_can($pdo, $uid, 'core.media.upload')) {
            throw new AssetLifecycleAccessDenied('Media upload permission denied.');
        }
        $duplicateRow = media_find_authorized_duplicate($pdo, $contentHash, $uid, true);
        if ($duplicateRow !== null) {
            $duplicateVisibility = strtolower((string)($duplicateRow['visibility'] ?? 'public'));
            $duplicateDisk = strtolower((string)($duplicateRow['storage_disk'] ?? 'public'));
            $duplicateScope = strtolower((string)($duplicateRow['access_scope'] ?? 'public'));
            $duplicateDownloadable = (int)($duplicateRow['is_downloadable'] ?? 1);
            if ($duplicateVisibility !== $visibility || $duplicateDisk !== $storage_disk
                || $duplicateScope !== $access_scope || $duplicateDownloadable !== $is_downloadable) {
                $duplicateRow = null;
            }
        }
        if ($duplicateRow !== null && $extensionInput !== [] && $mediaContext['selection_mode'] !== 'review') {
            $duplicateRow = null;
        }
        if ($duplicateRow !== null) {
            if (!$pdo->commit()) throw new RuntimeException('Unable to complete duplicate media lookup.');
            $duplicateMedia = media_filter_data($pdo, $duplicateRow, $mediaContext, true);
            $cleanupUpload(false);
            adiwira_json([
                'success' => true,
                'ok' => true,
                'duplicate' => true,
                'message' => __('This image already exists. You can reuse the existing media.'),
                'url' => $duplicateMedia['url'],
                'media' => $duplicateMedia,
                'context' => $mediaContext,
            ], 200);
        }
        if (!$pdo->commit()) throw new RuntimeException('Unable to complete duplicate media lookup.');
    }

    $publicPath = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $upload_base_dir = rtrim($publicPath, '/\\') . '/static/img';
    $upload_base_url = '/static/img';
    $year = date('Y');
    $month = date('m');
    $relative_storage_path = $year . '/' . $month;
    if ($storage_disk === 'private') {
        $target_dir = rtrim($private_upload_base_dir, '/\\') . '/media/' . $relative_storage_path;
        $dirMode = 0750;
    } else {
        $target_dir = $upload_base_dir . '/' . $relative_storage_path;
        $dirMode = 0775;
    }
    if (!is_dir($target_dir) && !@mkdir($target_dir, $dirMode, true) && !is_dir($target_dir)) {
        throw new RuntimeException('Failed to create upload folder.');
    }
    @chmod($target_dir, $dirMode);

    $originalStem = pathinfo($originalFilename, PATHINFO_FILENAME);
    $slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', mb_strtolower($originalStem, 'UTF-8'));
    $slug = trim((string)$slug, '-');
    if ($slug === '') $slug = bin2hex(random_bytes(4));
    $filename = $slug . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target_path = $target_dir . '/' . $filename;
    if (file_exists($target_path) || is_link($target_path)) {
        throw new RuntimeException('Media publication target already exists.');
    }
    $uploadState['target_path'] = $target_path;
    [$publicationStage, $publicationImage] = media_upload_copy_private(
        $stagePath,
        $target_dir,
        'media-publication-stage',
        20 * 1024 * 1024,
        100000000
    );
    $uploadState['publication_stage'] = $publicationStage;
    if ($publicationImage !== $finalImage) throw new RuntimeException('Media publication stage verification failed.');

    $storage_path = $relative_storage_path . '/' . $filename;
    $public_url = rtrim($upload_base_url, '/') . '/' . $storage_path;
    $client_url = $storage_disk === 'private' ? '' : $public_url;
    $response = [
        'success' => true,
        'ok' => true,
        'url' => $client_url,
        'visibility' => $visibility,
        'storage_disk' => $storage_disk,
        'access_scope' => $access_scope,
        'is_downloadable' => $is_downloadable,
    ];
    $title = trim((string)($_POST['title'] ?? $originalStem));
    $alt = trim((string)($_POST['alt'] ?? ''));
    $caption = trim((string)($_POST['caption'] ?? ''));
    $credit = trim((string)($_POST['credit'] ?? ''));
    $event = null;

    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)
        || !user_can($pdo, $uid, 'core.media.upload')) {
        throw new AssetLifecycleAccessDenied('Media upload permission denied.');
    }
    media_create_before_publication($pdo, [
        'schema' => 2,
        'filename' => $filename,
        'mime' => $mime,
        'ext' => $ext,
        'size' => $size,
        'width' => $width,
        'height' => $height,
        'content_hash' => $contentHash,
        'url' => $public_url,
        'visibility' => $visibility,
        'storage_disk' => $storage_disk,
        'access_scope' => $access_scope,
        'is_downloadable' => $is_downloadable,
    ], $mediaContext, $extensionInput);

    if (media_upload_inspect_image($publicationStage) !== $finalImage
        || file_exists($target_path) || is_link($target_path)) {
        throw new RuntimeException('Staged media artifact changed before publication.');
    }
    asset_lifecycle_rename($publicationStage, $target_path);
    $uploadState['publication_stage'] = null;
    $uploadState['published'] = true;
    @chmod($target_path, $storage_disk === 'private' ? 0640 : 0644);

    if ($auto_save) {
        $commonCols = 'url, filename, mime, ext, size, width, height, title, alt, caption, credit, user_id, created_at';
        $commonVals = ':url, :filename, :mime, :ext, :size, :width, :height, :title, :alt, :caption, :credit, :user_id, NOW()';
        $extraCols = '';
        $extraVals = '';
        $extraParams = [];
        if ($hasContentHash) {
            $extraCols .= ', content_hash';
            $extraVals .= ', :content_hash';
            $extraParams[':content_hash'] = $contentHash;
        }
        if ($hasPrivateCols) {
            $extraCols .= ', visibility, storage_disk, storage_path, access_scope, is_downloadable';
            $extraVals .= ', :visibility, :storage_disk, :storage_path, :access_scope, :is_downloadable';
            $extraParams = array_merge($extraParams, [
                ':visibility' => $visibility,
                ':storage_disk' => $storage_disk,
                ':storage_path' => $storage_path,
                ':access_scope' => $access_scope,
                ':is_downloadable' => $is_downloadable,
            ]);
        }
        $stmt = $pdo->prepare("INSERT INTO media ({$commonCols}{$extraCols}) VALUES ({$commonVals}{$extraVals})");
        $stmt->execute(array_merge([
            ':url' => $public_url,
            ':filename' => $filename,
            ':mime' => $mime,
            ':ext' => $ext,
            ':size' => $size,
            ':width' => $width,
            ':height' => $height,
            ':title' => $title,
            ':alt' => $alt ?: null,
            ':caption' => $caption ?: null,
            ':credit' => $credit ?: null,
            ':user_id' => $uid,
        ], $extraParams));

        $media_id = (int)$pdo->lastInsertId();
        if ($storage_disk === 'private') {
            $client_url = '/private/media/view/?id=' . $media_id;
            $up = $pdo->prepare('UPDATE media SET url = :url WHERE id = :id LIMIT 1');
            $up->execute([':url' => $client_url, ':id' => $media_id]);
            $response['url'] = $client_url;
        }
        $createdRow = media_load_live($pdo, $media_id, true);
        if ($createdRow === null) throw new RuntimeException('Created media row is unavailable.');
        $artifact = resource_lifecycle_artifact([
            'kind' => 'file', 'role' => 'primary', 'managed' => true,
            'disk' => $storage_disk, 'root' => 'media.' . $storage_disk,
            'relative_path' => $storage_path, 'absolute_path' => $target_path, 'state' => 'present',
            'transition' => ['operation' => 'create', 'to' => ['root' => 'media.' . $storage_disk, 'relative_path' => $storage_path]],
        ]);
        $event = resource_lifecycle_capture($pdo, 'media', 'create', [['row' => $createdRow, 'artifacts' => [$artifact]]], [
            'actor_id' => $uid, 'source' => 'core.media_upload',
            'metadata' => media_mutation_metadata($pdo, 'create', $createdRow, $_POST, $mediaContext),
        ]);
        $items = $event['items'];
        $items[0]['after'] = $createdRow;
        $event = resource_lifecycle_before_commit($pdo, $event, ['items' => $items, 'result' => ['affected' => 1]]);
        $response['media'] = media_filter_data($pdo, $createdRow, $mediaContext, true);
        $response['context'] = $mediaContext;
    }

    if (!$pdo->commit()) throw new RuntimeException('Unable to commit media publication.');
    $uploadState['committed'] = true;
    $cleanupUpload(false);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $cleanupUpload(false);
    error_log('upload_image.php publication failed: ' . $error->getMessage());
    adiwira_json([
        'success' => false,
        'ok' => false,
        'error' => $error instanceof AssetLifecycleAccessDenied ? __('Access denied.') : __('Database insert failed'),
    ], $error instanceof AssetLifecycleAccessDenied ? 403 : 500);
}

if ($event !== null) asset_lifecycle_committed($pdo, $event);

if (!$auto_save) {
    try {
        $response['cleanup_token'] = asset_lifecycle_temporary_issue('media', $uid, $storage_path);
    } catch (Throwable $error) {
        $cleanupUpload(true);
        error_log('upload_image.php cleanup grant failed: ' . $error->getMessage());
        adiwira_json(['success' => false, 'ok' => false, 'error' => __('Failed to prepare temporary upload.')], 500);
    }
}

adiwira_json($response, 200);
