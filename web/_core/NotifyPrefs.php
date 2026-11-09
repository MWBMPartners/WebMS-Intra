<?php
// Path: _core/NotifyPrefs.php
/**
 * -----------------------------------------------------------------------------
 * A member's notification choices — the ONE writer of tblUsers.notifyPrefs 🔔
 * -----------------------------------------------------------------------------
 * A member's notification choices live in one column, `tblUsers.notifyPrefs`,
 * as a JSON object such as `{"eventReminders": true, "pushLivestream": false}`.
 * Every sender reads it through `WebPush::notifyPrefAllows()` (or one of its
 * older copies), where a key that is MISSING means YES.
 *
 * WHAT WAS WRONG (#667). Two pages wrote the column, in two different ways:
 *
 *   - `account/notifications/save` (the full Notification preferences page)
 *     REBUILT the whole column from its own list of keys and wrote it over the
 *     top, so every key it did not list — including any key a future page or
 *     an older version stored — was DROPPED the next time the member pressed
 *     Save;
 *   - `account/save`, action `update_notifications` (the small card on
 *     /account), read the column, changed its own three keys and wrote it
 *     back (#612 had fixed it to do that) — but with no lock, so two saves at
 *     the same moment could each read the old value and the second write lost
 *     the first's change; and it wrote `false` for two keys its form no longer
 *     drew, because an unticked box and an absent box look the same.
 *
 * WHAT THIS CLASS DOES INSTEAD. Both pages call `merge()`, which changes ONLY
 * the keys the submitting form actually carried and keeps every other key
 * exactly as stored — including keys no page shows today. It reads the column
 * with `SELECT … FOR UPDATE` INSIDE the transaction that writes it (#647: a
 * lock outside a transaction is decoration), so a second save waits for the
 * first and then merges into what the first wrote.
 *
 * HOW A FORM SAYS WHICH KEYS IT CARRIES. An unticked checkbox sends nothing,
 * so "off" and "this form never drew that switch" are indistinguishable from
 * the switch alone. So beside every switch a page draws a hidden marker,
 * `prefsShown[<key>] = 1` (`shownMarker()`), and the switch itself is
 * `prefs[<key>] = 1` when ticked. A key with a marker and no tick is OFF; a
 * key with no marker is NOT TOUCHED. A post with no `prefsShown` at all
 * (an out-of-date page, or a request from somewhere else) changes NOTHING —
 * #677's rule: ask whether a field ARRIVED, never what it contains.
 *
 * The marker is KEYED (`prefsShown[eventReminders]`) rather than a list
 * (`prefsShown[]`) on purpose: each marker is then a field with its own name,
 * so nothing that reads a form into a name → value map — which several of
 * this repository's page tests do — can collapse twenty markers into one.
 *
 * WHAT IS DONE WITH A STORED VALUE THAT CANNOT BE READ (decided here, and
 * stated because the choice is not obvious). If the column holds text that is
 * not a JSON object — corrupt text, or a JSON list or a bare number — the save
 * REPLACES it with an object holding only the keys this save set, and says so
 * (`replacedUnreadable`), so the caller can record it. The alternatives were
 * worse: refusing the save would lock that member out of their own choices
 * for ever, since no page lets anybody repair the column. And replacing it
 * changes NO OTHER answer any reader gives: every reader of this column —
 * `WebPush::notifyPrefAllows()`, `Workflow`'s and `cron/user-reminders.php`'s
 * copies, `Giving::isOptedOutOfStatements()`, and both pages — already treats
 * a value it cannot read as "every choice is ON", and a key missing from the
 * new object also reads as ON. So the only answers that change are the ones
 * the member just chose. What IS lost is the unreadable text itself, which
 * nothing could read. (Checked by reading each of those readers on 9 November
 * 2026, `grep -rn notifyPrefs web/_core web/_apps`.) An empty column, SQL
 * NULL, or the JSON literal `null` simply means "nothing chosen yet" and is
 * not reported.
 *
 * WHAT THIS CANNOT DO, said plainly:
 *   - It cannot tell whether the person posting may change this account's
 *     choices. Both callers pass the signed-in person's OWN account number,
 *     read from their session; that is the whole of the gate, and it is the
 *     caller's.
 *   - A JSON number too large for PHP's integer (above about 9.2 × 10^18)
 *     stored under some unknown key comes back as a float in a different
 *     spelling. No key here holds a number at all, so this costs nothing
 *     today, but "every other key exactly as stored" is true of the VALUE as
 *     JSON reads it, not of every byte. MySQL itself reorders keys and
 *     respaces a JSON column on storage, so byte-for-byte was never on offer.
 *
 * Tests: `tools/notify-prefs-selftest.php` (both pages over real HTTP, plus
 * two real processes racing `merge()`), and section F of
 * `tools/go-live-button-selftest.php` (#612).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/667
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class NotifyPrefs
{
    /**
     * 📋 THE ONE LIST of the choices a member can set from a page.
     *
     * A key not on this list is never written by `merge()`, whatever a form
     * posts — so a crafted request cannot invent keys, and a key whose switch
     * has been taken off every page simply stops being written (its stored
     * value is KEPT, not dropped: that is the #667 fix).
     *
     * RETIRED, and deliberately absent:
     *   - `expenseUpdates` and `emailDigest`, which only the small card on
     *     /account wrote. Nothing has ever read either (#662); `expenseUpdates`
     *     also duplicated `expenseStatusUpdates` under a different name, so
     *     #667 retires it rather than keep two names for one choice. Stored
     *     values are left where they are — harmless, because nothing reads
     *     them, and deleting data a member chose is not this class's business.
     *   - The seven switches #569 took off the full page (emailDigest,
     *     eventRsvpConfirmation, expenseStatusUpdates, expenseApproverNudges,
     *     announcementsNew, prayerModeration, accountSecurity). Each comes
     *     back WITH its sender (#662), and is added here in the same change.
     *
     * ADDING A CHOICE: add the key here, and draw its switch with
     * `shownMarker()` beside it. Nothing else — there is no second list to
     * keep in step, and a key added here with no switch on any page is never
     * written, rather than written as OFF for everybody.
     */
    public const SETTABLE = [
        'eventReminders',
        'givingStatements',
        'taskReminders',
        'rotaReminders',
        'approvalRequests',
        'pushLivestream',
        'pushServiceReminders',
        'pushApprovals',
    ];

    /** The posted field naming which switches the form drew: `prefsShown[<key>]`. */
    public const SHOWN_FIELD = 'prefsShown';

    /** The posted field carrying a ticked switch: `prefs[<key>] = 1`. */
    public const VALUE_FIELD = 'prefs';

    /** `merge()` stored the change. */
    public const SAVED = 'saved';

    /** `merge()` was given no change, so it touched nothing (not even the database). */
    public const NOTHING_TO_CHANGE = 'nothing-to-change';

    /** No account has that number, so nothing was written. */
    public const NO_ACCOUNT = 'no-account';

    /** The database refused something (including a lock wait that ran out); nothing was written. */
    public const FAILED = 'failed';

    /**
     * The hidden marker a page draws beside a switch, saying "this form drew
     * that switch, so its absence from `prefs` means OFF".
     *
     * Throws for a key not on `SETTABLE`, because a switch drawn for a key no
     * save will write is a control that does nothing — the #662 shape — and
     * the place to find that out is the first time the page is drawn in a
     * test, not after members have used it.
     */
    public static function shownMarker(string $key): string
    {
        if (in_array($key, self::SETTABLE, true) === false) {
            throw new \InvalidArgumentException('NotifyPrefs: no page may draw a switch for a choice nothing saves (' . $key . ').');
        }

        return '<input type="hidden" name="' . self::SHOWN_FIELD . '[' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ']" value="1">';
    }

    /**
     * Read a posted form into the changes it asks for.
     *
     * Returns NULL when the form carried no `prefsShown` at all — the field
     * did not ARRIVE, so nothing may change (#677). Otherwise returns
     * `[key => bool]` for every key that is both marked as shown and on
     * `SETTABLE`: true where `prefs[key]` is exactly '1', false otherwise.
     * A marked key that is not on `SETTABLE` is ignored, never written.
     *
     * @param array<string,mixed> $post Normally `$_POST`.
     * @return array<string,bool>|null
     */
    public static function changesFromPost(array $post): ?array
    {
        if (array_key_exists(self::SHOWN_FIELD, $post) === false || is_array($post[self::SHOWN_FIELD]) === false) {
            return null;
        }
        $ticked  = (array_key_exists(self::VALUE_FIELD, $post) === true && is_array($post[self::VALUE_FIELD]) === true)
            ? $post[self::VALUE_FIELD]
            : [];
        $changes = [];
        foreach (array_keys($post[self::SHOWN_FIELD]) as $key) {
            // PHP turns a numeric field name into an INT key, which can never
            // be a choice's name; `in_array(..., true)` would refuse it anyway.
            if (is_string($key) === false || in_array($key, self::SETTABLE, true) === false) {
                continue;
            }
            $changes[$key] = (array_key_exists($key, $ticked) === true && $ticked[$key] === '1');
        }

        return $changes;
    }

    /**
     * Merge `$changes` into one account's stored choices, under a row lock.
     *
     * Every key NOT in `$changes` is kept exactly as stored, including keys no
     * page shows. The read is `SELECT … FOR UPDATE` inside the transaction
     * that writes, so two saves at once are made one after the other and the
     * second merges into what the first wrote (#647). Without the lock both
     * would read the old value and the second write would throw away the
     * first's change — `tools/notify-prefs-selftest.php` section F proves the
     * difference with two real processes.
     *
     * It never throws for a database fault: it rolls back and answers
     * `FAILED`, so a caller can tell the member honestly that nothing was
     * saved. It DOES throw for a programming mistake — a key not on
     * `SETTABLE`, or a value that is not true or false — because that is a
     * page asking to write something no page should.
     *
     * @param array<string,bool> $changes
     * @return array{outcome:string, replacedUnreadable:bool}
     */
    public static function merge(\mysqli $db, int $userId, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (is_string($key) === false || in_array($key, self::SETTABLE, true) === false || is_bool($value) === false) {
                throw new \InvalidArgumentException('NotifyPrefs::merge() was asked to write something that is not a choice a member can set.');
            }
        }
        if ($changes === []) {
            return ['outcome' => self::NOTHING_TO_CHANGE, 'replacedUnreadable' => false];
        }

        $began = false;
        try {
            // ⚠️ The transaction starts FIRST, and the read is inside it. A
            //    `FOR UPDATE` on an autocommitted statement takes its lock and
            //    releases it the moment the statement ends (#694 round 2).
            $began = $db->begin_transaction();
            if ($began === false) {
                return ['outcome' => self::FAILED, 'replacedUnreadable' => false];
            }

            $read = $db->prepare('SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = ? FOR UPDATE');
            if ($read === false) {
                $db->rollback();
                return ['outcome' => self::FAILED, 'replacedUnreadable' => false];
            }
            $read->bind_param('i', $userId);
            $read->execute();
            $row = $read->get_result()->fetch_assoc();
            $read->close();
            if ($row === null) {
                $db->rollback();
                return ['outcome' => self::NO_ACCOUNT, 'replacedUnreadable' => false];
            }

            [$stored, $replacedUnreadable] = self::decodeStored($row['notifyPrefs'] === null ? null : (string) $row['notifyPrefs']);
            foreach ($changes as $key => $value) {
                $stored->{$key} = $value;
            }
            $json = json_encode(
                $stored,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            );

            $write = $db->prepare('UPDATE `tblUsers` SET `notifyPrefs` = ? WHERE `userID` = ?');
            if ($write === false) {
                $db->rollback();
                return ['outcome' => self::FAILED, 'replacedUnreadable' => false];
            }
            $write->bind_param('si', $json, $userId);
            $write->execute();
            $write->close();

            if ($db->commit() === false) {
                $db->rollback();
                return ['outcome' => self::FAILED, 'replacedUnreadable' => false];
            }

            return ['outcome' => self::SAVED, 'replacedUnreadable' => $replacedUnreadable];
        } catch (\Throwable $e) {
            // Under the portal's strict reporting a refused statement THROWS
            // (a lock wait that ran out is ERROR 1205). Nothing is kept.
            if ($began === true) {
                try {
                    $db->rollback();
                } catch (\Throwable $ignored) {
                    // The connection has gone; the server rolls back for us.
                }
            }
            return ['outcome' => self::FAILED, 'replacedUnreadable' => false];
        }
    }

    /**
     * What is stored, as an OBJECT, and whether it had to be set aside.
     *
     * Decoded as an object, never as a PHP array, on purpose: an empty JSON
     * object `{}` decoded as an array comes back out as a list `[]`, so a
     * nested `{}` under some key a future page stores would be quietly turned
     * into something else. As an object it round-trips.
     *
     * @return array{0:\stdClass, 1:bool}
     */
    private static function decodeStored(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [new \stdClass(), false];
        }
        $decoded = json_decode($raw, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [new \stdClass(), true];
        }
        if ($decoded === null) {
            // The JSON literal `null`: readable, and it means nothing chosen.
            return [new \stdClass(), false];
        }
        if ($decoded instanceof \stdClass) {
            return [$decoded, false];
        }

        // A list, a number, a string or a true/false: readable JSON, but not
        // a set of named choices. See the class docblock for why replacing
        // it changes no reader's answer except the keys just set.
        return [new \stdClass(), true];
    }
}
