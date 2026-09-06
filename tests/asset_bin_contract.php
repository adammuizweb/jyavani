<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$sources = [
    'helper' => $read('cfg/helpers/asset_lifecycle.php'),
    'migration' => $read('schema/migrations/022-media-file-trash.sql'),
    'schema' => $read('schema/default.sql'),
    'undo' => $read('dashboard/admin/assets/undo_trash.php'),
    'bin_index' => $read('dashboard/admin/bin/_asset_index.php'),
    'bin_action' => $read('dashboard/admin/bin/_asset_action.php'),
    'hub' => $read('dashboard/admin/bin/index.php'),
    'media_delete' => $read('dashboard/admin/media/delete.php'),
    'file_delete' => $read('dashboard/admin/file/delete.php'),
    'media_ui' => $read('dashboard/admin/media/index.php'),
    'file_ui' => $read('dashboard/admin/file/index.php'),
    'upload_media' => $read('dashboard/admin/upload_image.php'),
    'upload_file' => $read('dashboard/admin/upload_file.php'),
];

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

foreach (['media', 'file'] as $resource) {
    $check(str_contains($sources['migration'], "ALTER TABLE `{$resource}` ADD COLUMN `is_deleted`")
        && str_contains($sources['migration'], "`idx_{$resource}_storage_identity` (`storage_disk`,`storage_path`(180))")
        && str_contains($sources['schema'], "`idx_{$resource}_storage_identity` (`storage_disk`,`storage_path`(180))"),
        $resource . ' lifecycle schema and storage identity index are installed');
    $check(is_file($root . '/dashboard/admin/bin/' . $resource . '/index.php')
        && is_file($root . '/dashboard/admin/bin/' . $resource . '/restore.php')
        && is_file($root . '/dashboard/admin/bin/' . $resource . '/delete_permanent.php')
        && is_file($root . '/dashboard/admin/bin/' . $resource . '/bulk_action.php'),
        $resource . ' Bin exposes list, restore, purge, and bulk routes');
}

$check(str_contains($sources['helper'], 'function asset_lifecycle_trash')
    && str_contains($sources['helper'], 'function asset_lifecycle_restore')
    && str_contains($sources['helper'], 'function asset_lifecycle_purge'),
    'one lifecycle service owns trash, restore, and purge');
$check(str_contains($sources['helper'], 'Managed storage is shared by multiple asset records.')
    && str_contains($sources['helper'], 'throw new AssetLifecycleConflict'),
    'duplicate storage identities fail closed');
$check(str_contains($sources['helper'], "parse_url(\$url, PHP_URL_PATH) !== \$url")
    && str_contains($sources['helper'], "storage_disk = 'public' AND storage_path = :path")
    && str_contains($sources['helper'], "\$_SESSION['adiwira_asset_cleanup']")
    && str_contains($sources['upload_media'], 'asset_lifecycle_temporary_issue')
    && str_contains($sources['upload_file'], 'asset_lifecycle_temporary_issue'),
    'temporary cleanup requires an exact actor-bound upload grant');
$check(str_contains($sources['helper'], "preg_match('/^[\\p{L}\\p{N}]")
    && str_contains($sources['migration'], "SET `storage_disk` = 'public'")
    && substr_count($sources['migration'], 'REGEXP') === 2,
    'Unicode upload paths are valid and conventional legacy local paths are backfilled');
$check(str_contains($sources['helper'], "asset_lifecycle_local_url_path(\$resource, \$row['url'] ?? null)")
    && substr_count($sources['helper'], "Local asset storage metadata is missing or invalid.") === 2,
    'local-looking assets fail closed when managed storage metadata is unusable');
$check(str_contains($sources['helper'], "dirname(__DIR__, 2) . '/private_files/.asset-trash'")
    && str_contains($sources['helper'], 'asset_lifecycle_rename')
    && str_contains($sources['helper'], "['dev']"),
    'quarantine is non-public and requires same-filesystem atomic renames');
$check(str_contains($sources['helper'], 'DELETE FROM post_media_items WHERE media_id = :id')
    && !str_contains($sources['media_delete'], 'DELETE FROM post_media_items'),
    'media relationships survive trash and are removed only by purge');
$check(str_contains($sources['undo'], 'asset_lifecycle_restore')
    && str_contains($sources['undo'], 'AssetLifecycleConflict|AssetLifecycleAccessDenied|InvalidArgumentException')
    && substr_count($sources['undo'], 'adiwira_undo_consume($undoToken);') === 3
    && str_contains($sources['undo'], "adiwira_json(['ok' => false, 'error' => __('Failed to restore asset.')], 500);"),
    'Undo consumes invalid/conflicting grants but retains retryable operational failures');
$check(str_contains($sources['bin_index'], 'authorization_owner_scope_condition')
    && str_contains($sources['bin_action'], 'asset_lifecycle_restore')
    && str_contains($sources['bin_action'], 'asset_lifecycle_purge'),
    'asset Bin listing and mutations enforce scoped lifecycle permissions');
$check(str_contains($sources['hub'], "'key' => 'media'") && str_contains($sources['hub'], "'key' => 'file'"),
    'Bin hub includes Media and File');
$check(str_contains($sources['media_ui'], 'action: action || null')
    && str_contains($sources['media_ui'], 'j.action')
    && str_contains($sources['file_ui'], 'action: action || null')
    && substr_count($sources['file_ui'], 'j.action') >= 2,
    'Media and File delete Toasts forward Undo actions');
$check(str_contains($sources['media_delete'], "asset_lifecycle_trash(\$pdo, 'media'")
    && str_contains($sources['file_delete'], "asset_lifecycle_trash(\$pdo, 'file'"),
    'saved single deletes use soft-delete lifecycle operations');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Asset Bin contract check(s) failed.\n");
    exit(1);
}
echo "Asset Bin contract passed ({$checks} checks).\n";
