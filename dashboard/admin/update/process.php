<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../_guard.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$expectedPath = ADMIN_BASE_PATH . '/admin/update/process.php';
if ($requestPath !== $expectedPath) {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
}

[$uid] = adiwira_require_login($pdo, true);
adiwira_require_site_owner($pdo, true);

$token = (string)($method === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
if (!update_operation_valid_token($token)) {
    adiwira_json(['ok' => false, 'error' => __('Invalid token')], 400);
}

if ($method === 'POST') {
    if ((string)($_POST['action'] ?? '') !== 'cancel') {
        adiwira_json(['ok' => false, 'error' => __('Invalid action')], 400);
    }
    $csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!adiwira_csrf_validate($csrf)) {
        adiwira_json(['ok' => false, 'error' => __('CSRF invalid')], 419);
    }
}

$publicState = static function (?array $record): array {
    if ($record === null) {
        return [
            'found' => false,
            'percentage' => 0,
            'stage' => 'starting',
            'status' => __('Starting...'),
            'done' => false,
            'outcome' => 'running',
            'error' => null,
            'cancel_allowed' => false,
            'cancel_requested' => false,
        ];
    }
    return [
        'found' => true,
        'percentage' => (int)$record['percentage'],
        'stage' => (string)$record['stage'],
        'status' => (string)$record['status'],
        'done' => (bool)$record['done'],
        'outcome' => (string)$record['outcome'],
        'error' => is_string($record['error']) ? $record['error'] : null,
        'cancel_allowed' => (bool)$record['cancel_allowed'],
        'cancel_requested' => (bool)$record['cancel_requested'],
    ];
};

$record = update_operation_read($token);
if ($record === null) {
    if ($method === 'GET') adiwira_json($publicState(null));
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}
if ((int)$record['owner_id'] !== $uid) {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$permission = match ((string)$record['type']) {
    'core' => 'core.updates.manage',
    'plugin' => 'core.plugins.manage',
    'theme' => 'core.themes.manage',
    default => '',
};
if ($permission === '') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}
adiwira_require_permission($pdo, $permission, true);
session_write_close();

if ($method === 'GET') {
    adiwira_json($publicState($record));
}

$result = update_operation_request_cancel($token, $uid);
$current = is_array($result['record'] ?? null) ? $result['record'] : update_operation_read($token);
if (($result['ok'] ?? false) !== true) {
    if (($result['reason'] ?? '') === 'not_allowed' && $current !== null) {
        adiwira_json($publicState($current), 409);
    }
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}
adiwira_json($publicState($current), 202);
