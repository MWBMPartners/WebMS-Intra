<?php
// Path: _core/apps/calendar.php
declare(strict_types=1);
return [
    'slug'        => 'calendar',
    'name'        => 'Calendar',
    'description' => 'Events, series, RSVP, recurring schedule.',
    'icon'        => 'fa-solid fa-calendar-days',
    'color'       => '#10b981',
    'category'    => 'community',
    'industries'  => ['church', 'community', 'school', 'nonprofit', 'small-business'],
    'route'       => 'calendar',
    'settingKey'  => 'calendar.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
