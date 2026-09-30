<?php
declare(strict_types=1);

$homeSections = [
    'home.hero' => ['label' => 'Homepage Hero'],
    'home.guide-bento' => ['label' => 'Homepage Guide Bento'],
    'home.latest-carousel' => ['label' => 'Homepage Latest Carousel'],
    'home.preset-posts' => [
        'label' => 'Homepage Preset Post List',
        'defaults' => [
            'preset' => 'demo_home_posts',
            'title' => 'Built with a reusable Preset',
            'summary' => 'This query and Collection Layout come from a published Preset; the active theme supplies this reusable section wrapper.',
        ],
    ],
    'home.categories' => ['label' => 'Homepage Categories'],
    'home.random-posts' => [
        'label' => 'Homepage Random Card Grid',
        'defaults' => [
            'preset' => 'demo_random_posts',
            'title' => 'Discover random articles',
            'summary' => 'A fresh selection from across the site. Shuffle the mix or open a card to continue reading.',
            'refresh_label' => 'Shuffle posts',
        ],
    ],
    'home.cta' => ['label' => 'Homepage Call to Action'],
    'home.topic-columns' => ['label' => 'Homepage Topic Columns'],
    'home.empty-state' => ['label' => 'Homepage Empty State'],
];

foreach ($homeSections as $name => $definition) {
    $label = (string)$definition['label'];
    $definition['label'] = function_exists('__') ? __($label) : $label;
    if (is_array($definition['defaults'] ?? null)) {
        foreach (['title', 'summary', 'refresh_label'] as $key) {
            if (is_string($definition['defaults'][$key] ?? null) && function_exists('__')) {
                $definition['defaults'][$key] = __($definition['defaults'][$key]);
            }
        }
    }
    $definition['repeatable'] = false;
    register_theme_section($name, $definition);
}

unset($homeSections, $name, $definition, $label, $key);
