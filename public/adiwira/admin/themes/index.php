<?php
// /adiwira/admin/themes/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// ===== ADMIN ONLY GUARD =====
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo '<section class="adam-card"><h2>Themes / Partials</h2><div class="adam-alert error" style="margin-top:.8rem;">Akses ditolak: belum login.</div></section>';
    return;
}

$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? '';

if ($role === '' || $role === null) {
    $r = $pdo->prepare("SELECT role FROM users WHERE id=:id AND is_deleted=0 LIMIT 1");
    $r->execute([':id' => $uid]);
    $role = (string)$r->fetchColumn();
    $_SESSION['user_role'] = $role;
}
$role = strtolower(trim($role ?: 'guest'));

if ($role !== 'admin') {
    http_response_code(403);
    echo '<section class="adam-card"><h2>Themes / Partials</h2><div class="adam-alert error" style="margin-top:.8rem;">Akses ditolak: menu Themes hanya untuk <strong>admin</strong>.</div></section>';
    return;
}
// ===== END ADMIN ONLY GUARD =====


$messages = [];
$errors   = [];

// fallback legacy query (kalau masih ada endpoint lama)
if (!empty($_GET['msg'])) $messages[] = urldecode((string)$_GET['msg']);
if (!empty($_GET['err'])) $errors[]   = urldecode((string)$_GET['err']);

// ===== ambil flash dari bulk_action/delete endpoint =====
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

    $flash_for_js[] = ['type'=>$type, 'text'=>$text];
  }
}
$messages = array_values(array_unique($messages));
$errors   = array_values(array_unique($errors));

// filter
$filter_status = (string)($_GET['status'] ?? '');
$search        = trim((string)($_GET['q'] ?? ''));

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset   = ($page_num - 1) * $per_page;

// base where & params
$where  = ["p.is_deleted = 0", "p.type = 'theme'"];
$params = [];

if ($filter_status !== '') {
  $where[] = "p.status = :status";
  $params[':status'] = $filter_status;
}
if ($search !== '') {
  $where[] = "(p.title LIKE :search OR p.slug LIKE :search)";
  $params[':search'] = '%' . $search . '%';
}
$where_sql = implode(' AND ', $where);

// count
$count_sql = "SELECT COUNT(*) FROM posts p WHERE $where_sql";
$countStmt = $pdo->prepare($count_sql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// list
$sql = "
  SELECT p.id, p.title, p.slug, p.status, p.created_at, p.updated_at
  FROM posts p
  WHERE $where_sql
  ORDER BY p.created_at DESC
  LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$themes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

// pagination helper (tetap)
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
$paging_items = build_pagination_items($page_num, $pages, 9);
?>

<section class="adam-card">
  <h2>Themes / Partials</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="page" value="admin/themes/index">
    <input type="text" name="q" placeholder="Cari judul atau slug..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;min-width:200px">
    <label style="display:flex;align-items:center;gap:.5rem">
      <span>Status:</span>
      <select name="status" style="padding:.4rem;">
        <option value="">-- Semua Status --</option>
        <option value="draft" <?= $filter_status==='draft'?'selected':'' ?>>Draft</option>
        <option value="published" <?= $filter_status==='published'?'selected':'' ?>>Published</option>
        <option value="private" <?= $filter_status==='private'?'selected':'' ?>>Private</option>
      </select>
    </label>
    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($base . '/index.php?page=admin/themes/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Reset</a>
  </form>

  <p style="margin-bottom:1rem">
    <a class="adam-button" href="<?= htmlspecialchars($base . '/index.php?page=admin/themes/add', ENT_QUOTES, 'UTF-8') ?>">+ Tambah Theme Partial</a>
    &nbsp;&nbsp;
    <a class="adam-att" href="<?= htmlspecialchars($base . '/index.php?page=admin/bin/theme/index', ENT_QUOTES, 'UTF-8') ?>">🗑️ Trash</a>
  </p>

  <!-- Bulk controls (admin only anyway) -->
  <form id="themesBulkForm" method="post" action="<?= htmlspecialchars($base . '/admin/themes/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>" onsubmit="return muizThemesOpenBulkConfirmModal(event,this)">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAllThemes"> Pilih semua di halaman
      </label>

      <select id="bulkActionThemes" name="action" style="padding:.4rem;">
        <option value="">-- Bulk action --</option>
        <option value="delete">Hapus</option>
        <option value="change_status">Ubah Status</option>
      </select>

      <select id="bulkStatusThemes" name="status" style="padding:.4rem;display:none;">
        <option value="draft">Draft</option>
        <option value="published">Published</option>
        <option value="private">Private</option>
      </select>

      <button type="submit" class="adam-button">Terapkan</button>
      <small style="color:#666;margin-left:.5rem;">(Bulk akan mempengaruhi item yang dicentang)</small>
    </div>

    <?php if (!empty($messages)): ?>
      <div class="adam-alert success auto-dismiss" data-dismiss-ms="3000"
           style="margin-bottom:1rem;padding:.8rem 1rem;background:#e8f7ec;border:1px solid #b6e2c2;border-radius:6px;color:#246;">
        <?php foreach ($messages as $m): ?><div><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="adam-alert error" style="margin-bottom:1rem;padding:.8rem 1rem;background:#fee;border:1px solid #fbb;color:#600;border-radius:6px;">
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
            <th>Slug</th>
            <th>Status</th>
            <th>Dibuat</th>
            <th style="width:160px">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($themes)): ?>
            <tr><td colspan="6" style="padding:1rem;">Belum ada theme partial.</td></tr>
          <?php else: ?>
            <?php foreach ($themes as $t): ?>
              <tr>
                <td style="text-align:center;">
                  <input type="checkbox" class="bulkCheckboxTheme" name="ids[]" value="<?= (int)$t['id'] ?>">
                </td>

                <td>
                  <a class="adam-link" href="<?= htmlspecialchars('/' . rawurlencode((string)$t['slug']) . '/', ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($t['title'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                  </a>
                </td>

                <td><?= htmlspecialchars($t['slug'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($t['status'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($t['created_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>

                <td>
                  <a class="adam-ubah" href="<?= htmlspecialchars($base . '/index.php?page=admin/themes/edit&id=' . (int)$t['id'], ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                  &nbsp;<span class="muted-divider">|</span>&nbsp;
                  <button type="button"
                          class="adam-hapus"
                          data-id="<?= (int)$t['id'] ?>"
                          data-title="<?= htmlspecialchars($t['title'] ?? '', ENT_QUOTES) ?>"
                          onclick="muizThemesOpenDeleteModal(this)">
                    Hapus
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>

  <?php if ($pages > 1): ?>
    <nav class="adam-pagination" style="margin-top:1rem;">
      <?php foreach ($paging_items as $item):
        if ($item === '...') { echo '<span class="dots">…</span> '; continue; }
        $i = (int)$item;
        $query = $_GET;
        $query['p'] = $i;
        $link = $base . '/index.php?' . http_build_query($query);
      ?>
        <?php if ($i === $page_num): ?><strong><?= $i ?></strong>
        <?php else: ?><a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a><?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <!-- 🧱 MODAL KONFIRMASI DELETE THEME -->
  <div id="muizThemeDeleteModal" class="adam-modal" role="dialog" aria-modal="true" aria-labelledby="muizThemeDeleteTitle" style="display:none;">
    <div class="adam-modal__panel" role="document">
      <h3 id="muizThemeDeleteTitle" class="adam-modal__title">Konfirmasi Hapus</h3>
      <p id="muizThemeDeleteText" class="adam-modal__text">Apakah kamu yakin?</p>

      <div class="adam-modal__actions">
        <button type="button" class="adam-btn adam-btn--ghost" onclick="muizThemesCloseDeleteModal()">Batal</button>

        <form id="muizThemeDeleteForm" method="post" action="<?= htmlspecialchars($base . '/admin/themes/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="id" id="muizThemeDeleteId" value="">
          <button type="submit" class="adam-btn adam-btn--danger">Hapus</button>
        </form>
      </div>
    </div>
  </div>

  <!-- 🧱 MODAL KONFIRMASI BULK -->
  <div id="muizThemeBulkConfirmModal" class="adam-modal" role="dialog" aria-modal="true" aria-labelledby="muizThemeBulkTitle" style="display:none;">
    <div class="adam-modal__panel" role="document">
      <h3 id="muizThemeBulkTitle" class="adam-modal__title">Konfirmasi Bulk Action</h3>
      <p id="muizThemeBulkText" class="adam-modal__text">...</p>

      <div class="adam-modal__actions">
        <button type="button" class="adam-btn adam-btn--ghost" onclick="muizThemesCloseBulkConfirmModal()">Batal</button>
        <button type="button" class="adam-btn adam-btn--danger" id="muizThemeBulkYes">Lanjutkan</button>
      </div>
    </div>
  </div>

  <script>
  // select all
  const selectAllThemes = document.getElementById('selectAllThemes');
  if (selectAllThemes) {
    selectAllThemes.addEventListener('change', function(){
      const checked = this.checked;
      document.querySelectorAll('.bulkCheckboxTheme').forEach(cb => cb.checked = checked);
    });
  }

  // show/hide status
  const bulkActionThemes = document.getElementById('bulkActionThemes');
  if (bulkActionThemes) {
    bulkActionThemes.addEventListener('change', function(){
      const bulkStatus = document.getElementById('bulkStatusThemes');
      if (bulkStatus) bulkStatus.style.display = (this.value === 'change_status') ? 'inline-block' : 'none';
    });
  }

  // ============================
  // Single delete modal
  // ============================
  function muizThemesOpenDeleteModal(btn){
    const modal = document.getElementById('muizThemeDeleteModal');
    if (!modal) return;

    if (modal.parentNode !== document.body) document.body.appendChild(modal);

    const id = btn?.dataset?.id || '';
    const title = btn?.dataset?.title || '';

    const idInput = document.getElementById('muizThemeDeleteId');
    const txt     = document.getElementById('muizThemeDeleteText');
    if (idInput) idInput.value = id;
    if (txt) txt.innerText = `Hapus theme partial "${title}"?`;

    modal.onclick = function(e){ if (e.target === modal) muizThemesCloseDeleteModal(); };
    const panel = modal.querySelector('.adam-modal__panel');
    if (panel) panel.addEventListener('click', (e)=>e.stopPropagation(), { once:true });

    document.addEventListener('keydown', muizThemesDeleteEsc);

    modal.classList.add('show');
    modal.style.display = 'flex';
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
  }

  function muizThemesCloseDeleteModal(){
    const modal = document.getElementById('muizThemeDeleteModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.style.display = 'none';
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    document.removeEventListener('keydown', muizThemesDeleteEsc);
  }

  function muizThemesDeleteEsc(e){
    if (e.key === 'Escape') muizThemesCloseDeleteModal();
  }

  // ============================
  // Bulk confirm modal
  // ============================
  let _themesBulkFormRef = null;

  function muizThemesGetBulkSummary(){
    const action = document.getElementById('bulkActionThemes')?.value || '';
    const checked = Array.from(document.querySelectorAll('.bulkCheckboxTheme:checked'));
    const count = checked.length;

    const actionLabel = (() => {
      const sel = document.getElementById('bulkActionThemes');
      if (!sel) return action;
      const opt = sel.options[sel.selectedIndex];
      return opt ? (opt.textContent || action) : action;
    })();

    if (!action) return { ok:false, message:'Pilih bulk action terlebih dahulu.' };
    if (count < 1) return { ok:false, message:'Pilih minimal satu theme.' };

    if (action === 'delete') {
      return { ok:true, type:'danger', title:`Konfirmasi: ${actionLabel}`, message:`Kamu akan memindahkan ${count} theme partial ke Trash. Lanjutkan?` };
    }

    if (action === 'change_status') {
      const stSel = document.getElementById('bulkStatusThemes');
      const st = stSel?.value || '';
      const stLabel = stSel && stSel.selectedIndex >= 0 ? (stSel.options[stSel.selectedIndex].textContent || st) : st;
      return { ok:true, type:'info', title:`Konfirmasi: ${actionLabel}`, message:`Kamu akan mengubah status ${count} theme partial menjadi "${stLabel}". Lanjutkan?` };
    }

    return { ok:true, type:'info', title:'Konfirmasi', message:`Jalankan "${actionLabel}" untuk ${count} theme?` };
  }

  function muizThemesOpenBulkConfirmModal(ev, form){
    ev.preventDefault();
    _themesBulkFormRef = form;

    const sum = muizThemesGetBulkSummary();
    if (!sum.ok) {
      if (typeof showToast === 'function') showToast(sum.message, 'error', 4500);
      else alert(sum.message);
      return false;
    }

    const modal   = document.getElementById('muizThemeBulkConfirmModal');
    const panel   = modal?.querySelector('.adam-modal__panel');
    const titleEl = document.getElementById('muizThemeBulkTitle');
    const textEl  = document.getElementById('muizThemeBulkText');
    const yesBtn  = document.getElementById('muizThemeBulkYes');

    if (titleEl) titleEl.textContent = sum.title || 'Konfirmasi Bulk Action';
    if (textEl)  textEl.textContent  = sum.message || 'Lanjutkan?';

    if (yesBtn) {
      yesBtn.classList.remove('adam-btn--danger');
      if (sum.type === 'danger') yesBtn.classList.add('adam-btn--danger');

      yesBtn.onclick = function(){
        muizThemesCloseBulkConfirmModal();
        if (_themesBulkFormRef) _themesBulkFormRef.submit();
      };
    }

    if (modal.parentNode !== document.body) document.body.appendChild(modal);

    if (panel) panel.addEventListener('click', (e)=>e.stopPropagation(), { once:true });
    modal.onclick = function(e){ if (e.target === modal) muizThemesCloseBulkConfirmModal(); };

    document.addEventListener('keydown', muizThemesBulkEsc);

    modal.classList.add('show');
    modal.style.display = 'flex';
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';

    setTimeout(()=>{ try{ yesBtn && yesBtn.focus(); }catch(e){} }, 0);
    return false;
  }

  function muizThemesCloseBulkConfirmModal(){
    const modal = document.getElementById('muizThemeBulkConfirmModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.style.display = 'none';
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    document.removeEventListener('keydown', muizThemesBulkEsc);
  }

  function muizThemesBulkEsc(e){
    if (e.key === 'Escape') muizThemesCloseBulkConfirmModal();
  }
  </script>
</section>