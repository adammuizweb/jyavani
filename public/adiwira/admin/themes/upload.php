<?php
// lokasi file /adiwira/admin/themes/upload.php

declare(strict_types=1);
require_once __DIR__ . '/../../../theme_helper.php';
if (session_status()===PHP_SESSION_NONE) session_start();
// cek auth & CSRF (sesuaikan)
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(403);
    exit('Forbidden');
}

$role = $_SESSION['user_role'] ?? '';
if ($role === '' || $role === null) {
    $r = $pdo->prepare("SELECT role FROM users WHERE id=:id AND is_deleted=0 LIMIT 1");
    $r->execute([':id'=>$uid]);
    $role = (string)$r->fetchColumn();
    $_SESSION['user_role'] = $role;
}

$role = strtolower(trim((string)$role));
if ($role !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}
$token = $_POST['csrf_token'] ?? '';
if (!function_exists('csrf_check') || !csrf_check($token)) {
    http_response_code(400); echo 'Invalid CSRF'; exit;
}

if (empty($_FILES['theme_zip']) || $_FILES['theme_zip']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); echo 'No file or upload error'; exit;
}

$file = $_FILES['theme_zip'];
// limits
$maxBytes = 50 * 1024 * 1024; // 50MB
if ($file['size'] > $maxBytes) { http_response_code(400); echo 'File too large'; exit; }

// basic mime check (not security-proof)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if ($mime !== 'application/zip' && $mime !== 'application/x-zip') {
    // allow some servers to return different mimetypes -> still allow .zip ext
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') { http_response_code(400); echo 'Not a zip'; exit; }
}

// prepare temp
$rand = bin2hex(random_bytes(8));
$tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "theme_upload_{$rand}.zip";
if (!move_uploaded_file($file['tmp_name'], $tmpZip)) {
    http_response_code(500); echo 'Failed move'; exit;
}

$zip = new ZipArchive();
if ($zip->open($tmpZip) !== true) { unlink($tmpZip); http_response_code(400); echo 'Invalid zip'; exit; }

// scan entries for path traversal & find top-level folder
$topFolders = [];
for ($i=0;$i<$zip->numFiles;$i++) {
    $name = $zip->getNameIndex($i);
    // disallow absolute paths
    if (preg_match('#^(?:[A-Za-z]:|/|\\\\)#', $name)) {
        $zip->close(); unlink($tmpZip);
        http_response_code(400); echo 'Zip contains absolute paths'; exit;
    }
    // disallow traversal
    if (strpos($name, '..') !== false) {
        $zip->close(); unlink($tmpZip);
        http_response_code(400); echo 'Zip contains invalid paths'; exit;
    }
    $parts = explode('/', rtrim($name, '/'));
    if (count($parts) && $parts[0] !== '') $topFolders[$parts[0]] = true;
}
// decide top-level folder
$topFolders = array_keys($topFolders);
$srcFolderInZip = $topFolders[0] ?? '';
if (!$srcFolderInZip) {
    // zip might contain files at root; we'll extract to a folder named from zip filename
    $srcFolderInZip = pathinfo($file['name'], PATHINFO_FILENAME);
}

// extract to temp dir
$extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "theme_extract_{$rand}";
mkdir($extractDir, 0700, true);
if (!$zip->extractTo($extractDir)) {
    $zip->close(); unlink($tmpZip);
    rrmdir($extractDir);
    http_response_code(500); echo 'Failed extract'; exit;
}
$zip->close();

// determine actual folder path containing theme files
$themeRoot = rtrim($extractDir . DIRECTORY_SEPARATOR . $srcFolderInZip, DIRECTORY_SEPARATOR);
if (!is_dir($themeRoot)) {
    // sometimes zip had files at root -> use extractDir
    $themeRoot = $extractDir;
}

// read manifest
$manifest = [];
$manifestPath = $themeRoot . DIRECTORY_SEPARATOR . 'theme.json';
if (is_file($manifestPath)) {
    $raw = @file_get_contents($manifestPath);
    $j = @json_decode($raw, true);
    if (is_array($j)) $manifest = $j;
}

// derive folder name (sanitize)
$folderCandidate = $manifest['folder'] ?? $manifest['name'] ?? pathinfo($file['name'], PATHINFO_FILENAME);
$folderCandidate = preg_replace('/[^a-z0-9._-]+/i', '-', trim($folderCandidate));
if ($folderCandidate === '') $folderCandidate = 'theme-' . $rand;

// final dest path
$destFs = path_candidate(VIEWS_BASE, $folderCandidate, '');
// if exists, require admin to choose overwrite (for now, return conflict)
if (is_dir($destFs)) {
    // optional: backup & overwrite; for safety we reject
    rrmdir($extractDir);
    unlink($tmpZip);
    http_response_code(409);
    echo 'Theme folder already exists: ' . htmlspecialchars($folderCandidate, ENT_QUOTES);
    exit;
}

// move extracted folder to VIEWS_BASE
// prefer rename if source is same filesystem
if (!@rename($themeRoot, $destFs)) {
    // fallback to recursive copy
    if (!recurse_copy($themeRoot, $destFs)) {
        rrmdir($extractDir);
        unlink($tmpZip);
        http_response_code(500); echo 'Failed move to themes dir'; exit;
    }
}

// register in DB
try {
    $manifestForDb = [
        'name' => $manifest['name'] ?? $folderCandidate,
        'description' => $manifest['description'] ?? '',
        'version' => $manifest['version'] ?? '',
        'author' => $manifest['author'] ?? '',
        'screenshot' => $manifest['screenshot'] ?? null,
    ];
    register_theme_in_db($pdo, $folderCandidate, $manifestForDb, !empty($manifest['is_active']));
} catch (Throwable $e) {
    // rollback: try to remove dest
    rrmdir($destFs);
    rrmdir($extractDir);
    unlink($tmpZip);
    http_response_code(500); echo 'DB register failed: ' . $e->getMessage(); exit;
}

// cleanup
rrmdir($extractDir);
unlink($tmpZip);

echo 'OK: Installed theme ' . htmlspecialchars($folderCandidate, ENT_QUOTES);
exit;

// helpers
function rrmdir($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getRealPath()); else unlink($file->getRealPath());
    }
    @rmdir($dir);
}
function recurse_copy($src, $dst) {
    $dir = opendir($src);
    if (!mkdir($dst, 0755, true) && !is_dir($dst)) return false;
    while(false !== ($file = readdir($dir))) {
        if (($file !== '.') && ($file !== '..')) {
            if (is_dir($src . '/' . $file)) {
                if (!recurse_copy($src . '/' . $file, $dst . '/' . $file)) return false;
            } else {
                if (!copy($src . '/' . $file, $dst . '/' . $file)) return false;
            }
        }
    }
    closedir($dir);
    return true;
}
