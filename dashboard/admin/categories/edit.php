<?php
declare(strict_types=1);

// /adiwira/admin/categories/edit.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_login($pdo, false);

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim($text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

$base = ADMIN_BASE_PATH;
$errors = [];

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/?page=admin/categories/index')
    : ($base . '/?page=admin/categories/index');

if ($id <= 0) {
    http_response_code(400);
    echo '<p>' . __('Invalid category ID.') . '</p>';
    return;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM categories
    WHERE id = :id
      AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$cat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cat) {
    http_response_code(404);
    echo '<p>' . __('Category not found.') . '</p>';
    return;
}

$cat['description'] = $cat['description'] ?? '';
$cat['name']        = isset($cat['name']) ? (string)$cat['name'] : '';
$cat['slug']        = isset($cat['slug']) ? (string)$cat['slug'] : '';
$cat['parent_id']   = isset($cat['parent_id']) && $cat['parent_id'] !== null ? (int)$cat['parent_id'] : null;
$cat['created_by']  = isset($cat['created_by']) ? (int)$cat['created_by'] : null;

if (!user_can($pdo, $uid, 'core.categories.update', ['owner_id' => (int)($cat['created_by'] ?? 0)])) {
    adiwira_render_404();
}

$readCondition = authorization_owner_scope_condition(
    $pdo,
    $uid,
    'core.categories.read',
    'categories.created_by',
    'category_edit_read'
);
$readWhere = $readCondition !== null ? ' AND (' . $readCondition['sql'] . ')' : ' AND 1=0';
$stmt = $pdo->prepare("
    SELECT id, name, parent_id
    FROM categories
    WHERE is_deleted = 0
      $readWhere
");
$stmt->execute($readCondition['params'] ?? []);
$allCats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$children = [];
$visibleCategoryIds = array_fill_keys(array_map(static fn(array $category): int => (int)$category['id'], $allCats), true);
foreach ($allCats as $c) {
    $pid = $c['parent_id'] === null ? 0 : (int)$c['parent_id'];
    if ($pid > 0 && !isset($visibleCategoryIds[$pid])) {
        $pid = 0;
    }
    $children[$pid][] = $c;
}

$flatten = [];
$walk = function(int $pid, int $depth) use (&$children, &$flatten, &$walk): void {
    if (!isset($children[$pid])) return;
    usort($children[$pid], fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
    foreach ($children[$pid] as $node) {
        $flatten[] = [
            'id'        => (int)$node['id'],
            'name'      => (string)$node['name'],
            'depth'     => $depth,
            'parent_id' => $node['parent_id'] === null ? null : (int)$node['parent_id'],
        ];
        $walk((int)$node['id'], $depth + 1);
    }
};
$walk(0, 0);

$integrityRows = $pdo->query(
    'SELECT id, parent_id FROM categories WHERE is_deleted = 0'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$integrityChildren = [];
foreach ($integrityRows as $integrityRow) {
    $integrityChildren[(int)($integrityRow['parent_id'] ?? 0)][] = $integrityRow;
}
$descendants = [];
$collectDesc = function(int $start) use (&$integrityChildren, &$descendants, &$collectDesc): void {
    if (!isset($integrityChildren[$start])) return;
    foreach ($integrityChildren[$start] as $c) {
        $cid = (int)$c['id'];
        if (isset($descendants[$cid])) continue;
        $descendants[$cid] = true;
        $collectDesc($cid);
    }
};
$collectDesc((int)$id);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    }

    $name        = trim((string)($_POST['name'] ?? ''));
    $slug        = trim((string)($_POST['slug'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $parent_id   = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;

    if ($name === '') {
        $errors[] = __('Category name is required.');
    }

    $slug = ($slug === '') ? slugify($name) : slugify($slug);

    if ($parent_id !== null && $parent_id === $id) {
        $errors[] = __('Parent cannot be the category itself.');
    }

    if ($parent_id !== null && empty($errors) && isset($descendants[$parent_id])) {
        $errors[] = __('Parent cannot be a child or descendant of this category.');
    }

    if ($parent_id !== null && empty($errors)) {
        $stmtParent = $pdo->prepare("
            SELECT id, created_by
            FROM categories
            WHERE id = :id
              AND is_deleted = 0
            LIMIT 1
        ");
        $stmtParent->execute([':id' => $parent_id]);
        $parentCategory = $stmtParent->fetch(PDO::FETCH_ASSOC);
        if (!$parentCategory
            || !user_can($pdo, $uid, 'core.categories.read', ['owner_id' => (int)($parentCategory['created_by'] ?? 0)])) {
            $errors[] = __('Invalid parent category.');
        }
    }

    if (empty($errors)) {
        $stmt2 = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE slug = :slug
              AND id != :id
              AND is_deleted = 0
            LIMIT 1
        ");
        $stmt2->execute([':slug' => $slug, ':id' => $id]);
        if ($stmt2->fetch()) {
            $errors[] = __('Slug already used by another category.');
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            if (!authorization_lock_actor_permissions($pdo, $uid)) {
                throw new DomainException('Category actor permission lock failed.');
            }
            $lockedRows = $pdo->query(
                'SELECT id, parent_id, created_by
                 FROM categories
                 WHERE is_deleted = 0
                 ORDER BY id
                 FOR UPDATE'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $lockedById = [];
            $lockedChildren = [];
            foreach ($lockedRows as $lockedRow) {
                $lockedId = (int)$lockedRow['id'];
                $lockedById[$lockedId] = $lockedRow;
                $lockedChildren[(int)($lockedRow['parent_id'] ?? 0)][] = $lockedId;
            }
            if (!isset($lockedById[$id])
                || !authorization_lock_owner_contexts($pdo, [(int)($lockedById[$id]['created_by'] ?? 0)])
                || !user_can($pdo, $uid, 'core.categories.update', ['owner_id' => (int)($lockedById[$id]['created_by'] ?? 0)])) {
                $pdo->rollBack();
                adiwira_render_404();
            }
            if ($parent_id !== null) {
                if ($parent_id === $id) {
                    throw new DomainException(__('Parent cannot be the category itself.'));
                }
                $lockedDescendants = [];
                $collectLockedDescendants = function(int $parent) use (&$collectLockedDescendants, &$lockedChildren, &$lockedDescendants): void {
                    foreach ($lockedChildren[$parent] ?? [] as $childId) {
                        if (isset($lockedDescendants[$childId])) continue;
                        $lockedDescendants[$childId] = true;
                        $collectLockedDescendants($childId);
                    }
                };
                $collectLockedDescendants($id);
                if (isset($lockedDescendants[$parent_id])) {
                    throw new DomainException(__('Parent cannot be a child or descendant of this category.'));
                }
                if (!isset($lockedById[$parent_id])
                    || !user_can($pdo, $uid, 'core.categories.read', ['owner_id' => (int)($lockedById[$parent_id]['created_by'] ?? 0)])) {
                    throw new DomainException(__('Invalid parent category.'));
                }
            }

            $stmtUpd = $pdo->prepare("
                UPDATE categories
                SET name = :name,
                    slug = :slug,
                    description = :desc,
                    parent_id = :parent,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $ok = $stmtUpd->execute([
                ':name'   => $name,
                ':slug'   => $slug,
                ':desc'   => $description !== '' ? $description : null,
                ':parent' => $parent_id,
                ':id'     => $id
            ]);

            if ($ok) {
                do_action('admin_category_before_edit_commit', $id, $pdo, [
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'parent_id' => $parent_id,
                ]);
                $pdo->commit();
                adiwira_redirect_with_flash($return_to, 'success', __('Category updated successfully.'));
            } else {
                $pdo->rollBack();
                $errors[] = __('Failed to update category.');
            }
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('categories/edit.php update error: ' . $e->getMessage());
            $errors[] = __('Failed to update category.');
        }
    }
}
?>
<section class="adam-card">
  <h2><?=_e('Edit Category')?></h2>

  <form method="post" novalidate id="category-edit-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">

    <div class="adam-accordion"
         id="theme-meta-accordion"
         data-open="1">

      <button type="button"
              class="adam-accordion-toggle"
              aria-expanded="true"
              aria-controls="theme-meta-body">
          <?= svg_ico('cog', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Category Settings')?>
          <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="theme-meta-body">
        <label><?=_e('Name')?><br>
          <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? $cat['name'], ENT_QUOTES, 'UTF-8') ?>" class="inpud">
        </label>

        <label><?=_e('Slug (optional)')?><br>
          <input type="text" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? $cat['slug'], ENT_QUOTES, 'UTF-8') ?>" class="inpud">
        </label>
      </div>
    </div>

    <label><?=_e('Parent (optional)')?><br>
      <select name="parent_id" class="inpud">
        <option value=""><?=_e('-- None --')?></option>
        <?php
        $selectedParent = isset($_POST['parent_id']) && $_POST['parent_id'] !== ''
            ? (int)$_POST['parent_id']
            : ($cat['parent_id'] !== null ? (int)$cat['parent_id'] : null);

        foreach ($flatten as $row):
            if ($row['id'] === (int)$id) continue;
            if (isset($descendants[$row['id']])) continue;
            $prefix = str_repeat('— ', max(0, $row['depth']));
        ?>
          <option value="<?= (int)$row['id'] ?>"
            <?= ($selectedParent !== null && (int)$selectedParent === (int)$row['id']) ? 'selected' : '' ?>>
            <?= $prefix . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label><?=_e('Description')?><br>
      <textarea name="description" style="width:100%;min-height:100px;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px"><?= htmlspecialchars($_POST['description'] ?? $cat['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    </label>

    <?php do_action('category_editor_after_fields', $cat, $pdo); ?>

    <p>
      <button type="submit" class="adam-button"><?=_e('Save Changes')?></button>
      <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Cancel')?></a>
    </p>
  </form>
</section>

<?php
if (!empty($errors) && function_exists('adiwira_bootstrap_toasts_script')) {
    $items = array_map(static fn($msg) => ['type' => 'error', 'message' => (string)$msg], $errors);
    echo adiwira_bootstrap_toasts_script($items);
}
?>

<script>
(function(){
  const form = document.getElementById('category-edit-form');
  if (!form) return;

  let confirmed = false;

  function askWarning(opts){
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
      return window.NewNotifConfirm.warning(opts);
    }
    return Promise.resolve(window.confirm(opts.message || '<?=__('Continue this action?')?>'));
  }

  form.addEventListener('submit', function(ev){
    if (confirmed) {
      confirmed = false;
      return;
    }

    ev.preventDefault();
    askWarning({
      title: '<?=__('Save category changes')?>',
      message: '<?=__('Changes to this category will be saved. Continue?')?>',
      confirmText: '<?=__('Yes, save')?>',
      cancelText: '<?=__('Cancel')?>'
    }).then(function(ok){
      if (!ok) return;
      confirmed = true;
      form.submit();
    });
  });
})();
</script>
