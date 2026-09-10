<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/resource_lifecycle.php';
require_once $root . '/cfg/helpers/authorization.php';
require_once $root . '/cfg/helpers/media_helpers.php';
require_once $root . '/cfg/helpers/cms_content.php';
require_once $root . '/cfg/helpers/widget_helper.php';
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', $root . '/public');
require_once $root . '/cfg/helpers/asset_lifecycle.php';
require_once $root . '/app/controllers/PostController.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$context = media_picker_context_from_request([
    'media_surface' => 'admin.content.editor', 'media_consumer' => 'post',
    'media_resource_id' => '42', 'media_field' => 'featured', 'media_content_locale' => 'pt-BR',
]);
$check($context === ['schema' => 1, 'surface' => 'admin.content.editor', 'consumer' => 'post', 'resource_id' => 42, 'field' => 'featured', 'content_locale' => 'pt-BR'],
    'picker context has a stable validated structure');
$invalid = media_extension_context(['surface' => '../bad', 'consumer' => str_repeat('x', 101)]);
$check($invalid['surface'] === 'media' && $invalid['consumer'] === null, 'unsafe context values fail to bounded defaults');
$check(str_contains(media_picker_query($context), 'media_resource_id=42') && str_contains(media_picker_query($context), 'media_content_locale=pt-BR'),
    'validated context serializes for modal routes');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE media (id INTEGER PRIMARY KEY, url TEXT, filename TEXT, mime TEXT, size INTEGER, width INTEGER, height INTEGER, title TEXT, alt TEXT, caption TEXT, credit TEXT, target_url TEXT, target_attribute TEXT, visibility TEXT, storage_disk TEXT, storage_path TEXT, access_scope TEXT, is_downloadable INTEGER, user_id INTEGER, is_deleted INTEGER)');
$pdo->exec("INSERT INTO media VALUES (1, '/static/img/live.jpg', 'live.jpg', 'image/jpeg', 10, 20, 30, 'Base', 'Alt', 'Caption', 'Credit', NULL, NULL, 'public', 'public', 'secret/path.jpg', 'public', 1, 9, 0)");
$pdo->exec("INSERT INTO media VALUES (2, '/private/media/view/?id=2', 'private.jpg', 'image/jpeg', 10, 20, 30, 'Private', '', '', '', NULL, NULL, 'private', 'private', 'secret/private.jpg', 'editorial', 0, 9, 0)");
$pdo->exec("INSERT INTO media VALUES (3, '/static/img/deleted.jpg', 'deleted.jpg', 'image/jpeg', 10, 20, 30, '', '', '', '', NULL, NULL, 'public', 'public', 'deleted.jpg', 'public', 1, 9, 1)");
$pdo->exec("INSERT INTO media VALUES (4, '/static/img/protected.jpg', 'protected.jpg', 'image/jpeg', 10, 20, 30, 'Protected', '', '', '', NULL, NULL, 'public', 'public', 'protected.jpg', 'editorial', 1, 9, 0)");
$check(media_load_live($pdo, 1)['title'] === 'Base' && media_load_live($pdo, 3) === null, 'live-row loading excludes trashed media');
$safe = media_filter_data($pdo, media_load_live($pdo, 1), $context);
$check($safe['url'] === '/static/img/live.jpg' && !array_key_exists('storage_path', $safe) && !array_key_exists('user_id', $safe),
    'client projection omits storage and ownership data');
$check(media_client_url(media_load_live($pdo, 2), false) === null && media_client_url(media_load_live($pdo, 2), true) === '/private/media/view/?id=2',
    'private media requires explicit protected access');
$check(media_client_url(media_load_live($pdo, 4), false) === null
    && media_client_url(media_load_live($pdo, 4), true) === '/private/media/view/?id=4'
    && media_filter_data($pdo, media_load_live($pdo, 4), $context, true)['url'] === '/private/media/view/?id=4',
    'nonpublic scope on public disk always projects through the protected controller');
$check(!media_projected_url_is_valid('/private/media/view/?id=999', false)
    && !media_projected_url_is_valid('/private/media/view/?id=999', true, '/private/media/view/?id=2')
    && media_projected_url_is_valid('/private/media/view/?id=2', true, '/private/media/view/?id=2'),
    'projected protected URLs cannot be introduced or switched by filters');

add_filter('media_data', static function (array $data, array $row, array $ctx): array {
    if ($ctx['content_locale'] === 'pt-BR') $data['alt'] = 'Localized alt';
    $data['extensions']['example.extension'] = ['available' => ['en', 'pt-BR']];
    $data['storage_path'] = 'must-not-leak';
    return $data;
});
$filtered = media_filter_data($pdo, media_load_live($pdo, 1), $context);
$check($filtered['alt'] === 'Localized alt' && !isset($filtered['storage_path'])
    && $filtered['extensions'] === ['example.extension' => ['available' => ['en', 'pt-BR']]],
    'media filters expose bounded extension payloads without expanding the safe projection');
$malformedFilter = static function (array $data): array {
    $data['id'] = 2;
    $data['url'] = 'javascript:alert(1)';
    $data['title'] = ['not', 'text'];
    $data['visibility'] = 'private';
    $data['storage_path'] = 'must-not-leak';
    return $data;
};
add_filter('media_data', $malformedFilter, 20);
$hardened = media_filter_data($pdo, media_load_live($pdo, 1), $context);
$check($hardened['id'] === 1 && $hardened['url'] === '/static/img/live.jpg' && $hardened['title'] === 'Base'
    && $hardened['visibility'] === 'public' && !isset($hardened['storage_path']),
    'media projection rejects malformed identity, URL, metadata, and security changes');
remove_filter('media_data', $malformedFilter, 20);

$authorized = [media_load_live($pdo, 1), media_load_live($pdo, 2)];
$restricted = media_filter_authorized_rows($authorized, [
    ['id' => 2, 'title' => 'Decorated', 'user_id' => 999, 'visibility' => 'public', 'storage_path' => 'leak.jpg'],
    ['id' => 999, 'title' => 'Injected'],
]);
$check(count($restricted) === 1 && $restricted[0]['id'] === 2 && $restricted[0]['title'] === 'Decorated'
    && $restricted[0]['user_id'] === 9 && $restricted[0]['visibility'] === 'private'
    && $restricted[0]['storage_path'] === 'secret/private.jpg',
    'list filtering may remove, reorder, or decorate but cannot inject identities or alter authorization fields');
$featured = media_resolve_featured($pdo, ['id' => 8, 'thumbnail_media_id' => 1, 'thumbnail' => 'https://legacy.example/image.jpg'], $context);
$fallback = media_resolve_featured($pdo, ['id' => 9, 'thumbnail_media_id' => null, 'thumbnail' => 'https://legacy.example/image.jpg'], $context);
$missingIdentity = media_resolve_featured($pdo, ['id' => 10, 'thumbnail_media_id' => 999, 'thumbnail' => 'https://legacy.example/image.jpg'], $context);
$protectedIdentity = media_resolve_featured($pdo, ['id' => 11, 'thumbnail_media_id' => 4, 'thumbnail' => 'https://legacy.example/image.jpg'], $context);
$trashedIdentity = media_resolve_featured($pdo, ['id' => 12, 'thumbnail_media_id' => 3, 'thumbnail' => 'https://legacy.example/image.jpg'], $context);
$check($featured['id'] === 1 && $featured['alt'] === 'Localized alt' && $fallback['id'] === null && $fallback['url'] === 'https://legacy.example/image.jpg',
    'featured resolution prefers live identity and preserves legacy URL fallback');
$check($missingIdentity === null && $protectedIdentity === null && $trashedIdentity === null,
    'associated missing, trashed, or protected media never falls back to the legacy URL');
$malformedFeatured = static fn(): array => [
    'id' => 2, 'url' => '/static/img/live.jpg', 'title' => ['invalid'], 'storage_path' => 'must-not-leak',
];
add_filter('featured_media', $malformedFeatured);
$hardenedFeatured = media_resolve_featured($pdo, ['id' => 8, 'thumbnail_media_id' => 1, 'thumbnail' => 'https://legacy.example/image.jpg'], $context);
$check($hardenedFeatured['id'] === 1 && $hardenedFeatured['url'] === '/static/img/live.jpg'
    && !isset($hardenedFeatured['storage_path']), 'featured filtering rejects switched identities and raw fields');
remove_filter('featured_media', $malformedFeatured);
$suppressFeatured = static fn(): null => null;
add_filter('featured_media', $suppressFeatured);
$suppressedPosts = [
    ['id' => 10, 'thumbnail_media_id' => 1, 'thumbnail' => 'https://legacy.example/image.jpg'],
    ['id' => 11, 'thumbnail_media_id' => 1, 'thumbnail' => 'https://legacy.example/image.jpg', 'youtube' => 'https://youtu.be/abcdefghijk'],
];
media_normalize_featured_posts($pdo, $suppressedPosts, $context);
$check(PostController::resolve_post_display_image($suppressedPosts[0]) === null
    && $suppressedPosts[0]['thumbnail'] === null
    && PostController::resolve_post_display_image($suppressedPosts[1]) === 'https://img.youtube.com/vi/abcdefghijk/hqdefault.jpg',
    'featured null suppresses canonical fallback while preserving YouTube-first behavior');
remove_filter('featured_media', $suppressFeatured);
$youtubePosts = [[
    'id' => 12, 'youtube' => 'https://youtu.be/abcdefghijk', 'thumbnail_media_id' => 1,
    'thumbnail' => '/static/img/live.jpg',
]];
media_normalize_featured_posts($pdo, $youtubePosts, $context);
$check($youtubePosts[0]['display_image'] === 'https://img.youtube.com/vi/abcdefghijk/hqdefault.jpg'
    && media_post_display_url($youtubePosts[0]) === $youtubePosts[0]['display_image'],
    'shared collection normalization preserves YouTube-first display precedence');
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, slug TEXT, content TEXT, type TEXT, meta TEXT, youtube TEXT, thumbnail TEXT, thumbnail_media_id INTEGER, status TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, sort_order INTEGER, is_deleted INTEGER)');
$pdo->exec("INSERT INTO posts VALUES (30,'Video','video','<p>Body</p>','article',NULL,'https://youtu.be/abcdefghijk','/static/img/live.jpg',1,'published',9,'2026-01-01','2026-01-01',0,0)");
$cmsRows = cms_posts_fetch($pdo, ['limit' => 1]);
$widgetRows = widget_fetch_recent_posts($pdo, 1);
$check(($cmsRows[0]['display_image'] ?? null) === 'https://img.youtube.com/vi/abcdefghijk/hqdefault.jpg'
    && ($widgetRows[0]['display_image'] ?? null) === 'https://img.youtube.com/vi/abcdefghijk/hqdefault.jpg',
    'CMS and recent-widget collection helpers apply identical YouTube-first normalization at runtime');

$mutationFilter = static function (array $metadata, string $operation, array $row, array $input): array {
    $metadata['saved'] = $input['example.extension']['locale'] ?? null;
    return $metadata;
};
add_filter('media_mutation_metadata', $mutationFilter);
$mutationMetadata = media_mutation_metadata($pdo, 'update', media_load_live($pdo, 1), [
    'title' => 'Core value',
    'media_extension' => [
        'example.extension' => ['locale' => 'pt-BR', 'oversized' => str_repeat('x', 5000)],
        '../invalid' => ['field' => 'ignored'],
    ],
], $context);
$check($mutationMetadata['saved'] === 'pt-BR'
    && $mutationMetadata['extension_input'] === ['example.extension' => ['locale' => 'pt-BR']],
    'mutation metadata receives only bounded namespaced extension input');
$tooManyFields = [];
for ($index = 0; $index < 101; $index++) $tooManyFields['field' . $index] = 'value';
$check(media_extension_input(['media_extension' => ['example.extension' => $tooManyFields]]) === [],
    'direct requests cannot exceed the extension field-count bound');

$pdo->beginTransaction();
$createEvent = resource_lifecycle_capture($pdo, 'media', 'create', [['row' => media_load_live($pdo, 1), 'artifacts' => []]], [
    'actor_id' => 9, 'source' => 'core.contract', 'metadata' => [],
]);
$updateEvent = resource_lifecycle_capture($pdo, 'media', 'update', [['row' => media_load_live($pdo, 1), 'artifacts' => []]], [
    'actor_id' => 9, 'source' => 'core.contract', 'metadata' => [],
]);
$pdo->rollBack();
$check($createEvent['items'][0]['before'] === null && $updateEvent['items'][0]['before']['id'] === 1,
    'media lifecycle provider distinguishes create and update snapshots at runtime');

$pdo->exec('CREATE TABLE media_extension_metadata (media_id INTEGER, locale TEXT)');
$persistMetadata = static function (array $event, ResourceLifecycleDatabase $database): void {
    $locale = $event['metadata']['extension_input']['example.extension']['locale'] ?? null;
    if ($event['resource'] !== 'media' || $event['operation'] !== 'update' || !is_string($locale)) return;
    $stmt = $database->prepare('INSERT INTO media_extension_metadata (media_id, locale) VALUES (?, ?)');
    $stmt->execute([(int)$event['items'][0]['id'], $locale]);
};
add_action('resource_lifecycle_before_commit', $persistMetadata);
$pdo->beginTransaction();
$event = resource_lifecycle_capture($pdo, 'media', 'update', [['row' => media_load_live($pdo, 1), 'artifacts' => []]], [
    'actor_id' => 9, 'source' => 'core.contract', 'metadata' => $mutationMetadata,
]);
$changedItems = $event['items'];
$changedItems[0]['after'] = array_replace($changedItems[0]['before'], ['title' => 'Changed']);
$ready = resource_lifecycle_before_commit($pdo, $event, ['items' => $changedItems, 'result' => ['affected' => 1]]);
$persisted = $pdo->query('SELECT media_id, locale FROM media_extension_metadata')->fetch(PDO::FETCH_ASSOC);
$pdo->rollBack();
$check($ready['items'][0]['before']['title'] === 'Base' && $ready['items'][0]['after']['title'] === 'Changed'
    && $persisted === ['media_id' => 1, 'locale' => 'pt-BR'],
    'before-commit listeners receive immutable before, intended after, and extension input inside the transaction');
remove_action('resource_lifecycle_before_commit', $persistMetadata);
remove_filter('media_mutation_metadata', $mutationFilter);

$preflightCalls = [];
$pdo->exec('CREATE TABLE media_publication_preflight (filename TEXT, locale TEXT)');
$preflightListener = static function (array $candidate, array $ctx, array $input, ResourceLifecycleDatabase $database) use (&$preflightCalls): void {
    $preflightCalls[] = [$candidate['filename'], $ctx['content_locale'], $database->inTransaction()];
    $stmt = $database->prepare('INSERT INTO media_publication_preflight (filename, locale) VALUES (?, ?)');
    $stmt->execute([$candidate['filename'], $input['example.extension']['locale'] ?? '']);
};
add_action('media_create_before_publication', $preflightListener);
$candidate = [
    'schema' => 1, 'filename' => 'candidate.jpg', 'mime' => 'image/jpeg', 'ext' => 'jpg',
    'size' => 10, 'width' => 20, 'height' => 30, 'url' => '/static/img/2026/01/candidate.jpg',
    'visibility' => 'public', 'storage_disk' => 'public', 'access_scope' => 'public', 'is_downloadable' => 1,
];
$pdo->beginTransaction();
media_create_before_publication($pdo, $candidate, $context, ['example.extension' => ['locale' => 'pt-BR']]);
$preflightRow = $pdo->query('SELECT filename, locale FROM media_publication_preflight')->fetch(PDO::FETCH_ASSOC);
$pdo->rollBack();
$check($preflightCalls === [['candidate.jpg', 'pt-BR', true]]
    && $preflightRow === ['filename' => 'candidate.jpg', 'locale' => 'pt-BR'],
    'create pre-publication listeners run transactionally with bounded candidate and extension input');
remove_action('media_create_before_publication', $preflightListener);

$auth = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$auth->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$auth->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, name TEXT, role TEXT, is_site_owner INTEGER, is_deleted INTEGER, is_locked INTEGER)');
$auth->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, authority_rank INTEGER, is_system INTEGER)');
$auth->exec('CREATE TABLE permissions (permission_key TEXT PRIMARY KEY, provider TEXT, resource TEXT, action TEXT, label TEXT, supports_scope INTEGER, is_delegable INTEGER, is_active INTEGER)');
$auth->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, expires_at TEXT)');
$auth->exec('CREATE TABLE role_permissions (role_id INTEGER, permission_key TEXT, scope TEXT)');
$auth->exec('CREATE TABLE authorized_media (id INTEGER PRIMARY KEY, user_id INTEGER)');
$auth->exec("INSERT INTO users VALUES (10,'own@test','own','Own','author',0,0,0),(11,'other@test','other','Other','author',0,0,0),(12,'admin@test','admin','Admin','admin',0,0,0)");
$auth->exec("INSERT INTO roles VALUES (1,'author','Author',10,1),(2,'admin','Admin',100,1)");
$auth->exec("INSERT INTO permissions VALUES ('core.media.read','core','media','read','Read media',1,1,1)");
$auth->exec("INSERT INTO user_roles VALUES (10,1,NULL),(11,1,NULL),(12,2,NULL)");
$auth->exec("INSERT INTO role_permissions VALUES (1,'core.media.read','own'),(2,'core.media.read','any')");
$auth->exec('INSERT INTO authorized_media VALUES (1,10),(2,11)');
$ownRead = authorization_owner_scope_condition($auth, 10, 'core.media.read', 'authorized_media.user_id', 'contract_media_read');
$readStmt = $auth->prepare('SELECT id FROM authorized_media WHERE ' . ($ownRead['sql'] ?? '0=1') . ' ORDER BY id');
$readStmt->execute($ownRead['params'] ?? []);
$check(media_user_can_read($auth, 10, ['id' => 1, 'user_id' => 10])
    && !media_user_can_read($auth, 10, ['id' => 2, 'user_id' => 11])
    && media_user_can_read($auth, 12, ['id' => 2, 'user_id' => 11])
    && array_map('intval', $readStmt->fetchAll(PDO::FETCH_COLUMN)) === [1],
    'media read permission enforces owner context consistently for details, rows, and counts');

$detail = (string)file_get_contents($root . '/dashboard/admin/media/single.php');
$modalDetail = (string)file_get_contents($root . '/dashboard/admin/modal_img/single_modal.php');
$modalIndex = (string)file_get_contents($root . '/dashboard/admin/modal_img/index.php');
$modalList = (string)file_get_contents($root . '/dashboard/admin/modal_img/list_modal.php');
$fullUpload = (string)file_get_contents($root . '/dashboard/admin/media/add.php');
$modalUpload = (string)file_get_contents($root . '/dashboard/admin/modal_img/add_modal.php');
$upload = (string)file_get_contents($root . '/dashboard/admin/upload_image.php');
$save = (string)file_get_contents($root . '/dashboard/admin/media/save.php');
$fullList = (string)file_get_contents($root . '/dashboard/admin/media/list.php');
$schema = (string)file_get_contents($root . '/schema/default.sql');
$migration = (string)file_get_contents($root . '/schema/migrations/023-post-featured-media.sql');
$check(substr_count($detail . $modalDetail, "do_action('media_admin_detail_before_fields'") === 2
    && substr_count($detail . $modalDetail, "do_action('media_admin_detail_after_fields'") === 2,
    'both mandatory detail surfaces expose matching form hooks');
$check(str_contains($detail, 'media_picker_context_from_request($_GET')
    && str_contains($detail, 'name="media_<?= htmlspecialchars($key')
    && str_contains($detail, 'content_default_locale()'),
    'full detail validates request context and preserves it through metadata save');
$check(substr_count($fullUpload . $modalUpload, '<div data-media-extension-fields>') === 2
    && substr_count($fullUpload . $modalUpload, 'appendMediaExtensionFields(fd)') === 4
    && substr_count($fullUpload . $modalUpload, "reserved = new Set(['image','auto_save','csrf_token'") === 2
    && substr_count($fullUpload . $modalUpload, 'media_extension\\[') >= 2,
    'both upload surfaces serialize bounded namespaced controls without Core-key overwrite');
$check(str_contains($modalIndex, 'media_picker_query($mediaContext)') && str_contains($modalList, 'context: <?= json_encode($mediaContext')
    && str_contains($modalList, "broadcast('media:insert', detail)"), 'modal routes preserve context in the insert payload');
$check(!str_contains($modalDetail, "parse_url((string)(\$r['url']")
    && str_contains($modalDetail, 'parse_url($url, PHP_URL_PATH)')
    && str_contains($modalDetail, 'name="url" value="<?= htmlspecialchars($url'),
    'modal detail never republishes a protected row raw URL');
$check(str_contains($schema, '`thumbnail_media_id`') && str_contains($migration, 'ADD COLUMN `thumbnail_media_id`')
    && str_contains($migration, 'fk_posts_thumbnail_media') && str_contains($migration, 'ON DELETE SET NULL')
    && str_contains($migration, 'GROUP BY BINARY `url`') && str_contains($migration, 'HAVING COUNT(*) = 1')
    && str_contains($migration, 'BINARY p.`thumbnail` = m.`exact_url`'),
    'migration adds featured identity and backfills only exact unambiguous live URLs');
$check(str_contains($upload, "resource_lifecycle_capture(\$pdo, 'media', 'create'")
    && str_contains($save, "resource_lifecycle_capture(\$pdo, 'media', 'update'")
    && strpos($save, 'FOR UPDATE') < strpos($save, "resource_lifecycle_capture(\$pdo, 'media', 'update'"),
    'create and lock-aware update participate in generic lifecycle phases');
$initialUploadAuth = strpos($upload, "user_can(\$pdo, \$uid, 'core.media.upload')");
$stageMove = strpos($upload, 'move_uploaded_file');
$preflight = strpos($upload, 'media_create_before_publication');
$publish = strpos($upload, 'asset_lifecycle_rename($stage_path, $target_path)');
$check($initialUploadAuth !== false && $stageMove !== false && $initialUploadAuth < $stageMove
    && $preflight !== false && $publish !== false && $preflight < $publish
    && str_contains($upload, '.media-upload-stage-') && str_contains($upload, '@chmod($stage_path, 0600)'),
    'upload authorization precedes byte movement and transactional preflight precedes atomic publication');
$check(str_contains($detail, 'media_user_can_read') && str_contains($modalDetail, 'media_user_can_read')
    && str_contains($fullList, "authorization_owner_scope_condition(\$pdo, \$uid, 'core.media.read'")
    && str_contains($modalList, "authorization_owner_scope_condition(\$pdo, (int)\$uid, 'core.media.read'"),
    'all media detail and list surfaces use dynamic read authorization');

foreach (['PostController.php', 'SearchController.php', 'CategoryController.php', 'ArchiveController.php', 'AuthorController.php', 'PageController.php'] as $controller) {
    $source = (string)file_get_contents($root . '/app/controllers/' . $controller);
    $check(str_contains($source, 'thumbnail_media_id') || str_contains($source, 'media_normalize_featured_posts'), $controller . ' covers featured media identity');
}
$check(str_contains((string)file_get_contents($root . '/cfg/helpers/cms_content.php'), 'media_normalize_featured_posts')
    && str_contains((string)file_get_contents($root . '/cfg/helpers/widget_helper.php'), 'media_normalize_featured_posts'),
    'shared theme collection and recent-post helpers normalize featured media centrally');
$thumbnailScripts = (string)file_get_contents($root . '/public/static/js/add/thumbnail-handler.js')
    . (string)file_get_contents($root . '/public/static/js/edit/thumbnail.js');
$pickerForms = (string)file_get_contents($root . '/dashboard/admin/posts/add.php')
    . (string)file_get_contents($root . '/dashboard/admin/posts/edit.php')
    . (string)file_get_contents($root . '/dashboard/admin/pages/add.php')
    . (string)file_get_contents($root . '/dashboard/admin/pages/edit.php');
$check(!str_contains($thumbnailScripts, 'document.documentElement.lang')
    && substr_count($thumbnailScripts, "getAttribute('data-content-locale')") === 2
    && substr_count($pickerForms, 'data-content-locale="<?= htmlspecialchars(content_default_locale()') === 4,
    'Core thumbnail pickers use the explicit source content locale rather than dashboard UI locale');

if (!function_exists('__')) { function __(string $text): string { return $text; } }
if (!function_exists('safe_strip_tags')) { function safe_strip_tags(string $html): string { return strip_tags($html); } }
$posts = [[
    'id' => 20, 'title' => 'Visible title', 'slug' => 'visible', 'content' => '<p>Body</p>',
    'display_image' => '/static/img/live.jpg',
    'featured_media' => ['id' => 1, 'url' => '/static/img/live.jpg', 'alt' => '', 'caption' => 'Localized caption'],
]];
$page = 1; $total = 1; $perPage = 10; $base = '/artikel/'; $q = '';
ob_start();
include $root . '/public/views/themes/default/main/list/post.php';
$listHtml = (string)ob_get_clean();
$posts = [[
    'id' => 21, 'title' => 'Suppressed', 'slug' => 'suppressed',
    'content' => '<p><img src="/content-fallback.jpg"></p>', 'display_image' => null,
    'featured_media' => null, 'featured_media_suppressed' => true, 'thumbnail' => null,
]];
$total = 1;
ob_start();
include $root . '/public/views/themes/default/main/list/post.php';
$suppressedHtml = (string)ob_get_clean();
$post = [
    'id' => 22, 'title' => 'Page title', 'content' => '<p>Page body</p>',
    'display_image' => '/static/img/page.jpg',
    'featured_media' => ['id' => 1, 'url' => '/static/img/page.jpg', 'alt' => 'Localized page alt', 'caption' => 'Localized page caption'],
];
ob_start();
include $root . '/public/views/themes/default/main/single/page.php';
$pageHtml = (string)ob_get_clean();
$check(str_contains($listHtml, 'alt=""') && !str_contains($listHtml, 'alt="Visible title"')
    && str_contains($listHtml, 'Localized caption')
    && !str_contains($suppressedHtml, '/content-fallback.jpg')
    && str_contains($pageHtml, 'alt="Localized page alt"') && str_contains($pageHtml, 'Localized page caption'),
    'default list and page rendering preserve decorative alt, localized metadata, and suppression');

$modalScripts = $modalList . $modalIndex . (string)file_get_contents($root . '/public/static/js/add/media-selector.js');
$check(str_contains($modalList, 'data-extensions=') && substr_count($modalScripts, 'extensions') >= 6
    && str_contains($modalList, 'JSON.parse') && str_contains($modalIndex, 'JSON.parse'),
    'modal gallery and normalized insert payloads carry safe media extensions');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " media extension contract check(s) failed.\n");
    exit(1);
}
echo "Media extension contract passed ({$checks} checks).\n";
