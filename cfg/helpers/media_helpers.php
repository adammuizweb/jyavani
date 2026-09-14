<?php
declare(strict_types=1);

function media_extension_context(array $input = [], array $defaults = []): array
{
    $source = array_replace($defaults, array_filter($input, static fn(mixed $item): bool => $item !== null && $item !== ''));
    $value = static function (string $key, int $max = 100) use ($source): ?string {
        $raw = $source[$key] ?? null;
        if ($raw === null || $raw === '') return null;
        if (!is_scalar($raw)) return null;
        $raw = trim((string)$raw);
        return $raw !== '' && strlen($raw) <= $max
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]*\z/D', $raw) === 1 ? $raw : null;
    };
    $resourceId = $source['resource_id'] ?? null;
    if (is_string($resourceId) && preg_match('/\A[1-9][0-9]*\z/D', $resourceId) === 1) $resourceId = (int)$resourceId;
    if (!is_int($resourceId) || $resourceId <= 0) $resourceId = null;
    $selectionMode = $source['selection_mode'] ?? 'immediate';
    $selectionMode = is_string($selectionMode) && in_array($selectionMode, ['immediate', 'review'], true)
        ? $selectionMode : 'immediate';

    return [
        'schema' => 2,
        'surface' => $value('surface') ?? 'media',
        'consumer' => $value('consumer'),
        'resource_id' => $resourceId,
        'field' => $value('field'),
        'content_locale' => $value('content_locale', 32),
        'selection_mode' => $selectionMode,
    ];
}

function media_picker_context_from_request(array $request, array $defaults = []): array
{
    return media_extension_context([
        'surface' => $request['media_surface'] ?? null,
        'consumer' => $request['media_consumer'] ?? null,
        'resource_id' => $request['media_resource_id'] ?? null,
        'field' => $request['media_field'] ?? null,
        'content_locale' => $request['media_content_locale'] ?? null,
        'selection_mode' => $request['media_selection_mode'] ?? null,
    ], $defaults);
}

function media_picker_id_from_request(array $request): ?string
{
    $value = $request['media_picker_id'] ?? null;
    if (!is_scalar($value)) return null;
    $value = trim((string)$value);
    return $value !== '' && strlen($value) <= 100
        && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9-]*\z/D', $value) === 1 ? $value : null;
}

function media_picker_query(array $context, ?string $pickerId = null): string
{
    $context = media_extension_context($context);
    $query = [];
    foreach (['surface', 'consumer', 'resource_id', 'field', 'content_locale', 'selection_mode'] as $key) {
        if ($context[$key] !== null) $query['media_' . $key] = $context[$key];
    }
    if ($pickerId !== null && media_picker_id_from_request(['media_picker_id' => $pickerId]) !== null) {
        $query['media_picker_id'] = $pickerId;
    }
    return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function media_extension_input(array $input): array
{
    $raw = $input['media_extension'] ?? [];
    if (!is_array($raw) || array_is_list($raw) || count($raw) > 20) return [];
    $normalized = [];
    $fieldCount = 0;
    foreach ($raw as $owner => $fields) {
        if (!is_string($owner) || !resource_lifecycle_name_is_valid($owner)
            || !is_array($fields) || array_is_list($fields) || count($fields) > 50) continue;
        foreach ($fields as $field => $value) {
            if (++$fieldCount > 100) return [];
            if (!is_string($field) || strlen($field) > 100
                || preg_match('/\A[a-zA-Z][a-zA-Z0-9._-]*\z/D', $field) !== 1) continue;
            $values = is_array($value) && array_is_list($value) ? array_slice($value, 0, 20) : [$value];
            $clean = [];
            foreach ($values as $item) {
                if (!is_scalar($item)) continue;
                $item = (string)$item;
                if (strlen($item) <= 4096 && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $item) !== 1) $clean[] = $item;
            }
            if ($clean !== []) $normalized[$owner][$field] = is_array($value) ? $clean : $clean[0];
        }
    }
    $json = json_encode($normalized);
    return is_string($json) && strlen($json) <= 65536 ? $normalized : [];
}

function media_load_live(PDO $pdo, int $id, bool $forUpdate = false): ?array
{
    if ($id <= 0) return null;
    $lock = $forUpdate && $pdo->inTransaction() && in_array((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true)
        ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT * FROM media WHERE id = :id AND is_deleted = 0 LIMIT 1' . $lock);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function media_user_can_read(PDO $pdo, int $actorUserId, array $row): bool
{
    return $actorUserId > 0 && (int)($row['id'] ?? 0) > 0
        && user_can($pdo, $actorUserId, 'core.media.read', ['owner_id' => (int)($row['user_id'] ?? 0)]);
}

function media_client_url(array $row, bool $allowProtected = false): ?string
{
    $id = (int)($row['id'] ?? 0);
    $visibility = strtolower((string)($row['visibility'] ?? 'public'));
    $disk = strtolower((string)($row['storage_disk'] ?? 'public'));
    $scope = strtolower((string)($row['access_scope'] ?? 'public'));
    $protected = $visibility !== 'public' || $disk !== 'public' || $scope !== 'public';
    if ($protected) return $allowProtected && $id > 0 ? '/private/media/view/?id=' . $id : null;
    $url = trim((string)($row['url'] ?? ''));
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) return null;
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return $scheme === null || in_array(strtolower((string)$scheme), ['http', 'https'], true) ? $url : null;
}

function media_public_file_descriptor(array $row): ?array
{
    if (strtolower((string)($row['visibility'] ?? '')) !== 'public'
        || strtolower((string)($row['storage_disk'] ?? '')) !== 'public'
        || strtolower((string)($row['access_scope'] ?? '')) !== 'public'
        || (int)($row['is_deleted'] ?? 0) !== 0
        || !function_exists('asset_lifecycle_config') || !function_exists('asset_lifecycle_managed_source')) {
        return null;
    }
    $source = asset_lifecycle_managed_source(asset_lifecycle_config('media'), 'public', $row['storage_path'] ?? null);
    if (($source['status'] ?? '') !== 'file' || !is_string($source['path'] ?? null)) return null;
    $path = $source['path'];
    $size = filesize($path);
    $modified = filemtime($path);
    if ($size === false || $modified === false || $size < 0) return null;

    $image = @getimagesize($path);
    $mime = is_array($image) ? strtolower(trim((string)($image['mime'] ?? ''))) : '';
    if (!in_array($mime, ['image/avif', 'image/jpeg', 'image/png', 'image/webp'], true)) return null;
    $stat = lstat($path);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000) return null;
    $etag = 'W/"' . hash('sha256', implode("\0", [
        (string)($stat['dev'] ?? ''), (string)($stat['ino'] ?? ''), (string)$size,
        (string)$modified, (string)($source['storage_path'] ?? ''),
    ])) . '"';
    return [
        'path' => $path, 'mime' => $mime, 'size' => (int)$size, 'modified' => (int)$modified, 'etag' => $etag,
        'device' => (int)($stat['dev'] ?? -1), 'inode' => (int)($stat['ino'] ?? -1),
    ];
}

function media_public_file_identity_matches(array $descriptor, array $stat): bool
{
    return (($stat['mode'] ?? 0) & 0170000) === 0100000
        && (int)($stat['dev'] ?? -2) === (int)($descriptor['device'] ?? -1)
        && (int)($stat['ino'] ?? -2) === (int)($descriptor['inode'] ?? -1)
        && (int)($stat['size'] ?? -1) === (int)($descriptor['size'] ?? -2)
        && (int)($stat['mtime'] ?? -1) === (int)($descriptor['modified'] ?? -2);
}

function media_http_date_timestamp(string $value): ?int
{
    $value = trim($value);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \\G\\M\\T', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('D, d M Y H:i:s \\G\\M\\T') !== $value) {
        return null;
    }
    return $date->getTimestamp();
}

function media_etag_weak_value(string $etag): string
{
    return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
}

function media_public_file_response_plan(array $descriptor, array $server): array
{
    $method = strtoupper(trim((string)($server['REQUEST_METHOD'] ?? 'GET')));
    $size = max(0, (int)($descriptor['size'] ?? 0));
    $modified = max(0, (int)($descriptor['modified'] ?? 0));
    $etag = (string)($descriptor['etag'] ?? '');
    $headers = [
        'Content-Type' => (string)($descriptor['mime'] ?? 'application/octet-stream'),
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'public, max-age=0, must-revalidate',
        'ETag' => $etag,
        'Last-Modified' => gmdate('D, d M Y H:i:s', $modified) . ' GMT',
        'Accept-Ranges' => 'bytes',
    ];
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return ['status' => 405, 'headers' => $headers + ['Allow' => 'GET, HEAD'], 'offset' => 0, 'length' => 0, 'send_body' => false];
    }

    $ifNoneMatch = trim((string)($server['HTTP_IF_NONE_MATCH'] ?? ''));
    $notModified = false;
    if ($ifNoneMatch !== '') {
        $expected = media_etag_weak_value($etag);
        $notModified = $ifNoneMatch === '*';
        foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
            if (media_etag_weak_value($candidate) === $expected) $notModified = true;
        }
    } else {
        $ifModifiedSince = media_http_date_timestamp((string)($server['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        $notModified = $ifModifiedSince !== null && $ifModifiedSince >= $modified;
    }
    if ($notModified) {
        return ['status' => 304, 'headers' => $headers, 'offset' => 0, 'length' => 0, 'send_body' => false];
    }

    $offset = 0;
    $length = $size;
    $status = 200;
    $range = trim((string)($server['HTTP_RANGE'] ?? ''));
    if (str_contains($range, ',')) $range = '';
    $ifRange = trim((string)($server['HTTP_IF_RANGE'] ?? ''));
    if ($range !== '' && $ifRange !== '') {
        $ifRangeDate = media_http_date_timestamp($ifRange);
        $strongMatch = !str_starts_with($etag, 'W/') && hash_equals($etag, $ifRange);
        if (!$strongMatch && ($ifRangeDate === null || $ifRangeDate < $modified)) $range = '';
    }
    if ($range !== '') {
        $valid = preg_match('/\Abytes=(\d*)-(\d*)\z/D', $range, $match) === 1 && ($match[1] !== '' || $match[2] !== '') && $size > 0;
        if ($valid && $match[1] === '') {
            $suffix = (int)$match[2];
            $valid = $suffix > 0;
            $offset = max(0, $size - $suffix);
            $length = $size - $offset;
        } elseif ($valid) {
            $offset = (int)$match[1];
            $end = $match[2] === '' ? $size - 1 : (int)$match[2];
            $valid = $offset < $size && $end >= $offset;
            if ($valid) $length = min($end, $size - 1) - $offset + 1;
        }
        if (!$valid) {
            return ['status' => 416, 'headers' => $headers + ['Content-Range' => 'bytes */' . $size], 'offset' => 0, 'length' => 0, 'send_body' => false];
        }
        $status = 206;
        $headers['Content-Range'] = 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $size;
    }
    $headers['Content-Length'] = (string)$length;
    return ['status' => $status, 'headers' => $headers, 'offset' => $offset, 'length' => $length, 'send_body' => $method === 'GET'];
}

function media_serve_public_file(array $row, ?array $server = null): bool
{
    $descriptor = media_public_file_descriptor($row);
    if ($descriptor === null) return false;
    $beforeOpen = lstat($descriptor['path']);
    if (!is_array($beforeOpen) || !media_public_file_identity_matches($descriptor, $beforeOpen)) return false;
    $stream = fopen($descriptor['path'], 'rb');
    if ($stream === false) return false;
    $opened = fstat($stream);
    if (!is_array($opened) || !media_public_file_identity_matches($descriptor, $opened)) {
        fclose($stream);
        return false;
    }
    $plan = media_public_file_response_plan($descriptor, $server ?? $_SERVER);
    if ($plan['send_body'] && $plan['offset'] > 0 && fseek($stream, (int)$plan['offset']) !== 0) {
        fclose($stream);
        return false;
    }
    http_response_code((int)$plan['status']);
    foreach ($plan['headers'] as $name => $value) header($name . ': ' . $value);
    if (!$plan['send_body'] || $plan['length'] <= 0) {
        fclose($stream);
        return true;
    }
    $remaining = (int)$plan['length'];
    while ($remaining > 0 && !feof($stream)) {
        $chunk = fread($stream, min(8192, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($stream);
    if ($remaining !== 0) error_log('[media] Public media response ended before the planned content length.');
    return true;
}

function media_projected_url_is_valid(string $url, bool $allowProtected, ?string $canonicalProtectedUrl = null): bool
{
    if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) return false;
    if (str_starts_with($url, '/private/')) {
        return $allowProtected && $canonicalProtectedUrl !== null && hash_equals($canonicalProtectedUrl, $url);
    }
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return $scheme === null || in_array(strtolower((string)$scheme), ['http', 'https'], true);
}

function media_sanitize_projected_metadata(array $candidate, array $fallback): array
{
    $safeText = static function (mixed $value, int $max): ?string {
        return is_string($value) && strlen($value) <= $max
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1 ? $value : null;
    };
    foreach (['title' => 4096, 'alt' => 4096, 'caption' => 65536, 'credit' => 4096] as $key => $max) {
        $candidate[$key] = $safeText($candidate[$key] ?? null, $max)
            ?? $safeText($fallback[$key] ?? null, $max)
            ?? '';
    }
    $target = $candidate['target_url'] ?? $fallback['target_url'] ?? '';
    $candidate['target_url'] = is_string($target) && ($target === '' || (filter_var($target, FILTER_VALIDATE_URL)
        && in_array(strtolower((string)parse_url($target, PHP_URL_SCHEME)), ['http', 'https'], true))) ? $target : ($fallback['target_url'] ?? '');
    $attribute = $candidate['target_attribute'] ?? $fallback['target_attribute'] ?? '';
    $candidate['target_attribute'] = is_string($attribute) && in_array($attribute, ['', '_self', '_blank', '_parent', '_top'], true)
        ? $attribute : ($fallback['target_attribute'] ?? '');
    return $candidate;
}

function media_public_extensions(mixed $extensions): array
{
    if (!is_array($extensions) || array_is_list($extensions) || count($extensions) > 20) return [];
    $cleanValue = static function (mixed $value, int $depth = 0) use (&$cleanValue): mixed {
        if ($depth > 4) return null;
        if (is_string($value)) {
            return strlen($value) <= 4096 && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1 ? $value : null;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) return $value;
        if (!is_array($value) || count($value) > 50) return null;
        $result = [];
        foreach ($value as $key => $item) {
            if ((!is_int($key) && (!is_string($key) || strlen($key) > 100
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $key) !== 1))) continue;
            $result[$key] = $cleanValue($item, $depth + 1);
        }
        return $result;
    };
    $normalized = [];
    foreach ($extensions as $owner => $value) {
        if (!is_string($owner) || !resource_lifecycle_name_is_valid($owner)) continue;
        $normalized[$owner] = $cleanValue($value);
    }
    $json = json_encode($normalized);
    return is_string($json) && strlen($json) <= 65536 ? $normalized : [];
}

function media_admin_list_badges(PDO $pdo, array $data, array $row, array $context = []): array
{
    $filtered = apply_filters('media_admin_list_badges', [], $data, $row, media_extension_context($context), $pdo);
    if (!is_array($filtered) || !array_is_list($filtered)) return [];
    $badges = [];
    foreach (array_slice($filtered, 0, 10) as $badge) {
        if (!is_array($badge)) continue;
        $label = trim((string)($badge['label'] ?? ''));
        $tone = trim((string)($badge['tone'] ?? 'neutral'));
        if ($label === '' || strlen($label) > 100 || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) continue;
        if (preg_match('/\A[a-z][a-z0-9-]{0,31}\z/D', $tone) !== 1) $tone = 'neutral';
        $badges[] = ['label' => $label, 'tone' => $tone];
    }
    return $badges;
}

function media_filter_data(PDO $pdo, array $row, array $context = [], bool $allowProtected = false): array
{
    $context = media_extension_context($context);
    $targetUrl = trim((string)($row['target_url'] ?? $row['link_url'] ?? ''));
    if ($targetUrl !== '' && (!filter_var($targetUrl, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string)parse_url($targetUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
        $targetUrl = '';
    }
    $targetAttribute = (string)($row['target_attribute'] ?? $row['link_target'] ?? '');
    if (!in_array($targetAttribute, ['', '_self', '_blank', '_parent', '_top'], true)) $targetAttribute = '';
    $data = [
        'id' => (int)($row['id'] ?? 0),
        'url' => media_client_url($row, $allowProtected),
        'filename' => (string)($row['filename'] ?? ''),
        'mime' => (string)($row['mime'] ?? ''),
        'ext' => (string)($row['ext'] ?? ''),
        'size' => (int)($row['size'] ?? 0),
        'width' => isset($row['width']) ? (int)$row['width'] : null,
        'height' => isset($row['height']) ? (int)$row['height'] : null,
        'title' => (string)($row['title'] ?? ''),
        'alt' => (string)($row['alt'] ?? ''),
        'caption' => (string)($row['caption'] ?? ''),
        'credit' => (string)($row['credit'] ?? ''),
        'target_url' => $targetUrl,
        'target_attribute' => $targetAttribute,
        'visibility' => strtolower((string)($row['visibility'] ?? 'public')),
        'storage_disk' => strtolower((string)($row['storage_disk'] ?? 'public')),
        'access_scope' => strtolower((string)($row['access_scope'] ?? 'public')),
        'is_downloadable' => (int)($row['is_downloadable'] ?? 1),
        'extensions' => [],
    ];
    $data = media_sanitize_projected_metadata($data, []);
    $filtered = apply_filters('media_data', $data, $row, $context, $pdo);
    if (!is_array($filtered)) return $data;
    foreach ($data as $key => $fallback) {
        if (!array_key_exists($key, $filtered)) $filtered[$key] = $fallback;
    }
    $filtered['id'] = $data['id'];
    $filteredUrl = is_string($filtered['url']) ? trim($filtered['url']) : '';
    $canonicalProtectedUrl = is_string($data['url']) && str_starts_with($data['url'], '/private/') ? $data['url'] : null;
    $filtered['url'] = media_projected_url_is_valid($filteredUrl, $allowProtected, $canonicalProtectedUrl)
        ? $filteredUrl : $data['url'];
    $filteredTarget = is_string($filtered['target_url']) ? trim($filtered['target_url']) : '';
    $filtered['target_url'] = $filteredTarget === '' || (filter_var($filteredTarget, FILTER_VALIDATE_URL)
        && in_array(strtolower((string)parse_url($filteredTarget, PHP_URL_SCHEME)), ['http', 'https'], true)) ? $filteredTarget : $data['target_url'];
    if (!in_array($filtered['target_attribute'], ['', '_self', '_blank', '_parent', '_top'], true)) {
        $filtered['target_attribute'] = $data['target_attribute'];
    }
    $filtered = media_sanitize_projected_metadata($filtered, $data);
    foreach (['filename', 'mime', 'ext', 'size', 'width', 'height', 'visibility', 'storage_disk', 'access_scope', 'is_downloadable'] as $key) {
        $filtered[$key] = $data[$key];
    }
    $filtered['extensions'] = media_public_extensions($filtered['extensions']);
    return array_intersect_key($filtered, $data);
}

function media_mutation_metadata(PDO $pdo, string $operation, array $row, array $input, array $context): array
{
    $extensionInput = media_extension_input($input);
    $metadata = apply_filters('media_mutation_metadata', [
        'context' => media_extension_context($context),
        'extension_input' => $extensionInput,
    ], $operation, $row, $extensionInput, $pdo);
    if (!is_array($metadata)) throw new InvalidArgumentException('Invalid media mutation metadata.');
    try {
        $json = json_encode($metadata, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new InvalidArgumentException('Media mutation metadata must be JSON-serializable.', 0, $error);
    }
    if (strlen($json) > 65536) throw new InvalidArgumentException('Media mutation metadata is too large.');
    return $metadata;
}

function media_mutation_response(PDO $pdo, string $operation, array $row, array $context, array $response = [], array $metadata = []): array
{
    $context = media_extension_context($context);
    $canonical = [
        'ok' => true,
        'id' => (int)($row['id'] ?? 0),
        'media' => media_filter_data($pdo, $row, $context, true),
        'context' => $context,
        'extensions' => [],
    ];
    $base = array_replace($response, $canonical);
    $filtered = apply_filters('media_mutation_response', $base, $operation, $row, $context, $pdo, $metadata);
    if (!is_array($filtered) || array_is_list($filtered)) $filtered = $base;
    $filtered['ok'] = true;
    $filtered['id'] = $canonical['id'];
    $filtered['media'] = $canonical['media'];
    $filtered['context'] = $canonical['context'];
    $filtered['extensions'] = media_public_extensions($filtered['extensions'] ?? []);
    try {
        $json = json_encode($filtered, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return $base;
    }
    return strlen($json) <= 65536 ? $filtered : $base;
}

function media_find_authorized_duplicate(PDO $pdo, string $contentHash, int $actorUserId, bool $forUpdate = false): ?array
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $contentHash) !== 1 || $actorUserId <= 0) return null;
    $lock = $forUpdate && $pdo->inTransaction() && in_array((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true)
        ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT * FROM media WHERE content_hash = :content_hash AND is_deleted = 0 ORDER BY id ASC' . $lock);
    $stmt->execute([':content_hash' => $contentHash]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (is_array($row) && media_user_can_read($pdo, $actorUserId, $row)) return $row;
    }
    return null;
}

function media_create_before_publication(PDO $pdo, array $candidate, array $context, array $extensionInput): void
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Media create preflight requires an active transaction.');
    $keys = ['schema', 'filename', 'mime', 'ext', 'size', 'width', 'height', 'content_hash', 'url', 'visibility', 'storage_disk', 'access_scope', 'is_downloadable'];
    if (array_diff(array_keys($candidate), $keys) !== [] || array_diff($keys, array_keys($candidate)) !== []
        || ($candidate['schema'] ?? null) !== 2
        || !is_string($candidate['filename']) || $candidate['filename'] === '' || strlen($candidate['filename']) > 255
        || !is_string($candidate['mime']) || !str_starts_with($candidate['mime'], 'image/')
        || !is_string($candidate['ext']) || preg_match('/\A[a-z0-9]{1,10}\z/D', $candidate['ext']) !== 1
        || !is_int($candidate['size']) || $candidate['size'] < 0
        || ($candidate['width'] !== null && (!is_int($candidate['width']) || $candidate['width'] <= 0))
        || ($candidate['height'] !== null && (!is_int($candidate['height']) || $candidate['height'] <= 0))
        || !is_string($candidate['content_hash']) || preg_match('/\A[a-f0-9]{64}\z/D', $candidate['content_hash']) !== 1
        || !is_string($candidate['url']) || !media_projected_url_is_valid($candidate['url'], false)
        || !in_array($candidate['visibility'], ['public', 'private'], true)
        || !in_array($candidate['storage_disk'], ['public', 'private'], true)
        || !in_array($candidate['access_scope'], ['public', 'editorial', 'admin'], true)
        || !is_int($candidate['is_downloadable']) || !in_array($candidate['is_downloadable'], [0, 1], true)) {
        throw new InvalidArgumentException('Invalid media create candidate.');
    }
    $context = media_extension_context($context);
    if ($extensionInput !== media_extension_input(['media_extension' => $extensionInput])) {
        throw new InvalidArgumentException('Invalid media create extension input.');
    }
    do_action('media_create_before_publication', $candidate, $context, $extensionInput, new ResourceLifecycleDatabase($pdo));
    if (!$pdo->inTransaction()) throw new RuntimeException('Media create preflight changed transaction ownership.');
}

function media_resolve_featured(PDO $pdo, array $post, array $context = []): ?array
{
    $context = media_extension_context($context, [
        'surface' => 'frontend',
        'consumer' => 'post',
        'resource_id' => isset($post['id']) ? (int)$post['id'] : null,
        'field' => 'featured',
        'content_locale' => function_exists('get_locale') ? get_locale() : null,
    ]);
    $featured = null;
    $mediaId = (int)($post['thumbnail_media_id'] ?? 0);
    if ($mediaId > 0) {
        $row = media_load_live($pdo, $mediaId);
        if ($row !== null && media_client_url($row, false) !== null) {
            $featured = media_filter_data($pdo, $row, $context, false);
        }
    }
    if ($featured === null && $mediaId <= 0) {
        $url = trim((string)($post['thumbnail'] ?? ''));
        if ($url !== '' && !str_starts_with($url, '/private/')) {
            $featured = ['id' => null, 'url' => $url, 'title' => '', 'alt' => '', 'caption' => '', 'credit' => '', 'target_url' => '', 'target_attribute' => '', 'extensions' => []];
        }
    }
    $filtered = apply_filters('featured_media', $featured, $post, $context, $pdo);
    if ($filtered === null) return null;
    if (!is_array($filtered) || !is_string($filtered['url'] ?? null)) return $featured;
    $url = trim($filtered['url']);
    if (!media_projected_url_is_valid($url, false)) return $featured;

    $id = $filtered['id'] ?? null;
    if ($id !== null) {
        $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int)$id : 0);
        $selected = $id > 0 ? media_load_live($pdo, $id) : null;
        if ($selected === null || media_client_url($selected, false) === null) return $featured;
        $canonical = media_filter_data($pdo, $selected, $context, false);
        if ($url !== $canonical['url']) return $featured;
        $filtered = array_replace($canonical, array_intersect_key($filtered, array_flip(['title', 'alt', 'caption', 'credit', 'target_url', 'target_attribute', 'extensions'])));
    } else {
        $filtered = array_intersect_key($filtered, array_flip(['id', 'url', 'title', 'alt', 'caption', 'credit', 'target_url', 'target_attribute', 'extensions']));
        $filtered['id'] = null;
        $filtered['url'] = $url;
    }
    $filtered['extensions'] = media_public_extensions($filtered['extensions'] ?? []);
    return media_sanitize_projected_metadata($filtered, is_array($featured) ? $featured : []);
}

function media_youtube_thumbnail(mixed $url): ?string
{
    if (!is_string($url) || trim($url) === '') return null;
    foreach (['#youtu\.be/([A-Za-z0-9_-]+)#i', '#[?&]v=([A-Za-z0-9_-]+)#i', '#/(?:embed|v)/([A-Za-z0-9_-]+)#i'] as $pattern) {
        if (preg_match($pattern, $url, $match) === 1) return 'https://img.youtube.com/vi/' . $match[1] . '/hqdefault.jpg';
    }
    return null;
}

function media_post_display_url(array $post): ?string
{
    $youtube = media_youtube_thumbnail($post['youtube'] ?? null);
    if ($youtube !== null) return $youtube;
    if (array_key_exists('display_image', $post)) {
        return is_string($post['display_image']) && trim($post['display_image']) !== '' ? trim($post['display_image']) : null;
    }
    $thumbnail = $post['thumbnail'] ?? null;
    return is_string($thumbnail) && trim($thumbnail) !== '' && !str_starts_with(trim($thumbnail), '/private/')
        ? trim($thumbnail) : null;
}

function media_post_image_alt(array $post, string $legacyFallback = ''): string
{
    if (array_key_exists('featured_media', $post)) {
        return is_array($post['featured_media']) && is_string($post['featured_media']['alt'] ?? null)
            ? $post['featured_media']['alt'] : '';
    }
    return $legacyFallback;
}

function media_post_image_caption(array $post): string
{
    return is_array($post['featured_media'] ?? null) && is_string($post['featured_media']['caption'] ?? null)
        ? $post['featured_media']['caption'] : '';
}

function media_normalize_featured_posts(PDO $pdo, array &$posts, array $context = []): void
{
    foreach ($posts as &$post) {
        if (!is_array($post)) continue;
        $featured = media_resolve_featured($pdo, $post, $context);
        $post['featured_media'] = $featured;
        $post['featured_media_suppressed'] = $featured === null;
        if ($featured === null) $post['thumbnail'] = null;
        $post['display_image'] = media_youtube_thumbnail($post['youtube'] ?? null) ?? ($featured['url'] ?? null);
        $post['display_image_target_url'] = $featured['target_url'] ?? null;
        $post['display_image_target_attribute'] = $featured['target_attribute'] ?? null;
    }
    unset($post);
}

function media_filter_authorized_rows(array $authorizedRows, mixed $filteredRows): array
{
    if (!is_array($filteredRows) || !array_is_list($filteredRows)) return $authorizedRows;
    $authorized = [];
    foreach ($authorizedRows as $row) {
        if (is_array($row) && (int)($row['id'] ?? 0) > 0) $authorized[(int)$row['id']] = $row;
    }
    $protected = ['id', 'user_id', 'is_deleted', 'visibility', 'storage_disk', 'storage_path', 'access_scope', 'is_downloadable', 'url'];
    $result = [];
    $seen = [];
    foreach ($filteredRows as $row) {
        if (!is_array($row)) continue;
        $id = (int)($row['id'] ?? 0);
        if (!isset($authorized[$id]) || isset($seen[$id])) continue;
        foreach ($protected as $key) {
            if (array_key_exists($key, $authorized[$id])) $row[$key] = $authorized[$id][$key];
            else unset($row[$key]);
        }
        $result[] = $row;
        $seen[$id] = true;
    }
    return $result;
}

function media_validate_featured_selection(PDO $pdo, mixed $id, ?string $url, int $actorUserId, bool $forUpdate = false): ?int
{
    $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int)$id : 0);
    if ($id <= 0 || $url === null || trim($url) === '') return null;
    $row = media_load_live($pdo, $id, $forUpdate);
    if ($row === null || !media_user_can_read($pdo, $actorUserId, $row)) return null;
    $clientUrl = media_client_url($row, true);
    return in_array(trim($url), array_filter([$clientUrl, trim((string)($row['url'] ?? ''))]), true) ? $id : null;
}
