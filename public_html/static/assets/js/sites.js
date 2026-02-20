// NAV FUTURISTIC ONLOAD trigger
(function(){
  'use strict';
  function run(){
    try{
      if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

      const html = document.documentElement;
      // pastikan nav-boot ada (kalau kamu tidak pakai opsi head)
      html.classList.add('nav-boot');

      // masukin class nav-enter setelah render 2 frame biar animasi kebaca
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          html.classList.add('nav-enter');
          // setelah animasi selesai, bersihkan nav-boot (biar balik normal)
          setTimeout(() => html.classList.remove('nav-boot'), 1400);
        });
      });
    }catch(e){}
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
