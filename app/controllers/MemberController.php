<?php
declare(strict_types=1);

class MemberController
{
    public static function profile(PDO $pdo, string $username): void
    {
        $stmt = $pdo->prepare(
            "SELECT id, username, display_name, bio, avatar, website, location, registered_at
             FROM members WHERE username = :u AND is_banned = 0 AND is_deleted = 0"
        );
        $stmt->execute([':u' => $username]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            http_response_code(404);
            $context_for_layout = '404';
            require __DIR__ . '/../layout.php';
            exit;
        }

        // Plugin count
        $cntStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM plugins WHERE member_id = :id AND status = 'approved' AND is_deleted = 0"
        );
        $cntStmt->execute([':id' => $member['id']]);
        $pluginCount = (int)$cntStmt->fetchColumn();

        // Recent plugins
        $plugins = $pdo->prepare(
            "SELECT slug, title, excerpt, icon, total_downloads, avg_rating, total_ratings, created_at
             FROM plugins WHERE member_id = :id AND status = 'approved' AND is_deleted = 0
             ORDER BY created_at DESC LIMIT 6"
        );
        $plugins->execute([':id' => $member['id']]);
        $plugins = $plugins->fetchAll(PDO::FETCH_ASSOC);

        $page_title = ($member['display_name'] ?: $member['username']) . ' — Member';
        $context_for_layout = 'member';

        ob_start(); ?>
        <section class="page-member" style="max-width:720px;margin:2rem auto;padding:0 1rem">
            <div style="display:flex;gap:1.25rem;align-items:start;flex-wrap:wrap">
                <!-- Avatar -->
                <div style="flex-shrink:0">
                    <?php if ($member['avatar']): ?>
                    <img src="<?= htmlspecialchars($member['avatar']) ?>" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover">
                    <?php else: ?>
                    <div style="width:96px;height:96px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:2rem;color:#9ca3af">
                        <?= htmlspecialchars(mb_strtoupper(mb_substr($member['display_name'] ?: $member['username'], 0, 1))) ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div style="flex:1;min-width:200px">
                    <h1 style="font-size:1.5rem;margin:0 0 .25rem"><?= htmlspecialchars($member['display_name'] ?: $member['username']) ?></h1>
                    <p style="color:#6b7280;font-size:.85rem;margin:0 0 .5rem">@<?= htmlspecialchars($member['username']) ?></p>

                    <?php if ($member['bio']): ?>
                    <p style="color:#374151;line-height:1.6;font-size:.92rem;margin:.5rem 0"><?= nl2br(htmlspecialchars($member['bio'])) ?></p>
                    <?php endif; ?>

                    <div style="display:flex;gap:1rem;font-size:.85rem;color:#6b7280;flex-wrap:wrap;margin-top:.75rem">
                        <?php if ($member['location']): ?><span>📍 <?= htmlspecialchars($member['location']) ?></span><?php endif; ?>
                        <?php if ($member['website']): ?><span>🌐 <a href="<?= htmlspecialchars($member['website']) ?>" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:none"><?= htmlspecialchars($member['website']) ?></a></span><?php endif; ?>
                        <span>📅 Bergabung <?= date('d M Y', strtotime($member['registered_at'])) ?></span>
                        <span>📦 <?= $pluginCount ?> plugin</span>
                    </div>
                </div>
            </div>

            <!-- Plugin list -->
            <?php if (!empty($plugins)): ?>
            <div style="margin-top:2rem">
                <h2 style="font-size:1.15rem;margin:0 0 .75rem">Plugin</h2>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.75rem">
                    <?php foreach ($plugins as $p): ?>
                    <a href="/plugins/<?= rawurlencode($p['slug']) ?>/" style="border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;text-decoration:none;color:inherit;transition:box-shadow .15s"
                       onmouseover="this.style.boxShadow='0 2px 6px rgba(0,0,0,.06)'" onmouseout="this.style.boxShadow='none'">
                        <strong style="font-size:.9rem"><?= htmlspecialchars($p['title']) ?></strong>
                        <?php if ($p['excerpt']): ?>
                        <p style="margin:.25rem 0 0;font-size:.8rem;color:#6b7280;line-height:1.4"><?= htmlspecialchars(mb_strimwidth($p['excerpt'], 0, 80, '…')) ?></p>
                        <?php endif; ?>
                        <div style="font-size:.75rem;color:#9ca3af;margin-top:.35rem">
                            ⬇ <?= number_format((int)$p['total_downloads']) ?> &middot; ★ <?= number_format((float)$p['avg_rating'], 1) ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($pluginCount > 6): ?>
                <p style="margin-top:.75rem"><a href="/member/<?= rawurlencode($member['username']) ?>/plugins/" style="color:#2563eb;font-size:.9rem">Lihat semua plugin (<?= $pluginCount ?>) →</a></p>
                <?php endif; ?>
            </div>
            <?php elseif ($pluginCount === 0): ?>
            <div style="margin-top:2rem;padding:1.5rem;background:#f9fafb;border-radius:10px;text-align:center;color:#6b7280">
                <p>Belum punya plugin.</p>
            </div>
            <?php endif; ?>
        </section>
        <?php
        $content_html = (string)ob_get_clean();
        require __DIR__ . '/../layout.php';
        exit;
    }

    public static function plugins(PDO $pdo, string $username): void
    {
        $stmt = $pdo->prepare(
            "SELECT id, username, display_name FROM members WHERE username = :u AND is_banned = 0 AND is_deleted = 0"
        );
        $stmt->execute([':u' => $username]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            http_response_code(404);
            $context_for_layout = '404';
            require __DIR__ . '/../layout.php';
            exit;
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 12;

        $cntStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM plugins WHERE member_id = :id AND status = 'approved' AND is_deleted = 0"
        );
        $cntStmt->execute([':id' => $member['id']]);
        $total = (int)$cntStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $plugins = $pdo->prepare(
            "SELECT slug, title, excerpt, icon, total_downloads, avg_rating, total_ratings, created_at
             FROM plugins WHERE member_id = :id AND status = 'approved' AND is_deleted = 0
             ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $plugins->execute([':id' => $member['id']]);
        $plugins = $plugins->fetchAll(PDO::FETCH_ASSOC);

        $page_title = ($member['display_name'] ?: $member['username']) . ' — Plugins';
        $context_for_layout = 'member-plugins';

        ob_start(); ?>
        <section class="page-member-plugins" style="max-width:720px;margin:2rem auto;padding:0 1rem">
            <p style="margin:0 0 .5rem;font-size:.85rem">
                <a href="/member/<?= rawurlencode($member['username']) ?>/" style="color:#2563eb;text-decoration:none">← Profil <?= htmlspecialchars($member['display_name'] ?: $member['username']) ?></a>
            </p>
            <h1 style="font-size:1.4rem;margin:0 0 .25rem">Plugin oleh <?= htmlspecialchars($member['display_name'] ?: $member['username']) ?></h1>
            <p style="color:#6b7280;font-size:.9rem;margin:0 0 1.25rem"><?= $total ?> plugin</p>

            <?php if (empty($plugins)): ?>
            <p style="color:#6b7280">Belum ada plugin.</p>
            <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.75rem">
                <?php foreach ($plugins as $p): ?>
                <a href="/plugins/<?= rawurlencode($p['slug']) ?>/" style="border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;text-decoration:none;color:inherit;transition:box-shadow .15s"
                   onmouseover="this.style.boxShadow='0 2px 6px rgba(0,0,0,.06)'" onmouseout="this.style.boxShadow='none'">
                    <strong style="font-size:.9rem"><?= htmlspecialchars($p['title']) ?></strong>
                    <?php if ($p['excerpt']): ?>
                    <p style="margin:.25rem 0 0;font-size:.8rem;color:#6b7280;line-height:1.4"><?= htmlspecialchars(mb_strimwidth($p['excerpt'], 0, 80, '…')) ?></p>
                    <?php endif; ?>
                    <div style="font-size:.75rem;color:#9ca3af;margin-top:.35rem">
                        ⬇ <?= number_format((int)$p['total_downloads']) ?> &middot; ★ <?= number_format((float)$p['avg_rating'], 1) ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
            <nav style="display:flex;justify-content:center;gap:.35rem;margin-top:1.5rem">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="/member/<?= rawurlencode($member['username']) ?>/plugins/?page=<?= $i ?>"
                   style="padding:.3rem .65rem;border:1px solid #d1d5db;border-radius:4px;text-decoration:none;color:<?= $i === $page ? '#fff' : '#374151' ?>;background:<?= $i === $page ? '#2563eb' : '#fff' ?>;font-size:.85rem">
                   <?= $i ?>
                </a>
                <?php endfor; ?>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
        $content_html = (string)ob_get_clean();
        require __DIR__ . '/../layout.php';
        exit;
    }
}
