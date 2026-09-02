<?php
declare(strict_types=1);

// /adiwira/admin/pages/edit.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$me] = adiwira_require_login($pdo, false);

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
$editorStatus = apply_filters('admin_page_editor_status', (string)($post['status'] ?? 'draft'), $post, $pdo);
if (!is_string($editorStatus) || !in_array($editorStatus, ['draft', 'published', 'private'], true)) {
    http_response_code(500);
    echo '<p>' . __('Page editor status is invalid.') . '</p>';
    return;
}
$post['status'] = $editorStatus;

$pageOwnerId = (int)($post['created_by'] ?? 0);
$editorContext = authorization_editor_context($pdo, $me, $pageOwnerId, 'core.pages.read', 'core.pages.update', [
    'resource_type' => 'page',
    'resource_id' => (int)$post['id'],
]);
if ($editorContext === null) {
    http_response_code(403);
    echo '<p>' . __('Access denied.') . '</p>';
    return;
}
$ownerContext = ['owner_id' => $pageOwnerId];
$canPublish = user_can($pdo, $me, 'core.pages.publish', $ownerContext);
$canChangeOwner = user_can($pdo, $me, 'core.pages.change_owner', $ownerContext);
$canChangeDates = user_can($pdo, $me, 'core.pages.change_dates', $ownerContext);
$canUseUnfilteredHtml = user_can($pdo, $me, 'core.pages.unfiltered_html');
$isReadOnly = $editorContext['read_only'] || ((string)($post['status'] ?? 'draft') !== 'draft' && !$canPublish);

if ($isReadOnly) {
    ?>
    <section class="adam-card">
      <div class="adam-notice adam-notice--info" role="status"><?=_e('Read-only: you can view this item, but you cannot change it.')?></div>
      <h2 class="edit-heading"><?=_e('View Page')?></h2>
      <label><?=_e('Title')?><br><input class="inpud" type="text" readonly value="<?= htmlspecialchars((string)$post['title'], ENT_QUOTES, 'UTF-8') ?>"></label>
      <label style="display:block;margin-top:.6rem"><?=_e('Slug')?><br><input class="inpud" type="text" readonly value="<?= htmlspecialchars((string)$post['slug'], ENT_QUOTES, 'UTF-8') ?>"></label>
      <label style="display:block;margin-top:.6rem"><?=_e('Status')?><br><input class="inpud" type="text" readonly value="<?= htmlspecialchars(__(ucfirst((string)$post['status'])), ENT_QUOTES, 'UTF-8') ?>"></label>
      <label style="display:block;margin-top:.6rem"><?=_e('Content')?><br><textarea class="inpud" rows="20" readonly><?= htmlspecialchars((string)$post['content'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
      <?php do_action('admin_content_readonly_actions', $post, $editorContext, $pdo); ?>
      <p><a class="adam-cancle" href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>"><?=_e('Back')?></a></p>
    </section>
    <?php
    return;
}

$users = $canChangeOwner ? array_values(array_filter(
    fetch_users_for_dropdown($pdo),
    static fn(array $user): bool => user_can($pdo, $me, 'core.pages.change_owner', ['owner_id' => (int)$user['id']])
)) : [];

// prefer POST value kalau ada redisplay
$val = function($key, $default = '') {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
};

$title      = (string)$val('title', $post['title'] ?? '');
$slug       = (string)$val('slug', $post['slug'] ?? '');
$content    = (string)$val('content', $post['content'] ?? '');
$content = $canUseUnfilteredHtml ? $content : cms_sanitize_restricted_html($content);
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
  <h2 class="edit-heading"><?=_e('Edit Page')?></h2>

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
        <?= svg_ico('cog', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Page Settings')?>
        <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="page-meta-body">
        <label><?=_e('Title')?><br>
          <input type="text"
                 name="title"
                 value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud">
        </label>

        <label style="display:block;margin-top:.6rem"><?=_e('Slug (optional)')?><br>
          <input type="text"
                 name="slug"
                 value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud">
        </label>

        <label style="display:block;margin-top:.6rem">
          <?=_e('Thumbnail')?><br>
          <div class="thumb-row">
            <?php if (!$canUseUnfilteredHtml): ?>
            <input type="hidden"
                   id="thumbnail-input"
                   name="thumbnail"
                   value="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>">
            <?php else: ?>
            <input type="text"
                   id="thumbnail-input"
                   name="thumbnail"
                   value="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>"
                   class="inpud"
                   placeholder="<?= _e('Thumbnail URL') ?>"
                   style="display:none">
            <?php endif; ?>
            <button type="button"
                    id="btn-open-media-for-thumb"
                    class="thumb-gallery-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
              <?=_e('Gallery')?>
            </button>
            <?php if ($canUseUnfilteredHtml): ?>
            <button type="button"
                    id="btn-toggle-url-input"
                    class="thumb-url-btn"><?=_e('Insert via URL')?></button>
            <?php endif; ?>
            <button type="button"
                    id="thumbnail-clear"
                    class="thumb-clear-btn"
                    title="<?=_e('Clear')?>"
                    style="<?= empty($thumbnail) ? 'display:none' : '' ?>">&times;</button>
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

    <?php do_action('editor_mode_before_options', $post ?? [], $chosenMode, $editorContext, $pdo); ?>

    <label style="display:block;margin-top:.6rem">
      <?=_e('Select Editor')?><br>
      <?php
      $editorModes = [
          'quill'      => __('Quill (rich)'),
          'codemirror' => __('CodeMirror (HTML)'),
      ];
      $editorModes = apply_filters('editor_mode_options', $editorModes, $post ?? []);
      ?>
      <div style="margin-top:.4rem;display:flex;gap:.5rem;align-items:center">
        <?php foreach ($editorModes as $modeVal => $modeLabel): ?>
        <label><input type="radio" name="editor_mode" value="<?= htmlspecialchars($modeVal, ENT_QUOTES) ?>" id="editor-<?= htmlspecialchars($modeVal, ENT_QUOTES) ?>" <?= ($chosenMode === $modeVal || ($chosenMode === '' && $modeVal === 'quill')) ? 'checked' : '' ?>> <?= htmlspecialchars($modeLabel, ENT_QUOTES) ?></label>
        <?php endforeach; ?>
      </div>
    </label>

    <textarea name="content" id="content-textarea" style="display:none"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>

    <div id="quill-area" class="adam-quill adam-quill--auto" style="margin-top:.6rem;">
      <div id="quill-toolbar"></div>
      <div id="quill-editor"></div>
    </div>

    <div id="codemirror-area" style="margin-top:.6rem;display:none;">
      <div id="cm-wrap" style="border:1px solid #333;border-radius:6px;overflow:hidden">
        <textarea id="cm-textarea" style="width:100%;min-height:300px;"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    </div>

    <?php do_action('editor_mode_after_areas', $post ?? [], $chosenMode, $editorContext, $pdo); ?>

    <div class="form-row" style="margin-top:.6rem">
      <label for="status"><?=_e('Status')?></label>
      <select name="status" id="status" style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <option value="draft" <?= ($status === 'draft') ? 'selected' : '' ?>><?=_e('Draft')?></option>
        <?php if ($canPublish): ?>
          <option value="published" <?= ($status === 'published') ? 'selected' : '' ?>><?=_e('Published')?></option>
          <option value="private" <?= ($status === 'private') ? 'selected' : '' ?>><?=_e('Private')?></option>
        <?php endif; ?>
      </select>
    </div>

    <?php if ($canChangeOwner): ?>
      <label style="display:block;margin-top:.6rem">
        <?=_e('Created By')?><br>
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
        <div style="font-size:12px;color:#666;margin-top:6px"><?= _e('Requires permission to change the owner.') ?></div>
      </label>
    <?php endif; ?>

    <?php if ($canChangeDates): ?>
      <label style="display:block;margin-top:.6rem">
        <?=_e('Created At')?><br>
        <input type="datetime-local"
               name="created_at"
               value="<?= htmlspecialchars($_POST['created_at'] ?? to_datetime_local($post['created_at']), ENT_QUOTES, 'UTF-8') ?>"
               style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <div style="font-size:12px;color:#666;margin-top:4px"><?=_e('Leave empty to keep the original value')?> (<?= htmlspecialchars((string)$post['created_at'], ENT_QUOTES, 'UTF-8') ?>).</div>
      </label>

      <label style="display:block;margin-top:.6rem">
        <?=_e('Updated At')?><br>
        <input type="datetime-local"
               name="updated_at"
               value="<?= htmlspecialchars($_POST['updated_at'] ?? to_datetime_local($post['updated_at']), ENT_QUOTES, 'UTF-8') ?>"
               style="padding:.4rem;border:1px solid #ddd;border-radius:6px">
        <div style="font-size:12px;color:#666;margin-top:4px"><?= _e('Leave empty to use current time.') ?></div>
      </label>
    <?php endif; ?>

    <?php
    $current_sidebar = '';
    $current_meta_desc = '';
    if (!empty($post['meta'])) {
        $pm = is_string($post['meta']) ? json_decode($post['meta'], true) : $post['meta'];
        if (is_array($pm)) {
            if (isset($pm['sidebar'])) {
                $current_sidebar = $pm['sidebar'];
            }
            if (isset($pm['meta_tags']['description'])) {
                $current_meta_desc = $pm['meta_tags']['description'];
            }
        }
    }
    ?>
    <div style="margin-top:.6rem;padding-top:.6rem;border-top:1px solid var(--adam-border);">
      <div style="font-size:13px;font-weight:600;margin-bottom:.4rem"><?= svg_ico('columns-2', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Sidebar Position')?></div>
      <select name="sidebar_override" style="padding:3px 5px;border:1px solid var(--adam-border-2);border-radius:4px;background:var(--adam-card);color:var(--adam-text);font-size:12px">
        <option value=""><?= _e('Default (follow global hierarchy)') ?></option>
        <option value="right" <?= $current_sidebar === 'right' ? 'selected' : '' ?>><?=_e('Right')?></option>
        <option value="left" <?= $current_sidebar === 'left' ? 'selected' : '' ?>><?=_e('Left')?></option>
        <option value="hide" <?= $current_sidebar === 'hide' ? 'selected' : '' ?>><?=_e('Hide')?></option>
      </select>
    </div>

    <div style="margin-top:.6rem;padding-top:.6rem;border-top:1px solid var(--adam-border);">
      <div style="font-size:13px;font-weight:600;margin-bottom:.4rem"><?= svg_ico('search', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Meta Description')?></div>
      <textarea name="meta_description" rows="3" style="width:100%;padding:.4rem;border:1px solid var(--adam-border-2);border-radius:4px;background:var(--adam-card);color:var(--adam-text);font-size:13px;resize:vertical;box-sizing:border-box" maxlength="320" placeholder="<?= _e('Custom meta description for SEO & social share. Leave empty to auto-generate from content.') ?>"><?= htmlspecialchars($current_meta_desc, ENT_QUOTES, 'UTF-8') ?></textarea>
      <div style="font-size:11px;color:#888;margin-top:3px"><?= _e('Recommended: 150-160 characters. Falls back to excerpt when empty.') ?></div>
    </div>

    <p style="margin-top:.8rem">
      <button type="submit" class="adam-button" id="btn-save"><?= _e('Save Changes') ?></button>
      <a class="adam-cancle" href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>"><?=_e('Cancel')?></a>
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
<script>window.QUILL_PLACEHOLDER = <?= json_encode(__('Write article content here...')) ?>;</script>
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
    return Promise.resolve(window.confirm(opts.message || '<?=__('Proceed with this action?')?>'));
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

      notify('success', data.message || '<?=__('Changes saved successfully.')?>', '<?=__('Success')?>');

    } catch (err) {
      notify('error', '<?=__('Network error while saving.')?>', '<?=__('Network')?>');
    } finally {
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.textContent = oldLabel || '<?=__('Save Changes')?>';
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
