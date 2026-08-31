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

$base = ADMIN_BASE_PATH;
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to($_REQUEST['return_to'] ?? null, $base . '/?page=admin/shortcodes/index&tab=presets')
    : ($base . '/?page=admin/shortcodes/index&tab=presets');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;

$preset = null;
$pref_title = '';
$pref_slug = '';
$pref_status = 'published';
$pref_config = shortcode_preset_default_config($pdo);

if ($isEdit) {
    $sql = "SELECT * FROM posts WHERE id = :id AND type = 'sc_preset' AND is_deleted = 0";
    $params = [':id' => $id];
    if (!$isAdmin) {
        $sql .= " AND created_by = :uid";
        $params[':uid'] = $uid;
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $preset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$preset) {
        http_response_code(404);
        echo '<p>' . __('Preset not found.') . '</p>';
        return;
    }

    $pref_title = (string)($preset['title'] ?? '');
    $pref_slug = (string)($preset['slug'] ?? '');
    $pref_status = (string)($preset['status'] ?? 'draft');

    $pref_config = shortcode_preset_config_loaded(
        (string)($preset['meta'] ?? ''),
        $preset,
        $pdo,
        ['suppress_provider_defaults' => !$isAdmin]
    );
}

$save_nonce = bin2hex(random_bytes(12));
if ($isEdit) {
    $_SESSION['sc_save_nonce_' . $id] = $save_nonce;
} else {
    $_SESSION['sc_add_nonce'] = $save_nonce;
}

// Fetch categories for dropdown
$cats = [];
try {
    $cs = $pdo->query("SELECT id, name, slug FROM categories WHERE is_deleted = 0 ORDER BY name ASC");
    $cats = $cs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Fetch users for author dropdown (admin only)
$users = [];
if ($isAdmin) {
    try {
        $us = $pdo->query("SELECT id, username, name FROM users WHERE is_deleted = 0 ORDER BY username ASC");
        $users = $us->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// Use the same filename contract and resolver as frontend rendering.
$layoutOptions = function_exists('post_cat__layout_names') ? post_cat__layout_names($pdo) : [];
$sourceContext = ['scope' => 'admin_editor', 'is_admin' => $isAdmin, 'user_id' => $uid];
$registeredSourceProviders = shortcode_source_providers($sourceContext, $pdo);
if (!$isAdmin) $pref_config = shortcode_preset_strip_provider_default_fields($pref_config, $registeredSourceProviders);
$sourceProviders = $isAdmin ? $registeredSourceProviders : [];
$sourceOptions = [];
$sourceClientDefinitions = [
    'posts' => ['owner' => 'core', 'defaults' => [], 'field_keys' => []],
];
foreach ($sourceProviders as $sourceId => $provider) {
    $sourceClientDefinitions[$sourceId] = shortcode_source_provider_client_definition($provider);
}
$clientPresetConfig = shortcode_preset_config_for_client($pref_config, $registeredSourceProviders);
foreach (shortcode_selectable_sources($sourceContext, $pdo) as $sourceId) {
    $providerLabel = $sourceProviders[$sourceId]['label'] ?? null;
    $sourceOptions[$sourceId] = is_string($providerLabel) && $providerLabel !== ''
        ? __($providerLabel)
        : __(match ($sourceId) {
            'posts' => 'Posts and pages',
            default => ucwords(str_replace(['_', '-'], ' ', $sourceId)),
        });
}
$currentSource = is_string($pref_config['source'] ?? null) && $pref_config['source'] !== ''
    ? $pref_config['source']
    : 'posts';
$currentSourceOwner = is_string($pref_config['source_owner'] ?? null) ? $pref_config['source_owner'] : '';
$expectedSourceOwner = $sourceClientDefinitions[$currentSource]['owner'] ?? null;
$sourceUnavailable = !isset($sourceOptions[$currentSource])
    || ($expectedSourceOwner !== 'core' && (!is_string($expectedSourceOwner) || $currentSourceOwner === '' || !hash_equals($expectedSourceOwner, $currentSourceOwner)));
if ($sourceUnavailable) $sourceOptions[$currentSource] = $currentSource . ' — ' . __('Provider unavailable');
$canAdoptProvider = $isAdmin && $isEdit && $currentSourceOwner === ''
    && isset($sourceProviders[$currentSource])
    && !in_array($currentSource, shortcode_core_source_ids(), true);
?>
<section class="adam-card">
  <h2><?= $isEdit ? _e('Edit Preset') : _e('Add New Preset') ?></h2>

  <form method="post" id="sc-form" action="<?= h($base . '/admin/shortcodes/save.php') ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="save_nonce" value="<?= h($save_nonce) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="return_to" value="<?= h($return_to) ?>">
    <input type="hidden" name="config_json" id="config-json" value="">

    <div class="form-toolbar" style="display:flex;align-items:center;gap:.5rem;margin-bottom:.8rem;">
      <button type="submit" class="adam-button" id="btn-save"><?= svg_ico('save', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?= $isEdit ? __('Save Changes') : __('Save') ?></button>
      <a href="<?= h($return_to) ?>" class="adam-cancle"><?=_e('Cancel')?></a>
      <div style="margin-left:auto;font-size:.9rem;color:var(--adam-muted,#555);">
        <?php if ($isEdit): ?>
          Updated: <span id="updated-at"><?= h(function_exists('format_datetime_indo') ? format_datetime_indo((string)($preset['updated_at'] ?? '-')) : (string)($preset['updated_at'] ?? '-')) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="adam-accordion" id="sc-general-accordion" data-open="1">
      <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="sc-general-body">
        <?= svg_ico('cog', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Preset Settings')?>
        <span class="chevron">▸</span>
      </button>
      <div class="adam-accordion-body" id="sc-general-body">
        <label><?=_e('Preset Name')?><br>
          <input type="text" name="title" value="<?= h($pref_title) ?>" class="inpud" required>
        </label>
        <label style="display:block;margin-top:.6rem"><?=_e('Widget Name (slug) — used in sidebar')?>: <code>widget('nama_ini')</code><br>
          <input type="text" name="slug" value="<?= h($pref_slug) ?>" class="inpud" placeholder="<?=_e('Leave empty to auto-generate')?>">
        </label>
        <label style="display:block;margin-top:.6rem"><?=_e('Status')?><br>
          <select name="status" class="inpud">
            <option value="published" <?= $pref_status === 'published' ? 'selected' : '' ?>><?=_e('Published')?></option>
            <option value="draft" <?= $pref_status === 'draft' ? 'selected' : '' ?>><?=_e('Draft')?></option>
            <option value="private" <?= $pref_status === 'private' ? 'selected' : '' ?>><?=_e('Private')?></option>
          </select>
        </label>
      </div>
    </div>

    <div class="adam-accordion" id="sc-filter-accordion" style="margin-top:.5rem" data-open="1">
      <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="sc-filter-body">
        <?= svg_ico('search', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Content Filter')?>
        <span class="chevron">▸</span>
      </button>
      <div class="adam-accordion-body" id="sc-filter-body">
        <?php if ($isAdmin): ?>
        <label><?=_e('Content source')?><br>
          <select name="filter_source" class="inpud" id="filter-source">
            <?php foreach ($sourceOptions as $sourceId => $sourceLabel): ?>
              <option value="<?=h($sourceId)?>" <?= $currentSource === $sourceId ? 'selected' : '' ?>><?=h($sourceLabel)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if ($canAdoptProvider): ?>
          <label class="tm-note" style="display:block;margin-top:.5rem">
            <input type="checkbox" name="adopt_provider_owner" value="1">
            <?=_e('Adopt the currently registered provider for this preset')?><br>
            <small><?=_e('This ownerless legacy preset will remain unavailable unless you explicitly adopt its current provider.')?></small>
          </label>
        <?php endif; ?>
        <?php else: ?>
          <input type="hidden" name="filter_source" id="filter-source" value="posts">
        <?php endif; ?>

        <label><?=_e('Post Type')?><br>
          <select name="filter_type" class="inpud" id="filter-type">
            <option value="article" <?= ($pref_config['type'] ?? 'article') === 'article' ? 'selected' : '' ?>><?=_e('Article')?></option>
            <option value="page" <?= ($pref_config['type'] ?? '') === 'page' ? 'selected' : '' ?>><?=_e('Page')?></option>
          </select>
        </label>

        <label style="display:block;margin-top:.6rem"><?=_e('Category (leave empty for all)')?><br>
          <select name="filter_category" class="inpud">
            <option value=""><?= _e('-- All Categories --') ?></option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= h($c['slug']) ?>" <?= ($pref_config['category'] ?? '') === $c['slug'] ? 'selected' : '' ?>><?= h($c['name']) ?> (<?= h($c['slug']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>

        <?php if ($isAdmin): ?>
        <label style="display:block;margin-top:.6rem"><?=_e('Author (leave empty for all)')?><br>
          <select name="filter_author" class="inpud">
            <option value=""><?=_e('-- All Authors --')?></option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= ($pref_config['author'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= h($u['username'] . ($u['name'] ? ' (' . $u['name'] . ')' : '')) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php else: ?>
          <input type="hidden" name="filter_author" value="">
        <?php endif; ?>

        <div style="display:flex;gap:1rem;margin-top:.6rem;flex-wrap:wrap;">
          <label><?=_e('Limit')?><br>
            <input type="number" name="filter_limit" value="<?= (int)($pref_config['limit'] ?? 5) ?>" class="inpud" style="width:100px" min="1" max="200">
          </label>
          <label><?=_e('Offset')?><br>
            <input type="number" name="filter_offset" value="<?= (int)($pref_config['offset'] ?? 0) ?>" class="inpud" style="width:100px" min="0">
          </label>
          <label><?=_e('Excerpt Length')?><br>
            <input type="number" name="filter_excerpt" value="<?= (int)($pref_config['excerpt_len'] ?? 90) ?>" class="inpud" style="width:100px" min="10" max="1000">
          </label>
        </div>

        <label style="display:block;margin-top:.6rem"><?=_e('Include Child Categories')?><br>
          <select name="filter_include_children" class="inpud" style="width:auto;">
            <option value="1" <?= (string)($pref_config['include_children'] ?? '1') === '1' ? 'selected' : '' ?>><?=_e('Yes')?></option>
            <option value="0" <?= (string)($pref_config['include_children'] ?? '1') === '0' ? 'selected' : '' ?>><?=_e('No')?></option>
          </select>
        </label>
      </div>
    </div>

    <div class="adam-accordion" style="margin-top:.5rem" data-open="1">
      <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="sc-order-body">
        <?= svg_ico('chart-bar', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Order & Date')?>
        <span class="chevron">▸</span>
      </button>
      <div class="adam-accordion-body" id="sc-order-body">
        <label><?=_e('Order')?><br>
          <select name="filter_order" class="inpud" id="filter-order" style="width:auto;">
            <option value="latest" <?= ($pref_config['order_by'] ?? '') === 'created_at' && ($pref_config['order_dir'] ?? 'DESC') === 'DESC' ? 'selected' : '' ?>><?=_e('Latest')?></option>
            <option value="oldest" <?= ($pref_config['order_by'] ?? '') === 'created_at' && ($pref_config['order_dir'] ?? 'DESC') === 'ASC' ? 'selected' : '' ?>><?=_e('Oldest')?></option>
            <option value="random" <?= ($pref_config['order_by'] ?? '') === 'RAND()' ? 'selected' : '' ?>><?=_e('Random')?></option>
            <option value="custom" <?= !in_array(($pref_config['order_by'] ?? 'created_at'), ['created_at', 'RAND()']) || ($pref_config['order_by'] ?? 'created_at') === 'created_at' && !in_array(($pref_config['order_dir'] ?? 'DESC'), ['DESC', 'ASC']) ? (in_array(($pref_config['order_by'] ?? ''), ['sort_order', 'id', 'updated_at', 'title']) ? 'selected' : '') : '' ?> id="order-custom-opt"><?=_e('Custom')?></option>
          </select>
        </label>

        <div id="order-custom-fields" style="display:<?= in_array(($pref_config['order_by'] ?? ''), ['sort_order', 'id', 'updated_at', 'title']) ? 'flex' : 'none' ?>;gap:1rem;margin-top:.6rem;">
          <label><?=_e('Column')?><br>
            <select name="filter_order_custom_col" class="inpud" style="width:auto;">
              <option value="created_at" <?= ($pref_config['order_by'] ?? '') === 'created_at' ? 'selected' : '' ?>>created_at</option>
              <option value="sort_order" <?= ($pref_config['order_by'] ?? '') === 'sort_order' ? 'selected' : '' ?>>sort_order</option>
              <option value="id" <?= ($pref_config['order_by'] ?? '') === 'id' ? 'selected' : '' ?>>id</option>
              <option value="updated_at" <?= ($pref_config['order_by'] ?? '') === 'updated_at' ? 'selected' : '' ?>>updated_at</option>
              <option value="title" <?= ($pref_config['order_by'] ?? '') === 'title' ? 'selected' : '' ?>>title</option>
            </select>
          </label>
          <label><?=_e('Direction')?><br>
            <select name="filter_order_custom_dir" class="inpud" style="width:auto;">
              <option value="DESC" <?= ($pref_config['order_dir'] ?? 'DESC') === 'DESC' ? 'selected' : '' ?>>DESC</option>
              <option value="ASC" <?= ($pref_config['order_dir'] ?? 'DESC') === 'ASC' ? 'selected' : '' ?>>ASC</option>
            </select>
          </label>
        </div>

        <div style="display:flex;gap:1rem;margin-top:.6rem;flex-wrap:wrap;">
          <label><?=_e('Date From (YYYY-MM-DD)')?><br>
            <input type="text" name="filter_date_from" value="<?= h($pref_config['date_from'] ?? '') ?>" class="inpud" style="width:160px" placeholder="2024-01-01">
          </label>
          <label><?=_e('Date To (YYYY-MM-DD)')?><br>
            <input type="text" name="filter_date_to" value="<?= h($pref_config['date_to'] ?? '') ?>" class="inpud" style="width:160px" placeholder="2024-12-31">
          </label>
        </div>
      </div>
    </div>

    <div class="adam-accordion" style="margin-top:.5rem" data-open="1">
      <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="sc-layout-body">
        <?= svg_ico('palette', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Display (Layout)')?>
        <span class="chevron">▸</span>
      </button>
      <div class="adam-accordion-body" id="sc-layout-body">
        <label><?=_e('Layout Template')?><br>
          <select name="filter_layout" class="inpud" style="width:auto;">
            <?php foreach ($layoutOptions as $lo): ?>
              <option value="<?= h($lo) ?>" <?= ($pref_config['layout'] ?? 'list') === $lo ? 'selected' : '' ?>><?= h($lo) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label style="display:block;margin-top:.6rem"><?=_e('Class Prefix (optional)')?><br>
          <input type="text" name="filter_class_prefix" value="<?= h($pref_config['class_prefix'] ?? '') ?>" class="inpud" placeholder="<?=_e('example: featured-news')?>">
        </label>

        <label style="display:block;margin-top:.6rem"><?=_e('Wrapper')?><br>
          <select name="filter_wrap" class="inpud" style="width:auto;">
            <option value="1" <?= (string)($pref_config['wrap'] ?? '1') === '1' ? 'selected' : '' ?>><?=_e('Yes (wrap with div)')?></option>
            <option value="0" <?= (string)($pref_config['wrap'] ?? '1') === '0' ? 'selected' : '' ?>><?=_e('No')?></option>
          </select>
        </label>
      </div>
    </div>
    <?php do_action('shortcode_preset_editor_fields', $pref_config, $preset, $pdo, ['is_admin' => $isAdmin, 'user_id' => $uid, 'source' => $currentSource, 'sources' => $sourceProviders]); ?>
  </form>
</section>

<div style="margin-top:1rem;display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:var(--adam-surface-3);border-radius:8px;font-size:.85rem;">
  <span>🧩 <strong><?=_e('Layout:')?></strong></span>
  <span id="edit-layout-name" style="color:var(--adam-accent);font-weight:600;"><?= h($pref_config['layout'] ?? 'list') ?></span>
  <a id="edit-layout-link" href="<?= h($base . '/?page=admin/shortcodes/layout&file=' . ($pref_config['layout'] ?? 'list') . '.php') ?>" class="adam-link" style="font-size:.8rem;" target="_blank">✏️ <?=_e('Edit This Layout')?></a>
  <span style="flex:1"></span>
  <span style="font-size:.78rem;color:var(--adam-muted,#888);"><?=_e('Preset = content filter · Layout = visual style')?></span>
</div>

<section class="adam-card" style="margin-top:1rem;">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;">
    <h3 style="margin:0;font-size:.95rem;">👁️ <?=_e('Live Preview')?></h3>
    <span style="display:flex;align-items:center;gap:6px;">
      <span id="edit-preview-mode" style="font-size:.72rem;background:var(--adam-surface-3);padding:.15rem .45rem;border-radius:3px;color:var(--adam-muted,#888);"><?=_e('preset config')?></span>
      <span id="edit-preview-status" style="font-size:.78rem;color:var(--adam-muted,#888);"><?=_e('Ready')?></span>
    </span>
  </div>
  <div id="edit-preview-error" style="display:none;padding:.4rem .7rem;font-size:.82rem;background:#fef0ef;color:#c0392b;border:1px solid #f5c6cb;border-radius:4px;margin-bottom:.5rem;"></div>
  <div id="edit-preview-content" style="min-height:100px;border:1px solid var(--adam-border-soft,#ddd);border-radius:6px;padding:1rem;background:var(--adam-bg,#fff);font-size:.9rem;color:var(--adam-muted,#888);">
    <?=_e('Press the "Preview" button to render the layout with real database content.')?>
  </div>
  <div style="margin-top:.5rem;display:flex;gap:.5rem;align-items:center;">
    <button type="button" id="edit-preview-btn" class="adam-button" style="font-size:.85rem;">🔄 <?=_e('Preview with Real Data')?></button>
    <span style="font-size:.78rem;color:var(--adam-muted,#888);"><?=_e('Fetches real content from the selected source according to the filters above')?></span>
  </div>
</section>

<div class="sc-help" style="margin-top:1rem;padding:1rem;background:var(--adam-surface-3);border-radius:var(--adam-radius,8px);border:1px solid var(--adam-border-soft);font-size:.85rem;color:var(--adam-text);line-height:1.5;">
  <div style="display:flex;align-items:flex-start;gap:.5rem;">
    <div style="font-size:1.1rem;flex-shrink:0;">💡</div>
    <div>
      <?=_e('<strong>How Preset &amp; Layout relate:</strong>')?><br>
      <?=_e('<strong>Preset</strong> = "what to display" (category, count, order, etc.).')?><br>
      <?=_e('<strong>Layout</strong> = the PHP file in <code>app/views/partials/shortcodes/post_cat/</code> that controls "how it looks".')?><br>
      <?= sprintf(__('One layout can be reused by many presets. Edit the layout in the %sLayouts Manager%s → changes instantly apply to every preset using that layout.'), '<a href="' . h($base . '/?page=admin/shortcodes/index&tab=layouts') . '" class="adam-link">', '</a>') ?>
    </div>
  </div>
</div>

<script>
(function(){
  var basePresetConfig = <?= json_encode($clientPresetConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var sourceDefinitions = <?= json_encode($sourceClientDefinitions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
  function presetConfigCopy() {
    return JSON.parse(JSON.stringify(basePresetConfig || {}));
  }
  var form = document.getElementById('sc-form');
  var btn = document.getElementById('btn-save');
  var orderSelect = document.getElementById('filter-order');
  var customFields = document.getElementById('order-custom-fields');
  var customOpt = document.getElementById('order-custom-opt');
  var sourceSelect = document.getElementById('filter-source');

  function applySourceDefaultsToForm(defaults) {
    var fields = {
      type: 'filter_type',
      category: 'filter_category',
      author: 'filter_author',
      limit: 'filter_limit',
      offset: 'filter_offset',
      excerpt_len: 'filter_excerpt',
      include_children: 'filter_include_children',
      date_from: 'filter_date_from',
      date_to: 'filter_date_to',
      layout: 'filter_layout',
      class_prefix: 'filter_class_prefix',
      wrap: 'filter_wrap'
    };
    Object.keys(fields).forEach(function(key) {
      if (!Object.prototype.hasOwnProperty.call(defaults, key)) return;
      var field = document.querySelector('[name="' + fields[key] + '"]');
      if (field) field.value = defaults[key] === null ? '' : String(defaults[key]);
    });
  }

  if (orderSelect && customFields) {
    orderSelect.addEventListener('change', function() {
      customFields.style.display = this.value === 'custom' ? 'flex' : 'none';
    });
  }

  if (sourceSelect) {
    sourceSelect.addEventListener('change', function() {
      var previousDefinition = sourceDefinitions[basePresetConfig.source] || null;
      var definition = sourceDefinitions[sourceSelect.value] || null;
      if (previousDefinition) {
        (previousDefinition.field_keys || []).forEach(function(key) {
          delete basePresetConfig[key];
        });
      }
      if (definition) {
        basePresetConfig = Object.assign({}, basePresetConfig, definition.defaults || {}, {
          source: sourceSelect.value,
          source_owner: definition.owner
        });
        applySourceDefaultsToForm(definition.defaults || {});
      }
      document.dispatchEvent(new CustomEvent('shortcode-preset-source-change', {
        detail: {
          source: sourceSelect.value,
          owner: definition ? definition.owner : '',
          defaults: definition ? (definition.defaults || {}) : {},
          form: form
        }
      }));
    });
  }

  if (form) {
    form.addEventListener('submit', function(e) {
      if (btn) btn.disabled = true;

      // Build config JSON
      var config = presetConfigCopy();

      // Remove hidden config_json field value - we'll rebuild
      var configField = document.getElementById('config-json');

      if (sourceSelect) config.source = sourceSelect.value;

      var type = document.querySelector('[name="filter_type"]');
      if (type) config.type = type.value;

      var cat = document.querySelector('[name="filter_category"]');
      if (cat) config.category = cat.value;

      var author = document.querySelector('[name="filter_author"]');
      if (author) config.author = author.value ? parseInt(author.value, 10) : null;

      var limit = document.querySelector('[name="filter_limit"]');
      if (limit) config.limit = parseInt(limit.value, 10) || 5;

      var offset = document.querySelector('[name="filter_offset"]');
      if (offset) config.offset = parseInt(offset.value, 10) || 0;

      var excerpt = document.querySelector('[name="filter_excerpt"]');
      if (excerpt) config.excerpt_len = parseInt(excerpt.value, 10) || 90;

      var inc = document.querySelector('[name="filter_include_children"]');
      if (inc) config.include_children = inc.value;

      var order = orderSelect ? orderSelect.value : 'latest';
      if (order === 'latest') {
        config.order_by = 'created_at';
        config.order_dir = 'DESC';
      } else if (order === 'oldest') {
        config.order_by = 'created_at';
        config.order_dir = 'ASC';
      } else if (order === 'random') {
        config.order_by = 'RAND()';
        delete config.order_dir;
      } else if (order === 'custom') {
        var col = document.querySelector('[name="filter_order_custom_col"]');
        var dir = document.querySelector('[name="filter_order_custom_dir"]');
        config.order_by = col ? col.value : 'created_at';
        config.order_dir = dir ? dir.value : 'DESC';
      }

      var df = document.querySelector('[name="filter_date_from"]');
      if (df) config.date_from = df.value || null;

      var dt = document.querySelector('[name="filter_date_to"]');
      if (dt) config.date_to = dt.value || null;

      var layout = document.querySelector('[name="filter_layout"]');
      if (layout) config.layout = layout.value;

      var cp = document.querySelector('[name="filter_class_prefix"]');
      if (cp) config.class_prefix = cp.value;

      var wrap = document.querySelector('[name="filter_wrap"]');
      if (wrap) config.wrap = wrap.value;

      if (configField) configField.value = JSON.stringify(config);
    });
  }

  // --- Live Preview ---
  var previewBtn = document.getElementById('edit-preview-btn');
  var previewContent = document.getElementById('edit-preview-content');
  var previewError = document.getElementById('edit-preview-error');
  var previewStatus = document.getElementById('edit-preview-status');
  var previewMode = document.getElementById('edit-preview-mode');
  var layoutSelect = document.querySelector('[name="filter_layout"]');
  var layoutLink = document.getElementById('edit-layout-link');
  var layoutNameSpan = document.getElementById('edit-layout-name');

  function buildPreviewConfig() {
    var config = presetConfigCopy();
    if (sourceSelect) config.source = sourceSelect.value;
    var type = document.querySelector('[name="filter_type"]');
    if (type) config.type = type.value;

    var cat = document.querySelector('[name="filter_category"]');
    if (cat) config.category = cat.value;

    var author = document.querySelector('[name="filter_author"]');
    if (author) config.author = author.value ? parseInt(author.value, 10) : null;

    var limit = document.querySelector('[name="filter_limit"]');
    if (limit) config.limit = parseInt(limit.value, 10) || 5;

    var offset = document.querySelector('[name="filter_offset"]');
    if (offset) config.offset = parseInt(offset.value, 10) || 0;

    var excerpt = document.querySelector('[name="filter_excerpt"]');
    if (excerpt) config.excerpt_len = parseInt(excerpt.value, 10) || 90;

    var inc = document.querySelector('[name="filter_include_children"]');
    if (inc) config.include_children = inc.value;

    var orderSel = document.getElementById('filter-order');
    var order = orderSel ? orderSel.value : 'latest';
    if (order === 'latest') {
      config.order_by = 'created_at'; config.order_dir = 'DESC';
    } else if (order === 'oldest') {
      config.order_by = 'created_at'; config.order_dir = 'ASC';
    } else if (order === 'random') {
      config.order_by = 'RAND()';
    } else if (order === 'custom') {
      var col = document.querySelector('[name="filter_order_custom_col"]');
      var dir = document.querySelector('[name="filter_order_custom_dir"]');
      config.order_by = col ? col.value : 'created_at';
      config.order_dir = dir ? dir.value : 'DESC';
    }

    var df = document.querySelector('[name="filter_date_from"]');
    if (df) config.date_from = df.value || null;
    var dt = document.querySelector('[name="filter_date_to"]');
    if (dt) config.date_to = dt.value || null;

    if (layoutSelect) config.layout = layoutSelect.value;
    var cp = document.querySelector('[name="filter_class_prefix"]');
    if (cp) config.class_prefix = cp.value;
    var wrap = document.querySelector('[name="filter_wrap"]');
    if (wrap) config.wrap = wrap.value;

    document.dispatchEvent(new CustomEvent('shortcode-preset-preview-config', {
      detail: { config: config, form: form }
    }));

    return config;
  }

  function doPresetPreview() {
    var config = buildPreviewConfig();
    if (!config.layout) {
      if (window.NewNotifToast) {
        window.NewNotifToast.show({ type: 'warning', title: <?=json_encode(__('Preview'))?>, message: <?=json_encode(__('Choose a layout template first.'))?> });
      }
      return;
    }

    previewStatus.textContent = <?=json_encode(__('Loading...'))?>;
    previewError.style.display = 'none';

    var fd = new FormData();
    fd.append('content', '<?= '// auto-loaded from layout file' ?>');
    fd.append('preset_config', JSON.stringify(config));
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);

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
        previewStatus.textContent = <?=json_encode(__('Ready'))?> + ' ✔';
        previewMode.textContent = '📦 ' + config.layout;
      } else {
        previewContent.innerHTML = data.html || '<div style="color:#e74c3c;padding:1rem;">' + <?=json_encode(__('Error'))?> + '</div>';
        if (data.error) {
          previewError.style.display = 'block';
          previewError.textContent = '⚠ ' + data.error;
        }
        previewStatus.textContent = 'error ✗';
      }
    })
    .catch(function(err) {
      previewContent.innerHTML = '<div style="color:#e74c3c;padding:1rem;"><?=__('Failed: ')?>' + err.message + '</div>';
      previewError.style.display = 'none';
      previewStatus.textContent = 'error ✗';
    });
  }

  if (previewBtn) {
    previewBtn.addEventListener('click', doPresetPreview);
  }

  if (layoutSelect && layoutLink && layoutNameSpan) {
    layoutSelect.addEventListener('change', function() {
      var v = this.value;
      layoutNameSpan.textContent = v;
      layoutLink.href = '<?= $base ?>/?page=admin/shortcodes/layout&file=' + encodeURIComponent(v) + '.php';
    });
  }

  document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      if (form) form.requestSubmit();
    }
  });
})();
</script>
