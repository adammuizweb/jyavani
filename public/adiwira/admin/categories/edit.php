<?php
declare(strict_types=1);

// /adiwira/admin/categories/edit.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim($text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$errors = [];

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/index.php?page=admin/categories/index')
    : ($base . '/index.php?page=admin/categories/index');

if ($id <= 0) {
    http_response_code(400);
    echo '<p>ID kategori tidak valid.</p>';
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
    echo '<p>Kategori tidak ditemukan.</p>';
    return;
}

$cat['description'] = $cat['description'] ?? '';
$cat['name']        = isset($cat['name']) ? (string)$cat['name'] : '';
$cat['slug']        = isset($cat['slug']) ? (string)$cat['slug'] : '';
$cat['parent_id']   = isset($cat['parent_id']) && $cat['parent_id'] !== null ? (int)$cat['parent_id'] : null;
$cat['created_by']  = isset($cat['created_by']) ? (int)$cat['created_by'] : null;

// author hanya boleh edit miliknya sendiri
if ($role === 'author' && (int)$cat['created_by'] !== $uid) {
    http_response_code(403);
    echo '<p>Akses ditolak: kamu tidak boleh mengedit kategori ini.</p>';
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, parent_id
    FROM categories
    WHERE is_deleted = 0
");
$stmt->execute();
$allCats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$children = [];
foreach ($allCats as $c) {
    $pid = $c['parent_id'] === null ? 0 : (int)$c['parent_id'];
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

$descendants = [];
$collectDesc = function(int $start) use (&$children, &$descendants, &$collectDesc): void {
    if (!isset($children[$start])) return;
    foreach ($children[$start] as $c) {
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
        $errors[] = 'CSRF token tidak valid.';
    }

    $name        = trim((string)($_POST['name'] ?? ''));
    $slug        = trim((string)($_POST['slug'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $parent_id   = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;

    if ($name === '') {
        $errors[] = 'Nama kategori tidak boleh kosong.';
    }

    $slug = ($slug === '') ? slugify($name) : slugify($slug);

    if ($parent_id !== null && $parent_id === $id) {
        $errors[] = 'Parent tidak boleh sama dengan kategori sendiri.';
    }

    if ($parent_id !== null && empty($errors) && isset($descendants[$parent_id])) {
        $errors[] = 'Parent tidak boleh menjadi anak atau cucu dari kategori ini.';
    }

    if ($parent_id !== null && empty($errors)) {
        $stmtParent = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE id = :id
              AND is_deleted = 0
            LIMIT 1
        ");
        $stmtParent->execute([':id' => $parent_id]);
        if (!$stmtParent->fetchColumn()) {
            $errors[] = 'Parent kategori tidak valid.';
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
            $errors[] = 'Slug sudah dipakai oleh kategori lain.';
        }
    }

    if (empty($errors)) {
        try {
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
                adiwira_redirect_with_flash($return_to, 'success', 'Kategori berhasil diperbarui.');
            } else {
                $errors[] = 'Gagal memperbarui kategori.';
            }
        } catch (Throwable $e) {
            error_log('categories/edit.php update error: ' . $e->getMessage());
            $errors[] = 'Gagal memperbarui kategori.';
        }
    }
}
?>
<section class="adam-card">
  <h2>Edit Kategori</h2>

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
          ⚙️ Pengaturan Category
          <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="theme-meta-body">
        <label>Nama<br>
          <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? $cat['name'], ENT_QUOTES, 'UTF-8') ?>" class="inpud">
        </label>

        <label>Slug (opsional)<br>
          <input type="text" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? $cat['slug'], ENT_QUOTES, 'UTF-8') ?>" class="inpud">
        </label>
      </div>
    </div>

    <label>Parent (opsional)<br>
      <select name="parent_id" class="inpud">
        <option value="">-- Tidak ada --</option>
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

    <label>Deskripsi<br>
      <textarea name="description" style="width:100%;min-height:100px;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px"><?= htmlspecialchars($_POST['description'] ?? $cat['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    </label>

    <p>
      <button type="submit" class="adam-button">Simpan Perubahan</button>
      <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Batal</a>
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
    return Promise.resolve(window.confirm(opts.message || 'Lanjutkan aksi ini?'));
  }

  form.addEventListener('submit', function(ev){
    if (confirmed) {
      confirmed = false;
      return;
    }

    ev.preventDefault();
    askWarning({
      title: 'Simpan perubahan kategori',
      message: 'Perubahan kategori ini akan disimpan. Lanjutkan?',
      confirmText: 'Ya, simpan',
      cancelText: 'Batal'
    }).then(function(ok){
      if (!ok) return;
      confirmed = true;
      form.submit();
    });
  });
})();
</script>