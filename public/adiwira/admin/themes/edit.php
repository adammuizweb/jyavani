<?php
declare(strict_types=1);

// /adiwira/admin/themes/edit.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$user_id, $user_role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($user_role === 'admin');

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

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/index.php?page=admin/themes/index')
    : ($base . '/index.php?page=admin/themes/index');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo '<p>ID theme tidak valid.</p>';
    return;
}

$sql = "SELECT * FROM posts WHERE id = :id AND type = 'theme' AND is_deleted = 0";
$params = [':id' => $id];

if (!$isAdmin) {
    $sql .= " AND created_by = :uid";
    $params[':uid'] = $user_id;
}

$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$theme = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$theme) {
    http_response_code(404);
    echo '<p>Theme tidak ditemukan.</p>';
    return;
}

$save_nonce = bin2hex(random_bytes(12));
$_SESSION['theme_save_nonce_' . $id] = $save_nonce;

$pref_title   = (string)($theme['title'] ?? '');
$pref_slug    = (string)($theme['slug'] ?? '');
$pref_content = (string)($theme['content'] ?? '');
$pref_status  = (string)($theme['status'] ?? 'draft');
?>
<section class="adam-card">
  <h2>Edit Theme / Partial</h2>

  <form method="post" id="theme-edit-form" action="<?= htmlspecialchars($base . '/admin/themes/save.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="save_nonce" id="save_nonce" value="<?= htmlspecialchars($save_nonce, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= (int)$theme['id'] ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-toolbar" style="display:flex;align-items:center;gap:.5rem;margin-bottom:.8rem;">
      <button type="submit" class="adam-button" id="btn-save">💾 Simpan Perubahan</button>
      <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle">Batal</a>

      <div style="margin-left:auto;font-size:.9rem;color:#555;">
        Updated:
        <span id="updated-at">
          <?= htmlspecialchars(function_exists('format_datetime_indo') ? format_datetime_indo((string)($theme['updated_at'] ?? '-')) : (string)($theme['updated_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
        </span>
      </div>
    </div>

    <div class="adam-accordion" id="theme-meta-accordion" data-open="1">
      <button type="button"
              class="adam-accordion-toggle"
              aria-expanded="true"
              aria-controls="theme-meta-body">
        ⚙️ Pengaturan Theme
        <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="theme-meta-body">
        <label>Judul<br>
          <input type="text" name="title"
                 value="<?= htmlspecialchars($pref_title, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud">
        </label>

        <label style="margin-top:.6rem;display:block">Slug (opsional)<br>
          <input type="text" name="slug"
                 value="<?= htmlspecialchars($pref_slug, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud">
        </label>

        <label style="margin-top:.6rem;display:block">Status<br>
          <select name="status" class="inpud">
            <option value="draft" <?= $pref_status === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="published" <?= $pref_status === 'published' ? 'selected' : '' ?>>Published</option>
            <option value="private" <?= $pref_status === 'private' ? 'selected' : '' ?>>Private</option>
          </select>
        </label>
      </div>
    </div>

    <div style="margin-top:.75rem;">
      <label>Konten (HTML / PHP fragment)<br>
        <textarea id="cm-textarea"
                  style="width:100%;min-height:70vh;padding:.5rem;margin-top:.4rem;border:1px solid #ddd;border-radius:6px;"><?= htmlspecialchars($pref_content, ENT_QUOTES, 'UTF-8') ?></textarea>
        <textarea id="content-textarea" name="content" style="display:none;"><?= htmlspecialchars($pref_content, ENT_QUOTES, 'UTF-8') ?></textarea>
      </label>
    </div>
  </form>
</section>

<input type="hidden" id="editor-codemirror" checked>

<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_FORM_ID = 'theme-edit-form';
</script>

<script src="/adiwira/static/js/edit/codemirror.js"></script>
<script src="/adiwira/static/js/edit/main-init.js"></script>

<script>
(function(){
  const form = document.getElementById('theme-edit-form');
  const saveBtn = document.getElementById('btn-save');
  const contentField = document.getElementById('content-textarea');
  const nonceField = document.getElementById('save_nonce');
  const updatedAtEl = document.getElementById('updated-at');

  if (!form || !contentField) return;

  function notify(type, message, title){
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: title, message: message });
      return;
    }
    alert(message);
  }

  function askWarning(opts){
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
      return window.NewNotifConfirm.warning(opts);
    }
    return Promise.resolve(window.confirm(opts.message || 'Lanjutkan aksi ini?'));
  }

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

  function syncContent(){
    contentField.value = getCMValue();
  }

  async function submitAjax(){
    syncContent();

    const oldLabel = saveBtn ? saveBtn.textContent : '';
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Menyimpan...';
    }

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });

      const data = await res.json().catch(function(){
        return { ok:false, errors:['Respons server tidak valid.'] };
      });

      if (!res.ok || !data.ok) {
        const errors = Array.isArray(data.errors) && data.errors.length
          ? data.errors
          : [data.error || data.message || 'Gagal menyimpan perubahan.'];

        errors.filter(Boolean).forEach(function(msg, idx){
          notify('error', String(msg), idx === 0 ? 'Gagal menyimpan' : 'Detail error');
        });
        return;
      }

      if (data.new_save_nonce && nonceField) {
        nonceField.value = data.new_save_nonce;
      }

      if (data.updated_at && updatedAtEl) {
        updatedAtEl.textContent = data.updated_at;
      }

      if (data.redirect) {
        window.location.href = data.redirect;
        return;
      }

      notify('success', data.message || 'Perubahan berhasil disimpan.', 'Berhasil');

    } catch (err) {
      notify('error', 'Terjadi gangguan jaringan saat menyimpan.', 'Jaringan');
    } finally {
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.textContent = oldLabel || '💾 Simpan Perubahan';
      }
    }
  }

  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    syncContent();

    askWarning({
      title: 'Simpan perubahan',
      message: 'Perubahan theme partial ini akan disimpan. Lanjutkan?',
      confirmText: 'Ya, simpan',
      cancelText: 'Batal'
    }).then(function(ok){
      if (!ok) return;
      submitAjax();
    });
  });

  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      form.requestSubmit();
    }
  });
})();
</script>