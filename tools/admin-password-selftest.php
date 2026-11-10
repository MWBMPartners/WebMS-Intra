<?php
// Path: tools/admin-password-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Admin, edit a user: a new password is really stored — self-test 🔑 (#586)
 * -----------------------------------------------------------------------------
 * WHAT WENT WRONG. On `/admin/users`, editing an account and typing a new
 * password said `User "…" updated successfully.` and stored NOTHING when the
 * account had never had a password of its own — one made by an invitation
 * nobody accepted, or one that signs in with Microsoft, Google or a passkey.
 * The handler looked for the account's sign-in row and wrote the new password
 * only if it found one, so there was no row to write to and no message to say
 * so. An administrator setting somebody a password during an incident was told
 * it had worked and that person still could not sign in. Found on 12 October
 * 2026 by the independent check of the recovery runbook (#488); the runbook had
 * to carry a warning about it (docs/disaster-recovery-runbook.md, section 7.4).
 *
 * It was that way from the very first version of the page:
 *     git show a443d6c:web/public_html/admin/users/save.php
 * (7 March 2026 — the same `if ($localRow !== null)` with nothing after it).
 *
 * WHAT THIS TEST DOES. It copies `web/` into a new scratch folder, gives the
 * copy a throwaway connection file, starts PHP's own web server behind a small
 * router that imitates `.htaccess`, signs a made-up global administrator in
 * through the real sign-in page, and then presses Save on the real edit form
 * for accounts of every kind. It is modelled on `tools/page-scripts-selftest.php`
 * and `tools/erasure-pages-selftest.php`, which is where its harness comes from.
 *
 *   A. The made-up world is really as these checks need it. Said out loud and
 *      measured, because a fixture in which the branch cannot be taken tests
 *      nothing: one account HAS a sign-in row, five have none, and of those
 *      five one has a linked Microsoft account, one has a passkey, one has an
 *      e-mail address too long to be a sign-in name, and one's address is
 *      already another account's sign-in name.
 *   B. The case that always worked, as a control: a new password on an account
 *      that already has one is stored, really signs that person in, and makes
 *      no second sign-in row.
 *   C. The fault itself: a new password on an account with NO way in at all
 *      creates the sign-in row, says so, and REALLY SIGNS THAT PERSON IN —
 *      both with their e-mail address and with the new sign-in name.
 *   D. The refusals. A Microsoft-only account and a passkey-only account are
 *      refused, because whether an administrator may ADD password sign-in to
 *      such an account is the owner's decision and not this page's; so are an
 *      address too long to be a sign-in name and one already taken. Each says
 *      in words that no password was set, and NOTHING ELSE IS SAVED EITHER —
 *      measured on the phone number posted in the same form, not assumed.
 *   E. The plant. Two faults are written into the SCRATCH copy, one at a time,
 *      and each must be caught: `&& false` on the gate that creates the row
 *      (the shape that has defeated several checks in this repository), and
 *      that plus the read-back switched off, which is the ORIGINAL fault —
 *      "updated successfully" over a password that was never stored. The file
 *      is then put back and proved byte-identical to the repository's.
 *   F. THE SAME FAULT IN THE "ADD USER" BRANCH (3 November 2026). An
 *      independent check of #586 found it one branch along: the sign-in insert
 *      there is wrapped in `if ($username !== '')`, and that box is labelled
 *      "Leave blank to skip local account" — so a typed password with the box
 *      blank stored nothing and the page said "created successfully". It is
 *      now refused, in words, before anything is written. The positive half is
 *      checked too, so F01 cannot pass because every create is refused.
 *
 * WHAT IT CANNOT PROVE — read this before trusting a pass
 *   - It is not a browser and runs no script. It proves the form POSTS and the
 *     handler stores; it does not prove the Edit dialog fills itself in.
 *   - One worker, so two administrators saving at once are never tested. The
 *     sign-in name's uniqueness under a race is left to the unique key on
 *     `tblLocalAccounts.username`, which this test does not force.
 *   - Nothing here exercises the two "the database could not even prepare the
 *     statement" branches in step 10b: this connection is strict, so prepare()
 *     throws rather than answering false, and those branches are belt and
 *     braces (see the comment beside them).
 *   - It signs people in through PHP's built-in server, not Apache, and the
 *     router only imitates `.htaccess`.
 *   - It says nothing about accounts in another organisation, or about an
 *     administrator who is not a global one: AccountGuard decides that, and
 *     `tools/account-guard-selftest.php` is what proves that rule.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/admin-password-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9086). The database must already
 *         hold `web/_sql/full_schema.sql`. Needs PHP's curl and `proc_open()`.
 * Exit:   0 only when every check that was meant to run ran and passed; 1
 *         otherwise, including every refusal to run.
 *
 * WHAT IT CHANGES, AND PUTS BACK. In the database: nine made-up people and
 * their rows, every one numbered 927001 to 927999, removed by that number at
 * the START (in case a run was interrupted) and at the END. On disk: one
 * scratch folder of its own, removed while it still holds this run's marker.
 * It never opens `web/_auth_keys`. IT NEVER PRINTS OR LOGS A PASSWORD OR A
 * HASH: where it has to know whether a password was stored it asks
 * password_verify(), and where it reports, it reports yes or no.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/586
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED = 50; // five added by #598 (part G, D-108)

// 👥 The made-up people. Every one of these numbers is this test's own.
const ADMIN    = 927001; // a global administrator, who does the editing
const WITHPWD  = 927002; // a member who already has a sign-in row (the control)
const NOPWD    = 927003; // a member with NO sign-in row and no other way in — the #586 case
const MSONLY   = 927004; // a member with no sign-in row and a linked Microsoft account
const KEYONLY  = 927005; // a member with no sign-in row and a passkey
const LONGMAIL = 927006; // a member with no sign-in row whose address is too long to be a sign-in name
const CLASH    = 927007; // a member with no sign-in row whose address is already a sign-in name
const PLANT1   = 927008; // a member with no sign-in row, kept for the first plant
const PLANT2   = 927009; // a member with no sign-in row, kept for the second plant

/** The sign-in name WITHPWD is given, which is also CLASH's e-mail address — that is the clash. */
const TAKEN_NAME = 'selftest-586-taken@admin-pwd.invalid';

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
$sep     = DIRECTORY_SEPARATOR;
$repo    = (string) realpath(dirname(__DIR__));
$webSrc  = $repo . $sep . 'web';
$handler = $repo . $sep . 'web' . $sep . '_apps' . $sep . 'admin' . $sep . 'users' . $sep . 'save.php';
if (is_file($handler) === false) {
    refuse('web/_apps/admin/users/save.php is not there; this test is about that file');
}
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
// 🔌 9086: a port no other test in tools/run-all-selftests.sh is given, so
//    that this one can run beside them in the same job. A default that clashes
//    with a neighbour only shows up when somebody tidies the environment block.
$port = ($portRaw === false || $portRaw === '') ? 9086 : (int) $portRaw;
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

// =============================================================================
// 🧹 The world: made, and taken away again
// =============================================================================

/** Every table this test inserts into, with the column its numbers go in. */
const WORLD = [
    ['tblWebAuthnCredentials', 'userID'], ['tblLinkedAccounts', 'userID'],
    ['tblLocalAccounts', 'userID'], ['tblUserSites', 'userID'], ['tblUsers', 'userID'],
];

/**
 * Remove every row with one of this test's numbers, and every row the PORTAL
 * wrote about its people. Returns how many such rows are LEFT, which must be
 * none.
 *
 * The second half matters: signing in and opening pages makes the portal write
 * rows of its own that point at the person (the activity log, for one), and
 * the database then refuses to remove the person. So, as
 * `tools/page-scripts-selftest.php` does, the database is asked for every
 * column that points at a person, and each is cleared of this test's numbers.
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
                $where = '`' . $column['c'] . '` BETWEEN 927001 AND 927999';
                if ($round === 1) {
                    q('DELETE FROM `' . $column['t'] . '` WHERE ' . $where);
                } else {
                    $left += (int) (one('SELECT COUNT(*) AS n FROM `' . $column['t'] . '` WHERE ' . $where)['n'] ?? 0);
                }
            }
        }
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

$state = ['started' => false, 'cleaned' => false, 'proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'left' => -1];

/** Put back everything this run changed, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    // The server, by the process proc_open() gave — never by a name.
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
        $state['left'] = removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.admin-password-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}
register_shutdown_function('cleanup');

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed, because the answer this test reads is the redirect. An answer
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
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 150,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:', 'Accept-Language: en'],
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
    if (preg_match('/^X-Admin-Password-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
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
    refuse('rows with this test\'s numbers (927001 to 927999) are in the database and could not be removed');
}
$site = (int) (one('SELECT MIN(siteID) AS s FROM tblSites WHERE isActive = 1')['s'] ?? 0);
if ($site <= 0) {
    refuse('the database has no organisation: load web/_sql/full_schema.sql first');
}

// 🔑 One password for everybody who starts with one, and one the administrator
//    will type into the form. Both satisfy the portal's own policy (at least
//    twelve characters, upper, lower, a number and a punctuation mark). Built
//    fresh each run, never written down anywhere.
$oldPassword = 'Aa1!old-selftest586-' . bin2hex(random_bytes(4));
$newPassword = 'Aa1!new-selftest586-' . bin2hex(random_bytes(4));
$oldHash     = password_hash($oldPassword, PASSWORD_DEFAULT);

$address = static fn (int $id): string => match ($id) {
    LONGMAIL => 'selftest-586-a-very-long-address-indeed-' . str_repeat('x', 12) . '@admin-pwd.invalid',
    CLASH    => TAKEN_NAME,
    default  => 'selftest-586-' . $id . '@admin-pwd.invalid',
};
$people = [
    ADMIN    => ['name' => 'Admin Password Selftest Administrator', 'admin' => 1],
    WITHPWD  => ['name' => 'Admin Password Selftest Has Password', 'admin' => 0],
    NOPWD    => ['name' => 'Admin Password Selftest No Way In',    'admin' => 0],
    MSONLY   => ['name' => 'Admin Password Selftest Microsoft',    'admin' => 0],
    KEYONLY  => ['name' => 'Admin Password Selftest Passkey',      'admin' => 0],
    LONGMAIL => ['name' => 'Admin Password Selftest Long Address', 'admin' => 0],
    CLASH    => ['name' => 'Admin Password Selftest Name Taken',   'admin' => 0],
    PLANT1   => ['name' => 'Admin Password Selftest Plant One',    'admin' => 0],
    PLANT2   => ['name' => 'Admin Password Selftest Plant Two',    'admin' => 0],
];
foreach ($people as $id => $who) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, phoneNumber, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, ?, 1, ?, ?)',
        'isssii', [$id, $who['name'], $address($id), '01000 000000', $who['admin'], $who['admin']]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, ?)',
        'iiii', [$id, $site, $who['admin'], $who['admin']]);
}
// Only two people start with a sign-in row. WITHPWD's sign-in NAME is
// deliberately CLASH's e-mail address: that is what makes CLASH's save the
// "this sign-in name is already taken" case, and nothing else in this world
// could produce it.
q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)',
    'iss', [ADMIN, 'selftest-586-admin', $oldHash]);
q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)',
    'iss', [WITHPWD, TAKEN_NAME, $oldHash]);
// One linked Microsoft account, and one passkey, each on its own person — two
// separate people on purpose. Auth::countLoginMethods() adds three sources up,
// so one person holding both could not tell you which source was being read.
q('INSERT INTO tblLinkedAccounts (userID, provider, providerSub, providerEmail) VALUES (?, \'ms365\', ?, ?)',
    'iss', [MSONLY, 'selftest-586-subject-' . bin2hex(random_bytes(6)), $address(MSONLY)]);
q('INSERT INTO tblWebAuthnCredentials (userID, credentialID, publicKey, friendlyName) VALUES (?, ?, ?, \'Selftest key\')',
    'iss', [KEYONLY, bin2hex(random_bytes(16)), bin2hex(random_bytes(32))]);

// The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . $sep . 'admin-password-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.admin-password-selftest-owner', $state['token']); // the marker FIRST
mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/admin-password-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

/** The copy of the handler that the plants in part E are written into. */
$scratchHandler = $child . $sep . 'web' . $sep . '_apps' . $sep . 'admin' . $sep . 'users' . $sep . 'save.php';

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/admin-password-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Admin-Password-Selftest: __TOKEN__');
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

// The server: its command as a LIST (no shell in between), ONE process, so
// nothing can outlive it. No stored copies of compiled pages: part E changes a
// file and must be served the changed one.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc']     = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'max_execution_time=120', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']],
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

echo "\nAdmin, edit a user: a new password is really stored — self-test (#586)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION
    . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "organisation {$site}; nine made-up people, 927001 to 927009.\n";

/**
 * Sign one of this test's people in through the real sign-in page, under the
 * name or address given. Answers their cookie jar, or '' when the portal would
 * not let them in — which several checks here want to know.
 */
function trySignIn(string $identifier, string $password): string
{
    global $child, $sep;
    $file   = $child . $sep . 'jar-' . substr(sha1($identifier . $password), 0, 10) . '.txt';
    @unlink($file);
    $tok    = token(req($file, 'GET', '/login')['body']);
    $answer = req($file, 'POST', '/login', ['csrf_token' => $tok, 'username' => $identifier, 'password' => $password]);
    if ($answer['status'] !== 302 || str_contains($answer['location'], '/login') === true) {
        return '';
    }

    return $file;
}

$adminJar = trySignIn('selftest-586-admin', $oldPassword);
if ($adminJar === '') {
    refuse('the made-up administrator could not sign in, so nothing below could be pressed');
}

/**
 * Press Save on the real Edit dialog for one account, exactly as the form
 * does. The form token is taken from a fresh load of the page, because
 * Auth::verifyCsrf() replaces it on every acceptance. Answers the flash
 * message the NEXT page load shows, which is where the page tells the
 * administrator what happened.
 *
 * @return array{status:int, flash:string, type:string}
 */
function pressSave(int $userId, string $password, string $phone = '01000 000000'): array
{
    global $adminJar, $address, $people;
    $page = req($adminJar, 'GET', '/admin/users');
    $tok  = token($page['body']);
    if ($tok === '') {
        return ['status' => -2, 'flash' => 'no form token on /admin/users', 'type' => ''];
    }
    $answer = req($adminJar, 'POST', '/admin/users/save', [
        'csrf_token'   => $tok,
        'action'       => 'update',
        'userID'       => (string) $userId,
        'fullName'     => $people[$userId]['name'],
        'emailAddress' => $address($userId),
        'phoneNumber'  => $phone,
        'isActive'     => '1',
        'password'     => $password,
    ]);
    $after = req($adminJar, 'GET', '/admin/users');
    $flash = '';
    $type  = '';
    // The flash block, and ONLY the flash block: the one dismissible alert
    // this page draws for it (admin/users/index.php). A looser pattern would
    // also match the #533 "accounts with no organisation" warning below it, so
    // the test would read somebody else's sentence and judge that.
    if (preg_match('#<div class="alert alert-([a-z]+) alert-dismissible fade show">(.*?)<button#s', $after['body'], $m) === 1) {
        $type  = $m[1];
        $flash = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
    }

    return ['status' => $answer['status'], 'flash' => preg_replace('/\s+/', ' ', $flash), 'type' => $type];
}

/** How many sign-in rows one account has. */
function signInRows(int $userId): int
{
    return (int) (one('SELECT COUNT(*) AS n FROM tblLocalAccounts WHERE userID = ?', 'i', [$userId])['n'] ?? 0);
}

/** The sign-in name on an account's one row, or '' when it has none. */
function signInName(int $userId): string
{
    return (string) (one('SELECT username FROM tblLocalAccounts WHERE userID = ? LIMIT 1', 'i', [$userId])['username'] ?? '');
}

/**
 * Does the password stored for this account match the one given? Asked with
 * password_verify() so that nothing here has to hold, print or compare a hash
 * itself. An account with no row answers false, which is the truth: no
 * password is stored for it.
 */
function storedPasswordIs(int $userId, string $password): bool
{
    $row = one('SELECT passwordHash FROM tblLocalAccounts WHERE userID = ? LIMIT 1', 'i', [$userId]);
    if ($row === null || (string) $row['passwordHash'] === '') {
        return false;
    }

    return password_verify($password, (string) $row['passwordHash']);
}

/** The phone number stored for an account, which is how "nothing else was saved either" is measured. */
function storedPhone(int $userId): string
{
    return (string) (one('SELECT phoneNumber FROM tblUsers WHERE userID = ? LIMIT 1', 'i', [$userId])['phoneNumber'] ?? '');
}

// =============================================================================
// A. THE MADE-UP WORLD IS REALLY AS THESE CHECKS NEED IT
// =============================================================================
head('A. The world: one account with a password, five without, each without for its own reason');
ok('A01 the control account has exactly one sign-in row, and the password it was given is what is stored',
    signInRows(WITHPWD) === 1 && storedPasswordIs(WITHPWD, $oldPassword) === true,
    signInRows(WITHPWD) . ' row(s); stored password matches: ' . json_encode(storedPasswordIs(WITHPWD, $oldPassword)));
ok('A02 the #586 account has NO sign-in row, no linked account and no passkey — no way in at all',
    signInRows(NOPWD) === 0
    && (int) one('SELECT COUNT(*) AS n FROM tblLinkedAccounts WHERE userID = ?', 'i', [NOPWD])['n'] === 0
    && (int) one('SELECT COUNT(*) AS n FROM tblWebAuthnCredentials WHERE userID = ?', 'i', [NOPWD])['n'] === 0);
ok('A03 the Microsoft account has no sign-in row and exactly one linked account',
    signInRows(MSONLY) === 0 && (int) one('SELECT COUNT(*) AS n FROM tblLinkedAccounts WHERE userID = ?', 'i', [MSONLY])['n'] === 1);
ok('A04 the passkey account has no sign-in row and exactly one passkey — a different source from A03, counted on its own',
    signInRows(KEYONLY) === 0 && (int) one('SELECT COUNT(*) AS n FROM tblWebAuthnCredentials WHERE userID = ?', 'i', [KEYONLY])['n'] === 1
    && (int) one('SELECT COUNT(*) AS n FROM tblLinkedAccounts WHERE userID = ?', 'i', [KEYONLY])['n'] === 0);
ok('A05 the long-address account has no sign-in row and an address of more than 50 characters',
    signInRows(LONGMAIL) === 0 && mb_strlen($address(LONGMAIL), 'UTF-8') > 50,
    mb_strlen($address(LONGMAIL), 'UTF-8') . ' characters');
ok('A06 the clashing account has no sign-in row, and its address is already ANOTHER account\'s sign-in name',
    signInRows(CLASH) === 0 && $address(CLASH) === TAKEN_NAME && signInName(WITHPWD) === TAKEN_NAME
    && mb_strlen(TAKEN_NAME, 'UTF-8') <= 50,
    'the taken name is held by account ' . (string) (one('SELECT userID FROM tblLocalAccounts WHERE username = ?', 's', [TAKEN_NAME])['userID'] ?? 'nobody'));
$usersPage = req($adminJar, 'GET', '/admin/users');
ok('A07 the administrator can open the real user list, which is where the Edit dialog and its form token live',
    $usersPage['status'] === 200 && token($usersPage['body']) !== '' && str_contains($usersPage['body'], 'id="editUserModal"') === true,
    'HTTP ' . $usersPage['status']);

// =============================================================================
// B. THE CONTROL: AN ACCOUNT THAT ALREADY HAS A PASSWORD
// =============================================================================
head('B. An account that already has a password: the case that always worked');
$saved = pressSave(WITHPWD, $newPassword);
ok('B01 the save answers a redirect and the page says it worked',
    $saved['status'] === 302 && $saved['type'] === 'success' && str_contains($saved['flash'], 'updated successfully') === true,
    'HTTP ' . $saved['status'] . ', ' . $saved['type'] . ': ' . $saved['flash']);
ok('B02 the message does NOT claim a sign-in was created, because this account already had one',
    str_contains($saved['flash'], 'sign-in was created') === false, $saved['flash']);
ok('B03 the new password is what is stored now, and the old one is not', storedPasswordIs(WITHPWD, $newPassword) === true && storedPasswordIs(WITHPWD, $oldPassword) === false);
ok('B04 and that person can really sign in with it', trySignIn($address(WITHPWD), $newPassword) !== '');
ok('B05 no second sign-in row was made', signInRows(WITHPWD) === 1, signInRows(WITHPWD) . ' row(s)');

// =============================================================================
// C. THE FAULT (#586): AN ACCOUNT WITH NO WAY IN AT ALL
// =============================================================================
head('C. An account with no password and no other way in: #586 itself');
$saved = pressSave(NOPWD, $newPassword, '01999 111222');
ok('C01 the save answers a redirect and the page says it worked',
    $saved['status'] === 302 && $saved['type'] === 'success' && str_contains($saved['flash'], 'updated successfully') === true,
    'HTTP ' . $saved['status'] . ', ' . $saved['type'] . ': ' . $saved['flash']);
ok('C02 and it SAYS a password sign-in was created, which "updated successfully" alone would not tell anybody',
    str_contains($saved['flash'], 'A password sign-in was created') === true, $saved['flash']);
ok('C03 a sign-in row now exists, under the account\'s e-mail address as its sign-in name',
    signInRows(NOPWD) === 1 && signInName(NOPWD) === $address(NOPWD),
    signInRows(NOPWD) . ' row(s), name "' . signInName(NOPWD) . '"');
ok('C04 the password that was typed is the one stored', storedPasswordIs(NOPWD, $newPassword) === true);
ok('C05 and that person can really sign in with their e-mail address', trySignIn($address(NOPWD), $newPassword) !== '');
ok('C06 and with the new sign-in name, which Auth::loginLocal() accepts as well', trySignIn(signInName(NOPWD), $newPassword) !== '');
ok('C07 the other field posted in the same form was saved too', storedPhone(NOPWD) === '01999 111222', 'stored "' . storedPhone(NOPWD) . '"');

// =============================================================================
// D. THE REFUSALS: NOTHING SAVED, AND THE PAGE SAYS SO
// =============================================================================
head('D. The four refusals: no password set, nothing else saved either, and the reason in words');
// [who, what the message must say, what it must not say]
$refusals = [
    [MSONLY,   'signs in another way',                   'an account that signs in with Microsoft'],
    [KEYONLY,  'signs in another way',                   'an account that signs in with a passkey'],
    [LONGMAIL, 'longer than 50 characters',              'an address too long to be a sign-in name'],
    [CLASH,    'that sign-in name cannot be used',       'an address that is already a sign-in name'],
];
$index = 1;
foreach ($refusals as [$who, $mustSay, $what]) {
    $saved = pressSave($who, $newPassword, '01888 333444');
    ok(sprintf('D%02d %s: the page says no password was set, and says why', $index, $what),
        str_contains($saved['flash'], 'No password was set') === true && str_contains($saved['flash'], $mustSay) === true,
        $saved['type'] . ': ' . $saved['flash']);
    ok(sprintf('D%02d %s: still no sign-in row, and no password stored', $index + 1, $what),
        signInRows($who) === 0 && storedPasswordIs($who, $newPassword) === false,
        signInRows($who) . ' row(s)');
    ok(sprintf('D%02d %s: and the phone number posted in the same form was NOT saved either, which the message promises', $index + 2, $what),
        storedPhone($who) === '01000 000000' && str_contains($saved['flash'], 'nothing else was saved either') === true,
        'stored "' . storedPhone($who) . '"');
    $index += 3;
}

// =============================================================================
// E. THE PLANT: PROVE THESE CHECKS WOULD CATCH THE FAULT COMING BACK
// =============================================================================
head('E. Two planted faults in the SCRATCH copy, each caught, and the file put back');
$asShipped = (string) file_get_contents($scratchHandler);
$inRepo    = (string) file_get_contents($handler);
if ($asShipped !== $inRepo) {
    refuse('the scratch copy of the handler is not the same as the repository\'s before any plant');
}

/**
 * Write one or more replacements into the scratch copy of the handler,
 * refusing unless EACH changed exactly one place. A plant that quietly matched
 * nothing would leave the code as it is, every check below would pass, and the
 * passing run would read as proof that the checks catch the fault.
 *
 * @param array<string,string> $swaps what to replace => what to put there
 */
function plant(array $swaps): string
{
    global $scratchHandler, $asShipped;
    $after = $asShipped;
    foreach ($swaps as $from => $to) {
        $count = 0;
        $after = str_replace($from, $to, $after, $count);
        if ($count !== 1) {
            refuse("the plant '{$from}' matched {$count} place(s) in the handler, not exactly one — a plant that changes nothing proves nothing");
        }
    }
    file_put_contents($scratchHandler, $after);
    clearstatcache(true, $scratchHandler);

    return $after;
}

// Plant 1: the sign-in name is never handed over, so no row is ever created.
//
// 🔁 RE-AIMED FOR #728, NOT WEAKENED. This used to be `&& false` on
//    `if ($makeSignIn === true) {`, the page's own create-or-update gate.
//    #728 moved that choice into `Auth::setPassword()`, which decides it from
//    what is in the database rather than from a flag — so the old plant
//    matched nothing and this test REFUSED, which is how it should fail when
//    the code moves out from under it (#667: removing a feature must not
//    remove the test guarding a past fault).
//
//    The purpose is unchanged: stop the sign-in row from being created and
//    the save must NOT report success. Passing `null` as the sign-in name is
//    exactly how you say "update an existing row and refuse if there is
//    none", so for an account with no row this reproduces the same harm by
//    the same route the new code really takes.
plant(['$makeSignIn === true ? $signInName : null' => 'null']);
$saved = pressSave(PLANT1, $newPassword);
ok('E01 with "&& false" on the gate that creates the sign-in row, the save does NOT report success',
    $saved['type'] !== 'success' && str_contains($saved['flash'], 'A password sign-in was created') === false,
    $saved['type'] . ': ' . $saved['flash']);
ok('E02 and nothing was stored, so C01-C04 would have failed on it',
    signInRows(PLANT1) === 0 && storedPasswordIs(PLANT1, $newPassword) === false,
    signInRows(PLANT1) . ' row(s)');

// Plant 2: the same, plus the read-back switched off. THIS IS THE ORIGINAL
// #586 FAULT — the page says "updated successfully" over a password that was
// never stored — and it is what part C exists to catch.
// 🔁 ALSO RE-AIMED. The read-back itself now lives in `Auth::setPassword()`,
//    where every caller gets it, so the page cannot switch it off — what the
//    page CAN do is ignore the answer, which is the same fault seen from the
//    page's side and is what `if ($stored['ok'] === false)` exists to stop.
//    Both halves together put the original #586 behaviour back exactly: the
//    page says "updated successfully" over a password that was never stored.
$planted = plant([
    '$makeSignIn === true ? $signInName : null' => 'null',
    // ⚠️ The condition alone appears TWICE in the handler (the Add branch has
    //    its own), and `plant()` refuses a swap that is not unique — rightly,
    //    because a plant that changes two places proves neither. The throw
    //    below it differs between the two branches, so the pair is the anchor.
    "if (\$stored['ok'] === false) {\n                throw new \\RuntimeException(\$stored['why']);"
        => "if (false) {\n                throw new \\RuntimeException(\$stored['why']);",
]);
ok('E03 the second plant really went in: no row is ever created and the refusal is ignored',
    str_contains($planted, 'if (false) {') === true
    && str_contains($planted, '$makeSignIn === true ? $signInName : null') === false
    && $planted !== $asShipped
    && (string) file_get_contents($scratchHandler) === $planted);
$saved = pressSave(PLANT2, $newPassword);
ok('E04 the original fault is back: the page says "updated successfully" …',
    $saved['type'] === 'success' && str_contains($saved['flash'], 'updated successfully') === true,
    $saved['type'] . ': ' . $saved['flash']);
ok('E05 … while NOTHING was stored — which is exactly what C01 to C04 go red on',
    signInRows(PLANT2) === 0 && storedPasswordIs(PLANT2, $newPassword) === false,
    signInRows(PLANT2) . ' row(s)');

// Put the file back and prove it, byte for byte, against the repository's own
// copy — not against anything this test made from it.
file_put_contents($scratchHandler, $asShipped);
clearstatcache(true, $scratchHandler);
ok('E06 the handler in the scratch copy is byte-identical to the repository\'s again',
    hash_file('sha256', $scratchHandler) === hash_file('sha256', $handler),
    hash_file('sha256', $scratchHandler) . ' vs ' . hash_file('sha256', $handler));
$saved = pressSave(PLANT1, $newPassword);
ok('E07 and with the plants gone the same save works: the sign-in row is created and the password is stored',
    $saved['type'] === 'success' && str_contains($saved['flash'], 'A password sign-in was created') === true
    && signInRows(PLANT1) === 1 && storedPasswordIs(PLANT1, $newPassword) === true,
    $saved['type'] . ': ' . $saved['flash'] . '; ' . signInRows(PLANT1) . ' row(s)');

// =============================================================================
// F. THE SAME FAULT IN THE "ADD USER" BRANCH (#586 part two, 3 November 2026)
// =============================================================================
// 🔑 WHY THIS SECTION EXISTS. An independent check of #586 found the identical
//    shape one branch along: the sign-in insert in the CREATE branch is wrapped
//    in `if ($username !== '')`, and the Add User form labels that box "Leave
//    blank to skip local account". So typing a password and leaving the box
//    blank stored NO PASSWORD and the page said 'User "…" created
//    successfully.' — a success message over a write that did not happen,
//    which is the whole of #586.
//
//    It is driven over real HTTP, like the rest of this file, and not read.
//
// 🧹 THE REFUSAL CASE CREATES NOTHING, which is why it is tested first and why
//    this section cleans up after the positive case by ADDRESS: an account the
//    portal creates gets an auto-increment number outside this test's own
//    927001-927999 range, so `removeWorld()` cannot see it and Z01 would pass
//    over a row left behind.
head('F. "Add User": a typed password with no sign-in name is refused, not swallowed');

/**
 * Press Save on the real Add User dialog, exactly as that form does.
 *
 * @return array{status:int, flash:string, type:string}
 */
$pressAdd = static function (string $name, string $email, string $username, string $password): array {
    global $adminJar;
    $page = req($adminJar, 'GET', '/admin/users');
    $tok  = token($page['body']);
    if ($tok === '') {
        return ['status' => -2, 'flash' => 'no form token on /admin/users', 'type' => ''];
    }
    $answer = req($adminJar, 'POST', '/admin/users/save', [
        'csrf_token'       => $tok,
        'action'           => 'create',
        'fullName'         => $name,
        'emailAddress'     => $email,
        'phoneNumber'      => '01000 111111',
        'username'         => $username,
        'password'         => $password,
        'password_confirm' => $password,
        'isActive'         => '1',
    ]);
    $after = req($adminJar, 'GET', '/admin/users');
    $flash = '';
    $type  = '';
    if (preg_match('#<div class="alert alert-([a-z]+) alert-dismissible fade show">(.*?)<button#s', $after['body'], $m) === 1) {
        $type  = $m[1];
        $flash = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
    }

    return ['status' => $answer['status'], 'flash' => preg_replace('/\s+/', ' ', $flash), 'type' => $type];
};

/** How many accounts hold one address. */
$accountsAt = static fn (string $email): int => (int) (
    one('SELECT COUNT(*) AS n FROM tblUsers WHERE emailAddress = ?', 's', [$email])['n'] ?? 0
);

$addrSwallow = 'selftest-586-create-swallowed@admin-pwd.invalid';
$addrCreated = 'selftest-586-create-works@admin-pwd.invalid';
$createdPass = $newPassword . 'Fx1!';

$added = $pressAdd('Admin Password Selftest Swallowed', $addrSwallow, '', $createdPass);
ok('F01 a password with the sign-in name box left blank is REFUSED, and the page says why',
    $added['type'] === 'danger'
    && str_contains($added['flash'], 'No account was created') === true
    && str_contains($added['flash'], 'sign-in name') === true,
    $added['type'] . ': ' . $added['flash']);
ok('F02 and NO account was created, so no success message could ever sit over a missing password',
    $accountsAt($addrSwallow) === 0,
    (string) $accountsAt($addrSwallow) . ' account(s) at that address');
ok('F03 the refusal does not claim success anywhere in its words',
    str_contains($added['flash'], 'created successfully') === false,
    $added['flash']);

// 📌 THE POSITIVE HALF, so F01 cannot pass because every create is refused —
//    which is exactly how a check comes to guard nothing.
$added = $pressAdd('Admin Password Selftest Created', $addrCreated, 'selftest-586-created', $createdPass);
$createdId = (int) (one('SELECT userID FROM tblUsers WHERE emailAddress = ? LIMIT 1', 's', [$addrCreated])['userID'] ?? 0);
ok('F04 the same form WITH a sign-in name does create the account and store the password',
    $added['type'] === 'success'
    && str_contains($added['flash'], 'created successfully') === true
    && $createdId > 0
    && signInRows($createdId) === 1
    && storedPasswordIs($createdId, $createdPass) === true,
    $added['type'] . ': ' . $added['flash'] . '; id ' . (string) $createdId);
ok('F05 and that person can really sign in with the password that was typed',
    $createdId > 0 && trySignIn($addrCreated, $createdPass) !== '');

// 🧹 Remove the one account this section really created, by its own number.
if ($createdId > 0) {
    q('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['tblLocalAccounts', 'tblUserSites', 'tblUsers'] as $table) {
        // The table name is one of three written here; the number is bound.
        q('DELETE FROM `' . $table . '` WHERE `userID` = ?', 'i', [$createdId]);
    }
    q('SET FOREIGN_KEY_CHECKS = 1');
}
ok('F06 and this section left no account of its own behind',
    $accountsAt($addrCreated) === 0 && $accountsAt($addrSwallow) === 0,
    (string) $accountsAt($addrCreated) . ' + ' . (string) $accountsAt($addrSwallow));

// =============================================================================
// G. D-108: AN ADMINISTRATOR SETTING A PASSWORD SIGNS THAT PERSON OUT EVERYWHERE
// =============================================================================
head('G. D-108: an administrator setting a password signs that person out of every browser (#598)');
/**
 * A NEW browser signed in as somebody, with a cookie jar of its own. Not
 * trySignIn(): that names its jar after the name and password, so a second
 * browser for the same person would overwrite the first.
 */
$browserOf = static function (string $identifier, string $password): string {
    global $child, $sep;
    $file   = $child . $sep . 'jar-g-' . bin2hex(random_bytes(5)) . '.txt';
    $tok    = token(req($file, 'GET', '/login')['body']);
    $answer = req($file, 'POST', '/login', ['csrf_token' => $tok, 'username' => $identifier, 'password' => $password]);

    return ($answer['status'] === 302 && str_contains($answer['location'], '/login') === false) ? $file : '';
};
/** What the account page answers this browser: 200 signed in, 302 sent to sign in. */
$accountStatus = static fn (string $jar): int => req($jar, 'GET', '/account')['status'];

$memberA = $browserOf($address(WITHPWD), $newPassword);
$memberB = $browserOf($address(WITHPWD), $newPassword);
ok('G01 set-up: the member is signed in on two browsers',
    $memberA !== '' && $memberB !== '' && $accountStatus($memberA) === 200 && $accountStatus($memberB) === 200);
$d108Password = $newPassword . 'Gd8!';
$saved        = pressSave(WITHPWD, $d108Password);
ok('G02 the administrator sets a new password, and the page says plainly that the person was signed out of every browser',
    $saved['type'] === 'success' && str_contains($saved['flash'], 'signed out of every browser') === true && storedPasswordIs(WITHPWD, $d108Password) === true,
    $saved['type'] . ': ' . $saved['flash']);
ok('G03 BOTH of the member\'s browsers are sent to sign in on their next request (red before D-108: both stayed in)',
    $accountStatus($memberA) === 302 && $accountStatus($memberB) === 302);
ok('G04 the administrator who set it is a different person and is still signed in',
    req($adminJar, 'GET', '/admin/users')['status'] === 200);
$memberC = $browserOf($address(WITHPWD), $d108Password);
ok('G05 the member signs in with the new password, and that browser stays signed in',
    $memberC !== '' && $accountStatus($memberC) === 200 && $accountStatus($memberC) === 200);

// =============================================================================
// 🧾 The count, the clean-up, and the verdict
// =============================================================================
cleanup();
ok('Z01 the clean-up left none of this test\'s rows behind', $state['left'] === 0, (string) $state['left'] . ' row(s) left');
// Counted AFTER Z01, so the number pinned above is every check this file makes.
$ran = $pass + $fail;
if ($ran !== EXPECTED) {
    echo "\nFAIL — " . $ran . ' checks ran, and ' . EXPECTED . " were expected. A block was skipped or ran twice.\n";
    $fail++;
}
$ran = $pass + $fail;
echo "\n" . $pass . ' passed, ' . $fail . ' failed, of ' . $ran . " checks.\n";
exit($fail === 0 ? 0 : 1);
