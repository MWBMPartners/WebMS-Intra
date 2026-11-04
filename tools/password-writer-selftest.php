<?php

/**
 * -----------------------------------------------------------------------------
 * One place hashes and stores a password — proved against a real database
 * -----------------------------------------------------------------------------
 *
 * Path: tools/password-writer-selftest.php
 *
 * WHAT THIS PROVES, AND WHY IT IS NOT THE AUDIT SCRIPT'S JOB
 * ---------------------------------------------------------
 * `tools/audit-checks/check_one_password_writer.py` proves that nothing ELSE
 * stores a password. It cannot prove that `Auth::setPassword()` does it
 * correctly — and a guard that points at the only writer, while that writer is
 * wrong, is worse than no guard, because it stops anybody looking.
 *
 * So this runs the real method against a real MySQL, and reads every answer
 * back out of the database rather than believing the return value.
 *
 * THE THREE THINGS THAT MATTER MOST
 * ---------------------------------
 *   1. **A password stored for an account with NO sign-in row is REFUSED**,
 *      not silently dropped. That is #586. Before #728 a caller ran
 *      `UPDATE ... WHERE userID = ?`, which affects nought rows and reports
 *      nothing — and `affected_rows` could not have told it either, because
 *      that answers 0 both for "no such row" and for "the hash was already
 *      that". Section C.
 *   2. **The read-back compares the HASH, not its length.** `admin/users/save.php`
 *      used to read `LENGTH(passwordHash)` and accept any non-zero answer, so
 *      an OLD hash left behind by a write that never landed would have passed.
 *      Section D plants exactly that state.
 *   3. **"Unusable" is the EMPTY string**, because that is the marker this
 *      portal already enforces — `Auth::loginLocal()` refuses an empty hash
 *      before `password_verify()` is reached, `_apps/offboarding/do.php` writes
 *      it, and the disaster-recovery runbook reads `passwordHash <> ''` as
 *      "this account has a password". A random hash nobody can match is NOT
 *      equivalent: nothing can recognise it. Section E.
 *
 * WHAT THIS CANNOT DO, said plainly because an overstated guarantee is worse
 * than none
 * --------------------------------------------------------------------------
 *   * **It cannot test two requests racing to create the same sign-in name.**
 *     That needs two connections and real overlap; the name is looked for and
 *     then inserted with no lock between, so the second INSERT throws on the
 *     unique key. That is stated in `Auth::setPassword()`'s own docblock and is
 *     deliberately not hidden here.
 *   * **It does not drive any PAGE.** Whether each converted caller handles a
 *     refusal properly is a question for that page's own test; what is proved
 *     here is that the refusal happens at all.
 *   * **It cannot prove the installer's `require_once` works**, because that
 *     needs the installer's own request. What it does prove is the premise that
 *     line rests on: the class loads alone and these methods touch nothing else.
 *     Section A.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_pwwriter SELFTEST_DB_PORT=… \
 *           php tools/password-writer-selftest.php
 *
 * @package   WebMS-Intra
 * @author    Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
 * @copyright All Rights Reserved -- MWBM Partners Ltd
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/728
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 56;

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    exit(1);
}

$failures = 0;
$passes   = 0;

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

function head(string $text): void
{
    echo "\n" . $text . "\n";
}

// =============================================================================
// Section A — the class loads ALONE, which is what the installer depends on
// =============================================================================
//
// 🧱 `web/_install/index.php` has NO autoloader: it requires every class it
//    uses by hand, and its own comment says it "can't autoload
//    \Portal\Core\Migrator". So `Auth::setPassword()` being callable there
//    rests entirely on `Auth.php` loading with nothing else present. That is
//    checked here rather than assumed, because `php -l` cannot see it and the
//    failure would be a fatal error on EVERY installation.

head('A. Auth.php loads with nothing else, which is what the installer relies on');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'Auth.php';

check('A1 the class exists after requiring Auth.php alone', class_exists('Portal\\Core\\Auth'));
check('A2 setPassword() is there', method_exists('Portal\\Core\\Auth', 'setPassword'));
check('A3 setUnusablePassword() is there', method_exists('Portal\\Core\\Auth', 'setUnusablePassword'));

// 🔍 The three methods must reference no class but `self`, or the installer's
//    one require would not be enough. Read out of the source, because a method
//    that merely COMPILES tells you nothing about what it reaches at run time.
/** Comments blanked, string literals kept, every position unmoved. */
function pw_withoutComments(string $code): string
{
    $out = '';
    $len = strlen($code);
    for ($i = 0; $i < $len; $i++) {
        $ch = $code[$i];
        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            $out  .= $ch;
            $i++;
            while ($i < $len) {
                if ($code[$i] === '\\' && $i + 1 < $len) {
                    $out .= substr($code, $i, 2);
                    $i   += 2;
                    continue;
                }
                $out .= $code[$i];
                if ($code[$i] === $quote) {
                    break;
                }
                $i++;
            }
            continue;
        }
        if (substr($code, $i, 2) === '//') {
            $end  = strpos($code, "\n", $i);
            $end  = $end === false ? $len : $end;
            $out .= str_repeat(' ', $end - $i);
            $i    = $end - 1;
            continue;
        }
        if (substr($code, $i, 2) === '/*') {
            $end  = strpos($code, '*/', $i + 2);
            $end  = $end === false ? $len : $end + 2;
            $out .= (string) preg_replace('/[^\n]/', ' ', substr($code, $i, $end - $i));
            $i    = $end - 1;
            continue;
        }
        $out .= $ch;
    }

    return $out;
}

$authSrc = (string) file_get_contents(
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'Auth.php'
);

/**
 * The body of one method, by walking braces, with COMMENTS REMOVED FIRST and
 * string literals stepped over.
 *
 * WHY THE COMMENTS GO FIRST, AND IT IS NOT TIDINESS: this walk had the exact
 * fault `.claude/CLAUDE.md` names, and it fired. A first version stepped over
 * string literals but read a `//` comment as ordinary code, so the apostrophe
 * in "a caller's transaction" opened a quote that never closed, the walk
 * sailed past the end of `storeHash()`, and check A4 reported that method
 * reaching `App::settings()` -- which lives in `validatePassword()`, three
 * methods further down. The same shape has been found twice before in this
 * repository's own audit checks. It was found here the only way it can be: by
 * a comment in the code under test gaining an apostrophe.
 *
 * Comments become spaces of the same length, and newlines are kept, so no
 * position moves.
 */
$bodyOf = static function (string $src, string $name): string {
    $src = pw_withoutComments($src);
    if (preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }
    $at    = (int) $m[0][1];
    $depth = 0;
    $begun = false;
    $len   = strlen($src);
    for ($i = $at; $i < $len; $i++) {
        $ch = $src[$i];
        // 🚶 Step over a string literal: a `{` or `}` inside a message would
        //    otherwise swallow the rest of the method, which is this
        //    repository's named brace-walk fault (#647).
        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            $i++;
            while ($i < $len) {
                if ($src[$i] === '\\') {
                    $i++;
                } elseif ($src[$i] === $quote) {
                    break;
                }
                $i++;
            }
            continue;
        }
        if ($ch === '{') {
            $depth++;
            $begun = true;
        } elseif ($ch === '}') {
            $depth--;
            if ($begun === true && $depth === 0) {
                return substr($src, $at, $i - $at + 1);
            }
        }
    }

    return '';
};

foreach (['setPassword', 'setUnusablePassword', 'storeHash'] as $method) {
    $body = $bodyOf($authSrc, $method);
    preg_match_all('/(?<![\\\\A-Za-z0-9_$>])([A-Z][A-Za-z0-9_]*)::/', $body, $refs);
    $outside = array_values(array_diff(array_unique($refs[1]), ['self']));
    check(
        'A4 ' . $method . '() reaches no class but self, so one require_once is enough',
        $body !== '' && count($outside) === 0,
        $body === '' ? 'the method body could not be read' : 'it reaches: ' . implode(', ', $outside)
    );
}

// =============================================================================
// Now the database.
// =============================================================================

$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

/** Make a person with no sign-in row, and answer their account number. */
$makePerson = static function (string $email) use ($mysqli): int {
    $stmt = $mysqli->prepare(
        'INSERT INTO tblUsers (fullName, emailAddress, isActive) VALUES (?, ?, 1)'
    );
    $name = 'Test ' . $email;
    $stmt->bind_param('ss', $name, $email);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
};

/** What is really stored for an account: every row, so a second one shows up. */
$rowsFor = static function (int $userId) use ($mysqli): array {
    $stmt = $mysqli->prepare(
        'SELECT `localID`, `username`, `passwordHash`, `isVerified` '
        . 'FROM `tblLocalAccounts` WHERE `userID` = ? ORDER BY `localID`'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $out = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $out;
};

use Portal\Core\Auth;

// =============================================================================
// Section B — the ordinary case: a password is stored, and it really works
// =============================================================================

head('B. A password is stored, and the stored value really verifies');

$ben    = $makePerson('ben@example.test');
$answer = Auth::setPassword($mysqli, $ben, 'correct horse battery', 'ben@example.test');

check('B1 it reports success', $answer['ok'] === true, 'why: ' . $answer['why']);
check('B2 it reports that a row was CREATED', $answer['created'] === true);
check('B3 the refusal sentence is empty on success', $answer['why'] === '');

$rows = $rowsFor($ben);
check('B4 exactly one sign-in row exists', count($rows) === 1, 'rows: ' . count($rows));
check(
    'B5 the stored hash verifies the password that was given',
    count($rows) === 1 && password_verify('correct horse battery', (string) $rows[0]['passwordHash']) === true
);
check(
    'B6 and does NOT verify a different password',
    count($rows) === 1 && password_verify('something else', (string) $rows[0]['passwordHash']) === false
);
check(
    'B7 isVerified is left at the column default (0) when not asked for',
    count($rows) === 1 && (int) $rows[0]['isVerified'] === 0,
    'isVerified: ' . ($rows[0]['isVerified'] ?? 'no row')
);

// 🔁 Setting it again UPDATES rather than adding a second row, and says so.
$again = Auth::setPassword($mysqli, $ben, 'a different password', 'ben@example.test');
check('B8 setting it again reports success', $again['ok'] === true, 'why: ' . $again['why']);
check('B9 and reports created => FALSE, because the row was already there', $again['created'] === false);
$rows = $rowsFor($ben);
check('B10 there is STILL exactly one row — no second set of sign-in details', count($rows) === 1,
    'rows: ' . count($rows));
check(
    'B11 the new password verifies',
    count($rows) === 1 && password_verify('a different password', (string) $rows[0]['passwordHash']) === true
);
check(
    'B12 and the OLD password no longer does — the point of changing it',
    count($rows) === 1 && password_verify('correct horse battery', (string) $rows[0]['passwordHash']) === false
);

// =============================================================================
// Section C — #586: an account with NO sign-in row is REFUSED, not dropped
// =============================================================================

head('C. #586 — a password for an account with no sign-in row is refused, not silently dropped');

$cara   = $makePerson('cara@example.test');
$noName = Auth::setPassword($mysqli, $cara, 'some password');

check('C1 it REFUSES when there is no row and no sign-in name was given', $noName['ok'] === false);
check('C2 it says created => false', $noName['created'] === false);
check('C3 the reason says the account has no sign-in details', str_contains($noName['why'], 'no sign-in details'));
check('C4 nothing was written', count($rowsFor($cara)) === 0, 'rows: ' . count($rowsFor($cara)));

// 🙈 The refusal must never name the password, nor any part of a hash.
check(
    'C5 the reason carries no part of the password',
    str_contains($noName['why'], 'some password') === false
);

// 🚫 An empty password is never a password.
$empty = Auth::setPassword($mysqli, $cara, '', 'cara@example.test');
check('C6 an empty password is refused', $empty['ok'] === false);
check('C7 and nothing was created by it', count($rowsFor($cara)) === 0);

// 🚫 No account named.
$noAccount = Auth::setPassword($mysqli, 0, 'x', 'nobody@example.test');
check('C8 account number 0 is refused', $noAccount['ok'] === false);

// =============================================================================
// Section D — the read-back compares the HASH, not its length
// =============================================================================

head('D. The read-back compares the hash itself, which LENGTH() could not');

// 🧪 This is the state the old `admin/users/save.php` read-back would have
//    passed: a row holding an OLD hash, 60 characters long, non-zero. Its
//    question was "is a hash present", which cannot tell a write that landed
//    from one that did not.
$dan = $makePerson('dan@example.test');
Auth::setPassword($mysqli, $dan, 'the old one', 'dan@example.test');
$before = $rowsFor($dan);
check('D1 there is a 60-character hash on file, which LENGTH() reads as "present"',
    count($before) === 1 && strlen((string) $before[0]['passwordHash']) === 60,
    'length: ' . strlen((string) ($before[0]['passwordHash'] ?? '')));

$set = Auth::setPassword($mysqli, $dan, 'the new one');
$after = $rowsFor($dan);
check('D2 setting a new password over it succeeds', $set['ok'] === true, 'why: ' . $set['why']);
check('D3 the hash on file CHANGED', (string) $before[0]['passwordHash'] !== (string) $after[0]['passwordHash']);
check('D4 the new password verifies against what is on file',
    password_verify('the new one', (string) $after[0]['passwordHash']) === true);

// =============================================================================
// Section E — "unusable" is the EMPTY marker this portal enforces
// =============================================================================

head('E. An unusable password is the empty marker, which the portal can recognise');

$eve = $makePerson('eve@example.test');
$un  = Auth::setUnusablePassword($mysqli, $eve, 'eve@example.test', false);

check('E1 it reports success', $un['ok'] === true, 'why: ' . $un['why']);
$rows = $rowsFor($eve);
check('E2 a row exists, so the forgot-password flow can still find the account', count($rows) === 1);
check(
    'E3 the stored hash is the EMPTY string — the marker offboarding, the runbook and '
        . 'loginLocal() all read as "no password"',
    count($rows) === 1 && (string) $rows[0]['passwordHash'] === '',
    'stored length: ' . strlen((string) ($rows[0]['passwordHash'] ?? 'x'))
);
check(
    'E4 no password verifies against it',
    count($rows) === 1 && password_verify('anything at all', (string) $rows[0]['passwordHash']) === false
);
check(
    'E5 nor does an empty password — measured, because "nobody can match it" is the whole claim',
    count($rows) === 1 && password_verify('', (string) $rows[0]['passwordHash']) === false
);

// ✅ And a real password can be set over it afterwards, which is the route
//    `users/api/create.php` promises: the person uses the reset link.
$later = Auth::setPassword($mysqli, $eve, 'chosen later');
check('E6 a real password can be set over the marker afterwards', $later['ok'] === true, 'why: ' . $later['why']);
check('E7 and it is an UPDATE, not a second row', $later['created'] === false);
$rows = $rowsFor($eve);
check('E8 exactly one row still', count($rows) === 1);
check('E9 and the chosen password verifies',
    count($rows) === 1 && password_verify('chosen later', (string) $rows[0]['passwordHash']) === true);

// =============================================================================
// Section F — the two honest refusals on a sign-in name
// =============================================================================

head('F. A sign-in name that is too long, or already in use, is refused in words');

$fay  = $makePerson('fay@example.test');
$long = str_repeat('a', 51) . '@example.test';
$tooLong = Auth::setPassword($mysqli, $fay, 'a password', $long);
check('F1 a name longer than 50 characters is refused', $tooLong['ok'] === false);
check('F2 the reason says how long it may be and how long this one is',
    str_contains($tooLong['why'], '50') && str_contains($tooLong['why'], (string) mb_strlen($long)),
    'why: ' . $tooLong['why']);
check('F3 nothing was written', count($rowsFor($fay)) === 0);

// 🙈 #521: a clash must NEVER say whose name it is.
$clash = Auth::setPassword($mysqli, $fay, 'a password', 'ben@example.test');
check('F4 a name already in use is refused', $clash['ok'] === false);
check('F5 the reason does NOT name the person holding it (#521)',
    str_contains($clash['why'], 'ben@example.test') === false
    && stripos($clash['why'], 'Test ben') === false,
    'why: ' . $clash['why']);
check('F6 nothing was written for the second person', count($rowsFor($fay)) === 0);

// =============================================================================
// Section G — more than one set of sign-in details is REFUSED, not chosen
// =============================================================================

head('G. An account with two sets of sign-in details is refused rather than one being chosen');

// 🧪 This state is reachable by nothing in the portal; it is made by hand here
//    because the refusal that guards it would otherwise be exercised by
//    nothing, and a guard no fixture can trip is not a guard.
$gus = $makePerson('gus@example.test');
Auth::setPassword($mysqli, $gus, 'first', 'gus-one@example.test');
$second = $mysqli->prepare(
    'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`) VALUES (?, ?, ?)'
);
$secondName = 'gus-two@example.test';
$secondHash = password_hash('second', PASSWORD_DEFAULT);
$second->bind_param('iss', $gus, $secondName, $secondHash);
$second->execute();
$second->close();

check('G1 the account really has two rows now', count($rowsFor($gus)) === 2, 'rows: ' . count($rowsFor($gus)));
$refused = Auth::setPassword($mysqli, $gus, 'a third password');
check('G2 setting a password is REFUSED rather than one row being chosen', $refused['ok'] === false);
check('G3 the reason says why it matters — the other set would stay usable',
    str_contains($refused['why'], 'other set usable') || str_contains($refused['why'], 'other set'),
    'why: ' . $refused['why']);

$rows = $rowsFor($gus);
check('G4 BOTH old passwords still verify, because nothing was changed',
    count($rows) === 2
    && password_verify('first', (string) $rows[0]['passwordHash']) === true
    && password_verify('second', (string) $rows[1]['passwordHash']) === true);

// =============================================================================
// Section H — the read-back really compares the HASH, proved by making the
//             write land as something else
// =============================================================================
//
// 🎯 THIS SECTION EXISTS BECAUSE I PLANTED THE FAULT AND SECTIONS A-G DID NOT
//    CATCH IT. Weakening the read-back from
//
//        if ($stored === null || (string) $stored['passwordHash'] !== $hash)
//    to
//        if ($stored === null)
//
//    — which is exactly the question the old `admin/users/save.php` asked with
//    `LENGTH(passwordHash)` — left this test at **49 passed, 0 failed**. A
//    test written to prove the read-back is stronger than LENGTH() could not
//    tell the two apart. That is the "test that passes on broken code" fault,
//    in the test built to stop it.
//
//    **Why sections A-G could not see it.** They all check the database before
//    and after, so they prove the write LANDED. The read-back only matters in
//    the one state they never produce: the write lands, a row exists, and the
//    value is NOT the hash we made. Nothing in an ordinary run reaches that.
//
//    **So the state is made deliberately, with a database trigger.** A
//    `BEFORE UPDATE` trigger that overwrites `passwordHash` stands in for
//    every real cause of the same thing — a truncation, a replica that answers
//    stale, a column altered under us. The method must REFUSE, because what
//    is on file is not what it stored; and the account must be left in a state
//    the portal can describe honestly rather than reported as saved.
//
//    A trigger needs the privilege to create one. Where that is refused the
//    section says so and is SKIPPED rather than passed — a skipped check is
//    not a pass, which is why the runner prints the two separately.

head('H. The read-back compares the hash, proved by making the write land as something else');

$triggerMade = false;
try {
    $mysqli->query('DROP TRIGGER IF EXISTS `pw_writer_selftest_mangle`');
    $mysqli->query(
        'CREATE TRIGGER `pw_writer_selftest_mangle` BEFORE UPDATE ON `tblLocalAccounts` '
        . "FOR EACH ROW SET NEW.`passwordHash` = 'not-the-hash-that-was-stored'"
    );
    $triggerMade = true;
} catch (\Throwable $ex) {
    echo "  SKIP  H1-H4 — this database will not let the test create a trigger, so the one state\n";
    echo "          the read-back exists to detect cannot be made. A SKIP is not a pass.\n";
    echo '          (' . $ex->getMessage() . ")\n";
}

if ($triggerMade === true) {
    $hal = $makePerson('hal@example.test');
    // A row first, WITHOUT the trigger interfering: the trigger is BEFORE
    // UPDATE only, so the creating INSERT lands normally.
    $created = Auth::setPassword($mysqli, $hal, 'the first one', 'hal@example.test');
    check('H1 the row is created normally (the trigger only bites on an UPDATE)',
        $created['ok'] === true && $created['created'] === true, 'why: ' . $created['why']);

    // 🧪 Now an UPDATE, which the trigger will land as something else.
    $mangled = Auth::setPassword($mysqli, $hal, 'the second one');
    check(
        'H2 setting a password whose write lands as a DIFFERENT value is REFUSED — this is the check '
            . 'that LENGTH(passwordHash) could not make, and the one a weakened read-back fails',
        $mangled['ok'] === false,
        'it reported ok => ' . var_export($mangled['ok'], true) . '; why: ' . $mangled['why']
    );
    check(
        'H3 the refusal does NOT claim nothing was changed, because that is not known',
        str_contains($mangled['why'], 'nothing was changed') === false
        && str_contains($mangled['why'], 'could not be confirmed as stored') === true,
        'why: ' . $mangled['why']
    );
    // 🔍 And what is really on file is the mangled value, which proves the
    //    fixture did what it claims rather than the method merely answering no.
    $halRows = $rowsFor($hal);
    check(
        'H4 and the fixture really did mangle the write — otherwise H2 would be refusing for some other reason',
        count($halRows) === 1 && (string) $halRows[0]['passwordHash'] === 'not-the-hash-that-was-stored',
        'on file: ' . var_export($halRows[0]['passwordHash'] ?? null, true)
    );

    $mysqli->query('DROP TRIGGER IF EXISTS `pw_writer_selftest_mangle`');
}

// =============================================================================
// Section I — TWO REQUESTS AT ONCE cannot create two sets of sign-in details
// =============================================================================
//
// 🔒 THIS SECTION EXISTS BECAUSE I CLAIMED THE RACE WAS IMPOSSIBLE AND IT WAS
//    NOT. The first version of `storeHash()` said "Nothing in this portal
//    creates a second one" in five places. An independent check measured it
//    false, and I reproduced it: two connections, each in its own transaction,
//    both setting a password for the SAME account with DIFFERENT sign-in
//    names, **both reported success and the account ended with two rows.**
//    Under REPEATABLE READ neither transaction can see the other's insert, so
//    neither `COUNT(*)` finds a clash.
//
//    The harm is not the duplicate row. It is what follows: that account can
//    never have a password set through the one writer again — it refuses,
//    correctly — while BOTH old passwords go on working. So a member who
//    changes their password because they fear their account is compromised
//    cannot, and the old one still signs in.
//
//    **The unique key does not cover it.** It is on `username`, so it catches
//    only the case where the two names MATCH, which is the harmless one.
//
//    IT TAKES TWO REAL PROCESSES. Two connections driven one after the other
//    from a single process cannot test this: the second call simply waits on
//    the first, which is still open, and the run dies of a lock-wait timeout
//    — measured while building this. That is why `tools/expense-payment-race-selftest.php`
//    uses real concurrency for the same kind of question, and why section J of
//    `tools/erasure-pages-selftest.php` says plainly that a one-worker HTTP
//    harness CANNOT test a race.

head('I. Two requests at once cannot create two sets of sign-in details');

$ivy = $makePerson('ivy@example.test');

// 🏃 A tiny worker, run twice at once. Each opens its own connection, starts
//    its own transaction, and asks for a password with its OWN sign-in name.
$worker = <<<'WORKER'
<?php
declare(strict_types=1);
require getenv('PW_AUTH');
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = new mysqli(getenv('PW_HOST'), getenv('PW_USER'), getenv('PW_PASS'), getenv('PW_NAME'), (int) getenv('PW_PORT'));
$db->begin_transaction();
try {
    $out = \Portal\Core\Auth::setPassword(
        $db,
        (int) getenv('PW_USERID'),
        'a password',
        getenv('PW_SIGNNAME')
    );
    $db->commit();
    echo ($out['ok'] === true ? ($out['created'] === true ? 'created' : 'updated') : 'refused') . "\n";
} catch (\Throwable $ex) {
    $db->rollback();
    // A lock-wait timeout is the HONEST outcome for the request that lost, and
    // it must leave no row behind. The caller rolls back, which every real
    // caller of this method does.
    echo "threw\n";
}
WORKER;
$workerPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pw-race-worker-' . getmypid() . '.php';
file_put_contents($workerPath, $worker);

$common = [
    'PW_AUTH'   => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
                   . DIRECTORY_SEPARATOR . 'Auth.php',
    'PW_HOST'   => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'PW_USER'   => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'PW_PASS'   => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'PW_NAME'   => $dbName,
    'PW_PORT'   => getenv('SELFTEST_DB_PORT') !== false ? (string) getenv('SELFTEST_DB_PORT') : '3306',
    'PW_USERID' => (string) $ivy,
];

$answers = [];
$handles  = [];
foreach (['ivy-one@example.test', 'ivy-two@example.test'] as $index => $signName) {
    $env = $common + ['PW_SIGNNAME' => $signName];
    $handles[$index] = proc_open(
        [PHP_BINARY, $workerPath],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes[$index],
        null,
        $env + ['PATH' => (string) getenv('PATH')]
    );
}
foreach ($handles as $index => $handle) {
    $answers[$index] = trim((string) stream_get_contents($pipes[$index][1]));
    fclose($pipes[$index][1]);
    fclose($pipes[$index][2]);
    proc_close($handle);
}
@unlink($workerPath);

$ivyRows = $rowsFor($ivy);
check(
    'I1 after two requests at once, the account has EXACTLY ONE set of sign-in details',
    count($ivyRows) === 1,
    count($ivyRows) . ' row(s); the two workers answered: ' . implode(', ', $answers)
);
// 🎯 WHAT THE RIGHT ANSWER ACTUALLY IS — and my first version of this check got
//    it wrong in the PESSIMISTIC direction. It asserted that exactly ONE of
//    the two may report success, i.e. that the loser is turned away. Measured:
//    BOTH report success, and that is correct and better. The lock makes the
//    second request WAIT; by the time it looks, the first has committed its
//    row, so the second finds a row and UPDATES it. Nobody is refused, and the
//    account ends with one set of sign-in details.
//
//    So what must be asserted is that at most one of them CREATED a row —
//    which is the fault — not that one of them failed. A check demanding a
//    failure the fix removes would have to be weakened the day the code got
//    better, which is how a test ends up fighting its own subject.
check(
    'I2 at most ONE of the two CREATED the row; a request that waited for the lock went on to update, not insert',
    count(array_filter($answers, static fn (string $a): bool => $a === 'created')) <= 1,
    'answers: ' . implode(', ', $answers)
);
// ✅ And the account is still usable afterwards, which is the whole point: the
//    two-row state is what makes it permanently unusable.
$afterRace = Auth::setPassword($mysqli, $ivy, 'a later password');
check(
    'I3 and a password can still be set for that account afterwards — the two-row state would refuse for ever',
    $afterRace['ok'] === true,
    'why: ' . $afterRace['why']
);

// =============================================================================
echo "\n";
echo $passes . ' passed, ' . $failures . " failed.\n";
if ($passes + $failures !== EXPECTED_CHECKS) {
    echo 'EXPECTED ' . EXPECTED_CHECKS . ' checks, ran ' . ($passes + $failures)
        . " — a block was skipped or ran twice.\n";
    exit(1);
}
exit($failures === 0 ? 0 : 1);
