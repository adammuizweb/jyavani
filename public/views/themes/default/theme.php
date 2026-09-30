<?php
declare(strict_types=1);

$homeSections = [
    'home.hero' => 'Homepage Hero',
    'home.guide-bento' => 'Homepage Guide Bento',
    'home.latest-carousel' => 'Homepage Latest Carousel',
    'home.categories' => 'Homepage Categories',
    'home.cta' => 'Homepage Call to Action',
    'home.topic-columns' => 'Homepage Topic Columns',
    'home.empty-state' => 'Homepage Empty State',
];

foreach ($homeSections as $name => $label) {
    register_theme_section($name, [
        'label' => function_exists('__') ? __($label) : $label,
        'repeatable' => false,
    ]);
}

unset($homeSections, $name, $label);
