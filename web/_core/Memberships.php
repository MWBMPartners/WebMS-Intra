<?php
// Path: _core/Memberships.php
/**
 * -----------------------------------------------------------------------------
 * Memberships: the ONE place a membership is switched off or on 🚪
 * -----------------------------------------------------------------------------
 * The ONE place that holds the SQL which switches a membership
 * (`tblUserSites`) off or on and writes the moment it was switched off
 * (`leftAtUtc`, a MOMENT IN UTC), and the ONE definition of "this person has
 * left an organisation" (`hasLeftSql()`). Do not write another statement that
 * changes `tblUserSites.isActive` or `leftAtUtc`: the hard gate
 * `tools/audit-checks/check_one_membership_switch.py` fails on it (#934, D-211).
 *
 * WHY. D-211: the records of somebody agreeing to the privacy notice, and of
 * their cookie choices, are deleted the privacy data-retention period after
 * they leave an organisation. Counting that needs the date, and the date is
 * only true if every place that switches a membership off records it. There
 * were two such places (offboarding switches off, rehire switches on).
 *
 * THIS CLASS HOLDS SQL TEXT ONLY. Each caller keeps its own runner (offboarding
 * keeps its step log and its deadlock re-throw; rehire keeps its own
 * statements), so nothing about how a step reports changes.
 *
 * THE MOMENT is the switch-off itself, `UTC_TIMESTAMP()`, never offboarding's
 * typed "effective date" (unvalidated posted text with no zone, while access
 * ends now). Cost, stated: a future-dated offboarding starts the clock now.
 *
 * ASSIGNMENT-ORDER TRAP. MySQL evaluates the SET list of a single-table UPDATE
 * left to right, using the values already assigned. So
 * `SET isActive = 0, leftAtUtc = IF(isActive = 1, ...)` would read the NEW 0.
 * The switch-off therefore keeps its test in the WHERE (`isActive = 1`), which
 * is also what is wanted: a membership that is already off keeps the date it
 * has (the person left that organisation earlier and that is the true date),
 * and one already off with NO date stays empty (no date is guessed). Never
 * rewrite it as a conditional SET.
 *
 * Never read `leftAtUtc` in `Site::organisationsFor()`: it runs before the
 * upgrade page and may only read columns present since migration 015.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/934
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class Memberships
{
    /** Switch off every switched-on membership of one person and stamp the moment. One `?`: the account number. */
    public const SWITCH_OFF_ALL_SQL = 'UPDATE `tblUserSites` SET `leftAtUtc` = UTC_TIMESTAMP(), `isActive` = 0 '
        . 'WHERE `userID` = ? AND `isActive` = 1';

    /** Switch on every membership of one person and empty the date. One `?`: the account number. */
    public const SWITCH_ON_ALL_SQL = 'UPDATE `tblUserSites` SET `isActive` = 1, `leftAtUtc` = NULL WHERE `userID` = ?';

    /** Switch on one membership and empty its date. Two `?`: the account number, then the organisation. */
    public const SWITCH_ON_ONE_SQL = 'UPDATE `tblUserSites` SET `isActive` = 1, `leftAtUtc` = NULL '
        . 'WHERE `userID` = ? AND `siteID` = ?';

    /**
     * Bring one person back by SWITCHING THE ACCOUNT ON (Admin, Users), on a ONE-organisation
     * portal only: switch that organisation's membership on and empty its date. Two
     * conditions do the work: the membership must be off (a row already on is left alone), and
     * the mode is read INSIDE the statement (no row, or any value other than exactly `true`, is
     * one organisation, with `CAST ... AS BINARY` as in hasLeftSql()), so with several
     * organisations it changes nothing and a failed read throws. Two `?`: the account number,
     * then the organisation open on the page.
     */
    public const SWITCH_ON_ONE_IF_SINGLE_SQL = 'UPDATE `tblUserSites` SET `isActive` = 1, `leftAtUtc` = NULL '
        . 'WHERE `userID` = ? AND `siteID` = ? AND `isActive` = 0 '
        . 'AND NOT EXISTS (SELECT 1 FROM `tblSettings` `msx` WHERE `msx`.`settingKey` = \'multisite.enabled\' '
        . 'AND `msx`.`siteID` IS NULL AND CAST(`msx`.`settingValue` AS BINARY) = CAST(\'true\' AS BINARY))';

    /**
     * 🚪 "Has this person left this organisation for longer than the period?",
     *    as a condition to put inside a WHERE. ONE `?`: the period in days.
     *
     * Each term, and why it is there:
     *  - `isActive = 0`: a switched-on row with a stray date (a hand edit, a
     *    restore) is NOT left.
     *  - `leftAtUtc IS NOT NULL`: with no date the clock has not started.
     *  - `leftAtUtc < UTC_TIMESTAMP() - period`: the column is UTC, so it is
     *    compared with the UTC clock. (`tblConsentLog.createdAt` is in the
     *    database session's zone and is compared with `NOW()` by its own
     *    caller. Never mix the two.)
     *  - `isRootAdmin = 0`: a global administrator belongs to every
     *    organisation (`Site::organisationsFor()`).
     *  - the last term is "does the person still belong, by the portal's own
     *    gate". With several organisations the gate reads the membership, so an
     *    off membership is enough. With ONE organisation the gate reads only the
     *    account's own switch (`SiteGate::check()` returns early), so the
     *    account must be off too: otherwise an offboarded person switched back
     *    on through Admin, Users is a current member, and their record of
     *    agreeing to the privacy notice would be deleted.
     *
     * THE MODE IS READ INSIDE THE STATEMENT, on purpose. Do NOT call
     * `AccountGuard::isSingleOrganisation()` here: its failure answer is "several
     * organisations", the direction that deletes MORE. Inside the statement a
     * failed read throws and deletes nothing. No row, or any value other than
     * exactly `true`, is one organisation (as `Site::preDetect()` reads it);
     * `CAST ... AS BINARY` because the column's collation ignores capitals and
     * PHP's reading is exactly `'true'`, so a stored `TRUE` must read as one
     * organisation here too.
     *
     * @param string $membershipAlias Alias of `tblUserSites` in the caller's query.
     * @param string $accountAlias    Alias of `tblUsers` in the caller's query.
     * @throws \InvalidArgumentException When an alias is not a plain identifier.
     */
    public static function hasLeftSql(string $membershipAlias, string $accountAlias): string
    {
        foreach ([$membershipAlias, $accountAlias] as $alias) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
                throw new \InvalidArgumentException('Memberships::hasLeftSql: "' . $alias . '" is not a plain alias.');
            }
        }
        $us = '`' . $membershipAlias . '`';
        $p  = '`' . $accountAlias . '`';

        return '(' . $us . '.`isActive` = 0 AND ' . $us . '.`leftAtUtc` IS NOT NULL'
            . ' AND ' . $us . '.`leftAtUtc` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)'
            . ' AND ' . $p . '.`isRootAdmin` = 0'
            . ' AND (' . $p . '.`isActive` = 0 OR EXISTS (SELECT 1 FROM `tblSettings` `msx`'
            . ' WHERE `msx`.`settingKey` = \'multisite.enabled\' AND `msx`.`siteID` IS NULL'
            . ' AND CAST(`msx`.`settingValue` AS BINARY) = CAST(\'true\' AS BINARY))))';
    }
}
