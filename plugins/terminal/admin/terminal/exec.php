<?php
// Plugin: Terminal Legacy XHR Executor
declare(strict_types=1);

require_once DASH_PATH . '/admin/_deny.php';
require_once DASH_PATH . '/admin/_guard.php';

[$uid, $role] = adiwira_require_admin($pdo, true);

$cmd = trim((string)($_POST['cmd'] ?? ''));
if ($cmd === '') {
    adiwira_json(['output' => '', 'error' => false]);
}

$blocked = ['rm -rf /', 'mkfs', 'dd if=', ':(){:|:&};:'];
foreach ($blocked as $b) {
    if (stripos($cmd, $b) !== false) {
        adiwira_json(['output' => 'Perintah diblokir: ' . $b, 'error' => true], 403);
    }
}

$safeCmd = 'cd /home/adam && sudo -u adam bash -c ' . escapeshellarg($cmd) . ' 2>&1';

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($safeCmd, $descriptors, $pipes);

if (!is_resource($process)) {
    adiwira_json(['output' => 'Gagal menjalankan perintah.', 'error' => true]);
}

fclose($pipes[0]);

$timeout = 10;
$start = microtime(true);
while ((microtime(true) - $start) < $timeout) {
    $status = proc_get_status($process);
    if (!$status['running']) break;
    usleep(50000);
}

$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_terminate($process, 9);

$output = trim($output);
if ($output === '') $output = '(no output)';

adiwira_json(['output' => $output, 'error' => false]);
