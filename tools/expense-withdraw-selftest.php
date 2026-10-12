<?php
// Path: tools/expense-withdraw-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A withdrawn expense claim: the list of choices, and the upgrade — self-test 💷↩️ (#587)
 * -----------------------------------------------------------------------------
 * The Withdraw button set a claim's status to 'Withdrawn', and the column
 * `tblExpenseClaims.status` did not allow that value. Migration
 * `web/_sql/211_expense_claim_withdrawn.sql` adds it, and turns an EMPTY
 * status (what a database server without strict checking stored in its
 * place) into 'Withdrawn'. This file checks:
 *
 *   1. The list of choices is the same everywhere it is written down: the
 *      install script, the migration, the API's status filter and the
 *      report builder. 'Withdrawn' is LAST, and the four older choices are
 *      in their old order, so no stored claim changes meaning.
 *   2. The upgrade, on real rows, run by the REAL upgrader
 *      (`Portal\Core\Migrator::runOne()`, which is what Admin → Upgrade
 *      uses): on a database with no empty status it changes no row at all;
 *      on one holding two claims with an empty status it changes those two
 *      and nothing else, and they keep their "last changed" time; a second
 *      and a third run change nothing; receipts, line items and approvals
 *      are untouched; and afterwards 'Withdrawn' can be stored with strict
 *      checking on.
 *   3. The withdraw page, read as text with its comments removed: it still
 *      asks the three questions (is the claim this organisation's and this
 *      person's own, is it Pending, and is it STILL Pending once the row is
 *      locked) before it writes.
 *
 * WHY PART 3 READS THE PAGE AS TEXT
 * ---------------------------------
 * `tools/access-pages-selftest.php` (part M) presses the real button with
 * real sign-ins and reads the stored status back; that is the proof a person
 * is refused. It sends one request at a time, so it cannot see the question
 * asked under the row lock go missing: the first question refuses first.
 * Part 3 is what fails then.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Every row it makes is numbered 917000 to 917999. They are
 *   removed at the START (in case an earlier run was interrupted) and at
 *   the END.
 * - IT CHANGES THE SHAPE OF `tblExpenseClaims` FOR THE LENGTH OF THE RUN.
 *   To stand for an older database it takes 'Withdrawn' off the column's
 *   list, twice, and lets the migration put it back. So nothing else may
 *   use the same database while it runs, and it refuses to start if the
 *   table holds a claim that is withdrawn or has an empty status (taking
 *   the choice away would damage that row). A change of shape cannot be
 *   rolled back, so the clean-up runs the migration once more if the list
 *   is still the old one; a run that is killed outright can leave the old
 *   list behind, and running this file again puts it right.
 * - It forgets, and the migration then writes again, the line in
 *   `tblMigrations` that says migration 211 has run.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - That a real person's press is refused or accepted: see the real-pages
 *   test above.
 * - A database server STARTED without strict checking. Part 2 switches
 *   strict checking off for its own connection to plant the empty rows,
 *   which stores the same thing, and says in its first lines which way the
 *   server itself is set.
 * - A table with many thousands of claims, or one whose column somebody
 *   changed by hand.
 * - MariaDB: no test in this repository covers it.
 * - Two people pressing at the same moment (the page's row lock).
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… php tools/expense-withdraw-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default root).
 *         The database must already hold `web/_sql/full_schema.sql`.
 * Exit:   0 only when every check ran and passed; 1 otherwise, including
 *         every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/587
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 37;

/** The migration this file is about. */
const MIGRATION = '211_expense_claim_withdrawn.sql';

/**
 * The column's choices, in order, written out by hand. NOT read from any of
 * the four places being checked: taking the expected answer from one of them
 * would agree with it even when it was wrong.
 */
const CHOICES     = ['Pending', 'Approved', 'Rejected', 'Reimbursed', 'Withdrawn'];
const OLD_CHOICES = ['Pending', 'Approved', 'Rejected', 'Reimbursed'];

// The made-up world. 915000s belong to the expense-rules test and 916000s to
// the real-pages test, so this file takes 917000 to 917999.
const ORG      = 917001;
const CLAIMANT = 917011;
const APPROVER = 917012;
const DEPT     = 917101;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows and changes a table's shape");
}

$web  = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';
$sql  = $web . DIRECTORY_SEPARATOR . '_sql';
$apps = $web . DIRECTORY_SEPARATOR . '_apps' . DIRECTORY_SEPARATOR;

$failures = 0;
$passes   = 0;

/**
 * ✅ Report one check.
 *
 * @param string $what   What is being checked, in plain words.
 * @param bool   $isOk   Whether it passed.
 * @param string $detail Shown only on failure.
 */
function check(string $what, bool $isOk, string $detail = ''): void
{
    global $failures, $passes;
    if ($isOk === true) {
        $passes++;
        echo '  PASS  ' . $what . "\n";

        return;
    }
    $failures++;
    echo '  FAIL  ' . $what . "\n";
    if ($detail !== '') {
        echo '          ' . $detail . "\n";
    }
}

/** A file's PHP with its comments AND all its spacing removed. Text inside quotes is kept as written. '' if the file is missing. */
function squeezed(string $file): string
{
    if (is_file($file) === false) {
        return '';
    }
    $out = '';
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) === true) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], true) === true) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

/** Where a piece of text first appears, or -1. */
function at(string $haystack, string $needle): int
{
    $position = strpos($haystack, $needle);

    return $position === false ? -1 : $position;
}

/** A SQL file with its `--` comment lines removed, so a list quoted in a comment is never the one found. */
function sqlOnly(string $file): string
{
    $lines = is_file($file) === true ? (array) file($file) : [];

    return implode('', array_filter($lines, static fn (string $line): bool => str_starts_with(ltrim($line), '--') === false));
}

/**
 * The choices in a piece of text shaped like `'Pending','Approved'` (with or
 * without spaces, and with quotes doubled as they are inside a SQL string).
 *
 * @return list<string>
 */
function choicesIn(string $list): array
{
    preg_match_all("/'+([A-Za-z]+)'+/", $list, $found);

    return $found[1];
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "A withdrawn expense claim: the list of choices, and the upgrade — self-test (#587)\n";
echo str_repeat('=', 78) . "\n";

// -----------------------------------------------------------------------------
// 1. One list, written in four places
// -----------------------------------------------------------------------------
echo "\n1. The list of choices is the same everywhere\n";

$installText   = sqlOnly($sql . DIRECTORY_SEPARATOR . 'full_schema.sql');
$migrationText = sqlOnly($sql . DIRECTORY_SEPARATOR . MIGRATION);
check('the install script and migration 211 were both read', strlen($installText) > 100000 && strlen($migrationText) > 300);

// The install script: the `status` line inside CREATE TABLE `tblExpenseClaims`.
$table         = preg_match('/CREATE TABLE IF NOT EXISTS `tblExpenseClaims` \((.*?)\) ENGINE=InnoDB/s', $installText, $t) === 1 ? $t[1] : '';
$installColumn = preg_match('/^\s*`status`\s+ENUM\(([^)]*)\)\s+COLLATE utf8mb4_general_ci DEFAULT \'Pending\',$/m', $table, $c) === 1 ? $c[1] : '';
check(
    'the install script declares the five choices, in order, with Withdrawn LAST (same character rules, default Pending, may hold no value)',
    choicesIn($installColumn) === CHOICES,
    'found: ' . json_encode(choicesIn($installColumn))
);

// The migration: the one ALTER, inside the guard's quoted statement.
$alters          = preg_match_all('/ALTER TABLE `tblExpenseClaims` MODIFY COLUMN `status` ENUM\(([^)]*)\) COLLATE utf8mb4_general_ci DEFAULT \'\'Pending\'\'\',/', $migrationText, $a);
$migrationColumn = $alters === 1 ? $a[1][0] : '';
check(
    'migration 211 changes the column to exactly the same five, in the same order, restating the rest of the column as the install script has it',
    $alters === 1 && choicesIn($migrationColumn) === CHOICES,
    'found ' . (int) $alters . ' ALTER(s): ' . json_encode(choicesIn($migrationColumn))
);
check(
    'the four older choices are still first and in their old order, so every stored claim keeps its meaning',
    array_slice(choicesIn($installColumn), 0, 4) === OLD_CHOICES && array_slice(choicesIn($migrationColumn), 0, 4) === OLD_CHOICES
);
check(
    'migration 211 asks the database whether Withdrawn is already on the list before changing anything (safe to run twice)',
    str_contains($migrationText, "AND COLUMN_TYPE NOT LIKE '%''Withdrawn''%'") === true
        && preg_match('/SET @sql := IF\(@needs_withdrawn = 1,\s*\'ALTER TABLE/', $migrationText) === 1
);
check(
    'its only UPDATE touches rows with an EMPTY status and nothing else, and keeps their "last changed" time',
    substr_count($migrationText, 'UPDATE `tblExpenseClaims`') === 1
        && preg_match('/UPDATE `tblExpenseClaims`\s+SET `status` = \'Withdrawn\', `updatedAt` = `updatedAt`\s+WHERE `status` = \'\';/', $migrationText) === 1
        && substr_count($migrationText, 'DELETE') === 0
);
$record = "INSERT INTO `tblMigrations` (`filename`) VALUES ('" . MIGRATION . "')\nON DUPLICATE KEY UPDATE `filename` = `filename`;";
check(
    'both files record migration 211 as run, in the form that is safe to repeat',
    substr_count($migrationText, $record) === 1 && substr_count($installText, $record) === 1
);

// The API's status filter.
$apiList = squeezed($apps . 'expenses/api/list.php');
$apiSet  = preg_match('/\$validStatuses=\[([^\]]*)\];/', $apiList, $v) === 1 ? choicesIn($v[1]) : [];
check('the API\'s status filter (expenses/api/list.php) allows exactly the five', $apiSet === CHOICES, 'found: ' . json_encode($apiSet));

// The report builder, asked through its own class.
define('PORTAL_CORE', $web . DIRECTORY_SEPARATOR . '_core');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'ReportRegistry.php';
$reportColumn = Portal\Core\ReportRegistry::column('expenses', 'status');
check(
    'the report builder\'s list for an expense claim\'s status is exactly the five',
    ($reportColumn['enum'] ?? null) === CHOICES,
    'found: ' . json_encode($reportColumn['enum'] ?? null)
);

// -----------------------------------------------------------------------------
// 2. The upgrade, on real rows
// -----------------------------------------------------------------------------
echo "\n2. The upgrade, on real rows, run by the real upgrader\n";

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$db->set_charset('utf8mb4');

/** @param list<mixed> $params @return list<array<string,mixed>> */
function q(string $statement, string $types = '', array $params = []): array
{
    global $db;
    $stmt = $db->prepare($statement);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** The column as the database itself describes it: choices, default, may-be-empty, character rules. */
function columnNow(): string
{
    $row = q(
        "SELECT COLUMN_TYPE, IFNULL(COLUMN_DEFAULT, '(none)') AS d, IS_NULLABLE, COLLATION_NAME FROM information_schema.COLUMNS "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblExpenseClaims' AND COLUMN_NAME = 'status'"
    )[0] ?? null;

    return $row === null ? 'no such column' : implode(' | ', $row);
}

/** The column, described, for a list of choices. */
function columnFor(array $choices): string
{
    return "enum('" . implode("','", $choices) . "') | Pending | YES | utf8mb4_general_ci";
}

/**
 * Every column of every claim this file made, PLUS the position the status
 * is stored at (1 = the first choice, 0 = the empty value a loose server
 * stores for a choice it does not know), and every row that hangs off them.
 * Two of these being equal means nothing at all changed.
 */
function everything(): string
{
    return json_encode([
        'claims'    => q('SELECT c.*, CAST(c.status + 0 AS SIGNED) AS statusPosition FROM tblExpenseClaims c WHERE c.claimID BETWEEN 917000 AND 917999 ORDER BY c.claimID'),
        'approvals' => q('SELECT * FROM tblExpenseClaimApprovals WHERE claimID BETWEEN 917000 AND 917999 ORDER BY approvalID'),
        'receipts'  => q('SELECT * FROM tblExpenseClaimFiles WHERE claimID BETWEEN 917000 AND 917999 ORDER BY fileID'),
        'items'     => q('SELECT * FROM tblExpenseClaimItems WHERE claimID BETWEEN 917000 AND 917999 ORDER BY itemID'),
        'payments'  => q('SELECT * FROM tblExpenseClaimPayments WHERE claimID BETWEEN 917000 AND 917999 ORDER BY payID'),
    ], JSON_THROW_ON_ERROR);
}

function removeWorld(): void
{
    // Receipts, line items, approvals and payments go with their claim (the tables' own rule).
    q('DELETE FROM tblExpenseClaims WHERE claimID BETWEEN 917000 AND 917999');
    q('DELETE FROM tblDepts WHERE deptID BETWEEN 917000 AND 917999');
    q('DELETE FROM tblUserSites WHERE siteID = ? OR userID BETWEEN 917000 AND 917999', 'i', [ORG]);
    q('DELETE FROM tblUsers WHERE userID BETWEEN 917000 AND 917999');
    q('DELETE FROM tblSites WHERE siteID = ?', 'i', [ORG]);
}

/** Take 'Withdrawn' off the list, to stand for a database from before migration 211. */
function makeOld(): void
{
    q("ALTER TABLE `tblExpenseClaims` MODIFY COLUMN `status` ENUM('Pending','Approved','Rejected','Reimbursed') COLLATE utf8mb4_general_ci DEFAULT 'Pending'");
    q('DELETE FROM tblMigrations WHERE filename = ?', 's', [MIGRATION]);
}

function claim(int $id, ?string $status, string $title, string $changedAt): void
{
    q(
        'INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status, fileName, createdAt, updatedAt) '
        . 'VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)',
        'iiiisdssss', [$id, ORG, CLAIMANT, DEPT, $title, ($id % 100) * 1.25, $status, 'claim_' . $id . '.pdf', $changedAt, $changedAt]
    );
}

// The real upgrader, exactly as Admin → Upgrade uses it.
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'Migrator.php';
$upgrader = new Portal\Core\Migrator($db, $sql);
$upgrade  = static fn (): array => $upgrader->runOne(MIGRATION);

$strictServer = str_contains((string) q('SELECT @@GLOBAL.sql_mode AS m')[0]['m'], 'STRICT');
$sessionMode  = (string) q('SELECT @@SESSION.sql_mode AS m')[0]['m'];
echo '  (database server ' . q('SELECT VERSION() AS v')[0]['v'] . ', started ' . ($strictServer === true ? 'WITH' : 'WITHOUT') . " strict checking)\n";

removeWorld();
$inTheWay = (int) q("SELECT COUNT(*) AS n FROM tblExpenseClaims WHERE status = 'Withdrawn' OR status = ''")[0]['n'];
if ($inTheWay > 0) {
    refuse("this database holds {$inTheWay} claim(s) that are withdrawn or have an empty status; taking the choice off the list would damage them. Use a database nothing else is using");
}

$cleaned = false;
/** Put the table's shape and the rows back, ONCE. Also registered as a shutdown function. */
$cleanup = static function () use (&$cleaned, $upgrade, $sessionMode): void {
    if ($cleaned === true) {
        return;
    }
    $cleaned = true;
    try {
        q('SET SESSION sql_mode = ?', 's', [$sessionMode]);
        removeWorld();
        if (columnNow() !== columnFor(CHOICES)) {
            $upgrade();
        }
    } catch (Throwable $e) {
        echo '  FAIL  the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
};
register_shutdown_function($cleanup);

// Everything from here to the clean-up is inside one `try`. A check that
// goes wrong part way (a migration that damaged a row, say, so that the next
// step is refused by the database) must end as a FAIL line and a failed run
// that still tidies up and still reaches the end of this file, not as a crash
// half way down. The first planted fault this file was tried on did exactly
// that, and left with exit code 255 and no summary.
try {
    q("INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES (?, 'selftest-withdraw', 'Selftest withdraw', 1)", 'i', [ORG]);
    foreach ([CLAIMANT => 'claimant', APPROVER => 'approver'] as $id => $name) {
        q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, 'Selftest withdraw ' . $name, 'u' . $id . '.withdraw@selftest.invalid']);
    }
    q("INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES (?, ?, 'Selftest withdraw dept', 'SWD', 1)", 'ii', [DEPT, ORG]);

    // Times a few days back, each different, worked out from today. They only
    // have to differ from "now", so that a "last changed" time that moved shows.
    $ago = static fn (int $hours): string => date('Y-m-d H:i:s', (int) strtotime('-3 days') - $hours * 3661);

    // --- round 1: an older database with NO empty status (every strict server) ---
    makeOld();
    check('to stand for an older database, Withdrawn is taken off the list', columnNow() === columnFor(OLD_CHOICES), columnNow());
    claim(917201, 'Pending', 'WITHDRAW pending', $ago(1));
    claim(917202, 'Approved', 'WITHDRAW approved', $ago(2));
    claim(917203, 'Rejected', 'WITHDRAW rejected', $ago(3));
    claim(917204, 'Reimbursed', 'WITHDRAW reimbursed', $ago(4));
    claim(917205, null, 'WITHDRAW no status at all', $ago(5));
    q("INSERT INTO tblExpenseClaimApprovals (claimID, userID, decision, approverRole) VALUES (917202, ?, 'Approved', 'dept_approver')", 'i', [APPROVER]);
    q("INSERT INTO tblExpenseClaimPayments (claimID, payReference, payAmount, paidByID) VALUES (917204, 'WITHDRAW-PAY', 5.00, ?)", 'i', [APPROVER]);

    $before = everything();
    $result = $upgrade();
    check('round 1 — a database with one claim of each status and none empty: the upgrader runs migration 211 without error', $result['success'] === true, $result['error']);
    check('…the column now has the five choices, Withdrawn last, and is otherwise as it was', columnNow() === columnFor(CHOICES), columnNow());
    check('…and NO row changed: every column of every claim, approval and payment is identical, each status at the same stored position', everything() === $before);
    check('…and the migration is recorded as run, once', (int) q('SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = ?', 's', [MIGRATION])[0]['n'] === 1);

    // --- round 2: the same database, after the Withdraw button was pressed twice
    //     on a server without strict checking -------------------------------------
    makeOld();
    claim(917206, 'Pending', 'WITHDRAW pressed on a loose server, one', $ago(6));
    claim(917207, 'Pending', 'WITHDRAW pressed on a loose server, two', $ago(7));
    q("INSERT INTO tblExpenseClaimApprovals (claimID, userID, decision, approverRole) VALUES (917206, ?, 'Approved', 'dept_approver')", 'i', [APPROVER]);
    q("INSERT INTO tblExpenseClaimFiles (claimID, originalFilename, storedFilename) VALUES (917206, 'receipt.jpg', 'withdraw-selftest-receipt.jpg')");
    q("INSERT INTO tblExpenseClaimItems (claimID, itemName, unitCost, quantity, lineTotal) VALUES (917206, 'WITHDRAW item', 7.50, 1, 7.50)");
    // The page's own statement, word for word, with strict checking off for this connection.
    $pageUpdate = 'UPDATE tblExpenseClaims SET status = ?, updatedAt = NOW() WHERE claimID = ? AND siteID = ?';
    q("SET SESSION sql_mode = ''");
    q($pageUpdate, 'sii', ['Withdrawn', 917206, ORG]);
    q($pageUpdate, 'sii', ['Withdrawn', 917207, ORG]);
    q('SET SESSION sql_mode = ?', 's', [$sessionMode]);
    // The press's own time, moved a few days back so that it differs from "now".
    q('UPDATE tblExpenseClaims SET updatedAt = ? WHERE claimID = 917206', 's', [$ago(8)]);
    q('UPDATE tblExpenseClaims SET updatedAt = ? WHERE claimID = 917207', 's', [$ago(9)]);
    $stored = q('SELECT status, CAST(status + 0 AS SIGNED) AS position FROM tblExpenseClaims WHERE claimID IN (917206, 917207) ORDER BY claimID');
    check(
        'the fault (#587): on the old list, without strict checking, the page\'s own UPDATE "succeeds" and stores an EMPTY status',
        $stored === [['status' => '', 'position' => 0], ['status' => '', 'position' => 0]],
        json_encode($stored)
    );

    $before = everything();
    $result = $upgrade();
    $after  = everything();
    check('round 2 — the same database with two empty statuses: the upgrader runs migration 211 without error', $result['success'] === true, $result['error']);
    check('…the column has the five choices again', columnNow() === columnFor(CHOICES), columnNow());

    // What is expected, worked out from the "before" picture by hand: the two
    // empty rows become Withdrawn (stored position 5), and nothing else moves.
    $expected = json_decode($before, true);
    $changed  = [];
    foreach ($expected['claims'] as $i => $row) {
        if ($row['status'] === '') {
            $expected['claims'][$i]['status']         = 'Withdrawn';
            $expected['claims'][$i]['statusPosition'] = 5;
            $changed[]                                = (int) $row['claimID'];
        }
    }
    $afterRows = json_decode($after, true);
    check('…exactly the two claims with an empty status are the ones it changed', $changed === [917206, 917207], json_encode($changed));
    check(
        '…they now read Withdrawn, and EVERY other column of EVERY claim is identical — the five other claims, the one with no status at all included',
        $afterRows['claims'] === $expected['claims'],
        'A row differs from what was expected. Compare tblExpenseClaims by hand.'
    );
    $times = static fn (array $picture): array => array_column($picture['claims'], 'updatedAt', 'claimID');
    check(
        '…the two repaired claims KEEP their "last changed" time (the moment the person pressed Withdraw), and so does every other claim',
        $times($afterRows) === $times(json_decode($before, true)) && count($times($afterRows)) === 7
    );
    check(
        '…and their receipt, line item and approval, and the other claims\' approval and payment, are untouched',
        $afterRows['approvals'] === $expected['approvals'] && $afterRows['receipts'] === $expected['receipts'] && $afterRows['items'] === $expected['items']
            && $afterRows['payments'] === $expected['payments'] && count($afterRows['approvals']) === 2 && count($afterRows['receipts']) === 1
            && count($afterRows['items']) === 1 && count($afterRows['payments']) === 1
    );

    // --- runs 2 and 3 ------------------------------------------------------------
    $result = $upgrade();
    check('a SECOND run of migration 211 succeeds and changes nothing at all', $result['success'] === true && everything() === $after && columnNow() === columnFor(CHOICES), $result['error']);
    $result = $upgrade();
    check('a THIRD run succeeds and changes nothing at all', $result['success'] === true && everything() === $after && columnNow() === columnFor(CHOICES), $result['error']);
    check('…and it is still recorded once', (int) q('SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = ?', 's', [MIGRATION])[0]['n'] === 1);
    check(
        'the key that includes the status is still on the table, and so are the table\'s five others',
        array_column(q("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblExpenseClaims' AND COLUMN_NAME = 'status'"), 'INDEX_NAME') === ['idx_claims_site_status']
            && (int) q("SELECT COUNT(DISTINCT INDEX_NAME) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblExpenseClaims'")[0]['n'] === 6
    );

    // --- afterwards: the page's statement, with strict checking ON ----------------
    q("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
    q($pageUpdate, 'sii', ['Withdrawn', 917201, ORG]);
    check(
        'after the upgrade, the page\'s own UPDATE with strict checking ON stores Withdrawn',
        q('SELECT status FROM tblExpenseClaims WHERE claimID = 917201')[0]['status'] === 'Withdrawn'
    );
    $refused = '';
    try {
        q($pageUpdate, 'sii', ['NotAChoice', 917202, ORG]);
    } catch (mysqli_sql_exception $e) {
        $refused = $e->getCode() . ' ' . $e->getMessage();
    }
    check(
        'control: the same statement with a value that is NOT on the list is refused (error 1265), so the check above really ran with strict checking on',
        str_starts_with($refused, '1265 ') === true && q('SELECT status FROM tblExpenseClaims WHERE claimID = 917202')[0]['status'] === 'Approved',
        $refused === '' ? 'it was not refused' : $refused
    );
    q("SET SESSION sql_mode = ''");
    q($pageUpdate, 'sii', ['Withdrawn', 917203, ORG]);
    check(
        '…and with strict checking OFF it stores Withdrawn too, not an empty status',
        q('SELECT status, CAST(status + 0 AS SIGNED) AS position FROM tblExpenseClaims WHERE claimID = 917203')[0] === ['status' => 'Withdrawn', 'position' => 5]
    );
} catch (Throwable $e) {
    check('part 2 ran to its end', false, 'It stopped early, so the checks after this line in part 2 did not run: ' . $e->getMessage());
}

$cleanup();
$left = (int) q(
    'SELECT (SELECT COUNT(*) FROM tblSites WHERE siteID = ?) + (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 917000 AND 917999) '
    . '+ (SELECT COUNT(*) FROM tblExpenseClaims WHERE claimID BETWEEN 917000 AND 917999) + (SELECT COUNT(*) FROM tblDepts WHERE deptID BETWEEN 917000 AND 917999) '
    . '+ (SELECT COUNT(*) FROM tblExpenseClaimApprovals WHERE claimID BETWEEN 917000 AND 917999) + (SELECT COUNT(*) FROM tblExpenseClaimFiles WHERE claimID BETWEEN 917000 AND 917999) AS n',
    'i', [ORG]
)[0]['n'];
check(
    'clean-up: nothing this file made is left, the column has its five choices, and migration 211 is recorded',
    $left === 0 && columnNow() === columnFor(CHOICES) && (int) q('SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = ?', 's', [MIGRATION])[0]['n'] === 1
        && (string) q('SELECT @@SESSION.sql_mode AS m')[0]['m'] === $sessionMode,
    'rows left: ' . $left . '; column: ' . columnNow()
);

// -----------------------------------------------------------------------------
// 3. The withdraw page still asks its three questions before it writes
// -----------------------------------------------------------------------------
echo "\n3. The withdraw page asks its three questions before it writes\n";

$page = squeezed($apps . 'expenses/withdraw/save.php');
check('expenses/withdraw/save.php was read', strlen($page) > 1500);

$lookup = "'SELECT EC.claimID, EC.userID, EC.status, EC.claimTitle, EC.deptID '.'FROM tblExpenseClaims EC '.'WHERE EC.claimID = ? AND EC.siteID = ? LIMIT 1'";
check(
    'it looks the claim up in the signed-in person\'s OWN organisation only',
    substr_count($page, $lookup) === 1 && str_contains($page, "\$stmt->bind_param('ii',\$claimID,\$siteId);") === true && substr_count($page, '$siteId=Site::id();') === 1
);
// The whole statement, exactly: one answer for "no such claim", "another
// organisation's claim" and "somebody else's claim". With anything written in
// front of the question (`$isAdmin === false &&`), this no longer matches.
$notYours = "if(\$claim===null||(int)\$claim['userID']!==\$userId){\$_SESSION['flash_msg']='Claim not found.';\$_SESSION['flash_type']='danger';"
    . "header('Location: '.Router::url('expenses/submit'));exit();}";
check(
    'somebody else\'s claim gets the SAME answer as a claim that does not exist: one plain top-level `if`, asked once',
    substr_count($page, $notYours) === 1 && substr_count($page, "['userID']!==\$userId") === 1
        && substr_count(substr($page, 0, max(0, at($page, $notYours))), '{') === substr_count(substr($page, 0, max(0, at($page, $notYours))), '}')
);
check(
    '…and $userId is the signed-in account and nothing else',
    substr_count($page, "\$userId=(int)(\$_SESSION['user_id']??0);") === 1 && substr_count($page, '$userId=') === 1
);
$notPending = "if(\$claim['status']!=='Pending'){";
$stillPending = "if(\$lockedClaim===null||\$lockedClaim['status']!=='Pending'){\$mysqli->rollback();";
$write = "'UPDATE tblExpenseClaims SET status = ?, updatedAt = NOW() WHERE claimID = ? AND siteID = ?'";
check(
    'only a Pending claim gets past the first look, which comes after the "not found" answer and before anything is written',
    substr_count($page, $notPending) === 1 && at($page, $notYours) >= 0 && at($page, $notYours) < at($page, $notPending)
        && at($page, $notPending) < at($page, '$mysqli->begin_transaction()')
);
check(
    '…and it is asked AGAIN once the row is locked, before the one statement that writes',
    substr_count($page, $stillPending) === 1 && substr_count($page, $write) === 1
        && at($page, 'FOR UPDATE') >= 0 && at($page, 'FOR UPDATE') < at($page, $stillPending) && at($page, $stillPending) < at($page, $write)
        && substr_count($page, 'UPDATE tblExpenseClaims') === 1
);
check(
    'the one statement that writes is bound to Withdrawn, this claim and this organisation, and nothing on the page deletes',
    str_contains($page, "\$withdrawn='Withdrawn';") === true && str_contains($page, "\$stmt->bind_param('sii',\$withdrawn,\$claimID,\$siteId);") === true
        && stripos($page, 'DELETE FROM') === false
);
check(
    'every address the page sends a person to is built by Router::url()',
    substr_count($page, "header('Location: '.Router::url(") === substr_count($page, "header('Location:") && substr_count($page, "header('Location:") === 7
);

// -----------------------------------------------------------------------------
// The end: every check ran
// -----------------------------------------------------------------------------
echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed, %d ran of %d expected\n", $passes, $failures, $passes + $failures, EXPECTED_CHECKS);

if (($passes + $failures) !== EXPECTED_CHECKS) {
    $failures++;
    echo "\nFAIL — the number of checks that ran is not the number this file expects. A block was\n";
    echo "skipped or ran twice, or a check was added without updating the number at the top.\n";
}
if ($failures > 0) {
    echo "\nSomething about withdrawing an expense claim, or the upgrade that allows it, is wrong. Read the FAIL lines above.\n";
    exit(1);
}

echo "\nAll good. The run reached its end: one list of choices everywhere, and the upgrade changes only what it says.\n";
exit(0);
