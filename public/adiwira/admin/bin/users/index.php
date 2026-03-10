<?php
// /adiwira/admin/bin/users/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
  http_response_code(403);
  exit('Forbidden');
}

if (session_status() === PHP_SESSION_NONE) session_start();

$errors = [];
$messages = [];
if (!empty($_GET['msg'])) $messages[] = urldecode((string)$_GET['msg']);
if (!empty($_GET['err'])) $errors[]   = urldecode((string)$_GET['err']);

function adiwira_root(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos = strpos($base, '/admin');
  return ($pos !== false) ? substr($base, 0, $pos) : $base;
}
$root = adiwira_root();

// auth: users bin hanya admin
$uid  = (int)($_SESSION['user_id'] ?? 0);
$role = current_user_role($pdo) ?: 'guest';

if ($uid <= 0) {
  $errors[] = 'Akses ditolak: belum login.';
}
if ($role !== 'admin') {
  $errors[] = 'Akses ditolak: bin users hanya untuk admin.';
}

// filters
$filter_role   = trim((string)($_GET['role'] ?? ''));
$filter_status = trim((string)($_GET['lock'] ?? '')); // locked | unlocked
$search        = trim((string)($_GET['q'] ?? ''));

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 15;
$offset   = ($page_num - 1) * $per_page;

// where
$where = ["is_deleted = 1"];
$params = [];

if ($filter_role !== '') {
  $where[] = "role = :role";
  $params[':role'] = $filter_role;
}

if ($filter_status === 'locked') {
  $where[] = "is_locked = 1";
} elseif ($filter_status === 'unlocked') {
  $where[] = "is_locked = 0";
}

if ($search !== '') {
  $where[] = "(name LIKE :search OR email LIKE :search OR username LIKE :search)";
  $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

// count
$total = 0;
$users = [];
$pages = 1;

if ($uid > 0 && $role === 'admin') {
  $count_sql = "SELECT COUNT(*) FROM users WHERE $where_sql";
  $totalStmt = $pdo->prepare($count_sql);
  $totalStmt->execute($params);
  $total = (int)$totalStmt->fetchColumn();
  $pages = max(1, (int)ceil($total / $per_page));

  if ($page_num > $pages) {
    $page_num = $pages;
    $offset = ($page_num - 1) * $per_page;
  }

  $sql = "
    SELECT id, email, username, name, role, img, bio, phone, created_at, updated_at, is_locked
    FROM users
    WHERE $where_sql
    ORDER BY updated_at DESC, id DESC
    LIMIT :limit OFFSET :offset
  ";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k => $v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

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

  $items[] = 1;
  $items[] = 2;
  if ($start > 3) $items[] = '...';
  for ($i = $start; $i <= $end; $i++) $items[] = $i;
  if ($end < $total - 2) $items[] = '...';
  $items[] = $total - 1;
  $items[] = $total;

  while (count($items) > $max_visible) {
    for ($i = 0; $i < count($items); $i++) {
      if (is_int($items[$i]) && !in_array($items[$i], [1, 2, $total - 1, $total], true)) {
        array_splice($items, $i, 1);
        break;
      }
    }
  }

  return $items;
}

$paging_items = build_pagination_items($page_num, $pages, 9);
$allRoles = ['author','editor','admin'];
$canBulk = ($uid > 0 && $role === 'admin');
?>

<section class="adam-card">
  <h2>Bin / Trash — Users</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/bin/users/index">

    <input type="text" name="q" placeholder="Cari nama, email atau username..."
      value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
      style="padding:.4rem;min-width:220px">

    <select name="role" style="padding:.4rem;">
      <option value="">-- Semua Role --</option>
      <?php foreach ($allRoles as $r): ?>
        <option value="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_role === $r ? 'selected' : '' ?>>
          <?= htmlspecialchars(ucfirst($r), ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="lock" style="padding:.4rem;">
      <option value="">-- Semua Status --</option>
      <option value="locked" <?= $filter_status === 'locked' ? 'selected' : '' ?>>Locked / Pending</option>
      <option value="unlocked" <?= $filter_status === 'unlocked' ? 'selected' : '' ?>>Unlocked / Approved</option>
    </select>

    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($root . '/index.php?page=admin/bin/users/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-link">Reset</a>

    <span style="margin-left:auto;color:#666;">
      Total trash: <strong><?= (int)$total ?></strong>
    </span>
  </form>

  <?php if (!empty($messages)): ?>
    <div class="adam-alert success" style="margin-bottom:1rem;">
      <?php foreach ($messages as $m): ?>
        <div><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="adam-alert error" style="margin-bottom:1rem;">
      <?php foreach ($errors as $e): ?>
        <div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($canBulk): ?>
    <form method="post" action="<?= htmlspecialchars($root . '/admin/bin/users/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirmUserBinBulk()">
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
        <small style="color:#666;">(Bulk mempengaruhi user yang dicentang)</small>
      </div>
  <?php endif; ?>

  <div class="adam-table-wrapper">
    <table class="adam-table" style="width:100%;border-collapse:collapse">
      <thead>
        <tr>
          <th style="width:40px"></th>
          <th>Avatar</th>
          <th>Nama</th>
          <th>Email / Username</th>
          <th>Role</th>
          <th>Status</th>
          <th>Phone</th>
          <th>Dihapus</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($users)): ?>
        <tr><td colspan="9" style="padding:1rem;">Trash user kosong.</td></tr>
      <?php else: ?>
        <?php foreach ($users as $u): ?>
          <?php
            $img = !empty($u['img']) ? (string)$u['img'] : '/static/img/person.svg';
            $isLocked = (int)($u['is_locked'] ?? 0) === 1;
            $labelName = (string)($u['name'] ?? ($u['email'] ?? 'User'));
          ?>
          <tr>
            <td style="text-align:center;">
              <?php if ($canBulk): ?>
                <input type="checkbox" class="bulkCheckbox" name="ids[]" value="<?= (int)$u['id'] ?>">
              <?php else: ?>
                &mdash;
              <?php endif; ?>
            </td>

            <td style="width:56px">
              <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>"
                   alt=""
                   style="height:40px;width:40px;object-fit:cover;border-radius:6px">
            </td>

            <td>
              <div style="font-weight:600;">
                <?= htmlspecialchars($u['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div style="color:#666;font-size:.85rem;">
                #<?= (int)$u['id'] ?>
              </div>
            </td>

            <td>
              <?= htmlspecialchars($u['email'] ?? '-', ENT_QUOTES, 'UTF-8') ?><br>
              <small style="color:#666"><?= htmlspecialchars($u['username'] ?? '-', ENT_QUOTES, 'UTF-8') ?></small>
            </td>

            <td><?= htmlspecialchars($u['role'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>

            <td>
              <?php if ($isLocked): ?>
                <span style="display:inline-block;padding:.22rem .55rem;border-radius:999px;background:#fff1f2;color:#b42318;border:1px solid #fecdd3;font-size:12px;font-weight:700;">Locked / Pending</span>
              <?php else: ?>
                <span style="display:inline-block;padding:.22rem .55rem;border-radius:999px;background:#ecfdf3;color:#027a48;border:1px solid #abefc6;font-size:12px;font-weight:700;">Unlocked / Approved</span>
              <?php endif; ?>
            </td>

            <td><?= htmlspecialchars($u['phone'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>

            <td>
              <?= htmlspecialchars(format_date_ddmmyyyy_time_bracket($u['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </td>

            <td>
              <button type="button"
                      class="adam-link-button"
                      data-id="<?= (int)$u['id'] ?>"
                      data-title="<?= htmlspecialchars($labelName, ENT_QUOTES, 'UTF-8') ?>"
                      onclick="openUserBinModal('restore', this)">Restore</button>

              &nbsp;|&nbsp;

              <button type="button"
                      class="adam-link-button"
                      data-id="<?= (int)$u['id'] ?>"
                      data-title="<?= htmlspecialchars($labelName, ENT_QUOTES, 'UTF-8') ?>"
                      onclick="openUserBinModal('delete_permanent', this)">Hapus Permanen</button>
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
        $query['page'] = 'admin/bin/users/index';
        $link = $root . '/index.php?' . http_build_query($query);
      ?>
        <?php if ($i === $page_num): ?>
          <strong><?= $i ?></strong>
        <?php else: ?>
          <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
</section>

<div id="userBinModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);align-items:center;justify-content:center;z-index:3000;">
  <div style="background:#fff;padding:1.5rem;border-radius:8px;max-width:420px;width:90%;box-shadow:0 4px 16px rgba(0,0,0,0.2);">
    <h3 id="userBinTitle" style="margin-top:0">Konfirmasi</h3>
    <p id="userBinText">...</p>

    <div style="margin-top:1rem;text-align:right;">
      <button type="button" onclick="closeUserBinModal()" style="padding:.4rem .8rem;background:#ccc;border:0;border-radius:6px;margin-right:.5rem;">Batal</button>

      <form id="userBinForm" method="post" action="#" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="id" id="userBinId">
        <button id="userBinSubmit" type="submit" style="padding:.4rem .8rem;background:#246;color:#fff;border:0;border-radius:6px;">OK</button>
      </form>
    </div>
  </div>
</div>

<script>
function openUserBinModal(type, btn) {
  const id = btn.dataset.id || '';
  const title = btn.dataset.title || '';
  const modal = document.getElementById('userBinModal');
  const form = document.getElementById('userBinForm');

  document.getElementById('userBinId').value = id;

  if (type === 'restore') {
    document.getElementById('userBinTitle').innerText = 'Restore User';
    document.getElementById('userBinText').innerText = 'Restore user "' + title + '"?';
    document.getElementById('userBinSubmit').innerText = 'Restore';
    document.getElementById('userBinSubmit').style.background = '#246';
    form.action = "<?= htmlspecialchars($root . '/admin/bin/users/restore.php', ENT_QUOTES, 'UTF-8') ?>";
  } else {
    document.getElementById('userBinTitle').innerText = 'Hapus Permanen';
    document.getElementById('userBinText').innerText = 'Hapus permanen user "' + title + '"? Ini tidak bisa dibatalkan.';
    document.getElementById('userBinSubmit').innerText = 'Hapus Permanen';
    document.getElementById('userBinSubmit').style.background = '#c33';
    form.action = "<?= htmlspecialchars($root . '/admin/bin/users/delete_permanent.php', ENT_QUOTES, 'UTF-8') ?>";
  }

  modal.style.display = 'flex';
}

function closeUserBinModal() {
  document.getElementById('userBinModal').style.display = 'none';
}

const selectAll = document.getElementById('selectAll');
if (selectAll) {
  selectAll.addEventListener('change', function() {
    const checked = this.checked;
    document.querySelectorAll('.bulkCheckbox').forEach(cb => cb.checked = checked);
  });
}

function confirmUserBinBulk() {
  const action = document.getElementById('bulkAction')?.value || '';
  if (!action) {
    alert('Pilih bulk action terlebih dahulu.');
    return false;
  }

  const any = Array.from(document.querySelectorAll('.bulkCheckbox')).some(cb => cb.checked);
  if (!any) {
    alert('Pilih minimal satu user.');
    return false;
  }

  if (action === 'delete_permanent') {
    return confirm('Yakin ingin menghapus permanen semua user terpilih? Ini tidak bisa dibatalkan.');
  }

  return true;
}

document.getElementById('userBinModal')?.addEventListener('click', function(e){
  if (e.target === this) closeUserBinModal();
});

document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') closeUserBinModal();
});
</script>