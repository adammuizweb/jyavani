<?php
declare(strict_types=1);

// Theme Customizer (lite) — theme.json declares editable fields, values stored
// per-theme in settings key theme_mods_{folder}, consumed via theme_mod().

if (!function_exists('theme_customizer_fields')) {

    // Read + normalize the "customizer" block from a theme's theme.json.
    // Returns: ['logo'=>bool, 'nav_menu'=>bool, 'controls'=>['search','lang','theme']]
    function theme_customizer_fields(string $folder): array {
        $out = ['logo' => false, 'nav_menu' => false, 'controls' => []];
        $file = rtrim((string)VIEWS_BASE, '/') . '/' . $folder . '/theme.json';
        if (!is_file($file)) return $out;
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || empty($data['customizer']) || !is_array($data['customizer'])) return $out;
        $c = $data['customizer'];
        $out['logo'] = !empty($c['logo']);
        $out['nav_menu'] = !empty($c['nav_menu']);
        if (!empty($c['controls']) && is_array($c['controls'])) {
            $allowed = ['search', 'lang', 'theme'];
            foreach ($c['controls'] as $ctl) {
                $ctl = (string)$ctl;
                if (in_array($ctl, $allowed, true)) $out['controls'][] = $ctl;
            }
        }
        return $out;
    }

    function theme_mods_all(?PDO $pdo = null, ?string $folder = null): array {
        $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
        if (!$pdo instanceof PDO) return [];
        if ($folder === null || $folder === '') {
            $folder = function_exists('get_active_theme_folder') ? get_active_theme_folder($pdo) : 'default';
        }
        if (!function_exists('settings_get')) return [];
        $raw = settings_get($pdo, 'theme_mods_' . $folder, '');
        if (!is_string($raw) || $raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // Read a single mod for the ACTIVE theme (frontend use)
    function theme_mod(string $key, mixed $default = null): mixed {
        static $mods = null;
        if ($mods === null) {
            $mods = theme_mods_all();
        }
        return array_key_exists($key, $mods) ? $mods[$key] : $default;
    }

    function theme_mods_save(PDO $pdo, string $folder, array $mods): bool {
        if (!function_exists('settings_set')) return false;
        return settings_set($pdo, 'theme_mods_' . $folder, json_encode($mods));
    }
}
