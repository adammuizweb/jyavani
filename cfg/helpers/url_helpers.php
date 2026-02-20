<?php
// url_helpers.php (atau di i18n_helpers.php)

function url_for(string $path = '', ?string $locale = null) : string {
    // path seharusnya tanpa leading slash atau bisa menerima juga '/foo' -> kita normalisasi
    $path = (string)$path;
    if ($path === '' || $path === '/') $path = '/';
    // Ensure single leading slash
    $path = '/' . ltrim($path, '/');

    $locale = $locale ?? get_locale();
    $default = default_locale();

    // If locale is default, do NOT prefix. Otherwise, prefix like '/id'
    if ($locale === $default) {
        return $path;
    }
    // Avoid double slashes: '/id/' + '/slug' -> '/id/slug'
    return '/' . $locale . rtrim($path, '/');
}
