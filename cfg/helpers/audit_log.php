<?php
declare(strict_types=1);

const AUDIT_LOG_JSON_MAX_BYTES = 32768;
const AUDIT_LOG_VALUE_MAX_BYTES = 1024;
const AUDIT_LOG_MAX_DEPTH = 6;
const AUDIT_LOG_MAX_ITEMS = 100;

function audit_log_id(): string
{
    return bin2hex(random_bytes(32));
}

function audit_log_operation_id(): string
{
    return audit_log_id();
}

function audit_log_name(string $value, int $maxBytes, string $field): string
{
    $value = strtolower(trim($value));
    if ($value === '' || strlen($value) > $maxBytes
        || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid audit ' . $field . '.');
    }
    return $value;
}

function audit_log_text(mixed $value, int $maxBytes, string $field, bool $nullable = false): ?string
{
    if ($value === null && $nullable) return null;
    if (!is_string($value)) throw new InvalidArgumentException('Invalid audit ' . $field . '.');
    $value = trim($value);
    if (($value === '' && !$nullable) || strlen($value) > $maxBytes
        || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
        throw new InvalidArgumentException('Invalid audit ' . $field . '.');
    }
    return $value === '' ? null : $value;
}

function audit_log_projection_text(mixed $value, int $maxBytes, string $field, bool $nullable = false): ?string
{
    if ($value === null && $nullable) return null;
    if (!is_string($value)) throw new InvalidArgumentException('Invalid audit ' . $field . '.');
    $value = trim($value);
    if (($value === '' && !$nullable) || preg_match('//u', $value) !== 1
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
        throw new InvalidArgumentException('Invalid audit ' . $field . '.');
    }
    if ($value === '') return null;
    if (strlen($value) <= $maxBytes) return $value;
    return mb_strcut($value, 0, max(1, $maxBytes - 3), 'UTF-8') . '...';
}

function audit_log_sensitive_key(string $key): bool
{
    $key = (string)preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key);
    $key = (string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
    $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $key));
    return preg_match('/(?:^|_)(?:password|passwd|secret|token|csrf|cookie|authorization|auth|bearer|api_key|private_key|session|credential)(?:_|$)/', $normalized) === 1;
}

function audit_log_id_set(array $ids): array
{
    $normalized = [];
    foreach ($ids as $id) {
        if (!is_int($id) && !(is_string($id) && preg_match('/\A[1-9][0-9]*\z/D', $id) === 1)) {
            throw new InvalidArgumentException('Invalid audit identifier set.');
        }
        $id = (int)$id;
        if ($id <= 0) throw new InvalidArgumentException('Invalid audit identifier set.');
        $normalized[$id] = $id;
    }
    $normalized = array_values($normalized);
    sort($normalized, SORT_NUMERIC);
    if (count($normalized) <= AUDIT_LOG_MAX_ITEMS) return $normalized;
    return [
        'count' => count($normalized),
        'sha256' => hash('sha256', implode(',', $normalized)),
        'sample' => array_slice($normalized, 0, 50),
        'truncated' => true,
    ];
}

function audit_log_normalize_value(mixed $value, int $depth = 0, ?string $key = null): mixed
{
    if ($key !== null && audit_log_sensitive_key($key)) return '[redacted]';
    if ($depth > AUDIT_LOG_MAX_DEPTH) throw new InvalidArgumentException('Audit data exceeds the nesting limit.');
    if ($value === null || is_bool($value) || is_int($value)) return $value;
    if (is_float($value)) {
        if (!is_finite($value)) throw new InvalidArgumentException('Audit data contains a non-finite number.');
        return $value;
    }
    if (is_string($value)) {
        if (preg_match('//u', $value) !== 1) throw new InvalidArgumentException('Audit data must be valid UTF-8.');
        if (strlen($value) <= AUDIT_LOG_VALUE_MAX_BYTES) return $value;
        return mb_strcut($value, 0, AUDIT_LOG_VALUE_MAX_BYTES - 15, 'UTF-8') . '[truncated]';
    }
    if (!is_array($value) || count($value) > AUDIT_LOG_MAX_ITEMS) {
        throw new InvalidArgumentException('Audit data contains an unsupported or oversized value.');
    }
    $normalized = [];
    foreach ($value as $itemKey => $itemValue) {
        if (!is_int($itemKey) && !is_string($itemKey)) throw new InvalidArgumentException('Invalid audit data key.');
        if (is_string($itemKey) && ($itemKey === '' || strlen($itemKey) > 191 || preg_match('//u', $itemKey) !== 1)) {
            throw new InvalidArgumentException('Invalid audit data key.');
        }
        $normalized[$itemKey] = audit_log_normalize_value($itemValue, $depth + 1, is_string($itemKey) ? $itemKey : null);
    }
    return $normalized;
}

function audit_log_json(array $value, string $field): ?string
{
    if ($value === []) return null;
    $normalized = audit_log_normalize_value($value);
    $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($json) > AUDIT_LOG_JSON_MAX_BYTES) {
        throw new InvalidArgumentException('Audit ' . $field . ' exceeds the storage limit.');
    }
    return $json;
}

function audit_log_changes(array $before, array $after, array $redactedFields = []): array
{
    $redacted = array_fill_keys(array_map('strval', $redactedFields), true);
    $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
    if (count($keys) > AUDIT_LOG_MAX_ITEMS) throw new InvalidArgumentException('Audit changes exceed the field limit.');
    sort($keys, SORT_STRING);
    $changes = [];
    foreach ($keys as $key) {
        if (!is_string($key) || $key === '') throw new InvalidArgumentException('Invalid audit change field.');
        $old = $before[$key] ?? null;
        $new = $after[$key] ?? null;
        if ($old === $new) continue;
        $changes[$key] = isset($redacted[$key]) || audit_log_sensitive_key($key)
            ? ['changed' => true, 'value' => '[redacted]']
            : ['before' => audit_log_normalize_value($old, 1, $key), 'after' => audit_log_normalize_value($new, 1, $key)];
    }
    return $changes;
}

function audit_log_request_context(?array $server = null, ?array $query = null): array
{
    $server ??= $_SERVER;
    $query ??= $_GET;
    static $requestId = null;
    $requestId ??= audit_log_id();

    $method = strtoupper(trim(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : ''));
    if ($method === '' || preg_match('/\A[A-Z]{1,10}\z/D', $method) !== 1) $method = null;

    $requestUri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '';
    $path = $requestUri !== '' ? parse_url($requestUri, PHP_URL_PATH) : null;
    if (!is_string($path) || $path === '' || str_starts_with($path, '//') || preg_match('//u', $path) !== 1
        || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        $path = null;
    } elseif (strlen($path) > 1024) {
        $path = mb_strcut($path, 0, 1024, 'UTF-8');
    }

    $route = is_scalar($query['page'] ?? null) ? trim((string)$query['page'], " \t\n\r\0\x0B/") : '';
    if ($route === '' || strlen($route) > 191 || preg_match('/\A[a-z0-9_\/-]+\z/D', $route) !== 1) $route = null;

    $ip = null;
    $remoteAddress = is_string($server['REMOTE_ADDR'] ?? null) ? trim($server['REMOTE_ADDR']) : '';
    if ($remoteAddress !== '') {
        $packed = @inet_pton($remoteAddress);
        if (is_string($packed) && (strlen($packed) === 4 || strlen($packed) === 16)) $ip = $packed;
    }

    $userAgent = is_string($server['HTTP_USER_AGENT'] ?? null) ? $server['HTTP_USER_AGENT'] : '';
    return [
        'request_id' => $requestId,
        'request_method' => $method,
        'request_path' => $path,
        'route' => $route,
        'ip_address' => $ip,
        'user_agent_hash' => $userAgent === '' ? null : hash('sha256', $userAgent),
    ];
}

function audit_log_actor_snapshot(PDO $pdo, ?int $actorUserId): array
{
    if ($actorUserId === null) return ['label' => 'System', 'email' => null];
    if ($actorUserId <= 0) throw new InvalidArgumentException('Invalid audit actor.');
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $actorUserId;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $stmt = $pdo->prepare('SELECT name, username, email FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $actorUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('Audit actor is unavailable.');
    $label = trim((string)($row['name'] ?? ''));
    if ($label === '') $label = trim((string)($row['username'] ?? ''));
    if ($label === '') $label = 'User #' . $actorUserId;
    return $cache[$cacheKey] = [
        'label' => audit_log_projection_text($label, 191, 'actor label'),
        'email' => audit_log_projection_text((string)($row['email'] ?? ''), 255, 'actor email', true),
    ];
}

function audit_log_dashboard_actions_enabled(PDO $pdo): bool
{
    if (!function_exists('settings_get')) return true;
    return settings_get($pdo, 'audit_dashboard_actions_enabled', '1') === '1';
}

function audit_log_record(PDO $pdo, array $entry): int
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Audit writes require the caller transaction.');
    $allowed = [
        'provider', 'event_key', 'actor_user_id', 'resource_type', 'resource_id', 'resource_label',
        'source', 'operation_id', 'changes', 'metadata', 'authorization_audit_id',
    ];
    if (array_diff(array_keys($entry), $allowed) !== []) throw new InvalidArgumentException('Unknown audit entry field.');

    $actorId = $entry['actor_user_id'] ?? null;
    if ($actorId !== null && (!is_int($actorId) || $actorId <= 0)) throw new InvalidArgumentException('Invalid audit actor.');
    $actor = audit_log_actor_snapshot($pdo, $actorId);
    $context = audit_log_request_context();
    $eventId = audit_log_id();
    $operationId = $entry['operation_id'] ?? $eventId;
    if (!is_string($operationId) || preg_match('/\A[a-f0-9]{64}\z/D', $operationId) !== 1) {
        throw new InvalidArgumentException('Invalid audit operation ID.');
    }
    $authorizationAuditId = $entry['authorization_audit_id'] ?? null;
    if ($authorizationAuditId !== null && (!is_int($authorizationAuditId) || $authorizationAuditId <= 0)) {
        throw new InvalidArgumentException('Invalid authorization audit link.');
    }

    $values = [
        ':event_id' => $eventId,
        ':request_id' => $context['request_id'],
        ':operation_id' => $operationId,
        ':provider' => audit_log_name((string)($entry['provider'] ?? ''), 100, 'provider'),
        ':event_key' => audit_log_name((string)($entry['event_key'] ?? ''), 191, 'event key'),
        ':actor_user_id' => $actorId,
        ':actor_label' => $actor['label'],
        ':actor_email' => $actor['email'],
        ':resource_type' => audit_log_name((string)($entry['resource_type'] ?? ''), 100, 'resource type'),
        ':resource_id' => audit_log_text($entry['resource_id'] ?? null, 191, 'resource ID'),
        ':resource_label' => audit_log_projection_text($entry['resource_label'] ?? null, 255, 'resource label', true),
        ':source' => audit_log_name((string)($entry['source'] ?? ''), 100, 'source'),
        ':route' => $context['route'],
        ':request_method' => $context['request_method'],
        ':request_path' => $context['request_path'],
        ':ip_address' => $context['ip_address'],
        ':user_agent_hash' => $context['user_agent_hash'],
        ':changes' => audit_log_json(is_array($entry['changes'] ?? null) ? $entry['changes'] : [], 'changes'),
        ':metadata' => audit_log_json(is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [], 'metadata'),
        ':authorization_audit_id' => $authorizationAuditId,
        ':occurred_at_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
    ];
    if ($actorId !== null && !audit_log_dashboard_actions_enabled($pdo)) return 0;

    $stmt = $pdo->prepare(
        'INSERT INTO audit_events
            (event_id, request_id, operation_id, provider, event_key, actor_user_id, actor_label, actor_email,
             resource_type, resource_id, resource_label, source, route, request_method, request_path,
             ip_address, user_agent_hash, changes, metadata, authorization_audit_id, occurred_at_utc)
         VALUES
            (:event_id, :request_id, :operation_id, :provider, :event_key, :actor_user_id, :actor_label, :actor_email,
             :resource_type, :resource_id, :resource_label, :source, :route, :request_method, :request_path,
             :ip_address, :user_agent_hash, :changes, :metadata, :authorization_audit_id, :occurred_at_utc)'
    );
    $stmt->execute($values);
    if (!$pdo->inTransaction()) throw new RuntimeException('Audit write changed transaction ownership.');
    $id = (int)$pdo->lastInsertId();
    if ($id <= 0) throw new RuntimeException('Audit event ID is unavailable.');
    return $id;
}

function audit_log_content_event(
    PDO $pdo,
    string $resource,
    string $operation,
    ?int $actorUserId,
    int $resourceId,
    string $resourceLabel,
    string $source,
    array $before,
    array $after,
    array $options = []
): int {
    if (!in_array($resource, ['article', 'page'], true) || $resourceId <= 0
        || array_diff(array_keys($options), ['operation_id', 'metadata', 'authorization_audit_id', 'redacted_fields']) !== []) {
        throw new InvalidArgumentException('Invalid content audit event.');
    }
    return audit_log_record($pdo, [
        'provider' => 'core',
        'event_key' => $resource . '.' . audit_log_name($operation, 80, 'content operation'),
        'actor_user_id' => $actorUserId,
        'resource_type' => $resource,
        'resource_id' => (string)$resourceId,
        'resource_label' => $resourceLabel,
        'source' => $source,
        'operation_id' => $options['operation_id'] ?? audit_log_operation_id(),
        'changes' => audit_log_changes($before, $after, (array)($options['redacted_fields'] ?? [])),
        'metadata' => is_array($options['metadata'] ?? null) ? $options['metadata'] : [],
        'authorization_audit_id' => $options['authorization_audit_id'] ?? null,
    ]);
}
