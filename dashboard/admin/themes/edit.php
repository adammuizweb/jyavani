<?php
declare(strict_types=1);

// /adiwira/admin/themes/edit.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$user_id] = adiwira_require_permission_scope($pdo, 'core.theme_content.read', false);
$user_role = authorization_active_legacy_role($pdo, $user_id);
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

$base = ADMIN_BASE_PATH;
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/?page=admin/themes/index')
    : ($base . '/?page=admin/themes/index');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo '<p>' . __('Invalid theme ID.') . '</p>';
    return;
}

$sql = "SELECT * FROM posts WHERE id = :id AND type = 'theme' AND is_deleted = 0 LIMIT 1";
$params = [':id' => $id];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$theme = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$theme) {
    http_response_code(404);
    echo '<p>' . __('Theme not found.') . '</p>';
    return;
}
$editorContext = authorization_editor_context($pdo, $user_id, (int)($theme['created_by'] ?? 0), 'core.theme_content.read', 'core.theme_content.update', [
    'resource_type' => 'theme',
    'resource_id' => (int)$theme['id'],
]);
if ($editorContext === null) adiwira_render_404();
$isReadOnly = $editorContext['read_only'];

$save_nonce = bin2hex(random_bytes(12));
$_SESSION['theme_save_nonce_' . $id] = $save_nonce;

$pref_title   = (string)($theme['title'] ?? '');
$pref_slug    = (string)($theme['slug'] ?? '');
$pref_content = (string)($theme['content'] ?? '');
$pref_status  = (string)($theme['status'] ?? 'draft');
$canonicalRoute = function_exists('content_route_find_canonical')
    ? content_route_find_canonical($pdo, (int)$theme['id'])
    : null;
$pref_public_path = (string)($canonicalRoute['path'] ?? '');

$enable_custom_meta = ($pdo instanceof PDO && function_exists('settings_get'))
    ? (settings_get($pdo, 'enable_custom_meta', '0') === '1')
    : false;
if ($isReadOnly) {
    ?>
    <section class="adam-card">
      <div class="adam-notice adam-notice--info" role="status"><?=_e('Read-only: you can view this item, but you cannot change it.')?></div>
      <h2><?=_e('View Theme / Partial')?></h2>
      <label><?=_e('Title')?><br><input class="inpud" readonly value="<?= htmlspecialchars($pref_title, ENT_QUOTES, 'UTF-8') ?>"></label>
      <label style="display:block;margin-top:.6rem"><?=_e('Internal slug')?><br><input class="inpud" readonly value="<?= htmlspecialchars($pref_slug, ENT_QUOTES, 'UTF-8') ?>"></label>
      <label style="display:block;margin-top:.6rem"><?=_e('Content')?><br><textarea class="inpud" rows="20" readonly><?= htmlspecialchars($pref_content, ENT_QUOTES, 'UTF-8') ?></textarea></label>
      <?php do_action('admin_content_readonly_actions', $theme, $editorContext, $pdo); ?>
      <p><a class="adam-cancle" href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>"><?=_e('Back')?></a></p>
    </section>
    <?php return;
}
?>
<section class="adam-card">
  <h2><?=_e('Edit Theme / Partial')?></h2>

  <form method="post" id="theme-edit-form" action="<?= htmlspecialchars($base . '/admin/themes/save.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="save_nonce" id="save_nonce" value="<?= htmlspecialchars($save_nonce, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= (int)$theme['id'] ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-toolbar" style="display:flex;align-items:center;gap:.5rem;margin-bottom:.8rem;">
      <button type="submit" class="adam-button" id="btn-save"><?= svg_ico('save', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Save Changes')?></button>
      <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Cancel')?></a>

      <div style="margin-left:auto;font-size:.9rem;color:#555;">
        <?=_e('Updated:')?>
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
        <?= svg_ico('cog', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Theme Settings')?>
        <span class="chevron">▸</span>
      </button>

      <div class="adam-accordion-body" id="theme-meta-body">
        <label><?=_e('Title')?><br>
          <input type="text" name="title"
                 value="<?= htmlspecialchars($pref_title, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud">
        </label>

        <label style="margin-top:.6rem;display:block"><?=_e('Internal slug (optional)')?><br>
          <input type="text" name="slug"
                 value="<?= htmlspecialchars($pref_slug, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud">
        </label>

        <label style="margin-top:.6rem;display:block"><?=_e('Public path (optional)')?><br>
          <input type="text" name="public_path"
                 value="<?= htmlspecialchars($pref_public_path, ENT_QUOTES, 'UTF-8') ?>"
                 class="inpud" placeholder="showcase/themes/example">
          <small class="adam-muted"><?=_e('Changing this path keeps the previous URL as a permanent redirect.')?></small>
        </label>

        <?php if ($enable_custom_meta):
        $current_meta_desc = '';
        if (!empty($theme['meta'])) {
            $pm = is_string($theme['meta']) ? json_decode($theme['meta'], true) : $theme['meta'];
            if (is_array($pm) && isset($pm['meta_tags']['description'])) {
                $current_meta_desc = $pm['meta_tags']['description'];
            }
        }
        ?>
        <label style="margin-top:.6rem;display:block">
          <?=_e('Meta Description')?><br>
          <textarea name="meta_description" rows="2" style="width:100%;padding:.4rem;border:1px solid var(--adam-border-2);border-radius:4px;background:var(--adam-card);color:var(--adam-text);font-size:13px;resize:vertical;box-sizing:border-box;margin-top:4px" maxlength="320" placeholder="<?=_e('Custom description for SEO & social share')?>"><?= htmlspecialchars($current_meta_desc, ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>
        <?php endif; ?>

        <label style="margin-top:.6rem;display:block"><?=_e('Status')?><br>
          <select name="status" class="inpud">
            <option value="draft" <?= $pref_status === 'draft' ? 'selected' : '' ?>><?= _e('Draft') ?></option>
            <option value="published" <?= $pref_status === 'published' ? 'selected' : '' ?>><?= _e('Published') ?></option>
            <option value="private" <?= $pref_status === 'private' ? 'selected' : '' ?>><?= _e('Private') ?></option>
          </select>
        </label>

        <?php if ($isAdmin): ?>
        <label style="margin-top:.6rem;display:block">
          <?=_e('Author')?><br>
          <select name="created_by" class="inpud">
            <?php
            $current_author = (int)($theme['created_by'] ?? $user_id);
            $authors = $pdo->query("SELECT id, email FROM users WHERE is_deleted = 0 ORDER BY email ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($authors as $a) {
                $sel = $a['id'] === $current_author ? 'selected' : '';
                echo '<option value="' . $a['id'] . '" ' . $sel . '>' . htmlspecialchars($a['email'], ENT_QUOTES, 'UTF-8') . '</option>';
            }
            ?>
          </select>
        </label>
        <?php endif; ?>
      </div>
    </div>

    <?php do_action('theme_editor_before_content', $theme, $pdo); ?>

    <div style="margin-top:.75rem;">
      <label><?=_e('Content (HTML / PHP fragment)')?><br>
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

<script src="/static/js/edit/codemirror.js"></script>
<script src="/static/js/edit/main-init.js"></script>

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
    return Promise.resolve(window.confirm(opts.message || '<?=__('Continue this action?')?>'));
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
          : [data.error || data.message || <?= json_encode(__('Failed to save changes.')) ?>];

        errors.filter(Boolean).forEach(function(msg, idx){
          notify('error', String(msg), idx === 0 ? <?= json_encode(__('Save failed')) ?> : <?= json_encode(__('Detail error')) ?>);
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

      notify('success', data.message || '<?=__('Changes saved successfully.')?>', '<?=__('Success')?>');

    } catch (err) {
      notify('error', '<?=__('Network error while saving.')?>', '<?=__('Network')?>');
    } finally {
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.innerHTML = oldLabel || '<?= svg_ico('save', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=__('Save Changes')?>';
      }
    }
  }

  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    syncContent();

    askWarning({
      title: <?= json_encode(__('Save changes')) ?>,
      message: '<?=__('Changes to this theme partial will be saved. Continue?')?>',
      confirmText: <?= json_encode(__('Yes, save')) ?>,
      cancelText: <?= json_encode(__('Cancel')) ?>
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
