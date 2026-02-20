<?php
// verify_geo.php
require_once __DIR__ . '/../../bootstrap_public.php';   // loads $pdo and helpers
require_once BACKEND_PATH . '/session.php';            // we need session helpers for login_user() (it will not send auth cookie until login_user)
require_once BACKEND_PATH . '/helpers/auth_helpers.php'; 

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['coords'])) {
    echo json_encode(['ok'=>false,'error'=>'invalid_payload']); exit;
}
$coords = $data['coords'];
// basic validation
if (!isset($coords['lat']) || !isset($coords['lon'])) {
    echo json_encode(['ok'=>false,'error'=>'missing_coords']); exit;
}

// Save minimal data or simply mark verified for this IP/email
$email = mb_strtolower($_POST['email'] ?? null); // optional
set_geo_verified($pdo, $email);

// optionally persist coords to server logs for audit (avoid PII leakage)
@file_put_contents(__DIR__ . '/geo_log.log', date('c')." ".get_client_ip()." ".json_encode($coords)."\n", FILE_APPEND);

echo json_encode(['ok'=>true]);
