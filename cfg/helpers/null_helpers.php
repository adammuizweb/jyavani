<?php
// null_helpers.php
// Helper umum untuk admin/backend

if (!function_exists('e')) {
    function e($v) {
        return htmlspecialchars(
            (string)($v ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
