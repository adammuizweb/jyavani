/* modal-helpers.js */
(function(){
  function injectHtmlWithScriptsTo(container, html) {
    try {
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');

      let bodyHtml = '';
      Array.from(doc.body.childNodes).forEach(n => {
        if (n.nodeName && n.nodeName.toLowerCase() === 'script') return;
        bodyHtml += n.outerHTML || n.textContent;
      });
      container.innerHTML = bodyHtml;

      const scripts = doc.querySelectorAll('script');
      const externals = [];
      scripts.forEach(s => { if (s.src) externals.push(s.src); });

      externals.forEach(src => {
        if (!document.querySelector('script[src="' + src + '"]')) {
          const el = document.createElement('script');
          el.src = src;
          el.async = false;
          document.head.appendChild(el);
        }
      });

      scripts.forEach(s => {
        if (!s.src) {
          const el = document.createElement('script');
          el.text = s.textContent;
          document.body.appendChild(el);
        }
      });

    } catch (err) {
      console.error('injectHtmlWithScriptsTo error', err);
      if (container) container.innerHTML = '<div style="color:#c00">Gagal memuat konten.</div>';
    }
  }

  if (!window.injectHtmlWithScriptsTo) window.injectHtmlWithScriptsTo = injectHtmlWithScriptsTo;

  if (!window.adamModalOpen) {
    window.adamModalOpen = function(url, opts){
      opts = opts || {};
      if (document.getElementById('adam-modal-backdrop')) return;
      const bd = document.createElement('div');
      bd.id = 'adam-modal-backdrop';
      bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;z-index:99999;padding:16px';
      const box = document.createElement('div');
      box.id = 'adam-modal-box';
      const maxWidth = (opts && opts.maxWidth) ? opts.maxWidth : '900px';
      box.style.cssText = 'background:#fff;border-radius:8px;max-width:' + maxWidth + ';width:100%;max-height:90vh;overflow:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);position:relative';
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.innerText = '×';
      closeBtn.style.cssText = 'position:absolute;right:10px;top:8px;border:0;background:transparent;font-size:20px;cursor:pointer';
      closeBtn.addEventListener('click', function(){ window.adamModalClose(); });
      box.appendChild(closeBtn);
      const content = document.createElement('div');
      content.id = 'adam-modal-content';
      content.style.padding = '14px';
      box.appendChild(content);
      bd.appendChild(box);
      document.body.appendChild(bd);

      fetch(url, { credentials: 'include' })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(html => injectHtmlWithScriptsTo(content, html))
        .catch(err => {
          content.innerHTML = '<div style="color:#c00">Gagal memuat modal.</div>';
          console.error(err);
        });

      bd.addEventListener('click', function(ev){ if (ev.target === bd) window.adamModalClose(); });
      function onKey(e){ if (e.key === 'Escape') window.adamModalClose(); }
      document.addEventListener('keydown', onKey);
      bd.__esc = onKey;
      return bd;
    };

    window.adamModalClose = function(){
      const bd = document.getElementById('adam-modal-backdrop');
      if (!bd) return;
      if (bd.__esc) document.removeEventListener('keydown', bd.__esc);
      bd.parentNode.removeChild(bd);
    };
  }
})();
