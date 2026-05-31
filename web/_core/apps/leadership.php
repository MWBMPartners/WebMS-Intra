<?php
// Path: _core/apps/leadership.php
declare(strict_types=1);
return [
    'slug'        => 'leadership',
    'name'        => 'Leadership',
    'description' => 'Roles, assignments, history, CSV export.',
    'icon'        => 'fa-solid fa-people-group',
    'color'       => '#a855f7',
    'category'    => 'community',
    'industries'  => ['church', 'community', 'nonprofit', 'membership-org'],
    'route'       => 'leadership',
    'settingKey'  => 'leadership.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
