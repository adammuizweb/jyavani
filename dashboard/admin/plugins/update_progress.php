<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid] = adiwira_require_permission($pdo, 'core.plugins.manage', true);
adiwira_require_site_owner($pdo, true);
session_write_close();

$token = (string)($_GET['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    adiwira_json(['ok' => false, 'error' => 'Invalid token'], 400);
}

require_once __DIR__ . '/../../../app/controllers/PluginStoreController.php';

$record = update_operation_read($token);
if ($record !== null && ((int)$record['owner_id'] !== (int)$uid || (string)$record['type'] !== 'plugin')) {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}
$progress = PluginStoreController::readProgress($token);
if ($progress) {
    adiwira_json([
        'percentage' => $progress['percentage'],
        'status' => $progress['status'],
        'done' => (bool)($progress['done'] ?? false),
        'error' => $progress['error'] ?? null,
        'stage' => $progress['stage'] ?? 'working',
        'outcome' => $progress['outcome'] ?? 'running',
        'cancel_allowed' => (bool)($progress['cancel_allowed'] ?? false),
        'cancel_requested' => (bool)($progress['cancel_requested'] ?? false),
    ]);
}

adiwira_json([
    'percentage' => 0,
    'status' => __('Starting...'),
    'done' => false,
    'error' => null,
    'stage' => 'starting',
    'outcome' => 'running',
    'cancel_allowed' => false,
    'cancel_requested' => false,
]);
