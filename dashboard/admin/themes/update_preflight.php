<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid, $role] = adiwira_require_permission($pdo, 'core.themes.manage', true);
adiwira_require_site_owner($pdo, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    adiwira_json(['ok' => false, 'allowed' => false, 'issues' => [], 'error' => __('Method not allowed')], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'allowed' => false, 'issues' => [], 'error' => __('CSRF invalid')], 419);
}

$folder = (string)($_POST['theme'] ?? '');
if ($folder === '' || strlen($folder) > 128
    || preg_match('/\A[a-zA-Z0-9_-][a-zA-Z0-9._-]*\z/', $folder) !== 1
    || in_array($folder, ['.', '..'], true)) {
    adiwira_json(['ok' => false, 'allowed' => false, 'issues' => [], 'error' => __('Invalid theme name.')], 400);
}

$decisionsJson = (string)($_POST['decisions'] ?? '{}');
$decisionsTrimmed = trim($decisionsJson);
if (strlen($decisionsJson) > 16384 || $decisionsTrimmed === ''
    || $decisionsTrimmed[0] !== '{' || !str_ends_with($decisionsTrimmed, '}')) {
    adiwira_json(['ok' => false, 'allowed' => false, 'issues' => [], 'error' => __('Invalid update decisions.')], 400);
}
try {
    $decisions = json_decode($decisionsJson, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    $decisions = null;
}
if (!is_array($decisions)) {
    adiwira_json(['ok' => false, 'allowed' => false, 'issues' => [], 'error' => __('Invalid update decisions.')], 400);
}

session_write_close();
require_once __DIR__ . '/../../../cfg/helpers/theme_helper.php';
require_once __DIR__ . '/../../../app/controllers/ThemeStoreClient.php';

$result = ThemeStoreClient::preflightUpdate($pdo, $folder, $decisions);
adiwira_json([
    'ok' => ($result['success'] ?? false) === true,
    'allowed' => ($result['allowed'] ?? false) === true,
    'issues' => is_array($result['issues'] ?? null) ? $result['issues'] : [],
    'error' => $result['error'] ?? null,
]);
