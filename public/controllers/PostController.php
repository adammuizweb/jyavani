<?php
// controllers/PostController.php

class PostController
{
    // ---------------------
    // Helpers
    // ---------------------

    private static function youtube_id_from_url(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('#youtu\.be/([A-Za-z0-9_\-]+)#i', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]v=([A-Za-z0-9_\-]+)#i', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#/(?:embed|v)/([A-Za-z0-9_\-]+)#i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function youtube_thumb_url(?string $url): ?string
    {
        $id = self::youtube_id_from_url($url);
        if (!$id) {
            return null;
        }

        return "https://img.youtube.com/vi/{$id}/hqdefault.jpg";
    }

    private static function extract_first_image_src_from_html(?string $html): ?string
    {
        if (!$html) {
            return null;
        }

        if (!class_exists('DOMDocument')) {
            return null;
        }

        $prev = libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return null;
        }

        $imgs = $doc->getElementsByTagName('img');
        foreach ($imgs as $img) {
            $src = trim((string)$img->getAttribute('src'));
            if ($src !== '') {
                return $src;
            }
        }

        return null;
    }

    public static function resolve_post_display_image(array $post): ?string
    {
        if (!empty($post['youtube'])) {
            $yt = self::youtube_thumb_url((string)$post['youtube']);
            if ($yt) {
                return $yt;
            }
        }

        if (!empty($post['display_image'])) {
            return (string)$post['display_image'];
        }

        if (!empty($post['thumbnail'])) {
            return (string)$post['thumbnail'];
        }

        return null;
    }

    /**
     * Resolve display images for an array of posts and (optionally) attach target metadata.
     * Backward-compatible: $pdo is optional.
     */
    public static function attach_display_images(array &$posts, ?PDO $pdo = null): void
    {
        if (empty($posts) || !is_array($posts)) {
            return;
        }

        // prefer provided PDO, fallback to global if available
        $pdoToUse = $pdo ?? ($GLOBALS['pdo'] ?? null);

        foreach ($posts as &$p) {
            if (!is_array($p)) {
                continue;
            }

            $p['display_image'] = self::resolve_post_display_image($p);

            // only attach target info if we have a PDO connection
            if ($pdoToUse instanceof PDO) {
                try {
                    self::attach_display_image_target($p, $pdoToUse);
                } catch (Throwable $e) {
                    error_log('[PostController::attach_display_images] attach target error: ' . $e->getMessage());
                    $p['display_image_target_url'] = null;
                    $p['display_image_target_attribute'] = null;
                }
            } else {
                $p['display_image_target_url'] = $p['display_image_target_url'] ?? null;
                $p['display_image_target_attribute'] = $p['display_image_target_attribute'] ?? null;
            }
        }

        unset($p);
    }

    /**
     * Attach target info (target_url & target_attribute) for a post's display_image.
     * Adds two keys to $postData by reference:
     *   - display_image_target_url
     *   - display_image_target_attribute
     *
     * If no matching media found, both keys are set to null.
     */
    private static function attach_display_image_target(array &$postData, PDO $pdo): void
    {
        $postData['display_image_target_url'] = null;
        $postData['display_image_target_attribute'] = null;

        if (empty($postData['display_image'])) {
            return;
        }

        $displayUrl = (string)$postData['display_image'];

        try {
            // 1) exact match by url
            $stm = $pdo->prepare('SELECT target_url, target_attribute FROM media WHERE url = :url LIMIT 1');
            $stm->execute([':url' => $displayUrl]);
            $m = $stm->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$m) {
                // 2) fallback: match by path fragment (useful when DB stores relative path)
                $path = parse_url($displayUrl, PHP_URL_PATH) ?: $displayUrl;
                $likePath = '%' . ltrim((string)$path, '/');

                $stm2 = $pdo->prepare('SELECT target_url, target_attribute FROM media WHERE url LIKE :like_path LIMIT 1');
                $stm2->execute([':like_path' => $likePath]);
                $m = $stm2->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($m) {
                $postData['display_image_target_url'] = $m['target_url'] !== null ? $m['target_url'] : null;
                $postData['display_image_target_attribute'] = $m['target_attribute'] !== null ? $m['target_attribute'] : null;
            }
        } catch (Throwable $e) {
            error_log('[PostController::attach_display_image_target] ' . $e->getMessage());
            $postData['display_image_target_url'] = null;
            $postData['display_image_target_attribute'] = null;
        }
    }

    /**
     * Resolve sidebar/layout defaults, optionally from theme meta.
     */
    private static function resolveLayoutOptions(array $themeData = []): array
    {
        $layout = [
            'layout_full_width' => false,
            'enable_sidebar'    => true,
            'sidebar_position'  => 'right',
        ];

        if (!empty($themeData['meta'])) {
            $meta = json_decode((string)$themeData['meta'], true);

            if (is_array($meta)) {
                if (array_key_exists('layout_full_width', $meta)) {
                    $layout['layout_full_width'] = (bool)$meta['layout_full_width'];
                }

                if (array_key_exists('enable_sidebar', $meta)) {
                    $layout['enable_sidebar'] = (bool)$meta['enable_sidebar'];
                }

                if (!empty($meta['sidebar_position'])) {
                    $pos = strtolower(trim((string)$meta['sidebar_position']));
                    if (in_array($pos, ['left', 'right'], true)) {
                        $layout['sidebar_position'] = $pos;
                    }
                }
            }
        }

        return $layout;
    }

    // ---------------------
    // Dispatchers
    // ---------------------

    public static function dispatchBySlug(string $slug, PDO $pdo, bool $isLoggedIn)
    {
        try {
            if ($isLoggedIn) {
                $stmt = $pdo->prepare("SELECT * FROM posts WHERE slug = :slug AND is_deleted = 0 LIMIT 1");
                $stmt->execute([':slug' => $slug]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM posts WHERE slug = :slug AND is_deleted = 0 AND status = 'published' LIMIT 1");
                $stmt->execute([':slug' => $slug]);
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[PostController] DB error: ' . $e->getMessage());
            http_response_code(500);
            echo 'Server error';
            exit;
        }

        if (!$row) {
            http_response_code(404);
            $page_title = '404';
            $context_for_layout = '404';
            $content_html = '';
            require __DIR__ . '/../layout.php';
            exit;
        }

        $type = $row['type'] ?? 'article';

        switch ($type) {
            case 'theme':
                require_once __DIR__ . '/ThemeController.php';
                ThemeController::renderTheme($row);
                break;

            case 'page':
                require_once __DIR__ . '/PageController.php';
                PageController::renderPage($pdo, (string)($row['slug'] ?? ''));
                break;

            default:
                self::renderArticle($row, $pdo);
                break;
        }

        exit;
    }

    // ---------------------
    // List handler
    // ---------------------

    public static function listArticles(PDO $pdo, int $page = 1, string $q = '')
    {
        $perPage = 10;
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $whereParts = ["type = 'article'", 'is_deleted = 0', "status = 'published'"];
        $params = [];

        if ($q !== '') {
            $whereParts[] = 'MATCH(title, content) AGAINST(:q IN NATURAL LANGUAGE MODE)';
            $params[':q'] = $q;
        }

        $whereSql = implode(' AND ', $whereParts);

        try {
            $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE {$whereSql}");
            $totalStmt->execute($params);
            $total = (int)$totalStmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('[PostController::listArticles] Count error: ' . $e->getMessage());
            http_response_code(500);
            echo 'Server error';
            exit;
        }

        $pages = max(1, (int)ceil($total / max(1, $perPage)));

        try {
            $sql = "SELECT id, title, slug, content, youtube, thumbnail, created_at
                    FROM posts
                    WHERE {$whereSql}
                    ORDER BY created_at DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);

            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }

            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[PostController::listArticles] Fetch error: ' . $e->getMessage());
            http_response_code(500);
            echo 'Server error';
            exit;
        }

        self::attach_display_images($posts, $pdo);

        $vars = [
            'posts'       => $posts,
            'results'     => $posts,
            'page'        => $page,
            'perPage'     => $perPage,
            'total'       => $total,
            'pages'       => $pages,
            'totalPages'  => $pages,
            'base'        => '/artikel/',
            'q'           => $q,
            'site_context'=> 'posts_list',
            'page_title'  => 'Artikel',
        ];

        // Respect explicit cleared assignment
        $isCleared = false;
        if (function_exists('is_assignment_cleared')) {
            try {
                $isCleared = is_assignment_cleared($pdo, 'content', 'posts_list');
            } catch (Throwable $e) {
                error_log('[PostController::listArticles] is_assignment_cleared error: ' . $e->getMessage());
                $isCleared = false;
            }
        }

        $content_html = '';

        if (!$isCleared) {
            // 1) explicit assignment candidates via render_assigned_slot
            if (function_exists('render_assigned_slot')) {
                try {
                    ob_start();
                    render_assigned_slot($pdo, 'content', 'posts_list', $vars);
                    $assigned = ob_get_clean();

                    if (trim((string)$assigned) !== '') {
                        $content_html = $assigned;
                    }
                } catch (Throwable $e) {
                    error_log('[PostController::listArticles] render_assigned_slot error: ' . $e->getMessage());
                }
            }
        }

        // 2) theme engine (active theme -> default theme handled by render_slot / resolve_template)
        if (trim($content_html) === '') {
            if (function_exists('render_slot')) {
                try {
                    $slotResult = render_slot($pdo, 'list.post', $vars);
                    if (trim((string)$slotResult) !== '') {
                        $content_html = $slotResult;
                    }
                } catch (Throwable $e) {
                    error_log('[PostController::listArticles] render_slot error: ' . $e->getMessage());
                }
            }
        }

        // 3) Explicit try DEFAULT_THEME_FOLDER (enforce default theme file before controller fallback)
        if (
            trim($content_html) === ''
            && function_exists('slot_to_file')
            && function_exists('resolve_theme_file_path')
            && function_exists('include_template_file')
            && defined('DEFAULT_THEME_FOLDER')
        ) {
            try {
                $themeFile = slot_to_file('list.post');
                $resolved = [
                    'type'         => 'theme_file',
                    'theme_folder' => DEFAULT_THEME_FOLDER,
                    'theme_file'   => $themeFile,
                ];

                $path = resolve_theme_file_path($resolved);
                if ($path) {
                    $content_html = include_template_file($path, $vars);
                }
            } catch (Throwable $e) {
                error_log('[PostController::listArticles] default-theme attempt error: ' . $e->getMessage());
            }
        }

        // 4) ultimate controller fallback (inline HTML)
        if (trim($content_html) === '') {
            ob_start();
            ?>
            <div class="container">
              <h1>Artikel (DEFAULT)</h1>

              <?php if (!empty($vars['q'])): ?>
                <p>Hasil pencarian untuk: <strong><?= htmlspecialchars((string)$vars['q'], ENT_QUOTES, 'UTF-8') ?></strong></p>
              <?php endif; ?>

              <?php if (empty($posts)): ?>
                <p>Tidak ada artikel.</p>
              <?php else: ?>
                <?php foreach ($posts as $p): ?>
                  <article class="post-item" style="margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid #eee">
                    <h2>
                      <a href="/<?= rawurlencode((string)($p['slug'] ?? '')) ?>/">
                        <?= htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                      </a>
                    </h2>

                    <div class="meta" style="color:#666;font-size:.9rem;margin-bottom:.5rem">
                      <?= htmlspecialchars((string)($p['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <?php if (!empty($p['display_image'])): ?>
                      <img
                        src="<?= htmlspecialchars((string)$p['display_image'], ENT_QUOTES, 'UTF-8') ?>"
                        alt=""
                        style="max-width:220px;display:block;margin-bottom:.6rem"
                      >
                    <?php endif; ?>

                    <p><?= htmlspecialchars(mb_strimwidth(safe_strip_tags((string)($p['content'] ?? '')), 0, 360, '…'), ENT_QUOTES, 'UTF-8') ?></p>

                    <p>
                      <a href="/<?= rawurlencode((string)($p['slug'] ?? '')) ?>/">Baca selengkapnya →</a>
                    </p>
                  </article>
                <?php endforeach; ?>

                <nav aria-label="Pagination" style="margin-top:1rem;">
                  <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($vars['base'] . '?page=' . ($page - 1), ENT_QUOTES, 'UTF-8') ?>">&larr; Sebelumnya</a>
                  <?php endif; ?>

                  &nbsp; Halaman <?= $page ?> dari <?= $pages ?> &nbsp;

                  <?php if ($page < $pages): ?>
                    <a href="<?= htmlspecialchars($vars['base'] . '?page=' . ($page + 1), ENT_QUOTES, 'UTF-8') ?>">Berikutnya &rarr;</a>
                  <?php endif; ?>
                </nav>
              <?php endif; ?>
            </div>
            <?php
            $content_html = ob_get_clean();
        }

        // expose expected layout variables
        $page_title = $vars['page_title'] ?? 'Artikel';
        $context_for_layout = 'list.post';
        $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
        $pdo = $layout_pdo;

        $themeDataSafe = (isset($themeData) && is_array($themeData)) ? $themeData : [];
        $layoutOptions = self::resolveLayoutOptions($themeDataSafe);

        $layout_full_width = $layoutOptions['layout_full_width'];
        $enable_sidebar    = $layoutOptions['enable_sidebar'];
        $sidebar_position  = $layoutOptions['sidebar_position'];

        // require layout which will echo header/footer and $content_html
        require __DIR__ . '/../layout.php';
    }

    // ---------------------
    // Enrichers
    // ---------------------

    protected static function augmentAuthor(array &$postData, PDO $pdo)
    {
        $postData['author_id'] = null;
        $postData['author_name'] = null;
        $postData['author_username'] = null;
        $postData['author_email'] = null;
        $postData['author_img'] = null;
        $postData['author_label'] = 'Penulis';

        if (!empty($postData['created_by'])) {
            try {
                $stm = $pdo->prepare("
                    SELECT id, name, username, email, img, is_deleted
                    FROM users
                    WHERE id = :id
                    LIMIT 1
                ");
                $stm->execute([':id' => (int)$postData['created_by']]);
                $author = $stm->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                error_log('[PostController] augmentAuthor DB error: ' . $e->getMessage());
                $author = null;
            }

            if (is_array($author)) {
                $postData['author_id']       = $author['id'] ?? null;
                $postData['author_name']     = $author['name'] ?? null;
                $postData['author_username'] = $author['username'] ?? null;
                $postData['author_email']    = $author['email'] ?? null;
                $postData['author_img']      = $author['img'] ?? null;
                $postData['author_label']    = $author['name']
                    ?? $author['username']
                    ?? $author['email']
                    ?? 'Penulis';
            }
        }
    }

    protected static function augmentCategories(array &$postData, PDO $pdo)
    {
        try {
            $catStmt = $pdo->prepare("
                SELECT
                    GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS category_names,
                    GROUP_CONCAT(DISTINCT c.slug ORDER BY c.name SEPARATOR ', ') AS category_slugs
                FROM post_categories pc
                JOIN categories c
                  ON c.id = pc.category_id
                 AND c.is_deleted = 0
                WHERE pc.post_id = :pid
            ");
            $catStmt->execute([':pid' => (int)$postData['id']]);

            $cats = $catStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $postData['category_names'] = $cats['category_names'] ?? '';
            $postData['category_slugs'] = $cats['category_slugs'] ?? '';
        } catch (Throwable $e) {
            error_log('[PostController] categories error: ' . $e->getMessage());
            $postData['category_names'] = '';
            $postData['category_slugs'] = '';
        }
    }

    // ---------------------
    // Single article renderer
    // ---------------------

    protected static function renderArticle(array $row, PDO $pdo)
    {
        $postData = $row;

        $postData['display_image'] = self::resolve_post_display_image($postData);
        self::augmentAuthor($postData, $pdo);
        self::augmentCategories($postData, $pdo);

        try {
            self::attach_display_image_target($postData, $pdo);
        } catch (Throwable $e) {
            error_log('[PostController::renderArticle] attach target error: ' . $e->getMessage());
            $postData['display_image_target_url'] = null;
            $postData['display_image_target_attribute'] = null;
        }

        $vars = [
            'post'         => $postData,
            'site_context' => 'posts_single',
            'page_title'   => $postData['title'] ?? 'Article',
        ];

        $content_html = '';

        //
        // 1) Per-post explicit assignment (highest priority)
        //    - render_assigned_slot() semantics are used (can return custom_post or theme_file)
        //
        if (function_exists('render_assigned_slot')) {
            try {
                ob_start();
                render_assigned_slot($pdo, 'content', 'posts_single:' . ($postData['slug'] ?? ''), $vars);
                $assigned_specific = ob_get_clean();

                if (trim((string)$assigned_specific) !== '') {
                    $content_html = $assigned_specific;
                } else {
                    ob_start();
                    render_assigned_slot($pdo, 'content', 'posts_single', $vars);
                    $assigned_generic = ob_get_clean();

                    if (trim((string)$assigned_generic) !== '') {
                        $content_html = $assigned_generic;
                    }
                }
            } catch (Throwable $e) {
                error_log('[PostController::renderArticle] render_assigned_slot error: ' . $e->getMessage());
            }
        }

        //
        // 2) Theme engine (active theme -> fallback to default theme as the theme engine semantics)
        //    - render_slot() already prefers custom_post assignment and theme file resolution
        //
        if (trim($content_html) === '') {
            if (function_exists('render_slot')) {
                try {
                    $slotResult = render_slot($pdo, 'single.post', $vars);
                    if (trim((string)$slotResult) !== '') {
                        $content_html = $slotResult;
                    }
                } catch (Throwable $e) {
                    error_log('[PostController::renderArticle] render_slot error: ' . $e->getMessage());
                }
            }
        }

        //
        // 3) Explicit: try DEFAULT_THEME_FOLDER directly
        //    - This enforces trying the default theme files (DEFAULT_THEME_FOLDER) before falling
        //      back to controller inline HTML. This matches: active theme -> default theme -> controller.
        //
        if (
            trim($content_html) === ''
            && function_exists('slot_to_file')
            && function_exists('resolve_theme_file_path')
            && function_exists('include_template_file')
            && defined('DEFAULT_THEME_FOLDER')
        ) {
            try {
                $themeFile = slot_to_file('single.post');
                $resolved = [
                    'type'         => 'theme_file',
                    'theme_folder' => DEFAULT_THEME_FOLDER,
                    'theme_file'   => $themeFile,
                ];

                $path = resolve_theme_file_path($resolved);
                if ($path) {
                    $content_html = include_template_file($path, $vars);
                }
            } catch (Throwable $e) {
                error_log('[PostController::renderArticle] default-theme attempt error: ' . $e->getMessage());
            }
        }

        //
        // 4) Ultimate controller fallback (inline HTML)
        //
        if (trim($content_html) === '') {
            ob_start();
            ?>
            <article>
              <h1><?= htmlspecialchars((string)($postData['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
              <div><?= $postData['content'] ?? '' ?></div>
            </article>
            <?php
            $content_html = ob_get_clean();
        }

        // canonical (optional)
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $canonical_url = $scheme . '://' . $host . '/' . rawurlencode((string)($postData['slug'] ?? '')) . '/';

        // expose to layout
        $page_title = $vars['page_title'] ?? 'Article';
        $context_for_layout = 'single.post';
        $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
        $pdo = $layout_pdo;

        $themeDataSafe = (isset($themeData) && is_array($themeData)) ? $themeData : [];
        $layoutOptions = self::resolveLayoutOptions($themeDataSafe);

        $layout_full_width = $layoutOptions['layout_full_width'];
        $enable_sidebar    = $layoutOptions['enable_sidebar'];
        $sidebar_position  = $layoutOptions['sidebar_position'];

        // layout.php expected to use $content_html, $page_title, $context_for_layout, $canonical_url
        require __DIR__ . '/../layout.php';
    }
}