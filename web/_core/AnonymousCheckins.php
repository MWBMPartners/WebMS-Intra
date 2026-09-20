<?php
// Path: _core/AnonymousCheckins.php
/**
 * -----------------------------------------------------------------------------
 * Anonymous check-ins — the only code that reads them 🚪
 * -----------------------------------------------------------------------------
 * A visitor can check in to an event without signing in: they scan a QR code,
 * or somebody presses a button on a kiosk at the door. Every one of those
 * presses has been written to `tblAnonymousCheckins` since September 2025.
 *
 * WHAT WAS WRONG BEFORE
 * ---------------------
 * Nothing read that table. Not one screen, not one report, not one download.
 * The counts went in and stayed in. An organisation could run a kiosk at the
 * door for a year and never see a single number from it. That is issue #525.
 *
 * This class is the one place that reads the table, so that:
 *   - the rule about who may see the figures is written once, not five times;
 *   - the organisation check is in the SQL, not in PHP afterwards;
 *   - nothing can accidentally put a browser description or a scrambled
 *     address on a screen, because no method here ever returns either.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * ----------------------------------------
 * It never reads `$_SESSION`, `$_GET`, `$_POST`, or the current organisation.
 * Every input arrives as a parameter. That is what lets the decision methods be
 * proved by `tools/anon-checkins-selftest.php` with no database at all, and it
 * is the shape #514's `EventVisibility` is expected to take.
 *
 * WHAT THE FIGURES CANNOT TELL YOU
 * --------------------------------
 * Read this before writing any wording on a screen. An overstated guarantee is
 * worse than none at all.
 *
 *   - "Probably unique senders" counts different internet connections, not
 *     different people. Two people sharing one connection are one sender. At a
 *     venue with its own wifi the figure can be badly low.
 *   - Somebody who comes back on another day is counted again on that day.
 *   - A check-in whose sender address was never recorded counts as its own
 *     sender. That errs towards over-counting, which is the safe direction for
 *     a figure with "probably" in its name.
 *   - After the detail behind a day has been cleared (see clearOldDetail), a
 *     check-in that arrives late for that same day is added on top, and
 *     somebody who came both before and after the clear-out is counted twice.
 *     That day is then MARKED as including late arrivals, so the figure is
 *     never quietly wrong — the owner asked for that on 18 September 2026,
 *     in preference to silently double counting.
 *   - Days come from the database server's clock. An event running past
 *     midnight is split across two days.
 *   - Nothing links a check-in to a member, and nothing here tries to. A person
 *     cannot ask for "their" check-ins to be deleted, because there is nothing
 *     to match them against. The time limit is the answer instead.
 *   - The stored per-day figure is exactly as good as the rows that existed
 *     when it was written. It is not a correction of anything, and it cannot be
 *     worked out again afterwards.
 *   - `App::isAdmin()` is true for the portal-wide legacy `isAdmin` flag, which
 *     a site administrator can set on any account. So "administrators only" is
 *     only as narrow as that flag is trustworthy. This class INHERITS that; it
 *     does not create it and does not fix it (#511, #518).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/525
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class AnonymousCheckins
{
    /**
     * The setting that decides who may see the figures.
     *
     * Named `attend.anonCounts.visibleTo` rather than something shorter so it
     * does NOT have to be renamed when #526 adds venue and event levels to the
     * same idea.
     */
    public const VISIBILITY_KEY = 'attend.anonCounts.visibleTo';

    /** How long the browser description and scrambled address are kept. */
    public const RETENTION_KEY = 'attend.detailRetentionDays';

    /** The narrowest choice, and the one anything unrecognised falls back to. */
    public const VISIBLE_ADMINS = 'admins';

    /** Administrators, plus the coordinators of the event being looked at. */
    public const VISIBLE_ADMINS_COORDINATORS = 'admins_coordinators';

    /** Whoever the page's own sign-in rule already lets in. */
    public const VISIBLE_PAGE = 'page';

    /**
     * The three choices, in the order they are offered on the settings screen.
     *
     * THERE USED TO BE FOUR. A fourth choice, "event coordinators only", was
     * planned and then dropped by the owner on 18 September 2026, because
     * administrators are now always included whatever the setting says — which
     * would have made "coordinators only" behave exactly like "administrators
     * and coordinators". A choice that changes nothing is worse than no choice
     * at all, because somebody will pick it believing it does something.
     *
     * @var array<string, string> Stored value => the label a person reads.
     */
    public const VISIBILITY_CHOICES = [
        self::VISIBLE_ADMINS              => 'Administrators only',
        self::VISIBLE_ADMINS_COORDINATORS => 'Administrators and the event\'s coordinators',
        self::VISIBLE_PAGE                => 'Anyone who can already open the page',
    ];

    /**
     * The column headings of the anonymous check-in spreadsheet, in order.
     *
     * Written down here, and handed to the exporter explicitly, because the
     * shared exporter otherwise takes its headings from the FIRST ROW it is
     * given — so a period with no check-ins in it would produce a file with no
     * headings at all, which looks like a broken download rather than an empty
     * one.
     *
     * The last column is the owner's decision of 18 September 2026: a figure
     * that may count somebody twice says so in the file as well as on screen.
     * A download that quietly dropped the warning would be the one copy of the
     * number that looked exact.
     *
     * @var array<int, string>
     */
    public const CSV_HEADINGS = [
        'Event',
        'Date',
        'Check-ins',
        'People claimed',
        'Probably unique senders',
        'Self',
        'Kiosk',
        'QR code',
        'Includes late arrivals',
    ];

    /**
     * How many days of detail are kept when no setting says otherwise.
     *
     * Zero, or any negative number, means keep for ever. Negative is treated
     * the same as zero on purpose: for a clear-out, refusing to act on a number
     * nobody meant to type is the safer direction.
     */
    public const DEFAULT_RETENTION_DAYS = 90;

    // -------------------------------------------------------------------------
    // 🧭 Who may see the figures
    // -------------------------------------------------------------------------

    /**
     * Turn whatever is stored into one of the three choices.
     *
     * Anything unrecognised — a typo, an empty value, a missing row, a value
     * left behind by an older version that had four choices — comes back as
     * `admins`, the narrowest. Failing closed matters here: the alternative is
     * a mistyped setting quietly showing figures to more people than intended.
     *
     * @param string|null $raw Exactly what was stored, or null if nothing was.
     *
     * @return string One of the keys of VISIBILITY_CHOICES.
     */
    public static function visibilityChoice(?string $raw): string
    {
        if ($raw === null) {
            return self::VISIBLE_ADMINS;
        }

        $trimmed = trim($raw);
        if (array_key_exists($trimmed, self::VISIBILITY_CHOICES) === true) {
            return $trimmed;
        }

        return self::VISIBLE_ADMINS;
    }

    /**
     * Read the choice for one organisation.
     *
     * 📌 THIS IS THE ONE PLACE #526 REPLACES. Issue #526 makes this setting
     *    (and the check-in rate limit beside it) settable at four levels —
     *    installation, organisation, venue and event — with the most specific
     *    one winning. When that lands, this method grows the extra lookups and
     *    every screen follows, because every screen reads the choice through
     *    here and nowhere else. Do not add a second reader.
     *
     * Today there are two levels only: this organisation's own row if it has
     * one, otherwise the installation-wide row. That is exactly what
     * `App::settingForSite()` already does.
     *
     * It is split from `visibilityChoice()` above purely so the decision can be
     * proved by the self-test without a database.
     *
     * @param int $siteId The organisation to read the setting for.
     *
     * @return string One of the keys of VISIBILITY_CHOICES.
     */
    public static function readVisibilityChoice(int $siteId): string
    {
        return self::visibilityChoice(App::settingForSite(self::VISIBILITY_KEY, $siteId));
    }

    /**
     * How many days of detail this organisation keeps.
     *
     * Same two-level lookup as the visibility choice, and the same note
     * applies: #526 is expected to widen it, here and nowhere else.
     *
     * @param int $siteId The organisation to read the setting for.
     *
     * @return int Days to keep. 0 means keep for ever.
     */
    public static function readRetentionDays(int $siteId): int
    {
        $raw = App::settingForSite(self::RETENTION_KEY, $siteId);
        if ($raw === null || trim($raw) === '') {
            return self::DEFAULT_RETENTION_DAYS;
        }

        $days = (int) $raw;

        // A negative number is not "keep for ever backwards"; it is a typing
        // mistake. Treated as 0 (keep for ever) because for a clear-out the
        // safe direction is to do nothing, not to clear more than intended.
        // NOTE the deliberate difference from the event-registration sweep in
        // _apps/cron/_retention-sweep.php, which turns a negative back into its
        // ordinary 90-day default. There, keeping a child's medical notes for
        // ever is the dangerous outcome, so erring towards clearing is right.
        // Here nothing sensitive is being kept, so erring towards not touching
        // anything is right. The two differ on purpose.
        if ($days < 0) {
            return 0;
        }

        return $days;
    }

    /**
     * The whole decision about whether one person may see the figures.
     *
     * TWO RULES HOLD EVERYWHERE, AND BOTH ARE WRITTEN HERE ONCE:
     *
     * 1. The setting can only ever NARROW. Every screen keeps its own existing
     *    sign-in rule exactly as it was; this runs AFTER it, never instead of
     *    it. That is what the first line below enforces. So no choice can show
     *    the figures to somebody who could not already open that page.
     *
     * 2. Administrators are ALWAYS included, whatever the setting says. The
     *    owner decided that on 18 September 2026. It is why the second line
     *    below is an unconditional yes and why there is no "coordinators only"
     *    choice.
     *
     * On a screen that covers the whole organisation there is no event, so
     * "the event's coordinators" cannot be tested at all. `admins_coordinators`
     * therefore means administrators only on such a screen. That is not an
     * oversight: showing a coordinator an organisation-wide total that includes
     * events they have nothing to do with is exactly what this issue set out to
     * avoid.
     *
     * @param string $choice                   One of the keys of VISIBILITY_CHOICES.
     * @param bool   $passesPageGate           Did the page's own rule let this person in?
     * @param bool   $isAdmin                  Is this person an administrator (App::isAdmin())?
     * @param bool   $isCoordinatorOfThisEvent Auth::isCoordinatorOf() for the event in question.
     *                                         Pass false on a screen with no single event.
     * @param bool   $isEventScoped            Is this screen about ONE event?
     *
     * @return bool True if the figures may be shown.
     */
    public static function mayView(
        string $choice,
        bool $passesPageGate,
        bool $isAdmin,
        bool $isCoordinatorOfThisEvent,
        bool $isEventScoped
    ): bool {
        // Rule 1 — the setting may narrow, never widen.
        if ($passesPageGate === false) {
            return false;
        }

        // Rule 2 — administrators always see the figures (owner, 18 Sept 2026).
        if ($isAdmin === true) {
            return true;
        }

        $normalised = self::visibilityChoice($choice);

        if ($normalised === self::VISIBLE_PAGE) {
            // The page's own rule already said yes, and that is the whole test.
            return true;
        }

        if ($normalised === self::VISIBLE_ADMINS_COORDINATORS) {
            return ($isEventScoped === true && $isCoordinatorOfThisEvent === true);
        }

        // self::VISIBLE_ADMINS, and anything unrecognised.
        return false;
    }

    // -------------------------------------------------------------------------
    // 🔢 The "probably unique senders" arithmetic
    // -------------------------------------------------------------------------

    /**
     * How many different senders one event saw on one day.
     *
     * The scrambled address on a check-in row already has the event number
     * mixed into it, so two rows for the SAME event and day with the same
     * scramble came from the same internet connection — and two different
     * events' scrambles can never be matched to each other, which is good for
     * privacy and means no cross-event comparison is possible here.
     *
     * Once a day's detail has been cleared, every row of that day has an empty
     * scramble, so counting live rows alone would suddenly jump to one sender
     * per row. That is exactly the drift this is written to avoid: the figure
     * is worked out and stored BEFORE the detail goes, and read back afterwards.
     *
     * `rowsCounted` on the stored row is what lets a reader tell a row whose
     * detail was CLEARED from a row that arrived afterwards. It counts every row
     * whose detail was cleared at that moment — including rows that had only a
     * browser description and never had a scramble at all, which is why the
     * stored figure already counts each of those as its own sender. Rows of that
     * day with an empty scramble BEYOND that number therefore have to be rows
     * that arrived later, and each counts as its own sender on top.
     *
     * AN EARLIER VERSION COUNTED ONLY THE ROWS THAT HAD A SCRAMBLE, and it was
     * wrong in a way that was easy to miss. A row that never had a scramble was
     * then left out of `rowsCounted`, so after the clear-out it looked exactly
     * like a row that had turned up late — and the day was marked "includes late
     * arrivals" when nothing of the sort had happened. The total was right; only
     * the warning was wrong. Two database proofs caught it.
     *
     * This is a separate method for one reason only: so the self-test can prove
     * the arithmetic, including the clamp at zero, without a database.
     *
     * @param int|null $storedSenders     uniqueSenders on the stored row, or null if none.
     * @param int|null $storedRowsCounted rowsCounted on the stored row, or null if none.
     * @param int      $liveDistinct      Different scrambles still present for that day.
     * @param int      $nullRows          Rows of that day with no scramble at all.
     *
     * @return int The senders figure. Never negative.
     */
    public static function sendersForDay(
        ?int $storedSenders,
        ?int $storedRowsCounted,
        int $liveDistinct,
        int $nullRows
    ): int {
        if ($storedSenders === null) {
            // Nothing stored: every scramble is one sender, and every row
            // without one is its own sender.
            return max(0, $liveDistinct) + max(0, $nullRows);
        }

        // max(0, …) because a hand-edited or corrupted stored row must not be
        // able to drag the figure below zero. It clamps; it does not correct.
        $uncovered = max(0, $nullRows - (int) $storedRowsCounted);

        return max(0, $storedSenders) + max(0, $liveDistinct) + $uncovered;
    }

    /**
     * Did this day gain check-ins AFTER its figure was stored?
     *
     * The owner decided on 18 September 2026 that such a day is marked as
     * approximate rather than silently double counted. The alternative
     * considered and rejected was to freeze the stored figure and drop late
     * arrivals entirely, which loses people; and the one before that was to add
     * them on top and say nothing, which is what this replaces.
     *
     * There are two moments to catch, and a single stored flag only catches one
     * of them:
     *
     *   - A late check-in has arrived but the clear-out has not run again yet.
     *     Its detail is still there, so the day has BOTH a stored figure and
     *     live rows. Nothing has been written down yet, but the figure is
     *     already a sum of two batches.
     *   - The clear-out has since run again and folded those late arrivals into
     *     the stored figure. Now nothing live is left to notice, which is why
     *     `hasLateArrivals` is written on the stored row at that moment.
     *
     * Both are checked below, so the marker appears from the moment it becomes
     * true and never disappears afterwards.
     *
     * WHAT THIS CANNOT DO: it cannot say HOW approximate the day is, or whether
     * anybody was actually counted twice. It only says that it is possible.
     *
     * @param bool     $hasStoredRow      Is there a stored figure for this day?
     * @param bool     $storedLateFlag    hasLateArrivals on the stored row.
     * @param int|null $storedRowsCounted rowsCounted on the stored row, or null if none.
     * @param int      $liveDistinct      Different scrambles still present for that day.
     * @param int      $nullRows          Rows of that day with no scramble at all.
     *
     * @return bool True if the day's figure may include somebody counted twice.
     */
    public static function lateArrivalsOnDay(
        bool $hasStoredRow,
        bool $storedLateFlag,
        ?int $storedRowsCounted,
        int $liveDistinct,
        int $nullRows
    ): bool {
        if ($hasStoredRow === false) {
            // Nothing was ever stored for this day, so nothing can have been
            // added on top of a stored figure. The figure is worked out live
            // and is as good as it will ever be.
            return false;
        }

        if ($storedLateFlag === true) {
            return true;
        }

        $uncovered = max(0, $nullRows - (int) $storedRowsCounted);

        return ($liveDistinct > 0 || $uncovered > 0);
    }

    // -------------------------------------------------------------------------
    // 📊 Reading the figures
    // -------------------------------------------------------------------------

    /**
     * Everything one event's anonymous check-ins add up to.
     *
     * The organisation check lives in the SQL — `INNER JOIN tblEvents … AND
     * e.siteID = ?` — and never in PHP afterwards. An event belonging to
     * another organisation therefore returns exactly what an event with no
     * check-ins returns: zeros and an empty day list. The two cannot be told
     * apart, which is the rule #503 set. Every caller has already refused a
     * foreign event before reaching here; this is a second belt, not the first.
     *
     * The primary `FROM tblAnonymousCheckins` table is deliberately NOT given a
     * short alias. `tools/audit-checks/check_sql_columns.py` has a known blind
     * spot with an alias immediately after a table name whose own name embeds a
     * SQL word, and the documented workaround is to qualify with the full table
     * name instead (see web/_core/SmallGroups.php). Tables brought in by JOIN
     * are invisible to that pattern and are aliased freely.
     *
     * Days are handled as text (`YYYY-MM-DD`) from beginning to end. They are
     * never turned into a timestamp and stepped forward a day at a time — doing
     * that repeats or skips a day on the two days a year the clocks change,
     * which is the fault recorded as #528 on the named attendance grid.
     *
     * @param mysqli $db      An open connection.
     * @param int    $eventId The event.
     * @param int    $siteId  The organisation the caller is acting for.
     *
     * @return array{checkins:int,people:int,groups:int,senders:int,
     *               sendersIncludeLateArrivals:bool,
     *               bySource:array{self:int,kiosk:int,qr:int},
     *               byDay:array<int, array{day:string,checkins:int,people:int,
     *                                      senders:int,sendersAreStored:bool,
     *                                      includesLateArrivals:bool}>}
     */
    public static function summaryForEvent(mysqli $db, int $eventId, int $siteId): array
    {
        $empty = [
            'checkins'                   => 0,
            'people'                     => 0,
            'groups'                     => 0,
            'senders'                    => 0,
            'sendersIncludeLateArrivals' => false,
            'bySource'                   => ['self' => 0, 'kiosk' => 0, 'qr' => 0],
            'byDay'                      => [],
        ];

        if ($eventId <= 0 || $siteId <= 0) {
            return $empty;
        }

        // 📅 One row per calendar day, with everything that day needs.
        //    Two bound values, in this order: the organisation (the ? in the
        //    JOIN, which comes first in the text), then the event.
        $stmt = $db->prepare(
            'SELECT DATE(tblAnonymousCheckins.checkedInAt) AS dayText, '
            . 'COUNT(*) AS checkins, '
            . 'COALESCE(SUM(tblAnonymousCheckins.headcount), 0) AS people, '
            . 'SUM(CASE WHEN tblAnonymousCheckins.headcount > 1 THEN 1 ELSE 0 END) AS groupCheckins, '
            . 'COUNT(DISTINCT tblAnonymousCheckins.ipHash) AS liveDistinct, '
            . 'SUM(CASE WHEN tblAnonymousCheckins.ipHash IS NULL THEN 1 ELSE 0 END) AS nullRows '
            . 'FROM tblAnonymousCheckins '
            . 'INNER JOIN tblEvents e ON e.eventID = tblAnonymousCheckins.eventID AND e.siteID = ? '
            . 'WHERE tblAnonymousCheckins.eventID = ? '
            . 'GROUP BY DATE(tblAnonymousCheckins.checkedInAt) '
            . 'ORDER BY dayText ASC'
        );
        if ($stmt === false) {
            return $empty;
        }
        $stmt->bind_param('ii', $siteId, $eventId);
        $stmt->execute();
        $result = $stmt->get_result();

        $dayRows = [];
        while ($row = $result->fetch_assoc()) {
            $dayRows[(string) $row['dayText']] = $row;
        }
        $stmt->close();

        if (count($dayRows) === 0) {
            return $empty;
        }

        $stored = self::storedDaysForEvent($db, $eventId, $siteId);

        $out = $empty;
        foreach ($dayRows as $day => $row) {
            $liveDistinct = (int) $row['liveDistinct'];
            $nullRows     = (int) $row['nullRows'];

            $storedRow         = $stored[$day] ?? null;
            $storedSenders     = $storedRow !== null ? (int) $storedRow['uniqueSenders'] : null;
            $storedRowsCounted = $storedRow !== null ? (int) $storedRow['rowsCounted'] : null;
            $storedLateFlag    = $storedRow !== null ? ((int) $storedRow['hasLateArrivals'] === 1) : false;

            $senders = self::sendersForDay($storedSenders, $storedRowsCounted, $liveDistinct, $nullRows);
            $late    = self::lateArrivalsOnDay(
                $storedRow !== null,
                $storedLateFlag,
                $storedRowsCounted,
                $liveDistinct,
                $nullRows
            );

            $out['checkins'] += (int) $row['checkins'];
            $out['people']   += (int) $row['people'];
            $out['groups']   += (int) $row['groupCheckins'];
            $out['senders']  += $senders;
            if ($late === true) {
                $out['sendersIncludeLateArrivals'] = true;
            }

            $out['byDay'][] = [
                'day'                  => $day,
                'checkins'             => (int) $row['checkins'],
                'people'               => (int) $row['people'],
                'senders'              => $senders,
                'sendersAreStored'     => $storedRow !== null,
                'includesLateArrivals' => $late,
            ];
        }

        $out['bySource'] = self::sourceSplitForEvent($db, $eventId, $siteId);

        return $out;
    }

    /**
     * The stored per-day figures for one event, keyed by `YYYY-MM-DD`.
     *
     * Organisation-scoped through the same join as everything else, so a
     * foreign event returns an empty list rather than anybody else's numbers.
     *
     * A stored day always still has its check-in rows: clearing empties two
     * columns and never deletes a row, and the only thing that deletes those
     * rows — deleting the event itself — removes the stored row with them
     * (both tables cascade from `tblEvents`). So there is no need to invent a
     * day here that the live query did not find.
     *
     * @param mysqli $db      An open connection.
     * @param int    $eventId The event.
     * @param int    $siteId  The organisation the caller is acting for.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function storedDaysForEvent(mysqli $db, int $eventId, int $siteId): array
    {
        $out = [];

        $stmt = $db->prepare(
            'SELECT DATE(tblAnonymousCheckinDays.checkinDay) AS dayText, '
            . 'tblAnonymousCheckinDays.uniqueSenders, '
            . 'tblAnonymousCheckinDays.rowsCounted, '
            . 'tblAnonymousCheckinDays.hasLateArrivals '
            . 'FROM tblAnonymousCheckinDays '
            . 'INNER JOIN tblEvents e ON e.eventID = tblAnonymousCheckinDays.eventID AND e.siteID = ? '
            . 'WHERE tblAnonymousCheckinDays.eventID = ?'
        );
        if ($stmt === false) {
            return $out;
        }
        $stmt->bind_param('ii', $siteId, $eventId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $out[(string) $row['dayText']] = $row;
        }
        $stmt->close();

        return $out;
    }

    /**
     * How the check-ins arrived, for one event.
     *
     * All three keys are always present, with 0 where there were none, so no
     * screen has to guard against a missing key.
     *
     * @param mysqli $db      An open connection.
     * @param int    $eventId The event.
     * @param int    $siteId  The organisation the caller is acting for.
     *
     * @return array{self:int,kiosk:int,qr:int}
     */
    private static function sourceSplitForEvent(mysqli $db, int $eventId, int $siteId): array
    {
        $out = ['self' => 0, 'kiosk' => 0, 'qr' => 0];

        $stmt = $db->prepare(
            'SELECT tblAnonymousCheckins.source AS src, COUNT(*) AS n '
            . 'FROM tblAnonymousCheckins '
            . 'INNER JOIN tblEvents e ON e.eventID = tblAnonymousCheckins.eventID AND e.siteID = ? '
            . 'WHERE tblAnonymousCheckins.eventID = ? '
            . 'GROUP BY tblAnonymousCheckins.source'
        );
        if ($stmt === false) {
            return $out;
        }
        $stmt->bind_param('ii', $siteId, $eventId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $src = (string) $row['src'];
            if (array_key_exists($src, $out) === true) {
                $out[$src] = (int) $row['n'];
            }
        }
        $stmt->close();

        return $out;
    }

    /**
     * The same four totals for a whole organisation over a year or a month.
     *
     * NO EVENT NAMES AND NO PER-EVENT ROWS, deliberately. This feeds the
     * attendance report, which under the widest setting any signed-in user on
     * this installation can open, not only members of the organisation (a
     * pre-existing gap, tracked as #529). An organisation-wide total tells
     * nobody which events took place; a list of event names would. Internal
     * events collect anonymous check-ins too, so that difference matters.
     *
     * Senders have to be worked out per event and per day, because the
     * scrambled address has the event number mixed into it — the same scramble
     * from two different events is two different values, and the same person at
     * two events cannot be recognised. Grouping any wider would give a figure
     * that means nothing. So the query groups by event and day, and the totals
     * are added up here.
     *
     * NOTE FOR WHOEVER WRITES THE SCREEN: these are counted by the day a
     * check-in ARRIVED. The named attendance figures above them on that page
     * are counted by the DATE OF AN ATTENDANCE SESSION. They are not two views
     * of one thing and must never be subtracted from one another.
     *
     * @param mysqli $db     An open connection.
     * @param int    $siteId The organisation.
     * @param int    $year   Four-digit year.
     * @param int    $month  1-12, or 0 for the whole year.
     *
     * @return array{checkins:int,people:int,groups:int,senders:int,
     *               sendersIncludeLateArrivals:bool,
     *               bySource:array{self:int,kiosk:int,qr:int},
     *               byMonth:array<int, array{month:int,checkins:int,people:int,senders:int}>}
     */
    public static function summaryForSite(mysqli $db, int $siteId, int $year, int $month = 0): array
    {
        $out = [
            'checkins'                   => 0,
            'people'                     => 0,
            'groups'                     => 0,
            'senders'                    => 0,
            'sendersIncludeLateArrivals' => false,
            'bySource'                   => ['self' => 0, 'kiosk' => 0, 'qr' => 0],
            'byMonth'                    => [],
        ];

        if ($siteId <= 0 || $year <= 0) {
            return $out;
        }

        foreach (self::eventDayRows($db, $siteId, $year, $month, false) as $row) {
            $senders = (int) $row['senders'];
            $monthNo = (int) substr((string) $row['dayText'], 5, 2);

            $out['checkins'] += (int) $row['checkins'];
            $out['people']   += (int) $row['people'];
            $out['groups']   += (int) $row['groupCheckins'];
            $out['senders']  += $senders;
            $out['bySource']['self']  += (int) $row['selfCount'];
            $out['bySource']['kiosk'] += (int) $row['kioskCount'];
            $out['bySource']['qr']    += (int) $row['qrCount'];
            if ($row['includesLateArrivals'] === true) {
                $out['sendersIncludeLateArrivals'] = true;
            }

            if (isset($out['byMonth'][$monthNo]) === false) {
                $out['byMonth'][$monthNo] = [
                    'month'    => $monthNo,
                    'checkins' => 0,
                    'people'   => 0,
                    'senders'  => 0,
                ];
            }
            $out['byMonth'][$monthNo]['checkins'] += (int) $row['checkins'];
            $out['byMonth'][$monthNo]['people']   += (int) $row['people'];
            $out['byMonth'][$monthNo]['senders']  += $senders;
        }

        ksort($out['byMonth']);

        return $out;
    }

    /**
     * One row per event per day, ready for a CSV download.
     *
     * ADMINISTRATORS ONLY, whatever the visibility setting says, and the
     * calling page is what enforces that. The reason is not that the figures
     * are more secret in a file — they are the same figures — but that a file
     * behaves differently from a screen. It leaves the building, it gets
     * forwarded, and it is still sitting in somebody's downloads folder after
     * the setting has been tightened again. These rows carry EVENT NAMES,
     * including internal events, which can collect anonymous check-ins from
     * signed-in members. The owner confirmed this on 18 September 2026.
     *
     * The last column is there because of the same owner decision: a figure
     * that may include somebody counted twice says so, on screen AND in the
     * file. A download that quietly dropped the warning would be the one copy
     * of the number that looked exact.
     *
     * @param mysqli $db     An open connection.
     * @param int    $siteId The organisation.
     * @param int    $year   Four-digit year.
     * @param int    $month  1-12, or 0 for the whole year.
     *
     * @return array<int, array<string, string|int>> Rows with human-readable keys.
     */
    public static function rowsForCsv(mysqli $db, int $siteId, int $year, int $month = 0): array
    {
        $rows = [];

        foreach (self::eventDayRows($db, $siteId, $year, $month, true) as $row) {
            $rows[] = [
                'Event'                   => (string) $row['eventName'],
                'Date'                    => (string) $row['dayText'],
                'Check-ins'               => (int) $row['checkins'],
                'People claimed'          => (int) $row['people'],
                'Probably unique senders' => (int) $row['senders'],
                'Self'                    => (int) $row['selfCount'],
                'Kiosk'                   => (int) $row['kioskCount'],
                'QR code'                 => (int) $row['qrCount'],
                'Includes late arrivals'  => $row['includesLateArrivals'] === true ? 'Yes' : 'No',
            ];
        }

        return $rows;
    }

    /**
     * The shared query behind `summaryForSite()` and `rowsForCsv()`.
     *
     * One row per event per day, with the senders figure already worked out
     * against any stored figure for that same event and day. Written once so
     * the screen and the download can never disagree about a number.
     *
     * @param mysqli $db        An open connection.
     * @param int    $siteId    The organisation.
     * @param int    $year      Four-digit year.
     * @param int    $month     1-12, or 0 for the whole year.
     * @param bool   $withNames Include the event's name (never for a screen a
     *                          plain member can reach — see summaryForSite).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function eventDayRows(
        mysqli $db,
        int $siteId,
        int $year,
        int $month,
        bool $withNames
    ): array {
        $out = [];
        if ($siteId <= 0 || $year <= 0) {
            return $out;
        }

        $nameColumn = $withNames === true ? 'e.eventName AS eventName, ' : '';

        $sql = 'SELECT tblAnonymousCheckins.eventID AS ev, '
             . $nameColumn
             . 'DATE(tblAnonymousCheckins.checkedInAt) AS dayText, '
             . 'COUNT(*) AS checkins, '
             . 'COALESCE(SUM(tblAnonymousCheckins.headcount), 0) AS people, '
             . 'SUM(CASE WHEN tblAnonymousCheckins.headcount > 1 THEN 1 ELSE 0 END) AS groupCheckins, '
             . 'SUM(CASE WHEN tblAnonymousCheckins.source = \'self\'  THEN 1 ELSE 0 END) AS selfCount, '
             . 'SUM(CASE WHEN tblAnonymousCheckins.source = \'kiosk\' THEN 1 ELSE 0 END) AS kioskCount, '
             . 'SUM(CASE WHEN tblAnonymousCheckins.source = \'qr\'    THEN 1 ELSE 0 END) AS qrCount, '
             . 'COUNT(DISTINCT tblAnonymousCheckins.ipHash) AS liveDistinct, '
             . 'SUM(CASE WHEN tblAnonymousCheckins.ipHash IS NULL THEN 1 ELSE 0 END) AS nullRows '
             . 'FROM tblAnonymousCheckins '
             . 'INNER JOIN tblEvents e ON e.eventID = tblAnonymousCheckins.eventID AND e.siteID = ? '
             . 'WHERE YEAR(tblAnonymousCheckins.checkedInAt) = ?';

        // Three bound values when a month is chosen, two otherwise. The ? in the
        // JOIN comes first in the text, so the organisation binds first.
        if ($month >= 1 && $month <= 12) {
            $sql .= ' AND MONTH(tblAnonymousCheckins.checkedInAt) = ?';
        }
        $sql .= ' GROUP BY tblAnonymousCheckins.eventID, DATE(tblAnonymousCheckins.checkedInAt) '
              . 'ORDER BY dayText ASC, ev ASC';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return $out;
        }
        if ($month >= 1 && $month <= 12) {
            $stmt->bind_param('iii', $siteId, $year, $month);
        } else {
            $stmt->bind_param('ii', $siteId, $year);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $raw = [];
        while ($row = $result->fetch_assoc()) {
            $raw[] = $row;
        }
        $stmt->close();

        if (count($raw) === 0) {
            return $out;
        }

        $stored = self::storedDaysForSite($db, $siteId, $year, $month);

        foreach ($raw as $row) {
            $key = (string) $row['ev'] . '|' . (string) $row['dayText'];

            $storedRow         = $stored[$key] ?? null;
            $storedSenders     = $storedRow !== null ? (int) $storedRow['uniqueSenders'] : null;
            $storedRowsCounted = $storedRow !== null ? (int) $storedRow['rowsCounted'] : null;
            $storedLateFlag    = $storedRow !== null ? ((int) $storedRow['hasLateArrivals'] === 1) : false;

            $row['senders'] = self::sendersForDay(
                $storedSenders,
                $storedRowsCounted,
                (int) $row['liveDistinct'],
                (int) $row['nullRows']
            );
            $row['includesLateArrivals'] = self::lateArrivalsOnDay(
                $storedRow !== null,
                $storedLateFlag,
                $storedRowsCounted,
                (int) $row['liveDistinct'],
                (int) $row['nullRows']
            );

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Stored per-day figures for a whole organisation, keyed `eventID|day`.
     *
     * @param mysqli $db     An open connection.
     * @param int    $siteId The organisation.
     * @param int    $year   Four-digit year.
     * @param int    $month  1-12, or 0 for the whole year.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function storedDaysForSite(mysqli $db, int $siteId, int $year, int $month): array
    {
        $out = [];

        $sql = 'SELECT tblAnonymousCheckinDays.eventID AS ev, '
             . 'DATE(tblAnonymousCheckinDays.checkinDay) AS dayText, '
             . 'tblAnonymousCheckinDays.uniqueSenders, '
             . 'tblAnonymousCheckinDays.rowsCounted, '
             . 'tblAnonymousCheckinDays.hasLateArrivals '
             . 'FROM tblAnonymousCheckinDays '
             . 'INNER JOIN tblEvents e ON e.eventID = tblAnonymousCheckinDays.eventID AND e.siteID = ? '
             . 'WHERE YEAR(tblAnonymousCheckinDays.checkinDay) = ?';

        if ($month >= 1 && $month <= 12) {
            $sql .= ' AND MONTH(tblAnonymousCheckinDays.checkinDay) = ?';
        }

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return $out;
        }
        if ($month >= 1 && $month <= 12) {
            $stmt->bind_param('iii', $siteId, $year, $month);
        } else {
            $stmt->bind_param('ii', $siteId, $year);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $out[(string) $row['ev'] . '|' . (string) $row['dayText']] = $row;
        }
        $stmt->close();

        return $out;
    }

    // -------------------------------------------------------------------------
    // 🧹 Clearing the personal detail on a timer
    // -------------------------------------------------------------------------

    /**
     * How many rows of this organisation's would be cleared right now.
     *
     * Used by the maintenance page's preview so an administrator sees a real
     * number before pressing anything. It is a snapshot, not a promise: more
     * rows become eligible as time passes.
     *
     * @param mysqli $db     An open connection.
     * @param int    $siteId The organisation.
     * @param int    $days   Days of detail to keep. 0 or less means keep for ever.
     *
     * @return int Rows that still hold a browser description or a scramble.
     */
    public static function countDetailToClear(mysqli $db, int $siteId, int $days): int
    {
        if ($days < 1 || $siteId <= 0) {
            return 0;
        }

        $cutoff = self::cutoffDate($db, $days);
        if ($cutoff === null) {
            return 0;
        }

        $stmt = $db->prepare(
            'SELECT COUNT(*) AS cnt '
            . 'FROM tblAnonymousCheckins '
            . 'INNER JOIN tblEvents e ON e.eventID = tblAnonymousCheckins.eventID AND e.siteID = ? '
            . 'WHERE DATE(tblAnonymousCheckins.checkedInAt) < ? '
            . 'AND (tblAnonymousCheckins.userAgent IS NOT NULL OR tblAnonymousCheckins.ipHash IS NOT NULL)'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('is', $siteId, $cutoff);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Store each old day's senders figure, then empty the personal detail.
     *
     * WHY THE FIGURE IS STORED FIRST
     * The senders figure is worked out from the scrambled addresses. Empty
     * those and the figure silently changes — every row becomes its own
     * sender — so an event's number would drift upwards the moment its detail
     * aged out. Writing it down first is the only way the number stays the
     * same. That is the whole reason `tblAnonymousCheckinDays` exists.
     *
     * WHY WHOLE CALENDAR DAYS, NOT "OLDER THAN N DAYS"
     * An earlier draft compared the timestamp: `checkedInAt < NOW() - N days`.
     * Rows on the same calendar day have different times, so that would clear
     * PART of a day and leave the rest — and the figure stored for that day
     * would be a figure for half a day, permanently, with no way to tell.
     * The comparison is on the DATE, so every row of a day goes together.
     *
     * WHY THE CUT-OFF IS WORKED OUT ONCE
     * If the snapshot used NOW() and the clear-out used NOW() a moment later, a
     * sweep running across midnight would snapshot one set of days and clear a
     * different set. One value, worked out up front, bound to both statements.
     *
     * WHY ONE TRANSACTION
     * Store without clearing and the next run adds the same figures again.
     * Clear without storing and the figure is gone for ever. Either half alone
     * is worse than neither, so both happen or neither does.
     *
     * WHAT IS NEVER TOUCHED: the counts, the headcounts, how the check-in
     * arrived, and when. Only the browser description and the scrambled address
     * are emptied, and the rows themselves stay.
     *
     * @param mysqli $db     An open connection.
     * @param int    $siteId The organisation.
     * @param int    $days   Days of detail to keep. 0 or less means keep for ever.
     *
     * @return array{daysStored:int,rowsCleared:int}
     */
    public static function clearOldDetail(mysqli $db, int $siteId, int $days): array
    {
        $none = ['daysStored' => 0, 'rowsCleared' => 0];

        // 0 (and anything below it) means keep for ever. Nothing runs at all —
        // not even the cut-off query — so this organisation is untouched.
        if ($days < 1 || $siteId <= 0) {
            return $none;
        }

        $cutoff = self::cutoffDate($db, $days);
        if ($cutoff === null) {
            return $none;
        }

        // 🔢 How many event-and-day pairs are about to be written down.
        //
        //    Counted with its own query on purpose. `affected_rows` after the
        //    INSERT below looks like the obvious answer and is WRONG: on
        //    `ON DUPLICATE KEY UPDATE` MySQL reports 1 for a fresh insert and 2
        //    for an update, so the "number of days" shown to a person would be
        //    inflated by one for every day that already had a figure.
        $daysStored = 0;
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS cnt FROM ('
            . 'SELECT 1 AS one '
            . 'FROM tblAnonymousCheckins '
            . 'INNER JOIN tblEvents e ON e.eventID = tblAnonymousCheckins.eventID AND e.siteID = ? '
            . 'WHERE DATE(tblAnonymousCheckins.checkedInAt) < ? '
            . 'AND (tblAnonymousCheckins.userAgent IS NOT NULL '
            . 'OR tblAnonymousCheckins.ipHash IS NOT NULL) '
            . 'GROUP BY tblAnonymousCheckins.eventID, DATE(tblAnonymousCheckins.checkedInAt)'
            . ') AS pairs'
        );
        if ($stmt === false) {
            return $none;
        }
        $stmt->bind_param('is', $siteId, $cutoff);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $daysStored = (int) ($row['cnt'] ?? 0);

        $db->begin_transaction();

        try {
            // 📝 Write the figure down before the detail behind it goes.
            //
            //    THE ROWS COVERED HERE ARE EXACTLY THE ROWS THE CLEAR-OUT BELOW
            //    WILL TOUCH — the same "still has some detail" condition, word
            //    for word. That is not a tidiness point, it is what makes the
            //    whole thing work. Cover fewer rows here than the clear-out
            //    empties and a row that had only a browser description drops out
            //    of `rowsCounted`, then looks like a late arrival for ever
            //    afterwards. Cover MORE rows here than the clear-out empties and
            //    the next sweep finds the same rows again, adds them a second
            //    time, and marks a perfectly ordinary day as approximate.
            //
            //    The senders figure is the full one: different scrambles, plus
            //    one for each row that has no scramble at all. Those rows count
            //    as their own sender everywhere else too, so counting them
            //    anywhere else would change the number the moment it was stored.
            //
            //    `hasLateArrivals = 1` appears ONLY in the duplicate branch, so
            //    it is written exactly when a day that already had a figure
            //    gains more check-ins afterwards. A day stored once and never
            //    added to keeps 0. That one column is the whole of the owner's
            //    18 September decision that such a day is marked rather than
            //    silently double counted.
            $stmt = $db->prepare(
                'INSERT INTO tblAnonymousCheckinDays '
                . '(eventID, checkinDay, uniqueSenders, rowsCounted, hasLateArrivals) '
                . 'SELECT tblAnonymousCheckins.eventID, '
                . 'DATE(tblAnonymousCheckins.checkedInAt), '
                . 'COUNT(DISTINCT tblAnonymousCheckins.ipHash) '
                . '+ SUM(CASE WHEN tblAnonymousCheckins.ipHash IS NULL THEN 1 ELSE 0 END), '
                . 'COUNT(*), '
                . '0 '
                . 'FROM tblAnonymousCheckins '
                . 'INNER JOIN tblEvents e ON e.eventID = tblAnonymousCheckins.eventID AND e.siteID = ? '
                . 'WHERE DATE(tblAnonymousCheckins.checkedInAt) < ? '
                . 'AND (tblAnonymousCheckins.userAgent IS NOT NULL '
                . 'OR tblAnonymousCheckins.ipHash IS NOT NULL) '
                . 'GROUP BY tblAnonymousCheckins.eventID, DATE(tblAnonymousCheckins.checkedInAt) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'uniqueSenders   = uniqueSenders + VALUES(uniqueSenders), '
                . 'rowsCounted     = rowsCounted   + VALUES(rowsCounted), '
                . 'hasLateArrivals = 1, '
                . 'storedAt        = NOW()'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Could not prepare the snapshot statement');
            }
            $stmt->bind_param('is', $siteId, $cutoff);
            // The return value is checked as well as relying on the database
            // throwing. The portal's start-up code turns database errors into
            // exceptions, which is what normally lands in the catch below — but
            // an installation, a test harness or a future change that runs
            // without that setting would get a plain `false` back instead, the
            // catch would never fire, and the figures would be stored for days
            // whose detail was then never cleared. Checking both means the
            // rollback happens either way.
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok === false) {
                throw new \RuntimeException('The snapshot statement did not run');
            }

            // 🧽 Empty the detail for exactly the same days.
            //    `affected_rows` IS the right answer here: this is a plain
            //    UPDATE, so it is one per row changed.
            $stmt = $db->prepare(
                'UPDATE tblAnonymousCheckins '
                . 'INNER JOIN tblEvents e ON e.eventID = tblAnonymousCheckins.eventID AND e.siteID = ? '
                . 'SET tblAnonymousCheckins.userAgent = NULL, tblAnonymousCheckins.ipHash = NULL '
                . 'WHERE DATE(tblAnonymousCheckins.checkedInAt) < ? '
                . 'AND (tblAnonymousCheckins.userAgent IS NOT NULL '
                . 'OR tblAnonymousCheckins.ipHash IS NOT NULL)'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Could not prepare the clear-out statement');
            }
            $stmt->bind_param('is', $siteId, $cutoff);
            $ok = $stmt->execute();
            $rowsCleared = (int) $stmt->affected_rows;
            $stmt->close();
            if ($ok === false) {
                throw new \RuntimeException('The clear-out statement did not run');
            }

            $db->commit();

            return ['daysStored' => $daysStored, 'rowsCleared' => $rowsCleared];
        } catch (\Throwable $e) {
            // Nothing stored and nothing cleared for this organisation. Zero is
            // reported, which is the truth — not a claim that there was nothing
            // to do. The caller carries on with the next organisation.
            $db->rollback();

            return $none;
        }
    }

    /**
     * The cut-off date, worked out once by the database server.
     *
     * Returns a plain `YYYY-MM-DD` string, which is then bound to both
     * statements. Asking the database rather than PHP keeps the comparison in
     * one clock: the rows' own timestamps are written by the database.
     *
     * @param mysqli $db   An open connection.
     * @param int    $days How many days back.
     *
     * @return string|null The date, or null if it could not be worked out.
     */
    private static function cutoffDate(mysqli $db, int $days): ?string
    {
        $stmt = $db->prepare('SELECT DATE(DATE_SUB(NOW(), INTERVAL ? DAY)) AS cutoff');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $cutoff = $row !== null ? (string) $row['cutoff'] : '';

        return $cutoff !== '' ? $cutoff : null;
    }

    // -------------------------------------------------------------------------
    // ➕ Adding the figures to the attendance record, on purpose
    // -------------------------------------------------------------------------

    /**
     * Write an event's anonymous headcount into an attendance session.
     *
     * Anonymous check-ins are NEVER part of the official attendance record
     * until somebody decides they should be. This is that decision. The caller
     * is responsible for requiring an administrator, a form token and a POST;
     * this method does the organisation check and the writing.
     *
     * WHY THE EVENT'S NAME IS IN THE LABEL
     * Two different events pushed into the same session must not overwrite each
     * other's row, so each gets its own label — exactly as Small Groups labels
     * its rows with the group's name. WHAT THAT CANNOT DO: two events whose
     * names are identical once cut to 100 characters (the column's width) would
     * share one row. The screen shows the number already stored under the label
     * before anything is written, so it cannot happen silently.
     *
     * WHY A TRANSACTION AND `FOR UPDATE`, NOT A PLAIN CHECK-THEN-WRITE
     * There is no unique key on (sessionID, groupLabel), so
     * `INSERT … ON DUPLICATE KEY UPDATE` cannot work at all here. A plain
     * check-then-write (what Small Groups does) is re-runnable but racy: two
     * administrators pressing the button at the same moment can both find
     * nothing and both insert, leaving two rows and a doubled headcount.
     * Locking the row first is what makes "never doubles a count" actually true
     * rather than nearly true.
     *
     * A session that belongs to another organisation is refused in exactly the
     * same way as one that does not exist — same answer, same wording — so the
     * refusal cannot be used to find out whether a session number is real.
     *
     * @param mysqli      $db        An open connection.
     * @param int         $siteId    The organisation the caller is acting for.
     * @param int         $eventId   The event whose check-ins are being written.
     * @param int         $sessionId The attendance session to write into.
     * @param string|null $day       One `YYYY-MM-DD` day, or null for every day.
     *
     * @return array{ok:bool,reason?:string,action?:string,was?:int|null,now?:int,label?:string}
     */
    public static function addToAttendanceSession(
        mysqli $db,
        int $siteId,
        int $eventId,
        int $sessionId,
        ?string $day
    ): array {
        if ($siteId <= 0 || $eventId <= 0 || $sessionId <= 0) {
            return ['ok' => false, 'reason' => 'notfound'];
        }

        // 1️⃣ The event, this organisation's, not deleted. Its name goes in the
        //    label, so it is read here rather than trusted from the caller.
        $stmt = $db->prepare(
            'SELECT eventName FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
        );
        if ($stmt === false) {
            return ['ok' => false, 'reason' => 'notfound'];
        }
        $stmt->bind_param('ii', $eventId, $siteId);
        $stmt->execute();
        $eventRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($eventRow === null) {
            return ['ok' => false, 'reason' => 'notfound'];
        }

        // 2️⃣ The session, this organisation's, not deleted.
        $stmt = $db->prepare(
            'SELECT sessionID FROM tblAttendanceSessions '
            . 'WHERE sessionID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
        );
        if ($stmt === false) {
            return ['ok' => false, 'reason' => 'notfound'];
        }
        $stmt->bind_param('ii', $sessionId, $siteId);
        $stmt->execute();
        $sessionRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($sessionRow === null) {
            return ['ok' => false, 'reason' => 'notfound'];
        }

        // 3️⃣ The number. Zero is allowed: writing 0 is a true statement about a
        //    day nobody checked in on, and refusing would leave the previous,
        //    now-wrong number in place.
        $headcount = self::headcountFor($db, $eventId, $siteId, $day);

        // 4️⃣ The label, built in ONE place (see labelFor) so the confirmation
        //    screen and the write can never disagree about which row is meant.
        $label = self::labelFor((string) $eventRow['eventName']);

        $db->begin_transaction();

        try {
            $stmt = $db->prepare(
                'SELECT countID, headcount FROM tblAttendanceCounts '
                . 'WHERE sessionID = ? AND groupLabel = ? LIMIT 1 FOR UPDATE'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Could not prepare the lookup');
            }
            $stmt->bind_param('is', $sessionId, $label);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing !== null) {
                $countId = (int) $existing['countID'];
                $was     = (int) $existing['headcount'];

                $upd = $db->prepare('UPDATE tblAttendanceCounts SET headcount = ? WHERE countID = ?');
                if ($upd === false) {
                    throw new \RuntimeException('Could not prepare the update');
                }
                $upd->bind_param('ii', $headcount, $countId);
                $ok = $upd->execute();
                $upd->close();
                if ($ok === false) {
                    throw new \RuntimeException('The update did not run');
                }

                $db->commit();

                return [
                    'ok'     => true,
                    'action' => 'updated',
                    'was'    => $was,
                    'now'    => $headcount,
                    'label'  => $label,
                ];
            }

            $ins = $db->prepare(
                'INSERT INTO tblAttendanceCounts (sessionID, groupLabel, headcount) VALUES (?, ?, ?)'
            );
            if ($ins === false) {
                throw new \RuntimeException('Could not prepare the insert');
            }
            $ins->bind_param('isi', $sessionId, $label, $headcount);
            $ok = $ins->execute();
            $ins->close();
            if ($ok === false) {
                throw new \RuntimeException('The insert did not run');
            }

            $db->commit();

            return [
                'ok'     => true,
                'action' => 'inserted',
                'was'    => null,
                'now'    => $headcount,
                'label'  => $label,
            ];
        } catch (\Throwable $e) {
            $db->rollback();

            return ['ok' => false, 'reason' => 'failed'];
        }
    }

    /**
     * How many people an event's anonymous check-ins claimed.
     *
     * Organisation-scoped through the same join as everything else. When a day
     * is given, only that day counts; the day is compared as text against
     * `DATE(checkedInAt)`, never turned into a timestamp.
     *
     * @param mysqli      $db      An open connection.
     * @param int         $eventId The event.
     * @param int         $siteId  The organisation the caller is acting for.
     * @param string|null $day     One `YYYY-MM-DD` day, or null for every day.
     *
     * @return int The total headcount. 0 when there is nothing.
     */
    public static function headcountFor(mysqli $db, int $eventId, int $siteId, ?string $day): int
    {
        $sql = 'SELECT COALESCE(SUM(tblAnonymousCheckins.headcount), 0) AS total '
             . 'FROM tblAnonymousCheckins '
             . 'INNER JOIN tblEvents e ON e.eventID = tblAnonymousCheckins.eventID AND e.siteID = ? '
             . 'WHERE tblAnonymousCheckins.eventID = ?';

        $useDay = ($day !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1);
        if ($useDay === true) {
            $sql .= ' AND DATE(tblAnonymousCheckins.checkedInAt) = ?';
        }

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return 0;
        }
        if ($useDay === true) {
            $stmt->bind_param('iis', $siteId, $eventId, $day);
        } else {
            $stmt->bind_param('ii', $siteId, $eventId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * The label an event's figures are stored under in an attendance session.
     *
     * Written in ONE place so the confirmation screen and the write can never
     * mean two different rows. Cut with `mb_substr` to the column's 100
     * characters, so a multi-byte character is never chopped in half and the
     * write can never be refused for being too long.
     *
     * WHAT THIS CANNOT DO: two events whose names are identical up to that cut
     * share one label and therefore one row. See `addToAttendanceSession()`.
     *
     * @param string $eventName The event's name, as stored.
     *
     * @return string The label, at most 100 characters.
     */
    public static function labelFor(string $eventName): string
    {
        return mb_substr('Anonymous check-ins — ' . $eventName, 0, 100);
    }

    /**
     * What is already stored under this event's label in a session.
     *
     * Used by the confirmation screen so an administrator can see the number
     * that is about to be replaced BEFORE pressing anything. That is what stops
     * the label collision described on `addToAttendanceSession()` from ever
     * happening silently.
     *
     * The label is built in PHP and bound, rather than rebuilt in SQL. An
     * earlier draft did rebuild it in SQL with CONCAT and LEFT, which worked
     * but tied the exact wording and the exact cut length to two places at
     * once — change the wording and the screen would quietly start reading a
     * row the write never touches.
     *
     * @param mysqli $db        An open connection.
     * @param int    $siteId    The organisation the caller is acting for.
     * @param int    $eventId   The event.
     * @param int    $sessionId The attendance session.
     *
     * @return int|null The stored headcount, or null if there is no row yet.
     */
    public static function storedInSession(mysqli $db, int $siteId, int $eventId, int $sessionId): ?int
    {
        if ($siteId <= 0 || $eventId <= 0 || $sessionId <= 0) {
            return null;
        }

        // The event's own name, organisation-scoped: a foreign event gives no
        // label and therefore no answer, exactly as a missing one does.
        $stmt = $db->prepare(
            'SELECT eventName FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $eventId, $siteId);
        $stmt->execute();
        $eventRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($eventRow === null) {
            return null;
        }

        $label = self::labelFor((string) $eventRow['eventName']);

        $stmt = $db->prepare(
            'SELECT c.headcount FROM tblAttendanceCounts c '
            . 'INNER JOIN tblAttendanceSessions s ON s.sessionID = c.sessionID AND s.siteID = ? '
            . 'WHERE c.sessionID = ? AND c.groupLabel = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('iis', $siteId, $sessionId, $label);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null ? (int) $row['headcount'] : null;
    }
}
