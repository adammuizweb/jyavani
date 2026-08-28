<?php
declare(strict_types=1);

final class PresetContractStatement extends PDOStatement
{
    public function __construct(private array $rows = []) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) {
            $column = (int)($args[0] ?? 0);
            return array_map(static fn(array $row): mixed => array_values($row)[$column] ?? null, $this->rows);
        }
        return $this->rows;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?: false;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = array_shift($this->rows);
        return is_array($row) ? (array_values($row)[$column] ?? false) : false;
    }
}

final class PresetContractPdo extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, "status = 'published'")) {
            return new PresetContractStatement([
                ['id' => 71, 'slug' => 'static_collision', 'meta' => '{}'],
                ['id' => 72, 'slug' => 'runtime_preset', 'meta' => '{"plugin_key":{"kept":true}}'],
                ['id' => 73, 'slug' => 'invalid.dot', 'meta' => '{}'],
                ['id' => 74, 'slug' => 'provider_runtime_preset', 'meta' => '{"source":"provider_feed","source_owner":"provider-plugin","layout":"list","author":8,"date_from":"2026-01-01","date_to":"2026-12-31","saved_private":"saved-value"}'],
            ]);
        }
        return new PresetContractStatement([]);
    }
}

final class PresetTransactionContractPdo extends PDO
{
    public function __construct(public bool $active) {}
    public function inTransaction(): bool { return $this->active; }
}

$root = dirname(__DIR__);
define('PUBLIC_PATH', $root . '/public');
define('VIEWS_BASE', PUBLIC_PATH . '/views/themes');
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/widget_helper.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$invalidFilters = shortcode_preset_list_filters(['p' => ['2'], 'q' => ['bad'], 'status' => ['deleted'], 'owner' => ['9']], false);
$check($invalidFilters === ['p' => 1, 'q' => '', 'status' => '', 'owner' => 0], 'list filters reject malformed pages, statuses, and non-admin owner filters');
$filters = shortcode_preset_list_filters(['p' => '3', 'q' => '  hero_100%  ', 'status' => 'private', 'owner' => '9'], true);
$check($filters['p'] === 3 && $filters['owner'] === 9 && $filters['status'] === 'private', 'admin list filters retain validated pagination, owner, and status');
$adminSpec = shortcode_preset_list_spec($filters, 4, 'admin');
$check(str_contains($adminSpec['where'], 'p.created_by = :owner') && ($adminSpec['params'][':owner'] ?? 0) === 9, 'admin owner filter is parameterized');
$check(($adminSpec['params'][':search'] ?? '') === '%hero\\_100\\%%', 'title and slug search escapes LIKE wildcards');
$authorSpec = shortcode_preset_list_spec($filters, 4, 'author');
$check(str_contains($authorSpec['where'], 'p.created_by = :uid') && !str_contains($authorSpec['where'], ':owner'), 'non-admin list visibility is always ownership scoped');

$check(shortcode_preset_slug_is_valid('hero_cards-2'), 'preset slug grammar accepts lowercase widget identifiers');
$check(!shortcode_preset_slug_is_valid('hero.cards') && !shortcode_preset_slug_is_valid('Hero'), 'preset slug grammar rejects names the shortcode parser cannot consume');
$check(shortcode_preset_slugify('Hero Cards!') === 'hero-cards', 'preset slug generation produces parser-safe identifiers');

add_filter('shortcode_preset_defaults', static function (array $defaults): array {
    $defaults['plugin_default'] = 'default-value';
    return $defaults;
});
add_filter('shortcode_preset_config_loaded', static function (array $config): array {
    $config['loaded_hook'] = true;
    return $config;
});
$loaded = shortcode_preset_config_loaded('{"plugin_key":{"kept":true}}');
$check(($loaded['plugin_default'] ?? '') === 'default-value' && ($loaded['plugin_key']['kept'] ?? false), 'defaults and stored unknown plugin keys survive config loading');
$check(($loaded['loaded_hook'] ?? false) === true, 'config-loaded filter runs');

add_filter('shortcode_preset_sources', static function (array $sources): array {
    $sources[] = 'plugin_feed';
    $sources[] = '2legacy_feed';
    $sources[] = 'Invalid Source';
    return $sources;
});
$sources = shortcode_preset_sources(['scope' => 'contract']);
$check(in_array('posts', $sources, true) && in_array('plugin_feed', $sources, true), 'source filter adds plugin sources without removing Core sources');
$check(in_array('2legacy_feed', $sources, true), 'legacy numeric-leading source identifiers remain valid');
$check(!in_array('invalid source', $sources, true), 'source filter rejects identifiers outside the source grammar');
$pluginSourceAdmin = shortcode_preset_validate_config(array_merge($loaded, ['source' => 'plugin_feed']), true, null, ['scope' => 'contract']);
$check(in_array('Source provider is unavailable.', $pluginSourceAdmin['errors'], true), 'legacy allowlisted sources without providers cannot be newly persisted');
$pluginSourceAuthor = shortcode_preset_validate_config(array_merge($loaded, ['source' => 'plugin_feed']), false, null, ['scope' => 'contract']);
$check(in_array('Only administrators can use non-Core preset sources.', $pluginSourceAuthor['errors'], true), 'plugin sources remain restricted for non-admin users');
$check(post_cat__safe_source('plugin_feed') === 'plugin_feed', 'runtime source parsing honors registered plugin sources');
$check(post_cat__safe_source('missing_feed') === '', 'explicit unavailable sources fail closed instead of becoming posts');
$check(post_cat__safe_source('') === 'posts', 'missing legacy source still defaults to posts');

$providerCalls = [];
$check(register_shortcode_source_provider('provider_feed', [
    'owner' => 'provider-plugin',
    'label' => 'Provider feed',
    'defaults' => ['limit' => 7, 'provider_default' => 'yes', 'editor_only' => 'editor-default', 'provider_secret' => 'server-only'],
    'client_fields' => ['provider_default', 'editor_only'],
    'public_override_fields' => ['provider_default'],
    'validate' => static function (array $config, array $context) use (&$providerCalls): array {
        $providerCalls[] = ['validate', $context['scope'] ?? '', $context['is_admin'] ?? null, $context['trust'] ?? '', [
            'preset_id' => $context['preset_id'] ?? null,
            'widget_name' => $context['__widget_name'] ?? null,
            'provider_default' => $config['provider_default'] ?? null,
            'editor_only' => $config['editor_only'] ?? null,
            'provider_secret' => $config['provider_secret'] ?? null,
            'saved_private' => $config['saved_private'] ?? null,
            'undeclared_provider_field' => $config['undeclared_provider_field'] ?? null,
        ]];
        $errors = [];
        if (!in_array($config['provider_default'] ?? null, ['yes', 'public-choice'], true)) {
            $errors[] = 'Invalid provider public override.';
        } elseif (($config['provider_default'] ?? '') === 'public-choice') {
            $config['provider_default'] = 'validated-public-choice';
        }
        $config['provider_validated'] = true;
        $config['limit'] = 999;
        return ['config' => $config, 'errors' => $errors];
    },
    'fetch' => static function (PDO $pdo, array $attrs, array $context) use (&$providerCalls): array {
        $providerCalls[] = ['fetch', $attrs['limit'] ?? 0, $context['scope'] ?? '', [
            'source' => $attrs['source'] ?? '',
            'source_owner' => $attrs['source_owner'] ?? '',
            'author' => $attrs['author'] ?? null,
            'created_by' => $attrs['created_by'] ?? null,
            'date_from' => $attrs['date_from'] ?? null,
            'date_to' => $attrs['date_to'] ?? null,
            'date_after' => $attrs['date_after'] ?? null,
            'date_before' => $attrs['date_before'] ?? null,
            'provider_default' => $attrs['provider_default'] ?? null,
            'editor_only' => $attrs['editor_only'] ?? null,
            'provider_secret' => $attrs['provider_secret'] ?? null,
            'saved_private' => $attrs['saved_private'] ?? null,
            'undeclared_provider_field' => $attrs['undeclared_provider_field'] ?? null,
        ]];
        return ['items' => [[
            'kind' => 'provider_item',
            'title' => 'Provider title',
            'url' => 'javascript:alert(1)',
            'thumb' => '/image.jpg',
            'desc' => 'Provider description',
            'date_iso' => '',
            'date_label' => '',
        ]]];
    },
]), 'valid source provider registers');
$check(!register_shortcode_source_provider('posts', ['owner' => 'other-plugin', 'label' => 'Override', 'fetch' => static fn(): array => ['items' => []]]), 'Core sources are collision protected');
$check(!register_shortcode_source_provider('provider_feed', ['owner' => 'other-plugin', 'label' => 'Collision', 'fetch' => static fn(): array => ['items' => []]]), 'first source provider wins collisions');
$check(!register_shortcode_source_provider('Invalid.Source', ['owner' => 'other-plugin', 'label' => 'Invalid', 'fetch' => static fn(): array => ['items' => []]]), 'invalid source provider identifiers are rejected');
$check(!register_shortcode_source_provider('owner_missing', ['label' => 'Missing owner', 'fetch' => static fn(): array => ['items' => []]]), 'source providers require a stable owner identity');
$check(!register_shortcode_source_provider('unsafe_client_fields', ['owner' => 'other-plugin', 'label' => 'Unsafe fields', 'client_fields' => ['limit'], 'fetch' => static fn(): array => ['items' => []]]), 'provider client fields cannot claim Core config keys');
$check(!register_shortcode_source_provider('unsafe_public_fields', ['owner' => 'other-plugin', 'label' => 'Unsafe public fields', 'client_fields' => ['safe_field'], 'public_override_fields' => ['undeclared_field'], 'fetch' => static fn(): array => ['items' => []]]), 'public override fields must be declared client fields');
$check(register_shortcode_source_provider('2provider', ['owner' => 'legacy-plugin', 'label' => 'Numeric provider', 'fetch' => static fn(): array => ['items' => []]]), 'numeric-leading provider identifiers remain registerable');
$check(register_shortcode_source_provider('replacement_feed', [
    'owner' => 'replacement-plugin',
    'label' => 'Replacement feed',
    'defaults' => ['limit' => 11, 'provider_default' => 'replacement', 'replacement_secret' => 'new-server-only'],
    'client_fields' => ['provider_default'],
    'fetch' => static fn(): array => ['items' => []],
]), 'replacement provider registers for source transition testing');
add_filter('shortcode_source_providers', static function (array $providers): array {
    $providers[] = ['id' => 'filter_feed', 'owner' => 'filter-plugin', 'label' => 'Filter feed', 'fetch' => static fn(): array => ['items' => []]];
    $providers[] = ['id' => 'posts', 'owner' => 'other-plugin', 'label' => 'Override', 'fetch' => static fn(): array => ['items' => []]];
    return $providers;
});
$providerDefinitions = shortcode_source_providers();
$check(isset($providerDefinitions['provider_feed'], $providerDefinitions['filter_feed']) && !isset($providerDefinitions['posts']), 'provider filter appends definitions without replacing registrations or Core sources');
$selectableSources = shortcode_selectable_sources(['scope' => 'contract']);
$check(in_array('provider_feed', $selectableSources, true) && !in_array('plugin_feed', $selectableSources, true)
    && !in_array('2legacy_feed', $selectableSources, true), 'legacy filter-only sources remain compatible but are not newly selectable');
$redactedEditorialConfig = shortcode_preset_strip_provider_default_fields(
    ['provider_default' => 'secret', 'limit' => 9, 'stored_extension_value' => 'kept'],
    $providerDefinitions
);
$check(!array_key_exists('provider_default', $redactedEditorialConfig) && ($redactedEditorialConfig['limit'] ?? 0) === 9
    && ($redactedEditorialConfig['stored_extension_value'] ?? '') === 'kept', 'non-admin serialization strips provider-only default fields without removing Core or unrelated stored values');
$providerLoaded = shortcode_preset_config_loaded(['source' => 'provider_feed', 'source_owner' => 'provider-plugin', 'layout' => 'list']);
$check(($providerLoaded['limit'] ?? 0) === 7 && ($providerLoaded['provider_default'] ?? '') === 'yes'
    && ($providerLoaded['editor_only'] ?? '') === 'editor-default'
    && ($providerLoaded['provider_secret'] ?? '') === 'server-only', 'provider defaults merge into loaded presets');
$providerClientDefinition = shortcode_source_provider_client_definition($providerDefinitions['provider_feed']);
$check(($providerClientDefinition['defaults']['limit'] ?? 0) === 7
    && ($providerClientDefinition['defaults']['provider_default'] ?? '') === 'yes'
    && ($providerClientDefinition['defaults']['editor_only'] ?? '') === 'editor-default'
    && !array_key_exists('provider_secret', $providerClientDefinition['defaults'])
    && $providerClientDefinition['field_keys'] === ['provider_default', 'editor_only'], 'client provider definitions expose only Core defaults and declared safe fields');
$providerClientConfig = shortcode_preset_config_for_client($providerLoaded, $providerDefinitions);
$check(($providerClientConfig['provider_default'] ?? '') === 'yes'
    && !array_key_exists('provider_secret', $providerClientConfig)
    && ($providerClientConfig['editor_only'] ?? '') === 'editor-default'
    && ($providerClientConfig['limit'] ?? 0) === 7, 'client preset config omits server-only provider defaults without deleting Core keys');
$transitionInput = [
    'source' => 'replacement_feed',
    'source_owner' => 'replacement-plugin',
    'provider_default' => 'stale-old-value',
    'provider_secret' => 'stale-secret',
    'category' => 'news',
    'limit' => 23,
];
$transitionPrevious = [
    'source' => 'provider_feed',
    'source_owner' => 'provider-plugin',
    'provider_default' => 'old-value',
    'provider_secret' => 'stored-secret',
];
$transitionNormalized = shortcode_preset_normalize_source_transition($transitionInput, $transitionPrevious);
$transitionConfig = shortcode_preset_apply_source_defaults($transitionNormalized);
$check(($transitionConfig['provider_default'] ?? '') === 'replacement'
    && !array_key_exists('provider_secret', $transitionConfig)
    && ($transitionConfig['replacement_secret'] ?? '') === 'new-server-only'
    && ($transitionConfig['category'] ?? '') === 'news'
    && ($transitionConfig['limit'] ?? 0) === 23, 'source transition removes old provider fields before target defaults while preserving Core config');
$sameSourceNormalized = shortcode_preset_normalize_source_transition(
    ['source' => 'provider_feed', 'source_owner' => 'provider-plugin', 'provider_secret' => 'tampered', 'provider_default' => 'changed'],
    ['source' => 'provider_feed', 'source_owner' => 'provider-plugin', 'provider_secret' => 'stored-secret', 'provider_default' => 'old']
);
$check(($sameSourceNormalized['provider_secret'] ?? '') === 'stored-secret'
    && ($sameSourceNormalized['provider_default'] ?? '') === 'changed', 'same-source normalization preserves server-only values and accepts declared client fields');
$reboundLoaded = shortcode_preset_config_loaded(['source' => 'provider_feed', 'source_owner' => 'replacement-plugin', 'layout' => 'list']);
$check(($reboundLoaded['provider_default'] ?? null) === null && ($reboundLoaded['limit'] ?? 0) === 5, 'provider defaults do not bind to a different persisted owner');
$providerValidation = shortcode_preset_validate_config($providerLoaded, true, null, ['scope' => 'contract']);
$check($providerValidation['errors'] === [] && ($providerValidation['config']['provider_validated'] ?? false), 'provider validation participates in preset validation');
$check(($providerValidation['config']['limit'] ?? 0) === 7, 'provider validation cannot replace sanitized Core fields');
$initialProviderValidation = shortcode_preset_validate_config(['source' => 'provider_feed', 'layout' => 'list'], true, null, ['scope' => 'initial_save', 'allow_provider_binding' => true]);
$check($initialProviderValidation['errors'] === [] && ($initialProviderValidation['config']['source_owner'] ?? '') === 'provider-plugin'
    && ($initialProviderValidation['config']['provider_default'] ?? '') === 'yes', 'initial save binds identity and merges provider defaults before validation');
$strictProviderValidation = shortcode_preset_validate_config(['source' => 'provider_feed', 'layout' => 'list'], true, null, ['scope' => 'runtime']);
$check(in_array('Source provider identity does not match the saved preset.', $strictProviderValidation['errors'], true), 'runtime validation rejects an unbound provider identity');
$runtimeCallOffset = count($providerCalls);
$providerHtml = post_cat_shortcode_render(new PresetContractPdo(), [
    'source' => 'provider_feed',
    'source_owner' => 'provider-plugin',
    'layout' => 'list',
    'limit' => 1,
]);
$check(str_contains($providerHtml, 'Provider title') && str_contains($providerHtml, 'href="#"'), 'provider runtime items render through layouts with unsafe URLs neutralized');
$runtimeCalls = array_slice($providerCalls, $runtimeCallOffset);
$check(($runtimeCalls[0][0] ?? '') === 'validate' && ($runtimeCalls[1][0] ?? '') === 'fetch', 'direct runtime validates provider config before fetch');
$check(($runtimeCalls[0][2] ?? null) === false && ($runtimeCalls[0][3] ?? '') === 'public_runtime', 'runtime provider validation receives non-admin public trust context');
$defaultRuntimeOffset = count($providerCalls);
post_cat_shortcode_render(new PresetContractPdo(), ['source' => 'provider_feed', 'source_owner' => 'provider-plugin', 'layout' => 'list']);
$defaultRuntimeCalls = array_slice($providerCalls, $defaultRuntimeOffset);
$check(($defaultRuntimeCalls[1][1] ?? 0) === 7, 'direct runtime merges and normalizes provider defaults before fetch');
$directShortcodeOffset = count($providerCalls);
$directShortcodeHtml = post_cat_shortcode_expand(
    '[post_cat_shortcode source="provider_feed" source_owner="provider-plugin" layout="list" provider_default="public-choice" editor_only="attacker-editor" provider_secret="attacker-secret" saved_private="attacker-saved" undeclared_provider_field="attacker-arbitrary"]',
    new PresetContractPdo()
);
$directShortcodeCalls = array_slice($providerCalls, $directShortcodeOffset);
$directShortcodeValidation = null;
$directShortcodeFetch = null;
foreach ($directShortcodeCalls as $call) {
    if (($call[0] ?? '') === 'validate') $directShortcodeValidation = $call;
    if (($call[0] ?? '') === 'fetch') $directShortcodeFetch = $call;
}
$check(str_contains($directShortcodeHtml, 'Provider title')
    && ($directShortcodeValidation[3] ?? '') === 'public_runtime'
    && ($directShortcodeValidation[4]['preset_id'] ?? null) === null
    && ($directShortcodeValidation[4]['provider_default'] ?? '') === 'public-choice'
    && ($directShortcodeValidation[4]['editor_only'] ?? '') === 'editor-default'
    && ($directShortcodeValidation[4]['provider_secret'] ?? '') === 'server-only'
    && ($directShortcodeValidation[4]['saved_private'] ?? null) === null
    && ($directShortcodeValidation[4]['undeclared_provider_field'] ?? null) === null
    && ($directShortcodeFetch[3]['provider_default'] ?? '') === 'validated-public-choice'
    && ($directShortcodeFetch[3]['editor_only'] ?? '') === 'editor-default'
    && ($directShortcodeFetch[3]['provider_secret'] ?? '') === 'server-only', 'direct post_cat shortcode applies provider defaults and validates only declared public provider attributes');
$staticWidgetOffset = count($providerCalls);
$staticWidgetHtml = render_widget('post_cat_shortcode', [
    'source' => 'provider_feed',
    'source_owner' => 'provider-plugin',
    'layout' => 'list',
    'provider_default' => 'public-choice',
    'editor_only' => 'attacker-editor',
    'provider_secret' => 'attacker-secret',
    'saved_private' => 'attacker-saved',
    'undeclared_provider_field' => 'attacker-arbitrary',
], new PresetContractPdo());
$staticWidgetCalls = array_slice($providerCalls, $staticWidgetOffset);
$staticWidgetValidation = null;
$staticWidgetFetch = null;
foreach ($staticWidgetCalls as $call) {
    if (($call[0] ?? '') === 'validate') $staticWidgetValidation = $call;
    if (($call[0] ?? '') === 'fetch') $staticWidgetFetch = $call;
}
$check(str_contains($staticWidgetHtml, 'Provider title')
    && ($staticWidgetValidation[3] ?? '') === 'public_runtime'
    && ($staticWidgetValidation[4]['widget_name'] ?? '') === 'post_cat_shortcode'
    && ($staticWidgetValidation[4]['provider_default'] ?? '') === 'public-choice'
    && ($staticWidgetValidation[4]['editor_only'] ?? '') === 'editor-default'
    && ($staticWidgetValidation[4]['provider_secret'] ?? '') === 'server-only'
    && ($staticWidgetValidation[4]['saved_private'] ?? null) === null
    && ($staticWidgetValidation[4]['undeclared_provider_field'] ?? null) === null
    && ($staticWidgetFetch[3]['provider_default'] ?? '') === 'validated-public-choice'
    && ($staticWidgetFetch[3]['editor_only'] ?? '') === 'editor-default'
    && ($staticWidgetFetch[3]['provider_secret'] ?? '') === 'server-only', 'static collection widget applies the same provider public override allowlist before validation and fetch');
$identityFetchCount = count(array_filter($providerCalls, static fn(array $call): bool => ($call[0] ?? '') === 'fetch'));
$identityHtml = post_cat_shortcode_render(new PresetContractPdo(), ['source' => 'provider_feed', 'source_owner' => 'replacement-plugin', 'layout' => 'list']);
$check(str_contains($identityHtml, 'Source provider is unavailable.')
    && count(array_filter($providerCalls, static fn(array $call): bool => ($call[0] ?? '') === 'fetch')) === $identityFetchCount, 'changed provider owners fail closed before fetch');
$untrustedAuthorFetchCount = count(array_filter($providerCalls, static fn(array $call): bool => ($call[0] ?? '') === 'fetch'));
$untrustedAuthorHtml = post_cat_shortcode_render(new PresetContractPdo(), [
    'source' => 'provider_feed',
    'source_owner' => 'provider-plugin',
    'layout' => 'list',
    'author' => 99,
]);
$check(str_contains($untrustedAuthorHtml, 'Source provider is unavailable.')
    && count(array_filter($providerCalls, static fn(array $call): bool => ($call[0] ?? '') === 'fetch')) === $untrustedAuthorFetchCount, 'untrusted direct runtime author overrides remain blocked before fetch');
$check(shortcode_provider_adoption_allowed(
    ['source' => 'provider_feed'],
    ['source' => 'provider_feed'],
    true,
    true
), 'administrator can explicitly adopt a registered provider for an ownerless preset');
$check(!shortcode_provider_adoption_allowed(['source' => 'provider_feed'], ['source' => 'provider_feed'], true, false)
    && !shortcode_provider_adoption_allowed(['source' => 'provider_feed'], ['source' => 'provider_feed'], false, true)
    && !shortcode_provider_adoption_allowed(['source' => 'provider_feed', 'source_owner' => 'old-plugin'], ['source' => 'provider_feed'], true, true)
    && !shortcode_provider_adoption_allowed(['source' => 'plugin_feed'], ['source' => 'plugin_feed'], true, true), 'provider adoption requires explicit administrator intent, an ownerless record, and a registered unchanged source');
$unavailableHtml = post_cat_shortcode_render(new PresetContractPdo(), ['source' => 'plugin_feed', 'layout' => 'list']);
$check(str_contains($unavailableHtml, 'Source provider is unavailable.'), 'legacy allowlisted source without a provider renders an unavailable state');
$check(register_shortcode_source_provider('broken_feed', [
    'owner' => 'broken-plugin',
    'label' => 'Broken feed',
    'fetch' => static fn(PDO $pdo, array $attrs, array $context): string => 'invalid',
]), 'malformed-result provider registers for failure testing');
$brokenHtml = post_cat_shortcode_render(new PresetContractPdo(), ['source' => 'broken_feed', 'source_owner' => 'broken-plugin', 'layout' => 'list']);
$check(str_contains($brokenHtml, 'Source provider is unavailable.'), 'malformed provider results fail closed');
$rejectedFetches = 0;
$check(register_shortcode_source_provider('rejected_feed', [
    'owner' => 'rejected-plugin',
    'label' => 'Rejected feed',
    'defaults' => ['provider_required' => 'defaulted'],
    'validate' => static function (array $config): array {
        return ['config' => $config, 'errors' => ['Provider rejected runtime config.']];
    },
    'fetch' => static function () use (&$rejectedFetches): array {
        $rejectedFetches++;
        return ['items' => []];
    },
]), 'rejecting provider registers for runtime validation testing');
$rejectedHtml = post_cat_shortcode_render(new PresetContractPdo(), ['source' => 'rejected_feed', 'source_owner' => 'rejected-plugin', 'layout' => 'list']);
$check(str_contains($rejectedHtml, 'Source provider is unavailable.') && $rejectedFetches === 0, 'provider validation errors fail closed before runtime fetch');

$validation = shortcode_preset_validate_config(array_merge($loaded, [
    'author' => 8,
    'date_from' => '2026-12-31',
    'date_to' => '2026-01-01',
]), false);
$check(count($validation['errors']) >= 2, 'server validation enforces non-admin author and date-range restrictions');
$check(($validation['config']['plugin_key']['kept'] ?? false) === true, 'validation preserves unknown plugin config keys');

$previewHookCalls = 0;
add_filter('shortcode_preset_preview_config', static function (array $config) use (&$previewHookCalls): array {
    $previewHookCalls++;
    return $config;
});
$invalidAuthorPreview = shortcode_preset_prepare_preview_config(['source' => 'provider_feed', 'source_owner' => 'provider-plugin', 'layout' => 'list'], false);
$check($invalidAuthorPreview['errors'] !== [] && $previewHookCalls === 0, 'invalid non-admin inline preview fails before preview source hooks');
$validAdminPreview = shortcode_preset_prepare_preview_config(['source' => 'provider_feed', 'source_owner' => 'provider-plugin', 'layout' => 'list'], true);
$check($validAdminPreview['errors'] === [] && $previewHookCalls === 1, 'admin plugin-source inline preview remains available after validation');
$check(post_cat__resolve_kicker([], '') === '', 'missing kicker with an empty category remains empty');
$check(post_cat__resolve_kicker([], 'world-news') === 'WORLD NEWS', 'missing kicker resolves from a nonempty category');
$check(post_cat__resolve_kicker(['kicker' => ''], 'world-news') === '', 'explicit empty kicker suppresses automatic category resolution');

$pdo = new PresetContractPdo();
register_widget_shortcode_handler('static_collision', static fn(): string => 'static');
$runtimeLocales = [];
add_filter('shortcode_preset_runtime_config', static function (array $config) use (&$runtimeLocales): array {
    $runtimeLocales[] = $GLOBALS['preset_contract_locale'] ?? 'unset';
    $config['runtime_locale'] = $GLOBALS['preset_contract_locale'] ?? 'unset';
    return $config;
});
load_preset_widgets($pdo);
$check(($GLOBALS['_widget_shortcode_handlers']['static_collision']['origin'] ?? '') === 'static', 'runtime presets do not replace statically registered widgets');
$check(($GLOBALS['_widget_shortcode_handlers']['runtime_preset']['origin'] ?? '') === 'preset', 'valid published presets register at runtime');
$check(!isset($GLOBALS['_widget_shortcode_handlers']['invalid.dot']), 'runtime rejects preset slugs outside parser grammar');
$check($runtimeLocales === [], 'runtime config filter is not captured during early preset registration');
$runtimeHandler = $GLOBALS['_widget_shortcode_handlers']['runtime_preset']['fn'];
$GLOBALS['preset_contract_locale'] = 'en';
$runtimeHandler($pdo, [], []);
$GLOBALS['preset_contract_locale'] = 'id';
$runtimeHandler($pdo, [], []);
$check($runtimeLocales === ['en', 'id'], 'runtime config filter evaluates at each render after locale routing');
$providerRuntimeHandler = $GLOBALS['_widget_shortcode_handlers']['provider_runtime_preset']['fn'];
$persistedRuntimeOffset = count($providerCalls);
$persistedRuntimeHtml = $providerRuntimeHandler($pdo, [
    'source' => 'rejected_feed',
    'source_owner' => 'rejected-plugin',
    'author' => 99,
    'created_by' => 100,
    'date_from' => '2030-01-01',
    'date_to' => '2030-12-31',
    'date_after' => '2031-01-01',
    'date_before' => '2031-12-31',
    'provider_default' => 'public-choice',
    'editor_only' => 'attacker-editor-value',
    'provider_secret' => 'attacker-secret',
    'saved_private' => 'attacker-saved-value',
    'undeclared_provider_field' => 'attacker-arbitrary-value',
], []);
$persistedRuntimeCalls = array_slice($providerCalls, $persistedRuntimeOffset);
$persistedFetch = null;
$persistedValidation = null;
foreach ($persistedRuntimeCalls as $call) {
    if (($call[0] ?? '') === 'fetch') $persistedFetch = $call;
    if (($call[0] ?? '') === 'validate') $persistedValidation = $call;
}
$check(str_contains($persistedRuntimeHtml, 'Provider title')
    && ($persistedFetch[3]['source'] ?? '') === 'provider_feed'
    && ($persistedFetch[3]['author'] ?? null) === 8
    && ($persistedFetch[3]['created_by'] ?? null) === null
    && ($persistedFetch[3]['date_from'] ?? '') === '2026-01-01'
    && ($persistedFetch[3]['date_to'] ?? '') === '2026-12-31'
    && ($persistedFetch[3]['date_after'] ?? null) === null
    && ($persistedFetch[3]['date_before'] ?? null) === null, 'trusted persisted public presets retain sanitized author and date filters while protected runtime overrides are ignored');
$check(($persistedValidation[1] ?? '') === 'validation'
    && ($persistedValidation[2] ?? null) === false
    && ($persistedValidation[3] ?? '') === 'persisted_preset'
    && ($persistedValidation[4]['preset_id'] ?? null) === 74
    && ($persistedValidation[4]['provider_default'] ?? '') === 'public-choice'
    && ($persistedValidation[4]['editor_only'] ?? '') === 'editor-default'
    && ($persistedValidation[4]['provider_secret'] ?? '') === 'server-only'
    && ($persistedValidation[4]['saved_private'] ?? '') === 'saved-value'
    && ($persistedValidation[4]['undeclared_provider_field'] ?? null) === null
    && ($persistedFetch[3]['provider_default'] ?? '') === 'validated-public-choice'
    && ($persistedFetch[3]['editor_only'] ?? '') === 'editor-default'
    && ($persistedFetch[3]['provider_secret'] ?? '') === 'server-only'
    && ($persistedFetch[3]['saved_private'] ?? '') === 'saved-value'
    && ($persistedFetch[3]['undeclared_provider_field'] ?? null) === null, 'persisted provider runtime accepts and validates only explicitly public client fields while ignoring arbitrary and server-only overrides');

$previewEventConfig = null;
add_filter('shortcode_preset_preview_config', static function (array $config, array $context): array {
    $config['preview_locale'] = $context['locale'] ?? '';
    return $config;
}, 10, 2);
add_action('shortcode_preset_preview_configured', static function (array $config) use (&$previewEventConfig): void {
    $previewEventConfig = $config;
});
$previewConfig = shortcode_preset_preview_config(['layout' => 'list'], ['locale' => 'de']);
$check(($previewConfig['preview_locale'] ?? '') === 'de' && $previewEventConfig === $previewConfig, 'preview config filter and event expose the final plugin-extended config');
add_filter('shortcode_preset_preview_result', static function (?array $result, array $config): ?array {
    return ($config['source'] ?? '') === 'plugin_feed'
        ? ['html' => '<p>plugin preview</p>', 'mode' => 'plugin_feed']
        : $result;
}, 10, 2);
$pluginPreview = shortcode_preset_preview_result(null, ['source' => 'plugin_feed'], ['mode' => 'inline']);
$check(($pluginPreview['html'] ?? '') === '<p>plugin preview</p>' && ($pluginPreview['mode'] ?? '') === 'plugin_feed', 'plugin-defined preset sources can return source-aware preview HTML without Core post queries');

$preDeleteSeen = false;
add_action('admin_shortcode_preset_before_delete', static function (int $presetId, PDO $transaction) use (&$preDeleteSeen): void {
    $preDeleteSeen = $presetId === 88 && $transaction->inTransaction();
    throw new RuntimeException('dependent cleanup failed');
});
$blocked = null;
try {
    shortcode_preset_before_delete(new PresetTransactionContractPdo(true), 88);
} catch (Throwable $error) {
    $blocked = $error;
}
$check($preDeleteSeen && $blocked instanceof ShortcodePresetDeletionBlockedException && $blocked->getPrevious() instanceof RuntimeException, 'pre-delete hook runs in the active transaction and propagates dependent cleanup failure');
$check($blocked instanceof ShortcodePresetDeletionBlockedException && !str_contains($blocked->getMessage(), 'dependent cleanup failed'), 'pre-delete listener internals are retained as the previous exception but redacted from the public message');
$requiresTransaction = false;
try {
    shortcode_preset_before_delete(new PresetTransactionContractPdo(false), 88);
} catch (LogicException $error) {
    $requiresTransaction = true;
}
$check($requiresTransaction, 'pre-delete contract rejects invocation outside a database transaction');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
