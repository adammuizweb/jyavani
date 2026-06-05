<?php
// footer.php - simple footer
$year = date('Y');
?>
<!-- =========================
     FOOTER: Simple 2-rowSA test lokal
     ========================= -->
<footer class="site-footer fade-up onload" data-duration="1000" data-anime-trigger="load" role="contentinfo">
  <div class="footer-container">
    <!-- Baris 1: Halaman (links) dan Social -->
    <div class="footer-row-top">
      <div class="footer-col footer-pages slide-right" aria-label="<?= __('Pages') ?>" data-anime-trigger="load" data-delay="250">
        <h3 class="visually-hidden"><?= __('Pages') ?></h3>
        <ul class="footer-links" role="list">
          <li><a class="footer-link" href="/about" aria-label="<?= __('About') ?>"> 
              <!-- icon kecil di samping -->
              <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10" stroke="currentColor" fill="none" stroke-width="1.5"/></svg>
              <span><?= __('About') ?></span>
            </a></li>
          <li><a class="footer-link" href="/docs" aria-label="<?= __('Docs') ?>">
              <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="4" width="16" height="16" stroke="currentColor" fill="none" stroke-width="1.5"/></svg>
              <span><?= __('Docs') ?></span>
            </a></li>
          <li><a class="footer-link" href="/contact" aria-label="<?= __('Contact') ?>">
              <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 8l9 6 9-6" stroke="currentColor" fill="none" stroke-width="1.6"/></svg>
              <span><?= __('Contact') ?></span>
            </a></li>
        </ul>
      </div>

      <div class="footer-col footer-social slide-left" aria-label="<?= __('Social Media') ?>" data-anime-trigger="load" data-delay="250">
        <h3 class="visually-hidden"><?= __('Social') ?></h3>
        <div class="social-icons" role="list">
          <a class="social-btn" href="https://twitter.com/" target="_blank" rel="noopener" aria-label="Twitter">
            <!-- twitter -->
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
              <path d="M23 4.6c-.8.4-1.7.7-2.7.8.98-.6 1.7-1.6 2-2.7-.9.5-1.9.9-3 1.1-1-1-2.5-1.6-4.1-1.6-3.1 0-5.3 2.8-4.6 5.7-3.9-.2-7.4-2-9.7-4.8-1.2 2.1-.6 4.9 1.4 6.3-.7 0-1.4-.2-2-.6v.1c0 2.3 1.6 4.3 3.7 4.8-.6.2-1.2.2-1.8.1.5 1.6 2 2.8 3.8 2.8C6 20 3.1 21 0 21c2.2 1.3 4.8 2 7.6 2 9.1 0 14-7.6 14-14v-.6c1-.7 1.8-1.6 2.4-2.6z" fill="currentColor"/>
            </svg>
          </a>

          <a class="social-btn" href="https://github.com/" target="_blank" rel="noopener" aria-label="GitHub">
            <!-- github -->
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
              <path d="M12 .5C5.7.5.9 5.3.9 11.6c0 4.7 3 8.7 7.2 10.1.5.1.7-.2.7-.5v-1.8c-2.9.6-3.5-1.2-3.5-1.2-.5-1.2-1.2-1.5-1.2-1.5-1-.7.1-.7.1-.7 1.1.1 1.7 1.1 1.7 1.1 1 .1 1.7.8 2.9.6.1-.7.4-1.2.8-1.5-2.3-.3-4.6-1.1-4.6-5 0-1.1.4-2 1.1-2.7-.1-.3-.5-1.5.1-3.1 0 0 .9-.3 3 .1.9-.3 1.8-.4 2.7-.4s1.8.1 2.7.4c2.1-.5 3-.1 3-.1.6 1.6.2 2.8.1 3.1.7.7 1.1 1.6 1.1 2.7 0 3.9-2.3 4.7-4.6 5 .4.3.7.9.7 1.8v2.6c0 .3.2.6.7.5 4.2-1.4 7.2-5.4 7.2-10.1C23 5.3 18.3.5 12 .5z" fill="currentColor"/>
            </svg>
          </a>

          <a class="social-btn" href="https://instagram.com/" target="_blank" rel="noopener" aria-label="Instagram">
            <!-- instagram -->
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
              <rect x="3" y="3" width="18" height="18" rx="5" ry="5" stroke="currentColor" fill="none" stroke-width="1.5"/>
              <circle cx="12" cy="12" r="3" fill="currentColor"/>
              <circle cx="17.5" cy="6.5" r="0.7" fill="currentColor"/>
            </svg>
          </a>
        </div>
      </div>
    </div>

    <!-- Baris 2: Copyright -->
    <div class="footer-row-bottom">
      <div class="copyright-text typewrite onload" data-duration="1000" data-anime-trigger="load"><?= _e('©') ?> <span id="year"></span> Jyavani. <?= _e('All rights reserved.') ?></div>
    </div>
  </div>
</footer>