<?php
declare(strict_types=1);

// /adiwira/admin/photos/api/_init.php
require_once __DIR__ . '/../../_guard.php';

// Kalau dibuka langsung di tab -> samarkan jadi 404 HTML
adiwira_cosmetic_404_on_direct_open();

// Untuk endpoint photos: author/editor/admin boleh akses
[$uid, $role] = adiwira_require_editorial($pdo, true);

// Setelah ini aman balas JSON
header('Content-Type: application/json; charset=utf-8');

// debug flag opsional
$DEBUG = (isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true'));

function out_json(array $arr, int $code = 200): void {
    adiwira_json($arr, $code);
}

// pastikan PDO ada
$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) {
    out_json(['ok' => false, 'error' => 'PDO not available'], 500);
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // ignore
}

if ($uid <= 0) {
    out_json(['ok' => false, 'error' => 'Not found'], 404);
}

// HANYA admin yang truly admin
$isAdmin = ($role === 'admin');

// input JSON body atau POST biasa
$raw = file_get_contents('php://input');
$IN  = json_decode($raw ?: '', true);
if (!is_array($IN)) $IN = [];
if (!empty($_POST) && empty($IN)) $IN = $_POST;

// csrf helper
function csrf_ok($token): bool {
    return adiwira_csrf_validate(is_string($token) ? $token : '');
}