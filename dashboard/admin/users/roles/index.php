<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.roles.manage', false);
$actor = authorization_actor($pdo, $uid);
if ($actor === null || $actor['is_site_owner'] !== true) {
    adiwira_render_404();
}

$base = ADMIN_BASE_PATH;
$pageUrl = $base . '/?page=admin/users/roles/index';
$errors = [];

$permissionsStmt = $pdo->query(
    'SELECT permission_key, provider, resource, action, label, supports_scope
     FROM permissions
     WHERE is_active = 1
     ORDER BY provider ASC, resource ASC, action ASC, permission_key ASC'
);
$permissions = $permissionsStmt->fetchAll(PDO::FETCH_ASSOC);
$enforcedCorePermissions = [
    'core.dashboard.access',
    'core.dashboard.stats.read',
    'core.dashboard.layout.manage',
    'core.users.read',
    'core.users.update',
    'core.users.delete',
    'core.users.restore',
    'core.users.purge',
    'core.users.lock',
    'core.categories.read',
    'core.categories.create',
    'core.categories.update',
    'core.categories.trash',
    'core.categories.restore',
    'core.categories.purge',
    'core.posts.read',
    'core.posts.create',
    'core.posts.update',
    'core.posts.trash',
    'core.posts.restore',
    'core.posts.purge',
    'core.posts.publish',
    'core.posts.change_owner',
    'core.posts.change_dates',
    'core.posts.unfiltered_html',
    'core.pages.read',
    'core.pages.create',
    'core.pages.update',
    'core.pages.trash',
    'core.pages.restore',
    'core.pages.purge',
    'core.pages.publish',
    'core.pages.change_owner',
    'core.pages.change_dates',
    'core.pages.unfiltered_html',
    'core.menus.manage',
    'core.sidebar.manage',
    'core.settings.manage',
    'core.profile.manage',
];
$permissionsByKey = [];
foreach ($permissions as &$permission) {
    $permission['_assignable'] = (string)$permission['provider'] !== 'core'
        || in_array((string)$permission['permission_key'], $enforcedCorePermissions, true);
    $permissionsByKey[(string)$permission['permission_key']] = $permission;
}
unset($permission);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = __('Invalid CSRF token.');
    }

    $action = strtolower(trim((string)($_POST['action'] ?? 'save')));
    $roleId = (int)($_POST['role_id'] ?? 0);

    if ($action === 'delete' && empty($errors)) {
        try {
            $pdo->beginTransaction();
            if (!authorization_lock_site_owner_actor($pdo, $uid)) {
                throw new RuntimeException('Site Owner authorization changed.');
            }
            $roleStmt = $pdo->prepare('SELECT id, slug, name, is_system FROM roles WHERE id = :id FOR UPDATE');
            $roleStmt->execute([':id' => $roleId]);
            $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
            if (!$role) {
                throw new RuntimeException('Role not found.');
            }
            if ((int)$role['is_system'] === 1) {
                throw new DomainException('System roles cannot be deleted.');
            }

            $assignedStmt = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id = :role_id');
            $assignedStmt->execute([':role_id' => $roleId]);
            $assignedUsers = (int)$assignedStmt->fetchColumn();

            $delete = $pdo->prepare('DELETE FROM roles WHERE id = :id');
            $delete->execute([':id' => $roleId]);
            if (!authorization_audit(
                $pdo,
                'role.deleted',
                $uid,
                null,
                'role',
                (string)$roleId,
                ['slug' => (string)$role['slug'], 'assigned_users' => $assignedUsers]
            )) {
                throw new RuntimeException('Role deletion audit failed.');
            }
            $pdo->commit();
            adiwira_redirect_with_flash($pageUrl, 'success', __('Role deleted successfully.'));
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = __($e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[roles/delete] ' . $e->getMessage());
            $errors[] = __('Failed to delete role.');
        }
    }

    if ($action === 'save' && empty($errors)) {
        $name = trim((string)($_POST['name'] ?? ''));
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $description = trim((string)($_POST['description'] ?? ''));
        $authorityRank = max(0, min(1000, (int)($_POST['authority_rank'] ?? 0)));
        $enabledPermissions = $_POST['enabled_permissions'] ?? [];
        $scopeInputs = $_POST['permission_scopes'] ?? [];
        $editingSystemRole = false;
        if ($roleId > 0) {
            $systemCheck = $pdo->prepare('SELECT is_system FROM roles WHERE id = :id LIMIT 1');
            $systemCheck->execute([':id' => $roleId]);
            $editingSystemRole = (int)$systemCheck->fetchColumn() === 1;
        }

        if ($name === '') {
            $errors[] = __('Role name is required.');
        }
        if ($roleId <= 0 && preg_match('/^[a-z0-9][a-z0-9_-]{1,99}$/', $slug) !== 1) {
            $errors[] = __('Role slug must use lowercase letters, numbers, hyphens, or underscores.');
        }
        if ($roleId <= 0 && in_array($slug, ['none', 'root', 'superuser', 'site-owner', 'site_owner'], true)) {
            $errors[] = __('This role slug is reserved.');
        }
        if (!is_array($enabledPermissions)) {
            $enabledPermissions = [];
        }
        if (!is_array($scopeInputs)) {
            $scopeInputs = [];
        }

        $grants = [];
        foreach (array_unique(array_map('strval', $enabledPermissions)) as $permissionKey) {
            if (!isset($permissionsByKey[$permissionKey])) {
                $errors[] = __('An unknown permission was selected.');
                break;
            }
            $permission = $permissionsByKey[$permissionKey];
            if (!$editingSystemRole && ($permission['_assignable'] ?? false) !== true) {
                $errors[] = __('This Core permission is not assignable until its module uses dynamic authorization.');
                break;
            }
            if ((int)$permission['supports_scope'] === 1) {
                $scope = (string)($scopeInputs[hash('sha256', $permissionKey)] ?? 'own');
                if (!in_array($scope, ['own', 'same_or_lower', 'any'], true)) {
                    $errors[] = __('An invalid permission scope was selected.');
                    break;
                }
            } else {
                $scope = 'global';
            }
            $grants[$permissionKey] = $scope;
        }

        if (!$editingSystemRole && $roleId > 0) {
            $retainedStmt = $pdo->prepare('SELECT permission_key, scope FROM role_permissions WHERE role_id = :role_id');
            $retainedStmt->execute([':role_id' => $roleId]);
            foreach ($retainedStmt->fetchAll(PDO::FETCH_ASSOC) as $retainedGrant) {
                $permissionKey = (string)$retainedGrant['permission_key'];
                if (!isset($permissionsByKey[$permissionKey]) || ($permissionsByKey[$permissionKey]['_assignable'] ?? false) !== true) {
                    $grants[$permissionKey] = (string)$retainedGrant['scope'];
                }
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                if (!authorization_lock_site_owner_actor($pdo, $uid)) {
                    throw new RuntimeException('Site Owner authorization changed.');
                }
                $isSystemRole = false;
                if ($roleId > 0) {
                    $roleStmt = $pdo->prepare('SELECT id, slug, authority_rank, is_system FROM roles WHERE id = :id FOR UPDATE');
                    $roleStmt->execute([':id' => $roleId]);
                    $existingRole = $roleStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$existingRole) {
                        throw new RuntimeException('Role not found.');
                    }
                    $slug = (string)$existingRole['slug'];
                    $isSystemRole = (int)$existingRole['is_system'] === 1;
                    if ($isSystemRole) {
                        $authorityRank = (int)$existingRole['authority_rank'];
                    }
                    $update = $pdo->prepare(
                        'UPDATE roles
                         SET name = :name, description = :description, authority_rank = :authority_rank
                         WHERE id = :id'
                    );
                    $update->execute([
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                        ':authority_rank' => $authorityRank,
                        ':id' => $roleId,
                    ]);
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO roles (slug, name, description, authority_rank, is_system)
                         VALUES (:slug, :name, :description, :authority_rank, 0)'
                    );
                    $insert->execute([
                        ':slug' => $slug,
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                        ':authority_rank' => $authorityRank,
                    ]);
                    $roleId = (int)$pdo->lastInsertId();
                }

                if (!$isSystemRole) {
                    $deleteGrants = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
                    $deleteGrants->execute([':role_id' => $roleId]);
                    $insertGrant = $pdo->prepare(
                        'INSERT INTO role_permissions (role_id, permission_key, scope)
                         VALUES (:role_id, :permission_key, :scope)'
                    );
                    foreach ($grants as $permissionKey => $scope) {
                        $insertGrant->execute([
                            ':role_id' => $roleId,
                            ':permission_key' => $permissionKey,
                            ':scope' => $scope,
                        ]);
                    }
                }

                if (!authorization_audit(
                    $pdo,
                    'role.saved',
                    $uid,
                    null,
                    'role',
                    (string)$roleId,
                    ['slug' => $slug, 'permission_count' => count($grants)]
                )) {
                    throw new RuntimeException('Role save audit failed.');
                }
                $pdo->commit();
                adiwira_redirect_with_flash($pageUrl . '&id=' . $roleId, 'success', __('Role saved successfully.'));
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ((string)$e->getCode() === '23000') {
                    $errors[] = __('Role slug is already in use.');
                } else {
                    error_log('[roles/save] ' . $e->getMessage());
                    $errors[] = __('Failed to save role.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[roles/save] ' . $e->getMessage());
                $errors[] = __('Failed to save role.');
            }
        }
    }
}

$roles = $pdo->query(
    'SELECT r.id, r.slug, r.name, r.description, r.authority_rank, r.is_system,
            COUNT(DISTINCT ur.user_id) AS user_count,
            COUNT(DISTINCT rp.permission_key) AS permission_count
     FROM roles r
     LEFT JOIN user_roles ur ON ur.role_id = r.id AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
     LEFT JOIN role_permissions rp ON rp.role_id = r.id
     GROUP BY r.id
     ORDER BY r.authority_rank DESC, r.name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$selectedRoleId = (int)($_GET['id'] ?? 0);
$selectedRole = null;
foreach ($roles as $role) {
    if ((int)$role['id'] === $selectedRoleId) {
        $selectedRole = $role;
        break;
    }
}

$selectedGrants = [];
if ($selectedRole !== null) {
    $grantStmt = $pdo->prepare('SELECT permission_key, scope FROM role_permissions WHERE role_id = :role_id');
    $grantStmt->execute([':role_id' => (int)$selectedRole['id']]);
    foreach ($grantStmt->fetchAll(PDO::FETCH_ASSOC) as $grant) {
        $selectedGrants[(string)$grant['permission_key']] = (string)$grant['scope'];
    }
}
$selectedRoleIsSystem = $selectedRole !== null && (int)$selectedRole['is_system'] === 1;
$formGrants = $selectedGrants;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && strtolower(trim((string)($_POST['action'] ?? 'save'))) === 'save'
    && !empty($errors)
    && !$selectedRoleIsSystem) {
    $formGrants = [];
    foreach (array_unique(array_map('strval', (array)($_POST['enabled_permissions'] ?? []))) as $permissionKey) {
        if (!isset($permissionsByKey[$permissionKey]) || ($permissionsByKey[$permissionKey]['_assignable'] ?? false) !== true) continue;
        $permission = $permissionsByKey[$permissionKey];
        $scope = 'global';
        if ((int)$permission['supports_scope'] === 1) {
            $scope = (string)(($_POST['permission_scopes'] ?? [])[hash('sha256', $permissionKey)] ?? 'own');
            if (!in_array($scope, ['own', 'same_or_lower', 'any'], true)) $scope = 'own';
        }
        $formGrants[$permissionKey] = $scope;
    }
    foreach ($selectedGrants as $permissionKey => $scope) {
        if (!isset($permissionsByKey[$permissionKey]) || ($permissionsByKey[$permissionKey]['_assignable'] ?? false) !== true) {
            $formGrants[$permissionKey] = $scope;
        }
    }
}

$groupedPermissions = [];
foreach ($permissions as $permission) {
    $groupKey = (string)$permission['provider'] . ' / ' . (string)$permission['resource'];
    $groupedPermissions[$groupKey][] = $permission;
}
$systemRoles = array_values(array_filter($roles, static fn(array $role): bool => (int)$role['is_system'] === 1));
$customRoles = array_values(array_filter($roles, static fn(array $role): bool => (int)$role['is_system'] !== 1));
$selectedPermissionCount = count($formGrants);
$hiddenRetainedGrantCount = count(array_diff_key($formGrants, $permissionsByKey));
$hasPreservedUnsavedState = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && strtolower(trim((string)($_POST['action'] ?? 'save'))) === 'save'
    && !empty($errors);
$assignablePermissionCount = count(array_filter($permissions, static fn(array $permission): bool => ($permission['_assignable'] ?? false) === true));
?>

<section class="adam-card authz-role-manager">
  <div class="authz-role-header">
    <div>
      <span class="authz-eyebrow"><?= _e('Access Control') ?></span>
      <h1><?= _e('Roles & Permissions') ?></h1>
      <p><?= _e('Build a role, choose what it can do, then limit which resources it can affect.') ?></p>
    </div>
    <div class="authz-header-summary" aria-label="<?= __('Role summary') ?>"><span><strong><?= count($roles) ?></strong><?= _e('Roles') ?></span><span><strong><?= $assignablePermissionCount ?></strong><?= _e('Available permissions') ?></span></div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="authz-role-errors" role="alert" tabindex="-1" id="authzRoleErrors">
      <strong><?= _e('Please review the following:') ?></strong>
      <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="authz-role-layout">
    <nav class="authz-role-rail" aria-label="<?= __('Roles') ?>">
      <div class="authz-rail-heading"><strong><?= _e('Roles') ?></strong><span><?= count($roles) ?></span></div>
      <a href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>" data-role-nav class="authz-new-role<?= $selectedRole === null ? ' is-active' : '' ?>" <?= $selectedRole === null ? 'aria-current="page"' : '' ?>><span>+</span><span><strong><?= _e('New custom role') ?></strong><small><?= _e('Start with no permissions') ?></small></span></a>
      <div class="authz-role-list">
      <?php foreach ($roles as $role): $roleActive = (int)$role['id'] === $selectedRoleId; ?>
        <a href="<?= htmlspecialchars($pageUrl . '&id=' . (int)$role['id'], ENT_QUOTES, 'UTF-8') ?>"
           data-role-nav class="authz-role-item<?= $roleActive ? ' is-active' : '' ?>" <?= $roleActive ? 'aria-current="page"' : '' ?>>
          <span class="authz-role-item-top"><span class="authz-role-name"><?= htmlspecialchars((string)$role['name'], ENT_QUOTES, 'UTF-8') ?></span><span class="authz-role-kind <?= (int)$role['is_system'] === 1 ? 'is-system' : 'is-custom' ?>"><?= (int)$role['is_system'] === 1 ? _e('System') : _e('Custom') ?></span></span>
          <code><?= htmlspecialchars((string)$role['slug'], ENT_QUOTES, 'UTF-8') ?></code>
          <small><?= (int)$role['user_count'] ?> <?= _e('users') ?> · <?= (int)$role['permission_count'] ?> <?= _e('permissions') ?></small>
        </a>
      <?php endforeach; ?>
      </div>
    </nav>

    <form method="post" class="authz-role-form" id="authzRoleForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="role_id" value="<?= (int)($selectedRole['id'] ?? 0) ?>">

      <div class="authz-editor-heading"><div><span><?= $selectedRole === null ? _e('Create role') : _e('Edit role') ?></span><h2><?= htmlspecialchars((string)($selectedRole['name'] ?? __('New custom role')), ENT_QUOTES, 'UTF-8') ?></h2></div><?php if ($selectedRole !== null): ?><small><?= (int)$selectedRole['user_count'] ?> <?= _e('users') ?> · <?= $selectedPermissionCount ?> <?= _e('permissions') ?></small><?php endif; ?></div>

      <?php if ($selectedRoleIsSystem): ?>
        <div class="authz-role-notice is-locked"><strong><?= _e('Protected system role') ?></strong><span><?= _e('You can edit its name and description. Core manages its slug, authority rank, and permissions.') ?></span></div>
      <?php elseif ($selectedRole !== null): ?>
        <div class="authz-role-notice is-editable"><strong><?= _e('Editable custom role') ?></strong><span><?= _e('Change its details, permissions, and ownership scopes below.') ?></span></div>
      <?php else: ?>
        <div class="authz-role-notice is-editable"><strong><?= _e('Create a custom role') ?></strong><span><?= _e('Name the role first, then grant only the permissions it needs.') ?></span></div>
      <?php endif; ?>

      <div class="authz-role-fields">
        <label><?= _e('Role Name') ?>
          <input type="text" name="name" id="authzRoleName" required value="<?= htmlspecialchars((string)($_POST['name'] ?? $selectedRole['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label><?= _e('Role Slug') ?>
          <input type="text" name="slug" id="authzRoleSlug" required <?= $selectedRole !== null ? 'readonly' : '' ?> aria-describedby="authzSlugHelp" value="<?= htmlspecialchars((string)($_POST['slug'] ?? $selectedRole['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          <small id="authzSlugHelp"><?= _e('Lowercase letters, numbers, hyphens, or underscores. It cannot be changed later.') ?></small>
        </label>
        <label><?= _e('Authority Rank') ?>
          <input type="number" name="authority_rank" min="0" max="1000" <?= $selectedRoleIsSystem ? 'readonly' : '' ?> value="<?= (int)($_POST['authority_rank'] ?? $selectedRole['authority_rank'] ?? 0) ?>">
          <small><?= _e('Used only by Same or Lower. Equal or lower ranks can be managed; rank alone grants nothing.') ?></small>
        </label>
        <label class="authz-role-description"><?= _e('Description') ?>
          <textarea name="description" rows="3"><?= htmlspecialchars((string)($_POST['description'] ?? $selectedRole['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>
      </div>

      <div class="authz-permission-heading"><div><h3><?= _e('Permission Grants') ?></h3><p><?= _e('Enable an action, then choose how broadly the role may use it.') ?></p></div><span id="authzEnabledTotal"><?= $selectedPermissionCount ?> <?= _e('enabled') ?></span></div>
      <?php if ($hiddenRetainedGrantCount > 0): ?><div class="authz-hidden-grants"><?= sprintf(__('%d retained inactive permissions are hidden and will not be changed.'), $hiddenRetainedGrantCount) ?></div><?php endif; ?>
      <div class="authz-scope-guide">
        <span><strong><?= _e('Own') ?></strong><?= _e('Only resources owned by the acting user.') ?></span>
        <span><strong><?= _e('Same or Lower') ?></strong><?= _e('Own resources and resources owned by users at an equal or lower rank.') ?></span>
        <span><strong><?= _e('Any') ?></strong><?= _e('Any resource covered by this permission.') ?></span>
        <span><strong><?= _e('Global') ?></strong><?= _e('This action does not use resource ownership.') ?></span>
      </div>
      <div class="authz-permission-tools"><label><span class="sr-only"><?= _e('Search permissions') ?></span><input type="search" id="authzPermissionSearch" placeholder="<?= __('Search permissions or modules...') ?>"></label><div><button type="button" class="adam-cancle" id="authzExpandAll"><?= _e('Expand all') ?></button><button type="button" class="adam-cancle" id="authzCollapseAll"><?= _e('Collapse all') ?></button></div></div>
      <div class="authz-permission-groups" id="authzPermissionGroups">
        <?php foreach ($groupedPermissions as $groupLabel => $items):
          $groupProvider = (string)($items[0]['provider'] ?? '');
          $groupResource = (string)($items[0]['resource'] ?? '');
          $groupName = __(ucwords(str_replace(['_', '-'], ' ', $groupResource)));
          $groupEnabled = count(array_filter($items, static fn(array $item): bool => array_key_exists((string)$item['permission_key'], $formGrants)));
        ?>
          <details class="authz-permission-group" <?= $groupEnabled > 0 ? 'open' : '' ?>>
            <summary><span><strong><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars(ucfirst($groupProvider), ENT_QUOTES, 'UTF-8') ?></small></span><span class="authz-group-count"><?= $groupEnabled ?> / <?= count($items) ?></span></summary>
            <div class="authz-permission-list">
            <?php foreach ($items as $permission):
              $permissionKey = (string)$permission['permission_key'];
              $permissionLabel = __(strval($permission['label']));
              $isEnabled = array_key_exists($permissionKey, $formGrants);
              $scope = $formGrants[$permissionKey] ?? ((int)$permission['supports_scope'] === 1 ? 'own' : 'global');
              $scopeHash = hash('sha256', $permissionKey);
              $permissionAssignable = ($permission['_assignable'] ?? false) === true;
              $permissionDisabled = $selectedRoleIsSystem || !$permissionAssignable;
            ?>
              <div class="authz-permission-row" data-permission-search="<?= htmlspecialchars(strtolower($groupName . ' ' . $permissionLabel . ' ' . $permissionKey), ENT_QUOTES, 'UTF-8') ?>">
                <label class="authz-permission-check">
                  <input type="checkbox" class="authz-permission-toggle" name="enabled_permissions[]" value="<?= htmlspecialchars($permissionKey, ENT_QUOTES, 'UTF-8') ?>" <?= $isEnabled ? 'checked' : '' ?> <?= $permissionDisabled ? 'disabled' : '' ?>>
                  <span><strong><?= htmlspecialchars($permissionLabel, ENT_QUOTES, 'UTF-8') ?></strong><code><?= htmlspecialchars($permissionKey, ENT_QUOTES, 'UTF-8') ?></code><?php if (!$permissionAssignable): ?><small class="authz-unavailable"><?= $isEnabled ? _e('Retained, currently unavailable to change') : _e('Not yet available for custom roles') ?></small><?php endif; ?></span>
                </label>
                <?php if ((int)$permission['supports_scope'] === 1): ?>
                  <select class="authz-scope-select" name="permission_scopes[<?= $scopeHash ?>]" aria-label="<?= htmlspecialchars(sprintf(__('Scope for %s'), $permissionLabel), ENT_QUOTES, 'UTF-8') ?>" <?= ($permissionDisabled || !$isEnabled) ? 'disabled' : '' ?>>
                    <option value="own" <?= $scope === 'own' ? 'selected' : '' ?>><?= _e('Own') ?></option>
                    <option value="same_or_lower" <?= $scope === 'same_or_lower' ? 'selected' : '' ?>><?= _e('Same or Lower') ?></option>
                    <option value="any" <?= $scope === 'any' ? 'selected' : '' ?>><?= _e('Any') ?></option>
                  </select>
                <?php else: ?>
                  <span class="authz-global-scope"><?= _e('Global') ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
      <p id="authzSearchEmpty" class="authz-search-empty" hidden><?= _e('No permissions match your search.') ?></p>

      <div class="authz-role-actions">
        <span id="authzDirtyState" class="<?= $hasPreservedUnsavedState ? 'is-dirty' : '' ?>" aria-live="polite"><?= $hasPreservedUnsavedState ? _e('Unsaved changes') : _e('No unsaved changes') ?></span><div><a class="adam-cancle" data-role-nav href="<?= htmlspecialchars($selectedRole === null ? $pageUrl : $pageUrl . '&id=' . (int)$selectedRole['id'], ENT_QUOTES, 'UTF-8') ?>"><?= _e('Reset') ?></a><button type="submit" class="adam-button" id="authzSaveRole"><?= $selectedRole === null ? _e('Create Role') : ($selectedRoleIsSystem ? _e('Save role details') : _e('Save Changes')) ?></button></div>
      </div>
    </form>
  </div>

  <?php if ($selectedRole !== null && (int)$selectedRole['is_system'] !== 1): ?>
    <div class="authz-danger-zone"><div><strong><?= _e('Delete this role') ?></strong><span><?= sprintf(__('%d users currently have this role.'), (int)$selectedRole['user_count']) ?></span></div><button type="button" class="adam-hapus" id="authzDeleteRoleOpen"><?= _e('Delete Role') ?></button></div>
    <form method="post" id="authzDeleteRoleForm" hidden>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="role_id" value="<?= (int)$selectedRole['id'] ?>">
    </form>
  <?php endif; ?>
</section>

<style>
.authz-role-header{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1.25rem}.authz-role-header h2{margin:0}.authz-role-header p{margin:.35rem 0 0;color:var(--adam-muted)}.authz-role-layout{display:grid;grid-template-columns:minmax(190px,260px) minmax(0,1fr);gap:1.25rem}.authz-role-list{display:flex;flex-direction:column;gap:.45rem}.authz-role-item{display:flex;flex-direction:column;gap:.2rem;padding:.75rem;border:1px solid var(--adam-border);border-radius:8px;text-decoration:none;color:var(--adam-text);background:var(--adam-card)}.authz-role-item.is-active{border-color:var(--adam-primary);box-shadow:0 0 0 2px color-mix(in srgb,var(--adam-primary) 18%,transparent)}.authz-role-name{font-weight:700}.authz-role-item code,.authz-permission-check code{font-size:.72rem;color:var(--adam-muted);overflow-wrap:anywhere}.authz-role-item small{color:var(--adam-muted)}.authz-role-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem}.authz-role-fields label{display:flex;flex-direction:column;gap:.35rem;font-weight:600}.authz-role-fields input,.authz-role-fields textarea,.authz-permission-row select{padding:.55rem;border:1px solid var(--adam-border);border-radius:7px;background:var(--adam-card);color:var(--adam-text)}.authz-role-fields small{font-weight:400;color:var(--adam-muted)}.authz-role-description{grid-column:1/-1}.authz-permission-groups{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:.8rem}.authz-permission-group{border:1px solid var(--adam-border);border-radius:9px;overflow:hidden}.authz-permission-group h4{margin:0;padding:.65rem .8rem;background:var(--adam-surface-3)}.authz-permission-row{display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.65rem .8rem;border-top:1px solid var(--adam-border)}.authz-permission-check{display:flex;gap:.55rem;align-items:flex-start;min-width:0}.authz-permission-check span{display:flex;flex-direction:column;gap:.15rem}.authz-permission-row select{max-width:140px}.authz-global-scope{font-size:.78rem;color:var(--adam-muted)}.authz-role-actions{margin-top:1rem}.authz-delete-role{margin-top:1rem;padding-top:1rem;border-top:1px solid var(--adam-border)}.authz-role-errors{padding:.75rem;margin-bottom:1rem;border:1px solid #fda29b;background:#fef3f2;color:#b42318;border-radius:8px}
@media(max-width:800px){.authz-role-layout{grid-template-columns:1fr}.authz-role-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}.authz-role-fields{grid-template-columns:1fr}.authz-permission-groups{grid-template-columns:1fr}.authz-permission-row{align-items:flex-start;flex-direction:column}.authz-permission-row select{max-width:none;width:100%}}
.authz-role-kind{align-self:flex-start;padding:.12rem .42rem;border-radius:999px;font-size:.68rem;font-weight:700}.authz-role-kind.is-system{color:var(--adam-muted);background:var(--adam-surface-3)}.authz-role-kind.is-custom{color:var(--adam-primary);background:var(--adam-primary-soft)}.authz-role-notice{margin-bottom:1rem;padding:.75rem .85rem;border-radius:8px;border:1px solid var(--adam-border);line-height:1.45}.authz-role-notice.is-locked{background:color-mix(in srgb,#f59e0b 12%,var(--adam-card));border-color:color-mix(in srgb,#f59e0b 35%,var(--adam-border))}.authz-role-notice.is-editable{background:var(--adam-primary-soft);border-color:color-mix(in srgb,var(--adam-primary) 30%,var(--adam-border))}
.authz-role-manager{max-width:1500px}.authz-role-header{align-items:flex-end;padding-bottom:1rem;border-bottom:1px solid var(--adam-border)}.authz-role-header h1{margin:.15rem 0 0;font-size:clamp(1.45rem,2.5vw,2rem);line-height:1.15}.authz-eyebrow,.authz-editor-heading>div>span{color:var(--adam-primary);font-size:.7rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase}.authz-header-summary{display:flex;gap:.5rem}.authz-header-summary span{display:flex;flex-direction:column;min-width:100px;padding:.55rem .7rem;border:1px solid var(--adam-border);border-radius:9px;color:var(--adam-muted);font-size:.72rem;background:var(--adam-surface-3)}.authz-header-summary strong{color:var(--adam-text);font-size:1.05rem}.authz-role-layout{grid-template-columns:minmax(220px,270px) minmax(0,1fr)}.authz-role-rail{position:sticky;top:1rem;align-self:start;max-height:calc(100vh - 2rem);overflow:auto;padding-right:.2rem}.authz-rail-heading{display:flex;justify-content:space-between;margin-bottom:.5rem}.authz-rail-heading span{padding:.05rem .4rem;border-radius:999px;background:var(--adam-surface-3);color:var(--adam-muted);font-size:.72rem}.authz-new-role{display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem;padding:.7rem;border:1px dashed var(--adam-border);border-radius:9px;text-decoration:none;color:var(--adam-text)}.authz-new-role>span:first-child{display:grid;place-items:center;width:28px;height:28px;border-radius:7px;background:var(--adam-primary-soft);color:var(--adam-primary);font-size:1.1rem}.authz-new-role>span:last-child{display:flex;flex-direction:column}.authz-new-role small{color:var(--adam-muted)}.authz-new-role.is-active,.authz-role-item.is-active{border-color:var(--adam-primary);background:color-mix(in srgb,var(--adam-primary) 7%,var(--adam-card));box-shadow:inset 3px 0 0 var(--adam-primary)}.authz-role-item-top{display:flex;justify-content:space-between;align-items:center;gap:.5rem}.authz-editor-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;margin-bottom:.75rem}.authz-editor-heading h2{margin:.15rem 0 0}.authz-editor-heading>small{padding:.25rem .5rem;border-radius:999px;background:var(--adam-surface-3);color:var(--adam-muted)}.authz-role-notice{display:flex;flex-direction:column;gap:.2rem}.authz-role-notice span{color:var(--adam-muted)}.authz-permission-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;margin:1.2rem 0 .7rem}.authz-permission-heading h3{margin:0}.authz-permission-heading p{margin:.2rem 0 0;color:var(--adam-muted);font-size:.85rem}.authz-permission-heading>span{padding:.28rem .55rem;border-radius:999px;background:var(--adam-surface-3);color:var(--adam-muted);font-size:.74rem}.authz-hidden-grants{margin-bottom:.7rem;padding:.55rem .7rem;border-radius:8px;background:color-mix(in srgb,#f59e0b 9%,var(--adam-card));color:#b45309;font-size:.78rem}.authz-scope-guide{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.45rem;margin-bottom:.75rem}.authz-scope-guide span{display:flex;flex-direction:column;gap:.1rem;padding:.5rem .6rem;border-radius:8px;background:var(--adam-surface-3);color:var(--adam-muted);font-size:.72rem;line-height:1.35}.authz-scope-guide strong{color:var(--adam-text)}.authz-permission-tools{display:flex;justify-content:space-between;gap:.6rem;align-items:center;margin-bottom:.7rem}.authz-permission-tools>label{flex:1;max-width:520px}.authz-permission-tools input{width:100%;box-sizing:border-box;padding:.55rem .65rem;border:1px solid var(--adam-border);border-radius:8px;background:var(--adam-card);color:var(--adam-text)}.authz-permission-tools>div{display:flex;gap:.35rem}.authz-permission-groups{display:flex;flex-direction:column;gap:.55rem}.authz-permission-group{background:var(--adam-card)}.authz-permission-group summary{display:flex;justify-content:space-between;align-items:center;padding:.65rem .75rem;background:var(--adam-surface-3);cursor:pointer;list-style:none}.authz-permission-group summary::-webkit-details-marker{display:none}.authz-permission-group summary>span:first-child{display:flex;align-items:center;gap:.45rem}.authz-permission-group summary small{padding:.08rem .35rem;border-radius:999px;background:var(--adam-card);color:var(--adam-muted);font-size:.62rem;text-transform:uppercase}.authz-group-count{font-size:.72rem;color:var(--adam-muted)}.authz-permission-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(120px,170px)}.authz-permission-row.is-search-hidden,.authz-permission-group.is-search-hidden{display:none}.authz-permission-row select:disabled{opacity:.5;cursor:not-allowed}.authz-global-scope{justify-self:end;padding:.22rem .45rem;border-radius:999px;background:var(--adam-surface-3);color:var(--adam-muted);font-size:.72rem}.authz-unavailable{color:#b45309}.authz-search-empty{text-align:center;color:var(--adam-muted)}.authz-role-actions{position:sticky;bottom:0;z-index:3;justify-content:space-between;align-items:center;padding:.7rem;border:1px solid var(--adam-border);border-radius:10px;background:color-mix(in srgb,var(--adam-card) 94%,transparent);backdrop-filter:blur(10px);box-shadow:0 -8px 24px color-mix(in srgb,#000 8%,transparent)}.authz-role-actions>span{font-size:.8rem;color:var(--adam-muted)}.authz-role-actions>span.is-dirty{color:#b45309;font-weight:700}.authz-role-actions>div{display:flex;gap:.45rem}.authz-danger-zone{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-top:1rem;padding:.8rem 1rem;border:1px solid color-mix(in srgb,#ef4444 35%,var(--adam-border));border-radius:10px;background:color-mix(in srgb,#ef4444 5%,var(--adam-card))}.authz-danger-zone>div{display:flex;flex-direction:column;gap:.12rem}.authz-danger-zone span{color:var(--adam-muted);font-size:.8rem}.authz-role-errors ul{margin:.4rem 0 0;padding-left:1.2rem}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:1050px){.authz-scope-guide{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:800px){.authz-role-header{align-items:flex-start;flex-direction:column}.authz-header-summary{width:100%}.authz-header-summary span{flex:1}.authz-role-rail{position:static;max-height:none}.authz-role-list{max-height:260px;overflow:auto;padding-right:.2rem}.authz-scope-guide{grid-template-columns:1fr}.authz-permission-tools{align-items:stretch;flex-direction:column}.authz-permission-tools>label{max-width:none}.authz-permission-row{grid-template-columns:1fr}.authz-global-scope{justify-self:start}.authz-role-actions{align-items:stretch;flex-direction:column}.authz-role-actions>div{display:grid;grid-template-columns:1fr 1fr}.authz-danger-zone{align-items:stretch;flex-direction:column}.authz-danger-zone button{width:100%}}
@media(min-width:801px){.authz-role-rail{top:4.75rem;max-height:calc(100vh - 5.75rem)}}
</style>

<script>
(function(){
  const form = document.getElementById('authzRoleForm');
  if (!form) return;
  const groups = Array.from(document.querySelectorAll('.authz-permission-group'));
  const dirtyState = document.getElementById('authzDirtyState');
  const saveButton = document.getElementById('authzSaveRole');
  const nameInput = document.getElementById('authzRoleName');
  const slugInput = document.getElementById('authzRoleSlug');
  const searchInput = document.getElementById('authzPermissionSearch');
  let slugTouched = !!(slugInput && slugInput.value);
  let submitting = false;

  function syncScope(toggle){
    const select = toggle.closest('.authz-permission-row')?.querySelector('.authz-scope-select');
    if (select && !toggle.disabled) select.disabled = !toggle.checked;
  }
  function updateCounts(){
    let total = 0;
    groups.forEach(function(group){
      const toggles = Array.from(group.querySelectorAll('.authz-permission-toggle'));
      const enabled = toggles.filter(function(toggle){ return toggle.checked; }).length;
      total += enabled;
      const count = group.querySelector('.authz-group-count');
      if (count) count.textContent = enabled + ' / ' + toggles.length;
    });
    const totalNode = document.getElementById('authzEnabledTotal');
    if (totalNode) totalNode.textContent = total + ' ' + <?= json_encode(__('enabled')) ?>;
  }
  function markDirty(){
    if (submitting || !dirtyState) return;
    dirtyState.textContent = <?= json_encode(__('Unsaved changes')) ?>;
    dirtyState.classList.add('is-dirty');
  }
  function slugify(value){
    return String(value || '').toLowerCase().trim().replace(/[^a-z0-9_-]+/g, '-').replace(/-{2,}/g, '-').replace(/^[-_]+|[-_]+$/g, '').slice(0, 100);
  }

  document.querySelectorAll('.authz-permission-toggle').forEach(function(toggle){
    syncScope(toggle);
    toggle.addEventListener('change', function(){ syncScope(toggle); updateCounts(); markDirty(); });
  });
  form.addEventListener('input', function(event){ if (event.target !== searchInput) markDirty(); });
  form.addEventListener('change', function(event){ if (event.target !== searchInput) markDirty(); });
  slugInput?.addEventListener('input', function(){ slugTouched = true; });
  nameInput?.addEventListener('input', function(){ if (slugInput && !slugInput.readOnly && !slugTouched) slugInput.value = slugify(nameInput.value); });

  searchInput?.addEventListener('input', function(){
    const query = this.value.trim().toLowerCase();
    let visibleGroups = 0;
    groups.forEach(function(group){
      let visibleRows = 0;
      group.querySelectorAll('.authz-permission-row').forEach(function(row){
        const visible = query === '' || String(row.dataset.permissionSearch || '').includes(query);
        row.classList.toggle('is-search-hidden', !visible);
        if (visible) visibleRows++;
      });
      group.classList.toggle('is-search-hidden', visibleRows === 0);
      if (visibleRows > 0) { visibleGroups++; if (query) group.open = true; }
    });
    const empty = document.getElementById('authzSearchEmpty');
    if (empty) empty.hidden = visibleGroups !== 0;
  });
  document.getElementById('authzExpandAll')?.addEventListener('click', function(){ groups.forEach(function(group){ group.open = true; }); });
  document.getElementById('authzCollapseAll')?.addEventListener('click', function(){ groups.forEach(function(group){ group.open = false; }); });

  document.querySelectorAll('[data-role-nav]').forEach(function(link){
    link.addEventListener('click', function(event){
      if (!dirtyState?.classList.contains('is-dirty') || submitting) return;
      event.preventDefault();
      const options = {title:<?= json_encode(__('Discard unsaved changes?')) ?>,message:<?= json_encode(__('Your changes to this role have not been saved.')) ?>,confirmText:<?= json_encode(__('Discard changes')) ?>,cancelText:<?= json_encode(__('Keep editing')) ?>};
      const decision = window.NewNotifConfirm?.warning ? window.NewNotifConfirm.warning(options) : Promise.resolve(window.confirm(options.message));
      decision.then(function(ok){ if (ok) { submitting = true; window.location.href = link.href; } });
    });
  });
  form.addEventListener('submit', function(){
    submitting = true;
    if (saveButton) { saveButton.disabled = true; saveButton.textContent = <?= json_encode(__('Saving...')) ?>; }
  });
  window.addEventListener('beforeunload', function(event){
    if (submitting || !dirtyState?.classList.contains('is-dirty')) return;
    event.preventDefault();
    event.returnValue = '';
  });

  const deleteButton = document.getElementById('authzDeleteRoleOpen');
  const deleteForm = document.getElementById('authzDeleteRoleForm');
  deleteButton?.addEventListener('click', function(){
    const options = {title:<?= json_encode(__('Delete Role')) ?>,message:<?= json_encode(sprintf(__('Delete %s? Assigned users will immediately lose this role.'), (string)($selectedRole['name'] ?? ''))) ?>,confirmText:<?= json_encode(__('Delete Role')) ?>,cancelText:<?= json_encode(__('Cancel')) ?>};
    const decision = window.NewNotifConfirm?.danger ? window.NewNotifConfirm.danger(options) : Promise.resolve(window.confirm(options.message));
    decision.then(function(ok){ if (ok) { submitting = true; deleteForm?.submit(); } });
  });
  document.getElementById('authzRoleErrors')?.focus();
  updateCounts();
})();
</script>
