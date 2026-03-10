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

$uid = (int)$_SESSION['user_id'];
$sessionRole = $_SESSION['user_role'] ?? null;
if (!$sessionRole) {
    $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stmtRole->execute([':id' => $uid]);
    $sessionRole = $stmtRole->fetchColumn() ?: 'author';
    $_SESSION['user_role'] = $sessionRole;
}

$sessionRole = strtolower(trim((string)$sessionRole));
if ($sessionRole !== 'admin') {
    http_response_code(403);
    echo '<p>Akses ditolak: menu users hanya untuk admin.</p>';
    exit;
}

$messages = [];
$errors   = [];
$flash_for_js = [];

if (!empty($_SESSION['_flash_success'])) {
    $msg = (string)$_SESSION['_flash_success'];
    $messages[] = $msg;
    $flash_for_js[] = ['type' => 'success', 'text' => $msg];
    unset($_SESSION['_flash_success']);
}
if (!empty($_SESSION['_flash_error'])) {
    $err = (string)$_SESSION['_flash_error'];
    $errors[] = $err;
    $flash_for_js[] = ['type' => 'error', 'text' => $err];
    unset($_SESSION['_flash_error']);
}

$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
if (is_array($flash)) {
    foreach ($flash as $f) {
        $type = (string)($f['type'] ?? 'info');
        $text = (string)($f['text'] ?? '');
        if ($text === '') continue;

        if ($type === 'success') $messages[] = $text;
        else $errors[] = $text;

        $flash_for_js[] = ['type' => $type, 'text' => $text];
    }
}

if (!empty($_GET['msg'])) {
    $m = (string)$_GET['msg'];
    $messages[] = $m;
    $flash_for_js[] = ['type' => 'success', 'text' => $m];
}
if (!empty($_GET['err'])) {
    $e = (string)$_GET['err'];
    $errors[] = $e;
    $flash_for_js[] = ['type' => 'error', 'text' => $e];
}

$messages = array_values(array_unique($messages));
$errors   = array_values(array_unique($errors));

$filter_role   = trim((string)($_GET['role'] ?? ''));
$filter_status = trim((string)($_GET['lock'] ?? ''));
$search        = trim((string)($_GET['q'] ?? ''));

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = ["is_deleted = 0"];
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
    $where[] = "(name LIKE :s OR email LIKE :s OR username LIKE :s)";
    $params[':s'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) FROM users WHERE $where_sql";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / max(1, $perPage)));
if ($page > $pages) $page = $pages;

$sql = "SELECT id, email, username, name, role, img, bio, phone, created_at, is_locked
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

$allRoles = ['author','editor','admin'];
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$currentQuery = $_GET;
$currentQuery['page'] = 'admin/users/index';
$returnTo = $base . '/index.php?' . http_build_query($currentQuery);

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
            if (is_int($items[$i]) && !in_array($items[$i], [1, 2, $total - 1, $total], true)) {
                array_splice($items, $i, 1);
                break;
            }
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

    <button class="adam-button" type="submit">Terapkan</button>
    <a class="adam-cancle" href="<?= htmlspecialchars($base . '/index.php?page=admin/users/index', ENT_QUOTES, 'UTF-8') ?>">Reset</a>

    <div style="margin-left:auto">
      <a class="adam-button" href="<?= htmlspecialchars($base . '/index.php?page=admin/users/save', ENT_QUOTES, 'UTF-8') ?>">+ Tambah User</a>
    </div>
  </form>

  <form id="bulkForm" method="post" action="<?= htmlspecialchars($base . '/index.php?page=admin/users/bulk_action', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">

    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAll"> Pilih semua di halaman
      </label>

      <select id="bulkAction" name="action" style="padding:.4rem;">
        <option value="">-- Bulk action --</option>
        <option value="change_role">Ubah Role</option>
        <option value="lock">Lock</option>
        <option value="unlock">Unlock / Approve</option>
        <option value="delete">Hapus (soft)</option>
      </select>

      <select id="bulkRole" name="role" style="padding:.4rem;display:none;">
        <?php foreach ($allRoles as $r): ?>
          <option value="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($r), ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="adam-button">Terapkan</button>
      <small style="color:#666;margin-left:.5rem;">(Aksi akan mempengaruhi user yang dicentang)</small>
    </div>

    <div class="adam-table-wrapper">
      <table class="adam-table" style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid #e6e6e6">
            <th style="width:44px"></th>
            <th>Avatar</th>
            <th>Nama</th>
            <th>Email / Username</th>
            <th>Role</th>
            <th>Status</th>
            <th>Bio</th>
            <th>Phone</th>
            <th>Terdaftar</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users)): ?>
            <tr><td colspan="10" style="padding:1rem;">Belum ada user.</td></tr>
          <?php else: ?>
            <?php foreach ($users as $u):
              $img = !empty($u['img']) ? $u['img'] : '/static/img/person.svg';
              $isSelf = ((int)$u['id'] === $uid);
              $isLocked = (int)($u['is_locked'] ?? 0) === 1;
              $nameRaw = (string)($u['name'] ?? ($u['email'] ?? ''));
              $name = htmlspecialchars($u['name'] ?? '-', ENT_QUOTES, 'UTF-8');
              $username = $u['username'] ?? '';
              $toggleFormId = 'toggle-lock-form-' . (int)$u['id'];
            ?>
            <tr style="border-bottom:1px solid #f3f3f3">
              <td style="text-align:center">
                <?php if (!$isSelf): ?>
                  <input type="checkbox" class="bulkCheckbox" name="ids[]" value="<?= (int)$u['id'] ?>">
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>

              <td style="width:56px">
                <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="" style="height:40px;width:40px;object-fit:cover;border-radius:6px">
              </td>

              <?php if (!empty($username)): ?>
                <td>
                  <a href="<?= htmlspecialchars('/author/' . rawurlencode($username), ENT_QUOTES, 'UTF-8') ?>"
                     class="adam-link"
                     title="Lihat profil <?= $name ?>"
                     target="_blank"
                     rel="noopener noreferrer"><?= $name ?></a>
                </td>
              <?php else: ?>
                <td><?= $name ?></td>
              <?php endif; ?>

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

              <td style="max-width:220px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
                <?= htmlspecialchars($u['bio'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
              </td>

              <td><?= htmlspecialchars($u['phone'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($u['created_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <a class="adam-ubah" href="<?= htmlspecialchars($base . '/index.php?page=admin/users/save&id=' . (int)$u['id'], ENT_QUOTES, 'UTF-8') ?>">Edit</a>

                <?php if (!$isSelf): ?>
                  &nbsp;|&nbsp;
                  <button type="submit"
                          form="<?= htmlspecialchars($toggleFormId, ENT_QUOTES, 'UTF-8') ?>"
                          class="<?= $isLocked ? 'adam-ubah' : 'adam-att' ?>"
                          style="background:none;border:0;padding:0;cursor:pointer;">
                    <?= $isLocked ? 'Approve' : 'Lock' ?>
                  </button>

                  &nbsp;|&nbsp;
                  <button type="button"
                          class="adam-hapus"
                          onclick='openDeleteModal(<?= (int)$u["id"] ?>, <?= json_encode($nameRaw) ?>)'>Hapus</button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>

  <?php foreach ($users as $u):
    $isSelf = ((int)$u['id'] === $uid);
    if ($isSelf) continue;
    $isLocked = (int)($u['is_locked'] ?? 0) === 1;
    $toggleFormId = 'toggle-lock-form-' . (int)$u['id'];
  ?>
    <form id="<?= htmlspecialchars($toggleFormId, ENT_QUOTES, 'UTF-8') ?>"
          method="post"
          action="<?= htmlspecialchars($base . '/index.php?page=admin/users/toggle_lock', ENT_QUOTES, 'UTF-8') ?>"
          style="display:none;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <input type="hidden" name="mode" value="<?= $isLocked ? 'unlock' : 'lock' ?>">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
    </form>
  <?php endforeach; ?>

  <?php if ($pages > 1): ?>
    <nav class="adam-pagination" style="margin-top:1rem;">
      <?php foreach ($paging_items as $item):
        if ($item === '...') { echo '<span class="dots">…</span> '; continue; }
        $i = (int)$item;
        $q = $_GET;
        $q['p'] = $i;
        $link = $base . '/index.php?' . http_build_query($q);
      ?>
        <?php if ($i === $page): ?>
          <strong><?= $i ?></strong>
        <?php else: ?>
          <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <div id="deleteModal" class="adam-modal" style="display:none;">
    <div class="adam-modal__panel" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
      <h3 id="deleteModalTitle" class="adam-modal__title">Konfirmasi Hapus</h3>
      <p id="deleteText" class="adam-modal__text">Yakin ingin menghapus user ini?</p>

      <div style="margin-top:1rem;text-align:right;">
        <button type="button"
                onclick="closeDeleteModal()"
                style="padding:.4rem .8rem;background:#ccc;border:0;border-radius:6px;margin-right:.5rem;">Batal</button>

        <form id="deleteForm"
              method="post"
              action="<?= htmlspecialchars($base . '/index.php?page=admin/users/delete', ENT_QUOTES, 'UTF-8') ?>"
              style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="id" id="deleteId">
          <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit"
                  style="padding:.4rem .8rem;background:#c33;color:#fff;border:0;border-radius:6px;">Hapus</button>
        </form>
      </div>
    </div>
  </div>

  <?php if (!empty($flash_for_js)): ?>
  <script>
  document.addEventListener('DOMContentLoaded', function(){
    if (typeof showToast !== 'function') return;
    const items = <?= json_encode($flash_for_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    items.forEach(function(it){
      const type = (it.type === 'error') ? 'error' : (it.type === 'success' ? 'success' : 'info');
      showToast(it.text, type, type === 'error' ? 6500 : 4200);
    });
  });
  </script>
  <?php endif; ?>
</section>

<script>
const selectAll = document.getElementById('selectAll');
if (selectAll) {
  selectAll.addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('.bulkCheckbox').forEach(cb => cb.checked = checked);
  });
}

const bulkAction = document.getElementById('bulkAction');
const bulkRole = document.getElementById('bulkRole');
if (bulkAction && bulkRole) {
  bulkAction.addEventListener('change', function(){
    bulkRole.style.display = (this.value === 'change_role') ? 'inline-block' : 'none';
  });
}
</script>