<?php
// Path: _core/EventRecurrence.php
/**
 * -----------------------------------------------------------------------------
 * Working out the dates of a repeating event the portal itself creates 🔁
 * -----------------------------------------------------------------------------
 * Turns one row of `tblRecurrenceRules` plus the first occurrence's wall-clock
 * start and end into the list of wall-clock starts and ends for the whole
 * series. It touches no database and reads no setting, which is why it can be
 * tested on its own: `tools/event-recurrence-selftest.php`.
 *
 * WHY THIS IS NOT `IcsReader`, which already expands repeating events
 * -----------------------------------------------------------------------------
 * That was the first thing considered, and reuse would have been better if it
 * fitted. It does not, for two separate reasons:
 *
 *  1. `IcsReader::expand()` takes a whole PARSED CALENDAR and carries the
 *     machinery that goes with importing one — per-calendar date limits,
 *     duplicate detection, attachments, and an elaborate "how far can I stand
 *     behind what I read" answer. Feeding it a made-up calendar built from our
 *     own rule row would be a trick, not reuse. `generateStarts()`, the part
 *     that actually matters, is private and shaped around an RFC 5545 rule.
 *
 *  2. MORE IMPORTANTLY, THE TWO WANT DIFFERENT ANSWERS. `IcsReader` follows
 *     RFC 5545 §3.8.5.3: every occurrence keeps the same EXACT length, which
 *     is the owner's decision of 23 September 2026 and the right rule when
 *     reading somebody else's calendar, because what they published is a list
 *     of instants.
 *
 *     Our own events are stored as a WALL-CLOCK READING plus the zone it is a
 *     reading in (`tblEvents.startDateTime` and `tblEvents.timezone`). "Every
 *     Sunday at 10:00" has to stay 10:00 after the clocks change, because that
 *     is when the congregation turns up. That is the NOMINAL rule, the other
 *     half of RFC 5545 §3.3.6. Generating our own services with the
 *     exact-length rule would move every one of them by an hour, twice a year.
 *
 *     So the difference is not an oversight to be tidied away later. Reading
 *     and creating genuinely need opposite rules, and each is right where it
 *     is. If anybody ever merges them, this is the paragraph that says why not.
 *
 * WHAT IT DOES WITH THE AWKWARD DATES
 * -----------------------------------------------------------------------------
 *  - **A day that does not exist in a month is SKIPPED, never moved.** "The
 *    31st of every month" produces no date in February, April, June,
 *    September or November. It is not shifted to the 30th or the 1st, because
 *    both would put a service on a day nobody chose. This matches RFC 5545's
 *    BYMONTHDAY, and it is the behaviour people expect from every other
 *    calendar. The same goes for the 29th of February in a yearly series.
 *  - **A reading inside the hour that does not exist** (the night the clocks
 *    go forward) is moved FORWARD past the gap, keeping its time-of-day
 *    offset from the gap's edge, and it still counts as one occurrence. That
 *    matches what `IcsReader` does with the same situation, and Google, Apple
 *    and Outlook.
 *  - **A reading inside the hour that happens TWICE** needs no decision here.
 *    A wall-clock reading of 01:30 is stored as 01:30; which of the two 01:30s
 *    it means is a question only for code turning it into an instant, and
 *    `Site::zoneFor()` is where that happens.
 *
 * THE LENGTH OF EACH OCCURRENCE is the first one's, measured in whole days plus
 * a time of day, not in seconds. An event from 19:00 to 21:00 is 19:00 to 21:00
 * every time; one running 22:00 to 01:00 next morning keeps that shape too. On
 * a clock-change night the real elapsed hours therefore differ, which is the
 * nominal rule working as intended.
 *
 * TWO DELIBERATE EXCEPTIONS around the hour that does not exist, so nobody
 * "corrects" either of them:
 *
 *   1. A date whose START falls in that hour is moved forward past the gap, and
 *      its END moves by the same amount. That occurrence is therefore NOT at
 *      the same time of day as the others — it cannot be — but it keeps its
 *      LENGTH, which is the part that matters for a booking.
 *   2. A date whose END falls in that hour has the END pushed past the gap. The
 *      instant and the length are UNCHANGED by this (a stored `01:30` already
 *      resolved to 02:30); what changes is that the stored text is now a
 *      reading that exists, which matters because wall-clock times in this
 *      portal compare as text. It LOOKS an hour later on the clock and is not
 *      actually longer — do not "compensate" for it.
 *
 * See the notes beside the gap handling in `datesFor()`.
 *
 * THERE IS ALWAYS A LIMIT. A rule with no end date and no count would otherwise
 * run for ever, so a number-of-dates ceiling and a how-far-ahead ceiling both
 * apply, and the caller is TOLD when one of them stopped the work (`capped`),
 * so it can say so rather than quietly producing a short series. Which limit
 * bites depends on the pattern: with the defaults, a weekly rule reaches the
 * three-year ceiling at 157 dates, long before the 200-date one, while a
 * twice-weekly rule hits 200 first.
 *
 * BOTH CEILINGS ARE SETTINGS, not constants — `calendar.repeat.maxOccurrences`
 * and `calendar.repeat.maxYears`, settable for the whole installation or for
 * one organisation. `limitsFrom($SETTINGS)` reads them; `DEFAULT_MAX_*` are
 * what an installation that has never chosen gets, and `HARD_MAX_*` are as far
 * as a setting may go (see those constants for why a ceiling needs a ceiling).
 * This class still reads no table and opens no connection, which is what lets
 * its test run with no database: the caller hands the numbers in.
 *
 * **The ceiling has one awkward consequence, and it is deliberate.** A yearly
 * series on 29 February produces exactly ONE date under the DEFAULT three-year
 * horizon, because the next leap day is four years away. Raising
 * `calendar.repeat.maxYears` to four or more is now a real answer to that, and
 * is why the number was worth making configurable. Either way the caller is
 * told the series was cut short, and the person extends it later. The
 * alternative — clamping 29 February to the 28th — was rejected: it would put
 * the event on a day nobody chose, which is the same fault as moving a missing
 * 31st. Check B02 of the self-test pins this so it is not "fixed" by clamping.
 *
 * WHAT IT CANNOT DO
 *   - It does not write anything, and does not know about `tblEvents`.
 *   - It does not handle `frequency = 'custom'`: there is no pattern to read.
 *     The caller gets no dates and a reason. `calendar/export.php` already
 *     treats 'custom' the same way when it maps a rule to an RRULE.
 *   - It cannot say whether a date is sensible for the organisation — a public
 *     holiday, a clash, a venue already booked. Those are the caller's.
 *   - It does not de-duplicate against events that already exist.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use DateTimeImmutable;
use DateTimeZone;

final class EventRecurrence
{
    /**
     * 🧢 The most dates one series may create, when nobody has chosen.
     *
     * A weekly service for two years is 104, so 200 leaves room for a
     * twice-weekly series over the same period. A ceiling exists at all
     * because a rule with no end and no count is otherwise endless, and
     * because every date becomes a real row in `tblEvents`: a runaway series
     * would be slow to create and worse to undo.
     *
     * This is the DEFAULT, not the rule. An installation or one organisation
     * may raise or lower it — see `limitsFrom()`.
     */
    public const DEFAULT_MAX_OCCURRENCES = 200;

    /**
     * 🧢 And the furthest ahead a series may reach, whatever its count.
     *
     * Three years. A diary further out than that is guesswork, and somebody
     * extending a series later is the normal way to work. Also a default.
     */
    public const DEFAULT_MAX_YEARS = 3;

    /**
     * 🛑 The most a setting may raise those to, and why there IS a stop.
     *
     * A configurable ceiling with no ceiling of its own is not a ceiling. Two
     * things make an unlimited one a real hazard rather than a theoretical
     * one, and both are properties of where this portal runs:
     *
     *   1. A series is created inside ONE web request, on shared hosting,
     *      under whatever time limit the host sets — commonly 30 seconds.
     *      Every date is an `INSERT … SELECT` copying a real event row. A
     *      request that runs out of time half way through leaves a series
     *      partly made, which is the worst of the available outcomes.
     *   2. There is no bulk undo. Removing 5,000 unwanted dates is 5,000
     *      decisions, or a database restore.
     *
     * So a global administrator may raise the ceiling a long way — 1,000
     * dates is a daily event for nearly three years — but not to infinity.
     * An administrator who genuinely needs more should make a second series,
     * which is also the honest answer: a 20-year diary is not one series.
     *
     * Lowering these in a later release is safe for data already created: it
     * changes what a NEW series may produce, never what exists.
     */
    public const HARD_MAX_OCCURRENCES = 1000;

    /** 🛑 And the furthest a setting may push the horizon. */
    public const HARD_MAX_YEARS = 10;

    /** ⚙️ The settings key for the number of dates. */
    public const OCCURRENCES_KEY = 'calendar.repeat.maxOccurrences';

    /** ⚙️ The settings key for how far ahead. */
    public const YEARS_KEY = 'calendar.repeat.maxYears';

    /** Days as `tblRecurrenceRules.dayOfWeek` writes them: 0 = Sunday. */
    private const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /**
     * ⚙️ The two ceilings in force, with the INSTALLATION having the last word.
     *
     * WHO WINS, AND WHY IT IS NOT THE ORDINARY SETTINGS RULE
     * ---------------------------------------------------------------------
     * For almost every setting an organisation's own choice simply overrides
     * the installation-wide one, and `$SETTINGS` has already done that merge
     * by the time any page runs.
     *
     * A LIMIT is different, and the owner set the rule on 29 October 2026:
     * the installation's value takes precedence. One installation may serve
     * many organisations, so an organisation may hold itself to a LOWER
     * ceiling than the installation allows, but may never raise its own
     * above it. The alternative lets one organisation on a shared install
     * help itself to resources the host deliberately capped — and every date
     * a series makes is a real row written inside one web request.
     *
     * So the answer is the SMALLER of the two, then capped by the hard
     * maximum in this file. Three tiers, each one unable to loosen the one
     * above it: code, then installation, then organisation.
     *
     * TAKES ARRAYS, NOT A CONNECTION, ON PURPOSE. This class opens no
     * connection and reads no table, which is what lets its whole test run
     * with no database. `$SETTINGS_INSTALLATION` is kept beside `$SETTINGS`
     * by the settings loader in `bootstrap.php` precisely so that a reader
     * which needs the two layers apart has them without a second query.
     *
     * WITH ONE ARGUMENT it behaves as it always did: the array given is
     * treated as both layers, so `min(x, x)` is x. That keeps every caller
     * that does not care about the distinction — and this class's own
     * arithmetic checks — working unchanged.
     *
     * WHAT AN UNUSABLE VALUE DOES, and why it is not all one rule:
     *
     *   • Missing, empty, or not a whole number → the default. Nobody chose,
     *     so the portal chooses.
     *   • Below 1 → the default, NOT zero. A ceiling of none would make the
     *     repeat controls appear to work and silently produce nothing, which
     *     is the shape of fault this project keeps finding; switching repeats
     *     off is a different request and would need its own setting.
     *   • Above the hard maximum → the hard maximum. Clamped rather than
     *     refused, because by the time a value is IN the database the person
     *     who typed it has gone. The save handler refuses an out-of-range
     *     number while they are still there to be told, so a value only
     *     reaches this clamp by direct database editing, or because a later
     *     release lowered the hard maximum under a value already stored.
     *
     * @param array<string,mixed>      $settings    The merged `$SETTINGS`, or any
     *                                              nested array with the same shape.
     * @param array<string,mixed>|null $installWide The installation-wide layer
     *                                              (`$SETTINGS_INSTALLATION`). Null
     *                                              means "no separate layer known",
     *                                              and `$settings` is used for both.
     *
     * @return array{occurrences: int, years: int}
     */
    public static function limitsFrom(array $settings, ?array $installWide = null): array
    {
        // 🏢 What the installation allows, resolved — so an installation that
        //    has chosen nothing still has a number (the built-in default).
        $allowed = self::repeatPair($installWide ?? $settings);

        // 🏢 What this organisation asked for, UNRESOLVED: null means it has
        //    not chosen, which is a different thing from choosing the default.
        //
        //    That distinction is load-bearing and was got wrong first time.
        //    Resolving the organisation's side to the DEFAULT when it had
        //    chosen nothing made the default cap the installation: an
        //    installation raising its horizon to four years handed back three,
        //    because min(3, 4) is 3. So an installation could lower a limit but
        //    never raise one unless every organisation also set a row. Caught
        //    by check H04 of this class's own test.
        $wanted = self::rawRepeatPair($settings);

        return [
            'occurrences' => self::smallerOfTwo($wanted['occurrences'], $allowed['occurrences']),
            'years'       => self::smallerOfTwo($wanted['years'], $allowed['years']),
        ];
    }

    /**
     * 🏢 One organisation's wish against what the installation allows.
     *
     * Nothing chosen means follow the installation. Otherwise the smaller of
     * the two: lower is always allowed, higher never is.
     *
     * @param int|null $wanted  What the organisation asked for, or null.
     * @param int      $allowed What the installation allows.
     */
    private static function smallerOfTwo(?int $wanted, int $allowed): int
    {
        if ($wanted === null) {
            return $allowed;
        }

        return min($wanted, $allowed);
    }

    /**
     * ⚙️ The ceiling the INSTALLATION allows, on its own.
     *
     * What an organisation may not exceed. The settings page needs it to say
     * so, and the save handler needs it to refuse a bigger number.
     *
     * @param array<string,mixed> $installWide `$SETTINGS_INSTALLATION`.
     *
     * @return array{occurrences: int, years: int}
     */
    public static function installationAllows(array $installWide): array
    {
        return self::repeatPair($installWide);
    }

    /**
     * ⚙️ Both ceilings out of one settings layer, resolved to real numbers.
     *
     * Anything unusable becomes the built-in default, so the answer is always
     * a workable pair. Use this for the layer that has to HAVE a number.
     *
     * @param array<string,mixed> $settings
     *
     * @return array{occurrences: int, years: int}
     */
    private static function repeatPair(array $settings): array
    {
        $raw = self::rawRepeatPair($settings);

        return [
            'occurrences' => $raw['occurrences'] ?? self::DEFAULT_MAX_OCCURRENCES,
            'years'       => $raw['years'] ?? self::DEFAULT_MAX_YEARS,
        ];
    }

    /**
     * ⚙️ Both ceilings out of one settings layer, WITHOUT a fallback.
     *
     * Null means "this layer has not chosen", which the caller may need to
     * tell apart from "this layer chose the default" — see `limitsFrom()`.
     * A value that IS there is still clamped to the hard maximum.
     *
     * @param array<string,mixed> $settings
     *
     * @return array{occurrences: int|null, years: int|null}
     */
    private static function rawRepeatPair(array $settings): array
    {
        $repeat = $settings['calendar']['repeat'] ?? [];
        if (is_array($repeat) === false) {
            $repeat = [];
        }

        return [
            'occurrences' => self::oneLimitOrNull(
                $repeat['maxOccurrences'] ?? null,
                self::HARD_MAX_OCCURRENCES
            ),
            'years' => self::oneLimitOrNull(
                $repeat['maxYears'] ?? null,
                self::HARD_MAX_YEARS
            ),
        ];
    }

    /**
     * ⚙️ One stored ceiling, read defensively.
     *
     * A settings value is always text, so `'12'` is the ordinary case and
     * `'twelve'`, `''` and `'-4'` all have to be survivable.
     *
     * @param mixed $stored  Whatever the settings array held.
     * @param int   $default Used when nothing usable was stored.
     * @param int   $hardMax The most this may be.
     *
     * @return int
     */
    private static function oneLimit(mixed $stored, int $default, int $hardMax): int
    {
        return self::oneLimitOrNull($stored, $hardMax) ?? $default;
    }

    /**
     * ⚙️ One stored ceiling, or null when nothing usable was stored.
     *
     * A settings value is always text, so `'12'` is the ordinary case and
     * `'twelve'`, `''` and `'-4'` all have to be survivable. Zero and
     * negatives count as "not chosen" rather than as a ceiling of none: see
     * `limitsFrom()` for why a ceiling of none is refused.
     *
     * @param mixed $stored  Whatever the settings array held.
     * @param int   $hardMax The most this may be.
     */
    private static function oneLimitOrNull(mixed $stored, int $hardMax): ?int
    {
        if (is_int($stored) === false && is_string($stored) === false) {
            return null;
        }

        $text = trim((string) $stored);
        if (preg_match('/^-?\d{1,9}$/', $text) !== 1) {
            return null;
        }

        $value = (int) $text;
        if ($value < 1) {
            return null;
        }

        return $value > $hardMax ? $hardMax : $value;
    }

    /**
     * 🧢 The defaults, for a caller with no settings to hand.
     *
     * @return array{occurrences: int, years: int}
     */
    public static function defaultLimits(): array
    {
        return [
            'occurrences' => self::DEFAULT_MAX_OCCURRENCES,
            'years'       => self::DEFAULT_MAX_YEARS,
        ];
    }

    /**
     * 🔁 Every wall-clock start and end this rule produces.
     *
     * @param array<string,mixed> $rule       A row of `tblRecurrenceRules`, or the
     *                                        same keys: frequency, intervalVal,
     *                                        dayOfWeek, dayOfMonth, weekOfMonth,
     *                                        monthOfYear, startDate, endDate,
     *                                        maxOccurrences.
     * @param string              $firstStart The first occurrence's start, as
     *                                        'YYYY-MM-DD HH:MM:SS' — a reading on
     *                                        a clock, not an instant.
     * @param string|null         $firstEnd   Its end in the same form, or null.
     * @param string              $zoneName   The zone those readings are in
     *                                        (`tblEvents.timezone`). Used ONLY to
     *                                        find the hour that does not exist.
     * @param array{occurrences?: int, years?: int}|null $limits
     *                                        How many dates, and how far ahead,
     *                                        this installation or organisation
     *                                        allows — from `limitsFrom($SETTINGS)`.
     *                                        Null, or a missing key, means the
     *                                        default. Anything unusable is read
     *                                        the same way a stored setting is;
     *                                        see `limitsFrom()`.
     *
     * @return array{dates: list<array{start: string, end: ?string}>, capped: bool, why: string}
     *         `why` is '' when nothing stopped the work early, and otherwise a
     *         plain sentence the caller can show.
     */
    public static function datesFor(
        array $rule,
        string $firstStart,
        ?string $firstEnd,
        string $zoneName,
        ?array $limits = null
    ): array {
        // 🧢 Normalised through the same reader a stored setting goes
        //    through, so a caller passing a partial or silly array gets the
        //    same treatment as a partial or silly database row.
        $caps = [
            'occurrences' => self::oneLimit(
                $limits['occurrences'] ?? null,
                self::DEFAULT_MAX_OCCURRENCES,
                self::HARD_MAX_OCCURRENCES
            ),
            'years' => self::oneLimit(
                $limits['years'] ?? null,
                self::DEFAULT_MAX_YEARS,
                self::HARD_MAX_YEARS
            ),
        ];

        $frequency = (string) ($rule['frequency'] ?? 'weekly');
        if ($frequency === 'custom') {
            return [
                'dates'  => [],
                'capped' => false,
                'why'    => 'A "custom" pattern has nothing in it to work the dates out from, so no repeats were made.',
            ];
        }

        $zone = self::zoneOrNull($zoneName);
        if ($zone === null) {
            return [
                'dates'  => [],
                'capped' => false,
                'why'    => 'The event\'s time zone was not recognised, so no repeats were made.',
            ];
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $firstStart, $zone);
        if ($start === false) {
            return ['dates' => [], 'capped' => false, 'why' => 'The event\'s start could not be read, so no repeats were made.'];
        }

        // ⏱️ The shape of the event, kept as "whole days later, at this time",
        //    never as a number of seconds. That is what makes the length
        //    nominal: see the note at the top of this file.
        $endShape = self::endShape($firstStart, $firstEnd);

        $interval = (int) ($rule['intervalVal'] ?? 1);
        if ($interval < 1) {
            $interval = 1;
        }

        // Fortnightly and quarterly are not separate patterns: they are a
        // weekly and a monthly one with the step doubled and tripled. Doing it
        // here means the walk below has three cases instead of five.
        if ($frequency === 'fortnightly') {
            $frequency = 'weekly';
            $interval *= 2;
        } elseif ($frequency === 'quarterly') {
            $frequency = 'monthly';
            $interval *= 3;
        }

        [$limit, $limitNote]  = self::occurrenceLimit($rule, $caps['occurrences']);
        [$stop, $ceilingNote] = self::lastAllowedDate($rule, $start, $caps['years']);

        $starts = $frequency === 'weekly'
            ? self::weeklyStarts($start, $interval, self::daysWanted($rule, $start), $limit, $limitNote, $stop, $ceilingNote)
            : self::monthlyOrYearlyStarts($frequency, $start, $interval, $rule, $limit, $limitNote, $stop, $ceilingNote);

        // 🕳️ The hour that does not exist, handled once for every date rather
        //    than inside each pattern.
        //
        //    THE END MOVES WITH THE START, BY THE SAME AMOUNT. This was wrong
        //    until the independent check measured it: the end was built from
        //    the MOVED start with the first occurrence's end time-of-day, so a
        //    01:30–02:30 event whose start was pushed to 02:30 came out
        //    02:30–02:30 — zero length — and a 01:30–02:00 one came out
        //    ending BEFORE it started. Measured in London AND New York, so it
        //    was never a European quirk.
        //
        //    Shifting both by the same number of seconds is what keeps the
        //    length, which is exactly what the documents promise. The end is
        //    therefore built from the UNMOVED start and then shifted.
        $dates = [];
        foreach ($starts['dates'] as $wallStart) {
            $moved = self::pastTheHourThatIsMissing($wallStart, $zone);
            $end   = $endShape === null ? null : self::applyEndShape($wallStart, $endShape);
            if ($end !== null && $moved !== $wallStart) {
                $end = self::shiftedBySameAmount($end, $wallStart, $moved);
            }

            // 🕳️ AND THE END IS PUT PAST THE GAP TOO, for the separate case
            //    where the START is fine and only the END lands in the missing
            //    hour — an event from 00:30 to 01:30 on the night London goes
            //    forward, say.
            //
            //    BE PRECISE ABOUT WHAT THIS DOES AND DOES NOT CHANGE, because
            //    the first version of this note was wrong in BOTH directions
            //    and the independent check measured it:
            //
            //      • It does NOT change the instant, and it does NOT change
            //        the length. Stored as `01:30` the reading resolved to
            //        02:30 BST; stored as `02:30` it resolves to 02:30 BST —
            //        the same moment, 60 minutes after the 00:30 start, before
            //        and after. Nothing ever "read back longer than intended".
            //      • Nor is the occurrence really longer than its siblings. It
            //        only LOOKS an hour later on the clock. This is the same
            //        distinction the calendar reader already draws correctly
            //        (see its check I34f: "appearance only").
            //
            //    WHAT IT DOES FIX is that the stored text was a reading that
            //    does not exist. That matters on its own: this project's rule
            //    is that wall-clock times compare AS TEXT, so a stored reading
            //    which no clock ever shows sorts and compares against readings
            //    that do, and anything reading it back gets a value different
            //    from the one written. There is no third option — the intended
            //    end has no reading that night — and a real time is better than
            //    a fictional one.
            if ($end !== null) {
                $end = self::pastTheHourThatIsMissing($end, $zone);
            }

            $dates[] = ['start' => $moved, 'end' => $end];
        }

        return ['dates' => $dates, 'capped' => $starts['capped'], 'why' => $starts['why']];
    }

    /**
     * 🗓️ Which weekdays the rule asks for, as day numbers (0 = Sunday).
     *
     * An empty or unreadable list means "the same day as the first
     * occurrence", which is what somebody ticking nothing means.
     *
     * @param array<string,mixed> $rule
     *
     * @return list<int>
     */
    private static function daysWanted(array $rule, DateTimeImmutable $start): array
    {
        $raw = trim((string) ($rule['dayOfWeek'] ?? ''));
        if ($raw === '') {
            return [(int) $start->format('w')];
        }

        $days = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece === '' || preg_match('/^[0-6]$/', $piece) !== 1) {
                continue;
            }
            $days[] = (int) $piece;
        }
        if ($days === []) {
            return [(int) $start->format('w')];
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * 📅 Weekly (and fortnightly, which arrives here with the step doubled).
     *
     * Walks week by week from the week the first occurrence falls in, and
     * inside each week emits every weekday the rule asks for, in order. A date
     * BEFORE the first occurrence is skipped: ticking Monday and Wednesday on
     * a Wednesday means this Wednesday, then next Monday — not a Monday that
     * has already gone.
     *
     * @param list<int> $daysWanted
     *
     * @return array{dates: list<string>, capped: bool, why: string}
     */
    private static function weeklyStarts(
        DateTimeImmutable $start,
        int $interval,
        array $daysWanted,
        int $limit,
        string $limitNote,
        DateTimeImmutable $stop,
        string $ceilingNote
    ): array {
        $time = $start->format('H:i:s');
        // The Sunday that begins the first occurrence's week, so that "every
        // other week" counts weeks rather than days.
        $weekStart = $start->modify('-' . (int) $start->format('w') . ' days')->setTime(0, 0, 0);

        $dates  = [];
        $capped = false;
        $why    = '';

        while (true) {
            foreach ($daysWanted as $day) {
                $dayDate = $weekStart->modify('+' . $day . ' days');
                // 🕳️ The reading the pattern ASKS FOR, kept as text. What goes
                //    into the list is this, not what PHP resolves it to — see
                //    the note on the list below.
                $wanted    = $dayDate->format('Y-m-d') . ' ' . $time;
                $candidate = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $wanted,
                    $dayDate->getTimezone()
                );
                if ($candidate === false) {
                    continue;
                }
                if ($candidate < $start) {
                    continue;   // a day earlier in the first week
                }
                if ($candidate > $stop) {
                    return $ceilingNote !== ''
                        ? ['dates' => $dates, 'capped' => true, 'why' => $ceilingNote]
                        : ['dates' => $dates, 'capped' => $capped, 'why' => $why];
                }
                // 🕳️ THE INTENDED READING GOES IN, not `$candidate`'s own
                //    format(). They differ only inside the hour that does not
                //    exist, where `createFromFormat()` quietly resolves 01:30
                //    to 02:30 — and that is precisely the case `datesFor()`
                //    has to notice, so that it can move the END by the same
                //    amount. Emitting the resolved reading here made
                //    `pastTheHourThatIsMissing()` a no-op and left such an
                //    occurrence with no length at all. Comparisons above still
                //    use the resolved object, which is right: it is the real
                //    instant.
                $dates[] = $wanted;
                if (count($dates) >= $limit) {
                    return [
                        'dates'  => $dates,
                        'capped' => $limitNote !== '',
                        'why'    => $limitNote,
                    ];
                }
            }
            $weekStart = $weekStart->modify('+' . $interval . ' weeks');
            if ($weekStart > $stop) {
                return $ceilingNote !== ''
                    ? ['dates' => $dates, 'capped' => true, 'why' => $ceilingNote]
                    : ['dates' => $dates, 'capped' => $capped, 'why' => $why];
            }
        }
    }

    /**
     * 📅 Monthly (including quarterly) and yearly.
     *
     * Two shapes are supported, and which one applies depends on the rule:
     *   - a day of the month ("the 15th"), and
     *   - the nth weekday of the month ("the second Tuesday", or with
     *     weekOfMonth = -1, "the last Tuesday").
     *
     * A MONTH THAT HAS NO SUCH DAY IS SKIPPED. "The 31st" produces nothing in
     * February; "the fifth Monday" produces nothing in a month with four. The
     * date is never moved to a nearby day, because a service on a day nobody
     * chose is worse than a missing one, and because every other calendar
     * behaves this way.
     *
     * @param array<string,mixed> $rule
     *
     * @return array{dates: list<string>, capped: bool, why: string}
     */
    private static function monthlyOrYearlyStarts(
        string $frequency,
        DateTimeImmutable $start,
        int $interval,
        array $rule,
        int $limit,
        string $limitNote,
        DateTimeImmutable $stop,
        string $ceilingNote
    ): array {
        $time        = $start->format('H:i:s');
        $zone        = $start->getTimezone();
        $weekOfMonth = ($rule['weekOfMonth'] ?? null) === null ? null : (int) ($rule['weekOfMonth'] ?? null);
        $dayOfMonth  = ($rule['dayOfMonth'] ?? null) === null ? null : (int) $rule['dayOfMonth'];
        $monthOfYear = ($rule['monthOfYear'] ?? null) === null ? null : (int) ($rule['monthOfYear'] ?? null);

        // Nothing said? Take it from the first occurrence, which is what
        // somebody means by "repeat monthly" with nothing else filled in.
        if ($weekOfMonth === null && $dayOfMonth === null) {
            $dayOfMonth = (int) $start->format('j');
        }
        $weekdayWanted = $weekOfMonth === null
            ? null
            : self::daysWanted($rule, $start)[0];

        // The step is in months for monthly, and in months of twelve for
        // yearly, so one walk covers both.
        $stepMonths = $frequency === 'yearly' ? 12 * $interval : $interval;

        // Where the walk begins: the first of the first occurrence's month,
        // except that a yearly rule naming a month starts in that month.
        $cursor = $start->setDate((int) $start->format('Y'), (int) $start->format('n'), 1)->setTime(0, 0, 0);
        if ($frequency === 'yearly' && $monthOfYear !== null && $monthOfYear >= 1 && $monthOfYear <= 12) {
            $cursor = $cursor->setDate((int) $start->format('Y'), $monthOfYear, 1);
            if ($cursor->format('Y-m') < $start->format('Y-m')) {
                $cursor = $cursor->modify('+12 months');
            }
        }

        $dates  = [];
        $capped = false;

        while ($cursor <= $stop) {
            $year  = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');

            $day = $weekdayWanted === null
                ? self::dayIfTheMonthHasIt($year, $month, (int) $dayOfMonth)
                : self::nthWeekdayOfMonth($year, $month, $weekdayWanted, (int) $weekOfMonth);

            if ($day !== null) {
                // 🕳️ The reading the pattern asks for; see the weekly walker
                //    for why the INTENDED text is what goes into the list.
                $wanted    = sprintf('%04d-%02d-%02d %s', $year, $month, $day, $time);
                $candidate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $wanted, $zone);
                if ($candidate !== false && $candidate >= $start && $candidate <= $stop) {
                    $dates[] = $wanted;
                    if (count($dates) >= $limit) {
                        return ['dates' => $dates, 'capped' => $limitNote !== '', 'why' => $limitNote];
                    }
                }
            }

            $cursor = $cursor->modify('+' . $stepMonths . ' months');
        }

        return $ceilingNote !== ''
            ? ['dates' => $dates, 'capped' => true, 'why' => $ceilingNote]
            : ['dates' => $dates, 'capped' => $capped, 'why' => ''];
    }

    /**
     * 🔢 That day of that month, or null when the month does not have one.
     *
     * `checkdate()` is used rather than building the date and seeing where it
     * lands, because PHP would roll 31 February forward into March without
     * complaining, and a silently moved date is exactly what must not happen.
     */
    private static function dayIfTheMonthHasIt(int $year, int $month, int $day): ?int
    {
        if ($day < 1 || $day > 31) {
            return null;
        }

        return checkdate($month, $day, $year) === true ? $day : null;
    }

    /**
     * 🔢 The nth given weekday of a month, or null when there is no such day.
     *
     * `$nth` of -1 means the last one. 5 means the fifth, which most months do
     * not have — and then this answers null rather than giving the fourth.
     */
    private static function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $nth): ?int
    {
        $name = self::DAY_NAMES[$weekday] ?? null;
        if ($name === null) {
            return null;
        }

        $first = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-01 00:00:00', $year, $month));
        if ($first === false) {
            return null;
        }
        $daysInMonth = (int) $first->format('t');

        $found = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            if ((int) date('w', (int) mktime(0, 0, 0, $month, $day, $year)) === $weekday) {
                $found[] = $day;
            }
        }
        if ($found === []) {
            return null;
        }

        if ($nth === -1) {
            return $found[count($found) - 1];
        }
        if ($nth < 1) {
            return null;
        }

        return $found[$nth - 1] ?? null;
    }

    /**
     * 🕳️ Move a reading forward out of the hour that does not exist.
     *
     * On the night the clocks go forward there is no 01:30 (in most of Europe)
     * or no 02:30 (in much of North America). PHP will happily hold such a
     * reading and then report a different wall clock when asked, which is how
     * a date can look right in the database and wrong on screen.
     *
     * The test is to build the reading in its own zone and ask what wall clock
     * it really is. If it comes back as something else, the reading was inside
     * the gap, and the moment PHP gave is the right place to be: it is the
     * same instant, expressed as the clock that really existed. So the moved
     * reading is simply what PHP reports.
     *
     * It still counts as ONE occurrence, which matches `IcsReader` and every
     * other calendar.
     *
     * WHAT THIS DOES NOT TOUCH: the hour that happens twice. A reading of
     * 01:30 on that night is a real wall clock, written down as 01:30; which
     * of the two it means is only a question when something turns it into an
     * instant, and that is `Site::zoneFor()`'s job, not this file's.
     */
    private static function pastTheHourThatIsMissing(string $wallClock, DateTimeZone $zone): string
    {
        $built = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $wallClock, $zone);
        if ($built === false) {
            return $wallClock;
        }

        return $built->format('Y-m-d H:i:s');
    }

    /**
     * ⏱️ Move a reading by the same amount another reading moved.
     *
     * Used only when a start was pushed out of the hour that does not exist:
     * the end has to travel the same distance or the occurrence changes length.
     * Both are plain readings, so this is read in UTC — which has no clock
     * changes, and is therefore the one place where "the same amount" is
     * unambiguous.
     *
     * @param string $reading The end, as it would have been.
     * @param string $before  The start before it was moved.
     * @param string $after   The start after it was moved.
     *
     * @return string The end, moved by the same number of seconds.
     */
    private static function shiftedBySameAmount(string $reading, string $before, string $after): string
    {
        $utc  = new DateTimeZone('UTC');
        $read = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $reading, $utc);
        $from = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $before, $utc);
        $to   = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $after, $utc);
        if ($read === false || $from === false || $to === false) {
            return $reading;
        }

        $seconds = $to->getTimestamp() - $from->getTimestamp();
        if ($seconds === 0) {
            return $reading;
        }

        return $read->modify(($seconds >= 0 ? '+' : '-') . abs($seconds) . ' seconds')->format('Y-m-d H:i:s');
    }

    /**
     * ⏱️ How the end sits relative to the start: whole days on, at a time.
     *
     * Returned as ['days' => int, 'time' => 'HH:MM:SS'] so that applying it is
     * calendar arithmetic rather than adding seconds. That is what keeps an
     * event 19:00 to 21:00 on every date, and keeps an overnight one overnight.
     *
     * @return array{days: int, time: string}|null
     */
    private static function endShape(string $firstStart, ?string $firstEnd): ?array
    {
        if ($firstEnd === null || trim($firstEnd) === '') {
            return null;
        }

        $utc   = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $firstStart, $utc);
        $end   = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $firstEnd, $utc);
        if ($start === false || $end === false) {
            return null;
        }

        // Read in UTC on purpose: both are plain readings here and UTC has no
        // clock changes, so the number of days between the two DATES is the
        // honest nominal difference. Using the event's own zone would make the
        // answer depend on which night the first occurrence happened to be.
        $startDay = $start->setTime(0, 0, 0);
        $endDay   = $end->setTime(0, 0, 0);
        $days     = (int) $startDay->diff($endDay)->days;
        if ($endDay < $startDay) {
            $days = -$days;
        }

        return ['days' => $days, 'time' => $end->format('H:i:s')];
    }

    /**
     * ⏱️ Put that shape back on a start, giving the occurrence's end.
     *
     * @param array{days: int, time: string} $shape
     */
    private static function applyEndShape(string $wallStart, array $shape): string
    {
        $utc   = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $wallStart, $utc);
        if ($start === false) {
            return $wallStart;
        }
        $day = $start->setTime(0, 0, 0)->modify(($shape['days'] >= 0 ? '+' : '-') . abs($shape['days']) . ' days');

        return $day->format('Y-m-d') . ' ' . $shape['time'];
    }

    /**
     * 🧢 How many dates this rule may produce, and WHO decided.
     *
     * The rule's own "repeat N times" wins when it asks for FEWER than the
     * ceiling. It can never raise it: a person filling in the form must not be
     * able to ask for more than the installation allows.
     *
     * The second return value is the sentence to show when the series was cut
     * short, and it is '' when the ORGANISER'S OWN COUNT is what stopped it.
     *
     * WHY, because this was wrong and two of this class's own checks pinned the
     * wrong wording until the independent check measured it: asking for 3 dates
     * and getting 3 produced "Only the first 3 dates were made. Extend the
     * series later to add more." Nothing had been cut short. `lastAllowedDate()`
     * already had this right — it answers '' when the organiser's own end date
     * stopped the series — and this is the same distinction for the count.
     *
     * @param array<string,mixed> $rule
     * @param int                 $ceiling This installation's or
     *                                     organisation's maximum.
     *
     * @return array{0: int, 1: string}
     */
    private static function occurrenceLimit(array $rule, int $ceiling): array
    {
        $asked = ($rule['maxOccurrences'] ?? null) === null ? null : (int) $rule['maxOccurrences'];
        if ($asked !== null && $asked > 0 && $asked < $ceiling) {
            return [$asked, ''];
        }

        return [$ceiling, self::cappedWhy($ceiling)];
    }

    /**
     * 🧢 The last date this rule may reach, and WHO decided it.
     *
     * Either the rule's own end date, or the furthest ahead any series may go,
     * whichever comes first. The second return value matters: stopping because
     * the ORGANISER said so is a series that ended, and stopping because WE
     * said so is a series that was cut short and the person has to be told, or
     * they will think the diary is complete when it is not.
     *
     * An earlier version returned only the date, so a weekly rule with no end
     * quietly produced 157 dates and reported nothing — found by this class's
     * own test, which expected to be told.
     *
     * @param int $years How far ahead this installation or organisation lets
     *                    a series reach.
     *
     * @return array{0: DateTimeImmutable, 1: string} The last date, and the
     *         sentence to show when it is OUR ceiling — '' when the series
     *         simply ended where its organiser said it would.
     *
     * It returns the SENTENCE rather than a yes/no because the sentence has to
     * name the number of years, and that number is now a setting rather than a
     * constant. Handing a bool back up and reading the setting again further
     * away would be two places that have to agree.
     */
    private static function lastAllowedDate(array $rule, DateTimeImmutable $start, int $years): array
    {
        $ceiling = $start->modify('+' . $years . ' years');
        $note    = self::ceilingWhy($years);

        $endDate = trim((string) ($rule['endDate'] ?? ''));
        if ($endDate === '') {
            return [$ceiling, $note];
        }

        $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endDate . ' 23:59:59', $start->getTimezone());
        if ($end === false) {
            return [$ceiling, $note];
        }

        return $end < $ceiling ? [$end, ''] : [$ceiling, $note];
    }

    /** 💬 Why a series stopped at our ceiling rather than at its own end. */
    private static function ceilingWhy(int $years): string
    {
        return 'Dates were made for the next ' . $years
            . ($years === 1 ? ' year' : ' years')
            . '. Extend the series later to add more.';
    }

    /** 💬 Why the list stopped, in words a person can read. */
    private static function cappedWhy(int $limit): string
    {
        return 'Only the first ' . $limit . ' dates were made. Extend the series later to add more.';
    }

    /** 🌍 The zone, or null when the name is not one. */
    private static function zoneOrNull(string $name): ?DateTimeZone
    {
        if ($name === '') {
            return null;
        }
        try {
            return new DateTimeZone($name);
        } catch (\Throwable) {
            return null;
        }
    }
}
