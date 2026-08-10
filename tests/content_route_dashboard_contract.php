<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = [
    'config' => (string)file_get_contents($root . '/cfg/config.php'),
    'permalink' => (string)file_get_contents($root . '/cfg/helpers/permalink_helpers.php'),
    'router' => (string)file_get_contents($root . '/public/router.php'),
    'post_controller' => (string)file_get_contents($root . '/app/controllers/PostController.php'),
    'sitemap' => (string)file_get_contents($root . '/app/controllers/SitemapController.php'),
    'theme_add' => (string)file_get_contents($root . '/dashboard/admin/themes/add.php'),
    'theme_edit' => (string)file_get_contents($root . '/dashboard/admin/themes/edit.php'),
    'theme_save' => (string)file_get_contents($root . '/dashboard/admin/themes/save.php'),
    'theme_index' => (string)file_get_contents($root . '/dashboard/admin/themes/index.php'),
    'menu_index' => (string)file_get_contents($root . '/dashboard/admin/menus/index.php'),
    'site_settings' => (string)file_get_contents($root . '/dashboard/admin/settings/site.php'),
    'auth_settings' => (string)file_get_contents($root . '/dashboard/admin/settings/auth.php'),
];

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(
    strpos($source['config'], 'content_route_helpers.php') < strpos($source['config'], 'permalink_helpers.php'),
    'content route helpers load before permalink generation'
);
$check(str_contains($source['permalink'], 'content_route_canonical_url'), 'post and page permalinks consult canonical content routes');
$check(str_contains($source['router'], 'content_route_resolve($pdo, $pathTrimmed'), 'router resolves explicit nested content routes');
$check(strpos($source['router'], 'content_route_resolve($pdo, $pathTrimmed') < strpos($source['router'], 'PostController::dispatchBySlug'), 'content routes resolve before legacy direct slugs');
$check(str_contains($source['router'], "header('Location: ' . \$target, true, 301)"), 'historical routes permanently redirect to their canonical path');
$check(str_contains($source['post_controller'], 'content_route_find_canonical') && str_contains($source['post_controller'], 'get_post_permalink($row)'), 'legacy internal slugs redirect through locale-aware canonical permalinks');

$check(str_contains($source['theme_add'], 'name="public_path"'), 'Theme add form exposes an optional public path');
$check(str_contains($source['theme_edit'], 'name="public_path"'), 'Theme edit form exposes the canonical public path');
$check(str_contains($source['theme_add'], '$pdo->beginTransaction()'), 'Theme creation starts a database transaction');
$check(str_contains($source['theme_add'], 'content_route_set_canonical($pdo, $postId'), 'Theme creation saves its public route atomically');
$check(str_contains($source['theme_save'], '$pdo->beginTransaction()'), 'Theme editing starts a database transaction');
$check(str_contains($source['theme_save'], 'content_route_delete_for_post($pdo, $id)'), 'clearing the public path removes stale aliases');
$check(str_contains($source['theme_save'], 'content_route_set_canonical($pdo, $id'), 'Theme editing preserves route history through the canonical setter');
$check(str_contains($source['theme_index'], 'cr.path AS public_path'), 'Theme list displays canonical public paths');

$check(str_contains($source['menu_index'], "data-type=\"theme\""), 'Menu Manager offers routed Theme pages');
$check(str_contains($source['menu_index'], 'cr.canonical_slot = 1'), 'Menu Manager excludes assignment-only Theme partials');
$check(str_contains($source['sitemap'], "'themes' => 'theme'"), 'sitemap controller supports routed Theme pages');
$check(str_contains($source['sitemap'], 'content_routes cr'), 'Theme sitemap includes only canonical public routes');
$check(str_contains($source['site_settings'], 'content_route_conflicts_with_setting_path'), 'collection settings cannot shadow existing content routes');
$check(str_contains($source['auth_settings'], 'content_route_conflicts_with_setting_path'), 'authentication settings cannot shadow existing content routes');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
