<?php
declare(strict_types=1);

if (!function_exists('content_status_published_event')) {
    function content_status_published_event(array $event): array
    {
        $keys = [
            'schema',
            'event_id',
            'occurred_at',
            'content_type',
            'content_id',
            'actor_id',
            'source',
            'previous_status',
            'status_revision',
        ];
        $eventKeys = array_keys($event);
        if (count($eventKeys) !== count($keys)
            || array_diff($eventKeys, $keys) !== []
            || ($event['schema'] ?? null) !== 1
            || !is_string($event['event_id'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $event['event_id']) !== 1
            || !is_string($event['occurred_at'] ?? null)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $event['occurred_at']) !== 1
            || ($event['content_type'] ?? null) !== 'article'
            || !is_int($event['content_id'] ?? null)
            || $event['content_id'] < 1
            || (!is_int($event['actor_id'] ?? null) && ($event['actor_id'] ?? null) !== null)
            || (is_int($event['actor_id'] ?? null) && $event['actor_id'] < 1)
            || !is_string($event['source'] ?? null)
            || !resource_lifecycle_name_is_valid($event['source'])
            || (!is_string($event['previous_status'] ?? null) && ($event['previous_status'] ?? null) !== null)
            || !in_array($event['previous_status'], [null, 'draft', 'private'], true)
            || !is_int($event['status_revision'] ?? null)
            || $event['status_revision'] < 0) {
            throw new InvalidArgumentException('Invalid content publication event.');
        }
        return $event;
    }
}

if (!function_exists('content_status_published_create')) {
    function content_status_published_create(
        PDO $pdo,
        int $contentId,
        ?int $actorId,
        string $source,
        ?string $previousStatus,
        int $statusRevision
    ): array {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Content publication events must be created inside the mutation transaction.');
        }
        return content_status_published_event([
            'schema' => 1,
            'event_id' => bin2hex(random_bytes(32)),
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'content_type' => 'article',
            'content_id' => $contentId,
            'actor_id' => $actorId,
            'source' => $source,
            'previous_status' => $previousStatus,
            'status_revision' => $statusRevision,
        ]);
    }
}

if (!function_exists('content_status_published_notify')) {
    function content_status_published_notify(PDO $pdo, array $event): array
    {
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Content publication observers require no active transaction.');
        }
        $event = content_status_published_event($event);
        $errors = [];
        foreach (array_merge(['content_status_published'], _hook_legacy_aliases('content_status_published')) as $hookName) {
            $hooks = $GLOBALS['_hooks']['actions'][$hookName] ?? [];
            ksort($hooks);
            foreach ($hooks as $priority => $listeners) {
                foreach ($listeners as $index => $listener) {
                    try {
                        call_user_func($listener, $event, $pdo);
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
                            error_log('[content-publication] Unable to roll back a transaction left by an observer: ' . $cleanupError->getMessage());
                            if ($pdo->inTransaction()) {
                                throw new RuntimeException('Unable to restore transaction ownership after a content publication observer.', 0, $cleanupError);
                            }
                        }
                        $errors[] = [
                            'hook' => $hookName,
                            'priority' => (int)$priority,
                            'listener' => (int)$index,
                            'exception' => LogicException::class,
                            'message' => 'Content publication observers cannot leave an active transaction.',
                            'code' => 0,
                        ];
                    }
                }
            }
        }
        return $errors;
    }
}

if (!function_exists('content_status_published_notify_safely')) {
    function content_status_published_notify_safely(PDO $pdo, array $event): void
    {
        try {
            foreach (content_status_published_notify($pdo, $event) as $error) {
                error_log('[content-publication] Observer failed: ' . (string)($error['message'] ?? 'Unknown listener error.'));
            }
        } catch (Throwable $error) {
            error_log('[content-publication] Notification failed: ' . $error->getMessage());
            if ($pdo->inTransaction()) {
                throw new RuntimeException('Content publication notification left an active transaction.', 0, $error);
            }
        }
    }
}
