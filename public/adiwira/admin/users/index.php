<?php
// /adiwira/admin/users/index.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo '<p>Akses ditolak: belum login.</p>';
    exit;
}

$errors = [];
$messages = [];

// flash success
if (!empty($_SESSION['_flash_success'])) {
    $messages[] = $_SESSION['_flash_success'];
    unset($_SESSION['_flash_success']);
}

// flash error
if (!empty($_SESSION['_flash_error'])) {
    $errors[] = $_SESSION['_flash_error'];
    unset($_SESSION['_flash_error']);
}

// backward compatibility: accept msg from older redirects
if (!empty($_GET['msg'])) {
    $messages[] = (string) $_GET['msg'];
}

$uid = (int)$_SESSION['user_id'];
$sessionRole = $_SESSION['user_role'] ?? null;
if (!$sessionRole) {
    $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stmtRole->execute([':id' => $uid]);
    $sessionRole = $stmtRole->fetchColumn() ?: 'author';
    $_SESSION['user_role'] = $sessionRole;
}

// --- params: filter, search, paging ---
$filter_role = trim((string)($_GET['role'] ?? ''));
$search      = trim((string)($_GET['q'] ?? ''));

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// build where
$where = ["is_deleted = 0"];
$params = [];

// role filter
if ($filter_role !== '') {
    $where[] = "role = :role";
    $params[':role'] = $filter_role;
}

// search (name / email / username)
if ($search !== '') {
    $where[] = "(name LIKE :s OR email LIKE :s OR username LIKE :s)";
    $params[':s'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

// total count
$countSql = "SELECT COUNT(*) FROM users WHERE $where_sql";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / max(1,$perPage)));
if ($page > $pages) $page = $pages;

// fetch users
$sql = "SELECT id, email, username, name, role, img, bio, phone, created_at
        FROM users
        WHERE $where_sql
        ORDER BY id DESC
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// roles list (hardcoded or fetch from config)
$allRoles = ['author','editor','admin'];

// base url for links
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$roleCurrent = $sessionRole;
$canBulk = !in_array($roleCurrent, ['author'], true); // authors cannot bulk

// helper pagination function (compact, same as posts)
function build_pagination_items(int $current, int $total, int $max_visible = 9): array {
    if ($total <= $max_visible) return range(1,$total);
    $items = [];
    $reserved = 6;
    $middle_slots = max(1, $max_visible - $reserved);
    $half = (int)floor($middle_slots/2);
    $start = max(3, $current - $half);
    $end = min($total - 2, $current + $half);
    if ($start === 3) $end = min($total-2, $start + $middle_slots -1);
    if ($end === $total-2) $start = max(3, $end - $middle_slots +1);
    $items[] = 1; $items[] = 2;
    if ($start > 3) $items[] = '...';
    for ($i=$start;$i<=$end;$i++) $items[] = $i;
    if ($end < $total - 2) $items[] = '...';
    $items[] = $total-1; $items[] = $total;
    while (count($items) > $max_visible) {
        for ($i=0;$i<count($items);$i++) {
            if (is_int($items[$i]) && !in_array($items[$i],[1,2,$total-1,$total],true)) { array_splice($items,$i,1); break; }
        }
    }
    return $items;
}
$paging_items = build_pagination_items($page, $pages, 9);
?>
<section class="adam-card">
  <h2>Manajemen User</h2>

  <form method="get" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem;">
    <input type="hidden" name="page" value="admin/users/index">
    <input type="text" name="q" placeholder="Cari nama, email atau username..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" style="padding:.4rem;min-width:220px">
    <select name="role" style="padding:.4rem;">
      <option value="">-- Semua Role --</option>
      <?php foreach ($allRoles as $r): ?>
        <option value="<?= htmlspecialchars($r, ENT_QUOTES) ?>" <?= $filter_role === $r ? 'selected':'' ?>><?= htmlspecialchars(ucfirst($r), ENT_QUOTES) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="adam-button" type="submit">Terapkan</button>
    <a class="adam-cancle" href="<?= htmlspecialchars($base . '/index.php?page=admin/users/index', ENT_QUOTES) ?>">Reset</a>
    <div style="margin-left:auto">
      <a class="adam-button" href="<?= htmlspecialchars($base . '/index.php?page=admin/users/save', ENT_QUOTES) ?>">+ Tambah User</a>
    </div>
  </form>

  <?php if ($canBulk): ?>
  <form id="bulkForm" method="post" action="<?= htmlspecialchars($base . '/index.php?page=admin/users/bulk_action', ENT_QUOTES) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAll"> Pilih semua di halaman
      </label>

      <select id="bulkAction" name="action" style="padding:.4rem;">
        <option value="">-- Bulk action --</option>
        <option value="change_role">Ubah Role</option>
        <option value="delete">Hapus (soft)</option>
      </select>

      <select id="bulkRole" name="role" style="padding:.4rem;display:none;">
        <?php foreach ($allRoles as $r): ?>
          <option value="<?= htmlspecialchars($r, ENT_QUOTES) ?>"><?= htmlspecialchars(ucfirst($r), ENT_QUOTES) ?></option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="adam-button">Terapkan</button>
      <small style="color:#666;margin-left:.5rem;">(Aksi akan mempengaruhi user yang dicentang)</small>
    </div>
  <?php else: ?>
    <div style="margin-bottom:1rem;color:#666;">Bulk actions disembunyikan untuk role <strong>author</strong>.</div>
  <?php endif; ?>

  <div class="adam-table-wrapper">
  <table class="adam-table" style="width:100%;border-collapse:collapse">
      
<?php if (!empty($messages)): ?>
  <div class="adam-alert success auto-dismiss" data-dismiss-ms="3000" style="margin-bottom:1rem;padding:.8rem 1rem;background:#e8f7ec;border:1px solid #b6e2c2;border-radius:6px;color:#246;">
    <?php foreach ($messages as $m): ?>
      <div><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="adam-alert error" style="margin-bottom:1rem;padding:.8rem 1rem;background:#fee;border:1px solid #fbb;color:#600;border-radius:6px;">
    <?php foreach ($errors as $e): ?>
      <div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
      
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #e6e6e6">
        <th style="width:44px"></th> <!-- checkbox column (ganti kolom ID) -->
        <th>Avatar</th>
        <th>Nama</th>
        <th>Email / Username</th>
        <th>Role</th>
        <th>Bio</th>
        <th>Phone</th>
        <th>Terdaftar</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($users)): ?>
        <tr><td colspan="9" style="padding:1rem;">Belum ada user.</td></tr>
      <?php else: ?>
        <?php foreach ($users as $u): 
          $img = !empty($u['img']) ? $u['img'] : '/static/img/person.svg';
          $isSelf = ((int)$u['id'] === $uid);
        ?>
        <tr style="border-bottom:1px solid #f3f3f3">
          <td style="text-align:center">
            <?php if ($canBulk): ?>
              <?php if (!$isSelf): ?>
                <input type="checkbox" class="bulkCheckbox" name="ids[]" value="<?= (int)$u['id'] ?>">
              <?php else: ?>
                &mdash; <!-- prevent selecting self to avoid accidental lockout -->
              <?php endif; ?>
            <?php else: ?>
              &mdash;
            <?php endif; ?>
          </td>

          <td style="width:56px">
            <img src="<?= htmlspecialchars($img, ENT_QUOTES) ?>" alt="" style="height:40px;width:40px;object-fit:cover;border-radius:6px">
          </td>

<?php
// Nama (klik menuju /author/username jika username tersedia)
$name = htmlspecialchars($u['name'] ?? '-', ENT_QUOTES);
$username = $u['username'] ?? '';

if (!empty($username)) {
    $profileUrl = '/author/' . rawurlencode($username);

    echo '<td>
            <a href="' . htmlspecialchars($profileUrl, ENT_QUOTES) . '"
               class="adam-link"
               title="Lihat profil ' . $name . '"
               target="_blank"
               rel="noopener noreferrer">
               ' . $name . '
            </a>
          </td>';
} else {
    echo '<td>' . $name . '</td>';
}
?>
          <td>
            <?= htmlspecialchars($u['email'] ?? '-', ENT_QUOTES) ?><br>
            <small style="color:#666"><?= htmlspecialchars($u['username'] ?? '-', ENT_QUOTES) ?></small>
          </td>

          <td><?= htmlspecialchars($u['role'], ENT_QUOTES) ?></td>

          <td style="max-width:220px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
            <?= htmlspecialchars($u['bio'] ?? '-', ENT_QUOTES) ?>
          </td>

          <td><?= htmlspecialchars($u['phone'] ?? '-', ENT_QUOTES) ?></td>

          <td><?= htmlspecialchars($u['created_at'], ENT_QUOTES) ?></td>

          <td>
            <a class="adam-ubah" href="<?= htmlspecialchars($base . '/index.php?page=admin/users/save&id=' . (int)$u['id'], ENT_QUOTES) ?>">Edit</a>
            <?php if (!$isSelf && $canBulk): ?>
              &nbsp;|&nbsp;
              <!-- IMPORTANT: use single-quoted attribute so json_encode (double-quoted) is safe -->
              <button type="button" class="adam-hapus" onclick='openDeleteModal(<?= (int)$u['id'] ?>, <?= json_encode($u['name'] ?? $u['email']) ?>)'>Hapus</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php if ($canBulk): ?>
    </form> <!-- end bulkForm -->
  <?php endif; ?>

  <?php if ($pages > 1): ?>
    <nav class="adam-pagination" style="margin-top:1rem;">
      <?php foreach ($paging_items as $item):
        if ($item === '...') { echo '<span class="dots">…</span> '; continue; }
        $i = (int)$item;
        $q = $_GET; $q['p'] = $i;
        $link = $base . '/index.php?' . http_build_query($q);
      ?>
        <?php if ($i === $page): ?>
          <strong><?= $i ?></strong>
        <?php else: ?>
          <a href="<?= htmlspecialchars($link, ENT_QUOTES) ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <!-- delete confirm modal -->
  <div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);align-items:center;justify-content:center;z-index:3000;">
    <div style="background:#fff;padding:1.25rem;border-radius:8px;max-width:420px;width:92%;box-shadow:0 4px 16px rgba(0,0,0,0.12);">
      <h3 style="margin:0 0 .5rem 0">Konfirmasi Hapus</h3>
      <p id="deleteText">Yakin ingin menghapus user ini?</p>
      <div style="margin-top:1rem;text-align:right;">
        <button type="button" onclick="closeDeleteModal()" style="padding:.4rem .8rem;background:#ccc;border:0;border-radius:6px;margin-right:.5rem;">Batal</button>
        <form id="deleteForm" method="post" action="<?= htmlspecialchars($base . '/index.php?page=admin/users/delete', ENT_QUOTES) ?>" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
          <input type="hidden" name="id" id="deleteId">
          <button type="submit" style="padding:.4rem .8rem;background:#c33;color:#fff;border:0;border-radius:6px;">Hapus</button>
        </form>
      </div>
    </div>
  </div>

</section>

<script>
// select all toggle
const selectAll = document.getElementById('selectAll');
if (selectAll) {
  selectAll.addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('.bulkCheckbox').forEach(cb => cb.checked = checked);
  });
}

// bulk action controls
const bulkActionEl = document.getElementById('bulkAction');
if (bulkActionEl) {
  bulkActionEl.addEventListener('change', function(){
    const v = this.value;
    const bulkRole = document.getElementById('bulkRole');
    if (bulkRole) bulkRole.style.display = (v === 'change_role') ? 'inline-block' : 'none';
  });
}

// confirm bulk form submit
const bulkForm = document.getElementById('bulkForm');
if (bulkForm) {
  bulkForm.addEventListener('submit', function(ev){
    const action = document.getElementById('bulkAction').value;
    if (!action) { alert('Pilih bulk action terlebih dahulu.'); ev.preventDefault(); return false; }
    const any = Array.from(document.querySelectorAll('.bulkCheckbox')).some(cb => cb.checked);
    if (!any) { alert('Pilih minimal satu user.'); ev.preventDefault(); return false; }

    if (action === 'delete') {
      if (!confirm('Yakin ingin menghapus (soft-delete) semua user terpilih?')) { ev.preventDefault(); return false; }
    }
    if (action === 'change_role') {
      const r = document.getElementById('bulkRole').value;
      if (!r) { alert('Pilih role tujuan.'); ev.preventDefault(); return false; }
      if (!confirm('Ubah role user terpilih menjadi "' + r + '"?')) { ev.preventDefault(); return false; }
    }
    return true;
  });
}

// delete modal functions
function openDeleteModal(id, name) {
  const modal = document.getElementById('deleteModal');
  if (!modal) return;
  modal.style.display = 'flex';
  const field = document.getElementById('deleteId');
  if (field) field.value = id;
  const txt = document.getElementById('deleteText');
  if (txt) txt.innerText = 'Hapus user: ' + (name || id) + '?';
}
function closeDeleteModal() {
  const modal = document.getElementById('deleteModal');
  if (!modal) return;
  modal.style.display = 'none';
}
</script>
