<?php
declare(strict_types=1);

function dashboard_layout_item_key(mixed $item): ?string
{
    if (!is_string($item)
        || preg_match('/\A([A-Za-z0-9][A-Za-z0-9._-]{0,99})(?::[lr])?\z/D', $item, $matches) !== 1) {
        return null;
    }
    return $matches[1];
}

/** @return list<string> */
function dashboard_layout_validate(mixed $layout): array
{
    if (!is_array($layout) || !array_is_list($layout)) {
        throw new InvalidArgumentException('Invalid layout data');
    }
    if (count($layout) > 100) {
        throw new InvalidArgumentException('Too many dashboard widgets');
    }

    $widgetKeys = [];
    foreach ($layout as $index => $item) {
        $widgetKey = dashboard_layout_item_key($item);
        if ($widgetKey === null) {
            throw new InvalidArgumentException('Invalid widget at item ' . $index);
        }
        if (isset($widgetKeys[$widgetKey])) {
            throw new InvalidArgumentException('Duplicate dashboard widget');
        }
        $widgetKeys[$widgetKey] = true;
    }

    return $layout;
}

/** @param list<string> $submitted @param array<string,mixed> $availableWidgets @return list<string> */
function dashboard_layout_restrict_submitted(array $submitted, array $availableWidgets): array
{
    return array_values(array_filter($submitted, static function (string $item) use ($availableWidgets): bool {
        $key = dashboard_layout_item_key($item);
        if ($key === null || !array_key_exists($key, $availableWidgets)) return false;
        $definition = $availableWidgets[$key];
        return is_array($definition) && empty($definition['protected']);
    }));
}

/** @param array<mixed> $layout @return array<mixed> */
function dashboard_layout_normalize_legacy(array $layout): array
{
    if ($layout === [] || !is_array($layout[0] ?? null) || !array_key_exists('w', $layout[0])) return $layout;
    $columns = [1 => 'l', 2 => 'r'];
    return array_map(
        static fn($item): string => (is_array($item) && is_string($item['w'] ?? null) ? $item['w'] : '?')
            . ':' . ($columns[(int)($item['col'] ?? 1)] ?? 'l'),
        $layout
    );
}

/**
 * @param array<mixed> $existing
 * @param array<string,mixed> $availableWidgets
 * @param array<string,array{item:string,index:int,previous:string,next:string}> $definitions
 * @return array<string,array{item:string,index:int,previous:string,next:string}>
 */
function dashboard_layout_preservation_definitions(
    array $existing,
    array $availableWidgets,
    array $definitions = []
): array {
    foreach ($existing as $existingIndex => $existingItem) {
        $existingKey = dashboard_layout_item_key($existingItem);
        if ($existingKey === null) continue;
        $isAvailable = array_key_exists($existingKey, $availableWidgets);
        $availableDefinition = $isAvailable ? $availableWidgets[$existingKey] : null;
        if ($isAvailable && (!is_array($availableDefinition) || empty($availableDefinition['protected']))) continue;
        if (!isset($definitions[$existingKey])) {
            $definitions[$existingKey] = [
                'item' => $existingItem,
                'index' => (int)$existingIndex,
                'previous' => '',
                'next' => '',
            ];
        }
    }
    return $definitions;
}

/**
 * Keep dashboard widgets outside the actor's authority in their prior layout.
 *
 * @param list<string> $submitted
 * @param array<mixed> $existing
 * @param array<string,array{item:string,index:int,previous:string,next:string}> $definitions
 * @return list<string>
 */
function dashboard_layout_preserve_unavailable(
    array $submitted,
    array $existing,
    array $definitions,
    bool $hasCustomizedLayout
): array {
    $submitted = array_values(array_filter(
        $submitted,
        static fn(string $item): bool => !isset($definitions[explode(':', $item, 2)[0]])
    ));
    $submittedPositions = [];
    foreach ($submitted as $submittedIndex => $submittedItem) {
        $submittedPositions[explode(':', $submittedItem, 2)[0]] = $submittedIndex;
    }

    $existingKeys = [];
    foreach ($existing as $existingIndex => $existingItem) {
        $existingKey = dashboard_layout_item_key($existingItem);
        if ($existingKey === null) continue;
        $existingKeys[(int)$existingIndex] = $existingKey;
    }

    $insertions = [];
    foreach ($definitions as $key => $defaultDefinition) {
        $preservedItem = null;
        $preservedIndex = null;
        foreach ($existing as $existingIndex => $existingItem) {
            if (dashboard_layout_item_key($existingItem) !== $key) continue;
            $preservedItem = $existingItem;
            $preservedIndex = (int)$existingIndex;
            break;
        }

        if ($preservedItem !== null) {
            $previousKey = null;
            $nextKey = null;
            foreach (array_reverse($existingKeys, true) as $existingIndex => $existingKey) {
                if ($existingIndex >= $preservedIndex || !isset($submittedPositions[$existingKey])) continue;
                $previousKey = $existingKey;
                break;
            }
            foreach ($existingKeys as $existingIndex => $existingKey) {
                if ($existingIndex <= $preservedIndex || !isset($submittedPositions[$existingKey])) continue;
                $nextKey = $existingKey;
                break;
            }
        } elseif (!$hasCustomizedLayout) {
            $preservedItem = $defaultDefinition['item'];
            $preservedIndex = $defaultDefinition['index'];
            $previousKey = $defaultDefinition['previous'];
            $nextKey = $defaultDefinition['next'];
        }
        if ($preservedItem === null || $preservedIndex === null) continue;

        $targetIndex = $nextKey !== null && isset($submittedPositions[$nextKey])
            ? $submittedPositions[$nextKey]
            : ($previousKey !== null && isset($submittedPositions[$previousKey])
                ? $submittedPositions[$previousKey] + 1
                : min($preservedIndex, count($submitted)));
        $insertions[] = [
            'item' => $preservedItem,
            'index' => $targetIndex,
            'order' => $preservedIndex,
        ];
    }

    usort($insertions, static fn(array $a, array $b): int => [$a['index'], $a['order']] <=> [$b['index'], $b['order']]);
    $inserted = 0;
    foreach ($insertions as $insertion) {
        $targetIndex = min(max(0, (int)$insertion['index'] + $inserted), count($submitted));
        array_splice($submitted, $targetIndex, 0, [$insertion['item']]);
        $inserted++;
    }

    return $submitted;
}
