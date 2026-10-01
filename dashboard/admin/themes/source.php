<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.themes.manage', false);
adiwira_require_site_owner($pdo, false);

$folder = trim((string)($_GET['folder'] ?? ''));
$fileId = strtolower(trim((string)($_GET['file'] ?? '')));
$base = ADMIN_BASE_PATH;
$managerUrl = $base . '/?page=admin/themes/assign';
$service = theme_source_service($pdo);
$error = '';
$inventory = null;
$source = null;
$revisions = [];
$editState = null;
$dirty = null;

try {
    if ($folder === '') throw new InvalidArgumentException(__('Select an installed theme to inspect.'));
    $inventory = $service->inventory($folder);
    if ($fileId !== '') {
        $source = $service->source($folder, $fileId);
        if ($source === null) throw new RuntimeException(__('The requested PHP source file is unavailable.'));
        $revisions = $service->revisions($folder, $fileId);
    }
    $editState = $service->editState($folder, [
        'actor_id' => (int)$uid,
        'operation' => 'edit',
        'file' => is_array($source) ? [
            'id' => (string)$source['id'],
            'path' => (string)$source['path'],
            'sha256' => (string)$source['sha256'],
            'utf8' => (bool)$source['utf8'],
        ] : null,
    ]);
    $dirty = $service->dirtyState($folder);
} catch (Throwable $exception) {
    $error = __('Theme source is unavailable or unsafe.');
}

$theme = is_array($inventory['theme'] ?? null) ? $inventory['theme'] : [];
$files = is_array($inventory['files'] ?? null) ? $inventory['files'] : [];
$editorUrl = static fn(string $id): string => ADMIN_BASE_PATH . '/?' . http_build_query([
    'page' => 'admin/themes/source', 'folder' => $folder, 'file' => $id,
]);
$needsLiveAck = !empty($editState['active']) || !empty($editState['assigned']);
$needsStoreAck = !empty($editState['store']);
$dirtyCount = (int)($dirty['changed_count'] ?? 0);
$editorActions = '';
if ($error === '' && $theme !== [] && function_exists('do_action_isolated_output')) {
    $stmt = $pdo->prepare('SELECT * FROM themes WHERE folder_name = ? LIMIT 1');
    $stmt->execute([$folder]);
    $themeRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $actionContext = [
        'schema' => 1,
        'operation' => 'edit',
        'folder' => $folder,
        'file_id' => is_array($source) ? (string)$source['id'] : null,
        'relative_path' => is_array($source) ? (string)$source['path'] : null,
        'actor_id' => (int)$uid,
        'active' => (bool)($editState['active'] ?? false),
        'assigned' => (bool)($editState['assigned'] ?? false),
        'store' => (bool)($editState['store'] ?? false),
        'system' => (bool)($editState['system'] ?? false),
        'can_edit' => (bool)($editState['allowed'] ?? false),
        'admin_base_path' => ADMIN_BASE_PATH,
    ];
    $hook = do_action_isolated_output('theme_source_editor_actions', $themeRow, $actionContext, $pdo);
    $editorActions = (string)$hook['output'];
    foreach ($hook['errors'] as $hookError) error_log('[theme_source_editor_actions] ' . $hookError['message']);
}
?>
<section class="adam-card theme-source">
  <div class="theme-source__heading">
    <div>
      <h2 class="page-heading"><?=_e('Installed Theme PHP Source')?></h2>
      <?php if ($theme): ?><p class="adam-muted"><?=htmlspecialchars((string)$theme['name'], ENT_QUOTES, 'UTF-8')?> <code><?=htmlspecialchars((string)$theme['folder'], ENT_QUOTES, 'UTF-8')?></code></p><?php endif; ?>
    </div>
    <div class="theme-source__heading-actions">
      <?php if ($folder !== ''): ?>
      <form method="post" action="<?=htmlspecialchars($base . '/admin/themes/source_export.php', ENT_QUOTES, 'UTF-8')?>">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')?>">
        <input type="hidden" name="folder" value="<?=htmlspecialchars($folder, ENT_QUOTES, 'UTF-8')?>">
        <button class="adam-cancle" type="submit"><?=_e('Export PHP source')?></button>
      </form>
      <?php endif; ?>
      <?=$editorActions?>
      <a class="adam-cancle" href="<?=htmlspecialchars($managerUrl, ENT_QUOTES, 'UTF-8')?>"><?=_e('Back to Theme Manager')?></a>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="adam-notice adam-notice--error" role="alert"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div>
  <?php else: ?>
    <div class="theme-source__status">
      <span class="adam-status <?=($dirty['tracked'] ?? false) ? ($dirtyCount > 0 ? 'draft' : 'published') : 'unknown'?>">
        <?=htmlspecialchars(($dirty['tracked'] ?? false) ? ($dirtyCount > 0 ? __('Modified PHP') : __('PHP baseline clean')) : __('PHP baseline unverified'), ENT_QUOTES, 'UTF-8')?>
      </span>
      <?php if (!empty($editState['system'])): ?><span class="theme-source__badge"><?=_e('Read-only system theme')?></span><?php endif; ?>
      <?php if (!empty($editState['active'])): ?><span class="theme-source__badge"><?=_e('Active theme')?></span><?php endif; ?>
      <?php if (!empty($editState['assigned'])): ?><span class="theme-source__badge"><?=_e('Assigned theme')?></span><?php endif; ?>
      <?php if (!empty($editState['store'])): ?><span class="theme-source__badge"><?=_e('Store-managed theme')?></span><?php endif; ?>
    </div>

    <div class="theme-source__layout">
      <aside class="theme-source__files" aria-label="<?=htmlspecialchars(__('PHP source files'), ENT_QUOTES, 'UTF-8')?>">
        <h3><?=_e('PHP files')?></h3>
        <?php if ($files === []): ?><p class="adam-muted"><?=_e('No regular PHP files were found.')?></p><?php endif; ?>
        <?php foreach ($files as $file): ?>
          <a class="theme-source__file<?=($source['id'] ?? '') === $file['id'] ? ' is-current' : ''?>" href="<?=htmlspecialchars($editorUrl((string)$file['id']), ENT_QUOTES, 'UTF-8')?>">
            <span><?=htmlspecialchars((string)$file['path'], ENT_QUOTES, 'UTF-8')?></span>
            <small><?=number_format((int)$file['size'])?> B</small>
          </a>
        <?php endforeach; ?>
      </aside>

      <div class="theme-source__editor">
        <?php if ($source === null): ?>
          <div class="adam-empty"><h3><?=_e('Select a PHP file')?></h3><p><?=_e('Choose an existing file from the inventory to inspect its source and revisions.')?></p></div>
        <?php else: ?>
          <div class="theme-source__file-heading">
            <div><strong><?=htmlspecialchars((string)$source['path'], ENT_QUOTES, 'UTF-8')?></strong><small><?=sprintf(htmlspecialchars(__('%d lines, %d bytes'), ENT_QUOTES, 'UTF-8'), (int)$source['lines'], (int)$source['size'])?></small></div>
          </div>
          <?php if (empty($editState['allowed'])): ?><div class="adam-notice adam-notice--info" role="status"><?=htmlspecialchars((string)$editState['message'], ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
          <form method="post" action="<?=htmlspecialchars($base . '/admin/themes/source_save.php', ENT_QUOTES, 'UTF-8')?>" data-unsaved-guard id="theme-source-form">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="folder" value="<?=htmlspecialchars($folder, ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="file" value="<?=htmlspecialchars((string)$source['id'], ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="expected_hash" value="<?=htmlspecialchars((string)$source['sha256'], ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="target_token" value="<?=htmlspecialchars((string)$source['target_token'], ENT_QUOTES, 'UTF-8')?>">
            <textarea id="theme-source-code" name="source" spellcheck="false"<?=empty($editState['allowed']) ? ' readonly' : ''?>><?=htmlspecialchars((string)$source['source'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></textarea>
            <?php if (!empty($editState['allowed'])): ?>
              <div class="theme-source__risk" role="group" aria-label="<?=htmlspecialchars(__('Required risk acknowledgement'), ENT_QUOTES, 'UTF-8')?>">
                <p><strong><?=_e('Editing PHP executes server-side code after save.')?></strong> <?=_e('PHP lint checks syntax only. Unsaved source is never executed or previewed.')?></p>
                <label><input type="checkbox" name="ack_direct" value="1" required> <?=_e('I understand that saving PHP can break or compromise this site.')?></label>
                <?php if ($needsLiveAck): ?><label><input type="checkbox" name="ack_live" value="1" required> <?=_e('I understand this active or assigned theme can affect live requests immediately.')?></label><?php endif; ?>
                <?php if ($needsStoreAck): ?><label><input type="checkbox" name="ack_store" value="1" required> <?=_e('I understand a Store update will replace these local PHP changes unless I export or fork them.')?></label><?php endif; ?>
              </div>
              <label class="theme-source__note"><?=_e('Change note (optional)')?><input class="inpud" type="text" name="note" maxlength="500"></label>
              <button class="adam-button" type="submit"><?=_e('Save PHP source')?></button>
            <?php endif; ?>
          </form>

          <section class="theme-source__revisions">
            <h3><?=_e('Revisions')?></h3>
            <?php if ($revisions === []): ?><p class="adam-muted"><?=_e('No revisions are available for this file.')?></p><?php endif; ?>
            <?php foreach ($revisions as $revision): ?>
              <div class="theme-source__revision">
                <div><strong><?=htmlspecialchars((string)$revision['created_at'], ENT_QUOTES, 'UTF-8')?></strong><small><?=htmlspecialchars((string)($revision['change_note'] ?: __('No change note')), ENT_QUOTES, 'UTF-8')?></small></div>
                <?php if (!empty($editState['allowed'])): ?>
                <form method="post" action="<?=htmlspecialchars($base . '/admin/themes/source_restore.php', ENT_QUOTES, 'UTF-8')?>">
                  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')?>">
                  <input type="hidden" name="folder" value="<?=htmlspecialchars($folder, ENT_QUOTES, 'UTF-8')?>">
                  <input type="hidden" name="file" value="<?=htmlspecialchars((string)$source['id'], ENT_QUOTES, 'UTF-8')?>">
                  <input type="hidden" name="revision" value="<?=htmlspecialchars((string)$revision['revision_id'], ENT_QUOTES, 'UTF-8')?>">
                  <input type="hidden" name="expected_hash" value="<?=htmlspecialchars((string)$source['sha256'], ENT_QUOTES, 'UTF-8')?>">
                  <input type="hidden" name="target_token" value="<?=htmlspecialchars((string)$source['target_token'], ENT_QUOTES, 'UTF-8')?>">
                  <label><input type="checkbox" name="ack_direct" value="1" required> <?=_e('Revision restore requires explicit risk acknowledgement.')?></label>
                  <?php if ($needsLiveAck): ?><label><input type="checkbox" name="ack_live" value="1" required> <?=_e('Acknowledge live impact')?></label><?php endif; ?>
                  <?php if ($needsStoreAck): ?><label><input type="checkbox" name="ack_store" value="1" required> <?=_e('Acknowledge Store replacement risk')?></label><?php endif; ?>
                  <button class="adam-cancle" type="submit"><?=_e('Restore this revision')?></button>
                </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </section>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<style>
.theme-source__heading,.theme-source__heading-actions,.theme-source__status,.theme-source__file-heading,.theme-source__revision{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.theme-source__heading-actions,.theme-source__status{justify-content:flex-start}.theme-source__heading-actions form{margin:0}.theme-source__layout{display:grid;grid-template-columns:minmax(220px,280px) minmax(0,1fr);gap:18px;margin-top:18px}.theme-source__files{border:1px solid var(--adam-border-2);border-radius:8px;overflow:hidden;align-self:start}.theme-source__files h3{margin:0;padding:12px}.theme-source__file{display:flex;flex-direction:column;gap:3px;padding:9px 12px;border-top:1px solid var(--adam-border-2);color:var(--adam-text);text-decoration:none;overflow-wrap:anywhere}.theme-source__file:hover,.theme-source__file.is-current{background:var(--adam-surface-2);color:var(--adam-primary)}.theme-source__file small,.theme-source__file-heading small,.theme-source__revision small{display:block;color:var(--adam-muted);font-size:.78rem}.theme-source__badge{padding:4px 8px;border:1px solid var(--adam-border-2);border-radius:999px;font-size:.78rem}.theme-source .CodeMirror{height:clamp(480px,65vh,760px);border:1px solid var(--adam-border-2);border-radius:8px;margin:10px 0}.theme-source__risk{display:grid;gap:7px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.35);border-radius:8px;padding:12px;margin:10px 0}.theme-source__risk p{margin:0}.theme-source__note{display:block;margin:10px 0}.theme-source__revisions{margin-top:24px;border-top:1px solid var(--adam-border-2);padding-top:14px}.theme-source__revision{padding:10px 0;border-bottom:1px solid var(--adam-border-2)}.theme-source__revision form{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0}@media(max-width:800px){.theme-source__layout{grid-template-columns:1fr}.theme-source__files{max-height:260px;overflow:auto}.theme-source .CodeMirror{height:55vh}}
</style>
<?php if ($source !== null): ?>
<script>
(function(){
  var textarea=document.getElementById('theme-source-code');
  if(!textarea||typeof CodeMirror==='undefined')return;
  var editor=CodeMirror.fromTextArea(textarea,{mode:'application/x-httpd-php',lineNumbers:true,lineWrapping:false,matchBrackets:true,autoCloseBrackets:true,indentUnit:4,readOnly:textarea.readOnly?'nocursor':false,theme:document.documentElement.dataset.theme==='dark'?'dracula':'default',foldGutter:true,gutters:['CodeMirror-linenumbers','CodeMirror-foldgutter']});
  editor.on('change',function(){editor.save();textarea.dispatchEvent(new Event('input',{bubbles:true}));});
  var form=document.getElementById('theme-source-form');
  if(form)form.addEventListener('submit',function(){editor.save();});
})();
</script>
<?php endif; ?>
