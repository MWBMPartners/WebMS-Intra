<?php
// Path: _core/EventDateRange.php
/**
 * -----------------------------------------------------------------------------
 * 📅 The range of dates an event may be stored on (#1045, D-181)
 * -----------------------------------------------------------------------------
 * The ONE place that decides whether an event's start (and end) is a date the
 * portal will store. Every writer of `tblEvents.startDateTime` /
 * `endDateTime` asks it: the public "suggest an event" form, the events API
 * (create and update), Manage Events (a single event, the dates a repeat
 * adds, and a series' pattern change), the CSV import, and the outside
 * calendar importer. Do not write a second year test in a page.
 *
 * WHY THERE IS A RANGE AT ALL. Every one of those writers accepted any date a
 * `DATETIME` column holds, so `0001-01-01` and `9999-12-31` could be stored.
 * Such a date is almost certainly a typing slip, and one such row made the
 * calendar list view unusable for a whole organisation in the first version
 * of #1024 (fixed there by bounding the work). The owner set the range on
 * 12 November 2026 (D-181): "from 1900 until 2200 (or at least 50 years
 * ahead, whichever is further)".
 *
 * So an event may start from 1 January 1900 00:00:00 to the last second of
 * 31 December of the LATER of 2200 and (this year + 50). The upper year is
 * WORKED OUT every time, never written down: in 2160 it becomes 2210.
 *
 * ⚠️ IT BUILDS NO MOMENT. The columns hold a reading from a clock (#777), and
 *    the range is a whole number of calendar years, so the YEAR DIGITS of the
 *    reading are all that is compared. Turning the reading into a moment to
 *    compare it would bring back the hour that does not exist, for nothing.
 *
 * WHY THE END IS CHECKED TOO (the builder's choice, recorded on #1045). The
 * decision names the start. Most writers already refuse an end before its
 * start, but nothing stopped an end in the year 9999 after a start in 2026,
 * and it is the END that makes a page walk an event's days one by one — the
 * very harm #1024 met. An end inside the same range costs nobody anything
 * real: an event that starts in range and genuinely ends past the last
 * allowed year would have to last decades.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd. All Rights Reserved.
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1045
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class EventDateRange
{
    /** The earliest year an event may start in (from 1 January, 00:00:00). */
    public const FIRST_YEAR = 1900;

    /** The latest year is never EARLIER than this one... */
    public const LAST_YEAR_AT_LEAST = 2200;

    /** ...and never fewer than this many years after the current one. */
    public const YEARS_AHEAD = 50;

    /**
     * The last year an event may start in: the later of 2200 and this year
     * plus fifty.
     *
     * @param int|null $currentYear For tests; null means the year today on
     *                              PHP's clock, which start-up sets to the
     *                              organisation's. A few hours' difference at
     *                              New Year moves nothing until the 2150s,
     *                              and then only by one year at the far end.
     */
    public static function lastYear(?int $currentYear = null): int
    {
        $year = $currentYear ?? (int) date('Y');

        return max(self::LAST_YEAR_AT_LEAST, $year + self::YEARS_AHEAD);
    }

    /**
     * Is this stored reading inside the range?
     *
     * @param string   $reading     `Y-m-d H:i:s` (or any text that begins
     *                              `YYYY-MM-DD`). Anything else — a minus
     *                              sign, a five-digit year — is outside.
     * @param int|null $currentYear For tests only.
     */
    public static function contains(string $reading, ?int $currentYear = null): bool
    {
        // Compare the YEAR DIGITS, never a moment (see the header).
        if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $reading, $parts) !== 1) {
            return false;
        }
        $year = (int) $parts[1];

        return $year >= self::FIRST_YEAR && $year <= self::lastYear($currentYear);
    }

    /**
     * The plain reason a writer gives when a date is outside the range, or
     * null when every reading given is inside it.
     *
     * @param list<string|null> $readings    The start, and the end if there is
     *                                       one. A null (no end) is skipped.
     * @param int|null          $currentYear For tests only.
     */
    public static function whyRefused(array $readings, ?int $currentYear = null): ?string
    {
        foreach ($readings as $reading) {
            if ($reading === null) {
                continue;
            }
            if (self::contains($reading, $currentYear) === false) {
                return self::refusalWords($currentYear);
            }
        }

        return null;
    }

    /**
     * The one sentence naming the allowed range. Every writer shows these
     * words, so the public form, Manage Events and the API cannot disagree.
     */
    public static function refusalWords(?int $currentYear = null): string
    {
        return 'An event must start and end between 1 January ' . self::FIRST_YEAR
            . ' and 31 December ' . self::lastYear($currentYear)
            . '. Please check the year.';
    }
}
