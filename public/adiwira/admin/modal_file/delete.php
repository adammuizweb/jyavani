<?php
// /adiwira/admin/modal_file/delete.php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../_guard.php';
// 1) Kalau orang buka URL ini langsung di browser: samarkan total sebagai 404 HTML
if (adiwira_is_navigate_request()) {
  http_response_code(404);
  require __DIR__ . '/../../../frontend_404.php';
  exit;
}

// 2) Untuk request programmatic (AJAX/fetch): pakai JSON
adiwira_require_admin(true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  adiwira_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!adiwira_csrf_validate(is_string($csrf) ? $csrf : '')) {
  adiwira_json(['ok'=>false,'error'=>'CSRF invalid'], 419);
}

$id = (int)($_POST['id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));

if ($id <= 0 && $url === '') {
  adiwira_json(['ok'=>false,'error'=>'Missing id or url'], 400);
}

// helper startsWith (hindari fn/arrow, aman untuk versi lama)
$startsWith = function($haystack, $needle){
  $haystack = (string)$haystack; $needle = (string)$needle;
  if ($needle === '') return true;
  return strncmp($haystack, $needle, strlen($needle)) === 0;
};

// fetch url from DB if id given
if ($id > 0) {
  $q = $pdo->prepare("SELECT id, url FROM `file` WHERE id=:id LIMIT 1");
  $q->execute([':id'=>$id]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if ($row && !empty($row['url'])) $url = (string)$row['url'];
}

// delete physical file only if under /static/
$path = $url ? (parse_url($url, PHP_URL_PATH) ?: '') : '';
if ($path && $startsWith($path, '/static/')) {
  $static_root = realpath(rtrim((string)PUBLIC_PATH, '/\\') . '/static');
  if ($static_root) {
    $rel = substr($path, strlen('/static')); // starts with '/'
    $local = $static_root . $rel;
    $real_local = realpath($local);
    if ($real_local && $startsWith($real_local, $static_root) && is_file($real_local)) {
      @unlink($real_local);
    }
  }
}

// delete DB row
try {
  $deleted_id = $id;

  if ($deleted_id <= 0 && $url !== '') {
    $q = $pdo->prepare("SELECT id FROM `file` WHERE url=:url LIMIT 1");
    $q->execute([':url'=>$url]);
    $found = $q->fetch(PDO::FETCH_ASSOC);
    if ($found) $deleted_id = (int)$found['id'];
  }

  if ($deleted_id > 0) {
    $pdo->prepare("DELETE FROM `file` WHERE id=:id")->execute([':id'=>$deleted_id]);
  } elseif ($url !== '') {
    $pdo->prepare("DELETE FROM `file` WHERE url=:url")->execute([':url'=>$url]);
  }

  adiwira_json(['ok'=>true, 'id'=>$deleted_id, 'url'=>$url], 200);

} catch (Throwable $e) {
  adiwira_json(['ok'=>false,'error'=>'DB error','detail'=>$e->getMessage()], 500);
}