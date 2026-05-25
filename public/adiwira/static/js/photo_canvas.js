(function () {
  if (window.PhotoCanvas && window.PhotoCanvas.__loaded) return;

  const instances = {}; // canvasId => instance

  function safeJsonParse(s, fallback) {
    try { return JSON.parse(s || ''); } catch (e) { return fallback; }
  }

  // ============================
  // MEDIA NORMALIZER
  // ============================
  function normalizeMedia(detail) {
    if (!detail) return null;
    const m = (detail.media && typeof detail.media === 'object') ? detail.media : detail;

    const id = (m.id != null) ? parseInt(m.id, 10) : 0;
    const url = (m.url != null) ? String(m.url || '') : '';

    if (!id || !url) return null;

    return {
      id: id,
      url: url,
      caption: String(m.caption || ''),
      alt: String(m.alt || ''),
      // optional (kalau modal kamu tidak kirim, aman jadi '')
      link_url: String(m.link_url || ''),
      link_target: String(m.link_target || '')
    };
  }

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // ============================
  // 3) PhotoCanvas core
  // ============================
  function init(opts) {
    opts = opts || {};
    const canvas = document.getElementById(opts.canvasId);
    const input = document.getElementById(opts.inputId);
    const addBtn = document.getElementById(opts.addBtnId);
    if (!canvas || !input || !addBtn) return null;

    if (instances[opts.canvasId]) return instances[opts.canvasId];

    const emptyEl = document.getElementById(opts.canvasId + '-empty');
    const countEl = document.getElementById(opts.canvasId + '-count');
    const modalUrl = opts.modalUrl || '/adiwira/admin/modal_img/index.php?embedded=1';

    function readItems() {
      const arr = safeJsonParse(input.value, []);
      if (!Array.isArray(arr)) return [];
      return arr.map(function (x) {
        if (!x) return null;
        const id = x.id != null ? parseInt(x.id, 10) : 0;
        const url = x.url != null ? String(x.url || '') : '';
        if (!id || !url) return null;
        return {
          id: id, url: url,
          caption: String(x.caption || ''),
          alt: String(x.alt || ''),
          link_url: String(x.link_url || ''),
          link_target: String(x.link_target || '')
        };
      }).filter(Boolean);
    }

    function writeItems(items, silent) {
      input.value = JSON.stringify(items || []);
      if (countEl) countEl.textContent = String((items || []).length);
      if (emptyEl) emptyEl.style.display = (items && items.length) ? 'none' : 'block';

      if (!silent) {
        try {
          document.dispatchEvent(new CustomEvent('photo_canvas:change', {
            detail: { canvasId: opts.canvasId, items: (items || []) }
          }));
        } catch (e) {}
      }
    }

    function render() {
      const items = readItems();
      writeItems(items, true);

      if (!items.length) {
        canvas.innerHTML = '';
        return;
      }

      canvas.innerHTML = items.map(function (it, idx) {
        const badge = (idx === 0) ? '<span class="phtc-badge">Cover</span>' : '';
        return (
          '<div class="phtc-item" draggable="true" data-idx="' + idx + '" data-id="' + it.id + '">' +
            '<img class="phtc-img" src="' + escapeHtml(it.url) + '" alt="">' +
            '<button type="button" class="phtc-remove" title="Hapus" data-idx="' + idx + '">✕</button>' +
            badge +
          '</div>'
        );
      }).join('');
    }

    function addItem(media) {
      const items = readItems();
      if (items.some(function (x) { return String(x.id) === String(media.id); })) return;
      items.push(media);
      writeItems(items, false);
      render();
    }

    async function openPicker() {
      // ✅ pakai sistem modal gallery kamu
      const detail = await window.openMediaSelector({ url: modalUrl, maxWidth: '980px' });
      const media = normalizeMedia(detail);
      if (!media) return;
      addItem(media);
    }

    // remove
    canvas.addEventListener('click', function (e) {
      const rm = e.target && e.target.closest ? e.target.closest('.phtc-remove') : null;
      if (!rm) return;
      const idx = parseInt(rm.getAttribute('data-idx'), 10);
      if (!Number.isFinite(idx)) return;
      const items = readItems();
      items.splice(idx, 1);
      writeItems(items, false);
      render();
    });

    // drag reorder
    let dragFrom = null;
    canvas.addEventListener('dragstart', function (e) {
      const item = e.target && e.target.closest ? e.target.closest('.phtc-item') : null;
      if (!item) return;
      dragFrom = parseInt(item.getAttribute('data-idx'), 10);
      try { e.dataTransfer.effectAllowed = 'move'; } catch (err) {}
    });

    canvas.addEventListener('dragover', function (e) { e.preventDefault(); });

    canvas.addEventListener('drop', function (e) {
      e.preventDefault();
      const toEl = e.target && e.target.closest ? e.target.closest('.phtc-item') : null;
      if (!toEl || dragFrom === null) return;

      const dragTo = parseInt(toEl.getAttribute('data-idx'), 10);
      if (!Number.isFinite(dragTo)) return;

      const items = readItems();
      if (dragFrom < 0 || dragFrom >= items.length) return;

      const moved = items.splice(dragFrom, 1)[0];
      items.splice(dragTo, 0, moved);

      dragFrom = null;
      writeItems(items, false);
      render();
    });

    addBtn.addEventListener('click', openPicker);

    const api = {
      getItems: () => readItems(),
      setItems: (items, options) => {
        const silent = !!(options && options.silent);
        writeItems(Array.isArray(items) ? items : [], silent);
        render();
      },
      openPicker: () => openPicker(),
      render: () => render()
    };

    instances[opts.canvasId] = api;
    render();
    return api;
  }

  window.PhotoCanvas = {
    init,
    get: (canvasId) => instances[canvasId] || null,
    __loaded: true
  };

  function bootAll() {
    const list = window.PHOTO_CANVAS_BOOT || [];
    if (!Array.isArray(list)) return;
    list.forEach(function (cfg) {
      try { init(cfg); } catch (e) { console.error(e); }
    });
    window.PHOTO_CANVAS_BOOT = [];
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAll);
  } else {
    bootAll();
  }
})();
