<?php
declare(strict_types=1);

// /adiwira/admin/pages/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../_guard.php';

[$uid, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);

$errors = [];
$messages = [];

// pastikan session aktif untuk flash
if (function_exists('ensure_session_started')) {
    ensure_session_started(false);
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// legacy query fallback
if (!empty($_GET['err'])) {
    $errors[] = urldecode((string)$_GET['err']);
}
if (!empty($_GET['msg'])) {
    $messages[] = urldecode((string)$_GET['msg']);
}

// flash session
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$flash_for_js = [];
if (is_array($flash)) {
    foreach ($flash as $f) {
        $type = isset($f['type']) ? (string)$f['type'] : 'info';
        $text = isset($f['text']) ? (string)$f['text'] : '';
        if ($text === '') continue;

        if ($type === 'success') {
            $messages[] = $text;
        } else {
            $errors[] = $text;
        }

        $flash_for_js[] = ['type' => $type, 'text' => $text];
    }
}

$messages = array_values(array_unique($messages));
$errors   = array_values(array_unique($errors));

// filter
$filter_status = (string)($_GET['status'] ?? '');
$filter_author = (string)($_GET['author'] ?? '');
$search        = trim((string)($_GET['q'] ?? ''));

// pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 10;
$offset   = ($page_num - 1) * $per_page;

// base where
$where = ["p.is_deleted = 0", "p.type = 'page'"];
$params = [];

// author + editor hanya boleh lihat page miliknya sendiri
if (in_array($role, ['author', 'editor'], true)) {
    $where[] = "p.created_by = :uid";
    $params[':uid'] = $uid;
}

// filter status
if ($filter_status !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $filter_status;
}

// filter author
if ($filter_author !== '') {
    $aid = (int)$filter_author;
    if ($aid > 0) {
        $where[] = "p.created_by = :author_id";
        $params[':author_id'] = $aid;
    }
}

// search
if ($search !== '') {
    $where[] = "(p.title LIKE :search OR p.slug LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

// total count
$count_sql = "SELECT COUNT(*) FROM posts p WHERE $where_sql";
$countStmt = $pdo->prepare($count_sql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

// data
$sql = "
SELECT
  p.id, p.title, p.slug, p.status, p.created_at, p.updated_at,
  u.id AS author_id,
  u.username AS author_username,
  COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), CAST(u.id AS CHAR)) AS author_name
FROM posts p
LEFT JOIN users u ON u.id = p.created_by
WHERE $where_sql
ORDER BY p.created_at DESC
LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$pages_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// authors dropdown
$authorsStmt = $pdo->query("
    SELECT id, name, username
    FROM users
    WHERE is_deleted = 0
      AND is_locked = 0
    ORDER BY name ASC, username ASC
");
$authors = $authorsStmt->fetchAll(PDO::FETCH_ASSOC);

// base
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');

// bulk tersedia untuk semua role yang diizinkan
$canBulk = in_array($role, ['admin', 'editor', 'author'], true);

/** helper pagination */
if (!function_exists('build_pagination_items')) {
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

        for ($i = $start; $i <= $end; $i++) {
            $items[] = $i;
        }

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
}

$paging_items = build_pagination_items($page_num, $pages, 9);
?>

<section class="adam-card">
  <h2>Pages</h2>

  <form method="get" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="admin/pages/index">
    <input type="text" name="q" placeholder="Cari judul atau slug..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="padding:.4rem;min-width:200px">

    <select name="status" style="padding:.4rem;">
      <option value="">-- Semua Status --</option>
      <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draft</option>
      <option value="published" <?= $filter_status === 'published' ? 'selected' : '' ?>>Published</option>
      <option value="private" <?= $filter_status === 'private' ? 'selected' : '' ?>>Private</option>
    </select>

    <select name="author" style="padding:.4rem;">
      <option value="">-- Semua Penulis --</option>
      <?php foreach ($authors as $a):
        $label = $a['name'] ?: ($a['username'] ?: $a['id']);
      ?>
        <option value="<?= (int)$a['id'] ?>" <?= ((string)$filter_author === (string)$a['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="adam-button">Terapkan</button>
    <a href="<?= htmlspecialchars($base . '/index.php?page=admin/pages/index', ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Reset</a>
  </form>

  <p style="margin-bottom:1rem">
    <a class="adam-button" href="<?= htmlspecialchars($base . '/index.php?page=admin/pages/halaman', ENT_QUOTES, 'UTF-8') ?>">+ Tambah Page</a>
    <?php if ($role === 'admin') : ?>
      &nbsp;&nbsp;
      <a class="adam-att" href="<?= htmlspecialchars($base . '/index.php?page=admin/bin/page/index', ENT_QUOTES, 'UTF-8') ?>">🗑️ Trash</a>
    <?php endif; ?>
  </p>

  <?php if ($canBulk): ?>
  <form id="pagesBulkForm"
        method="post"
        action="<?= htmlspecialchars($base . '/admin/pages/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>"
        onsubmit="return muizPagesOpenBulkConfirmModal(event,this)">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4rem;">
        <input type="checkbox" id="selectAllPages"> Pilih semua di halaman
      </label>

      <select id="bulkActionPages" name="action" style="padding:.4rem;">
        <option value="">-- Bulk action --</option>
        <option value="delete">Hapus</option>
        <option value="change_status">Ubah Status</option>
        <?php if ($role === 'admin'): ?>
          <option value="change_author">Ubah Author</option>
        <?php endif; ?>
      </select>

      <select id="bulkStatusPages" name="status" style="padding:.4rem;display:none;">
        <option value="draft">Draft</option>
        <option value="published">Published</option>
        <option value="private">Private</option>
      </select>

      <?php if ($role === 'admin'): ?>
      <select id="bulkAuthorPages" name="author_id" style="padding:.4rem;display:none;">
        <option value="">-- Pilih Author --</option>
        <?php foreach ($authors as $a):
          $label = $a['name'] ?: ($a['username'] ?: $a['id']);
        ?>
          <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <button type="submit" class="adam-button">Terapkan</button>
      <small style="color:#666;margin-left:.5rem;">(Bulk akan mempengaruhi item yang dicentang)</small>
    </div>
  <?php else: ?>
    <div style="margin-bottom:1rem;color:#666;">
      Bulk actions disembunyikan.
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
          <th>Slug</th>
          <th>Status</th>
          <th>Dibuat</th>
          <th>Penulis</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pages_list)): ?>
          <tr><td colspan="7" style="padding:1rem;">Tidak ada page ditemukan.</td></tr>
        <?php else: ?>
          <?php foreach ($pages_list as $p): ?>
            <?php
              $status = strtolower(trim((string)($p['status'] ?? 'unknown')));
              $statusClass = in_array($status, ['published','draft','private'], true) ? $status : 'unknown';

              $icons = [
                'published' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                'draft'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M3 21v-3l11-11 3 3L6 21H3z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                'private'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
                'unknown'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.4"/><path d="M9.5 9a2.5 2.5 0 1 1 5 1c0 1.5-1.5 1.75-1.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="17.2" r="0.6" fill="currentColor"/></svg>',
              ];
              $iconSvg = $icons[$statusClass] ?? $icons['unknown'];

              $postSlug = trim((string)($p['slug'] ?? ''));
              $titleHref = $postSlug !== '' ? '/' . rawurlencode($postSlug) . '/' : '#';
            ?>
            <tr class="adam-row">
              <td style="text-align:center;">
                <?php if ($canBulk): ?>
                  <input type="checkbox" class="bulkCheckboxPage" name="ids[]" value="<?= (int)$p['id'] ?>">
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>

              <td>
                <a class="adam-link" href="<?= htmlspecialchars($titleHref, ENT_QUOTES, 'UTF-8') ?>"
                   title="<?= htmlspecialchars((string)($p['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars((string)($p['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                </a>
              </td>

              <td><?= htmlspecialchars((string)($p['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <span class="adam-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                      role="status" aria-label="<?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>">
                  <span class="adam-status-icon"><?= $iconSvg ?></span>
                  <span class="adam-status-text"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
                </span>
              </td>

              <td><?= htmlspecialchars(format_date_ddmmyyyy_time_bracket($p['created_at']), ENT_QUOTES, 'UTF-8') ?></td>

              <td>
                <?php
                  $authorName = $p['author_name'] ?? '-';
                  $authorUsername = trim((string)($p['author_username'] ?? ''));
                  if ($authorUsername !== '') {
                    $authorHref = '/author/' . rawurlencode($authorUsername) . '/';
                    echo '<a class="adam-penulis" href="' . htmlspecialchars($authorHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$authorName, ENT_QUOTES, 'UTF-8') . '</a>';
                  } else {
                    echo htmlspecialchars((string)$authorName, ENT_QUOTES, 'UTF-8');
                  }
                ?>
              </td>

              <td>
                <a class="adam-ubah" href="<?= htmlspecialchars($base . '/index.php?page=admin/pages/edit&id=' . (int)$p['id'], ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                &nbsp;<span class="muted-divider">|</span>&nbsp;
                <button type="button"
                        class="adam-hapus"
                        data-id="<?= (int)$p['id'] ?>"
                        data-title="<?= htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES) ?>"
                        onclick="muizPagesOpenDeleteModal(this)">
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
    </form>
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

  <div id="pagesDeleteModal" class="adam-modal" role="dialog" aria-modal="true" aria-labelledby="pagesDeleteModalTitle" style="display:none;">
    <div class="adam-modal__panel" role="document">
      <h3 id="pagesDeleteModalTitle" class="adam-modal__title">Konfirmasi Hapus</h3>
      <p id="pagesDeleteText" class="adam-modal__text">Apakah kamu yakin?</p>

      <div class="adam-modal__actions">
        <button type="button" class="adam-btn adam-btn--ghost" onclick="muizPagesCloseDeleteModal()">Batal</button>

        <form id="pagesDeleteForm" method="post" action="<?= htmlspecialchars($base . '/admin/pages/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="id" id="pagesDeleteId" value="">
          <button type="submit" class="adam-btn adam-btn--danger">Hapus</button>
        </form>
      </div>
    </div>
  </div>

  <div id="pagesBulkConfirmModal" class="adam-modal" role="dialog" aria-modal="true" aria-labelledby="pagesBulkConfirmTitle" style="display:none;">
    <div class="adam-modal__panel" role="document">
      <h3 id="pagesBulkConfirmTitle" class="adam-modal__title">Konfirmasi Bulk Action</h3>
      <p id="pagesBulkConfirmText" class="adam-modal__text">...</p>

      <div class="adam-modal__actions">
        <button type="button" class="adam-btn adam-btn--ghost" onclick="muizPagesCloseBulkConfirmModal()">Batal</button>
        <button type="button" class="adam-btn adam-btn--danger" id="pagesBulkConfirmYes">Lanjutkan</button>
      </div>
    </div>
  </div>

<script>
const selectAllPages = document.getElementById('selectAllPages');
if (selectAllPages) {
  selectAllPages.addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('.bulkCheckboxPage').forEach(cb => cb.checked = checked);
  });
}

const bulkActionPages = document.getElementById('bulkActionPages');
if (bulkActionPages) {
  bulkActionPages.addEventListener('change', function(){
    const bulkStatusPages = document.getElementById('bulkStatusPages');
    const bulkAuthorPages = document.getElementById('bulkAuthorPages');

    if (bulkStatusPages) bulkStatusPages.style.display = (this.value === 'change_status') ? 'inline-block' : 'none';
    if (bulkAuthorPages) bulkAuthorPages.style.display = (this.value === 'change_author') ? 'inline-block' : 'none';
  });
}

function muizPagesOpenDeleteModal(btn){
  const modal = document.getElementById('pagesDeleteModal');
  if (!modal) return;

  if (modal.parentNode !== document.body) document.body.appendChild(modal);

  const id = btn?.dataset?.id || '';
  const title = btn?.dataset?.title || '';

  const idInput = document.getElementById('pagesDeleteId');
  const txt = document.getElementById('pagesDeleteText');
  if (idInput) idInput.value = id;
  if (txt) txt.innerText = `Hapus halaman "${title}"?`;

  modal.onclick = function(e){ if (e.target === modal) muizPagesCloseDeleteModal(); };

  const panel = modal.querySelector('.adam-modal__panel');
  if (panel) panel.addEventListener('click', (e)=>e.stopPropagation(), { once:true });

  document.addEventListener('keydown', muizPagesDeleteEsc);

  modal.classList.add('show');
  modal.style.display = 'flex';
  document.documentElement.style.overflow = 'hidden';
  document.body.style.overflow = 'hidden';
}

function muizPagesCloseDeleteModal(){
  const modal = document.getElementById('pagesDeleteModal');
  if (!modal) return;
  modal.classList.remove('show');
  modal.style.display = 'none';
  document.documentElement.style.overflow = '';
  document.body.style.overflow = '';
  document.removeEventListener('keydown', muizPagesDeleteEsc);
}

function muizPagesDeleteEsc(e){
  if (e.key === 'Escape') muizPagesCloseDeleteModal();
}

let _pagesBulkFormRef = null;

function muizPagesGetBulkSummary(){
  const action = document.getElementById('bulkActionPages')?.value || '';
  const checked = Array.from(document.querySelectorAll('.bulkCheckboxPage:checked'));
  const count = checked.length;

  const actionLabel = (() => {
    const sel = document.getElementById('bulkActionPages');
    if (!sel) return action;
    const opt = sel.options[sel.selectedIndex];
    return opt ? (opt.textContent || action) : action;
  })();

  if (!action) return { ok:false, message:'Pilih bulk action terlebih dahulu.' };
  if (count < 1) return { ok:false, message:'Pilih minimal satu halaman.' };

  if (action === 'delete') {
    return { ok:true, type:'danger', title:`Konfirmasi: ${actionLabel}`, message:`Kamu akan menghapus (soft delete) ${count} halaman. Lanjutkan?` };
  }

  if (action === 'change_status') {
    const st = document.getElementById('bulkStatusPages')?.value || '';
    return { ok:true, type:'info', title:`Konfirmasi: ${actionLabel}`, message:`Kamu akan mengubah status ${count} halaman menjadi "${st}". Lanjutkan?` };
  }

  if (action === 'change_author') {
    const authorEl = document.getElementById('bulkAuthorPages');
    const authorId = authorEl?.value || '';
    const authorLabel = authorEl && authorEl.selectedIndex >= 0 ? (authorEl.options[authorEl.selectedIndex].textContent || '') : '';
    if (!authorId) return { ok:false, message:'Pilih author terlebih dahulu.' };
    return { ok:true, type:'info', title:`Konfirmasi: ${actionLabel}`, message:`Kamu akan mengubah author ${count} halaman menjadi "${authorLabel}". Lanjutkan?` };
  }

  return { ok:true, type:'info', title:'Konfirmasi', message:`Jalankan "${actionLabel}" untuk ${count} halaman?` };
}

function muizPagesOpenBulkConfirmModal(ev, form){
  ev.preventDefault();
  _pagesBulkFormRef = form;

  const sum = muizPagesGetBulkSummary();
  if (!sum.ok) {
    if (typeof showToast === 'function') showToast(sum.message, 'error', 4500);
    else alert(sum.message);
    return false;
  }

  const modal = document.getElementById('pagesBulkConfirmModal');
  const panel = modal?.querySelector('.adam-modal__panel');
  const titleEl = document.getElementById('pagesBulkConfirmTitle');
  const textEl  = document.getElementById('pagesBulkConfirmText');
  const yesBtn  = document.getElementById('pagesBulkConfirmYes');

  if (titleEl) titleEl.textContent = sum.title || 'Konfirmasi Bulk Action';
  if (textEl)  textEl.textContent  = sum.message || 'Lanjutkan?';

  if (yesBtn) {
    yesBtn.classList.remove('adam-btn--danger');
    if (sum.type === 'danger') yesBtn.classList.add('adam-btn--danger');

    yesBtn.onclick = function(){
      muizPagesCloseBulkConfirmModal();
      if (_pagesBulkFormRef) _pagesBulkFormRef.submit();
    };
  }

  if (modal.parentNode !== document.body) document.body.appendChild(modal);

  if (panel) panel.addEventListener('click', (e)=>e.stopPropagation(), { once:true });

  modal.onclick = function(e){ if (e.target === modal) muizPagesCloseBulkConfirmModal(); };

  document.addEventListener('keydown', muizPagesBulkEsc);

  modal.classList.add('show');
  modal.style.display = 'flex';
  document.documentElement.style.overflow = 'hidden';
  document.body.style.overflow = 'hidden';

  setTimeout(()=>{ try{ yesBtn && yesBtn.focus(); }catch(e){} }, 0);
  return false;
}

function muizPagesCloseBulkConfirmModal(){
  const modal = document.getElementById('pagesBulkConfirmModal');
  if (!modal) return;
  modal.classList.remove('show');
  modal.style.display = 'none';
  document.documentElement.style.overflow = '';
  document.body.style.overflow = '';
  document.removeEventListener('keydown', muizPagesBulkEsc);
}

function muizPagesBulkEsc(e){
  if (e.key === 'Escape') muizPagesCloseBulkConfirmModal();
}
</script>

</section>