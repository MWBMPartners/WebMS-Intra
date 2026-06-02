<?php
// Path: _core/apps/livestream.php
declare(strict_types=1);
return [
    'slug'        => 'livestream',
    'name'        => 'Livestream',
    'description' => 'Embed YouTube / Vimeo / Twitch / Facebook livestreams on the dashboard during scheduled times.',
    'icon'        => 'fa-solid fa-tower-broadcast',
    'color'       => '#ef4444',
    'category'    => 'communications',
    'industries'  => ['church', 'school', 'events', 'broadcasting'],
    'route'       => 'live',
    'settingKey'  => 'livestream.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
