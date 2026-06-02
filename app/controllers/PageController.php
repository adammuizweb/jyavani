<?php
// controllers/PageController.php

class PageController
{
    /**
     * Ambil page dari database berdasarkan slug
     */
    private static function fetchPage(PDO $pdo, string $slug): ?array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT id, title, slug, content, created_at, updated_at, created_by, status
                FROM posts
                WHERE slug = :slug 
                  AND type = 'page'
                  AND is_deleted = 0
                LIMIT 1
            ");
            $stmt->execute([':slug' => $slug]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);
            return $page ?: null;
        } catch (Throwable $e) {
            error_log("[PageController::fetchPage] " . $e->getMessage());
            return null;
        }
    }

    /**
     * Tambahkan informasi author (opsional)
     */
    private static function augmentAuthor(array &$pageData, PDO $pdo): void
    {
        $pageData['author_id'] = $pageData['author_id'] ?? null;
        $pageData['author_name'] = $pageData['author_name'] ?? null;
        $pageData['author_username'] = $pageData['author_username'] ?? null;
        $pageData['author_email'] = $pageData['author_email'] ?? null;
        $pageData['author_img'] = $pageData['author_img'] ?? null;
        $pageData['author_label'] = $pageData['author_label'] ?? 'Penulis';

        if (empty($pageData['created_by'])) {
            return;
        }

        try {
            $stm = $pdo->prepare("
                SELECT id, name, username, email, img, is_deleted
                FROM users
                WHERE id = :id
                LIMIT 1
            ");
            $stm->execute([':id' => (int)$pageData['created_by']]);
            $author = $stm->fetch(PDO::FETCH_ASSOC) ?: null;

            if (is_array($author)) {
                $pageData['author_id']       = $author['id'] ?? $pageData['author_id'];
                $pageData['author_name']     = $author['name'] ?? $pageData['author_name'];
                $pageData['author_username'] = $author['username'] ?? $pageData['author_username'];
                $pageData['author_email']    = $author['email'] ?? $pageData['author_email'];
                $pageData['author_img']      = $author['img'] ?? $pageData['author_img'];
                $pageData['author_label']    = $author['name']
                                                ?? $author['username']
                                                ?? $author['email']
                                                ?? $pageData['author_label'];
            }
        } catch (Throwable $e) {
            error_log("[PageController::augmentAuthor] " . $e->getMessage());
        }
    }

    /**
     * ============================
     *  LIST of PAGES: /halaman/
     * ============================
     *
     * @param PDO $pdo
     * @param int $page (1-based)
     * @param string $q optional search query
     */
    public static function listPages(PDO $pdo, int $page = 1, string $q = '')
    {
        $perPage = 10;
        $page = max(1, (int)$page);
        $offset = ($page - 1) * $perPage;

        $isLoggedIn = !empty($_SESSION['user_id'] ?? null);

        $where = ["type = 'page'", "is_deleted = 0"];
        $params = [];

        if (!$isLoggedIn) {
            $where[] = "status = 'published'";
        }

        if ($q !== '') {
            $where[] = "MATCH(title,content) AGAINST(:q IN NATURAL LANGUAGE MODE)";
            $params[':q'] = $q;
        }

        $whereSql = implode(' AND ', $where);

        try {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("[PageController::listPages] Count error: " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        try {
            $sql = "SELECT id, title, slug, content, created_at, status
                    FROM posts
                    WHERE {$whereSql}
                    ORDER BY created_at DESC
                    LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $pagesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("[PageController::listPages] Fetch error: " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        // prepare variables for templates
        $vars = [
            'pages' => $pagesRows,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages_total' => $pages,
            'base' => function_exists('get_pages_list_base') ? get_pages_list_base($pdo) : '/halaman/',
            'site_context' => 'pages_list',
            'q' => $q,
        ];

        // Respect explicit cleared assignment
        $isCleared = false;
        if (function_exists('is_assignment_cleared')) {
            try {
                $isCleared = is_assignment_cleared($pdo, 'content', 'pages_list');
            } catch (Throwable $e) {
                $isCleared = false;
            }
        }

        $content_html = '';

        // 1) assigned slot candidates via render_assigned_slot (highest priority)
        if (!$isCleared && function_exists('render_assigned_slot')) {
            try {
                ob_start();
                render_assigned_slot($pdo, 'content', 'pages_list', $vars);
                $assigned = ob_get_clean();
                if (trim($assigned) !== '') $content_html = $assigned;
            } catch (Throwable $e) {
                error_log("[PageController::listPages] render_assigned_slot error: " . $e->getMessage());
            }
        }

        // 2) theme engine slot: prefer 'list.page' then 'list.post'
        if (trim($content_html) === '' && !$isCleared && function_exists('render_slot')) {
            try {
                $slotHtml = render_slot($pdo, 'list.page', $vars);
                if (trim((string)$slotHtml) !== '') {
                    $content_html = $slotHtml;
                } else {
                    $slotHtml2 = render_slot($pdo, 'list.post', $vars);
                    if (trim((string)$slotHtml2) !== '') $content_html = $slotHtml2;
                }
            } catch (Throwable $e) {
                error_log("[PageController::listPages] render_slot error: " . $e->getMessage());
            }
        }

// 3) Explicit: try DEFAULT_THEME_FOLDER directly (default theme file)
if (trim($content_html) === '') {
    try {
        $themeFile = slot_to_file('list.page');
        $resolved = [
            'type' => 'theme_file',
            'theme_folder' => DEFAULT_THEME_FOLDER,
            'theme_file' => $themeFile
        ];
        $path = resolve_theme_file_path($resolved);
        if ($path) {
            $content_html = include_template_file($path, $vars);
        }
    } catch (Throwable $e) {
        error_log("[PageController::listPages] default-theme attempt error: " . $e->getMessage());
    }
}

        // 4) inline fallback
        if (trim($content_html) === '') {
            ob_start();
            ?>
            <div class="container">
              <h1>Halaman</h1>
              <?php if (empty($pagesRows)): ?>
                <p>Tidak ada halaman.</p>
              <?php else: ?>
                <?php foreach ($pagesRows as $p): ?>
                  <article style="margin-bottom:1.25rem">
                    <h2><a href="<?= htmlspecialchars(function_exists('get_page_permalink') ? get_page_permalink($p) : '/' . rawurlencode($p['slug']) . '/', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></a></h2>
                    <div style="color:#666;font-size:.9rem"><?= htmlspecialchars($p['created_at'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($p['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                    <p><?= htmlspecialchars(mb_strimwidth(safe_strip_tags($p['content']), 0, 360, '…'), ENT_QUOTES, 'UTF-8') ?></p>
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

// expose to layout
$page_title = 'Halaman';
$context_for_layout = 'list.page';
$layout_pdo = $GLOBALS['pdo'] ?? $pdo;
$pdo = $layout_pdo;

        // ==============================
        // LAYOUT OVERRIDE (INI TEMPATNYA)
        // ==============================
        // Default layout policy di layout.php:
        // - homepage: full width + sidebar off
        // - selain homepage: container + sidebar on
        //
        // Kalau theme-page kamu mau beda, set di sini:

        $layout_full_width = false;   // paksa pakai container (jadi tidak full width)
        $enable_sidebar    = true;    // paksa sidebar aktif
        $sidebar_position  = 'right';  // 'left' atau 'right'

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
        
        // END SIDEBAR

        // ensure layout picks up $content_html
        require __DIR__ . '/../layout.php';
        exit;
    }

    /**
     * Render single page (uses same ordering as PostController):
     * 1) custom assignment from DB (posts.type='theme')
     * 2) assigned slot (can reference other themes)
     * 3) theme engine (active -> default) via render_slot()
     * 4) local /views/pages/single.php
     * 5) controller inline fallback
     */
    public static function renderPage(PDO $pdo, string $slug)
    {
        $slug = trim($slug);
        if ($slug === '') {
            http_response_code(404);
            echo "Page Not Found";
            exit;
        }

        $pageData = self::fetchPage($pdo, $slug);

        if (!$pageData) {
            http_response_code(404);
            echo "<h1>404 — Page Not Found</h1>";
            exit;
        }

        // Perkaya data (author, dll)
        self::augmentAuthor($pageData, $pdo);

        // Konsisten context untuk template: provide both 'page' and 'post'
        $vars = [
            'page'         => $pageData,
            'post'         => $pageData,
            'site_context' => 'pages_single',
            'page_title'   => $pageData['title'] ?? 'Page',
        ];

        $content_html = '';

        // Respect explicit cleared assignment
        $isCleared = false;
        if (function_exists('is_assignment_cleared')) {
            try {
                $isCleared = is_assignment_cleared($pdo, 'content', 'pages_single');
            } catch (Throwable $e) {
                error_log("[PageController::renderPage] is_assignment_cleared error: " . $e->getMessage());
                $isCleared = false;
            }
        }

        // 1) assigned slot (highest: per-page, then generic)
        if (!$isCleared && function_exists('render_assigned_slot')) {
            try {
                ob_start();
                render_assigned_slot($pdo, 'content', 'pages_single:' . $slug, $vars);
                $assigned_specific = ob_get_clean();
                if (trim($assigned_specific) !== '') {
                    $content_html = $assigned_specific;
                } else {
                    ob_start();
                    render_assigned_slot($pdo, 'content', 'pages_single', $vars);
                    $assigned_generic = ob_get_clean();
                    if (trim($assigned_generic) !== '') {
                        $content_html = $assigned_generic;
                    }
                }
            } catch (Throwable $e) {
                error_log("[PageController::renderPage] render_assigned_slot error: " . $e->getMessage());
            }
        }

        // 2) theme engine: use render_slot for 'single.page' so theme active/default used
        if (trim($content_html) === '' && function_exists('render_slot')) {
            try {
                $slotResult = render_slot($pdo, 'single.page', $vars);
                if (trim((string)$slotResult) !== '') {
                    $content_html = $slotResult;
                }
            } catch (Throwable $e) {
                error_log("[PageController::renderPage] render_slot error: " . $e->getMessage());
            }
        }

// 3) Explicit: try DEFAULT_THEME_FOLDER directly (default theme file)
if (trim($content_html) === '') {
    try {
        $themeFile = slot_to_file('single.page');
        $resolved = [
            'type' => 'theme_file',
            'theme_folder' => DEFAULT_THEME_FOLDER,
            'theme_file' => $themeFile
        ];
        $path = resolve_theme_file_path($resolved);
        if ($path) {
            $content_html = include_template_file($path, $vars);
        }
    } catch (Throwable $e) {
        error_log("[PageController::renderPage] default-theme attempt error: " . $e->getMessage());
    }
}

        // 4) controller fallback
        if (trim($content_html) === '') {
            ob_start();
            ?>
            <article>
              <h1><?= htmlspecialchars($pageData['title'] ?? 'Page', ENT_QUOTES, 'UTF-8') ?></h1>
              <div><?= $pageData['content'] ?? '' ?></div>
            </article>
            <?php
            $content_html = ob_get_clean();
        }

        // expose layout variables
        $page_title = $vars['page_title'] ?? ($pageData['title'] ?? 'Page');
        $context_for_layout = 'single.page';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $canonical_url = $scheme . '://' . $host . (function_exists('get_page_permalink') ? get_page_permalink($pageData) : '/' . rawurlencode($pageData['slug'] ?? '') . '/');
        $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
        $pdo = $layout_pdo;
        
        // ==============================
        // LAYOUT OVERRIDE (INI TEMPATNYA)
        // ==============================
        // Default layout policy di layout.php:
        // - homepage: full width + sidebar off
        // - selain homepage: container + sidebar on
        //
        // Kalau theme-page kamu mau beda, set di sini:

        $layout_full_width = false;   // paksa pakai container (jadi tidak full width)
        $enable_sidebar    = true;     // sidebar aktif default (bisa diatur via Settings > Sidebar)
        $sidebar_position  = 'right';  // 'left' atau 'right'

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
        
        // END SIDEBAR

        $post = $pageData;

        require __DIR__ . '/../layout.php';
        exit;
    }
}
