<?php
// Path: tools/expense-receipts-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A receipt that is not attached is SAID, over real HTTP 📎 (#649)
 * -----------------------------------------------------------------------------
 * Until 9 November 2026 `expenses/submit/save.php` skipped a receipt it would
 * not keep with a bare `continue` — too big, the wrong kind, refused by the
 * server before PHP saw it, or not movable into the receipts folder — and
 * still saved the claim and sent the claimant on as though every receipt had
 * gone. In an expenses system the receipt IS the evidence.
 *
 * This test SUBMITS REAL CLAIMS WITH REAL FILES through the real handler, and
 * reads back what happened from three places, because each can lie alone:
 * the message the claimant is shown (on the page the handler sends them to),
 * the database (the claim and its receipt rows), and the DISK (the files in
 * the receipts folder).
 *
 * WHAT IT COVERS
 *   A  the rule and the words, asked of `ExpenseRules` directly (no copy of
 *      either is kept here — #647: a test holding its own copy tests nothing)
 *   B  #649's own acceptance case: a good file, a file over 10 MB, a file of
 *      the wrong kind and an EMPTY SLOT — which is what a file box with
 *      nothing chosen sends, as the unused second box does with JavaScript
 *      off — the claim is saved, the good file is attached on disk and in the
 *      database, the two others are named with their reasons, the empty slot
 *      is not mentioned, and the claimant's file names reach no log
 *   C  a file over the SERVER's own limit (PHP refuses it before the portal
 *      sees it — the commonest real case on shared hosting)
 *   D  an empty file is refused; a HEIC photo is attached; a name that is not
 *      valid UTF-8 does not blank the whole message
 *   E  the folder exists but cannot be written: every move fails — the claim
 *      is saved and the file is named as NOT stored, with an error-log line
 *   F  the folder cannot even be MADE: the whole claim is refused (#649's own
 *      wording) and nothing is written; a claim with NO receipt is not refused
 *   G  a failure after a receipt was moved rolls the claim back AND takes the
 *      moved file away, so nothing is left on disk that no row points at; and
 *      a file that cannot be removed is logged by its path
 *   H  a failure AFTER the commit (the approvers' e-mail) no longer tells the
 *      claimant "Error saving claim" about a claim that WAS saved
 *   I  more files than `max_file_uploads`: PHP drops the rest before the
 *      handler runs, and the claimant is told, without names (PHP gives none)
 *   J  the database connection is LOST part-way, so the rollback throws too:
 *      the moved file is still removed and the claimant still told
 *   K  the commit is SENT and cannot be confirmed, through a small proxy
 *      (`tools/expense-receipts-commit-proxy.py`): NO receipt file is ever
 *      deleted on this path. A claim the server saved is reported as saved
 *      (approvers not e-mailed, do not submit again); otherwise "not known".
 *      Every kept file is logged by its path — including the orphan left when
 *      the commit really did fail, which is the cost of never deleting.
 *   L  the CONNECT half of the new connection's 3-second deadline: asked to
 *      reach an address that never answers a connection attempt, it gives
 *      up in seconds, not PHP's default 60. K4 drives the READ half.
 *   Z  across the WHOLE run, no column of either log holds a claimant's own
 *      file name (#714)
 *
 * WHAT IT CANNOT DO, said plainly
 *   - A partly-arrived file (`UPLOAD_ERR_PARTIAL`) and PHP having no temporary
 *     folder cannot be produced over HTTP here; section A covers their WORDS
 *     and their place in the rule, not the handler reaching them. Their
 *     error-log line is written by the SAME statement as a failed move's,
 *     which section E drives.
 *   - It runs ONE worker, so two claims at once racing to make the folder is
 *     untested (the handler asks `is_dir()` again after a failed `mkdir()`
 *     for exactly that, and nothing here proves it).
 *   - Sections E and F take write permission away from a folder. Run as the
 *     ROOT user that does nothing, so the test REFUSES rather than passing.
 *   - A commit REFUSED with the connection still up (a database cluster can
 *     do that) is not produced here; it takes the same path as section K, and
 *     only the words of its message are pinned (A47-A49).
 *   - Stopped with Ctrl-C or a polite kill, it puts back what it changed
 *     (when PHP has the pcntl extension). Killed outright (`kill -9`) it
 *     cannot: see the note beside the signal handler for what to remove.
 *   - Whether the e-mail step is ever called on section K's path is held by
 *     the handler's STRUCTURE, not by a check: with the connection lost the
 *     step records nothing, so a version that called it anyway stays green
 *     (planted, P31). K06b proves only that no attempt is recorded.
 *   - Section L needs an address that never answers. On a machine whose
 *     network answers "no route" at once for every address it tries, check
 *     L00 FAILS (exit 1) — DELIBERATELY: a check that passed there would be
 *     claiming the connect half works without having tested it (#645).
 *
 * Usage:  SELFTEST_DB_NAME=selftest_expreceipts SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/expense-receipts-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl and fileinfo extensions, `proc_open()`, python3 (for
 *         section K's proxy), permission to CREATE TRIGGER, and, for section
 *         L, an address that never answers a connection attempt (one of the
 *         documentation-only addresses it tries, such as 192.0.2.1). It
 *         REFUSES rather than skipping when anything else is missing, because
 *         a skipped test reads exactly like a passing one.
 *
 * Exit:   0 only when every check ran and passed, and the count is the one
 *         expected; 1 when a check failed; 2 when it refused to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/649
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 148;

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'db' => null, 'undo' => []];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing more was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

function check(string $name, mixed $got, mixed $want): void
{
    global $passed, $failed;
    if ($got === $want) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    echo '      wanted: ' . var_export($want, true) . "\n";
    echo '      got:    ' . var_export($got, true) . "\n";
}

// ── What it needs ────────────────────────────────────────────────────────────
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open', 'mime_content_type'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this test needs {$needed}(), which this PHP does not have");
    }
}
if (function_exists('posix_geteuid') === true && posix_geteuid() === 0) {
    refuse('this test is running as ROOT, which ignores folder permissions, so sections E and F could not fail');
}

$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$state['db'] = $db;

$tables = (int) ($db->query(
    'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

// ═════════════════════════════════════════════════════════════════════════════
echo "A. The rule and the words, asked of ExpenseRules itself\n";
// ═════════════════════════════════════════════════════════════════════════════
// The class is loaded from the REPOSITORY, the same file the copied tree below
// serves, so section A and the pages cannot be testing two different rules.
// It needs `App` and `Capabilities` only for `mayExportClaims()`, which is
// never called here; PHP does not load a class until it is used.
require_once $repo . $sep . 'web' . $sep . '_core' . $sep . 'ExpenseRules.php';

use Portal\Core\ExpenseRules as R;

check('A01 a chosen file of an allowed kind and size is allowed',
    R::whyReceiptRefused(UPLOAD_ERR_OK, 1000, 'receipt.pdf'), R::RECEIPT_OK);
check('A02 an empty slot is "nothing chosen", which is never reported',
    R::whyReceiptRefused(UPLOAD_ERR_NO_FILE, 0, ''), R::RECEIPT_NOTHING_CHOSEN);
check('A03 one byte over 10 MB is too big',
    R::whyReceiptRefused(UPLOAD_ERR_OK, 10 * 1024 * 1024 + 1, 'a.jpg'), R::RECEIPT_TOO_BIG);
check('A04 exactly 10 MB is still allowed (the limit was never "under 10 MB")',
    R::whyReceiptRefused(UPLOAD_ERR_OK, 10 * 1024 * 1024, 'a.jpg'), R::RECEIPT_OK);
check('A05 over the SERVER\'s limit (refused by PHP) is its own reason',
    R::whyReceiptRefused(UPLOAD_ERR_INI_SIZE, 0, 'a.jpg'), R::RECEIPT_TOO_BIG_FOR_SERVER);
check('A06 ...and so is the form\'s own limit',
    R::whyReceiptRefused(UPLOAD_ERR_FORM_SIZE, 0, 'a.jpg'), R::RECEIPT_TOO_BIG_FOR_SERVER);
check('A07 a partly-arrived file is refused as partial',
    R::whyReceiptRefused(UPLOAD_ERR_PARTIAL, 500, 'a.jpg'), R::RECEIPT_PARTIAL);
check('A08 no temporary folder on the server is "could not store it", not the claimant\'s fault',
    R::whyReceiptRefused(UPLOAD_ERR_NO_TMP_DIR, 0, 'a.jpg'), R::RECEIPT_NOT_STORED);
check('A09 ...and so is a code no PHP has yet (an unknown code is never "allowed")',
    R::whyReceiptRefused(99, 500, 'a.jpg'), R::RECEIPT_NOT_STORED);
check('A10 an empty file is refused', R::whyReceiptRefused(UPLOAD_ERR_OK, 0, 'a.pdf'), R::RECEIPT_EMPTY);
check('A11 a kind of file not on the list is refused',
    R::whyReceiptRefused(UPLOAD_ERR_OK, 10, 'setup.exe'), R::RECEIPT_WRONG_TYPE);
check('A12 ...and so is a name with no kind at all',
    R::whyReceiptRefused(UPLOAD_ERR_OK, 10, 'receipt'), R::RECEIPT_WRONG_TYPE);
check('A13 the kind is read case-blind (an iPhone writes IMG_0001.HEIC)',
    R::whyReceiptRefused(UPLOAD_ERR_OK, 10, 'IMG_0001.HEIC'), R::RECEIPT_OK);
check('A14 HEIF is allowed too', R::whyReceiptRefused(UPLOAD_ERR_OK, 10, 'x.heif'), R::RECEIPT_OK);
check('A15 a "folder/../name.exe" trick is judged by its last part',
    R::whyReceiptRefused(UPLOAD_ERR_OK, 10, 'a.pdf/../b.exe'), R::RECEIPT_WRONG_TYPE);

$shapes = R::receiptsFromUpload([
    'name' => ['a.pdf', ''], 'tmp_name' => ['/t/1', ''], 'size' => [10, 0], 'error' => [0, 4],
]);
check('A16 the form\'s files[] shape is read as one entry per file', count($shapes), 2);
check('A17 ...with each entry\'s own facts', $shapes[0], ['error' => 0, 'name' => 'a.pdf', 'tmp' => '/t/1', 'size' => 10]);
check('A18 a single (unbracketed) file is read too, not skipped',
    count(R::receiptsFromUpload(['name' => 'a.pdf', 'tmp_name' => '/t', 'size' => 1, 'error' => 0])), 1);
check('A19 nothing at all is an empty list', R::receiptsFromUpload(null), []);
check('A20 a value of the wrong type for an error code counts as "nothing chosen", never as "OK"',
    R::receiptsFromUpload(['error' => ['x'], 'name' => ['a.pdf'], 'tmp_name' => ['/t'], 'size' => [1]])[0]['error'],
    UPLOAD_ERR_NO_FILE);

$reasons = [R::RECEIPT_TOO_BIG, R::RECEIPT_TOO_BIG_FOR_SERVER, R::RECEIPT_PARTIAL,
    R::RECEIPT_EMPTY, R::RECEIPT_WRONG_TYPE, R::RECEIPT_NOT_STORED];
$words = array_map(static fn (string $r): string => R::receiptRefusalWords($r), $reasons);
check('A21 every refusal has its OWN words (six reasons, six different sentences)',
    count(array_unique($words)), 6);
check('A22 the server\'s limit is said in ordinary units',
    str_contains(R::receiptRefusalWords(R::RECEIPT_TOO_BIG_FOR_SERVER, '8M'), '(8 MB)'), true);
check('A23 an unknown reason still says the file was not stored, never nothing',
    R::receiptRefusalWords('something-new'), R::receiptRefusalWords(R::RECEIPT_NOT_STORED));

$none = R::submittedMessage(41, []);
check('A24 nothing left out: a plain success', $none, ['type' => 'success', 'message' => 'Your claim #41 has been submitted.']);
$oneMsg = R::submittedMessage(41, [['name' => 'big.jpg', 'reason' => R::RECEIPT_TOO_BIG]]);
check('A25 one left out: a WARNING, not a success', $oneMsg['type'], 'warning');
check('A26 ...naming the file and the reason in one sentence',
    str_contains($oneMsg['message'], '"big.jpg", because it is larger than 10 MB'), true);
check('A27 ...and saying in plain words it was NOT attached',
    str_contains($oneMsg['message'], 'one file you chose was NOT attached'), true);
$twoMsg = R::submittedMessage(41, [
    ['name' => 'a.jpg', 'reason' => R::RECEIPT_TOO_BIG],
    ['name' => 'b.exe', 'reason' => R::RECEIPT_WRONG_TYPE],
]);
check('A28 several left out: the count and both names',
    str_contains($twoMsg['message'], '2 files you chose were NOT attached')
        && str_contains($twoMsg['message'], '"a.jpg"') && str_contains($twoMsg['message'], '"b.exe"'), true);
check('A29 a crafted name cannot carry control characters into the message',
    R::shownFileName("a\nb\x07c.pdf"), '"abc.pdf"');
check('A30 a long name is shortened', mb_strlen(R::shownFileName(str_repeat('x', 300) . '.pdf')) <= 82, true);
check('A31 the 10 MB limit is unchanged', R::RECEIPT_MAX_BYTES, 10485760);

// Round 1 of the independent check, 9 November 2026.
check('A32 a name that is not valid UTF-8 is SCRUBBED, so the page can still escape it',
    htmlspecialchars(R::shownFileName("Caf\xE9 bill.txt"), ENT_QUOTES, 'UTF-8') !== '', true);
check('A33 ...and keeps the rest of the name', str_contains(R::shownFileName("Caf\xE9 bill.txt"), ' bill.txt'), true);
foreach ([R::RECEIPT_TOO_BIG => 'smaller copy', R::RECEIPT_TOO_BIG_FOR_SERVER => 'smaller copy',
          R::RECEIPT_WRONG_TYPE => 'needs saving as', R::RECEIPT_EMPTY => 'check that you chose'] as $r => $fix) {
    check('A34 a file refused as ' . $r . ' is told WHAT TO CHANGE, not just to send it again',
        str_contains(R::receiptRefusalWords($r), $fix), true);
}
check('A35 no reason holds a semicolon (the list of files is joined with one)',
    count(array_filter($words, static fn (string $w): bool => str_contains($w, ';'))), 0);
check('A36 the advice no longer says "submit it again with the file." for a file that would be refused again',
    str_contains($oneMsg['message'], 'do what is said above'), true);
$warn = ['type' => 2, 'message' => 'PHP Request Startup: ' . R::UPLOAD_COUNT_WARNING, 'file' => 'Unknown', 'line' => 0];
check('A37 PHP\'s warning means files WERE dropped', R::uploadsCutShort($warn, 3, 3), R::CUT_SHORT_CERTAIN);
check('A38 with no warning, as many as allowed means some MAY have been', R::uploadsCutShort(null, 3, 3), R::CUT_SHORT_POSSIBLE);
check('A39 fewer than allowed, and no warning, means none were', R::uploadsCutShort(null, 2, 3), R::CUT_SHORT_NO);
check('A40 some other warning is not mistaken for it',
    R::uploadsCutShort(['type' => 2, 'message' => 'Undefined variable'], 1, 20), R::CUT_SHORT_NO);
$cutOnly = R::submittedMessage(7, [], '', R::CUT_SHORT_CERTAIN, 3);
check('A41 files dropped and nothing named: still a WARNING', $cutOnly['type'], 'warning');
check('A42 ...saying the limit and that the rest never arrived',
    str_contains($cutOnly['message'], 'This server accepts at most 3 files in one submission and more than that were sent, so anything you chose beyond the first 3 never reached the portal'), true);
$maybe = R::submittedMessage(7, [], '', R::CUT_SHORT_POSSIBLE, 3);
check('A43 when it cannot be told, the words say IF, never that files were lost',
    str_contains($maybe['message'], 'so if you chose more than 3, the rest never reached the portal'), true);
$both = R::submittedMessage(7, [['name' => 'a.exe', 'reason' => R::RECEIPT_WRONG_TYPE]], '', R::CUT_SHORT_CERTAIN, 3);
// A claim whose save could not be confirmed (round 3 of the check simplified
// this: once COMMIT is sent nothing is deleted, and the look only picks words).
check('A45 a claim that is not there is not "saved"', R::claimIsSaved($db, 2147480001, 1), false);
check('A46 no claim row was ever written (number 0): not "saved", with nothing asked', R::claimIsSaved($db, 0, 1), false);
$savedUnconfirmed = R::unconfirmedMessage(true, 41, []);
check('A47 found saved: a warning saying it WAS submitted, that the approvers were NOT e-mailed, and not to submit again',
    [$savedUnconfirmed['type'], str_contains($savedUnconfirmed['message'], 'Your claim #41 has been submitted.'),
        str_contains($savedUnconfirmed['message'], 'the approvers were NOT sent their usual e-mail'),
        str_contains($savedUnconfirmed['message'], 'Please do NOT submit it again')], ['warning', true, true, true]);
$notKnown = R::unconfirmedMessage(false, 41, [['name' => 'x.exe', 'reason' => R::RECEIPT_WRONG_TYPE]]);
check('A48 not found: "not known", and the file that was not attached is STILL named',
    [$notKnown['type'], str_starts_with($notKnown['message'], R::MSG_SAVE_NOT_CONFIRMED),
        str_contains($notKnown['message'], 'Either way, one file you chose was NOT attached: "x.exe", because')], ['danger', true, true]);
check('A49 neither message claims the connection "was lost", which is not known (a commit can be refused with it still up)',
    str_contains($savedUnconfirmed['message'] . $notKnown['message'], 'lost'), false);
check('A50 "not known" says to submit again only if it is STILL not listed AFTER A FEW MINUTES (a commit may still be finishing)',
    str_contains(R::MSG_SAVE_NOT_CONFIRMED, 'If it is still not listed after a few minutes, submit it again.'), true);
check('A44 a named file AND dropped files: both said, with the advice for both',
    str_contains($both['message'], '"a.exe"') && str_contains($both['message'], 'at most 3 files')
        && str_contains($both['message'], 'no more than 3 files'), true);

// ═════════════════════════════════════════════════════════════════════════════
// The pages. A COPY of web/, so changing a folder's permissions never touches
// the repository, and every receipt this run stores goes in a folder this run
// made and removes.
// ═════════════════════════════════════════════════════════════════════════════
$child = sys_get_temp_dir() . $sep . 'expense-receipts-' . bin2hex(random_bytes(6));
$state['child'] = $child;

register_shutdown_function(static function () use (&$state): void {
    // Put back anything a section changed in the database, newest first, even
    // when the run died part-way: a renamed table or a trigger left behind
    // would break the next test on this database.
    foreach (array_reverse($state['undo']) as $sql) {
        try {
            $state['db']?->query($sql);
        } catch (Throwable $e) {
            echo "  ! could not undo: {$sql}\n";
        }
    }
    foreach (['proc', 'proxy'] as $process) {
        if (is_resource($state[$process] ?? null) === true) {
            proc_terminate($state[$process]);
            proc_close($state[$process]);
        }
    }
    if ($state['child'] !== null && is_dir($state['child']) === true) {
        // A recursive delete of a folder THIS run made, under the system temp
        // folder, whose name holds random bytes. Permissions are given back
        // first, because sections E and F take them away.
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($state['child'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $entry) {
            if ($entry->isDir() === true) {
                @chmod($entry->getPathname(), 0700);
            }
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($state['child'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($state['child']);
    }
});

// 🛑 CTRL-C AND A POLITE STOP RUN THE SAME CLEAN-UP (round 2 of the check).
//    PHP does not run shutdown functions when a signal ends the process, so a
//    run stopped part-way used to leave a planted trigger, a renamed
//    `tblUserDepts` and a temporary copy of web/ holding the database password.
//    `exit()` from a signal handler DOES run them. What still cannot be
//    cleaned: a run killed outright (SIGKILL, `kill -9`), which no program can
//    catch — then remove `trg_p649_*` triggers, rename `tblUserDepts_p649_aside`
//    back, delete the `expense-receipts-*` folder under the temp folder, and
//    stop the two processes it started and left running: PHP's web server
//    (`php -S 127.0.0.1:…`) and section K's python3 commit proxy. The test's
//    member and department are removed at the start of the next run. Without
//    the pcntl extension only the shutdown function is there.
if (function_exists('pcntl_async_signals') === true && function_exists('pcntl_signal') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
        pcntl_signal($signal, static function (int $which): void {
            echo "\nStopped by a signal ({$which}); putting things back.\n";
            exit(3);
        });
    }
}

mkdir($child, 0700, true);
$copied = 0;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $to = $child . $sep . 'web' . substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    if ($entry->isDir() === true) {
        mkdir($to, 0700, true);
        continue;
    }
    copy($entry->getPathname(), $to);
    $copied++;
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}

@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/expense-receipts-selftest.php for ONE run.\nreturn "
    . var_export([
        'db_host' => $dbHost,
        'db_user' => $dbUser,
        'db_pass' => $dbPass,
        'db_name' => $dbName,
        'db_port' => $dbPort,
    ], true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

$uploads  = $child . $sep . 'web' . $sep . '_uploads';
$receipts = $uploads . $sep . 'expenses';
@mkdir($uploads, 0700, true);

// The router imitates web/public_html/.htaccess, as the other page tests do.
$router = <<<'ROUTER'
<?php
// Written by tools/expense-receipts-selftest.php for ONE run. Test only.
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
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
file_put_contents($child . $sep . 'router.php', $router);

$port = 8960 + random_int(0, 250);
$jar  = $child . $sep . 'cookies.txt';

// 📏 The server's own limit for ONE file is set to 12 MB on purpose: above the
//    portal's 10 MB (so section B's 10.5 MB file reaches the PORTAL's check)
//    and below 13 MB (so section C's file is refused by PHP itself). Section I
//    starts it again with `max_file_uploads=3`; sign-ins survive, because the
//    sessions are files in this run's own folder.
$startServer = static function (array $extraIni = []) use (&$state, $child, $sep, $port): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
        // A new server started too soon fails to bind and every request answers 0.
        usleep(500000);
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = 'prod';
    $ini = [];
    foreach ($extraIni as $key => $value) {
        $ini[] = '-d';
        $ini[] = $key . '=' . $value;
    }
    $state['proc'] = proc_open(
        array_merge(
            [
                PHP_BINARY,
                '-d', 'session.save_path=' . $child . $sep . 'sessions',
                '-d', 'error_log=' . $child . $sep . 'php-errors.log',
                '-d', 'log_errors=1', '-d', 'display_errors=0',
                '-d', 'max_execution_time=120',
                '-d', 'upload_max_filesize=12M', '-d', 'post_max_size=64M',
                '-d', 'upload_tmp_dir=' . $child,
                '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
                // Session clean-up switched OFF for this server: round 2 of the
                // check measured a clean-up notice REPLACING PHP's "too many
                // files" warning before the handler could read it, which turns
                // check I03 red by chance. The handler copes (its fallback is
                // the count, checked by I07); the test must not depend on luck.
                '-d', 'session.gc_probability=0',
            ],
            $ini,
            [
                '-S', '127.0.0.1:' . $port,
                '-t', $child . $sep . 'web' . $sep . 'public_html',
                $child . $sep . 'router.php',
            ]
        ),
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $child . $sep . 'server.log', 'a'],
            2 => ['file', $child . $sep . 'server.log', 'a'],
        ],
        $pipes,
        $child,
        $env
    );
    if (is_resource($state['proc']) === false) {
        refuse('could not start PHP\'s web server');
    }
};

/**
 * A request, ready to send. `$files` is a list of [field name, file name,
 * bytes]; when it holds anything the body is built as multipart BY HAND,
 * because curl cannot send an EMPTY slot (`filename=""`) — which is what a
 * file box with nothing chosen sends, so a claim with no receipt carries one,
 * and so does the second box the form shows when JavaScript is off.
 */
$makeRequest = static function (string $method, string $path, array $post = [], array $files = []) use ($port, $jar) {
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = [];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '*',
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        if ($files === []) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        } else {
            $b    = '----p649' . bin2hex(random_bytes(8));
            $body = '';
            foreach ($post as $name => $value) {
                foreach ((array) $value as $one) {
                    $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$one}\r\n";
                }
            }
            foreach ($files as [$field, $fileName, $bytes]) {
                $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$fileName}\"\r\n"
                    . "Content-Type: application/octet-stream\r\n\r\n{$bytes}\r\n";
            }
            $body     .= "--{$b}--\r\n";
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $b;
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
    }
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

    return $handle;
};

/** What came back for a finished request. */
$answerOf = static function ($handle, $raw): array {
    if ($raw === false || $raw === null) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $m) === 1 ? $m[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
};

/** One request, waited for. */
$http = static function (string $method, string $path, array $post = [], array $files = []) use ($makeRequest, $answerOf): array {
    $handle = $makeRequest($method, $path, $post, $files);

    return $answerOf($handle, curl_exec($handle));
};

$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) === 1) {
        return $m[1];
    }
    return '';
};

/** Wait for the server by asking it for a page, never by sleeping blind. */
$waitUp = static function () use ($http): void {
    for ($i = 0; $i < 80; $i++) {
        if ($http('GET', '/login')['status'] > 0) {
            return;
        }
        usleep(250000);
    }
    refuse('the test server never answered');
};

$startServer();
$waitUp();

// ── The world: one member, one department ───────────────────────────────────
$email    = 'expense.receipts@selftest.invalid';
$password = 'Sel' . bin2hex(random_bytes(8)) . '!9x';
$siteId   = (int) ($db->query('SELECT MIN(siteID) AS s FROM tblSites')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation to put a member in');
}

/**
 * Remove this run's member and everything that names them, children first.
 * Every table name comes from information_schema, never from a request.
 */
$removeMember = static function (int $who) use ($db): void {
    if ($who <= 0) {
        return;
    }
    $db->query("DELETE FROM tblExpenseClaims WHERE userID = {$who}"); // items and files cascade
    $rs = $db->query(
        "SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() "
        . "AND REFERENCED_TABLE_NAME = 'tblUsers' AND COLUMN_NAME = 'userID'"
    );
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . "` WHERE `userID` = {$who}");
        } catch (Throwable $e) {
            // A table of another shape; whether the member can go is checked below.
        }
    }
    $db->query("DELETE FROM tblUsers WHERE userID = {$who}");
};

$old = $db->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ?');
$old->bind_param('s', $email);
$old->execute();
foreach ($old->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $removeMember((int) $row['userID']);
}
$old->close();
$db->query("DELETE FROM tblDepts WHERE deptName = 'P649 receipts test'");

$ins = $db->prepare("INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin, createdAt) VALUES ('Receipts Test Member', ?, 1, 0, NOW())");
$ins->bind_param('s', $email);
$ins->execute();
$userId = (int) $db->insert_id;
$ins->close();
$db->query("INSERT IGNORE INTO tblUserSites (siteID, userID, isActive) VALUES ({$siteId}, {$userId}, 1)");
$signInName = 'selftest-p649-receipts';
$hash = password_hash($password, PASSWORD_DEFAULT);
$acc  = $db->prepare('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)');
$acc->bind_param('iss', $userId, $signInName, $hash);
$acc->execute();
$acc->close();
$db->query("INSERT INTO tblDepts (siteID, deptName, isActive) VALUES ({$siteId}, 'P649 receipts test', 1)");
$deptId = (int) $db->insert_id;
$state['undo'][] = "DELETE FROM tblDepts WHERE deptID = {$deptId}";
$state['undo'][] = "DELETE FROM tblLocalAccounts WHERE userID = {$userId}";
// Undone NEWEST FIRST, so this runs before the department's removal: a run
// stopped part-way leaves claims charged to the department, and the database
// refuses to remove a department that claims still point at (measured with
// Ctrl-C). The member's own row is removed at the start of the next run.
$state['undo'][] = "DELETE FROM tblExpenseClaims WHERE userID = {$userId}";

// Sign in through the real form.
$page = $http('GET', '/login');
$in   = $http('POST', '/login', ['csrf_token' => $tokenOn($page['body']), 'username' => $signInName, 'password' => $password]);
if (($in['status'] === 302 || $in['status'] === 303) === false) {
    refuse('signing in did not answer a redirect (status ' . $in['status'] . ')');
}
$form = $http('GET', '/expenses/submit');
if ($form['status'] !== 200 || $tokenOn($form['body']) === '') {
    refuse('the claim form was not reachable for a signed-in member (status ' . $form['status'] . ')');
}

// A real one-pixel PNG, so the server's own type check reads it as a picture.
$png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

/**
 * Submit one claim with these files, then load the page the handler sends the
 * claimant to and read the one-off message on it.
 *
 * @return array{post:array, message:string, alert:string, claim:?array, rows:list<array>, disk:list<string>}
 */
// 📛 EVERY FILE NAME THIS RUN SENDS, collected HERE, inside `$submit`, for
//    section Z. Round 2 of the independent check: the first list was kept by
//    hand, missed every section-I name and the Latin-1 "Café" name, and a
//    planted fault writing real names into the activity log left the whole
//    test green. Whatever any section sends is now in it.
$sentNames = [];

$submit = static function (string $title, array $files, ?callable $during = null, ?callable $afterPost = null) use ($http, $makeRequest, $answerOf, $tokenOn, $db, $deptId, $userId, $receipts, &$sentNames): array {
    foreach ($files as [, $fileName]) {
        if ($fileName !== '') {
            $sentNames[] = $fileName;
        }
    }
    $form    = $http('GET', '/expenses/submit');
    $request = $makeRequest('POST', '/expenses/submit/save', [
        'csrf_token' => $tokenOn($form['body']),
        'deptID'     => (string) $deptId,
        'claimTitle' => $title,
        'itemDesc[]' => ['Train fare'],
        'itemQty[]'  => ['1'],
        'itemUnit[]' => ['12.50'],
    ], $files);
    if ($during === null) {
        $post = $answerOf($request, curl_exec($request));
    } else {
        // ⏱️ Sent WITHOUT waiting, so this test can act while the handler is
        //    part-way: a planted trigger holds the receipt's row insert open
        //    (the file has already been moved by then), and `$during` is given
        //    the handler's database connection number the moment that pause is
        //    seen running. It is called once; the test then waits for the
        //    answer. A request never seen is reported by the caller's checks.
        //
        //    ⚠️ MEASURED: while a trigger runs, the process list shows the
        //       TRIGGER's statement (`SET @p649_pause = SLEEP(4)`), not the
        //       INSERT that fired it — so matching the INSERT never matched.
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $request);
        $called  = false;
        $running = 1;
        $waited  = 0;
        do {
            curl_multi_exec($multi, $running);
            if ($called === false) {
                $seen = $db->query(
                    "SELECT ID FROM information_schema.PROCESSLIST WHERE INFO LIKE 'SET @p649_pause = SLEEP%' AND ID <> CONNECTION_ID()"
                )->fetch_assoc();
                if ($seen !== null) {
                    $during((int) $seen['ID']);
                    $called = true;
                }
            }
            curl_multi_select($multi, 0.1);
            $waited++;
        } while ($running > 0 && $waited < 1200);
        $post = $answerOf($request, curl_multi_getcontent($request));
        curl_multi_remove_handle($multi, $request);
        curl_multi_close($multi);
        if ($called === false) {
            echo "  ! the handler's receipt insert was never seen running, so nothing was done part-way\n";
        }
    }
    // Something to put right between the post and reading the message (section
    // K removes the proxy's "refuse every connection" file here, or the page
    // that shows the message could not reach the database either).
    if ($afterPost !== null) {
        $afterPost();
    }
    $after   = $http('GET', '/expenses/submit');
    $message = '';
    $alert   = '';
    if (preg_match('#<div class="alert alert-([a-z]+) alert-dismissible fade show">\s*(.*?)\s*<button#s', $after['body'], $m) === 1) {
        $alert   = $m[1];
        $message = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
    }
    $q = $db->prepare('SELECT claimID, totalAmount, status FROM tblExpenseClaims WHERE userID = ? AND claimTitle = ?');
    $q->bind_param('is', $userId, $title);
    $q->execute();
    $claim = $q->get_result()->fetch_assoc();
    $q->close();
    $rows = [];
    $disk = [];
    if ($claim !== null) {
        $id   = (int) $claim['claimID'];
        $rows = $db->query("SELECT fileLabel, storedFilename, fileSize, fileType FROM tblExpenseClaimFiles WHERE claimID = {$id} AND stage IS NULL ORDER BY fileID")->fetch_all(MYSQLI_ASSOC);
        foreach ((array) @scandir($receipts) as $f) {
            if (is_string($f) === true && str_starts_with($f, $id . '_') === true) {
                $disk[] = $f;
            }
        }
    }

    return ['post' => $post, 'message' => $message, 'alert' => $alert, 'claim' => $claim, 'rows' => $rows, 'disk' => $disk];
};

/** The newest error-log rows with this code, written after `$sinceId`. */
$errorsSince = static function (int $sinceId, string $code) use ($db): array {
    $s = $db->prepare('SELECT errorTitle, errorDetail, requestHeaders FROM tblErrors WHERE errorID > ? AND errorCode = ?');
    $s->bind_param('is', $sinceId, $code);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
};
$lastErrorId = static fn (): int => (int) ($db->query('SELECT COALESCE(MAX(errorID), 0) AS m FROM tblErrors')->fetch_assoc()['m'] ?? 0);

/**
 * EVERY column of every row both logs gained after the given marks, as one
 * text. Round 1 of the independent check showed why it must be every column:
 * the first version searched only `tblErrors.errorDetail` and
 * `tblActivityLogs.activityDescription`, and a planted error-log line with
 * the claimant's file name in its TITLE left the whole test green.
 */
$logsSince = static function (int $errorId, int $activityId) use ($db): string {
    $rows = array_merge(
        $db->query("SELECT * FROM tblErrors WHERE errorID > {$errorId}")->fetch_all(MYSQLI_ASSOC),
        $db->query("SELECT * FROM tblActivityLogs WHERE logID > {$activityId}")->fetch_all(MYSQLI_ASSOC)
    );

    return (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
};
$lastActivityId = static fn (): int => (int) ($db->query('SELECT COALESCE(MAX(logID), 0) AS m FROM tblActivityLogs')->fetch_assoc()['m'] ?? 0);
// The marks for section Z, taken before the first claim of the run.
$runErrorStart    = $lastErrorId();
$runActivityStart = $lastActivityId();

/**
 * A file name as it would appear in `$logsSince()`'s text: encoded the SAME
 * way, so a name that is not valid UTF-8 ("Caf\xE9") is searched for in the
 * form the haystack really holds it, never in a form it cannot contain.
 */
$needleOf = static fn (string $name): string => substr(
    (string) json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    1,
    -1
);
/** Which of these names appear anywhere in the text. */
$namesFoundIn = static fn (string $haystack, array $names): array => array_values(array_filter(
    array_unique($names),
    static fn (string $n): bool => str_contains($haystack, $needleOf($n))
));

/** Every file in the receipts folder, so a section can tell what appeared. */
$folderNow = static function () use ($receipts): array {
    $all = @scandir($receipts);
    return is_array($all) === true ? array_values(array_diff($all, ['.', '..'])) : [];
};

// ═════════════════════════════════════════════════════════════════════════════
echo "\nB. #649's own case: a good file, one too big, one of the wrong kind, and an empty slot\n";
// ═════════════════════════════════════════════════════════════════════════════
$bigName   = 'Phone photo of the hotel bill.jpg';
$wrongName = 'Mileage notes.txt';
$goodName  = 'Train ticket ZZ-GOOD.png';
$errBefore = $lastErrorId();
$b = $submit('P649 B acceptance', [
    ['files[]', $goodName, $png],
    ['files[]', $bigName, str_repeat('x', 10 * 1024 * 1024 + 512 * 1024)],
    ['files[]', $wrongName, 'forty miles at 45p'],
    ['files[]', '', ''],
]);
check('B01 the handler answers a redirect back to the claims page',
    ($b['post']['status'] === 302 || $b['post']['status'] === 303) && str_contains($b['post']['location'], '/expenses/submit'), true);
check('B02 THE CLAIM IS SAVED (a file not attached does not refuse it)', $b['claim'] !== null, true);
check('B03 ...with the right total', (string) ($b['claim']['totalAmount'] ?? ''), '12.50');
check('B04 exactly ONE receipt row: the good file', count($b['rows']), 1);
check('B05 ...labelled as receipt 1, the numbering counting only what was kept',
    preg_match('/^Claim_\d+_Receipt_1_\d{4}-\d{2}-\d{2}\.png$/', (string) ($b['rows'][0]['fileLabel'] ?? '')), 1);
check('B06 ...read by the server as a picture, not by the name it came with',
    (string) ($b['rows'][0]['fileType'] ?? ''), 'image/png');
check('B07 THE FILE IS REALLY ON DISK, under the name the row gives',
    $b['disk'] === [(string) ($b['rows'][0]['storedFilename'] ?? '-')], true);
check('B08 ...and whole: its size on disk is the size sent',
    @filesize($receipts . DIRECTORY_SEPARATOR . ($b['rows'][0]['storedFilename'] ?? '-')), strlen($png));
check('B09 THE CLAIMANT IS WARNED, not congratulated', $b['alert'], 'warning');
check('B10 ...told that TWO files were not attached', str_contains($b['message'], '2 files you chose were NOT attached'), true);
check('B11 ...naming the big one with its reason',
    str_contains($b['message'], '"' . $bigName . '", because it is larger than 10 MB'), true);
check('B12 ...naming the wrong kind with its reason',
    str_contains($b['message'], '"' . $wrongName . '", because it is not a kind of file a receipt may be'), true);
check('B13 ...NOT naming the good file, which was attached', str_contains($b['message'], 'ZZ-GOOD'), false);
check('B14 ...and NOT reporting the empty slot (a file box with nothing chosen sends one)',
    str_contains($b['message'], 'a file with no name'), false);
check('B15 ...and saying how to add them', str_contains($b['message'], 'withdraw the claim from its own page'), true);
$act = $db->query("SELECT activityDescription FROM tblActivityLogs WHERE userID = {$userId} AND activityType = 'ExpenseSubmit' ORDER BY logID DESC LIMIT 1")->fetch_assoc();
check('B16 the activity record counts what was not attached',
    str_contains((string) ($act['activityDescription'] ?? ''), '2 file(s) not attached'), true);
$leak = $namesFoundIn($logsSince($runErrorStart, $runActivityStart), [$bigName, $wrongName, $goodName]);
check('B17 the claimant\'s own file names reach NO column of either log (#714)', $leak, []);
check('B18 no file name reached the database\'s receipt rows either',
    str_contains(json_encode($b['rows']) ?: '', 'Train ticket'), false);
check('B19 nothing was put in the error log for files the CLAIMANT got wrong',
    count($errorsSince($errBefore, 'RECEIPT_NOT_STORED')), 0);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nC. A file over the SERVER's own limit, refused by PHP before the portal sees it\n";
// ═════════════════════════════════════════════════════════════════════════════
$c = $submit('P649 C server limit', [['files[]', 'Scan of everything.pdf', str_repeat('y', 13 * 1024 * 1024)]]);
check('C01 the claim is saved', $c['claim'] !== null, true);
check('C02 no receipt row and nothing on disk', [count($c['rows']), $c['disk']], [0, []]);
check('C03 the claimant is told it was larger than THIS SERVER accepts, with the figure',
    str_contains($c['message'], '"Scan of everything.pdf", because it is larger than this server accepts for one file (12 MB)'), true);
check('C04 ...as a warning', $c['alert'], 'warning');
check('C05 ...in the one-file sentence', str_contains($c['message'], 'one file you chose was NOT attached'), true);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nD. An empty file is refused; an iPhone photo (HEIC) is attached\n";
// ═════════════════════════════════════════════════════════════════════════════
$d = $submit('P649 D empty and heic', [
    ['files[]', 'Empty.pdf', ''],
    ['files[]', 'IMG_4031.HEIC', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64)],
    // "Café bill.txt" from a computer using an older character set: é is the
    // single byte E9, which is not valid UTF-8 (round 1 of the check).
    ['files[]', "Caf\xE9 bill.txt", 'not a receipt'],
]);
check('D01 the claim is saved', $d['claim'] !== null, true);
check('D02 the HEIC photo IS attached (one row, one file on disk)', [count($d['rows']), count($d['disk'])], [1, 1]);
check('D03 ...kept as a .heic file', str_ends_with((string) ($d['rows'][0]['storedFilename'] ?? ''), '.heic'), true);
check('D04 the empty file is named as empty', str_contains($d['message'], '"Empty.pdf", because the file is empty'), true);
check('D05 ...and the HEIC is not named', str_contains($d['message'], 'IMG_4031'), false);
// ⚠️ D02 alone passes on the ORIGINAL handler by coincidence — measured: it
//    refused the HEIC and attached the EMPTY file instead, which is still "one
//    row, one file". So the empty file's absence is checked by itself.
check('D06 the empty file has NO receipt row (an empty file is no evidence)',
    count(array_filter($d['rows'], static fn (array $r): bool => (int) $r['fileSize'] === 0)), 0);
check('D07 A NAME THAT IS NOT VALID UTF-8 DOES NOT BLANK THE MESSAGE (it was printed empty)',
    str_contains($d['message'], ' bill.txt", because it is not a kind of file'), true);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nE. The folder exists but cannot be written, so the move fails\n";
// ═════════════════════════════════════════════════════════════════════════════
chmod($receipts, 0500);
clearstatcache();
if (is_writable($receipts) === true) {
    refuse('taking write permission from the receipts folder did not take; section E could not fail');
}
$errBefore = $lastErrorId();
$before    = $folderNow();
$e = $submit('P649 E move fails', [['files[]', 'Parking ZZ-MOVE.png', $png]]);
chmod($receipts, 0700);
check('E01 the claim is saved', $e['claim'] !== null, true);
check('E02 no receipt row (a file that is not there must not be listed)', count($e['rows']), 0);
check('E03 nothing new appeared in the folder', $folderNow(), $before);
check('E04 THE CLAIMANT IS TOLD it was not stored, and that it is not their fault',
    str_contains($e['message'], '"Parking ZZ-MOVE.png", because the portal could not store it, which is a fault on the server'), true);
check('E05 ...as a warning, not a success', $e['alert'], 'warning');
$moveErrors = $errorsSince($errBefore, 'RECEIPT_NOT_STORED');
check('E06 the administrator gets ONE error-log line for it', count($moveErrors), 1);
check('E07 ...naming the claim, not the claimant\'s file (#714)',
    str_contains((string) ($moveErrors[0]['errorDetail'] ?? ''), 'Claim #' . (int) ($e['claim']['claimID'] ?? 0))
        && str_contains(json_encode($moveErrors) ?: '', 'ZZ-MOVE') === false, true);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nF. The folder cannot even be MADE: the whole claim is refused\n";
// ═════════════════════════════════════════════════════════════════════════════
// The folder this run's earlier sections filled is moved aside, and the
// folder above it made unwritable, so `mkdir()` must fail.
$aside = $uploads . DIRECTORY_SEPARATOR . 'expenses-kept-by-test';
rename($receipts, $aside);
chmod($uploads, 0500);
clearstatcache();
if (is_writable($uploads) === true) {
    refuse('taking write permission from _uploads did not take; section F could not fail');
}
$errBefore = $lastErrorId();
$f = $submit('P649 F no folder', [['files[]', 'Taxi ZZ-NOFOLDER.png', $png]]);
check('F01 THE CLAIM IS NOT SAVED', $f['claim'], null);
check('F02 the claimant is told so, plainly, as a danger alert',
    [$f['alert'], $f['message']], ['danger', R::MSG_RECEIPT_FOLDER_MISSING]);
check('F03 the folder still does not exist (nothing half-made)', is_dir($receipts), false);
check('F04 the administrator gets an error-log line saying why', count($errorsSince($errBefore, 'RECEIPT_FOLDER')), 1);
// Only an EMPTY slot, which is what the real form sends when nothing is chosen.
$f2 = $submit('P649 F2 no folder no receipt', [['files[]', '', '']]);
check('F05 a claim with NO receipt is NOT refused for want of a folder it does not need', $f2['claim'] !== null, true);
check('F06 ...and is told it was submitted', [$f2['alert'], $f2['message']],
    ['success', 'Your claim #' . (int) ($f2['claim']['claimID'] ?? 0) . ' has been submitted.']);
chmod($uploads, 0700);
rename($aside, $receipts);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nG. A failure AFTER a receipt was moved takes the file away with the claim\n";
// ═════════════════════════════════════════════════════════════════════════════
// A trigger refuses the receipt's row (and only a receipt's: the generated
// PDF's row carries a stage). The file has ALREADY been moved by then, which
// is the state the clean-up exists for.
try {
    $db->query('DROP TRIGGER IF EXISTS trg_p649_refuse_receipt');
    $db->query(
        "CREATE TRIGGER trg_p649_refuse_receipt BEFORE INSERT ON tblExpenseClaimFiles FOR EACH ROW "
        . "BEGIN IF NEW.stage IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p649 planted refusal'; END IF; END"
    );
} catch (Throwable $ex) {
    refuse('could not CREATE TRIGGER on this database, so section G cannot plant a failure: ' . $ex->getMessage());
}
$state['undo'][] = 'DROP TRIGGER IF EXISTS trg_p649_refuse_receipt';
$before = $folderNow();
$g = $submit('P649 G rollback', [['files[]', 'Lunch ZZ-ROLLBACK.png', $png]]);
$db->query('DROP TRIGGER IF EXISTS trg_p649_refuse_receipt');
check('G01 the claim is rolled back', $g['claim'], null);
check('G02 the claimant is told it was not saved', [$g['alert'], $g['message']], ['danger', 'Error saving claim. Please try again.']);
check('G03 THE MOVED FILE WENT WITH IT: the folder is exactly as it was', $folderNow(), $before);

// G2: the same failure, but the file CANNOT be removed. The trigger pauses the
// receipt's row insert (the file is already moved) and then refuses it; while
// it pauses, the folder loses its write permission, so the clean-up's removal
// fails. That file must be named in the error log by its PATH — the name the
// portal made up — and never by the claimant's own name.
$slowRefusal = static function (string $then) use ($db): void {
    $db->query('DROP TRIGGER IF EXISTS trg_p649_slow');
    $db->query(
        "CREATE TRIGGER trg_p649_slow BEFORE INSERT ON tblExpenseClaimFiles FOR EACH ROW "
        . "BEGIN IF NEW.stage IS NULL THEN SET @p649_pause = SLEEP(4); {$then} END IF; END"
    );
};
$slowRefusal("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p649 planted refusal after a pause';");
$state['undo'][] = 'DROP TRIGGER IF EXISTS trg_p649_slow';
$errBefore = $lastErrorId();
$before    = $folderNow();
$g2 = $submit('P649 G2 left behind', [['files[]', 'Dinner ZZ-LEFT.png', $png]], static function (int $thread) use ($receipts): void {
    chmod($receipts, 0500);
});
chmod($receipts, 0700);
$db->query('DROP TRIGGER IF EXISTS trg_p649_slow');
$leftFiles = array_values(array_diff($folderNow(), $before));
$leftRows  = $errorsSince($errBefore, 'RECEIPT_LEFT_BEHIND');
check('G04 the claim is rolled back and the claimant told', [$g2['claim'], $g2['alert']], [null, 'danger']);
check('G05 the premise: the file really could not be removed (one left behind)', count($leftFiles), 1);
check('G06 THE ADMINISTRATOR IS TOLD, by the file\'s own path in the folder',
    count($leftRows) === 1 && str_contains((string) ($leftRows[0]['errorDetail'] ?? ''), (string) ($leftFiles[0] ?? '-')), true);
check('G07 ...and never by the claimant\'s own file name (#714)', str_contains(json_encode($leftRows) ?: '', 'ZZ-LEFT'), false);
foreach ($leftFiles as $leftover) {
    @unlink($receipts . DIRECTORY_SEPARATOR . $leftover);
}

// ═════════════════════════════════════════════════════════════════════════════
echo "\nH. A failure AFTER the commit never says the saved claim was not saved\n";
// ═════════════════════════════════════════════════════════════════════════════
// The approvers' e-mail runs after the commit. A from-address makes it go past
// its early return, and the table it reads approvers from is renamed, so it
// throws. Before #649 that throw reached the catch that said "Error saving
// claim. Please try again." — about a claim that had been saved.
$fromRow = $db->query("SELECT settingID, settingValue FROM tblSettings WHERE settingKey = 'mail.defaultFromAddress' AND siteID IS NULL")->fetch_assoc();
if ($fromRow === null) {
    refuse('the database has no installation-wide mail.defaultFromAddress row to set');
}
$oldFrom = $db->real_escape_string((string) $fromRow['settingValue']);
$db->query("UPDATE tblSettings SET settingValue = 'p649@selftest.invalid' WHERE settingID = " . (int) $fromRow['settingID']);
$state['undo'][] = "UPDATE tblSettings SET settingValue = '{$oldFrom}' WHERE settingID = " . (int) $fromRow['settingID'];
$db->query('RENAME TABLE tblUserDepts TO tblUserDepts_p649_aside');
$state['undo'][] = 'RENAME TABLE tblUserDepts_p649_aside TO tblUserDepts';
$errBefore = $lastErrorId();
$h = $submit('P649 H after commit', [['files[]', 'Hotel ZZ-AFTER.png', $png]]);
$db->query('RENAME TABLE tblUserDepts_p649_aside TO tblUserDepts');
array_pop($state['undo']);
$db->query("UPDATE tblSettings SET settingValue = '{$oldFrom}' WHERE settingID = " . (int) $fromRow['settingID']);
array_pop($state['undo']);
$hTitles = $db->query("SELECT COUNT(*) AS n FROM tblErrors WHERE errorID > {$errBefore} AND errorTitle LIKE '%tblUserDepts%'")->fetch_assoc();
check('H00 the plant really bit: the e-mail step failed and was recorded', (int) ($hTitles['n'] ?? 0) >= 1, true);
check('H01 the claim is saved', $h['claim'] !== null, true);
check('H02 ...with its receipt', count($h['rows']), 1);
check('H03 THE CLAIMANT IS TOLD IT WAS SUBMITTED, not "Error saving claim"',
    [$h['alert'], $h['message']], ['success', 'Your claim #' . (int) ($h['claim']['claimID'] ?? 0) . ' has been submitted.']);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nJ. The database connection is LOST part-way, so the rollback throws as well\n";
// ═════════════════════════════════════════════════════════════════════════════
// The realistic way a claim fails after a receipt has moved is a lost
// connection, and on a lost connection `rollback()` THROWS too ("MySQL server
// has gone away"). The first version rolled back FIRST, unguarded: the second
// exception escaped, the moved file was never removed, and the claimant got
// no message (round 1 of the independent check). Here the trigger holds the
// receipt's row insert open and this test KILLS the handler's connection.
$slowRefusal('');
$before = $folderNow();
$killed = false;
$j = $submit('P649 J connection lost', [['files[]', 'Fuel ZZ-KILLED.png', $png]], static function (int $thread) use ($db, &$killed): void {
    $db->query('KILL ' . $thread);
    $killed = true;
});
$db->query('DROP TRIGGER IF EXISTS trg_p649_slow');
array_pop($state['undo']);
check('J00 the premise: the handler\'s connection really was killed part-way', $killed, true);
check('J01 the claim is not saved (the server threw it away with the connection)', $j['claim'], null);
check('J02 THE MOVED FILE IS STILL REMOVED: the folder is exactly as it was', $folderNow(), $before);
check('J03 THE CLAIMANT IS STILL TOLD it was not saved',
    [$j['post']['status'], $j['alert'], $j['message']], [302, 'danger', 'Error saving claim. Please try again.']);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nK. The commit was SENT and could not be confirmed: no receipt is ever deleted\n";
// ═════════════════════════════════════════════════════════════════════════════
// Two designs deleted the receipt of a SAVED claim on this path, both measured
// by the independent check: round 1 removed the files on every failure, and
// round 2 asked two questions at two moments, so a commit landing between them
// read as "not saved" (10 of 60 runs). Round 3 simplified the rule: ONCE COMMIT
// HAS BEEN SENT, NO FILE IS DELETED. A new connection is asked only which
// MESSAGE to show. A small proxy sits between the portal and the database and
// acts on the commit itself (`tools/expense-receipts-commit-proxy.py`):
//   K1 forward        the server commits; the portal never hears it
//   K2 forward-refuse the same, and the portal cannot even ask afterwards
//   K3 swallow        the server never gets the commit — but the portal SENT
//                     it, cannot know, and so takes this same path: the file
//                     is KEPT, an orphan logged by path. That is the cost the
//                     simpler rule accepts, and this case pins it.
//   K4 forward-stall  the portal's new connection meets a database that
//                     answers nothing, and its short deadline must end the wait
$python = trim((string) shell_exec('command -v python3 2>/dev/null'));
if ($python === '') {
    refuse('section K needs python3 to run its commit proxy, and this machine has none');
}
if ($dbHost !== '127.0.0.1' && $dbHost !== 'localhost') {
    refuse("section K's proxy forwards to 127.0.0.1, and this database is on {$dbHost}");
}
$proxyPort = $port + 1000 + random_int(0, 200);
$arm       = $child . $sep . 'proxy-arm';
$refuseNew = $child . $sep . 'proxy-refuse';
$proxyLog  = $child . $sep . 'proxy.log';
$state['proxy'] = proc_open(
    [$python, $repo . $sep . 'tools' . $sep . 'expense-receipts-commit-proxy.py',
        (string) $proxyPort, (string) $dbPort, $arm, $refuseNew, $proxyLog],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $proxyLog, 'a'], 2 => ['file', $proxyLog, 'a']],
    $proxyPipes
);
$listening = false;
for ($i = 0; $i < 80; $i++) {
    if (str_contains((string) @file_get_contents($proxyLog), 'listening on')) {
        $listening = true;
        break;
    }
    usleep(100000);
}
if ($listening === false) {
    refuse('the commit proxy never started (see ' . $proxyLog . ')');
}
// Point THIS RUN's copy of the portal at the proxy. Only the copy's
// credentials file changes; the repository's is never touched.
$credsFile     = $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php';
$credsOriginal = (string) file_get_contents($credsFile);
file_put_contents($credsFile, "<?php\n// Section K of tools/expense-receipts-selftest.php: through the commit proxy.\nreturn "
    . var_export(['db_host' => '127.0.0.1', 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $proxyPort], true) . ";\n");
$phpErrorLog = $child . $sep . 'php-errors.log';
$proxySaid   = static fn (): string => (string) @file_get_contents($proxyLog);

/** The rows this run's error log gained with a code, after a mark. */
$keptRows = static function (int $since) use ($errorsSince): array {
    return $errorsSince($since, 'RECEIPT_KEPT_AFTER_FAILED_COMMIT');
};

// 📧 SO K06 CAN PIN THE APPROVERS' E-MAIL ITSELF (round 4 of the check), not
//    only the activity record: an approver for the department, and a sender
//    address, so a CONFIRMED save really does try to e-mail them. K05b proves
//    that on a control claim through the same proxy; without it, "no e-mail
//    for K1" would pass merely because no e-mail is ever tried here.
$approverEmail = 'p649.approver@selftest.invalid';
$oldApprover   = $db->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ?');
$oldApprover->bind_param('s', $approverEmail);
$oldApprover->execute();
foreach ($oldApprover->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $db->query('DELETE FROM tblUserDepts WHERE userID = ' . (int) $row['userID']);
    $removeMember((int) $row['userID']);
}
$oldApprover->close();
$ins = $db->prepare("INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin, createdAt) VALUES ('Receipts Test Approver', ?, 1, 0, NOW())");
$ins->bind_param('s', $approverEmail);
$ins->execute();
$approverId = (int) $db->insert_id;
$ins->close();
$db->query("INSERT IGNORE INTO tblUserSites (siteID, userID, isActive) VALUES ({$siteId}, {$approverId}, 1)");
$db->query("INSERT INTO tblUserDepts (userID, deptID, siteID, isApprover) VALUES ({$approverId}, {$deptId}, {$siteId}, 1)");
$state['undo'][] = "DELETE FROM tblUserDepts WHERE userID = {$approverId}";
$db->query("UPDATE tblSettings SET settingValue = 'p649@selftest.invalid' WHERE settingID = " . (int) $fromRow['settingID']);
$state['undo'][] = "UPDATE tblSettings SET settingValue = '{$oldFrom}' WHERE settingID = " . (int) $fromRow['settingID'];
/**
 * How many records say the approvers' e-mail about this claim was ATTEMPTED.
 *
 * Three places, because which one is written depends on how far the send gets
 * (measured on the first run of this section: the test's unconfigured mail
 * route throws "Graph creds missing" BEFORE Mailer writes `tblEmailLog`, so
 * the e-mail log alone held nothing, even for a confirmed save, and K05b went
 * red — which is exactly what it is for):
 *   - `tblEmailLog`, when Mailer reaches its own send record;
 *   - the activity record `ExpenseMailer::notify()` writes after a send;
 *   - the error-log row it writes when the send throws.
 * Any of them means the e-mail step RAN.
 */
$approverMailFor = static function (int $claimId) use ($db): int {
    $subject  = '%New Expense Claim #' . $claimId . ' %';
    $sent     = 'Sent submitted email for claim #' . $claimId . ' to %';
    $failed   = 'Failed to send submitted email for claim #' . $claimId;
    $s = $db->prepare(
        'SELECT (SELECT COUNT(*) FROM tblEmailLog WHERE subject LIKE ?)'
        . ' + (SELECT COUNT(*) FROM tblActivityLogs WHERE activityDescription LIKE ?)'
        . ' + (SELECT COUNT(*) FROM tblErrors WHERE errorTitle = ?) AS n'
    );
    $s->bind_param('sss', $subject, $sent, $failed);
    $s->execute();
    $n = (int) ($s->get_result()->fetch_assoc()['n'] ?? -1);
    $s->close();
    return $n;
};

// K0: a CONFIRMED save through the same proxy, as the control.
$k0 = $submit('P649 K0 control', [['files[]', 'Control ZZ-K0.png', $png]]);
check('K05b the premise: on a CONFIRMED save in this same set-up, the approvers\' e-mail step RUNS (an attempt is recorded)',
    [$k0['alert'], $approverMailFor((int) ($k0['claim']['claimID'] ?? 0)) >= 1], ['success', true]);

// K1: the server commits; the portal never hears it.
file_put_contents($arm, 'forward');
$errBefore = $lastErrorId();
$k1 = $submit('P649 K1 committed', [['files[]', 'Hotel ZZ-K1.png', $png], ['files[]', 'K1 notes ZZ.txt', 'not a receipt']]);
$k1Id     = (int) ($k1['claim']['claimID'] ?? 0);
$k1Stored = (string) ($k1['rows'][0]['storedFilename'] ?? '-');
$k1Kept   = $keptRows($errBefore);
check('K00 the premise: the proxy forwarded COMMIT and cut the reply', str_contains($proxySaid(), 'forwarded; dropping before the reply (forward)'), true);
check('K01 the server DID save the claim, with its receipt row', [$k1['claim'] !== null, count($k1['rows'])], [true, 1]);
check('K02 THE RECEIPT FILE IS STILL ON DISK (round 1 deleted it)', is_file($receipts . $sep . $k1Stored), true);
check('K03 THE CLAIMANT IS TOLD IT WAS SAVED, that the approvers were NOT e-mailed, and not to submit it again',
    [$k1['alert'], str_contains($k1['message'], 'Your claim #' . $k1Id . ' has been submitted'),
        str_contains($k1['message'], R::MSG_SAVED_UNCONFIRMED)], ['warning', true, true]);
check('K04 ...and the file that was not attached is still named', str_contains($k1['message'], '"K1 notes ZZ.txt", because'), true);
check('K05 ONE RECEIPT_KEPT_AFTER_FAILED_COMMIT row, written through the NEW connection, by stored path and never by the claimant\'s name',
    count($k1Kept) === 1 && str_contains((string) ($k1Kept[0]['errorDetail'] ?? ''), $k1Stored)
        && str_contains(json_encode($k1Kept) ?: '', 'ZZ-K1') === false, true);
$k1Activity = (int) ($db->query("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityDescription LIKE 'Claim #{$k1Id} created%'")->fetch_assoc()['n'] ?? -1);
check('K06 the steps after a confirmed save really did not run (no "created" record)', $k1Activity, 0);
// ⚠️ WHAT K06b CANNOT SEE, measured with a plant: a version that DID call the
//    e-mail step on this path stays green here, because in K1 the request's
//    own connection is gone and the e-mail step fails before it records
//    anything. K06b pins the message's claim for a lost connection; a commit
//    refused with the connection still up is not produced by this test.
check('K06b THE APPROVERS WERE NOT E-MAILED about K1 (no attempt recorded anywhere K05b found one), so the message saying so is true',
    $approverMailFor($k1Id), 0);

// K2: the same, and the portal cannot ask what happened.
file_put_contents($arm, 'forward-refuse');
$k2 = $submit('P649 K2 cannot tell', [['files[]', 'Taxi ZZ-K2.png', $png]], null, static function () use ($refuseNew): void {
    @unlink($refuseNew);
});
@unlink($refuseNew);
$k2Stored = (string) ($k2['rows'][0]['storedFilename'] ?? '-');
$phpLog   = (string) @file_get_contents($phpErrorLog);
check('K07 the premise: COMMIT forwarded, and the portal\'s new connection refused',
    str_contains($proxySaid(), '(forward-refuse)') && str_contains($proxySaid(), 'new connection refused'), true);
check('K08 the server did save it, with its receipt row', [$k2['claim'] !== null, count($k2['rows'])], [true, 1]);
check('K09 THE FILE IS KEPT', is_file($receipts . $sep . $k2Stored), true);
check('K10 the claimant is told it is NOT KNOWN, and to look before submitting again',
    [$k2['alert'], $k2['message']], ['danger', R::MSG_SAVE_NOT_CONFIRMED]);
check('K11 with no database to write to, the kept file is named in PHP\'s own log by its PATH, never the claimant\'s name (#714)',
    str_contains($phpLog, 'was KEPT: ') && str_contains($phpLog, $k2Stored) && str_contains($phpLog, 'ZZ-K2') === false, true);

// K3: the server never gets the commit — but the portal sent it.
file_put_contents($arm, 'swallow');
$errBefore = $lastErrorId();
$before    = $folderNow();
$k3        = $submit('P649 K3 never committed', [['files[]', 'Fuel ZZ-K3.png', $png]]);
$k3New     = array_values(array_diff($folderNow(), $before));
$k3Kept    = $keptRows($errBefore);
check('K12 the premise: the proxy did NOT forward COMMIT', str_contains($proxySaid(), 'NOT forwarded'), true);
check('K13 the claim was not saved (the server threw it away)', $k3['claim'], null);
check('K14 THE FILE IS STILL KEPT — the portal cannot know, and never deletes after sending COMMIT (the stated cost: an orphan)',
    count($k3New), 1);
check('K15 ...and it is logged by its path so an administrator can remove it',
    count($k3Kept) === 1 && str_contains((string) ($k3Kept[0]['errorDetail'] ?? ''), (string) ($k3New[0] ?? '-')), true);
check('K16 the claimant is told it is not known, never that it failed or saved',
    [$k3['alert'], $k3['message']], ['danger', R::MSG_SAVE_NOT_CONFIRMED]);
foreach ($k3New as $orphan) {
    @unlink($receipts . $sep . $orphan); // this test's own orphan, by its generated name
}

// K4: the new connection meets a database that says nothing.
file_put_contents($arm, 'forward-stall');
$started = microtime(true);
$k4 = $submit('P649 K4 silent database', [['files[]', 'Parking ZZ-K4.png', $png]], null, static function () use ($refuseNew): void {
    @unlink($refuseNew);
});
$took = microtime(true) - $started;
@unlink($refuseNew);
check('K17 the premise: the new connection was held in silence', str_contains($proxySaid(), 'new connection held silently (stall)'), true);
check('K18 THE REQUEST STILL ENDED within 20 seconds (the new connection\'s 3-second read deadline; without it nothing ends the wait until this stand-in server gives up at 120 seconds)',
    $took < 20.0, true);
check('K19 ...with the file kept and "not known" said',
    [is_file($receipts . $sep . (string) ($k4['rows'][0]['storedFilename'] ?? '-')), $k4['message']], [true, R::MSG_SAVE_NOT_CONFIRMED]);

// Back to the database directly, and the proxy stopped.
file_put_contents($credsFile, $credsOriginal);
@unlink($arm);
proc_terminate($state['proxy']);
proc_close($state['proxy']);
$state['proxy'] = null;
$db->query("UPDATE tblSettings SET settingValue = '{$oldFrom}' WHERE settingID = " . (int) $fromRow['settingID']);
$db->query("DELETE FROM tblUserDepts WHERE userID = {$approverId}");
$removeMember($approverId);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nL. The CONNECT half of the new connection's deadline\n";
// ═════════════════════════════════════════════════════════════════════════════
// Round 4 of the check: K4 drives only the READ half (its stand-in server
// accepts the connection and then says nothing). Taking the CONNECT half away
// left every check green while a connection to an address that never answers
// took 60 seconds instead of 3. Here `DbServer::connect()` is asked, with a
// 3-second deadline, to reach an address that never answers the attempt.
//
// ⚠️ THE PREMISE IS ASSERTED FIRST. Whether an address "never answers" is a
//    fact about this machine's network, not about the code: a network that
//    answers "no route" at once would make the check pass on broken code. So
//    an address is used only if a plain 5-second attempt to reach it really
//    is still waiting at the end; if none is, L00 FAILS and says this
//    environment could not test it — it never passes in silence.
require_once $repo . $sep . 'web' . $sep . '_core' . $sep . 'DbServer.php';
$blackhole = '';
foreach (['192.0.2.1', '198.51.100.1', '203.0.113.1', '10.255.255.1'] as $candidate) {
    $tried = microtime(true);
    $sock  = @stream_socket_client('tcp://' . $candidate . ':3306', $errNo, $errText, 5.0);
    $waited = microtime(true) - $tried;
    if (is_resource($sock) === true) {
        fclose($sock);
        continue; // it answered — not an address that never answers
    }
    if ($waited >= 4.5) {
        $blackhole = $candidate;
        break;
    }
}
check('L00 the premise: an address that never answers an attempt to connect exists from here (if not, this environment COULD NOT TEST the connect half)',
    $blackhole !== '', true);
$beforeSettings = [ini_get('default_socket_timeout'), ini_get('mysqlnd.net_read_timeout')];
$tried = microtime(true);
$threw = false;
try {
    \Portal\Core\DbServer::connect($blackhole !== '' ? $blackhole : '192.0.2.1', 'nobody', 'nothing', '', 3306, 3);
} catch (Throwable $e) {
    $threw = true;
}
$waited = microtime(true) - $tried;
check('L01 WITH the 3-second deadline, giving up on that address takes under 10 seconds (PHP\'s own connect deadline is 60)',
    [$threw, $waited < 10.0], [true, true]);
check('L02 ...and the request\'s own settings are put back afterwards',
    [ini_get('default_socket_timeout'), ini_get('mysqlnd.net_read_timeout')], $beforeSettings);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nI. More files than the server takes in one submission (max_file_uploads)\n";
// ═════════════════════════════════════════════════════════════════════════════
// PHP drops every file past `max_file_uploads` (20 by default; hosts set it
// lower) BEFORE the handler runs: they are not in `$_FILES` at all. Measured
// by round 1 of the independent check: three of four attached, "Your claim
// #8 has been submitted.", the fourth named nowhere.
$startServer(['max_file_uploads' => '3']);
$waitUp();
$i1 = $submit('P649 I four files', [
    ['files[]', 'I-one ZZ.png', $png], ['files[]', 'I-two ZZ.png', $png], ['files[]', 'I-three ZZ.png', $png], ['files[]', 'r4 ZZ-DROPPED.png', $png],
]);
check('I01 the premise: PHP really dropped one (three receipts kept)', [$i1['claim'] !== null, count($i1['rows'])], [true, 3]);
check('I02 THE CLAIMANT IS WARNED, not congratulated', $i1['alert'], 'warning');
check('I03 ...told PHP\'s own limit and that the rest never arrived (said as certain, because PHP said so)',
    str_contains($i1['message'], 'This server accepts at most 3 files in one submission and more than that were sent, so anything you chose beyond the first 3 never reached the portal'), true);
check('I04 ...and how to add them', str_contains($i1['message'], 'submit it again with no more than 3 files'), true);
check('I05 ...without inventing a name for the dropped file', str_contains($i1['message'], 'ZZ-DROPPED'), false);
$i2 = $submit('P649 I two files', [['files[]', 'I-one ZZ.png', $png], ['files[]', 'I-two ZZ.png', $png]]);
check('I06 fewer files than the limit: a plain success, no talk of a limit',
    [count($i2['rows']), $i2['alert'], str_contains($i2['message'], 'at most')], [2, 'success', false]);
$i3 = $submit('P649 I exactly three', [['files[]', 'I-one ZZ.png', $png], ['files[]', 'I-two ZZ.png', $png], ['files[]', 'I-three ZZ.png', $png]]);
check('I07 EXACTLY the limit: all three kept, and PHP gave no warning, so the words say IF',
    [count($i3['rows']), str_contains($i3['message'], 'so if you chose more than 3, the rest never reached the portal')], [3, true]);
// MEASURED: PHP does NOT count an empty box towards its limit (an empty box
// then three files, limit 3: all three kept, no warning). The first version of
// the fallback counted empty boxes, so two files and an unused box read as
// "as many as allowed" and told the claimant files might be missing.
$i4 = $submit('P649 I two and an empty box', [['files[]', 'I-one ZZ.png', $png], ['files[]', 'I-two ZZ.png', $png], ['files[]', '', '']]);
check('I08 two files and an EMPTY box, limit 3: a plain success (an empty box is not a file PHP counts)',
    [count($i4['rows']), $i4['alert'], str_contains($i4['message'], 'at most')], [2, 'success', false]);
$startServer();
$waitUp();

// ═════════════════════════════════════════════════════════════════════════════
echo "\nZ. Across the WHOLE run, no column of either log holds a claimant's file name\n";
// ═════════════════════════════════════════════════════════════════════════════
$allLogs = $logsSince($runErrorStart, $runActivityStart);
check('Z00 the premise: the run did write log rows to search', strlen($allLogs) > 1000, true);
// The list is collected inside `$submit`, so its size proves it was really
// filled by every section (the first version's hand-kept list missed some).
check('Z00b the premise: the names searched for include the ones the hand-kept list missed (section I\'s, and the Latin-1 "Café" name)',
    count(array_unique($sentNames)) >= 15
        && in_array("Caf\xE9 bill.txt", $sentNames, true)
        && in_array('I-one ZZ.png', $sentNames, true), true);
check('Z01 NONE of the file names the run sent appears in ANY column of tblErrors or tblActivityLogs (#714)',
    $namesFoundIn($allLogs, $sentNames), []);

// ── Tidy up the rows this test made ─────────────────────────────────────────
$removeMember($userId);

$total = $passed + $failed;
echo "\n{$total} checks: {$passed} passed, {$failed} failed.\n";
if ($total !== EXPECTED_CHECKS) {
    echo "THE COUNT IS WRONG: expected " . EXPECTED_CHECKS . " checks, ran {$total}. A block was skipped or ran twice.\n";
    exit(1);
}
echo "It drives the REAL handler over HTTP and reads the database AND the disk.\n";
echo "It cannot produce a partly-arrived file or two claims racing to make the\n";
echo "folder; section A covers the first's words, and nothing covers the second.\n";
exit($failed === 0 ? 0 : 1);
