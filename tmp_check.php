<?php
$pdo = new PDO('mysql:host=localhost;dbname=jyavani','root','muiz');
$st = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('sidebar_enabled','sidebar_controller_overrides')");
foreach ($st as $r) {
    echo $r['key'] . '=' . $r['value'] . PHP_EOL;
}
