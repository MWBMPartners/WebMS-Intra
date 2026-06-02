<?php
// Path: _core/apps/newsletter.php
declare(strict_types=1);
return [
    'slug'        => 'newsletter',
    'name'        => 'Newsletter',
    'description' => 'Compose, schedule, and send branded HTML newsletters. Internal sender now; MailerMatt adapter slot reserved.',
    'icon'        => 'fa-solid fa-envelope-open-text',
    'color'       => '#16a34a',
    'category'    => 'communications',
    'industries'  => ['church', 'nonprofit', 'school', 'membership-org', 'small-business'],
    'route'       => 'newsletter',
    'settingKey'  => 'newsletter.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
