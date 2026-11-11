<?php
// Path: web/_core/HiddenSeriesDates.php
/**
 * -----------------------------------------------------------------------------
 * Hidden dates of a series, and bringing one back ♻️ (#691)
 * -----------------------------------------------------------------------------
 * Changing a series' pattern HIDES a date the new pattern no longer wants
 * (`isDeleted = 1`) rather than deleting the row, so nothing attached to it is
 * destroyed. Until #691 nothing in the portal could undo that: the only code
 * that ever cleared `isDeleted` on an event was `FeedImporter`, for imported
 * rows, so a date hidden by mistake needed somebody with direct database
 * access — which a customer on shared hosting may not have.
 *
 * This class is the ONE place that lists a series' hidden dates and the ONE
 * place that brings one back. `calendar/manage/series-edit.php` (the series'
 * bulk edit page) draws the list and posts the button; it holds no rule of its
 * own.
 *
 * WHICH DATES ARE LISTED — every hidden date of the series, however it was
 * hidden. That is broader than "the dates a pattern change hid", and on
 * purpose: three things hide an event (the pattern page, the Delete button on
 * the Manage Events page, and the events API), and NONE of them records which
 * one did it. `calendar/manage/delete.php` does not even fill `deletedAt`.
 * Narrowing the list would need a guess — and a guess that hid a date somebody
 * wanted back would put them where #691 started. The page says so in words.
 *
 * WHAT BRINGING ONE BACK DOES, AND WHY
 * -----------------------------------------------------------------------------
 *   • The row is un-hidden IN PLACE: same number, same address, same name,
 *     venue, registrations and everything attached. Nothing is copied.
 *   • IT COMES BACK AS A DRAFT, whatever it was when it was hidden. That is
 *     D-033's reasoning applied to a date coming back: a date nobody has looked
 *     at since it vanished should not reappear in front of members unasked. A
 *     draft is one tick from right on the same page (Status → Published); a
 *     wrongly published service is already in front of everybody.
 *   • The series' repeat rule (`tblRecurrenceRules`) is NOT touched. The
 *     calendar feed already re-checks that a rule matches the series' PUBLISHED
 *     dates before it collapses them into one entry (`calendar/export.php`), so
 *     a restored date — a draft, and later perhaps published — can never be
 *     hidden from subscribers by a rule that does not describe it.
 *
 * WHAT IT REFUSES, each with its own plain sentence (`words()`)
 * -----------------------------------------------------------------------------
 *   • NOT_FOUND — the number is not a hidden, portal-owned date of THIS series
 *     in THIS organisation. ONE answer for "does not exist", "another
 *     organisation's", "another series'", "imported" and "the series has gone",
 *     so the answer can never tell anybody whether another organisation has an
 *     event with that number (#514's rule).
 *   • ALREADY_BACK — the date is a live date of this series already (another
 *     administrator brought it back a moment earlier). Said separately because
 *     it is this organisation's own date, so naming it leaks nothing, and "not
 *     found" would send somebody looking for a fault.
 *   • DAY_TAKEN — the series already has a live date on that DAY. That is the
 *     case that really happens: shorten a series, then extend it over the same
 *     weeks again, and the pattern page makes NEW rows for those days (the
 *     hidden ones keep their addresses for ever). Bringing the old one back
 *     would put the same service in the diary twice, and
 *     `SeriesRepattern` refuses to touch two dates on one day afterwards
 *     (`KEPT_SAME_DAY`), so the series could no longer be re-patterned there.
 *   • ADDRESS_TAKEN — another event in the organisation holds this date's web
 *     address (`eventSlug`). BE PRECISE ABOUT HOW LIKELY THAT IS: while
 *     `uq_event_site_slug (siteID, eventSlug)` exists it CANNOT happen — the
 *     key covers hidden rows, so a hidden date keeps its address for ever and
 *     nothing else can take it (measured: section T of
 *     `tools/event-repeat-pages-selftest.php` asks the database to give a
 *     second row that address and it refuses). The check is kept because #691
 *     asked for it and because a database whose key has been lost — a
 *     one-table restore, a hand-made copy — must still not end up with two
 *     events at one address. Section T reaches it only by dropping the key, a
 *     state the portal itself never makes, and says so.
 *
 * TWO ADMINISTRATORS AT ONCE
 * -----------------------------------------------------------------------------
 * `restore()` opens its transaction FIRST and then takes `SELECT … FOR UPDATE`
 * on the SERIES row before anything else (#697: a lock outside a transaction is
 * decoration). That row is the one thing every restore of this series must
 * pass through, so two restores into the same day queue behind it and the
 * second sees the first's committed date and is refused as DAY_TAKEN. Every
 * later read in the transaction is a locking read too, because a plain SELECT
 * reads the transaction's snapshot and would not see what the first restore
 * just committed. The pattern page does not take this lock, so a restore and a
 * pattern change at the same instant are not serialised by it; the restore's
 * write is conditional (`isDeleted = 1`, the series, the organisation) and the
 * pattern page's own writes are conditional too, so neither can write over a
 * row the other has just changed.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/691
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class HiddenSeriesDates
{
    // 🏷️ What `restore()` answers. `words()` is the only place each becomes
    //    English, so the page can never say something the class did not mean.
    public const RESTORED      = 'restored';
    public const NOT_FOUND     = 'not-found';
    public const ALREADY_BACK  = 'already-back';
    public const DAY_TAKEN     = 'day-taken';
    public const ADDRESS_TAKEN = 'address-taken';
    public const FAILED        = 'failed';

    /**
     * Every hidden, portal-owned date of one series in one organisation,
     * earliest first.
     *
     * The series is asked about in the SAME statement (it must still exist,
     * belong to this organisation and hold no imported date), so a series that
     * has gone lists nothing — its dates' `seriesID` is NULL by then anyway,
     * through `fk_event_series … ON DELETE SET NULL`.
     *
     * @return list<array{eventID:int, eventName:string, startDateTime:string, endDateTime:?string, status:string, dayTaken:bool}>
     */
    public static function listFor(mysqli $db, int $siteId, int $seriesId): array
    {
        $stmt = $db->prepare(
            'SELECT `e`.`eventID`, `e`.`eventName`, `e`.`startDateTime`, `e`.`endDateTime`, `e`.`status`, '
            // 📅 Does a LIVE date of the series already sit on this day? Asked
            //    here only so the page can say so beside the button; the
            //    restore asks again, under its lock, and that answer is the one
            //    that counts. `DATE()` of a DATETIME is the text's own date —
            //    no time zone is applied, so a wall-clock reading stays one.
            . '(SELECT COUNT(*) FROM `tblEvents` `live` WHERE `live`.`seriesID` = `e`.`seriesID` '
            . '   AND `live`.`siteID` = `e`.`siteID` AND `live`.`isDeleted` = 0 AND `live`.`externalFeedID` IS NULL '
            . '   AND DATE(`live`.`startDateTime`) = DATE(`e`.`startDateTime`)) AS `dayTaken` '
            . 'FROM `tblEvents` `e` '
            . 'JOIN `tblEventSeries` `s` ON `s`.`seriesID` = `e`.`seriesID` AND `s`.`siteID` = `e`.`siteID` '
            . 'WHERE `e`.`seriesID` = ? AND `e`.`siteID` = ? AND `e`.`isDeleted` = 1 AND `e`.`externalFeedID` IS NULL '
            . '  AND NOT EXISTS (SELECT 1 FROM `tblEvents` `xi` WHERE `xi`.`seriesID` = `s`.`seriesID` AND `xi`.`externalFeedID` IS NOT NULL) '
            . 'ORDER BY `e`.`startDateTime` ASC, `e`.`eventID` ASC'
        );
        if ($stmt === false) {
            // Only reachable if the connection stops throwing on a fault.
            return [];
        }
        $stmt->bind_param('ii', $seriesId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        $out    = [];
        while ($row = $result->fetch_assoc()) {
            $out[] = [
                'eventID'       => (int) $row['eventID'],
                'eventName'     => (string) $row['eventName'],
                'startDateTime' => (string) $row['startDateTime'],
                'endDateTime'   => $row['endDateTime'] === null ? null : (string) $row['endDateTime'],
                'status'        => (string) $row['status'],
                'dayTaken'      => (int) $row['dayTaken'] > 0,
            ];
        }
        $stmt->close();

        return $out;
    }

    /**
     * Bring one hidden date of a series back, as a draft.
     *
     * The caller has already decided that the person may manage this series
     * (the series pages ask `App::isAdmin()`) and has checked the form token;
     * this method decides only whether THIS date may come back, and does it.
     *
     * Never throws: a fault rolls back, is recorded without its own words (a
     * database error can quote a value, here an event's name), and answers
     * FAILED.
     */
    public static function restore(mysqli $db, int $siteId, int $seriesId, int $eventId, ?int $userId): string
    {
        if ($seriesId <= 0 || $eventId <= 0) {
            return self::NOT_FOUND;
        }

        // 🔒 THE TRANSACTION FIRST, THEN THE LOCKS. Taking `FOR UPDATE` before
        //    `begin_transaction()` locks nothing that outlives the statement.
        $db->begin_transaction();
        try {
            // 1️⃣ The series, locked. Still there, still this organisation's,
            //    and holding no imported date — the same test the series pages
            //    make, so a series they would refuse to open is refused here
            //    too. The lock lands on the series row alone: the subquery has
            //    no locking clause of its own, so it locks nothing. The
            //    organisation is NOT joined in here, because a joined row is
            //    locked as well and `FOR UPDATE OF` (which would narrow it) is
            //    MySQL-only; it is asked on its own in step 2.
            $series = $db->prepare(
                'SELECT `s`.`seriesID` FROM `tblEventSeries` `s` '
                . 'WHERE `s`.`seriesID` = ? AND `s`.`siteID` = ? '
                . '  AND NOT EXISTS (SELECT 1 FROM `tblEvents` `xi` WHERE `xi`.`seriesID` = `s`.`seriesID` AND `xi`.`externalFeedID` IS NOT NULL) '
                . 'FOR UPDATE'
            );
            if ($series === false) {
                throw new \RuntimeException('the series could not be read');
            }
            $series->bind_param('ii', $seriesId, $siteId);
            $series->execute();
            $seriesRow = $series->get_result()->fetch_row();
            $series->close();
            if ($seriesRow === null) {
                $db->rollback();

                return self::NOT_FOUND;
            }

            // 2️⃣ The organisation, which must still be an active one. Never
            //    bring a date back into an organisation that has been switched
            //    off: nobody could see it, and it would come back to life the
            //    day the organisation was switched on again, unasked.
            $site = $db->prepare('SELECT `siteID` FROM `tblSites` WHERE `siteID` = ? AND `isActive` = 1');
            if ($site === false) {
                throw new \RuntimeException('the organisation could not be read');
            }
            $site->bind_param('i', $siteId);
            $site->execute();
            $siteRow = $site->get_result()->fetch_row();
            $site->close();
            if ($siteRow === null) {
                $db->rollback();

                return self::NOT_FOUND;
            }

            // 3️⃣ The date itself, locked, scoped to THIS organisation and THIS
            //    series and never an imported row. Anything else — including
            //    another organisation's event number — is NOT_FOUND, the same
            //    answer as a number that does not exist.
            $event = $db->prepare(
                'SELECT `eventID`, `eventSlug`, `startDateTime`, `isDeleted` FROM `tblEvents` '
                . 'WHERE `eventID` = ? AND `siteID` = ? AND `seriesID` = ? AND `externalFeedID` IS NULL '
                . 'FOR UPDATE'
            );
            if ($event === false) {
                throw new \RuntimeException('the date could not be read');
            }
            $event->bind_param('iii', $eventId, $siteId, $seriesId);
            $event->execute();
            $row = $event->get_result()->fetch_assoc();
            $event->close();
            if ($row === null) {
                $db->rollback();

                return self::NOT_FOUND;
            }
            if ((int) $row['isDeleted'] !== 1) {
                $db->rollback();

                return self::ALREADY_BACK;
            }

            // 4️⃣ Nobody else holds its web address. See the class comment:
            //    unreachable while the unique key exists, kept for a database
            //    that has lost it. No `isDeleted` and no `externalFeedID` here —
            //    the question is "would two rows share an address", and a hidden
            //    or imported row holds an address exactly as a live one does.
            $slug     = (string) $row['eventSlug'];
            $slugTest = $db->prepare(
                'SELECT `eventID` FROM `tblEvents` WHERE `siteID` = ? AND `eventSlug` = ? AND `eventID` <> ? LIMIT 1 FOR UPDATE'
            );
            if ($slugTest === false) {
                throw new \RuntimeException('the web address could not be checked');
            }
            $slugTest->bind_param('isi', $siteId, $slug, $eventId);
            $slugTest->execute();
            $slugTaken = $slugTest->get_result()->fetch_row() !== null;
            $slugTest->close();
            if ($slugTaken === true) {
                $db->rollback();

                return self::ADDRESS_TAKEN;
            }

            // 5️⃣ No live date of this series on the same DAY. A locking read,
            //    so a restore that committed while this one waited for the
            //    series lock is seen. The day is the reading's own first ten
            //    characters — a date on the event's own clock, never a moment.
            $day     = substr((string) $row['startDateTime'], 0, 10);
            $dayTest = $db->prepare(
                'SELECT `eventID` FROM `tblEvents` '
                . 'WHERE `seriesID` = ? AND `siteID` = ? AND `isDeleted` = 0 AND `externalFeedID` IS NULL '
                . '  AND `eventID` <> ? AND DATE(`startDateTime`) = ? LIMIT 1 FOR UPDATE'
            );
            if ($dayTest === false) {
                throw new \RuntimeException('the series\' other dates could not be checked');
            }
            $dayTest->bind_param('iiis', $seriesId, $siteId, $eventId, $day);
            $dayTest->execute();
            $dayTaken = $dayTest->get_result()->fetch_row() !== null;
            $dayTest->close();
            if ($dayTaken === true) {
                $db->rollback();

                return self::DAY_TAKEN;
            }

            // 6️⃣ The write. Every condition the reads established is repeated
            //    on the statement itself, so it changes this one row or none.
            //    `deletedAt` is emptied because the column means "when it was
            //    hidden", and a live row was not. Status: a DRAFT (see the class
            //    comment and D-033).
            $write = $db->prepare(
                "UPDATE `tblEvents` SET `isDeleted` = 0, `deletedAt` = NULL, `status` = 'draft', `updatedByID` = ? "
                . 'WHERE `eventID` = ? AND `siteID` = ? AND `seriesID` = ? AND `isDeleted` = 1 AND `externalFeedID` IS NULL'
            );
            if ($write === false) {
                throw new \RuntimeException('the date could not be brought back');
            }
            $write->bind_param('iiii', $userId, $eventId, $siteId, $seriesId);
            $write->execute();
            $changed = $write->affected_rows;
            $write->close();
            if ($changed !== 1) {
                // Cannot happen under the lock taken in step 3; if it ever
                // does, nothing is claimed.
                $db->rollback();

                return self::NOT_FOUND;
            }

            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
                // The original fault is the one worth recording.
            }
            Logger::errorPlatform(
                'Calendar',
                'Error',
                'SERIES_DATE_RESTORE_FAILED',
                'a hidden date of a series could not be brought back, so nothing was changed',
                'seriesID=' . $seriesId . ' eventID=' . $eventId
            );

            return self::FAILED;
        }

        // 📝 Numbers only: an event's name can carry somebody's details.
        Logger::activity(
            'SeriesDateRestored',
            'Event #' . $eventId . ' of series #' . $seriesId . ' brought back as a draft',
            $userId
        );

        return self::RESTORED;
    }

    /**
     * The plain sentence for one answer of `restore()`, and whether it is good
     * news. The ONLY place these words are written.
     *
     * @return array{type:string, words:string}
     */
    public static function words(string $outcome): array
    {
        return match ($outcome) {
            self::RESTORED => [
                'type'  => 'success',
                'words' => 'That date is back in the series as a draft, so members cannot see it yet. '
                    . 'Tick it in the list below and set Status to Published when it is ready.',
            ],
            self::ALREADY_BACK => [
                'type'  => 'info',
                'words' => 'That date is already back in the series — somebody else may have just brought it back. Nothing was changed.',
            ],
            self::DAY_TAKEN => [
                'type'  => 'warning',
                'words' => 'Nothing was changed. This series already has a date on that day, so bringing the hidden one back '
                    . 'would put the same day in the diary twice. Hide or move the date that is there now first if you want '
                    . 'the old one back instead.',
            ],
            self::ADDRESS_TAKEN => [
                'type'  => 'warning',
                'words' => 'Nothing was changed. Another event in this organisation now uses that date\'s web address, so '
                    . 'bringing it back would give two events the same address.',
            ],
            self::NOT_FOUND => [
                'type'  => 'danger',
                'words' => 'That date could not be brought back: it is not a hidden date of this series. Nothing was changed.',
            ],
            default => [
                'type'  => 'danger',
                'words' => 'Something went wrong, so nothing was changed. Please try again.',
            ],
        };
    }
}
