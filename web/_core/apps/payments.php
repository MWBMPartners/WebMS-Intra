<?php
// Path: _core/apps/payments.php
declare(strict_types=1);
return [
    'slug'        => 'payments',
    'name'        => 'Payments',
    'description' => 'Pluggable payment processor (Stripe today; PayPal + GoCardless adapters reserved) used by Giving + Projects.',
    'icon'        => 'fa-solid fa-credit-card',
    'color'       => '#7c3aed',
    'category'    => 'finance',
    'industries'  => ['church', 'nonprofit', 'events', 'school', 'small-business', 'membership-org'],
    'route'       => 'payments',
    'settingKey'  => 'payments.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
