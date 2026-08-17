// Script Navigasi
/* /static/assets/js/script.js
   Combined: theme handling (system/light/dark) + navigation/mobile menu
   - Assumes there's an inline head script that sets data-theme-initialized early.
   - Safe guards if elements are missing.
*/
(() => {
  'use strict';

  // Utilities
  const $ = sel => document.querySelector(sel);
  const $$ = sel => Array.from(document.querySelectorAll(sel));
  const htmlEl = document.documentElement;
  const prefersDarkMQ = window.matchMedia('(prefers-color-scheme: dark)');

  // Theme helpers
  function getSavedTheme() {
    try {
      return localStorage.getItem('site-theme') || 'system';
    } catch (e) {
      return 'system';
    }
  }

  function saveTheme(v) {
    try {
      localStorage.setItem('site-theme', v);
    } catch (e) { /* ignore */ }

    // also set cookie (optional server-side integration)
    try {
      document.cookie = `site-theme=${encodeURIComponent(v)}; path=/; max-age=${60*60*24*365}`;
    } catch(e) { /* ignore */ }
  }

  // apply explicit classes. For 'system' we remove explicit classes and let CSS @media handle
  function applyThemeClass(theme) {
    htmlEl.classList.remove('theme-dark', 'theme-light');
    if (theme === 'dark') {
      htmlEl.classList.add('theme-dark');
      htmlEl.setAttribute('data-current-theme', 'dark');
    } else if (theme === 'light') {
      htmlEl.classList.add('theme-light');
      htmlEl.setAttribute('data-current-theme', 'light');
    } else {
      // 'system' -> remove explicit class so CSS @media takes effect
      htmlEl.removeAttribute('data-current-theme');
      // Optionally set a data attr to reflect effective theme for debugging:
      htmlEl.setAttribute('data-current-theme', prefersDarkMQ.matches ? 'dark' : 'light');
    }
  }

  // When system preference changes and user selected 'system', update effective display
  function onSystemChange(e) {
    const saved = getSavedTheme();
    if (saved === 'system') {
      // update the effective theme marker (but keep no explicit theme class)
      htmlEl.setAttribute('data-current-theme', e.matches ? 'dark' : 'light');
      // if you want to explicitly add theme class for some reason uncomment:
      // applyThemeClass('system'); // which primarily removes explicit classes
    }
  }
  // add listener (use addEventListener if available)
  if (typeof prefersDarkMQ.addEventListener === 'function') {
    prefersDarkMQ.addEventListener('change', onSystemChange);
  } else if (typeof prefersDarkMQ.addListener === 'function') {
    prefersDarkMQ.addListener(onSystemChange);
  }

  /* ----------------------------
     DOMContentLoaded main init
     ---------------------------- */
  document.addEventListener('DOMContentLoaded', () => {
    // NAV elements (may or may not exist)
    const navbar = $('#navbar');
    const hamburger = $('#hamburger');
    const closeMenu = $('#closeMenu');
    const overlay = $('#overlay');
    const themeSelect = $('#themeSelect');

    // --- THEME SELECT UI init & event ---
    // If themeSelect exists, sync it with saved value and wire change handler.
    (function initThemeControl() {
      if (!themeSelect) return;
      const saved = getSavedTheme();
      themeSelect.value = saved;

      // apply initial theme (inline head script likely already applied,
      // but call this to ensure consistent class/attrs)
      applyThemeClass(saved);

      themeSelect.addEventListener('change', (ev) => {
        const v = ev.target.value;
        saveTheme(v);
        // If user picks 'system', remove explicit classes (let CSS handle) but update marker attr
        applyThemeClass(v);
      });
    })();

    // --- NAV MENU toggle ---
    function toggleMenu(show) {
      if (!navbar || !overlay) return;
      if (show) {
        navbar.classList.add('open');
        overlay.classList.add('active');
        // prevent body scroll when open
        document.body.style.overflow = 'hidden';
      } else {
        navbar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
      }
    }

    if (hamburger) {
      hamburger.addEventListener('click', (e) => {
        e.preventDefault();
        toggleMenu(true);
      });
    }
    if (closeMenu) {
      closeMenu.addEventListener('click', (e) => {
        e.preventDefault();
        toggleMenu(false);
      });
    }
    if (overlay) {
      overlay.addEventListener('click', () => toggleMenu(false));
    }

    // mobile submenu toggles (guard for missing)
    $$('.mobile-toggle-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const rowDiv = btn.parentElement;
        const submenu = rowDiv ? rowDiv.nextElementSibling : null;
        if (submenu && submenu.classList.contains('submenu')) {
          const isOpen = submenu.classList.contains('open');
          submenu.classList.toggle('open');
          btn.classList.toggle('expanded');
          if (!isOpen) {
            // close siblings within same parent (only one open)
            const grandParent = rowDiv.parentElement && rowDiv.parentElement.parentElement;
            if (grandParent) {
              grandParent.querySelectorAll(':scope > li > .submenu').forEach(s => { if (s !== submenu) s.classList.remove('open'); });
              grandParent.querySelectorAll(':scope > li > .mobile-row > .mobile-toggle-btn').forEach(t => { if (t !== btn) t.classList.remove('expanded'); });
            }
          }
        }
      });
    });

    // hide menu on nav link click (mobile)
    $$('a[data-key]').forEach(link => {
      link.addEventListener('click', () => {
        if (window.innerWidth <= 900) toggleMenu(false);
      });
    });

    // Optional: accessibility — close menu with ESC
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') {
        toggleMenu(false);
      }
    });

    // Ensure theme state is consistent with system on load if saved is 'system'
    const initialSaved = getSavedTheme();
    if (initialSaved === 'system') {
      // No explicit class; set data-current-theme marker so you can style transitions if needed
      htmlEl.setAttribute('data-current-theme', prefersDarkMQ.matches ? 'dark' : 'light');
    } else {
      // ensure explicit class is present
      applyThemeClass(initialSaved);
    }
  });
})();


// footer
(function(){ var y = new Date().getFullYear(); var el = document.getElementById('year'); if(el) el.textContent = y; })();

// END
