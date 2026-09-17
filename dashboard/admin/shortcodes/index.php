<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/_layout_manager.php';

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');
$actor = function_exists('authorization_actor') ? authorization_actor($pdo, $uid) : null;
$isSiteOwner = $actor !== null && $actor['is_site_owner'] === true;

$base = ADMIN_BASE_PATH;
$tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'presets';
if ($tab === 'layouts' && !$isSiteOwner) {
    adiwira_require_site_owner($pdo, false);
}
$layoutScope = is_string($_GET['scope'] ?? null) ? $_GET['scope'] : 'collection';
if (!in_array($layoutScope, ['collection', 'section'], true) || ($layoutScope === 'section' && !$isSiteOwner)) {
    $layoutScope = 'collection';
}

// --- Presets ---
$presets = [];
$presetFilters = shortcode_preset_list_filters($_GET, $isAdmin);
$presetSpec = shortcode_preset_list_spec($presetFilters, $uid, $role);
$presetPerPage = 15;
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM posts p WHERE ' . $presetSpec['where']);
$countStmt->execute($presetSpec['params']);
$presetTotal = (int)$countStmt->fetchColumn();
$presetPages = max(1, (int)ceil($presetTotal / $presetPerPage));
$presetFilters['p'] = min($presetFilters['p'], $presetPages);
$presetOffset = ($presetFilters['p'] - 1) * $presetPerPage;
$stmt = $pdo->prepare('SELECT p.id, p.title, p.slug, p.status, p.meta, p.created_at, p.updated_at, p.created_by,
    COALESCE(NULLIF(u.name, \'\'), NULLIF(u.username, \'\'), CAST(u.id AS CHAR)) AS owner_name
    FROM posts p LEFT JOIN users u ON u.id = p.created_by
    WHERE ' . $presetSpec['where'] . ' ORDER BY p.created_at DESC, p.id DESC LIMIT :limit OFFSET :offset');
foreach ($presetSpec['params'] as $key => $value) $stmt->bindValue($key, $value);
$stmt->bindValue(':limit', $presetPerPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $presetOffset, PDO::PARAM_INT);
$stmt->execute();
$presets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$presetOwners = [];
if ($isAdmin) {
    $ownersStmt = $pdo->query("SELECT DISTINCT u.id, u.name, u.username FROM users u INNER JOIN posts p ON p.created_by = u.id WHERE p.type = 'sc_preset' AND p.is_deleted = 0 AND u.is_deleted = 0 ORDER BY u.name, u.username");
    $presetOwners = $ownersStmt ? $ownersStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}
$presetQuery = ['page' => 'admin/shortcodes/index', 'tab' => 'presets'];
if ($presetFilters['q'] !== '') $presetQuery['q'] = $presetFilters['q'];
if ($presetFilters['status'] !== '') $presetQuery['status'] = $presetFilters['status'];
if ($isAdmin && $presetFilters['owner'] > 0) $presetQuery['owner'] = $presetFilters['owner'];
if ($presetFilters['p'] > 1) $presetQuery['p'] = $presetFilters['p'];
$presetReturnTo = $base . '/?' . http_build_query($presetQuery);
$presetAddHref = $base . '/?' . http_build_query(['page' => 'admin/shortcodes/edit', 'return_to' => $presetReturnTo]);
$buildPresetPaginationItems = static function (int $current, int $total, int $maxVisible = 9): array {
    if ($total <= $maxVisible) return range(1, $total);

    $items = [];
    $middleSlots = max(1, $maxVisible - 6);
    $half = (int)floor($middleSlots / 2);
    $start = max(3, $current - $half);
    $end = min($total - 2, $current + $half);

    if ($start === 3) $end = min($total - 2, $start + $middleSlots - 1);
    if ($end === $total - 2) $start = max(3, $end - $middleSlots + 1);

    $items[] = 1;
    $items[] = 2;
    if ($start > 3) $items[] = '...';
    for ($i = $start; $i <= $end; $i++) $items[] = $i;
    if ($end < $total - 2) $items[] = '...';
    $items[] = $total - 1;
    $items[] = $total;

    while (count($items) > $maxVisible) {
        foreach ($items as $index => $item) {
            if (is_int($item) && !in_array($item, [1, 2, $total - 1, $total], true)) {
                array_splice($items, $index, 1);
                break;
            }
        }
    }
    return $items;
};
$presetPagingItems = $buildPresetPaginationItems($presetFilters['p'], $presetPages, 9);

// --- Layouts ---
$activeThemeFolder = function_exists('get_active_theme_folder') ? get_active_theme_folder($pdo) : DEFAULT_THEME_FOLDER;
$layoutFilters = shortcode_layout_list_filters($_GET, $layoutScope);
$layoutManagerError = '';
$layoutEntries = [];
if ($isSiteOwner) {
    try {
        $layoutEntries = shortcode_layout_list($pdo, $layoutScope);
    } catch (Throwable $error) {
        error_log('shortcode layout list failed: ' . $error->getMessage());
        $layoutManagerError = __('Layout manager is temporarily unavailable. No layout changes were made.');
    }
}
$layoutEntries = array_values(array_filter($layoutEntries, static function (array $layout) use ($layoutFilters, $layoutScope): bool {
    if ($layoutFilters['q'] !== ''
        && stripos((string)$layout['file'], $layoutFilters['q']) === false
        && stripos((string)$layout['name'], $layoutFilters['q']) === false) {
        return false;
    }
    if ($layoutFilters['filter'] === '') return true;
    if ($layoutScope === 'section') {
        return $layoutFilters['filter'] === ($layout['registered'] ? 'registered' : 'unregistered');
    }
    return $layoutFilters['filter'] === ($layout['builtin'] ? 'builtin' : 'custom');
}));
$layoutPerPage = 15;
$layoutTotal = count($layoutEntries);
$layoutPages = max(1, (int)ceil($layoutTotal / $layoutPerPage));
$layoutFilters['p'] = min($layoutFilters['p'], $layoutPages);
$layoutOffset = ($layoutFilters['p'] - 1) * $layoutPerPage;
$layoutEntries = array_slice($layoutEntries, $layoutOffset, $layoutPerPage);
$layoutQuery = ['page' => 'admin/shortcodes/index', 'tab' => 'layouts', 'scope' => $layoutScope];
if ($layoutFilters['q'] !== '') $layoutQuery['q'] = $layoutFilters['q'];
if ($layoutFilters['filter'] !== '') $layoutQuery['filter'] = $layoutFilters['filter'];
if ($layoutFilters['p'] > 1) $layoutQuery['p'] = $layoutFilters['p'];
$layoutReturnTo = $base . '/?' . http_build_query($layoutQuery);
$layoutAddHref = $base . '/?' . http_build_query([
    'page' => 'admin/shortcodes/layout',
    'scope' => $layoutScope,
    'return_to' => $layoutReturnTo,
]);
$layoutPagingItems = $buildPresetPaginationItems($layoutFilters['p'], $layoutPages, 9);
?>
<section class="adam-card">
  <h2><?=_e('Shortcode Builder')?></h2>

  <div style="display:flex;gap:0;margin-bottom:1rem;border-bottom:2px solid var(--adam-border,#ddd);">
    <a href="?page=admin/shortcodes/index&tab=presets" style="padding:.6rem 1.2rem;text-decoration:none;border-bottom:2px solid <?= $tab === 'presets' ? 'var(--adam-accent,#4361ee)' : 'transparent' ?>;margin-bottom:-2px;color:<?= $tab === 'presets' ? 'var(--adam-accent,#4361ee)' : 'var(--adam-text,#333)' ?>;font-weight:<?= $tab === 'presets' ? 'bold' : 'normal' ?>;"><?=_e('Presets')?></a>
    <?php if ($isSiteOwner): ?>
      <a href="?page=admin/shortcodes/index&tab=layouts" style="padding:.6rem 1.2rem;text-decoration:none;border-bottom:2px solid <?= $tab === 'layouts' ? 'var(--adam-accent,#4361ee)' : 'transparent' ?>;margin-bottom:-2px;color:<?= $tab === 'layouts' ? 'var(--adam-accent,#4361ee)' : 'var(--adam-text,#333)' ?>;font-weight:<?= $tab === 'layouts' ? 'bold' : 'normal' ?>;"><?=_e('Layouts')?></a>
    <?php endif; ?>
  </div>

<?php if ($tab === 'presets'): ?>
  <div class="sc-toolbar sc-presets-toolbar">
    <form method="get" class="sc-filter-form">
      <input type="hidden" name="page" value="admin/shortcodes/index">
      <input type="hidden" name="tab" value="presets">
      <div class="sc-filter-fields<?= $isAdmin ? ' has-owner' : '' ?>">
        <input type="search" name="q" value="<?= h($presetFilters['q']) ?>" placeholder="<?= h(__('Search preset title or slug…')) ?>" class="inp sc-search-control">
        <select name="status" class="inp">
          <option value=""><?=_e('All Status')?></option>
          <option value="published" <?= $presetFilters['status'] === 'published' ? 'selected' : '' ?>><?=_e('Published')?></option>
          <option value="draft" <?= $presetFilters['status'] === 'draft' ? 'selected' : '' ?>><?=_e('Draft')?></option>
          <option value="private" <?= $presetFilters['status'] === 'private' ? 'selected' : '' ?>><?=_e('Private')?></option>
        </select>
        <?php if ($isAdmin): ?>
          <select name="owner" class="inp">
            <option value=""><?=_e('All Owners')?></option>
            <?php foreach ($presetOwners as $owner):
              $ownerLabel = (string)($owner['name'] ?: ($owner['username'] ?: $owner['id']));
            ?>
              <option value="<?= (int)$owner['id'] ?>" <?= $presetFilters['owner'] === (int)$owner['id'] ? 'selected' : '' ?>><?= h($ownerLabel) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
      <div class="sc-filter-actions">
        <button type="submit" class="adam-button"><?=_e('Apply')?></button>
        <a class="adam-cancle" href="<?= h($base . '/?page=admin/shortcodes/index&tab=presets') ?>"><?=_e('Reset')?></a>
      </div>
    </form>
    <a class="adam-button sc-toolbar-primary" href="<?= h($presetAddHref) ?>"><?=_e('+ Add Preset')?></a>
  </div>

  <form id="preset-bulk-form" method="post" action="<?= h($base . '/admin/shortcodes/bulk_action.php') ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="return_to" value="<?= h($presetReturnTo) ?>">
    <div class="sc-bulk-bar sc-presets-bulk" id="preset-bulk-bar" data-active="false">
      <div class="sc-bulk-summary">
        <label class="check-row"><input type="checkbox" id="preset-select-all"> <?=_e('Select all on page')?></label>
        <span id="preset-selection-count" class="bulk-selection-count" role="status" aria-live="polite" hidden>
          <span class="bsc-number">0</span>
          <span class="bsc-label"><?=_e('Presets Selected')?></span>
        </span>
      </div>
      <div class="sc-bulk-actions">
        <select name="action" id="preset-bulk-action" class="inp" required>
          <option value=""><?=_e('-- Bulk action --')?></option>
          <option value="publish"><?=_e('Publish')?></option>
          <option value="draft"><?=_e('Set to draft')?></option>
          <option value="private"><?=_e('Set to private')?></option>
          <option value="delete"><?=_e('Move to trash')?></option>
        </select>
        <button type="submit" id="preset-bulk-submit" class="adam-button"><?=_e('Apply')?></button>
      </div>
      <small class="sc-bulk-hint"><?=_e('Bulk only affects checked items.')?></small>
    </div>
  <div class="adam-table-wrapper">
    <table class="adam-table">
      <thead>
        <tr>
          <th style="width:32px"><span class="sr-only"><?=_e('Select')?></span></th>
          <th><?=_e('Preset Name')?></th>
          <th><?=_e('Widget Name')?></th>
          <th><?=_e('Status')?></th>
          <?php if ($isAdmin): ?><th><?=_e('Owner')?></th><?php endif; ?>
          <th><?= _e('Created') ?></th>
          <th style="width:140px"><?= _e('Actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($presets)): ?>
          <tr><td colspan="<?= $isAdmin ? 7 : 6 ?>" style="padding:1rem;"><?=_e('No presets found.')?> <a href="<?= h($presetAddHref) ?>"><?=_e('Create one now')?></a>.</td></tr>
        <?php else: ?>
          <?php foreach ($presets as $p): ?>
            <?php
              $st = strtolower(trim((string)($p['status'] ?? 'draft')));
              $stClass = in_array($st, ['published','draft','private'], true) ? $st : 'unknown';
              $editHref = $base . '/?' . http_build_query(['page' => 'admin/shortcodes/edit', 'id' => (int)$p['id'], 'return_to' => $presetReturnTo]);
            ?>
            <tr class="adam-row sc-presets-table-row">
              <td><input type="checkbox" class="preset-row-check" name="ids[]" value="<?= (int)$p['id'] ?>" aria-label="<?= h(sprintf(__('Select %s'), (string)($p['title'] ?? ''))) ?>"></td>
              <td><a class="adam-link" href="<?= h($editHref) ?>"><?= h((string)($p['title'] ?? '-')) ?></a></td>
              <td><code><?= h((string)($p['slug'] ?? '-')) ?></code></td>
              <td><span class="adam-status <?= h($stClass) ?>"><span class="adam-status-text"><?= h(__(ucfirst($st))) ?></span></span></td>
              <?php if ($isAdmin): ?><td><?= h((string)($p['owner_name'] ?? '-')) ?></td><?php endif; ?>
              <td><?= h(function_exists('format_date_ddmmyyyy_time_bracket') ? format_date_ddmmyyyy_time_bracket((string)$p['created_at']) : (string)$p['created_at']) ?></td>
              <td>
                <a class="adam-ubah" href="<?= h($editHref) ?>"><?= svg_ico('pen', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Edit')?></a>
                &nbsp;<span class="muted-divider">|</span>&nbsp;
                <button type="button" class="adam-hapus js-preset-delete" data-id="<?= (int)$p['id'] ?>" data-title="<?= h((string)($p['title'] ?? '')) ?>"><?= svg_ico('trash-2', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Delete')?></button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  </form>

  <?php if ($presetPages > 1): ?>
    <nav class="adam-pagination pagination-wrap">
      <?php foreach ($presetPagingItems as $item):
        if ($item === '...') {
          echo '<span class="dots">…</span> ';
          continue;
        }
        $pageNumber = (int)$item;
        $pageQuery = $presetQuery;
        $pageQuery['p'] = $pageNumber;
        $pageLink = $base . '/?' . http_build_query($pageQuery);
      ?>
        <?php if ($pageNumber === $presetFilters['p']): ?>
          <strong><?= $pageNumber ?></strong>
        <?php else: ?>
          <a href="<?= h($pageLink) ?>"><?= $pageNumber ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <form id="preset-delete-form" method="post" action="<?= h($base . '/admin/shortcodes/delete.php') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" id="preset-delete-id">
    <input type="hidden" name="return_to" value="<?= h($presetReturnTo) ?>">
  </form>

<?php elseif ($tab === 'layouts'): ?>
  <?php if ($layoutManagerError !== ''): ?>
    <p role="alert" style="padding:.65rem .8rem;border:1px solid var(--adam-danger,#b42318);color:var(--adam-danger,#b42318);border-radius:6px;"><?= h($layoutManagerError) ?></p>
  <?php endif; ?>
  <div class="sc-toolbar sc-layouts-toolbar">
    <nav class="sc-scope-switch" aria-label="<?= h(__('Layout scope')) ?>">
      <a class="<?= $layoutScope === 'collection' ? 'is-active' : '' ?>" href="<?= h($base . '/?page=admin/shortcodes/index&tab=layouts&scope=collection') ?>" <?= $layoutScope === 'collection' ? 'aria-current="page"' : '' ?>><?=_e('Collection Layouts')?></a>
      <?php if ($isSiteOwner): ?>
        <a class="<?= $layoutScope === 'section' ? 'is-active' : '' ?>" href="<?= h($base . '/?page=admin/shortcodes/index&tab=layouts&scope=section') ?>" <?= $layoutScope === 'section' ? 'aria-current="page"' : '' ?>><?=_e('Theme Sections')?></a>
      <?php endif; ?>
    </nav>
    <a class="adam-button sc-toolbar-primary" href="<?= h($layoutAddHref) ?>"><?= $layoutScope === 'section' ? __('+ Add Theme Section') : __('+ Add Layout') ?></a>
  </div>

  <?php if ($layoutScope === 'section'): ?>
    <p style="margin:-.25rem 0 1rem;color:var(--adam-muted,#666);font-size:.88rem;">
      <?= sprintf(__('Editing renderers owned by the active theme: %s'), '<code>' . h($activeThemeFolder) . '</code>') ?>
    </p>
  <?php endif; ?>
  <p style="margin:-.25rem 0 1rem;color:var(--adam-muted,#666);font-size:.82rem;">
    <?=_e('Layout removal moves files one at a time under an operation lock. After interruption, Core restores the group on the next manager operation when destinations are unchanged; conflicts stay retained for recovery and are logged.')?>
  </p>

  <div class="sc-toolbar sc-layouts-filter-toolbar">
    <form method="get" class="sc-filter-form">
      <input type="hidden" name="page" value="admin/shortcodes/index">
      <input type="hidden" name="tab" value="layouts">
      <input type="hidden" name="scope" value="<?= h($layoutScope) ?>">
      <div class="sc-layout-filter-fields">
        <input type="search" name="q" value="<?= h($layoutFilters['q']) ?>" maxlength="120" placeholder="<?= h(__('Search layout file or name…')) ?>" class="inp sc-search-control">
        <select name="filter" class="inp">
          <option value=""><?= $layoutScope === 'section' ? __('All registration statuses') : __('All layout types') ?></option>
          <?php if ($layoutScope === 'section'): ?>
            <option value="registered" <?= $layoutFilters['filter'] === 'registered' ? 'selected' : '' ?>><?=_e('Registered')?></option>
            <option value="unregistered" <?= $layoutFilters['filter'] === 'unregistered' ? 'selected' : '' ?>><?=_e('Unregistered')?></option>
          <?php else: ?>
            <option value="builtin" <?= $layoutFilters['filter'] === 'builtin' ? 'selected' : '' ?>><?=_e('Built-in')?></option>
            <option value="custom" <?= $layoutFilters['filter'] === 'custom' ? 'selected' : '' ?>><?=_e('Custom')?></option>
          <?php endif; ?>
        </select>
      </div>
      <div class="sc-filter-actions">
        <button type="submit" class="adam-button"><?=_e('Apply')?></button>
        <a class="adam-cancle" href="<?= h($base . '/?' . http_build_query(['page' => 'admin/shortcodes/index', 'tab' => 'layouts', 'scope' => $layoutScope])) ?>"><?=_e('Reset')?></a>
      </div>
    </form>
  </div>

  <form id="layout-bulk-form" method="post" action="<?= h($base . '/admin/shortcodes/bulk_layout_action.php') ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="scope" value="<?= h($layoutScope) ?>">
    <input type="hidden" name="return_to" value="<?= h($layoutReturnTo) ?>">
    <div class="sc-bulk-bar sc-layouts-bulk" id="layout-bulk-bar" data-active="false">
      <div class="sc-bulk-summary">
        <label class="check-row"><input type="checkbox" id="layout-select-all"> <?=_e('Select all removable')?></label>
        <span id="layout-selection-count" class="bulk-selection-count" role="status" aria-live="polite" hidden>
          <span class="bsc-number">0</span>
          <span class="bsc-label"><?=_e('Layouts Selected')?></span>
        </span>
      </div>
      <div class="sc-bulk-actions">
        <select name="action" id="layout-bulk-action" class="inp" required>
          <option value=""><?=_e('-- Bulk action --')?></option>
          <option value="delete"><?=_e('Remove to quarantine')?></option>
        </select>
        <button type="submit" id="layout-bulk-submit" class="adam-button"><?=_e('Apply')?></button>
      </div>
      <small class="sc-bulk-hint"><?= $layoutScope === 'collection' ? __('Built-in layouts stay protected and cannot be selected.') : __('Bulk only affects checked items.') ?></small>
    </div>
  <div class="adam-table-wrapper">
    <table class="adam-table">
      <thead>
        <tr>
          <th style="width:32px"><span class="sr-only"><?=_e('Select')?></span></th>
          <th><?=_e('File Name')?></th>
          <th><?= $layoutScope === 'section' ? __('Section Name') : __('Layout Name') ?></th>
          <th><?=_e('Type')?></th>
          <th><?=_e('Size')?></th>
          <th style="width:140px"><?= _e('Actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($layoutEntries)): ?>
          <tr><td colspan="6" style="padding:1rem;"><?=_e('No layouts found.')?> <a href="<?= h($layoutAddHref) ?>"><?=_e('Create one now')?></a>.</td></tr>
        <?php else: ?>
          <?php foreach ($layoutEntries as $layout):
            $f = (string)$layout['file'];
            $layoutName = (string)$layout['name'];
            $fsize = (int)$layout['size'];
            $fsizeStr = $fsize > 1024 ? round($fsize / 1024, 1) . ' KB' : $fsize . ' B';
            $canDelete = !$layout['builtin'];
            $typeLabel = $layoutScope === 'section'
              ? ($layout['registered'] ? __('Registered') : __('Unregistered'))
              : ($layout['builtin'] ? __('Built-in') : __('Custom'));
            $editHref = $base . '/?' . http_build_query([
              'page' => 'admin/shortcodes/layout',
              'scope' => $layoutScope,
              'file' => $f,
              'return_to' => $layoutReturnTo,
            ]);
          ?>
            <tr class="adam-row sc-layouts-table-row">
              <td>
                <?php if ($canDelete): ?>
                  <input type="checkbox" class="layout-row-check" name="files[]" value="<?= h($f) ?>" aria-label="<?= h(sprintf(__('Select %s'), $layoutName)) ?>">
                <?php else: ?>
                  <span class="sr-only"><?=_e('Built-in')?></span>
                <?php endif; ?>
              </td>
              <td><a class="adam-link" href="<?= h($editHref) ?>"><?= h($f) ?></a></td>
              <td><code><?= h($layoutName) ?></code></td>
              <td><?= h($typeLabel) ?></td>
              <td><?= h($fsizeStr) ?></td>
              <td>
                <a class="adam-ubah" href="<?= h($editHref) ?>"><?= svg_ico('pen', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Edit')?></a>
                <?php if ($canDelete): ?>
                  &nbsp;<span class="muted-divider">|</span>&nbsp;
                  <button type="button" class="adam-hapus js-layout-delete" data-file="<?= h($f) ?>" data-name="<?= h($layoutName) ?>"><?= svg_ico('trash-2', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Delete')?></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  </form>
  <p id="layout-bulk-feedback" role="status" aria-live="polite" tabindex="-1" style="display:none;margin:.5rem 0;color:var(--adam-danger,#b42318);"></p>

  <?php if ($layoutPages > 1): ?>
    <nav class="adam-pagination pagination-wrap">
      <?php foreach ($layoutPagingItems as $item):
        if ($item === '...') {
          echo '<span class="dots">…</span> ';
          continue;
        }
        $pageNumber = (int)$item;
        $pageQuery = $layoutQuery;
        $pageQuery['p'] = $pageNumber;
        $pageLink = $base . '/?' . http_build_query($pageQuery);
      ?>
        <?php if ($pageNumber === $layoutFilters['p']): ?>
          <strong><?= $pageNumber ?></strong>
        <?php else: ?>
          <a href="<?= h($pageLink) ?>"><?= $pageNumber ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <form id="layout-delete-form" method="post" action="<?= h($base . '/admin/shortcodes/delete_layout.php') ?>" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="file" id="layout-delete-file">
    <input type="hidden" name="scope" value="<?= h($layoutScope) ?>">
    <input type="hidden" name="return_to" value="<?= h($layoutReturnTo) ?>">
  </form>
<?php endif; ?>
</section>

<style>
.sc-toolbar,.sc-filter-form,.sc-bulk-bar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.sc-toolbar{ margin-bottom:1rem; }
.sc-bulk-bar{ margin-bottom:.65rem; }
.sc-presets-toolbar{
  align-items:stretch;
  background:var(--adam-surface-3, rgba(127,127,127,.06));
  border:1px solid var(--adam-border-soft, rgba(127,127,127,.2));
  border-radius:10px;
  display:grid;
  gap:.75rem;
  grid-template-columns:minmax(0, 1fr) auto;
  padding:.8rem;
}
.sc-presets-toolbar .sc-filter-form{ align-items:stretch; min-width:0; }
.sc-filter-fields{ display:grid; flex:1 1 32rem; gap:.6rem; grid-template-columns:minmax(14rem, 2fr) minmax(9rem, 1fr); min-width:0; }
.sc-filter-fields.has-owner{ grid-template-columns:minmax(14rem, 2fr) minmax(9rem, .8fr) minmax(10rem, 1fr); }
.sc-filter-fields .inp{ margin:0; min-height:2.55rem; min-width:0; width:100%; }
.sc-filter-actions{ align-items:center; display:flex; gap:.5rem; }
.sc-filter-actions .adam-button,.sc-filter-actions .adam-cancle,.sc-toolbar-primary{ align-items:center; display:inline-flex; justify-content:center; min-height:2.55rem; white-space:nowrap; }
.sc-presets-bulk{
  background:var(--adam-surface-3, rgba(127,127,127,.06));
  border:1px solid var(--adam-border-soft, rgba(127,127,127,.2));
  border-radius:10px;
  display:grid;
  gap:.65rem 1rem;
  grid-template-columns:minmax(14rem, 1fr) auto;
  margin-bottom:.85rem;
  padding:.7rem .8rem;
  transition:border-color .15s ease, background .15s ease, box-shadow .15s ease;
}
.sc-presets-bulk[data-active="true"]{ background:var(--adam-primary-soft, rgba(67,97,238,.08)); border-color:var(--adam-primary, #4361ee); box-shadow:0 0 0 2px var(--adam-focus, rgba(67,97,238,.12)); }
.sc-bulk-summary,.sc-bulk-actions{ align-items:center; display:flex; gap:.65rem; min-width:0; }
.sc-presets-bulk .bulk-selection-count{ margin-left:0; }
.sc-bulk-actions .inp{ margin:0; min-height:2.45rem; min-width:12rem; width:auto; }
.sc-bulk-actions .adam-button{ min-height:2.45rem; }
.sc-bulk-actions :disabled{ cursor:not-allowed; opacity:.48; }
.sc-bulk-hint{ color:var(--adam-muted, #777); grid-column:1 / -1; }
.sc-presets-table-row.is-selected td,.sc-layouts-table-row.is-selected td{ background:var(--adam-primary-soft, rgba(67,97,238,.08)); }
.sc-layouts-toolbar{
  align-items:center;
  background:var(--adam-surface-3, rgba(127,127,127,.06));
  border:1px solid var(--adam-border-soft, rgba(127,127,127,.2));
  border-radius:10px;
  display:flex;
  justify-content:space-between;
  padding:.65rem .75rem;
}
.sc-scope-switch{ background:var(--adam-card, #fff); border:1px solid var(--adam-border-soft, rgba(127,127,127,.2)); border-radius:8px; display:inline-flex; padding:3px; }
.sc-scope-switch a{ border-radius:6px; color:var(--adam-muted, #666); font-size:.86rem; font-weight:700; padding:.48rem .8rem; text-decoration:none; transition:background .15s ease, color .15s ease, box-shadow .15s ease; }
.sc-scope-switch a:hover{ color:var(--adam-text, #222); }
.sc-scope-switch a.is-active{ background:var(--adam-primary, #4361ee); box-shadow:0 2px 7px var(--adam-primary-soft, rgba(67,97,238,.2)); color:#fff; }
.sc-layouts-filter-toolbar{ background:var(--adam-surface-3, rgba(127,127,127,.06)); border:1px solid var(--adam-border-soft, rgba(127,127,127,.2)); border-radius:10px; padding:.75rem; }
.sc-layouts-filter-toolbar .sc-filter-form{ align-items:stretch; flex:1; }
.sc-layout-filter-fields{ display:grid; flex:1; gap:.6rem; grid-template-columns:minmax(14rem, 2fr) minmax(10rem, 1fr); min-width:0; }
.sc-layout-filter-fields .inp{ margin:0; min-height:2.55rem; min-width:0; width:100%; }
.sc-layouts-bulk{
  background:var(--adam-surface-3, rgba(127,127,127,.06));
  border:1px solid var(--adam-border-soft, rgba(127,127,127,.2));
  border-radius:10px;
  display:grid;
  gap:.65rem 1rem;
  grid-template-columns:minmax(14rem, 1fr) auto;
  margin-bottom:.85rem;
  padding:.7rem .8rem;
  transition:border-color .15s ease, background .15s ease, box-shadow .15s ease;
}
.sc-layouts-bulk[data-active="true"]{ background:var(--adam-primary-soft, rgba(67,97,238,.08)); border-color:var(--adam-primary, #4361ee); box-shadow:0 0 0 2px var(--adam-focus, rgba(67,97,238,.12)); }
.sc-layouts-bulk .bulk-selection-count{ margin-left:0; }
@media (max-width: 900px){
  .sc-presets-toolbar{ grid-template-columns:1fr; }
  .sc-toolbar-primary{ width:100%; }
  .sc-filter-fields,.sc-filter-fields.has-owner{ grid-template-columns:minmax(0, 1fr) minmax(9rem, .55fr); }
  .sc-filter-fields select[name="owner"]{ grid-column:1 / -1; }
  .sc-layout-filter-fields{ grid-template-columns:minmax(0, 1fr) minmax(10rem, .6fr); }
}
@media (max-width: 620px){
  .sc-filter-fields,.sc-filter-fields.has-owner{ grid-template-columns:1fr; }
  .sc-filter-fields select[name="owner"]{ grid-column:auto; }
  .sc-filter-actions,.sc-bulk-actions{ display:grid; grid-template-columns:1fr 1fr; width:100%; }
  .sc-filter-actions .adam-button,.sc-filter-actions .adam-cancle,.sc-bulk-actions .inp,.sc-bulk-actions .adam-button{ width:100%; }
  .sc-presets-bulk{ grid-template-columns:1fr; }
  .sc-bulk-summary{ align-items:flex-start; flex-direction:column; }
  .sc-bulk-hint{ grid-column:auto; }
  .sc-layouts-toolbar{ align-items:stretch; flex-direction:column; }
  .sc-scope-switch{ display:grid; grid-template-columns:1fr 1fr; width:100%; }
  .sc-scope-switch a{ text-align:center; }
  .sc-layout-filter-fields{ grid-template-columns:1fr; width:100%; }
  .sc-layouts-bulk{ grid-template-columns:1fr; }
}
</style>

<div class="sc-help" style="margin-top:2rem;padding:1.2rem;background:var(--adam-surface-3);border-radius:var(--adam-radius,8px);border:1px solid var(--adam-border-soft);font-size:.9rem;color:var(--adam-text);line-height:1.6;">
<?php if ($tab === 'layouts' && $layoutScope === 'section'): ?>
  <h3 style="margin:0 0 .6rem;font-size:1rem;"><?=_e('Theme Section Shortcodes')?></h3>
  <p><?=_e('Theme Section renderers are PHP templates owned by the active theme and selected with a validated section identifier.')?></p>
  <table class="adam-table" style="font-size:.85rem;margin-bottom:.6rem;">
    <thead><tr><th><?=_e('Method')?></th><th><?=_e('Example')?></th></tr></thead>
    <tbody>
      <tr><td><?=_e('Shortcode in content')?></td><td><code>[[widget:theme_section name="page.hero" title="Welcome"]]</code></td></tr>
      <tr><td><?=_e('PHP in theme template')?></td><td><code>&lt;?= render_theme_section('page.hero', ['title' =&gt; 'Welcome'], $pdo) ?&gt;</code></td></tr>
    </tbody>
  </table>
  <p style="margin:0;"><?=_e('Resolution order: active theme, default theme, global section directory, then semantic Core fallback.')?></p>
<?php else: ?>
  <h3 style="margin:0 0 .6rem;font-size:1rem;display:flex;align-items:center;gap:6px;">
    <span style="width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;background:var(--adam-primary);color:#fff;border-radius:50%;font-size:12px;font-weight:bold;">?</span>
    <?=_e('How to Use Shortcodes')?>
  </h3>

  <h4 style="margin:1rem 0 .3rem;font-size:.9rem;color:var(--adam-accent);display:flex;align-items:center;gap:5px;"><?= svg_ico('box', '', ['style' => 'width:16px;height:16px']) ?> <?=_e('Preset — Save Configurations, Reuse Later')?></h4>
  <p style="margin:0 0 .5rem;">
    <?=_e('A preset is a <strong>collection of filter + layout settings</strong> stored in the database.')?>
    <?=_e('Use a preset to show a post/page list anywhere without reconfiguring.')?>
  </p>

  <h5 style="margin:.6rem 0 .2rem;font-size:.85rem;"><?=_e('How to use a Preset:')?></h5>
  <table class="adam-table" style="font-size:.85rem;margin-bottom:.6rem;">
    <thead><tr><th style="width:180px;"><?=_e('Method')?></th><th><?=_e('Example')?></th></tr></thead>
    <tbody>
      <tr><td><strong><?=_e('Shortcode in content')?></strong></td><td><code>[[widget:nama_preset]]</code></td></tr>
      <tr><td><strong><?=_e('PHP in theme template')?></strong></td><td><code>&lt;?= widget('nama_preset') ?&gt;</code></td></tr>
      <tr><td><strong><?=_e('Sidebar widget')?></strong></td><td><?=_e('Add the "Post/Page List" widget under Dashboard → Appearance → Widgets, then choose the preset from the dropdown')?></td></tr>
      <tr><td><strong><?=_e('PHP API (ShortcodeQuery)')?></strong></td><td><code>&lt;?= ShortcodeQuery::posts()-&gt;category('news')-&gt;limit(4)-&gt;render() ?&gt;</code></td></tr>
    </tbody>
  </table>

  <h4 style="margin:1rem 0 .3rem;font-size:.9rem;color:var(--adam-accent);display:flex;align-items:center;gap:5px;"><?= svg_ico('puzzle', '', ['style' => 'width:16px;height:16px']) ?> <?=_e('Layout — Visual Template for Post Display')?></h4>
  <p style="margin:0 0 .5rem;">
    <?=_e('A layout is a <strong>PHP file</strong> in <code>views/partials/shortcodes/post_cat/</code> that controls <em>how</em> posts/pages are rendered. Six built-in layouts are available:')?>
  </p>
  <ul style="margin:0 0 .5rem;padding-left:1.2rem;">
    <li><code>list</code> — <?=_e('vertical list with excerpt')?></li>
    <li><code>cards</code> — <?=_e('card grid with thumbnail')?></li>
    <li><code>card2</code> — <?=_e('card variant with a different accent')?></li>
    <li><code>sliderpage</code> — <?=_e('horizontal slider (carousel)')?></li>
    <li><code>grid</code> — <?=_e('compact content grid')?></li>
    <li><code>mini</code> — <?=_e('minimal content list')?></li>
  </ul>
  <p style="margin:0;">
    <?=_e('You can create your own custom layout via the <strong>Layouts</strong> tab.')?>
    <?=_e('New files automatically appear in the layout dropdown when editing a preset.')?>
  </p>

  <h4 style="margin:1rem 0 .3rem;font-size:.9rem;color:var(--adam-accent);display:flex;align-items:center;gap:5px;"><?= svg_ico('link', '', ['style' => 'width:16px;height:16px']) ?> <?=_e('Sidebar Integration')?></h4>
  <p style="margin:0;">
    <?=_e('The "Post/Page List" sidebar widget under <strong>Dashboard → Appearance → Widgets</strong> supports direct preset selection.')?>
    <?=_e('All presets with status <code>published</code> will appear in the widget selection dropdown.')?>
  </p>
<?php endif; ?>
</div>

<script>
(function(){
  var presetSelectAll = document.getElementById('preset-select-all');
  var presetChecks = Array.prototype.slice.call(document.querySelectorAll('.preset-row-check'));
  var presetBulkBar = document.getElementById('preset-bulk-bar');
  var presetBulkAction = document.getElementById('preset-bulk-action');
  var presetBulkSubmit = document.getElementById('preset-bulk-submit');
  var presetSelectionCount = document.getElementById('preset-selection-count');
  function updatePresetSelection(){
    var selected = presetChecks.filter(function(check){ return check.checked; }).length;
    if (presetSelectAll) {
      presetSelectAll.checked = presetChecks.length > 0 && selected === presetChecks.length;
      presetSelectAll.indeterminate = selected > 0 && selected < presetChecks.length;
      presetSelectAll.disabled = presetChecks.length === 0;
    }
    if (presetBulkBar) presetBulkBar.setAttribute('data-active', selected > 0 ? 'true' : 'false');
    if (presetBulkAction) presetBulkAction.disabled = selected === 0;
    if (presetBulkSubmit) presetBulkSubmit.disabled = selected === 0;
    if (presetSelectionCount) {
      var number = presetSelectionCount.querySelector('.bsc-number');
      var label = presetSelectionCount.querySelector('.bsc-label');
      if (number) number.textContent = String(selected);
      if (label) label.textContent = selected === 1 ? <?= json_encode(__('Preset Selected')) ?> : <?= json_encode(__('Presets Selected')) ?>;
      presetSelectionCount.hidden = selected === 0;
    }
    presetChecks.forEach(function(check){
      var row = check.closest('.sc-presets-table-row');
      if (row) row.classList.toggle('is-selected', check.checked);
    });
  }
  if (presetSelectAll) {
    presetSelectAll.addEventListener('change', function(){
      presetChecks.forEach(function(check){ check.checked = presetSelectAll.checked; });
      updatePresetSelection();
    });
    presetChecks.forEach(function(check){
      check.addEventListener('change', updatePresetSelection);
    });
    updatePresetSelection();
  }

  function ask(variant, opts) {
    if (window.NewNotifConfirm) {
      if (variant === 'danger' && typeof window.NewNotifConfirm.danger === 'function')
        return window.NewNotifConfirm.danger(opts);
      if (typeof window.NewNotifConfirm.warning === 'function')
        return window.NewNotifConfirm.warning(opts);
    }
    return Promise.resolve(window.confirm(opts.message || '<?=__('Continue?')?>'));
  }

  var layoutSelectAll = document.getElementById('layout-select-all');
  var layoutChecks = Array.prototype.slice.call(document.querySelectorAll('.layout-row-check'));
  var layoutBulkBar = document.getElementById('layout-bulk-bar');
  var layoutBulkAction = document.getElementById('layout-bulk-action');
  var layoutBulkSubmit = document.getElementById('layout-bulk-submit');
  var layoutSelectionCount = document.getElementById('layout-selection-count');
  function updateLayoutSelection(){
    var selected = layoutChecks.filter(function(check){ return check.checked; }).length;
    if (layoutSelectAll) {
      layoutSelectAll.checked = layoutChecks.length > 0 && selected === layoutChecks.length;
      layoutSelectAll.indeterminate = selected > 0 && selected < layoutChecks.length;
      layoutSelectAll.disabled = layoutChecks.length === 0;
    }
    if (layoutBulkBar) layoutBulkBar.setAttribute('data-active', selected > 0 ? 'true' : 'false');
    if (layoutBulkAction) layoutBulkAction.disabled = selected === 0;
    if (layoutBulkSubmit) layoutBulkSubmit.disabled = selected === 0;
    if (layoutSelectionCount) {
      var number = layoutSelectionCount.querySelector('.bsc-number');
      var label = layoutSelectionCount.querySelector('.bsc-label');
      if (number) number.textContent = String(selected);
      if (label) label.textContent = selected === 1 ? <?= json_encode(__('Layout Selected')) ?> : <?= json_encode(__('Layouts Selected')) ?>;
      layoutSelectionCount.hidden = selected === 0;
    }
    layoutChecks.forEach(function(check){
      var row = check.closest('.sc-layouts-table-row');
      if (row) row.classList.toggle('is-selected', check.checked);
    });
  }
  if (layoutSelectAll) {
    layoutSelectAll.addEventListener('change', function(){
      layoutChecks.forEach(function(check){ check.checked = layoutSelectAll.checked; });
      updateLayoutSelection();
    });
    layoutChecks.forEach(function(check){
      check.addEventListener('change', updateLayoutSelection);
    });
    updateLayoutSelection();
  }

  var layoutBulkForm = document.getElementById('layout-bulk-form');
  var layoutBulkFeedback = document.getElementById('layout-bulk-feedback');
  function layoutNotice(message) {
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: 'warning', title: <?= json_encode(__('Layouts')) ?>, message: message });
      return;
    }
    if (layoutBulkFeedback) {
      layoutBulkFeedback.textContent = message;
      layoutBulkFeedback.style.display = 'block';
      layoutBulkFeedback.focus();
    }
  }
  if (layoutBulkForm) {
    layoutBulkForm.addEventListener('submit', function(event){
      event.preventDefault();
      var selected = layoutChecks.filter(function(check){ return check.checked; }).length;
      if (selected === 0) {
        layoutNotice(<?= json_encode(__('No layouts selected.')) ?>);
        return;
      }
      ask('danger', {
        title: <?= json_encode(__('Remove selected layouts')) ?>,
        message: <?= json_encode(__('Remove %d selected layout(s) from active use and move them to non-public quarantine?')) ?>.replace('%d', String(selected)),
        confirmText: <?= json_encode(__('Yes, remove')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      }).then(function(ok){
        if (ok) layoutBulkForm.submit();
      });
    });
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
          message: '<?=__('Remove layout file')?> "' + name + '"? <?=__('The file will be moved to non-public quarantine.')?>',
          confirmText: <?= json_encode(__('Yes, remove')) ?>,
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
