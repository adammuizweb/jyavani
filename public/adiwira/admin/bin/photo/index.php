<?php
// /adiwira/admin/bin/photo/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
  http_response_code(403);
  exit('Forbidden');
}

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$messages = [];
$errors = [];
if (!empty($_GET['msg'])) $messages[] = urldecode($_GET['msg']);
if (!empty($_GET['err'])) $errors[] = urldecode($_GET['err']);

function adiwira_root(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos  = strpos($base, '/admin');
  return ($pos !== false) ? substr($base, 0, $pos) : $base; // => /adiwira
}
$root = adiwira_root();
$base = $root; // router utama pakai /adiwira/index.php?page=...

// auth: admin-only
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
  http_response_code(403);
  exit('Akses ditolak: belum login.');
}

$role = $_SESSION['user_role'] ?? null;
if (!$role) {
  if (function_exists('current_user_role')) {
    $role = current_user_role($pdo) ?: null;
  }
  if (!$role) {
    $st = $pdo->prepare("SELECT role FROM users WHERE id=:id AND is_deleted=0 LIMIT 1");
    $st->execute([':id' => $uid]);
    $role = $st->fetchColumn() ?: null;
  }
  $_SESSION['user_role'] = $role;
}
if ($role !== 'admin') {
  http_response_code(403);
  exit('Akses ditolak: hanya admin.');
}

$csrf = csrf_token();

// categories map (untuk tampil chip di detail)
$catsStmt = $pdo->query("SELECT id, name, parent_id FROM categories WHERE is_deleted=0 ORDER BY parent_id ASC, name ASC");
$allCats = $catsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// API endpoints (trash)
$api = [
  'list'    => $root . '/admin/bin/photo/api/photos_list.php',
  'get'     => $root . '/admin/bin/photo/api/photo_get.php',
  'restore' => $root . '/admin/bin/photo/api/photo_restore.php',
  'delp'    => $root . '/admin/bin/photo/api/photo_delete_permanent.php',
  'bulk'    => $root . '/admin/bin/photo/api/photo_bulk_action.php',
];
?>

<section class="adam-card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
    <div>
      <h2 style="margin:0">Bin / Trash — Photo Posts</h2>
      <div style="margin-top:6px;color:#64748b;font-size:12px">
        Kelola Photo Post yang sudah dihapus: restore atau hapus permanen. (Admin only)
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <a class="adam-link" href="<?= e($base) ?>/index.php?page=admin/bin/index">← Kembali ke Bin</a>
    </div>
  </div>

  <?php if (!empty($messages)): ?>
    <div class="adam-alert success" style="margin-top:12px;margin-bottom:12px;padding:.8rem 1rem;background:#e8f7ec;border:1px solid #b6e2c2;border-radius:6px;color:#246;">
      <?php foreach ($messages as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="adam-alert error" style="margin-top:12px;margin-bottom:12px;padding:.8rem 1rem;background:#fee;border:1px solid #fbb;border-radius:6px;color:#600;">
      <?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:380px 1fr;gap:14px;min-height:72vh;margin-top:14px">

    <!-- LEFT -->
    <div style="background:#fff;border-radius:14px;border:1px solid #e5e7eb;overflow:hidden">
      <div style="padding:14px 14px 12px;border-bottom:1px solid #eef2f7;background:linear-gradient(180deg,#fff,#fbfdff)">
        <h3 style="margin:0;font-size:14px;font-weight:800;color:#0f172a">Trash Photo Post</h3>

        <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
          <input id="pSearch" style="width:100%;border:1px solid #e5e7eb;border-radius:10px;padding:10px 10px;outline:none;background:#fff"
                 placeholder="Cari judul / slug / id...">
        </div>

        <div style="margin-top:8px;color:#64748b;font-size:12px;display:flex;justify-content:space-between;align-items:center;gap:8px">
          <div>
            Klik item untuk lihat detail.
          </div>
          <span style="display:inline-flex;align-items:center;justify-content:center;min-width:28px;padding:3px 9px;border-radius:999px;background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;font-weight:800;font-size:12px" id="pCount">0</span>
        </div>

        <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#64748b">
            <input type="checkbox" id="selectAll">
            Pilih semua (halaman)
          </label>

          <select id="bulkAction" style="padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-size:12px">
            <option value="">-- Bulk action --</option>
            <option value="restore">Restore</option>
            <option value="delete_permanent">Hapus Permanen</option>
          </select>

          <button type="button" id="btnBulk"
            style="border:1px solid #e5e7eb;background:#0f172a;color:#fff;padding:8px 12px;border-radius:10px;cursor:pointer;font-weight:800;font-size:12px">
            Terapkan
          </button>

          <small style="color:#64748b;font-size:12px">(Bulk untuk item yang dicentang)</small>
        </div>
      </div>

      <div id="pList" style="max-height:calc(72vh - 72px);overflow:auto"></div>

      <div id="pPager" style="padding:10px 12px;border-top:1px solid #eef2f7;display:flex;gap:8px;align-items:center;justify-content:space-between">
        <div style="display:flex;gap:6px;align-items:center">
          <button type="button" id="pPrev">Prev</button>
          <button type="button" id="pNext">Next</button>
          <span id="pPageInfo" style="font-size:12px;color:#64748b"></span>
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <span style="font-size:12px;color:#64748b">Per page</span>
          <select id="pPer">
            <option value="20">20</option>
            <option value="30" selected>30</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </div>
      </div>
    </div>

    <!-- RIGHT -->
    <div style="background:#fff;border-radius:14px;border:1px solid #e5e7eb;overflow:hidden">
      <div style="padding:14px 14px 12px;border-bottom:1px solid #eef2f7;background:linear-gradient(180deg,#fff,#fbfdff)">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap">
          <div>
            <h3 id="editorTitle" style="margin:0;font-size:14px;font-weight:800;color:#0f172a">Pilih photo post</h3>
            <div id="editorMeta" style="margin-top:4px;color:#64748b;font-size:12px">Klik salah satu item di kiri.</div>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <button type="button" id="btnRestore"
              style="display:none;border:0;background:#0ea5e9;color:#fff;padding:9px 12px;border-radius:10px;cursor:pointer;font-weight:800">
              Restore
            </button>
            <button type="button" id="btnDeletePermanent"
              style="display:none;border:0;background:#ef4444;color:#fff;padding:9px 12px;border-radius:10px;cursor:pointer;font-weight:800">
              Hapus Permanen
            </button>
          </div>
        </div>
      </div>

      <div style="padding:14px">
        <div id="emptyState" style="color:#64748b;font-size:12px">Belum ada item dipilih.</div>

        <div id="editor" style="display:none">
          <div style="display:grid;grid-template-columns:1fr 260px;gap:12px">

            <div>
              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div style="font-size:12px;font-weight:800;color:#0f172a">Judul</div>
                <div id="vTitle" style="padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc"></div>
              </div>

              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div style="font-size:12px;font-weight:800;color:#0f172a">Slug</div>
                <div id="vSlug" style="padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc"></div>
              </div>

              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div style="font-size:12px;font-weight:800;color:#0f172a">Kategori</div>
                <div id="vCats" style="border:1px solid #e5e7eb;border-radius:12px;padding:10px;min-height:44px;background:#fff"></div>
              </div>
            </div>

            <div>
              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div style="font-size:12px;font-weight:800;color:#0f172a">Status</div>
                <div id="vStatus" style="padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc"></div>
              </div>

              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div style="font-size:12px;font-weight:800;color:#0f172a">Info</div>
                <div id="vInfo" style="color:#64748b;font-size:12px">—</div>
              </div>

              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div style="font-size:12px;font-weight:800;color:#0f172a">Deleted at</div>
                <div id="vDeletedAt" style="padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc"></div>
              </div>
            </div>

          </div>

          <div style="height:1px;background:#eef2f7;margin:12px 0"></div>

          <div style="border:1px solid #eef2f7;border-radius:12px;padding:12px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
              <div>
                <div style="font-weight:800;color:#0f172a">Foto</div>
                <div style="font-size:12px;color:#64748b;margin-top:2px">Preview foto (read-only). Foto pertama adalah cover.</div>
              </div>
              <span id="vCount" style="display:inline-flex;min-width:28px;justify-content:center;align-items:center;padding:4px 10px;border-radius:999px;background:#0ea5e9;color:#fff;font-weight:800;font-size:12px">0</span>
            </div>

            <div id="vEmptyMedia" style="margin-top:10px;color:#64748b;font-size:12px">Tidak ada foto.</div>
            <div id="vGrid" style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px"></div>
          </div>

        </div>
      </div>
    </div>

  </div>
</section>

<div id="toast" style="position:fixed;right:16px;bottom:16px;background:#0f172a;color:#fff;padding:10px 12px;border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,.18);font-size:13px;opacity:0;transform:translateY(10px);transition:all .18s ease;z-index:99999"></div>

<script>
(function(){
  const CSRF = <?= json_encode($csrf) ?>;
  const API  = <?= json_encode($api) ?>;
  const ALL_CATS = <?= json_encode($allCats, JSON_UNESCAPED_UNICODE) ?>;

  const el = (id)=>document.getElementById(id);

  // toast
  const toast = el('toast');
  let toastTimer = null;
  function showToast(msg){
    toast.textContent = msg;
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(()=>{
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(10px)';
    }, 1400);
  }

  function escapeHTML(s){
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  }
  function cutText(s, n){
    s = String(s ?? '').replace(/\s+/g,' ').trim();
    return s.length <= n ? s : (s.slice(0,n-1) + '…');
  }

  async function fetchJSON(url, payload=null){
    const opt = payload ? {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    } : { method:'GET', credentials:'same-origin' };

    const res = await fetch(url, opt);
    const txt = await res.text();
    let data = null;
    try { data = JSON.parse(txt); } catch(e){ throw new Error('Response bukan JSON: ' + txt.slice(0,200)); }
    if (!res.ok || !data || data.ok !== true) {
      throw new Error((data && data.error) ? data.error : ('HTTP ' + res.status));
    }
    return data;
  }

  // cat map
  const catMap = new Map();
  (ALL_CATS || []).forEach(c => catMap.set(Number(c.id), String(c.name)));

  // state
  let rows = [];
  let active = null;
  let q = '';
  let page = 1;
  let per  = 30;
  let total = 0;

  // els
  const pList = el('pList');
  const pCount = el('pCount');
  const pSearch = el('pSearch');
  const pPrev = el('pPrev');
  const pNext = el('pNext');
  const pPer = el('pPer');
  const pPageInfo = el('pPageInfo');

  const selectAll = el('selectAll');
  const bulkAction = el('bulkAction');
  const btnBulk = el('btnBulk');

  const emptyState = el('emptyState');
  const editor = el('editor');
  const editorTitle = el('editorTitle');
  const editorMeta = el('editorMeta');

  const btnRestore = el('btnRestore');
  const btnDeletePermanent = el('btnDeletePermanent');

  const vTitle = el('vTitle');
  const vSlug  = el('vSlug');
  const vCats  = el('vCats');
  const vStatus = el('vStatus');
  const vInfo  = el('vInfo');
  const vDeletedAt = el('vDeletedAt');
  const vGrid  = el('vGrid');
  const vEmptyMedia = el('vEmptyMedia');
  const vCount = el('vCount');

  function renderPager(){
    const pages = Math.max(1, Math.ceil(total / Math.max(1, per)));
    pPrev.disabled = (page <= 1);
    pNext.disabled = (page >= pages);
    pPageInfo.textContent = `Page ${page} / ${pages} • ${total} item`;
  }

  function statusChip(status){
    status = String(status || 'draft');
    const style = (status === 'published')
      ? 'background:#ecfeff;border-color:#a5f3fc;color:#155e75'
      : (status === 'private')
        ? 'background:#f1f5f9;border-color:#cbd5e1;color:#0f172a'
        : 'background:#fff7ed;border-color:#fed7aa;color:#9a3412';
    return `<span style="font-size:11px;padding:3px 8px;border-radius:999px;border:1px solid #e5e7eb;${style}">${escapeHTML(status)}</span>`;
  }

  function renderList(){
    pList.innerHTML = '';
    pCount.textContent = String(total || 0);

    if (!rows.length){
      pList.innerHTML = '<div style="padding:12px;color:#64748b;font-size:12px">Trash kosong.</div>';
      return;
    }

    rows.forEach(r => {
      const row = document.createElement('div');
      row.className = 'binp-row' + (active && Number(active.id) === Number(r.id) ? ' binp-row-active' : '');
      row.dataset.id = r.id;

      row.style.display = 'flex';
      row.style.gap = '10px';
      row.style.padding = '11px 12px';
      row.style.borderBottom = '1px solid #f1f5f9';
      row.style.cursor = 'pointer';
      row.style.alignItems = 'center';

      if (active && Number(active.id) === Number(r.id)){
        row.style.background = '#fff1f2';
        row.style.outline = '2px solid rgba(239,68,68,.22)';
      }

      const thumb = r.thumbnail
        ? `<div style="width:46px;height:46px;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb;background:#f8fafc;flex:0 0 auto">
             <img src="${escapeHTML(r.thumbnail)}" alt="" style="width:100%;height:100%;object-fit:cover;display:block">
           </div>`
        : `<div style="width:46px;height:46px;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb;background:#f8fafc;flex:0 0 auto"></div>`;

      row.innerHTML = `
        <div style="flex:0 0 auto;width:26px;display:flex;justify-content:center">
          <input type="checkbox" class="chk" data-id="${Number(r.id)}">
        </div>
        ${thumb}
        <div style="min-width:0;flex:1">
          <div style="font-weight:900;color:#0f172a;font-size:13px;line-height:1.2">#${Number(r.id)} · ${escapeHTML(cutText(r.title || '(tanpa judul)', 80))}</div>
          <div style="margin-top:4px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            ${statusChip(r.status)}
            <span style="font-size:11px;padding:3px 8px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;color:#0f172a">${Number(r.media_count||0)} foto</span>
            <span style="font-size:11px;color:#64748b">deleted: ${escapeHTML(r.deleted_at || '-')}</span>
          </div>
        </div>
        <button type="button" class="btn-restore"
          style="border:1px solid #bae6fd;background:#ecfeff;color:#075985;border-radius:10px;width:40px;height:38px;cursor:pointer;flex:0 0 auto"
          title="Restore">↩</button>
        <button type="button" class="btn-del"
          style="border:1px solid #fecaca;background:#fff;color:#dc2626;border-radius:10px;width:40px;height:38px;cursor:pointer;flex:0 0 auto"
          title="Hapus permanen">🗑</button>
      `;

      // select row
      row.addEventListener('click', (ev)=>{
        if (ev.target && (ev.target.closest('.btn-restore') || ev.target.closest('.btn-del') || ev.target.closest('.chk'))) return;
        selectPhoto(r.id);
      });

      // restore single
      row.querySelector('.btn-restore').addEventListener('click', async (ev)=>{
        ev.stopPropagation();
        await restoreOne(r.id);
      });

      // delete permanent single
      row.querySelector('.btn-del').addEventListener('click', async (ev)=>{
        ev.stopPropagation();
        await deletePermanentOne(r.id);
      });

      pList.appendChild(row);
    });
  }

  async function loadList(){
    const url = API.list + '?q=' + encodeURIComponent(q) + '&p=' + encodeURIComponent(page) + '&per=' + encodeURIComponent(per);
    const data = await fetchJSON(url);
    rows = data.rows || [];
    total = Number(data.total || 0);
    renderList();
    renderPager();
    if (selectAll) selectAll.checked = false;
  }

  function renderActive(photo){
    active = photo;

    emptyState.style.display = 'none';
    editor.style.display = 'block';
    btnRestore.style.display = 'inline-block';
    btnDeletePermanent.style.display = 'inline-block';

    editorTitle.textContent = 'Detail Photo #' + Number(photo.id);
    editorMeta.textContent  = 'Trash mode • Restore atau hapus permanen';

    vTitle.textContent = photo.title || '(tanpa judul)';
    vSlug.textContent  = photo.slug || '-';
    vStatus.textContent = photo.status || '-';

    vDeletedAt.textContent = photo.deleted_at || '-';
    vInfo.textContent = `Items: ${(photo.items||[]).length} • Updated: ${photo.updated_at || '-'} • Created: ${photo.created_at || '-'}`;

    // categories chips
    const ids = (photo.category_ids || []).map(Number).filter(Boolean);
    if (!ids.length){
      vCats.innerHTML = '<span style="color:#64748b;font-size:12px">—</span>';
    } else {
      vCats.innerHTML = ids.map(id => {
        const name = catMap.get(id) || ('#' + id);
        return `<span style="display:inline-flex;align-items:center;gap:6px;margin:4px 6px 0 0;padding:4px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;font-size:12px">${escapeHTML(name)}</span>`;
      }).join('');
    }

    // grid items
    const items = Array.isArray(photo.items) ? photo.items : [];
    vCount.textContent = String(items.length);

    vGrid.innerHTML = '';
    if (!items.length){
      vEmptyMedia.style.display = 'block';
      return;
    }
    vEmptyMedia.style.display = 'none';

    items.forEach((it, idx)=>{
      const url = it.thumb || it.url || it.src || '';
      const card = document.createElement('div');
      card.className = 'g-item';
      card.style.position = 'relative';
      card.style.border = '1px solid #e5e7eb';
      card.style.borderRadius = '12px';
      card.style.overflow = 'hidden';
      card.style.background = '#fff';

      const badge = (idx === 0)
        ? `<div style="position:absolute;left:8px;top:8px;background:rgba(14,165,233,.92);color:#fff;font-size:11px;font-weight:800;border-radius:999px;padding:4px 8px">Cover</div>`
        : '';

      card.innerHTML = `
        ${badge}
        <div style="height:120px;background:#f8fafc">
          ${url ? `<img src="${escapeHTML(url)}" alt="" style="width:100%;height:120px;object-fit:cover;display:block">` : ''}
        </div>
      `;
      vGrid.appendChild(card);
    });

    // re-highlight active in list
    renderList();
  }

  async function selectPhoto(id){
    const data = await fetchJSON(API.get + '?id=' + encodeURIComponent(id));
    renderActive(data.photo);
  }

  function getCheckedIds(){
    return Array.from(document.querySelectorAll('.chk:checked'))
      .map(cb => Number(cb.getAttribute('data-id')))
      .filter(Boolean);
  }

  async function restoreOne(id){
    if (!confirm('Restore photo post ini?')) return;
    await fetchJSON(API.restore, { csrf_token: CSRF, id: Number(id) });
    showToast('Direstore');
    if (active && Number(active.id) === Number(id)) {
      active = null;
      editor.style.display = 'none';
      emptyState.style.display = 'block';
      btnRestore.style.display = 'none';
      btnDeletePermanent.style.display = 'none';
      editorTitle.textContent = 'Pilih photo post';
      editorMeta.textContent  = 'Klik salah satu item di kiri.';
    }
    await loadList();
  }

  async function deletePermanentOne(id){
    if (!confirm('Hapus permanen photo post ini? Ini tidak bisa dibatalkan.')) return;
    await fetchJSON(API.delp, { csrf_token: CSRF, id: Number(id) });
    showToast('Dihapus permanen');
    if (active && Number(active.id) === Number(id)) {
      active = null;
      editor.style.display = 'none';
      emptyState.style.display = 'block';
      btnRestore.style.display = 'none';
      btnDeletePermanent.style.display = 'none';
      editorTitle.textContent = 'Pilih photo post';
      editorMeta.textContent  = 'Klik salah satu item di kiri.';
    }
    await loadList();
  }

  async function runBulk(){
    const action = bulkAction.value;
    if (!action) { alert('Pilih bulk action terlebih dahulu.'); return; }
    const ids = getCheckedIds();
    if (!ids.length) { alert('Pilih minimal satu item.'); return; }

    if (action === 'delete_permanent') {
      if (!confirm('Yakin ingin menghapus permanen semua item terpilih?')) return;
    } else {
      if (!confirm('Jalankan bulk "' + action + '" untuk ' + ids.length + ' item?')) return;
    }

    await fetchJSON(API.bulk, { csrf_token: CSRF, action, ids });
    showToast('Bulk sukses');
    // reset UI
    active = null;
    editor.style.display = 'none';
    emptyState.style.display = 'block';
    btnRestore.style.display = 'none';
    btnDeletePermanent.style.display = 'none';
    await loadList();
  }

  // events
  let t = null;
  pSearch.addEventListener('input', ()=>{
    clearTimeout(t);
    t = setTimeout(async ()=>{
      q = pSearch.value.trim();
      page = 1;
      await loadList();
    }, 180);
  });

  pPer.addEventListener('change', async ()=>{
    per = Number(pPer.value || 30);
    page = 1;
    await loadList();
  });

  pPrev.addEventListener('click', async ()=>{
    if (page > 1) { page--; await loadList(); }
  });
  pNext.addEventListener('click', async ()=>{
    const pages = Math.max(1, Math.ceil(total / Math.max(1, per)));
    if (page < pages) { page++; await loadList(); }
  });

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      const checked = this.checked;
      document.querySelectorAll('.chk').forEach(cb => cb.checked = checked);
    });
  }

  btnBulk.addEventListener('click', runBulk);

  btnRestore.addEventListener('click', async ()=>{
    if (!active) return;
    await restoreOne(active.id);
  });

  btnDeletePermanent.addEventListener('click', async ()=>{
    if (!active) return;
    await deletePermanentOne(active.id);
  });

  // auto-hide alerts
  setTimeout(() => {
    const alert = document.querySelector('.adam-alert');
    if (alert) {
      alert.style.transition = 'opacity 0.5s ease';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 600);
    }
  }, 3000);

  // init
  loadList().catch(err=>{
    console.error(err);
    alert('Gagal load bin photo: ' + err.message);
  });

})();
</script>
