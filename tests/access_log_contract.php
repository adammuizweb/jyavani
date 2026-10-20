<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/audit_log.php';
require_once $root . '/cfg/helpers/settings_helpers.php';
require_once $root . '/cfg/helpers/access_log.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$throws = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (Throwable) {
        return true;
    }
};

$documentServer = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/adiwira/?page=admin/settings/audit&csrf_token=secret',
    'REMOTE_ADDR' => '192.0.2.30',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.30',
    'HTTP_USER_AGENT' => 'Access Contract',
    'HTTP_SEC_FETCH_MODE' => 'navigate',
    'HTTP_SEC_FETCH_DEST' => 'document',
    'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
];
$check(access_log_is_document_request($documentServer), 'ordinary GET document navigation is eligible for access logging');
$searchProjection = access_log_public_projection('main.search');
$check(!str_contains(json_encode($searchProjection, JSON_THROW_ON_ERROR), 'private-search-term')
    && ($searchProjection['page_title'] ?? null) === 'Main Search',
    'public collection projections use route labels rather than query-derived document titles');
$check(access_log_public_projection('plugin.example~report')['resource_id'] === 'plugin.example~report',
    'plugin-compatible public route identities remain loggable');
foreach ([
    array_replace($documentServer, ['REQUEST_METHOD' => 'POST']),
    array_replace($documentServer, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']),
    array_replace($documentServer, ['HTTP_SEC_FETCH_MODE' => 'cors']),
    array_replace($documentServer, ['HTTP_SEC_FETCH_DEST' => 'iframe']),
    array_replace($documentServer, ['HTTP_SEC_PURPOSE' => 'prefetch']),
] as $excludedServer) {
    $check(!access_log_is_document_request($excludedServer), 'non-document, mutation, AJAX, iframe, and prefetch requests are excluded');
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP access log runtime contract requires PDO SQLite\n";
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, username TEXT, email TEXT)');
    $pdo->exec("INSERT INTO users (id,name,username,email) VALUES (7,'Access User','access-user','access@example.test')");
    $longActor = str_repeat('é', 100);
    $actorInsert = $pdo->prepare('INSERT INTO users (id,name,username,email) VALUES (8,:name,\'long-actor\',\'long@example.test\')');
    $actorInsert->execute([':name' => $longActor]);
    $pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT, autoload INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec("INSERT INTO settings (`key`,`value`,`autoload`) VALUES
        ('audit_dashboard_navigation_enabled','1',1),
        ('audit_public_page_views_enabled','0',1),
        ('audit_access_retention_days','90',1),
        ('audit_access_last_pruned_utc','1970-01-01 00:00:00',1)");
    $pdo->exec('CREATE TABLE access_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL UNIQUE, request_id TEXT NOT NULL,
        surface TEXT NOT NULL, event_key TEXT NOT NULL, actor_user_id INTEGER, actor_label TEXT NOT NULL,
        actor_email TEXT, route TEXT NOT NULL, request_path TEXT NOT NULL, page_title TEXT,
        resource_type TEXT, resource_id TEXT, resource_label TEXT, response_status INTEGER NOT NULL,
        ip_address BLOB, user_agent_hash TEXT, occurred_at_utc TEXT NOT NULL
    )');
    unset($GLOBALS['__jy_settings_autoload_cache']);

    $entry = [
        'surface' => 'dashboard',
        'event_key' => 'dashboard.page_viewed',
        'actor_user_id' => 7,
        'route' => 'admin/settings/audit',
        'page_title' => 'Audit Log',
        'resource_type' => 'dashboard_route',
        'resource_id' => 'admin/settings/audit',
        'resource_label' => 'Audit Log',
        'response_status' => 200,
    ];
    $id = access_log_record($pdo, $entry, $documentServer);
    $row = $pdo->query('SELECT * FROM access_events WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    $check(($row['actor_label'] ?? null) === 'Access User'
        && ($row['request_path'] ?? null) === '/adiwira/'
        && !str_contains((string)($row['request_path'] ?? ''), 'csrf_token')
        && ($row['ip_address'] ?? null) === inet_pton('192.0.2.30')
        && ($row['user_agent_hash'] ?? null) === hash('sha256', 'Access Contract'),
        'access events keep actor snapshots while omitting query strings and untrusted proxy addresses');
    $longActorEntry = array_replace($entry, ['actor_user_id' => 8, 'route' => 'admin/users/index']);
    $longActorId = access_log_record($pdo, $longActorEntry, $documentServer);
    $storedLongActor = (string)$pdo->query('SELECT actor_label FROM access_events WHERE id = ' . $longActorId)->fetchColumn();
    $check(strlen($storedLongActor) <= 191 && str_ends_with($storedLongActor, '...'),
        'valid multibyte display labels are safely projected instead of dropping the access event');

    $pdo->beginTransaction();
    $check($throws(static fn() => access_log_record($pdo, $entry, $documentServer)),
        'access writes reject caller transactions instead of interfering with mutation transactions');
    $pdo->rollBack();

    $frontendEntry = [
        'surface' => 'frontend',
        'event_key' => 'frontend.page_viewed',
        'actor_user_id' => null,
        'route' => 'single.post',
        'page_title' => 'Public Article',
        'resource_type' => 'article',
        'resource_id' => '42',
        'resource_label' => 'Public Article',
        'response_status' => 200,
    ];
    $check(!access_log_try_record_document($pdo, $frontendEntry, $documentServer),
        'disabled public page-view logging performs no write');
    $pdo->exec("UPDATE settings SET value = '1' WHERE `key` = 'audit_public_page_views_enabled'");
    unset($GLOBALS['__jy_settings_autoload_cache']);
    $check(access_log_try_record_document($pdo, $frontendEntry, $documentServer),
        'enabled public page-view logging records a successful document once');
    $anonymous = $pdo->query("SELECT actor_label FROM access_events WHERE surface = 'frontend' LIMIT 1")->fetchColumn();
    $check($anonymous === 'Anonymous', 'public visitors without an authenticated session retain an anonymous actor snapshot');

    $pdo->exec("UPDATE access_events SET occurred_at_utc = '2000-01-01 00:00:00.000000' WHERE id = 1");
    $check(access_log_prune($pdo, 90, 100) === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM access_events WHERE id = 1')->fetchColumn() === 0,
        'configured retention prunes only bounded expired access events');
}

$schema = (string)file_get_contents($root . '/schema/default.sql');
$migration = (string)file_get_contents($root . '/schema/migrations/033-access-events.sql');
$config = (string)file_get_contents($root . '/cfg/config.php');
$viewer = (string)file_get_contents($root . '/dashboard/admin/settings/audit.php');
$dashboardIndex = (string)file_get_contents($root . '/dashboard/index.php');
$dashboardMain = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/main.php');
$publicLayout = (string)file_get_contents($root . '/app/layout.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$docs = (string)file_get_contents($root . '/cms.md');

$check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS `access_events`')
    && str_contains($migration, 'CREATE TABLE IF NOT EXISTS `access_events`')
    && str_contains($migration, "'audit_dashboard_navigation_enabled','0',1")
    && str_contains($migration, "'audit_public_page_views_enabled','0',1")
    && str_contains($migration, "'audit_access_retention_days','90',1")
    && str_contains($migration, "'audit_access_last_pruned_utc','1970-01-01 00:00:00',1"),
    'fresh and upgraded sites receive a separate access stream with opt-in defaults and retention');
$check(str_contains($schema, "'core.audit.manage'") && str_contains($migration, "'core.audit.manage'")
    && str_contains($migration, "'Manage audit logging',0,0,1"),
    'audit logging management uses an active nondelegable Core permission');
$check(str_contains($config, "require_once __DIR__ . '/helpers/access_log.php';")
    && strpos($config, "helpers/settings_helpers.php") < strpos($config, "helpers/access_log.php"),
    'Core loads access logging after settings support');
$check(str_contains($viewer, "user_can(\$pdo, \$uid, 'core.audit.manage')")
    && str_contains($viewer, 'authorization_lock_site_owner_actor($pdo, $uid)')
    && str_contains($viewer, 'authorization_lock_actor_permissions($pdo, $uid)')
    && str_contains($viewer, 'adiwira_csrf_validate(')
    && str_contains($viewer, "'audit.settings_changed'")
    && str_contains($viewer, "['mutations', 'access', 'settings']"),
    'Site Owner Audit Log provides guarded tabs and atomically audited settings controls');
$check(str_contains($viewer, 'class="tabs audit-page__tabs"')
    && str_contains($viewer, 'aria-current="page"')
    && str_contains($viewer, 'posts-filter-disclosure')
    && str_contains($viewer, '--posts-toolbar-control-size:38px')
    && str_contains($viewer, 'class="adam-table audit-table"')
    && str_contains($viewer, 'class="metatags-toggle"')
    && str_contains($viewer, 'data-unsaved-floating-save'),
    'Audit Log reuses responsive Adiwira tabs, filters, tables, toggles, and save behavior');
$check(str_contains($dashboardMain, "['__JY_DASHBOARD_DOCUMENT']['rendered'] = true")
    && str_contains($dashboardIndex, "'dashboard.page_viewed'")
    && strpos($dashboardIndex, "require_once DASH_PATH . '/theme/adiwira/layout.php';") < strpos($dashboardIndex, "'dashboard.page_viewed'"),
    'dashboard navigation is recorded only after an authorized route and complete layout return');
$check(str_contains($publicLayout, '$publicMainRendered = true;')
    && str_contains($publicLayout, "'frontend.page_viewed'")
    && str_contains($publicLayout, 'access_log_public_projection(')
    && str_contains($publicLayout, "'[access-log] Public projection failed:")
    && str_contains($publicLayout, "(\$context_for_layout ?? '') !== '404'"),
    'public page views are recorded only after successful main HTML rendering and exclude 404 pages');
$pruneTool = (string)file_get_contents($root . '/tools/prune-access-log.php');
$check(str_contains($pruneTool, "PHP_SAPI !== 'cli'")
    && str_contains($pruneTool, 'access_log_prune(')
    && str_contains($pruneTool, 'if ($lastCount === 5000)')
    && str_contains($viewer, "modify('-7 days')")
    && str_contains($viewer, "modify('-30 days')")
    && str_contains($viewer, "modify('-31 days')")
    && str_contains($viewer, '$totalPages = min(200,')
    && str_contains((string)file_get_contents($root . '/SERVER_SETUP.md'), 'tools/prune-access-log.php'),
    'access retention has an independent CLI worker and the high-volume viewer defaults to bounded windows');
$check(substr_count($translations, "'Record dashboard navigation'") >= 2
    && substr_count($translations, "'Record public page views'") >= 2
    && str_contains($docs, '## Access logging'),
    'access logging controls are translated and documented');

if ($failures !== []) exit(1);
echo "Access log contract passed.\n";
