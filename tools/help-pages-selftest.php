<?php
// Path: tools/help-pages-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Every in-app help page really opens 📖🧪
 * -----------------------------------------------------------------------------
 * Serves a copy of the portal with PHP's own web server and FETCHES all twenty
 * help pages, as an administrator and as an ordinary member, so what is checked
 * is what a person would really receive.
 *
 * WHY THIS EXISTS (#744, the owner's decision of 4 November 2026)
 * --------------------------------------------------------------
 * **The first version of this header said "nothing in this repository loaded
 * any help page". That was untrue**, and the repository's own rule is that a
 * claim of that shape must carry the command that established it. Thirteen of
 * the twenty were loaded by nothing; SEVEN were already fetched over HTTP for
 * their WORDS, by `feed-admin-selftest.php`, `mail-pages-selftest.php`,
 * `mail-queue-pages-selftest.php`, `channel-selftest.php`,
 * `page-head-selftest.php` and `portal-address-selftest.php` — none of them
 * checked for whether the page FINISHED. Established by
 * `git grep -nE "(req|pw_req|[$]get)[(].*'/help" 55b0f78^ -- tools`.
 *
 * The in-app help is what an end user actually reads — this repository's own plain-English rule says it matters
 * MORE than the developer notes, not less — and it is PHP, so a page can throw
 * and nobody would know until a member opened it.
 *
 * What that risks is on record (#583). `help/disaster-recovery.php` is the page
 * somebody opens when something has ALREADY gone wrong. It had been telling
 * customers to do two things that would have destroyed their data. A page that
 * does not open at all, at that moment, is the same kind of harm.
 *
 * WHAT IT CHECKS
 *   A. As an ADMINISTRATOR, every help address answers 200 with a WHOLE page.
 *      "Whole" matters: a page that throws part-way still answers 200, because
 *      its header has already gone — what arrives is the top of a page with
 *      nothing wrong in it. Only the closing `</html>` shows it finished.
 *   B. As an ORDINARY MEMBER, every help address answers either a whole page or
 *      a CLEAN refusal (403, or a redirect to the sign-in). Never a 500, and
 *      never a page that stops part-way. A help page an ordinary member cannot
 *      read is a decision, not a fault; a help page that CRASHES for them is a
 *      fault whoever asks.
 *   C. No answer carries the text of a PHP error, which is what a page looks
 *      like when `display_errors` is on somewhere it should not be.
 *   D. Every help page ON DISK is reachable at a registered address, and every
 *      registered help address has a file. A page nobody can open is a page
 *      nobody has checked (the #478 and #483 lesson).
 *   E. PLANTED FAULTS: with a deliberate fault put into one help page, this
 *      test must FAIL. A test that cannot see the fault it exists to catch
 *      proves nothing, and this project has shipped several such checks.
 *
 * WHAT IT CANNOT DO, said plainly because an overstated guarantee is worse
 * than none
 *   - **It does not judge whether anything the help SAYS is true.** No test
 *     can. #583's fault was advice that would have destroyed a customer's
 *     data, on a page that loaded perfectly. A green run here means the pages
 *     open, and nothing more.
 *   - It does not look at how they LOOK, nor follow the links on them. The
 *     stale-runbook-reference guard is
 *     `tools/audit-checks/check_help_page_references.py`.
 *   - It reads the pages as two made-up people only. A help page that behaves
 *     differently for some third kind of account is not covered.
 *   - It does not check the help is COMPLETE — a feature with no help page at
 *     all is invisible to it.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/help-pages-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9663 — a port of its own, so that
 *         dropping its line from a workflow cannot silently collide with a
 *         test running beside it). The database must already hold
 *         `web/_sql/full_schema.sql`. Needs PHP's curl, `proc_open()`.
 * Exit:   0 only when every check that was meant to run ran and passed; 1
 *         otherwise, including every refusal to run.
 *
 * WHAT IT CHANGES, AND PUTS BACK. In the database: two made-up people, each
 * with a number from 926000 to 926999, removed by that number. On disk: one
 * scratch folder of its own, removed while it still holds this run's marker.
 * It never opens web/_auth_keys.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/744
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Every row this test inserts has this number, or the next ones. */
const ADMIN  = 926001;
const MEMBER = 926002;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$sep    = DIRECTORY_SEPARATOR;
$repo   = (string) realpath(dirname(__DIR__));
$webSrc = $repo . $sep . 'web';
$helpSrc = $webSrc . $sep . '_apps' . $sep . 'help';
if (is_dir($helpSrc) === false) {
    refuse('web/_apps/help is not there');
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
$port    = ($portRaw === false || $portRaw === '') ? 9663 : (int) $portRaw;
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

/**
 * Remove every row with one of this test's numbers, and every row the PORTAL
 * wrote about its two people. Returns how many such rows are LEFT, which must
 * be none.
 *
 * The second half matters: signing in and opening pages makes the portal write
 * rows of its own that point at the person (the activity log, for one), and the
 * database then refuses to remove the person. So the database is asked for
 * every column that points at a person, and each is cleared of this test's
 * numbers — the same approach `tools/page-scripts-selftest.php` uses, and for
 * the same reason.
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
    foreach ([['tblLocalAccounts', 'userID'], ['tblUserSites', 'userID'], ['tblUsers', 'userID']] as [$table, $column]) {
        $columns[] = ['t' => $table, 'c' => $column];
    }
    $left = 0;
    q('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ([1, 2] as $round) {
            foreach ($columns as $column) {
                $where = '`' . $column['c'] . '` BETWEEN 926000 AND 926999';
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
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.help-pages-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and
 * is returned as status -1, which no check expects.
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
    if (preg_match('/^X-Help-Pages-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
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

/**
 * How much PHP has written to its own error log so far.
 *
 * WHY THIS EXISTS, AND IT IS THE HONEST ANSWER TO A CLAIM I GOT WRONG
 * ------------------------------------------------------------------
 * The first version of this test called the closing `</html>` "THE CHECK THAT
 * MATTERS MOST" and said that a page which throws part-way is caught because
 * only that tag shows the page finished. **That was wrong, and wrong in the
 * reassuring direction** — found by an independent check, which planted a
 * `throw` half way down a help page and watched BOTH checks pass:
 *
 *     status 200, 63,316 bytes, contains </html>: YES, no PHP error words,
 *     and the content after the throw simply missing.
 *
 * The portal's own error handler prints the footer, so a page that dies
 * mid-render still ends in an ordinary `</html>`. **`.claude/CLAUDE.md`'s own
 * record of #669 says exactly that** — "the error handler printed the footer,
 * so the answer was HTTP 200 ending in an ordinary `</html>` with the preview
 * silently missing" — so the issue I cited as proof of my claim disproves it.
 * #669 would have passed the first version of this test.
 *
 * So the real detector is the error LOG. The harness points PHP's `error_log`
 * at a file of its own and `display_errors` off, which is how a customer's
 * server is set up; an uncaught throw lands there whatever the page sends to
 * the browser. Watching that file grow across one request catches the shape
 * `</html>` cannot see.
 *
 * WHAT IT STILL CANNOT SEE, said plainly: a page that renders the wrong thing
 * without erroring, and a fault the portal CATCHES and handles deliberately
 * (which is not a fault). It also cannot tell WHICH request wrote a line when
 * two overlap — the harness runs one worker, so they do not.
 */
function errorLogSize(): int
{
    global $child, $sep;
    $file = $child . $sep . 'php-errors.log';
    clearstatcache(true, $file);

    return is_file($file) === true ? (int) filesize($file) : 0;
}

/**
 * What is wrong with one answer to a help page, in words. An empty list means
 * nothing is wrong WITH THE ANSWER — it says nothing about the words on it.
 *
 * `$mayRefuse` is true for the member, who is allowed to be turned away.
 * `$loggedBytes` is how much PHP wrote to its error log while this answer was
 * being made: anything at all means the page threw, however complete the page
 * that arrived looks. See errorLogSize() for why that is the real test and
 * `</html>` is not.
 *
 * @return list<string>
 */
function faultsIn(array $answer, bool $mayRefuse, int $loggedBytes = 0): array
{
    $wrong = [];
    $status = $answer['status'];

    if ($status === 0 || $status === -1) {
        return [trim((string) $answer['body'])];
    }
    if ($status >= 500) {
        $wrong[] = "HTTP {$status}: the page failed on the server";

        return $wrong;
    }
    if ($mayRefuse === true && ($status === 403 || $status === 404 || ($status >= 300 && $status < 400))) {
        // A clean refusal or a redirect to the sign-in. That is a DECISION
        // about who may read the page, not a fault, and this test does not
        // second-guess it — `tools/access-pages-selftest.php` is where who may
        // open what is settled.
        return $wrong;
    }
    if ($status !== 200) {
        $wrong[] = "HTTP {$status}" . ($answer['location'] === '' ? '' : ' to ' . $answer['location']);

        return $wrong;
    }
    if (trim((string) $answer['body']) === '') {
        $wrong[] = 'the answer has no page in it';

        return $wrong;
    }
    // 📌 WHAT THIS ONE REALLY PROVES, AND WHAT IT DOES NOT. A missing closing
    //    tag means the answer was cut off before the footer — a hard stop, a
    //    timeout, a killed worker. It does NOT mean the page is whole: the
    //    portal's error handler PRINTS the footer, so a page that throws
    //    half way through still ends in an ordinary `</html>`. That is #669's
    //    own recorded shape, and the first version of this test cited #669 as
    //    proof of the opposite. The error-log check below is what catches it.
    if (stripos((string) $answer['body'], '</html>') === false) {
        $wrong[] = 'the answer was cut off before the footer (no </html>)';
    }
    // 🔎 The real test for a page that threw: PHP wrote to its error log while
    //    this answer was being made. See errorLogSize().
    if ($loggedBytes > 0) {
        $wrong[] = 'PHP wrote ' . $loggedBytes . ' byte(s) to its error log while making this answer, '
                 . 'so the page threw — whatever arrived in the browser';
    }
    foreach (['Fatal error', 'Parse error', 'Uncaught ', 'Warning: ', 'Deprecated: ', 'Stack trace:'] as $leak) {
        if (str_contains((string) $answer['body'], $leak) === true) {
            $wrong[] = 'the page shows PHP\'s own error text ("' . trim($leak) . '"), which a customer must never see';
        }
    }

    return $wrong;
}

register_shutdown_function('cleanup');

// =============================================================================
// 🏗️ The world, the scratch copy and the server
// =============================================================================
$state['started'] = true;
if (removeWorld() > 0) {
    refuse('rows with this test\'s numbers (926000 to 926999) are in the database and could not be removed');
}
$site = (int) (one('SELECT MIN(siteID) AS s FROM tblSites WHERE isActive = 1')['s'] ?? 0);
if ($site <= 0) {
    refuse('the database has no organisation: load web/_sql/full_schema.sql first');
}

// 🗺️ Every help address the database really answers, read from tblRoutes
// rather than written down here. A list in this file would go stale the day
// somebody adds a help page, which is the fault this whole test is about.
// ⚠️ THE COLUMN IS `routeKey`. The first version of this line guessed
//    "routePath" and the test died on its first run with "Unknown column
//    'routePath'" — CLAUDE.md's "name things by the right identifier"
//    trap, which says to check a name against the thing that uses it
//    (here `web/_sql/full_schema.sql`) rather than against what looks
//    similar. Caught by running it, which is the point of running it.
$routes = q("SELECT routeKey, targetFile FROM tblRoutes WHERE targetFile LIKE 'help/%' ORDER BY routeKey");
if (count($routes) < 5) {
    refuse('tblRoutes holds only ' . count($routes) . ' help addresses; the database does not look like a real install');
}

// The two people.
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach ([[ADMIN, 'admin', 1], [MEMBER, 'member', 0]] as [$id, $who, $admin]) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii',
        [$id, 'Help Pages Selftest ' . ucfirst($who), "selftest-help-{$who}@help-pages.selftest.invalid", $admin, $admin]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, ?)', 'iiii', [$id, $site, $admin, $admin]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-help-' . $who, $hash]);
}

// The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . $sep . 'help-pages-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.help-pages-selftest-owner', $state['token']); // the marker FIRST
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/help-pages-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/help-pages-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Help-Pages-Selftest: __TOKEN__');
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
// nothing can outlive it. `display_errors=0` on purpose — part C proves no
// page leaks PHP's own error text, and leaving it on would make that check
// pass for the wrong reason.
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

echo "\nEvery in-app help page really opens — self-test (#744)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION
    . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo count($routes) . " help address(es) found in tblRoutes; organisation {$site}.\n";

/** Sign one made-up person in through the real sign-in page; returns their cookie jar. */
function signIn(string $who): string
{
    global $child, $sep, $password;
    $file   = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $tok    = token(req($file, 'GET', '/login')['body']);
    $answer = req($file, 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-help-' . $who, 'password' => $password]);
    if ($answer['status'] !== 302 || str_contains($answer['location'], '/login') === true) {
        refuse("signing in as selftest-help-{$who} did not work (HTTP {$answer['status']} {$answer['location']})");
    }

    return $file;
}

$jars = ['admin' => signIn('admin'), 'member' => signIn('member')];

// =============================================================================
// D. EVERY PAGE IS REACHABLE, AND EVERY ADDRESS HAS A PAGE
// =============================================================================
head('D. Every help page has an address, and every help address has a page');
$onDisk = [];
foreach (scandir($helpSrc) ?: [] as $name) {
    if (str_ends_with($name, '.php') === true) {
        $onDisk[] = 'help/' . $name;
    }
}
sort($onDisk);
$targets = array_map(static fn (array $r): string => (string) $r['targetFile'], $routes);
sort($targets);

$unreachable = array_values(array_diff($onDisk, $targets));
ok(
    'D01 every help page on disk is reachable at a registered address (' . count($onDisk) . ' pages)',
    $unreachable === [],
    'no address answers: ' . implode(', ', $unreachable) . ' — a page nobody can open is a page nobody has checked'
);
$missingFile = [];
foreach ($routes as $route) {
    if (is_file($webSrc . $sep . '_apps' . $sep . str_replace('/', $sep, (string) $route['targetFile'])) === false) {
        $missingFile[] = (string) $route['routeKey'] . ' → ' . (string) $route['targetFile'];
    }
}
ok(
    'D02 every registered help address has a file behind it (' . count($routes) . ' addresses)',
    $missingFile === [],
    'address with no file: ' . implode(', ', $missingFile)
);

// =============================================================================
// A and B. EVERY HELP PAGE OPENS
// =============================================================================
head('A. As an administrator, every help page answers a whole page');
$seen = [];
foreach ($routes as $route) {
    $path   = '/' . ltrim((string) $route['routeKey'], '/');
    $before = errorLogSize();
    $answer = req($jars['admin'], 'GET', $path);
    $wrong  = faultsIn($answer, false, errorLogSize() - $before);
    $seen[$path] = $answer['status'];
    ok(
        'A ' . $path . ' opens for an administrator',
        $wrong === [],
        'HTTP ' . $answer['status'] . ' — ' . implode(' | ', $wrong)
    );
}

head('B. As an ordinary member, every help page answers a whole page or a clean refusal');
$memberRead    = 0;
$memberRefused = [];
foreach ($routes as $route) {
    $path   = '/' . ltrim((string) $route['routeKey'], '/');
    $before = errorLogSize();
    $answer = req($jars['member'], 'GET', $path);
    $wrong  = faultsIn($answer, true, errorLogSize() - $before);
    if ($answer['status'] === 200) {
        $memberRead++;
    } else {
        $memberRefused[] = $path . ' (' . $answer['status'] . ')';
    }
    // 📌 The label is decided from the VERDICT, not from the status. The first
    //    version composed it from the status, so a 500 printed
    //    "answers a member with a clean refusal (500)" on a FAILING line — a
    //    label asserting the opposite of its own result.
    ok(
        'B ' . $path . ' answers a member ' . ($wrong !== [] ? 'without failing' : ($answer['status'] === 200 ? 'with a whole page' : 'with a clean refusal (' . $answer['status'] . ')')),
        $wrong === [],
        'HTTP ' . $answer['status'] . ' — ' . implode(' | ', $wrong)
    );
}
// 📌 WITHOUT THIS, PART B IS VACUOUS. "A whole page OR a clean refusal" is
//    satisfied completely by twenty refusals — so if every help page turned an
//    ordinary member away, every check above would pass while proving nothing
//    about a page opening for the person most likely to read it. The I7d
//    lesson (#645): when a check exists to prove something ORDINARY still
//    works, check that the fixture is actually ordinary.
ok(
    'B99 and a member really READ some of them — ' . $memberRead . ' of ' . count($routes)
    . ', so the checks above are not satisfied by refusals alone',
    $memberRead > 0,
    'every help page turned the member away, so part B proved nothing: ' . implode(', ', array_slice($memberRefused, 0, 6))
);
echo '       (a member was refused ' . count($memberRefused) . ': '
    . ($memberRefused === [] ? 'none' : implode(', ', $memberRefused)) . ")\n";

// =============================================================================
// C. THE ONE PAGE SOMEBODY OPENS MID-INCIDENT
// =============================================================================
head('C. The disaster-recovery page, which is read when something has already gone wrong');
$drRoute = null;
foreach ($routes as $route) {
    if ((string) $route['targetFile'] === 'help/disaster-recovery.php') {
        $drRoute = '/' . ltrim((string) $route['routeKey'], '/');
    }
}
ok('C01 it has a registered address at all', $drRoute !== null, 'no address in tblRoutes answers help/disaster-recovery.php');
if ($drRoute !== null) {
    $beforeDr = errorLogSize();
    $dr       = req($jars['admin'], 'GET', $drRoute);
    $drFaults = faultsIn($dr, false, errorLogSize() - $beforeDr);
    ok('C02 and it opens as a whole page for an administrator', $drFaults === [], 'HTTP ' . $dr['status'] . ' — ' . implode(' | ', $drFaults));
    // 🛟 #583 removed advice that would have destroyed a customer's data. These
    //    two pins are NOT a judgement of the page's wording — no test can do
    //    that — they are a tripwire on the two specific sentences that were
    //    wrong, so that neither comes back by a copy-and-paste from an old
    //    version. Rotating `enc.key` by hand makes every integration secret
    //    and every person's two-factor sign-in unreadable at once (runbook
    //    7.9); clearing that queue folder orphans members' photos.
    ok(
        'C03 and it no longer tells the reader to rotate enc.key by hand, which runbook 7.9 forbids',
        preg_match('/rotate\s+(?:<[^>]+>\s*)?`?enc\.key/i', (string) $dr['body']) !== 1,
        'the page says to rotate enc.key — see #583 and runbook 7.9'
    );
    ok(
        'C04 and it no longer tells the reader to clear the photo queue folder, which orphans members\' photos',
        str_contains((string) $dr['body'], '_uploads/photos/queue') === false,
        'the page names _uploads/photos/queue as something to clear — see #583'
    );
}

// =============================================================================
// E. PLANTED FAULTS — this test must see a broken help page
// =============================================================================
head('E. Planted faults: a help page that throws, and one that stops part-way');
$plantTarget = $child . $sep . 'web' . $sep . '_apps' . $sep . 'help' . $sep . 'faq.php';
$plantPath   = null;
foreach ($routes as $route) {
    if ((string) $route['targetFile'] === 'help/faq.php') {
        $plantPath = '/' . ltrim((string) $route['routeKey'], '/');
    }
}
if ($plantPath === null || is_file($plantTarget) === false) {
    ok('E00 the page used for planting is there and has an address', false, 'help/faq.php has no address, or was not copied');
} else {
    $original = (string) file_get_contents($plantTarget);

    // ⚠️ E01 — A GENUINE MID-RENDER THROW, AND THE FIRST VERSION WAS NOT ONE.
    //    It used `str_replace('<?php', …)`, which replaced ALL FOURTEEN `<?php`
    //    in the file including the one on line 1 — putting a statement before
    //    `declare(strict_types=1);`, which PHP refuses to compile. So the plant
    //    produced an HTTP 500 and was caught by the `$status >= 500` branch,
    //    while its label claimed to be testing a part-way throw. An
    //    independent check measured it: `php -l` on the mutated file said
    //    "strict_types declaration must be the very first statement".
    //
    //    Worse, the shape it CLAIMED to catch passed: a `throw` half way down
    //    the page gave 200, 63,316 bytes, a closing `</html>` and no error
    //    text, because the portal's error handler prints the footer. That is
    //    #669's own recorded shape — the issue the first version cited as
    //    proof of the opposite.
    //
    //    So the plant now throws AFTER the page has started rendering, and
    //    what must notice it is the ERROR LOG, not the closing tag. The
    //    assertion is positive — it names the fault it expects — because a bare
    //    "something was wrong" would pass on the compile error the old plant
    //    really produced.
    $afterHeader = strpos($original, "require");
    if ($afterHeader === false) {
        ok('E01 the page used for planting has a require to plant after', false, 'no require in help/faq.php');
    } else {
        $lineEnd = strpos($original, "\n", $afterHeader);
        file_put_contents(
            $plantTarget,
            substr($original, 0, $lineEnd + 1)
            . "throw new RuntimeException('planted by help-pages-selftest');\n"
            . substr($original, $lineEnd + 1)
        );
        $beforeLog = errorLogSize();
        $broken    = req($jars['admin'], 'GET', $plantPath);
        $brokenFaults = faultsIn($broken, false, errorLogSize() - $beforeLog);
        ok(
            'E01 a help page that throws AFTER rendering has started is reported, by the error log',
            $brokenFaults !== [] && str_contains(implode(' ', $brokenFaults), 'error log') === true,
            'HTTP ' . $broken['status'] . ', ' . strlen((string) $broken['body']) . ' bytes, closing tag: '
            . (stripos((string) $broken['body'], '</html>') === false ? 'no' : 'YES')
            . ' — faults found: ' . ($brokenFaults === [] ? 'NONE' : implode(' | ', $brokenFaults))
        );
        // 📌 AND THE SAME ANSWER PROVES THE CLOSING TAG CANNOT SEE IT. This is
        //    the check that keeps the header honest: if the portal ever stopped
        //    printing the footer on an error, this goes red and the wording
        //    about `</html>` has to be revisited rather than quietly becoming
        //    true again.
        ok(
            'E01b and the closing tag alone does NOT see it — the error handler prints the footer (#669\'s shape)',
            stripos((string) $broken['body'], '</html>') !== false,
            'the page did NOT end in </html>, so the footer was not printed: re-read what this test claims about "whole"'
        );
    }

    // E02 — a page that prints PHP's own error text. A customer must never see
    //       it, and part C must notice.
    file_put_contents($plantTarget, $original . "\n<p>Fatal error: planted by help-pages-selftest</p>\n");
    $beforeLeak = errorLogSize();
    $leaky      = req($jars['admin'], 'GET', $plantPath);
    $leakFaults = faultsIn($leaky, false, errorLogSize() - $beforeLeak);
    ok(
        'E02 a help page showing PHP\'s own error text is reported',
        $leakFaults !== [] && str_contains(implode(' ', $leakFaults), 'error text') === true,
        'HTTP ' . $leaky['status'] . ' — ' . implode(' | ', $leakFaults)
    );

    // Put it back, and prove the page is clean again — so a failure above
    // cannot be the leftovers of a plant.
    file_put_contents($plantTarget, $original);
    clearstatcache(true, $plantTarget);
    $beforeAgain = errorLogSize();
    $again       = req($jars['admin'], 'GET', $plantPath);
    $againFaults = faultsIn($again, false, errorLogSize() - $beforeAgain);
    ok(
        'E03 and with the page put back it opens cleanly again',
        $againFaults === [],
        'HTTP ' . $again['status'] . ' — ' . implode(' | ', $againFaults)
    );
}

// =============================================================================
// The end
// =============================================================================
cleanup();
head('Summary');
echo "Passed: {$pass}   Failed: {$fail}\n";
if ($state['left'] > 0) {
    echo "FAIL — {$state['left']} row(s) with this test's numbers are still in the database.\n";
    $fail++;
}
if ($fail > 0) {
    echo "\nA help page did not open, or this test could not see a planted fault. Either way, read the failures above.\n";
    exit(1);
}
echo "\nREACHED THE END — every help page opens, and the planted faults were seen.\n";
echo "This says NOTHING about whether the help is CORRECT. No test can.\n";
exit(0);
