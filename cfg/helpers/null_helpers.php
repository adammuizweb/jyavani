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

/**
 * svg_icon — render Lucide SVG icon from disk
 * @param string $name  Lucide icon filename (without .svg)
 * @param string $class Extra CSS classes
 * @param array  $attrs Additional SVG attributes
 */
if (!function_exists('svg_ico')) {
    function svg_ico($name, $class = '', $attrs = []) {
        static $cache = [];

        $icoDir = defined('PUBLIC_PATH') ? realpath(PUBLIC_PATH . '/static/icons/lucide') : false;
        if (!$icoDir) {
            $icoDir = realpath(__DIR__ . '/../../public_html/static/icons/lucide') ?: realpath(__DIR__ . '/../../public/static/icons/lucide');
        }
        if (!$icoDir) return '';

        if (!isset($cache[$name])) {
            $path = $icoDir . '/' . $name . '.svg';
            $raw = is_file($path) ? file_get_contents($path) : '';
            $cache[$name] = $raw;
        }

        $inner = $cache[$name];
        if ($inner === '') {
            $inner = '<circle cx="12" cy="12" r="8"></circle>';
        } else {
            $inner = trim(preg_replace('/^<svg[^>]*>|<\/svg>$/', '', $inner));
        }

        $cls = trim('lucide-icon ' . $class);
        $extra = '';
        foreach ($attrs as $k => $v) {
            $extra .= ' ' . $k . '="' . e($v) . '"';
        }

        return '<svg class="' . e($cls) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' . $extra . '>' . $inner . '</svg>';
    }
}
