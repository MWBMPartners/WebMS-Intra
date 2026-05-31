<?php
// Path: _core/apps/prayer-requests.php
declare(strict_types=1);
return [
    'slug'        => 'prayer-requests',
    'name'        => 'Prayer Requests',
    'description' => 'Logged-in and anonymous public prayer-request submission with moderation.',
    'icon'        => 'fa-solid fa-hands-praying',
    'color'       => '#8b5cf6',
    'category'    => 'community',
    'industries'  => ['church'],
    'route'       => 'prayer-requests',
    'settingKey'  => 'prayerRequests.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
