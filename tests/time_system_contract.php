<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/time_helpers.php';
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/settings_helpers.php';
require_once $root . '/cfg/helpers/permalink_helpers.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$originalTimezone = date_default_timezone_get();
$originalAppTimezone = $GLOBALS['__APP_TIMEZONE_ID'] ?? null;

$check(app_timezone_default_id() === 'Asia/Jakarta',
    'legacy installations retain Asia/Jakarta as the compatibility default');
$check(app_date_format_default() === 'F j, Y'
    && app_time_format_default() === 'H:i'
    && app_display_format_is_valid('d.m.Y', 'date')
    && app_display_format_is_valid('g:i A', 'time')
    && !app_display_format_is_valid('Y-m-d H:i', 'date')
    && !app_display_format_is_valid('date("c")', 'date')
    && !app_display_format_is_valid(str_repeat('Y', 65), 'date'),
    'display format defaults and bounded token validation are explicit');
$check(app_timezone_is_valid('Asia/Jakarta')
    && app_timezone_is_valid('Europe/Berlin')
    && app_timezone_is_valid('America/New_York')
    && app_timezone_is_valid('UTC')
    && !app_timezone_is_valid('EST')
    && !app_timezone_is_valid('+07:00')
    && !app_timezone_is_valid('../UTC'),
    'timezone validation accepts IANA identifiers and rejects abbreviations, offsets, and malformed input');

$GLOBALS['__APP_TIMEZONE_ID'] = 'Europe/Berlin';
date_default_timezone_set('Europe/Berlin');
$wall = app_parse_wall_mysql('2026-01-02 03:04:05');
$check($wall instanceof DateTimeImmutable
    && $wall->format('Y-m-d H:i:s e') === '2026-01-02 03:04:05 Europe/Berlin'
    && app_wall_mysql_to_datetime_local('2026-01-02 03:04:05') === '2026-01-02T03:04'
    && str_contains(format_datetime_indo('2026-01-02 03:04:05', false, true), '03:04'),
    'legacy wall-clock parsing preserves local calendar fields in the configured timezone');
$preview = new DateTimeImmutable('2026-09-17 16:20:00', app_timezone());
$check(app_display_format($preview, 'F j, Y') === 'September 17, 2026'
    && app_display_format($preview, 'Y-m-d') === '2026-09-17'
    && app_display_format($preview, 'g:i a') === '4:20 pm'
    && app_display_format($preview, 'H:i') === '16:20',
    'date and time presets render deterministic human-readable output');
$check(app_parse_wall_mysql('2026-02-30 03:04:05') === null
    && app_parse_wall_mysql('0000-00-00 00:00:00') === null
    && app_parse_site_datetime_local('2026-01-02T03:04')?->format('Y-m-d H:i:s') === '2026-01-02 03:04:00',
    'strict parsers reject normalized and zero dates while accepting datetime-local input');

$GLOBALS['__APP_TIMEZONE_ID'] = 'America/New_York';
$beforeDst = app_utc_mysql_to_site('2026-03-08 06:30:00');
$afterDst = app_utc_mysql_to_site('2026-03-08 07:30:00');
$firstFold = app_utc_mysql_to_site('2026-11-01 05:30:00');
$secondFold = app_utc_mysql_to_site('2026-11-01 06:30:00');
$check($beforeDst?->format('Y-m-d H:i P') === '2026-03-08 01:30 -05:00'
    && $afterDst?->format('Y-m-d H:i P') === '2026-03-08 03:30 -04:00'
    && $firstFold?->format('Y-m-d H:i P') === '2026-11-01 01:30 -04:00'
    && $secondFold?->format('Y-m-d H:i P') === '2026-11-01 01:30 -05:00',
    'UTC instants convert correctly across daylight-saving gaps and folds');
$check(app_now_utc()->getTimezone()->getName() === 'UTC'
    && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', app_now_utc_mysql()) === 1,
    'UTC clock helpers provide explicit scheduler-safe instants');

$sqlite = new PDO('sqlite::memory:');
$check(app_db_set_session_timezone($sqlite, 'Europe/Berlin') === 'Europe/Berlin',
    'non-MySQL test connections preserve the validated timezone contract without session SQL');
$sqlite->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT, autoload INTEGER NOT NULL DEFAULT 1)');
$sqlite->exec("INSERT INTO settings (`key`, `value`, autoload) VALUES
    ('date_format', 'd.m.Y', 1), ('time_format', 'g:i A', 1),
    ('permalink_posts', '/%year%/%monthnum%/%slug%/', 1)");
$GLOBALS['pdo'] = $sqlite;
unset($GLOBALS['__jy_settings_autoload_cache']);
$check(app_display_date('2026-09-17 16:20:00') === '17.09.2026'
    && app_display_time('2026-09-17 16:20:00') === '4:20 PM'
    && app_display_datetime('2026-09-17 16:20:00') === '17.09.2026 4:20 PM',
    'configured display formats apply to site-local wall-clock values');
$permalinkBefore = get_post_permalink(['id' => 0, 'slug' => 'sample', 'created_at' => '2026-09-17 16:20:00']);
$sqlite->exec("UPDATE settings SET `value` = 'm/d/Y' WHERE `key` = 'date_format'");
unset($GLOBALS['__jy_settings_autoload_cache']);
$permalinkAfter = get_post_permalink(['id' => 0, 'slug' => 'sample', 'created_at' => '2026-09-17 16:20:00']);
$check($permalinkBefore === '/2026/09/sample/' && $permalinkAfter === $permalinkBefore,
    'display format changes do not alter year/month permalink tokens');

$config = (string)file_get_contents($root . '/cfg/config.php');
$db = (string)file_get_contents($root . '/cfg/db.php');
$schema = (string)file_get_contents($root . '/schema/default.sql');
$migration = (string)file_get_contents($root . '/schema/migrations/026-site-timezone.sql');
$formatMigration = (string)file_get_contents($root . '/schema/migrations/027-date-time-formats.sql');
$settings = (string)file_get_contents($root . '/dashboard/admin/settings/site.php');
$installer = (string)file_get_contents($root . '/public/pondasi/index.php');
$editors = '';
foreach (['dashboard/admin/posts/add.php', 'dashboard/admin/posts/save.php', 'dashboard/admin/posts/edit.php',
    'dashboard/admin/pages/add.php', 'dashboard/admin/pages/save.php', 'dashboard/admin/pages/edit.php',
    'dashboard/admin/pages/bulk_action.php'] as $file) {
    $editors .= (string)file_get_contents($root . '/' . $file);
}

$check(strpos($config, "helpers/time_helpers.php") < strpos($config, "require_once __DIR__ . '/db.php'")
    && str_contains($db, 'app_time_bootstrap($pdo)'),
    'time helpers load before PDO and bootstrap configures every primary database session');
$check(str_contains($schema, "('site_timezone',    'Asia/Jakarta', 1)")
    && str_contains($migration, "VALUES ('site_timezone', 'Asia/Jakarta', 1)")
    && !preg_match('/ALTER\s+TABLE\s+`?(?:posts|categories|users)`?/i', $migration),
    'fresh and upgraded sites receive the timezone default without rewriting legacy timestamps');
$check(str_contains($schema, "('date_format',      'F j, Y', 1)")
    && str_contains($schema, "('time_format',      'H:i', 1)")
    && str_contains($formatMigration, "('date_format', 'F j, Y', 1)")
    && str_contains($formatMigration, "('time_format', 'H:i', 1)"),
    'fresh and upgraded sites receive stable human-readable format defaults');
$check(str_contains($settings, 'name="site_timezone"')
    && str_contains($settings, 'app_timezone_is_valid($current_site_timezone)')
    && str_contains($settings, "settings_set(\$pdo, 'site_timezone'")
    && str_contains($settings, "settings_set(\$pdo, 'date_format'")
    && str_contains($settings, "settings_set(\$pdo, 'time_format'")
    && str_contains($settings, 'app_time_bootstrap($pdo)')
    && str_contains($installer, "['site_timezone', app_timezone_default_id()]"),
    'Site Settings and Pondasi persist the validated timezone');
$coreDisplaySources = '';
foreach (['public/views/themes/default/main/single/post.php', 'public/views/themes/default/main/single/page.php',
    'public/views/themes/default/main/list/page.php', 'public/views/themes/default/main/list/archive.php',
    'public/views/themes/default/main/list/category.php', 'public/views/themes/default/main/list/author.php',
    'public/views/themes/default/main/search.php', 'cfg/helpers/theme_zones.php',
    'cfg/helpers/widget_helper.php'] as $file) {
    $coreDisplaySources .= (string)file_get_contents($root . '/' . $file);
}
$check(substr_count($coreDisplaySources, 'app_display_date(') >= 8
    && str_contains($coreDisplaySources, 'app_display_datetime('),
    'Core human-readable date output uses the configurable display helpers');
$check(!str_contains($editors, "new DateTimeZone('Asia/Jakarta')")
    && str_contains($editors, 'app_parse_site_datetime_local(')
    && str_contains($editors, 'app_now_wall_mysql()'),
    'Core content editors use the configured site-time wall-clock helpers');
$check(str_contains((string)file_get_contents($root . '/AGENTS.md'), 'New instant-bearing fields')
    && str_contains((string)file_get_contents($root . '/AGENTS.md'), 'UTC_TIMESTAMP()'),
    'future scheduler fields have an explicit UTC storage and comparison contract');

if ($originalAppTimezone === null) unset($GLOBALS['__APP_TIMEZONE_ID']);
else $GLOBALS['__APP_TIMEZONE_ID'] = $originalAppTimezone;
unset($GLOBALS['pdo'], $GLOBALS['__jy_settings_autoload_cache']);
date_default_timezone_set($originalTimezone);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " time system contract check(s) failed.\n");
    exit(1);
}
echo "Time system contract passed ({$checks} checks).\n";
