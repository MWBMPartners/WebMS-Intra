<?php
// Path: _core/apps/worship.php
declare(strict_types=1);
return [
    'slug'        => 'worship',
    'name'        => 'Worship',
    'description' => 'Live presentation layer for Service Plans — operator console, public projector display, song library + CCLI usage log.',
    'icon'        => 'fa-solid fa-music',
    'color'       => '#7c3aed',
    'category'    => 'operations',
    'industries'  => ['church', 'events', 'broadcasting'],
    'route'       => 'worship',
    'settingKey'  => 'worship.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
