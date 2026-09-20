<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/lang_helpers.php';
require_once $root . '/cfg/helpers/collection_helpers.php';

function get_post_permalink(array $post): string
{
    return '/content/' . rawurlencode((string)($post['slug'] ?? '')) . '/';
}

require_once $root . '/cfg/helpers/theme_zones.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$types = theme_zone_widget_types();
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$check(isset($types['tz_articles'], $types['tz_theme_content']), 'Core registers Article List and Theme Content List gadgets');
$check(($types['tz_articles']['sidebar_addable'] ?? true) === false && ($types['tz_theme_content']['sidebar_addable'] ?? true) === false,
    'content-list gadgets stay out of Sidebar until it provides their configuration controls');
$translationKeys = ['Article List', 'Theme Content List', 'All authors', 'All categories', 'Author filter', 'Category filter', 'Maximum items', 'Theme Content to show', 'No published Theme Content is available.', 'Invalid gadget type.'];
$check(array_reduce($translationKeys, static fn(bool $valid, string $key): bool => $valid && substr_count($translations, "'" . $key . "'") >= 2, true),
    'new gadget and editor labels have Indonesian and German translation seeds');
$check(($types['tz_post_author']['addable'] ?? true) === false && ($types['tz_post_meta']['addable'] ?? true) === false,
    'legacy single-post gadgets remain renderable but are not addable');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, slug TEXT, type TEXT, status TEXT, is_deleted INTEGER, created_by INTEGER, created_at TEXT)');
$pdo->exec('CREATE TABLE post_categories (post_id INTEGER, category_id INTEGER)');
$pdo->exec("INSERT INTO posts VALUES
    (1, 'First article', 'first', 'article', 'published', 0, 7, '2026-01-01 00:00:00'),
    (2, 'Second article', 'second', 'article', 'published', 0, 7, '2026-02-01 00:00:00'),
    (3, 'Other author', 'other', 'article', 'published', 0, 8, '2026-03-01 00:00:00'),
    (10, 'Theme One', 'theme-one', 'theme', 'published', 0, 7, '2026-01-01 00:00:00'),
    (11, 'Theme Two', 'theme-two', 'theme', 'published', 0, 7, '2026-02-01 00:00:00'),
    (12, 'Theme Draft', 'theme-draft', 'theme', 'draft', 0, 7, '2026-03-01 00:00:00')");
$pdo->exec('INSERT INTO post_categories VALUES (1, 4), (2, 5), (3, 4)');

$contexts = [];
add_filter('collection_query_clauses', static function (array $clauses, array $context) use (&$contexts): array {
    $contexts[] = ['query', $context['scope'] ?? '', $context['gadget_type'] ?? ''];
    if (($context['scope'] ?? '') === 'article_list') {
        $clauses['where'][] = 'p.id <> :tz_contract_hidden';
        $clauses['params'][':tz_contract_hidden'] = 99;
    }
    return $clauses;
}, 10, 2);
add_filter('collection_rows', static function (array $rows, array $context) use (&$contexts): array {
    $contexts[] = ['rows', $context['scope'] ?? '', $context['gadget_type'] ?? ''];
    foreach ($rows as &$row) $row['title'] = 'Localized ' . $row['title'];
    unset($row);
    return $rows;
}, 10, 2);
add_filter('collection_url', static function (string $url, string $type, array $context): string {
    return '/localized/' . $type . '/' . rawurlencode((string)($context['item']['slug'] ?? '')) . '/';
}, 10, 3);

$articleHtml = _theme_zone_built_in_widget_html($pdo, 'tz_articles', [
    'title' => 'News',
    'author_id' => 7,
    'category_id' => 4,
    'limit' => 10,
    'list_class' => 'footer-articles unsafe<script>',
]);
$check(str_contains($articleHtml, 'Localized First article') && !str_contains($articleHtml, 'Second article') && !str_contains($articleHtml, 'Other author'),
    'Article List applies author and category filters before rendering');
$check(str_contains($articleHtml, '/localized/article/first/') && !str_contains($articleHtml, '<script>'),
    'Article List uses filtered canonical URLs and sanitizes its CSS class');
$check(in_array(['query', 'article_list', 'tz_articles'], $contexts, true)
    && in_array(['rows', 'article_list', 'tz_articles'], $contexts, true),
    'Article List exposes both multilingual collection phases');

$themeHtml = _theme_zone_built_in_widget_html($pdo, 'tz_theme_content', [
    'title' => 'Explore',
    'items' => [11, 12, 10, 11, -1],
    'list_class' => 'footer-themes',
]);
$check(str_contains($themeHtml, 'Localized Theme One') && str_contains($themeHtml, 'Localized Theme Two')
    && !str_contains($themeHtml, 'Theme Draft') && strpos($themeHtml, 'Theme Two') < strpos($themeHtml, 'Theme One'),
    'Theme Content List keeps configured order and excludes unpublished selections');
$check(str_contains($themeHtml, '/localized/theme/theme-two/')
    && in_array(['query', 'theme_content_list', 'tz_theme_content'], $contexts, true)
    && in_array(['rows', 'theme_content_list', 'tz_theme_content'], $contexts, true),
    'Theme Content List uses canonical URL and multilingual collection contracts');
$check(_theme_zone_built_in_widget_html($pdo, 'tz_theme_content', ['items' => []]) === '',
    'Theme Content List with no selection renders nothing');
$preparedDefault = theme_zone_prepare_default_config($pdo, 'tz_theme_content', ['item_slugs' => ['theme-two', 'missing', 'theme-one']]);
$check(($preparedDefault['items'] ?? null) === [11, 10] && !isset($preparedDefault['item_slugs']),
    'portable Theme Content defaults resolve source slugs to stored IDs');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Theme Zone content-list contract check(s) failed.\n");
    exit(1);
}

echo "Theme Zone content-list contract passed.\n";
