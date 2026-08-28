<?php
// Path: _core/apps/small-groups.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Small Groups AppRegistry config 👥 (#150)
 * -----------------------------------------------------------------------------
 * Groups/classes register — Sabbath School classes, home groups, Bible
 * studies. Roster with leader/co-leader/member roles, optional self-service
 * join requests, per-meeting roll with an additive headcount push into the
 * existing Attendance app via a group's linked tblAttendanceServiceTypes
 * row. Meeting location reuses the #456 shared location partials (a group
 * often meets in a member's home, so a members-only visibility default
 * applies — see `locationVisibility` on `tblSmallGroups`).
 *
 * v1 covers adult portal users only — no minor/child membership rows (the
 * Kids app owns child identity; see migration 183 header + DEV_NOTES.md).
 *
 * `settingKey` MUST match the key seeded by migration 183 exactly
 * (`AppRegistry::isEnabled()` walks the dot path through
 * `$SETTINGS['small-groups']['enabled']` and accepts '1' or 'true').
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/150
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return [
    'slug'        => 'small-groups',
    'name'        => 'Small Groups',
    'description' => 'Groups & classes register — leaders, member assignment, join requests, meeting rolls tied to attendance service types.',
    'icon'        => 'fa-solid fa-people-group',
    'color'       => '#0f766e',
    'category'    => 'community',
    'industries'  => ['church', 'school', 'community', 'membership-org', 'nonprofit'],
    'route'       => 'small-groups',
    'settingKey'  => 'small-groups.enabled',
    'requires'    => [],
    'isCore'      => false,
    'version'     => '1.0.0',
];
