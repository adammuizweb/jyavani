<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.settings.manage', false);
adiwira_require_site_owner($pdo, false);

$base = ADMIN_BASE_PATH;
$errors = [];
$toasts = [];
$report = site_health_read_report();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = is_string($_POST['site_health_action'] ?? null) ? $_POST['site_health_action'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if ($action !== 'run_site_health') $errors[] = __('Invalid Site Health request.');
    if (!adiwira_csrf_validate($token)) $errors[] = __('Invalid CSRF token.');

    if ($errors === []) {
        try {
            $report = site_health_run_and_store(dirname(DASH_PATH), (string)PUBLIC_PATH);
            $toasts[] = ['type' => 'success', 'message' => __('Site Health scan completed.')];
        } catch (Throwable $error) {
            error_log('[site-health] Core integrity scan failed');
            $errors[] = __('Site Health scan could not be completed.');
        }
    }
}

$statusLabels = [
    'clean' => __('Clean'),
    'unverified' => __('Unverified'),
    'modified' => __('Modified'),
    'contaminated' => __('Contaminated'),
    'infected' => __('Infected'),
    'scanned' => __('Scanned'),
];
$reasonLabels = [
    'hash_mismatch' => __('File contents differ from the trusted release.'),
    'missing_file' => __('A required Core file is missing.'),
    'unsafe_path' => __('The file path is unsafe or crosses a filesystem boundary.'),
    'unsafe_file_type' => __('The expected file is a symbolic link or unsupported file type.'),
    'file_unreadable' => __('The file could not be read reliably.'),
    'unexpected_file' => __('An unexpected file exists in a Core-managed location.'),
    'unexpected_symlink' => __('An unexpected symbolic link exists in a Core-managed location.'),
    'unexpected_special_file' => __('An unexpected special file exists in a Core-managed location.'),
    'inventory_unreadable' => __('A Core-managed location could not be inventoried.'),
    'inventory_incomplete' => __('The Core file inventory did not complete.'),
    'inventory_limit_reached' => __('The Core file inventory reached its safety limit.'),
    'scan_limit_reached' => __('The Core scan reached its time or size limit.'),
    'file_size_limit' => __('The file exceeds the Core scan size limit.'),
    'baseline_unavailable' => __('A usable Core integrity baseline is not available.'),
    'scan_root_unreadable' => __('A scan location could not be read.'),
    'executable_upload' => __('Executable or server configuration content was found in site-owned files.'),
    'image_mime_mismatch' => __('The image extension does not match the detected file type.'),
    'mime_detector_unavailable' => __('The file type detector was unavailable or could not identify the image.'),
];
$extensionReasonLabels = [
    'extension_manifest_invalid' => __('The extension manifest is missing or invalid.'),
    'store_file_manifest_unavailable' => __('The extension declares Store metadata, but no trusted file manifest is available for verification.'),
    'extension_source_unverified' => __('The extension has no independently trusted file manifest.'),
    'unsafe_extension_artifact' => __('The extension contains a symbolic link or unsupported file type.'),
    'extension_root_unreadable' => __('The extension root could not be read.'),
    'extension_inventory_incomplete' => __('The extension inventory did not complete.'),
];
$coreReport = is_array($report['components']['core'] ?? null) ? $report['components']['core'] : null;
$extensions = is_array($report['components']['extensions'] ?? null) ? $report['components']['extensions'] : null;
$content = is_array($report['components']['content'] ?? null) ? $report['components']['content'] : null;
$status = is_array($report) ? (string)($report['status'] ?? 'unverified') : 'unverified';
$coreStatus = is_array($coreReport) ? (string)($coreReport['status'] ?? 'unverified') : 'unverified';
$summary = is_array($coreReport['summary'] ?? null) ? $coreReport['summary'] : [];
$baseline = is_array($coreReport['baseline'] ?? null) ? $coreReport['baseline'] : [];
$findings = is_array($coreReport['findings'] ?? null) ? $coreReport['findings'] : [];
$extensionItems = is_array($extensions['items'] ?? null) ? $extensions['items'] : [];
$contentFindings = is_array($content['findings'] ?? null) ? $content['findings'] : [];
$cleanPercentage = null;
if (is_array($coreReport) && (int)($summary['expected'] ?? 0) > 0) {
    $observedTotal = array_sum(array_map('intval', array_intersect_key($summary, array_flip(['clean', 'unverified', 'modified', 'contaminated', 'infected']))));
    $cleanTotal = max((int)$summary['expected'], $observedTotal);
    $cleanPercentage = min(100, max(0, (int)round(((int)($summary['clean'] ?? 0) / $cleanTotal) * 100)));
}
$statusLabel = $statusLabels[$status] ?? $statusLabels['unverified'];
$scanTime = is_array($report) && (int)($report['completed_at'] ?? 0) > 0
    ? format_date_ddmmyyyy_time_bracket(date('Y-m-d H:i:s', (int)$report['completed_at']))
    : __('Never scanned');
$disclaimer = __('Site Health reports the state observed during the most recent scan. A clean result means that verified files matched their trusted manifests and no known suspicious condition was detected within the scanned scope. It does not prove that the website, its code, database, server, dependencies, or network is free from vulnerabilities or compromise. Files can change after a scan, scanners can produce false positives or false negatives, and some resources may be inaccessible to the CMS. Use server-level monitoring, backups, access control, timely updates, and independent security review as additional safeguards.');
?>
<style>
.site-health{max-width:1100px;margin:18px auto;color:var(--adam-text,#0f172a)}
.site-health__topline{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.site-health__back{display:inline-flex;align-items:center;gap:6px;color:var(--adam-muted,#64748b);font-size:.83rem;font-weight:700;text-decoration:none}.site-health__back:hover{color:var(--adam-primary,#ef3f28)}.site-health__back svg{width:15px;height:15px}
.site-health__hero{position:relative;overflow:hidden;padding:24px;border:1px solid var(--adam-border,#e2e8f0);border-radius:20px;background:linear-gradient(135deg,var(--adam-card,#fff) 10%,var(--adam-surface-3,#f8fafc));box-shadow:0 18px 50px rgba(15,23,42,.07)}
.site-health__hero:after{content:"";position:absolute;right:-70px;top:-95px;width:240px;height:240px;border-radius:50%;background:color-mix(in srgb,var(--adam-primary,#ef3f28) 10%,transparent);pointer-events:none}
.site-health__head{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:20px}
.site-health__identity{display:flex;align-items:center;gap:15px}.site-health__icon{display:grid;place-items:center;width:52px;height:52px;border-radius:16px;background:var(--adam-primary-soft,#fff1ed);color:var(--adam-primary,#ef3f28)}
.site-health__icon svg{width:26px;height:26px}.site-health h1{margin:0;font-size:1.65rem}.site-health__lead{margin:.4rem 0 0;color:var(--adam-muted,#64748b);line-height:1.5}
.site-health__action{position:relative;z-index:1}.site-health__action .adam-button{min-height:42px;padding-inline:16px;border-radius:999px;white-space:nowrap}.site-health__action svg{width:17px;height:17px}
.site-health__alerts{display:grid;gap:10px;margin-top:15px}.site-health__alert{padding:12px 14px;border-radius:12px;border:1px solid var(--adam-danger-light,#fecaca);background:var(--adam-danger-soft,#fef2f2);color:var(--adam-danger,#b91c1c)}
.site-health__disclaimer{margin-top:16px;padding:15px 17px;border:1px solid color-mix(in srgb,#d97706 32%,var(--adam-border,#e2e8f0));border-radius:14px;background:color-mix(in srgb,#f59e0b 8%,var(--adam-card,#fff));color:var(--adam-text-3,#334155);font-size:.88rem;line-height:1.6}
.site-health__disclaimer b{display:block;margin-bottom:3px;color:#a16207}.site-health__layout{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(280px,.75fr);gap:16px;margin-top:16px;align-items:start}
.site-health__panel{padding:19px;border:1px solid var(--adam-border,#e2e8f0);border-radius:16px;background:var(--adam-card,#fff);box-shadow:0 8px 24px rgba(15,23,42,.045)}
.site-health__panel h2{margin:0;font-size:1.08rem}.site-health__panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:15px}.site-health__panel-result{display:flex;align-items:center;gap:12px}.site-health__clean-ring{--clean-percent:0;position:relative;display:grid;place-items:center;width:72px;height:72px;flex:0 0 72px;border-radius:50%;background:conic-gradient(var(--adam-success,#16a34a) calc(var(--clean-percent) * 1%),color-mix(in srgb,var(--adam-success,#16a34a) 13%,var(--adam-border,#e2e8f0)) 0);color:var(--adam-success,#15803d);font-size:1.18rem;font-weight:850;line-height:1}.site-health__clean-ring:before{content:"";position:absolute;inset:7px;border-radius:50%;background:var(--adam-card,#fff)}.site-health__clean-ring-value{position:relative;z-index:1}.site-health__clean-ring small{font-size:.68em}.site-health__clean-ring-label{position:absolute;z-index:1;top:47px;font-size:.54rem;font-style:normal;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.site-health__meta{margin-top:4px;color:var(--adam-muted,#64748b);font-size:.8rem}
.site-health__badge{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.035em}.site-health__badge--clean{background:#dcfce7;color:#166534}.site-health__badge--unverified{background:#e2e8f0;color:#475569}.site-health__badge--modified{background:#fef3c7;color:#92400e}.site-health__badge--contaminated,.site-health__badge--infected{background:#fee2e2;color:#991b1b}.site-health__badge--scanned{background:#dbeafe;color:#1d4ed8}
.site-health__counts{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}.site-health__count{padding:12px 8px;border:1px solid var(--adam-border,#e2e8f0);border-radius:12px;background:var(--adam-surface-3,#f8fafc);text-align:center}.site-health__count strong{display:block;font-size:1.35rem}.site-health__count span{color:var(--adam-muted,#64748b);font-size:.72rem}
.site-health__scope{display:grid;gap:9px}.site-health__scope-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 12px;border:1px solid var(--adam-border,#e2e8f0);border-radius:11px}.site-health__scope-row small{display:block;margin-top:2px;color:var(--adam-muted,#64748b)}
.site-health__scope-state{font-size:.73rem;font-weight:800;color:var(--adam-muted,#64748b);white-space:nowrap}.site-health__scope-state.active{color:var(--adam-success,#15803d)}
.site-health__findings{margin-top:16px}.site-health__table-wrap{overflow:auto;border:1px solid var(--adam-border,#e2e8f0);border-radius:12px}.site-health__table{width:100%;border-collapse:collapse;font-size:.84rem}.site-health__table th,.site-health__table td{padding:11px 12px;border-bottom:1px solid var(--adam-border,#e2e8f0);text-align:left;vertical-align:top}.site-health__table th{background:var(--adam-surface-3,#f8fafc);font-size:.73rem;text-transform:uppercase;letter-spacing:.04em}.site-health__table tr:last-child td{border-bottom:0}.site-health__path{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}.site-health__empty{padding:24px;text-align:center;color:var(--adam-muted,#64748b)}
.site-health__legend{display:grid;gap:9px}.site-health__legend-row{display:grid;grid-template-columns:120px 1fr;gap:10px;align-items:start;font-size:.82rem;color:var(--adam-text-3,#334155)}
.site-health__ext-name{font-weight:750}.site-health__ext-meta{margin-top:3px;color:var(--adam-muted,#64748b);font-size:.76rem}.site-health__section-gap{margin-top:16px}
@media(max-width:800px){.site-health__head{align-items:flex-start;flex-direction:column}.site-health__action,.site-health__action form{width:100%}.site-health__action .adam-button{width:100%;justify-content:center}.site-health__layout{grid-template-columns:1fr}.site-health__counts{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.site-health__panel-head{align-items:flex-start}.site-health__panel-result{flex-direction:column-reverse;align-items:flex-end}.site-health__clean-ring{width:64px;height:64px;flex-basis:64px}.site-health__clean-ring:before{inset:6px}.site-health__clean-ring-label{top:42px}}
</style>

<section class="site-health">
  <div class="site-health__topline"><a class="site-health__back" href="<?=h($base)?>/?page=admin/settings/index"><?=svg_ico('arrow-left')?> <?=_e('Settings')?></a><span class="site-health__badge site-health__badge--<?=h($status)?>"><?=h($statusLabel)?></span></div>
  <div class="site-health__hero">
    <div class="site-health__head">
      <div class="site-health__identity">
        <span class="site-health__icon" aria-hidden="true"><?=svg_ico('shield-check')?></span>
        <div><h1><?=_e('Site Health')?></h1><p class="site-health__lead"><?=_e('Verify Core release integrity and review security signals by ownership boundary.')?></p></div>
      </div>
      <div class="site-health__action">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
          <input type="hidden" name="site_health_action" value="run_site_health">
          <button class="adam-button" type="submit"><?=svg_ico('refresh-cw')?> <?=_e('Run full scan')?></button>
        </form>
      </div>
    </div>
    <?php if ($errors !== []): ?><div class="site-health__alerts" role="alert"><?php foreach ($errors as $error): ?><div class="site-health__alert"><?=h($error)?></div><?php endforeach;?></div><?php endif;?>
    <div class="site-health__disclaimer"><b><?=_e('Important limitation')?></b><?=h($disclaimer)?></div>
  </div>

  <div class="site-health__layout" aria-live="polite">
    <div>
      <article class="site-health__panel">
        <div class="site-health__panel-head">
          <div><h2><?=_e('Core Integrity')?></h2><div class="site-health__meta"><?=h(sprintf(__('Last scan: %s'), $scanTime))?></div></div>
          <div class="site-health__panel-result">
            <?php if ($cleanPercentage !== null): ?><div class="site-health__clean-ring" style="--clean-percent:<?=$cleanPercentage?>" role="img" aria-label="<?=h(sprintf('%s: %d%%', __('Clean'), $cleanPercentage))?>"><span class="site-health__clean-ring-value"><?=$cleanPercentage?><small>%</small></span><em class="site-health__clean-ring-label"><?=_e('Clean')?></em></div><?php endif;?>
            <span class="site-health__badge site-health__badge--<?=h($coreStatus)?>"><?=h($statusLabels[$coreStatus] ?? $statusLabels['unverified'])?></span>
          </div>
        </div>
        <?php if (is_array($coreReport)): ?>
          <div class="site-health__counts">
            <?php foreach (['clean','unverified','modified','contaminated','infected'] as $countStatus): ?>
              <div class="site-health__count"><strong><?=number_format((int)($summary[$countStatus] ?? 0))?></strong><span><?=h($statusLabels[$countStatus])?></span></div>
            <?php endforeach;?>
          </div>
          <div class="site-health__meta" style="margin-top:12px"><?=h(sprintf(__('Baseline version: %s'), (string)($baseline['version'] ?? '?')))?> · <?=h(($baseline['trusted'] ?? false) === true ? __('Canonical HTTPS manifest') : __('Local manifest, not independently verified'))?> · <?=h(sprintf(__('%d expected files'), (int)($summary['expected'] ?? 0)))?> · <?=h(sprintf(__('%d ms'), (int)($coreReport['duration_ms'] ?? 0)))?></div>
        <?php else: ?><div class="site-health__empty"><?=_e('Run the first Core scan to establish the observed integrity state.')?></div><?php endif;?>
      </article>

      <article class="site-health__panel site-health__findings">
        <div class="site-health__panel-head"><h2><?=_e('Core Findings')?></h2><?php if (($coreReport['findings_truncated'] ?? false) === true): ?><span class="site-health__badge site-health__badge--unverified"><?=_e('Results truncated')?></span><?php endif;?></div>
        <?php if ($findings !== []): ?>
          <div class="site-health__table-wrap"><table class="site-health__table"><thead><tr><th><?=_e('File')?></th><th><?=_e('Status')?></th><th><?=_e('Reason')?></th></tr></thead><tbody>
          <?php foreach ($findings as $finding): $findingStatus=(string)($finding['status']??'unverified'); $reason=(string)($finding['reason']??''); ?>
            <tr><td class="site-health__path"><?=h((string)($finding['path']??''))?></td><td><span class="site-health__badge site-health__badge--<?=h($findingStatus)?>"><?=h($statusLabels[$findingStatus]??$statusLabels['unverified'])?></span></td><td><?=h($reasonLabels[$reason]??__('The file requires manual review.'))?></td></tr>
          <?php endforeach;?></tbody></table></div>
        <?php elseif (is_array($coreReport)): ?><div class="site-health__empty"><?=_e('No Core file findings were detected in this scan.')?></div>
        <?php else: ?><div class="site-health__empty"><?=_e('No scan report is available yet.')?></div><?php endif;?>
      </article>

      <article class="site-health__panel site-health__section-gap">
        <div class="site-health__panel-head"><div><h2><?=_e('Plugin and Theme Inventory')?></h2><div class="site-health__meta"><?=_e('Hook usage does not modify Core; each extension keeps its own integrity state.')?></div></div><?php if (is_array($extensions)): $extensionStatus=(string)($extensions['status']??'unverified'); ?><span class="site-health__badge site-health__badge--<?=h($extensionStatus)?>"><?=h($statusLabels[$extensionStatus]??$statusLabels['unverified'])?></span><?php endif;?></div>
        <?php if ($extensionItems !== []): ?><div class="site-health__table-wrap"><table class="site-health__table"><thead><tr><th><?=_e('Extension')?></th><th><?=_e('Status')?></th><th><?=_e('Reason')?></th></tr></thead><tbody>
        <?php foreach ($extensionItems as $extension): $extensionStatus=(string)($extension['status']??'unverified'); $extensionReason=(string)($extension['reason']??''); $extensionVersion=(string)($extension['version']??''); if($extensionVersion==='')$extensionVersion=__('Unknown version'); ?>
          <tr><td><div class="site-health__ext-name"><?=h((string)($extension['name']??$extension['folder']??''))?></div><div class="site-health__ext-meta"><?=h(ucfirst((string)($extension['type']??'')))?> · <?=h($extensionVersion)?> · <?=h(sprintf(__('%d files inventoried'), (int)($extension['files_scanned']??0)))?></div></td><td><span class="site-health__badge site-health__badge--<?=h($extensionStatus)?>"><?=h($statusLabels[$extensionStatus]??$statusLabels['unverified'])?></span></td><td><?=h($extensionReasonLabels[$extensionReason]??__('The extension requires manual review.'))?></td></tr>
        <?php endforeach;?></tbody></table></div>
        <?php elseif (is_array($extensions) && ($extensions['complete']??false)===true): ?><div class="site-health__empty"><?=_e('No non-Core plugins or themes were found.')?></div>
        <?php elseif (is_array($extensions)): ?><div class="site-health__empty"><?=_e('The plugin and theme inventory could not be completed.')?></div>
        <?php else: ?><div class="site-health__empty"><?=_e('Run a full scan to inventory plugins and themes.')?></div><?php endif;?>
      </article>

      <article class="site-health__panel site-health__section-gap">
        <div class="site-health__panel-head"><div><h2><?=_e('Media and File Safety')?></h2><?php if (is_array($content)): ?><div class="site-health__meta"><?=h(sprintf(__('%d site-owned files inspected'), (int)($content['files_scanned']??0)))?></div><?php endif;?></div><?php if (is_array($content)): $contentStatus=(string)($content['status']??'unverified'); ?><span class="site-health__badge site-health__badge--<?=h($contentStatus)?>"><?=h($statusLabels[$contentStatus]??$statusLabels['unverified'])?></span><?php endif;?></div>
        <?php if ($contentFindings !== []): ?><div class="site-health__table-wrap"><table class="site-health__table"><thead><tr><th><?=_e('File')?></th><th><?=_e('Status')?></th><th><?=_e('Reason')?></th></tr></thead><tbody>
        <?php foreach ($contentFindings as $finding): $findingStatus=(string)($finding['status']??'unverified'); $reason=(string)($finding['reason']??''); ?>
          <tr><td class="site-health__path"><?=h((string)($finding['path']??''))?></td><td><span class="site-health__badge site-health__badge--<?=h($findingStatus)?>"><?=h($statusLabels[$findingStatus]??$statusLabels['unverified'])?></span></td><td><?=h($reasonLabels[$reason]??__('The file requires manual review.'))?></td></tr>
        <?php endforeach;?></tbody></table></div>
        <?php elseif (is_array($content) && ($content['complete']??false)===true): ?><div class="site-health__empty"><?=_e('No suspicious media or file artifacts were detected by the enabled safety rules.')?></div>
        <?php elseif (is_array($content)): ?><div class="site-health__empty"><?=_e('The media and file safety scan could not be completed.')?></div>
        <?php else: ?><div class="site-health__empty"><?=_e('Run a full scan to inspect site-owned media and files.')?></div><?php endif;?>
      </article>
    </div>

    <aside style="display:grid;gap:16px">
      <article class="site-health__panel"><div class="site-health__panel-head"><h2><?=_e('Scan Coverage')?></h2></div><div class="site-health__scope">
        <div class="site-health__scope-row"><div><b><?=_e('Core files')?></b><small><?=_e('Release hashes and unexpected files')?></small></div><span class="site-health__badge site-health__badge--<?=h($coreStatus)?>"><?=h($statusLabels[$coreStatus]??$statusLabels['unverified'])?></span></div>
        <?php $extensionStatus=is_array($extensions)?(string)($extensions['status']??'unverified'):'unverified'; ?><div class="site-health__scope-row"><div><b><?=_e('Plugins and themes')?></b><small><?=_e('Inventory and filesystem safety')?></small></div><span class="site-health__badge site-health__badge--<?=h($extensionStatus)?>"><?=h($statusLabels[$extensionStatus]??$statusLabels['unverified'])?></span></div>
        <?php $contentStatus=is_array($content)?(string)($content['status']??'unverified'):'unverified'; ?><div class="site-health__scope-row"><div><b><?=_e('Media and files')?></b><small><?=_e('Executable and MIME safety rules')?></small></div><span class="site-health__badge site-health__badge--<?=h($contentStatus)?>"><?=h($statusLabels[$contentStatus]??$statusLabels['unverified'])?></span></div>
      </div></article>
      <article class="site-health__panel"><div class="site-health__panel-head"><h2><?=_e('Status Guide')?></h2></div><div class="site-health__legend">
        <div class="site-health__legend-row"><span class="site-health__badge site-health__badge--clean"><?=_e('Clean')?></span><span><?=_e('Matches a trusted manifest.')?></span></div>
        <div class="site-health__legend-row"><span class="site-health__badge site-health__badge--unverified"><?=_e('Unverified')?></span><span><?=_e('The baseline or scan could not be verified.')?></span></div>
        <div class="site-health__legend-row"><span class="site-health__badge site-health__badge--modified"><?=_e('Modified')?></span><span><?=_e('A required file changed or is missing.')?></span></div>
        <div class="site-health__legend-row"><span class="site-health__badge site-health__badge--contaminated"><?=_e('Contaminated')?></span><span><?=_e('An unexpected or prohibited artifact was found.')?></span></div>
        <div class="site-health__legend-row"><span class="site-health__badge site-health__badge--infected"><?=_e('Infected')?></span><span><?=_e('Reserved for a configured high-confidence malware detector.')?></span></div>
      </div></article>
    </aside>
  </div>
</section>
<?=adiwira_bootstrap_toasts_script($toasts)?>
