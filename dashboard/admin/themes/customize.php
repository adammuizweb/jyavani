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

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$selfUrl = $base . '/?page=admin/themes/customize';

$folder = get_active_theme_folder($pdo);
$fields = theme_customizer_fields($folder);
$hasFields = $fields['logo'] || $fields['nav_menu'] || !empty($fields['controls']);

// ─── Save ───
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['tc_save'])) {
    if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
        return;
    }

    $mods = theme_mods_all($pdo, $folder);

    if ($fields['logo']) {
        $logo = trim((string)($_POST['logo'] ?? ''));
        if ($logo === '' || preg_match('#^/(static|views|private)/#', $logo) || preg_match('#^https?://#i', $logo)) {
            $mods['logo'] = $logo;
        }
    }

    if ($fields['nav_menu']) {
        $slug = trim((string)($_POST['nav_menu'] ?? ''));
        $mods['nav_menu'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $slug);
    }

    foreach ($fields['controls'] as $ctl) {
        $mods['control_' . $ctl] = !empty($_POST['control_' . $ctl]);
    }

    theme_mods_save($pdo, $folder, $mods);
    adiwira_redirect_with_flash($selfUrl, 'success', __('Customization saved.'));
    return;
}

$mods = theme_mods_all($pdo, $folder);
$menus = function_exists('menu_get_all') ? menu_get_all($pdo) : [];
$currentMenu = (string)($mods['nav_menu'] ?? '');
$currentLogo = (string)($mods['logo'] ?? '');

$controlLabels = [
    'search' => __('Search box'),
    'lang'   => __('Language selector'),
    'theme'  => __('Light/Dark selector'),
];
?>

<div class="tc-wrap" style="max-width:760px;">
  <div class="tc-header" style="margin-bottom:1rem;">
    <h2 style="margin:0 0 .25rem;"><?= __('Customize') ?> — <?= htmlspecialchars($folder, ENT_QUOTES) ?></h2>
    <p class="muted"><?= __('Minimal theme customization. Fields are declared by the active theme via theme.json.') ?></p>
  </div>

  <?php foreach ($page_toasts as $t): ?>
    <div class="adam-alert <?= htmlspecialchars($t['type'] ?? 'success', ENT_QUOTES) ?> auto-dismiss"><?= htmlspecialchars($t['message'] ?? '', ENT_QUOTES) ?></div>
  <?php endforeach; ?>

  <?php if (!$hasFields): ?>
    <div class="adam-alert warning">
      <?= __('The active theme does not declare any customizer fields.') ?>
      <code>theme.json → "customizer": {"logo": true, "nav_menu": true, "controls": ["search","lang","theme"]}</code>
    </div>
  <?php else: ?>
    <form method="post" style="display:flex; flex-direction:column; gap:1.25rem;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
      <input type="hidden" name="tc_save" value="1">

      <?php if ($fields['logo']): ?>
        <div class="tc-field">
          <label style="display:block; font-weight:600; margin-bottom:.35rem;"><?= __('Logo') ?></label>
          <input type="text" name="logo" id="tc-logo" value="<?= htmlspecialchars($currentLogo, ENT_QUOTES) ?>"
                 placeholder="/static/img/logo.svg" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit;">
          <small class="muted"><?= __('Image URL or path. Leave empty to use the theme default brand.') ?></small>
          <div id="tc-logo-preview" style="margin-top:.6rem; <?= $currentLogo === '' ? 'display:none;' : '' ?>">
            <img src="<?= htmlspecialchars($currentLogo, ENT_QUOTES) ?>" alt="" style="max-height:56px; max-width:220px; border-radius:6px; background:rgba(127,127,127,.1); padding:6px;">
          </div>
        </div>
      <?php endif; ?>

      <?php if ($fields['nav_menu']): ?>
        <div class="tc-field">
          <label style="display:block; font-weight:600; margin-bottom:.35rem;"><?= __('Navigation Menu') ?></label>
          <select name="nav_menu" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit;">
            <option value=""><?= __('Theme default (primary)') ?></option>
            <?php foreach ($menus as $m): ?>
              <?php $slug = (string)($m['slug'] ?? ''); ?>
              <option value="<?= htmlspecialchars($slug, ENT_QUOTES) ?>" <?= $currentMenu === $slug ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)($m['name'] ?? $slug), ENT_QUOTES) ?> (<?= htmlspecialchars($slug, ENT_QUOTES) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <small class="muted"><?= __('Which menu renders in the header navigation slot.') ?></small>
        </div>
      <?php endif; ?>

      <?php if (!empty($fields['controls'])): ?>
        <div class="tc-field">
          <label style="display:block; font-weight:600; margin-bottom:.35rem;"><?= __('Header Controls') ?></label>
          <?php foreach ($fields['controls'] as $ctl): ?>
            <?php $on = array_key_exists('control_' . $ctl, $mods) ? !empty($mods['control_' . $ctl]) : true; ?>
            <label style="display:inline-flex; align-items:center; gap:.4rem; margin-right:1rem; font-weight:400;">
              <input type="checkbox" name="control_<?= htmlspecialchars($ctl, ENT_QUOTES) ?>" value="1" <?= $on ? 'checked' : '' ?>>
              <?= htmlspecialchars($controlLabels[$ctl] ?? $ctl, ENT_QUOTES) ?>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div>
        <button type="submit" class="btn btn-primary"><?= __('Save') ?></button>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
(function(){
  const inp = document.getElementById('tc-logo');
  const prev = document.getElementById('tc-logo-preview');
  if (!inp || !prev) return;
  inp.addEventListener('input', function(){
    const v = inp.value.trim();
    if (v === '') { prev.style.display = 'none'; return; }
    prev.querySelector('img').src = v;
    prev.style.display = 'block';
  });
})();
</script>
