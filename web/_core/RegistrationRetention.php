<?php
// Path: _core/RegistrationRetention.php
/**
 * -----------------------------------------------------------------------------
 * How long an event registration's personal details are kept 🧒⏳ (#816)
 * -----------------------------------------------------------------------------
 * The ONE place that answers "for how many days after the event may a
 * registration's name and contact details be kept here?", and the ONE writer of
 * the two settings that decide it. Do not read or write them anywhere else.
 *
 *   events.registrationRetentionDays     the period. Seeded installation-wide
 *                                        (90 days, migration 191); an
 *                                        organisation may have its own row.
 *   events.registrationRetentionMaxDays  the OPTIONAL ceiling (#816, migration
 *                                        257). Installation-wide only. 0 means
 *                                        "no ceiling", which is how it ships.
 *
 * A third figure exists and is read here too: `tblEvents.registrationRetentionDays`,
 * an event's own period, which wins over its organisation's when it is set.
 *
 * -----------------------------------------------------------------------------
 * WHAT A REGISTRATION HOLDS, SO THE FIGURE IS NOT SET CASUALLY
 * -----------------------------------------------------------------------------
 * A child's name, date of birth, allergies and medical notes, beside a parent's
 * telephone number and e-mail address. Most are made by people with no account,
 * so an erasure request has nothing to match them on: this time limit is what
 * removes them (#479, migration 191).
 *
 * -----------------------------------------------------------------------------
 * THE CEILING IS OPT-IN, AND THAT IS THE OWNER'S DECISION (D-074)
 * -----------------------------------------------------------------------------
 * Asked whether the installation's figure should cap an organisation's here, as
 * it does for attendance (#668) and anonymous check-ins (#685), the owner chose
 * the giving-records approach instead (D-032): an OPTIONAL ceiling, OFF by
 * default. A registration period can be a legal requirement — safeguarding
 * rules may make an organisation keep a child's registration for years — and a
 * ceiling forced on from the start could make it delete details it must keep.
 * So until the installation's administrator sets one, NOTHING CHANGES.
 *
 * -----------------------------------------------------------------------------
 * TWO SAVE-TIME GATES, AND WHY THE CLEAR-OUT DOES NOT CAP (D-032's shape)
 * -----------------------------------------------------------------------------
 *   1. A period ABOVE the ceiling is REFUSED with the reason — an organisation's
 *      own period, and the installation's own period. Never clamped: silently
 *      shortening a number somebody typed is how a person comes to believe a
 *      period is in force when it is not (#668).
 *   2. A ceiling BELOW a period somebody already holds is REFUSED — when the
 *      ceiling is raised as well as lowered, as the giving-records ceiling
 *      does — and the installation's administrator is told WHO holds it: the
 *      installation's own period, each organisation with a longer period of
 *      its own, and each event with a longer period of its own. Removing the
 *      ceiling altogether is never refused, which is the way out.
 *
 * Between them those keep "every period <= the ceiling" true by construction,
 * so the nightly clear-out never meets a period it would have to shorten, and
 * it does NOT apply the ceiling. That looks like #668's "a save-time cap is not
 * a cap" and is not, for the reason D-032 gives: where a period above the
 * ceiling does exist — written by hand in SQL, or brought back by a restored
 * backup — the job obeys the STORED period and keeps the details LONGER. Keeping
 * too long is a privacy fault somebody can see and fix; deleting too early may
 * be unlawful and can never be undone. The page says so when it sees one.
 *
 * BOTH GATES RE-READ THE CEILING LIVE AND LOCK THE INSTALLATION'S OWN CEILING
 * ROW (`FOR UPDATE`) INSIDE THE TRANSACTION THAT WRITES. #687 measured why: a
 * gate judged against the request's settings snapshot let two ordinary
 * overlapping web requests store fifty years under a ceiling of seven, in 60 of
 * 60 pairs. Both savers here lock the SAME row, so one waits for the other and
 * sees what it committed. A lock outside a transaction is decoration (#697), so
 * every lock is taken after `begin_transaction()`.
 *
 * AND THEY JUDGE ONLY WHAT CHANGED (#687's three false refusals). A Save that
 * stores the same period the organisation already has is not judged, so a
 * period above the ceiling — which only SQL or a restore can produce — never
 * blocks an untouched Save, and never forces somebody to shorten a legal
 * retention period just to use the page.
 *
 * -----------------------------------------------------------------------------
 * WHO CAN WRITE EACH FIGURE, MEASURED (7 November 2026)
 * -----------------------------------------------------------------------------
 * `git grep -n "registrationRetentionDays" -- web` finds no page, API handler,
 * import or series page that writes `tblEvents.registrationRetentionDays`:
 * `calendar/manage/series-repattern.php` names it only to SKIP it when copying
 * a date, and `events/api/detail.php` only reads it. So an event's own period
 * can today only come from SQL or a restore, and the one gate it meets is
 * gate 2. If a page is ever given a box for it, that page must ask this class.
 *
 * `events.registrationRetentionDays` was written only by the general settings
 * editor (`_apps/settings/save.php`): its installation row by a global
 * administrator, and an EXISTING organisation row by that organisation's
 * administrator (the editor refuses to ADD one, because the installation holds
 * the key). That editor now refuses both keys — see lockedInGeneralEditor() —
 * and they are written on `/admin/settings/registrations`, through this class.
 *
 * -----------------------------------------------------------------------------
 * HOW A STORED PERIOD IS READ — the job's arithmetic, kept exactly
 * -----------------------------------------------------------------------------
 * daysFromStored() reproduces what `registration_sweep_sites()` has done since
 * migration 191, including its oddities, because the ceiling's gate 2 must judge
 * a stored row exactly as the job will act on it: no row is 90 days, a negative
 * number is 90 days (a typing mistake, not an intention), and anything else is
 * PHP's `(int)` of the text, so an EMPTY value is 0 — "keep indefinitely". That
 * last one is surprising and is NOT changed here: changing how an existing row
 * is read would change what the job deletes on an installation nobody touched.
 *
 * An EVENT's own period of 0 or less is also "kept indefinitely", because the
 * job's clause is `COALESCE(e.registrationRetentionDays, ?) > 0`.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/816
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class RegistrationRetention
{
    /** The period, dotted. Seeded installation-wide; an organisation may have its own row. */
    public const KEY_DAYS = 'events.registrationRetentionDays';

    /** The optional ceiling, dotted. Installation-wide only; 0 means none. */
    public const KEY_MAX_DAYS = 'events.registrationRetentionMaxDays';

    /** The clear-out's on/off switch, dotted. Seeded installation-wide as 'true'; an organisation may have its own row. */
    public const KEY_RUN = 'events.registrationRetentionRun';

    /** What the job has always used when nothing is stored (migration 191). */
    public const DEFAULT_DAYS = 90;

    /** No ceiling. A stored 0 means this, so 0 is the LARGEST value (#668's trap). */
    public const NO_CAP = 0;

    /**
     * The most a period or a ceiling may ever be: one hundred years.
     *
     * Deliberately generous. This is the first time anything has bounded the
     * figure at all, and some safeguarding records are kept for a lifetime; a
     * hard maximum that refused a period somebody is legally bound to keep
     * would be exactly the harm D-074 exists to avoid. It is here only so a
     * typing slip cannot store a number MySQL's date arithmetic cannot use.
     */
    public const HARD_MAX_DAYS = 36500;

    /**
     * What the general settings editor says when it refuses one of the keys.
     * It names the page that DOES write them, and never says who may change the
     * ceiling: the two levels may be different parties.
     */
    public const LOCKED_MESSAGE = 'How long event registrations are kept is set on Admin, Registration '
        . 'retention, where a period is checked against the installation\'s ceiling and a refusal '
        . 'is recorded. It is not changed here. Nothing was changed.';

    // #########################################################################
    // 🔒 The general settings editor
    // #########################################################################

    /**
     * Whether the general settings editor must refuse this key — to change, to
     * add, and to delete.
     *
     * BOTH KEYS, FOR EVERYBODY, a global administrator included. Changing the
     * ceiling there would skip gate 2; changing a period there would skip
     * gate 1; and deleting either is not a smaller act — with the ceiling row
     * gone there is no ceiling, and with the installation's period row gone
     * every organisation following it falls back to 90 days, which may be above
     * a ceiling. `events.registrationRetentionRun` (the on/off switch) is NOT
     * locked: it is not a period and nothing here judges it.
     *
     * Compared without regard to capitals, as the database compares names.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::KEY_DAYS) === 0 || strcasecmp($key, self::KEY_MAX_DAYS) === 0;
    }

    // #########################################################################
    // 📖 Reading
    // #########################################################################

    /**
     * 🔢 A stored period as the clear-out job acts on it. See the header.
     *
     * @param mixed $stored The settingValue as read, or null when there is no row.
     */
    public static function daysFromStored(mixed $stored): int
    {
        if ($stored === null) {
            return self::DEFAULT_DAYS;
        }
        $days = (int) (string) $stored;

        return $days < 0 ? self::DEFAULT_DAYS : $days;
    }

    /**
     * 🧹 The period ONE NAMED organisation uses for an event with no period of
     *    its own. 0 means "keep indefinitely". For the clear-out job.
     *
     * Asked by organisation number, never from the merged `$SETTINGS`: a
     * scheduled job merges the settings once for the whole run (#683), so the
     * merged array would apply one organisation's figure to every other's.
     *
     * NOT CAPPED BY THE CEILING, on purpose (D-074 following D-032): see the
     * header. A stored period above the ceiling keeps details LONGER.
     */
    public static function daysForSite(int $siteId): int
    {
        return self::daysFromStored(App::settingForSite(self::KEY_DAYS, $siteId));
    }

    /**
     * 🔛 Whether the clear-out runs at all for ONE NAMED organisation.
     *
     * Moved here from `registration_sweep_sites()` (#816) so the job AND the two
     * pages that say what happens to registrations ask the SAME question: a page
     * that read only the period would tell an administrator that children's
     * details are deleted after 90 days for an organisation whose clear-out is
     * switched off, where nothing is deleted at all. The reading is the job's,
     * unchanged since migration 191: no row means ON, and anything but the exact
     * text `true` means OFF.
     */
    public static function clearOutRunsForSite(int $siteId): bool
    {
        return (string) (App::settingForSite(self::KEY_RUN, $siteId) ?? 'true') === 'true';
    }

    /**
     * 🏢 The period ONE organisation has chosen FOR ITSELF — null when it has
     *    no row of its own.
     *
     * Read at the organisation's own level, never through settingForSite() or
     * the merged array, both of which fall back to the installation's row: a box
     * filled from either shows a number this organisation never chose, and one
     * press of Save makes it its own (#674, #677).
     */
    public static function ownDays(\mysqli $db, int $siteId): ?int
    {
        $stored = self::storedValue($db, self::KEY_DAYS, $siteId, false);

        return $stored === null ? null : self::daysFromStored($stored);
    }

    /** 🏛️ The INSTALLATION's own period, read live from its own row. */
    public static function installationDays(\mysqli $db): int
    {
        return self::daysFromStored(self::storedValue($db, self::KEY_DAYS, null, false));
    }

    /**
     * 🛑 The ceiling as the database has it RIGHT NOW — 0 for none — with its
     *    row read live.
     *
     * For a page that must show what is stored rather than what the request
     * started with (the installation section's own box). Not locked: the save
     * paths take the lock themselves, inside their transaction.
     */
    public static function ceilingNow(\mysqli $db): int
    {
        return self::ceilingFromStored(self::storedValue($db, self::KEY_MAX_DAYS, null, false));
    }

    /**
     * 🔢 A stored ceiling as a number of days, 0 for none.
     *
     * `SettingLimit` is the ONE place the arithmetic of a three-tier limit
     * lives, and its zero trap is passed rather than re-derived here: a stored
     * 0 means "no ceiling", so it is the LARGEST value. Unreadable text counts
     * as no ceiling, which is the giving-records precedent (D-032) and the
     * direction that keeps details rather than deleting them; nothing but this
     * class writes the row, and it writes only whole numbers.
     */
    public static function ceilingFromStored(mixed $stored): int
    {
        self::needSettingLimit();

        if (SettingLimit::allowsForEverFrom($stored, self::NO_CAP, self::HARD_MAX_DAYS) === true) {
            return self::NO_CAP;
        }

        return SettingLimit::ceilingFrom($stored, self::NO_CAP, self::HARD_MAX_DAYS, true);
    }

    /** Whether a period is longer than a ceiling. 0 (indefinitely) is longer than every ceiling. */
    public static function isAboveCeiling(int $days, int $ceiling): bool
    {
        if ($ceiling === self::NO_CAP) {
            return false;
        }

        return $days <= 0 || $days > $ceiling;
    }

    /** A period in words: "90 days", "1 day", "kept indefinitely". */
    public static function daysInWords(int $days): string
    {
        if ($days <= 0) {
            return 'kept indefinitely';
        }

        return $days . ' ' . ($days === 1 ? 'day' : 'days');
    }

    // #########################################################################
    // 🚫 Gate 1 — a period above the ceiling
    // #########################################################################

    /**
     * 🚫 Why this period may not be saved under this ceiling — '' when it may.
     *
     * Delegates to `SettingLimit::whyNotAllowedFor()`, the ONE place the rule
     * is decided, with the ceiling passed as the stored value read LIVE and the
     * zero flag set because 0 means "indefinitely" for the period and "no
     * ceiling" for the ceiling. It takes the ceiling as a FACT rather than
     * fetching one, because the save asks it inside the transaction with the
     * locked value (#647's shape).
     *
     * @return string '', 'above-installation' or 'above-hard-max'.
     */
    public static function whyDaysNotAllowed(int $days, mixed $ceilingStored): string
    {
        self::needSettingLimit();

        return SettingLimit::whyNotAllowedFor($days, $ceilingStored, self::NO_CAP, self::HARD_MAX_DAYS, true);
    }

    /**
     * 💬 A refusal in words for an organisation's administrator.
     *
     * `SettingLimit::refusalWords()` is the one wording, and it never says WHO
     * set the ceiling — the two levels may be different parties.
     */
    public static function refusalWords(string $reason, mixed $ceilingStored, int $days): string
    {
        if ($reason === 'above-hard-max') {
            return 'A period can be at most ' . self::HARD_MAX_DAYS . ' days. Nothing was saved.';
        }

        self::needSettingLimit();

        return SettingLimit::refusalWords(
            $reason,
            SettingLimit::ceilingFrom($ceilingStored, self::NO_CAP, self::HARD_MAX_DAYS, true),
            'days',
            $days === 0
        );
    }

    // #########################################################################
    // 🚫 Gate 2 — a ceiling below a period somebody holds
    // #########################################################################

    /**
     * 🏢 Every ORGANISATION and every EVENT whose own period is longer than a
     *    proposed ceiling. The installation's own period is judged separately
     *    by the caller, because it may be changing in the same save.
     *
     * Only periods OF THEIR OWN: an organisation with no row follows the
     * installation's period, which gate 1 already holds to the ceiling.
     *
     * Names are returned, not just counts, because the reader is the
     * installation's own administrator and these are their organisations. The
     * activity log is given counts only, because it is read later and by
     * others (the giving-records precedent).
     *
     * A READ THAT FAILS THROWS. It must never answer "nobody", which would let a
     * ceiling through that shortens somebody's period.
     *
     * @return array{organisations: list<array{name: string, days: int}>,
     *               events: list<array{name: string, organisation: string, days: int}>,
     *               eventCount: int}
     */
    public static function periodsAboveCeiling(\mysqli $db, int $ceiling): array
    {
        $out = ['organisations' => [], 'events' => [], 'eventCount' => 0];
        if ($ceiling === self::NO_CAP) {
            return $out;
        }

        $stmt = $db->prepare(
            'SELECT `s`.`siteName` AS `name`, `t`.`settingValue` AS `value` FROM `tblSettings` AS `t` '
            . 'INNER JOIN `tblSites` AS `s` ON `s`.`siteID` = `t`.`siteID` '
            . 'WHERE `t`.`settingKey` = ? AND `t`.`siteID` IS NOT NULL ORDER BY `s`.`siteName`'
        );
        if ($stmt === false) {
            throw new \RuntimeException('The organisations\' own periods could not be read.');
        }
        $key = self::KEY_DAYS;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $rows = $stmt->get_result();
        while ($row = $rows->fetch_assoc()) {
            $days = self::daysFromStored($row['value']);
            if (self::isAboveCeiling($days, $ceiling) === true) {
                $out['organisations'][] = ['name' => (string) $row['name'], 'days' => $days];
            }
        }
        $rows->free();
        $stmt->close();

        // 📅 Events. The condition mirrors the job's: 0 or less is "kept
        //    indefinitely", which is longer than every ceiling. Soft-deleted and
        //    imported events are included, because the job's clause includes
        //    them: their registrations are cleared by the same period.
        $where = 'FROM `tblEvents` AS `e` INNER JOIN `tblSites` AS `s` ON `s`.`siteID` = `e`.`siteID` '
            . 'WHERE `e`.`registrationRetentionDays` IS NOT NULL '
            . 'AND (`e`.`registrationRetentionDays` <= 0 OR `e`.`registrationRetentionDays` > ?)';

        $count = $db->prepare('SELECT COUNT(*) AS `n` ' . $where);
        if ($count === false) {
            throw new \RuntimeException('The events\' own periods could not be read.');
        }
        $count->bind_param('i', $ceiling);
        $count->execute();
        $out['eventCount'] = (int) ($count->get_result()->fetch_assoc()['n'] ?? 0);
        $count->close();

        if ($out['eventCount'] > 0) {
            $list = $db->prepare(
                'SELECT `e`.`eventName` AS `name`, `s`.`siteName` AS `organisation`, '
                . '`e`.`registrationRetentionDays` AS `days` ' . $where
                . ' ORDER BY `s`.`siteName`, `e`.`startDateTime`, `e`.`eventID` LIMIT 10'
            );
            if ($list === false) {
                throw new \RuntimeException('The events\' own periods could not be read.');
            }
            $list->bind_param('i', $ceiling);
            $list->execute();
            $rows = $list->get_result();
            while ($row = $rows->fetch_assoc()) {
                $out['events'][] = [
                    'name'         => (string) $row['name'],
                    'organisation' => (string) $row['organisation'],
                    'days'         => (int) $row['days'],
                ];
            }
            $rows->free();
            $list->close();
        }

        return $out;
    }

    // #########################################################################
    // 💾 Saving
    // #########################################################################

    /**
     * 💾 Store, clear or refuse ONE organisation's own period.
     *
     * `$days` of null means "follow the installation": the organisation's own
     * row is DELETED. The caller must have established that the field really
     * ARRIVED before passing null (#677).
     *
     * A period equal to the one already stored is NOT judged and NOT written
     * (#687): an untouched Save must pass, even over a period above the
     * ceiling that only SQL or a restore could have put there.
     *
     * @param \mysqli  $db     An open connection, NOT already in a transaction.
     * @param int      $siteId The organisation.
     * @param int|null $days   The period to store, or null to follow the installation.
     *
     * @return array{saved: bool, type: string, message: string, refusal: string, outcome: string}
     *         `outcome` is 'stored', 'cleared', 'unchanged' or 'refused'.
     */
    public static function saveForSite(\mysqli $db, int $siteId, ?int $days): array
    {
        $refuse = static fn (string $message, string $reason): array => [
            'saved' => false, 'type' => 'danger', 'message' => $message, 'refusal' => $reason, 'outcome' => 'refused',
        ];
        if ($days !== null && ($days < 0 || $days > self::HARD_MAX_DAYS)) {
            // Not "from 0 to …": under a ceiling 0 is refused too, and this check
            // runs before the ceiling is read, so it says only what is always true.
            return $refuse('A period can be at most ' . self::HARD_MAX_DAYS . ' days. Nothing was saved.', 'above-hard-max');
        }

        try {
            // 🔒 Transaction FIRST, so the lock below is a real one (#697).
            $db->begin_transaction();
            $ceilingStored = self::storedValue($db, self::KEY_MAX_DAYS, null, true);
            $current       = self::storedValue($db, self::KEY_DAYS, $siteId, true);
            $currentDays   = $current === null ? null : self::daysFromStored($current);

            if ($days === $currentDays) {
                $db->commit();

                return [
                    'saved' => true, 'type' => 'info', 'refusal' => '', 'outcome' => 'unchanged',
                    'message' => 'Nothing was different, so nothing was changed.',
                ];
            }

            if ($days !== null) {
                $reason = self::whyDaysNotAllowed($days, $ceilingStored);
                if ($reason !== '') {
                    $db->rollback();

                    return $refuse(self::refusalWords($reason, $ceilingStored, $days), $reason);
                }
                self::writeRow($db, $siteId, self::KEY_DAYS, (string) $days);
            } else {
                $del = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
                $key = self::KEY_DAYS;
                $del->bind_param('si', $key, $siteId);
                $del->execute();
                $del->close();
            }
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
                // Nothing more can be done; the connection is closed at the end of the request.
            }
            // The fault's own words are not logged: under strict reporting they
            // can quote the statement, and nothing an administrator acts on is
            // in them.
            error_log('[WebMS-Intra] RegistrationRetention: the registration period of organisation #'
                . $siteId . ' could not be saved (' . get_class($e) . ').');

            return $refuse('The period could not be saved because the database refused the change. '
                . 'Nothing was changed.', '');
        }

        if ($days === null) {
            return [
                'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'cleared',
                'message' => 'Your organisation now follows the period this installation sets.',
            ];
        }

        return [
            'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'stored',
            'message' => $days === 0
                ? 'Saved. Your organisation now keeps registrations indefinitely.'
                : 'Saved. Your organisation now keeps registrations for ' . self::daysInWords($days) . ' after the event.',
        ];
    }

    /**
     * 🏛️ Store or refuse the INSTALLATION's own period and/or its ceiling. A
     *    global administrator only — checked here as well as at the page,
     *    because this writes for every organisation at once and the two levels
     *    are two different parties (the owner, 29 October 2026).
     *
     * Each argument is null when its field did not ARRIVE (#677), and a field
     * that arrived holding what is already stored is not judged (#687).
     *
     * @param int|null $days    The installation's period, or null to leave it.
     * @param int|null $ceiling The ceiling (0 for none), or null to leave it.
     *
     * @return array{saved: bool, type: string, message: string, changes: list<string>,
     *               blockedOrganisations: int, blockedEvents: int, blockedInstallation: bool}
     */
    public static function saveInstallation(\mysqli $db, ?int $days, ?int $ceiling, bool $globalAdmin): array
    {
        $refuse = static fn (string $message, int $orgs = 0, int $events = 0, bool $own = false): array => [
            'saved' => false, 'type' => 'danger', 'message' => $message, 'changes' => [],
            'blockedOrganisations' => $orgs, 'blockedEvents' => $events, 'blockedInstallation' => $own,
        ];
        if ($globalAdmin === false) {
            return $refuse('Only a global administrator can change what applies to every organisation. Nothing was saved.');
        }
        if ($days !== null && ($days < 0 || $days > self::HARD_MAX_DAYS)) {
            return $refuse('The installation\'s period has to be a whole number of days from 0 to '
                . self::HARD_MAX_DAYS . '. Nothing was saved.');
        }
        if ($ceiling !== null && ($ceiling < 0 || $ceiling > self::HARD_MAX_DAYS)) {
            return $refuse('The ceiling has to be a whole number of days from 1 to ' . self::HARD_MAX_DAYS
                . ', or empty for no ceiling. Nothing was saved.');
        }

        try {
            // 🔒 The SAME row saveForSite() locks, so the two cannot interleave:
            //    without it both could read "nothing is above this" and then
            //    commit a ceiling and a period that contradict each other.
            $db->begin_transaction();
            $ceilingStored  = self::storedValue($db, self::KEY_MAX_DAYS, null, true);
            $currentCeiling = self::ceilingFromStored($ceilingStored);
            $currentDays    = self::daysFromStored(self::storedValue($db, self::KEY_DAYS, null, true));

            $newCeiling     = $ceiling ?? $currentCeiling;
            $newDays        = $days ?? $currentDays;
            $ceilingChanged = $newCeiling !== $currentCeiling;
            $daysChanged    = $newDays !== $currentDays;

            if ($ceilingChanged === false && $daysChanged === false) {
                $db->commit();

                return [
                    'saved' => true, 'type' => 'info', 'message' => 'Nothing was different, so nothing was changed.',
                    'changes' => [], 'blockedOrganisations' => 0, 'blockedEvents' => 0, 'blockedInstallation' => false,
                ];
            }

            // 🚫 Gate 1 for the installation's own period: judged against the
            //    ceiling that will be in force AFTER this save, which may be
            //    changing in the same request.
            if ($daysChanged === true && self::isAboveCeiling($newDays, $newCeiling) === true) {
                $db->rollback();

                return $refuse('The installation\'s own period (' . self::daysInWords($newDays)
                    . ') would be longer than the ceiling of ' . self::daysInWords($newCeiling)
                    . '. Choose a period of ' . $newCeiling . ' days or fewer, or a longer ceiling. Nothing was saved.',
                    0, 0, true);
            }

            // 🚫 Gate 2: a NEW ceiling below a period somebody already holds —
            //    whether the ceiling is going UP or DOWN, as GivingRetention's
            //    installation ceiling does (D-032, which D-074 says to follow).
            //    A first version let a RAISE through; that was reverted in the
            //    #816 check (round 2) because it departed from the precedent
            //    without a decision, and the state it eased is reachable only
            //    by SQL or a restore.
            //
            //    The way out of such a state is always open: REMOVING the
            //    ceiling ("no ceiling", stored 0) is never refused, because it
            //    can shorten nobody's period.
            if ($ceilingChanged === true && $newCeiling !== self::NO_CAP) {
                $ownAbove = $daysChanged === false && self::isAboveCeiling($newDays, $newCeiling) === true;
                $above    = self::periodsAboveCeiling($db, $newCeiling);
                if ($ownAbove === true || $above['organisations'] !== [] || $above['eventCount'] > 0) {
                    $db->rollback();

                    return $refuse(
                        self::ceilingRefusalWords($newCeiling, $ownAbove === true ? $newDays : null, $above),
                        count($above['organisations']),
                        $above['eventCount'],
                        $ownAbove
                    );
                }
            }

            $changes = [];
            if ($daysChanged === true) {
                self::writeRow($db, null, self::KEY_DAYS, (string) $newDays);
                $changes[] = 'the installation\'s period from ' . self::daysInWords($currentDays) . ' to ' . self::daysInWords($newDays);
            }
            if ($ceilingChanged === true) {
                self::writeRow($db, null, self::KEY_MAX_DAYS, (string) $newCeiling);
                $changes[] = 'the ceiling from ' . ($currentCeiling === self::NO_CAP ? 'none' : self::daysInWords($currentCeiling))
                    . ' to ' . ($newCeiling === self::NO_CAP ? 'none' : self::daysInWords($newCeiling));
            }
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
                // Nothing more can be done.
            }
            error_log('[WebMS-Intra] RegistrationRetention: the installation\'s registration settings could not be saved ('
                . get_class($e) . ').');

            return $refuse('The change could not be saved: either the periods already chosen could not be read '
                . 'to check it, or the database refused it. Nothing was saved.');
        }

        return [
            'saved' => true, 'type' => 'success', 'changes' => $changes,
            'blockedOrganisations' => 0, 'blockedEvents' => 0, 'blockedInstallation' => false,
            'message' => 'Saved. ' . ($newCeiling === self::NO_CAP
                ? 'There is no ceiling: every organisation may choose any period.'
                : 'No organisation may now choose a period longer than ' . self::daysInWords($newCeiling)
                  . '. An organisation that has chosen less keeps its own period.')
                . ' This installation\'s own period is ' . self::daysInWords($newDays) . '.',
        ];
    }

    /** 💬 The refusal when a ceiling would be below periods already held — for the installation's administrator. */
    private static function ceilingRefusalWords(int $ceiling, ?int $installationDays, array $above): string
    {
        $parts = [];
        if ($installationDays !== null) {
            $parts[] = 'this installation\'s own period (' . self::daysInWords($installationDays) . ')';
        }
        foreach ($above['organisations'] as $one) {
            $parts[] = $one['name'] . ' (' . self::daysInWords((int) $one['days']) . ')';
        }
        foreach ($above['events'] as $one) {
            $parts[] = 'the event "' . $one['name'] . '" at ' . $one['organisation']
                . ' (' . self::daysInWords((int) $one['days']) . ')';
        }
        $more = (int) $above['eventCount'] - count($above['events']);
        if ($more > 0) {
            $parts[] = $more . ' more ' . ($more === 1 ? 'event' : 'events');
        }

        $words = 'A ceiling of ' . self::daysInWords($ceiling) . ' is shorter than periods already chosen, so it was '
            . 'not saved: ' . implode('; ', $parts) . '. A registration period can be a legal requirement, so the '
            . 'portal will not shorten one behind anybody\'s back. Lower those periods first, or choose a longer ceiling.';
        if ((int) $above['eventCount'] > 0) {
            // Said plainly, because nothing in the portal can change it.
            $words .= ' An event\'s own period is not set by any page in this portal, so one listed here was '
                . 'written directly into the database or brought back by a restored backup.';
        }

        return $words . ' Nothing was saved.';
    }

    // #########################################################################
    // 🔧 Loading, and the two statements
    // #########################################################################

    /**
     * 📦 Load SettingLimit and what it reads, if nothing else has.
     *
     * The house pattern (GivingRetention::needSettingLimit()). Under the
     * portal's own start-up the autoloader has done this already; it matters
     * for a self-test that requires `_apps/cron/_retention-sweep.php` with a
     * hand-picked list of classes, which is how a missing dependency has
     * stopped a test dead twice before. Each require is guarded.
     */
    private static function needSettingLimit(): void
    {
        if (class_exists(InstallationSettings::class) === false) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'InstallationSettings.php';
        }
        if (class_exists(App::class) === false) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'App.php';
        }
        if (class_exists(SettingLimit::class) === false) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'SettingLimit.php';
        }
    }

    /**
     * One settings row's text, or null when there is no row.
     *
     * With `$lock`, the row is read `FOR UPDATE`; the caller must already be in
     * a transaction or the lock is released at once (#697). Every name here is a
     * constant of this class, never a request value.
     */
    private static function storedValue(\mysqli $db, string $key, ?int $siteId, bool $lock): ?string
    {
        $stmt = $db->prepare(
            'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` '
            . ($siteId === null ? 'IS NULL' : '= ?')
            . ($lock === true ? ' FOR UPDATE' : '')
        );
        if ($stmt === false) {
            throw new \RuntimeException('A registration setting could not be read.');
        }
        if ($siteId === null) {
            $stmt->bind_param('s', $key);
        } else {
            $stmt->bind_param('si', $key, $siteId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (string) $row['settingValue'];
    }

    /** Write one settings row at one level (null = installation-wide). */
    private static function writeRow(\mysqli $db, ?int $siteId, string $key, string $value): void
    {
        $stmt = $db->prepare(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`, `updatedAt`) '
            . 'VALUES (?, ?, ?, ?, 0, NOW()) '
            . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), `updatedAt` = NOW()'
        );
        if ($stmt === false) {
            throw new \RuntimeException('A registration setting could not be written.');
        }
        $default = $siteId === null ? $value : '';
        $stmt->bind_param('isss', $siteId, $key, $value, $default);
        $stmt->execute();
        $stmt->close();
    }
}
