<?php
declare(strict_types=1);

const JYAVANI_PRODUCT_ID = 'org.jyavani';
const JYAVANI_DISTRIBUTION_ID = 'org.jyavani.php';
const JYAVANI_IMPLEMENTATION_ID = 'org.jyavani.php.mysql';
const JYAVANI_EXTENSION_IMPLEMENTATION_ID = 'org.jyavani.php.extensions';
const JYAVANI_THEME_IMPLEMENTATION_ID = 'org.jyavani.php.themes';
const JYAVANI_PLATFORM_CONTRACT_VERSION = '1.0.0';
const JYAVANI_EXTENSION_API_VERSION = '1.0.0';
const JYAVANI_THEME_API_VERSION = '1.0.0';
const JYAVANI_AWARE_CORE_VERSION_FLOOR = '2.3.195';

function jyavani_descriptor_fail(string $message): never
{
    throw new RuntimeException($message);
}

function jyavani_descriptor_object(mixed $value, string $label, array $keys): array
{
    if (!is_array($value) || array_is_list($value)) jyavani_descriptor_fail("$label must be a JSON object.");
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($keys, SORT_STRING);
    if ($actual !== $keys) jyavani_descriptor_fail("$label has an invalid shape.");
    return $value;
}

function jyavani_descriptor_string(mixed $value, string $label, int $max = 160): string
{
    if (!is_string($value)) jyavani_descriptor_fail("$label must be text.");
    $value = trim($value);
    if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        jyavani_descriptor_fail("$label is invalid.");
    }
    return $value;
}

function jyavani_descriptor_version(mixed $value, string $label): string
{
    $version = jyavani_descriptor_string($value, $label, 64);
    if (preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?\z/D', $version) !== 1) {
        jyavani_descriptor_fail("$label is invalid.");
    }
    return $version;
}

function jyavani_core_version_requires_aware_metadata(mixed $value): bool
{
    $version = trim((string)$value);
    if (preg_match('/\A([0-9]+(?:\.[0-9]+){0,2})(?:[-+][0-9A-Za-z.-]+)?\z/D', $version, $match) !== 1) {
        jyavani_descriptor_fail('Legacy Core version is invalid.');
    }
    return version_compare($match[1], JYAVANI_AWARE_CORE_VERSION_FLOOR, '>=');
}

function jyavani_descriptor_id(mixed $value, string $label): string
{
    $id = jyavani_descriptor_string($value, $label, 160);
    if (preg_match('/\A[a-z][a-z0-9]*(?:[.-][a-z][a-z0-9_-]*)+\z/D', $id) !== 1) {
        jyavani_descriptor_fail("$label is invalid.");
    }
    return $id;
}

function jyavani_descriptor_https_url(mixed $value, string $label): string
{
    $url = jyavani_descriptor_string($value, $label, 2048);
    if (filter_var($url, FILTER_VALIDATE_URL) === false
        || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https'
        || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
        jyavani_descriptor_fail("$label must be an HTTPS URL without credentials.");
    }
    return $url;
}

function jyavani_distribution_descriptor_validate(mixed $value, string $releaseVersion): array
{
    $descriptor = jyavani_descriptor_object($value, 'distribution', [
        'schema_version', 'distribution_id', 'implementation_id', 'name', 'version',
        'platform_contract', 'runtime', 'capabilities', 'extension_system', 'theme_system',
    ]);
    if (($descriptor['schema_version'] ?? null) !== 1) jyavani_descriptor_fail('Unsupported distribution descriptor schema.');
    $descriptor['distribution_id'] = jyavani_descriptor_id($descriptor['distribution_id'] ?? null, 'distribution.distribution_id');
    $descriptor['implementation_id'] = jyavani_descriptor_id($descriptor['implementation_id'] ?? null, 'distribution.implementation_id');
    if ($descriptor['distribution_id'] !== JYAVANI_DISTRIBUTION_ID
        || $descriptor['implementation_id'] !== JYAVANI_IMPLEMENTATION_ID) {
        jyavani_descriptor_fail('This package targets another Jyavani distribution.');
    }
    $descriptor['name'] = jyavani_descriptor_string($descriptor['name'] ?? null, 'distribution.name', 120);
    $descriptor['version'] = jyavani_descriptor_version($descriptor['version'] ?? null, 'distribution.version');
    if (!hash_equals($releaseVersion, $descriptor['version'])) jyavani_descriptor_fail('Distribution version does not match the release version.');
    $descriptor['platform_contract'] = jyavani_descriptor_version($descriptor['platform_contract'] ?? null, 'distribution.platform_contract');
    if (!hash_equals(JYAVANI_PLATFORM_CONTRACT_VERSION, $descriptor['platform_contract'])) {
        jyavani_descriptor_fail('Unsupported Jyavani platform contract.');
    }

    $runtime = jyavani_descriptor_object($descriptor['runtime'] ?? null, 'distribution.runtime', ['language', 'runtime', 'data_stores']);
    $runtime['language'] = jyavani_descriptor_string($runtime['language'] ?? null, 'distribution.runtime.language', 40);
    $runtime['runtime'] = jyavani_descriptor_string($runtime['runtime'] ?? null, 'distribution.runtime.runtime', 80);
    if (!is_array($runtime['data_stores'] ?? null) || !array_is_list($runtime['data_stores'])
        || $runtime['data_stores'] === [] || count($runtime['data_stores']) > 8) {
        jyavani_descriptor_fail('distribution.runtime.data_stores is invalid.');
    }
    $dataStores = [];
    foreach ($runtime['data_stores'] as $store) {
        $store = jyavani_descriptor_string($store, 'distribution.runtime.data_stores entry', 80);
        if (isset($dataStores[$store])) jyavani_descriptor_fail('Duplicate distribution data store.');
        $dataStores[$store] = true;
    }
    $runtime['data_stores'] = array_keys($dataStores);
    $descriptor['runtime'] = $runtime;

    if (!is_array($descriptor['capabilities'] ?? null) || !array_is_list($descriptor['capabilities'])
        || count($descriptor['capabilities']) > 128) jyavani_descriptor_fail('distribution.capabilities is invalid.');
    $capabilities = [];
    foreach ($descriptor['capabilities'] as $capability) {
        if (!is_array($capability) || array_is_list($capability)) jyavani_descriptor_fail('Invalid distribution capability.');
        $keys = array_keys($capability);
        sort($keys, SORT_STRING);
        if ($keys !== ['contract_version', 'id', 'status'] && $keys !== ['contract_version', 'id', 'limitations', 'status']) {
            jyavani_descriptor_fail('Invalid distribution capability shape.');
        }
        $id = jyavani_descriptor_id($capability['id'] ?? null, 'capability.id');
        if (isset($capabilities[$id])) jyavani_descriptor_fail('Duplicate distribution capability.');
        $status = $capability['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['native', 'adapted', 'partial', 'unsupported'], true)) {
            jyavani_descriptor_fail('Invalid distribution capability status.');
        }
        $normalized = [
            'id' => $id,
            'contract_version' => jyavani_descriptor_version($capability['contract_version'] ?? null, 'capability.contract_version'),
            'status' => $status,
        ];
        if (array_key_exists('limitations', $capability)) {
            $normalized['limitations'] = jyavani_descriptor_string($capability['limitations'], 'capability.limitations', 500);
        } elseif ($status === 'partial') {
            jyavani_descriptor_fail('Partial capabilities require limitations.');
        }
        $capabilities[$id] = $normalized;
    }
    $descriptor['capabilities'] = array_values($capabilities);

    foreach ([
        ['extension_system', JYAVANI_EXTENSION_IMPLEMENTATION_ID, JYAVANI_EXTENSION_API_VERSION],
        ['theme_system', JYAVANI_THEME_IMPLEMENTATION_ID, JYAVANI_THEME_API_VERSION],
    ] as [$key, $implementationId, $apiVersion]) {
        $system = jyavani_descriptor_object($descriptor[$key] ?? null, "distribution.$key", ['implementation_id', 'api_versions']);
        $system['implementation_id'] = jyavani_descriptor_id($system['implementation_id'] ?? null, "distribution.$key.implementation_id");
        if ($system['implementation_id'] !== $implementationId) {
            jyavani_descriptor_fail("distribution.$key targets another implementation API.");
        }
        if (!is_array($system['api_versions'] ?? null) || !array_is_list($system['api_versions'])
            || $system['api_versions'] === [] || count($system['api_versions']) > 16) {
            jyavani_descriptor_fail("distribution.$key.api_versions is invalid.");
        }
        $versions = [];
        foreach ($system['api_versions'] as $version) $versions[] = jyavani_descriptor_version($version, "distribution.$key.api_versions entry");
        if (count(array_unique($versions, SORT_STRING)) !== count($versions) || !in_array($apiVersion, $versions, true)) {
            jyavani_descriptor_fail("distribution.$key does not advertise the required API.");
        }
        $system['api_versions'] = $versions;
        $descriptor[$key] = $system;
    }
    return $descriptor;
}

function jyavani_release_contract_metadata_validate(mixed $productValue, mixed $distributionValue, mixed $contractsValue, string $releaseVersion): array
{
    $product = jyavani_descriptor_object($productValue, 'product', ['schema_version', 'id', 'name', 'platform_contract']);
    $product['id'] = jyavani_descriptor_id($product['id'] ?? null, 'product.id');
    $product['name'] = jyavani_descriptor_string($product['name'] ?? null, 'product.name', 120);
    $product['platform_contract'] = jyavani_descriptor_version($product['platform_contract'] ?? null, 'product.platform_contract');
    if (($product['schema_version'] ?? null) !== 1 || $product['id'] !== JYAVANI_PRODUCT_ID
        || $product['name'] !== 'Jyavani' || $product['platform_contract'] !== JYAVANI_PLATFORM_CONTRACT_VERSION) {
        jyavani_descriptor_fail('Invalid Jyavani product identity.');
    }

    $contractsValue = jyavani_descriptor_object($contractsValue, 'contracts', ['distribution_descriptor', 'portable_archive']);
    $contracts = [
        'distribution_descriptor' => jyavani_descriptor_version($contractsValue['distribution_descriptor'], 'contracts.distribution_descriptor'),
        'portable_archive' => jyavani_descriptor_version($contractsValue['portable_archive'], 'contracts.portable_archive'),
    ];
    if ($contracts !== ['distribution_descriptor' => '1.0.0', 'portable_archive' => '1.0.0']) {
        jyavani_descriptor_fail('Unsupported Jyavani contract versions.');
    }

    return [
        'product' => $product,
        'distribution' => jyavani_distribution_descriptor_validate($distributionValue, $releaseVersion),
        'contracts' => $contracts,
    ];
}

function jyavani_product_metadata_validate(mixed $value, ?string $versionFileValue = null): array
{
    if (!is_array($value) || array_is_list($value)) jyavani_descriptor_fail('version.json must contain a JSON object.');
    foreach (['name', 'version', 'build', 'changelog', 'edition', 'php_required', 'mysql_required', 'author', 'homepage', 'changelog_url', 'product', 'distribution', 'contracts'] as $key) {
        if (!array_key_exists($key, $value)) jyavani_descriptor_fail("version.json is missing $key.");
    }
    $normalized = $value;
    $normalized['name'] = jyavani_descriptor_string($value['name'], 'name', 120);
    $normalized['version'] = jyavani_descriptor_version($value['version'], 'version');
    if ($versionFileValue !== null && !hash_equals($normalized['version'], trim($versionFileValue))) {
        jyavani_descriptor_fail('VERSION does not match version.json.');
    }
    $normalized['build'] = jyavani_descriptor_string($value['build'], 'build', 10);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized['build']);
    if ($date === false || $date->format('Y-m-d') !== $normalized['build']) jyavani_descriptor_fail('build must be an ISO date.');
    foreach (['changelog', 'edition', 'php_required', 'mysql_required', 'author'] as $key) {
        $normalized[$key] = jyavani_descriptor_string($value[$key], $key, $key === 'changelog' ? 500 : 120);
    }
    $normalized['homepage'] = jyavani_descriptor_https_url($value['homepage'], 'homepage');
    $normalized['changelog_url'] = jyavani_descriptor_https_url($value['changelog_url'], 'changelog_url');

    $normalized = array_merge($normalized, jyavani_release_contract_metadata_validate(
        $value['product'],
        $value['distribution'],
        $value['contracts'],
        $normalized['version']
    ));
    return $normalized;
}

function jyavani_core_manifest_metadata_validate(array $metadata): array
{
    $awareKeys = ['product', 'distribution', 'contracts'];
    $present = array_values(array_filter($awareKeys, static fn(string $key): bool => array_key_exists($key, $metadata)));
    if ($present === []) {
        if (jyavani_core_version_requires_aware_metadata($metadata['version'] ?? null)) {
            jyavani_descriptor_fail('Jyavani product and distribution metadata is required for this Core version.');
        }
        return $metadata;
    }
    if (count($present) !== count($awareKeys)) jyavani_descriptor_fail('Incomplete Jyavani product or distribution metadata.');
    $version = jyavani_descriptor_version($metadata['version'] ?? null, 'version');
    return array_merge($metadata, jyavani_release_contract_metadata_validate(
        $metadata['product'],
        $metadata['distribution'],
        $metadata['contracts'],
        $version
    ));
}

function jyavani_core_package_distribution_errors(array $metadata): array
{
    $awareKeys = ['product', 'distribution', 'contracts'];
    $present = array_values(array_filter($awareKeys, static fn(string $key): bool => array_key_exists($key, $metadata)));
    if ($present === []) {
        try {
            return jyavani_core_version_requires_aware_metadata($metadata['version'] ?? null)
                ? ['Jyavani product and distribution metadata is required for this Core version.']
                : [];
        } catch (Throwable $error) {
            return [$error->getMessage()];
        }
    }
    if (count($present) !== count($awareKeys)) return ['Incomplete Jyavani product or distribution metadata'];
    try {
        jyavani_product_metadata_validate($metadata, is_string($metadata['version'] ?? null) ? $metadata['version'] : null);
        return [];
    } catch (Throwable $error) {
        return [$error->getMessage()];
    }
}

function jyavani_product_metadata(string $projectRoot): array
{
    $root = rtrim($projectRoot, '/\\');
    $raw = cms_manifest_read_bounded_regular_file($root . '/version.json', 128 * 1024);
    $versionRaw = cms_manifest_read_bounded_regular_file($root . '/VERSION', 128);
    if (!is_string($raw) || !is_string($versionRaw)) jyavani_descriptor_fail('Jyavani release metadata is unavailable.');
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        jyavani_descriptor_fail('Jyavani release metadata is invalid JSON.');
    }
    return jyavani_product_metadata_validate($decoded, $versionRaw);
}

function jyavani_installed_product_metadata(): array
{
    static $metadata = null;
    if (is_array($metadata)) return $metadata;
    $metadata = jyavani_product_metadata(dirname(__DIR__, 2));
    return $metadata;
}
