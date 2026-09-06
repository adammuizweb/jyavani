<?php
declare(strict_types=1);

if (!function_exists('adiwira_bin_record_audit')) {
    function adiwira_bin_record_audit(PDO $pdo, string $resource, int $resourceId, int $actorId, string $event, array $metadata = []): int
    {
        if (!authorization_audit($pdo, $event, $actorId, null, $resource, (string)$resourceId, $metadata)) {
            throw new RuntimeException('Failed to audit reversible ' . $resource . ' mutation.');
        }
        $auditId = (int)$pdo->lastInsertId();
        if ($auditId <= 0) {
            throw new RuntimeException('Reversible mutation audit ID unavailable.');
        }
        return $auditId;
    }
}

if (!function_exists('adiwira_bin_latest_audit_id')) {
    function adiwira_bin_latest_audit_id(PDO $pdo, string $resource, int $resourceId): int
    {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM authorization_audit_log
             WHERE resource_type = :resource_type AND resource_id = :resource_id
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':resource_type' => $resource, ':resource_id' => (string)$resourceId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('adiwira_bin_issue_trash_undo')) {
    function adiwira_bin_issue_trash_undo(PDO $pdo, string $resource, int $actorId, array $items): ?array
    {
        if (!in_array($resource, ['article', 'page', 'theme', 'category'], true)
            || $actorId <= 0 || $items === [] || count($items) > 100) {
            return null;
        }
        $firstId = (int)($items[0]['id'] ?? 0);
        if ($firstId <= 0) return null;

        $ids = array_values(array_unique(array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items)));
        if (count($ids) !== count($items) || in_array(0, $ids, true)) return null;
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($resource === 'category') {
            $stmt = $pdo->prepare("SELECT id, created_by FROM categories WHERE id IN ($in) AND is_deleted = 1");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $permission = 'core.categories.restore';
        } else {
            $permission = match ($resource) {
                'article' => 'core.posts.restore',
                'page' => 'core.pages.restore',
                default => 'core.theme_content.restore',
            };
            $stmt = $pdo->prepare("SELECT id, created_by, status FROM posts WHERE id IN ($in) AND type = ? AND is_deleted = 1");
            $stmt->execute(array_merge($ids, [$resource]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if (count($rows) !== count($ids)) return null;
        foreach ($rows as $row) {
            $context = ['owner_id' => (int)($row['created_by'] ?? 0)];
            if (!user_can($pdo, $actorId, $permission, $context)) return null;
            if (($resource === 'article' || $resource === 'page') && (string)($row['status'] ?? '') !== 'draft') {
                $publishPermission = $resource === 'article' ? 'core.posts.publish' : 'core.pages.publish';
                if (!user_can($pdo, $actorId, $publishPermission, $context)) return null;
            }
        }

        $relationshipCount = 0;
        foreach ($items as $item) $relationshipCount += count((array)($item['category_ids'] ?? []));
        if ($relationshipCount > 1000) return null;

        $token = adiwira_undo_issue('bin.trash.' . $resource, $firstId, $actorId, [
            'resource' => $resource,
            'items' => array_values($items),
        ]);
        if ($token === null) return null;

        return [
            'label' => __('Undo'),
            'request' => [
                'url' => ADMIN_BASE_PATH . '/admin/bin/undo_trash.php',
                'body' => [
                    'csrf_token' => csrf_token(),
                    'undo_token' => $token,
                    'resource' => $resource,
                ],
                'errorMessage' => __('Failed to undo move to trash.'),
            ],
        ];
    }
}

if (!function_exists('adiwira_bin_parse_undo_items')) {
    function adiwira_bin_parse_undo_items(mixed $rawItems, bool $withParent = false, bool $withCategories = false): array
    {
        if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > 100) return [];
        $items = [];
        $relationshipCount = 0;
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) return [];
            $id = (int)($rawItem['id'] ?? 0);
            $auditId = (int)($rawItem['audit_id'] ?? 0);
            if ($id <= 0 || $auditId <= 0 || isset($items[$id])) return [];
            $items[$id] = ['id' => $id, 'audit_id' => $auditId];
            if ($withParent) {
                $parentId = $rawItem['parent_id'] ?? null;
                $items[$id]['parent_id'] = $parentId === null ? null : (int)$parentId;
            }
            if ($withCategories) {
                if (!is_array($rawItem['category_ids'] ?? null)) return [];
                $categoryIds = array_map('intval', $rawItem['category_ids']);
                if (in_array(0, $categoryIds, true) || count(array_unique($categoryIds)) !== count($categoryIds)) return [];
                sort($categoryIds, SORT_NUMERIC);
                $relationshipCount += count($categoryIds);
                if ($relationshipCount > 1000) return [];
                $items[$id]['category_ids'] = $categoryIds;
            }
        }
        ksort($items, SORT_NUMERIC);
        return $items;
    }
}

if (!function_exists('adiwira_bin_post_category_map')) {
    function adiwira_bin_post_category_map(PDO $pdo, array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn(int $id): bool => $id > 0)));
        sort($postIds, SORT_NUMERIC);
        if ($postIds === [] || count($postIds) > 100) return [];
        $map = array_fill_keys($postIds, []);
        $in = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $pdo->prepare("SELECT post_id, category_id FROM post_categories WHERE post_id IN ($in) ORDER BY post_id, category_id FOR UPDATE");
        $stmt->execute($postIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $postId = (int)$row['post_id'];
            if (isset($map[$postId])) $map[$postId][] = (int)$row['category_id'];
        }
        return $map;
    }
}

if (!function_exists('adiwira_content_parse_status_undo_items')) {
    function adiwira_content_parse_status_undo_items(mixed $rawItems): array
    {
        if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > 100) return [];
        $allowed = ['draft', 'published', 'private'];
        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) return [];
            $id = (int)($rawItem['id'] ?? 0);
            $auditId = (int)($rawItem['audit_id'] ?? 0);
            $previousStatus = (string)($rawItem['previous_status'] ?? '');
            $changedStatus = (string)($rawItem['changed_status'] ?? '');
            $statusRevision = (int)($rawItem['status_revision'] ?? -1);
            if ($id <= 0 || $auditId <= 0 || $statusRevision <= 0 || isset($items[$id])
                || !in_array($previousStatus, $allowed, true)
                || !in_array($changedStatus, $allowed, true)
                || $previousStatus === $changedStatus) {
                return [];
            }
            $items[$id] = [
                'id' => $id,
                'audit_id' => $auditId,
                'previous_status' => $previousStatus,
                'changed_status' => $changedStatus,
                'status_revision' => $statusRevision,
            ];
        }
        ksort($items, SORT_NUMERIC);
        return $items;
    }
}

if (!function_exists('adiwira_content_issue_status_undo')) {
    function adiwira_content_issue_status_undo(PDO $pdo, string $resource, int $actorId, array $items): ?array
    {
        $itemsById = adiwira_content_parse_status_undo_items($items);
        if (!in_array($resource, ['article', 'page', 'theme'], true) || $actorId <= 0 || $itemsById === []) return null;

        $ids = array_keys($itemsById);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, status, status_revision, created_by FROM posts WHERE id IN ($in) AND type = ? AND is_deleted = 0");
        $stmt->execute(array_merge($ids, [$resource]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== count($ids)) return null;

        $updatePermission = match ($resource) {
            'article' => 'core.posts.update',
            'page' => 'core.pages.update',
            default => 'core.theme_content.update',
        };
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $item = $itemsById[$id] ?? null;
            $context = ['owner_id' => (int)($row['created_by'] ?? 0)];
            if (!$item || (string)$row['status'] !== $item['changed_status']
                || (int)$row['status_revision'] !== $item['status_revision']
                || !user_can($pdo, $actorId, $updatePermission, $context)) {
                return null;
            }
            if (($resource === 'article' || $resource === 'page')
                && ($item['previous_status'] !== 'draft' || $item['changed_status'] !== 'draft')) {
                $publishPermission = $resource === 'article' ? 'core.posts.publish' : 'core.pages.publish';
                if (!user_can($pdo, $actorId, $publishPermission, $context)) return null;
            }
        }

        $token = adiwira_undo_issue('content.bulk_status.' . $resource, $ids[0], $actorId, [
            'resource' => $resource,
            'items' => array_values($itemsById),
        ]);
        if ($token === null) return null;

        return [
            'label' => __('Undo'),
            'request' => [
                'url' => ADMIN_BASE_PATH . '/admin/content/undo_bulk_status.php',
                'body' => [
                    'csrf_token' => csrf_token(),
                    'undo_token' => $token,
                    'resource' => $resource,
                ],
                'errorMessage' => __('Failed to undo status change.'),
            ],
        ];
    }
}
