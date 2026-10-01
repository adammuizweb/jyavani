<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid] = adiwira_require_permission($pdo, 'core.themes.manage', false);
adiwira_require_site_owner($pdo, false);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_render_404();
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) adiwira_render_404();

$folder = (string)($_POST['folder'] ?? '');
$service = theme_source_service($pdo);
$export = null;
$handle = null;
try {
    $export = $service->exportPhpSource($folder, (int)$uid);
    $path = (string)$export['path'];
    $expectedDirectory = realpath(rtrim((string)BACKEND_PATH, '/\\') . '/var/theme-source/exports');
    $real = realpath($path);
    $before = @lstat($path);
    $handle = @fopen($path, 'rb');
    $stat = is_resource($handle) ? fstat($handle) : false;
    clearstatcache(true, $path);
    $after = @lstat($path);
    if ($expectedDirectory === false || $real === false || dirname($real) !== $expectedDirectory
        || !is_array($before) || !is_resource($handle) || !is_array($stat) || !is_array($after) || is_link($path)
        || (($stat['mode'] & 0170000) !== 0100000) || (($stat['mode'] & 0777) !== 0600) || (int)$stat['nlink'] !== 1
        || (int)$stat['size'] !== (int)$export['size'] || (int)$stat['dev'] !== (int)$export['dev'] || (int)$stat['ino'] !== (int)$export['ino']
        || (int)$before['dev'] !== (int)$stat['dev'] || (int)$before['ino'] !== (int)$stat['ino']
        || (int)$after['dev'] !== (int)$stat['dev'] || (int)$after['ino'] !== (int)$stat['ino']) {
        throw new RuntimeException(__('Protected source export could not be opened safely.'));
    }
    $hash = hash_init('sha256');
    $bytes = 0;
    while (!feof($handle)) {
        $chunk = fread($handle, 65536);
        if ($chunk === false) throw new RuntimeException(__('Protected source export could not be opened safely.'));
        $bytes += strlen($chunk);
        if ($bytes > (int)$export['size']) throw new RuntimeException(__('Protected source export could not be opened safely.'));
        hash_update($hash, $chunk);
    }
    $hashedStat = fstat($handle);
    if ($bytes !== (int)$export['size'] || !hash_equals((string)$export['sha256'], hash_final($hash))
        || !is_array($hashedStat) || (int)$hashedStat['dev'] !== (int)$stat['dev'] || (int)$hashedStat['ino'] !== (int)$stat['ino']
        || (int)$hashedStat['size'] !== (int)$stat['size'] || fseek($handle, 0) !== 0) {
        throw new RuntimeException(__('Protected source export could not be opened safely.'));
    }
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addslashes((string)$export['download_name']) . '"');
    header('Content-Length: ' . (int)$export['size']);
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $streamed = fpassthru($handle);
    if (!is_int($streamed) || $streamed !== (int)$export['size']) error_log('[theme-source-export] Verified export streaming failed.');
} catch (Throwable $error) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo __('Theme source export failed safely.');
} finally {
    if (is_resource($handle)) fclose($handle);
    if (is_array($export) && is_string($export['path'] ?? null) && !$service->removeExport($export['path'])) {
        error_log('[theme-source-export] Verified export cleanup failed.');
    }
}
exit;
