<?php
declare(strict_types=1);

/** Build the bounded context exposed to Media and File detail actions. */
function asset_detail_action_context(
    string $resource,
    string $surface,
    array $row,
    string $clientUrl,
    int $actorId
): array {
    $allowedSurfaces = [
        'media' => ['admin.media.detail', 'admin.media.modal.detail'],
        'file' => ['admin.file.detail', 'admin.file.modal.detail'],
    ];
    if (!isset($allowedSurfaces[$resource]) || !in_array($surface, $allowedSurfaces[$resource], true)) {
        throw new InvalidArgumentException('Invalid asset detail action context.');
    }

    $visibility = strtolower(trim((string)($row['visibility'] ?? 'public'))) ?: 'public';
    $storageDisk = strtolower(trim((string)($row['storage_disk'] ?? 'public'))) ?: 'public';
    $accessScope = strtolower(trim((string)($row['access_scope'] ?? 'public'))) ?: 'public';
    $isPublic = $visibility === 'public' && $storageDisk === 'public' && $accessScope === 'public';
    $publicUrl = $isPublic ? content_row_actions_public_url($clientUrl) : null;

    $filename = trim((string)($row['filename'] ?? ''));
    $title = trim((string)($row['title'] ?? ''));
    if (strlen($filename) > 255 || preg_match('/[\x00-\x1F\x7F]/', $filename)) $filename = '';
    if (strlen($title) > 255 || preg_match('/[\x00-\x1F\x7F]/', $title)) $title = '';

    return [
        'schema' => 1,
        'resource' => $resource,
        'surface' => $surface,
        'id' => max(0, (int)($row['id'] ?? 0)),
        'title' => $title,
        'filename' => $filename,
        'public_url' => $publicUrl,
        'visibility' => $visibility,
        'storage_disk' => $storageDisk,
        'access_scope' => $accessScope,
        'is_public' => $isPublic && $publicUrl !== null,
        'actor_id' => max(0, $actorId),
    ];
}

/** Render validated non-destructive extension actions for an asset detail surface. */
function asset_detail_actions_render(PDO $pdo, array $context): string
{
    if (!function_exists('apply_filters') || !defined('ADMIN_BASE_PATH')) return '';

    $items = apply_filters('admin_asset_detail_actions', [], $context, $pdo);
    if (!is_array($items) || !array_is_list($items)) return '';

    $basePath = rtrim((string)ADMIN_BASE_PATH, '/');
    $rendered = [];
    $keys = [];
    foreach ($items as $item) {
        if (count($rendered) >= 8) break;
        if (!is_array($item)) continue;
        $key = trim((string)($item['key'] ?? ''));
        $label = trim((string)($item['label'] ?? ''));
        $url = trim((string)($item['url'] ?? ''));
        $title = trim((string)($item['title'] ?? ''));
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/', $key) !== 1 || isset($keys[$key])
            || $label === '' || strlen($label) > 80 || preg_match('/[\x00-\x1F\x7F]/', $label)
            || $url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $url)
            || $title !== '' && (strlen($title) > 160 || preg_match('/[\x00-\x1F\x7F]/', $title))) {
            continue;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || ($parts['path'] ?? '') !== $basePath . '/' || !isset($parts['query'])) {
            continue;
        }
        parse_str((string)$parts['query'], $query);
        $page = is_string($query['page'] ?? null) ? trim($query['page'], '/') : '';
        if (preg_match('/\A[a-z0-9_-]+(?:\/[a-z0-9_-]+)*\z/', $page) !== 1) continue;

        $keys[$key] = true;
        $attributes = ' class="asset-detail-open asset-detail-extension-action" href="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
        if ($title !== '') $attributes .= ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
        $rendered[] = '<a' . $attributes . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    return implode('', $rendered);
}
