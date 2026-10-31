<?php
declare(strict_types=1);

require_once __DIR__ . '/cms_manifest.php';
require_once __DIR__ . '/product_descriptor.php';

function extension_parse_version_constraint(string $requirement): ?array
{
    $requirement = trim($requirement);
    if ($requirement === '') return [];
    $groups = preg_split('/\s*\|\|\s*/', $requirement);
    if (!is_array($groups) || in_array('', $groups, true)) return null;
    $parsed = [];
    foreach ($groups as $group) {
        $clauses = preg_split('/\s*,\s*|\s+(?=(?:\^|~|>=|<=|>|<|==|=)?\s*\d)/', trim($group));
        if (!is_array($clauses) || $clauses === [] || in_array('', $clauses, true)) return null;
        $parsedGroup = [];
        foreach ($clauses as $clause) {
            if (preg_match('/\A(\^|~|>=|<=|>|<|==|=)?\s*(\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?)\z/', $clause, $match) !== 1) return null;
            $parsedGroup[] = [$match[1] ?? '', $match[2]];
        }
        $parsed[] = $parsedGroup;
    }
    return $parsed;
}

function extension_version_requirement_met(string $current, string $requirement): bool
{
    if (preg_match('/\A\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?\z/', $current) !== 1) return false;
    $groups = extension_parse_version_constraint($requirement);
    if ($groups === null) return false;
    if ($groups === []) return true;
    foreach ($groups as $clauses) {
        $passed = true;
        foreach ($clauses as [$operator, $version]) {
            if ($operator === '^' || $operator === '~') {
                $numbers = array_map('intval', explode('.', preg_split('/[-+]/', $version, 2)[0]));
                $numbers = array_pad($numbers, 3, 0);
                if ($operator === '^') {
                    if ($numbers[0] > 0) $upper = ($numbers[0] + 1) . '.0.0';
                    elseif ($numbers[1] > 0) $upper = '0.' . ($numbers[1] + 1) . '.0';
                    else $upper = '0.0.' . ($numbers[2] + 1);
                } else {
                    $upper = $numbers[0] . '.' . ($numbers[1] + 1) . '.0';
                }
                if (!version_compare($current, $version, '>=') || !version_compare($current, $upper, '<')) $passed = false;
            } else {
                $comparison = $operator === '' || $operator === '=' ? '>=' : $operator;
                if (!version_compare($current, $version, $comparison)) $passed = false;
            }
            if (!$passed) break;
        }
        if ($passed) return true;
    }
    return false;
}

function extension_canonical_version_requirement(string $requirement): ?string
{
    $groups = extension_parse_version_constraint($requirement);
    if ($groups === null) return null;
    if ($groups === []) return '';
    $canonical = [];
    foreach ($groups as $clauses) {
        $values = [];
        foreach ($clauses as [$operator, $version]) $values[] = ($operator === '' || $operator === '=' ? '>=' : $operator) . $version;
        $canonical[] = implode(' ', $values);
    }
    return implode(' || ', $canonical);
}

function extension_technology_profile(array $metadata, string $kind): array
{
    if (!in_array($kind, ['plugin', 'theme'], true)) throw new InvalidArgumentException('Unknown extension kind.');
    if (!array_key_exists('technology', $metadata)) return ['aware' => false, 'profile' => null, 'errors' => []];
    $value = $metadata['technology'];
    $errors = [];
    if (!is_array($value) || array_is_list($value)) {
        return ['aware' => true, 'profile' => null, 'errors' => ['Invalid technology compatibility declaration']];
    }
    $keys = array_keys($value);
    sort($keys, SORT_STRING);
    if ($keys !== ['api', 'distribution_id', 'implementation_id', 'schema']) {
        $errors[] = 'Invalid technology compatibility declaration shape';
    }
    if (($value['schema'] ?? null) !== 1) $errors[] = 'Unsupported technology compatibility schema';
    $distribution = is_string($value['distribution_id'] ?? null) ? trim($value['distribution_id']) : '';
    $implementation = is_string($value['implementation_id'] ?? null) ? trim($value['implementation_id']) : '';
    $api = is_string($value['api'] ?? null) ? trim($value['api']) : '';
    if ($distribution === '' || strlen($distribution) > 160
        || preg_match('/\A[a-z][a-z0-9]*(?:[.-][a-z][a-z0-9_-]*)+\z/D', $distribution) !== 1) {
        $errors[] = 'Invalid technology distribution identity';
    }
    if ($implementation === '' || strlen($implementation) > 160
        || preg_match('/\A[a-z][a-z0-9]*(?:[.-][a-z][a-z0-9_-]*)+\z/D', $implementation) !== 1) {
        $errors[] = 'Invalid extension implementation identity';
    }
    if ($api === '' || strlen($api) > 160 || extension_canonical_version_requirement($api) === null) {
        $errors[] = 'Invalid extension API requirement';
    }
    $expectedImplementation = $kind === 'plugin' ? JYAVANI_EXTENSION_IMPLEMENTATION_ID : JYAVANI_THEME_IMPLEMENTATION_ID;
    $currentApi = $kind === 'plugin' ? JYAVANI_EXTENSION_API_VERSION : JYAVANI_THEME_API_VERSION;
    if ($distribution !== '' && !hash_equals(JYAVANI_DISTRIBUTION_ID, $distribution)) {
        $errors[] = 'Extension targets another Jyavani distribution';
    }
    if ($implementation !== '' && !hash_equals($expectedImplementation, $implementation)) {
        $errors[] = 'Extension targets another implementation API';
    }
    if ($api !== '' && extension_canonical_version_requirement($api) !== null
        && !extension_version_requirement_met($currentApi, $api)) {
        $errors[] = ucfirst($kind) . ' API requirement is not satisfied';
    }
    $profile = [
        'schema' => 1,
        'distribution_id' => $distribution,
        'implementation_id' => $implementation,
        'api' => $api,
    ];
    return ['aware' => true, 'profile' => $profile, 'errors' => array_values(array_unique($errors))];
}

function extension_technology_errors(array $metadata, string $kind): array
{
    return extension_technology_profile($metadata, $kind)['errors'];
}

function extension_technology_parity_errors(array $authoritative, array $package, string $kind): array
{
    $expected = extension_technology_profile($authoritative, $kind);
    $actual = extension_technology_profile($package, $kind);
    $errors = array_merge($expected['errors'], $actual['errors']);
    if ($expected['aware'] !== $actual['aware']) {
        $errors[] = 'Store and package technology declarations do not match';
    } elseif ($expected['aware'] && $actual['aware']) {
        $left = $expected['profile'];
        $right = $actual['profile'];
        if (!is_array($left) || !is_array($right)
            || ($left['schema'] ?? null) !== ($right['schema'] ?? null)
            || ($left['distribution_id'] ?? null) !== ($right['distribution_id'] ?? null)
            || ($left['implementation_id'] ?? null) !== ($right['implementation_id'] ?? null)
            || extension_canonical_version_requirement((string)($left['api'] ?? ''))
                !== extension_canonical_version_requirement((string)($right['api'] ?? ''))) {
            $errors[] = 'Store and package technology declarations do not match';
        }
    }
    return array_values(array_unique($errors));
}
