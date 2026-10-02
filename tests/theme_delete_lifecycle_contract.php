<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/cfg/helpers/hooks.php';

if (!defined('THEME_LIFECYCLE_LOCK_KEY')) define('THEME_LIFECYCLE_LOCK_KEY', '0-theme-lifecycle');
$source = (string)file_get_contents(dirname(__DIR__) . '/cfg/helpers/theme_helper.php');
$start = strpos($source, 'function theme_delete_preflight(');
$end = strpos($source, '/** Exact registered and on-disk folder keys', $start);
if ($start === false || $end === false) throw new RuntimeException('Theme delete preflight source not found.');
eval(substr($source, $start, $end - $start));

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE marker (id INTEGER PRIMARY KEY)');
$theme = ['id' => 8, 'folder_name' => 'managed-fork'];
$manifest = ['folder' => 'managed-fork', 'version' => '1.0.0'];
$context = ['schema' => 1, 'operation' => 'delete', 'actor_id' => 4, 'source' => 'theme_manager', 'delete_files' => true];

$deny = static fn(array $state): array => ['allowed' => false, 'message' => 'Delete this managed theme through its owner.'];
$reverse = static fn(array $state): array => ['allowed' => true, 'message' => ''];
add_filter('theme_delete_preflight', $deny, 10);
add_filter('theme_delete_preflight', $reverse, 20);
$pdo->beginTransaction();
$state = theme_delete_preflight($theme, $manifest, $context, $pdo);
$check($state === ['allowed' => false, 'message' => 'Delete this managed theme through its owner.'],
    'later theme deletion policy cannot reverse an earlier denial');
$pdo->rollBack();
remove_filter('theme_delete_preflight', $deny, 10);
remove_filter('theme_delete_preflight', $reverse, 20);

$malformed = static fn(array $state): array => ['allowed' => 'yes', 'message' => ''];
add_filter('theme_delete_preflight', $malformed);
$pdo->beginTransaction();
$state = theme_delete_preflight($theme, $manifest, $context, $pdo);
$check($state['allowed'] === false && $state['message'] === 'Theme deletion was denied because policy validation failed.',
    'malformed theme deletion policy fails closed with a generic message');
$pdo->rollBack();
remove_filter('theme_delete_preflight', $malformed);

$emptyDenial = static fn(array $state): array => ['allowed' => false, 'message' => '   '];
add_filter('theme_delete_preflight', $emptyDenial);
$pdo->beginTransaction();
$state = theme_delete_preflight($theme, $manifest, $context, $pdo);
$check($state['allowed'] === false && $state['message'] === 'Theme deletion was denied because policy validation failed.',
    'theme deletion denials require an actionable nonempty message');
$pdo->rollBack();
remove_filter('theme_delete_preflight', $emptyDenial);

$transactionBreaker = static function (array $state, array $theme, array $manifest, array $context, PDO $pdo): array {
    $pdo->rollBack();
    return $state;
};
add_filter('theme_delete_preflight', $transactionBreaker);
$pdo->beginTransaction();
$state = theme_delete_preflight($theme, $manifest, $context, $pdo);
$check($state['allowed'] === false && !$pdo->inTransaction(), 'theme deletion policy cannot silently change transaction ownership');
remove_filter('theme_delete_preflight', $transactionBreaker);

$assignSource = (string)file_get_contents(dirname(__DIR__) . '/dashboard/admin/themes/assign.php');
$check(str_contains($assignSource, 'theme_delete_preflight(')
    && strpos($assignSource, '$lockedThemeStmt') < strpos($assignSource, 'theme_delete_preflight(')
    && strpos($assignSource, 'theme_delete_preflight(') < strpos($assignSource, 'DELETE FROM themes'),
    'Core Theme Manager runs deletion preflight after row lock and before mutation');
$check(str_contains($assignSource, '__($deletePolicy[\'message\'])'),
    'Theme Manager localizes a bounded deletion-policy message');

$translations = (string)file_get_contents(dirname(__DIR__) . '/schema/translations.sql');
$check(substr_count($translations, "'Theme deletion was denied because policy validation failed.'") >= 2,
    'generic fail-closed deletion policy message is seeded for supported dashboard locales');

if ($failures !== []) {
    fwrite(STDERR, 'Theme delete lifecycle contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo 'RESULT: ALL PASS' . PHP_EOL;
