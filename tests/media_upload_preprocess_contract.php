<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/resource_lifecycle.php';
require_once $root . '/cfg/helpers/media_helpers.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$throws = static function (callable $callback, string $class = Throwable::class): ?Throwable {
    try {
        $callback();
    } catch (Throwable $error) {
        return $error instanceof $class ? $error : null;
    }
    return null;
};

$fixtureRoot = sys_get_temp_dir() . '/jy-media-upload-contract-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot, 0700, true);
$workspace = media_upload_private_workspace($fixtureRoot);
register_shutdown_function(static function () use (&$workspace, $fixtureRoot): void {
    media_upload_remove_workspace($workspace);
    @rmdir($fixtureRoot);
});

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EF//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EF//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EF//2Q==', true);
$writePrivate = static function (string $path, string $bytes): void {
    file_put_contents($path, $bytes);
    chmod($path, 0600);
};

$stage = $workspace . '/input';
$writePrivate($stage, $png);
$descriptor = media_upload_descriptor($stage, '../camera.PNG');
$check($descriptor === [
    'schema' => 1,
    'original_name' => 'camera.PNG',
    'mime' => 'image/png',
    'ext' => 'png',
    'size' => strlen($png),
    'width' => 1,
    'height' => 1,
    'sha256' => hash('sha256', $png),
], 'inspection derives a canonical schema-1 descriptor from actual staged bytes');

$empty = $workspace . '/empty';
$writePrivate($empty, '');
$invalid = $workspace . '/invalid';
$writePrivate($invalid, 'not an image');
$oversized = $throws(static fn() => media_upload_inspect_image($stage, strlen($png) - 1), MediaUploadRejected::class);
$check($throws(static fn() => media_upload_inspect_image($empty), MediaUploadRejected::class) !== null
    && $throws(static fn() => media_upload_inspect_image($invalid), MediaUploadRejected::class) !== null
    && $oversized instanceof MediaUploadRejected && $oversized->status() === 413,
    'inspection rejects empty, non-image, and actually oversized files');

$twoPixelPng = substr_replace(substr_replace($png, pack('N', 2), 16, 4), pack('N', 2), 20, 4);
$pixels = $workspace . '/pixels';
$writePrivate($pixels, $twoPixelPng);
$check($throws(static fn() => media_upload_inspect_image($pixels, 20971520, 3), MediaUploadRejected::class) !== null,
    'inspection enforces the decoded pixel bound');

$symlink = $workspace . '/symlink';
$symlinkRejected = function_exists('symlink') && @symlink($stage, $symlink)
    ? $throws(static fn() => media_upload_inspect_image($symlink), RuntimeException::class) !== null
    : true;
$hardlink = $workspace . '/hardlink';
$hardlinkRejected = function_exists('link') && @link($stage, $hardlink)
    ? $throws(static fn() => media_upload_inspect_image($stage), RuntimeException::class) !== null
    : true;
@unlink($symlink);
@unlink($hardlink);
$check($symlinkRejected && $hardlinkRejected, 'inspection rejects symlink and multi-link upload artifacts');

$writePrivate($stage, $png);
$rewritten = media_upload_atomic_rewrite($stage, static function (string $input, string $output) use ($jpeg): void {
    file_put_contents($output, $jpeg);
});
$check(file_get_contents($stage) === $jpeg
    && $rewritten['mime'] === 'image/jpeg' && $rewritten['ext'] === 'jpg'
    && $rewritten['size'] === strlen($jpeg) && $rewritten['width'] === 1 && $rewritten['height'] === 1
    && $rewritten['sha256'] === hash('sha256', $jpeg),
    'atomic rewrite publishes valid transformed bytes and recomputes canonical format metadata');

$writePrivate($stage, $png);
$beforeNoop = file_get_contents($stage);
$noop = media_upload_atomic_rewrite($stage, static function (string $input, string $output) use ($jpeg): bool {
    file_put_contents($output, $jpeg);
    return false;
});
$check(file_get_contents($stage) === $beforeNoop && $noop['sha256'] === hash('sha256', $png),
    'a false writer result retains the original stage and removes its unused output');

$writePrivate($stage, $png);
$marker = new RuntimeException('processor failed');
$caught = $throws(static function () use ($stage, $marker): void {
    media_upload_atomic_rewrite($stage, static function () use ($marker): void {
        throw $marker;
    });
});
$check($caught === $marker && file_get_contents($stage) === $png,
    'writer exceptions propagate while preserving the original stage');

$writePrivate($stage, $png);
$invalidOutput = $throws(static function () use ($stage): void {
    media_upload_atomic_rewrite($stage, static function (string $input, string $output): void {
        file_put_contents($output, 'invalid replacement');
    });
}, UnexpectedValueException::class);
$check($invalidOutput !== null && file_get_contents($stage) === $png,
    'invalid writer output is rejected without replacing the original stage');

$writePrivate($stage, $png);
$changedInput = $throws(static function () use ($stage, $jpeg): void {
    media_upload_atomic_rewrite($stage, static function (string $input, string $output) use ($jpeg): void {
        file_put_contents($input, 'changed');
        file_put_contents($output, $jpeg);
    });
}, RuntimeException::class);
$check($changedInput !== null && file_get_contents($stage) === $png,
    'atomic rewrite detects input mutation and restores the original bytes');

$context = media_extension_context(['surface' => 'admin.media.upload', 'selection_mode' => 'review']);
$extensionInput = ['example.extension' => ['mode' => 'strip']];
$descriptor = media_upload_descriptor($stage, 'camera.png');
$calls = [];
$reject = static function (string $path, array $seenDescriptor, array $seenContext, array $seenInput) use (&$calls): void {
    $calls[] = [basename($path), array_keys($seenDescriptor), $seenContext['schema'], $seenInput];
    throw new MediaUploadRejected('Policy rejected this image.', 409);
};
$shouldNotRun = static function () use (&$calls): void {
    $calls[] = 'late';
};
add_action('media_upload_preprocess', $reject, 10);
add_action('media_upload_preprocess', $shouldNotRun, 20);
$hookError = $throws(static fn() => media_upload_preprocess($stage, $descriptor, $context, $extensionInput), MediaUploadRejected::class);
$check($hookError instanceof MediaUploadRejected && $hookError->getMessage() === 'Policy rejected this image.'
    && $hookError->status() === 409 && count($calls) === 1
    && $calls[0][1] === ['schema', 'original_name', 'mime', 'ext', 'size', 'width', 'height', 'sha256']
    && $calls[0][2] === 2 && $calls[0][3] === $extensionInput,
    'preprocessing is fail-fast and exposes only the documented path, descriptor, context, and extension input');
remove_action('media_upload_preprocess', $reject, 10);
remove_action('media_upload_preprocess', $shouldNotRun, 20);

$bounded = $throws(static fn() => new MediaUploadRejected(str_repeat('x', 501), 422), InvalidArgumentException::class);
$badStatus = $throws(static fn() => new MediaUploadRejected('unsafe status', 500), InvalidArgumentException::class);
$check($bounded !== null && $badStatus !== null, 'typed client-safe rejections enforce message and HTTP status bounds');

$uploadSource = (string)file_get_contents($root . '/dashboard/admin/upload_image.php');
$authorization = strpos($uploadSource, "user_can(\$pdo, \$uid, 'core.media.upload')");
$csrfCheck = strpos($uploadSource, 'adiwira_csrf_validate');
$uploadedCheck = strpos($uploadSource, 'is_uploaded_file');
$workspaceCreate = strpos($uploadSource, 'media_upload_private_workspace');
$uploadMove = strpos($uploadSource, 'move_uploaded_file');
$preprocess = strpos($uploadSource, 'media_upload_preprocess($stagePath');
$finalInspection = strpos($uploadSource, '$finalImage = media_upload_inspect_image($stagePath)');
$finalHash = strpos($uploadSource, '$contentHash = $finalImage[\'sha256\']');
$hashLock = strpos($uploadSource, "theme_operation_acquire(['media-content-' . \$contentHash]");
$duplicate = strpos($uploadSource, 'media_find_authorized_duplicate');
$filename = strpos($uploadSource, '$filename = $slug');
$publicationStage = strpos($uploadSource, "'media-publication-stage'");
$publication = strpos($uploadSource, 'asset_lifecycle_rename($publicationStage, $target_path)');
$check($authorization !== false && $csrfCheck !== false && $uploadedCheck !== false && $workspaceCreate !== false
    && $uploadMove !== false && $preprocess !== false && $finalInspection !== false && $finalHash !== false
    && $hashLock !== false && $duplicate !== false && $filename !== false && $publicationStage !== false && $publication !== false
    && $authorization < $workspaceCreate && $csrfCheck < $workspaceCreate && $uploadedCheck < $workspaceCreate
    && $workspaceCreate < $uploadMove && $uploadMove < $preprocess
    && $preprocess < $finalInspection && $finalInspection < $finalHash && $finalHash < $hashLock
    && $hashLock < $duplicate && $duplicate < $filename && $filename < $publicationStage && $publicationStage < $publication,
    'source order puts private staging and preprocessing before final identity, deduplication, naming, and publication');
$check(str_contains($uploadSource, '@chmod($stagePath, 0600)')
    && str_contains($uploadSource, 'media_upload_copy_private(')
    && str_contains($uploadSource, 'media_upload_inspect_image($publicationStage) !== $finalImage')
    && !str_contains($uploadSource, "['size'] ?? 0) >"),
    'upload uses private verified stages and never treats client-reported size as authoritative');

foreach (scandir($workspace) ?: [] as $entry) {
    if ($entry !== '.' && $entry !== '..') @unlink($workspace . '/' . $entry);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " media upload preprocessing contract check(s) failed.\n");
    exit(1);
}
echo "Media upload preprocessing contract passed ({$checks} checks).\n";
