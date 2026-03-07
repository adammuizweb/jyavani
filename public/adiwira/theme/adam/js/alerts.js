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

// js milik adam modal
(function(){
  const modal = document.getElementById('deleteModal');
  if (!modal) return;

  // ensure modal panel stops propagation so clicks inside don't close
  const panel = modal.querySelector('.adam-modal__panel');
  if (panel) {
    panel.addEventListener('click', function(e){ e.stopPropagation(); });
  }

  // close when clicking overlay (but not panel)
  modal.addEventListener('click', function(e){
    if (e.target === modal) closeDeleteModal();
  });

  // close on ESC
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && modal.classList.contains('show')) closeDeleteModal();
  });

  // export functions globally (used by your markup)
  window.openDeleteModal = function(btn){
    // append to body to avoid any ancestor stacking context problems
    if (modal.parentNode !== document.body) document.body.appendChild(modal);

    modal.classList.add('show');
    modal.style.display = 'flex';

    // lock page scrolling while modal open
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';

    const idInput = document.getElementById('deleteId');
    if (idInput) idInput.value = btn.dataset.id || '';

    const txt = document.getElementById('deleteText');
    if (txt) txt.innerText = `Hapus artikel "${btn.dataset.title || ''}"?`;

    // focus actionable button
    const confirmBtn = modal.querySelector('button[type="submit"], .adam-btn--danger');
    if (confirmBtn) confirmBtn.focus();
  };

  window.closeDeleteModal = function(){
    modal.classList.remove('show');
    modal.style.display = 'none';
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
  };
})();


// pengganti bulk succ
// admin-bulk.js (usage: intercept form submit)
async function submitBulkForm(form) {
  const url = form.action;
  const fd = new FormData(form);

  // optionally add header to indicate AJAX
  try {
    const res = await fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const j = await res.json();
    if (j.ok) {
      showToast(j.message || 'Operasi berhasil', 'success', 4000);
      // optionally refresh table or remove rows
      // refresh current page or update DOM selectively
      setTimeout(()=> location.reload(), 900); // gentle refresh
    } else {
      showToast(j.message || (j.errors && j.errors.join?.(', ')) || 'Gagal', 'error', 6000);
    }
  } catch (err) {
    showToast('Terjadi kesalahan jaringan', 'error', 6000);
  }
}

showToast('Berhasil menghapus 5 artikel', 'success', 4500, {
  label: 'Undo',
  onClick: () => {
    // panggil endpoint undo via fetch; contoh saja
    fetch('/adiwira/admin/posts/undo_delete.php',{ method:'POST', body: new FormData(/*...*/)}).then(r=>r.json()).then(j=>{
      showToast(j.ok ? 'Dibatalkan' : 'Gagal undo', j.ok ? 'success' : 'error');
    });
  }
});