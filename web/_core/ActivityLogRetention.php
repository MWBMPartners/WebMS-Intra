<?php
// Path: _core/ActivityLogRetention.php
/**
 * -----------------------------------------------------------------------------
 * How long the activity log is kept — including "never delete" 📜♾️ (#932)
 * -----------------------------------------------------------------------------
 * The ONE place that answers "how many days are `tblActivityLogs` rows kept
 * here, or are they never deleted?", and the ONE writer of the setting that
 * decides it, `audit.retentionDays`. Do not read or write it anywhere else.
 *
 * WHAT CHANGED, AND WHY (the owner, 10 November 2026 — D-117)
 * -----------------------------------------------------------------------------
 * Until #932 a stored 0 fell back to a year, so there was no way to keep the
 * activity log for ever. The owner asked for exactly that: *"Allow activity log
 * to also be configurable to never delete records"*. So for THIS setting a
 * stored 0 now means "never delete", and it follows the portal's rule for
 * limits (#668, D-013): **0 is the LARGEST value**.
 *
 *   - The installation may choose a number of days (1 to 3650) or never.
 *   - An organisation may choose a number of days, or never, or nothing at all
 *     (it then follows the installation — never the built-in year, #668's
 *     first trap).
 *   - An organisation may choose never ONLY where the installation itself has
 *     chosen never. Under an installation with a number of days, every
 *     organisation is held to that number.
 *   - "Never" is a separate choice, past the 3650-day hard maximum. It is
 *     NEVER clamped to 3650: D-117 rules that out by name.
 *
 * THE ERROR LOG IS NOT PART OF THIS. `errors.retentionDays` (`tblErrors`) shares
 * the clear-out's helpers in `_apps/cron/_retention-sweep.php`, and there a
 * stored 0 still falls back to a year. The owner named the activity log only,
 * and D-117 rules out extending the choice. The helpers send ONLY this key here.
 *
 * WHAT "NEVER" COSTS, said wherever it is offered
 * -----------------------------------------------------------------------------
 * The log grows without limit. And an erasure UNLINKS a person's activity
 * records (the catalogue's decision for `tblActivityLogs`): it empties the
 * columns that point at them and keeps the record of what happened, so a
 * DESCRIPTION that names somebody by account number is not rewritten. Until
 * #932 the clear-out was what eventually removed such a line; under "never"
 * it stays for ever.
 *
 * WHO WRITES IT, MEASURED (10 November 2026)
 * -----------------------------------------------------------------------------
 * `git grep -n "audit.retentionDays" 203d771e -- web` finds two seeds in
 * `web/_sql/` and READERS only: no page or handler names the key. It was
 * written by the general settings editor (`_apps/settings/save.php`), which
 * changes the installation's row for a global administrator and refuses to ADD
 * an organisation's own row (the installation already holds the key). That
 * editor judged nothing, so it now refuses this key on all three of its paths
 * (change, add, delete) — see lockedInGeneralEditor() — and the setting is
 * written on Admin, Maintenance, Retention, through this class.
 *
 * WHO MAY CHANGE WHICH FIGURE (the owner, 10 November 2026 — D-118)
 * -----------------------------------------------------------------------------
 *   - The INSTALLATION's figure: a global administrator only. saveInstallation()
 *     refuses anybody else, as well as the page.
 *   - An ORGANISATION's own figure: that organisation's own administrator (and a
 *     global administrator), within the installation's limit — as with
 *     attendance and giving records. The owner chose this KNOWING it lets that
 *     administrator shorten how long their own actions are kept on record.
 *     saveForSite() takes whether the person is an administrator of THAT
 *     organisation as a fact, and refuses when they are not.
 *
 * LOWERING THE INSTALLATION'S FIGURE BELOW AN ORGANISATION'S IS REFUSED
 * (the owner, 10 November 2026 — D-119, following the giving-records ceiling,
 * D-032). A new installation figure shorter than one an organisation has
 * already chosen for itself — "never" counting as the longest — is REFUSED, and
 * the organisations are NAMED to the installation's administrator, so they can
 * be asked to lower their own figure first. Nothing is deleted until they do.
 * The organisations' figures are read LIVE, locked, inside the transaction that
 * writes, with the installation's row locked first (#687: the first version of
 * the giving gate compared against a snapshot and lost the race 60 times in
 * 60). If they cannot be read, the save THROWS inside its transaction and is
 * refused — "could not read" must never become "nobody is above it".
 *
 * Between them the two save-time gates keep "every organisation's own figure
 * is within the installation's" true for everything a page writes. A SAVE-TIME
 * REFUSAL IS NOT A CAP (#668), though, so the READER still caps: daysForSite()
 * holds a figure written by SQL or restored from a backup to the installation's
 * limit rather than obeying it. Both save methods re-read the installation's row
 * LIVE and LOCKED, inside the transaction that writes (#687; a lock outside a
 * transaction is decoration, #697), so the two savers cannot interleave.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/932
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/491
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class ActivityLogRetention
{
    /** The setting, dotted. Seeded installation-wide (365, migration 046); an organisation may have its own row. */
    public const KEY = 'audit.retentionDays';

    /** What an installation that has chosen nothing keeps the log for: a year, as it always has. */
    public const DEFAULT_DAYS = 365;

    /**
     * The most a NUMBER of days may be: ten years, the same ceiling the other
     * retention periods use. "Never" is not a number of days and is not held
     * to this; it is its own choice (D-117).
     */
    public const HARD_MAX_DAYS = 3650;

    /** "Never delete", as stored. 0 is the LARGEST value (#668's trap). */
    public const NEVER = 0;

    /**
     * What the general settings editor says when it refuses the key. It names
     * the page that DOES write it and does not say who may change it there.
     */
    public const LOCKED_MESSAGE = 'How long the activity log is kept is set on Admin, Maintenance, '
        . 'Retention, where an organisation\'s figure is checked against the installation\'s and a '
        . 'refusal is recorded. It is not changed here. Nothing was changed.';

    // #########################################################################
    // 🔒 The general settings editor
    // #########################################################################

    /**
     * Whether the general settings editor must refuse this key — to change, to
     * add and to delete, for everybody. Changing it there skips the three-tier
     * refusal; deleting the installation's row silently moves every
     * organisation that follows it back to a year. Compared without regard to
     * capitals, as the database compares names.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strcasecmp($key, self::KEY) === 0;
    }

    // #########################################################################
    // 📖 Reading
    // #########################################################################

    /**
     * 🏛️ The INSTALLATION's figure, from start-up's installation-wide layer:
     *    a number of days, or 0 for never.
     *
     * This is both the period for rows that belong to no organisation and the
     * ceiling every organisation is held to. Asked of `SettingLimit` with the
     * zero flag, because `installationAllows()` on its own answers 3650 for a
     * stored 0 — the right number to COMPARE against, and exactly the clamp
     * D-117 forbids if it were taken as the answer.
     *
     * When start-up has not handed the layer over, this answers the built-in
     * year: the clear-out then deletes, which is the direction it has always
     * taken, rather than inventing a "never" nobody chose.
     */
    public static function installationDays(): int
    {
        self::needSettingLimit();

        if (SettingLimit::installationAllowsForEver(self::KEY, self::DEFAULT_DAYS, self::HARD_MAX_DAYS) === true) {
            return self::NEVER;
        }

        return SettingLimit::installationAllows(self::KEY, self::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);
    }

    /**
     * 🏛️ The same reading, of a value the CALLER read (a locked row inside a
     *    save). Null — no row — is the built-in year; unreadable text is too.
     */
    public static function installationDaysFromStored(mixed $stored): int
    {
        self::needSettingLimit();

        if (SettingLimit::allowsForEverFrom($stored, self::DEFAULT_DAYS, self::HARD_MAX_DAYS) === true) {
            return self::NEVER;
        }

        return SettingLimit::ceilingFrom($stored, self::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);
    }

    /**
     * 🧹 The figure the clear-out uses for ONE NAMED organisation: a number of
     *    days, or 0 for never. Held to the installation's.
     *
     * Asked by number, never from the merged `$SETTINGS`: the scheduled job has
     * no current organisation (#491, #683). `App::settingForSite()` falls back
     * to the installation's row by itself, so an organisation that chose
     * nothing follows the installation.
     *
     * An UNUSABLE own figure — empty, not a whole number, negative — counts as
     * "nothing chosen" and follows the installation, as it did before #932.
     * A usable one goes through `SettingLimit::cappedForSite()` with the zero
     * flag, which is where "never survives only where BOTH levels mean it"
     * lives: own 0 under an installation of 30 is 30; own 0 under an
     * installation of never is never; own 1000 under never is 3650.
     */
    public static function daysForSite(int $siteId): int
    {
        self::needSettingLimit();

        $own  = App::settingForSite(self::KEY, $siteId);
        $text = $own === null ? '' : trim($own);
        if (preg_match('/^\d{1,18}$/', $text) !== 1) {
            return self::installationDays();
        }

        return SettingLimit::cappedForSite((int) $text, self::KEY, self::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);
    }

    /**
     * 🏢 What ONE organisation has chosen FOR ITSELF: a number of days, 0 for
     *    never, or null when it has no usable row of its own.
     *
     * Read at the organisation's own level, never through settingForSite() or
     * the merged array, both of which fall back to the installation's row: a
     * form filled from either shows a figure the organisation never chose, and
     * one press of Save makes it its own (#674, #677).
     */
    public static function ownDays(\mysqli $db, int $siteId): ?int
    {
        return self::usableOwn(self::storedValue($db, $siteId, false));
    }

    /** A figure in words: "365 days", "1 day", "never deleted". */
    public static function inWords(int $days): string
    {
        if ($days === self::NEVER) {
            return 'never deleted';
        }

        return $days . ' ' . ($days === 1 ? 'day' : 'days');
    }

    // #########################################################################
    // 🚫 The rule for an organisation
    // #########################################################################

    /**
     * 🚫 Why this figure may not be stored for an organisation under this
     *    installation figure — '' when it may.
     *
     * `SettingLimit::whyNotAllowedFor()` IS the rule, with the zero flag: a
     * posted 0 is allowed only where the installation's own stored value means
     * for ever, and a number of days only up to the installation's. It takes
     * the installation's value as a FACT, because a save asks it with the row
     * it has just read locked (#647's shape).
     *
     * @return string '', 'above-installation' or 'above-hard-max'.
     */
    public static function whyNotAllowedForSite(int $days, mixed $installationStored): string
    {
        self::needSettingLimit();

        return SettingLimit::whyNotAllowedFor($days, $installationStored, self::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);
    }

    /**
     * 💬 That refusal in words. `SettingLimit::refusalWords()` is the one
     *    wording for a limit and never says WHO set it (the two levels may be
     *    different parties); its "keep for ever" form is used for a refused
     *    never, so the advice can never name the 0 just refused.
     */
    public static function refusalWordsForSite(string $reason, mixed $installationStored, int $days): string
    {
        self::needSettingLimit();

        if ($reason === 'above-hard-max') {
            return 'A number of days can be at most ' . self::HARD_MAX_DAYS . ' (ten years). Nothing was saved.';
        }

        return SettingLimit::refusalWords(
            $reason,
            SettingLimit::ceilingFrom($installationStored, self::DEFAULT_DAYS, self::HARD_MAX_DAYS, true),
            'days',
            $days === self::NEVER
        );
    }

    // #########################################################################
    // 💾 Saving
    // #########################################################################

    /**
     * 🏛️ Store the INSTALLATION's figure: 1 to 3650 days, or 0 for never.
     *
     * A global administrator only (D-118), checked here as well as at the
     * page, because it binds every organisation at once.
     *
     * A FIGURE SHORTER THAN AN ORGANISATION'S OWN IS REFUSED (D-119), naming
     * the organisations — whether the installation's figure is going down OR
     * up, as the giving-records ceiling does (D-032): what matters is that the
     * new figure would sit below a figure somebody has already chosen, which a
     * page can only reach going down but SQL or a restore can leave in place
     * going up. Choosing "never" can shorten nobody and is never refused, so
     * that is always a way out. `blockedOrganisations` is a COUNT, for the
     * activity log; the names are in the message, which only the person who
     * pressed Save reads.
     *
     * Judged ONLY when the figure CHANGES (the giving-records lesson on false
     * refusals): a figure equal to the one in force is not written and not
     * judged, so an untouched Save changes nothing and says so.
     *
     * @param \mysqli $db          An open connection, NOT already in a transaction.
     * @param int     $days        1 to HARD_MAX_DAYS, or NEVER.
     * @param bool    $globalAdmin Whether the person saving is a global administrator.
     *
     * @return array{saved: bool, type: string, message: string, refusal: string, outcome: string, blockedOrganisations: int}
     *         `outcome` is 'stored', 'unchanged' or 'refused'.
     */
    public static function saveInstallation(\mysqli $db, int $days, bool $globalAdmin): array
    {
        $refuse = static fn (string $message, string $reason, int $blocked = 0): array => [
            'saved' => false, 'type' => 'danger', 'message' => $message, 'refusal' => $reason,
            'outcome' => 'refused', 'blockedOrganisations' => $blocked,
        ];
        if ($globalAdmin === false) {
            return $refuse('Only a global administrator can change what applies to every organisation. Nothing was saved.', 'not-global-admin');
        }
        if ($days < 0) {
            return $refuse('A number of days has to be a whole number from 1 to ' . self::HARD_MAX_DAYS . '. Nothing was saved.', 'not-a-number');
        }
        if ($days > self::HARD_MAX_DAYS) {
            // 🚫 Refused, never clamped (#668, D-013). "Never" is the way to
            //    keep it longer than ten years, and it is named.
            return $refuse('A number of days can be at most ' . self::HARD_MAX_DAYS . ' (ten years). '
                . 'To keep the activity log for ever, choose "Never delete" instead. Nothing was saved.', 'above-hard-max');
        }

        try {
            // 🔒 Transaction FIRST, so the lock below is real (#697). The SAME
            //    row saveForSite() locks, so the two cannot interleave.
            $db->begin_transaction();
            $current = self::installationDaysFromStored(self::storedValue($db, null, true));
            if ($current === $days) {
                $db->commit();

                return [
                    'saved' => true, 'type' => 'info', 'refusal' => '', 'outcome' => 'unchanged',
                    'blockedOrganisations' => 0, 'message' => 'Nothing was different, so nothing was changed.',
                ];
            }

            // 🚫 D-119: never below a figure an organisation has chosen. Read
            //    INSIDE this transaction, after the installation's row is locked,
            //    and locked itself; it THROWS rather than answer "none" when it
            //    cannot read, which lands in the catch below as a refusal.
            if ($days !== self::NEVER) {
                $above = self::organisationsAbove($db, $days);
                if ($above !== []) {
                    $db->rollback();

                    return $refuse(self::aboveRefusalWords($days, $above), 'organisations-above', count($above));
                }
            }

            self::writeRow($db, null, (string) $days);
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            // The fault's own words are not logged: under strict reporting they
            // can quote the statement, and nothing an administrator acts on is
            // in them.
            error_log('[WebMS-Intra] ActivityLogRetention: the installation\'s activity-log period could not be saved ('
                . get_class($e) . ').');

            return $refuse('The change could not be saved: either the organisations\' own figures could not be read '
                . 'to check it, or the database refused it. Nothing was changed.', 'database');
        }

        return [
            'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'stored', 'blockedOrganisations' => 0,
            'message' => $days === self::NEVER
                ? 'Saved. This installation now never deletes activity-log records, and every organisation may '
                  . 'choose a number of days or "Never delete" for itself.'
                : 'Saved. This installation now keeps activity-log records for ' . self::inWords($days)
                  . ', and no organisation may keep them longer.',
        ];
    }

    /**
     * 💬 The D-119 refusal, for the installation's administrator: the
     *    organisations by NAME with the figure each has chosen. Their own
     *    business, as `GivingRetention::capRefusalWords()` says of the same list.
     *
     * @param array<int, array{name: string, days: int}> $above
     */
    public static function aboveRefusalWords(int $days, array $above): string
    {
        $named = [];
        foreach ($above as $one) {
            $named[] = $one['name'] . ' (' . self::inWords($one['days']) . ')';
        }

        return 'Keeping activity-log records for ' . self::inWords($days) . ' is shorter than '
            . (count($above) === 1 ? 'an organisation has' : 'some organisations have')
            . ' already chosen for itself, so it was not saved: ' . implode(', ', $named) . '. '
            . 'The portal will not delete an organisation\'s records sooner than it chose behind its back. '
            . 'Ask them to lower their own figure first, or choose a longer figure. Nothing was saved.';
    }

    /**
     * 🏢 Store, clear or refuse ONE organisation's own figure.
     *
     * `$days` of null means "follow the installation": the organisation's own
     * row is DELETED. The caller must have established that the choice really
     * ARRIVED before passing null (#677).
     *
     * An organisation's never under an installation with a number of days is
     * REFUSED with the reason, never clamped (D-013, D-117). A figure equal to
     * the one already stored is not judged and not written (#687), so an
     * untouched Save over a "never" that only SQL or a restore could have put
     * there passes and changes nothing.
     *
     * @param \mysqli  $db                 An open connection, NOT already in a transaction.
     * @param int      $siteId             The organisation.
     * @param int|null $days               1 to HARD_MAX_DAYS, NEVER, or null to follow the installation.
     * @param bool     $adminOfOrganisation Whether the person saving administers THIS organisation (D-118);
     *                                     a global administrator counts.
     *
     * @return array{saved: bool, type: string, message: string, refusal: string, outcome: string}
     *         `outcome` is 'stored', 'cleared', 'unchanged' or 'refused'.
     */
    public static function saveForSite(\mysqli $db, int $siteId, ?int $days, bool $adminOfOrganisation): array
    {
        $refuse = static fn (string $message, string $reason): array => [
            'saved' => false, 'type' => 'danger', 'message' => $message, 'refusal' => $reason, 'outcome' => 'refused',
        ];
        if ($adminOfOrganisation === false) {
            return $refuse('Only an administrator of this organisation can change how long it keeps the activity log. Nothing was saved.', 'not-admin');
        }
        if ($days !== null && $days < 0) {
            return $refuse('A number of days has to be a whole number from 1 to ' . self::HARD_MAX_DAYS . '. Nothing was saved.', 'not-a-number');
        }

        try {
            // 🔒 Transaction FIRST (#697); the installation's row is read LIVE
            //    and LOCKED (#687), then this organisation's own row.
            $db->begin_transaction();
            $installationStored = self::storedValue($db, null, true);
            $currentText        = self::storedValue($db, $siteId, true);
            $currentOwn         = self::usableOwn($currentText);

            $unchanged = $days === null ? $currentText === null : $currentOwn === $days;
            if ($unchanged === true) {
                $db->commit();

                return [
                    'saved' => true, 'type' => 'info', 'refusal' => '', 'outcome' => 'unchanged',
                    'message' => 'Nothing was different, so nothing was changed.',
                ];
            }

            if ($days !== null) {
                $reason = self::whyNotAllowedForSite($days, $installationStored);
                if ($reason !== '') {
                    $db->rollback();

                    return $refuse(self::refusalWordsForSite($reason, $installationStored, $days), $reason);
                }
                self::writeRow($db, $siteId, (string) $days);
            } else {
                $del = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
                if ($del === false) {
                    throw new \RuntimeException('The activity-log period could not be removed.');
                }
                $key = self::KEY;
                $del->bind_param('si', $key, $siteId);
                $del->execute();
                $del->close();
            }
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] ActivityLogRetention: the activity-log period of organisation #'
                . $siteId . ' could not be saved (' . get_class($e) . ').');

            return $refuse('The change could not be saved because the database refused it. Nothing was changed.', 'database');
        }

        if ($days === null) {
            return [
                'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'cleared',
                'message' => 'Saved. This organisation now follows the installation\'s figure for the activity log.',
            ];
        }

        return [
            'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'stored',
            'message' => $days === self::NEVER
                ? 'Saved. This organisation\'s activity-log records are now never deleted.'
                : 'Saved. This organisation now keeps activity-log records for ' . self::inWords($days) . '.',
        ];
    }

    // #########################################################################
    // 🔧 Loading, and the statements
    // #########################################################################

    /**
     * 📦 Load what this class reads, if nothing else has (the house pattern,
     *    `RegistrationRetention::needSettingLimit()`). It matters for a
     *    self-test that requires `_apps/cron/_retention-sweep.php` with a
     *    hand-picked list of classes. Each require is guarded.
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

    /** An organisation's own stored text as a figure, or null when it is absent or unusable. */
    private static function usableOwn(?string $stored): ?int
    {
        $text = $stored === null ? '' : trim($stored);

        return preg_match('/^\d{1,18}$/', $text) === 1 ? (int) $text : null;
    }

    /**
     * 🏢 The organisations whose OWN figure is longer than `$days` — a never,
     *    or more days — keyed by organisation number, with name and figure.
     *
     * ONLY organisations with a usable figure of their own: one that chose
     * nothing follows the installation and can never be "above" it, and an
     * unusable figure is read by daysForSite() as "follow the installation" too.
     *
     * READ LOCKED (`FOR UPDATE`), and called only inside saveInstallation()'s
     * transaction after the installation's row is locked. saveForSite() locks
     * that same row before it writes, so the two serialise; the lock here also
     * means a plain snapshot can never answer for rows another save has just
     * committed. AND IT THROWS rather than answer an empty list it cannot stand
     * behind (`GivingRetention::organisationsAboveCap()`'s rule): "could not
     * read" must never read as "nobody is above it".
     *
     * @return array<int, array{name: string, days: int}>
     */
    private static function organisationsAbove(\mysqli $db, int $days): array
    {
        $stmt = $db->prepare(
            'SELECT `s`.`siteID`, `s`.`siteName`, `t`.`settingValue` FROM `tblSettings` AS `t` '
            . 'INNER JOIN `tblSites` AS `s` ON `s`.`siteID` = `t`.`siteID` '
            . 'WHERE `t`.`settingKey` = ? AND `t`.`siteID` IS NOT NULL ORDER BY `s`.`siteName` FOR UPDATE'
        );
        if ($stmt === false) {
            throw new \RuntimeException('The organisations\' activity-log periods could not be read.');
        }
        $key = self::KEY;
        $stmt->bind_param('s', $key);
        if ($stmt->execute() === false) {
            throw new \RuntimeException('The organisations\' activity-log periods could not be read.');
        }
        $result = $stmt->get_result();
        if ($result === false) {
            throw new \RuntimeException('The organisations\' activity-log periods could not be read.');
        }
        $above = [];
        while ($row = $result->fetch_assoc()) {
            $own = self::usableOwn($row['settingValue'] === null ? null : (string) $row['settingValue']);
            if ($own !== null && ($own === self::NEVER || $own > $days)) {
                $above[(int) $row['siteID']] = ['name' => (string) $row['siteName'], 'days' => $own];
            }
        }
        $stmt->close();

        return $above;
    }

    /**
     * One settings row's text at one level (null = the installation's), or
     * null when there is no row. With `$lock`, read `FOR UPDATE`; the caller
     * must already be inside a transaction or the lock is let go at once (#697).
     */
    private static function storedValue(\mysqli $db, ?int $siteId, bool $lock): ?string
    {
        $stmt = $db->prepare(
            'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` '
            . ($siteId === null ? 'IS NULL' : '= ?')
            . ($lock === true ? ' FOR UPDATE' : '')
        );
        if ($stmt === false) {
            throw new \RuntimeException('The activity-log period could not be read.');
        }
        $key = self::KEY;
        if ($siteId === null) {
            $stmt->bind_param('s', $key);
        } else {
            $stmt->bind_param('si', $key, $siteId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null || $row['settingValue'] === null ? null : (string) $row['settingValue'];
    }

    /** Write the setting at one level (null = installation-wide). */
    private static function writeRow(\mysqli $db, ?int $siteId, string $value): void
    {
        $stmt = $db->prepare(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`, `updatedAt`) '
            . 'VALUES (?, ?, ?, ?, 0, NOW()) '
            . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), `updatedAt` = NOW()'
        );
        if ($stmt === false) {
            throw new \RuntimeException('The activity-log period could not be written.');
        }
        $key = self::KEY;
        // The installation's row keeps the built-in year as its default, as
        // migration 046 seeded it; an organisation's row has none.
        $default = $siteId === null ? (string) self::DEFAULT_DAYS : '';
        $stmt->bind_param('isss', $siteId, $key, $value, $default);
        $stmt->execute();
        $stmt->close();
    }

    /** Roll back, swallowing a second fault: nothing more can be done, and the connection closes at the end of the request. */
    private static function rollbackQuietly(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $ignored) {
            // Nothing more can be done.
        }
    }
}
