<?php
// Path: tools/self-service-password-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A member adds a password to their own account — self-test 🔑 (#731)
 * -----------------------------------------------------------------------------
 * WHAT THIS IS ABOUT. A member whose account signs in ONLY through Microsoft,
 * Google or a passkey had no route to a password at all, so losing that one way
 * in locked them out for good — and an administrator may not type a password
 * for somebody else (the owner's decision, D-018 / #586). The new route lets
 * the account's OWNER add one, and only after proving, by a method they really
 * hold, that they are still the person at the keyboard.
 *
 * This is a security feature on an authentication path. A mistake here either
 * gives somebody a second way into an account they should not have, or locks a
 * real member out. So almost nothing below is read: it is run.
 *
 * HOW IT RUNS. Sections A and B call the real class and the real `Auth` method
 * directly, with no database and no web server, because a rule about facts and
 * a rule about a session are both exactly testable that way. Everything from C
 * onwards copies `web/` into a scratch folder, gives the copy a throwaway
 * connection file, starts PHP's own web server behind a small router that
 * imitates `.htaccess`, and presses the real buttons with real cookies. The
 * harness comes from `tools/admin-password-selftest.php`.
 *
 *   A. The rule, on its own. Above all: the INSTALLATION's "no" beats an
 *      organisation's "yes", and an installation-wide value that is MISSING
 *      counts as no.
 *   B. The proof, with a real session: five minutes, single use, the right
 *      person, a known method, and a proof from the future refused.
 *   C. The made-up world is really as these checks need it.
 *   D. The ordinary case, over real HTTP: a member with no password adds one
 *      and can then REALLY sign in with it, while the Microsoft link and every
 *      passkey survive untouched.
 *   E. With no fresh proof it is REFUSED — including a POST with a perfectly
 *      good form token, which is the shape that matters.
 *   F. A proof older than five minutes is refused, and a spent one cannot be
 *      used again.
 *   G. The two levels, over real HTTP.
 *   H. An administrator still cannot do it for somebody else.
 *   I. The plants. Six faults are written into the SCRATCH copy, one at a
 *      time, each proved applied, and each required to turn a named check red.
 *
 * HOW A MEMBER WITH NO PASSWORD IS SIGNED IN AT ALL, said plainly because it is
 * the one artificial thing here. Nothing in a test can drive a real Microsoft
 * sign-in or a real authenticator, so each person is given a password, signed
 * in through the REAL sign-in page — which records a real proof by the real
 * code path — and their sign-in row is then deleted. That leaves exactly the
 * state a Microsoft-only account is in a moment after signing in: signed in,
 * no password, a fresh proof. The only difference is the word the proof carries
 * ('password' rather than 'Microsoft'), and checks A15 to A21 cover the other
 * three paths by asking which files call `recordProof()` and nothing else.
 *
 * WHAT THIS CANNOT DO — read this before trusting a pass
 *   - **It cannot drive a real Microsoft, Google or passkey sign-in**, so the
 *     end-to-end journey "press Sign in again with Microsoft, come back here,
 *     add the password" is NOT exercised. The two halves are: the proof's
 *     recording and reading (B), and `Auth::comeBackAfterSignIn()` (B10, B11).
 *   - **It is not a browser and runs no script**, so the passkey button's own
 *     script is never executed. That the page CARRIES it, with the nonce
 *     without which the browser silently refuses it, is checked; that it works
 *     is not.
 *   - **One worker** (`PHP_CLI_SERVER_WORKERS` is unset), so two requests never
 *     overlap. Two people adding a password at the same moment is therefore not
 *     tested, and such a test would PASS ON THE BROKEN CODE (#647).
 *
 *     **BE EXACT ABOUT WHAT PROTECTS IT — a draft of this said "the
 *     transaction and the lock `Auth::setPassword()` takes", and that is
 *     untrue in the reassuring direction.** The lock makes two simultaneous
 *     adds SEQUENTIAL; it does not refuse the second. `storeHash()`'s
 *     existing-row branch would then simply UPDATE and answer `ok => true`, so
 *     the second request's password would silently replace the first's and
 *     both would be told "Your password is set".
 *
 *     What actually refuses it is **the second `whyNotAllowed()` call inside
 *     the transaction** (`add-password-save.php`), whose own comment says so:
 *     the fact that matters is "does this account already have a usable
 *     password", read on facts fetched inside the lock. The lock is what makes
 *     that read trustworthy; the refusal is what does the work.
 *
 *     That second ask was pinned by NOTHING — an independent check deleted the
 *     whole `$why !== ''` block inside the transaction and this test stayed
 *     green. A race test is still the wrong remedy for the reason above, so
 *     what is owed is a check on the rule's SECOND call, not on the race.
 *   - **It says nothing about the e-mail really arriving.** The route is
 *     deliberately pointed at an SMTP mailbox that is not set up, so what is
 *     checked is that a message was addressed to the right person and that the
 *     password was stored anyway.
 *   - It signs people in through PHP's built-in server, not Apache, and the
 *     router only imitates `.htaccess`.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/self-service-password-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9088). The database must already
 *         hold `web/_sql/full_schema.sql`.
 * Exit:   0 only when every check that was meant to run ran and passed.
 *
 * WHAT IT CHANGES, AND PUTS BACK. In the database: made-up people numbered
 * 931001 to 931999 and their rows, removed by that number at the START (in case
 * a run was interrupted) and at the END; and three installation-wide settings
 * rows, whose values are recorded first and written back. On disk: one scratch
 * folder of its own, removed while it still holds this run's marker. It never
 * opens `web/_auth_keys`. IT NEVER PRINTS A PASSWORD OR A HASH: where it has to
 * know whether a password was stored it asks `password_verify()`.
 *
 * @package   Portal\Tools
 * @author    Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/731
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED = 93;

// 👥 The made-up people. Every one of these numbers is this test's own.
const ADMIN   = 931001; // a global administrator, who must NOT be able to do it for somebody else
const SSOONLY = 931002; // the main case: a Microsoft link and a passkey, no password
const HASPWD  = 931003; // a member who keeps a password throughout: nothing to add
const KEYONLY = 931004; // a passkey and nothing else
const NOWAY   = 931005; // no password, no provider, no passkey: nothing to prove with
const LEVELS  = 931006; // used by section G, whose organisation gets its own settings row
const REUSE   = 931007; // sections E, F, H and every plant reuse this one

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open', 'mb_strlen'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}

$sep  = DIRECTORY_SEPARATOR;
$repo = (string) realpath(dirname(__DIR__));

/** The three files this test is about. A missing one is a refusal, not a failure. */
$classFile   = $repo . $sep . 'web' . $sep . '_core' . $sep . 'SelfServicePassword.php';
$viewFile    = $repo . $sep . 'web' . $sep . '_apps' . $sep . 'auth' . $sep . 'account' . $sep . 'add-password.php';
$handlerFile = $repo . $sep . 'web' . $sep . '_apps' . $sep . 'auth' . $sep . 'account' . $sep . 'add-password-save.php';
foreach ([$classFile, $viewFile, $handlerFile] as $needed) {
    if (is_file($needed) === false) {
        refuse('a file this test is about is not there: ' . $needed);
    }
}

// 🧵 A SESSION FOR SECTION B, STARTED HERE AND NOT THERE — and the reason is
//    worth knowing. `session_start()` sends a cookie header, and PHP refuses to
//    start a session once ANYTHING has been printed, on the command line as
//    much as in a request. Section B is about forty lines of output further
//    down, so starting it there failed with "Session save path cannot be
//    changed after headers have already been sent" and the whole run refused.
//    It costs nothing to start it first: the session is this process's own, in
//    a folder of its own, and is closed and removed at the end of section B.
$sessionDir = sys_get_temp_dir() . $sep . 'ssp-selftest-session-' . getmypid();
if (@mkdir($sessionDir, 0700) === false) {
    refuse('could not make a folder for this process\'s own session');
}
session_save_path($sessionDir);
if (@session_start() === false) {
    refuse('this PHP could not start a session, which section B needs');
}

$pass = 0;
$fail = 0;

/** ✅ Report one check. `$detail` is shown only on failure. */
function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail;
    if ($passed === true) {
        $pass++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $fail++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

function head(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

echo "A member adds a password to their own account — self-test (#731)\n";
echo 'PHP ' . PHP_VERSION . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

// =============================================================================
head('A. The rule, with no database and no request');
// =============================================================================
// 🧱 The class is required on its own. `allowedFrom()`, `whyNotAllowed()` and
//    `methodsToProveWith()` take what they judge, so they can be asked here
//    exactly as the page and the handler ask them — which is the whole point of
//    a rule that takes FACTS rather than a connection (#647).

require_once $classFile;

use Portal\Core\SelfServicePassword as SSP;

ok('A01 the class loads on its own', class_exists('Portal\\Core\\SelfServicePassword'));

/** A settings layer holding one value for this setting. */
$layer = static fn (mixed $value): array => ['auth' => ['password' => ['selfAdd' => $value]]];

ok('A02 both levels say yes: allowed', SSP::allowedFrom($layer('1'), $layer('1')) === true);
ok(
    'A03 the INSTALLATION says no and the organisation says yes: NOT allowed — the stricter level wins (D-026)',
    SSP::allowedFrom($layer('1'), $layer('0')) === false
);
ok(
    'A04 the installation says yes and the organisation says no: NOT allowed — an organisation may still refuse',
    SSP::allowedFrom($layer('0'), $layer('1')) === false
);
ok('A05 an EMPTY installation layer: NOT allowed', SSP::allowedFrom([], $layer('1')) === false);
ok(
    'A06 the key missing from the installation layer: NOT allowed, even with the organisation saying yes',
    SSP::allowedFrom(['auth' => ['password' => []]], $layer('1')) === false
);

$yesValues = ['1', 'true', 'TRUE', ' yes ', 'on', 1, true];
$yesAll    = true;
foreach ($yesValues as $value) {
    $yesAll = $yesAll && SSP::allowedFrom($layer($value), $layer($value)) === true;
}
ok('A07 every value that clearly says yes is read as yes', $yesAll, 'one of: ' . implode(', ', array_map('strval', $yesValues)));

$noValues = ['', '0', 'false', 'maybe', '2', 0, false, null, [], ['1']];
$noAll    = true;
foreach ($noValues as $value) {
    $noAll = $noAll && SSP::allowedFrom($layer($value), $layer('1')) === false;
}
ok('A08 anything else at the installation level is read as no', $noAll);

/** Facts for somebody who may add a password; each check below spoils one of them. */
$good = [
    'userId' => 42, 'email' => 'someone@example.test', 'hasUsablePassword' => false,
    'hasSignInRow' => false, 'providers' => ['ms365'], 'passkeys' => 0,
    'allowed' => true, 'provedThemselves' => true,
];

ok('A09 everything in place: the rule says yes (the empty string)', SSP::whyNotAllowed($good) === '');
ok('A10 nobody signed in: refused', SSP::whyNotAllowed(['userId' => 0] + $good) === SSP::MSG_NOT_SIGNED_IN);
ok(
    'A11 a usable password already: refused, and said BEFORE the policy refusal',
    SSP::whyNotAllowed(['hasUsablePassword' => true, 'allowed' => false] + $good) === SSP::MSG_ALREADY_HAS_ONE
);
ok('A12 the portal does not offer it: refused', SSP::whyNotAllowed(['allowed' => false] + $good) === SSP::MSG_NOT_ALLOWED_HERE);
ok(
    'A13 nothing to prove themselves with: refused, and said BEFORE the proof is asked for',
    SSP::whyNotAllowed(['providers' => [], 'passkeys' => 0, 'provedThemselves' => false] + $good) === SSP::MSG_NO_WAY_TO_PROVE
);
ok('A14 no fresh proof: refused last of all', SSP::whyNotAllowed(['provedThemselves' => false] + $good) === SSP::MSG_NEEDS_PROOF);

ok('A15 Microsoft only is offered as Microsoft', SSP::methodsToProveWith(['providers' => ['ms365'], 'passkeys' => 0]) === ['Microsoft']);
ok('A16 Google only is offered as Google', SSP::methodsToProveWith(['providers' => ['google'], 'passkeys' => 0]) === ['Google']);
ok('A17 a passkey only is offered as a passkey', SSP::methodsToProveWith(['providers' => [], 'passkeys' => 2]) === ['passkey']);
ok(
    'A18 all three are offered, in the page\'s order',
    SSP::methodsToProveWith(['providers' => ['google', 'ms365'], 'passkeys' => 1]) === ['Microsoft', 'Google', 'passkey']
);
ok('A19 nothing at all is offered as nothing', SSP::methodsToProveWith(['providers' => [], 'passkeys' => 0]) === []);

// 🛂 WHERE A PROOF MAY COME FROM. This is the heart of the feature: a proof
//    recorded by anything other than a path that has really just
//    re-authenticated somebody would make the whole gate decorative, because a
//    session can be borrowed. So the call sites are counted out of the source.
$callers = [];
$tree    = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS)
);
foreach ($tree as $path => $info) {
    if ($info->isFile() === false || strtolower((string) $info->getExtension()) !== 'php') {
        continue;
    }
    $body = (string) file_get_contents((string) $path);
    // Comments are blanked first, so a mention in a note is not a call. The
    // same walk `tools/password-writer-selftest.php` uses, for the same reason:
    // an apostrophe in a `//` comment otherwise puts every later quote out of
    // step and the reader sails past the end of the file.
    $clean = (string) preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s'], ['', ''], $body);
    if (preg_match('/\brecordProof\s*\(/', $clean) === 1) {
        $callers[] = str_replace($repo . $sep, '', (string) $path);
    }
}
sort($callers);
$expectedCallers = [
    'web' . $sep . '_apps' . $sep . 'auth' . $sep . 'login' . $sep . 'webauthn.php',
    'web' . $sep . '_core' . $sep . 'Auth.php',
    'web' . $sep . '_core' . $sep . 'SelfServicePassword.php',
];
ok(
    'A20 recordProof() is called from the authentication paths and NOWHERE else',
    $callers === $expectedCallers,
    'found: ' . implode(', ', $callers)
);
$authSource = (string) file_get_contents($repo . $sep . 'web' . $sep . '_core' . $sep . 'Auth.php');
ok(
    'A21 Auth.php records it for all three of its own sign-in paths (password, Microsoft, Google)',
    substr_count($authSource, 'SelfServicePassword::recordProof(') === 3
    && str_contains($authSource, 'SelfServicePassword::METHOD_PASSWORD') === true
    && str_contains($authSource, 'SelfServicePassword::METHOD_MICROSOFT') === true
    && str_contains($authSource, 'SelfServicePassword::METHOD_GOOGLE') === true,
    substr_count($authSource, 'SelfServicePassword::recordProof(') . ' call(s)'
);
ok(
    'A22 neither of the two new pages records a proof — only a real sign-in may',
    str_contains((string) file_get_contents($viewFile), 'recordProof') === false
    && str_contains((string) file_get_contents($handlerFile), 'recordProof') === false
);

// =============================================================================
head('B. The proof itself, with a real session');
// =============================================================================
// 🧵 A real session in this process. `recordProof()` writes into `$_SESSION`
//    and `recentlyProvedThemselves()` reads it, so this exercises the real
//    thing rather than a stand-in.

require_once $repo . $sep . 'web' . $sep . '_core' . $sep . 'Auth.php';

SSP::recordProof(7001, SSP::METHOD_PASSKEY);
ok('B01 a proof is recorded and read back', SSP::recentlyProvedThemselves(7001) === true);
ok('B02 the method is reported in ordinary words', SSP::proofMethod(7001) === 'passkey');

$_SESSION[SSP::PROOF_KEY]['atUtc'] = time() - (SSP::PROOF_GOOD_FOR_SECONDS - 1);
ok('B03 a proof one second inside the five minutes is still good', SSP::recentlyProvedThemselves(7001) === true);

$_SESSION[SSP::PROOF_KEY]['atUtc'] = time() - (SSP::PROOF_GOOD_FOR_SECONDS + 1);
ok('B04 a proof one second past the five minutes is refused', SSP::recentlyProvedThemselves(7001) === false);
ok('B05 ... and the method is then reported as nothing', SSP::proofMethod(7001) === '');

$_SESSION[SSP::PROOF_KEY]['atUtc'] = time() + 60;
ok(
    'B06 a proof from the FUTURE is refused — "we cannot tell how old this is" is not "it is fresh"',
    SSP::recentlyProvedThemselves(7001) === false
);

SSP::recordProof(7001, SSP::METHOD_PASSKEY);
ok('B07 a proof naming somebody else is not a proof for this account', SSP::recentlyProvedThemselves(7002) === false);

SSP::spendProof();
ok('B08 a spent proof is refused', SSP::recentlyProvedThemselves(7001) === false);
ok('B09 ... and nothing is left in the session under its name', isset($_SESSION[SSP::PROOF_KEY]) === false);

SSP::recordProof(7001, 'something-else');
ok('B10 an unknown method is NOT recorded', isset($_SESSION[SSP::PROOF_KEY]) === false);
SSP::recordProof(0, SSP::METHOD_PASSWORD);
ok('B11 no account number means nothing is recorded', isset($_SESSION[SSP::PROOF_KEY]) === false);

// 🧪 A proof of the wrong SHAPE. A session is a file on disk and can be
//    hand-edited or restored from a backup; anything that is not what this
//    class writes is not a proof.
$_SESSION[SSP::PROOF_KEY] = ['userID' => '7001', 'method' => 'passkey', 'atUtc' => time()];
ok('B12 a proof whose account number is text, not a number, is refused', SSP::recentlyProvedThemselves(7001) === false);
$_SESSION[SSP::PROOF_KEY] = 'passkey';
ok('B13 a proof that is not even a list of facts is refused', SSP::recentlyProvedThemselves(7001) === false);
unset($_SESSION[SSP::PROOF_KEY]);

// 🔙 Where somebody comes back to after signing in again.
$_SESSION['login_redirect'] = '/one-organisation/account/add-password';
ok(
    'B14 comeBackAfterSignIn() answers the address a page stored',
    \Portal\Core\Auth::comeBackAfterSignIn() === '/one-organisation/account/add-password'
);
ok(
    'B15 ... and TAKES it, so an abandoned attempt cannot send a later sign-in there',
    isset($_SESSION['login_redirect']) === false && \Portal\Core\Auth::comeBackAfterSignIn() === '/'
);

session_write_close();
foreach ((array) glob($sessionDir . $sep . '*') as $one) {
    @unlink((string) $one);
}
@rmdir($sessionDir);

// =============================================================================
// Now the database and a real web server.
// =============================================================================
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
// 🔌 9088: a port no other test in tools/run-all-selftests.sh is given, so this
//    one can run beside them in the same job.
$port = ($portRaw === false || $portRaw === '') ? 9088 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

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
function q(string $sql, string $types = '', array $params = []): array
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** @param list<mixed> $params */
function one(string $sql, string $types = '', array $params = []): ?array
{
    return q($sql, $types, $params)[0] ?? null;
}

/** Every table this test inserts into by hand, with the column its numbers go in. */
const WORLD = [
    ['tblWebAuthnCredentials', 'userID'], ['tblLinkedAccounts', 'userID'],
    ['tblLocalAccounts', 'userID'], ['tblUserSites', 'userID'], ['tblUsers', 'userID'],
];

/**
 * Remove every row with one of this test's numbers, and every row the PORTAL
 * wrote about its people — signing in and opening pages writes rows that point
 * at a person (the activity log, for one), and the database then refuses to
 * remove them. The database is asked for every column that points at a person,
 * as `tools/admin-password-selftest.php` does.
 *
 * @return int How many such rows are LEFT, which must be none.
 */
function removeWorld(): int
{
    $columns = q(
        "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers' "
        . "UNION SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c "
        . "JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE' "
        . "WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'userID'"
    );
    foreach (WORLD as [$table, $column]) {
        $columns[] = ['t' => $table, 'c' => $column];
    }
    $left = 0;
    q('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ([1, 2] as $round) {
            foreach ($columns as $column) {
                $where = '`' . $column['c'] . '` BETWEEN 931001 AND 931999';
                if ($round === 1) {
                    q('DELETE FROM `' . $column['t'] . '` WHERE ' . $where);
                } else {
                    $left += (int) (one('SELECT COUNT(*) AS n FROM `' . $column['t'] . '` WHERE ' . $where)['n'] ?? 0);
                }
            }
        }
        q("DELETE FROM `tblEmailLog` WHERE `toRecipients` LIKE '%@selftest-731.invalid'");
        // 🚦 AND THE SIGN-IN REFUSALS FROM THIS MACHINE, which is not tidiness:
        //    `RateLimiter` blocks an ADDRESS after five `LoginFailed` records
        //    less than fifteen minutes old, and check D06 makes one on purpose
        //    (it proves the member could not sign in with the new password
        //    BEFORE adding it). Those records name nobody — `Logger::activity()`
        //    fills `userID` from the session, and a refused sign-in has none —
        //    so removing this test's people does not remove them, and the FIFTH
        //    run on one database would be refused at the sign-in step with a
        //    message about the made-up person rather than about the limit.
        //    Measured, five runs in: that is exactly what happened.
        //
        //    Safe to delete outright because every self-test here has a
        //    database of its own, and these rows are from the loopback address
        //    this run's own server is reached on.
        q(
            "DELETE FROM `tblActivityLogs` WHERE `activityType` IN ('LoginFailed', 'LoginBlocked') "
            . "AND `userID` IS NULL AND `visitorIP` IN ('127.0.0.1', '::1', 'localhost')"
        );
    } finally {
        q('SET FOREIGN_KEY_CHECKS = 1');
    }

    return $left;
}

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

$state = [
    'started' => false, 'cleaned' => false, 'proc' => null, 'child' => '',
    'token' => bin2hex(random_bytes(16)), 'left' => -1, 'settings' => [],
];

/** The installation-wide settings rows this test writes, so they can be put back exactly. */
const TOUCHED_SETTINGS = ['auth.password.selfAdd', 'mail.provider'];

/** Put back everything this run changed, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        $until = microtime(true) + 5;
        while (proc_get_status($state['proc'])['running'] === true && microtime(true) < $until) {
            usleep(50000);
        }
        if (proc_get_status($state['proc'])['running'] === true) {
            proc_terminate($state['proc'], 9);
        }
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    try {
        // The settings first: a per-organisation row points at an organisation,
        // and the installation-wide rows are restored to the value recorded
        // before anything was touched.
        q("DELETE FROM `tblSettings` WHERE `settingKey` = 'auth.password.selfAdd' AND `siteID` IS NOT NULL");
        foreach (TOUCHED_SETTINGS as $key) {
            $was = $state['settings'][$key] ?? null;
            if ($was === null) {
                q('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
                continue;
            }
            q(
                'UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL',
                'ss',
                [$was, $key]
            );
        }
        $state['left'] = removeWorld();
    } catch (Throwable $problem) {
        echo 'FAIL — the clean-up could not put the database back: ' . $problem->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.ssp-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}
register_shutdown_function('cleanup');

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed, because the answer this test reads is often the redirect. An answer
 * without this run's header came from somewhere else and is returned as
 * status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 150, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ['Expect:', 'Accept-Language: en'],
    ]);
    if ($jar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle), 'head' => ''];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Self-Service-Password-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S*)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

/** The form token on a page. */
function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

// =============================================================================
// 🏗️ The world, the scratch copy and the server
// =============================================================================
$state['started'] = true;
if (removeWorld() > 0) {
    refuse('rows with this test\'s numbers (931001 to 931999) are in the database and could not be removed');
}
$site = (int) (one('SELECT MIN(siteID) AS s FROM tblSites WHERE isActive = 1')['s'] ?? 0);
if ($site <= 0) {
    refuse('the database has no organisation: load web/_sql/full_schema.sql first');
}
foreach (TOUCHED_SETTINGS as $key) {
    $row = one('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
    $state['settings'][$key] = $row === null ? null : (string) $row['settingValue'];
}
if ($state['settings']['auth.password.selfAdd'] === null) {
    refuse('auth.password.selfAdd is not seeded installation-wide: load the current web/_sql/full_schema.sql');
}
/**
 * 📧 The mail route is pointed at SMTP with nothing filled in, on purpose.
 *
 * `Mailer::send()`'s default route is Microsoft Graph, which THROWS when it is
 * not set up; SMTP with nothing filled in answers false and writes a row to
 * `tblEmailLog` saying so. That gives this test two things it could not
 * otherwise have: proof that a message was addressed to the right person, and
 * proof that the password was stored even though the notice could not go.
 */
q(
    "INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`) VALUES ('mail.provider', 'smtp', 0, '') "
    . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
);

// 🔑 One password everybody starts with, and one that gets added. Both satisfy
//    the portal's own policy. Built fresh each run and never written down.
$oldPassword = 'Aa1!old-selftest731-' . bin2hex(random_bytes(4));
$newPassword = 'Aa1!new-selftest731-' . bin2hex(random_bytes(4));

$address = static fn (int $id): string => 'selftest-731-' . $id . '@selftest-731.invalid';

$people = [
    ADMIN   => ['name' => 'Self Service Password Administrator', 'admin' => 1],
    SSOONLY => ['name' => 'Self Service Password Microsoft',     'admin' => 0],
    HASPWD  => ['name' => 'Self Service Password Has One',       'admin' => 0],
    KEYONLY => ['name' => 'Self Service Password Passkey',       'admin' => 0],
    NOWAY   => ['name' => 'Self Service Password No Way',        'admin' => 0],
    LEVELS  => ['name' => 'Self Service Password Levels',        'admin' => 0],
    REUSE   => ['name' => 'Self Service Password Reused',        'admin' => 0],
];
foreach ($people as $id => $who) {
    q(
        'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `phoneNumber`, `isActive`, `isAdmin`, `isRootAdmin`) '
        . 'VALUES (?, ?, ?, ?, 1, ?, ?)',
        'isssii',
        [$id, $who['name'], $address($id), '01000 000000', $who['admin'], $who['admin']]
    );
    q(
        'INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, ?, ?)',
        'iiii',
        [$id, $site, $who['admin'], $who['admin']]
    );
}

// 🔗 What each person holds besides a password. SSOONLY has a Microsoft link
//    AND a passkey, which is what lets section D prove that BOTH survive;
//    KEYONLY has a passkey only, so the page's own offer can be told apart
//    from SSOONLY's; NOWAY has neither.
q(
    "INSERT INTO `tblLinkedAccounts` (`userID`, `provider`, `providerSub`, `providerEmail`) VALUES (?, 'ms365', ?, ?)",
    'iss',
    [SSOONLY, 'selftest-731-subject-' . bin2hex(random_bytes(6)), $address(SSOONLY)]
);
foreach ([SSOONLY, KEYONLY] as $withKey) {
    q(
        "INSERT INTO `tblWebAuthnCredentials` (`userID`, `credentialID`, `publicKey`, `friendlyName`) VALUES (?, ?, ?, 'Selftest key')",
        'iss',
        [$withKey, bin2hex(random_bytes(16)), bin2hex(random_bytes(32))]
    );
}
q(
    "INSERT INTO `tblLinkedAccounts` (`userID`, `provider`, `providerSub`, `providerEmail`) VALUES (?, 'ms365', ?, ?)",
    'iss',
    [LEVELS, 'selftest-731-subject-' . bin2hex(random_bytes(6)), $address(LEVELS)]
);
q(
    "INSERT INTO `tblLinkedAccounts` (`userID`, `provider`, `providerSub`, `providerEmail`) VALUES (?, 'ms365', ?, ?)",
    'iss',
    [REUSE, 'selftest-731-subject-' . bin2hex(random_bytes(6)), $address(REUSE)]
);

/**
 * Give somebody the starting password, so they can be signed in through the
 * real sign-in page. The SQL is written out here deliberately: a test under
 * `tools/` may show it, and `tools/audit-checks/check_one_password_writer.py`
 * looks only under `web/` for exactly that reason.
 */
$givePassword = static function (int $id) use ($oldPassword, $address): void {
    $hash = password_hash($oldPassword, PASSWORD_DEFAULT);
    q('DELETE FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [$id]);
    q(
        'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
        'iss',
        [$id, $address($id), $hash]
    );
};
$takePasswordAway = static function (int $id): void {
    q('DELETE FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [$id]);
};

foreach ([ADMIN, HASPWD] as $keepsOne) {
    $givePassword($keepsOne);
}

// The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . $sep . 'ssp-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.ssp-selftest-owner', $state['token']); // the marker FIRST
mkdir($child . $sep . 'web', 0700);
$webSrc = $repo . $sep . 'web';
$copied = 0;
$tree   = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($tree as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        refuse('web/ holds a link (' . $rel . '); refusing to copy it');
    }
    if ($info->isDir() === true) {
        mkdir($child . $sep . 'web' . $sep . $rel, 0700);
    } else {
        copy((string) $path, $child . $sep . 'web' . $sep . $rel);
        $copied++;
    }
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/self-service-password-selftest.php for one run; deleted with its folder.\nreturn "
    . var_export($creds, true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

/** The three copies the plants in part I are written into. */
$scratchClass   = $child . $sep . 'web' . $sep . '_core' . $sep . 'SelfServicePassword.php';
$scratchHandler = $child . $sep . 'web' . $sep . '_apps' . $sep . 'auth' . $sep . 'account' . $sep . 'add-password-save.php';
// 🆕 The PAGE too, for plant 7. The documents claimed a seventh plant before
//    one existed, and there was no scratch copy of the page to write it into —
//    so the claim could not have been true. Adding the target is the honest
//    fix: an independent check proved D17 really does go red on it.
$scratchView    = $child . $sep . 'web' . $sep . '_apps' . $sep . 'auth' . $sep . 'account' . $sep . 'add-password.php';

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/self-service-password-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Self-Service-Password-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
// Required at the TOP LEVEL, never inside a function: the portal's start-up
// sets $mysqli and $SETTINGS as globals.
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') { $target = $real; } else { readfile($real); return true; }
}
$_SERVER['SCRIPT_NAME']     = substr($target, strlen($root));
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
ROUTER;
file_put_contents($child . $sep . 'router.php', str_replace('__TOKEN__', $state['token'], $router));

// The server. ONE process, no stored copies of compiled pages (part I changes a
// file and must be served the changed one), and `session.serialize_handler=php`
// SET EXPLICITLY: sections E and F read and edit the session FILE, so its
// format must be the one they know rather than whatever this machine prefers.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc']     = proc_open(
    [
        PHP_BINARY,
        '-d', 'session.save_path=' . $child . $sep . 'sessions',
        '-d', 'session.serialize_handler=php',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120',
        '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php',
    ],
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $child . $sep . 'server-output.log', 'a'],
        2 => ['file', $child . $sep . 'server-output.log', 'a'],
    ],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('the web server could not be started');
}
$deadline = microtime(true) + 10;
while (portAnswers($port) === false && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false) {
    refuse('the web server never answered on port ' . $port);
}

echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port
    . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . "\n";
echo "organisation {$site}; seven made-up people, 931001 to 931007.\n";

/**
 * Sign one of this test's people in through the REAL sign-in page, which is
 * what records a real proof by the real code path. Answers their cookie jar, or
 * '' when the portal would not let them in — which several checks want to know.
 */
$signIn = static function (string $name, string $identifier, string $password) use ($child, $sep): string {
    $file = $child . $sep . 'jar-' . preg_replace('/[^a-z0-9]+/i', '-', $name) . '.txt';
    @unlink($file);
    $tok    = token(req($file, 'GET', '/login')['body']);
    $answer = req($file, 'POST', '/login', ['csrf_token' => $tok, 'username' => $identifier, 'password' => $password]);
    if ($answer['status'] !== 302 || str_contains($answer['location'], '/login') === true) {
        return '';
    }

    return $file;
};

/**
 * Put somebody in exactly the state a Microsoft-only member is in a moment
 * after signing in: SIGNED IN, with NO password, and a proof minted seconds
 * ago by a real authentication.
 *
 * The password is given, used and then taken away again, because no test can
 * drive a real Microsoft sign-in or a real authenticator. See the file header.
 */
$readyWithProof = static function (int $id, string $label) use ($givePassword, $takePasswordAway, $signIn, $oldPassword, $address): string {
    $givePassword($id);
    $jar = $signIn($label, $address($id), $oldPassword);
    if ($jar === '') {
        refuse("the made-up person {$id} could not sign in, so nothing below could be pressed");
    }
    $takePasswordAway($id);

    return $jar;
};

/** The session identifier a cookie jar holds ('' when none). */
$sessionId = static function (string $file): string {
    foreach ((array) @file($file) as $line) {
        $columns = explode("\t", rtrim((string) $line, "\r\n"));
        if (count($columns) === 7 && $columns[5] === 'PHPSESSID') {
            return $columns[6];
        }
    }

    return '';
};

/**
 * Change the proof inside a session's own file on disk.
 *
 * WHY THIS IS DONE THIS WAY. The window and the single use cannot be tested by
 * waiting — five minutes is five minutes — and they cannot be tested by calling
 * the class in this process, because what matters is the PAGE's answer to a
 * real request. The server's sessions are in this run's own folder, and its
 * `session.serialize_handler` is set to `php` above, so the format is known.
 *
 * `$how` is 'remove', 'age' (by `$value` seconds) or 'reassign' (to account
 * `$value`). It REFUSES rather than failing quietly when it cannot find the
 * proof: an edit that did not apply would make every check below pass for the
 * wrong reason.
 */
/**
 * 🕰️ The moment stored in the proof, read straight out of the session file.
 *
 * Needed by F02b, which asks whether merely LOOKING at the proof renews it.
 * Answers -1 when there is no proof, so the caller can tell "gone" from "old".
 */
$proofMoment = static function (string $jar) use ($child, $sep, $sessionId): int {
    $id = $sessionId($jar);
    if ($id === '') {
        return -1;
    }
    $file = $child . $sep . 'sessions' . $sep . 'sess_' . $id;
    clearstatcache(true, $file);
    $body = (string) @file_get_contents($file);
    if (preg_match('/reauth_proof\|a:3:\{s:6:"userID";i:\d+;s:6:"method";s:\d+:"[^"]*";s:5:"atUtc";i:(\d+);\}/', $body, $found) !== 1) {
        return -1;
    }

    return (int) $found[1];
};

$editProof = static function (string $jar, string $how, int $value = 0) use ($child, $sep, $sessionId): void {
    $id = $sessionId($jar);
    if ($id === '') {
        refuse('a cookie jar holds no session identifier, so the proof could not be edited');
    }
    $file = $child . $sep . 'sessions' . $sep . 'sess_' . $id;
    clearstatcache(true, $file);
    $body = (string) @file_get_contents($file);
    if ($body === '') {
        refuse('the session file ' . $file . ' could not be read');
    }
    $pattern = '/reauth_proof\|a:3:\{s:6:"userID";i:(\d+);s:6:"method";s:(\d+):"([^"]*)";s:5:"atUtc";i:(\d+);\}/';
    if (preg_match($pattern, $body, $found) !== 1) {
        refuse('no proof was found in the session file, so this run cannot test the window or its single use');
    }
    if ($how === 'remove') {
        $after = (string) preg_replace($pattern, '', $body, 1);
    } elseif ($how === 'age') {
        $after = str_replace(
            's:5:"atUtc";i:' . $found[4] . ';',
            's:5:"atUtc";i:' . (string) ((int) $found[4] - $value) . ';',
            $body
        );
    } else {
        $after = str_replace(
            'reauth_proof|a:3:{s:6:"userID";i:' . $found[1] . ';',
            'reauth_proof|a:3:{s:6:"userID";i:' . (string) $value . ';',
            $body
        );
    }
    if ($after === $body) {
        refuse('the edit to the session file changed nothing, so it proves nothing');
    }
    file_put_contents($file, $after);
    clearstatcache(true, $file);
};

/** How many sign-in rows one account has, and whether any holds a usable password. */
$signInRows = static fn (int $id): int => (int) (one('SELECT COUNT(*) AS n FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [$id])['n'] ?? 0);

/**
 * Does the password stored for this account match the one given? Asked with
 * `password_verify()`, so nothing here holds, prints or compares a hash itself.
 */
$storedPasswordIs = static function (int $id, string $password): bool {
    $row = one('SELECT `passwordHash` FROM `tblLocalAccounts` WHERE `userID` = ? LIMIT 1', 'i', [$id]);
    if ($row === null || (string) $row['passwordHash'] === '') {
        return false;
    }

    return password_verify($password, (string) $row['passwordHash']);
};

/**
 * Press "Add this password" exactly as the form does, and answer the message
 * the page shows afterwards. The form token is taken from a page load, because
 * `Auth::verifyCsrf()` replaces it on every acceptance.
 *
 * `$tokenFrom` is the page the token is taken from — '/account' by default, so
 * that a POST can be made even when the add-password page draws no form at
 * all. That is the shape that matters for section E: a perfectly good token and
 * no proof.
 *
 * @return array{status:int, flash:string, type:string}
 */
$pressAdd = static function (string $jar, string $password, string $tokenFrom = '/account', array $extra = []): array {
    $page = req($jar, 'GET', $tokenFrom);
    $tok  = token($page['body']);
    if ($tok === '') {
        return ['status' => -2, 'flash' => 'no form token on ' . $tokenFrom, 'type' => ''];
    }
    $answer = req($jar, 'POST', '/account/add-password-save', $extra + [
        'csrf_token'           => $tok,
        'new_password'         => $password,
        'new_password_confirm' => $password,
    ]);
    $after = req($jar, 'GET', '/account/add-password');
    $flash = '';
    $type  = '';
    if (preg_match('#<div class="alert alert-([a-z]+)" role="alert">\s*(.*?)\s*</div>#s', $after['body'], $m) === 1) {
        $type  = $m[1];
        $flash = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
    }

    return ['status' => $answer['status'], 'flash' => (string) preg_replace('/\s+/', ' ', $flash), 'type' => $type];
};

// =============================================================================
head('C. The made-up world is really as these checks need it');
// =============================================================================
ok(
    'C01 the setting ships SWITCHED ON installation-wide, because this is a recovery route',
    $state['settings']['auth.password.selfAdd'] === '1',
    'stored: ' . var_export($state['settings']['auth.password.selfAdd'], true)
);

$worldIsRight = $signInRows(SSOONLY) === 0
    && $signInRows(HASPWD) === 1
    && $signInRows(KEYONLY) === 0
    && $signInRows(NOWAY) === 0
    && (int) (one('SELECT COUNT(*) AS n FROM `tblLinkedAccounts` WHERE `userID` = ?', 'i', [SSOONLY])['n'] ?? 0) === 1
    && (int) (one('SELECT COUNT(*) AS n FROM `tblWebAuthnCredentials` WHERE `userID` = ?', 'i', [SSOONLY])['n'] ?? 0) === 1
    && (int) (one('SELECT COUNT(*) AS n FROM `tblWebAuthnCredentials` WHERE `userID` = ?', 'i', [KEYONLY])['n'] ?? 0) === 1
    && (int) (one('SELECT COUNT(*) AS n FROM `tblLinkedAccounts` WHERE `userID` = ?', 'i', [NOWAY])['n'] ?? 0) === 0;
ok(
    'C02 one person has a Microsoft link AND a passkey, one keeps a password, one has a passkey only, one has nothing',
    $worldIsRight
);

// =============================================================================
head('D. The ordinary case: a member with no password adds one, over real HTTP');
// =============================================================================
$ssoJar = $readyWithProof(SSOONLY, 'microsoft-only');

$accountPage = req($ssoJar, 'GET', '/account');
ok(
    'D01 the Account page offers "Add a password" to a member who has none',
    $accountPage['status'] === 200 && str_contains($accountPage['body'], 'account/add-password') === true,
    'HTTP ' . $accountPage['status']
);

$hasJar = $signIn('has-a-password', $address(HASPWD), $oldPassword);
if ($hasJar === '') {
    refuse('the member who keeps a password could not sign in');
}
$hasPage = req($hasJar, 'GET', '/account');
ok(
    'D02 ... and does NOT offer it to a member who already has one',
    $hasPage['status'] === 200 && str_contains($hasPage['body'], 'account/add-password') === false,
    'HTTP ' . $hasPage['status']
);

$form = req($ssoJar, 'GET', '/account/add-password');
ok(
    'D03 the page draws the form for a member with a fresh proof',
    $form['status'] === 200 && str_contains($form['body'], 'name="new_password"') === true
    && str_contains($form['body'], 'Add this password') === true,
    'HTTP ' . $form['status']
);
ok(
    'D04 ... and names the sign-in name that will be used: the account\'s own e-mail address',
    str_contains($form['body'], htmlspecialchars($address(SSOONLY), ENT_QUOTES, 'UTF-8')) === true
);
ok(
    'D05 ... and the page ends whole, so nothing threw part-way through it',
    str_contains($form['body'], '</html>') === true
);

$beforeAdd = $signIn('before-add', $address(SSOONLY), $newPassword);
ok('D06 the member cannot sign in with the new password BEFORE adding it', $beforeAdd === '');

$saved = $pressAdd($ssoJar, $newPassword);
ok(
    'D07 pressing "Add this password" works, and the page says so',
    $saved['type'] === 'success' && str_contains($saved['flash'], 'Your password is set') === true,
    $saved['type'] . ': ' . $saved['flash']
);
ok('D08 exactly one sign-in row now exists', $signInRows(SSOONLY) === 1, $signInRows(SSOONLY) . ' row(s)');
ok('D09 ... and it holds the password that was typed', $storedPasswordIs(SSOONLY, $newPassword) === true);

$afterAdd = $signIn('after-add', $address(SSOONLY), $newPassword);
ok(
    'D10 the member can REALLY sign in with it, through the real sign-in page',
    $afterAdd !== '',
    'the sign-in was refused'
);

ok(
    'D11 the Microsoft link is untouched: it is an ADDITION, and nothing is removed',
    (int) (one('SELECT COUNT(*) AS n FROM `tblLinkedAccounts` WHERE `userID` = ?', 'i', [SSOONLY])['n'] ?? 0) === 1
);
ok(
    'D12 every passkey is untouched too',
    (int) (one('SELECT COUNT(*) AS n FROM `tblWebAuthnCredentials` WHERE `userID` = ?', 'i', [SSOONLY])['n'] ?? 0) === 1
);

$added = q(
    "SELECT `activityType`, `activityDescription` FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` IN ('PasswordAdded', 'PasswordChanged')",
    'i',
    [SSOONLY]
);
ok(
    'D13 the activity log says a method was ADDED, and never that a password was CHANGED',
    count($added) === 1 && $added[0]['activityType'] === 'PasswordAdded',
    count($added) . ' record(s): ' . implode(', ', array_column($added, 'activityType'))
);
$description = (string) ($added[0]['activityDescription'] ?? '');
ok(
    'D14 ... and that record holds no password and no part of a hash',
    $description !== ''
    && str_contains($description, $newPassword) === false
    && preg_match('/\$2[aby]?\$/', $description) !== 1
);

$posted = one(
    'SELECT `toRecipients`, `subject`, `status` FROM `tblEmailLog` WHERE `toRecipients` = ? ORDER BY `emailLogID` DESC LIMIT 1',
    's',
    [$address(SSOONLY)]
);
ok(
    'D15 an e-mail was addressed to the account\'s own address, saying a sign-in method was added',
    $posted !== null && str_contains((string) $posted['subject'], 'password sign-in was added') === true,
    $posted === null ? 'no row in tblEmailLog' : (string) $posted['subject']
);
ok(
    'D16 ... and because it could not be sent, the page said so rather than claiming it had been',
    str_contains($saved['flash'], 'could not send') === true,
    $saved['flash']
);

// 📧 THE PAGE'S OWN CLAIM ABOUT THE E-MAIL, CHECKED AGAINST THE FALLBACK AND
//    NOT THE HAPPY PATH (#671). This run's mail route is SMTP with nothing
//    filled in, so `Mailer::canSend()` is false, and the one sentence a member
//    reads BEFORE deciding to add a second way into their account must say so
//    rather than promise an e-mail that cannot go.
ok(
    'D17 with no mail route set up, the page says plainly that nobody will be e-mailed about this',
    str_contains($form['body'], 'nobody will be e-mailed') === true
    && str_contains($form['body'], 'We e-mail you to say a sign-in method was added') === false,
    'the page promised the e-mail anyway'
);

// =============================================================================
head('E. Without a fresh proof it is REFUSED');
// =============================================================================
$reuseJar = $readyWithProof(REUSE, 'no-proof');
$editProof($reuseJar, 'remove');

$noProof = req($reuseJar, 'GET', '/account/add-password');
ok(
    'E01 with the proof gone the page refuses and draws no form',
    $noProof['status'] === 200
    && str_contains($noProof['body'], 'name="new_password"') === false
    && str_contains($noProof['body'], 'please sign in again the way you normally do') === true,
    'HTTP ' . $noProof['status']
);
ok(
    'E02 ... and offers the way back in this account really has',
    str_contains($noProof['body'], 'Sign in again with Microsoft') === true
);

$refused = $pressAdd($reuseJar, $newPassword);
ok(
    'E03 posting the save anyway, with a perfectly good form token, is REFUSED',
    $refused['type'] !== 'success' && str_contains($refused['flash'], 'sign in again') === true,
    $refused['type'] . ': ' . $refused['flash']
);
ok('E04 ... and no sign-in row was created', $signInRows(REUSE) === 0, $signInRows(REUSE) . ' row(s)');

// 🔐 THE FORM TOKEN. This pair exists because an independent check deleted the
//    whole `Auth::verifyCsrf()` gate from the handler and this test stayed
//    88 of 88 GREEN — every other check here fetches a real token first, and
//    E03's whole point is the opposite case (a GOOD token and no proof). So
//    the one thing standing between a member and a page that posts this form
//    FOR them was pinned by nothing.
//
//    Why it matters rather than being tidiness: inside the five-minute proof
//    window, a page the member visits could post the add-password form on
//    their behalf — a second way into the account chosen by somebody who is
//    not its owner, which is exactly what #586 refuses on the administrator's
//    side. The window is narrow; this gate is all there is in it.
//
//    The proof is deliberately VALID for both, so the only thing being tested
//    is the token.
$tokenJar = $readyWithProof(REUSE, 'form-token');
$noToken  = req($tokenJar, 'POST', '/account/add-password-save', [
    'new_password'         => $newPassword,
    'new_password_confirm' => $newPassword,
]);
ok(
    'E04b posting the save with NO form token is refused, and no sign-in row is created',
    $signInRows(REUSE) === 0,
    'HTTP ' . $noToken['status'] . '; rows: ' . $signInRows(REUSE)
);
$badToken = req($tokenJar, 'POST', '/account/add-password-save', [
    'csrf_token'           => str_repeat('a', 64),
    'new_password'         => $newPassword,
    'new_password_confirm' => $newPassword,
]);
ok(
    'E04c ... and with a WRONG one it is refused too — a token must be verified, not merely present',
    $signInRows(REUSE) === 0,
    'HTTP ' . $badToken['status'] . '; rows: ' . $signInRows(REUSE)
);

// The passkey-only member sees the passkey offer and not the Microsoft one.
$keyJar = $readyWithProof(KEYONLY, 'passkey-only');
$editProof($keyJar, 'remove');
$keyPage = req($keyJar, 'GET', '/account/add-password');
ok(
    'E05 a passkey-only member is offered their passkey and not a Microsoft sign-in',
    str_contains($keyPage['body'], 'Use my passkey') === true
    && str_contains($keyPage['body'], 'Sign in again with Microsoft') === false
);
ok(
    'E06 ... and that button carries the answer\'s own nonce, without which the browser refuses the script in silence',
    preg_match('/<script nonce="[0-9a-f]{32}">/', $keyPage['body']) === 1
);

// Somebody with no way to prove themselves is told so, and is not offered a form.
$noWayJar = $readyWithProof(NOWAY, 'no-way-in');
$editProof($noWayJar, 'remove');
$noWayPage = req($noWayJar, 'GET', '/account/add-password');
ok(
    'E07 a member with no Microsoft, Google or passkey is told we cannot offer them a way to prove who they are',
    str_contains($noWayPage['body'], 'cannot offer you a way to prove who you are') === true
    && str_contains($noWayPage['body'], 'name="new_password"') === false
);

// A proof naming somebody else is not a proof for this account.
$otherJar = $readyWithProof(REUSE, 'wrong-person');
$editProof($otherJar, 'reassign', KEYONLY);
$wrongPerson = $pressAdd($otherJar, $newPassword);
ok(
    'E08 a proof naming a DIFFERENT account is refused — the page judges the signed-in person',
    $wrongPerson['type'] !== 'success' && $signInRows(REUSE) === 0,
    $wrongPerson['type'] . ': ' . $wrongPerson['flash']
);

// =============================================================================
head('F. A stale proof, and a proof that has been spent');
// =============================================================================
$staleJar = $readyWithProof(REUSE, 'stale-proof');
$editProof($staleJar, 'age', SSP::PROOF_GOOD_FOR_SECONDS + 5);

$stalePage = req($staleJar, 'GET', '/account/add-password');
ok(
    'F01 a proof five minutes and five seconds old: the page refuses and draws no form',
    str_contains($stalePage['body'], 'name="new_password"') === false
    && str_contains($stalePage['body'], 'please sign in again the way you normally do') === true
);
$staleSave = $pressAdd($staleJar, $newPassword);
ok(
    'F02 ... and the save is refused',
    $staleSave['type'] !== 'success' && $signInRows(REUSE) === 0,
    $staleSave['type'] . ': ' . $staleSave['flash']
);

// ⏳ THE WINDOW IS FIXED, NOT SLIDING — and this was pinned by nothing until an
//    independent check made the page renew the proof's moment every time it was
//    drawn, and the suite stayed 88 of 88 GREEN.
//
//    Why that matters: this class's stated protection against a borrowed
//    session is that the window is SHORT. A sliding window turns five minutes
//    into "five minutes after you stop looking", so a borrowed session that
//    reloads the page every few minutes holds the proof open for as long as it
//    likes. F01 cannot see it, because F01 only proves that an ALREADY stale
//    proof is refused — it cannot see one being kept young.
//    ⚠️ THE PROOF MUST BE AGED FIRST, and a draft of this check did not do it.
//       The whole run happens inside a second or two, so a renewal writes back
//       the SAME `time()` the proof already held and compares equal — the check
//       passed on the planted fault, which is the shape this repository names
//       as a clause that cannot fail. Ageing it by a known amount, while
//       leaving it comfortably INSIDE the window, is what makes a renewal
//       visible: a sliding window would move the moment forward to now.
$freshJar = $readyWithProof(REUSE, 'fixed-window');
$editProof($freshJar, 'age', SSP::PROOF_GOOD_FOR_SECONDS - 30);
$momentWas = $proofMoment($freshJar);
$stillDraws = req($freshJar, 'GET', '/account/add-password');
req($freshJar, 'GET', '/account/add-password');
$momentNow = $proofMoment($freshJar);
ok(
    'F02b the proof is still good at thirty seconds left, so the page really draws the form — without which '
        . 'the next check could not fail',
    str_contains($stillDraws['body'], 'name="new_password"') === true
);
ok(
    'F02c ... and drawing it twice does NOT renew the proof: the moment is unchanged, so the five minutes '
        . 'run from when the person really proved themselves, not from when they last looked',
    $momentWas > 0 && $momentNow === $momentWas,
    'was ' . $momentWas . ', now ' . $momentNow . ' (a difference means the window slides)'
);

// SINGLE USE. One proof, one add. The password is taken away again afterwards,
// so that the second refusal cannot be "you already have one".
$spendJar = $readyWithProof(REUSE, 'single-use');
$firstAdd = $pressAdd($spendJar, $newPassword);
ok(
    'F03 the first add works',
    $firstAdd['type'] === 'success' && $signInRows(REUSE) === 1,
    $firstAdd['type'] . ': ' . $firstAdd['flash']
);
$takePasswordAway(REUSE);
$secondAdd = $pressAdd($spendJar, $newPassword);
ok(
    'F04 the SAME proof cannot add a second password: it was spent',
    $secondAdd['type'] !== 'success' && str_contains($secondAdd['flash'], 'sign in again') === true
    && $signInRows(REUSE) === 0,
    $secondAdd['type'] . ': ' . $secondAdd['flash']
);
// 🧪 And F04 is not passing for some other reason: with a FRESH proof the very
//    same second add goes through. Without this, "refused" could mean the
//    person, the page or the world had changed rather than the proof.
$freshJar  = $readyWithProof(REUSE, 'single-use-again');
$thirdAdd  = $pressAdd($freshJar, $newPassword);
ok(
    'F05 ... and with a FRESH proof the same second add DOES work, so F04 refused for the right reason',
    $thirdAdd['type'] === 'success' && $signInRows(REUSE) === 1,
    $thirdAdd['type'] . ': ' . $thirdAdd['flash']
);

// =============================================================================
head('G. The two levels: the installation\'s "no" beats an organisation\'s "yes"');
// =============================================================================
$levelSet = static function (?string $installation, ?string $organisation) use ($site): void {
    if ($installation === null) {
        q("DELETE FROM `tblSettings` WHERE `settingKey` = 'auth.password.selfAdd' AND `siteID` IS NULL");
    } else {
        q(
            "INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`) VALUES ('auth.password.selfAdd', ?, 0, '1') "
            . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)',
            's',
            [$installation]
        );
    }
    q("DELETE FROM `tblSettings` WHERE `settingKey` = 'auth.password.selfAdd' AND `siteID` IS NOT NULL");
    if ($organisation !== null) {
        q(
            "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `isSensitive`, `defaultValue`) VALUES (?, 'auth.password.selfAdd', ?, 0, '1')",
            'is',
            [$site, $organisation]
        );
    }
};

$levelsJar = $readyWithProof(LEVELS, 'two-levels');

$levelSet('0', null);
$page = req($levelsJar, 'GET', '/account/add-password');
ok(
    'G01 the installation says no and the organisation says nothing: refused',
    str_contains($page['body'], 'not available on this portal') === true
    && str_contains($page['body'], 'name="new_password"') === false
);

$levelSet('0', '1');
$page = req($levelsJar, 'GET', '/account/add-password');
ok(
    'G02 the installation says no and the organisation says YES: still refused — the merged settings cannot express this',
    str_contains($page['body'], 'not available on this portal') === true
    && str_contains($page['body'], 'name="new_password"') === false
);
$blocked = $pressAdd($levelsJar, $newPassword);
ok(
    'G03 ... and the save is refused too, so the rule is not only in the form',
    $blocked['type'] !== 'success' && $signInRows(LEVELS) === 0,
    $blocked['type'] . ': ' . $blocked['flash']
);

$levelSet('1', '0');
$page = req($levelsJar, 'GET', '/account/add-password');
ok(
    'G04 the installation says yes and the organisation says no: refused — an organisation may hold itself to less',
    str_contains($page['body'], 'not available on this portal') === true
);

$levelSet('1', null);
$page = req($levelsJar, 'GET', '/account/add-password');
ok(
    'G05 both say yes: the form is drawn again, so G01 to G04 refused for the setting and not for something else',
    str_contains($page['body'], 'name="new_password"') === true
);

// =============================================================================
head('H. An administrator still cannot do it for somebody else (#586, D-018)');
// =============================================================================
$adminJar = $signIn('administrator', $address(ADMIN), $oldPassword);
if ($adminJar === '') {
    refuse('the made-up administrator could not sign in');
}
$beforeLevels = $signInRows(LEVELS);
$asSomebody   = $pressAdd($adminJar, $newPassword, '/account', ['userID' => (string) LEVELS, 'user_id' => (string) LEVELS]);
ok(
    'H01 an administrator naming somebody else\'s account is judged on their OWN account, and refused — they have a password',
    $asSomebody['type'] !== 'success' && str_contains($asSomebody['flash'], 'already has a password') === true,
    $asSomebody['type'] . ': ' . $asSomebody['flash']
);
ok(
    'H02 ... and the other person still has no password: nothing was done for them',
    $signInRows(LEVELS) === $beforeLevels && $beforeLevels === 0,
    $signInRows(LEVELS) . ' row(s)'
);
ok(
    'H03 ... and the administrator\'s own password is unchanged',
    $storedPasswordIs(ADMIN, $oldPassword) === true && $storedPasswordIs(ADMIN, $newPassword) === false
);

// =============================================================================
head('I. The plants: seven faults, each proved applied and each required to be caught');
// =============================================================================
// 🧨 Every plant is written into the SCRATCH copy, never the repository's, and
//    each one is proved to have CHANGED the file before anything is pressed. A
//    plant that changes nothing proves nothing.

$classAsShipped   = (string) file_get_contents($classFile);
$handlerAsShipped = (string) file_get_contents($handlerFile);
$viewAsShipped    = (string) file_get_contents($viewFile);

/**
 * Write one plant into a scratch file, from the repository's own text each
 * time, and prove it applied.
 *
 * @param array<string,string> $swaps
 */
$plant = static function (string $target, string $asShipped, array $swaps): string {
    $after = $asShipped;
    foreach ($swaps as $from => $to) {
        $count = 0;
        $after = str_replace($from, $to, $after, $count);
        if ($count !== 1) {
            refuse("the plant '" . mb_substr($from, 0, 60) . "' matched {$count} place(s), not exactly one");
        }
    }
    file_put_contents($target, $after);
    clearstatcache(true, $target);
    if ((string) file_get_contents($target) !== $after || $after === $asShipped) {
        refuse('a plant did not apply to ' . $target);
    }

    return $after;
};
$putBack = static function () use (
    $scratchClass,
    $classAsShipped,
    $scratchHandler,
    $handlerAsShipped,
    $scratchView,
    $viewAsShipped
): void {
    file_put_contents($scratchClass, $classAsShipped);
    file_put_contents($scratchHandler, $handlerAsShipped);
    file_put_contents($scratchView, $viewAsShipped);
    clearstatcache(true, $scratchView);
    clearstatcache(true, $scratchClass);
    clearstatcache(true, $scratchHandler);
};

// --- Plant 1: the re-authentication gate switched off --------------------
$plant($scratchClass, $classAsShipped, [
    "if ((\$facts['provedThemselves'] ?? false) !== true) {\n            return self::MSG_NEEDS_PROOF;"
        => "if (false) {\n            return self::MSG_NEEDS_PROOF;",
]);
$jar = $readyWithProof(REUSE, 'plant-one');
$editProof($jar, 'remove');
$planted = $pressAdd($jar, $newPassword);
ok(
    'I01 with the re-authentication gate switched off, a session with NO proof adds a password — which E03 goes red on',
    $planted['type'] === 'success' && $signInRows(REUSE) === 1,
    $planted['type'] . ': ' . $planted['flash']
);
$putBack();

// --- Plant 2: the five-minute window removed -----------------------------
$plant($scratchClass, $classAsShipped, [
    'return $age >= 0 && $age <= self::PROOF_GOOD_FOR_SECONDS;' => 'return $age >= 0;',
]);
$jar = $readyWithProof(REUSE, 'plant-two');
$editProof($jar, 'age', SSP::PROOF_GOOD_FOR_SECONDS + 5);
$planted = $pressAdd($jar, $newPassword);
ok(
    'I02 with the five-minute window removed, a proof five minutes old still works — which F01 and F02 go red on',
    $planted['type'] === 'success' && $signInRows(REUSE) === 1,
    $planted['type'] . ': ' . $planted['flash']
);
$putBack();

// --- Plant 3: the single use removed -------------------------------------
$plant($scratchClass, $classAsShipped, [
    'unset($_SESSION[self::PROOF_KEY]);' => '/* the proof is deliberately NOT spent — planted */',
]);
$jar = $readyWithProof(REUSE, 'plant-three');
$first = $pressAdd($jar, $newPassword);
$takePasswordAway(REUSE);
$second = $pressAdd($jar, $newPassword);
ok(
    'I03 with the single use removed, ONE proof adds a password TWICE — which F04 goes red on',
    $first['type'] === 'success' && $second['type'] === 'success' && $signInRows(REUSE) === 1,
    $first['type'] . ' then ' . $second['type'] . ': ' . $second['flash']
);
$putBack();

// --- Plant 4: the installation layer read from the MERGED settings -------
$plant($scratchClass, $classAsShipped, [
    'return self::allowedFrom(is_array($merged) === true ? $merged : [], InstallationSettings::layer());'
        => 'return self::allowedFrom(is_array($merged) === true ? $merged : [], is_array($merged) === true ? $merged : []);',
]);
$levelSet('0', '1');
$jar     = $readyWithProof(REUSE, 'plant-four');
$planted = $pressAdd($jar, $newPassword);
ok(
    'I04 reading the installation\'s layer out of the MERGED settings lets an organisation overrule it — which G02 and G03 go red on',
    $planted['type'] === 'success' && $signInRows(REUSE) === 1,
    $planted['type'] . ': ' . $planted['flash']
);
$putBack();
$levelSet('1', null);

// --- Plant 5: the handler removes the Microsoft link ---------------------
$plant($scratchHandler, $handlerAsShipped, [
    '    $mysqli->commit();' => "    \$gone = \$mysqli->prepare('DELETE FROM `tblLinkedAccounts` WHERE `userID` = ?');\n"
        . "    \$gone->bind_param('i', \$userId);\n    \$gone->execute();\n    \$gone->close();\n    \$mysqli->commit();",
]);
$takePasswordAway(REUSE);
$jar     = $readyWithProof(REUSE, 'plant-five');
$planted = $pressAdd($jar, $newPassword);
$linksLeft = (int) (one('SELECT COUNT(*) AS n FROM `tblLinkedAccounts` WHERE `userID` = ?', 'i', [REUSE])['n'] ?? 0);
ok(
    'I05 a handler that removes the Microsoft link still reports success — which D11 goes red on',
    $planted['type'] === 'success' && $linksLeft === 0,
    $planted['type'] . '; links left: ' . $linksLeft
);
$putBack();
q(
    "INSERT INTO `tblLinkedAccounts` (`userID`, `provider`, `providerSub`, `providerEmail`) VALUES (?, 'ms365', ?, ?)",
    'iss',
    [REUSE, 'selftest-731-subject-' . bin2hex(random_bytes(6)), $address(REUSE)]
);

// --- Plant 6: the handler writes the password into the activity log ------
$plant($scratchHandler, $handlerAsShipped, [
    "'A password sign-in was added to this account by its owner, after signing in again.'"
        => "'A password sign-in was added to this account by its owner: ' . \$chosen",
]);
$takePasswordAway(REUSE);
$jar     = $readyWithProof(REUSE, 'plant-six');
$planted = $pressAdd($jar, $newPassword);
$logged  = (string) (one(
    "SELECT `activityDescription` AS d FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = 'PasswordAdded' ORDER BY `logID` DESC LIMIT 1",
    'i',
    [REUSE]
)['d'] ?? '');
ok(
    'I06 a handler that writes the password into the activity log still reports success — which D14 goes red on',
    $planted['type'] === 'success' && str_contains($logged, $newPassword) === true,
    $planted['type'] . '; the record holds the password: ' . (str_contains($logged, $newPassword) === true ? 'YES' : 'no')
);
$putBack();

// --- Plant 7: the page promises the e-mail whatever the mail route says --
//
// 🎯 THIS IS THE PLANT THE DOCUMENTS CLAIMED BEFORE IT EXISTED. Both drafts
//    said "seven plants" and named this one, while the test held six and the
//    page had no scratch copy to plant into — so a document asserted that a
//    committed plant proved D17, and no such plant was there. An independent
//    check tried it by hand and D17 really does go red, so the substance was
//    sound; what was wrong was shipping the claim. The remedy is the plant,
//    not a quieter sentence.
//
//    `Mailer::canSend()` is what the page asks before it promises anybody an
//    e-mail. Forced to true with no mail route set up, the page would tell a
//    member they will be e-mailed about a new way into their account when
//    nothing will be sent — the reassuring direction, and on the one page
//    where that reassurance is the only warning they get.
$plant($scratchView, $viewAsShipped, [
    '$canTellThem = Mailer::canSend();' => '$canTellThem = true;',
]);
$takePasswordAway(REUSE);
$jar      = $readyWithProof(REUSE, 'plant-seven');
$promised = req($jar, 'GET', '/account/add-password');
ok(
    'I07 a page that promises the e-mail whatever the mail route says still draws the form — which D17 goes red on',
    str_contains($promised['body'], 'name="new_password"') === true
    && str_contains($promised['body'], 'nobody will be e-mailed') === false,
    'the page still says nobody will be e-mailed: '
    . (str_contains($promised['body'], 'nobody will be e-mailed') === true ? 'YES' : 'no')
);
$putBack();

// --- And the files are the repository's again, byte for byte -------------
ok(
    'I07b all three scratch files are byte-identical to the repository\'s again',
    hash_file('sha256', $scratchClass) === hash_file('sha256', $classFile)
    && hash_file('sha256', $scratchHandler) === hash_file('sha256', $handlerFile)
    && hash_file('sha256', $scratchView) === hash_file('sha256', $viewFile),
    hash_file('sha256', $scratchView) . ' vs ' . hash_file('sha256', $viewFile)
);
$takePasswordAway(REUSE);
$jar     = $readyWithProof(REUSE, 'after-the-plants');
$working = $pressAdd($jar, $newPassword);
ok(
    'I08 ... and with the plants gone the same add works again, so nothing above was left behind',
    $working['type'] === 'success' && $signInRows(REUSE) === 1 && $storedPasswordIs(REUSE, $newPassword) === true,
    $working['type'] . ': ' . $working['flash']
);

// =============================================================================
head('Nothing went wrong behind the pages, and everything is put back');
// =============================================================================
$log = (string) @file_get_contents($child . $sep . 'php-errors.log');
$bad = array_values(array_filter(
    (array) preg_split('/\R/', $log),
    static fn (string $line): bool => preg_match('/PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught/', $line) === 1
));
ok(
    'Z01 the server\'s PHP error log holds no warning, notice or fatal error',
    $bad === [],
    count($bad) . ' found: ' . implode(' | ', array_map(static fn (string $line): string => mb_substr($line, 0, 220), array_slice($bad, 0, 4)))
);

cleanup();
ok(
    'Z02 clean-up: the made-up people and their rows are gone, and every setting is back as it was',
    $state['left'] === 0
    && (int) (one('SELECT COUNT(*) AS n FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NOT NULL', 's', ['auth.password.selfAdd'])['n'] ?? 0) === 0
    && (string) (one('SELECT `settingValue` AS v FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', ['auth.password.selfAdd'])['v'] ?? '') === (string) $state['settings']['auth.password.selfAdd'],
    'rows left: ' . $state['left']
);
ok(
    'Z03 clean-up: the server is stopped and the scratch folder is deleted',
    portAnswers($port) === false && file_exists($child) === false
);

echo "\n{$pass} passed, {$fail} failed, " . ($pass + $fail) . ' ran of ' . EXPECTED . " expected.\n";
if (($pass + $fail) !== EXPECTED) {
    echo "FAIL — the number of checks that ran is not the number this file expects. A block was skipped or ran\n";
    echo "       twice, so this run cannot be read as a pass. Fix the cause, or the EXPECTED constant if a\n";
    echo "       check was added or removed on purpose.\n";
    exit(1);
}
exit($fail === 0 ? 0 : 1);
