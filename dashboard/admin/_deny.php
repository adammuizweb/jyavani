<?php
declare(strict_types=1);

if (!function_exists('adiwira_admin_404')) {
    function adiwira_admin_404(): void
    {
        http_response_code(404);

        $file = dirname(__DIR__, 2) . '/app/frontend_404.php';
        require $file;
        exit;
    }
}