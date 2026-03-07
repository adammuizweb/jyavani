<?php
// /adiwira/admin/posts/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

$errors = [];
$messages = [];

// pastikan session hidup (flash ada di session)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// dukung err dari redirect (delete.php pakai err)
if (!empty($_GET['err'])) {
  $errors[] = urldecode((string)$_GET['err']);
}

// msg dari query (kalau ada)
if (!empty($_GET['msg'])) {
  $messages[] = urldecode((string)$_GET['msg']);
}

// ===== ambil flash dari bulk_action.php =====
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']); // penting biar tidak muncul terus setiap refresh

$flash_for_js = []; // opsional: untuk toast
if (is_array($flash)) {
  foreach ($flash as $f) {
    $type = isset($f['type']) ? (string)$f['type'] : 'info';
    $text = isset($f['text']) ? (string)$f['text'] : '';
    if ($text === '') continue;

    if ($type === 'success') $messages[] = $text;
    else $errors[] = $text;

    $flash_for_js[] = ['type'=>$type, 'text'=>$text];
  }
}

$messages = array_values(array_unique($messages));
$errors   = array_values(array_unique($errors));
// ambil filter dari query
$filter_status   = $_GET['status']   ?? '';
$filter_category = $_GET['category'] ?? '';
$search          = trim($_GET['q'] ?? '');

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 15;
$offset   = ($page_num - 1) * $per_page;

// =========================
// ROLE ENGINEERING (posts/index)
// =========================
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
  http_response_code(403);
  exit('Akses ditolak: belum login.');
}

$role = current_user_role($pdo) ?: ($_SESSION['user_role'] ?? 'guest');
$role = is_string($role) ? strtolower(trim($role)) : 'guest';
$_SESSION['user_role'] = $role; // sync biar konsisten

// base query
$where = ["p.is_deleted = 0", "p.type = 'article'"];
$params = [];

// author + editor hanya boleh lihat post miliknya sendiri
if (in_array($role, ['author','editor'], true)) {
  $where[] = "p.created_by = :uid";
  $params[':uid'] = $uid;
}

// filter status
if ($filter_status !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $filter_status;
}

// filter kategori
if ($filter_category !== '') {
    $where[] = "c.id = :category_id";
    $params[':category_id'] = $filter_category;
}

// pencarian (title/slug)
if ($search !== '') {
    $where[] = "(p.title LIKE :search OR p.slug LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

// count total (distinct post.id, karena join kategori bisa duplikat)
$count_sql = "
SELECT COUNT(DISTINCT p.id)
FROM posts p
LEFT JOIN post_categories pc ON pc.post_id = p.id
LEFT JOIN categories c ON c.id = pc.category_id AND c.is_deleted = 0
WHERE $where_sql
";
$totalStmt = $pdo->prepare($count_sql);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// ambil data post
$sql = "
SELECT 
  p.id, p.title, p.slug, p.status, p.created_at,
  u.name AS created_by,
  u.username AS author_username,
  GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') AS categories,
  GROUP_CONCAT(DISTINCT c.id SEPARATOR ',') AS category_ids
FROM posts p
LEFT JOIN post_categories pc ON pc.post_id = p.id
LEFT JOIN categories c ON c.id = pc.category_id AND c.is_deleted = 0
LEFT JOIN users u ON u.id = p.created_by
WHERE $where_sql
GROUP BY p.id
ORDER BY p.created_at DESC
LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ambil semua kategori (slug, parent_id, name) agar bisa membangun path kategori lengkap
$allCatsStmt = $pdo->query("SELECT id, slug, parent_id, name FROM categories WHERE is_deleted = 0");
$allCatsRows = $allCatsStmt->fetchAll(PDO::FETCH_ASSOC);
$catsMap = [];
foreach ($allCatsRows as $r) {
    $catsMap[(int)$r['id']] = [
        'slug' => $r['slug'],
        'parent_id' => $r['parent_id'] === null ? null : (int)$r['parent_id'],
        'name' => $r['name'],
    ];
}

// helper: build category path (array of slugs from root -> ... -> leaf), returns string like "parent/child/grandchild"
$categoryPathCache = [];
function build_category_path(array $catsMap, int $catId, array &$cache) : ?string {
    if (isset($cache[$catId])) {
        return $cache[$catId];
    }

    if (!isset($catsMap[$catId])) {
        $cache[$catId] = null;
        return null;
    }

    $segments = [];
    $cur = $catId;
    $seen = [];
    while ($cur !== null && isset($catsMap[$cur]) && !in_array($cur, $seen, true)) {
        $seen[] = $cur;
        array_unshift($segments, $catsMap[$cur]['slug']);
        $cur = $catsMap[$cur]['parent_id'] ?? null;
    }

    if (empty($segments)) {
        $cache[$catId] = null;
        return null;
    }

    $path = implode('/', $segments);
    $cache[$catId] = $path;
    return $path;
}

// helper kecil: deterministic hue per category id (DEKLARASI SEKALI DI SINI)
if (!function_exists('cat_hue')) {
    function cat_hue(int $id): int {
        return ($id * 57) % 360; // deterministic mapping -> hue 0..359
    }
}

// ambil kategori untuk dropdown / panel checkbox
$catStmt = $pdo->query("SELECT id, name FROM categories WHERE is_deleted = 0 ORDER BY name ASC");
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// base url
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$role = current_user_role($pdo) ?: 'guest';
$canBulk = in_array($role, ['admin','editor','author'], true);
/**
 * helper: render pagination compact (maksimum item tampil = $max_visible, termasuk ellipses)
 * returns array of items (numbers or '...')
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

    if ($start > 3) {
        $items[] = '...';
    }

    for ($i = $start; $i <= $end; $i++) {
        $items[] = $i;
    }

    if ($end < $total - 2) {
        $items[] = '...';
    }

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
  <h2>Posts (Article)</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/posts/index">
    <input type="text" name="q" placeholder="Cari judul atau slug..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;min-width:200px">
    
    <select name="status" style="padding:.4rem;">
      <option value="">-- Semua Status --</option>
      <option value="draft" <?= $filter_status==='draft'?'selected':'' ?>>Draft</option>
      <option value="published" <?= $filter_status==='published'?'selected':'' ?>>Published</option>
      <option value="private" <?= $filter_status==='private'?'selected':'' ?>>Private</option>
    </select>

    <select name="category" style="padding:.4rem;">
      <option value="">-- Semua Kategori --</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= (int)$cat['id'] ?>" <?= $filter_category==$cat['id']?'selected':'' ?>>
          <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($base . '/index.php?page=admin/posts/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Reset</a>
  </form>

<p style="margin-bottom:1rem">
  <a class="adam-button" href="<?= htmlspecialchars($base . '/index.php?page=admin/posts/artikel', ENT_QUOTES, 'UTF-8') ?>">+ Tambah Article</a>
<?php if ($role === 'admin') : ?>
  &nbsp;&nbsp;
  <a class="adam-att" href="<?= htmlspecialchars($base . '/index.php?page=admin/bin/article/index', ENT_QUOTES, 'UTF-8') ?>">🗑️ Trash</a>
<?php endif; ?>
</p>

 <?php if ($canBulk): ?>
  <!-- BULK ACTION FORM (render hanya untuk admin/editor dll) -->
<form id="bulkForm" method="post" action="/adiwira/admin/posts/bulk_action.php" onsubmit="return openBulkConfirmModal(event,this)">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAll"> Pilih semua di halaman
      </label>

      <select id="bulkAction" name="action" style="padding:.4rem;">
        <option value="">-- Bulk action --</option>
        <option value="delete">Hapus</option>
        <option value="change_status">Ubah Status</option>
        <option value="change_categories">Kelola Kategori (cek)</option>
      </select>

      <select id="bulkStatus" name="status" style="padding:.4rem;display:none;">
        <option value="draft">Draft</option>
        <option value="published">Published</option>
        <option value="private">Private</option>
      </select>

      <select id="bulkCatMode" name="cat_mode" style="padding:.4rem;display:none;">
        <option value="add">Tambah (Add)</option>
        <option value="remove">Hapus (Remove)</option>
        <option value="toggle">Toggle (add/remove)</option>
      </select>

      <div id="bulkCategoriesPanel" style="display:none;border:1px solid #eee;padding:.4rem;border-radius:6px;max-height:160px;overflow:auto;">
        <?php foreach ($categories as $cat): ?>
          <label style="display:block;white-space:nowrap;">
            <input type="checkbox" name="categories[]" value="<?= (int)$cat['id'] ?>">
            <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
          </label>
        <?php endforeach; ?>
      </div>

      <button type="submit" class="adam-button">Terapkan</button>
      <small style="color:#666;margin-left:.5rem;">(Bulk akan mempengaruhi item yang dicentang)</small>
    </div>
<?php else: ?>
  <div style="margin-bottom:1rem;color:#666;">
    Bulk actions disembunyikan untuk role <strong>author</strong>.
  </div>
<?php endif; ?>

<?php if (!empty($messages)): ?>
  <div class="adam-alert success auto-dismiss"
       data-dismiss-ms="3000"
       style="margin-bottom:1rem;padding:.8rem 1rem;background:#e8f7ec;border:1px solid #b6e2c2;border-radius:6px;color:#246;">
    <?php foreach ($messages as $m): ?>
      <div><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="adam-alert error"
       style="margin-bottom:1rem;padding:.8rem 1rem;background:#fee;border:1px solid #fbb;color:#600;border-radius:6px;">
    <?php foreach ($errors as $e): ?>
      <div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
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
      <th>Judul</th>
      <th>Status</th>
      <th>Kategori</th>
      <th>Dibuat</th>
      <th>Penulis</th>
      <th>Aksi</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($posts)): ?>
      <tr><td colspan="7" style="padding:1rem;">Tidak ada artikel ditemukan.</td></tr>
    <?php else: ?>
      <?php foreach ($posts as $p): ?>
<?php
// per-post (di dalam loop)
$status = strtolower(trim($p['status'] ?? 'unknown'));
$statusClass = in_array($status, ['published','draft','private'], true) ? $status : 'unknown';

// simple SVG icons (small, inline)
$icons = [
  'published' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  'draft'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M3 21v-3l11-11 3 3L6 21H3z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  'private'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
  'unknown'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.4"/><path d="M9.5 9a2.5 2.5 0 1 1 5 1c0 1.5-1.5 1.75-1.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="17.2" r="0.6" fill="currentColor"/></svg>',
];
$iconSvg = $icons[$statusClass] ?? $icons['unknown'];
?>
        <tr class="adam-row">
          <td style="text-align:center;">
            <?php if ($canBulk): ?>
              <input type="checkbox" class="bulkCheckbox" name="ids[]" value="<?= (int)$p['id'] ?>">
            <?php else: ?>
              &mdash;
            <?php endif; ?>
          </td>

          <!-- Judul -->
          <td>
            <?php
              $postSlug = trim((string)$p['slug']);
              $titleHref = $postSlug !== '' ? '/' . rawurlencode($postSlug) . '/' : '#';
            ?>
            <a class="adam-link" href="<?= htmlspecialchars($titleHref, ENT_QUOTES, 'UTF-8') ?>"
               title="<?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>">
               <?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          </td>

          <!-- Status (per-item) -->
          <td>
            <span class="adam-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                  role="status" aria-label="<?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>">
              <span class="adam-status-icon"><?= $iconSvg ?></span>
              <span class="adam-status-text"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
            </span>
          </td>

          <!-- Kategori -->
          <td>
            <?php
              if (!empty($p['category_ids'])) {
                $catIds = array_filter(array_map('trim', explode(',', $p['category_ids'])));
                $catLinks = [];
                foreach ($catIds as $cidRaw) {
                  $cid = (int)$cidRaw;
                  $catName = $catsMap[$cid]['name'] ?? '';
                  $catPath = build_category_path($catsMap, $cid, $categoryPathCache);

                  if ($catPath !== null && $catPath !== '') {
                    $catHref = '/category/' . implode('/', array_map('rawurlencode', explode('/', $catPath))) . '/';
                  } else {
                    // fallback: link to category by id or plain name
                    $catHref = '/admin/categories/view.php?id=' . $cid;
                  }

                  // set hue with helper (cat_hue declared once above)
                  $hue = cat_hue($cid);

                  // build colors
$hue = cat_hue($cid);

$catLinks[] = '<a class="adam-category" href="' . htmlspecialchars($catHref, ENT_QUOTES, 'UTF-8') . '"'
              . ' style="--cat-h:' . (int)$hue . ';"'
              . ' title="' . htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') . '">'
              . htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') . '</a>';
                }
                echo implode(' ', $catLinks);
              } else {
                echo '<span class="adam-category-empty">-</span>';
              }
            ?>
          </td>

          <!-- Dibuat -->
          <td><?= htmlspecialchars(format_date_ddmmyyyy_time_bracket($p['created_at']), ENT_QUOTES, 'UTF-8') ?></td>

          <!-- Penulis -->
          <td>
            <?php
              $authorName = $p['created_by'] ?? '-';
              $authorUsername = $p['author_username'] ?? '';
              if ($authorUsername !== '') {
                $authorHref = '/author/' . rawurlencode($authorUsername) . '/';
                echo '<a class="adam-penulis" href="' . htmlspecialchars($authorHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') . '</a>';
              } else {
                echo htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8');
              }
            ?>
          </td>

          <!-- Aksi -->
          <td>
            <a class="adam-ubah" href="<?= htmlspecialchars($base . '/index.php?page=admin/posts/edit&id=' . (int)$p['id']) ?>">Edit</a>
            &nbsp;<span class="muted-divider">|</span>&nbsp;
            <button type="button"
              class="adam-hapus"
              data-id="<?= (int)$p['id'] ?>"
              data-title="<?= htmlspecialchars($p['title'], ENT_QUOTES) ?>"
              onclick="openDeleteModal(this)">
              Hapus
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($canBulk): ?>
  </form> <!-- akhir bulkForm -->
<?php endif; ?>

<?php if ($pages > 1): ?>
  <nav class="adam-pagination" style="margin-top:1rem;">
    <?php foreach ($paging_items as $item): 
      if ($item === '...') {
        echo '<span class="dots">…</span> ';
        continue;
      }
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


<!-- 🧱 MODAL KONFIRMASI HAPUS (single) -->
<div id="deleteModal" class="adam-modal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle" style="display:none;">
  <div class="adam-modal__panel" role="document">
    <h3 id="deleteModalTitle" class="adam-modal__title">Konfirmasi Hapus</h3>
    <p id="deleteText" class="adam-modal__text">Apakah kamu yakin ingin menghapus artikel ini?</p>

    <div class="adam-modal__actions">
      <button type="button" class="adam-btn adam-btn--ghost" onclick="closeDeleteModal()">Batal</button>

      <form id="deleteForm" method="post" action="<?= htmlspecialchars($base . '/admin/posts/delete.php') ?>" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="id" id="deleteId">
        <button type="submit" class="adam-btn adam-btn--danger">Hapus</button>
      </form>
    </div>
  </div>
</div>

<!-- 🧱 MODAL KONFIRMASI BULK -->
<div id="bulkConfirmModal"
     class="adam-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="bulkConfirmTitle"
     style="display:none;">
  <div class="adam-modal__panel" role="document">
    <h3 id="bulkConfirmTitle" class="adam-modal__title">Konfirmasi Bulk Action</h3>
    <p id="bulkConfirmText" class="adam-modal__text">...</p>

    <div class="adam-modal__actions">
      <button type="button" class="adam-btn adam-btn--ghost" onclick="closeBulkConfirmModal()">Batal</button>
      <button type="button" class="adam-btn adam-btn--danger" id="bulkConfirmYes">Lanjutkan</button>
    </div>
  </div>
</div>

<script>
function openDeleteModal(btn) {
  document.getElementById('deleteModal').style.display = 'flex';
  document.getElementById('deleteId').value = btn.dataset.id;
  document.getElementById('deleteText').innerText = `Hapus artikel "${btn.dataset.title}"?`;
}
function closeDeleteModal() {
  document.getElementById('deleteModal').style.display = 'none';
}

// select all toggle
const selectAll = document.getElementById('selectAll');
if (selectAll) {
  selectAll.addEventListener('change', function() {
    var checked = this.checked;
    document.querySelectorAll('.bulkCheckbox').forEach(cb => cb.checked = checked);
  });
}

// show/hide extra controls depending on action
const bulkActionEl = document.getElementById('bulkAction');
if (bulkActionEl) {
  bulkActionEl.addEventListener('change', function() {
    var v = this.value;
    const bulkStatus = document.getElementById('bulkStatus');
    const bulkCatMode = document.getElementById('bulkCatMode');
    const bulkCategoriesPanel = document.getElementById('bulkCategoriesPanel');
    if (bulkStatus) bulkStatus.style.display = (v === 'change_status') ? 'inline-block' : 'none';
    if (bulkCatMode) bulkCatMode.style.display = (v === 'change_categories') ? 'inline-block' : 'none';
    if (bulkCategoriesPanel) bulkCategoriesPanel.style.display = (v === 'change_categories') ? 'block' : 'none';
  });
}


// confirm bulk action
/* =========================
   BULK CONFIRM MODAL LOGIC
   ========================= */
let _bulkFormRef = null;

function getBulkSummary() {
  const action = document.getElementById('bulkAction')?.value || '';
  const checkedIds = Array.from(document.querySelectorAll('.bulkCheckbox:checked')).map(x => x.value);
  const count = checkedIds.length;

  // helper ambil label selected option
  const actionLabel = (() => {
    const sel = document.getElementById('bulkAction');
    if (!sel) return action;
    const opt = sel.options[sel.selectedIndex];
    return opt ? (opt.textContent || action) : action;
  })();

  // validasi dasar
  if (!action) return { ok:false, message:'Pilih bulk action terlebih dahulu.' };
  if (count < 1) return { ok:false, message:'Pilih minimal satu artikel.' };

  // detail per action
  if (action === 'delete') {
    return {
      ok:true,
      type:'danger',
      message:`Kamu akan menghapus (soft delete) ${count} artikel. Lanjutkan?`,
      title:`Konfirmasi: ${actionLabel}`
    };
  }

  if (action === 'change_status') {
    const st = document.getElementById('bulkStatus')?.value || '';
    return {
      ok:true,
      type:'info',
      message:`Kamu akan mengubah status ${count} artikel menjadi "${st}". Lanjutkan?`,
      title:`Konfirmasi: ${actionLabel}`
    };
  }

  if (action === 'change_categories') {
    const mode = document.getElementById('bulkCatMode')?.value || 'add';
    const selectedCats = Array.from(document.querySelectorAll('#bulkCategoriesPanel input[type=checkbox]:checked'));
    const catCount = selectedCats.length;

    // kamu sebelumnya allow lanjut walau kosong -> kita ikutin, tapi teksnya jelas
    const modeLabel = (mode === 'remove') ? 'hapus' : (mode === 'toggle' ? 'toggle' : 'tambah');
    const catText = catCount
      ? `${catCount} kategori (${modeLabel})`
      : `TIDAK ADA kategori dipilih (tidak ada perubahan kategori)`;

    return {
      ok:true,
      type:'info',
      message:`Kamu akan menerapkan perubahan kategori untuk ${count} artikel: ${catText}. Lanjutkan?`,
      title:`Konfirmasi: ${actionLabel}`
    };
  }

  return { ok:true, type:'info', message:`Jalankan "${actionLabel}" untuk ${count} artikel?`, title:'Konfirmasi' };
}

function openBulkConfirmModal(ev, form){
  ev.preventDefault();
  _bulkFormRef = form;

  const sum = getBulkSummary();
  if (!sum.ok) {
    // kalau muiz toast ada, pakai toast. fallback: alert.
    if (typeof showToast === 'function') showToast(sum.message, 'error', 4500);
    else alert(sum.message);
    return false;
  }

  const modal = document.getElementById('bulkConfirmModal');
  const panel = modal?.querySelector('.adam-modal__panel');
  const titleEl = document.getElementById('bulkConfirmTitle');
  const textEl = document.getElementById('bulkConfirmText');
  const yesBtn = document.getElementById('bulkConfirmYes');

  if (titleEl) titleEl.textContent = sum.title || 'Konfirmasi Bulk Action';
  if (textEl) textEl.textContent = sum.message || 'Lanjutkan?';

  // set tombol danger hanya untuk delete (biar UX jelas)
  if (yesBtn) {
    yesBtn.classList.remove('adam-btn--danger');
    yesBtn.classList.add('adam-btn--danger'); // default danger feels OK
if (sum.type !== 'danger') {
  yesBtn.classList.remove('adam-btn--danger');
}

    // rebind handler aman
    yesBtn.onclick = function(){
      closeBulkConfirmModal();
      // submit form secara native (tanpa trigger onsubmit lagi)
      if (_bulkFormRef) _bulkFormRef.submit();
    };
  }

  // stop propagation biar klik panel tidak nutup
  if (panel) panel.addEventListener('click', (e) => e.stopPropagation(), { once:true });

  // klik overlay untuk close
  modal.onclick = function(e){
    if (e.target === modal) closeBulkConfirmModal();
  };

  // ESC close
  document.addEventListener('keydown', onBulkEsc);

  // show modal
  modal.style.display = 'flex';
  modal.classList.add('show');
  document.documentElement.style.overflow = 'hidden';
  document.body.style.overflow = 'hidden';

  // fokus tombol lanjutkan biar cepat
  setTimeout(()=> { try{ yesBtn && yesBtn.focus(); }catch(e){} }, 0);

  return false;
}

function closeBulkConfirmModal(){
  const modal = document.getElementById('bulkConfirmModal');
  if (!modal) return;
  modal.classList.remove('show');
  modal.style.display = 'none';
  document.documentElement.style.overflow = '';
  document.body.style.overflow = '';
  document.removeEventListener('keydown', onBulkEsc);
}

function onBulkEsc(e){
  if (e.key === 'Escape') closeBulkConfirmModal();
}
</script>

</section>
