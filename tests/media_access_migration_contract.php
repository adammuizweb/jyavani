<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string)file_get_contents($root . '/schema/migrations/017-media-access-policy.sql');
$default = (string)file_get_contents($root . '/schema/default.sql');
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

foreach (['media', 'file'] as $table) {
    $check(str_contains($migration, "ALTER TABLE `{$table}`"), "migration upgrades the {$table} table");
}
foreach (['visibility', 'storage_disk', 'storage_path', 'access_scope', 'is_downloadable'] as $column) {
    $check(substr_count($migration, "ADD COLUMN IF NOT EXISTS `{$column}`") === 2,
        "migration adds {$column} idempotently to media and file");
    $check(substr_count($default, "`{$column}`") >= 2,
        "fresh schema includes {$column} for media and file");
}

echo "Checks: {$checks}, Failures: " . count($failures) . PHP_EOL;
exit($failures === [] ? 0 : 1);
