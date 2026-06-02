/* public/adiwira/static/js/add/modal-helpers.js */
(function(){
  var loadedExternalScripts = window.__adamModalLoadedScripts || {};
  window.__adamModalLoadedScripts = loadedExternalScripts;

  function hasExternalScript(src) {
    var scripts = document.getElementsByTagName('script');
    for (var i = 0; i < scripts.length; i++) {
      var cur = scripts[i].getAttribute('src') || '';
      if (cur === src || scripts[i].src === src) return true;
    }
    return false;
  }

  function loadExternalScript(src) {
    if (!src) return Promise.resolve();

    if (loadedExternalScripts[src]) {
      return loadedExternalScripts[src];
    }

    loadedExternalScripts[src] = new Promise(function(resolve, reject){
      if (hasExternalScript(src)) {
        resolve();
        return;
      }

      var el = document.createElement('script');
      el.src = src;
      el.async = false;
      el.onload = function(){ resolve(); };
      el.onerror = function(){ reject(new Error('Failed load script: ' + src)); };
      document.head.appendChild(el);
    });

    return loadedExternalScripts[src];
  }

  function cleanupInjectedScripts(container) {
    if (!container) return;

    try {
      if (container.__adamModalExecHost && container.__adamModalExecHost.parentNode) {
        container.__adamModalExecHost.parentNode.removeChild(container.__adamModalExecHost);
      }
    } catch (e) {}

    container.__adamModalExecHost = null;
  }

  function parseHtmlAndCollectScripts(html) {
    var parser = new DOMParser();
    var doc = parser.parseFromString(String(html || ''), 'text/html');
    var scripts = [];
    var scriptNodes = doc.querySelectorAll('script');

    Array.prototype.forEach.call(scriptNodes, function(s){
      scripts.push({
        src: s.getAttribute('src') || '',
        type: s.getAttribute('type') || '',
        text: s.textContent || ''
      });
      if (s.parentNode) s.parentNode.removeChild(s);
    });

    return {
      html: doc.body ? doc.body.innerHTML : '',
      scripts: scripts
    };
  }

  function runScriptsSequentially(container, scripts) {
    if (!container || !scripts || !scripts.length) return Promise.resolve();

    var execHost = document.createElement('div');
    execHost.setAttribute('data-adam-modal-script-host', '1');
    execHost.style.display = 'none';
    container.appendChild(execHost);
    container.__adamModalExecHost = execHost;

    return scripts.reduce(function(chain, item){
      return chain.then(function(){
        if (!container.isConnected) return;

        if (item.src) {
          return loadExternalScript(item.src);
        }

        return new Promise(function(resolve){
          if (!execHost.isConnected) {
            resolve();
            return;
          }

          var el = document.createElement('script');
          if (item.type) el.type = item.type;
          el.text = item.text || '';
          execHost.appendChild(el);
          resolve();
        });
      });
    }, Promise.resolve());
  }

  function injectHtmlWithScriptsTo(container, html) {
    try {
      if (!container) return Promise.resolve();

      cleanupInjectedScripts(container);

      var parsed = parseHtmlAndCollectScripts(html);
      container.innerHTML = parsed.html;

      return runScriptsSequentially(container, parsed.scripts).catch(function(err){
        console.error('runScriptsSequentially error', err);
      });
    } catch (err) {
      console.error('injectHtmlWithScriptsTo error', err);
      if (container) {
        container.innerHTML = '<div style="color:#c00">Gagal memuat konten.</div>';
      }
      return Promise.reject(err);
    }
  }

  window.injectHtmlWithScriptsTo = injectHtmlWithScriptsTo;

  window.adamModalOpen = function(url, opts){
    opts = opts || {};

    var old = document.getElementById('adam-modal-backdrop');
    if (old && typeof window.adamModalClose === 'function') {
      window.adamModalClose();
    }

    var bd = document.createElement('div');
    bd.id = 'adam-modal-backdrop';
    bd.style.cssText = [
      'position:fixed',
      'inset:0',
      'background:rgba(0,0,0,0.45)',
      'display:flex',
      'align-items:center',
      'justify-content:center',
      'z-index:99999',
      'padding:16px'
    ].join(';');

    var box = document.createElement('div');
    box.id = 'adam-modal-box';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');

    var maxWidth = (opts && opts.maxWidth) ? opts.maxWidth : '900px';
    box.style.cssText = [
      'background:#fff',
      'border-radius:8px',
      'max-width:' + maxWidth,
      'width:100%',
      'max-height:90vh',
      'overflow:auto',
      'box-shadow:0 8px 32px rgba(0,0,0,.25)',
      'position:relative'
    ].join(';');

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.innerText = '×';
    closeBtn.setAttribute('aria-label', 'Close');
    closeBtn.style.cssText = [
      'position:absolute',
      'right:10px',
      'top:8px',
      'border:0',
      'background:transparent',
      'font-size:20px',
      'cursor:pointer',
      'z-index:2'
    ].join(';');
    closeBtn.addEventListener('click', function(){
      window.adamModalClose();
    });

    var content = document.createElement('div');
    content.id = 'adam-modal-content';
    content.style.padding = '14px';
    content.innerHTML = '<div style="color:#666">Memuat modal...</div>';

    box.appendChild(closeBtn);
    box.appendChild(content);
    bd.appendChild(box);
    document.body.appendChild(bd);

    var controller = null;
    if (typeof AbortController !== 'undefined') {
      controller = new AbortController();
      bd.__abortController = controller;
    }

    bd.__isClosed = false;

    fetch(url, {
      credentials: 'include',
      cache: 'no-store',
      signal: controller ? controller.signal : undefined
    })
      .then(function(r){
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function(html){
        if (bd.__isClosed || !document.body.contains(bd)) return;
        return injectHtmlWithScriptsTo(content, html);
      })
      .catch(function(err){
        if (err && err.name === 'AbortError') return;
        if (bd.__isClosed || !document.body.contains(bd)) return;

        content.innerHTML = '<div style="color:#c00">Gagal memuat modal.</div>';
        console.error(err);
      });

    bd.addEventListener('click', function(ev){
      if (ev.target === bd) window.adamModalClose();
    });

    function onKey(e){
      if (e.key === 'Escape') window.adamModalClose();
    }

    document.addEventListener('keydown', onKey);
    bd.__esc = onKey;

    return bd;
  };

  window.adamModalClose = function(){
    var bd = document.getElementById('adam-modal-backdrop');
    if (!bd) return;

    bd.__isClosed = true;

    if (bd.__abortController && typeof bd.__abortController.abort === 'function') {
      try { bd.__abortController.abort(); } catch (e) {}
    }

    if (bd.__esc) {
      document.removeEventListener('keydown', bd.__esc);
    }

    var content = document.getElementById('adam-modal-content');
    if (content) {
      cleanupInjectedScripts(content);
      content.innerHTML = '';
    }

    if (bd.parentNode) {
      bd.parentNode.removeChild(bd);
    }
  };
})();