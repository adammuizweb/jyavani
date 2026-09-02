<?php
declare(strict_types=1);

// /adiwira/admin/categories/add.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.categories.create', false);

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
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/?page=admin/categories/index')
    : ($base . '/?page=admin/categories/index');

$errors = [];

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

    if ($parent_id !== null && $parent_id <= 0) {
        $parent_id = null;
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
        $stmt = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE slug = :slug
              AND is_deleted = 0
            LIMIT 1
        ");
        $stmt->execute([':slug' => $slug]);
        if ($stmt->fetch()) {
            $errors[] = __('Slug already taken. Please use another slug.');
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            if (!authorization_lock_actor_permissions($pdo, $uid)) {
                throw new DomainException('Category actor permission lock failed.');
            }
            if (!user_can($pdo, $uid, 'core.categories.create')) {
                throw new DomainException('Category create permission changed.');
            }
            $lockedCategories = $pdo->query(
                'SELECT id, slug, created_by, is_deleted FROM categories ORDER BY id FOR UPDATE'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $parentCategory = null;
            foreach ($lockedCategories as $lockedCategory) {
                if ((string)$lockedCategory['slug'] === $slug) {
                    throw new DomainException(__('Slug already taken. Please use another slug.'));
                }
                if ($parent_id !== null && (int)$lockedCategory['id'] === $parent_id && (int)$lockedCategory['is_deleted'] === 0) {
                    $parentCategory = $lockedCategory;
                }
            }
            if ($parent_id !== null) {
                $parentOwnerId = is_array($parentCategory) ? (int)($parentCategory['created_by'] ?? 0) : 0;
                if (!$parentCategory || !authorization_lock_owner_contexts($pdo, [$parentOwnerId])
                    || !user_can($pdo, $uid, 'core.categories.read', ['owner_id' => $parentOwnerId])) {
                    throw new DomainException(__('Invalid parent category.'));
                }
            }
            $stmt = $pdo->prepare("
                INSERT INTO categories (name, slug, description, parent_id, created_by, created_at, updated_at)
                VALUES (:name, :slug, :desc, :parent, :created_by, NOW(), NOW())
            ");
            $ok = $stmt->execute([
                ':name'       => $name,
                ':slug'       => $slug,
                ':desc'       => $description !== '' ? $description : null,
                ':parent'     => $parent_id,
                ':created_by' => $uid,
            ]);

            if ($ok) {
                $categoryId = (int)$pdo->lastInsertId();
                do_action('admin_category_before_add_commit', $categoryId, $pdo, [
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'parent_id' => $parent_id,
                    'created_by' => $uid,
                ]);
                $pdo->commit();
                adiwira_redirect_with_flash($return_to, 'success', __('Category saved successfully.'));
            } else {
                $pdo->rollBack();
                $errors[] = __('Failed to add category.');
            }
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('categories/add.php insert error: ' . $e->getMessage());
            $errors[] = __('Failed to add category.');
        }
    }
}

$readCondition = authorization_owner_scope_condition(
    $pdo,
    $uid,
    'core.categories.read',
    'categories.created_by',
    'category_add_read'
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
?>
<section class="adam-card">
  <h2><?=_e('Add Category')?></h2>

  <form method="post" novalidate id="category-add-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">
    
    <div class="adam-accordion"
         id="theme-meta-accordion"
         data-open="1">

      <button type="button"
              class="adam-accordion-toggle"
              aria-expanded="true"
              aria-controls="theme-meta-body">
          <?= svg_ico('cog') ?> <?=_e('Category Settings')?>
          <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="theme-meta-body">
        <label><?=_e('Name')?><br>
          <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">
        </label>

        <label><?=_e('Slug (optional)')?><br>
          <input type="text" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="inpud">
        </label>
      </div>
    </div>

    <label><?=_e('Parent (optional)')?><br>
      <select name="parent_id" class="inp w-full">
        <option value=""><?=_e('-- None --')?></option>
        <?php
        $selectedParent = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
        foreach ($flatten as $row):
            $prefix = str_repeat('— ', max(0, $row['depth']));
        ?>
          <option value="<?= (int)$row['id'] ?>" <?= ($selectedParent !== null && $selectedParent === (int)$row['id']) ? 'selected' : '' ?>>
            <?= $prefix . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label><?=_e('Description')?><br>
      <textarea name="description" class="inp w-full" style="min-height:100px"><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    </label>

    <p><button type="submit" class="adam-button"><?=_e('Save')?></button> <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Cancel')?></a></p>
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
  const form = document.getElementById('category-add-form');
  if (!form) return;

  let confirmed = false;

  function askWarning(opts){
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
      return window.NewNotifConfirm.warning(opts);
    }
    return Promise.resolve(window.confirm(opts.message || '<?=__('Proceed with this action?')?>'));
  }

  form.addEventListener('submit', function(ev){
    if (confirmed) {
      confirmed = false;
      return;
    }

    ev.preventDefault();
    askWarning({
      title: '<?=__('Save category')?>',
      message: '<?=__('New category will be saved. Continue?')?>',
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
