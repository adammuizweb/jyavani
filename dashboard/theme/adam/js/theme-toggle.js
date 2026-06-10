(function () {
  var KEY = 'adam_theme';
  var root = document.documentElement;

  function prefersDark(){
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function getMode(){
    if (root.classList.contains('theme-dark')) return 'dark';
    if (root.classList.contains('theme-light')) return 'light';
    return prefersDark() ? 'dark' : 'light';
  }

  function applyCodeMirror(mode){
    var theme = (mode === 'dark') ? 'dark' : 'light';

    // 1) kalau kamu punya helper instance (beberapa page pakai window.ADIWIRA.codemirror)
    try{
      var cm1 = window.ADIWIRA && window.ADIWIRA.codemirror && window.ADIWIRA.codemirror.getInstance
        ? window.ADIWIRA.codemirror.getInstance()
        : null;
      if (cm1 && cm1.setOption) {
        cm1.setOption('theme', theme);
        var w1 = cm1.getWrapperElement && cm1.getWrapperElement();
        if (w1) {
          w1.classList.remove('cm-s-dark','cm-s-light');
          w1.classList.add(theme === 'dark' ? 'cm-s-dark' : 'cm-s-light');
        }
        cm1.refresh && cm1.refresh();
      }
    } catch(e){}

    // 2) fallback universal: scan semua editor CodeMirror yang ada di halaman
    document.querySelectorAll('.CodeMirror').forEach(function(el){
      var cm = el && el.CodeMirror;
      if (!cm || !cm.setOption) return;

      cm.setOption('theme', theme);
      var w = cm.getWrapperElement && cm.getWrapperElement();
      if (w) {
        w.classList.remove('cm-s-dark','cm-s-light');
        w.classList.add(theme === 'dark' ? 'cm-s-dark' : 'cm-s-light');
      }
      cm.refresh && cm.refresh();
    });
  }

  function setTheme(mode){
    root.classList.toggle('theme-dark', mode === 'dark');
    root.classList.toggle('theme-light', mode === 'light');
    root.style.colorScheme = mode;
    try { localStorage.setItem(KEY, mode); } catch(e){}
    try { document.cookie = KEY + '=' + mode + '; path=/; max-age=' + (60*60*24*365); } catch(e){}
    var tc = document.querySelector('meta[name="theme-color"]');
    if (tc) tc.setAttribute('content', mode === 'dark' ? '#071022' : '#f9fafb');

    // apply CM now + slightly later (buat CM yang baru di-init setelah load)
    applyCodeMirror(mode);
    setTimeout(function(){ applyCodeMirror(getMode()); }, 120);

    syncUI();
  }

function syncUI(){
  var mode = getMode();
  var btn = document.getElementById('btn-theme-toggle');
  if (!btn) return;

  var label = document.getElementById('adamThemeLabel');

  btn.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');
  btn.setAttribute('data-mode', mode);

  if (label) label.textContent = (mode === 'dark') ? 'Dark' : 'Light';
}

  document.addEventListener('click', function(e){
    var btn = e.target.closest('#btn-theme-toggle');
    if (!btn) return;

    var mode = getMode();
    setTheme(mode === 'dark' ? 'light' : 'dark');
  });

  document.addEventListener('DOMContentLoaded', function(){
    syncUI();
    // pastikan CodeMirror langsung sesuai theme saat halaman selesai load
    applyCodeMirror(getMode());
    setTimeout(function(){ applyCodeMirror(getMode()); }, 120);
  });
})();