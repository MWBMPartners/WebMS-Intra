<?php
// Path: _core/apps/venues.php
declare(strict_types=1);
return [
    'slug'        => 'venues',
    'name'        => 'Venue Bookings',
    'description' => 'Record and track the hire of external buildings — schedule, agreements, invoices, payments, and calendar conflict warnings.',
    'icon'        => 'fa-solid fa-building-columns',
    'color'       => '#b45309',
    'category'    => 'operations',
    'industries'  => ['church', 'community', 'school', 'nonprofit', 'small-business', 'coworking'],
    'route'       => 'venues',
    'settingKey'  => 'venues.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
