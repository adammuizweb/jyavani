<?php
declare(strict_types=1);

if (!function_exists('settings_get')) {
    function settings_get(PDO $pdo, string $key, ?string $default = null): ?string {
        $cache =& $GLOBALS['__jy_settings_autoload_cache'];

        // lazy load autoload settings sekali per request
        if (!is_array($cache)) {
            $cache = [];
            try {
                $st = $pdo->query("SELECT `key`, `value` FROM settings WHERE autoload = 1");
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $cache[(string)$row['key']] = $row['value'] !== null ? (string)$row['value'] : null;
                }
            } catch (Throwable $e) {
                // kalau tabel belum ada / error, fallback ke default
                return $default;
            }
        }

        return array_key_exists($key, $cache) ? $cache[$key] : $default;
    }
}

if (!function_exists('settings_set')) {
    function settings_set(PDO $pdo, string $key, ?string $value, int $autoload = 1): bool {
        $sql = "INSERT INTO settings (`key`,`value`,`autoload`)
                VALUES (:k,:v,:a)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `autoload` = VALUES(`autoload`)";
        $st = $pdo->prepare($sql);
        $saved = $st->execute([
            ':k' => $key,
            ':v' => $value,
            ':a' => $autoload
        ]);
        if ($saved && isset($GLOBALS['__jy_settings_autoload_cache']) && is_array($GLOBALS['__jy_settings_autoload_cache'])) {
            if ($autoload === 1) {
                $GLOBALS['__jy_settings_autoload_cache'][$key] = $value;
            } else {
                unset($GLOBALS['__jy_settings_autoload_cache'][$key]);
            }
        }
        return $saved;
    }
}

if (!function_exists('settings_robots_txt_validation_error')) {
    function settings_robots_txt_validation_error(string $content): ?string {
        if (strlen($content) > 16384
            || preg_match('//u', $content) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1) {
            return 'Robots.txt must be valid UTF-8 text, contain no control characters, and not exceed 16 KiB.';
        }
        return null;
    }
}

if (!function_exists('site_search_engine_indexing_allowed')) {
    function site_search_engine_indexing_allowed(PDO $pdo): bool {
        return settings_get($pdo, 'search_engine_indexing', '1') !== '0';
    }
}

if (!function_exists('site_robots_txt_normalize')) {
    function site_robots_txt_normalize(string $content): string {
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if ($content === '') return '';
        return rtrim($content, "\n") . "\n";
    }
}

if (!function_exists('site_robots_txt_content')) {
    function site_robots_txt_content(PDO $pdo, string $baseUrl): string {
        $indexingAllowed = site_search_engine_indexing_allowed($pdo);
        $default = $indexingAllowed
            ? "User-agent: *\nAllow: /\nSitemap: " . rtrim($baseUrl, '/') . "/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";
        $custom = settings_get($pdo, 'robots_txt_custom', '') ?? '';
        $content = $indexingAllowed && trim($custom) !== '' ? $custom : $default;
        if ($indexingAllowed && function_exists('apply_filters')) {
            $filtered = apply_filters('robots_txt_content', $content, $pdo, $indexingAllowed);
            if (is_string($filtered)) $content = $filtered;
        }
        if (settings_robots_txt_validation_error($content) !== null) $content = $default;
        return site_robots_txt_normalize($content);
    }
}

if (!function_exists('settings_favicon_url_validation_error')) {
    /** Validate favicon URL safety and inspect local image dimensions without fetching remote URLs. */
    function settings_favicon_url_validation_error(string $url, ?string $publicPath = null): ?string {
        $url = trim($url);
        if ($url === '') return null;
        $invalidUrl = 'Favicon must use a root-relative path or an HTTPS URL to a PNG, ICO, or SVG file.';
        $invalidFile = 'Local favicon file is missing or outside the public directory.';
        $invalidDimensions = 'Favicon must be square (1:1) and at least 48×48 pixels.';

        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) return $invalidUrl;
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['fragment'])) return $invalidUrl;
        $isLocal = str_starts_with($url, '/') && !str_starts_with($url, '//');
        if ($isLocal) {
            if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return $invalidUrl;
        } elseif (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return $invalidUrl;
        }

        $urlPath = rawurldecode((string)($parts['path'] ?? ''));
        if ($urlPath === '' || preg_match('/[\x00-\x20\x7F]/', $urlPath) === 1 || str_contains($urlPath, '\\')) return $invalidUrl;
        $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'ico', 'svg'], true)) return $invalidUrl;
        if (!$isLocal) return null;

        $publicPath ??= defined('PUBLIC_PATH') ? PUBLIC_PATH : '';
        $root = $publicPath !== '' ? realpath($publicPath) : false;
        $candidatePath = $root !== false ? $root . DIRECTORY_SEPARATOR . ltrim($urlPath, '/') : '';
        $candidate = $candidatePath !== '' ? realpath($candidatePath) : false;
        if ($root === false || $candidate === false || !is_file($candidate) || is_link($candidatePath)
            || !str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)) return $invalidFile;

        $width = 0.0;
        $height = 0.0;
        if ($extension === 'svg') {
            $size = filesize($candidate);
            $source = is_int($size) && $size > 0 && $size <= 1048576 ? file_get_contents($candidate) : false;
            if (!is_string($source) || !class_exists('DOMDocument')) return $invalidDimensions;
            $previous = libxml_use_internal_errors(true);
            $document = new DOMDocument();
            $loaded = $document->loadXML($source, LIBXML_NONET | LIBXML_NOBLANKS);
            $svg = $loaded ? $document->documentElement : null;
            if ($svg instanceof DOMElement && strtolower($svg->localName) === 'svg') {
                $viewBox = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox')));
                if (is_array($viewBox) && count($viewBox) === 4 && is_numeric($viewBox[2]) && is_numeric($viewBox[3])) {
                    $width = (float)$viewBox[2];
                    $height = (float)$viewBox[3];
                } elseif (preg_match('/^([0-9]+(?:\.[0-9]+)?)/', $svg->getAttribute('width'), $widthMatch)
                    && preg_match('/^([0-9]+(?:\.[0-9]+)?)/', $svg->getAttribute('height'), $heightMatch)) {
                    $width = (float)$widthMatch[1];
                    $height = (float)$heightMatch[1];
                }
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        } else {
            $dimensions = @getimagesize($candidate);
            if (is_array($dimensions)) {
                $width = (float)($dimensions[0] ?? 0);
                $height = (float)($dimensions[1] ?? 0);
            }
        }

        if ($width < 48 || $height < 48 || abs($width - $height) > 0.01) return $invalidDimensions;
        return null;
    }
}
