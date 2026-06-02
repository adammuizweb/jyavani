<?php
declare(strict_types=1);

// /adiwira/admin/settings/site.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_admin($pdo, false);

if (!function_exists('site_settings_valid_host')) {
    function site_settings_valid_host(string $host): bool
    {
        $host = trim($host);
        if ($host === '') {
            return false;
        }

        // jangan izinkan scheme / path / query
        if (preg_match('~^[a-z][a-z0-9+\-.]*://~i', $host)) {
            return false;
        }
        if (strpbrk($host, "/?# \t\r\n") !== false) {
            return false;
        }

        // localhost, domain, atau IPv4 + optional :port
        return (bool)preg_match(
            '/^(localhost|(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)*)|(?:\d{1,3}(?:\.\d{1,3}){3}))(?::\d{1,5})?$/i',
            $host
        );
    }
}

$errors = [];
$success_msg = '';

// dukung query toast lama bila masih ada route lama
$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

// dukung flash session bila helper tersedia
if (function_exists('adiwira_flash_pull')) {
    $flash = adiwira_flash_pull();
    if (is_array($flash)) {
        foreach ($flash as $f) {
            $type = isset($f['type']) ? (string)$f['type'] : 'info';
            $text = isset($f['text']) ? (string)$f['text'] : (isset($f['message']) ? (string)$f['message'] : '');
            if ($text !== '') {
                $page_toasts[] = [
                    'type' => $type,
                    'message' => $text,
                ];
            }
        }
    }
}

$current_title = function_exists('settings_get')
    ? (settings_get($pdo, 'site_title', 'Pre Univ APU') ?? 'Pre Univ APU')
    : 'Pre Univ APU';

$current_host = function_exists('settings_get')
    ? (settings_get($pdo, 'site_host', 'pre-univapu.kmb.ac.id') ?? 'pre-univapu.kmb.ac.id')
    : 'pre-univapu.kmb.ac.id';

$base = ADMIN_BASE_PATH;
$self_url = $base . '/?page=admin/settings/site';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = 'CSRF token tidak valid.';
    }

    $site_title = trim((string)($_POST['site_title'] ?? ''));
    $site_host  = trim((string)($_POST['site_host'] ?? ''));

    // pertahankan nilai input saat validasi gagal
    $current_title = $site_title;
    $current_host  = $site_host;

    if ($site_title === '') {
        $errors[] = 'Site title tidak boleh kosong.';
    }

    if ($site_host === '') {
        $errors[] = 'Site host tidak boleh kosong.';
    } elseif (!site_settings_valid_host($site_host)) {
        $errors[] = 'Format host tidak valid. Contoh: pre-univapu.kmb.ac.id atau localhost:8000';
    }

    if (!$errors) {
        $ok1 = settings_set($pdo, 'site_title', $site_title, 1);
        $ok2 = settings_set($pdo, 'site_host',  $site_host,  1);

        if ($ok1 && $ok2) {
            if (function_exists('adiwira_redirect_with_flash')) {
                adiwira_redirect_with_flash($self_url, 'success', 'Pengaturan website berhasil disimpan.');
                exit;
            }

            $success_msg = 'Pengaturan website berhasil disimpan.';
        } else {
            $errors[] = 'Gagal menyimpan pengaturan.';
        }
    }
}

// fallback inline bila sistem toast tidak tersedia
$show_inline_success = ($success_msg !== '' && !function_exists('adiwira_bootstrap_toasts_script'));
$show_inline_errors  = (!empty($errors) && !function_exists('adiwira_bootstrap_toasts_script'));
?>

<section class="adam-card" style="max-width:820px;margin:18px auto;">
  <h2>Pengaturan Website</h2>

  <?php if ($show_inline_success): ?>
    <div class="adam-success" style="margin:10px 0;">
      ✅ <?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <?php if ($show_inline_errors): ?>
    <div class="adam-error" style="margin:10px 0;">
      <ul style="margin:0;padding-left:18px">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" novalidate id="site-settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <label style="display:block;margin:.6rem 0;">
      Site Title (default)
      <input type="text" name="site_title"
        value="<?= htmlspecialchars($current_title, ENT_QUOTES, 'UTF-8') ?>"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;">
    </label>

    <label style="display:block;margin:.6rem 0;">
      Site Host (fallback / canonical default)
      <input type="text" name="site_host"
        value="<?= htmlspecialchars($current_host, ENT_QUOTES, 'UTF-8') ?>"
        placeholder="jyavani.com atau localhost:8000"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;">
    </label>

    <div style="margin-top:14px;display:flex;gap:10px;align-items:center;">
      <button type="submit" class="adam-button">Simpan</button>
      <a class="adam-cancle" href="<?= ADMIN_BASE_PATH ?>/?page=admin/settings/index">Kembali</a>
    </div>
  </form>
</section>

<?php
if (function_exists('adiwira_bootstrap_toasts_script')) {
    $toast_items = $page_toasts;

    if ($success_msg !== '') {
        $toast_items[] = [
            'type' => 'success',
            'message' => $success_msg,
        ];
    }

    foreach ($errors as $msg) {
        $toast_items[] = [
            'type' => 'error',
            'message' => (string)$msg,
        ];
    }

    if (!empty($toast_items)) {
        echo adiwira_bootstrap_toasts_script($toast_items);
    }
}
?>