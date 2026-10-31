<?php
// Path: web/_core/SeriesRepattern.php
/**
 * -----------------------------------------------------------------------------
 * What changing a series' pattern would do, decided in one place 🔁
 * -----------------------------------------------------------------------------
 * A repeating series in this portal is NOT one row with a rule attached. Every
 * date is an ordinary `tblEvents` row, and `tblRecurrenceRules` only describes
 * them so the calendar feed can publish one repeating entry instead of twenty
 * (see `calendar/export.php`). So changing a series' pattern means adding,
 * moving and removing real event rows — each of which may have a person's
 * attendance, a booked room or a child's check-in hanging off it.
 *
 * This class is the ONE place that decides what such a change would do. It
 * takes FACTS and returns a PLAN; it opens no connection and runs no query, so
 * the whole decision can be tested without a database, and the page that shows
 * the preview and the handler that applies it cannot disagree about what is
 * about to happen — they are given the same plan object.
 *
 * THE THREE RULES THE OWNER SET, 31 OCTOBER 2026 (#669)
 * -----------------------------------------------------------------------------
 *  1. FUTURE DATES ONLY, and a date with ANYTHING attached is never moved and
 *     never removed. It is listed with the reason instead. The alternative — a
 *     tick-box per attached date saying what would be lost — was rejected
 *     because that list is the part most likely to be written wrongly, and
 *     understating it is exactly what would make the tick feel safe. Removing
 *     one awkward date already works through the ordinary edit and delete, so
 *     nobody is stuck.
 *  2. A HAND-EDITED DATE IS WORKED OUT, NOT REMEMBERED. See `followsPattern()`.
 *  3. NOTHING IS WRITTEN WITHOUT A PREVIEW. That is the page's job, but it is
 *     why `plan()` returns four named lists rather than just doing the work.
 *
 * WHY A DATE THAT STAYS IS UPDATED IN PLACE, WHICH IS THE WHOLE DESIGN
 * -----------------------------------------------------------------------------
 * A date the new pattern still wants has its START AND END changed and nothing
 * else. It is never deleted and re-created. That one choice is what makes a
 * hand-changed title, venue, description or poster safe WITHOUT this class
 * having to detect those changes at all — they are simply never touched. The
 * hand edits the comparison therefore has to catch are the ones to the two
 * columns a move DOES write: the start and the finish. Both are compared —
 * `followsPattern()` says why the finish is not optional, and what the fallback
 * for a date older than migration 232 still cannot see.
 *
 * Re-creating instead would also destroy every attached record through the
 * CASCADE rules in `ATTACHMENTS` below, for a date the person expected to keep.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * -----------------------------------------------------------------------------
 *   - It does not work out the pattern's dates. `EventRecurrence::datesFor()`
 *     does that, with the ceilings and the clock-change behaviour already
 *     settled, and needs nothing new (#669 says so and it was true).
 *   - It does not decide whether the series' rule row may survive the change.
 *     That is the caller's, because it needs the series' PAST dates too; see
 *     `ruleCanStillBeTrue()` for the test it should use.
 *   - It cannot tell a date that was never generated from a pattern from one
 *     whose origin was forgotten. Both come back as `KEPT_UNKNOWN`, and the
 *     page says so in those words rather than guessing.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/669
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class SeriesRepattern
{
    // 🏷️ Why a date is being left exactly as it is. These are the only four
    //    answers, and `reasonWords()` is the only place they become English.
    public const KEPT_PAST    = 'past';
    public const KEPT_ATTACHED = 'attached';
    public const KEPT_BY_HAND = 'by-hand';
    public const KEPT_UNKNOWN = 'unknown-origin';
    // Two dates of this series on one day. Its own reason, not KEPT_BY_HAND:
    // the independent check found that a PAST or ATTACHED date sharing a day
    // was being reported as "moved by hand", which is simply untrue of it, and
    // was then offered an edit link that makes no sense for a date that has
    // already happened.
    public const KEPT_SAME_DAY = 'same-day';

    /**
     * 🔗 EVERY TABLE THAT POINTS AT AN EVENT, and what a row in it means.
     *
     * This is the list rule 1 is enforced from: a future date with a row in
     * ANY of these is left alone. It is written out here, rather than read from
     * `information_schema` at run time, for two reasons. A list read from the
     * live database would change behaviour silently when a customer's database
     * drifted, and a plan that cannot be predicted cannot be tested.
     *
     * IT CANNOT GO STALE, because `tools/audit-checks/check_series_attachments_complete.py`
     * compares it against `web/_sql/full_schema.sql` and FAILS BOTH WAYS — a
     * new table with an `eventID` COLUMN that is missing here, and an entry
     * here naming a table that no longer has one.
     *
     * THE TEST IS THE COLUMN, NOT A FOREIGN KEY, and saying otherwise is what
     * caused the fault this list was rebuilt to fix: two tables name an event
     * with no key at all. A table that keeps its `eventID` column and merely
     * drops its foreign key is still on this list and still protected, which
     * is correct. Without that check this
     * constant is the worst shape in the codebase: a safety list that looks
     * complete and silently is not. A new table would make its rows invisible
     * to this class, and the first pattern change after it shipped would remove
     * the dates they hang off.
     *
     * The ON DELETE rule is given per entry as a fact about the schema, NOT
     * because it describes the harm here. BE PRECISE ABOUT THAT, because an
     * earlier version of this comment was not and the difference was measured
     * on MySQL 8.0.36 rather than reasoned about:
     *
     *   • THIS PAGE SOFT-DELETES (`isDeleted = 1`). No ON DELETE rule fires on
     *     an UPDATE, so all three values behave IDENTICALLY here — every
     *     attached row survives, pointing at a date nobody can see. That is the
     *     real harm, and it is the same for all 34 tables.
     *   • The rule only decides what happens if something later really DELETEs
     *     the event row. Then CASCADE takes the rows with it, SET NULL leaves
     *     them findable as belonging to nothing, and 'no rule' leaves a row
     *     pointing at an id that no longer exists at all.
     *
     * So 'no rule' is the worst of the three ONLY in that second case. Saying
     * it is "the most dangerous value" full stop, and giving the soft-delete
     * consequence as the reason, welded two different scenarios together.
     *
     * @var array<string,array{words:string,onDelete:string}>
     */
    public const ATTACHMENTS = [
        'tblEventAttendance'         => ['words' => 'attendance has been marked',                  'onDelete' => 'cascade'],
        'tblAttendanceSessions'      => ['words' => 'an attendance session was opened',            'onDelete' => 'set null'],
        'tblEventRegistrations'      => ['words' => 'somebody has registered',                     'onDelete' => 'cascade'],
        'tblEventRSVPInvites'        => ['words' => 'invitations have been sent',                  'onDelete' => 'cascade'],
        'tblAnonymousCheckins'       => ['words' => 'people have checked themselves in',           'onDelete' => 'cascade'],
        'tblAnonymousCheckinDays'    => ['words' => 'a self check-in day was opened',              'onDelete' => 'cascade'],
        'tblKidCheckins'             => ['words' => 'a child was checked in',                      'onDelete' => 'set null'],
        'tblVenueBookings'           => ['words' => 'a room is booked',                            'onDelete' => 'set null'],
        'tblEventOccurrenceOverrides' => ['words' => 'this date was already changed by hand',      'onDelete' => 'cascade'],
        'tblSalvationCards'          => ['words' => 'a pastoral record was kept',                  'onDelete' => 'set null'],
        'tblDecisionMoments'         => ['words' => 'a pastoral record was kept',                  'onDelete' => 'cascade'],
        'tblServicePlan'             => ['words' => 'a service plan was prepared',                 'onDelete' => 'set null'],
        'tblServicePlans'            => ['words' => 'a service plan was prepared',                 'onDelete' => 'set null'],
        'tblEventCrews'              => ['words' => 'people are rostered',                         'onDelete' => 'cascade'],
        'tblEventJobs'               => ['words' => 'jobs were assigned',                          'onDelete' => 'cascade'],
        'tblEventPeople'             => ['words' => 'people are named on it',                      'onDelete' => 'cascade'],
        'tblEventCoordinators'       => ['words' => 'it has a coordinator',                        'onDelete' => 'cascade'],
        'tblLivestreamSessions'      => ['words' => 'it was streamed',                             'onDelete' => 'set null'],
        'tblLiveChatMessages'        => ['words' => 'people talked in its chat',                   'onDelete' => 'cascade'],
        'tblLivePrompts'             => ['words' => 'prompts were prepared for its stream',        'onDelete' => 'cascade'],
        'tblEventBroadcasts'         => ['words' => 'a message was broadcast about it',            'onDelete' => 'cascade'],
        'tblEventReminderLog'        => ['words' => 'reminders have gone out',                     'onDelete' => 'cascade'],
        'tblEventMaterials'          => ['words' => 'materials were attached',                     'onDelete' => 'cascade'],
        'tblEventLinks'              => ['words' => 'links were added',                            'onDelete' => 'cascade'],
        'tblDocuments'               => ['words' => 'a document was filed against it',             'onDelete' => 'set null'],
        'tblEventHubResources'       => ['words' => 'resources were published for it',             'onDelete' => 'cascade'],
        'tblEventHubVideos'          => ['words' => 'videos were published for it',                'onDelete' => 'cascade'],
        'tblAssetEventAssignments'   => ['words' => 'equipment is booked out to it',               'onDelete' => 'cascade'],
        'tblEventOrgs'               => ['words' => 'another organisation is named on it',         'onDelete' => 'cascade'],
        'tblEventThemeMap'           => ['words' => 'it was given a theme',                       'onDelete' => 'cascade'],
        'tblExternalEventTags'       => ['words' => 'it carries tags from an outside calendar',    'onDelete' => 'cascade'],
        'tblExternalEventApprovals'  => ['words' => 'it is waiting for approval',                  'onDelete' => 'cascade'],
        // ⚠️ THESE TWO HAVE NO FOREIGN KEY AT ALL, which is exactly why they
        //    were missing from this list until the independent check of
        //    31 October 2026 found them. The first version of this constant was
        //    built from the schema's FOREIGN KEY declarations, and the audit
        //    script beside it said in its own docstring that nothing in this
        //    schema points at an event without one. That claim was written
        //    WITHOUT CHECKING and was false.
        //
        //    `tblEventRSVPs` is the worse of the two by far: its `eventID` is
        //    NOT NULL, it carries `uq_event_user (eventID, userID)`, and it is
        //    the table holding every member's going/maybe/no answer — the very
        //    thing the owner's decision of 31 October names. A pattern change
        //    was offering to remove a date while those answers sat against it.
        //    With no foreign key nothing cascades, so the rows do not even go:
        //    they are left pointing at a date nobody can see.
        //
        //    `onDelete` is 'no rule' for both, which means the schema never
        //    stops an event row being deleted out from under them. It changes
        //    NOTHING about what this page does — a soft delete fires no ON
        //    DELETE rule at all, so rows in every one of these 34 tables
        //    survive it equally — but it means a later hard delete would leave
        //    these two tables holding a reference to nothing.
        'tblEventRSVPs'              => ['words' => 'people have said whether they are coming',   'onDelete' => 'no rule'],
        'tblZoomMeeting'             => ['words' => 'a Zoom meeting is booked for it',            'onDelete' => 'no rule'],
    ];

    /**
     * 🕵️ WHETHER A DATE IS STILL WHERE ITS PATTERN PUT IT.
     *
     * Rule 2. The answer is WORKED OUT by comparing, never remembered as a
     * flag, and there are two bases for the comparison:
     *
     *   'recorded' — `tblEvents.patternStart` holds the moment the generator
     *                produced this date (migration 232). If it equals the
     *                date's current start, nobody has moved it. This is the
     *                only basis that is right for certain.
     *
     *   'old-rule' — `patternStart` is empty, because the date was created
     *                before migration 232 ran. We fall back to re-generating
     *                the series' CURRENT rule and asking whether this date is
     *                one of its dates. That is nearly as good and is the
     *                reason this feature works on an existing installation at
     *                all. Without it every date on every customer's database
     *                would read as "origin unknown" and a pattern change would
     *                do nothing — a fix that only works on a fresh install is
     *                a fix for the one case where the fault is invisible
     *                (#671's first lesson, applied deliberately here).
     *
     *   'unknown'  — no recorded origin AND no rule row to fall back on. The
     *                creation path deletes a series' rule when ordinary dates
     *                join it, so this is a real state, not a theoretical one.
     *                The date is left alone and the page says why.
     *
     * `$oldPatternStarts` is compared as TEXT, which is right rather than lazy:
     * both sides are readings of the same event's own clock in the same zone,
     * and this project's standing rule is that wall-clock times compare as text
     * (#435). Turning either into a moment would break on the two nights a year
     * the clocks change.
     *
     * THE END IS JUDGED TOO, and leaving it out was a real fault rather than a
     * nicety. The docblock at the top of this class used to say "the only hand
     * edit the comparison has to catch is a changed TIME", and the independent
     * check of 31 October 2026 measured that it caught a changed START only:
     * somebody who lengthened one future service from an hour to three had it
     * silently shortened again by the next pattern change, and the preview
     * called that date "re-timed" rather than leaving it alone. That is the
     * owner's rule 2 broken — a hand edit quietly overwritten — and it is the
     * #645 shape, a comment promising what the code had forgotten to do.
     *
     * So `patternEnd` is recorded beside `patternStart` and both must still
     * match. WHAT THIS CANNOT DO, said plainly: the `old-rule` fallback judges
     * the START only, because re-generating the series' rule gives dates whose
     * ends come from the first occurrence's shape rather than from what each
     * date really held. A date created before migration 232 whose END alone was
     * changed by hand is therefore still treated as following the pattern, and
     * will be re-timed. Narrowing that would mean refusing every pre-232 date,
     * which would make the feature useless on an existing installation.
     *
     * @param ?string           $patternStart     `tblEvents.patternStart`, or null.
     * @param ?string           $patternEnd       `tblEvents.patternEnd`, or null.
     * @param string            $start            `tblEvents.startDateTime`.
     * @param ?string           $end              `tblEvents.endDateTime`.
     * @param list<string>      $oldPatternStarts Readings the CURRENT rule produces; empty when there is no rule.
     *
     * @return array{follows:bool,basis:string}
     */
    public static function followsPattern(
        ?string $patternStart,
        ?string $patternEnd,
        string $start,
        ?string $end,
        array $oldPatternStarts
    ): array {
        $recorded = $patternStart === null ? '' : trim($patternStart);

        if ($recorded !== '') {
            // 🕰️ Both readings, compared as TEXT. An event with no finish time
            //    has none recorded either, and two nulls agree — written as a
            //    normalisation rather than `===` on mixed types so that '' and
            //    null cannot read as a difference and report every such date as
            //    hand-edited.
            $sameStart = $recorded === trim($start);
            $sameEnd   = self::sameReading($patternEnd, $end);

            return ['follows' => $sameStart === true && $sameEnd === true, 'basis' => 'recorded'];
        }

        if ($oldPatternStarts === []) {
            return ['follows' => false, 'basis' => 'unknown'];
        }

        return ['follows' => in_array(trim($start), $oldPatternStarts, true), 'basis' => 'old-rule'];
    }

    /**
     * 🕰️ Whether two stored readings mean the same moment on the same clock.
     *
     * "Not set" is one value however it arrives — null, an empty string or
     * spaces — because a column read back from the database and a value built
     * in PHP do not always agree about which of those they use, and treating
     * them as different would report a perfectly ordinary date as hand-edited.
     */
    private static function sameReading(?string $a, ?string $b): bool
    {
        return trim((string) $a) === trim((string) $b);
    }

    /**
     * 🚦 WHY THIS DATE MAY NOT BE TOUCHED, or an empty string if it may.
     *
     * The order of these tests is deliberate and is the order a person would
     * ask them in: a date that has already happened is simply not our business,
     * whatever else is true of it. Attachment comes next because it is the one
     * with real records behind it. Then the two origin answers.
     *
     * NOTE WHAT IS *NOT* HERE: nothing about the event's status, category or
     * name. A cancelled future date is still a date the pattern governs, and
     * moving it keeps the cancellation — which is what somebody who cancelled
     * one week of a service would expect.
     *
     * @param array{start:string,patternStart:?string,attachedTo:list<string>} $event
     * @param string                                                           $nowReading A reading of the organisation's own clock, 'Y-m-d H:i:s'.
     * @param list<string>                                                     $oldPatternStarts
     */
    public static function whyKept(array $event, string $nowReading, array $oldPatternStarts): string
    {
        $start = trim((string) ($event['start'] ?? ''));

        // 🕰️ Compared as TEXT against a reading of the same clock. See
        //    followsPattern() above for why that is correct here.
        if ($start === '' || $start <= trim($nowReading)) {
            return self::KEPT_PAST;
        }

        if (($event['attachedTo'] ?? []) !== []) {
            return self::KEPT_ATTACHED;
        }

        $origin = self::followsPattern(
            $event['patternStart'] ?? null,
            $event['patternEnd'] ?? null,
            $start,
            $event['end'] ?? null,
            $oldPatternStarts
        );
        if ($origin['follows'] === true) {
            return '';
        }

        return $origin['basis'] === 'unknown' ? self::KEPT_UNKNOWN : self::KEPT_BY_HAND;
    }

    /**
     * 💬 One of the four reasons, in words a person reads.
     *
     * Kept apart from the reasons themselves so that nothing records or
     * compares the English — the same shape `Sms::heldPersonWords()` uses.
     *
     * @param list<string> $attachedTo What is attached, for KEPT_ATTACHED only.
     */
    public static function reasonWords(string $reason, array $attachedTo = []): string
    {
        switch ($reason) {
            case self::KEPT_PAST:
                return 'it has already happened';
            case self::KEPT_ATTACHED:
                return $attachedTo === []
                    // Defensive: the caller passed the reason without the
                    // detail. Saying "something" is true; inventing a record
                    // that may not exist is not.
                    ? 'something is attached to it'
                    : implode('; ', $attachedTo);
            case self::KEPT_BY_HAND:
                return 'it was moved by hand, so the pattern is not what put it there';
            case self::KEPT_SAME_DAY:
                // The day is passed in `$attachedTo` for this one reason, so
                // there is ONE wording rather than two. `plan()` used to build
                // its own sentence here, which left this branch unreachable
                // from the page while the class self-test exercised only the
                // unused copy — a test holding its own copy of the thing it
                // tests, in miniature.
                $day = $attachedTo === [] ? 'that day' : (string) $attachedTo[0];
                return 'more than one date of this series falls on ' . $day
                    . ', so which one the pattern means is not clear';
            case self::KEPT_UNKNOWN:
                return 'there is no record of what put this date here, so it is not safe to assume the pattern did';
            default:
                return 'it is being left as it is';
        }
    }

    /**
     * 📋 THE PLAN: what changing the pattern would do, as four named lists.
     *
     * HOW AN EXISTING DATE IS PAIRED WITH A NEW ONE: by the DAY, never by the
     * time. That is what makes all four of the things #669 asks for fall out of
     * one rule — shifting a service from 10:00 to 10:30 pairs every date with
     * itself and moves it; going weekly to fortnightly leaves half the days
     * unpaired and removes them; extending a run leaves new days unpaired and
     * adds them; shortening it removes the tail.
     *
     * TWO DATES ON ONE DAY ARE BOTH LEFT ALONE. A series can hold two events on
     * the same day — somebody moves one date onto another, or adds an extra by
     * hand — and there is then no honest way to say which one the pattern
     * means. Pairing the first and removing the rest would delete a date
     * somebody deliberately added. So neither is touched and the day is
     * reported. This is the only case where a date with a perfectly good
     * recorded origin is still left alone.
     *
     * @param list<array{eventID:int,start:string,end:?string,patternStart:?string,attachedTo:list<string>}> $events     Every live, portal-owned event of the series.
     * @param list<array{start:string,end:?string}>                                                          $newDates   What the NEW pattern produces.
     * @param string                                                                                         $nowReading A reading of the organisation's own clock.
     * @param list<string>                                                                                   $oldPatternStarts
     *
     * @return array{
     *     move:list<array{eventID:int,from:string,to:string,toEnd:?string}>,
     *     add:list<array{start:string,end:?string}>,
     *     remove:list<array{eventID:int,start:string}>,
     *     keep:list<array{eventID:int,start:string,why:string,words:string}>,
     *     sameDay:list<string>
     * }
     */
    public static function plan(array $events, array $newDates, string $nowReading, array $oldPatternStarts): array
    {
        $plan = ['move' => [], 'add' => [], 'remove' => [], 'keep' => [], 'sameDay' => []];

        // 🗂️ The series' own dates, grouped by day, so pairing is one lookup.
        $byDay = [];
        foreach ($events as $event) {
            $day = substr(trim((string) ($event['start'] ?? '')), 0, 10);
            if ($day === '') {
                continue;
            }
            $byDay[$day][] = $event;
        }

        // 🗂️ The new pattern's dates, also by day. A pattern cannot produce two
        //    dates on one day today, but reading only the FIRST per day means a
        //    future pattern that could would add one date rather than silently
        //    pairing one and adding the other.
        $wantedByDay = [];
        foreach ($newDates as $date) {
            $day = substr(trim((string) ($date['start'] ?? '')), 0, 10);
            if ($day === '' || isset($wantedByDay[$day]) === true) {
                continue;
            }
            $wantedByDay[$day] = $date;
        }

        // 1️⃣ Every date the series already has.
        foreach ($byDay as $day => $onThatDay) {
            $wanted = $wantedByDay[$day] ?? null;

            if (count($onThatDay) > 1) {
                $plan['sameDay'][] = $day;
                foreach ($onThatDay as $event) {
                    $plan['keep'][] = [
                        'eventID' => (int) $event['eventID'],
                        'start'   => (string) $event['start'],
                        'why'     => self::KEPT_SAME_DAY,
                        'words'   => self::reasonWords(self::KEPT_SAME_DAY, [$day]),
                    ];
                }
                continue;
            }

            $event = $onThatDay[0];
            $why   = self::whyKept($event, $nowReading, $oldPatternStarts);

            if ($why !== '') {
                $plan['keep'][] = [
                    'eventID' => (int) $event['eventID'],
                    'start'   => (string) $event['start'],
                    'why'     => $why,
                    'words'   => self::reasonWords($why, (array) ($event['attachedTo'] ?? [])),
                ];
                continue;
            }

            if ($wanted === null) {
                $plan['remove'][] = [
                    'eventID' => (int) $event['eventID'],
                    'start'   => (string) $event['start'],
                ];
                continue;
            }

            // Same day, and the new pattern wants it. Move it only if the
            // reading really differs — an unchanged date must not be reported
            // as a change, and must not be written either.
            $toStart = trim((string) $wanted['start']);
            $toEnd   = $wanted['end'] === null ? null : trim((string) $wanted['end']);
            $fromEnd = $event['end'] === null ? null : trim((string) $event['end']);

            if ($toStart !== trim((string) $event['start']) || $toEnd !== $fromEnd) {
                $plan['move'][] = [
                    'eventID' => (int) $event['eventID'],
                    'from'    => (string) $event['start'],
                    'to'      => $toStart,
                    'toEnd'   => $toEnd,
                ];
            }
        }

        // 2️⃣ Days the new pattern wants and the series has not got. Only
        //    FUTURE ones: back-filling a date into last month would invent a
        //    meeting that never happened, and no attendance could ever be
        //    marked against it.
        foreach ($wantedByDay as $day => $date) {
            if (isset($byDay[$day]) === true) {
                continue;
            }
            if (trim((string) $date['start']) <= trim($nowReading)) {
                continue;
            }
            $plan['add'][] = [
                'start' => trim((string) $date['start']),
                'end'   => $date['end'] === null ? null : trim((string) $date['end']),
            ];
        }

        return $plan;
    }

    /** 🔢 Whether a plan would change anything at all. */
    public static function planIsEmpty(array $plan): bool
    {
        return ($plan['move'] ?? []) === []
            && ($plan['add'] ?? []) === []
            && ($plan['remove'] ?? []) === [];
    }

    /**
     * 📡 WHETHER THE SERIES' RULE ROW COULD STILL BE TRUE AFTER THE CHANGE.
     *
     * `calendar/export.php` collapses a series with a rule into ONE repeating
     * entry and then skips every other event of that series. So a rule that
     * over-promises does not merely look untidy — it sends every subscriber
     * dates the diary does not contain, and hides the ones it does.
     *
     * A rule may therefore survive a repattern only when the series, afterwards,
     * holds EXACTLY the dates the new pattern makes and nothing else. In
     * practice that means a series with no past dates and nothing left alone.
     * Any other outcome gets no rule, and the feed lists each date on its own —
     * which is the owner's decision of 30 October 2026 for exactly this case
     * (#670): a series whose dates no longer match its pattern is published one
     * date at a time.
     *
     * `export.php` has its own `$patternStillTrue` guard, so this is the second
     * of two defences rather than the only one — but it is the one that stops a
     * false rule being WRITTEN, and a stored falsehood that one reader happens
     * to catch is still a stored falsehood.
     *
     * @param list<string> $liveStartsAfter Every live date of the series AFTER the change, as readings.
     * @param list<string> $newPatternStarts Readings the new pattern produces.
     */
    public static function ruleCanStillBeTrue(array $liveStartsAfter, array $newPatternStarts): bool
    {
        $have = $liveStartsAfter;
        $want = $newPatternStarts;
        sort($have);
        sort($want);
        return $have === $want;
    }
}
