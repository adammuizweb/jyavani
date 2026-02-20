(function(){
  window.ADIWIRA = window.ADIWIRA || {};
  const form = document.getElementById('theme-edit-form');
  const btnSave = form && form.querySelector('button[type="submit"]');
  const btnThemeToggle = document.getElementById('btn-theme-toggle');
  let isSaving = false;

  // tunggu CodeMirror siap (jika ada)
  if (window.ADIWIRA && window.ADIWIRA.codemirror && typeof window.ADIWIRA.codemirror.whenCMReady === 'function') {
    window.ADIWIRA.codemirror.whenCMReady?.(function(){
      try {
        const cm = window.ADIWIRA.codemirror.getInstance?.();
        const canonical = document.getElementById('content-textarea');
        if (cm && canonical && canonical.value && (cm.getValue?.() || '').trim() === '') {
          window.ADIWIRA.codemirror.setValueSilent?.(canonical.value);
        }
      } catch(e){ console.warn(e); }
    });
  }

  if (form) {
    form.addEventListener('submit', function(e){
      e.preventDefault();
      if (isSaving) return;
      isSaving = true;
      if (btnSave) btnSave.disabled = true;

      // sinkronkan CodeMirror -> textarea canonical
      try {
        const cmInst = window.ADIWIRA.codemirror?.getInstance?.();
        if (cmInst && typeof cmInst.save === 'function') cmInst.save();
        else if (cmInst && typeof cmInst.getValue === 'function') {
          // pastikan canonical di-set
          document.getElementById('content-textarea').value = cmInst.getValue();
        }
      } catch(e){ /* ignore */ }

      const finish = () => {
        isSaving = false;
        if (btnSave) btnSave.disabled = false;
      };

      // fallback inline AJAX (karena Anda tidak menggunakan ajax_save.js)
      (function fallbackSave(){
        const fd = new FormData(form);
        // pastikan content ada
        const cmInst = window.ADIWIRA.codemirror?.getInstance?.();
        if (cmInst && typeof cmInst.getValue === 'function') fd.set('content', cmInst.getValue());
        fd.set('ajax','1');

        const saveUrl = window.ADIWIRA?.config?.themeSaveUrl;
if (!saveUrl) {
  alert('Save URL tidak ditemukan.');
  finish();
  return;
}

fetch(saveUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body: fd,
          headers: {'X-Requested-With':'XMLHttpRequest'}
        }).then(async r => {
          const ct = r.headers.get('content-type') || '';
          let data;
          if (ct.indexOf('application/json') !== -1) {
            data = await r.json();
          } else {
            // fallback: treat as text
            const txt = await r.text();
            try { data = JSON.parse(txt); } catch(e){ data = { ok:false, message: txt }; }
          }
          const notif = document.getElementById('notif-modal');
          if (notif) {
            document.getElementById('notif-title').textContent = data.ok ? 'Tersimpan ✅' : '❌ Gagal Menyimpan';
            document.getElementById('notif-body').textContent = data.message || (data.errors ? data.errors.join('; ') : '');
            notif.style.display = 'flex';
            
  if (data.ok) {
    clearTimeout(window.__notifTimer);
    window.__notifTimer = setTimeout(() => {
      notif.style.display = 'none';
    }, 1000);
  }
  
          }
          if (data && data.updated_at) {
            document.getElementById('updated-at').textContent = data.updated_at;
          }
          // update save_nonce jika server mengembalikan new_save_nonce
          if (data && data.new_save_nonce) {
            const el = document.getElementById('save_nonce');
            if (el) el.value = data.new_save_nonce;
          }
        }).catch(err => {
          alert('Gagal menyimpan: ' + (err && err.message ? err.message : err));
        }).finally(finish);
      })();

    });
  }

  // Theme toggle (tetap seperti sebelumnya)
  const THEME_KEY = 'themeEditorMode';
  function applyTheme(isDark){
    const theme = isDark ? 'dark' : 'light';
    try {
      if (window.ADIWIRA.codemirror && typeof window.ADIWIRA.codemirror.getInstance === 'function') {
        const cm = window.ADIWIRA.codemirror.getInstance();
        cm?.setOption?.('theme', theme);
        const wrap = cm?.getWrapperElement?.();
        if (wrap) { wrap.classList.remove('cm-s-dark','cm-s-light'); wrap.classList.add(theme==='dark' ? 'cm-s-dark' : 'cm-s-light'); }
      }
    } catch(e){/* ignore */ }
    const btn = btnThemeToggle;
    if (btn) { btn.textContent = isDark ? '🌞' : '🌙'; btn.style.background = isDark ? '#444' : '#ddd'; }
    try { localStorage.setItem(THEME_KEY, isDark ? 'dark' : 'light'); } catch(e){}
  }
  const saved = (function(){ try{ return localStorage.getItem(THEME_KEY); }catch(e){return null;} })();
  const defaultDark = saved ? saved === 'dark' : (window.matchMedia && window.matchMedia('(prefers-color-scheme:dark)').matches);
  applyTheme(!!defaultDark);
  btnThemeToggle?.addEventListener('click', function(){ const cur = localStorage.getItem(THEME_KEY) || (defaultDark ? 'dark' : 'light'); applyTheme(cur !== 'dark'); });
  
    function triggerSaveFromShortcut(e) {
    const isMac = navigator.platform.toUpperCase().includes('MAC');
    const isSaveCombo =
      (isMac && e.metaKey && e.key === 's') ||
      (!isMac && e.ctrlKey && e.key === 's');

    if (!isSaveCombo) return;

    e.preventDefault();
    if (isSaving) return;

    // gunakan requestSubmit agar lewat jalur submit resmi
    if (form && typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else if (form) {
      // fallback lama
      form.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  }

  // 1️⃣ Global fallback (kalau fokus bukan di CodeMirror)
  document.addEventListener('keydown', triggerSaveFromShortcut);

  // 2️⃣ Binding khusus CodeMirror (INI YANG PENTING)
  if (window.ADIWIRA?.codemirror?.whenCMReady) {
    window.ADIWIRA.codemirror.whenCMReady(function(){
      try {
        const cm = window.ADIWIRA.codemirror.getInstance?.();
        if (!cm) return;

        cm.addKeyMap({
          'Ctrl-S': function(cm){ triggerSaveFromShortcut(new KeyboardEvent('keydown', { ctrlKey:true, key:'s' })); },
          'Cmd-S': function(cm){ triggerSaveFromShortcut(new KeyboardEvent('keydown', { metaKey:true, key:'s' })); }
        });
      } catch(e){
        console.warn('Keymap Ctrl+S gagal:', e);
      }
    });
  }

})();