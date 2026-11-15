<?php
// Path: _core/RetentionPeriod.php
/**
 * -----------------------------------------------------------------------------
 * A keeping period of "a number of days, or never", set at two levels ⏳♾️
 * -----------------------------------------------------------------------------
 * The rules both `ActivityLogRetention` (#932: how long the activity log is
 * kept) and `NetworkAddressRetention` (#1019: how long a network address is
 * kept) follow, written ONCE. Each of those two classes names its own setting
 * (`KEY`), its built-in figure (`DEFAULT_DAYS`) and its own words (`words()`);
 * everything that DECIDES lives here, so the two cannot drift apart.
 *
 * WHY ONE CLASS AND NOT TWO COPIES. #1019 asked for a second setting with
 * exactly the activity log's rules (the owner's decision D-178, following
 * D-117 to D-119). The first draft of this note proposed copying the class;
 * a copy of an 840-line rule is two rules the day somebody fixes one of them
 * (#768's shape: five copies of the form-token writer, no two agreeing).
 *
 * THE RULES (the activity log's, D-117 to D-119; #668, D-013):
 *   - The installation chooses a number of days (1 to `HARD_MAX_DAYS`) or
 *     never (stored as 0). **0 is the LARGEST value.**
 *   - An organisation chooses a number of days, never, or nothing (it then
 *     follows the installation — never the built-in figure, #668's first trap).
 *   - An organisation may choose never ONLY where the installation has.
 *   - "Never" is its own choice, past the hard maximum, NEVER clamped to it.
 *   - A figure above what is allowed is REFUSED with the reason, never clamped.
 *   - The installation's figure is a global administrator's; an organisation's
 *     own is that organisation's administrator's (D-118).
 *   - Lowering the installation's figure below one an organisation has chosen
 *     is REFUSED, naming the organisations (D-119, D-032).
 *   - A save-time refusal is not a cap (#668), so `daysForSite()` caps too.
 *   - Both saves re-read the installation's row LIVE and LOCKED inside the
 *     transaction that writes (#687; a lock outside a transaction is
 *     decoration, #697).
 *
 * LATE STATIC BINDING. Every constant a subclass names is read as `static::`.
 * The methods call each other as `self::`, which in PHP FORWARDS the class
 * that was called, so `NetworkAddressRetention::saveForSite()` reads
 * `NetworkAddressRetention::KEY` all the way down. Reading a subclass
 * constant as `self::` here would be a fatal error (this class names none), so
 * a slip cannot quietly use the wrong setting.
 *
 * `lockedInGeneralEditor()` is deliberately declared in EACH subclass, not
 * here: `tools/settings-locks-selftest.php` finds lock methods by their
 * DECLARATION and presses every key each one locks, so a method declared once
 * on this abstract class would be found once, with no key to press.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1019
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/932
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

abstract class RetentionPeriod
{
    /**
     * The most a NUMBER of days may be: ten years, the same ceiling the other
     * retention periods use. "Never" is not a number of days and is not held
     * to this; it is its own choice (D-117).
     */
    public const HARD_MAX_DAYS = 3650;

    /** "Never", as stored. 0 is the LARGEST value (#668's trap). */
    public const NEVER = 0;

    /** The period is a number of days (`days` holds it). */
    public const MEMBER_DAYS = 'days';

    /** This organisation has chosen "never" for itself. */
    public const MEMBER_OWN_NEVER = 'own-never';

    /** The installation has chosen "never" and this organisation follows it. */
    public const MEMBER_INSTALLATION_NEVER = 'installation-never';

    /** The figure could not be read: the words then promise no figure at all. */
    public const MEMBER_UNKNOWN = 'unknown';

    /**
     * 🗣️ The subclass's own words. Each is a WHOLE sentence or phrase (#694:
     *    sentences assembled from branches are where the wording faults were);
     *    `%s` is a figure in words, `%d` a number of days.
     *
     * Keys: `never` (a "never" figure in words), `neverChoice` (the page's
     * name for that choice), `notAdmin`, `neverRefused` (%d),
     * `installationNever`, `installationDays` (%s), `aboveWhat`, `aboveRule`,
     * `siteCleared`, `siteNever`, `siteDays` (%s), `period` (what the setting
     * is, for a log line or a fault).
     *
     * @return array<string, string>
     */
    abstract protected static function words(): array;

    // #########################################################################
    // 📖 Reading
    // #########################################################################

    /**
     * 🏛️ The INSTALLATION's figure, from start-up's installation-wide layer:
     *    a number of days, or 0 for never.
     *
     * This is both the period for rows that belong to no organisation and the
     * ceiling every organisation is held to. Asked of `SettingLimit` with the
     * zero flag, because `installationAllows()` on its own answers the hard
     * maximum for a stored 0 — the right number to COMPARE against, and
     * exactly the clamp D-117 forbids if it were taken as the answer.
     *
     * When start-up has not handed the layer over, this answers the built-in
     * figure: the clear-out then acts, which is the direction it has always
     * taken, rather than inventing a "never" nobody chose.
     */
    public static function installationDays(): int
    {
        self::needSettingLimit();

        if (SettingLimit::installationAllowsForEver(static::KEY, static::DEFAULT_DAYS, self::HARD_MAX_DAYS) === true) {
            return self::NEVER;
        }

        return SettingLimit::installationAllows(static::KEY, static::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);
    }

    /**
     * 🏛️ The same reading, of a value the CALLER read (a locked row inside a
     *    save). Null — no row — is the built-in figure; unreadable text is too.
     */
    public static function installationDaysFromStored(mixed $stored): int
    {
        self::needSettingLimit();

        if (SettingLimit::allowsForEverFrom($stored, static::DEFAULT_DAYS, self::HARD_MAX_DAYS) === true) {
            return self::NEVER;
        }

        return SettingLimit::ceilingFrom($stored, static::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);
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
     * "nothing chosen" and follows the installation. A usable one goes through
     * `SettingLimit::cappedForSite()` with the zero flag, which is where "never
     * survives only where BOTH levels mean it" lives: own 0 under an
     * installation of 30 is 30; own 0 under an installation of never is never;
     * own 1000 under never is 1000; own 99999 under never is the hard maximum
     * (a NUMBER is still held to it — measured, not reasoned, under #932).
     */
    public static function daysForSite(int $siteId): int
    {
        self::needSettingLimit();

        $own  = App::settingForSite(static::KEY, $siteId);
        $text = $own === null ? '' : trim($own);
        if (preg_match('/^\d{1,18}$/', $text) !== 1) {
            return self::installationDays();
        }

        return SettingLimit::cappedForSite((int) $text, static::KEY, static::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);
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

    /** The page's name for the "never" choice: "Never delete", "Keep for ever". */
    public static function neverChoice(): string
    {
        return static::words()['neverChoice'];
    }

    /** A figure in words: "365 days", "1 day", or the subclass's word for never. */
    public static function inWords(int $days): string
    {
        if ($days === self::NEVER) {
            return static::words()['never'];
        }

        return $days . ' ' . ($days === 1 ? 'day' : 'days');
    }

    /**
     * 🔎 Which of the four things a member should be told about ONE
     *    organisation's period, as FACTS: `state` is one of the MEMBER_*
     *    constants and `days` the figure in force (0 for never, and 0 when it
     *    could not be read).
     *
     * The figure is daysForSite(), the SAME reader the nightly clear-out uses,
     * so a page and the clear-out cannot disagree (#647). Only when that answer
     * is "never" is the organisation's own row read, and only to say WHO chose
     * it. A failed read is its OWN answer, never a figure (#940).
     *
     * @return array{state: string, days: int}
     */
    public static function memberFactsForSite(\mysqli $db, int $siteId): array
    {
        try {
            $days = self::daysForSite($siteId);
            if ($days !== self::NEVER) {
                return ['state' => self::MEMBER_DAYS, 'days' => $days];
            }

            return [
                'state' => self::ownDays($db, $siteId) === self::NEVER ? self::MEMBER_OWN_NEVER : self::MEMBER_INSTALLATION_NEVER,
                'days'  => self::NEVER,
            ];
        } catch (\Throwable $e) {
            return ['state' => self::MEMBER_UNKNOWN, 'days' => self::NEVER];
        }
    }

    // #########################################################################
    // 🚫 The rule for an organisation
    // #########################################################################

    /**
     * 🚫 Why this figure may not be stored for an organisation under this
     *    installation figure — '' when it may.
     *
     * `SettingLimit::whyNotAllowedFor()` IS the rule, with the zero flag. It
     * takes the installation's value as a FACT, because a save asks it with
     * the row it has just read locked (#647's shape).
     *
     * @return string '', 'above-installation' or 'above-hard-max'.
     */
    public static function whyNotAllowedForSite(int $days, mixed $installationStored): string
    {
        self::needSettingLimit();

        return SettingLimit::whyNotAllowedFor($days, $installationStored, static::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);
    }

    /**
     * 💬 That refusal in words. A number of days goes through
     *    `SettingLimit::refusalWords()`, the one wording for a limit, which never
     *    says WHO set it (the two levels may be different parties). A refused
     *    never is worded by the subclass, in the page's own name for the choice.
     *
     * #940 round 4: what the ORGANISATION may choose depends on the
     * installation, so the never choice is named only where the installation
     * allows it, and the range given is the one really allowed.
     */
    public static function refusalWordsForSite(string $reason, mixed $installationStored, int $days): string
    {
        self::needSettingLimit();

        $allowed = SettingLimit::ceilingFrom($installationStored, static::DEFAULT_DAYS, self::HARD_MAX_DAYS, true);
        $forEver = SettingLimit::allowsForEverFrom($installationStored, static::DEFAULT_DAYS, self::HARD_MAX_DAYS);
        $never   = static::words()['neverChoice'];

        if ($reason === 'not-a-number') {
            return $forEver === true
                ? 'A number of days has to be a whole number from 1 to ' . self::HARD_MAX_DAYS
                    . '. For no time limit, choose "' . $never . '" instead. Nothing was saved.'
                : 'A number of days has to be a whole number from 1 to ' . $allowed . '. Nothing was saved.';
        }
        if ($reason === 'above-hard-max') {
            return $forEver === true
                ? 'A number of days can be at most ' . self::HARD_MAX_DAYS . ' (ten years). For no time limit, '
                    . 'choose "' . $never . '" instead. Nothing was saved.'
                : SettingLimit::refusalWords('above-installation', $allowed, 'days', false);
        }
        if ($reason === 'above-installation' && $days === self::NEVER) {
            return sprintf(static::words()['neverRefused'], $allowed);
        }

        return SettingLimit::refusalWords($reason, $allowed, 'days', false);
    }

    // #########################################################################
    // 💾 Saving
    // #########################################################################

    /**
     * 🏛️ Store the INSTALLATION's figure: 1 to HARD_MAX_DAYS, or 0 for never.
     *
     * A global administrator only (D-118), checked here as well as at the
     * page, because it binds every organisation at once.
     *
     * A FIGURE SHORTER THAN AN ORGANISATION'S OWN IS REFUSED (D-119), naming
     * the organisations — whether the installation's figure is going down OR
     * up, as the giving-records ceiling does (D-032). Choosing never can
     * shorten nobody and is never refused, so that is always a way out.
     * `blockedOrganisations` is a COUNT, for the activity log; the names are in
     * the message, which only the person who pressed Save reads.
     *
     * Judged ONLY when the figure CHANGES: a figure equal to the one in force
     * is not written and not judged.
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
        $words  = static::words();
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
                . 'For no time limit, choose "' . $words['neverChoice'] . '" instead. Nothing was saved.', 'above-hard-max');
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
            //
            //    KNOWN AND LEFT ALONE (#932): an organisation's own figure ABOVE
            //    the hard maximum (only SQL or a restore can store one) blocks
            //    lowering the installation from never to the maximum, although
            //    the reader already holds that organisation to it. Comparing
            //    against the figure AS STORED keeps this rule the same as the
            //    save rule's, and the way out is plain: lower that figure.
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
            error_log('[WebMS-Intra] ' . self::shortName() . ': the installation\'s ' . $words['period']
                . ' could not be saved (' . get_class($e) . ').');

            return $refuse('The change could not be saved: either the organisations\' own figures could not be read '
                . 'to check it, or the database refused it. Nothing was changed.', 'database');
        }

        return [
            'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'stored', 'blockedOrganisations' => 0,
            'message' => $days === self::NEVER
                ? $words['installationNever']
                : sprintf($words['installationDays'], self::inWords($days)),
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
        $words = static::words();
        $named = [];
        foreach ($above as $one) {
            $named[] = $one['name'] . ' (' . self::inWords($one['days']) . ')';
        }

        return 'This was not saved. ' . self::inWords($days) . ' is shorter than the period '
            . (count($above) === 1 ? 'this organisation has' : 'these organisations have')
            . ' chosen ' . $words['aboveWhat'] . ': ' . implode(', ', $named) . '. '
            . $words['aboveRule'] . ' '
            . 'Ask ' . (count($above) === 1 ? 'it' : 'them') . ' to choose a shorter period first, '
            . 'or choose a longer period here. Nothing was changed.';
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
     * the one already stored is not judged and not written (#687).
     *
     * @param \mysqli  $db                  An open connection, NOT already in a transaction.
     * @param int      $siteId              The organisation.
     * @param int|null $days                1 to HARD_MAX_DAYS, NEVER, or null to follow the installation.
     * @param bool     $adminOfOrganisation Whether the person saving administers THIS organisation (D-118);
     *                                      a global administrator counts.
     *
     * @return array{saved: bool, type: string, message: string, refusal: string, outcome: string}
     *         `outcome` is 'stored', 'cleared', 'unchanged' or 'refused'.
     */
    public static function saveForSite(\mysqli $db, int $siteId, ?int $days, bool $adminOfOrganisation): array
    {
        $words  = static::words();
        $refuse = static fn (string $message, string $reason): array => [
            'saved' => false, 'type' => 'danger', 'message' => $message, 'refusal' => $reason, 'outcome' => 'refused',
        ];
        if ($adminOfOrganisation === false) {
            return $refuse($words['notAdmin'], 'not-admin');
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
                    throw new \RuntimeException('The ' . $words['period'] . ' could not be removed.');
                }
                $key = static::KEY;
                $del->bind_param('si', $key, $siteId);
                $del->execute();
                $del->close();
            }
            $db->commit();
        } catch (\Throwable $e) {
            self::rollbackQuietly($db);
            error_log('[WebMS-Intra] ' . self::shortName() . ': the ' . $words['period'] . ' of organisation #'
                . $siteId . ' could not be saved (' . get_class($e) . ').');

            return $refuse('The change could not be saved because the database refused it. Nothing was changed.', 'database');
        }

        if ($days === null) {
            return [
                'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'cleared',
                'message' => $words['siteCleared'],
            ];
        }

        return [
            'saved' => true, 'type' => 'success', 'refusal' => '', 'outcome' => 'stored',
            'message' => $days === self::NEVER ? $words['siteNever'] : sprintf($words['siteDays'], self::inWords($days)),
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
    protected static function needSettingLimit(): void
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

    /** The subclass's short name, for a log line. */
    private static function shortName(): string
    {
        $parts = explode('\\', static::class);

        return (string) end($parts);
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
     * nothing follows the installation and can never be "above" it.
     *
     * READ LOCKED (`FOR UPDATE`), and called only inside saveInstallation()'s
     * transaction after the installation's row is locked. AND IT THROWS rather
     * than answer an empty list it cannot stand behind
     * (`GivingRetention::organisationsAboveCap()`'s rule): "could not read"
     * must never read as "nobody is above it".
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
            throw new \RuntimeException('The organisations\' own periods could not be read.');
        }
        $key = static::KEY;
        $stmt->bind_param('s', $key);
        if ($stmt->execute() === false) {
            throw new \RuntimeException('The organisations\' own periods could not be read.');
        }
        $result = $stmt->get_result();
        if ($result === false) {
            throw new \RuntimeException('The organisations\' own periods could not be read.');
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
            throw new \RuntimeException('The ' . static::words()['period'] . ' could not be read.');
        }
        $key = static::KEY;
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
            throw new \RuntimeException('The ' . static::words()['period'] . ' could not be written.');
        }
        $key = static::KEY;
        // The installation's row keeps the built-in figure as its default, as
        // its seed did; an organisation's row has none.
        $default = $siteId === null ? (string) static::DEFAULT_DAYS : '';
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
