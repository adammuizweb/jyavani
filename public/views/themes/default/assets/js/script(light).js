/* /static/assets/js/script.js
   Permanent light-theme + navigation/mobile menu
*/
(() => {
  'use strict';

  // Utilities
  const $ = sel => document.querySelector(sel);
  const $$ = sel => Array.from(document.querySelectorAll(sel));
  const htmlEl = document.documentElement;

  /* ======================
     FORCE LIGHT THEME
     ====================== */

  // Ensure html has theme-light and remove any theme-dark if present
  try {
    htmlEl.classList.remove('theme-dark');
    htmlEl.classList.add('theme-light');
    htmlEl.setAttribute('data-current-theme', 'light');

    // Remove legacy storage/cookie that may contain previous choices
    try { localStorage.removeItem('site-theme'); } catch (e) { /* ignore */ }
    try { document.cookie = 'site-theme=; path=/; max-age=0'; } catch (e) { /* ignore */ }
  } catch (e) {
    // Best-effort — don't break page if anything fails
    console && console.warn && console.warn('Light-theme enforcement failed:', e);
  }

  // Optional robust guard: if any script later tries to add theme-dark, remove it
  // (keeps cost low — only watches attribute changes on <html>)
  try {
    const obs = new MutationObserver(muts => {
      muts.forEach(m => {
        if (m.attributeName === 'class' && htmlEl.classList.contains('theme-dark')) {
          htmlEl.classList.remove('theme-dark');
          htmlEl.classList.add('theme-light');
          htmlEl.setAttribute('data-current-theme', 'light');
        }
      });
    });
    obs.observe(htmlEl, { attributes: true, attributeFilter: ['class'] });
    // No need to disconnect — keep guard active for page lifetime
  } catch (e) {
    // ignore if MutationObserver not available
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

    // If there's a theme select control, lock it to 'light' and disable it.
    if (themeSelect) {
      try {
        if (themeSelect.tagName === 'SELECT') {
          themeSelect.value = 'light';
        } else if (themeSelect.tagName === 'INPUT' || themeSelect.tagName === 'BUTTON') {
          // try to reflect visually if it's not a select
          themeSelect.setAttribute('data-forced-theme', 'light');
        }
        themeSelect.disabled = true;
        // remove existing event listeners by cloning (robust way to drop handlers)
        const clone = themeSelect.cloneNode(true);
        themeSelect.parentNode && themeSelect.parentNode.replaceChild(clone, themeSelect);
      } catch (e) {
        // ignore anything that fails here
      }
    }

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

    // final reassurance: ensure class is set after DOM ready
    try {
      htmlEl.classList.remove('theme-dark');
      if (!htmlEl.classList.contains('theme-light')) htmlEl.classList.add('theme-light');
      htmlEl.setAttribute('data-current-theme', 'light');
    } catch (e) {
      // silent
    }
  });
})();

/* footer clock */
(function(){ var y = new Date().getFullYear(); var el = document.getElementById('year'); if(el) el.textContent = y; })();
