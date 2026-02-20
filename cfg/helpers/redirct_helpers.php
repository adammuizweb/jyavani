<?php
if (!function_exists('qz_redirect')) {
  function qz_redirect(string $url): void {
    // Kalau masih bisa pakai header, bersihkan buffer agar tidak ada output nyangkut.
    if (!headers_sent()) {
      while (ob_get_level() > 0) {
        ob_end_clean();
      }
      // 303 lebih benar untuk POST -> GET
      header('Location: ' . $url, true, 303);
      exit;
    }

    // Kalau header sudah terlanjur terkirim, fallback JS/meta refresh
    echo '<script>location.replace(' . json_encode($url) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
  }
}
