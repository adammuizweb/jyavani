<?php
declare(strict_types=1);

if (!function_exists('adiwira_admin_404')) {
    function adiwira_admin_404(): void
    {
        if (headers_sent()) {
            echo '<section class="adam-empty"><h2>' . __('Page not found') . '</h2>'
                . '<p>' . __('The requested dashboard page is unavailable.') . '</p></section>';
            exit;
        }
        http_response_code(404);

        $file = dirname(__DIR__, 2) . '/app/frontend_404.php';
        require $file;
        exit;
    }
}
