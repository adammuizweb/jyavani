document.addEventListener('DOMContentLoaded', function(){
  // keyboard: tekan Delete pada baris terpilih untuk buka modal (UX kecil)
  document.querySelectorAll('.bulkCheckbox').forEach(cb => {
    cb.addEventListener('keydown', function(e){
      if (e.key === 'Delete') {
        const tr = this.closest('tr');
        const btn = tr && tr.querySelector('.adam-hapus');
        if (btn) btn.click();
      }
    });
  });
});
