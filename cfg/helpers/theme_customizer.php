<?php
declare(strict_types=1);

// Theme Customizer (lite) — theme.json declares editable fields, values stored
// per-theme in settings key theme_mods_{folder}, consumed via theme_mod().

if (!function_exists('theme_customizer_fields')) {

    // Read + normalize the "customizer" block from a theme's theme.json.
    // Returns section-based array:
    // ['header' => ['label' => 'Header', 'fields' => [...]], 'footer' => [...]]
    function theme_customizer_fields(string $folder): array {
        $out = [];
        $file = rtrim((string)VIEWS_BASE, '/') . '/' . $folder . '/theme.json';
        if (!is_file($file)) return $out;
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || empty($data['customizer']) || !is_array($data['customizer'])) return $out;

        $c = $data['customizer'];

        // Legacy flat format: "customizer": {"logo": true, "nav_menu": true, "controls": [...]}
        if (!isset($c['sections']) && (isset($c['logo']) || isset($c['nav_menu']) || isset($c['controls']))) {
            $c = ['sections' => ['header' => ['label' => __('Header'), 'fields' => _theme_customizer_flat_to_fields($c)]]];
        }

        if (!isset($c['sections']) || !is_array($c['sections'])) return $out;

        foreach ($c['sections'] as $sectionKey => $section) {
            if (!is_array($section) || empty($section['fields']) || !is_array($section['fields'])) continue;
            $label = (string)($section['label'] ?? ucfirst((string)$sectionKey));
            $fields = [];
            foreach ($section['fields'] as $key => $def) {
                if (!is_array($def)) continue;
                $type = (string)($def['type'] ?? 'toggle');
                if (!in_array($type, ['image', 'menu', 'sidebar_zone', 'textarea', 'text', 'toggle'], true)) continue;
                $field = [
                    'key'   => (string)$key,
                    'type'  => $type,
                    'label' => (string)($def['label'] ?? _theme_customizer_default_label((string)$key)),
                ];
                if (array_key_exists('translatable', $def) && is_bool($def['translatable'])) {
                    $field['translatable'] = $def['translatable'];
                }
                if (($def['format'] ?? '') === 'json') {
                    $field['format'] = 'json';
                }
                $fields[(string)$key] = $field;
            }
            if (!empty($fields)) {
                $normalizedSection = ['label' => $label, 'fields' => $fields];
                if (array_key_exists('slot', $section) && is_string($section['slot'])) {
                    $normalizedSection['slot'] = $section['slot'];
                }
                $out[(string)$sectionKey] = $normalizedSection;
            }
        }
        return $out;
    }

    function _theme_customizer_flat_to_fields(array $c): array {
        $fields = [];
        if (!empty($c['logo'])) $fields['logo'] = ['type' => 'image', 'label' => __('Logo')];
        if (!empty($c['nav_menu'])) $fields['nav_menu'] = ['type' => 'menu', 'label' => __('Navigation menu')];
        foreach ($c['controls'] ?? [] as $ctl) {
            $ctl = (string)$ctl;
            if ($ctl === 'search') $fields['show_search'] = ['type' => 'toggle', 'label' => __('Show search box')];
            if ($ctl === 'lang') $fields['show_lang'] = ['type' => 'toggle', 'label' => __('Show language selector')];
            if ($ctl === 'theme') $fields['show_theme'] = ['type' => 'toggle', 'label' => __('Show theme selector')];
        }
        return $fields;
    }

    function _theme_customizer_default_label(string $key): string {
        $labels = [
            'logo' => __('Logo'),
            'nav_menu' => __('Navigation menu'),
            'show_theme' => __('Show theme selector'),
            'show_lang' => __('Show language selector'),
            'show_search' => __('Show search box'),
            'footer_text' => __('Footer text'),
            'footer_menu' => __('Footer menu'),
            'footer_sidebar_zone' => __('Footer sidebar zone'),
            'show_social' => __('Show social icons'),
        ];
        return $labels[$key] ?? ucfirst(str_replace(['_', '-'], ' ', $key));
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

    function theme_mod(string $key, mixed $default = null): mixed {
        static $modsByFolder = [];
        $pdo = ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null;
        $folder = (string)($GLOBALS['__jy_render_theme_folder'] ?? '');
        if ($folder === '') {
            $folder = function_exists('get_active_theme_folder')
                ? get_active_theme_folder($pdo)
                : (defined('DEFAULT_THEME_FOLDER') ? DEFAULT_THEME_FOLDER : 'default');
        }
        if (!array_key_exists($folder, $modsByFolder)) {
            $modsByFolder[$folder] = theme_mods_all($pdo, $folder);
        }

        $value = array_key_exists($key, $modsByFolder[$folder]) ? $modsByFolder[$folder][$key] : $default;
        $slotKey = is_string($GLOBALS['__jy_render_slot_key'] ?? null)
            ? $GLOBALS['__jy_render_slot_key']
            : null;
        return function_exists('apply_filters')
            ? apply_filters('theme_mod_value', $value, $key, $folder, $slotKey, $pdo)
            : $value;
    }

    function theme_mods_save(PDO $pdo, string $folder, array $mods): bool {
        if (!function_exists('settings_set')) return false;
        return settings_set($pdo, 'theme_mods_' . $folder, json_encode($mods));
    }
}
