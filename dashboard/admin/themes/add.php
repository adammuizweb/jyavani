<?php
declare(strict_types=1);

// /adiwira/admin/themes/add.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$user_id, $user_role] = adiwira_require_editorial($pdo, false);

if (function_exists('ensure_session_started')) {
    ensure_session_started(false);
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim((string)$text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

$base = ADMIN_BASE_PATH;
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/?page=admin/themes/index')
    : ($base . '/?page=admin/themes/index');

/* ---------- SAVE NONCE (anti double insert) ---------- */
$nonce_key = 'theme_add_nonce';
if (empty($_SESSION[$nonce_key])) {
    $_SESSION[$nonce_key] = bin2hex(random_bytes(12));
}
$save_nonce = (string)$_SESSION[$nonce_key];

$errors = [];

$title   = trim((string)($_POST['title'] ?? ''));
$slug    = trim((string)($_POST['slug'] ?? ''));
$content = (string)($_POST['content'] ?? '');
$status  = in_array((string)($_POST['status'] ?? ''), ['draft', 'published', 'private'], true)
    ? (string)$_POST['status']
    : 'draft';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = __('Invalid CSRF token.');
    }

    if (!hash_equals($save_nonce, (string)($_POST['save_nonce'] ?? ''))) {
        $errors[] = __('Token penyimpanan tidak valid. Muat ulang halaman.');
    }

    if ($title === '') {
        $errors[] = __('Title is required.');
    }

    if (trim($content) === '') {
        $errors[] = __('Content is required.');
    }

    $slug = $slug === '' ? slugify($title) : slugify($slug);

    if (empty($errors)) {
        $s = $pdo->prepare("SELECT id FROM posts WHERE slug = :slug LIMIT 1");
        $s->execute([':slug' => $slug]);
        if ($s->fetch()) {
            $errors[] = __('Slug already used.');
        }
    }

    if (empty($errors)) {
        unset($_SESSION[$nonce_key]); // invalidate once used

        try {
            $stmt = $pdo->prepare("
                INSERT INTO posts
                    (title, slug, content, type, status, created_by, created_at, updated_at)
                VALUES
                    (:title, :slug, :content, 'theme', :status, :uid, NOW(), NOW())
            ");

            $ok = $stmt->execute([
                ':title'   => $title,
                ':slug'    => $slug,
                ':content' => $content,
                ':status'  => $status,
                ':uid'     => $user_id,
            ]);

            if ($ok) {
                adiwira_redirect_with_flash($return_to, 'success', __('Theme partial berhasil disimpan.'));
            }

            $errors[] = __('Failed to save to database.');
        } catch (Throwable $e) {
            error_log('themes/add.php error: ' . $e->getMessage());
            $errors[] = __('Terjadi kesalahan saat menyimpan data.');
        }
    }

    if (!empty($errors)) {
        $_SESSION[$nonce_key] = bin2hex(random_bytes(12));
        $save_nonce = (string)$_SESSION[$nonce_key];
    }
}
?>

<section class="adam-card">
  <h2><?=_e('Add Theme / Partial')?></h2>

  <form method="post" id="theme-add-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="save_nonce" value="<?= htmlspecialchars($save_nonce, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-toolbar" style="display:flex;gap:.5rem;margin-bottom:.8rem;">
      <button type="submit" class="adam-button">💾 <?=_e('Save')?></button>
      <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Cancel')?></a>
    </div>

    <div class="adam-accordion" id="theme-meta-accordion" data-open="1">
      <button
        type="button"
        class="adam-accordion-toggle"
        aria-expanded="true"
        aria-controls="theme-meta-body"
      >
        ⚙️ Pengaturan Theme
        <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="theme-meta-body">
        <label>
          <?=_e('Title')?><br>
          <input
            type="text"
            name="title"
            value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
            class="inpud"
          >
        </label>

        <label style="margin-top:.6rem;display:block">
          Slug (opsional)<br>
          <input
            type="text"
            name="slug"
            value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
            class="inpud"
          >
        </label>

        <label style="margin-top:.6rem;display:block">
          Status<br>
          <select name="status" class="inpud">
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
            <option value="private" <?= $status === 'private' ? 'selected' : '' ?>>Private</option>
          </select>
        </label>
      </div>
    </div>

    <label style="margin-top:.75rem;display:block">
      Konten (HTML / PHP fragment)<br>
      <div class="adam-cm-wrap">
        <textarea id="cm-textarea" style="width:100%;min-height:70vh;"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
      <textarea id="content-textarea" name="content" style="display:none"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
    </label>
  </form>
</section>

<?php
if (!empty($errors) && function_exists('adiwira_bootstrap_toasts_script')) {
    $items = array_map(static fn($msg) => ['type' => 'error', 'message' => (string)$msg], $errors);
    echo adiwira_bootstrap_toasts_script($items);
}
?>

<input type="hidden" id="editor-codemirror" checked>

<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_FORM_ID = 'theme-add-form';
</script>

<script src="/static/js/edit/codemirror.js"></script>
<script src="/static/js/edit/main-init.js"></script>

<script>
(function(){
  const form = document.getElementById('theme-add-form');
  const btn = form ? form.querySelector('button[type="submit"]') : null;
  let saving = false;

  function getCMValue(){
    if (window.ADIWIRA && window.ADIWIRA.cm && typeof window.ADIWIRA.cm.getValue === 'function') {
      return window.ADIWIRA.cm.getValue();
    }
    const cmHelper = window.ADIWIRA && window.ADIWIRA.codemirror;
    if (cmHelper && typeof cmHelper.getInstance === 'function') {
      const cm = cmHelper.getInstance();
      if (cm && typeof cm.getValue === 'function') return cm.getValue();
    }
    const ta = document.getElementById('cm-textarea');
    return ta ? ta.value : '';
  }

  if (form) {
    form.addEventListener('submit', function(e){
      if (saving) {
        e.preventDefault();
        return false;
      }

      saving = true;
      if (btn) btn.disabled = true;

      const canonical = document.getElementById('content-textarea');
      if (canonical) canonical.value = getCMValue();
    });
  }

  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      if (!saving && form) form.requestSubmit();
    }
  });
})();
</script>