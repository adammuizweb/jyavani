<?php
declare(strict_types=1);

const DEMO_MANIFEST_VERSION = 1;

function demo_fail(string $message): never
{
    fwrite(STDERR, "Demo content error: {$message}\n");
    exit(1);
}

function demo_json(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) demo_fail('Cannot read ' . $path);
    try {
        $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        demo_fail(basename($path) . ': ' . $error->getMessage());
    }
    if (!is_array($value)) demo_fail(basename($path) . ' must contain a JSON object.');
    return $value;
}

function demo_string(mixed $value, string $field, bool $allowEmpty = false): string
{
    if (!is_string($value) || (!$allowEmpty && trim($value) === '')) demo_fail($field . ' must be a non-empty string.');
    if (str_contains($value, "\0")) demo_fail($field . ' contains a NUL byte.');
    return $value;
}

function demo_int(mixed $value, string $field, int $minimum = 1): int
{
    if (!is_int($value) || $value < $minimum) demo_fail($field . ' must be an integer >= ' . $minimum . '.');
    return $value;
}

function demo_sql(mixed $value): string
{
    if ($value === null) return 'NULL';
    if (is_int($value)) return (string)$value;
    if (is_bool($value)) return $value ? '1' : '0';
    if (!is_string($value)) demo_fail('Unsupported SQL value type.');
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
}

function demo_row(array $values): string
{
    return '(' . implode(', ', array_map('demo_sql', $values)) . ')';
}

function demo_relative_file(string $root, string $relative, string $field): string
{
    if ($relative === '' || str_contains($relative, '\\') || str_starts_with($relative, '/')
        || preg_match('#(?:^|/)\.\.?(/|$)#', $relative)) {
        demo_fail($field . ' is not a safe relative path.');
    }
    $base = realpath($root);
    $path = realpath($root . '/' . $relative);
    if ($base === false || $path === false || !is_file($path)
        || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
        demo_fail($field . ' does not resolve to a contained file: ' . $relative);
    }
    return $path;
}

function demo_asset(string $path, string $field): string
{
    if (!preg_match('#\A/static/img/[a-zA-Z0-9._/-]+\z#', $path)
        || str_contains($path, '//') || preg_match('#(?:^|/)\.\.?(/|$)#', $path)) {
        demo_fail($field . ' is not a safe demo asset path: ' . $path);
    }
    return $path;
}

function demo_datetime(mixed $value, string $field): string
{
    $value = demo_string($value, $field);
    if (!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value)) demo_fail($field . ' is not a SQL datetime.');
    return $value;
}

$root = dirname(__DIR__);
$sourceRoot = $root . '/schema/demo-content';
$manifest = demo_json($sourceRoot . '/manifest.json');
$mediaSource = demo_json($sourceRoot . '/media.json');

if (($manifest['version'] ?? null) !== DEMO_MANIFEST_VERSION) demo_fail('manifest version must be 1.');
if (($manifest['source_locale'] ?? null) !== 'id') demo_fail('source_locale must be id.');
if (($mediaSource['version'] ?? null) !== DEMO_MANIFEST_VERSION) demo_fail('media version must be 1.');

$categories = $manifest['categories'] ?? null;
$documents = $manifest['documents'] ?? null;
$media = $mediaSource['media'] ?? null;
if (!is_array($categories) || !array_is_list($categories)) demo_fail('categories must be a list.');
if (!is_array($documents) || !array_is_list($documents)) demo_fail('documents must be a list.');
if (!is_array($media) || !array_is_list($media)) demo_fail('media must be a list.');

$categoryIds = [];
$categorySlugs = [];
foreach ($categories as $index => $category) {
    if (!is_array($category)) demo_fail("categories[{$index}] must be an object.");
    $id = demo_int($category['id'] ?? null, "categories[{$index}].id");
    $slug = demo_string($category['slug'] ?? null, "categories[{$index}].slug");
    if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)) demo_fail('Invalid category slug: ' . $slug);
    if (isset($categoryIds[$id])) demo_fail('Duplicate category ID: ' . $id);
    if (isset($categorySlugs[$slug])) demo_fail('Duplicate category slug: ' . $slug);
    $categoryIds[$id] = true;
    $categorySlugs[$slug] = true;
    demo_string($category['name'] ?? null, "categories[{$index}].name");
    demo_string($category['description'] ?? null, "categories[{$index}].description", true);
    demo_datetime($category['created_at'] ?? null, "categories[{$index}].created_at");
    demo_datetime($category['updated_at'] ?? null, "categories[{$index}].updated_at");
}

$mediaIds = [];
$mediaUrls = [];
$mediaColumns = ['id','url','filename','mime','ext','size','width','height','title','alt','caption','credit','visibility','storage_disk','storage_path','access_scope','is_downloadable','user_id','created_at','updated_at','target_url','target_attribute'];
foreach ($media as $index => $item) {
    if (!is_array($item)) demo_fail("media[{$index}] must be an object.");
    foreach ($mediaColumns as $column) if (!array_key_exists($column, $item)) demo_fail("media[{$index}].{$column} is required.");
    $id = demo_int($item['id'], "media[{$index}].id");
    $url = demo_asset(demo_string($item['url'], "media[{$index}].url"), "media[{$index}].url");
    if (isset($mediaIds[$id])) demo_fail('Duplicate media ID: ' . $id);
    if (isset($mediaUrls[$url])) demo_fail('Duplicate media URL: ' . $url);
    $mediaIds[$id] = true;
    $mediaUrls[$url] = true;
    $filename = demo_string($item['filename'], "media[{$index}].filename");
    $ext = demo_string($item['ext'], "media[{$index}].ext");
    if (basename($filename) !== $filename || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== strtolower($ext)) demo_fail('Invalid media filename identity for ID ' . $id);
    if (!preg_match('#\Aimage/[a-z0-9.+-]+\z#', demo_string($item['mime'], "media[{$index}].mime"))) demo_fail('Invalid media MIME for ID ' . $id);
    if (!is_int($item['size']) || $item['size'] < 0) demo_fail('Invalid media size for ID ' . $id);
    foreach (['width','height'] as $dimension) if (!is_int($item[$dimension]) || $item[$dimension] <= 0) demo_fail('Invalid media dimension for ID ' . $id);
    if (!in_array($item['visibility'], ['public','private'], true) || !in_array($item['storage_disk'], ['public','private'], true)
        || !in_array($item['access_scope'], ['public','editorial','admin'], true)) demo_fail('Invalid media access identity for ID ' . $id);
    if ($item['storage_path'] !== null) {
        $storage = demo_string($item['storage_path'], "media[{$index}].storage_path");
        if (str_starts_with($storage, '/') || str_contains($storage, '\\') || preg_match('#(?:^|/)\.\.?(/|$)#', $storage)) demo_fail('Unsafe media storage_path for ID ' . $id);
    }
    demo_datetime($item['created_at'], "media[{$index}].created_at");
    if ($item['updated_at'] !== null) demo_datetime($item['updated_at'], "media[{$index}].updated_at");
}

$documentIds = [];
$documentSlugs = [];
$documentRows = [];
$relationships = [];
foreach ($documents as $index => $document) {
    if (!is_array($document)) demo_fail("documents[{$index}] must be an object.");
    foreach (['id','type','title','slug','content_file','thumbnail','status','created_at','updated_at','categories'] as $field) {
        if (!array_key_exists($field, $document)) demo_fail("documents[{$index}].{$field} is required.");
    }
    $id = demo_int($document['id'], "documents[{$index}].id");
    $type = demo_string($document['type'], "documents[{$index}].type");
    $slug = demo_string($document['slug'], "documents[{$index}].slug");
    if (!in_array($type, ['article','page'], true)) demo_fail('Invalid document type for ID ' . $id);
    if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)) demo_fail('Invalid document slug: ' . $slug);
    if (isset($documentIds[$id])) demo_fail('Duplicate document ID: ' . $id);
    if (isset($documentSlugs[$slug])) demo_fail('Duplicate document slug: ' . $slug);
    $documentIds[$id] = true;
    $documentSlugs[$slug] = true;
    $contentFile = demo_string($document['content_file'], "documents[{$index}].content_file");
    $expectedPrefix = $type === 'article' ? 'articles/' : 'pages/';
    if (!str_starts_with($contentFile, $expectedPrefix) || !str_ends_with($contentFile, '.html')) demo_fail('Unexpected content_file for ID ' . $id);
    $content = file_get_contents(demo_relative_file($sourceRoot, $contentFile, "documents[{$index}].content_file"));
    if ($content === false || trim($content) === '') demo_fail('Empty content for ID ' . $id);
    if (!mb_check_encoding($content, 'UTF-8')) demo_fail('Content is not UTF-8 for ID ' . $id);
    preg_match_all('#(?:src|href)=["\'](/static/img/[^"\']+)["\']#', $content, $matches);
    foreach ($matches[1] as $asset) {
        demo_asset($asset, 'content asset for ID ' . $id);
        if (!isset($mediaUrls[$asset])) demo_fail('Content asset is absent from media.json for ID ' . $id . ': ' . $asset);
    }
    $thumbnail = $document['thumbnail'];
    if ($thumbnail !== null) {
        $thumbnail = demo_asset(demo_string($thumbnail, "documents[{$index}].thumbnail"), "documents[{$index}].thumbnail");
        if (!isset($mediaUrls[$thumbnail])) demo_fail('Thumbnail is absent from media.json for ID ' . $id);
    }
    if ($document['status'] !== 'published') demo_fail('Demo document status must be published for ID ' . $id);
    if (!is_array($document['categories']) || !array_is_list($document['categories'])) demo_fail('Categories must be a list for ID ' . $id);
    if ($type === 'page' && $document['categories'] !== []) demo_fail('Pages cannot have categories: ' . $id);
    $seenCategories = [];
    foreach ($document['categories'] as $categoryId) {
        if (!is_int($categoryId) || !isset($categoryIds[$categoryId])) demo_fail('Unknown category reference on document ' . $id);
        if (isset($seenCategories[$categoryId])) demo_fail('Duplicate category reference on document ' . $id);
        $seenCategories[$categoryId] = true;
        $relationships[] = [$id, $categoryId, 1, demo_datetime($document['relationship_at'] ?? $document['updated_at'], "documents[{$index}].relationship_at")];
    }
    $meta = $document['meta'] ?? null;
    if ($meta !== null) {
        if (!is_array($meta)) demo_fail('Document meta must be an object for ID ' . $id);
        $meta = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    $documentRows[] = [$id, demo_string($document['title'], "documents[{$index}].title"), $slug, rtrim($content) . "\n", $type, $meta, null, $thumbnail, 'published', 1, demo_datetime($document['created_at'], "documents[{$index}].created_at"), demo_datetime($document['updated_at'], "documents[{$index}].updated_at"), 0, null, 0];
}

$preset = $manifest['preset'] ?? null;
if (!is_array($preset)) demo_fail('preset must be an object.');
$presetId = demo_int($preset['id'] ?? null, 'preset.id');
$presetSlug = demo_string($preset['slug'] ?? null, 'preset.slug');
if (isset($documentIds[$presetId]) || isset($documentSlugs[$presetSlug])) demo_fail('Preset identity collides with a document.');
if (!preg_match('/\A[a-z0-9_]+\z/', $presetSlug)) demo_fail('preset.slug is invalid.');
if (($preset['status'] ?? null) !== 'published') demo_fail('preset.status must be published.');
demo_string($preset['title'] ?? null, 'preset.title');
demo_datetime($preset['created_at'] ?? null, 'preset.created_at');
demo_datetime($preset['updated_at'] ?? null, 'preset.updated_at');
$presetMeta = $preset['metadata'] ?? null;
if (!is_array($presetMeta)) demo_fail('preset.metadata must be an object.');
$presetMetaJson = json_encode($presetMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

usort($categories, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
usort($documentRows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
usort($media, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
usort($relationships, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

$lines = [
    '-- Jyavani CMS Demo Content',
    '-- Generated: 2026-08-19',
    '-- Canonical source: schema/demo-content/manifest.json (version 1)',
    '-- Regenerate: php tools/build-demo-content.php',
    '--',
    '-- Imported on request by Pondasi after the Core schema and initial Site Owner exist.',
    '-- Tables written: categories, posts, media, post_categories, sidebar_zone_items.',
    '',
    'SET FOREIGN_KEY_CHECKS=0;',
    'SET NAMES utf8mb4;',
    "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';",
    '',
    'REPLACE INTO `categories` (`id`, `name`, `slug`, `description`, `parent_id`, `meta`, `created_by`, `created_at`, `updated_at`, `is_deleted`, `deleted_at`) VALUES',
];
$rows = [];
foreach ($categories as $category) $rows[] = demo_row([$category['id'],$category['name'],$category['slug'],$category['description'],null,null,1,$category['created_at'],$category['updated_at'],0,null]);
$lines[] = implode(",\n", $rows) . ';';
$lines[] = '';
$lines[] = 'INSERT INTO `posts` (`id`, `title`, `slug`, `content`, `type`, `meta`, `youtube`, `thumbnail`, `status`, `created_by`, `created_at`, `updated_at`, `sort_order`, `deleted_at`, `is_deleted`) VALUES';
$lines[] = implode(",\n", array_map('demo_row', $documentRows)) . ';';
$lines[] = '';
$lines[] = 'INSERT INTO `media` (`' . implode('`, `', $mediaColumns) . '`) VALUES';
$rows = [];
foreach ($media as $item) $rows[] = demo_row(array_map(static fn(string $column): mixed => $item[$column], $mediaColumns));
$lines[] = implode(",\n", $rows) . ';';
$lines[] = '';
$lines[] = 'INSERT INTO `post_categories` (`post_id`, `category_id`, `assigned_by`, `assigned_at`) VALUES';
$lines[] = implode(",\n", array_map('demo_row', $relationships)) . ';';
$lines[] = '';
$lines[] = '-- Demo shortcode preset: random demo posts by the initial Site Owner.';
$lines[] = 'INSERT INTO `posts` (`id`, `title`, `slug`, `content`, `type`, `meta`, `youtube`, `thumbnail`, `status`, `created_by`, `created_at`, `updated_at`, `sort_order`, `deleted_at`, `is_deleted`) VALUES';
$lines[] = demo_row([$presetId,$preset['title'],$presetSlug,'','sc_preset',$presetMetaJson,null,null,$preset['status'],1,$preset['created_at'],$preset['updated_at'],0,null,0]) . ';';
$lines[] = '';
$lines[] = '-- Demo sidebar widget using the preset above.';
$widget = $preset['sidebar_widget'] ?? null;
if (!is_array($widget)) demo_fail('preset.sidebar_widget must be an object.');
demo_int($widget['zone_id'] ?? null, 'preset.sidebar_widget.zone_id');
demo_string($widget['type'] ?? null, 'preset.sidebar_widget.type');
demo_string($widget['title'] ?? null, 'preset.sidebar_widget.title');
if (!is_int($widget['ordering'] ?? null) || $widget['ordering'] < 0) demo_fail('preset.sidebar_widget.ordering must be a non-negative integer.');
if (!in_array($widget['active'] ?? null, [0, 1], true)) demo_fail('preset.sidebar_widget.active must be 0 or 1.');
if (!is_array($widget['config'] ?? null)) demo_fail('preset.sidebar_widget.config must be an object.');
$widgetConfig = json_encode($widget['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$lines[] = 'INSERT IGNORE INTO `sidebar_zone_items` (`zone_id`, `type`, `title`, `config`, `ordering`, `active`) VALUES';
$lines[] = demo_row([$widget['zone_id'],$widget['type'],$widget['title'],$widgetConfig,$widget['ordering'],$widget['active']]) . ';';
$lines[] = '';
$lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
$generated = implode("\n", $lines) . "\n";

$output = $root . '/schema/demo.sql';
$checkOnly = in_array('--check', array_slice($argv, 1), true);
foreach (array_slice($argv, 1) as $argument) if ($argument !== '--check') demo_fail('Unknown argument: ' . $argument);
if ($checkOnly) {
    $current = @file_get_contents($output);
    if ($current !== $generated) demo_fail('schema/demo.sql is stale. Run php tools/build-demo-content.php.');
    fwrite(STDOUT, "Demo content is current.\n");
    exit(0);
}

$temporary = tempnam(dirname($output), '.demo.sql.');
if ($temporary === false) demo_fail('Cannot create a temporary output file.');
$outputMode = is_file($output) ? (fileperms($output) & 0777) : 0644;
if (file_put_contents($temporary, $generated, LOCK_EX) !== strlen($generated)
    || !chmod($temporary, $outputMode)
    || !rename($temporary, $output)) {
    @unlink($temporary);
    demo_fail('Cannot atomically write schema/demo.sql.');
}
fwrite(STDOUT, 'Generated schema/demo.sql (' . count($documentRows) . ' documents, ' . count($media) . " media).\n");
