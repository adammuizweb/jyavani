<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sourceRoot = $root . '/schema/demo-content';
$assetRoot = $root . '/schema/demo-assets';
$manifestPath = $sourceRoot . '/manifest.json';
$mediaPath = $sourceRoot . '/media.json';
$sqlPath = $root . '/schema/demo.sql';
$generatorPath = $root . '/tools/build-demo-content.php';
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$loadJson = static function (string $path) use ($check): array {
    try {
        $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $check(is_array($decoded), basename($path) . ' decodes to an object');
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $error) {
        $check(false, basename($path) . ' is valid JSON: ' . $error->getMessage());
        return [];
    }
};
$run = static function (array $arguments) use ($root): array {
    $command = array_merge([PHP_BINARY, $root . '/tools/build-demo-content.php'], $arguments);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) return [1, '', 'cannot start generator'];
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string)$stdout, (string)$stderr];
};

$manifest = $loadJson($manifestPath);
$mediaSource = $loadJson($mediaPath);
$check(($manifest['version'] ?? null) === 2, 'manifest contract is version 2');
$check(($manifest['source_locale'] ?? null) === 'id', 'manifest source locale is Indonesian');
$check(($mediaSource['version'] ?? null) === 1, 'media contract is version 1');

$categories = $manifest['categories'] ?? [];
$documents = $manifest['documents'] ?? [];
$media = $mediaSource['media'] ?? [];
$check(is_array($categories) && count($categories) === 4, 'four canonical categories are declared');
$check(is_array($documents) && count($documents) === 25, 'twenty-two articles and three pages are declared');
$check(is_array($media) && count($media) === 59, 'all 59 demo media rows are declared');

$categoryIds = [];
$categorySlugs = [];
foreach ($categories as $category) {
    $id = $category['id'] ?? null;
    $slug = $category['slug'] ?? null;
    $check(is_int($id) && !isset($categoryIds[$id]), 'category ID is a unique integer: ' . json_encode($id));
    $check(is_string($slug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1 && !isset($categorySlugs[$slug]), 'category slug is unique and safe: ' . json_encode($slug));
    if (is_int($id)) $categoryIds[$id] = true;
    if (is_string($slug)) $categorySlugs[$slug] = true;
}

$mediaIds = [];
$mediaUrls = [];
$mediaMetadataMatchesAssets = true;
foreach ($media as $item) {
    $id = $item['id'] ?? null;
    $url = $item['url'] ?? null;
    $check(is_int($id) && !isset($mediaIds[$id]), 'media ID is unique: ' . json_encode($id));
    $check(is_string($url) && preg_match('#^/static/img/[a-zA-Z0-9._/-]+$#', $url) === 1 && !isset($mediaUrls[$url]), 'media URL is unique and safe: ' . json_encode($url));
    if (is_int($id)) $mediaIds[$id] = true;
    if (is_string($url)) $mediaUrls[$url] = true;
    $assetPath = is_string($url) ? $assetRoot . $url : '';
    $image = $assetPath !== '' && is_file($assetPath) ? @getimagesize($assetPath) : false;
    $extension = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
    if (!is_array($image)
        || ($item['filename'] ?? null) !== basename($assetPath)
        || ($item['mime'] ?? null) !== ($image['mime'] ?? null)
        || ($item['ext'] ?? null) !== $extension
        || ($item['size'] ?? null) !== filesize($assetPath)
        || ($item['width'] ?? null) !== ($image[0] ?? null)
        || ($item['height'] ?? null) !== ($image[1] ?? null)) {
        $mediaMetadataMatchesAssets = false;
    }
}
$check($mediaMetadataMatchesAssets, 'demo media metadata matches the shipped asset bytes');
$expectedMediaIds = [64,65,66,67,68,70,71,72,73,75,76,77,78,79,80,81,82,84,85,86,88,89,90,92,93,94,96,97,98,100,101,102,104,105,106,108,109,110,111,112,113,114,115,117,118,119,120,121,122,123,124,125,126,127,128,129,130,131,132];
$check(array_keys($mediaIds) === $expectedMediaIds, 'demo media identity set includes Core Mail and Site Health assets');

$documentIds = [];
$documentSlugs = [];
$relationshipPairs = [];
$articleCount = 0;
$pageCount = 0;
foreach ($documents as $document) {
    $missing = array_diff(['id','type','title','slug','content_file','thumbnail','status','created_at','updated_at','categories'], array_keys($document));
    $check($missing === [], 'document declares every required field: ' . json_encode($document['id'] ?? null));
    $id = $document['id'] ?? null;
    $slug = $document['slug'] ?? null;
    $type = $document['type'] ?? null;
    $check(is_int($id) && !isset($documentIds[$id]), 'document ID is unique: ' . json_encode($id));
    $check(is_string($slug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1 && !isset($documentSlugs[$slug]), 'document slug is unique and safe: ' . json_encode($slug));
    if (is_int($id)) $documentIds[$id] = true;
    if (is_string($slug)) $documentSlugs[$slug] = true;
    if ($type === 'article') $articleCount++;
    if ($type === 'page') $pageCount++;
    $check(in_array($type, ['article', 'page'], true), 'document type is routable: ' . json_encode($id));
    $relative = $document['content_file'] ?? '';
    $expectedPrefix = $type === 'article' ? 'articles/' : 'pages/';
    $realSource = realpath($sourceRoot);
    $realContent = is_string($relative) ? realpath($sourceRoot . '/' . $relative) : false;
    $check(is_string($relative) && str_starts_with($relative, $expectedPrefix) && str_ends_with($relative, '.html'), 'content path has the expected type directory: ' . json_encode($id));
    $check($realSource !== false && $realContent !== false && str_starts_with($realContent, $realSource . DIRECTORY_SEPARATOR), 'content file is contained and exists: ' . json_encode($id));
    $content = $realContent !== false ? (string)file_get_contents($realContent) : '';
    $check($content !== '' && mb_check_encoding($content, 'UTF-8'), 'content body is non-empty UTF-8: ' . json_encode($id));
    preg_match_all('#(?:src|href)=["\'](/static/img/[^"\']+)["\']#', $content, $matches);
    foreach ($matches[1] as $asset) $check(isset($mediaUrls[$asset]), 'body asset resolves to historical media: ' . $asset);
    $thumbnail = $document['thumbnail'] ?? null;
    $check($thumbnail === null || isset($mediaUrls[$thumbnail]), 'thumbnail resolves to historical media: ' . json_encode($id));
    $categoriesForDocument = $document['categories'] ?? null;
    $check(is_array($categoriesForDocument) && array_is_list($categoriesForDocument), 'document categories form a list: ' . json_encode($id));
    if ($type === 'page') $check($categoriesForDocument === [], 'page has no category relationship: ' . json_encode($id));
    foreach (is_array($categoriesForDocument) ? $categoriesForDocument : [] as $categoryId) {
        $check(is_int($categoryId) && isset($categoryIds[$categoryId]), 'document category reference exists: ' . json_encode([$id, $categoryId]));
        $pair = $id . ':' . $categoryId;
        $check(!isset($relationshipPairs[$pair]), 'document category relationship is unique: ' . $pair);
        $relationshipPairs[$pair] = true;
    }
}
$check($articleCount === 22 && $pageCount === 3, 'inventory contains 22 articles and 3 pages');
$expectedDocumentIds = array_merge(range(272, 296));
$check(array_keys($documentIds) === $expectedDocumentIds, 'source document IDs are exactly 272 through 296');
$check(($manifest['documents'][19]['id'] ?? null) === 291 && ($manifest['documents'][24]['id'] ?? null) === 296, 'new documentation IDs 291-296 are present in order');
$check(count($relationshipPairs) === 35, 'all 35 article/category relationships are represented');
$presets = $manifest['presets'] ?? [];
$check(is_array($presets) && array_is_list($presets) && count($presets) === 2, 'demo declares sidebar and homepage presets');
$presetBySlug = [];
foreach (is_array($presets) ? $presets : [] as $preset) {
    if (is_array($preset) && is_string($preset['slug'] ?? null)) $presetBySlug[$preset['slug']] = $preset;
}
$randomPreset = $presetBySlug['demo_random_posts'] ?? [];
$homePreset = $presetBySlug['demo_home_posts'] ?? [];
$check(($randomPreset['id'] ?? null) === 300 && ($randomPreset['status'] ?? null) === 'published'
    && ($randomPreset['metadata']['layout'] ?? null) === 'mini'
    && ($randomPreset['metadata']['excerpt_len'] ?? null) === '90'
    && !array_key_exists('author', $randomPreset['metadata'] ?? [])
    && ($randomPreset['theme_section']['name'] ?? null) === 'home.random-posts'
    && ($randomPreset['theme_section']['layout_override'] ?? null) === 'grid'
    && ($randomPreset['theme_section']['limit'] ?? null) === 4
    && ($randomPreset['theme_section']['max_items'] ?? null) === 20
    && ($randomPreset['theme_section']['pagination'] ?? null) === 1, 'random preset remains sidebar-safe and declares its five-page homepage Card Grid composition');
$check(($homePreset['id'] ?? null) === 301 && ($homePreset['status'] ?? null) === 'published'
    && ($homePreset['metadata']['category'] ?? null) === 'pengembangan'
    && ($homePreset['metadata']['order_by'] ?? null) === 'created_at'
    && ($homePreset['metadata']['layout'] ?? null) === 'cards'
    && ($homePreset['theme_section']['name'] ?? null) === 'home.preset-posts', 'homepage preset is deterministic and declares its reusable Theme Section');

$themeRoot = $root . '/public/views/themes/default';
$themeRegistration = (string)file_get_contents($themeRoot . '/theme.php');
$homepageSource = (string)file_get_contents($themeRoot . '/main/homepage.php');
$sectionName = (string)($homePreset['theme_section']['name'] ?? '');
$sectionPath = $themeRoot . '/partials/shortcodes/section/' . $sectionName . '.php';
$sectionSource = is_file($sectionPath) ? (string)file_get_contents($sectionPath) : '';
$check($sectionName !== '' && is_file($sectionPath)
    && str_contains($themeRegistration, "'" . $sectionName . "'")
    && str_contains($themeRegistration, "'preset' => 'demo_home_posts'")
    && str_contains($themeRegistration, "'title' => '" . ($homePreset['theme_section']['title'] ?? '') . "'")
    && str_contains($homepageSource, "render_theme_section('" . $sectionName . "'")
    && str_contains($sectionSource, 'render_shortcode_preset($pdo, $preset'), 'manifest preset, default Theme Section renderer, and homepage composition share one exact identity');
$randomSectionName = (string)($randomPreset['theme_section']['name'] ?? '');
$randomSectionPath = $themeRoot . '/partials/shortcodes/section/' . $randomSectionName . '.php';
$randomSectionSource = is_file($randomSectionPath) ? (string)file_get_contents($randomSectionPath) : '';
$check($randomSectionName !== '' && is_file($randomSectionPath)
    && str_contains($themeRegistration, "'preset' => 'demo_random_posts'")
    && str_contains($homepageSource, "render_theme_section('" . $randomSectionName . "'")
    && str_contains($randomSectionSource, "'layout' => 'grid'")
    && str_contains($randomSectionSource, "'limit' => 4")
    && str_contains($randomSectionSource, "'max_items' => 20")
    && str_contains($randomSectionSource, "'pagination' => '1'")
    && str_contains($randomSectionSource, 'name="random_posts"'), 'random preset, Card Grid override, homepage composition, and shuffle navigation share one section contract');
$guideSource = (string)file_get_contents($sourceRoot . '/articles/281-widget-shortcode.html');
$check(str_contains($guideSource, 'demo_home_posts') && str_contains($guideSource, 'home.preset-posts')
    && str_contains($guideSource, 'home.random-posts') && str_contains($guideSource, 'Acak Lagi')
    && str_contains($guideSource, 'Build Section'), 'demo guide explains deterministic and random preset-to-section composition');

$generatorSource = (string)file_get_contents($generatorPath);
$sqlSource = (string)file_get_contents($sqlPath);
$check(!preg_match('/\b(?:PDO|mysqli|DB_HOST|DB_NAME)\b/', $generatorSource), 'generator has no source database dependency');
$check(!str_contains($generatorSource, 'jyavani_local') && !str_contains($sqlSource, 'Source DB:'), 'generated artifacts contain no hard-coded source database name');

[$firstStatus, $firstOut, $firstError] = $run([]);
$firstBytes = (string)file_get_contents($sqlPath);
[$secondStatus, $secondOut, $secondError] = $run([]);
$secondBytes = (string)file_get_contents($sqlPath);
[$checkStatus, $checkOut, $checkError] = $run(['--check']);
$check($firstStatus === 0, 'first generator run succeeds: ' . trim($firstError ?: $firstOut));
$check($secondStatus === 0, 'second generator run succeeds: ' . trim($secondError ?: $secondOut));
$check($firstBytes === $secondBytes, 'normal generator output is byte-for-byte deterministic');
$check($checkStatus === 0 && str_contains($checkOut, 'current'), '--check accepts the generated SQL: ' . trim($checkError ?: $checkOut));
$check(str_contains($secondBytes, '-- Generated: 2026-08-19'), 'generated header has the canonical date');
$check(str_contains($secondBytes, 'manifest.json (version 2)')
    && str_contains($secondBytes, 'Requires Pondasi to set @jyavani_demo_owner_id')
    && substr_count($secondBytes, '@jyavani_demo_owner_id') > 100, 'generated SQL binds demo ownership to the actual installer Site Owner');
$check(str_contains($secondBytes, 'Tables written: categories, posts, media, post_categories, sidebar_zone_items.'), 'generated header describes actual tables');
$check(substr_count($secondBytes, "'article'") >= 22
    && str_contains($secondBytes, "(300, 'Demo Random Posts Preset'")
    && str_contains($secondBytes, "(301, 'Demo Homepage Posts Preset'"), 'generated SQL includes article inventory and both published presets');
$check(!str_contains($secondBytes, 'SET FOREIGN_KEY_CHECKS=0')
    && str_contains($secondBytes, 'INSERT INTO `categories`')
    && str_contains($secondBytes, 'ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)')
    && !str_contains($secondBytes, 'REPLACE INTO `categories`'), 'demo import keeps foreign-key checks active and updates seeded categories without REPLACE delete semantics');
$check(substr_count($secondBytes, '`updated_by`') === 2, 'generated post and preset inserts include updater attribution');

if ($failures !== []) {
    fwrite(STDERR, 'Demo content contract failed: ' . count($failures) . " assertion(s).\n");
    exit(1);
}

echo "RESULT: ALL PASS\n";
