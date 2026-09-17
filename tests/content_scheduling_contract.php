<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/time_helpers.php';
if (!function_exists('settings_get')) {
    function settings_get(PDO $pdo, string $key, mixed $default = null): mixed
    {
        return $key === 'content_scheduling_enabled'
            ? ($GLOBALS['__TEST_CONTENT_SCHEDULING_ENABLED'] ?? $default)
            : $default;
    }
}
require_once $root . '/cfg/helpers/content_scheduler.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$originalTimezone = $GLOBALS['__APP_TIMEZONE_ID'] ?? null;
$GLOBALS['__APP_TIMEZONE_ID'] = 'Asia/Jakarta';

$scheduled = content_schedule_resolve('scheduled', '2099-01-02T03:04');
$check($scheduled === [
    'status' => 'draft',
    'publish_at_utc' => '2099-01-01 20:04:00',
    'editor_status' => 'scheduled',
], 'site-local schedule input is stored as an explicit UTC instant');
$check(content_schedule_datetime_local('2099-01-01 20:04:00') === '2099-01-02T03:04',
    'stored UTC schedule values round-trip to site-local editor input');
$check(content_schedule_editor_status('draft', ['publish_at_utc' => '2099-01-01 20:04:00']) === 'scheduled'
    && content_schedule_editor_status('published', ['publish_at_utc' => '2099-01-01 20:04:00']) === 'published',
    'only a draft with a publication instant has the virtual Scheduled status');
$check(str_contains(content_schedule_status_sql('p.status'), "publish_at_utc IS NOT NULL")
    && str_contains(content_schedule_status_sql('p.status'), "'scheduled'"),
    'list status SQL exposes scheduled content without changing stored status values');

$pastRejected = false;
try {
    content_schedule_resolve('scheduled', '2020-01-01T00:00');
} catch (InvalidArgumentException) {
    $pastRejected = true;
}
$check($pastRejected, 'past scheduled publication times are rejected');
$GLOBALS['__APP_TIMEZONE_ID'] = 'America/New_York';
$ambiguousRejected = false;
try {
    content_schedule_resolve('scheduled', '2026-11-01T01:30');
} catch (InvalidArgumentException $error) {
    $ambiguousRejected = $error->getMessage() === 'Scheduled publication time is ambiguous in the site timezone.';
}
$GLOBALS['__APP_TIMEZONE_ID'] = 'Asia/Jakarta';
$check($ambiguousRejected, 'ambiguous daylight-saving fallback times are rejected');
$check(content_schedule_transition_error('published', 'scheduled') !== null
    && content_schedule_transition_error('draft', 'scheduled') === null
    && content_schedule_transition_error('published', 'draft') === null,
    'published content requires an explicit Draft save before scheduling');

$schema = (string)file_get_contents($root . '/schema/default.sql');
$migration = (string)file_get_contents($root . '/schema/migrations/028-content-scheduling.sql');
$settingMigration = (string)file_get_contents($root . '/schema/migrations/029-content-scheduling-setting.sql');
$worker = (string)file_get_contents($root . '/tools/publish-scheduled.php');
$helper = (string)file_get_contents($root . '/cfg/helpers/content_scheduler.php');
$scheduleScript = (string)file_get_contents($root . '/public/static/dashboard/js/content-schedule.js');
$dashboardCss = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$editors = '';
foreach (['dashboard/admin/posts/add.php', 'dashboard/admin/posts/edit.php', 'dashboard/admin/posts/save.php',
    'dashboard/admin/pages/add.php', 'dashboard/admin/pages/edit.php', 'dashboard/admin/pages/save.php',
    'dashboard/admin/themes/add.php', 'dashboard/admin/themes/edit.php', 'dashboard/admin/themes/save.php'] as $file) {
    $editors .= (string)file_get_contents($root . '/' . $file);
}
$check(str_contains($schema, '`publish_at_utc` datetime DEFAULT NULL')
    && str_contains($migration, 'idx_posts_scheduled_publish'),
    'fresh and upgraded schemas include the indexed UTC scheduler field');
$check(str_contains($schema, "('content_scheduling_enabled', '0', 1)")
    && str_contains($settingMigration, "('content_scheduling_enabled', '0', 1)"),
    'fresh and upgraded sites keep scheduled publishing opt-in');
$check(str_contains($helper, 'publish_at_utc <= UTC_TIMESTAMP()')
    && str_contains($helper, "type IN ('article', 'page', 'theme')")
    && str_contains($helper, "AND status = 'draft'")
    && str_contains($helper, 'publish_at_utc = NULL')
    && str_contains($helper, 'updated_by = NULL')
    && str_contains($helper, "do_action('content_scheduler_before_publish'")
    && str_contains($helper, 'publish_at_utc = :publish_at_utc')
    && str_contains($helper, '$candidateLimit = min(5000, max(100, $limit * 10))')
    && str_contains($helper, 'if (count($published) >= $limit) break;')
    && str_contains($helper, 'rowCount() !== 1'),
    'worker locks due drafts, runs fail-fast workflow hooks, publishes the exact instant, and records a system mutation');
$check(str_contains($helper, "if (\$pdo->inTransaction()) throw new RuntimeException('Scheduled publication requires no active transaction.')")
    && str_contains($helper, 'Scheduler observers cannot leave an active transaction.')
    && str_contains($helper, '$pdo->rollBack()'),
    'worker owns autocommit publication and cleans transactions leaked by isolated observers');
$check(str_contains($worker, "PHP_SAPI !== 'cli'")
    && str_contains($worker, "app/bootstrap_core.php")
    && str_contains($worker, 'theme_lifecycle_reader_start()')
    && str_contains($worker, 'plugin_load_active()')
    && str_contains($worker, 'content_scheduler_publish_due($pdo, $limit)'),
    'scheduler entrypoint is CLI-only, runs Core bootstrap, locks extension lifecycle, and delegates to the bounded worker');
$check(substr_count($editors, 'name="schedule_at"') === 6
    && substr_count($editors, "value=\"scheduled\"") === 6
    && substr_count($editors, 'data-content-schedule hidden') === 6
    && substr_count($editors, 'content_scheduling_enabled($pdo)') === 12
    && substr_count($editors, 'content_schedule_resolve_for_site(') === 6
    && str_contains($scheduleScript, "status.value === 'scheduled'")
    && str_contains($scheduleScript, 'input.disabled = !scheduled')
    && str_contains($scheduleScript, 'input.required = scheduled')
    && str_contains($dashboardCss, '.content-publish-row>[data-content-schedule][hidden]{display:none!important}')
    && str_contains($dashboardCss, '.content-publish-row{display:flex;')
    && str_contains($dashboardCss, 'flex:0 0 auto;')
    && str_contains($dashboardCss, '.content-publish-row .inp{width:auto;'),
    'Article, Page, and Theme Content add/edit forms expose schedule status and site-local time input');
$check(str_contains((string)file_get_contents($root . '/dashboard/admin/posts/edit.php'), "\$currentStatus !== 'published'")
    && str_contains((string)file_get_contents($root . '/dashboard/admin/pages/edit.php'), "\$status !== 'published'")
    && str_contains((string)file_get_contents($root . '/dashboard/admin/themes/edit.php'), "\$pref_status !== 'published'")
    && substr_count($editors, 'content_schedule_transition_error(') >= 6,
    'published editors hide Scheduled and all save paths reject a forged direct transition');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sqlite->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => '2026-09-17 12:00:00');
    $GLOBALS['__TEST_CONTENT_SCHEDULING_ENABLED'] = '0';
    $disabledRejected = false;
    try {
        content_schedule_resolve_for_site($sqlite, 'scheduled', '2099-01-02T03:04');
    } catch (InvalidArgumentException $error) {
        $disabledRejected = $error->getMessage() === 'Scheduled publishing is disabled in Site Settings.';
    }
    $GLOBALS['__TEST_CONTENT_SCHEDULING_ENABLED'] = '1';
    $enabledSchedule = content_schedule_resolve_for_site($sqlite, 'scheduled', '2099-01-02T03:04');
    $check($disabledRejected && ($enabledSchedule['editor_status'] ?? null) === 'scheduled',
        'site setting rejects forged schedules while allowing enabled editors');
    $GLOBALS['__TEST_CONTENT_SCHEDULING_ENABLED'] = '0';
    $sqlite->exec('CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, title TEXT, status TEXT,
        status_revision INTEGER NOT NULL DEFAULT 0, publish_at_utc TEXT,
        updated_at TEXT, updated_by INTEGER, is_deleted INTEGER NOT NULL DEFAULT 0
    )');
    $sqlite->exec("INSERT INTO posts (type,title,status,publish_at_utc) VALUES
        ('article','First','draft','2026-09-17 11:00:00'),
        ('page','Second','draft','2026-09-17 11:30:00')");
    $blocked = static function (array $item): void {
        if (($item['title'] ?? '') === 'First') {
            throw new RuntimeException('Companion workflow rejected publication.');
        }
    };
    add_action('content_scheduler_before_publish', $blocked);
    $partial = content_scheduler_publish_due($sqlite, 10);
    remove_action('content_scheduler_before_publish', $blocked);
    $check(count($partial) === 1
        && ($partial[0]['title'] ?? '') === 'Second'
        && count(content_scheduler_last_errors()) === 1
        && $sqlite->query("SELECT status FROM posts WHERE title = 'First'")->fetchColumn() === 'draft'
        && $sqlite->query("SELECT status FROM posts WHERE title = 'Second'")->fetchColumn() === 'published',
        'a failing pre-publication hook leaves only that item scheduled and does not starve later due content');
    $sqlite->exec("INSERT INTO posts (type,title,status,publish_at_utc) VALUES
        ('page','Third','draft','2026-09-17 11:45:00')");
    add_action('content_scheduler_published', static function (array $item, PDO $database): void {
        $database->beginTransaction();
    });
    $published = content_scheduler_publish_due($sqlite, 10);
    $states = $sqlite->query('SELECT status, status_revision, publish_at_utc FROM posts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check(count($published) === 2
        && array_column($states, 'status') === ['published', 'published', 'published']
        && array_map('intval', array_column($states, 'status_revision')) === [1, 1, 1]
        && array_column($states, 'publish_at_utc') === [null, null, null]
        && !$sqlite->inTransaction(),
        'a transaction-leaking observer cannot roll back or block later publications in the batch');
}
unset($GLOBALS['__TEST_CONTENT_SCHEDULING_ENABLED']);

if ($originalTimezone === null) unset($GLOBALS['__APP_TIMEZONE_ID']);
else $GLOBALS['__APP_TIMEZONE_ID'] = $originalTimezone;

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " content scheduling contract check(s) failed.\n");
    exit(1);
}
echo "Content scheduling contract passed ({$checks} checks).\n";
