<?php
declare(strict_types=1);

// /adiwira/admin/themes/customize.php — Theme Customizer (lite)
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$selfUrl = $base . '/?page=admin/themes/customize';

$folder = get_active_theme_folder($pdo);
$sections = theme_customizer_fields($folder);
$hasFields = !empty($sections);

$menus = function_exists('menu_get_all') ? menu_get_all($pdo) : [];
$zones = function_exists('sidebar_zone_get_all') ? sidebar_zone_get_all($pdo) : [];
$mods = theme_mods_all($pdo, $folder);

// Field sanitizers for saving
$field_sanitizers = [
    'image'        => fn($v) => preg_match('#^(/|https?://)#i', trim((string)$v)) ? trim((string)$v) : '',
    'menu'         => fn($v) => preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string)$v)),
    'sidebar_zone' => fn($v) => preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string)$v)),
    'text'         => fn($v) => trim((string)$v),
    'textarea'     => fn($v) => trim((string)$v),
    'toggle'       => fn($v) => (bool)$v,
];

// ─── Save ───
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['tc_save'])) {
    if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
        return;
    }

    foreach ($sections as $sectionKey => $section) {
        foreach ($section['fields'] as $key => $def) {
            $type = $def['type'];
            $raw = $_POST[$key] ?? '';
            $mods[$key] = $field_sanitizers[$type]($raw);
        }
    }

    // Remove keys that are no longer declared (schema changed / renamed)
    $validKeys = [];
    foreach ($sections as $section) {
        foreach ($section['fields'] as $key => $def) {
            $validKeys[$key] = true;
        }
    }
    $mods = array_intersect_key($mods, $validKeys);

    theme_mods_save($pdo, $folder, $mods);
    adiwira_redirect_with_flash($selfUrl, 'success', __('Customization saved.'));
    return;
}
?>

<div class="tc-wrap" style="max-width:900px;">
  <div class="tc-header" style="margin-bottom:1.25rem;">
    <h2 style="margin:0 0 .25rem;"><?= __('Customize') ?> — <?= h($folder) ?></h2>
    <p class="muted"><?= __('Edit the active theme options below. Only fields declared by this theme in theme.json are shown.') ?></p>
  </div>

  <?php foreach ($page_toasts as $t): ?>
    <div class="adam-alert <?= h($t['type'] ?? 'success') ?> auto-dismiss"><?= h((string)($t['message'] ?? '')) ?></div>
  <?php endforeach; ?>

  <?php if (!$hasFields): ?>
    <div class="adam-alert warning">
      <?= __('The active theme does not declare any customizer fields.') ?>
    </div>
  <?php else: ?>
    <form method="post" style="display:flex; flex-direction:column; gap:1.5rem;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="tc_save" value="1">

      <?php foreach ($sections as $sectionKey => $section): ?>
        <div class="tc-panel" style="background:var(--adam-card, transparent); border:1px solid rgba(127,127,127,.18); border-radius:8px; padding:1.25rem;">
          <h3 style="margin:0 0 1rem; font-size:1.1rem; border-bottom:1px solid rgba(127,127,127,.18); padding-bottom:.5rem;"><?= h($section['label']) ?></h3>

          <div style="display:grid; grid-template-columns: 1fr; gap:1.25rem;">
            <?php foreach ($section['fields'] as $key => $def): ?>
              <div class="tc-field">
                <label style="display:block; font-weight:600; font-size:.9rem; margin-bottom:.4rem;"><?= h($def['label']) ?></label>

                <?php if ($def['type'] === 'image'): ?>
                  <input type="text" name="<?= h($key) ?>" id="tc-<?= h($key) ?>" value="<?= h((string)($mods[$key] ?? '')) ?>"
                         placeholder="/static/img/logo.svg" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; box-sizing:border-box;">
                  <div class="tc-image-preview" id="tc-preview-<?= h($key) ?>" style="margin-top:.6rem; <?= empty($mods[$key]) ? 'display:none;' : '' ?>">
                    <img src="<?= h((string)($mods[$key] ?? '')) ?>" alt="" style="max-height:56px; max-width:220px; border-radius:6px; background:rgba(127,127,127,.1); padding:6px;">
                  </div>

                <?php elseif ($def['type'] === 'menu'): ?>
                  <select name="<?= h($key) ?>" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit;">
                    <option value=""><?= __('Theme default') ?></option>
                    <?php foreach ($menus as $m): ?>
                      <?php $slug = (string)($m['slug'] ?? ''); ?>
                      <option value="<?= h($slug) ?>" <?= ((string)($mods[$key] ?? '') === $slug) ? 'selected' : '' ?>><?= h((string)($m['name'] ?? $slug)) ?> (<?= h($slug) ?>)</option>
                    <?php endforeach; ?>
                  </select>

                <?php elseif ($def['type'] === 'sidebar_zone'): ?>
                  <select name="<?= h($key) ?>" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit;">
                    <option value=""><?= __('None') ?></option>
                    <?php foreach ($zones as $z): ?>
                      <?php $slug = (string)($z['slug'] ?? ''); ?>
                      <option value="<?= h($slug) ?>" <?= ((string)($mods[$key] ?? '') === $slug) ? 'selected' : '' ?>><?= h((string)($z['name'] ?? $slug)) ?> (<?= h($slug) ?>)</option>
                    <?php endforeach; ?>
                  </select>

                <?php elseif ($def['type'] === 'textarea'): ?>
                  <textarea name="<?= h($key) ?>" rows="3" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; font:inherit; box-sizing:border-box;"><?= h((string)($mods[$key] ?? '')) ?></textarea>

                <?php elseif ($def['type'] === 'text'): ?>
                  <input type="text" name="<?= h($key) ?>" value="<?= h((string)($mods[$key] ?? '')) ?>"
                         style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; box-sizing:border-box;">

                <?php elseif ($def['type'] === 'toggle'): ?>
                  <?php $on = (bool)($mods[$key] ?? true); ?>
                  <label style="display:inline-flex; align-items:center; gap:.5rem; font-weight:400; cursor:pointer;">
                    <input type="checkbox" name="<?= h($key) ?>" value="1" <?= $on ? 'checked' : '' ?>
                           style="width:18px; height:18px;">
                    <span class="muted"><?= __('Enabled') ?></span>
                  </label>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div>
        <button type="submit" class="btn btn-primary"><?= __('Save Customization') ?></button>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
(function(){
  document.querySelectorAll('input[type="text"][id^="tc-"]').forEach(function(inp){
    const preview = document.getElementById('tc-preview-' + inp.id.replace('tc-',''));
    if (!preview) return;
    inp.addEventListener('input', function(){
      const v = inp.value.trim();
      if (v === '') { preview.style.display = 'none'; return; }
      preview.querySelector('img').src = v;
      preview.style.display = 'block';
    });
  });
})();
</script>
