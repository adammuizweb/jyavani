// /static/assets/modal-img.js
(function () {
  'use strict';

  // safety: tunggu DOM siap
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    // cari atau buat modal jika belum ada
    var modal = document.getElementById('adam-img-modal');
    var modalImg = modal && document.getElementById('adam-img-modal-img');

    if (!modal || !modalImg) {
      // buat modal fallback kalau HTML tidak ada
      modal = document.createElement('div');
      modal.id = 'adam-img-modal';
      modal.style.cssText =
        'display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.85);align-items:center;justify-content:center;cursor:zoom-out;';
      modalImg = document.createElement('img');
      modalImg.id = 'adam-img-modal-img';
      modalImg.style.cssText =
        'max-width:90%;max-height:90%;border-radius:6px;box-shadow:0 6px 24px rgba(0,0,0,0.5);';
      modal.appendChild(modalImg);
      document.body.appendChild(modal);
    }

    // helper: show modal
    function showImage(src, alt) {
      modalImg.src = src || '';
      if (alt) modalImg.alt = alt;
      modal.style.display = 'flex';
      // prevent scroll behind modal
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';
    }

    // helper: hide modal
    function hideModal() {
      modal.style.display = 'none';
      modalImg.src = '';
      document.documentElement.style.overflow = '';
      document.body.style.overflow = '';
    }

    // klik di area gelap -> tutup (tapi klik pada gambar jangan menutup)
    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target === modalImg) {
        hideModal();
      }
    });

    // tombol Esc untuk tutup
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' || e.key === 'Esc') {
        if (modal && modal.style.display === 'flex') hideModal();
      }
    });

    // Delegation: pasang listener pada container artikel
    var container = document.querySelector('.adam-post-single');

    // Jika container ada, pakai delegation (aman untuk gambar yang di-insert oleh HTML)
    if (container) {
container.addEventListener('click', function (ev) {
  var el = ev.target;
  while (el && el !== container) {
    if (el.tagName && el.tagName.toLowerCase() === 'img') {

      // jika gambar di dalam <a href="..."> yang bernavigasi, biarkan browser mengikuti link
      var link = el.closest && el.closest('a');
      if (link) {
        var href = link.getAttribute && link.getAttribute('href');
        if (href) {
          href = String(href).trim();
          var isNav = href !== '' && href !== '#' && !href.match(/^javascript:/i);
          if (isNav) {
            // do nothing — allow navigation (no preventDefault)
            return;
          }
          // otherwise (href empty or '#'), treat as non-navigating and continue to show modal
        }
      }

      // open modal only when not a real navigational link
      ev.preventDefault();
      ev.stopPropagation();
      var src = el.getAttribute('data-src') || el.currentSrc || el.src;
      var alt = el.getAttribute('alt') || '';
      if (src) showImage(src, alt);
      return;
    }
    el = el.parentNode;
  }
});
    } else {
      // fallback: pasang event pada semua <img> di doc
      var imgs = document.querySelectorAll('img');
      imgs.forEach(function (img) {
        img.style.cursor = 'zoom-in';
        img.addEventListener('click', function (e) {
          e.preventDefault();
          var src = img.getAttribute('data-src') || img.currentSrc || img.src;
          showImage(src, img.getAttribute('alt') || '');
        });
      });
    }

    // accessibility: beri focus pada modal image ketika terbuka
    modalImg.setAttribute('tabindex', '0');
    modalImg.addEventListener('click', function (e) {
      // klik gambar tidak menutup, tapi bisa memberi fokus agar Esc bekerja
      e.stopPropagation();
      modalImg.focus();
    });

    // set cursor untuk semua gambar di dalam artikel
    var imgsInArticle = document.querySelectorAll('.adam-post-single img');
    imgsInArticle.forEach(function (i) {
      i.style.cursor = 'zoom-in';
      // jika ada lazy load dengan loading="lazy" atau data-src, biarkan
    });
  });
})();
