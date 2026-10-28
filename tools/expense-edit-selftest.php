<?php
// Path: tools/expense-edit-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A claim may be changed only while nobody has decided on it — self-test ✏️ (#651)
 * -----------------------------------------------------------------------------
 * `Portal\Core\ExpenseRules::whyNotEditable()` is the ONE place that decides
 * whether an expense claim may still be changed. The rule is the claimant
 * only, while the claim is still Pending, and only while NOBODY has recorded a
 * decision on it.
 *
 * THAT LAST PART IS THE WHOLE POINT, and it is why this test exists rather
 * than a one-line check on the status. In a department needing several
 * approvals a claim STAYS "Pending" after the first approval. If the status
 * alone decided, this sequence would work:
 *
 *   1. the claimant opens the edit form on a £20 claim;
 *   2. an approver approves it;
 *   3. the claimant saves it as £2,000.
 *
 * The approval row would then sit against £2,000 that nobody approved, and the
 * claim would be fully approved and payable. That is the oldest fraud in
 * expenses — get a small amount approved, then enlarge it. Section D walks
 * exactly that sequence against a real database, at the level of the rule.
 *
 * What is checked:
 *
 *   A. the rule itself, every row of its table written out by hand, including
 *      the "could not read the count" case, which must close the claim rather
 *      than open it;
 *   B. `approvalCount()` against a REAL database, with real approval rows;
 *   C. the pages, read as text with their comments stripped out: the handler
 *      asks the rule TWICE — once cheaply and once INSIDE its row lock — and
 *      the second ask really is after the lock and before anything is
 *      written; it never reads a posted total; and the form page asks the same
 *      rule, so it cannot offer an edit the handler would refuse;
 *   D. the fraud sequence above, walked against a real database — the rule
 *      asked on real rows with a real approval landing between the two asks.
 *      It does NOT load the handler (that needs a web server and a session),
 *      so it proves the RULE answers correctly at both moments; section C is
 *      what proves the handler still asks it and acts on the answer.
 *
 * WHY C READS THE PAGES AS TEXT
 * -----------------------------
 * Sections A, B and D exercise the RULE. They cannot tell you the handler
 * still calls it, still calls it in the right place, or still ignores the
 * posted total. Deleting the locked re-ask from the handler leaves A, B and D
 * all passing — that was measured, not assumed, and the planted faults for
 * this file include it. Comments are stripped first, so a call that has been
 * commented out never counts as a call.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_edit SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/expense-edit-selftest.php
 *
 *         The database must already be built from `web/_sql/full_schema.sql`:
 *
 *             docker exec -i p651-mysql mysql -uroot -proot selftest_edit \
 *                 < web/_sql/full_schema.sql
 *
 * Exit:   0 only when every check ran and passed; 1 otherwise, including when
 *         the number of checks is not the number expected.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/651
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 61;

const SITE_ID   = 916801;
const CLAIMANT  = 916802;
const OTHER     = 916803;
const APPROVER  = 916804;
const DEPT_ID   = 916805;
const CLAIM_OPEN    = 916811; // Pending, nobody has decided
const CLAIM_DECIDED = 916812; // Pending, but one approval recorded
const CLAIM_APPROVED = 916813; // fully approved
const CLAIM_FRAUD   = 916814; // the one section D walks the sequence on

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test writes rows, so it only ever runs on a throwaway database. Nothing was checked.\n";
    exit(1);
}

$failures = 0;
$passes   = 0;

/** ✅ Report one check. */
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

/** A page's PHP with its comments removed, so a commented-out call is never counted. '' if missing. */
/**
 * A page's PHP with comments AND the inline HTML removed.
 *
 * WHY THIS EXISTS, because it is a trap worth naming. `codeOnly()` keeps the
 * inline HTML on purpose — check C20 searches it for a `<script` tag, so a
 * helper that threw the HTML away would make that check unable to fail. But
 * keeping it meant a check looking for a CALL could be satisfied by an HTML
 * COMMENT that merely mentions the call: an independent check on 28 October
 * 2026 planted a claim page whose button was decided by the status alone, with
 * no call to the rule anywhere in its PHP, and this file reported 53 of 53
 * passed — because the explanatory HTML comment beside the button contains the
 * words `ExpenseRules::whyNotEditable()`.
 *
 * So there are two helpers, deliberately: `codeOnly()` for "what does this page
 * send to the browser", and this one for "what does this page DO".
 */
function phpOnly(string $file): string
{
    if (is_file($file) === false) {
        return '';
    }
    $out = '';
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) === true) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT || $token[0] === T_INLINE_HTML) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

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

$root = dirname(__DIR__);
$web  = $root . DIRECTORY_SEPARATOR . 'web';
$sep  = DIRECTORY_SEPARATOR;

require_once $web . $sep . '_core' . $sep . 'ExpenseRules.php';

use Portal\Core\ExpenseRules;

// -----------------------------------------------------------------------------
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
if ($db->connect_errno !== 0) {
    echo "REFUSED — could not connect to '{$dbName}': " . $db->connect_error . "\n";
    exit(1);
}

$tables = $db->query(
    "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
    . "AND TABLE_NAME IN ('tblExpenseClaims','tblExpenseClaimApprovals','tblUsers','tblDepts','tblSites')"
);
$tableRow = $tables !== false ? $tables->fetch_assoc() : null;
if ($tableRow === null || (int) $tableRow['n'] !== 5) {
    echo "REFUSED — this database does not hold the expense tables, so it was not built from\n";
    echo "web/_sql/full_schema.sql. Load the schema and run again. Nothing was checked.\n";
    exit(1);
}

/** 🧰 Run a statement, stopping the run on a real error. */
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

echo "A claim may be changed only while nobody has decided on it (#651)\n";
echo "Database: {$dbName}\n";

// =============================================================================
head('Section A — the rule itself, written out by hand');
// =============================================================================

// 😀 The one case that says yes.
check(
    'A1. the claimant, a Pending claim, nobody has decided: allowed',
    ExpenseRules::whyNotEditable('Pending', 0, CLAIMANT, CLAIMANT) === ExpenseRules::EDIT_ALLOWED
);

// 🧾 One decision closes it, EVEN THOUGH the claim is still Pending. This is
//    the check that would fail if somebody "simplified" the rule to the status.
check(
    'A2. still Pending but ONE approval recorded: refused as already decided',
    ExpenseRules::whyNotEditable('Pending', 1, CLAIMANT, CLAIMANT) === ExpenseRules::EDIT_ALREADY_DECIDED,
    'this is the fraud shape — a part-approved claim stays Pending'
);
check(
    'A3. several decisions recorded: still refused',
    ExpenseRules::whyNotEditable('Pending', 3, CLAIMANT, CLAIMANT) === ExpenseRules::EDIT_ALREADY_DECIDED
);

// 🛟 The count could not be read. This MUST close the claim, not open it.
check(
    'A4. the approval count could not be read (-1): refused, NOT allowed',
    ExpenseRules::whyNotEditable('Pending', -1, CLAIMANT, CLAIMANT) === ExpenseRules::EDIT_ALREADY_DECIDED,
    '"we could not tell whether anybody has decided" must never become "nobody has, go ahead"'
);

// 📊 Every other status refuses, and each is named so a new status cannot be
//    added silently without somebody deciding what it means here.
foreach (['Approved', 'Rejected', 'Reimbursed', 'Withdrawn'] as $i => $status) {
    check(
        'A' . (5 + $i) . '. a ' . $status . ' claim cannot be changed',
        ExpenseRules::whyNotEditable($status, 0, CLAIMANT, CLAIMANT) === ExpenseRules::EDIT_NOT_PENDING
    );
}

// 🙅 Ownership, and that it is answered FIRST.
check(
    'A9. somebody else\'s Pending claim: refused as not theirs',
    ExpenseRules::whyNotEditable('Pending', 0, CLAIMANT, OTHER) === ExpenseRules::EDIT_NOT_YOURS
);
check(
    'A10. ownership is answered BEFORE the status, so a stranger learns nothing about the claim',
    ExpenseRules::whyNotEditable('Reimbursed', 5, CLAIMANT, OTHER) === ExpenseRules::EDIT_NOT_YOURS,
    'a stranger was told the claim had been decided — that confirms it exists and says where it got to'
);
check(
    'A11. "nobody" (account 0) owns nothing and may change nothing',
    ExpenseRules::whyNotEditable('Pending', 0, CLAIMANT, 0) === ExpenseRules::EDIT_NOT_YOURS
);
check(
    'A12. a claim with no owner is not editable by account 0 either',
    ExpenseRules::whyNotEditable('Pending', 0, 0, 0) === ExpenseRules::EDIT_NOT_YOURS,
    '"nobody\'s claim is everybody\'s own" would be the wrong way for a bug to fail'
);

// 💬 The words. Each reason has its own, and an unknown reason gets the
//    cautious one rather than an empty string.
check(
    'A13. "not yours" has its own sentence',
    ExpenseRules::editRefusalWords(ExpenseRules::EDIT_NOT_YOURS) === ExpenseRules::MSG_EDIT_NOT_YOURS
);
check(
    'A14. "not pending" has its own sentence',
    ExpenseRules::editRefusalWords(ExpenseRules::EDIT_NOT_PENDING) === ExpenseRules::MSG_EDIT_NOT_PENDING
);
check(
    'A15. "already decided" has its own sentence',
    ExpenseRules::editRefusalWords(ExpenseRules::EDIT_ALREADY_DECIDED) === ExpenseRules::MSG_EDIT_ALREADY_DECIDED
);
check(
    'A16. an unknown reason gets a real sentence, never a blank one',
    ExpenseRules::editRefusalWords('something-new-nobody-worded') !== ''
);
check(
    'A17. the "already decided" sentence tells the person what to do instead',
    str_contains(ExpenseRules::MSG_EDIT_ALREADY_DECIDED, 'withdraw') === true,
    '"no" on its own leaves somebody stuck with a claim they can see is wrong'
);

// =============================================================================
head('Section B — the approval count, against a real database');
// =============================================================================

$db->query('SET FOREIGN_KEY_CHECKS = 0');
foreach (['tblExpenseClaimApprovals', 'tblExpenseClaimItems', 'tblExpenseClaims', 'tblDepts', 'tblUsers', 'tblSites'] as $t) {
    $db->query('DELETE FROM `' . $t . '` WHERE 1=1');
}
$db->query('SET FOREIGN_KEY_CHECKS = 1');

q($db, 'INSERT INTO tblSites (siteID, siteName, siteKey) VALUES (?, ?, ?)', 'iss', [SITE_ID, 'Edit Org', 'editorg']);
foreach ([CLAIMANT => 'The Claimant', OTHER => 'Someone Else', APPROVER => 'An Approver'] as $id => $name) {
    q(
        $db,
        'INSERT INTO tblUsers (userID, fullName, emailAddress) VALUES (?, ?, ?)',
        'iss',
        [$id, $name, 'u' . $id . '@example.invalid']
    );
}
q($db, 'INSERT INTO tblDepts (deptID, siteID, deptName, isActive) VALUES (?, ?, ?, 1)', 'iis', [DEPT_ID, SITE_ID, 'Edit Dept']);

$makeClaim = static function (mysqli $db, int $claimId, string $status, string $title) : void {
    q(
        $db,
        'INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status) '
        . 'VALUES (?, ?, ?, ?, ?, CURDATE(), 20.00, ?)',
        'iiiiss',
        [$claimId, SITE_ID, CLAIMANT, DEPT_ID, $title, $status]
    );
};
$makeClaim($db, CLAIM_OPEN, 'Pending', 'Nobody has decided this one');
$makeClaim($db, CLAIM_DECIDED, 'Pending', 'One approver has decided this one');
$makeClaim($db, CLAIM_APPROVED, 'Approved', 'This one is fully approved');
$makeClaim($db, CLAIM_FRAUD, 'Pending', 'The twenty pound claim');

$approve = static function (mysqli $db, int $claimId, int $who, string $decision = 'Approved'): void {
    q(
        $db,
        'INSERT INTO tblExpenseClaimApprovals (claimID, userID, decision, approverRole) VALUES (?, ?, ?, ?)',
        'iiss',
        [$claimId, $who, $decision, 'dept_approver']
    );
};
$approve($db, CLAIM_DECIDED, APPROVER);

check(
    'B1. a claim nobody has decided counts 0',
    ExpenseRules::approvalCount($db, CLAIM_OPEN) === 0
);
check(
    'B2. a claim with one decision counts 1',
    ExpenseRules::approvalCount($db, CLAIM_DECIDED) === 1,
    'got ' . ExpenseRules::approvalCount($db, CLAIM_DECIDED)
);

$approve($db, CLAIM_DECIDED, OTHER, 'Rejected');
check(
    'B3. a REJECTION counts too — any decision closes the claim to changes',
    ExpenseRules::approvalCount($db, CLAIM_DECIDED) === 2,
    'got ' . ExpenseRules::approvalCount($db, CLAIM_DECIDED)
);

// 🧩 The two put together: this is what the pages actually ask.
check(
    'B4. the open claim really is editable by its claimant',
    ExpenseRules::whyNotEditable('Pending', ExpenseRules::approvalCount($db, CLAIM_OPEN), CLAIMANT, CLAIMANT)
        === ExpenseRules::EDIT_ALLOWED
);
check(
    'B5. the part-decided claim really is not, though it is still Pending',
    ExpenseRules::whyNotEditable('Pending', ExpenseRules::approvalCount($db, CLAIM_DECIDED), CLAIMANT, CLAIMANT)
        === ExpenseRules::EDIT_ALREADY_DECIDED
);

// =============================================================================
head('Section C — the pages still ask the rule, in the right places');
// =============================================================================

$save  = codeOnly($web . $sep . '_apps' . $sep . 'expenses' . $sep . 'edit' . $sep . 'save.php');
$form  = codeOnly($web . $sep . '_apps' . $sep . 'expenses' . $sep . 'edit' . $sep . 'index.php');
$claimPage = codeOnly($web . $sep . '_apps' . $sep . 'expenses' . $sep . 'view' . $sep . 'index.php');

$saveTight = (string) preg_replace('/\s+/', '', $save);
$formTight = (string) preg_replace('/\s+/', '', $form);
$pageTight = (string) preg_replace('/\s+/', '', $claimPage);

// 🧠 The same three pages with their inline HTML removed as well, for every
//    check that asks "does this page DO this" rather than "does this page SEND
//    this". See phpOnly() for the fault that made this necessary.
$savePhp  = (string) preg_replace('/\s+/', '', phpOnly($web . $sep . '_apps' . $sep . 'expenses' . $sep . 'edit' . $sep . 'save.php'));
$formPhp  = (string) preg_replace('/\s+/', '', phpOnly($web . $sep . '_apps' . $sep . 'expenses' . $sep . 'edit' . $sep . 'index.php'));
$pagePhp  = (string) preg_replace('/\s+/', '', phpOnly($web . $sep . '_apps' . $sep . 'expenses' . $sep . 'view' . $sep . 'index.php'));

check('C1. the save handler is readable', $saveTight !== '', 'web/_apps/expenses/edit/save.php missing or empty');
check('C2. the form page is readable', $formTight !== '', 'web/_apps/expenses/edit/index.php missing or empty');

// 🧠 It asks the ONE rule, and TWICE: once cheaply, once under the lock.
$askCount = substr_count($saveTight, 'ExpenseRules::whyNotEditable(');
check(
    'C3. the handler asks ExpenseRules::whyNotEditable TWICE — once cheaply, once under the lock',
    $askCount === 2,
    'found ' . $askCount . ' calls; one alone means an approval landing mid-edit is not noticed'
);

// 📍 And the second ask is after the lock and before anything is written.
$lockAt = strpos($saveTight, 'FORUPDATE');
$firstAsk = strpos($saveTight, 'ExpenseRules::whyNotEditable(');
$secondAsk = $firstAsk === false ? false : strpos($saveTight, 'ExpenseRules::whyNotEditable(', $firstAsk + 1);
$firstWrite = min(
    array_filter([
        strpos($saveTight, 'UPDATEtblExpenseClaimsSET'),
        strpos($saveTight, 'DELETEFROMtblExpenseClaimItems'),
    ], static fn ($v): bool => $v !== false) ?: [PHP_INT_MAX]
);

check(
    'C4. the handler takes a row lock on the claim',
    $lockAt !== false,
    'no FOR UPDATE — an approval landing mid-edit would not be noticed'
);
check(
    'C5. the second ask comes AFTER the lock',
    $lockAt !== false && $secondAsk !== false && $secondAsk > $lockAt,
    'lock at ' . var_export($lockAt, true) . ', second ask at ' . var_export($secondAsk, true)
);
check(
    'C6. and BEFORE anything is written',
    $secondAsk !== false && $firstWrite !== PHP_INT_MAX && $secondAsk < $firstWrite,
    'second ask at ' . var_export($secondAsk, true) . ', first write at ' . var_export($firstWrite, true)
);
check(
    'C7. the lock is taken before anything is written too',
    $lockAt !== false && $firstWrite !== PHP_INT_MAX && $lockAt < $firstWrite
);

// 🙅 No second copy of the rule. A handler that tests the status itself can
//    drift away from ExpenseRules without anything noticing.
check(
    'C8. the handler does not carry its own copy of the status test',
    preg_match('/\$(locked|claim)\[[\'"]status[\'"]\]\s*[!=]==?\s*[\'"]Pending[\'"]/', $saveTight) !== 1,
    'a second status test here is how two places start to disagree'
);

// 💰 The posted total is never read — the whole point of recalculating.
check(
    'C9. the handler never reads a posted total',
    str_contains($saveTight, "\$_POST['totalAmount']") === false
        && str_contains($saveTight, '$_POST["totalAmount"]') === false,
    'a posted total is a number the sender chose'
);
check(
    'C10. it works the total out from the lines instead',
    str_contains($saveTight, '$total+=$line') === true || str_contains($saveTight, '$total=round($total,2)') === true
);

// 🔢 The type-confusion guards the other money handlers carry (#642).
check(
    'C11. the claim number must be a single value, not a list',
    str_contains($saveTight, 'is_scalar($claimIdRaw)===true?(int)$claimIdRaw:0') === true
);
check(
    'C12. the title is text or it is nothing',
    str_contains($saveTight, 'is_string($titleRaw)===true?trim($titleRaw):\'\'') === true
);

// 🧷 The guarded header update.
check(
    'C13. the header update will only move a claim that is still Pending',
    str_contains($saveTight, 'WHEREclaimID=?ANDsiteID=?ANDstatus=?') === true
);

// 🧾 Per-line validation, which the submit page still lacks (#650 item 4).
check(
    'C14. a negative unit price is refused',
    str_contains($saveTight, '(float)$unitEntry<0') === true
);
check(
    'C15. a quantity below 1 is refused',
    str_contains($saveTight, '$qty<1') === true
);

// 🗂️ The trail.
check(
    'C16. the old values are written to the audit log',
    str_contains($saveTight, 'Logger::audit(') === true
);
check(
    'C17. the old LINES are captured too, not just the header',
    str_contains($saveTight, '$oldRecord[\'items\']=$oldItems') === true,
    'a trail that records that something changed without recording what is not a trail'
);

// 🖥️ The form page asks the same rule, so the two cannot disagree.
check(
    'C18. the form page asks the same rule',
    str_contains($formTight, 'ExpenseRules::whyNotEditable(') === true
);
check(
    'C19. the form page gets its refusal words from the same place',
    str_contains($formTight, 'ExpenseRules::editRefusalWords(') === true
);

// 🧨 The nonce trap (#625): no inline script on the form page at all.
check(
    'C20. the form page has no inline script, so there is no nonce to forget',
    stripos($form, '<script') === false,
    'an inline script without the answer\'s nonce is silently refused and the page looks finished'
);
check(
    'C21. and no element handlers either',
    preg_match('/\son(click|change|submit|input)\s*=/i', $form) !== 1
);

// 🔗 The claim page offers the button through the same rule.
// 📌 The ASSIGNMENT, not merely a mention. C22 used to search $pageTight for
//    'ExpenseRules::whyNotEditable(' and passed on the HTML comment beside the
//    button (see phpOnly()). It now requires the page to compute $mayEditClaim
//    FROM the rule, in its PHP, which a mention in prose cannot satisfy.
check(
    'C22. the claim page computes its Change button FROM the rule, in code',
    str_contains($pagePhp, '$mayEditClaim=(ExpenseRules::whyNotEditable(') === true,
    'the button must be decided by the rule, not by the status alone'
);
check(
    'C22b. and the rule is really called in the page\'s PHP, not just named in a comment',
    str_contains($pagePhp, 'ExpenseRules::whyNotEditable(') === true
);
check(
    'C23. the claim page shows the button only when that rule allows it',
    str_contains($pageTight, '$mayEditClaim===true') === true
);

// 🛣️ The addresses are seeded, in BOTH places, and they agree.
$schema = (string) file_get_contents($web . $sep . '_sql' . $sep . 'full_schema.sql');
$migration = (string) file_get_contents($web . $sep . '_sql' . $sep . '223_expenses_edit_routes.sql');
foreach (['expenses/edit', 'expenses/edit/save'] as $i => $route) {
    check(
        'C' . (24 + $i) . '. the address ' . $route . ' is seeded in the install script AND the migration',
        str_contains($schema, "'" . $route . "'") === true
            && str_contains($migration, "'" . $route . "'") === true
    );
}
// ♻️ Safe to run twice: EVERY insert must carry its own ON DUPLICATE KEY
//    UPDATE. Each insert is looked at separately, and the SQL comments are
//    stripped off first.
//
//    WHAT WAS WRONG WITH THIS CHECK, found by planting the fault it is meant
//    to catch. It used to be `substr_count($migration, 'ON DUPLICATE KEY
//    UPDATE') >= 3`. Removing the clause from BOTH route inserts left the
//    count at 3 and the check passed — because the migration's own header
//    comment mentions the phrase three times in prose ("Both inserts use ON
//    DUPLICATE KEY UPDATE…", "Plain INSERT … ON DUPLICATE KEY UPDATE only").
//    The check was reading the explanation instead of the SQL. A check that
//    passes on broken code is worse than no check, because it reads as proof.
$migrationSql = (string) preg_replace('/^\s*--.*$/m', '', $migration);
$insertParts  = preg_split('/INSERT\s+INTO/i', $migrationSql);
$insertCount  = is_array($insertParts) === true ? count($insertParts) - 1 : 0;
$guardedCount = 0;
if (is_array($insertParts) === true) {
    foreach (array_slice($insertParts, 1) as $part) {
        // Only this statement's own text, up to its terminating semicolon.
        $statement = substr($part, 0, (strpos($part, ';') !== false ? (int) strpos($part, ';') + 1 : strlen($part)));
        if (stripos($statement, 'ON DUPLICATE KEY UPDATE') !== false) {
            $guardedCount++;
        }
    }
}
check(
    'C26. the migration holds the three inserts it should',
    $insertCount === 3,
    'found ' . $insertCount . ' (two addresses and the self-record)'
);
check(
    'C27. and EVERY one of them is safe to run twice',
    $insertCount > 0 && $guardedCount === $insertCount,
    $guardedCount . ' of ' . $insertCount . ' inserts carry ON DUPLICATE KEY UPDATE in their own statement'
);

// 🚪 THE ANSWER MUST BE ACTED ON, NOT MERELY ASKED FOR.
//
//    WHY THESE EXIST. C3, C5 and C6 check that the rule is asked twice and
//    WHERE the second ask sits. They do not check that anything happens as a
//    result. An independent check on 28 October 2026 kept the locked ask
//    exactly where it is and replaced its refusal block with
//    `unset($lockedReason);` — so the handler saved a claim an approver had
//    just approved, which is the £20 → £2,000 sequence this file's own header
//    calls "the whole point" — and this file reported 53 of 53 passed.
//
//    The payment race test had already got this right (its B6), and the recipe
//    is the same: find the block, walk its braces to the matching close, and
//    look INSIDE it. Searching for "the next $refuse( anywhere in the file"
//    would find a different block's and prove nothing.
/**
 * The body of the `if (…) { … }` whose condition text is given, braces matched.
 *
 * Returns '' when the block cannot be found or cannot be bounded, and every
 * caller treats '' as a failure rather than as "nothing to check".
 *
 * IT SKIPS QUOTED TEXT, and that is not a nicety. The first version counted
 * raw `{` and `}` characters. `phpOnly()` removes comments but KEEPS string
 * literals, so a brace inside a string was counted as code. A round-2
 * independent check on 28 October 2026 measured what that costs: with a log
 * line holding `'refused {'` inside the block, the "body" came back as 2,432
 * characters instead of about 80 — it had swallowed the rest of the `try`
 * block, including the UPDATE, the item rewrite, the PDF and the commit. On
 * its own that produced no false pass, because nothing later in the file held
 * `$refuse(`. The checker then added a second, entirely plausible `$refuse(`
 * at step 4, and **all 59 checks passed with the fraud gate removed.** A
 * brace in a string and a second refusal are both ordinary things a later
 * edit adds, so this had to be fixed properly rather than relied on.
 *
 * A closing quote is found honouring backslash escapes, so `'it\'s {'` does
 * not end the string early. Heredocs are not handled; there are none in these
 * files, and a body longer than the sanity bound is reported as not found
 * rather than searched, so an unhandled shape fails safe.
 */
$blockBody = static function (string $haystack, string $conditionText): string {
    /** Longer than any refusal block in these handlers; see the note above. */
    $sanityBound = 600;

    $at = strpos($haystack, $conditionText);
    if ($at === false) {
        return '';
    }
    $open = strpos($haystack, '{', $at);
    if ($open === false) {
        return '';
    }

    $depth = 0;
    for ($i = (int) $open, $len = strlen($haystack); $i < $len; $i++) {
        $ch = $haystack[$i];

        // 🧵 Step over a quoted run in one go, so nothing inside it counts.
        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            for ($i++; $i < $len; $i++) {
                if ($haystack[$i] === '\\') {
                    $i++;
                    continue;
                }
                if ($haystack[$i] === $quote) {
                    break;
                }
            }
            if ($i >= $len) {
                // An unterminated string: say "not found" rather than guess.
                return '';
            }
            continue;
        }

        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $body = substr($haystack, (int) $open, $i - (int) $open + 1);

                // 📏 A body far larger than any refusal block means the walk
                //    went wrong. Report nothing found; searching it could
                //    find another block's statements and pass on them.
                return strlen($body) > $sanityBound ? '' : $body;
            }
        }
    }

    return '';
};

$earlyBlock  = $blockBody($savePhp, 'if($earlyReason!==ExpenseRules::EDIT_ALLOWED){');
$lockedBlock = $blockBody($savePhp, 'if($lockedReason!==ExpenseRules::EDIT_ALLOWED){');

check(
    'C28. the FIRST ask\'s answer is acted on — its block refuses and stops',
    $earlyBlock !== '' && str_contains($earlyBlock, '$refuse(') === true,
    'block found: ' . ($earlyBlock === '' ? 'no' : 'yes') . ' — ' . substr($earlyBlock, 0, 140)
);
check(
    'C29. the LOCKED ask\'s answer is acted on — its block rolls back AND refuses',
    $lockedBlock !== ''
        && str_contains($lockedBlock, 'rollback()') === true
        && str_contains($lockedBlock, '$refuse(') === true,
    'block found: ' . ($lockedBlock === '' ? 'no' : 'yes') . ' — ' . substr($lockedBlock, 0, 140)
);
check(
    'C30. and the refusal uses the words from the one place, not its own sentence',
    $lockedBlock !== '' && str_contains($lockedBlock, 'ExpenseRules::editRefusalWords(') === true
);

// 🏢 ORGANISATION SCOPING. The shipped code is right everywhere, but nothing
//    checked it: the same independent check removed `AND siteID = ?` from both
//    the lookup and the lock and all 53 checks still passed. A claim belonging
//    to another organisation could then be read and changed.
// 📌 EACH STATEMENT NAMED SEPARATELY, not counted.
//
//    WHAT WAS WRONG. This was
//    `substr_count($savePhp, 'WHEREclaimID=?ANDsiteID=?') >= 2`. There are
//    THREE statements whose WHERE begins with that text — the early lookup
//    (`LIMIT 1`), the lock (`FOR UPDATE`) and the header UPDATE
//    (`AND status = ?`) — so removing the scoping from either READ left the
//    count at 2 and the check passed while asserting, in its own PASS line,
//    that both reads were scoped. A round-2 independent check removed it from
//    the lock alone and all 59 checks passed. Counting cannot tell statements
//    apart; naming them can.
check(
    'C31. the early lookup is scoped to this organisation',
    str_contains($savePhp, 'WHEREclaimID=?ANDsiteID=?LIMIT1') === true,
    'another organisation\'s claim could be read'
);
check(
    'C31b. and so is the lock',
    str_contains($savePhp, 'WHEREclaimID=?ANDsiteID=?FORUPDATE') === true,
    'another organisation\'s claim could be locked and changed'
);
check(
    'C31c. and so is the header update',
    str_contains($savePhp, 'WHEREclaimID=?ANDsiteID=?ANDstatus=?') === true,
    'another organisation\'s claim could be written'
);
check(
    'C32. the department must belong to this organisation and be live',
    str_contains($savePhp, 'WHEREdeptID=?ANDsiteID=?ANDisActive=1') === true,
    'otherwise a claim could be moved onto another organisation\'s department'
);

// =============================================================================
head('Section D — the fraud sequence, walked on real rows (the rule, not the handler)');
// =============================================================================
// 1. the claimant opens the edit form on a £20 claim  → allowed
// 2. an approver approves it                          → a row lands
// 3. the claimant saves                               → MUST be refused

$openedWith = ExpenseRules::whyNotEditable(
    'Pending',
    ExpenseRules::approvalCount($db, CLAIM_FRAUD),
    CLAIMANT,
    CLAIMANT
);
check(
    'D1. the claimant opens the edit form on the £20 claim and is allowed in',
    $openedWith === ExpenseRules::EDIT_ALLOWED
);

// 🕐 … and while the form is open, an approver approves it.
$approve($db, CLAIM_FRAUD, APPROVER);

// 🔒 This is the second ask, on facts read inside the handler's lock.
$lockRow = q($db, 'SELECT userID, status FROM tblExpenseClaims WHERE claimID = ?', 'i', [CLAIM_FRAUD])
    ->get_result()->fetch_assoc();
$savedWith = ExpenseRules::whyNotEditable(
    (string) $lockRow['status'],
    ExpenseRules::approvalCount($db, CLAIM_FRAUD),
    (int) $lockRow['userID'],
    CLAIMANT
);

check(
    'D2. the claim is STILL Pending, so the status alone would have let the edit through',
    $lockRow['status'] === 'Pending',
    'this is why the rule counts decisions rather than reading the status'
);
check(
    'D3. but the save is refused, because an approval has landed',
    $savedWith === ExpenseRules::EDIT_ALREADY_DECIDED,
    'got ' . var_export($savedWith, true) . ' — the £20 claim could have become £2,000'
);
check(
    'D4. and the person is told what to do instead',
    ExpenseRules::editRefusalWords($savedWith) === ExpenseRules::MSG_EDIT_ALREADY_DECIDED
);

// -----------------------------------------------------------------------------
printf("\n%d passed, %d failed, %d ran of %d expected\n", $passes, $failures, $passes + $failures, EXPECTED_CHECKS);

if (($passes + $failures) !== EXPECTED_CHECKS) {
    echo "REFUSED — the number of checks is not the number expected, so a block was skipped or ran twice.\n";
    exit(1);
}

exit($failures === 0 ? 0 : 1);
