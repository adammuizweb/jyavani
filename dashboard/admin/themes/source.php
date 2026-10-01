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
$themeName = trim((string)($theme['name'] ?? ''));
$themeFolder = trim((string)($theme['folder'] ?? $folder));
$themeDisplayName = $themeName !== '' ? $themeName : $themeFolder;
$showThemeFolder = $themeFolder !== '' && strcasecmp($themeDisplayName, $themeFolder) !== 0;
if (!$showThemeFolder && $themeDisplayName !== '') {
    $themeDisplayName = ucfirst(str_replace(['-', '_'], ' ', $themeDisplayName));
}
$editorUrl = static fn(string $id): string => ADMIN_BASE_PATH . '/?' . http_build_query([
    'page' => 'admin/themes/source', 'folder' => $folder, 'file' => $id,
]);
$needsLiveAck = !empty($editState['active']) || !empty($editState['assigned']);
$needsStoreAck = !empty($editState['store']);
$dirtyCount = (int)($dirty['changed_count'] ?? 0);
$sourceSlotKeys = [];
if (is_array($source) && function_exists('theme_slot_definitions')) {
    try {
        $slotDefinitions = theme_slot_definitions($pdo, ['scope' => 'theme-source-editor', 'theme_folder' => $folder]);
        if (count($slotDefinitions) <= 256) {
            foreach ($slotDefinitions as $slotKey => $definition) {
                if (is_string($slotKey) && is_array($definition)
                    && is_string($definition['template'] ?? null)
                    && hash_equals((string)$source['path'], $definition['template'])) {
                    $sourceSlotKeys[] = $slotKey;
                }
            }
        }
    } catch (Throwable $slotError) {
        error_log('[theme_source_editor_actions] Slot lookup failed: ' . $slotError->getMessage());
    }
}
$editorActions = '';
$editorContext = '';
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
        'slot_keys' => $sourceSlotKeys,
        'actor_id' => (int)$uid,
        'active' => (bool)($editState['active'] ?? false),
        'assigned' => (bool)($editState['assigned'] ?? false),
        'store' => (bool)($editState['store'] ?? false),
        'system' => (bool)($editState['system'] ?? false),
        'can_edit' => (bool)($editState['allowed'] ?? false),
        'admin_base_path' => ADMIN_BASE_PATH,
        'return_url' => is_array($source) ? $editorUrl((string)$source['id']) : $editorUrl(''),
    ];
    $hook = do_action_isolated_output('theme_source_editor_actions', $themeRow, $actionContext, $pdo);
    $editorActions = (string)$hook['output'];
    foreach ($hook['errors'] as $hookError) error_log('[theme_source_editor_actions] ' . $hookError['message']);
    if (is_array($source)) {
        $contextHook = do_action_isolated_output('theme_source_editor_context', $themeRow, $actionContext, $pdo);
        $editorContext = (string)$contextHook['output'];
        foreach ($contextHook['errors'] as $hookError) error_log('[theme_source_editor_context] ' . $hookError['message']);
        if (preg_match('/<\s*\/?\s*(?:form|input|button|select|textarea)\b/i', $editorContext) === 1) {
            error_log('[theme_source_editor_context] Form controls are not allowed in source context panels.');
            $editorContext = '';
        }
    }
}
?>
<section class="adam-card theme-source">
  <div class="theme-source__heading">
    <div>
      <h2 class="page-heading"><?=_e('Installed Theme PHP Source')?></h2>
      <?php if ($theme): ?>
      <div class="theme-source__identity">
        <span class="theme-source__identity-icon" aria-hidden="true"><?=svg_ico('palette')?></span>
        <span class="theme-source__identity-copy"><small><?=_e('Theme')?></small><strong><?=htmlspecialchars($themeDisplayName, ENT_QUOTES, 'UTF-8')?></strong></span>
        <?php if ($showThemeFolder): ?><code><?=htmlspecialchars($themeFolder, ENT_QUOTES, 'UTF-8')?></code><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="theme-source__heading-actions">
      <div class="theme-source-action-group theme-source-action-group--core">
        <span class="theme-source-action-owner"><?=_e('Core')?></span>
        <?php if ($folder !== ''): ?>
        <form method="post" action="<?=htmlspecialchars($base . '/admin/themes/source_export.php', ENT_QUOTES, 'UTF-8')?>">
          <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')?>">
          <input type="hidden" name="folder" value="<?=htmlspecialchars($folder, ENT_QUOTES, 'UTF-8')?>">
          <button class="adam-cancle theme-source-action theme-source-action--core" type="submit"><?=_e('Export PHP source')?></button>
        </form>
        <?php endif; ?>
        <a class="adam-cancle theme-source-action theme-source-action--core" href="<?=htmlspecialchars($managerUrl, ENT_QUOTES, 'UTF-8')?>"><?=_e('Back to Theme Manager')?></a>
      </div>
      <?=$editorActions?>
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
          <?=$editorContext?>
          <?php if (empty($editState['allowed'])): ?><div class="adam-notice adam-notice--info" role="status"><?=htmlspecialchars((string)$editState['message'], ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
          <section class="theme-source__tools" data-theme-source-tools>
            <div class="theme-source__tools-bar">
              <div class="theme-source__tabs" role="tablist" aria-label="<?=htmlspecialchars(__('Installed Theme PHP Source'), ENT_QUOTES, 'UTF-8')?>">
                <?php if (!empty($editState['allowed'])): ?>
                  <button class="theme-source__tab is-active" id="theme-source-save-tab" type="button" role="tab" aria-selected="true" aria-controls="theme-source-save-panel"><?=_e('Save PHP source')?></button>
                <?php endif; ?>
                <button class="theme-source__tab<?=empty($editState['allowed']) ? ' is-active' : ''?>" id="theme-source-revisions-tab" type="button" role="tab" aria-selected="<?=empty($editState['allowed']) ? 'true' : 'false'?>" aria-controls="theme-source-revisions-panel"><?=_e('Revisions')?> <span class="theme-source__tab-count"><?=number_format(count($revisions))?></span></button>
              </div>
              <button class="theme-source__tools-toggle" type="button" aria-expanded="true" aria-controls="theme-source-tools-body" aria-label="<?=htmlspecialchars(__('Close'), ENT_QUOTES, 'UTF-8')?>" title="<?=htmlspecialchars(__('Close'), ENT_QUOTES, 'UTF-8')?>" data-open-label="<?=htmlspecialchars(__('Open'), ENT_QUOTES, 'UTF-8')?>" data-close-label="<?=htmlspecialchars(__('Close'), ENT_QUOTES, 'UTF-8')?>"><span aria-hidden="true"></span></button>
            </div>
            <div class="theme-source__tools-body" id="theme-source-tools-body">
              <?php if (!empty($editState['allowed'])): ?>
                <section class="theme-source__tool-panel" id="theme-source-save-panel" role="tabpanel" aria-labelledby="theme-source-save-tab">
                  <div class="theme-source__risk" role="group" aria-label="<?=htmlspecialchars(__('Required risk acknowledgement'), ENT_QUOTES, 'UTF-8')?>">
                    <p><strong><?=_e('Editing PHP executes server-side code after save.')?></strong> <?=_e('PHP lint checks syntax only. Unsaved source is never executed or previewed.')?></p>
                    <label><input form="theme-source-form" type="checkbox" name="ack_direct" value="1" required> <?=_e('I understand that saving PHP can break or compromise this site.')?></label>
                    <?php if ($needsLiveAck): ?><label><input form="theme-source-form" type="checkbox" name="ack_live" value="1" required> <?=_e('I understand this active or assigned theme can affect live requests immediately.')?></label><?php endif; ?>
                    <?php if ($needsStoreAck): ?><label><input form="theme-source-form" type="checkbox" name="ack_store" value="1" required> <?=_e('I understand a Store update will replace these local PHP changes unless I export or fork them.')?></label><?php endif; ?>
                  </div>
                  <label class="theme-source__note"><?=_e('Change note (optional)')?><input form="theme-source-form" class="inpud" type="text" name="note" maxlength="500"></label>
                  <div class="theme-source__save-row">
                    <button class="adam-button" form="theme-source-form" type="submit"><?=_e('Save PHP source')?></button>
                  </div>
                </section>
              <?php endif; ?>

              <section class="theme-source__tool-panel" id="theme-source-revisions-panel" role="tabpanel" aria-labelledby="theme-source-revisions-tab"<?=!empty($editState['allowed']) ? ' hidden' : ''?>>
                <div class="theme-source__revisions">
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
                </div>
              </section>
            </div>
          </section>

          <form method="post" action="<?=htmlspecialchars($base . '/admin/themes/source_save.php', ENT_QUOTES, 'UTF-8')?>" data-unsaved-guard id="theme-source-form">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="folder" value="<?=htmlspecialchars($folder, ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="file" value="<?=htmlspecialchars((string)$source['id'], ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="expected_hash" value="<?=htmlspecialchars((string)$source['sha256'], ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="target_token" value="<?=htmlspecialchars((string)$source['target_token'], ENT_QUOTES, 'UTF-8')?>">
            <textarea id="theme-source-code" name="source" spellcheck="false"<?=empty($editState['allowed']) ? ' readonly' : ''?>><?=htmlspecialchars((string)$source['source'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></textarea>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<style>
.theme-source__heading,
.theme-source__heading-actions,
.theme-source__status,
.theme-source__file-heading,
.theme-source__revision{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
}
.theme-source__heading-actions,
.theme-source__status{
  justify-content:flex-start;
}
.theme-source__heading-actions{
  gap:8px;
  align-items:flex-start;
}
.theme-source__heading{
  margin-bottom:14px;
}
.theme-source__identity{
  display:flex;
  align-items:center;
  gap:9px;
  width:max-content;
  max-width:100%;
  margin-top:7px;
  color:var(--adam-text-2);
}
.theme-source__identity-icon{
  display:grid;
  place-items:center;
  flex:0 0 auto;
  width:30px;
  height:30px;
  border:1px solid rgba(37,99,235,.24);
  border-radius:9px;
  background:rgba(37,99,235,.09);
  color:#2563eb;
}
.theme-source__identity-icon svg{
  width:15px;
  height:15px;
}
.theme-source__identity-copy{
  display:grid;
  min-width:0;
  gap:1px;
}
.theme-source__identity-copy small{
  color:var(--adam-muted);
  font-size:.66rem;
  font-weight:750;
  letter-spacing:.055em;
  line-height:1;
  text-transform:uppercase;
}
.theme-source__identity-copy strong{
  overflow:hidden;
  font-size:.88rem;
  line-height:1.2;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.theme-source__identity code{
  overflow:hidden;
  max-width:180px;
  padding:2px 6px;
  border:1px solid var(--adam-border-2);
  border-radius:6px;
  color:var(--adam-muted);
  font-size:.69rem;
  text-overflow:ellipsis;
  white-space:nowrap;
}
html.theme-dark .theme-source__identity-icon,
html:not(.theme-light):not(.theme-dark) .theme-source__identity-icon{
  border-color:rgba(147,197,253,.3);
  background:rgba(59,130,246,.16);
  color:#bfdbfe;
}
.theme-source__heading-actions form{
  margin:0;
}
.theme-source__heading-actions > a,
.theme-source__heading-actions > form > button{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:38px;
  padding:8px 13px;
  border:1px solid var(--adam-border-2);
  border-radius:10px;
  background:var(--adam-card);
  color:var(--adam-text-2);
  box-shadow:0 1px 2px var(--adam-border-soft);
  font:inherit;
  font-size:.86rem;
  font-weight:700;
  line-height:1.2;
  text-decoration:none!important;
  cursor:pointer;
  transition:border-color .16s ease,box-shadow .16s ease,color .16s ease,transform .16s ease;
}
.theme-source__heading-actions > a:hover,
.theme-source__heading-actions > form > button:hover{
  border-color:var(--adam-primary);
  color:var(--adam-primary);
  box-shadow:0 6px 16px var(--adam-border-soft);
  text-decoration:none!important;
  transform:translateY(-1px);
}
.theme-source__heading-actions > a:focus-visible,
.theme-source__heading-actions > form > button:focus-visible{
  outline:3px solid var(--adam-focus);
  outline-offset:2px;
  text-decoration:none!important;
}
.theme-source-action-group{
  display:flex;
  align-items:center;
  flex-wrap:wrap;
  gap:6px;
  min-height:40px;
  padding:5px;
  border:1px solid var(--adam-border-2);
  border-radius:11px;
  background:var(--adam-surface-2);
}
.theme-source-action-owner{
  display:inline-flex;
  align-items:center;
  gap:7px;
  padding:0 7px 0 5px;
  color:var(--adam-muted);
  font-size:.72rem;
  font-weight:800;
  letter-spacing:.045em;
  text-transform:uppercase;
}
.theme-source-action-owner::before{
  width:7px;
  height:7px;
  border-radius:999px;
  background:currentColor;
  box-shadow:0 0 0 3px color-mix(in srgb,currentColor 16%,transparent);
  content:"";
}
.theme-source-action{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:32px;
  padding:6px 10px;
  border:1px solid var(--adam-border-2);
  border-radius:8px;
  background:var(--adam-card);
  color:var(--adam-text-2);
  font:inherit;
  font-size:.8rem;
  font-weight:750;
  line-height:1.2;
  text-decoration:none!important;
  cursor:pointer;
  transition:border-color .16s ease,background .16s ease,color .16s ease,box-shadow .16s ease;
}
.theme-source-action:hover{
  text-decoration:none!important;
}
.theme-source-action:focus-visible{
  outline:3px solid var(--adam-focus);
  outline-offset:2px;
}
.theme-source-action-group--core{
  border-color:rgba(37,99,235,.3);
  background:linear-gradient(135deg,rgba(37,99,235,.1),rgba(59,130,246,.035));
}
.theme-source-action-group--core .theme-source-action-owner{
  color:#2563eb;
}
.theme-source-action--core{
  border-color:rgba(37,99,235,.35);
  color:#1d4ed8;
}
.theme-source-action--core:hover{
  border-color:#2563eb;
  background:rgba(37,99,235,.1);
  color:#1e40af;
  box-shadow:0 4px 10px rgba(37,99,235,.12);
}
html.theme-dark .theme-source-action-group--core,
html:not(.theme-light):not(.theme-dark) .theme-source-action-group--core{
  border-color:rgba(147,197,253,.32);
  background:linear-gradient(135deg,rgba(59,130,246,.2),rgba(37,99,235,.07));
}
html.theme-dark .theme-source-action-group--core .theme-source-action-owner,
html:not(.theme-light):not(.theme-dark) .theme-source-action-group--core .theme-source-action-owner,
html.theme-dark .theme-source-action--core,
html:not(.theme-light):not(.theme-dark) .theme-source-action--core{
  color:#bfdbfe;
}
.theme-source__layout{
  display:grid;
  grid-template-columns:minmax(220px,280px) minmax(0,1fr);
  gap:18px;
  margin-top:18px;
}
.theme-source__editor{
  min-width:0;
}
.theme-source__files{
  border:1px solid var(--adam-border-2);
  border-radius:10px;
  align-self:start;
}
.theme-source__files h3{
  margin:0;
  padding:12px;
  border-bottom:1px solid var(--adam-border-2);
}
.theme-source__file{
  display:flex;
  flex-direction:column;
  gap:3px;
  padding:9px 12px;
  border-top:1px solid var(--adam-border-2);
  color:var(--adam-text);
  text-decoration:none;
  overflow-wrap:anywhere;
}
.theme-source__files h3 + .theme-source__file{
  border-top:0;
}
.theme-source__file:hover,
.theme-source__file.is-current{
  background:var(--adam-surface-2);
  color:var(--adam-primary);
  text-decoration:none;
}
.theme-source__file small,
.theme-source__file-heading small,
.theme-source__revision small{
  display:block;
  color:var(--adam-muted);
  font-size:.78rem;
}
.theme-source__badge{
  padding:4px 8px;
  border:1px solid var(--adam-border-2);
  border-radius:999px;
  font-size:.78rem;
}
.theme-source__tools{
  position:sticky;
  top:72px;
  z-index:30;
  margin:10px 0;
  border:1px solid var(--adam-border-2);
  border-radius:11px;
  background:color-mix(in srgb,var(--adam-card) 92%,transparent);
  box-shadow:0 10px 24px rgba(0,0,0,.08);
  backdrop-filter:blur(14px) saturate(1.2);
  -webkit-backdrop-filter:blur(14px) saturate(1.2);
  overflow:hidden;
}
.theme-source__tools-bar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  min-height:50px;
  padding:6px;
  background:var(--adam-card);
}
.theme-source__tabs{
  display:flex;
  align-items:center;
  gap:3px;
  min-width:0;
  padding:3px;
  border-radius:8px;
  background:var(--adam-surface-3);
}
.theme-source__tab{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:7px;
  min-height:34px;
  padding:7px 11px;
  border:0;
  border-radius:7px;
  background:transparent;
  color:var(--adam-muted);
  font:inherit;
  font-size:.8rem;
  font-weight:750;
  cursor:pointer;
}
.theme-source__tab:hover{
  color:var(--adam-text);
}
.theme-source__tab.is-active{
  background:var(--adam-card);
  color:var(--adam-primary);
  box-shadow:0 1px 4px rgba(0,0,0,.1);
}
.theme-source__tab:focus-visible,
.theme-source__tools-toggle:focus-visible{
  outline:3px solid var(--adam-focus);
  outline-offset:1px;
}
.theme-source__tab-count{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:20px;
  height:20px;
  padding:0 6px;
  border-radius:999px;
  background:var(--adam-primary-soft);
  color:var(--adam-primary);
  font-size:.72rem;
}
.theme-source__tools-toggle{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  flex:0 0 auto;
  width:36px;
  height:36px;
  border:1px solid var(--adam-border-2);
  border-radius:8px;
  background:var(--adam-card);
  color:var(--adam-muted);
  cursor:pointer;
}
.theme-source__tools-toggle:hover{
  border-color:var(--adam-primary);
  color:var(--adam-primary);
}
.theme-source__tools-toggle span{
  width:8px;
  height:8px;
  border-right:2px solid currentColor;
  border-bottom:2px solid currentColor;
  transform:rotate(225deg) translate(-1px,-1px);
  transition:transform .16s ease;
}
.theme-source__tools-toggle[aria-expanded="false"] span{
  transform:rotate(45deg) translate(-1px,-1px);
}
.theme-source__tools-body{
  max-height:min(52vh,480px);
  border-top:1px solid var(--adam-border-2);
  overflow:auto;
  scrollbar-gutter:stable;
}
.theme-source__tool-panel{
  min-width:0;
  padding:14px;
}
.theme-source .CodeMirror{
  height:auto;
  border:1px solid var(--adam-border-2);
  border-radius:10px;
  margin:0;
}
.theme-source__risk{
  display:grid;
  gap:8px;
  background:rgba(239,68,68,.08);
  border:1px solid rgba(239,68,68,.35);
  border-radius:8px;
  padding:12px;
  margin:0 0 10px;
}
.theme-source__risk p{
  margin:0;
}
.theme-source__risk label,
.theme-source__revision form label{
  display:flex;
  align-items:flex-start;
  gap:7px;
}
.theme-source__risk input,
.theme-source__revision form input[type="checkbox"]{
  flex:0 0 auto;
  margin-top:3px;
}
.theme-source__note{
  display:grid;
  gap:6px;
  margin:10px 0;
  color:var(--adam-text-2);
  font-size:.84rem;
  font-weight:700;
}
.theme-source__note input{
  width:100%;
}
.theme-source__save-row{
  display:flex;
  justify-content:flex-end;
}
.theme-source__revisions{
  margin:0;
}
.theme-source__revisions > .adam-muted{
  margin:12px 0 0;
}
.theme-source__revision{
  align-items:flex-start;
  padding:12px 0;
  border-bottom:1px solid var(--adam-border-2);
}
.theme-source__revision:last-child{
  border-bottom:0;
}
.theme-source__revision form{
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
  margin:0;
}
.theme-source__revision form label{
  flex-basis:100%;
  font-size:.8rem;
}
.theme-source__revision button{
  text-decoration:none!important;
}
@media(max-width:800px){
  .theme-source__layout{
    grid-template-columns:1fr;
  }
  .theme-source__tools{
    position:static;
  }
  .theme-source__tabs{
    overflow-x:auto;
  }
  .theme-source__heading-actions{
    width:100%;
  }
  .theme-source__heading-actions > a,
  .theme-source__heading-actions > form,
  .theme-source__heading-actions > form > button{
    flex:1 1 auto;
  }
  .theme-source-action-group{
    width:100%;
  }
}
</style>
<?php if ($source !== null): ?>
<script>
(function(){
  var tools=document.querySelector('[data-theme-source-tools]');
  if(tools){
    var tabs=Array.prototype.slice.call(tools.querySelectorAll('[role="tab"]'));
    var panels=Array.prototype.slice.call(tools.querySelectorAll('[role="tabpanel"]'));
    var toolsBody=document.getElementById('theme-source-tools-body');
    var toolsToggle=tools.querySelector('.theme-source__tools-toggle');
    var activateTab=function(tab){
      tabs.forEach(function(candidate){
        var active=candidate===tab;
        candidate.classList.toggle('is-active',active);
        candidate.setAttribute('aria-selected',active?'true':'false');
        candidate.tabIndex=active?0:-1;
      });
      panels.forEach(function(panel){panel.hidden=panel.id!==tab.getAttribute('aria-controls');});
      if(toolsBody&&toolsToggle&&toolsBody.hidden){
        toolsBody.hidden=false;
        toolsToggle.setAttribute('aria-expanded','true');
        toolsToggle.setAttribute('aria-label',toolsToggle.dataset.closeLabel||'Close');
        toolsToggle.title=toolsToggle.dataset.closeLabel||'Close';
      }
    };
    tabs.forEach(function(tab,index){
      tab.addEventListener('click',function(){activateTab(tab);});
      tab.addEventListener('keydown',function(event){
        var next=index;
        if(event.key==='ArrowRight')next=(index+1)%tabs.length;
        else if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;
        else if(event.key==='Home')next=0;
        else if(event.key==='End')next=tabs.length-1;
        else return;
        event.preventDefault();
        activateTab(tabs[next]);
        tabs[next].focus();
      });
    });
    if(toolsToggle&&toolsBody)toolsToggle.addEventListener('click',function(){
      var expanded=toolsToggle.getAttribute('aria-expanded')==='true';
      toolsBody.hidden=expanded;
      toolsToggle.setAttribute('aria-expanded',expanded?'false':'true');
      var label=expanded?(toolsToggle.dataset.openLabel||'Open'):(toolsToggle.dataset.closeLabel||'Close');
      toolsToggle.setAttribute('aria-label',label);
      toolsToggle.title=label;
    });
  }

  var textarea=document.getElementById('theme-source-code');
  if(!textarea||typeof CodeMirror==='undefined')return;
  var editor=CodeMirror.fromTextArea(textarea,{mode:'application/x-httpd-php',lineNumbers:true,lineWrapping:false,matchBrackets:true,autoCloseBrackets:true,indentUnit:4,readOnly:textarea.readOnly?'nocursor':false,theme:document.documentElement.dataset.theme==='dark'?'dracula':'default',foldGutter:true,gutters:['CodeMirror-linenumbers','CodeMirror-foldgutter']});
  editor.on('change',function(){editor.save();textarea.dispatchEvent(new Event('input',{bubbles:true}));});
  var form=document.getElementById('theme-source-form');
  if(form)form.addEventListener('submit',function(){editor.save();});
  var files=document.querySelector('.theme-source__files');
  var syncEditorHeight=function(){
    if(!files)return;
    var height=Math.ceil(files.getBoundingClientRect().height);
    if(height>0)editor.setSize(null,height);
  };
  syncEditorHeight();
  requestAnimationFrame(syncEditorHeight);
  window.addEventListener('resize',syncEditorHeight,{passive:true});
  if(typeof ResizeObserver!=='undefined')new ResizeObserver(syncEditorHeight).observe(files);
  if(document.fonts&&document.fonts.ready)document.fonts.ready.then(syncEditorHeight);
})();
</script>
<?php endif; ?>
