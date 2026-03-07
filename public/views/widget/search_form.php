<?php
$placeholder = $placeholder ?? 'Cari...';
$button = $button ?? 'Cari';
?>
<div class="w-box w-search">
  <div class="w-title">Cari</div>
  <form method="get" action="/" class="w-search-form">
    <input type="text" name="s" value="<?= widget_h($_GET['s'] ?? '') ?>" placeholder="<?= widget_h($placeholder) ?>">
    <button type="submit"><?= widget_h($button) ?></button>
  </form>
</div>
