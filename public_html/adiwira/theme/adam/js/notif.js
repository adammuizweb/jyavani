// notif.js
// Menjaga agar .adam-alert tidak dihapus otomatis oleh skrip lain,
// tapi IZINKAN penghapusan untuk .adam-alert.auto-dismiss.
// Dukungan: tombol close (.alert-close atau .close) tetap bekerja.

(function(){
  'use strict';

  // simpan reference asli
  const origElemRemove = Element.prototype.remove;
  const origRemoveChild = Node.prototype.removeChild;

  function isAdamAlert(node) {
    try {
      if (!node || node.nodeType !== 1) return false;
      if (node.classList && node.classList.contains('adam-alert')) return true;
      if (node.closest && node.closest('.adam-alert')) return true;
    } catch (e) {}
    return false;
  }

  // override Element.remove
  Element.prototype.remove = function() {
    try {
      // find the alert element this call would affect (self or ancestor)
      const targetAlert = (this.classList && this.classList.contains('adam-alert')) ? this
                            : (this.closest ? this.closest('.adam-alert') : null);

      if (targetAlert) {
        // allow removal if:
        // - alert explicitly allowed (dataset.allowRemove === '1'), OR
        // - alert has class "auto-dismiss"
        const allow = (targetAlert.dataset && targetAlert.dataset.allowRemove === '1')
                      || (targetAlert.classList && targetAlert.classList.contains('auto-dismiss'));
        if (!allow) {
          // block automatic removal for non-auto-dismiss alerts
          return this;
        }
      }
    } catch (err) {
      // if anything goes wrong, fall back to original
    }
    return origElemRemove.apply(this, arguments);
  };

  // override Node.removeChild to guard parent.removeChild(child) usage
  Node.prototype.removeChild = function(child) {
    try {
      if (isAdamAlert(child)) {
        const allow = (child.dataset && child.dataset.allowRemove === '1')
                      || (child.classList && child.classList.contains('auto-dismiss'));
        if (!allow) {
          return child; // block
        }
      }
    } catch (err) {}
    return origRemoveChild.apply(this, arguments);
  };

  // Delegated click handler for manual close buttons inside alerts.
  // Supported selectors: .alert-close, .close
  document.addEventListener('click', function(ev){
    try {
      const btn = ev.target.closest ? ev.target.closest('.adam-alert .alert-close, .adam-alert .close, .alert-close, .close') : null;
      if (!btn) return;
      const alert = btn.closest ? btn.closest('.adam-alert') : null;
      if (!alert) return;
      // allow removal and remove using original remove
      if (alert.dataset) alert.dataset.allowRemove = '1';
      if (typeof origElemRemove === 'function') origElemRemove.call(alert);
    } catch (err) {
      // noop
    }
  }, true);

  // If .auto-dismiss alerts are added, schedule their automatic removal here
  // using the original remove method (so it bypasses our guard because
  // auto-dismiss class is checked in the override).
  function scheduleAutoDismiss(el, delayMs) {
    if (!el || !(el instanceof Element)) return;
    try {
      // do not schedule if not marked auto-dismiss
      if (!(el.classList && el.classList.contains('auto-dismiss'))) return;
      setTimeout(() => {
        // mark allowRemove for safety and remove
        if (el.dataset) el.dataset.allowRemove = '1';
        if (typeof origElemRemove === 'function') {
          origElemRemove.call(el);
        } else {
          el.remove(); // fallback (will be allowed because of class auto-dismiss)
        }
      }, Math.max(0, parseInt(delayMs || 3000, 10)));
    } catch (e) {}
  }

  // Observe newly-added alerts and apply auto-dismiss scheduling when needed
  try {
    const observer = new MutationObserver(function(mutations){
      for (const m of mutations) {
        if (!m.addedNodes) continue;
        m.addedNodes.forEach(node => {
          try {
            if (node && node.nodeType === 1 && node.classList && node.classList.contains('adam-alert')) {
              // Ensure allowRemove flag not set by default
              if (node.dataset && node.dataset.allowRemove === '1') delete node.dataset.allowRemove;
              // If element has class auto-dismiss, schedule removal
              if (node.classList.contains('auto-dismiss')) {
                // if a custom duration is provided as data-dismiss-ms, use it
                const ms = node.dataset && node.dataset.dismissMs ? parseInt(node.dataset.dismissMs,10) : 3000;
                scheduleAutoDismiss(node, ms);
              }
            }
          } catch (e) {}
        });
      }
    });
    observer.observe(document.documentElement || document.body, { childList: true, subtree: true });
  } catch (err) {
    // fallback: attempt to schedule already existing alerts
    document.querySelectorAll && document.querySelectorAll('.adam-alert.auto-dismiss').forEach(el => {
      const ms = el.dataset && el.dataset.dismissMs ? parseInt(el.dataset.dismissMs,10) : 3000;
      scheduleAutoDismiss(el, ms);
    });
  }

  // Also schedule existing auto-dismiss alerts present at script run
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll && document.querySelectorAll('.adam-alert.auto-dismiss').forEach(el => {
      const ms = el.dataset && el.dataset.dismissMs ? parseInt(el.dataset.dismissMs,10) : 3000;
      scheduleAutoDismiss(el, ms);
    });
  });
})();
