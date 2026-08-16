<?php
// Path: _core/apps/assets.php
declare(strict_types=1);
return [
    'slug'        => 'assets',
    'name'        => 'Asset Tracker',
    'description' => 'Track physical and digital assets — ownership, loans, maintenance, licences, and a public lost-and-found page.',
    'icon'        => 'fa-solid fa-boxes-stacked',
    'color'       => '#0d9488',
    'category'    => 'operations',
    'industries'  => ['church', 'community', 'school', 'nonprofit', 'small-business', 'coworking'],
    'route'       => 'assets',
    'settingKey'  => 'assets.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
