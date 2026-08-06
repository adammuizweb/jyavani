<?php
$title = $title ?? 'Search';
$placeholder = $placeholder ?? 'Search articles...';
$button = $button ?? 'Search';
$action = apply_filters('widget_search_action', '/', $__pdo ?? null);
?>
<div class="w-box w-search">
  <div class="w-title"><?= widget_h($title) ?></div>
  <form method="get" action="<?= widget_h($action) ?>" class="w-search-form">
    <input type="text" name="s" value="<?= widget_h($_GET['s'] ?? '') ?>" placeholder="<?= widget_h($placeholder) ?>">
    <button type="submit"><?= widget_h($button) ?></button>
  </form>
</div>
