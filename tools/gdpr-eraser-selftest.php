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
 * WHAT IT CANNOT PROVE
 * --------------------
 * - The rows are not linked to each other the way real data is (each has its
 *   own made-up numbers), so a delete in one table cascading into ANOTHER
 *   table's rows is not exercised by the rows themselves. Section 4b checks the
 *   database's own cascade rules instead: no "delete" instruction may cascade
 *   into a table that must be kept.
 * - It runs on whatever server it is pointed at. It has only ever been run on
 *   MySQL 8.0.36.
 * - It does not drive the administrator's page; it calls the class.
 * - A second person column that no instruction matches on (a row created by
 *   somebody else and last changed by P) is still not found. That is #492, and
 *   this test does not pretend otherwise.
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
 * @version   1.0.0
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
const EXPECTED_CHECKS = 79;

// The headings that must all be reached for a pass.
const REQUIRED_SECTIONS = ['0', '1', '2', '3', '4', '4b', '5', '5b', '6', '7', '7b', '7c', '8'];

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

const REQ_A     = 940001; // P_A's request, the one that is run
const REQ_A_DUP = 940002; // P_A pressed the button twice: still waiting
const REQ_A_OLD = 940003; // an older request of P_A's, cancelled
const REQ_C     = 940004; // C's own waiting request: must not be touched
const REQ_B     = 940011;
const REQ_PC    = 940021;
const REQ_E     = 940031;
const REQ_F     = 940041;

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
 * list, plus every column the database itself links to an account. The second
 * half matters: it is how this test notices a person column the list forgot.
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
        if (in_array($col['name'], GdprEraser::LINK_COLUMNS, true) === true
            || in_array($col['name'], $fk[$table] ?? [], true) === true
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
    $perTable     = [];
    foreach ($instructions as $instruction) {
        $perTable[(string) $instruction['table']] = ($perTable[(string) $instruction['table']] ?? 0) + 1;
    }

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

        // R2: where an unlink lists more person columns to empty, a second row
        // with the person in ALL of them. Only when the table has a single
        // instruction, so no other instruction can find the row first. A
        // unique key may forbid a second row for the same person; that is the
        // one case skipped, and section 3 checks enough of them were made.
        $listed = array_values(array_diff(
            array_intersect(array_map('strval', (array) ($instruction['nullCols'] ?? [])), ge_personColumns($table)),
            [$col]
        ));
        if ($action === 'anonymise' && $listed !== [] && $perTable[$table] === 1) {
            try {
                $ref2   = ge_insert($table, array_fill_keys(array_merge([$col], $listed), $person));
                $rows[] = ['i' => $i, 'kind' => 'R2', 'ref' => $ref2];
                $allowed[ge_keyText($table, $ref2['key'])] = true;
                ge_ensureParents($ref2);
            } catch (mysqli_sql_exception $e) {
                if ((int) $e->getCode() !== 1062) {
                    throw $e;
                }
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
        'allowed' => $allowed, 'demo' => $demo, 'emailLog' => $emailLog, 'file' => $file];
}

/** Run the real erasure. Returns its answer, or the exception it threw. */
function ge_execute(int $requestId, int $person): mixed
{
    try {
        return GdprEraser::execute($requestId, $person, ADMIN);
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
        foreach ($now as $name => $value) {
            if (in_array($name, $onUpdate, true) === true) {
                continue;
            }
            $mayEmpty = $name === $col || in_array($name, $listed, true) === true;
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
        $rowsFor[$tracked['i']] = ($rowsFor[$tracked['i']] ?? 0) + 1;
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
foreach ([REQ_A, REQ_A_DUP, REQ_A_OLD] as $requestId) {
    $worldA['allowed'][ge_keyText('tblErasureRequest', ['requestID' => $requestId])] = true;
}

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
    $heldA !== null && $heldA['rows'] === 3 && $heldA['action'] === 'anonymise'
    && str_contains($heldA['reason'], '1 processing') && str_contains($heldA['reason'], '1 pending review')
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
    $resultA['failed'] === 0 && $resultA['changed'] === true && $resultA['steps'] === $instructionCount + 4,
    json_encode($resultA) . ' (expected steps: ' . ($instructionCount + 4) . ')');

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

// =============================================================================
ge_section('2', 'The audit trail: one line per instruction, in order, and sealed');
// =============================================================================
$auditA = ge_audit(REQ_A);
$auditProblems = ge_checkAudit($worldA, $auditA, true, [], 'completed');
ge_ok('exactly one line per instruction, each for the right table, with the right outcome and row count ('
    . count($auditA) . ' lines)', $auditProblems === [], implode('; ', array_slice($auditProblems, 0, 8)));
ge_ok('the number of lines is the instructions, the Demo Data step, the three dedicated lines and the closing line',
    count($auditA) === $instructionCount + 5, count($auditA) . ' lines for ' . $instructionCount . ' instructions');
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
$visitLine = array_values(array_filter($auditA, static fn (array $l): bool => $l['tableName'] === 'tblCareVisit'));
ge_ok('tblCareVisit: the audit line says the visits the person made are kept and why their number stays, not that nothing ties it to an account',
    count($visitLine) === 1 && $visitLine[0]['action'] === 'retain'
    && str_contains((string) $visitLine[0]['details'], 'pastoral visits this person made are kept')
    && str_contains((string) $visitLine[0]['details'], 'does not allow that column to be empty')
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
ge_ok('rows naming the person in TWO columns were tested too (' . implode(', ', $twoColumnTables) . ')',
    $rowsA['r2'] >= 3 && $rowsA['r2'] === count($twoColumnTables), 'only ' . $rowsA['r2']);
sort($rowsA['left']);
ge_ok('the columns that could not be emptied are exactly the known list: ' . implode(', ', KNOWN_LEFT_IN_PLACE),
    $rowsA['left'] === KNOWN_LEFT_IN_PLACE, json_encode($rowsA['left']));
$leftLines = array_values(array_filter($auditA, static fn (array $l): bool => in_array($l['action'], ['skip', 'partial'], true)));
ge_ok('...and each has an audit line saying why (' . count($leftLines) . ' line)',
    count($leftLines) === count(KNOWN_LEFT_IN_PLACE) && str_contains((string) ($leftLines[0]['details'] ?? ''), 'does not allow'),
    json_encode($leftLines));

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
    if (($meta['decision'] ?? '') === 'retain') {
        $mustKeep[] = (string) $table;
    }
}
$cascades = [];
foreach (ge_q(
    "SELECT REFERENCED_TABLE_NAME AS parent, TABLE_NAME AS child FROM information_schema.REFERENTIAL_CONSTRAINTS "
    . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND DELETE_RULE = 'CASCADE'"
) as $link) {
    $cascades[$link['parent']][] = $link['child'];
}
$deleteFrom = [];
foreach ($worldA['instructions'] as $instruction) {
    if ($instruction['action'] === 'delete') {
        $deleteFrom[(string) $instruction['table']] = true;
    }
}
$reached = [];
$queue   = array_keys($deleteFrom);
$dragged = [];
while ($queue !== []) {
    $parent = array_shift($queue);
    foreach ($cascades[$parent] ?? [] as $child) {
        if (isset($reached[$child]) === true) {
            continue;
        }
        $reached[$child] = true;
        $queue[]         = $child;
        if (in_array($child, $mustKeep, true) === true) {
            $dragged[] = $parent . ' -> ' . $child;
        }
    }
}
ge_ok('the database\'s cascade rules were read (more than 100 of them)', array_sum(array_map('count', $cascades)) > 100);
ge_ok('deleting from any of the ' . count($deleteFrom) . ' tables an instruction deletes from never cascades into a kept table',
    $dragged === [], implode('; ', $dragged));

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
foreach ([['tblDbsChecks', 'userID'], ['tblCareVisit', 'visitedByID'], ['tblAnonymousCheckins', '']] as [$table, $col]) {
    $found = ge_find($worldA, $table, $col);
    ge_ok($table . ': kept, and not one column changed',
        $found !== null && $found['action'] === 'retain' && ge_row($found['ref']) === $beforeRowsA[$found['n']]);
}
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
    is_array($resultB1) === true && $resultB1['failed'] === 1 && $resultB1['steps'] === $instructionCount + 4,
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
    str_contains((string) $summary['details'], '1 of ' . ($instructionCount + 4) . ' steps failed')
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
    count($auditB2) === $instructionCount + 4 && $noneLines > 100
    && ($auditB2[count($auditB2) - 1]['action'] ?? '') === 'completed',
    count($auditB2) . ' lines, expected ' . ($instructionCount + 4));
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
$resultF2 = ge_execute(REQ_F, P_F);
$reqF2    = ge_one('SELECT status FROM tblErasureRequest WHERE requestID = ?', 'i', [REQ_F]);
ge_ok('once the column is back (the database upgraded), running the request again completes it',
    is_array($resultF2) === true && $resultF2['failed'] === 0 && $reqF2['status'] === 'completed',
    $resultF2 instanceof Throwable ? $resultF2->getMessage() : json_encode([$resultF2, $reqF2]));

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
ge_q("UPDATE tblErasureAudit SET details = 'edited afterwards' WHERE requestID = ? ORDER BY auditID LIMIT 1", 'i', [REQ_A]);
ge_ok('an audit line edited afterwards breaks the chain, and verifying says so', GdprEraser::verifyAuditChain(REQ_A) === false);

ge_finish();
