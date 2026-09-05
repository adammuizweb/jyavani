<?php
declare(strict_types=1);

final class UpdateOperationCancelled extends RuntimeException
{
}

function update_operation_valid_token(string $token): bool
{
    return preg_match('/\A[a-f0-9]{32}\z/D', $token) === 1;
}

function update_operation_directory(): ?string
{
    $directory = defined('UPDATE_OPERATION_DIRECTORY')
        ? (string)UPDATE_OPERATION_DIRECTORY
        : (defined('BACKEND_PATH') ? rtrim((string)BACKEND_PATH, '/\\') . '/var/update-operations' : '');
    if ($directory === '' || str_contains($directory, "\0")) return null;
    if (!str_starts_with($directory, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $directory) !== 1) return null;

    if (!file_exists($directory) && !@mkdir($directory, 02770, true) && !is_dir($directory)) return null;
    clearstatcache(true, $directory);
    $stat = @lstat($directory);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000
        || (($stat['mode'] ?? 0) & 0002) !== 0 || is_link($directory)) return null;
    if ((($stat['mode'] ?? 07777) & 07777) !== 02770 && !@chmod($directory, 02770)) return null;
    clearstatcache(true, $directory);
    $stat = @lstat($directory);
    if (!is_array($stat) || (($stat['mode'] ?? 07777) & 07777) !== 02770) return null;

    return rtrim($directory, '/\\');
}

function update_operation_path(string $token): ?string
{
    $directory = update_operation_valid_token($token) ? update_operation_directory() : null;
    return $directory === null ? null : $directory . '/update-operation-' . $token . '.json';
}

/** @return resource|null */
function update_operation_open_lock(string $path)
{
    clearstatcache(true, $path);
    $before = @lstat($path);
    if ($before === false) {
        $handle = @fopen($path, 'x+b');
        if (is_resource($handle)) @chmod($path, 0660);
    } else {
        $handle = null;
    }

    if (!is_resource($handle)) {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || (($before['mode'] ?? 0) & 0170000) !== 0100000
            || (($before['mode'] ?? 0) & 0777) !== 0660 || ($before['nlink'] ?? 0) !== 1
            || is_link($path)) return null;
        $handle = @fopen($path, 'r+b');
    }
    if (!is_resource($handle)) return null;

    $descriptor = @fstat($handle);
    clearstatcache(true, $path);
    $current = @lstat($path);
    $safe = is_array($descriptor) && is_array($current)
        && (($descriptor['mode'] ?? 0) & 0170000) === 0100000
        && (($descriptor['mode'] ?? 0) & 0777) === 0660
        && ($descriptor['nlink'] ?? 0) === 1
        && ($descriptor['dev'] ?? null) === ($current['dev'] ?? null)
        && ($descriptor['ino'] ?? null) === ($current['ino'] ?? null)
        && !is_link($path);
    if (!$safe) {
        @fclose($handle);
        return null;
    }
    return $handle;
}

/** @return resource|null */
function update_operation_record_lock(string $token)
{
    $path = update_operation_path($token);
    if ($path === null) return null;
    // A bounded lock stripe set avoids one persistent lock inode per operation.
    $lock = update_operation_open_lock(dirname($path) . '/record-lock-' . substr($token, 0, 2) . '.lock');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) @fclose($lock);
        return null;
    }
    return $lock;
}

function update_operation_cleanup_stale(int $limit = 20): void
{
    $directory = update_operation_directory();
    if ($directory === null || $limit <= 0) return;
    try {
        $paths = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
    } catch (Throwable $error) {
        return;
    }
    $checked = 0;
    foreach ($paths as $entry) {
        $path = $entry->getPathname();
        $basename = basename($path);
        if (preg_match('/\Aupdate-operation-([a-f0-9]{32})\.json\z/D', $basename, $matches) !== 1) continue;
        if ($checked++ >= $limit) break;
        clearstatcache(true, $path);
        if ((int)@filemtime($path) >= time() - 3600) continue;
        update_operation_read($matches[1]);
    }
}

function update_operation_normalize_text(string $value, int $limit): string
{
    $value = str_replace("\0", '', trim($value));
    return strlen($value) <= $limit ? $value : substr($value, 0, $limit);
}

function update_operation_record_valid(array $record): bool
{
    $required = [
        'schema', 'type', 'target', 'owner_id', 'stage', 'percentage', 'status', 'done',
        'outcome', 'error', 'cancel_allowed', 'cancel_requested', 'created_at', 'updated_at',
    ];
    foreach ($required as $field) if (!array_key_exists($field, $record)) return false;
    if ($record['schema'] !== 1 || !in_array($record['type'], ['core', 'plugin', 'theme'], true)) return false;
    if (!is_string($record['target']) || !is_int($record['owner_id']) || $record['owner_id'] <= 0) return false;
    if (!is_string($record['stage']) || !is_int($record['percentage'])
        || $record['percentage'] < 0 || $record['percentage'] > 100 || !is_string($record['status'])) return false;
    if (!is_bool($record['done']) || !in_array($record['outcome'], ['running', 'completed', 'failed', 'cancelled'], true)) return false;
    if (!is_null($record['error']) && !is_string($record['error'])) return false;
    if (!is_bool($record['cancel_allowed']) || !is_bool($record['cancel_requested'])) return false;
    if (!is_int($record['created_at']) || !is_int($record['updated_at'])) return false;
    return $record['done'] === ($record['outcome'] !== 'running');
}

function update_operation_read_locked(string $path): ?array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false) return null;
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0660 || ($stat['nlink'] ?? 0) !== 1
        || ($stat['size'] ?? 0) < 2 || ($stat['size'] ?? 0) > 65536 || is_link($path)) return null;

    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) return null;
    $descriptor = @fstat($handle);
    clearstatcache(true, $path);
    $current = @lstat($path);
    $safe = is_array($descriptor) && is_array($current)
        && (($descriptor['mode'] ?? 0) & 0170000) === 0100000
        && (($descriptor['mode'] ?? 0) & 0777) === 0660
        && ($descriptor['nlink'] ?? 0) === 1
        && ($descriptor['size'] ?? 0) >= 2 && ($descriptor['size'] ?? 0) <= 65536
        && ($descriptor['dev'] ?? null) === ($current['dev'] ?? null)
        && ($descriptor['ino'] ?? null) === ($current['ino'] ?? null)
        && !is_link($path);
    $json = $safe ? stream_get_contents($handle, 65537) : false;
    @fclose($handle);
    if (!is_string($json) || strlen($json) > 65536) return null;
    try {
        $record = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return null;
    }
    return is_array($record) && update_operation_record_valid($record) ? $record : null;
}

function update_operation_write_locked(string $path, array $record): bool
{
    if (!update_operation_record_valid($record)) return false;
    try {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable $error) {
        return false;
    }
    $temporary = $path . '.tmp-' . $suffix;
    $handle = @fopen($temporary, 'x+b');
    if (!is_resource($handle)) return false;

    $ok = @chmod($temporary, 0660);
    $descriptor = @fstat($handle);
    $ok = $ok && is_array($descriptor)
        && (($descriptor['mode'] ?? 0) & 0170000) === 0100000
        && (($descriptor['mode'] ?? 0) & 0777) === 0660
        && ($descriptor['nlink'] ?? 0) === 1;
    $offset = 0;
    $length = strlen($json);
    while ($ok && $offset < $length) {
        $written = @fwrite($handle, substr($json, $offset));
        if (!is_int($written) || $written <= 0) {
            $ok = false;
            break;
        }
        $offset += $written;
    }
    if ($ok) $ok = @fflush($handle);
    if ($ok && function_exists('fsync')) $ok = @fsync($handle);
    if (!@fclose($handle)) $ok = false;
    if ($ok) $ok = @rename($temporary, $path);
    if ($ok) @chmod($path, 0660);
    if (!$ok) @unlink($temporary);
    return $ok;
}

function update_operation_begin(string $token, int $ownerId, string $type, string $target, string $status): bool
{
    update_operation_cleanup_stale();
    $path = update_operation_path($token);
    if ($path === null || $ownerId <= 0 || !in_array($type, ['core', 'plugin', 'theme'], true)) return false;
    $lock = update_operation_record_lock($token);
    if (!is_resource($lock)) return false;
    try {
        if (@lstat($path) !== false) return false;
        $now = time();
        return update_operation_write_locked($path, [
            'schema' => 1,
            'type' => $type,
            'target' => update_operation_normalize_text($target, 255),
            'owner_id' => $ownerId,
            'stage' => 'starting',
            'percentage' => 0,
            'status' => update_operation_normalize_text($status, 1000),
            'done' => false,
            'outcome' => 'running',
            'error' => null,
            'cancel_allowed' => false,
            'cancel_requested' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function update_operation_read(string $token): ?array
{
    $path = update_operation_path($token);
    $lock = $path === null ? null : update_operation_record_lock($token);
    if ($path === null || !is_resource($lock)) return null;
    try {
        $record = update_operation_read_locked($path);
        if ($record !== null && $record['done'] === false && $record['updated_at'] < time() - 1800) {
            $operationLock = update_operation_acquire_lock();
            if (is_resource($operationLock)) {
                $record['stage'] = 'failed';
                $record['status'] = function_exists('__') ? __('Update process stopped unexpectedly.') : 'Update process stopped unexpectedly.';
                $record['done'] = true;
                $record['outcome'] = 'failed';
                $record['error'] = $record['status'];
                $record['cancel_allowed'] = false;
                $record['updated_at'] = time();
                update_operation_write_locked($path, $record);
                update_operation_release_lock($operationLock);
            }
        }
        if ($record !== null && $record['done'] === true && $record['updated_at'] < time() - 3600) {
            @unlink($path);
            return null;
        }
        return $record;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function update_operation_mutate(string $token, callable $mutation): void
{
    $path = update_operation_path($token);
    $lock = $path === null ? null : update_operation_record_lock($token);
    if ($path === null || !is_resource($lock)) return;
    try {
        $record = update_operation_read_locked($path);
        if ($record === null) return;
        $changed = $mutation($record);
        if (!is_array($changed) || $changed === $record) return;
        $changed['updated_at'] = time();
        update_operation_write_locked($path, $changed);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function update_operation_progress(string $token, int $pct, string $stage, string $status, bool $cancelAllowed): void
{
    update_operation_mutate($token, static function (array $record) use ($pct, $stage, $status, $cancelAllowed): array {
        if ($record['done']) return $record;
        $record['percentage'] = max($record['percentage'], min(100, max(0, $pct)));
        $record['stage'] = update_operation_normalize_text($stage, 100);
        $record['status'] = update_operation_normalize_text($status, 1000);
        $record['cancel_allowed'] = $cancelAllowed;
        return $record;
    });
}

function update_operation_fail(string $token, string $status, string $error): void
{
    update_operation_mutate($token, static function (array $record) use ($status, $error): array {
        if ($record['done']) return $record;
        $record['stage'] = 'failed';
        $record['status'] = update_operation_normalize_text($status, 1000);
        $record['done'] = true;
        $record['outcome'] = 'failed';
        $record['error'] = update_operation_normalize_text($error, 2000);
        $record['cancel_allowed'] = false;
        return $record;
    });
}

function update_operation_complete(string $token, string $status): void
{
    update_operation_mutate($token, static function (array $record) use ($status): array {
        if ($record['done']) return $record;
        $record['stage'] = 'complete';
        $record['percentage'] = 100;
        $record['status'] = update_operation_normalize_text($status, 1000);
        $record['done'] = true;
        $record['outcome'] = 'completed';
        $record['error'] = null;
        $record['cancel_allowed'] = false;
        return $record;
    });
}

function update_operation_request_cancel(string $token, int $ownerId): array
{
    $path = update_operation_path($token);
    if ($path === null || $ownerId <= 0) return ['ok' => false, 'reason' => 'invalid'];
    $lock = update_operation_record_lock($token);
    if (!is_resource($lock)) return ['ok' => false, 'reason' => 'unavailable'];
    try {
        $record = update_operation_read_locked($path);
        if ($record === null) return ['ok' => false, 'reason' => 'not_found'];
        if ($record['owner_id'] !== $ownerId) return ['ok' => false, 'reason' => 'owner_mismatch'];
        if ($record['done'] || !$record['cancel_allowed']) {
            return ['ok' => false, 'reason' => 'not_allowed', 'record' => $record];
        }
        $record['cancel_requested'] = true;
        $record['updated_at'] = time();
        if (!update_operation_write_locked($path, $record)) return ['ok' => false, 'reason' => 'unavailable'];
        return ['ok' => true, 'reason' => 'requested', 'record' => $record];
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function update_operation_cancellation_requested(string $token): bool
{
    $record = update_operation_read($token);
    return $record !== null && $record['cancel_requested'] === true;
}

function update_operation_checkpoint(string $token): void
{
    $cancelled = false;
    update_operation_mutate($token, static function (array $record) use (&$cancelled): array {
        if (!$record['done'] && $record['cancel_requested'] && $record['cancel_allowed']) {
            $record['stage'] = 'cancelling';
            $record['status'] = function_exists('__') ? __('Cancelling...') : 'Cancelling...';
            $record['cancel_allowed'] = false;
            $cancelled = true;
        }
        return $record;
    });
    if ($cancelled) throw new UpdateOperationCancelled('Update operation cancellation requested.');
}

function update_operation_enter_critical(string $token, int $pct, string $stage, string $status): void
{
    $cancelled = false;
    update_operation_mutate($token, static function (array $record) use ($pct, $stage, $status, &$cancelled): array {
        if ($record['done']) return $record;
        if ($record['cancel_requested'] && $record['cancel_allowed']) {
            $record['stage'] = 'cancelling';
            $record['status'] = function_exists('__') ? __('Cancelling...') : 'Cancelling...';
            $record['cancel_allowed'] = false;
            $cancelled = true;
            return $record;
        }
        $record['percentage'] = max($record['percentage'], min(100, max(0, $pct)));
        $record['stage'] = update_operation_normalize_text($stage, 100);
        $record['status'] = update_operation_normalize_text($status, 1000);
        $record['cancel_allowed'] = false;
        return $record;
    });
    if ($cancelled) throw new UpdateOperationCancelled('Update operation cancellation requested.');
}

function update_operation_mark_cancelled(string $token, string $status): void
{
    update_operation_mutate($token, static function (array $record) use ($status): array {
        if ($record['done'] && $record['outcome'] !== 'cancelled') return $record;
        $record['stage'] = 'cancelled';
        $record['status'] = update_operation_normalize_text($status, 1000);
        $record['done'] = true;
        $record['outcome'] = 'cancelled';
        $record['error'] = null;
        $record['cancel_allowed'] = false;
        $record['cancel_requested'] = true;
        return $record;
    });
}

function update_operation_clear(string $token): void
{
    $path = update_operation_path($token);
    $lock = $path === null ? null : update_operation_record_lock($token);
    if ($path === null || !is_resource($lock)) return;
    try {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0100000 && !is_link($path)) @unlink($path);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/** @return resource|null */
function update_operation_acquire_lock()
{
    $directory = update_operation_directory();
    if ($directory === null) return null;
    $lock = update_operation_open_lock($directory . '/update-operation.lock');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) @fclose($lock);
        return null;
    }
    return $lock;
}

/** @param resource|null $lock */
function update_operation_release_lock($lock): void
{
    if (!is_resource($lock)) return;
    @flock($lock, LOCK_UN);
    @fclose($lock);
}
