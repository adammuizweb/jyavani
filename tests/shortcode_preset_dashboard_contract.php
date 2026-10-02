<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'index' => $root . '/dashboard/admin/shortcodes/index.php',
    'edit' => $root . '/dashboard/admin/shortcodes/edit.php',
    'save' => $root . '/dashboard/admin/shortcodes/save.php',
    'delete' => $root . '/dashboard/admin/shortcodes/delete.php',
    'bulk' => $root . '/dashboard/admin/shortcodes/bulk_action.php',
    'helper' => $root . '/cfg/helpers/shortcode_builder.php',
    'preview' => $root . '/dashboard/admin/shortcodes/preview_layout.php',
    'widget' => $root . '/cfg/helpers/widget_helper.php',
    'layout_editor' => $root . '/dashboard/admin/shortcodes/layout.php',
    'sidebar' => $root . '/dashboard/admin/sidebar/index.php',
    'settings' => $root . '/dashboard/admin/settings/index.php',
    'agents' => $root . '/AGENTS.md',
    'cms_docs' => $root . '/cms.md',
    'demo_widget_docs' => $root . '/schema/demo-content/articles/281-widget-shortcode.html',
    'translations' => $root . '/schema/translations.sql',
    'dashboard_style' => $root . '/public/static/dashboard/css/style.css',
    'action_menu_script' => $root . '/public/static/dashboard/js/action-menu.js',
    'dashboard_layout' => $root . '/dashboard/theme/adiwira/layout.php',
    'public_layout' => $root . '/app/layout.php',
    'pagination_script' => $root . '/public/static/js/preset-pagination.js',
    'slider_layout' => $root . '/public/views/partials/shortcodes/post_cat/sliderpage.php',
];
$source = array_map(static fn(string $file): string => (string)file_get_contents($file), $files);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($source['index'], '$presetPerPage = 15'), 'preset listing paginates at 15 rows per page');
$check(str_contains($source['index'], '$presetPagingItems') && str_contains($source['index'], "\$items[] = '...'") && str_contains($source['index'], 'adam-pagination pagination-wrap'), 'preset listing matches Posts compact numbered and ellipsis pagination');
$check(str_contains($source['index'], '$pageQuery = $presetQuery') && str_contains($source['index'], '$pageQuery[\'p\'] = $pageNumber'), 'numbered preset pagination preserves validated filters');
$check(str_contains($source['index'], 'shortcode_preset_list_filters($_GET, $isAdmin)'), 'preset listing uses validated query filters');
$check(str_contains($source['index'], 'shortcode_preset_list_spec($presetFilters, $uid, $role)'), 'preset listing applies role-aware ownership SQL');
$check(str_contains($source['index'], 'class="sc-toolbar sc-presets-toolbar"')
    && str_contains($source['index'], 'class="sc-filter-fields<?= $isAdmin ? \' has-owner\' : \'\' ?>"')
    && str_contains($source['index'], '@media (max-width: 620px)'), 'preset toolbar separates responsive filters from its primary action');
$check(str_contains($source['index'], 'id="preset-bulk-bar" data-active="false"')
    && str_contains($source['index'], 'id="preset-selection-count"')
    && str_contains($source['index'], 'updatePresetSelection()')
    && str_contains($source['index'], 'presetSelectAll.indeterminate'), 'preset bulk bar exposes contextual selection state and mixed select-all feedback');
$check(str_contains($source['index'], 'id="preset-bulk-action" class="inp" required')
    && str_contains($source['index'], 'id="preset-bulk-submit" class="adam-button"')
    && str_contains($source['index'], 'presetBulkAction.disabled = selected === 0')
    && str_contains($source['index'], 'presetBulkSubmit.disabled = selected === 0'), 'preset bulk controls become contextual when JavaScript selection state is available');
$check(str_contains($source['index'], "name=\"return_to\" value=\"<?= h(\$presetReturnTo)"), 'bulk and delete forms preserve the filtered return URL');
$check(str_contains($source['edit'], "shortcode_preset_editor_fields") && str_contains($source['edit'], 'basePresetConfig'), 'editor hook and config roundtrip preserve plugin fields');
$check(str_contains($source['edit'], 'shortcode_source_providers($sourceContext, $pdo)') && str_contains($source['edit'], 'name="filter_source"'), 'preset editor discovers source providers and exposes a source selector');
$check(str_contains($source['edit'], 'shortcode_selectable_sources($sourceContext, $pdo)')
    && !str_contains($source['edit'], 'foreach (shortcode_preset_sources($sourceContext, $pdo) as $sourceId)'), 'filter-only legacy sources are excluded from selectable options');
$check(str_contains($source['edit'], '$sourceProviders = $isAdmin ? $registeredSourceProviders : []')
    && str_contains($source['edit'], "['suppress_provider_defaults' => !\$isAdmin]")
    && str_contains($source['edit'], 'shortcode_preset_strip_provider_default_fields($pref_config, $registeredSourceProviders)'), 'non-admin editor responses do not serialize provider definitions or provider-only default fields');
$check(substr_count($source['edit'], 'config.source = sourceSelect.value') === 2, 'save and preview configs retain the selected provider source');
$check(str_contains($source['edit'], 'shortcode-preset-source-change'), 'preset editor notifies plugin fields when the selected source changes');
$check(str_contains($source['edit'], 'basePresetConfig = Object.assign({}, basePresetConfig, definition.defaults || {}')
    && str_contains($source['edit'], 'source_owner: definition.owner')
    && str_contains($source['edit'], 'applySourceDefaultsToForm(definition.defaults || {})'), 'source switching merges provider defaults and stable owner identity into config and visible fields');
$check(str_contains($source['edit'], 'shortcode_source_provider_client_definition($provider)')
    && str_contains($source['edit'], 'shortcode_preset_config_for_client($pref_config, $registeredSourceProviders)')
    && str_contains($source['edit'], '(previousDefinition.field_keys || []).forEach')
    && strpos($source['edit'], 'delete basePresetConfig[key]') < strpos($source['edit'], 'basePresetConfig = Object.assign'), 'source switching removes declared old-provider fields before applying client-safe target defaults');
$check(str_contains($source['save'], 'shortcode_preset_config_before_save') && str_contains($source['helper'], 'shortcode_preset_validation_errors'), 'save path exposes stable before-save and validation hooks');
$check(str_contains($source['edit'], 'name="adopt_provider_owner"')
    && str_contains($source['save'], 'shortcode_provider_adoption_allowed(')
    && str_contains($source['save'], '$storedStmt->fetchColumn()')
    && strpos($source['save'], '$storedSource === $finalSource') > strpos($source['save'], "apply_filters('shortcode_preset_config_before_save'"), 'ownerless provider adoption requires clear UI intent and final filtered-state verification');
$check(str_contains($source['translations'], "'Adopt the currently registered provider for this preset'")
    && str_contains($source['translations'], "'This ownerless legacy preset will remain unavailable unless you explicitly adopt its current provider.'")
    && str_contains($source['translations'], "'Provider adoption could not be confirmed. Reload the preset and try again.'")
    && str_contains($source['translations'], "'Explicit provider adoption is required for this ownerless preset.'"), 'provider adoption UI and validation messages have translation seeds');
$check(str_contains($source['translations'], "'Preset Selected'") && str_contains($source['translations'], "'Presets Selected'"), 'preset selection count has Indonesian and German translation seeds');
$check(strpos($source['save'], 'shortcode_preset_normalize_source_transition(') < strpos($source['save'], 'shortcode_preset_apply_source_defaults($config, $context, $pdo)')
    && strpos($source['save'], 'shortcode_preset_apply_source_defaults($config, $context, $pdo)') < strpos($source['save'], "apply_filters('shortcode_preset_config_before_save'"), 'save normalizes source transitions before merging provider defaults, hooks, and validation');
$check(str_contains($source['helper'], "apply_filters('shortcode_source_providers'") && str_contains($source['helper'], "'validate'"), 'source provider registry and validation callback contracts are present');
$widgetRuntime = (string)file_get_contents($root . '/cfg/helpers/widget_shortcodes_p.php');
$check(str_contains($widgetRuntime, 'shortcode_preset_validate_config(')
    && str_contains($widgetRuntime, "? 'persisted_preset' : 'public_runtime'")
    && str_contains($widgetRuntime, "'trust' => \$runtimeTrust")
    && str_contains($widgetRuntime, "'allow_provider_binding' => \$runtimeTrust !== 'persisted_preset'"), 'runtime provider validation uses explicit persisted or untrusted public trust context');
$check(str_contains($source['helper'], 'shortcode_preset_merge_runtime_overrides($runtimeConfig, $overrides, $pdo, $runtimeContext)')
    && str_contains($source['helper'], "'trust' => 'persisted_preset'")
    && str_contains($source['preview'], "'trust' => 'persisted_preset'"), 'persisted runtime and preview paths preserve trusted filters while protecting privileged overrides');
$check(str_contains($source['helper'], "\$publicOverrideFields = \$provider['public_override_fields'] ?? []")
    && str_contains($source['helper'], '!isset($normalizedClientFields[$key])')
    && str_contains($source['helper'], "['scope' => 'runtime_overrides', 'source' => \$source]")
    && str_contains($source['helper'], "\$provider['public_override_fields'] ?? []"), 'provider public overrides are an explicit client-field subset resolved with persisted runtime context');
$check(str_contains($source['helper'], 'shortcode_preset_normalize_public_provider_attributes')
    && str_contains($widgetRuntime, "if (\$runtimeTrust !== 'persisted_preset')")
    && str_contains($widgetRuntime, 'shortcode_preset_normalize_public_provider_attributes($attrs, $provider)')
    && strpos($widgetRuntime, 'shortcode_preset_normalize_public_provider_attributes($attrs, $provider)') < strpos($widgetRuntime, 'shortcode_preset_validate_config('), 'direct shortcode and static collection widgets normalize provider public attributes before defaults, validation, and fetch');
$check(!str_contains($source['helper'], 'shop_products') && !str_contains($source['helper'], 'shop_categories')
    && !str_contains($widgetRuntime, 'shop_products') && !str_contains($widgetRuntime, 'shop_categories'),
    'Core shortcode sources remain domain-neutral and plugin-owned');
$check(str_contains($source['save'], 'shortcode_collection_layout_with_lock($pdo') && strpos($source['save'], 'shortcode_preset_validate_config') > strpos($source['save'], 'shortcode_collection_layout_with_lock($pdo'), 'preset layout validation and database write share the collection lifecycle lock');
$check(str_contains($source['save'], '$stmt->rowCount() === 0') && str_contains($source['save'], '$stmt->rowCount() !== 1'), 'preset update and insert verify affected rows');
$check(strpos($source['delete'], '$return_to =') < strpos($source['delete'], 'adiwira_csrf_validate'), 'delete sanitizes return_to before CSRF errors');
$check(str_contains($source['bulk'], "REQUEST_METHOD") && str_contains($source['bulk'], 'adiwira_csrf_validate'), 'bulk endpoint requires POST and CSRF');
$check(str_contains($source['bulk'], '$pdo->beginTransaction()') && str_contains($source['bulk'], '$pdo->commit()'), 'bulk updates run in a transaction');
$check(str_contains($source['bulk'], "created_by = ?") && str_contains($source['bulk'], "type = 'sc_preset'"), 'bulk SQL enforces ownership and preset type');
$check(str_contains($source['bulk'], '$affected = $stmt->rowCount()'), 'bulk response count comes from affected rows');
$check(str_contains($source['delete'], '$pdo->beginTransaction()') && str_contains($source['delete'], 'shortcode_preset_before_delete($pdo, $id)') && strpos($source['delete'], 'shortcode_preset_before_delete') < strpos($source['delete'], 'UPDATE posts SET is_deleted'), 'single preset pre-delete hook runs inside a transaction before source deletion');
$check(str_contains($source['bulk'], 'shortcode_preset_before_delete($pdo, $deletedId)') && strpos($source['bulk'], 'shortcode_preset_before_delete') < strpos($source['bulk'], 'UPDATE posts SET is_deleted') && strpos($source['bulk'], 'shortcode_preset_before_delete') < strpos($source['bulk'], '$pdo->commit()'), 'bulk preset pre-delete hooks run inside the shared transaction before source deletion');
$check(str_contains($source['helper'], "do_action('admin_shortcode_preset_before_delete'") && str_contains($source['helper'], '!$pdo->inTransaction()'), 'shared pre-delete contract enforces an active transaction and lets listener failures propagate');
$check(str_contains($source['helper'], "apply_filters('shortcode_preset_runtime_config'")
    && str_contains($source['helper'], 'return shortcode_preset_render_row($pdo, $p, $vars, $ctx, $config);'), 'runtime preset config filtering is deferred into the shared render pipeline');
$check(str_contains($source['helper'], "apply_filters('shortcode_preset_preview_config'") && str_contains($source['helper'], "do_action('shortcode_preset_preview_configured'"), 'Core exposes preview-config filter and event contracts');
$check(str_contains($source['helper'], '$excerptLength !== 0')
    && str_contains($widgetRuntime, "if (\$maxLen === 0) return ''")
    && str_contains($source['edit'], 'Number.isInteger(excerptValue)'), 'zero excerpt length suppresses descriptions without weakening positive length validation');
$check(str_contains($source['helper'], "apply_filters('shortcode_preset_preview_result'") && str_contains($source['preview'], 'shortcode_preset_preview_result(null, $config'), 'preset preview exposes a source-aware render/result contract before Core post queries');
$check(substr_count($source['preview'], 'shortcode_preset_prepare_preview_config($config, $role === \'admin\'') === 2, 'inline and stored previews validate with the actual caller role');
$check(strpos($source['preview'], 'shortcode_preset_prepare_preview_config') < strpos($source['preview'], 'shortcode_preset_preview_result'), 'preview validation runs before source result hooks and Core querying');
$check(str_contains($source['preview'], 'post_cat__pagination_state(')
    && substr_count($source['preview'], '$prepareCorePreviewPage(') === 2
    && substr_count($source['preview'], '$decoratePreviewPagination(') === 2
    && str_contains($source['preview'], "'#preview-page-' . \$page"), 'inline and stored Core previews share runtime pagination limits and render representative page controls');
$check(str_contains($source['preview'], 'shortcode_collection_preview_document')
    && str_contains($source['preview'], '$collectionPreviewResponse(')
    && str_contains($source['edit'], "frame.setAttribute('sandbox', 'allow-same-origin')")
    && str_contains($source['edit'], 'frame.srcdoc = documentHtml'), 'preset previews render all Core and provider results inside a script-free theme-styled frame');
$check(!str_contains($source['edit'], "fd.append('preset_id'")
    && !str_contains(substr($source['preview'], strpos($source['preview'], "'mode' => 'inline'"), 240), "'preset_id'"), 'inline preset previews do not expose an unverified stored-preset identity to extension hooks');
$check(str_contains($source['edit'], 'post_cat__layout_template_descriptor')
    && str_contains($source['edit'], "source: descriptor.source")
    && str_contains($source['edit'], "query.set('theme_folder', descriptor.theme_folder)"), 'preset layout actions retain global or theme ownership when the selection changes');
$check(str_contains($source['preview'], 'AND created_by = :created_by') && str_contains($source['preview'], "if (\$role !== 'admin') \$params[':created_by'] = \$uid"), 'stored preview preserves non-admin preset ownership');
$check(str_contains($source['helper'], 'getPrevious') === false && str_contains($source['helper'], 'A dependent plugin prevented preset deletion.') && str_contains($source['helper'], 'error_log('), 'pre-delete internals are logged while the public exception text is generic');
$check(str_contains($source['save'], 'admin_shortcode_preset_after_add') && str_contains($source['save'], 'admin_shortcode_preset_after_edit') && str_contains($source['delete'], 'admin_shortcode_preset_after_delete') && str_contains($source['bulk'], 'admin_shortcode_preset_after_delete'), 'successful CRUD and bulk delete paths fire stable admin lifecycle hooks');
$check(str_contains($source['save'], "do_action('admin_shortcode_preset_after_add', \$newId, \$pdo, \$_POST)") && str_contains($source['save'], "do_action('admin_shortcode_preset_after_edit', \$id, \$pdo, \$_POST)"), 'add and edit lifecycle hook arguments match Core admin conventions');
$check(!str_contains(implode("\n", $source), 'shortcode_preset_pre_save_config') && !preg_match('/(?<!admin_)shortcode_preset_after_(?:add|edit|delete)/', implode("\n", $source)), 'superseded candidate hook names are absent');
$check(str_contains($source['helper'], 'function render_shortcode_preset(')
    && str_contains($source['helper'], 'shortcode_preset_find_published($pdo, $preset)')
    && str_contains($source['helper'], 'shortcode_preset_render_row($pdo, $row, $overrides, $context)'), 'Core exposes a first-class published-preset composition API');
$check(str_contains($source['helper'], 'composition cycle detected for preset')
    && str_contains($source['helper'], 'finally')
    && str_contains($source['helper'], 'array_pop($renderStack)'), 'preset composition has a bounded request-local cycle guard');
$check(str_contains($source['widget'], 'function_exists(\'load_preset_widgets\')')
    && strpos($source['widget'], 'load_preset_widgets($pdo)') < strpos($source['widget'], '// Fallback: registered shortcode-based handler'), 'direct widget rendering lazily loads stored preset handlers before handler fallback');
$check(str_contains($source['index'], "'preset' => (string)(\$p['slug'] ?? '')")
    && str_contains($source['index'], "_e('Build Section')")
    && str_contains($source['layout_editor'], 'id="section-preset-select"')
    && str_contains($source['layout_editor'], 'render_shortcode_preset($pdo,'), 'published presets link to a Theme Section editor with preset composition controls');
$check(str_contains($source['sidebar'], "'label' => __('Published Preset')")
    && str_contains($source['sidebar'], "SELECT slug, title, meta FROM posts WHERE type = 'sc_preset' AND status = 'published'")
    && str_contains($source['sidebar'], 'post_cat__layout_template_descriptor($pdo, $layout)')
    && str_contains($source['sidebar'], "__('Collection Layout') . ': ' . \$layout"), 'sidebar preset choices expose the selected Collection Layout and its global or theme owner');
$check(substr_count($source['sidebar'], "__('Choose a published Preset.')") >= 2
    && str_contains($source['sidebar'], "if (\$errors !== []) \$action = '';"), 'sidebar add and save reject missing or stale preset references before mutation');
$check(str_contains($source['sidebar'], 'the layout itself is not a separate widget.')
    && str_contains($source['sidebar'], 'A saved widget remains invisible when the theme has no sidebar output.'), 'sidebar guidance explains preset composition and theme-controlled visibility');
$check(str_contains($source['sidebar'], 'The primary zone is only the default when a theme renders a sidebar without naming a zone.')
    && str_contains($source['sidebar'], 'Saved changes appear only in frontend templates that render this sidebar zone.')
    && !str_contains($source['sidebar'], 'will appear on the front page')
    && !str_contains($source['sidebar'], 'Results are immediately visible'), 'sidebar guidance never promises output that the active theme does not render');
$check(str_contains($source['edit'], 'Preset Slug — use in shortcodes, PHP renderers, or Published Preset widgets')
    && str_contains($source['save'], 'Select it in a Published Preset sidebar widget or render it by slug.')
    && str_contains($source['settings'], 'Create reusable content-query Presets for shortcodes, Theme Sections, and sidebar widgets.'), 'Preset dashboard terminology describes reusable identity without conflating it with a sidebar item');
$check(str_contains($source['layout_editor'], 'Small heading text from the effective Preset attributes')
    && !str_contains($source['layout_editor'], 'from the sidebar widget'), 'Collection Layout variable help attributes effective values to the Preset render call');
$check(str_contains($source['agents'], 'owns the persisted content query')
    && str_contains($source['agents'], 'Bounded trusted runtime overrides affect only that render call')
    && str_contains($source['cms_docs'], 'sidebar item -> Preset -> Collection Layout')
    && str_contains($source['demo_widget_docs'], 'Override ini hanya berlaku pada render beranda dan tidak mengubah konfigurasi Preset tersimpan.'), 'maintainer and demo documentation distinguish persisted Preset ownership from request-local overrides');
$check(str_contains($source['agents'], 'main/single/post.php')
    && str_contains($source['cms_docs'], '| `single.post` | `before_content`, `after_content` | `main/single/post.php` |')
    && !str_contains($source['agents'], '| `single/post.php` |'), 'theme documentation uses the canonical nested main tree for discovered partials');
$check(substr_count($source['translations'], "'Published Preset'") >= 2
    && substr_count($source['translations'], "'Choose a published Preset.'") >= 2
    && substr_count($source['translations'], "'The active theme must render the selected sidebar zone. A saved widget remains invisible when the theme has no sidebar output.'") >= 2
    && substr_count($source['translations'], "'Saved changes appear only in frontend templates that render this sidebar zone.'") >= 2
    && !str_contains($source['translations'], "'Add the \"Post/Page List\" widget under Dashboard → Appearance → Widgets"), 'sidebar preset terminology has current Indonesian and German translation seeds without the obsolete widget path');
$check(str_contains($source['layout_editor'], 'class="adam-link--full">')
    && !str_contains($source['layout_editor'], 'class="adam-link">edit preset'),
    'the inline edit-preset help link follows the surrounding text baseline');
$check(str_contains($source['edit'], 'id="article-category-fields"')
    && strpos($source['edit'], "_e('Include Child Categories')") > strpos($source['edit'], "_e('Category (leave empty for all)')")
    && str_contains($source['edit'], 'categoryFields.hidden = !articleSelected')
    && str_contains($source['edit'], "type.value === 'article' ? cat.value : ''"), 'category and child-category controls are adjacent and active only for article presets');
$check(str_contains($source['edit'], "__('What does Limit mean?')")
    && str_contains($source['edit'], "__('What does Max Items mean?')")
    && str_contains($source['edit'], "__('What does Offset mean?')")
    && str_contains($source['edit'], "__('What does Excerpt Length mean?')")
    && str_contains($source['edit'], 'class="field-help__tooltip" role="tooltip"'), 'preset numeric controls use the accessible Core field-help component');
$check(str_contains($source['edit'], 'name="filter_pagination"')
    && str_contains($source['edit'], 'name="filter_max_items"')
    && str_contains($source['edit'], 'maxItemsValue > limitValue')
    && str_contains($source['edit'], 'syncPaginationField()')
    && str_contains($source['helper'], "'pagination' => '0'")
    && !str_contains($source['edit'], 'randomSelected')
    && !str_contains($source['helper'], 'Pagination cannot be used with random ordering.'), 'preset editor and validation expose seeded pagination for deterministic and random Core collections');
$check(substr_count($source['edit'], "sourceOwnsCompatibilityField('max_items')") >= 2
    && substr_count($source['edit'], "sourceOwnsCompatibilityField('pagination')") >= 3
    && str_contains($source['helper'], 'shortcode_source_provider_compatibility_field_keys()'), 'provider presets preserve explicitly declared legacy fields that collide with new Core pagination names');
$check(str_contains($source['translations'], "'What does Limit mean?'")
    && str_contains($source['translations'], "'What does Max Items mean?'")
    && str_contains($source['translations'], "'What does Offset mean?'")
    && str_contains($source['translations'], "'What does Excerpt Length mean?'")
    && str_contains($source['translations'], "'What does Pagination mean?'"), 'preset field guidance has Indonesian and German translation seeds');
$check(str_contains($source['public_layout'], '/static/js/preset-pagination.js')
    && str_contains($source['pagination_script'], "fetch(targetUrl.href")
    && str_contains($source['pagination_script'], 'data-pcat-pagination-root')
    && str_contains($source['pagination_script'], "history.pushState")
    && str_contains($widgetRuntime, 'data-pcat-pagination-root='), 'published preset pagination progressively enhances server links with isolated AJAX replacement');
$check(str_contains($source['pagination_script'], 'data-pcat-pagination-slider="core"')
    && str_contains($source['pagination_script'], "paginationLink(slider, 'next')")
    && str_contains($source['pagination_script'], 'pcatPaginationArrival')
    && str_contains($widgetRuntime, 'data-pcat-pagination-mode="slider"'), 'slider arrows fetch adjacent batches and preserve backward arrival position without numbered controls');
$check(str_contains($source['pagination_script'], 'currentPaginationUrl(link, key)')
    && str_contains($source['pagination_script'], 'current.searchParams.set(key')
    && str_contains($source['pagination_script'], "name.indexOf('pcat_seed_') === 0")
    && str_contains($source['pagination_script'], 'seedStableCurrentUrl(targetUrl).href')
    && str_contains($source['pagination_script'], "document.querySelectorAll('[data-pcat-pagination-root] .pcat-pagination a[href]')")
    && str_contains($source['pagination_script'], 'activeRoots.forEach(function (currentRoot, index)')
    && str_contains($source['pagination_script'], 'replacements.forEach(function (replacement)')
    && str_contains($source['slider_layout'], "mx === 0 && !pageLink('prev') && !pageLink('next')")
    && str_contains($source['slider_layout'], 'overflow-x: auto'), 'pagination preserves query and random history state, synchronizes duplicate roots, and keeps short slider batches reachable');
$check(str_contains($source['slider_layout'], 'sliderpage-thumb--placeholder')
    && str_contains($source['slider_layout'], '--sp-cols: var(--sliderpage-cols-tablet, 2)')
    && str_contains($source['slider_layout'], '--sp-cols: var(--sliderpage-cols-mobile, 1)')
    && str_contains($source['slider_layout'], 'grid-template-columns: minmax(0, 1fr)')
    && str_contains($source['slider_layout'], 'getComputedStyle(track)'),
    'built-in slider preview has visible placeholder media, responsive columns, and no empty arrow gutters before enhancement');
$check(!str_contains($source['translations'], 'Random ordering cannot be paginated.'), 'translation seeds do not retain the obsolete random-pagination restriction');
$check(str_contains($source['index'], 'class="adam-actions__trigger"')
    && str_contains($source['index'], 'class="adam-actions__menu" role="menu" hidden')
    && substr_count($source['index'], 'role="menuitem"') >= 3
    && str_contains($source['index'], 'aria-controls="<?= h($actionMenuId) ?>"'), 'preset row actions use the reusable accessible Core overflow menu');
$check(str_contains($source['dashboard_style'], '.adam-actions__menu')
    && str_contains($source['dashboard_style'], '.adam-actions__trigger[aria-expanded="true"]')
    && str_contains($source['dashboard_layout'], '/static/dashboard/js/action-menu.js')
    && str_contains($source['action_menu_script'], "event.key === 'Escape'")
    && str_contains($source['action_menu_script'], "event.key === 'ArrowDown'")
    && str_contains($source['action_menu_script'], 'getBoundingClientRect()')
    && str_contains($source['action_menu_script'], 'trigger.focus({ preventScroll: true })'), 'Core overflow component provides viewport positioning and keyboard focus management');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
