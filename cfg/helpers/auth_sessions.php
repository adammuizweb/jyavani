<?php
declare(strict_types=1);

const AUTH_SESSIONS_TOUCH_INTERVAL = 300;
const AUTH_SESSIONS_RETENTION_DAYS = 30;

function auth_sessions_hash_is_valid(?string $hash): bool
{
    return is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1;
}

function auth_sessions_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function auth_sessions_mysql_time(DateTimeInterface $time): string
{
    return $time->format('Y-m-d H:i:s.u');
}

function auth_sessions_request_metadata(?array $server = null): array
{
    $server ??= $_SERVER;
    $ip = null;
    $remote = trim(is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '');
    if ($remote !== '') {
        $packed = @inet_pton($remote);
        if (is_string($packed) && in_array(strlen($packed), [4, 16], true)) $ip = $packed;
    }

    $rawUserAgent = is_string($server['HTTP_USER_AGENT'] ?? null) ? $server['HTTP_USER_AGENT'] : '';
    $userAgent = null;
    if ($rawUserAgent !== '' && preg_match('//u', $rawUserAgent) === 1
        && preg_match('/[\x00-\x1F\x7F]/', $rawUserAgent) !== 1) {
        $userAgent = trim($rawUserAgent);
        if ($userAgent === '') {
            $userAgent = null;
        } elseif (strlen($userAgent) > 1024) {
            $userAgent = mb_strcut($userAgent, 0, 1021, 'UTF-8') . '...';
        }
    }

    return [
        'ip_address' => $ip,
        'user_agent' => $userAgent,
        'user_agent_hash' => $rawUserAgent === '' ? null : hash('sha256', $rawUserAgent),
    ];
}

function auth_sessions_establish(PDO $pdo, int $userId, string $sessionKeyHash, int $lifetime): void
{
    if ($userId <= 0 || !auth_sessions_hash_is_valid($sessionKeyHash)) {
        throw new InvalidArgumentException('Invalid login session identity.');
    }

    $now = auth_sessions_now();
    $expires = $now->modify('+' . max(1, $lifetime) . ' seconds');
    $metadata = auth_sessions_request_metadata();
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO user_sessions
                (session_key_hash, user_id, created_at_utc, last_seen_at_utc, expires_at_utc,
                 ip_address, user_agent_hash, user_agent)
             VALUES
                (:session_key_hash, :user_id, :created_at_utc, :last_seen_at_utc, :expires_at_utc,
                 :ip_address, :user_agent_hash, :user_agent)'
        );
        $stmt->execute([
            ':session_key_hash' => $sessionKeyHash,
            ':user_id' => $userId,
            ':created_at_utc' => auth_sessions_mysql_time($now),
            ':last_seen_at_utc' => auth_sessions_mysql_time($now),
            ':expires_at_utc' => auth_sessions_mysql_time($expires),
            ':ip_address' => $metadata['ip_address'],
            ':user_agent_hash' => $metadata['user_agent_hash'],
            ':user_agent' => $metadata['user_agent'],
        ]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function auth_sessions_validate(PDO $pdo, int $userId, string $sessionKeyHash, int $lifetime): array
{
    if ($userId <= 0 || !auth_sessions_hash_is_valid($sessionKeyHash)) {
        return ['ok' => false, 'reason' => 'invalid'];
    }

    static $valid = [];
    $cacheKey = spl_object_id($pdo) . ':' . $userId . ':' . $sessionKeyHash;
    if (isset($valid[$cacheKey])) return $valid[$cacheKey];

    $now = auth_sessions_now();
    $nowSql = auth_sessions_mysql_time($now);
    try {
        $stmt = $pdo->prepare(
            'SELECT s.last_seen_at_utc
             FROM user_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.session_key_hash = :session_key_hash
               AND s.user_id = :user_id
               AND s.revoked_at_utc IS NULL
               AND s.expires_at_utc > :now_utc
               AND u.is_deleted = 0
               AND u.is_locked = 0
             LIMIT 1'
        );
        $stmt->execute([
            ':session_key_hash' => $sessionKeyHash,
            ':user_id' => $userId,
            ':now_utc' => $nowSql,
        ]);
        $lastSeen = $stmt->fetchColumn();
        if (!is_string($lastSeen) || $lastSeen === '') {
            return ['ok' => false, 'reason' => 'inactive'];
        }

        $lastSeenAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $lastSeen, new DateTimeZone('UTC'))
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lastSeen, new DateTimeZone('UTC'));
        if (!$lastSeenAt || $now->getTimestamp() - $lastSeenAt->getTimestamp() >= AUTH_SESSIONS_TOUCH_INTERVAL) {
            $metadata = auth_sessions_request_metadata();
            $touch = $pdo->prepare(
                'UPDATE user_sessions
                 SET last_seen_at_utc = :last_seen_at_utc,
                     expires_at_utc = :expires_at_utc,
                     ip_address = :ip_address,
                     user_agent_hash = :user_agent_hash,
                     user_agent = :user_agent
                 WHERE session_key_hash = :session_key_hash
                   AND user_id = :user_id
                   AND revoked_at_utc IS NULL
                   AND expires_at_utc > :now_utc'
            );
            $touch->execute([
                ':last_seen_at_utc' => $nowSql,
                ':expires_at_utc' => auth_sessions_mysql_time($now->modify('+' . max(1, $lifetime) . ' seconds')),
                ':ip_address' => $metadata['ip_address'],
                ':user_agent_hash' => $metadata['user_agent_hash'],
                ':user_agent' => $metadata['user_agent'],
                ':session_key_hash' => $sessionKeyHash,
                ':user_id' => $userId,
                ':now_utc' => $nowSql,
            ]);
            if ($touch->rowCount() !== 1) return ['ok' => false, 'reason' => 'inactive'];
        }
        return $valid[$cacheKey] = ['ok' => true, 'reason' => 'active'];
    } catch (Throwable $error) {
        error_log('[auth_sessions] validation unavailable: ' . $error->getMessage());
        return ['ok' => false, 'reason' => 'unavailable'];
    }
}

function auth_sessions_audit_revoke(
    PDO $pdo,
    string $eventKey,
    ?int $actorUserId,
    string $resourceType,
    string $resourceId,
    int $count,
    string $reason
): void {
    if (!function_exists('audit_log_record') || !$pdo->inTransaction()) return;
    try {
        audit_log_record($pdo, [
            'provider' => 'core',
            'event_key' => $eventKey,
            'actor_user_id' => $actorUserId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'resource_label' => $resourceType === 'user' ? 'User #' . $resourceId : null,
            'source' => PHP_SAPI === 'cli' ? 'cli' : 'dashboard',
            'metadata' => ['count' => $count, 'reason' => $reason],
        ]);
    } catch (Throwable $error) {
        error_log('[auth_sessions] revoke audit failed: ' . $error->getMessage());
    }
}

function auth_sessions_revoke(
    PDO $pdo,
    string $sessionKeyHash,
    ?int $actorUserId,
    string $reason = 'manual',
    ?int $expectedUserId = null,
    bool $audit = true
): int {
    if (!auth_sessions_hash_is_valid($sessionKeyHash)
        || preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $reason) !== 1) {
        throw new InvalidArgumentException('Invalid session revocation request.');
    }
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $sql = 'UPDATE user_sessions
                SET revoked_at_utc = :revoked_at_utc,
                    revoked_by_user_id = :revoked_by_user_id,
                    revoke_reason = :revoke_reason
                WHERE session_key_hash = :session_key_hash
                  AND revoked_at_utc IS NULL';
        $params = [
            ':revoked_at_utc' => auth_sessions_mysql_time(auth_sessions_now()),
            ':revoked_by_user_id' => $actorUserId,
            ':revoke_reason' => $reason,
            ':session_key_hash' => $sessionKeyHash,
        ];
        if ($expectedUserId !== null) {
            $sql .= ' AND user_id = :user_id';
            $params[':user_id'] = $expectedUserId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->rowCount();
        if ($count === 1 && $audit) {
            auth_sessions_audit_revoke($pdo, 'auth.session_revoked', $actorUserId, 'login_session', $sessionKeyHash, 1, $reason);
        }
        if ($ownsTransaction) $pdo->commit();
        return $count;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function auth_sessions_revoke_user(
    PDO $pdo,
    int $targetUserId,
    ?int $actorUserId,
    string $reason,
    ?string $exceptSessionKeyHash = null,
    bool $audit = true
): int {
    if ($targetUserId <= 0 || ($exceptSessionKeyHash !== null && !auth_sessions_hash_is_valid($exceptSessionKeyHash))
        || preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $reason) !== 1) {
        throw new InvalidArgumentException('Invalid account session revocation request.');
    }
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $sql = 'UPDATE user_sessions
                SET revoked_at_utc = :revoked_at_utc,
                    revoked_by_user_id = :revoked_by_user_id,
                    revoke_reason = :revoke_reason
                WHERE user_id = :user_id
                  AND revoked_at_utc IS NULL';
        $params = [
            ':revoked_at_utc' => auth_sessions_mysql_time(auth_sessions_now()),
            ':revoked_by_user_id' => $actorUserId,
            ':revoke_reason' => $reason,
            ':user_id' => $targetUserId,
        ];
        if ($exceptSessionKeyHash !== null) {
            $sql .= ' AND session_key_hash <> :except_session_key_hash';
            $params[':except_session_key_hash'] = $exceptSessionKeyHash;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->rowCount();
        if ($count > 0 && $audit) {
            auth_sessions_audit_revoke($pdo, 'auth.sessions_revoked', $actorUserId, 'user', (string)$targetUserId, $count, $reason);
        }
        if ($ownsTransaction) $pdo->commit();
        return $count;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function auth_sessions_for_user(PDO $pdo, int $userId, ?string $currentSessionKeyHash = null, int $limit = 30): array
{
    if ($userId <= 0) return [];
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare(
        'SELECT session_key_hash, created_at_utc, last_seen_at_utc, expires_at_utc,
                revoked_at_utc, revoke_reason, ip_address, user_agent
         FROM user_sessions
         WHERE user_id = :user_id
         ORDER BY created_at_utc DESC, id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([':user_id' => $userId]);
    $now = auth_sessions_now();
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $expires = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', (string)$row['expires_at_utc'], new DateTimeZone('UTC'))
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$row['expires_at_utc'], new DateTimeZone('UTC'));
        $row['active'] = $row['revoked_at_utc'] === null && $expires instanceof DateTimeImmutable && $expires > $now;
        $row['current'] = $currentSessionKeyHash !== null
            && hash_equals((string)$row['session_key_hash'], $currentSessionKeyHash);
        $row['ip_address'] = is_string($row['ip_address']) ? (@inet_ntop($row['ip_address']) ?: null) : null;
        $rows[] = $row;
    }
    return $rows;
}

function auth_sessions_active_for_user(PDO $pdo, int $userId, ?string $currentSessionKeyHash = null, int $limit = 100): array
{
    if ($userId <= 0) return [];
    $limit = max(1, min(1000, $limit));
    $now = auth_sessions_mysql_time(auth_sessions_now());
    $stmt = $pdo->prepare(
        'SELECT session_key_hash, created_at_utc, last_seen_at_utc, expires_at_utc,
                revoked_at_utc, revoke_reason, ip_address, user_agent
         FROM user_sessions
         WHERE user_id = :user_id
           AND revoked_at_utc IS NULL
           AND expires_at_utc > :now_utc
         ORDER BY last_seen_at_utc DESC, id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([':user_id' => $userId, ':now_utc' => $now]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['active'] = true;
        $row['current'] = $currentSessionKeyHash !== null
            && hash_equals((string)$row['session_key_hash'], $currentSessionKeyHash);
        $row['ip_address'] = is_string($row['ip_address']) ? (@inet_ntop($row['ip_address']) ?: null) : null;
        $rows[] = $row;
    }
    return $rows;
}

function auth_sessions_active_count(PDO $pdo, int $userId): int
{
    if ($userId <= 0) return 0;
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM user_sessions
         WHERE user_id = :user_id
           AND revoked_at_utc IS NULL
           AND expires_at_utc > :now_utc'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':now_utc' => auth_sessions_mysql_time(auth_sessions_now()),
    ]);
    return (int)$stmt->fetchColumn();
}

function auth_sessions_device_label(?string $userAgent): string
{
    $userAgent = trim((string)$userAgent);
    if ($userAgent === '') return function_exists('__') ? __('Unknown device') : 'Unknown device';

    $browser = match (true) {
        str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
        str_contains($userAgent, 'Firefox/') => 'Firefox',
        str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
        str_contains($userAgent, 'Safari/') => 'Safari',
        default => function_exists('__') ? __('Unknown browser') : 'Unknown browser',
    };
    $platform = match (true) {
        str_contains($userAgent, 'iPad') => 'iPadOS',
        str_contains($userAgent, 'iPhone') => 'iOS',
        str_contains($userAgent, 'Android') => 'Android',
        str_contains($userAgent, 'Windows') => 'Windows',
        str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS X') => 'macOS',
        str_contains($userAgent, 'Linux') => 'Linux',
        default => function_exists('__') ? __('Unknown platform') : 'Unknown platform',
    };
    return $browser . ' / ' . $platform;
}

function auth_sessions_prune(PDO $pdo, int $limit = 100): int
{
    $limit = max(1, min(1000, $limit));
    $cutoff = auth_sessions_mysql_time(auth_sessions_now()->modify('-' . AUTH_SESSIONS_RETENTION_DAYS . ' days'));
    $stmt = $pdo->prepare(
        'DELETE FROM user_sessions
         WHERE (revoked_at_utc IS NOT NULL AND revoked_at_utc < :cutoff_revoked)
            OR expires_at_utc < :cutoff_expired
         LIMIT ' . $limit
    );
    $stmt->execute([':cutoff_revoked' => $cutoff, ':cutoff_expired' => $cutoff]);
    return $stmt->rowCount();
}
