<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid, $role] = adiwira_require_admin($pdo, true);
session_write_close();

$token = (string)($_GET['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    adiwira_json(['ok' => false, 'error' => 'Invalid token'], 400);
}

require_once __DIR__ . '/../../../app/controllers/ThemeStoreClient.php';

$progress = ThemeStoreClient::readProgress($token);
if ($progress) {
    adiwira_json([
        'percentage' => $progress['percentage'],
        'status' => $progress['status'],
        'done' => (bool)($progress['done'] ?? false),
        'error' => $progress['error'] ?? null,
    ]);
}

adiwira_json([
    'percentage' => 0,
    'status' => __('Starting...'),
    'done' => false,
    'error' => null,
]);
