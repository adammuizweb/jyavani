<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/_layout_manager.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);
$presetIdInput = $_POST['preset_id'] ?? 0;
$presetId = (is_string($presetIdInput) || is_int($presetIdInput))
    ? (filter_var($presetIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0)
    : 0;
$layoutScope = is_string($_POST['scope'] ?? null) ? $_POST['scope'] : 'collection';
if (!in_array($layoutScope, ['collection', 'section'], true)) $layoutScope = 'collection';
$isSectionScope = $layoutScope === 'section';
$presetConfigJson = is_string($_POST['preset_config'] ?? null) ? $_POST['preset_config'] : '';
$isValidatedPresetPreview = !$isSectionScope && ($presetConfigJson !== '' || $presetId > 0);
if ($role !== 'admin' && !$isValidatedPresetPreview) {
    adiwira_require_admin($pdo, true);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
}

$csrfInput = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrf = is_string($csrfInput) ? $csrfInput : '';
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'error' => __('CSRF invalid')], 419);
}

$content = is_string($_POST['content'] ?? null) ? $_POST['content'] : '';
if (trim($content) === '') {
    adiwira_json(['ok' => false, 'html' => '<div class="pcat__empty" style="padding:2rem;text-align:center;color:var(--adam-muted,#888);">Template kosong — tulis kode layout di editor.</div>']);
}

$tmpDir = defined('CFG_VAR_PATH') ? (string)CFG_VAR_PATH : (__DIR__ . '/../../../cfg/var');
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0755, true);
}

$tmpFile = $tmpDir . '/tmp_layout_preview_' . bin2hex(random_bytes(8)) . '.php';
register_shutdown_function(static function () use ($tmpFile): void {
    if (is_file($tmpFile)) @unlink($tmpFile);
});
try {
    $esc = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };
    $safeUrl = static function (mixed $value): string {
        return function_exists('theme_section_safe_url') ? theme_section_safe_url($value) : '';
    };
    $sliderEnabled = false;

    if ($isSectionScope) {
        $sectionName = is_string($_POST['section_name'] ?? null) ? strtolower(trim($_POST['section_name'])) : '';
        if ($sectionName === '') $sectionName = 'preview.section';
        if (!function_exists('theme_section_name_is_valid') || !theme_section_name_is_valid($sectionName)) {
            adiwira_json(['ok' => false, 'error' => __('Invalid section name.')], 400);
        }

        $attrs = [
            'title' => __('Theme Section Preview'),
            'summary' => __('Shortcode attributes and page context are available to this active-theme renderer.'),
            'url' => '#preview',
            'link_label' => __('Learn more'),
        ];
        $context = [
            'type' => 'theme',
            'title' => __('Preview Page'),
            'slug' => 'preview-page',
        ];
        $definition = function_exists('theme_section_definition') ? theme_section_definition($sectionName) : [];
        $section = $sectionName;
        $section_name = $sectionName;

        if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            throw new RuntimeException(__('Failed to prepare template preview.'));
        }

        ob_start();
        (static function () use ($tmpFile, $section, $section_name, $attrs, $context, $definition, $esc, $safeUrl, $pdo): void {
            extract([
                'section' => $section,
                'section_name' => $section_name,
                'attrs' => $attrs,
                'context' => $context,
                'definition' => $definition,
                'esc' => $esc,
                'safe_url' => $safeUrl,
                'pdo' => $pdo,
            ], EXTR_SKIP);
            include $tmpFile;
        })();
        $html = (string)ob_get_clean();
        if (trim($html) === '') {
            $html = '<div style="padding:2rem;text-align:center;color:var(--adam-muted,#888);">' . __('Template produced no output.') . '</div>';
        }

        $document = function_exists('theme_section_preview_document')
            ? theme_section_preview_document($html, $pdo, ['section' => $sectionName])
            : $html;
        adiwira_json(['ok' => true, 'html' => $html, 'document' => $document, 'mode' => 'section']);
        return;
    }

    // --- MODE A: Preview from inline config (used by preset editor, unsaved data) ---
    if ($presetConfigJson !== '') {
        $config = json_decode($presetConfigJson, true);
        if (!is_array($config)) {
            adiwira_json(['ok' => false, 'error' => __('Invalid preset configuration.')], 400);
        }
        $previewContext = ['mode' => 'inline', 'source' => is_string($config['source'] ?? null) ? $config['source'] : 'posts'];
        $validation = shortcode_preset_prepare_preview_config($config, $role === 'admin', $previewContext, $pdo);
        if ($validation['errors'] !== []) {
            adiwira_json(['ok' => false, 'error' => (string)$validation['errors'][0], 'errors' => $validation['errors']], 422);
        }
        $config = $validation['config'];
        $previewResult = shortcode_preset_preview_result(null, $config, $previewContext, $pdo);
        if ($previewResult !== null) {
            adiwira_json(array_merge(['ok' => true], $previewResult));
            return;
        }
        if (($config['source'] ?? 'posts') !== 'posts') {
            $html = function_exists('post_cat_shortcode_render')
                ? post_cat_shortcode_render($pdo, $config, ['scope' => 'preset_preview'])
                : '';
            adiwira_json([
                'ok' => true,
                'html' => $html !== '' ? $html : '<div class="pcat__empty">' . __('No items are available for this preset source.') . '</div>',
                'mode' => 'preset_source',
            ]);
            return;
        }

        $layoutName = is_string($config['layout'] ?? null) ? $config['layout'] : 'list';

        // Load the actual layout template from file
        $layoutReal = shortcode_collection_layout_name_is_valid($layoutName)
            ? post_cat__find_layout_template($pdo, $layoutName)
            : null;
        if (!$layoutReal) {
            adiwira_json(['ok' => false, 'error' => __('Layout template not found.')], 404);
        }
        $layoutContent = file_get_contents($layoutReal);
        if ($layoutContent === false || file_put_contents($tmpFile, $layoutContent, LOCK_EX) === false) {
            throw new RuntimeException(__('Failed to prepare template preview.'));
        }

        $type = (string)($config['type'] ?? 'article');
        $catRaw = (string)($config['category'] ?? '');
        $limit = max(1, (int)($config['limit'] ?? 5));
        $offset = max(0, (int)($config['offset'] ?? 0));
        $orderBy = (string)($config['order_by'] ?? 'created_at');
        $orderDir = (string)($config['order_dir'] ?? 'DESC');
        $includeChildren = ($config['include_children'] ?? '1') !== '0';
        $excerptLen = max(10, (int)($config['excerpt_len'] ?? 90));
        $classPrefix = trim((string)($config['class_prefix'] ?? ''));
        $wrap = ($config['wrap'] ?? '1') === '1';
        $dateFrom = $config['date_from'] ?? null;
        $dateTo = $config['date_to'] ?? null;
        $authorId = isset($config['author']) ? (int)$config['author'] : (isset($config['created_by']) ? (int)$config['created_by'] : null);
        $kicker = post_cat__resolve_kicker($config, $catRaw);
        $sliderEnabled = strpos($layoutName, 'slider') !== false;

        $items = [];
        if (function_exists('cms_posts_by_category')) {
            $posts = cms_posts_by_category($pdo, $catRaw, [
                'type' => $type,
                'status' => 'published',
                'include_children' => $includeChildren,
                'limit' => $limit,
                'offset' => $offset,
                'order_by' => $orderBy,
                'order_dir' => $orderDir,
                'created_by' => $authorId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            if ($posts) {
                foreach ($posts as $p) {
                    $titleRaw = (string)($p['title'] ?? '');
                    $slug = (string)($p['slug'] ?? '');
                    $url = $slug !== '' ? '/' . rawurlencode($slug) . '/' : '#';
                    $thumb = trim((string)($p['thumbnail'] ?? ''));
                    $desc = (function_exists('post_cat__excerpt') ? post_cat__excerpt((string)($p['content'] ?? ''), $excerptLen) : mb_substr(strip_tags((string)($p['content'] ?? '')), 0, $excerptLen));
                    $dateIso = '';
                    $dateLabel = '';
                    $createdAt = (string)($p['created_at'] ?? '');
                    if ($createdAt !== '') {
                        $ts = strtotime($createdAt);
                        if ($ts) {
                            $dateIso = date('c', $ts);
                            $dateLabel = date('d M Y', $ts);
                        }
                    }

                    $items[] = [
                        'kind' => 'post',
                        'title' => $titleRaw,
                        'slug' => $slug,
                        'url' => $url,
                        'thumb' => $thumb,
                        'desc' => $desc,
                        'date_iso' => $dateIso,
                        'date_label' => $dateLabel,
                        'raw' => $p,
                    ];
                }
            }
        }

        if (empty($items)) {
            $html = '<div class="pcat__empty" style="padding:2rem;text-align:center;color:var(--adam-muted,#888);">' . __('No posts match these filters.') . '</div>';
        }

        $attrs = $config;
        $attrs['source'] = $config['source'] ?? 'posts';
        $layout = $layoutName ?: 'list';
        $limitVisible = (int)($config['limit'] ?? 5);

        ob_start();
        (static function () use ($tmpFile, $items, $attrs, $layout, $kicker, $classPrefix, $wrap, $esc, $sliderEnabled, $limitVisible) {
            $instance_id = 'pcat_' . bin2hex(random_bytes(4));
            $limit_visible = $limitVisible;
            $slider_enabled = $sliderEnabled;
            extract([
                'items' => $items,
                'attrs' => $attrs,
                'layout' => $layout,
                'kicker' => $kicker,
                'class_prefix' => $classPrefix,
                'wrap' => $wrap,
                'esc' => $esc,
                'slider_enabled' => $sliderEnabled,
                'instance_id' => $instance_id,
                'limit_visible' => $limitVisible,
            ], EXTR_SKIP);
            include $tmpFile;
        })();
        $html = (string)ob_get_clean();

        if (trim($html) === '') {
            $html = '<div class="pcat__empty" style="padding:2rem;text-align:center;color:var(--adam-muted,#888);">' . __('Template produced no output.') . '</div>';
        }

        adiwira_json(['ok' => true, 'html' => $html, 'mode' => 'preset_config']);
        return;
    }

    // --- MODE B: Preview with a real preset (real posts from DB) ---
    if ($presetId > 0) {
        $ownershipSql = $role === 'admin' ? '' : ' AND created_by = :created_by';
        $stmt = $pdo->prepare("SELECT meta, created_by FROM posts WHERE id = :id AND type = 'sc_preset' AND is_deleted = 0" . $ownershipSql . " LIMIT 1");
        $params = [':id' => $presetId];
        if ($role !== 'admin') $params[':created_by'] = $uid;
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            adiwira_json(['ok' => false, 'html' => '<div style="padding:1.5rem;color:#e74c3c;">' . e(__('Preset not found.')) . '</div>', 'error' => __('Preset not found.')]);
            return;
        }

        $config = shortcode_preset_config_loaded((string)($row['meta'] ?? '{}'), $row, $pdo);
        $previewContext = ['mode' => 'stored', 'preset_id' => $presetId, 'source' => is_string($config['source'] ?? null) ? $config['source'] : 'posts'];
        $validation = shortcode_preset_prepare_preview_config($config, $role === 'admin', $previewContext, $pdo);
        if ($validation['errors'] !== []) {
            adiwira_json(['ok' => false, 'error' => (string)$validation['errors'][0], 'errors' => $validation['errors']], 422);
        }
        $config = $validation['config'];
        $previewResult = shortcode_preset_preview_result(null, $config, $previewContext + ['template_content' => $content], $pdo);
        if ($previewResult !== null) {
            adiwira_json(array_merge(['ok' => true, 'preset_id' => $presetId], $previewResult));
            return;
        }
        if (($config['source'] ?? 'posts') !== 'posts') {
            $html = function_exists('post_cat_shortcode_render')
                ? post_cat_shortcode_render($pdo, $config, ['scope' => 'preset_preview', 'preset_id' => $presetId])
                : '';
            adiwira_json([
                'ok' => true,
                'html' => $html !== '' ? $html : '<div class="pcat__empty">' . __('No items are available for this preset source.') . '</div>',
                'mode' => 'preset_source',
                'preset_id' => $presetId,
            ]);
            return;
        }

        if ($role !== 'admin') {
            $storedLayout = post_cat__find_layout_template($pdo, (string)($config['layout'] ?? ''));
            $storedContent = $storedLayout ? file_get_contents($storedLayout) : false;
            if (!is_string($storedContent)) throw new RuntimeException(__('Failed to prepare template preview.'));
            $content = $storedContent;
        }
        if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            throw new RuntimeException(__('Failed to prepare template preview.'));
        }

        $type = (string)($config['type'] ?? 'article');
        $catRaw = (string)($config['category'] ?? '');
        $limit = max(1, (int)($config['limit'] ?? 5));
        $offset = max(0, (int)($config['offset'] ?? 0));
        $orderBy = (string)($config['order_by'] ?? 'created_at');
        $orderDir = (string)($config['order_dir'] ?? 'DESC');
        $includeChildren = ($config['include_children'] ?? '1') !== '0';
        $excerptLen = max(10, (int)($config['excerpt_len'] ?? 90));
        $classPrefix = trim((string)($config['class_prefix'] ?? ''));
        $wrap = ($config['wrap'] ?? '1') === '1';
        $dateFrom = $config['date_from'] ?? null;
        $dateTo = $config['date_to'] ?? null;
        $authorId = isset($config['author']) ? (int)$config['author'] : (isset($config['created_by']) ? (int)$config['created_by'] : null);
        $kicker = post_cat__resolve_kicker($config, $catRaw);

        // Enable slider if layout has "slider" in the name
        $layoutName = (string)($config['layout'] ?? 'list');
        $sliderEnabled = strpos($layoutName, 'slider') !== false;

        $items = [];
        if (function_exists('cms_posts_by_category')) {
            $posts = cms_posts_by_category($pdo, $catRaw, [
                'type' => $type,
                'status' => 'published',
                'include_children' => $includeChildren,
                'limit' => $limit,
                'offset' => $offset,
                'order_by' => $orderBy,
                'order_dir' => $orderDir,
                'created_by' => $authorId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            if ($posts) {
                foreach ($posts as $p) {
                    $titleRaw = (string)($p['title'] ?? '');
                    $slug = (string)($p['slug'] ?? '');
                    $url = $slug !== '' ? '/' . rawurlencode($slug) . '/' : '#';
                    $thumb = trim((string)($p['thumbnail'] ?? ''));
                    $desc = (function_exists('post_cat__excerpt') ? post_cat__excerpt((string)($p['content'] ?? ''), $excerptLen) : mb_substr(strip_tags((string)($p['content'] ?? '')), 0, $excerptLen));
                    $dateIso = '';
                    $dateLabel = '';
                    $createdAt = (string)($p['created_at'] ?? '');
                    if ($createdAt !== '') {
                        $ts = strtotime($createdAt);
                        if ($ts) {
                            $dateIso = date('c', $ts);
                            $dateLabel = date('d M Y', $ts);
                        }
                    }

                    $items[] = [
                        'kind' => 'post',
                        'title' => $titleRaw,
                        'slug' => $slug,
                        'url' => $url,
                        'thumb' => $thumb,
                        'desc' => $desc,
                        'date_iso' => $dateIso,
                        'date_label' => $dateLabel,
                        'raw' => $p,
                    ];
                }
            }
        }

        if (empty($items)) {
            $items = [];
            $html = '<div class="pcat__empty" style="padding:2rem;text-align:center;color:var(--adam-muted,#888);">' . sprintf(__('Preset "%s" — no posts match these filters.'), $esc((string)($config['title'] ?? ''))) . '</div>';
        }

        $attrs = $config;
        $attrs['source'] = $config['source'] ?? 'posts';
        $layout = $layoutName ?: 'list';
        $limitVisible = (int)($config['limit'] ?? 5);

        ob_start();
        (static function () use ($tmpFile, $items, $attrs, $layout, $kicker, $classPrefix, $wrap, $esc, $sliderEnabled, $limitVisible) {
            $instance_id = 'pcat_' . bin2hex(random_bytes(4));
            $limit_visible = $limitVisible;
            $slider_enabled = $sliderEnabled;
            extract([
                'items' => $items,
                'attrs' => $attrs,
                'layout' => $layout,
                'kicker' => $kicker,
                'class_prefix' => $classPrefix,
                'wrap' => $wrap,
                'esc' => $esc,
                'slider_enabled' => $sliderEnabled,
                'instance_id' => $instance_id,
                'limit_visible' => $limitVisible,
            ], EXTR_SKIP);
            include $tmpFile;
        })();
        $html = (string)ob_get_clean();

        if (trim($html) === '') {
            $html = '<div class="pcat__empty" style="padding:2rem;text-align:center;color:var(--adam-muted,#888);">' . __('Template produced no output.') . '</div>';
        }

        adiwira_json(['ok' => true, 'html' => $html, 'mode' => 'preset', 'preset_id' => $presetId]);
        return;
    }

    // --- MODE D: Dummy data preview (no preset) ---
    if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
        throw new RuntimeException(__('Failed to prepare template preview.'));
    }
    $items = [
        [
            'kind' => 'post',
            'title' => __('Digital Education Transformation in Indonesia 2026'),
            'slug' => 'transformasi-digital-pendidikan',
            'url' => '/artikel/transformasi-digital-pendidikan/',
            'thumb' => '',
            'desc' => __('The government officially launched a school digitalization program covering 50,000 educational institutions across Indonesia. The program aims to improve access and quality of technology-based learning.'),
            'date_iso' => '2026-06-26T10:30:00+07:00',
            'date_label' => '26 Jun 2026',
            'raw' => [
                'title' => __('Digital Education Transformation in Indonesia 2026'),
                'slug' => 'transformasi-digital-pendidikan',
                'content' => __('The government officially launched a school digitalization program...'),
                'created_at' => '2026-06-26 10:30:00',
                'author_name' => 'Admin',
            ],
        ],
        [
            'kind' => 'post',
            'title' => __('5 Hidden Nature Tourism Recommendations in West Java'),
            'slug' => 'wisata-alam-jawa-barat',
            'url' => '/artikel/wisata-alam-jawa-barat/',
            'thumb' => '',
            'desc' => __('Away from the hustle and bustle of the city, these nature destinations still offer pristine beauty. Perfect for those wanting to unwind on the weekend.'),
            'date_iso' => '2026-06-25T14:00:00+07:00',
            'date_label' => '25 Jun 2026',
            'raw' => [
                'title' => __('5 Hidden Nature Tourism Recommendations in West Java'),
                'slug' => 'wisata-alam-jawa-barat',
                'content' => __('Away from the hustle and bustle of the city...'),
                'created_at' => '2026-06-25 14:00:00',
                'author_name' => __('Editorial'),
            ],
        ],
        [
            'kind' => 'post',
            'title' => __('Tips for Starting a Career as a Frontend Developer in 2026'),
            'slug' => 'tips-karir-frontend-developer',
            'url' => '/artikel/tips-karir-frontend-developer/',
            'thumb' => '',
            'desc' => __('The tech industry keeps evolving. Check out a complete guide to starting a career as a frontend developer with the latest roadmap and curated learning resources.'),
            'date_iso' => '2026-06-24T09:15:00+07:00',
            'date_label' => '24 Jun 2026',
            'raw' => [
                'title' => __('Tips for Starting a Career as a Frontend Developer in 2026'),
                'slug' => 'tips-karir-frontend-developer',
                'content' => __('The tech industry keeps evolving...'),
                'created_at' => '2026-06-24 09:15:00',
                'author_name' => 'Admin',
            ],
        ],
        [
            'kind' => 'post',
            'title' => __('The 2026 Nusantara Culinary Festival Returns to Jakarta'),
            'slug' => 'festival-kuliner-nusantara-2026',
            'url' => '/artikel/festival-kuliner-nusantara-2026/',
            'thumb' => '',
            'desc' => __('More than 200 food stalls from across Indonesia are ready to spoil visitors. The event runs for 10 days at the Jakarta Convention Center.'),
            'date_iso' => '2026-06-23T16:45:00+07:00',
            'date_label' => '23 Jun 2026',
            'raw' => [
                'title' => __('The 2026 Nusantara Culinary Festival Returns to Jakarta'),
                'slug' => 'festival-kuliner-nusantara-2026',
                'content' => __('More than 200 food stalls...'),
                'created_at' => '2026-06-23 16:45:00',
                'author_name' => __('Editorial'),
            ],
        ],
    ];

    $attrs = [
        'source' => 'posts',
        'type' => 'article',
        'status' => 'published',
        'limit' => 4,
        'offset' => 0,
        'layout' => 'preview',
        'category' => 'news',
        'order_by' => 'created_at',
        'order_dir' => 'DESC',
        'include_children' => '1',
        'excerpt_len' => 150,
        'class_prefix' => '',
        'wrap' => '1',
        'kicker' => 'PREVIEW',
        'date_format' => 'd M Y',
    ];

    $layout = 'preview';
    $kicker = 'PREVIEW';
    $class_prefix = '';
    $wrap = true;
    $instance_id = 'pcat_preview_' . bin2hex(random_bytes(4));
    $limit_visible = 4;

    ob_start();
    (static function () use ($tmpFile, $items, $attrs, $layout, $kicker, $class_prefix, $wrap, $esc, $sliderEnabled, $instance_id, $limit_visible) {
        $slider_enabled = $sliderEnabled;
        extract([
            'items' => $items,
            'attrs' => $attrs,
            'layout' => $layout,
            'kicker' => $kicker,
            'class_prefix' => $class_prefix,
            'wrap' => $wrap,
            'esc' => $esc,
            'slider_enabled' => $sliderEnabled,
            'instance_id' => $instance_id,
            'limit_visible' => $limit_visible,
        ], EXTR_SKIP);
        include $tmpFile;
    })();
    $html = (string)ob_get_clean();

    if (trim($html) === '') {
        $html = '<div class="pcat__empty" style="padding:2rem;text-align:center;color:var(--adam-muted,#888);">Template tidak menghasilkan output. Pastikan ada <code>&lt;?=</code> atau <code>echo</code> di dalam loop.</div>';
    }

    adiwira_json(['ok' => true, 'html' => $html, 'mode' => 'dummy']);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $line = $e->getLine();
    adiwira_json([
        'ok' => false,
        'html' => '<div style="padding:1.5rem;color:#e74c3c;background:#fef0ef;border:1px solid #f5c6cb;border-radius:6px;font-family:monospace;font-size:.85rem;white-space:pre-wrap;">Error baris ' . $line . ': ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>',
        'error' => $msg,
    ]);
} finally {
    if (isset($tmpFile) && is_file($tmpFile)) {
        @unlink($tmpFile);
    }
}
