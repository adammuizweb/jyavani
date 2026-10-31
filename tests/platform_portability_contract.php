<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$platformRoot = $root . '/schema/platform';
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$loadJson = static function (string $path) use ($check): array {
    try {
        $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $check(is_array($value), substr($path, strrpos($path, '/') + 1) . ' decodes to a JSON object');
        return is_array($value) ? $value : [];
    } catch (Throwable $error) {
        $check(false, substr($path, strrpos($path, '/') + 1) . ' is valid JSON: ' . $error->getMessage());
        return [];
    }
};
$exactKeys = static function (array $value, array $keys): bool {
    $actual = array_keys($value);
    sort($actual);
    sort($keys);
    return $actual === $keys;
};
$localId = static fn(mixed $value): bool => is_string($value)
    && preg_match('/^[a-z][a-z0-9_-]{0,31}:[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $value) === 1;
$sha256 = static fn(mixed $value): bool => is_string($value)
    && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
$namespacedId = static fn(mixed $value): bool => is_string($value)
    && preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z][a-z0-9_-]*)+$/D', $value) === 1;

$jsonPaths = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($platformRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'json') {
        $jsonPaths[] = $file->getPathname();
    }
}
sort($jsonPaths);
$json = [];
foreach ($jsonPaths as $path) $json[$path] = $loadJson($path);
$check(count($jsonPaths) === 9, 'the contract ships four schemas and five focused JSON fixtures');

$descriptorSchemaPath = $platformRoot . '/distribution-descriptor.schema.json';
$manifestSchemaPath = $platformRoot . '/portable-archive-manifest-v1.schema.json';
$recordSchemaPath = $platformRoot . '/portable-core-record-v1.schema.json';
$packageProfileSchemaPath = $platformRoot . '/implementation-package-profile.schema.json';
$descriptorSchema = $json[$descriptorSchemaPath] ?? [];
$manifestSchema = $json[$manifestSchemaPath] ?? [];
$recordSchema = $json[$recordSchemaPath] ?? [];
$packageProfileSchema = $json[$packageProfileSchemaPath] ?? [];

$strictObjects = static function (mixed $node, string $path = '$') use (&$strictObjects): array {
    $missing = [];
    if (!is_array($node)) return $missing;
    if (($node['type'] ?? null) === 'object' && ($node['additionalProperties'] ?? null) !== false) {
        $missing[] = $path;
    }
    foreach ($node as $key => $child) {
        if (is_array($child)) {
            $missing = array_merge($missing, $strictObjects($child, $path . '/' . (string)$key));
        }
    }
    return $missing;
};
$check(($descriptorSchema['$schema'] ?? null) === 'https://json-schema.org/draft/2020-12/schema'
    && ($manifestSchema['$schema'] ?? null) === 'https://json-schema.org/draft/2020-12/schema'
    && ($recordSchema['$schema'] ?? null) === 'https://json-schema.org/draft/2020-12/schema'
    && ($packageProfileSchema['$schema'] ?? null) === 'https://json-schema.org/draft/2020-12/schema',
    'all schemas declare JSON Schema Draft 2020-12');
$check($strictObjects($descriptorSchema) === []
    && $strictObjects($manifestSchema) === []
    && $strictObjects($recordSchema) === []
    && $strictObjects($packageProfileSchema) === [],
    'every schema object shape rejects unknown properties');
$check(($descriptorSchema['properties']['schema_version']['const'] ?? null) === 1
    && ($descriptorSchema['properties']['platform_contract']['const'] ?? null) === '1.0.0'
    && ($descriptorSchema['$defs']['implementationContract']['properties']['api_versions']['minItems'] ?? null) === 1
    && isset($descriptorSchema['properties']['capabilities'], $descriptorSchema['properties']['extension_system'], $descriptorSchema['properties']['theme_system']),
    'distribution descriptor versions capabilities and implementation-specific extension/theme systems');
$check(($packageProfileSchema['properties']['schema']['const'] ?? null) === 1
    && ($packageProfileSchema['required'] ?? null) === ['schema', 'distribution_id', 'implementation_id', 'api']
    && ($packageProfileSchema['additionalProperties'] ?? null) === false,
    'implementation package profile is atomic and schema-versioned');
$check(($manifestSchema['properties']['format']['const'] ?? null) === 'org.jyavani.portable-archive'
    && ($manifestSchema['properties']['format_version']['const'] ?? null) === 1
    && ($manifestSchema['properties']['operation']['const'] ?? null) === 'create_only'
    && ($manifestSchema['properties']['identity_scope']['const'] ?? null) === 'archive_local',
    'manifest schema fixes archive v1 to create-only archive-local identity');
$check(($manifestSchema['$defs']['security']['properties']['contains_executable_code']['const'] ?? null) === false
    && ($manifestSchema['$defs']['security']['properties']['contains_secrets']['const'] ?? null) === false
    && ($manifestSchema['$defs']['sha256']['pattern'] ?? null) === '^[a-f0-9]{64}$',
    'manifest schema prohibits code and secrets and requires lowercase SHA-256');
$check(isset($manifestSchema['properties']['capabilities'], $manifestSchema['properties']['extensions'])
    && ($manifestSchema['$defs']['extension']['required'] ?? []) === [
        'kind', 'implementation_id', 'package_id', 'package_version',
        'api_contract_version', 'required', 'record_types'
    ], 'manifest schema carries explicit capability and complete package identity declarations');

$coreTypes = [
    'core.actor_profile.v1',
    'core.content.v1',
    'core.taxonomy_term.v1',
    'core.asset.v1',
    'core.relationship.v1',
    'core.navigation_item.v1',
    'core.setting.v1',
];
$recordConstants = [];
foreach (($recordSchema['$defs'] ?? []) as $definition) {
    $constant = $definition['properties']['record_type']['const'] ?? null;
    if (is_string($constant)) $recordConstants[] = $constant;
}
sort($recordConstants);
$sortedCoreTypes = $coreTypes;
sort($sortedCoreTypes);
$check($recordConstants === $sortedCoreTypes, 'core record schema defines the complete closed v1 record type set');
$check(isset($recordSchema['$defs']['utcTimestamp'], $recordSchema['$defs']['localCivilTimestamp'])
    && ($recordSchema['$defs']['utcTimestamp']['properties']['kind']['const'] ?? null) === 'utc'
    && ($recordSchema['$defs']['localCivilTimestamp']['properties']['kind']['const'] ?? null) === 'local_civil'
    && in_array('timezone', $recordSchema['$defs']['localCivilTimestamp']['required'] ?? [], true),
    'core timestamps distinguish UTC instants from zoned local civil values');
$check(($recordSchema['$defs']['assetPayload']['properties']['blob_sha256']['$ref'] ?? null) === '#/$defs/sha256'
    && isset($recordSchema['$defs']['assetPayload']['properties']['visibility'])
    && isset($recordSchema['$defs']['assetPayload']['properties']['access_scope']),
    'asset records separate blob identity, visibility, and access scope');
$settingKeys = $recordSchema['$defs']['settingPayload']['properties']['key']['enum'] ?? null;
$check(is_array($settingKeys) && count($settingKeys) === 6
    && !in_array('authentication', $settingKeys, true)
    && !in_array('secret', $settingKeys, true),
    'portable settings use a closed non-secret allowlist');

$validateDescriptor = static function (array $descriptor) use ($exactKeys, $namespacedId): bool {
    if (!$exactKeys($descriptor, [
        'schema_version', 'distribution_id', 'implementation_id', 'name', 'version',
        'platform_contract', 'runtime', 'capabilities', 'extension_system', 'theme_system'
    ])) return false;
    if (($descriptor['schema_version'] ?? null) !== 1
        || ($descriptor['platform_contract'] ?? null) !== '1.0.0'
        || !$namespacedId($descriptor['distribution_id'] ?? null)
        || !$namespacedId($descriptor['implementation_id'] ?? null)) return false;
    $seen = [];
    foreach (($descriptor['capabilities'] ?? null) as $capability) {
        if (!is_array($capability) || !$namespacedId($capability['id'] ?? null)) return false;
        $id = $capability['id'];
        if (isset($seen[$id]) || !in_array($capability['status'] ?? null, ['native', 'adapted', 'partial', 'unsupported'], true)) return false;
        if (($capability['status'] ?? null) === 'partial' && !is_string($capability['limitations'] ?? null)) return false;
        $seen[$id] = true;
    }
    return true;
};
$validateManifest = static function (array $manifest) use ($exactKeys, $localId, $sha256, $namespacedId, $coreTypes): bool {
    if (!$exactKeys($manifest, [
        'format', 'format_version', 'operation', 'identity_scope', 'archive_id', 'created_at_utc',
        'source_distribution', 'site_timezone', 'timestamp_semantics', 'capabilities', 'extensions',
        'records', 'blobs', 'security'
    ])) return false;
    if (($manifest['format'] ?? null) !== 'org.jyavani.portable-archive'
        || ($manifest['format_version'] ?? null) !== 1
        || ($manifest['operation'] ?? null) !== 'create_only'
        || ($manifest['identity_scope'] ?? null) !== 'archive_local'
        || !$localId($manifest['archive_id'] ?? null)
        || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D', (string)($manifest['created_at_utc'] ?? '')) !== 1
        || ($manifest['security'] ?? null) !== ['contains_executable_code' => false, 'contains_secrets' => false]
        || ($manifest['timestamp_semantics']['utc_instants'] ?? null) !== 'rfc3339_utc_z'
        || ($manifest['timestamp_semantics']['local_civil'] ?? null) !== 'local_civil_with_iana_zone_no_implicit_conversion') return false;
    $declaredRecordTypes = [];
    foreach (($manifest['extensions'] ?? null) as $extension) {
        if (!is_array($extension)
            || !$exactKeys($extension, ['kind', 'implementation_id', 'package_id', 'package_version', 'api_contract_version', 'required', 'record_types'])
            || !$namespacedId($extension['implementation_id'] ?? null)
            || !$namespacedId($extension['package_id'] ?? null)) return false;
        foreach (($extension['record_types'] ?? []) as $type) $declaredRecordTypes[$type] = true;
    }
    $ids = [];
    $paths = [];
    foreach (($manifest['records'] ?? null) as $record) {
        if (!is_array($record)
            || !$exactKeys($record, ['local_id', 'record_type', 'path', 'required', 'sha256'])
            || !$localId($record['local_id'] ?? null)
            || !$namespacedId($record['record_type'] ?? null)
            || preg_match('#^records/[A-Za-z0-9][A-Za-z0-9._-]{0,159}\.json$#D', (string)($record['path'] ?? '')) !== 1
            || !is_bool($record['required'] ?? null)
            || !$sha256($record['sha256'] ?? null)
            || isset($ids[$record['local_id']])
            || isset($paths[$record['path']])) return false;
        $known = in_array($record['record_type'], $coreTypes, true) || isset($declaredRecordTypes[$record['record_type']]);
        if ($record['required'] && !$known) return false;
        $ids[$record['local_id']] = true;
        $paths[$record['path']] = true;
    }
    foreach (($manifest['blobs'] ?? null) as $blob) {
        if (!is_array($blob) || !$sha256($blob['sha256'] ?? null)
            || ($blob['path'] ?? null) !== 'blobs/sha256/' . $blob['sha256']) return false;
    }
    return true;
};

$validDescriptor = $json[$platformRoot . '/fixtures/valid-distribution.json'] ?? [];
$validPackageProfile = $json[$platformRoot . '/fixtures/valid-implementation-package-profile.json'] ?? [];
$validManifest = $json[$platformRoot . '/fixtures/valid-manifest.json'] ?? [];
$validRecord = $json[$platformRoot . '/fixtures/valid-content-record.json'] ?? [];
$invalidManifest = $json[$platformRoot . '/fixtures/invalid-unknown-required-manifest.json'] ?? [];
$check($validateDescriptor($validDescriptor), 'synthetic distribution descriptor satisfies key strict invariants');
$check($exactKeys($validPackageProfile, ['schema', 'distribution_id', 'implementation_id', 'api'])
    && ($validPackageProfile['schema'] ?? null) === 1
    && $namespacedId($validPackageProfile['distribution_id'] ?? null)
    && $namespacedId($validPackageProfile['implementation_id'] ?? null),
    'synthetic implementation package profile binds distribution, implementation, and API');
$check($validateManifest($validManifest), 'synthetic archive manifest satisfies key strict invariants');
$check(!$validateManifest($invalidManifest), 'an unknown required record fails closed before import');

$timestampValues = $validRecord['payload']['timestamps'] ?? [];
$check($exactKeys($validRecord, ['schema_version', 'local_id', 'record_type', 'payload'])
    && ($validRecord['schema_version'] ?? null) === 1
    && ($validRecord['record_type'] ?? null) === 'core.content.v1'
    && $localId($validRecord['local_id'] ?? null),
    'synthetic core record uses a strict v1 envelope and archive-local ID');
$check(($timestampValues['created']['kind'] ?? null) === 'local_civil'
    && ($timestampValues['created']['timezone'] ?? null) === 'Europe/Berlin'
    && !str_ends_with((string)($timestampValues['created']['value'] ?? ''), 'Z')
    && ($timestampValues['updated']['kind'] ?? null) === 'utc'
    && str_ends_with((string)($timestampValues['updated']['value'] ?? ''), 'Z'),
    'synthetic record preserves local civil and UTC timestamp kinds without conflation');
$recordJson = strtolower((string)json_encode($validRecord, JSON_UNESCAPED_SLASHES));
$check(!preg_match('/password|secret|api[_-]?key|session|private[_-]?key|token/', $recordJson),
    'synthetic portable record contains no authentication material or secrets');

$platformDoc = (string)file_get_contents($platformRoot . '/PLATFORM.md');
$archiveDoc = (string)file_get_contents($platformRoot . '/PORTABLE_ARCHIVE_V1.md');
$check(str_contains($platformDoc, '## Product philosophy')
    && str_contains($platformDoc, '## Platform invariants')
    && str_contains($platformDoc, '## Shared and distribution-specific boundary')
    && str_contains($platformDoc, '## Domain vocabulary')
    && str_contains($platformDoc, '## Capability parity matrix'),
    'platform document covers philosophy, invariants, boundaries, vocabulary, and parity');
$check(str_contains($platformDoc, 'content.created.v1')
    && str_contains($platformDoc, 'asset.created.v1')
    && str_contains($platformDoc, 'archive.imported.v1')
    && str_contains($platformDoc, 'completed domain facts rather than commands'),
    'platform document defines versioned implementation-neutral semantic events');
$check(str_contains($platformDoc, 'Extension/theme implementation identity is the tuple')
    && str_contains($platformDoc, 'not implementation identity')
    && str_contains($platformDoc, 'not a portable semantic identity')
    && str_contains($platformDoc, 'Legacy and aware metadata MUST NOT be'),
    'platform document prevents slug, label, or presentation identity confusion');
$check(str_contains($archiveDoc, 'Archive v1 is explicitly create-only')
    && str_contains($archiveDoc, 'MUST NOT merge, update, upsert, overwrite')
    && str_contains($archiveDoc, 'Archive-local IDs MUST NOT be stored'),
    'archive document limits pre-UUID import to create-only archive-local identity');
$check(str_contains($archiveDoc, 'No executable code is permitted in an archive')
    && str_contains($archiveDoc, 'Secrets MUST NOT be exported')
    && str_contains($archiveDoc, 'MUST never receive executable permission'),
    'archive document states mandatory executable-code and secret exclusions');
$check(str_contains($archiveDoc, 'MUST fail closed on every unknown required record type')
    && str_contains($archiveDoc, 'before making any persistent write')
    && str_contains($archiveDoc, 'Duplicate JSON member names are invalid'),
    'archive document requires complete fail-closed preflight validation');
$check(str_contains($archiveDoc, 'SHA-256 is written as exactly 64 lowercase hexadecimal characters')
    && str_contains($archiveDoc, 'hash exact staged bytes')
    && str_contains($archiveDoc, 'Filenames are display metadata'),
    'archive document binds blobs to exact-byte SHA-256 rather than paths or names');
$check(str_contains($archiveDoc, 'A UTC instant is')
    && str_contains($archiveDoc, 'A local civil value is')
    && str_contains($archiveDoc, 'MUST NOT silently convert it to UTC'),
    'archive document makes UTC and local-civil timestamp semantics explicit');
$check(str_contains($archiveDoc, 'package kind, implementation ID, package ID, package version, API contract version')
    && str_contains($archiveDoc, 'trusted package policy')
    && str_contains($archiveDoc, 'they do not carry or authorize code'),
    'archive document declares capabilities and exact extension/theme identity without code trust');
$check(str_contains($platformDoc, 'does not define runtime exporter/importer code')
    && str_contains($platformDoc, 'database migration'),
    'Phase 0 explicitly excludes runtime importer/exporter and migration work');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " platform portability contract check(s) failed.\n");
    exit(1);
}

echo "RESULT: ALL PASS ({$checks} checks)\n";
