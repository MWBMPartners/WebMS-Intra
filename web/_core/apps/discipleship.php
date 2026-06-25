<?php
// Path: _core/apps/discipleship.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Discipleship Pathway Tracker AppRegistry config 📖 (#303 Phase 1)
 * -----------------------------------------------------------------------------
 * Phase 1 ships schema + admin CRUD only. The app stays hidden from the
 * marketplace UI / dashboard / router until an admin sets
 * `discipleship.enabled` = '1' or 'true' in /admin/settings.
 *
 * Industries: church, community, nonprofit, membership-org — pastoral /
 * member-formation flavour. School / small-business installs won't see it.
 *
 * Phase 2 (deferred) will add: per-user progress (tblPathwayProgress),
 * mentor relationships, auto-completion rules, member-facing routes, and
 * a pastor dashboard. The settingKey wires straight through; once that
 * code ships, flipping the toggle on a Phase-1 install lights it all up.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/303
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return [
    'slug'        => 'discipleship',
    'name'        => 'Discipleship Pathways',
    'description' => 'Define ordered formation pathways (e.g. New believer, Leadership track) for pastoral follow-up.',
    'icon'        => 'fa-solid fa-route',
    'color'       => '#a855f7',
    'category'    => 'pastoral',
    'industries'  => ['church', 'community', 'nonprofit', 'membership-org'],
    'route'       => 'admin/discipleship',
    'settingKey'  => 'discipleship.enabled',
    'requires'    => [],
    'isCore'      => false,
    'version'     => '1.0.0',
];
