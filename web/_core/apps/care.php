<?php
// Path: _core/apps/care.php
declare(strict_types=1);
return [
    'slug'        => 'care',
    'name'        => 'Care Register',
    'description' => 'Confidential pastoral / wellbeing register with visit log. Role-restricted, encrypted notes.',
    'icon'        => 'fa-solid fa-hand-holding-heart',
    'color'       => '#dc2626',
    'category'    => 'community',
    'industries'  => ['church', 'community', 'nonprofit', 'hr', 'mutual-aid'],
    'route'       => 'care',
    'settingKey'  => 'care.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
