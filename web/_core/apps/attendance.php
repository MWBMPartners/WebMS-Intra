<?php
// Path: _core/apps/attendance.php
declare(strict_types=1);
return [
    'slug'        => 'attendance',
    'name'        => 'Attendance',
    'description' => 'Session attendance tracking with headcount by service type + CSV.',
    'icon'        => 'fa-solid fa-user-check',
    'color'       => '#0ea5e9',
    'category'    => 'community',
    'industries'  => ['church', 'school', 'community', 'membership-org'],
    'route'       => 'attendance',
    'settingKey'  => 'attendance.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
