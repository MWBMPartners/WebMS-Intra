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
 *      the wrong kind and an EMPTY SLOT (as the real form always sends) — the
 *      claim is saved, the good file is attached on disk and in the database,
 *      the two others are named with their reasons, the empty slot is not
 *      mentioned, and the claimant's file names reach no log
 *   C  a file over the SERVER's own limit (PHP refuses it before the portal
 *      sees it — the commonest real case on shared hosting)
 *   D  an empty file is refused; a HEIC photo is attached
 *   E  the folder exists but cannot be written: every move fails — the claim
 *      is saved and the file is named as NOT stored, with an error-log line
 *   F  the folder cannot even be MADE: the whole claim is refused (#649's own
 *      wording) and nothing is written; a claim with NO receipt is not refused
 *   G  a failure after a receipt was moved rolls the claim back AND takes the
 *      moved file away, so nothing is left on disk that no row points at
 *   H  a failure AFTER the commit (the approvers' e-mail) no longer tells the
 *      claimant "Error saving claim" about a claim that WAS saved
 *
 * WHAT IT CANNOT DO, said plainly
 *   - A partly-arrived file (`UPLOAD_ERR_PARTIAL`) and PHP having no temporary
 *     folder cannot be produced over HTTP here; section A covers their WORDS
 *     and their place in the rule, not the handler reaching them.
 *   - It runs ONE worker, so two claims at once racing to make the folder is
 *     untested (the handler asks `is_dir()` again after a failed `mkdir()`
 *     for exactly that, and nothing here proves it).
 *   - Sections E and F take write permission away from a folder. Run as the
 *     ROOT user that does nothing, so the test REFUSES rather than passing.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_expreceipts SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/expense-receipts-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl and fileinfo extensions, `proc_open()`, and permission
 *         to CREATE TRIGGER. It REFUSES rather than skipping when anything is
 *         missing, because a skipped test reads exactly like a passing one.
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
const EXPECTED_CHECKS = 81;

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
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
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
//    and below 13 MB (so section C's file is refused by PHP itself).
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc'] = proc_open(
    [
        PHP_BINARY,
        '-d', 'session.save_path=' . $child . $sep . 'sessions',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1', '-d', 'display_errors=0',
        '-d', 'max_execution_time=120',
        '-d', 'upload_max_filesize=12M', '-d', 'post_max_size=64M',
        '-d', 'upload_tmp_dir=' . $child,
        '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port,
        '-t', $child . $sep . 'web' . $sep . 'public_html',
        $child . $sep . 'router.php',
    ],
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

/**
 * One request. `$files` is a list of [field name, file name, bytes]; when it
 * holds anything the body is built as multipart BY HAND, because curl cannot
 * send an EMPTY slot (`filename=""`), which is exactly what the real form's
 * second file box sends on every submission.
 */
$http = static function (string $method, string $path, array $post = [], array $files = []) use ($port, $jar): array {
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
    $raw = curl_exec($handle);
    if ($raw === false) {
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

$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) === 1) {
        return $m[1];
    }
    return '';
};

$up = false;
for ($i = 0; $i < 80; $i++) {
    if ($http('GET', '/login')['status'] > 0) {
        $up = true;
        break;
    }
    usleep(250000);
}
if ($up === false) {
    refuse('the test server never answered');
}

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
$submit = static function (string $title, array $files) use ($http, $tokenOn, $db, $deptId, $userId, $receipts): array {
    $form = $http('GET', '/expenses/submit');
    $post = $http('POST', '/expenses/submit/save', [
        'csrf_token' => $tokenOn($form['body']),
        'deptID'     => (string) $deptId,
        'claimTitle' => $title,
        'itemDesc[]' => ['Train fare'],
        'itemQty[]'  => ['1'],
        'itemUnit[]' => ['12.50'],
    ], $files);
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
check('B14 ...and NOT reporting the empty slot (the real form sends one every time)',
    str_contains($b['message'], 'a file with no name'), false);
check('B15 ...and saying how to add them', str_contains($b['message'], 'withdraw the claim from its own page'), true);
$act = $db->query("SELECT activityDescription FROM tblActivityLogs WHERE userID = {$userId} AND activityType = 'ExpenseSubmit' ORDER BY logID DESC LIMIT 1")->fetch_assoc();
check('B16 the activity record counts what was not attached',
    str_contains((string) ($act['activityDescription'] ?? ''), '2 file(s) not attached'), true);
$leak = 0;
foreach (['tblActivityLogs' => 'activityDescription', 'tblErrors' => 'errorDetail'] as $t => $c) {
    foreach ([$bigName, $wrongName, $goodName] as $n) {
        $s = $db->prepare("SELECT COUNT(*) AS n FROM `{$t}` WHERE `{$c}` LIKE CONCAT('%', ?, '%')");
        $s->bind_param('s', $n);
        $s->execute();
        $leak += (int) $s->get_result()->fetch_assoc()['n'];
        $s->close();
    }
}
check('B17 the claimant\'s own file names reach NEITHER log (#714)', $leak, 0);
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
