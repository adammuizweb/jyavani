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
    const panelBody = document.getElementById('adam-panel-body');
    const resizer = document.getElementById('adam-panel-resizer');
    const panelClose = document.getElementById('adam-panel-close');
    const focusStart = panelElement && panelElement.querySelector('[data-panel-focus-start]');
    const focusEnd = panelElement && panelElement.querySelector('[data-panel-focus-end]');
    const asideElement = document.getElementById('adam-aside');
    let mobileLastFocus = null;
    let previousMobile = window.innerWidth <= CONFIG.mobileBreakpoint;

    if (!panelElement) {
      if (btnTogglePanel) btnTogglePanel.hidden = true;
      return;
    }

    const isMobile = () => window.innerWidth <= CONFIG.mobileBreakpoint;

    function readCssVar(name) {
      return getComputedStyle(docEl).getPropertyValue(name).trim() || '';
    }

    function maxPanelWidth() {
      const asideWidth = asideElement ? asideElement.getBoundingClientRect().width : 0;
      return Math.max(CONFIG.minWidth, Math.min(CONFIG.maxWidth, window.innerWidth - asideWidth - 352));
    }

    function clampWidth(width) {
      return Math.max(CONFIG.minWidth, Math.min(maxPanelWidth(), width));
    }

    function setPanelCssWidth(valuePx) {
      docEl.style.setProperty(CONFIG.cssVar, valuePx);
      if (resizer) {
        const width = parseInt(valuePx, 10);
        resizer.setAttribute('aria-valuemax', String(maxPanelWidth()));
        if (!Number.isNaN(width) && width >= CONFIG.minWidth) {
          resizer.setAttribute('aria-valuenow', String(clampWidth(width)));
        }
      }
    }

    function setExpandedPanelWidth(value) {
      const width = parseInt(value, 10);
      const normalized = clampWidth(Number.isNaN(width) ? 360 : width);
      setPanelCssWidth(normalized + 'px');
      return normalized;
    }

    function syncPanelState() {
      const open = isMobile()
        ? body.classList.contains('panel-mobile-open')
        : body.classList.contains('panel-expanded');
      if (btnTogglePanel) btnTogglePanel.setAttribute('aria-expanded', open ? 'true' : 'false');
      panelElement.setAttribute('aria-hidden', open ? 'false' : 'true');
      if (isMobile() && open) {
        panelElement.setAttribute('role', 'dialog');
        panelElement.setAttribute('aria-modal', 'true');
      } else {
        panelElement.setAttribute('role', 'complementary');
        panelElement.removeAttribute('aria-modal');
      }
    }

    function restoreState() {
      if (!isMobile()) {
        try {
          const savedState = localStorage.getItem(CONFIG.panelKey);
          const savedWidth = localStorage.getItem(CONFIG.widthKey);

          if (savedState === 'expanded') {
            body.classList.add('panel-expanded');
            if (savedWidth && !isNaN(parseInt(savedWidth, 10))) {
              setExpandedPanelWidth(savedWidth);
            } else {
              setExpandedPanelWidth(readCssVar(CONFIG.expandedWidthVar) || '360px');
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
      syncPanelState();
    }

    function toggleDesktopPanel() {
      const expanding = !body.classList.contains('panel-expanded');
      if (expanding) {
        body.classList.add('panel-expanded');
        let savedWidth = null;
        try { savedWidth = localStorage.getItem(CONFIG.widthKey); } catch (e) { /* noop */ }
        setExpandedPanelWidth(savedWidth && !isNaN(parseInt(savedWidth, 10))
          ? savedWidth
          : (readCssVar(CONFIG.expandedWidthVar) || '360px'));
        try { localStorage.setItem(CONFIG.panelKey, 'expanded'); } catch (e) { /* noop */ }
      } else {
        body.classList.remove('panel-expanded');
        try {
          setPanelCssWidth(readCssVar(CONFIG.collapsedWidthVar) || '32px');
          localStorage.setItem(CONFIG.panelKey, 'collapsed');
        } catch (e) { /* noop */ }
      }
      syncPanelState();
    }

    function panelFocusable() {
      return Array.from(panelElement.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), iframe, [tabindex]:not([tabindex="-1"])')).filter(function (element) {
        return element !== resizer && element !== focusStart && element !== focusEnd
          && (element.offsetWidth > 0 || element.offsetHeight > 0 || element.getClientRects().length > 0);
      });
    }

    function closeMobilePanel(restoreFocus) {
      body.classList.remove('panel-mobile-open');
      syncPanelState();
      if (restoreFocus && mobileLastFocus && typeof mobileLastFocus.focus === 'function') mobileLastFocus.focus();
      mobileLastFocus = null;
    }

    function toggleMobilePanel() {
      if (body.classList.contains('panel-mobile-open')) {
        closeMobilePanel(true);
        return;
      }
      mobileLastFocus = document.activeElement;
      body.classList.add('panel-mobile-open');
      syncPanelState();
      setTimeout(function () {
        const target = panelFocusable()[0] || panelBody;
        if (target && typeof target.focus === 'function') target.focus();
      }, 0);
    }

    function initToggle() {
      if (!btnTogglePanel) return;
      btnTogglePanel.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (isMobile()) toggleMobilePanel();
        else toggleDesktopPanel();
      });
    }

    function initMobileAccessibility() {
      if (panelClose) panelClose.addEventListener('click', function () { closeMobilePanel(true); });
      if (focusStart) focusStart.addEventListener('focus', function () {
        const focusable = panelFocusable();
        (focusable[focusable.length - 1] || panelBody).focus();
      });
      if (focusEnd) focusEnd.addEventListener('focus', function () {
        const focusable = panelFocusable();
        (focusable[0] || panelBody).focus();
      });
      panelElement.querySelectorAll('iframe').forEach(function (frame) {
        const bindEscape = function () {
          try {
            frame.contentDocument.addEventListener('keydown', function (event) {
              if (event.key === 'Escape' && isMobile() && body.classList.contains('panel-mobile-open')) {
                event.preventDefault();
                closeMobilePanel(true);
              }
            });
          } catch (error) { /* Cross-origin plugin frames retain the visible close button. */ }
        };
        frame.addEventListener('load', bindEscape);
        bindEscape();
      });
      document.addEventListener('click', function (event) {
        if (!isMobile() || !body.classList.contains('panel-mobile-open')) return;
        if (!panelElement.contains(event.target) && (!btnTogglePanel || !btnTogglePanel.contains(event.target))) {
          closeMobilePanel(true);
        }
      });
      document.addEventListener('keydown', function (event) {
        if (!isMobile() || !body.classList.contains('panel-mobile-open')) return;
        if (event.key === 'Escape') {
          event.preventDefault();
          closeMobilePanel(true);
          return;
        }
        if (event.key !== 'Tab') return;
        const focusable = panelFocusable();
        if (focusable.length === 0) {
          event.preventDefault();
          if (panelBody) panelBody.focus();
          return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (!panelElement.contains(document.activeElement)) {
          event.preventDefault();
          (event.shiftKey ? last : first).focus();
        } else if (event.shiftKey && (document.activeElement === first || document.activeElement === panelBody)) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      });
    }

    function initRailClick() {
      if (!panelElement) return;
      panelElement.addEventListener('click', function (ev) {
        if (resizer && (ev.target === resizer || resizer.contains(ev.target))) return;
        if (!isMobile() && !body.classList.contains('panel-expanded')) {
          body.classList.add('panel-expanded');
          try { localStorage.setItem(CONFIG.panelKey, 'expanded'); } catch (e) { /* noop */ }
          let savedWidth = null;
          try { savedWidth = localStorage.getItem(CONFIG.widthKey); } catch (e) { /* noop */ }
          if (savedWidth && !isNaN(parseInt(savedWidth, 10))) {
            setExpandedPanelWidth(savedWidth);
          } else {
            setExpandedPanelWidth(readCssVar(CONFIG.expandedWidthVar) || '360px');
          }
          syncPanelState();
        }
      });
    }

    function initResizer() {
      if (!resizer) return;

      // Ensure touch doesn't trigger scroll while dragging
      resizer.style.touchAction = 'none';

      let isDragging = false;
      let activePointerId = null;

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
      resizer.addEventListener('keydown', function (e) {
        if (isMobile() || !body.classList.contains('panel-expanded')) return;
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
        e.preventDefault();
        const current = parseInt(getComputedStyle(docEl).getPropertyValue(CONFIG.cssVar), 10) || CONFIG.minWidth;
        const width = e.key === 'Home'
          ? CONFIG.minWidth
          : (e.key === 'End' ? maxPanelWidth() : clampWidth(current + (e.key === 'ArrowLeft' ? 20 : -20)));
        setPanelCssWidth(width + 'px');
        try { localStorage.setItem(CONFIG.widthKey, String(width)); } catch (error) { /* noop */ }
      });

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

    // Watch breakpoint changes without closing the mobile sheet for virtual-keyboard resizes.
    let resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        const mobile = isMobile();
        if (mobile === previousMobile) {
          if (!mobile && body.classList.contains('panel-expanded')) {
            setExpandedPanelWidth(getComputedStyle(docEl).getPropertyValue(CONFIG.cssVar));
          }
          syncPanelState();
          return;
        }
        previousMobile = mobile;
        if (mobile) {
          body.classList.remove('panel-expanded');
          setPanelCssWidth(readCssVar(CONFIG.collapsedWidthVar) || '32px');
        } else {
          if (body.classList.contains('panel-mobile-open')) closeMobilePanel(true);
          restoreState();
        }
        syncPanelState();
      }, 140);
    });

    let layoutTimer = null;
    new MutationObserver(function () {
      if (isMobile() || !body.classList.contains('panel-expanded')) return;
      clearTimeout(layoutTimer);
      layoutTimer = setTimeout(function () {
        setExpandedPanelWidth(getComputedStyle(docEl).getPropertyValue(CONFIG.cssVar));
      }, 220);
    }).observe(body, { attributes: true, attributeFilter: ['class'] });

    // Init
    restoreState();
    initToggle();
    initMobileAccessibility();
    initRailClick();
    initResizer();
  });

})();
