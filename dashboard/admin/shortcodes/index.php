<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');

if (!function_exists('slugify_sc')) {
    function slugify_sc(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        return trim((string)$text, '-') ?: bin2hex(random_bytes(4));
    }
}

$base = ADMIN_BASE_PATH;
$tab = (string)($_GET['tab'] ?? 'presets');

// --- Presets ---
$presets = [];
$stmt = $pdo->prepare("SELECT id, title, slug, status, meta, created_at, updated_at, created_by FROM posts WHERE type = 'sc_preset' AND is_deleted = 0 ORDER BY created_at DESC");
$stmt->execute();
$presets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Layouts ---
$layoutFiles = [];
$layoutDir = realpath(__DIR__ . '/../../../app/views/partials/shortcodes/post_cat');
if ($layoutDir && is_dir($layoutDir)) {
    $files = scandir($layoutDir);
    foreach ($files as $f) {
        if (str_ends_with($f, '.php')) {
            $layoutFiles[] = $f;
        }
    }
    sort($layoutFiles);
}
?>
<section class="adam-card">
  <h2>Shortcode Builder</h2>

  <div style="display:flex;gap:0;margin-bottom:1rem;border-bottom:2px solid var(--adam-border,#ddd);">
    <a href="?page=admin/shortcodes/index&tab=presets" style="padding:.6rem 1.2rem;text-decoration:none;border-bottom:2px solid <?= $tab === 'presets' ? 'var(--adam-accent,#4361ee)' : 'transparent' ?>;margin-bottom:-2px;color:<?= $tab === 'presets' ? 'var(--adam-accent,#4361ee)' : 'var(--adam-text,#333)' ?>;font-weight:<?= $tab === 'presets' ? 'bold' : 'normal' ?>;">Presets</a>
    <a href="?page=admin/shortcodes/index&tab=layouts" style="padding:.6rem 1.2rem;text-decoration:none;border-bottom:2px solid <?= $tab === 'layouts' ? 'var(--adam-accent,#4361ee)' : 'transparent' ?>;margin-bottom:-2px;color:<?= $tab === 'layouts' ? 'var(--adam-accent,#4361ee)' : 'var(--adam-text,#333)' ?>;font-weight:<?= $tab === 'layouts' ? 'bold' : 'normal' ?>;">Layouts</a>
  </div>

<?php if ($tab === 'presets'): ?>
  <p style="margin-bottom:1rem">
    <a class="adam-button" href="<?= h($base . '/?page=admin/shortcodes/edit') ?>"><?=_e('+ Add Preset')?></a>
    <?php if ($isAdmin): ?>
      &nbsp;&nbsp;
      <a class="adam-att" href="<?= h($base . '/?page=admin/bin/index') ?>">🗑️ Trash</a>
    <?php endif; ?>
  </p>

  <div class="adam-table-wrapper">
    <table class="adam-table">
      <thead>
        <tr>
          <th><?=_e('Preset Name')?></th>
          <th>Widget Name</th>
          <th>Status</th>
          <th><?= _e('Created') ?></th>
          <th style="width:140px"><?= _e('Actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($presets)): ?>
          <tr><td colspan="5" style="padding:1rem;"><?=_e('No presets yet.')?> <a href="<?= h($base . '/?page=admin/shortcodes/edit') ?>"><?=_e('Create one now')?></a>.</td></tr>
        <?php else: ?>
          <?php foreach ($presets as $p): ?>
            <?php
              $st = strtolower(trim((string)($p['status'] ?? 'draft')));
              $stClass = in_array($st, ['published','draft','private'], true) ? $st : 'unknown';
              $editHref = $base . '/?' . http_build_query(['page' => 'admin/shortcodes/edit', 'id' => (int)$p['id']]);
            ?>
            <tr class="adam-row">
              <td><a class="adam-link" href="<?= h($editHref) ?>"><?= h((string)($p['title'] ?? '-')) ?></a></td>
              <td><code><?= h((string)($p['slug'] ?? '-')) ?></code></td>
              <td><span class="adam-status <?= h($stClass) ?>"><span class="adam-status-text"><?= h(ucfirst($st)) ?></span></span></td>
              <td><?= h(function_exists('format_date_ddmmyyyy_time_bracket') ? format_date_ddmmyyyy_time_bracket((string)$p['created_at']) : (string)$p['created_at']) ?></td>
              <td>
                <a class="adam-ubah" href="<?= h($editHref) ?>">Edit</a>
                &nbsp;<span class="muted-divider">|</span>&nbsp;
                <button type="button" class="adam-hapus js-preset-delete" data-id="<?= (int)$p['id'] ?>" data-title="<?= h((string)($p['title'] ?? '')) ?>"><?=_e('Delete')?></button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <form id="preset-delete-form" method="post" action="<?= h($base . '/admin/shortcodes/delete.php') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" id="preset-delete-id">
    <input type="hidden" name="return_to" value="<?= h($base . '/?page=admin/shortcodes/index&tab=presets') ?>">
  </form>

<?php elseif ($tab === 'layouts'): ?>
  <p style="margin-bottom:1rem">
    <a class="adam-button" href="<?= h($base . '/?page=admin/shortcodes/layout') ?>"><?=_e('+ Add Layout')?></a>
  </p>

  <div class="adam-table-wrapper">
    <table class="adam-table">
      <thead>
        <tr>
          <th><?=_e('File Name')?></th>
          <th><?=_e('Layout Name')?></th>
          <th><?=_e('Size')?></th>
          <th style="width:140px"><?= _e('Actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($layoutFiles)): ?>
          <tr><td colspan="4" style="padding:1rem;"><?=_e('No layout templates yet.')?> <a href="<?= h($base . '/?page=admin/shortcodes/layout') ?>"><?=_e('Create one now')?></a>.</td></tr>
        <?php else: ?>
          <?php foreach ($layoutFiles as $f):
            $layoutName = pathinfo($f, PATHINFO_FILENAME);
            $fpath = $layoutDir . DIRECTORY_SEPARATOR . $f;
            $fsize = is_file($fpath) ? filesize($fpath) : 0;
            $fsizeStr = $fsize > 1024 ? round($fsize / 1024, 1) . ' KB' : $fsize . ' B';
            $editHref = $base . '/?' . http_build_query(['page' => 'admin/shortcodes/layout', 'file' => $f]);
          ?>
            <tr class="adam-row">
              <td><a class="adam-link" href="<?= h($editHref) ?>"><?= h($f) ?></a></td>
              <td><code><?= h($layoutName) ?></code></td>
              <td><?= $fsizeStr ?></td>
              <td>
                <a class="adam-ubah" href="<?= h($editHref) ?>">Edit</a>
                <?php if (!in_array($layoutName, ['cards', 'list', 'card2', 'sliderpage'], true)): ?>
                  &nbsp;<span class="muted-divider">|</span>&nbsp;
                  <button type="button" class="adam-hapus js-layout-delete" data-file="<?= h($f) ?>" data-name="<?= h($layoutName) ?>"><?=_e('Delete')?></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <form id="layout-delete-form" method="post" action="<?= h($base . '/admin/shortcodes/delete_layout.php') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="file" id="layout-delete-file">
    <input type="hidden" name="return_to" value="<?= h($base . '/?page=admin/shortcodes/index&tab=layouts') ?>">
  </form>
<?php endif; ?>
</section>

<script>
(function(){
  function ask(variant, opts) {
    if (window.NewNotifConfirm) {
      if (variant === 'danger' && typeof window.NewNotifConfirm.danger === 'function')
        return window.NewNotifConfirm.danger(opts);
      if (typeof window.NewNotifConfirm.warning === 'function')
        return window.NewNotifConfirm.warning(opts);
    }
    return Promise.resolve(window.confirm(opts.message || '<?=__('Continue?')?>'));
  }

  var deleteForm = document.getElementById('preset-delete-form');
  if (deleteForm) {
    document.querySelectorAll('.js-preset-delete').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = this.getAttribute('data-id') || '';
        var title = this.getAttribute('data-title') || '<?=__('this preset')?>';
        ask('danger', {
          title: '<?=__('Delete preset')?>',
          message: '<?=__('Delete preset')?> "' + title + '"? <?=__('Item will be moved to trash.')?>',
          confirmText: <?= json_encode(__('Yes, delete')) ?>,
          cancelText: <?= json_encode(__('Cancel')) ?>
        }).then(function(ok){
          if (!ok) return;
          document.getElementById('preset-delete-id').value = id;
          deleteForm.submit();
        });
      });
    });
  }

  var layoutDeleteForm = document.getElementById('layout-delete-form');
  if (layoutDeleteForm) {
    document.querySelectorAll('.js-layout-delete').forEach(function(btn){
      btn.addEventListener('click', function(){
        var file = this.getAttribute('data-file') || '';
        var name = this.getAttribute('data-name') || '<?=__('this layout')?>';
        ask('danger', {
          title: '<?=__('Delete layout')?>',
          message: '<?=__('Delete layout file')?> "' + name + '"? <?=__('File will be permanently deleted.')?>',
          confirmText: <?= json_encode(__('Yes, delete')) ?>,
          cancelText: <?= json_encode(__('Cancel')) ?>
        }).then(function(ok){
          if (!ok) return;
          document.getElementById('layout-delete-file').value = file;
          layoutDeleteForm.submit();
        });
      });
    });
  }
})();
</script>
