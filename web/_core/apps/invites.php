<?php
// Path: _core/apps/invites.php
declare(strict_types=1);
return [
    'slug'        => 'invites',
    'name'        => 'Invite Onboarding',
    'description' => 'Generate single-use invite links so new members self-register with role pre-assigned.',
    'icon'        => 'fa-solid fa-envelope-open-text',
    'color'       => '#0ea5e9',
    'category'    => 'admin',
    'industries'  => [],
    'route'       => 'invites',
    'settingKey'  => 'invites.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
