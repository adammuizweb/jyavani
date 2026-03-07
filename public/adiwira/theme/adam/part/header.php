<?php
// /adiwira/theme/adam/part/header.php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<header id="adam-header" class="adam-header">
  <div class="adam-header-inner">
    <div style="display:flex; align-items:center; gap:12px;">
      <button id="adam-burger" class="adam-burger" aria-label="Toggle menu" title="Toggle menu">☰</button>
      <h1 class="adam-brand">Adiwira — <small class="adam-sub">Dashboard</small></h1>
    </div>

    <div class="adam-user" style="display:none; align-items:center; gap:10px;">
      <span class="adam-user-email"><?= htmlspecialchars($user['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
      <a class="adam-logout" href="<?= htmlspecialchars(dirname($_SERVER['SCRIPT_NAME']) . '/logout.php', ENT_QUOTES, 'UTF-8') ?>">Logout</a>
    </div>
    <?php // di /adiwira/theme/adam/part/header.php, di area kanan atau kiri header ?>
<button id="adam-panel-toggle" class="adam-button" type="button"
        aria-controls="adam-panel" aria-expanded="true" title="Tampilkan/sembunyikan panel">
  Panel
</button>

  </div>
</header>
