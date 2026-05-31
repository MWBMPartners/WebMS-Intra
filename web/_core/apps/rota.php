<?php
// Path: _core/apps/rota.php
declare(strict_types=1);
return [
    'slug'        => 'rota',
    'name'        => 'Duty Roster',
    'description' => 'Recurring duty / shift assignments with swap requests and reminders.',
    'icon'        => 'fa-solid fa-calendar-week',
    'color'       => '#0891b2',
    'category'    => 'community',
    'industries'  => ['church', 'community', 'small-business', 'nonprofit', 'school', 'membership-org'],
    'route'       => 'rota',
    'settingKey'  => 'rota.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
