<?php
// controllers/ArchiveController.php

class ArchiveController
{
    /**
     * Local view loader: checks possible app-level view locations.
     */
    private static function render_local_view(string $filename, array $vars = []): string
    {
        $paths = [
            __DIR__ . '/../views/archive/' . $filename,
            __DIR__ . '/../views/' . $filename,
            __DIR__ . '/../views/posts/' . $filename,
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                ob_start();
                extract($vars, EXTR_SKIP);
                include $path;
                return ob_get_clean();
            }
        }

        return '';
    }

    /**
     * Show posts by year or year+month.
     *
     * @param PDO $pdo
     * @param int $year
     * @param int|null $month
     * @param int $page
     * @param string|null $basePathPrefix optional prefix like 'archive' or null for root (/2025/)
     */
    public static function show(PDO $pdo, int $year, ?int $month = null, int $page = 1, ?string $basePathPrefix = 'archive')
    {
        // ensure local $pdo for layout.php
        $pdo_local = $pdo;
        $pdo = $pdo_local;

        $year = (int)$year;
        $month = $month ? (int)$month : null;
        $page = max(1, (int)$page);

        // Accept query param if router didn't supply a path page (common case for /2025/?page=2)
        $qsPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        if ($page <= 1 && $qsPage) {
            $page = $qsPage;
        }

        $perPage = 10;
        $offset = ($page - 1) * $perPage;

        if ($year < 1900) {
            http_response_code(404);
            require __DIR__ . '/../frontend_404.php';
            exit;
        }

        $isLoggedIn = !empty($_SESSION['user_id']);

        // build where clauses
        $where = ["p.type = 'article'", "p.is_deleted = 0"];
        $params = [];
        $where[] = "YEAR(p.created_at) = :yr";
        $params[':yr'] = $year;
        if ($month !== null) {
            $where[] = "MONTH(p.created_at) = :mo";
            $params[':mo'] = $month;
        }
        if (!$isLoggedIn) $where[] = "p.status = 'published'";

        $whereSql = implode(' AND ', $where);

        // count
        $countSql = "SELECT COUNT(*) FROM posts p WHERE $whereSql";
        try {
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("[ArchiveController::show] count error: " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        // fetch posts
        try {
            $sql = "SELECT p.id, p.title, p.slug, p.content, p.thumbnail, p.youtube, p.created_at
                    FROM posts p
                    WHERE $whereSql
                    ORDER BY p.created_at DESC
                    LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("[ArchiveController::show] fetch error: " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        // Attach display images (youtube/thumb) if helper available
        if (!class_exists('PostController')) {
            $maybe = __DIR__ . '/PostController.php';
            if (file_exists($maybe)) {
                require_once $maybe;
            }
        }
        if (class_exists('PostController') && method_exists('PostController', 'attach_display_images')) {
            try {
                PostController::attach_display_images($posts);
            } catch (Throwable $e) {
                error_log("[ArchiveController] attach_display_images error: " . $e->getMessage());
            }
        }

        // build base path for pagination and canonical
        if ($basePathPrefix === null) {
            // WordPress-like root: /2025/ or /2025/11/
            if ($month !== null) {
                $basePath = sprintf('/%04d/%02d/', $year, $month);
            } else {
                $basePath = sprintf('/%04d/', $year);
            }
        } else {
            // prefixed: /archive/2025/ or /archive/2025/11/
            if ($month !== null) {
                $basePath = sprintf('/%s/%04d/%02d/', trim($basePathPrefix, '/'), $year, $month);
            } else {
                $basePath = sprintf('/%s/%04d/', trim($basePathPrefix, '/'), $year);
            }
        }

        // build absolute base URL for pagination and canonical
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $scheme . '://' . $host . $basePath;

        // canonical: always point to basePath (page 1)
        $canonical_url = $baseUrl;

        // rel prev / next: only set when applicable
        $rel_prev = null;
        $rel_next = null;

        if ($page > 1) {
            $prevPage = $page - 1;
            // previous page: base (page1) or with ?page=N
            $rel_prev = $baseUrl . ($prevPage === 1 ? '' : '?page=' . $prevPage);
        }

        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $rel_next = $baseUrl . '?page=' . $nextPage;
        }

        // prepare vars for templates/slots
        $vars = [
            'year' => $year,
            'month' => $month,
            'posts' => $posts,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'site_context' => 'posts_list',
            'basePath' => $basePath, // expose for templates
        ];

        // ---- RESOLUTION / FALLBACK CHAIN ----
        // 1) explicit assignment (most specific: per-month, per-year, generic)
        // 2) theme engine slots (list.archive -> posts_list -> list.post)
        // 3) legacy theme partials
        // 4) local view files (views/archive/list.php, views/posts/list.php)
        // 5) inline controller fallback

        $content_html = '';

        // Respect explicit cleared assignment
        $isCleared = false;
        if (function_exists('is_assignment_cleared')) {
            try {
                $isCleared = is_assignment_cleared($pdo, 'content', 'posts_list');
            } catch (Throwable $e) {
                if (defined('THEME_DEBUG') && THEME_DEBUG) error_log("[ArchiveController::show] is_assignment_cleared error: " . $e->getMessage());
                $isCleared = false;
            }
        }

        // 1) assigned slots (if not cleared)
        if (!$isCleared && function_exists('render_assigned_slot')) {
            try {
                // most specific: posts_list:archive-YYYY-MM
                if ($month !== null) {
                    $slotSpecificMonth = 'posts_list:archive-' . $year . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT);
                    ob_start();
                    render_assigned_slot($pdo, 'content', $slotSpecificMonth, $vars);
                    $slotOut = ob_get_clean();
                    if (trim($slotOut) !== '') $content_html = $slotOut;
                }

                // less specific: posts_list:archive-YYYY
                if (trim($content_html) === '') {
                    $slotSpecificYear = 'posts_list:archive-' . $year;
                    ob_start();
                    render_assigned_slot($pdo, 'content', $slotSpecificYear, $vars);
                    $slotOut = ob_get_clean();
                    if (trim($slotOut) !== '') $content_html = $slotOut;
                }

                // generic posts_list
                if (trim($content_html) === '') {
                    ob_start();
                    render_assigned_slot($pdo, 'content', 'posts_list', $vars);
                    $slotOut = ob_get_clean();
                    if (trim($slotOut) !== '') $content_html = $slotOut;
                }
            } catch (Throwable $e) {
                error_log("[ArchiveController::show] render_assigned_slot error: " . $e->getMessage());
            }
        }

        // 2) theme engine slots (if not cleared and still empty)
        if (trim($content_html) === '' && !$isCleared && function_exists('render_slot')) {
            try {
                $s = render_slot($pdo, 'list.archive', $vars);
                if (trim((string)$s) !== '') {
                    $content_html = $s;
                } else {
                    $s2 = render_slot($pdo, 'posts_list', $vars);
                    if (trim((string)$s2) !== '') $content_html = $s2;
                    else {
                        $s3 = render_slot($pdo, 'list.post', $vars);
                        if (trim((string)$s3) !== '') $content_html = $s3;
                    }
                }
            } catch (Throwable $e) {
                error_log("[ArchiveController::show] render_slot error: " . $e->getMessage());
            }
        }

        // 3) legacy theme partials (include_theme_partial) if still empty
        if (trim($content_html) === '' && !$isCleared && function_exists('include_theme_partial')) {
            try {
                ob_start();
                include_theme_partial('main/list/archive', $vars);
                $tp = ob_get_clean();
                if (trim($tp) !== '') $content_html = $tp;
            } catch (Throwable $e) {
                error_log("[ArchiveController::show] include_theme_partial error: " . $e->getMessage());
            }
        }

        // 4) local app-level view files
        if (trim($content_html) === '') {
            $local = self::render_local_view('list.php', $vars); // views/archive/list.php or views/posts/list.php
            if (trim($local) !== '') $content_html = $local;
        }

        // 5) ultimate inline fallback
        if (trim($content_html) === '') {
            ob_start();
            ?>
            <div class="container">
              <h1><?= htmlspecialchars($month ? date('F', mktime(0,0,0,$month,1,$year)) . ' ' . $year : 'Arsip — ' . $year, ENT_QUOTES, 'UTF-8') ?></h1>

              <?php if (empty($posts)): ?>
                <p>Tidak ada artikel.</p>
              <?php else: ?>
                <?php foreach ($posts as $p): ?>
                  <article style="margin-bottom:1.2rem">
                    <h2><a href="<?= htmlspecialchars(function_exists('get_post_permalink') ? get_post_permalink($p) : '/' . rawurlencode($p['slug']) . '/', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></a></h2>
                    <div style="color:#666"><?= htmlspecialchars($p['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                    <p><?= htmlspecialchars(mb_strimwidth(safe_strip_tags($p['content']), 0, 360, '…'), ENT_QUOTES, 'UTF-8') ?></p>
                  </article>
                <?php endforeach; ?>

                <nav aria-label="Pagination">
                  <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($basePath . ($page-1 === 1 ? '' : '?page=' . ($page-1)), ENT_QUOTES, 'UTF-8') ?>">&larr; Sebelumnya</a>
                  <?php endif; ?>
                  &nbsp; Halaman <?= $page ?> dari <?= $totalPages ?> &nbsp;
                  <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars($basePath . '?page=' . ($page+1), ENT_QUOTES, 'UTF-8') ?>">Berikutnya &rarr;</a>
                  <?php endif; ?>
                </nav>
              <?php endif; ?>
            </div>
            <?php
            $content_html = ob_get_clean();
        }

        // expose canonical and rel prev/next and layout vars
        $page_title = $month ? (date('F', mktime(0,0,0,$month,1,$year)) . ' ' . $year) : ('Arsip — ' . $year);
        $context_for_layout = 'archive';
        $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
        $pdo = $layout_pdo;

        // make canonical & rel available to layout (use globals to be safe)
        $GLOBALS['canonical_url'] = $canonical_url;
        if ($rel_prev) $GLOBALS['rel_prev'] = $rel_prev;
        if ($rel_next) $GLOBALS['rel_next'] = $rel_next;

        require __DIR__ . '/../layout.php';
        exit;
    }
}
