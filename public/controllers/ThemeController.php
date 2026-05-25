<?php
// controllers/ThemeController.php

class ThemeController
{
    /**
     * Render a "theme" type post.
     *
     * Theme posts are experimental layout containers.
     * They do NOT participate in:
     * - slot system
     * - theme engine
     * - default theme fallback
     *
     * Source of truth: database only.
     */
    public static function renderTheme(array $themeData)
    {
        // (Opsional tapi aman) pastikan helper tersedia jika kamu pakai render_custom_post_template + shortcode
        $root = realpath(__DIR__ . '/..');
        if ($root) {
            $btTheme = $root . '/bootstrap_theme.php';
            if (is_file($btTheme)) require_once $btTheme;

            $th = $root . '/../cfg/helpers/theme_helper.php';
            if (!function_exists('render_custom_post_template') && is_file($th)) require_once $th;

            $wh = $root . '/../cfg/helpers/widget_helper.php';
            if (!function_exists('render_widget') && is_file($wh)) require_once $wh;
        }

        $vars = [
            'post'         => $themeData,
            'page'         => $themeData,
            'site_context' => 'theme',
            'page_title'   => $themeData['title'] ?? 'Theme',
        ];

        $content_html = '';

        // 1) Render DB template (preferred)
        if (!empty($themeData['content']) && function_exists('render_custom_post_template')) {
            try {
                $rendered = render_custom_post_template($themeData, $vars);
                if (trim($rendered) !== '') {
                    $content_html = $rendered;
                }
            } catch (Throwable $e) {
                error_log("[ThemeController::renderTheme] render_custom_post_template error: " . $e->getMessage());
            }
        }

        // 2) Raw DB content fallback
        if (trim($content_html) === '') {
            $content_html =
                '<article>' .
                '<h1>' . htmlspecialchars($themeData['title'] ?? 'Theme', ENT_QUOTES, 'UTF-8') . '</h1>' .
                ($themeData['content'] ?? '') .
                '</article>';
        }

        // canonical (optional but consistent)
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $canonical_url = $scheme . '://' . $host . '/' . rawurlencode($themeData['slug'] ?? '') . '/';

        // expose to layout
        $page_title = $vars['page_title'];
        $context_for_layout = 'theme';

        // ==============================
        // LAYOUT OVERRIDE (INI TEMPATNYA)
        // ==============================
        // Default layout policy di layout.php:
        // - homepage: full width + sidebar off
        // - selain homepage: container + sidebar on
        //
        // Kalau theme-page kamu mau beda, set di sini:

        $layout_full_width = true;   // paksa pakai container (jadi tidak full width)
        $enable_sidebar    = false;    // paksa sidebar aktif
        $sidebar_position  = 'left';  // 'left' atau 'right'

        // (Opsional) kalau mau bisa diatur dari meta JSON pada post theme:
        // meta contoh: {"layout_full_width":true,"enable_sidebar":false,"sidebar_position":"right"}
        if (!empty($themeData['meta'])) {
            $meta = json_decode((string)$themeData['meta'], true);
            if (is_array($meta)) {
                if (array_key_exists('layout_full_width', $meta)) $layout_full_width = (bool)$meta['layout_full_width'];
                if (array_key_exists('enable_sidebar', $meta))    $enable_sidebar    = (bool)$meta['enable_sidebar'];
                if (!empty($meta['sidebar_position'])) {
                    $pos = strtolower(trim((string)$meta['sidebar_position']));
                    if (in_array($pos, ['left','right'], true)) $sidebar_position = $pos;
                }
            }
        }

        // layout.php akan bootstrap lagi, tapi require_once aman.
        require __DIR__ . '/../layout.php';
        exit;
    }
}
