<?php
// Path: _core/apps/milestones.php
declare(strict_types=1);
return [
    'slug'        => 'milestones',
    'name'        => 'Milestones',
    'description' => 'Birthdays, anniversaries, joining dates with daily digest for designated roles.',
    'icon'        => 'fa-solid fa-cake-candles',
    'color'       => '#ec4899',
    'category'    => 'community',
    'industries'  => ['church', 'hr', 'community', 'nonprofit', 'school', 'membership-org'],
    'route'       => 'milestones',
    'settingKey'  => 'milestones.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
