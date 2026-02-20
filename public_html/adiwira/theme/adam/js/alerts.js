(function(){
  // tunggu DOM siap
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    // ambil semua adam-alert
    const alerts = document.querySelectorAll('.adam-alert');
    if (!alerts.length) return;

    alerts.forEach(alert => {
      // JANGAN auto-dismiss error
      if (alert.classList.contains('error')) return;

      // durasi default 3 detik
      const delay = 3000;

      setTimeout(() => {
        // fade-out ringan (tanpa CSS tambahan)
        alert.style.transition = 'opacity .3s ease, transform .3s ease';
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(-4px)';

        setTimeout(() => {
          if (alert.parentNode) {
            alert.parentNode.removeChild(alert);
          }
        }, 300);
      }, delay);
    });
  }
})();