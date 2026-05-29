<?php
// Path: _install/upgrade-policy.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Upgrade Policy 🛡️
 * -----------------------------------------------------------------------------
 * Static, version-controlled policy that governs whether an existing
 * installation can be upgraded in place or must be torn down and
 * reinstalled. Read by:
 *
 *   • _install/db_state.php — to decide whether to offer the
 *     "continue with existing data" option when a DB is detected.
 *   • _install/index.php step 2.5 — to render the right choice page.
 *   • _install/upgrade.php / Migrator — to gate migration runs that
 *     can't be applied to old-enough installations.
 *
 * Each entry in `breaking_changes` documents WHY the threshold exists so
 * future maintainers can verify the constraint is still real.
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return [
    // 📌 Installations whose recorded portal.installed_version is BELOW
    //    this threshold cannot be upgraded in place — the schema rewrite
    //    cost (or missing data, or some other irrecoverable shape change)
    //    makes a migration path impractical. The installer's step-2.5
    //    choice page disables the "continue" option for these.
    //
    //    Set to '0.0.0' until we actually ship a breaking change. Bumping
    //    this is a deliberate release-engineering action — not a routine
    //    code change.
    'fresh_required_below' => '0.0.0',

    // 📋 Breaking changes — chronological list of versions where the
    //    schema (or some other on-disk invariant) changed incompatibly.
    //    Each entry should be self-explanatory enough that a future
    //    maintainer can decide whether the threshold should be raised
    //    above their migration target.
    'breaking_changes' => [
        // Example shape — uncomment / populate when a real breaking
        // change ships:
        //
        // '0.99.0' => 'tblUsers PK rewrite — no automated migration; '
        //             . 'see migrations/060_users_pk_rewrite.sql header '
        //             . 'for the data-rescue mapping required.',
    ],

    // 🛡️ Always require an admin to type the portal hostname to confirm
    //    a destructive drop-and-rebuild action. Set to false to skip
    //    the confirmation (NOT recommended for production).
    'require_hostname_confirmation_for_drop' => true,

    // 📦 Backup configuration (used by Portal\Core\DbBackup)
    'backup' => [
        // 🪞 Always create a JSON snapshot of every portal table before
        //    running upgrade-path migrations. Set false to skip (NOT
        //    recommended).
        'auto_backup_before_upgrade' => true,

        // 🗄️ Maximum number of historical backup directories to keep
        //    under web/_backups/. Older sets are pruned LIFO. Set to 0
        //    to keep all backups indefinitely.
        'keep_last_n' => 10,
    ],
];
