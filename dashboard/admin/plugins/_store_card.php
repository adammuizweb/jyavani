<?php
declare(strict_types=1);

function plugin_store_card_html(array $plugin, array $installedPlugins, string $base, string $csrfToken): string
{
    $name = (string)$plugin['name'];
    $title = (string)($plugin['title'] ?? $name);
    $version = (string)($plugin['version'] ?? '');
    $installed = $installedPlugins[$name] ?? null;
    $installedVersion = is_array($installed) ? (string)($installed['version'] ?? '') : '';
    $hasUpdate = is_array($installed) && $installedVersion !== '' && version_compare($version, $installedVersion, '>');
    $icon = (string)($plugin['icon'] ?? '');
    $firstLetter = mb_strtoupper(mb_substr($title, 0, 1));
    $colors = ['#6366f1','#ec4899','#14b8a6','#f97316','#8b5cf6','#ef4444','#06b6d4','#84cc16','#d946ef','#0ea5e9'];
    $color = $colors[abs(crc32($name)) % count($colors)];
    ob_start();
    ?>
    <article class="plugin-card" data-plugin="<?= h($name) ?>">
      <div class="plugin-card-head">
        <?php if ($icon !== ''): ?>
          <img class="plugin-icon" src="<?= h($icon) ?>" alt="" loading="lazy" width="48" height="48">
        <?php else: ?>
          <span class="plugin-icon-placeholder" style="background:<?= h($color) ?>"><?= h($firstLetter) ?></span>
        <?php endif; ?>
        <div>
          <div class="plugin-card-title"><?= h($title) ?></div>
          <div class="plugin-card-meta">
            <span>v<?= h($version ?: '—') ?></span>
            <?php if (($plugin['php_required'] ?? '') !== ''): ?><span class="badge-php">PHP <?= h((string)$plugin['php_required']) ?></span><?php endif; ?>
            <?php if (($plugin['author'] ?? '') !== ''): ?><span><?= _e('by') ?> <?= h((string)$plugin['author']) ?></span><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="plugin-card-body">
        <?php if (($plugin['description'] ?? '') !== ''): ?><div class="plugin-card-desc"><?= h(mb_strimwidth((string)$plugin['description'], 0, 160, '…')) ?></div><?php endif; ?>
      </div>
      <div class="plugin-card-actions">
        <?php if (is_array($installed)): ?>
          <?php if ($hasUpdate): ?>
            <a href="<?= h($base) ?>/?page=admin/plugins/index" class="btn btn-sm btn-update"><?= _e('Update Available') ?></a>
          <?php else: ?>
            <span class="btn btn-sm btn-disabled" aria-disabled="true"><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px']) ?> <?= _e('Installed') ?></span>
          <?php endif; ?>
        <?php else: ?>
          <form method="post" class="plugin-install-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="plugin" value="<?= h($name) ?>">
            <input type="hidden" name="action" value="">
            <button type="submit" class="btn btn-sm btn-outline" data-install-action="install" data-plugin-title="<?= h($title) ?>"><?= _e('Install') ?></button>
            <button type="submit" class="btn btn-sm btn-primary" data-install-action="install_activate" data-plugin-title="<?= h($title) ?>"><?= _e('Install & Activate') ?></button>
          </form>
        <?php endif; ?>
        <?php if (($plugin['plugin_uri'] ?? '') !== ''): ?><a href="<?= h((string)$plugin['plugin_uri']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline"><?= _e('Detail') ?></a><?php endif; ?>
      </div>
    </article>
    <?php
    return (string)ob_get_clean();
}
