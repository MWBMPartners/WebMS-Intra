<?php
// Path: _core/apps/projects.php
declare(strict_types=1);
return [
    'slug'        => 'projects',
    'name'        => 'Projects',
    'description' => 'Project fundraising pages with pledge thermometer, updates feed, and public sharing.',
    'icon'        => 'fa-solid fa-bullseye',
    'color'       => '#0ea5e9',
    'category'    => 'fundraising',
    'industries'  => ['church', 'nonprofit', 'school', 'community', 'mutual-aid'],
    'route'       => 'projects',
    'settingKey'  => 'projects.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
