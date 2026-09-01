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
$bulk = (string)file_get_contents($root . '/dashboard/admin/posts/bulk_action.php');
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

$bulkHook = strpos($bulk, "do_action('admin_posts_bulk_before_mutation'");
$bulkMutation = strpos($bulk, "if (\$action === 'delete')", $bulkHook === false ? 0 : $bulkHook);
$check($bulkHook !== false && $bulkMutation !== false && $bulkHook < $bulkMutation,
    'bulk article actions expose locked pre-mutation policy enforcement');

$check(str_contains($sitemap, "apply_filters('sitemap_query_clauses'")
    && str_contains($sitemap, 'self::queryClauses($pdo, $dbType)')
    && str_contains($sitemap, 'self::queryClauses($pdo, $type)'),
    'default sitemap counts and rows share extension query clauses');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, type TEXT, is_deleted INTEGER, status TEXT)');
$pdo->exec('CREATE TABLE workflow_rows (post_id INTEGER PRIMARY KEY, source_status TEXT)');
$pdo->exec("INSERT INTO posts VALUES (1, 'article', 0, 'published'), (2, 'article', 0, 'published')");
$pdo->exec("INSERT INTO workflow_rows VALUES (2, 'draft')");

$visibility = static function (array $clauses): array {
    $clauses['where'][] = 'NOT EXISTS (SELECT 1 FROM workflow_rows cw WHERE cw.post_id = p.id AND cw.source_status <> :workflow_source_status)';
    $clauses['params'][':workflow_source_status'] = 'published';
    return $clauses;
};
add_filter('sitemap_query_clauses', $visibility);
$count = new ReflectionMethod(SitemapController::class, 'countByType');
$check($count->invoke(null, $pdo, 'article') === 1,
    'sitemap query clauses can hide aggregate-public rows with a nonpublic source');
remove_filter('sitemap_query_clauses', $visibility);

$malformed = static fn(array $clauses): array => ['where' => ['1=1; DROP TABLE posts'], 'params' => []];
add_filter('sitemap_query_clauses', $malformed);
$check($count->invoke(null, $pdo, 'article') === 2,
    'malformed sitemap query clauses fail back to Core visibility');
remove_filter('sitemap_query_clauses', $malformed);

$check(str_contains($docs, 'admin_post_before_add_commit')
    && str_contains($docs, 'admin_post_before_edit_commit')
    && str_contains($docs, 'admin_posts_bulk_before_mutation')
    && str_contains($docs, 'sitemap_query_clauses'),
    'article workflow and sitemap extension contracts are documented');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
