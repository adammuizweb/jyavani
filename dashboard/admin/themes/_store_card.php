<?php
declare(strict_types=1);

function theme_store_physical_manifest(string $path): ?array
{
    $manifestPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'theme.json';
    if (!is_file($manifestPath) || is_link($manifestPath)) return null;
    $raw = @file_get_contents($manifestPath);
    if (!is_string($raw)) return null;
    $manifest = json_decode($raw, true);
    if (!is_array($manifest) || (array_key_exists('version', $manifest) && !is_string($manifest['version']))) return null;
    return $manifest;
}

function theme_store_installed_map(PDO $pdo): array
{
    $installed = [];
    foreach (get_registered_themes($pdo) as $theme) {
        $folder = (string)($theme['folder_name'] ?? '');
        if (preg_match('/\A[a-zA-Z0-9_-][a-zA-Z0-9._-]{0,127}\z/', $folder) !== 1 || in_array($folder, ['.', '..'], true)) continue;
        $path = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($path) || is_link($path)) continue;
        $installed[$folder] = theme_store_physical_manifest($path) ?? [];
    }
    foreach (is_dir(VIEWS_BASE) ? (scandir(VIEWS_BASE) ?: []) : [] as $folder) {
        if ($folder === '.' || $folder === '..' || isset($installed[$folder])) continue;
        if (preg_match('/\A[a-zA-Z0-9_-][a-zA-Z0-9._-]{0,127}\z/', $folder) !== 1) continue;
        $path = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($path) || is_link($path)) continue;
        $manifest = theme_store_physical_manifest($path);
        if ($manifest !== null) $installed[$folder] = $manifest;
    }
    return $installed;
}

function theme_store_card_html(array $theme, array $installedThemes, string $base, string $csrfToken): string
{
    $name = (string)$theme['name'];
    $title = (string)($theme['title'] ?? $name);
    $version = (string)($theme['version'] ?? '');
    $installed = $installedThemes[$name] ?? null;
    $installedVersion = is_array($installed) ? (string)($installed['version'] ?? '') : '';
    $hasUpdate = is_array($installed) && $installedVersion !== '' && version_compare($version, $installedVersion, '>');
    $screenshot = (string)($theme['screenshot'] ?? '');
    ob_start();
    ?>
    <article class="theme-card" data-theme="<?= h($name) ?>">
      <div class="theme-card-shot">
        <?php if ($screenshot !== ''): ?><img src="<?= h($screenshot) ?>" alt="<?= h($title) ?>" loading="lazy">
        <?php else: ?><span class="theme-card-placeholder"><?= h(mb_strtoupper(mb_substr($title, 0, 1))) ?></span><?php endif; ?>
      </div>
      <div class="theme-card-body">
        <div class="theme-card-title"><?= h($title) ?></div>
        <?php if (($theme['description'] ?? '') !== ''): ?><div class="theme-card-desc"><?= h(mb_strimwidth((string)$theme['description'], 0, 160, '…')) ?></div><?php endif; ?>
        <div class="theme-card-meta">
          <span>v<?= h($version ?: '—') ?></span>
          <?php if (($theme['php_required'] ?? '') !== ''): ?><span class="badge-php">PHP <?= h((string)$theme['php_required']) ?></span><?php endif; ?>
          <?php if (($theme['author'] ?? '') !== ''): ?><span><?= _e('by') ?> <?= h((string)$theme['author']) ?></span><?php endif; ?>
          <?php if (($theme['avg_rating'] ?? 0) > 0): ?><span>★ <?= h(number_format((float)$theme['avg_rating'], 1)) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="theme-card-actions">
        <?php if (is_array($installed)): ?>
          <?php if ($hasUpdate): ?><a href="<?= h($base) ?>/?page=admin/themes/assign" class="btn btn-sm btn-update"><?= _e('Update Available') ?></a>
          <?php else: ?><span class="btn btn-sm btn-disabled" aria-disabled="true"><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px']) ?> <?= _e('Installed') ?></span><?php endif; ?>
        <?php else: ?>
          <form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="install"><input type="hidden" name="theme" value="<?= h($name) ?>"><button type="submit" class="btn btn-sm btn-primary" data-install-theme data-theme-title="<?= h($title) ?>"><?= _e('Install') ?></button></form>
        <?php endif; ?>
        <?php if (($theme['homepage'] ?? '') !== ''): ?><a href="<?= h((string)$theme['homepage']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline"><?= _e('Detail') ?></a><?php endif; ?>
      </div>
    </article>
    <?php
    return (string)ob_get_clean();
}
