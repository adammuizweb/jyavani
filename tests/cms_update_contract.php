<?php
declare(strict_types=1);

$sourceRoot = dirname(__DIR__);
$fixtureBase = sys_get_temp_dir() . '/jyavani-update-contract-' . getmypid() . '-' . bin2hex(random_bytes(4));
$projectRoot = $fixtureBase . '/app';
$publicRoot = $fixtureBase . '/public_html';

function __(string $message, mixed ...$values): string {
    return $values === [] ? $message : sprintf($message, ...$values);
}

function contract_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        contract_remove_tree($path . '/' . $entry);
    }
    @rmdir($path);
}

function contract_write(string $path, string $contents): void {
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException('Cannot create fixture directory: ' . $parent);
    }
    if (file_put_contents($path, $contents) === false) throw new RuntimeException('Cannot write fixture: ' . $path);
}

function contract_manifest(string $version, array $entries, array $extra = []): array {
    $files = [];
    foreach ($entries as $path => $contents) $files[$path] = hash('sha256', $contents);
    return array_merge([
        'name' => 'Jyavani CMS Contract',
        'version' => $version,
        'build' => '2026-08-10',
        'total_files' => count($files),
        'files' => $files,
    ], $extra);
}

function contract_zip(string $path, array $entries): void {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create fixture ZIP.');
    }
    foreach ($entries as $entry) {
        $name = (string)$entry['name'];
        $zip->addFromString($name, (string)$entry['contents']);
        if (!empty($entry['symlink'])) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
    }
    if (!$zip->close()) throw new RuntimeException('Cannot close fixture ZIP.');
}

function contract_entries(array $files): array {
    $entries = [];
    foreach ($files as $name => $contents) $entries[] = ['name' => $name, 'contents' => $contents];
    return $entries;
}

mkdir($projectRoot . '/dashboard', 0775, true);
mkdir($projectRoot . '/cfg/var', 0775, true);
mkdir($projectRoot . '/tools', 0775, true);
mkdir($publicRoot, 0775, true);
define('DASH_PATH', $projectRoot . '/dashboard');
define('PUBLIC_PATH', $publicRoot);
require_once $sourceRoot . '/dashboard/admin/update/_update_helpers.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$updatePageSource = (string)file_get_contents($sourceRoot . '/dashboard/admin/update/index.php');
$updateActionsSource = (string)file_get_contents($sourceRoot . '/dashboard/admin/update/_update_actions.php');
$updateScriptSource = (string)file_get_contents($sourceRoot . '/public/static/dashboard/js/update.js');
$check(
    str_contains($updatePageSource, "require_once __DIR__ . '/_update_actions.php';")
        && str_contains($updatePageSource, 'cms_update_handle_post('),
    'update page delegates request mutations to the action module'
);
$check(
    str_contains($updateActionsSource, 'function cms_update_check_remote(')
        && str_contains($updateActionsSource, 'function cms_update_store_upload(')
        && str_contains($updateActionsSource, 'function cms_update_reinstall('),
    'update actions remain separated by use case'
);
$check(
    !str_contains($updatePageSource, '<style>')
        && str_contains($updatePageSource, '/static/dashboard/css/update.css'),
    'update page styling is served from its static stylesheet'
);
$check(
    !str_contains($updateScriptSource, '<?')
        && str_contains($updatePageSource, 'window.CMS_UPDATE_CONFIG')
        && str_contains($updatePageSource, '/static/dashboard/js/update.js'),
    'update behavior uses a static script with explicit server configuration'
);
$check(
    strpos($updatePageSource, 'cms_update_handle_progress_request();')
        < strpos($updatePageSource, 'adiwira_require_site_owner($pdo, false);'),
    'update progress remains readable during the first Site Owner migration'
);
$check(
    str_contains($updatePageSource, "'successUrl' => \$base . '/?cms_update_ok=1'"),
    'successful updates return to the authorized dashboard entry point'
);

try {
    $oldFiles = [
        'public/assets/changed.txt' => "old changed\n",
        'public/assets/obsolete.txt' => "old obsolete\n",
        'public/assets/unchanged.txt' => "same\n",
        'app/core.php' => "old core\n",
        'version.json' => "{\"version\":\"1.0.0\"}\n",
        'public/views/themes/default/canary.txt' => "old default theme\n",
        'public/views/themes/adam/canary.txt' => "adam Store theme\n",
        'public/views/themes/custom/canary.txt' => "theme canary\n",
        'public/static/plugins/custom/canary.txt' => "static plugin canary\n",
        'plugins/custom/canary.txt' => "plugin canary\n",
        'cfg/var/runtime-canary.txt' => "runtime canary\n",
    ];
    foreach ($oldFiles as $logical => $contents) {
        $target = str_starts_with($logical, 'public/')
            ? $publicRoot . '/' . substr($logical, 7)
            : $projectRoot . '/' . $logical;
        contract_write($target, $contents);
    }
    $localManifest = contract_manifest('1.0.0', $oldFiles);
    contract_write($projectRoot . '/tools/cms-manifest.json', json_encode($localManifest, JSON_THROW_ON_ERROR));

    $remoteFiles = [
        'public/assets/changed.txt' => "new changed\n",
        'public/assets/new.txt' => "new file\n",
        'public/assets/unchanged.txt' => "same\n",
        'app/core.php' => "new core\n",
        'version.json' => "{\"version\":\"1.0.0\"}\n",
        'public/views/themes/default/canary.txt' => "new default theme\n",
        'public/views/themes/custom/canary.txt' => "replace theme\n",
        'public/static/plugins/custom/canary.txt' => "replace static plugin\n",
        'plugins/custom/canary.txt' => "replace plugin\n",
        'cfg/var/runtime-canary.txt' => "replace runtime\n",
    ];
    $remoteManifest = contract_manifest('1.0.0', $remoteFiles);
    $successZip = $fixtureBase . '/success.zip';
    contract_zip($successZip, contract_entries($remoteFiles));
    $result = _apply_cms_update_from_zip($successZip, $remoteManifest, '1.0.0');

    $check($result['success'] === true, 'same-version update applies successfully');
    $check(file_get_contents($publicRoot . '/assets/changed.txt') === "new changed\n", 'changed public file maps to public_html');
    $check(file_get_contents($publicRoot . '/assets/new.txt') === "new file\n", 'new public file maps to public_html');
    $check(!file_exists($publicRoot . '/assets/obsolete.txt'), 'obsolete mapped public file is deleted');
    $check(file_get_contents($projectRoot . '/app/core.php') === "new core\n", 'non-public file remains rooted in the project');
    $check(!file_exists($projectRoot . '/public/assets/changed.txt'), 'mapped files are not written to the source public directory');
    $check(file_get_contents($publicRoot . '/views/themes/default/canary.txt') === "new default theme\n", 'default system theme is updated by Core');
    $check(file_get_contents($publicRoot . '/views/themes/adam/canary.txt') === "adam Store theme\n", 'adam Store theme from an older Core manifest is preserved when absent from the new manifest');
    $check(file_get_contents($publicRoot . '/views/themes/custom/canary.txt') === "theme canary\n", 'theme canary is preserved by logical path');
    $check(file_get_contents($publicRoot . '/static/plugins/custom/canary.txt') === "static plugin canary\n", 'plugin static canary is preserved by logical path');
    $check(file_get_contents($projectRoot . '/plugins/custom/canary.txt') === "plugin canary\n", 'plugin canary is preserved');
    $check(file_get_contents($projectRoot . '/cfg/var/runtime-canary.txt') === "runtime canary\n", 'runtime canary is preserved');

    $backupDirs = glob($projectRoot . '/cfg/var/backup-*') ?: [];
    sort($backupDirs);
    $successBackup = end($backupDirs);
    $check(is_string($successBackup) && is_file($successBackup . '/public/assets/changed.txt'), 'backup uses canonical public/assets path');
    $check(is_string($successBackup) && is_file($successBackup . '/public/assets/obsolete.txt'), 'obsolete mapped file has a canonical backup');
    $check(is_string($successBackup) && is_file($successBackup . '/tools/cms-manifest.json'), 'previous manifest is tracked in the canonical backup');
    $check(is_string($successBackup) && !file_exists($successBackup . '/public_html'), 'backup never records the physical mapped root name');
    $installedManifest = _cms_decode_json_array((string)file_get_contents($projectRoot . '/tools/cms-manifest.json'), 'installed manifest');
    $check($installedManifest === $remoteManifest, 'verified remote logical manifest is installed verbatim');

    $rollbackOld = [
        'public/rollback/changed.txt' => "rollback original\n",
        'public/rollback/obsolete.txt' => "rollback obsolete\n",
        'version.json' => "{\"version\":\"1.0.0\"}\n",
    ];
    contract_write($publicRoot . '/rollback/changed.txt', $rollbackOld['public/rollback/changed.txt']);
    contract_write($publicRoot . '/rollback/obsolete.txt', $rollbackOld['public/rollback/obsolete.txt']);
    $rollbackLocalManifest = contract_manifest('1.0.0', $rollbackOld);
    $rollbackManifestJson = json_encode($rollbackLocalManifest, JSON_THROW_ON_ERROR);
    contract_write($projectRoot . '/tools/cms-manifest.json', $rollbackManifestJson);
    $rollbackRemoteFiles = [
        'public/rollback/changed.txt' => "rollback mutated\n",
        'public/rollback/created.txt' => "rollback created\n",
        'version.json' => "{\"version\":\"1.0.0\"}\n",
    ];
    $invalidValue = fopen('php://memory', 'rb');
    $rollbackRemoteManifest = contract_manifest('1.0.0', $rollbackRemoteFiles, ['invalid_metadata' => $invalidValue]);
    $rollbackZip = $fixtureBase . '/rollback.zip';
    contract_zip($rollbackZip, contract_entries($rollbackRemoteFiles));
    $rollbackResult = _apply_cms_update_from_zip($rollbackZip, $rollbackRemoteManifest, '1.0.0');
    fclose($invalidValue);
    $check($rollbackResult['success'] === false, 'late manifest failure reports update failure');
    $check(file_get_contents($publicRoot . '/rollback/changed.txt') === "rollback original\n", 'rollback restores a changed mapped file');
    $check(!file_exists($publicRoot . '/rollback/created.txt'), 'rollback removes a newly created mapped file');
    $check(file_get_contents($publicRoot . '/rollback/obsolete.txt') === "rollback obsolete\n", 'rollback restores a deleted mapped file');
    $check(file_get_contents($projectRoot . '/tools/cms-manifest.json') === $rollbackManifestJson, 'failed apply retains the previous local manifest');

    $refusalBase = ['version.json' => "{\"version\":\"1.0.0\"}\n"];
    contract_write($projectRoot . '/tools/cms-manifest.json', json_encode(contract_manifest('1.0.0', $refusalBase), JSON_THROW_ON_ERROR));

    $traversalFiles = ['public/../escape.txt' => "escape\n"];
    $traversalZip = $fixtureBase . '/traversal.zip';
    contract_zip($traversalZip, contract_entries($traversalFiles));
    $check(_apply_cms_update_from_zip($traversalZip, contract_manifest('1.0.0', $traversalFiles), '1.0.0')['success'] === false, 'manifest traversal is refused');
    $check(!file_exists($fixtureBase . '/escape.txt'), 'traversal refusal does not write outside either root');

    $hashFiles = ['public/refusal/hash.txt' => "actual\n"];
    $hashManifest = contract_manifest('1.0.0', $hashFiles);
    $hashManifest['files']['public/refusal/hash.txt'] = hash('sha256', "different\n");
    $hashZip = $fixtureBase . '/hash.zip';
    contract_zip($hashZip, contract_entries($hashFiles));
    $check(_apply_cms_update_from_zip($hashZip, $hashManifest, '1.0.0')['success'] === false, 'package hash mismatch is refused');
    $check(!file_exists($publicRoot . '/refusal/hash.txt'), 'hash refusal occurs before mutation');

    $invalidVersionFiles = ['version.json' => "{not-json}\n"];
    $invalidVersionZip = $fixtureBase . '/invalid-version.zip';
    contract_zip($invalidVersionZip, contract_entries($invalidVersionFiles));
    $check(_apply_cms_update_from_zip($invalidVersionZip, contract_manifest('1.0.0', $invalidVersionFiles), '1.0.0')['success'] === false, 'malformed package version JSON is refused clearly');

    $duplicateZip = $fixtureBase . '/duplicate.zip';
    contract_zip($duplicateZip, [
        ['name' => 'public/dup-a.txt', 'contents' => "first\n"],
        ['name' => 'public/dup-b.txt', 'contents' => "second\n"],
    ]);
    $duplicateBytes = file_get_contents($duplicateZip);
    contract_write($duplicateZip, str_replace('public/dup-b.txt', 'public/dup-a.txt', (string)$duplicateBytes));
    $duplicateManifest = contract_manifest('1.0.0', ['public/dup-a.txt' => "first\n"]);
    $check(_apply_cms_update_from_zip($duplicateZip, $duplicateManifest, '1.0.0')['success'] === false, 'duplicate archive path is refused');

    $outside = $fixtureBase . '/outside';
    mkdir($outside, 0775, true);
    contract_write($outside . '/canary.txt', "outside\n");
    symlink($outside, $publicRoot . '/linked');
    $linkedFiles = ['public/linked/canary.txt' => "escaped\n"];
    $linkedZip = $fixtureBase . '/linked.zip';
    contract_zip($linkedZip, contract_entries($linkedFiles));
    $check(_apply_cms_update_from_zip($linkedZip, contract_manifest('1.0.0', $linkedFiles), '1.0.0')['success'] === false, 'physical target symlink escape is refused');
    $check(file_get_contents($outside . '/canary.txt') === "outside\n", 'symlink refusal leaves the outside target unchanged');

    $archiveSymlinkZip = $fixtureBase . '/archive-symlink.zip';
    contract_zip($archiveSymlinkZip, [[
        'name' => 'public/archive-link.txt',
        'contents' => 'outside-target',
        'symlink' => true,
    ]]);
    $archiveSymlinkManifest = contract_manifest('1.0.0', ['public/archive-link.txt' => 'outside-target']);
    $check(_apply_cms_update_from_zip($archiveSymlinkZip, $archiveSymlinkManifest, '1.0.0')['success'] === false, 'archive symlink entry is refused');

    $generatorRoot = $fixtureBase . '/generator-app';
    contract_write($generatorRoot . '/tools/generate-manifest.php', (string)file_get_contents($sourceRoot . '/tools/generate-manifest.php'));
    contract_write($generatorRoot . '/version.json', json_encode(['name' => 'Fixture', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
    contract_write($generatorRoot . '/cfg/env.php', "<?php\nfunction fixture_env(): void {}\n");
    contract_write($generatorRoot . '/plugins/example/plugin.json', json_encode([
        'name' => 'example',
        'static' => ['copy' => [['from' => 'assets/example.js', 'to' => 'static/plugins/example/example.js']]],
    ], JSON_THROW_ON_ERROR));
    contract_write($generatorRoot . '/plugins/example/assets/example.js', "plugin asset\n");
    mkdir($generatorRoot . '/public', 0775, true);
    $generatorOutput = [];
    $generatorStatus = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($generatorRoot . '/tools/generate-manifest.php') . ' 2>&1', $generatorOutput, $generatorStatus);
    $generatedManifest = _cms_decode_json_array((string)file_get_contents($generatorRoot . '/tools/cms-manifest.json'), 'generated fixture manifest');
    $check($generatorStatus === 0 && isset($generatedManifest['files']['cfg/env.php']), 'source manifest tracks Core-managed cfg/env.php');
    $check(!file_exists($generatorRoot . '/public/static/plugins/example/example.js'), 'manifest generation has no plugin-static mutation side effect');
} finally {
    contract_remove_tree($fixtureBase);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
