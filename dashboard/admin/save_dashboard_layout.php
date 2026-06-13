<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid, $role] = adiwira_require_admin($pdo, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    adiwira_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!csrf_check($csrf)) {
    adiwira_json(['ok' => false, 'error' => 'Invalid CSRF token'], 400);
}

$raw = (string)($_POST['layout'] ?? '');
$decoded = json_decode($raw, true);

if (!is_array($decoded)) {
    adiwira_json(['ok' => false, 'error' => 'Invalid layout data'], 400);
}

// Validate each entry
foreach ($decoded as $item) {
    if (!isset($item['w'], $item['col'])) {
        adiwira_json(['ok' => false, 'error' => 'Missing widget key or column'], 400);
    }
    if (!in_array($item['col'], [1, 2], true)) {
        adiwira_json(['ok' => false, 'error' => 'Column must be 1 or 2'], 400);
    }
}

$ok = settings_set($pdo, 'dashboard_widget_layout', json_encode($decoded), 1);
session_write_close();

if ($ok) {
    adiwira_json(['ok' => true]);
} else {
    adiwira_json(['ok' => false, 'error' => 'Failed to save layout'], 500);
}
