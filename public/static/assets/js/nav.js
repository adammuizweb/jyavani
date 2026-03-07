/* lokasi file /static/assets/js/nav.js
   Theme handling (light/dark only) + navigation/mobile menu
   - Keeps your navigation behavior intact.
*/
(() => {
  'use strict';

  // Utilities
  const $ = sel => document.querySelector(sel);
  const $$ = sel => Array.from(document.querySelectorAll(sel));
  const htmlEl = document.documentElement;

  // =========================
  // THEME (LIGHT/DARK ONLY)
  // =========================

  const THEME_KEY = 'site-theme'; // values: 'light' | 'dark'

  function normalizeTheme(v) {
    return (v === 'dark' || v === 'light') ? v : 'light';
  }

  function getSavedTheme() {
    // priority: localStorage first
    let v = '';
    try { v = localStorage.getItem(THEME_KEY) || ''; } catch (e) {}

    // migration: if old value was 'system' or anything else => light
    v = normalizeTheme(v);
    return v;
  }

  function saveTheme(v) {
    v = normalizeTheme(v);

    try { localStorage.setItem(THEME_KEY, v); } catch (e) { /* ignore */ }

    // also set cookie (optional server-side integration)
    try {
      document.cookie = `${THEME_KEY}=${encodeURIComponent(v)}; path=/; max-age=${60 * 60 * 24 * 365}`;
    } catch (e) { /* ignore */ }
  }

  function applyThemeClass(theme) {
    theme = normalizeTheme(theme);

    // Only 2 themes now
    htmlEl.classList.remove('theme-dark', 'theme-light');
    if (theme === 'dark') {
      htmlEl.classList.add('theme-dark');
    } else {
      // optional: add theme-light if you still want explicit class
      // htmlEl.classList.add('theme-light');
    }

    htmlEl.setAttribute('data-current-theme', theme);

    // optional sync for meta
    const cs = $('meta[name="color-scheme"]');
    if (cs) cs.setAttribute('content', theme);
    const tc = $('meta[name="theme-color"]');
    if (tc) tc.setAttribute('content', theme === 'dark' ? '#071022' : '#ffffff');
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
    (function initThemeControl() {
      // migrate any previous bad value to light and apply
      const saved = getSavedTheme();
      saveTheme(saved);
      applyThemeClass(saved);

      if (!themeSelect) return;

      // IMPORTANT: themeSelect sekarang hanya punya light/dark
      themeSelect.value = saved;

      themeSelect.addEventListener('change', (ev) => {
        const v = normalizeTheme(ev.target.value);
        saveTheme(v);
        applyThemeClass(v);
      });
    })();

    // --- NAV MENU toggle --- (TETAP punyamu)
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

    // mobile submenu toggles (guard for missing) (TETAP punyamu)
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

    // hide menu on nav link click (mobile) (TETAP punyamu)
    $$('a[data-key]').forEach(link => {
      link.addEventListener('click', () => {
        if (window.innerWidth <= 900) toggleMenu(false);
      });
    });

    // Optional: accessibility — close menu with ESC (TETAP punyamu)
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') {
        toggleMenu(false);
      }
    });
  });
})();
