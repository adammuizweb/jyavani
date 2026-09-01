<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/app/controllers/SitemapController.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$add = (string)file_get_contents($root . '/dashboard/admin/posts/add.php');
$save = (string)file_get_contents($root . '/dashboard/admin/posts/save.php');
$edit = (string)file_get_contents($root . '/dashboard/admin/posts/edit.php');
$bulk = (string)file_get_contents($root . '/dashboard/admin/posts/bulk_action.php');
$postList = (string)file_get_contents($root . '/dashboard/admin/posts/index.php');
$siteSettings = (string)file_get_contents($root . '/dashboard/admin/settings/site.php');
$sitemap = (string)file_get_contents($root . '/app/controllers/SitemapController.php');
$docs = (string)file_get_contents($root . '/cms.md');

$addHook = strpos($add, "do_action('admin_post_before_add_commit'");
$addCommit = strpos($add, '$pdo->commit();', $addHook === false ? 0 : $addHook);
$check($addHook !== false && $addCommit !== false && $addHook < $addCommit,
    'article creation exposes an in-transaction pre-commit action');

$saveHook = strpos($save, "do_action('admin_post_before_edit_commit'");
$saveCommit = strpos($save, '$pdo->commit();', $saveHook === false ? 0 : $saveHook);
$check($saveHook !== false && $saveCommit !== false && $saveHook < $saveCommit,
    'article editing exposes an in-transaction pre-commit action');

$check(str_contains($edit, "apply_filters('admin_post_editor_status'")
    && substr_count($save, "apply_filters('admin_post_editor_status'") === 2
    && str_contains($save, 'Post editor status is invalid.'),
    'article editor and locked mutation permissions share an extensible source status');

$check(str_contains($siteSettings, "apply_filters('site_settings_validation_errors'")
    && str_contains($siteSettings, 'stored_content_default_language')
    && str_contains($siteSettings, 'array_merge($errors, $filteredErrors)'),
    'site settings expose monotonic validation before persistence');

$listJoinFilter = strpos($postList, "apply_filters('post_list_join'");
$listCount = strpos($postList, 'SELECT COUNT(DISTINCT p.id)');
$check(str_contains($postList, "apply_filters('post_list_status_expression'")
    && str_contains($postList, "apply_filters('post_list_search_condition'")
    && str_contains($postList, 'AS editor_status')
    && str_contains($postList, "['editor_status']")
    && $listJoinFilter !== false && $listCount !== false && $listJoinFilter < $listCount,
    'post list filtering, display, authorization, and counts share localized joins and expressions');

$check(substr_count($bulk, "do_action('admin_posts_bulk_before_mutation'") === 5
    && str_contains($bulk, "apply_filters('admin_post_editor_status'"),
    'bulk article actions use source-aware permissions and expose validated pre-mutation policy enforcement');

$check(str_contains($sitemap, "apply_filters('sitemap_query_clauses'")
    && str_contains($sitemap, 'self::queryClauses($pdo, $dbType)')
    && str_contains($sitemap, 'self::queryClauses($pdo, $type)'),
    'default sitemap counts and rows share extension query clauses');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, type TEXT, is_deleted INTEGER, status TEXT, slug TEXT)');
$pdo->exec('CREATE TABLE content_routes (post_id INTEGER, locale TEXT, canonical_slot INTEGER)');
$pdo->exec('CREATE TABLE workflow_rows (post_id INTEGER PRIMARY KEY, source_status TEXT)');
$pdo->exec("INSERT INTO posts VALUES (1, 'article', 0, 'published', 'one'), (2, 'article', 0, 'published', 'two'), (3, 'theme', 0, 'published', 'three'), (4, 'theme', 0, 'published', 'four')");
$pdo->exec("INSERT INTO content_routes VALUES (3, '', 1), (4, '', 1)");
$pdo->exec("INSERT INTO workflow_rows VALUES (2, 'draft'), (4, 'draft')");

$visibility = static function (array $clauses): array {
    $clauses['where'][] = 'NOT EXISTS (SELECT 1 FROM workflow_rows cw WHERE cw.post_id = p.id AND cw.source_status <> :workflow_source_status)';
    $clauses['params'][':workflow_source_status'] = 'published';
    return $clauses;
};
add_filter('sitemap_query_clauses', $visibility);
$count = new ReflectionMethod(SitemapController::class, 'countByType');
$check($count->invoke(null, $pdo, 'article') === 1,
    'sitemap query clauses can hide aggregate-public rows with a nonpublic source');
$themeCount = new ReflectionMethod(SitemapController::class, 'countRoutedThemes');
$check($themeCount->invoke(null, $pdo) === 1,
    'theme sitemap counts share extension query clauses with sitemap rows');
remove_filter('sitemap_query_clauses', $visibility);

$malformed = static fn(array $clauses): array => ['where' => ['1=1; DROP TABLE posts'], 'params' => []];
add_filter('sitemap_query_clauses', $malformed);
$check($count->invoke(null, $pdo, 'article') === 2,
    'malformed sitemap query clauses fail back to Core visibility');
remove_filter('sitemap_query_clauses', $malformed);

$unbound = static fn(array $clauses): array => ['where' => ['p.id <> :workflow_excluded'], 'params' => []];
add_filter('sitemap_query_clauses', $unbound);
$check($count->invoke(null, $pdo, 'article') === 2,
    'unbound sitemap placeholders fail back to Core visibility');
remove_filter('sitemap_query_clauses', $unbound);

$unused = static fn(array $clauses): array => ['where' => ['p.id > 0'], 'params' => [':workflow_unused' => 1]];
add_filter('sitemap_query_clauses', $unused);
$check($count->invoke(null, $pdo, 'article') === 2,
    'unused sitemap parameters fail back to Core visibility');
remove_filter('sitemap_query_clauses', $unused);

$quotedColon = static fn(array $clauses): array => ['where' => ["'workflow:draft' <> ''"], 'params' => []];
add_filter('sitemap_query_clauses', $quotedColon);
$check($count->invoke(null, $pdo, 'article') === 2,
    'colons inside sitemap SQL literals are not treated as placeholders');
remove_filter('sitemap_query_clauses', $quotedColon);

$check(str_contains($docs, 'admin_post_before_add_commit')
    && str_contains($docs, 'admin_post_before_edit_commit')
    && str_contains($docs, 'admin_posts_bulk_before_mutation')
    && str_contains($docs, 'admin_post_editor_status')
    && str_contains($docs, 'site_settings_validation_errors')
    && str_contains($docs, 'post_list_status_expression')
    && str_contains($docs, 'sitemap_query_clauses'),
    'article workflow and sitemap extension contracts are documented');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
