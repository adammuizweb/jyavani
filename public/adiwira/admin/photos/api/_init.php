<?php
declare(strict_types=1);

// /adiwira/admin/photos/api/_init.php
require_once __DIR__ . '/../../_guard.php';

// Kalau dibuka di tab (navigate/document) -> tampilkan 404 HTML dan stop di sini
adiwira_cosmetic_404_on_direct_open();

// Untuk request fetch/XHR -> balas JSON 404 saat tidak login
adiwira_require_admin(adiwira_wants_json());

// Setelah ini barulah aman set JSON header
header('Content-Type: application/json; charset=utf-8');

// debug flag (opsional)
$DEBUG = (isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true'));

function out_json(array $arr, int $code = 200): void {
  adiwira_json($arr, $code); // pakai responder yang sudah bersih
}

// pastikan PDO ada
$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) {
  out_json(['ok'=>false,'error'=>'PDO not available'], 500);
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }

// setelah require_admin(true), normalnya uid sudah ada
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) out_json(['ok'=>false,'error'=>'Not found'], 404);

// role (ambil dari helper kalau ada, atau session)
$role = function_exists('current_user_role')
  ? (string)current_user_role($pdo)
  : (string)($_SESSION['user_role'] ?? 'admin');

$isAdmin = in_array($role, ['admin','editor'], true);

// input JSON body atau POST biasa
$raw = file_get_contents('php://input');
$IN  = json_decode($raw ?: '', true);
if (!is_array($IN)) $IN = [];
if (!empty($_POST) && empty($IN)) $IN = $_POST;

// csrf helper
function csrf_ok($token): bool {
  return adiwira_csrf_validate(is_string($token) ? $token : '');
}