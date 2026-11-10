<?php
// Path: tools/expense-rules-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Nobody decides or pays their own expense claim — self-test 💷 (#545)
 * -----------------------------------------------------------------------------
 * `Portal\Core\ExpenseRules` holds one rule: the person who made an expense
 * claim may not approve it, reject it or record it as paid, and is left off
 * the list of people whose approval the claim waits for. This file checks:
 *
 *   1. `isOwnClaim()`, every row of its small table written out by hand;
 *   2. `requiredApprovers()` against a REAL database: the claimant is left
 *      out; other leads and required approvers stay; a person whose
 *      membership of the organisation has ended is left out; another
 *      organisation's rows never count; a plain approver was never on the
 *      list; a retired department's approvers still are;
 *   3. the two refusal sentences, word for word;
 *   4. the pages, read as text with their comments removed: both save
 *      handlers ask `ExpenseRules::isOwnClaim(` before their first INSERT
 *      and before anything is written; the approval handler takes its list
 *      from `ExpenseRules::requiredApprovers(` and no longer carries a copy
 *      of the query; the three list queries leave the viewer's own claims
 *      out; the claim page offers neither button on a person's own claim;
 *      the e-mail that asks for a decision leaves the claimant out; the
 *      dashboard's number leaves the administrator's own claims out; and
 *      the "no authority at all" refusal is still exactly what it was, and
 *      still comes before any claim is looked up.
 *   5. whose claims a person may see (#648, D-103): the reach rule's pure
 *      parts by hand; its SQL form against real rows, required to pick
 *      exactly the claims its PHP form opens; and the claim page, the
 *      spreadsheet and the approvals list each asking it.
 *
 * WHY PART 4 READS THE PAGES AS TEXT
 * ----------------------------------
 * Parts 1 and 2 prove the rule. They would still pass if a handler simply
 * stopped calling it. Part 4 is what fails then. Running the handlers for
 * real (a sign-in, a form token, a redirect) needs the portal running:
 * `tools/access-pages-selftest.php` does that, and is the proof that a
 * person really is refused.
 *
 * Part 4 first looked only for the CALL, so it still passed with
 * `$isAdmin === false &&` written in front of it, which lets every
 * administrator off the rule (found by the independent check of 11 October
 * 2026). It now requires the whole statement, exactly: a plain `if` at the
 * top level of the handler, holding that one question and nothing else,
 * straight after the "claim not found" answer.
 *
 * SAFETY
 * ------
 * It writes rows, so it REFUSES unless the database's name starts with
 * `selftest_`. Every row it makes is numbered 915000 or higher (the
 * calendar tests use 910000 to 910999), and everything happens inside ONE
 * transaction that is rolled back at the end, whatever happens — so nothing
 * is left behind, and nothing needs tidying before the next run.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - That a real request is refused, or that the refusal reaches the screen.
 * - Part 4 reads text. It requires the exact statement, but it cannot see
 *   something done earlier in the handler that makes the statement
 *   harmless (the signed-in account's number overwritten, say). The
 *   real-pages test above is what catches that.
 * - Two people approving at the same moment (the handler's row lock).
 * - MariaDB: not run. MySQL 8.0.36 and 8.4.11: run on both.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… php tools/expense-rules-selftest.php
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
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/545
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 91;

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test writes rows, so it only ever runs on a throwaway database. Nothing was checked.\n";
    exit(1);
}

$web = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';

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

/** A page's PHP with its comments removed, so a commented-out call is never counted. '' if the file is missing. */
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

/**
 * PHP code with its comments AND all its spacing removed, so two pieces of
 * code compare equal exactly when they are the same statements. Text inside
 * quotes is kept as written.
 */
function squeezed(string $code): string
{
    $out = '';
    foreach (token_get_all($code) as $token) {
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

/** How many `{` are still open at a position in squeezed code (0 = the top level of the file). */
function depthAt(string $squeezedCode, int $position): int
{
    $before = substr($squeezedCode, 0, max(0, $position));

    return substr_count($before, '{') - substr_count($before, '}');
}

/** Where a piece of text first appears, or -1. */
function at(string $haystack, string $needle): int
{
    $position = strpos($haystack, $needle);

    return $position === false ? -1 : $position;
}

/** The account numbers as a sorted list, for comparing. @param list<int> $ids @return list<int> */
function sorted(array $ids): array
{
    sort($ids);

    return $ids;
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "Nobody decides or pays their own expense claim — self-test (#545)\n";
echo str_repeat('=', 78) . "\n";

$classFile   = $web . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'ExpenseRules.php';
$classExists = is_file($classFile);
if ($classExists === true) {
    require $classFile;
}
check(
    'the rule\'s own class, web/_core/ExpenseRules.php, exists',
    $classExists === true && class_exists('Portal\\Core\\ExpenseRules') === true,
    'Without it every check below fails or cannot run.'
);
$rules = 'Portal\\Core\\ExpenseRules';

// -----------------------------------------------------------------------------
// 1. isOwnClaim() — written out by hand
// -----------------------------------------------------------------------------
echo "\n1. Is this claim the acting person's own?\n";

// claimant, acting person, expected, why
$ownRows = [
    [915011, 915011, true,  'the same account: their own claim'],
    [915011, 915012, false, 'somebody else\'s claim'],
    [915012, 915011, false, 'the other way round is somebody else\'s claim too'],
    [0,      0,      false, 'nobody signed in and no claimant: 0 is never "the same person"'],
    [0,      915011, false, 'a claim with no claimant is nobody\'s own'],
    [915011, 0,      false, 'nobody signed in owns no claim'],
    [-5,     -5,     false, 'a nonsense account number is never "the same person"'],
];
foreach ($ownRows as [$claimant, $actor, $expected, $why]) {
    $actual = $classExists === true ? $rules::isOwnClaim($claimant, $actor) : null;
    check(
        sprintf('claimant %d, acting person %d => %s (%s)', $claimant, $actor, $expected === true ? 'own claim' : 'not their own', $why),
        $actual === $expected,
        'got ' . var_export($actual, true)
    );
}

// -----------------------------------------------------------------------------
// 2. requiredApprovers() — against the real database
// -----------------------------------------------------------------------------
echo "\n2. Whose approval a claim still needs\n";

$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$mysqli->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

// The made-up world. Two organisations; department D1 of organisation A is
// the one the claims are charged to.
const ORG_A = 915001;
const ORG_B = 915002;
const D1    = 915101; // department of A
const D2    = 915102; // a RETIRED department of A (isActive = 0)
const D3    = 915103; // department of A whose only lead is the claimant
const DB1   = 915104; // department of B
const LEAD      = 915011; // lead of D1 — the claimant in most checks
const LEAD2     = 915012; // a second lead of D1
const REQUIRED  = 915013; // a required approver of D1 (not a lead)
const APPROVER  = 915014; // a plain approver of D1: may decide, but the claim never waits for them
const LEFT      = 915015; // a required approver of D1 whose membership of A has ENDED
const MEMBER    = 915016; // an ordinary member of A, in D1 with no flag at all
const B_LEAD    = 915017; // a lead of B's department, and a member of B only
const RETIRED_L = 915018; // the lead of the retired department D2
const SOLE      = 915019; // the only lead of D3

$worldBuilt = false;
$mysqli->begin_transaction();
try {
    $mysqli->query(
        'INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES '
        . "(" . ORG_A . ", 'selftest-exp-a', 'Selftest expenses A', 1), (" . ORG_B . ", 'selftest-exp-b', 'Selftest expenses B', 1)"
    );
    $users = [LEAD => 'Lead', LEAD2 => 'Second lead', REQUIRED => 'Required approver', APPROVER => 'Plain approver',
        LEFT => 'Left the organisation', MEMBER => 'Member', B_LEAD => 'Lead in B', RETIRED_L => 'Lead of a retired department', SOLE => 'Sole lead'];
    foreach ($users as $userId => $name) {
        $mysqli->query(
            'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES '
            . "({$userId}, 'Selftest {$name}', 'u{$userId}.exp@selftest.invalid', 1, 0, 0)"
        );
    }
    // Memberships. LEFT's membership of A has ended (isActive = 0); B_LEAD belongs to B only.
    foreach ([LEAD => 1, LEAD2 => 1, REQUIRED => 1, APPROVER => 1, LEFT => 0, MEMBER => 1, RETIRED_L => 1, SOLE => 1] as $userId => $active) {
        $mysqli->query('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (' . $userId . ', ' . ORG_A . ", {$active}, 0, 0)");
    }
    $mysqli->query('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (' . B_LEAD . ', ' . ORG_B . ', 1, 0, 0)');
    $mysqli->query(
        'INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES '
        . '(' . D1 . ', ' . ORG_A . ", 'Selftest D1', 'SD1', 1), (" . D2 . ', ' . ORG_A . ", 'Selftest D2 retired', 'SD2', 0), "
        . '(' . D3 . ', ' . ORG_A . ", 'Selftest D3', 'SD3', 1), (" . DB1 . ', ' . ORG_B . ", 'Selftest DB1', 'SDB', 1)"
    );
    // userID, deptID, siteID, lead, approver, required approver
    $flags = [
        [LEAD,      D1,  ORG_A, 1, 0, 0],
        [LEAD2,     D1,  ORG_A, 1, 0, 0],
        [REQUIRED,  D1,  ORG_A, 0, 0, 1],
        [APPROVER,  D1,  ORG_A, 0, 1, 0],
        [LEFT,      D1,  ORG_A, 0, 0, 1],
        [MEMBER,    D1,  ORG_A, 0, 0, 0],
        [B_LEAD,    DB1, ORG_B, 1, 0, 1],
        [RETIRED_L, D2,  ORG_A, 1, 0, 0],
        [SOLE,      D3,  ORG_A, 1, 0, 0],
    ];
    foreach ($flags as [$userId, $deptId, $siteId, $lead, $approver, $required]) {
        $mysqli->query(
            'INSERT INTO tblUserDepts (userID, deptID, siteID, isDeptLead, isApprover, isMandatoryApprover) VALUES '
            . "({$userId}, {$deptId}, {$siteId}, {$lead}, {$approver}, {$required})"
        );
    }
    $worldBuilt = true;
} catch (Throwable $e) {
    echo '  (the made-up world could not be built: ' . $e->getMessage() . ")\n";
}
check('the made-up world was built (two organisations, nine accounts, four departments)', $worldBuilt === true);

/** requiredApprovers(), or null when it cannot be asked (so the check fails rather than the file stopping). */
$ask = static function (int $deptId, int $siteId, int $claimantId) use ($mysqli, $rules, $classExists, $worldBuilt): ?array {
    if ($classExists === false || $worldBuilt === false) {
        return null;
    }

    return sorted($rules::requiredApprovers($mysqli, $deptId, $siteId, $claimantId));
};

try {
    // Expected lists are written out by hand from the world above.
    check(
        'a claim by an ordinary member of D1 waits for both leads and the required approver — not the plain approver, not the one who left',
        $ask(D1, ORG_A, MEMBER) === [LEAD, LEAD2, REQUIRED],
        'got ' . json_encode($ask(D1, ORG_A, MEMBER))
    );
    check(
        'a claim by a LEAD of D1 waits for the other lead and the required approver — the claimant is left out',
        $ask(D1, ORG_A, LEAD) === [LEAD2, REQUIRED],
        'got ' . json_encode($ask(D1, ORG_A, LEAD))
    );
    check(
        'a claim by the REQUIRED APPROVER of D1 waits for both leads — the claimant is left out',
        $ask(D1, ORG_A, REQUIRED) === [LEAD, LEAD2],
        'got ' . json_encode($ask(D1, ORG_A, REQUIRED))
    );
    check(
        'a claim by the plain approver changes nothing: they were never on the list',
        $ask(D1, ORG_A, APPROVER) === [LEAD, LEAD2, REQUIRED],
        'got ' . json_encode($ask(D1, ORG_A, APPROVER))
    );
    check(
        'the required approver whose membership has ended is on nobody\'s list',
        in_array(LEFT, (array) $ask(D1, ORG_A, MEMBER), true) === false && in_array(LEFT, (array) $ask(D1, ORG_A, LEAD), true) === false
            && $ask(D1, ORG_A, MEMBER) !== null
    );
    check(
        'a claim by the ONLY lead of a department waits for nobody, so one approval from somebody else is enough',
        $ask(D3, ORG_A, SOLE) === [],
        'got ' . json_encode($ask(D3, ORG_A, SOLE))
    );
    check(
        '…and a claim by anybody else in that department still waits for that lead',
        $ask(D3, ORG_A, MEMBER) === [SOLE],
        'got ' . json_encode($ask(D3, ORG_A, MEMBER))
    );
    check(
        'a RETIRED department\'s lead is still on the list: its pending claims are finished by its own approvers',
        $ask(D2, ORG_A, MEMBER) === [RETIRED_L],
        'got ' . json_encode($ask(D2, ORG_A, MEMBER))
    );
    check(
        'asked about organisation B\'s department with organisation A\'s number, nobody is found',
        $ask(DB1, ORG_A, MEMBER) === [],
        'got ' . json_encode($ask(DB1, ORG_A, MEMBER))
    );
    check(
        'asked about D1 with organisation B\'s number, nobody is found',
        $ask(D1, ORG_B, MEMBER) === [],
        'got ' . json_encode($ask(D1, ORG_B, MEMBER))
    );
    check(
        'organisation B\'s own department gives B\'s own lead (so the two empty answers above are not just "nothing ever matches")',
        $ask(DB1, ORG_B, MEMBER) === [B_LEAD],
        'got ' . json_encode($ask(DB1, ORG_B, MEMBER))
    );
    check(
        'a claimant number of 0 leaves nobody out',
        $ask(D1, ORG_A, 0) === [LEAD, LEAD2, REQUIRED],
        'got ' . json_encode($ask(D1, ORG_A, 0))
    );
    // The control for the "left the organisation" check: with the membership
    // switched back on, the same person IS listed. Without this, that check
    // would also pass if LEFT had simply never been set up as an approver.
    if ($worldBuilt === true) {
        $mysqli->query('UPDATE tblUserSites SET isActive = 1 WHERE userID = ' . LEFT . ' AND siteID = ' . ORG_A);
    }
    check(
        'control: once that person\'s membership is active again, they ARE on the list',
        $ask(D1, ORG_A, MEMBER) === [LEAD, LEAD2, REQUIRED, LEFT],
        'got ' . json_encode($ask(D1, ORG_A, MEMBER))
    );
} finally {
    // Everything this file wrote goes, whatever happened above.
    $mysqli->rollback();
}
$left = $mysqli->query('SELECT (SELECT COUNT(*) FROM tblSites WHERE siteID IN (' . ORG_A . ', ' . ORG_B . ')) + (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 915000 AND 915999) AS n')->fetch_assoc();
check('nothing this file made is left in the database', (int) ($left['n'] ?? -1) === 0, 'rows left: ' . ($left['n'] ?? '?'));

// -----------------------------------------------------------------------------
// 3. The two refusal sentences
// -----------------------------------------------------------------------------
echo "\n3. The two refusal sentences\n";

check(
    'the approval refusal says what is wrong and who can do it instead',
    $classExists === true && $rules::MSG_OWN_DECISION === 'You cannot approve or reject your own expense claim. It must be decided by the other leads and required approvers of its department or, when there are none, by another approver or an administrator.'
);
// The first wording was "Another approver or an administrator must decide
// it". That is untrue where the department has another lead or required
// approver: an administrator's approval alone leaves such a claim waiting.
check(
    'the sentence shown on a claimant\'s own claim page says the same about who decides it',
    $classExists === true && $rules::MSG_OWN_CLAIM_PAGE === 'This is your own claim, so you cannot decide it yourself. It must be decided by the other leads and required approvers of its department or, when there are none, by another approver or an administrator.'
);
check(
    'the payment refusal says what is wrong and who can do it instead',
    $classExists === true && $rules::MSG_OWN_PAYMENT === 'You cannot record payment of your own expense claim. Another treasurer or an administrator must pay it.'
);

// -----------------------------------------------------------------------------
// 4. The pages use the rule
// -----------------------------------------------------------------------------
echo "\n4. The pages use the rule\n";

$apps        = $web . DIRECTORY_SEPARATOR . '_apps' . DIRECTORY_SEPARATOR;
$approveSave = codeOnly($apps . 'expenses/approve/save.php');
$paySave     = codeOnly($apps . 'expenses/treasury/save.php');
$approveList = codeOnly($apps . 'expenses/approve/index.php');
$payList     = codeOnly($apps . 'expenses/treasury/index.php');
$claimPage   = codeOnly($apps . 'expenses/view/index.php');
$dashboard   = codeOnly($apps . 'dashboard/index.php');
$mailer      = codeOnly($web . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'ExpenseMailer.php');

check('all seven files were read', min(strlen($approveSave), strlen($paySave), strlen($approveList), strlen($payList), strlen($claimPage), strlen($dashboard), strlen($mailer)) > 500);

// --- the approval handler ----------------------------------------------------
$ownCall = "ExpenseRules::isOwnClaim((int) \$claim['userID'], \$userId) === true";
$own     = at($approveSave, $ownCall);
check('approve/save.php asks whether the claim is the acting person\'s own, comparing the claim\'s owner with the signed-in account', $own >= 0);
check(
    '…after the claim is loaded, and before the transaction, the row lock and the first INSERT',
    $own >= 0
        && at($approveSave, 'FROM tblExpenseClaims EC') >= 0 && at($approveSave, 'FROM tblExpenseClaims EC') < $own
        && $own < at($approveSave, '$mysqli->begin_transaction()')
        && $own < at($approveSave, 'FOR UPDATE')
        && $own < at($approveSave, 'INSERT INTO')
);
$ownBlock = $own >= 0 ? substr($approveSave, $own, 400) : '';
check(
    '…and its answer is the refusal sentence, then back to the approvals page, then stop',
    preg_match('/^' . preg_quote($ownCall, '/') . '\)\s*\{\s*\$_SESSION\[\'flash_msg\'\]\s*=\s*ExpenseRules::MSG_OWN_DECISION;\s*\$_SESSION\[\'flash_type\'\]\s*=\s*\'danger\';\s*header\(\'Location: \' \. Router::url\(\'expenses\/approve\'\)\);\s*exit\(\);\s*\}/', $ownBlock) === 1,
    'Found: ' . substr($ownBlock, 0, 200)
);
// The whole statement, exactly, at the top level of the handler, straight
// after the "claim not found" answer. With anything written in front of the
// question (`$isAdmin === false &&`), or anything wrapped round the `if`,
// this no longer matches.
$approveSqueezed = squeezed((string) @file_get_contents($apps . 'expenses/approve/save.php'));
$approveExact    = "exit();}if(ExpenseRules::isOwnClaim((int)\$claim['userID'],\$userId)===true){\$_SESSION['flash_msg']=ExpenseRules::MSG_OWN_DECISION;"
    . "\$_SESSION['flash_type']='danger';header('Location: '.Router::url('expenses/approve'));exit();}\$approverRole=null;";
check(
    '…as a plain top-level `if` holding that ONE question — nothing in front of it (no "unless an administrator"), nothing wrapped round it — asked exactly once',
    substr_count($approveSqueezed, $approveExact) === 1 && substr_count($approveSqueezed, 'isOwnClaim(') === 1
        && depthAt($approveSqueezed, at($approveSqueezed, $approveExact) + strlen('exit();}')) === 0,
    'The statement must read exactly: if (ExpenseRules::isOwnClaim((int) $claim[\'userID\'], $userId) === true) { flash; redirect; exit(); }'
);
check(
    'approve/save.php takes the people a claim waits for from ExpenseRules::requiredApprovers(), leaving the CLAIMANT out',
    preg_match('/\$mandatoryApprovers\s*=\s*ExpenseRules::requiredApprovers\(\s*\$mysqli,\s*\(int\) \$claim\[\'deptID\'\],\s*\$siteId,\s*\(int\) \$claim\[\'userID\'\]\s*\);/', $approveSave) === 1
);
check(
    '…and no longer carries its own copy of that query (two copies would drift apart)',
    str_contains($approveSave, 'isMandatoryApprover = 1') === false && str_contains($approveSave, 'FROM tblUserDepts') === false
);
// The "no authority at all" refusal: what it always was, and before any claim lookup.
// One thing in it changed on 12 October 2026 (roll-out package B2a), here and
// in the two "own claim" statements this file pins above and below: the
// address the person is sent back to is now built by Router::url(), so it
// stays inside their own organisation's address. The words, the test and the
// order did not change.
//
// 🚪 AND ONE THING CHANGED ON 4 NOVEMBER 2026 (#739): the role test used to
//    read `App::hasRole('Approver')` and is now
//    `App::can(Capabilities::EXPENSES_APPROVE)`. That capability SHIPS
//    pointing at the `approver` role and falls open for a global
//    administrator, so for an installation that has never opened the Roles
//    page it answers exactly what the old line answered — the same two
//    grounds in the same order. What is new is that a customer may point the
//    door at a role of their own without editing PHP.
//
// 🚪 AND AGAIN ON 10 NOVEMBER 2026 (#648, #915, D-103): the role is no longer
//    a way past this gate at all. Its only effect here had been to let a
//    role-holder with no department reach the claim lookup, where a real
//    pending claim answered "Forbidden" and a made-up number "Claim not
//    found" — telling them which numbers were real. The condition is now the
//    administrator test alone, and the words no longer name the role, because
//    a role-holder would have been told they needed the role they held.
//
//    ⚠️ THE PIN IS STILL THE WHOLE BLOCK, AND IT HAD TO BE UPDATED RATHER
//       THAN LOOSENED. The temptation on seeing this check go red is to match
//       the inside of the block and let the condition be anything; that would
//       pass on `if (false && ...)`, and the department fall-back underneath
//       is the only thing then standing between a signed-in member and the
//       approvals handler. If this goes red again, read what the condition
//       has become and decide whether it is right — do not widen the pattern.
$noAuthority = "if (\$isAdmin === false) {\n"
    . "    \$approverDepts = Departments::approverDepts(\$mysqli, \$userId, \$siteId);\n"
    . "    if (count(\$approverDepts) === 0) {\n"
    . "        \$_SESSION['flash_msg']  = 'Access denied — only an administrator, or a lead or approver of a department, can decide expense claims.';\n"
    . "        \$_SESSION['flash_type'] = 'danger';\n"
    . "        header('Location: ' . Router::url('expenses/approve'));\n"
    . "        exit();\n"
    . "    }\n"
    . "}\n";
$rawApprove = (string) @file_get_contents($apps . 'expenses/approve/save.php');

// 💰 THE DECISION IS SAID EXACTLY, OR IT IS REFUSED (found 27 October 2026).
//
//    approve/save.php used to read:
//        $decision = ($_POST["decision"] ?? "") === "Rejected" ? "Rejected" : "Approved";
//    (with single quotes) so anything that was not the exact text "Rejected"
//    recorded an APPROVAL — a missing field, lower case, a misspelling, or
//    `decision[]=Rejected`, because an array is never equal to a string. If
//    that was the last approval the claim needed, a claim meant to be REJECTED
//    became payable.
//
//    BE HONEST ABOUT WHAT THESE CHECKS ARE: they read the CODE, not the
//    behaviour, so they would not catch a third way of writing the same
//    mistake. There is no end-to-end test that posts a malformed decision to
//    the real page and proves nothing is approved; that belongs in a test which
//    drives a server, and it is not written yet.
$approveTight = squeezed($rawApprove);
check(
    "approve/save.php reads the decision through a named value it can then test, not a ternary that guesses",
    str_contains($approveTight, '$decisionRaw=$_POST[') === true
        && str_contains($approveTight, "==='Rejected'?'Rejected':'Approved'") === false
);
check(
    "...it requires the decision to be text saying exactly Approved or Rejected",
    str_contains($approveTight, 'is_string($decisionRaw)===true') === true
        && str_contains($approveTight, '$decisionRaw===\'Approved\'') === true
        && str_contains($approveTight, '$decisionRaw===\'Rejected\'') === true
);

// 🧠 THIS CHECK USED TO BE WORTHLESS, AND THAT IS WHY IT NOW READS THE BLOCK.
//
//    It used to assert only that the text `if($decision===''){` appeared
//    somewhere before the first claim query. It never looked INSIDE the block.
//    A review on 27 October 2026 planted this, which puts the money bug back
//    in full while keeping every phrase the other checks hunt for:
//
//        if ($decision === '') {
//            $decision = 'Approved';   // "be forgiving about an older form"
//        }
//
//    and the self-test reported 54 of 54 passed. A missing `decision` field
//    approved the claim again, with every check green. So the block's CONTENTS
//    are what matter: it must stop the request, and it must not quietly decide.
// NOTE the single quotes. Written with double quotes, PHP interpolates
//    `$decision` out of the needle and the check silently looks for the wrong
//    text — which is how two of these checks first passed by accident.
$refuseFrom = at($approveTight, 'if($decision===\'\'){');
// 🧱 The block is taken to its MATCHING close brace, not to "the next exit()
//    anywhere in the file". A round-2 review deleted the `exit();` from this
//    block and the check still passed, because strpos() happily found an
//    exit() hundreds of characters further down. A check that reads past the
//    end of what it is checking is not reading it at all.
$refuseBody = '';
if ($refuseFrom >= 0) {
    $open  = strpos($approveTight, '{', $refuseFrom);
    $depth = 0;
    for ($i = $open; $i < strlen($approveTight); $i++) {
        if ($approveTight[$i] === '{') {
            $depth++;
        } elseif ($approveTight[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                $refuseBody = substr($approveTight, $refuseFrom, ($i + 1) - $refuseFrom);
                break;
            }
        }
    }
}
check(
    "...and when it is neither, the page STOPS without deciding anything",
    $refuseBody !== ''
        && str_contains($refuseBody, "\$_SESSION['flash_type']='danger'") === true
        && str_contains($refuseBody, 'exit();') === true
        // The block must not SET a decision. That is the planted fault above.
        //    Tested as an assignment and not as plain text, because the
        //    block's own opening condition, `if($decision===''){`, contains
        //    the characters `$decision=` and defeated the simpler test.
        && preg_match('/\\$decision\\s*=(?!=)/', $refuseBody) !== 1,
    $refuseBody === '' ? 'the refusal block could not be found at all' : $refuseBody
);
// 🧠 AND THE LINE THAT MAKES THE BLOCK REACHABLE. A round-2 review changed
//    only the initial value —
//        $decision    = 'Approved';   // was: ''
//    — leaving the refusal block and every phrase the other checks hunt for
//    exactly as they were. `if ($decision === '')` can then never fire, so a
//    missing field, an empty value, the wrong case and a list ALL record an
//    approval again, and the self-test reported 57 of 57. Guarding the inside
//    of a block is worth nothing if the way in can be closed off.
// ⚠️ SINGLE QUOTES, AND THAT IS THE WHOLE POINT (#757). This needle was
//    written in DOUBLE quotes, so PHP interpolated `$decision` — undefined
//    here — and the needle became `='';`. Measured:
//
//        php -r '$n = "$decision=\'\';"; var_dump($n);'
//        PHP Warning:  Undefined variable $decision
//        string(4) "='';"
//
//    So the check looked for ANY `x='';` anywhere in the tightened file, and
//    printed an undefined-variable warning on every run. The comment above
//    explains that this check exists BECAUSE a reviewer once changed only the
//    initial value, and that it was deliberately written as an assignment
//    rather than plain text — the interpolation undid exactly that care, so
//    the guard for a recorded past fault was silently blunted.
//
//    **If this ever goes red, do NOT widen the pattern.** Matching less than
//    the whole assignment is what the original comment warns against, and a
//    needle that stops matching means the thing it guards has changed, which
//    is the finding.
check(
    "...and the decision starts as nothing, so that block is actually reachable",
    str_contains($approveTight, '$decision=\'\';') === true,
    preg_match('/\$decision\s*=\s*[^;]*;/', $rawApprove, $seen) === 1 ? trim($seen[0]) : '(not found)'
);
check(
    "...and it refuses before the claim is even looked up, so it reveals nothing about which claims exist",
    $refuseFrom >= 0 && $refuseFrom < at($approveTight, 'FROM tblExpenseClaims EC')
);

// 🔢 The claim NUMBER is guarded the same way as the decision. `(int) ['x']` is
//    1 in PHP, silently, so `claimID[]=916301` used to arrive as claim number
//    one and a real decision was recorded against whatever claim that is. The
//    same line appeared in all three money handlers and all three were changed
//    together, so all three are checked here.
foreach (['approve', 'treasury', 'withdraw'] as $handler) {
    $tight = squeezed((string) @file_get_contents($apps . 'expenses/' . $handler . '/save.php'));
    check(
        "expenses/$handler/save.php will not take a list as the claim number",
        str_contains($tight, 'is_scalar($claimIdRaw)===true?(int)$claimIdRaw:0') === true
            && str_contains($tight, '$claimID=(int)($_POST[') === false,
        $tight === '' ? 'the file could not be read' : ''
    );
}


check(
    'the refusal for somebody with no authority at all is the whole block, word for word — administrator or a department flag, and NOT the Approver role (#915)',
    str_contains($rawApprove, $noAuthority) === true
);
check(
    '…and still comes before any claim is looked up, so it reveals nothing about which claims exist',
    at($approveSave, "'Access denied — only an administrator, or a lead or approver of a department, can decide expense claims.'") >= 0
        && at($approveSave, "'Access denied — only an administrator, or a lead or approver of a department, can decide expense claims.'") < at($approveSave, 'FROM tblExpenseClaims EC')
);
// The answer under the lock was reworded on 12 October 2026 (#587). It used to
// say "already been decided by another approver", which is untrue when the
// claim left Pending because its claimant withdrew it. The test under the lock
// itself is pinned here too, word for word: it is the only thing that stops a
// decision being recorded on a claim that was withdrawn a moment earlier, and
// no test that sends one request at a time can see it go missing.
check(
    'the row lock, the "still Pending?" question asked under it, and its answer (true of a decided claim AND of a withdrawn one) are still there',
    str_contains($approveSave, 'WHERE claimID = ? AND siteID = ? FOR UPDATE') === true
        && str_contains($approveSave, "if (\$lockedClaim === null || \$lockedClaim['status'] !== 'Pending') {") === true
        && str_contains($approveSave, "'This claim is no longer waiting for a decision. Another approver has decided it, or the person who made it has withdrawn it.'") === true
        && str_contains($approveSave, 'already been decided by another approver') === false
);

// --- the payment handler -----------------------------------------------------
$payOwn = at($paySave, $ownCall);
check('treasury/save.php asks whether the claim is the acting person\'s own, comparing the claim\'s owner with the signed-in account', $payOwn >= 0);
check(
    '…after the claim is loaded, and before the transaction and the first INSERT',
    $payOwn >= 0
        && at($paySave, 'FROM tblExpenseClaims EC') >= 0 && at($paySave, 'FROM tblExpenseClaims EC') < $payOwn
        && $payOwn < at($paySave, '$mysqli->begin_transaction()')
        && $payOwn < at($paySave, 'INSERT INTO')
);
$payBlock = $payOwn >= 0 ? substr($paySave, $payOwn, 400) : '';
check(
    '…and its answer is the refusal sentence, then back to the treasury page, then stop',
    preg_match('/^' . preg_quote($ownCall, '/') . '\)\s*\{\s*\$_SESSION\[\'flash_msg\'\]\s*=\s*ExpenseRules::MSG_OWN_PAYMENT;\s*\$_SESSION\[\'flash_type\'\]\s*=\s*\'danger\';\s*header\(\'Location: \' \. Router::url\(\'expenses\/treasury\'\)\);\s*exit\(\);\s*\}/', $payBlock) === 1,
    'Found: ' . substr($payBlock, 0, 200)
);
$paySqueezed = squeezed((string) @file_get_contents($apps . 'expenses/treasury/save.php'));
$payExact    = "exit();}if(ExpenseRules::isOwnClaim((int)\$claim['userID'],\$userId)===true){\$_SESSION['flash_msg']=ExpenseRules::MSG_OWN_PAYMENT;"
    . "\$_SESSION['flash_type']='danger';header('Location: '.Router::url('expenses/treasury'));exit();}\$paidByName=";
check(
    '…as a plain top-level `if` holding that ONE question — nothing in front of it (no "unless an administrator"), nothing wrapped round it — asked exactly once',
    substr_count($paySqueezed, $payExact) === 1 && substr_count($paySqueezed, 'isOwnClaim(') === 1
        && depthAt($paySqueezed, at($paySqueezed, $payExact) + strlen('exit();}')) === 0,
    'The statement must read exactly: if (ExpenseRules::isOwnClaim((int) $claim[\'userID\'], $userId) === true) { flash; redirect; exit(); }'
);
check(
    'in both handlers $userId is the signed-in account and nothing else',
    substr_count($approveSave, '$userId  = (int) ($_SESSION[\'user_id\'] ?? 0);') === 1 && substr_count($approveSave, '$userId =') === 0
        && substr_count($paySave, '$userId   = (int) ($_SESSION[\'user_id\'] ?? 0);') === 1 && substr_count($paySave, '$userId =') === 0,
    'A second assignment to $userId would let the comparison be made against somebody else.'
);

// --- the three lists ---------------------------------------------------------
// #648: the two list queries (one for an administrator, one with the department
// join written out) became ONE query whose department condition comes from
// ExpenseRules::reachSql(reachToDecide()). Section 5 pins that it asks the rule;
// these two still pin that the viewer's own claims are left out, for everybody.
check(
    'approve/index.php: its ONE list query leaves the viewer\'s own claims out, for an administrator and everybody else alike',
    substr_count($approveList, "WHERE EC.status = 'Pending' AND EC.siteID = ? AND EC.userID <> ? AND ") === 1
        && substr_count($approveList, "EC.status = 'Pending'") === 1
);
check(
    '…and binds the signed-in account to that question mark',
    str_contains($approveList, "\$stmt->bind_param('ii' . \$reach['types'], \$siteId, \$currentUserId, ...\$reach['params']);") === true
        && str_contains($approveList, '$currentUserId = (int) ($_SESSION[\'user_id\'] ?? 0);') === true
);
check(
    'treasury/index.php: the list query leaves the viewer\'s own claims out, bound to the signed-in account',
    str_contains($payList, 'WHERE EC.status = "Approved" AND EC.siteID = ? AND EC.userID <> ? ') === true
        && substr_count($payList, 'EC.status = "Approved"') === 1
        && str_contains($payList, "\$stmt->bind_param('ii', \$siteId, \$currentUserId);") === true
        && str_contains($payList, '$currentUserId = (int) ($_SESSION[\'user_id\'] ?? 0);') === true
);
check(
    'both list pages say, in one line, that a person\'s own claims are not listed',
    str_contains($approveList, 'Your own claims are not listed here: somebody else must decide them.') === true
        && str_contains($payList, 'Your own claims are not listed here: somebody else must pay them.') === true
);

// --- the claim page, the e-mail and the dashboard ----------------------------
check(
    'the claim page offers neither "Go to Approvals" nor "Go to Treasury" on a person\'s own claim',
    str_contains($claimPage, "\$claim['status'] === 'Pending' && \$isClaimant === false && \$mayDecide === true") === true
        && str_contains($claimPage, "\$claim['status'] === 'Approved' && \$isClaimant === false && (\$hasTreasurer === true || \$isAdmin === true)") === true
        && str_contains($claimPage, "\$isClaimant    = ((int) \$claim['userID'] === \$currentUserId);") === true
);
check(
    'the claim page tells a claimant who could otherwise have decided the claim who does decide it, with the rule\'s own sentence',
    str_contains($claimPage, "\$claim['status'] === 'Pending' && \$isClaimant === true && \$mayDecide === true") === true
        && str_contains($claimPage, "htmlspecialchars(ExpenseRules::MSG_OWN_CLAIM_PAGE, ENT_QUOTES, 'UTF-8')") === true
);
check(
    'the e-mail that asks for a decision ("submitted") leaves the claimant out',
    preg_match("/case 'submitted':\s*\\\$recipients = self::getApproverEmails\(\(int\) \\\$claim\['deptID'\], \\\$siteId, \(int\) \\\$claim\['userID'\]\);/", $mailer) === 1
        && str_contains($mailer, 'WHERE UD.deptID = ? AND UD.siteID = ? AND UD.userID <> ? ') === true
        && str_contains($mailer, "\$stmt->bind_param('iii', \$deptID, \$siteId, \$exceptUserId);") === true
);
check(
    '…while the two that only report what happened ("withdrawn", "reimbursed") still go to every approver',
    substr_count($mailer, "self::getApproverEmails((int) \$claim['deptID'], \$siteId)") === 2
);
check(
    'the dashboard\'s "Pending Claims" number leaves the administrator\'s own claims out, like the list it links to',
    str_contains($dashboard, "WHERE status = \\'Pending\\' AND siteID = ? AND userID <> ?'") === true
        && str_contains($dashboard, "\$expStmt->bind_param('ii', \$siteId, \$userId);") === true
);

// -----------------------------------------------------------------------------
// 5. Whose claims a person may see (#648, D-103)
// -----------------------------------------------------------------------------
// The organisation-wide Approver role used to open ANY claim on the claim page
// and put EVERY claim in the spreadsheet, while the approvals list and the
// decision handler kept it to the departments its holder approves for. The
// owner's answer (D-103): the role sees and downloads only those departments'
// claims; an administrator and a treasurer see everything.
//
// 5a drives the rule's pure parts by hand. 5b runs `reachSql()` against REAL
// rows and requires it to pick exactly the claims `reachCovers()` says yes to,
// for every reach in a list that includes the empty one and a malformed one —
// so the PHP answer the claim page gives and the SQL answer the spreadsheet
// and the approvals list give cannot drift apart. 5c reads the three pages as
// text (comments removed) and requires each to ask the rule; it cannot see a
// page that asks and then ignores the answer — `tools/access-pages-selftest.php`
// section L3 is what proves, over real HTTP, that a person is refused.
echo "\n5. Whose claims a person may see (#648)\n";

$deptFile = $web . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'Departments.php';
if (is_file($deptFile) === true && class_exists('Portal\\Core\\Departments') === false) {
    require $deptFile;
}

// --- 5a. the rule's pure parts ------------------------------------------------
$all      = $classExists === true ? $rules::reachFrom(true, [7 => ['isDeptLead' => true]]) : null;
$two      = $classExists === true ? $rules::reachFrom(false, [9 => [], 4 => [], 0 => [], -3 => []]) : null;
$none     = $classExists === true ? $rules::reachFrom(false, []) : null;
check(
    'reachFrom(): somebody who sees everything reaches everything, whatever departments are passed',
    $all === ['all' => true, 'depts' => []],
    json_encode($all)
);
check(
    'reachFrom(): anybody else reaches the KEYS of approverDepts(), sorted, with nonsense numbers dropped',
    $two === ['all' => false, 'depts' => [4, 9]],
    json_encode($two)
);
check('reachFrom(): no department is no reach at all', $none === ['all' => false, 'depts' => []], json_encode($none));

// mayViewClaim(reach, claimant, claim's department, viewer) — every row written out by hand.
$viewTable = [
    ['the claimant opens their own claim with no reach at all',                          ['all' => false, 'depts' => []],  50, 4, 50, true],
    ['the claimant opens their own claim in a department they do not approve for',      ['all' => false, 'depts' => [9]], 50, 4, 50, true],
    ['an approver of department 4 opens a claim of department 4',                        ['all' => false, 'depts' => [4]], 50, 4, 60, true],
    ['an approver of department 4 does NOT open a claim of department 9 (D-103)',        ['all' => false, 'depts' => [4]], 50, 9, 60, false],
    ['somebody with no department opens nobody else\'s claim (the role alone, D-103)',   ['all' => false, 'depts' => []],  50, 4, 60, false],
    ['somebody who reaches everything opens any claim',                                  ['all' => true,  'depts' => []],  50, 9, 60, true],
    ['viewer 0 is nobody, and nobody\'s claim (claimant 0) is not theirs',               ['all' => false, 'depts' => []],  0,  4, 0,  false],
    ['a malformed reach ("all" not exactly true) reaches nothing',                       ['all' => 1,     'depts' => []],  50, 4, 60, false],
    ['a malformed reach (department numbers as text) reaches nothing',                   ['all' => false, 'depts' => ['4']], 50, 4, 60, false],
    ['a reach with no department list at all reaches nothing',                           ['all' => false],                 50, 4, 60, false],
];
foreach ($viewTable as [$what, $reachRow, $claimant, $dept, $viewer, $expected]) {
    $got = $classExists === true ? $rules::mayViewClaim($reachRow, $claimant, $dept, $viewer) : null;
    check('mayViewClaim(): ' . $what, $got === $expected, 'got ' . var_export($got, true));
}

$sqlAll  = $classExists === true ? $rules::reachSql(['all' => true, 'depts' => [4]], 'EC.deptID') : null;
$sqlNone = $classExists === true ? $rules::reachSql(['all' => false, 'depts' => []], 'EC.deptID') : null;
$sqlTwo  = $classExists === true ? $rules::reachSql(['all' => false, 'depts' => [4, 9, 4]], 'EC.deptID') : null;
check('reachSql(): everything is `1 = 1` with nothing bound', $sqlAll === ['sql' => '1 = 1', 'types' => '', 'params' => []], json_encode($sqlAll));
check('reachSql(): no department is `1 = 0` with nothing bound — no rows, never "every row"', $sqlNone === ['sql' => '1 = 0', 'types' => '', 'params' => []], json_encode($sqlNone));
check(
    'reachSql(): some departments are an IN list with every number BOUND, never written into the SQL',
    $sqlTwo === ['sql' => 'EC.deptID IN (?, ?)', 'types' => 'ii', 'params' => [4, 9]],
    json_encode($sqlTwo)
);
$refused = false;
try {
    $rules::reachSql(['all' => false, 'depts' => [4]], 'EC.deptID OR 1=1');
} catch (\InvalidArgumentException $e) {
    $refused = true;
}
check('reachSql(): a column that is not a plain alias.column is refused, because it is put into the SQL as written', $refused === true);

// --- 5b. the SQL answer is the PHP answer, on real rows ----------------------
const S_D1 = 915201; // three departments of A
const S_D2 = 915202;
const S_D3 = 915203; // retired: its claims are still reached by its own approvers
const S_DB = 915204; // a department of B
const S_CLAIMANT = 915031;
const S_APPROVER = 915032; // approver of S_D1, required approver of S_D3
const S_LEFT     = 915033; // lead of S_D2 whose membership of A has ENDED
const S_NOFLAG   = 915034; // in S_D1 with no flag
const S_BLEAD    = 915035; // lead of B's department, member of B only
/** @var array<int, int> claim number => its department */
$sClaims = [915301 => S_D1, 915302 => S_D1, 915303 => S_D2, 915304 => S_D3, 915305 => S_DB];

$sBuilt = false;
$mysqli->begin_transaction();
try {
    $mysqli->query(
        'INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES '
        . '(' . ORG_A . ", 'selftest-exp-a', 'Selftest expenses A', 1), (" . ORG_B . ", 'selftest-exp-b', 'Selftest expenses B', 1)"
    );
    foreach ([S_CLAIMANT, S_APPROVER, S_LEFT, S_NOFLAG, S_BLEAD] as $userId) {
        $mysqli->query(
            'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES '
            . "({$userId}, 'Selftest reach {$userId}', 'u{$userId}.exp@selftest.invalid', 1, 0, 0)"
        );
    }
    foreach ([S_CLAIMANT => 1, S_APPROVER => 1, S_LEFT => 0, S_NOFLAG => 1] as $userId => $active) {
        $mysqli->query('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (' . $userId . ', ' . ORG_A . ", {$active}, 0, 0)");
    }
    $mysqli->query('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (' . S_BLEAD . ', ' . ORG_B . ', 1, 0, 0)');
    $mysqli->query(
        'INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES '
        . '(' . S_D1 . ', ' . ORG_A . ", 'Reach D1', 'RD1', 1), (" . S_D2 . ', ' . ORG_A . ", 'Reach D2', 'RD2', 1), "
        . '(' . S_D3 . ', ' . ORG_A . ", 'Reach D3 retired', 'RD3', 0), (" . S_DB . ', ' . ORG_B . ", 'Reach DB', 'RDB', 1)"
    );
    foreach ([[S_APPROVER, S_D1, ORG_A, 0, 1, 0], [S_APPROVER, S_D3, ORG_A, 0, 0, 1], [S_LEFT, S_D2, ORG_A, 1, 0, 0],
        [S_NOFLAG, S_D1, ORG_A, 0, 0, 0], [S_BLEAD, S_DB, ORG_B, 1, 0, 0]] as [$userId, $deptId, $siteId, $lead, $approver, $required]) {
        $mysqli->query(
            'INSERT INTO tblUserDepts (userID, deptID, siteID, isDeptLead, isApprover, isMandatoryApprover) VALUES '
            . "({$userId}, {$deptId}, {$siteId}, {$lead}, {$approver}, {$required})"
        );
    }
    foreach ($sClaims as $claimId => $deptId) {
        $siteId = $deptId === S_DB ? ORG_B : ORG_A;
        $mysqli->query(
            'INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status) VALUES '
            . "({$claimId}, {$siteId}, " . S_CLAIMANT . ", {$deptId}, 'Reach claim {$claimId}', CURDATE(), 10.00, 'Pending')"
        );
    }
    $sBuilt = true;
} catch (Throwable $e) {
    echo '  (the made-up world for section 5 could not be built: ' . $e->getMessage() . ")\n";
}
check('the made-up world for section 5 was built (two organisations, four departments, five claims)', $sBuilt === true);

/** The claim numbers of A that reachSql() picks, in order. @return list<int>|null */
$picked = static function (array $reach) use ($mysqli, $rules, $classExists, $sBuilt): ?array {
    if ($classExists === false || $sBuilt === false) {
        return null;
    }
    $cond = $rules::reachSql($reach, 'EC.deptID');
    $stmt = $mysqli->prepare(
        'SELECT EC.claimID FROM tblExpenseClaims EC WHERE EC.siteID = ? AND EC.claimID BETWEEN 915301 AND 915399 AND '
        . $cond['sql'] . ' ORDER BY EC.claimID'
    );
    $stmt->bind_param('i' . $cond['types'], ...array_merge([ORG_A], $cond['params']));
    $stmt->execute();
    $ids = array_map(static fn (array $r): int => (int) $r['claimID'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    return $ids;
};
/** The claim numbers of A that reachCovers() says yes to, in order. @return list<int> */
$covered = static function (array $reach) use ($rules, $sClaims): array {
    $ids = [];
    foreach ($sClaims as $claimId => $deptId) {
        if ($deptId !== S_DB && $rules::reachCovers($reach, $deptId) === true) {
            $ids[] = $claimId;
        }
    }

    return $ids;
};

try {
    $deptsOf = static fn (int $userId): array => $sBuilt === true && class_exists('Portal\\Core\\Departments') === true
        ? $rules::reachFrom(false, \Portal\Core\Departments::approverDepts($mysqli, $userId, ORG_A))
        : ['all' => false, 'depts' => [-1]];
    $cases = [
        'an approver of D1 and required approver of the RETIRED D3' => [$deptsOf(S_APPROVER), [915301, 915302, 915304]],
        'a lead of D2 whose membership has ENDED'                   => [$deptsOf(S_LEFT),     []],
        'a member of D1 with no flag'                               => [$deptsOf(S_NOFLAG),   []],
        'a lead of B\'s department, asked about A'                  => [$deptsOf(S_BLEAD),    []],
        'everything (an administrator, or a treasurer reading)'      => [$rules::reachFrom(true, []), [915301, 915302, 915303, 915304]],
        'a malformed reach'                                         => [['all' => 'yes', 'depts' => ['915201']], []],
    ];
    foreach ($cases as $what => [$reachCase, $expected]) {
        $sqlIds = $picked($reachCase);
        check(
            'on real rows, ' . $what . ': the SQL picks exactly the claims the PHP rule opens, and they are the expected ones',
            $sqlIds === $covered($reachCase) && $sqlIds === $expected,
            'SQL ' . json_encode($sqlIds) . ', PHP ' . json_encode($covered($reachCase)) . ', expected ' . json_encode($expected)
        );
    }
    check(
        'control: the "ended membership" answer is the membership, not a missing row — switched back on, D2\'s claim IS reached',
        $sBuilt === true && $mysqli->query('UPDATE tblUserSites SET isActive = 1 WHERE userID = ' . S_LEFT . ' AND siteID = ' . ORG_A) === true
            && $picked($deptsOf(S_LEFT)) === [915303]
    );
} finally {
    $mysqli->rollback();
}
$left = $mysqli->query('SELECT (SELECT COUNT(*) FROM tblSites WHERE siteID IN (' . ORG_A . ', ' . ORG_B . ')) + (SELECT COUNT(*) FROM tblExpenseClaims WHERE claimID BETWEEN 915301 AND 915399) AS n')->fetch_assoc();
check('nothing section 5 made is left in the database', (int) ($left['n'] ?? -1) === 0, 'rows left: ' . ($left['n'] ?? '?'));

// --- 5c. the pages ask the rule ---------------------------------------------
$exportPage = codeOnly($apps . 'expenses/api/export.php');
$claimTight = squeezed((string) @file_get_contents($apps . 'expenses/view/index.php'));
$exportTight = squeezed((string) @file_get_contents($apps . 'expenses/api/export.php'));
$listTight  = squeezed((string) @file_get_contents($apps . 'expenses/approve/index.php'));
// The whole statement, so `&& false`, or a second ground written in front of
// it, changes the text and fails here.
$claimGate = "\$reach=ExpenseRules::reachToSee(\$mysqli,\$currentUserId,\$siteId);";
$claimRefusal = "if(\$claim===null||ExpenseRules::mayViewClaim(\$reach,(int)\$claim['userID'],(int)\$claim['deptID'],\$currentUserId)===false){Router::renderError(404);return;}";
check(
    'the claim page works out the reach BEFORE it reads the claim, at the top level of the page',
    at($claimTight, $claimGate) >= 0 && at($claimTight, $claimGate) < at($claimTight, 'FROM tblExpenseClaims EC')
        && depthAt($claimTight, at($claimTight, $claimGate)) === 0
);
check(
    '…and answers a claim it may not open EXACTLY as a missing claim — one statement, one 404 — at the top level, once',
    substr_count($claimTight, $claimRefusal) === 1 && depthAt($claimTight, at($claimTight, $claimRefusal)) === 0
        && at($claimTight, $claimRefusal) < at($claimTight, 'FROM tblExpenseClaimItems'),
    'The statement must read exactly: if ($claim === null || ExpenseRules::mayViewClaim($reach, (int) $claim[\'userID\'], (int) $claim[\'deptID\'], $currentUserId) === false) { Router::renderError(404); return; }'
);
check(
    '…and no longer lets the Approver role (the expenses.approve capability) in, or answers 403',
    str_contains($claimPage, 'EXPENSES_APPROVE') === false && str_contains($claimPage, 'renderError(403)') === false
);
check(
    'the spreadsheet\'s rows follow the SAME reach as the claim page, inside the query',
    str_contains($exportTight, "\$reach=ExpenseRules::reachSql(ExpenseRules::reachToSee(\$mysqli,\$userId,\$siteId),'EC.deptID');") === true
        && str_contains($exportTight, "WHERE EC.siteID = ? AND \".\$reach['sql'].\"") === true
        && str_contains($exportTight, "\$stmt->bind_param('i'.\$reach['types'],\$siteId,...\$reach['params']);") === true
        && substr_count($exportPage, 'FROM tblExpenseClaims') === 1,
    'The handler must build its condition from reachSql(reachToSee(...)) and bind its numbers after the organisation.'
);
check(
    'the approvals list asks the DECIDING reach, in its one query',
    str_contains($listTight, "\$reach=ExpenseRules::reachSql(ExpenseRules::reachToDecide(\$mysqli,\$currentUserId,\$siteId),'EC.deptID');") === true
        && str_contains($listTight, "EC.userID <> ? AND \".\$reach['sql'].'") === true
        && str_contains($listTight, "\$stmt->bind_param('ii'.\$reach['types'],\$siteId,\$currentUserId,...\$reach['params']);") === true
        && str_contains($approveList, 'FROM tblUserDepts') === false,
    'and it no longer carries its own copy of the department join.'
);
check(
    'mayExportClaims() admits a treasurer (D-040) as well as an administrator and the Approver role',
    $classExists === true
        && str_contains(squeezed((string) @file_get_contents($classFile)), "if(App::can(Capabilities::EXPENSES_TREASURY)===true){returntrue;}returnApp::can(Capabilities::EXPENSES_APPROVE);}") === true
);
check(
    'the claim page offers "Go to Approvals" only to somebody who may DECIDE this claim — the approvals list\'s own reach, not the Approver role',
    str_contains($claimTight, "\$mayDecide=ExpenseRules::reachCovers(ExpenseRules::reachToDecide(\$mysqli,\$currentUserId,\$siteId),(int)\$claim['deptID']);") === true
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
    echo "\nSomething about who may decide or pay an expense claim is wrong. Read the FAIL lines above.\n";
    exit(1);
}

echo "\nAll good. Nobody decides or pays their own claim, and no claim waits for its own claimant.\n";
exit(0);
