<?php
declare(strict_types=1);

define('BACKEND_PATH', dirname(__DIR__) . '/cfg');
require_once dirname(__DIR__) . '/cfg/helpers/hooks.php';
require_once dirname(__DIR__) . '/cfg/helpers/authorization.php';
require_once dirname(__DIR__) . '/plugins/index.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) {
        echo "PASS {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "FAIL {$message}\n";
};

$valid = [
    'name' => 'example',
    'permissions' => [
        [
            'key' => 'plugin.example.settings.access',
            'label' => 'Access Example Settings',
            'supports_scope' => false,
        ],
        [
            'key' => 'plugin.example.jobs.run',
            'label' => 'Run Example Jobs',
            'supports_scope' => false,
            'default_roles' => ['editor', 'admin', 'editor'],
            'delegable' => false,
        ],
    ],
    'admin' => [
        'pages' => [[
            'route' => 'admin/tools/example',
            'file' => 'admin/index.php',
            'permission' => 'plugin.example.settings.access',
            'roles' => ['admin'],
        ]],
        'nav' => [],
    ],
];
$contract = plugin_manifest_contract($valid);
$check($contract['errors'] === [], 'valid plugin-owned permission contract passes');
$check(isset($contract['permissions']['plugin.example.settings.access']), 'permission declarations normalize by key');
$check(($contract['route_roles']['plugin.example.settings.access'] ?? null) === ['admin'], 'route compatibility roles normalize for grant seeding');
$check(($contract['permissions']['plugin.example.settings.access']['default_roles'] ?? null) === ['admin'], 'permissions without explicit defaults derive identical route compatibility roles');
$check(($contract['permissions']['plugin.example.jobs.run']['default_roles'] ?? null) === ['admin', 'editor'], 'non-route permission defaults normalize uniquely and remain declared');
$check(($contract['permissions']['plugin.example.jobs.run']['is_delegable'] ?? true) === false, 'permission delegability normalizes from the manifest');

$conflictingDefaults = $valid;
$conflictingDefaults['permissions'][0]['default_roles'] = ['editor'];
$check(plugin_manifest_contract_errors($conflictingDefaults) !== [], 'explicit permission defaults cannot conflict with route compatibility roles');

$invalidDelegability = $valid;
$invalidDelegability['permissions'][0]['delegable'] = 0;
$check(plugin_manifest_contract_errors($invalidDelegability) !== [], 'permission delegability must be boolean');

$wrongOwner = $valid;
$wrongOwner['permissions'][0]['key'] = 'plugin.other.settings.access';
$wrongOwner['admin']['pages'][0]['permission'] = 'plugin.other.settings.access';
$check(plugin_manifest_contract_errors($wrongOwner) !== [], 'another provider permission prefix is rejected');

$coreKey = $valid;
$coreKey['permissions'][0]['key'] = 'core.settings.manage';
$coreKey['admin']['pages'][0]['permission'] = 'core.settings.manage';
$check(plugin_manifest_contract_errors($coreKey) !== [], 'core permission ownership cannot be claimed');

$scopedRoute = $valid;
$scopedRoute['permissions'][0]['supports_scope'] = true;
$check(plugin_manifest_contract_errors($scopedRoute) !== [], 'scoped permissions cannot guard a route without resource context');

$ownerAndPermission = $valid;
$ownerAndPermission['admin']['pages'][0]['site_owner'] = true;
$check(plugin_manifest_contract_errors($ownerAndPermission) !== [], 'Site Owner and delegable permission guards cannot be combined');

$traversal = $valid;
$traversal['admin']['pages'][0]['file'] = '../dashboard/index.php';
$check(plugin_manifest_contract_errors($traversal) !== [], 'plugin route file traversal is rejected');

$inconsistent = $valid;
$inconsistent['admin']['pages'][] = [
    'route' => 'admin/tools/example/save',
    'file' => 'admin/save.php',
    'permission' => 'plugin.example.settings.access',
    'roles' => ['editor', 'admin'],
];
$check(plugin_manifest_contract_errors($inconsistent) !== [], 'shared route permissions require identical compatibility roles');

$legacy = [
    'name' => 'legacy',
    'admin' => [
        'pages' => [['route' => 'admin/tools/legacy', 'file' => 'admin/index.php', 'roles' => ['admin']]],
        'nav' => [],
    ],
];
$check(plugin_manifest_contract_errors($legacy) === [], 'legacy role-only plugin manifests remain valid');

$ownerOnly = [
    'name' => 'owner-tool',
    'admin' => [
        'pages' => [['route' => 'admin/tools/owner-tool', 'file' => 'admin/index.php', 'site_owner' => true, 'roles' => ['admin']]],
        'nav' => [['page' => 'admin/tools/owner-tool', 'site_owner' => true, 'roles' => ['admin']]],
    ],
];
$check(plugin_manifest_contract_errors($ownerOnly) === [], 'Site Owner-only plugin routes and navigation are valid');

$longProvider = $valid;
$longProvider['name'] = str_repeat('a', 101);
$longProvider['permissions'][0]['key'] = 'plugin.' . $longProvider['name'] . '.settings.access';
$longProvider['admin']['pages'][0]['permission'] = $longProvider['permissions'][0]['key'];
$check(plugin_manifest_contract_errors($longProvider) !== [], 'plugin providers cannot exceed the database column limit');

$uppercaseLegacy = $legacy;
$uppercaseLegacy['name'] = 'LegacyPlugin';
$check(plugin_manifest_contract_errors($uppercaseLegacy) === [], 'legacy role-only manifests retain uppercase-name compatibility');

$coreCollision = $legacy;
$coreCollision['name'] = 'core-collision';
$coreCollision['admin']['pages'][0]['route'] = 'admin/plugins/index';
$coreErrors = plugin_route_collision_errors($coreCollision);
$check($coreErrors !== [] && str_contains(implode(' ', $coreErrors), 'Core dashboard route'), 'plugin routes cannot shadow Core dashboard files');
$check(plugin_install_requirements_error_message($coreCollision, false) !== '', 'install preflight rejects Core route collisions');

$collisionA = [
    'name' => 'collision-a',
    'version' => '1.0.0',
    'admin' => ['pages' => [['route' => 'admin/tools/shared', 'file' => 'admin/index.php', 'roles' => ['admin']]], 'nav' => []],
];
$collisionB = $collisionA;
$collisionB['name'] = 'collision-b';
$crossErrors = plugin_route_collision_errors($collisionA, ['collision-b' => $collisionB]);
$check($crossErrors !== [] && str_contains(implode(' ', $crossErrors), 'collision-b'), 'cross-plugin route collisions identify the conflicting plugin');
$check(plugin_route_collision_errors($collisionA, ['collision-a' => $collisionA]) === [], 'same-plugin replacement retains its existing routes');

$dependent = [
    'name' => 'collision-dependent',
    'version' => '1.0.0',
    'requires' => ['plugins' => ['collision-a' => '>=1.0.0']],
];
$collisionResolution = plugin_resolve_active_plugins([
    'collision-a' => $collisionA,
    'collision-b' => $collisionB,
    'collision-dependent' => $dependent,
], []);
$check($collisionResolution['active'] === [], 'activation fails closed for colliding plugins and their dependents');
$check(isset($collisionResolution['diagnostics']['collision-a'], $collisionResolution['diagnostics']['collision-b']), 'both cross-plugin route owners receive diagnostics');
$check(isset($collisionResolution['diagnostics']['collision-dependent']), 'route-collision failures cascade to dependent plugins');

$registry = (string)file_get_contents(dirname(__DIR__) . '/plugins/index.php');
$dashboard = (string)file_get_contents(dirname(__DIR__) . '/dashboard/index.php');
$aside = (string)file_get_contents(dirname(__DIR__) . '/dashboard/theme/adiwira/part/aside.php');
$check(str_contains($registry, 'adiwira_require_site_owner($pdo, $asJson)'), 'plugin router enforces Site Owner metadata');
$check(str_contains($registry, 'adiwira_require_permission($pdo, $permission, $asJson)'), 'plugin router enforces dynamic permission metadata');
$check(substr_count($registry, "apply_filters('plugin_page_roles'") >= 2, 'permission routes preserve role-filter tightening compatibility');
$check(str_contains($registry, "['_plugin_ready_permissions']"), 'permission routes fail closed until synchronization succeeds');
$check(str_contains($registry, 'plugin_route_collision_errors($manifest, plugins_all())'), 'install and update preflight checks installed route ownership');
$check(str_contains($registry, "\$GLOBALS['_plugin_current_route'] = \$route") && str_contains($registry, "'route' => \$route"), 'plugin guards expose authoritative resolved route metadata');
$loadAt = strpos($dashboard, 'plugin_load_active();');
$syncAt = strpos($dashboard, 'plugin_sync_permissions($pdo);');
$initAt = strpos($dashboard, "do_action('admin_init');");
$check($loadAt !== false && $syncAt !== false && $initAt !== false && $loadAt < $syncAt && $syncAt < $initAt, 'dashboard synchronizes permissions after plugin loading and before admin_init');
$check(substr_count($aside, 'plugin_route_is_allowed($pdo,') === 3, 'all three plugin navigation locations use route authorization');
$check(!str_contains($aside, 'in_array($userRole, $pnRoles') && !str_contains($aside, 'in_array($userRole, $roles'), 'plugin navigation no longer duplicates legacy role guards');

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE permissions (permission_key TEXT PRIMARY KEY, provider TEXT NOT NULL, resource TEXT NOT NULL, action TEXT NOT NULL, label TEXT NOT NULL, supports_scope INTEGER NOT NULL DEFAULT 0, is_delegable INTEGER NOT NULL DEFAULT 1, is_active INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, slug TEXT NOT NULL, is_system INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE role_permissions (role_id INTEGER NOT NULL, permission_key TEXT NOT NULL, scope TEXT NOT NULL DEFAULT "global", PRIMARY KEY (role_id, permission_key), FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE, FOREIGN KEY (permission_key) REFERENCES permissions (permission_key) ON DELETE CASCADE)');
    $pdo->exec("INSERT INTO roles (id, slug, is_system) VALUES (1, 'author', 1), (2, 'editor', 1), (3, 'admin', 1), (4, 'custom', 0)");
    $pdo->exec("INSERT INTO permissions (permission_key, provider, resource, action, label, supports_scope, is_delegable, is_active) VALUES ('plugin.example.jobs.run', 'example', 'jobs', 'run', 'Old Job Label', 0, 1, 1)");
    $pdo->exec("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (4, 'plugin.example.jobs.run', 'global')");

    $syncHooks = [];
    add_action('plugin_permissions_synced', static function (array $policy, ?int $actorId) use (&$syncHooks): void {
        $syncHooks[] = [$policy, $actorId];
    });

    $GLOBALS['_plugin_active_cache'] = ['example' => $valid];
    $check(plugin_sync_permissions($pdo) === [], 'active plugin permissions synchronize');
    $check((int)$pdo->query("SELECT is_active FROM permissions WHERE permission_key = 'plugin.example.settings.access'")->fetchColumn() === 1, 'synchronized permission is active');
    $check((int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role_id = 3 AND permission_key = 'plugin.example.settings.access'")->fetchColumn() === 1, 'compatibility admin grant is seeded');
    $check((int)$pdo->query("SELECT is_delegable FROM permissions WHERE permission_key = 'plugin.example.jobs.run'")->fetchColumn() === 0, 'existing plugin permission delegability is intentionally updateable');
    $check((int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role_id IN (2,3) AND permission_key = 'plugin.example.jobs.run'")->fetchColumn() === 2, 'non-route permission defaults seed system-role grants');
    $check((int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role_id = 4 AND permission_key = 'plugin.example.jobs.run'")->fetchColumn() === 0, 'nondelegable permission synchronization deletes stale custom-role grants');
    $check(count($syncHooks) === 1 && isset($syncHooks[0][0]['old']['plugin.example.jobs.run'], $syncHooks[0][0]['new']['plugin.example.jobs.run']), 'plugin sync hook fires after commit with old/new permission policy');
    $permissionCountBeforeNestedSync = (int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
    $pdo->beginTransaction();
    $nestedSyncResult = plugin_sync_permissions($pdo);
    $check($nestedSyncResult !== [] && $pdo->inTransaction(), 'plugin permission sync rejects caller-owned transactions without ending them');
    $check($GLOBALS['_plugin_ready_permissions'] === [] && count($syncHooks) === 1, 'rejected nested plugin sync fails closed without ready state or hooks');
    $check((int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn() === $permissionCountBeforeNestedSync, 'rejected nested plugin sync does not mutate permissions');
    $pdo->rollBack();
    $pdo->exec("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (4, 'plugin.example.settings.access', 'global')");
    $check(plugin_sync_permissions($pdo) === [], 'delegable active permission resynchronizes');
    $check((int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role_id = 4 AND permission_key = 'plugin.example.settings.access'")->fetchColumn() === 1, 'delegable active permission preserves custom-role grants');

    $pdo->exec("UPDATE permissions SET resource = 'conflict' WHERE permission_key = 'plugin.example.settings.access'");
    $hookCountBeforeFailure = count($syncHooks);
    $check(plugin_sync_permissions($pdo) !== [], 'plugin permission semantic ownership and scope conflicts fail closed');
    $check(count($syncHooks) === $hookCountBeforeFailure, 'plugin sync hook does not fire on rollback');
    $pdo->exec("UPDATE permissions SET resource = 'settings' WHERE permission_key = 'plugin.example.settings.access'");

    $GLOBALS['_plugin_active_cache'] = [];
    $check(plugin_sync_permissions($pdo) === [], 'uninstalled plugin permissions reconcile');
    $check((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key = 'plugin.example.settings.access'")->fetchColumn() === 0, 'uninstalled plugin permission is deleted');
    $check((int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role_id = 4 AND permission_key = 'plugin.example.settings.access'")->fetchColumn() === 0, 'uninstalled plugin grants are deleted');
    $check(count($syncHooks) === $hookCountBeforeFailure + 1, 'plugin sync hook fires for successful uninstall reconciliation');
    $directRoute = ['route' => 'admin/tools/example/direct', 'roles' => []];
    plugin_guard_route($pdo, $directRoute);
    $check(($GLOBALS['_plugin_current_route']['route'] ?? null) === 'admin/tools/example/direct', 'plugin guard publishes route identity for direct-path repeated guards');
} else {
    echo "SKIP permission synchronization behavior requires pdo_sqlite\n";
}

if ($failures !== []) {
    fwrite(STDERR, 'Plugin authorization contract failed: ' . implode('; ', array_unique($failures)) . "\n");
    exit(1);
}

echo "RESULT: ALL PASS\n";
