<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/resource_lifecycle.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$throws = static function (callable $callback, ?string $class = null): bool {
    try {
        $callback();
        return false;
    } catch (Throwable $error) {
        return $class === null || $error instanceof $class;
    }
};

$pdo = new PDO('sqlite::memory:');
$capture = static function (ResourceLifecycleDatabase $database, string $operation, array $lockedItems): array {
    return array_map(static fn(array $row): array => [
        'id' => $row['id'],
        'before' => $row,
        'after' => null,
        'artifacts' => [],
    ], $lockedItems);
};

$check(register_resource_lifecycle_provider('contract.record', ['owner' => 'contract', 'capture' => $capture]),
    'a valid lifecycle provider registers');
$check(!register_resource_lifecycle_provider('contract.record', ['owner' => 'other', 'capture' => $capture])
    && !register_resource_lifecycle_provider('../record', ['owner' => 'contract', 'capture' => $capture])
    && !register_resource_lifecycle_provider('contract.other', ['owner' => 'contract', 'capture' => $capture, 'extra' => true]),
    'provider registration rejects collisions, unsafe names, and malformed declarations');
$check(register_resource_lifecycle_provider('contract.ordered', ['capture' => $capture, 'owner' => 'contract']),
    'provider declarations do not depend on associative key order');

$beforeCalls = [];
add_action('resource_lifecycle_before_mutation', static function (array $event, ResourceLifecycleDatabase $database) use (&$beforeCalls): void {
    $beforeCalls[] = [$event['event_id'], $event['items'][0]['before']['value'], $database->inTransaction()];
});
$pdo->beginTransaction();
$event = resource_lifecycle_capture($pdo, 'contract.record', 'update', [
    ['id' => 20, 'value' => 'second'],
    ['id' => 3, 'value' => 'first'],
], ['actor_id' => 7, 'source' => 'core.contract', 'metadata' => ['route' => 'test']]);
$check($event['schema'] === 1 && preg_match('/\A[a-f0-9]{64}\z/D', $event['event_id']) === 1
    && array_column($event['items'], 'id') === [3, 20] && $event['bulk'] === true
    && $beforeCalls === [[$event['event_id'], 'first', true]],
    'capture normalizes ordered snapshots and fires inside the caller transaction');

$changedItems = $event['items'];
foreach ($changedItems as &$item) $item['after'] = ['id' => $item['id'], 'value' => 'changed'];
unset($item);
$commitCalls = [];
add_action('resource_lifecycle_before_commit', static function (array $candidate, ResourceLifecycleDatabase $database) use (&$commitCalls): void {
    $commitCalls[] = [$candidate['event_id'], $candidate['result']['affected'] ?? null, $database->inTransaction()];
});
$ready = resource_lifecycle_before_commit($pdo, $event, [
    'items' => $changedItems,
    'result' => ['affected' => 2],
    'warnings' => [['code' => 'example']],
]);
$check($ready['event_id'] === $event['event_id'] && $ready['items'][0]['after']['value'] === 'changed'
    && $commitCalls === [[$event['event_id'], 2, true]],
    'pre-commit preserves correlation and publishes intended result state transactionally');

$tampered = $changedItems;
$tampered[0]['before']['value'] = 'tampered';
$check($throws(static fn() => resource_lifecycle_before_commit($pdo, $event, ['items' => $tampered]), InvalidArgumentException::class),
    'pre-commit rejects changed identities and immutable before snapshots');
$pdo->commit();

$committedCalls = [];
add_action('resource_lifecycle_committed', static function (): void { throw new RuntimeException('observer failed'); }, 5);
add_action('resource_lifecycle_committed', static function (array $candidate, ResourceLifecycleDatabase $database) use (&$committedCalls): void {
    $committedCalls[] = [$candidate['event_id'], $database->inTransaction()];
}, 10);
$errors = resource_lifecycle_after_commit($pdo, $ready);
$check(count($errors) === 1 && $errors[0]['message'] === 'observer failed'
    && $committedCalls === [[$event['event_id'], false]],
    'committed observers are isolated and run only outside the transaction');
$check(!method_exists(ResourceLifecycleDatabase::class, 'commit')
    && $throws(static fn() => (new ResourceLifecycleDatabase($pdo))->exec('COMMIT'), LogicException::class)
    && $throws(static fn() => (new ResourceLifecycleDatabase($pdo))->prepare('START TRANSACTION'), LogicException::class)
    && $throws(static fn() => (new ResourceLifecycleDatabase($pdo))->exec('CREATE TABLE unsafe (id INTEGER)'), LogicException::class)
    && $throws(static fn() => (new ResourceLifecycleDatabase($pdo))->exec('SET @@autocommit=1'), LogicException::class)
    && $throws(static fn() => (new ResourceLifecycleDatabase($pdo))->exec('LOCK TABLES records WRITE'), LogicException::class),
    'listener database facade rejects method and SQL transaction control');

$GLOBALS['_hooks']['actions']['resource_lifecycle_committed'] = [];
$observerTransactions = [];
add_action('resource_lifecycle_committed', static function () use ($pdo): void { $pdo->beginTransaction(); }, 5);
add_action('resource_lifecycle_committed', static function (array $candidate, ResourceLifecycleDatabase $database) use (&$observerTransactions): void {
    $observerTransactions[] = $database->inTransaction();
}, 10);
$transactionErrors = resource_lifecycle_after_commit($pdo, $ready);
$check(count($transactionErrors) === 1 && $observerTransactions === [false] && !$pdo->inTransaction(),
    'committed dispatch rolls back observer transactions before invoking the next listener');

$check($throws(static fn() => resource_lifecycle_capture($pdo, 'contract.record', 'update', [['id' => 1]]), RuntimeException::class),
    'capture fails without caller-owned transaction');
$pdo->beginTransaction();
$check($throws(static fn() => resource_lifecycle_after_commit($pdo, $ready), RuntimeException::class),
    'committed notification rejects an active transaction');
$pdo->rollBack();

register_resource_lifecycle_provider('contract.bad', [
    'owner' => 'contract',
    'capture' => static fn(): array => [['id' => 1, 'before' => [], 'after' => null, 'artifacts' => [[
        'kind' => 'file', 'role' => 'primary', 'managed' => false, 'state' => 'present', 'absolute_path' => '/tmp/leak',
    ]]]],
]);
$pdo->beginTransaction();
$check($throws(static fn() => resource_lifecycle_capture($pdo, 'contract.bad', 'purge', []), InvalidArgumentException::class),
    'artifact validation rejects absolute paths for unmanaged storage');
$pdo->rollBack();

register_resource_lifecycle_provider('contract.rollback', [
    'owner' => 'contract',
    'capture' => static function (ResourceLifecycleDatabase $database): array {
        $database->exec('ROLLBACK');
        return [['id' => 1, 'before' => [], 'after' => null, 'artifacts' => []]];
    },
]);
$pdo->beginTransaction();
$check($throws(static fn() => resource_lifecycle_capture($pdo, 'contract.rollback', 'update', []), LogicException::class)
    && $pdo->inTransaction(), 'capture providers cannot steal transaction ownership through SQL');
$pdo->rollBack();

$configSource = (string)file_get_contents($root . '/cfg/config.php');
$hooksOffset = strpos($configSource, "require_once __DIR__ . '/helpers/hooks.php';");
$lifecycleOffset = strpos($configSource, "require_once __DIR__ . '/helpers/resource_lifecycle.php';");
$assetOffset = strpos($configSource, "require_once __DIR__ . '/helpers/asset_lifecycle.php';");
$check($hooksOffset !== false && $lifecycleOffset > $hooksOffset && $assetOffset > $lifecycleOffset,
    'Core loads the lifecycle contract after hooks and before resource consumers');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Resource lifecycle contract check(s) failed.\n");
    exit(1);
}
echo "Resource lifecycle contract passed ({$checks} checks).\n";
