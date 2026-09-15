<?php
declare(strict_types=1);

require_once __DIR__ . '/extension_release_manifest.php';

const SITE_HEALTH_DEPLOYMENT_MANIFEST_SCHEMA = 1;
const SITE_HEALTH_DEPLOYMENT_MANIFEST_MAX_BYTES = 8 * 1024 * 1024;
const SITE_HEALTH_DEPLOYMENT_MANIFEST_MAX_COMPONENTS = 2000;
const SITE_HEALTH_DEPLOYMENT_MANIFEST_DOMAIN = "Jyavani deployment extension manifest\0v1\0";

function site_health_deployment_manifest_env(string $name): string
{
    $value = $_ENV[$name] ?? getenv($name);
    return is_string($value) ? $value : '';
}

function site_health_deployment_manifest_absolute_path(string $path): bool
{
    return str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1;
}

function site_health_deployment_manifest_base64(string $value, ?int $bytes = null): ?string
{
    if ($value === '' || preg_match('/\A(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?\z/D', $value) !== 1) return null;
    $decoded = base64_decode($value, true);
    return is_string($decoded) && ($bytes === null || strlen($decoded) === $bytes)
        && base64_encode($decoded) === $value ? $decoded : null;
}

function site_health_deployment_manifest_canonical_json(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            $parts = array_map('site_health_deployment_manifest_canonical_json', $value);
            return '[' . implode(',', $parts) . ']';
        }
        $keys = array_keys($value);
        foreach ($keys as $key) if (!is_string($key)) throw new RuntimeException('Invalid deployment extension manifest payload.');
        sort($keys, SORT_STRING);
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                . ':' . site_health_deployment_manifest_canonical_json($value[$key]);
        }
        return '{' . implode(',', $parts) . '}';
    }
    if (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) {
        throw new RuntimeException('Invalid deployment extension manifest payload.');
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function site_health_deployment_manifest_validate_payload(array $payload): array
{
    if (array_is_list($payload)) throw new RuntimeException('Invalid deployment extension manifest payload.');
    $keys = array_keys($payload);
    sort($keys, SORT_STRING);
    $expected = isset($payload['deployment_id'])
        ? ['components', 'deployment_id', 'schema', 'source_revision']
        : ['components', 'schema', 'source_revision'];
    if ($keys !== $expected || ($payload['schema'] ?? null) !== SITE_HEALTH_DEPLOYMENT_MANIFEST_SCHEMA
        || !is_string($payload['source_revision'] ?? null)
        || preg_match('/\A[a-f0-9]{40}\z/D', $payload['source_revision']) !== 1
        || !is_array($payload['components'] ?? null) || !array_is_list($payload['components'])
        || count($payload['components']) > SITE_HEALTH_DEPLOYMENT_MANIFEST_MAX_COMPONENTS) {
        throw new RuntimeException('Invalid deployment extension manifest payload.');
    }
    if (isset($payload['deployment_id']) && (!is_string($payload['deployment_id'])
        || $payload['deployment_id'] === '' || strlen($payload['deployment_id']) > 200
        || preg_match('//u', $payload['deployment_id']) !== 1
        || preg_match('/[\x00-\x1F\x7F]/', $payload['deployment_id']) === 1)) {
        throw new RuntimeException('Invalid deployment extension manifest payload.');
    }

    $previous = null;
    $components = [];
    foreach ($payload['components'] as $component) {
        if (!is_array($component) || array_is_list($component)) throw new RuntimeException('Invalid deployment extension manifest component.');
        $componentKeys = array_keys($component);
        sort($componentKeys, SORT_STRING);
        if ($componentKeys !== ['files', 'folder', 'total_files', 'type', 'version']
            || !in_array($component['type'] ?? null, ['plugin', 'theme'], true)
            || !is_string($component['folder'] ?? null) || $component['folder'] === ''
            || strlen($component['folder']) > 255 || preg_match('//u', $component['folder']) !== 1
            || preg_match('#[\\\\/\x00-\x1F\x7F]#', $component['folder']) === 1
            || in_array($component['folder'], ['.', '..'], true)
            || !is_string($component['version'] ?? null)
            || !extension_release_manifest_version_valid($component['version'])
            || !is_int($component['total_files'] ?? null)
            || !is_array($component['files'] ?? null) || array_is_list($component['files'])
            || $component['files'] === [] || $component['total_files'] !== count($component['files'])
            || count($component['files']) > EXTENSION_RELEASE_MANIFEST_MAX_FILES) {
            throw new RuntimeException('Invalid deployment extension manifest component.');
        }
        $identity = $component['type'] . '/' . $component['folder'];
        if ($previous !== null && strcmp($previous, $identity) >= 0) throw new RuntimeException('Invalid deployment extension manifest component order.');
        $previous = $identity;
        $files = extension_release_manifest_validate([
            'schema_version' => 1,
            'type' => $component['type'],
            'name' => extension_release_manifest_slug_valid($component['folder']) ? $component['folder'] : 'local-extension',
            'version' => $component['version'],
            'package_sha256' => str_repeat('0', 64),
            'zip_size' => 1,
            'total_files' => $component['total_files'],
            'files' => $component['files'],
        ], $component['type'], extension_release_manifest_slug_valid($component['folder']) ? $component['folder'] : 'local-extension', $component['version']);
        $components[$identity] = ['version' => $component['version'], 'total_files' => $component['total_files'], 'files' => $files['files']];
    }
    return $components;
}

function site_health_deployment_manifest_load(
    ?string $path = null,
    ?string $publicKeyBase64 = null,
    ?string $publicRoot = null,
    ?string $sourceRevision = null
): array
{
    $path ??= site_health_deployment_manifest_env('SITE_HEALTH_DEPLOYMENT_MANIFEST_PATH');
    $publicKeyBase64 ??= site_health_deployment_manifest_env('SITE_HEALTH_DEPLOYMENT_PUBLIC_KEY');
    $sourceRevision ??= site_health_deployment_manifest_env('SITE_HEALTH_DEPLOYMENT_SOURCE_REVISION');
    if ($path === '' && $publicKeyBase64 === '' && $sourceRevision === '') {
        return ['configured' => false, 'components' => [], 'reason' => null];
    }
    if ($path === '' || $publicKeyBase64 === '' || preg_match('/\A[a-f0-9]{40}\z/D', $sourceRevision) !== 1
        || !site_health_deployment_manifest_absolute_path($path)
        || !function_exists('sodium_crypto_sign_verify_detached')) {
        return ['configured' => true, 'components' => [], 'reason' => 'signed_deployment_manifest_invalid'];
    }
    try {
        if ($publicRoot !== null) {
            $manifestReal = realpath($path);
            $publicReal = realpath($publicRoot);
            if ($manifestReal === false || $publicReal === false
                || $manifestReal === $publicReal || str_starts_with($manifestReal, rtrim($publicReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Deployment extension manifest must remain private.');
            }
        }
        $raw = cms_manifest_read_bounded_regular_file($path, SITE_HEALTH_DEPLOYMENT_MANIFEST_MAX_BYTES);
        $publicKey = site_health_deployment_manifest_base64($publicKeyBase64, 32);
        if (!is_string($raw) || $publicKey === null) throw new RuntimeException('Invalid deployment extension manifest.');
        $envelope = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || array_is_list($envelope)) throw new RuntimeException('Invalid deployment extension manifest.');
        $keys = array_keys($envelope);
        sort($keys, SORT_STRING);
        if ($keys !== ['algorithm', 'payload', 'schema', 'signature']
            || ($envelope['schema'] ?? null) !== SITE_HEALTH_DEPLOYMENT_MANIFEST_SCHEMA
            || ($envelope['algorithm'] ?? null) !== 'ed25519'
            || !is_string($envelope['payload'] ?? null) || !is_string($envelope['signature'] ?? null)) {
            throw new RuntimeException('Invalid deployment extension manifest.');
        }
        $payloadRaw = site_health_deployment_manifest_base64($envelope['payload']);
        $signature = site_health_deployment_manifest_base64($envelope['signature'], 64);
        if ($payloadRaw === null || $signature === null || strlen($payloadRaw) > SITE_HEALTH_DEPLOYMENT_MANIFEST_MAX_BYTES) throw new RuntimeException('Invalid deployment extension manifest.');
        if (!sodium_crypto_sign_verify_detached($signature, SITE_HEALTH_DEPLOYMENT_MANIFEST_DOMAIN . $payloadRaw, $publicKey)) {
            return ['configured' => true, 'components' => [], 'reason' => 'signed_deployment_manifest_signature_invalid'];
        }
        $payload = json_decode($payloadRaw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || site_health_deployment_manifest_canonical_json($payload) !== $payloadRaw) throw new RuntimeException('Invalid deployment extension manifest payload.');
        $components = site_health_deployment_manifest_validate_payload($payload);
        if (!hash_equals($sourceRevision, $payload['source_revision'])) {
            throw new RuntimeException('Deployment extension manifest source revision mismatch.');
        }
        return [
            'configured' => true,
            'source_revision' => $payload['source_revision'],
            'components' => $components,
            'reason' => null,
        ];
    } catch (Throwable $error) {
        return ['configured' => true, 'components' => [], 'reason' => 'signed_deployment_manifest_invalid'];
    }
}
