<?php
// controllers/SearchController.php

class SearchController
{
    protected static function render_local_view(string $filename, array $vars = []): string
    {
        $paths = [
            PUBLIC_PATH . '/views/' . $filename,
            PUBLIC_PATH . '/views/search/' . $filename,
            PUBLIC_PATH . '/views/posts/' . $filename,
            PUBLIC_PATH . '/views/main/list/' . $filename,
        ];
        foreach ($paths as $p) {
            if (is_file($p)) {
                ob_start();
                extract($vars, EXTR_SKIP);
                include $p;
                return ob_get_clean();
            }
        }
        return '';
    }

    public static function search(PDO $pdo, string $q, int $page = 1)
    {
        $q = trim((string)$q);
        if ($q === '') {
            header('Location: /');
            exit;
        }

        // =====================================================
        // ENSURE THEME ENGINE LOADED (karena root index.php hanya bootstrap_core)
        // =====================================================
        $root = realpath(__DIR__ . '/..'); // controllers/.. = root web
        if ($root) {
            $btTheme = $root . '/bootstrap_theme.php';
            if (is_file($btTheme)) {
                require_once $btTheme;
            }
            $helper = $root . '/../cfg/helpers/theme_helper.php';
            if (!function_exists('render_slot') && is_file($helper)) {
                require_once $helper;
            }
        }

        // ensure PostController available for attach_display_images
        if (!class_exists('PostController') && is_file(__DIR__ . '/PostController.php')) {
            require_once __DIR__ . '/PostController.php';
        }

        $perPage = 10;
        $page = max(1, (int)$page);
        $offset = ($page - 1) * $perPage;

        // Query constraints: only article, published, not deleted (0 or NULL)
        $where = [
            "type = 'article'",
            "(is_deleted = 0 OR is_deleted IS NULL)",
            "status = 'published'",
            "(title LIKE :kw OR content LIKE :kw)"
        ];
        $params = [':kw' => '%' . $q . '%'];
        $whereSql = implode(' AND ', $where);

        try {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE {$whereSql}");
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();
        } catch (Throwable $e) {
            error_log('[SearchController] count error: ' . $e->getMessage());
            http_response_code(500);
            exit('Server error');
        }

        $totalPages = max(1, (int)ceil($total / max(1, $perPage)));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        try {
            $sql = "
                SELECT id, title, slug, content, youtube, thumbnail, created_at
                FROM posts
                WHERE {$whereSql}
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset
            ";
            $stm = $pdo->prepare($sql);
            foreach ($params as $k => $v) $stm->bindValue($k, $v);
            $stm->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stm->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stm->execute();
            $results = $stm->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[SearchController] fetch error: ' . $e->getMessage());
            http_response_code(500);
            exit('Server error');
        }

        // Untuk kompatibilitas template list.post: gunakan key "posts"
        $posts = $results;

        if (class_exists('PostController') && method_exists('PostController', 'attach_display_images')) {
            try {
                PostController::attach_display_images($posts, $pdo);
            } catch (Throwable $e) {
                error_log('[SearchController] attach_display_images error: ' . $e->getMessage());
            }
        }

        $qEsc = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
        $baseUrl = '/?s=' . urlencode($q);

        $vars = [
            // compat (tema/list biasanya pakai "posts")
            'posts'       => $posts,
            // keep original
            'results'     => $posts,

            'total'       => $total,
            'page'        => $page,
            'perPage'     => $perPage,
            'pages'       => $totalPages,
            'totalPages'  => $totalPages,

            'q'           => $q,
            'qEsc'        => $qEsc,
            'base'        => $baseUrl,

            'is_search'   => true,
            'site_context'=> 'posts_list',
        ];

        // canonical (layout.php membaca $canonical_url)
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $canonical_url = $scheme . '://' . $host . '/?s=' . urlencode($q) . ($page > 1 ? '&page=' . $page : '');

        $page_title = 'Pencarian: ' . $qEsc;

        // ==========================================
        // CARA 1: SEARCH punyai konteks sendiri
        // => layout akan render main.search
        // ==========================================
        $context_for_layout = 'search';

        $content_html = '';

        // 1) Theme slot utama search (main.search)
        if (function_exists('render_slot')) {
            try {
                $content_html = trim((string)render_slot($pdo, 'main.search', $vars));
            } catch (Throwable $e) {
                error_log('[SearchController] render_slot(main.search) error: ' . $e->getMessage());
                $content_html = '';
            }

            // 2) fallback ke list.post jika belum ada template search
            if ($content_html === '') {
                try {
                    $content_html = trim((string)render_slot($pdo, 'list.post', $vars));
                } catch (Throwable $e) {
                    error_log('[SearchController] render_slot(list.post) error: ' . $e->getMessage());
                    $content_html = '';
                }
            }
        }

        // 3) fallback local view (opsional)
        if (trim($content_html) === '') {
            $local = self::render_local_view('search.php', $vars);
            if (trim($local) !== '') $content_html = $local;
        }
        if (trim($content_html) === '') {
            $local2 = self::render_local_view('list.php', $vars);
            if (trim($local2) !== '') $content_html = $local2;
        }

        // 4) ultimate controller fallback (inline HTML)
        if (trim($content_html) === '') {
            ob_start(); ?>
            <section class="search-results">
                <h1>Hasil pencarian: “<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>”</h1>
                <p><?= (int)$total ?> hasil ditemukan.</p>

                <?php if (empty($posts)): ?>
                    <p>Tidak ada hasil.</p>
                <?php else: foreach ($posts as $p): ?>
                    <article style="margin-bottom:1rem;padding:1rem;border:1px solid #eee;border-radius:8px">
                        <h2>
                            <a href="<?= htmlspecialchars(function_exists('get_post_permalink') ? get_post_permalink($p) : '/' . rawurlencode((string)($p['slug'] ?? '')) . '/', ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </h2>
                        <time datetime="<?= htmlspecialchars((string)($p['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(date('d M Y', strtotime((string)($p['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8') ?>
                        </time>
                        <p><?= htmlspecialchars(mb_strimwidth(safe_strip_tags((string)($p['content'] ?? '')), 0, 250, '…'), ENT_QUOTES, 'UTF-8') ?></p>
                    </article>
                <?php endforeach; endif; ?>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="<?= htmlspecialchars($baseUrl . '&page=' . $i, ENT_QUOTES, 'UTF-8') ?>"
                               <?= $i == $page ? 'aria-current="page"' : '' ?>>
                               <?= $i ?>
                            </a>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>
            </section>
            <?php
            $content_html = ob_get_clean();
        }

        // Expose to layout.php (layout akan echo $content_html)
        require __DIR__ . '/../layout.php';
        exit;
    }
}
