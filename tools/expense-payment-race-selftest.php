<?php
// Path: tools/expense-payment-race-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A claim cannot be paid twice — self-test 💷 (#647)
 * -----------------------------------------------------------------------------
 * `web/_apps/expenses/treasury/save.php` used to read a claim's status, then
 * pay it, with nothing holding the claim still in between. Two treasurers
 * submitting for the same claim — or one person clicking twice because the
 * first answer was slow — both saw "Approved", both wrote their own payment
 * reference, both set the claim to "Reimbursed", and both sent the claimant a
 * "you have been reimbursed" e-mail.
 *
 * This file checks the fix in the only two ways that catch it, because
 * NEITHER CATCHES THE OTHER'S HALF:
 *
 *   Section A — the BEHAVIOUR, against a real database, with two real
 *   connections racing. This is what proves the row lock actually serialises
 *   two payment requests, and that the loser finds the claim already paid.
 *
 *   Section B — the SHAPE of the handler, read as text with its comments
 *   stripped out. Section A exercises the statements the handler uses, not the
 *   handler itself (see the limit below), so if somebody deleted the lock from
 *   the page tomorrow, section A would still pass. Section B is what notices.
 *
 * WHY THIS IS NOT AN HTTP TEST, WHICH IS WHERE IT BELONGS
 * ------------------------------------------------------
 * `tools/access-pages-selftest.php` drives the real pages over real HTTP and
 * already has a treasurer signed in, so the obvious home for this is there.
 * It cannot go there, and the reason is worth writing down so nobody wastes an
 * afternoon on it: that harness starts PHP's built-in web server and
 * deliberately does `unset($env['PHP_CLI_SERVER_WORKERS'])`, so the server
 * handles ONE request at a time. Two payment requests fired at it can never
 * overlap — the second does not begin until the first has finished and the
 * claim already says "Reimbursed". A race test built on that harness would
 * pass on the BROKEN code, which is worse than having no test, because it
 * would read like proof.
 *
 * WHAT SECTION A DOES AND DOES NOT PROVE — read this before trusting it
 * --------------------------------------------------------------------
 * It runs the handler's own statements, in the handler's own order, from two
 * connections, against a real database. So it proves the MECHANISM: that
 * `SELECT … FOR UPDATE` makes the second request wait, and that when the wait
 * ends the status has moved, so the second request's own condition turns it
 * away before it writes anything.
 *
 * It does NOT load the page. It cannot, without a web server and a session.
 * So it cannot tell you the page still contains those statements. That is
 * section B's whole job, and it is why both sections exist.
 *
 * ONE MORE HONEST LIMIT, ABOUT THE GUARDED UPDATE
 * -----------------------------------------------
 * The fix also added `AND status = 'Approved'` to the status UPDATE. Section A
 * checks it, but its practical value is smaller than it looks, and the comment
 * in the handler says so rather than implying otherwise: the only status an
 * Approved claim moves to through this page is "Reimbursed", and MySQL reports
 * zero rows changed when an UPDATE sets a column to the value it already
 * holds. So on the exact double-payment path the guard and no guard look the
 * same from `affected_rows`. The protection that really stops the second
 * payment is the LOCK plus the status re-check, because they stop the handler
 * before it reaches its payment INSERT at all — and the payment INSERT is the
 * harm. The guard is defence in depth and a tripwire for a state that should
 * be impossible, not the thing doing the work.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_payrace SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/expense-payment-race-selftest.php
 *
 *         The database must already be built from `web/_sql/full_schema.sql`,
 *         the same way the other self-tests here expect it. For example:
 *
 *             docker exec -i p647-mysql mysql -uroot -proot selftest_payrace \
 *                 < web/_sql/full_schema.sql
 *
 *         Other settings: SELFTEST_DB_HOST (default 127.0.0.1),
 *         SELFTEST_DB_USER (default root), SELFTEST_DB_PASS (default empty).
 *
 * Exit:   0 only when every check ran and passed; 1 otherwise, including when
 *         the number of checks is not the number expected.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/647
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 28;

// 🔢 Row numbers of our own, high enough not to meet anything a schema seeds.
const SITE_ID    = 916901;
const CLAIMANT   = 916902;
const TREASURER  = 916903;
const TREASURER2 = 916904;
const DEPT_ID    = 916905;
const CLAIM_RACE = 916911; // the claim two treasurers both try to pay
const CLAIM_PLAIN = 916912; // the control: one treasurer, no race

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test writes rows, so it only ever runs on a throwaway database. Nothing was checked.\n";
    exit(1);
}

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

/** 🏷️ A section heading. */
function head(string $text): void
{
    echo "\n" . $text . "\n";
}

/**
 * 🔌 One connection to the throwaway database.
 *
 * Each racer needs its OWN connection: a transaction and its row locks belong
 * to a connection, so two requests cannot be imitated on one.
 */
function connect(string $dbName): mysqli
{
    $host = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
    $user = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
    $pass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
    $port = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = new mysqli($host, $user, $pass, $dbName, $port);
    if ($conn->connect_errno !== 0) {
        echo "REFUSED — could not connect to '{$dbName}': " . $conn->connect_error . "\n";
        exit(1);
    }

    return $conn;
}

/**
 * 🧰 Run a statement with values, and stop the run on a real error.
 *
 * @param list<mixed> $values
 */
function q(mysqli $db, string $sql, string $types = '', array $values = []): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        echo "REFUSED — could not prepare: {$sql}\n          " . $db->error . "\n";
        exit(1);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    if ($stmt->execute() === false) {
        echo "REFUSED — could not run: {$sql}\n          " . $stmt->error . "\n";
        exit(1);
    }

    return $stmt;
}

/** A page's PHP with its comments removed, so a commented-out statement is never counted. '' if missing. */
function codeOnly(string $file): string
{
    if (is_file($file) === false) {
        return '';
    }
    $out = '';
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) === true) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'ExpenseRules.php';

use Portal\Core\ExpenseRules;

$db = connect($dbName);

// 🛡️ Refuse a database that was not built from the real schema, rather than
//    creating a pretend table and checking a world of our own making.
$tableCheck = $db->query(
    "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
    . "AND TABLE_NAME IN ('tblExpenseClaims','tblExpenseClaimPayments','tblUsers','tblDepts','tblSites')"
);
$tableRow = $tableCheck !== false ? $tableCheck->fetch_assoc() : null;
if ($tableRow === null || (int) $tableRow['n'] !== 5) {
    echo "REFUSED — this database does not hold the expense tables, so it was not built from\n";
    echo "web/_sql/full_schema.sql. Load the schema and run again. Nothing was checked.\n";
    exit(1);
}

echo "A claim cannot be paid twice — self-test (#647)\n";
echo "Database: {$dbName}\n";

// -----------------------------------------------------------------------------
// 🌍 A small world: one organisation, a claimant, two treasurers, two claims
// -----------------------------------------------------------------------------
$db->query('SET FOREIGN_KEY_CHECKS = 0');
foreach (['tblExpenseClaimPayments', 'tblExpenseClaims', 'tblDepts', 'tblUsers', 'tblSites'] as $t) {
    $db->query('DELETE FROM `' . $t . '` WHERE 1=1');
}
$db->query('SET FOREIGN_KEY_CHECKS = 1');

q($db, 'INSERT INTO tblSites (siteID, siteName, siteKey) VALUES (?, ?, ?)', 'iss', [SITE_ID, 'Race Org', 'race']);
foreach ([CLAIMANT => 'Claimant', TREASURER => 'Treasurer One', TREASURER2 => 'Treasurer Two'] as $id => $name) {
    q(
        $db,
        // 📧 The column is `emailAddress`, NOT `email`. Worth a note: the first
        //    version of this file said `email`, and the strict connection the
        //    schema now demands turned that into a clear "Unknown column"
        //    rather than a silent miss. That is the repository's own
        //    "name things by the right identifier" trap.
        'INSERT INTO tblUsers (userID, fullName, emailAddress) VALUES (?, ?, ?)',
        'iss',
        [$id, $name, 'u' . $id . '@example.invalid']
    );
}
q($db, 'INSERT INTO tblDepts (deptID, siteID, deptName, isActive) VALUES (?, ?, ?, 1)', 'iis', [DEPT_ID, SITE_ID, 'Race Dept']);

/** Make an Approved claim ready to be paid. */
$makeApprovedClaim = static function (mysqli $db, int $claimId, string $title): void {
    q(
        $db,
        'INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status) '
        . "VALUES (?, ?, ?, ?, ?, CURDATE(), 25.00, 'Approved')",
        'iiiis',
        [$claimId, SITE_ID, CLAIMANT, DEPT_ID, $title]
    );
};
$makeApprovedClaim($db, CLAIM_RACE, 'The claim two treasurers both try to pay');
$makeApprovedClaim($db, CLAIM_PLAIN, 'The claim only one treasurer pays');

/** How many payment rows a claim carries. */
$paymentCount = static function (mysqli $db, int $claimId): int {
    $stmt = q($db, 'SELECT COUNT(*) AS n FROM tblExpenseClaimPayments WHERE claimID = ?', 'i', [$claimId]);
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['n'] ?? -1);
};

/** A claim's status. */
$statusOf = static function (mysqli $db, int $claimId): string {
    $stmt = q($db, 'SELECT status FROM tblExpenseClaims WHERE claimID = ?', 'i', [$claimId]);
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string) ($row['status'] ?? '');
};

// 🔒 The handler's own lock statement, word for word from treasury/save.php.
//    Written out here on purpose: section B checks the page still carries it,
//    and if the page's wording changes, that is the check that should fail,
//    not this one quietly testing something the page no longer does.
const LOCK_SQL = 'SELECT claimID, status FROM tblExpenseClaims WHERE claimID = ? AND siteID = ? FOR UPDATE';
const GUARDED_UPDATE_SQL = 'UPDATE tblExpenseClaims SET status = ?, updatedAt = NOW() '
    . 'WHERE claimID = ? AND siteID = ? AND status = ?';

// =============================================================================
head('Section A — two real connections race for one claim');
// =============================================================================

$treasurerA = connect($dbName);
$treasurerB = connect($dbName);

// ⏱️ Treasurer B waits only a moment for a lock, so this test finishes. A real
//    request waits as long as the server allows (50 seconds by default), which
//    is the right behaviour there: waiting and then finding the claim paid is
//    exactly what should happen.
$treasurerB->query('SET SESSION innodb_lock_wait_timeout = 1');

// ── Treasurer A arrives first and takes the claim ────────────────────────────
$treasurerA->begin_transaction();
$lockA = q($treasurerA, LOCK_SQL, 'ii', [CLAIM_RACE, SITE_ID]);
$rowA  = $lockA->get_result()->fetch_assoc();
$lockA->close();

check(
    'A1. treasurer A takes the claim and finds it waiting for payment',
    $rowA !== null && $rowA['status'] === 'Approved',
    'got ' . var_export($rowA['status'] ?? null, true)
);

// ── Treasurer B arrives while A still holds it ───────────────────────────────
//    This is the whole point. Before the fix there was no lock here, so B read
//    "Approved" straight away and went on to pay the same claim.
$lockB = $treasurerB->prepare(LOCK_SQL);
$claimRace = CLAIM_RACE;
$siteIdVal = SITE_ID;
$lockB->bind_param('ii', $claimRace, $siteIdVal);
$blocked = $lockB->execute();
$blockedError = $lockB->errno;
$lockB->close();

check(
    'A2. treasurer B is made to WAIT for the claim rather than reading it straight away',
    $blocked === false && $blockedError !== 0,
    'B acquired the row while A held it — the lock is not serialising the two requests'
);
check(
    'A3. …and the reason is a lock wait, not some other database fault',
    $blockedError === 1205 || $blockedError === 1213,
    'error ' . $blockedError . ' (expected 1205 lock wait timeout, or 1213 deadlock)'
);

// ── Treasurer A finishes paying ──────────────────────────────────────────────
$payA = q(
    $treasurerA,
    'INSERT INTO tblExpenseClaimPayments (claimID, payReference, paidByID) VALUES (?, ?, ?)',
    'isi',
    [CLAIM_RACE, 'BACS-A', TREASURER]
);
$payA->close();

$updA = $treasurerA->prepare(GUARDED_UPDATE_SQL);
$reimbursed = 'Reimbursed';
$approved   = 'Approved';
$updA->bind_param('siis', $reimbursed, $claimRace, $siteIdVal, $approved);
$updA->execute();
$changedA = $updA->affected_rows;
$updA->close();

check(
    'A4. treasurer A moves the claim to Reimbursed — exactly one row changes',
    $changedA === 1,
    $changedA . ' rows changed'
);

$treasurerA->commit();

// ── Treasurer B wakes up and must find the claim already paid ────────────────
$treasurerB->begin_transaction();
$lockB2 = q($treasurerB, LOCK_SQL, 'ii', [CLAIM_RACE, SITE_ID]);
$rowB   = $lockB2->get_result()->fetch_assoc();
$lockB2->close();

check(
    'A5. treasurer B now gets the claim, and it is no longer waiting for payment',
    $rowB !== null && $rowB['status'] === 'Reimbursed',
    'got ' . var_export($rowB['status'] ?? null, true)
);

// 🛡️ This is the condition the handler itself asks. If it is true, the handler
//    rolls back and tells the treasurer their reference was NOT saved — before
//    it reaches its payment INSERT. That return is what stops the second
//    payment; nothing after it runs.
$handlerWouldRefuse = ($rowB === null || $rowB['status'] !== 'Approved');
check(
    'A6. so the handler\'s own test turns treasurer B away BEFORE any payment is written',
    $handlerWouldRefuse === true
);

// 🧷 And the guard on the UPDATE, as a tripwire for a state that should not
//    happen. Honest note: see the header — on this exact path the new value
//    equals the stored one, so zero rows changed does not by itself prove the
//    guard is present. It is checked because a NON-zero answer here would mean
//    something is very wrong.
$updB = $treasurerB->prepare(GUARDED_UPDATE_SQL);
$updB->bind_param('siis', $reimbursed, $claimRace, $siteIdVal, $approved);
$updB->execute();
$changedB = $updB->affected_rows;
$updB->close();
check(
    'A7. the guarded update moves nothing for treasurer B',
    $changedB === 0,
    $changedB . ' rows changed'
);

$treasurerB->rollback();

// ── The end state is the only thing a claimant would ever see ───────────────
check(
    'A8. the claim carries exactly ONE payment record, not two',
    $paymentCount($db, CLAIM_RACE) === 1,
    $paymentCount($db, CLAIM_RACE) . ' payment rows — this is the fault itself if it is 2'
);
check(
    'A9. and the claim is Reimbursed once',
    $statusOf($db, CLAIM_RACE) === 'Reimbursed',
    'status ' . $statusOf($db, CLAIM_RACE)
);

// ── Control: the ordinary single payment must still work ────────────────────
$treasurerA->begin_transaction();
$lockC = q($treasurerA, LOCK_SQL, 'ii', [CLAIM_PLAIN, SITE_ID]);
$rowC  = $lockC->get_result()->fetch_assoc();
$lockC->close();
$payC = q(
    $treasurerA,
    'INSERT INTO tblExpenseClaimPayments (claimID, payReference, paidByID) VALUES (?, ?, ?)',
    'isi',
    [CLAIM_PLAIN, 'BACS-ONLY', TREASURER]
);
$payC->close();
$claimPlain = CLAIM_PLAIN;
$updC = $treasurerA->prepare(GUARDED_UPDATE_SQL);
$updC->bind_param('siis', $reimbursed, $claimPlain, $siteIdVal, $approved);
$updC->execute();
$changedC = $updC->affected_rows;
$updC->close();
$treasurerA->commit();

check(
    'A10. CONTROL: one treasurer paying one claim still works — one row changed',
    $rowC !== null && $rowC['status'] === 'Approved' && $changedC === 1,
    'status ' . var_export($rowC['status'] ?? null, true) . ', ' . $changedC . ' rows changed'
);
check(
    'A11. CONTROL: and that claim carries exactly one payment record',
    $paymentCount($db, CLAIM_PLAIN) === 1,
    $paymentCount($db, CLAIM_PLAIN) . ' payment rows'
);

$treasurerA->close();
$treasurerB->close();

// =============================================================================
head('Section B — the handler still contains what section A exercised');
// =============================================================================

$pay = codeOnly(
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps'
    . DIRECTORY_SEPARATOR . 'expenses' . DIRECTORY_SEPARATOR . 'treasury' . DIRECTORY_SEPARATOR . 'save.php'
);
$payTight = (string) preg_replace('/\s+/', '', $pay);

check(
    'B1. the payment handler is readable',
    $payTight !== '',
    'web/_apps/expenses/treasury/save.php is missing or empty'
);

// 🔒 The lock itself.
check(
    'B2. it locks the claim row with FOR UPDATE',
    str_contains($payTight, 'FORUPDATE') === true,
    'no FOR UPDATE anywhere — a second treasurer can read the claim while the first is paying it'
);

// 📍 WHERE the lock is matters as much as whether it is there. A lock taken
//    AFTER the payment insert protects nothing. So: the lock must come before
//    the first INSERT INTO tblExpenseClaimPayments in the file.
$lockAt   = strpos($payTight, 'FORUPDATE');
$insertAt = strpos($payTight, 'INSERTINTOtblExpenseClaimPayments');
check(
    'B3. the lock is taken BEFORE the payment is written, not after',
    $lockAt !== false && $insertAt !== false && $lockAt < $insertAt,
    'lock at ' . var_export($lockAt, true) . ', payment insert at ' . var_export($insertAt, true)
);

// 🛡️ The re-check. Without it the lock buys nothing: the handler would wait
//    politely for the claim and then pay it anyway.
check(
    'B4. it refuses when the locked claim is no longer Approved',
    preg_match('/\$lockedClaim\[\'status\'\]!==\'Approved\'/', $payTight) === 1,
    'the status is not re-read after the lock — waiting for the claim and then paying it regardless'
);

// 📍 And that refusal must come before the payment insert too.
$recheckAt = strpos($payTight, '$lockedClaim[\'status\']!==\'Approved\'');
check(
    'B5. that refusal comes before the payment is written',
    $recheckAt !== false && $insertAt !== false && $recheckAt < $insertAt,
    're-check at ' . var_export($recheckAt, true) . ', payment insert at ' . var_export($insertAt, true)
);

// 🚪 The refusal must actually leave. A re-check that sets a message and falls
//    through to the payment is the fault with a comment on top.
$refuseFrom = strpos($payTight, 'if($lockedClaim===null||$lockedClaim[\'status\']!==\'Approved\'){');
$refuseBody = '';
if ($refuseFrom !== false) {
    // Walk from the opening brace to its match, so "the next exit() anywhere in
    // the file" can never be mistaken for this block's own.
    $i     = strpos($payTight, '{', $refuseFrom);
    $depth = 0;
    for ($j = (int) $i, $len = strlen($payTight); $j < $len; $j++) {
        if ($payTight[$j] === '{') {
            $depth++;
        } elseif ($payTight[$j] === '}') {
            $depth--;
            if ($depth === 0) {
                $refuseBody = substr($payTight, (int) $i, $j - (int) $i + 1);
                break;
            }
        }
    }
}
check(
    'B6. the refusal rolls back and leaves — it does not fall through to the payment',
    $refuseBody !== ''
        && str_contains($refuseBody, 'rollback()') === true
        && str_contains($refuseBody, 'exit();') === true,
    'block found: ' . ($refuseBody === '' ? 'no' : 'yes') . ' — ' . substr($refuseBody, 0, 160)
);

// 🧷 The guarded UPDATE, and that a failure cannot commit half a payment.
check(
    'B7. the status update will only move a claim that is still Approved',
    str_contains($payTight, "WHEREclaimID=?ANDsiteID=?ANDstatus=?") === true,
    'the update has no status guard, so it moves the claim whatever it says'
);
check(
    'B8. a failed status update throws instead of committing the payment rows alone',
    preg_match('/if\(\$upd===false\)\{throw/', $payTight) === 1,
    'a prepare failure is still skipped silently — payments would commit with the claim left Approved'
);

// 🔢 THE TYPE GUARDS, which had no check of any kind until an independent
//    review on 28 October 2026 pointed that out. They were fixed in the same
//    commit as the race and shipped unexercised, which this project's own
//    step 2 ("run a real check that exercises the change") does not allow.
//
//    What they guard is in #642's family: a request chooses the TYPE of what it
//    sends as well as the value.
check(
    'B9. the comment is text or it is nothing',
    str_contains($payTight, 'is_string($commentsRaw)===true?trim($commentsRaw):\'\'') === true,
    'an array reaching trim() ends the request on the 500 page, and the treasurer cannot tell whether the payment was recorded'
);

// 🧾 THE REFERENCES ARE READ BY THE ONE PLACE, and these checks call THAT
//    place, not a copy of it.
//
//    WHAT WAS WRONG WITH THESE CHECKS. They used to exercise a copy of the
//    parsing lifted into this file. The round-2 check dropped `array_filter`
//    from the HANDLER only and all 25 checks still passed, because the copy
//    here was untouched — and that drift would have written an EMPTY payment
//    reference against a real claim (with `payRef` absent the handler then gets
//    `['']`, which is not `empty()`, so its "at least one reference" refusal
//    lets it through). The parsing moved into
//    `ExpenseRules::paymentReferences()`, the handler calls it, and so do the
//    behaviour checks below. A copy cannot drift if there is no copy.
check(
    'B10. the payment page reads its references through the one place, not its own copy',
    str_contains($payTight, 'ExpenseRules::paymentReferences($_POST[\'payRef\']??[])') === true,
    'a second copy of this parsing is how the handler and its test came to disagree'
);
// 🙅 No copy of the PARSING left behind. Narrowly worded on purpose: the
//    handler still uses `implode(', ', $refs)` to list the references in its
//    activity log, which is a perfectly good use and nothing to do with
//    reading them. The first version of this check looked for `implode(',',`
//    and failed on that log line — a check has to name the thing it means.
// 🧷 ASSIGNED EXACTLY ONCE, which closes a dead-store evasion.
//
//    The round-3 check found it and supplied this fix. B10 proves the handler
//    CALLS the one place and B11 proves it holds no copy of the parsing — but
//    a handler could call the method and then OVERWRITE `$refs` with a
//    differently-shaped inline parse, and both would still pass. That exact
//    shape (filter before trim) returns `['']` for `payRef[]=' '`, which is not
//    `empty()`, so a blank payment reference would be written against a real
//    claim.
//
//    It needs an unused assignment that no reader would let through, so it is
//    unlikely rather than impossible — and one line closes it, which is a
//    better trade than leaving a known hole in a money check.
check(
    'B11a. the references are assigned exactly once, so nothing overwrites them',
    substr_count($payTight, '$refs=') === 1,
    'found ' . substr_count($payTight, '$refs=') . ' assignments; a second one can undo the first'
);
check(
    'B11. and it keeps no copy of the parsing itself',
    preg_match('/array_filter\(array_map\(\'trim\',explode\(/', $payTight) !== 1,
    'the handler is assembling references itself again, so there are two copies of the rule'
);

// 🧪 The behaviour, exercised against the SHIPPED method.
check(
    'B12. payRef[][]=x yields NO reference, so the "at least one" refusal turns it away',
    ExpenseRules::paymentReferences([['x']]) === [],
    'got ' . json_encode(ExpenseRules::paymentReferences([['x']]))
        . ' — a reference of "Array" would be stored against a real claim'
);
check(
    'B13. payRef=abc (a plain field) yields one reference',
    ExpenseRules::paymentReferences('abc') === ['abc'],
    'got ' . json_encode(ExpenseRules::paymentReferences('abc'))
);
check(
    'B14. payRef[]=a,b still yields two references, so the guard refuses only what it should',
    ExpenseRules::paymentReferences(['a,b']) === ['a', 'b'],
    'got ' . json_encode(ExpenseRules::paymentReferences(['a,b']))
);
check(
    'B15. nothing sent at all yields NO reference — not one empty one',
    ExpenseRules::paymentReferences([]) === [] && ExpenseRules::paymentReferences(null) === [],
    'an empty reference is not empty() and would be written against a real claim: '
        . json_encode(ExpenseRules::paymentReferences([]))
);
// 📎 This also pins `array_values()`, so a failure here may be about the KEYS
//    rather than the values — no caller can tell the difference today, but the
//    method promises `list<string>` and that promise is worth keeping. The
//    message says so, because the round-3 check pointed out that somebody
//    seeing "got [...]" would otherwise hunt for a behaviour change that is
//    not there.
check(
    'B16. a blank and a real reference together keep only the real one, numbered from zero',
    ExpenseRules::paymentReferences(['', ' BACS-1 ']) === ['BACS-1'],
    'got ' . json_encode(ExpenseRules::paymentReferences(['', ' BACS-1 ']))
        . ' — if the values look right, check the KEYS: this pins list<string>, so array_values() matters'
);

// -----------------------------------------------------------------------------
printf("\n%d passed, %d failed, %d ran of %d expected\n", $passes, $failures, $passes + $failures, EXPECTED_CHECKS);

if (($passes + $failures) !== EXPECTED_CHECKS) {
    echo "REFUSED — the number of checks is not the number expected, so a block was skipped or ran twice.\n";
    exit(1);
}

exit($failures === 0 ? 0 : 1);
