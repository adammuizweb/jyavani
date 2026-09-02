<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/authorization.php';
require_once $root . '/cfg/helpers/cms_content.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$migration = (string)file_get_contents($root . '/schema/migrations/013-dynamic-authorization.sql');
$policyMigration = (string)file_get_contents($root . '/schema/migrations/014-plugin-permission-policy.sql');
$categoryOwnershipMigration = (string)file_get_contents($root . '/schema/migrations/018-author-category-ownership.sql');
$schema = (string)file_get_contents($root . '/schema/default.sql');
$installer = (string)file_get_contents($root . '/public/pondasi/index.php');
$guard = (string)file_get_contents($root . '/dashboard/admin/_guard.php');
$authorizationHelper = (string)file_get_contents($root . '/cfg/helpers/authorization.php');
$roleHelper = (string)file_get_contents($root . '/cfg/helpers/role_helpers.php');
$dashboard = (string)file_get_contents($root . '/dashboard/index.php');
$userDelete = (string)file_get_contents($root . '/dashboard/admin/users/delete.php');
$userLock = (string)file_get_contents($root . '/dashboard/admin/users/toggle_lock.php');
$profile = (string)file_get_contents($root . '/dashboard/admin/profile/index.php');
$recovery = (string)file_get_contents($root . '/tools/promote-site-owner.php');
$registration = (string)file_get_contents($root . '/dashboard/gerbank/daptar/index.php');
$userSave = (string)file_get_contents($root . '/dashboard/admin/users/save.php');
$userBulk = (string)file_get_contents($root . '/dashboard/admin/users/bulk_action.php');
$userIndex = (string)file_get_contents($root . '/dashboard/admin/users/index.php');
$siteOwnerEndpoint = (string)file_get_contents($root . '/dashboard/admin/users/site_owner.php');
$roleManager = (string)file_get_contents($root . '/dashboard/admin/users/roles/index.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$userBinRestore = (string)file_get_contents($root . '/dashboard/admin/bin/users/restore.php');
$userBinPurge = (string)file_get_contents($root . '/dashboard/admin/bin/users/delete_permanent.php');
$categoryRoutes = '';
foreach ([
    'dashboard/admin/categories/index.php',
    'dashboard/admin/categories/add.php',
    'dashboard/admin/categories/edit.php',
    'dashboard/admin/categories/delete.php',
    'dashboard/admin/categories/bulk_action.php',
    'dashboard/admin/bin/category/index.php',
    'dashboard/admin/bin/category/restore.php',
    'dashboard/admin/bin/category/delete_permanent.php',
    'dashboard/admin/bin/category/bulk_action.php',
] as $categoryRoute) {
    $categoryRoutes .= (string)file_get_contents($root . '/' . $categoryRoute);
}
$postRoutes = '';
foreach ([
    'dashboard/admin/posts/index.php',
    'dashboard/admin/posts/add.php',
    'dashboard/admin/posts/edit.php',
    'dashboard/admin/posts/save.php',
    'dashboard/admin/posts/delete.php',
    'dashboard/admin/posts/bulk_action.php',
    'dashboard/admin/bin/article/index.php',
    'dashboard/admin/bin/article/restore.php',
    'dashboard/admin/bin/article/delete_permanent.php',
    'dashboard/admin/bin/article/bulk_action.php',
] as $postRoute) {
    $postRoutes .= (string)file_get_contents($root . '/' . $postRoute);
}
$pageRoutes = '';
foreach ([
    'dashboard/admin/pages/index.php',
    'dashboard/admin/pages/add.php',
    'dashboard/admin/pages/edit.php',
    'dashboard/admin/pages/save.php',
    'dashboard/admin/pages/delete.php',
    'dashboard/admin/pages/bulk_action.php',
    'dashboard/admin/bin/page/index.php',
    'dashboard/admin/bin/page/restore.php',
    'dashboard/admin/bin/page/delete_permanent.php',
    'dashboard/admin/bin/page/bulk_action.php',
] as $pageRoute) {
    $pageRoutes .= (string)file_get_contents($root . '/' . $pageRoute);
}
$aside = (string)file_get_contents($root . '/dashboard/theme/adam/part/aside.php');
$themeWriters = (string)file_get_contents($root . '/dashboard/admin/themes/add.php')
    . (string)file_get_contents($root . '/dashboard/admin/themes/save.php');
$menuRoutes = '';
foreach ([
    'dashboard/admin/menus/index.php',
    'dashboard/admin/menus/save.php',
    'dashboard/admin/menus/items_save.php',
    'dashboard/admin/menus/delete.php',
] as $menuRoute) {
    $menuRoutes .= (string)file_get_contents($root . '/' . $menuRoute);
}
$sidebarRoutes = '';
foreach ([
    'dashboard/admin/sidebar/index.php',
    'dashboard/admin/sidebar/save.php',
    'dashboard/admin/sidebar/delete.php',
    'dashboard/admin/settings/sidebar.php',
] as $sidebarRoute) {
    $sidebarRoutes .= (string)file_get_contents($root . '/' . $sidebarRoute);
}
$siteSettingsRoute = (string)file_get_contents($root . '/dashboard/admin/settings/site.php');
$authSettingsRoute = (string)file_get_contents($root . '/dashboard/admin/settings/auth.php');
$emailSettingsRoute = (string)file_get_contents($root . '/dashboard/admin/settings/email.php');
$emailTestRoute = (string)file_get_contents($root . '/dashboard/admin/settings/email_test.php');
$settingsRoutes = $siteSettingsRoute . $authSettingsRoute . $emailSettingsRoute . $emailTestRoute;
$pluginManagerRoutes = '';
foreach (glob($root . '/dashboard/admin/plugins/*.php') ?: [] as $pluginManagerRoute) {
    $pluginManagerRoutes .= (string)file_get_contents($pluginManagerRoute);
}
$installedThemeRoutes = '';
foreach ([
    'dashboard/admin/themes/assign.php',
    'dashboard/admin/themes/browse.php',
    'dashboard/admin/themes/customize.php',
    'dashboard/admin/themes/upload.php',
    'dashboard/admin/themes/update_apply.php',
    'dashboard/admin/themes/update_preflight.php',
    'dashboard/admin/themes/update_progress.php',
] as $installedThemeRoute) {
    $installedThemeRoutes .= (string)file_get_contents($root . '/' . $installedThemeRoute);
}
$updateManagerRoutes = (string)file_get_contents($root . '/dashboard/admin/update/index.php')
    . (string)file_get_contents($root . '/dashboard/admin/update/update_apply.php')
    . (string)file_get_contents($root . '/dashboard/admin/check_updates_ajax.php');
$dashboardHome = (string)file_get_contents($root . '/dashboard/theme/adam/part/views/home.php');
$dashboardWidgets = (string)file_get_contents($root . '/dashboard/theme/adam/part/views/widgets.php');
$dashboardLayoutSave = (string)file_get_contents($root . '/dashboard/admin/save_dashboard_layout.php');
$dashboardHeader = (string)file_get_contents($root . '/dashboard/theme/adam/part/header.php');
$menuHelper = (string)file_get_contents($root . '/cfg/helpers/menu_helper.php');
$dashboardCss = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');

$check(str_contains($migration, '`is_site_owner`') && str_contains($schema, '`is_site_owner`'), 'migration and fresh schema define Site Owner state');
$check(str_contains($migration, '`role_permissions`') && str_contains($schema, '`role_permissions`'), 'migration and fresh schema define role permission grants');
$check(
    str_contains($policyMigration, '`information_schema`.`COLUMNS`')
    && str_contains($policyMigration, 'PREPARE jy_permission_policy_stmt')
    && str_contains($policyMigration, 'EXECUTE jy_permission_policy_stmt')
    && str_contains($policyMigration, "'SELECT 1'")
    && !str_contains($policyMigration, 'ADD COLUMN IF NOT EXISTS')
    && str_contains($schema, '`is_delegable`'),
    'plugin permission policy migration is replay-safe on MySQL 5.7, MariaDB, and fresh schemas'
);
$migrationStatements = preg_split('/;\s*$/m', implode("\n", array_filter(
    explode("\n", $policyMigration),
    static fn(string $line): bool => trim($line) !== '' && !str_starts_with(trim($line), '--') && !str_starts_with(trim($line), '#')
))) ?: [];
$migrationStatements = array_values(array_filter(array_map('trim', $migrationStatements), static fn(string $statement): bool => $statement !== ''));
$check(count($migrationStatements) === 5, 'plugin permission policy migration remains compatible with migration_helper semicolon splitting');
$check(str_contains($migration, '`site_owner_role_backups`') && str_contains($schema, '`site_owner_role_backups`'), 'migration and fresh schema preserve compatibility role assignments during Site Owner changes');
$check(str_contains($migration, "('author','Author'") && str_contains($migration, "('admin','Administrator'"), 'migration seeds compatibility roles');
$check(!str_contains($migration, 'SET `is_site_owner` = 1'), 'existing-site migration never promotes an arbitrary administrator to Site Owner');
$check(str_contains($schema, "('core.dashboard.access','core'") && str_contains($schema, "WHERE r.slug = 'admin'"), 'fresh schema includes the canonical permission and grant seeds');
$check(str_contains($migration, "enum('none','author','editor','admin')") && str_contains($schema, "enum('none','author','editor','admin')"), 'legacy role bridge has a fail-closed none value');
$check(str_contains($installer, "'site_owner.installed'") && str_contains($installer, 'is_site_owner = 1'), 'Pondasi creates and audits the initial Site Owner');
$check(
    substr_count($installedThemeRoutes, "'core.themes.manage'") === 7
    && substr_count($installedThemeRoutes, 'adiwira_require_site_owner') === 7
    && !str_contains($installedThemeRoutes, 'adiwira_require_admin')
    && !str_contains($installedThemeRoutes, "adiwira_require_role(\$pdo, ['admin']"),
    'executable installed-theme operations require permission and Site Owner authority'
);
$check(str_contains($aside, "current_user_can(\$pdo, 'core.themes.manage')") && str_contains($aside, "\$navActor['is_site_owner'] === true"), 'installed-theme navigation follows the Site Owner boundary');
$check(str_contains($guard, 'adiwira_require_permission') && str_contains($guard, 'adiwira_authorize_resource'), 'dashboard exposes permission and resource guards');
$check(str_contains($guard, 'if (headers_sent())') && str_contains($guard, 'The requested dashboard page is unavailable.'), 'late dashboard denials render safely after layout output');
$check(str_contains($dashboard, "current_user_can(\$pdo, 'core.dashboard.access')"), 'dashboard entry requires an explicit permission');
$check(str_contains($userDelete, 'authorization_change_user_status') && str_contains($userLock, 'authorization_change_user_status') && str_contains($profile, 'authorization_change_user_status'), 'single-user mutations use the atomic Site Owner invariant');
$check(str_contains($recovery, 'authorization_recover_site_owner') && str_contains($recovery, "PHP_SAPI !== 'cli'") && str_contains($authorizationHelper, "'site_owner.recovered'"), 'Site Owner recovery is CLI-only, centralized, and audited');
$check(str_contains($registration, 'authorization_assign_legacy_role') && str_contains($userSave, 'authorization_assign_roles'), 'registration compatibility and User Management synchronize role assignments');
$check(str_contains($registration, '$roleChange') && strpos($registration, '$pdo->commit();') < strpos($registration, "do_action('authorization_user_roles_changed'"), 'registration emits its caller-owned role hook only after outer commit');
$check(str_contains($userSave, 'authorization_assign_roles($pdo, $targetUserId, $selectedRoleIds, $uid, $roleChange)') && strpos($userSave, '$pdo->commit();') < strpos($userSave, "do_action('authorization_user_roles_changed'"), 'User Management dispatches helper snapshots only after its outer commit');
$check(str_contains($userBulk, 'authorization_assign_roles($pdo, $targetId, [$newRoleId], $uid, $roleChange)') && strpos($userBulk, '$pdo->commit();', strpos($userBulk, "if (\$action === 'change_role')")) < strpos($userBulk, "do_action('authorization_user_roles_changed'"), 'bulk role changes dispatch each helper snapshot only after the batch commit');
$check(strpos($dashboard, "current_user_can(\$pdo, 'core.dashboard.access')") < strpos($dashboard, 'plugin_load_active()'), 'plugin code loads only after dashboard authorization');
$check(str_contains($userIndex, 'js-site-owner') && str_contains($userIndex, 'siteOwnerPassword'), 'User Management exposes password-confirmed Site Owner controls');
$check(!str_contains($userIndex, "img, bio, phone") && !str_contains($userIndex, "_e('Bio')"), 'User Management omits biography from the operational list');
$check(str_contains($userIndex, "const storageKey = 'users_columns'") && str_contains($userIndex, 'data-col="col-user-status"'), 'User Management persists optional column visibility');
$check(str_contains($userIndex, 'user-actions-menu') && str_contains($userIndex, 'user-actions-toggle') && str_contains($dashboardCss, '.user-actions-menu'), 'User Management uses a non-overlapping overflow action menu');
$check(str_contains($userIndex, 'user-status-badge is-active') && str_contains($userIndex, 'user-status-badge is-locked'), 'User Management uses concise account status badges');
$check(str_contains($dashboardCss, '.user-identity') && str_contains($dashboardCss, '.user-owner-badge'), 'User Management combines avatar, identity, and Site Owner state cleanly');
$check(str_contains($siteOwnerEndpoint, "['is_site_owner'] !== true") && str_contains($siteOwnerEndpoint, 'password_verify'), 'Site Owner mutation requires an existing Site Owner and current password');
$check(str_contains($siteOwnerEndpoint, 'site_owner_reauth_blocked_until') && str_contains($siteOwnerEndpoint, 'usleep(250000)'), 'Site Owner password confirmation is throttled');
$check(str_contains($siteOwnerEndpoint, 'authorization_set_site_owner') && str_contains($siteOwnerEndpoint, "targetId === \$uid"), 'Site Owner mutation uses the atomic policy and blocks self-demotion');
$check(substr_count($authorizationHelper, "do_action('authorization_user_roles_changed'") >= 4 && str_contains($authorizationHelper, 'Nested callers receive $roleChange'), 'role assignment, legacy roles, Site Owner transitions, and recovery define post-commit hook semantics');
$siteOwnerHelperSource = substr($authorizationHelper, (int)strpos($authorizationHelper, "if (!function_exists('authorization_set_site_owner')"), (int)strpos($authorizationHelper, "if (!function_exists('authorization_audit')") - (int)strpos($authorizationHelper, "if (!function_exists('authorization_set_site_owner')"));
$recoveryHelperSource = substr($authorizationHelper, (int)strpos($authorizationHelper, "if (!function_exists('authorization_recover_site_owner')"));
$check(strpos($siteOwnerHelperSource, '$pdo->commit();') < strpos($siteOwnerHelperSource, "do_action('authorization_user_roles_changed'"), 'Site Owner grant and revoke emit role changes only after the helper final commit');
$check(strpos($recoveryHelperSource, '$pdo->commit();') < strpos($recoveryHelperSource, "do_action('authorization_user_roles_changed'"), 'Site Owner recovery emits role changes only after its final commit');
$check(!str_contains($recovery, "cfg/helpers/hooks.php") && str_contains($recovery, 'hooks require listeners registered in-process'), 'CLI Site Owner recovery documents that it does not bootstrap plugin hook listeners');
$check(str_contains($userSave, "SELECT id, is_site_owner FROM users") && str_contains($userSave, 'FOR UPDATE'), 'user edits recheck Site Owner protection inside the mutation transaction');
$check(str_contains($userIndex, 'canMutateUser') && str_contains($userIndex, '$hasUserActions = $canEditUser'), 'ordinary administrators do not receive Site Owner account mutation controls');
$check(str_contains($profile, 'profile_reauth_blocked_until') && str_contains($profile, "name=\"current_password\""), 'profile password and email changes require throttled current-password verification');
$check(str_contains($profile, 'currentUserIsSiteOwner') && str_contains($profile, 'A Site Owner cannot delete their own account.'), 'Profile blocks Site Owner self-deletion in UI and server handling');
$check(str_contains($userBinRestore, 'actorIsSiteOwner') && str_contains($userBinPurge, 'actorIsSiteOwner'), 'trashed Site Owner accounts remain protected from ordinary administrators');
$check(str_contains($translations, "'Change Site Owner access'") && str_contains($translations, "'The final active Site Owner cannot be revoked.'") && str_contains($translations, "'Site Owner is the highest-trust account."), 'Site Owner management strings have translation seeds');
$check(str_contains($roleManager, "['is_site_owner'] !== true") && str_contains($roleManager, 'role_permissions'), 'Role Manager is Site Owner-only and persists permission grants');
$check(str_contains($roleManager, "['own', 'same_or_lower', 'any']") && !str_contains($roleManager, 'return confirm('), 'Role Manager validates scopes and avoids native confirmation dialogs');
$check(str_contains($roleManager, 'Not yet available for custom roles') && str_contains($roleManager, '_assignable'), 'Role Manager disables Core permissions whose routes are not migrated');
$check(str_contains($roleManager, 'is_delegable') && str_contains($roleManager, "authorization_role_permissions_changed"), 'Role Manager shows nondelegable permissions without custom assignment and emits lifecycle hooks after commits');
$roleDeleteSource = substr($roleManager, (int)strpos($roleManager, "if (\$action === 'delete'"), (int)strpos($roleManager, "if (\$action === 'save'") - (int)strpos($roleManager, "if (\$action === 'delete'"));
$check(
    str_contains($roleDeleteSource, 'SELECT user_id FROM user_roles')
    && str_contains($roleDeleteSource, 'FOR UPDATE')
    && str_contains($roleDeleteSource, "'affected_user_ids' => \$affectedUserIds")
    && str_contains($roleDeleteSource, "'old_role_ids'")
    && str_contains($roleDeleteSource, "'new_role_ids'")
    && str_contains($roleDeleteSource, "do_action('authorization_user_roles_changed'"),
    'Role deletion locks active assignments and carries affected user IDs with old/new role snapshots'
);
$check(strpos($roleDeleteSource, '$pdo->commit();') < strpos($roleDeleteSource, "do_action('authorization_user_roles_changed'"), 'Role deletion emits affected user role hooks only after successful commit');
$check(str_contains($authorizationHelper, 'function authorization_roles') && str_contains($authorizationHelper, "authorization_user_roles_changed"), 'authorization exposes the role catalog and role-assignment lifecycle action');
$check(str_contains($authorizationHelper, "=== 'mysql' ? ' FOR UPDATE' : ''") && str_contains($authorizationHelper, "SELECT id FROM users WHERE id = :id LIMIT 1") && str_contains($authorizationHelper, "ORDER BY role_id' . \$lock"), 'role assignment locks the target and assignment snapshot on MySQL while remaining SQLite-compatible');
$check(str_contains($roleHelper, 'authorization_active_legacy_role'), 'public compatibility role checks honor active dynamic assignments');
$check(str_contains($authorizationHelper, 'authorization_owner_scope_condition'), 'authorization exposes a reusable owner-scoped list condition');
$check(
    !str_contains($categoryRoutes, 'adiwira_require_role')
    && !str_contains($categoryRoutes, 'adiwira_require_admin')
    && str_contains($categoryRoutes, 'core.categories.read')
    && str_contains($categoryRoutes, 'core.categories.create')
    && str_contains($categoryRoutes, 'core.categories.update')
    && str_contains($categoryRoutes, 'core.categories.trash')
    && str_contains($categoryRoutes, 'core.categories.restore')
    && str_contains($categoryRoutes, 'core.categories.purge'),
    'category routes use action permissions instead of legacy roles'
);
$check(
    str_contains($roleManager, "'core.categories.read'")
    && str_contains($roleManager, "'core.categories.purge'"),
    'Role Manager exposes the migrated category permission family'
);
$check(
    !str_contains($categoryRoutes, 'LEFT JOIN posts')
    && !str_contains($categoryRoutes, 'post_count'),
    'category permissions do not disclose post aggregates before post routes are migrated'
);
$check(
    str_contains($schema, "SELECT 'core.categories.update', 'own'")
    && str_contains($schema, "SELECT 'core.categories.trash', 'own'")
    && str_contains($schema, "SELECT 'core.categories.restore', 'own'")
    && str_contains($categoryOwnershipMigration, "'core.categories.trash', 'own'")
    && str_contains($categoryOwnershipMigration, "'core.categories.restore', 'own'")
    && str_contains($categoryOwnershipMigration, "`slug` = 'author'"),
    'authors can create, update, trash, and restore only their own categories while retaining category read access'
);
$check(
    str_contains($categoryRoutes, "apply_filters('admin_category_list_rows'")
    && str_contains($categoryRoutes, "do_action('admin_category_row_actions'")
    && str_contains($categoryRoutes, "do_action('admin_category_before_add_commit'")
    && str_contains($categoryRoutes, "do_action('admin_category_before_edit_commit'")
    && str_contains($categoryRoutes, "do_action('admin_category_before_trash_commit'")
    && str_contains($categoryRoutes, "do_action('admin_category_before_restore_commit'")
    && str_contains($categoryRoutes, "do_action('admin_category_before_purge_commit'")
    && substr_count($categoryRoutes, "do_action('admin_category_before_edit_commit'") >= 2
    && !str_contains($categoryRoutes, 'ct_enabled_locales')
    && !str_contains($categoryRoutes, 'content-translation'),
    'category extension contracts remain generic and Core has no Content Translation dependency'
);
$check(
    substr_count($categoryRoutes, 'authorization_lock_actor_permissions') >= 7
    && substr_count($categoryRoutes, 'authorization_lock_owner_contexts') >= 7,
    'category mutations lock actor grants and owner contexts before changing owned resources'
);
$check(
    substr_count($categoryRoutes, 'ORDER BY id') >= 2
    && substr_count($categoryRoutes, 'FOR UPDATE') >= 2,
    'category reparenting mutations lock a consistent integrity snapshot'
);
$check(
    !str_contains($postRoutes, 'adiwira_require_role')
    && !str_contains($postRoutes, 'adiwira_require_admin')
    && str_contains($postRoutes, 'core.posts.read')
    && str_contains($postRoutes, 'core.posts.create')
    && str_contains($postRoutes, 'core.posts.update')
    && str_contains($postRoutes, 'core.posts.trash')
    && str_contains($postRoutes, 'core.posts.restore')
    && str_contains($postRoutes, 'core.posts.purge')
    && str_contains($postRoutes, 'core.posts.publish')
    && str_contains($postRoutes, 'core.posts.change_owner')
    && str_contains($postRoutes, 'core.posts.change_dates')
    && str_contains($postRoutes, 'core.posts.unfiltered_html'),
    'article routes use action permissions instead of legacy roles'
);
$restrictedHtml = cms_sanitize_restricted_html('<unknown><img src="/safe" onerror="alert(1)"><script>alert(2)</script></unknown>');
$check(
    !str_contains(strtolower($restrictedHtml), 'onerror')
    && !str_contains(strtolower($restrictedHtml), '<script'),
    'restricted article HTML sanitization traverses unknown wrapper descendants'
);
$check(
    str_contains($postRoutes, "core.posts.publish")
    && str_contains($postRoutes, "!== 'draft' || \$status !== 'draft'")
    && substr_count($postRoutes, 'FOR UPDATE') >= 8,
    'published article changes and single/bulk mutations are reauthorized under row locks'
);
$check(
    str_contains($authorizationHelper, 'function authorization_lock_actor_permissions')
    && str_contains($authorizationHelper, 'function authorization_lock_owner_contexts')
    && substr_count($authorizationHelper, "\$pdo->inTransaction() ? ' FOR UPDATE' : ''") >= 4
    && substr_count($postRoutes, 'authorization_lock_actor_permissions') >= 7
    && substr_count($postRoutes, 'authorization_lock_owner_contexts') >= 7,
    'article mutations lock current actor grants and owner rank contexts'
);
$check(
    !str_contains($pageRoutes, 'adiwira_require_role')
    && !str_contains($pageRoutes, 'adiwira_require_admin')
    && str_contains($pageRoutes, 'core.pages.read')
    && str_contains($pageRoutes, 'core.pages.create')
    && str_contains($pageRoutes, 'core.pages.update')
    && str_contains($pageRoutes, 'core.pages.trash')
    && str_contains($pageRoutes, 'core.pages.restore')
    && str_contains($pageRoutes, 'core.pages.purge')
    && str_contains($pageRoutes, 'core.pages.publish')
    && str_contains($pageRoutes, 'core.pages.change_owner')
    && str_contains($pageRoutes, 'core.pages.change_dates')
    && str_contains($pageRoutes, 'core.pages.unfiltered_html'),
    'page routes use action permissions instead of legacy roles'
);
$check(
    substr_count($pageRoutes, 'authorization_lock_actor_permissions') >= 7
    && substr_count($pageRoutes, 'authorization_lock_owner_contexts') >= 6
    && substr_count($pageRoutes, 'FOR UPDATE') >= 10
    && substr_count($pageRoutes, 'shortcode_collection_layout_content_mutation') >= 4
    && str_contains($pageRoutes, "type IN ('article', 'page', 'theme')"),
    'page mutations reauthorize under locks and preserve the global content slug namespace'
);
$check(
    substr_count($postRoutes . $pageRoutes . $themeWriters, "type IN ('article', 'page', 'theme')") >= 10
    && substr_count($themeWriters, 'shortcode_collection_layout_content_mutation') >= 2
    && substr_count($themeWriters, 'FOR UPDATE') >= 2,
    'all Core content writers recheck the shared slug namespace under the content lock'
);
$check(
    substr_count($roleManager, "'core.pages.") >= 10
    && str_contains($aside, "current_user_permission_scope(\$pdo, 'core.pages.read')")
    && str_contains($aside, "current_user_can(\$pdo, 'core.pages.create')")
    && str_contains($aside, "current_user_permission_scope(\$pdo, 'core.pages.restore')"),
    'Role Manager and navigation expose the migrated page permission family'
);
$check(
    str_contains($roleManager, 'authz-role-kind')
    && str_contains($roleManager, 'Protected system role')
    && str_contains($roleManager, 'Editable custom role'),
    'Role Manager clearly distinguishes locked system roles from editable custom roles'
);
$check(
    str_contains($roleManager, 'Retained, currently unavailable to change')
    && str_contains($roleManager, '$formGrants')
    && str_contains($roleManager, 'authzPermissionSearch')
    && str_contains($roleManager, 'authzDirtyState')
    && str_contains($roleManager, 'NewNotifConfirm?.warning'),
    'Role Manager preserves grants and provides searchable guarded editing'
);
$check(
    str_contains($roleManager, 'authz-plugins-group') && str_contains($roleManager, 'authz-plugin-provider')
    && str_contains($roleManager, 'pluginProviders'),
    'Role Manager groups plugin permissions into nested accordions'
);
$check(
    str_contains($roleManager, 'authzCreateModal') && str_contains($roleManager, 'authzOpenCreateModal')
    && str_contains($roleManager, 'openCreateModal'),
    'Role Manager uses a modal dialog for creating new roles'
);
$rolesNavPosition = strpos($aside, "__('Roles & Permissions')");
$binNavPosition = strpos($aside, "__('Bin')");
$check(
    $rolesNavPosition !== false && $binNavPosition !== false && $rolesNavPosition < $binNavPosition,
    'Roles and Permissions navigation appears before Bin near Users'
);
$authNavPosition = strpos($aside, "adam_nav_active(\$requested,'admin/settings/auth')");
$shortcodeNavPosition = strpos($aside, "adam_nav_active(\$requested,'admin/shortcodes')");
$check(
    $authNavPosition !== false && $shortcodeNavPosition !== false && $authNavPosition < $shortcodeNavPosition,
    'Shortcodes navigation appears directly after Authentication'
);
$check(
    !str_contains($menuRoutes, 'adiwira_require_role')
    && !str_contains($menuRoutes, "in_array(\$role")
    && substr_count($menuRoutes, "core.menus.manage") >= 4,
    'Menu Manager routes consistently require the global menu permission'
);
$check(
    str_contains($roleManager, "'core.menus.manage'")
    && str_contains($menuRoutes, "type='article' AND status='published'")
    && str_contains($menuRoutes, "type='page' AND status='published'"),
    'Menu Manager is assignable without exposing unpublished source content'
);
$check(
    !str_contains($sidebarRoutes, 'adiwira_require_role')
    && !str_contains($sidebarRoutes, 'adiwira_require_admin')
    && substr_count($sidebarRoutes, "core.sidebar.manage") >= 4
    && str_contains($roleManager, "'core.sidebar.manage'"),
    'sidebar settings and zone routes consistently use the assignable sidebar permission'
);
$check(
    !str_contains($settingsRoutes, 'adiwira_require_admin')
    && substr_count($settingsRoutes, "core.settings.manage") === 4
    && str_contains($roleManager, "'core.settings.manage'")
    && str_contains($authSettingsRoute, 'adiwira_require_site_owner')
    && str_contains($emailSettingsRoute, 'adiwira_require_site_owner')
    && str_contains($emailTestRoute, 'adiwira_require_site_owner'),
    'site settings are assignable while authentication and mail delivery controls remain Site Owner-only'
);
$check(
    str_contains($profile, "adiwira_require_permission(\$pdo, 'core.profile.manage'")
    && str_contains($roleManager, "'core.profile.manage'"),
    'Profile uses its assignable Core permission'
);
$check(
    !str_contains($pluginManagerRoutes, 'adiwira_require_role')
    && !str_contains($pluginManagerRoutes, 'adiwira_require_admin')
    && substr_count($pluginManagerRoutes, "core.plugins.manage") === 6
    && substr_count($pluginManagerRoutes, 'adiwira_require_site_owner') === 6
    && !str_contains($roleManager, "'core.plugins.manage'"),
    'code-executing plugin management remains Site Owner-only'
);
$check(
    !str_contains($updateManagerRoutes, 'adiwira_require_role')
    && !str_contains($updateManagerRoutes, 'adiwira_require_admin')
    && substr_count($updateManagerRoutes, "core.updates.manage") === 3
    && substr_count($updateManagerRoutes, 'adiwira_require_site_owner') === 3
    && !str_contains($roleManager, "'core.updates.manage'"),
    'code-replacing Core update routes remain Site Owner-only'
);
$check(
    str_contains($sidebarRoutes, '$canManageRawHtml')
    && str_contains($sidebarRoutes, '$delegatedConfigurableTypes')
    && str_contains($sidebarRoutes, 'array_intersect_key($widget_types')
    && str_contains($sidebarRoutes, "\$config['html'] = (string)(\$existingConfig['html'] ?? '')")
    && str_contains($sidebarRoutes, "\$type = (string)(\$currentItemsById[\$wid]['type'] ?? '')")
    && str_contains($sidebarRoutes, '$preserveWidgetConfig')
    && str_contains($sidebarRoutes, "? !empty(\$currentItemsById[\$wid]['active'])")
    && str_contains($sidebarRoutes, "unset(\$config['html'])"),
    'delegated sidebar managers can configure only the Core-safe widget set'
);
$check(
    str_contains($menuHelper, 'menu_url_is_safe')
    && str_contains($menuHelper, "['http', 'https', 'mailto', 'tel']")
    && str_contains($menuHelper, 'menu_url_is_safe($manualUrl)')
    && str_contains($menuRoutes, 'menu_url_is_safe')
    && str_contains($menuRoutes, "\$item['translations']"),
    'menu custom links reject active URL schemes on write and render'
);
$check(
    str_contains($dashboardHome, "core.dashboard.stats.read")
    && str_contains($dashboardWidgets, "core.dashboard.stats.read")
    && str_contains($dashboardLayoutSave, "core.dashboard.layout.manage")
    && str_contains($dashboardHeader, '$canCheckUpdates')
    && str_contains($roleManager, "'core.dashboard.stats.read'")
    && str_contains($roleManager, "'core.dashboard.layout.manage'"),
    'dashboard metadata, layout mutation, and update UI use dedicated permissions'
);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if ($failures !== []) {
        fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
        exit(1);
    }
    echo "SKIP behavior matrix requires pdo_sqlite; static authorization contracts passed\n";
    exit(0);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    email TEXT NOT NULL,
    username TEXT,
    name TEXT,
    role TEXT NOT NULL,
    is_site_owner INTEGER NOT NULL DEFAULT 0,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    is_locked INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT
)");
$pdo->exec("CREATE TABLE roles (
    id INTEGER PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    authority_rank INTEGER NOT NULL,
    is_system INTEGER NOT NULL DEFAULT 0
)");
$pdo->exec("CREATE TABLE permissions (
    permission_key TEXT PRIMARY KEY,
    provider TEXT NOT NULL,
    resource TEXT NOT NULL,
    action TEXT NOT NULL,
    label TEXT NOT NULL,
    supports_scope INTEGER NOT NULL DEFAULT 0,
    is_delegable INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1
)");
$pdo->exec("CREATE TABLE user_roles (
    user_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL,
    assigned_by INTEGER,
    assigned_at TEXT,
    expires_at TEXT,
    PRIMARY KEY (user_id, role_id)
)");
$pdo->exec("CREATE TABLE role_permissions (
    role_id INTEGER NOT NULL,
    permission_key TEXT NOT NULL,
    scope TEXT NOT NULL,
    PRIMARY KEY (role_id, permission_key)
)");
$pdo->exec("CREATE TABLE authorization_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER,
    subject_user_id INTEGER,
    event_key TEXT NOT NULL,
    resource_type TEXT,
    resource_id TEXT,
    metadata TEXT
)");

$pdo->exec("INSERT INTO roles (id,slug,name,description,authority_rank,is_system) VALUES
    (1,'author','Author','Author role',10,1),
    (2,'editor','Editor','Editor role',50,1),
    (3,'admin','Administrator','Admin role',100,1),
    (4,'reviewer','Reviewer','Custom role',5,0)");
$pdo->exec("INSERT INTO users (id,email,username,name,role,is_site_owner,is_deleted,is_locked) VALUES
    (1,'owner@example.test','owner','Owner','admin',1,0,0),
    (2,'author@example.test','author','Author','author',0,0,0),
    (3,'editor@example.test','editor','Editor','editor',0,0,0),
    (4,'admin@example.test','admin','Admin','admin',0,0,0),
    (5,'locked@example.test','locked','Locked','admin',0,0,1),
    (6,'peer@example.test','peer','Peer','editor',0,0,0),
    (7,'reviewer@example.test','reviewer','Reviewer','none',0,0,0)");
$pdo->exec("INSERT INTO user_roles (user_id,role_id) VALUES (1,3),(2,1),(3,2),(4,3),(5,3),(6,2),(7,4)");
$pdo->exec("INSERT INTO permissions (permission_key,provider,resource,action,label,supports_scope,is_delegable,is_active) VALUES
    ('core.posts.update','core','posts','update','Update posts',1,1,1),
    ('core.settings.manage','core','settings','manage','Manage settings',0,1,1),
    ('core.disabled','core','disabled','read','Disabled permission',0,1,0),
    ('plugin.example.jobs.run','example','jobs','run','Run jobs',0,0,1)");
$pdo->exec("INSERT INTO role_permissions (role_id,permission_key,scope) VALUES
    (1,'core.posts.update','own'),
    (2,'core.posts.update','same_or_lower'),
    (3,'core.posts.update','any'),
    (3,'core.settings.manage','global'),
    (3,'plugin.example.jobs.run','global'),
    (4,'plugin.example.jobs.run','global')");

$check(user_can($pdo, 1, 'core.settings.manage'), 'Site Owner can use a registered permission');
$check(!user_can($pdo, 1, 'core.unknown.manage'), 'Site Owner cannot bypass an unknown permission');
$check(!user_can($pdo, 1, 'core.disabled'), 'Site Owner cannot bypass an inactive permission');
$check(!user_can($pdo, 1, '../invalid'), 'invalid permission keys fail closed');

$check(user_can($pdo, 2, 'core.posts.update', ['owner_id' => 2]), 'own scope accepts the actor resource');
$check(!user_can($pdo, 2, 'core.posts.update', ['owner_id' => 3]), 'own scope rejects another owner');
$check(!user_can($pdo, 2, 'core.posts.update', ['owner_id' => 0]), 'own scope rejects null ownership');
$check(!user_can($pdo, 2, 'core.posts.update'), 'scoped own grants cannot authorize a context-free request');

$check(user_can($pdo, 3, 'core.posts.update', ['owner_id' => 2]), 'same_or_lower accepts a lower-ranked owner');
$check(user_can($pdo, 3, 'core.posts.update', ['owner_id' => 6]), 'same_or_lower accepts a peer owner');
$check(!user_can($pdo, 3, 'core.posts.update', ['owner_id' => 4]), 'same_or_lower rejects a higher-ranked owner');
$check(!user_can($pdo, 3, 'core.posts.update', ['owner_id' => 1]), 'same_or_lower cannot target a Site Owner');
$check(!user_can($pdo, 3, 'core.posts.update'), 'scoped same-or-lower grants cannot authorize a context-free request');
$check(user_can($pdo, 4, 'core.posts.update', ['owner_id' => 0]), 'any scope accepts null ownership');
$check(user_can($pdo, 4, 'core.posts.update'), 'any scope can authorize a collection route');
$check(!user_can($pdo, 5, 'core.settings.manage'), 'locked users have no effective permissions');
$check(user_can($pdo, 4, 'plugin.example.jobs.run'), 'nondelegable permission accepts an active system-role grant');
$check(!user_can($pdo, 7, 'plugin.example.jobs.run'), 'nondelegable permission denies a stale custom-role grant');

$check(user_permission_scope($pdo, 1, 'core.posts.update') === 'any', 'Site Owner receives any scope for contextual permissions');
$check(user_permission_scope($pdo, 1, 'core.settings.manage') === 'global', 'Site Owner receives global scope for non-contextual permissions');
$check(user_permission_scope($pdo, 3, 'core.posts.update') === 'same_or_lower', 'effective scope reports the strongest role grant');

$pdo->exec('CREATE TABLE owned_resources (id INTEGER PRIMARY KEY, owner_id INTEGER)');
$pdo->exec('INSERT INTO owned_resources (id, owner_id) VALUES (1,2),(2,3),(3,4),(4,1),(5,NULL)');
$ownCondition = authorization_owner_scope_condition($pdo, 2, 'core.posts.update', 'owned_resources.owner_id', 'test_own');
$ownStmt = $pdo->prepare('SELECT id FROM owned_resources WHERE ' . ($ownCondition['sql'] ?? '1=0') . ' ORDER BY id');
$ownStmt->execute($ownCondition['params'] ?? []);
$ownIds = array_map('intval', $ownStmt->fetchAll(PDO::FETCH_COLUMN));
$check($ownIds === [1], 'own list condition exposes only actor-owned resources');
$lowerCondition = authorization_owner_scope_condition($pdo, 3, 'core.posts.update', 'owned_resources.owner_id', 'test_lower');
$lowerStmt = $pdo->prepare('SELECT id FROM owned_resources WHERE ' . ($lowerCondition['sql'] ?? '1=0') . ' ORDER BY id');
$lowerStmt->execute($lowerCondition['params'] ?? []);
$lowerIds = array_map('intval', $lowerStmt->fetchAll(PDO::FETCH_COLUMN));
$check($lowerIds === [1, 2], 'same-or-lower list condition rejects higher-ranked, Site Owner, and orphaned resources');

$catalog = authorization_roles($pdo);
$check(array_column($catalog, 'slug') === ['admin', 'editor', 'author', 'reviewer'], 'authorization role catalog is stable by authority and name');
$check(($catalog[0]['id'] ?? null) === 3 && ($catalog[0]['is_system'] ?? null) === true, 'authorization role catalog exposes normalized plugin-useful fields');

$roleChangeHooks = [];
add_action('authorization_user_roles_changed', static function (int $userId, array $oldRoleIds, array $newRoleIds, ?int $actorId) use (&$roleChangeHooks): void {
    $roleChangeHooks[] = [$userId, $oldRoleIds, $newRoleIds, $actorId];
});
$check(authorization_assign_roles($pdo, 2, [1, 2], 1), 'role assignment accepts valid dynamic role IDs');
$check($roleChangeHooks === [[2, [1], [1, 2], 1]], 'successful role assignment emits old/new role IDs and actor after commit');
$assigned = $pdo->query('SELECT role_id FROM user_roles WHERE user_id = 2 ORDER BY role_id')->fetchAll(PDO::FETCH_COLUMN);
$check($assigned === ['1', '2'] || $assigned === [1, 2], 'role assignment replaces the user role set atomically');
$check($roleChangeHooks[0][2] === array_map('intval', $assigned), 'role assignment hook new snapshot matches committed final assignments');
$check(!authorization_assign_roles($pdo, 2, [999], 1), 'role assignment rejects unknown roles');
$check(count($roleChangeHooks) === 1, 'failed role assignment does not emit a lifecycle hook');
$assignedAfterFailure = $pdo->query('SELECT role_id FROM user_roles WHERE user_id = 2 ORDER BY role_id')->fetchAll(PDO::FETCH_COLUMN);
$check($assignedAfterFailure === $assigned, 'failed role assignment preserves existing roles');
$pdo->beginTransaction();
$nestedRejected = authorization_assign_roles($pdo, 2, [999], 1);
$pdo->commit();
$assignedAfterNestedFailure = $pdo->query('SELECT role_id FROM user_roles WHERE user_id = 2 ORDER BY role_id')->fetchAll(PDO::FETCH_COLUMN);
$check(!$nestedRejected && $assignedAfterNestedFailure === $assigned, 'invalid nested role assignment does not mutate caller-owned transactions');

$legacyChange = null;
$check(authorization_assign_legacy_role($pdo, 2, 'admin', 1, $legacyChange), 'self-owned legacy role assignment commits successfully');
$legacyAssigned = array_map('intval', $pdo->query('SELECT role_id FROM user_roles WHERE user_id = 2 ORDER BY role_id')->fetchAll(PDO::FETCH_COLUMN));
$check($legacyAssigned === [3] && ($legacyChange['new_role_ids'] ?? null) === [3], 'legacy role helper returns and commits its final role snapshot');
$check(count($roleChangeHooks) === 2 && $roleChangeHooks[1] === [2, [1, 2], [3], 1], 'self-owned legacy role assignment emits exactly once after commit');
$pdo->beginTransaction();
$nestedLegacyChange = null;
$nestedLegacyOk = authorization_assign_legacy_role($pdo, 2, 'author', 1, $nestedLegacyChange);
$pdo->rollBack();
$check($nestedLegacyOk && ($nestedLegacyChange['new_role_ids'] ?? null) === [1] && count($roleChangeHooks) === 2, 'caller-owned legacy assignment returns a snapshot without emitting before rollback');
$check(array_map('intval', $pdo->query('SELECT role_id FROM user_roles WHERE user_id = 2 ORDER BY role_id')->fetchAll(PDO::FETCH_COLUMN)) === [3], 'rolled-back caller-owned legacy assignment leaves committed roles unchanged');

$check(
    authorization_audit($pdo, 'role.assigned', 1, 2, 'user', '2', ['roles' => [1, 2]]),
    'authorization changes can be audited'
);
$check((int)$pdo->query('SELECT COUNT(*) FROM authorization_audit_log')->fetchColumn() === 1, 'authorization audit entry is stored');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}

echo "RESULT: ALL PASS\n";
