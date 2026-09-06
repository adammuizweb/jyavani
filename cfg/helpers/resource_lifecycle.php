<?php
declare(strict_types=1);

$GLOBALS['_hooks']['lifecycle_resources'] ??= [];

final class ResourceLifecycleDatabase
{
    public function __construct(private PDO $pdo)
    {
    }

    private function assertStatement(string $statement): void
    {
        if (preg_match('/\A\s*(?:SELECT|INSERT|UPDATE|DELETE)\b/i', $statement) !== 1
            || preg_match('/\b(?:BEGIN|COMMIT|ROLLBACK|SAVEPOINT|AUTOCOMMIT|START\s+TRANSACTION|LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i', $statement) === 1) {
            throw new LogicException('Resource lifecycle listeners may execute only transaction-safe DML.');
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->assertStatement($query);
        return $this->pdo->prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->assertStatement($query);
        return $fetchMode === null
            ? $this->pdo->query($query)
            : $this->pdo->query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->assertStatement($statement);
        return $this->pdo->exec($statement);
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return $this->pdo->quote($string, $type);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->pdo->lastInsertId($name);
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->pdo->getAttribute($attribute);
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}

function resource_lifecycle_name_is_valid(string $value): bool
{
    return strlen($value) <= 100
        && preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $value) === 1;
}

function register_resource_lifecycle_provider(string $resource, array $provider): bool
{
    $providerKeys = array_keys($provider);
    if (!resource_lifecycle_name_is_valid($resource)
        || count($providerKeys) !== 2
        || array_diff($providerKeys, ['owner', 'capture']) !== []
        || !is_string($provider['owner'])
        || !resource_lifecycle_name_is_valid($provider['owner'])
        || !is_callable($provider['capture'])
        || isset($GLOBALS['_hooks']['lifecycle_resources'][$resource])) {
        return false;
    }
    $GLOBALS['_hooks']['lifecycle_resources'][$resource] = $provider;
    return true;
}

function resource_lifecycle_artifact(array $artifact): array
{
    $allowed = ['kind', 'role', 'managed', 'disk', 'root', 'relative_path', 'absolute_path', 'state', 'transition'];
    if (array_diff(array_keys($artifact), $allowed) !== []
        || ($artifact['kind'] ?? null) !== 'file'
        || !is_string($artifact['role'] ?? null)
        || !resource_lifecycle_name_is_valid($artifact['role'])
        || !is_bool($artifact['managed'] ?? null)
        || !is_string($artifact['state'] ?? null)
        || !in_array($artifact['state'], ['present', 'missing', 'unmanaged', 'removed'], true)) {
        throw new InvalidArgumentException('Invalid resource lifecycle artifact.');
    }
    foreach (['disk', 'root'] as $key) {
        if (isset($artifact[$key]) && (!is_string($artifact[$key]) || !resource_lifecycle_name_is_valid($artifact[$key]))) {
            throw new InvalidArgumentException('Invalid resource lifecycle artifact location.');
        }
    }
    foreach (['relative_path', 'absolute_path'] as $key) {
        if (isset($artifact[$key]) && (!is_string($artifact[$key]) || $artifact[$key] === ''
            || strlen($artifact[$key]) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $artifact[$key]) === 1)) {
            throw new InvalidArgumentException('Invalid resource lifecycle artifact path.');
        }
    }
    if (isset($artifact['relative_path'])) {
        $relative = $artifact['relative_path'];
        if (str_starts_with($relative, '/') || str_contains($relative, '\\')
            || in_array('', explode('/', $relative), true)
            || in_array('.', explode('/', $relative), true)
            || in_array('..', explode('/', $relative), true)) {
            throw new InvalidArgumentException('Invalid resource lifecycle relative artifact path.');
        }
    }
    if (isset($artifact['absolute_path'])
        && (!$artifact['managed'] || !str_starts_with($artifact['absolute_path'], DIRECTORY_SEPARATOR))) {
        throw new InvalidArgumentException('Only managed artifacts may expose absolute paths.');
    }
    if ($artifact['managed'] && $artifact['state'] === 'present'
        && (!isset($artifact['root'], $artifact['relative_path'], $artifact['absolute_path']))) {
        throw new InvalidArgumentException('Present managed artifacts require a complete location.');
    }
    if (isset($artifact['transition'])) {
        $transition = $artifact['transition'];
        if (!is_array($transition)
            || array_diff(array_keys($transition), ['operation', 'from', 'to']) !== []
            || !in_array($transition['operation'] ?? null, ['move', 'delete', 'create', 'none'], true)) {
            throw new InvalidArgumentException('Invalid resource lifecycle artifact transition.');
        }
        $hasFrom = isset($transition['from']);
        $hasTo = isset($transition['to']);
        $validEndpoints = match ($transition['operation']) {
            'move' => $hasFrom && $hasTo,
            'delete' => $hasFrom && !$hasTo,
            'create' => !$hasFrom && $hasTo,
            'none' => !$hasFrom && !$hasTo,
        };
        if (!$validEndpoints) {
            throw new InvalidArgumentException('Invalid resource lifecycle artifact transition endpoints.');
        }
        foreach (['from', 'to'] as $direction) {
            if (!isset($transition[$direction])) continue;
            $location = $transition[$direction];
            if (!is_array($location) || array_diff(array_keys($location), ['root', 'relative_path']) !== []
                || !is_string($location['root'] ?? null) || !resource_lifecycle_name_is_valid($location['root'])
                || !is_string($location['relative_path'] ?? null) || $location['relative_path'] === ''
                || strlen($location['relative_path']) > 4096
                || preg_match('/[\x00-\x1F\x7F]/', $location['relative_path']) === 1
                || str_starts_with($location['relative_path'], '/') || str_contains($location['relative_path'], '\\')
                || in_array('', explode('/', $location['relative_path']), true)
                || in_array('.', explode('/', $location['relative_path']), true)
                || in_array('..', explode('/', $location['relative_path']), true)) {
                throw new InvalidArgumentException('Invalid resource lifecycle artifact transition path.');
            }
        }
    }
    return $artifact;
}

function resource_lifecycle_items(array $items): array
{
    if ($items === [] || !array_is_list($items)) {
        throw new InvalidArgumentException('Resource lifecycle items must be a non-empty list.');
    }
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item) || array_diff(array_keys($item), ['id', 'before', 'after', 'artifacts']) !== []) {
            throw new InvalidArgumentException('Invalid resource lifecycle item.');
        }
        $id = $item['id'] ?? null;
        if ((!is_int($id) && !is_string($id)) || (string)$id === '' || strlen((string)$id) > 191
            || preg_match('/[\x00-\x1F\x7F]/', (string)$id) === 1 || isset($normalized[(string)$id])) {
            throw new InvalidArgumentException('Invalid or duplicate resource lifecycle item ID.');
        }
        if (!array_key_exists('before', $item) || ($item['before'] !== null && !is_array($item['before']))
            || !array_key_exists('after', $item) || ($item['after'] !== null && !is_array($item['after']))) {
            throw new InvalidArgumentException('Invalid resource lifecycle item snapshot.');
        }
        $artifacts = $item['artifacts'] ?? [];
        if (!is_array($artifacts) || !array_is_list($artifacts)) {
            throw new InvalidArgumentException('Invalid resource lifecycle artifact list.');
        }
        $item['artifacts'] = array_map('resource_lifecycle_artifact', $artifacts);
        $normalized[(string)$id] = $item;
    }
    uksort($normalized, 'strnatcmp');
    return array_values($normalized);
}

function resource_lifecycle_event(array $event): array
{
    $keys = ['schema', 'event_id', 'occurred_at', 'resource', 'operation', 'bulk', 'actor_id', 'source', 'items', 'metadata', 'result', 'warnings'];
    $eventKeys = array_keys($event);
    if (count($eventKeys) !== count($keys) || array_diff($eventKeys, $keys) !== [] || ($event['schema'] ?? null) !== 1
        || !is_string($event['event_id'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $event['event_id']) !== 1
        || !is_string($event['occurred_at'] ?? null)
        || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $event['occurred_at']) !== 1
        || !resource_lifecycle_name_is_valid((string)($event['resource'] ?? ''))
        || !resource_lifecycle_name_is_valid((string)($event['operation'] ?? ''))
        || !is_bool($event['bulk'] ?? null)
        || (!is_int($event['actor_id']) && $event['actor_id'] !== null)
        || !is_string($event['source'] ?? null) || !resource_lifecycle_name_is_valid($event['source'])
        || !is_array($event['metadata'] ?? null) || !is_array($event['result'] ?? null)
        || !is_array($event['warnings'] ?? null) || !array_is_list($event['warnings'])) {
        throw new InvalidArgumentException('Invalid resource lifecycle event.');
    }
    $event['items'] = resource_lifecycle_items($event['items']);
    if ($event['bulk'] !== (count($event['items']) > 1)) {
        throw new InvalidArgumentException('Resource lifecycle bulk state does not match its items.');
    }
    try {
        json_encode($event, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Resource lifecycle events must be JSON-serializable.');
    }
    return $event;
}

function resource_lifecycle_capture(
    PDO $pdo,
    string $resource,
    string $operation,
    array $lockedItems,
    array $context = []
): array {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Resource lifecycle capture requires an active transaction.');
    }
    $provider = $GLOBALS['_hooks']['lifecycle_resources'][$resource] ?? null;
    if (!is_array($provider) || !is_callable($provider['capture'] ?? null)) {
        throw new RuntimeException('No resource lifecycle provider is registered for ' . $resource . '.');
    }
    if (!resource_lifecycle_name_is_valid($operation)
        || array_diff(array_keys($context), ['actor_id', 'source', 'metadata']) !== []) {
        throw new InvalidArgumentException('Invalid resource lifecycle capture context.');
    }
    $actorId = $context['actor_id'] ?? null;
    $source = $context['source'] ?? 'core';
    $metadata = $context['metadata'] ?? [];
    if ((!is_int($actorId) && $actorId !== null) || !is_string($source)
        || !resource_lifecycle_name_is_valid($source) || !is_array($metadata)) {
        throw new InvalidArgumentException('Invalid resource lifecycle capture context.');
    }
    $database = new ResourceLifecycleDatabase($pdo);
    $items = call_user_func($provider['capture'], $database, $operation, $lockedItems, $context);
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Resource lifecycle provider changed transaction ownership.');
    }
    $items = resource_lifecycle_items(is_array($items) ? $items : []);
    $event = resource_lifecycle_event([
        'schema' => 1,
        'event_id' => bin2hex(random_bytes(32)),
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'resource' => $resource,
        'operation' => $operation,
        'bulk' => count($items) > 1,
        'actor_id' => $actorId,
        'source' => $source,
        'items' => $items,
        'metadata' => $metadata,
        'result' => [],
        'warnings' => [],
    ]);
    do_action('resource_lifecycle_before_mutation', $event, $database);
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Resource lifecycle listener changed transaction ownership.');
    }
    return $event;
}

function resource_lifecycle_before_commit(PDO $pdo, array $event, array $changes = []): array
{
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Resource lifecycle pre-commit requires an active transaction.');
    }
    $event = resource_lifecycle_event($event);
    if (array_diff(array_keys($changes), ['items', 'result', 'warnings']) !== []) {
        throw new InvalidArgumentException('Invalid resource lifecycle changes.');
    }
    if (isset($changes['items'])) {
        $changed = resource_lifecycle_items($changes['items']);
        if (array_column($changed, 'id') !== array_column($event['items'], 'id')) {
            throw new InvalidArgumentException('Resource lifecycle item identities cannot change.');
        }
        foreach ($changed as $index => $item) {
            if ($item['before'] !== $event['items'][$index]['before']) {
                throw new InvalidArgumentException('Resource lifecycle before snapshots are immutable.');
            }
        }
        $event['items'] = $changed;
    }
    if (isset($changes['result'])) {
        if (!is_array($changes['result'])) throw new InvalidArgumentException('Invalid resource lifecycle result.');
        $event['result'] = $changes['result'];
    }
    if (isset($changes['warnings'])) {
        if (!is_array($changes['warnings']) || !array_is_list($changes['warnings'])) {
            throw new InvalidArgumentException('Invalid resource lifecycle warnings.');
        }
        $event['warnings'] = $changes['warnings'];
    }
    $event = resource_lifecycle_event($event);
    do_action('resource_lifecycle_before_commit', $event, new ResourceLifecycleDatabase($pdo));
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Resource lifecycle listener changed transaction ownership.');
    }
    return $event;
}

function resource_lifecycle_after_commit(PDO $pdo, array $event): array
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Resource lifecycle committed notification requires no active transaction.');
    }
    $event = resource_lifecycle_event($event);
    $database = new ResourceLifecycleDatabase($pdo);
    $errors = [];
    foreach (array_merge(['resource_lifecycle_committed'], _hook_legacy_aliases('resource_lifecycle_committed')) as $hookName) {
        $hooks = $GLOBALS['_hooks']['actions'][$hookName] ?? [];
        ksort($hooks);
        foreach ($hooks as $priority => $listeners) {
            foreach ($listeners as $index => $listener) {
                try {
                    call_user_func($listener, $event, $database);
                } catch (Throwable $error) {
                    $errors[] = [
                        'hook' => $hookName,
                        'priority' => (int)$priority,
                        'listener' => (int)$index,
                        'exception' => get_class($error),
                        'message' => $error->getMessage(),
                        'code' => $error->getCode(),
                    ];
                }
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (Throwable $cleanupError) {
                        error_log('[resource-lifecycle] Unable to roll back a transaction left by a committed observer: ' . $cleanupError->getMessage());
                    }
                    $errors[] = [
                        'hook' => $hookName,
                        'priority' => (int)$priority,
                        'listener' => (int)$index,
                        'exception' => LogicException::class,
                        'message' => 'Committed observers cannot leave an active transaction.',
                        'code' => 0,
                    ];
                }
            }
        }
    }
    return $errors;
}
