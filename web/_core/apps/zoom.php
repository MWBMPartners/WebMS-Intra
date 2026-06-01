<?php
// Path: _core/apps/zoom.php
declare(strict_types=1);
return [
    'slug'        => 'zoom',
    'name'        => 'Zoom',
    'description' => 'OAuth Zoom integration: create meetings from calendar events, auto-link recordings via webhook.',
    'icon'        => 'fa-solid fa-video',
    'color'       => '#2d8cff',
    'category'    => 'integrations',
    'industries'  => ['church', 'school', 'nonprofit', 'small-business', 'events'],
    'route'       => 'admin/integrations/zoom',
    'settingKey'  => 'zoom.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
