<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once __DIR__ . '/../_guard.php';

[$uid] = adiwira_require_permission($pdo, 'core.settings.manage', false);
adiwira_require_site_owner($pdo, false);

$metadata = null;
$metadataError = false;
try {
    $metadata = jyavani_installed_product_metadata();
} catch (Throwable $error) {
    $metadataError = true;
    error_log('[system-information] Jyavani product metadata is invalid.');
}

$safeFact = static function (mixed $value, int $max = 200): string {
    if (!is_string($value)) return __('Unavailable');
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
    return $value !== '' ? substr($value, 0, $max) : __('Unavailable');
};
$databaseDriver = __('Unavailable');
$databaseVersion = __('Unavailable');
try {
    $databaseDriver = $safeFact($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 40);
    $databaseVersion = $safeFact($pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 120);
} catch (Throwable $error) {
}
$serverSoftware = $safeFact($_SERVER['SERVER_SOFTWARE'] ?? null);
$distribution = is_array($metadata['distribution'] ?? null) ? $metadata['distribution'] : [];
$runtime = is_array($distribution['runtime'] ?? null) ? $distribution['runtime'] : [];
$extensionSystem = is_array($distribution['extension_system'] ?? null) ? $distribution['extension_system'] : [];
$themeSystem = is_array($distribution['theme_system'] ?? null) ? $distribution['theme_system'] : [];
$base = ADMIN_BASE_PATH;
?>
<style>
.system-info{max-width:1080px;margin:18px auto;display:grid;gap:16px}.system-info__head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap}.system-info__head h1{margin:0}.system-info__head p{margin:.45rem 0 0;color:var(--adam-muted);max-width:72ch;line-height:1.55}.system-info__links{display:flex;gap:8px;flex-wrap:wrap}.system-info__links a{display:inline-flex;align-items:center;gap:7px;padding:.65rem .85rem;border:1px solid var(--adam-border);border-radius:10px;text-decoration:none;color:var(--adam-text);background:var(--adam-card)}.system-info__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.system-info__card{border:1px solid var(--adam-border);border-radius:14px;background:var(--adam-card);box-shadow:var(--adam-shadow);padding:17px}.system-info__card h2{font-size:1rem;margin:0 0 12px}.system-info__facts{margin:0;display:grid;grid-template-columns:minmax(120px,.8fr) minmax(0,1.2fr);gap:9px 14px}.system-info__facts dt{color:var(--adam-muted)}.system-info__facts dd{margin:0;overflow-wrap:anywhere}.system-info__facts code{font-size:.83em}.system-info__warning{padding:12px 14px;border:1px solid var(--adam-danger);border-radius:12px;color:var(--adam-danger);background:var(--adam-danger-soft)}.system-info__capabilities{display:grid;gap:8px}.system-info__capability{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--adam-border)}.system-info__capability:last-child{border-bottom:0}.system-info__status{font-weight:700;text-transform:capitalize}@media(max-width:540px){.system-info__facts{grid-template-columns:1fr}.system-info__facts dd{margin-bottom:6px}}
</style>
<section class="system-info">
  <header class="system-info__head">
    <div>
      <h1><?=h(__('System Information'))?></h1>
      <p><?=h(__('Review this Jyavani distribution, its implementation contracts, and the observed server runtime.'))?></p>
    </div>
    <div class="system-info__links">
      <a href="<?=h($base . '/?page=admin/settings/health')?>"><?=svg_ico('shield-check')?> <?=h(__('Site Health'))?></a>
      <?php if (current_user_can($pdo, 'core.updates.manage')): ?>
        <a href="<?=h($base . '/?page=admin/update/index')?>"><?=svg_ico('refresh-cw')?> <?=h(__('Update Manager'))?></a>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($metadataError): ?><div class="system-info__warning"><?=h(__('Jyavani release metadata is unavailable or invalid.'))?></div><?php endif; ?>

  <div class="system-info__grid">
    <article class="system-info__card">
      <h2><?=h(__('Product and distribution'))?></h2>
      <dl class="system-info__facts">
        <dt><?=h(__('Product'))?></dt><dd><?=h((string)($metadata['product']['name'] ?? 'Jyavani'))?></dd>
        <dt><?=h(__('Product ID'))?></dt><dd><code><?=h((string)($metadata['product']['id'] ?? __('Unavailable')))?></code></dd>
        <dt><?=h(__('Distribution'))?></dt><dd><?=h((string)($distribution['name'] ?? __('Unavailable')))?></dd>
        <dt><?=h(__('Distribution ID'))?></dt><dd><code><?=h((string)($distribution['distribution_id'] ?? __('Unavailable')))?></code></dd>
        <dt><?=h(__('Implementation ID'))?></dt><dd><code><?=h((string)($distribution['implementation_id'] ?? __('Unavailable')))?></code></dd>
        <dt><?=h(__('Version'))?></dt><dd><?=h((string)($metadata['version'] ?? __('Unavailable')))?></dd>
        <dt><?=h(__('Edition'))?></dt><dd><?=h((string)($metadata['edition'] ?? __('Unavailable')))?></dd>
        <dt><?=h(__('Build'))?></dt><dd><?=h((string)($metadata['build'] ?? __('Unavailable')))?></dd>
        <dt><?=h(__('Platform contract'))?></dt><dd><?=h((string)($distribution['platform_contract'] ?? __('Unavailable')))?></dd>
      </dl>
    </article>

    <article class="system-info__card">
      <h2><?=h(__('Implementation contracts'))?></h2>
      <dl class="system-info__facts">
        <dt><?=h(__('Runtime'))?></dt><dd><?=h((string)($runtime['runtime'] ?? __('Unavailable')))?></dd>
        <dt><?=h(__('Data stores'))?></dt><dd><?=h(implode(', ', is_array($runtime['data_stores'] ?? null) ? $runtime['data_stores'] : []))?></dd>
        <dt><?=h(__('Extension implementation'))?></dt><dd><code><?=h((string)($extensionSystem['implementation_id'] ?? __('Unavailable')))?></code></dd>
        <dt><?=h(__('Extension API'))?></dt><dd><?=h(implode(', ', is_array($extensionSystem['api_versions'] ?? null) ? $extensionSystem['api_versions'] : []))?></dd>
        <dt><?=h(__('Theme implementation'))?></dt><dd><code><?=h((string)($themeSystem['implementation_id'] ?? __('Unavailable')))?></code></dd>
        <dt><?=h(__('Theme API'))?></dt><dd><?=h(implode(', ', is_array($themeSystem['api_versions'] ?? null) ? $themeSystem['api_versions'] : []))?></dd>
        <dt><?=h(__('Portable Archive'))?></dt><dd><?=h((string)($metadata['contracts']['portable_archive'] ?? __('Unavailable')))?></dd>
      </dl>
    </article>

    <article class="system-info__card">
      <h2><?=h(__('Observed runtime'))?></h2>
      <dl class="system-info__facts">
        <dt><?=h(__('Server'))?></dt><dd><?=h($serverSoftware)?></dd>
        <dt><?=h(__('PHP Version'))?></dt><dd><?=h(PHP_VERSION)?></dd>
        <dt><?=h(__('Server API'))?></dt><dd><?=h(PHP_SAPI)?></dd>
        <dt><?=h(__('Operating system'))?></dt><dd><?=h(PHP_OS_FAMILY)?></dd>
        <dt><?=h(__('Database driver'))?></dt><dd><?=h($databaseDriver)?></dd>
        <dt><?=h(__('Database server'))?></dt><dd><?=h($databaseVersion)?></dd>
        <dt><?=h(__('Admin Path'))?></dt><dd><code><?=h(ADMIN_BASE_PATH)?></code></dd>
      </dl>
    </article>

    <article class="system-info__card">
      <h2><?=h(__('Distribution capabilities'))?></h2>
      <div class="system-info__capabilities">
        <?php foreach (is_array($distribution['capabilities'] ?? null) ? $distribution['capabilities'] : [] as $capability): ?>
          <div class="system-info__capability"><code><?=h((string)($capability['id'] ?? ''))?></code><span class="system-info__status"><?=h(__((string)($capability['status'] ?? 'unsupported')))?></span></div>
        <?php endforeach; ?>
      </div>
    </article>
  </div>
</section>
