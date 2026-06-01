<?php
// Path: _core/apps/directory.php
declare(strict_types=1);
return [
    'slug'        => 'directory',
    'name'        => 'Member Directory',
    'description' => 'Searchable directory of members with opt-in per-field visibility.',
    'icon'        => 'fa-solid fa-address-book',
    'color'       => '#0ea5e9',
    'category'    => 'community',
    'industries'  => ['church', 'community', 'small-business', 'nonprofit', 'membership-org', 'school'],
    'route'       => 'directory',
    'settingKey'  => 'directory.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
