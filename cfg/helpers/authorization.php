<?php
declare(strict_types=1);

if (!function_exists('authorization_permission_key_is_valid')) {
    function authorization_permission_key_is_valid(string $permission): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{2,190}$/', $permission) === 1;
    }
}

if (!function_exists('authorization_roles')) {
    function authorization_roles(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                'SELECT id, slug, name, description, authority_rank, is_system
                 FROM roles
                 ORDER BY authority_rank DESC, name ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['id'] = (int)$row['id'];
                $row['authority_rank'] = (int)$row['authority_rank'];
                $row['is_system'] = (int)$row['is_system'] === 1;
            }
            unset($row);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('authorization_actor')) {
    function authorization_actor(PDO $pdo, ?int $userId = null): ?array
    {
        $userId ??= (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        try {
            $lockingRead = $pdo->inTransaction() ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare(
                'SELECT id, email, username, name, role AS legacy_role, is_site_owner, is_deleted, is_locked
                 FROM users
                 WHERE id = :id
                 LIMIT 1' . $lockingRead
            );
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || (int)$user['is_deleted'] === 1 || (int)$user['is_locked'] === 1) {
                return null;
            }

            $rolesStmt = $pdo->prepare(
                'SELECT r.id, r.slug, r.name, r.authority_rank
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = :user_id
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY r.authority_rank DESC, r.id ASC' . $lockingRead
            );
            $rolesStmt->execute([':user_id' => $userId]);

            return [
                'id' => (int)$user['id'],
                'email' => (string)$user['email'],
                'username' => (string)($user['username'] ?? ''),
                'name' => (string)($user['name'] ?? ''),
                'legacy_role' => (string)($user['legacy_role'] ?? ''),
                'is_site_owner' => (int)$user['is_site_owner'] === 1,
                'roles' => $rolesStmt->fetchAll(PDO::FETCH_ASSOC),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('authorization_active_legacy_role')) {
    function authorization_active_legacy_role(PDO $pdo, int $userId): string
    {
        if ($userId <= 0) {
            return 'none';
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT r.slug
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 JOIN users u ON u.id = ur.user_id
                 WHERE ur.user_id = :user_id
                   AND u.is_deleted = 0
                   AND u.is_locked = 0
                   AND r.slug IN ('author','editor','admin')
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY r.authority_rank DESC, r.id ASC
                 LIMIT 1"
            );
            $stmt->execute([':user_id' => $userId]);
            return (string)($stmt->fetchColumn() ?: 'none');
        } catch (Throwable $e) {
            return 'none';
        }
    }
}

if (!function_exists('authorization_lock_site_owner_actor')) {
    function authorization_lock_site_owner_actor(PDO $pdo, int $actorUserId): bool
    {
        if (!$pdo->inTransaction() || $actorUserId <= 0) {
            return false;
        }
        try {
            $activeOwnerIds = array_map('intval', $pdo->query(
                'SELECT id FROM users
                 WHERE is_site_owner = 1 AND is_deleted = 0 AND is_locked = 0
                 FOR UPDATE'
            )->fetchAll(PDO::FETCH_COLUMN));
            return in_array($actorUserId, $activeOwnerIds, true);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('authorization_permission')) {
    function authorization_permission(PDO $pdo, string $permission): ?array
    {
        $permission = strtolower(trim($permission));
        if (!authorization_permission_key_is_valid($permission)) {
            return null;
        }

        try {
            $lockingRead = $pdo->inTransaction() ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare(
                'SELECT permission_key, provider, resource, action, label, supports_scope, is_delegable
                 FROM permissions
                 WHERE permission_key = :permission AND is_active = 1
                 LIMIT 1' . $lockingRead
            );
            $stmt->execute([':permission' => $permission]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('authorization_permission_grants')) {
    function authorization_permission_grants(PDO $pdo, int $userId, string $permission): array
    {
        try {
            $lockingRead = $pdo->inTransaction() ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare(
                'SELECT rp.scope, r.id AS role_id, r.slug AS role_slug, r.authority_rank, r.is_system
                  FROM user_roles ur
                  JOIN roles r ON r.id = ur.role_id
                  JOIN role_permissions rp ON rp.role_id = r.id
                  JOIN permissions p ON p.permission_key = rp.permission_key
                  WHERE ur.user_id = :user_id
                    AND rp.permission_key = :permission
                    AND (p.is_delegable = 1 OR r.is_system = 1)
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY r.authority_rank DESC, r.id ASC' . $lockingRead
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':permission' => $permission,
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('authorization_lock_actor_permissions')) {
    function authorization_lock_actor_permissions(PDO $pdo, int $userId): bool
    {
        if (!$pdo->inTransaction() || $userId <= 0) return false;
        try {
            $userStmt = $pdo->prepare(
                'SELECT id FROM users
                 WHERE id = :id AND is_deleted = 0 AND is_locked = 0
                 FOR UPDATE'
            );
            $userStmt->execute([':id' => $userId]);
            if (!$userStmt->fetchColumn()) return false;

            $roleStmt = $pdo->prepare(
                'SELECT r.id
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = :user_id
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY r.id
                 FOR UPDATE'
            );
            $roleStmt->execute([':user_id' => $userId]);
            $roleStmt->fetchAll(PDO::FETCH_COLUMN);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('authorization_lock_owner_contexts')) {
    function authorization_lock_owner_contexts(PDO $pdo, array $ownerIds): bool
    {
        if (!$pdo->inTransaction()) return false;
        $ownerIds = array_values(array_unique(array_filter(array_map('intval', $ownerIds), static fn(int $id): bool => $id > 0)));
        if ($ownerIds === []) return true;
        $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
        try {
            $users = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) ORDER BY id FOR UPDATE");
            $users->execute($ownerIds);
            $users->fetchAll(PDO::FETCH_COLUMN);

            $roles = $pdo->prepare(
                "SELECT ur.user_id, r.id
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id IN ($placeholders)
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY ur.user_id, r.id
                 FOR UPDATE"
            );
            $roles->execute($ownerIds);
            $roles->fetchAll(PDO::FETCH_ASSOC);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('authorization_owner_rank')) {
    function authorization_owner_rank(PDO $pdo, int $ownerId): ?int
    {
        if ($ownerId <= 0) {
            return null;
        }

        try {
            $lockingRead = $pdo->inTransaction() ? ' FOR UPDATE' : '';
            $userStmt = $pdo->prepare(
                'SELECT is_site_owner
                 FROM users
                 WHERE id = :id AND is_deleted = 0
                 LIMIT 1' . $lockingRead
            );
            $userStmt->execute([':id' => $ownerId]);
            $isSiteOwner = $userStmt->fetchColumn();
            if ($isSiteOwner === false || (int)$isSiteOwner === 1) {
                return null;
            }

            $roleStmt = $pdo->prepare(
                'SELECT r.authority_rank
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = :id
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY r.authority_rank DESC, r.id ASC' . $lockingRead
            );
            $roleStmt->execute([':id' => $ownerId]);
            $ranks = array_map('intval', $roleStmt->fetchAll(PDO::FETCH_COLUMN));
            return $ranks === [] ? 0 : max($ranks);
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('authorization_scope_strength')) {
    function authorization_scope_strength(string $scope): int
    {
        return match ($scope) {
            'global' => 10,
            'own' => 20,
            'same_or_lower' => 30,
            'any' => 40,
            default => 0,
        };
    }
}

if (!function_exists('user_permission_scope')) {
    function user_permission_scope(PDO $pdo, int $userId, string $permission): ?string
    {
        $permissionRow = authorization_permission($pdo, $permission);
        $actor = authorization_actor($pdo, $userId);
        if ($permissionRow === null || $actor === null) {
            return null;
        }

        if ($actor['is_site_owner'] === true) {
            return (int)$permissionRow['supports_scope'] === 1 ? 'any' : 'global';
        }

        $best = null;
        foreach (authorization_permission_grants($pdo, $userId, $permission) as $grant) {
            $scope = (string)($grant['scope'] ?? '');
            if ($best === null || authorization_scope_strength($scope) > authorization_scope_strength($best)) {
                $best = $scope;
            }
        }
        return $best;
    }
}

if (!function_exists('current_user_permission_scope')) {
    function current_user_permission_scope(PDO $pdo, string $permission): ?string
    {
        return user_permission_scope($pdo, (int)($_SESSION['user_id'] ?? 0), $permission);
    }
}

if (!function_exists('authorization_owner_scope_condition')) {
    function authorization_owner_scope_condition(
        PDO $pdo,
        int $userId,
        string $permission,
        string $ownerColumn,
        string $parameterPrefix = 'authz'
    ): ?array {
        if ($userId <= 0 || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $ownerColumn) !== 1) {
            return null;
        }

        $parameterPrefix = preg_replace('/[^A-Za-z0-9_]/', '_', $parameterPrefix) ?: 'authz';
        $scope = user_permission_scope($pdo, $userId, $permission);
        if ($scope === null) {
            return null;
        }
        if ($scope === 'any') {
            return ['sql' => '1=1', 'params' => [], 'scope' => $scope];
        }
        if ($scope === 'own') {
            $actorParam = ':' . $parameterPrefix . '_actor_id';
            return [
                'sql' => $ownerColumn . ' = ' . $actorParam,
                'params' => [$actorParam => $userId],
                'scope' => $scope,
            ];
        }
        if ($scope !== 'same_or_lower') {
            return null;
        }

        $authorityRank = null;
        foreach (authorization_permission_grants($pdo, $userId, $permission) as $grant) {
            if ((string)($grant['scope'] ?? '') !== 'same_or_lower') {
                continue;
            }
            $rank = (int)($grant['authority_rank'] ?? 0);
            $authorityRank = $authorityRank === null ? $rank : max($authorityRank, $rank);
        }
        if ($authorityRank === null) {
            return null;
        }

        $actorParam = ':' . $parameterPrefix . '_actor_id';
        $rankParam = ':' . $parameterPrefix . '_authority_rank';
        $ownerAlias = $parameterPrefix . '_owner';
        $roleAlias = $parameterPrefix . '_role';
        $assignmentAlias = $parameterPrefix . '_assignment';

        return [
            'sql' => "($ownerColumn = $actorParam OR EXISTS (
                SELECT 1
                FROM users $ownerAlias
                WHERE $ownerAlias.id = $ownerColumn
                  AND $ownerAlias.is_deleted = 0
                  AND $ownerAlias.is_site_owner = 0
                  AND COALESCE((
                    SELECT MAX($roleAlias.authority_rank)
                    FROM user_roles $assignmentAlias
                    JOIN roles $roleAlias ON $roleAlias.id = $assignmentAlias.role_id
                    WHERE $assignmentAlias.user_id = $ownerAlias.id
                      AND ($assignmentAlias.expires_at IS NULL OR $assignmentAlias.expires_at > NOW())
                  ), 0) <= CAST($rankParam AS SIGNED)
            ))",
            'params' => [
                $actorParam => $userId,
                $rankParam => $authorityRank,
            ],
            'scope' => $scope,
        ];
    }
}

if (!function_exists('user_can')) {
    function user_can(PDO $pdo, int $userId, string $permission, array $context = []): bool
    {
        $permission = strtolower(trim($permission));
        $permissionRow = authorization_permission($pdo, $permission);
        $actor = authorization_actor($pdo, $userId);
        if ($permissionRow === null || $actor === null) {
            return false;
        }

        if ($actor['is_site_owner'] === true) {
            return true;
        }

        $grants = authorization_permission_grants($pdo, $userId, $permission);
        if ($grants === []) {
            return false;
        }

        if ((int)$permissionRow['supports_scope'] !== 1) {
            return true;
        }

        $hasOwnerContext = array_key_exists('owner_id', $context);
        if (!$hasOwnerContext) {
            foreach ($grants as $grant) {
                if ((string)($grant['scope'] ?? '') === 'any') {
                    return true;
                }
            }
            return false;
        }

        $ownerId = (int)$context['owner_id'];
        foreach ($grants as $grant) {
            $scope = (string)($grant['scope'] ?? '');
            if ($scope === 'any') {
                return true;
            }
            if ($ownerId > 0 && $ownerId === $userId && in_array($scope, ['own', 'same_or_lower'], true)) {
                return true;
            }
            if ($scope !== 'same_or_lower' || $ownerId <= 0) {
                continue;
            }

            $ownerRank = authorization_owner_rank($pdo, $ownerId);
            if ($ownerRank !== null && $ownerRank <= (int)$grant['authority_rank']) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(PDO $pdo, string $permission, array $context = []): bool
    {
        return user_can($pdo, (int)($_SESSION['user_id'] ?? 0), $permission, $context);
    }
}

if (!function_exists('authorization_editor_context')) {
    function authorization_editor_context(
        PDO $pdo,
        int $userId,
        int $ownerId,
        string $readPermission,
        string $updatePermission,
        array $context = []
    ): ?array {
        $ownerContext = ['owner_id' => $ownerId];
        if (!user_can($pdo, $userId, $readPermission, $ownerContext)) {
            return null;
        }

        $canUpdate = user_can($pdo, $userId, $updatePermission, $ownerContext);
        return array_replace($context, [
            'owner_id' => $ownerId,
            'can_read' => true,
            'can_update' => $canUpdate,
            'read_only' => !$canUpdate,
        ]);
    }
}

if (!function_exists('authorization_actor_can_assign_roles')) {
    function authorization_actor_can_assign_roles(PDO $pdo, int $actorUserId, array $roleIds): bool
    {
        $actor = authorization_actor($pdo, $actorUserId);
        if ($actor === null || $actor['is_site_owner'] !== true) {
            return false;
        }
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), static fn(int $id): bool => $id > 0)));
        if ($roleIds === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE id IN ($placeholders)");
            $stmt->execute($roleIds);
            return (int)$stmt->fetchColumn() === count($roleIds);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('authorization_change_user_status')) {
    function authorization_change_user_status(
        PDO $pdo,
        int $targetUserId,
        string $action,
        ?int $actorUserId = null,
        ?string $auditEvent = null,
        ?string $requiredPermission = null,
        ?int &$auditId = null
    ): string {
        $auditId = null;
        if ($targetUserId <= 0 || !in_array($action, ['delete', 'lock', 'unlock'], true)) {
            return 'invalid';
        }

        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'authorization_change_user_status';
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }
            $activeOwners = array_map('intval', $pdo->query(
                'SELECT id
                 FROM users
                 WHERE is_site_owner = 1 AND is_deleted = 0 AND is_locked = 0
                 FOR UPDATE'
            )->fetchAll(PDO::FETCH_COLUMN));

            $targetStmt = $pdo->prepare(
                'SELECT id, is_site_owner, is_deleted, is_locked
                 FROM users
                 WHERE id = :id
                 FOR UPDATE'
            );
            $targetStmt->execute([':id' => $targetUserId]);
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                return 'missing';
            }

            if ($action === 'delete' && (int)$target['is_deleted'] === 1) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                return 'missing';
            }

            if ($requiredPermission !== null
                && ($actorUserId === null || !user_can($pdo, $actorUserId, $requiredPermission, ['owner_id' => $targetUserId]))) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                return 'forbidden';
            }

            if ((int)$target['is_site_owner'] === 1 && !in_array((int)$actorUserId, $activeOwners, true)) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                return 'site_owner_required';
            }

            $deactivates = $action === 'delete' || $action === 'lock';
            $targetIsActiveOwner = (int)$target['is_site_owner'] === 1
                && (int)$target['is_deleted'] === 0
                && (int)$target['is_locked'] === 0;
            if ($deactivates && $targetIsActiveOwner && count($activeOwners) <= 1) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                return 'last_site_owner';
            }

            if ($action === 'delete') {
                $update = $pdo->prepare('UPDATE users SET is_deleted = 1, updated_at = NOW() WHERE id = :id');
            } elseif ($action === 'lock') {
                $update = $pdo->prepare('UPDATE users SET is_locked = 1, updated_at = NOW() WHERE id = :id');
            } else {
                $update = $pdo->prepare('UPDATE users SET is_locked = 0, updated_at = NOW() WHERE id = :id');
            }
            $update->execute([':id' => $targetUserId]);

            $event = $auditEvent ?? match ($action) {
                'delete' => 'user.deleted',
                'lock' => 'user.locked',
                default => 'user.unlocked',
            };
            if (!authorization_audit($pdo, $event, $actorUserId, $targetUserId, 'user', (string)$targetUserId)) {
                throw new RuntimeException('Authorization audit failed.');
            }
            $auditId = (int)$pdo->lastInsertId();
            if ($auditId <= 0) {
                throw new RuntimeException('Authorization audit ID unavailable.');
            }

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return 'ok';
        } catch (Throwable $e) {
            $auditId = null;
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownsTransaction && $pdo->inTransaction()) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (Throwable $ignored) {
                }
            }
            return 'error';
        }
    }
}

if (!function_exists('authorization_assign_roles')) {
    function authorization_assign_roles(
        PDO $pdo,
        int $userId,
        array $roleIds,
        ?int $assignedBy = null,
        ?array &$roleChange = null
    ): bool
    {
        $roleChange = null;
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), static fn(int $id): bool => $id > 0)));
        sort($roleIds);
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'authorization_assign_roles';

        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }
            $lock = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $target = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1' . $lock);
            $target->execute([':id' => $userId]);
            if (!$target->fetchColumn()) {
                throw new RuntimeException('Target user not found.');
            }

            $validIds = [];
            if ($roleIds !== []) {
                $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
                $valid = $pdo->prepare("SELECT id FROM roles WHERE id IN ($placeholders) ORDER BY id" . $lock);
                $valid->execute($roleIds);
                $validIds = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));
                if ($validIds !== $roleIds) {
                    throw new RuntimeException('Unknown role selected.');
                }
            }

            if ($assignedBy !== null && $assignedBy > 0) {
                $assigner = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
                $assigner->execute([':id' => $assignedBy]);
                if (!$assigner->fetchColumn()) {
                    throw new RuntimeException('Assigning user not found.');
                }
            } else {
                $assignedBy = null;
            }

            $existingStmt = $pdo->prepare(
                'SELECT role_id,
                        CASE WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 1 ELSE 0 END AS is_expired
                 FROM user_roles
                 WHERE user_id = :user_id
                 ORDER BY role_id' . $lock
            );
            $existingStmt->execute([':user_id' => $userId]);
            $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
            $existingIds = array_map('intval', array_column($existingRows, 'role_id'));
            $oldRoleIds = [];
            $expiredIds = [];
            foreach ($existingRows as $existingRow) {
                if ((int)$existingRow['is_expired'] === 1) {
                    $expiredIds[] = (int)$existingRow['role_id'];
                } else {
                    $oldRoleIds[] = (int)$existingRow['role_id'];
                }
            }
            sort($oldRoleIds);
            $removeIds = array_values(array_diff($existingIds, $validIds));
            $addIds = array_values(array_diff($validIds, $existingIds));
            $reactivateIds = array_values(array_intersect($validIds, $expiredIds));

            if ($removeIds !== []) {
                $removePlaceholders = implode(',', array_fill(0, count($removeIds), '?'));
                $delete = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id IN ($removePlaceholders)");
                $delete->execute(array_merge([$userId], $removeIds));
            }

            if ($addIds !== []) {
                $insert = $pdo->prepare(
                    'INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (:user_id, :role_id, :assigned_by)'
                );
                foreach ($addIds as $roleId) {
                    $insert->execute([
                        ':user_id' => $userId,
                        ':role_id' => $roleId,
                        ':assigned_by' => $assignedBy,
                    ]);
                }
            }

            if ($reactivateIds !== []) {
                $reactivatePlaceholders = implode(',', array_fill(0, count($reactivateIds), '?'));
                $reactivate = $pdo->prepare(
                    "UPDATE user_roles
                     SET expires_at = NULL, assigned_by = ?, assigned_at = NOW()
                     WHERE user_id = ? AND role_id IN ($reactivatePlaceholders)"
                );
                $reactivate->execute(array_merge([$assignedBy, $userId], $reactivateIds));
            }

            $legacyRole = 'none';
            $legacy = $pdo->prepare(
                "SELECT r.slug
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = :user_id
                   AND r.slug IN ('author','editor','admin')
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY r.authority_rank DESC, r.id ASC
                 LIMIT 1"
            );
            $legacy->execute([':user_id' => $userId]);
            $legacyRole = (string)($legacy->fetchColumn() ?: 'none');
            $legacyUpdate = $pdo->prepare('UPDATE users SET role = :role, updated_at = NOW() WHERE id = :id');
            $legacyUpdate->execute([':role' => $legacyRole, ':id' => $userId]);

            $finalStmt = $pdo->prepare(
                'SELECT role_id FROM user_roles
                 WHERE user_id = :user_id
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY role_id' . $lock
            );
            $finalStmt->execute([':user_id' => $userId]);
            $finalRoleIds = array_map('intval', $finalStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($oldRoleIds !== $finalRoleIds) {
                $roleChange = [
                    'user_id' => $userId,
                    'old_role_ids' => $oldRoleIds,
                    'new_role_ids' => $finalRoleIds,
                    'actor_id' => $assignedBy,
                ];
            }

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            if ($ownsTransaction && $roleChange !== null && function_exists('do_action')) {
                // authorization_user_roles_changed(user ID, old role IDs, new role IDs, actor ID, PDO)
                try {
                    do_action('authorization_user_roles_changed', $userId, $roleChange['old_role_ids'], $roleChange['new_role_ids'], $assignedBy, $pdo);
                } catch (Throwable $hookError) {
                    error_log('[authorization_user_roles_changed] ' . $hookError->getMessage());
                }
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownsTransaction && $pdo->inTransaction()) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (Throwable $ignored) {
                }
            }
            return false;
        }
    }
}

if (!function_exists('authorization_assign_legacy_role')) {
    function authorization_assign_legacy_role(
        PDO $pdo,
        int $userId,
        string $legacyRole,
        ?int $assignedBy = null,
        ?array &$roleChange = null
    ): bool {
        $roleChange = null;
        $legacyRole = strtolower(trim($legacyRole));
        if (!in_array($legacyRole, ['none', 'author', 'editor', 'admin'], true)) {
            return false;
        }
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'authorization_assign_legacy_role';
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }
            $lock = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $target = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1' . $lock);
            $target->execute([':id' => $userId]);
            if (!$target->fetchColumn()) {
                throw new RuntimeException('Target user not found.');
            }

            $roleId = 0;
            if ($legacyRole !== 'none') {
                $stmt = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1' . $lock);
                $stmt->execute([':slug' => $legacyRole]);
                $roleId = (int)$stmt->fetchColumn();
                if ($roleId <= 0) {
                    throw new RuntimeException('Legacy role not found.');
                }
            }

            $oldStmt = $pdo->prepare(
                'SELECT role_id FROM user_roles
                 WHERE user_id = :user_id
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY role_id' . $lock
            );
            $oldStmt->execute([':user_id' => $userId]);
            $oldRoleIds = array_map('intval', $oldStmt->fetchAll(PDO::FETCH_COLUMN));

            $delete = $pdo->prepare(
                "DELETE FROM user_roles
                 WHERE user_id = :user_id
                   AND role_id IN (
                       SELECT id FROM roles WHERE slug IN ('author','editor','admin')
                   )"
            );
            $delete->execute([':user_id' => $userId]);

            if ($roleId > 0) {
                $insert = $pdo->prepare(
                    'INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (:user_id, :role_id, :assigned_by)'
                );
                $insert->execute([
                    ':user_id' => $userId,
                    ':role_id' => $roleId,
                    ':assigned_by' => $assignedBy,
                ]);
            }

            $legacyUpdate = $pdo->prepare('UPDATE users SET role = :role, updated_at = NOW() WHERE id = :id');
            if (!$legacyUpdate->execute([':role' => $legacyRole, ':id' => $userId])) {
                throw new RuntimeException('Legacy user role update failed.');
            }

            $finalStmt = $pdo->prepare(
                'SELECT role_id FROM user_roles
                 WHERE user_id = :user_id
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY role_id' . $lock
            );
            $finalStmt->execute([':user_id' => $userId]);
            $finalRoleIds = array_map('intval', $finalStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($oldRoleIds !== $finalRoleIds) {
                $roleChange = [
                    'user_id' => $userId,
                    'old_role_ids' => $oldRoleIds,
                    'new_role_ids' => $finalRoleIds,
                    'actor_id' => $assignedBy,
                ];
            }

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            if ($ownsTransaction && $roleChange !== null && function_exists('do_action')) {
                // Caller-owned transactions receive $roleChange and must dispatch only after their outer commit.
                try {
                    do_action('authorization_user_roles_changed', $userId, $roleChange['old_role_ids'], $roleChange['new_role_ids'], $assignedBy, $pdo);
                } catch (Throwable $hookError) {
                    error_log('[authorization_user_roles_changed] ' . $hookError->getMessage());
                }
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownsTransaction && $pdo->inTransaction()) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (Throwable $ignored) {
                }
            }
            return false;
        }
    }
}

if (!function_exists('authorization_set_site_owner')) {
    function authorization_set_site_owner(
        PDO $pdo,
        int $actorUserId,
        int $targetUserId,
        bool $makeSiteOwner,
        ?array &$roleChange = null
    ): string {
        $roleChange = null;
        if ($actorUserId <= 0 || $targetUserId <= 0) {
            return 'invalid';
        }

        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'authorization_set_site_owner';
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }

            $activeOwnerIds = array_map('intval', $pdo->query(
                'SELECT id
                 FROM users
                 WHERE is_site_owner = 1 AND is_deleted = 0 AND is_locked = 0
                 FOR UPDATE'
            )->fetchAll(PDO::FETCH_COLUMN));

            $users = $pdo->prepare(
                'SELECT id, role, is_site_owner, site_owner_previous_role, is_deleted, is_locked
                 FROM users
                 WHERE id IN (:actor_id, :target_id)
                 FOR UPDATE'
            );
            $users->execute([
                ':actor_id' => $actorUserId,
                ':target_id' => $targetUserId,
            ]);
            $rows = [];
            foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[(int)$row['id']] = $row;
            }
            $actor = $rows[$actorUserId] ?? null;
            $target = $rows[$targetUserId] ?? null;
            if (!$actor || (int)$actor['is_site_owner'] !== 1 || (int)$actor['is_deleted'] === 1 || (int)$actor['is_locked'] === 1) {
                throw new RuntimeException('Actor is not an active Site Owner.');
            }
            if (!$target || (int)$target['is_deleted'] === 1 || (int)$target['is_locked'] === 1) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                return 'target_inactive';
            }

            $currentlyOwner = (int)$target['is_site_owner'] === 1;
            if ($currentlyOwner === $makeSiteOwner) {
                if ($ownsTransaction) {
                    $pdo->commit();
                } else {
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                return 'unchanged';
            }
            if (!$makeSiteOwner && in_array($targetUserId, $activeOwnerIds, true) && count($activeOwnerIds) <= 1) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                return 'last_site_owner';
            }

            $oldRoleStmt = $pdo->prepare(
                'SELECT role_id FROM user_roles
                 WHERE user_id = :user_id
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY role_id
                 FOR UPDATE'
            );
            $oldRoleStmt->execute([':user_id' => $targetUserId]);
            $oldRoleIds = array_map('intval', $oldRoleStmt->fetchAll(PDO::FETCH_COLUMN));

            $previousRole = $target['site_owner_previous_role'] !== null
                ? (string)$target['site_owner_previous_role']
                : null;
            if ($makeSiteOwner) {
                $clearBackup = $pdo->prepare('DELETE FROM site_owner_role_backups WHERE user_id = :user_id');
                $clearBackup->execute([':user_id' => $targetUserId]);
                $backup = $pdo->prepare(
                    "INSERT INTO site_owner_role_backups
                        (user_id, role_id, assigned_by, assigned_at, expires_at)
                     SELECT ur.user_id, ur.role_id, ur.assigned_by, ur.assigned_at, ur.expires_at
                     FROM user_roles ur
                     JOIN roles r ON r.id = ur.role_id
                     WHERE ur.user_id = :user_id
                       AND r.slug IN ('author','editor','admin')"
                );
                $backup->execute([':user_id' => $targetUserId]);

                $update = $pdo->prepare(
                    'UPDATE users
                     SET is_site_owner = 1, site_owner_previous_role = :previous_role, updated_at = NOW()
                     WHERE id = :id'
                );
                $update->execute([
                    ':previous_role' => (string)$target['role'],
                    ':id' => $targetUserId,
                ]);
                if (!authorization_assign_legacy_role($pdo, $targetUserId, 'admin', $actorUserId)) {
                    throw new RuntimeException('Site Owner compatibility role assignment failed.');
                }
            } else {
                if ($previousRole !== null) {
                    $deleteCompatibilityRoles = $pdo->prepare(
                        "DELETE FROM user_roles
                         WHERE user_id = :user_id
                           AND role_id IN (
                               SELECT id FROM roles WHERE slug IN ('author','editor','admin')
                           )"
                    );
                    $deleteCompatibilityRoles->execute([':user_id' => $targetUserId]);
                    $restore = $pdo->prepare(
                        'INSERT INTO user_roles (user_id, role_id, assigned_by, assigned_at, expires_at)
                         SELECT user_id, role_id, assigned_by, assigned_at, expires_at
                         FROM site_owner_role_backups
                         WHERE user_id = :user_id'
                    );
                    $restore->execute([':user_id' => $targetUserId]);
                    $update = $pdo->prepare(
                        'UPDATE users
                         SET role = :role, is_site_owner = 0, site_owner_previous_role = NULL, updated_at = NOW()
                         WHERE id = :id'
                    );
                    $update->execute([
                        ':role' => $previousRole,
                        ':id' => $targetUserId,
                    ]);
                    $clearBackup = $pdo->prepare('DELETE FROM site_owner_role_backups WHERE user_id = :user_id');
                    $clearBackup->execute([':user_id' => $targetUserId]);
                } else {
                    $update = $pdo->prepare(
                        'UPDATE users
                         SET is_site_owner = 0, site_owner_previous_role = NULL, updated_at = NOW()
                         WHERE id = :id'
                    );
                    $update->execute([':id' => $targetUserId]);
                }
            }
            if (!authorization_audit(
                $pdo,
                $makeSiteOwner ? 'site_owner.granted' : 'site_owner.revoked',
                $actorUserId,
                $targetUserId,
                'user',
                (string)$targetUserId,
                ['previous_role' => $makeSiteOwner ? (string)$target['role'] : $previousRole]
            )) {
                throw new RuntimeException('Site Owner audit failed.');
            }

            $finalRoleStmt = $pdo->prepare(
                'SELECT role_id FROM user_roles
                 WHERE user_id = :user_id
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY role_id
                 FOR UPDATE'
            );
            $finalRoleStmt->execute([':user_id' => $targetUserId]);
            $finalRoleIds = array_map('intval', $finalRoleStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($oldRoleIds !== $finalRoleIds) {
                $roleChange = [
                    'user_id' => $targetUserId,
                    'old_role_ids' => $oldRoleIds,
                    'new_role_ids' => $finalRoleIds,
                    'actor_id' => $actorUserId,
                ];
            }

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            if ($ownsTransaction && $roleChange !== null && function_exists('do_action')) {
                // Nested callers receive $roleChange and must dispatch after their own final commit.
                try {
                    do_action('authorization_user_roles_changed', $targetUserId, $roleChange['old_role_ids'], $roleChange['new_role_ids'], $actorUserId, $pdo);
                } catch (Throwable $hookError) {
                    error_log('[authorization_user_roles_changed] ' . $hookError->getMessage());
                }
            }
            return 'ok';
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownsTransaction && $pdo->inTransaction()) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (Throwable $ignored) {
                }
            }
            return 'error';
        }
    }
}

if (!function_exists('authorization_audit')) {
    function authorization_audit(
        PDO $pdo,
        string $event,
        ?int $actorUserId = null,
        ?int $subjectUserId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = []
    ): bool {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO authorization_audit_log
                    (actor_user_id, subject_user_id, event_key, resource_type, resource_id, metadata)
                 VALUES
                    (:actor_user_id, :subject_user_id, :event_key, :resource_type, :resource_id, :metadata)'
            );
            return $stmt->execute([
                ':actor_user_id' => $actorUserId,
                ':subject_user_id' => $subjectUserId,
                ':event_key' => strtolower(trim($event)),
                ':resource_type' => $resourceType,
                ':resource_id' => $resourceId,
                ':metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('authorization_recover_site_owner')) {
    function authorization_recover_site_owner(PDO $pdo, int $targetUserId): string
    {
        if ($targetUserId <= 0) {
            return 'invalid';
        }
        try {
            $pdo->beginTransaction();
            $pdo->query(
                'SELECT id FROM users
                 WHERE is_site_owner = 1 AND is_deleted = 0 AND is_locked = 0
                 FOR UPDATE'
            )->fetchAll(PDO::FETCH_COLUMN);
            $targetStmt = $pdo->prepare(
                'SELECT id, role, is_site_owner, is_deleted, is_locked
                 FROM users WHERE id = :id FOR UPDATE'
            );
            $targetStmt->execute([':id' => $targetUserId]);
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$target || (int)$target['is_deleted'] === 1 || (int)$target['is_locked'] === 1) {
                $pdo->rollBack();
                return 'target_inactive';
            }
            if ((int)$target['is_site_owner'] === 1) {
                $pdo->commit();
                return 'unchanged';
            }

            $oldRoleStmt = $pdo->prepare(
                'SELECT role_id FROM user_roles
                 WHERE user_id = :user_id
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY role_id
                 FOR UPDATE'
            );
            $oldRoleStmt->execute([':user_id' => $targetUserId]);
            $oldRoleIds = array_map('intval', $oldRoleStmt->fetchAll(PDO::FETCH_COLUMN));

            $clearBackup = $pdo->prepare('DELETE FROM site_owner_role_backups WHERE user_id = :user_id');
            $clearBackup->execute([':user_id' => $targetUserId]);
            $backup = $pdo->prepare(
                "INSERT INTO site_owner_role_backups
                    (user_id, role_id, assigned_by, assigned_at, expires_at)
                 SELECT ur.user_id, ur.role_id, ur.assigned_by, ur.assigned_at, ur.expires_at
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = :user_id
                   AND r.slug IN ('author','editor','admin')"
            );
            $backup->execute([':user_id' => $targetUserId]);
            $promote = $pdo->prepare(
                'UPDATE users
                 SET is_site_owner = 1, site_owner_previous_role = :previous_role, updated_at = NOW()
                 WHERE id = :id'
            );
            $promote->execute([
                ':previous_role' => (string)$target['role'],
                ':id' => $targetUserId,
            ]);
            if (!authorization_assign_legacy_role($pdo, $targetUserId, 'admin')) {
                throw new RuntimeException('Recovery role assignment failed.');
            }
            if (!authorization_audit(
                $pdo,
                'site_owner.recovered',
                null,
                $targetUserId,
                'user',
                (string)$targetUserId,
                ['source' => 'cli', 'previous_role' => (string)$target['role']]
            )) {
                throw new RuntimeException('Recovery audit failed.');
            }
            $finalRoleStmt = $pdo->prepare(
                'SELECT role_id FROM user_roles
                 WHERE user_id = :user_id
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY role_id
                 FOR UPDATE'
            );
            $finalRoleStmt->execute([':user_id' => $targetUserId]);
            $finalRoleIds = array_map('intval', $finalRoleStmt->fetchAll(PDO::FETCH_COLUMN));
            $pdo->commit();
            if ($oldRoleIds !== $finalRoleIds && function_exists('do_action')) {
                try {
                    do_action('authorization_user_roles_changed', $targetUserId, $oldRoleIds, $finalRoleIds, null, $pdo);
                } catch (Throwable $hookError) {
                    error_log('[authorization_user_roles_changed] ' . $hookError->getMessage());
                }
            }
            return 'ok';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return 'error';
        }
    }
}
