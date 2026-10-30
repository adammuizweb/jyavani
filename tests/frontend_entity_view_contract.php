<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';

final class FrontendEntityViewContractPdo extends PDO
{
    public function __construct() {}
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$pdo = new FrontendEntityViewContractPdo();
$events = [];
$listener = static function (array $entity, array $context, PDO $receivedPdo) use (&$events): void {
    $events[] = [$entity, $context, $receivedPdo];
};
add_action('frontend_entity_viewed', $listener);

$_SERVER['REQUEST_METHOD'] = 'head';
frontend_entity_viewed_notify($pdo, [
    'id' => 42,
    'type' => 'article',
    'status' => 'published',
    'is_deleted' => 0,
], 'post', 'article', 'single.post', '/articles/public/', 1);

$check(count($events) === 1
    && $events[0][0] === ['kind' => 'post', 'type' => 'article', 'id' => 42]
    && $events[0][1] === [
        'schema' => 1,
        'route' => 'single.post',
        'status' => 'published',
        'is_public' => true,
        'canonical_url' => '/articles/public/',
        'page' => 1,
        'request_method' => 'HEAD',
    ]
    && $events[0][2] === $pdo,
    'published entities emit the exact schema-1 payload and active PDO once');

frontend_entity_viewed_notify($pdo, ['id' => 43, 'type' => 'page', 'status' => 'draft'], 'post', 'page', 'single.page', '/draft/');
frontend_entity_viewed_notify($pdo, ['id' => 44, 'type' => 'theme', 'status' => 'private'], 'post', 'theme', 'single.theme', '/private/');
frontend_entity_viewed_notify($pdo, ['id' => 45, 'type' => 'article', 'status' => 'published', 'is_deleted' => 1], 'post', 'article', 'single.post', '/deleted/');
frontend_entity_viewed_notify($pdo, ['id' => 0, 'is_deleted' => 0], 'category', 'category', 'list.category', '/category/', 1);
$check(count($events) === 1, 'draft, private, deleted, and non-positive entities do not emit');

frontend_entity_viewed_notify($pdo, ['id' => 7, 'is_deleted' => 0], 'category', 'category', 'list.category', 'https://example.test/category/news/', 3);
$check(count($events) === 2
    && $events[1][0] === ['kind' => 'category', 'type' => 'category', 'id' => 7]
    && $events[1][1]['route'] === 'list.category'
    && $events[1][1]['canonical_url'] === null
    && $events[1][1]['page'] === 3,
    'category collections use their canonical Core slot and discard non-root-relative URLs');
remove_action('frontend_entity_viewed', $listener);

$sources = [
    'post' => $read('app/controllers/PostController.php'),
    'page' => $read('app/controllers/PageController.php'),
    'theme' => $read('app/controllers/ThemeController.php'),
    'category' => $read('app/controllers/CategoryController.php'),
];
$check(substr_count($read('cfg/helpers/hooks.php'), "do_action('frontend_entity_viewed', \$entity, \$context, \$pdo);") === 1,
    'the generic lifecycle boundary dispatches the action at exactly one code location');
$check(substr_count(implode("\n", $sources), 'frontend_entity_viewed_notify(') === 4
    && substr_count($sources['post'], "'post', 'article', 'single.post'") === 1
    && substr_count($sources['page'], "'post', 'page', 'single.page'") === 1
    && substr_count($sources['theme'], "'post', 'theme', 'single.theme'") === 1
    && substr_count($sources['category'], "'category', 'category', 'list.category'") === 1,
    'Article, Page, direct Theme Content, and resolved Category paths each notify once');
$check(str_contains($sources['post'], 'public static function renderArticle(array $row, PDO $pdo, ?string $canonicalPath = null)')
    && str_contains($sources['page'], 'public static function renderPage(PDO $pdo, string $slug, ?string $canonicalPath = null)')
    && str_contains($sources['theme'], 'public static function renderTheme(array $themeData, ?string $canonicalPath = null)'),
    'single-content renderers accept an already-resolved canonical path');
$check(str_contains($sources['page'], "apply_filters('post_content', (string)(\$pageData['content'] ?? ''), \$pageData)"),
    'Page content filters run on the content body before custom template rendering');

$postNotify = strpos($sources['post'], 'frontend_entity_viewed_notify(');
$postRender = strpos($sources['post'], "render_assigned_slot(\$pdo, 'content', 'posts_single:'");
$pageResolved = strpos($sources['page'], 'if (!$pageData) {');
$pageNotify = strpos($sources['page'], 'frontend_entity_viewed_notify(');
$pageRender = strpos($sources['page'], "render_assigned_slot(\$pdo, 'content', 'pages_single:'");
$themeNotify = strpos($sources['theme'], 'frontend_entity_viewed_notify(');
$themeRender = strpos($sources['theme'], 'render_custom_post_template(');
$categoryResolved = strpos($sources['category'], 'if (!$category) {');
$categoryNotify = strpos($sources['category'], 'frontend_entity_viewed_notify(');
$categoryRender = strpos($sources['category'], "self::trySlot(\$pdo, 'list.category'", $categoryNotify ?: 0);
$check($postNotify !== false && $postRender !== false && $postNotify < $postRender
    && $pageResolved !== false && $pageNotify !== false && $pageRender !== false && $pageResolved < $pageNotify && $pageNotify < $pageRender
    && $themeNotify !== false && $themeRender !== false && $themeNotify < $themeRender
    && $categoryResolved !== false && $categoryNotify !== false && $categoryRender !== false && $categoryResolved < $categoryNotify && $categoryNotify < $categoryRender,
    'notifications occur after successful resolution and before controller rendering');

$router = $read('public/router.php');
$check(strpos($router, "header('Location: ' . \$routeUrl, true, 301);") < strpos($router, 'PostController::renderArticle($resolved, $pdo, $renderCanonicalUrl);')
    && strpos($router, "header('Location: ' . \$target, true, 301);") < strpos($router, 'PostController::renderArticle($routed, $pdo, $routedCanonicalUrl);'),
    'canonical redirects terminate before single-entity render paths can notify');
$check(str_contains($router, "trim(\$routeUrl, '/') !== trim(\$rawPath, '/')")
    && str_contains($router, 'PostController::dispatchBySlug($pathTrimmed, $pdo, $isLoggedIn, $rawPath)')
    && str_contains($router, 'ThemeController::renderTheme($routed, $routedCanonicalUrl)')
    && str_contains($router, 'PageController::renderPage($pdo, (string)$routed[\'slug\'], $routedCanonicalUrl)'),
    'the router preserves localized canonical URLs and compares them with the original request path');
$check(str_contains($read('cms.md'), "do_action('frontend_entity_viewed'")
    && str_contains($read('AGENTS.md'), 'frontend_entity_viewed($entity, $context, $pdo)'),
    'the frontend lifecycle contract is documented in repository hook references');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " frontend entity view contract check(s) failed.\n");
    exit(1);
}
echo "Frontend entity view contract passed.\n";
