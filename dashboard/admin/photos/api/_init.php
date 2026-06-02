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

// debug flag: ?debug=1 di URL ATAU APP_DEBUG=1 di .env
$DEBUG = (isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true'))
      || (function_exists('app_debug_enabled') && app_debug_enabled());

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

// ===== schema detection (shared by all photo API files) =====
$PHOTO_HAS_SORT_ORDER = false;
$PHOTO_HAS_MEDIA_ITEMS = false;

try {
    $st = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'sort_order' LIMIT 1");
    $PHOTO_HAS_SORT_ORDER = (bool)$st->fetchColumn();
} catch (Throwable $e) {}

try {
    $st = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_media_items' LIMIT 1");
    $PHOTO_HAS_MEDIA_ITEMS = (bool)$st->fetchColumn();
} catch (Throwable $e) {}

// ===== ensure posts.type can hold 'photo' (auto-fix if ENUM missing it) =====
$PHOTO_TYPE_OK = false;
try {
    $st = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'type' LIMIT 1");
    $colType = $st->fetchColumn();
    if ($colType !== false) {
        $colType = strtolower($colType);
        if ($colType === 'varchar(50)' || $colType === 'varchar(255)' || strpos($colType, 'text') !== false || strpos($colType, "'photo'") !== false) {
            $PHOTO_TYPE_OK = true;
        } elseif (strpos($colType, 'enum') !== false) {
            try {
                $pdo->exec("ALTER TABLE `posts` MODIFY COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'article'");
                $PHOTO_TYPE_OK = true;
            } catch (Throwable $e2) {}
        }
    }
} catch (Throwable $e) {}

// input JSON body atau POST biasa
$raw = file_get_contents('php://input');
$IN  = json_decode($raw ?: '', true);
if (!is_array($IN)) $IN = [];
if (!empty($_POST) && empty($IN)) $IN = $_POST;

// csrf helper
function csrf_ok($token): bool {
    return adiwira_csrf_validate(is_string($token) ? $token : '');
}