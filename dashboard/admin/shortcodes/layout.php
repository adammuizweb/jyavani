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

$layoutDir = (defined('PUBLIC_PATH') ? realpath(PUBLIC_PATH . '/views/partials/shortcodes/post_cat') : realpath(__DIR__ . '/../../../public/views/partials/shortcodes/post_cat'));
if (!$layoutDir || !is_dir($layoutDir)) {
    echo '<section class="adam-card"><p>' . __('Layout directory not found.') . '</p></section>';
    return;
}

$fileName = (string)($_GET['file'] ?? $_POST['file'] ?? '');
$isNew = $fileName === '';
$filePath = '';

if (!$isNew) {
    $cleanName = preg_replace('/[^a-z0-9_\-\.]/i', '', basename($fileName));
    if (!str_ends_with($cleanName, '.php')) {
        $cleanName .= '.php';
    }
    $filePath = $layoutDir . DIRECTORY_SEPARATOR . $cleanName;
    $realPath = realpath($filePath);

    if (!$realPath || strpos($realPath, $layoutDir) !== 0 || !is_file($realPath)) {
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
} else {
    $pref_content = file_get_contents($filePath);
    $pref_layout_name = pathinfo($fileName, PATHINFO_FILENAME);
}

$save_nonce = bin2hex(random_bytes(12));
$_SESSION['sc_layout_nonce'] = $save_nonce;

$preset_list_url = $base . '/?page=admin/shortcodes/index&tab=presets';

// --- Connected Presets (presets that use THIS layout) ---
$connectedPresets = [];
$allPresets = [];
if (!$isNew) {
    try {
        $stmt = $pdo->prepare("SELECT id, title, slug, meta FROM posts WHERE type = 'sc_preset' AND is_deleted = 0 ORDER BY title ASC");
        $stmt->execute();
        $allPresets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allPresets as $p) {
            $meta = json_decode((string)($p['meta'] ?? '{}'), true);
            if (is_array($meta) && ($meta['layout'] ?? '') === $pref_layout_name) {
                $connectedPresets[] = $p;
            }
        }
    } catch (Throwable $e) {}
} else {
    try {
        $stmt = $pdo->prepare("SELECT id, title, slug FROM posts WHERE type = 'sc_preset' AND is_deleted = 0 ORDER BY title ASC");
        $stmt->execute();
        $allPresets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// --- Starter Templates ---
$tplClean = '';

$tplList = '<div class="pcat__list">
<?php foreach ($items as $it): ?>
  <?php
    $title = $esc($it[\'title\'] ?? \'\');
    $url = $esc($it[\'url\'] ?? \'#\');
    $desc = $esc($it[\'desc\'] ?? \'\');
    $date = $esc($it[\'date_label\'] ?? \'\');
  ?>
  <article class="pcat__item">
    <a class="pcat__card" href="<?= $url ?>">
      <h3 class="pcat__title"><?= $title ?></h3>
      <?php if ($desc !== \'\'): ?>
        <p class="pcat__desc"><?= $desc ?></p>
      <?php endif; ?>
      <time class="pcat__date" datetime="<?= $esc($it[\'date_iso\'] ?? \'\') ?>"><?= $date ?></time>
    </a>
  </article>
<?php endforeach; ?>
</div>';

$tplCards = '<?php if ($wrap): ?>
<div class="pcat pcat--<?= $esc($layout) ?><?= $class_prefix !== \'\' ? \' \' . $esc($class_prefix) : \'\' ?>">
<?php endif; ?>
  <div class="pcat__track" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;">
    <?php foreach ($items as $it): ?>
      <?php
        $title = $esc($it[\'title\'] ?? \'\');
        $url = $esc($it[\'url\'] ?? \'#\');
        $thumb = trim((string)($it[\'thumb\'] ?? \'\'));
        $desc = $esc($it[\'desc\'] ?? \'\');
        $date = $esc($it[\'date_label\'] ?? \'\');
      ?>
      <article class="pcat__item">
        <a class="pcat__card" href="<?= $url ?>">
          <div class="pcat__media">
            <?php if ($thumb !== \'\'): ?>
              <img class="pcat__img" src="<?= $esc($thumb) ?>" alt="" loading="lazy">
            <?php else: ?>
              <div class="pcat__img pcat__img--placeholder" style="background:var(--adam-surface-3,#e0e0e0);aspect-ratio:16/9;border-radius:6px;"></div>
            <?php endif; ?>
          </div>
          <div class="pcat__body">
            <h3 class="pcat__title"><?= $title ?></h3>
            <?php if ($desc !== \'\'): ?>
              <p class="pcat__desc"><?= $desc ?></p>
            <?php endif; ?>
            <time class="pcat__date" datetime="<?= $esc($it[\'date_iso\'] ?? \'\') ?>"><?= $date ?></time>
          </div>
        </a>
      </article>
    <?php endforeach; ?>
  </div>
<?php if ($wrap): ?>
</div>
<?php endif; ?>';

$tplCard2 = '<?php if ($wrap): ?>
<div class="pcat pcat--<?= $esc($layout) ?><?= $class_prefix !== \'\' ? \' \' . $esc($class_prefix) : \'\' ?>">
<?php endif; ?>
  <div class="pcat__track" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.75rem;">
    <?php foreach ($items as $it): ?>
      <?php
        $title = $esc($it[\'title\'] ?? \'\');
        $url = $esc($it[\'url\'] ?? \'#\');
        $thumb = trim((string)($it[\'thumb\'] ?? \'\'));
        $desc = $esc($it[\'desc\'] ?? \'\');
      ?>
      <article class="pcat__item">
        <a class="pcat__card" href="<?= $url ?>" style="display:flex;flex-direction:column;gap:.5rem;text-decoration:none;color:inherit;border:1px solid var(--adam-border-soft,#ddd);border-radius:8px;overflow:hidden;">
          <div class="pcat__media" style="position:relative;padding-top:56.25%;overflow:hidden;">
            <?php if ($thumb !== \'\'): ?>
              <img class="pcat__img" src="<?= $esc($thumb) ?>" alt="" loading="lazy" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
              <div class="pcat__img pcat__img--placeholder" style="position:absolute;top:0;left:0;width:100%;height:100%;background:linear-gradient(135deg,var(--adam-accent,#4361ee),#7c3aed);opacity:.15;"></div>
            <?php endif; ?>
          </div>
          <div class="pcat__body" style="padding:.6rem .75rem .75rem;">
            <h3 class="pcat__title" style="margin:0 0 .3rem;font-size:1rem;"><?= $title ?></h3>
            <?php if ($desc !== \'\'): ?>
              <p class="pcat__desc" style="margin:0;font-size:.85rem;color:var(--adam-muted,#888);"><?= $desc ?></p>
            <?php endif; ?>
          </div>
        </a>
      </article>
    <?php endforeach; ?>
  </div>
<?php if ($wrap): ?>
</div>
<?php endif; ?>';

$tplSlider = '<?php if ($wrap): ?>
<div class="pcat pcat--<?= $esc($layout) ?> pcat--slider" id="<?= $esc($instance_id) ?>" data-slider data-slider-visible="<?= (int)$limit_visible ?>">
<?php endif; ?>
  <div class="pcat__track" style="display:flex;overflow-x:auto;scroll-snap-type:x mandatory;gap:1rem;padding:.5rem 0;">
    <?php foreach ($items as $i => $it): ?>
      <?php
        $title = $esc($it[\'title\'] ?? \'\');
        $url = $esc($it[\'url\'] ?? \'#\');
        $thumb = trim((string)($it[\'thumb\'] ?? \'\'));
        $desc = $esc($it[\'desc\'] ?? \'\');
      ?>
      <article class="pcat__item" style="flex:0 0 calc(100% / <?= max(1,(int)$limit_visible) ?>);scroll-snap-align:start;min-width:250px;">
        <a class="pcat__card" href="<?= $url ?>" style="display:block;text-decoration:none;color:inherit;border:1px solid var(--adam-border-soft,#ddd);border-radius:8px;overflow:hidden;">
          <div class="pcat__media" style="aspect-ratio:16/9;overflow:hidden;">
            <?php if ($thumb !== \'\'): ?>
              <img class="pcat__img" src="<?= $esc($thumb) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
              <div class="pcat__img pcat__img--placeholder" style="width:100%;height:100%;background:linear-gradient(135deg,var(--adam-accent,#4361ee),#06b6d4);opacity:.12;"></div>
            <?php endif; ?>
          </div>
          <div class="pcat__body" style="padding:.6rem .75rem .75rem;">
            <h3 class="pcat__title" style="margin:0 0 .3rem;font-size:.95rem;"><?= $title ?></h3>
            <?php if ($desc !== \'\'): ?>
              <p class="pcat__desc" style="margin:0;font-size:.82rem;color:var(--adam-muted,#888);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= $desc ?></p>
            <?php endif; ?>
          </div>
        </a>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($slider_enabled): ?>
    <div class="pcat__nav" style="display:flex;justify-content:center;gap:.5rem;margin-top:.5rem;">
      <button class="pcat__prev" data-slider-prev style="padding:.3rem .7rem;border:1px solid var(--adam-border-soft,#ddd);border-radius:4px;cursor:pointer;">‹ Prev</button>
      <button class="pcat__next" data-slider-next style="padding:.3rem .7rem;border:1px solid var(--adam-border-soft,#ddd);border-radius:4px;cursor:pointer;">Next ›</button>
    </div>
  <?php endif; ?>
<?php if ($wrap): ?>
</div>
<?php endif; ?>';

$snippetForeach = '<?php foreach ($items as $it): ?>
  <?php
    $title = $esc($it[\'title\'] ?? \'\');
    $url = $esc($it[\'url\'] ?? \'#\');
  ?>
  <article class="pcat__item">
    <a class="pcat__card" href="<?= $url ?>">
      <h3><?= $title ?></h3>
    </a>
  </article>
<?php endforeach; ?>';

$snippetThumb = '<?php $thumb = trim((string)($it[\'thumb\'] ?? \'\')); ?>
<?php if ($thumb !== \'\'): ?>
  <img class="pcat__img" src="<?= $esc($thumb) ?>" alt="" loading="lazy">
<?php else: ?>
  <div class="pcat__img pcat__img--placeholder"></div>
<?php endif; ?>';

$snippetExcerpt = '<?php $desc = $esc($it[\'desc\'] ?? \'\'); ?>
<?php if ($desc !== \'\'): ?>
  <p class="pcat__desc"><?= $desc ?></p>
<?php endif; ?>';

$snippetDate = '<time class="pcat__date" datetime="<?= $esc($it[\'date_iso\'] ?? \'\') ?>"><?= $esc($it[\'date_label\'] ?? \'\') ?></time>';

$snippetKicker = '<?php if ($kicker !== \'\'): ?>
  <div class="pcat__kicker"><?= $esc($kicker) ?></div>
<?php endif; ?>';

$snippetSlider = '<div class="pcat__nav">
  <button class="pcat__prev" data-slider-prev>‹</button>
  <button class="pcat__next" data-slider-next>›</button>
</div>';

$snippetWrapper = '<?php if ($wrap): ?>
<div class="pcat pcat--<?= $esc($layout) ?><?= $class_prefix !== \'\' ? \' \' . $esc($class_prefix) : \'\' ?>">
<?php endif; ?>

  <!-- your content here -->

<?php if ($wrap): ?>
</div>
<?php endif; ?>';
?>
<style>
.lyo-wrap {
  display:flex;flex-direction:column;gap:1rem;
}
.lyo-header {
  display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;
}
.lyo-header h2 {
  margin:0;font-size:1.1rem;
}
.lyo-header-left {
  display:flex;align-items:center;gap:.75rem;
}
.lyo-header-right {
  display:flex;align-items:center;gap:.5rem;
}
.lyo-name-input {
  background:var(--adam-input-bg,var(--adam-bg,#fff));color:var(--adam-text,#333);
  border:1px solid var(--adam-border-soft,var(--adam-border,#ddd));
  border-radius:6px;padding:.4rem .6rem;font-size:.85rem;width:180px;
}
.lyo-name-input:focus { outline:2px solid var(--adam-accent,#4361ee);outline-offset:-1px; }

.lyo-templates {
  display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem;
}
.lyo-tpl-card {
  cursor:pointer;border:2px solid var(--adam-border-soft,var(--adam-border,#ddd));
  border-radius:8px;padding:1rem;text-align:center;
  background:var(--adam-surface-2,var(--adam-card,#fff));
  transition:border-color .15s,box-shadow .15s;
}
.lyo-tpl-card:hover {
  border-color:var(--adam-accent,#4361ee);box-shadow:0 2px 12px rgba(67,97,238,.12);
}
.lyo-tpl-card.selected {
  border-color:var(--adam-accent,#4361ee);background:var(--adam-surface-3,#f0f4ff);
}
.lyo-tpl-icon {
  font-size:2rem;margin-bottom:.4rem;display:block;
}
.lyo-tpl-title {
  font-weight:600;font-size:.9rem;margin-bottom:.2rem;
}
.lyo-tpl-desc {
  font-size:.78rem;color:var(--adam-muted,#888);line-height:1.4;
}

.lyo-editor-area {
  display:flex;gap:0;border:1px solid var(--adam-border-soft,var(--adam-border,#ddd));
  border-radius:8px;overflow:hidden;min-height:480px;
}
.lyo-editor-pane {
  flex:1 1 55%;min-width:0;display:flex;flex-direction:column;
  border-right:1px solid var(--adam-border-soft,var(--adam-border,#ddd));
}
.lyo-preview-pane {
  flex:1 1 45%;min-width:0;display:flex;flex-direction:column;
}
.lyo-pane-header {
  display:flex;align-items:center;justify-content:space-between;
  padding:.4rem .75rem;font-size:.8rem;font-weight:600;
  background:var(--adam-surface-2,var(--adam-card,#fafafa));
  border-bottom:1px solid var(--adam-border-soft,var(--adam-border,#ddd));
  color:var(--adam-muted,#888);
}
.lyo-pane-body {
  flex:1;overflow:auto;position:relative;
}
.lyo-pane-body .CodeMirror {
  height:100% !important;min-height:440px;
}

.lyo-toolbar {
  display:flex;flex-wrap:wrap;gap:4px;padding:.4rem .75rem;
  background:var(--adam-surface-2,var(--adam-card,#fafafa));
  border:1px solid var(--adam-border-soft,var(--adam-border,#ddd));
  border-radius:8px;
}
.lyo-toolbar button {
  font-size:.78rem;padding:.25rem .55rem;border:1px solid var(--adam-border-soft,var(--adam-border,#ddd));
  border-radius:4px;background:var(--adam-surface-3,var(--adam-card,#fff));
  color:var(--adam-text,#333);cursor:pointer;white-space:nowrap;
  transition:background .12s,border-color .12s;
}
.lyo-toolbar button:hover {
  background:var(--adam-accent,#4361ee);color:#fff;border-color:var(--adam-accent,#4361ee);
}
.lyo-toolbar button.danger:hover {
  background:#e74c3c;border-color:#e74c3c;
}

.lyo-variables {
  border:1px solid var(--adam-border-soft,var(--adam-border,#ddd));
  border-radius:8px;overflow:hidden;
}
.lyo-var-toggle {
  display:flex;align-items:center;justify-content:space-between;
  width:100%;padding:.6rem .9rem;font-size:.85rem;font-weight:600;
  background:var(--adam-surface-2,var(--adam-card,#fafafa));
  border:none;cursor:pointer;color:var(--adam-text,#333);text-align:left;
}
.lyo-var-toggle:hover { background:var(--adam-surface-3,#f0f0f0); }
.lyo-var-toggle .chevron { transition:transform .2s;display:inline-block; }
.lyo-var-toggle.open .chevron { transform:rotate(90deg); }
.lyo-var-body { padding:.75rem;display:none; }
.lyo-var-body.open { display:block; }
.lyo-var-table { width:100%;border-collapse:collapse;font-size:.82rem; }
.lyo-var-table th,.lyo-var-table td {
  padding:.35rem .6rem;border-bottom:1px solid var(--adam-border-soft,var(--adam-border,#ddd));
  text-align:left;vertical-align:top;
}
.lyo-var-table th { font-weight:600;color:var(--adam-muted,#888);font-size:.78rem;text-transform:uppercase; }

.lyo-error-banner {
  padding:.5rem .75rem;font-size:.82rem;
  background:#fef0ef;color:#c0392b;border-bottom:1px solid #f5c6cb;
  display:none;align-items:center;gap:.4rem;
}

@media (max-width:768px) {
  .lyo-editor-area { flex-direction:column; }
  .lyo-editor-pane { border-right:none;border-bottom:1px solid var(--adam-border-soft,var(--adam-border,#ddd)); }
}
</style>

<section class="adam-card">
  <div class="lyo-wrap">

    <div class="lyo-header">
      <div class="lyo-header-left">
        <h2><?= $isNew ? '📄 ' . _e('New Layout') : '✏️ ' . _e('Edit Layout:') . ' ' . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php if ($isNew): ?>
          <input type="text" id="lyo-name" class="lyo-name-input" placeholder="<?=_e('Layout name (slug)')?>" value="<?= htmlspecialchars($pref_layout_name, ENT_QUOTES, 'UTF-8') ?>" autofocus>
        <?php endif; ?>
      </div>
      <div class="lyo-header-right">
        <button type="button" class="adam-button" id="btn-save">💾 <?= $isNew ? __('Save') : __('Save Changes') ?></button>
        <a href="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>" class="adam-cancle"><?=_e('Cancel')?></a>
      </div>
    </div>

    <form method="post" id="layout-form" action="<?= htmlspecialchars($base . '/admin/shortcodes/save_layout.php', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="save_nonce" value="<?= htmlspecialchars($save_nonce, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8') ?>">
      <?php if (!$isNew): ?>
        <input type="hidden" name="file" value="<?= htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') ?>">
      <?php endif; ?>
      <input type="hidden" name="layout_name" id="hidden-layout-name" value="<?= htmlspecialchars($pref_layout_name, ENT_QUOTES, 'UTF-8') ?>">
      <textarea id="content-textarea" name="content" style="display:none;"><?= htmlspecialchars($pref_content, ENT_QUOTES, 'UTF-8') ?></textarea>
    </form>

<?php if ($isNew): ?>
    <div style="font-size:.85rem;font-weight:600;color:var(--adam-muted,#888);margin-bottom:-.5rem;"><?=_e('Choose starting template:')?></div>
    <div id="lyo-starter" class="lyo-templates">
      <div class="lyo-tpl-card selected" data-template="0">
        <span class="lyo-tpl-icon">📄</span>
        <div class="lyo-tpl-title"><?=_e('Blank')?></div>
        <div class="lyo-tpl-desc"><?=_e('Start from a blank page')?></div>
      </div>
      <div class="lyo-tpl-card" data-template="1">
        <span class="lyo-tpl-icon">📋</span>
        <div class="lyo-tpl-title"><?=_e('Simple List')?></div>
        <div class="lyo-tpl-desc"><?=_e('Vertical article list with excerpt + date')?></div>
      </div>
      <div class="lyo-tpl-card" data-template="2">
        <span class="lyo-tpl-icon">🗂️</span>
        <div class="lyo-tpl-title"><?=_e('Card Grid')?></div>
        <div class="lyo-tpl-desc"><?=_e('Card grid with thumbnail, excerpt & date')?></div>
      </div>
      <div class="lyo-tpl-card" data-template="3">
        <span class="lyo-tpl-icon">🃏</span>
        <div class="lyo-tpl-title"><?=_e('Card Accent')?></div>
        <div class="lyo-tpl-desc"><?=_e('Vertical card with gradient accent')?></div>
      </div>
      <div class="lyo-tpl-card" data-template="4">
        <span class="lyo-tpl-icon">🎠</span>
        <div class="lyo-tpl-title"><?=_e('Slider')?></div>
        <div class="lyo-tpl-desc"><?=_e('Horizontal carousel with navigation')?></div>
      </div>
    </div>
<?php endif; ?>

    <?php if (!$isNew && !empty($connectedPresets)): ?>
    <div style="display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:var(--adam-surface-3);border-radius:8px;font-size:.85rem;">
      <span>🔗 <strong><?=_e('Connected Presets:')?></strong></span>
      <?php foreach ($connectedPresets as $cp): ?>
        <a href="<?= h($base . '/?page=admin/shortcodes/edit&id=' . (int)$cp['id']) ?>" class="adam-link" style="font-size:.82rem;display:inline-flex;align-items:center;gap:3px;">
          📦 <?= h((string)($cp['title'] ?? $cp['slug'] ?? '')) ?>
        </a><?= $cp !== end($connectedPresets) ? '<span style="color:var(--adam-muted,#888);font-size:.7rem;">|</span>' : '' ?>
      <?php endforeach; ?>
      <span style="font-size:.78rem;color:var(--adam-muted,#888);margin-left:auto;"><?= sprintf(__('This layout is used by %d preset(s)'), count($connectedPresets)) ?></span>
    </div>
    <?php endif; ?>

    <?php if (empty($allPresets) || (isset($connectedPresets) && empty($connectedPresets))): ?>
    <?php if (!empty($allPresets)): ?>
    <div style="display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:var(--adam-surface-3);border-radius:8px;font-size:.85rem;">
      <span>🔗 <strong><?=_e('Preview with real data:')?></strong></span>
      <select id="preview-preset-select" style="font-size:.82rem;padding:.25rem .5rem;border:1px solid var(--adam-border-soft,#ddd);border-radius:4px;background:var(--adam-bg,#fff);color:var(--adam-text,#333);">
        <option value=""><?=_e('— Choose a preset —')?></option>
        <?php foreach ($allPresets as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= h((string)($p['title'] ?? $p['slug'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
      <span style="font-size:.78rem;color:var(--adam-muted,#888);"><?=_e('Choose a preset to see the layout with real content from the DB')?></span>
      <button type="button" id="preview-preset-btn" style="font-size:.78rem;padding:.2rem .6rem;border:1px solid var(--adam-accent,#4361ee);border-radius:4px;background:var(--adam-accent,#4361ee);color:#fff;cursor:pointer;"><?=_e('Preview')?></button>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div style="display:flex;align-items:flex-start;gap:.5rem;padding:.6rem .75rem;background:var(--adam-surface-2);border-radius:8px;font-size:.85rem;line-height:1.5;">
      <div style="font-size:1.1rem;flex-shrink:0;">💡</div>
      <div>
        <?=_e('<strong>How Preset &amp; Layout work together:</strong>')?><br>
        <?=_e('<strong>Preset</strong> = content filter (category, count, order, etc.) + layout choice.')?><br>
        <?=_e('<strong>Layout</strong> = the PHP file that controls the post&rsquo;s <em>visual appearance</em>.')?><br>
        <?= sprintf(__('On the %sedit preset%s page, you pick which layout to use.'), '<a href="' . h($preset_list_url) . '" class="adam-link">', '</a>') ?>
        <?=_e('The same layout can be reused by many presets — so create one layout and use it in any preset.')?>
      </div>
    </div>

    <div class="lyo-toolbar" id="lyo-toolbar">
      <button data-snippet="0" title="<?=_e('Insert foreach loop')?>">🔁 <?=_e('Loop Items')?></button>
      <button data-snippet="1" title="<?=_e('Insert thumbnail block')?>">🖼️ <?=_e('Thumbnail')?></button>
      <button data-snippet="2" title="<?=_e('Insert excerpt')?>">📝 <?=_e('Excerpt')?></button>
      <button data-snippet="3" title="<?=_e('Insert date')?>">📅 <?=_e('Date')?></button>
      <button data-snippet="4" title="<?=_e('Insert kicker')?>">🏷️ <?=_e('Kicker')?></button>
      <button data-snippet="5" title="<?=_e('Insert slider nav buttons')?>">🎠 <?=_e('Slider Nav')?></button>
      <button data-snippet="6" title="<?=_e('Insert wrapper div')?>">📦 <?=_e('Wrapper')?></button>
      <span style="flex:1"></span>
      <button data-snippet="clear" class="danger" style="border-color:#e74c3c;color:#e74c3c;" title="<?=_e('Clear editor')?>">🗑️ <?=_e('Clear')?></button>
    </div>

    <div class="lyo-editor-area">
      <div class="lyo-editor-pane">
        <div class="lyo-pane-header">
          <span>&lt;&gt; <?=_e('Code Editor')?></span>
          <span style="font-weight:400;font-size:.75rem;color:var(--adam-muted,#888);"><?=_e('PHP + HTML — click a component to insert code')?></span>
        </div>
        <div class="lyo-pane-body">
          <textarea id="cm-textarea" style="width:100%;min-height:440px;padding:.5rem;border:none;resize:none;font-family:monospace;font-size:.85rem;background:var(--adam-bg,#fff);color:var(--adam-text,#333);"><?= htmlspecialchars($pref_content, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
      </div>
      <div class="lyo-preview-pane">
        <div class="lyo-pane-header">
          <span>👁️ <?=_e('Live Preview')?></span>
          <span style="display:flex;align-items:center;gap:6px;">
            <span id="preview-mode" style="font-weight:400;font-size:.7rem;background:var(--adam-surface-3);padding:.1rem .4rem;border-radius:3px;color:var(--adam-muted,#888);">dummy</span>
            <span id="preview-status" style="font-weight:400;font-size:.75rem;color:var(--adam-muted,#888);"><?=_e('typing...')?></span>
          </span>
        </div>
        <div class="lyo-error-banner" id="preview-error"></div>
        <div class="lyo-pane-body">
          <div id="preview-content" style="padding:1rem;"><?= $isNew ? '<div style="color:var(--adam-muted,#888);text-align:center;padding:3rem 1rem;font-size:.9rem;">' . __('Choose a starter template above, then edit the code — preview appears automatically.') . '</div>' : '<div style="color:var(--adam-muted,#888);text-align:center;padding:3rem 1rem;font-size:.9rem;">' . __('Loading preview...') . '</div>' ?></div>
        </div>
      </div>
    </div>

    <div class="lyo-variables">
      <button type="button" class="lyo-var-toggle" id="lyo-var-toggle">
        <span>📖 Template Variables</span>
        <span class="chevron">▶</span>
      </button>
      <div class="lyo-var-body" id="lyo-var-body">
        <table class="lyo-var-table">
          <thead>
            <tr><th style="width:150px;"><?=_e('Variable')?></th><th style="width:80px;"><?=_e('Type')?></th><th><?=_e('Description')?></th></tr>
          </thead>
          <tbody>
            <tr><td><code>$items</code></td><td><code>array</code></td><td><?=_e('Array of post/page data. Each item has: <code>title</code>, <code>url</code>, <code>thumb</code>, <code>desc</code>, <code>date_label</code>, <code>date_iso</code>, <code>kind</code>, <code>raw</code>')?></td></tr>
            <tr><td><code>$attrs</code></td><td><code>array</code></td><td><?=_e('Filter attributes: <code>source</code>, <code>type</code>, <code>category</code>, <code>limit</code>, <code>offset</code>, <code>order_by</code>, <code>excerpt_len</code>, etc.')?></td></tr>
            <tr><td><code>$layout</code></td><td><code>string</code></td><td><?=_e('Layout name — same as the file name (e.g. <code>cards</code>, <code>list</code>)')?></td></tr>
            <tr><td><code>$kicker</code></td><td><code>string</code></td><td><?=_e('Small text above the title, from the sidebar widget (e.g. <code>LATEST NEWS</code>)')?></td></tr>
            <tr><td><code>$class_prefix</code></td><td><code>string</code></td><td><?=_e('Extra CSS prefix from the preset for styling customization')?></td></tr>
            <tr><td><code>$wrap</code></td><td><code>bool</code></td><td><?=_e('Wrap with <code>&lt;div class="pcat"&gt;</code> (<code>true</code>/<code>false</code>)')?></td></tr>
            <tr><td><code>$esc()</code></td><td><code>callable</code></td><td><?=_e('HTML escaping function: <code>$esc($text)</code> — required for all user content output')?></td></tr>
            <tr><td><code>$slider_enabled</code></td><td><code>bool</code></td><td><?=_e('Whether slider/carousel mode is active')?></td></tr>
            <tr><td><code>$instance_id</code></td><td><code>string</code></td><td><?=_e('Unique ID per instance, useful for HTML <code>id</code>')?></td></tr>
            <tr><td><code>$limit_visible</code></td><td><code>int</code></td><td><?=_e('Number of visible items (for slider / lazy load)')?></td></tr>
          </tbody>
        </table>
        <div style="margin-top:.6rem;font-size:.8rem;color:var(--adam-muted,#888);background:var(--adam-surface-3);padding:.5rem .7rem;border-radius:4px;">
          <strong>📍 <?=_e('Layout file:')?></strong> <code>views/partials/shortcodes/post_cat/<span id="lyo-layout-path-name"><?= htmlspecialchars($isNew ? '{' . __('name') . '}' : $pref_layout_name, ENT_QUOTES, 'UTF-8') ?></span>.php</code>
        </div>
      </div>
    </div>

  </div>
</section>

<input type="hidden" id="editor-codemirror" checked>

<script>
var DEFAULT_TEMPLATES = <?= json_encode([$tplClean, $tplList, $tplCards, $tplCard2, $tplSlider]) ?>;
var SNIPPETS = <?= json_encode([$snippetForeach, $snippetThumb, $snippetExcerpt, $snippetDate, $snippetKicker, $snippetSlider, $snippetWrapper]) ?>;

(function(){
  var form = document.getElementById('layout-form');
  var btn = document.getElementById('btn-save');
  var contentField = document.getElementById('content-textarea');
  var cmTextarea = document.getElementById('cm-textarea');
  var previewContent = document.getElementById('preview-content');
  var previewError = document.getElementById('preview-error');
  var previewStatus = document.getElementById('preview-status');
  var previewMode = document.getElementById('preview-mode');
  var varToggle = document.getElementById('lyo-var-toggle');
  var varBody = document.getElementById('lyo-var-body');
  var lyoName = document.getElementById('lyo-name');
  var hiddenName = document.getElementById('hidden-layout-name');
  var presetSelect = document.getElementById('preview-preset-select');
  var presetBtn = document.getElementById('preview-preset-btn');

  var nonceField = document.querySelector('[name="save_nonce"]');
  var isNew = <?= $isNew ? 'true' : 'false' ?>;
  var previewTimer = null;
  var previewRunning = false;
  var currentPresetId = 0;

  function getEditorValue() {
    if (window.ADIWIRA && window.ADIWIRA.cm && typeof window.ADIWIRA.cm.getValue === 'function') {
      return window.ADIWIRA.cm.getValue();
    }
    var cmHelper = window.ADIWIRA && window.ADIWIRA.codemirror;
    if (cmHelper && typeof cmHelper.getInstance === 'function') {
      var cm = cmHelper.getInstance();
      if (cm && typeof cm.getValue === 'function') return cm.getValue();
    }
    return cmTextarea ? cmTextarea.value : '';
  }

  function setEditorValue(val) {
    if (window.ADIWIRA && window.ADIWIRA.cm && typeof window.ADIWIRA.cm.setValue === 'function') {
      window.ADIWIRA.cm.setValue(val);
    } else {
      var cmHelper = window.ADIWIRA && window.ADIWIRA.codemirror;
      if (cmHelper && typeof cmHelper.getInstance === 'function') {
        var cm = cmHelper.getInstance();
        if (cm && typeof cm.setValue === 'function') { cm.setValue(val); return; }
      }
      cmTextarea.value = val;
    }
    triggerPreview();
  }

  function insertAtCursor(text) {
    if (window.ADIWIRA && window.ADIWIRA.cm && typeof window.ADIWIRA.cm.replaceSelection === 'function') {
      window.ADIWIRA.cm.replaceSelection(text);
      triggerPreview();
      return;
    }
    var cmHelper = window.ADIWIRA && window.ADIWIRA.codemirror;
    if (cmHelper && typeof cmHelper.getInstance === 'function') {
      var inst = cmHelper.getInstance();
      if (inst && typeof inst.replaceSelection === 'function') {
        inst.replaceSelection(text);
        triggerPreview();
        return;
      }
    }
    var ta = cmTextarea;
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    ta.value = ta.value.substring(0, start) + text + ta.value.substring(end);
    ta.selectionStart = ta.selectionEnd = start + text.length;
    ta.focus();
    triggerPreview();
  }

  function triggerPreview() {
    if (previewTimer) clearTimeout(previewTimer);
    previewTimer = setTimeout(doPreview, 600);
  }

  function doPreview() {
    if (previewRunning) return;
    var code = getEditorValue();
    if (!code || code.trim() === '') {
      previewContent.innerHTML = '<div style="color:var(--adam-muted,#888);text-align:center;padding:3rem 1rem;font-size:.9rem;">        <?=_e('Editor is empty — write some layout code to see the preview.')?></div>';
      previewError.style.display = 'none';
      previewStatus.textContent = 'menunggu input...';
      return;
    }

    previewRunning = true;
    previewStatus.textContent = 'merender...';

    var fd = new FormData();
    fd.append('content', code);
    if (currentPresetId > 0) fd.append('preset_id', String(currentPresetId));

    fetch('<?= $base ?>/admin/shortcodes/preview_layout.php', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.ok) {
        previewContent.innerHTML = data.html;
        previewError.style.display = 'none';
        if (data.mode === 'preset') {
          previewMode.textContent = '📦 ' + (presetSelect ? presetSelect.options[presetSelect.selectedIndex].text : 'preset');
          previewStatus.textContent = <?=json_encode(__('Ready'))?> + ' ✔';
        } else {
          previewMode.textContent = 'dummy';
          previewStatus.textContent = <?=json_encode(__('Ready'))?> + ' ✔';
        }
      } else {
        previewContent.innerHTML = data.html || '<div style="color:#e74c3c;padding:1rem;">' + <?=json_encode(__('Preview error'))?> + '</div>';
        if (data.error) {
          previewError.style.display = 'flex';
          previewError.textContent = '⚠ ' + data.error;
        } else {
          previewError.style.display = 'none';
        }
        previewStatus.textContent = 'error ✗';
      }
    })
    .catch(function(err) {
      previewContent.innerHTML = '<div style="color:#e74c3c;padding:1rem;"><?=__('Failed to reach server:')?> ' + err.message + '</div>';
      previewError.style.display = 'none';
      previewStatus.textContent = 'error ✗';
    })
    .finally(function() {
      previewRunning = false;
    });
  }

  function syncContent() {
    contentField.value = getEditorValue();
  }

  <?php if ($isNew): ?>
  function loadTemplate(idx) {
    var code = DEFAULT_TEMPLATES[idx] || '';
    setEditorValue(code);
    if (lyoName && !lyoName.value) {
      var names = ['', 'list', 'cards', 'card2', 'sliderpage'];
      if (names[idx]) lyoName.value = names[idx];
    }
  }

  var tplCards = document.querySelectorAll('.lyo-tpl-card');
  tplCards.forEach(function(card) {
    card.addEventListener('click', function() {
      tplCards.forEach(function(c) { c.classList.remove('selected'); });
      this.classList.add('selected');
      loadTemplate(parseInt(this.getAttribute('data-template')));
    });
  });

  if (lyoName) {
    lyoName.addEventListener('input', function() {
      if (hiddenName) hiddenName.value = this.value;
    });
  }

  var selectedCard = document.querySelector('.lyo-tpl-card.selected');
  if (selectedCard) {
    loadTemplate(parseInt(selectedCard.getAttribute('data-template')));
  }
  <?php else: ?>
  setTimeout(triggerPreview, 300);
  <?php endif; ?>

  document.querySelectorAll('#lyo-toolbar button[data-snippet]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var key = this.getAttribute('data-snippet');
      if (key === 'clear') {
        setEditorValue('');
        return;
      }
      var snippet = SNIPPETS[parseInt(key)];
      if (snippet) insertAtCursor(snippet);
    });
  });

  if (varToggle) {
    varToggle.addEventListener('click', function() {
      this.classList.toggle('open');
      varBody.classList.toggle('open');
    });
  }

  if (presetBtn && presetSelect) {
    presetBtn.addEventListener('click', function() {
      var val = parseInt(presetSelect.value);
      if (!val) {
        if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
          window.NewNotifToast.show({ type: 'warning', title: <?=json_encode(__('Choose a Preset'))?>, message: <?=json_encode(__('Choose a preset from the dropdown first.'))?> });
        }
        return;
      }
      currentPresetId = val;
      triggerPreview();
    });
    presetSelect.addEventListener('change', function() {
      var val = parseInt(this.value);
      if (!val) {
        currentPresetId = 0;
        triggerPreview();
      }
    });
  }

  async function submitAjax(){
    syncContent();
    var oldLabel = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Menyimpan...'; }

    try {
      var res = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      });
      var data = await res.json().catch(function(){
        return { ok: false, errors: ['Respons server tidak valid.'] };
      });

      if (!res.ok || !data.ok) {
        var errors = Array.isArray(data.errors) && data.errors.length
          ? data.errors : [data.error || data.message || '<?=__('Failed to save.')?>'];
        errors.filter(Boolean).forEach(function(msg){
          if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
            window.NewNotifToast.show({ type: 'error', title: '<?=__('Failed')?>', message: String(msg) });
          } else { alert(String(msg)); }
        });
        return;
      }

      if (data.new_save_nonce && nonceField) {
        nonceField.value = data.new_save_nonce;
      }

      if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
        window.NewNotifToast.show({ type: 'success', title: <?= json_encode(__('Success')) ?>, message: data.message || <?= json_encode(__('Layout saved successfully.')) ?> });
      }

      if (data.redirect) {
        window.location.href = data.redirect;
      }
    } catch (err) {
      if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
        window.NewNotifToast.show({ type: 'error', title: <?= json_encode(__('Network')) ?>, message: '<?=__('Something happened.')?>' });
      } else { alert('<?=__('Failed: ')?>' + err.message); }
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

  if (btn) {
    btn.addEventListener('click', function(ev) {
      ev.preventDefault();
      if (form) form.requestSubmit();
    });
  }

  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      if (form) form.requestSubmit();
    }
  });

  var cmTextareaEl = document.getElementById('cm-textarea');
  if (cmTextareaEl) {
    cmTextareaEl.addEventListener('input', triggerPreview);
  }

  var cmCheck = setInterval(function() {
    var cm = null;
    if (window.ADIWIRA && window.ADIWIRA.cm && typeof window.ADIWIRA.cm.on === 'function') {
      cm = window.ADIWIRA.cm;
    } else {
      var ch = window.ADIWIRA && window.ADIWIRA.codemirror;
      if (ch && typeof ch.getInstance === 'function') {
        cm = ch.getInstance();
      }
    }
    if (cm && typeof cm.on === 'function') {
      cm.on('change', function() { triggerPreview(); });
      clearInterval(cmCheck);
      triggerPreview();
    }
  }, 200);
})();
</script>
