// aside.js - improved toggle + mobile burger support + smoothness helpers
(function () {
  'use strict';

  // Run after DOM ready to ensure elements exist
  document.addEventListener('DOMContentLoaded', function () {
    var body = document.body;
    var html = document.documentElement;
    var aside = document.getElementById('adam-aside');
    var collapseBtn = document.getElementById('adam-collapse');
    // try both id and class for burger (some headers use either)
    var burgerBtn = document.getElementById('adam-burger') || document.querySelector('.adam-burger');

    if (!aside) return; // nothing to do if aside missing

    var KEY = 'adam_aside_state';
    var mobileBreakpoint = 900;

    function isMobile() {
      return window.innerWidth <= mobileBreakpoint;
    }

    function syncAria() {
      var collapsed = html.classList.contains('aside-collapsed') || (body && body.classList.contains('aside-collapsed'));
      try { aside.setAttribute('aria-hidden', collapsed ? 'true' : 'false'); } catch (e) { /* noop */ }
      if (collapseBtn) collapseBtn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
      if (burgerBtn) burgerBtn.setAttribute('aria-expanded', aside.classList.contains('show') ? 'true' : 'false');
      if (collapseBtn) {
  collapseBtn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
  collapseBtn.setAttribute('title', collapsed ? 'Tampilkan sidebar' : 'Sembunyikan sidebar');
}

    }

    function saveState() {
      try {
        localStorage.setItem(KEY, (html.classList.contains('aside-collapsed') || (body && body.classList.contains('aside-collapsed'))) ? 'collapsed' : 'expanded');
      } catch (e) { /* noop */ }
    }

    function openMobileAside() {
      aside.classList.add('show');
      if (body) body.classList.add('aside-open');
      setTimeout(syncAria, 0);
    }

    function closeMobileAside() {
      aside.classList.remove('show');
      if (body) body.classList.remove('aside-open');
      setTimeout(syncAria, 0);
    }

    function withNoTrans(fn) {
      html.classList.add('no-transitions');
      try { fn(); } catch (e) { /* noop */ }
      // small delay to allow paint then remove class
      setTimeout(function () { html.classList.remove('no-transitions'); }, 120);
    }

    function toggleCollapse() {
      if (isMobile()) {
        // mobile toggles overlay open/close
        if (aside.classList.contains('show')) closeMobileAside();
        else openMobileAside();
        return;
      }

      var nowCollapsed = html.classList.contains('aside-collapsed') || (body && body.classList.contains('aside-collapsed'));

      withNoTrans(function () {
        if (nowCollapsed) {
          html.classList.remove('aside-collapsed');
          if (body) body.classList.remove('aside-collapsed');
          // ensure overlay class removed on desktop
          aside.classList.remove('show');
          if (body) body.classList.remove('aside-open');
        } else {
          html.classList.add('aside-collapsed');
          if (body) body.classList.add('aside-collapsed');
          // ensure overlay class removed (defensive)
          aside.classList.remove('show');
          if (body) body.classList.remove('aside-open');
        }
      });

      syncAria();
      saveState();
    }

    // click handlers
    if (collapseBtn) {
      collapseBtn.addEventListener('click', function (e) {
        e.preventDefault();
        toggleCollapse();
      });
    }

    if (burgerBtn) {
      burgerBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (isMobile()) {
          if (aside.classList.contains('show')) closeMobileAside();
          else openMobileAside();
        } else {
          toggleCollapse();
        }
      });
    }

    // close mobile when clicking outside aside (capture)
    document.addEventListener('click', function (e) {
      if (!isMobile()) return;
      if (!aside.classList.contains('show')) return;
      var inside = aside.contains(e.target) || (collapseBtn && collapseBtn.contains(e.target)) || (burgerBtn && burgerBtn.contains(e.target));
      if (!inside) closeMobileAside();
    }, true);

    // ESC closes mobile aside
    document.addEventListener('keydown', function (e) {
      if ((e.key === 'Escape' || e.key === 'Esc') && isMobile() && aside.classList.contains('show')) closeMobileAside();
    });

    // On resize: remove desktop collapsed on mobile and restore persisted state on desktop
    var resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        if (isMobile()) {
          // on mobile, ensure desktop-collapsed class removed and overlay closed
          html.classList.remove('aside-collapsed');
          if (body) body.classList.remove('aside-collapsed');
          aside.classList.remove('show');
          if (body) body.classList.remove('aside-open');
        } else {
          try {
            var v = localStorage.getItem(KEY);
            if (v === 'collapsed') {
              html.classList.add('aside-collapsed');
              if (body) body.classList.add('aside-collapsed');
            } else {
              html.classList.remove('aside-collapsed');
              if (body) body.classList.remove('aside-collapsed');
            }
          } catch (e) { /* noop */ }
          // Always ensure mobile overlay state off on desktop
          aside.classList.remove('show');
          if (body) body.classList.remove('aside-open');
        }
        syncAria();
      }, 140);
    });

    // initial state restore - deterministic and mobile-aware
    try {
      var v = localStorage.getItem(KEY);
      if (!isMobile() && v === 'collapsed') {
        html.classList.add('aside-collapsed');
        if (body) body.classList.add('aside-collapsed');
        aside.classList.remove('show');
        if (body) body.classList.remove('aside-open');
      } else {
        // on mobile, do not auto collapse; ensure aside closed
        html.classList.remove('aside-collapsed');
        if (body) body.classList.remove('aside-collapsed');
        aside.classList.remove('show');
        if (body) body.classList.remove('aside-open');
      }
    } catch (e) {
      // ignore storage errors
      html.classList.remove('aside-collapsed');
      if (body) body.classList.remove('aside-collapsed');
      aside.classList.remove('show');
      if (body) body.classList.remove('aside-open');
    }

    // expose a11y-friendly attributes for initial state
    syncAria();

    // Optional: allow toggling by double-click on the aside rail (UX nicety)
    // only on desktop and if collapseBtn missing
    if (!collapseBtn) {
      aside.addEventListener('dblclick', function () {
        if (!isMobile()) toggleCollapse();
      });
    }
  });
})();