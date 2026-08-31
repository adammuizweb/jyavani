<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/migration_helper.php';
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
    $check(substr_count($migration, "ADD COLUMN `{$column}`") === 2,
        "migration adds {$column} separately to media and file");
    $check(substr_count($default, "`{$column}`") >= 2,
        "fresh schema includes {$column} for media and file");
}
$check(!str_contains($migration, 'ADD COLUMN IF NOT EXISTS')
    && str_contains((string)file_get_contents($root . '/cfg/helpers/migration_helper.php'), 'migration_duplicate_addition_is_safe'),
    'migration is MySQL 5.7 compatible and replay-safe after partial DDL');
$duplicateColumn = new PDOException('duplicate column');
$duplicateColumn->errorInfo = ['42S21', 1060, 'Duplicate column name'];
$duplicateIndex = new PDOException('duplicate index');
$duplicateIndex->errorInfo = ['42000', 1061, 'Duplicate key name'];
$check(migration_duplicate_addition_is_safe($duplicateColumn, 'ALTER TABLE `media` ADD COLUMN `visibility` INT')
    && migration_duplicate_addition_is_safe($duplicateIndex, 'ALTER TABLE `users` ADD INDEX `idx_owner` (`id`)')
    && !migration_duplicate_addition_is_safe($duplicateColumn, 'SELECT 1'),
    'runner ignores only duplicate errors from replay-safe additive DDL');

echo "Checks: {$checks}, Failures: " . count($failures) . PHP_EOL;
exit($failures === [] ? 0 : 1);
