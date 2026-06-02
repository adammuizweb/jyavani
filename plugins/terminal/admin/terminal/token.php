<?php
// Plugin: Terminal WebSocket Token Endpoint
declare(strict_types=1);

require_once DASH_PATH . '/admin/_deny.php';
require_once DASH_PATH . '/admin/_guard.php';

[$uid, $role] = adiwira_require_admin($pdo, true);

$tokenDir = BACKEND_PATH . '/var/terminal-tokens';
if (!is_dir($tokenDir)) {
    @mkdir($tokenDir, 0750, true);
}

$token = bin2hex(random_bytes(32));
$expiresAt = (time() + 30) * 1000;

$tokenData = json_encode([
    'uid' => $uid,
    'role' => $role,
    'createdAt' => time() * 1000,
    'expiresAt' => $expiresAt,
]);

$tokenPath = $tokenDir . '/' . $token;
$written = file_put_contents($tokenPath, $tokenData, LOCK_EX);

if ($written === false) {
    adiwira_json(['error' => 'Gagal menulis token'], 500);
    exit;
}

adiwira_json([
    'token' => $token,
    'expiresIn' => 30,
]);
