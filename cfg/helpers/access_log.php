<?php
declare(strict_types=1);

const ACCESS_LOG_RETENTION_DAYS = [30, 90, 180, 365];

function access_log_route(string $route): string
{
    $route = strtolower(trim($route, " \t\n\r\0\x0B/"));
    if ($route === '' || strlen($route) > 191 || preg_match('/\A[a-z0-9][a-z0-9._~\/-]*\z/D', $route) !== 1) {
        throw new InvalidArgumentException('Invalid access route.');
    }
    return $route;
}

function access_log_settings(PDO $pdo): array
{
    $retention = (int)settings_get($pdo, 'audit_access_retention_days', '90');
    if (!in_array($retention, ACCESS_LOG_RETENTION_DAYS, true)) $retention = 90;
    return [
        'actions_enabled' => audit_log_dashboard_actions_enabled($pdo),
        'dashboard_enabled' => settings_get($pdo, 'audit_dashboard_navigation_enabled', '0') === '1',
        'retention_days' => $retention,
    ];
}

function access_log_is_document_request(?array $server = null): bool
{
    $server ??= $_SERVER;
    if (strtoupper((string)($server['REQUEST_METHOD'] ?? '')) !== 'GET') return false;
    if (strtolower((string)($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') return false;

    $purpose = strtolower(trim((string)($server['HTTP_SEC_PURPOSE'] ?? $server['HTTP_PURPOSE'] ?? '')));
    if ($purpose !== '' && (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender'))) return false;

    $mode = strtolower(trim((string)($server['HTTP_SEC_FETCH_MODE'] ?? '')));
    $destination = strtolower(trim((string)($server['HTTP_SEC_FETCH_DEST'] ?? '')));
    if ($mode !== '' && $mode !== 'navigate') return false;
    if ($destination !== '' && $destination !== 'document') return false;
    if ($mode === '' && $destination === '') {
        $accept = strtolower((string)($server['HTTP_ACCEPT'] ?? ''));
        if (!str_contains($accept, 'text/html') && !str_contains($accept, 'application/xhtml+xml')) return false;
    }
    return true;
}

function access_log_prune(PDO $pdo, int $retentionDays, int $limit = 1000): int
{
    if (!in_array($retentionDays, ACCESS_LOG_RETENTION_DAYS, true)) {
        throw new InvalidArgumentException('Invalid access log retention.');
    }
    $limit = max(1, min(5000, $limit));
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $retentionDays . ' days')
        ->format('Y-m-d H:i:s.u');
    $select = $pdo->prepare('SELECT id FROM access_events WHERE occurred_at_utc < :cutoff ORDER BY id ASC LIMIT ' . $limit);
    $select->execute([':cutoff' => $cutoff]);
    $ids = array_values(array_filter(array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn(int $id): bool => $id > 0));
    if ($ids === []) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $delete = $pdo->prepare("DELETE FROM access_events WHERE id IN ($placeholders)");
    $delete->execute($ids);
    return $delete->rowCount();
}

function access_log_mark_prune_due(PDO $pdo): void
{
    $due = '1970-01-01 00:00:00';
    $stmt = $pdo->prepare("UPDATE settings SET `value` = :due WHERE `key` = 'audit_access_last_pruned_utc'");
    $stmt->execute([':due' => $due]);
    if (isset($GLOBALS['__jy_settings_autoload_cache']) && is_array($GLOBALS['__jy_settings_autoload_cache'])) {
        $GLOBALS['__jy_settings_autoload_cache']['audit_access_last_pruned_utc'] = $due;
    }
}

function access_log_maybe_prune(PDO $pdo, int $retentionDays, int $limit = 1000): int
{
    static $attempted = false;
    if ($attempted || $pdo->inTransaction()) return 0;
    $attempted = true;
    $last = settings_get($pdo, 'audit_access_last_pruned_utc', '1970-01-01 00:00:00') ?? '1970-01-01 00:00:00';
    $lastInstant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $last, new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($lastInstant && $lastInstant > $now->modify('-1 hour')) return 0;

    $nowValue = $now->format('Y-m-d H:i:s');
    $claim = $pdo->prepare(
        "UPDATE settings SET `value` = :now
         WHERE `key` = 'audit_access_last_pruned_utc' AND `value` = :previous"
    );
    $claim->execute([':now' => $nowValue, ':previous' => $last]);
    if ($claim->rowCount() !== 1) return 0;
    if (isset($GLOBALS['__jy_settings_autoload_cache']) && is_array($GLOBALS['__jy_settings_autoload_cache'])) {
        $GLOBALS['__jy_settings_autoload_cache']['audit_access_last_pruned_utc'] = $nowValue;
    }
    $deleted = access_log_prune($pdo, $retentionDays, $limit);
    if ($deleted === $limit) access_log_mark_prune_due($pdo);
    return $deleted;
}

function access_log_record(PDO $pdo, array $entry, ?array $server = null): int
{
    if ($pdo->inTransaction()) throw new RuntimeException('Access logging requires no active transaction.');
    $allowed = [
        'surface', 'event_key', 'actor_user_id', 'route', 'page_title',
        'resource_type', 'resource_id', 'resource_label', 'response_status',
    ];
    if (array_diff(array_keys($entry), $allowed) !== []) throw new InvalidArgumentException('Unknown access event field.');

    $surface = (string)($entry['surface'] ?? '');
    if ($surface !== 'dashboard') throw new InvalidArgumentException('Invalid access event surface.');
    $actorId = $entry['actor_user_id'] ?? null;
    if ($actorId !== null && (!is_int($actorId) || $actorId <= 0)) throw new InvalidArgumentException('Invalid access event actor.');
    $actor = $actorId === null ? ['label' => 'Anonymous', 'email' => null] : audit_log_actor_snapshot($pdo, $actorId);
    $status = (int)($entry['response_status'] ?? 0);
    if ($status < 200 || $status >= 300) throw new InvalidArgumentException('Invalid access response status.');

    $context = audit_log_request_context($server);
    if (!is_string($context['request_path']) || $context['request_path'] === '') {
        throw new InvalidArgumentException('Invalid access request path.');
    }
    $resourceType = $entry['resource_type'] ?? null;
    if ($resourceType !== null) $resourceType = audit_log_name((string)$resourceType, 100, 'access resource type');
    $resourceId = $entry['resource_id'] ?? null;
    if ($resourceId !== null) $resourceId = audit_log_text($resourceId, 191, 'access resource ID', true);

    $eventId = audit_log_id();
    $stmt = $pdo->prepare(
        'INSERT INTO access_events
            (event_id, request_id, session_correlation_id, surface, event_key, actor_user_id, actor_label, actor_email,
             route, request_path, page_title, resource_type, resource_id, resource_label,
             response_status, ip_address, user_agent_hash, user_agent, occurred_at_utc)
         VALUES
            (:event_id, :request_id, :session_correlation_id, :surface, :event_key, :actor_user_id, :actor_label, :actor_email,
             :route, :request_path, :page_title, :resource_type, :resource_id, :resource_label,
             :response_status, :ip_address, :user_agent_hash, :user_agent, :occurred_at_utc)'
    );
    $stmt->execute([
        ':event_id' => $eventId,
        ':request_id' => $context['request_id'],
        ':session_correlation_id' => $actorId === null ? null : $context['session_correlation_id'],
        ':surface' => $surface,
        ':event_key' => audit_log_name((string)($entry['event_key'] ?? ''), 191, 'access event key'),
        ':actor_user_id' => $actorId,
        ':actor_label' => $actor['label'],
        ':actor_email' => $actor['email'],
        ':route' => access_log_route((string)($entry['route'] ?? '')),
        ':request_path' => $context['request_path'],
        ':page_title' => audit_log_projection_text($entry['page_title'] ?? null, 255, 'access page title', true),
        ':resource_type' => $resourceType,
        ':resource_id' => $resourceId,
        ':resource_label' => audit_log_projection_text($entry['resource_label'] ?? null, 255, 'access resource label', true),
        ':response_status' => $status,
        ':ip_address' => $context['ip_address'],
        ':user_agent_hash' => $context['user_agent_hash'],
        ':user_agent' => $context['user_agent'],
        ':occurred_at_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
    ]);
    $id = (int)$pdo->lastInsertId();
    if ($id <= 0) throw new RuntimeException('Access event ID is unavailable.');
    return $id;
}

function access_log_try_record_document(PDO $pdo, array $entry, ?array $server = null): bool
{
    static $recorded = false;
    $surface = (string)($entry['surface'] ?? '');
    if ($surface !== 'dashboard' || $recorded) return false;
    $settings = access_log_settings($pdo);
    try {
        access_log_maybe_prune($pdo, $settings['retention_days']);
    } catch (Throwable $error) {
        error_log('[access-log] Retention failed: ' . $error->getMessage());
    }
    if (!$settings['dashboard_enabled'] || !access_log_is_document_request($server)) return false;

    try {
        $id = access_log_record($pdo, $entry, $server);
        $recorded = true;
        return true;
    } catch (Throwable $error) {
        error_log('[access-log] Record failed: ' . $error->getMessage());
        return false;
    }
}
