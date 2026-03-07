// navigasi
document.addEventListener('DOMContentLoaded', () => {
  const navbar = document.getElementById('navbar');
  const hamburger = document.getElementById('hamburger');
  const closeMenu = document.getElementById('closeMenu');
  const overlay = document.getElementById('overlay');
  const themeSelect = document.getElementById('themeSelect');
  const htmlEl = document.documentElement;
  const prefersDarkMQ = window.matchMedia('(prefers-color-scheme: dark)');

  function applyTheme(theme) {
    htmlEl.classList.remove('theme-dark', 'theme-light');
    if (theme === 'dark') htmlEl.classList.add('theme-dark');
    else if (theme === 'light') htmlEl.classList.add('theme-light');
  }

  if (themeSelect) {
    const savedTheme = localStorage.getItem('site-theme') || 'system';
    themeSelect.value = savedTheme;
    applyTheme(savedTheme);
    themeSelect.addEventListener('change', (e) => {
      localStorage.setItem('site-theme', e.target.value);
      applyTheme(e.target.value);
    });
  }

  function toggleMenu(show) {
    if (show) {
      navbar.classList.add('open');
      overlay.classList.add('active');
    } else {
      navbar.classList.remove('open');
      overlay.classList.remove('active');
    }
  }

  hamburger.addEventListener('click', () => toggleMenu(true));
  closeMenu.addEventListener('click', () => toggleMenu(false));
  overlay.addEventListener('click', () => toggleMenu(false));

  document.querySelectorAll('.mobile-toggle-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const rowDiv = btn.parentElement; 
      const submenu = rowDiv.nextElementSibling;
      if (submenu && submenu.classList.contains('submenu')) {
        const isOpen = submenu.classList.contains('open');
        submenu.classList.toggle('open');
        btn.classList.toggle('expanded');
        if (!isOpen) {
          const grandParent = rowDiv.parentElement.parentElement;
          grandParent.querySelectorAll(':scope > li > .submenu').forEach(s => { if(s !== submenu) s.classList.remove('open'); });
          grandParent.querySelectorAll(':scope > li > .mobile-row > .mobile-toggle-btn').forEach(t => { if(t !== btn) t.classList.remove('expanded'); });
        }
      }
    });
  });

  document.querySelectorAll('a[data-key]').forEach(link => {
    link.addEventListener('click', (e) => { if (window.innerWidth <= 900) toggleMenu(false); });
  });
});