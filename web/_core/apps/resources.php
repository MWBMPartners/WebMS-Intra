<?php
// Path: _core/apps/resources.php
declare(strict_types=1);
return [
    'slug'        => 'resources',
    'name'        => 'Resource Booking',
    'description' => 'Bookable resources (rooms, equipment, vehicles) with conflict detection + approval workflow.',
    'icon'        => 'fa-solid fa-building',
    'color'       => '#7c3aed',
    'category'    => 'operations',
    'industries'  => ['church', 'community', 'school', 'coworking', 'small-business', 'nonprofit'],
    'route'       => 'resources',
    'settingKey'  => 'resources.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
