<?php
// /adiwira/admin/categories/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

$messages = [];
$errors = [];
if (!empty($_GET['msg'])) $messages[] = urldecode($_GET['msg']);
if (!empty($_GET['err'])) $errors[] = urldecode($_GET['err']);

// filters / search
$search = trim($_GET['q'] ?? '');
$filter_parent = (int)($_GET['parent'] ?? 0);
$filter_author = (int)($_GET['author'] ?? 0);

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20; // ubah ke 10 jika mau lebih kecil
$offset = ($page_num - 1) * $per_page;

// build where + params
$where = ["c.is_deleted = 0"];
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

// total count
$count_sql = "SELECT COUNT(*) FROM categories c WHERE $where_sql";
$countStmt = $pdo->prepare($count_sql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// ----------------- Mulai REPLACE: ambil semua kategori & bangun tree (gantikan block fetch categories lama) -----------------
// fetch categories (tanpa LIMIT/OFFSET) — akan kita proses jadi hirarki
$sql = "
  SELECT 
    c.id, c.name, c.slug, c.description, c.parent_id, c.created_at, c.updated_at,
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
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// Jika ada search, pastikan juga mengambil semua ancestor
// (parent → grandparent → ...) agar traversal dari root tidak memutus.
// --------------------------------------------------
if ($search !== '' && !empty($allCategories)) {
    // kumpulkan id yang sudah ada
    $existingIds = array_map(fn($r) => (int)$r['id'], $allCategories);
    $needParents = [];

    // cari parent_id yang belum ada di $existingIds
    foreach ($allCategories as $r) {
        $pid = $r['parent_id'];
        if ($pid !== null && $pid !== 0 && !in_array((int)$pid, $existingIds, true)) {
            $needParents[] = (int)$pid;
        }
    }
    $needParents = array_values(array_unique($needParents));

    // iteratively walk up the chain, menambahkan parent yang belum diambil
    while (!empty($needParents)) {
        // ambil parent rows untuk id yg ada di $needParents
        $placeholders = implode(',', array_fill(0, count($needParents), '?'));
        $ancestorSql = "
          SELECT 
            c.id, c.name, c.slug, c.description, c.parent_id, c.created_at, c.updated_at,
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
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // reset list for next iteration (collect parent of these ancestors if still missing)
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

        // dedup and continue
        $needParents = array_values(array_unique($nextMissing));
    }
}

// Build map by id and children lists
$catsById = [];
$children = [];
foreach ($allCategories as $r) {
    $id = (int)$r['id'];
    $pid = ($r['parent_id'] === null) ? 0 : (int)$r['parent_id'];
    $r['parent_id'] = $pid;
    $catsById[$id] = $r;
    $children[$pid][] = $id;
}

// helper: bangun path kategori (cache) -> "parent/child/grandchild"
$categoryPathCache = [];
function build_category_path(array $catsById, int $catId, array &$cache): ?string {
    if (isset($cache[$catId])) return $cache[$catId];

    if (!isset($catsById[$catId])) {
        $cache[$catId] = null;
        return null;
    }

    $segments = [];
    $cur = $catId;
    $seen = [];

    while ($cur && isset($catsById[$cur]) && !in_array($cur, $seen, true)) {
        $seen[] = $cur;
        $slug = $catsById[$cur]['slug'] ?? '';
        if ($slug !== '') {
            array_unshift($segments, $slug);
        }
        $cur = $catsById[$cur]['parent_id'] ?? 0;
        if ($cur === 0) break;
    }

    if (empty($segments)) {
        $cache[$catId] = null;
        return null;
    }

    $path = implode('/', $segments);
    $cache[$catId] = $path;
    return $path;
}

// recursive flatten (parent -> children), preserving alphabetical order already ensured by SQL
$flatCats = [];
$visited = [];

/**
 * traverse_categories
 * - parameter by-ref (arrays) before optional ones to avoid deprecation warnings
 */
function traverse_categories(array $children, array $catsById, array &$flatCats, array &$visited, int $parentId = 0, int $depth = 0) {
    if (empty($children[$parentId])) return;
    foreach ($children[$parentId] as $cid) {
        if (isset($visited[$cid])) continue; // safety against cycles
        $visited[$cid] = true;
        $item = $catsById[$cid];
        $item['depth'] = $depth;
        $flatCats[] = $item;
        // recurse into children
        traverse_categories($children, $catsById, $flatCats, $visited, $cid, $depth + 1);
    }
}
// Start from root (parent_id = NULL stored as 0)
traverse_categories($children, $catsById, $flatCats, $visited, 0, 0);

// Recompute pagination based on flattened count
$total = count($flatCats);
$pages = max(1, (int)ceil($total / $per_page));
if ($page_num > $pages) $page_num = $pages;
$offset = ($page_num - 1) * $per_page;

// slice for display on current page
$pages_list = array_slice($flatCats, $offset, $per_page);

// build parent options (hierarchical) for the filter/bulk dropdowns
$parentOptions = [];
$visitedOptions = [];
function build_parent_options(array $children, array $catsById, array &$parentOptions, array &$visitedOptions, int $parentId = 0, int $depth = 0) {
    if (empty($children[$parentId])) return;
    foreach ($children[$parentId] as $cid) {
        if (isset($visitedOptions[$cid])) continue;
        $visitedOptions[$cid] = true;
        $label = str_repeat('— ', $depth) . ($catsById[$cid]['name'] ?? '');
        $parentOptions[] = ['id' => $cid, 'label' => $label];
        build_parent_options($children, $catsById, $parentOptions, $visitedOptions, $cid, $depth + 1);
    }
}
build_parent_options($children, $catsById, $parentOptions, $visitedOptions, 0, 0);

// ----------------- Akhir REPLACE -----------------

// fetch parent categories for filter / bulk (exclude deleted)
$parentsStmt = $pdo->query("SELECT id, name FROM categories WHERE is_deleted = 0 ORDER BY name ASC");
$parents = $parentsStmt->fetchAll(PDO::FETCH_ASSOC);

// fetch authors for filter / bulk
$authorsStmt = $pdo->query("SELECT id, name, username FROM users WHERE is_deleted = 0 ORDER BY name ASC, username ASC");
$authors = $authorsStmt->fetchAll(PDO::FETCH_ASSOC);

// base url
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$role = current_user_role($pdo) ?: 'guest';
$canBulk = !in_array($role, ['author'], true); // only non-authors can bulk

/**
 * pagination helper (same pattern)
 */
function build_pagination_items(int $current, int $total, int $max_visible = 9): array {
    if ($total <= $max_visible) {
        return range(1, $total);
    }
    $items = [];
    $reserved = 6;
    $middle_slots = max(1, $max_visible - $reserved);
    $half = (int)floor($middle_slots / 2);
    $start = max(3, $current - $half);
    $end = min($total - 2, $current + $half);
    if ($start === 3) {
        $end = min($total - 2, $start + $middle_slots - 1);
    }
    if ($end === $total - 2) {
        $start = max(3, $end - $middle_slots + 1);
    }
    $items[] = 1;
    $items[] = 2;
    if ($start > 3) $items[] = '...';
    for ($i = $start; $i <= $end; $i++) $items[] = $i;
    if ($end < $total - 2) $items[] = '...';
    $items[] = $total - 1;
    $items[] = $total;
    while (count($items) > $max_visible) {
        for ($i = 0; $i < count($items); $i++) {
            if (is_int($items[$i]) && $items[$i] !== 1 && $items[$i] !== 2 && $items[$i] !== $total - 1 && $items[$i] !== $total) {
                array_splice($items, $i, 1);
                break;
            }
        }
    }
    return $items;
}
$paging_items = build_pagination_items($page_num, $pages, 9);
?>

<section class="adam-card">
  <h2>Kategori</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/categories/index">
    <input type="text" name="q" placeholder="Cari nama atau slug..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;min-width:200px">

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
          <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($base . '/index.php?page=admin/categories/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Reset</a>
  </form>

  <p style="margin-bottom:1rem">
    <a class="adam-button" href="<?= htmlspecialchars($base . '/index.php?page=admin/categories/add', ENT_QUOTES, 'UTF-8') ?>">
      + Tambah Kategori
    </a>
    <?php if ($userRole === 'admin') : ?>
    &nbsp;&nbsp;
    <a class="adam-att" href="<?= htmlspecialchars($base . '/index.php?page=admin/bin/category/index', ENT_QUOTES, 'UTF-8') ?>">
      🗑️ Trash
    </a>
    <?php endif; ?>
  </p>

  <?php if ($canBulk): ?>
  <form method="post" action="<?= htmlspecialchars($base . '/admin/categories/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirmBulkCategories()">
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
        <option value="">-- Pilih Parent (kosong = none) --</option>
        <option value="0">(Tanpa Parent)</option>
        <?php foreach ($parents as $pr): ?>
          <option value="<?= (int)$pr['id'] ?>"><?= htmlspecialchars($pr['name'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="adam-button">Terapkan</button>
      <small style="color:#666;margin-left:.5rem;">(Bulk akan mempengaruhi item yang dicentang)</small>
    </div>
  <?php else: ?>
    <div style="margin-bottom:1rem;color:#666;">Bulk actions disembunyikan untuk role <strong>author</strong>.</div>
  <?php endif; ?>

  <?php if (!empty($messages)): ?>
    <div class="adam-alert success" style="margin-bottom:1rem;padding:.8rem 1rem;background:#e8f7ec;border:1px solid #b6e2c2;border-radius:6px;color:#246;">
      <?php foreach ($messages as $m): ?><div><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="adam-alert error" style="margin-bottom:1rem;padding:.8rem 1rem;background:#fee;border:1px solid #fbb;color:#600;border-radius:6px;">
      <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="adam-table-wrapper">
<table style="width:100%;border-collapse:collapse">
  <thead>
    <tr style="text-align:left;border-bottom:1px solid #eee">
      <th style="padding:.6rem;width:40px"></th>
      <th style="padding:.6rem">Nama</th>
      <th style="padding:.6rem; display: none;">Slug</th>
      <th style="padding:.6rem">Posts</th>
      <th style="padding:.6rem; display: none;">Dibuat</th>
      <th style="padding:.6rem">Aksi</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($pages_list)): ?>
      <tr><td colspan="6" style="padding:.75rem">Belum ada kategori.</td></tr>
    <?php else: ?>
      <?php foreach ($pages_list as $cat): 
        $aCount = (int)$cat['post_count'];
        $depth = max(0, (int)$cat['depth']);
        $catId = (int)$cat['id'];

        // indentasi visual
        $levelClass = 'cat-level-' . min($depth, 3);

$icon = match ($depth) {
    0 => '📁',   // parent
    1 => '📂',   // child
    default => '📄', // grandchild+
};

$indentHtml = '<span class="cat-indent ' . $levelClass . '">' . $icon . '</span>';


        // build path dan href (encode setiap segmen)
        $catPath = build_category_path($catsById, $catId, $categoryPathCache);
        if ($catPath !== null && $catPath !== '') {
            $segments = array_map('rawurlencode', explode('/', $catPath));
            $href = '/category/' . implode('/', $segments) . '/';
            // jika menggunakan subfolder base, uncomment baris berikut dan hapus baris $href di atas:
            // $href = rtrim($base, '/') . '/category/' . implode('/', $segments) . '/';
            $nameHtml = '<a class="adam-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') . '</a>';
        } else {
            $nameHtml = htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8');
        }
      ?>
        <tr>
          <td style="text-align:center;padding:.6rem">
            <?php if ($canBulk): ?>
              <input type="checkbox" class="bulkCheckboxCategory" name="ids[]" value="<?= $catId ?>">
            <?php else: ?>
              &mdash;
            <?php endif; ?>
          </td>

          <td style="padding:.6rem"><?= $indentHtml . $nameHtml ?></td>
          <td style="padding:.6rem; display: none;"><?= htmlspecialchars($cat['slug'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="padding:.6rem">
            <a
              class="count-badge<?= $aCount === 0 ? ' zero' : '' ?>"
              href="<?= htmlspecialchars($base . '/index.php?page=admin/posts/index&category=' . $catId . '&type=article', ENT_QUOTES, 'UTF-8') ?>"
              title="<?= $aCount === 0 ? 'Tidak ada artikel' : $aCount . ' artikel' ?>"
              aria-label="<?= $aCount === 0 ? 'Tidak ada artikel' : $aCount . ' artikel' ?>"
            >
              <?= $aCount ?>
            </a>
          </td>
          <td style="padding:.6rem; display: none;"><?= htmlspecialchars($cat['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="padding:.6rem">
            <a class="adam-link" href="<?= htmlspecialchars($base . '/index.php?page=admin/categories/edit&id=' . $catId, ENT_QUOTES, 'UTF-8') ?>">Edit</a>
            &nbsp;|&nbsp;
            <button type="button" class="adam-link-button" onclick="confirmSingleCategoryDelete(<?= $catId ?>, '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>')">Hapus</button>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>

  </div>

  <?php if ($canBulk): ?></form><?php endif; ?>

  <?php if ($pages > 1): ?>
    <nav class="adam-pagination" style="margin-top:1rem">
      <?php foreach ($paging_items as $item):
        if ($item === '...') { echo '<span class="dots">…</span> '; continue; }
        $i = (int)$item;
        $query = $_GET;
        $query['p'] = $i;
        $link = $base . '/index.php?' . http_build_query($query);
      ?>
        <?php if ($i === $page_num): ?><strong style="margin-right:.5rem;"><?= $i ?></strong>
        <?php else: ?><a style="margin-right:.5rem" href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a><?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <!-- global single-delete form (outside bulk form to avoid nested forms) -->
  <form id="singleDeleteCategoryForm" method="post" action="<?= htmlspecialchars($base . '/admin/categories/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" id="singleDeleteCategoryCsrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" id="singleDeleteCategoryId" value="">
  </form>

  <script>
    // alerts auto-hide
    setTimeout(() => {
      const alert = document.querySelector('.adam-alert');
      if (alert) { alert.style.transition = 'opacity 0.5s ease'; alert.style.opacity = '0'; setTimeout(() => alert.remove(), 600); }
    }, 3000);

    // select all
    const selectAllCategories = document.getElementById('selectAllCategories');
    if (selectAllCategories) {
      selectAllCategories.addEventListener('change', function() {
        const checked = this.checked;
        document.querySelectorAll('.bulkCheckboxCategory').forEach(cb => cb.checked = checked);
      });
    }

    // show/hide bulk controls
    const bulkActionCategories = document.getElementById('bulkActionCategories');
    if (bulkActionCategories) {
      bulkActionCategories.addEventListener('change', function() {
        const bulkParent = document.getElementById('bulkParentCategories');
        if (bulkParent) bulkParent.style.display = (this.value === 'change_parent') ? 'inline-block' : 'none';
      });
    }

    // confirm bulk
    function confirmBulkCategories() {
      const actionEl = document.getElementById('bulkActionCategories');
      if (!actionEl) return true;
      const action = actionEl.value;
      if (!action) { alert('Pilih bulk action terlebih dahulu.'); return false; }
      const any = Array.from(document.querySelectorAll('.bulkCheckboxCategory')).some(cb => cb.checked);
      if (!any) { alert('Pilih minimal satu kategori.'); return false; }
      if (action === 'delete') return confirm('Yakin ingin menghapus kategori terpilih? (akan menandai is_deleted = 1 dan menghapus relasi ke post)'); 
      if (action === 'change_parent') {
        const parent = document.getElementById('bulkParentCategories').value;
        if (parent === '') { alert('Pilih parent (atau pilih (Tanpa Parent)).'); return false; }
        return confirm('Yakin ingin mengubah parent kategori untuk semua kategori terpilih?');
      }
      return true;
    }

    // single delete via global form
    function confirmSingleCategoryDelete(id, csrf) {
      if (!confirm('Hapus kategori ini?')) return;
      const f = document.getElementById('singleDeleteCategoryForm');
      if (!f) { alert('Form hapus tidak ditemukan.'); return; }
      document.getElementById('singleDeleteCategoryId').value = id;
      document.getElementById('singleDeleteCategoryCsrf').value = csrf;
      f.submit();
    }
  </script>
</section>
<style>
/* badge untuk jumlah posts */
.count-badge {
  display: inline-block;
  min-width: 28px;
  padding: 0.18rem 0.5rem;
  font-size: 0.85rem;
  line-height: 1;
  text-align: center;
  border-radius: 999px;
  border: 1px solid rgba(30, 100, 200, 0.15);
  background: rgba(30, 100, 200, 0.06);
  color: #1e64c8; /* warna teks badge */
  text-decoration: none; /* hapus underline */
  transition: background .12s ease, transform .06s ease;
  box-shadow: 0 0 0 2px transparent;
}

/* badge untuk zero (muted) */
.count-badge.zero {
  background: transparent;
  border-color: rgba(0,0,0,0.06);
  color: #6b6b6b;
}

/* hover / focus states */
.count-badge:hover,
.count-badge:focus {
  background: rgba(30, 100, 200, 0.12);
  transform: translateY(-1px);
  text-decoration: none;
  outline: none;
}

/* keyboard focus visible */
.count-badge:focus {
  box-shadow: 0 0 0 3px rgba(30,100,200,0.12);
}
/* ===== Category hierarchy styling ===== */

.cat-indent {
  display: inline-flex;
  align-items: center;
  width: 32px;
  justify-content: center;
  margin-right: 4px;
  opacity: 0.85;
}

/* Parent */
.cat-level-0 {
  font-weight: 600;
  color: #1f3a5f;
}

/* Child */
.cat-level-1 {
  margin-left: 18px;
  color: #2c5282;
}

/* Grandchild */
.cat-level-2 {
  margin-left: 36px;
  color: #4a5568;
}

/* Deeper levels (fallback) */
.cat-level-3 {
  margin-left: 54px;
  color: #6b7280;
}

/* Hover clarity */
tbody tr:hover .cat-indent {
  opacity: 1;
}

/* small responsive tweak */
@media (max-width: 600px) {
  .count-badge { min-width: 24px; padding: 0.12rem 0.45rem; font-size: .78rem; }
}
</style>
