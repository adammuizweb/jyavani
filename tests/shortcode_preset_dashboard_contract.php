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
$check(str_contains($source['index'], "name=\"return_to\" value=\"<?= h(\$presetReturnTo)"), 'bulk and delete forms preserve the filtered return URL');
$check(str_contains($source['edit'], "shortcode_preset_editor_fields") && str_contains($source['edit'], 'basePresetConfig'), 'editor hook and config roundtrip preserve plugin fields');
$check(str_contains($source['save'], 'shortcode_preset_config_before_save') && str_contains($source['helper'], 'shortcode_preset_validation_errors'), 'save path exposes stable before-save and validation hooks');
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
$check(str_contains($source['helper'], "apply_filters('shortcode_preset_runtime_config'") && strpos($source['helper'], "apply_filters('shortcode_preset_runtime_config'") > strpos($source['helper'], 'register_widget_shortcode_handler($slug'), 'runtime preset config filtering is deferred into the render handler');
$check(str_contains($source['helper'], "apply_filters('shortcode_preset_preview_config'") && str_contains($source['helper'], "do_action('shortcode_preset_preview_configured'"), 'Core exposes preview-config filter and event contracts');
$check(str_contains($source['helper'], "apply_filters('shortcode_preset_preview_result'") && str_contains($source['preview'], 'shortcode_preset_preview_result(null, $config'), 'preset preview exposes a source-aware render/result contract before Core post queries');
$check(substr_count($source['preview'], 'shortcode_preset_prepare_preview_config($config, $role === \'admin\'') === 2, 'inline and stored previews validate with the actual caller role');
$check(strpos($source['preview'], 'shortcode_preset_prepare_preview_config') < strpos($source['preview'], 'shortcode_preset_preview_result'), 'preview validation runs before source result hooks and Core querying');
$check(str_contains($source['preview'], 'AND created_by = :created_by') && str_contains($source['preview'], "if (\$role !== 'admin') \$params[':created_by'] = \$uid"), 'stored preview preserves non-admin preset ownership');
$check(str_contains($source['helper'], 'getPrevious') === false && str_contains($source['helper'], 'A dependent plugin prevented preset deletion.') && str_contains($source['helper'], 'error_log('), 'pre-delete internals are logged while the public exception text is generic');
$check(str_contains($source['save'], 'admin_shortcode_preset_after_add') && str_contains($source['save'], 'admin_shortcode_preset_after_edit') && str_contains($source['delete'], 'admin_shortcode_preset_after_delete') && str_contains($source['bulk'], 'admin_shortcode_preset_after_delete'), 'successful CRUD and bulk delete paths fire stable admin lifecycle hooks');
$check(str_contains($source['save'], "do_action('admin_shortcode_preset_after_add', \$newId, \$pdo, \$_POST)") && str_contains($source['save'], "do_action('admin_shortcode_preset_after_edit', \$id, \$pdo, \$_POST)"), 'add and edit lifecycle hook arguments match Core admin conventions');
$check(!str_contains(implode("\n", $source), 'shortcode_preset_pre_save_config') && !preg_match('/(?<!admin_)shortcode_preset_after_(?:add|edit|delete)/', implode("\n", $source)), 'superseded candidate hook names are absent');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
