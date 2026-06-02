<?php
// Path: _core/apps/sms.php
declare(strict_types=1);
return [
    'slug'        => 'sms',
    'name'        => 'SMS',
    'description' => 'SMS notifications for critical alerts via Twilio / MessageBird / AWS SNS.',
    'icon'        => 'fa-solid fa-comment-sms',
    'color'       => '#dc2626',
    'category'    => 'communications',
    'industries'  => ['church', 'nonprofit', 'school', 'small-business', 'volunteer-org'],
    'route'       => 'admin/sms',
    'settingKey'  => 'sms.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
