<?php
// silence is golden
    http_response_code(404);
    require __DIR__ . '/../../app/frontend_404.php';
    exit;