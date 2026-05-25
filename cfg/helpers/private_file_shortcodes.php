<?php
declare(strict_types=1);

// cfg/helpers/private_file_shortcodes.php
// Shortcode:
// [private_pdf id="123"]
// [private_pdf id="123" mode="embed"]
// [private_pdf id="123" mode="card"]
// [private_file id="123" mode="button"]

if (!function_exists('private_file_sc_e')) {
    function private_file_sc_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('private_file_sc_attrs')) {
    function private_file_sc_attrs(string $attrText): array
    {
        $attrs = [];

        if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_\-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s\]]+))/', $attrText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $key = strtolower((string)$m[1]);
                $val = $m[3] ?? $m[4] ?? $m[5] ?? '';
                $attrs[$key] = (string)$val;
            }
        }

        return $attrs;
    }
}

if (!function_exists('private_file_sc_fetch')) {
    function private_file_sc_fetch(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) return null;

        try {
            $stmt = $pdo->prepare("SELECT * FROM `file` WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            error_log('[private_file_shortcodes] fetch error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('private_file_sc_admin_ok')) {
    function private_file_sc_admin_ok(PDO $pdo): bool
    {
        try {
            if (function_exists('adiwira_fetch_identity')) {
                $identity = adiwira_fetch_identity($pdo);
                if (($identity['ok'] ?? false) === true) {
                    $role = strtolower((string)($identity['role'] ?? ''));
                    return in_array($role, ['admin', 'editor', 'author'], true);
                }
            }

            return function_exists('is_logged_in') && is_logged_in();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('private_file_sc_can_access')) {
    function private_file_sc_can_access(PDO $pdo, array $file): bool
    {
        $visibility = strtolower((string)($file['visibility'] ?? 'public'));
        $disk = strtolower((string)($file['storage_disk'] ?? 'public'));
        $scope = strtolower((string)($file['access_scope'] ?? 'public'));

        if ($visibility === 'public' && $disk === 'public' && $scope === 'public') {
            return true;
        }

        $adminOk = private_file_sc_admin_ok($pdo);

        if ($scope === 'admin') return $adminOk;

        return $adminOk;
    }
}

if (!function_exists('private_file_sc_is_pdf')) {
    function private_file_sc_is_pdf(array $file): bool
    {
        $mime = strtolower((string)($file['mime'] ?? ''));
        $ext = strtolower((string)($file['ext'] ?? pathinfo((string)($file['filename'] ?? ''), PATHINFO_EXTENSION)));
        return $mime === 'application/pdf' || $ext === 'pdf';
    }
}

if (!function_exists('private_file_sc_title')) {
    function private_file_sc_title(array $file): string
    {
        $title = trim((string)($file['title'] ?? ''));
        if ($title !== '') return $title;

        $filename = trim((string)($file['filename'] ?? ''));
        if ($filename !== '') return $filename;

        return 'File #' . (int)($file['id'] ?? 0);
    }
}

if (!function_exists('private_file_sc_stream_url')) {
    function private_file_sc_stream_url(int $id): string
    {
        return '/private/file/view/?id=' . $id;
    }
}

if (!function_exists('private_file_sc_pdf_url')) {
    function private_file_sc_pdf_url(int $id): string
    {
        return '/private/pdf/view/?id=' . $id;
    }
}

if (!function_exists('private_file_sc_css')) {
    function private_file_sc_css(): string
    {
        static $sent = false;
        if ($sent) return '';
        $sent = true;

        return '<style>
.pvt-pdf-card,.pvt-pdf-card *{box-sizing:border-box}
.pvt-pdf-card{--pvt-pdf-blue:#2563eb;--pvt-pdf-cyan:#14b8a6;--pvt-pdf-text:#0f172a;--pvt-pdf-muted:#64748b;--pvt-pdf-border:rgba(148,163,184,.32);width:100%;margin:1.1rem 0;border:1px solid var(--pvt-pdf-border);border-radius:18px;background:rgba(255,255,255,.84);overflow:hidden;color:var(--pvt-pdf-text)}
.pvt-pdf-card__head{display:flex;gap:.9rem;align-items:center;justify-content:space-between;padding:1rem 1.1rem;border-bottom:1px solid var(--pvt-pdf-border);background:rgba(248,250,252,.90)}
.pvt-pdf-card__meta{min-width:0}
.pvt-pdf-card__kicker{margin:0 0 .25rem;color:var(--pvt-pdf-cyan);font-size:.72rem;font-weight:950;letter-spacing:.11em;text-transform:uppercase}
.pvt-pdf-card__title{margin:0;color:var(--pvt-pdf-text);font-size:clamp(1.05rem,2vw,1.35rem);font-weight:950;letter-spacing:-.03em;line-height:1.15}
.pvt-pdf-card__sub{margin:.35rem 0 0;color:var(--pvt-pdf-muted);font-size:.86rem;line-height:1.45}
.pvt-pdf-card__actions{display:flex;gap:.45rem;flex-wrap:wrap;justify-content:flex-end;flex:0 0 auto}
.pvt-pdf-card__btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:.55rem .85rem;border-radius:999px;border:1px solid var(--pvt-pdf-border);background:#fff;color:var(--pvt-pdf-text);text-decoration:none;font-weight:900;font-size:.88rem}
.pvt-pdf-card__btn--primary{border-color:transparent;color:#fff;background:linear-gradient(135deg,var(--pvt-pdf-blue),var(--pvt-pdf-cyan));box-shadow:0 10px 22px rgba(37,99,235,.20)}
.pvt-pdf-card__frame-wrap{position:relative;width:100%;min-height:100px;background:#fff}
.pvt-pdf-card__frame{display:block;width:100%;min-height:100px;border:0;background:#fff}
.pvt-pdf-card--embed{border-radius:14px;box-shadow:none;background:transparent}
.pvt-pdf-card--embed .pvt-pdf-card__frame-wrap{min-height:100px;background:transparent}
.pvt-pdf-card--locked{padding:1rem 1.1rem;background:rgba(239,246,255,.8)}
@media(max-width:720px){.pvt-pdf-card__head{align-items:flex-start;flex-direction:column}.pvt-pdf-card__actions{justify-content:flex-start}}
</style>';
    }
}

if (!function_exists('private_file_sc_render_locked')) {
    function private_file_sc_render_locked(): string
    {
        return '';
    }
}

if (!function_exists('private_file_sc_render')) {
    function private_file_sc_render(PDO $pdo, int $id, string $mode = 'embed'): string
    {
        $file = private_file_sc_fetch($pdo, $id);
        if (!$file) {
            return private_file_sc_css() . '<div class="pvt-pdf-card pvt-pdf-card--locked"><strong>File tidak ditemukan.</strong></div>';
        }

        if (!private_file_sc_can_access($pdo, $file)) {
            return private_file_sc_render_locked();
        }

        $isPdf = private_file_sc_is_pdf($file);
        $title = private_file_sc_title($file);
        $mime = (string)($file['mime'] ?? '');
        $size = (int)($file['size'] ?? 0);
        $sizeLabel = $size > 0 ? number_format($size / 1024 / 1024, 2, ',', '.') . ' MB' : 'File internal';

        $viewerUrl = $isPdf ? private_file_sc_pdf_url($id) : private_file_sc_stream_url($id);

        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['embed', 'card', 'button', 'link'], true)) {
            $mode = $isPdf ? 'embed' : 'card';
        }

        if (!$isPdf && $mode === 'embed') {
            $mode = 'card';
        }

        if ($mode === 'link') {
            return '<a href="' . private_file_sc_e($viewerUrl) . '" target="_blank" rel="noopener">' . private_file_sc_e($title) . '</a>';
        }

        if ($mode === 'button') {
            return private_file_sc_css() . '<a class="pvt-pdf-card__btn pvt-pdf-card__btn--primary" href="' . private_file_sc_e($viewerUrl) . '" target="_blank" rel="noopener">Buka ' . private_file_sc_e($title) . '</a>';
        }

        if ($mode === 'embed' && $isPdf) {
            $embedUrl = private_file_sc_pdf_url($id) . '&embed=1';
            $frameId = 'pvt-pdf-frame-' . (int)$id;
            $html = private_file_sc_css();
            $html .= '<section class="pvt-pdf-card pvt-pdf-card--embed" data-private-file-id="' . (int)$id . '">';
            $html .= '<div class="pvt-pdf-card__frame-wrap">';
            $html .= '<iframe id="' . $frameId . '" class="pvt-pdf-card__frame" src="' . private_file_sc_e($embedUrl) . '" title="' . private_file_sc_e($title) . '"></iframe>';
            $html .= '</div>';
            $html .= '</section>';
            $html .= '<script nonce="' . private_file_sc_e((string)($_SERVER['NONCE'] ?? '')) . '">(function(){var f=document.getElementById("' . $frameId . '");if(!f)return;window.addEventListener("message",function(e){if(e.data&&e.data.type==="pvtpdf-resize"&&e.data.height>0){f.style.height=e.data.height+"px"}});})();</script>';
            return $html;
        }

        $html = private_file_sc_css();
        $html .= '<section class="pvt-pdf-card" data-private-file-id="' . (int)$id . '">';
        $html .= '<header class="pvt-pdf-card__head">';
        $html .= '<div class="pvt-pdf-card__meta">';
        $html .= '<p class="pvt-pdf-card__kicker">' . ($isPdf ? 'Protected PDF' : 'Protected File') . '</p>';
        $html .= '<h3 class="pvt-pdf-card__title">' . private_file_sc_e($title) . '</h3>';
        $html .= '<p class="pvt-pdf-card__sub">' . private_file_sc_e($mime ?: 'file') . ' · ' . private_file_sc_e($sizeLabel) . ' · internal access</p>';
        $html .= '</div>';
        $html .= '<nav class="pvt-pdf-card__actions" aria-label="Aksi file">';
        $html .= '<a class="pvt-pdf-card__btn pvt-pdf-card__btn--primary" href="' . private_file_sc_e($viewerUrl) . '" target="_blank" rel="noopener">Buka viewer</a>';
        if (!$isPdf) {
            $html .= '<a class="pvt-pdf-card__btn" href="' . private_file_sc_e(private_file_sc_stream_url($id)) . '" target="_blank" rel="noopener">Buka file</a>';
        }
        $html .= '</nav>';
        $html .= '</header>';
        $html .= '</section>';

        return $html;
    }
}

if (!function_exists('private_file_shortcode_expand')) {
    function private_file_shortcode_expand(string $html, ?PDO $pdo = null, array $context = []): string
    {
        if (!$pdo instanceof PDO || $html === '') {
            return $html;
        }

        $pattern = '/\[(private_pdf|private_file|protected_file)\b([^\]]*)\]/i';

        return preg_replace_callback($pattern, function (array $m) use ($pdo) {
            $tag = strtolower((string)$m[1]);
            $attrs = private_file_sc_attrs((string)($m[2] ?? ''));
            $id = max(0, (int)($attrs['id'] ?? $attrs['file_id'] ?? 0));
            $mode = strtolower((string)($attrs['mode'] ?? ''));

            if ($mode === '') {
                $mode = ($tag === 'private_pdf') ? 'embed' : 'card';
            }

            if ($id <= 0) {
                return '';
            }

            return private_file_sc_render($pdo, $id, $mode);
        }, $html) ?? $html;
    }
}
