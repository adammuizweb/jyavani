<?php
// env loader
function load_env($path) {
    if (!file_exists($path)) {
        throw new Exception(".env file not found at {$path}");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Abaikan komentar
        if (str_starts_with(trim($line), '#')) continue;

        // Pisahkan key dan value
        [$name, $value] = array_map('trim', explode('=', $line, 2));

        // Hapus kutipan jika ada
        $value = trim($value, "'\"");

        // Set ke $_ENV dan environment process
        $_ENV[$name] = $value;
        putenv("$name=$value");
    }
}

// helper env 
function env($key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?? $default;
}