<?php
// /adiwira/admin/bin/category/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
  http_response_code(403);
  exit('Forbidden');
}
if (session_status() === PHP_SESSION_NONE) session_start();

$errors = [];
$messages = [];
if (!empty($_GET['msg'])) $messages[] = urldecode($_GET['msg']);
if (!empty($_GET['err'])) $errors[]   = urldecode($_GET['err']);

function adiwira_root(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos = strpos($base, '/admin');
  return ($pos !== false) ? substr($base, 0, $pos) : $base; // => /adiwira
}
$root = adiwira_root();

// auth
$uid  = (int)($_SESSION['user_id'] ?? 0);
$role = current_user_role($pdo) ?: 'guest';
if ($uid <= 0) $errors[] = 'Akses ditolak: belum login.';

// filters
$search        = trim((string)($_GET['q'] ?? ''));
$filter_parent = (int)($_GET['parent'] ?? 0);
$filter_author = (int)($_GET['author'] ?? 0);

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;
$offset   = ($page_num - 1) * $per_page;

// where
$where  = ["c.is_deleted = 1"];
$params = [];

if ($role === 'author') {
  $where[] = "c.created_by = :uid";
  $params[':uid'] = $uid;
}

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

// count
$count_sql = "SELECT COUNT(*) FROM categories c WHERE $where_sql";
$totalStmt = $pdo->prepare($count_sql);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// data
$sql = "
SELECT
  c.id, c.name, c.slug, c.parent_id, c.created_at, c.deleted_at,
  COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), CAST(u.id AS CHAR)) AS created_by_label,
  p2.name AS parent_name,
  SUM(CASE WHEN p.id IS NOT NULL AND p.is_deleted=0 AND p.type='article' THEN 1 ELSE 0 END) AS post_count
FROM categories c
LEFT JOIN categories p2 ON p2.id = c.parent_id
LEFT JOIN post_categories pc ON pc.category_id = c.id
LEFT JOIN posts p ON p.id = pc.post_id
LEFT JOIN users u ON u.id = c.created_by
WHERE $where_sql
GROUP BY c.id
ORDER BY c.deleted_at DESC, c.id DESC
LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$cats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// parent options (hierarki) - ambil semua kategori (deleted & active) biar filter nyaman
$optStmt = $pdo->query("SELECT id, name, parent_id, is_deleted FROM categories ORDER BY COALESCE(parent_id,0) ASC, name ASC");
$optAll = $optStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$children = [];
$byId = [];
foreach ($optAll as $r) {
  $id = (int)$r['id'];
  $pid = (int)($r['parent_id'] ?? 0);
  $byId[$id] = $r;
  $children[$pid][] = $id;
}
$parentOptions = [];
$seen = [];
$buildOpt = function($pid, $depth) use (&$buildOpt, &$children, &$byId, &$parentOptions, &$seen) {
  if (empty($children[$pid])) return;
  foreach ($children[$pid] as $cid) {
    if (isset($seen[$cid])) continue;
    $seen[$cid] = true;
    $r = $byId[$cid];
    $suffix = ((int)($r['is_deleted'] ?? 0) === 1) ? ' (trash)' : '';
    $label = str_repeat('— ', $depth) . ($r['name'] ?? '-') . $suffix;
    $parentOptions[] = ['id' => $cid, 'label' => $label];
    $buildOpt($cid, $depth + 1);
  }
};
$buildOpt(0, 0);

// authors
$authorsStmt = $pdo->query("SELECT id, name, username FROM users WHERE is_deleted=0 ORDER BY name ASC, username ASC");
$authors = $authorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function build_pagination_items(int $current, int $total, int $max_visible = 9): array {
  if ($total <= $max_visible) return range(1, $total);

  $items = [];
  $reserved = 6;
  $middle_slots = max(1, $max_visible - $reserved);
  $half = (int)floor($middle_slots / 2);

  $start = max(3, $current - $half);
  $end   = min($total - 2, $current + $half);

  if ($start === 3) $end = min($total - 2, $start + $middle_slots - 1);
  if ($end === $total - 2) $start = max(3, $end - $middle_slots + 1);

  $items[] = 1; $items[] = 2;
  if ($start > 3) $items[] = '...';
  for ($i = $start; $i <= $end; $i++) $items[] = $i;
  if ($end < $total - 2) $items[] = '...';
  $items[] = $total - 1; $items[] = $total;

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
$paging_items = build_pagination_items($page_num, $pages, 9);

$canBulk = ($role !== 'author');
?>

<section class="adam-card">
  <h2>Bin / Trash — Categories</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/bin/category/index">

    <input type="text" name="q" placeholder="Cari nama/slug..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;min-width:220px">

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
    <a href="<?= htmlspecialchars($root . '/index.php?page=admin/bin/category/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-link">Reset</a>

    <span style="margin-left:auto;color:#666;">
      Total trash: <strong><?= (int)$total ?></strong>
    </span>
  </form>

  <?php if (!empty($messages)): ?>
    <div class="adam-alert success" style="margin-bottom:1rem;padding:.8rem 1rem;background:#e8f7ec;border:1px solid #b6e2c2;border-radius:6px;color:#246;">
      <?php foreach ($messages as $m): ?><div><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="adam-alert error" style="margin-bottom:1rem;padding:.8rem 1rem;background:#fee;border:1px solid #fbb;border-radius:6px;color:#600;">
      <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($canBulk): ?>
    <form method="post" action="<?= htmlspecialchars($root . '/admin/bin/category/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirmBinBulk()">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

      <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.6rem;flex-wrap:wrap;">
        <label style="display:flex;align-items:center;gap:.4rem;">
          <input type="checkbox" id="selectAll"> Pilih semua di halaman
        </label>

        <select id="bulkAction" name="action" style="padding:.4rem;">
          <option value="">-- Bulk action --</option>
          <option value="restore">Restore</option>
          <option value="delete_permanent">Hapus Permanen</option>
        </select>

        <button type="submit" class="adam-button">Terapkan</button>
        <small style="color:#666;">(Bulk mempengaruhi item yang dicentang)</small>
      </div>
  <?php else: ?>
      <div style="margin-bottom:1rem;color:#666;">
        Bulk actions disembunyikan untuk role <strong>author</strong>.
      </div>
  <?php endif; ?>

  <div class="adam-table-wrapper">
    <table class="adam-table" style="margin-top:.5rem;">
      <thead>
        <tr>
          <th style="width:40px"></th>
          <th>Nama</th>
          <th>Slug</th>
          <th>Parent</th>
          <th>Posts</th>
          <th>Deleted</th>
          <th>Creator</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($cats)): ?>
        <tr><td colspan="8" style="padding:1rem;">Trash kosong.</td></tr>
      <?php else: ?>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td style="text-align:center;">
              <?php if ($canBulk): ?>
                <input type="checkbox" class="bulkCheckbox" name="ids[]" value="<?= (int)$c['id'] ?>">
              <?php else: ?>
                &mdash;
              <?php endif; ?>
            </td>
            <td style="font-weight:600;"><?= htmlspecialchars($c['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($c['slug'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($c['parent_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= (int)($c['post_count'] ?? 0) ?></td>
            <td><?= htmlspecialchars(!empty($c['deleted_at']) ? format_date_ddmmyyyy_time_bracket($c['deleted_at']) : '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($c['created_by_label'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <button type="button" class="adam-link-button"
                data-id="<?= (int)$c['id'] ?>"
                data-title="<?= htmlspecialchars($c['name'] ?? '', ENT_QUOTES) ?>"
                onclick="openBinModal('restore', this)">Restore</button>
              &nbsp;|&nbsp;
              <button type="button" class="adam-link-button"
                data-id="<?= (int)$c['id'] ?>"
                data-title="<?= htmlspecialchars($c['name'] ?? '', ENT_QUOTES) ?>"
                onclick="openBinModal('delete_permanent', this)">Hapus Permanen</button>
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
        $query['page'] = 'admin/bin/category/index';
        $link = $root . '/index.php?' . http_build_query($query);
      ?>
        <?php if ($i === $page_num): ?><strong><?= $i ?></strong>
        <?php else: ?><a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a><?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
</section>

<!-- Modal aksi -->
<div id="binModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);align-items:center;justify-content:center;z-index:3000;">
  <div style="background:#fff;padding:1.5rem;border-radius:8px;max-width:420px;width:90%;box-shadow:0 4px 16px rgba(0,0,0,0.2);">
    <h3 id="binTitle" style="margin-top:0">Konfirmasi</h3>
    <p id="binText">...</p>
    <div style="margin-top:1rem;text-align:right;">
      <button type="button" onclick="closeBinModal()" style="padding:.4rem .8rem;background:#ccc;border:0;border-radius:6px;margin-right:.5rem;">Batal</button>
      <form id="binForm" method="post" action="#" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="id" id="binId">
        <button id="binSubmit" type="submit" style="padding:.4rem .8rem;background:#246;color:#fff;border:0;border-radius:6px;">OK</button>
      </form>
    </div>
  </div>
</div>

<script>
function openBinModal(type, btn) {
  const id = btn.dataset.id;
  const title = btn.dataset.title || '';
  const modal = document.getElementById('binModal');
  const form = document.getElementById('binForm');

  document.getElementById('binId').value = id;

  if (type === 'restore') {
    document.getElementById('binTitle').innerText = 'Restore Kategori';
    document.getElementById('binText').innerText  = `Restore "${title}"?`;
    document.getElementById('binSubmit').innerText = 'Restore';
    document.getElementById('binSubmit').style.background = '#246';
    form.action = "<?= htmlspecialchars($root . '/admin/bin/category/restore.php', ENT_QUOTES, 'UTF-8') ?>";
  } else {
    document.getElementById('binTitle').innerText = 'Hapus Permanen';
    document.getElementById('binText').innerText  = `Hapus permanen "${title}"? Ini tidak bisa dibatalkan.`;
    document.getElementById('binSubmit').innerText = 'Hapus Permanen';
    document.getElementById('binSubmit').style.background = '#c33';
    form.action = "<?= htmlspecialchars($root . '/admin/bin/category/delete_permanent.php', ENT_QUOTES, 'UTF-8') ?>";
  }
  modal.style.display = 'flex';
}

function closeBinModal() {
  document.getElementById('binModal').style.display = 'none';
}

const selectAll = document.getElementById('selectAll');
if (selectAll) {
  selectAll.addEventListener('change', function() {
    const checked = this.checked;
    document.querySelectorAll('.bulkCheckbox').forEach(cb => cb.checked = checked);
  });
}

function confirmBinBulk() {
  const action = document.getElementById('bulkAction').value;
  if (!action) { alert('Pilih bulk action terlebih dahulu.'); return false; }

  const any = Array.from(document.querySelectorAll('.bulkCheckbox')).some(cb => cb.checked);
  if (!any) { alert('Pilih minimal satu kategori.'); return false; }

  if (action === 'delete_permanent') {
    return confirm('Yakin ingin menghapus permanen semua kategori terpilih? Ini tidak bisa dibatalkan.');
  }
  return true;
}
</script>
