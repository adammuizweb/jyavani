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

$current_posts_permalink = function_exists('get_permalink_structure')
    ? get_permalink_structure($pdo, 'post')
    : '/%slug%/';

$current_pages_permalink = function_exists('get_permalink_structure')
    ? get_permalink_structure($pdo, 'page')
    : '/%slug%/';

$current_posts_list_path = function_exists('get_posts_list_path')
    ? get_posts_list_path($pdo)
    : 'artikel';

$current_pages_list_path = function_exists('get_pages_list_path')
    ? get_pages_list_path($pdo)
    : 'halaman';

$current_category_path = function_exists('get_category_path')
    ? get_category_path($pdo)
    : 'category';

$current_site_language = settings_get($pdo, 'site_language', 'en') ?? 'en';

$base = ADMIN_BASE_PATH;
$self_url = $base . '/?page=admin/settings/site';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    }

    $site_title = trim((string)($_POST['site_title'] ?? ''));
    $site_host  = trim((string)($_POST['site_host'] ?? ''));
    $posts_permalink = trim((string)($_POST['permalink_posts'] ?? '/%slug%/'));
    $pages_permalink = trim((string)($_POST['permalink_pages'] ?? '/%slug%/'));
    $posts_list_path = trim((string)($_POST['posts_list_path'] ?? 'artikel'));
    $pages_list_path = trim((string)($_POST['pages_list_path'] ?? 'halaman'));
    $category_path = trim((string)($_POST['category_path'] ?? 'category'));
    $current_site_language = trim((string)($_POST['site_language'] ?? 'en'));

    // pertahankan nilai input saat validasi gagal
    $current_title = $site_title;
    $current_host  = $site_host;
    $current_posts_permalink = $posts_permalink;
    $current_pages_permalink = $pages_permalink;
    $current_posts_list_path = $posts_list_path;
    $current_pages_list_path = $pages_list_path;
    $current_category_path = $category_path;

    if ($site_title === '') {
        $errors[] = __('Site title cannot be empty.');
    }

    if ($site_host === '') {
        $errors[] = __('Site host cannot be empty.');
    } elseif (!site_settings_valid_host($site_host)) {
        $errors[] = __('Invalid host format. Example: jyavani.com or localhost:8000');
    }

    if ($posts_permalink === '') {
        $errors[] = __('Post permalink cannot be empty.');
    } elseif (!function_exists('validate_permalink_structure') || !validate_permalink_structure($posts_permalink)) {
        $errors[] = __('Post permalink must contain %slug% and may only use tokens: %year%, %monthnum%, %day%, %slug%, %cat%.');
    }

    if ($pages_permalink === '') {
        $errors[] = __('Page permalink cannot be empty.');
    } elseif (!function_exists('validate_permalink_structure') || !validate_permalink_structure($pages_permalink)) {
        $errors[] = __('Page permalink must contain %slug% and may only use tokens: %year%, %monthnum%, %day%, %slug%, %cat%.');
    }

    if ($posts_list_path !== '' && !preg_match('/^[a-z0-9_\/-]+$/', $posts_list_path)) {
        $errors[] = __('Posts list path may only contain lowercase letters, numbers, slashes, underscores, and hyphens.');
    }

    if ($pages_list_path !== '' && !preg_match('/^[a-z0-9_\/-]+$/', $pages_list_path)) {
        $errors[] = __('Pages list path may only contain lowercase letters, numbers, slashes, underscores, and hyphens.');
    }

    if ($category_path !== '' && !preg_match('/^[a-z0-9_\/-]+$/', $category_path)) {
        $errors[] = __('Category path may only contain lowercase letters, numbers, slashes, underscores, and hyphens.');
    }

    if (!$errors) {
        $ok1 = settings_set($pdo, 'site_title', $site_title, 1);
        $ok2 = settings_set($pdo, 'site_host',  $site_host,  1);
        $ok3 = function_exists('set_permalink_structure')
            ? (set_permalink_structure($pdo, 'post', $posts_permalink) && set_permalink_structure($pdo, 'page', $pages_permalink))
            : true;
        $ok4 = settings_set($pdo, 'posts_list_path', $posts_list_path, 1);
        $ok5 = settings_set($pdo, 'pages_list_path', $pages_list_path, 1);
        $ok6 = settings_set($pdo, 'category_path', $category_path, 1);
        $ok7 = settings_set($pdo, 'site_language', $current_site_language, 1);

        if ($ok1 && $ok2 && $ok3 && $ok4 && $ok5 && $ok6 && $ok7) {
            if (function_exists('adiwira_redirect_with_flash')) {
                adiwira_redirect_with_flash($self_url, 'success', __('Site settings saved successfully.'));
                exit;
            }

            $success_msg = __('Site settings saved successfully.');
        } else {
            $errors[] = __('Failed to save settings.');
        }
    }
}

// fallback inline bila sistem toast tidak tersedia
$show_inline_success = ($success_msg !== '' && !function_exists('adiwira_bootstrap_toasts_script'));
$show_inline_errors  = (!empty($errors) && !function_exists('adiwira_bootstrap_toasts_script'));
?>

<section class="adam-card" style="max-width:820px;margin:18px auto;">
  <h2><?=_e('Site Settings')?></h2>

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
      <?=_e('Site Title (default)')?>
      <input type="text" name="site_title"
        value="<?= htmlspecialchars($current_title, ENT_QUOTES, 'UTF-8') ?>"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;">
    </label>

    <label style="display:block;margin:.6rem 0;">
      <?=_e('Site Host (fallback / canonical default)')?>
      <input type="text" name="site_host"
        value="<?= htmlspecialchars($current_host, ENT_QUOTES, 'UTF-8') ?>"
        placeholder="<?=__('jyavani.com or localhost:8000')?>"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;">
    </label>

    <hr style="margin:1.4rem 0;border:none;border-top:1px solid #eee">

    <h3 style="margin:0 0 .6rem;"><?=_e('Custom Permalink')?></h3>

    <details style="margin:0 0 1rem;font-size:.85rem;color:#555;background:#f9f9fb;border-radius:8px;padding:.4rem .8rem;cursor:pointer;">
      <summary style="font-weight:600;color:#333;outline:none;"><?=_e('How permalinks work? (click for details)')?></summary>
      <div style="margin-top:.6rem;line-height:1.7;padding-left:.4rem;">

        <p><strong><?=_e('Available tokens for Posts:')?></strong></p>
        <table style="width:100%;border-collapse:collapse;margin:.3rem 0 .8rem;">
          <tr><td style="padding:2px 8px;font-family:monospace;">%slug%</td><td style="padding:2px 8px;"><?=_e('The post\'s own slug (required)')?></td></tr>
          <tr><td style="padding:2px 8px;font-family:monospace;">%year%</td><td style="padding:2px 8px;"><?=_e('Publication year (4 digits, e.g. 2026)')?></td></tr>
          <tr><td style="padding:2px 8px;font-family:monospace;">%monthnum%</td><td style="padding:2px 8px;"><?=_e('Publication month (2 digits, e.g. 06)')?></td></tr>
          <tr><td style="padding:2px 8px;font-family:monospace;">%day%</td><td style="padding:2px 8px;"><?=_e('Publication day (2 digits, e.g. 02)')?></td></tr>
          <tr><td style="padding:2px 8px;font-family:monospace;">%cat%</td><td style="padding:2px 8px;"><?=_e('Slug of the <strong>parent category</strong> (top-level ancestor, not full child path). Empty if no category.')?></td></tr>
        </table>

        <p><strong><?=_e('Example Post structures:')?></strong></p>
        <table style="width:100%;border-collapse:collapse;margin:.3rem 0 .8rem;">
          <tr><td style="padding:2px 8px;font-family:monospace;">/%slug%/</td><td style="padding:2px 8px;"><?=_e('Standard')?> &mdash; <code>/los-geht/</code></td></tr>
          <tr><td style="padding:2px 8px;font-family:monospace;">/%year%/%slug%/</td><td style="padding:2px 8px;"><?=_e('Date-based')?> &mdash; <code>/2026/los-geht/</code></td></tr>
          <tr><td style="padding:2px 8px;font-family:monospace;">/%year%/%monthnum%/%slug%/</td><td style="padding:2px 8px;"><?=_e('Date + month')?> &mdash; <code>/2026/06/los-geht/</code></td></tr>
          <tr><td style="padding:2px 8px;font-family:monospace;">/%cat%/%slug%/</td><td style="padding:2px 8px;"><?=_e('Category + slug')?> &mdash; <code>/berita/los-geht/</code></td></tr>
          <tr><td style="padding:2px 8px;font-family:monospace;">/%cat%/%year%/%slug%/</td><td style="padding:2px 8px;"><?=_e('Category + date')?> &mdash; <code>/berita/2026/los-geht/</code></td></tr>
          <tr><td style="padding:2px 8px;font-family:monospace;">/blog/%slug%/</td><td style="padding:2px 8px;"><?=_e('Static prefix')?> &mdash; <code>/blog/los-geht/</code></td></tr>
        </table>

        <p><strong><?=_e('Important notes:')?></strong></p>
        <ul style="margin:.3rem 0 .8rem;padding-left:1.2rem;">
          <li><?=_e('Structure <strong>must start with <code>/</code></strong> and contain <code>%slug%</code>.')?></li>
          <li><?=_e('If the structure contains <code>%year%</code>, the path with a year in the first segment (e.g. <code>/2026/</code>) will be treated as <strong>archive year</strong>, not a category.')?></li>
          <li><?=_e('The <code>%cat%</code> token is for Posts only, not Pages.')?></li>
          <li><?=_e('If <code>%cat%</code> is used but the post has no category, the token will be removed (URL becomes <code>//slug/</code> &rarr; normalized to <code>/slug/</code>).')?></li>
          <li><?=_e('If there is a slug conflict between a post and a category, <strong>the post wins</strong> (post is always checked first).')?></li>
        </ul>

        <hr style="border:none;border-top:1px solid #e0e0e0;margin:.6rem 0;">

        <p><strong><?=_e('Post &amp; Page List:')?></strong></p>
        <p style="margin:.2rem 0 .4rem;"><?=_e('Path prefix for listing pages (index). If left empty, listing pages are not available (404). Example: <code>artikel</code> &rarr; <code>/artikel/</code>, <code>blog</code> &rarr; <code>/blog/</code>.')?></p>

        <hr style="border:none;border-top:1px solid #e0e0e0;margin:.6rem 0;">

        <p><strong><?=_e('Category path:')?></strong></p>
        <p style="margin:.2rem 0 .4rem;">
          <?=_e('Prefix for all category pages. Default <code>category</code> &rarr; <code>/category/slug/</code>. If left empty, categories are accessible <strong>directly at root</strong> &rarr; <code>/slug/</code> (without prefix). The category index (listing all categories) is not available when the prefix is empty. To configure this together with the <code>%cat%</code> token in posts, ensure the category path is empty so post URLs like <code>/category-slug/post-slug/</code> can work.')?>
        </p>

      </div>
    </details>

    <label style="display:block;margin:.6rem 0;">
      <?=_e('Post permalink structure')?>
      <input type="text" name="permalink_posts"
        value="<?= htmlspecialchars($current_posts_permalink, ENT_QUOTES, 'UTF-8') ?>"
        placeholder="/%slug%/"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;font-family:monospace;">
    </label>

    <label style="display:block;margin:.6rem 0;">
      <?=_e('Page permalink structure')?>
      <input type="text" name="permalink_pages"
        value="<?= htmlspecialchars($current_pages_permalink, ENT_QUOTES, 'UTF-8') ?>"
        placeholder="/%slug%/"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;font-family:monospace;">
    </label>

    <hr style="margin:1.4rem 0;border:none;border-top:1px solid #eee">

    <h3 style="margin:0 0 .6rem;"><?=_e('Post & Page List')?></h3>
    <p style="color:#666;font-size:.85rem;margin:0 0 1rem;">
      <?=_e('Path prefix for the post and page listing pages. Leave empty to disable.')?>
    </p>

    <label style="display:block;margin:.6rem 0;">
      <?=_e('Posts list path')?>
      <input type="text" name="posts_list_path"
        value="<?= htmlspecialchars($current_posts_list_path, ENT_QUOTES, 'UTF-8') ?>"
        placeholder="<?=__('artikel')?>"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;font-family:monospace;">
      <span style="display:block;font-size:.8rem;color:#888;margin-top:.25rem;"><?=_e('Leave empty to disable the posts list page.')?></span>
    </label>

    <label style="display:block;margin:.6rem 0;">
      <?=_e('Pages list path')?>
      <input type="text" name="pages_list_path"
        value="<?= htmlspecialchars($current_pages_list_path, ENT_QUOTES, 'UTF-8') ?>"
        placeholder="<?=__('halaman')?>"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;font-family:monospace;">
      <span style="display:block;font-size:.8rem;color:#888;margin-top:.25rem;"><?=_e('Leave empty to disable the pages list page.')?></span>
    </label>

    <hr style="margin:1.4rem 0;border:none;border-top:1px solid #eee">

    <h3 style="margin:0 0 .6rem;"><?=_e('Categories')?></h3>

    <label style="display:block;margin:.6rem 0;">
      <?=_e('Category path')?>
      <input type="text" name="category_path"
        value="<?= htmlspecialchars($current_category_path, ENT_QUOTES, 'UTF-8') ?>"
        placeholder="category"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;font-family:monospace;">
      <span style="display:block;font-size:.8rem;color:#888;margin-top:.25rem;"><?=_e('Leave empty to disable categories entirely.')?></span>
    </label>

    <hr style="margin:1.4rem 0;border:none;border-top:1px solid #eee">

    <h3 style="margin:0 0 .6rem;"><?=_e('Language')?></h3>

    <label style="display:block;margin:.6rem 0;">
      <?=_e('Site Language')?>
      <select name="site_language" style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;">
        <option value="en" <?=$current_site_language==='en'?'selected':''?>><?=_e('English')?></option>
        <option value="id" <?=$current_site_language==='id'?'selected':''?>><?=_e('Indonesian')?></option>
        <option value="de" <?=$current_site_language==='de'?'selected':''?>><?=_e('German')?></option>
      </select>
    </label>

    <div style="margin-top:14px;display:flex;gap:10px;align-items:center;">
      <button type="submit" class="adam-button"><?=_e('Save')?></button>
      <a class="adam-cancle" href="<?= ADMIN_BASE_PATH ?>/?page=admin/settings/index"><?=_e('Back')?></a>
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