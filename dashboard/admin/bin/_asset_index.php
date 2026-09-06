<?php
declare(strict_types=1);

if (!isset($assetResource) || !in_array($assetResource, ['media', 'file'], true)) {
    throw new LogicException('Asset Bin resource is missing.');
}

require_once __DIR__ . '/../_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_login($pdo, false);
$config = asset_lifecycle_config($assetResource);
$alias = $assetResource === 'media' ? 'm' : 'f';
$restoreCondition = authorization_owner_scope_condition($pdo, $uid, $config['permissions']['restore'], $alias . '.user_id', $assetResource . '_restore');
$purgeCondition = authorization_owner_scope_condition($pdo, $uid, $config['permissions']['purge'], $alias . '.user_id', $assetResource . '_purge');
if ($restoreCondition === null && $purgeCondition === null) adiwira_render_404();

$conditions = array_values(array_filter([$restoreCondition, $purgeCondition]));
$accessSql = implode(' OR ', array_map(static fn(array $condition): string => '(' . $condition['sql'] . ')', $conditions));
$params = [];
foreach ($conditions as $condition) $params = array_merge($params, $condition['params']);

$search = trim((string)($_GET['q'] ?? ''));
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;
$where = ["$alias.is_deleted = 1", '(' . $accessSql . ')'];
if ($search !== '') {
    $where[] = "($alias.title LIKE :search OR $alias.filename LIKE :search OR $alias.storage_path LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$count = $pdo->prepare("SELECT COUNT(*) FROM {$config['table']} $alias WHERE $whereSql");
$count->execute($params);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
if ($pageNum > $pages) {
    $pageNum = $pages;
    $offset = ($pageNum - 1) * $perPage;
}

$stmt = $pdo->prepare(
    "SELECT $alias.id, $alias.title, $alias.filename, $alias.mime, $alias.size,
            $alias.visibility, $alias.storage_disk, $alias.storage_path, $alias.quarantine_path,
            $alias.created_at, $alias.deleted_at, $alias.user_id AS owner_id,
            u.name AS owner_name, u.username AS owner_username
     FROM {$config['table']} $alias
     LEFT JOIN users u ON u.id = $alias.user_id
     WHERE $whereSql
     ORDER BY $alias.deleted_at DESC, $alias.id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) $stmt->bindValue($key, $value);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageToasts = function_exists('adiwira_collect_query_toasts') ? adiwira_collect_query_toasts() : [];
foreach (adiwira_flash_pull() as $flash) $pageToasts[] = $flash;

$base = ADMIN_BASE_PATH;
$resourceLabel = $assetResource === 'media' ? __('Media') : __('File');
$route = 'admin/bin/' . $assetResource . '/index';
$currentQuery = $_GET;
$currentQuery['page'] = $route;
$currentReturnTo = $base . '/?' . http_build_query($currentQuery);
$canRestore = $restoreCondition !== null;
$canPurge = $purgeCondition !== null;
?>
<section class="adam-card">
  <h2><?= htmlspecialchars(sprintf(__('Bin / Trash - %s'), $resourceLabel), ENT_QUOTES, 'UTF-8') ?></h2>

  <form method="get" class="toolbar-filter bin-filter-bar">
    <input type="hidden" name="page" value="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>">
    <input type="text" name="q" class="inp" placeholder="<?= htmlspecialchars(__('Search title, filename, or path...'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" class="adam-button"><?=_e('Apply')?></button>
    <a class="adam-cancle" href="<?= htmlspecialchars($base . '/?page=' . $route, ENT_QUOTES, 'UTF-8') ?>"><?=_e('Reset')?></a>
    <span class="bin-trash-total"><?=_e('Total trash:')?> <strong><?= $total ?></strong></span>
  </form>

  <form id="assetBinForm" method="post" action="<?= htmlspecialchars($base . '/admin/bin/' . $assetResource . '/bulk_action.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnTo, ENT_QUOTES, 'UTF-8') ?>">
    <div class="bulk-bar">
      <label class="check-row"><input type="checkbox" id="assetBinSelectAll"> <?=_e('Select all on page')?></label>
      <select name="action" id="assetBinAction" class="inp">
        <option value=""><?=_e('-- Bulk action --')?></option>
        <?php if ($canRestore): ?><option value="restore"><?=_e('Restore')?></option><?php endif; ?>
        <?php if ($canPurge): ?><option value="delete_permanent"><?=_e('Delete Permanently')?></option><?php endif; ?>
      </select>
      <button type="submit" class="adam-button" data-bulk-submit="1"><?=_e('Apply')?></button>
      <small class="bin-bulk-note"><?=_e('Bulk only affects checked items.')?></small>
    </div>

    <div class="adam-table-wrapper">
      <table class="adam-table" style="margin-top:.5rem;">
        <thead><tr>
          <th style="width:40px"></th><th><?=_e('Title')?></th><th><?=_e('Type')?></th><th><?=_e('Storage')?></th><th><?=_e('Deleted')?></th><th><?=_e('Owner')?></th><th><?=_e('Actions')?></th>
        </tr></thead>
        <tbody>
        <?php if ($assets === []): ?>
          <tr><td colspan="7" style="padding:1rem;"><?=_e('Trash is empty.')?></td></tr>
        <?php else: foreach ($assets as $asset):
          $ownerId = (int)($asset['owner_id'] ?? 0);
          $rowCanRestore = user_can($pdo, $uid, $config['permissions']['restore'], ['owner_id' => $ownerId]);
          $rowCanPurge = user_can($pdo, $uid, $config['permissions']['purge'], ['owner_id' => $ownerId]);
          $title = trim((string)($asset['title'] ?? '')) ?: ((string)($asset['filename'] ?? '') ?: '#' . (int)$asset['id']);
        ?>
          <tr class="adam-row">
            <td style="text-align:center;"><?php if ($rowCanRestore || $rowCanPurge): ?><input class="asset-bin-checkbox" type="checkbox" name="ids[]" value="<?= (int)$asset['id'] ?>"><?php else: ?>&mdash;<?php endif; ?></td>
            <td><strong><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></strong><div style="color:var(--adam-muted);font-size:.85rem;"><?= htmlspecialchars((string)($asset['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div></td>
            <td><?= htmlspecialchars((string)($asset['mime'] ?? '-'), ENT_QUOTES, 'UTF-8') ?><div style="color:var(--adam-muted);font-size:.85rem;"><?= number_format(((int)($asset['size'] ?? 0)) / 1024, 1) ?> KB</div></td>
            <td><?= htmlspecialchars(ucfirst((string)($asset['storage_disk'] ?? '-')), ENT_QUOTES, 'UTF-8') ?><div style="color:var(--adam-muted);font-size:.85rem;"><?= htmlspecialchars((string)($asset['storage_path'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div></td>
            <td><?= htmlspecialchars(!empty($asset['deleted_at']) ? format_date_ddmmyyyy_time_bracket((string)$asset['deleted_at']) : '-', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($asset['owner_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <?php if ($rowCanRestore): ?><button type="submit" class="adam-link-button" formaction="<?= htmlspecialchars($base . '/admin/bin/' . $assetResource . '/restore.php', ENT_QUOTES, 'UTF-8') ?>" name="id" value="<?= (int)$asset['id'] ?>" data-single-action="restore" data-title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('rotate-ccw', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Restore')?></button><?php endif; ?>
              <?php if ($rowCanRestore && $rowCanPurge): ?> <span class="muted-divider">|</span> <?php endif; ?>
              <?php if ($rowCanPurge): ?><button type="submit" class="adam-link-button" formaction="<?= htmlspecialchars($base . '/admin/bin/' . $assetResource . '/delete_permanent.php', ENT_QUOTES, 'UTF-8') ?>" name="id" value="<?= (int)$asset['id'] ?>" data-single-action="delete_permanent" data-title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"><?= svg_ico('trash-2', '', ['style' => 'width:12px;height:12px;vertical-align:middle;margin-right:2px']) ?><?=_e('Delete Permanently')?></button><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </form>

  <?php if ($pages > 1): ?><nav class="adam-pagination" style="margin-top:1rem;">
    <?php for ($i = 1; $i <= $pages; $i++): $query = $_GET; $query['page'] = $route; $query['p'] = $i; ?>
      <?php if ($i === $pageNum): ?><strong><?= $i ?></strong><?php else: ?><a href="<?= htmlspecialchars($base . '/?' . http_build_query($query), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
  </nav><?php endif; ?>
</section>

<?php if ($pageToasts !== []) echo adiwira_bootstrap_toasts_script($pageToasts); ?>
<script>
(function(){
  const form = document.getElementById('assetBinForm');
  const selectAll = document.getElementById('assetBinSelectAll');
  const action = document.getElementById('assetBinAction');
  let confirmed = false;
  if (!form) return;
  if (selectAll) selectAll.addEventListener('change', function(){ document.querySelectorAll('.asset-bin-checkbox').forEach(cb => { cb.checked = this.checked; }); });
  form.addEventListener('submit', function(event){
    if (confirmed) {
      confirmed = false;
      return;
    }
    const submitter = event.submitter;
    const singleAction = submitter ? submitter.getAttribute('data-single-action') : '';
    let selectedAction = singleAction || (action ? action.value : '');
    let count = singleAction ? 1 : document.querySelectorAll('.asset-bin-checkbox:checked').length;
    if (!selectedAction || count < 1) {
      event.preventDefault();
      if (window.NewNotifToast) window.NewNotifToast.show({type:'error', title:<?= json_encode($resourceLabel) ?>, message:<?= json_encode(__('Select an action and at least one item.')) ?>});
      return;
    }
    event.preventDefault();
    const purge = selectedAction === 'delete_permanent';
    const title = submitter ? (submitter.getAttribute('data-title') || '') : String(count) + ' ' + <?= json_encode($resourceLabel) ?>;
    const options = {
      title: purge ? <?= json_encode(__('Delete permanently')) ?> : <?= json_encode(__('Restore')) ?>,
      message: purge ? <?= json_encode(__('This action cannot be undone. Permanently delete')) ?> + ' "' + title + '"?' : <?= json_encode(__('Restore from trash')) ?> + ' "' + title + '"?',
      confirmText: purge ? <?= json_encode(__('Yes, delete permanently')) ?> : <?= json_encode(__('Yes, restore')) ?>,
      cancelText: <?= json_encode(__('Cancel')) ?>
    };
    const ask = window.NewNotifConfirm && (purge ? window.NewNotifConfirm.danger : window.NewNotifConfirm.warning);
    (ask ? ask(options) : Promise.resolve(window.confirm(options.message))).then(ok => {
      if (!ok) return;
      confirmed = true;
      form.requestSubmit(submitter || undefined);
    });
  }, {once:false});
})();
</script>
