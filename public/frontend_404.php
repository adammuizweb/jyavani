<?php
declare(strict_types=1);

// rootweb/frontend_404.php
if (!defined('PUBLIC_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap_core.php';
    require_once __DIR__ . '/bootstrap_theme.php';
}

http_response_code(404);

// pastikan layout memakai pdo global yang benar
$layout_pdo = $GLOBALS['pdo'] ?? ($pdo ?? null);
if ($layout_pdo instanceof PDO) {
    $pdo = $layout_pdo;
}

$page_title = '404 — Tidak ditemukan';
$context_for_layout = '404';

$layout_full_width = false;
$enable_sidebar    = true;
$sidebar_position  = 'right';

$vars = [
    'site_context'       => '404',
    'page_title'         => $page_title,
    'layout_full_width'  => $layout_full_width,
    'enable_sidebar'     => $enable_sidebar,
    'sidebar_position'   => $sidebar_position,
];

$content_html = '';

// 1) Coba lewat theme engine jika tersedia
if (($pdo ?? null) instanceof PDO && function_exists('render_slot')) {
    try {
        $slot = render_slot($pdo, 'main.404', $vars);
        if (trim((string)$slot) !== '') {
            $content_html = (string)$slot;
        }
    } catch (Throwable $e) {
        error_log('[frontend_404] render_slot(main.404) error: ' . $e->getMessage());
    }
}

// 2) Fallback langsung ke file theme default
if (trim($content_html) === '' && defined('DEFAULT_THEME_FOLDER')) {
    $file = __DIR__ . '/views/themes/' . DEFAULT_THEME_FOLDER . '/main/404.php';
    if (is_file($file)) {
        try {
            ob_start();
            extract($vars, EXTR_SKIP);
            require $file;
            $content_html = (string)ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            error_log('[frontend_404] theme 404 include error: ' . $e->getMessage());
        }
    }
}

// 3) Inline fallback terakhir
if (trim($content_html) === '') {
    $content_html = '
    <section class="error-page">
      <h1 class="error-title">404 — Halaman Tidak Ditemukan</h1>
      <p class="error-message">Maaf, halaman yang kamu cari tidak tersedia atau telah dipindahkan.</p>
      <a href="/" class="error-link">Kembali ke Beranda</a>
    </section>
    <style>
    .error-page {
      text-align: center;
      padding: 80px 20px;
    }
    .error-title {
      font-size: 2.5em;
      color: #d9534f;
    }
    .error-message {
      font-size: 1.2em;
      margin: 20px 0;
    }
    .error-link {
      display: inline-block;
      margin-top: 30px;
      padding: 10px 20px;
      background-color: #0275d8;
      color: #fff;
      text-decoration: none;
      border-radius: 5px;
    }
    </style>';
}

require __DIR__ . '/layout.php';
exit;