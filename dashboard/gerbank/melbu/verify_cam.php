<?php
// verify_cam.php
require_once __DIR__ . '/../../bootstrap_public.php';   // loads $pdo and helpers
require_once BACKEND_PATH . '/session.php';            // we need session helpers for login_user() (it will not send auth cookie until login_user)
require_once BACKEND_PATH . '/helpers/auth_helpers.php'; 

header('Content-Type: application/json');

if (empty($_FILES['selfie'])) {
    echo json_encode(['ok'=>false,'error'=>'no_file']); exit;
}

$img = $_FILES['selfie'];
if ($img['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'error'=>'upload_error']); exit;
}
// Basic checks: size limit, mime
if ($img['size'] > 2 * 1024 * 1024) {
    echo json_encode(['ok'=>false,'error'=>'file_too_large']); exit;
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($img['tmp_name']);
if (!in_array($mime, ['image/jpeg','image/png'], true)) {
    echo json_encode(['ok'=>false,'error'=>'invalid_type']); exit;
}

// Persist selfie to protected folder for audit/review (NOT in webroot)
$targetDir = dirname(__DIR__, 3) . '/private_uploads/selfies';
@mkdir($targetDir, 0700, true);
$filename = $targetDir . '/' . time() . '_' . bin2hex(random_bytes(6)) . '.jpg';
if (!move_uploaded_file($img['tmp_name'], $filename)) {
    echo json_encode(['ok'=>false,'error'=>'move_failed']); exit;
}

// Mark cam_verified for this IP/email
$email = mb_strtolower($_POST['email'] ?? null);
set_cam_verified($pdo, $email);

// log
@file_put_contents($targetDir . '/upload_log.txt', date('c')." ".get_client_ip()." saved $filename\n", FILE_APPEND);

echo json_encode(['ok'=>true]);
