<?php
declare(strict_types=1);

$base = rtrim((string)(getenv('JY_BASE_URL') ?: 'https://jyavani.lan'), '/');
$publicRoot = (string)(getenv('JY_PUBLIC_ROOT') ?: dirname(__DIR__) . '/public');
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};
$request = static function (string $url): array {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Jyavani HTTP security contract',
    ]);
    $raw = curl_exec($handle);
    $headerSize = (int)curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $headers = [];
    foreach (preg_split('/\r?\n/', is_string($raw) ? substr($raw, 0, $headerSize) : '') ?: [] as $line) {
        if (!str_contains($line, ':')) continue;
        [$name, $value] = array_map('trim', explode(':', $line, 2));
        $headers[strtolower($name)] = $value;
    }
    $result = [
        'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => $headers,
        'body' => is_string($raw) ? substr($raw, $headerSize) : '',
    ];
    curl_close($handle);
    return $result;
};

$home = $request($base . '/');
$check($home['status'] === 200, 'HTTPS homepage remains available');
foreach (['strict-transport-security', 'content-security-policy-report-only', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy'] as $header) {
    $check(isset($home['headers'][$header]), 'homepage emits ' . $header);
}

foreach (['/cfg/', '/.htaccess', '/php.ini', '/views/.htaccess', '/dev_lock.php'] as $path) {
    $response = $request($base . $path);
    $check($response['status'] === 404 && strlen($response['body']) > 500, $path . ' uses cosmetic 404 masking');
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($publicRoot . '/views', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') continue;
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($publicRoot)));
    $response = $request($base . $relative);
    $check($response['status'] === 404, 'direct PHP view is masked');
}

$asset = $request($base . '/views/themes/default/assets/css/style.css');
$check($asset['status'] === 200, 'theme static assets remain available');

$parts = parse_url($base);
if (is_array($parts) && ($parts['scheme'] ?? '') === 'https') {
    $http = 'http://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
    $redirect = $request($http);
    $check(in_array($redirect['status'], [301, 302, 307, 308], true) && str_starts_with((string)($redirect['headers']['location'] ?? ''), 'https://'), 'HTTP redirects to HTTPS');
}

foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
if ($failures !== []) exit(1);
echo "HTTP security contract passed ({$checks} checks).\n";
