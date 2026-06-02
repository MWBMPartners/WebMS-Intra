<?php
// Path: _core/apps/recordings.php
declare(strict_types=1);
return [
    'slug'        => 'recordings',
    'name'        => 'Recordings',
    'description' => 'Searchable audio/video library with podcast RSS feed and HTML5 playback.',
    'icon'        => 'fa-solid fa-microphone-lines',
    'color'       => '#7c3aed',
    'category'    => 'communications',
    'industries'  => ['church', 'training', 'events', 'school', 'media'],
    'route'       => 'recordings',
    'settingKey'  => 'recordings.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
