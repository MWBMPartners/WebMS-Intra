<?php
// Path: _core/apps/expenses.php
declare(strict_types=1);
return [
    'slug'        => 'expenses',
    'name'        => 'Expenses',
    'description' => 'Submit, approve, treasury, withdraw, multi-approver, PDF + CSV.',
    'icon'        => 'fa-solid fa-receipt',
    'color'       => '#ef4444',
    'category'    => 'finance',
    'industries'  => ['church', 'community', 'nonprofit', 'small-business', 'school'],
    'route'       => 'expenses',
    'settingKey'  => 'expenses.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
