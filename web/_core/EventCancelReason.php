<?php
// Path: web/_core/EventCancelReason.php
/**
 * -----------------------------------------------------------------------------
 * Why an event is cancelled or postponed ✍️ (#998)
 * -----------------------------------------------------------------------------
 * `tblEvents.cancelReason` is shown to members on an event's own page, under
 * the "This event has been cancelled" or "This event has been postponed"
 * banner (`calendar/event.php`), and is returned by the events API's `detail`.
 * Until #998 NOTHING under web/ wrote it — every reference was a read
 * (established with `grep -rn cancelReason web/_apps web/_core` on commit
 * `e9111b57`, which answers readers only) — so a cancelled date could carry a
 * reason only if somebody typed one into the database by hand.
 *
 * This class is the ONE place that decides what the column holds, and the ONE
 * place that writes it on an existing event:
 *
 *   • `whyRefused()`  — whether a posted reason can be taken at all (a text,
 *                        no longer than `MAX_LENGTH` characters). Over-long is
 *                        REFUSED with the reason, never cut short: quietly
 *                        shortening what somebody typed to tell a congregation
 *                        why a service is off would publish half a sentence.
 *   • `normalise()`   — the text as it is stored: trimmed, Windows line ends
 *                        made plain, and an empty box stored as NULL.
 *   • `toStore()`     — the value for a NEW row (the Manage Events create
 *                        form), worked out from its status.
 *   • `write()`       — the one UPDATE of the column on rows that exist.
 *
 * THE RULE, in one line: a reason is kept only while the event's status is
 * `cancelled` or `postponed` (`STATUSES`). When the status is anything else
 * the reason is EMPTIED, so a reason can never be left lying on a published
 * date and reappear, out of date, the next time somebody cancels it.
 *
 * `write()` DECIDES FROM THE STATUS THE ROW HOLDS, not from the status the
 * caller meant to set. It runs after the caller's status UPDATE, in the same
 * transaction, and its one statement is
 *
 *     cancelReason = CASE WHEN status IN ('cancelled','postponed') THEN <new> ELSE NULL END
 *
 * so whatever the row's status really is at that moment, the reason agrees
 * with it. That is deliberate: a second administrator, the events API or a
 * restore can change a status too, and a writer that trusted its caller's idea
 * of the status could leave the two disagreeing.
 *
 * "DID THE FIELD ARRIVE" (#677). `write()` takes `$arrived`: false means "the
 * caller did not send a reason, so leave each kept reason as it is" — the
 * events API (which has no reason field) and the series bulk update when its
 * reason box is left empty. Even then a reason is still EMPTIED on a row whose
 * status no longer carries one. True means "this is the reason now", and an
 * empty value with `$arrived` true clears it.
 *
 * WHAT IT DOES NOT DO
 *   • It does not touch a HIDDEN date (`isDeleted = 1`). A hidden date keeps
 *     its reason so it can come back with it (D-146, D-147); the only thing
 *     that changes a hidden date is `HiddenSeriesDates::restore()`.
 *   • It does not touch an imported event (`externalFeedID IS NOT NULL`):
 *     `FeedImporter` is the only writer of those (#514).
 *   • It does not decide who may change a status. Each caller keeps its own
 *     gate (an administrator and a form token on the two pages; the API's own
 *     `events:write`).
 *   • It does not escape. The column holds what was typed; every page that
 *     shows it escapes it there (`calendar/event.php` uses
 *     `htmlspecialchars()` then `nl2br()`).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/998
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class EventCancelReason
{
    /**
     * 🚦 The statuses that carry a reason. Every other status has none.
     *
     * The same two words as `HiddenSeriesDates::KEEPS_STATUS` and NOT the same
     * list on purpose: that one answers "which statuses survive being brought
     * back", this one "which statuses show members a reason". They agree today
     * because both are "the event is not going ahead as planned"; a third
     * status could belong to one and not the other.
     *
     * @var list<string>
     */
    public const STATUSES = ['cancelled', 'postponed'];

    /**
     * ✂️ The longest reason accepted, in CHARACTERS (`mb_strlen`), not bytes.
     * The column is TEXT (65,535 bytes); a thousand characters is at most
     * 4,000 bytes in utf8mb4, so the column can always hold it. A thousand is
     * a short paragraph — room for "the hall is flooded; we will meet at the
     * school instead" many times over — and a limit at all stops the banner
     * on the event's page being used to publish an essay.
     */
    public const MAX_LENGTH = 1000;

    /** Whether an event with this status shows members a reason. */
    public static function carriesReason(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    /**
     * Why a posted reason cannot be taken, or null when it can.
     *
     * `$raw` is the posted value AS IT ARRIVED: a request chooses the TYPE of
     * what it sends as well as the value, so `cancelReason[]=x` arrives as an
     * array and must be refused here rather than reach `trim()` and throw
     * (the #509 / two-factor gate lesson).
     */
    public static function whyRefused(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw) === false) {
            return 'The reason for cancelling or postponing could not be read. Nothing was saved.';
        }
        $length = mb_strlen(self::plain($raw), 'UTF-8');
        if ($length > self::MAX_LENGTH) {
            return 'The reason for cancelling or postponing is ' . number_format($length) . ' characters long; it can be at most '
                . number_format(self::MAX_LENGTH) . '. Shorten it and save again. Nothing was saved.';
        }

        return null;
    }

    /**
     * The reason as it is stored: trimmed, with Windows line endings (which a
     * browser sends from a text box) made plain, and NULL for an empty box —
     * never the empty string, because every reader asks "is there a reason"
     * and an empty string answers that differently in PHP and in SQL.
     */
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $plain = self::plain($raw);

        return $plain === '' ? null : $plain;
    }

    /**
     * The value for a NEW row: the reason when the status carries one, NULL
     * otherwise. Used by the create form, whose INSERT names the column so the
     * dates a repeat makes in the same request (copied from that row) carry
     * the same reason as the same status.
     */
    public static function toStore(string $status, ?string $reason): ?string
    {
        return self::carriesReason($status) === true ? self::normalise($reason) : null;
    }

    /**
     * ✍️ THE one UPDATE of `cancelReason` on events that already exist.
     *
     * Call it AFTER the status has been written, inside the caller's own
     * transaction, so the status and the reason land together. It decides from
     * the status each row holds at that moment (see the file header).
     *
     * Rows it may change: these numbers, in THIS organisation, live
     * (`isDeleted = 0`) and the portal's own (`externalFeedID IS NULL`).
     * Anything else among `$eventIds` is left alone, so a number posted by
     * hand for another organisation's event, a hidden date or an imported
     * event changes nothing.
     *
     * @param list<int> $eventIds
     * @param int|null  $seriesId when given, only dates of THAT series are
     *                            touched — the series bulk update passes it,
     *                            so a number posted by hand for another
     *                            series' date cannot have its reason changed
     *                            while its status (whose UPDATE carries the
     *                            same series test) was not.
     *
     * @return array{cleared:int, took:int} read under the same lock as the
     *         write, so a page can say each truthfully:
     *         `cleared` — how many of those rows HAD a reason and lose it
     *         because their status no longer carries one;
     *         `took` — how many rows took the reason SENT (0 unless
     *         `$arrived`): the rows in scope whose status carries one. Ask
     *         this, never the caller's own status UPDATE's affected-row count,
     *         which counts rows whose values CHANGED. That statement also
     *         writes `updatedByID`, so it is 0 for a date only when the date
     *         already had that status AND the same administrator made its last
     *         edit — and such a date still takes the new reason here (round 2
     *         of #998's check measured the page saying "0 event(s) updated"
     *         over a reason it had just stored, in exactly that case).
     */
    public static function write(mysqli $db, int $siteId, array $eventIds, bool $arrived, ?string $reason, ?int $seriesId = null): array
    {
        $ids = array_values(array_filter(
            array_map('intval', $eventIds),
            static fn (int $id): bool => $id > 0
        ));
        if ($ids === []) {
            return ['cleared' => 0, 'took' => 0];
        }
        $idMarks     = implode(', ', array_fill(0, count($ids), '?'));
        $statusMarks = implode(', ', array_fill(0, count(self::STATUSES), '?'));
        $scope       = '`eventID` IN (' . $idMarks . ') AND `siteID` = ? AND `isDeleted` = 0 AND `externalFeedID` IS NULL'
            . ($seriesId !== null ? ' AND `seriesID` = ?' : '');
        // The values `$scope` asks for, in its order, and their types.
        $scopeTypes  = str_repeat('i', count($ids)) . 'i' . ($seriesId !== null ? 'i' : '');
        $scopeValues = array_merge($ids, [$siteId], $seriesId !== null ? [$seriesId] : []);

        // 🔢 What is about to be emptied, and what is about to take the
        //    reason, counted FIRST and under a lock, so the counts and the
        //    write describe the same rows. (The lock is real only inside the
        //    caller's transaction — #697 — which is where every caller is.)
        //    `status IN (...)` is 1 or 0 in MySQL and MariaDB, so SUM counts.
        $count = $db->prepare(
            'SELECT '
            . 'COALESCE(SUM(`cancelReason` IS NOT NULL AND `status` NOT IN (' . $statusMarks . ')), 0) AS `cleared`, '
            . 'COALESCE(SUM(`status` IN (' . $statusMarks . ')), 0) AS `carrying` '
            . 'FROM `tblEvents` WHERE ' . $scope . ' FOR UPDATE'
        );
        $countTypes  = str_repeat('s', 2 * count(self::STATUSES)) . $scopeTypes;
        $countValues = array_merge(self::STATUSES, self::STATUSES, $scopeValues);
        $count->bind_param($countTypes, ...$countValues);
        $count->execute();
        $counted  = $count->get_result()->fetch_assoc() ?? [];
        $cleared  = (int) ($counted['cleared'] ?? 0);
        $carrying = (int) ($counted['carrying'] ?? 0);
        $count->close();

        // ✍️ The write. With no reason sent, a row whose status still carries
        //    one keeps its own (`THEN cancelReason`); with one sent, it takes
        //    that (`THEN ?`). Either way a row whose status carries none is
        //    emptied. Both statements end in `$scope`, which carries
        //    `externalFeedID` IS NULL: an imported event is never touched
        //    (#514), and check_event_visibility.py reads this line for it.
        $keep   = $arrived === true ? '?' : '`cancelReason`';
        $update = $db->prepare(
            'UPDATE `tblEvents` SET `cancelReason` = CASE WHEN `status` IN (' . $statusMarks . ') THEN ' . $keep . ' ELSE NULL END '
            . 'WHERE ' . $scope
        );
        $types  = str_repeat('s', count(self::STATUSES));
        $values = self::STATUSES;
        if ($arrived === true) {
            $types   .= 's';
            $values[] = self::normalise($reason);
        }
        $types  .= $scopeTypes;
        $values  = array_merge($values, $scopeValues);
        $update->bind_param($types, ...$values);
        $update->execute();
        $update->close();

        return ['cleared' => $cleared, 'took' => $arrived === true ? $carrying : 0];
    }

    /** Trimmed, with `\r\n` and a lone `\r` both made `\n`. */
    private static function plain(string $raw): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $raw));
    }
}
