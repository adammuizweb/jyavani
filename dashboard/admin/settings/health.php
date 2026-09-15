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
    'store_release_manifest_unavailable' => __('The canonical Store manifest for this exact version could not be fetched.'),
    'store_release_manifest_invalid' => __('The canonical Store returned an invalid or mismatched release manifest.'),
    'extension_source_unverified' => __('The extension has no independently trusted file manifest.'),
    'canonical_store_manifest_match' => __('Every extension file matches the canonical exact-version HTTPS Store baseline.'),
    'signed_deployment_manifest_match' => __('Every extension file matches the signed local deployment baseline.'),
    'signed_deployment_manifest_invalid' => __('The configured signed deployment manifest or public key is invalid or unavailable.'),
    'signed_deployment_manifest_signature_invalid' => __('The configured deployment manifest signature is invalid.'),
    'signed_deployment_manifest_entry_unavailable' => __('The signed deployment manifest has no matching entry for this extension version.'),
    'extension_files_modified' => __('A required extension file or copied static asset changed or is missing.'),
    'unexpected_or_unsafe_extension_file' => __('An unexpected or unsafe artifact exists in the extension tree or copied static assets.'),
    'extension_scan_incomplete' => __('The extension could not be fully hashed within the scan limits.'),
    'unsafe_extension_artifact' => __('The extension contains a symbolic link or unsupported file type.'),
    'extension_root_unreadable' => __('The extension root could not be read.'),
    'extension_inventory_incomplete' => __('The extension inventory did not complete.'),
];
$coreReport = is_array($report['components']['core'] ?? null) ? $report['components']['core'] : null;
$extensions = is_array($report['components']['extensions'] ?? null) ? $report['components']['extensions'] : null;
$content = is_array($report['components']['content'] ?? null) ? $report['components']['content'] : null;
$status = is_array($report) ? (string)($report['status'] ?? 'unverified') : 'unverified';
$coreStatus = is_array($coreReport) ? (string)($coreReport['status'] ?? 'unverified') : 'unverified';
$extensionStatus = is_array($extensions) ? (string)($extensions['status'] ?? 'unverified') : 'unverified';
$contentStatus = is_array($content) ? (string)($content['status'] ?? 'unverified') : 'unverified';
$summary = is_array($coreReport['summary'] ?? null) ? $coreReport['summary'] : [];
$baseline = is_array($coreReport['baseline'] ?? null) ? $coreReport['baseline'] : [];
$findings = is_array($coreReport['findings'] ?? null) ? $coreReport['findings'] : [];
$extensionItems = is_array($extensions['items'] ?? null) ? $extensions['items'] : [];
$extensionBaselineLabels = [
    'canonical_exact_https_store' => __('Canonical exact HTTPS Store baseline'),
    'signed_local_deployment' => __('Signed local deployment baseline'),
    'none' => __('No trusted release baseline'),
];
$contentFindings = is_array($content['findings'] ?? null) ? $content['findings'] : [];
$coreDistribution = [];
if (is_array($coreReport) && (int)($summary['expected'] ?? 0) > 0) {
    $distributionCounts = [];
    foreach (['clean', 'unverified', 'modified', 'contaminated', 'infected'] as $distributionStatus) {
        $distributionCounts[$distributionStatus] = max(0, (int)($summary[$distributionStatus] ?? 0));
    }
    $observedTotal = array_sum($distributionCounts);
    $distributionCounts['unverified'] += max(0, (int)$summary['expected'] - $observedTotal);
    $distributionTotal = array_sum($distributionCounts);
    $distributionOffset = 0.0;
    foreach ($distributionCounts as $distributionStatus => $distributionCount) {
        $percentage = $distributionTotal > 0 ? ($distributionCount / $distributionTotal) * 100 : 0.0;
        $coreDistribution[$distributionStatus] = [
            'percentage' => $percentage,
            'display' => number_format($percentage, $percentage === floor($percentage) ? 0 : 1),
            'offset' => $distributionOffset,
        ];
        $distributionOffset += $percentage;
    }
}
$statusLabel = $statusLabels[$status] ?? $statusLabels['unverified'];
$scanTime = is_array($report) && (int)($report['completed_at'] ?? 0) > 0
    ? format_date_ddmmyyyy_time_bracket(date('Y-m-d H:i:s', (int)$report['completed_at']))
    : __('Never scanned');
$disclaimer = [
    __('Site Health reports the state observed during the most recent scan. A clean result means verified files matched their trusted manifests and no known suspicious condition was detected within the scanned scope.'),
    __('It does not prove that the website, code, database, server, dependencies, or network is free from vulnerabilities or compromise.'),
    __('Files can change after a scan, scanners can miss threats, and some resources may be inaccessible to the CMS. Use server monitoring, backups, access control, timely updates, and independent security review as additional safeguards.'),
];
?>
<style>
.site-health{max-width:1100px;margin:18px auto;color:var(--adam-text,#0f172a)}
.site-health__topline{display:flex;align-items:center;margin-bottom:10px}.site-health__back{display:inline-flex;align-items:center;gap:6px;color:var(--adam-muted,#64748b);font-size:.83rem;font-weight:700;text-decoration:none}.site-health__back:hover{color:var(--adam-primary,#ef3f28)}.site-health__back svg{width:15px;height:15px}
.site-health__hero{position:relative;overflow:hidden;padding:28px;border:1px solid color-mix(in srgb,var(--adam-primary,#ef3f28) 18%,var(--adam-border,#e2e8f0));border-radius:22px;background:radial-gradient(circle at 85% 10%,color-mix(in srgb,var(--adam-primary,#ef3f28) 15%,transparent),transparent 32%),linear-gradient(135deg,var(--adam-card,#fff) 20%,var(--adam-surface-3,#f8fafc));box-shadow:0 22px 60px rgba(15,23,42,.09)}
.site-health__hero:before{content:"";position:absolute;inset:0;background-image:linear-gradient(color-mix(in srgb,var(--adam-border,#e2e8f0) 34%,transparent) 1px,transparent 1px),linear-gradient(90deg,color-mix(in srgb,var(--adam-border,#e2e8f0) 34%,transparent) 1px,transparent 1px);background-size:28px 28px;mask-image:linear-gradient(90deg,transparent 15%,#000);opacity:.45;pointer-events:none}.site-health__hero:after{content:"";position:absolute;right:-70px;top:-95px;width:240px;height:240px;border:1px solid color-mix(in srgb,var(--adam-primary,#ef3f28) 18%,transparent);border-radius:50%;pointer-events:none}
.site-health__head{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:20px}
.site-health__identity{display:flex;align-items:center;gap:15px}.site-health__icon{display:grid;place-items:center;width:52px;height:52px;border-radius:16px;background:var(--adam-primary-soft,#fff1ed);color:var(--adam-primary,#ef3f28)}
.site-health__icon svg{width:26px;height:26px}.site-health h1{margin:0;font-size:1.72rem;letter-spacing:-.025em}.site-health__eyebrow{display:block;margin-bottom:4px;color:var(--adam-primary,#ef3f28);font-size:.68rem;font-weight:850;letter-spacing:.11em;text-transform:uppercase}.site-health__lead{max-width:620px;margin:.4rem 0 0;color:var(--adam-muted,#64748b);line-height:1.55}.site-health__hero-controls{display:flex;align-items:center;gap:13px}.site-health__hero-meta{display:flex;align-items:center;gap:9px}.site-health__hero-time{display:inline-flex;align-items:center;gap:5px;color:var(--adam-muted,#64748b);font-size:.72rem;white-space:nowrap}.site-health__hero-time svg{width:14px;height:14px}.site-health__action{position:relative;z-index:1}.site-health__action .adam-button{min-height:44px;padding-inline:17px;border-radius:999px;white-space:nowrap;box-shadow:0 8px 18px color-mix(in srgb,var(--adam-primary,#ef3f28) 18%,transparent)}.site-health__action svg{width:17px;height:17px}
.site-health__status-color--clean{--health-color:#16a34a}.site-health__status-color--unverified{--health-color:#6366f1}.site-health__status-color--scanned{--health-color:#0284c7}.site-health__status-color--modified{--health-color:#d97706}.site-health__status-color--contaminated{--health-color:#ea580c}.site-health__status-color--infected{--health-color:#dc2626}
.site-health__alerts{display:grid;gap:10px;margin-top:15px}.site-health__alert{padding:12px 14px;border-radius:12px;border:1px solid var(--adam-danger-light,#fecaca);background:var(--adam-danger-soft,#fef2f2);color:var(--adam-danger,#b91c1c)}
.site-health__disclaimer{display:grid;grid-template-columns:minmax(160px,.3fr) minmax(0,1fr);gap:18px;margin-top:16px;padding:15px 17px;border:1px solid color-mix(in srgb,#d97706 32%,var(--adam-border,#e2e8f0));border-radius:14px;background:color-mix(in srgb,#f59e0b 8%,var(--adam-card,#fff));color:var(--adam-text-3,#334155);font-size:.84rem;line-height:1.55}.site-health__disclaimer b{display:flex;align-items:center;justify-content:center;width:9ch;min-height:100%;margin:auto;padding:8px;color:#a16207;font-size:1.28rem;line-height:1.15;text-align:center}.site-health__disclaimer-copy{display:grid;gap:6px;max-width:82ch}.site-health__disclaimer p{margin:0}
.site-health__signals{position:relative;z-index:2;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.site-health__signal{--health-color:#64748b;--signal-color:var(--health-color);display:flex;align-items:center;gap:11px;min-width:0;padding:13px 14px;border:1px solid color-mix(in srgb,var(--signal-color) 38%,var(--adam-border,#e2e8f0));border-inline-start:4px solid var(--signal-color);border-radius:14px;background:linear-gradient(135deg,color-mix(in srgb,var(--signal-color) 14%,var(--adam-card,#fff)),color-mix(in srgb,var(--signal-color) 4%,var(--adam-card,#fff)) 72%);color:inherit;text-decoration:none;box-shadow:inset 0 1px 0 color-mix(in srgb,var(--signal-color) 10%,transparent),0 5px 14px rgba(15,23,42,.05);transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}.site-health__signal--overall{--signal-color:#7c3aed}.site-health__signal--core{--signal-color:#2563eb}.site-health__signal--extensions{--signal-color:#ea580c}.site-health__signal--content{--signal-color:#0891b2}.site-health__signal[href]{cursor:pointer}.site-health__signal[href]:hover{transform:translateY(-1px);border-color:color-mix(in srgb,var(--signal-color) 58%,var(--adam-border,#e2e8f0));box-shadow:inset 0 1px 0 color-mix(in srgb,var(--signal-color) 13%,transparent),0 8px 18px rgba(15,23,42,.08)}.site-health__signal[href]:focus-visible{outline:3px solid color-mix(in srgb,var(--signal-color) 32%,transparent);outline-offset:3px}.site-health__signal-icon{display:grid;place-items:center;width:36px;height:36px;flex:0 0 36px;border:1px solid color-mix(in srgb,var(--signal-color) 24%,transparent);border-radius:11px;background:color-mix(in srgb,var(--signal-color) 14%,transparent);color:var(--signal-color)}.site-health__signal-icon svg{width:18px;height:18px}.site-health__signal-copy{min-width:0}.site-health__signal-copy small{display:block;overflow:hidden;color:var(--adam-muted,#64748b);font-size:.68rem;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.site-health__signal-copy strong{display:block;margin-top:2px;color:var(--health-color);font-size:.84rem}.site-health__signal-action{display:grid;place-items:center;width:26px;height:26px;margin-inline-start:auto;flex:0 0 26px;border:1px solid color-mix(in srgb,var(--signal-color) 26%,transparent);border-radius:999px;background:color-mix(in srgb,var(--signal-color) 11%,transparent);color:var(--signal-color);transition:transform .18s ease,background .18s ease}.site-health__signal-action svg{width:14px;height:14px}.site-health__signal[href]:hover .site-health__signal-action{transform:translateX(2px);background:color-mix(in srgb,var(--signal-color) 18%,transparent)}.site-health__layout{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(280px,.75fr);gap:16px;margin-top:16px;align-items:start}
.site-health__panel{position:relative;overflow:hidden;padding:19px;border:1px solid var(--adam-border,#e2e8f0);border-radius:17px;background:var(--adam-card,#fff);box-shadow:0 9px 28px rgba(15,23,42,.05)}.site-health__panel--accent:before{content:"";position:absolute;inset:0 auto 0 0;width:3px;background:var(--health-color,#64748b)}
.site-health__panel h2{margin:0;font-size:1.08rem}.site-health__panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:15px}.site-health__section-title{display:flex;align-items:center;gap:10px}.site-health__section-icon{display:grid;place-items:center;width:34px;height:34px;flex:0 0 34px;border-radius:10px;background:color-mix(in srgb,var(--health-color,#64748b) 11%,transparent);color:var(--health-color,#64748b)}.site-health__section-icon svg{width:17px;height:17px}.site-health__meta{margin-top:4px;color:var(--adam-muted,#64748b);font-size:.8rem}
.site-health__disclosure>summary{margin-bottom:0;list-style:none;cursor:pointer;user-select:none}.site-health__disclosure>summary::-webkit-details-marker{display:none}.site-health__disclosure[open]>summary{margin-bottom:15px}.site-health__disclosure-state{display:flex;align-items:center;gap:9px}.site-health__disclosure-chevron{display:grid;place-items:center;color:var(--adam-muted,#64748b);transition:transform .2s ease,color .2s ease}.site-health__disclosure-chevron svg{width:20px;height:20px;stroke-width:2.25}.site-health__disclosure[open] .site-health__disclosure-chevron{transform:rotate(180deg);color:var(--health-color,#64748b)}.site-health__disclosure-body{animation:site-health-reveal .22s ease both}@keyframes site-health-reveal{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:translateY(0)}}
.site-health__badge{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.035em}.site-health__badge--clean{background:#dcfce7;color:#166534}.site-health__badge--unverified{background:#e2e8f0;color:#475569}.site-health__badge--modified{background:#fef3c7;color:#92400e}.site-health__badge--contaminated,.site-health__badge--infected{background:#fee2e2;color:#991b1b}.site-health__badge--scanned{background:#dbeafe;color:#1d4ed8}
.site-health__status-chart{position:relative;width:190px;max-width:100%;margin:0 auto 16px}.site-health__status-chart svg{display:block;width:100%;height:auto;overflow:visible}.site-health__chart-track,.site-health__chart-segment{fill:none;stroke-width:12}.site-health__chart-track{stroke:var(--adam-surface-3,#f1f5f9)}.site-health__chart-segment{stroke-linecap:butt;stroke-dasharray:0 100;stroke-dashoffset:calc(var(--segment-offset) * -1);cursor:pointer;transition:stroke-dasharray .9s cubic-bezier(.22,1,.36,1),stroke-width .18s ease,filter .18s ease}.site-health__status-chart.is-ready .site-health__chart-segment{stroke-dasharray:var(--segment-size) calc(100 - var(--segment-size))}.site-health__chart-segment:hover,.site-health__chart-segment:focus{stroke-width:16;filter:drop-shadow(0 2px 3px rgba(15,23,42,.2));outline:none}.site-health__chart-segment--clean{stroke:#16a34a}.site-health__chart-segment--unverified{stroke:#94a3b8}.site-health__chart-segment--modified{stroke:#f59e0b}.site-health__chart-segment--contaminated{stroke:#f97316}.site-health__chart-segment--infected{stroke:#dc2626}.site-health__chart-center{position:absolute;inset:0;display:grid;place-content:center;text-align:center;pointer-events:none}.site-health__chart-center strong{font-size:1.55rem;line-height:1;color:var(--adam-text,#0f172a)}.site-health__chart-center span{margin-top:5px;color:var(--adam-muted,#64748b);font-size:.7rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.site-health__chart-legend{display:grid;gap:4px}.site-health__chart-key{display:grid;grid-template-columns:10px 1fr auto;align-items:center;gap:8px;width:100%;padding:7px 8px;border:0;border-radius:8px;background:transparent;color:var(--adam-text-3,#334155);font:inherit;font-size:.79rem;text-align:left;cursor:pointer}.site-health__chart-key:hover,.site-health__chart-key:focus-visible{background:var(--adam-surface-3,#f8fafc);outline:2px solid color-mix(in srgb,var(--adam-primary,#ef3f28) 35%,transparent);outline-offset:1px}.site-health__chart-dot{width:9px;height:9px;border-radius:50%}.site-health__chart-dot--clean{background:#16a34a}.site-health__chart-dot--unverified{background:#94a3b8}.site-health__chart-dot--modified{background:#f59e0b}.site-health__chart-dot--contaminated{background:#f97316}.site-health__chart-dot--infected{background:#dc2626}.site-health__chart-key b{font-size:.76rem}
.site-health__counts{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}.site-health__count{--health-color:#64748b;position:relative;overflow:hidden;padding:13px 8px 11px;border:1px solid color-mix(in srgb,var(--health-color) 18%,var(--adam-border,#e2e8f0));border-radius:12px;background:linear-gradient(180deg,color-mix(in srgb,var(--health-color) 7%,var(--adam-card,#fff)),var(--adam-card,#fff));text-align:center}.site-health__count:before{content:"";position:absolute;inset:0 0 auto;height:2px;background:var(--health-color)}.site-health__count strong{display:block;color:var(--health-color);font-size:1.38rem}.site-health__count span{color:var(--adam-muted,#64748b);font-size:.72rem}
.site-health__count--clean{--health-color:#15803d;border-color:#bbf7d0;background:#f0fdf4}.site-health__count--unverified{--health-color:#475569;border-color:#cbd5e1;background:#f8fafc}.site-health__count--modified{--health-color:#b45309;border-color:#fde68a;background:#fffbeb}.site-health__count--contaminated{--health-color:#c2410c;border-color:#fed7aa;background:#fff7ed}.site-health__count--infected{--health-color:#b91c1c;border-color:#fecaca;background:#fef2f2}
html.theme-dark .site-health__count--clean{--health-color:#4ade80;border-color:rgba(74,222,128,.34);background:rgba(22,163,74,.13)}html.theme-dark .site-health__count--unverified{--health-color:#cbd5e1;border-color:rgba(203,213,225,.25);background:rgba(148,163,184,.11)}html.theme-dark .site-health__count--modified{--health-color:#fbbf24;border-color:rgba(251,191,36,.32);background:rgba(217,119,6,.14)}html.theme-dark .site-health__count--contaminated{--health-color:#fb923c;border-color:rgba(251,146,60,.34);background:rgba(234,88,12,.15)}html.theme-dark .site-health__count--infected{--health-color:#f87171;border-color:rgba(248,113,113,.34);background:rgba(220,38,38,.15)}
.site-health__scope{display:grid;gap:9px}.site-health__scope-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 12px;border:1px solid var(--adam-border,#e2e8f0);border-radius:11px}.site-health__scope-row small{display:block;margin-top:2px;color:var(--adam-muted,#64748b)}
.site-health__scope-state{font-size:.73rem;font-weight:800;color:var(--adam-muted,#64748b);white-space:nowrap}.site-health__scope-state.active{color:var(--adam-success,#15803d)}
.site-health__findings{margin-top:16px}.site-health__list-tools{display:grid;grid-template-columns:minmax(0,1fr) minmax(150px,.35fr);gap:9px;margin-bottom:10px}.site-health__list-tools input,.site-health__list-tools select{width:100%;min-height:40px;padding:8px 11px;border:1px solid var(--adam-border,#cbd5e1);border-radius:10px;background:var(--adam-card,#fff);color:var(--adam-text,#0f172a);font:inherit}.site-health__list-tools input:focus,.site-health__list-tools select:focus{outline:2px solid color-mix(in srgb,var(--adam-primary,#ef3f28) 35%,transparent);border-color:var(--adam-primary,#ef3f28)}.site-health__table-wrap{overflow:auto;border:1px solid var(--adam-border,#e2e8f0);border-radius:12px}.site-health__table{width:100%;border-collapse:collapse;font-size:.84rem}.site-health__table th,.site-health__table td{padding:11px 12px;border-bottom:1px solid var(--adam-border,#e2e8f0);text-align:left;vertical-align:top}.site-health__table th{background:var(--adam-surface-3,#f8fafc);font-size:.73rem;text-transform:uppercase;letter-spacing:.04em}.site-health__table tr:last-child td{border-bottom:0}.site-health__path{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}.site-health__list-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:10px;color:var(--adam-muted,#64748b);font-size:.78rem}.site-health__pager{display:flex;gap:6px}.site-health__pager button{min-height:34px;padding:6px 10px;border:1px solid var(--adam-border,#cbd5e1);border-radius:8px;background:var(--adam-card,#fff);color:var(--adam-text,#0f172a);font:inherit;font-weight:700;cursor:pointer}.site-health__pager button:hover:not(:disabled){border-color:var(--adam-primary,#ef3f28);color:var(--adam-primary,#ef3f28)}.site-health__pager button:disabled{cursor:not-allowed;opacity:.45}.site-health__empty{padding:24px;text-align:center;color:var(--adam-muted,#64748b)}
.site-health__legend{display:grid;gap:9px}.site-health__legend-row{display:grid;grid-template-columns:32px 120px minmax(0,1fr);gap:10px;align-items:center;font-size:.82rem;color:var(--adam-text-3,#334155)}.site-health__legend-icon{display:grid;place-items:center;width:32px;height:32px;border:1px solid color-mix(in srgb,var(--health-color) 25%,var(--adam-border,#e2e8f0));border-radius:9px;background:color-mix(in srgb,var(--health-color) 10%,var(--adam-card,#fff));color:var(--health-color)}.site-health__legend-icon svg{width:16px;height:16px}
.site-health__ext-name{font-weight:750}.site-health__ext-meta{margin-top:3px;color:var(--adam-muted,#64748b);font-size:.76rem}.site-health__section-gap{margin-top:16px}
@media(max-width:900px){.site-health__signals{grid-template-columns:repeat(2,1fr)}}
@media(max-width:800px){.site-health__head{align-items:flex-start;flex-direction:column}.site-health__hero-controls{width:100%;justify-content:space-between}.site-health__action,.site-health__action form{height:100%}.site-health__action .adam-button{height:100%;justify-content:center}.site-health__layout{grid-template-columns:1fr}.site-health__counts{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.site-health__hero{padding:21px}.site-health__identity{align-items:flex-start}.site-health__hero-controls,.site-health__signals{grid-template-columns:1fr;display:grid}.site-health__disclaimer{grid-template-columns:1fr;gap:7px}.site-health__disclaimer b{min-height:auto;padding:3px}.site-health__signals{grid-template-columns:1fr 1fr}.site-health__signal{padding:11px}.site-health__signal-icon{display:none}.site-health__legend-row{grid-template-columns:32px minmax(100px,.5fr) minmax(0,1fr)}}
@media(max-width:520px){.site-health__list-tools{grid-template-columns:1fr}.site-health__list-foot{align-items:flex-start;flex-direction:column}.site-health__pager{width:100%}.site-health__pager button{flex:1}}
@media(prefers-color-scheme:dark){html:not(.theme-light):not(.theme-dark) .site-health__count--clean{--health-color:#4ade80;border-color:rgba(74,222,128,.34);background:rgba(22,163,74,.13)}html:not(.theme-light):not(.theme-dark) .site-health__count--unverified{--health-color:#cbd5e1;border-color:rgba(203,213,225,.25);background:rgba(148,163,184,.11)}html:not(.theme-light):not(.theme-dark) .site-health__count--modified{--health-color:#fbbf24;border-color:rgba(251,191,36,.32);background:rgba(217,119,6,.14)}html:not(.theme-light):not(.theme-dark) .site-health__count--contaminated{--health-color:#fb923c;border-color:rgba(251,146,60,.34);background:rgba(234,88,12,.15)}html:not(.theme-light):not(.theme-dark) .site-health__count--infected{--health-color:#f87171;border-color:rgba(248,113,113,.34);background:rgba(220,38,38,.15)}}
@media(prefers-reduced-motion:reduce){.site-health__signal,.site-health__signal-action{transition:none}.site-health__signal[href]:hover{transform:none}.site-health__chart-segment{transition:stroke-width .18s ease,filter .18s ease}.site-health__disclosure-chevron{transition:none}.site-health__disclosure-body{animation:none}}
</style>

<section class="site-health">
  <div class="site-health__topline"><a class="site-health__back" href="<?=h($base)?>/?page=admin/settings/index"><?=svg_ico('arrow-left')?> <?=_e('Settings')?></a></div>
  <div class="site-health__hero">
    <div class="site-health__head">
      <div class="site-health__identity">
        <span class="site-health__icon" aria-hidden="true"><?=svg_ico('shield-check')?></span>
        <div><span class="site-health__eyebrow"><?=_e('Integrity center')?></span><h1><?=_e('Site Health')?></h1><p class="site-health__lead"><?=_e('Verify Core release integrity and review security signals by ownership boundary.')?></p></div>
      </div>
      <div class="site-health__hero-controls">
        <div class="site-health__hero-meta"><span class="site-health__badge site-health__badge--<?=h($status)?>"><?=h($statusLabel)?></span><span class="site-health__hero-time"><?=svg_ico('timer')?> <?=h($scanTime)?></span></div>
        <div class="site-health__action"><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="site_health_action" value="run_site_health"><button class="adam-button" type="submit"><?=svg_ico('refresh-cw')?> <?=_e('Run full scan')?></button></form></div>
      </div>
    </div>
    <?php if ($errors !== []): ?><div class="site-health__alerts" role="alert"><?php foreach ($errors as $error): ?><div class="site-health__alert"><?=h($error)?></div><?php endforeach;?></div><?php endif;?>
    <div class="site-health__disclaimer"><b><?=_e('Important limitation')?></b><div class="site-health__disclaimer-copy"><?php foreach ($disclaimer as $paragraph): ?><p><?=h($paragraph)?></p><?php endforeach;?></div></div>
  </div>

  <div class="site-health__signals" aria-label="<?=h(__('Scan Coverage'))?>">
    <div class="site-health__signal site-health__signal--overall site-health__status-color--<?=h($status)?>"><span class="site-health__signal-icon" aria-hidden="true"><?=svg_ico('shield-check')?></span><span class="site-health__signal-copy"><small><?=_e('Overall health')?></small><strong><?=h($statusLabel)?></strong></span></div>
    <a class="site-health__signal site-health__signal--core site-health__status-color--<?=h($coreStatus)?>" href="#core-integrity"><span class="site-health__signal-icon" aria-hidden="true"><?=svg_ico('monitor')?></span><span class="site-health__signal-copy"><small><?=_e('Core files')?></small><strong><?=h($statusLabels[$coreStatus]??$statusLabels['unverified'])?></strong></span><span class="site-health__signal-action" aria-hidden="true"><?=svg_ico('chevron-right')?></span></a>
    <a class="site-health__signal site-health__signal--extensions site-health__status-color--<?=h($extensionStatus)?>" href="#extension-inventory"><span class="site-health__signal-icon" aria-hidden="true"><?=svg_ico('puzzle')?></span><span class="site-health__signal-copy"><small><?=_e('Plugins and themes')?></small><strong><?=h($statusLabels[$extensionStatus]??$statusLabels['unverified'])?></strong></span><span class="site-health__signal-action" aria-hidden="true"><?=svg_ico('chevron-right')?></span></a>
    <a class="site-health__signal site-health__signal--content site-health__status-color--<?=h($contentStatus)?>" href="#content-safety"><span class="site-health__signal-icon" aria-hidden="true"><?=svg_ico('image')?></span><span class="site-health__signal-copy"><small><?=_e('Media and files')?></small><strong><?=h($statusLabels[$contentStatus]??$statusLabels['unverified'])?></strong></span><span class="site-health__signal-action" aria-hidden="true"><?=svg_ico('chevron-right')?></span></a>
  </div>

  <div class="site-health__layout" aria-live="polite">
    <div>
      <article class="site-health__panel site-health__panel--accent site-health__status-color--<?=h($coreStatus)?>" id="core-integrity">
        <div class="site-health__panel-head">
          <div class="site-health__section-title"><span class="site-health__section-icon" aria-hidden="true"><?=svg_ico('monitor')?></span><div><h2><?=_e('Core Integrity')?></h2><div class="site-health__meta"><?=h(sprintf(__('Last scan: %s'), $scanTime))?></div></div></div>
          <span class="site-health__badge site-health__badge--<?=h($coreStatus)?>"><?=h($statusLabels[$coreStatus] ?? $statusLabels['unverified'])?></span>
        </div>
        <?php if (is_array($coreReport)): ?>
          <div class="site-health__counts">
            <?php foreach (['clean','unverified','modified','contaminated','infected'] as $countStatus): ?>
              <div class="site-health__count site-health__count--<?=h($countStatus)?>"><strong><?=number_format((int)($summary[$countStatus] ?? 0))?></strong><span><?=h($statusLabels[$countStatus])?></span></div>
            <?php endforeach;?>
          </div>
          <div class="site-health__meta" style="margin-top:12px"><?=h(sprintf(__('Baseline version: %s'), (string)($baseline['version'] ?? '?')))?> · <?=h(($baseline['trusted'] ?? false) === true ? __('Canonical HTTPS manifest') : __('Local manifest, not independently verified'))?> · <?=h(sprintf(__('%d expected files'), (int)($summary['expected'] ?? 0)))?> · <?=h(sprintf(__('%d ms'), (int)($coreReport['duration_ms'] ?? 0)))?></div>
        <?php else: ?><div class="site-health__empty"><?=_e('Run the first Core scan to establish the observed integrity state.')?></div><?php endif;?>
      </article>

      <details class="site-health__panel site-health__panel--accent site-health__status-color--<?=h($coreStatus)?> site-health__findings site-health__disclosure" id="core-findings" open>
        <summary class="site-health__panel-head"><div class="site-health__section-title"><span class="site-health__section-icon" aria-hidden="true"><?=svg_ico('file-text')?></span><h2><?=_e('Core Findings')?></h2></div><span class="site-health__disclosure-state"><?php if (($coreReport['findings_truncated'] ?? false) === true): ?><span class="site-health__badge site-health__badge--unverified"><?=_e('Results truncated')?></span><?php endif;?><span class="site-health__disclosure-chevron" aria-hidden="true"><?=svg_ico('chevron-down')?></span></span></summary>
        <div class="site-health__disclosure-body">
        <?php if ($findings !== []): ?>
          <div data-health-list data-page-size="15" data-summary="<?=h(__('Showing %d-%d of %d results'))?>">
          <div class="site-health__list-tools"><input type="search" data-health-search placeholder="<?=h(__('Search results…'))?>" aria-label="<?=h(__('Search results…'))?>"><select data-health-status aria-label="<?=h(__('Filter by status'))?>"><option value=""><?=_e('All statuses')?></option><?php foreach (['unverified','modified','contaminated','infected'] as $filterStatus): ?><option value="<?=h($filterStatus)?>"><?=h($statusLabels[$filterStatus])?></option><?php endforeach;?></select></div>
          <div class="site-health__table-wrap"><table class="site-health__table"><thead><tr><th><?=_e('File')?></th><th><?=_e('Status')?></th><th><?=_e('Reason')?></th></tr></thead><tbody>
          <?php foreach ($findings as $finding): $findingStatus=(string)($finding['status']??'unverified'); $reason=(string)($finding['reason']??''); ?>
            <tr data-health-row data-status="<?=h($findingStatus)?>"><td class="site-health__path"><?=h((string)($finding['path']??''))?></td><td><span class="site-health__badge site-health__badge--<?=h($findingStatus)?>"><?=h($statusLabels[$findingStatus]??$statusLabels['unverified'])?></span></td><td><?=h($reasonLabels[$reason]??__('The file requires manual review.'))?></td></tr>
          <?php endforeach;?></tbody></table></div>
          <div class="site-health__empty" data-health-empty hidden><?=_e('No results match these filters.')?></div><div class="site-health__list-foot"><span data-health-summary></span><div class="site-health__pager"><button type="button" data-health-prev><?=_e('Previous')?></button><button type="button" data-health-next><?=_e('Next')?></button></div></div></div>
        <?php elseif (is_array($coreReport)): ?><div class="site-health__empty"><?=_e('No Core file findings were detected in this scan.')?></div>
        <?php else: ?><div class="site-health__empty"><?=_e('No scan report is available yet.')?></div><?php endif;?>
        </div>
      </details>

      <details class="site-health__panel site-health__panel--accent site-health__status-color--<?=h($extensionStatus)?> site-health__section-gap site-health__disclosure" id="extension-inventory" open>
        <summary class="site-health__panel-head"><div class="site-health__section-title"><span class="site-health__section-icon" aria-hidden="true"><?=svg_ico('puzzle')?></span><div><h2><?=_e('Plugin and Theme Inventory')?></h2><div class="site-health__meta"><?=_e('Hook usage does not modify Core; each extension keeps its own integrity state.')?></div></div></div><span class="site-health__disclosure-state"><?php if (is_array($extensions)): ?><span class="site-health__badge site-health__badge--<?=h($extensionStatus)?>"><?=h($statusLabels[$extensionStatus]??$statusLabels['unverified'])?></span><?php endif;?><span class="site-health__disclosure-chevron" aria-hidden="true"><?=svg_ico('chevron-down')?></span></span></summary>
        <div class="site-health__disclosure-body">
        <?php if ($extensionItems !== []): ?><div data-health-list data-page-size="15" data-summary="<?=h(__('Showing %d-%d of %d results'))?>"><div class="site-health__list-tools"><input type="search" data-health-search placeholder="<?=h(__('Search results…'))?>" aria-label="<?=h(__('Search results…'))?>"><select data-health-status aria-label="<?=h(__('Filter by status'))?>"><option value=""><?=_e('All statuses')?></option><?php foreach (['clean','unverified','modified','contaminated','infected'] as $filterStatus): ?><option value="<?=h($filterStatus)?>"><?=h($statusLabels[$filterStatus])?></option><?php endforeach;?></select></div><div class="site-health__table-wrap"><table class="site-health__table"><thead><tr><th><?=_e('Extension')?></th><th><?=_e('Status')?></th><th><?=_e('Reason')?></th></tr></thead><tbody>
        <?php foreach ($extensionItems as $extension): $extensionStatus=(string)($extension['status']??'unverified'); $extensionReason=(string)($extension['reason']??''); $extensionVersion=(string)($extension['version']??''); if($extensionVersion==='')$extensionVersion=__('Unknown version'); ?>
          <tr data-health-row data-status="<?=h($extensionStatus)?>"><td><div class="site-health__ext-name"><?=h((string)($extension['name']??$extension['folder']??''))?></div><div class="site-health__ext-meta"><?=h(ucfirst((string)($extension['type']??'')))?> · <?=h($extensionVersion)?> · <?=h(sprintf(__('%d files inventoried'), (int)($extension['files_scanned']??0)))?> · <?=h($extensionBaselineLabels[$extension['baseline']??'none']??$extensionBaselineLabels['none'])?></div></td><td><span class="site-health__badge site-health__badge--<?=h($extensionStatus)?>"><?=h($statusLabels[$extensionStatus]??$statusLabels['unverified'])?></span></td><td><?=h($extensionReasonLabels[$extensionReason]??__('The extension requires manual review.'))?></td></tr>
        <?php endforeach;?></tbody></table></div><div class="site-health__empty" data-health-empty hidden><?=_e('No results match these filters.')?></div><div class="site-health__list-foot"><span data-health-summary></span><div class="site-health__pager"><button type="button" data-health-prev><?=_e('Previous')?></button><button type="button" data-health-next><?=_e('Next')?></button></div></div></div>
        <?php elseif (is_array($extensions) && ($extensions['complete']??false)===true): ?><div class="site-health__empty"><?=_e('No non-Core plugins or themes were found.')?></div>
        <?php elseif (is_array($extensions)): ?><div class="site-health__empty"><?=_e('The plugin and theme inventory could not be completed.')?></div>
        <?php else: ?><div class="site-health__empty"><?=_e('Run a full scan to inventory plugins and themes.')?></div><?php endif;?>
        </div>
      </details>

      <details class="site-health__panel site-health__panel--accent site-health__status-color--<?=h($contentStatus)?> site-health__section-gap site-health__disclosure" id="content-safety" open>
        <summary class="site-health__panel-head"><div class="site-health__section-title"><span class="site-health__section-icon" aria-hidden="true"><?=svg_ico('image')?></span><div><h2><?=_e('Media and File Safety')?></h2><?php if (is_array($content)): ?><div class="site-health__meta"><?=h(sprintf(__('%d site-owned files inspected'), (int)($content['files_scanned']??0)))?></div><?php endif;?></div></div><span class="site-health__disclosure-state"><?php if (is_array($content)): ?><span class="site-health__badge site-health__badge--<?=h($contentStatus)?>"><?=h($statusLabels[$contentStatus]??$statusLabels['unverified'])?></span><?php endif;?><span class="site-health__disclosure-chevron" aria-hidden="true"><?=svg_ico('chevron-down')?></span></span></summary>
        <div class="site-health__disclosure-body">
        <?php if ($contentFindings !== []): ?><div data-health-list data-page-size="15" data-summary="<?=h(__('Showing %d-%d of %d results'))?>"><div class="site-health__list-tools"><input type="search" data-health-search placeholder="<?=h(__('Search results…'))?>" aria-label="<?=h(__('Search results…'))?>"><select data-health-status aria-label="<?=h(__('Filter by status'))?>"><option value=""><?=_e('All statuses')?></option><?php foreach (['unverified','modified','contaminated','infected'] as $filterStatus): ?><option value="<?=h($filterStatus)?>"><?=h($statusLabels[$filterStatus])?></option><?php endforeach;?></select></div><div class="site-health__table-wrap"><table class="site-health__table"><thead><tr><th><?=_e('File')?></th><th><?=_e('Status')?></th><th><?=_e('Reason')?></th></tr></thead><tbody>
        <?php foreach ($contentFindings as $finding): $findingStatus=(string)($finding['status']??'unverified'); $reason=(string)($finding['reason']??''); ?>
          <tr data-health-row data-status="<?=h($findingStatus)?>"><td class="site-health__path"><?=h((string)($finding['path']??''))?></td><td><span class="site-health__badge site-health__badge--<?=h($findingStatus)?>"><?=h($statusLabels[$findingStatus]??$statusLabels['unverified'])?></span></td><td><?=h($reasonLabels[$reason]??__('The file requires manual review.'))?></td></tr>
        <?php endforeach;?></tbody></table></div><div class="site-health__empty" data-health-empty hidden><?=_e('No results match these filters.')?></div><div class="site-health__list-foot"><span data-health-summary></span><div class="site-health__pager"><button type="button" data-health-prev><?=_e('Previous')?></button><button type="button" data-health-next><?=_e('Next')?></button></div></div></div>
        <?php elseif (is_array($content) && ($content['complete']??false)===true): ?><div class="site-health__empty"><?=_e('No suspicious media or file artifacts were detected by the enabled safety rules.')?></div>
        <?php elseif (is_array($content)): ?><div class="site-health__empty"><?=_e('The media and file safety scan could not be completed.')?></div>
        <?php else: ?><div class="site-health__empty"><?=_e('Run a full scan to inspect site-owned media and files.')?></div><?php endif;?>
        </div>
      </details>
    </div>

    <aside style="display:grid;gap:16px">
      <?php if ($coreDistribution !== []): $defaultDistribution = $coreDistribution['clean']; ?>
      <article class="site-health__panel">
        <div class="site-health__panel-head"><h2><?=_e('Core file status')?></h2></div>
        <div class="site-health__status-chart" data-health-chart data-default-label="<?=h($statusLabels['clean'])?>" data-default-percent="<?=h($defaultDistribution['display'])?>">
          <svg viewBox="0 0 120 120" role="img" aria-label="<?=h(__('Core file status'))?>">
            <circle class="site-health__chart-track" cx="60" cy="60" r="47"></circle>
            <?php foreach ($coreDistribution as $distributionStatus => $distribution): if ($distribution['percentage'] <= 0) continue; $distributionLabel = $statusLabels[$distributionStatus]; ?>
              <circle class="site-health__chart-segment site-health__chart-segment--<?=h($distributionStatus)?>" cx="60" cy="60" r="47" pathLength="100" transform="rotate(-90 60 60)" tabindex="0" style="--segment-size:<?=h(number_format($distribution['percentage'], 4, '.', ''))?>;--segment-offset:<?=h(number_format($distribution['offset'], 4, '.', ''))?>" data-chart-status="<?=h($distributionStatus)?>" data-chart-label="<?=h($distributionLabel)?>" data-chart-percent="<?=h($distribution['display'])?>"><title><?=h(sprintf('%s: %s%%', $distributionLabel, $distribution['display']))?></title></circle>
            <?php endforeach;?>
          </svg>
          <div class="site-health__chart-center" aria-live="polite"><strong data-chart-percent><?=$defaultDistribution['display']?>%</strong><span data-chart-label><?=h($statusLabels['clean'])?></span></div>
        </div>
        <div class="site-health__chart-legend">
          <?php foreach ($coreDistribution as $distributionStatus => $distribution): $distributionLabel = $statusLabels[$distributionStatus]; ?>
            <button class="site-health__chart-key" type="button" data-chart-status="<?=h($distributionStatus)?>" data-chart-label="<?=h($distributionLabel)?>" data-chart-percent="<?=h($distribution['display'])?>"><span class="site-health__chart-dot site-health__chart-dot--<?=h($distributionStatus)?>" aria-hidden="true"></span><span><?=h($distributionLabel)?></span><b><?=$distribution['display']?>%</b></button>
          <?php endforeach;?>
        </div>
      </article>
      <?php endif;?>
      <article class="site-health__panel"><div class="site-health__panel-head"><h2><?=_e('Scan Coverage')?></h2></div><div class="site-health__scope">
        <div class="site-health__scope-row"><div><b><?=_e('Core files')?></b><small><?=_e('Release hashes and unexpected files')?></small></div><span class="site-health__badge site-health__badge--<?=h($coreStatus)?>"><?=h($statusLabels[$coreStatus]??$statusLabels['unverified'])?></span></div>
        <div class="site-health__scope-row"><div><b><?=_e('Plugins and themes')?></b><small><?=_e('Canonical Store or signed deployment hashes and filesystem safety')?></small></div><span class="site-health__badge site-health__badge--<?=h($extensionStatus)?>"><?=h($statusLabels[$extensionStatus]??$statusLabels['unverified'])?></span></div>
        <div class="site-health__scope-row"><div><b><?=_e('Media and files')?></b><small><?=_e('Executable and MIME safety rules')?></small></div><span class="site-health__badge site-health__badge--<?=h($contentStatus)?>"><?=h($statusLabels[$contentStatus]??$statusLabels['unverified'])?></span></div>
      </div></article>
      <article class="site-health__panel"><div class="site-health__panel-head"><div class="site-health__section-title"><span class="site-health__section-icon" aria-hidden="true"><?=svg_ico('clipboard-list')?></span><h2><?=_e('Status Guide')?></h2></div></div><div class="site-health__legend">
        <div class="site-health__legend-row"><span class="site-health__legend-icon site-health__status-color--clean" aria-hidden="true"><?=svg_ico('circle-check')?></span><span class="site-health__badge site-health__badge--clean"><?=_e('Clean')?></span><span><?=_e('Matches a trusted manifest.')?></span></div>
        <div class="site-health__legend-row"><span class="site-health__legend-icon site-health__status-color--unverified" aria-hidden="true"><?=svg_ico('search')?></span><span class="site-health__badge site-health__badge--unverified"><?=_e('Unverified')?></span><span><?=_e('The baseline or scan could not be verified.')?></span></div>
        <div class="site-health__legend-row"><span class="site-health__legend-icon site-health__status-color--modified" aria-hidden="true"><?=svg_ico('file-text')?></span><span class="site-health__badge site-health__badge--modified"><?=_e('Modified')?></span><span><?=_e('A required file changed or is missing.')?></span></div>
        <div class="site-health__legend-row"><span class="site-health__legend-icon site-health__status-color--contaminated" aria-hidden="true"><?=svg_ico('alert-triangle')?></span><span class="site-health__badge site-health__badge--contaminated"><?=_e('Contaminated')?></span><span><?=_e('An unexpected or prohibited artifact was found.')?></span></div>
        <div class="site-health__legend-row"><span class="site-health__legend-icon site-health__status-color--infected" aria-hidden="true"><?=svg_ico('circle-x')?></span><span class="site-health__badge site-health__badge--infected"><?=_e('Infected')?></span><span><?=_e('Reserved for a configured high-confidence malware detector.')?></span></div>
      </div></article>
    </aside>
  </div>
</section>
<?=adiwira_bootstrap_toasts_script($toasts)?>
<script>
document.querySelectorAll('[data-health-list]').forEach(function(list){
  var rows=Array.prototype.slice.call(list.querySelectorAll('[data-health-row]'));
  var search=list.querySelector('[data-health-search]');
  var status=list.querySelector('[data-health-status]');
  var summary=list.querySelector('[data-health-summary]');
  var empty=list.querySelector('[data-health-empty]');
  var previous=list.querySelector('[data-health-prev]');
  var next=list.querySelector('[data-health-next]');
  var page=1;
  var size=parseInt(list.dataset.pageSize,10)||15;
  var render=function(){
    var query=search.value.trim().toLocaleLowerCase();
    var filtered=rows.filter(function(row){return(!status.value||row.dataset.status===status.value)&&(!query||row.textContent.toLocaleLowerCase().indexOf(query)!==-1);});
    var pages=Math.max(1,Math.ceil(filtered.length/size));
    page=Math.min(page,pages);
    var start=(page-1)*size;
    var end=Math.min(start+size,filtered.length);
    rows.forEach(function(row){row.hidden=true;});
    filtered.slice(start,end).forEach(function(row){row.hidden=false;});
    empty.hidden=filtered.length!==0;
    summary.textContent=list.dataset.summary.replace('%d',filtered.length?start+1:0).replace('%d',end).replace('%d',filtered.length);
    previous.disabled=page<=1;
    next.disabled=page>=pages;
  };
  search.addEventListener('input',function(){page=1;render();});
  status.addEventListener('change',function(){page=1;render();});
  previous.addEventListener('click',function(){if(page>1){page--;render();}});
  next.addEventListener('click',function(){page++;render();});
  list.healthFilterStatus=function(value){status.value=value;page=1;render();};
  render();
});
document.querySelectorAll('[data-health-chart]').forEach(function(chart){
  var percent=chart.querySelector('[data-chart-percent]:not([data-chart-label])');
  var label=chart.querySelector('.site-health__chart-center [data-chart-label]');
  var show=function(item){percent.textContent=item.dataset.chartPercent+'%';label.textContent=item.dataset.chartLabel;};
  var reset=function(){percent.textContent=chart.dataset.defaultPercent+'%';label.textContent=chart.dataset.defaultLabel;};
  chart.querySelectorAll('[data-chart-label][data-chart-percent]').forEach(function(item){
    item.addEventListener('mouseenter',function(){show(item);});
    item.addEventListener('mouseleave',reset);
    item.addEventListener('focus',function(){show(item);});
    item.addEventListener('blur',reset);
    item.addEventListener('click',function(){
      show(item);
      if(item.dataset.chartStatus==='clean')return;
      var findings=document.getElementById('core-findings');
      var list=findings?findings.querySelector('[data-health-list]'):null;
      if(findings&&findings.tagName==='DETAILS')findings.open=true;
      if(list&&typeof list.healthFilterStatus==='function')list.healthFilterStatus(item.dataset.chartStatus);
      if(findings)findings.scrollIntoView({behavior:'smooth',block:'start'});
    });
  });
  requestAnimationFrame(function(){requestAnimationFrame(function(){chart.classList.add('is-ready');});});
});
document.querySelectorAll('.site-health__signal[href^="#"]').forEach(function(link){
  link.addEventListener('click',function(){var target=document.querySelector(link.getAttribute('href'));if(target&&target.tagName==='DETAILS')target.open=true;});
});
</script>
