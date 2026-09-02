<?php
declare(strict_types=1);

// /adiwira/admin/pages/save.php
ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid] = adiwira_require_login($pdo, true);

if (!function_exists('adiwira_request_wants_json')) {
    function adiwira_request_wants_json(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

if (!function_exists('save_success_response')) {
    function save_success_response(string $message, string $redirect, array $extra = []): void {
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

if (!function_exists('save_error_response')) {
    function save_error_response(array $errors, string $redirect, int $httpCode = 400): void {
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
    save_error_response(['CSRF invalid'], ADMIN_BASE_PATH . '/?page=admin/pages/index', 419);
}

if (!function_exists('parse_dt_jkt')) {
    function parse_dt_jkt(string $s): ?string {
        $s = trim($s);
        if ($s === '') return null;

        $d = DateTime::createFromFormat('Y-m-d\\TH:i', $s, new DateTimeZone('Asia/Jakarta'));
        if ($d !== false) return $d->format('Y-m-d H:i:s');

        try {
            $d2 = new DateTime($s, new DateTimeZone('Asia/Jakarta'));
            return $d2->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}

$errors = [];
$now = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

$id            = (int)($_POST['id'] ?? 0);
$title         = trim((string)($_POST['title'] ?? ''));
$slug_in       = trim((string)($_POST['slug'] ?? ''));
$content       = (string)($_POST['content'] ?? '');
$statusIn      = (string)($_POST['status'] ?? 'draft');
$status        = in_array($statusIn, ['draft', 'published', 'private'], true) ? $statusIn : 'draft';
$thumbnail     = trim((string)($_POST['thumbnail'] ?? '')) ?: null;
$created_at_in = trim((string)($_POST['created_at'] ?? ''));
$updated_at_in = trim((string)($_POST['updated_at'] ?? ''));
$created_by_in = (int)($_POST['created_by'] ?? 0);
$return_to     = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), ADMIN_BASE_PATH . '/?page=admin/pages/index')
    : ADMIN_BASE_PATH . '/?page=admin/pages/index';
$edit_return   = ADMIN_BASE_PATH . '/?' . http_build_query([
    'page'      => 'admin/pages/edit',
    'id'        => $id,
    'return_to' => $return_to,
]);

if ($id <= 0) {
    $errors[] = __('Invalid ID.');
}

$title = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($title)));
if ($title === '') {
    $errors[] = __('Title is required.');
}

if (!user_can($pdo, $uid, 'core.pages.unfiltered_html')) $content = cms_sanitize_restricted_html($content);

if (function_exists('normalize_links_in_html') && class_exists('DOMDocument')) {
    $content = normalize_links_in_html($content);
}

if (trim(strip_tags($content)) === '') {
    $errors[] = __('Content is required.');
}

// slug
$slugSeed = ($slug_in !== '') ? $slug_in : ($title !== '' ? $title : 'untitled');
$slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', mb_strtolower($slugSeed, 'UTF-8'));
$slug = preg_replace('/[-]{2,}/', '-', (string)$slug);
$slug = trim((string)$slug, '-');
if ($slug === '') {
    $slug = bin2hex(random_bytes(4));
}

// existing page
$st = $pdo->prepare("
    SELECT id, slug, status, created_by, created_at, meta
    FROM posts
    WHERE id = :id
      AND type = 'page'
      AND is_deleted = 0
    LIMIT 1
");
$st->execute([':id' => $id]);
$existing = $st->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    $errors[] = __('Page not found.');
}
$existingEditorStatus = is_array($existing)
    ? apply_filters('admin_page_editor_status', (string)($existing['status'] ?? 'draft'), $existing, $pdo)
    : 'draft';
if (!is_string($existingEditorStatus) || !in_array($existingEditorStatus, ['draft', 'published', 'private'], true)) {
    $errors[] = __('Page editor status is invalid.');
    $existingEditorStatus = 'draft';
}

if (empty($errors) && !user_can($pdo, $uid, 'core.pages.update', ['owner_id' => (int)($existing['created_by'] ?? 0)])) {
    $errors[] = __('Access denied.');
}
if (empty($errors) && ($existingEditorStatus !== 'draft' || $status !== 'draft')
    && !user_can($pdo, $uid, 'core.pages.publish', ['owner_id' => (int)($existing['created_by'] ?? 0)])) {
    $errors[] = __('Access denied.');
}
if (empty($errors) && ($created_at_in !== '' || $updated_at_in !== '')
    && !user_can($pdo, $uid, 'core.pages.change_dates', ['owner_id' => (int)($existing['created_by'] ?? 0)])) {
    $errors[] = __('Access denied.');
}

// unique slug
if (empty($errors)) {
    $q = $pdo->prepare("
        SELECT id
        FROM posts
        WHERE slug = :slug
          AND id != :id
          AND type IN ('article', 'page', 'theme')
          AND is_deleted = 0
        LIMIT 1
    ");
    $q->execute([
        ':slug' => $slug,
        ':id'   => $id,
    ]);

    if ($q->fetch()) {
        $errors[] = __('Slug already used by another page.');
    }
}

if (!empty($errors)) {
    save_error_response($errors, $edit_return, 400);
}

// default admin fields
$final_created = (string)($existing['created_at'] ?? $now);
$final_updated = $now;

$pc = $created_at_in !== '' ? parse_dt_jkt($created_at_in) : null;
$pu = $updated_at_in !== '' ? parse_dt_jkt($updated_at_in) : null;
if ($created_at_in !== '' && $pc === null) save_error_response([__('Invalid Created At format.')], $edit_return, 400);
if ($updated_at_in !== '' && $pu === null) save_error_response([__('Invalid Updated At format.')], $edit_return, 400);
if ($pc) $final_created = $pc;
if ($pu) $final_updated = $pu;

$final_creator = (int)($existing['created_by'] ?? $uid);
if ($created_by_in > 0 && $created_by_in !== $final_creator
    && user_can($pdo, $uid, 'core.pages.change_owner', ['owner_id' => $final_creator])) {
    $chk = $pdo->prepare("
        SELECT id
        FROM users
        WHERE id = :id
          AND is_deleted = 0
          AND is_locked = 0
        LIMIT 1
    ");
    $chk->execute([':id' => $created_by_in]);
    if ($chk->fetchColumn() && user_can($pdo, $uid, 'core.pages.change_owner', ['owner_id' => $created_by_in])) {
        $final_creator = $created_by_in;
    }
    else save_error_response([__('Author not found.')], $edit_return, 400);
} elseif ($created_by_in > 0 && $created_by_in !== $final_creator) {
    save_error_response([__('Access denied.')], $edit_return, 403);
}

$sidebarOverride = (string)($_POST['sidebar_override'] ?? '');
if ($sidebarOverride !== '' && !in_array($sidebarOverride, ['right', 'left', 'hide'], true)) {
    $sidebarOverride = '';
}
$metaDescription = trim((string)($_POST['meta_description'] ?? ''));
$currentMeta = !empty($existing['meta']) ? json_decode($existing['meta'], true) : [];
if (!is_array($currentMeta)) $currentMeta = [];
if ($sidebarOverride !== '') {
    $currentMeta['sidebar'] = $sidebarOverride;
} else {
    unset($currentMeta['sidebar']);
}
if ($metaDescription !== '') {
    $currentMeta['meta_tags']['description'] = $metaDescription;
} else {
    unset($currentMeta['meta_tags']['description']);
    if (empty($currentMeta['meta_tags'])) {
        unset($currentMeta['meta_tags']);
    }
}
$finalMeta = !empty($currentMeta) ? json_encode($currentMeta, JSON_UNESCAPED_UNICODE) : null;
$requiresDatePermission = $created_at_in !== '' || $updated_at_in !== '';

try {
    shortcode_collection_layout_content_mutation($pdo, static function () use ($pdo, $title, $slug, $content, $thumbnail, $status, $finalMeta, $final_creator, $final_created, $final_updated, $id, $uid, $requiresDatePermission, $created_at_in): void {
    $pdo->beginTransaction();
    try {
    if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Page actor permission lock failed.');
    if (!user_can($pdo, $uid, 'core.pages.unfiltered_html')) $content = cms_sanitize_restricted_html($content);
    $lock = $pdo->prepare("SELECT id, created_by, status, created_at FROM posts WHERE id = :id AND type = 'page' AND is_deleted = 0 FOR UPDATE");
    $lock->execute([':id' => $id]);
    $lockedPage = $lock->fetch(PDO::FETCH_ASSOC);
    $lockedEditorStatus = is_array($lockedPage)
        ? apply_filters('admin_page_editor_status', (string)($lockedPage['status'] ?? 'draft'), $lockedPage, $pdo)
        : 'draft';
    if (!is_string($lockedEditorStatus) || !in_array($lockedEditorStatus, ['draft', 'published', 'private'], true)) {
        throw new DomainException('Page editor status is invalid.');
    }
    $lockedOwnerId = (int)($lockedPage['created_by'] ?? 0);
    if (!authorization_lock_owner_contexts($pdo, [$lockedOwnerId])) throw new DomainException('Page owner context lock failed.');
    if (!$lockedPage || !user_can($pdo, $uid, 'core.pages.update', ['owner_id' => $lockedOwnerId])) {
        throw new DomainException('Page update permission changed.');
    }
    if (($lockedEditorStatus !== 'draft' || $status !== 'draft')
        && !user_can($pdo, $uid, 'core.pages.publish', ['owner_id' => $lockedOwnerId])) {
        throw new DomainException('Page publish permission changed.');
    }
    if ($requiresDatePermission && !user_can($pdo, $uid, 'core.pages.change_dates', ['owner_id' => $lockedOwnerId])) {
        throw new DomainException('Page date permission changed.');
    }
    if ($final_creator !== $lockedOwnerId
        && !user_can($pdo, $uid, 'core.pages.change_owner', ['owner_id' => $lockedOwnerId])) {
        throw new DomainException('Page owner permission changed.');
    }
    if ($final_creator !== $lockedOwnerId
        && !user_can($pdo, $uid, 'core.pages.change_owner', ['owner_id' => $final_creator])) {
        throw new DomainException('Page owner target permission changed.');
    }
    if ($final_creator !== $lockedOwnerId) {
        $ownerLock = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_deleted = 0 AND is_locked = 0 FOR UPDATE');
        $ownerLock->execute([':id' => $final_creator]);
        if (!$ownerLock->fetchColumn()) throw new DomainException('Page owner target changed.');
    }
    $slugLock = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug AND id != :id AND type IN ('article', 'page', 'theme') AND is_deleted = 0 LIMIT 1 FOR UPDATE");
    $slugLock->execute([':slug' => $slug, ':id' => $id]);
    if ($slugLock->fetchColumn()) throw new DomainException('Page slug changed.');
    $effectiveCreatedAt = $created_at_in !== '' ? $final_created : (string)$lockedPage['created_at'];
    $upd = $pdo->prepare("
        UPDATE posts
        SET title      = :title,
            slug       = :slug,
            content    = :content,
            thumbnail  = :thumbnail,
            status     = :status,
            meta       = :meta,
            created_by = :created_by,
            created_at = :created_at,
            updated_at = :updated_at,
            updated_by = :updated_by
        WHERE id = :id
          AND type = 'page'
          AND is_deleted = 0
        LIMIT 1
    ");

    $ok = $upd->execute([
        ':title'      => $title,
        ':slug'       => $slug,
        ':content'    => $content,
        ':thumbnail'  => $thumbnail,
        ':status'     => $status,
        ':meta'       => $finalMeta,
        ':created_by' => $final_creator,
        ':created_at' => $effectiveCreatedAt,
        ':updated_at' => $final_updated,
        ':updated_by' => $uid,
        ':id'         => $id,
    ]);

    if (!$ok) {
        throw new RuntimeException('DB update failed.');
    }
    do_action('admin_page_before_edit_commit', $id, $pdo, [
        'title' => $title,
        'slug' => $slug,
        'content' => $content,
        'status' => $status,
        'previous_created_by' => $lockedOwnerId,
        'created_by' => $final_creator,
        'updated_by' => $uid,
    ]);
    $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    });

    do_action('admin_page_after_edit', $id, $pdo, $_POST);

    save_success_response(__('Page updated successfully.'), $return_to, [
        'page' => [
            'id'         => $id,
            'slug'       => $slug,
            'status'     => $status,
            'created_by' => $final_creator,
            'updated_at' => $final_updated,
        ],
        'updated_at' => $final_updated,
    ]);

} catch (Throwable $e) {
    error_log('pages/save.php error: ' . $e->getMessage());
    save_error_response(['Gagal menyimpan halaman.'], $edit_return, 500);
}
