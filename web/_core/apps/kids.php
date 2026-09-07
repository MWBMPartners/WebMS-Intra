<?php
// Path: _core/apps/kids.php
declare(strict_types=1);
return [
    'slug'        => 'kids',
    'name'        => "Children's Ministry",
    'description' => "Children's ministry check-in / check-out with 6-digit safeguarding badge codes.",
    'icon'        => 'fa-solid fa-child-reaching',
    'color'       => '#f59e0b',
    'category'    => 'community',
    'industries'  => ['church', 'community'],
    'route'       => 'kids',
    // 🎯 Where a menu entry or dashboard card should link to.
    //    'route' above is a PREFIX used to work out which app owns a
    //    page (for the on/off switch), NOT an address you can visit:
    //    there is no /kids front page — check-in is where the team starts.
    'landing'     => 'kids/checkin',
    'settingKey'  => 'kids.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
