<?php
declare(strict_types=1);

if (!function_exists('default_theme_home_data')) {
    function default_theme_home_data(?PDO $pdo = null): array
    {
        $hasPdo = $pdo instanceof PDO;
        if (isset($GLOBALS['__jy_default_theme_home_data'])
            && is_array($GLOBALS['__jy_default_theme_home_data'])
            && (!$hasPdo || ($GLOBALS['__jy_default_theme_home_data_has_pdo'] ?? false) === true)) {
            return $GLOBALS['__jy_default_theme_home_data'];
        }

        $data = [
            'site_title' => 'Jyavani CMS',
            'site_description' => '',
            'admin_url' => '/dashboard/',
            'categories' => [],
            'panduan_posts' => [],
            'latest_posts' => [],
            'keamanan_posts' => [],
            'pengembangan_posts' => [],
            'sistem_posts' => [],
            'has_posts' => false,
            'main_post' => null,
            'side_posts' => [],
            'carousel_posts' => [],
        ];
        $GLOBALS['__jy_default_theme_home_data'] = $data;
        $GLOBALS['__jy_default_theme_home_data_has_pdo'] = false;

        if ($pdo instanceof PDO) {
            if (function_exists('settings_get')) {
                try {
                    $data['site_title'] = (string)settings_get($pdo, 'site_title', 'Jyavani CMS');
                    $data['site_description'] = (string)settings_get($pdo, 'site_description', '');
                } catch (Throwable $e) {
                    error_log('[default-theme] homepage settings failed: ' . $e->getMessage());
                }
            }
            if (function_exists('get_admin_path')) {
                try {
                    $data['admin_url'] = '/' . trim(get_admin_path($pdo), '/') . '/';
                } catch (Throwable $e) {
                    error_log('[default-theme] homepage admin URL failed: ' . $e->getMessage());
                }
            }
            if (function_exists('widget_fetch_categories')) {
                try {
                    $data['categories'] = widget_fetch_categories($pdo, 50, true);
                } catch (Throwable $e) {
                    error_log('[default-theme] homepage categories failed: ' . $e->getMessage());
                }
            }
            if (function_exists('cms_posts_by_category')) {
                $queries = [
                    'panduan_posts' => ['panduan', 5],
                    'latest_posts' => ['', 8],
                    'keamanan_posts' => ['keamanan', 3],
                    'pengembangan_posts' => ['pengembangan', 3],
                    'sistem_posts' => ['sistem', 3],
                ];
                foreach ($queries as $key => [$category, $limit]) {
                    try {
                        $posts = cms_posts_by_category($pdo, $category, [
                            'type' => 'article',
                            'status' => 'published',
                            'limit' => $limit,
                            'order_by' => 'created_at',
                            'order_dir' => 'DESC',
                        ]);
                        $data[$key] = is_array($posts) ? $posts : [];
                    } catch (Throwable $e) {
                        error_log('[default-theme] homepage posts failed for ' . $category . ': ' . $e->getMessage());
                    }
                }
            }
        }

        $data['has_posts'] = $data['latest_posts'] !== [];
        $data['main_post'] = $data['panduan_posts'][0] ?? null;
        $data['side_posts'] = array_slice($data['panduan_posts'], 1, 4);
        $data['carousel_posts'] = array_slice($data['latest_posts'], 0, 8);
        $GLOBALS['__jy_default_theme_home_data'] = $data;
        $GLOBALS['__jy_default_theme_home_data_has_pdo'] = $hasPdo;
        return $data;
    }
}

if (!function_exists('default_theme_home_post_url')) {
    function default_theme_home_post_url(array $post): string
    {
        return function_exists('get_post_permalink')
            ? get_post_permalink($post)
            : '/' . rawurlencode((string)($post['slug'] ?? '')) . '/';
    }
}

if (!function_exists('default_theme_home_post_thumb')) {
    function default_theme_home_post_thumb(array $post): string
    {
        $thumb = function_exists('media_post_display_url')
            ? (string)(media_post_display_url($post) ?? '')
            : trim((string)($post['thumbnail'] ?? ''));
        if ($thumb !== '') return $thumb;
        if (array_key_exists('display_image', $post) || array_key_exists('featured_media', $post)) return '';
        return function_exists('widget_first_image_from_content')
            ? widget_first_image_from_content((string)($post['content'] ?? ''))
            : '';
    }
}

if (!function_exists('default_theme_home_post_date')) {
    function default_theme_home_post_date(array $post): string
    {
        return function_exists('widget_format_date_id')
            ? widget_format_date_id($post['created_at'] ?? null)
            : '';
    }
}

if (!function_exists('default_theme_home_excerpt')) {
    function default_theme_home_excerpt(string $html, int $length = 140): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($html)));
        if (function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $length
                ? mb_substr($text, 0, $length, 'UTF-8') . '…'
                : $text;
        }
        return strlen($text) > $length ? substr($text, 0, $length) . '…' : $text;
    }
}

if (!function_exists('default_theme_home_carousel_script')) {
    function default_theme_home_carousel_script(): string
    {
        return <<<'HTML'
<script>
(function(){
    if (typeof Swiper === 'undefined') return;
    new Swiper('.hp-carousel__swiper', {
        slidesPerView: 1.25,
        spaceBetween: 16,
        loop: true,
        autoplay: {
            delay: 3000,
            disableOnInteraction: false,
            pauseOnMouseEnter: true,
        },
        speed: 650,
        breakpoints: {
            520: { slidesPerView: 2.25 },
            780: { slidesPerView: 3.25 },
            1100: { slidesPerView: 4.25 },
        },
    });
})();
</script>
HTML;
    }
}
