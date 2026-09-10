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
    // 🎯 Where a menu entry or dashboard card should link to.
    //    'route' above is a PREFIX used to work out which app owns a
    //    page (for the on/off switch), NOT an address you can visit:
    //    the app's own prefix `expenses` is not a page — a claimant starts at Submit.
    'landing'     => 'expenses/submit',
    'settingKey'  => 'expenses.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
