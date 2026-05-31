<?php
// Path: _core/apps/praise.php
declare(strict_types=1);
return [
    'slug'        => 'praise',
    'name'        => 'Praise Reports',
    'description' => 'Share gratitude, answered prayers, celebrations, wins. Counterpart to prayer requests.',
    'icon'        => 'fa-solid fa-hands-clapping',
    'color'       => '#22c55e',
    'category'    => 'community',
    'industries'  => ['church', 'community', 'school'],
    'route'       => 'praise',
    'settingKey'  => 'praise.enabled',
    'requires'    => ['prayer-requests'],
    'isCore'      => false,
    'version'     => '1.0.0',
];
