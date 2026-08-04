<?php
// Path: _core/apps/discipleship.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Discipleship Pathway Tracker AppRegistry config 📖 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * Phase 1 shipped schema + admin CRUD only, hidden from the dashboard/router
 * until an admin sets `discipleship.enabled` = '1' or 'true' in
 * /admin/settings. Phase 2 adds a real member landing page at `/discipleship`
 * ("my pathways" + progress bars), a member pathway/step view, per-pathway
 * admin/pastor rosters, enrol/withdraw, manual mark/unmark, and a lazy
 * auto-completion sweep from per-user attendance/RSVP evidence.
 *
 * Industries: church, community, nonprofit, membership-org — pastoral /
 * member-formation flavour. School / small-business installs won't see it.
 *
 * Mentor relationships remain deferred to a later phase — no
 * `tblPathwayMentor` schema, no UI, yet.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/303
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return [
    'slug'        => 'discipleship',
    'name'        => 'Discipleship Pathways',
    'description' => 'Ordered formation pathways (e.g. New believer, Leadership track) with per-member progress tracking, auto-completion from attendance/RSVPs, and a pastor roster — for pastoral follow-up.',
    'icon'        => 'fa-solid fa-route',
    'color'       => '#a855f7',
    'category'    => 'pastoral',
    'industries'  => ['church', 'community', 'nonprofit', 'membership-org'],
    'route'       => 'discipleship',
    'settingKey'  => 'discipleship.enabled',
    'requires'    => [],
    'isCore'      => false,
    'version'     => '1.1.0',
];
