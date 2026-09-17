<?php
declare(strict_types=1);

if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

$detailsContext = is_array($adminDetailsContext ?? null)
    ? $adminDetailsContext
    : admin_details_context($pdo, (string)($_GET['page'] ?? 'home'), is_array($user ?? null) ? (int)($user['id'] ?? 0) : 0);
$detailsVisible = isset($adminDetailsVisible)
    ? $adminDetailsVisible === true
    : admin_details_visible($pdo, $detailsContext);
if (!$detailsVisible) return;

$requested = (string)$detailsContext['page'];
$themeId = (int)$detailsContext['entity_id'];
$isThemeEditor = $detailsContext['mode'] === 'theme_preview';

ob_start();
if ($isThemeEditor):
?>
  <div class="admin-details-preview">
    <h3><?= h(sprintf(__('Live Theme Preview (ID: %d)'), $themeId)) ?></h3>
    <iframe
      id="theme-live-preview"
      src="<?= h((string)$detailsContext['admin_base_path'] . '/live.php?id=' . $themeId) ?>"
      title="<?= h(__('Live Theme Preview')) ?>"
      sandbox="allow-same-origin allow-scripts allow-forms"
    ></iframe>
  </div>
<?php else: ?>
  <?php if (str_starts_with($requested, 'admin/posts')): ?>
    <h3><?=_e('Posts')?></h3>
    <p>
      <?=_e('Posts are used to publish dynamic articles such as news, activities, agendas, announcements, and other informative content.')?>
      <?=_e('All posts are sorted by date, can be categorized, and can be published or saved as drafts.')?>
    </p>
  <?php elseif (str_starts_with($requested, 'admin/pages')): ?>
    <h3><?=_e('Pages')?></h3>
    <p>
      <?=_e('Pages are used to create static pages such as Profile, Vision & Mission, About Us, and Contact Page.')?>
      <?=_e('Unlike posts, pages are not date-based and are typically used for permanent or rarely changed content.')?>
    </p>
  <?php elseif (str_starts_with($requested, 'admin/categories')): ?>
    <h3><?=_e('Categories')?></h3>
    <p>
      <?=_e('Categories are used to create labels or topic groups that can be used to organize and filter articles or programs.')?>
      <?=_e('Categories help visitors find relevant content according to their interests.')?>
    </p>
  <?php elseif (str_starts_with($requested, 'admin/themes')): ?>
    <h3><?=_e('Themes')?></h3>
    <p>
      <?=_e('Themes are used to create or edit theme partials using HTML, CSS, and JavaScript.')?>
      <?=_e('This menu is intended for users who understand frontend basics to design the website appearance as needed.')?>
    </p>
  <?php elseif ($requested === 'home'): ?>
    <h3><?=_e('Information')?></h3>
    <p><?=_e('Welcome to the control panel. Select a menu on the side to start managing content.')?></p>
  <?php endif; ?>

  <section class="panel-info">
    <p><?=_e('This panel displays contextual information according to the menu currently being opened.')?></p>
  </section>
<?php
endif;
$coreDetailsContent = (string)ob_get_clean();
$coreDetailsContent = admin_details_filter_core_content($pdo, $coreDetailsContent, $detailsContext);
?>
<aside id="adam-panel" class="adam-panel" role="complementary" aria-label="<?= h(__('Details panel')) ?>" aria-hidden="true" data-details-schema="1" data-details-page="<?= h($requested) ?>">
  <div id="adam-panel-resizer" class="adam-panel-resizer" role="separator" aria-label="<?= h(__('Resize details panel')) ?>" aria-controls="adam-panel-body" aria-orientation="vertical" aria-valuemin="250" aria-valuemax="1500" aria-valuenow="360" tabindex="0"></div>
  <span class="admin-details-focus-sentinel" data-panel-focus-start tabindex="0"></span>
  <button id="adam-panel-close" class="admin-details-close" type="button" aria-label="<?= h(__('Close details panel')) ?>">&times;</button>
  <div id="adam-panel-body" class="adam-panel-body" tabindex="-1">
    <?php admin_details_run_action('admin_details_before', $pdo, $detailsContext); ?>
    <?= $coreDetailsContent ?>
    <?php admin_details_run_action('admin_details', $pdo, $detailsContext); ?>
    <?php admin_details_run_action('admin_details_after', $pdo, $detailsContext); ?>
  </div>
  <span class="admin-details-focus-sentinel" data-panel-focus-end tabindex="0"></span>
</aside>
