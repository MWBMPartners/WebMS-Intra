<?php
// Path: _core/IcsReader.php
/**
 * -----------------------------------------------------------------------------
 * Reading an outside calendar file 📅🔎 (#514, part P5)
 * -----------------------------------------------------------------------------
 * An "outside calendar" is a calendar somebody else keeps — a Google calendar,
 * a Microsoft 365 calendar, a room-booking system — which an administrator has
 * asked the portal to show. The file those systems hand out is in the iCalendar
 * format (RFC 5545), the same format a `.ics` invitation in an email uses.
 *
 * This class turns that file into a plain list of dates the portal can store.
 * `Portal\Core\SafeFetch` (part P4 of #514) fetches the file; this class reads
 * it; the importer (part P6) writes it to the database. None of the three
 * knows anything about the others' job.
 *
 * TWO STEPS, ON PURPOSE
 * ---------------------
 * `parse()` reads the file into its properties and does nothing clever.
 * `expand()` turns those properties into real dates: it works out time zones,
 * follows repeat rules, applies skipped and changed dates, cleans the text and
 * keeps only what falls inside the window asked for.
 *
 * They are separate because they fail differently. A file that is cut off half
 * way still parses into whatever arrived, and the caller is told `complete` is
 * false so it can refuse to delete anything. Working the dates out is the
 * expensive step, so it takes its own time limit.
 *
 * THE FILE IS UNTRUSTED
 * ---------------------
 * Every piece of text in it was typed by somebody outside this organisation,
 * and it ends up on a portal page. So:
 * - control characters and invisible formatting characters are removed
 *   (U+202E, "right-to-left override", can make `event-txt.exe` read as
 *   `event-exe.txt` on screen);
 * - a description that arrives as HTML — Google writes one — is turned into
 *   plain text, tags removed and entities decoded, so nothing can be injected
 *   into a page later;
 * - a web address is kept only when it really is an http or https address;
 * - every field is cut to a length the database column can hold, counted in
 *   characters and never cutting a character in half.
 *
 * The caller must still escape everything on the way out. Cleaning here is the
 * first line, not the last.
 *
 * PROTECTING THE SERVER FROM THE FILE
 * -----------------------------------
 * A calendar can ask for an unbounded amount of work: "every day for ever",
 * "every day from the year 1 with a count of a million". The `MAX_*` limits
 * below stop that. Each of them ends the work quietly — a warning, and either
 * `capped = true` from `expand()` or `complete = false` from `parse()` — never
 * a failed import. A time limit (`$deadline`, a moment from `microtime(true)`)
 * is checked as well, and passing it throws `RuntimeException('time budget')`
 * — the caller decides what to do.
 *
 * THE TIME LIMIT IS CHECKED IN TIME, NOT IN STEPS. It is checked on every
 * line while the file is read, on every event, on every date a series
 * contributes, and on every step the repeat-rule worker takes. It used to be
 * checked every two hundred steps, and in two of those loops not at all,
 * because the settled plan said "every 200 steps" and assumed a step was
 * cheap. One yearly step naming 749 different weekdays has to look at every
 * day of every month, which is about a quarter of a second, so a FIVE-second
 * budget came back after 47.78 seconds from a 4,236-byte file. That matters
 * because the importer runs at a web-served address, where an overrun ends as
 * PHP's own "maximum execution time exceeded" — another fatal error nothing
 * can catch. Asking the time costs 20 nanoseconds, measured, so there was
 * never anything to save by asking less often.
 *
 * WHAT THE TIME LIMIT STILL CANNOT DO: a single step cannot be interrupted
 * part way through, so the work may run over by one step — about a quarter of
 * a second for the most expensive shape measured — rather than by two hundred
 * of them.
 *
 * MEMORY, WHICH IS THE ONE THAT CANNOT BE PROMISED OUTRIGHT
 * ---------------------------------------------------------
 * Running out of memory in PHP is a FATAL error. It cannot be caught, so an
 * importer refreshing several calendars in turn would stop dead and leave
 * every calendar after that one unrefreshed, with nothing in the log to
 * explain it. THE FILE IS UNTRUSTED, so this is not a rare accident to be
 * made unlikely: it is something somebody may be trying to do on purpose,
 * inside a file small enough for the fetcher to accept.
 *
 * Three rounds of independent checking each found this class dying of the same
 * mistake, in ten different places altogether. The mistake is always the same
 * shape, so it is written here as a RULE rather than as a list of the shapes
 * that were measured:
 *
 *     NEVER TURN TEXT THAT CAME OUT OF THE FILE INTO AN ARRAY BEFORE YOU KNOW
 *     HOW MANY PIECES IT WILL MAKE.
 *
 * Counting the separators first costs nothing (`substr_count()` builds no
 * array at all — see `tooManyPieces()`, which every one of these guards now
 * goes through). Splitting first and counting afterwards costs one array entry
 * per separator, BEFORE anything can object, and five and a quarter million of
 * them is more memory than PHP allows.
 *
 * WHAT IS BOUNDED, AND BY WHAT
 * ----------------------------
 * Every place a calendar chooses how big something gets:
 * - `MAX_LINES_PER_FILE` — how many lines are read at all. 4 MB of nothing
 *   but line endings made PHP ask for 128 MB in a single allocation.
 * - `MAX_EVENT_BLOCKS_PER_FILE` — how many events are held from one file.
 * - `MAX_COLLECTED_OCCURRENCES` — how many dates are gathered before the list
 *   is cut to size. A 95 KB file of 500 never-ending daily series asked for
 *   200,000 dates to be built and sorted.
 * - `MAX_OCCURRENCES_PER_SERIES` — how many dates ONE event may contribute,
 *   counting dates added by hand with `RDATE` as well as dates from its
 *   repeat rule.
 * - `MAX_DATE_LIST_PER_EVENT` — how many skipped (`EXDATE`) or added
 *   (`RDATE`) dates are read from one event. Counted in PIECES READ, not in
 *   dates kept: repeats used to collapse into one entry, so a list of the same
 *   date five million times never reached the limit.
 * - `MAX_CATEGORY_LIST_PER_EVENT` — how many category pieces are read from one
 *   event, applied to the split AND to the list built from it.
 * - `MAX_RULE_PARTS` — how many parts one repeat rule is split into.
 * - `MAX_RULE_LIST_ENTRIES` — how many values each `BY…` part may list.
 * - `MAX_PARAMS_PER_PROPERTY` — how many settings one property line may carry.
 * - `MAX_ZONE_NAME_LENGTH` and `MAX_ZONE_NAME_SEGMENTS` — how long a time-zone
 *   name may be and how many parts it may be split into.
 * - `MAX_WARNINGS` — how many warnings are recorded, with repeats dropped as
 *   they arrive rather than at the end.
 * - `MAX_RULE_INTERVAL` — how far apart a repeat rule may repeat. Without it
 *   an absurd `INTERVAL` overflowed into floating-point arithmetic and threw
 *   a kind of error the caller is never told to expect.
 * - `LAST_STORABLE_END` — how far ahead an event may end, because the end goes
 *   into a database column that stops at the year 9999.
 * - `MAX_EVENTS_PER_FEED_CEILING` — the most the per-feed limit may be raised
 *   to. That limit is the ONE number a customer may change (see below); this
 *   is the bound on what they may change it to.
 *
 * AND THE COMPANION RULE, WHICH IS WHAT THE FOURTH ROUND OF CHECKING FOUND:
 *
 *     A LIMIT THAT BOUNDS ONE OF SOMETHING MUST BE MATCHED BY SOMETHING THAT
 *     BOUNDS THE SUM OF ALL OF THEM.
 *
 * Every limit above with "PER_EVENT" in its name bounds what ONE event keeps.
 * Each of those lists is then held for every event, for the whole of
 * `expand()`, and nothing bounded the total. A per-event limit of four
 * thousand means nothing when six thousand events are held at once: ninety-
 * nine events each staying honestly inside the per-event limit, in a 3.57 MB
 * file, killed the process. So each per-event limit now has a file-wide twin:
 * - `MAX_RETAINED_ADDED_DATES` — added (`RDATE`) dates kept across the file.
 * - `MAX_RETAINED_SKIPPED_DATES` — skipped (`EXDATE`) dates kept across it.
 * - `MAX_RETAINED_RULE_VALUES` — repeat-rule `BY…` values kept across it.
 * Each is sized from a MEASURED cost per entry; see their own notes.
 *
 * Beside all of that, both steps watch `memory_get_usage()` against this
 * process's own `memory_limit` as they go, stopping at half of it while
 * reading and at four fifths while working the dates out.
 *
 * The counts do the everyday work, because a count behaves the same on every
 * host. The memory watch is a second line, for shapes no count can bound (a
 * handful of events carrying megabytes of text), and it is the one with the
 * caveat below.
 *
 * WHAT IS STILL NOT BOUNDED, SAID PLAINLY
 * ---------------------------------------
 * - The memory watch is checked every five hundred lines while reading, and
 *   before each event while the dates are worked out — in BOTH of the loops
 *   that read events, which was not true until the fourth round of checking.
 *   One of them checked only when the event number divided by a hundred, with
 *   a comment claiming a hundred events was a small enough step; a hundred
 *   events were then measured growing by up to 155 MB against a margin of
 *   25.6 MB. A guard whose outcome depends on where the file's size happens
 *   to fall relative to a sampling interval is not a guard.
 * - It is still NOT checked inside the work a single event does. That gap is
 *   where every fatal error found here has happened, which is why the counts
 *   above exist; but the watch itself does not cover it. The margin held back
 *   is a fifth of what PHP allows (25.6 MB of the usual 128 MB) and the most
 *   one event can add to what is KEPT is about 2.1 MB, so the margin is
 *   twelve times the worst growth between two checks. Those two numbers are
 *   the honest measure of this guard, and both are measured.
 * - On a host with `memory_limit` set to `-1` (no limit) the memory watch does
 *   nothing at all, because there is nothing to measure against. The counts
 *   are the only thing bounding the work there.
 * - Nothing is checked on every allocation. A single field may still be as
 *   large as the whole file, because one enormous string is legitimate — a
 *   5 MB `DESCRIPTION` is read, cleaned and then cut to 5,000 characters, and
 *   the copies made while cleaning it are real memory nothing counts.
 * - The block stack in `parse()` grows one entry per `BEGIN:` line. It is
 *   bounded only by `MAX_LINES_PER_FILE`: half a million `BEGIN:` lines, in a
 *   5 MB file, was measured at 43 MB in a process doing nothing else. That is
 *   within what PHP allows, and it is recorded here rather than guarded,
 *   because the array of lines beside it is the larger cost and is the
 *   accepted design.
 *
 * So: the honest claim is that every list whose length the FILE chooses is now
 * counted before it is built, that the sum of those lists across every event
 * is bounded too, and that the shapes measured across four rounds of checking
 * — and the shapes found while fixing them — come back with a warning instead
 * of a fatal error. It is NOT "this cannot happen". Each of the four rounds
 * found shapes the round before had missed, and the fourth found the same
 * mistake one level up from where the third had fixed it.
 *
 * THE ONE NUMBER A CUSTOMER MAY CHANGE
 * ------------------------------------
 * Every limit above is fixed in this file, with one exception: how many dates
 * a single calendar may contribute (`MAX_EVENTS_PER_FEED`, 2,000). The owner
 * decided on 23 September 2026 that a customer should be able to raise that,
 * and the reason is not comfort — it is correctness. A calendar that reaches
 * the limit comes back `capped`, a capped answer tells the importer not to
 * tidy up dates that have genuinely gone, and a large school or cathedral
 * therefore accumulates stale entries for ever.
 *
 * **This class still reads no setting and opens no database connection.** That
 * is what lets it be self-tested at all. The number arrives as the last
 * argument to `expand()`; leaving the argument out gives exactly the behaviour
 * every earlier round measured. Part P6 reads the customer's setting and
 * passes it in. A value outside 1 to `MAX_EVENTS_PER_FEED_CEILING` is refused
 * with a warning and the usual number used — never half-obeyed.
 *
 * TWO THINGS A FIFTH ROUND OF CHECKING CHANGED IN WHAT THE ANSWER MEANS
 * --------------------------------------------------------------------
 * - **A cut list of skipped or added dates now throws away the end point.**
 *   `effectiveWindowEnd` means "everything up to here was read properly", and
 *   the importer deletes stored dates at or before it. Cutting one event's
 *   list loses dates from inside that list, and they can fall anywhere in the
 *   period — so the end point some OTHER series left behind said nothing
 *   about them. Measured: 500 real dates in 2026 missing, under an end point
 *   of February 2027.
 * - **A repeating event whose SKIPPED-date list was cut has its repeat rule
 *   thrown away**, with a warning, which is what this class already does for
 *   a repeat rule it cannot work out. A cut skipped-date list does not lose
 *   dates; it BRINGS BACK dates somebody deliberately removed. Measured: a
 *   daily series since 1985 with every weekend removed gave 365 dates in 2026
 *   where the truth is 261 — 104 cancelled days shown as going ahead.
 *   A cut ADDED-date list is deliberately not treated this way: it fails the
 *   other way round, and `capped` already covers it.
 *
 *   **What is left is the dates the file states one by one** — the series'
 *   own first date and any `RDATE` — and every one of them goes through the
 *   ordinary checks: a date in the part of the skipped list that WAS read is
 *   still dropped, a changed-date block still replaces it, and the period
 *   asked for still applies. A SIXTH round of checking found that this was
 *   NOT what the code did: it handed the first date back untouched, so a
 *   first date the calendar itself had cancelled came back as a real event,
 *   a first date moved to the afternoon came back at its original time and
 *   was reported as a duplicate, and added dates were dropped. It is fixed,
 *   and this paragraph is written the way it is because the sentence it
 *   replaced — "the same treatment" — was believed for a whole round.
 *
 *   What it still cannot promise: a date cancelled by a skipped-date line
 *   BEYOND the cut can still get through, if it is the first date, an added
 *   date, or a CHANGED date. Far fewer than leaving the rule in, but not none.
 *   The third of those was missing from this list until a seventh round of
 *   independent checking measured it, and the list is the sentence a
 *   maintainer reads — so it being short by one route mattered more than it
 *   looks. A changed date is a `RECURRENCE-ID` block. When the rule is
 *   refused, the date it was meant to replace is never produced, so the block
 *   is an orphan and is kept on its own (see `expand()`); the orphan is
 *   checked against the cancellations that WERE read, and a cancellation
 *   beyond the cut is not one of them.
 *
 *   The list is believed complete at three routes. A fourth was looked for
 *   and not found: a changed date whose cancellation WAS read is dropped, an
 *   added date that is also a cancelled date is dropped when nothing was cut,
 *   and a list that stops exactly on the allowance still sets the flag that
 *   refuses the rule. Everything else in the period comes from the repeat
 *   rule, and the rule is not run at all.
 *
 * HOW LONG AN EVENT LASTS, AND THE TWO NIGHTS A YEAR IT MATTERS
 * -------------------------------------------------------------
 * RFC 5545 §3.3.6 separates an EXACT length (hours, minutes, seconds — a fixed
 * number of seconds) from a NOMINAL one (days and weeks — the same clock
 * reading, so many days later). On the two nights the clocks change, the two
 * give different answers, and an event that spans one of them used to come
 * back an hour out in either direction with no warning at all.
 *
 * What this class does now:
 * - an explicit `DTEND` states an end MOMENT, so that moment is used. For a
 *   repeating event the same number of seconds is applied to every date, which
 *   is what RFC 5545 §3.8.5.3 asks for a length stated with `DTEND`.
 * - a `DURATION` is applied as written — days and weeks by the clock, hours
 *   and minutes and seconds by the second.
 *
 * Said plainly, because it is a choice and not a fact: calendar programs
 * disagree about the repeating case, and some keep the wall-clock reading for
 * every date instead, so on those a 23:00 to 04:00 series still shows 04:00 on
 * the night the clocks go back rather than 03:00.
 *
 * THIS IS THE OWNER'S DECISION, TAKEN ON 23 SEPTEMBER 2026, NOT THE BUILDER'S
 * JUDGEMENT. The question was put to the owner — follow RFC 5545, or keep the
 * wall-clock reading the way some calendar programs do — and the answer was to
 * follow RFC 5545, because it is the only written rule both a calendar and this
 * portal can be held to. It is written here so that nobody reopens it as an
 * open question: it is settled, and changing it now would need the owner to say
 * so. Nothing about how the code behaves changed when that decision was
 * recorded; the code already did this, and only the reason beside it changed.
 * A one-off is not affected by the choice at all.
 *
 * WHAT THIS CLASS DELIBERATELY IGNORES
 * ------------------------------------
 * - `VTIMEZONE` blocks. A calendar file may carry its own description of a
 *   zone's rules. They are ignored: the zone NAME is looked up in the system's
 *   own zone database (and, for Microsoft's Windows names, in
 *   `Portal\Core\WindowsTimeZones`), which is kept up to date by the operating
 *   system and by PHP. Trusting the file's own offsets would mean trusting a
 *   stranger's copy of the rules, which is often years out of date. What this
 *   costs: a made-up zone that exists only inside the file cannot be read, and
 *   falls back to the calendar's own zone with a warning.
 * - `X-ALT-DESC` (the HTML twin of the description) and every `X-MICROSOFT-*`
 *   property. The ordinary properties carry everything the portal shows.
 * - `ATTACH`. Attachments are counted so the importer can say "this calendar
 *   has attachments we do not import", and nothing else. No attachment data,
 *   and no address of an attachment, ever leaves this class.
 * - Everything that is not a `VEVENT`: to-dos, journal entries, free/busy
 *   blocks and alarms. An alarm sits INSIDE an event and has its own
 *   `DESCRIPTION`; reading that by mistake would put "Reminder" in the title
 *   of half the events in a calendar, so nested blocks are skipped whole.
 *
 * WHAT IT CANNOT DO
 * -----------------
 * - It cannot read a file that is not UTF-8 or plain ASCII. Real calendar
 *   feeds are UTF-8 (RFC 5545 says so). A UTF-16 file would come out as
 *   nonsense rather than an error.
 * - It does not support every repeat rule in RFC 5545. `UNSUPPORTED_RULE_PARTS`
 *   lists the BY… parts it refuses; `parseRule()` also refuses repeating every
 *   second, minute or hour, an interval below 1, a count above 50,000, and a
 *   BY… part listing more values than it could possibly use. A series using
 *   any of them keeps the dates the file states one by one — its own first
 *   date, any added date, and any changed date left without a slot — and
 *   loses the dates the repeat pattern would have produced, with a warning,
 *   rather than being read wrongly. This used to be written "imported as its
 *   first date only" in seven places, which was never true of this path.
 * - It cannot tell whether a date is one the viewer may see. That is
 *   `Portal\Core\EventVisibility` (part P1). This class only reads.
 * - It has never been run against a real Google or Microsoft 365 calendar in
 *   this repository's own checks. Every test uses hand-written files (owner
 *   decision, 21 September 2026), so "works against a real Google or
 *   Microsoft 365 calendar" is NOT proven by anything here.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class IcsReader
{
    // =========================================================================
    // 📏 Limits — each ends the work with a warning rather than an error
    //    (the memory guard is the one with a caveat: see the file header)
    // =========================================================================

    /**
     * Most dates one repeating event may contribute inside the window asked for.
     *
     * This counts EVERY date the event contributes, however it was produced:
     * dates worked out from its repeat rule, and dates the calendar added one
     * by one with `RDATE`. It used to be applied only inside the repeat-rule
     * worker, so an event with no rule at all but a hundred thousand `RDATE`
     * lines walked straight past it and used more memory than PHP allows —
     * a fatal error, from a 1.6 MB file. The comment above it still said
     * "most dates one repeating event may contribute", which was simply not
     * true of that shape.
     */
    public const MAX_OCCURRENCES_PER_SERIES = 400;

    /**
     * Most dates one calendar file may contribute in total — the DEFAULT.
     *
     * This is the one limit a customer may change. The owner decided that on
     * 23 September 2026, and the reason matters more than the number: a
     * calendar that reaches this limit comes back `capped`, and a capped
     * answer tells the importer not to tidy up dates that have genuinely gone
     * from the feed. So a large school or cathedral whose calendar sits above
     * the ceiling never has anything tidied away, and stale entries pile up
     * for ever. Two thousand is right for an ordinary organisation and wrong
     * for a big one, and only the customer knows which they are.
     *
     * The reader itself reads no settings and opens no database connection —
     * that is what lets it be self-tested at all — so the number arrives as
     * the last argument to `expand()`. Leaving that argument out gives exactly
     * this number, and exactly the behaviour every earlier round measured.
     *
     * **Part P6 owns the other half**: seeding the setting, reading it for the
     * site being refreshed, and passing it in. Nothing here reads a setting.
     */
    public const MAX_EVENTS_PER_FEED = 2000;

    /**
     * The most this class will ever accept for the limit above, whatever it is
     * handed.
     *
     * Why there has to be a ceiling on the ceiling: the number comes from a
     * setting, a setting is edited by a person, and a person can type 1000000.
     * The dates gathered before the list is cut are held in memory all at
     * once, so obeying that number would trade a `capped` answer — which is
     * survivable — for a fatal "allowed memory size exhausted", which is not,
     * because it cannot be caught and it stops every calendar queued behind
     * this one.
     *
     * Ten thousand, and the number is measured rather than chosen for looking
     * round. Two measurements, both on PHP 8.5.10:
     *
     *  - one gathered date, built exactly as `expand()` keeps it and weighed
     *    with `memory_get_usage(true)`: 200,000 of them came to 174 MB, which
     *    is **912 bytes each**. Gathering allows three times the ceiling (see
     *    `MAX_COLLECTED_OCCURRENCES`), so at ten thousand the gathered list
     *    alone is 30,000 dates, about 26 MB;
     *  - and then the whole thing, end to end, rather than the arithmetic: a
     *    calendar of 85 never-ending daily series (31,025 dates offered, more
     *    than the gathering allows) read at this ceiling **peaks at 52.0 MB
     *    and takes 0.22 seconds**, and gives back 10,000 dates. The same
     *    calendar at the default peaks at 14.0 MB. The gap between 26 and 52
     *    is the second copy of the list made while repeats are dropped and the
     *    dates are put in order, which the arithmetic above does not count —
     *    which is exactly why it was measured.
     *
     * 52 MB sits inside the four fifths of PHP's allowance `expand()` may use
     * (102 MB of the usual 128 MB). Five times the default is also far more
     * than any one organisation's calendar holds in one window.
     *
     * Said plainly, because this is a promise it would be easy to overstate:
     * at the very top of this range, on a host with only the usual 128 MB, a
     * calendar that ALSO fills the three retained-list budgets will run the
     * memory watch out before it reaches ten thousand dates. That is the watch
     * doing its job — a warning and a `capped` answer — not a fault. The
     * ceiling bounds what a SETTING can ask for; the memory watch is what
     * bounds what a particular host can actually do.
     *
     * A value outside 1 to this number is refused: the default is used
     * instead and a warning says so. Refusing rather than quietly clamping is
     * the same choice this class makes everywhere else — an administrator who
     * typed something impossible should be told, not silently half-obeyed.
     */
    public const MAX_EVENTS_PER_FEED_CEILING = 10000;

    /**
     * How many dates are gathered for every one that may be kept.
     *
     * Three, and the reasoning is under `MAX_COLLECTED_OCCURRENCES`. It is a
     * constant of its own because the gathering limit has to follow the
     * ceiling when a customer raises it — otherwise raising the ceiling to
     * five thousand would give back exactly the same two thousand dates, and
     * the setting would look broken.
     */
    private const COLLECT_MULTIPLE = 3;

    /**
     * Most steps the repeat-rule worker may take for one series. A "step" is
     * one period looked at (one day, week, month or year), not one date
     * produced. A series that starts in the year 1 and repeats daily needs
     * about 740,000 steps to reach today; this stops it long before that.
     */
    public const MAX_EXPANSION_STEPS = 50000;

    /** Most categories kept for one event. */
    public const MAX_CATEGORIES_PER_EVENT = 20;

    /**
     * Most VEVENT blocks `parse()` will hold on to from one file.
     *
     * Three times the number of dates a whole calendar may contribute, because
     * a calendar legitimately holds far more events than end up inside the
     * window asked for — old ones, future ones, and ones belonging to another
     * period entirely. Three times leaves room for that and still stops a file
     * of a hundred thousand events from being held in memory all at once.
     *
     * Reaching this is reported as a warning and `complete = false`, which
     * means the same thing to the importer as a download that was cut off: do
     * not treat what came back as the whole calendar, and do not delete
     * anything for being missing from it.
     *
     * This number does NOT follow the per-feed ceiling a customer may raise.
     * It belongs to `parse()`, which takes no such argument, and it counts
     * EVENT BLOCKS in the file rather than dates in the window — a different
     * thing. Six thousand blocks can produce far more than six thousand dates,
     * because one block may be a series. A customer who raises the ceiling and
     * whose file really does hold more than six thousand blocks still gets
     * `complete = false`, and the importer still tidies nothing away. That is
     * recorded here rather than fixed, because making `parse()` take the same
     * argument would mean a second place to get it wrong for a case nobody has
     * yet met.
     */
    public const MAX_EVENT_BLOCKS_PER_FILE = 3 * self::MAX_EVENTS_PER_FEED;

    /**
     * Most dates `expand()` will gather before it stops gathering.
     *
     * This is NOT the same limit as `MAX_EVENTS_PER_FEED`. That one is applied
     * at the very end, to the finished list; this one stops the gathering that
     * produces the list. Without it, a 95 KB file holding 500 never-ending
     * daily series asks for 200,000 dates to be built and sorted before 2,000
     * of them are kept — measured at more memory than PHP's usual limit, which
     * is a fatal error and cannot be caught. Three times the number kept
     * leaves ordinary calendars untouched: a calendar of 2,500 events, or 100
     * weekly series over a year, is nowhere near it.
     *
     * What it costs, plainly: a calendar far over the limit has its dates cut
     * off partly in the order the FILE listed them, rather than purely by
     * date. The result is marked `capped` with no end point, which the caller
     * is told to treat as "this does not reliably cover the period", so
     * nothing is deleted on the strength of it either way.
     *
     * It is checked before EACH event, not once per UID as it first was. A
     * calendar may give two hundred and fifty never-ending series the SAME
     * UID; checking once per UID then never checked any of them, and a 31 KB
     * file of that shape used up all the memory PHP allows. That was found by
     * a second independent check, after the first fix had already been made
     * and measured — which is a fair warning that "the shape I measured is now
     * safe" is not the same as "this is bounded".
     *
     * This is the gathering limit for the DEFAULT ceiling. When a customer
     * raises the per-feed ceiling, `expand()` works out its own gathering
     * limit as `COLLECT_MULTIPLE` times whatever ceiling it was given, so the
     * pair keeps its meaning; with no ceiling given the two are the same
     * number and the behaviour is exactly what it was before the ceiling could
     * be changed at all.
     */
    public const MAX_COLLECTED_OCCURRENCES = self::COLLECT_MULTIPLE * self::MAX_EVENTS_PER_FEED;

    /**
     * Most lines `parse()` will read from one file.
     *
     * Why a count of LINES and not simply a count of bytes: the lines are held
     * as a PHP array, and an array costs memory per ENTRY as well as per
     * character. A file of nothing but line endings is tiny on disk and
     * enormous in memory — 4 MB of them asked PHP for 128 MB in one go, which
     * is a fatal error that cannot be caught, and it happened before any of
     * the checks further down had run even once.
     *
     * The number: `SafeFetch` refuses a body over 5 MB. RFC 5545 asks a writer
     * to fold its lines at 75 characters, so a 5 MB file written that way holds
     * about 70,000 lines; even one made entirely of the shortest line a real
     * calendar contains (`END:VEVENT`) holds about 440,000. Half a million
     * therefore leaves every real file untouched while still bounding the
     * array.
     *
     * Reaching it is reported the same way as reaching the event limit: a
     * warning, and `complete = false`.
     *
     * What it costs, measured rather than guessed: a file that folds one long
     * property into lines of only a few characters each is legal and is now
     * cut short. A 5 MB description folded into 1.3 million seven-byte lines
     * used to be read in full, at a peak of 90 MB out of the 128 MB PHP
     * usually allows — too close to be comfortable — and now stops with the
     * warning instead. Nothing writes calendars that way; if something is ever
     * found that does, raise this number rather than removing it, and measure
     * the peak again.
     */
    public const MAX_LINES_PER_FILE = 500000;

    /**
     * Most skipped dates (`EXDATE`) or added dates (`RDATE`) read from ONE
     * event.
     *
     * These lists are not the same thing as the dates an event contributes, so
     * they do not share `MAX_OCCURRENCES_PER_SERIES`. Skipped dates build up
     * over the whole life of a series — a daily meeting running since 2015 may
     * carry thousands — while only the ones inside the window asked for matter.
     * Cutting such a list at 400 would quietly bring deleted dates back, and
     * the ones that matter are usually at the END of the list, where a cut
     * lands.
     *
     * Ten times the per-event date limit is far beyond anything a real
     * calendar writes, and still bounds the work: a calendar that put a
     * hundred thousand added dates on one event built a hundred thousand date
     * objects and killed the process outright.
     *
     * What it cannot do: when a list really is longer than this, the later
     * entries are not read, so a skipped date could reappear or an added date
     * go missing. That is why hitting it marks the answer `capped` as well as
     * warning — the caller is told not to delete anything on the strength of
     * it.
     */
    public const MAX_DATE_LIST_PER_EVENT = 10 * self::MAX_OCCURRENCES_PER_SERIES;

    /**
     * How much of the memory PHP allows this process each step may use before
     * it stops and says so.
     *
     * Why two different shares, and why so far below the whole limit: reading
     * and working out the dates are two piles of memory that exist AT THE SAME
     * TIME. `expand()` builds its dates while `parse()`'s properties are still
     * held, because it is reading them. Measured on PHP 8.5 with a plain file
     * of 20,000 events, reading it used 81 MB and working out the dates took
     * the total to 144 MB — nearly double. So reading may use half, leaving
     * the other half for the step that has to follow it.
     *
     * The remaining fifth above `expand()`'s share is not spare: it is the
     * room needed between two checks. The checks happen every so many lines or
     * events, never on every allocation, so the process has to be able to
     * carry on to the next check without running out.
     */
    private const MEMORY_SHARE_FOR_PARSE  = 0.5;
    private const MEMORY_SHARE_FOR_EXPAND = 0.8;

    /**
     * Repeat-rule parts this class does not support. A series using any of
     * them keeps the dates the file states one by one and loses the dates the
     * repeat pattern would have produced, with a warning.
     *
     * Why refuse rather than approximate: reading `BYWEEKNO` as "roughly the
     * same week" would put events on the wrong day and nobody would ever
     * notice. One date and a warning is honest.
     */
    public const UNSUPPORTED_RULE_PARTS = ['BYHOUR', 'BYMINUTE', 'BYSECOND', 'BYWEEKNO', 'BYYEARDAY'];

    /** Repeat frequencies that are supported. SECONDLY, MINUTELY and HOURLY are not. */
    private const SUPPORTED_FREQ = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /** A `COUNT` larger than this is treated as unsupported (see above). */
    private const MAX_RULE_COUNT = 50000;

    /**
     * How many values each `BY…` part of a repeat rule may list before the
     * rule is refused.
     *
     * These are not guesses at what is reasonable: they are the number of
     * DIFFERENT values each part can possibly hold. `BYMONTH` picks months, so
     * twelve. `BYMONTHDAY` picks days of the month, counting forwards 1 to 31
     * or backwards -1 to -31, so sixty-two. `BYSETPOS` picks a position within
     * a year at most, forwards or backwards, so 732. `BYDAY` names a weekday
     * with an optional number in front of it, from -53 to 53, so 7 × 107.
     *
     * Anything longer is repetition, and repetition is what makes it
     * dangerous: `BYDAY=MO,MO,MO,…` three hundred thousand times is a 900 KB
     * file that used up all the memory PHP allows before it had even finished
     * reading the rule. The list is measured before it is split apart, so the
     * refusal costs nothing.
     *
     * A refused rule is treated like every other rule this class does not work
     * out: the dates the file states one by one are kept, the dates the repeat
     * pattern would have produced are lost, and a warning says so.
     */
    private const MAX_RULE_LIST_ENTRIES = [
        'BYDAY'      => 749,
        'BYMONTHDAY' => 62,
        'BYMONTH'    => 12,
        'BYSETPOS'   => 732,
    ];

    /**
     * Most settings ("parameters") one property line may carry before the
     * whole line is left out.
     *
     * A property line is written `NAME;SETTING=VALUE;SETTING=VALUE:the value`,
     * and the part before the colon is split on its semicolons. RFC 5545
     * defines about twenty settings altogether, and no real calendar puts more
     * than a handful on one line, so sixty-four is far beyond anything
     * legitimate.
     *
     * Why there has to be a limit at all: the splitting builds one piece of
     * text per semicolon, and it used to happen before anything had counted
     * them. One line carrying five and a quarter million semicolons — in a
     * file small enough for the fetcher to accept — asked PHP for more memory
     * than it allows and killed the process outright. That is a fatal error,
     * which cannot be caught, so an importer refreshing several calendars in
     * turn stopped dead and left every calendar after it unrefreshed with
     * nothing in the log to explain it.
     *
     * A line over the limit is left out with a warning, and the read is
     * reported as `complete = false`, which tells the caller the same thing a
     * cut-off download does: this is not all of the calendar, so do not delete
     * anything for being missing from it.
     *
     * Public because the self-test builds its control from it: a line carrying
     * EXACTLY this many settings must still be read normally, which is what
     * stops the limit being quietly lowered to something useless.
     */
    public const MAX_PARAMS_PER_PROPERTY = 64;

    /**
     * Most parts one repeat rule may be made of before the rule is refused.
     *
     * A repeat rule is written `FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10` and is split
     * on its semicolons. RFC 5545 defines fourteen parts; thirty-two leaves
     * room for the extensions some calendars add (`RSCALE`, `SKIP`, `X-`
     * parts) and still bounds the work.
     *
     * The same reason as the limit above: the split ran before anything
     * counted the semicolons, and a rule of five and a quarter million of them
     * used all the memory PHP allows. `MAX_RULE_LIST_ENTRIES` did not help,
     * because those numbers count the commas INSIDE one part's value and never
     * see the semicolons BETWEEN the parts — which is exactly the kind of gap
     * that makes "we measured this shape" different from "this is bounded".
     *
     * A rule over the limit is treated like every other rule this class does
     * not work out: the dates the file states one by one are kept, the dates
     * the repeat pattern would have produced are lost, and a warning says so.
     */
    private const MAX_RULE_PARTS = 32;

    /**
     * Longest a time-zone name may be, and the most parts it may be made of.
     *
     * A time-zone name is something like `Europe/London`, `GMT Standard Time`,
     * or the form Thunderbird writes,
     * `/mozilla.org/20050126_1/Europe/London`. The longest name in the world's
     * zone database is thirty-two characters and the longest Windows name is
     * about thirty, so two hundred characters is generous beyond any real use.
     *
     * Both numbers are needed, for different reasons. The LENGTH bounds the
     * work done on the text: the name is matched against a pattern, looked up
     * in the Windows list, and — when it is not recognised — printed into a
     * warning, and none of that should ever run over megabytes. The SEGMENT
     * count bounds the ARRAY: a name containing slashes is split on them so
     * that the longest ending which IS a real zone can be tried, and that
     * split used to run before anything counted the slashes. A `TZID` of two
     * and a half million slashes, inside a file small enough for the fetcher
     * to accept, used every byte PHP allows. Behind the memory cost sat a
     * second one: the search tried every possible ending in turn, which is
     * work proportional to the SQUARE of the number of segments.
     *
     * A name over either limit is treated exactly like a name PHP does not
     * know: the calendar's own zone is used, and a warning says so. The
     * warning does not quote the name, because the name is the thing that was
     * too big.
     */
    private const MAX_ZONE_NAME_LENGTH   = 200;
    private const MAX_ZONE_NAME_SEGMENTS = 16;

    /**
     * Most pieces read from one event's `CATEGORIES` lines.
     *
     * Only twenty categories are ever kept (`MAX_CATEGORIES_PER_EVENT`), but
     * the raw pieces are cleaned first, and empty or repeated ones are then
     * dropped — so the raw list has to be allowed to be longer than the
     * finished one. Ten times the number kept is far beyond anything a person
     * types.
     *
     * Why it exists: the splitting built one piece of text per comma AND then
     * copied every piece into a second list, neither of them bounded, and only
     * the finished result was ever cut to twenty. A `CATEGORIES` line of five
     * and a quarter million bare commas killed the process; a line of that
     * many real short categories reached 116 MB of the 128 MB PHP usually
     * allows, which is far too close once an importer's own work is counted
     * as well.
     */
    public const MAX_CATEGORY_LIST_PER_EVENT = 10 * self::MAX_CATEGORIES_PER_EVENT;

    /**
     * THE COMPANION RULE TO THE ONE IN THE FILE HEADER, AND WHY THESE THREE
     * NUMBERS EXIST:
     *
     *     A LIMIT THAT BOUNDS ONE OF SOMETHING MUST BE MATCHED BY SOMETHING
     *     THAT BOUNDS THE SUM OF ALL OF THEM.
     *
     * `MAX_DATE_LIST_PER_EVENT` and `MAX_RULE_LIST_ENTRIES` bound what ONE
     * event may keep. Nothing bounded what SIX THOUSAND events keep between
     * them, and each of those lists is held for the whole of `expand()`. A
     * fourth round of independent checking killed the process with two files
     * inside the fetcher's 5 MB limit — ninety-nine events each listing
     * exactly the four thousand added dates the per-event limit allows, and
     * seven hundred events each listing the 749 different `BYDAY` values the
     * per-event limit allows. A per-event limit of four thousand is
     * meaningless on its own when six thousand events are held at once.
     *
     * These three numbers are set from MEASURED costs, not from judgement.
     * Two hundred thousand of each kind were built exactly as this class keeps
     * them and weighed with `memory_get_usage(true)` on PHP 8.5:
     *
     *   - one added date (`RDATE`) becomes a `DateTimeImmutable` — 388 bytes
     *     from a 9-byte piece of the file, a forty-three-fold blow-up;
     *   - one skipped date (`EXDATE`) becomes an array KEY — 52 bytes;
     *   - one `BYDAY` value becomes a small array — 357 bytes; a
     *     `BYMONTHDAY`, `BYMONTH` or `BYSETPOS` value is a plain whole number
     *     — 21 bytes, so `BYDAY` is the one the limit is sized for.
     *
     * So the worst a whole file can retain in these three lists is
     * 30,000 x 388 + 200,000 x 52 + 40,000 x 357 bytes — about
     * 11.4 + 10.4 + 13.6 = **35 MB**. That sits inside the four fifths of
     * PHP's allowance `expand()` may use (102 MB of the usual 128 MB) with
     * room for the reading step's own memory beside it. Measured rather than
     * left as arithmetic: a 5.2 MB file built to reach all three budgets at
     * once — 130 events, each with 2,000 added dates, 2,000 skipped dates and
     * a full set of 749 `BYDAY` values — peaks at **61 MB**, of which about
     * 36 MB is these three lists and the rest is the reading step still
     * holding the file's properties. Before these budgets existed the same
     * file peaked at 127.0 MB of the 128 MB PHP allows.
     *
     * And the generosity, the other way round: a whole feed may contribute
     * 2,000 dates (`MAX_EVENTS_PER_FEED`). These allow fifteen added dates,
     * a hundred skipped dates and twenty repeat-rule values for every one of
     * those. The two ordinary calendars this was tested against — a 117 KB
     * church export of 321 events and a 48 KB school export of 172 — retain
     * about a hundred entries altogether, three orders of magnitude below.
     * A school timetable
     * of two thousand weekday lesson series, each cancelled for twenty
     * half-term days, retains 10,000 rule values and 40,000 skipped dates,
     * still comfortably inside.
     *
     * Reaching one of them cuts the later events' lists and marks the answer
     * `capped` with a warning — "this is not all of it, do not delete
     * anything" — which is what every other cut in this class does.
     *
     * The added-date and skipped-date budgets are counted in PIECES READ, the
     * same unit as `MAX_DATE_LIST_PER_EVENT`, so the two read as one limit
     * rather than two that have to be reconciled. Pieces read is never less
     * than entries kept, so the memory figures above are an upper bound.
     *
     * Public because the self-test builds its controls from them: a file that
     * retains EXACTLY this many entries must still be read normally, which is
     * what stops a limit being quietly lowered to something useless.
     */
    public const MAX_RETAINED_ADDED_DATES   = 30000;
    public const MAX_RETAINED_SKIPPED_DATES = 200000;
    public const MAX_RETAINED_RULE_VALUES   = 40000;

    /**
     * Largest `INTERVAL` a repeat rule may use before the rule is refused.
     *
     * RFC 5545 puts no upper bound on `INTERVAL`, and `(int)` turns any
     * absurdly long number into `PHP_INT_MAX` rather than refusing it. The
     * arithmetic that moves to the next period then overflows into a floating
     * point number, and the failure is NOT the quiet warning this class
     * promises everywhere else: `FREQ=WEEKLY;INTERVAL=99999999999999999999`
     * made `7 * $interval` a float and `modify()` threw
     * `DateMalformedStringException`, and `FREQ=MONTHLY` with the same value
     * overflowed the month counter and `intdiv()` threw `TypeError`. A caller
     * told to expect `RuntimeException('time budget')` and nothing else would
     * have let either of those end the whole import.
     *
     * Ten thousand is far beyond anything a person writes ("every other
     * week", "every third month") and far below the point where any of the
     * arithmetic can overflow — the largest product of it is
     * 7 x 10,000 = 70,000 days. A rule over it is treated exactly like a
     * `COUNT` over 50,000: the dates the file states one by one are kept, the
     * dates the repeat pattern would have produced are lost, and a warning
     * says so.
     */
    private const MAX_RULE_INTERVAL = 10000;

    /**
     * The last moment an end date may be, because of where it is going next.
     *
     * The importer (part P6) writes these into a MySQL `DATETIME` column, and
     * MySQL's `DATETIME` stops at the end of year 9999. This class's own
     * `makeDate()` already refuses a START outside years 1 to 9999, but the
     * END is worked out by adding a length to the start, and nothing checked
     * it: `DURATION:P999999999999W` gave an end in the year 19,165,351,075,
     * and `DTEND:99991231T235959Z` read in London gave 10000-01-01 00:59:59 —
     * one second past what can be stored. The importer would have failed to
     * write the row, which is exactly the "failed import" this class promises
     * never to cause.
     */
    private const LAST_STORABLE_END = '9999-12-31 23:59:59';

    /**
     * Most warnings one read records.
     *
     * A warning is text built from what the file says, so the FILE decides how
     * many there are. That makes the list of warnings one more thing a
     * calendar can grow without limit, and it is the one that hid the longest,
     * because nothing about it looks like reading a calendar.
     *
     * Two things bound it now. Identical messages are dropped as they are
     * added (see `note()`), and the list stops at this many altogether. An
     * unknown time zone used to be recorded once for the start, once for the
     * end and once for EVERY skipped date in a series: one event with a
     * made-up zone name and five and a quarter million skipped dates built
     * 327,680 copies of the same sentence and reached 114 MB of the 128 MB PHP
     * usually allows. A de-duplication at the very end of `expand()` then
     * reduced them all to one — correct, and far too late to stop the memory
     * being taken. That is why the de-duplication now happens as each warning
     * is added instead.
     *
     * A hundred different warnings is already more than anybody reads; past
     * that, one last line says that the rest were left out.
     */
    public const MAX_WARNINGS = 100;

    /** Longest each cleaned field may be, in characters (matching the database columns). */
    private const MAX_TITLE       = 255;
    private const MAX_LOCATION    = 255;
    private const MAX_DESCRIPTION = 5000;
    private const MAX_URL         = 500;
    private const MAX_CATEGORY    = 100;

    /** Shown instead of an empty title, so a date is never nameless on a page. */
    private const UNTITLED = '(Untitled)';

    /** Weekday two-letter codes, in RFC 5545's order, mapped to PHP's 1 (Monday) to 7 (Sunday). */
    private const WEEKDAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    /**
     * Properties kept from a VEVENT. Everything else in the block is dropped
     * as it is read, so a calendar cannot make this class hold on to
     * unbounded amounts of anything.
     *
     * Three of them — `CATEGORIES`, `EXDATE` and `RDATE` — carry a
     * comma-separated LIST in one value, and each is split where it is read in
     * `prepareEvent()`. There is deliberately no constant listing them: one
     * that nothing consulted sat here at first and was simply a claim.
     */
    private const KEPT_PROPERTIES = [
        'UID', 'SUMMARY', 'DESCRIPTION', 'LOCATION', 'URL', 'CATEGORIES', 'CLASS', 'STATUS',
        'DTSTART', 'DTEND', 'DURATION', 'RRULE', 'RDATE', 'EXDATE', 'RECURRENCE-ID', 'ATTACH',
    ];

    // =========================================================================
    // 📣 Warnings — the one list the FILE decides the length of
    // =========================================================================

    /**
     * Record a warning: once each, and never more than `MAX_WARNINGS` of them.
     *
     * Every other list in this class is bounded by a count of events, dates or
     * lines. This one is bounded by nothing a calendar cannot choose, because
     * a calendar decides how many things are wrong with it. One event naming a
     * made-up time zone and listing five and a quarter million skipped dates
     * produced one warning per skipped date — the same sentence, 327,680
     * times, reaching 114 MB of the 128 MB PHP usually allows. There WAS a
     * de-duplication, at the very end of `expand()`; it reduced them all to
     * one, long after the memory had been taken. Doing it here instead is the
     * whole fix: nothing is written down twice in the first place.
     *
     * The order of the three tests matters. The cap is checked first so that a
     * flood costs one comparison once the list is full. Repeats are dropped
     * next, so a repeat arriving when the list is nearly full cannot trigger
     * the "the rest were left out" line — saying something was left out when
     * nothing was is the mistake this codebase has already had to correct at
     * two other limits.
     *
     * What it cannot do: it compares the finished sentences, so two warnings
     * about two DIFFERENT made-up zone names are two warnings, as they should
     * be — but a calendar that invents a hundred different zone names will
     * fill the list with them and push other warnings out. The cap is what
     * stops that costing memory; nothing can stop it costing attention.
     *
     * @param list<string> $warnings Added to in place.
     */
    private static function note(array &$warnings, string $text): void
    {
        if (count($warnings) >= self::MAX_WARNINGS) {
            return;
        }
        if (in_array($text, $warnings, true) === true) {
            return;
        }
        if (count($warnings) === self::MAX_WARNINGS - 1) {
            // Room for one more line, and it is spent saying that the list
            // stops here — otherwise the last thing an administrator reads
            // would look like the last thing that went wrong.
            $warnings[] = 'This calendar produced more warnings than the portal records ('
                . self::MAX_WARNINGS . '), so the rest were left out.';

            return;
        }

        $warnings[] = $text;
    }

    // =========================================================================
    // 📖 Step one: read the file into properties
    // =========================================================================

    /**
     * Read a calendar file into its events' properties.
     *
     * This step does no interpreting at all: no time zones, no repeats, no
     * cleaning. That is `expand()`. Keeping them apart means a file that is
     * cut off half way still gives the caller everything that did arrive,
     * together with `complete = false` so it knows not to treat the result as
     * the whole calendar (an importer that deleted "everything missing from
     * the file" on a truncated download would wipe a calendar).
     *
     * `complete = false` means the SAME thing in every other case where this
     * method does not read all of the file: too many lines, too many events to
     * hold, not enough memory left to go on, or a single line so malformed
     * that it was thrown away (a property line carrying more settings than any
     * calendar writes). They all say "this is not all of it", which is the
     * only thing the caller has to act on, and a warning says which of them
     * happened. A caller must read `complete` as well as `expand()`'s
     * `capped`: the two cover different halves of the work, and a file that is
     * cut short here can still expand without any cap being reached.
     *
     * @param  string $body     The file as it was downloaded.
     * @param  float  $deadline A moment from `microtime(true)`. Passing it
     *                          throws RuntimeException('time budget').
     * @return array{complete:bool, events:list<array<string,list<array{value:string,params:array<string,string>}>>>, warnings:list<string>}
     *         `events` is one entry per VEVENT: property name (in capitals) =>
     *         the list of times that property appeared, each with its raw
     *         value and its parameters.
     */
    public static function parse(string $body, float $deadline): array
    {
        $warnings = [];

        // A byte-order mark is three invisible bytes some programs put at the
        // very start of a file. Left in place it becomes part of the first
        // property name, so `BEGIN` is never recognised and the whole file
        // reads as empty.
        if (str_starts_with($body, "\xEF\xBB\xBF") === true) {
            $body = substr($body, 3);
        }

        // RFC 5545 says lines end with carriage-return + line-feed. Plenty of
        // real feeds use line-feed alone, and a few use carriage-return alone,
        // so all three are accepted.
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        // "Unfolding": a long line may be split, with every continuation line
        // starting with one space or tab. Those continuation lines are joined
        // back on before anything else looks at them, because a fold can fall
        // in the middle of a word, a date or even a value like PRI/VATE.
        //
        // The file is walked one line ending at a time rather than handed to
        // `explode("\n", $body)`. That used to be the first thing this method
        // did, and it was the single most dangerous line in the class: it
        // built an array holding EVERY line of the file before any check in
        // this method had run once. A 4 MB file of nothing but line endings
        // made PHP ask the operating system for 128 MB in one go and die with
        // a fatal error, which cannot be caught, so an importer refreshing
        // several calendars stopped dead on that one file. Walking the file
        // instead means only one array is ever built — the unfolded lines —
        // and the count and the memory watch below both apply to it as it
        // grows.
        $lines = [];
        $lineCount = 0;
        // Set when we stop part way through on purpose. What was read is still
        // handed back, exactly as it is for a download that was cut off.
        $stoppedEarly = false;
        // Set when a LINE was thrown away because it could not be read safely.
        // It is not the same thing as `$stoppedEarly`: we did not stop, we
        // carried on past it. But what comes back is still not everything the
        // file held, so `complete` is false either way and the caller treats
        // it exactly as it treats a download that was cut off.
        $droppedLine = false;
        $bodyLength = strlen($body);
        $offset     = 0;
        while ($offset <= $bodyLength) {
            $breakAt = strpos($body, "\n", $offset);
            if ($breakAt === false) {
                // The last line of a file that does not end with a line ending.
                $rawLine = substr($body, $offset);
                $offset  = $bodyLength + 1;
                if ($rawLine === '') {
                    // There is nothing after the final line ending, and a file
                    // that ends properly always lands here. Counting that
                    // nothing as a line made a file of EXACTLY
                    // `MAX_LINES_PER_FILE` real lines report itself as cut
                    // short: the administrator was told "the rest were left
                    // out" when there was no rest. The same off-by-one was
                    // found and corrected at the limit on how many events one
                    // file may hold, and again at the limit on how many dates
                    // one series may contribute.
                    break;
                }
            } else {
                $rawLine = substr($body, $offset, $breakAt - $offset);
                $offset  = $breakAt + 1;
            }

            $lineCount++;
            if ($lineCount > self::MAX_LINES_PER_FILE) {
                $stoppedEarly = true;
                self::note(
                    $warnings,
                    'This calendar has more lines than the portal reads from one file ('
                    . self::MAX_LINES_PER_FILE . '), so the rest were left out.'
                );
                break;
            }
            // The clock is checked on EVERY line and the memory every five
            // hundred, and the two are deliberately different. Asking the time
            // costs 20 nanoseconds, so half a million lines is 10
            // milliseconds; asking how much memory is in use has to read and
            // pick apart PHP's `memory_limit` setting first, which was
            // measured at 86 nanoseconds, and a single line cannot take much
            // memory on its own. The reason the clock is the one that moved to
            // every line: a single line CAN take a long time, because a
            // property line may be megabytes long.
            if (microtime(true) > $deadline) {
                throw new RuntimeException('time budget');
            }
            if (($lineCount % 500) === 0) {
                if (self::memoryRunningOut(self::MEMORY_SHARE_FOR_PARSE) === true) {
                    $stoppedEarly = true;
                    self::note(
                        $warnings,
                        'This calendar is too large for the portal to hold all at once, '
                        . 'so only the part of it that had been read was used.'
                    );
                    break;
                }
            }
            if ($rawLine !== '' && ($rawLine[0] === ' ' || $rawLine[0] === "\t") && $lines !== []) {
                $lines[count($lines) - 1] .= substr($rawLine, 1);
                continue;
            }
            $lines[] = $rawLine;
        }

        // The unfolded lines now hold everything the rest of this method needs,
        // so the copy of the file that was needed to build them is let go here
        // rather than at the end. For a file at the fetcher's 5 MB limit that
        // is several megabytes handed back before the expensive part starts,
        // which is several megabytes the next step does not have to do
        // without.
        unset($body);

        $sawCalendarStart = false;
        $sawCalendarEnd   = false;

        /** @var list<string> $stack The blocks we are inside, outermost first. */
        $stack = [];
        /** @var array<string,list<array{value:string,params:array<string,string>}>>|null $current */
        $current = null;
        $events  = [];

        $checked = 0;
        foreach ($lines as $line) {
            $checked++;
            // Every line, for the same reason as the walk above: reading ONE
            // property line can be expensive when the line is megabytes long.
            if (microtime(true) > $deadline) {
                throw new RuntimeException('time budget');
            }
            if (($checked % 500) === 0) {
                if (self::memoryRunningOut(self::MEMORY_SHARE_FOR_PARSE) === true) {
                    $stoppedEarly = true;
                    self::note(
                        $warnings,
                        'This calendar is too large for the portal to hold all at once, '
                        . 'so only the part of it that had been read was used.'
                    );
                    break;
                }
            }
            if ($line === '') {
                continue;
            }

            $property = self::parsePropertyLine($line, $warnings, $droppedLine);
            if ($property === null) {
                // A line with no colon at all is not a property. Real feeds
                // have a few (stray blank-ish lines from a bad exporter), and
                // stopping over one would lose the whole calendar.
                continue;
            }

            $name  = $property['name'];
            $value = $property['value'];

            if ($name === 'BEGIN') {
                $block = strtoupper(trim($value));
                $stack[] = $block;
                if ($block === 'VCALENDAR') {
                    $sawCalendarStart = true;
                }
                if ($block === 'VEVENT' && count($stack) >= 1) {
                    $current = [];
                }
                continue;
            }

            if ($name === 'END') {
                $block = strtoupper(trim($value));
                if ($block === 'VCALENDAR') {
                    $sawCalendarEnd = true;
                }
                if ($block === 'VEVENT' && $current !== null) {
                    // A second, simpler guard beside the memory one above, for
                    // the case the memory guard cannot cover: a host with no
                    // memory limit at all, where nothing else would ever stop
                    // this. It also makes the behaviour the same on every host
                    // for the sizes that matter, instead of depending on what
                    // that host's limit happens to be.
                    //
                    // The check happens BEFORE this event is kept, not after.
                    // Written the other way round — keep it, then stop if the
                    // limit has been reached — a file holding EXACTLY the
                    // limit's worth of events was reported as cut short and
                    // the administrator was told "the rest were left out" when
                    // there was no rest. Checking first means the warning is
                    // only ever raised by an event that really was left out:
                    // the 6,001st. (The same mistake was fixed at the other
                    // limit, the one on dates in a single series, one round of
                    // checking earlier.)
                    if (count($events) >= self::MAX_EVENT_BLOCKS_PER_FILE) {
                        $stoppedEarly = true;
                        self::note(
                            $warnings,
                            'This calendar holds more events than the portal reads from one file ('
                            . self::MAX_EVENT_BLOCKS_PER_FILE . '), so the rest were left out.'
                        );
                        break;
                    }
                    $events[] = $current;
                    $current  = null;
                }
                // Pop the innermost matching block. A file with mismatched
                // BEGIN/END lines is broken; popping only on a match keeps the
                // stack honest rather than unwinding blocks that never ended.
                $last = count($stack) > 0 ? $stack[count($stack) - 1] : null;
                if ($last === $block) {
                    array_pop($stack);
                }
                continue;
            }

            // Only properties directly inside a VEVENT are kept. An alarm
            // (VALARM) sits inside the event and has its own DESCRIPTION and
            // SUMMARY; reading those would rename the event "Reminder".
            if ($current === null) {
                continue;
            }
            $innermost = count($stack) > 0 ? $stack[count($stack) - 1] : '';
            if ($innermost !== 'VEVENT') {
                continue;
            }

            if (in_array($name, self::KEPT_PROPERTIES, true) === false) {
                continue;
            }

            if (isset($current[$name]) === false) {
                $current[$name] = [];
            }
            $current[$name][] = ['value' => $value, 'params' => $property['params']];
        }

        if ($current !== null && $stoppedEarly === false) {
            // A VEVENT that never ended: the download was cut off inside it.
            // What arrived is kept, and `complete` below is false anyway.
            //
            // When WE stopped reading on purpose instead — too large, too many
            // lines, or too many events — whatever block was open is
            // deliberately dropped, and this warning is not raised: the file
            // did not end there, so it would be untrue. Stopping for size or
            // for lines leaves a genuinely half-read block, which may have no
            // end time and no title. Stopping for too many events leaves a
            // whole one, dropped because keeping it would put the count one
            // over the limit the warning has just named.
            $events[]   = $current;
            self::note($warnings, 'The file ends in the middle of an event, so it was not complete.');
        }

        $complete = ($sawCalendarStart === true && $sawCalendarEnd === true
            && $stoppedEarly === false && $droppedLine === false);
        if (($sawCalendarStart === false || $sawCalendarEnd === false) && $stoppedEarly === false) {
            // Only said when the FILE was the problem. When we stopped reading
            // on purpose the warning above has already said so in plain words,
            // and "the file did not begin and end as a whole calendar" would
            // point the reader at the wrong thing — the file may be perfectly
            // whole; we simply did not read all of it.
            //
            // Written out in full rather than as "complete is false", which is
            // what it used to say. Those meant the same thing until a dropped
            // line became a third reason for `complete` to be false; now they
            // do not, and a calendar that began and ended perfectly well would
            // have been told it did not.
            self::note($warnings, 'The file did not begin and end as a whole calendar, so it may be incomplete.');
        }

        return ['complete' => $complete, 'events' => $events, 'warnings' => $warnings];
    }

    /**
     * Split one unfolded line into its name, its parameters and its value.
     *
     * The hard part is finding the colon that ends the name-and-parameters
     * part. A parameter value may be quoted and may then contain a colon, a
     * semicolon or a comma — Microsoft writes `TZID="GMT Standard Time"` and
     * Google writes `ALTREP="https://..."`. Splitting on the first colon would
     * cut such a line in the wrong place.
     *
     * Returns null when the line holds no colon at all, which is not a
     * property line, and also when the name-and-parameters part carries more
     * semicolons than any calendar writes (see below).
     *
     * @param list<string> $warnings    Added to in place.
     * @param bool         $droppedLine Set to true when a line was thrown
     *                                  away, so `parse()` can report the read
     *                                  as not the whole calendar.
     * @return array{name:string, params:array<string,string>, value:string}|null
     */
    private static function parsePropertyLine(string $line, array &$warnings, bool &$droppedLine): ?array
    {
        $inQuotes = false;
        $colonAt  = -1;
        $length   = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '"') {
                $inQuotes = ($inQuotes === false);
                continue;
            }
            if ($char === ':' && $inQuotes === false) {
                $colonAt = $i;
                break;
            }
        }
        if ($colonAt < 0) {
            return null;
        }

        $head  = substr($line, 0, $colonAt);
        $value = substr($line, $colonAt + 1);

        // Count the semicolons before splitting on them. The loop below builds
        // one piece of text per semicolon, and nothing used to stop it: a
        // single line whose name-and-parameters part was five and a quarter
        // million semicolons — in a file small enough for the fetcher to
        // accept — asked PHP for more memory than it allows and killed the
        // process. That happened inside `parse()`, where no per-event limit
        // applies, and the memory watch runs only every five hundred lines, so
        // a file of a handful of such lines went straight past everything.
        //
        // Note this counts semicolons INSIDE quotation marks too, which the
        // split itself would leave alone. That makes it refuse very slightly
        // sooner than it strictly must, which is the safe direction: a real
        // line carrying more than sixty-four quoted semicolons does not exist.
        //
        // The line is dropped whole rather than having its extra parameters
        // ignored. Ignoring them would be worse than it sounds: a `TZID`
        // sitting past the cut-off would vanish silently and the event would
        // be read in the wrong time zone, which nobody would ever notice.
        if (self::tooManyPieces($head, ';', self::MAX_PARAMS_PER_PROPERTY + 1) === true) {
            $droppedLine = true;
            self::note(
                $warnings,
                'A line in this calendar carried more settings than the portal reads from one line ('
                . self::MAX_PARAMS_PER_PROPERTY . '), so that line was left out.'
            );

            return null;
        }

        // Now split the head on semicolons, again ignoring quoted sections.
        $pieces   = [];
        $buffer   = '';
        $inQuotes = false;
        $headLen  = strlen($head);
        for ($i = 0; $i < $headLen; $i++) {
            $char = $head[$i];
            if ($char === '"') {
                $inQuotes = ($inQuotes === false);
                $buffer  .= $char;
                continue;
            }
            if ($char === ';' && $inQuotes === false) {
                $pieces[] = $buffer;
                $buffer   = '';
                continue;
            }
            $buffer .= $char;
        }
        $pieces[] = $buffer;

        $name   = strtoupper(trim(array_shift($pieces) ?? ''));
        $params = [];
        foreach ($pieces as $piece) {
            if ($piece === '') {
                continue;
            }
            $equalsAt = strpos($piece, '=');
            if ($equalsAt === false) {
                // A parameter with no value (some exporters write one). RFC
                // 5545 does not allow it; it is kept with an empty value
                // rather than throwing the line away.
                $params[strtoupper(trim($piece))] = '';
                continue;
            }
            $key      = strtoupper(trim(substr($piece, 0, $equalsAt)));
            $rawValue = trim(substr($piece, $equalsAt + 1));
            if (strlen($rawValue) >= 2 && $rawValue[0] === '"' && $rawValue[strlen($rawValue) - 1] === '"') {
                $rawValue = substr($rawValue, 1, -1);
            }
            $params[$key] = $rawValue;
        }

        return ['name' => $name, 'params' => $params, 'value' => $value];
    }

    // =========================================================================
    // 🧠 Memory: stopping before PHP stops us
    // =========================================================================

    /**
     * How many bytes PHP allows this process altogether, or null when there is
     * no limit set (or the setting cannot be read).
     *
     * `memory_limit` is written the way PHP settings are written: a plain
     * number of bytes, or a number followed by K, M or G. `-1` means no limit
     * at all, which is what a command-line run often has.
     *
     * This is worked out afresh each time rather than remembered. It is read
     * only every few hundred lines, so it costs nothing worth saving, and
     * remembering it would go stale if anything changed the setting mid-run —
     * which the self-test does exactly, on purpose.
     */
    private static function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }
        if (preg_match('/^(\d+)\s*([KMG]?)$/i', $raw, $matches) !== 1) {
            return null;
        }

        $bytes = (int) $matches[1];
        $unit  = strtoupper($matches[2]);
        if ($unit === 'K') {
            $bytes *= 1024;
        } elseif ($unit === 'M') {
            $bytes *= 1024 * 1024;
        } elseif ($unit === 'G') {
            $bytes *= 1024 * 1024 * 1024;
        }

        return $bytes > 0 ? $bytes : null;
    }

    /**
     * Has this process used more than its share of the memory it is allowed?
     *
     * WHY THIS EXISTS. Running out of memory in PHP is a FATAL error: it
     * cannot be caught, nothing after it runs, and the whole request or job
     * ends there. For an importer refreshing several calendars in turn, one
     * oversized or hostile file would therefore stop every calendar after it
     * from being refreshed at all, with nothing to say why. Stopping
     * ourselves, a comfortable distance short of the limit, turns that into an
     * ordinary "this was too big, here is what we did read" answer.
     *
     * `memory_get_usage(true)` is used, not the softer reading without the
     * `true`: the number PHP compares against `memory_limit` is the memory it
     * has actually taken from the operating system, which is what `true`
     * gives.
     *
     * WHAT IT CANNOT DO. It is a check at a moment, not a promise. Nothing
     * stops a single line, or a single event, from using the remaining fifth
     * of the limit all at once between two checks. It makes the fatal error
     * very unlikely for the sizes this portal accepts (`SafeFetch` refuses a
     * body over 5 MB); it does not make it impossible. On a host with no
     * memory limit at all it does nothing, and the `MAX_*` counts are what
     * bound the work instead.
     *
     * It is also not checked everywhere. It runs every five hundred lines
     * while the file is read, and before each event while the dates are worked
     * out — never inside the work one single event does. That gap is where
     * every fatal error found here so far happened, and it is bounded by
     * counts rather than by this.
     */
    private static function memoryRunningOut(float $shareAllowed): bool
    {
        $limit = self::memoryLimitBytes();
        if ($limit === null) {
            return false;
        }

        return memory_get_usage(true) > (int) ((float) $limit * $shareAllowed);
    }

    // =========================================================================
    // ✂️ Text: splitting lists, undoing escapes, and cleaning what a stranger wrote
    // =========================================================================

    /**
     * Would splitting this text on that separator make more pieces than are
     * allowed?
     *
     * THIS IS THE RULE THE WHOLE CLASS NOW FOLLOWS: never turn text that came
     * out of the file into an array before you know how many pieces it will
     * make. `substr_count()` walks the string and returns a number. It builds
     * nothing at all, so asking the question is free however long the line is,
     * while splitting first and counting afterwards costs one array entry per
     * separator BEFORE anything can object.
     *
     * That difference is not theoretical. Three separate rounds of independent
     * checking found this class dying of it, in six different places, every
     * one of them a single over-long line inside a file small enough for the
     * fetcher to accept. Each round fixed the shapes it had measured and the
     * next round found more, which is why the rule is written here as a rule
     * rather than as six separate guards.
     *
     * What it cannot do: it counts EVERY separator, including ones inside
     * quotation marks that the real splitting would leave alone. So it answers
     * "too many" slightly sooner than the split itself would. That is the safe
     * direction to be wrong in, and it only bites a line carrying more than
     * the whole allowance in quoted separators, which no calendar writes.
     */
    private static function tooManyPieces(string $text, string $separator, int $most): bool
    {
        return (substr_count($text, $separator) + 1) > $most;
    }

    /**
     * Split a property value on its commas, leaving escaped commas (`\,`)
     * alone, and never making more than `$most` pieces.
     *
     * `CATEGORIES:Youth,Music` is two categories. `CATEGORIES:Tea\, coffee` is
     * one. A plain `explode(',')` would get the second wrong, and a category
     * called "Tea" would appear on the site out of nowhere.
     *
     * WHY THERE IS A LIMIT, and why it is inside this loop rather than applied
     * to the finished list. This used to build one piece of text per comma and
     * hand the whole lot back, and its callers then counted as they walked the
     * result — by which time the memory had already been taken. A single
     * `CATEGORIES`, `EXDATE` or `RDATE` line of five and a quarter million
     * bare commas, in a file small enough for the fetcher to accept, asked PHP
     * for more memory than it allows and killed the process with an error that
     * cannot be caught. The limit has to stop the pieces being MADE, which is
     * something only this loop can do.
     *
     * `capped` says there was still text after the last piece made, so the
     * caller can warn and mark its answer as not the whole picture. It is
     * deliberately NOT set for a list that ends in a comma and nothing else:
     * the only thing left unread there is emptiness, and telling an
     * administrator that something was left out when nothing was is a fault
     * this class has had to correct at two other limits already. It IS set
     * when the unread remainder is nothing but more commas, which is honest —
     * there was more text, and it was not read.
     *
     * `$most` must be at least 1. Every caller works out its own remaining
     * allowance and skips the call entirely when there is none left.
     *
     * @return array{parts:list<string>, capped:bool}
     */
    private static function splitList(string $value, int $most): array
    {
        $parts  = [];
        $buffer = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $buffer .= $char . $value[$i + 1];
                $i++;
                continue;
            }
            if ($char === ',') {
                $parts[] = $buffer;
                $buffer  = '';
                if (count($parts) >= $most) {
                    return ['parts' => $parts, 'capped' => ($i + 1 < $length)];
                }
                continue;
            }
            $buffer .= $char;
        }
        $parts[] = $buffer;

        return ['parts' => $parts, 'capped' => false];
    }

    /**
     * Undo the escapes RFC 5545 uses inside text: `\n` and `\N` are new lines,
     * `\,` a comma, `\;` a semicolon, `\\` a backslash.
     *
     * The order matters. Replacing `\\` first would turn `\\n` (a literal
     * backslash followed by the letter n) into a new line, which is wrong, so
     * the string is walked once from left to right instead of using a series
     * of replacements.
     */
    private static function unescapeText(string $value): string
    {
        $out    = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char !== '\\' || $i + 1 >= $length) {
                $out .= $char;
                continue;
            }
            $next = $value[$i + 1];
            $i++;
            if ($next === 'n' || $next === 'N') {
                $out .= "\n";
                continue;
            }
            if ($next === ',' || $next === ';' || $next === '\\') {
                $out .= $next;
                continue;
            }
            // Any other escape is not one RFC 5545 defines. The backslash is
            // dropped and the character kept, which is what every calendar
            // program does with, for example, `\:`.
            $out .= $next;
        }

        return $out;
    }

    /**
     * Clean a one-line field (a title, a place, a category).
     *
     * Three things happen, and each has a reason:
     * - control characters and Unicode "format" characters are removed.
     *   `\p{Cf}` covers U+202E, the right-to-left override, which reverses the
     *   text after it on screen: a title written as `Invoice\u{202E}gpj.exe`
     *   reads as `Invoiceexe.jpg` to the person looking at it;
     * - runs of white space (including new lines) become one ordinary space,
     *   so a title cannot push a page's layout apart;
     * - the result is cut to `$maxChars` CHARACTERS, not bytes, so a multi-byte
     *   character is never cut in half (half a character stored in the
     *   database is rejected by MySQL on a `utf8mb4` column, and the whole
     *   import would fail).
     */
    private static function cleanOneLine(string $text, int $maxChars): string
    {
        $text = self::stripDangerousCharacters($text, false);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return self::cut($text, $maxChars);
    }

    /**
     * Clean a description, which may arrive as HTML.
     *
     * Google writes the description twice: once as plain text, once as HTML in
     * `X-ALT-DESC`. But plenty of systems (and Google itself, for an event
     * created from a web form) put HTML in the ordinary `DESCRIPTION` too. If
     * that were stored as it stands, the portal would either show the tags to
     * the reader or — far worse, if a later page ever printed it unescaped —
     * let a stranger put a script on the page.
     *
     * So: if there is a `<` anywhere, the tags that mean "new line" are turned
     * into new lines first (otherwise a list would come out as one long run-on
     * sentence), every tag is removed, and the HTML entities are decoded.
     * `ENT_QUOTES | ENT_HTML5` decodes `&amp;`, `&#39;` and the HTML5 names.
     *
     * What this cannot do: it does not make the text safe to print unescaped.
     * Nothing does. Every page must still escape it on the way out.
     */
    private static function cleanDescription(string $text): string
    {
        if (str_contains($text, '<') === true) {
            // A script or style block is removed WITH its contents. strip_tags()
            // on its own takes the tags off and leaves the code behind as
            // words, so a description holding a script would arrive as the
            // script's own source text in the middle of the description.
            $text = preg_replace('#<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', ' ', $text) ?? $text;
            $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text) ?? $text;
            $text = preg_replace('#<\s*/\s*(p|li|div)\s*>#i', "\n", $text) ?? $text;
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // New lines are kept here: a description is allowed to have paragraphs.
        $text = self::stripDangerousCharacters($text, true);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);

        return self::cut($text, self::MAX_DESCRIPTION);
    }

    /**
     * Remove control characters and invisible formatting characters.
     *
     * `$keepNewLines` decides whether a new line survives. Tabs never do: a tab
     * in a title is only ever an attempt to disturb a layout.
     *
     * The first step throws away anything that is not valid UTF-8. A feed with
     * a broken byte in it would otherwise make every later regular expression
     * silently return null (PCRE refuses invalid UTF-8), and the field would
     * come out uncleaned.
     */
    private static function stripDangerousCharacters(string $text, bool $keepNewLines): string
    {
        if (preg_match('//u', $text) !== 1) {
            // Not valid UTF-8. Keep the characters that ARE valid and drop
            // only the bytes that are not, so one bad byte in a field costs
            // that one byte rather than every accented letter beside it.
            //
            // The pattern lists the byte sequences a valid UTF-8 character is
            // allowed to be (Unicode 15, table 3-7: it rejects overlong forms,
            // halves of surrogate pairs and anything above U+10FFFF). Each of
            // those is matched and then abandoned with `(*SKIP)(*FAIL)`, which
            // tells PCRE to step past it without replacing it; anything else
            // is a single byte, and that is what gets removed. There is no
            // `u` flag, on purpose — the subject is not valid UTF-8, and PCRE
            // refuses to run a `u` pattern against such a string at all.
            //
            // This used to be `preg_replace('/[\x80-\xFF]+/', '', $text)`,
            // which threw away EVERY byte above 127 the moment any one of them
            // was wrong: a title reading `Zoë café ÿ end` with one stray byte
            // came out as `Zo caf end`, while the comment beside it claimed
            // the valid characters were kept. It was the comment that was
            // wrong, so the code was brought up to it.
            //
            // What it still cannot do: guess an encoding. A file written in
            // Latin-1 has no valid UTF-8 characters in it at all, so every
            // accented letter is a stray byte and is dropped — `Café` becomes
            // `Caf`. Guessing was rejected: reading Latin-1 bytes as if they
            // were Windows-1252, or the other way round, produces confident
            // nonsense, and RFC 5545 says a calendar file is UTF-8.
            $salvaged = preg_replace(
                '/(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]'
                . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]'
                . '|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}'
                . '|\xF4[\x80-\x8F][\x80-\xBF]{2})(*SKIP)(*FAIL)|./s',
                '',
                $text
            );
            // Null means PCRE gave up (it has its own limits). Falling back to
            // the blunt strip is worse text but still safe text, and the one
            // thing that must not happen is leaving invalid UTF-8 in place:
            // every `/u` pattern below would then quietly return null and the
            // field would come out uncleaned.
            $text = $salvaged ?? (string) preg_replace('/[\x80-\xFF]+/', '', $text);
        }

        $pattern = $keepNewLines === true ? '/[^\P{Cc}\n]|\p{Cf}/u' : '/\p{Cc}|\p{Cf}/u';
        $cleaned = preg_replace($pattern, '', $text);

        return $cleaned ?? '';
    }

    /** Cut to a number of CHARACTERS, never splitting a character in half. */
    private static function cut(string $text, int $maxChars): string
    {
        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars, 'UTF-8');
    }

    /**
     * Keep a web address only when it really is one, and only http or https.
     *
     * Returns null for anything else. `javascript:` addresses are the reason:
     * one stored and later printed into a link would run whatever the stranger
     * wrote, for whoever clicked it.
     */
    private static function cleanUrl(string $value): ?string
    {
        $value = trim(self::stripDangerousCharacters($value, false));
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) (parse_url($value, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return self::cut($value, self::MAX_URL);
    }

    /**
     * Clean and shorten the category list.
     *
     * Duplicates are removed without regard to capital letters, because
     * "Youth" and "youth" are one category to a reader, and because the
     * portal's own category matching (part P6) compares them that way. The
     * first spelling seen is the one kept.
     *
     * @param  list<string> $raw
     * @return list<string>
     */
    private static function cleanCategories(array $raw): array
    {
        $out  = [];
        $seen = [];
        foreach ($raw as $value) {
            $clean = self::cleanOneLine(self::unescapeText($value), self::MAX_CATEGORY);
            if ($clean === '') {
                continue;
            }
            $key = mb_strtolower($clean, 'UTF-8');
            if (isset($seen[$key]) === true) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $clean;
            if (count($out) >= self::MAX_CATEGORIES_PER_EVENT) {
                break;
            }
        }

        return $out;
    }

    // =========================================================================
    // 🕰️ Times: which zone a value is in, and what it means
    // =========================================================================

    /**
     * Work out which time zone a `TZID` parameter names.
     *
     * Four spellings are understood, tried in this order:
     * 1. an ordinary name PHP knows, such as `Europe/London`;
     * 2. a Windows name, such as `GMT Standard Time`, looked up in
     *    `Portal\Core\WindowsTimeZones` (Microsoft 365 writes these);
     * 3. the form Mozilla Thunderbird writes,
     *    `/mozilla.org/20050126_1/Europe/London`: the longest ending that is a
     *    real name is used. The ending is tried longest-first so that a
     *    three-part name like `America/Argentina/Buenos_Aires` still comes out
     *    whole;
     * 4. anything else: the calendar's own zone is used and a warning is
     *    recorded.
     *
     * Why not guess UTC for an unknown zone: it would move every event in that
     * calendar by the offset of whatever zone was really meant, silently, and
     * it would look right to anybody whose own zone happens to be UTC.
     *
     * @param list<string> $warnings Added to in place.
     */
    private static function resolveZone(string $tzid, DateTimeZone $fallback, array &$warnings): DateTimeZone
    {
        $name = trim($tzid);
        if ($name === '') {
            return $fallback;
        }

        // Nothing below this point should ever run over megabytes of text, so
        // the name is bounded before anything looks at it.
        //
        // The LENGTH matters because the name is matched against a pattern,
        // looked up in the Windows list (which copies it into lower case) and,
        // when it is not recognised, cleaned and printed into a warning.
        //
        // The SEGMENT count matters because of the slash search below, which
        // splits the name into an array. That split used to run before
        // anything had counted the slashes: a `TZID` of two and a half million
        // slashes, inside a file small enough for the fetcher to accept, used
        // every byte PHP allows and killed the process. The search behind it
        // then tried every possible ending in turn, which is work proportional
        // to the square of the number of segments — a second problem the
        // memory ran out too soon to reach.
        //
        // A name this far outside anything real is treated exactly like a name
        // PHP does not know. The warning does not quote it, because the name
        // is the thing that was too big.
        if (strlen($name) > self::MAX_ZONE_NAME_LENGTH
            || self::tooManyPieces($name, '/', self::MAX_ZONE_NAME_SEGMENTS) === true) {
            self::note(
                $warnings,
                'A time zone name in this calendar was far longer than any real one, '
                . 'so the calendar\'s own zone was used instead.'
            );

            return $fallback;
        }

        $zone = self::zoneOrNull($name);
        if ($zone !== null) {
            return $zone;
        }

        $windows = WindowsTimeZones::toIana($name);
        if ($windows !== null) {
            $zone = self::zoneOrNull($windows);
            if ($zone !== null) {
                return $zone;
            }
        }

        if (str_contains($name, '/') === true) {
            // Safe to split now: the check at the top of this method has
            // already proved the name holds at most
            // `MAX_ZONE_NAME_SEGMENTS` pieces, so this builds a tiny array and
            // the loop behind it runs at most that many times.
            $segments = array_values(array_filter(explode('/', $name), static function (string $piece): bool {
                return $piece !== '';
            }));
            for ($take = count($segments); $take >= 2; $take--) {
                $candidate = implode('/', array_slice($segments, count($segments) - $take));
                $zone      = self::zoneOrNull($candidate);
                if ($zone !== null) {
                    return $zone;
                }
            }
        }

        self::note(
            $warnings,
            'Unknown time zone "' . self::cleanOneLine($name, 80) . '"; the calendar\'s own zone was used instead.'
        );

        return $fallback;
    }

    /** A DateTimeZone for this name, or null when PHP does not know the name. */
    private static function zoneOrNull(string $name): ?DateTimeZone
    {
        // Rejected before it is tried: a bare offset such as "+01:00" and any
        // short abbreviation such as "BST", "EST" or "CET". PHP accepts all of
        // them, and every one of them is a FIXED offset that never changes
        // with the seasons — measured on this PHP: BST, EST, CET, EET, WET,
        // MET, GMT, PST and CST all report the same offset in July as in
        // January. So a calendar written in "EST" and read that way would be an
        // hour out for eight months of the year, silently. Refusing them sends
        // the caller to the calendar's own zone WITH a warning, which is wrong
        // in a way somebody can see. The cost: a calendar that really did mean
        // the fixed-offset zone gets a warning it did not need.
        if (preg_match('/^[A-Za-z][A-Za-z0-9_+\-\/]*$/', $name) !== 1) {
            return null;
        }
        if (str_contains($name, '/') === false && strlen($name) <= 4 && strtoupper($name) !== 'UTC') {
            return null;
        }

        try {
            return new DateTimeZone($name);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Read one date-or-time value from the file.
     *
     * Three forms exist:
     * - `20261224` with `VALUE=DATE`: a whole day. It is NOT converted between
     *   zones — Christmas Day is Christmas Day wherever you read it — so it is
     *   built at midnight in the organisation's own zone;
     * - `20261015T180000Z`: an exact moment, in UTC;
     * - `20261015T180000` with or without a `TZID`: a wall-clock time in that
     *   zone, or, with no zone given at all, in the calendar's own zone (a
     *   "floating" time, which RFC 5545 says means the same clock reading
     *   wherever you are).
     *
     * Returns null when the value is not a date at all.
     *
     * @param  array<string,string> $params
     * @param  list<string>         $warnings Added to in place.
     * @return array{dt:DateTimeImmutable, isDate:bool, zone:DateTimeZone}|null
     */
    private static function readDateTime(
        string $value,
        array $params,
        DateTimeZone $floatingZone,
        DateTimeZone $orgZone,
        array &$warnings
    ): ?array {
        $value = trim($value);
        $isDate = (strtoupper($params['VALUE'] ?? '') === 'DATE');

        if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/', $value, $m) !== 1) {
            return null;
        }

        $hasTime = isset($m[4]);
        if ($hasTime === false) {
            $isDate = true;
        }

        if ($isDate === true) {
            $zone = $orgZone;
            $dt   = self::makeDate((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, $zone);

            return $dt === null ? null : ['dt' => $dt, 'isDate' => true, 'zone' => $zone];
        }

        if (($m[7] ?? '') === 'Z') {
            $zone = new DateTimeZone('UTC');
        } elseif (isset($params['TZID']) === true && trim($params['TZID']) !== '') {
            $zone = self::resolveZone($params['TZID'], $floatingZone, $warnings);
        } else {
            $zone = $floatingZone;
        }

        $dt = self::makeDate(
            (int) $m[1],
            (int) $m[2],
            (int) $m[3],
            (int) $m[4],
            (int) $m[5],
            (int) $m[6],
            $zone
        );

        return $dt === null ? null : ['dt' => $dt, 'isDate' => false, 'zone' => $zone];
    }

    /**
     * Build one date in one zone, or null when the numbers are not a real date.
     *
     * `DateTimeImmutable` happily rolls 31 February over into 3 March, which
     * would silently move an event, so the result is checked against the
     * numbers that went in.
     */
    private static function makeDate(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        DateTimeZone $zone
    ): ?DateTimeImmutable {
        if ($year < 1 || $year > 9999 || checkdate($month, $day, $year) === false) {
            return null;
        }
        if ($hour > 23 || $minute > 59 || $second > 60) {
            return null;
        }
        // A second of 60 is a leap second; PHP has no such thing, so it is
        // read as the last ordinary second of that minute.
        if ($second === 60) {
            $second = 59;
        }

        $text = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        try {
            return new DateTimeImmutable($text, $zone);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * The identity of one date within its series (plan section 1.7).
     *
     * For a whole-day series it is the date, `20261224`. For a timed series it
     * is the date's ORIGINAL start turned into UTC, `20261022T230000Z`.
     *
     * Turning it into UTC is what makes a skipped or changed date line up with
     * the date it refers to: the series may be written in New York time while
     * the `EXDATE` that removes one of its dates is written in UTC, and both
     * then produce the same key.
     *
     * This is an identifier and nothing else. It is never shown, and never
     * used as the time of the event — which is why converting it to UTC does
     * not break the portal's rule that event times are stored and compared as
     * wall-clock readings (DEV_NOTES.md, "Wall-clock times compare as text").
     */
    private static function recurrenceKeyFor(DateTimeImmutable $original, bool $isAllDay): string
    {
        if ($isAllDay === true) {
            return $original->format('Ymd');
        }

        return $original->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * Read a `DURATION` value such as `PT1H30M`, `P2D` or `P1W`.
     *
     * Returns null when it cannot be read, and null for a negative duration:
     * RFC 5545 allows a negative duration on an alarm, never on an event, and
     * an event that ends before it starts is not something to guess about.
     */
    private static function readDuration(string $value): ?DateInterval
    {
        $value = strtoupper(trim($value));
        if ($value === '' || $value[0] === '-') {
            return null;
        }
        if ($value[0] === '+') {
            $value = substr($value, 1);
        }
        if (preg_match('/^P(\d+W|(\d+D)?(T(\d+H)?(\d+M)?(\d+S)?)?)$/', $value) !== 1) {
            return null;
        }

        try {
            return new DateInterval($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    // =========================================================================
    // 🔁 Repeat rules
    // =========================================================================

    /**
     * Read an `RRULE` value into its parts, and say whether this class can
     * work it out.
     *
     * When `supported` is false the caller keeps the dates the file states one
     * by one — the series' own first date, any added date, and any changed
     * date left without a slot to take — drops the dates the repeat pattern
     * would have produced, and records `reason` as a warning. That is
     * deliberate: a rule read roughly would put events on the wrong days, and
     * nobody checks a calendar they did not create.
     *
     * @return array{freq:string, interval:int, count:?int, untilRaw:string, byday:list<array{ord:?int,day:string}>,
     *               bymonthday:list<int>, bymonth:list<int>, bysetpos:list<int>, wkst:string,
     *               supported:bool, reason:string}
     */
    private static function parseRule(string $value): array
    {
        $rule = [
            'freq'       => '',
            'interval'   => 1,
            'count'      => null,
            'untilRaw'   => '',
            'byday'      => [],
            'bymonthday' => [],
            'bymonth'    => [],
            'bysetpos'   => [],
            'wkst'       => 'MO',
            'supported'  => true,
            'reason'     => '',
        ];

        // Count the semicolons BEFORE splitting on them, and before the value
        // is even copied into capital letters. The split below builds one
        // piece of text per semicolon, and it used to be the first thing this
        // method did: a rule of five and a quarter million semicolons, in a
        // file small enough for the fetcher to accept, used more memory than
        // PHP allows and killed the process with an error nothing can catch.
        //
        // The per-part limits further down did not help. They count the commas
        // inside ONE part's value — `BYDAY=MO,MO,MO,…` — and never see the
        // semicolons that separate one part from the next.
        if (self::tooManyPieces($value, ';', self::MAX_RULE_PARTS) === true) {
            $rule['supported'] = false;
            $rule['reason']    = 'The repeat rule is made of more parts than this portal works out.';

            return $rule;
        }

        $parts = explode(';', strtoupper(trim($value)));
        foreach ($parts as $part) {
            $equalsAt = strpos($part, '=');
            if ($equalsAt === false) {
                continue;
            }
            $key     = trim(substr($part, 0, $equalsAt));
            $setting = trim(substr($part, $equalsAt + 1));

            if (in_array($key, self::UNSUPPORTED_RULE_PARTS, true) === true) {
                $rule['supported'] = false;
                $rule['reason']    = 'The repeat rule uses ' . $key . ', which this portal does not work out.';
                continue;
            }

            switch ($key) {
                case 'FREQ':
                    $rule['freq'] = $setting;
                    break;
                case 'INTERVAL':
                    $rule['interval'] = (int) $setting;
                    break;
                case 'COUNT':
                    $rule['count'] = (int) $setting;
                    break;
                case 'UNTIL':
                    $rule['untilRaw'] = $setting;
                    break;
                case 'WKST':
                    if (isset(self::WEEKDAYS[$setting]) === true) {
                        $rule['wkst'] = $setting;
                    }
                    break;
                case 'BYDAY':
                    if (self::ruleListTooLong($setting, 'BYDAY') === true) {
                        $rule['supported'] = false;
                        $rule['reason']    = 'The repeat rule gives BYDAY more values than it can possibly use, '
                            . 'which this portal does not work out.';
                        break;
                    }
                    foreach (explode(',', $setting) as $entry) {
                        $entry = trim($entry);
                        if (preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $entry, $m) !== 1) {
                            continue;
                        }
                        $rule['byday'][] = [
                            'ord' => (($m[1] ?? '') === '') ? null : (int) $m[1],
                            'day' => $m[2],
                        ];
                    }
                    break;
                case 'BYMONTHDAY':
                    if (self::ruleListTooLong($setting, 'BYMONTHDAY') === true) {
                        $rule['supported'] = false;
                        $rule['reason']    = 'The repeat rule gives BYMONTHDAY more values than it can possibly use, '
                            . 'which this portal does not work out.';
                        break;
                    }
                    foreach (explode(',', $setting) as $entry) {
                        $day = (int) trim($entry);
                        if ($day !== 0 && $day >= -31 && $day <= 31) {
                            $rule['bymonthday'][] = $day;
                        }
                    }
                    break;
                case 'BYMONTH':
                    if (self::ruleListTooLong($setting, 'BYMONTH') === true) {
                        $rule['supported'] = false;
                        $rule['reason']    = 'The repeat rule gives BYMONTH more values than it can possibly use, '
                            . 'which this portal does not work out.';
                        break;
                    }
                    foreach (explode(',', $setting) as $entry) {
                        $month = (int) trim($entry);
                        if ($month >= 1 && $month <= 12) {
                            $rule['bymonth'][] = $month;
                        }
                    }
                    break;
                case 'BYSETPOS':
                    if (self::ruleListTooLong($setting, 'BYSETPOS') === true) {
                        $rule['supported'] = false;
                        $rule['reason']    = 'The repeat rule gives BYSETPOS more values than it can possibly use, '
                            . 'which this portal does not work out.';
                        break;
                    }
                    foreach (explode(',', $setting) as $entry) {
                        $position = (int) trim($entry);
                        if ($position !== 0) {
                            $rule['bysetpos'][] = $position;
                        }
                    }
                    break;
                default:
                    // Anything else (RSCALE, SKIP, X- parts) is ignored.
                    break;
            }
        }

        // Drop repeats from each BY… list.
        //
        // This is not tidying: a repeated value is COUNTED. `BYMONTHDAY=1,1`
        // with `COUNT=3` offered the first of the month twice in every month,
        // so the count ran out after two months and the third date never
        // appeared. The repeat also made no difference to the dates otherwise,
        // which is why nobody would ever have looked at the rule to find out
        // why a date was missing.
        //
        // The same de-duplication is what makes the length limits above an
        // honest refusal rather than an arbitrary one: a list longer than the
        // number of different values it could hold is repetition by
        // definition.
        $rule['bymonthday'] = array_values(array_unique($rule['bymonthday']));
        $rule['bymonth']    = array_values(array_unique($rule['bymonth']));
        $rule['bysetpos']   = array_values(array_unique($rule['bysetpos']));

        $seenDays   = [];
        $uniqueDays = [];
        foreach ($rule['byday'] as $entry) {
            $dayKey = ($entry['ord'] === null ? '' : (string) $entry['ord']) . $entry['day'];
            if (isset($seenDays[$dayKey]) === true) {
                continue;
            }
            $seenDays[$dayKey] = true;
            $uniqueDays[]      = $entry;
        }
        $rule['byday'] = $uniqueDays;

        if ($rule['supported'] === true && in_array($rule['freq'], self::SUPPORTED_FREQ, true) === false) {
            $rule['supported'] = false;
            $rule['reason']    = 'The repeat rule repeats every "' . self::cleanOneLine($rule['freq'], 20)
                . '", which this portal does not work out.';
        }
        if ($rule['supported'] === true && $rule['interval'] < 1) {
            $rule['supported'] = false;
            $rule['reason']    = 'The repeat rule has an interval below 1, which has no meaning.';
        }
        // An interval this large is not merely useless, it is DANGEROUS, and
        // the danger is not the one you would guess. `(int)` turns
        // `99999999999999999999` into the largest whole number PHP can hold
        // rather than refusing it, and the arithmetic that moves to the next
        // period then tips into floating point: `7 * $interval` made
        // `modify()` throw `DateMalformedStringException` for a weekly rule,
        // and the month counter made `intdiv()` throw `TypeError` for a
        // monthly one. Neither is the `RuntimeException('time budget')` the
        // caller is told to expect, and this class promises every limit ends
        // with a warning and never a failed import. Refusing here keeps that
        // promise.
        if ($rule['supported'] === true && $rule['interval'] > self::MAX_RULE_INTERVAL) {
            $rule['supported'] = false;
            // The number is deliberately NOT quoted back. `(int)` has already
            // turned whatever the file wrote into the largest whole number PHP
            // can hold, so printing it would show the administrator a figure
            // that appears nowhere in their calendar.
            $rule['reason']    = 'The repeat rule repeats less often than once every '
                . self::MAX_RULE_INTERVAL . ' periods, which is further apart than this portal works out.';
        }
        // A `COUNT` of zero or less. It used to be read as "no count at all",
        // so `COUNT=-5` on a daily rule quietly produced every date in the
        // window — 365 of them — with nothing said. This class's own policy
        // for a rule it cannot honour is the first date and a warning, and a
        // count that asks for no dates, or for minus five of them, is a rule
        // it cannot honour. (Note the order: this test comes before the "too
        // many" one below so that the message names the real problem.)
        if ($rule['supported'] === true && $rule['count'] !== null && $rule['count'] < 1) {
            $rule['supported'] = false;
            $rule['reason']    = 'The repeat rule asks for ' . $rule['count']
                . ' dates, which is not a number of dates this portal can work out.';
        }
        if ($rule['supported'] === true && $rule['count'] !== null && $rule['count'] > self::MAX_RULE_COUNT) {
            $rule['supported'] = false;
            $rule['reason']    = 'The repeat rule asks for ' . $rule['count'] . ' dates, which is more than this portal works out.';
        }
        // A yearly rule that names no month, and picks its days with a NUMBERED
        // weekday (`2SU`, "the second Sunday") together with either a plain
        // weekday or a day of the month. RFC 5545 counts a numbered weekday
        // across the whole year but a plain one within each month, so the two
        // together have no single reading this class can be sure of. Rather
        // than pick one and be quietly wrong twelve times a year, the series is
        // imported as its first date with a warning — the same treatment every
        // other rule this class does not support gets.
        //
        // The two shapes on their own ARE supported: `BYDAY=SU` alone is every
        // Sunday of the year, `BYMONTHDAY=1` alone is the first of every month,
        // and `BYDAY=2SU` alone is the second Sunday of the year.
        if ($rule['supported'] === true && $rule['freq'] === 'YEARLY' && $rule['bymonth'] === []) {
            $hasOrdinalDay = false;
            $hasPlainDay   = false;
            foreach ($rule['byday'] as $entry) {
                if ($entry['ord'] !== null) {
                    $hasOrdinalDay = true;
                    continue;
                }
                $hasPlainDay = true;
            }
            if ($hasOrdinalDay === true && ($hasPlainDay === true || $rule['bymonthday'] !== [])) {
                $rule['supported'] = false;
                $rule['reason']    = 'The repeat rule repeats every year and mixes a numbered weekday with '
                    . 'another kind of day without naming a month, which this portal does not work out.';
            }
        }

        return $rule;
    }

    /**
     * Does one `BY…` part of a repeat rule list more values than it could
     * possibly use?
     *
     * The commas are COUNTED rather than the value being split apart, and that
     * is the whole point of the method. Splitting first is what used to
     * happen, and a rule listing a million days of the month built a million
     * separate pieces of text before anything looked at them. Counting commas
     * touches no memory at all, so the refusal is free however long the line
     * is.
     *
     * An unknown part name is never too long: this method decides nothing
     * about parts it has no limit for.
     */
    private static function ruleListTooLong(string $setting, string $part): bool
    {
        $most = self::MAX_RULE_LIST_ENTRIES[$part] ?? null;
        if ($most === null) {
            return false;
        }

        return self::tooManyPieces($setting, ',', $most);
    }

    /**
     * Work out the dates a repeat rule produces, inside the window asked for.
     *
     * The work is done on WALL-CLOCK time in the series' own zone, stepping
     * one period at a time from the series' first date. That is what RFC 5545
     * describes and what a person expects: "every Thursday at 19:00" stays at
     * 19:00 through a clock change, even though the gap between two of those
     * Thursdays is then 23 or 25 hours rather than 24.
     *
     * Dates come out in ascending order, so the caller can stop reading at the
     * first one past the window.
     *
     * The step limit below is counted afresh for EACH series, as the plan
     * asks. What bounds a whole file of nasty series is the deadline, not this
     * limit — a caller that gives a generous deadline to a file holding
     * thousands of never-ending series will wait for it.
     *
     * @param  array<string,mixed> $rule       From `parseRule()`.
     * @param  list<string>        $warnings   Added to in place.
     * @return array{starts:list<DateTimeImmutable>, capped:bool, lastStart:?DateTimeImmutable}
     */
    private static function generateStarts(
        array $rule,
        DateTimeImmutable $dtstart,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        float $deadline,
        array &$warnings
    ): array {
        $steps = 0;
        $zone   = $dtstart->getTimezone();
        $result = [];
        $capped = false;

        // When does the rule stop? UNTIL is written either as a plain date
        // (whole-day series) or as an exact moment in UTC. A few exporters
        // write a local time with no zone; that is read in the series' own
        // zone, which is the only sensible reading available.
        $until = null;
        if ($rule['untilRaw'] !== '') {
            $throwaway = [];
            $untilRead = self::readDateTime((string) $rule['untilRaw'], [], $zone, $zone, $throwaway);
            if ($untilRead !== null) {
                $until = $untilRead['isDate'] === true
                    ? $untilRead['dt']->setTime(23, 59, 59)
                    : $untilRead['dt'];
            }
        }

        $hour   = (int) $dtstart->format('H');
        $minute = (int) $dtstart->format('i');
        $second = (int) $dtstart->format('s');

        $generated = 0;
        $countCap  = ($rule['count'] !== null && $rule['count'] > 0) ? (int) $rule['count'] : null;

        $freq        = (string) $rule['freq'];
        $interval    = max(1, (int) $rule['interval']);
        $cursorDay   = $dtstart;
        $cursorYear  = (int) $dtstart->format('Y');
        $cursorMonth = (int) $dtstart->format('n');

        if ($freq === 'WEEKLY') {
            // A weekly rule searches one week at a time, so it starts from the
            // first day of the week the series begins in. Which day that is
            // depends on the rule's own WKST, and is not always Monday.
            //
            // The step back is taken ON THE CALENDAR, never as that many lots
            // of 86,400 seconds. Going back across the night the clocks go
            // forward, a seconds-based step lands an hour early, and from
            // midnight an hour early is the day before. The week searched
            // would then begin a day early and — the half that is easy to miss
            // — END a day early too, so the last matching weekday of that week
            // falls outside it. Every fortnight (INTERVAL=2) nothing picks it
            // up, because the next week searched is a fortnight away, and
            // every date after the first comes back on the wrong week.
            //
            // What checks this now: the two "B6 … the step back to the start
            // of the week" checks in tools/ics-reader-selftest.php, against
            // the last two events of the fixture file
            // tools/fixtures/ics/allday-clock-change.ics — a fortnightly and a
            // weekly Saturday whole-day series beginning Saturday 3 April
            // 2027 with WKST=SU, whose step back crosses the March change.
            // Both fail if this line ever counts seconds.
            $weekStartNumber = self::WEEKDAYS[(string) $rule['wkst']];
            $stepBack        = ((int) $dtstart->format('N') - $weekStartNumber + 7) % 7;
            $cursorDay       = $dtstart->modify('-' . $stepBack . ' days');
        }

        // The first date is always the series' own start. RFC 5545 §3.8.5.3
        // makes DTSTART the first date of the set even where the BY… parts
        // would not have chosen it, and every calendar program shows it. It is
        // merged with the first period's own dates rather than replacing them,
        // because a weekly rule starting on a Monday and repeating on Monday,
        // Wednesday and Friday must still give that first Wednesday and Friday
        // — an earlier version of this loop lost them.
        $pending = self::mergeCandidates(
            [$dtstart],
            self::candidatesForPeriod($freq, $rule, $cursorDay, $cursorYear, $cursorMonth, $dtstart, $zone, $hour, $minute, $second)
        );

        $stop = false;
        while ($stop === false) {
            $steps++;
            if ($steps > self::MAX_EXPANSION_STEPS) {
                $capped     = true;
                self::note(
                    $warnings,
                    'A repeating event was too long to work out in full, so only its earlier dates were imported.'
                );
                break;
            }
            // EVERY step, not every two-hundredth.
            //
            // The settled plan for this part said "check the deadline every
            // 200 steps", and that was followed. The plan's assumption is what
            // fails: it takes a step to be cheap. A step is one PERIOD, and a
            // yearly period with 749 different `BYDAY` values has to look at
            // every day of every month of that year — about a quarter of a
            // second of work for ONE step. Two hundred of those is 48 seconds,
            // and that is exactly what happened: a 4,236-byte file, inside
            // every other limit, came back from a FIVE-second budget after
            // 47.78 seconds. On the web-served scheduled-job address the
            // importer is planned to use, that ends as PHP's "maximum
            // execution time exceeded" — another fatal error nothing can
            // catch, on a job that was supposed to give up politely.
            //
            // `microtime(true)` was measured at 20 nanoseconds a call, so
            // checking on all 50,000 steps a rule may take costs about one
            // millisecond. There was never anything to save.
            //
            // What this still cannot do: a single step is not interruptible.
            // The overrun is therefore up to one step's worth of work — about
            // a quarter of a second for the most expensive shape measured —
            // on top of the budget, instead of two hundred times that.
            if (microtime(true) > $deadline) {
                throw new RuntimeException('time budget');
            }

            foreach ($pending as $candidate) {
                if ($candidate < $dtstart) {
                    continue;
                }
                if ($until !== null && $candidate > $until) {
                    $stop = true;
                    break;
                }
                if ($candidate > $windowEnd) {
                    $stop = true;
                    break;
                }

                $generated++;
                if ($candidate >= $windowStart) {
                    // The limit is checked BEFORE this date is kept, not after.
                    // Written the other way round — keep it, then stop if the
                    // count has reached the limit — a series with exactly 400
                    // dates in the window was reported as cut short, and the
                    // administrator was told "the later ones were left out"
                    // when there were no later ones. Checking first means the
                    // warning is only ever raised by a date that really was
                    // left out: the 401st.
                    if (count($result) >= self::MAX_OCCURRENCES_PER_SERIES) {
                        $capped     = true;
                        self::note(
                            $warnings,
                            'A repeating event has more dates in this period than the portal imports ('
                            . self::MAX_OCCURRENCES_PER_SERIES . '), so the later ones were left out.'
                        );
                        $stop = true;
                        break;
                    }
                    $result[] = $candidate;
                }

                if ($countCap !== null && $generated >= $countCap) {
                    $stop = true;
                    break;
                }
            }
            $pending = [];

            if ($stop === true) {
                break;
            }

            // Move to the next period.
            if ($freq === 'DAILY') {
                $cursorDay = $cursorDay->modify('+' . $interval . ' days');
                if ($cursorDay > $windowEnd) {
                    break;
                }
                $pending = self::candidatesForPeriod($freq, $rule, $cursorDay, $cursorYear, $cursorMonth, $dtstart, $zone, $hour, $minute, $second);
                continue;
            }
            if ($freq === 'WEEKLY') {
                $cursorDay = $cursorDay->modify('+' . (7 * $interval) . ' days');
                if ($cursorDay > $windowEnd->modify('+7 days')) {
                    break;
                }
                $pending = self::candidatesForPeriod($freq, $rule, $cursorDay, $cursorYear, $cursorMonth, $dtstart, $zone, $hour, $minute, $second);
                continue;
            }
            if ($freq === 'MONTHLY') {
                // Counted in whole months rather than with "+1 month", which
                // turns 31 January into 3 March and would skip a month.
                $monthIndex  = ($cursorYear * 12) + ($cursorMonth - 1) + $interval;
                $cursorYear  = intdiv($monthIndex, 12);
                $cursorMonth = ($monthIndex % 12) + 1;
                if ($cursorYear > (int) $windowEnd->format('Y')
                    || ($cursorYear === (int) $windowEnd->format('Y') && $cursorMonth > (int) $windowEnd->format('n'))) {
                    break;
                }
                $pending = self::candidatesForPeriod($freq, $rule, $cursorDay, $cursorYear, $cursorMonth, $dtstart, $zone, $hour, $minute, $second);
                continue;
            }
            // YEARLY
            $cursorYear += $interval;
            if ($cursorYear > (int) $windowEnd->format('Y')) {
                break;
            }
            $pending = self::candidatesForPeriod($freq, $rule, $cursorDay, $cursorYear, $cursorMonth, $dtstart, $zone, $hour, $minute, $second);
        }

        return [
            'starts'    => $result,
            'capped'    => $capped,
            'lastStart' => $result === [] ? null : $result[count($result) - 1],
        ];
    }

    /**
     * The dates one period of the rule offers, in order.
     *
     * A "period" is one day, one week, one month or one year, depending on
     * `FREQ`. `BYSETPOS` then picks from that list — "the last weekday of the
     * month" is `BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1`, and it only means anything
     * once the whole month's list exists, which is why the list is built first
     * and filtered afterwards.
     *
     * @param  array<string,mixed> $rule
     * @return list<DateTimeImmutable>
     */
    private static function candidatesForPeriod(
        string $freq,
        array $rule,
        DateTimeImmutable $cursorDay,
        int $cursorYear,
        int $cursorMonth,
        DateTimeImmutable $dtstart,
        DateTimeZone $zone,
        int $hour,
        int $minute,
        int $second
    ): array {
        /** @var list<int> $byMonth */
        $byMonth = $rule['bymonth'];
        /** @var list<array{ord:?int,day:string}> $byDay */
        $byDay = $rule['byday'];
        /** @var list<int> $byMonthDay */
        $byMonthDay = $rule['bymonthday'];

        $candidates = [];

        if ($freq === 'DAILY') {
            $day = $cursorDay->setTime($hour, $minute, $second);
            if (self::dayPassesFilters($day, $byMonth, $byDay, $byMonthDay) === true) {
                $candidates[] = $day;
            }
        } elseif ($freq === 'WEEKLY') {
            $wantedDays = [];
            foreach ($byDay as $entry) {
                $wantedDays[] = $entry['day'];
            }
            if ($wantedDays === []) {
                $wantedDays[] = array_search((int) $dtstart->format('N'), self::WEEKDAYS, true);
            }
            for ($offset = 0; $offset < 7; $offset++) {
                $day = $cursorDay->modify('+' . $offset . ' days')->setTime($hour, $minute, $second);
                $code = array_search((int) $day->format('N'), self::WEEKDAYS, true);
                if (in_array($code, $wantedDays, true) === false) {
                    continue;
                }
                if ($byMonth !== [] && in_array((int) $day->format('n'), $byMonth, true) === false) {
                    continue;
                }
                $candidates[] = $day;
            }
        } elseif ($freq === 'MONTHLY') {
            if ($byMonth !== [] && in_array($cursorMonth, $byMonth, true) === false) {
                return [];
            }
            $candidates = self::datesInMonth(
                $cursorYear,
                $cursorMonth,
                $byDay,
                $byMonthDay,
                (int) $dtstart->format('j'),
                $zone,
                $hour,
                $minute,
                $second
            );
        } else {
            // YEARLY
            $months = $byMonth !== [] ? $byMonth : [];
            if ($months === []) {
                $hasOrdinalDay = false;
                $hasPlainDay   = false;
                foreach ($byDay as $entry) {
                    if ($entry['ord'] !== null) {
                        $hasOrdinalDay = true;
                        continue;
                    }
                    $hasPlainDay = true;
                }
                if ($hasOrdinalDay === true && $hasPlainDay === false && $byMonthDay === []) {
                    // "The second Sunday in the year" — counted across the
                    // whole year, as RFC 5545 asks when no month is given.
                    //
                    // The `$hasPlainDay === false` part is a safety net rather
                    // than a live path: `parseRule()` has already refused a
                    // numbered weekday mixed with a plain one, so such a rule
                    // never reaches here. It is written out anyway so this
                    // method is correct read on its own, without having to
                    // remember what another method promised.
                    return self::datesInYearByDay($cursorYear, $byDay, $zone, $hour, $minute, $second);
                }
                if ($byDay !== [] || $byMonthDay !== []) {
                    // No month is named, and the rule picks days by weekday or
                    // by day-of-month. RFC 5545 §3.3.10 counts those across the
                    // WHOLE year, so every month has to be looked at:
                    // `FREQ=YEARLY;BYDAY=SU` is every Sunday of the year, and
                    // `FREQ=YEARLY;BYMONTHDAY=1` is the first of every month.
                    //
                    // This used to fall through to the line below and use the
                    // month the series started in, which gave 4 dates for the
                    // first rule and 1 for the second — silently, with no
                    // warning, which is the one thing this class is not
                    // supposed to do. The mixtures that genuinely have no
                    // single reading (a numbered weekday together with a plain
                    // one, or together with a day-of-month) are refused in
                    // `parseRule()` instead, so they never reach this point.
                    $months = range(1, 12);
                } else {
                    // No BY… parts at all: the same day of the same month
                    // every year, taken from the series' own start.
                    $months = [(int) $dtstart->format('n')];
                }
            }
            foreach ($months as $month) {
                foreach (self::datesInMonth(
                    $cursorYear,
                    $month,
                    $byDay,
                    $byMonthDay,
                    (int) $dtstart->format('j'),
                    $zone,
                    $hour,
                    $minute,
                    $second
                ) as $date) {
                    $candidates[] = $date;
                }
            }
        }

        usort($candidates, static function (DateTimeImmutable $a, DateTimeImmutable $b): int {
            return $a <=> $b;
        });

        // BYSETPOS picks by position within this period's list.
        /** @var list<int> $bySetPos */
        $bySetPos = $rule['bysetpos'];
        if ($bySetPos !== [] && $candidates !== []) {
            $picked = [];
            $total  = count($candidates);
            foreach ($bySetPos as $position) {
                $index = $position > 0 ? ($position - 1) : ($total + $position);
                if ($index >= 0 && $index < $total) {
                    $picked[$index] = $candidates[$index];
                }
            }
            ksort($picked);
            $candidates = array_values($picked);
        }

        return $candidates;
    }

    /**
     * Does this day pass the BY… filters that only narrow a daily rule down?
     *
     * @param list<int>                        $byMonth
     * @param list<array{ord:?int,day:string}> $byDay
     * @param list<int>                        $byMonthDay
     */
    private static function dayPassesFilters(
        DateTimeImmutable $day,
        array $byMonth,
        array $byDay,
        array $byMonthDay
    ): bool {
        if ($byMonth !== [] && in_array((int) $day->format('n'), $byMonth, true) === false) {
            return false;
        }
        if ($byDay !== []) {
            $code  = array_search((int) $day->format('N'), self::WEEKDAYS, true);
            $found = false;
            foreach ($byDay as $entry) {
                if ($entry['day'] === $code) {
                    $found = true;
                    break;
                }
            }
            if ($found === false) {
                return false;
            }
        }
        if ($byMonthDay !== []) {
            $dayOfMonth  = (int) $day->format('j');
            $daysInMonth = (int) $day->format('t');
            $found       = false;
            foreach ($byMonthDay as $wanted) {
                $resolved = $wanted > 0 ? $wanted : ($daysInMonth + $wanted + 1);
                if ($resolved === $dayOfMonth) {
                    $found = true;
                    break;
                }
            }
            if ($found === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * The dates one month offers.
     *
     * - with `BYMONTHDAY`: those days of the month (a negative number counts
     *   back from the end, so -1 is the last day), narrowed by `BYDAY` when
     *   both are given;
     * - with `BYDAY` alone: every matching weekday, or the numbered one
     *   (`-1FR` is the last Friday, `2TU` the second Tuesday);
     * - with neither: the same day of the month as the series' first date,
     *   and nothing at all in a month that has no such day (31 February never
     *   becomes 3 March).
     *
     * @param  list<array{ord:?int,day:string}> $byDay
     * @param  list<int>                        $byMonthDay
     * @return list<DateTimeImmutable>
     */
    private static function datesInMonth(
        int $year,
        int $month,
        array $byDay,
        array $byMonthDay,
        int $fallbackDayOfMonth,
        DateTimeZone $zone,
        int $hour,
        int $minute,
        int $second
    ): array {
        $first = self::makeDate($year, $month, 1, $hour, $minute, $second, $zone);
        if ($first === null) {
            return [];
        }
        $daysInMonth = (int) $first->format('t');
        $dates       = [];

        if ($byMonthDay !== []) {
            foreach ($byMonthDay as $wanted) {
                $dayOfMonth = $wanted > 0 ? $wanted : ($daysInMonth + $wanted + 1);
                if ($dayOfMonth < 1 || $dayOfMonth > $daysInMonth) {
                    continue;
                }
                $date = self::makeDate($year, $month, $dayOfMonth, $hour, $minute, $second, $zone);
                if ($date === null) {
                    continue;
                }
                if ($byDay !== []) {
                    $code  = array_search((int) $date->format('N'), self::WEEKDAYS, true);
                    $match = false;
                    foreach ($byDay as $entry) {
                        if ($entry['day'] === $code) {
                            $match = true;
                            break;
                        }
                    }
                    if ($match === false) {
                        continue;
                    }
                }
                $dates[] = $date;
            }

            return $dates;
        }

        if ($byDay !== []) {
            foreach ($byDay as $entry) {
                $matching = [];
                for ($dayOfMonth = 1; $dayOfMonth <= $daysInMonth; $dayOfMonth++) {
                    $date = self::makeDate($year, $month, $dayOfMonth, $hour, $minute, $second, $zone);
                    if ($date === null) {
                        continue;
                    }
                    if (array_search((int) $date->format('N'), self::WEEKDAYS, true) === $entry['day']) {
                        $matching[] = $date;
                    }
                }
                if ($entry['ord'] === null) {
                    foreach ($matching as $date) {
                        $dates[] = $date;
                    }
                    continue;
                }
                $index = $entry['ord'] > 0 ? ($entry['ord'] - 1) : (count($matching) + $entry['ord']);
                if ($index >= 0 && $index < count($matching)) {
                    $dates[] = $matching[$index];
                }
            }

            return $dates;
        }

        if ($fallbackDayOfMonth > $daysInMonth) {
            return [];
        }
        $date = self::makeDate($year, $month, $fallbackDayOfMonth, $hour, $minute, $second, $zone);

        return $date === null ? [] : [$date];
    }

    /**
     * The dates a numbered weekday gives across a whole year — "the second
     * Sunday of the year", `FREQ=YEARLY;BYDAY=2SU` with no month given.
     *
     * @param  list<array{ord:?int,day:string}> $byDay
     * @return list<DateTimeImmutable>
     */
    private static function datesInYearByDay(
        int $year,
        array $byDay,
        DateTimeZone $zone,
        int $hour,
        int $minute,
        int $second
    ): array {
        $dates = [];
        foreach ($byDay as $entry) {
            if ($entry['ord'] === null) {
                continue;
            }
            $matching = [];
            for ($month = 1; $month <= 12; $month++) {
                $first = self::makeDate($year, $month, 1, $hour, $minute, $second, $zone);
                if ($first === null) {
                    continue;
                }
                $daysInMonth = (int) $first->format('t');
                for ($dayOfMonth = 1; $dayOfMonth <= $daysInMonth; $dayOfMonth++) {
                    $date = self::makeDate($year, $month, $dayOfMonth, $hour, $minute, $second, $zone);
                    if ($date === null) {
                        continue;
                    }
                    if (array_search((int) $date->format('N'), self::WEEKDAYS, true) === $entry['day']) {
                        $matching[] = $date;
                    }
                }
            }
            $index = $entry['ord'] > 0 ? ($entry['ord'] - 1) : (count($matching) + $entry['ord']);
            if ($index >= 0 && $index < count($matching)) {
                $dates[] = $matching[$index];
            }
        }

        usort($dates, static function (DateTimeImmutable $a, DateTimeImmutable $b): int {
            return $a <=> $b;
        });

        return $dates;
    }

    /**
     * Join two lists of candidate dates into one ordered list with no
     * repeats.
     *
     * Used to put the series' own first date alongside the first period's
     * dates. Comparing by the exact moment (`getTimestamp()` plus the
     * micro-second field) rather than by wall-clock text matters in the hour a
     * clock goes back, when the same reading happens twice.
     *
     * @param  list<DateTimeImmutable> $first
     * @param  list<DateTimeImmutable> $second
     * @return list<DateTimeImmutable>
     */
    private static function mergeCandidates(array $first, array $second): array
    {
        $byMoment = [];
        foreach (array_merge($first, $second) as $date) {
            $byMoment[$date->format('U.u') . '|' . $date->format('P')] = $date;
        }
        $merged = array_values($byMoment);
        usort($merged, static function (DateTimeImmutable $a, DateTimeImmutable $b): int {
            return $a <=> $b;
        });

        return $merged;
    }

    // =========================================================================
    // 📅 Step two: turn the properties into real dates
    // =========================================================================

    /**
     * Turn parsed events into the list of dates that fall inside a window.
     *
     * @param array                 $parsed            Exactly what `parse()` returned.
     * @param DateTimeZone          $orgZone           The zone the portal stores times in (the organisation's own).
     * @param DateTimeZone          $floatingZone      The zone to read a time that names no zone of its own, and the
     *                                                 fallback when a named zone cannot be recognised. Usually the
     *                                                 calendar's own zone.
     * @param DateTimeImmutable     $windowStartLocal  Earliest start to keep (compared as a moment).
     * @param DateTimeImmutable     $windowEndLocal    Latest start to keep.
     * @param float                 $deadline          A moment from `microtime(true)`; passing it throws
     *                                                 RuntimeException('time budget').
     * @param int|null              $maxDatesPerFeed   Most dates this one calendar may contribute. Leave it out
     *                                                 (or pass null) for `MAX_EVENTS_PER_FEED`, which is what every
     *                                                 earlier round measured. Anything outside 1 to
     *                                                 `MAX_EVENTS_PER_FEED_CEILING` is refused with a warning and the
     *                                                 default used instead. Part P6 reads the customer's setting and
     *                                                 passes it here; this class never reads a setting itself.
     *
     * @return array{occurrences:list<array<string,mixed>>, capped:bool, effectiveWindowEnd:?string, duplicates:int,
     *               skippedNoUid:int, attachments:int, warnings:list<string>}
     *
     * `warnings` holds only what this step found. `parse()`'s own warnings are
     * separate, so the caller must join the two lists if it wants both.
     *
     * `effectiveWindowEnd` is the honest end of what was read: when a limit
     * stopped the work early, it is the last date that was kept, and the
     * caller must not treat dates after it as "not in the calendar" (an
     * importer that deleted everything missing after that point would delete
     * real events).
     *
     * What it CANNOT tell the caller: when a limit stopped a series before it
     * produced any date inside the window at all, there is no "last date kept"
     * to report, so `effectiveWindowEnd` stays null while `capped` is true.
     * A caller must therefore look at `capped` FIRST and treat a capped read
     * with no end as covering nothing reliably. The same is now true whenever
     * a skipped-date or added-date list was cut short — see the note beside
     * `$anyDateListCut` further down, which is a fault a fifth round of
     * independent checking found.
     */
    public static function expand(
        array $parsed,
        DateTimeZone $orgZone,
        DateTimeZone $floatingZone,
        DateTimeImmutable $windowStartLocal,
        DateTimeImmutable $windowEndLocal,
        float $deadline,
        ?int $maxDatesPerFeed = null
    ): array {
        $warnings     = [];

        // How many dates this one calendar may contribute, and how many are
        // gathered before the list is cut to that size.
        //
        // The number is the customer's if they set one, and the default
        // otherwise. It is checked HERE, once, rather than wherever it is
        // used, so that there is exactly one place that decides what a bad
        // value means — and a bad value means "use the default and say so",
        // never "obey it". See `MAX_EVENTS_PER_FEED_CEILING` for why a setting
        // a person types cannot simply be trusted.
        $feedLimit = self::MAX_EVENTS_PER_FEED;
        if ($maxDatesPerFeed !== null) {
            if ($maxDatesPerFeed >= 1 && $maxDatesPerFeed <= self::MAX_EVENTS_PER_FEED_CEILING) {
                $feedLimit = $maxDatesPerFeed;
            } else {
                self::note(
                    $warnings,
                    'This portal was asked to import up to ' . $maxDatesPerFeed . ' dates from one calendar, '
                    . 'which is outside what it can do (1 to ' . self::MAX_EVENTS_PER_FEED_CEILING
                    . '), so it used its usual ' . self::MAX_EVENTS_PER_FEED . ' instead.'
                );
            }
        }
        $collectLimit = self::COLLECT_MULTIPLE * $feedLimit;
        $attachments  = 0;
        $skippedNoUid = 0;
        $duplicates   = 0;
        $anySeriesCapped = false;
        /** @var DateTimeImmutable|null $earliestCutOff The earliest point at which some limit stopped the reading. */
        $earliestCutOff = null;

        // Set when ANY skipped-date or added-date list was cut short, for
        // either reason — this one event listed too many, or the calendar as a
        // whole did. It throws the "read reliably up to here" point away at
        // the end of this method, and a fifth round of independent checking
        // found that it had to.
        //
        // Why, in plain terms. A cut list is the one kind of cut that loses
        // dates WITHOUT the answer knowing where they were. An event may carry
        // four and a half thousand added dates, the last five hundred of them
        // in the period being imported; cutting the list at four thousand
        // silently drops those five hundred, and they sit anywhere in the
        // period — including before some OTHER series' cut-off point.
        //
        // The end point is meant to mean "everything up to here was read
        // properly". Leaving it set after a cut list says exactly the opposite
        // of the truth, and the importer's own rule (part P6) then soft-
        // deletes every stored date at or before that point which this read
        // did not produce. Measured on a file of two events: the answer came
        // back `capped = true` with an end of 4 February 2027, and five
        // hundred real dates in 2026 — every one of them BEFORE that end —
        // were missing from it. The first refresh to cross the limit would
        // have deleted all five hundred.
        //
        // The two other stops already do this (see the end of this method, and
        // the note there about "an importer that believed it would delete real
        // events"). This is the same reasoning applied to the third.
        $anyDateListCut = false;

        /** @var array<string,list<array<string,mixed>>> $masters   Events with no RECURRENCE-ID, by UID. */
        $masters = [];
        /** @var array<string,list<array<string,mixed>>> $overrides Events with a RECURRENCE-ID, by UID. */
        $overrides = [];
        /** @var list<string> $uidOrder UIDs in the order the file listed them, so "the first one wins" is stable. */
        $uidOrder = [];

        // Set when the work stops early because memory is running short. It
        // joins `capped`, which already means "what came back is not
        // everything, so do not delete anything for being missing".
        $stoppedForMemory = false;

        // The running total of what every event read so far has KEPT. This is
        // the companion to the per-event limits, and the whole of this round's
        // fix: see `MAX_RETAINED_ADDED_DATES` for why a limit that bounds one
        // event is meaningless on its own. `cut` is set by `prepareEvent()`
        // when one of the three budgets stopped it reading something.
        $retained = ['addedDates' => 0, 'skippedDates' => 0, 'ruleValues' => 0, 'cut' => false];

        $events    = $parsed['events'] ?? [];
        foreach ($events as $raw) {
            // BOTH checks run on EVERY event, and that matters more than it
            // looks. The memory check used to run when the event number
            // divided by a hundred, with a comment saying "a hundred events is
            // still a small enough step that the fifth of the limit held back
            // covers it easily". That fifth is 25.6 MB of the usual 128 MB,
            // and a hundred events were measured GROWING BY UP TO 155 MB. The
            // result was a guard whose outcome depended on where the file's
            // size happened to fall relative to the sampling interval: 1,300
            // events of one shape survived because the check at event 300 fell
            // just above the threshold, and 700 of the same shape died because
            // the same check fell just below it. That is not a guard.
            //
            // Now the interval is ONE event, and the two numbers can be put
            // side by side honestly: the margin held back is a fifth of what
            // PHP allows (25.6 MB at the usual 128 MB), and the most one event
            // can add to what is KEPT is about 2.1 MB — four thousand added
            // dates at 388 bytes, four thousand skipped-date keys at 52, and
            // 749 repeat-rule values at 357. The margin is twelve times the
            // worst growth over the interval. (What it still does not cover is
            // the TRANSIENT cost of cleaning one enormous text field, which
            // the file header lists among the things nothing counts.)
            //
            // The clock is checked here too. It was not checked in this loop
            // at all, and reading six thousand events is not free: a 1.4 MB
            // file took 9.14 seconds on a five-second budget and finished
            // normally, with no exception raised anywhere. `microtime(true)`
            // was measured at 20 nanoseconds a call, so six thousand of them
            // cost about a tenth of a millisecond; there was never anything to
            // save by leaving it out.
            if (microtime(true) > $deadline) {
                throw new RuntimeException('time budget');
            }
            if (self::memoryRunningOut(self::MEMORY_SHARE_FOR_EXPAND) === true) {
                $stoppedForMemory = true;
                break;
            }

            $attachments += count($raw['ATTACH'] ?? []);

            $uid = trim(self::unescapeText(self::firstValue($raw, 'UID')));
            if ($uid === '') {
                // Without a UID there is nothing to recognise this event by on
                // the next refresh, so it would be deleted and recreated every
                // time — losing any decision an administrator had made about
                // it. Skipping it is the lesser harm.
                $skippedNoUid++;
                continue;
            }

            $prepared = self::prepareEvent($raw, $uid, $orgZone, $floatingZone, $warnings, $retained);
            if ($prepared === null) {
                continue;
            }

            $uidKey = bin2hex($prepared['uidHash']);
            if (in_array($uidKey, $uidOrder, true) === false) {
                $uidOrder[] = $uidKey;
            }
            if ($prepared['recurrenceIdKey'] !== null) {
                $overrides[$uidKey][] = $prepared;
                continue;
            }
            $masters[$uidKey][] = $prepared;
        }

        // One of the three file-wide budgets stopped something being read, so
        // dates may be missing. Said once, here, rather than by every event
        // that hit it — `note()` would drop the repeats anyway, but raising it
        // once is clearer about what it means: it is a fact about the CALENDAR,
        // not about one event.
        if ($retained['cut'] === true) {
            $anySeriesCapped = true;
            $anyDateListCut  = true;
            self::note(
                $warnings,
                'This calendar\'s events between them list more added dates, skipped dates or repeat-rule '
                . 'values than the portal holds for one calendar, so the later ones were left out.'
            );
        }

        /** @var list<array<string,mixed>> $collected In file order; sorted at the end. */
        $collected = [];

        $gatheredTooMany = false;

        // Two guards, and WHERE they are checked is the whole point of them.
        //
        // They used to be checked once per UID, at the top of this loop. That
        // reads as "before each event" and is not the same thing: a calendar
        // chooses its own UIDs, and one that gives two hundred and fifty
        // never-ending series the same UID was never checked at all between
        // the first of them and the last. A 31 KB file of exactly that shape
        // used up every byte PHP allows and died with a fatal error, which
        // cannot be caught. So both guards now sit inside the two inner loops,
        // where one turn of the loop is one event's worth of work.
        //
        // The count is checked first and is the one that normally stops this:
        // it behaves the same on every host, while the memory guard depends on
        // what that host's `memory_limit` happens to be. The memory guard
        // stays as the second line, for the shapes a count cannot bound — a
        // few events carrying enormous amounts of text, for instance.
        //
        // What they still cannot do: neither of them runs INSIDE the work one
        // single event does. What bounds that is `MAX_OCCURRENCES_PER_SERIES`
        // (every date one event may contribute, however it was produced),
        // `MAX_DATE_LIST_PER_EVENT` (its skipped and added dates) and the
        // limits inside `parseRule()` on how long a repeat rule's lists may
        // be. Each of those had to be added after a file was measured going
        // straight past these two.
        $overBudget = static function () use (&$collected, &$gatheredTooMany, &$stoppedForMemory, $collectLimit): bool {
            if (count($collected) >= $collectLimit) {
                $gatheredTooMany = true;
                return true;
            }
            if ($stoppedForMemory === true
                || self::memoryRunningOut(self::MEMORY_SHARE_FOR_EXPAND) === true) {
                $stoppedForMemory = true;
                return true;
            }

            return false;
        };

        foreach ($uidOrder as $uidKey) {
            if ($gatheredTooMany === true || $stoppedForMemory === true) {
                break;
            }

            $usedOverrideKeys = [];

            foreach ($masters[$uidKey] ?? [] as $master) {
                if ($overBudget() === true) {
                    break;
                }
                // Read from the event itself rather than from what
                // `occurrencesForMaster()` gives back, because that method's
                // `capped` merges this with the quite different "this series
                // has more dates in the period than are imported" — and only
                // ONE of those two throws the end point away.
                if ((bool) ($master['dateListCapped'] ?? false) === true) {
                    $anyDateListCut = true;
                }
                $seriesResult = self::occurrencesForMaster(
                    $master,
                    $overrides[$uidKey] ?? [],
                    $orgZone,
                    $windowStartLocal,
                    $windowEndLocal,
                    $deadline,
                    $warnings,
                    $usedOverrideKeys
                );
                foreach ($seriesResult['occurrences'] as $occurrence) {
                    $collected[] = $occurrence;
                }
                if ($seriesResult['capped'] === true) {
                    $anySeriesCapped = true;
                    if ($seriesResult['lastStart'] !== null
                        && ($earliestCutOff === null || $seriesResult['lastStart'] < $earliestCutOff)) {
                        $earliestCutOff = $seriesResult['lastStart'];
                    }
                }
            }

            if ($gatheredTooMany === true || $stoppedForMemory === true) {
                break;
            }

            // An override whose series is not in the file at all (some
            // exporters send only the changed date), or one for a date the
            // rule never produced, is kept on its own. It is a real event in
            // the calendar, and dropping it would hide it with nothing to say
            // why.
            foreach ($overrides[$uidKey] ?? [] as $override) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('time budget');
                }
                if ($overBudget() === true) {
                    break;
                }
                // A changed-date block may carry its own skipped/added-date
                // lists, and those are cut short in the same way an ordinary
                // event's are. They never go through `occurrencesForMaster()`,
                // which is where that is normally turned into `capped`, so it
                // is picked up here instead. Without this the warning would be
                // raised while the answer still claimed to be complete.
                if ((bool) ($override['dateListCapped'] ?? false) === true) {
                    $anySeriesCapped = true;
                    $anyDateListCut  = true;
                }
                $key = (string) $override['recurrenceIdKey'];
                if (isset($usedOverrideKeys[$key]) === true) {
                    continue;
                }
                if (isset($masters[$uidKey]) === true && self::keyWasExcluded($masters[$uidKey], $key) === true) {
                    // The series says this date was removed. A removal is an
                    // explicit act by whoever keeps the calendar, so it wins
                    // over a leftover changed-date block.
                    continue;
                }
                // The window is tested on the date itself, not on the finished
                // occurrence. An earlier version passed the finished array here
                // and stopped the whole import with a type error the moment a
                // calendar sent a changed date on its own — which no fixture
                // covered until the mutation run found it.
                if (self::inWindow($override['start'], $windowStartLocal, $windowEndLocal) === false) {
                    continue;
                }
                $collected[] = self::buildOccurrence($override, $override['start'], $key, $orgZone, $override, true, $warnings);
            }
        }

        // Drop repeats of the same identity. The first one in file order is
        // kept, and marked so the importer can tell the administrator that the
        // calendar sends the same event twice.
        $byIdentity = [];
        $unique     = [];
        foreach ($collected as $occurrence) {
            $identity = bin2hex($occurrence['uidHash']) . '|' . $occurrence['recurrenceKey'];
            if (isset($byIdentity[$identity]) === true) {
                $duplicates++;
                $unique[$byIdentity[$identity]]['duplicate'] = true;
                continue;
            }
            $byIdentity[$identity] = count($unique);
            $unique[]              = $occurrence;
        }

        // Sorted by when they start. The title is used only to break a tie, so
        // that a calendar with several events at the same moment comes back in
        // the same order every time — otherwise the list that gets cut at the
        // limit below could differ between two reads of the SAME file, and the
        // importer would delete and recreate rows for no reason.
        usort($unique, static function (array $a, array $b): int {
            return [$a['start'], $a['title']] <=> [$b['start'], $b['title']];
        });

        $capped = $anySeriesCapped;
        if ($gatheredTooMany === true) {
            $capped     = true;
            self::note(
                $warnings,
                'This calendar has far more dates in the period than the portal imports, so it stopped '
                . 'after the first ' . $collectLimit . ' it worked out.'
            );
        }
        if ($stoppedForMemory === true) {
            // `capped` is the flag that already means "this is not all of it".
            // `effectiveWindowEnd` is deliberately NOT set from here: the
            // events were still in the order the file listed them when we
            // stopped, not in date order, so the last one read says nothing
            // about how far through the period the reading got. The method's
            // own notes cover this case — a capped read with no end point
            // covers nothing reliably.
            $capped     = true;
            self::note(
                $warnings,
                'This calendar needed more memory than the portal allows for one calendar, '
                . 'so only the dates worked out before it ran short were imported.'
            );
        }
        if (count($unique) > $feedLimit) {
            $unique = array_slice($unique, 0, $feedLimit);
            $capped = true;
            self::note(
                $warnings,
                'This calendar has more dates in the period than the portal imports ('
                . $feedLimit . '), so the later ones were left out.'
            );
            $lastKept = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string) $unique[count($unique) - 1]['start'],
                $orgZone
            );
            if ($lastKept !== false && ($earliestCutOff === null || $lastKept < $earliestCutOff)) {
                $earliestCutOff = $lastKept;
            }
        }

        if ($gatheredTooMany === true || $stoppedForMemory === true || $anyDateListCut === true) {
            // Whichever end point the steps above worked out, it is thrown
            // away here. The first two stops happen while the dates are still
            // in the order the FILE listed them, not in date order, so an
            // event listed further down could have had dates earlier than the
            // last one kept. The third — a skipped-date or added-date list cut
            // short — loses dates from inside one event's own list, and those
            // dates can fall anywhere in the period, including before any
            // other series' cut-off. Reporting "read reliably up to here"
            // would then be a claim this class cannot stand behind, and an
            // importer that believed it would delete real events. `capped` is
            // true with no end point, which the notes above define as "covers
            // nothing reliably" — the safe reading.
            //
            // The third of those three was missing until a fifth round of
            // independent checking measured it: 500 real dates in 2026,
            // dropped by a cut list, under an end point of February 2027. See
            // the note beside `$anyDateListCut` at the top of this method.
            $earliestCutOff = null;
        }

        // There used to be a de-duplication here — `array_unique()` over the
        // whole list, at the very end. The reason for it was right: the same
        // warning is raised many times over, because an unknown time zone is
        // recorded once for the start, once for the end and once for every
        // skipped date in a long series, and saying it fifty times helps
        // nobody and buries the rest.
        //
        // But doing it HERE meant every one of those copies existed first. One
        // event with a made-up zone name and five and a quarter million
        // skipped dates built 327,680 copies of one sentence and reached
        // 114 MB of the 128 MB PHP usually allows — and then reduced them to
        // one. `note()` now drops a repeat as it is offered, so there is
        // nothing left for this line to do.

        return [
            'occurrences'        => $unique,
            'capped'             => $capped,
            'effectiveWindowEnd' => $earliestCutOff === null ? null : $earliestCutOff->format('Y-m-d H:i:s'),
            'duplicates'         => $duplicates,
            'skippedNoUid'       => $skippedNoUid,
            'attachments'        => $attachments,
            'warnings'           => $warnings,
        ];
    }

    /** The first value of a property, or an empty string when it is not there. */
    private static function firstValue(array $raw, string $name): string
    {
        return isset($raw[$name][0]) === true ? (string) $raw[$name][0]['value'] : '';
    }

    /**
     * Read one VEVENT into the shape the rest of this class works with:
     * cleaned text, a start, how long it lasts, and its repeat information.
     *
     * Returns null when there is no usable start date, which is the one thing
     * an event cannot do without.
     *
     * `$retained` is the running total of the lists EVERY event read so far
     * has kept, and it is the whole of this round's fix. Each of the three
     * lists below is bounded per event, and each is then held for the whole of
     * `expand()`; nothing bounded the sum, and ninety-nine events each staying
     * inside the per-event limit killed the process. Passing the totals in and
     * out is what lets one event's allowance depend on what the events before
     * it already took.
     *
     * @param  list<string> $warnings Added to in place.
     * @param  array{addedDates:int,skippedDates:int,ruleValues:int,cut:bool} $retained Added to in place.
     * @return array<string,mixed>|null
     */
    private static function prepareEvent(
        array $raw,
        string $uid,
        DateTimeZone $orgZone,
        DateTimeZone $floatingZone,
        array &$warnings,
        array &$retained
    ): ?array {
        $startProperty = $raw['DTSTART'][0] ?? null;
        if ($startProperty === null) {
            self::note($warnings, 'An event in this calendar has no start date, so it was left out.');
            return null;
        }
        $start = self::readDateTime(
            (string) $startProperty['value'],
            $startProperty['params'],
            $floatingZone,
            $orgZone,
            $warnings
        );
        if ($start === null) {
            self::note(
                $warnings,
                'An event in this calendar has a start date that could not be read, so it was left out.'
            );
            return null;
        }

        $isAllDay = $start['isDate'];
        $zone     = $start['zone'];

        // How long does it last? Either an end time, or a DURATION, or
        // nothing at all (which means "no length" for a timed event and "one
        // day" for a whole-day one).
        //
        // TWO WAYS OF HOLDING A LENGTH, AND THE DIFFERENCE IS TWICE A YEAR
        // ----------------------------------------------------------------
        // RFC 5545 §3.3.6 separates two kinds of length, and a clock change is
        // the only time they differ:
        //
        //  - an EXACT length is a fixed number of seconds. Five hours is five
        //    hours whatever the clocks do.
        //  - a NOMINAL length is a number of days or weeks, and means "the
        //    same clock reading, so many days later". The day the clocks go
        //    back is 25 hours long, and a one-day event that night still ends
        //    at the same time next morning.
        //
        // `$lengthSeconds` holds an exact length; `$lengthInterval` holds a
        // `DateInterval` read straight from a `DURATION`, which PHP's `add()`
        // already applies exactly the way RFC 5545 asks — days and weeks as
        // wall-clock, hours, minutes and seconds as real seconds. Measured on
        // PHP 8.5.10, 24 October 2026 23:00 London: `+P1D` gives 23:00 the
        // next night (nominal, right) and `+PT5H` gives 03:00 (exact, right).
        //
        // WHAT WAS WRONG BEFORE, because this is the sort of thing that gets
        // "tidied" back: an explicit `DTEND` was turned into a length with
        // `$start->diff($end)` and that interval was then applied with
        // `add()`. An interval that CAME FROM `diff()` is applied by PHP as a
        // wall-clock offset, not as a length in seconds — the same call with
        // the same numbers built by hand behaves differently. So an event
        // written `DTSTART 24 October 23:00, DTEND 25 October 04:00` came back
        // ending at 05:00, an hour late, and its March twin came back an hour
        // early. An overnight vigil, a night shelter or a youth sleepover was
        // shown on the portal with the wrong end time, twice a year, with no
        // warning of any kind. Five rounds of checking walked past it because
        // it is a correctness fault rather than a safety one.
        //
        // WHICH READING THIS IMPLEMENTS FOR A REPEATING EVENT, AND WHY. When a
        // series states a `DTEND`, RFC 5545 §3.8.5.3 says "the same exact
        // duration will apply to all the members of the generated recurrence
        // set" — so the length is worked out once, in seconds, and every date
        // of the series gets exactly that many seconds. When a series states a
        // `DURATION` instead, the same paragraph says the nominal duration
        // applies and "the exact duration of each recurrence instance will
        // depend on its specific start time", which is what keeping the
        // `DateInterval` does.
        //
        // This is a real choice and it is worth knowing that calendar programs
        // disagree about it. Some keep the wall-clock reading for every date
        // of a `DTEND` series, so a 23:00-04:00 booking still shows 04:00 on
        // the night the clocks change rather than 03:00. The checker that
        // found the fault deliberately did not judge that case, so it went to
        // the OWNER, who decided on 23 September 2026 to follow RFC 5545 —
        // because it is the only written rule both a calendar and this portal
        // can be held to. That is a settled decision and not an open question;
        // recording it changed no behaviour at all, only the reason written
        // beside it. A one-off — which is where the fault was actually
        // measured — is not affected by the choice either way, since for a
        // single date "the moment the file states" and "that many seconds
        // after the start" are the same instant.
        $lengthInterval = null;
        $lengthSeconds  = null;
        $allDayDays     = 1;
        $endProperty    = $raw['DTEND'][0] ?? null;
        $durationValue  = self::firstValue($raw, 'DURATION');

        if ($endProperty !== null) {
            $end = self::readDateTime((string) $endProperty['value'], $endProperty['params'], $floatingZone, $orgZone, $warnings);
            if ($end !== null) {
                if ($isAllDay === true) {
                    // RFC 5545 makes a whole-day end exclusive: 24 to 27
                    // December means the 24th, 25th and 26th.
                    //
                    // An end BEFORE the start is treated as no length at all,
                    // so the event covers its start day and nothing more —
                    // the same answer the timed branch below gives, and what
                    // the plan asks for. This used to be `diff()->days` on its
                    // own, and `DateInterval::days` is always positive: a
                    // whole-day event running from 27 December back to the
                    // 24th came out as a THREE-day event on the 27th, 28th
                    // and 29th, dates that appear nowhere in the file. Only a
                    // broken calendar writes this, but a broken calendar is
                    // exactly when invented dates get believed.
                    if ($end['dt'] <= $start['dt']) {
                        $allDayDays = 1;
                    } else {
                        $days       = (int) $start['dt']->diff($end['dt'])->days;
                        $allDayDays = max(1, $days);
                    }
                } elseif ($end['dt'] > $start['dt']) {
                    // The exact number of seconds between the two moments the
                    // file states. Both are real moments, so subtracting their
                    // timestamps is the one measurement no clock change can
                    // confuse. See the long note above for what this replaced
                    // and why the old way was an hour out twice a year.
                    $lengthSeconds = $end['dt']->getTimestamp() - $start['dt']->getTimestamp();
                } else {
                    // An end before or equal to the start is not a length.
                    // Rather than guess, the event is given no length at all,
                    // which the outlets show as a start time only.
                    $lengthSeconds = null;
                }
            }
        } elseif ($durationValue !== '') {
            $interval = self::readDuration($durationValue);
            if ($interval !== null) {
                if ($isAllDay === true) {
                    $allDayDays = max(1, ((int) $interval->d) + (((int) $interval->y) * 365) + (((int) $interval->m) * 30));
                } else {
                    $lengthInterval = $interval;
                }
            }
        }

        $rruleValue = self::firstValue($raw, 'RRULE');
        $rule       = $rruleValue === '' ? null : self::parseRule($rruleValue);

        // The file-wide budget on repeat-rule values, applied AFTER the rule
        // has been read and de-duplicated, because that is the point at which
        // we know how many values this event will actually keep.
        //
        // Why it is needed even though `MAX_RULE_LIST_ENTRIES` already bounds
        // each list: that limit is per event, and 749 `BYDAY` values is the
        // number of DIFFERENT ones there can be, so a file may lawfully give
        // every one of its six thousand events a full set. Seven hundred
        // events doing exactly that, in a 2.86 MB file, used every byte PHP
        // allows — each value is a small array of about 357 bytes, so 749 of
        // them is 267 KB per event and there was nothing to stop the total.
        //
        // Over the budget the rule is refused, which is the treatment every
        // rule this class cannot work out already gets: the series' first date
        // and a warning. The four lists are emptied at the same time, so the
        // memory is handed straight back rather than being held for the whole
        // of `expand()` by a rule nothing will ever read.
        if ($rule !== null) {
            $ruleValues = count($rule['byday']) + count($rule['bymonthday'])
                + count($rule['bymonth']) + count($rule['bysetpos']);
            if (($retained['ruleValues'] + $ruleValues) > self::MAX_RETAINED_RULE_VALUES) {
                $rule['supported']  = false;
                $rule['reason']     = 'This calendar\'s repeat rules together list more values than the portal '
                    . 'holds for one calendar (' . self::MAX_RETAINED_RULE_VALUES . ').';
                $rule['byday']      = [];
                $rule['bymonthday'] = [];
                $rule['bymonth']    = [];
                $rule['bysetpos']   = [];
                $retained['cut']    = true;
            } else {
                $retained['ruleValues'] += $ruleValues;
            }
        }

        if ($rule !== null && $rule['supported'] === false) {
            // The sentence used to end "Only its first date was imported." It
            // was not true, and an administrator reads this warning.
            //
            // This path has ALWAYS kept more than the first date: any date the
            // file adds by hand with an `RDATE` line, and any changed date
            // (`RECURRENCE-ID`) left with no slot to take once the rule is
            // refused. Measured during a seventh round of independent
            // checking: an unsupported rule with one change block comes back
            // with two dates, not one. What is actually left out is the dates
            // the repeat pattern would have produced.
            //
            // So it now says the same thing as the warning for a series whose
            // list of cancelled dates was too long to read, a few hundred
            // lines below. That wording was corrected for exactly this reason
            // one round earlier; this is its twin and was missed then.
            self::note(
                $warnings,
                $rule['reason'] . ' It left out the dates that come from the repeat pattern, and imported '
                . 'only the dates the calendar lists one by one.'
            );
        }

        // Skipped and added dates. Both may appear on several lines and may
        // each hold a comma-separated list, and each line carries its own
        // TZID — which is why they are read with the same reader as DTSTART
        // and turned into the same kind of key.
        //
        // Both lists stop at MAX_DATE_LIST_PER_EVENT PIECES READ, and the
        // count carries across ALL of the event's lines — the allowance
        // belongs to the event, not to each line of it. Neither list used to
        // stop at all: one event carrying a hundred thousand added dates built
        // a hundred thousand date objects and killed the process with a fatal
        // error, from a 1.6 MB file, because the limit on how many dates a
        // series may contribute lived inside the repeat-rule worker and added
        // dates never go through it.
        //
        // `$dateListCapped` is handed back so the answer can be marked
        // `capped`: when a list really is cut, a date the calendar removed can
        // reappear, and the caller must not treat what it got as the whole
        // picture.
        $dateListCapped = false;

        // Set only when THIS EVENT's own allowance is what ran out, as opposed
        // to the calendar's. The two are counted separately a few lines below,
        // and this is what finally makes the warning match the truth.
        //
        // What was wrong before, and it is worth saying because the comment
        // below already claimed the opposite: `$dateListCapped` was set for
        // either reason, and the warning it raised always said "an event in
        // this calendar lists more … than the portal reads (4000)". A file of
        // nine events each listing EXACTLY four thousand added dates — not one
        // of them over the per-event limit — came back with that warning as
        // well as the true one, so an administrator was sent looking for an
        // event that does not exist. The comment beside the two allowances
        // said this must not happen; the code did not do it.
        //
        // Both can be true at once, and then both are said, because both are
        // then honest: a list can use up the last of the event's allowance and
        // the last of the calendar's in the same breath.
        $perEventListCut = false;

        // Set only when the SKIPPED-date list was cut. Kept apart from
        // `$dateListCapped` because the two lists fail in opposite directions
        // and only one of them is dangerous: see the note in
        // `occurrencesForMaster()` about refusing a series whose skipped-date
        // list was cut.
        $exdateListCut = false;

        // Skipped dates are held as the KEYS of an array, not as a list, so
        // that asking "is this date skipped?" costs nothing however long the
        // list is — searching a list of several thousand for each of several
        // hundred dates does not. Repeated dates collapse into one key, which
        // keeps THIS array small.
        //
        // The allowance is counted in PIECES READ, not in keys kept, and the
        // difference matters. It used to be `count($exdateKeys)`, and because
        // duplicates collapse into one key, a calendar that repeated the SAME
        // skipped date over and over never reached the limit at all: every one
        // of five and a quarter million repeats was read, a date object was
        // built for each, and each recorded its own warning. Counting the
        // pieces is what the limit was always meant to mean.
        //
        // TWO allowances apply, and the smaller of them wins. The per-event
        // one stops a single event from carrying an enormous list. The
        // file-wide one (`MAX_RETAINED_SKIPPED_DATES`) stops six thousand
        // events from each carrying a list that is lawful on its own — the
        // fault a fourth round of checking found, where the per-event limit
        // was honoured to the letter by every event and the process still
        // died. Both are counted in pieces READ, so `min()` compares like with
        // like.
        $exdateKeys   = [];
        $exdatePieces = 0;
        foreach ($raw['EXDATE'] ?? [] as $property) {
            // The two allowances are worked out separately, and not merely
            // combined with `min()`, so that the WARNING can name whichever
            // one really stopped the reading. Telling an administrator "this
            // event lists too many" when the truth is "this calendar as a
            // whole lists too many" would send them looking at the wrong
            // event.
            $perEventRoom = self::MAX_DATE_LIST_PER_EVENT - $exdatePieces;
            $fileWideRoom = self::MAX_RETAINED_SKIPPED_DATES - $retained['skippedDates'];
            $room         = min($perEventRoom, $fileWideRoom);
            if ($room < 1) {
                // Either an earlier line of THIS event has used its whole
                // allowance, or the events before it have used the file's, so
                // this line is not read at all. Whichever ran out is the one
                // that gets named; when both have, both are.
                $dateListCapped = true;
                $exdateListCut  = true;
                if ($fileWideRoom < 1) {
                    $retained['cut'] = true;
                }
                if ($perEventRoom < 1) {
                    $perEventListCut = true;
                }
                break;
            }
            $split = self::splitList((string) $property['value'], $room);
            foreach ($split['parts'] as $piece) {
                $exdatePieces++;
                $retained['skippedDates']++;
                $read = self::readDateTime(trim($piece), $property['params'], $floatingZone, $orgZone, $warnings);
                if ($read !== null) {
                    $exdateKeys[self::recurrenceKeyFor($read['dt'], $isAllDay)] = true;
                }
            }
            if ($split['capped'] === true) {
                // `$room` was the smaller of the two allowances, so the
                // smaller one is what stopped the split. A tie means both were
                // used up, and both are then reported.
                $dateListCapped = true;
                $exdateListCut  = true;
                if ($fileWideRoom <= $perEventRoom) {
                    $retained['cut'] = true;
                }
                if ($perEventRoom <= $fileWideRoom) {
                    $perEventListCut = true;
                }
                break;
            }
        }

        // Counted in pieces read, for the same reason as the skipped dates
        // above: the old test was `count($rdates)`, which counts only the
        // pieces that turned out to BE dates, so a list of unreadable rubbish
        // was read to the end however long it was.
        //
        // The same two allowances as the skipped dates above, and this is the
        // list that made the fault fatal: an added date becomes a
        // `DateTimeImmutable`, measured at 388 bytes, from nine bytes of file.
        // Four thousand of them is 1.55 MB for ONE event, and ninety-nine
        // events each staying inside that per-event limit — a 3.57 MB file —
        // asked for more memory than PHP allows and killed the process with an
        // error nothing can catch.
        $rdates      = [];
        $rdatePieces = 0;
        foreach ($raw['RDATE'] ?? [] as $property) {
            $perEventRoom = self::MAX_DATE_LIST_PER_EVENT - $rdatePieces;
            $fileWideRoom = self::MAX_RETAINED_ADDED_DATES - $retained['addedDates'];
            $room         = min($perEventRoom, $fileWideRoom);
            if ($room < 1) {
                $dateListCapped = true;
                if ($fileWideRoom < 1) {
                    $retained['cut'] = true;
                }
                if ($perEventRoom < 1) {
                    $perEventListCut = true;
                }
                break;
            }
            $split = self::splitList((string) $property['value'], $room);
            foreach ($split['parts'] as $piece) {
                $rdatePieces++;
                $retained['addedDates']++;
                // A RDATE may be written as "moment/length". Only the moment
                // is used; the event's own length applies, because a per-date
                // length has nowhere to go in the portal's event table.
                $moment = trim($piece);
                $slash  = strpos($moment, '/');
                if ($slash !== false) {
                    $moment = substr($moment, 0, $slash);
                }
                $read = self::readDateTime($moment, $property['params'], $floatingZone, $orgZone, $warnings);
                if ($read !== null) {
                    $rdates[] = $read['dt'];
                }
            }
            if ($split['capped'] === true) {
                $dateListCapped = true;
                if ($fileWideRoom <= $perEventRoom) {
                    $retained['cut'] = true;
                }
                if ($perEventRoom <= $fileWideRoom) {
                    $perEventListCut = true;
                }
                break;
            }
        }

        // Only when THIS EVENT's own allowance ran out. When it was the
        // calendar's, `expand()` says so once, in words about the calendar —
        // see `$retained['cut']` there. Saying both when only one is true is
        // what this was corrected from.
        if ($perEventListCut === true) {
            self::note(
                $warnings,
                'An event in this calendar lists more skipped or added dates than the portal reads ('
                . self::MAX_DATE_LIST_PER_EVENT . '), so the later ones were left out.'
            );
        }

        $recurrenceIdKey = null;
        $thisAndFuture   = false;
        $recurrenceProperty = $raw['RECURRENCE-ID'][0] ?? null;
        if ($recurrenceProperty !== null) {
            $read = self::readDateTime(
                (string) $recurrenceProperty['value'],
                $recurrenceProperty['params'],
                $floatingZone,
                $orgZone,
                $warnings
            );
            if ($read !== null) {
                $recurrenceIdKey = self::recurrenceKeyFor($read['dt'], $read['isDate']);
                if (strtoupper($recurrenceProperty['params']['RANGE'] ?? '') === 'THISANDFUTURE') {
                    $thisAndFuture = true;
                }
            }
        }

        // "Private" means: any CLASS line with a value other than PUBLIC.
        // RFC 5545 §3.8.1.3 says an unknown value must be treated as the most
        // private one, so CONFIDENTIAL, X-SOMETHING and a misspelling all
        // count. Nothing at all, or PUBLIC, is not private.
        $private   = false;
        $classText = trim(self::unescapeText(self::firstValue($raw, 'CLASS')));
        if ($classText !== '' && strcasecmp($classText, 'PUBLIC') !== 0) {
            $private = true;
        }

        $cancelled = (strcasecmp(trim(self::firstValue($raw, 'STATUS')), 'CANCELLED') === 0);

        // Categories are bounded twice over, and both are needed. The SPLIT
        // stops at `MAX_CATEGORY_LIST_PER_EVENT` pieces, and the list built
        // from them stops at the same number — because the pieces were copied
        // into this second list with nothing checking it, so a
        // `CATEGORIES` line of five and a quarter million commas cost the
        // memory twice. Only the finished, cleaned list was ever cut to
        // twenty, which is far too late to be a limit.
        //
        // Cutting categories does NOT mark the answer `capped`. That flag
        // means "dates may be missing, so do not delete anything", and a
        // missing category is not that. The warning says what happened.
        $categoriesRaw    = [];
        $categoryPieces   = 0;
        $categoriesCut    = false;
        foreach ($raw['CATEGORIES'] ?? [] as $property) {
            $room = self::MAX_CATEGORY_LIST_PER_EVENT - $categoryPieces;
            if ($room < 1) {
                $categoriesCut = true;
                break;
            }
            $split = self::splitList((string) $property['value'], $room);
            foreach ($split['parts'] as $piece) {
                $categoryPieces++;
                $categoriesRaw[] = $piece;
            }
            if ($split['capped'] === true) {
                $categoriesCut = true;
                break;
            }
        }
        if ($categoriesCut === true) {
            self::note(
                $warnings,
                'An event in this calendar lists more categories than the portal reads ('
                . self::MAX_CATEGORY_LIST_PER_EVENT . '), so the later ones were left out.'
            );
        }

        return [
            // The UID is shortened only for showing and storing. The identity
            // below is the SHA-256 of the WHOLE UID as it arrived, so two very
            // long UIDs that happen to share their first 255 characters are
            // still two different events.
            'uid'             => self::cut($uid, 255),
            'uidHash'         => hash('sha256', $uid, true),
            'start'           => $start['dt'],
            'isAllDay'        => $isAllDay,
            'zone'            => $zone,
            // A length read from a `DURATION` (nominal days and weeks, exact
            // hours, minutes and seconds — see the note above).
            'lengthInterval'  => $lengthInterval,
            // A length worked out from an explicit `DTEND`, in whole seconds.
            // Never both: a file states one or the other.
            'lengthSeconds'   => $lengthSeconds,
            'allDayDays'      => $allDayDays,
            'title'           => self::titleOf($raw),
            'description'     => self::cleanDescription(self::unescapeText(self::firstValue($raw, 'DESCRIPTION'))),
            'location'        => self::cleanOneLine(self::unescapeText(self::firstValue($raw, 'LOCATION')), self::MAX_LOCATION),
            'url'             => self::cleanUrl(self::unescapeText(self::firstValue($raw, 'URL'))),
            'categories'      => self::cleanCategories($categoriesRaw),
            'private'         => $private,
            'cancelled'       => $cancelled,
            'rule'            => $rule,
            'exdateKeys'      => $exdateKeys,
            'rdates'          => $rdates,
            // True when either of those two lists was cut short. Read in
            // `occurrencesForMaster()`, which turns it into `capped`, and in
            // `expand()`, which turns it into "no reliable end point".
            'dateListCapped'  => $dateListCapped,
            // True when the SKIPPED-date list in particular was cut short.
            // `occurrencesForMaster()` refuses the whole series on this one,
            // which it does not do for a cut added-date list.
            'exdateListCut'   => $exdateListCut,
            'recurrenceIdKey' => $recurrenceIdKey,
            'thisAndFuture'   => $thisAndFuture,
        ];
    }

    /** The cleaned title, or `(Untitled)` when the calendar gave none. */
    private static function titleOf(array $raw): string
    {
        $title = self::cleanOneLine(self::unescapeText(self::firstValue($raw, 'SUMMARY')), self::MAX_TITLE);

        return $title === '' ? self::UNTITLED : $title;
    }

    /**
     * Every date one event (and its changed dates) contributes.
     *
     * @param  array<string,mixed>       $master
     * @param  list<array<string,mixed>> $overrideList     Changed-date blocks for this same UID.
     * @param  list<string>              $warnings         Added to in place.
     * @param  array<string,bool>        $usedOverrideKeys Which changed dates were used; added to in place.
     * @return array{occurrences:list<array<string,mixed>>, capped:bool, lastStart:?DateTimeImmutable}
     */
    private static function occurrencesForMaster(
        array $master,
        array $overrideList,
        DateTimeZone $orgZone,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        float $deadline,
        array &$warnings,
        array &$usedOverrideKeys
    ): array {
        $rule     = $master['rule'];
        $isSeries = ($rule !== null || $master['rdates'] !== []);

        // A list of skipped or added dates that was cut short while the event
        // was being READ counts as "this is not all of it" just as much as a
        // limit reached here does. Worked out before the one-off case below,
        // because an event can carry a long EXDATE list and no repeat rule at
        // all — nonsense in a calendar, but it still has to be reported
        // honestly rather than silently called complete.
        $capped = ((bool) ($master['dateListCapped'] ?? false) === true);

        if ($isSeries === false) {
            // The window is tested FIRST, and the date is built only if it is
            // kept. It used to be built first, and building a date is where
            // the "this ends after the year 9999" warning is raised — so a
            // calendar holding one absurd event that is not even in the period
            // asked for produced a warning about an event the answer does not
            // contain. An administrator cannot act on that: there is nothing
            // in the list to look at.
            $keep = self::inWindow($master['start'], $windowStart, $windowEnd);

            return [
                'occurrences' => $keep === true
                    ? [self::buildOccurrence($master, $master['start'], '', $orgZone, $master, false, $warnings)]
                    : [],
                'capped'      => $capped,
                'lastStart'   => null,
            ];
        }

        $lastStart = null;
        $starts    = [];

        // A SERIES whose skipped-date list was cut short has its REPEAT RULE
        // thrown away. What is left is the dates the calendar states one by
        // one — the series' own first date and any `RDATE` — and those go
        // through the ordinary loop below like every other date, so the
        // skipped dates that WERE read still remove them, a changed-date
        // block still replaces them, and the period asked for still applies.
        //
        // Why throwing the rule away is right here, rather than doing the best
        // we can. A skipped date (`EXDATE`) is somebody deliberately removing
        // a date: the service is cancelled, the hall is shut, the class is not
        // meeting. Reading only part of that list does not lose dates — it
        // BRINGS BACK dates the calendar's owner deleted. Measured: a daily
        // series running since 1985 with every weekend removed (4,382 skipped
        // dates) gave 365 dates in 2026 where the truth is 261, so 104 deleted
        // weekends came back as real-looking entries. `capped` does not help,
        // because it tells the importer not to DELETE; nothing stops it
        // INSERTING.
        //
        // For a church that is the worst shape there is — a cancelled service
        // shown on the website as going ahead — and a member turns up to a
        // locked building. The dates the file states one by one, plus a
        // warning, is honest: the importer deletes nothing (the answer is
        // `capped`), so dates imported by an earlier, complete refresh stay
        // exactly as they were, and the rule invents nothing to add to them.
        //
        // WHAT THIS DOES NOT GUARANTEE, said plainly because the comment that
        // stood here promised more than the code gave. A date the calendar
        // cancelled with a skipped-date line BEYOND the cut still gets
        // through, by one of THREE routes: it is the first date, it is an
        // added date, or it is a CHANGED date. That is a far smaller exposure
        // than leaving the rule in — one or a handful of stated dates instead
        // of every date the rule produces in the period — but it is not
        // nothing, and nobody should read this as "no cancelled date can come
        // back".
        //
        // The third route was missing from this list until a seventh round of
        // independent checking measured it, and it is worth spelling out
        // because it does not happen here at all. A changed date is a
        // `RECURRENCE-ID` block. With the rule refused, the date that block
        // was meant to replace is never produced, so the block has no slot to
        // take and `expand()` keeps it on its own as an orphan. The orphan is
        // checked against the cancellations that WERE read — and a
        // cancellation past the cut is not one of them. Measured: a daily
        // series with 4,000 cancellations from 2013 and a 4,001st cancelling
        // 10 November 2026, plus a block moving 10 November to 14:00, comes
        // back with that afternoon date; the same calendar with nothing cut
        // gives no 10 November date at all.
        //
        // The list is believed complete at three routes, and a fourth was
        // looked for. A changed date whose cancellation WAS read is dropped;
        // an added date that is also a cancelled date is dropped when nothing
        // was cut; a list that stops exactly on the allowance still sets the
        // flag that refuses the rule. Everything else this series could
        // contribute comes from the repeat rule, and the rule is not run.
        //
        // THE ADDED DATES (`RDATE`) ARE KEPT, and that is a judgement rather
        // than a fact, taken on 23 September 2026 after an independent check
        // asked for it to be settled explicitly. Three reasons.
        // - The first date is kept on exactly the same terms. `DTSTART` is
        //   itself a member of the set of dates a series produces, and a
        //   skipped-date line can cancel it (RFC 5545 §3.8.5.1). So dropping
        //   added dates while keeping the first one would draw a line with no
        //   principle behind it.
        // - An added date is usually the REPLACEMENT for a cancelled one —
        //   "this week the service moves to the hall". Dropping it loses the
        //   date a member most needs, to avoid a risk that needs the same date
        //   to be both added by hand and cancelled by hand in a calendar that
        //   already carries four thousand cancellations.
        // - It is what the unsupported-rule path below already does, so the
        //   two really do behave alike. THE COMMENT THAT USED TO SIT HERE SAID
        //   THEY ALREADY DID, AND THEY DID NOT: this block returned the first
        //   date straight out instead of running it through the loop. A round
        //   of independent checking measured the cost — a first date the
        //   calendar itself cancelled came back as a real event; a first date
        //   moved to the afternoon came back at its original time and was
        //   reported to the importer as the calendar sending the same event
        //   twice; and added dates disappeared. The fix built to stop
        //   cancelled dates coming back brought the first one back.
        //
        // A cut ADDED-date list is deliberately NOT treated this way. It fails
        // the other way round: dates go missing, which is what `capped` is for,
        // and refusing the rule as well would throw away dates that are
        // certainly real in order to avoid dates that are certainly absent.
        //
        // What it costs: nothing that has been shown to happen. The same
        // series since 2010 carries 1,774 skipped dates, comes back exactly
        // right, and raises no warning at all — the limit is 4,000 per event
        // and 200,000 across the whole calendar.
        $exdateListCut = ((bool) ($master['exdateListCut'] ?? false) === true);
        if ($exdateListCut === true) {
            self::note(
                $warnings,
                // The wording deliberately does not name an allowance. The
                // list may have been cut because THIS event listed too many or
                // because the calendar as a whole did, and the warnings that
                // DO name a number are raised separately for each of those.
                'The portal could not read the whole list of removed dates for a repeating event in this '
                . 'calendar, so it could not tell which of its dates had been cancelled. It left out the '
                . 'dates that come from the repeat pattern, and imported only the dates the calendar '
                . 'lists one by one.'
            );
        }

        if ($exdateListCut === true) {
            // The same starting point the unsupported-rule branch below uses,
            // reached by a different route. `$capped` is set here as well,
            // even though a cut skipped-date list always arrives with
            // `dateListCapped` set too, which the top of this method has
            // already turned into `$capped` — the refusal must not quietly
            // depend on another flag staying in step with this one.
            $starts = [$master['start']];
            $capped = true;
        } elseif ($rule !== null && $rule['supported'] === true) {
            $zone     = $master['start']->getTimezone();
            $generated = self::generateStarts(
                $rule,
                $master['start'],
                $windowStart->setTimezone($zone),
                $windowEnd->setTimezone($zone),
                $deadline,
                $warnings
            );
            $starts    = $generated['starts'];
            // Kept, not replaced: the skipped/added-date lists may already
            // have been cut short before the repeat rule was even looked at.
            $capped    = ($capped === true || $generated['capped'] === true);
            $lastStart = $generated['lastStart'] === null
                ? null
                : $generated['lastStart']->setTimezone($orgZone);
        } else {
            // Either there is no repeat rule (dates added one by one with
            // RDATE), or the rule uses something this class does not work out.
            // Both give the series' own first date.
            $starts = [$master['start']];
        }

        foreach ($master['rdates'] as $added) {
            $starts[] = $added;
        }
        $starts = self::mergeCandidates($starts, []);

        $occurrences = [];
        /** @var DateTimeImmutable|null $lastKept The last date actually kept, for the cut-off report below. */
        $lastKept = null;
        foreach ($starts as $originalStart) {
            // This loop had no deadline check at all, and it is not a cheap
            // one: `findOverride()` below walks the whole list of changed
            // dates for every start, so a calendar with thousands of changed
            // dates on one UID costs the number of starts MULTIPLIED by the
            // number of changed dates. A 1.4 MB file of twenty same-UID
            // series and 5,980 changed dates took 9.14 seconds on a
            // five-second budget and finished normally, telling the caller
            // nothing at all. Checking here costs 20 nanoseconds a turn.
            if (microtime(true) > $deadline) {
                throw new RuntimeException('time budget');
            }
            $key = self::recurrenceKeyFor($originalStart, $master['isAllDay']);
            // `exdateKeys` is keyed BY the date key, so this is a direct
            // lookup rather than a search through the whole list.
            if (isset($master['exdateKeys'][$key]) === true) {
                continue;
            }

            $source      = $master;
            $actualStart = $originalStart;
            $override    = self::findOverride($overrideList, $key);
            if ($override !== null) {
                $usedOverrideKeys[$key] = true;
                $source      = $override;
                $actualStart = $override['start'];
                if ($override['thisAndFuture'] === true) {
                    // RANGE=THISANDFUTURE asks for "this date and every one
                    // after it". Applying it to the later dates as well would
                    // rewrite dates the calendar never sent, so it is applied
                    // to this one date and the difference is reported.
                    self::note(
                        $warnings,
                        'A change in this calendar was meant to apply to one date and every later date; '
                        . 'the portal applied it to that one date only.'
                    );
                }
            }

            if (self::inWindow($actualStart, $windowStart, $windowEnd) === false) {
                continue;
            }

            // The limit on how many dates ONE event may contribute, applied
            // here rather than only inside the repeat-rule worker.
            //
            // Why it moved: dates added one by one with RDATE never go through
            // that worker, so an event with no repeat rule at all and a
            // hundred thousand RDATE lines produced a hundred thousand dates
            // and killed the process. Checking here covers both kinds at once
            // — dates from the rule and dates added by hand — which is what
            // the limit's own description has always claimed.
            //
            // Checked BEFORE the date is kept, so a series with exactly the
            // limit's worth of dates is not wrongly reported as cut short.
            if (count($occurrences) >= self::MAX_OCCURRENCES_PER_SERIES) {
                $capped     = true;
                self::note(
                    $warnings,
                    'A repeating event has more dates in this period than the portal imports ('
                    . self::MAX_OCCURRENCES_PER_SERIES . '), so the later ones were left out.'
                );
                // `$starts` is in date order, so the last date kept is the
                // honest point at which this event stopped being read.
                $lastStart = $lastKept === null ? $lastStart : $lastKept->setTimezone($orgZone);
                break;
            }
            $lastKept      = $actualStart;
            $occurrences[] = self::buildOccurrence($source, $actualStart, $key, $orgZone, $master, true, $warnings);
        }

        return ['occurrences' => $occurrences, 'capped' => $capped, 'lastStart' => $lastStart];
    }

    /**
     * The changed-date block for one date, or null.
     *
     * @param list<array<string,mixed>> $overrideList
     * @return array<string,mixed>|null
     */
    private static function findOverride(array $overrideList, string $key): ?array
    {
        foreach ($overrideList as $override) {
            if ((string) $override['recurrenceIdKey'] === $key) {
                return $override;
            }
        }

        return null;
    }

    /**
     * Did any block for this UID say this date was removed?
     *
     * @param list<array<string,mixed>> $masterList
     */
    private static function keyWasExcluded(array $masterList, string $key): bool
    {
        foreach ($masterList as $master) {
            if (isset($master['exdateKeys'][$key]) === true) {
                return true;
            }
        }

        return false;
    }

    /** Is this start inside the window the caller asked for? Compared as moments. */
    private static function inWindow(
        DateTimeImmutable $start,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): bool {
        return ($start >= $windowStart && $start <= $windowEnd);
    }

    /**
     * Build one finished date, ready for the importer.
     *
     * `$source` is where the words and the length come from: the event itself,
     * or the changed-date block when there is one. `$series` is the event the
     * date belongs to, and decides two things that are set for the whole
     * series: whether it is cancelled, and whether it is private. A series
     * marked private stays private on every date, even a changed one that
     * forgot to say so.
     *
     * @param  array<string,mixed> $source
     * @param  array<string,mixed> $series
     * @param  list<string>        $warnings Added to in place.
     * @return array<string,mixed>
     */
    private static function buildOccurrence(
        array $source,
        DateTimeImmutable $start,
        string $recurrenceKey,
        DateTimeZone $orgZone,
        array $series,
        bool $isSeries,
        array &$warnings
    ): array {
        $isAllDay = (bool) $source['isAllDay'];

        if ($isAllDay === true) {
            // Whole days are not moved between zones: Christmas Day is
            // Christmas Day wherever it is read. The day already sits at
            // midnight in the organisation's own zone.
            $startLocal = $start;
            $endLocal   = $start->modify('+' . max(0, ((int) $source['allDayDays']) - 1) . ' days')
                ->setTime(23, 59, 59);
        } else {
            $startLocal = $start->setTimezone($orgZone);
            $endLocal   = $startLocal;
            if ($source['lengthInterval'] instanceof DateInterval) {
                // Read from a `DURATION`. PHP applies a hand-built interval
                // the way RFC 5545 §3.3.6 asks — days and weeks by the clock,
                // hours and minutes and seconds by the second — so this is
                // left exactly as it was.
                $endLocal = $startLocal->add($source['lengthInterval']);
            } elseif (($source['lengthSeconds'] ?? null) !== null) {
                // Read from an explicit `DTEND`: a fixed number of seconds,
                // added to the moment rather than to the clock reading. For a
                // one-off this lands on precisely the moment the file stated,
                // whatever the clocks did in between; for a repeating event it
                // is the "same exact duration" RFC 5545 §3.8.5.3 asks for.
                // `setTimestamp()` keeps the zone, so the answer is still
                // written in the organisation's own time.
                $seconds  = (int) $source['lengthSeconds'];
                $endLocal = $startLocal->setTimestamp($startLocal->getTimestamp() + max(0, $seconds));
            }
            if ($endLocal < $startLocal) {
                $endLocal = $startLocal;
            }
        }

        // The end is brought back to the last moment a database can hold.
        //
        // This is the only place an end is worked out, for whole-day and timed
        // events alike, so it is the one place the check belongs. `makeDate()`
        // already refuses a START outside years 1 to 9999; nothing looked at
        // the END, which is the start plus a length. `DURATION:P999999999999W`
        // — which RFC 5545's grammar allows, since it puts no limit on how
        // many digits a duration may have — gave an end in the year
        // 19,165,351,075. `DTEND:99991231T235959Z` read in London gave
        // 10000-01-01 00:59:59: one second past what MySQL's `DATETIME` column
        // can store, so the importer would have failed to write the row. That
        // is the "failed import" this class promises never to cause.
        //
        // Bringing it back rather than dropping the event: the START is real
        // and inside the window, so the date belongs in the calendar. Only the
        // end is nonsense, and an end of "the end of year 9999" says the same
        // thing an end of "the year 19 billion" says — this never finishes —
        // in a form that can be stored. The warning is there so nobody has to
        // work out from the data why an end moved.
        // How the test is made, and why it looks odd: the end is written out
        // as text here ANYWAY, for the answer below, so the test is made on
        // that same text and costs nothing extra. It was first written as a
        // comparison against a second `DateTimeImmutable` built on the spot,
        // and that showed up in the measurements at once — an ordinary school
        // calendar of 637 dates got 1.7 milliseconds slower, because building
        // a date object is not free and it was being built 637 times for a
        // check that almost never fires.
        //
        // Nineteen characters is what `Y-m-d H:i:s` always takes for a year
        // between 1 and 9999, because PHP pads a short year with noughts
        // (year 5 is written `0005`). So a longer piece of text means a year
        // past 9999, and that is the whole of the first test. A plain
        // comparison of the two pieces of text would NOT do on its own:
        // `19165351075-07-27 …` sorts BEFORE `9999-12-31 …`, because the
        // comparison is letter by letter and `1` comes before `9`, so the
        // worst case of all would have slipped straight through.
        $endText = $endLocal->format('Y-m-d H:i:s');
        if (strlen($endText) !== 19 || $endText > self::LAST_STORABLE_END) {
            $endText = self::LAST_STORABLE_END;
            self::note(
                $warnings,
                'An event in this calendar ends after the end of the year 9999, which is further ahead '
                . 'than dates can be stored, so its end was brought back to 31 December 9999.'
            );
        }

        return [
            'uid'           => (string) $source['uid'],
            'uidHash'       => (string) $series['uidHash'],
            'recurrenceKey' => $recurrenceKey,
            'start'         => $startLocal->format('Y-m-d H:i:s'),
            'end'           => $endText,
            'isAllDay'      => $isAllDay,
            'title'         => (string) $source['title'],
            'description'   => (string) $source['description'],
            'location'      => (string) $source['location'],
            'url'           => $source['url'] === null ? null : (string) $source['url'],
            'categories'    => $source['categories'],
            'private'       => ((bool) $series['private'] === true || (bool) $source['private'] === true),
            'cancelled'     => ((bool) $series['cancelled'] === true || (bool) $source['cancelled'] === true),
            'isSeries'      => $isSeries,
            'duplicate'     => false,
        ];
    }
}
