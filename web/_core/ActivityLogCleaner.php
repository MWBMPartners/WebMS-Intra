<?php
// Path: _core/ActivityLogCleaner.php
/**
 * -----------------------------------------------------------------------------
 * Activity log: cleaning the records written before #622 🧹
 * -----------------------------------------------------------------------------
 * WHAT IT IS FOR
 *   Until October 2026 every activity record stored the browser session's
 *   identifier (which IS the sign-in cookie) and a copy of the whole session.
 *   Logger no longer writes either (see Logger::sessionFingerprint() and
 *   Logger::sessionFacts()). This class deals with the records ALREADY stored:
 *
 *     - the identifier becomes the same fingerprint Logger writes today, so
 *       one session's old and new records still match;
 *     - the copy of the session is blanked;
 *     - BEFORE a copy is blanked, it is read for an authenticator key. A set-up
 *       page left open kept the new key in the session, so the copy holds it
 *       in readable text. A key cannot be made safe by cleaning the log:
 *       backups taken earlier still hold it. So the account gets ONE record
 *       of its own (KEY_LINE_TYPE) that names the account and holds no key,
 *       and the health page counts those accounts until each has a new key.
 *
 * WHY THIS IS NOT IN THE DATABASE UPGRADE FILE (migration 220)
 *   Measured on 13 October 2026, on 500,000 made-up records (a table of about
 *   1 GB, MySQL 8.0.36): as ONE statement the clean-up took 176 seconds. A web
 *   request that long is cut off on most shared hosting; the database then
 *   undoes all of it and the next try starts from nothing, so on a large log
 *   the upgrade might never finish. In pieces of a few thousand records the
 *   same work took 60 to 90 seconds (this class, measured twice), no piece
 *   took more than two seconds, and whatever is finished is kept.
 *   TRIED AND REJECTED: dropping the column and adding it back (instant, but
 *   the old bytes stay in the data file, and a second run could not tell new
 *   records from old); blanking the identifiers (the monthly sign-ins report
 *   joins on that column); a stored procedure (shared hosts often refuse them).
 *
 * HOW IT WORKS
 *   run() goes through the log in pieces of PIECE log numbers, oldest first,
 *   for at most a time budget, and remembers how far it has got in one
 *   setting (THROUGH_KEY). The Upgrade page calls it after the upgrade files
 *   have run, and offers a button to call it again while records are left.
 *
 *   ONE PIECE IS ONE TRANSACTION, in this order: (1) take the "how far" row
 *   for writing; (2) read the piece's old copies for authenticator keys and
 *   write the one record for each account that has none yet; (3) fingerprint
 *   and blank the piece; (4) move "how far" on. A piece that is cut off is
 *   undone whole by the database. So no copy is ever blanked before its key
 *   was recorded, and "how far" never says more than what was kept.
 *
 *   ONLY ONE RUNS AT A TIME. A named database lock is taken first, without
 *   waiting: a second press beside a running one is told "busy" at once. The
 *   lock goes when the connection goes, so a request that dies cannot leave
 *   it held. The "how far" row is also taken for writing inside each piece,
 *   so even if the named lock failed, two runs could not do one piece twice.
 *
 *   A RECORD ALREADY IN THE NEW FORM IS LEFT EXACTLY AS IT IS: the statement
 *   changes only an identifier that is not yet a fingerprint and a copy that
 *   does not start as Logger's list of facts does. So records written while
 *   it works, and a second run from the start, change nothing.
 *
 *   THE PORTAL IS NOT CLOSED FOR IT. This is not a database upgrade file and
 *   has nothing to do with the upgrade gate (Portal\Core\Maintenance).
 *
 * A BACKUP FROM BEFORE THE UPGRADE, PUT BACK AFTER IT, brings back the old
 * records and has no "how far" row, because settings travel with the backup.
 * The next press therefore starts again from the first record.
 *
 * WHAT IT CANNOT DO
 *   - It does not clean backups, snapshots or copies of the database made
 *     earlier. Those hold what the log held on the day they were made.
 *   - It does not wipe the old bytes off the disk. The database reuses that
 *     space in its own time.
 *   - It finds a key only where the old set-up page kept it: under the name
 *     "totp_setup_secret", as 16 to 128 letters A to Z and digits 2 to 7.
 *   - It cannot know whether the key in the log is the one the account uses
 *     today (the stored key is encrypted). Every account whose old records
 *     held a readable key gets a record. The health page then counts an
 *     account only while two-step sign-in is on with the SAME stored key as
 *     when the record was written.
 *   - It cannot see what a set-up page that was left open across the upgrade
 *     goes on to do. This used to say such a page "stores the key from before
 *     as if it were new", and that is NO LONGER TRUE of this release: since
 *     #623 the set-up page keeps its key in the session encrypted, a key left
 *     there by the older code does not decrypt, and the page throws it away
 *     and makes a new one, telling the person the page had been open too
 *     long. The sentence is kept, reworded, because it was acted on: nothing
 *     HERE watches that page, so if it ever changes again this class will not
 *     notice.
 *   - "How far" and the list of key records are settings, so anything that
 *     changes them changes what the portal believes. Edited by hand, "how
 *     far" can be made to say the cleaning is finished when it is not; the
 *     general Settings page does not refuse either of them. The Backup
 *     page's restore of tblSettings ON ITS OWN, from a backup taken before
 *     the cleaning, reaches the same place from a button: the independent
 *     check of this package did it on 26 October 2026 and the health page's
 *     count of accounts with a once-readable key went from 1 to 0 for good,
 *     because the records are already clean and no key is ever found again.
 *     Nothing is lost by that (the key records themselves stay in Admin,
 *     Activity Log) but nobody is prompted any more.
 *   - The key records are activity records, so the log's own retention
 *     removes them when they are old enough (a year unless set otherwise).
 *   - While the cleaning is half done, the monthly sign-ins chart (Admin,
 *     Reports) can count one sign-in twice. It links a password sign-in to
 *     its code page by the session, and for a moment the first record of a
 *     session carries the fingerprint while the second still carries the
 *     identifier, so the link is lost. It comes right as soon as the log is
 *     fully cleaned, and that page already says it gives the closest honest
 *     figure its records allow.
 *
 * Test: tools/activity-log-selftest.php.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/622
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;
use Throwable;

final class ActivityLogCleaner
{
    /** The setting that says how far the cleaning has got: a log number, or DONE. Absent means "not started". */
    public const THROUGH_KEY = 'activityLog.cleanedThrough';

    /** What THROUGH_KEY holds once every record has been gone through. The install script writes it into a database with an empty log. */
    public const DONE = 'all';

    /** The setting that lists the log numbers of the key records written (KEY_LINE_TYPE), with commas between them. */
    public const KEY_LINES_KEY = 'activityLog.keyLines';

    /** The activity type of the one record an account gets when its old records held a readable authenticator key. */
    public const KEY_LINE_TYPE = 'AuthenticatorKeyWasReadable';

    /** How many log numbers one piece covers. Measured: about half a second a piece, two seconds at the slowest. */
    public const PIECE = 5000;

    /** How long one call of run() goes on starting new pieces, in seconds. A piece already begun is finished, so a call can last this plus one piece. */
    public const BUDGET_SECONDS = 20.0;

    /** The copy of a session written by Logger::sessionFacts() starts with exactly this (see Logger::SESSION_FACTS_FORMAT). */
    private const NEW_COPY_LIKE = '{"format":"facts-1",%';

    /** The named database lock, one per database: two installations on one database server must not hold each other up. */
    private const LOCK_SQL = "CONCAT('portal-log-cleaner:', SHA1(DATABASE()))";

    /**
     * 🧹 Go through old records for at most `$budgetSeconds`. Never throws.
     *
     * @return array{ran: bool, busy: bool, finished: bool, pieces: int, cleaned: int, left: int|null, keyLines: int,
     *               keysWithoutAccount: int, slowestPiece: float, error: string}
     *   ran                 false when nothing was attempted (busy, or the lock could not be asked for)
     *   busy                another run holds the lock
     *   finished            every record has been gone through
     *   cleaned             records this call changed
     *   left                records still to go through (null when that could not be counted)
     *   keyLines            accounts given their record by this call
     *   keysWithoutAccount  old records that held a key but did not say whose (no line could be written)
     */
    public static function run(mysqli $db, float $budgetSeconds = self::BUDGET_SECONDS): array
    {
        $out = ['ran' => false, 'busy' => false, 'finished' => false, 'pieces' => 0, 'cleaned' => 0, 'left' => null,
            'keyLines' => 0, 'keysWithoutAccount' => 0, 'linked' => 0, 'cleared' => 0, 'slowestPiece' => 0.0, 'error' => ''];
        $locked = false;
        try {
            $got    = $db->query('SELECT GET_LOCK(' . self::LOCK_SQL . ', 0) AS got')->fetch_assoc();
            $locked = (int) ($got['got'] ?? 0) === 1;
            if ($locked === false) {
                $out['busy'] = true;
                $out['left'] = self::left($db);

                return $out;
            }
            $out['ran'] = true;
            $started    = microtime(true);
            do {
                $pieceStarted = microtime(true);
                $piece        = self::onePiece($db);
                $out['slowestPiece'] = max($out['slowestPiece'], microtime(true) - $pieceStarted);
                $out['pieces']++;
                $out['cleaned']            += $piece['cleaned'];
                $out['keyLines']           += $piece['keyLines'];
                $out['keysWithoutAccount'] += $piece['keysWithoutAccount'];
                $out['linked']             += $piece['linked'];
                $out['cleared']            += $piece['cleared'];
                $out['finished']            = $piece['finished'];
            } while ($out['finished'] === false && (microtime(true) - $started) < $budgetSeconds);
        } catch (Throwable $e) {
            // A piece that failed has been undone (see onePiece()). What was
            // finished before it is kept, and the next call carries on. The
            // reason goes to the server's own log, which is where the page
            // tells the administrator to look; it is not shown on the page.
            $out['error'] = get_class($e) . ': ' . $e->getMessage();
            error_log('ActivityLogCleaner stopped: ' . preg_replace('/\s+/', ' ', $out['error']));
        } finally {
            if ($locked === true) {
                try {
                    $db->query('DO RELEASE_LOCK(' . self::LOCK_SQL . ')');
                } catch (Throwable $ignored) {
                    unset($ignored); // the lock goes with the connection anyway
                }
            }
        }
        $out['left'] = $out['finished'] === true ? 0 : self::left($db);

        return $out;
    }

    /**
     * How many records are still to be gone through: 0 when the cleaning is
     * finished, null when it could not be counted. Reads only.
     *
     * It counts every record after "how far", so a record written since by
     * today's Logger is counted too until the cleaning passes it.
     *
     * HOW IT COUNTS, AND WHY. "SUM(logID > ?)" over the whole table, which
     * the database answers from the smallest of the table's keys without
     * opening a record: 0.3 seconds for 500,000 records, measured. The
     * plainer "COUNT(*) ... WHERE logID > ?" was written first. The database
     * sometimes answered that by reading the records themselves, a gigabyte
     * of them: four seconds, added to every press and to every look at the
     * health page.
     */
    public static function left(mysqli $db): ?int
    {
        try {
            $through = self::setting($db, self::THROUGH_KEY);
            if ($through === self::DONE) {
                return 0;
            }
            $stmt = $db->prepare('SELECT COALESCE(SUM(logID > ?), 0) AS n FROM tblActivityLogs');
            $from = self::isNumber($through) === true ? (int) $through : 0;
            $stmt->bind_param('i', $from);
            $stmt->execute();
            $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
            $stmt->close();

            return $n;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 🔑 How many accounts still depend on an authenticator key that the log
     * once held in readable text: null when that could not be worked out.
     *
     * An account is counted while two-step sign-in is ON for it and its
     * stored key is the one it had when its record was written. The record
     * carries a short fingerprint of the STORED, ENCRYPTED key for exactly
     * this comparison (not of the key itself, which this class never reads).
     * Switching two-step sign-in off, or setting it up again, changes or
     * removes the stored key, and the account is no longer counted.
     *
     * Reads only, and only the listed records by their own numbers, so it is
     * safe on the health page however large the log is.
     */
    public static function accountsWithReadableKey(mysqli $db): ?int
    {
        try {
            $lines = self::numberList((string) self::setting($db, self::KEY_LINES_KEY));
            $found = [];
            foreach (array_chunk($lines, 500) as $some) {
                $stmt = $db->prepare(
                    'SELECT DISTINCT n.userID FROM tblActivityLogs n '
                    . 'INNER JOIN tblUsers u ON u.userID = n.userID '
                    . 'WHERE n.logID IN (' . implode(', ', array_fill(0, count($some), '?')) . ') AND n.activityType = ? '
                    . 'AND u.totpEnabled = 1 AND u.totpSecret IS NOT NULL '
                    . "AND n.activityDescription LIKE CONCAT('%[', LEFT(SHA2(u.totpSecret, 256), 16), ']%')"
                );
                $values = array_merge($some, [self::KEY_LINE_TYPE]);
                $stmt->bind_param(str_repeat('i', count($some)) . 's', ...$values);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                    $found[(int) $row['userID']] = true;
                }
                $stmt->close();
            }

            return count($found);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * What a call of run() did, as one plain sentence or two: for the Upgrade
     * page and for the activity record of the call.
     *
     * @param array<string, mixed> $result What run() answered.
     */
    public static function inWords(array $result): string
    {
        if (($result['busy'] ?? false) === true) {
            return 'Another cleaning of the old activity records is running, so this one did nothing. Wait a minute and look again.';
        }
        $cleaned = (int) ($result['cleaned'] ?? 0);
        $words   = $cleaned === 1 ? '1 old activity record was cleaned just now.' : number_format($cleaned) . ' old activity records were cleaned just now.';
        if (($result['finished'] ?? false) === true) {
            $words .= ' None are left.';
        } elseif (($result['left'] ?? null) === null) {
            $words .= ' How many are left could not be counted.';
        } else {
            $words .= ' ' . number_format((int) $result['left']) . ((int) $result['left'] === 1 ? ' record is' : ' records are') . ' still to go through.';
        }
        $lines = (int) ($result['keyLines'] ?? 0);
        if ($lines > 0) {
            $words .= ' ' . ($lines === 1 ? '1 account was' : $lines . ' accounts were')
                . ' found whose old records held a readable authenticator key; each now has a record saying so (Admin, Activity Log, type '
                . self::KEY_LINE_TYPE . ').';
        }
        // #626: said separately from "cleaned", because these are two
        // different things happening to a record and an administrator reading
        // this needs to be able to tell them apart.
        $linked = (int) ($result['linked'] ?? 0);
        if ($linked > 0) {
            $words .= ' ' . ($linked === 1 ? '1 record was' : number_format($linked) . ' records were')
                . ' given the account they belong to, which had been kept only in the record\'s session details.'
                . ' Until now an erasure could not reach those records, and they were missing from the person\'s own'
                . ' data download and from the "filter by person" list.';
        }
        $cleared = (int) ($result['cleared'] ?? 0);
        if ($cleared > 0) {
            $words .= ' ' . ($cleared === 1 ? '1 record belonged' : number_format($cleared) . ' records belonged')
                . ' to an account that has been erased, or is no longer there, so the address, the browser name, the'
                . ' headers and the session details were removed from '
                . ($cleared === 1 ? 'it' : 'them') . ' instead. That finishes what those erasures could not reach.';
        }
        $nameless = (int) ($result['keysWithoutAccount'] ?? 0);
        if ($nameless > 0) {
            $words .= ' ' . ($nameless === 1 ? '1 old record' : $nameless . ' old records')
                . ' held an authenticator key without saying whose, so no account could be recorded for '
                . ($nameless === 1 ? 'it' : 'them') . '.';
        }
        if ((string) ($result['error'] ?? '') !== '') {
            $words .= ' The cleaning then stopped: the database refused a step. What was finished is kept; press the button again. '
                . 'If it stops again, the reason is in the server\'s error log.';
        }

        return $words;
    }

    /**
     * The words of an account's record. No key is in it, and none is passed
     * to this method.
     *
     * @param string $storedKeyMark A fingerprint of the account's stored, ENCRYPTED key, or '' when two-step sign-in is off.
     */
    public static function keyLineWords(string $storedKeyMark): string
    {
        return 'Before this version of the portal, the activity log kept a readable copy of an authenticator key made for this account '
            . 'while two-step sign-in was being set up. The copy has been removed from the log, but backups made earlier still hold it. '
            . 'If two-step sign-in is on for this account, ask the person to switch it off on their Account page and set it up again: '
            . 'that makes a new key. '
            . ($storedKeyMark === ''
                ? 'Two-step sign-in was off for this account when this was written.'
                : 'The health page counts this account until that is done [' . $storedKeyMark . '].');
    }

    /* ---------------------------------------------------------------------- */
    /* One piece                                                              */
    /* ---------------------------------------------------------------------- */

    /**
     * One piece, as one transaction. Throws when the database refuses a step,
     * after undoing the piece.
     *
     * @return array{finished: bool, cleaned: int, keyLines: int, keysWithoutAccount: int}
     */
    private static function onePiece(mysqli $db): array
    {
        $out = ['finished' => false, 'cleaned' => 0, 'keyLines' => 0, 'keysWithoutAccount' => 0, 'linked' => 0, 'cleared' => 0];

        // The "how far" row has to exist before it can be taken for writing.
        // Made outside the transaction, and never changed if it is there.
        // (uq_setting_key_scope, migration 187, is what stops a second row.)
        $key  = self::THROUGH_KEY;
        $stmt = $db->prepare(
            "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, '0', '', 0) "
            . 'ON DUPLICATE KEY UPDATE settingKey = settingKey'
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();

        $db->begin_transaction();
        try {
            // 1. Take "how far" for writing. A second run waits here until
            //    this piece is kept or undone, and then reads what is true.
            $stmt = $db->prepare('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL FOR UPDATE');
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $through = (string) ($stmt->get_result()->fetch_assoc()['settingValue'] ?? '0');
            $stmt->close();
            if ($through === self::DONE) {
                $db->commit();
                $out['finished'] = true;

                return $out;
            }
            $through = self::isNumber($through) === true ? (int) $through : 0;

            // The piece starts at the next record there IS, so a gap in the
            // numbers (old records removed by retention) costs nothing.
            $stmt = $db->prepare('SELECT MIN(logID) AS first FROM tblActivityLogs WHERE logID > ?');
            $stmt->bind_param('i', $through);
            $stmt->execute();
            $first = $stmt->get_result()->fetch_assoc()['first'] ?? null;
            $stmt->close();
            if ($first === null) {
                // Nothing after "how far": every record has been gone through.
                self::writeSetting($db, self::THROUGH_KEY, self::DONE);
                $db->commit();
                $out['finished'] = true;

                return $out;
            }
            $from = (int) $first;
            $to   = $from + self::PIECE - 1;

            // 2. Keys first: see the top of this file for why the order matters.
            $keys = self::recordKeys($db, $from, $to);
            $out['keyLines']           = $keys['lines'];
            $out['keysWithoutAccount'] = $keys['nameless'];

            // 2b. 🧍 PUT THE ACCOUNT IN ITS OWN COLUMN, OR CLEAR THE ROW
            //     (#626, 27 October 2026). MUST run before step 3, which
            //     blanks every old session copy — and the old copy is the only
            //     place a pre-#622 record says whose session it was. Done the
            //     other way round, the chance is gone for ever.
            $links = self::linkAccounts($db, $from, $to);
            $out['linked']  = $links['linked'];
            $out['cleared'] = $links['cleared'];

            // 3. Fingerprint and blank. Each CASE leaves a value already in
            //    the new form exactly as it is; the WHERE keeps such a record
            //    from being written at all. The fingerprint is the one
            //    Logger::fingerprintOf() makes: the self-test holds the two
            //    to the same answer.
            $mark = Logger::FINGERPRINT_MARK;
            $like = $mark . '%';
            $new  = self::NEW_COPY_LIKE;
            $stmt = $db->prepare(
                'UPDATE tblActivityLogs SET '
                . "sessionID = CASE WHEN sessionID IS NULL OR sessionID = '' OR sessionID LIKE ? THEN sessionID ELSE CONCAT(?, SHA2(sessionID, 256)) END, "
                . 'sessionDataSnapshot = CASE WHEN sessionDataSnapshot LIKE ? THEN sessionDataSnapshot ELSE NULL END '
                . 'WHERE logID BETWEEN ? AND ? '
                . "AND ((sessionID IS NOT NULL AND sessionID <> '' AND sessionID NOT LIKE ?) "
                . 'OR (sessionDataSnapshot IS NOT NULL AND sessionDataSnapshot NOT LIKE ?))'
            );
            $stmt->bind_param('sssiiss', $like, $mark, $new, $from, $to, $like, $new);
            $stmt->execute();
            $out['cleaned'] = max(0, (int) $stmt->affected_rows);
            $stmt->close();

            // 4. "How far" moves on, in the same transaction as the piece.
            self::writeSetting($db, self::THROUGH_KEY, (string) $to);
            $db->commit();
        } catch (Throwable $e) {
            try {
                $db->rollback();
            } catch (Throwable $ignored) {
                unset($ignored); // a lost connection has already undone it
            }
            throw $e;
        }

        return $out;
    }

    /**
     * Step 2 of a piece: find the accounts whose old copies in this piece
     * held a readable authenticator key, and write the one record for each
     * that has none. Runs inside the piece's transaction.
     *
     * @return array{lines: int, nameless: int}
     */
    private static function recordKeys(mysqli $db, int $from, int $to): array
    {
        // Only old copies that name the key at all: a handful of rows, read
        // whole and looked at here, because a database cannot be trusted to
        // read text that may not be well-formed.
        $new  = self::NEW_COPY_LIKE;
        $stmt = $db->prepare(
            'SELECT siteID, userID, sessionDataSnapshot FROM tblActivityLogs WHERE logID BETWEEN ? AND ? '
            . "AND sessionDataSnapshot LIKE '%totp\\_setup\\_secret%' AND sessionDataSnapshot NOT LIKE ?"
        );
        $stmt->bind_param('iis', $from, $to, $new);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $accounts = []; // account number => the organisation of the first record seen
        $nameless = 0;
        foreach ($rows as $row) {
            $account = self::accountOfReadableKey((string) $row['sessionDataSnapshot'], $row['userID'] === null ? null : (int) $row['userID']);
            if ($account === null) {
                continue; // no readable key in this copy
            }
            if ($account === 0) {
                $nameless++;
                continue;
            }
            $accounts[$account] ??= $row['siteID'] === null ? null : (int) $row['siteID'];
        }

        $lines   = 0;
        $written = [];
        $type    = self::KEY_LINE_TYPE;
        foreach ($accounts as $account => $siteId) {
            // The account must still exist, and must not have its record yet.
            $stmt = $db->prepare('SELECT totpEnabled, totpSecret FROM tblUsers WHERE userID = ?');
            $stmt->bind_param('i', $account);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($user === null) {
                $nameless++;
                continue;
            }
            // Asked by account number, which the table keeps an index on.
            // (Asked as "no such record exists" over the whole log, the
            // database read the whole log for every piece: measured.)
            $stmt = $db->prepare('SELECT logID FROM tblActivityLogs WHERE userID = ? AND activityType = ? LIMIT 1');
            $stmt->bind_param('is', $account, $type);
            $stmt->execute();
            $already = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($already !== null) {
                continue;
            }
            $storedKeyMark = ((int) $user['totpEnabled'] === 1 && is_string($user['totpSecret']) === true && $user['totpSecret'] !== '')
                ? substr(hash('sha256', $user['totpSecret']), 0, 16)
                : '';
            $words = self::keyLineWords($storedKeyMark);
            $stmt  = $db->prepare('INSERT INTO tblActivityLogs (siteID, userID, activityType, activityDescription) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('iiss', $siteId, $account, $type, $words);
            $stmt->execute();
            $written[] = (int) $stmt->insert_id;
            $stmt->close();
            $lines++;
        }
        if ($written !== []) {
            $listed = self::numberList((string) self::setting($db, self::KEY_LINES_KEY));
            self::writeSetting($db, self::KEY_LINES_KEY, implode(',', array_merge($listed, $written)));
        }

        return ['lines' => $lines, 'nameless' => $nameless];
    }

    /**
     * Whose readable authenticator key an old copy of a session holds:
     * null when it holds none, 0 when it holds one and does not say whose,
     * otherwise the account's number.
     *
     * "Readable" is the form the old set-up page kept: 16 to 128 characters,
     * every one a letter A to Z or a digit 2 to 7. The set-up page since #613
     * keeps the key encrypted there, which is longer and uses other
     * characters, and is not counted.
     *
     * The account is the one the SESSION was signed in as (only a signed-in
     * person reaches the set-up page). The record's own account is used only
     * when the copy does not say.
     */
    private static function accountOfReadableKey(string $copy, ?int $recordAccount): ?int
    {
        $session = json_decode($copy, true);
        if (is_array($session) === true) {
            $secret = $session['totp_setup_secret'] ?? null;
            $whose  = $session['user_id'] ?? null;
        } else {
            // Not well-formed (cut short, say): look at the text itself.
            $secret = preg_match('/"totp_setup_secret":"([^"\\\\]*)"/', $copy, $m) === 1 ? $m[1] : null;
            $whose  = preg_match('/"user_id":"?([0-9]{1,10})"?[,}]/', $copy, $m) === 1 ? $m[1] : null;
        }
        if (is_string($secret) === false || preg_match('/^[A-Z2-7]{16,128}$/', $secret) !== 1) {
            return null;
        }
        if ((is_int($whose) === true || (is_string($whose) === true && self::isNumber($whose) === true)) && (int) $whose > 0) {
            return (int) $whose;
        }

        return ($recordAccount !== null && $recordAccount > 0) ? $recordAccount : 0;
    }

    /**
     * 🧍 Step 2b of a piece: give each record of a signed-in person the account
     *     number in its OWN column, or — where that account has been erased or
     *     is gone — clear what the record still says about them.
     *
     * WHY THIS EXISTS (#626). An erasure empties a column by matching on one:
     * `UPDATE ... WHERE userID = ?`. Until 27 October 2026 `Logger::activity()`
     * wrote NULL into `userID` whenever the caller named nobody — roughly half its
     * callers — while the session facts beside it said who the person was.
     * An erasure never reached those records, so the address, the browser
     * name, the headers, the fingerprint and the facts all survived it. The
     * logger now fills the column; this brings the records already written
     * into line, so that one rule covers every record of somebody signed in
     * as themselves. (Not "every record there is": see the kiosk note below.)
     *
     * TWO ANSWERS, AND THE SECOND IS THE ONE THAT NEEDS CARE:
     *
     *   - the account is a real one that has NOT been erased -> put its number
     *     in the column. The record is now reachable by an erasure, by the
     *     person's own data download, and by the "filter by person" list.
     *   - the account has been ERASED, or is not there at all -> clear the
     *     address, the browser name, the headers, the fingerprint and the
     *     facts, and leave the column empty.
     *
     * **Filling the column for an erased person would UNDO their erasure**, by
     * tying records back to them that an erasure had been asked to let go of.
     * It is the one way this method could do real harm, so the test for it is
     * the one definition the whole portal uses, GdprEraser::emptiedAccountSql().
     * Clearing instead finishes the job their erasure could not reach — which
     * is the honest outcome, and the reason this is not simply a backfill.
     *
     * WHAT IT NEVER DOES. It never changes a record that already names
     * somebody: a filled column is somebody's considered answer, and about
     * half the callers pass one. It never reads an account out of
     * `waitingForCodeUserID` (somebody part way through their second step,
     * not signed in yet) or `kioskUserID` — which is **a real member's
     * account**, the one a shared kiosk has been handed to by a PIN, not
     * "the machine's own account" as an earlier version of this said. It is
     * left out because a kiosk session outlives the person at it until the
     * idle timer clears it, so naming that account could attribute somebody
     * else's action to them and, on an erasure, clear their details for it.
     * A kiosk record therefore still names nobody and an erasure still
     * cannot reach it: the same gap in miniature, left open on purpose
     * because what a shared device's record means is the owner's decision.
     * Logger::activity() leaves it out for the same reason, and the two must
     * stay in step.
     *
     * WHY THE ROWS ARE READ INTO PHP rather than matched in SQL: the same
     * reason recordKeys() gives. A session copy may not be well-formed, and a
     * database cannot be trusted to read text that may not be. A piece is
     * bounded (PIECE rows), so the cost is bounded with it.
     *
     * Runs inside the piece's transaction, so it is kept or undone with it.
     *
     * @return array{linked: int, cleared: int}
     */
    private static function linkAccounts(mysqli $db, int $from, int $to): array
    {
        $out = ['linked' => 0, 'cleared' => 0];

        // Only records that name nobody and still say something about a
        // session. Both shapes of copy are read: the new named facts, and the
        // old whole-session copy that step 3 is about to blank.
        $stmt = $db->prepare(
            'SELECT logID, sessionDataSnapshot FROM tblActivityLogs '
            . 'WHERE logID BETWEEN ? AND ? AND userID IS NULL '
            . "AND sessionDataSnapshot IS NOT NULL AND sessionDataSnapshot <> ''"
        );
        $stmt->bind_param('ii', $from, $to);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if ($rows === []) {
            return $out;
        }

        // logID => the account its copy names.
        $whose = [];
        foreach ($rows as $row) {
            $account = self::accountInCopy((string) $row['sessionDataSnapshot']);
            if ($account !== null) {
                $whose[(int) $row['logID']] = $account;
            }
        }
        if ($whose === []) {
            return $out;
        }

        // Which of those accounts may be named: it exists AND an erasure has
        // not emptied it. Asked once for the whole piece.
        $numbers  = array_values(array_unique($whose));
        $holes    = implode(', ', array_fill(0, count($numbers), '?'));
        $nameable = [];
        $stmt     = $db->prepare(
            'SELECT userID FROM tblUsers u WHERE userID IN (' . $holes . ') '
            . 'AND NOT (' . GdprEraser::emptiedAccountSql('u') . ')'
        );
        $stmt->bind_param(str_repeat('i', count($numbers)), ...$numbers);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $nameable[(int) $row['userID']] = true;
        }
        $stmt->close();

        // The records to fill, grouped by account, and the ones to clear.
        $fill  = []; // account => logIDs
        $clear = [];
        foreach ($whose as $logId => $account) {
            if (isset($nameable[$account]) === true) {
                $fill[$account][] = $logId;
                continue;
            }
            $clear[] = $logId;
        }

        foreach ($fill as $account => $logIds) {
            $holes = implode(', ', array_fill(0, count($logIds), '?'));
            $stmt  = $db->prepare('UPDATE tblActivityLogs SET userID = ? WHERE userID IS NULL AND logID IN (' . $holes . ')');
            $values = array_merge([(int) $account], $logIds);
            $stmt->bind_param(str_repeat('i', count($values)), ...$values);
            $stmt->execute();
            $out['linked'] += max(0, (int) $stmt->affected_rows);
            $stmt->close();
        }

        if ($clear !== []) {
            $holes = implode(', ', array_fill(0, count($clear), '?'));
            $stmt  = $db->prepare(
                'UPDATE tblActivityLogs SET sessionID = NULL, visitorIP = NULL, userAgent = NULL, '
                . 'requestHeaders = NULL, sessionDataSnapshot = NULL '
                . 'WHERE userID IS NULL AND logID IN (' . $holes . ')'
            );
            $stmt->bind_param(str_repeat('i', count($clear)), ...$clear);
            $stmt->execute();
            $out['cleared'] += max(0, (int) $stmt->affected_rows);
            $stmt->close();
        }

        return $out;
    }

    /**
     * The account a session copy says was signed in, in either shape, or null.
     *
     * The new named facts call it `userID` (Logger::sessionFacts()); the old
     * whole-session copy called it `user_id`, which is the session key itself.
     * **Only those two.** `waitingForCodeUserID` / `2fa_user_id` is somebody
     * part way through their second step, and `kioskUserID` / `kiosk_user_id`
     * is the machine's own account: neither is "this record is that person's".
     *
     * A copy that is not well-formed is read with a pattern instead, as
     * accountOfReadableKey() does and for the same reason.
     */
    private static function accountInCopy(string $copy): ?int
    {
        $session = json_decode($copy, true);
        if (is_array($session) === true) {
            $whose = $session['userID'] ?? $session['user_id'] ?? null;
        } else {
            $whose = preg_match('/"user_?ID"\s*:\s*"?([0-9]{1,10})"?[,}]/i', $copy, $m) === 1 ? $m[1] : null;
        }
        if ((is_int($whose) === true || (is_string($whose) === true && self::isNumber($whose) === true)) && (int) $whose > 0) {
            return (int) $whose;
        }

        return null;
    }

    /* ---------------------------------------------------------------------- */
    /* Small helpers                                                          */
    /* ---------------------------------------------------------------------- */

    /** The installation-wide value of one setting, read from the database now: null when there is no such row. */
    private static function setting(mysqli $db, string $key): ?string
    {
        $stmt = $db->prepare('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : (string) ($row['settingValue'] ?? '');
    }

    /** Write the installation-wide value of one setting, making the row when it is not there. */
    private static function writeSetting(mysqli $db, string $key, string $value): void
    {
        $stmt = $db->prepare(
            "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, '', 0) "
            . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)'
        );
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
    }

    /** True for text that is one to ten digits and nothing else (a log number or an account number: the columns hold up to ten). */
    private static function isNumber(?string $text): bool
    {
        return $text !== null && preg_match('/^[0-9]{1,10}$/', $text) === 1;
    }

    /**
     * "12,15,20" as whole numbers. Anything that is not a number is left out,
     * so text somebody typed into the setting cannot reach a statement.
     *
     * @return list<int>
     */
    private static function numberList(string $text): array
    {
        $numbers = [];
        foreach (explode(',', $text) as $part) {
            if (self::isNumber(trim($part)) === true && (int) trim($part) > 0) {
                $numbers[] = (int) trim($part);
            }
        }

        return array_values(array_unique($numbers));
    }
}
