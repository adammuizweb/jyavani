<?php
declare(strict_types=1);

// cfg/helpers/video_shortcodes.php
// Shortcode: [video id="X" url="..." mime="..."]

if (!function_exists('video_shortcode_expand')) {
    function video_shortcode_expand(string $html, ?PDO $pdo = null): string
    {
        if ($html === '') return $html;

        return preg_replace_callback('/\[video\b([^\]]*)\]/i', function (array $m) use ($pdo) {
            $attrs = function_exists('private_file_sc_attrs')
                ? private_file_sc_attrs((string)($m[1] ?? ''))
                : [];
            $id = max(0, (int)($attrs['id'] ?? 0));
            $url = trim((string)($attrs['url'] ?? ''));
            $mime = trim((string)($attrs['mime'] ?? ''));

            $videoUrl = '';
            $videoMime = $mime;

            if ($id > 0 && $pdo instanceof PDO && function_exists('private_file_sc_fetch')) {
                $row = private_file_sc_fetch($pdo, $id);
                if ($row) {
                    $visibility = strtolower((string)($row['visibility'] ?? 'public'));
                    $disk = strtolower((string)($row['storage_disk'] ?? 'public'));

                    if ($visibility === 'private' || $disk === 'private') {
                        if (!function_exists('private_file_sc_can_access') || !private_file_sc_can_access($pdo, $row)) {
                            return '';
                        }
                        $mime = strtolower((string)($row['mime'] ?? ''));
                        $ext = strtolower((string)($row['ext'] ?? pathinfo((string)($row['filename'] ?? ''), PATHINFO_EXTENSION)));
                        if ($mime === 'application/pdf' || $ext === 'pdf') {
                            $videoUrl = '/private/pdf/view/?id=' . $id;
                        } else {
                            $videoUrl = '/private/file/view/?id=' . $id;
                        }
                    } else {
                        $videoUrl = (string)($row['url'] ?? '');
                    }
                    if ($videoMime === '') $videoMime = (string)($row['mime'] ?? '');
                }
            } elseif ($url !== '') {
                $videoUrl = $url;
            }

            if ($videoUrl === '') return (string)$m[0];

            $safeUrl = htmlspecialchars($videoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeMime = $videoMime !== '' ? ' type="' . htmlspecialchars($videoMime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';

            return '<p><video controls preload="metadata" style="max-width:100%;height:auto;border-radius:12px"><source src="' . $safeUrl . '"' . $safeMime . '></video></p>';
        }, $html) ?? $html;
    }
}
