(function () {
  if (window.PhotoCanvas && window.PhotoCanvas.__loaded) return;

  const instances = {}; // canvasId => instance

  function safeJsonParse(s, fallback) {
    try { return JSON.parse(s || ''); } catch (e) { return fallback; }
  }

  // ============================
  // 1) MODAL HELPERS (compatible)
  // ============================
  // inject HTML to container and execute scripts (preserve order-ish)
  function injectHtmlWithScriptsTo(container, html) {
    try {
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');

      // build inner html excluding <script>
      let bodyHtml = '';
      Array.from(doc.body.childNodes).forEach(n => {
        if (n.nodeName && n.nodeName.toLowerCase() === 'script') return;
        bodyHtml += n.outerHTML || n.textContent;
      });
      container.innerHTML = bodyHtml;

      // collect scripts
      const scripts = doc.querySelectorAll('script');

      // external scripts
      const externals = [];
      scripts.forEach(s => { if (s.src) externals.push(s.src); });

      // append external scripts (avoid duplicates)
      externals.forEach(src => {
        try {
          if (!document.querySelector('script[src="' + src + '"]')) {
            const el = document.createElement('script');
            el.src = src;
            el.async = false;
            document.head.appendChild(el);
          }
        } catch (e) { console.error('inject external script', e); }
      });

      // append inline scripts
      scripts.forEach(s => {
        if (!s.src) {
          try {
            const el = document.createElement('script');
            el.text = s.textContent;
            document.body.appendChild(el);
          } catch (e) { console.error('inject inline script', e); }
        }
      });

    } catch (err) {
      console.error('injectHtmlWithScriptsTo error', err);
      if (container) container.innerHTML = '<div style="color:#c00">Gagal memuat konten.</div>';
    }
  }

  if (!window.injectHtmlWithScriptsTo) window.injectHtmlWithScriptsTo = injectHtmlWithScriptsTo;

  // minimal adamModalOpen/Close (ONLY if not exists)
  if (!window.adamModalOpen) {
    window.adamModalOpen = function (url, opts) {
      opts = opts || {};
      if (document.getElementById('adam-modal-backdrop')) return;

      const bd = document.createElement('div');
      bd.id = 'adam-modal-backdrop';
      bd.style.cssText =
        'position:fixed;inset:0;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;z-index:99999;padding:16px';

      const box = document.createElement('div');
      box.id = 'adam-modal-box';
      const maxWidth = opts.maxWidth ? opts.maxWidth : '900px';
      box.style.cssText =
        'background:#fff;border-radius:10px;max-width:' + maxWidth + ';width:100%;max-height:90vh;overflow:auto;' +
        'box-shadow:0 8px 32px rgba(0,0,0,.25);position:relative';

      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.innerText = '×';
      closeBtn.style.cssText =
        'position:absolute;right:10px;top:6px;border:0;background:transparent;font-size:22px;cursor:pointer;line-height:1';
      closeBtn.addEventListener('click', function () { window.adamModalClose(); });

      const content = document.createElement('div');
      content.id = 'adam-modal-content';
      content.style.padding = '14px';

      box.appendChild(closeBtn);
      box.appendChild(content);
      bd.appendChild(box);
      document.body.appendChild(bd);

      fetch(url, { credentials: 'include' })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(html => window.injectHtmlWithScriptsTo(content, html))
        .catch(err => {
          console.error(err);
          content.innerHTML = '<div style="color:#c00">Gagal memuat modal.</div>';
        });

      bd.addEventListener('click', function (ev) {
        if (ev.target === bd) window.adamModalClose();
      });

      function onKey(e) { if (e.key === 'Escape') window.adamModalClose(); }
      document.addEventListener('keydown', onKey);
      bd.__esc = onKey;

      return bd;
    };

    window.adamModalClose = function () {
      const bd = document.getElementById('adam-modal-backdrop');
      if (!bd) return;
      if (bd.__esc) document.removeEventListener('keydown', bd.__esc);
      bd.parentNode.removeChild(bd);
    };
  }

  // ============================
  // 2) MEDIA SELECTOR (compatible)
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

  // openMediaSelector: open modal and resolve on media:insert or postMessage
  if (!window.openMediaSelector) {
    window.openMediaSelector = function (opts) {
      opts = opts || {};
      const url = opts.url || '/adiwira/admin/modal_img/index.php?embedded=1';

      return new Promise(function (resolve) {
        let resolved = false;

        function cleanup() {
          document.removeEventListener('media:insert', onInsert);
          window.removeEventListener('message', onMessage);
        }

        function finish(payload) {
          if (resolved) return;
          resolved = true;
          cleanup();
          try { if (typeof window.adamModalClose === 'function') window.adamModalClose(); } catch (e) {}
          resolve(payload || null);
        }

        function onInsert(e) {
          finish(e && e.detail ? e.detail : null);
        }

        function onMessage(ev) {
          try {
            if (!ev.data) return;
            if (ev.data.type === 'media:insert' || ev.data.type === 'pht_media_insert') {
              finish(ev.data.detail || null);
            }
          } catch (e) {}
        }

        document.addEventListener('media:insert', onInsert);
        window.addEventListener('message', onMessage);

        // open modal (HTML inject, same pattern like editor artikel kamu)
        try {
          window.adamModalOpen(url, opts);
        } catch (e) {
          // jika gagal sekalipun, jangan buka tab baru (biar konsisten)
          console.error('adamModalOpen failed', e);
          finish(null);
          return;
        }

        // if modal closed without selection => resolve null
        const iv = setInterval(function () {
          const b = document.getElementById('adam-modal-backdrop');
          if (!b) {
            clearInterval(iv);
            if (!resolved) {
              cleanup();
              resolve(null);
            }
          }
        }, 200);
      });
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
