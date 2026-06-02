<?php
// Path: _core/apps/giving.php
declare(strict_types=1);
return [
    'slug'        => 'giving',
    'name'        => 'Giving',
    'description' => 'Contributions log with Gift Aid declaration capture, HMRC export, and year-end statements.',
    'icon'        => 'fa-solid fa-hand-holding-dollar',
    'color'       => '#059669',
    'category'    => 'finance',
    'industries'  => ['church', 'nonprofit', 'membership-org', 'school'],
    'route'       => 'giving',
    'settingKey'  => 'giving.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
