// panel.js - pointer-based resizer (robust) + panel toggle + mobile handling
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    const body = document.body;
    const docEl = document.documentElement;

    const CONFIG = {
      panelKey: 'adam_panel_state',
      widthKey: 'adam_panel_width',
      mobileBreakpoint: 900,
      minWidth: 250,
      maxWidth: 1500,
      cssVar: '--current-panel-w',
      expandedWidthVar: '--panel-expanded-width',
      collapsedWidthVar: '--panel-collapsed-width'
    };

    const btnTogglePanel = document.getElementById('adam-panel-toggle');
    const panelElement = document.getElementById('adam-panel');
    const resizer = document.getElementById('adam-panel-resizer');

    const isMobile = () => window.innerWidth <= CONFIG.mobileBreakpoint;

    function readCssVar(name) {
      return getComputedStyle(docEl).getPropertyValue(name).trim() || '';
    }

    function setPanelCssWidth(valuePx) {
      docEl.style.setProperty(CONFIG.cssVar, valuePx);
    }

    function restoreState() {
      if (!isMobile()) {
        try {
          const savedState = localStorage.getItem(CONFIG.panelKey);
          const savedWidth = localStorage.getItem(CONFIG.widthKey);

          if (savedState === 'expanded') {
            body.classList.add('panel-expanded');
            if (savedWidth && !isNaN(parseInt(savedWidth, 10))) {
              setPanelCssWidth(parseInt(savedWidth, 10) + 'px');
            } else {
              setPanelCssWidth(readCssVar(CONFIG.expandedWidthVar) || '360px');
            }
          } else {
            body.classList.remove('panel-expanded');
            setPanelCssWidth(readCssVar(CONFIG.collapsedWidthVar) || '32px');
          }
        } catch (e) {
          body.classList.remove('panel-expanded');
          setPanelCssWidth(readCssVar(CONFIG.collapsedWidthVar) || '32px');
        }
      } else {
        body.classList.remove('panel-expanded');
        body.classList.remove('panel-mobile-open');
        setPanelCssWidth(readCssVar(CONFIG.collapsedWidthVar) || '32px');
      }
    }

    function toggleDesktopPanel() {
      const expanding = !body.classList.contains('panel-expanded');
      if (expanding) {
        body.classList.add('panel-expanded');
        try {
          const savedWidth = localStorage.getItem(CONFIG.widthKey);
          if (savedWidth && !isNaN(parseInt(savedWidth, 10))) {
            setPanelCssWidth(parseInt(savedWidth, 10) + 'px');
          } else {
            setPanelCssWidth(readCssVar(CONFIG.expandedWidthVar) || '360px');
          }
          localStorage.setItem(CONFIG.panelKey, 'expanded');
        } catch (e) { /* noop */ }
      } else {
        body.classList.remove('panel-expanded');
        try {
          setPanelCssWidth(readCssVar(CONFIG.collapsedWidthVar) || '32px');
          localStorage.setItem(CONFIG.panelKey, 'collapsed');
        } catch (e) { /* noop */ }
      }
    }

    function toggleMobilePanel() {
      if (body.classList.contains('panel-mobile-open')) {
        body.classList.remove('panel-mobile-open');
      } else {
        body.classList.add('panel-mobile-open');
        const onDocClick = function (ev) {
          if (!panelElement.contains(ev.target) && (!btnTogglePanel || !btnTogglePanel.contains(ev.target))) {
            body.classList.remove('panel-mobile-open');
            document.removeEventListener('click', onDocClick);
          }
        };
        setTimeout(function () { document.addEventListener('click', onDocClick); }, 120);
      }
    }

    function initToggle() {
      if (!btnTogglePanel) return;
      btnTogglePanel.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (isMobile()) toggleMobilePanel();
        else toggleDesktopPanel();
      });
    }

    function initRailClick() {
      if (!panelElement) return;
      panelElement.addEventListener('click', function (ev) {
        if (resizer && (ev.target === resizer || resizer.contains(ev.target))) return;
        if (!isMobile() && !body.classList.contains('panel-expanded')) {
          body.classList.add('panel-expanded');
          try { localStorage.setItem(CONFIG.panelKey, 'expanded'); } catch (e) { /* noop */ }
          const savedWidth = localStorage.getItem(CONFIG.widthKey);
          if (savedWidth && !isNaN(parseInt(savedWidth, 10))) {
            setPanelCssWidth(parseInt(savedWidth, 10) + 'px');
          } else {
            setPanelCssWidth(readCssVar(CONFIG.expandedWidthVar) || '360px');
          }
        }
      });
    }

    function initResizer() {
      if (!resizer) return;

      // Ensure touch doesn't trigger scroll while dragging
      resizer.style.touchAction = 'none';

      let isDragging = false;
      let activePointerId = null;

      function clampWidth(w) {
        if (w < CONFIG.minWidth) return CONFIG.minWidth;
        if (w > CONFIG.maxWidth) return CONFIG.maxWidth;
        return w;
      }

      function pointerMove(e) {
        if (!isDragging) return;
        // compute width as distance from right edge
        const clientX = (typeof e.clientX === 'number') ? e.clientX : (e.touches && e.touches[0] && e.touches[0].clientX) || 0;
        let newWidth = clampWidth(window.innerWidth - clientX);
        setPanelCssWidth(newWidth + 'px');
        if (e.cancelable) e.preventDefault();
      }

      function endDrag(e) {
        if (!isDragging) return;
        isDragging = false;
        try {
          if (activePointerId !== null && resizer.releasePointerCapture) {
            try { resizer.releasePointerCapture(activePointerId); } catch(_) {}
          }
        } catch(_) {}
        activePointerId = null;
        body.classList.remove('is-resizing');
        // remove listeners
        document.removeEventListener('pointermove', pointerMove);
        document.removeEventListener('pointerup', endDrag);
        document.removeEventListener('pointercancel', endDrag);
        // persist width
        try {
          const cur = getComputedStyle(docEl).getPropertyValue(CONFIG.cssVar).trim();
          const px = parseInt(cur.replace('px', ''), 10);
          if (!Number.isNaN(px)) localStorage.setItem(CONFIG.widthKey, px);
        } catch (err) { /* noop */ }
      }

      function startDrag(e) {
        // only desktop and when panel open
        if (isMobile() || !body.classList.contains('panel-expanded')) return;
        e.preventDefault();
        isDragging = true;
        activePointerId = e.pointerId || null;
        try {
          if (activePointerId !== null && resizer.setPointerCapture) {
            try { resizer.setPointerCapture(activePointerId); } catch(_) {}
          }
        } catch(_) {}
        body.classList.add('is-resizing');
        document.addEventListener('pointermove', pointerMove, { passive: false });
        document.addEventListener('pointerup', endDrag);
        document.addEventListener('pointercancel', endDrag);
      }

      // Prefer pointer events
      resizer.addEventListener('pointerdown', startDrag, { passive: false });

      // For older browsers, fallback to mouse/touch
      resizer.addEventListener('mousedown', function (e) {
        if (window.PointerEvent) return;
        return startDrag(e);
      });
      resizer.addEventListener('touchstart', function (e) {
        if (window.PointerEvent) return;
        return startDrag(e);
      }, { passive: false });
    }

    // watch resize to cleanup mobile/desktop states
    let resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        if (isMobile()) {
          body.classList.remove('panel-expanded');
          body.classList.remove('panel-mobile-open');
          setPanelCssWidth(readCssVar(CONFIG.collapsedWidthVar) || '32px');
        } else {
          try {
            const v = localStorage.getItem(CONFIG.panelKey);
            if (v === 'expanded') {
              body.classList.add('panel-expanded');
              const savedWidth = localStorage.getItem(CONFIG.widthKey);
              if (savedWidth && !isNaN(parseInt(savedWidth, 10))) {
                setPanelCssWidth(parseInt(savedWidth, 10) + 'px');
              } else {
                setPanelCssWidth(readCssVar(CONFIG.expandedWidthVar) || '360px');
              }
            } else {
              body.classList.remove('panel-expanded');
              setPanelCssWidth(readCssVar(CONFIG.collapsedWidthVar) || '32px');
            }
          } catch (e) { /* noop */ }
          body.classList.remove('panel-mobile-open');
        }
      }, 140);
    });

    // Init
    restoreState();
    initToggle();
    initRailClick();
    initResizer();
  });

})();
