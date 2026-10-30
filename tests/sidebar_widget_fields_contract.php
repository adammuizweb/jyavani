<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/cfg/helpers/hooks.php';
require_once dirname(__DIR__) . '/cfg/helpers/resource_lifecycle.php';
require_once dirname(__DIR__) . '/cfg/helpers/sidebar_helper.php';

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$condition) $failures++;
};

$definition = [
    'default_config' => ['period' => '30', 'limit' => 5, 'private' => 'server-owned'],
    'config_fields' => [
        'period' => ['label' => 'Period', 'control' => 'select', 'options' => ['1' => 'Today', '30' => '30 days', '365' => '365 days']],
        'limit' => ['label' => 'Item limit', 'control' => 'number', 'min' => 1, 'max' => 20],
        '../unsafe' => ['label' => 'Unsafe', 'control' => 'number', 'min' => 1, 'max' => 2],
        'html' => ['label' => 'HTML', 'control' => 'textarea'],
    ],
];

$fields = sidebar_widget_config_fields($definition);
$check(array_keys($fields) === ['period', 'limit']
    && $fields['period']['options'] === [1 => 'Today', 30 => '30 days', 365 => '365 days'],
    'external Sidebar field definitions are bounded to validated select and number controls');
$check(sidebar_widget_normalize_config($definition, ['period' => '1', 'limit' => '99', 'private' => 'forged']) === [
    'period' => '1', 'limit' => 20,
], 'submitted external config accepts declared values, clamps numbers, and drops undeclared input');
$check(sidebar_widget_normalize_config($definition, ['period' => 'forged', 'limit' => 'bad'], ['period' => '365', 'limit' => 7]) === [
    'period' => '365', 'limit' => 7,
], 'invalid external config falls back to the existing persisted values');
$check(sidebar_widget_normalize_config($definition, []) === ['period' => '30', 'limit' => 5],
    'new external widgets normalize from their registered defaults');

$dashboard = (string)file_get_contents(dirname(__DIR__) . '/dashboard/admin/sidebar/index.php');
$check(str_contains($dashboard, 'sidebar_widget_config_fields($widgetDefinition)')
    && str_contains($dashboard, 'sidebar_widget_normalize_config($widget_types[$type], $config, $existingConfig)')
    && str_contains($dashboard, '$extensionConfigFields !== []')
    && str_contains($dashboard, 'name="widget[<?= $itemId ?>][config][<?= h($fieldKey) ?>]"'),
    'Sidebar Settings delegates rendering and save normalization to the generic extension field contract');

echo $failures === 0 ? "RESULT: ALL PASS\n" : "RESULT: {$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
