<?php
// /adiwira/logout.php
require_once __DIR__ . '/bootstrap.php';

// perform logout (logout_user handles cookie expiry)
logout_user();

// redirect
header('Location: /');
exit;