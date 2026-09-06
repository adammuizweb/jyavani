<?php
declare(strict_types=1);
$assetResource = 'file';
$requestedAction = (string)($_POST['action'] ?? '');
$assetAction = $requestedAction === 'restore' ? 'restore' : ($requestedAction === 'delete_permanent' ? 'purge' : '');
$assetBulk = true;
require __DIR__ . '/../_asset_action.php';
