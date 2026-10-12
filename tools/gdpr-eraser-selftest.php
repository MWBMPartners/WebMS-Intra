<?php
// Path: tools/gdpr-eraser-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Erasure self-test on a real database 🗑️🧪 (#510, #535)
 * -----------------------------------------------------------------------------
 * `tools/gdpr-coverage-selftest.php` proves the erasure INSTRUCTIONS are the
 * right ones. It cannot prove they work. This file runs the REAL
 * `GdprEraser::execute()` against a throwaway database and looks at the rows
 * afterwards.
 *
 * WHY IT EXISTS
 * -------------
 * Until 11 October 2026 nothing ran an erasure before a customer did. The
 * table walk deleted the request it was carrying out, the database deleted the
 * request's audit lines with it, and the run crashed with the person half
 * erased (#510). Eight tables the written list promised to deal with were
 * never touched (#535). Both were found by reading, and both would have been
 * found in seconds by a test like this one.
 *
 * THE MADE-UP WORLD, AND WHY IT IS BUILT BY A LOOP
 * ------------------------------------------------
 * For EVERY instruction `GdprEraser::catalogue()` produces, this file inserts
 * a row that the instruction should find, filling each column by its type from
 * what the database itself says the table looks like. Nothing here lists the
 * tables by hand, so a table added to the catalogue tomorrow is tested
 * tomorrow, without anybody remembering to add it.
 *
 *   P   the person being erased
 *   C   a control: has a row of their own in every table P has one in
 *   D   a second control: named on P's rows wherever a row can name two people
 *       ("created by D, last changed by P")
 *
 * Rows are inserted with foreign-key checks switched off (a generic row cannot
 * have real parents), and the checks are switched back ON before the erasure
 * runs, so a statement the database would refuse is refused here too.
 *
 * WHAT IT PROVES
 * --------------
 *  1. A run ends, the request survives, and it is closed with the person's
 *     details taken off it.
 *  2. Exactly one audit line per instruction, in order, and the chain verifies.
 *  3. Every delete deletes, every unlink empties the person's own number and
 *     nobody else's, and every "keep" keeps.
 *  4. NOTHING ELSE IN THE WHOLE DATABASE CHANGED. Every row of every table is
 *     fingerprinted before and after; only P's own rows may differ.
 *  5. The tables #535 was about, each by name; and the records that must
 *     never be lost, each by name.
 *  6. A step that fails is recorded, the rest still run, the request goes back
 *     to "pending review" untouched, and a second run finishes the job.
 *  7. If the audit trail cannot be written the run stops, nothing is left
 *     half-recorded, and the request can be run again.
 *  7b. A statement file that cannot be deleted, and a send log that cannot be
 *     updated, each count as a failed step and the request is not closed.
 *  7c. A database missing a COLUMN gives a failed step (the request is not
 *     closed); one missing a whole TABLE gives a skip, because nothing can be
 *     held in a table that is not there.
 *
 * ADDED BY ROLL-OUT PACKAGE A2a (11 October 2026)
 * -----------------------------------------------
 *  3b. OTHER PEOPLE'S RECORDS SURVIVE, proven with rows that really are
 *     linked to each other: a visitor, a salvation card, a task and a rota
 *     slot given to the erased person; a swap request aimed at them; a
 *     child's check-in, a pastoral visit, an expense payment and a Zoom
 *     meeting they worked on; another person's gifts, Gift Aid declaration
 *     and pastoral case. And the erased person's OWN visitor record, pledge
 *     and Zoom connection are gone.
 *  4c. Every column in the whole database that can hold an account number
 *     is given the erased person's number, and afterwards the ones that
 *     still hold it are EXACTLY a named list. This is the check that would
 *     have found the fourteen tables nobody had an instruction for.
 *  5c. The send log: only an entry that IS the address is replaced, never a
 *     longer address that contains it.
 *  7d. The person's photo: what is deleted, and the three things that are
 *     refused (a path outside the uploads folder, a file that is not an
 *     image, a file that cannot be deleted).
 *  9.  A request stuck at "processing" can be put back, a live one cannot,
 *     and the test of "live" does not depend on the server's clock or zone.
 *  10. Filing a request: unconfirmed from "Request data erasure", confirmed
 *     from "Delete my account". And an administrator confirming a waiting
 *     request on the person's behalf, with the audit line that says so.
 *
 * WHAT IT CANNOT PROVE
 * --------------------
 * - The rows the LOOP makes are not linked to each other the way real data is
 *   (each has its own made-up numbers). Section 3b adds a small hand-built
 *   set that is linked, and section 4b checks the database's own cascade
 *   rules: no "delete" instruction may cascade into a table that is kept,
 *   apart from a short named list.
 * - Section 9 proves the put-back does not depend on the server's time ZONE.
 *   It does not sit through a real clock change.
 * - It runs on whatever server it is pointed at. It has been run on MySQL
 *   8.0.36 and 8.4.11, and never on MariaDB.
 * - It does not drive the administrator's page; it calls the class.
 * - One place still keeps an erased person's number and is pinned by name
 *   (TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER): a step of an approval route that
 *   is given to one named person. What that step should become is a decision
 *   nobody has taken. Everything else that keeps a number is kept on purpose
 *   and listed in KNOWN_STILL_NAMED.
 * - Section 4c finds a person column three ways: by GdprEraser's list of
 *   names, by the database's own links to the account table, and by the
 *   SHAPE of the column's name. A column that holds an account number under
 *   a name of some other shape, with no link, would still be missed; so
 *   would a TEXT column other than the one pinned.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=<port> SELFTEST_DB_PASS=<pw> \
 *           php tools/gdpr-eraser-selftest.php
 *         The database must be a throwaway one built from web/_sql/full_schema.sql
 *         and its name must start with selftest_. The account needs the right to
 *         create triggers (sections 6 and 7 plant a failure with one).
 *         Run it as an ordinary user, not as root: section 7b makes a folder
 *         read-only to stop a file being deleted, and nothing stops root.
 * Exit:   0 only when every section was reached, exactly EXPECTED_CHECKS
 *         checks ran, and none failed. 1 otherwise.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/510
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/535
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🧯 Anything thrown outside the checks becomes a plain FAIL line and exit 1,
//    not a stack trace that might happen to contain the word PASS.
set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    exit(1);
});

// 🛑 Refuse anything but a throwaway database, before anything is loaded.
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test deletes rows, so it only ever runs on a throwaway database. Nothing was checked.\n";
    exit(1);
}

// 🔢 How many checks this file runs — EXACTLY. A check that never ran cannot
//    fail, so the count is part of the result. Change it only with a check.
const EXPECTED_CHECKS = 132;

// The headings that must all be reached for a pass.
const REQUIRED_SECTIONS = ['0', '1', '2', '3', '3b', '4', '4b', '4c', '5', '5b', '5c', '6', '7', '7b', '7c', '7d', '8', '9', '10'];

// 🗺️ The made-up people and requests. Every number is 920000 or higher, far
//    above anything the fresh-install script seeds.
const P_A   = 920001; // erased in sections 1-5
const C     = 920002; // control: their own rows
const D     = 920003; // control: named on P's rows
const ADMIN = 920004; // the administrator who presses the button
const P_B   = 920011; // erased in section 6 (a step fails, then a second run)
const P_C   = 920021; // erased in section 7 (the audit trail fails)
const P_E   = 920031; // erased in section 7b (a file, then the send log, cannot be dealt with)
const P_F   = 920041; // erased in section 7c (the database is missing a column and a table)
const P_G   = 920051; // erased in section 7d (a photo that must not, or cannot, be deleted)
const P_S   = 920061; // section 9 (a request stuck at "processing")
const P_T   = 920071; // section 10 (filing a request, and confirming one on the person's behalf)

const REQ_A     = 940001; // P_A's request, the one that is run
const REQ_A_DUP = 940002; // P_A pressed the button twice: still waiting
const REQ_A_OLD = 940003; // an older request of P_A's, cancelled
const REQ_C     = 940004; // C's own waiting request: must not be touched
const REQ_B     = 940011;
const REQ_PC    = 940021;
const REQ_E     = 940031;
const REQ_F     = 940041;
const REQ_A_STUCK = 940005; // another request of P_A's, left at "processing" by a run that died
const REQ_A_SITE2 = 940006; // P_A also asked at a SECOND organisation: still waiting there
const REQ_D_DONE  = 940007; // D's request, completed long ago; P_A was the administrator who ran it
const REQ_G       = 940051;
const REQ_S_OLD   = 940061; // "processing", last sign of a run 20 minutes ago
const REQ_S_LIVE  = 940062; // "processing", last sign of a run 1 minute ago
const REQ_S_NULL  = 940063; // "processing", with no moment recorded (stuck before migration 208)
const REQ_S_DONE  = 940064; // completed
const SITE_2      = 2;      // a second organisation on the same installation

// Details typed about the person that an instruction must EMPTY, by name.
// Listed here, and not read from the instructions, on purpose: the rest of
// this file works out what to expect from each instruction, so an instruction
// that quietly promised LESS (a column dropped from its list) changed the
// expectation with it and nothing failed. The independent check of
// 11 October 2026 planted exactly that and this test passed.
// "table.matched column" => the columns that must be empty afterwards.
const MUST_BE_EMPTIED = [
    'tblPrayerRequests.submitterID' => ['submitterName', 'submitterEmail', 'submitterIP'],
    'tblCareCase.personUserID'      => ['personName'],
];

// Columns the database will not allow to be emptied, so the person's number
// stays on the row and the audit line says so. This list is EXACT: a new one
// appearing is a decision somebody has to take (migration 192 is where the
// others were made emptiable), not something to wave through.
const KNOWN_LEFT_IN_PLACE = ['tblExpenseClaims.userID'];

// 🧾 EVERY column in the database that still holds the erased person's account
//    number after a complete, successful erasure, with the reason. Section 4c
//    gives every person column in every table the person's number and then
//    compares what is left with this list. IT IS EXACT, BOTH WAYS: a column
//    that still names them and is not here fails, and so does a column listed
//    here that no longer does (the list would then be out of date).
//
//    Adding to this list is a decision, not a tidy-up.
const KNOWN_STILL_NAMED = [
    'kept on purpose, with the number, by an instruction that says so' => [
        'tblGiftAidDeclaration.donorID', 'tblGivingStatementLog.donorID', 'tblDbsChecks.userID',
    ],
    'the database does not allow the column to be empty' => [
        'tblExpenseClaims.userID',
    ],
    // (A group of 37 columns stood here on 11 October 2026: every table's
    // SECOND "who did it" column, #492. 31 were built that day. The last six
    // were built on 12 October, on Salem874's decisions: five once the
    // database allowed them to be empty, and an accepted invitation, which
    // is now deleted with its person.)
    'the giver on a counted envelope: part of the giving-records work (roll-out package A2b)' => [
        'tblCountEnvelopes.giverID',
    ],
];

// 🔤 What a person column's NAME looks like. Section 4c treats an integer
//    column as a person column if its name is on GdprEraser's list, OR the
//    database links it to the account table, OR its name has this shape.
//
//    The third way was added on 12 October 2026. Until then a column that
//    was on no list and had no link was invisible here, and the independent
//    check found three that an erasure never reached: who sent an event in,
//    who marked an error as dealt with, and who ran a database upgrade.
const PERSON_COLUMN_SHAPE = '/(?:By|To)(?:User)?ID$|[Uu]serID$|^(?:giver|donor|member|owner|approver|moderator|presenter|viewer|leader|operator|submitter|assignee|reviewer|author|creator)ID$/';

// Columns whose name has that shape and which do NOT hold an account number.
// Exact, both ways: a new look-alike has to be looked at and added here with
// its reason, and an entry that stops being true has to come out.
const LOOKS_LIKE_A_PERSON_COLUMN_AND_IS_NOT = [
    'tblApiKeys.rotatedToID'             => 'the number of the KEY that replaced this one',
    'tblAssetOwners.ownerID'             => 'this table\'s own row number',
    'tblExternalAudienceMembers.ownerID' => 'the number of the calendar, choice or rule the list belongs to',
    'tblVenueAgreements.supersededByID'  => 'the number of the AGREEMENT that replaced this one',
];

// 📝 TEXT that can hold an account number, which an erasure does NOT clear.
//    Pinned here so that it is known, and so that building it changes this
//    list. A step of an approval route can be given to a role, a group, or
//    ONE NAMED PERSON, and then `assigneeValue` holds that person's account
//    number as text. It does not say who did something; it says who MUST.
//    Emptying it would leave a step that nobody can approve, so what the
//    step should become is a decision, and none has been taken.
const TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER = [
    'tblWorkflowSteps.assigneeValue' => 'who an approval step is given to, when it is one named person',
];

// 🧍 The account row after an erasure, column by column. EXACT, both ways:
//    every column of tblUsers must be in one of these two lists, a column in
//    the first must hold exactly the value given, and a column in the second
//    must be as it was.
//
//    Added on 12 October 2026. The checks on the emptied account used to
//    name fifteen columns, and the independent check took `displayPhone` out
//    of the eraser's list and every test still passed: the person's second
//    telephone number stayed on an "emptied" account and nothing said so.
//    '@request' stands for that request's own made-up address.
const ACCOUNT_AFTER_ERASURE = [
    'fullName' => '[Deleted User]', 'emailAddress' => '@request',
    'isActive' => 0, 'isAdmin' => 0, 'isRootAdmin' => 0, 'totpEnabled' => 0,
    'phoneNumber' => null, 'avatarPath' => null, 'locale' => null, 'notifyPrefs' => null, 'totpSecret' => null,
    'calendarToken' => null, 'displayBio' => null, 'displayPhoto' => null, 'displayPhone' => null,
    'displayAddress' => null, 'latitude' => null, 'longitude' => null, 'what3words' => null,
];
// Not about the person: the row's own number and date, and switches that
// have nothing left to show once the columns above are empty.
const ACCOUNT_COLUMNS_LEFT_AS_THEY_WERE = [
    'userID', 'createdAt', 'sabbathHonour', 'visibilityName', 'visibilityRoles', 'visibilityEmail', 'visibilityPhone',
    'visibilityAddress', 'visibilityCoords', 'visibilityBio', 'visibilityPhoto',
];

// Person columns section 4c could not put a made-up row in, so it says nothing
// about them. Exact, for the same reason.
const COULD_NOT_BE_TRIED = [];

// A delete that the database carries on into a table whose records are KEPT.
// Each is here on purpose, with its reason; section 4b fails on any other.
// "parent -> child" => why it is right.
const CASCADES_INTO_KEPT_TABLES = [
    'tblVisitor -> tblVisitorContact' => 'The only visitor record an erasure deletes is the erased person\'s OWN (matched on '
        . '`convertedUserID`). The follow-up notes under it are about them, so they go with it. A note the person WROTE '
        . 'about somebody else\'s visitor record is kept (section 3b proves both).',
    'tblZoomAccount -> tblZoomMeeting' => 'A meeting made through the erased person\'s own Zoom connection is a room in '
        . 'THEIR Zoom account, which the portal cannot open once the connection is gone. Salem874 decided on 11 October '
        . '2026 that it goes with the connection. A meeting they made through somebody else\'s connection is kept.',
];

// A delete after which the database EMPTIES a link on a kept table's rows.
// "deleted table -> kept table.column" => why it is right.
const EMPTIED_LINKS_ON_KEPT_TABLES = [
    'tblPledges -> tblGivingEntry.pledgeID' => 'A pledge is a promise, about the person, and is deleted. The gifts given '
        . 'towards it are kept and lose only the link to it: each keeps its campaign, amount, date and method, so no total '
        . 'changes (section 3b proves it on a real gift).',
];

// 📁 A scratch folder to stand in for the portal's own. The giving-statement
//    step deletes files under PORTAL_ROOT, so it must never be the real one.
$scratchParent = getenv('SELFTEST_WEB_DIR') !== false ? (string) getenv('SELFTEST_WEB_DIR') : sys_get_temp_dir();
$scratch       = rtrim($scratchParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    . 'gdpr-eraser-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
$statementDir  = $scratch . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving'
    . DIRECTORY_SEPARATOR . 'statements' . DIRECTORY_SEPARATOR . '1';
if (mkdir($statementDir, 0700, true) === false) {
    echo "REFUSED — could not make the scratch folder {$scratch}. Nothing was checked.\n";
    exit(1);
}
define('PORTAL_ROOT', $scratch);

// Classes one by one, never through bootstrap.php: this test must not read
// the portal's real connection file or its encryption key.
$coreDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
require_once $coreDir . 'App.php';
require_once $coreDir . 'GdprEraser.php';

use Portal\Core\GdprEraser;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// `$mysqli` is a global on purpose: `App::db()` falls back to it.
$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$mysqli->set_charset('utf8mb4');

$GLOBALS['ge_pass']     = 0;
$GLOBALS['ge_fail']     = 0;
$GLOBALS['ge_sections'] = [];
$GLOBALS['ge_triggers'] = [];
$GLOBALS['ge_files']    = [];
$GLOBALS['ge_dirs']     = [];
$GLOBALS['ge_family']   = 931000;

// 🧹 Whatever happens, take the planted triggers and the scratch files away.
//    Only files this run made are removed, by their exact names; the folders
//    are removed with rmdir(), which refuses a folder that is not empty.
register_shutdown_function(static function () use ($scratch): void {
    global $mysqli;
    foreach ($GLOBALS['ge_triggers'] as $trigger) {
        try {
            $mysqli->query('DROP TRIGGER IF EXISTS `' . $trigger . '`');
        } catch (Throwable $ignored) {
            // The database is a throwaway; nothing more can be done here.
        }
    }
    foreach ($GLOBALS['ge_files'] as $file) {
        if (is_file($file) === true) {
            @unlink($file);
        }
    }
    // Folders this run made for photos (section 7d makes one read-only, and
    // it may still be if the run stopped there). Newest first; rmdir() only
    // removes a folder that is empty.
    foreach (array_reverse($GLOBALS['ge_dirs']) as $dir) {
        if (is_dir($dir) === true) {
            @chmod($dir, 0700);
            foreach ($GLOBALS['ge_files'] as $file) {
                if (is_file($file) === true) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }
    $statements = $scratch . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving'
        . DIRECTORY_SEPARATOR . 'statements';
    // Section 7b's folder may still be read-only if the run stopped there.
    if (is_dir($statements . DIRECTORY_SEPARATOR . '2') === true) {
        @chmod($statements . DIRECTORY_SEPARATOR . '2', 0700);
        foreach ($GLOBALS['ge_files'] as $file) {
            if (is_file($file) === true) {
                @unlink($file);
            }
        }
        @rmdir($statements . DIRECTORY_SEPARATOR . '2');
    }
    $dir = $statements . DIRECTORY_SEPARATOR . '1';
    while (strlen($dir) >= strlen($scratch)) {
        @rmdir($dir);
        $dir = dirname($dir);
    }
});

// =============================================================================
// 🛠️ Small helpers
// =============================================================================

function ge_ok(string $label, bool $passed, string $detail = ''): void
{
    if ($passed === true) {
        $GLOBALS['ge_pass']++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $GLOBALS['ge_fail']++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . mb_substr($detail, 0, 1500)) . "\n";
}

function ge_section(string $number, string $title): void
{
    $GLOBALS['ge_sections'][$number] = true;
    echo "\n== " . $number . '. ' . $title . "\n";
}

/** The end: exit 0 only when everything ran and nothing failed. */
function ge_finish(): never
{
    $ran = $GLOBALS['ge_pass'] + $GLOBALS['ge_fail'];
    echo "\n" . $GLOBALS['ge_pass'] . ' passed, ' . $GLOBALS['ge_fail'] . ' failed, ' . $ran . ' ran of '
        . EXPECTED_CHECKS . " expected.\n";
    $bad     = false;
    $missing = array_values(array_diff(REQUIRED_SECTIONS, array_map('strval', array_keys($GLOBALS['ge_sections']))));
    if ($missing !== []) {
        echo 'FAIL — these sections never ran: ' . implode(', ', $missing) . ". A skipped section never reads as a pass.\n";
        $bad = true;
    }
    if ($ran !== EXPECTED_CHECKS) {
        echo 'FAIL — ' . $ran . ' checks ran, but this file expects exactly ' . EXPECTED_CHECKS . ".\n";
        $bad = true;
    }
    if ($GLOBALS['ge_fail'] > 0) {
        echo 'FAIL — ' . $GLOBALS['ge_fail'] . " check(s) failed.\n";
        $bad = true;
    }
    if ($bad === true) {
        exit(1);
    }
    echo 'PASS — all ' . $GLOBALS['ge_pass'] . " checks passed and every section ran.\n";
    exit(0);
}

/** Run a statement with bound values and return its rows (if it has any). */
function ge_q(string $sql, string $types = '', array $params = []): array
{
    global $mysqli;
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function ge_one(string $sql, string $types = '', array $params = []): ?array
{
    return ge_q($sql, $types, $params)[0] ?? null;
}

/** A table's columns, as the database describes them. Remembered. */
function ge_columns(string $table): array
{
    static $seen = [];
    if (isset($seen[$table]) === false) {
        $seen[$table] = ge_q(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_TYPE AS fullType, IS_NULLABLE AS nullable, '
            . 'CHARACTER_MAXIMUM_LENGTH AS maxLen, EXTRA AS extra FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            's',
            [$table]
        );
    }

    return $seen[$table];
}

function ge_isNullable(string $table, string $column): bool
{
    foreach (ge_columns($table) as $col) {
        if ($col['name'] === $column) {
            return $col['nullable'] === 'YES';
        }
    }

    return false;
}

/** A table's primary-key columns, in order. Empty when it has none. */
function ge_pk(string $table): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (ge_q(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE "
            . "WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY TABLE_NAME, ORDINAL_POSITION"
        ) as $row) {
            $all[$row['t']][] = $row['c'];
        }
    }

    return $all[$table] ?? [];
}

/**
 * The columns of a table that name a person: every name on GdprEraser's one
 * list, every column the database itself links to an account, and every
 * integer column whose NAME has the shape of a person column. The second and
 * third matter: they are how this test notices a person column the list
 * forgot, linked by the database or not.
 */
function ge_personColumns(string $table): array
{
    static $fk = null;
    if ($fk === null) {
        $fk = [];
        foreach (ge_q(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE "
            . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers' AND REFERENCED_COLUMN_NAME = 'userID'"
        ) as $row) {
            $fk[$row['t']][] = $row['c'];
        }
    }
    $names = [];
    foreach (ge_columns($table) as $col) {
        $looksLikeOne = in_array(strtolower((string) $col['type']), ['int', 'bigint', 'mediumint'], true) === true
            && preg_match(PERSON_COLUMN_SHAPE, (string) $col['name']) === 1
            && isset(LOOKS_LIKE_A_PERSON_COLUMN_AND_IS_NOT[$table . '.' . $col['name']]) === false;
        if (in_array($col['name'], GdprEraser::LINK_COLUMNS, true) === true
            || in_array($col['name'], $fk[$table] ?? [], true) === true
            || $looksLikeOne === true
        ) {
            $names[] = $col['name'];
        }
    }

    return $names;
}

/** A value that fits a column, made from a number unique to the row. */
function ge_value(array $col, int $family): mixed
{
    $type = strtolower((string) $col['type']);
    switch ($type) {
        case 'int':
        case 'bigint':
        case 'mediumint':
            return $family;
        case 'smallint':
            return ($family % 30000) + 1;
        case 'tinyint':
            return 1;
        case 'decimal':
        case 'float':
        case 'double':
            return 1;
        case 'year':
            return 2026;
        case 'enum':
        case 'set':
            if (preg_match("/^(?:enum|set)\\('((?:[^']|'')*)'/", (string) $col['fullType'], $m) === 1) {
                return str_replace("''", "'", $m[1]);
            }
            throw new RuntimeException('could not read the allowed values of ' . $col['name']);
        case 'char':
        case 'varchar':
        case 'tinytext':
        case 'text':
        case 'mediumtext':
        case 'longtext':
            // The digits reversed, so even a two-letter column differs from
            // row to row and a unique key on a short column is not tripped.
            return substr(strrev((string) $family) . '-selftest', 0, max(1, min(40, (int) ($col['maxLen'] ?? 40))));
        case 'json':
            return '{}';
        case 'date':
            return '2026-01-15';
        case 'datetime':
        case 'timestamp':
            return '2026-01-15 10:00:00';
        case 'time':
            return '10:00:00';
        case 'binary':
        case 'varbinary':
        case 'tinyblob':
        case 'blob':
        case 'mediumblob':
        case 'longblob':
            return 'x';
        default:
            throw new RuntimeException('no made-up value for a column of type ' . $type . ' (' . $col['name'] . ')');
    }
}

/**
 * Insert one made-up row and return its key.
 *
 * Every column is filled, including the ones allowed to be empty, so that
 * "this row is unchanged" compares real content. If the database refuses that
 * (a rule this file cannot guess), it tries once more with the optional
 * columns left empty; a second refusal is thrown, never skipped.
 *
 * @param array<string, int>   $people Person column => whose number goes in it.
 * @param array<string, mixed> $fixed  Columns given an exact value.
 * @param int                  $other  The number for person columns not in $people.
 *
 * @return array{table: string, key: array<string, mixed>}
 */
function ge_insert(string $table, array $people, array $fixed = [], int $other = D): array
{
    global $mysqli;
    $family = ++$GLOBALS['ge_family'];
    $person = ge_personColumns($table);

    $build = static function (bool $fillOptional) use ($table, $people, $fixed, $other, $family, $person): array {
        $row = [];
        foreach (ge_columns($table) as $col) {
            $name = (string) $col['name'];
            if (stripos((string) $col['extra'], 'GENERATED') !== false) {
                continue;
            }
            if (array_key_exists($name, $fixed) === true) {
                $row[$name] = $fixed[$name];
            } elseif (in_array($name, $person, true) === true) {
                $row[$name] = $people[$name] ?? $other;
            } elseif ($fillOptional === false && $col['nullable'] === 'YES') {
                $row[$name] = null;
            } else {
                $row[$name] = ge_value($col, $family);
            }
        }

        return $row;
    };

    $problem = null;
    foreach ([true, false] as $fillOptional) {
        $row = $build($fillOptional);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')';
        try {
            $stmt   = $mysqli->prepare($sql);
            $values = array_values($row);
            $stmt->bind_param(str_repeat('s', count($values)), ...$values);
            $stmt->execute();
            $stmt->close();

            $key = [];
            foreach (ge_pk($table) as $pkCol) {
                $key[$pkCol] = $row[$pkCol];
            }
            if ($key === []) {
                throw new RuntimeException($table . ' has no primary key, so its made-up row cannot be found again');
            }

            return ['table' => $table, 'key' => $key];
        } catch (mysqli_sql_exception $e) {
            $problem = $e;
        }
    }
    throw $problem;
}

/**
 * Give a made-up row the parent rows its foreign keys point at.
 *
 * Needed for the rows the erasure UPDATES. The first version of this test
 * left them without parents and two steps failed: emptying
 * `tblEventAttendance.userID` was refused because of the EVENT link. The
 * person column is part of a unique key that begins with the event number,
 * the database uses that key to police the event link, and so changing the
 * person makes it look the event up again. In a real database the event is
 * always there. Here it has to be put there.
 *
 * Links to an account, and links that include a person column, are left
 * alone: the people already exist, and a made-up membership for the erased
 * person would itself be found and deleted by another instruction.
 */
function ge_ensureParents(array $ref): void
{
    static $links = null;
    if ($links === null) {
        $links = [];
        foreach (ge_q(
            "SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS k, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS pt, REFERENCED_COLUMN_NAME AS pc "
            . "FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL "
            . "ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION"
        ) as $row) {
            $links[$row['t']][$row['k']]['parent']          = $row['pt'];
            $links[$row['t']][$row['k']]['cols'][$row['c']] = $row['pc'];
        }
    }
    $row    = ge_row($ref);
    $people = ge_personColumns($ref['table']);
    foreach ($links[$ref['table']] ?? [] as $link) {
        if ($link['parent'] === 'tblUsers' || array_intersect(array_keys($link['cols']), $people) !== []) {
            continue;
        }
        $fixed = [];
        foreach ($link['cols'] as $childCol => $parentCol) {
            if ($row[$childCol] === null) {
                continue 2;
            }
            $fixed[$parentCol] = $row[$childCol];
        }
        $where = implode(' AND ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($fixed)));
        if (ge_one('SELECT 1 FROM `' . $link['parent'] . '` WHERE ' . $where, str_repeat('s', count($fixed)), array_values($fixed)) === null) {
            // Any person named on a parent row is the administrator, who is
            // never erased, so no instruction can find the parent.
            ge_insert($link['parent'], [], $fixed, ADMIN);
        }
    }
}

/** One row by its key, or null when it is gone. */
function ge_row(array $ref): ?array
{
    $where = [];
    foreach (array_keys($ref['key']) as $col) {
        $where[] = '`' . $col . '` = ?';
    }

    return ge_one(
        'SELECT * FROM `' . $ref['table'] . '` WHERE ' . implode(' AND ', $where),
        str_repeat('s', count($ref['key'])),
        array_values($ref['key'])
    );
}

function ge_keyText(string $table, array $key): string
{
    return $table . '#' . implode('|', array_map('strval', array_values($key)));
}

/**
 * A fingerprint of EVERY row of EVERY table in the database.
 *
 * @return array<string, string> "table#key" => fingerprint of the row.
 */
function ge_snapshot(): array
{
    global $mysqli;
    $prints = [];
    $tables = ge_q("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    foreach ($tables as $one) {
        $table  = (string) $one['t'];
        $pk     = ge_pk($table);
        $result = $mysqli->query('SELECT * FROM `' . $table . '`');
        while ($row = $result->fetch_assoc()) {
            $print = sha1(serialize($row));
            // A table with no primary key is keyed on the whole row, so a
            // change to it shows up as one row gone and another arrived.
            $key = $pk === [] ? [$print] : array_intersect_key($row, array_flip($pk));
            $prints[ge_keyText($table, $key)] = $print;
        }
        $result->free();
    }

    return $prints;
}

/**
 * What differs between two fingerprints of the database, leaving out the rows
 * that were allowed to change.
 *
 * @param array<string, true> $allowed "table#key" of rows that may change or go.
 *
 * @return string[] One line per row that changed and should not have.
 */
function ge_unexpectedChanges(array $before, array $after, array $allowed): array
{
    $problems = [];
    foreach ($before as $key => $print) {
        if (isset($allowed[$key]) === true) {
            continue;
        }
        if (isset($after[$key]) === false) {
            $problems[] = $key . ' was DELETED';
        } elseif ($after[$key] !== $print) {
            $problems[] = $key . ' was CHANGED';
        }
    }
    foreach ($after as $key => $print) {
        if (isset($before[$key]) === false && isset($allowed[$key]) === false
            && str_starts_with($key, 'tblErasureAudit#') === false
        ) {
            $problems[] = $key . ' was ADDED';
        }
    }

    return $problems;
}

/**
 * Make a file under the scratch portal folder, and remember it (and any folder
 * made for it) so the end of the run takes it away again.
 *
 * @param string $relative Where, below PORTAL_ROOT, with forward slashes.
 */
function ge_file(string $relative, string $content = 'made-up image'): string
{
    $path = PORTAL_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $dir  = dirname($path);
    $made = [];
    while (is_dir($dir) === false) {
        $made[] = $dir;
        $dir    = dirname($dir);
    }
    foreach (array_reverse($made) as $one) {
        mkdir($one, 0700);
        $GLOBALS['ge_dirs'][] = $one;
    }
    file_put_contents($path, $content);
    $GLOBALS['ge_files'][] = $path;

    return $path;
}

/**
 * Insert a made-up row whose links are REAL: the columns given in $fixed point
 * at rows that exist, and every other link gets a parent made for it.
 */
function ge_linked(string $table, array $people, array $fixed = [], int $other = D): array
{
    $ref = ge_insert($table, $people, $fixed, $other);
    ge_ensureParents($ref);

    return $ref;
}

/** The first (usually only) key value of a tracked row. */
function ge_id(array $ref): int
{
    return (int) array_values($ref['key'])[0];
}

/**
 * Is a row the same as it was, apart from the columns named?
 * `updatedAt` is stamped by the database itself and never counts.
 */
function ge_same(?array $was, ?array $now, array $except = []): bool
{
    if ($was === null || $now === null) {
        return false;
    }
    foreach ($now as $name => $value) {
        if ($name !== 'updatedAt' && in_array($name, $except, true) === false && $value !== $was[$name]) {
            return false;
        }
    }

    return true;
}

/** Plant a trigger, remembering it so it is always taken away again. */
function ge_trigger(string $name, string $sql): void
{
    global $mysqli;
    $GLOBALS['ge_triggers'][] = $name;
    $mysqli->query('DROP TRIGGER IF EXISTS `' . $name . '`');
    $mysqli->query($sql);
}

function ge_dropTrigger(string $name): void
{
    global $mysqli;
    $mysqli->query('DROP TRIGGER IF EXISTS `' . $name . '`');
}

function ge_user(int $id, string $name, string $email): void
{
    ge_insert('tblUsers', [], [
        'userID' => $id, 'fullName' => $name, 'emailAddress' => $email, 'isActive' => 1, 'isAdmin' => 0, 'isRootAdmin' => 0,
    ]);
}

function ge_request(int $requestId, ?int $userId, string $email, string $name, string $status, ?string $notes = null): void
{
    ge_q(
        'INSERT INTO tblErasureRequest (requestID, siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy, notes) '
        . 'VALUES (?, 1, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 MONTH), ?)',
        'iisssss',
        [$requestId, $userId, $email, $name, $status === 'pending_confirmation' ? str_repeat('a', 64) : null, $status, $notes]
    );
}

function ge_audit(int $requestId): array
{
    return ge_q('SELECT auditID, action, tableName, recordKey, details FROM tblErasureAudit WHERE requestID = ? ORDER BY auditID', 'i', [$requestId]);
}

/**
 * Build the made-up world for one person: one row for every instruction.
 *
 * @return array{person: int, email: string, instructions: array, rows: array, allowed: array<string, true>, demo: array, emailLog: array}
 */
function ge_buildWorld(int $person, string $email, string $name): array
{
    global $mysqli;
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');

    ge_user($person, $name, $email);
    $allowed = [ge_keyText('tblUsers', ['userID' => $person]) => true];

    $instructions = GdprEraser::catalogue();

    $rows = [];
    foreach ($instructions as $i => $instruction) {
        $table  = (string) $instruction['table'];
        $col    = (string) $instruction['userCol'];
        $action = (string) $instruction['action'];
        if ($table === 'tblUsers') {
            // The account row itself was made above.
            $rows[] = ['i' => $i, 'kind' => 'R1', 'ref' => ['table' => 'tblUsers', 'key' => ['userID' => $person]]];
            continue;
        }

        // R1: the row the instruction should find. Every OTHER person column
        // on it names D, so "only this person's own number goes" is tested on
        // every table that can name two people.
        $ref    = ge_insert($table, $col === '' ? [] : [$col => $person]);
        $rows[] = ['i' => $i, 'kind' => 'R1', 'ref' => $ref];
        if ($action !== 'retain') {
            $allowed[ge_keyText($table, $ref['key'])] = true;
        }
        if ($action === 'anonymise') {
            ge_ensureParents($ref);
        }
    }

    // R2: a row naming the person in EVERY column its table has an "empty it"
    // instruction for (a task both given to them and created by them). Each
    // instruction must empty its own column on it, and the row must stay.
    //
    // CHANGED on 11 October 2026 (#492). R2 used to be made only for a table
    // with ONE instruction that emptied a second column "in passing". No
    // instruction does that any more (tools/gdpr-coverage-selftest.php fails
    // if one does): every person column has an instruction of its own, so R2
    // is now made for every table with two or more of them. Tables that also
    // delete or keep on a column are left out: the row would be deleted, or
    // would have to stay untouched, before the others ever looked at it.
    // A unique key may forbid the extra row; that is the one case skipped,
    // and section 3 checks enough of them were made.
    $emptied = [];
    $mixed   = [];
    foreach ($instructions as $i => $instruction) {
        $table = (string) $instruction['table'];
        if ((string) $instruction['action'] === 'anonymise' && $table !== 'tblUsers') {
            $emptied[$table][$i] = (string) $instruction['userCol'];
        } else {
            $mixed[$table] = true;
        }
    }
    $extra = [];
    foreach ($emptied as $table => $columns) {
        if (count($columns) < 2 || isset($mixed[$table]) === true) {
            continue;
        }
        try {
            $ref2   = ge_insert($table, array_fill_keys(array_values($columns), $person));
            $rows[] = ['i' => (int) array_key_first($columns), 'kind' => 'R2', 'ref' => $ref2, 'cols' => array_values($columns)];
            $allowed[ge_keyText($table, $ref2['key'])] = true;
            ge_ensureParents($ref2);
            // Every one of those instructions will report this row, so the
            // audit check has to count it for each of them.
            $extra[] = array_merge((array) ge_row($ref2), ['__table' => $table]);
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() !== 1062) {
                throw $e;
            }
        }
    }

    // 🧪 The Demo Data page's list: two entries point at this person and go;
    //    an entry for an announcement that merely has the same NUMBER stays.
    $userSite = null;
    foreach ($rows as $row) {
        if ($row['ref']['table'] === 'tblUserSites' && (string) $instructions[$row['i']]['userCol'] === 'userID') {
            $userSite = (int) $row['ref']['key']['userSiteID'];
        }
    }
    $demo = [
        'account'      => ge_insert('tblDemoDataRegister', [], ['tableName' => 'tblUsers', 'rowID' => $person]),
        'membership'   => ge_insert('tblDemoDataRegister', [], ['tableName' => 'tblUserSites', 'rowID' => (int) $userSite]),
        'announcement' => ge_insert('tblDemoDataRegister', [], ['tableName' => 'tblAnnouncements', 'rowID' => $person]),
    ];
    $allowed[ge_keyText('tblDemoDataRegister', $demo['account']['key'])]    = true;
    $allowed[ge_keyText('tblDemoDataRegister', $demo['membership']['key'])] = true;

    // 📧 The send log: one message to this person and D together.
    $emailLog = ge_insert('tblEmailLog', [], ['toRecipients' => $email . ', dee.control@selftest.invalid']);
    $allowed[ge_keyText('tblEmailLog', $emailLog['key'])] = true;

    // 🧾 A rendered giving statement on disk.
    $file = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving' . DIRECTORY_SEPARATOR
        . 'statements' . DIRECTORY_SEPARATOR . '1' . DIRECTORY_SEPARATOR . 'statement-' . $person . '-2025.pdf';
    file_put_contents($file, 'made-up statement');
    $GLOBALS['ge_files'][] = $file;

    // 🖼️ A photo file in the uploads folder, a picture address from single
    //    sign-on, a biography, and administrator rights: an erasure has to
    //    take all of them off the account.
    $photo = ge_file('_uploads/directory/' . $person . '.jpg');
    ge_q(
        "UPDATE tblUsers SET displayPhoto = ?, avatarPath = ?, displayBio = 'I have helped with the youth club since 2019.', "
        . 'isAdmin = 1, isRootAdmin = 1, totpEnabled = 1 WHERE userID = ?',
        'ssi',
        ['directory/' . $person . '.jpg', 'https://sso.selftest.invalid/photo/' . $person, $person]
    );

    // The kept giving-statement row has ONE column the erasure does empty
    // (the address it was mailed to), so that row is allowed to change; its
    // other columns are checked one by one in section 3.
    foreach ($rows as $row) {
        if ($row['ref']['table'] === 'tblGivingStatementLog') {
            $allowed[ge_keyText('tblGivingStatementLog', $row['ref']['key'])] = true;
        }
    }

    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

    return ['person' => $person, 'email' => $email, 'instructions' => $instructions, 'rows' => $rows,
        'allowed' => $allowed, 'demo' => $demo, 'emailLog' => $emailLog, 'file' => $file, 'photo' => $photo,
        'linkedBefore' => $extra];
}

/**
 * 🤝 A small set of rows that really ARE linked to each other, around one
 *    person about to be erased: other people's records that the person
 *    worked on, and a few records that are the person's own.
 *
 * The loop in ge_buildWorld() gives every instruction a row, but each row
 * stands alone. This is what real data looks like: a visitor with follow-up
 * notes under it, a swap request on a rota slot, a gift towards a pledge.
 *
 * @return array{refs: array<string, array>, before: array<string, ?array>, mayChange: string[]}
 */
function ge_buildLinked(int $p): array
{
    global $mysqli;
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
    $r = [];

    // 🚶 Visitors. One is somebody else, followed up by the person. One is
    //    C, from before C had an account. One is the person themselves.
    $r['visitor']        = ge_linked('tblVisitor', ['assignedToID' => $p, 'createdByID' => D], ['convertedUserID' => null, 'fullName' => 'Vera Visitor']);
    $r['noteByPerson']   = ge_linked('tblVisitorContact', ['contactedByID' => $p], ['visitorID' => ge_id($r['visitor']), 'summary' => 'Rang Vera; she will come on Sunday.']);
    $r['noteByOther']    = ge_linked('tblVisitorContact', ['contactedByID' => D], ['visitorID' => ge_id($r['visitor']), 'summary' => 'Vera came with her sister.']);
    $r['visitorIsC']     = ge_linked('tblVisitor', ['assignedToID' => $p, 'createdByID' => $p, 'convertedUserID' => C], ['fullName' => 'Cal Control']);
    $r['visitorIsP']     = ge_linked('tblVisitor', ['assignedToID' => D, 'createdByID' => D, 'convertedUserID' => $p], ['fullName' => 'Pat Erased']);
    $r['noteAboutP']     = ge_linked('tblVisitorContact', ['contactedByID' => D], ['visitorID' => ge_id($r['visitorIsP']), 'summary' => 'Pat asked about joining.']);

    // 🕊️ A salvation card about C, given to the person to follow up.
    $r['card']           = ge_linked('tblSalvationCards', ['assignedToID' => $p], ['fullName' => 'Cal Control', 'email' => 'cal.control@selftest.invalid']);

    // ✅ Tasks: one given to the person, one the person created for C.
    $r['taskGiven']      = ge_linked('tblTasks', ['assignedToID' => $p, 'createdByID' => D], ['title' => 'Ring the plumber', 'parentTaskID' => null]);
    $r['taskMade']       = ge_linked('tblTasks', ['assignedToID' => C, 'createdByID' => $p], ['title' => 'Order the chairs', 'parentTaskID' => null]);

    // 📅 Rota: the person's own slot; C's slot, which the person set up; and
    //    three swap requests on C's slot.
    $r['slotOfPerson']   = ge_linked('tblRotaSlot', ['assignedToID' => $p, 'createdByID' => D]);
    $r['slotOfC']        = ge_linked('tblRotaSlot', ['assignedToID' => C, 'createdByID' => $p]);
    $r['swapWaiting']    = ge_linked('tblRotaSwapRequest', ['requestedByID' => C, 'targetUserID' => $p], ['slotID' => ge_id($r['slotOfC']), 'status' => 'pending', 'requestMessage' => 'Could you cover me?']);
    $r['swapAccepted']   = ge_linked('tblRotaSwapRequest', ['requestedByID' => D, 'targetUserID' => $p], ['slotID' => ge_id($r['slotOfC']), 'status' => 'accepted']);
    $r['swapOfOthers']   = ge_linked('tblRotaSwapRequest', ['requestedByID' => C, 'targetUserID' => D], ['slotID' => ge_id($r['slotOfC']), 'status' => 'pending']);

    // 🧒 C's child, checked in by C and checked OUT by the person.
    $r['child']          = ge_linked('tblKidProfiles', ['parentUserID' => C], ['fullName' => 'Kit Control']);
    $r['checkin']        = ge_linked('tblKidCheckins', ['checkedInByID' => C, 'checkedOutByID' => $p], ['childID' => ge_id($r['child'])]);

    // 📝 A child's event registration that C submitted and the person only
    //    REVIEWED. It holds the child's medical details, and a registration
    //    is DELETED when whoever submitted it is erased - so it matters a
    //    great deal that reviewing one is not treated the same way.
    $r['registration']   = ge_linked('tblEventRegistrations', ['submittedByUserID' => C, 'reviewedByID' => $p], ['fullName' => 'Kit Control']);

    // 🫶 A pastoral case about C: one visit the person made, and one D made
    //    whose follow-up was given to the person.
    $r['case']           = ge_linked('tblCareCase', ['personUserID' => C, 'openedByID' => D]);
    $r['visitByPerson']  = ge_linked('tblCareVisit', ['visitedByID' => $p, 'followUpAssignedToID' => D], ['caseID' => ge_id($r['case']), 'notes' => 'Visited Cal at home.']);
    $r['visitFollowUp']  = ge_linked('tblCareVisit', ['visitedByID' => D, 'followUpAssignedToID' => $p], ['caseID' => ge_id($r['case']), 'notes' => 'Cal asked for a call next week.']);

    // 💷 Money. C's gift, C's Gift Aid declaration, C's expense claim with a
    //    payment the person entered. And the person's own: a pledge, a gift
    //    towards it, and a Gift Aid declaration.
    $r['giftOfC']        = ge_linked('tblGivingEntry', ['donorID' => C, 'recordedByID' => D], ['pledgeID' => null, 'campaignID' => null]);
    $r['giftAidOfC']     = ge_linked('tblGiftAidDeclaration', ['donorID' => C]);
    $r['claimOfC']       = ge_linked('tblExpenseClaims', ['userID' => C]);
    $r['payment']        = ge_linked('tblExpenseClaimPayments', ['paidByID' => $p], ['claimID' => ge_id($r['claimOfC'])]);
    $r['pledge']         = ge_linked('tblPledges', ['userID' => $p]);
    $pledge              = ge_row($r['pledge']);
    $r['giftToPledge']   = ge_linked('tblGivingEntry', ['donorID' => $p, 'recordedByID' => D], ['pledgeID' => ge_id($r['pledge']), 'campaignID' => (int) $pledge['campaignID']]);
    $r['giftAidOfPerson'] = ge_linked('tblGiftAidDeclaration', ['donorID' => $p]);

    // ✉️ Invitations: one the person ACCEPTED (it is how they joined, and it
    //    holds their own email address), and one they SENT to C.
    $r['inviteAccepted'] = ge_linked('tblInvitation', ['acceptedByID' => $p, 'createdByID' => D, 'revokedByID' => D], ['email' => 'pat.erased@selftest.invalid']);
    $r['inviteSent']     = ge_linked('tblInvitation', ['acceptedByID' => C, 'createdByID' => $p, 'revokedByID' => D], ['email' => 'cal.control@selftest.invalid']);

    // 📅 Two swap requests the person ASKED for, on their own slot: one still
    //    waiting for C's answer, one D accepted long ago.
    $r['swapAsked']      = ge_linked('tblRotaSwapRequest', ['requestedByID' => $p, 'targetUserID' => C], ['slotID' => ge_id($r['slotOfPerson']), 'status' => 'pending', 'requestMessage' => 'I am away that week.']);
    $r['swapAskedDone']  = ge_linked('tblRotaSwapRequest', ['requestedByID' => $p, 'targetUserID' => D], ['slotID' => ge_id($r['slotOfPerson']), 'status' => 'accepted']);

    // 🏠 A room booking the person made, still waiting for a decision.
    $r['booking']        = ge_linked('tblResourceBooking', ['bookedByID' => $p, 'approvedByID' => D], ['status' => 'pending', 'purpose' => 'Welcome team supper']);

    // 🎥 Zoom: the person's own connection with a meeting made through it,
    //    and a meeting the person made through C's connection.
    $r['zoomOfPerson']   = ge_linked('tblZoomAccount', ['userID' => $p]);
    $r['meetingViaPerson'] = ge_linked('tblZoomMeeting', ['createdByID' => D], ['accountID' => ge_id($r['zoomOfPerson'])]);
    $r['zoomOfC']        = ge_linked('tblZoomAccount', ['userID' => C]);
    $r['meetingViaC']    = ge_linked('tblZoomMeeting', ['createdByID' => $p], ['accountID' => ge_id($r['zoomOfC'])]);

    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

    $before = [];
    foreach ($r as $name => $ref) {
        $before[$name] = ge_row($ref);
    }

    // Everything NOT named here must come through the erasure byte for byte:
    // the whole-database comparison in section 4 holds it to that.
    $mayChange = ['inviteAccepted', 'inviteSent', 'swapAsked', 'swapAskedDone', 'booking', 'registration', 'visitor', 'noteByPerson', 'visitorIsC', 'visitorIsP', 'noteAboutP', 'card', 'taskGiven', 'taskMade',
        'slotOfPerson', 'slotOfC', 'swapWaiting', 'swapAccepted', 'checkin', 'visitByPerson', 'visitFollowUp', 'payment',
        'pledge', 'giftToPledge', 'zoomOfPerson', 'meetingViaPerson', 'meetingViaC'];

    return ['refs' => $r, 'before' => $before, 'mayChange' => $mayChange];
}

/**
 * 🔢 Give EVERY person column in the database the person's number.
 *
 * ge_buildWorld() makes a row for each column an instruction matches on. This
 * makes one for every OTHER column that can hold an account number - in every
 * table, whether the written list knows the table or not - so that section 4c
 * can say which of them an erasure leaves behind.
 *
 * Left out, and checked by name elsewhere: the account row itself, and the
 * erasure request (a made-up one would be closed along with the real one).
 *
 * @return array{rows: array<int, array{where: string, ref: array}>, untried: string[]}
 */
function ge_buildEveryPersonColumn(int $person, array $instructions): array
{
    global $mysqli;
    $matched = [];
    foreach ($instructions as $instruction) {
        $matched[(string) $instruction['table'] . '.' . (string) $instruction['userCol']] = true;
    }

    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
    $rows    = [];
    $untried = [];
    foreach (ge_q("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $one) {
        $table = (string) $one['t'];
        if (in_array($table, ['tblUsers', 'tblErasureRequest', 'tblErasureAudit'], true) === true) {
            continue;
        }
        foreach (ge_personColumns($table) as $column) {
            if (isset($matched[$table . '.' . $column]) === true) {
                continue;
            }
            try {
                $rows[] = ['where' => $table . '.' . $column, 'ref' => ge_insert($table, [$column => $person])];
            } catch (mysqli_sql_exception | RuntimeException $e) {
                // A unique key that will not take a second made-up row, or a
                // table with no key to find the row again by.
                $untried[] = $table . '.' . $column;
            }
        }
    }
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

    return ['rows' => $rows, 'untried' => $untried];
}

/** Run the real erasure. Returns its answer, or the exception it threw. */
function ge_execute(int $requestId, int $person, int $admin = ADMIN): mixed
{
    try {
        return GdprEraser::execute($requestId, $person, $admin);
    } catch (Throwable $e) {
        return $e;
    }
}

/**
 * Look at every made-up row after a run and say what is wrong with it.
 *
 * @param string[] $expectStillNamed "table.column" of instructions that were
 *                                   made to fail, whose rows must be untouched.
 *
 * @return array{problems: string[], left: string[], r2: int, checked: int}
 */
function ge_checkRows(array $world, array $before, array $expectStillNamed = []): array
{
    $person   = $world['person'];
    $problems = [];
    $left     = [];
    $r2       = 0;
    $checked  = 0;

    foreach ($world['rows'] as $n => $tracked) {
        $instruction = $world['instructions'][$tracked['i']];
        $table       = (string) $instruction['table'];
        $col         = (string) $instruction['userCol'];
        $action      = (string) $instruction['action'];
        $was         = $before[$n];
        $now         = ge_row($tracked['ref']);
        $label       = $table . ($col === '' ? '' : '.' . $col) . ' (' . $action . ', ' . $tracked['kind'] . ')';
        $checked++;
        if ($tracked['kind'] === 'R2') {
            $r2++;
        }
        if ($table === 'tblUsers') {
            continue; // checked by name in section 1
        }

        if (in_array($table . '.' . $col, $expectStillNamed, true) === true) {
            if ($now !== $was) {
                $problems[] = $label . ': its step failed, yet the row changed';
            }
            continue;
        }

        if ($action === 'delete') {
            if ($now !== null) {
                $problems[] = $label . ': the row is still there';
            }
            continue;
        }
        if ($now === null) {
            $problems[] = $label . ': the row was DELETED and should have been kept';
            continue;
        }

        $onUpdate = [];
        foreach (ge_columns($table) as $c) {
            if (stripos((string) $c['extra'], 'on update') !== false) {
                $onUpdate[] = $c['name'];
            }
        }

        if ($action === 'retain') {
            foreach ($now as $name => $value) {
                if ($table === 'tblGivingStatementLog' && $name === 'emailedTo') {
                    if ($value !== null) {
                        $problems[] = $label . ': the mailed-to address is still there';
                    }
                    continue;
                }
                if (in_array($name, $onUpdate, true) === false && $value !== $was[$name]) {
                    $problems[] = $label . ': kept row changed in `' . $name . '`';
                }
            }
            continue;
        }

        // anonymise
        $listed = array_map('strval', (array) ($instruction['nullCols'] ?? []));
        $people = ge_personColumns($table);
        // A column an instruction changes ON PURPOSE with a fixed piece of
        // SQL (`sqlSets`): today only the status of a swap request, which is
        // cancelled if it was waiting. This loop cannot work out what the
        // new value should be, so it steps over that column - and section 3b
        // checks the exact value by name, on a waiting request AND on an
        // accepted one. tools/gdpr-coverage-selftest.php holds the list of
        // such pieces to exactly that one.
        $changedOnPurpose = [];
        foreach ((array) ($instruction['sqlSets'] ?? []) as $piece) {
            if (preg_match('/^`(\w+)`\s*=/', (string) $piece, $m) === 1) {
                $changedOnPurpose[] = $m[1];
            }
        }
        foreach ($now as $name => $value) {
            if (in_array($name, $onUpdate, true) === true || in_array($name, $changedOnPurpose, true) === true) {
                continue;
            }
            // An R2 row names the person in several columns, each emptied
            // by its own instruction.
            $mayEmpty = $name === $col || in_array($name, $listed, true) === true
                || in_array($name, (array) ($tracked['cols'] ?? []), true) === true;
            $isPerson = in_array($name, $people, true) === true;
            if ($mayEmpty === true && ($isPerson === false || (int) $was[$name] === $person)) {
                // The person's own number, or free text about them: must go,
                // unless the database forbids emptying the column.
                if (ge_isNullable($table, $name) === true) {
                    if ($value !== null) {
                        $problems[] = $label . ': `' . $name . '` was not emptied';
                    }
                } else {
                    $left[] = $table . '.' . $name;
                    if ($value !== $was[$name]) {
                        $problems[] = $label . ': `' . $name . '` cannot be empty, yet it changed';
                    }
                }
                continue;
            }
            if ($value !== $was[$name]) {
                $problems[] = $label . ': `' . $name . '` changed from ' . json_encode($was[$name]) . ' to ' . json_encode($value)
                    . ($isPerson === true ? ' — ANOTHER PERSON\'S NUMBER' : '');
            }
            if ($isPerson === true && $value !== null && (int) $value === $person) {
                $problems[] = $label . ': `' . $name . '` still names the erased person';
            }
        }
    }

    return ['problems' => $problems, 'left' => array_values(array_unique($left)), 'r2' => $r2, 'checked' => $checked];
}

/**
 * Compare a run's audit lines with what its instructions should have written,
 * one by one and in order.
 *
 * @param array    $lines       The audit lines of ONE run, in order.
 * @param bool     $demoLine    Whether the Demo Data step wrote a line first.
 * @param string[] $failTables  Tables whose steps were made to fail.
 * @param string   $lastAction  'completed' for a clean run, 'failed' otherwise.
 *
 * @return string[] What is wrong; empty when the trail is exactly as expected.
 */
function ge_checkAudit(array $world, array $lines, bool $demoLine, array $failTables, string $lastAction): array
{
    $problems = [];
    $expected = [];
    if ($demoLine === true) {
        $expected[] = ['tblDemoDataRegister', ['delete']];
    }
    $rowsFor = [];
    foreach ($world['rows'] as $tracked) {
        if ($tracked['kind'] === 'R1') {
            $rowsFor[$tracked['i']] = ($rowsFor[$tracked['i']] ?? 0) + 1;
        }
    }
    // The R2 rows (the person in several columns of one row) and the
    // hand-built linked rows of section 3b are found by instructions too.
    // Each is counted for every instruction whose column names the person on
    // it, and each line's row count has to match EXACTLY: a count that is one
    // too high would mean an instruction touched a row it should not.
    foreach ($world['instructions'] as $i => $instruction) {
        foreach ($world['linkedBefore'] ?? [] as $row) {
            $column = (string) $instruction['userCol'];
            if ($row !== null && $column !== '' && $row['__table'] === $instruction['table']
                && array_key_exists($column, $row) === true && $row[$column] !== null && (int) $row[$column] === $world['person']
            ) {
                $rowsFor[$i] = ($rowsFor[$i] ?? 0) + 1;
            }
        }
    }
    foreach ($world['instructions'] as $i => $instruction) {
        $table  = (string) $instruction['table'];
        $name   = $table . '.' . (string) $instruction['userCol'];
        $action = (string) $instruction['action'];
        if (in_array($table, $failTables, true) === true) {
            $expected[] = [$table, ['failed']];
        } elseif ($action === 'anonymise' && in_array($name, KNOWN_LEFT_IN_PLACE, true) === true) {
            $expected[] = [$table, ['skip', 'partial']];
        } elseif ($action === 'retain') {
            $expected[] = [$table, ['retain']];
        } else {
            $expected[] = [$table, [$action], (string) ($rowsFor[$i] ?? 0) . ' rows'];
        }
    }
    $expected[] = ['GivingStatementPdfFiles', ['delete']];
    $expected[] = ['tblGivingStatementLog', ['anonymise']];
    $expected[] = ['tblEmailLog', ['anonymise']];
    $expected[] = ['ProfileImageFiles', ['delete'], '1 file(s)'];
    $expected[] = ['tblErasureRequest', [$lastAction]];

    if (count($lines) !== count($expected)) {
        $problems[] = count($lines) . ' audit lines were written and exactly ' . count($expected) . ' were expected';
    }
    foreach ($expected as $n => $want) {
        $line = $lines[$n] ?? null;
        if ($line === null) {
            $problems[] = 'line ' . ($n + 1) . ' is missing (expected ' . $want[0] . ')';
            break;
        }
        if ($line['tableName'] !== $want[0] || in_array($line['action'], $want[1], true) === false) {
            $problems[] = 'line ' . ($n + 1) . ': expected ' . $want[0] . ' ' . implode('/', $want[1]) . ', found '
                . $line['tableName'] . ' ' . $line['action'] . ' (' . mb_substr((string) $line['details'], 0, 120) . ')';
        } elseif (isset($want[2]) === true && $line['action'] !== 'partial' && (string) $line['recordKey'] !== $want[2]) {
            $problems[] = 'line ' . ($n + 1) . ' (' . $want[0] . '): expected "' . $want[2] . '", found "' . (string) $line['recordKey'] . '"';
        }
        if (stripos((string) $line['details'], 'table-missing') !== false) {
            $problems[] = 'line ' . ($n + 1) . ' (' . $want[0] . ') says "table-missing" about a table that exists';
        }
    }

    return $problems;
}

/** The tracked row of one instruction, found by table and matched column. */
function ge_find(array $world, string $table, string $col): ?array
{
    foreach ($world['rows'] as $n => $tracked) {
        $instruction = $world['instructions'][$tracked['i']];
        if ($tracked['kind'] === 'R1' && $instruction['table'] === $table && $instruction['userCol'] === $col) {
            return ['n' => $n, 'ref' => $tracked['ref'], 'action' => (string) $instruction['action']];
        }
    }

    return null;
}

// =============================================================================
ge_section('0', 'The made-up world');
// =============================================================================
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(C, 'Cal Control', 'cal.control@selftest.invalid');
ge_user(D, 'Dee Control', 'dee.control@selftest.invalid');
ge_user(ADMIN, 'Ada Admin', 'ada.admin@selftest.invalid');
// C's own row in every table an instruction touches: every person column on
// it names C. And one row in each kept table that no column ties to anybody.
$controlTables = [];
foreach (GdprEraser::catalogue() as $instruction) {
    $controlTables[(string) $instruction['table']] = true;
}
unset($controlTables['tblUsers']);
foreach (array_keys($controlTables) as $table) {
    ge_insert($table, [], [], C);
}
ge_insert('tblDemoDataRegister', [], ['tableName' => 'tblUsers', 'rowID' => C]);
ge_insert('tblEmailLog', [], ['toRecipients' => 'cal.control@selftest.invalid']);
$controlFile = $statementDir . DIRECTORY_SEPARATOR . 'statement-' . C . '-2025.pdf';
file_put_contents($controlFile, 'made-up statement');
$GLOBALS['ge_files'][] = $controlFile;
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

$worldA = ge_buildWorld(P_A, 'pat.erased@selftest.invalid', 'Pat Erased');
ge_request(REQ_A, P_A, 'pat.erased@selftest.invalid', 'Pat Erased', 'processing', 'Pat rang the office about this.');
ge_request(REQ_A_DUP, P_A, 'pat.erased@selftest.invalid', 'Pat Erased', 'pending_review');
ge_request(REQ_A_OLD, P_A, 'pat.old-address@selftest.invalid', 'Pat Erased', 'cancelled', 'Pat changed their mind.');
ge_request(REQ_C, C, 'cal.control@selftest.invalid', 'Cal Control', 'pending_review', 'Cal\'s own request.');
// Roll-out package A2a: three more requests around the same account.
//  - one of P_A's own left at "processing" by a run that died;
//  - one P_A filed at a SECOND organisation, still waiting there;
//  - D's request, finished long ago, which P_A carried out as administrator.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_insert('tblSites', [], ['siteID' => SITE_2]);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_A_STUCK, P_A, 'pat.erased@selftest.invalid', 'Pat Erased', 'processing');
ge_request(REQ_A_SITE2, P_A, 'pat.erased@selftest.invalid', 'Pat Erased', 'pending_review');
ge_q('UPDATE tblErasureRequest SET siteID = ? WHERE requestID = ?', 'ii', [SITE_2, REQ_A_SITE2]);
ge_request(REQ_D_DONE, null, 'deleted-' . REQ_D_DONE . '@example.invalid', '', 'completed');
ge_q('UPDATE tblErasureRequest SET subjectName = NULL, processedAt = NOW(), processedByID = ? WHERE requestID = ?', 'ii', [P_A, REQ_D_DONE]);
foreach ([REQ_A, REQ_A_DUP, REQ_A_OLD, REQ_A_STUCK, REQ_A_SITE2, REQ_D_DONE] as $requestId) {
    $worldA['allowed'][ge_keyText('tblErasureRequest', ['requestID' => $requestId])] = true;
}
$requestDoneWas = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_D_DONE]);

// 🤝 The linked rows of section 3b.
$linkedA = ge_buildLinked(P_A);
// Each linked row as it was, with its table's name beside it, so the audit
// check can work out how many rows every instruction should report.
foreach ($linkedA['before'] as $name => $row) {
    $worldA['linkedBefore'][$name] = $row === null ? null : array_merge($row, ['__table' => $linkedA['refs'][$name]['table']]);
}
foreach ($linkedA['mayChange'] as $name) {
    $worldA['allowed'][ge_keyText($linkedA['refs'][$name]['table'], $linkedA['refs'][$name]['key'])] = true;
}

// 🖼️ Photos. P_A's own file is made by ge_buildWorld(). Here: C's own photo,
//    which must not be touched, and a file that P_A's account AND D's both
//    point at, which must be kept because it is D's photo too.
$controlPhoto = ge_file('_uploads/directory/' . C . '.jpg');
ge_q('UPDATE tblUsers SET displayPhoto = ? WHERE userID = ?', 'si', ['directory/' . C . '.jpg', C]);
$sharedPhoto = ge_file('_uploads/directory/shared-with-dee.png');
ge_q("UPDATE tblUsers SET avatarPath = 'directory/shared-with-dee.png' WHERE userID = ?", 'i', [P_A]);
ge_q("UPDATE tblUsers SET displayPhoto = 'directory/shared-with-dee.png' WHERE userID = ?", 'i', [D]);

// 📧 The send log, for section 5c: P_A's address beside a LONGER address that
//    contains it; that longer address on its own (must not be touched); and
//    P_A's address written with capitals and no space after the comma.
$logBesideLonger = ge_insert('tblEmailLog', [], ['toRecipients' => 'xpat.erased@selftest.invalid, pat.erased@selftest.invalid']);
$logOnlyLonger   = ge_insert('tblEmailLog', [], ['toRecipients' => 'xpat.erased@selftest.invalid']);
$logCapitals     = ge_insert('tblEmailLog', [], ['toRecipients' => 'Pat.Erased@SELFTEST.invalid,dee.control@selftest.invalid']);
$worldA['allowed'][ge_keyText('tblEmailLog', $logBesideLonger['key'])] = true;
$worldA['allowed'][ge_keyText('tblEmailLog', $logCapitals['key'])]     = true;

// 🔢 The person's number in every OTHER person column of every table (4c).
$everyColumnA = ge_buildEveryPersonColumn(P_A, $worldA['instructions']);

// 📝 A step of an approval route given to the person by name: the one place
//    an account number is kept as TEXT (see TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER).
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$stepForPerson = ge_insert('tblWorkflowSteps', [], ['assigneeType' => 'user', 'assigneeValue' => (string) P_A]);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

$instructionCount = count($worldA['instructions']);
$tablesCovered    = count($controlTables) + 1;
echo '   ' . $instructionCount . ' instructions over ' . $tablesCovered . ' tables; ' . count($worldA['rows'])
    . " made-up rows for the person; one control row per table.\n";
ge_ok('the world was built: a row for every instruction (more than 130 of them)',
    $instructionCount > 130 && count($worldA['rows']) >= $instructionCount,
    $instructionCount . ' instructions, ' . count($worldA['rows']) . ' rows');

$beforeRowsA = [];
foreach ($worldA['rows'] as $n => $tracked) {
    $beforeRowsA[$n] = ge_row($tracked['ref']);
}
$missingBefore = array_keys(array_filter($beforeRowsA, static fn ($row): bool => $row === null));
ge_ok('every made-up row can be read back before the run', $missingBefore === [], json_encode($missingBefore));
$printsBeforeA = ge_snapshot();
echo '   ' . count($printsBeforeA) . " rows fingerprinted across the whole database.\n";

// "What we hold about you" (/account/my-data) must list the person's own
// erasure requests and the state each is in. It briefly stopped doing so: the
// list is built from the same instructions as the erasure, and #510 took the
// request out of those.
$ownRequest = static function (int $person): ?array {
    foreach (GdprEraser::inventory($person) as $held) {
        if ($held['table'] === 'tblErasureRequest') {
            return $held;
        }
    }

    return null;
};
$heldA = $ownRequest(P_A);
$heldC = $ownRequest(C);
ge_ok('"what we hold about you" lists the person\'s own erasure requests, with the state of each',
    $heldA !== null && $heldA['rows'] === 5 && $heldA['action'] === 'anonymise'
    && str_contains($heldA['reason'], '2 processing') && str_contains($heldA['reason'], '2 pending review')
    && str_contains($heldA['reason'], '1 cancelled')
    && $heldC !== null && $heldC['rows'] === 1 && str_contains($heldC['reason'], '1 pending review'),
    json_encode([$heldA, $heldC]));

// =============================================================================
ge_section('1', 'A run ends, the request survives, and it is closed (#510)');
// =============================================================================
$resultA = ge_execute(REQ_A, P_A);
ge_ok('the run ends without an exception',
    ($resultA instanceof Throwable) === false,
    $resultA instanceof Throwable ? get_class($resultA) . ': ' . $resultA->getMessage() : '');
if ($resultA instanceof Throwable || is_array($resultA) === false) {
    // Nothing below can be trusted after a crash, and running on would bury
    // the one line that matters under a hundred that follow from it.
    echo "\nThe erasure did not finish, so the remaining sections were not run.\n";
    ge_finish();
}
ge_ok('it reports no failed step, and the number of steps it ran',
    $resultA['failed'] === 0 && $resultA['changed'] === true && $resultA['steps'] === $instructionCount + 5,
    json_encode($resultA) . ' (expected steps: ' . ($instructionCount + 5) . ')');

$reqA = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_A]);
ge_ok('the request row still exists and is "completed"', $reqA !== null && $reqA['status'] === 'completed', json_encode($reqA));
ge_ok('its account number, name, notes and token are cleared, and its address replaced',
    $reqA !== null && $reqA['userID'] === null && $reqA['subjectName'] === null && $reqA['notes'] === null
    && $reqA['confirmToken'] === null && $reqA['subjectEmail'] === 'deleted-' . REQ_A . '@example.invalid',
    json_encode($reqA));
ge_ok('its dates and who processed it are kept (the record that it was done)',
    $reqA !== null && $reqA['processedAt'] !== null && (int) $reqA['processedByID'] === ADMIN
    && $reqA['requestedAt'] !== null && $reqA['dueBy'] !== null, json_encode($reqA));

$reqDup = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_A_DUP]);
ge_ok('a second waiting request from the same account is closed with it and cleared',
    $reqDup !== null && $reqDup['status'] === 'completed' && $reqDup['userID'] === null && $reqDup['subjectName'] === null
    && $reqDup['subjectEmail'] === 'deleted-' . REQ_A_DUP . '@example.invalid',
    json_encode($reqDup));
$dupAudit = ge_audit(REQ_A_DUP);
ge_ok('...with one line in its own audit trail naming the request that did the work',
    count($dupAudit) === 1 && $dupAudit[0]['action'] === 'completed' && str_contains((string) $dupAudit[0]['details'], '#' . REQ_A)
    && GdprEraser::verifyAuditChain(REQ_A_DUP) === true, json_encode($dupAudit));
// Roll-out package A2a. One left at "processing" by a run that died used to
// have its account number cleared and then stay at "processing" for ever.
$reqStuck   = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_A_STUCK]);
$stuckAudit = ge_audit(REQ_A_STUCK);
ge_ok('another request from the same account that was stuck at "processing" is closed with it too, with one line saying why',
    $reqStuck !== null && $reqStuck['status'] === 'completed' && $reqStuck['userID'] === null
    && $reqStuck['subjectEmail'] === 'deleted-' . REQ_A_STUCK . '@example.invalid'
    && count($stuckAudit) === 1 && $stuckAudit[0]['action'] === 'completed' && str_contains((string) $stuckAudit[0]['details'], '#' . REQ_A),
    json_encode([$reqStuck, $stuckAudit]));
// A request at ANOTHER organisation is closed too. Its administrator cannot
// open this organisation's report, so the line must not send them to it, nor
// hand them this organisation's request number.
$reqSite2   = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_A_SITE2]);
$site2Audit = ge_audit(REQ_A_SITE2);
$site2Line  = (string) ($site2Audit[0]['details'] ?? '');
ge_ok('a waiting request at ANOTHER organisation is closed too, and its line does not send that organisation to a report it cannot open',
    $reqSite2 !== null && $reqSite2['status'] === 'completed' && $reqSite2['userID'] === null && (int) $reqSite2['siteID'] === SITE_2
    && count($site2Audit) === 1 && str_contains($site2Line, 'another organisation')
    && str_contains($site2Line, 'nothing more needs doing here')
    && str_contains($site2Line, 'that request') === false && str_contains($site2Line, '#' . REQ_A) === false
    && GdprEraser::verifyAuditChain(REQ_A_SITE2) === true,
    json_encode([$reqSite2, $site2Audit]));
// D's finished request, which the erased person carried out as administrator.
$requestDoneNow = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_D_DONE]);
$closingLine    = ge_audit(REQ_A);
$closingLine    = (string) ($closingLine[count($closingLine) - 1]['details'] ?? '');
ge_ok('on somebody else\'s request that the erased person handled as administrator, only who handled it is cleared, and the closing line says so',
    (int) $requestDoneWas['processedByID'] === P_A && $requestDoneNow !== null && $requestDoneNow['processedByID'] === null
    && ge_same($requestDoneWas, $requestDoneNow, ['processedByID']) === true && count(ge_audit(REQ_D_DONE)) === 0
    && str_contains($closingLine, 'On 1 other request(s) that this person handled as an administrator'),
    json_encode([$requestDoneNow, $closingLine]));
$reqOld = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_A_OLD]);
ge_ok('an older, cancelled request from the same account is cleared and stays "cancelled"',
    $reqOld !== null && $reqOld['status'] === 'cancelled' && $reqOld['userID'] === null && $reqOld['notes'] === null
    && $reqOld['subjectEmail'] === 'deleted-' . REQ_A_OLD . '@example.invalid', json_encode($reqOld));
$reqC = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_C]);
ge_ok('ANOTHER person\'s waiting request is untouched',
    $reqC !== null && $reqC['status'] === 'pending_review' && (int) $reqC['userID'] === C
    && $reqC['subjectEmail'] === 'cal.control@selftest.invalid' && $reqC['notes'] === 'Cal\'s own request.', json_encode($reqC));

ge_ok('after the erasure nothing links a request to the erased account, and the OTHER person still sees their own request',
    $ownRequest(P_A) === null && ($ownRequest(C)['rows'] ?? 0) === 1, json_encode([$ownRequest(P_A), $ownRequest(C)]));

$userA = ge_one('SELECT * FROM tblUsers WHERE userID = ?', 'i', [P_A]);
ge_ok('the account row is emptied, never deleted',
    $userA !== null && $userA['fullName'] === GdprEraser::TOMBSTONE_NAME
    && $userA['emailAddress'] === 'deleted-' . REQ_A . '@example.invalid' && $userA['phoneNumber'] === null
    && $userA['displayAddress'] === null && $userA['latitude'] === null && $userA['totpSecret'] === null
    && (int) $userA['isActive'] === 0, json_encode($userA));
// Roll-out package A2a. All of these were left on the "emptied" account.
$userAWas = $beforeRowsA[ge_find($worldA, 'tblUsers', 'userID')['n']];

// THE WHOLE ROW, against an exact list (see ACCOUNT_AFTER_ERASURE for why).
$accountProblems = [];
$sorted          = array_merge(array_keys(ACCOUNT_AFTER_ERASURE), ACCOUNT_COLUMNS_LEFT_AS_THEY_WERE);
foreach (array_diff(array_keys((array) $userA), $sorted) as $column) {
    $accountProblems[] = '`' . $column . '` is on neither list: decide whether an erasure must empty it';
}
foreach (array_diff($sorted, array_keys((array) $userA)) as $column) {
    $accountProblems[] = '`' . $column . '` is on a list and is not a column of tblUsers';
}
foreach (ACCOUNT_AFTER_ERASURE as $column => $expected) {
    $expected = $expected === '@request' ? 'deleted-' . REQ_A . '@example.invalid' : $expected;
    $actual   = $userA[$column] ?? null;
    if ($actual !== $expected) {
        $accountProblems[] = '`' . $column . '` holds ' . json_encode($actual) . ', expected ' . json_encode($expected);
    }
    if (($userAWas[$column] ?? null) === $expected) {
        $accountProblems[] = '`' . $column . '` already held ' . json_encode($expected) . ' before the run, so nothing was proven';
    }
}
foreach (ACCOUNT_COLUMNS_LEFT_AS_THEY_WERE as $column) {
    if (($userA[$column] ?? null) !== ($userAWas[$column] ?? null)) {
        $accountProblems[] = '`' . $column . '` changed from ' . json_encode($userAWas[$column] ?? null) . ' to ' . json_encode($userA[$column] ?? null);
    }
}
ge_ok('the emptied account matches an EXACT list, column by column: ' . count(ACCOUNT_AFTER_ERASURE) . ' columns hold exactly what they should, '
    . count(ACCOUNT_COLUMNS_LEFT_AS_THEY_WERE) . ' are as they were, and no column of the account is on neither list',
    $accountProblems === [], implode('; ', $accountProblems));
ge_ok('the biography the person wrote is cleared, and so are the links to their photo',
    $userAWas['displayBio'] !== null && $userAWas['displayPhoto'] !== null && $userAWas['avatarPath'] !== null
    && $userA['displayBio'] === null && $userA['displayPhoto'] === null && $userA['avatarPath'] === null, json_encode($userA));
ge_ok('an emptied account is no longer an administrator, and its two-step sign-in is switched off',
    (int) $userAWas['isAdmin'] === 1 && (int) $userAWas['isRootAdmin'] === 1 && (int) $userAWas['totpEnabled'] === 1
    && (int) $userA['isAdmin'] === 0 && (int) $userA['isRootAdmin'] === 0 && (int) $userA['totpEnabled'] === 0, json_encode($userA));
// The key inside the person's private calendar-feed address. The feed looks
// an account up by it without asking whether the account is still active, so
// while it stayed on the emptied account that address went on answering.
ge_ok('the key to the person\'s private calendar feed is cleared, so the feed address stops working; so are their notification choices',
    $userAWas['calendarToken'] !== null && $userAWas['notifyPrefs'] !== null
    && $userA['calendarToken'] === null && $userA['notifyPrefs'] === null
    && ge_one('SELECT userID FROM tblUsers WHERE calendarToken = ?', 's', [(string) $userAWas['calendarToken']]) === null,
    json_encode([$userA['calendarToken'], $userA['notifyPrefs']]));
$photoLine = array_values(array_filter(ge_audit(REQ_A), static fn (array $l): bool => $l['tableName'] === 'ProfileImageFiles'));
ge_ok('the photo FILE is deleted; another person\'s photo is still there; a file another account also uses is kept, and the line says so',
    is_file($worldA['photo']) === false && is_file($controlPhoto) === true && is_file($sharedPhoto) === true
    && count($photoLine) === 1 && $photoLine[0]['action'] === 'delete' && $photoLine[0]['recordKey'] === '1 file(s)'
    && str_contains((string) $photoLine[0]['details'], '1 file(s) kept because another account uses the same one')
    && str_contains((string) $photoLine[0]['details'], (string) P_A) === false,
    json_encode([is_file($worldA['photo']), is_file($controlPhoto), is_file($sharedPhoto), $photoLine]));

// =============================================================================
ge_section('2', 'The audit trail: one line per instruction, in order, and sealed');
// =============================================================================
$auditA = ge_audit(REQ_A);
$auditProblems = ge_checkAudit($worldA, $auditA, true, [], 'completed');
ge_ok('exactly one line per instruction, each for the right table, with the right outcome and row count ('
    . count($auditA) . ' lines)', $auditProblems === [], implode('; ', array_slice($auditProblems, 0, 8)));
ge_ok('the number of lines is the instructions, the Demo Data step, the four dedicated lines and the closing line',
    count($auditA) === $instructionCount + 6, count($auditA) . ' lines for ' . $instructionCount . ' instructions');
ge_ok('the chain of the audit trail verifies', GdprEraser::verifyAuditChain(REQ_A) === true);
$keptLines = array_values(array_filter($auditA, static fn (array $l): bool => $l['action'] === 'retain'));
$keptCount = count(array_filter($worldA['instructions'], static fn (array $e): bool => $e['action'] === 'retain'));
ge_ok('every kept table has a "retain" line, and none says a table is missing (' . $keptCount . ' kept tables)',
    $keptCount >= 8 && count($keptLines) === $keptCount, count($keptLines) . ' retain lines for ' . $keptCount . ' kept tables');
$notSearched = array_values(array_filter($keptLines, static fn (array $l): bool => str_contains((string) $l['details'], 'not searched')));
$noColumn    = count(array_filter($worldA['instructions'], static fn (array $e): bool => $e['action'] === 'retain' && $e['userCol'] === ''));
ge_ok('a kept table that no column ties to an account says so, rather than claiming to have looked',
    $noColumn >= 5 && count($notSearched) === $noColumn, count($notSearched) . ' of ' . $noColumn);
// ...and that statement has to be TRUE. It goes into a sealed record. It was
// made about pastoral visits, which carry the account number of whoever made
// the visit; the written list simply had not named the column. So the claim is
// checked against the database itself: its own links to accounts, and every
// column name on GdprEraser's list.
$falselyUnlinked = [];
foreach ($worldA['instructions'] as $instruction) {
    if ($instruction['action'] === 'retain' && $instruction['userCol'] === '' && ge_personColumns((string) $instruction['table']) !== []) {
        $falselyUnlinked[] = $instruction['table'] . ' (has ' . implode(', ', ge_personColumns((string) $instruction['table'])) . ')';
    }
}
ge_ok('every table the audit trail says "no column ties to an account" really has no such column in the database',
    $falselyUnlinked === [], implode('; ', $falselyUnlinked));
// CHANGED by roll-out package A2a. This used to expect ONE "retain" line
// saying the number stays because the column cannot be empty. Migration 208
// made it emptiable, so there are now two lines, one per person column, each
// saying the visit is kept and who is detached.
$visitLine = array_values(array_filter($auditA, static fn (array $l): bool => $l['tableName'] === 'tblCareVisit'));
ge_ok('tblCareVisit: two audit lines, one for who made a visit and one for who its follow-up was given to, each saying the visit is kept',
    count($visitLine) === 2 && $visitLine[0]['action'] === 'anonymise' && $visitLine[1]['action'] === 'anonymise'
    && str_contains((string) $visitLine[0]['details'], 'who made the visit is detached')
    && str_contains((string) $visitLine[1]['details'], 'who its follow-up was given to is detached')
    && str_contains((string) $visitLine[0]['details'], 'kept with the case')
    && str_contains((string) $visitLine[0]['details'], 'not searched') === false, json_encode($visitLine));

// =============================================================================
ge_section('3', 'Every instruction did what it says, and only that');
// =============================================================================
$rowsA = ge_checkRows($worldA, $beforeRowsA);
ge_ok('every made-up row was looked at (' . $rowsA['checked'] . ' rows)', $rowsA['checked'] === count($worldA['rows']) && $rowsA['checked'] >= $instructionCount);
ge_ok('delete: no row of the person is left. unlink: the row stays and their own number is gone. keep: the row is unchanged. '
    . 'And no OTHER person\'s number on any of those rows changed',
    $rowsA['problems'] === [], implode('; ', array_slice($rowsA['problems'], 0, 8)));
$stillThere = [];
foreach (MUST_BE_EMPTIED as $where => $columns) {
    [$table, $col] = explode('.', $where);
    $found = ge_find($worldA, $table, $col);
    $now   = $found === null ? null : ge_row($found['ref']);
    foreach ($columns as $column) {
        if ($found === null || $now === null) {
            $stillThere[] = $where . ': no row to look at';
        } elseif ($beforeRowsA[$found['n']][$column] === null) {
            $stillThere[] = $where . ': `' . $column . '` was empty before the run, so nothing was proven';
        } elseif ($now[$column] !== null) {
            $stillThere[] = $where . ': `' . $column . '` still holds ' . json_encode($now[$column]);
        }
    }
}
ge_ok('typed details about the person are emptied, by name: a prayer request\'s name, email address and sender address, and a pastoral case\'s name',
    $stillThere === [], implode('; ', $stillThere));
$twoColumnTables = [];
foreach ($worldA['rows'] as $tracked) {
    if ($tracked['kind'] === 'R2') {
        $twoColumnTables[] = $tracked['ref']['table'];
    }
}
// CHANGED on 11 October 2026 (#492): "at least 3" became "at least 15", now
// that every table with two or more "empty it" instructions gets such a row.
ge_ok('rows naming the person in TWO OR MORE columns were tested too, each column emptied by its own instruction (' . count($twoColumnTables)
    . ' tables: ' . implode(', ', $twoColumnTables) . ')',
    $rowsA['r2'] >= 15 && $rowsA['r2'] === count($twoColumnTables), 'only ' . $rowsA['r2']);
sort($rowsA['left']);
ge_ok('the columns that could not be emptied are exactly the known list: ' . implode(', ', KNOWN_LEFT_IN_PLACE),
    $rowsA['left'] === KNOWN_LEFT_IN_PLACE, json_encode($rowsA['left']));
$leftLines = array_values(array_filter($auditA, static fn (array $l): bool => in_array($l['action'], ['skip', 'partial'], true)));
ge_ok('...and each has an audit line saying why (' . count($leftLines) . ' line)',
    count($leftLines) === count(KNOWN_LEFT_IN_PLACE) && str_contains((string) ($leftLines[0]['details'] ?? ''), 'does not allow'),
    json_encode($leftLines));

// =============================================================================
ge_section('3b', 'Other people\'s records survive, with rows that really are linked (roll-out package A2a)');
// =============================================================================
// Until 11 October 2026 the first eight checks here would each have found the
// row DELETED: an erasure deleted every visitor, salvation card, task and rota
// slot that had been given to the person, and every swap request sent to them.
$was = $linkedA['before'];
$now = [];
foreach ($linkedA['refs'] as $name => $ref) {
    $now[$name] = ge_row($ref);
}
$linkedBuilt = array_keys(array_filter($was, static fn ($row): bool => $row === null));
ge_ok('the linked rows were all built, and each named the person where it should before the run (' . count($was) . ' rows)',
    $linkedBuilt === [] && (int) $was['visitor']['assignedToID'] === P_A && (int) $was['noteByPerson']['contactedByID'] === P_A
    && (int) $was['card']['assignedToID'] === P_A && (int) $was['taskGiven']['assignedToID'] === P_A
    && (int) $was['slotOfPerson']['assignedToID'] === P_A && (int) $was['swapWaiting']['targetUserID'] === P_A
    && (int) $was['checkin']['checkedOutByID'] === P_A && (int) $was['visitByPerson']['visitedByID'] === P_A
    && (int) $was['visitFollowUp']['followUpAssignedToID'] === P_A && (int) $was['payment']['paidByID'] === P_A
    && (int) $was['visitorIsP']['convertedUserID'] === P_A && (int) $was['meetingViaC']['createdByID'] === P_A,
    json_encode($linkedBuilt));

ge_ok('a VISITOR the person was following up is kept, with their name, contact details and notes; only the worker is detached',
    ge_same($was['visitor'], $now['visitor'], ['assignedToID']) === true && $now['visitor']['assignedToID'] === null
    && $now['visitor']['fullName'] === 'Vera Visitor' && (int) $now['visitor']['createdByID'] === D,
    json_encode($now['visitor']));
ge_ok('...and so are the follow-up notes about that visitor: the person\'s own note stays with its words and loses only its author; a colleague\'s note is untouched',
    ge_same($was['noteByPerson'], $now['noteByPerson'], ['contactedByID']) === true && $now['noteByPerson']['contactedByID'] === null
    && $now['noteByPerson']['summary'] === 'Rang Vera; she will come on Sunday.'
    && $now['noteByOther'] === $was['noteByOther'],
    json_encode([$now['noteByPerson'], $now['noteByOther']]));
ge_ok('ANOTHER PERSON\'S own visitor record (they later became a member) is kept; the erased person is detached as its worker and as who typed it',
    ge_same($was['visitorIsC'], $now['visitorIsC'], ['assignedToID', 'createdByID']) === true
    && (int) $now['visitorIsC']['convertedUserID'] === C && $now['visitorIsC']['assignedToID'] === null
    && $now['visitorIsC']['createdByID'] === null, json_encode($now['visitorIsC']));
ge_ok('the erased person\'s OWN visitor record is deleted, and the notes about them go with it',
    $now['visitorIsP'] === null && $now['noteAboutP'] === null, json_encode([$now['visitorIsP'], $now['noteAboutP']]));
ge_ok('a SALVATION CARD about somebody else is kept whole; only the worker is detached',
    ge_same($was['card'], $now['card'], ['assignedToID']) === true && $now['card']['assignedToID'] === null
    && $now['card']['fullName'] === 'Cal Control' && $now['card']['email'] === 'cal.control@selftest.invalid',
    json_encode($now['card']));
ge_ok('a TASK given to the person is kept and unassigned; a task they created for somebody else is kept, still assigned to that person',
    ge_same($was['taskGiven'], $now['taskGiven'], ['assignedToID']) === true && $now['taskGiven']['assignedToID'] === null
    && (int) $now['taskGiven']['createdByID'] === D && $now['taskGiven']['title'] === 'Ring the plumber'
    && ge_same($was['taskMade'], $now['taskMade'], ['createdByID']) === true && $now['taskMade']['createdByID'] === null
    && (int) $now['taskMade']['assignedToID'] === C, json_encode([$now['taskGiven'], $now['taskMade']]));
ge_ok('a ROTA SLOT the person was filling is kept and unfilled; a slot they set up for somebody else is kept, still filled by that person',
    ge_same($was['slotOfPerson'], $now['slotOfPerson'], ['assignedToID']) === true && $now['slotOfPerson']['assignedToID'] === null
    && ge_same($was['slotOfC'], $now['slotOfC'], ['createdByID']) === true && $now['slotOfC']['createdByID'] === null
    && (int) $now['slotOfC']['assignedToID'] === C, json_encode([$now['slotOfPerson'], $now['slotOfC']]));
ge_ok('a SWAP REQUEST aimed at the person is kept and CANCELLED, not deleted: it still shows who asked, and their message',
    ge_same($was['swapWaiting'], $now['swapWaiting'], ['targetUserID', 'status']) === true
    && $now['swapWaiting']['status'] === 'cancelled' && $now['swapWaiting']['targetUserID'] === null
    && (int) $now['swapWaiting']['requestedByID'] === C && $now['swapWaiting']['requestMessage'] === 'Could you cover me?',
    json_encode($now['swapWaiting']));
ge_ok('...one already accepted keeps its status (that is history), and a request between two other people is untouched',
    ge_same($was['swapAccepted'], $now['swapAccepted'], ['targetUserID']) === true && $now['swapAccepted']['status'] === 'accepted'
    && $now['swapAccepted']['targetUserID'] === null && $now['swapOfOthers'] === $was['swapOfOthers'],
    json_encode([$now['swapAccepted'], $now['swapOfOthers']]));
ge_ok('a CHILD\'S CHECK-IN made by somebody else and checked OUT by the person loses only the check-out number; the child\'s profile is untouched',
    ge_same($was['checkin'], $now['checkin'], ['checkedOutByID']) === true && $now['checkin']['checkedOutByID'] === null
    && (int) $now['checkin']['checkedInByID'] === C && $now['child'] === $was['child'], json_encode($now['checkin']));
ge_ok('an INVITATION the person ACCEPTED, which holds their own email address, is deleted; one they only SENT to somebody else is kept whole, with only who sent it detached',
    (int) $was['inviteAccepted']['acceptedByID'] === P_A && $now['inviteAccepted'] === null
    && ge_same($was['inviteSent'], $now['inviteSent'], ['createdByID']) === true && $now['inviteSent']['createdByID'] === null
    && (int) $now['inviteSent']['acceptedByID'] === C && $now['inviteSent']['email'] === 'cal.control@selftest.invalid',
    json_encode([$now['inviteAccepted'], $now['inviteSent']]));
ge_ok('a SWAP REQUEST the person ASKED for is kept with its message and cancelled if it was still waiting; one already accepted keeps its status; who asked is detached on both',
    (int) $was['swapAsked']['requestedByID'] === P_A
    && ge_same($was['swapAsked'], $now['swapAsked'], ['requestedByID', 'status']) === true && $now['swapAsked']['requestedByID'] === null
    && $now['swapAsked']['status'] === 'cancelled' && (int) $now['swapAsked']['targetUserID'] === C && $now['swapAsked']['requestMessage'] === 'I am away that week.'
    && ge_same($was['swapAskedDone'], $now['swapAskedDone'], ['requestedByID']) === true && $now['swapAskedDone']['requestedByID'] === null
    && $now['swapAskedDone']['status'] === 'accepted', json_encode([$now['swapAsked'], $now['swapAskedDone']]));
ge_ok('a ROOM BOOKING the person made is kept, with its times, purpose and status; only who booked it is detached',
    (int) $was['booking']['bookedByID'] === P_A && ge_same($was['booking'], $now['booking'], ['bookedByID']) === true
    && $now['booking']['bookedByID'] === null && $now['booking']['purpose'] === 'Welcome team supper' && $now['booking']['status'] === 'pending',
    json_encode($now['booking']));
ge_ok('a CHILD\'S EVENT REGISTRATION that somebody else submitted and the person only REVIEWED is kept, with the child\'s name and medical details; only the reviewer is detached',
    (int) $was['registration']['reviewedByID'] === P_A && ge_same($was['registration'], $now['registration'], ['reviewedByID']) === true
    && $now['registration']['reviewedByID'] === null && (int) $now['registration']['submittedByUserID'] === C
    && $now['registration']['fullName'] === 'Kit Control' && $now['registration']['medicalNotes'] === $was['registration']['medicalNotes']
    && $now['registration']['medicalNotes'] !== null, json_encode($now['registration']));
ge_ok('a PASTORAL CASE about somebody else is untouched; a visit the person made is kept with its notes and no number; so is a visit whose follow-up was given to them',
    $now['case'] === $was['case']
    && ge_same($was['visitByPerson'], $now['visitByPerson'], ['visitedByID']) === true && $now['visitByPerson']['visitedByID'] === null
    && $now['visitByPerson']['notes'] === 'Visited Cal at home.' && (int) $now['visitByPerson']['followUpAssignedToID'] === D
    && ge_same($was['visitFollowUp'], $now['visitFollowUp'], ['followUpAssignedToID']) === true
    && $now['visitFollowUp']['followUpAssignedToID'] === null && (int) $now['visitFollowUp']['visitedByID'] === D,
    json_encode([$now['case'], $now['visitByPerson'], $now['visitFollowUp']]));
ge_ok('ANOTHER PERSON\'S MONEY RECORDS are untouched, byte for byte: their gift, their Gift Aid declaration and their expense claim',
    $now['giftOfC'] !== null && $now['giftOfC'] === $was['giftOfC'] && $now['giftAidOfC'] !== null
    && $now['giftAidOfC'] === $was['giftAidOfC'] && $now['claimOfC'] !== null && $now['claimOfC'] === $was['claimOfC'],
    json_encode([$now['giftOfC'], $now['giftAidOfC'], $now['claimOfC']]));
ge_ok('a PAYMENT the person entered against somebody else\'s expense claim is kept, with its amount, method and reference; only who entered it is cleared',
    ge_same($was['payment'], $now['payment'], ['paidByID']) === true && $now['payment']['paidByID'] === null
    && $now['payment']['payAmount'] === $was['payment']['payAmount'] && $now['payment']['payAmount'] !== null,
    json_encode($now['payment']));
ge_ok('the person\'s own PLEDGE is deleted; the gift given towards it is kept with its amount, date and campaign, and loses the donor and the pledge link; their Gift Aid declaration is unchanged',
    $now['pledge'] === null && ge_same($was['giftToPledge'], $now['giftToPledge'], ['donorID', 'pledgeID']) === true
    && $now['giftToPledge']['donorID'] === null && $now['giftToPledge']['pledgeID'] === null
    && $now['giftToPledge']['amountPence'] === $was['giftToPledge']['amountPence']
    && $now['giftToPledge']['campaignID'] === $was['giftToPledge']['campaignID']
    && $now['giftAidOfPerson'] !== null && $now['giftAidOfPerson'] === $was['giftAidOfPerson'],
    json_encode([$now['pledge'], $now['giftToPledge'], $now['giftAidOfPerson']]));
ge_ok('ZOOM: the person\'s own connection and the meeting made through it are gone; a meeting they made through somebody else\'s connection is kept, and that connection is untouched',
    $now['zoomOfPerson'] === null && $now['meetingViaPerson'] === null && $now['zoomOfC'] === $was['zoomOfC']
    && ge_same($was['meetingViaC'], $now['meetingViaC'], ['createdByID']) === true && $now['meetingViaC']['createdByID'] === null,
    json_encode([$now['zoomOfPerson'], $now['meetingViaPerson'], $now['meetingViaC']]));

// =============================================================================
ge_section('4', 'Nothing else in the whole database changed');
// =============================================================================
$printsAfterA = ge_snapshot();
$unexpectedA  = ge_unexpectedChanges($printsBeforeA, $printsAfterA, $worldA['allowed']);
ge_ok('every row of every table that is not the person\'s own is byte-for-byte unchanged (' . count($printsAfterA) . ' rows compared)',
    $unexpectedA === [], implode('; ', array_slice($unexpectedA, 0, 10)));
$changedCount = 0;
foreach ($printsBeforeA as $key => $print) {
    if (($printsAfterA[$key] ?? null) !== $print) {
        $changedCount++;
    }
}
ge_ok('...and the comparison is not empty-handed: the person\'s own rows DID change (' . $changedCount . ' rows)',
    $changedCount >= $instructionCount - $keptCount, (string) $changedCount);
$demoNow = [
    'account'      => ge_row($worldA['demo']['account']),
    'membership'   => ge_row($worldA['demo']['membership']),
    'announcement' => ge_row($worldA['demo']['announcement']),
];
ge_ok('Demo Data list (#498): the two entries pointing at the person are gone; the announcement with the same number stays',
    $demoNow['account'] === null && $demoNow['membership'] === null && $demoNow['announcement'] !== null, json_encode($demoNow));
$logNow = ge_row($worldA['emailLog']);
ge_ok('the email send log: the person\'s address is gone and the other recipient\'s is still there',
    $logNow !== null && $logNow['toRecipients'] === '[erased], dee.control@selftest.invalid', json_encode($logNow));
ge_ok('the giving statement PDF: the person\'s file is deleted and another person\'s file is still there',
    is_file($worldA['file']) === false && is_file($controlFile) === true);

// -----------------------------------------------------------------------------
ge_section('4b', 'No delete can cascade into a record that must be kept');
// -----------------------------------------------------------------------------
// The made-up rows are not linked to each other, so this asks the database
// for its own rules instead: starting from every table an instruction deletes
// from, follow each "delete the children too" link, as far as it goes.
$catalogueFile = require $coreDir . 'personal-data-catalogue.php';
$mustKeep = ['tblUsers', 'tblErasureRequest', 'tblErasureAudit', 'tblGivingEntry', 'tblPayment', 'tblExpenseClaims', 'tblKidProfiles'];
foreach ($catalogueFile as $table => $meta) {
    // CHANGED by roll-out package A2a: 'unlink' tables count as kept too.
    // Only 'retain' did, and that is how the cascade from a person's Zoom
    // connection into `tblZoomMeeting` (which the list said was kept) went
    // unseen. A table that is BOTH deleted from and kept (group memberships:
    // the person's own rows go, rows they merely added stay) is left out,
    // because a delete "reaching" the table it is aimed at says nothing.
    if (in_array(($meta['decision'] ?? ''), ['retain', 'unlink'], true) === true) {
        $mustKeep[] = (string) $table;
    }
}
$cascades = [];
$emptied  = [];
foreach (ge_q(
    "SELECT r.REFERENCED_TABLE_NAME AS parent, r.TABLE_NAME AS child, r.DELETE_RULE AS rule, k.COLUMN_NAME AS col "
    . "FROM information_schema.REFERENTIAL_CONSTRAINTS r JOIN information_schema.KEY_COLUMN_USAGE k "
    . "ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME AND k.TABLE_NAME = r.TABLE_NAME "
    . "WHERE r.CONSTRAINT_SCHEMA = DATABASE() AND r.DELETE_RULE IN ('CASCADE', 'SET NULL') "
    . "ORDER BY r.REFERENCED_TABLE_NAME, r.TABLE_NAME, k.COLUMN_NAME"
) as $link) {
    if ($link['rule'] === 'CASCADE') {
        $cascades[$link['parent']][$link['child']] = true;
    } else {
        $emptied[$link['parent']][] = $link['child'] . '.' . $link['col'];
    }
}
$deleteFrom = [];
foreach ($worldA['instructions'] as $instruction) {
    if ($instruction['action'] === 'delete') {
        $deleteFrom[(string) $instruction['table']] = true;
    }
}
// Every table a delete can reach: the ones deleted from, and everything the
// database then deletes below them, as far as it goes.
$reached = $deleteFrom;
$queue   = array_keys($deleteFrom);
$dragged = [];
while ($queue !== []) {
    $parent = array_shift($queue);
    foreach (array_keys($cascades[$parent] ?? []) as $child) {
        if (in_array($child, $mustKeep, true) === true && isset($deleteFrom[$child]) === false) {
            $dragged[$parent . ' -> ' . $child] = true;
        }
        if (isset($reached[$child]) === false) {
            $reached[$child] = true;
            $queue[]         = $child;
        }
    }
}
$dragged = array_keys($dragged);
sort($dragged);
$allowedCascades = array_keys(CASCADES_INTO_KEPT_TABLES);
sort($allowedCascades);
$cascadeCount = array_sum(array_map('count', $cascades));
ge_ok('the database\'s cascade rules were read (more than 100 of them)', $cascadeCount > 100, (string) $cascadeCount);
ge_ok('a delete reaches a KEPT table (kept on purpose, or kept with the name removed) only in the ' . count($allowedCascades)
    . ' named cases, each with its reason',
    $dragged === $allowedCascades && in_array('', CASCADES_INTO_KEPT_TABLES, true) === false,
    'not on the list: ' . implode('; ', array_diff($dragged, $allowedCascades)) . ' | on the list but no longer true: '
    . implode('; ', array_diff($allowedCascades, $dragged)));
// And the quieter kind: the row is kept, but the database empties a link on
// it because the row it pointed at was deleted (a gift losing its pledge).
$unlinked = [];
foreach (array_keys($reached) as $parent) {
    foreach ($emptied[$parent] ?? [] as $childColumn) {
        $child = explode('.', $childColumn)[0];
        if (in_array($child, $mustKeep, true) === true && isset($deleteFrom[$child]) === false) {
            $unlinked[$parent . ' -> ' . $childColumn] = true;
        }
    }
}
$unlinked = array_keys($unlinked);
sort($unlinked);
$allowedUnlinks = array_keys(EMPTIED_LINKS_ON_KEPT_TABLES);
sort($allowedUnlinks);
ge_ok('a delete empties a link on a KEPT table\'s rows only in the ' . count($allowedUnlinks) . ' named cases, each with its reason',
    $unlinked === $allowedUnlinks && in_array('', EMPTIED_LINKS_ON_KEPT_TABLES, true) === false,
    'not on the list: ' . implode('; ', array_diff($unlinked, $allowedUnlinks)) . ' | on the list but no longer true: '
    . implode('; ', array_diff($allowedUnlinks, $unlinked)));

// -----------------------------------------------------------------------------
ge_section('4c', 'No account number of the erased person is left anywhere, except where a named list says so');
// -----------------------------------------------------------------------------
// Every column that can hold an account number was given the person's: the
// columns an instruction matches on by ge_buildWorld(), every other one by
// ge_buildEveryPersonColumn(). Which of them still hold it?
$stillNamed = [];
foreach ($worldA['rows'] as $tracked) {
    $instruction = $worldA['instructions'][$tracked['i']];
    $column      = (string) $instruction['userCol'];
    $row         = ge_row($tracked['ref']);
    if ($tracked['kind'] === 'R1' && $instruction['table'] !== 'tblUsers' && $column !== '' && $row !== null
        && $row[$column] !== null && (int) $row[$column] === P_A
    ) {
        $stillNamed[$instruction['table'] . '.' . $column] = true;
    }
}
$unreachedGone = [];
foreach ($everyColumnA['rows'] as $tracked) {
    $column = explode('.', $tracked['where'])[1];
    $row    = ge_row($tracked['ref']);
    if ($row === null) {
        $unreachedGone[] = $tracked['where'];
    } elseif ($row[$column] !== null && (int) $row[$column] === P_A) {
        $stillNamed[$tracked['where']] = true;
    }
}
$stillNamed = array_keys($stillNamed);
sort($stillNamed);
$knownStillNamed = array_merge(...array_values(KNOWN_STILL_NAMED));
sort($knownStillNamed);
$untried = $everyColumnA['untried'];
sort($untried);
echo '   ' . count($everyColumnA['rows']) . ' more rows were made, one for each person column that no instruction matches on; '
    . count($untried) . " column(s) could not be given one.\n";
// How many different columns were given the person's number: one for each
// column an instruction matches on, and one for each column nothing matches.
// (CHANGED on 11 October 2026: this used to require at least 20 columns that
// NOTHING matched on, which was true while #492 was open. Building #492 took
// that to seven, so the floor is now on the total, which is what the check is
// for: that the whole database was covered.)
$columnsTried = [];
foreach ($worldA['instructions'] as $instruction) {
    if ((string) $instruction['userCol'] !== '') {
        $columnsTried[$instruction['table'] . '.' . $instruction['userCol']] = true;
    }
}
foreach ($everyColumnA['rows'] as $tracked) {
    $columnsTried[$tracked['where']] = true;
}
ge_ok('the person\'s number was put in every person column of every table (' . count($columnsTried)
    . ' columns), apart from an exact list of ' . count(COULD_NOT_BE_TRIED) . ' that could not be tried',
    count($columnsTried) >= 190 && $untried === COULD_NOT_BE_TRIED && $unreachedGone === [],
    'could not be tried: ' . json_encode($untried) . ' | rows that vanished: ' . json_encode($unreachedGone));
// Found by the SHAPE of its name alone (not on GdprEraser's list, not linked
// by the database): each such column must be a known look-alike, with its
// reason. Anything else is a person column nobody has dealt with.
$shapeOnly = [];
$linked    = [];
foreach (ge_q(
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE "
    . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers' AND REFERENCED_COLUMN_NAME = 'userID'"
) as $row) {
    $linked[$row['t'] . '.' . $row['c']] = true;
}
$shapedCount = 0;
foreach (ge_q(
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
    . "AND DATA_TYPE IN ('int', 'bigint', 'mediumint') ORDER BY TABLE_NAME, COLUMN_NAME"
) as $row) {
    if (preg_match(PERSON_COLUMN_SHAPE, (string) $row['c']) !== 1 || str_starts_with((string) $row['t'], 'selftest_') === true) {
        continue;
    }
    $shapedCount++;
    if (in_array($row['c'], GdprEraser::LINK_COLUMNS, true) === false && isset($linked[$row['t'] . '.' . $row['c']]) === false) {
        $shapeOnly[] = $row['t'] . '.' . $row['c'];
    }
}
sort($shapeOnly);
$lookAlikes = array_keys(LOOKS_LIKE_A_PERSON_COLUMN_AND_IS_NOT);
sort($lookAlikes);
ge_ok('person columns are also looked for by the SHAPE of their name (' . $shapedCount . ' columns have it): the only ones that are on no list and have no link are the '
    . count($lookAlikes) . ' named look-alikes',
    $shapedCount > 150 && $shapeOnly === $lookAlikes,
    'looks like a person column, on no list, no link, and not a named look-alike: ' . implode(', ', array_diff($shapeOnly, $lookAlikes))
    . ' | named as a look-alike but no longer one: ' . implode(', ', array_diff($lookAlikes, $shapeOnly)));
// The one place an account number is kept as TEXT. Pinned, not built.
$stepNow = ge_row($stepForPerson);
ge_ok('PINNED, not built: a step of an approval route given to the person by name still holds their number as text ('
    . implode(', ', array_keys(TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER)) . '). When that is decided and built, this check and its list must change',
    array_keys(TEXT_THAT_CAN_HOLD_AN_ACCOUNT_NUMBER) === ['tblWorkflowSteps.assigneeValue'] && $stepNow !== null
    && $stepNow['assigneeType'] === 'user' && $stepNow['assigneeValue'] === (string) P_A, json_encode($stepNow));
ge_ok('after the erasure, the columns that still hold the person\'s number are EXACTLY the named list (' . count($knownStillNamed) . ' columns)',
    $stillNamed === $knownStillNamed,
    'still named and NOT on the list: ' . implode(', ', array_diff($stillNamed, $knownStillNamed))
    . ' | on the list but no longer named: ' . implode(', ', array_diff($knownStillNamed, $stillNamed)));

// =============================================================================
ge_section('5', 'The tables #535 was about, each by name');
// =============================================================================
$named = [
    ['tblRecording', 'uploadedByID'], ['tblRecording', 'presenterID'], ['tblAssetAudit', 'actorUserID'],
    ['tblAssetScanLog', 'actorUserID'], ['tblExternalFeedRuns', 'triggeredByID'], ['tblServicePlanItem', 'presenterID'],
    ['tblLiveChatMessages', 'moderatedByID'],
];
foreach ($named as [$table, $col]) {
    $found = ge_find($worldA, $table, $col);
    $now   = $found === null ? null : ge_row($found['ref']);
    $was   = $found === null ? null : $beforeRowsA[$found['n']];
    ge_ok($table . '.' . $col . ': the record is kept, and the person\'s number is emptied',
        $found !== null && $found['action'] === 'anonymise' && $was !== null && (int) $was[$col] === P_A
        && $now !== null && $now[$col] === null, json_encode([$found['action'] ?? 'no instruction', $now]));
}
$viewer = ge_find($worldA, 'tblCareAccessLog', 'viewerID');
ge_ok('tblCareAccessLog.viewerID: the line saying the person opened a file is deleted',
    $viewer !== null && $viewer['action'] === 'delete' && $beforeRowsA[$viewer['n']] !== null && ge_row($viewer['ref']) === null);
$chat = ge_find($worldA, 'tblLiveChatMessages', 'moderatedByID');
$chatNow = $chat === null ? null : ge_row($chat['ref']);
$chatWas = $chat === null ? null : $beforeRowsA[$chat['n']];
ge_ok('tblLiveChatMessages: the sender\'s typed name, address and message are untouched (they belong to somebody else)',
    $chatNow !== null && $chatNow['displayName'] === $chatWas['displayName'] && $chatNow['senderIP'] === $chatWas['senderIP']
    && $chatNow['body'] === $chatWas['body'], json_encode($chatNow));

// -----------------------------------------------------------------------------
ge_section('5b', 'The records that must never be lost, each by name');
// -----------------------------------------------------------------------------
foreach ([['tblGivingEntry', 'donorID'], ['tblPayment', 'userID'], ['tblExpenseClaims', 'userID']] as [$table, $col]) {
    $found = ge_find($worldA, $table, $col);
    $now   = $found === null ? null : ge_row($found['ref']);
    $was   = $found === null ? null : $beforeRowsA[$found['n']];
    $same  = $now !== null;
    foreach ((array) $now as $name => $value) {
        // `updatedAt` is stamped by the database itself whenever a row is
        // saved, so it is the one other column allowed to differ.
        if ($name !== $col && $name !== 'updatedAt' && $value !== $was[$name]) {
            $same = false;
        }
    }
    ge_ok($table . ': the row and every amount on it stay; only the person\'s own number may go',
        $found !== null && $same === true, json_encode([$was, $now]));
}
foreach ([['tblDbsChecks', 'userID'], ['tblAnonymousCheckins', '']] as [$table, $col]) {
    $found = ge_find($worldA, $table, $col);
    ge_ok($table . ': kept, and not one column changed',
        $found !== null && $found['action'] === 'retain' && ge_row($found['ref']) === $beforeRowsA[$found['n']]);
}
// CHANGED by roll-out package A2a. tblCareVisit was in the loop above ("kept,
// and not one column changed"), because the visitor's number could not be
// removed. It can now, so the check is: the visit and its notes are kept, and
// the visitor's number - nothing else - is gone.
$careVisit = ge_find($worldA, 'tblCareVisit', 'visitedByID');
ge_ok('tblCareVisit: the visit is kept with its notes and its case; only the number of whoever made it is emptied',
    $careVisit !== null && $careVisit['action'] === 'anonymise' && ge_row($careVisit['ref']) !== null
    && ge_same($beforeRowsA[$careVisit['n']], ge_row($careVisit['ref']), ['visitedByID']) === true
    && ge_row($careVisit['ref'])['visitedByID'] === null && ge_row($careVisit['ref'])['notes'] !== null,
    json_encode($careVisit === null ? null : ge_row($careVisit['ref'])));
// Roll-out decision 8 (Salem874, 11 October 2026): kept for tax reasons. It
// was DELETED on erasure until then, so this is checked by name.
$giftAid     = ge_find($worldA, 'tblGiftAidDeclaration', 'donorID');
$giftAidLine = array_values(array_filter($auditA, static fn (array $l): bool => $l['tableName'] === 'tblGiftAidDeclaration'));
ge_ok('tblGiftAidDeclaration: the declaration is kept, not one column changed, and the audit trail says it was kept on purpose and why',
    $giftAid !== null && $giftAid['action'] === 'retain' && $beforeRowsA[$giftAid['n']] !== null
    && ge_row($giftAid['ref']) === $beforeRowsA[$giftAid['n']]
    && count($giftAidLine) === 1 && $giftAidLine[0]['action'] === 'retain'
    && str_contains((string) $giftAidLine[0]['details'], 'HMRC'),
    json_encode([$giftAid['action'] ?? 'no instruction', $giftAid === null ? null : ge_row($giftAid['ref']), $giftAidLine]));
$statement = ge_find($worldA, 'tblGivingStatementLog', 'donorID');
$statementNow = $statement === null ? null : ge_row($statement['ref']);
$statementWas = $statement === null ? null : $beforeRowsA[$statement['n']];
ge_ok('tblGivingStatementLog: kept with its totals and period; only the mailed-to address is emptied',
    $statementNow !== null && $statementNow['emailedTo'] === null && $statementWas['emailedTo'] !== null
    && (int) $statementNow['donorID'] === P_A && $statementNow['totalPence'] === $statementWas['totalPence']
    && $statementNow['periodKey'] === $statementWas['periodKey'], json_encode($statementNow));
$kid = ge_find($worldA, 'tblKidProfiles', 'parentUserID');
$kidNow = $kid === null ? null : ge_row($kid['ref']);
$kidWas = $kid === null ? null : $beforeRowsA[$kid['n']];
ge_ok('tblKidProfiles: the child\'s record stays with its name and medical notes; only the parent link is emptied',
    $kidNow !== null && $kidNow['parentUserID'] === null && $kidNow['fullName'] === $kidWas['fullName']
    && $kidNow['medicalNotes'] === $kidWas['medicalNotes'], json_encode($kidNow));

// -----------------------------------------------------------------------------
ge_section('5c', 'The send log: only an entry that IS the address is replaced (roll-out package A2a)');
// -----------------------------------------------------------------------------
// The step used to replace the text wherever it appeared, so erasing
// pat.erased@... also rewrote somebody else's xpat.erased@... .
$besideLonger = ge_row($logBesideLonger);
$onlyLonger   = ge_row($logOnlyLonger);
ge_ok('a LONGER address that merely contains the person\'s address is left exactly as it was, beside theirs and on its own',
    ($besideLonger['toRecipients'] ?? '') === 'xpat.erased@selftest.invalid, [erased]'
    && ($onlyLonger['toRecipients'] ?? '') === 'xpat.erased@selftest.invalid', json_encode([$besideLonger, $onlyLonger]));
$capitals = ge_row($logCapitals);
ge_ok('the person\'s address written with capitals, and with no space after the comma, is still found; the list keeps its shape',
    ($capitals['toRecipients'] ?? '') === '[erased],dee.control@selftest.invalid', json_encode($capitals));

// =============================================================================
ge_section('6', 'A step that fails: recorded, the rest still run, and a second run finishes');
// =============================================================================
$worldB = ge_buildWorld(P_B, 'bo.second@selftest.invalid', 'Bo Second');
ge_request(REQ_B, P_B, 'bo.second@selftest.invalid', 'Bo Second', 'processing', 'Bo asked by letter.');
$worldB['allowed'][ge_keyText('tblErasureRequest', ['requestID' => REQ_B])] = true;
$beforeRowsB = [];
foreach ($worldB['rows'] as $n => $tracked) {
    $beforeRowsB[$n] = ge_row($tracked['ref']);
}
$printsBeforeB = ge_snapshot();

// One table's update is made to fail, the way a lock or a full disk would.
ge_trigger('selftest_gdpr_fail_step', "CREATE TRIGGER `selftest_gdpr_fail_step` BEFORE UPDATE ON `tblExternalFeedRuns` "
    . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'selftest: this update was made to fail'");
$resultB1 = ge_execute(REQ_B, P_B);
ge_ok('the run still ends without an exception, and reports exactly one failed step',
    is_array($resultB1) === true && $resultB1['failed'] === 1 && $resultB1['steps'] === $instructionCount + 5,
    $resultB1 instanceof Throwable ? get_class($resultB1) . ': ' . $resultB1->getMessage() : json_encode($resultB1));
$reqB = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_B]);
ge_ok('the request is back at "pending review" with its account number, name, address and notes all still on it',
    $reqB !== null && $reqB['status'] === 'pending_review' && (int) $reqB['userID'] === P_B
    && $reqB['subjectEmail'] === 'bo.second@selftest.invalid' && $reqB['subjectName'] === 'Bo Second'
    && $reqB['notes'] === 'Bo asked by letter.' && $reqB['processedAt'] === null, json_encode($reqB));
$auditB1 = ge_audit(REQ_B);
$auditB1Problems = ge_checkAudit($worldB, $auditB1, true, ['tblExternalFeedRuns'], 'failed');
ge_ok('the audit trail has a line for EVERY instruction: one "failed", and nothing after it was skipped',
    $auditB1Problems === [], implode('; ', array_slice($auditB1Problems, 0, 8)));
$summary = $auditB1[count($auditB1) - 1] ?? ['details' => ''];
ge_ok('its last line says how many steps failed and that the request can be run again',
    str_contains((string) $summary['details'], '1 of ' . ($instructionCount + 5) . ' steps failed')
    && str_contains((string) $summary['details'], 'back at pending review'), (string) $summary['details']);
$rowsB1 = ge_checkRows($worldB, $beforeRowsB, ['tblExternalFeedRuns.triggeredByID']);
ge_ok('the failed table\'s row is untouched, and every other instruction still did its work',
    $rowsB1['problems'] === [], implode('; ', array_slice($rowsB1['problems'], 0, 8)));

// Between the two runs a message to the person's address is logged. The
// account row is already emptied, so only the request still knows the address.
ge_dropTrigger('selftest_gdpr_fail_step');
$lateLog = ge_insert('tblEmailLog', [], ['toRecipients' => 'bo.second@selftest.invalid']);
$worldB['allowed'][ge_keyText('tblEmailLog', $lateLog['key'])] = true;
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ? AND status = 'pending_review'", 'i', [REQ_B]);
$resultB2 = ge_execute(REQ_B, P_B);
ge_ok('run again: no failed step',
    is_array($resultB2) === true && $resultB2['failed'] === 0,
    $resultB2 instanceof Throwable ? get_class($resultB2) . ': ' . $resultB2->getMessage() : json_encode($resultB2));
$reqB2 = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_B]);
ge_ok('the request is now "completed" and cleared',
    $reqB2 !== null && $reqB2['status'] === 'completed' && $reqB2['userID'] === null
    && $reqB2['subjectEmail'] === 'deleted-' . REQ_B . '@example.invalid', json_encode($reqB2));
$rowsB2 = ge_checkRows($worldB, $beforeRowsB);
ge_ok('every instruction has now done its work, the one that failed included', $rowsB2['problems'] === [],
    implode('; ', array_slice($rowsB2['problems'], 0, 8)));
// The second run finds almost nothing left to do. It must still write a line
// for every instruction - "nothing held" - or a step that never ran would
// look exactly like a step with nothing to do.
$auditB2   = array_slice(ge_audit(REQ_B), count($auditB1));
$noneLines = count(array_filter($auditB2, static fn (array $l): bool => $l['action'] === 'none'));
ge_ok('the second run also wrote one line per instruction, most of them "nothing held" (' . count($auditB2) . ' lines, ' . $noneLines . ' "none")',
    count($auditB2) === $instructionCount + 5 && $noneLines > 100
    && ($auditB2[count($auditB2) - 1]['action'] ?? '') === 'completed',
    count($auditB2) . ' lines, expected ' . ($instructionCount + 5));
$lateNow = ge_row($lateLog);
ge_ok('the second run still found the person\'s address: the message logged between the runs is scrubbed',
    $lateNow !== null && $lateNow['toRecipients'] === '[erased]', json_encode($lateNow));
ge_ok('the audit trail of both runs together still verifies', GdprEraser::verifyAuditChain(REQ_B) === true);
$unexpectedB = ge_unexpectedChanges($printsBeforeB, ge_snapshot(), $worldB['allowed']);
ge_ok('across both runs, nothing that was not the person\'s own changed anywhere in the database',
    $unexpectedB === [], implode('; ', array_slice($unexpectedB, 0, 10)));

// =============================================================================
ge_section('7', 'If the audit trail cannot be written, the run stops and nothing is half-recorded');
// =============================================================================
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_C, 'Cy Third', 'cy.third@selftest.invalid');
$first    = GdprEraser::catalogue()[0];
$firstRef = ge_insert((string) $first['table'], [(string) $first['userCol'] => P_C]);
ge_ensureParents($firstRef);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_PC, P_C, 'cy.third@selftest.invalid', 'Cy Third', 'processing');
$firstWas = ge_row($firstRef);

ge_trigger('selftest_gdpr_fail_audit', "CREATE TRIGGER `selftest_gdpr_fail_audit` BEFORE INSERT ON `tblErasureAudit` "
    . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'selftest: the audit trail was made to fail'");
$resultC1 = ge_execute(REQ_PC, P_C);
ge_dropTrigger('selftest_gdpr_fail_audit');
ge_ok('the run stops with a plain message that says the request can be run again',
    $resultC1 instanceof RuntimeException && str_contains($resultC1->getMessage(), 'stopped before it finished')
    && str_contains($resultC1->getMessage(), 'back at pending review'),
    $resultC1 instanceof Throwable ? $resultC1->getMessage() : json_encode($resultC1));
$reqPc = ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_PC]);
ge_ok('the request is back at "pending review" with its account number still on it',
    $reqPc !== null && $reqPc['status'] === 'pending_review' && (int) $reqPc['userID'] === P_C, json_encode($reqPc));
ge_ok('the step whose audit line could not be written was undone: the row still names the person, and no audit line exists',
    ge_row($firstRef) === $firstWas && ge_audit(REQ_PC) === [], json_encode([ge_row($firstRef), count(ge_audit(REQ_PC))]));
$userC = ge_one('SELECT fullName, emailAddress FROM tblUsers WHERE userID = ?', 'i', [P_C]);
ge_ok('nothing later ran: the account row is not emptied', $userC !== null && $userC['fullName'] === 'Cy Third', json_encode($userC));
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_PC]);
$resultC2 = ge_execute(REQ_PC, P_C);
$reqPc2   = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_PC]);
ge_ok('run again with the audit trail working: completed, and the first step is now recorded',
    is_array($resultC2) === true && $resultC2['failed'] === 0 && $reqPc2['status'] === 'completed'
    && (ge_audit(REQ_PC)[0]['tableName'] ?? '') === (string) $first['table']
    && in_array(ge_audit(REQ_PC)[0]['action'] ?? '', ['anonymise', 'delete'], true),
    $resultC2 instanceof Throwable ? $resultC2->getMessage() : json_encode([$resultC2, $reqPc2, ge_audit(REQ_PC)[0] ?? null]));

// =============================================================================
ge_section('7b', 'A file that cannot be deleted, and a send log that cannot be updated');
// =============================================================================
// Two steps that are not table instructions. Each used to be passed over when
// it failed: the statement with the person's name on it stayed on disk, or
// their address stayed in the send log, and the erasure was reported complete.
// One run for each, so each is shown on its own to stop the request closing.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_E, 'Eli Fifth', 'eli.fifth@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_E, P_E, 'eli.fifth@selftest.invalid', 'Eli Fifth', 'processing');
$dirE  = dirname($statementDir) . DIRECTORY_SEPARATOR . '2';
$fileE = $dirE . DIRECTORY_SEPARATOR . 'statement-' . P_E . '-2025.pdf';
mkdir($dirE, 0700);
file_put_contents($fileE, 'made-up statement');
$GLOBALS['ge_files'][] = $fileE;
$failedLines = static fn (int $requestId, int $from): array => array_values(array_filter(
    array_slice(ge_audit($requestId), $from),
    static fn (array $l): bool => $l['action'] === 'failed' && $l['tableName'] !== 'tblErasureRequest'
));
$isRoot = function_exists('posix_geteuid') === true && posix_geteuid() === 0;

// Run 1: the folder is read-only, so the file cannot be deleted.
chmod($dirE, 0500);
$resultE1 = ge_execute(REQ_E, P_E);
chmod($dirE, 0700);
$failedE1 = $failedLines(REQ_E, 0);
$reqE1    = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_E]);
ge_ok('a statement file that cannot be deleted is a failed step, recorded as one',
    is_array($resultE1) === true && $resultE1['failed'] === 1 && count($failedE1) === 1
    && $failedE1[0]['tableName'] === 'GivingStatementPdfFiles' && str_contains((string) $failedE1[0]['details'], 'still on disk'),
    ($isRoot === true ? 'THIS RUN IS ROOT, which a read-only folder cannot stop: run it as an ordinary user. ' : '')
    . ($resultE1 instanceof Throwable ? $resultE1->getMessage() : json_encode([$resultE1, $failedE1])));
ge_ok('...so the request is NOT closed, and the file is still there to be deleted next time',
    $reqE1['status'] === 'pending_review' && (int) $reqE1['userID'] === P_E && is_file($fileE) === true, json_encode($reqE1));

// Run 2: the file can now go, but the send log cannot be updated.
$logE = ge_insert('tblEmailLog', [], ['toRecipients' => 'eli.fifth@selftest.invalid']);
ge_trigger('selftest_gdpr_fail_log', "CREATE TRIGGER `selftest_gdpr_fail_log` BEFORE UPDATE ON `tblEmailLog` "
    . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'selftest: the send log was made to fail'");
$linesSoFar = count(ge_audit(REQ_E));
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_E]);
$resultE2 = ge_execute(REQ_E, P_E);
ge_dropTrigger('selftest_gdpr_fail_log');
$failedE2 = $failedLines(REQ_E, $linesSoFar);
$reqE2    = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_E]);
ge_ok('a send log that cannot be updated is a failed step, recorded as one',
    is_array($resultE2) === true && $resultE2['failed'] === 1 && count($failedE2) === 1 && $failedE2[0]['tableName'] === 'tblEmailLog',
    $resultE2 instanceof Throwable ? $resultE2->getMessage() : json_encode([$resultE2, $failedE2]));
ge_ok('...so the request is NOT closed, and the address is still in the send log to be removed next time',
    $reqE2['status'] === 'pending_review' && (int) $reqE2['userID'] === P_E
    && (ge_row($logE)['toRecipients'] ?? '') === 'eli.fifth@selftest.invalid' && is_file($fileE) === false,
    json_encode([$reqE2, ge_row($logE), is_file($fileE)]));

// Run 3: nothing in the way.
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_E]);
$resultE3 = ge_execute(REQ_E, P_E);
$reqE3    = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_E]);
ge_ok('with both put right, the next run completes: the file is gone and the address is out of the send log',
    is_array($resultE3) === true && $resultE3['failed'] === 0 && $reqE3['status'] === 'completed'
    && is_file($fileE) === false && (ge_row($logE)['toRecipients'] ?? '') === '[erased]',
    $resultE3 instanceof Throwable ? $resultE3->getMessage() : json_encode([$resultE3, $reqE3, ge_row($logE)]));
@rmdir($dirE);

// =============================================================================
ge_section('7c', 'A database that is missing a column, and one that is missing a table');
// =============================================================================
// The two look alike and must not be treated alike. A missing TABLE is an app
// that was never installed: nothing can be held there, so the step is skipped.
// A missing COLUMN is a table that exists and was never looked at: that is a
// failed step, and the request must not be closed as if it had been.
// (Both were once written down as "table-missing" and the request closed.)
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_F, 'Fen Sixth', 'fen.sixth@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_F, P_F, 'fen.sixth@selftest.invalid', 'Fen Sixth', 'processing');
$mysqli->query('ALTER TABLE `tblAssetAudit` DROP COLUMN `actorUserID`');
$mysqli->query('RENAME TABLE `tblUserTours` TO `selftest_hidden_user_tours`');
$resultF1 = ge_execute(REQ_F, P_F);
$mysqli->query('RENAME TABLE `selftest_hidden_user_tours` TO `tblUserTours`');
$mysqli->query('ALTER TABLE `tblAssetAudit` ADD COLUMN `actorUserID` INT DEFAULT NULL, ADD KEY `idx_astaud_actor` (`actorUserID`)');
$auditF1  = ge_audit(REQ_F);
$columnF  = array_values(array_filter($auditF1, static fn (array $l): bool => $l['tableName'] === 'tblAssetAudit'));
$tableF   = array_values(array_filter($auditF1, static fn (array $l): bool => $l['tableName'] === 'tblUserTours'));
$reqF1    = ge_one('SELECT status, userID, subjectEmail FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_F]);
ge_ok('a missing column is a failed step and the request is NOT closed: back at "pending review" with everything on it',
    is_array($resultF1) === true && $resultF1['failed'] === 1 && $reqF1['status'] === 'pending_review'
    && (int) $reqF1['userID'] === P_F && $reqF1['subjectEmail'] === 'fen.sixth@selftest.invalid',
    $resultF1 instanceof Throwable ? $resultF1->getMessage() : json_encode([$resultF1, $reqF1]));
ge_ok('the audit line names the column and does not call it a missing table; the missing TABLE is a skip, not a failure',
    count($columnF) === 1 && $columnF[0]['action'] === 'failed' && str_contains((string) $columnF[0]['details'], 'actorUserID')
    && str_contains((string) $columnF[0]['details'], 'table-missing') === false
    && count($tableF) === 1 && $tableF[0]['action'] === 'skip' && $tableF[0]['details'] === 'table-missing',
    json_encode([$columnF, $tableF]));
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_F]);
// This run is pressed by the person being erased (an administrator carrying
// out their own request), for the check after next.
$resultF2 = ge_execute(REQ_F, P_F, P_F);
$reqF2    = ge_one('SELECT status, processedByID, processedAt FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_F]);
ge_ok('once the column is back (the database upgraded), running the request again completes it',
    is_array($resultF2) === true && $resultF2['failed'] === 0 && $reqF2['status'] === 'completed',
    $resultF2 instanceof Throwable ? $resultF2->getMessage() : json_encode([$resultF2, $reqF2]));
ge_ok('an administrator who carries out their OWN erasure is not written onto the request as who did it (that would put their number straight back)',
    $reqF2['processedByID'] === null && $reqF2['processedAt'] !== null, json_encode($reqF2));

// =============================================================================
ge_section('7d', 'The person\'s photo: what is refused, and a file that cannot be deleted (roll-out package A2a)');
// =============================================================================
// A path kept in a database column must not be trusted with the right to
// delete files. Three things are refused, and each must leave the file where
// it is, leave the links on the account, and count as a FAILED step.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_G, 'Gil Seventh', 'gil.seventh@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_G, P_G, 'gil.seventh@selftest.invalid', 'Gil Seventh', 'processing');
$photoStep  = new ReflectionMethod(GdprEraser::class, 'eraseProfileImages');
$photoState = static fn (): array => ge_one('SELECT avatarPath, displayPhoto FROM tblUsers WHERE userID = ?', 'i', [P_G]);
$lastAudit  = static function (int $requestId): array {
    $lines = ge_audit($requestId);

    return $lines[count($lines) - 1] ?? ['action' => '', 'tableName' => '', 'details' => ''];
};

// 1. An image, but OUTSIDE the uploads folder, reached with "..".
$outside = ge_file('outside-the-uploads-folder.jpg');
ge_q("UPDATE tblUsers SET avatarPath = NULL, displayPhoto = '../outside-the-uploads-folder.jpg' WHERE userID = ?", 'i', [P_G]);
$outcomeOutside = $photoStep->invoke(null, REQ_G, P_G);
ge_ok('a photo path that climbs OUT of the uploads folder is refused: a failed step, the file untouched, the link left on the account',
    $outcomeOutside === 'failed' && is_file($outside) === true && $photoState()['displayPhoto'] === '../outside-the-uploads-folder.jpg'
    && $lastAudit(REQ_G)['action'] === 'failed' && $lastAudit(REQ_G)['tableName'] === 'ProfileImageFiles'
    && str_contains((string) $lastAudit(REQ_G)['details'], 'outside-the-uploads-folder') === false,
    json_encode([$outcomeOutside, is_file($outside), $photoState(), $lastAudit(REQ_G)]));

// 2. Inside the uploads folder, but NOT an image: another person's statement.
ge_q("UPDATE tblUsers SET displayPhoto = ? WHERE userID = ?", 'si', ['giving/statements/1/statement-' . C . '-2025.pdf', P_G]);
$outcomePdf = $photoStep->invoke(null, REQ_G, P_G);
ge_ok('a photo path that points at a file which is not an image (another person\'s giving statement) is refused, and that file is untouched',
    $outcomePdf === 'failed' && is_file($controlFile) === true && $photoState()['displayPhoto'] !== null
    && $lastAudit(REQ_G)['action'] === 'failed', json_encode([$outcomePdf, is_file($controlFile), $photoState()]));

// 2b. In the WEB folder. A photo address without "http" in front means a file
//     under the web folder (Portal\Core\Avatar). Nothing in the web folder is
//     ever deleted on the say-so of a column: that folder holds the portal's
//     own pages and pictures. A file found there is refused, so that a person
//     looks at it. (The web folder is wherever the web server says it is;
//     here it is a made-up one inside the scratch folder.)
$inWebFolder = ge_file('made-up-web-folder/uploads/avatars/' . P_G . '.jpg');
$_SERVER['DOCUMENT_ROOT'] = PORTAL_ROOT . DIRECTORY_SEPARATOR . 'made-up-web-folder';
ge_q("UPDATE tblUsers SET displayPhoto = NULL, avatarPath = ? WHERE userID = ?", 'si', ['uploads/avatars/' . P_G . '.jpg', P_G]);
$outcomeWeb = $photoStep->invoke(null, REQ_G, P_G);
unset($_SERVER['DOCUMENT_ROOT']);
ge_ok('a photo that is a file in the WEB folder is refused too: a failed step, the file untouched, the link left on the account',
    $outcomeWeb === 'failed' && is_file($inWebFolder) === true && $photoState()['avatarPath'] === 'uploads/avatars/' . P_G . '.jpg'
    && $lastAudit(REQ_G)['action'] === 'failed', json_encode([$outcomeWeb, is_file($inWebFolder), $photoState()]));
ge_q('UPDATE tblUsers SET avatarPath = NULL WHERE userID = ?', 'i', [P_G]);

// 3. A real photo that cannot be deleted (its folder is read-only). Through
//    the whole erasure this time, to show the request is NOT closed.
$lockedPhoto = ge_file('_uploads/directory-locked/' . P_G . '.png');
ge_q("UPDATE tblUsers SET displayPhoto = ? WHERE userID = ?", 'si', ['directory-locked/' . P_G . '.png', P_G]);
chmod(dirname($lockedPhoto), 0500);
$resultG1 = ge_execute(REQ_G, P_G);
chmod(dirname($lockedPhoto), 0700);
$reqG1    = ge_one('SELECT status, userID FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_G]);
$failedG1 = $failedLines(REQ_G, 3);
ge_ok('a photo file that cannot be deleted is a failed step: the request is NOT closed, the file is still there, and so is the link to it',
    is_array($resultG1) === true && $resultG1['failed'] === 1 && count($failedG1) === 1
    && $failedG1[0]['tableName'] === 'ProfileImageFiles' && str_contains((string) $failedG1[0]['details'], 'still on disk')
    && $reqG1['status'] === 'pending_review' && (int) $reqG1['userID'] === P_G && is_file($lockedPhoto) === true
    && $photoState()['displayPhoto'] === 'directory-locked/' . P_G . '.png',
    ($isRoot === true ? 'THIS RUN IS ROOT, which a read-only folder cannot stop: run it as an ordinary user. ' : '')
    . ($resultG1 instanceof Throwable ? $resultG1->getMessage() : json_encode([$resultG1, $failedG1, $reqG1, $photoState()])));

// 4. Nothing in the way: the next run finds the file again and finishes.
ge_q("UPDATE tblErasureRequest SET status = 'processing' WHERE requestID = ?", 'i', [REQ_G]);
$resultG2 = ge_execute(REQ_G, P_G);
$reqG2    = ge_one('SELECT status FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_G]);
ge_ok('with the folder writable again the next run finds the file through the link it left, deletes it, empties the links and completes',
    is_array($resultG2) === true && $resultG2['failed'] === 0 && $reqG2['status'] === 'completed' && is_file($lockedPhoto) === false
    && $photoState()['displayPhoto'] === null && $photoState()['avatarPath'] === null && is_file($outside) === true,
    $resultG2 instanceof Throwable ? $resultG2->getMessage() : json_encode([$resultG2, $reqG2, $photoState()]));

// =============================================================================
ge_section('8', 'The guards themselves can fail');
// =============================================================================
// A check that can only ever say "yes" proves nothing, so two of them are
// shown saying "no".
$processEntry = new ReflectionMethod(GdprEraser::class, 'processEntry');
$controlRef   = null;
foreach (ge_q('SELECT * FROM tblEventAttendance WHERE userID = ?', 'i', [C]) as $row) {
    $controlRef = $row;
}
$outcome = $processEntry->invoke(null, $mysqli, REQ_PC, C, ['table' => 'tblEventAttendance', 'userCol' => 'userID', 'action' => 'tidy-up']);
$stillThere = ge_q('SELECT * FROM tblEventAttendance WHERE userID = ?', 'i', [C]);
$lastLine   = ge_audit(REQ_PC);
$lastLine   = $lastLine[count($lastLine) - 1];
ge_ok('an instruction nobody recognises is refused: a failed step, a line saying so, and the row untouched',
    $outcome === 'failed' && $controlRef !== null && ($stillThere[0] ?? null) === $controlRef
    && $lastLine['action'] === 'failed' && str_contains((string) $lastLine['details'], 'unrecognised instruction'),
    json_encode([$outcome, $lastLine]));
// The guard that stops an instruction wiping ANOTHER person's number. When an
// instruction lists a person column to empty beside the one it matches on,
// that column is emptied only where it names the erased person. No
// instruction lists one any more (each person column has an instruction of
// its own since 11 October 2026, and tools/gdpr-coverage-selftest.php fails
// if one comes back), so the made-up world no longer exercises this guard,
// and a planted fault that removed it went unnoticed. It is still the last
// thing standing between a careless instruction and somebody else's number,
// so it is tested here directly, with an instruction of exactly that shape.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$sharedTask = ge_insert('tblTasks', ['assignedToID' => C, 'createdByID' => D], ['parentTaskID' => null]);
ge_ensureParents($sharedTask);
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
$guardOutcome = $processEntry->invoke(null, $mysqli, REQ_PC, C, ['table' => 'tblTasks', 'userCol' => 'assignedToID', 'action' => 'anonymise',
    'nullCols' => ['createdByID'], 'reason' => 'selftest: an instruction that lists a second person column']);
$sharedNow = ge_row($sharedTask);
ge_ok('an instruction that lists a second person column empties it ONLY where it names the erased person: somebody else\'s number on the same row is left alone',
    $guardOutcome === 'changed' && $sharedNow !== null && $sharedNow['assignedToID'] === null && (int) $sharedNow['createdByID'] === D,
    json_encode([$guardOutcome, $sharedNow]));
ge_q("UPDATE tblErasureAudit SET details = 'edited afterwards' WHERE requestID = ? ORDER BY auditID LIMIT 1", 'i', [REQ_A]);
ge_ok('an audit line edited afterwards breaks the chain, and verifying says so', GdprEraser::verifyAuditChain(REQ_A) === false);

// =============================================================================
ge_section('9', 'A request stuck at "processing" can be put back; one still running cannot (roll-out package A2a)');
// =============================================================================
// A run killed by the web host leaves its request at "processing", and the
// erasure page only runs one that is at "pending review". Nothing could put
// it back. Now something can, but only when the request has shown no sign of
// a run for a quarter of an hour.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_S, 'Sol Stuck', 'sol.stuck@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
ge_request(REQ_S_OLD, P_S, 'sol.stuck@selftest.invalid', 'Sol Stuck', 'processing');
ge_request(REQ_S_LIVE, C, 'cal.control@selftest.invalid', 'Cal Control', 'processing');
ge_request(REQ_S_NULL, D, 'dee.control@selftest.invalid', 'Dee Control', 'processing');
ge_request(REQ_S_DONE, null, 'deleted-' . REQ_S_DONE . '@example.invalid', '', 'completed');
$setClaimed = static function (int $requestId, ?int $minutesAgo): void {
    if ($minutesAgo === null) {
        ge_q('UPDATE tblErasureRequest SET claimedAt = NULL WHERE requestID = ?', 'i', [$requestId]);

        return;
    }
    ge_q('UPDATE tblErasureRequest SET claimedAt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE requestID = ?', 'ii', [$minutesAgo, $requestId]);
};
$requestRow = static fn (int $requestId): ?array => ge_one('SELECT status, claimedAt, userID, siteID FROM tblErasureRequest WHERE requestID = ?', 'i', [$requestId]);
$putBack    = static function (int $requestId, int $siteId = 1): string {
    try {
        return GdprEraser::putBackStuckRequest($requestId, $siteId);
    } catch (Throwable $e) {
        return 'threw: ' . $e->getMessage();
    }
};

// THE SERVER'S CLOCK MUST NOT MATTER. The connection is told it is thirteen
// hours ahead of UTC, then eleven behind. Written with NOW() instead of
// UTC_TIMESTAMP(), the first would put back a request that showed life a
// minute ago, and the second would refuse one that has been dead for twenty.
$setClaimed(REQ_S_LIVE, 1);
$mysqli->query("SET time_zone = '+13:00'");
$liveAhead = $putBack(REQ_S_LIVE);
$mysqli->query("SET time_zone = '-11:00'");
$liveBehind = $putBack(REQ_S_LIVE);
$liveRow    = $requestRow(REQ_S_LIVE);
ge_ok('a request that showed a sign of a run ONE minute ago is refused, whatever zone the database server keeps its clock in',
    $liveAhead === 'still-going' && $liveBehind === 'still-going' && $liveRow['status'] === 'processing'
    && $liveRow['claimedAt'] !== null && ge_audit(REQ_S_LIVE) === [], json_encode([$liveAhead, $liveBehind, $liveRow]));

$setClaimed(REQ_S_OLD, 20);
$oldBehind = $putBack(REQ_S_OLD);
$mysqli->query("SET time_zone = 'SYSTEM'");
$oldRow   = $requestRow(REQ_S_OLD);
$oldAudit = ge_audit(REQ_S_OLD);
ge_ok('one with no sign of a run for TWENTY minutes is put back to "pending review", with one audit line saying so and nothing else changed',
    $oldBehind === 'put-back' && $oldRow['status'] === 'pending_review' && $oldRow['claimedAt'] === null && (int) $oldRow['userID'] === P_S
    && count($oldAudit) === 1 && $oldAudit[0]['action'] === 'put-back' && str_contains((string) $oldAudit[0]['details'], 'safe to repeat')
    && GdprEraser::verifyAuditChain(REQ_S_OLD) === true, json_encode([$oldBehind, $oldRow, $oldAudit]));

$setClaimed(REQ_S_NULL, null);
$wrongSite    = $putBack(REQ_S_NULL, SITE_2);
$afterWrong   = $requestRow(REQ_S_NULL);
$rightSite    = $putBack(REQ_S_NULL);
$finished     = $putBack(REQ_S_DONE);
$alreadyBack  = $putBack(REQ_S_OLD);
ge_ok('another organisation\'s request is "not found" and untouched; one stuck from before the moment was recorded is put back; a finished one, and one already back, are left alone',
    $wrongSite === 'not-found' && $afterWrong['status'] === 'processing'
    && $rightSite === 'put-back' && $requestRow(REQ_S_NULL)['status'] === 'pending_review'
    && $finished === 'not-processing' && $requestRow(REQ_S_DONE)['status'] === 'completed'
    && $alreadyBack === 'not-processing' && count(ge_audit(REQ_S_OLD)) === 1,
    json_encode([$wrongSite, $afterWrong, $rightSite, $finished, $alreadyBack]));

// The sign of life itself: refreshed when a minute has gone by, and not before.
$beat = new ReflectionMethod(GdprEraser::class, 'stillGoing');
$setClaimed(REQ_S_LIVE, 10);
$tooSoon   = $beat->invoke(null, $mysqli, REQ_S_LIVE, time() - 30);
$agoBefore = (int) ge_one('SELECT TIMESTAMPDIFF(SECOND, claimedAt, UTC_TIMESTAMP()) AS s FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_S_LIVE])['s'];
$refreshed = $beat->invoke(null, $mysqli, REQ_S_LIVE, time() - 61);
$agoAfter  = (int) ge_one('SELECT TIMESTAMPDIFF(SECOND, claimedAt, UTC_TIMESTAMP()) AS s FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_S_LIVE])['s'];
ge_ok('a run refreshes its sign of life once a minute has passed, in UTC, and not before',
    $agoBefore >= 590 && $agoAfter >= 0 && $agoAfter <= 30 && $refreshed >= time() - 2 && $tooSoon < time() - 20,
    json_encode([$agoBefore, $agoAfter]));

// And the point of it all: a request that was put back can be run.
ge_q("UPDATE tblErasureRequest SET status = 'processing', claimedAt = UTC_TIMESTAMP() WHERE requestID = ? AND status = 'pending_review'", 'i', [REQ_S_OLD]);
$resultS = ge_execute(REQ_S_OLD, P_S);
ge_ok('a request that was put back runs to completion, and its audit trail (the put-back line and the run) verifies',
    is_array($resultS) === true && $resultS['failed'] === 0 && $requestRow(REQ_S_OLD)['status'] === 'completed'
    && GdprEraser::verifyAuditChain(REQ_S_OLD) === true,
    $resultS instanceof Throwable ? $resultS->getMessage() : json_encode([$resultS, $requestRow(REQ_S_OLD)]));

// =============================================================================
ge_section('10', 'Filing a request, and an administrator confirming one on the person\'s behalf (roll-out package A2a)');
// =============================================================================
// Before an administrator can run a request, somebody has to have confirmed
// it is the person's. Three ways: the emailed link ("Request data erasure"),
// the typed phrase ("Delete my account"), or an administrator who has checked.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
ge_user(P_T, 'Tam Tenth', 'tam.tenth@selftest.invalid');
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
unset($_SERVER['HTTP_HOST']); // no address to build a link from, so no email is attempted here
$fullRequest = static fn (int $requestId): ?array => ge_one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [$requestId]);

$ordinary    = GdprEraser::fileRequest(1, P_T);
$ordinaryRow = $fullRequest($ordinary['requestID']);
ge_ok('"Request data erasure" files a request that WAITS: "pending confirmation", with a link token, and no moment of confirmation',
    $ordinary['confirmed'] === false && $ordinaryRow['status'] === 'pending_confirmation' && (int) $ordinaryRow['userID'] === P_T
    && preg_match('/^[0-9a-f]{64}$/', (string) $ordinaryRow['confirmToken']) === 1 && $ordinaryRow['confirmedAt'] === null
    && $ordinaryRow['subjectEmail'] === 'tam.tenth@selftest.invalid', json_encode([$ordinary, $ordinaryRow]));

$typed    = GdprEraser::fileRequest(1, P_T, true);
$typedRow = $fullRequest($typed['requestID']);
ge_ok('"Delete my account" files a request that is ALREADY CONFIRMED: straight to "pending review", the moment recorded, no link token and no email',
    $typed['confirmed'] === true && $typed['mailed'] === false && $typedRow['status'] === 'pending_review'
    && $typedRow['confirmToken'] === null && $typedRow['confirmedAt'] !== null && (int) $typedRow['userID'] === P_T
    && ge_audit($typed['requestID']) === [], json_encode([$typed, $typedRow]));

$confirm = static function (int $requestId, int $siteId): string {
    try {
        return GdprEraser::confirmOnBehalf($requestId, $siteId, ADMIN);
    } catch (Throwable $e) {
        return 'threw: ' . $e->getMessage();
    }
};
$otherOrganisation = $confirm($ordinary['requestID'], SITE_2);
$afterOther        = $fullRequest($ordinary['requestID']);
ge_ok('an administrator of ANOTHER organisation cannot confirm it: "not found", the request untouched, no audit line',
    $otherOrganisation === 'not-found' && $afterOther === $ordinaryRow && ge_audit($ordinary['requestID']) === [],
    json_encode([$otherOrganisation, $afterOther]));

$onBehalf     = $confirm($ordinary['requestID'], 1);
$confirmedRow = $fullRequest($ordinary['requestID']);
$behalfAudit  = ge_audit($ordinary['requestID']);
ge_ok('an administrator of ITS organisation can: it moves to "pending review", the emailed link stops working, and ONE audit line says who confirmed and that they state they checked the person\'s identity',
    $onBehalf === 'confirmed' && $confirmedRow['status'] === 'pending_review' && $confirmedRow['confirmedAt'] !== null
    && $confirmedRow['confirmToken'] === null && (int) $confirmedRow['userID'] === P_T
    && count($behalfAudit) === 1 && $behalfAudit[0]['action'] === 'confirmed-on-behalf'
    && str_contains((string) $behalfAudit[0]['details'], 'account #' . ADMIN)
    && str_contains((string) $behalfAudit[0]['details'], 'states that they checked the person\'s identity')
    && str_contains((string) $behalfAudit[0]['details'], 'Nothing has been erased by this')
    && GdprEraser::verifyAuditChain($ordinary['requestID']) === true,
    json_encode([$onBehalf, $confirmedRow, $behalfAudit]));

$again        = $confirm($ordinary['requestID'], 1);
$typedAgain   = $confirm($typed['requestID'], 1);
$finishedOne  = $confirm(REQ_S_DONE, 1);
ge_ok('only a request that is WAITING can be confirmed: a second press, a request already confirmed by the typed phrase, and a finished one are all left alone, with no new audit line',
    $again === 'not-waiting' && $typedAgain === 'not-waiting' && $finishedOne === 'not-waiting'
    && count(ge_audit($ordinary['requestID'])) === 1 && ge_audit($typed['requestID']) === []
    && $fullRequest($typed['requestID']) === $typedRow && $fullRequest(REQ_S_DONE)['status'] === 'completed',
    json_encode([$again, $typedAgain, $finishedOne]));

ge_q("UPDATE tblErasureRequest SET status = 'processing', claimedAt = UTC_TIMESTAMP() WHERE requestID = ? AND status = 'pending_review'", 'i', [$ordinary['requestID']]);
$resultT = ge_execute($ordinary['requestID'], P_T);
ge_ok('a request confirmed on the person\'s behalf then runs to completion; the other request from the same account is closed with it; and the trail (the confirmation line, then the run) verifies',
    is_array($resultT) === true && $resultT['failed'] === 0 && $fullRequest($ordinary['requestID'])['status'] === 'completed'
    && $fullRequest($typed['requestID'])['status'] === 'completed' && GdprEraser::verifyAuditChain($ordinary['requestID']) === true
    && (ge_audit($ordinary['requestID'])[0]['action'] ?? '') === 'confirmed-on-behalf',
    $resultT instanceof Throwable ? $resultT->getMessage() : json_encode([$resultT, $fullRequest($ordinary['requestID'])]));

ge_finish();
