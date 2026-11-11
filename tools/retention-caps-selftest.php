<?php
// Path: tools/retention-caps-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A retention period a clear-out USES is held to the installation's — self-test
 * 🧹🔒 (#685, #722)
 * -----------------------------------------------------------------------------
 * `tools/setting-limit-selftest.php` proves the RULE (`Portal\Core\SettingLimit`)
 * with no database at all. This proves the two things that rule cannot:
 *
 *   1. that the READERS the nightly clear-out actually uses apply it, against
 *      rows really in a database — including rows this portal would now refuse
 *      to let anybody store; and
 *   2. that the SAVE path judges the ceiling live, with the installation's own
 *      row LOCKED, which needs two connections at once and therefore cannot be
 *      proved by a page test at all.
 *
 * WHY 1 NEEDS A DATABASE AND NOT A FIXTURE ARRAY. #685 was exactly the case
 * where the stored row disagrees with what any form would now accept: an
 * organisation that chose 1000 days while that was allowed, or that had a row
 * written by hand or restored from a whole-database backup. A SAVE-TIME CAP IS
 * NOT A CAP. So every figure here is put in with SQL, which is the only way to
 * reach the state the issue is about.
 *
 * WHY 2 IS A SEPARATE SECTION, AND WHY IT IS NOT IN THE PAGE TEST. #687
 * measured two ordinary overlapping web requests storing fifty years under a
 * ceiling of seven, 60 pairs out of 60, because the gate compared against the
 * request's settings snapshot taken at start-up. `access-pages-selftest.php`
 * and the other HTTP harnesses run the built-in server with ONE worker, so two
 * requests never overlap and such a test PASSES ON THE BROKEN CODE — the lesson
 * the expense payment race is written up under. Section D holds the lock from a
 * second process instead, and the committed check is the one that can fail.
 *
 * WHAT IS CHECKED
 *   A. #685 — `AnonymousCheckins::readRetentionDays()`, every row of the rule's
 *      own table, each figure inserted by SQL. Including that a SHORTER
 *      organisation figure survives, which is what stops the cap being a clamp
 *      to one number.
 *   B. #714/#722 — `TranscriptRetention::daysForSite()`, the same, plus "keep
 *      for ever" surviving where BOTH levels mean it. That last one could not
 *      happen before #722: the class worked its own arithmetic out against a
 *      ceiling that never answers 0, so an installation choosing "for ever" had
 *      every transcript deleted at ten years, under a comment in the sweep
 *      saying it kept them.
 *   C. `TranscriptRetention::saveForSite()` — refuses above the installation and
 *      writes nothing, refuses above the hard maximum in different words,
 *      stores a shorter figure, and clears the organisation's own row.
 *   D. THE LOCK — a second process holds the installation's row and raises the
 *      ceiling; a save of a longer figure must WAIT for it and then refuse.
 *   E. the page and its handler, read as text: both of #668's questions asked,
 *      a box never prefilled above its own `max`, a refusal that exits without
 *      writing, and the refusal recorded.
 *   F. #491 — the ACTIVITY-LOG and ERROR clear-outs, against real rows in both
 *      tables: each organisation cleared by its own figure, an organisation's
 *      stored figure held to the installation's, and the rows that belong to
 *      NO organisation cleared by the installation's own figure rather than
 *      kept for ever. Before this both read one pair of settings and deleted
 *      across the whole installation, so one organisation's choice decided
 *      every organisation's history.
 *      #932 (D-117): for the ACTIVITY log a stored 0 now means "never
 *      delete", at both levels and in both directions — an installation at
 *      never lets an organisation choose any number of days or never; one
 *      with a number of days holds an organisation's never to that number
 *      (reader) and refuses it (save, `ActivityLogRetention`); never is not
 *      clamped to 3650; and the sweep counts and deletes nothing under it.
 *      D-119: lowering the installation below an organisation's own figure is
 *      refused, naming it — including a lowering made WHILE an organisation's
 *      never is being saved, driven with a second process (`--hold-activity`).
 *      The ERROR log's 0 still falls back to a year, pinned on its own so the
 *      shared helpers cannot drift.
 *   G. #810 — the REGISTRATIONS clear-out, driven for real: a registration's
 *      period is measured on its EVENT's own clock, not the database server's.
 *      The old expression is run beside it on the same rows first, to show it
 *      really deleted early (an event behind the server's clock) and late (one
 *      ahead of it). Also: the organisation's own figure for an event with
 *      none (20 days, and 0 = keep indefinitely), both of its bindings; the
 *      deadline is the LATER of n days on the clock and n x 24 real hours,
 *      across a real recent spring-forward night, AND the per-zone rule
 *      (`Events::deadlineReadingFor()`) driven at FIXED moments for the three
 *      cases the real "now" cannot reach: a skipped-hour reading at the
 *      window's start, a winter inside a summer-to-summer window, and zones
 *      with no list of changes (`EST`, `+05:00`); zones compared byte for
 *      byte, so a hand-written `Europe/Zürich` cannot capture the real Zurich
 *      rows (and a trailing space still reaches its zone); and the one case
 *      that cannot be exact — a row whose zone was written after the list was
 *      read — reads the EARLIEST clock on Earth.
 *
 * WHAT THIS CANNOT PROVE
 *   - Nothing here runs over HTTP, so it says nothing about what the page looks
 *     like, whether its gate refuses a non-administrator, or whether the form
 *     token is checked. Section E reads those as text, which is weaker.
 *   - #932's two race checks (section F) cannot catch ONE fault: the D-119
 *     read of the organisations moved to BEFORE the installation's row is
 *     locked. Both orders then answer the same in every timing these checks
 *     can arrange without a hook inside the method; the order is held by the
 *     method's own comment and by review, not by a test. The holder processes
 *     are given time (a sleep) rather than asserted to hold their lock; if one
 *     never took it, the saver does not wait and the "it waited" half of the
 *     check fails, so this fails closed.
 *   - Section D proves the lock SERIALISES two savers and that the second sees
 *     the first's ceiling. It does not run many simultaneous pairs; #687's
 *     116-pair measurement is the precedent for that and was done by hand.
 *   - It runs one engine and one version, whichever the caller points it at.
 *   - Section G cannot fix the time of day, so each reading is built from the
 *     zone's own clock at the moment of the run, hours either side of its
 *     deadline, and its spring-forward zone is FOUND (the most recent one
 *     among every zone PHP knows), so which zone it names changes with the
 *     date. It does not reach the sweep's `ELSE` THROUGH the sweep (a zone
 *     written between two statements microseconds apart); it asks the method
 *     directly and pins the sweep's call as text. A zone read that FAILS is
 *     not a case at all: under strict reporting it throws and nothing is
 *     deleted.
 *   - A date-line jump is the same arithmetic on a larger gain and is not
 *     separately driven. Nothing here can tell `CAST(... AS BINARY)` from
 *     `COLLATE utf8mb4_bin`: the two differ only on trailing spaces, which
 *     `Site::zoneFor()` trims, so no row's answer changes (planted, it stays
 *     green); the binary form is there so "byte for byte" is true.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_retcaps SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/retention-caps-selftest.php
 *
 * Exit:   0 only when every check ran and passed; 1 otherwise, including when
 *         the number of checks is not the number expected.
 *
 * Leaves:  `tblSettings` exactly as it found it, on every way out including a
 *          failure (#947) — see "EVERY SETTINGS ROW IS PUT BACK" below. A run
 *          stopped from outside (kill, Ctrl-C) is put right by the next run on
 *          the SAME build of the database; after a rebuild the next run is
 *          refused until the stale record is deleted, rather than put an old
 *          build's settings onto a new one. Two runs at once are refused.
 *          Other tables keep this file's fixture rows, as they always have.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/685
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/491
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/810
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const RC_EXPECTED_CHECKS = 143; // 64 until #491 added section F's sixteen; 80 until #810 added section G's thirty-six; 116 until #932 replaced section F's three 0-means-a-year pins with twenty-two; 135 until D-118/D-119 (#932 round 2) added six, including the race and the unreadable case; 141 until check round 1 added the race the other way; 142 until check round 2 added never saved over organisations' days.

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    exit(1);
}

$rcHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$rcUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$rcPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$rcPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

$rcCore = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
require_once $rcCore . 'InstallationSettings.php';
require_once $rcCore . 'App.php';
require_once $rcCore . 'SettingLimit.php';
require_once $rcCore . 'AnonymousCheckins.php';
require_once $rcCore . 'TranscriptRetention.php';
// 🧒 #816: section G drives the registrations clear-out, which reads each
//    organisation's period through RegistrationRetention.
require_once $rcCore . 'RegistrationRetention.php';

use Portal\Core\AnonymousCheckins;
use Portal\Core\App;
use Portal\Core\InstallationSettings;
use Portal\Core\TranscriptRetention;

// -----------------------------------------------------------------------------
// 🔒 SECOND ROLE: holding the installation's row for section D.
//
//    The same file, started again with an argument, because the lock has to be
//    held by a DIFFERENT CONNECTION while this one runs — and a lock that is
//    not held by somebody else proves nothing. It takes the lock through the
//    portal's own method, so a change to that method is felt here too; then it
//    raises the ceiling and waits before committing, which is the window the
//    saver has to be caught in.
// -----------------------------------------------------------------------------
if (($argv[1] ?? '') === '--hold-ceiling') {
    $holdTo      = (string) ($argv[2] ?? '7');
    $holdForMs   = (int) ($argv[3] ?? 3000);
    $holder      = new mysqli($rcHost, $rcUser, $rcPass, $dbName, $rcPort);
    $holder->begin_transaction();
    TranscriptRetention::installationCeilingLocked($holder);
    $up  = $holder->prepare('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
    $key = TranscriptRetention::KEY;
    $up->bind_param('ss', $holdTo, $key);
    $up->execute();
    $up->close();
    usleep($holdForMs * 1000);
    $holder->commit();
    $holder->close();
    exit(0);
}

// 🔒 FOURTH ROLE (#932 check round 1, finding M1): the INSTALLATION's figure
//    being lowered from never to 30, in flight. It locks the installation's
//    row, as saveInstallation() does, changes it, and waits before committing.
//    An organisation's "never" saved in that window must WAIT and then see 30.
//    Without the lock in storedValue() it reads the old never from a snapshot
//    and stores the organisation's never under an installation of 30 days —
//    exactly #687's fault — and the THIRD role below cannot see that, because
//    there the lowering's own locked read of the organisations catches it.
if (($argv[1] ?? '') === '--hold-installation') {
    $holdForMs = (int) ($argv[2] ?? 2500);
    $holder    = new mysqli($rcHost, $rcUser, $rcPass, $dbName, $rcPort);
    $holder->begin_transaction();
    $holder->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'audit.retentionDays' AND `siteID` IS NULL FOR UPDATE");
    $holder->query("UPDATE `tblSettings` SET `settingValue` = '30' WHERE `settingKey` = 'audit.retentionDays' AND `siteID` IS NULL");
    usleep($holdForMs * 1000);
    $holder->commit();
    $holder->close();
    exit(0);
}

// 🔒 THIRD ROLE (#932, D-119): an organisation's save of "never" in flight. It
//    locks the INSTALLATION's activity-log row first, exactly as
//    ActivityLogRetention::saveForSite() does, writes organisation 1's never,
//    and waits before committing — the window a lowering has to be caught in.
if (($argv[1] ?? '') === '--hold-activity') {
    $holdForMs = (int) ($argv[2] ?? 2500);
    $holder    = new mysqli($rcHost, $rcUser, $rcPass, $dbName, $rcPort);
    $holder->begin_transaction();
    $holder->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'audit.retentionDays' AND `siteID` IS NULL FOR UPDATE");
    $holder->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (1, 'audit.retentionDays', '0', '', 0)");
    usleep($holdForMs * 1000);
    $holder->commit();
    $holder->close();
    exit(0);
}

$mysqli = new mysqli($rcHost, $rcUser, $rcPass, $dbName, $rcPort);

// -----------------------------------------------------------------------------
// ♻️ EVERY SETTINGS ROW IS PUT BACK AS FOUND, ON EVERY WAY OUT (#947)
// -----------------------------------------------------------------------------
// This test changes settings in almost every section: `rc_world()` deletes a
// key at BOTH levels and writes it again, the race holders change the
// installation's row from another process, the saves it drives write rows of
// their own, and the sweeps it runs may record things too. Until #947 none of
// it was put back, so a run left the installation's `audit.retentionDays` and
// `errors.retentionDays` at 30, the transcript period at 7, and
// per-organisation rows nobody chose — and the NEXT test on the same database
// read those as the installation's real figures. `erasure-pages-selftest.php`
// had to set 365 at its own start for exactly that reason (#940).
//
// WHAT IS CAPTURED: the WHOLE of `tblSettings`, every column of every row
// (number, organisation, key, value, default, sensitivity and the time it was
// last changed), not a list of the keys this file happens to name. A list
// would have to be kept up to date by hand, and it would miss anything a
// method or a sweep this test drives writes on its own — the shape #668 found
// four times. Only rows that DIFFER are touched on the way out.
//
// HOW A ROW IS PUT BACK: each row is matched by what it IS — its key and its
// organisation (or "the installation") — because `rc_world()` deletes and
// re-inserts, so the same setting comes back under a NEW number. A row that is
// not exactly as captured is deleted, and the captured row is inserted again
// with its OWN number and its own "last changed" time, so the table's rows are
// identical to what was there before the run. A row the run added is deleted;
// a row the run deleted comes back. Nothing refers to `tblSettings`, so
// deleting a row cascades nowhere. What CANNOT be put back is the table's
// AUTO_INCREMENT counter, which every insert moves on — a dump of the rows is
// identical, a dump that includes the table definition is not.
//
// LEFTOVERS FROM A RUN THAT DID NOT FINISH (#932's lesson, section M of
// `tools/activity-log-retention-selftest.php`): the capture is written to a
// file named for this database, BEFORE anything is changed, and removed only
// after a successful put-back. A shutdown function does the put-back, and PHP
// runs it on a normal end, on every `exit()`, and after an uncaught error.
// A run STOPPED FROM OUTSIDE skips it: `kill -9`, an ordinary `kill`
// (SIGTERM), Ctrl-C (SIGINT), a runner's timeout, or the machine going down —
// measured for SIGTERM and SIGINT in #947's check round 1, since PHP only runs
// shutdown functions when the script ends itself. So the leftover-file path
// is not rare: any interrupted local run reaches it. Then the file is still
// there, and the next run takes ITS capture as the original and puts that back
// first. It never takes the rows in the database as the original while that
// file exists, because they may be the dead run's leftovers.
//
// A LEFTOVER IS ONLY PUT BACK ONTO THE SAME TABLE IT CAME FROM (#947 check
// round 1). The whole-suite runner rebuilds `selftest_retcaps` from
// `full_schema.sql` on every run, on the same port with the same name, so a
// file named only by port and database would be "restored" onto a FRESH
// database: measured, it deleted a setting the new install script had added
// and left stale row numbers and times in place for ever, while the run passed.
// So the file also records WHICH table it captured — the server's own id and
// the moment `tblSettings` was created, which a write never changes and a
// rebuild always does — and a file that does not match is REFUSED, the
// database untouched, with the same "delete that file" advice an unreadable
// one gets. The limit: `CREATE_TIME` counts whole seconds, so a database
// dropped and rebuilt within the second it was first created would look the
// same; loading the install script takes several seconds.
//
// TWO RUNS AT ONCE on one database are refused too. The second would find
// the first's LIVE file and take it for an unfinished run's. A named lock on
// the server (`GET_LOCK`) is held by this run's connection, and the server
// lets it go when that connection ends, however the run ended — so a killed
// run never leaves the lock behind the way it can leave the file.
//
// A capture holding NO rows is refused rather than put back: putting back an
// empty table would delete every setting there is. A database whose
// `tblSettings` is empty has not had the install script loaded, and is
// refused at capture for the same reason.
//
// WHAT THIS CANNOT DO: put back anything but `tblSettings`. The second
// organisation this file creates (`tblSites` row 2) and the log, event and
// registration rows it inserts are left, as they always were; they are
// fixtures every run creates again, and no other test reads them as a
// setting. And a capture file somebody has edited by hand is believed.
$rcSettingsFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'retcaps-original-settings-' . $rcPort . '-' . $dbName . '.json';

/** The columns that make a settings row what it is. `siteScope` is worked out by the database and cannot be written. */
const RC_SETTING_COLUMNS = ['settingID', 'siteID', 'settingKey', 'settingValue', 'updatedAt', 'isSensitive', 'defaultValue'];

/**
 * Every settings row as it stands, keyed by what the row IS: its organisation
 * ('-' for the installation) and its key. Read with an ordinary query, so every
 * value comes back as text (or null), exactly as it will after a round trip
 * through the capture file — a prepared statement would hand numbers back as
 * numbers, and `===` would then call identical rows different.
 *
 * @return array<string, array<string, ?string>>
 */
$rcSettingsNow = static function (mysqli $db): array {
    $rows = [];
    $res  = $db->query(
        'SELECT `' . implode('`, `', RC_SETTING_COLUMNS) . '` FROM `tblSettings` ORDER BY `settingID`'
    );
    while ($row = $res->fetch_assoc()) {
        $rows[($row['siteID'] ?? '-') . '|' . $row['settingKey']] = $row;
    }
    $res->free();
    ksort($rows, SORT_STRING);

    return $rows;
};

/**
 * Put `tblSettings` back exactly as captured, in ONE transaction, touching only
 * the rows that differ. Answers how many settings it had to change.
 *
 * Any transaction this connection still has open is rolled back FIRST: a run
 * that died part-way through a save would otherwise have the put-back written
 * INSIDE that transaction, and then thrown away with it when the connection
 * closes (#694's lesson — the fault and its repair both lost, silently).
 *
 * @param array<string, array<string, ?string>> $original
 */
$rcSettingsPutBack = static function (mysqli $db, array $original) use ($rcSettingsNow): int {
    try {
        $db->rollback();
    } catch (Throwable $ignored) {
        // Nothing was open, so there is nothing to roll back.
    }

    // 🏢 Section F renames `tblSites` for the length of one save and puts it
    //    back in a `finally`. A run stopped from outside inside that window
    //    (`kill`, Ctrl-C, `kill -9`) skips the `finally`, and then every later
    //    run would fail on a missing table, so
    //    it is put back here too when that is plainly what happened.
    $away  = $db->query("SHOW TABLES LIKE 'tblSitesP932Away'")->num_rows > 0;
    $sites = $db->query("SHOW TABLES LIKE 'tblSites'")->num_rows > 0;
    if ($away === true && $sites === false) {
        $db->query('RENAME TABLE `tblSitesP932Away` TO `tblSites`');
    }

    $now = $rcSettingsNow($db);
    if ($now === $original) {
        return 0;
    }

    $changed = 0;
    $db->begin_transaction();
    try {
        // 1. Delete every row that is not exactly as captured, including rows
        //    the run ADDED. Done first, so the inserts below can never meet a
        //    clash on a key or a number.
        $del = $db->prepare('DELETE FROM `tblSettings` WHERE `settingID` = ?');
        foreach ($now as $identity => $row) {
            if (isset($original[$identity]) === true && $original[$identity] === $row) {
                continue;
            }
            $id = (int) $row['settingID'];
            $del->bind_param('i', $id);
            $del->execute();
            $changed++;
        }
        $del->close();

        // 2. Insert every captured row that is not there now, under its OWN
        //    number and with its own values, so the table reads as it did.
        $ins = $db->prepare(
            'INSERT INTO `tblSettings` (`' . implode('`, `', RC_SETTING_COLUMNS) . '`) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($original as $identity => $row) {
            if (isset($now[$identity]) === true && $now[$identity] === $row) {
                continue;
            }
            $id        = $row['settingID'];
            $site      = $row['siteID'];
            $key       = $row['settingKey'];
            $value     = $row['settingValue'];
            $updatedAt = $row['updatedAt'];
            $sensitive = $row['isSensitive'];
            $default   = $row['defaultValue'];
            // Every value is bound as text and MySQL converts it; a null stays
            // NULL whatever its letter says, so "no value" survives as itself.
            $ins->bind_param('sssssss', $id, $site, $key, $value, $updatedAt, $sensitive, $default);
            $ins->execute();
            if (isset($now[$identity]) === false) {
                $changed++;
            }
        }
        $ins->close();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    // Read it back rather than believing the statements: a put-back that
    // reports success over a table that still differs is the fault this
    // exists to stop.
    if ($rcSettingsNow($db) !== $original) {
        throw new RuntimeException('tblSettings still differs from the capture after putting it back');
    }

    return $changed;
};

/**
 * WHICH `tblSettings` this is: the server's own id, the database, and the
 * moment the table was created. A write never changes the last of these and a
 * rebuild of the database always does (measured on MySQL 8.0.36), so a capture
 * taken from one build of `selftest_retcaps` is never mistaken for another.
 * MariaDB has no `@@server_uuid`; there the server is named by its host name
 * and port instead, which is weaker and is all it offers.
 *
 * @return array{server: string, database: string, created: string}
 */
$rcSettingsIdentity = static function (mysqli $db) use ($dbName): array {
    try {
        $server = (string) ($db->query('SELECT @@server_uuid AS s')->fetch_assoc()['s'] ?? '');
    } catch (Throwable $noUuid) {
        $server = (string) ($db->query('SELECT CONCAT(@@hostname, \':\', @@port) AS s')->fetch_assoc()['s'] ?? '');
    }
    $created = $db->query(
        "SELECT `CREATE_TIME` AS c FROM `information_schema`.`TABLES` "
        . "WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblSettings'"
    )->fetch_assoc()['c'] ?? null;

    return ['server' => $server, 'database' => $dbName, 'created' => (string) $created];
};

/** Refuse this run, saying why and what to do, having changed nothing. */
$rcSettingsRefuse = static function (string $why) use ($rcSettingsFile, $dbName): never {
    echo "REFUSED — {$why}\n"
        . "Nothing in {$dbName} was changed. If an earlier run was stopped part-way, reload\n"
        . "web/_sql/full_schema.sql into {$dbName}, delete {$rcSettingsFile}, and run again.\n";
    exit(1);
};

// 🔒 ONE RUN AT A TIME on this database, asked before the file is even looked
//    at: a second run would otherwise read the first's live capture as an
//    unfinished run's and put it back under the first's feet. The name is
//    hashed only to stay inside MySQL's 64-character limit for a lock name.
$rcLockName = 'retcaps-selftest-' . substr(hash('sha256', $dbName), 0, 40);
$rcLocked   = (int) ($mysqli->query("SELECT GET_LOCK('" . $rcLockName . "', 0) AS l")->fetch_assoc()['l'] ?? 0);
if ($rcLocked !== 1) {
    echo "REFUSED — another run of this test is using {$dbName} right now. Nothing was changed; wait for it to finish.\n";
    exit(1);
}

$rcSettingsHere     = $rcSettingsIdentity($mysqli);
$rcSettingsOriginal = null;
if ($rcSettingsHere['created'] === '') {
    $rcSettingsRefuse("the moment {$dbName}'s tblSettings was created cannot be read, so a capture of it could not be told from a capture of another build of it.");
}
if (is_file($rcSettingsFile) === true) {
    // An earlier run on this database did not finish. ITS capture is the
    // original; the rows in the database now may be what it left behind.
    $rcSettingsSaved = json_decode((string) file_get_contents($rcSettingsFile), true);
    if (is_array($rcSettingsSaved) === false
        || is_array($rcSettingsSaved['identity'] ?? null) === false
        || is_array($rcSettingsSaved['rows'] ?? null) === false
    ) {
        // Refused rather than re-captured: the rows now may be a dead run's
        // leftovers, and taking them as the original would make them permanent.
        // A file from before the identity was recorded lands here too.
        $rcSettingsRefuse("{$rcSettingsFile} is there but cannot be read as this test's record, so the settings as they were before an earlier unfinished run are not known.");
    }
    if ($rcSettingsSaved['rows'] === []) {
        // Putting back an empty table would delete every setting there is.
        $rcSettingsRefuse("{$rcSettingsFile} records NO settings at all, and putting that back would delete every one.");
    }
    if ($rcSettingsSaved['identity'] !== $rcSettingsHere) {
        // A capture of a DIFFERENT build of this database — the usual case
        // after the whole-suite runner rebuilds it. Restoring it would replace
        // a fresh install's settings with an old one's.
        $rcSettingsRefuse("{$rcSettingsFile} was captured from a different copy of {$dbName} (" . json_encode($rcSettingsSaved['identity'])
            . ', this one is ' . json_encode($rcSettingsHere) . '), so it is not put back.');
    }
    $rcSettingsOriginal = $rcSettingsSaved['rows'];
    $rcSettingsBack     = $rcSettingsPutBack($mysqli, $rcSettingsOriginal);
    echo "  ! an earlier run on {$dbName} did not finish; its record of the settings was used, and "
        . $rcSettingsBack . " setting(s) were put back before starting\n";
} else {
    $rcSettingsOriginal = $rcSettingsNow($mysqli);
    if ($rcSettingsOriginal === []) {
        $rcSettingsRefuse("{$dbName}'s tblSettings is empty, so web/_sql/full_schema.sql has not been loaded into it.");
    }
    // Written to a temporary name and then renamed, so a run killed while
    // writing leaves either no file or a whole one, never half of one.
    $rcSettingsTemp = $rcSettingsFile . '.' . bin2hex(random_bytes(4)) . '.part';
    file_put_contents(
        $rcSettingsTemp,
        json_encode(['identity' => $rcSettingsHere, 'rows' => $rcSettingsOriginal], JSON_THROW_ON_ERROR)
    );
    rename($rcSettingsTemp, $rcSettingsFile);
}

register_shutdown_function(static function () use (
    $mysqli,
    $rcHost,
    $rcUser,
    $rcPass,
    $dbName,
    $rcPort,
    $rcSettingsFile,
    $rcSettingsOriginal,
    $rcSettingsPutBack
): void {
    try {
        try {
            $back = $rcSettingsPutBack($mysqli, $rcSettingsOriginal);
        } catch (Throwable $first) {
            // The run's own connection may be the thing that broke (lost, or
            // closed). One fresh connection is tried before giving up.
            $back = $rcSettingsPutBack(new mysqli($rcHost, $rcUser, $rcPass, $dbName, $rcPort), $rcSettingsOriginal);
        }
        @unlink($rcSettingsFile);
        echo "\nSettings put back as they were before this run (" . $back . " setting(s) changed).\n";
    } catch (Throwable $e) {
        // The file is deliberately KEPT, so the next run puts these back first.
        echo "\n! COULD NOT put the settings back: " . $e->getMessage() . "\n"
            . "  The record of them is kept at {$rcSettingsFile}; the next run on {$dbName} puts it back first.\n";
        // A run that leaves the database changed has not passed, however its
        // checks went: the next test on this database would read the leftover
        // figures as real. `exit()` inside a shutdown function sets the
        // process's exit status, so a runner sees this as a failure.
        exit(1);
    }
});

$rcPasses   = 0;
$rcFailures = 0;

function rc_check(string $what, bool $isOk, string $detail = ''): void
{
    global $rcPasses, $rcFailures;
    if ($isOk === true) {
        $rcPasses++;
        echo '  ✓ ' . $what . "\n";

        return;
    }
    $rcFailures++;
    echo '  ✗ ' . $what . ($detail === '' ? '' : '  —  ' . $detail) . "\n";
}

function rc_head(string $text): void
{
    echo "\n" . $text . "\n";
}

/**
 * 🧰 Put one setting in BY SQL, at one or both levels, and stand the layers up
 *    exactly as `bootstrap.php` does.
 *
 * The rows are what the readers go to the database for; the layers are what
 * `SettingLimit` reads for the installation's side. Both have to be set or the
 * fixture is only half a world — and the two must AGREE, or the test is
 * measuring a state the portal cannot be in, which is #645's lesson.
 */
function rc_world(mysqli $db, string $dotKey, string $installation, ?string $own, int $siteId = 1): void
{
    $stmt = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ?');
    $stmt->bind_param('s', $dotKey);
    $stmt->execute();
    $stmt->close();

    $ins = $db->prepare(
        'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) '
        . 'VALUES (?, ?, ?, ?, 0)'
    );
    $nullSite = null;
    $blank    = '';
    $ins->bind_param('isss', $nullSite, $dotKey, $installation, $blank);
    $ins->execute();
    if ($own !== null) {
        $ins->bind_param('isss', $siteId, $dotKey, $own, $blank);
        $ins->execute();
    }
    $ins->close();

    // The nested shape `assign_setting()` in bootstrap.php produces.
    $nest = static function (string $key, string $value): array {
        $parts = array_reverse(explode('.', $key));
        $node  = $value;
        foreach ($parts as $part) {
            $node = [$part => $node];
        }

        return $node;
    };
    InstallationSettings::remember($nest($dotKey, $installation));
    // The merged array is the organisation's own value where it has one, and
    // the installation's where it has not — which is what bootstrap produces.
    App::init($db, $nest($dotKey, $own ?? $installation));
}

$AK = AnonymousCheckins::RETENTION_KEY;
$TK = TranscriptRetention::KEY;

// 🏢 A SECOND ORGANISATION, and it has to be a real row: `tblSettings.siteID`
//    is linked to `tblSites` with `ON DELETE CASCADE`, so the database refuses
//    a settings row for an organisation that does not exist. Without a second
//    one the per-organisation lookup is pinned by nothing — a reader that
//    ignored its `$siteId` argument entirely would pass every check here.
$mysqli->query(
    'INSERT INTO `tblSites` (`siteID`, `siteName`, `siteKey`) VALUES (2, \'Second organisation\', \'second\') '
    . 'ON DUPLICATE KEY UPDATE `siteName` = VALUES(`siteName`)'
);

// =============================================================================
rc_head('A. #685 — the attendance clear-out holds every organisation to the installation');
// =============================================================================
// Each figure is INSERTED BY SQL, which is the only way to reach the state the
// issue is about: a row stored before the save-time refusal existed, written by
// hand, or restored from a whole-database backup.
rc_world($mysqli, $AK, '90', '1000');
rc_check('an organisation storing 1000 days is cleared at the installation\'s 90',
    AnonymousCheckins::readRetentionDays(1) === 90, (string) AnonymousCheckins::readRetentionDays(1));

rc_world($mysqli, $AK, '90', '0');
rc_check('"keep for ever" does NOT survive an installation that allows 90 days',
    AnonymousCheckins::readRetentionDays(1) === 90, (string) AnonymousCheckins::readRetentionDays(1));

rc_world($mysqli, $AK, '90', '-1');
rc_check('a negative figure is read as "for ever" and then capped like one',
    AnonymousCheckins::readRetentionDays(1) === 90, (string) AnonymousCheckins::readRetentionDays(1));

// 🚨 THE CAP MUST NOT BE A CLAMP TO ONE NUMBER. Without this check the whole
//    section passes on a reader that simply answers the installation's figure
//    whatever an organisation chose — which would silently LENGTHEN retention
//    for everybody who had chosen less, the opposite of the point.
rc_world($mysqli, $AK, '90', '30');
rc_check('an organisation holding ITSELF to 30 days keeps 30',
    AnonymousCheckins::readRetentionDays(1) === 30, (string) AnonymousCheckins::readRetentionDays(1));

rc_world($mysqli, $AK, '0', '1000');
rc_check('where the INSTALLATION allows for ever, 1000 days is allowed',
    AnonymousCheckins::readRetentionDays(1) === 1000, (string) AnonymousCheckins::readRetentionDays(1));

rc_world($mysqli, $AK, '0', '0');
rc_check('…and "for ever" survives where BOTH levels mean it',
    AnonymousCheckins::readRetentionDays(1) === 0, (string) AnonymousCheckins::readRetentionDays(1));

rc_world($mysqli, $AK, '90', null);
rc_check('an organisation with no row of its own follows the installation',
    AnonymousCheckins::readRetentionDays(1) === 90, (string) AnonymousCheckins::readRetentionDays(1));

// 🪤 A SECOND ORGANISATION, because with one the per-organisation lookup is
//    pinned by nothing: a reader that ignored `$siteId` entirely would pass
//    every check above.
rc_world($mysqli, $AK, '90', '1000');
$ins = $mysqli->prepare('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (2, ?, \'10\', \'\', 0)');
$ins->bind_param('s', $AK);
$ins->execute();
$ins->close();
rc_check('organisation 2\'s own 10 days is not confused with organisation 1\'s',
    AnonymousCheckins::readRetentionDays(2) === 10, (string) AnonymousCheckins::readRetentionDays(2));
rc_check('…and organisation 1 is still held to 90',
    AnonymousCheckins::readRetentionDays(1) === 90, (string) AnonymousCheckins::readRetentionDays(1));

// The stored figure, read at the organisation's OWN level, is what lets the page
// say a figure is being held down. It must NOT fall back to the installation's.
rc_check('ownRetentionDays() answers the stored 1000 for organisation 1',
    AnonymousCheckins::ownRetentionDays($mysqli, 1) === 1000,
    var_export(AnonymousCheckins::ownRetentionDays($mysqli, 1), true));
rc_world($mysqli, $AK, '90', null);
rc_check('…and null, never the installation\'s 90, for an organisation with no row',
    AnonymousCheckins::ownRetentionDays($mysqli, 1) === null,
    var_export(AnonymousCheckins::ownRetentionDays($mysqli, 1), true));

// =============================================================================
rc_head('B. #714/#722 — the transcript clear-out, and the "for ever" that could not happen');
// =============================================================================
rc_world($mysqli, $TK, '30', '9999');
rc_check('a hand-written transcript period above the ceiling is held to it',
    TranscriptRetention::daysForSite(1) === 30, (string) TranscriptRetention::daysForSite(1));

rc_world($mysqli, $TK, '30', '0');
rc_check('"for ever" does not survive a ceiling of 30 days',
    TranscriptRetention::daysForSite(1) === 30, (string) TranscriptRetention::daysForSite(1));

rc_world($mysqli, $TK, '30', '7');
rc_check('an organisation holding itself to 7 days keeps 7',
    TranscriptRetention::daysForSite(1) === 7, (string) TranscriptRetention::daysForSite(1));

// 🚨 THIS IS THE ONE THAT COULD NOT PASS BEFORE #722. `installationAllows()`
//    never answers 0 — it hands back the hard maximum where "for ever" is
//    stored, because what a form's `max` needs is a number of days — so the old
//    arithmetic here could never return 0, and `run_transcript_sweep()`'s
//    branch reading `$days <= 0` as "keeps them for ever, and the installation
//    allows that" was unreachable. An installation that chose for ever had every
//    transcript deleted at ten years.
rc_world($mysqli, $TK, '0', null);
rc_check('an installation that chooses "keep for ever" really keeps them for ever',
    TranscriptRetention::daysForSite(1) === 0, (string) TranscriptRetention::daysForSite(1));
rc_check('…and `installationAllowsForEver()` says so, where `installationAllows()` says 3650',
    TranscriptRetention::installationAllowsForEver() === true
    && TranscriptRetention::installationAllows() === TranscriptRetention::HARD_MAX_DAYS,
    (string) TranscriptRetention::installationAllows());

rc_world($mysqli, $TK, '0', '90');
rc_check('an organisation may still choose 90 days under an installation that allows for ever',
    TranscriptRetention::daysForSite(1) === 90, (string) TranscriptRetention::daysForSite(1));

rc_world($mysqli, $TK, '365', null);
rc_check('and with a real ceiling, "for ever" is not offered',
    TranscriptRetention::installationAllowsForEver() === false);

// 🪤 AN UNUSABLE OWN ROW MUST FOLLOW THE INSTALLATION, NOT THE BUILT-IN
//    DEFAULT — #668's first trap, and it bit this method's first version.
//    Answering the DAYS ceiling alone gave an installation that had chosen
//    "keep for ever" a period of 365 days for any organisation whose own row
//    held nonsense, because `installationAllows()` never answers 0. Both
//    ceiling questions are needed, and this is the only check that can tell.
rc_world($mysqli, $TK, '0', '-5');
rc_check('an unusable own figure under a "for ever" installation keeps them for ever',
    TranscriptRetention::daysForSite(1) === 0, (string) TranscriptRetention::daysForSite(1));
rc_world($mysqli, $TK, '30', 'twelve');
rc_check('…and under a real ceiling it follows that ceiling, never the 365-day default',
    TranscriptRetention::daysForSite(1) === 30, (string) TranscriptRetention::daysForSite(1));

// =============================================================================
rc_head('C. The save path: refuse with the reason, never clamp, and write nothing');
// =============================================================================
rc_world($mysqli, $TK, '90', null);

$refused = TranscriptRetention::saveForSite($mysqli, 1, 365);
rc_check('365 days is REFUSED on an installation allowing 90',
    $refused['saved'] === false && $refused['refusal'] === 'above-installation', $refused['message']);
rc_check('…the words say what the limit is and what to do about it',
    str_contains($refused['message'], '90 days') === true
    && str_contains($refused['message'], 'not saved') === true
    && str_contains($refused['message'], 'Ask whoever runs this installation') === true, $refused['message']);
rc_check('…and they never name the setting key or who set the limit',
    str_contains($refused['message'], $TK) === false
    && stripos($refused['message'], 'global administrator') === false, $refused['message']);
rc_check('…and NOTHING was written: no row for this organisation',
    TranscriptRetention::ownDays($mysqli, 1) === null,
    var_export(TranscriptRetention::ownDays($mysqli, 1), true));

$forEver = TranscriptRetention::saveForSite($mysqli, 1, 0);
rc_check('"for ever" is refused where the installation does not allow it',
    $forEver['saved'] === false && $forEver['refusal'] === 'above-installation', $forEver['message']);

$tooBig = TranscriptRetention::saveForSite($mysqli, 1, 99999);
rc_check('a figure above the hard maximum in code is refused, in DIFFERENT words',
    $tooBig['saved'] === false && $tooBig['refusal'] === 'above-hard-max'
    && str_contains($tooBig['message'], 'installation') === false, $tooBig['message']);

$ok = TranscriptRetention::saveForSite($mysqli, 1, 30);
rc_check('a SHORTER figure is stored',
    $ok['saved'] === true && TranscriptRetention::ownDays($mysqli, 1) === 30,
    var_export(TranscriptRetention::ownDays($mysqli, 1), true));
rc_check('…and the clear-out then uses it',
    TranscriptRetention::daysForSite(1) === 30, (string) TranscriptRetention::daysForSite(1));

$again = TranscriptRetention::saveForSite($mysqli, 1, 60);
rc_check('saving again CHANGES the one row rather than adding a second',
    $again['saved'] === true && TranscriptRetention::ownDays($mysqli, 1) === 60
    && rc_row_count($mysqli, $TK, 1) === 1, (string) rc_row_count($mysqli, $TK, 1));

$cleared = TranscriptRetention::saveForSite($mysqli, 1, null);
rc_check('an empty box removes the organisation\'s own row, so it follows the installation again',
    $cleared['saved'] === true && $cleared['cleared'] === true
    && TranscriptRetention::ownDays($mysqli, 1) === null
    && TranscriptRetention::daysForSite(1) === 90,
    var_export(TranscriptRetention::ownDays($mysqli, 1), true));

rc_world($mysqli, $TK, '0', null);
$forEverOk = TranscriptRetention::saveForSite($mysqli, 1, 0);
rc_check('"for ever" IS allowed where the installation itself allows for ever',
    $forEverOk['saved'] === true && TranscriptRetention::daysForSite(1) === 0, $forEverOk['message']);

/** How many rows of one setting one organisation has. */
function rc_row_count(mysqli $db, string $key, int $siteId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) AS n FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
    $stmt->bind_param('si', $key, $siteId);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();

    return $n;
}

// =============================================================================
rc_head('D. THE LOCK — a save waits for whoever is changing the ceiling, then obeys it');
// =============================================================================
// 🔒 WHY THIS IS HERE AT ALL (#687, measured rather than argued). A gate that
//    compares against the request's settings snapshot lets two overlapping
//    ordinary web requests store fifty years under a ceiling of seven — 60
//    pairs out of 60 — because neither sees what the other has just committed.
//    So the save re-reads the installation's row LIVE, `FOR UPDATE`, INSIDE the
//    transaction that writes. A lock outside a transaction is decoration (#697).
//
//    The second process holds that row and raises the ceiling to 7 while this
//    one tries to store 3000. With the lock, this one WAITS, then sees 7 and
//    refuses. Without it, it reads the old ceiling, succeeds, and the database
//    ends up holding 3000 under a ceiling of 7 — which is the fault.
//
//    ⚠️ WHAT THIS SECTION DOES NOT CATCH, measured by planting it: moving the
//       locked read OUTSIDE the transaction leaves every check here GREEN. A
//       `SELECT … FOR UPDATE` still blocks on the holder and still reads the
//       committed 7, so this scenario is answered correctly — what is lost is
//       that the lock is RELEASED at once by the implicit commit, leaving a
//       window between reading the ceiling and writing the figure. That is
//       #697's "a lock outside a transaction is decoration", and the only
//       thing that catches it is the ORDERING check in section E. Both are
//       needed, and neither covers the other's half.
rc_world($mysqli, $TK, '3650', null);

$holdMs  = 3000;
$cmd     = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
         . ' --hold-ceiling 7 ' . $holdMs;
$env     = [
    'SELFTEST_DB_NAME' => $dbName,
    'SELFTEST_DB_HOST' => $rcHost,
    'SELFTEST_DB_USER' => $rcUser,
    'SELFTEST_DB_PASS' => $rcPass,
    'SELFTEST_DB_PORT' => (string) $rcPort,
];
$pipes   = [];
$holder  = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env + $_ENV);
rc_check('the second process started, so there is really another connection holding the row',
    is_resource($holder) === true);

// Long enough for the holder to have taken the lock and raised the ceiling, and
// well short of the moment it commits.
usleep(800_000);

$started = microtime(true);
$raced   = TranscriptRetention::saveForSite($mysqli, 1, 3000);
$waited  = microtime(true) - $started;

if (is_resource($holder) === true) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($holder);
}

// ⚠️ THIS CHECK IS CONTEXT, NOT THE PROOF, and saying so is the point.
//    MEASURED: with ` FOR UPDATE` deleted from the locked read, this check still
//    PASSED — the save waited anyway, because the holder's `UPDATE` on
//    `uq_settings_key_site` locks the gap beside its own row, so the saver's
//    INSERT for organisation 1 blocked instead. So "it waited" cannot tell a
//    real lock from a coincidence of a neighbouring gap, and a test that stopped
//    here would have reported the lock as proved while the fault was live. The
//    THREE checks below are the discriminators: in the planted state they went
//    red with 3000 days STORED under a ceiling of 7 and the save reported as
//    successful — #687's fault, exactly.
rc_check('the save did not finish before the other connection committed',
    $waited > 1.0, 'it took ' . number_format($waited, 2) . 's, and the holder was still in its '
    . number_format($holdMs / 1000, 1) . 's window when this started');
rc_check('…and then refused, because by the time it could read, the ceiling was 7',
    $raced['saved'] === false && $raced['refusal'] === 'above-installation', $raced['message']);
rc_check('…naming the LIVE ceiling of 7, not the 3650 this request started with',
    str_contains($raced['message'], '7 days') === true
    && str_contains($raced['message'], '3650') === false, $raced['message']);
rc_check('…and 3000 is NOT in the database',
    TranscriptRetention::ownDays($mysqli, 1) === null,
    var_export(TranscriptRetention::ownDays($mysqli, 1), true));

// The committed ceiling really is 7 afterwards, so the holder did what it said
// it would. Without this the two checks above could pass on a holder that had
// quietly failed and a saver refusing for some other reason.
$check = $mysqli->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
$check->bind_param('s', $TK);
$check->execute();
$ceilingNow = (string) ($check->get_result()->fetch_assoc()['settingValue'] ?? '');
$check->close();
rc_check('the other connection\'s change really did commit, so the window was real',
    $ceilingNow === '7', $ceilingNow);

// =============================================================================
rc_head('E. The page asks both questions, and the handler acts on the answer');
// =============================================================================
// Sections A to D exercise behaviour. They cannot tell you the page still asks
// the right questions or that its handler acts on the refusal. Comments are
// stripped first, so a call that has been commented out never counts.
$rcStrip = static function (string $path): string {
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $t) {
        if (is_array($t) === true && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
            continue;
        }
        $out .= is_array($t) === true ? $t[1] : $t;
    }

    return $out;
};
$pageFile    = dirname(__DIR__) . '/web/_apps/admin/settings/transcription/index.php';
$handlerFile = dirname(__DIR__) . '/web/_apps/admin/settings/transcription/save.php';
$page        = $rcStrip($pageFile);
$handler     = $rcStrip($handlerFile);
$pageRaw     = (string) file_get_contents($pageFile);

// TWO QUESTIONS, NOT ONE. `min="0"` offers for-ever whatever the max says, so a
// page that asked only for the ceiling in days would offer a control its own
// Save refuses.
rc_check('the page asks what the installation ALLOWS, in days',
    str_contains($page, 'TranscriptRetention::installationAllows()') === true);
rc_check('…and SEPARATELY whether it allows "for ever"',
    str_contains($page, 'TranscriptRetention::installationAllowsForEver()') === true);
rc_check('the box\'s max is the installation\'s ceiling, never the hard maximum in code',
    preg_match('/max="<\?php echo \(int\) \$ceilingDays; \?>"/', $pageRaw) === 1);
rc_check('…and its min is 0 only where "for ever" is allowed',
    preg_match('/min="<\?php echo \$mayKeepForEver === true \? 0 : 1; \?>"/', $pageRaw) === 1);

// 🚨 NEVER PREFILL A VALUE THE BOX\'S OWN `max` FORBIDS. A browser then refuses
//    to submit the whole form, so the only way to use the page is to shorten
//    the period — the one thing an administrator must not be forced into. The
//    value shown is the stored figure held to the ceiling, and the whole
//    expression is pinned, because `min(` alone passes on `min($ownDays, 99999)`.
rc_check('the value shown is the stored figure held to the ceiling',
    preg_match('/\$shown\s*=\s*\(string\)\s*\(\s*\$mayKeepForEver\s*===\s*false\s*&&\s*\$ownDays\s*===\s*0\s*\?\s*\$ceilingDays\s*:\s*min\(\$ownDays,\s*\$ceilingDays\)\s*\)\s*;/', $page) === 1);
rc_check('…read at the organisation\'s OWN level, not from the merged settings',
    str_contains($page, 'TranscriptRetention::ownDays($mysqli, $siteId)') === true
    && str_contains($page, "App::settings('transcription") === false);
rc_check('…and the page says so when a stored figure is being held down',
    str_contains($page, '$heldDown') === true
    && str_contains($pageRaw, 'longer than this installation allows') === true);

// The handler must ASK WHETHER THE FIELD ARRIVED, never only what it holds: a
// disabled control is not submitted, and reading an absent field as empty is
// how `site.url` was cleared for every organisation once (#677).
rc_check('the handler asks whether the field ARRIVED before reading it',
    preg_match('/if\s*\(\s*array_key_exists\(\s*\x27transcriptDays\x27\s*,\s*\$_POST\s*\)\s*===\s*false\s*\)\s*\{/', $handler) === 1);
rc_check('the handler checks the form token BEFORE anything with a side effect',
    (($c = strpos($handler, 'Auth::verifyCsrf')) !== false)
    && (($l = strpos($handler, 'Logger::activity')) !== false)
    && (($s = strpos($handler, 'TranscriptRetention::saveForSite')) !== false)
    && $c < $l && $c < $s);

// 🚨 PIN THE WHOLE CONDITION, not the call. The independent check of #668 proved
//    that asking only whether the text is present passes on four different
//    disabled gates: `if (false)`, `&& false` added to the condition, a refusal
//    that never exits so it logs "nothing was saved" and then writes anyway, and
//    a refusal that silently CLAMPS instead.
rc_check('it hands the posted number to saveForSite() exactly as typed, or null for an empty box',
    preg_match('/\$result\s*=\s*TranscriptRetention::saveForSite\(\$mysqli,\s*\$siteId,\s*\$days\)\s*;/', $handler) === 1);
rc_check('…and the condition on the answer is EXACTLY "was it saved", with nothing added to weaken it',
    preg_match('/if\s*\(\s*\$result\[\x27saved\x27\]\s*===\s*false\s*\)\s*\{/', $handler) === 1);
rc_check('it does NOT convert 0 itself: the zero rule belongs in SettingLimit, read at both levels',
    preg_match('/\$days\s*===\s*0\s*\?\s*TranscriptRetention::HARD_MAX_DAYS/', $handler) !== 1);
rc_check('it RECORDS a refusal', str_contains($handler, "'TranscriptRetentionRefused'") === true);
rc_check('…and records a save too, so a log search by type is not a count of refusals only',
    str_contains($handler, "'TranscriptRetentionSaved'") === true);

// The refusal block must END the request. One that logs and falls through writes
// anyway while the record says nothing was saved — the worst of the four shapes.
$refusalBlock = (static function (string $code): string {
    $at = strpos($code, "if (\$result['saved'] === false) {");
    if ($at === false) {
        return '';
    }
    $close = strpos($code, "\n}", $at);

    return $close === false ? '' : substr($code, $at, $close - $at);
})($handler);
rc_check('the refusal block ends the request rather than falling through',
    $refusalBlock !== '' && str_contains($refusalBlock, 'exit();') === true,
    $refusalBlock === '' ? 'the block could not be found, so nothing was checked' : 'block is ' . strlen($refusalBlock) . ' chars');
// `=(?!=)` — an ASSIGNMENT, not a comparison. Without the lookahead this matches
// the `$days === 0` inside the refusal's own message and fails on correct code.
rc_check('…and it does not quietly CLAMP the number on the way past',
    $refusalBlock !== '' && preg_match('/\$days\s*=(?!=)/', $refusalBlock) !== 1);

// 🚧 #685's NOTICE HALF, on the attendance page. The cap SHORTENS retention of
//    data already held, so the page has to say when the number it shows is not
//    the number the organisation chose — otherwise it reads "90 days — your
//    organisation's own choice" to somebody who chose 1000, which is two true
//    halves making a false sentence. Nothing else checks that sentence.
$attendance = (string) file_get_contents(dirname(__DIR__) . '/web/_apps/admin/settings/attendance/index.php');
rc_check('the attendance page reads the stored figure at the organisation\'s OWN level',
    str_contains($attendance, 'AnonymousCheckins::ownRetentionDays($mysqli, $siteId)') === true);
rc_check('…and says so when that figure is being held down by the installation',
    preg_match('/\$retentionHeldDown\s*=\s*\$ownRetentionMeant\s*!==\s*null\s*&&\s*\$ownRetentionMeant\s*!==\s*\(int\)\s*\$effectiveRetention\s*;/', $attendance) === 1
    && str_contains($attendance, 'longer than this installation allows') === true);

// -----------------------------------------------------------------------------
// 🚧 "0 MEANS KEEP FOR EVER" — THE SENTENCE #685 MADE UNTRUE AND LEFT STANDING
// -----------------------------------------------------------------------------
// Measured on the rule itself: 0 is the LARGEST value for this setting, so it
// survives ONLY where the installation allows for ever. With the installation
// at 90 — which is what `full_schema.sql` seeds, so every fresh installation —
// an organisation storing 0 is cleared at 90 DAYS. Yet
// `admin/maintenance/retention.php` went on ending its paragraph "Each
// organisation uses its own setting, and 0 means keep for ever", and the
// personal-data catalogue said the same in BOTH its `reason` and its `period`.
//
// Those last two feed `inventory()`, which is the "what we hold about you"
// answer a person receives, so the overstatement was a promise made to a data
// subject. #706's own rule is that a `period` must be checked against the
// purging code; #685 changed the purging code.
//
// ⚠️ THE CHECK IS A POSITIVE CLAUSE FIRST, THEN THE ABSENCE. A check made only
//    of absences passes when the sentence stops being printed at all (#694's
//    section-P lesson), and this page's whole job is to say what the clear-out
//    will do — so it has to still SAY something. The forbidden wording is the
//    bare assertion with no condition attached; the conditional forms
//    ("only where the installation allows it", "does NOT mean keep for ever
//    here") are what the page and the catalogue say now and must keep saying.
// ⚠️ THE CARD IS READ WITH ITS PHP COMMENTS STRIPPED, and the first version of
//    this check was not — so it went red on the correct page, because the
//    comment explaining the fix QUOTES the untrue sentence it replaced. That
//    quote is the valuable part of the comment (it is how the next reader knows
//    what not to put back), so the check has to look at the PROSE the page
//    prints, not at the file.
$maintenanceCard = $rcStrip(dirname(__DIR__) . '/web/_apps/admin/maintenance/retention.php');
rc_check('the maintenance card still states what happens to anonymous check-in detail at all',
    str_contains($maintenanceCard, 'attend.detailRetentionDays') === true
    && str_contains($maintenanceCard, 'AnonymousCheckins::readRetentionDays(Site::id())') === true);
rc_check('…and asks the installation\'s own allowance rather than describing it',
    str_contains($maintenanceCard, 'SettingLimit::installationAllowsForEver(') === true
    && str_contains($maintenanceCard, 'SettingLimit::installationAllows(') === true);
rc_check('…and never asserts bare "0 means keep for ever", which #685 made untrue',
    str_contains($maintenanceCard, 'its own setting, and 0 means keep for ever') === false
    && str_contains($maintenanceCard, '0 means keep for ever.') === false);
// ⚠️ THE CATALOGUE IS ASKED FOR ITS ASSEMBLED VALUES, NEVER SEARCHED AS SOURCE.
//    The first version of these three searched the file for
//    'only where the installation allows it too' and went red on the correct
//    entry, because the sentence is built from two concatenated string
//    literals and the phrase straddles the join. That is the repository's own
//    rule — print the outputs by calling the real code rather than
//    transcribing them — and it matters more here than usual, because these
//    two sentences are what `inventory()` hands a data subject.
$rcCatalogueEntry = (require $rcCore . 'personal-data-catalogue.php')['tblAnonymousCheckins'];
$rcReason = (string) $rcCatalogueEntry['reason'];
$rcPeriod = (string) $rcCatalogueEntry['period'];
rc_check('the personal-data catalogue still gives tblAnonymousCheckins a period at all',
    str_contains($rcPeriod, 'attend.detailRetentionDays') === true
    && str_contains($rcPeriod, '90 days by default') === true);
rc_check('…and neither its reason nor its period claims 0 keeps for ever unconditionally',
    str_contains($rcReason, '0 means keep for ever') === false
    && str_contains($rcPeriod, '0 means keep for ever') === false
    && str_contains($rcPeriod, '0 = keep for ever') === false);
rc_check('…and both name the installation\'s cap, which is the only true form',
    str_contains($rcReason, 'held down to') === true
    && str_contains($rcPeriod, 'only where the installation allows it too') === true,
    $rcPeriod);

// 🔒 And the lock itself, pinned WHOLE: the five words are what make section D
//    able to fail, and ` FOR UPDATE` is one edit away from being gone.
$retention = (string) file_get_contents($rcCore . 'TranscriptRetention.php');
rc_check('the live ceiling is read with the installation\'s own row LOCKED',
    str_contains($retention, "'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL FOR UPDATE'") === true);
rc_check('…and the transaction is opened BEFORE that read, because a lock outside one is decoration',
    (($b = strpos($retention, '$db->begin_transaction();')) !== false)
    && (($r = strpos($retention, 'self::installationCeilingLocked($db)')) !== false)
    && $b < $r, 'begin at ' . var_export($b ?? null, true) . ', locked read at ' . var_export($r ?? null, true));
rc_check('…and the refusal inside the transaction rolls it back',
    preg_match('/if\s*\(\$reason\s*!==\s*\x27\x27\)\s*\{\s*\$db->rollback\(\);/', $rcStrip($rcCore . 'TranscriptRetention.php')) === 1);

// =============================================================================
rc_head('F. #491 — the activity-log and error clear-outs, one organisation at a time');
// =============================================================================
// WHAT WAS WRONG. Both clear-outs read ONE pair of settings through
// `App::settings()`, which answers the CURRENT organisation's value, and then
// deleted across the whole installation. So an administrator of one
// organisation choosing "keep activity logs for 7 days" shortened every other
// organisation's history, including one that had deliberately chosen a year —
// and run from the scheduled job, which has no current organisation at all,
// whichever value happened to resolve decided for everybody.
//
// WHY THIS NEEDS REAL ROWS. The fix is not a rule to be read off a fixture: it
// is which rows a DELETE reaches. Both tables carry a `siteID` (checked in the
// schema, not assumed), and the checks below are the only thing that can tell
// a delete limited to one organisation from one that is not.
//
// THE THREE KINDS OF ROW, and the third is the one a per-organisation loop
// quietly loses: a row of organisation 1's, a row of organisation 2's, and a
// row belonging to NO organisation. HOW a row comes to have no organisation
// differs by table, and this used to say "written before the organisation was
// decided, or by a scheduled job" for both — which is untrue of the activity
// log (#940 measured it; corrected here by #947):
//   - `tblActivityLogs`: `Logger::activity()` binds `Site::id()`, a whole
//     number from 1 on every request and job, and `fk_logs_site` refuses 0 or
//     an organisation that does not exist. So a new record always has one. An
//     empty `siteID` comes only from the key's `ON DELETE SET NULL` (its
//     organisation was deleted), or from `ActivityLogCleaner` copying the
//     empty `siteID` of such a row.
//   - `tblErrors`: the same `ON DELETE SET NULL` (`fk_errors_site`), and also
//     when `Site` itself could not be loaded (`Logger::errorPlatform()` then
//     records "not known") or when a caller deliberately names no
//     organisation (`Logger::errorPlatformForSite(null, ...)`).
// The rows below are written with an empty `siteID` directly, which is the
// state those paths leave. They follow the INSTALLATION's figure; a loop over
// `tblSites` alone would keep them for ever.
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps'
    . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . '_retention-sweep.php';

/** Put one row into one of the two log tables, so many days old. */
$rcLogRow = static function (string $table, string $dateColumn, ?int $siteId, int $daysOld) use ($mysqli): int {
    $stmt = $mysqli->prepare(
        'INSERT INTO `' . $table . '` (`siteID`, `' . $dateColumn . '`) '
        . 'VALUES (?, DATE_SUB(NOW(), INTERVAL ? DAY))'
    );
    $stmt->bind_param('ii', $siteId, $daysOld);
    $stmt->execute();
    $id = (int) $mysqli->insert_id;
    $stmt->close();

    return $id;
};

/** Which of those rows are still there, by their own numbers. */
$rcStillThere = static function (string $table, string $idColumn, array $ids) use ($mysqli): array {
    if ($ids === []) {
        return [];
    }
    $left = [];
    $rows = $mysqli->query(
        'SELECT `' . $idColumn . '` AS id FROM `' . $table . '` '
        . 'WHERE `' . $idColumn . '` IN (' . implode(', ', array_map('intval', $ids)) . ') '
        . 'ORDER BY `' . $idColumn . '`'
    );
    while ($one = $rows->fetch_assoc()) {
        $left[] = (int) $one['id'];
    }
    $rows->free();

    return $left;
};

// 🏢 The installation allows 30 days. Organisation 1 holds ITSELF to 7.
//    Organisation 2 has 1000 days stored — a figure no page would accept, put
//    there by SQL as #685's rows are, because that is the state the issue is
//    about — so it is held to the installation's 30.
rc_world($mysqli, 'audit.retentionDays', '30', '7');
$ins = $mysqli->prepare('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (2, \'audit.retentionDays\', \'1000\', \'\', 0)');
$ins->execute();
$ins->close();

rc_check('organisation 1 keeps activity logs for the 7 days it chose for itself',
    log_retention_days('audit.retentionDays', 1) === 7, (string) log_retention_days('audit.retentionDays', 1));
rc_check('organisation 2\'s stored 1000 days is held to the installation\'s 30',
    log_retention_days('audit.retentionDays', 2) === 30, (string) log_retention_days('audit.retentionDays', 2));
rc_check('rows that belong to no organisation follow the installation\'s own 30',
    log_retention_installation_days('audit.retentionDays') === 30,
    (string) log_retention_installation_days('audit.retentionDays'));

$mysqli->query('DELETE FROM `tblActivityLogs`');
$rcAct = [];
foreach ([1, 2, null] as $owner) {
    foreach ([3, 10, 60] as $age) {
        $rcAct[($owner === null ? 'none' : 'site' . $owner) . '-' . $age] = $rcLogRow('tblActivityLogs', 'timestamp', $owner, $age);
    }
}

// 📊 The count an administrator is shown, and the delete, must agree — they go
//    through the one function, with `$reallyDelete` the only difference.
$rcPreview = log_sweep('activity', false);
rc_check('the count is four: organisation 1\'s 10- and 60-day rows at its own 7 days, and one 60-day row each for organisation 2 and for no organisation at 30',
    $rcPreview === 4, (string) $rcPreview);

$rcDeleted = log_sweep('activity', true);
rc_check('the delete removes exactly those four', $rcDeleted === 4, (string) $rcDeleted);

$rcLeft = $rcStillThere('tblActivityLogs', 'logID', array_values($rcAct));
$rcExpectLeft = [$rcAct['site1-3'], $rcAct['site2-3'], $rcAct['site2-10'], $rcAct['none-3'], $rcAct['none-10']];
sort($rcExpectLeft);
// 🚨 THE DECISIVE CHECK, and it is the one the old code fails. Organisation
//    2's 10-day row and the 10-day row belonging to no organisation must
//    SURVIVE while organisation 1's 10-day row goes: one figure for the whole
//    installation cannot produce that, whichever figure it picks.
rc_check('…and organisation 2\'s 10-day row and the 10-day row owned by nobody are still there, while organisation 1\'s is gone',
    $rcLeft === $rcExpectLeft, 'left: ' . json_encode($rcLeft) . ' expected: ' . json_encode($rcExpectLeft));
rc_check('nothing was left for organisation 1 past its own seven days',
    $rcStillThere('tblActivityLogs', 'logID', [$rcAct['site1-10'], $rcAct['site1-60']]) === [],
    json_encode($rcStillThere('tblActivityLogs', 'logID', [$rcAct['site1-10'], $rcAct['site1-60']])));

// 🪤 AND THE CAP MUST NOT BE A CLAMP TO ONE NUMBER. Organisation 1 holding
//    itself to 7 days is proved above; this is the other direction — an
//    organisation with NO figure of its own follows the installation, never
//    the built-in year (#668's first trap).
$mysqli->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'audit.retentionDays' AND `siteID` = 1");
rc_check('an organisation with no figure of its own follows the installation\'s 30, not the built-in 365',
    log_retention_days('audit.retentionDays', 1) === 30, (string) log_retention_days('audit.retentionDays', 1));

// ♾️ #932 (the owner, 10 November 2026 — D-117): FOR THE ACTIVITY LOG A
//    STORED 0 NOW MEANS "NEVER DELETE". Until then it fell back to a year, and
//    this block pinned that ("an installation storing 0 falls back to a year
//    rather than keeping logs for ever"). That was never an owner's decision,
//    and D-117 reverses it for the activity log ONLY. So 0 is the LARGEST
//    value, exactly as for the attendance, webhook and transcript periods:
//      - an installation at never lets an organisation choose any number of
//        days, or never;
//      - an installation with a number of days holds every organisation to it,
//        a stored never included (the READER caps — a save-time refusal is
//        not a cap, #668);
//      - never is NOT clamped to the 3650-day hard maximum (D-117 names that).
//    The error log keeps the old rule; its pins follow this block.
$AL = 'audit.retentionDays';
rc_world($mysqli, $AL, '0', null);
rc_check('#932: an installation storing 0 NEVER deletes its activity log (0), not a year and not 3650 days',
    log_retention_installation_days($AL) === 0, (string) log_retention_installation_days($AL));
rc_check('…and an organisation with no figure of its own follows it: never',
    log_retention_days($AL, 1) === 0, (string) log_retention_days($AL, 1));
rc_world($mysqli, $AL, '0', '0');
rc_check('…an organisation that chose never under it keeps never',
    log_retention_days($AL, 1) === 0, (string) log_retention_days($AL, 1));
rc_world($mysqli, $AL, '0', '45');
rc_check('…and one that chose 45 days under it keeps 45 (never above, any number of days below)',
    log_retention_days($AL, 1) === 45, (string) log_retention_days($AL, 1));
rc_world($mysqli, $AL, '0', '99999');
rc_check('…while a NUMBER above the hard maximum is still held to 3650 days — a number is not never',
    log_retention_days($AL, 1) === 3650, (string) log_retention_days($AL, 1));
rc_world($mysqli, $AL, '30', '0');
rc_check('#932, the other direction: an organisation storing never under an installation of 30 days is held to 30',
    log_retention_days($AL, 1) === 30, (string) log_retention_days($AL, 1));
rc_world($mysqli, $AL, '99999', null);
rc_check('a figure above the ten-year hard maximum is held to it',
    log_retention_installation_days($AL) === 3650,
    (string) log_retention_installation_days($AL));

// 🧹 AND THE SWEEP DELETES NOTHING UNDER NEVER, on real rows. Organisation 1
//    follows an installation at never, organisation 2 chose never itself, and
//    rows owned by nobody follow the installation: three ways to reach never,
//    and none of the 4000-day-old rows may go — counted OR deleted.
$mysqli->query('DELETE FROM `tblActivityLogs`');
rc_world($mysqli, $AL, '0', null);
$rcIns2 = $mysqli->prepare('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (2, \'audit.retentionDays\', \'0\', \'\', 0)');
$rcIns2->execute();
$rcIns2->close();
$rcNever = [];
foreach ([1, 2, null] as $owner) {
    $rcNever[] = $rcLogRow('tblActivityLogs', 'timestamp', $owner, 4000);
}
sort($rcNever);
rc_check('#932: under never, the clear-out COUNTS nothing to delete',
    log_sweep('activity', false) === 0, (string) log_sweep('activity', false));
rc_check('…and DELETES nothing: all three 4000-day-old rows are still there',
    log_sweep('activity', true) === 0 && $rcStillThere('tblActivityLogs', 'logID', $rcNever) === $rcNever,
    json_encode($rcStillThere('tblActivityLogs', 'logID', $rcNever)));

// …but an organisation that chose a number of days under an installation at
//    never is still cleared by ITS number — never is not a blanket switch.
rc_world($mysqli, $AL, '0', '7');
$rcIns2 = $mysqli->prepare('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (2, \'audit.retentionDays\', \'0\', \'\', 0)');
$rcIns2->execute();
$rcIns2->close();
rc_check('…while organisation 1, which chose 7 days under that installation, has exactly its own old row deleted',
    log_sweep('activity', true) === 1
    && $rcStillThere('tblActivityLogs', 'logID', $rcNever) === array_values(array_diff($rcNever, [$rcNever[0]])),
    json_encode($rcStillThere('tblActivityLogs', 'logID', $rcNever)));
$mysqli->query('DELETE FROM `tblActivityLogs`');

// 💾 THE SAVE PATH (`ActivityLogRetention`, the ONE writer). The rule is
//    SettingLimit's, asked with the installation's row read LIVE and LOCKED;
//    each case reads the stored row back rather than believing the answer.
$rcAlSetting = static function (?int $siteId) use ($mysqli): ?string {
    $stmt = $mysqli->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = \'audit.retentionDays\' AND `siteID` '
        . ($siteId === null ? 'IS NULL' : '= ?'));
    if ($siteId !== null) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? null : (string) $row['settingValue'];
};
rc_world($mysqli, $AL, '30', null);
$rcSave = \Portal\Core\ActivityLogRetention::saveForSite($mysqli, 1, 0, true);
rc_check('#932: under an installation of 30 days an organisation\'s never is REFUSED with the reason, and nothing is stored',
    $rcSave['outcome'] === 'refused' && $rcSave['refusal'] === 'above-installation' && $rcAlSetting(1) === null
    && str_contains($rcSave['message'], 'does not allow "Never delete"') === true && str_contains($rcSave['message'], 'between 1 and 30') === true,
    json_encode($rcSave) . ' stored: ' . var_export($rcAlSetting(1), true));
$rcSave = \Portal\Core\ActivityLogRetention::saveForSite($mysqli, 1, 31, true);
rc_check('…and so are 31 days, never clamped to 30',
    $rcSave['outcome'] === 'refused' && $rcAlSetting(1) === null, json_encode($rcSave));
$rcSave = \Portal\Core\ActivityLogRetention::saveForSite($mysqli, 1, 20, true);
rc_check('…while 20 days is stored',
    $rcSave['outcome'] === 'stored' && $rcAlSetting(1) === '20', json_encode($rcSave));
rc_world($mysqli, $AL, '0', null);
$rcSave = \Portal\Core\ActivityLogRetention::saveForSite($mysqli, 1, 0, true);
rc_check('under an installation at never, the same organisation\'s never IS stored, as 0',
    $rcSave['outcome'] === 'stored' && $rcAlSetting(1) === '0', json_encode($rcSave));
$rcSave = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 3651, true);
rc_check('the installation\'s 3651 days is refused (above the hard maximum), and its stored never is untouched — not clamped to 3650',
    $rcSave['outcome'] === 'refused' && $rcSave['refusal'] === 'above-hard-max' && $rcAlSetting(null) === '0',
    json_encode($rcSave) . ' stored: ' . var_export($rcAlSetting(null), true));
// 🚫 D-119 (the owner, 10 November 2026): lowering the installation below a
//    figure an organisation has ALREADY chosen is REFUSED, naming it — never
//    counting as the longest — and nothing is deleted until that organisation
//    lowers its own first. (Until D-119 this check asserted the opposite: the
//    lowering was allowed and the organisation silently held to it.)
$rcSite1Name = (string) ($mysqli->query('SELECT `siteName` FROM `tblSites` WHERE `siteID` = 1')->fetch_assoc()['siteName'] ?? '');
$rcSave = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 30, true);
rc_check('D-119: lowering the installation from never to 30 days is REFUSED while organisation 1 holds never, naming it, and nothing changes',
    $rcSave['outcome'] === 'refused' && $rcSave['refusal'] === 'organisations-above' && $rcSave['blockedOrganisations'] === 1
    && $rcSite1Name !== '' && str_contains($rcSave['message'], $rcSite1Name . ' (never deleted)') === true
    && $rcAlSetting(null) === '0' && $rcAlSetting(1) === '0',
    json_encode($rcSave) . ' stored: ' . var_export([$rcAlSetting(null), $rcAlSetting(1)], true));
\Portal\Core\ActivityLogRetention::saveForSite($mysqli, 1, 400, true);
$rcSave = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 365, true);
rc_check('…and so is 365 days while it holds 400 — "below" means shorter than ANY figure an organisation chose',
    $rcSave['outcome'] === 'refused' && $rcAlSetting(null) === '0' && str_contains($rcSave['message'], '(400 days)') === true,
    json_encode($rcSave));
$rcSave = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 400, true);
rc_check('…while 400 days, equal to the longest figure chosen, is stored: nobody is above it',
    $rcSave['outcome'] === 'stored' && $rcAlSetting(null) === '400', json_encode($rcSave));
// 🪤 NEVER A FALSE REFUSAL (the giving-records lesson): an untouched Save of
//    the installation's figure is not judged, even where an organisation holds
//    MORE than it — a state only SQL or a restore can produce, and the reader
//    holds that organisation down meanwhile. Refusing it would block the page.
rc_world($mysqli, $AL, '30', '400');
$rcSave = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 30, true);
rc_check('…and an untouched Save of the installation\'s 30, with an organisation holding 400 by SQL, is NOT refused: "nothing was different"',
    $rcSave['outcome'] === 'unchanged' && $rcAlSetting(null) === '30', json_encode($rcSave));
rc_world($mysqli, $AL, '400', null);
$rcSave = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 30, true);
rc_check('once the organisation follows the installation again, lowering to 30 days is accepted — a lowering nobody blocks',
    $rcSave['outcome'] === 'stored' && $rcAlSetting(null) === '30', json_encode($rcSave));
$rcSave = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 0, true);
rc_check('and the installation\'s never is stored as 0, never as 3650 — never shortens nobody, so it is never refused',
    $rcSave['outcome'] === 'stored' && $rcAlSetting(null) === '0', json_encode($rcSave));
// 🪤 "CHOOSING NEVER IS NEVER REFUSED" — with organisations HOLDING numbers of
//    days, because that is the only state in which a wrong refusal could happen
//    (check round 2, plant K: judging never like any other figure left every
//    earlier check green, since each installation-never save ran with no
//    organisation rows at all). Never is longer than any number of days, so it
//    can shorten nobody, and both organisations keep their own figures.
rc_world($mysqli, $AL, '30', '20');
$rcIns15 = $mysqli->prepare('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (2, \'audit.retentionDays\', \'15\', \'\', 0)');
$rcIns15->execute();
$rcIns15->close();
$rcSave = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 0, true);
rc_check('the installation\'s NEVER is stored while organisations hold 20 and 15 days, refusing nothing and leaving both figures alone',
    $rcSave['outcome'] === 'stored' && $rcSave['blockedOrganisations'] === 0 && $rcAlSetting(null) === '0'
    && $rcAlSetting(1) === '20' && $rcAlSetting(2) === '15',
    json_encode($rcSave) . ' stored: ' . var_export([$rcAlSetting(null), $rcAlSetting(1), $rcAlSetting(2)], true));
rc_world($mysqli, $AL, '0', null);
$rcSave = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 30, false);
$rcSave2 = \Portal\Core\ActivityLogRetention::saveForSite($mysqli, 1, 5, false);
rc_check('the installation\'s save refuses anybody not a global administrator, and an organisation\'s refuses anybody not its administrator (D-118)',
    $rcSave['outcome'] === 'refused' && $rcSave2['outcome'] === 'refused' && $rcAlSetting(null) === '0' && $rcAlSetting(1) === null,
    json_encode([$rcSave, $rcSave2]));

// 🚫 D-119: IF THE ORGANISATIONS' FIGURES CANNOT BE READ, THE LOWERING IS
//    REFUSED — never treated as "nobody is above it". Made real by renaming
//    `tblSites` (which only that read touches) for the length of one save, and
//    put back in a `finally` so a failure here cannot leave the database broken.
rc_world($mysqli, $AL, '0', '0');
$mysqli->query('RENAME TABLE `tblSites` TO `tblSitesP932Away`');
try {
    $rcBlind = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 30, true);
} finally {
    $mysqli->query('RENAME TABLE `tblSitesP932Away` TO `tblSites`');
}
rc_check('D-119: when the organisations\' own figures cannot be read, lowering the installation is REFUSED and nothing is stored',
    $rcBlind['outcome'] === 'refused' && $rcBlind['refusal'] === 'database' && $rcAlSetting(null) === '0',
    json_encode($rcBlind) . ' stored: ' . var_export($rcAlSetting(null), true));
$mysqli->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'audit.retentionDays' AND `siteID` IS NOT NULL");

// 🔒 D-119's RACE (#687's lesson). An organisation's save in flight — holding
//    the installation's row, as saveForSite() does, and writing "never" — must
//    make a lowering WAIT and then see the never and refuse. Driven with a
//    second PROCESS, because one process cannot block itself. Without the
//    locked, live read the lowering reads a snapshot, misses the uncommitted
//    never, and stores 30 under it.
rc_world($mysqli, $AL, '0', null);
$rcHoldCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --hold-activity 2500';
$rcHoldPipes = [];
$rcHolder = proc_open($rcHoldCmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rcHoldPipes, dirname(__DIR__), [
    'SELFTEST_DB_NAME' => $dbName, 'SELFTEST_DB_HOST' => $rcHost, 'SELFTEST_DB_USER' => $rcUser,
    'SELFTEST_DB_PASS' => $rcPass, 'SELFTEST_DB_PORT' => (string) $rcPort,
] + $_ENV);
usleep(800_000);
$rcRaceStart = microtime(true);
$rcRaced     = \Portal\Core\ActivityLogRetention::saveInstallation($mysqli, 30, true);
$rcRaceWait  = microtime(true) - $rcRaceStart;
if (is_resource($rcHolder) === true) {
    fclose($rcHoldPipes[1]);
    fclose($rcHoldPipes[2]);
    proc_close($rcHolder);
}
rc_check('D-119 RACE: a lowering made while an organisation\'s "never" is being saved WAITS for it, then refuses — the installation stays never',
    $rcRaceWait > 1.0 && $rcRaced['outcome'] === 'refused' && $rcAlSetting(null) === '0' && $rcAlSetting(1) === '0',
    sprintf('waited %.2fs; ', $rcRaceWait) . json_encode($rcRaced) . ' stored: ' . var_export([$rcAlSetting(null), $rcAlSetting(1)], true));

// 🔒 AND THE RACE THE OTHER WAY (check round 1, finding M1). The installation is
//    being lowered from never to 30 in another, uncommitted transaction; an
//    organisation's "never" saved meanwhile must WAIT, then judge against 30 and
//    REFUSE, storing nothing. "It waited" is context only: the decisive half is
//    that NO organisation row was written. Measured: with ` FOR UPDATE` taken
//    out of storedValue() alone, every other check in this file stayed green.
rc_world($mysqli, $AL, '0', null);
$rcHoldCmd2 = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --hold-installation 2500';
$rcHoldPipes2 = [];
$rcHolder2 = proc_open($rcHoldCmd2, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rcHoldPipes2, dirname(__DIR__), [
    'SELFTEST_DB_NAME' => $dbName, 'SELFTEST_DB_HOST' => $rcHost, 'SELFTEST_DB_USER' => $rcUser,
    'SELFTEST_DB_PASS' => $rcPass, 'SELFTEST_DB_PORT' => (string) $rcPort,
] + $_ENV);
usleep(800_000);
$rcRace2Start = microtime(true);
$rcRaced2     = \Portal\Core\ActivityLogRetention::saveForSite($mysqli, 1, 0, true);
$rcRace2Wait  = microtime(true) - $rcRace2Start;
if (is_resource($rcHolder2) === true) {
    fclose($rcHoldPipes2[1]);
    fclose($rcHoldPipes2[2]);
    proc_close($rcHolder2);
}
rc_check('RACE, the other way: an organisation\'s "never" saved while the installation is being lowered to 30 days WAITS, then is refused, and NO organisation row is written',
    $rcRace2Wait > 1.0 && $rcRaced2['outcome'] === 'refused' && $rcAlSetting(null) === '30' && $rcAlSetting(1) === null,
    sprintf('waited %.2fs; ', $rcRace2Wait) . json_encode($rcRaced2) . ' stored: ' . var_export([$rcAlSetting(null), $rcAlSetting(1)], true));
$mysqli->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'audit.retentionDays' AND `siteID` IS NOT NULL");

// 🚨 THE ERROR LOG DID NOT CHANGE, and these pins are what stop a shared
//    helper drifting: #932 touches `log_retention_installation_days()` and
//    `log_retention_days()`, which the error log uses too. For `tblErrors` a
//    stored 0 STILL falls back to a year — D-117 rules out extending "never"
//    to it — and the sweep still deletes under it.
$EK = 'errors.retentionDays';
rc_world($mysqli, $EK, '0', null);
rc_check('the ERROR log: an installation storing 0 still falls back to a year (365), not never',
    log_retention_installation_days($EK) === 365, (string) log_retention_installation_days($EK));
rc_check('…and an organisation following it gets 365 too, not never',
    log_retention_days($EK, 1) === 365, (string) log_retention_days($EK, 1));
rc_world($mysqli, $EK, '30', '0');
rc_check('…and an organisation storing 0 follows the installation\'s 30, rather than clearing everything or keeping for ever',
    log_retention_days($EK, 1) === 30, (string) log_retention_days($EK, 1));
rc_world($mysqli, $EK, '0', null);
$mysqli->query('DELETE FROM `tblErrors`');
$rcErrOld = $rcLogRow('tblErrors', 'createdAt', null, 400);
$rcErrNew = $rcLogRow('tblErrors', 'createdAt', null, 300);
rc_check('…and on real rows: under a stored 0 the error clear-out deletes a 400-day-old row and keeps a 300-day-old one',
    log_sweep('errors', true) === 1 && $rcStillThere('tblErrors', 'errorID', [$rcErrOld, $rcErrNew]) === [$rcErrNew],
    json_encode($rcStillThere('tblErrors', 'errorID', [$rcErrOld, $rcErrNew])));
$mysqli->query('DELETE FROM `tblErrors`');

// 🚨 THE ERROR LOG IS A SECOND TABLE WITH A SECOND SETTING AND A DIFFERENT
//    DATE COLUMN, and it is checked on rows too rather than assumed to follow:
//    one pairing written wrongly is how a page comes to promise one number and
//    the job to remove another.
rc_world($mysqli, 'errors.retentionDays', '30', '7');
$mysqli->query('DELETE FROM `tblErrors`');
$rcErr = [];
foreach ([1, null] as $owner) {
    foreach ([10, 60] as $age) {
        $rcErr[($owner === null ? 'none' : 'site' . $owner) . '-' . $age] = $rcLogRow('tblErrors', 'createdAt', $owner, $age);
    }
}
$rcErrCount = log_sweep('errors', false);
rc_check('the error log is counted by its own setting and its own date column: three of four rows are past their day',
    $rcErrCount === 3, (string) $rcErrCount);
rc_check('…and the delete leaves only the 10-day row that belongs to no organisation, which the installation keeps for 30',
    log_sweep('errors', true) === 3
    && $rcStillThere('tblErrors', 'errorID', array_values($rcErr)) === [$rcErr['none-10']],
    json_encode($rcStillThere('tblErrors', 'errorID', array_values($rcErr))));

// 📄 And the two callers, read as text. Weaker than the checks above, and here
//    for one thing they cannot see: that the sweep and the page go through this
//    function at all rather than reading the merged settings array again.
$rcSweepSource = $rcStrip(dirname(__DIR__) . '/web/_apps/cron/_retention-sweep.php');
rc_check('the sweep and its preview both go through log_sweep()',
    substr_count($rcSweepSource, "log_sweep('activity'") === 2
    && substr_count($rcSweepSource, "log_sweep('errors'") === 2,
    (string) substr_count($rcSweepSource, 'log_sweep('));
rc_check('…and neither reads the merged settings array for either period any more',
    str_contains($rcSweepSource, "App::settings('audit.retentionDays')") === false
    && str_contains($rcSweepSource, "App::settings('errors.retentionDays')") === false);
$rcRetentionPage = $rcStrip(dirname(__DIR__) . '/web/_apps/admin/maintenance/retention.php');
rc_check('the page shows its own organisation\'s figure through the same function, and the installation\'s beside it',
    str_contains($rcRetentionPage, "log_retention_days('audit.retentionDays', Site::id())") === true
    && str_contains($rcRetentionPage, "log_retention_installation_days('audit.retentionDays')") === true
    && str_contains($rcRetentionPage, "App::settings('audit.retentionDays')") === false);

// =============================================================================
rc_head('G. #810 — a registration\'s period is measured on its EVENT\'s own clock');
// =============================================================================
// WHAT WAS WRONG. The clear-out deleted a registration once
//     GREATEST(COALESCE(end, start), start) < DATE_SUB(NOW(), INTERVAL n DAY)
// — but `startDateTime`/`endDateTime` are readings of the EVENT's clock
// (`tblEvents.timezone`), while `NOW()` is the DATABASE SERVER's, in its session
// zone. A reading on a clock BEHIND the server's is a smaller number for the
// same moment, so it crossed the deadline EARLY by the difference: on a database
// in UTC, an `Etc/GMT+12` event's registrations went twelve hours before the
// period was up, a New York one's four or five. (The issue's own text has the
// direction the other way round — "ahead of the server" — and the premise
// checks below are what settle it: an event AHEAD of the server, Kiritimati
// here, was deleted LATE, which is harmless; the early side is the one that
// breaks the promise.)
//
// WHY REAL ROWS, THE REAL SWEEP, AND A FIXED READING. Which rows a DELETE
// reaches is the fix, so nothing short of the real `registration_sweep()`
// against real rows can show it. The time of day cannot be fixed, so each
// reading is built FROM the zone's own clock now, a few hours either side of
// its deadline; an hour of margin is far more than the run takes.
//
// THE PREMISE IS ASSERTED FIRST, with the OLD expression run beside the new
// one on the very same rows: if the old expression did not delete the
// "not yet due" rows early, the survival checks below would prove nothing.
require_once $rcCore . 'Site.php';
require_once $rcCore . 'I18n.php';
require_once $rcCore . 'Events.php';

// 🌐 The database on UTC, which is the ordinary case on a host and the one the
//    issue measured. The sweep reads App::db(), which is this connection.
$mysqli->query("SET time_zone = '+00:00'");

/** Every zone's clock now, as a reading, the way the portal reads one. */
$rcClockNow = static fn (string $zone): string => \Portal\Core\I18n::wallClockNow(new DateTimeZone($zone));

/**
 * A reading `$days` days and `$hours` hours away from `$reading`, by WALL-CLOCK
 * arithmetic — what MySQL's DATE_SUB/DATE_ADD do to a DATETIME. Worked in UTC
 * because UTC has no transitions, so a reading put in comes straight back out
 * moved by exactly that much (#777's rule).
 */
$rcShift = static function (string $reading, int $days, int $hours): string {
    return (new DateTimeImmutable($reading, new DateTimeZone('UTC')))
        ->modify(sprintf('%+d days', $days))->modify(sprintf('%+d hours', $hours))
        ->format('Y-m-d H:i:s');
};

$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$mysqli->query('DELETE FROM `tblEventRegistrations`');
$mysqli->query("DELETE FROM `tblEvents` WHERE `eventSlug` LIKE 'rc810-%'");
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

/**
 * One event that ENDED at `$endReading` on its own clock in `$zone`, keeping
 * registrations for `$ownDays` of its own (null: the organisation's figure),
 * with one registration on it. The start is two hours before the end.
 *
 * @return int The registration's number.
 */
$rcRegistration = static function (string $slug, int $siteId, string $zone, string $endReading, ?int $ownDays = 30) use ($mysqli, $rcShift): int {
    $start = $rcShift($endReading, 0, -2);
    $name  = 'Selftest #810 ' . $slug;
    $stmt  = $mysqli->prepare(
        'INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `endDateTime`, `timezone`, `registrationRetentionDays`) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssssi', $siteId, $name, $slug, $start, $endReading, $zone, $ownDays);
    $stmt->execute();
    $eventId = (int) $mysqli->insert_id;
    $stmt->close();

    $reg = $mysqli->prepare('INSERT INTO `tblEventRegistrations` (`eventID`, `fullName`) VALUES (?, ?)');
    $who = 'Child of ' . $slug;
    $reg->bind_param('is', $eventId, $who);
    $reg->execute();
    $id = (int) $mysqli->insert_id;
    $reg->close();

    return $id;
};

// 🏢 EACH ORGANISATION'S OWN FIGURE, for events that have none (#810 round 1:
//    every event here used to carry its own 30 days, so the organisation's
//    figure — bound TWICE in the clause, once for "above zero" and once for
//    the arithmetic — was pinned by nothing, and binding either one wrong left
//    every check green). Organisation 1 keeps registrations for 20 days;
//    organisation 2 has chosen 0, "keep indefinitely", which must mean NOTHING
//    of an event without its own figure is ever deleted.
$mysqli->query("DELETE FROM `tblSettings` WHERE `settingKey` IN ('events.registrationRetentionDays', 'events.registrationRetentionRun') AND `siteID` IN (1, 2)");
$mysqli->query(
    "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES "
    . "(1, 'events.registrationRetentionDays', '20', '', 0), (2, 'events.registrationRetentionDays', '0', '', 0), "
    . "(1, 'events.registrationRetentionRun', 'true', '', 0), (2, 'events.registrationRetentionRun', 'true', '', 0)"
);

// 🕰️ Each reading is "the deadline on that event's own clock, moved by a few
//    hours": `-n days` from what the zone's clock reads now is the moment the
//    n-day period is up for an event that ended then.
$rcDue = static fn (string $zone, int $hours, int $days = 30): string => $rcShift($rcClockNow($zone), -$days, $hours);

// 🌗 A ZONE WHOSE CLOCKS WENT FORWARD RECENTLY, for the "n days on the clock
//    against n x 24 real hours" rule. Across such a night, n days on the
//    clock is SHORTER than n x 24 hours by the gain, so a registration can be
//    past its clock-day deadline while its n x 24 hours are not yet up. The
//    test cannot pick its date, so it finds, among every zone PHP knows, the
//    one whose most recent spring-forward is most recent (at least two days
//    ago, with no other change in the forty days before it) — on any date of
//    the year some zone has had one within months.
$rcUtcNow  = time();
$rcSpring  = null;
foreach (DateTimeZone::listIdentifiers() as $rcZoneName) {
    $rcT = (new DateTimeZone($rcZoneName))->getTransitions($rcUtcNow - 400 * 86400, $rcUtcNow);
    if (is_array($rcT) === false || count($rcT) < 2) {
        continue;
    }
    $rcLast = $rcT[count($rcT) - 1];
    $rcPrev = $rcT[count($rcT) - 2];
    $rcAge  = $rcUtcNow - (int) $rcLast['ts'];
    $rcGain = (int) $rcLast['offset'] - (int) $rcPrev['offset'];
    $rcQuiet = count($rcT) < 3 || (int) $rcPrev['ts'] < (int) $rcLast['ts'] - 40 * 86400;
    if ($rcGain > 0 && $rcAge >= 2 * 86400 && $rcQuiet === true
        && ($rcSpring === null || $rcAge < $rcSpring['age'])) {
        $rcSpring = ['zone' => $rcZoneName, 'age' => $rcAge, 'gain' => $rcGain, 'before' => (int) $rcPrev['offset']];
    }
}
// n is chosen so the event ended about three days BEFORE that night.
$rcSpringDays = $rcSpring === null ? 30 : intdiv($rcSpring['age'], 86400) + 3;
$rcSpringAt   = static function (int $minutesPastClockDeadline) use ($rcSpring, $rcClockNow, $rcSpringDays): string {
    return (new DateTimeImmutable($rcClockNow($rcSpring['zone'] ?? 'UTC'), new DateTimeZone('UTC')))
        ->modify('-' . $rcSpringDays . ' days')->modify(sprintf('%+d minutes', -$minutesPastClockDeadline))
        ->format('Y-m-d H:i:s');
};
$rcGainMin = intdiv((int) ($rcSpring['gain'] ?? 3600), 60);

$rcRegs = [
    // BEHIND the server (UTC-12): three hours still to go on its own clock.
    'behind-not-yet'   => $rcRegistration('rc810-behind-not-yet', 1, 'Etc/GMT+12', $rcDue('Etc/GMT+12', 3)),
    // BEHIND the server: its period was up an hour ago on its own clock.
    'behind-due'       => $rcRegistration('rc810-behind-due', 1, 'Etc/GMT+12', $rcDue('Etc/GMT+12', -1)),
    // A REAL zone behind UTC, on an ordinary organisation's calendar: an hour to go.
    'newyork-not-yet'  => $rcRegistration('rc810-newyork-not-yet', 1, 'America/New_York', $rcDue('America/New_York', 1)),
    // AHEAD of the server (UTC+14), on the SECOND organisation: up three hours ago.
    'ahead-due'        => $rcRegistration('rc810-ahead-due', 2, 'Pacific/Kiritimati', $rcDue('Pacific/Kiritimati', -3)),
    // AHEAD of the server: an hour to go.
    'ahead-not-yet'    => $rcRegistration('rc810-ahead-not-yet', 2, 'Pacific/Kiritimati', $rcDue('Pacific/Kiritimati', 1)),
    // NO figure of its own, on organisation 1 (20 days): two hours to go.
    'fallback-not-yet' => $rcRegistration('rc810-fallback-not-yet', 1, 'UTC', $rcDue('UTC', 2, 20), null),
    // NO figure of its own, on organisation 1: up two hours ago.
    'fallback-due'     => $rcRegistration('rc810-fallback-due', 1, 'UTC', $rcDue('UTC', -2, 20), null),
    // NO figure of its own, on organisation 2, which keeps indefinitely: 400 days old.
    'zero-kept'        => $rcRegistration('rc810-zero-kept', 2, 'UTC', $rcDue('UTC', 0, 400), null),
    // Past its CLOCK-DAY deadline by half the gain, but n x 24 real hours not yet up.
    'spring-not-yet'   => $rcRegistration('rc810-spring-not-yet', 1, $rcSpring['zone'] ?? 'UTC', $rcSpringAt(intdiv($rcGainMin, 2)), $rcSpringDays),
    // Past BOTH deadlines: the clock-day one by the gain plus half an hour.
    'spring-due'       => $rcRegistration('rc810-spring-due', 1, $rcSpring['zone'] ?? 'UTC', $rcSpringAt($rcGainMin + 30), $rcSpringDays),
];

/** Which of the given registrations a clause built on `$nowSql` (bound with `$types`/`$params`) would take. */
$rcWouldTake = static function (array $ids, string $nowSql, string $types = '', array $params = []) use ($mysqli): array {
    $stmt = $mysqli->prepare(
        'SELECT `r`.`registrationID` AS `id` FROM `tblEventRegistrations` AS `r` '
        . 'INNER JOIN `tblEvents` AS `e` ON `e`.`eventID` = `r`.`eventID` '
        . 'WHERE `r`.`registrationID` IN (' . implode(', ', array_map('intval', $ids)) . ') '
        . '  AND GREATEST(COALESCE(`e`.`endDateTime`, `e`.`startDateTime`), `e`.`startDateTime`) '
        . '      < DATE_SUB(' . $nowSql . ', INTERVAL COALESCE(`e`.`registrationRetentionDays`, 90) DAY) '
        . 'ORDER BY `r`.`registrationID`'
    );
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $out[] = (int) $row['id'];
    }
    $stmt->close();

    return $out;
};
$rcOldTook = $rcWouldTake([$rcRegs['behind-not-yet'], $rcRegs['newyork-not-yet'], $rcRegs['ahead-due']], 'NOW()');
// The CLOCK-DAY reading alone — what a page asks, `nowOnItsOwnClock()` — for
// the spring-forward pair: it must take the one the real-hours rule keeps.
$rcPage     = \Portal\Core\Events::nowOnItsOwnClock($mysqli, 'e', 1);
$rcPageTook = $rcWouldTake([$rcRegs['spring-not-yet']], $rcPage['sql'], $rcPage['types'], $rcPage['params']);

/** Seconds since the moment a registration's event ended, on its own zone. */
$rcElapsed = static function (string $key) use ($mysqli, $rcRegs, $rcUtcNow): int {
    $row = $mysqli->query(
        'SELECT `e`.`endDateTime` AS `endAt`, `e`.`timezone` AS `zone` FROM `tblEventRegistrations` AS `r` '
        . 'INNER JOIN `tblEvents` AS `e` ON `e`.`eventID` = `r`.`eventID` WHERE `r`.`registrationID` = ' . (int) $rcRegs[$key]
    )->fetch_assoc();

    return $rcUtcNow - (new DateTimeImmutable((string) $row['endAt'], new DateTimeZone((string) $row['zone'])))->getTimestamp();
};

// 📌 THE PREMISES. Without them every survival check below is vacuous.
rc_check('PREMISE: the database clock really is UTC for this connection',
    (string) ($mysqli->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS d')->fetch_assoc()['d'] ?? 'x') === '0');
rc_check('PREMISE: the OLD clause deleted EARLY — it took the UTC-12 registration with three hours still to go, and the New York one with an hour to go',
    in_array($rcRegs['behind-not-yet'], $rcOldTook, true) === true
    && in_array($rcRegs['newyork-not-yet'], $rcOldTook, true) === true,
    json_encode($rcOldTook));
rc_check('PREMISE: …and LATE the other way — it kept the UTC+14 registration whose period was up three hours ago',
    in_array($rcRegs['ahead-due'], $rcOldTook, true) === false, json_encode($rcOldTook));
rc_check('PREMISE: a zone whose clocks went forward recently was found (' . ($rcSpring['zone'] ?? 'none') . ', '
    . intdiv((int) ($rcSpring['age'] ?? 0), 86400) . ' days ago, +' . $rcGainMin . ' min)',
    $rcSpring !== null);
rc_check('PREMISE: the spring-forward registration is past its CLOCK-DAY deadline (the page reading takes it) while fewer than n x 24 real hours have passed',
    $rcPageTook === [$rcRegs['spring-not-yet']] && $rcElapsed('spring-not-yet') < $rcSpringDays * 86400,
    json_encode([$rcPageTook, $rcElapsed('spring-not-yet'), $rcSpringDays * 86400]));
rc_check('PREMISE: …and its partner is past n x 24 real hours too',
    $rcElapsed('spring-due') >= $rcSpringDays * 86400,
    json_encode([$rcElapsed('spring-due'), $rcSpringDays * 86400]));

// 📊 The count an administrator is shown, then the delete. Exactly four are
//    due: one behind the server, one ahead of it, one by its organisation's
//    own figure, and the spring-forward one past both deadlines.
$rcRegCount = registration_sweep(false);
rc_check('the count shown before confirming is four: the four registrations whose period is up',
    $rcRegCount === 4, (string) $rcRegCount);
$rcRegDeleted = registration_sweep(true);
rc_check('…and the delete removes exactly those four', $rcRegDeleted === 4, (string) $rcRegDeleted);

$rcRegLeft = $rcStillThere('tblEventRegistrations', 'registrationID', array_values($rcRegs));
// 🚨 THE DECISIVE CHECKS — the first is the one the old code fails.
rc_check('a registration whose period is NOT yet up on its event\'s clock SURVIVES — UTC-12, three hours to go, which the server\'s clock took early',
    in_array($rcRegs['behind-not-yet'], $rcRegLeft, true) === true, json_encode($rcRegLeft));
rc_check('…and so does the New York one with an hour to go — an ordinary zone, not only the extreme one',
    in_array($rcRegs['newyork-not-yet'], $rcRegLeft, true) === true, json_encode($rcRegLeft));
rc_check('…and the UTC+14 one with an hour to go',
    in_array($rcRegs['ahead-not-yet'], $rcRegLeft, true) === true, json_encode($rcRegLeft));
rc_check('a registration whose period IS up on its event\'s clock is gone — UTC-12, up an hour ago',
    in_array($rcRegs['behind-due'], $rcRegLeft, true) === false, json_encode($rcRegLeft));
rc_check('…and the UTC+14 one up three hours ago is gone on time, which the server\'s clock kept late',
    in_array($rcRegs['ahead-due'], $rcRegLeft, true) === false, json_encode($rcRegLeft));
// 🏢 The organisation's own figure, both bindings of it.
rc_check('an event with NO figure of its own keeps its registrations for its organisation\'s 20 days: two hours to go, still there',
    in_array($rcRegs['fallback-not-yet'], $rcRegLeft, true) === true, json_encode($rcRegLeft));
rc_check('…and is cleared once those 20 days are up',
    in_array($rcRegs['fallback-due'], $rcRegLeft, true) === false, json_encode($rcRegLeft));
rc_check('an organisation that keeps registrations INDEFINITELY (0) loses none of an event with no figure of its own, even 400 days on',
    in_array($rcRegs['zero-kept'], $rcRegLeft, true) === true, json_encode($rcRegLeft));
// 🌗 The later of the two deadlines.
rc_check('a registration past its clock-day deadline but NOT yet n x 24 real hours old SURVIVES — the later of the two deadlines wins (' . ($rcSpring['zone'] ?? '?') . ')',
    in_array($rcRegs['spring-not-yet'], $rcRegLeft, true) === true, json_encode($rcRegLeft));
rc_check('…and one past BOTH is gone',
    in_array($rcRegs['spring-due'], $rcRegLeft, true) === false, json_encode($rcRegLeft));

// 🎯 THE DEADLINE RULE DRIVEN AT FIXED MOMENTS (#810 round 2). The cases that
//    matter fall on particular dates, and the sweep's "now" is the real one,
//    so the checks above cannot reach them: they would pass with the window's
//    two extra days removed, with the lowest offset taken only at the window's
//    two ends, or with a zone that has no list of changes read at offset 0 —
//    each of which deletes early (up to two hours in about two hundred zones,
//    and five hours for `EST`). `Events::deadlineReadingFor()` is the real
//    per-zone rule the sweep uses, given the moment instead of reading the
//    clock, and `$rcGoes` applies the clause's own comparison to its answer:
//    a row goes when its reading is earlier than "m − n days".
$rcGoes = static function (string $zone, int $lookBack, int $days, int $nowUtc, string $reading): bool {
    $m = \Portal\Core\Events::deadlineReadingFor(new DateTimeZone($zone), $lookBack, $nowUtc);

    return strcmp($reading, (new DateTimeImmutable($m, new DateTimeZone('UTC')))->modify('-' . $days . ' days')->format('Y-m-d H:i:s')) < 0;
};
$rcAt = static fn (string $utc): int => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();

// (a) A reading in the hour London SKIPS, near the window's START. 01:30 on
//     29 March 2026 never happened; its later meaning is 01:30 GMT, i.e.
//     01:30 UTC, an offset that ended at 01:00 UTC. 30 days kept, 30 looked
//     back: one minute before 30 x 24 hours from that later meaning.
$rcSkipDue = $rcAt('2026-03-29 01:30:00') + 30 * 86400;
rc_check('PREMISE: a window of exactly n days (no two extra days) WOULD delete the skipped-hour reading a minute early',
    $rcGoes('Europe/London', 28, 30, $rcSkipDue - 60, '2026-03-29 01:30:00') === true);
rc_check('a reading in the skipped hour near the window\'s start is NOT deleted before 30 x 24 hours from its later meaning',
    $rcGoes('Europe/London', 30, 30, $rcSkipDue - 60, '2026-03-29 01:30:00') === false);
rc_check('…and is deleted a minute after it, so the rule is not simply "never"',
    $rcGoes('Europe/London', 30, 30, $rcSkipDue + 60, '2026-03-29 01:30:00') === true);

// (b) A WHOLE WINTER INSIDE THE WINDOW, with summer at BOTH ends: London,
//     an event ending at 12:00 GMT on 16 March 2026, kept 30 days, in an
//     organisation whose longest period is 300 days — the window runs from
//     June 2025 (BST) to April 2026 (BST).
$rcWinterDue = $rcAt('2026-03-16 12:00:00') + 30 * 86400;
$rcWinterEnds = (new DateTimeZone('Europe/London'))->getTransitions($rcWinterDue - 60 - 302 * 86400, $rcWinterDue - 60);
rc_check('PREMISE: both ends of that window are on summer time (+1 h), and the winter (+0) is only in the middle',
    is_array($rcWinterEnds) === true
    && (int) $rcWinterEnds[0]['offset'] === 3600 && (int) $rcWinterEnds[count($rcWinterEnds) - 1]['offset'] === 3600
    && min(array_map(static fn (array $s): int => (int) $s['offset'], $rcWinterEnds)) === 0);
rc_check('an event that ended in the winter in the middle of the window is NOT deleted before 30 x 24 hours',
    $rcGoes('Europe/London', 300, 30, $rcWinterDue - 30 * 60, '2026-03-16 12:00:00') === false);
rc_check('…and is deleted a minute after them',
    $rcGoes('Europe/London', 300, 30, $rcWinterDue + 60, '2026-03-16 12:00:00') === true);

// (c) A ZONE PHP OPENS BUT HAS NO LIST OF CHANGES FOR: `EST` (-5 h, an
//     abbreviation) and `+05:00` (a bare offset). Only an old, hand-written
//     or restored row can hold one; it must read the lowest offset there is.
$rcFixedDue = $rcAt('2026-06-01 12:00:00') + 30 * 86400;
rc_check('PREMISE: PHP has no list of changes for EST or +05:00 (getTransitions answers false)',
    (new DateTimeZone('EST'))->getTransitions($rcFixedDue - 32 * 86400, $rcFixedDue) === false
    && (new DateTimeZone('+05:00'))->getTransitions($rcFixedDue - 32 * 86400, $rcFixedDue) === false);
rc_check('an EST event (ended 07:00 on its clock = 12:00 UTC) is NOT deleted a minute before 30 x 24 hours',
    $rcGoes('EST', 30, 30, $rcFixedDue - 60, '2026-06-01 07:00:00') === false);
rc_check('…and nor is a +05:00 one (ended 17:00 on its clock = 12:00 UTC)',
    $rcGoes('+05:00', 30, 30, $rcFixedDue - 60, '2026-06-01 17:00:00') === false);

// 🧯 THE ONE CASE THAT CANNOT BE EXACT IS MADE LATE: a row whose zone was
//    written in the instant between the method's two statements, so it is not
//    in the list and takes the `ELSE`. That instant cannot be reached through
//    the sweep, so the method is asked directly, with a zone written AFTER it
//    read the list — which is exactly the race — and the sweep's own call is
//    pinned as text below. (A FAILED read is not a case: under this portal's
//    strict reporting it throws, and the sweep deletes nothing.)
$rcEarliestOffset = PHP_INT_MAX;
$rcEarliestZone   = '';
foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $rcZone) {
    $rcOff = (new DateTimeImmutable('now', new DateTimeZone($rcZone)))->getOffset();
    if ($rcOff < $rcEarliestOffset) {
        $rcEarliestOffset = $rcOff;
        $rcEarliestZone   = $rcZone;
    }
}
rc_check('PREMISE: no zone PHP knows reads EARLIER than Etc/GMT+12 right now (so it really is the latest-deleting clock)',
    $rcEarliestOffset >= -12 * 3600, $rcEarliestZone . ' at ' . $rcEarliestOffset . 's');

$rcExpr        = \Portal\Core\Events::nowForADeletionDeadline($mysqli, 'e', 2, 30);
$rcExprDefault = \Portal\Core\Events::nowOnItsOwnClock($mysqli, 'e', 2);
$rcRegistration('rc810-written-after', 2, 'Asia/Tokyo', $rcDue('Asia/Tokyo', -1));
/** What an expression reads for one event, found by its address. */
$rcReadFor = static function (array $expr, string $slug = 'rc810-written-after') use ($mysqli): string {
    $stmt = $mysqli->prepare(
        'SELECT ' . $expr['sql'] . ' AS `n` FROM `tblEvents` AS `e` WHERE `e`.`eventSlug` = ?'
    );
    $stmt->bind_param($expr['types'] . 's', ...array_merge($expr['params'], [$slug]));
    $stmt->execute();
    $n = (string) ($stmt->get_result()->fetch_assoc()['n'] ?? '');
    $stmt->close();

    return $n;
};
$rcGap = static fn (string $a, string $b): int => abs(
    (new DateTimeImmutable($a, new DateTimeZone('UTC')))->getTimestamp()
    - (new DateTimeImmutable($b, new DateTimeZone('UTC')))->getTimestamp()
);
rc_check('PREMISE: the late-written zone really is NOT in the list the method read (so the `ELSE` is what answers it)',
    in_array('Asia/Tokyo', $rcExpr['params'], true) === false, json_encode($rcExpr['params']));
rc_check('a row the deletion reading could not place reads the EARLIEST clock on Earth, so it can only be judged late',
    $rcGap($rcReadFor($rcExpr), $rcClockNow('Etc/GMT+12')) <= 120,
    $rcReadFor($rcExpr) . ' against ' . $rcClockNow('Etc/GMT+12'));
// (Only worth anything while the organisation's clock is NOT Etc/GMT+12 itself,
// or this and the check above could not tell the two answers apart.)
rc_check('…while a PAGE (nowOnItsOwnClock) still gets the organisation\'s clock there (nothing else changed)',
    \Portal\Core\Site::timezone(2) !== 'Etc/GMT+12'
    && $rcGap($rcReadFor($rcExprDefault), $rcClockNow(\Portal\Core\Site::timezone(2))) <= 120,
    $rcReadFor($rcExprDefault) . ' against ' . $rcClockNow(\Portal\Core\Site::timezone(2)));

// 🔤 THE ZONE IS COMPARED EXACTLY. `utf8mb4_general_ci` ignores accents, so a
//    hand-written `Europe/Zürich` (PHP cannot open it, so it reads the
//    organisation's clock) and the real `Europe/Zurich` are ONE value to it.
//    The bad spelling goes in first, so a case-and-accent-blind list hands it
//    back for both and its `WHEN` catches the real Zurich rows too.
$rcRegistration('rc810-zurich-bad', 2, 'Europe/Zürich', $rcDue('UTC', 0, 1));
$rcRegistration('rc810-zurich-real', 2, 'Europe/Zurich', $rcDue('UTC', 0, 1));
$rcRegistration('rc810-zurich-space', 2, 'Europe/Zurich ', $rcDue('UTC', 0, 1));
$rcCiDistinct = (int) ($mysqli->query(
    "SELECT COUNT(*) AS `n` FROM (SELECT DISTINCT `timezone` FROM `tblEvents` WHERE `eventSlug` IN ('rc810-zurich-bad', 'rc810-zurich-real')) AS `x`"
)->fetch_assoc()['n'] ?? 0);
rc_check('PREMISE: the column\'s own collation really does treat the two spellings as one, and Zurich\'s clock differs from the organisation\'s',
    $rcCiDistinct === 1 && \Portal\Core\Site::phpCanOpenZone('Europe/Zürich') === false
    && $rcGap($rcClockNow('Europe/Zurich'), $rcClockNow(\Portal\Core\Site::timezone(2))) >= 3000,
    'distinct: ' . $rcCiDistinct);
$rcZurichPage = \Portal\Core\Events::nowOnItsOwnClock($mysqli, 'e', 2);
rc_check('a real Europe/Zurich event reads ZURICH\'s clock, not the organisation\'s, beside a hand-written Europe/Zürich',
    $rcGap($rcReadFor($rcZurichPage, 'rc810-zurich-real'), $rcClockNow('Europe/Zurich')) <= 120,
    $rcReadFor($rcZurichPage, 'rc810-zurich-real') . ' against ' . $rcClockNow('Europe/Zurich'));
rc_check('a stored "Europe/Zurich " with a trailing space — its own value under a byte-for-byte list — still reaches Zurich\'s clock through Site::zoneFor()\'s trim',
    $rcGap($rcReadFor($rcZurichPage, 'rc810-zurich-space'), $rcClockNow('Europe/Zurich')) <= 120,
    $rcReadFor($rcZurichPage, 'rc810-zurich-space'));
rc_check('…and the hand-written one reads the organisation\'s, as every page shows it',
    $rcGap($rcReadFor($rcZurichPage, 'rc810-zurich-bad'), $rcClockNow(\Portal\Core\Site::timezone(2))) <= 120,
    $rcReadFor($rcZurichPage, 'rc810-zurich-bad'));

// 📄 And the sweep asks for exactly the deletion reading, pinned WHOLE — the
//    behavioural checks above cannot see a scope or a look-back changed to
//    something that happens to give the same answer on this test's rows.
$rcSweepCode = $rcStrip(dirname(__DIR__) . '/web/_apps/cron/_retention-sweep.php');
rc_check('the sweep asks the deletion reading, per organisation, over its LONGEST period, and the page reading nowhere',
    substr_count($rcSweepCode, "Events::nowForADeletionDeadline(\$db, 'e', \$siteId, max(\$longest, \$days))") === 1
    && substr_count($rcSweepCode, 'Events::nowForADeletionDeadline(') === 1
    && substr_count($rcSweepCode, 'Events::nowOnItsOwnClock(') === 0
    && str_contains($rcSweepCode, 'INTERVAL COALESCE(`e`.`registrationRetentionDays`') === true
    && preg_match('/DATE_SUB\(\s*NOW\(\)\s*,\s*INTERVAL\s+COALESCE/i', $rcSweepCode) === 0,
    'calls: ' . substr_count($rcSweepCode, 'Events::nowForADeletionDeadline('));

// =============================================================================
echo "\n" . $rcPasses . ' passed, ' . $rcFailures . ' failed, ' . ($rcPasses + $rcFailures)
    . ' ran of ' . RC_EXPECTED_CHECKS . " expected.\n";
if ($rcPasses + $rcFailures !== RC_EXPECTED_CHECKS) {
    echo "FAIL — the number of checks that ran is not the number this file expects.\n";
    exit(1);
}
if ($rcFailures > 0) {
    exit(1);
}
echo 'PASS — all ' . RC_EXPECTED_CHECKS . " checks passed.\n";
exit(0);
