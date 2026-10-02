<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/cfg/helpers/hooks.php';
require_once dirname(__DIR__) . '/cfg/helpers/resource_lifecycle.php';
require_once dirname(__DIR__) . '/cfg/helpers/sidebar_helper.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE sidebar_zones (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT, description TEXT, is_primary INTEGER)');
$pdo->exec('CREATE TABLE sidebar_zone_items (id INTEGER PRIMARY KEY AUTOINCREMENT, zone_id INTEGER, type TEXT, title TEXT, config TEXT, ordering INTEGER, active INTEGER)');
$pdo->exec("INSERT INTO sidebar_zones (id,name,slug,description,is_primary) VALUES (1,'Main','main','',1),(2,'Alt','alt','',0)");
$pdo->exec("INSERT INTO sidebar_zone_items (id,zone_id,type,title,config,ordering,active) VALUES
    (10,1,'search','Search','{}',1,1),
    (11,1,'shortcode_preset','Preset','{\"preset_slug\":\"demo\"}',2,1),
    (12,2,'categories','Categories','{}',1,1)");
$itemProvider = $GLOBALS['_hooks']['lifecycle_resources']['sidebar_item'] ?? null;
$zoneProvider = $GLOBALS['_hooks']['lifecycle_resources']['sidebar_zone'] ?? null;
$check(($itemProvider['owner'] ?? null) === 'core'
    && ($itemProvider['capture'] ?? null) === 'sidebar_lifecycle_capture_items'
    && ($zoneProvider['owner'] ?? null) === 'core'
    && register_resource_lifecycle_provider('sidebar_item', ['owner' => 'plugin', 'capture' => 'sidebar_lifecycle_capture_items']) === false
    && ($GLOBALS['_hooks']['lifecycle_resources']['sidebar_item']['owner'] ?? null) === 'core',
    'Core sidebar lifecycle providers are registered first and cannot be replaced');

$events = [];
$observer = static function (array $event, ResourceLifecycleDatabase $database) use (&$events): void {
    if (!in_array($event['resource'], ['sidebar_item', 'sidebar_zone'], true)) return;
    $events[] = [$event['resource'], $event['operation'], $event['metadata']['reason'] ?? '', array_column($event['items'], 'id')];
};
add_action('resource_lifecycle_before_mutation', $observer);

$deleted = sidebar_delete_items($pdo, 1, [10], 7, 'item_delete');
$check($deleted === 1 && (int)$pdo->query('SELECT COUNT(*) FROM sidebar_zone_items WHERE id=10')->fetchColumn() === 0,
    'explicit sidebar item deletion removes the locked row');
$check($events[0] === ['sidebar_item', 'delete', 'item_delete', [10]],
    'explicit deletion emits one sidebar_item lifecycle snapshot with its reason');

$deleted = sidebar_delete_items($pdo, 1, [11], 7, 'save_omitted');
$check($deleted === 1 && $events[1] === ['sidebar_item', 'delete', 'save_omitted', [11]],
    'save omission uses the same fail-fast sidebar item lifecycle');

$result = sidebar_delete_zone($pdo, 2, 7);
$check(array_column($result['items'], 'id') === [12]
    && (int)$pdo->query('SELECT COUNT(*) FROM sidebar_zones WHERE id=2')->fetchColumn() === 0,
    'zone deletion locks and removes its child items and zone');
$check($events[2] === ['sidebar_item', 'delete', 'zone_delete', [12]]
    && $events[3] === ['sidebar_zone', 'delete', 'zone_delete', [2]],
    'zone deletion emits child item lifecycle before zone lifecycle');

$pdo->exec("INSERT INTO sidebar_zone_items (id,zone_id,type,title,config,ordering,active) VALUES (13,1,'search','Blocked','{}',1,1)");
$deny = static function (array $event): void {
    if (($event['resource'] ?? '') === 'sidebar_item' && in_array(13, array_column($event['items'] ?? [], 'id'), true)) {
        throw new RuntimeException('companion cleanup failed');
    }
};
add_action('resource_lifecycle_before_mutation', $deny, 20);
$blocked = false;
try {
    sidebar_delete_items($pdo, 1, [13], 7, 'item_delete');
} catch (RuntimeException $error) {
    $blocked = $error->getMessage() === 'companion cleanup failed';
}
$check($blocked && (int)$pdo->query('SELECT COUNT(*) FROM sidebar_zone_items WHERE id=13')->fetchColumn() === 1,
    'throwing companion cleanup rolls back Core sidebar deletion');

$pdo->exec("INSERT INTO sidebar_zone_items (id,zone_id,type,title,config,ordering,active) VALUES (14,1,'search','Original','{}',2,1)");
$denyBeforeCommit = static function (array $event): void {
    if (($event['resource'] ?? '') === 'sidebar_item' && in_array(14, array_column($event['items'] ?? [], 'id'), true)) {
        throw new RuntimeException('pre-commit cleanup failed');
    }
};
add_action('resource_lifecycle_before_commit', $denyBeforeCommit);
$atomicRollback = false;
try {
    $pdo->beginTransaction();
    $pdo->exec("UPDATE sidebar_zone_items SET title='Changed' WHERE id=14");
    sidebar_delete_items_in_transaction($pdo, 1, [14], 7, 'save_omitted');
    $pdo->commit();
} catch (RuntimeException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $atomicRollback = $error->getMessage() === 'pre-commit cleanup failed';
}
remove_action('resource_lifecycle_before_commit', $denyBeforeCommit);
$check($atomicRollback
    && $pdo->query('SELECT title FROM sidebar_zone_items WHERE id=14')->fetchColumn() === 'Original',
    'caller-owned Sidebar save transactions roll back updates when omitted-item cleanup is denied');

$pdo->exec("INSERT INTO sidebar_zone_items (id,zone_id,type,title,config,ordering,active) VALUES (15,1,'search','Observed','{}',3,1)");
$committedObserved = false;
$throwingObserver = static function (array $event): void {
    if (($event['resource'] ?? '') === 'sidebar_item' && in_array(15, array_column($event['items'] ?? [], 'id'), true)) {
        throw new RuntimeException('observer failure');
    }
};
$followingObserver = static function (array $event) use (&$committedObserved): void {
    if (($event['resource'] ?? '') === 'sidebar_item' && in_array(15, array_column($event['items'] ?? [], 'id'), true)) {
        $committedObserved = true;
    }
};
add_action('resource_lifecycle_committed', $throwingObserver, 10);
add_action('resource_lifecycle_committed', $followingObserver, 20);
sidebar_delete_items($pdo, 1, [15], 7, 'item_delete');
remove_action('resource_lifecycle_committed', $throwingObserver, 10);
remove_action('resource_lifecycle_committed', $followingObserver, 20);
$check($committedObserved && (int)$pdo->query('SELECT COUNT(*) FROM sidebar_zone_items WHERE id=15')->fetchColumn() === 0,
    'committed Sidebar observers are isolated after successful deletion');

$primaryBlocked = false;
$pdo->exec("INSERT INTO sidebar_zones (id,name,slug,description,is_primary) VALUES (3,'Other','other','',0)");
try {
    sidebar_delete_zone($pdo, 1, 7);
} catch (RuntimeException $error) {
    $primaryBlocked = str_contains($error->getMessage(), 'primary zone');
}
$check($primaryBlocked && (int)$pdo->query('SELECT COUNT(*) FROM sidebar_zones WHERE id=1')->fetchColumn() === 1,
    'primary-zone protection is rechecked from locked transaction state');

$configSource = (string)file_get_contents(dirname(__DIR__) . '/cfg/config.php');
$dashboardSource = (string)file_get_contents(dirname(__DIR__) . '/dashboard/admin/sidebar/index.php');
$check(strpos($configSource, "helpers/sidebar_helper.php") < strpos($configSource, "helpers/widget_helper.php"),
    'Core loads Sidebar lifecycle providers before plugin bootstrap can run');
$check(str_contains($dashboardSource, 'sidebar_delete_items_in_transaction(')
    && str_contains($dashboardSource, "if (\$pdo->inTransaction()) \$pdo->rollBack();")
    && str_contains($dashboardSource, "\$errors[] = __('Failed to save settings.');"),
    'Sidebar Manager handles lifecycle failures and keeps Save All atomic');

if ($failures !== []) {
    fwrite(STDERR, 'Sidebar lifecycle contract failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo 'RESULT: ALL PASS' . PHP_EOL;
