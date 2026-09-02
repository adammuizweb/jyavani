<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$schema = (string)file_get_contents($root . '/schema/default.sql');
$migration = (string)file_get_contents($root . '/schema/migrations/019-content-readonly-attribution.sql');
$authorization = (string)file_get_contents($root . '/cfg/helpers/authorization.php');
$articleList = (string)file_get_contents($root . '/dashboard/admin/posts/index.php');
$articleEditor = (string)file_get_contents($root . '/dashboard/admin/posts/edit.php');
$pageList = (string)file_get_contents($root . '/dashboard/admin/pages/index.php');
$pageEditor = (string)file_get_contents($root . '/dashboard/admin/pages/edit.php');
$pondasi = (string)file_get_contents($root . '/public/pondasi/index.php');
$themeAdd = (string)file_get_contents($root . '/dashboard/admin/themes/add.php');
$themeSave = (string)file_get_contents($root . '/dashboard/admin/themes/save.php');
$themeRoutes = '';
foreach ([
    'dashboard/admin/themes/index.php', 'dashboard/admin/themes/edit.php',
    'dashboard/admin/themes/add.php', 'dashboard/admin/themes/save.php',
    'dashboard/admin/themes/delete.php', 'dashboard/admin/themes/bulk_action.php',
    'dashboard/admin/bin/theme/index.php', 'dashboard/admin/bin/theme/restore.php',
    'dashboard/admin/bin/theme/delete_permanent.php', 'dashboard/admin/bin/theme/bulk_action.php',
] as $relative) $themeRoutes .= (string)file_get_contents($root . '/' . $relative);

foreach (['core.posts.read', 'core.pages.read', 'core.categories.read', 'core.theme_content.read'] as $permission) {
    $check(str_contains($migration, "'$permission'") && str_contains($schema, "SELECT '$permission', 'any'"),
        $permission . ' is any-scoped for fresh and upgraded system editorial roles');
}
$check(str_contains($migration, "r.is_system = 1 AND r.slug IN ('author','editor')")
    && !str_contains($migration, "r.is_system = 0"), 'policy migration changes only protected system roles');
$check(str_contains($pondasi, "019-content-readonly-attribution.sql")
    && strpos($pondasi, '013-dynamic-authorization.sql') < strpos($pondasi, '019-content-readonly-attribution.sql'),
    'fresh installation reapplies the final content policy after legacy authorization seeds');
$check(str_contains($authorization, 'function authorization_editor_context')
    && !str_contains($authorization, 'plugin_'), 'generic editor context has no extension-specific concepts');
$check(str_contains($articleList, "_e('View')") && str_contains($pageList, "_e('View')")
    && str_contains($articleEditor, "_e('View Article')") && str_contains($pageEditor, "_e('View Page')"),
    'article and page lists expose readable records through safe read-only editors');
$check(str_contains($articleEditor, 'authorization_editor_context')
    && str_contains($pageEditor, 'authorization_editor_context')
    && substr_count($articleEditor . $pageEditor, 'htmlspecialchars((string)$post[\'content\']') === 2,
    'read-only editors authorize reads independently and escape stored content');
$check(!str_contains($themeRoutes, 'adiwira_require_role')
    && str_contains($themeRoutes, 'core.theme_content.read')
    && str_contains($themeRoutes, 'core.theme_content.update')
    && str_contains($themeRoutes, 'core.theme_content.delete')
    && str_contains($themeRoutes, 'core.theme_content.restore')
    && str_contains($themeRoutes, 'core.theme_content.purge'),
    'theme-content CRUD and bin routes use the scoped Core permission family');
$check(str_contains($themeAdd, 'authorization_lock_actor_permissions')
    && str_contains($themeAdd, "user_can(\$pdo, \$user_id, 'core.theme_content.create')"),
    'theme creation reauthorizes the actor inside its transaction');
$check(!str_contains($themeSave, 'AND created_by = :uid')
    && str_contains($themeSave, "user_can(\$pdo, \$user_id, 'core.theme_content.update'"),
    'delegated scoped theme updates are not narrowed by a legacy role ownership clause');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
