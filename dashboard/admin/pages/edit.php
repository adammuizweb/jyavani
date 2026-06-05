<?php
declare(strict_types=1);

// /adiwira/admin/pages/edit.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$me, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        $text = trim($text, '-');
        return $text ?: bin2hex(random_bytes(4));
    }
}

if (!function_exists('fetch_users_for_dropdown')) {
    function fetch_users_for_dropdown(PDO $pdo): array {
        try {
            $sql = "
                SELECT
                    id,
                    name,
                    username,
                    email,
                    img,
                    CASE
                      WHEN name IS NOT NULL AND name != '' THEN CONCAT(name, ' (', email, ')')
                      WHEN email IS NOT NULL AND email != '' THEN email
                      ELSE CONCAT('user-', id)
                    END AS label
                FROM users
                WHERE is_deleted = 0
                  AND is_locked = 0
                ORDER BY name ASC, email ASC
            ";
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('to_datetime_local')) {
    function to_datetime_local(?string $mysqlDt): ?string {
        if (!$mysqlDt) return null;
        try {
            $d = new DateTime($mysqlDt, new DateTimeZone('Asia/Jakarta'));
            return $d->format('Y-m-d\\TH:i');
        } catch (Exception $e) {
            return null;
        }
    }
}

$base = ADMIN_BASE_PATH;
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/?page=admin/pages/index')
    : ($base . '/?page=admin/pages/index');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo '<p>' . __('Invalid page ID.') . '</p>';
    return;
}

// ambil page
$stmt = $pdo->prepare("
    SELECT *
    FROM posts
    WHERE id = :id
      AND type = 'page'
      AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    http_response_code(404);
    echo '<p>' . __('Page not found or is not a page.') . '</p>';
    return;
}

// admin bebas, author/editor hanya miliknya sendiri
if ($role !== 'admin' && (int)($post['created_by'] ?? 0) !== $me) {
    http_response_code(403);
    echo '<p>' . __('Access denied: you can only edit your own pages.') . '</p>';
    return;
}

// users admin-only
$users = ($role === 'admin') ? fetch_users_for_dropdown($pdo) : [];

// prefer POST value kalau ada redisplay
$val = function($key, $default = '') {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
};

$title      = (string)$val('title', $post['title'] ?? '');
$slug       = (string)$val('slug', $post['slug'] ?? '');
$content    = (string)$val('content', $post['content'] ?? '');
$status     = (string)$val('status', $post['status'] ?? 'draft');
$thumbnail  = (string)$val('thumbnail', $post['thumbnail'] ?? '');
$created_by = (int)$val('created_by', $post['created_by'] ?? 0);

// mode editor default
$chosenMode = (string)($_POST['editor_mode'] ?? '');
$isComplex  = (bool)preg_match('/<(script|style|iframe|embed|object|form|svg|canvas|php|link|meta)[\s>]|on[a-z]+\s*=|style\s*=/i', $content);

if ($chosenMode === '') {
    $chosenMode = $isComplex ? 'codemirror' : 'quill';
}
if (!in_array($chosenMode, ['quill', 'codemirror'], true)) {
    $chosenMode = $isComplex ? 'codemirror' : 'quill';
}
?>

<section class="adam-card">
  <h2>Edit Page</h2>

  <form method="post"
        id="page-edit-form"
        action="<?= htmlspecialchars($base . '/admin/pages/save.php', ENT_QUOTES, 'UTF-8') ?>"
        novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">

    <div class="adam-accordion" id="page-meta-accordion" data-open="1">
      <button type="button"
              class="adam-accordion-toggle"
              aria-expanded="true"
              aria-controls="page-meta-body">
        ⚙️ Pengaturan Halaman
        <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="page-meta-body">
        <label>Judul<br>
          <input type="text"
                 name="title"
                 value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud">
        </label>

        <label style="display:block;margin-top:.6rem">Slug (opsional)<br>
          <input type="text"
                 name="slug"
                 value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud">
        </label>

        <label style="display:block;margin-top:.6rem">
          Thumbnail (URL) atau pilih dari Media<br>
          <div style="display:flex;gap:.5rem;align-items:center;margin-top:.4rem;">
            <input type="text"
                   id="thumbnail-input"
                   name="thumbnail"
                   value="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>"
                   style="flex:1;padding:.5rem;border:1px solid #ddd;border-radius:6px"
                   placeholder="<?= _e('Thumbnail URL (or select from Media)') ?>">
            <button type="button"
                    id="btn-open-media-for-thumb"
                    class="adam-button"
                    style="padding:.45rem .7rem;border-radius:6px;border:1px solid #ddd"><?= _e('Select from Media') ?></button>
            <button type="button"
                    id="thumbnail-clear"
                    class="adam-link"
                    style="padding:.35rem .6rem">
              Clear
            </button>
          </div>

          <div id="thumbnail-preview" style="margin-top:.6rem;">
            <?php if (!empty($thumbnail)): ?>
              <img src="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>"
                   alt="preview"
                   style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem">
            <?php endif; ?>
          </div>
        </label>
      </div>
    </div>

    <label style="display:block;margin-top:.6rem">
      Pilih Editor<br>
      <div style="margin-top:.4rem;display:flex;gap:.5rem;align-items:center">
        <label>
          <input type="radio"
                 name="editor_mode"
                 value="quill"
                 id="editor-quill"
                 <?= ($chosenMode === 'quill') ? 'checked' : '' ?>>
          Quill (rich)
        </label>
        <label>
          <input type="radio"
                 name="editor_mode"
                 value="codemirror"
                 id="editor-codemirror"
                 <?= ($chosenMode === 'codemirror') ? 'checked' : '' ?>>
          CodeMirror (HTML)
        </label>
      </div>
    </label>

    <textarea name="content" id="content-textarea" style="display:none"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>

    <div id="quill-area" class="adam-quill adam-quill--auto" style="margin-top:.6rem;">
      <div id="quill-toolbar"></div>
      <div id="quill-editor"><?= $content ? $content : '' ?></div>
    </div>

    <div id="codemirror-area" style="margin-top:.6rem;display:none;">
      <div id="cm-wrap" style="border:1px solid #333;border-radius:6px;overflow:hidden">
        <textarea id="cm-textarea" style="width:100%;min-height:300px;"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    </div>

    <div class="form-row" style="margin-top:.6rem">
      <label for="status">Status</label>
      <select name="status" id="status" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <option value="draft" <?= ($status === 'draft') ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= ($status === 'published') ? 'selected' : '' ?>>Published</option>
        <option value="private" <?= ($status === 'private') ? 'selected' : '' ?>>Private</option>
      </select>
    </div>

    <?php if ($role === 'admin'): ?>
      <label style="display:block;margin-top:.6rem">
        Created By<br>
        <select name="created_by" style="margin-top:.4rem;padding:.4rem;border:1px solid #ddd;border-radius:6px">
          <?php
            if (!empty($users)) {
                foreach ($users as $u) {
                    $uidOpt = (int)$u['id'];
                    $label  = htmlspecialchars($u['label'], ENT_QUOTES, 'UTF-8');
                    $username = htmlspecialchars($u['username'] ?? '', ENT_QUOTES, 'UTF-8');
                    $img = htmlspecialchars($u['img'] ?? '', ENT_QUOTES, 'UTF-8');
                    $sel = ($uidOpt === $created_by) ? 'selected' : '';
                    echo "<option value=\"{$uidOpt}\" data-username=\"{$username}\" data-img=\"{$img}\" {$sel}>{$label}</option>";
                }
            } else {
                echo '<option value="' . (int)$created_by . '" selected>User ID ' . (int)$created_by . '</option>';
            }
          ?>
        </select>
        <div style="font-size:12px;color:#666;margin-top:6px"><?= _e('Admin only.') ?></div>
      </label>

      <label style="display:block;margin-top:.6rem">
        Created At<br>
        <input type="datetime-local"
               name="created_at"
               value="<?= htmlspecialchars($_POST['created_at'] ?? to_datetime_local($post['created_at']), ENT_QUOTES, 'UTF-8') ?>"
               style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <div style="font-size:12px;color:#666;margin-top:4px">Kosongkan untuk mempertahankan nilai semula (<?= htmlspecialchars((string)$post['created_at'], ENT_QUOTES, 'UTF-8') ?>).</div>
      </label>

      <label style="display:block;margin-top:.6rem">
        Updated At<br>
        <input type="datetime-local"
               name="updated_at"
               value="<?= htmlspecialchars($_POST['updated_at'] ?? to_datetime_local($post['updated_at']), ENT_QUOTES, 'UTF-8') ?>"
               style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <div style="font-size:12px;color:#666;margin-top:4px"><?= _e('Leave empty to use current time.') ?></div>
      </label>
    <?php else: ?>
      <div style="margin-top:.8rem;color:#666;font-size:12px"><?= _e('Creator cannot be changed. Timestamp can only be edited by admin.') ?></div>
    <?php endif; ?>

    <?php
    $current_sidebar = '';
    if (!empty($post['meta'])) {
        $pm = is_string($post['meta']) ? json_decode($post['meta'], true) : $post['meta'];
        if (is_array($pm) && isset($pm['sidebar'])) {
            $current_sidebar = $pm['sidebar'];
        }
    }
    ?>
    <div style="margin-top:.6rem;padding-top:.6rem;border-top:1px solid var(--adam-border);">
      <div style="font-size:13px;font-weight:600;margin-bottom:.4rem">📐 Posisi Sidebar</div>
      <select name="sidebar_override" style="padding:3px 5px;border:1px solid var(--adam-border-2);border-radius:4px;background:var(--adam-card);color:var(--adam-text);font-size:12px">
        <option value=""><?= _e('Default (follow global hierarchy)') ?></option>
        <option value="right" <?= $current_sidebar === 'right' ? 'selected' : '' ?>>Kanan</option>
        <option value="left" <?= $current_sidebar === 'left' ? 'selected' : '' ?>>Kiri</option>
        <option value="hide" <?= $current_sidebar === 'hide' ? 'selected' : '' ?>>Sembunyikan</option>
      </select>
    </div>

    <p style="margin-top:.8rem">
      <button type="submit" class="adam-button" id="btn-save"><?= _e('Save Changes') ?></button>
      <a class="adam-cancle" href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">Batal</a>
    </p>
  </form>
</section>

<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_FORM_ID = 'page-edit-form';
</script>

<script src="/static/js/edit/utils.js"></script>

<!-- pakai helper modal + selector yang sudah terbukti jalan di halaman add -->
<script src="/static/js/add/modal-helpers.js"></script>
<script src="/static/js/add/media-selector.js"></script>
<script src="/static/js/add/file-selector.js"></script>

<!-- editor spesifik edit tetap -->
<script src="/static/js/edit/codemirror.js"></script>
<script src="/static/js/edit/quill.js"></script>
<script src="/static/js/edit/editor_mode.js"></script>
<script src="/static/js/edit/thumbnail.js"></script>
<script src="/static/js/edit/ajax_save.js"></script>
<script src="/static/js/edit/main-init.js"></script>

<script>
(function(){
  const form = document.getElementById('page-edit-form');
  const saveBtn = document.getElementById('btn-save');
  const contentField = document.getElementById('content-textarea');

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

  function currentEditorMode(){
    const checked = document.querySelector('input[name="editor_mode"]:checked');
    return checked ? checked.value : 'quill';
  }

  function getCodeMirrorValue(){
    const direct = window.ADIWIRA && window.ADIWIRA.cm;
    if (direct && typeof direct.getValue === 'function') {
      return direct.getValue();
    }

    const wrapper = document.querySelector('#codemirror-area .CodeMirror');
    if (wrapper && wrapper.CodeMirror && typeof wrapper.CodeMirror.getValue === 'function') {
      return wrapper.CodeMirror.getValue();
    }

    const textarea = document.getElementById('cm-textarea');
    return textarea ? textarea.value : '';
  }

  function getQuillValue(){
    const direct = window.ADIWIRA && window.ADIWIRA.quill;
    if (direct && direct.root) {
      return direct.root.innerHTML;
    }

    if (window.quill && window.quill.root) {
      return window.quill.root.innerHTML;
    }

    const editor = document.querySelector('#quill-editor .ql-editor');
    return editor ? editor.innerHTML : '';
  }

  function syncContent(){
    contentField.value = currentEditorMode() === 'codemirror'
      ? getCodeMirrorValue()
      : getQuillValue();
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
        saveBtn.textContent = oldLabel || 'Simpan Perubahan';
      }
    }
  }

  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    syncContent();

    askWarning({
      title: <?= json_encode(__('Save changes')) ?>,
      message: <?= json_encode(__('This page will be saved. Proceed?')) ?>,
      confirmText: <?= json_encode(__('Yes, save')) ?>,
      cancelText: <?= json_encode(__('Cancel')) ?>
    }).then(function(ok){
      if (!ok) return;
      submitAjax();
    });
  });
})();
</script>