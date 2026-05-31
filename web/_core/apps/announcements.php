<?php
// Path: _core/apps/announcements.php
declare(strict_types=1);
return [
    'slug'        => 'announcements',
    'name'        => 'Announcements',
    'description' => 'Site noticeboard with pinned + scheduled posts.',
    'icon'        => 'fa-solid fa-bullhorn',
    'color'       => '#f59e0b',
    'category'    => 'communications',
    'industries'  => ['church', 'community', 'school', 'nonprofit', 'small-business', 'membership-org'],
    'route'       => 'announcements',
    'settingKey'  => 'announcements.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
