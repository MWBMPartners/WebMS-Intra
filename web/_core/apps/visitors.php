<?php
// Path: _core/apps/visitors.php
declare(strict_types=1);
return [
    'slug'        => 'visitors',
    'name'        => 'Visitor Tracking',
    'description' => 'First-time visitor capture with follow-up cadence + kanban workflow.',
    'icon'        => 'fa-solid fa-user-plus',
    'color'       => '#06b6d4',
    'category'    => 'community',
    'industries'  => ['church', 'community', 'nonprofit', 'membership-org'],
    'route'       => 'visitors',
    'settingKey'  => 'visitors.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
