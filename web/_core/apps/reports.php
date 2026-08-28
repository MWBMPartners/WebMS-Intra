<?php
// Path: _core/apps/reports.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Reports AppRegistry config 📊 (#93 dashboards + #156 builder)
 * -----------------------------------------------------------------------------
 * Registers the WHOLE `/admin/reports` area — the fixed analytics
 * dashboards shipped by #93 AND the whitelist-driven custom report builder
 * (#156) at `/admin/reports/builder/*` — under one toggleable flag.
 * Seeded ON (migration 184) so nothing changes for an existing install
 * that upgrades: both halves keep working exactly as before, they just
 * become independently disable-able from `/admin/apps` like every other
 * app (A7 in the #156 build spec).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/156
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return [
    'slug'        => 'reports',
    'name'        => 'Reports',
    'description' => 'Analytics dashboards plus a whitelist-driven custom report builder with CSV export.',
    'icon'        => 'fa-solid fa-chart-bar',
    'color'       => '#0ea5e9',
    'category'    => 'admin',
    'industries'  => [],
    'route'       => 'admin/reports',
    'settingKey'  => 'reports.enabled',
    'requires'    => [],
    'isCore'      => false,
    'version'     => '1.0.0',
];
