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

$current_favicon_url = settings_get($pdo, 'favicon_url', '') ?? '';

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
    $favicon_url = trim((string)($_POST['favicon_url'] ?? ''));

    // pertahankan nilai input saat validasi gagal
    $current_title = $site_title;
    $current_host  = $site_host;
    $current_posts_permalink = $posts_permalink;
    $current_pages_permalink = $pages_permalink;
    $current_posts_list_path = $posts_list_path;
    $current_pages_list_path = $pages_list_path;
    $current_category_path = $category_path;
    $current_favicon_url = $favicon_url;

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
        $enable_custom_meta = (string)($_POST['enable_custom_meta'] ?? '0') === '1' ? '1' : '0';
        $ok7  = settings_set($pdo, 'site_language', $current_site_language, 1);
        $ok8  = settings_set($pdo, 'favicon_url', $favicon_url, 1);
        $ok9  = settings_set($pdo, 'enable_custom_meta', $enable_custom_meta, 1);

        if ($ok1 && $ok2 && $ok3 && $ok4 && $ok5 && $ok6 && $ok7 && $ok8 && $ok9) {
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

<section class="adam-card settings-card">
  <h2 class="edit-heading"><?=_e('Site Settings')?></h2>

  <?php if ($show_inline_success): ?>
    <div class="adam-success" style="margin:10px 0;">
      <?= svg_ico('circle-check') ?> <?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <?php if ($show_inline_errors): ?>
    <div class="adam-error" style="margin:10px 0;">
      <ul>
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" novalidate id="site-settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <!-- General -->
    <div class="settings-section settings-section--general" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('globe') ?> <?=_e('General')?>
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">

        <div class="form-group">
          <label for="site_title"><?=_e('Site Title (default)')?></label>
          <input type="text" name="site_title" id="site_title"
            value="<?= htmlspecialchars($current_title, ENT_QUOTES, 'UTF-8') ?>"
            class="inp inp-w100">
        </div>

        <div class="form-group">
          <label for="site_host"><?=_e('Site Host (fallback / canonical default)')?></label>
          <input type="text" name="site_host" id="site_host"
            value="<?= htmlspecialchars($current_host, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="<?=__('jyavani.com or localhost:8000')?>"
            class="inp inp-w100">
        </div>

      </div>
    </div>

    <!-- Custom Permalink -->
    <div class="settings-section settings-section--permalink" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('link') ?> <?=_e('Custom Permalink')?>
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">

        <details class="settings-details">
          <summary><?=_e('How permalinks work? (click for details)')?></summary>
          <div class="inner">

            <p><strong><?=_e('Available tokens for Posts:')?></strong></p>
            <table class="settings-table">
              <tr><td>%slug%</td><td><?=_e('The post\'s own slug (required)')?></td></tr>
              <tr><td>%year%</td><td><?=_e('Publication year (4 digits, e.g. 2026)')?></td></tr>
              <tr><td>%monthnum%</td><td><?=_e('Publication month (2 digits, e.g. 06)')?></td></tr>
              <tr><td>%day%</td><td><?=_e('Publication day (2 digits, e.g. 02)')?></td></tr>
              <tr><td>%cat%</td><td><?=_e('Slug of the <strong>parent category</strong> (top-level ancestor, not full child path). Empty if no category.')?></td></tr>
            </table>

            <p><strong><?=_e('Example Post structures:')?></strong></p>
            <table class="settings-table">
              <tr><td>/%slug%/</td><td><?=_e('Standard')?> &mdash; <code>/los-geht/</code></td></tr>
              <tr><td>/%year%/%slug%/</td><td><?=_e('Date-based')?> &mdash; <code>/2026/los-geht/</code></td></tr>
              <tr><td>/%year%/%monthnum%/%slug%/</td><td><?=_e('Date + month')?> &mdash; <code>/2026/06/los-geht/</code></td></tr>
              <tr><td>/%cat%/%slug%/</td><td><?=_e('Category + slug')?> &mdash; <code>/berita/los-geht/</code></td></tr>
              <tr><td>/%cat%/%year%/%slug%/</td><td><?=_e('Category + date')?> &mdash; <code>/berita/2026/los-geht/</code></td></tr>
              <tr><td>/blog/%slug%/</td><td><?=_e('Static prefix')?> &mdash; <code>/blog/los-geht/</code></td></tr>
            </table>

            <p><strong><?=_e('Important notes:')?></strong></p>
            <ul>
              <li><?=_e('Structure <strong>must start with <code>/</code></strong> and contain <code>%slug%</code>.')?></li>
              <li><?=_e('If the structure contains <code>%year%</code>, the path with a year in the first segment (e.g. <code>/2026/</code>) will be treated as <strong>archive year</strong>, not a category.')?></li>
              <li><?=_e('The <code>%cat%</code> token is for Posts only, not Pages.')?></li>
              <li><?=_e('If <code>%cat%</code> is used but the post has no category, the token will be removed (URL becomes <code>//slug/</code> &rarr; normalized to <code>/slug/</code>).')?></li>
              <li><?=_e('If there is a slug conflict between a post and a category, <strong>the post wins</strong> (post is always checked first).')?></li>
            </ul>

            <hr class="settings-hr" style="margin:.6rem 0;">

            <p><strong><?=_e('Post &amp; Page List:')?></strong></p>
            <p><?=_e('Path prefix for listing pages (index). If left empty, listing pages are not available (404). Example: <code>artikel</code> &rarr; <code>/artikel/</code>, <code>blog</code> &rarr; <code>/blog/</code>.')?></p>

            <hr class="settings-hr" style="margin:.6rem 0;">

            <p><strong><?=_e('Category path:')?></strong></p>
            <p>
              <?=_e('Prefix for all category pages. Default <code>category</code> &rarr; <code>/category/slug/</code>. If left empty, categories are accessible <strong>directly at root</strong> &rarr; <code>/slug/</code> (without prefix). The category index (listing all categories) is not available when the prefix is empty. To configure this together with the <code>%cat%</code> token in posts, ensure the category path is empty so post URLs like <code>/category-slug/post-slug/</code> can work.')?>
            </p>

          </div>
        </details>

        <div class="form-group">
          <label for="permalink_posts"><?=_e('Post permalink structure')?></label>
          <div class="permalink-builder">
            <span class="permalink-prefix">https://<?= htmlspecialchars($current_host, ENT_QUOTES, 'UTF-8') ?>/</span>
            <input type="text" name="permalink_posts" id="permalink_posts"
              value="<?= htmlspecialchars($current_posts_permalink, ENT_QUOTES, 'UTF-8') ?>"
              placeholder="/%slug%/"
              class="permalink-input">
          </div>
          <div class="permalink-example" id="permalink-posts-example">
            → https://<?= htmlspecialchars($current_host, ENT_QUOTES, 'UTF-8') ?><span class="example-path"><?= htmlspecialchars($current_posts_permalink === '/%slug%/' ? '/sample-post/' : str_replace('%slug%', 'sample-post', $current_posts_permalink), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        </div>

        <div class="form-group">
          <label for="permalink_pages"><?=_e('Page permalink structure')?></label>
          <div class="permalink-builder">
            <span class="permalink-prefix">https://<?= htmlspecialchars($current_host, ENT_QUOTES, 'UTF-8') ?>/</span>
            <input type="text" name="permalink_pages" id="permalink_pages"
              value="<?= htmlspecialchars($current_pages_permalink, ENT_QUOTES, 'UTF-8') ?>"
              placeholder="/%slug%/"
              class="permalink-input">
          </div>
          <div class="permalink-example" id="permalink-pages-example">
            → https://<?= htmlspecialchars($current_host, ENT_QUOTES, 'UTF-8') ?><span class="example-path"><?= htmlspecialchars($current_pages_permalink === '/%slug%/' ? '/sample-page/' : str_replace('%slug%', 'sample-page', $current_pages_permalink), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        </div>

      </div>
    </div>

    <!-- Post & Page List -->
    <div class="settings-section settings-section--postlist" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('file-text') ?> <?=_e('Post & Page List')?>
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">

        <p class="settings-desc">
          <?=_e('Path prefix for the post and page listing pages. Leave empty to disable.')?>
        </p>

        <div class="form-group">
          <label for="posts_list_path"><?=_e('Posts list path')?></label>
          <input type="text" name="posts_list_path" id="posts_list_path"
            value="<?= htmlspecialchars($current_posts_list_path, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="<?=__('artikel')?>"
            class="inp inp-w100" style="font-family:monospace;">
          <span class="field-note"><?=_e('Leave empty to disable the posts list page.')?></span>
        </div>

        <div class="form-group">
          <label for="pages_list_path"><?=_e('Pages list path')?></label>
          <input type="text" name="pages_list_path" id="pages_list_path"
            value="<?= htmlspecialchars($current_pages_list_path, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="<?=__('halaman')?>"
            class="inp inp-w100" style="font-family:monospace;">
          <span class="field-note"><?=_e('Leave empty to disable the pages list page.')?></span>
        </div>

      </div>
    </div>

    <!-- Categories -->
    <div class="settings-section settings-section--categories" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('folder') ?> <?=_e('Categories')?>
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">

        <div class="form-group">
          <label for="category_path"><?=_e('Category path')?></label>
          <div class="permalink-builder">
            <span class="permalink-prefix">https://<?= htmlspecialchars($current_host, ENT_QUOTES, 'UTF-8') ?>/</span>
            <input type="text" name="category_path" id="category_path"
              value="<?= htmlspecialchars($current_category_path, ENT_QUOTES, 'UTF-8') ?>"
              placeholder="category"
              class="permalink-input">
            <span class="permalink-suffix">/slug/</span>
          </div>
          <span class="field-note"><?=_e('Leave empty to disable categories entirely.')?></span>
        </div>

      </div>
    </div>

    <!-- Favicon -->
    <div class="settings-section settings-section--favicon" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('image') ?> <?=_e('Favicon')?>
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">

        <div class="form-group">
          <label for="favicon-url-input"><?=_e('Custom Favicon URL')?></label>
          <div class="thumb-row">
            <input type="text" name="favicon_url" id="favicon-url-input"
              value="<?= htmlspecialchars($current_favicon_url, ENT_QUOTES, 'UTF-8') ?>"
              placeholder="/static/img/favicon/favicon.ico"
              class="inp" style="flex:1;min-width:140px;">
            <button type="button" id="btn-favicon-gallery" class="thumb-gallery-btn">
              <?= svg_ico('image', '', ['style' => 'width:14px;height:14px']) ?> <?=_e('Gallery')?>
            </button>
            <button type="button" id="favicon-clear" class="thumb-clear-btn">&times;</button>
          </div>
          <div id="favicon-preview" style="margin-top:.6rem;">
            <?php if ($current_favicon_url !== ''): ?>
              <img src="<?= htmlspecialchars($current_favicon_url, ENT_QUOTES, 'UTF-8') ?>" alt="favicon preview" class="settings-favicon-preview">
            <?php endif; ?>
          </div>
          <span class="field-note"><?=_e('Recommended: .ico, .png (32×32 or larger), or .svg. Leave empty to use the default favicon.')?></span>
        </div>

      </div>
    </div>

    <!-- Meta Tags -->
    <div class="settings-section settings-section--metatags" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('tag') ?> <?=_e('Meta Tags')?>
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">
        <div class="form-group">
          <label for="enable_custom_meta">
            <input type="hidden" name="enable_custom_meta" value="0">
            <input type="checkbox" name="enable_custom_meta" id="enable_custom_meta" value="1"<?= settings_get($pdo, 'enable_custom_meta', '0') === '1' ? ' checked' : '' ?>>
            <?=_e('Enable custom meta description per post/page')?>
          </label>
          <span class="field-note"><?=_e('When enabled, editors can set a custom meta description per post/page. Falls back to auto-generated excerpt (160 chars) if empty.')?></span>
        </div>
      </div>
    </div>

    <!-- Language -->
    <div class="settings-section settings-section--language" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('book-open') ?> <?=_e('Language')?>
        <span class="chevron">▸</span>
      </button>
      <div class="settings-section-body">

        <div class="form-group">
          <label for="site_language"><?=_e('Site Language')?></label>
          <select name="site_language" id="site_language" class="inp inp-w100">
            <option value="en" <?=$current_site_language==='en'?'selected':''?>><?=_e('English')?></option>
            <option value="id" <?=$current_site_language==='id'?'selected':''?>><?=_e('Indonesian')?></option>
            <option value="de" <?=$current_site_language==='de'?'selected':''?>><?=_e('German')?></option>
          </select>
        </div>

      </div>
    </div>

    <div class="btn-row" style="margin-top:14px;margin-bottom:0;">
      <button type="submit" class="adam-button"><?= svg_ico('save', '', ['style' => 'width:15px;height:15px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Save')?></button>
      <a class="adam-cancle" href="<?= ADMIN_BASE_PATH ?>/?page=admin/settings/index"><?=_e('Back')?></a>
    </div>
  </form>
</section>

<script src="/static/js/add/modal-helpers.js"></script>
<script src="/static/js/add/media-selector.js"></script>
<script>
(function(){
  var input = document.getElementById('favicon-url-input');
  var preview = document.getElementById('favicon-preview');
  var clearBtn = document.getElementById('favicon-clear');
  var galleryBtn = document.getElementById('btn-favicon-gallery');

  if (galleryBtn && input) {
    galleryBtn.addEventListener('click', function(){
      openMediaSelector({ url: ADMIN_PATH + '/admin/modal_img/list_modal.php?embedded=1' })
        .then(function(detail){
          var m = (typeof normalizeMedia === 'function')
            ? normalizeMedia(detail)
            : (detail || null);
          if (!m || !m.url) return;
          input.value = m.url;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
          if (preview) {
            preview.innerHTML = '<img src="' + m.url.replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '" alt="favicon preview" class="settings-favicon-preview">';
          }
        });
    });
  }

  if (clearBtn && input) {
    clearBtn.addEventListener('click', function(){
      input.value = '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      if (preview) preview.innerHTML = '';
    });
  }
})();
</script>
<script>
(function(){
  function updatePermalinkExample(inputId, exampleId) {
    var input = document.getElementById(inputId);
    var example = document.getElementById(exampleId);
    if (!input || !example) return;
    var pathSpan = example.querySelector('.example-path');

    function refresh() {
      var val = input.value || '';
      var exampleUrl = val.replace(/%slug%/g, inputId === 'permalink_posts' ? 'sample-post' : 'sample-page');
      if (pathSpan) pathSpan.textContent = exampleUrl;
    }

    input.addEventListener('input', refresh);
    input.addEventListener('change', refresh);
  }

  updatePermalinkExample('permalink_posts', 'permalink-posts-example');
  updatePermalinkExample('permalink_pages', 'permalink-pages-example');
})();
</script>
<script>
(function(){
  var sections = document.querySelectorAll('.settings-section[data-open]');
  Array.prototype.forEach.call(sections, function(section){
    var toggle = section.querySelector('.settings-section-toggle');
    var body = section.querySelector('.settings-section-body');
    if (!toggle || !body) return;

    var isOpen = section.getAttribute('data-open') === '1';
    body.hidden = !isOpen;
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

    toggle.addEventListener('click', function(){
      var open = section.getAttribute('data-open') === '1';
      var newOpen = open ? '0' : '1';
      section.setAttribute('data-open', newOpen);
      body.hidden = !(newOpen === '1');
      toggle.setAttribute('aria-expanded', newOpen === '1' ? 'true' : 'false');
    });
  });
})();
</script>
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