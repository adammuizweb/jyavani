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
$check(str_contains($schema, '`updated_by` int(10) unsigned DEFAULT NULL')
    && str_contains($schema, 'fk_posts_updated_by_users'), 'fresh schema defines nullable indexed updater attribution');
$check(str_contains($migration, 'SET `updated_by` = `created_by`')
    && str_contains($migration, 'core_posts_updated_by_backfilled')
    && str_contains($migration, 'fk_posts_updated_by_users'), 'migration 019 adds and replay-safely backfills updater attribution');

$writers = [
    'article add' => 'dashboard/admin/posts/add.php',
    'article edit' => 'dashboard/admin/posts/save.php',
    'article trash' => 'dashboard/admin/posts/delete.php',
    'article bulk' => 'dashboard/admin/posts/bulk_action.php',
    'article restore' => 'dashboard/admin/bin/article/restore.php',
    'article bin bulk' => 'dashboard/admin/bin/article/bulk_action.php',
    'page add' => 'dashboard/admin/pages/add.php',
    'page edit' => 'dashboard/admin/pages/save.php',
    'page trash' => 'dashboard/admin/pages/delete.php',
    'page bulk' => 'dashboard/admin/pages/bulk_action.php',
    'page restore' => 'dashboard/admin/bin/page/restore.php',
    'page bin bulk' => 'dashboard/admin/bin/page/bulk_action.php',
    'theme add' => 'dashboard/admin/themes/add.php',
    'theme edit' => 'dashboard/admin/themes/save.php',
    'theme trash' => 'dashboard/admin/themes/delete.php',
    'theme bulk' => 'dashboard/admin/themes/bulk_action.php',
    'theme restore' => 'dashboard/admin/bin/theme/restore.php',
    'theme bin bulk' => 'dashboard/admin/bin/theme/bulk_action.php',
    'preset save' => 'dashboard/admin/shortcodes/save.php',
    'preset trash' => 'dashboard/admin/shortcodes/delete.php',
    'preset bulk' => 'dashboard/admin/shortcodes/bulk_action.php',
];
foreach ($writers as $label => $relative) {
    $source = (string)file_get_contents($root . '/' . $relative);
    $check(str_contains($source, 'updated_by'), $label . ' attributes surviving post mutations');
}

$check(!str_contains((string)file_get_contents($root . '/dashboard/admin/bin/article/delete_permanent.php'), 'updated_by')
    && !str_contains((string)file_get_contents($root . '/dashboard/admin/bin/page/delete_permanent.php'), 'updated_by'),
    'permanent deletion does not write non-surviving attribution');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
