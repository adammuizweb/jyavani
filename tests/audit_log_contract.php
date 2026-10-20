<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/audit_log.php';

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

$changes = audit_log_changes(
    ['title' => 'Before', 'password' => 'old', 'content' => '<p>old</p>'],
    ['title' => 'After', 'password' => 'new', 'content' => '<p>new</p>'],
    ['content']
);
$check(($changes['title']['before'] ?? null) === 'Before' && ($changes['title']['after'] ?? null) === 'After',
    'audit changes preserve bounded ordinary before and after values');
$check(($changes['password']['value'] ?? null) === '[redacted]' && ($changes['content']['value'] ?? null) === '[redacted]',
    'audit changes redact sensitive keys and explicitly protected content');
$check(audit_log_normalize_value(['csrf_token' => 'secret', 'nested' => ['api-key' => 'secret'], 'accessToken' => 'secret', 'APIKey' => 'secret', 'CSRFToken' => 'secret', 'auth' => 'secret']) === [
    'csrf_token' => '[redacted]',
    'nested' => ['api-key' => '[redacted]'],
    'accessToken' => '[redacted]',
    'APIKey' => '[redacted]',
    'CSRFToken' => '[redacted]',
    'auth' => '[redacted]',
], 'recursive audit normalization redacts credential-like fields');
$largeIdSet = audit_log_id_set(range(1, 150));
$check(($largeIdSet['count'] ?? null) === 150
    && ($largeIdSet['sha256'] ?? null) === hash('sha256', implode(',', range(1, 150)))
    && count($largeIdSet['sample'] ?? []) === 50
    && ($largeIdSet['truncated'] ?? null) === true,
    'large identifier sets use a bounded deterministic audit projection');
$check($throws(static function (): void {
    $value = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => true]]]]]]]];
    audit_log_normalize_value($value);
}), 'audit data rejects excessive nesting');

$context = audit_log_request_context([
    'REQUEST_METHOD' => 'post',
    'REQUEST_URI' => '/dashboard/?page=admin/quiz/attempts&csrf_token=secret',
    'REMOTE_ADDR' => '192.0.2.10',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
    'HTTP_USER_AGENT' => 'Contract Browser',
], ['page' => 'admin/quiz/attempts', 'csrf_token' => 'secret']);
$check(($context['request_method'] ?? null) === 'POST'
    && ($context['request_path'] ?? null) === '/dashboard/'
    && ($context['route'] ?? null) === 'admin/quiz/attempts',
    'request context retains method, path, and validated dashboard route without the query string');
$check(($context['ip_address'] ?? null) === inet_pton('192.0.2.10')
    && ($context['user_agent_hash'] ?? null) === hash('sha256', 'Contract Browser'),
    'request context uses only the direct peer address and hashes the user agent');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP audit runtime contract requires PDO SQLite\n";
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, username TEXT, email TEXT)');
    $pdo->exec("INSERT INTO users (id, name, username, email) VALUES (7, 'Audit User', 'audit-user', 'audit@example.test')");
    $pdo->exec('CREATE TABLE audit_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT NOT NULL UNIQUE,
        request_id TEXT NOT NULL,
        operation_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        event_key TEXT NOT NULL,
        actor_user_id INTEGER,
        actor_label TEXT NOT NULL,
        actor_email TEXT,
        resource_type TEXT NOT NULL,
        resource_id TEXT NOT NULL,
        resource_label TEXT,
        source TEXT NOT NULL,
        route TEXT,
        request_method TEXT,
        request_path TEXT,
        ip_address BLOB,
        user_agent_hash TEXT,
        changes TEXT,
        metadata TEXT,
        authorization_audit_id INTEGER UNIQUE,
        occurred_at_utc TEXT NOT NULL
    )');

    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/dashboard/?page=admin/posts/index',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'Runtime Contract',
    ];
    $_GET = ['page' => 'admin/posts/index'];
    $entry = [
        'provider' => 'core',
        'event_key' => 'article.status_changed',
        'actor_user_id' => 7,
        'resource_type' => 'article',
        'resource_id' => '42',
        'resource_label' => 'Contract Article',
        'source' => 'admin.posts.bulk',
        'operation_id' => audit_log_operation_id(),
        'changes' => audit_log_changes(['status' => 'draft'], ['status' => 'published']),
        'metadata' => ['bulk' => true, 'csrf_token' => 'must-not-survive'],
    ];
    $check($throws(static fn() => audit_log_record($pdo, $entry)), 'audit writes require an existing caller transaction');

    $pdo->beginTransaction();
    $auditId = audit_log_record($pdo, $entry);
    $check($auditId > 0 && $pdo->inTransaction(), 'audit write returns its ID without changing transaction ownership');
    $pdo->commit();
    $row = $pdo->query('SELECT * FROM audit_events WHERE id = ' . $auditId)->fetch(PDO::FETCH_ASSOC);
    $metadata = json_decode((string)($row['metadata'] ?? ''), true);
    $check(($row['actor_label'] ?? null) === 'Audit User' && ($row['actor_email'] ?? null) === 'audit@example.test',
        'audit event keeps a durable actor identity snapshot');
    $check(($row['route'] ?? null) === 'admin/posts/index' && ($metadata['csrf_token'] ?? null) === '[redacted]',
        'stored audit context captures the route and redacts sensitive metadata');

    $pdo->beginTransaction();
    audit_log_record($pdo, array_replace($entry, [
        'provider' => 'quiz',
        'event_key' => 'attempt.score_changed',
        'resource_type' => 'quiz_attempt',
        'resource_id' => '88',
        'source' => 'plugin.quiz',
        'operation_id' => audit_log_operation_id(),
    ]));
    $pdo->rollBack();
    $check((int)$pdo->query('SELECT COUNT(*) FROM audit_events')->fetchColumn() === 1,
        'caller rollback removes plugin audit events atomically');
}

$schema = (string)file_get_contents($root . '/schema/default.sql');
$migration = (string)file_get_contents($root . '/schema/migrations/032-audit-events.sql');
$config = (string)file_get_contents($root . '/cfg/config.php');
$viewer = (string)file_get_contents($root . '/dashboard/admin/settings/audit.php');
$aside = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/aside.php');
$settings = (string)file_get_contents($root . '/dashboard/admin/settings/index.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$docs = (string)file_get_contents($root . '/cms.md');
$check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS `audit_events`')
    && str_contains($migration, 'CREATE TABLE IF NOT EXISTS `audit_events`'),
    'fresh and upgraded databases receive the generic audit event schema');
$check(str_contains($schema, "'core.audit.read'") && str_contains($migration, "'core.audit.read'")
    && str_contains($migration, "`is_delegable`=VALUES(`is_delegable`)"),
    'audit viewing permission is nondelegable and available to fresh and upgraded sites');
$check(str_contains($config, "require_once __DIR__ . '/helpers/audit_log.php';"),
    'Core loads the generic audit API for dashboard and plugin mutations');
$check(str_contains($viewer, "adiwira_require_permission(\$pdo, 'core.audit.read', false)")
    && str_contains($viewer, 'adiwira_require_site_owner($pdo, false)')
    && !str_contains($viewer, 'DELETE FROM audit_events'),
    'audit viewer is read-only and restricted by permission plus Site Owner identity');
$check(str_contains($viewer, "parse_url") === false
    && str_contains($viewer, 'ORDER BY id DESC LIMIT ')
    && str_contains($viewer, 'app_site_datetime_to_utc_mysql'),
    'audit viewer provides bounded newest-first filtering with site-local date boundaries');
$check(str_contains($aside, "current_user_can(\$pdo, 'core.audit.read')")
    && str_contains($settings, "user_can(\$pdo, \$uid, 'core.audit.read')")
    && substr_count($translations, "'Audit Log'") >= 2,
    'Site Owners with audit permission receive translated navigation and settings entry points');

$mutationFiles = [
    'article add' => $root . '/dashboard/admin/posts/add.php',
    'article edit' => $root . '/dashboard/admin/posts/save.php',
    'article delete and bulk' => $root . '/dashboard/admin/posts/bulk_action.php',
    'article single delete' => $root . '/dashboard/admin/posts/delete.php',
    'page add' => $root . '/dashboard/admin/pages/add.php',
    'page edit' => $root . '/dashboard/admin/pages/save.php',
    'page delete and bulk' => $root . '/dashboard/admin/pages/bulk_action.php',
    'page single delete' => $root . '/dashboard/admin/pages/delete.php',
];
foreach ($mutationFiles as $label => $path) {
    $source = (string)file_get_contents($path);
    $auditPosition = strrpos($source, 'audit_log_content_event(');
    $commitPosition = $auditPosition === false ? false : strpos($source, '$pdo->commit();', $auditPosition);
    $check($auditPosition !== false && $commitPosition !== false && $auditPosition < $commitPosition,
        $label . ' records its audit event before the caller transaction commits');
}
$articleBulk = (string)file_get_contents($root . '/dashboard/admin/posts/bulk_action.php');
$pageBulk = (string)file_get_contents($root . '/dashboard/admin/pages/bulk_action.php');
$scheduler = (string)file_get_contents($root . '/cfg/helpers/content_scheduler.php');
foreach (['trashed', 'status_changed', 'owner_changed', 'dates_changed'] as $operation) {
    $check(str_contains($articleBulk, "'$operation'") && str_contains($pageBulk, "'$operation'"),
        'Article and Page bulk actions audit ' . $operation);
}
$check(str_contains($articleBulk, "'categories_changed'")
    && str_contains($articleBulk, 'audit_log_id_set(')
    && str_contains($articleBulk, 'WHERE post_id IN ($placePost)"')
    && substr_count($articleBulk, '$operationId') >= 5 && substr_count($pageBulk, '$operationId') >= 5,
    'bulk category changes record bounded complete snapshots and each bulk request shares one operation ID');
$check(str_contains($articleBulk, "selectedPost['created_by'] === null")
    && str_contains($pageBulk, "selectedPage['created_by'] === null")
    && str_contains($articleBulk, "\$affected !== count(\$ownerChangedIds)")
    && str_contains($pageBulk, "\$affected !== count(\$ownerChangedIds)"),
    'bulk owner changes include orphaned content and verify every intended row before auditing');
$check(str_contains($scheduler, "'core.scheduler'")
    && str_contains($scheduler, "'scheduled_publication' => true")
    && str_contains($scheduler, "'status_changed'")
    && str_contains($scheduler, '$pdo->commit();'),
    'scheduled Article and Page publication records a system audit event before commit');
$binFiles = [
    'article restore' => $root . '/dashboard/admin/bin/article/restore.php',
    'article purge' => $root . '/dashboard/admin/bin/article/delete_permanent.php',
    'article Bin bulk' => $root . '/dashboard/admin/bin/article/bulk_action.php',
    'page restore' => $root . '/dashboard/admin/bin/page/restore.php',
    'page purge' => $root . '/dashboard/admin/bin/page/delete_permanent.php',
    'page Bin bulk' => $root . '/dashboard/admin/bin/page/bulk_action.php',
    'trash Undo' => $root . '/dashboard/admin/bin/undo_trash.php',
    'status Undo' => $root . '/dashboard/admin/content/undo_bulk_status.php',
];
foreach ($binFiles as $label => $path) {
    $source = (string)file_get_contents($path);
    $check(str_contains($source, 'audit_log_content_event('), $label . ' records a content audit event');
}
$articleBinBulk = (string)file_get_contents($root . '/dashboard/admin/bin/article/bulk_action.php');
$pageBinBulk = (string)file_get_contents($root . '/dashboard/admin/bin/page/bulk_action.php');
$check(str_contains($articleBinBulk, 'count($ids) > 100') && str_contains($pageBinBulk, 'count($ids) > 100')
    && str_contains($articleBinBulk, '$count !== count($ids)') && str_contains($pageBinBulk, '$count !== count($ids)'),
    'Article and Page Bin bulk audit is bounded and verifies the complete mutation before commit');
$check(str_contains($docs, '## Mutation audit events')
    && str_contains($docs, "'provider' => 'quiz'")
    && str_contains($docs, 'not page-view analytics'),
    'plugin documentation defines transactional semantic audit events without implying page-view coverage');

if ($failures !== []) exit(1);
echo "Audit log contract passed.\n";
