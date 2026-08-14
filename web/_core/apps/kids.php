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
    'settingKey'  => 'kids.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
