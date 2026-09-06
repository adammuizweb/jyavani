<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$sources = [
    'helper' => $read('dashboard/admin/bin/_undo.php'),
    'undo' => $read('dashboard/admin/bin/undo_trash.php'),
    'hub' => $read('dashboard/admin/bin/index.php'),
    'article_bin' => $read('dashboard/admin/bin/article/index.php'),
    'page_bin' => $read('dashboard/admin/bin/page/index.php'),
    'category_bin' => $read('dashboard/admin/bin/category/index.php'),
    'theme_bin' => $read('dashboard/admin/bin/theme/index.php'),
    'users_bin' => $read('dashboard/admin/bin/users/index.php'),
    'dashboard_style' => $read('public/static/dashboard/css/style.css'),
    'post_delete' => $read('dashboard/admin/posts/delete.php'),
    'post_bulk' => $read('dashboard/admin/posts/bulk_action.php'),
    'page_delete' => $read('dashboard/admin/pages/delete.php'),
    'page_bulk' => $read('dashboard/admin/pages/bulk_action.php'),
    'theme_delete' => $read('dashboard/admin/themes/delete.php'),
    'theme_bulk' => $read('dashboard/admin/themes/bulk_action.php'),
    'category_delete' => $read('dashboard/admin/categories/delete.php'),
    'category_bulk' => $read('dashboard/admin/categories/bulk_action.php'),
    'category_restore' => $read('dashboard/admin/bin/category/restore.php'),
    'category_restore_bulk' => $read('dashboard/admin/bin/category/bulk_action.php'),
    'theme_restore' => $read('dashboard/admin/bin/theme/restore.php'),
    'theme_restore_bulk' => $read('dashboard/admin/bin/theme/bulk_action.php'),
    'restore_icon' => $read('public/static/icons/lucide/rotate-ccw.svg'),
    'schema' => $read('schema/default.sql'),
    'migration' => $read('schema/migrations/020-authorization-audit-resource-index.sql'),
    'translations' => $read('schema/translations.sql'),
];

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($sources['helper'], "['article', 'page', 'theme', 'category']")
    && str_contains($sources['helper'], "adiwira_undo_issue('bin.trash.' . \$resource")
    && str_contains($sources['helper'], 'count($items) > 100'),
    'shared grants allow only bounded Core Bin resources');
$check(str_contains($sources['helper'], "ORDER BY id DESC")
    && str_contains($sources['helper'], 'FOR UPDATE')
    && str_contains($sources['schema'], 'idx_authz_audit_resource')
    && str_contains($sources['migration'], '`resource_id`(150)'),
    'latest resource mutation checks use a dedicated audit index');
$check(str_contains($sources['undo'], "adiwira_undo_get(\$undoToken, 'bin.trash.' . \$resource, \$uid)")
    && str_contains($sources['undo'], 'adiwira_bin_latest_audit_id($pdo, $resource, $id)')
    && str_contains($sources['undo'], 'adiwira_undo_consume($undoToken);'),
    'Undo is actor-bound, audit-bound, and consumed after use');
$check(str_contains($sources['undo'], 'authorization_lock_actor_permissions')
    && str_contains($sources['undo'], 'authorization_lock_owner_contexts')
    && str_contains($sources['undo'], "user_can(\$pdo, \$uid, \$config['permission']"),
    'Undo rechecks locked authorization state');
$check(str_contains($sources['undo'], 'shortcode_collection_layout_content_mutation($pdo, $performUndo)')
    && str_contains($sources['undo'], "\$resource !== 'category'")
    && str_contains($sources['theme_restore'], 'shortcode_collection_layout_content_mutation')
    && str_contains($sources['theme_restore_bulk'], 'shortcode_collection_layout_content_mutation'),
    'all posts-table restores use the content mutation lock');
$check(str_contains($sources['undo'], 'count(array_unique($slugs)) !== count($slugs)')
    && str_contains($sources['undo'], "type IN ('article', 'page', 'theme') AND is_deleted = 0"),
    'content Undo rejects duplicate and active slug collisions');
$check(str_contains($sources['undo'], "\$items[\$id]['parent_id']")
    && str_contains($sources['undo'], "__('Restore the parent category first.')")
    && !str_contains($sources['category_restore'], 'parent_id = :pid')
    && !str_contains($sources['category_restore_bulk'], 'c.parent_id = CASE'),
    'Category restore preserves and validates the original parent relationship');
$check(!str_contains($sources['post_delete'], 'DELETE FROM post_categories WHERE post_id = :id')
    && !str_contains($sources['post_bulk'], 'DELETE FROM post_categories WHERE post_id IN ($in)')
    && !str_contains($sources['page_delete'], 'DELETE FROM post_categories WHERE post_id = :id')
    && !str_contains($sources['page_bulk'], 'DELETE FROM post_categories WHERE post_id IN ($in)'),
    'Article and Page trash retain category relationships');
$check(str_contains($sources['helper'], 'adiwira_bin_post_category_map')
    && str_contains($sources['undo'], "\$items[\$id]['category_ids']")
    && str_contains($sources['post_delete'], "'category_ids' =>")
    && str_contains($sources['page_bulk'], "'category_ids' =>"),
    'Article and Page Undo validates the exact category relationship set');

foreach (['post_delete', 'post_bulk', 'page_delete', 'page_bulk', 'theme_delete', 'theme_bulk', 'category_delete', 'category_bulk'] as $key) {
    $check(str_contains($sources[$key], 'adiwira_bin_record_audit')
        && str_contains($sources[$key], 'adiwira_bin_issue_trash_undo'),
        $key . ' audits trash and emits Undo');
}
$check(str_contains($sources['hub'], "\$page_toasts[] = \$f;")
    && !str_contains($sources['hub'], "'message' => \$text,"),
    'Bin hub preserves complete Toast action descriptors');
$check(str_contains($sources['theme_restore'], "type IN ('article', 'page', 'theme')")
    && str_contains($sources['theme_restore_bulk'], 'count(array_unique($slugs))'),
    'Theme restore fails closed on single and bulk slug collisions');
$check(str_contains($sources['restore_icon'], 'lucide-rotate-ccw')
    && str_contains($sources['restore_icon'], '<path d="M3 12a9 9 0 1 0')
    && str_contains($sources['article_bin'], "svg_ico('rotate-ccw'"),
    'Bin Restore uses the bundled Lucide rotate-ccw asset instead of the circle fallback');
foreach (['article_bin', 'page_bin', 'category_bin', 'theme_bin', 'users_bin'] as $key) {
    $check(str_contains($sources[$key], 'class="toolbar-filter bin-filter-bar"')
        && str_contains($sources[$key], 'class="inp"')
        && str_contains($sources[$key], 'class="bulk-bar"')
        && str_contains($sources[$key], 'class="check-row"'),
        $key . ' reuses the Core filter and bulk control styling');
}
$check(str_contains($sources['dashboard_style'], '.bin-filter-bar')
    && str_contains($sources['dashboard_style'], '.bin-trash-total')
    && str_contains($sources['dashboard_style'], '@media (max-width:640px)')
    && str_contains($sources['dashboard_style'], '.bin-filter-bar input[type="text"].inp'),
    'Bin filter metadata has responsive Core styling');

foreach ([
    'Failed to undo move to trash.',
    'Item restored successfully.',
    'One or more selected slugs are duplicated.',
    'One or more selected slugs are already active.',
    '%d article(s) moved to trash.',
    '%d page(s) moved to trash.',
    'Theme partial moved to trash successfully.',
    '%d theme partial(s) moved to trash.',
    '%d user(s) moved to trash.',
] as $key) {
    $quoted = preg_quote("('default', '" . str_replace("'", "''", $key) . "'", '/');
    $check(preg_match_all('/' . $quoted . '/', $sources['translations']) === 2, $key . ' has Indonesian and German seeds');
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Bin Undo contract check(s) failed.\n");
    exit(1);
}
echo "Bin Undo contract passed ({$checks} checks).\n";
