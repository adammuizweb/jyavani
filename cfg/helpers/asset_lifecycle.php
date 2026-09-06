<?php
declare(strict_types=1);

final class AssetLifecycleConflict extends RuntimeException
{
}

final class AssetLifecycleAccessDenied extends DomainException
{
}

function asset_lifecycle_config(string $resource): array
{
    $projectRoot = dirname(__DIR__, 2);
    return match ($resource) {
        'media' => [
            'table' => '`media`',
            'public_root' => PUBLIC_PATH . '/static/img',
            'private_root' => $projectRoot . '/private_files/media',
            'permissions' => [
                'delete' => 'core.media.delete',
                'restore' => 'core.media.restore',
                'purge' => 'core.media.purge',
            ],
        ],
        'file' => [
            'table' => '`file`',
            'public_root' => PUBLIC_PATH . '/static/files',
            'private_root' => $projectRoot . '/private_files/files',
            'permissions' => [
                'delete' => 'core.files.delete',
                'restore' => 'core.files.restore',
                'purge' => 'core.files.purge',
            ],
        ],
        default => throw new InvalidArgumentException('Unsupported asset resource.'),
    };
}

function asset_lifecycle_ids(array $ids): array
{
    if ($ids === []) {
        throw new InvalidArgumentException('At least one asset ID is required.');
    }
    $normalized = [];
    foreach ($ids as $id) {
        if (is_int($id)) {
            $value = $id;
        } elseif (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1) {
            $value = filter_var($id, FILTER_VALIDATE_INT);
            if ($value === false) {
                throw new InvalidArgumentException('Asset IDs must be positive integers.');
            }
        } else {
            throw new InvalidArgumentException('Asset IDs must be positive integers.');
        }
        if ($value <= 0) {
            throw new InvalidArgumentException('Asset IDs must be positive integers.');
        }
        $normalized[$value] = $value;
        if (count($normalized) > 100) {
            throw new InvalidArgumentException('No more than 100 unique asset IDs are allowed.');
        }
    }
    $normalized = array_values($normalized);
    sort($normalized, SORT_NUMERIC);
    return $normalized;
}

function asset_lifecycle_storage_path_is_valid(?string $path, int $maxLength = 500): bool
{
    if ($path === null || $path === '' || strlen($path) > $maxLength
        || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1
        || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        return false;
    }
    if (preg_match('//u', $path) !== 1) {
        return false;
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255
            || preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._-]*$/Du', $segment) !== 1) {
            return false;
        }
    }
    return true;
}

function asset_lifecycle_path_within(string $path, string $root): bool
{
    return $path !== $root && str_starts_with($path, $root . DIRECTORY_SEPARATOR);
}

function asset_lifecycle_existing_directory(string $path): ?string
{
    $absolute = str_starts_with($path, DIRECTORY_SEPARATOR);
    if (!$absolute) {
        return null;
    }
    $current = DIRECTORY_SEPARATOR;
    foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $segment) {
        if ($segment === '') {
            continue;
        }
        $current = $current === DIRECTORY_SEPARATOR ? $current . $segment : $current . DIRECTORY_SEPARATOR . $segment;
        if (is_link($current) || !is_dir($current)) {
            return null;
        }
    }
    $real = realpath($path);
    return $real === false ? null : rtrim($real, DIRECTORY_SEPARATOR);
}

function asset_lifecycle_managed_source(array $config, mixed $disk, mixed $storagePath): array
{
    if (!is_string($disk) || !in_array($disk, ['public', 'private'], true)) {
        return ['status' => 'unmanaged', 'warning' => 'Storage disk is not managed.'];
    }
    if (!is_string($storagePath) || !asset_lifecycle_storage_path_is_valid($storagePath)) {
        return ['status' => 'unmanaged', 'warning' => 'Storage path is missing or invalid.'];
    }
    $root = asset_lifecycle_existing_directory($config[$disk . '_root']);
    if ($root === null) {
        return ['status' => 'unmanaged', 'warning' => 'Managed storage root is missing or unsafe.'];
    }
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);
    $current = $root;
    $segments = explode('/', $storagePath);
    foreach ($segments as $index => $segment) {
        $current .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($current)) {
            return ['status' => 'unmanaged', 'warning' => 'Managed storage path contains a symlink.'];
        }
        if (!file_exists($current)) {
            return ['status' => 'missing', 'warning' => 'Managed file is missing.'];
        }
        if ($index < count($segments) - 1 && !is_dir($current)) {
            return ['status' => 'unmanaged', 'warning' => 'Managed storage parent is not a directory.'];
        }
    }
    $real = realpath($candidate);
    if ($real === false || !asset_lifecycle_path_within($real, $root) || !is_file($candidate)) {
        return ['status' => 'unmanaged', 'warning' => 'Managed storage candidate is not a regular contained file.'];
    }
    $stat = lstat($candidate);
    if ($stat === false || (($stat['mode'] ?? 0) & 0170000) !== 0100000) {
        return ['status' => 'unmanaged', 'warning' => 'Managed storage candidate is not a regular file.'];
    }
    return ['status' => 'file', 'path' => $candidate, 'root' => $root, 'disk' => $disk, 'storage_path' => $storagePath];
}

function asset_lifecycle_safe_original_target(array $config, mixed $disk, mixed $storagePath): string
{
    if (!is_string($disk) || !in_array($disk, ['public', 'private'], true)
        || !is_string($storagePath) || !asset_lifecycle_storage_path_is_valid($storagePath)) {
        throw new RuntimeException('Original managed storage metadata is invalid.');
    }
    $root = asset_lifecycle_existing_directory($config[$disk . '_root']);
    if ($root === null) {
        throw new RuntimeException('Original managed storage root is missing or unsafe.');
    }
    $segments = explode('/', $storagePath);
    $basename = array_pop($segments);
    $parent = $root;
    foreach ($segments as $segment) {
        $parent .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($parent) || !is_dir($parent)) {
            throw new RuntimeException('Original managed storage parent is missing or unsafe.');
        }
    }
    $realParent = realpath($parent);
    if ($realParent === false || ($realParent !== $root && !asset_lifecycle_path_within($realParent, $root))) {
        throw new RuntimeException('Original managed storage parent escapes its root.');
    }
    $target = $parent . DIRECTORY_SEPARATOR . $basename;
    if (file_exists($target) || is_link($target)) {
        throw new RuntimeException('Original managed storage target already exists.');
    }
    return $target;
}

function asset_lifecycle_quarantine_relative(string $resource, int $id, string $disk, string $storagePath): string
{
    $extension = strtolower((string)pathinfo($storagePath, PATHINFO_EXTENSION));
    $suffix = preg_match('/^[a-z0-9]{1,10}$/D', $extension) === 1 ? '.' . $extension : '';
    return $resource . '/' . $id . '/' . hash('sha256', $disk . "\0" . $storagePath) . $suffix . '.trash';
}

function asset_lifecycle_quarantine_root(bool $create): string
{
    $privateFiles = dirname(__DIR__, 2) . '/private_files';
    $base = asset_lifecycle_existing_directory($privateFiles);
    if ($base === null) {
        throw new RuntimeException('Private files root is missing or unsafe.');
    }
    $root = $base . '/.asset-trash';
    if (is_link($root)) {
        throw new RuntimeException('Asset quarantine root must not be a symlink.');
    }
    if (!is_dir($root)) {
        if (!$create || file_exists($root) || !mkdir($root, 0750)) {
            throw new RuntimeException('Asset quarantine root is unavailable.');
        }
    }
    if (!chmod($root, 0750)) {
        throw new RuntimeException('Unable to enforce asset quarantine permissions.');
    }
    $real = realpath($root);
    if ($real === false || !asset_lifecycle_path_within($real, $base)) {
        throw new RuntimeException('Asset quarantine root is unsafe.');
    }
    return $real;
}

function asset_lifecycle_ensure_quarantine_parent(string $root, string $relative): string
{
    if (!asset_lifecycle_storage_path_is_valid($relative, 600)) {
        throw new RuntimeException('Generated quarantine path is invalid.');
    }
    $segments = explode('/', $relative);
    $basename = array_pop($segments);
    $parent = $root;
    foreach ($segments as $segment) {
        $parent .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) {
            throw new RuntimeException('Asset quarantine hierarchy is unsafe.');
        }
        if (!is_dir($parent) && !mkdir($parent, 0750)) {
            throw new RuntimeException('Unable to create asset quarantine hierarchy.');
        }
        if (!chmod($parent, 0750)) {
            throw new RuntimeException('Unable to enforce asset quarantine permissions.');
        }
    }
    $realParent = realpath($parent);
    if ($realParent === false || !asset_lifecycle_path_within($realParent, $root)) {
        throw new RuntimeException('Asset quarantine target parent is unsafe.');
    }
    $target = $parent . DIRECTORY_SEPARATOR . $basename;
    if (file_exists($target) || is_link($target)) {
        throw new RuntimeException('Asset quarantine target already exists.');
    }
    return $target;
}

function asset_lifecycle_rename(string $source, string $target): void
{
    $sourceStat = lstat($source);
    $targetParentStat = stat(dirname($target));
    if ($sourceStat === false || $targetParentStat === false
        || (int)($sourceStat['dev'] ?? -1) !== (int)($targetParentStat['dev'] ?? -2)) {
        throw new RuntimeException('Asset storage and quarantine must use the same filesystem.');
    }
    if (!rename($source, $target)) {
        throw new RuntimeException('Unable to move asset bytes.');
    }
}

function asset_lifecycle_existing_quarantine(string $resource, array $row): array
{
    $relative = $row['quarantine_path'] ?? null;
    $disk = $row['storage_disk'] ?? null;
    $storagePath = $row['storage_path'] ?? null;
    if (!is_string($relative) || !asset_lifecycle_storage_path_is_valid($relative, 600)
        || !is_string($disk) || !in_array($disk, ['public', 'private'], true)
        || !is_string($storagePath) || !asset_lifecycle_storage_path_is_valid($storagePath)
        || $relative !== asset_lifecycle_quarantine_relative($resource, (int)$row['id'], $disk, $storagePath)) {
        throw new RuntimeException('Stored quarantine path is invalid.');
    }
    $rootPath = dirname(__DIR__, 2) . '/private_files/.asset-trash';
    if (!file_exists($rootPath) && !is_link($rootPath)) {
        return ['status' => 'missing', 'path' => $rootPath . '/' . $relative, 'relative' => $relative];
    }
    $root = asset_lifecycle_quarantine_root(false);
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $current = $root;
    foreach (explode('/', $relative) as $index => $segment) {
        $current .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($current)) {
            throw new RuntimeException('Quarantine path contains a symlink.');
        }
        if (!file_exists($current)) {
            return ['status' => 'missing', 'path' => $candidate, 'relative' => $relative];
        }
        if ($index < substr_count($relative, '/') && !is_dir($current)) {
            throw new RuntimeException('Quarantine parent is not a directory.');
        }
    }
    $real = realpath($candidate);
    $stat = lstat($candidate);
    if ($real === false || !asset_lifecycle_path_within($real, $root) || $stat === false
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000) {
        throw new RuntimeException('Quarantine artifact is not a regular contained file.');
    }
    return ['status' => 'file', 'path' => $candidate, 'relative' => $relative];
}

function asset_lifecycle_locked_rows(PDO $pdo, string $table, array $ids, int $deleted): array
{
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT *
         FROM $table
         WHERE id IN ($in) AND is_deleted = ?
         ORDER BY id
         FOR UPDATE"
    );
    $stmt->execute(array_merge($ids, [$deleted]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) !== count($ids)) {
        throw new AssetLifecycleConflict('One or more assets are missing or in the wrong lifecycle state.');
    }
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['user_id'] = $row['user_id'] === null ? 0 : (int)$row['user_id'];
    }
    unset($row);
    return $rows;
}

function asset_lifecycle_capture_items(ResourceLifecycleDatabase $database, string $operation, array $lockedItems): array
{
    if (!in_array($operation, ['trash', 'restore', 'purge'], true)) {
        throw new InvalidArgumentException('Unsupported asset lifecycle operation.');
    }
    $items = [];
    foreach ($lockedItems as $lockedItem) {
        $row = is_array($lockedItem['row'] ?? null) ? $lockedItem['row'] : null;
        $artifacts = $lockedItem['artifacts'] ?? [];
        if ($row === null || (int)($row['id'] ?? 0) <= 0 || !is_array($artifacts)) {
            throw new InvalidArgumentException('Invalid locked asset lifecycle item.');
        }
        $items[] = [
            'id' => (int)$row['id'],
            'before' => $row,
            'after' => null,
            'artifacts' => $artifacts,
        ];
    }
    return $items;
}

foreach (['media', 'file'] as $assetLifecycleResource) {
    if (!register_resource_lifecycle_provider($assetLifecycleResource, [
        'owner' => 'core',
        'capture' => 'asset_lifecycle_capture_items',
    ])) {
        throw new LogicException('Unable to register Core asset lifecycle provider.');
    }
}
unset($assetLifecycleResource);

function asset_lifecycle_unmanaged_artifact(string $status): array
{
    return [[
        'kind' => 'file',
        'role' => 'primary',
        'managed' => false,
        'state' => $status === 'missing' ? 'missing' : 'unmanaged',
    ]];
}

function asset_lifecycle_committed(PDO $pdo, array $event): void
{
    foreach (resource_lifecycle_after_commit($pdo, $event) as $error) {
        error_log('[resource-lifecycle] Committed observer failed for ' . $event['resource'] . '.' . $event['operation']
            . ': ' . ($error['message'] ?? 'Unknown listener error'));
    }
}

function asset_lifecycle_authorize(PDO $pdo, array $config, string $action, array $rows, int $actorUserId): void
{
    if (!authorization_lock_owner_contexts($pdo, array_column($rows, 'user_id'))) {
        throw new RuntimeException('Unable to lock asset owner contexts.');
    }
    foreach ($rows as $row) {
        if (!user_can($pdo, $actorUserId, $config['permissions'][$action], ['owner_id' => $row['user_id']])) {
            throw new AssetLifecycleAccessDenied('Asset lifecycle permission denied for ID ' . $row['id'] . '.');
        }
    }
}

function asset_lifecycle_audit(PDO $pdo, string $event, int $actorUserId, string $resource, int $id, array $metadata): int
{
    if (!authorization_audit($pdo, $event, $actorUserId, null, $resource, (string)$id, $metadata)) {
        throw new RuntimeException('Asset lifecycle audit failed.');
    }
    $auditId = (int)$pdo->lastInsertId();
    if ($auditId <= 0) {
        throw new RuntimeException('Asset lifecycle audit ID is unavailable.');
    }
    return $auditId;
}

function asset_lifecycle_latest_audit_id(PDO $pdo, string $resource, int $id): int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM authorization_audit_log
         WHERE resource_type = :resource AND resource_id = :id
         ORDER BY id DESC LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':resource' => $resource, ':id' => (string)$id]);
    return (int)$stmt->fetchColumn();
}

function asset_lifecycle_expected_audits(array $ids, array $expected): array
{
    $map = [];
    if (array_is_list($expected)) {
        if (count($expected) !== count($ids)) {
            throw new InvalidArgumentException('Expected audit IDs must exactly match asset IDs.');
        }
        foreach ($expected as $index => $value) {
            if (is_array($value)) {
                $id = (int)($value['id'] ?? 0);
                $auditId = (int)($value['audit_id'] ?? 0);
            } else {
                $id = $ids[$index];
                $auditId = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int)$value : 0;
            }
            if ($id <= 0 || $auditId <= 0 || isset($map[$id])) {
                throw new InvalidArgumentException('Expected audit IDs are invalid.');
            }
            $map[$id] = $auditId;
        }
    } else {
        foreach ($expected as $id => $auditId) {
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
                || (!is_int($auditId) && !(is_string($auditId) && ctype_digit($auditId)))
                || (int)$id <= 0 || (int)$auditId <= 0) {
                throw new InvalidArgumentException('Expected audit IDs are invalid.');
            }
            $map[(int)$id] = (int)$auditId;
        }
    }
    ksort($map, SORT_NUMERIC);
    if (array_keys($map) !== $ids) {
        throw new InvalidArgumentException('Expected audit IDs must exactly match asset IDs.');
    }
    return $map;
}

function asset_lifecycle_warning(int $id, string $code, string $message): array
{
    return ['id' => $id, 'code' => $code, 'message' => $message];
}

function asset_lifecycle_local_url_path(string $resource, mixed $url): ?string
{
    if (!is_string($url)) return null;
    $prefix = $resource === 'media' ? '/static/img/' : '/static/files/';
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || !str_starts_with($path, $prefix)) return null;
    $relative = substr($path, strlen($prefix));
    return asset_lifecycle_storage_path_is_valid($relative) ? $relative : '';
}

function asset_lifecycle_temporary_issue(string $resource, int $actorUserId, string $storagePath, int $ttl = 900): string
{
    if (!in_array($resource, ['media', 'file'], true) || $actorUserId <= 0
        || !asset_lifecycle_storage_path_is_valid($storagePath) || !ensure_session_started(true)) {
        throw new RuntimeException('Unable to issue temporary upload cleanup grant.');
    }
    $token = bin2hex(random_bytes(32));
    $now = time();
    $grants = is_array($_SESSION['adiwira_asset_cleanup'] ?? null) ? $_SESSION['adiwira_asset_cleanup'] : [];
    foreach ($grants as $key => $grant) {
        if (!is_array($grant) || (int)($grant['expires_at'] ?? 0) < $now) unset($grants[$key]);
    }
    $grants[hash('sha256', $token)] = [
        'resource' => $resource,
        'actor_id' => $actorUserId,
        'storage_path' => $storagePath,
        'expires_at' => $now + max(60, min(3600, $ttl)),
    ];
    $_SESSION['adiwira_asset_cleanup'] = array_slice($grants, -20, null, true);
    return $token;
}

function asset_lifecycle_delete_temporary_public(PDO $pdo, string $resource, string $url, int $actorUserId, string $cleanupToken): void
{
    $config = asset_lifecycle_config($resource);
    $permission = $resource === 'media' ? 'core.media.upload' : 'core.files.upload';
    $prefix = $resource === 'media' ? '/static/img/' : '/static/files/';
    if ($actorUserId <= 0 || !user_can($pdo, $actorUserId, $permission)) {
        throw new AssetLifecycleAccessDenied(__('Access denied.'));
    }
    if ($url === '' || !str_starts_with($url, $prefix) || parse_url($url, PHP_URL_PATH) !== $url) {
        throw new InvalidArgumentException($resource === 'media' ? __('Invalid media path.') : __('Invalid file path.'));
    }
    $relative = substr($url, strlen($prefix));
    if (!asset_lifecycle_storage_path_is_valid($relative)) {
        throw new InvalidArgumentException($resource === 'media' ? __('Invalid media path.') : __('Invalid file path.'));
    }

    if (preg_match('/^[a-f0-9]{64}$/D', $cleanupToken) !== 1 || !ensure_session_started(false)) {
        throw new AssetLifecycleAccessDenied(__('Access denied.'));
    }
    $grantKey = hash('sha256', $cleanupToken);
    $grant = $_SESSION['adiwira_asset_cleanup'][$grantKey] ?? null;
    if (!is_array($grant) || (int)($grant['expires_at'] ?? 0) < time()
        || !hash_equals((string)($grant['resource'] ?? ''), $resource)
        || (int)($grant['actor_id'] ?? 0) !== $actorUserId
        || !hash_equals((string)($grant['storage_path'] ?? ''), $relative)) {
        unset($_SESSION['adiwira_asset_cleanup'][$grantKey]);
        throw new AssetLifecycleAccessDenied(__('Access denied.'));
    }

    // Saved rows own their storage identity regardless of lifecycle state or URL spelling.
    $existing = $pdo->prepare(
        "SELECT id FROM {$config['table']}
         WHERE (storage_disk = 'public' AND storage_path = :path) OR url = :url
         LIMIT 1"
    );
    $existing->execute([':path' => $relative, ':url' => $url]);
    if ($existing->fetchColumn() !== false) {
        throw new AssetLifecycleConflict($resource === 'media'
            ? __('Use the media ID to move a saved item to trash.')
            : __('Use the file ID to move a saved item to trash.'));
    }

    $source = asset_lifecycle_managed_source($config, 'public', $relative);
    if (($source['status'] ?? '') !== 'file' || !unlink($source['path'])) {
        throw new RuntimeException(__('Failed to delete temporary upload.'));
    }
    unset($_SESSION['adiwira_asset_cleanup'][$grantKey]);
}

function asset_lifecycle_trash(PDO $pdo, string $resource, array $ids, int $actorUserId): array
{
    $config = asset_lifecycle_config($resource);
    $ids = asset_lifecycle_ids($ids);
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Asset lifecycle operations require their own transaction.');
    }
    $moved = [];
    $warnings = [];
    $event = null;
    $result = null;
    try {
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Unable to begin asset lifecycle transaction.');
        }
        if ($actorUserId <= 0 || !authorization_lock_actor_permissions($pdo, $actorUserId)) {
            throw new AssetLifecycleAccessDenied('Asset lifecycle actor is not active.');
        }
        $rows = asset_lifecycle_locked_rows($pdo, $config['table'], $ids, 0);
        asset_lifecycle_authorize($pdo, $config, 'delete', $rows, $actorUserId);
        $plans = [];
        $lockedItems = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            $source = asset_lifecycle_managed_source($config, $row['storage_disk'], $row['storage_path']);
            if ($source['status'] !== 'file') {
                if (asset_lifecycle_local_url_path($resource, $row['url'] ?? null) !== null) {
                    throw new AssetLifecycleConflict('Local asset storage metadata is missing or invalid.');
                }
                $warnings[] = asset_lifecycle_warning($id, $source['status'], $source['warning']);
                $plans[$id] = ['source' => null, 'quarantine_relative' => null];
                $lockedItems[] = ['row' => $row, 'artifacts' => asset_lifecycle_unmanaged_artifact($source['status'])];
                continue;
            }
            $shared = $pdo->prepare(
                "SELECT id FROM {$config['table']}
                 WHERE id <> :id
                   AND storage_disk = :disk AND storage_path = :path
                 ORDER BY id LIMIT 1 FOR UPDATE"
            );
            $shared->execute([':id' => $id, ':disk' => $source['disk'], ':path' => $source['storage_path']]);
            if ($shared->fetchColumn() !== false) {
                throw new AssetLifecycleConflict('Managed storage is shared by multiple asset records.');
            }
            $quarantineRelative = asset_lifecycle_quarantine_relative($resource, $id, $source['disk'], $source['storage_path']);
            $plans[$id] = ['source' => $source, 'quarantine_relative' => $quarantineRelative];
            $lockedItems[] = ['row' => $row, 'artifacts' => [[
                'kind' => 'file',
                'role' => 'primary',
                'managed' => true,
                'disk' => $source['disk'],
                'root' => $resource . '.' . $source['disk'],
                'relative_path' => $source['storage_path'],
                'absolute_path' => $source['path'],
                'state' => 'present',
                'transition' => [
                    'operation' => 'move',
                    'from' => ['root' => $resource . '.' . $source['disk'], 'relative_path' => $source['storage_path']],
                    'to' => ['root' => 'asset.quarantine', 'relative_path' => $quarantineRelative],
                ],
            ]]];
        }
        $event = resource_lifecycle_capture($pdo, $resource, 'trash', $lockedItems, [
            'actor_id' => $actorUserId,
            'source' => 'core.asset_lifecycle',
            'metadata' => [],
        ]);
        $items = [];
        $bulk = count($ids) > 1;
        $update = $pdo->prepare(
            "UPDATE {$config['table']}
             SET is_deleted = 1, deleted_at = NOW(), quarantine_path = :quarantine_path
             WHERE id = :id AND is_deleted = 0 LIMIT 1"
        );
        foreach ($rows as &$row) {
            $id = $row['id'];
            $plan = &$plans[$id];
            $quarantineRelative = $plan['quarantine_relative'];
            if ($plan['source'] !== null) {
                $currentSource = asset_lifecycle_managed_source($config, $row['storage_disk'], $row['storage_path']);
                if (($currentSource['status'] ?? null) !== 'file'
                    || !hash_equals($plan['source']['path'], $currentSource['path'])) {
                    throw new AssetLifecycleConflict('Managed asset changed before moving to trash.');
                }
                $quarantineRoot = asset_lifecycle_quarantine_root(true);
                $quarantineTarget = asset_lifecycle_ensure_quarantine_parent($quarantineRoot, $quarantineRelative);
                asset_lifecycle_rename($plan['source']['path'], $quarantineTarget);
                $moved[] = ['original' => $plan['source']['path'], 'artifact' => $quarantineTarget];
                $plan['artifact'] = $quarantineTarget;
            }
            $update->execute([':quarantine_path' => $quarantineRelative, ':id' => $id]);
            if ($update->rowCount() !== 1) {
                throw new AssetLifecycleConflict('Asset state changed while moving to trash.');
            }
            $row['is_deleted'] = 1;
            $row['quarantine_path'] = $quarantineRelative;
            $auditId = asset_lifecycle_audit($pdo, $resource . '.trashed', $actorUserId, $resource, $id, [
                'storage_disk' => is_string($row['storage_disk']) ? $row['storage_disk'] : null,
                'quarantined' => $quarantineRelative !== null,
                'bulk' => $bulk,
            ]);
            $items[] = ['id' => $id, 'audit_id' => $auditId];
        }
        unset($row);
        $afterRows = asset_lifecycle_locked_rows($pdo, $config['table'], $ids, 1);
        $afterById = array_column($afterRows, null, 'id');
        $changedItems = $event['items'];
        foreach ($changedItems as &$changedItem) {
            $id = (int)$changedItem['id'];
            $changedItem['after'] = $afterById[$id];
            if (isset($plans[$id]['artifact'])) {
                $changedItem['artifacts'][0]['disk'] = 'quarantine';
                $changedItem['artifacts'][0]['root'] = 'asset.quarantine';
                $changedItem['artifacts'][0]['relative_path'] = $plans[$id]['quarantine_relative'];
                $changedItem['artifacts'][0]['absolute_path'] = $plans[$id]['artifact'];
            }
        }
        unset($changedItem);
        $event = resource_lifecycle_before_commit($pdo, $event, [
            'items' => $changedItems,
            'result' => ['affected' => count($ids), 'audit_ids' => array_column($items, 'audit_id')],
            'warnings' => $warnings,
        ]);
        if (!$pdo->commit()) {
            throw new RuntimeException('Unable to commit asset trash transaction.');
        }
        $result = ['count' => count($ids), 'ids' => $ids, 'items' => $items, 'warnings' => $warnings, 'rows' => $afterRows];
    } catch (Throwable $e) {
        // Files move before their database state; reverse every move if the transaction cannot commit.
        foreach (array_reverse($moved) as $move) {
            if (!rename($move['artifact'], $move['original'])) {
                error_log('[CRITICAL asset_lifecycle] Trash rollback could not restore ' . $move['artifact'] . ' to ' . $move['original']);
            }
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    asset_lifecycle_committed($pdo, $event);
    return $result;
}

function asset_lifecycle_restore(PDO $pdo, string $resource, array $ids, int $actorUserId, ?array $expectedAuditIds = null): array
{
    $config = asset_lifecycle_config($resource);
    $ids = asset_lifecycle_ids($ids);
    $expected = $expectedAuditIds === null ? null : asset_lifecycle_expected_audits($ids, $expectedAuditIds);
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Asset lifecycle operations require their own transaction.');
    }
    $restored = [];
    $warnings = [];
    $event = null;
    $result = null;
    try {
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Unable to begin asset lifecycle transaction.');
        }
        if ($actorUserId <= 0 || !authorization_lock_actor_permissions($pdo, $actorUserId)) {
            throw new AssetLifecycleAccessDenied('Asset lifecycle actor is not active.');
        }
        $rows = asset_lifecycle_locked_rows($pdo, $config['table'], $ids, 1);
        asset_lifecycle_authorize($pdo, $config, 'restore', $rows, $actorUserId);
        $plans = [];
        $lockedItems = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if ($expected !== null && asset_lifecycle_latest_audit_id($pdo, $resource, $id) !== $expected[$id]) {
                throw new AssetLifecycleConflict('Asset audit state changed before restore.');
            }
            if ($row['quarantine_path'] === null) {
                $warnings[] = asset_lifecycle_warning($id, 'metadata_only', 'Asset had no quarantine artifact; only metadata was restored.');
                $plans[$id] = ['artifact' => null, 'target' => null];
                $lockedItems[] = ['row' => $row, 'artifacts' => asset_lifecycle_unmanaged_artifact('missing')];
                continue;
            }
            $artifact = asset_lifecycle_existing_quarantine($resource, $row);
            if ($artifact['status'] !== 'file') {
                throw new RuntimeException('Quarantine artifact is missing.');
            }
            $target = asset_lifecycle_safe_original_target($config, $row['storage_disk'], $row['storage_path']);
            $plans[$id] = ['artifact' => $artifact, 'target' => $target];
            $lockedItems[] = ['row' => $row, 'artifacts' => [[
                'kind' => 'file',
                'role' => 'primary',
                'managed' => true,
                'disk' => 'quarantine',
                'root' => 'asset.quarantine',
                'relative_path' => $artifact['relative'],
                'absolute_path' => $artifact['path'],
                'state' => 'present',
                'transition' => [
                    'operation' => 'move',
                    'from' => ['root' => 'asset.quarantine', 'relative_path' => $artifact['relative']],
                    'to' => ['root' => $resource . '.' . $row['storage_disk'], 'relative_path' => $row['storage_path']],
                ],
            ]]];
        }
        $event = resource_lifecycle_capture($pdo, $resource, 'restore', $lockedItems, [
            'actor_id' => $actorUserId,
            'source' => 'core.asset_lifecycle',
            'metadata' => ['undo' => $expected !== null],
        ]);
        $bulk = count($ids) > 1;
        $auditIds = [];
        $update = $pdo->prepare(
            "UPDATE {$config['table']}
             SET is_deleted = 0, deleted_at = NULL, quarantine_path = NULL
             WHERE id = :id AND is_deleted = 1 LIMIT 1"
        );
        foreach ($rows as $row) {
            $id = $row['id'];
            $plan = $plans[$id];
            if ($plan['artifact'] !== null) {
                $currentArtifact = asset_lifecycle_existing_quarantine($resource, $row);
                $currentTarget = asset_lifecycle_safe_original_target($config, $row['storage_disk'], $row['storage_path']);
                if (($currentArtifact['status'] ?? null) !== 'file'
                    || !hash_equals($plan['artifact']['path'], $currentArtifact['path'])
                    || !hash_equals($plan['target'], $currentTarget)) {
                    throw new AssetLifecycleConflict('Asset storage changed before restore.');
                }
                asset_lifecycle_rename($currentArtifact['path'], $currentTarget);
                $restored[] = ['original' => $currentTarget, 'artifact' => $currentArtifact['path']];
            }
            $update->execute([':id' => $id]);
            if ($update->rowCount() !== 1) {
                throw new AssetLifecycleConflict('Asset state changed while restoring.');
            }
            $auditIds[] = asset_lifecycle_audit($pdo, $resource . '.restored', $actorUserId, $resource, $id, [
                'bulk' => $bulk,
                'undo' => $expected !== null,
            ]);
        }
        $afterRows = asset_lifecycle_locked_rows($pdo, $config['table'], $ids, 0);
        $afterById = array_column($afterRows, null, 'id');
        $changedItems = $event['items'];
        foreach ($changedItems as &$changedItem) {
            $id = (int)$changedItem['id'];
            $changedItem['after'] = $afterById[$id];
            if ($plans[$id]['target'] !== null) {
                $changedItem['artifacts'][0]['disk'] = $afterById[$id]['storage_disk'];
                $changedItem['artifacts'][0]['root'] = $resource . '.' . $afterById[$id]['storage_disk'];
                $changedItem['artifacts'][0]['relative_path'] = $afterById[$id]['storage_path'];
                $changedItem['artifacts'][0]['absolute_path'] = $plans[$id]['target'];
            }
        }
        unset($changedItem);
        $event = resource_lifecycle_before_commit($pdo, $event, [
            'items' => $changedItems,
            'result' => ['affected' => count($ids), 'audit_ids' => $auditIds],
            'warnings' => $warnings,
        ]);
        if (!$pdo->commit()) {
            throw new RuntimeException('Unable to commit asset restore transaction.');
        }
        $result = ['count' => count($ids), 'ids' => $ids, 'warnings' => $warnings];
    } catch (Throwable $e) {
        // Restore compensation puts bytes back under the exact quarantine name before rolling back metadata.
        foreach (array_reverse($restored) as $move) {
            if (!rename($move['original'], $move['artifact'])) {
                error_log('[CRITICAL asset_lifecycle] Restore rollback could not return ' . $move['original'] . ' to ' . $move['artifact']);
            }
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    asset_lifecycle_committed($pdo, $event);
    return $result;
}

function asset_lifecycle_purge(PDO $pdo, string $resource, array $ids, int $actorUserId): array
{
    $config = asset_lifecycle_config($resource);
    $ids = asset_lifecycle_ids($ids);
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Asset lifecycle operations require their own transaction.');
    }
    $recoveries = [];
    $warnings = [];
    $event = null;
    try {
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Unable to begin asset lifecycle transaction.');
        }
        if ($actorUserId <= 0 || !authorization_lock_actor_permissions($pdo, $actorUserId)) {
            throw new AssetLifecycleAccessDenied('Asset lifecycle actor is not active.');
        }
        $rows = asset_lifecycle_locked_rows($pdo, $config['table'], $ids, 1);
        asset_lifecycle_authorize($pdo, $config, 'purge', $rows, $actorUserId);
        $plans = [];
        $lockedItems = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if ($row['quarantine_path'] === null && asset_lifecycle_local_url_path($resource, $row['url'] ?? null) !== null) {
                throw new AssetLifecycleConflict('Local asset storage metadata is missing or invalid.');
            }
            if ($row['quarantine_path'] === null) {
                $plans[$id] = ['artifact' => null];
                $lockedItems[] = ['row' => $row, 'artifacts' => asset_lifecycle_unmanaged_artifact('unmanaged')];
                continue;
            }
            $artifact = asset_lifecycle_existing_quarantine($resource, $row);
            if ($artifact['status'] === 'missing') {
                $warnings[] = asset_lifecycle_warning($id, 'missing_quarantine', 'Quarantine artifact is missing; metadata purge continued.');
                $plans[$id] = ['artifact' => null];
                $lockedItems[] = ['row' => $row, 'artifacts' => asset_lifecycle_unmanaged_artifact('missing')];
                continue;
            }
            $plans[$id] = ['artifact' => $artifact];
            $lockedItems[] = ['row' => $row, 'artifacts' => [[
                'kind' => 'file',
                'role' => 'primary',
                'managed' => true,
                'disk' => 'quarantine',
                'root' => 'asset.quarantine',
                'relative_path' => $artifact['relative'],
                'absolute_path' => $artifact['path'],
                'state' => 'present',
                'transition' => [
                    'operation' => 'delete',
                    'from' => ['root' => 'asset.quarantine', 'relative_path' => $artifact['relative']],
                ],
            ]]];
        }
        $event = resource_lifecycle_capture($pdo, $resource, 'purge', $lockedItems, [
            'actor_id' => $actorUserId,
            'source' => 'core.asset_lifecycle',
            'metadata' => [],
        ]);
        $bulk = count($ids) > 1;
        $auditIds = [];
        $deleteLinks = $resource === 'media'
            ? $pdo->prepare('DELETE FROM post_media_items WHERE media_id = :id')
            : null;
        $delete = $pdo->prepare("DELETE FROM {$config['table']} WHERE id = :id AND is_deleted = 1 LIMIT 1");
        foreach ($rows as $row) {
            $id = $row['id'];
            $artifact = $plans[$id]['artifact'];
            if ($artifact !== null) {
                $currentArtifact = asset_lifecycle_existing_quarantine($resource, $row);
                if (($currentArtifact['status'] ?? null) !== 'file'
                    || !hash_equals($artifact['path'], $currentArtifact['path'])) {
                    throw new AssetLifecycleConflict('Quarantine artifact changed before purge.');
                }
                do {
                    $recovery = dirname($currentArtifact['path']) . '/.purge-recovery-' . bin2hex(random_bytes(16));
                } while (file_exists($recovery) || is_link($recovery));
                asset_lifecycle_rename($currentArtifact['path'], $recovery);
                $recoveries[] = [
                    'artifact' => $currentArtifact['path'],
                    'recovery' => $recovery,
                    'relative' => dirname($currentArtifact['relative']) . '/' . basename($recovery),
                    'id' => $id,
                ];
            }
            if ($deleteLinks !== null) {
                $deleteLinks->execute([':id' => $id]);
            }
            $auditIds[] = asset_lifecycle_audit($pdo, $resource . '.purged', $actorUserId, $resource, $id, ['bulk' => $bulk]);
            $delete->execute([':id' => $id]);
            if ($delete->rowCount() !== 1) {
                throw new AssetLifecycleConflict('Asset state changed while purging.');
            }
        }
        $recoveryById = array_column($recoveries, null, 'id');
        $changedItems = $event['items'];
        foreach ($changedItems as &$changedItem) {
            $id = (int)$changedItem['id'];
            $changedItem['after'] = null;
            if (isset($recoveryById[$id])) {
                $changedItem['artifacts'][0]['disk'] = 'recovery';
                $changedItem['artifacts'][0]['root'] = 'asset.recovery';
                $changedItem['artifacts'][0]['relative_path'] = $recoveryById[$id]['relative'];
                $changedItem['artifacts'][0]['absolute_path'] = $recoveryById[$id]['recovery'];
                $changedItem['artifacts'][0]['transition']['from'] = [
                    'root' => 'asset.recovery',
                    'relative_path' => $recoveryById[$id]['relative'],
                ];
            }
        }
        unset($changedItem);
        $event = resource_lifecycle_before_commit($pdo, $event, [
            'items' => $changedItems,
            'result' => ['affected' => count($ids), 'audit_ids' => $auditIds],
            'warnings' => $warnings,
        ]);
        if (!$pdo->commit()) {
            throw new RuntimeException('Unable to commit asset purge transaction.');
        }
    } catch (Throwable $e) {
        // Recovery names preserve every artifact until the database purge has committed.
        foreach (array_reverse($recoveries) as $move) {
            if (!rename($move['recovery'], $move['artifact'])) {
                error_log('[CRITICAL asset_lifecycle] Purge rollback could not return ' . $move['recovery'] . ' to ' . $move['artifact']);
            }
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    foreach ($recoveries as $move) {
        if (!unlink($move['recovery'])) {
            error_log('[asset_lifecycle] Committed purge could not unlink recovery artifact ' . $move['recovery']);
            $warnings[] = asset_lifecycle_warning($move['id'], 'recovery_unlink_failed', 'Purge committed but its recovery artifact could not be removed.');
        }
    }
    $recoveryById = array_column($recoveries, null, 'id');
    foreach ($event['items'] as &$committedItem) {
        $id = (int)$committedItem['id'];
        if (isset($recoveryById[$id])) {
            $committedItem['artifacts'][0]['state'] = file_exists($recoveryById[$id]['recovery']) ? 'present' : 'removed';
        }
    }
    unset($committedItem);
    $event['warnings'] = $warnings;
    asset_lifecycle_committed($pdo, resource_lifecycle_event($event));
    return ['count' => count($ids), 'ids' => $ids, 'warnings' => $warnings];
}
