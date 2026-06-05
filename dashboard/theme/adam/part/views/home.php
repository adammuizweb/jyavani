<?php
// /adiwira/theme/adam/part/home.php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<?php do_action('admin_home'); ?>
<section class="adam-welcome" style="position:relative;">
    <!-- Konten home tetap ada di bawah flash -->
    <div class="adam-welcome-inner">
        <h2>Halo, selamat datang!</h2>
        <p>Ini adalah dashboard <strong>Adiwira</strong> dengan tema <strong>adam</strong> banh.</p>
    </div>
    <br><br>
        <?php if (!empty($flash_success)): ?>
        <!-- FLASH : hanya dirender jika ada pesan -->
        <div class="adam-flash-wrap" aria-hidden="false" style="display:block;">
            <div id="adam-flash"
                 class="adam-flash adam-flash-success"
                 role="status"
                 aria-live="polite"
                 tabindex="-1">
                <div class="adam-flash-inner">
                    <span class="adam-flash-icon" aria-hidden="true">✅</span>
                    <div class="adam-flash-body">
                        <?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <button type="button"
                            class="adam-flash-close"
                            aria-label="Tutup notifikasi">&times;</button>
                </div>
            </div>
        </div>

        <style>
        /* Pastikan flash mengisi lebar section dan tidak "dikerdilkan" oleh layout kolom */
        .adam-flash-wrap { width: 100%; display: block; box-sizing: border-box; padding: 0 0.25rem; }
        .adam-flash {
            width: 100%;
            max-width: 100%;
            margin: 0 0 1rem 0;
            border-radius: 10px;
            box-shadow: 0 6px 18px rgba(20,30,50,0.06);
            transition: opacity .35s ease, transform .35s ease;
            opacity: 1;
            transform: translateY(0);
            /* Force not to shrink if parent is a flex row/column */
            flex: 0 0 100%;
        }
        .adam-flash.hide { opacity: 0; transform: translateY(-8px); pointer-events: none; }
        .adam-flash-inner {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1rem;
            background: #f0fdf4;
            color: #064e2a;
            border: 1px solid #d1f3d9;
            border-radius: 10px;
            font-weight: 600;
            box-sizing: border-box;
        }
        .adam-flash-icon { font-size: 1.1rem; line-height: 1; }
        .adam-flash-body { flex: 1; word-break: break-word; }
        .adam-flash-close {
            background: transparent;
            border: 0;
            font-size: 1.2rem;
            cursor: pointer;
            color: rgba(6,78,42,0.8);
            padding: 0 .25rem;
            border-radius: 6px;
        }
        .adam-flash-close:focus { outline: 2px solid rgba(11,118,239,0.25); outline-offset: 2px; }
        @media (max-width: 640px) {
            .adam-flash-inner { padding: 0.7rem 0.9rem; font-size: 0.95rem; }
        }
        </style>

        <script>
        (function () {
            try {
                var el = document.getElementById('adam-flash');
                if (!el) return;

                var closeBtn = el.querySelector('.adam-flash-close');

                // Fokus supaya screen reader mengetahui notifikasi
                try { el.focus({ preventScroll: true }); } catch (e) {}

                // Auto-hide setelah 4 detik
                var hideTimer = setTimeout(hideFlash, 4000);

                closeBtn.addEventListener('click', function () {
                    clearTimeout(hideTimer);
                    hideFlash();
                });

                function hideFlash() {
                    el.classList.add('hide');
                    setTimeout(function () {
                        if (el && el.parentNode) el.parentNode.removeChild(el);
                    }, 420);
                }

                // ESC untuk menutup
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' || e.key === 'Esc') {
                        clearTimeout(hideTimer);
                        hideFlash();
                    }
                });
            } catch (err) {
                console && console.warn && console.warn('flash error:', err);
            }
        })();
        </script>
    <?php endif; ?>
    
</section>
