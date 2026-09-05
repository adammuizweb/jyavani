<?php
// /adiwira/theme/adiwira/part/header.php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<?php do_action('admin_header'); ?>
<header id="adam-header" class="adam-header">
  <div class="adam-header-inner">
    <div style="display:flex; align-items:center; gap:12px;">
      <button id="adam-burger" class="adam-burger" aria-label="<?=_e('Toggle menu')?>" title="<?=_e('Toggle menu')?>">☰</button>
      <h1 class="adam-brand">
        <svg class="adam-brand-logo" viewBox="0 0 456 517" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M418,.12c1.33.02,2.67-.04,4,0l-.44,4.06c-25,67.24-48.15,135.25-75.38,201.62-39.14,95.42-88.12,186.46-148.51,269.55-1.14,1.57-2.51,8.11-3.66,3.27s-1.26-9.76-2.51-14.51c-10.58-40.08-53.89-70.36-81.02-100.98-39.91-45.03-100.81-125.96-42.01-180.04,27.19-25.01,69.92-26.68,99.53-4.97V54.12H60.5c-19.86,0-47.28-22.29-55.48-38.73C2.94,11.21.47,4.69,0,.12h256c-2.15,4.32-8.05,6.56-10.59,9.93-5.89,7.85-12.73,18.57-13.4,34.58l-.17,185.33c-13.23,5.36-25.94,14.52-34.25,26.25-3.57,5.03-5.79,12.39-8.75,16.25-.6.78-1.61,2.56-2.83,1.65-.96-.72-5.54-18.68-7.73-22.73-5.67-10.49-19.05-25.97-30.6-30.45-25.49-9.87-49.07,6.74-44.44,34.44s53.52,79.92,74.75,100.25c2.78,2.67,14.77,14.05,17.05,14.96,4.23,1.69,4.29-.2,5.51-1.91,35.65-49.89,62.37-117.52,85.92-174.6,10.84-26.29,20.13-57.12,32.53-81.97,16.28-32.62,40.03-66.98,67.53-90.97,9.58-8.36,20.29-15.03,31.47-21.03Z" fill="url(#brandGrad)"/>
          <path d="M418,.12c-11.18,5.99-21.89,12.66-31.47,21.03-27.49,23.99-51.25,58.35-67.53,90.97L358.14.26c19.93,0,39.94-.48,59.86-.14Z" fill="#cfab57"/>
          <defs>
            <linearGradient id="brandGrad" x1="0" y1="0" x2="456" y2="517" gradientUnits="userSpaceOnUse">
              <stop stop-color="#f97316"/>
              <stop offset="1" stop-color="#dc2626"/>
            </linearGradient>
          </defs>
        </svg>
        <span class="adam-brand-text">Adiwira <small class="adam-sub"><?=_e('Dashboard')?></small></span>
      </h1>
    </div>

    <div class="adam-user" style="display:none; align-items:center; gap:10px;">
      <span class="adam-user-email"><?= htmlspecialchars($user['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
      <a class="adam-logout" href="<?= htmlspecialchars(ADMIN_BASE_PATH . '/logout.php', ENT_QUOTES, 'UTF-8') ?>"><?=_e('Logout')?></a>
    </div>
    <?php
    $headerActor = function_exists('authorization_actor') ? authorization_actor($pdo) : null;
    $canCheckUpdates = $headerActor !== null && $headerActor['is_site_owner'] === true
      && function_exists('current_user_can') && current_user_can($pdo, 'core.updates.manage');
    ?>
    <div style="display:flex;align-items:center;gap:6px">
<?php if ($canCheckUpdates): ?>
<button id="adam-update-bell" class="adam-bell" type="button"
        title="<?=_e('Check for updates')?>" aria-label="<?=_e('Updates')?>">
  <svg class="adam-bell-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
  </svg>
  <span id="adam-bell-badge" class="adam-bell-badge" style="display:none">0</span>
</button>
<div id="adam-update-dropdown" class="adam-update-dd" style="display:none">
  <div class="adam-update-dd-header"><?=_e('Updates')?></div>
  <div class="adam-update-dd-body" id="adam-update-dd-body">
    <div class="adam-update-dd-empty"><?=_e('Checking...')?></div>
  </div>
  <div class="adam-update-dd-footer">
    <button type="button" class="adam-button" id="adam-update-refresh"><?=_e('Check for Updates')?></button>
  </div>
</div>
<?php endif; ?>

<button id="adam-panel-toggle" class="adam-button" type="button"
        aria-controls="adam-panel" aria-expanded="true" title="<?=_e('Show/hide panel')?>">
  <?=_e('Panel')?>
</button>
    </div>

  </div>
</header>
