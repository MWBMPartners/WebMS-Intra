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
const EXPECTED_CHECKS = 50;

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
$noAuthority = "if (\$isAdmin === false && App::hasRole('Approver') === false) {\n"
    . "    \$approverDepts = Departments::approverDepts(\$mysqli, \$userId, \$siteId);\n"
    . "    if (count(\$approverDepts) === 0) {\n"
    . "        \$_SESSION['flash_msg']  = 'Access denied — Approver or Admin role required.';\n"
    . "        \$_SESSION['flash_type'] = 'danger';\n"
    . "        header('Location: ' . Router::url('expenses/approve'));\n"
    . "        exit();\n"
    . "    }\n"
    . "}\n";
$rawApprove = (string) @file_get_contents($apps . 'expenses/approve/save.php');
check(
    'the refusal for somebody with no authority at all is exactly what it was',
    str_contains($rawApprove, $noAuthority) === true
);
check(
    '…and still comes before any claim is looked up, so it reveals nothing about which claims exist',
    at($approveSave, "'Access denied — Approver or Admin role required.'") >= 0
        && at($approveSave, "'Access denied — Approver or Admin role required.'") < at($approveSave, 'FROM tblExpenseClaims EC')
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
check(
    'approve/index.php: BOTH list queries (the administrator\'s and the approver\'s) leave the viewer\'s own claims out',
    substr_count($approveList, "WHERE EC.status = 'Pending' AND EC.siteID = ? AND EC.userID <> ? ") === 2
        && substr_count($approveList, "EC.status = 'Pending'") === 2
);
check(
    '…and each binds the signed-in account to that question mark',
    str_contains($approveList, "\$stmt->bind_param('ii', \$siteId, \$currentUserId);") === true
        && str_contains($approveList, "\$stmt->bind_param('iii', \$currentUserId, \$siteId, \$currentUserId);") === true
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
    str_contains($claimPage, "\$claim['status'] === 'Pending' && \$isClaimant === false && (\$hasApprover === true || \$isAdmin === true || \$hasDeptFlag === true)") === true
        && str_contains($claimPage, "\$claim['status'] === 'Approved' && \$isClaimant === false && (\$hasTreasurer === true || \$isAdmin === true)") === true
        && str_contains($claimPage, "\$isClaimant    = ((int) \$claim['userID'] === \$currentUserId);") === true
);
check(
    'the claim page tells a claimant who could otherwise have decided the claim who does decide it, with the rule\'s own sentence',
    str_contains($claimPage, "\$claim['status'] === 'Pending' && \$isClaimant === true && (\$hasApprover === true || \$isAdmin === true || \$hasDeptFlag === true)") === true
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
