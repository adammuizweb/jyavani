<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'helper' => (string)file_get_contents($root . '/dashboard/admin/bin/_undo.php'),
    'endpoint' => (string)file_get_contents($root . '/dashboard/admin/content/undo_bulk_status.php'),
    'article' => (string)file_get_contents($root . '/dashboard/admin/posts/bulk_action.php'),
    'page' => (string)file_get_contents($root . '/dashboard/admin/pages/bulk_action.php'),
    'theme' => (string)file_get_contents($root . '/dashboard/admin/themes/bulk_action.php'),
    'article_save' => (string)file_get_contents($root . '/dashboard/admin/posts/save.php'),
    'page_save' => (string)file_get_contents($root . '/dashboard/admin/pages/save.php'),
    'theme_save' => (string)file_get_contents($root . '/dashboard/admin/themes/save.php'),
    'schema' => (string)file_get_contents($root . '/schema/default.sql'),
    'migration' => (string)file_get_contents($root . '/schema/migrations/021-content-status-revision.sql'),
    'translations' => (string)file_get_contents($root . '/schema/translations.sql'),
];
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($sources['helper'], "['article', 'page', 'theme']")
    && str_contains($sources['helper'], "adiwira_undo_issue('content.bulk_status.' . \$resource")
    && str_contains($sources['helper'], 'adiwira_content_parse_status_undo_items'),
    'status Undo grants are bounded to supported content resources');
$check(str_contains($sources['helper'], "'previous_status' => \$previousStatus")
    && str_contains($sources['helper'], "'changed_status' => \$changedStatus")
    && str_contains($sources['helper'], "'status_revision' => \$statusRevision")
    && str_contains($sources['helper'], '$previousStatus === $changedStatus'),
    'status grants retain distinct validated before and after states');
$check(str_contains($sources['helper'], "'core.posts.publish'")
    && str_contains($sources['helper'], "'core.pages.publish'")
    && str_contains($sources['helper'], "'core.theme_content.update'"),
    'grant issuance checks update and applicable publish permissions');
$check(str_contains($sources['endpoint'], "adiwira_undo_get(\$undoToken, 'content.bulk_status.' . \$resource, \$uid)")
    && str_contains($sources['endpoint'], 'authorization_lock_actor_permissions')
    && str_contains($sources['endpoint'], 'authorization_lock_owner_contexts'),
    'status Undo is actor-bound and rechecks locked authorization');
$check(str_contains($sources['endpoint'], "(string)\$row['status'] !== \$item['changed_status']")
    && str_contains($sources['endpoint'], "(int)\$row['status_revision'] !== \$item['status_revision']")
    && str_contains($sources['endpoint'], 'adiwira_bin_latest_audit_id($pdo, $resource, $id)')
    && str_contains($sources['endpoint'], 'FOR UPDATE'),
    'status Undo compares locked current status and latest mutation audit');
$check(str_contains($sources['endpoint'], "apply_filters('admin_post_editor_status'")
    && str_contains($sources['endpoint'], "apply_filters('admin_page_editor_status'")
    && str_contains($sources['article'], '$statusUndoEligible')
    && str_contains($sources['page'], '$statusUndoEligible'),
    'status Undo fails closed when extension and canonical statuses diverge');
$check(str_contains($sources['endpoint'], "'previous_status'] . ':' . \$item['changed_status']")
    && str_contains($sources['endpoint'], "AND status = ?")
    && str_contains($sources['endpoint'], "\$update->rowCount() !== count(\$group['ids'])"),
    'mixed previous statuses restore in exact atomic groups');
$check(str_contains($sources['endpoint'], "'admin_posts_bulk_before_mutation'")
    && str_contains($sources['endpoint'], "'admin_pages_bulk_before_mutation'")
    && str_contains($sources['endpoint'], "'undo' => true"),
    'Article and Page status Undo preserves bulk workflow hooks');
$commit = strpos($sources['endpoint'], '$pdo->commit();');
$consume = strpos($sources['endpoint'], 'adiwira_undo_consume($undoToken);', $commit === false ? 0 : $commit);
$check($commit !== false && $consume !== false && $commit < $consume
    && str_contains($sources['endpoint'], '.status_change_undone'),
    'status Undo is audited and consumed only after commit');

foreach (['article' => 'article.status_changed', 'page' => 'page.status_changed', 'theme' => 'theme.status_changed'] as $key => $event) {
    $check(str_contains($sources[$key], $event)
        && str_contains($sources[$key], 'adiwira_content_issue_status_undo')
        && str_contains($sources[$key], "'previous_status' =>")
        && str_contains($sources[$key], "'changed_status' =>"),
        $key . ' bulk status changes emit exact audited Undo state');
    $check(str_contains($sources[$key], '$affected !== count(')
        && str_contains($sources[$key], 'status change committed but notification failed'),
        $key . ' bulk status changes fail closed before commit and report post-commit success');
}
$check(str_contains($sources['schema'], '`status_revision` bigint(20) unsigned NOT NULL DEFAULT 0')
    && str_contains($sources['migration'], 'ADD COLUMN `status_revision`')
    && str_contains($sources['article_save'], 'status_revision = status_revision + IF(status <> :revision_status, 1, 0)')
    && str_contains($sources['page_save'], 'status_revision = status_revision + IF(status <> :revision_status, 1, 0)')
    && str_contains($sources['theme_save'], 'status_revision = status_revision + IF(status <> :revision_status, 1, 0)'),
    'every Core editor status writer advances the persistent status revision');

foreach (['Failed to undo status change.', '%d item(s) status restored.', '%d theme partial(s) status changed to "%s".'] as $key) {
    $quoted = preg_quote("('default', '" . str_replace("'", "''", $key) . "'", '/');
    $check(preg_match_all('/' . $quoted . '/', $sources['translations']) === 2, $key . ' has Indonesian and German seeds');
}

require_once $root . '/dashboard/admin/bin/_undo.php';
$valid = adiwira_content_parse_status_undo_items([
    ['id' => 8, 'audit_id' => 20, 'previous_status' => 'private', 'changed_status' => 'draft', 'status_revision' => 5],
    ['id' => 3, 'audit_id' => 19, 'previous_status' => 'published', 'changed_status' => 'draft', 'status_revision' => 2],
]);
$check(array_keys($valid) === [3, 8]
    && $valid[8]['previous_status'] === 'private', 'status Undo parser normalizes a valid mixed-state batch');
$check(adiwira_content_parse_status_undo_items([
    ['id' => 3, 'audit_id' => 19, 'previous_status' => 'draft', 'changed_status' => 'draft', 'status_revision' => 2],
]) === [], 'status Undo parser rejects no-op state');
$check(adiwira_content_parse_status_undo_items([
    ['id' => 3, 'audit_id' => 19, 'previous_status' => 'invalid', 'changed_status' => 'draft', 'status_revision' => 2],
]) === [], 'status Undo parser rejects invalid statuses');
$check(adiwira_content_parse_status_undo_items([
    ['id' => 3, 'audit_id' => 19, 'previous_status' => 'published', 'changed_status' => 'draft', 'status_revision' => 0],
]) === [], 'status Undo parser rejects a missing status revision');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " content status Undo contract check(s) failed.\n");
    exit(1);
}
echo "Content status Undo contract passed ({$checks} checks).\n";
