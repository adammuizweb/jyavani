<?php
declare(strict_types=1);

if (!function_exists('content_schedule_statuses')) {
    function content_schedule_statuses(): array
    {
        return ['draft', 'published', 'private', 'scheduled'];
    }
}

if (!function_exists('content_schedule_editor_status')) {
    function content_schedule_editor_status(string $status, array $content): string
    {
        return $status === 'draft' && !empty($content['publish_at_utc']) ? 'scheduled' : $status;
    }
}

if (!function_exists('content_schedule_status_sql')) {
    function content_schedule_status_sql(string $statusExpression = 'p.status', string $tableAlias = 'p'): string
    {
        return "CASE WHEN ({$statusExpression}) = 'draft' AND {$tableAlias}.publish_at_utc IS NOT NULL THEN 'scheduled' ELSE ({$statusExpression}) END";
    }
}

if (!function_exists('content_schedule_datetime_local')) {
    function content_schedule_datetime_local(?string $publishAtUtc): string
    {
        return app_utc_mysql_to_site($publishAtUtc)?->format('Y-m-d\\TH:i') ?? '';
    }
}

if (!function_exists('content_schedule_resolve')) {
    function content_schedule_resolve(string $requestedStatus, ?string $localInput): array
    {
        if (!in_array($requestedStatus, content_schedule_statuses(), true)) $requestedStatus = 'draft';
        if ($requestedStatus !== 'scheduled') {
            return ['status' => $requestedStatus, 'publish_at_utc' => null, 'editor_status' => $requestedStatus];
        }

        $scheduled = app_parse_site_datetime_local($localInput);
        if (!$scheduled) throw new InvalidArgumentException('Invalid scheduled publication time.');
        $wallAsUtc = app_parse_exact_datetime($localInput, 'Y-m-d\\TH:i', app_utc_timezone());
        $wallTimestamp = $wallAsUtc?->getTimestamp();
        if ($wallTimestamp === null) throw new InvalidArgumentException('Invalid scheduled publication time.');
        $offsets = [];
        foreach (app_timezone()->getTransitions($wallTimestamp - 86400, $wallTimestamp + 86400) ?: [] as $transition) {
            $offsets[(int)$transition['offset']] = true;
        }
        $candidates = [];
        foreach (array_keys($offsets) as $offset) {
            $candidate = (new DateTimeImmutable('@' . ($wallTimestamp - $offset)))->setTimezone(app_timezone());
            if ($candidate->format('Y-m-d\\TH:i') === $localInput) {
                $candidates[app_site_datetime_to_utc_mysql($candidate)] = true;
            }
        }
        if (count($candidates) > 1) {
            throw new InvalidArgumentException('Scheduled publication time is ambiguous in the site timezone.');
        }
        $publishAtUtc = (string)(array_key_first($candidates) ?? app_site_datetime_to_utc_mysql($scheduled));
        if ($publishAtUtc <= app_now_utc_mysql()) {
            throw new InvalidArgumentException('Scheduled publication time must be in the future.');
        }
        return ['status' => 'draft', 'publish_at_utc' => $publishAtUtc, 'editor_status' => 'scheduled'];
    }
}

if (!function_exists('content_schedule_transition_error')) {
    function content_schedule_transition_error(string $existingStatus, string $requestedStatus): ?string
    {
        return $existingStatus === 'published' && $requestedStatus === 'scheduled'
            ? 'Published content must be changed to Draft before it can be scheduled.'
            : null;
    }
}

if (!function_exists('content_scheduler_notify_published')) {
    function content_scheduler_notify_published(array $item, PDO $pdo): array
    {
        if ($pdo->inTransaction()) throw new RuntimeException('Scheduler observers require no active transaction.');
        $errors = [];
        foreach (array_merge(['content_scheduler_published'], _hook_legacy_aliases('content_scheduler_published')) as $hookName) {
            $hooks = $GLOBALS['_hooks']['actions'][$hookName] ?? [];
            ksort($hooks);
            foreach ($hooks as $priority => $listeners) {
                foreach ($listeners as $index => $listener) {
                    try {
                        call_user_func($listener, $item, $pdo);
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
                            error_log('[content-scheduler] Unable to roll back a transaction left by an observer: ' . $cleanupError->getMessage());
                        }
                        $errors[] = [
                            'hook' => $hookName,
                            'priority' => (int)$priority,
                            'listener' => (int)$index,
                            'exception' => LogicException::class,
                            'message' => 'Scheduler observers cannot leave an active transaction.',
                            'code' => 0,
                        ];
                    }
                }
            }
        }
        return $errors;
    }
}

if (!function_exists('content_scheduler_last_errors')) {
    function content_scheduler_last_errors(): array
    {
        return is_array($GLOBALS['__CONTENT_SCHEDULER_ERRORS'] ?? null)
            ? $GLOBALS['__CONTENT_SCHEDULER_ERRORS']
            : [];
    }
}

if (!function_exists('content_scheduler_publish_due')) {
    function content_scheduler_publish_due(PDO $pdo, int $limit = 100): array
    {
        if ($pdo->inTransaction()) throw new RuntimeException('Scheduled publication requires no active transaction.');
        $GLOBALS['__CONTENT_SCHEDULER_ERRORS'] = [];
        $limit = max(1, min(500, $limit));
        $candidateLimit = min(5000, max(100, $limit * 10));
        app_db_set_session_timezone($pdo, app_timezone_id());

        $select = $pdo->prepare(
            "SELECT id
             FROM posts
             WHERE type IN ('article', 'page', 'theme')
               AND status = 'draft'
               AND is_deleted = 0
               AND publish_at_utc IS NOT NULL
               AND publish_at_utc <= UTC_TIMESTAMP()
             ORDER BY publish_at_utc ASC, id ASC
             LIMIT :limit"
        );
        $select->bindValue(':limit', $candidateLimit, PDO::PARAM_INT);
        $select->execute();
        $dueIds = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $publish = $pdo->prepare(
            "UPDATE posts
             SET status = 'published',
                 status_revision = status_revision + 1,
                 publish_at_utc = NULL,
                 updated_at = :updated_at,
                 updated_by = NULL
             WHERE id = :id
               AND type = :type
               AND status = 'draft'
               AND is_deleted = 0
               AND publish_at_utc = :publish_at_utc
               AND publish_at_utc <= UTC_TIMESTAMP()"
        );
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $lockSuffix = in_array($driver, ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '';
        $lock = $pdo->prepare(
            "SELECT *
             FROM posts
             WHERE id = :id
               AND type IN ('article', 'page', 'theme')
               AND status = 'draft'
               AND is_deleted = 0
               AND publish_at_utc IS NOT NULL
               AND publish_at_utc <= UTC_TIMESTAMP()
             LIMIT 1{$lockSuffix}"
        );
        $published = [];
        foreach ($dueIds as $dueId) {
            if (count($published) >= $limit) break;
            $pdo->beginTransaction();
            try {
                $lock->execute([':id' => $dueId]);
                $item = $lock->fetch(PDO::FETCH_ASSOC);
                if (!is_array($item)) {
                    $pdo->rollBack();
                    continue;
                }
                $editorHook = match ((string)$item['type']) {
                    'article' => 'admin_post_editor_status',
                    'page' => 'admin_page_editor_status',
                    default => null,
                };
                if ($editorHook !== null) {
                    $sourceStatus = apply_filters($editorHook, (string)$item['status'], $item, $pdo);
                    if (!is_string($sourceStatus) || !in_array($sourceStatus, ['draft', 'published', 'private'], true)) {
                        throw new DomainException('Scheduled content editor status is invalid.');
                    }
                    if ($sourceStatus !== 'draft') {
                        throw new DomainException('Scheduled content source status is no longer draft.');
                    }
                }
                do_action('content_scheduler_before_publish', $item, $pdo);
                if (!$pdo->inTransaction()) throw new RuntimeException('Scheduler listener changed transaction ownership.');
                $publish->execute([
                    ':updated_at' => app_now_wall_mysql(),
                    ':id' => (int)$item['id'],
                    ':type' => (string)$item['type'],
                    ':publish_at_utc' => (string)$item['publish_at_utc'],
                ]);
                if ($publish->rowCount() !== 1) throw new RuntimeException('Scheduled content changed before publication.');
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $GLOBALS['__CONTENT_SCHEDULER_ERRORS'][] = [
                    'id' => $dueId,
                    'exception' => get_class($error),
                    'message' => $error->getMessage(),
                ];
                error_log('[content-scheduler] Publication failed for content ' . $dueId . ': ' . $error->getMessage());
                continue;
            }
            $published[] = $item;
            foreach (content_scheduler_notify_published($item, $pdo) as $error) {
                error_log('[content-scheduler] Observer failed: ' . (string)($error['message'] ?? 'Unknown listener error.'));
            }
        }
        return $published;
    }
}
