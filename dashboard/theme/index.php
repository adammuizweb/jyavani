<?php
    http_response_code(404);
    require dirname(__DIR__, 2) . '/public/frontend_404.php';
    exit;