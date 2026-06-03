<?php
// Path: _core/apps/photos.php
declare(strict_types=1);
return [
    'slug'        => 'photos',
    'name'        => 'Photos',
    'description' => 'Photo gallery with moderation queue, tiered role-based visibility, and EXIF-aware serving (raw file kept; GPS stripped from public downloads).',
    'icon'        => 'fa-solid fa-images',
    'color'       => '#db2777',
    'category'    => 'media',
    'industries'  => ['church', 'school', 'nonprofit', 'community', 'events'],
    'route'       => 'photos',
    'settingKey'  => 'photos.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
