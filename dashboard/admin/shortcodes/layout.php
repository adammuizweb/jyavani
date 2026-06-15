<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_editorial($pdo, false);

$base = ADMIN_BASE_PATH;
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/?page=admin/shortcodes/index&tab=layouts')
    : ($base . '/?page=admin/shortcodes/index&tab=layouts');

$layoutDir = realpath(__DIR__ . '/../../../app/views/partials/shortcodes/post_cat');
if (!$layoutDir || !is_dir($layoutDir)) {
    echo '<section class="adam-card"><p>' . __('Layout directory not found.') . '</p></section>';
    return;
}

$fileName = (string)($_GET['file'] ?? $_POST['file'] ?? '');
$isNew = $fileName === '';
$filePath = '';

if (!$isNew) {
    // Security: prevent path traversal
    $cleanName = preg_replace('/[^a-z0-9_\-\.]/i', '', basename($fileName));
    if (!str_ends_with($cleanName, '.php')) {
        $cleanName .= '.php';
    }
    $filePath = $layoutDir . DIRECTORY_SEPARATOR . $cleanName;
    $realPath = realpath($filePath);

    if (!$realPath || strpos($realPath, $layoutDir) !== 0 || !is_file($realPath)) {
        http_response_code(404);
        echo '<section class="adam-card"><p>' . __('Layout file not found:') . ' ' . htmlspecialchars($cleanName, ENT_QUOTES, 'UTF-8') . '</p></section>';
        return;
    }
    $fileName = $cleanName;
    $filePath = $realPath;
}

$pref_content = '';
$pref_layout_name = '';
if ($isNew) {
    $pref_layout_name = trim((string)($_GET['name'] ?? ''));
    $pref_content = "<?php\n// /views/partials/shortcodes/post_cat/{{name}}.php\n// Variables: \$items, \$attrs, \$layout, \$kicker, \$class_prefix, \$esc\n\n\$items = (isset(\$items) && is_array(\$items)) ? \$items : [];\n\$kicker = isset(\$kicker) ? (string)\$kicker : '';\n\$class_prefix = isset(\$class_prefix) ? (string)\$class_prefix : '';\n\$wrap = !empty(\$wrap);\n\nif (!isset(\$esc) || !is_callable(\$esc)) {\n    \$esc = static function (\$v): string {\n        return htmlspecialchars((string)\$v, ENT_QUOTES, 'UTF-8');\n    };\n}\n\n\$extra = \$class_prefix !== '' ? ' ' . \$esc(\$class_prefix) : '';\n?>\n\n<?php if (\$wrap): ?>\n<div class=\"pcat pcat--<?= \$esc(\$layout) ?><?= \$extra ?>\" data-pcat-layout=\"<?= \$esc(\$layout) ?>\">\n<?php endif; ?>\n\n  <div class=\"pcat__track\">\n    <?php foreach (\$items as \$it): ?>\n      <?php\n        if (!is_array(\$it)) continue;\n        \$title = \$esc(\$it['title'] ?? '');\n        \$url = \$esc(\$it['url'] ?? '#');\n        \$thumb = trim((string)(\$it['thumb'] ?? ''));\n        \$desc = \$esc(\$it['desc'] ?? '');\n      ?>\n      <article class=\"pcat__item\">\n        <a class=\"pcat__card\" href=\"<?= \$url ?>\" aria-label=\"<?= \$title ?>\">\n          <div class=\"pcat__media\">\n            <?php if (\$thumb !== ''): ?>\n              <img class=\"pcat__img\" src=\"<?= \$esc(\$thumb) ?>\" alt=\"\" loading=\"lazy\" decoding=\"async\">\n            <?php else: ?>\n              <div class=\"pcat__img pcat__img--placeholder\" aria-hidden=\"true\"></div>\n            <?php endif; ?>\n          </div>\n          <div class=\"pcat__body\">\n            <div class=\"pcat__kicker\"><?= \$esc(\$kicker) ?></div>\n            <h3 class=\"pcat__title\"><?= \$title ?></h3>\n            <?php if (\$desc !== ''): ?>\n              <p class=\"pcat__desc\"><?= \$desc ?></p>\n            <?php endif; ?>\n          </div>\n        </a>\n      </article>\n    <?php endforeach; ?>\n  </div>\n\n<?php if (\$wrap): ?>\n</div>\n<?php endif; ?>\n";
} else {
    $pref_content = file_get_contents($filePath);
    $pref_layout_name = pathinfo($fileName, PATHINFO_FILENAME);
}

$save_nonce = bin2hex(random_bytes(12));
$_SESSION['sc_layout_nonce'] = $save_nonce;
?>
<section class="adam-card">
  <h2><?= $isNew ? _e('Add New Layout') : _e('Edit Layout:') . ' ' . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') ?></h2>

  <form method="post" id="layout-form" action="<?= htmlspecialchars($base . '/admin/shortcodes/save_layout.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="save_nonce" value="<?= htmlspecialchars($save_nonce, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">

    <?php if (!$isNew): ?>
      <input type="hidden" name="file" value="<?= htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <div class="form-toolbar" style="display:flex;align-items:center;gap:.5rem;margin-bottom:.8rem;">
      <button type="submit" class="adam-button" id="btn-save"><?= svg_ico('save', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?= $isNew ? __('Save') : __('Save Changes') ?></button>
      <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Cancel')?></a>
    </div>

    <?php if ($isNew): ?>
      <div class="adam-accordion" data-open="1">
        <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="layout-meta-body">
          <?= svg_ico('cog', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Layout Settings')?>
          <span class="chevron">▸</span>
        </button>
        <div class="adam-accordion-body" id="layout-meta-body">
          <label><?=_e('Layout Name (slug)')?><br>
            <input type="text" name="layout_name" value="<?= htmlspecialchars($pref_layout_name, ENT_QUOTES, 'UTF-8') ?>" class="inpud" placeholder="my_layout" required>
            <small style="color:var(--adam-muted,#888);">Akan menjadi: <code>views/partials/shortcodes/post_cat/{nama}.php</code></small>
          </label>
          <p style="margin-top:.6rem;font-size:.9rem;background:var(--adam-surface-3);padding:.6rem;border-radius:6px;">
            <strong>Template variables:</strong>
            <code>$items</code>, <code>$attrs</code>, <code>$layout</code>, <code>$kicker</code>,
            <code>$class_prefix</code>, <code>$wrap</code>, <code>$esc()</code>,
            <code>$slider_enabled</code>, <code>$instance_id</code>, <code>$limit_visible</code>
          </p>
        </div>
      </div>
    <?php endif; ?>

    <div style="margin-top:.75rem;">
      <label><?=_e('Content Template (HTML + PHP)')?><br>
        <textarea id="cm-textarea" style="width:100%;min-height:70vh;padding:.5rem;margin-top:.4rem;border:1px solid var(--adam-border-2);border-radius:6px;"><?= htmlspecialchars($pref_content, ENT_QUOTES, 'UTF-8') ?></textarea>
        <textarea id="content-textarea" name="content" style="display:none;"><?= htmlspecialchars($pref_content, ENT_QUOTES, 'UTF-8') ?></textarea>
      </label>
    </div>
  </form>
</section>

<input type="hidden" id="editor-codemirror" checked>

<script>
  window.ADIWIRA = window.ADIWIRA || {};
  window.ADIWIRA_FORM_ID = 'layout-form';
</script>

<script src="/static/js/edit/codemirror.js"></script>
<script src="/static/js/edit/main-init.js"></script>

<script>
(function(){
  var form = document.getElementById('layout-form');
  var btn = document.getElementById('btn-save');
  var contentField = document.getElementById('content-textarea');
  var nonceField = document.querySelector('[name="save_nonce"]');

  function getCMValue(){
    if (window.ADIWIRA && window.ADIWIRA.cm && typeof window.ADIWIRA.cm.getValue === 'function') {
      return window.ADIWIRA.cm.getValue();
    }
    var cmHelper = window.ADIWIRA && window.ADIWIRA.codemirror;
    if (cmHelper && typeof cmHelper.getInstance === 'function') {
      var cm = cmHelper.getInstance();
      if (cm && typeof cm.getValue === 'function') return cm.getValue();
    }
    var ta = document.getElementById('cm-textarea');
    return ta ? ta.value : '';
  }

  function syncContent(){
    contentField.value = getCMValue();
  }

  async function submitAjax(){
    syncContent();

    var oldLabel = btn ? btn.textContent : '';
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Menyimpan...';
    }

    try {
      var res = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });

      var data = await res.json().catch(function(){
        return { ok: false, errors: ['Respons server tidak valid.'] };
      });

      if (!res.ok || !data.ok) {
        var errors = Array.isArray(data.errors) && data.errors.length
          ? data.errors
          : [data.error || data.message || 'Gagal menyimpan.'];
        errors.filter(Boolean).forEach(function(msg){
          if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
            window.NewNotifToast.show({ type: 'error', title: 'Gagal', message: String(msg) });
          } else { alert(String(msg)); }
        });
        return;
      }

      if (data.new_save_nonce && nonceField) {
        nonceField.value = data.new_save_nonce;
      }

      if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
        window.NewNotifToast.show({ type: 'success', title: <?= json_encode(__('Success')) ?>, message: data.message || 'Layout berhasil disimpan.' });
      }

      if (data.redirect) {
        window.location.href = data.redirect;
      }
    } catch (err) {
      if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
        window.NewNotifToast.show({ type: 'error', title: <?= json_encode(__('Network')) ?>, message: 'Terjadi gangguan jaringan.' });
      } else { alert('Gagal: ' + err.message); }
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = oldLabel || '<?= $isNew ? __('Save') : __('Save Changes') ?>';
      }
    }
  }

  if (form) {
    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      syncContent();
      if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
        window.NewNotifConfirm.warning({
          title: '<?=__('Save layout')?>',
          message: '<?=__('Changes will be saved. Continue?')?>',
          confirmText: <?= json_encode(__('Yes, save')) ?>,
          cancelText: <?= json_encode(__('Cancel')) ?>
        }).then(function(ok){
          if (!ok) return;
          submitAjax();
        });
      } else {
        if (!window.confirm('<?=__('Save changes to this layout?')?>')) return;
        submitAjax();
      }
    });
  }

  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      if (form) form.requestSubmit();
    }
  });
})();
</script>
