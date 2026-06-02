<?php
    http_response_code(404);
    require dirname(__DIR__, 3) . '/public/frontend_404.php';
    exit;