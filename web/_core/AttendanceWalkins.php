<?php
// Path: _core/AttendanceWalkins.php
/**
 * -----------------------------------------------------------------------------
 * Which walk-in is which, on the attendance grid 🚶📋 (#655)
 * -----------------------------------------------------------------------------
 * THE FAULT THIS REPLACES
 * -----------------------------------------------------------------------
 * `tblEventAttendance.walkinName` is compared by the database the way its
 * column is declared, `utf8mb4_general_ci`: capitals do not matter, trailing
 * spaces do not matter, and some accented letters count as their plain
 * letter. The grid used to compare names in PHP, byte for byte, so "Dave" on
 * one day and "dave" on another were ONE row in the walk-in list (the list
 * asks the database, via `SELECT DISTINCT`) but TWO different keys in the
 * attendance map. The second day then drew as "not attended" although a row
 * existed, and its cell posted `toggle=1` (add) instead of `toggle=0`
 * (remove): the add was swallowed by `uq_attendance_person`, so the row could
 * be neither seen nor removed.
 *
 * THE RULE, IN ONE PLACE
 * -----------------------------------------------------------------------
 * The grid no longer decides when two names are the same. The DATABASE does,
 * and PHP is only told the answer: `groupsSql()` numbers each distinct name
 * (distinct AS THE DATABASE COMPARES IT) with the lowest attendance row that
 * carries it, and `cellKey()` is the ONE place a grid key is built from that
 * number. The walk-in list and the attendance map both read the same query, so
 * they cannot drift apart again, and nothing here needs to know what
 * `utf8mb4_general_ci` considers equal.
 *
 * WHY NOT FOLD THE NAME IN PHP. The first design was `mb_strtolower()` plus
 * `rtrim()`. Measured on MySQL 8.0.36, that is NOT what general_ci does:
 *   - `e` = `é`, `a` = `Ä`, `ß` = `s`, `σ` = `ς`, `i` = `İ` (equal in the
 *     database), while `ß` = `ss`, `o` = `ø`, `æ` = `ae` are NOT equal;
 *   - trailing spaces are ignored, a trailing tab or non-breaking space is
 *     not, and spaces INSIDE a name are not.
 * A hand-written fold would have to copy that table and would be wrong the
 * first time MySQL or MariaDB changed it. Asking the database has no such gap.
 *
 * WHAT THIS CANNOT DO
 * -----------------------------------------------------------------------
 *   - It groups the way the COLUMN compares. If the column's collation is
 *     ever changed, grouping follows with no edit here, which is the point.
 *   - A name that differs only by something the database treats as a real
 *     difference (a trailing tab, a doubled inner space, `ß` against `ss`)
 *     is, correctly, a different walk-in: it gets its own row.
 *   - Display name: where several spellings exist the list shows whichever
 *     one the database hands back for the group (it is not specified which).
 *     The removal statement compares by the same rule, so any of them works.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/655
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class AttendanceWalkins
{
    /**
     * 🔎 The ONE query that numbers an event's walk-ins and lists every
     * attendance row with its walk-in's number.
     *
     * One result row per attendance row. `groupRef` is NULL for a member's row
     * and, for a walk-in's row, the lowest `attendanceID` among every row of
     * that event whose name the DATABASE calls the same. The join below and
     * the `GROUP BY` inside it use the same column and so the same collation;
     * that is what makes the grid agree with the database.
     *
     * Bind one integer: the event, twice (`ii`).
     */
    public static function groupsSql(): string
    {
        return 'SELECT a.`attendanceID`, a.`userID`, a.`walkinName`, a.`dayDate`, g.`groupRef` '
            . 'FROM `tblEventAttendance` a '
            . 'LEFT JOIN ('
            . 'SELECT `walkinName`, MIN(`attendanceID`) AS `groupRef` FROM `tblEventAttendance` '
            . 'WHERE `eventID` = ? AND `userID` IS NULL AND `walkinName` IS NOT NULL GROUP BY `walkinName`'
            . ') g ON g.`walkinName` = a.`walkinName` AND a.`userID` IS NULL '
            . 'WHERE a.`eventID` = ?';
    }

    /**
     * 🗝️ The ONE place a grid cell's key is built. The walk-in list and the
     * attendance map both call this, so a key can only ever be compared with
     * one made the same way.
     *
     * A member is keyed by account number; a walk-in by the group number from
     * `groupsSql()`, never by the typed name.
     */
    public static function cellKey(?int $userId, ?int $groupRef, string $day): string
    {
        if ($userId !== null) {
            return 'u:' . $userId . ':' . $day;
        }

        return 'w:' . (int) $groupRef . ':' . $day;
    }

    /**
     * 📋 Read an event's attendance once. Returns the walk-ins to draw (one
     * per group) and the map of ticked cells, both from the same rows.
     *
     * @return array{walkins: list<array{groupRef:int, name:string}>, attended: array<string,bool>}
     */
    public static function read(\mysqli $mysqli, int $eventId): array
    {
        $walkins  = [];
        $attended = [];
        $stmt     = $mysqli->prepare(self::groupsSql());
        if ($stmt === false) {
            return ['walkins' => [], 'attended' => []];
        }
        $stmt->bind_param('ii', $eventId, $eventId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $userId   = $r['userID'] !== null ? (int) $r['userID'] : null;
            $groupRef = $r['groupRef'] !== null ? (int) $r['groupRef'] : null;
            if ($userId === null && $groupRef === null) {
                continue; // a row with neither a person nor a name: nothing to draw
            }
            $attended[self::cellKey($userId, $groupRef, (string) $r['dayDate'])] = true;
            if ($userId === null && isset($walkins[$groupRef]) === false) {
                $walkins[$groupRef] = ['groupRef' => $groupRef, 'name' => (string) $r['walkinName']];
            }
        }
        $stmt->close();

        // Same order the list always had: by name, the way the database sorts.
        usort($walkins, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['walkins' => $walkins, 'attended' => $attended];
    }
}
