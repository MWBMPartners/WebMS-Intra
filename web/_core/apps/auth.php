<?php
// Path: _core/apps/auth.php
declare(strict_types=1);
return [
    'slug'        => 'auth',
    'name'        => 'Authentication',
    'description' => 'Sign-in, account management, 2FA, passkeys, password reset.',
    'icon'        => 'fa-solid fa-lock',
    'color'       => '#5e6ad2',
    'category'    => 'core',
    'industries'  => [],
    'route'       => 'auth',
    'settingKey'  => 'auth.enabled',
    'isCore'      => true,
    'version'     => '1.0.0',
];
