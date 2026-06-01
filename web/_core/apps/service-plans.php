<?php
// Path: _core/apps/service-plans.php
declare(strict_types=1);
return [
    'slug'        => 'service-plans',
    'name'        => 'Service Plans',
    'description' => 'Programme run-sheet with sections (preacher, scripture, hymns, AV, welcome team).',
    'icon'        => 'fa-solid fa-list-ol',
    'color'       => '#0891b2',
    'category'    => 'operations',
    'industries'  => ['church', 'events', 'school', 'broadcasting'],
    'route'       => 'service-plans',
    'settingKey'  => 'service_plans.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
