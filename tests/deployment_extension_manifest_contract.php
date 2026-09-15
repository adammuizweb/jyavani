<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/deployment_extension_manifest.php';

$fixture = sys_get_temp_dir() . '/jy-deployment-manifest-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
$path = $fixture . '/extensions.json';
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$writeEnvelope = static function (array $payload, string $secretKey, ?string $payloadRaw = null, ?string $signature = null) use ($path): void {
    $payloadRaw ??= site_health_deployment_manifest_canonical_json($payload);
    $signature ??= sodium_crypto_sign_detached(SITE_HEALTH_DEPLOYMENT_MANIFEST_DOMAIN . $payloadRaw, $secretKey);
    file_put_contents($path, json_encode([
        'schema' => 1,
        'algorithm' => 'ed25519',
        'payload' => base64_encode($payloadRaw),
        'signature' => base64_encode($signature),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
};

try {
    $keypair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    $publicKey = sodium_crypto_sign_publickey($keypair);
    $payload = [
        'schema' => 1,
        'deployment_id' => 'opaque deployment 17',
        'source_revision' => str_repeat('a', 40),
        'components' => [[
            'type' => 'plugin',
            'folder' => 'example-extension',
            'version' => '1.2.3',
            'total_files' => 1,
            'files' => ['plugin.php' => str_repeat('b', 64)],
        ]],
    ];
    $_ENV['SITE_HEALTH_DEPLOYMENT_SOURCE_REVISION'] = $payload['source_revision'];
    $writeEnvelope($payload, $secretKey);
    $loaded = site_health_deployment_manifest_load($path, base64_encode($publicKey));
    $check(($loaded['reason'] ?? null) === null
        && ($loaded['source_revision'] ?? null) === $payload['source_revision']
        && ($loaded['components']['plugin/example-extension']['version'] ?? null) === '1.2.3',
        'a canonical domain-separated Ed25519 envelope resolves its exact component map');

    $_ENV['SITE_HEALTH_DEPLOYMENT_SOURCE_REVISION'] = str_repeat('c', 40);
    $check(site_health_deployment_manifest_load($path, base64_encode($publicKey))['reason'] === 'signed_deployment_manifest_invalid',
        'a valid signed envelope is rejected when its source revision differs from runtime configuration');
    $_ENV['SITE_HEALTH_DEPLOYMENT_SOURCE_REVISION'] = $payload['source_revision'];

    $rawPayload = site_health_deployment_manifest_canonical_json($payload);
    $writeEnvelope($payload, $secretKey, $rawPayload, sodium_crypto_sign_detached($rawPayload, $secretKey));
    $check(site_health_deployment_manifest_load($path, base64_encode($publicKey))['reason'] === 'signed_deployment_manifest_signature_invalid',
        'a signature without the domain separator is rejected');

    $writeEnvelope($payload, $secretKey, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $check(site_health_deployment_manifest_load($path, base64_encode($publicKey))['reason'] === 'signed_deployment_manifest_invalid',
        'signed but noncanonical payload JSON is rejected');

    $unsorted = $payload;
    $unsorted['components'][0]['files'] = ['z.php' => str_repeat('c', 64), 'a.php' => str_repeat('d', 64)];
    $unsorted['components'][0]['total_files'] = 2;
    $unsortedRaw = site_health_deployment_manifest_canonical_json($unsorted);
    $sortedFiles = '"files":{"a.php":"' . str_repeat('d', 64) . '","z.php":"' . str_repeat('c', 64) . '"}';
    $unsortedFiles = '"files":{"z.php":"' . str_repeat('c', 64) . '","a.php":"' . str_repeat('d', 64) . '"}';
    $unsortedRaw = str_replace($sortedFiles, $unsortedFiles, $unsortedRaw);
    $writeEnvelope($unsorted, $secretKey, $unsortedRaw);
    $check(site_health_deployment_manifest_load($path, base64_encode($publicKey))['reason'] === 'signed_deployment_manifest_invalid',
        'component file maps must already be sorted even when the payload signature is valid');

    $writeEnvelope($payload, $secretKey);
    $check(site_health_deployment_manifest_load('relative/extensions.json', base64_encode($publicKey))['reason'] === 'signed_deployment_manifest_invalid'
        && site_health_deployment_manifest_load($path, base64_encode($publicKey) . "\n")['reason'] === 'signed_deployment_manifest_invalid',
        'configuration requires an absolute path and canonical strict base64 public key');

    $link = $fixture . '/link.json';
    symlink($path, $link);
    $check(site_health_deployment_manifest_load($link, base64_encode($publicKey))['reason'] === 'signed_deployment_manifest_invalid',
        'manifest reads reject symbolic links');
    unlink($link);
    $check(site_health_deployment_manifest_load($path, base64_encode($publicKey), $fixture)['reason'] === 'signed_deployment_manifest_invalid',
        'a configured manifest cannot be read from the public root');

    $duplicate = $payload;
    $duplicate['components'][] = $duplicate['components'][0];
    $writeEnvelope($duplicate, $secretKey);
    $check(site_health_deployment_manifest_load($path, base64_encode($publicKey))['reason'] === 'signed_deployment_manifest_invalid',
        'component identities must be sorted and unique');

    $invalidRevision = $payload;
    $invalidRevision['source_revision'] = str_repeat('g', 40);
    $writeEnvelope($invalidRevision, $secretKey);
    $check(site_health_deployment_manifest_load($path, base64_encode($publicKey))['reason'] === 'signed_deployment_manifest_invalid',
        'payload schemas require an exact 40-hex source revision');

    $validRaw = site_health_deployment_manifest_canonical_json($payload);
    $validSignature = sodium_crypto_sign_detached(SITE_HEALTH_DEPLOYMENT_MANIFEST_DOMAIN . $validRaw, $secretKey);
    file_put_contents($path, json_encode([
        'schema' => 1, 'algorithm' => 'ed25519', 'payload' => base64_encode($validRaw),
        'signature' => base64_encode($validSignature), 'extra' => true,
    ], JSON_THROW_ON_ERROR));
    $check(site_health_deployment_manifest_load($path, base64_encode($publicKey))['reason'] === 'signed_deployment_manifest_invalid',
        'envelopes reject fields outside the exact schema');

    unset($_ENV['SITE_HEALTH_DEPLOYMENT_SOURCE_REVISION']);
    $check(site_health_deployment_manifest_load('', '', null, '') === ['configured' => false, 'components' => [], 'reason' => null],
        'an unconfigured deployment manifest preserves existing behavior only when all three settings are empty');
    $check(site_health_deployment_manifest_load('', '', null, str_repeat('a', 40))['reason'] === 'signed_deployment_manifest_invalid',
        'partial source-revision-only configuration fails closed');
} finally {
    unset($_ENV['SITE_HEALTH_DEPLOYMENT_SOURCE_REVISION']);
    @unlink($path);
    @rmdir($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " deployment extension manifest contract checks failed.\n");
    exit(1);
}
echo "Deployment extension manifest contract passed ({$checks} checks).\n";
