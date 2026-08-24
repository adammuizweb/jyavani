<?php
declare(strict_types=1);

// public/controllers/PrivateFileController.php
// Protected file stream + PDF viewer untuk File Library private/public.

class PrivateFileController
{
    private static function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function abort(int $code = 404, string $message = ''): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo '<!doctype html><meta charset="utf-8"><title>Not found</title><p>Not found</p>';
        exit;
    }

    private static function fileId(): int
    {
        return max(0, (int)($_GET['id'] ?? 0));
    }

    private static function privateBaseDir(): string
    {
        $appRoot = realpath(__DIR__ . '/../..');
        if ($appRoot === false) {
            $appRoot = dirname(__DIR__, 2);
        }

        return rtrim(str_replace('\\', '/', $appRoot), '/') . '/private_files';
    }

    private static function safePrivatePath(string $relative): ?string
    {
        $base = self::privateBaseDir() . '/files';
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $realBase = realpath($base);
        $candidate = $base . '/' . $relative;
        $realFile = realpath($candidate);

        if ($realBase === false || $realFile === false || !is_file($realFile)) {
            return null;
        }

        $realBase = rtrim(str_replace('\\', '/', $realBase), '/') . '/';
        $realFileNorm = str_replace('\\', '/', $realFile);

        if (strpos($realFileNorm, $realBase) !== 0) {
            return null;
        }

        return $realFile;
    }

    private static function fetchFile(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT * FROM `file` WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function canAccess(PDO $pdo, array $file): array
    {
        $visibility = strtolower((string)($file['visibility'] ?? 'public'));
        $disk = strtolower((string)($file['storage_disk'] ?? 'public'));
        $scope = strtolower((string)($file['access_scope'] ?? 'public'));

        if ($visibility === 'public' && $disk === 'public' && $scope === 'public') {
            return ['allowed' => true];
        }

        return ['allowed' => content_access_scope_allows($pdo, $scope)];
    }

    private static function publicUrl(array $file): string
    {
        return trim((string)($file['url'] ?? ''));
    }

    private static function tokenSecret(): string
    {
        $secret = trim((string)(getenv('PRIVATE_FILE_TOKEN_SECRET') ?: getenv('APP_KEY') ?: getenv('SESSION_SECRET') ?: ''));
        if ($secret !== '') return $secret;

        error_log('[PrivateFileController] WARNING: No token secret configured. Using fallback.');
        return hash('sha256', __FILE__ . '|jyavani-private-file-token');
    }

    private static function makePdfRawToken(int $id, int $ttlSeconds = 900): string
    {
        $exp = time() + max(60, $ttlSeconds);
        $sig = hash_hmac('sha256', $id . '|' . $exp, self::tokenSecret());
        return $exp . ':' . $sig;
    }

    private static function validatePdfRawToken(int $id): bool
    {
        $token = (string)($_GET['t'] ?? '');
        if ($id <= 0 || $token === '' || !str_contains($token, ':')) return false;

        [$expRaw, $sig] = explode(':', $token, 2);
        if (!ctype_digit($expRaw)) return false;

        $exp = (int)$expRaw;
        if ($exp < time()) return false;

        $expected = hash_hmac('sha256', $id . '|' . $exp, self::tokenSecret());
        return hash_equals($expected, $sig);
    }

    private static function protectedStreamUrl(int $id, bool $signedPdfRaw = false): string
    {
        if ($signedPdfRaw) {
            return '/private/pdf/raw/?id=' . $id . '&t=' . rawurlencode(self::makePdfRawToken($id));
        }
        return '/private/file/view/?id=' . $id;
    }

    private static function pdfViewerUrl(int $id): string
    {
        return '/private/pdf/view/?id=' . $id;
    }

    private static function isPdf(array $file): bool
    {
        $mime = strtolower((string)($file['mime'] ?? ''));
        $ext = strtolower((string)($file['ext'] ?? pathinfo((string)($file['filename'] ?? ''), PATHINFO_EXTENSION)));

        return $mime === 'application/pdf' || $ext === 'pdf';
    }

    public static function stream(PDO $pdo): void
    {
        $id = self::fileId();
        $file = self::fetchFile($pdo, $id);
        if (!$file) {
            self::abort(404);
        }

        $access = self::canAccess($pdo, $file);
        if (!$access['allowed']) {
            self::abort(404);
        }

        $visibility = strtolower((string)($file['visibility'] ?? 'public'));
        $disk = strtolower((string)($file['storage_disk'] ?? 'public'));

        if ($disk === 'public' && $visibility === 'public') {
            $url = self::publicUrl($file);
            if ($url === '') {
                self::abort(404);
            }

            header('Location: ' . $url, true, 302);
            exit;
        }

        $isPdf = self::isPdf($file);
        $reqPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        $isPdfRawRoute = (bool)preg_match('#/private/pdf/raw(?:/|\.php|$)#i', $reqPath);

        if ($isPdf && ($visibility === 'private' || $disk === 'private')) {
            if (!$isPdfRawRoute || !self::validatePdfRawToken($id)) {
                self::abort(404);
            }
        }

        $storagePath = trim((string)($file['storage_path'] ?? ''));
        $realFile = self::safePrivatePath($storagePath);
        if ($realFile === null) {
            self::abort(404);
        }

        $downloadRequested = in_array((string)($_GET['download'] ?? ''), ['1', 'true', 'yes'], true);
        $isDownloadable = (int)($file['is_downloadable'] ?? 0) === 1;

        if ($downloadRequested && !$isDownloadable) {
            self::abort(404);
        }

        $mime = trim((string)($file['mime'] ?? ''));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        $filename = (string)($file['filename'] ?? basename($realFile));
        $filename = str_replace(["\r", "\n", '"'], ['', '', ''], $filename);

        $size = (int)filesize($realFile);
        $disposition = ($downloadRequested && $isDownloadable) ? 'attachment' : 'inline';

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($filename) . '"');
        header('Accept-Ranges: bytes');

        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            if ($m[1] !== '') {
                $start = max(0, (int)$m[1]);
            }
            if ($m[2] !== '') {
                $end = min($end, (int)$m[2]);
            }
            if ($start <= $end && $start < $size) {
                $status = 206;
            } else {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
        }

        $length = $end - $start + 1;

        if ($status === 206) {
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }

        header('Content-Length: ' . $length);

        $fp = fopen($realFile, 'rb');
        if (!$fp) {
            self::abort(404);
        }

        if ($start > 0) {
            fseek($fp, $start);
        }

        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunkSize = min(8192, $remaining);
            $buffer = fread($fp, $chunkSize);
            if ($buffer === false || $buffer === '') {
                break;
            }
            echo $buffer;
            $remaining -= strlen($buffer);
            flush();
        }

        fclose($fp);
        exit;
    }

    public static function pdfViewer(PDO $pdo): void
    {
        $id = self::fileId();
        $file = self::fetchFile($pdo, $id);
        if (!$file) {
            self::abort(404);
        }

        $access = self::canAccess($pdo, $file);
        if (!$access['allowed']) {
            self::abort(404);
        }

        if (!self::isPdf($file)) {
            self::abort(400, 'Viewer ini hanya untuk PDF.');
        }

        $title = trim((string)($file['title'] ?? ''));
        if ($title === '') {
            $title = trim((string)($file['filename'] ?? ('PDF #' . $id)));
        }

        $streamUrl = '/private/pdf/raw/?id=' . $id . '&t=' . rawurlencode(self::makePdfRawToken($id, 7200));
        $pdfJsUrl = '/static/vendor/pdfjs/pdf.min.js';
        $pdfWorkerUrl = '/static/vendor/pdfjs/pdf.worker.min.js';

        $isEmbed = in_array(strtolower((string)($_GET['embed'] ?? '')), ['1', 'true', 'yes'], true);

        $now = date('Y-m-d H:i:s');

        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        ?>
<!doctype html>
<html lang="<?=e(get_locale())?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title><?= self::e($title) ?> — PDF Viewer</title>
  <style>
    .pvtpdf-viewer,
    .pvtpdf-viewer * { box-sizing: border-box; }

    .pvtpdf-viewer {
      --pvtpdf-blue: #2563eb;
      --pvtpdf-cyan: #14b8a6;
      --pvtpdf-text: #0f172a;
      --pvtpdf-muted: #64748b;
      --pvtpdf-border: rgba(148,163,184,.32);
      --pvtpdf-soft: rgba(239,246,255,.88);
      min-height: 100vh;
      margin: 0;
      color: var(--pvtpdf-text);
      background: linear-gradient(135deg, #f8fbff 0%, #eef6ff 52%, #ecfeff 100%);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .pvtpdf-viewer--embed {
      min-height: 0;
      background: transparent;
    }

    .pvtpdf-shell {
      width: min(1180px, calc(100% - 28px));
      margin: 0 auto;
      padding: 18px 0 22px;
    }

    .pvtpdf-viewer--embed .pvtpdf-shell {
      width: 100%;
      padding: 0;
    }

    .pvtpdf-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 16px;
      border: 1px solid var(--pvtpdf-border);
      border-radius: 22px;
      background: rgba(255,255,255,.94);
      box-shadow: 0 18px 50px rgba(15,23,42,.08);
    }

    .pvtpdf-title-wrap { min-width: 0; }
    .pvtpdf-kicker {
      color: var(--pvtpdf-cyan);
      font-size: 11px;
      font-weight: 950;
      letter-spacing: .12em;
      text-transform: uppercase;
      margin: 0 0 4px;
    }

    .pvtpdf-title {
      margin: 0;
      font-size: clamp(18px, 3vw, 30px);
      line-height: 1.12;
      letter-spacing: -.04em;
      font-weight: 950;
      color: #0f172a;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 72vw;
    }

    .pvtpdf-meta {
      margin: 6px 0 0;
      color: var(--pvtpdf-muted);
      font-size: 12px;
      line-height: 1.45;
    }

    .pvtpdf-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
      flex: 0 0 auto;
    }

    .pvtpdf-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 38px;
      border: 1px solid var(--pvtpdf-border);
      border-radius: 999px;
      padding: 9px 13px;
      color: #0f172a;
      text-decoration: none;
      background: rgba(255,255,255,.86);
      font-weight: 900;
      font-size: 13px;
      cursor: pointer;
    }

    .pvtpdf-btn--primary {
      color: #fff;
      border-color: transparent;
      background: linear-gradient(135deg, var(--pvtpdf-blue), var(--pvtpdf-cyan));
      box-shadow: 0 12px 26px rgba(37,99,235,.18);
    }

    .pvtpdf-reader {
      margin-top: 14px;
      border-radius: 18px;
      overflow: hidden;
      border: 1px solid var(--pvtpdf-border);
      background: rgba(241,245,249,.96);
      box-shadow: 0 18px 50px rgba(15,23,42,.09);
    }

    .pvtpdf-viewer--embed .pvtpdf-reader {
      margin-top: 0;
      border: 0;
      border-radius: 0;
      box-shadow: none;
      background: transparent;
      overflow: visible;
    }

    .pvtpdf-toolbar {
      position: sticky;
      top: 0;
      z-index: 5;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding: 10px 12px;
      border-bottom: 1px solid var(--pvtpdf-border);
      background: rgba(255,255,255,.94);
      backdrop-filter: blur(12px);
    }

    .pvtpdf-viewer--embed .pvtpdf-toolbar {
      border: 1px solid var(--pvtpdf-border);
      border-radius: 16px 16px 0 0;
    }

    .pvtpdf-toolbar__group {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
    }

    .pvtpdf-page-info,
    .pvtpdf-zoom-info {
      color: var(--pvtpdf-muted);
      font-size: 12px;
      font-weight: 800;
    }

    .pvtpdf-pages {
      overflow: auto;
      padding: 18px 10px 26px;
      background:
        radial-gradient(circle at 10% 0%, rgba(20,184,166,.07), transparent 28%),
        radial-gradient(circle at 90% 10%, rgba(37,99,235,.08), transparent 30%),
        #f8fafc;
    }

    .pvtpdf-viewer--embed .pvtpdf-pages {
      padding: 12px 6px 16px;
    }

    .pvtpdf-page {
      position: relative;
      width: fit-content;
      margin: 0 auto 18px;
      border-radius: 10px;
      background: #fff;
      box-shadow: 0 12px 36px rgba(15,23,42,.16);
      overflow: hidden;
    }

    .pvtpdf-page canvas {
      display: block;
      width: 100%;
      height: auto;
      background: #fff;
    }

    .pvtpdf-page__label {
      position: absolute;
      left: 10px;
      top: 10px;
      z-index: 2;
      border-radius: 999px;
      padding: 5px 8px;
      background: rgba(15,23,42,.72);
      color: #fff;
      font-size: 11px;
      font-weight: 900;
      pointer-events: none;
    }

    .pvtpdf-state {
      padding: 22px;
      text-align: center;
      color: var(--pvtpdf-muted);
      font-size: 14px;
      line-height: 1.6;
    }

    .pvtpdf-state strong { color: #0f172a; }
    .pvtpdf-error { color: #b91c1c; }

    .pvtpdf-note {
      margin-top: 12px;
      border-left: 4px solid var(--pvtpdf-blue);
      background: rgba(239,246,255,.82);
      border-radius: 14px;
      padding: 12px 14px;
      color: #1e3a8a;
      line-height: 1.55;
      font-size: 13px;
    }

    @media (max-width: 720px) {
      .pvtpdf-shell { width: min(100% - 18px, 1180px); padding-top: 10px; }
      .pvtpdf-viewer--embed .pvtpdf-shell { width: 100%; padding: 0; }
      .pvtpdf-topbar { align-items: flex-start; flex-direction: column; border-radius: 18px; }
      .pvtpdf-title { max-width: 100%; white-space: normal; }
      .pvtpdf-actions { width: 100%; justify-content: flex-start; }
      .pvtpdf-reader { border-radius: 18px; }
      .pvtpdf-viewer--embed .pvtpdf-reader { border-radius: 0; }
      .pvtpdf-toolbar { align-items: flex-start; flex-direction: column; }
      .pvtpdf-pages { padding: 12px 6px 18px; }
    }
  </style>
</head>
<body class="pvtpdf-viewer<?= $isEmbed ? ' pvtpdf-viewer--embed' : '' ?>">
  <main class="pvtpdf-shell">
    <?php if (!$isEmbed): ?>
      <header class="pvtpdf-topbar">
        <div class="pvtpdf-title-wrap">
          <p class="pvtpdf-kicker">Internal PDF Viewer</p>
          <h1 class="pvtpdf-title"><?= self::e($title) ?></h1>
          <p class="pvtpdf-meta">
            Authorized preview · <?= self::e($now) ?>
          </p>
        </div>
        <nav class="pvtpdf-actions" aria-label="Aksi PDF">
          <a class="pvtpdf-btn" href="javascript:history.length>1?history.back():location.href='/'">Kembali</a>
        </nav>
      </header>
    <?php endif; ?>

    <section class="pvtpdf-reader" aria-label="PDF">
      <div class="pvtpdf-toolbar">
        <div class="pvtpdf-toolbar__group">
          <button class="pvtpdf-btn" type="button" data-pvtpdf-zoom-out>−</button>
          <span class="pvtpdf-zoom-info" data-pvtpdf-zoom-label>100%</span>
          <button class="pvtpdf-btn" type="button" data-pvtpdf-zoom-in>+</button>
          <button class="pvtpdf-btn" type="button" data-pvtpdf-fit>Fit width</button>
        </div>
        <div class="pvtpdf-toolbar__group">
          <span class="pvtpdf-page-info" data-pvtpdf-page-info>Memuat PDF...</span>
        </div>
      </div>
      <div class="pvtpdf-pages" data-pvtpdf-pages>
        <div class="pvtpdf-state" data-pvtpdf-state>Memuat PDF.js lokal...</div>
      </div>
    </section>

    <?php if (!$isEmbed): ?>
      <div class="pvtpdf-note">
        Dokumen ini hanya untuk penggunaan internal. Direct file path tidak ditampilkan. Akses dicatat per sesi admin/editor.
        Preview dirender memakai PDF.js lokal agar kompatibel dengan berbagai browser.
      </div>
    <?php endif; ?>
  </main>

  <script>
    window.PVTPDF_VIEWER_CONFIG = {
      pdfUrl: <?= json_encode($streamUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      workerUrl: <?= json_encode($pdfWorkerUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      title: <?= json_encode($title, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script src="<?= self::e($pdfJsUrl) ?>"></script>
  <script>
    (function () {
      'use strict';

      var config = window.PVTPDF_VIEWER_CONFIG || {};
      var pagesWrap = document.querySelector('[data-pvtpdf-pages]');
      var state = document.querySelector('[data-pvtpdf-state]');
      var pageInfo = document.querySelector('[data-pvtpdf-page-info]');
      var zoomLabel = document.querySelector('[data-pvtpdf-zoom-label]');
      var btnZoomIn = document.querySelector('[data-pvtpdf-zoom-in]');
      var btnZoomOut = document.querySelector('[data-pvtpdf-zoom-out]');
      var btnFit = document.querySelector('[data-pvtpdf-fit]');

      var pdfDoc = null;
      var zoom = 1;
      var baseScale = 1;
      var rendering = false;
      var rerenderRequested = false;

      function setState(html, isError) {
        if (!state) return;
        state.innerHTML = html;
        state.classList.toggle('pvtpdf-error', !!isError);
        state.style.display = html ? 'block' : 'none';
      }

      function updateZoomLabel() {
        if (zoomLabel) zoomLabel.textContent = Math.round(zoom * 100) + '%';
      }

      function updatePageInfo(done, total) {
        if (!pageInfo) return;
        if (!total) {
          pageInfo.textContent = 'Memuat PDF...';
          return;
        }
        pageInfo.textContent = done >= total ? (total + ' halaman') : ('Rendering ' + done + ' / ' + total);
      }

      function calculateScale(page) {
        var viewport = page.getViewport({ scale: 1 });
        var available = Math.max(280, (pagesWrap ? pagesWrap.clientWidth : window.innerWidth) - 28);
        baseScale = Math.min(2.2, Math.max(0.5, available / viewport.width));
        return baseScale * zoom;
      }

      function clearPages() {
        if (!pagesWrap) return;
        pagesWrap.querySelectorAll('.pvtpdf-page').forEach(function (node) { node.remove(); });
      }

      function renderAllPages() {
        if (!pdfDoc || !pagesWrap || rendering) {
          if (rendering) rerenderRequested = true;
          return;
        }

        rendering = true;
        rerenderRequested = false;
        clearPages();
        setState('', false);
        updateZoomLabel();
        updatePageInfo(0, pdfDoc.numPages);

        var chain = Promise.resolve();
        for (var pageNumber = 1; pageNumber <= pdfDoc.numPages; pageNumber++) {
          (function (num) {
            chain = chain.then(function () {
              return pdfDoc.getPage(num).then(function (page) {
                var scale = calculateScale(page);
                var viewport = page.getViewport({ scale: scale });
                var outputScale = Math.max(1, Math.min(2, window.devicePixelRatio || 1));

                var pageBox = document.createElement('article');
                pageBox.className = 'pvtpdf-page';
                pageBox.setAttribute('data-page', String(num));

                var label = document.createElement('div');
                label.className = 'pvtpdf-page__label';
                label.textContent = 'Hal. ' + num;
                pageBox.appendChild(label);

                var canvas = document.createElement('canvas');
                var context = canvas.getContext('2d', { alpha: false });

                canvas.width = Math.floor(viewport.width * outputScale);
                canvas.height = Math.floor(viewport.height * outputScale);
                canvas.style.width = Math.floor(viewport.width) + 'px';
                canvas.style.height = Math.floor(viewport.height) + 'px';

                pageBox.style.width = Math.floor(viewport.width) + 'px';
                pageBox.appendChild(canvas);
                pagesWrap.appendChild(pageBox);

                var transform = outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null;
                return page.render({ canvasContext: context, viewport: viewport, transform: transform }).promise.then(function () {
                  updatePageInfo(num, pdfDoc.numPages);
                });
              });
            });
          })(pageNumber);
        }

        chain.then(function () {
          rendering = false;
          pvtpdfSyncHeight();
          if (rerenderRequested) renderAllPages();
        }).catch(function (error) {
          rendering = false;
          pvtpdfSyncHeight();
          console.error(error);
          setState('<strong>Gagal render PDF.</strong><br>' + String(error && error.message ? error.message : error), true);
        });
      }

      function loadPdf() {
        if (!window.pdfjsLib) {
          setState('<strong>Local PDF.js not loaded.</strong><br>Make sure files exist at <code>/static/vendor/pdfjs/pdf.min.js</code> and <code>/static/vendor/pdfjs/pdf.worker.min.js</code>.', true);
          return;
        }

        window.pdfjsLib.GlobalWorkerOptions.workerSrc = config.workerUrl;
        setState('Fetching PDF document...', false);

        var loadingTask = window.pdfjsLib.getDocument({
          url: config.pdfUrl,
          withCredentials: true,
          disableAutoFetch: false,
          disableStream: false,
          disableRange: false
        });

        loadingTask.promise.then(function (doc) {
          pdfDoc = doc;
          setState('', false);
          renderAllPages();
        }).catch(function (error) {
          console.error(error);
          setState('<strong>Gagal memuat PDF.</strong><br>' + String(error && error.message ? error.message : error), true);
        });
      }

      if (btnZoomIn) {
        btnZoomIn.addEventListener('click', function () {
          zoom = Math.min(2.5, zoom + 0.15);
          renderAllPages();
        });
      }

      if (btnZoomOut) {
        btnZoomOut.addEventListener('click', function () {
          zoom = Math.max(0.55, zoom - 0.15);
          renderAllPages();
        });
      }

      if (btnFit) {
        btnFit.addEventListener('click', function () {
          zoom = 1;
          renderAllPages();
        });
      }

      var resizeTimer = null;
      window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
          if (pdfDoc) renderAllPages();
        }, 350);
      });

      loadPdf();

      function pvtpdfSyncHeight() {
        if (window.parent === window || rendering) return;
        var h = document.documentElement.scrollHeight;
        parent.postMessage({ type: 'pvtpdf-resize', height: h }, '*');
      }

      if (window.parent !== window) {
        var ro = new ResizeObserver(pvtpdfSyncHeight);
        ro.observe(document.documentElement);
        setTimeout(function () { if (!rendering) pvtpdfSyncHeight(); }, 2000);
      }
    })();
  </script>
</body>
</html>
        <?php
        exit;
    }
}
