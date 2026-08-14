<?php
// Path: _core/apps/noticeboard.php
declare(strict_types=1);
return [
    'slug'        => 'noticeboard',
    'name'        => 'Noticeboard',
    'description' => 'Visual poster wall — Canva embeds, image/video/text posters, weekday recurrence, QR share.',
    'icon'        => 'fa-solid fa-thumbtack',
    'color'       => '#caa063',
    'category'    => 'communications',
    'industries'  => ['church', 'community', 'school', 'nonprofit', 'small-business'],
    'route'       => 'noticeboard',
    'settingKey'  => 'noticeboard.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
