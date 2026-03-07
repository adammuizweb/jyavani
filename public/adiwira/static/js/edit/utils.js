// utils.js
(function(){
  window.ADIWIRA = window.ADIWIRA || {};

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

  function debounce(fn, wait){ let t; return function(){ clearTimeout(t); t = setTimeout(fn, wait); }; }

  function showNotif(title, body, timeout = 2500) {
    const m = document.getElementById('notif-modal');
    const t = document.getElementById('notif-title');
    const b = document.getElementById('notif-body');
    if (!m || !t || !b) return alert(body || title);
    t.textContent = title || 'Informasi';
    b.textContent = body || '';
    m.style.display = 'flex';
    if (timeout) setTimeout(hideNotif, timeout);
  }
  function hideNotif() {
    const m = document.getElementById('notif-modal');
    if (m) m.style.display = 'none';
  }

  function getYouTubeId(url) {
    if (!url) return null;
    url = url.trim();
    const shortMatch = url.match(/youtu\.be\/([A-Za-z0-9_\-]+)/i);
    if (shortMatch) return shortMatch[1];
    try {
      const u = new URL(url, window.location.origin);
      if (u.hostname.toLowerCase().includes('youtube.com')) {
        const v = u.searchParams.get('v');
        if (v) return v;
        const pathMatch = u.pathname.match(/\/(embed|v)\/([A-Za-z0-9_\-]+)/i);
        if (pathMatch) return pathMatch[2];
      }
    } catch(e) {
      const qMatch = url.match(/[?&]v=([A-Za-z0-9_\-]+)/i);
      if (qMatch) return qMatch[1];
    }
    return null;
  }

  // simple hash helper for preview payload comparison (stringified small object)
  function payloadHash(p) {
    const keys = ['title','slug','thumbnail','youtube','status','created_at','updated_at','created_by'];
    const small = {};
    keys.forEach(k => { if (typeof p[k] !== 'undefined') small[k] = p[k]+''; else small[k] = ''; });
    small._cats = (p.categories && Array.isArray(p.categories.ids)) ? p.categories.ids.join(',') : '';
    small._html_len = p.html ? String((p.html||'').length) : '0';
    return JSON.stringify(small);
  }

  // find iframe by id, with retry
  async function findIframe(id = 'theme-live-preview', max=25, interval=120) {
    return new Promise(resolve => {
      let tries = 0;
      const t = setInterval(()=> {
        tries++;
        const f = document.getElementById(id);
        if (f) { clearInterval(t); resolve(f); }
        else if (tries>=max) { clearInterval(t); resolve(null); }
      }, interval);
    });
  }

  window.injectHtmlWithScriptsTo = injectHtmlWithScriptsTo;
  window.ADIWIRA.utils = {
    injectHtmlWithScriptsTo,
    debounce,
    showNotif,
    hideNotif,
    getYouTubeId,
    payloadHash,
    findIframe
  };
})();
