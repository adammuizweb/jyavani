<?php
declare(strict_types=1);

const UPDATE_METADATA_MAX_BYTES = 1024 * 1024;
const UPDATE_METADATA_REQUEST_SECONDS = 4.0;
const UPDATE_METADATA_CONNECT_SECONDS = 2.0;

function update_metadata_fetch_json(string $url, string $userAgent, ?float $deadline = null): ?array
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') return null;

    $remaining = $deadline === null
        ? UPDATE_METADATA_REQUEST_SECONDS
        : min(UPDATE_METADATA_REQUEST_SECONDS, $deadline - microtime(true));
    if ($remaining <= 0) return null;
    $requestDeadline = microtime(true) + $remaining;

    $body = '';
    $status = 0;
    $transportFailed = false;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) return null;
        $timeoutMs = max(1, (int)floor($remaining * 1000));
        $redirectProtocols = strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https'
            ? CURLPROTO_HTTPS
            : CURLPROTO_HTTP | CURLPROTO_HTTPS;
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, (int)(UPDATE_METADATA_CONNECT_SECONDS * 1000)),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => $redirectProtocols,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > UPDATE_METADATA_MAX_BYTES) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl) === true;
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        $transportFailed = !$ok;
        curl_close($curl);
        if (!$ok || $status < 200 || $status >= 300) {
            error_log('[update-metadata] Request failed for ' . $host . ': HTTP ' . $status . ($error !== '' ? ' (' . $error . ')' : ''));
            return null;
        }
    } else {
        $response = update_metadata_stream_request($url, $userAgent, $requestDeadline);
        if ($response === null) {
            error_log('[update-metadata] Request failed for ' . $host . ': transport, timeout, or size limit');
            return null;
        }
        $status = $response['status'];
        $body = $response['body'];
        if ($status < 200 || $status >= 300) {
            error_log('[update-metadata] Request failed for ' . $host . ': HTTP ' . $status);
            return null;
        }
    }

    $data = json_decode((string)$body, true);
    return is_array($data) ? $data : null;
}

function update_metadata_stream_request(string $url, string $userAgent, float $deadline): ?array
{
    $current = $url;
    $initialScheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    for ($redirects = 0; $redirects <= 3; $redirects++) {
        $response = update_metadata_stream_request_once($current, $userAgent, $deadline);
        if ($response === null) return null;
        if (!in_array($response['status'], [301, 302, 303, 307, 308], true)) return $response;
        $location = $response['location'];
        if (!is_string($location) || $location === '') return $response;
        $next = update_metadata_redirect_url($current, $location);
        if ($next === null || ($initialScheme === 'https' && strtolower((string)parse_url($next, PHP_URL_SCHEME)) !== 'https')) return null;
        $current = $next;
    }
    return null;
}

function update_metadata_stream_request_once(string $url, string $userAgent, float $deadline): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts)) return null;
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) return null;

    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port < 1 || $port > 65535 || microtime(true) >= $deadline) return null;
    $bareHost = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
    $socketHost = str_contains($bareHost, ':') ? '[' . $bareHost . ']' : $bareHost;
    $context = stream_context_create($scheme === 'https' ? ['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $bareHost,
        'SNI_enabled' => true,
    ]] : []);
    $socket = @stream_socket_client(
        ($scheme === 'https' ? 'tls://' : 'tcp://') . $socketHost . ':' . $port,
        $errorNumber,
        $errorMessage,
        max(0.001, $deadline - microtime(true)),
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!is_resource($socket)) return null;
    stream_set_blocking($socket, false);

    try {
        $path = (string)($parts['path'] ?? '/');
        if ($path === '') $path = '/';
        if (isset($parts['query'])) $path .= '?' . $parts['query'];
        if (preg_match('/[\x00-\x20\x7F]/', $path) === 1) return null;
        $agent = preg_replace('/[\x00-\x1F\x7F]/', '', $userAgent) ?? 'JyavaniCMS';
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $hostHeader = $socketHost . ($port === $defaultPort ? '' : ':' . $port);
        $request = "GET {$path} HTTP/1.1\r\nHost: {$hostHeader}\r\nAccept: application/json\r\nUser-Agent: {$agent}\r\nConnection: close\r\n\r\n";
        if (!update_metadata_stream_write($socket, $request, $deadline)) return null;

        do {
            $statusLine = update_metadata_stream_line($socket, $deadline, 8192);
            if (!is_string($statusLine) || preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})\b#i', $statusLine, $match) !== 1) return null;
            $status = (int)$match[1];
            $headers = [];
            $headerBytes = strlen($statusLine);
            while (true) {
                $line = update_metadata_stream_line($socket, $deadline, 8192);
                if (!is_string($line)) return null;
                $headerBytes += strlen($line);
                if ($headerBytes > 32768) return null;
                if ($line === "\r\n" || $line === "\n") break;
                $separator = strpos($line, ':');
                if ($separator === false) continue;
                $name = strtolower(trim(substr($line, 0, $separator)));
                $value = trim(substr($line, $separator + 1));
                if ($name !== '') $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
            }
        } while ($status >= 100 && $status < 200 && $status !== 101);

        $location = $headers['location'] ?? null;
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            return ['status' => $status, 'body' => '', 'location' => is_string($location) ? $location : null];
        }

        $body = '';
        if (str_contains(strtolower((string)($headers['transfer-encoding'] ?? '')), 'chunked')) {
            while (true) {
                $line = update_metadata_stream_line($socket, $deadline, 128);
                if (!is_string($line) || preg_match('/^([0-9a-fA-F]+)/', trim($line), $match) !== 1) return null;
                $length = hexdec($match[1]);
                if (!is_int($length) && !is_float($length)) return null;
                $length = (int)$length;
                if ($length === 0) break;
                if ($length < 0 || strlen($body) + $length > UPDATE_METADATA_MAX_BYTES) return null;
                $chunk = update_metadata_stream_bytes($socket, $length, $deadline);
                if (!is_string($chunk) || update_metadata_stream_bytes($socket, 2, $deadline) !== "\r\n") return null;
                $body .= $chunk;
            }
        } else {
            $declaredLength = isset($headers['content-length']) && ctype_digit($headers['content-length'])
                ? (int)$headers['content-length']
                : null;
            if ($declaredLength !== null && $declaredLength > UPDATE_METADATA_MAX_BYTES) return null;
            while (!feof($socket)) {
                if (!update_metadata_stream_wait($socket, $deadline)) return null;
                $chunk = fread($socket, min(8192, UPDATE_METADATA_MAX_BYTES + 1 - strlen($body)));
                if ($chunk === false) return null;
                if ($chunk === '') {
                    if (feof($socket)) break;
                    continue;
                }
                $body .= $chunk;
                if (strlen($body) > UPDATE_METADATA_MAX_BYTES) return null;
            }
            if ($declaredLength !== null && strlen($body) !== $declaredLength) return null;
        }
        return ['status' => $status, 'body' => $body, 'location' => null];
    } finally {
        fclose($socket);
    }
}

function update_metadata_stream_wait($stream, float $deadline, bool $write = false): bool
{
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) return false;
    $read = $write ? [] : [$stream];
    $writes = $write ? [$stream] : [];
    $except = [];
    $seconds = (int)floor($remaining);
    $ready = @stream_select($read, $writes, $except, $seconds, (int)(($remaining - $seconds) * 1000000));
    return $ready === 1;
}

function update_metadata_stream_write($stream, string $data, float $deadline): bool
{
    $offset = 0;
    while ($offset < strlen($data)) {
        if (!update_metadata_stream_wait($stream, $deadline, true)) return false;
        $written = fwrite($stream, substr($data, $offset));
        if (!is_int($written) || $written < 1) return false;
        $offset += $written;
    }
    return true;
}

function update_metadata_stream_line($stream, float $deadline, int $maxBytes): ?string
{
    $line = '';
    while (strlen($line) <= $maxBytes) {
        if (!update_metadata_stream_wait($stream, $deadline)) return null;
        $byte = fread($stream, 1);
        if (!is_string($byte) || $byte === '') return null;
        $line .= $byte;
        if ($byte === "\n") return $line;
    }
    return null;
}

function update_metadata_stream_bytes($stream, int $length, float $deadline): ?string
{
    $data = '';
    while (strlen($data) < $length) {
        if (!update_metadata_stream_wait($stream, $deadline)) return null;
        $chunk = fread($stream, $length - strlen($data));
        if (!is_string($chunk) || $chunk === '') return null;
        $data .= $chunk;
    }
    return $data;
}

function update_metadata_redirect_url(string $baseUrl, string $location): ?string
{
    $location = trim($location);
    if ($location === '' || preg_match('/[\x00-\x20\x7F]/', $location) === 1) return null;
    if (filter_var($location, FILTER_VALIDATE_URL) !== false) return $location;
    $base = parse_url($baseUrl);
    if (!is_array($base) || !isset($base['scheme'], $base['host'])) return null;
    if (str_starts_with($location, '//')) return $base['scheme'] . ':' . $location;
    $port = isset($base['port']) ? ':' . $base['port'] : '';
    $baseHost = (string)$base['host'];
    $baseHost = str_starts_with($baseHost, '[') && str_ends_with($baseHost, ']') ? substr($baseHost, 1, -1) : $baseHost;
    $baseHost = str_contains($baseHost, ':') ? '[' . $baseHost . ']' : $baseHost;
    $origin = $base['scheme'] . '://' . $baseHost . $port;
    if (str_starts_with($location, '/')) return $origin . $location;
    if (str_starts_with($location, '?')) return $origin . (string)($base['path'] ?? '/') . $location;
    $directory = rtrim(str_replace('\\', '/', dirname((string)($base['path'] ?? '/'))), '/');
    return $origin . ($directory === '' ? '' : $directory) . '/' . $location;
}
