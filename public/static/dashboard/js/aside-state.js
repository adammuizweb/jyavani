// /adiwira/theme/adiwira/js/aside-state.js
// apply collapsed state early and suppress initial transitions
(function(){
  try {
    var KEY = 'adam_aside_state';
    var mobileBreakpoint = 900;
    var html = document.documentElement;

    // suppress transitions briefly during first paint
    html.classList.add('no-transitions');

    // If mobile: never apply desktop collapsed state
    if (window.innerWidth <= mobileBreakpoint) {
      html.classList.remove('aside-collapsed');
      document.addEventListener('DOMContentLoaded', function(){
        try { document.body.classList.remove('aside-collapsed'); } catch(e) {}
        setTimeout(function(){ html.classList.remove('no-transitions'); }, 50);
      });
      return;
    }

    var v = null;
    try { v = localStorage.getItem(KEY); } catch(e) { v = null; }

    if (v === 'collapsed') {
      html.classList.add('aside-collapsed');
      if (document.body) document.body.classList.add('aside-collapsed');
    } else {
      html.classList.remove('aside-collapsed');
      if (document.body) document.body.classList.remove('aside-collapsed');
    }

    function clearNoTrans() {
      if (window.requestIdleCallback) {
        requestIdleCallback(function(){ html.classList.remove('no-transitions'); });
      } else {
        setTimeout(function(){ html.classList.remove('no-transitions'); }, 80);
      }
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      clearNoTrans();
    } else {
      document.addEventListener('DOMContentLoaded', clearNoTrans);
    }
  } catch (e) {
    try { document.documentElement.classList.remove('no-transitions'); } catch(_) {}
  }
})();
