<?php
// Path: _core/apps/reading-plans.php
declare(strict_types=1);
return [
    'slug'        => 'reading-plans',
    'name'        => 'Reading Plans',
    'description' => 'Daily reading plans with streak tracking and per-day check-off.',
    'icon'        => 'fa-solid fa-book-open',
    'color'       => '#a855f7',
    'category'    => 'content',
    'industries'  => ['church', 'book-club', 'training', 'school'],
    'route'       => 'reading-plans',
    'settingKey'  => 'reading_plans.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
