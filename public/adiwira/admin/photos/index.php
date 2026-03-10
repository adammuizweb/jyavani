<?php
declare(strict_types=1);

// /adiwira/admin/photos/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../_guard.php';
[$uid, $role] = adiwira_require_editorial($pdo, false);

if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$csrf = csrf_token();

// categories for checkbox tree
$catsStmt = $pdo->query("SELECT id, name, parent_id FROM categories WHERE is_deleted=0 ORDER BY parent_id ASC, name ASC");
$allCats = $catsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// API endpoints
$api = [
    'list'    => $base . '/admin/photos/api/photos_list.php',
    'get'     => $base . '/admin/photos/api/photo_get.php',
    'save'    => $base . '/admin/photos/api/photo_save.php',
    'del'     => $base . '/admin/photos/api/photo_delete.php',
    'reorder' => $base . '/admin/photos/api/photos_reorder.php',
];

// canvas config
$canvas_id  = 'photoCanvas';
$input_id   = 'photoItemsJson';
$add_btn_id = 'btnAddMediaToCanvas';
$modal_url  = '/adiwira/admin/modal_img/index.php?embedded=1';
?>
<section class="adam-card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
    <div>
      <h2 style="margin:0">Photo Master Builder</h2>
      <div style="margin-top:6px;font-size:12px" class="pht-muted">
        1 layar untuk kelola Photo Post + urutan gambar (drag), auto-save, dan cover otomatis.
      </div>
    </div>
  </div>

  <div class="pht-grid">

    <div class="pht-pane">
      <div class="pht-pane-head">
        <h3 class="pht-h3">Daftar Photo Post</h3>

        <div class="pht-rowbar">
          <input id="pSearch" class="pht-input" placeholder="Cari judul / slug...">
          <button id="btnAdd" type="button" class="pht-btn pht-btn--primary">+ Photo</button>
        </div>

        <div class="pht-sub" style="margin-top:8px">
          Klik item untuk edit. Cover diambil dari foto pertama.
          <span class="pht-count" id="pCount" style="float:right">0</span>
        </div>
      </div>

      <div id="pList" class="pht-list"></div>

      <div id="pPager">
        <div style="display:flex;gap:6px;align-items:center">
          <button type="button" id="pPrev">Prev</button>
          <button type="button" id="pNext">Next</button>
          <span id="pPageInfo"></span>
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <span>Per page</span>
          <select id="pPer">
            <option value="30">30</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </div>
      </div>
    </div>

    <div class="pht-pane">
      <div class="pht-pane-head">
        <h3 id="editorTitle" class="pht-h3">Pilih photo post</h3>
        <div id="editorMeta" class="pht-sub" style="margin-top:4px">Klik salah satu item di kiri untuk mulai edit.</div>
      </div>

      <div class="pht-editor-body">
        <div id="emptyState" class="pht-help">Belum ada photo post dipilih.</div>

        <div id="editor" style="display:none">
          <div style="display:grid;grid-template-columns:1fr 260px;gap:12px">

            <div>
              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div class="pht-label">Judul</div>
                <input id="title" class="pht-input" placeholder="Judul photo post...">
              </div>

              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div class="pht-label">Slug</div>
                <input id="slug" class="pht-input" placeholder="opsional, otomatis jika kosong">
              </div>

              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div class="pht-label">Kategori</div>
                <div id="catPanel" style="border:1px solid var(--adam-border);border-radius:12px;padding:10px;max-height:220px;overflow:auto;background:var(--adam-surface-4)"></div>
                <div class="pht-help">Centang kategori untuk photo post ini.</div>
              </div>
            </div>

            <div>
              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div class="pht-label">Status</div>
                <select id="status" class="pht-select">
                  <option value="draft">draft</option>
                  <option value="published">published</option>
                  <option value="private">private</option>
                </select>
              </div>

              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div class="pht-label">Info</div>
                <div id="infoBox" style="color:#64748b;font-size:12px">—</div>
              </div>

              <div style="display:grid;gap:6px;margin-bottom:10px">
                <div class="pht-label">Auto-save</div>
                <div style="color:#64748b;font-size:12px">Tersimpan otomatis setelah kamu berhenti mengetik / mengubah canvas.</div>
              </div>
            </div>

          </div>

          <div class="pht-divider"></div>

          <?php include __DIR__ . '/_photo_canvas.php'; ?>
        </div>
      </div>
    </div>

  </div>
</section>

<div id="toast" class="pht-toast"></div>

<script>
  window.PHOTO_CANVAS_BOOT = window.PHOTO_CANVAS_BOOT || [];
  window.PHOTO_CANVAS_BOOT.push({
    canvasId: <?= json_encode($canvas_id) ?>,
    inputId: <?= json_encode($input_id) ?>,
    addBtnId: <?= json_encode($add_btn_id) ?>,
    modalUrl: <?= json_encode($modal_url) ?>
  });
</script>
<script src="<?= e($base) ?>/static/js/photo_canvas.js?v=20260205"></script>

<script>
(function(){
  const CSRF = <?= json_encode($csrf) ?>;
  const API  = <?= json_encode($api) ?>;
  const ALL_CATS = <?= json_encode($allCats, JSON_UNESCAPED_UNICODE) ?>;

  const el = (id)=>document.getElementById(id);

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
    try {
      data = JSON.parse(txt);
    } catch(e) {
      throw new Error('Response bukan JSON: ' + txt.slice(0, 200));
    }

    if (!res.ok || !data || data.ok !== true) {
      throw new Error((data && data.error) ? data.error : ('HTTP ' + res.status));
    }
    return data;
  }

  let photos = [];
  let active = null;
  let searchTerm = '';
  let saveTimer = null;
  let orderTimer = null;
  let hydrating = false;
  let currentPage = 1;
  let totalPages = 1;
  let perPage = 30;

  let suppressClickUntil = 0;
  function suppressClick(ms = 220){
    suppressClickUntil = Date.now() + ms;
  }
  function isClickSuppressed(){
    return Date.now() < suppressClickUntil;
  }

  const pList = el('pList');
  const pCount = el('pCount');
  const pSearch = el('pSearch');
  const btnAdd = el('btnAdd');
  const pPrev = el('pPrev');
  const pNext = el('pNext');
  const pPageInfo = el('pPageInfo');
  const pPer = el('pPer');

  const editorTitle = el('editorTitle');
  const editorMeta  = el('editorMeta');
  const emptyState  = el('emptyState');
  const editor      = el('editor');

  const titleEl  = el('title');
  const slugEl   = el('slug');
  const statusEl = el('status');
  const catPanel = el('catPanel');
  const infoBox  = el('infoBox');

  function escapeHTML(s){
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  }

  function cutText(s, n){
    s = String(s).replace(/\s+/g,' ').trim();
    return s.length <= n ? s : (s.slice(0, n - 1) + '…');
  }

  function statusClass(status){
    status = String(status || 'draft').toLowerCase();
    if (status === 'published') return 'published';
    if (status === 'private') return 'private';
    return 'draft';
  }

  function renderPager(total){
    pPageInfo.textContent = 'Page ' + currentPage + ' / ' + totalPages + ' • Total ' + total;
    pPrev.disabled = currentPage <= 1;
    pNext.disabled = currentPage >= totalPages;
    pPer.value = String(perPage);
  }

  function renderList(){
    pCount.textContent = String(photos.length);
    pList.innerHTML = '';

    if (!photos.length){
      pList.innerHTML = '<div style="padding:12px;font-size:12px" class="pht-muted">Tidak ada photo post.</div>';
      return;
    }

    const dragEnabled = !searchTerm;

    photos.forEach((p)=>{
      const row = document.createElement('div');
      row.className = 'pht-row'
        + (active && Number(active.id) === Number(p.id) ? ' pht-row-active' : '')
        + (!dragEnabled ? ' is-nodrag' : '');
      row.dataset.id = p.id;
      row.setAttribute('draggable', dragEnabled ? 'true' : 'false');

      const thumb = p.thumbnail
        ? `<div class="pht-thumb"><img src="${escapeHTML(p.thumbnail)}" alt=""></div>`
        : `<div class="pht-thumb"></div>`;

      const st = statusClass(p.status);

      row.innerHTML = `
        ${thumb}
        <div style="min-width:0;flex:1">
          <div class="pht-title">#${p.id} · ${escapeHTML(cutText(p.title || '(tanpa judul)', 80))}</div>
          <div class="pht-meta">
            <span class="pht-chip ${st}">${escapeHTML(p.status || 'draft')}</span>
            <span class="pht-chip count">${Number(p.media_count || 0)} foto</span>
          </div>
        </div>
        <button type="button" class="pht-del-btn" title="Hapus">🗑</button>
      `;

      row.addEventListener('click', ()=>{
        if (isClickSuppressed()) return;
        selectPhoto(p.id);
      });

      row.querySelector('.pht-del-btn').addEventListener('click', async (e)=>{
        e.stopPropagation();
        await delById(p.id);
      });

      pList.appendChild(row);
    });
  }

  async function loadList(){
    const qs = new URLSearchParams();
    qs.set('page', String(currentPage));
    qs.set('per_page', String(perPage));
    if (searchTerm) qs.set('q', searchTerm);

    const data = await fetchJSON(API.list + '?' + qs.toString());
    photos = data.photos || [];
    currentPage = Number(data.page || 1);
    perPage = Number(data.per_page || perPage);
    totalPages = Number(data.total_pages || 1);

    renderList();
    renderPager(Number(data.total || 0));
  }

  function buildCatTree(cats){
    const map = new Map();
    cats.forEach(c=>{
      const pid = c.parent_id == null ? 0 : Number(c.parent_id);
      if (!map.has(pid)) map.set(pid, []);
      map.get(pid).push(c);
    });
    map.forEach(arr => arr.sort((a,b)=>String(a.name).localeCompare(String(b.name))));

    function render(pid, depth){
      const arr = map.get(pid) || [];
      let html = '';
      for (const c of arr){
        html += `<label style="display:block;font-size:13px;margin:4px 0">
          ${'<span style="display:inline-block;width:14px"></span>'.repeat(depth)}
          <input type="checkbox" data-cid="${Number(c.id)}"> ${escapeHTML(c.name)}
        </label>`;
        html += render(Number(c.id), depth + 1);
      }
      return html;
    }
    return render(0, 0);
  }

  function setCatSelected(selectedIds){
    const set = new Set((selectedIds || []).map(Number));
    catPanel.querySelectorAll('input[type=checkbox][data-cid]').forEach(cb=>{
      const id = Number(cb.getAttribute('data-cid'));
      cb.checked = set.has(id);
    });
  }

  function getCatSelected(){
    const out = [];
    catPanel.querySelectorAll('input[type=checkbox][data-cid]:checked').forEach(cb=>{
      out.push(Number(cb.getAttribute('data-cid')));
    });
    return out;
  }

  function scheduleSave(){
    if (!active || hydrating) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNow, 520);
  }

  async function saveNow(){
    if (!active) return;

    const canvas = window.PhotoCanvas ? window.PhotoCanvas.get(<?= json_encode($canvas_id) ?>) : null;
    const items = canvas ? canvas.getItems() : [];

    const payload = {
      csrf_token: CSRF,
      id: active.id,
      title: titleEl.value,
      slug: slugEl.value,
      status: statusEl.value,
      category_ids: getCatSelected(),
      items: items
    };

    const data = await fetchJSON(API.save, payload);

    if (data.slug != null) {
      active.slug = data.slug;
      slugEl.value = data.slug;
    }

    if (data.thumbnail !== undefined) active.thumbnail = data.thumbnail;

    showToast('Tersimpan');
    await loadList();
  }

  async function selectPhoto(id){
    const data = await fetchJSON(API.get + '?id=' + encodeURIComponent(id));
    active = data.photo;

    hydrating = true;

    emptyState.style.display = 'none';
    editor.style.display = 'block';

    editorTitle.textContent = 'Edit Photo #' + active.id;
    editorMeta.textContent  = 'Auto-save aktif • Drag foto untuk urutkan';

    titleEl.value  = active.title || '';
    slugEl.value   = active.slug || '';
    statusEl.value = active.status || 'draft';
    infoBox.textContent = `Items: ${(active.items||[]).length} • Updated: ${active.updated_at || '-'}`;

    setCatSelected(active.category_ids || []);

    const canvas = window.PhotoCanvas ? window.PhotoCanvas.get(<?= json_encode($canvas_id) ?>) : null;
    if (canvas) canvas.setItems(active.items || [], { silent: true });

    renderList();
    hydrating = false;
  }

  async function addNew(){
    const payload = {
      csrf_token: CSRF,
      id: 0,
      title: 'photo gallery',
      slug: '',
      status: 'draft',
      category_ids: [],
      items: []
    };

    const data = await fetchJSON(API.save, payload);
    showToast('Photo dibuat');
    currentPage = 1;
    await loadList();
    await selectPhoto(data.id);
  }

  async function delById(id){
    if (!confirm('Hapus photo post ini?')) return;

    await fetchJSON(API.del, { csrf_token: CSRF, id: Number(id) });
    showToast('Dihapus');

    if (active && Number(active.id) === Number(id)){
      active = null;
      editor.style.display = 'none';
      emptyState.style.display = 'block';
      editorTitle.textContent = 'Pilih photo post';
      editorMeta.textContent  = 'Klik salah satu item di kiri untuk mulai edit.';
    }

    await loadList();
  }

  function scheduleOrderSave(){
    clearTimeout(orderTimer);
    orderTimer = setTimeout(saveOrderNow, 450);
  }

  async function saveOrderNow(){
    if (searchTerm) return;

    const ids = (photos || []).map(p => Number(p.id)).filter(Boolean);
    if (ids.length < 2) return;

    await fetchJSON(API.reorder, { csrf_token: CSRF, ids });
    showToast('Urutan disimpan');
  }

  function moveById(arr, fromId, toId){
    const fromIdx = arr.findIndex(x => Number(x.id) === Number(fromId));
    const toIdx   = arr.findIndex(x => Number(x.id) === Number(toId));
    if (fromIdx < 0 || toIdx < 0 || fromIdx === toIdx) return arr;

    const copy = arr.slice();
    const [moved] = copy.splice(fromIdx, 1);
    copy.splice(toIdx, 0, moved);
    return copy;
  }

  btnAdd.addEventListener('click', addNew);

  let dragFromId = null;

  pList.addEventListener('dragstart', (e)=>{
    if (searchTerm) return;

    const row = e.target && e.target.closest ? e.target.closest('.pht-row') : null;
    if (!row) return;
    if (e.target.closest && e.target.closest('.pht-del-btn')) return;

    dragFromId = Number(row.dataset.id || 0);
    if (!dragFromId) return;

    suppressClick(300);
    try { e.dataTransfer.effectAllowed = 'move'; } catch(err){}
  });

  pList.addEventListener('dragover', (e)=>{
    if (!dragFromId) return;
    e.preventDefault();
  });

  pList.addEventListener('drop', (e)=>{
    if (!dragFromId) return;
    e.preventDefault();

    const row = e.target && e.target.closest ? e.target.closest('.pht-row') : null;
    const toId = row ? Number(row.dataset.id || 0) : 0;

    suppressClick(300);

    if (!toId || toId === dragFromId) { dragFromId = null; return; }
    if (searchTerm) { dragFromId = null; return; }

    photos = moveById(photos, dragFromId, toId);
    dragFromId = null;

    renderList();
    scheduleOrderSave();
  });

  pList.addEventListener('dragend', ()=>{
    dragFromId = null;
    suppressClick(300);
  });

  titleEl.addEventListener('input', scheduleSave);
  slugEl.addEventListener('input', scheduleSave);
  statusEl.addEventListener('change', scheduleSave);

  catPanel.addEventListener('change', (e)=>{
    if (e.target && e.target.matches('input[type=checkbox][data-cid]')) scheduleSave();
  });

  document.addEventListener('photo_canvas:change', (e)=>{
    if (!e || !e.detail) return;
    if (e.detail.canvasId !== <?= json_encode($canvas_id) ?>) return;
    scheduleSave();
  });

  let t = null;
  pSearch.addEventListener('input', ()=>{
    clearTimeout(t);
    t = setTimeout(async ()=>{
      searchTerm = pSearch.value.trim();
      currentPage = 1;
      await loadList();
    }, 160);
  });

  pPrev.addEventListener('click', async ()=>{
    if (currentPage <= 1) return;
    currentPage--;
    await loadList();
  });

  pNext.addEventListener('click', async ()=>{
    if (currentPage >= totalPages) return;
    currentPage++;
    await loadList();
  });

  pPer.addEventListener('change', async ()=>{
    perPage = Math.max(5, Math.min(100, parseInt(pPer.value || '30', 10) || 30));
    currentPage = 1;
    await loadList();
  });

  catPanel.innerHTML = buildCatTree(ALL_CATS);

  loadList().catch(err=>{
    console.error(err);
    alert('Gagal load list: ' + err.message);
  });
})();
</script>