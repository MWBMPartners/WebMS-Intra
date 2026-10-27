<?php
// Path: tools/backup-restore-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test for the portal's own backup and restore 📦🧪 (#577, #578, #579, #472)
 * -----------------------------------------------------------------------------
 * `Portal\Core\DbBackup` is the code that stands between a customer and losing
 * their data. A restore runs when something has already gone wrong, and a
 * backup that cannot be put back is found out on the one day it is needed. So
 * this script takes REAL snapshots of a real database with the real class,
 * damages the database, puts the snapshot back, and then compares EVERY VALUE
 * OF EVERY TABLE with how it stood before.
 *
 * THE COMPARISON SHARES NO CODE WITH THE CLASS. `brs_fingerprint()` below asks
 * the database for every value itself, over its own connection. If it borrowed
 * the class's reading or writing, the two could agree by making the same
 * mistake.
 *
 * WHAT IT NEEDS, AND WHY IT REFUSES RATHER THAN SKIPS
 *   A throwaway MySQL database whose name starts with `selftest_`, built from
 *   `web/_sql/full_schema.sql`, and nothing else in it. For example:
 *
 *       docker run --rm -d --name brs-mysql -e MYSQL_ROOT_PASSWORD=secret \
 *         -p 127.0.0.1:3307:3306 mysql:8.0.36
 *       docker exec brs-mysql mysql -uroot -psecret -e "CREATE DATABASE selftest_backup;
 *         CREATE USER 'brs'@'%' IDENTIFIED BY 'brs-pw';
 *         GRANT ALL PRIVILEGES ON selftest_backup.* TO 'brs'@'%';"
 *       docker exec -i brs-mysql mysql -ubrs -pbrs-pw selftest_backup < web/_sql/full_schema.sql
 *
 *       SELFTEST_DB_NAME=selftest_backup SELFTEST_DB_PORT=3307 SELFTEST_DB_USER=brs \
 *       SELFTEST_DB_PASS=brs-pw php tools/backup-restore-selftest.php
 *
 *   Give it a database user with rights on that ONE database, as a shared host
 *   does: several proofs are about what such a user is allowed to do. Without a
 *   database it stops with a message and exit code 2. A test that did not run
 *   has proved nothing, and must never read as a pass.
 *
 *   Settings (the same names as tools/feed-importer-selftest.php):
 *     SELFTEST_DB_HOST (default 127.0.0.1)   SELFTEST_DB_PORT (default 3306)
 *     SELFTEST_DB_USER (default root)        SELFTEST_DB_PASS (default empty)
 *     SELFTEST_DB_NAME (required; must start with selftest_)
 *
 *   Options:
 *     --only=1,5,12     run just these proofs
 *     --rows=60000      rows in the large table (proofs 5, 7, 9 and 11)
 *     --memory=32M      the memory limit proof 5 runs under
 *     --write-samples   write the two sample files that
 *                       tools/audit-checks/check_backup_column_types.py checks
 *                       against the schemas, then stop
 *
 * WHAT IT DOES TO THE DATABASE
 *   It adds rows whose identity numbers are 970000 or higher, makes a few
 *   scratch tables (names containing "Brs"), and removes all of them again.
 *   Before every proof it puts the database back to its starting state and
 *   CHECKS that it has; if it cannot, it stops, because every result after
 *   that would be about some other database. Its snapshots go in a scratch
 *   folder under the system's temporary folder, removed at the end.
 *
 * NO CHECK HERE DEPENDS ON TODAY'S DATE. The dates in the rows below are only
 * values to carry; nothing compares them with the clock. The proofs that do
 * use the clock (7 and 19, a folder name that is already taken; 12, how old an
 * unfinished folder is) work their times out from "now".
 *
 * THE PROOFS (numbered as in the plan for roll-out package G)
 *    1  Imported calendar events with awkward raw bytes               (#577)
 *    2  All nine tables whose rows point at their own table; a ring of
 *       two rows; a row pointing at itself                             (#578)
 *    3  The same, with "last changed" dates that must not be re-stamped
 *    4  The rehearsal says no to a snapshot the restore refuses and yes to
 *       one it accepts, and changes nothing either time
 *    5  A large table under a fixed memory limit                       (#579)
 *    6  A snapshot in the OLD form still restores (written here by a frozen
 *       copy of the old writer)
 *    7  A snapshot that fails or is killed: nothing counts it as a backup
 *    8  A table file cut short is refused before anything is touched
 *    9  A restore that fails, or is killed half way, changes nothing   (#472)
 *   10  Moments in TIMESTAMP columns survive a change of time zone,
 *       including the night the clocks go back
 *   11  A record saved WHILE a snapshot runs is in it whole or not at all
 *   12  prune() keeps finished snapshots and never removes a fresh
 *       unfinished one or a folder it did not make
 *   13  A table whose rows point at each other with the strict kind of link
 *   14  A column type the backup has not been taught is refused, and every
 *       type it has been taught comes back identical
 *   16  A link between two tables added AFTER the snapshot was taken (as a
 *       migration does): a pointer at a row that is gone is emptied and
 *       counted where the link's own rule says so, and refused otherwise
 *   17  Putting ONE table back: self-pointing rows, and rows the database
 *       now calls duplicates
 *   18  A restore never says yes while the database SAYS it changed a value:
 *       a column changed after the snapshot, on a connection the host left
 *       loose, and on one with the database's notes switched off; a change
 *       noted only when a pointer is filled in; dates of zero and a row
 *       numbered 0 come back exactly; the connection's own settings are put
 *       back afterwards. (The changes the database makes WITHOUT a word are
 *       listed in the class's header; nothing can refuse those.)
 *   19  Names and folders: a snapshot outside the backups folder, odd names
 *       sent by a browser, Delete and a link, a cell the safety net carried
 *       as bytes, a folder name already taken
 *   20  A large row that the database accepts by itself goes back although
 *       smaller rows were waiting to be sent with it; a row larger than the
 *       database's limit is refused in words before it is sent
 *   21  A table the database cannot undo changes to (not InnoDB) is refused
 *       before anything is touched
 *   22  Tables that are NOT in the snapshot and lose rows or pointers when it
 *       is put back: counted and reported, by the rehearsal too, including a
 *       table reached only through another outside table and an outside
 *       table's pointers at itself
 *   23  An undo the database does not confirm: the connection is closed, the
 *       result says so, and nothing has changed. The same when the
 *       connection's own settings cannot be put back after a restore
 *   26  The three tables a ONE-table restore refuses by name, because putting
 *       one of them back by itself leaves the rest of the database out of step
 *       with it: the refusal, that it happens before anything is read or
 *       emptied, that a table NOT on the list still goes back, and that a
 *       whole-database restore still puts all three back
 *   24  A value that is not on its column's list of choices: the snapshot is
 *       taken and SAYS it cannot be put back whole, naming the table, the
 *       column and how many; mended, a new snapshot goes back exactly
 *   (The numbers are not in order of running: 5, 7, 9 and 11 need the large
 *   table and run last.)
 *   (15, an older release meeting a new snapshot, was run once by hand against
 *   the release before this change and is recorded in the build's evidence;
 *   it cannot be a standing test because it needs the old code.)
 *
 * WHAT THIS CANNOT TELL YOU
 *   - Anything about MariaDB, or about a real shared host's limits.
 *   - That the backup page works in a browser: this calls the class directly.
 *   - Timings. The row counts are chosen to make faults show, not to measure.
 *
 * Exit: 0 only if every selected proof passed AND the run reached its end
 *       (it prints "REACHED THE END" as its last line; check for that line as
 *       well as the exit code, because a crash can look like a pass).
 *       1 if any proof failed. 2 if it could not run at all.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.3.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/577
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/578
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/579
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require __DIR__ . '/../web/_core/DbBackup.php';

use Portal\Core\DbBackup;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** Every row this test adds has an identity number at or above this. */
const BRS_ID = 970000;
/** The large table's rows start here, so they can be kept between proofs. */
const BRS_BIG_ID = 2000000;
/** A venue the test makes, and a number no venue has: swapping one for the other in a file plants a row the database must refuse. */
const BRS_VENUE = '987654';
const BRS_NO_SUCH_VENUE = '987655';

/** Each table the test adds rows to, and the column its rows are found by. */
const BRS_FIXTURE_TABLES = [
    'tblExternalEventChoices'   => 'choiceID',
    'tblEvents'                 => 'eventID',
    'tblExternalFeeds'          => 'feedID',
    'tblVenueAgreements'        => 'agreementID',
    'tblVenues'                 => 'venueID',
    'tblTasks'                  => 'taskID',
    'tblEventCategories'        => 'categoryID',
    'tblEventSeries'            => 'seriesID',
    'tblEventTypes'             => 'typeID',
    'tblAttendanceServiceTypes' => 'serviceTypeID',
    'tblAssetLoans'             => 'loanID',
    'tblAssets'                 => 'assetID',
    'tblAssetLocations'         => 'locationID',
    'tblGroups'                 => 'groupID',
    'tblAttendanceSessions'     => 'sessionID',
    'tblSettings'               => 'settingID',
    'tblActivityLogs'           => 'logID',
    // Proof 26's own rows. tblTotpBackupCodes is listed BEFORE tblUsers only
    // for tidiness; brs_tidy() switches the link checks off, so the order of
    // this list does not matter.
    'tblTotpBackupCodes'        => 'codeID',
    'tblUsers'                  => 'userID',
];

/** Scratch tables, children before parents. `brs_types_copy` has no `tbl` prefix ON PURPOSE: the backup leaves it alone. */
const BRS_SCRATCH_TABLES = [
    'tblZzBrsChild', 'tblZzBrsGone', 'tblZzBrsLoose', 'tblZzBrsKept', 'tblZzBrsPair', 'tblAaBrsParent', 'tblAaBrsPairParent',
    'tblZzBrsStrict', 'tblZzBrsFloat', 'tblZzBrsBit', 'tblZzBrsTypes', 'tblZzBrsDrift', 'tblZzBrsZero', 'tblZzBrsChoice',
    'tblZzBrsStamp', 'tblZzBrsLatin', 'tblZzBrsBig', 'tblZzBrsMyisam', 'brs_types_copy',
    'tblZaBrsOnward', 'tblZzBrsSelfOut', 'tblZzBrsSpaces', 'tblZzBrsChoiceB',
];

/** Where the two committed sample files live (see --write-samples). */
const BRS_SAMPLE_DIR = __DIR__ . '/audit-checks/fixtures/backup_format/good';

/** What the run knows as it goes. */
final class Brs
{
    public static mysqli $writer;          // adds, damages and tidies the test's own rows
    public static mysqli $reader;          // the independent reader: compares values, pinned to UTC
    public static string $root = '';       // scratch folder for snapshots
    /** @var array<string, string> */
    public static array $base = [];        // every table as it stood at the start
    /** @var array<string, string>|null */
    public static ?array $baseBig = null;  // the same, with the large table loaded
    public static int $bigRows = 60000;
    public static string $memory = '32M';
    public static bool $proofOk = true;
    public static int $checks = 0;
    public static bool $sessionLinkAtStart = false;   // see brs_sessionLink()
}

// =============================================================================
// 🔌 Connections
// =============================================================================

function brs_env(string $name, string $default): string
{
    return getenv($name) !== false ? (string) getenv($name) : $default;
}

/**
 * A connection whose database never confirms an undo. Everything else is the
 * real thing. The class under test must then CLOSE the connection to be sure
 * its unfinished work is undone (proof 23); nothing in the database itself can
 * be made to behave this way on demand.
 */
final class BrsNoUndo extends mysqli
{
    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        return false;
    }
}

/**
 * A connection that can be made to misbehave in two ways nothing in a real
 * database can be made to do on demand. Everything else is the real thing.
 *
 *   $failPutBack  the statement that puts the session's strictness BACK after
 *                 a restore fails (it is the second `SET SESSION sql_mode` on
 *                 the connection; the first, which sets it, works). Proof 23.
 *   $packetSays   what the connection answers when asked for the largest
 *                 message the database takes. The real database then accepts
 *                 a row the class believes is too large, so if the class SENT
 *                 the row the restore would succeed and the proof would fail:
 *                 that is how proof 20 shows the row was refused before it
 *                 was sent.
 */
final class BrsOddDb extends mysqli
{
    public static bool $failPutBack = false;
    public static int $packetSays = 0;
    private int $modeSets = 0;

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        if (self::$failPutBack === true && str_starts_with($query, 'SET SESSION sql_mode') === true && ++$this->modeSets === 2) {
            throw new mysqli_sql_exception('planted by the self-test: this connection will not take its settings back');
        }
        if (self::$packetSays > 0 && $query === 'SELECT @@session.max_allowed_packet') {
            $query = 'SELECT ' . self::$packetSays;
        }
        return parent::query($query, $result_mode);
    }
}

/**
 * A new connection, set up the way the portal sets its own up (utf8mb4,
 * nothing else).
 *
 * @param string               $zone    A time zone to give the session, or '' for the server's.
 * @param class-string<mysqli> $class   mysqli itself, BrsNoUndo or BrsOddDb.
 * @param string               $charset 'utf8mb4' as the portal uses; proof 19 uses 'binary'.
 */
function brs_connect(string $zone = '', string $class = mysqli::class, string $charset = 'utf8mb4'): mysqli
{
    $db = new $class(
        brs_env('SELFTEST_DB_HOST', '127.0.0.1'),
        brs_env('SELFTEST_DB_USER', 'root'),
        brs_env('SELFTEST_DB_PASS', ''),
        brs_env('SELFTEST_DB_NAME', ''),
        (int) brs_env('SELFTEST_DB_PORT', '3306')
    );
    $db->set_charset($charset);
    if ($zone !== '') {
        $db->query("SET time_zone = '" . $db->real_escape_string($zone) . "'");
    }
    return $db;
}

/**
 * Every setting of a connection's session, less the handful the database
 * changes by itself as statements run (found by reading them twice). Two of
 * these are equal only if a restore left the session exactly as it found it.
 *
 * @return array<string, string>
 */
function brs_sessionSettings(mysqli $db): array
{
    $settings = array_column($db->query('SHOW SESSION VARIABLES')->fetch_all(), 1, 0);
    foreach (['identity', 'last_insert_id', 'insert_id', 'statement_id', 'timestamp', 'error_count', 'warning_count'] as $moves) {
        unset($settings[$moves]);
    }
    return $settings;
}

/** The class under test, on a connection of its own, writing to its own scratch folder. */
function brs_backup(string $folder, ?mysqli $db = null): DbBackup
{
    $root = Brs::$root . '/' . $folder;
    if (is_dir($root) === false) {
        mkdir($root, 0750, true);
    }
    return new DbBackup($db ?? brs_connect(), $root);
}

// =============================================================================
// 🔬 The independent reader
// =============================================================================

/**
 * One line per table: how many rows, and a checksum of every value in every
 * row. Two of these are equal only if the two states of the database are.
 *
 * Text and raw bytes are read as HEX(), so bytes that are not valid text and
 * every character set compare exactly. Numbers, dates and times are read as
 * the database writes them, which is exact for every type the product uses
 * (HEX() of a decimal would round it to a whole number first). A TIMESTAMP is
 * read on a connection pinned to UTC, so what is compared is the moment.
 *
 * WHAT IT CANNOT DO: tell two DOUBLE values apart beyond the digits the
 * database prints. No product table has one; proof 14 compares its scratch
 * DOUBLE column inside the database instead.
 *
 * @param array<int, string> $leaveOut Tables not to read. Only proof 20 uses
 *        it, for its one table of very large rows: the database cannot sort
 *        or hex values that size, so that table is compared another way there.
 *
 * @return array<string, string> Table name => "N rows <checksum>".
 */
function brs_fingerprint(array $leaveOut = []): array
{
    $hexTypes = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'binary', 'varbinary',
                 'tinyblob', 'blob', 'mediumblob', 'longblob', 'geometry', 'point', 'bit'];
    $columns = [];
    $rs = Brs::$reader->query(
        'SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE FROM information_schema.COLUMNS c '
        . 'JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME '
        . "WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION"
    );
    while (($row = $rs->fetch_row()) !== null) {
        $quoted = '`' . $row[1] . '`';
        $columns[(string) $row[0]][] = in_array(strtolower((string) $row[2]), $hexTypes, true) === true ? 'HEX(' . $quoted . ')' : $quoted;
    }
    $rs->free();

    $print = [];
    foreach ($columns as $table => $expressions) {
        if (in_array($table, $leaveOut, true) === true) {
            continue;
        }
        // Ordered by every column, so the order rows happen to be stored in
        // cannot change the answer.
        $rs = Brs::$reader->query(
            'SELECT ' . implode(', ', $expressions) . ' FROM `' . $table . '` ORDER BY ' . implode(', ', range(1, count($expressions))),
            MYSQLI_USE_RESULT
        );
        $hash  = hash_init('sha256');
        $count = 0;
        while (($row = $rs->fetch_row()) !== null) {
            foreach ($row as $value) {
                // An empty value and no value at all must not look the same.
                hash_update($hash, $value === null ? "N\n" : 'S' . $value . "\n");
            }
            hash_update($hash, "--row--\n");
            $count++;
        }
        $rs->free();
        $print[$table] = $count . ' rows ' . hash_final($hash);
    }
    return $print;
}

/** The tables in which two fingerprints differ, as text; empty when they are the same. */
function brs_differences(array $before, array $after): string
{
    $differ = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $table) {
        if (($before[$table] ?? 'absent') !== ($after[$table] ?? 'absent')) {
            $differ[] = $table;
        }
    }
    sort($differ);
    return implode(', ', array_slice($differ, 0, 12)) . (count($differ) > 12 ? ' and ' . (count($differ) - 12) . ' more' : '');
}

// =============================================================================
// ✅ Reporting
// =============================================================================

function brs_check(bool $ok, string $what, string $detail = ''): bool
{
    Brs::$checks++;
    echo ($ok === true ? '    ok    ' : '    FAIL  ') . $what . "\n";
    if ($ok === false) {
        Brs::$proofOk = false;
        if ($detail !== '') {
            echo '          ' . str_replace("\n", "\n          ", trim($detail)) . "\n";
        }
    }
    return $ok;
}

/** Check the database is exactly as a fingerprint says it should be. */
function brs_checkSame(array $expected, string $what): bool
{
    $differ = brs_differences($expected, brs_fingerprint());
    return brs_check($differ === '', $what, 'these tables differ: ' . $differ);
}

/**
 * Call a method the class may not have, without stopping the proof. A release
 * older than this test has no newestFinished() or unfinishedCount(); run
 * against one, the check fails and says so, and the checks after it (which
 * are about what that release DOES do) still run.
 */
function brs_ask(DbBackup $backup, string $method): mixed
{
    return method_exists($backup, $method) === true ? $backup->$method() : 'this release has no ' . $method . '()';
}

/** A result from the class, shortened for a failure message. */
function brs_say(mixed $result): string
{
    return substr((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 600);
}

// =============================================================================
// 🧱 The test's own rows
// =============================================================================

function brs_sql(string ...$statements): void
{
    foreach ($statements as $statement) {
        Brs::$writer->query($statement);
    }
}

/** Imported calendar events: raw bytes that are not text, a zero byte in the middle, zero bytes at the end. */
function brs_addByteRows(): void
{
    brs_sql(
        "INSERT INTO tblExternalFeeds (feedID, siteID, name, url) VALUES (970701, 1, 'Self-test calendar', 'https://calendar.example.com/self-test.ics')",
        // Each event names its zone: since #604 the column has no default, and
        // the database refuses an event with none. 'UTC' is a test value.
        "INSERT INTO tblEvents (eventID, siteID, eventName, eventSlug, startDateTime, timezone, externalFeedID, externalUidHash) VALUES "
        . "(970501, 1, 'Ordinary event — côté jardin 🍂', 'brs-ordinary', '2026-10-25 01:30:00', 'UTC', NULL, NULL), "
        . "(970502, 1, 'Imported event', 'brs-imported', '2026-11-01 10:00:00', 'UTC', 970701, UNHEX(SHA2('uid-1@example.com', 256))), "
        . "(970503, 1, 'Imported, awkward bytes', 'brs-awkward', '2026-11-02 10:00:00', 'UTC', 970701, UNHEX('FF00FE80C328A0A1E28200000A0D22275C7B7D5B5D2C3A00000000000000FF00')), "
        . "(970504, 1, 'Imported, ends in zero bytes', 'brs-zeros', '2026-11-03 10:00:00', 'UTC', 970701, UNHEX('4142000000000000000000000000000000000000000000000000000000000000'))",
        "INSERT INTO tblExternalEventChoices (choiceID, siteID, feedID, scope, externalUidHash, audienceLevel, createdAt) VALUES "
        . "(970801, 1, 970701, 'series', UNHEX(SHA2('uid-1@example.com', 256)), 'members', '2026-10-01 09:00:00')"
    );
}

/**
 * Every table whose rows can point at rows of the same table, each with an
 * OLDER row pointing at a NEWER one (the order that could never be restored);
 * plus, in tblTasks, a ring of two rows and a row pointing at itself.
 */
function brs_addSelfPointingRows(): void
{
    brs_sql(
        "INSERT INTO tblVenues (venueID, venueName) VALUES (" . BRS_VENUE . ", 'Self-test hall')",
        "INSERT INTO tblVenueAgreements (agreementID, siteID, venueID, title, status) VALUES (970001, 1, " . BRS_VENUE . ", 'Hall hire 2025', 'active'), (970002, 1, " . BRS_VENUE . ", 'Hall hire 2026', 'active')",
        "UPDATE tblVenueAgreements SET status = 'superseded', supersededByID = 970002 WHERE agreementID = 970001",
        "INSERT INTO tblTasks (taskID, siteID, title) VALUES (970001, 1, 'Older task'), (970002, 1, 'Newer task'), (970003, 1, 'Ring A'), (970004, 1, 'Ring B'), (970005, 1, 'Points at itself')",
        "UPDATE tblTasks SET parentTaskID = 970002 WHERE taskID = 970001",
        "UPDATE tblTasks SET parentTaskID = 970004 WHERE taskID = 970003",
        "UPDATE tblTasks SET parentTaskID = 970003 WHERE taskID = 970004",
        "UPDATE tblTasks SET parentTaskID = 970005 WHERE taskID = 970005",
        "INSERT INTO tblEventCategories (categoryID, categoryName, categorySlug) VALUES (970001, 'Older category', 'brs-older-cat'), (970002, 'Newer category', 'brs-newer-cat')",
        "UPDATE tblEventCategories SET parentID = 970002 WHERE categoryID = 970001",
        "INSERT INTO tblEventSeries (seriesID, seriesName, seriesSlug) VALUES (970001, 'Older series', 'brs-older-series'), (970002, 'Newer series', 'brs-newer-series')",
        "UPDATE tblEventSeries SET parentID = 970002 WHERE seriesID = 970001",
        "INSERT INTO tblEventTypes (typeID, typeName, typeSlug) VALUES (970001, 'Older type', 'brs-older-type'), (970002, 'Newer type', 'brs-newer-type')",
        "UPDATE tblEventTypes SET parentID = 970002 WHERE typeID = 970001",
        "INSERT INTO tblAttendanceServiceTypes (serviceTypeID, typeName, typeSlug) VALUES (970001, 'Older service', 'brs-older-service'), (970002, 'Newer service', 'brs-newer-service')",
        "UPDATE tblAttendanceServiceTypes SET parentID = 970002 WHERE serviceTypeID = 970001",
        "INSERT INTO tblAssetLocations (locationID, locationName) VALUES (970001, 'Older location'), (970002, 'Newer location')",
        "UPDATE tblAssetLocations SET parentLocationID = 970002 WHERE locationID = 970001",
        "INSERT INTO tblAssets (assetID, name, publicToken) VALUES (970001, 'Older asset', REPEAT('y', 32)), (970002, 'Newer asset', REPEAT('z', 32))",
        "UPDATE tblAssets SET parentAssetID = 970002 WHERE assetID = 970001",
        "INSERT INTO tblAssetLoans (loanID, assetID, direction, counterpartyType) VALUES (970001, 970001, 'out', 'other'), (970002, 970001, 'out', 'other')",
        "UPDATE tblAssetLoans SET parentLoanID = 970002 WHERE loanID = 970001"
    );
}

/** Which of the nine self-pointing tables the rows above cover, read from the database so a tenth cannot go unnoticed. */
function brs_selfPointingTables(): array
{
    $tables = [];
    $rs = Brs::$reader->query(
        'SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = TABLE_NAME ORDER BY 1'
    );
    while (($row = $rs->fetch_row()) !== null) {
        $tables[] = (string) $row[0];
    }
    return $tables;
}

/** Damage the self-pointing rows in ways a restore must undo: a pointer emptied, a row deleted, a row changed, a row added. */
function brs_damageSelfPointingRows(): void
{
    brs_sql(
        'UPDATE tblVenueAgreements SET supersededByID = NULL WHERE agreementID = 970001',
        "UPDATE tblVenueAgreements SET title = 'Changed after the snapshot' WHERE agreementID = 970002",
        'UPDATE tblTasks SET parentTaskID = NULL WHERE taskID IN (970003, 970005)',
        'DELETE FROM tblTasks WHERE taskID = 970001',
        "INSERT INTO tblTasks (taskID, siteID, title) VALUES (970099, 1, 'Added after the snapshot')",
        'UPDATE tblEventSeries SET parentID = NULL WHERE seriesID = 970001',
        'UPDATE tblAssets SET parentAssetID = NULL WHERE assetID = 970001'
    );
}

/** The large table: plain, repeatable rows, so reloading them gives exactly the same table. */
function brs_loadBigRows(): void
{
    $padding = str_repeat('activity log padding ', 10);
    for ($from = 0; $from < Brs::$bigRows; $from += 1000) {
        $values = [];
        for ($i = $from; $i < min(Brs::$bigRows, $from + 1000); $i++) {
            $values[] = '(' . (BRS_BIG_ID + $i) . ", NULL, NULL, 'SelfTestRow', 'Row " . $i . ' ' . $padding
                      . "', '{}', 'session-" . $i . "', '192.0.2.1', 'backup self-test', '{}', '2026-01-01 00:00:00')";
        }
        Brs::$writer->query(
            'INSERT INTO tblActivityLogs (logID, siteID, userID, activityType, activityDescription, requestHeaders, '
            . 'sessionID, visitorIP, userAgent, sessionDataSnapshot, `timestamp`) VALUES ' . implode(',', $values)
        );
    }
}

function brs_bigRowCount(mysqli $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM tblActivityLogs WHERE logID >= ' . BRS_BIG_ID)->fetch_row()[0];
}

/**
 * The link from an attendance session to its event (`fk_att_sess_event`), put
 * in place or taken away. Proof 16 needs to take a snapshot BEFORE that link
 * exists and restore it AFTER.
 *
 * The link is added by migration 210 (roll-out package E), written here the
 * way that migration writes it. A database built before that migration does
 * not have it and one built after does, so this works from either: it looks
 * first, and brs_reset() puts back whichever was there at the start.
 */
function brs_sessionLink(?bool $wanted = null): bool
{
    $present = (int) Brs::$writer->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() "
        . "AND TABLE_NAME = 'tblAttendanceSessions' AND CONSTRAINT_NAME = 'fk_att_sess_event'"
    )->fetch_row()[0] === 1;
    if ($wanted === true && $present === false) {
        Brs::$writer->query('ALTER TABLE `tblAttendanceSessions` ADD CONSTRAINT `fk_att_sess_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE SET NULL');
    } elseif ($wanted === false && $present === true) {
        Brs::$writer->query('ALTER TABLE `tblAttendanceSessions` DROP FOREIGN KEY `fk_att_sess_event`');
    }
    return $present;
}

/**
 * Remove every row and scratch table this test makes. With $keepBigRows the
 * large table's rows are left in place (reloaded if any are missing), so the
 * four proofs that need them do not each load 60,000 rows again.
 *
 * The rows are deleted with the database's link checking switched off ON THE
 * TEST'S OWN CONNECTION ONLY, so the order does not matter. The class under
 * test never runs on this connection and never has that checking off.
 */
function brs_tidy(bool $keepBigRows = false): void
{
    $writer = Brs::$writer;
    $writer->query('SET SESSION foreign_key_checks = 0');
    foreach (BRS_FIXTURE_TABLES as $table => $column) {
        $writer->query('DELETE FROM `' . $table . '` WHERE `' . $column . '` >= ' . BRS_ID
                     . ($table === 'tblActivityLogs' && $keepBigRows === true ? ' AND `' . $column . '` < ' . BRS_BIG_ID : ''));
    }
    foreach (BRS_SCRATCH_TABLES as $table) {
        $writer->query('DROP TABLE IF EXISTS `' . $table . '`');
    }
    if ($keepBigRows === true && brs_bigRowCount($writer) !== Brs::$bigRows) {
        $writer->query('DELETE FROM tblActivityLogs WHERE logID >= ' . BRS_BIG_ID);
        brs_loadBigRows();
    }
    $writer->query('SET SESSION foreign_key_checks = 1');
}

/**
 * Put the database back to its starting state, and CHECK that it is.
 */
function brs_reset(bool $withBigRows): void
{
    brs_tidy($withBigRows);
    brs_sessionLink(Brs::$sessionLinkAtStart);

    $now = brs_fingerprint();
    if ($withBigRows === true && Brs::$baseBig === null) {
        // First time the large table is loaded: everything else must still be
        // the starting state.
        $expected = Brs::$base;
        $expected['tblActivityLogs'] = $now['tblActivityLogs'] ?? '';
        Brs::$baseBig = $expected;
    }
    $differ = brs_differences($withBigRows === true ? Brs::$baseBig : Brs::$base, $now);
    if ($differ !== '') {
        echo "\nSTOPPED. The database could not be put back to its starting state, so nothing after this\n";
        echo "point would mean anything. These tables differ: " . $differ . "\n";
        echo "Rebuild the database from web/_sql/full_schema.sql and run again.\n";
        exit(2);
    }
}

// =============================================================================
// 📁 Snapshot folders: the test's own reading and editing of them
// =============================================================================

/** The file a snapshot keeps one table in: the new form's or the old form's, whichever is there. */
function brs_tableFile(string $dir, string $table): string
{
    return is_file($dir . '/' . $table . '.jsonl') === true ? $dir . '/' . $table . '.jsonl' : $dir . '/' . $table . '.json';
}

function brs_copyFolder(string $from, string $to): void
{
    mkdir($to, 0750, true);
    foreach (scandir($from) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            copy($from . '/' . $entry, $to . '/' . $entry);
        }
    }
}

function brs_removeFolder(string $dir): void
{
    // Only ever a folder this run made, under its own scratch root.
    if ($dir === '' || Brs::$root === '' || str_starts_with($dir, Brs::$root) === false || is_dir($dir) === false) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) === true && is_link($path) === false) {
            brs_removeFolder($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

/** Change a snapshot's manifest: one table's checksum and row count, after the test has edited that table's file. */
function brs_remanifest(string $dir, string $table, ?string $sha256, ?int $rows = null): void
{
    $manifest = json_decode((string) file_get_contents($dir . '/_manifest.json'), true);
    if ($sha256 !== null) {
        $manifest['tables'][$table]['sha256'] = $sha256;
    }
    if ($rows !== null) {
        $manifest['tables'][$table]['rows'] = $rows;
    }
    file_put_contents($dir . '/_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * A copy of a good snapshot holding ONE row the database must refuse: a venue
 * agreement for a venue that does not exist. Its checksum is made to match, so
 * only actually writing the row can find the fault.
 */
function brs_plantBadRow(string $good, string $bad): bool
{
    brs_copyFolder($good, $bad);
    $file   = brs_tableFile($bad, 'tblVenueAgreements');
    $edited = str_replace('"' . BRS_VENUE . '"', '"' . BRS_NO_SUCH_VENUE . '"', (string) file_get_contents($file), $changed);
    file_put_contents($file, $edited);
    brs_remanifest($bad, 'tblVenueAgreements', hash('sha256', $edited));
    return $changed > 0;
}

// =============================================================================
// 👶 Child processes (for limits, and for things that get killed)
// =============================================================================

/**
 * Start this script again as a separate process doing one job with the class.
 * It prints "THREAD <its database connection's number>" and later
 * "RESULT <json>". A separate process is the only way to give the class a
 * memory limit of its own, or to kill it half way.
 *
 * @return array{process: resource, out: resource, err: resource}
 */
function brs_startChild(string $job, array $arguments, array $phpOptions = []): array
{
    $command = array_merge([PHP_BINARY], $phpOptions, [__FILE__, '--child=' . $job], $arguments);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($process) === false) {
        throw new RuntimeException('Could not start a child process.');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'out' => $pipes[1], 'err' => $pipes[2], 'seen' => '', 'errors' => ''];
}

/** Read whatever the child has printed so far. */
function brs_readChild(array &$child): void
{
    $child['seen']   .= (string) stream_get_contents($child['out']);
    $child['errors'] .= (string) stream_get_contents($child['err']);
}

function brs_childRunning(array &$child): bool
{
    brs_readChild($child);
    return proc_get_status($child['process'])['running'] === true;
}

/** Wait for the child to end (or stop it after the deadline); returns its RESULT, or null if it never gave one. */
function brs_finishChild(array &$child, int $deadlineSeconds = 900): ?array
{
    $until = microtime(true) + $deadlineSeconds;
    while (brs_childRunning($child) === true) {
        if (microtime(true) > $until) {
            proc_terminate($child['process'], 9);
            break;
        }
        usleep(20000);
    }
    usleep(20000);
    brs_readChild($child);
    fclose($child['out']);
    fclose($child['err']);
    proc_close($child['process']);
    if (preg_match('/^RESULT (.*)$/m', $child['seen'], $found) === 1) {
        $result = json_decode($found[1], true);
        return is_array($result) === true ? $result : null;
    }
    return null;
}

/** The number of the child's database connection, once it has printed it. */
function brs_childThread(array &$child): int
{
    $until = microtime(true) + 60;
    while (microtime(true) < $until) {
        brs_readChild($child);
        if (preg_match('/^THREAD (\d+)$/m', $child['seen'], $found) === 1) {
            return (int) $found[1];
        }
        usleep(5000);
    }
    return 0;
}

/** Wait until a condition holds; false if the deadline passes first. */
function brs_waitFor(callable $condition, float $seconds): bool
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        if ($condition() === true) {
            return true;
        }
        usleep(2000);
    }
    return false;
}

/** The snapshot folder a child is writing into, once the large table's file has appeared in it. */
function brs_folderBeingWritten(string $root): string
{
    foreach (glob($root . '/upgrade-*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (is_file($dir . '/_manifest.json') === false
            && (is_file($dir . '/tblActivityLogs.jsonl') === true || is_file($dir . '/tblActivityLogs.json') === true)
        ) {
            return $dir;
        }
    }
    return '';
}

/** What a child process does. Never reached in an ordinary run. */
function brs_runChild(string $job, array $arguments): never
{
    $db = brs_connect();
    echo 'THREAD ' . $db->thread_id . "\n";
    $backup = new DbBackup($db, $arguments[0]);
    $result = match ($job) {
        'snapshot' => $backup->snapshot('self-test child'),
        'check'    => $backup->restoreAll($arguments[0] . '/' . $arguments[1], true),
        'restore'  => $backup->restoreAll($arguments[0] . '/' . $arguments[1], false),
        default    => ['success' => false, 'error' => 'unknown job'],
    };
    $result['peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
    echo 'RESULT ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    exit(0);
}

// =============================================================================
// 🧊 The OLD form, written by a frozen copy of the old writer
// =============================================================================

/**
 * Write a snapshot exactly as DbBackup did before October 2026: one
 * pretty-printed JSON document per table and a manifest with no `format`.
 *
 * FROZEN ON PURPOSE. This is a copy of the old writer's logic and must not be
 * "tidied" to match the class: its whole job is to keep producing the files
 * that real customers have on disk, so proof 6 can show they still restore.
 */
function brs_legacySnapshot(mysqli $db, string $dir): void
{
    mkdir($dir, 0750, true);
    $generated = [];
    $rs = $db->query(
        'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
        . "AND GENERATION_EXPRESSION IS NOT NULL AND GENERATION_EXPRESSION <> ''"
    );
    while (($row = $rs->fetch_assoc()) !== null) {
        $generated[(string) $row['TABLE_NAME']][] = (string) $row['COLUMN_NAME'];
    }
    $manifest = ['created_at' => date('c'), 'reason' => 'old form', 'php_version' => PHP_VERSION,
                 'mysql_server' => $db->server_info, 'tables' => []];
    $tables = $db->query("SHOW TABLES LIKE 'tbl%'");
    while (($listed = $tables->fetch_array(MYSQLI_NUM)) !== null) {
        $table   = (string) $listed[0];
        $rs      = $db->query('SELECT * FROM `' . $table . '`');
        $columns = [];
        foreach ($rs->fetch_fields() as $field) {
            $columns[] = (string) $field->name;
        }
        $columns = array_values(array_diff($columns, $generated[$table] ?? []));
        $rows    = [];
        while (($row = $rs->fetch_assoc()) !== null) {
            foreach ($generated[$table] ?? [] as $column) {
                unset($row[$column]);
            }
            $rows[] = $row;
        }
        $json = json_encode(
            ['meta' => ['table' => $table, 'columns' => $columns, 'row_count' => count($rows), 'snapped_at' => date('c')], 'rows' => $rows],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        file_put_contents($dir . '/' . $table . '.json', $json);
        $manifest['tables'][$table] = ['columns' => $columns, 'rows' => count($rows), 'sha256' => hash('sha256', $json)];
    }
    file_put_contents($dir . '/_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// =============================================================================
// 📐 The committed sample files
// =============================================================================

/** The names of the properties in a file's manifest, one table's entry, first line and last line. */
function brs_shapeOf(string $dir, string $table): array
{
    $manifest = json_decode((string) @file_get_contents($dir . '/_manifest.json'), true);
    $lines    = @file($dir . '/' . $table . '.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
    $keys     = static function (mixed $value): array {
        $names = is_array($value) === true ? array_keys($value) : [];
        sort($names);
        return $names;
    };
    return [
        'manifest'   => $keys($manifest),
        'table'      => $keys($manifest['tables'][$table] ?? null),
        'first line' => $keys(json_decode($lines[0] ?? '', true)),
        'last line'  => $keys(json_decode($lines[count($lines) - 1] ?? '', true)),
    ];
}

/** Write the sample that the schema check reads: a real snapshot's manifest, cut down to two small tables, and those two files. */
function brs_writeSamples(string $snapshot): void
{
    $keep     = ['tblExternalEventChoices', 'tblExternalFeeds'];
    $manifest = json_decode((string) file_get_contents($snapshot . '/_manifest.json'), true);
    $manifest['tables'] = array_intersect_key($manifest['tables'], array_flip($keep));
    if (is_dir(BRS_SAMPLE_DIR) === false) {
        mkdir(BRS_SAMPLE_DIR, 0755, true);
    }
    file_put_contents(
        BRS_SAMPLE_DIR . '/_manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    foreach ($keep as $table) {
        copy($snapshot . '/' . $table . '.jsonl', BRS_SAMPLE_DIR . '/' . $table . '.jsonl');
    }
}

// =============================================================================
// 🧪 The proofs
// =============================================================================

/** 1 — imported calendar events with awkward raw bytes (#577). */
function brs_proof1(): void
{
    brs_addByteRows();
    $before = brs_fingerprint();
    $backup = brs_backup('p1');

    $taken = $backup->snapshot('proof 1');
    if (brs_check($taken['success'] === true, 'a snapshot is taken with imported calendar events in the database', brs_say($taken)) === false) {
        return;
    }
    $head = json_decode((string) (@file($taken['directory'] . '/tblEvents.jsonl')[0] ?? ''), true);
    brs_check(
        is_array($head) === true && in_array('externalUidHash', (array) ($head['base64'] ?? []), true) === true,
        'the events file says its raw-bytes column travels as base64',
        'first line: ' . brs_say($head)
    );
    if (is_dir(BRS_SAMPLE_DIR) === true) {
        $now    = brs_shapeOf($taken['directory'], 'tblExternalEventChoices');
        $sample = brs_shapeOf(BRS_SAMPLE_DIR, 'tblExternalEventChoices');
        brs_check(
            $now === $sample,
            'the committed sample files still have the shape the class writes today',
            "written today: " . brs_say($now) . "\ncommitted sample: " . brs_say($sample)
            . "\nRun this script with --write-samples, and bring the two schema files into line."
        );
    } else {
        brs_check(false, 'the committed sample files are present', BRS_SAMPLE_DIR . ' is missing');
    }

    brs_sql(
        'UPDATE tblEvents SET externalUidHash = UNHEX(REPEAT(\'11\', 32)) WHERE eventID = 970503',
        'DELETE FROM tblExternalEventChoices WHERE choiceID = 970801',
        'DELETE FROM tblEvents WHERE eventID = 970504',
        "INSERT INTO tblEvents (eventID, siteID, eventName, eventSlug, startDateTime, timezone) VALUES (970599, 1, 'Added after the snapshot', 'brs-added', '2026-12-01 10:00:00', 'UTC')"
    );
    brs_check(brs_differences($before, brs_fingerprint()) !== '', 'the damage really changed the database');

    $restored = $backup->restoreAll($taken['directory']);
    brs_check($restored['success'] === true, 'the snapshot is put back', brs_say($restored));
    brs_checkSame($before, 'every value of every table is as it was, raw bytes included');
}

/** 2 — rows that point at rows of their own table (#578). */
function brs_proof2(): void
{
    brs_addSelfPointingRows();
    $covered = array_intersect(brs_selfPointingTables(), array_keys(BRS_FIXTURE_TABLES));
    brs_check(
        count($covered) === count(brs_selfPointingTables()) && count($covered) >= 9,
        'this proof has rows in every table that points at itself (' . count(brs_selfPointingTables()) . ' today)',
        'not covered: ' . implode(', ', array_diff(brs_selfPointingTables(), array_keys(BRS_FIXTURE_TABLES)))
    );
    $before = brs_fingerprint();
    $backup = brs_backup('p2');

    $taken = $backup->snapshot('proof 2');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    brs_damageSelfPointingRows();
    brs_check(brs_differences($before, brs_fingerprint()) !== '', 'the damage really changed the database');

    $restored = $backup->restoreAll($taken['directory']);
    brs_check($restored['success'] === true, 'the snapshot is put back although older rows point at newer ones', brs_say($restored));
    brs_checkSame($before, 'every value of every table is as it was: chains, the ring and the row pointing at itself');
}

/** 3 — the same, with "last changed" dates that filling a pointer in must not re-stamp. */
function brs_proof3(): void
{
    brs_addSelfPointingRows();
    $stamped = [];
    $rs = Brs::$reader->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%on update%'"
    );
    while (($row = $rs->fetch_row()) !== null) {
        if (in_array($row[0], brs_selfPointingTables(), true) === true) {
            $stamped[(string) $row[0]] = (string) $row[1];
            // Setting the column by name is what stops the database stamping it with "now".
            Brs::$writer->query('UPDATE `' . $row[0] . '` SET `' . $row[1] . "` = '2020-01-02 03:04:05' WHERE `"
                              . BRS_FIXTURE_TABLES[$row[0]] . '` >= ' . BRS_ID);
        }
    }
    brs_check(count($stamped) >= 5, 'at least five self-pointing tables have a date the database re-stamps on change (' . count($stamped) . ')');
    $before = brs_fingerprint();
    $backup = brs_backup('p3');

    $taken = $backup->snapshot('proof 3');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    brs_damageSelfPointingRows();
    $restored = $backup->restoreAll($taken['directory']);
    brs_check($restored['success'] === true, 'the snapshot is put back', brs_say($restored));

    $task = (string) (Brs::$reader->query('SELECT updatedAt FROM tblTasks WHERE taskID = 970001')->fetch_row()[0] ?? 'row missing');
    brs_check($task === '2020-01-02 03:04:05', 'a task whose pointer was filled in still carries its old "last changed" date', 'it now reads ' . $task);
    brs_checkSame($before, 'every value of every table is as it was');
}

/** 4 — the rehearsal gives the restore's own answer and changes nothing. */
function brs_proof4(): void
{
    brs_addSelfPointingRows();
    $before = brs_fingerprint();
    $backup = brs_backup('p4');

    $taken = $backup->snapshot('proof 4');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    $bad = dirname($taken['directory']) . '/planted-bad-row';
    brs_check(brs_plantBadRow($taken['directory'], $bad) === true, 'a row the database must refuse was planted in a copy of the snapshot (checksum made to match)');

    brs_damageSelfPointingRows();
    $damaged = brs_fingerprint();

    $rehearsal = $backup->restoreAll($bad, true);
    brs_check($rehearsal['success'] === false, 'the check-only run says NO to the snapshot holding the bad row', brs_say($rehearsal));
    brs_check(
        str_contains((string) $rehearsal['error'], 'tblVenueAgreements') === true
        && str_contains((string) $rehearsal['error'], 'venueID') === true
        && str_contains((string) $rehearsal['error'], BRS_NO_SUCH_VENUE) === true,
        'and says why: it names the table, the column and the row that is missing',
        (string) $rehearsal['error']
    );
    brs_checkSame($damaged, 'the database is exactly as it was after that check');

    $real = $backup->restoreAll($bad, false);
    brs_check($real['success'] === false, 'the real restore refuses the same snapshot', brs_say($real));
    brs_checkSame($damaged, 'the database is exactly as it was after the refused restore');

    $rehearsal = $backup->restoreAll($taken['directory'], true);
    brs_check($rehearsal['success'] === true, 'the check-only run says YES to the good snapshot', brs_say($rehearsal));
    brs_check(
        $rehearsal['rows_restored'] === $taken['rows'],
        'and reports having written every row (' . $taken['rows'] . ')',
        brs_say($rehearsal)
    );
    brs_checkSame($damaged, 'THE REHEARSAL CHANGED NOTHING: the database is still exactly in its damaged state');

    $real = $backup->restoreAll($taken['directory'], false);
    brs_check($real['success'] === true, 'the real restore of the good snapshot succeeds', brs_say($real));
    brs_checkSame($before, 'every value of every table is as it was');
}

/** 5 — a large table under a fixed memory limit (#579). */
function brs_proof5(): void
{
    brs_addSelfPointingRows();
    $before = brs_fingerprint();
    $root   = Brs::$root . '/p5';
    mkdir($root, 0750, true);
    $limit  = ['-d', 'memory_limit=' . Brs::$memory];
    $said   = static function (array $child, ?array $result): string {
        return $result === null ? 'the process gave no result. It printed: ' . substr(trim($child['errors'] . $child['seen']), 0, 400) : brs_say($result);
    };

    $child = brs_startChild('snapshot', [$root], $limit);
    $taken = brs_finishChild($child);
    if (brs_check(
        ($taken['success'] ?? false) === true,
        'a snapshot of ' . number_format(Brs::$bigRows) . ' activity-log rows is taken under memory_limit=' . Brs::$memory
        . ' (peak ' . ($taken['peak_mb'] ?? '?') . ' MB)',
        $said($child, $taken)
    ) === false) {
        return;
    }
    $name = basename((string) $taken['directory']);

    brs_damageSelfPointingRows();
    Brs::$writer->query('DELETE FROM tblActivityLogs WHERE logID BETWEEN ' . (BRS_BIG_ID + 10000) . ' AND ' . (BRS_BIG_ID + 10999));
    $damaged = brs_fingerprint();

    $child     = brs_startChild('check', [$root, $name], $limit);
    $rehearsal = brs_finishChild($child);
    brs_check(
        ($rehearsal['success'] ?? false) === true,
        'the rehearsal runs under the same limit (peak ' . ($rehearsal['peak_mb'] ?? '?') . ' MB)',
        $said($child, $rehearsal)
    );
    brs_checkSame($damaged, 'the rehearsal changed nothing');

    $child    = brs_startChild('restore', [$root, $name], $limit);
    $restored = brs_finishChild($child);
    brs_check(
        ($restored['success'] ?? false) === true,
        'the restore runs under the same limit (peak ' . ($restored['peak_mb'] ?? '?') . ' MB)',
        $said($child, $restored)
    );
    brs_checkSame($before, 'every value of every table is as it was, all ' . number_format(Brs::$bigRows) . ' rows included');
}

/** 6 — a snapshot in the old form still restores. */
function brs_proof6(): void
{
    brs_addSelfPointingRows();
    $before = brs_fingerprint();
    $backup = brs_backup('p6');
    $old    = Brs::$root . '/p6/upgrade-20260901-120000';
    brs_legacySnapshot(brs_connect(), $old);
    brs_check(
        is_file($old . '/tblTasks.json') === true && isset(json_decode((string) file_get_contents($old . '/_manifest.json'), true)['format']) === false,
        'an old-form snapshot was written by the frozen copy of the old writer (one .json per table, no "format" in the manifest)'
    );

    brs_damageSelfPointingRows();
    $damaged = brs_fingerprint();

    $rehearsal = $backup->restoreAll($old, true);
    brs_check($rehearsal['success'] === true, 'the rehearsal accepts it', brs_say($rehearsal));
    brs_checkSame($damaged, 'the rehearsal changed nothing');

    $restored = $backup->restoreAll($old, false);
    brs_check($restored['success'] === true, 'the old-form snapshot is put back, self-pointing rows included', brs_say($restored));
    brs_checkSame($before, 'every value of every table is as it was');

    // A newer form than this release knows must be refused, not guessed at.
    $newer = Brs::$root . '/p6/from-a-newer-release';
    brs_copyFolder($old, $newer);
    $manifest = json_decode((string) file_get_contents($newer . '/_manifest.json'), true);
    $manifest['format'] = 3;
    file_put_contents($newer . '/_manifest.json', json_encode($manifest));
    $refused = $backup->restoreAll($newer, false);
    brs_check(
        $refused['success'] === false && stripos((string) $refused['error'], 'newer release') !== false,
        'a snapshot marked as a newer form than this release knows is refused',
        brs_say($refused)
    );
    brs_checkSame($before, 'and nothing changed');
}

/** 7 — a snapshot that fails or is killed is never counted as a backup. */
function brs_proof7(): void
{
    $before = brs_fingerprint();
    $root   = Brs::$root . '/p7';
    $backup = brs_backup('p7');

    $good = $backup->snapshot('proof 7, the good one');
    if (brs_check($good['success'] === true, 'a good snapshot is taken first', brs_say($good)) === false) {
        return;
    }
    $goodName = basename($good['directory']);
    // The folder is named by the second; make sure the next one gets a new name.
    brs_waitFor(static fn (): bool => date('Ymd-His') !== substr($goodName, 8), 3);

    // --- 7a. A failure PHP can catch: the database connection is cut half way.
    $child  = brs_startChild('snapshot', [$root]);
    $thread = brs_childThread($child);
    $seen   = brs_waitFor(static fn (): bool => brs_folderBeingWritten($root) !== '', 120);
    if ($seen === true && $thread > 0) {
        try {
            Brs::$writer->query('KILL ' . $thread);
        } catch (Throwable $e) {
            unset($e);
        }
    }
    $result = brs_finishChild($child);
    brs_check($seen === true, 'a second snapshot was caught half way and its database connection was cut');
    brs_check(
        is_array($result) === true && $result['success'] === false,
        'that snapshot reports failure',
        $result === null ? 'no result. It printed: ' . substr($child['errors'] . $child['seen'], 0, 300) : brs_say($result)
    );
    $folders = array_map('basename', glob($root . '/*', GLOB_ONLYDIR) ?: []);
    brs_check($folders === [$goodName], 'and removed its own unfinished folder', 'folders now: ' . implode(', ', $folders));

    // --- 7b. A failure PHP cannot catch: the whole process is killed.
    brs_waitFor(static fn (): bool => is_dir($root . '/upgrade-' . date('Ymd-His')) === false, 3);
    $child = brs_startChild('snapshot', [$root]);
    $seen  = brs_waitFor(static fn (): bool => brs_folderBeingWritten($root) !== '', 120);
    $left  = brs_folderBeingWritten($root);
    proc_terminate($child['process'], 9);
    $result = brs_finishChild($child);
    brs_check($seen === true && $result === null, 'a third snapshot was killed half way (the process, not just its connection)');
    brs_check(
        $left !== '' && is_dir($left) === true && is_file($left . '/_manifest.json') === false,
        'it left a folder behind with no manifest'
    );

    $list = [];
    foreach ($backup->listSnapshots() as $entry) {
        $list[$entry['name']] = $entry['complete'] ?? 'no "complete" in the list';
    }
    brs_check(($list[basename($left)] ?? null) === false, 'the list marks that folder as unfinished', brs_say($list));
    brs_check(($list[$goodName] ?? null) === true, 'and the good snapshot as finished', brs_say($list));
    brs_check(
        (brs_ask($backup, 'newestFinished')['name'] ?? '') === $goodName,
        'the newest FINISHED snapshot is the good one, although the unfinished folder is newer',
        'it says: ' . brs_say(brs_ask($backup, 'newestFinished'))
    );
    brs_check(brs_ask($backup, 'unfinishedCount') === 1, 'one unfinished folder is counted, so the administrator can be told', 'counted ' . brs_say(brs_ask($backup, 'unfinishedCount')));

    $refused = $backup->restoreAll($left, false);
    brs_check($refused['success'] === false, 'restoring the whole unfinished folder is refused', brs_say($refused));
    $refused = $backup->restoreTable($left, 'tblActivityLogs');
    brs_check($refused['success'] === false, 'restoring one table from the unfinished folder is refused too', brs_say($refused));
    brs_checkSame($before, 'nothing in the database changed through any of that');

    // --- 7c. Two snapshots in the same second must not share a folder.
    //     The name is the second the snapshot starts in, so every name it
    //     could get in the next ten seconds is taken first.
    $taken = [];
    for ($i = 0; $i <= 10; $i++) {
        $name = $root . '/upgrade-' . date('Ymd-His', time() + $i);
        if (is_dir($name) === false) {
            mkdir($name, 0750);
            $taken[] = $name;
        }
    }
    $second = $backup->snapshot('proof 7, same second');
    $empty  = true;
    foreach ($taken as $name) {
        $empty = $empty && count(scandir($name)) === 2;
    }
    brs_check(
        $second['success'] === false && $empty === true,
        'a snapshot whose folder name is already taken is refused, and the folder that was there is left untouched',
        brs_say($second)
    );
    foreach ($taken as $name) {
        brs_removeFolder($name);
    }
}

/** 8 — a table file cut short is refused before anything is touched. */
function brs_proof8(): void
{
    brs_addSelfPointingRows();
    $backup = brs_backup('p8');
    $taken  = $backup->snapshot('proof 8');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    brs_damageSelfPointingRows();
    $damaged = brs_fingerprint();
    $root    = dirname($taken['directory']);

    $cases = [
        'cut 40 bytes short, checksum still in the manifest' => static function (string $file, string $dir): void {
            file_put_contents($file, substr((string) file_get_contents($file), 0, -40));
        },
        'its LAST LINE lost and no checksum to catch it (every line that remains is a good row)' => static function (string $file, string $dir): void {
            $lines = file($file);
            array_pop($lines);
            file_put_contents($file, implode('', $lines));
            brs_remanifest($dir, 'tblTasks', '');
        },
        'cut off in the middle of a row, and no checksum to catch it' => static function (string $file, string $dir): void {
            file_put_contents($file, substr((string) file_get_contents($file), 0, -60));
            brs_remanifest($dir, 'tblTasks', '');
        },
    ];
    $n = 0;
    foreach ($cases as $what => $cut) {
        $copy = $root . '/cut-' . (++$n);
        brs_copyFolder($taken['directory'], $copy);
        $cut(brs_tableFile($copy, 'tblTasks'), $copy);
        foreach ([true => 'the rehearsal', false => 'the restore'] as $dryRun => $who) {
            $result = $backup->restoreAll($copy, (bool) $dryRun);
            brs_check(
                $result['success'] === false && str_contains((string) $result['error'], 'Nothing has been changed'),
                $who . ' refuses a snapshot with one file ' . $what,
                brs_say($result)
            );
        }
        brs_checkSame($damaged, 'and nothing was touched');
    }
}

/** 9 — a restore that fails, or is killed half way, leaves the database exactly as it was (#472). */
function brs_proof9(): void
{
    brs_addSelfPointingRows();
    $root   = Brs::$root . '/p9';
    $backup = brs_backup('p9');
    $taken  = $backup->snapshot('proof 9');
    if (brs_check($taken['success'] === true, 'a snapshot is taken (' . number_format((int) $taken['rows']) . ' rows)', brs_say($taken)) === false) {
        return;
    }
    $before = brs_fingerprint();

    brs_damageSelfPointingRows();
    Brs::$writer->query('DELETE FROM tblActivityLogs WHERE logID BETWEEN ' . (BRS_BIG_ID + 10000) . ' AND ' . (BRS_BIG_ID + 10999));
    $damaged   = brs_fingerprint();
    $committed = brs_bigRowCount(Brs::$writer);

    // --- 9a. A row the database refuses, after tens of thousands were written.
    $bad = $root . '/planted-bad-row';
    brs_plantBadRow($taken['directory'], $bad);
    $result = $backup->restoreAll($bad, false);
    brs_check($result['success'] === false, 'a restore that meets a row the database refuses reports failure', brs_say($result));
    brs_checkSame($damaged, 'and the database is exactly as it was before the attempt');

    // --- 9b. The process is killed while rows are being written.
    //     A second connection that is allowed to see unfinished work
    //     ("READ UNCOMMITTED") watches the large table's row count. The moment
    //     it stops matching the saved count, the restore is in the middle of
    //     its writing, and that is when the process is killed.
    $peek = brs_connect();
    $peek->query('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
    foreach (['restore' => 'a real restore', 'check' => 'a rehearsal'] as $job => $who) {
        $child  = brs_startChild($job, [$root, basename($taken['directory'])]);
        $midway = brs_waitFor(
            static function () use ($peek, $committed, &$child): bool {
                return brs_bigRowCount($peek) !== $committed || brs_childRunning($child) === false;
            },
            300
        );
        $writing = $midway === true && brs_childRunning($child) === true;
        proc_terminate($child['process'], 9);
        $result = brs_finishChild($child);
        brs_check($writing === true && $result === null, $who . ' was killed while it was half way through writing rows');
        // The database undoes the unfinished work once it notices the connection has gone.
        //     "Settled" means the count is back AND stays back: on its way
        //     down the count can pass through the right number for an instant.
        $settled = brs_waitFor(
            static function () use ($peek, $committed): bool {
                if (brs_bigRowCount($peek) !== $committed) {
                    return false;
                }
                usleep(300000);
                return brs_bigRowCount($peek) === $committed;
            },
            300
        );
        brs_check($settled === true, 'the database undid the unfinished work by itself');
        brs_checkSame($damaged, 'and the database is exactly as it was before ' . $who . ' started');
    }
    $peek->close();

    $result = $backup->restoreAll($taken['directory'], false);
    brs_check($result['success'] === true, 'the same snapshot then restores normally', brs_say($result));
    brs_checkSame($before, 'every value of every table is as it was');
}

/** 10 — moments in TIMESTAMP columns survive a change of the connection's time zone. */
function brs_proof10(): void
{
    // Written over the UTC connection, so these ARE the moments named.
    // 970902 and 970903 are both "01:30" on a clock in London on the night the
    // clocks go back: once in summer time, once an hour later in winter time.
    brs_sql(
        "INSERT INTO tblGroups (groupID, siteID, groupName, dateAdded, dateLastUpdated) VALUES "
        . "(970901, 1, 'Self-test group, midday', '2026-06-01 12:00:00', '2026-06-01 12:00:00'), "
        . "(970902, 1, 'Self-test group, first 01:30', '2026-10-25 00:30:00', '2026-10-25 00:30:00'), "
        . "(970903, 1, 'Self-test group, second 01:30', '2026-10-25 01:30:00', '2026-10-25 01:30:00')"
    );
    $before = brs_fingerprint();
    $moments = static function (): string {
        $rows = Brs::$reader->query('SELECT groupID, dateAdded, dateLastUpdated FROM tblGroups WHERE groupID >= ' . BRS_ID . ' ORDER BY groupID')->fetch_all();
        return implode(' | ', array_map(static fn (array $r): string => implode(' ', $r), $rows));
    };
    $want = $moments();

    foreach ([['-07:00', '+00:00'], ['Europe/London', 'Europe/London']] as $n => [$snapshotZone, $restoreZone]) {
        $taking  = brs_connect($snapshotZone);
        $backup  = brs_backup('p10-' . $n, $taking);
        $taken   = $backup->snapshot('proof 10');
        if (brs_check($taken['success'] === true, 'a snapshot is taken on a connection whose time zone is ' . $snapshotZone, brs_say($taken)) === false) {
            return;
        }
        $zoneNow = (string) $taking->query('SELECT @@session.time_zone')->fetch_row()[0];
        brs_check($zoneNow === $snapshotZone, 'and the connection has its own time zone back afterwards', 'it is now ' . $zoneNow);

        brs_sql("UPDATE tblGroups SET dateAdded = '2001-01-01 00:00:00', dateLastUpdated = '2001-01-01 00:00:00' WHERE groupID >= " . BRS_ID);

        $restoring = brs_connect($restoreZone);
        $restored  = brs_backup('p10-' . $n, $restoring)->restoreAll($taken['directory'], false);
        brs_check($restored['success'] === true, 'it is put back on a connection whose time zone is ' . $restoreZone, brs_say($restored));
        $zoneNow = (string) $restoring->query('SELECT @@session.time_zone')->fetch_row()[0];
        brs_check($zoneNow === $restoreZone, 'and that connection has its own time zone back afterwards', 'it is now ' . $zoneNow);
        brs_check($moments() === $want, 'the three moments, read in UTC, are the moments that were saved', "saved:  " . $want . "\nnow:    " . $moments());
        brs_checkSame($before, 'every value of every table is as it was');
    }

    // The same for putting ONE table back, which sets the connection's zone in
    // a place of its own. A scratch table, because a single table can only be
    // put back when nothing points at it.
    brs_sql(
        'CREATE TABLE tblZzBrsStamp (id INT PRIMARY KEY, at TIMESTAMP NULL) ENGINE=InnoDB',
        "INSERT INTO tblZzBrsStamp VALUES (1, '2026-06-01 12:00:00'), (2, '2026-10-25 00:30:00'), (3, '2026-10-25 01:30:00')"
    );
    $before = brs_fingerprint();
    $taken  = brs_backup('p10-one', brs_connect('-07:00'))->snapshot('proof 10, one table');
    if (brs_check($taken['success'] === true, 'a snapshot holding a scratch table of moments is taken at -07:00', brs_say($taken)) === false) {
        return;
    }
    brs_sql("UPDATE tblZzBrsStamp SET at = '2001-01-01 00:00:00'");
    $restoring = brs_connect('+05:30');
    $restored  = brs_backup('p10-one', $restoring)->restoreTable($taken['directory'], 'tblZzBrsStamp');
    brs_check($restored['success'] === true, 'that ONE table is put back on a connection at +05:30', brs_say($restored));
    brs_check((string) $restoring->query('SELECT @@session.time_zone')->fetch_row()[0] === '+05:30', 'and that connection has its own time zone back afterwards');
    brs_checkSame($before, 'the moments in it, read in UTC, are the moments that were saved (a one-table restore reads them in the zone they were written in)');
}

/** 11 — a record saved while the snapshot runs is in it whole or not at all. */
function brs_proof11(): void
{
    // A parent table that is read FIRST and a child table that is read LAST,
    // with the large table in between to give time to save a record.
    brs_sql(
        "CREATE TABLE tblAaBrsParent (id INT PRIMARY KEY, label VARCHAR(60) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE tblZzBrsChild (id INT PRIMARY KEY, parentID INT NOT NULL, label VARCHAR(60) NOT NULL, "
        . "CONSTRAINT fk_brs_child FOREIGN KEY (parentID) REFERENCES tblAaBrsParent (id)) ENGINE=InnoDB"
    );
    $root   = Brs::$root . '/p11';
    $backup = brs_backup('p11');

    $child  = brs_startChild('snapshot', [$root]);
    $seen   = brs_waitFor(static fn (): bool => brs_folderBeingWritten($root) !== '', 120);
    $dir    = brs_folderBeingWritten($root);
    // The parent's file is finished and the child's has not been started:
    // save a parent and its child now, as one piece of work.
    Brs::$writer->begin_transaction();
    brs_sql(
        "INSERT INTO tblAaBrsParent (id, label) VALUES (1, 'brs-parent-saved-mid-snapshot')",
        "INSERT INTO tblZzBrsChild (id, parentID, label) VALUES (1, 1, 'brs-child-saved-mid-snapshot')"
    );
    Brs::$writer->commit();
    $inTime = $seen === true && is_file($dir . '/tblZzBrsChild.jsonl') === false && is_file($dir . '/tblZzBrsChild.json') === false;
    $taken  = brs_finishChild($child);

    brs_check($inTime === true, 'a parent and its child were saved after the parent table was copied and before the child table was');
    if (brs_check(($taken['success'] ?? false) === true, 'the snapshot finished', $taken === null ? substr($child['errors'] . $child['seen'], 0, 300) : brs_say($taken)) === false) {
        return;
    }
    $hasParent = str_contains((string) file_get_contents(brs_tableFile($dir, 'tblAaBrsParent')), 'brs-parent-saved-mid-snapshot');
    $hasChild  = str_contains((string) file_get_contents(brs_tableFile($dir, 'tblZzBrsChild')), 'brs-child-saved-mid-snapshot');
    brs_check(
        $hasParent === $hasChild,
        'the snapshot holds both halves of that record or neither (it holds ' . ($hasParent !== $hasChild ? 'ONE HALF ONLY' : ($hasParent === true ? 'both' : 'neither')) . ')',
        'parent row in the snapshot: ' . var_export($hasParent, true) . '; child row in the snapshot: ' . var_export($hasChild, true)
        . '. A child without its parent can never be put back.'
    );
    $rehearsal = $backup->restoreAll($dir, true);
    brs_check($rehearsal['success'] === true, 'and the rehearsal confirms that snapshot can be put back', brs_say($rehearsal));
}

/** 12 — prune() keeps finished snapshots, and never removes a fresh unfinished folder or one it did not make. */
function brs_proof12(): void
{
    $root   = Brs::$root . '/p12';
    $backup = brs_backup('p12');
    $now    = time();
    $make   = static function (string $name, ?string $createdAt, int $changedSecondsAgo, string $manifestText = '') use ($root, $now): void {
        mkdir($root . '/' . $name);
        file_put_contents($root . '/' . $name . '/tblUsers.jsonl', '{"format":2,"table":"tblUsers","columns":["userID"],"base64":[]}' . "\n" . '{"end":true,"rows":0}' . "\n");
        if ($createdAt !== null) {
            file_put_contents($root . '/' . $name . '/_manifest.json', json_encode([
                'format' => 2, 'created_at' => $createdAt, 'reason' => 'made up for proof 12', 'time_zone' => '+00:00',
                'tables' => ['tblUsers' => ['columns' => ['userID'], 'rows' => 0, 'sha256' => '', 'file' => 'tblUsers.jsonl']],
            ]));
        } elseif ($manifestText !== '') {
            file_put_contents($root . '/' . $name . '/' . ($manifestText === 'tmp' ? '_manifest.json.tmp' : '_manifest.json'), '{"format":2,"tab');
        }
        foreach (array_merge(glob($root . '/' . $name . '/*') ?: [], [$root . '/' . $name]) as $path) {
            touch($path, $now - $changedSecondsAgo);
        }
    };
    $folders = static function () use ($root): array {
        return array_map('basename', glob($root . '/*', GLOB_ONLYDIR) ?: []);
    };

    // Two finished snapshots, and four NEWER unfinished folders: three that
    // changed a minute ago and one untouched for 49 hours.
    $make('upgrade-20261001-090000', '2026-10-01T09:00:00+00:00', 600000);
    $make('upgrade-20261002-090000', '2026-10-02T09:00:00+00:00', 500000);
    $make('upgrade-20261010-070001', null, 60);
    $make('upgrade-20261010-070002', null, 60, 'half a manifest');
    $make('upgrade-20261010-070003', null, 60, 'tmp');
    $make('upgrade-20261010-070004', null, 49 * 3600);
    $make('kept-by-hand', null, 49 * 3600);
    // A FINISHED snapshot in a folder a person named (a copy they kept), older
    // than both real ones, and an old unfinished folder whose name is a real
    // snapshot name followed by a line break. Neither is the portal's own.
    $make('kept-by-hand-finished', '2026-09-01T09:00:00+00:00', 700000);
    $make("upgrade-20261010-070005\n", null, 49 * 3600);

    $list = [];
    foreach ($backup->listSnapshots() as $entry) {
        $list[$entry['name']] = $entry['complete'] ?? 'no "complete" in the list';
    }
    brs_check(
        count($list) === 9 && array_keys($list, true, true) === ['upgrade-20261002-090000', 'upgrade-20261001-090000', 'kept-by-hand-finished'],
        'the list shows all nine folders and marks only the three with a whole manifest as finished (a folder with half a manifest is listed, not hidden)',
        brs_say($list)
    );
    brs_check((brs_ask($backup, 'newestFinished')['name'] ?? '') === 'upgrade-20261002-090000', 'the newest finished snapshot is the 2 October one', brs_say(brs_ask($backup, 'newestFinished')));
    brs_check(brs_ask($backup, 'unfinishedCount') === 4, 'four unfinished snapshot folders are counted (the hand-made folder is not one, nor is the name ending in a line break)', 'counted ' . brs_say(brs_ask($backup, 'unfinishedCount')));

    $outcome = $backup->prune(4);
    $left    = $folders();
    brs_check(
        in_array('upgrade-20261001-090000', $left, true) === true && in_array('upgrade-20261002-090000', $left, true) === true,
        'prune(4) keeps BOTH finished snapshots (it used to delete them and keep the unfinished ones)',
        'left: ' . implode(', ', $left) . ' — ' . brs_say($outcome)
    );
    brs_check(
        count(array_intersect($left, ['upgrade-20261010-070001', 'upgrade-20261010-070002', 'upgrade-20261010-070003'])) === 3,
        'the three unfinished folders that changed a minute ago are left alone (one may be being written right now)',
        'left: ' . implode(', ', $left)
    );
    brs_check(in_array('upgrade-20261010-070004', $left, true) === false, 'the unfinished folder untouched for 49 hours is removed', 'left: ' . implode(', ', $left));
    brs_check(in_array('kept-by-hand', $left, true) === true, 'a folder the portal did not make is never removed, however old', 'left: ' . implode(', ', $left));
    brs_check(
        in_array("upgrade-20261010-070005\n", $left, true) === true,
        'an old unfinished folder whose name is a snapshot name FOLLOWED BY A LINE BREAK is not the portal\'s own, and is left alone',
        'left: ' . brs_say($left)
    );

    $outcome = $backup->prune(1);
    $left    = $folders();
    brs_check(
        in_array('upgrade-20261002-090000', $left, true) === true && in_array('upgrade-20261001-090000', $left, true) === false,
        'prune(1) keeps the newer finished snapshot and removes the older',
        'left: ' . implode(', ', $left)
    );
    brs_check(
        in_array('kept-by-hand-finished', $left, true) === true && ($outcome['kept'] ?? null) === 1 && ($outcome['pruned'] ?? null) === 1,
        'a FINISHED snapshot in a folder the portal did not name is neither removed nor counted among those kept (kept 1, removed 1)',
        'left: ' . implode(', ', $left) . ' — ' . brs_say($outcome)
    );

    // The night the clocks go back: the folder named 01:10 was made AFTER the
    // one named 01:50, and is the one to keep.
    $make('upgrade-20261025-015000', '2026-10-25T01:50:00+01:00', 400000);
    $make('upgrade-20261025-011000', '2026-10-25T01:10:00+00:00', 400000);
    $backup->prune(1);
    $left = $folders();
    brs_check(
        in_array('upgrade-20261025-011000', $left, true) === true && in_array('upgrade-20261025-015000', $left, true) === false,
        'on the night the clocks go back, "newest" goes by the moment each snapshot was taken, not by its folder name',
        'left: ' . implode(', ', $left)
    );
}

/** 13 — rows pointing at each other with the strict kind of link (a parent may not be deleted while a child points at it). */
function brs_proof13(): void
{
    brs_sql(
        "CREATE TABLE tblZzBrsStrict (id INT PRIMARY KEY, parentID INT NULL, label VARCHAR(60) NOT NULL, "
        . "updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, "
        . "CONSTRAINT fk_brs_strict FOREIGN KEY (parentID) REFERENCES tblZzBrsStrict (id)) ENGINE=InnoDB",
        "INSERT INTO tblZzBrsStrict (id, parentID, label, updatedAt) VALUES (1, NULL, 'parent', '2020-01-02 03:04:05'), "
        . "(2, NULL, 'child of 1', '2020-01-02 03:04:05'), (3, NULL, 'points forward at 4', '2020-01-02 03:04:05'), (4, NULL, 'pointed at by 3', '2020-01-02 03:04:05')",
        "UPDATE tblZzBrsStrict SET parentID = 1, updatedAt = updatedAt WHERE id = 2",
        "UPDATE tblZzBrsStrict SET parentID = 4, updatedAt = updatedAt WHERE id = 3"
    );
    $before = brs_fingerprint();
    $backup = brs_backup('p13');
    $taken  = $backup->snapshot('proof 13');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    brs_sql("UPDATE tblZzBrsStrict SET label = 'changed after the snapshot' WHERE id = 4");

    $restored = $backup->restoreAll($taken['directory'], false);
    brs_check($restored['success'] === true, 'the snapshot is put back although the table cannot simply be emptied row by row', brs_say($restored));
    brs_checkSame($before, 'every value of every table is as it was');
}

/** 14 — an unknown column type is refused by name; every known type comes back identical. */
function brs_proof14(): void
{
    $backup = brs_backup('p14');
    $root   = Brs::$root . '/p14';

    foreach (['tblZzBrsFloat' => ['ratio', 'FLOAT', 'float'], 'tblZzBrsBit' => ['flags', 'BIT(8)', 'bit']] as $table => [$column, $sqlType, $type]) {
        brs_sql('CREATE TABLE `' . $table . '` (id INT PRIMARY KEY, `' . $column . '` ' . $sqlType . ') ENGINE=InnoDB');
        $refused = $backup->snapshot('proof 14');
        brs_check(
            $refused['success'] === false
            && str_contains((string) ($refused['error'] ?? ''), $table . '.' . $column)
            && str_contains((string) ($refused['error'] ?? ''), '"' . $type . '"'),
            'a snapshot is refused when a table has a ' . $sqlType . ' column, and the message names ' . $table . '.' . $column,
            brs_say($refused)
        );
        brs_check((glob($root . '/*') ?: []) === [], 'and no folder is left behind', implode(', ', glob($root . '/*') ?: []));
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $leftover) {
            brs_removeFolder($leftover);
        }
        brs_sql('DROP TABLE `' . $table . '`');
    }

    // One column of every type on the class's two lists, with awkward values.
    brs_sql(
        "CREATE TABLE tblZzBrsTypes (id INT PRIMARY KEY, c_tinyint TINYINT, c_smallint SMALLINT, c_mediumint MEDIUMINT, "
        . "c_bigint BIGINT UNSIGNED, c_decimal DECIMAL(30,10), c_double DOUBLE, c_date DATE, c_datetime DATETIME(6), "
        . "c_timestamp TIMESTAMP NULL, c_time TIME(6), c_year YEAR, c_char CHAR(5), c_varchar VARCHAR(40) CHARACTER SET latin1, "
        . "c_tinytext TINYTEXT, c_text TEXT, c_mediumtext MEDIUMTEXT, c_longtext LONGTEXT, c_enum ENUM('x','y'), "
        . "c_set SET('a','b','c'), c_json JSON, c_binary BINARY(4), c_varbinary VARBINARY(16), c_tinyblob TINYBLOB, "
        . "c_blob BLOB, c_mediumblob MEDIUMBLOB, c_longblob LONGBLOB, c_geometry GEOMETRY, c_point POINT) ENGINE=InnoDB",
        "INSERT INTO tblZzBrsTypes VALUES (1, -128, 32767, -8388608, 18446744073709551615, 12345678901234567890.0123456789, "
        . "0.1, '2026-10-25', '2026-10-25 01:30:00.123456', '2026-10-25 00:30:00', '-838:59:58.999999', 2026, 'ab', 'éèü', "
        . "'tiny', CONCAT('line1', CHAR(10), 'tab', CHAR(9), 'zero', CHAR(0), 'sep', CONVERT(UNHEX('E280A8') USING utf8mb4), '🍂', CHAR(92), CHAR(34)), "
        . "'medium', 'long', 'y', 'a,c', '{\"a\": [1, 2.5, \"é🍂\"], \"b\": null}', UNHEX('00FF1080'), UNHEX('FFFE00'), "
        . "UNHEX('00'), UNHEX('00FF10800A0D'), UNHEX('FF'), UNHEX('0000'), ST_GeomFromText('LINESTRING(0 0, 1 1)'), POINT(1.5, -2))",
        "INSERT INTO tblZzBrsTypes (id, c_double, c_decimal, c_varchar, c_text, c_binary, c_blob) VALUES "
        . "(2, 1e308, -0.0000000001, '', '', UNHEX('00000000'), ''), (3, 5e-324, 0, NULL, NULL, NULL, NULL), (4, 0.3333333333333333, 1, 'x', 'y', UNHEX('41420000'), UNHEX('41'))"
    );
    $inTable = array_map('strval', array_column(Brs::$reader->query(
        "SELECT DISTINCT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblZzBrsTypes'"
    )->fetch_all(), 0));
    $lists = defined(DbBackup::class . '::TEXT_TYPES') === true ? array_merge(DbBackup::TEXT_TYPES, DbBackup::BYTE_TYPES) : [];
    sort($inTable);
    sort($lists);
    brs_check(
        $inTable === $lists,
        'the scratch table has one column of EVERY type on the class\'s two lists, and of no other (' . count($lists) . ' types)',
        'on the lists but not proved here: ' . implode(', ', array_diff($lists, $inTable)) . '; here but not on the lists: ' . implode(', ', array_diff($inTable, $lists))
    );
    // A copy the backup does not touch (no "tbl" prefix), to compare the
    // DOUBLE column inside the database, where the comparison is exact.
    brs_sql('CREATE TABLE brs_types_copy AS SELECT id, c_double FROM tblZzBrsTypes');
    $before = brs_fingerprint();

    $taken = $backup->snapshot('proof 14');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    brs_sql('DELETE FROM tblZzBrsTypes WHERE id = 2', "UPDATE tblZzBrsTypes SET c_double = 7, c_blob = UNHEX('01'), c_text = 'changed'");
    $restored = $backup->restoreAll($taken['directory'], false);
    brs_check($restored['success'] === true, 'the snapshot is put back', brs_say($restored));
    brs_checkSame($before, 'every value of every type is as it was');
    $unequal = (int) Brs::$reader->query(
        'SELECT COUNT(*) FROM brs_types_copy c LEFT JOIN tblZzBrsTypes t ON t.id = c.id WHERE NOT (t.c_double <=> c.c_double) OR t.id IS NULL'
    )->fetch_row()[0];
    brs_check($unequal === 0, 'the DOUBLE values are exactly equal, compared inside the database', $unequal . ' row(s) differ');
}

/** 16 — a link added AFTER the snapshot was taken, and a pointer at a row that is gone. */
function brs_proof16(): void
{
    $backup = brs_backup('p16');

    // --- The half that must WORK: the link's own rule is "empty the pointer".
    //     Three sessions as they could stand before migration 210: one for a
    //     live event, one for an event deleted long ago (nothing stopped that
    //     then), one for no event.
    brs_sessionLink(false);
    brs_sql(
        "INSERT INTO tblEvents (eventID, siteID, eventName, eventSlug, startDateTime, timezone) VALUES (970501, 1, 'A live event', 'brs-live', '2026-11-01 10:00:00', 'UTC')",
        "INSERT INTO tblAttendanceServiceTypes (serviceTypeID, typeName, typeSlug) VALUES (970001, 'Self-test service', 'brs-service')",
        "INSERT INTO tblAttendanceSessions (sessionID, siteID, serviceTypeID, eventID, sessionDate, createdAt, updatedAt) VALUES "
        . "(970001, 1, 970001, 970501, '2026-11-01', '2020-01-02 03:04:05', '2020-01-02 03:04:05'), "
        . "(970002, 1, 970001, 970777, '2026-11-08', '2020-01-02 03:04:05', '2020-01-02 03:04:05'), "
        . "(970003, 1, 970001, NULL,   '2026-11-15', '2020-01-02 03:04:05', '2020-01-02 03:04:05')"
    );
    $taken = $backup->snapshot('proof 16, before the link');
    if (brs_check($taken['success'] === true, 'a snapshot is taken BEFORE the link exists, holding a session whose event is gone', brs_say($taken)) === false) {
        return;
    }

    // What migration 210 does: empty the pointers at events that are gone
    // (holding "last changed" still), then add the link.
    brs_sql('UPDATE `tblAttendanceSessions` SET `eventID` = NULL, `updatedAt` = `updatedAt` WHERE `eventID` IS NOT NULL '
          . 'AND NOT EXISTS (SELECT 1 FROM `tblEvents` WHERE `tblEvents`.`eventID` = `tblAttendanceSessions`.`eventID`)');
    brs_sessionLink(true);
    // The database as the migration left it is exactly what the restore
    // should bring back: the dead pointer empty, everything else as saved.
    $afterMigration = brs_fingerprint();

    brs_sql("UPDATE tblAttendanceSessions SET notes = 'changed after the snapshot' WHERE sessionID = 970001", 'DELETE FROM tblAttendanceSessions WHERE sessionID = 970003');
    $damaged = brs_fingerprint();

    $rehearsal = $backup->restoreAll($taken['directory'], true);
    brs_check($rehearsal['success'] === true, 'the rehearsal says the snapshot can be put back', brs_say($rehearsal));
    brs_check(
        ($rehearsal['pointers_emptied'] ?? null) === ['tblAttendanceSessions.eventID' => 1],
        'and reports that one pointer would be emptied, naming tblAttendanceSessions.eventID',
        brs_say($rehearsal['pointers_emptied'] ?? 'no count in the result')
    );
    brs_checkSame($damaged, 'the rehearsal changed nothing');

    $restored = $backup->restoreAll($taken['directory'], false);
    brs_check($restored['success'] === true, 'the snapshot is put back although the link was added after it was taken', brs_say($restored));
    brs_check(
        ($restored['pointers_emptied'] ?? null) === ['tblAttendanceSessions.eventID' => 1]
        && str_contains(implode(' ', $restored['warnings'] ?? []), 'tblAttendanceSessions.eventID (1)'),
        'the restore reports the same count, and says so in words',
        brs_say($restored)
    );
    $rows = Brs::$reader->query('SELECT sessionID, eventID, updatedAt FROM tblAttendanceSessions WHERE sessionID >= ' . BRS_ID . ' ORDER BY sessionID')->fetch_all();
    brs_check(
        $rows === [['970001', '970501', '2020-01-02 03:04:05'], ['970002', null, '2020-01-02 03:04:05'], ['970003', null, '2020-01-02 03:04:05']],
        'the live pointer is kept, the dead one is empty, and "last changed" is untouched on all three sessions',
        brs_say($rows)
    );
    brs_checkSame($afterMigration, 'every other value of every table is as it was saved');

    // --- The half that must be REFUSED: a link with any other rule.
    //     The pointer column MAY be empty here, on purpose, so the only thing
    //     standing in the way of emptying it is the link's own rule.
    brs_sql(
        'CREATE TABLE tblAaBrsParent (id INT PRIMARY KEY, label VARCHAR(60) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE tblZzBrsChild (id INT PRIMARY KEY, parentID INT NULL, label VARCHAR(60) NOT NULL, KEY idx_brs_child (parentID)) ENGINE=InnoDB',
        "INSERT INTO tblAaBrsParent VALUES (1, 'the only parent')",
        "INSERT INTO tblZzBrsChild VALUES (1, 1, 'has a parent'), (2, 424242, 'points at a parent that is gone')",
        // The same for a table that points at ITSELF (used at the end).
        'CREATE TABLE tblZzBrsStrict (id INT PRIMARY KEY, parentID INT NULL, label VARCHAR(60) NOT NULL, KEY idx_brs_strict (parentID)) ENGINE=InnoDB',
        "INSERT INTO tblZzBrsStrict VALUES (1, NULL, 'a row'), (2, 1, 'points at row 1'), (3, 515151, 'points at a row of its own table that is gone')"
    );
    $taken = $backup->snapshot('proof 16, a strict link');
    if (brs_check($taken['success'] === true, 'a second snapshot is taken, holding a scratch row whose parent is gone', brs_say($taken)) === false) {
        return;
    }
    brs_sql('DELETE FROM tblZzBrsChild WHERE id = 2');
    foreach (['' => 'the ordinary rule (refuse)', ' ON DELETE CASCADE' => 'the rule "delete the child too"'] as $rule => $words) {
        brs_sql('ALTER TABLE tblZzBrsChild ADD CONSTRAINT fk_brs_child FOREIGN KEY (parentID) REFERENCES tblAaBrsParent (id)' . $rule);
        $state = brs_fingerprint();
        foreach ([true => 'the rehearsal', false => 'the restore'] as $dryRun => $who) {
            $result = $backup->restoreAll($taken['directory'], (bool) $dryRun);
            brs_check(
                $result['success'] === false
                && str_contains((string) $result['error'], 'tblZzBrsChild')
                && str_contains((string) $result['error'], 'parentID')
                && str_contains((string) $result['error'], '424242'),
                $who . ' REFUSES when the link added since has ' . $words . ', naming the table, the column and the missing row',
                brs_say($result)
            );
        }
        brs_checkSame($state, 'and the database is exactly as it was');
        brs_sql('ALTER TABLE tblZzBrsChild DROP FOREIGN KEY fk_brs_child');
    }

    // --- The same two halves for a table pointing at ITSELF, whose pointers
    //     are filled in by a separate step of the restore.
    brs_sql('DELETE FROM tblZzBrsStrict WHERE id = 3', 'ALTER TABLE tblZzBrsStrict ADD CONSTRAINT fk_brs_strict FOREIGN KEY (parentID) REFERENCES tblZzBrsStrict (id)');
    $state  = brs_fingerprint();
    $result = $backup->restoreAll($taken['directory'], false);
    brs_check(
        $result['success'] === false && str_contains((string) $result['error'], 'tblZzBrsStrict') && str_contains((string) $result['error'], '515151'),
        'a pointer at a missing row of the SAME table is refused when that link has the ordinary rule',
        brs_say($result)
    );
    brs_checkSame($state, 'and the database is exactly as it was');
    brs_sql(
        'ALTER TABLE tblZzBrsStrict DROP FOREIGN KEY fk_brs_strict',
        'ALTER TABLE tblZzBrsStrict ADD CONSTRAINT fk_brs_strict FOREIGN KEY (parentID) REFERENCES tblZzBrsStrict (id) ON DELETE SET NULL'
    );
    // Putting that ONE table back must refuse the dead pointer even now that
    // the link's rule is "empty the pointer": only a whole restore, which
    // counts and reports what it empties, may do that.
    $state  = brs_fingerprint();
    $result = $backup->restoreTable($taken['directory'], 'tblZzBrsStrict');
    brs_check(
        $result['success'] === false && str_contains((string) ($result['error'] ?? ''), 'left exactly as it was'),
        'putting that ONE table back refuses the dead pointer, although the link\'s rule is "empty the pointer"',
        brs_say($result)
    );
    brs_checkSame($state, 'and the table is exactly as it was');
    $result = $backup->restoreAll($taken['directory'], false);
    $rows   = Brs::$reader->query('SELECT id, parentID FROM tblZzBrsStrict ORDER BY id')->fetch_all();
    brs_check(
        $result['success'] === true
        && ($result['pointers_emptied'] ?? null) === ['tblZzBrsStrict.parentID' => 1]
        && $rows === [['1', null], ['2', '1'], ['3', null]],
        'and is emptied and counted when that link says "empty the pointer" (the live pointer beside it is kept)',
        brs_say($result) . ' rows: ' . brs_say($rows)
    );

    // --- A link of TWO columns is never emptied, whatever its rule. The dead
    //     pair (7, 7) is chosen so that its FIRST column matches no parent
    //     either: code that treated the link as a link of one column would
    //     empty that column, and the database would then accept the row.
    brs_sql(
        'CREATE TABLE tblAaBrsPairParent (a INT NOT NULL, b INT NOT NULL, PRIMARY KEY (a, b)) ENGINE=InnoDB',
        'CREATE TABLE tblZzBrsPair (id INT PRIMARY KEY, a INT NULL, b INT NULL, KEY idx_brs_pair (a, b)) ENGINE=InnoDB',
        'INSERT INTO tblAaBrsPairParent VALUES (1, 1)',
        'INSERT INTO tblZzBrsPair VALUES (1, 1, 1), (2, 7, 7)'
    );
    $taken = $backup->snapshot('proof 16, a link of two columns');
    if (brs_check($taken['success'] === true, 'a third snapshot is taken, holding a row whose PAIR of pointers matches no row', brs_say($taken)) === false) {
        return;
    }
    brs_sql(
        'DELETE FROM tblZzBrsPair WHERE id = 2',
        'ALTER TABLE tblZzBrsPair ADD CONSTRAINT fk_brs_pair FOREIGN KEY (a, b) REFERENCES tblAaBrsPairParent (a, b) ON DELETE SET NULL'
    );
    $state = brs_fingerprint();
    foreach ([true => 'the rehearsal', false => 'the restore'] as $dryRun => $who) {
        $result = $backup->restoreAll($taken['directory'], (bool) $dryRun);
        brs_check(
            $result['success'] === false && ($result['pointers_emptied'] ?? null) === [] && str_contains((string) $result['error'], 'tblZzBrsPair') === true,
            $who . ' REFUSES a dead pointer made of two columns, although that link\'s rule is "empty the pointer": nothing is guessed about half a pointer',
            brs_say($result)
        );
    }
    brs_checkSame($state, 'and the database is exactly as it was');
}

/** 17 — putting ONE table back. */
function brs_proof17(): void
{
    brs_addSelfPointingRows();
    // A setting somebody edited, so the snapshot holds one. (Its leftover twin
    // used to be planted in the file here; that case moved to tblRoutes below
    // when tblSettings stopped being allowed on its own.)
    brs_sql("INSERT INTO tblSettings (settingID, siteID, settingKey, settingValue, defaultValue, isSensitive, updatedAt) "
          . "VALUES (970010, NULL, 'brs.selftest.setting', 'edited by an administrator', 'the default', 0, '2026-01-02 00:00:00')");
    $before = brs_fingerprint();
    $backup = brs_backup('p17');

    $taken = $backup->snapshot('proof 17');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    $old = Brs::$root . '/p17/upgrade-20260901-120000';
    brs_legacySnapshot(brs_connect(), $old);

    foreach (['the new form' => $taken['directory'], 'the old form' => $old] as $form => $dir) {
        brs_damageSelfPointingRows();
        $result = $backup->restoreTable($dir, 'tblTasks');
        brs_check($result['success'] === true, 'tblTasks alone is put back from ' . $form . ', self-pointing rows included', brs_say($result));
        $tasks = brs_fingerprint();
        brs_check(($tasks['tblTasks'] ?? '') === $before['tblTasks'], 'and tblTasks is exactly as it was', 'tblTasks differs');

        $result = $backup->restoreTable($dir, 'tblVenues');
        brs_check(
            $result['success'] === false && str_contains((string) ($result['error'] ?? ''), 'other tables point at it'),
            'a table other tables point at is still refused on its own (' . $form . ')',
            brs_say($result)
        );
        $restored = $backup->restoreAll($dir, false);
        brs_check($restored['success'] === true, 'the whole of ' . $form . ' snapshot then restores', brs_say($restored));
        brs_checkSame($before, 'every value of every table is as it was');
    }

    // tblSettings is no longer allowed on its own (see proof 26), so this
    // duplicate-row case USED to be done on it and had to move. The table now
    // used, tblRoutes, is the same shape of problem: a unique key on one
    // column (routeKey) that a snapshot taken before that key existed can
    // break twice over.
    $answer = $backup->restoreTable($taken['directory'], 'tblSettings');
    brs_check(
        $answer['success'] === false && str_contains((string) ($answer['error'] ?? ''), 'cannot be put back on its own') === true,
        'tblSettings on its own is refused now (proof 26 has the whole story; this check is here so proof 17 cannot quietly start passing again if the guard goes)',
        brs_say($answer)
    );

    // A snapshot from before a "no two rows the same" rule holds a duplicate.
    // It must be left out and counted, and the row that was already in the
    // table must be the one that survives.
    $copy  = Brs::$root . '/p17/with-a-duplicate-route';
    brs_copyFolder($taken['directory'], $copy);
    $file  = $copy . '/tblRoutes.jsonl';
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $head  = json_decode($lines[0], true);
    $at    = array_flip($head['columns']);
    $first = null;
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row) === true && array_is_list($row) === true) {
            $first = $row;
            break;
        }
    }
    if (brs_check($first !== null, 'the snapshot file holds at least one address') === false) {
        return;
    }
    // The twin: a different identity number, the SAME routeKey. Added at the
    // END of the file, so the real row is offered first and the twin is the
    // one the database turns away.
    $twin = $first;
    $twin[$at['routeID']] = '970020';
    array_pop($lines);                       // the file's own "end" line
    $lines[] = json_encode($twin, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $lines[] = json_encode(['end' => true, 'rows' => count($lines) - 1]);
    $text    = implode("\n", $lines) . "\n";
    file_put_contents($file, $text);
    brs_remanifest($copy, 'tblRoutes', hash('sha256', $text), count($lines) - 2);

    brs_sql("UPDATE tblRoutes SET targetFile = 'changed/after/the/snapshot.php' WHERE routeID = " . (int) $first[$at['routeID']]);
    $result = $backup->restoreTable($copy, 'tblRoutes');
    brs_check(
        $result['success'] === true && ($result['skipped_duplicates'] ?? 0) === 1,
        'a table is put back with one duplicate row left out and counted',
        brs_say($result)
    );
    brs_checkSame($before, 'and the row that survived is the one the snapshot really held: every value of every table is as it was');

    // A single-table restore that fails must leave the table as it was.
    $bad = Brs::$root . '/p17/planted-bad-task';
    brs_copyFolder($taken['directory'], $bad);
    $file   = $bad . '/tblTasks.jsonl';
    $edited = str_replace('"Newer task"', '"Newer task","more values than columns"', (string) file_get_contents($file));
    file_put_contents($file, $edited);
    brs_remanifest($bad, 'tblTasks', hash('sha256', $edited));
    brs_damageSelfPointingRows();
    $damaged = brs_fingerprint();
    $result  = $backup->restoreTable($bad, 'tblTasks');
    brs_check($result['success'] === false, 'a single-table restore from a file with a row that cannot be read is refused', brs_say($result));
    brs_checkSame($damaged, 'and nothing was touched');
}

/** 18 — a restore never says yes while changing a value, whatever the host's own strictness. */
function brs_proof18(): void
{
    $backup = brs_backup('p18');
    $loose  = static function (): mysqli {
        // As some shared hosts run: nothing is refused, values are "made to fit".
        $db = brs_connect('-07:00');
        $db->query("SET SESSION sql_mode = ''");
        return $db;
    };
    $quiet  = static function () use ($loose): mysqli {
        // The same, with the database's NOTES switched off as well. Rounding a
        // decimal and cutting spaces off the end of a text are reported only
        // as notes, and a session set like this does not count them: a
        // restore on one used to say yes and bring back the changed value.
        $db = $loose();
        $db->query('SET SESSION sql_notes = 0');
        return $db;
    };

    // --- Columns changed AFTER the snapshot was taken, as a migration does.
    //     Each case: the changes, and the column whose saved value no longer fits.
    $cases = [
        'a text column made shorter' => ['a', ['UPDATE tblZzBrsDrift SET a = LEFT(a, 5)', 'ALTER TABLE tblZzBrsDrift MODIFY a VARCHAR(5) NULL']],
        'a number column made smaller' => ['b', ['UPDATE tblZzBrsDrift SET b = 1', 'ALTER TABLE tblZzBrsDrift MODIFY b SMALLINT NULL']],
        'a column that may no longer be empty' => ['a', ["UPDATE tblZzBrsDrift SET a = 'x' WHERE a IS NULL", 'ALTER TABLE tblZzBrsDrift MODIFY a VARCHAR(50) NOT NULL']],
        'a column turned into a list of choices' => ['a', ["UPDATE tblZzBrsDrift SET a = 'short'", "ALTER TABLE tblZzBrsDrift MODIFY a ENUM('short','other') NULL"]],
        'a decimal column given fewer places (the database only NOTES that it rounded)' => ['c', ['UPDATE tblZzBrsDrift SET c = ROUND(c, 1)', 'ALTER TABLE tblZzBrsDrift MODIFY c DECIMAL(12,1) NULL']],
        'a text column made shorter than the spaces at the end of a value (the database only NOTES that it cut them)' => ['d', ['UPDATE tblZzBrsDrift SET d = RTRIM(d)', 'ALTER TABLE tblZzBrsDrift MODIFY d VARCHAR(5) NULL']],
    ];
    foreach ($cases as $what => [$column, $changes]) {
        brs_sql(
            'DROP TABLE IF EXISTS tblZzBrsDrift',
            'CREATE TABLE tblZzBrsDrift (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, a VARCHAR(50) NULL, b INT NULL, c DECIMAL(12,4) NULL, d VARCHAR(20) NULL) ENGINE=InnoDB',
            "INSERT INTO tblZzBrsDrift (a, b, c, d) VALUES ('a long value that needs all its room', 70000, 1.2345, 'abc     '), (NULL, NULL, NULL, NULL), ('short', -1, 2.5, 'x')"
        );
        brs_waitFor(static fn (): bool => is_dir(Brs::$root . '/p18/upgrade-' . date('Ymd-His')) === false, 3);
        $taken = $backup->snapshot('proof 18');
        if (brs_check($taken['success'] === true, $what . ': a snapshot is taken first', brs_say($taken)) === false) {
            return;
        }
        brs_sql(...$changes);
        $live = brs_fingerprint();

        $kinds = ['a connection whose sql_mode is empty' => $loose];
        if (str_contains($what, 'only NOTES') === true) {
            $kinds['a connection that has the database\'s notes switched off as well (sql_notes = 0)'] = $quiet;
        }
        $runs = [
            'the rehearsal'         => static fn (mysqli $db): array => brs_backup('p18', $db)->restoreAll($taken['directory'], true),
            'the restore'           => static fn (mysqli $db): array => brs_backup('p18', $db)->restoreAll($taken['directory'], false),
            'the one-table restore' => static fn (mysqli $db): array => brs_backup('p18', $db)->restoreTable($taken['directory'], 'tblZzBrsDrift'),
        ];
        foreach ($kinds as $kind => $connect) {
            foreach ($runs as $who => $run) {
                $db     = $connect();
                $answer = $run($db);
                brs_check(
                    $answer['success'] === false
                    && str_contains((string) $answer['error'], "'" . $column . "'") === true
                    && str_contains((string) $answer['error'], 'never knowingly puts back a changed value') === true,
                    $what . ': ' . $who . ' is REFUSED on ' . $kind . ', and the reason names the column',
                    brs_say($answer)
                );
                if ($connect === $quiet) {
                    $now = $db->query('SELECT @@session.sql_notes, @@session.sql_mode')->fetch_row();
                    brs_check($now === ['0', ''], 'and afterwards that connection still has its notes switched off and its sql_mode empty: both were put back', brs_say($now));
                }
            }
        }
        brs_checkSame($live, $what . ': and the database is exactly as it was after every one of them');
    }
    brs_sql('DROP TABLE IF EXISTS tblZzBrsDrift');

    // --- A change the database notes ONLY WHEN A POINTER IS FILLED IN. A row
    //     points at another row of its own table by a text key, and its
    //     pointer was saved with spaces at its end (the database treats
    //     'abc' and 'abc     ' as the same key in this kind of column). A
    //     later migration made the pointer column shorter. Each row first
    //     goes in with its pointer EMPTY, which raises nothing; the note
    //     comes when the pointer is filled in afterwards, and that second
    //     step must refuse on a note exactly as the first does.
    brs_sql(
        'CREATE TABLE tblZzBrsSpaces (code VARCHAR(8) NOT NULL PRIMARY KEY, parentCode VARCHAR(8) NULL, '
        . 'CONSTRAINT fk_brs_spaces FOREIGN KEY (parentCode) REFERENCES tblZzBrsSpaces (code) ON DELETE SET NULL) '
        . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        "INSERT INTO tblZzBrsSpaces VALUES ('abc', NULL)",
        "INSERT INTO tblZzBrsSpaces VALUES ('def', 'abc     ')"
    );
    brs_waitFor(static fn (): bool => is_dir(Brs::$root . '/p18/upgrade-' . date('Ymd-His')) === false, 3);
    $taken = $backup->snapshot('proof 18, a pointer saved with spaces at its end');
    brs_sql(
        'ALTER TABLE tblZzBrsSpaces DROP FOREIGN KEY fk_brs_spaces',
        'UPDATE tblZzBrsSpaces SET parentCode = RTRIM(parentCode)',
        'ALTER TABLE tblZzBrsSpaces MODIFY parentCode VARCHAR(5) NULL',
        'ALTER TABLE tblZzBrsSpaces ADD CONSTRAINT fk_brs_spaces FOREIGN KEY (parentCode) REFERENCES tblZzBrsSpaces (code) ON DELETE SET NULL'
    );
    $live    = brs_fingerprint();
    $answers = [
        'the rehearsal'         => brs_backup('p18')->restoreAll((string) $taken['directory'], true),
        'the restore'           => brs_backup('p18')->restoreAll((string) $taken['directory'], false),
        'the one-table restore' => brs_backup('p18')->restoreTable((string) $taken['directory'], 'tblZzBrsSpaces'),
    ];
    foreach ($answers as $who => $answer) {
        brs_check(
            $taken['success'] === true && $answer['success'] === false
            && str_contains((string) $answer['error'], 'Could not fill in a pointer in tblZzBrsSpaces') === true
            && str_contains((string) $answer['error'], "'parentCode'") === true
            && str_contains((string) $answer['error'], 'never knowingly puts back a changed value') === true,
            'a pointer whose value the database would cut short, noted ONLY when the pointer is filled in: ' . $who . ' is REFUSED, naming the column',
            brs_say($answer)
        );
    }
    brs_checkSame($live, 'and the database is exactly as it was after all three');
    brs_sql('DROP TABLE tblZzBrsSpaces');

    // --- Values a strict server refuses but a loose one may already hold:
    //     dates of zero, a date that does not exist, a row numbered 0. They
    //     were in the database, so they must go back exactly as they were.
    brs_sql('CREATE TABLE tblZzBrsZero (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, d DATE NULL, dt DATETIME NULL, ts TIMESTAMP NULL, label VARCHAR(30) NOT NULL) ENGINE=InnoDB');
    $modeWas = (string) Brs::$writer->query('SELECT @@session.sql_mode')->fetch_row()[0];
    brs_sql(
        "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO,ALLOW_INVALID_DATES'",
        "INSERT INTO tblZzBrsZero (id, d, dt, ts, label) VALUES (0, '0000-00-00', '0000-00-00 00:00:00', '0000-00-00 00:00:00', 'the row numbered 0'), "
        . "(7, '2026-00-00', '2026-02-31 10:00:00', NULL, 'a month of zero, 31 February')",
        "SET SESSION sql_mode = '" . $modeWas . "'"
    );
    $rows = Brs::$reader->query('SELECT id, d, dt, ts FROM tblZzBrsZero ORDER BY id')->fetch_all();
    brs_check(
        $rows === [['0', '0000-00-00', '0000-00-00 00:00:00', '0000-00-00 00:00:00'], ['7', '2026-00-00', '2026-02-31 10:00:00', null]],
        'a row numbered 0, dates of zero and a date that does not exist are really in the database',
        brs_say($rows)
    );
    $before = brs_fingerprint();
    brs_waitFor(static fn (): bool => is_dir(Brs::$root . '/p18/upgrade-' . date('Ymd-His')) === false, 3);
    $taken  = $backup->snapshot('proof 18, zero dates');
    if (brs_check($taken['success'] === true, 'a snapshot holding them is taken', brs_say($taken)) === false) {
        return;
    }
    foreach (['set as the server sets it (strict)' => brs_connect('-07:00'), 'whose sql_mode is empty' => $loose()] as $kind => $db) {
        foreach (['the whole restore' => false, 'the one-table restore' => true] as $who => $oneTable) {
            brs_sql("UPDATE tblZzBrsZero SET label = 'changed after the snapshot'", 'DELETE FROM tblZzBrsZero WHERE id = 7');
            $settings = brs_sessionSettings($db);
            $answer   = $oneTable === true
                ? brs_backup('p18', $db)->restoreTable($taken['directory'], 'tblZzBrsZero')
                : brs_backup('p18', $db)->restoreAll($taken['directory'], false);
            brs_check($answer['success'] === true, $who . ' puts them back on a connection ' . $kind, brs_say($answer));
            brs_checkSame($before, 'every value is as it was: the row is still numbered 0 and the dates are the same dates');
            brs_check(
                brs_sessionSettings($db) === $settings,
                'and that connection\'s own settings (sql_mode, time zone and every other) are exactly as they were before',
                brs_say(array_diff_assoc(brs_sessionSettings($db), $settings))
            );
        }
    }

    // --- The connection's settings after a restore that FAILS, and after a rehearsal.
    $bad = Brs::$root . '/p18/planted-bad-row';
    brs_copyFolder($taken['directory'], $bad);
    $file   = $bad . '/tblZzBrsZero.jsonl';
    $edited = str_replace('"the row numbered 0"', '"a label far longer than the thirty letters this column holds"', (string) file_get_contents($file), $changed);
    file_put_contents($file, $edited);
    brs_remanifest($bad, 'tblZzBrsZero', hash('sha256', $edited));
    $state = brs_fingerprint();
    foreach (['a restore that fails' => [$bad, false], 'a one-table restore that fails' => [$bad, null], 'a rehearsal' => [$taken['directory'], true]] as $who => [$dir, $dryRun]) {
        $db       = $loose();
        $settings = brs_sessionSettings($db);
        $answer   = $dryRun === null ? brs_backup('p18', $db)->restoreTable($dir, 'tblZzBrsZero') : brs_backup('p18', $db)->restoreAll($dir, $dryRun);
        brs_check($changed === 1 && $answer['success'] === ($dryRun === true), $who . ' ends as it should', brs_say($answer));
        brs_check(
            brs_sessionSettings($db) === $settings && (string) $db->query('SELECT @@session.sql_mode')->fetch_row()[0] === '',
            'after ' . $who . ' the connection\'s own settings are exactly as they were (sql_mode still empty, time zone still -07:00)',
            brs_say(array_diff_assoc(brs_sessionSettings($db), $settings))
        );
    }
    brs_checkSame($state, 'and the database is exactly as it was after all three');
    brs_sql('DROP TABLE tblZzBrsZero');

    // --- WHAT STRICTNESS COSTS, proved so that nobody is surprised by it: a
    //     value the database kept only because it was loose (a text not on a
    //     column's list, stored as an empty text) is REFUSED, with the reason,
    //     because no setting lets it be put back unchanged.
    brs_sql(
        "CREATE TABLE tblZzBrsChoice (id INT PRIMARY KEY, e ENUM('a','b') NULL) ENGINE=InnoDB",
        "SET SESSION sql_mode = ''",
        "INSERT INTO tblZzBrsChoice VALUES (1, 'a'), (2, 'not on the list')",
        "SET SESSION sql_mode = '" . $modeWas . "'"
    );
    brs_waitFor(static fn (): bool => is_dir(Brs::$root . '/p18/upgrade-' . date('Ymd-His')) === false, 3);
    $taken = $backup->snapshot('proof 18, a value not on a list');
    $state = brs_fingerprint();
    $answer = brs_backup('p18', $loose())->restoreAll((string) $taken['directory'], true);
    brs_check(
        $taken['success'] === true && $answer['success'] === false && str_contains((string) $answer['error'], "'e'") === true
        && str_contains((string) $answer['error'], 'first saved by a database set to accept anything') === true,
        'a value the database kept only because it was loose (not on its column\'s list) is refused by the rehearsal, with the reason, never altered',
        brs_say($answer)
    );
    brs_checkSame($state, 'and the database is exactly as it was');
}

/** 19 — names and folders. */
function brs_proof19(): void
{
    brs_addSelfPointingRows();
    $root   = Brs::$root . '/p19';
    $backup = brs_backup('p19');
    $taken  = $backup->snapshot('proof 19');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    $good = basename($taken['directory']);
    brs_damageSelfPointingRows();
    $damaged = brs_fingerprint();

    // --- A snapshot that is NOT in the backups folder is not opened, however it is named.
    $outside = Brs::$root . '/p19-outside';
    mkdir($outside, 0750);
    brs_copyFolder($taken['directory'], $outside . '/a-good-snapshot');
    symlink($outside . '/a-good-snapshot', $root . '/link-to-a-snapshot-outside');
    $ways = [
        'its real path'                        => $outside . '/a-good-snapshot',
        'a path that climbs out of the folder' => $root . '/' . $good . '/../../p19-outside/a-good-snapshot',
        'a link inside the folder'             => $root . '/link-to-a-snapshot-outside',
    ];
    foreach ($ways as $how => $path) {
        $answers = [$backup->restoreAll($path, true), $backup->restoreAll($path, false), $backup->restoreTable($path, 'tblTasks')];
        $refused = 0;
        foreach ($answers as $answer) {
            $refused += $answer['success'] === false && str_contains((string) $answer['error'], 'not in the backups folder') === true ? 1 : 0;
        }
        brs_check($refused === 3, 'a good snapshot OUTSIDE the backups folder, reached by ' . $how . ', is refused by the rehearsal, the restore and the one-table restore', brs_say($answers));
    }
    brs_checkSame($damaged, 'and nothing in the database changed');
    // The page's Inspect reads a manifest through manifestOf(): the same door.
    $viaLink = $backup->manifestOf('link-to-a-snapshot-outside');
    $viaName = $backup->manifestOf($good);
    brs_check(
        is_string($viaLink) === true && str_contains($viaLink, 'not in the backups folder') === true
        && is_array($viaName) === true && isset($viaName['tables']['tblTasks']) === true,
        'manifestOf(), which Inspect uses, refuses the link that leads outside and opens the real snapshot',
        brs_say([$viaLink, is_array($viaName) === true ? 'a manifest' : $viaName])
    );

    // --- The one test for a name sent by a browser.
    $odd = ['..', '.', '../', '../../x', $outside . '/a-good-snapshot', '%2e%2e%2fx', '..\\x', $good . "\0x", $good . "\n", $good . ' ',
            'aaa-note.txt', '', ['x' => 'y'], null, 12, $good . '/'];
    $let = [];
    foreach ($odd as $name) {
        if (DbBackup::isSnapshotName($name) === true) {
            $let[] = brs_say($name);
        }
    }
    brs_check($let === [], count($odd) . ' odd names (dots, paths, a zero byte, a line break at the end, a list, a number, nothing) all fail the name test', 'let through: ' . implode(', ', $let));
    brs_check(
        DbBackup::isSnapshotName($good) === true && DbBackup::isSnapshotName('kept-by-hand_2') === true,
        'and the portal\'s own names, and a plain name a person chose, pass it'
    );
    // The same for a TABLE name, which goes into SQL and into a file name.
    $let = [];
    foreach (["tblTasks\n", 'tblTasks ', 'tbl-Tasks', '../tblTasks', 'tblTasks;', '1tblTasks', '', ['tblTasks'], null] as $name) {
        if (DbBackup::isTableName($name) === true) {
            $let[] = brs_say($name);
        }
    }
    brs_check(
        $let === [] && DbBackup::isTableName('tblTasks') === true,
        'a table name ending in a line break, and eight other odd ones, fail the table-name test; a real table name passes',
        'let through: ' . implode(', ', $let)
    );
    $answer = $backup->restoreTable($taken['directory'], "tblTasks\n");
    brs_check($answer['success'] === false, 'and putting back a table named with a line break at the end is refused by the class itself', brs_say($answer));
    brs_checkSame($damaged, 'with nothing changed');

    // --- Delete removes one folder inside the backups folder and nothing else.
    file_put_contents($outside . '/precious.txt', 'must survive');
    symlink($outside, $root . '/link-to-outside');
    file_put_contents($root . '/.alert-state', 'a file the logger keeps here');
    file_put_contents($root . '/loose-note', 'a person\'s note');
    mkdir($root . '/upgrade-20200101-000000', 0750);
    file_put_contents($root . '/upgrade-20200101-000000/tblUsers.jsonl', "{}\n");
    symlink($outside, $root . '/upgrade-20200101-000000/link-inside');
    $outsideHolds = static fn (): array => array_values(array_diff((array) scandir($outside), ['.', '..']));
    $heldBefore   = $outsideHolds();

    brs_check($backup->deleteSnapshot('.') === false && $backup->deleteSnapshot('..') === false, 'Delete refuses the names "." and ".."');
    brs_check($backup->deleteSnapshot('loose-note') === false && is_file($root . '/loose-note') === true, 'Delete will not remove a loose file');
    brs_check(is_file($root . '/.alert-state') === true, 'the logger\'s file in the backups folder is still there');
    brs_check(
        $backup->deleteSnapshot('link-to-outside') === true && file_exists($root . '/link-to-outside') === false && is_link($root . '/link-to-outside') === false,
        'Delete of a LINK removes the link'
    );
    brs_check($outsideHolds() === $heldBefore && is_file($outside . '/precious.txt') === true, 'and every file in the folder it pointed at, outside the backups folder, is still there', brs_say($outsideHolds()));
    brs_check(
        $backup->deleteSnapshot('upgrade-20200101-000000') === true && is_dir($root . '/upgrade-20200101-000000') === false && $outsideHolds() === $heldBefore,
        'Delete of a folder holding a link removes the folder and the link, and still nothing outside'
    );
    brs_check(is_dir($root . '/' . $good) === true, 'and the good snapshot beside them is untouched');

    // --- A cell the safety net carried as bytes is turned back. Reached the
    //     only way it can be: a connection that is NOT utf8mb4 (here `binary`)
    //     reading a column kept in latin1, whose bytes are not valid UTF-8.
    brs_sql(
        'CREATE TABLE tblZzBrsLatin (id INT PRIMARY KEY, v VARCHAR(20) CHARACTER SET latin1 NOT NULL) ENGINE=InnoDB',
        "INSERT INTO tblZzBrsLatin VALUES (1, 'café'), (2, 'plain')"
    );
    $before = brs_fingerprint();
    $binary = brs_backup('p19-binary', brs_connect('', mysqli::class, 'binary'));
    $taken  = $binary->snapshot('proof 19, bytes that are not text');
    if (brs_check($taken['success'] === true, 'a snapshot is taken over a connection that hands text over as raw bytes', brs_say($taken)) === false) {
        return;
    }
    brs_check(
        str_contains((string) file_get_contents($taken['directory'] . '/tblZzBrsLatin.jsonl'), '{"b64":"' . base64_encode("caf\xE9") . '"}') === true,
        'the one cell that is not valid text was carried as bytes, marked',
        (string) file_get_contents($taken['directory'] . '/tblZzBrsLatin.jsonl')
    );
    brs_sql("UPDATE tblZzBrsLatin SET v = 'changed'");
    $answer = $binary->restoreTable($taken['directory'], 'tblZzBrsLatin');
    brs_check($answer['success'] === true, 'that table is put back over the same kind of connection', brs_say($answer));
    brs_checkSame($before, 'and the cell is the text it was (the marked bytes were turned back)');

    // --- A folder name that is already taken: the night the clocks go back,
    //     when 01:30:00 comes round twice. The names for this second and the
    //     next are taken first, as if by snapshots made an hour ago.
    $now      = time();
    $occupied = [$root . '/upgrade-' . date('Ymd-His', $now), $root . '/upgrade-' . date('Ymd-His', $now + 1)];
    foreach ($occupied as $dir) {
        if (is_dir($dir) === false) {
            mkdir($dir, 0750);
        }
    }
    $taken    = $backup->snapshot('proof 19, a name already taken');
    $empty    = count(scandir($occupied[0])) === 2 && count(scandir($occupied[1])) === 2;
    brs_check(
        $taken['success'] === true && in_array($taken['directory'], $occupied, true) === false
        && preg_match('/\Aupgrade-\d{8}-\d{6}\z/', basename((string) $taken['directory'])) === 1 && $empty === true,
        'a snapshot whose folder name is already taken waits for the next free second, keeps the same form of name, and leaves the folders that were there untouched',
        brs_say($taken)
    );
}

/** 20 — a large row that the database accepts by itself. */
function brs_proof20(): void
{
    $packet = (int) Brs::$writer->query('SELECT @@session.max_allowed_packet')->fetch_row()[0];
    // Sizes follow the server's own limit, so the proof means the same on any
    // server: fifteen rows that together stay under a quarter of the limit
    // (so none of them is sent yet), then one row that fits by itself but not
    // together with them.
    $small = intdiv($packet, 64);
    $large = intdiv($packet, 32) * 25;
    brs_check($packet >= 16 * 1048576 && 15 * $small + $large > $packet && $large < $packet, 'the server accepts messages of ' . round($packet / 1048576) . ' MB: fifteen rows of ' . round($small / 1048576, 1) . ' MB and one of ' . round($large / 1048576) . ' MB fit one at a time and not together');
    brs_sql('CREATE TABLE tblZzBrsBig (id INT PRIMARY KEY, body LONGBLOB NOT NULL) ENGINE=InnoDB');

    // --- FIRST, the other side of the limit: a row LARGER than the largest
    //     message the database takes is refused IN WORDS, BEFORE IT IS SENT.
    //     (It used to be sent. The database answers that by ending the
    //     connection, so all anyone saw was "MySQL server has gone away".)
    //     A row really that large would be 64 MB or more, so instead the
    //     connection is made to SAY its limit is 200,000 bytes (BrsOddDb) and
    //     the row is 300,000. The real database would take that row, which is
    //     the point: had the class sent it, these restores would succeed.
    brs_sql("INSERT INTO tblZzBrsBig VALUES (1, REPEAT('a', 1000)), (2, REPEAT('b', 300000))");
    $limited = brs_backup('p20-limit');
    $taken   = $limited->snapshot('proof 20, a row larger than the limit');
    brs_sql("UPDATE tblZzBrsBig SET body = 'changed after the snapshot' WHERE id = 1");
    // The independent reader cannot sort values this long, so this one table
    // is compared by length and checksum (as the rest of this proof does).
    $bigNow = static fn (): array => Brs::$reader->query('SELECT id, LENGTH(body), SHA2(body, 256) FROM tblZzBrsBig ORDER BY id')->fetch_all();
    $state  = [brs_fingerprint(['tblZzBrsBig']), $bigNow()];
    BrsOddDb::$packetSays = 200000;
    try {
        foreach (['the rehearsal' => true, 'the restore' => false, 'the one-table restore' => null] as $who => $dryRun) {
            $db     = brs_connect('', BrsOddDb::class);
            $answer = $dryRun === null
                ? brs_backup('p20-limit', $db)->restoreTable((string) $taken['directory'], 'tblZzBrsBig')
                : brs_backup('p20-limit', $db)->restoreAll((string) $taken['directory'], $dryRun);
            $open   = false;
            try {
                $open = (string) $db->query('SELECT 1')->fetch_row()[0] === '1';
            } catch (Throwable $e) {
                $open = false;
            }
            brs_check(
                $taken['success'] === true && $answer['success'] === false
                && str_contains((string) $answer['error'], 'tblZzBrsBig') === true
                && str_contains((string) $answer['error'], 'max_allowed_packet') === true
                && str_contains((string) $answer['error'], 'so the row was not sent') === true
                && str_contains((string) $answer['error'], 'What to change') === true,
                'a row larger than the largest message the database takes: ' . $who . ' is REFUSED before the row is sent, naming the table, the setting and what to change',
                brs_say($answer)
            );
            brs_check(
                ($answer['connection_closed'] ?? null) === false && $open === true,
                'and the connection is still open: the ordinary undo ran, so a page can put the portal back as it found it',
                brs_say($answer['connection_closed'] ?? 'no such key')
            );
        }
    } finally {
        BrsOddDb::$packetSays = 0;
    }
    brs_check(
        brs_differences($state[0], brs_fingerprint(['tblZzBrsBig'])) === '' && $bigNow() === $state[1],
        'and the database is exactly as it was after all three',
        brs_differences($state[0], brs_fingerprint(['tblZzBrsBig'])) . ' ' . brs_say(array_column($bigNow(), 1, 0))
    );
    $answer = $limited->restoreTable((string) $taken['directory'], 'tblZzBrsBig');
    brs_check($answer['success'] === true, 'on a connection that reports its true limit the very same snapshot goes back, so it was the measuring that refused it', brs_say($answer));
    brs_sql('DELETE FROM tblZzBrsBig');
    for ($i = 1; $i <= 15; $i++) {
        Brs::$writer->query('INSERT INTO tblZzBrsBig VALUES (' . $i . ", REPEAT('" . chr(96 + $i) . "', " . $small . '))');
    }
    Brs::$writer->query("INSERT INTO tblZzBrsBig VALUES (16, REPEAT('z', " . $large . '))');
    // The independent reader cannot sort or hex values this size, so this one
    // table is compared by length and checksum, worked out by the database.
    $sums   = static fn (): array => Brs::$reader->query('SELECT id, LENGTH(body), SHA2(body, 256) FROM tblZzBrsBig ORDER BY id')->fetch_all();
    $before = $sums();
    $rest   = brs_fingerprint(['tblZzBrsBig']);

    $root  = Brs::$root . '/p20';
    mkdir($root, 0750, true);
    // One row is held several times over while it is read and written (see
    // the class's header), so the three jobs get plenty of memory.
    $room  = ['-d', 'memory_limit=' . max(1024, (int) ceil($large / 1048576) * 10) . 'M'];
    $said  = static fn (array $child, ?array $result): string => $result === null ? 'no result. It printed: ' . substr(trim($child['errors'] . $child['seen']), 0, 400) : brs_say($result);

    $child = brs_startChild('snapshot', [$root], $room);
    $taken = brs_finishChild($child);
    if (brs_check(($taken['success'] ?? false) === true, 'a snapshot of the sixteen rows is taken', $said($child, $taken)) === false) {
        return;
    }
    $name = basename((string) $taken['directory']);
    brs_sql('DELETE FROM tblZzBrsBig WHERE id IN (3, 16)');

    $child     = brs_startChild('check', [$root, $name], $room);
    $rehearsal = brs_finishChild($child);
    brs_check(($rehearsal['success'] ?? false) === true, 'the rehearsal says it can be put back (the large row is sent by itself, after the rows that were waiting)', $said($child, $rehearsal));
    $child    = brs_startChild('restore', [$root, $name], $room);
    $restored = brs_finishChild($child);
    brs_check(($restored['success'] ?? false) === true, 'and the restore puts it back', $said($child, $restored));
    brs_check($sums() === $before, 'all sixteen rows are back, each the same length and with the same checksum', brs_say(array_column($sums(), 1, 0)));
    $now = brs_fingerprint(['tblZzBrsBig']);
    brs_check(brs_differences($rest, $now) === '', 'and every value of every other table is as it was', brs_differences($rest, $now));
}

/** 21 — a table the database cannot undo changes to is refused before anything is touched. */
function brs_proof21(): void
{
    brs_addSelfPointingRows();
    brs_sql(
        'CREATE TABLE tblZzBrsMyisam (id INT PRIMARY KEY, v VARCHAR(60) NOT NULL) ENGINE=MyISAM',
        "INSERT INTO tblZzBrsMyisam VALUES (1, 'saved'), (2, 'also saved')"
    );
    $engine = (string) Brs::$reader->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblZzBrsMyisam'")->fetch_row()[0];
    if (brs_check($engine === 'MyISAM', 'a scratch table kept by the MyISAM engine exists (the database cannot undo a change to one)', 'its engine is ' . $engine) === false) {
        return;
    }
    $backup = brs_backup('p21');
    $taken  = $backup->snapshot('proof 21');
    if (brs_check($taken['success'] === true, 'a snapshot is taken (reading such a table is harmless)', brs_say($taken)) === false) {
        return;
    }
    brs_damageSelfPointingRows();
    brs_sql("UPDATE tblZzBrsMyisam SET v = 'changed after the snapshot'");
    $state   = brs_fingerprint();
    $answers = [
        'the rehearsal'         => $backup->restoreAll($taken['directory'], true),
        'the restore'           => $backup->restoreAll($taken['directory'], false),
        'the one-table restore' => $backup->restoreTable($taken['directory'], 'tblZzBrsMyisam'),
    ];
    foreach ($answers as $who => $answer) {
        brs_check(
            $answer['success'] === false && str_contains((string) $answer['error'], 'tblZzBrsMyisam') === true
            && str_contains((string) $answer['error'], 'InnoDB') === true && str_contains((string) $answer['error'], 'Nothing has been changed') === true,
            $who . ' is REFUSED, naming the table and saying it must be InnoDB',
            brs_say($answer)
        );
    }
    brs_checkSame($state, 'and nothing was touched: not that table, and not one of the tables that would have been emptied before it');
}

/** 22 — tables that are not in the snapshot, and what putting it back does to them. */
function brs_proof22(): void
{
    brs_sql(
        'CREATE TABLE tblAaBrsParent (id INT PRIMARY KEY, label VARCHAR(60) NOT NULL) ENGINE=InnoDB',
        "INSERT INTO tblAaBrsParent VALUES (1, 'one'), (2, 'two'), (3, 'three')"
    );
    $backup = brs_backup('p22');
    $taken  = $backup->snapshot('proof 22');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    // Tables made AFTER the snapshot, as a later migration makes them. Three
    // point at a table that IS in it, with the three kinds of link. Two more:
    //   tblZaBrsOnward  points at one of those OUTSIDE tables, not at a table
    //                   in the snapshot, and its name sorts BEFORE that
    //                   table's. Outside tables have to be followed onward
    //                   until no new one turns up; a single pass in name
    //                   order meets this table before the one it hangs from
    //                   and leaves it out, though it loses two of its rows.
    //   tblZzBrsSelfOut points at a table in the snapshot AND at itself. When
    //                   its first row is deleted, the row that pointed at it
    //                   has that pointer emptied, which must be counted too.
    brs_sql(
        'CREATE TABLE tblZzBrsGone (id INT PRIMARY KEY, parentID INT NULL, CONSTRAINT fk_brs_gone FOREIGN KEY (parentID) REFERENCES tblAaBrsParent (id) ON DELETE CASCADE) ENGINE=InnoDB',
        'CREATE TABLE tblZzBrsLoose (id INT PRIMARY KEY, parentID INT NULL, CONSTRAINT fk_brs_loose FOREIGN KEY (parentID) REFERENCES tblAaBrsParent (id) ON DELETE SET NULL) ENGINE=InnoDB',
        'CREATE TABLE tblZzBrsKept (id INT PRIMARY KEY, label VARCHAR(60) NOT NULL) ENGINE=InnoDB',
        'INSERT INTO tblZzBrsGone VALUES (1, 1), (2, 3), (3, NULL)',
        'INSERT INTO tblZzBrsLoose VALUES (1, 1), (2, 2), (3, 3), (4, NULL)',
        "INSERT INTO tblZzBrsKept VALUES (1, 'not linked to anything')",
        'CREATE TABLE tblZaBrsOnward (id INT PRIMARY KEY, goneID INT NULL, CONSTRAINT fk_brs_onward FOREIGN KEY (goneID) REFERENCES tblZzBrsGone (id) ON DELETE CASCADE) ENGINE=InnoDB',
        'INSERT INTO tblZaBrsOnward VALUES (1, 1), (2, 2), (3, NULL), (4, 3)',
        'CREATE TABLE tblZzBrsSelfOut (id INT PRIMARY KEY, parentID INT NULL, upID INT NULL, '
        . 'CONSTRAINT fk_brs_selfout_parent FOREIGN KEY (parentID) REFERENCES tblAaBrsParent (id) ON DELETE CASCADE, '
        . 'CONSTRAINT fk_brs_selfout_up FOREIGN KEY (upID) REFERENCES tblZzBrsSelfOut (id) ON DELETE SET NULL) ENGINE=InnoDB',
        'INSERT INTO tblZzBrsSelfOut VALUES (1, 1, NULL), (2, NULL, 1), (3, NULL, 2)',
        "UPDATE tblAaBrsParent SET label = 'changed after the snapshot' WHERE id = 2"
    );
    $live   = brs_fingerprint();
    $expect = [
        'tblZaBrsOnward'  => ['rows_before' => 4, 'rows_after' => 2, 'pointers' => ['goneID' => ['before' => 3, 'after' => 1]]],
        'tblZzBrsGone'    => ['rows_before' => 3, 'rows_after' => 1, 'pointers' => ['parentID' => ['before' => 2, 'after' => 0]]],
        'tblZzBrsLoose'   => ['rows_before' => 4, 'rows_after' => 4, 'pointers' => ['parentID' => ['before' => 3, 'after' => 0]]],
        'tblZzBrsSelfOut' => ['rows_before' => 3, 'rows_after' => 2, 'pointers' => ['parentID' => ['before' => 1, 'after' => 0], 'upID' => ['before' => 2, 'after' => 1]]],
    ];
    foreach ([true => 'the rehearsal', false => 'the restore'] as $dryRun => $who) {
        $answer = $backup->restoreAll($taken['directory'], (bool) $dryRun);
        $words  = implode(' ', $answer['warnings'] ?? []);
        brs_check($answer['success'] === true, $who . ' says yes', brs_say($answer));
        brs_check(
            ($answer['other_tables_changed'] ?? null) === $expect,
            $who . ' counts what happens to the four linked tables that are NOT in the snapshot: rows gone from one, pointers emptied in another, '
            . 'two rows gone from the one reached only THROUGH an outside table, and in the one that points at itself both the row it lost and the pointer of its own that was emptied',
            brs_say($answer['other_tables_changed'] ?? 'no such count in the result')
        );
        brs_check(
            str_contains($words, 'NOT in this snapshot') === true && str_contains($words, 'tblZzBrsGone held 3 row(s)') === true
            && str_contains($words, 'in tblZzBrsLoose, 3 row(s) held a value in parentID') === true && str_contains($words, 'tblZzBrsKept') === false
            && str_contains($words, 'tblZaBrsOnward held 4 row(s)') === true
            && str_contains($words, 'in tblZzBrsSelfOut, 2 row(s) held a value in upID and 1') === true,
            'and says so in a warning that names all four tables and the numbers, and does not name the table nothing links',
            $words
        );
        if ((bool) $dryRun === true) {
            brs_checkSame($live, 'the rehearsal changed nothing, in those tables or any other');
        }
    }
    $rows = [
        Brs::$reader->query('SELECT id, parentID FROM tblZzBrsGone ORDER BY id')->fetch_all(),
        Brs::$reader->query('SELECT id, parentID FROM tblZzBrsLoose ORDER BY id')->fetch_all(),
        Brs::$reader->query('SELECT COUNT(*) FROM tblZzBrsKept')->fetch_row(),
        Brs::$reader->query('SELECT id, goneID FROM tblZaBrsOnward ORDER BY id')->fetch_all(),
        Brs::$reader->query('SELECT id, parentID, upID FROM tblZzBrsSelfOut ORDER BY id')->fetch_all(),
    ];
    brs_check(
        $rows === [[['3', null]], [['1', null], ['2', null], ['3', null], ['4', null]], ['1'], [['3', null], ['4', '3']], [['2', null, null], ['3', null, '2']]],
        'after the restore the tables hold exactly what was reported, and the table nothing links is untouched',
        brs_say($rows)
    );

    // A link whose rule is "refuse" stops the restore instead.
    brs_sql(
        'DROP TABLE tblZaBrsOnward',
        'DROP TABLE tblZzBrsGone',
        'CREATE TABLE tblZzBrsGone (id INT PRIMARY KEY, parentID INT NULL, CONSTRAINT fk_brs_gone FOREIGN KEY (parentID) REFERENCES tblAaBrsParent (id) ON DELETE RESTRICT) ENGINE=InnoDB',
        'INSERT INTO tblZzBrsGone VALUES (1, 1)'
    );
    $state  = brs_fingerprint();
    $answer = $backup->restoreAll($taken['directory'], false);
    brs_check($answer['success'] === false, 'when the outside table\'s link says "refuse", the restore is refused', brs_say($answer));
    brs_checkSame($state, 'and the database is exactly as it was');
}

/** 23 — an undo the database does not confirm. */
function brs_proof23(): void
{
    brs_addSelfPointingRows();
    $backup = brs_backup('p23');
    $taken  = $backup->snapshot('proof 23');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    $before = brs_fingerprint();
    $bad = dirname($taken['directory']) . '/planted-bad-row';
    brs_plantBadRow($taken['directory'], $bad);
    // And a copy whose tblTasks holds a title no column could take. tblTasks
    // is a table nothing else points at, so it can be put back on its own.
    $badTask = dirname($taken['directory']) . '/planted-bad-task';
    brs_copyFolder($taken['directory'], $badTask);
    $edited = str_replace('"Newer task"', '"' . str_repeat('x', 70000) . '"', (string) file_get_contents($badTask . '/tblTasks.jsonl'), $planted);
    file_put_contents($badTask . '/tblTasks.jsonl', $edited);
    brs_remanifest($badTask, 'tblTasks', hash('sha256', $edited));
    brs_check($planted === 1, 'a row the database must refuse was planted in two copies of the snapshot');
    brs_damageSelfPointingRows();
    $damaged = brs_fingerprint();
    // The database undoes a closed connection's work a moment after it closes.
    $settled = static fn (): bool => brs_waitFor(static fn (): bool => brs_differences($damaged, brs_fingerprint()) === '', 30);

    $cases = [
        'a rehearsal of a good snapshot'             => [$taken['directory'], true, null],
        'a restore that fails on a row'              => [$bad, false, null],
        'a rehearsal that fails on a row'            => [$bad, true, null],
        'a one-table restore that fails on a row'    => [$badTask, false, 'tblTasks'],
    ];
    foreach ($cases as $who => [$dir, $dryRun, $table]) {
        $db     = brs_connect('', BrsNoUndo::class);
        $class  = brs_backup('p23', $db);
        $answer = $table === null ? $class->restoreAll($dir, $dryRun) : $class->restoreTable($dir, $table);
        $closed = false;
        try {
            $db->query('SELECT 1');
        } catch (Throwable $e) {
            $closed = true;
        }
        brs_check(
            $answer['success'] === false && ($answer['connection_closed'] ?? null) === true
            && str_contains((string) $answer['error'], 'did not confirm') === true && str_contains((string) $answer['error'], 'closed') === true,
            $who . ', when the database does not confirm the undo: the result says so and says the connection was closed',
            brs_say($answer)
        );
        brs_check($closed === true, 'and the connection really is closed');
        brs_check($settled() === true, 'and the database is exactly as it was (closing the connection undid the work)', brs_differences($damaged, brs_fingerprint()));
    }

    // The ordinary case, for contrast: the undo IS confirmed, so the
    // connection stays open and usable.
    $db     = brs_connect();
    $answer = brs_backup('p23', $db)->restoreAll($bad, false);
    brs_check(
        $answer['success'] === false && $answer['connection_closed'] === false && (string) $db->query('SELECT 1')->fetch_row()[0] === '1'
        && str_contains((string) $answer['error'], 'everything has been put back as it was') === true,
        'a restore that fails on an ordinary connection confirms its undo, says "everything has been put back", and leaves the connection usable',
        brs_say($answer)
    );
    brs_checkSame($damaged, 'and the database is exactly as it was');

    // --- THE CONNECTION'S OWN SETTINGS CANNOT BE PUT BACK AFTERWARDS. A
    //     restore makes its session strict and puts it back when it ends. If
    //     the putting back fails, the connection would be handed back still
    //     strict, and whatever the page did next would run that way. So the
    //     class closes the connection and SAYS so, even when the restore
    //     itself worked. (It used to try once and say nothing.) The last case
    //     is a restore that works, so it is run last.
    $cases = [
        'a rehearsal of a good snapshot'          => [$taken['directory'], true, null, true, 'changed nothing', $damaged],
        'a restore that fails on a row'           => [$bad, false, null, false, 'everything has been put back as it was', $damaged],
        'a one-table restore that fails on a row' => [$badTask, false, 'tblTasks', false, 'left exactly as it was', $damaged],
        'a restore that WORKS'                    => [$taken['directory'], false, null, true, 'FINISHED and is permanent', $before],
    ];
    BrsOddDb::$failPutBack = true;
    try {
        foreach ($cases as $who => [$dir, $dryRun, $table, $succeeds, $alsoSays, $expected]) {
            $db     = brs_connect('', BrsOddDb::class);
            $answer = $table === null ? brs_backup('p23', $db)->restoreAll($dir, $dryRun) : brs_backup('p23', $db)->restoreTable($dir, $table);
            $closed = false;
            try {
                $db->query('SELECT 1');
            } catch (Throwable $e) {
                $closed = true;
            }
            $words = (string) $answer['error'] . ' ' . implode(' ', $answer['warnings'] ?? []) . ' ' . (string) ($answer['notice'] ?? '');
            brs_check(
                $answer['success'] === $succeeds && ($answer['connection_closed'] ?? null) === true
                && str_contains($words, 'could not be put back as they were, so the connection was closed') === true
                && str_contains($words, $alsoSays) === true,
                $who . ', when the connection\'s settings cannot be put back afterwards: the result says so, says the connection was closed, and still says truly how the work itself ended ("' . $alsoSays . '")',
                brs_say($answer)
            );
            brs_check($closed === true, 'and the connection really is closed, so nothing can run on it in the restore\'s own strictness');
            brs_check(
                brs_waitFor(static fn (): bool => brs_differences($expected, brs_fingerprint()) === '', 30) === true,
                $succeeds === true && $dryRun === false ? 'and every value of every table is as it was when the snapshot was taken: the restore did finish' : 'and the database is exactly as it was',
                brs_differences($expected, brs_fingerprint())
            );
        }
    } finally {
        BrsOddDb::$failPutBack = false;
    }
}

/** 24 — a value that is not on its column's list of choices. */
function brs_proof24(): void
{
    // As some shared hosts run: nothing is refused, values are "made to fit".
    $loose = static function (): mysqli {
        $db = brs_connect();
        $db->query("SET SESSION sql_mode = ''");
        return $db;
    };
    $newFolder = static fn (): bool => brs_waitFor(static fn (): bool => is_dir(Brs::$root . '/p24/upgrade-' . date('Ymd-His')) === false, 3);
    $manifest  = static fn (array $taken): array => (array) json_decode((string) file_get_contents($taken['directory'] . '/_manifest.json'), true);

    // Two tables with lists of choices. `f` has an EMPTY text as one of its
    // choices, and `s` is a SET: an empty text in either is a proper value,
    // and must never be counted.
    brs_sql(
        "CREATE TABLE tblZzBrsChoice (id INT PRIMARY KEY, e ENUM('a','b') NULL, f ENUM('','x') NULL, s SET('a','b') NULL) ENGINE=InnoDB",
        "CREATE TABLE tblZzBrsChoiceB (id INT PRIMARY KEY, kind ENUM('one','two') NOT NULL DEFAULT 'one') ENGINE=InnoDB",
        "INSERT INTO tblZzBrsChoice VALUES (1, 'a', '', ''), (2, 'b', 'x', 'a,b'), (3, NULL, NULL, NULL)",
        "INSERT INTO tblZzBrsChoiceB VALUES (1, 'one'), (2, 'two')"
    );

    // --- A database with NO such value: nothing is said.
    $class = brs_backup('p24', $loose());
    $clean = $class->snapshot('proof 24, nothing to report');
    brs_check(
        $clean['success'] === true && ($clean['warnings'] ?? null) === [] && ($clean['unlisted_values'] ?? null) === [],
        'a database with no such value: the snapshot is taken with no warning (an empty text that IS one of a column\'s choices, and an empty SET, are not counted)',
        brs_say($clean)
    );
    brs_check(($manifest($clean)['unlisted_values'] ?? null) === [], 'and its manifest records that it looked and found none', brs_say($manifest($clean)['unlisted_values'] ?? 'no such key'));

    // --- Two such values in one table and one in another, saved the only way
    //     they can be: by a connection set to accept anything.
    $writer = $loose();
    $writer->query("INSERT INTO tblZzBrsChoice VALUES (4, 'not on the list', 'x', 'a'), (5, 'nor is this', '', 'zzz')");
    $writer->query("INSERT INTO tblZzBrsChoiceB VALUES (3, 'three')");
    $stored = Brs::$reader->query('SELECT (SELECT COUNT(*) FROM tblZzBrsChoice WHERE e = 0), (SELECT COUNT(*) FROM tblZzBrsChoiceB WHERE kind = 0)')->fetch_row();
    if (brs_check($stored === ['2', '1'], 'three values that are not on their column\'s list are really in the database, in two tables', brs_say($stored)) === false) {
        return;
    }
    $found = [
        ['table' => 'tblZzBrsChoice', 'column' => 'e', 'cells' => 2],
        ['table' => 'tblZzBrsChoiceB', 'column' => 'kind', 'cells' => 1],
    ];
    $newFolder();
    $taken = $class->snapshot('proof 24, three values not on their lists');
    brs_check(
        $taken['success'] === true && ($taken['unlisted_values'] ?? null) === $found,
        'the snapshot is STILL TAKEN, and its result names each table and column and how many: tblZzBrsChoice.e 2, tblZzBrsChoiceB.kind 1 (and not the columns f or s)',
        brs_say($taken)
    );
    $words = (string) ($taken['warnings'][0] ?? '');
    brs_check(
        str_contains($words, 'CANNOT BE PUT BACK WHOLE') === true && str_contains($words, 'holds 3 value(s)') === true
        && str_contains($words, 'tblZzBrsChoice.e (2), tblZzBrsChoiceB.kind (1)') === true
        && str_contains($words, 'mend those rows in the live database') === true && str_contains($words, 'take a NEW snapshot') === true
        && str_contains($words, 'SELECT * FROM `tblZzBrsChoice` WHERE `e` = 0;') === true,
        'its warning says it cannot be put back whole, which values, and exactly what to do, with the statement that finds them',
        $words === '' ? 'no warning at all: ' . brs_say($taken) : $words
    );
    brs_check(($manifest($taken)['unlisted_values'] ?? null) === $found, 'the manifest holds the same list', brs_say($manifest($taken)['unlisted_values'] ?? 'no such key'));
    $newest = brs_ask($class, 'newestFinished');
    brs_check(
        is_array($newest) === true && ($newest['unlisted_values'] ?? null) === $found && DbBackup::unlistedWarning($newest['unlisted_values']) === $words,
        'and so does the newest finished snapshot as the health page reads it, giving the same sentence',
        brs_say($newest)
    );
    // The statement in the warning really does find the rows.
    $rows = Brs::$reader->query('SELECT id FROM `tblZzBrsChoice` WHERE `e` = 0 ORDER BY id')->fetch_all();
    brs_check($rows === [['4'], ['5']], 'the statement the warning gives finds exactly those rows', brs_say($rows));

    // The warning is TRUE: this snapshot cannot be put back whole, by anyone.
    $state = brs_fingerprint();
    foreach (['the rehearsal' => true, 'the restore' => false] as $who => $dryRun) {
        $answer = brs_backup('p24', $loose())->restoreAll((string) $taken['directory'], $dryRun);
        brs_check($answer['success'] === false && str_contains((string) $answer['error'], "'e'") === true, 'and the warning is true: ' . $who . ' of that snapshot is refused', brs_say($answer));
    }
    brs_checkSame($state, 'with the database exactly as it was');

    // A manifest is a file a person can edit: nothing odd in it reaches a page.
    $odd = ['unlisted_values' => [['table' => 'tblX; DROP', 'column' => 'e', 'cells' => 1], ['table' => 'tblX', 'column' => 'e', 'cells' => '<b>2</b>'],
                                  ['table' => 'tblX', 'column' => 'e', 'cells' => 0], 'text', ['table' => 'tblGood', 'column' => 'kind', 'cells' => 7]]];
    brs_check(
        DbBackup::unlistedIn($odd) === [['table' => 'tblGood', 'column' => 'kind', 'cells' => 7]] && DbBackup::unlistedIn([]) === [] && DbBackup::unlistedWarning([]) === '',
        'a manifest edited by hand: an entry not in the proper form is dropped, and a manifest with no such list (an older snapshot) gives no warning',
        brs_say(DbBackup::unlistedIn($odd))
    );

    // --- What the warning says to do: mend the live rows, take a NEW snapshot.
    brs_sql('UPDATE tblZzBrsChoice SET e = NULL WHERE e = 0', "UPDATE tblZzBrsChoiceB SET kind = 'one' WHERE kind = 0");
    $mended = brs_fingerprint();
    $newFolder();
    $again  = $class->snapshot('proof 24, after the rows were mended');
    brs_check(
        $again['success'] === true && ($again['warnings'] ?? null) === [] && ($manifest($again)['unlisted_values'] ?? null) === [],
        'after the rows are mended, a new snapshot carries no warning',
        brs_say($again)
    );
    brs_sql("UPDATE tblZzBrsChoice SET e = 'b', s = 'a'", 'DELETE FROM tblZzBrsChoiceB WHERE id > 1');
    $answer = brs_backup('p24', $loose())->restoreAll((string) $again['directory'], false);
    brs_check($answer['success'] === true, 'and it is put back, on a connection whose sql_mode is empty', brs_say($answer));
    brs_checkSame($mended, 'with every value of every table as it was when that snapshot was taken (the empty choice, the empty SET and the emptied cells included)');
}

// =============================================================================
// 🚦 The run
// =============================================================================

// Everything above is also used by tools/backup-page-selftest.php, which loads
// this file for its functions. The run below happens only when THIS file is
// the script being run.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

$options = getopt('', ['only::', 'rows::', 'memory::', 'write-samples', 'child::'], $restIndex);

if (str_starts_with(brs_env('SELFTEST_DB_NAME', ''), 'selftest_') === false) {
    echo "REFUSED. Set SELFTEST_DB_NAME to a throwaway database whose name starts with selftest_\n";
    echo "(got '" . brs_env('SELFTEST_DB_NAME', '') . "'). This test adds rows, damages them and restores over every table,\n";
    echo "so it will only run against a database made for it. Nothing was run, and nothing is proved.\n";
    exit(2);
}

if (isset($options['child']) === true) {
    brs_runChild((string) $options['child'], array_slice($argv, $restIndex));
}

try {
    Brs::$writer = brs_connect();
    Brs::$reader = brs_connect('+00:00');
} catch (Throwable $e) {
    echo "REFUSED. Could not connect to the test database: " . $e->getMessage() . "\n";
    echo "Nothing was run, and nothing is proved.\n";
    exit(2);
}
Brs::$bigRows = isset($options['rows']) === true ? max(1000, (int) $options['rows']) : 60000;
Brs::$memory  = isset($options['memory']) === true ? (string) $options['memory'] : '32M';
Brs::$root    = rtrim(sys_get_temp_dir(), '/') . '/backup-restore-selftest-' . getmypid();
/** Run a migration file exactly as it is written, on the writer's connection. Throws on the first error. */
function brs_runMigration(string $file): void
{
    $writer = Brs::$writer;
    $writer->multi_query((string) file_get_contents(dirname(__DIR__) . '/web/_sql/' . $file));
    do {
        $result = $writer->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($writer->more_results() === true && $writer->next_result() === true);
}

/**
 * 25 — a snapshot taken BEFORE migration 217 goes back onto a database that
 * has run it (#604).
 *
 * Migration 217 removes a column (`tblEvents.eventTimezone`) and moves an
 * organisation's time zone out of a setting and into its own row. A snapshot
 * taken the day before still holds a value of the removed column for every
 * event, the old setting, and none of the migration's own traces. Put back
 * onto the upgraded database it used to be REFUSED ("Unknown column"), which
 * is no way back at the very moment one is needed. This proves the whole
 * journey: the snapshot goes back; every event keeps exactly what its own
 * `timezone` held, and the removed column's values are let go (an event whose
 * zone box had been emptied stays empty: the removed column held only its
 * default for it, and neither the restore nor the migration copies that in);
 * and when the upgrade then runs 217 again (its record went with the restore),
 * the organisation ends on the zone ITS pages were showing when the snapshot
 * was taken, not on a zone chosen since.
 *
 * It changes the shape of a table on the way, so it takes a snapshot of the
 * starting state first and puts THAT back at the end, whatever happened.
 */
function brs_proof25(): void
{
    $backup = brs_backup('p25');
    $start  = $backup->snapshot('proof 25, the starting state');
    if (brs_check($start['success'] === true, 'the starting state is saved, to be put back at the end', brs_say($start)) === false) {
        return;
    }
    $hasOldColumn = static fn (): bool => (int) Brs::$writer->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEvents' AND COLUMN_NAME = 'eventTimezone'"
    )->fetch_row()[0] === 1;
    $zoneOf = static fn (int $event): string => (string) (Brs::$writer->query('SELECT timezone FROM tblEvents WHERE eventID = ' . $event)->fetch_row()[0] ?? '(no such event)');
    $org    = static fn (): string => (string) (Brs::$writer->query('SELECT timezone FROM tblSites WHERE siteID = 1')->fetch_row()[0] ?? '(empty)');
    $count  = static fn (string $where): int => (int) Brs::$writer->query('SELECT COUNT(*) FROM tblSettings WHERE ' . $where)->fetch_row()[0];

    try {
        // 1. An installation that has NOT run migration 217: the second zone
        //    column is there, the zone setting is there (and differs from the
        //    organisation's row), and the migration has left no trace.
        brs_sql(
            "ALTER TABLE tblEvents ADD COLUMN eventTimezone VARCHAR(64) NOT NULL DEFAULT 'Europe/London' COMMENT 'IANA timezone for event display (#238)'",
            "ALTER TABLE tblEvents MODIFY COLUMN timezone VARCHAR(50) NOT NULL DEFAULT 'Europe/London'",
            "DELETE FROM tblMigrations WHERE filename = '217_one_time_zone_answer.sql'",
            "DELETE FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt'",
            "INSERT INTO tblSettings (settingID, siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (970901, NULL, 'site.timezone', 'Pacific/Auckland', 'UTC', 0)",
            "UPDATE tblSites SET timezone = 'UTC' WHERE siteID = 1",
            "INSERT INTO tblEvents (eventID, siteID, eventName, eventSlug, startDateTime, timezone, eventTimezone) VALUES "
            . "(970801, 1, 'The two columns agree', 'brs-zone-same', '2026-12-01 10:00:00', 'America/New_York', 'America/New_York'), "
            . "(970802, 1, 'Made in the portal', 'brs-zone-portal', '2026-12-02 10:00:00', 'Pacific/Auckland', 'Europe/London'), "
            . "(970803, 1, 'The zone box emptied on the old form', 'brs-zone-empty', '2026-12-03 10:00:00', '', 'Europe/London')"
        );
        $older = $backup->snapshot('proof 25, before migration 217');
        if (brs_check($older['success'] === true, 'a snapshot is taken on a database that has not run migration 217', brs_say($older)) === false) {
            return;
        }

        // 2. The upgrade.
        brs_runMigration('217_one_time_zone_answer.sql');
        brs_check(
            $hasOldColumn() === false && $org() === 'Pacific/Auckland' && $zoneOf(970803) === '',
            'migration 217 runs: the second column is gone, the organisation\'s row holds the zone its pages were showing, and the event whose zone box was emptied is left empty',
            'old column there: ' . json_encode($hasOldColumn()) . '; organisation: ' . $org() . '; the third event: ' . $zoneOf(970803)
        );

        // 3. Life goes on: a zone is chosen at Admin, Organisations, and events change.
        brs_sql("UPDATE tblSites SET timezone = 'Asia/Tokyo' WHERE siteID = 1", "UPDATE tblEvents SET timezone = 'UTC' WHERE eventID IN (970801, 970802, 970803)");

        // 4. The older snapshot is put back onto the upgraded database.
        $restored = $backup->restoreAll($older['directory'], false);
        brs_check($restored['success'] === true, 'the snapshot taken before the migration is put back onto the database that has run it (it used to be refused: "Unknown column")', brs_say($restored));
        brs_check(
            $zoneOf(970801) === 'America/New_York' && $zoneOf(970802) === 'Pacific/Auckland' && $zoneOf(970803) === '' && $hasOldColumn() === false,
            'every event has exactly what its own `timezone` held in the snapshot, the empty one included (the removed column\'s "Europe/London" is NOT put into it); the removed column is not brought back',
            json_encode([$zoneOf(970801), $zoneOf(970802), $zoneOf(970803)]) . '; old column there: ' . json_encode($hasOldColumn())
        );
        brs_check(
            $org() === 'UTC' && $count("settingKey = 'site.timezone' AND settingValue = 'Pacific/Auckland'") === 1 && $count("settingKey = 'site.timezoneKeptAt'") === 0,
            'the organisation\'s row, the old setting and the missing mark are all as the snapshot held them: a database that has not been through the migration',
            'organisation: ' . $org() . '; old setting rows: ' . $count("settingKey = 'site.timezone'") . '; mark rows: ' . $count("settingKey = 'site.timezoneKeptAt'")
        );

        // 5. The upgrade runs the migration again: its record went with the restore.
        brs_runMigration('217_one_time_zone_answer.sql');
        brs_check(
            $org() === 'Pacific/Auckland' && $count("settingKey = 'site.timezone'") === 0 && $count("settingKey = 'site.timezoneKeptAt' AND settingValue = 'organisation'") === 1,
            'and run again, the migration brings the restored organisation into line: the zone its pages were showing when the snapshot was taken, not the one chosen afterwards',
            'organisation: ' . $org() . '; old setting rows: ' . $count("settingKey = 'site.timezone'") . '; mark rows: ' . $count("settingKey = 'site.timezoneKeptAt'")
        );
    } finally {
        // Whatever happened: the table's shape as migration 217 leaves it, then the starting state.
        brs_runMigration('217_one_time_zone_answer.sql');
        $back = $backup->restoreAll($start['directory'], false);
        brs_check($back['success'] === true, 'the starting state is put back', brs_say($back));
    }
}

/**
 * 26 — the three tables a ONE-table restore refuses by name (#X9, the owner's
 * decision of 26 October 2026).
 *
 * WHY THIS PROOF EXISTS. restoreTable() used to check two things about a table
 * and nothing else: that its NAME is shaped like an identifier, and that no
 * other table points at it. Neither can see what a table MEANS. An independent
 * check of the restore ran it and watched real damage:
 *
 *   • tblTotpBackupCodes put back on its own brought already-used sign-in
 *     codes back to life, and they stayed usable.
 *   • tblMigrations put back on its own made the portal believe an upgrade it
 *     had already done was still waiting, so the upgrade ran again over data
 *     it had already changed. The checker saw that delete a two-factor backup
 *     code made after the upgrade.
 *
 * WHAT IS CHECKED HERE, and why each check is not enough on its own:
 *   1. Each of the three is refused, and the words name the table and the
 *      button to press instead.
 *   2. The refusal comes BEFORE anything is read or emptied. "Nothing
 *      changed" would NOT prove that by itself — the database undoes a
 *      failed restore, so a refusal placed after the emptying also leaves
 *      nothing changed. So the proof is that the SAME refusal comes back for
 *      a path that is not a snapshot at all: the guard cannot have read a
 *      file, let alone emptied a table. The row counts are checked too.
 *   3. Capital letters do not matter. Whether MySQL treats two names that
 *      differ only in capitals as one table is a server setting that differs
 *      between a shared host and a developer's machine.
 *   4. A table NOT on the list goes back exactly as before: the guard must
 *      not have narrowed the button it was built to keep.
 *   5. A WHOLE-database restore still puts all three back, exactly. That is
 *      the supported way, and the refusal above is worthless if the way out
 *      it recommends does not work.
 *   6. Every name on the list is a real table in this database. A typo in the
 *      constant would guard nothing at all and nothing else would notice.
 */
function brs_proof26(): void
{
    $backup = brs_backup('p26');

    // 🧱 A made-up account with two unused sign-in codes. No real person, and
    //    the "codes" are not codes: nothing here is ever hashed or printed.
    brs_sql(
        "INSERT INTO tblUsers (userID, emailAddress) VALUES (970031, 'brs-selftest@example.invalid')",
        "INSERT INTO tblTotpBackupCodes (codeID, userID, codeHash, isUsed) VALUES "
        . "(970031, 970031, 'not-a-real-hash-1', 0), (970032, 970031, 'not-a-real-hash-2', 0)",
        "INSERT INTO tblSettings (settingID, siteID, settingKey, settingValue, defaultValue, isSensitive, updatedAt) "
        . "VALUES (970030, NULL, 'brs.selftest.x9', 'as it was when the snapshot was taken', 'the default', 0, '2026-01-02 00:00:00')"
    );
    brs_addSelfPointingRows();
    $before = brs_fingerprint();

    $taken = $backup->snapshot('proof 26');
    if (brs_check($taken['success'] === true, 'a snapshot is taken', brs_say($taken)) === false) {
        return;
    }
    $dir = (string) $taken['directory'];

    // 📋 The list itself: exactly the three tables, each with its own words,
    //    and each name a table this database really has. A name misspelt in
    //    the constant would guard nothing, and nothing else here would notice.
    $list = DbBackup::WHOLE_DATABASE_ONLY;
    brs_check(
        array_keys($list) === ['tblMigrations', 'tblTotpBackupCodes', 'tblSettings'],
        'the list of tables that may only go back with the whole database is exactly the three it should be',
        brs_say(array_keys($list))
    );
    // The tables this database really has, asked for ONCE and compared in PHP:
    // no name is ever put into a statement, so there is nothing to escape.
    $real = array_column(Brs::$writer->query('SHOW TABLES')->fetch_all(), 0);
    $missing  = [];
    $wordless = [];
    foreach ($list as $table => $why) {
        if (in_array((string) $table, $real, true) === false) {
            $missing[] = (string) $table;
        }
        if (trim((string) $why) === '') {
            $wordless[] = (string) $table;
        }
    }
    brs_check($missing === [], 'every name on the list is a real table in this database (a typo would guard nothing)', brs_say($missing));
    brs_check($wordless === [], 'and each has its own sentence saying why, so the refusal is not vague', brs_say($wordless));

    // 🧨 Damage of exactly the kind the checker found: the codes are spent,
    //    a setting is changed, and the record of an upgrade is removed.
    brs_sql(
        "UPDATE tblTotpBackupCodes SET isUsed = 1, usedAt = '2026-10-26 09:00:00' WHERE userID = 970031",
        "UPDATE tblSettings SET settingValue = 'changed after the snapshot' WHERE settingID = 970030",
        "DELETE FROM tblMigrations WHERE filename = '217_one_time_zone_answer.sql'"
    );
    $damaged  = brs_fingerprint();
    $spentNow = static fn (): int => (int) Brs::$writer->query('SELECT COUNT(*) FROM tblTotpBackupCodes WHERE userID = 970031 AND isUsed = 1')->fetch_row()[0];
    brs_check($spentNow() === 2, 'both sign-in codes are now spent, and a setting and an upgrade record have been changed', 'spent: ' . $spentNow());

    // 1 & 2. Each table is refused, in words, and nothing is touched.
    $notASnapshot = Brs::$root . '/p26/not-a-snapshot-at-all';
    mkdir($notASnapshot, 0750, true);
    foreach (array_keys($list) as $table) {
        $rowsBefore = (int) Brs::$writer->query('SELECT COUNT(*) FROM `' . $table . '`')->fetch_row()[0];
        $answer     = $backup->restoreTable($dir, (string) $table);
        brs_check(
            $answer['success'] === false
            && str_contains((string) ($answer['error'] ?? ''), $table . ' cannot be put back on its own') === true
            && str_contains((string) ($answer['error'] ?? ''), 'Restore ALL tables') === true
            && str_contains((string) ($answer['error'] ?? ''), 'Nothing has been changed') === true,
            $table . ' on its own is refused, and the words name the table, the button to press instead, and say nothing was changed',
            brs_say($answer)
        );
        brs_check(
            (int) ($answer['rows_restored'] ?? -1) === 0
            && (int) Brs::$writer->query('SELECT COUNT(*) FROM `' . $table . '`')->fetch_row()[0] === $rowsBefore,
            'and ' . $table . ' still holds every row it held',
            brs_say($answer)
        );
        // The decisive one: a path that is no snapshot. If the guard sat lower
        // down, the answer would be about the missing manifest instead, which
        // would mean the snapshot had been opened before the table was judged.
        $answer = $backup->restoreTable($notASnapshot, (string) $table);
        brs_check(
            $answer['success'] === false
            && str_contains((string) ($answer['error'] ?? ''), $table . ' cannot be put back on its own') === true,
            'asked with a path that is not a snapshot at all, ' . $table . ' is refused for WHAT IT IS, not for the missing file: so the guard runs before anything is read or emptied',
            brs_say($answer)
        );
    }
    brs_checkSame($damaged, 'after all those refusals the database is exactly as the damage left it');
    brs_check($spentNow() === 2, 'the two spent sign-in codes are still spent: no code was brought back to life', 'spent: ' . $spentNow());

    // 3. Capital letters.
    foreach (['tblmigrations', 'TBLTOTPBACKUPCODES', 'TblSettings'] as $oddly) {
        $answer = $backup->restoreTable($dir, $oddly);
        brs_check(
            $answer['success'] === false && str_contains((string) ($answer['error'] ?? ''), 'cannot be put back on its own') === true,
            'written as "' . $oddly . '" it is refused just the same (whether capitals matter is a server setting, so being stricter than the server is the safe side)',
            brs_say($answer)
        );
    }
    brs_check(DbBackup::isWholeDatabaseOnly(['tblSettings']) === false, 'a list sent in place of a name is not mistaken for one of them (a browser can send table[]=x)');
    brs_check(DbBackup::isWholeDatabaseOnly('tblTasks') === false, 'and an ordinary table is not on the list');

    // 4. A table that is NOT on the list still goes back, exactly as before.
    brs_damageSelfPointingRows();
    $answer = $backup->restoreTable($dir, 'tblTasks');
    brs_check($answer['success'] === true, 'a table that is NOT on the list still goes back on its own, self-pointing rows and all', brs_say($answer));
    $now = brs_fingerprint();
    brs_check(($now['tblTasks'] ?? '') === ($before['tblTasks'] ?? ''), 'and tblTasks is exactly as it was', 'tblTasks differs');

    // 5. The whole-database restore, the way out the refusal recommends, still
    //    puts all three back exactly. Without this the refusal would be a dead
    //    end rather than a redirection.
    $whole = $backup->restoreAll($dir, false);
    brs_check($whole['success'] === true, 'the WHOLE-database restore still runs', brs_say($whole));
    brs_checkSame($before, 'and every value of every table is as it was when the snapshot was taken, the three refused tables included');
    brs_check(
        $spentNow() === 0
        && (string) Brs::$writer->query('SELECT settingValue FROM tblSettings WHERE settingID = 970030')->fetch_row()[0] === 'as it was when the snapshot was taken'
        && (int) Brs::$writer->query("SELECT COUNT(*) FROM tblMigrations WHERE filename = '217_one_time_zone_answer.sql'")->fetch_row()[0] === 1,
        'named one by one: the sign-in codes, the setting and the upgrade record are all back as the snapshot held them',
        'spent: ' . $spentNow()
    );
}

mkdir(Brs::$root, 0750, true);
Brs::$root    = (string) realpath(Brs::$root);
// The scratch folder holds whole copies of the database, so it is removed
// however the run ends: at the end, on a refusal, or on a crash. (The first
// version removed it only at the end, and five were left behind by runs that
// stopped early.) What this cannot survive is the process being killed.
register_shutdown_function(static function (): void {
    brs_removeFolder(Brs::$root);
});

$proofs = [
    1  => ['Imported calendar events with awkward raw bytes (#577)', 'brs_proof1', false],
    2  => ['Rows that point at rows of their own table (#578)', 'brs_proof2', false],
    3  => ['"Last changed" dates are not re-stamped when a pointer is filled in', 'brs_proof3', false],
    4  => ['The check-only run is a rehearsal: the restore\'s own answer, and nothing changed', 'brs_proof4', false],
    6  => ['A snapshot in the old form still restores', 'brs_proof6', false],
    8  => ['A table file cut short is refused before anything is touched', 'brs_proof8', false],
    10 => ['Moments survive a change of time zone, and the night the clocks go back', 'brs_proof10', false],
    12 => ['prune() keeps finished snapshots and leaves alone what it should', 'brs_proof12', false],
    13 => ['Rows linked to their own table by the strict kind of link', 'brs_proof13', false],
    14 => ['An unknown column type is refused; every known type comes back identical', 'brs_proof14', false],
    16 => ['A link added after the snapshot was taken: a pointer at a row that is gone', 'brs_proof16', false],
    17 => ['Putting one table back', 'brs_proof17', false],
    18 => ['A restore never says yes while the database says it changed a value, whatever the host\'s own settings', 'brs_proof18', false],
    19 => ['Names and folders: outside the backups folder, odd names, Delete, marked bytes, a name already taken', 'brs_proof19', false],
    20 => ['A large row the database accepts by itself goes back; one larger than the database\'s limit is refused in words', 'brs_proof20', false],
    21 => ['A table the database cannot undo changes to is refused before anything is touched', 'brs_proof21', false],
    22 => ['Tables that are not in the snapshot: what putting it back does to them is counted and said', 'brs_proof22', false],
    23 => ['An undo the database does not confirm closes the connection, and the result says so', 'brs_proof23', false],
    24 => ['A value not on its column\'s list of choices: the snapshot says at once that it cannot be put back whole', 'brs_proof24', false],
    25 => ['A snapshot taken before migration 217 goes back onto a database that has run it (#604)', 'brs_proof25', false],
    26 => ['Three tables a one-table restore refuses by name, and a whole restore still puts back', 'brs_proof26', false],
    // These four need the large table, so they run together at the end.
    5  => ['A large table under a fixed memory limit (#579)', 'brs_proof5', true],
    7  => ['A snapshot that fails or is killed is never counted as a backup', 'brs_proof7', true],
    9  => ['A restore that fails or is killed half way changes nothing (#472)', 'brs_proof9', true],
    11 => ['A record saved while a snapshot runs is in it whole or not at all', 'brs_proof11', true],
];
$only = isset($options['only']) === true ? array_map('intval', explode(',', (string) $options['only'])) : array_keys($proofs);

echo "Backup and restore self-test\n";
echo str_repeat('=', 78) . "\n";
printf(
    "Database %s on %s:%s, server %s, as user %s. PHP %s.\n",
    brs_env('SELFTEST_DB_NAME', ''),
    brs_env('SELFTEST_DB_HOST', '127.0.0.1'),
    brs_env('SELFTEST_DB_PORT', '3306'),
    Brs::$writer->server_info,
    brs_env('SELFTEST_DB_USER', 'root'),
    PHP_VERSION
);

// The starting state. Anything left over from a run that was cut short is
// cleared away first, with the same tidy-up every proof begins with.
brs_tidy();
Brs::$sessionLinkAtStart = brs_sessionLink();
Brs::$base = brs_fingerprint();
$rowsAtStart = 0;
foreach (Brs::$base as $line) {
    $rowsAtStart += (int) $line;
}
printf("Starting state: %d tables, %d rows. Every comparison below is of every value in all of them.\n", count(Brs::$base), $rowsAtStart);
if (count(Brs::$base) < 200) {
    echo "REFUSED. This database has fewer than 200 tables, so it was not built from web/_sql/full_schema.sql.\n";
    echo "Nothing was run, and nothing is proved.\n";
    exit(2);
}

if (isset($options['write-samples']) === true) {
    brs_addByteRows();
    $taken = brs_backup('samples')->snapshot('sample for the schema check');
    if ($taken['success'] !== true) {
        echo 'Could not take the snapshot to make samples from: ' . brs_say($taken) . "\n";
        exit(1);
    }
    brs_writeSamples($taken['directory']);
    brs_reset(false);
    brs_removeFolder(Brs::$root);
    echo 'Sample files written to ' . BRS_SAMPLE_DIR . "\n";
    exit(0);
}

$passed = [];
$failed = [];
foreach ($proofs as $number => [$title, $function, $needsBigRows]) {
    if (in_array($number, $only, true) === false) {
        continue;
    }
    echo "\nProof " . $number . '. ' . $title . "\n";
    brs_reset($needsBigRows);
    Brs::$proofOk = true;
    $checksBefore = Brs::$checks;
    try {
        $function();
    } catch (Throwable $e) {
        // A proof that stops with an error has failed; say where, and carry on
        // with the others.
        brs_check(false, 'the proof ran to its end', get_class($e) . ': ' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
    }
    if (Brs::$checks === $checksBefore) {
        brs_check(false, 'the proof made at least one check');
    }
    if (Brs::$proofOk === true) {
        $passed[] = $number;
    } else {
        $failed[] = $number;
    }
}

// Leave the database as it was found, and say so.
brs_reset(false);
brs_removeFolder(Brs::$root);

sort($passed);
sort($failed);
echo "\n" . str_repeat('=', 78) . "\n";
printf("%d proof(s) run, %d check(s) made. Passed: %s. Failed: %s.\n", count($passed) + count($failed), Brs::$checks, $passed === [] ? 'none' : implode(', ', $passed), $failed === [] ? 'none' : implode(', ', $failed));
if (count($passed) + count($failed) < count($proofs)) {
    echo "Only some proofs were selected (--only), so this run does not prove the whole set.\n";
}
echo "The database was put back to its starting state and checked.\n";
if ($failed !== []) {
    echo "\nThe portal's backup or restore is broken. Do not release this.\n";
}
echo "REACHED THE END\n";
exit($failed === [] ? 0 : 1);
