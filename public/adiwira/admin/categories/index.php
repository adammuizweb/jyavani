<?php
declare(strict_types=1);

// /adiwira/admin/categories/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
  http_response_code(403);
  exit('Forbidden');
}

require_once __DIR__ . '/../_guard.php';

[$uid, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);

$messages = [];
$errors   = [];

// legacy fallback
if (!empty($_GET['msg'])) $messages[] = urldecode((string)$_GET['msg']);
if (!empty($_GET['err'])) $errors[]   = urldecode((string)$_GET['err']);

// flash session
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$flash_for_js = [];
if (is_array($flash)) {
  foreach ($flash as $f) {
    $type = isset($f['type']) ? (string)$f['type'] : 'info';
    $text = isset($f['text']) ? (string)$f['text'] : '';
    if ($text === '') continue;

    if ($type === 'success') $messages[] = $text;
    else $errors[] = $text;

    $flash_for_js[] = ['type' => $type, 'text' => $text];
  }
}

$messages = array_values(array_unique($messages));
$errors   = array_values(array_unique($errors));

// filters
$search        = trim((string)($_GET['q'] ?? ''));
$filter_parent = (int)($_GET['parent'] ?? 0);
$filter_author = (int)($_GET['author'] ?? 0);

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;
$offset   = ($page_num - 1) * $per_page;

// query builder
$where  = ["c.is_deleted = 0"];
$params = [];

if ($search !== '') {
  $where[] = "(c.name LIKE :search OR c.slug LIKE :search)";
  $params[':search'] = '%' . $search . '%';
}
if ($filter_parent > 0) {
  $where[] = "c.parent_id = :parent_id";
  $params[':parent_id'] = $filter_parent;
}
if ($filter_author > 0) {
  $where[] = "c.created_by = :created_by";
  $params[':created_by'] = $filter_author;
}

$where_sql = implode(' AND ', $where);

// ambil kategori yang match filter
$sql = "
  SELECT 
    c.id,
    c.name,
    c.slug,
    c.description,
    c.parent_id,
    c.created_at,
    c.updated_at,
    c.created_by,
    COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), CAST(u.id AS CHAR)) AS created_by_label,
    SUM(
      CASE 
        WHEN p.id IS NOT NULL AND p.is_deleted = 0 AND p.type = 'article' THEN 1
        ELSE 0
      END
    ) AS post_count
  FROM categories c
  LEFT JOIN post_categories pc ON pc.category_id = c.id
  LEFT JOIN posts p ON p.id = pc.post_id
  LEFT JOIN users u ON u.id = c.created_by
  WHERE $where_sql
  GROUP BY c.id
  ORDER BY COALESCE(c.parent_id, 0) ASC, c.name ASC
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// jika search aktif, ambil semua ancestor agar tree tidak putus
if ($search !== '' && !empty($allCategories)) {
  $existingIds = array_map(fn($r) => (int)$r['id'], $allCategories);
  $needParents = [];

  foreach ($allCategories as $r) {
    $pid = $r['parent_id'];
    if ($pid !== null && $pid !== 0 && !in_array((int)$pid, $existingIds, true)) {
      $needParents[] = (int)$pid;
    }
  }
  $needParents = array_values(array_unique($needParents));

  while (!empty($needParents)) {
    $placeholders = implode(',', array_fill(0, count($needParents), '?'));
    $ancestorSql = "
      SELECT 
        c.id,
        c.name,
        c.slug,
        c.description,
        c.parent_id,
        c.created_at,
        c.updated_at,
        c.created_by,
        COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), CAST(u.id AS CHAR)) AS created_by_label,
        SUM(
          CASE 
            WHEN p.id IS NOT NULL AND p.is_deleted = 0 AND p.type = 'article' THEN 1
            ELSE 0
          END
        ) AS post_count
      FROM categories c
      LEFT JOIN post_categories pc ON pc.category_id = c.id
      LEFT JOIN posts p ON p.id = pc.post_id
      LEFT JOIN users u ON u.id = c.created_by
      WHERE c.id IN ($placeholders) AND c.is_deleted = 0
      GROUP BY c.id
    ";
    $stmt2 = $pdo->prepare($ancestorSql);
    $stmt2->execute($needParents);
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $nextMissing = [];
    foreach ($rows as $r) {
      $rid = (int)$r['id'];
      if (!in_array($rid, $existingIds, true)) {
        $allCategories[] = $r;
        $existingIds[] = $rid;
        $pp = $r['parent_id'];
        if ($pp !== null && $pp !== 0 && !in_array((int)$pp, $existingIds, true)) {
          $nextMissing[] = (int)$pp;
        }
      }
    }
    $needParents = array_values(array_unique($nextMissing));
  }
}

// map by id + children
$catsById = [];
$children = [];
foreach ($allCategories as $r) {
  $id  = (int)$r['id'];
  $pid = ($r['parent_id'] === null) ? 0 : (int)$r['parent_id'];
  $r['parent_id'] = $pid;
  $catsById[$id] = $r;
  $children[$pid][] = $id;
}

// helper path
$categoryPathCache = [];
$buildCategoryPath = function(int $catId) use (&$catsById, &$categoryPathCache): ?string {
  if (isset($categoryPathCache[$catId])) return $categoryPathCache[$catId];
  if (!isset($catsById[$catId])) {
    $categoryPathCache[$catId] = null;
    return null;
  }

  $segments = [];
  $cur = $catId;
  $seen = [];

  while ($cur && isset($catsById[$cur]) && !in_array($cur, $seen, true)) {
    $seen[] = $cur;
    $slug = (string)($catsById[$cur]['slug'] ?? '');
    if ($slug !== '') array_unshift($segments, $slug);
    $cur = (int)($catsById[$cur]['parent_id'] ?? 0);
    if ($cur === 0) break;
  }

  if (empty($segments)) {
    $categoryPathCache[$catId] = null;
    return null;
  }

  $path = implode('/', $segments);
  $categoryPathCache[$catId] = $path;
  return $path;
};

// flatten tree
$flatCats = [];
$visited = [];
$traverseCategories = function(int $parentId = 0, int $depth = 0) use (&$children, &$catsById, &$flatCats, &$visited, &$traverseCategories): void {
  if (empty($children[$parentId])) return;
  foreach ($children[$parentId] as $cid) {
    if (isset($visited[$cid])) continue;
    $visited[$cid] = true;
    $item = $catsById[$cid];
    $item['depth'] = $depth;
    $flatCats[] = $item;
    $traverseCategories($cid, $depth + 1);
  }
};
$traverseCategories(0, 0);

// pagination after flatten
$total = count($flatCats);
$pages = max(1, (int)ceil($total / $per_page));
if ($page_num > $pages) $page_num = $pages;
$offset = ($page_num - 1) * $per_page;

$categories_list = array_slice($flatCats, $offset, $per_page);

// build parent options
$parentOptions = [];
$visitedOptions = [];
$buildParentOptions = function(int $parentId = 0, int $depth = 0) use (&$children, &$catsById, &$parentOptions, &$visitedOptions, &$buildParentOptions): void {
  if (empty($children[$parentId])) return;
  foreach ($children[$parentId] as $cid) {
    if (isset($visitedOptions[$cid])) continue;
    $visitedOptions[$cid] = true;
    $label = str_repeat('— ', $depth) . (string)($catsById[$cid]['name'] ?? '');
    $parentOptions[] = ['id' => $cid, 'label' => $label];
    $buildParentOptions($cid, $depth + 1);
  }
};
$buildParentOptions(0, 0);

// authors for filter
$authorsStmt = $pdo->query("
  SELECT id, name, username
  FROM users
  WHERE is_deleted = 0
    AND is_locked = 0
  ORDER BY name ASC, username ASC
");
$authors = $authorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// base
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$canBulk   = in_array($role, ['editor', 'admin'], true);
$canDelete = in_array($role, ['editor', 'admin'], true);

if (!function_exists('build_pagination_items')) {
  function build_pagination_items(int $current, int $total, int $max_visible = 9): array {
    if ($total <= $max_visible) return range(1, $total);

    $items = [];
    $reserved = 6;
    $middle_slots = max(1, $max_visible - $reserved);
    $half = (int)floor($middle_slots / 2);
    $start = max(3, $current - $half);
    $end = min($total - 2, $current + $half);

    if ($start === 3) $end = min($total - 2, $start + $middle_slots - 1);
    if ($end === $total - 2) $start = max(3, $end - $middle_slots + 1);

    $items[] = 1;
    $items[] = 2;
    if ($start > 3) $items[] = '...';
    for ($i = $start; $i <= $end; $i++) $items[] = $i;
    if ($end < $total - 2) $items[] = '...';
    $items[] = $total - 1;
    $items[] = $total;

    while (count($items) > $max_visible) {
      for ($i = 0; $i < count($items); $i++) {
        if (is_int($items[$i]) && !in_array($items[$i], [1,2,$total-1,$total], true)) {
          array_splice($items, $i, 1);
          break;
        }
      }
    }
    return $items;
  }
}
$paging_items = build_pagination_items($page_num, $pages, 9);
?>

<section class="adam-card">
  <h2>Kategori</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/categories/index">

    <input type="text" name="q" placeholder="Cari nama atau slug..."
      value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
      style="padding:.4rem;min-width:200px">

    <select name="parent" style="padding:.4rem;">
      <option value="0">-- Semua Parent --</option>
      <?php foreach ($parentOptions as $opt): ?>
        <option value="<?= (int)$opt['id'] ?>" <?= $filter_parent === (int)$opt['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="author" style="padding:.4rem;">
      <option value="0">-- Semua Creator --</option>
      <?php foreach ($authors as $a):
        $label = $a['name'] ?: ($a['username'] ?: $a['id']);
      ?>
        <option value="<?= (int)$a['id'] ?>" <?= $filter_author === (int)$a['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($base . '/index.php?page=admin/categories/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Reset</a>
  </form>

  <p style="margin-bottom:1rem">
    <a class="adam-button" href="<?= htmlspecialchars($base . '/index.php?page=admin/categories/add', ENT_QUOTES, 'UTF-8') ?>">+ Tambah Kategori</a>
    <?php if ($role === 'admin') : ?>
      &nbsp;&nbsp;
      <a class="adam-att" href="<?= htmlspecialchars($base . '/index.php?page=admin/bin/category/index', ENT_QUOTES, 'UTF-8') ?>">🗑️ Trash</a>
    <?php endif; ?>
  </p>

  <?php if ($canBulk): ?>
    <form id="categoriesBulkForm"
          method="post"
          action="<?= htmlspecialchars($base . '/admin/categories/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>"
          onsubmit="return muizCategoriesOpenBulkConfirmModal(event,this)">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

      <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;">
        <label style="display:flex;align-items:center;gap:.4rem;">
          <input type="checkbox" id="selectAllCategories"> Pilih semua di halaman
        </label>

        <select id="bulkActionCategories" name="action" style="padding:.4rem;">
          <option value="">-- Bulk action --</option>
          <option value="delete">Hapus</option>
          <option value="change_parent">Ubah Parent</option>
        </select>

        <select id="bulkParentCategories" name="parent_id" style="padding:.4rem;display:none;">
          <option value="">-- Pilih Parent --</option>
          <option value="0">(Tanpa Parent)</option>
          <?php foreach ($parentOptions as $opt): ?>
            <option value="<?= (int)$opt['id'] ?>"><?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="adam-button">Terapkan</button>
        <small style="color:#666;margin-left:.5rem;">(Bulk akan mempengaruhi item yang dicentang)</small>
      </div>
  <?php else: ?>
    <div style="margin-bottom:1rem;color:#666;">Bulk actions disembunyikan untuk role <strong>author</strong>.</div>
  <?php endif; ?>

  <?php if (!empty($messages)): ?>
    <div class="adam-alert success auto-dismiss"
         data-dismiss-ms="3000"
         style="margin-bottom:1rem;padding:.8rem 1rem;background:#e8f7ec;border:1px solid #b6e2c2;border-radius:6px;color:#246;">
      <?php foreach ($messages as $m): ?><div><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="adam-alert error"
         style="margin-bottom:1rem;padding:.8rem 1rem;background:#fee;border:1px solid #fbb;color:#600;border-radius:6px;">
      <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($flash_for_js)): ?>
  <script>
  document.addEventListener('DOMContentLoaded', function(){
    if (typeof showToast !== 'function') return;
    const items = <?= json_encode($flash_for_js, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    items.forEach(it => {
      const t = (it.type === 'error') ? 'error' : (it.type === 'success' ? 'success' : 'info');
      showToast(it.text, t, t === 'error' ? 6500 : 4200);
    });
  });
  </script>
  <?php endif; ?>

  <div class="adam-table-wrapper">
    <table class="adam-table" style="margin-top:.5rem;">
      <thead>
        <tr>
          <th style="width:40px"></th>
          <th>Nama</th>
          <th>Posts</th>
          <th style="width:160px">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($categories_list)): ?>
          <tr><td colspan="4" style="padding:1rem;">Belum ada kategori.</td></tr>
        <?php else: ?>
          <?php foreach ($categories_list as $cat):
            $aCount = (int)$cat['post_count'];
            $depth  = max(0, (int)$cat['depth']);
            $catId  = (int)$cat['id'];

            $levelClass = 'cat-level-' . min($depth, 3);
            $icon = match ($depth) {
              0 => '📁',
              1 => '📂',
              default => '📄',
            };
            $indentHtml = '<span class="cat-indent ' . $levelClass . '">' . $icon . '</span>';

            $catPath = $buildCategoryPath($catId);
            if ($catPath !== null && $catPath !== '') {
              $segments = array_map('rawurlencode', explode('/', $catPath));
              $href = '/category/' . implode('/', $segments) . '/';
              $nameHtml = '<a class="adam-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$cat['name'], ENT_QUOTES, 'UTF-8') . '</a>';
            } else {
              $nameHtml = htmlspecialchars((string)$cat['name'], ENT_QUOTES, 'UTF-8');
            }
          ?>
            <tr>
              <td style="text-align:center;">
                <?php if ($canBulk): ?>
                  <input type="checkbox" class="bulkCheckboxCategory" name="ids[]" value="<?= $catId ?>">
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>

              <td><?= $indentHtml . $nameHtml ?></td>

              <td>
                <a class="count-badge<?= $aCount === 0 ? ' zero' : '' ?>"
                   href="<?= htmlspecialchars($base . '/index.php?page=admin/posts/index&category=' . $catId, ENT_QUOTES, 'UTF-8') ?>"
                   title="<?= $aCount === 0 ? 'Tidak ada artikel' : $aCount . ' artikel' ?>">
                  <?= $aCount ?>
                </a>
              </td>

              <td>
                <a class="adam-ubah" href="<?= htmlspecialchars($base . '/index.php?page=admin/categories/edit&id=' . $catId, ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                <?php if ($canDelete): ?>
                  &nbsp;<span class="muted-divider">|</span>&nbsp;
                  <button type="button"
                          class="adam-hapus"
                          data-id="<?= $catId ?>"
                          data-name="<?= htmlspecialchars((string)($cat['name'] ?? ''), ENT_QUOTES) ?>"
                          onclick="muizCategoriesOpenDeleteModal(this)">
                    Hapus
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canBulk): ?>
    </form>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
    <nav class="adam-pagination" style="margin-top:1rem;">
      <?php foreach ($paging_items as $item):
        if ($item === '...') { echo '<span class="dots">…</span> '; continue; }
        $i = (int)$item;
        $query = $_GET;
        $query['p'] = $i;
        $link = $base . '/index.php?' . http_build_query($query);
      ?>
        <?php if ($i === $page_num): ?>
          <strong><?= $i ?></strong>
        <?php else: ?>
          <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <div id="categoriesDeleteModal" class="adam-modal" role="dialog" aria-modal="true" aria-labelledby="categoriesDeleteModalTitle" style="display:none;">
    <div class="adam-modal__panel" role="document">
      <h3 id="categoriesDeleteModalTitle" class="adam-modal__title">Konfirmasi Hapus</h3>
      <p id="categoriesDeleteText" class="adam-modal__text">Apakah kamu yakin?</p>

      <div class="adam-modal__actions">
        <button type="button" class="adam-btn adam-btn--ghost" onclick="muizCategoriesCloseDeleteModal()">Batal</button>

        <form id="categoriesDeleteForm" method="post" action="<?= htmlspecialchars($base . '/admin/categories/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="id" id="categoriesDeleteId" value="">
          <button type="submit" class="adam-btn adam-btn--danger">Hapus</button>
        </form>
      </div>
    </div>
  </div>

  <div id="categoriesBulkConfirmModal" class="adam-modal" role="dialog" aria-modal="true" aria-labelledby="categoriesBulkConfirmTitle" style="display:none;">
    <div class="adam-modal__panel" role="document">
      <h3 id="categoriesBulkConfirmTitle" class="adam-modal__title">Konfirmasi Bulk Action</h3>
      <p id="categoriesBulkConfirmText" class="adam-modal__text">...</p>

      <div class="adam-modal__actions">
        <button type="button" class="adam-btn adam-btn--ghost" onclick="muizCategoriesCloseBulkConfirmModal()">Batal</button>
        <button type="button" class="adam-btn adam-btn--danger" id="categoriesBulkConfirmYes">Lanjutkan</button>
      </div>
    </div>
  </div>

  <script>
  const selectAllCategories = document.getElementById('selectAllCategories');
  if (selectAllCategories) {
    selectAllCategories.addEventListener('change', function(){
      const checked = this.checked;
      document.querySelectorAll('.bulkCheckboxCategory').forEach(cb => cb.checked = checked);
    });
  }

  const bulkActionCategories = document.getElementById('bulkActionCategories');
  if (bulkActionCategories) {
    bulkActionCategories.addEventListener('change', function(){
      const bulkParent = document.getElementById('bulkParentCategories');
      if (bulkParent) bulkParent.style.display = (this.value === 'change_parent') ? 'inline-block' : 'none';
    });
  }

  function muizCategoriesOpenDeleteModal(btn){
    const modal = document.getElementById('categoriesDeleteModal');
    if (!modal) return;

    if (modal.parentNode !== document.body) document.body.appendChild(modal);

    const id = btn?.dataset?.id || '';
    const name = btn?.dataset?.name || '';

    const idInput = document.getElementById('categoriesDeleteId');
    const txt = document.getElementById('categoriesDeleteText');
    if (idInput) idInput.value = id;
    if (txt) txt.innerText = `Hapus kategori "${name}"? (Kategori yang punya subkategori aktif tidak bisa dihapus)`;

    modal.onclick = function(e){ if (e.target === modal) muizCategoriesCloseDeleteModal(); };
    const panel = modal.querySelector('.adam-modal__panel');
    if (panel) panel.addEventListener('click', (e)=>e.stopPropagation(), { once:true });

    document.addEventListener('keydown', muizCategoriesDeleteEsc);

    modal.classList.add('show');
    modal.style.display = 'flex';
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
  }

  function muizCategoriesCloseDeleteModal(){
    const modal = document.getElementById('categoriesDeleteModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.style.display = 'none';
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    document.removeEventListener('keydown', muizCategoriesDeleteEsc);
  }

  function muizCategoriesDeleteEsc(e){
    if (e.key === 'Escape') muizCategoriesCloseDeleteModal();
  }

  let _catBulkFormRef = null;

  function muizCategoriesGetBulkSummary(){
    const action = document.getElementById('bulkActionCategories')?.value || '';
    const checked = Array.from(document.querySelectorAll('.bulkCheckboxCategory:checked'));
    const count = checked.length;

    const actionLabel = (() => {
      const sel = document.getElementById('bulkActionCategories');
      if (!sel) return action;
      const opt = sel.options[sel.selectedIndex];
      return opt ? (opt.textContent || action) : action;
    })();

    if (!action) return { ok:false, message:'Pilih bulk action terlebih dahulu.' };
    if (count < 1) return { ok:false, message:'Pilih minimal satu kategori.' };

    if (action === 'delete') {
      return { ok:true, type:'danger', title:`Konfirmasi: ${actionLabel}`, message:`Kamu akan memindahkan ${count} kategori ke Trash. (Kategori parent yang masih punya subkategori aktif akan gagal). Lanjutkan?` };
    }

    if (action === 'change_parent') {
      const parentEl = document.getElementById('bulkParentCategories');
      const v = parentEl?.value ?? '';
      if (v === '') return { ok:false, message:'Pilih parent (atau pilih Tanpa Parent).' };

      const label = (v === '0')
        ? '(Tanpa Parent)'
        : (parentEl && parentEl.selectedIndex >= 0 ? (parentEl.options[parentEl.selectedIndex].textContent || '') : '');

      return { ok:true, type:'info', title:`Konfirmasi: ${actionLabel}`, message:`Kamu akan mengubah parent untuk ${count} kategori menjadi "${label}". Lanjutkan?` };
    }

    return { ok:true, type:'info', title:'Konfirmasi', message:`Jalankan "${actionLabel}" untuk ${count} kategori?` };
  }

  function muizCategoriesOpenBulkConfirmModal(ev, form){
    ev.preventDefault();
    _catBulkFormRef = form;

    const sum = muizCategoriesGetBulkSummary();
    if (!sum.ok) {
      if (typeof showToast === 'function') showToast(sum.message, 'error', 4500);
      else alert(sum.message);
      return false;
    }

    const modal = document.getElementById('categoriesBulkConfirmModal');
    const panel = modal?.querySelector('.adam-modal__panel');
    const titleEl = document.getElementById('categoriesBulkConfirmTitle');
    const textEl  = document.getElementById('categoriesBulkConfirmText');
    const yesBtn  = document.getElementById('categoriesBulkConfirmYes');

    if (titleEl) titleEl.textContent = sum.title || 'Konfirmasi Bulk Action';
    if (textEl)  textEl.textContent  = sum.message || 'Lanjutkan?';

    if (yesBtn) {
      yesBtn.classList.remove('adam-btn--danger');
      if (sum.type === 'danger') yesBtn.classList.add('adam-btn--danger');

      yesBtn.onclick = function(){
        muizCategoriesCloseBulkConfirmModal();
        if (_catBulkFormRef) _catBulkFormRef.submit();
      };
    }

    if (modal.parentNode !== document.body) document.body.appendChild(modal);

    if (panel) panel.addEventListener('click', (e)=>e.stopPropagation(), { once:true });
    modal.onclick = function(e){ if (e.target === modal) muizCategoriesCloseBulkConfirmModal(); };
    document.addEventListener('keydown', muizCategoriesBulkEsc);

    modal.classList.add('show');
    modal.style.display = 'flex';
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';

    setTimeout(()=>{ try{ yesBtn && yesBtn.focus(); }catch(e){} }, 0);
    return false;
  }

  function muizCategoriesCloseBulkConfirmModal(){
    const modal = document.getElementById('categoriesBulkConfirmModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.style.display = 'none';
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    document.removeEventListener('keydown', muizCategoriesBulkEsc);
  }

  function muizCategoriesBulkEsc(e){
    if (e.key === 'Escape') muizCategoriesCloseBulkConfirmModal();
  }
  </script>
</section>

<style>
.count-badge{
  display:inline-block;
  min-width:28px;
  padding:.18rem .5rem;
  font-size:.85rem;
  line-height:1;
  text-align:center;
  border-radius:999px;
  border:1px solid rgba(30,100,200,.15);
  background:rgba(30,100,200,.06);
  color:#1e64c8;
  text-decoration:none;
  transition: background .12s ease, transform .06s ease;
}
.count-badge.zero{
  background:transparent;
  border-color:rgba(0,0,0,.06);
  color:#6b6b6b;
}
.count-badge:hover,.count-badge:focus{
  background:rgba(30,100,200,.12);
  transform:translateY(-1px);
  text-decoration:none;
  outline:none;
}
.count-badge:focus{ box-shadow:0 0 0 3px rgba(30,100,200,.12); }

.cat-indent{
  display:inline-flex;
  align-items:center;
  width:32px;
  justify-content:center;
  margin-right:4px;
  opacity:.85;
}
.cat-level-0{ font-weight:600; color:#1f3a5f; }
.cat-level-1{ margin-left:18px; color:#2c5282; }
.cat-level-2{ margin-left:36px; color:#4a5568; }
.cat-level-3{ margin-left:54px; color:#6b7280; }
tbody tr:hover .cat-indent{ opacity:1; }

@media (max-width:600px){
  .count-badge{ min-width:24px; padding:.12rem .45rem; font-size:.78rem; }
}
</style>