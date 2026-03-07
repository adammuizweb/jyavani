(function () {
  try {
    var KEY = 'site-theme'; // 'light' | 'dark'
    var saved = null;

    try { saved = localStorage.getItem(KEY); } catch(e) {}

    // normalisasi + migrasi dari value lama 'system' -> treat as empty
    if (saved !== 'light' && saved !== 'dark') saved = null;

    var prefersDark = false;
    try {
      prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    } catch(e) {}

    // first visit: pakai preferensi device jika ada, kalau tidak => light
    var theme = saved ? saved : (prefersDark ? 'dark' : 'light');

    // apply class secepat mungkin (anti FOUC)
    var html = document.documentElement;
    html.classList.remove('theme-dark','theme-light');
    html.classList.add(theme === 'dark' ? 'theme-dark' : 'theme-light');
    html.setAttribute('data-current-theme', theme);

    // persist supaya request berikutnya server bisa set class via cookie
    try { localStorage.setItem(KEY, theme); } catch(e) {}
    try { document.cookie = KEY + '=' + encodeURIComponent(theme) + '; path=/; max-age=' + (60*60*24*365); } catch(e) {}
  } catch (e) {}
})();