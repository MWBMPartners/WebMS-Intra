<?php
// Path: tools/installer-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The installer, driven for real — self-test 🔑 (#499, #500)
 * -----------------------------------------------------------------------------
 * Until October 2026 a stranger who reached a freshly uploaded portal before
 * its owner could point the installer at a database of their own, make
 * themselves administrator, and own a portal running on the customer's
 * address. This file drives the REAL installer (`web/_install/index.php`),
 * page by page, in a scratch copy of `web/` behind PHP's built-in web server
 * and against a throwaway database, and looks at what it answers and at what
 * it leaves on the disk and in the database.
 *
 * WHAT IT CHECKS
 * --------------
 *   S. Without a server: the "refuse everything" files are one file; the one
 *      comparison of codes is hash_equals() and is not fooled by text that
 *      looks like numbers; what a code is made of, and that it is picked by
 *      PHP's secure source; the words for the channel, also when a
 *      `.channel` file holds a word nobody knows; that a choice made for one
 *      database is not held for another (a table of cases); which rules
 *      files are written again and which are left alone; that a read-only
 *      code file is still removed; and 200 wrong codes sent at the same
 *      moment from several processes, with others reading the file all the
 *      while, counted as exactly 200.
 *   A. A stranger at a fresh upload, with no code: every step's address by
 *      GET, and every form by POST with hand-made fields (lists where text
 *      is expected, a real database login, a valid form token and none), is
 *      answered with the first page; nothing is created; nothing about the
 *      server is said that the first page does not say to anybody. Opened
 *      directly (a website pointed at the wrong folder) the installer
 *      refuses and writes nothing, and `install-code.php` prints nothing.
 *      Reached through the web folder's own name on that misdirected
 *      website (`/httpdocs/`, in capitals, encoded, by GET and by POST) it
 *      refuses too, names the folder, and writes nothing.
 *      Where it cannot write its code file it says what to do. A warning
 *      planted inside it (in the scratch copy) is not printed into the page.
 *   C. The code: a wrong one is refused in the same words however close it
 *      was; tries are counted in the file, across sessions; the tenth writes
 *      a new code and ends every session let in by the old one; a form
 *      without this session's token is not acted on, the code form itself
 *      included (the RIGHT code without the token lets nobody in).
 *   I. One whole installation with NO `.channel` file, in a web folder of
 *      another name: every step's refusals in words (a bad address: a path,
 *      another scheme, a line break); the finish asks for the code again and
 *      writes nothing without it; a finish that stops at its last write (the
 *      database login cannot be written) says so, and the address corrected
 *      afterwards replaces the one that finish had already stored; a code
 *      file the owner made read-only is still removed; and then what a
 *      finished installation
 *      holds: one `site.url` row with the address typed, the support
 *      address, `_uploads` and `_backups`, a full key file, a "refuse
 *      everything" file in each private folder, no code file. The last page
 *      says the channel was ASSUMED and what to put in which file. A second
 *      visit is refused.
 *   U. The upgrade page of that installation: it does not open for an
 *      administrator of one organisation who is not a global one, and
 *      writes nothing for them; the channel and how it was
 *      decided; a deleted "refuse everything" file is put back, and
 *      somebody's own is left alone; and its three redirects and its
 *      breadcrumb keep an organisation's first part of the address when
 *      that way of telling organisations apart is switched on IN THE
 *      SCRATCH COPY.
 *   L. A second installation WITH a `.channel` file: the pages say the word
 *      and who said it; a session lost after the tables are built, and
 *      again after the administrator is made, just asks for the code again;
 *      a database that also holds another program's tables is refused;
 *      "wipe it and start again" wipes once and only once, removes only
 *      tables named `tbl…` and leaves `_backups` alone; a key file and a
 *      portal address that were already there are kept, also across a
 *      finish that stopped part way; a rules file holding only a comment is
 *      written again, and one of somebody's own that does not refuse is
 *      left alone and SAID to be that on the last page; and a database that
 *      holds a FINISHED portal still demands a signed-in global
 *      administrator, and says so. Last, on all ten pages: one top-level
 *      heading, and every link and form leads somewhere real.
 *   W. (inside part L, while its database is built and not yet finished)
 *      A choice belongs to ONE database. The check's own scenario of 13
 *      October 2026: "wipe" confirmed for database ONE, Back, database TWO
 *      typed, the build step opened by its address, "Build the tables"
 *      pressed. And its variations: the build page for ONE still on the
 *      screen (the browser's Back button); the choice page skipped by
 *      somebody who chose nothing; the same database typed again; two tabs,
 *      both ways round; and the same database name on another server. Each
 *      must leave database TWO exactly as it was: the same tables, the same
 *      number of rows in every one, the same checksums, and its uploaded
 *      file. Then "carry on" really builds and removes nothing.
 *
 * SAFETY
 * ------
 * - It needs a database server it may create databases on, so run it only
 *   against a THROWAWAY server. The databases it uses are its own: it makes
 *   three, named `selftest_inst_<random>_a`, `_b` and `_c`, refuses to start
 *   if any exists, and drops exactly those three at the end. When a SECOND
 *   throwaway server is named (SELFTEST_DB_PORT_2) it makes one more there,
 *   with the same name as `_c`, and drops it there.
 * - The repository's files are only read. Everything is done in a NEW
 *   scratch folder under `SELFTEST_WEB_DIR` (or the system's temporary
 *   folder). It refuses if that folder contains the repository or is inside
 *   it, and deletes its child at the end only if it still holds this run's
 *   marker. `web/_auth_keys` and a developer's own `web/.channel` are never
 *   read or copied.
 * - The two servers are started with their commands as lists (no shell), and
 *   stopped by the process numbers they were given, never by a name. It
 *   refuses to start one if anything already answers on its port. Every
 *   answer must carry this run's header.
 * - Nothing leaves the machine: no e-mail is sent and no outside address is
 *   asked. The made-up addresses it types end in `.invalid`.
 * - The clean-up also runs from a shutdown function. WHAT IT CANNOT SURVIVE:
 *   being killed outright, which leaves the scratch folder and the two
 *   databases behind (their names are printed at the start).
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - Apache. The router only imitates the web folder's `.htaccess`, so this
 *   does not show that a "refuse everything" file is obeyed. The second
 *   server here obeys none, which is how it shows what the code file does
 *   when nothing protects it.
 * - A real hosting panel, a file manager that hides or drops files, or a
 *   host that ends a request after thirty seconds while the tables are being
 *   built.
 * - A browser: how a page looks, whether it fits a phone, what a script on
 *   it does. One top-level heading, and where each link and form leads, are
 *   read from the page's text.
 * - That the time an answer takes says nothing about a guess. The comparison
 *   is held to hash_equals() by reading the code; no clock is used.
 * - A disk with no file locks: the count of wrong tries is not exercised
 *   there. Wrong codes sent at the same moment ARE exercised, but from
 *   several processes on this machine and not through a web server: the two
 *   servers here answer one request at a time.
 * - The finish failing part way in any way but one: only "the database login
 *   cannot be written" is exercised. A disk that fills, and a folder that
 *   cannot be made, are not.
 * - A code file the hosting will not let go of at all: what the last page
 *   then says is not drawn here (the function's answer is checked, the page
 *   is not).
 * - The same database name on ANOTHER SERVER, unless a second server is
 *   named with SELFTEST_DB_PORT_2. Without one that single check is not run,
 *   and the last line says so. That a choice is not held when only the
 *   server, the port, the name or the user differs is always checked,
 *   without a server (S10).
 * - Session fixing, and a form posted from another website in a real
 *   browser: the token rule is checked, the browser's own cookie rules are
 *   not.
 * - The staff gate of a test copy beyond one sign-in, several organisations
 *   beyond the upgrade page's four addresses, and MariaDB.
 *
 * Usage:  SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… SELFTEST_WEB_DIR=/a/quiet/folder \
 *             php tools/installer-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_DB_PORT_2 (a SECOND throwaway server, reached with
 *         the same user and password; SELFTEST_DB_HOST_2 if it is not on the
 *         same host), SELFTEST_WEB_PORT (default 9750; the next port up is used
 *         as well), SELFTEST_PAGE_SECONDS (how long one page may take;
 *         default 300, because one page builds every table),
 *         SELFTEST_KEEP_PAGES (a folder to save each of the installer's ten
 *         pages into, as answered, for somebody to open in a browser).
 *         Needs PHP's curl extension and `proc_open()`. Do not run it as the
 *         system's administrator account: three checks make a folder
 *         read-only, which does not stop that account.
 * Exit:   0 only when every check ran and passed; 1 otherwise, including
 *         every refusal to run. The one check that needs a second server is
 *         counted only when one was named; the last line says when it was
 *         not run, and whether the run reached its end.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/499
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/500
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * How many checks a full run makes with ONE database server. A different
 * number means a block was skipped or ran twice. With a second server named
 * (SELFTEST_DB_PORT_2) one more is made: see `$expectedChecks`.
 */
const EXPECTED_CHECKS = 130;

/** The made-up addresses this run types. `.invalid` never reaches anybody. */
const ADDRESS_TYPED = 'https://portal.installer-selftest.invalid';
const SUPPORT_TYPED = 'help@installer-selftest.invalid';
const ADMIN_EMAIL   = 'first.admin@installer-selftest.invalid';
const ORG_ADMIN_EMAIL = 'org.admin@installer-selftest.invalid';
/** An organisation made straight in the database, for the upgrade page's addresses. */
const ORG_KEY       = 'f5north';
const ORG_ID        = 918501;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$sep       = DIRECTORY_SEPARATOR;
$repo      = (string) realpath(dirname(__DIR__));
$webSrc    = $repo . $sep . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9750 : (int) $portRaw;
if ($port < 1024 || $port > 65534) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65534');
}
$ports       = ['main' => $port, 'wrong' => $port + 1];
$keepPages   = (string) getenv('SELFTEST_KEEP_PAGES');
if ($keepPages !== '' && is_dir($keepPages) === false) {
    refuse('SELFTEST_KEEP_PAGES must be a folder that exists');
}
$secondsRaw  = getenv('SELFTEST_PAGE_SECONDS');
$pageSeconds = ($secondsRaw === false || $secondsRaw === '') ? 300 : max(1, (int) $secondsRaw);

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$dbLogin = [
    'host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
try {
    $server = new mysqli($dbLogin['host'], $dbLogin['user'], $dbLogin['pass'], '', $dbLogin['port']);
} catch (mysqli_sql_exception $e) {
    refuse('the database server could not be reached: ' . $e->getMessage());
}
$server->set_charset('utf8mb4');
$run       = bin2hex(random_bytes(4));
$databases = ['a' => 'selftest_inst_' . $run . '_a', 'b' => 'selftest_inst_' . $run . '_b', 'c' => 'selftest_inst_' . $run . '_c'];

// A SECOND throwaway server, only for "the same database name on another
// server" (W09). Optional: most people who run this have one server. When it
// is not named, that one check is not run and the last line says so.
$otherLogin  = null;
$otherServer = null;
if (getenv('SELFTEST_DB_PORT_2') !== false && getenv('SELFTEST_DB_PORT_2') !== '') {
    $otherLogin = ['host' => getenv('SELFTEST_DB_HOST_2') !== false ? (string) getenv('SELFTEST_DB_HOST_2') : $dbLogin['host'], 'port' => (int) getenv('SELFTEST_DB_PORT_2')];
    if ($otherLogin['host'] === $dbLogin['host'] && $otherLogin['port'] === $dbLogin['port']) {
        refuse('SELFTEST_DB_PORT_2 names the same server as the first one');
    }
    try {
        $otherServer = new mysqli($otherLogin['host'], $dbLogin['user'], $dbLogin['pass'], '', $otherLogin['port']);
        $otherServer->set_charset('utf8mb4');
        $there = $otherServer->query("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $databases['c'] . "'")->num_rows;
    } catch (mysqli_sql_exception $e) {
        refuse('the second database server (SELFTEST_DB_PORT_2) could not be reached: ' . $e->getMessage());
    }
    if ($there > 0) {
        refuse("a database named {$databases['c']} already exists on the second server");
    }
}
$expectedChecks = EXPECTED_CHECKS + ($otherServer === null ? 0 : 1);

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

/**
 * One query on the server, with or without a database chosen. A query that
 * cannot be run answers with no rows: when the installer is broken, the
 * database or the table a check asks about may not exist, and the check must
 * then FAIL, not end the run.
 *
 * @return list<array<string,mixed>>
 */
function q(string $database, string $sql, string $types = '', array $params = []): array
{
    global $server;
    try {
        $server->select_db($database === '' ? 'information_schema' : $database);
        $stmt = $server->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (mysqli_sql_exception) {
        return [];
    }

    return $rows;
}

/** A count asked as "SELECT COUNT(*) AS n …"; -1 when it could not be asked. */
function n(string $database, string $sql, string $types = '', array $params = []): int
{
    $rows = q($database, $sql, $types, $params);

    return isset($rows[0]['n']) === true ? (int) $rows[0]['n'] : -1;
}

/** Remove a file if it is there. */
function gone(string $file): void
{
    if (is_file($file) === true) {
        unlink($file);
    }
}

function databaseExists(string $name): bool
{
    return q('', 'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', 's', [$name]) !== [];
}

/** The rows of one installation-wide setting, as "value | value". */
function setting(string $database, string $key): string
{
    return implode(' | ', array_map(static fn (array $row): string => (string) $row['settingValue'], q($database, 'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL ORDER BY settingID', 's', [$key])));
}

/** A PHP file's code with its comments taken out. */
function codeOnly(string $file): string
{
    $code = '';
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) === true && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
            continue;
        }
        $code .= is_array($token) === true ? $token[1] : $token;
    }

    return $code;
}

foreach ($databases as $name) {
    if (databaseExists($name) === true) {
        refuse("a database named {$name} already exists");
    }
}

// =============================================================================
// S. WITHOUT A SERVER
// =============================================================================
head('S. Without a server: the files, the comparison, the code, the words');

require $webSrc . $sep . '_install' . $sep . 'owner_code.php';

$refuseTexts = [];
foreach (['_apps', '_core', '_lang', '_sql', '_vendor', '_functions', '_includes'] as $folder) {
    $file                 = $webSrc . $sep . $folder . $sep . '.htaccess';
    $refuseTexts[$folder] = is_file($file) === true ? (string) file_get_contents($file) : '(missing from ' . $folder . ')';
}
$shippedRefuse = $refuseTexts['_core'];
ok('S01 each of _apps, _core, _lang, _sql, _vendor, _functions and _includes holds a "refuse everything" file, and the seven are one file byte for byte',
    count(array_unique($refuseTexts)) === 1 && str_contains($shippedRefuse, '(missing') === false, json_encode(array_map('md5', $refuseTexts)));
ok('S02 its ONLY rule is "Require all denied"', installRulesOf($shippedRefuse) === ['Require all denied'], json_encode(installRulesOf($shippedRefuse)));

// The one comparison. Pairs PHP's == calls equal because both look like numbers, and pairs that differ by one letter.
$fooled = [];
foreach ([['0e1234', '0e5678'], ['1e3', '1000'], ['100', '1e2'], ['0', '0.0'], ['2E99999999999999', '3E99999999999999'], ['ABCDEFGHJKMNPQRS', 'ABCDEFGHJKMNPQRT'],
    ['ABCDEFGHJKMNPQRS', 'abcdefghjkmnpqrs'], ['ABCDEFGHJKMNPQRS', 'ABCDEFGHJKMNPQR'], ['ABCDEFGHJKMNPQRS', '']] as [$inFile, $typed]) {
    if (installCodesMatch($inFile, $typed) !== false) {
        $fooled[] = $inFile . ' = ' . $typed;
    }
}
ok('S03 the comparison is not fooled: text that only LOOKS like the same number ("0e1234" and "0e5678"), one letter out, small letters, one letter short and nothing at all are each refused',
    $fooled === [] && installCodesMatch('ABCDEFGHJKMNPQRS', 'ABCDEFGHJKMNPQRS') === true, 'accepted: ' . implode('; ', $fooled));
$ownerCode = codeOnly($webSrc . $sep . '_install' . $sep . 'owner_code.php');
ok('S04 that comparison is hash_equals() and nothing else, and it is the only place the file\'s code is compared: the judging function calls it once and holds no comparison of its own on the code',
    preg_match('/function installCodesMatch\(string \$inFile, string \$typed\): bool\s*\{\s*return hash_equals\(\$inFile, \$typed\);\s*\}/', $ownerCode) === 1
    && substr_count($ownerCode, 'hash_equals(') === 1 && substr_count($ownerCode, 'installCodesMatch($now[\'code\'], $tidy)') === 1
    && preg_match('/\$now\[\'code\'\]\s*(===|==|!==|!=|<=>)|(===|==|!==|!=|<=>)\s*\$now\[\'code\'\]|strcmp|strcasecmp|in_array/', $ownerCode) !== 1);

$codes  = [];
$odd    = [];
for ($i = 0; $i < 300; $i++) {
    $code    = installCodeNew();
    $codes[] = $code;
    if (preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ]{16}$/', $code) !== 1) {
        $odd[] = $code;
    }
}
ok('S05 a code is sixteen letters, never I, L or O and never a digit, and 300 of them are 300 different codes', $odd === [] && count(array_unique($codes)) === 300, implode(', ', array_slice($odd, 0, 3)));
ok('S06 what somebody types is tidied before it is compared: small letters, dashes, spaces and a line end all pass; only the first 100 characters are looked at',
    installCodeTidy(" abcd-efgh jkmn\tpqrs\r\n") === 'ABCDEFGHJKMNPQRS' && installCodeTidy(str_repeat('-', 100) . 'ABCD') === '' && installCodeAsShown('ABCDEFGHJKMNPQRS') === 'ABCD-EFGH-JKMN-PQRS');
$fileText = installCodeFileText('ABCDEFGHJKMNPQRS', 7);
ok('S07 the code file\'s text is read back as the same code and count, shows the code to a person in groups of four, and a file that is not a code file is "no code"',
    installCodeParse($fileText) === ['code' => 'ABCDEFGHJKMNPQRS', 'wrongTries' => 7] && str_contains($fileText, 'YOUR CODE IS:   ABCD-EFGH-JKMN-PQRS') === true
    && installCodeParse("<?php\nreturn ['code' => 'ABCDEFGHJKMNPQR1', 'wrongTries' => 0];\n") === null && installCodeParse('') === null
    && installCodeParse("<?php\nreturn ['code' => '2E99999999999999', 'wrongTries' => 0];\n") === null);

// The words for the channel, from the answers Channel itself gives.
$words = [
    'assumed'     => installChannelWords('prod', 'assumed', 'httpdocs'),
    'file'        => installChannelWords('beta', 'file', 'httpdocs'),
    'folder'      => installChannelWords('dev', 'folder', 'public_html_dev'),
    'environment' => installChannelWords('prod', 'environment', 'httpdocs'),
];
ok('S08 a channel that was only ASSUMED is said to be assumed, with the file to create and the word to put in it; a channel that was stated is never called assumed',
    $words['assumed']['assumed'] === true && str_contains($words['assumed']['how'], 'ASSUMED') === true
    && str_contains(implode(' ', $words['assumed']['advice']), 'file named .channel') === true && str_contains(implode(' ', $words['assumed']['advice']), 'that word is: live') === true
    && $words['file']['assumed'] === false && $words['folder']['assumed'] === false && $words['environment']['assumed'] === false
    && str_contains(json_encode([$words['file'], $words['folder'], $words['environment']]), 'ASSUMED') === false, json_encode($words['assumed']));
ok('S09 a stated channel says the word and who said it: the file, the web folder\'s name, or the server\'s setting',
    $words['file']['copy'] === 'a beta copy (a test copy, not the live portal)' && $words['file']['how'] === 'The file .channel, beside the _core folder, says so.'
    && str_contains($words['folder']['how'], '(public_html_dev)') === true && str_contains($words['folder']['copy'], 'a dev copy') === true
    && $words['environment']['copy'] === 'the live portal' && str_contains($words['environment']['how'], 'PORTAL_ENV') === true, json_encode($words));

// A choice ("carry on" or "wipe") belongs to ONE database. The same functions the installer itself asks, handed a table of cases.
require $webSrc . $sep . '_install' . $sep . 'db_state.php';
$_SESSION = [];
$dbOne    = ['host' => 'db-one.installer-selftest.invalid', 'user' => 'portal', 'pass' => 'a password', 'name' => 'portal', 'port' => 3306];
$held     = [];
$_SESSION['install_db'] = $dbOne;
installChoiceKeep('drop');
$held['the database it was made for']     = installChoiceHeld();
$_SESSION['install_db']                   = ['pass' => 'a corrected password'] + $dbOne;
$held['the same one, another password']   = installChoiceHeld();
foreach (['host' => 'db-two.installer-selftest.invalid', 'port' => 3307, 'name' => 'portal_two', 'user' => 'somebody_else'] as $part => $other) {
    $_SESSION['install_db']             = [$part => $other] + $dbOne;
    $held['another ' . $part]           = installChoiceHeld();
}
unset($_SESSION['install_db']);
$held['no database held']                 = installChoiceHeld();
$_SESSION['install_db']                   = $dbOne;
$_SESSION['install_action']               = 'drop';
$held['a bare word, as an older session kept it'] = installChoiceHeld();
installChoiceKeep('not one of the choices');
$held['a word that is not a choice']      = installChoiceHeld();
installChoiceKeep('continue');
$held['carry on, kept']                   = installChoiceHeld();
installChoiceForget();
$held['forgotten']                        = installChoiceHeld();
$runTogether = installDbIdentity(['host' => 'a', 'port' => 1, 'name' => 'b","c', 'user' => 'u']) === installDbIdentity(['host' => 'a","b', 'port' => 1, 'name' => 'c', 'user' => 'u']);
$_SESSION = [];
ok('S10 a choice is held for the ONE database it was made for: not when the server, the port, the name or the user differs, not when no database is held, and not as a bare word; a corrected password is still the same database',
    $held === ['the database it was made for' => 'drop', 'the same one, another password' => 'drop', 'another host' => '', 'another port' => '', 'another name' => '', 'another user' => '',
        'no database held' => '', 'a bare word, as an older session kept it' => '', 'a word that is not a choice' => '', 'carry on, kept' => 'continue', 'forgotten' => '']
    && $runTogether === false && installDbIdentity(null) === '' && installDbIdentity(['host' => 'a']) === '', json_encode($held));

// Reading the installer's code: every reader of the choice goes through those functions, and there is one wipe.
$installerCode = codeOnly($webSrc . $sep . '_install' . $sep . 'index.php');
$wipeBlock     = preg_match('/\$wipeThisDatabase = \(\$buildChoice === \'drop\'\);\s*if \(\$wipeThisDatabase === true\) \{(.*?)installChoiceKeep\(\'fresh\'\);/s', $installerCode, $m) === 1 ? $m[1] : '';
ok('S11 the installer never reads the session\'s choice for itself (only through the functions S10 has just tried), wipes in ONE place and only on a wipe choice held for the database in hand, and empties uploaded files only inside that wipe',
    str_contains($installerCode, "\$_SESSION['install_action']") === false && substr_count($installerCode, 'INSTALL_CHOICE_KEY') === 1 && substr_count($installerCode, '$buildChoice = installChoiceHeld();') === 1
    && substr_count($installerCode, 'DROP TABLE') === 1 && str_contains($wipeBlock, 'DROP TABLE') === true && str_contains($wipeBlock, 'installClearDirectory($uploadsDir)') === true
    && substr_count($installerCode, 'installClearDirectory(') === 3, 'the wipe block was ' . ($wipeBlock === '' ? 'not found' : 'found') . '; installClearDirectory( appears ' . substr_count($installerCode, 'installClearDirectory(') . ' times');

// The code's source. PHP's ordinary random numbers can be set going from a known start, and then give the same answers
// again; the secure source cannot. So: start the ordinary one the same way twice, and ask for a code each time.
mt_srand(918501);
$seededOnce = installCodeNew();
mt_srand(918501);
$seededTwice = installCodeNew();
mt_srand();
ok('S12 a code is picked by PHP\'s secure random source: starting the ordinary one the same way twice does not give the same code twice, and the function asks random_int() and no other source',
    $seededOnce !== $seededTwice && preg_match('/function installCodeNew\(\): string\s*\{[^}]*\[random_int\(0, \$last\)\][^}]*\}/s', $ownerCode) === 1
    && preg_match('/\b(mt_rand|rand|mt_srand|srand|shuffle|str_shuffle|array_rand|lcg_value|uniqid|random_bytes|openssl_random_pseudo_bytes)\s*\(/', $ownerCode) !== 1, $seededOnce . ' / ' . $seededTwice);

// A .channel file that is there but holds a word nobody knows.
$notUsed   = installChannelWords('prod', 'assumed', 'httpdocs', true);
$notUsedBy = installChannelWords('dev', 'folder', 'public_html_dev', true);
$known     = preg_match('/The words the portal knows are (.+?)\./', $notUsed['how'], $m) === 1 ? (preg_split('/, | and /', $m[1]) ?: []) : [];
$unknownTo = array_values(array_filter($known, static fn (string $word): bool => \Portal\Core\Channel::decideFrom(null, $word . "\n", null)['source'] !== 'file'));
ok('S13 a .channel file whose first line is not a word the portal knows is said to be exactly that, with the words it does know (' . implode(', ', $known) . ': the portal really understands each); the copy is still called ASSUMED, and the advice is to open the file, not to create one',
    $notUsed['assumed'] === true && str_contains($notUsed['how'], 'That is ASSUMED: there is a file named .channel beside the _core folder, but it was not used: its first line is not a word the portal knows') === true
    && count($known) === 4 && $unknownTo === [] && \Portal\Core\Channel::decideFrom(null, "production\n", null)['source'] === 'assumed'
    && str_contains(implode(' ', $notUsed['advice']), 'open the file .channel') === true && str_contains(implode(' ', $notUsed['advice']), 'create a plain text file') === false
    && $notUsedBy['assumed'] === false && str_contains($notUsedBy['how'], 'worked out from the name of the web folder (public_html_dev)') === true && str_contains($notUsedBy['how'], 'but it was not used') === true
    && str_contains(installChannelWords('prod', 'assumed', 'httpdocs')['how'], 'That is ASSUMED: nothing on this server says which copy this is.') === true, json_encode([$notUsed, $known, $unknownTo]));

// =============================================================================
// 🧰 The scratch folder, the two copies, the two servers, one request
// =============================================================================
$state = ['procs' => ['main' => null, 'wrong' => null], 'child' => '', 'token' => bin2hex(random_bytes(16)), 'cleaned' => false, 'made' => [], 'madeOther' => [], 'logRead' => 0];
$child = $parent . $sep . 'installer-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.installer-selftest-owner', $state['token']); // the marker FIRST
register_shutdown_function('cleanup');
mkdir($child . $sep . 'sessions', 0700);

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Stop one of the servers this run started, by the process proc_open() gave — never by a name. */
function stopServer(string $which): void
{
    global $state, $ports;
    if (is_resource($state['procs'][$which]) === false) {
        return;
    }
    proc_terminate($state['procs'][$which]);
    $until = microtime(true) + 5;
    while (proc_get_status($state['procs'][$which])['running'] === true && microtime(true) < $until) {
        usleep(50000);
    }
    if (proc_get_status($state['procs'][$which])['running'] === true) {
        proc_terminate($state['procs'][$which], 9);
    }
    proc_close($state['procs'][$which]);
    $state['procs'][$which] = null;
    $until                  = microtime(true) + 5;
    while (portAnswers($ports[$which]) === true && microtime(true) < $until) {
        usleep(50000);
    }
}

/** Put back everything this run made, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state, $server, $otherServer;
    if ($state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    stopServer('main');
    stopServer('wrong');
    // Only the databases this run named, and only by that exact name.
    foreach ($state['made'] as $name) {
        if (preg_match('/^selftest_inst_[0-9a-f]{8}_[abc]$/', $name) === 1) {
            try {
                $server->query('DROP DATABASE IF EXISTS `' . $name . '`');
            } catch (Throwable $e) {
                echo 'FAIL — the clean-up could not drop ' . $name . ': ' . $e->getMessage() . "\n";
            }
        }
    }
    // And the one this run made on the second server, by its exact name.
    foreach ($state['madeOther'] as $name) {
        if ($otherServer !== null && preg_match('/^selftest_inst_[0-9a-f]{8}_c$/', $name) === 1) {
            try {
                $otherServer->query('DROP DATABASE IF EXISTS `' . $name . '`');
            } catch (Throwable $e) {
                echo 'FAIL — the clean-up could not drop ' . $name . ' on the second server: ' . $e->getMessage() . "\n";
            }
        }
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false && @file_get_contents($child . DIRECTORY_SEPARATOR . '.installer-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            if ($info->isDir() === true && $info->isLink() === false) {
                @chmod((string) $path, 0700);
                @rmdir((string) $path);
            } else {
                @unlink((string) $path);
            }
        }
        @rmdir($child);
    }
}

/** A scratch copy of web/, with the web folder renamed. Returns the folder that holds _core. */
function makeCopy(string $name, string $webFolder): string
{
    global $child, $sep, $webSrc;
    $root = $child . $sep . $name . $sep . 'web';
    mkdir($root, 0700, true);
    $copied = 0;
    $tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($tree as $path => $info) {
        $rel = substr((string) $path, strlen($webSrc) + 1);
        // Never the secrets or the uploads, and never a developer's own channel file: the copy must start with none.
        if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries', '.channel'], true) === true) {
            continue;
        }
        if ($info->isLink() === true) {
            refuse('web/ holds a link (' . $rel . '); refusing to copy it');
        }
        if ($info->isDir() === true) {
            mkdir($root . $sep . $rel, 0700);
        } else {
            copy((string) $path, $root . $sep . $rel);
            $copied++;
        }
    }
    if ($copied < 100) {
        refuse("the copy of web/ holds only {$copied} files");
    }
    rename($root . $sep . 'public_html', $root . $sep . $webFolder);

    return $root;
}

/**
 * Start one of the two servers on a copy, stopping the one before it.
 *
 * `main` is the website as it should be: the web folder, behind a router
 * that imitates its `.htaccess`. `wrong` is a website pointed at the WRONG
 * folder, the one that holds `_core`, on a web server that obeys no
 * `.htaccess` at all: PHP's own server runs whatever PHP file is asked for.
 *
 * `display_errors=1` is given ON PURPOSE: PHP would print every warning
 * unless the installer itself switches that off.
 */
function startServer(string $which, string $root, string $webFolder = ''): void
{
    global $state, $child, $sep, $ports, $pageSeconds;
    stopServer($which);
    if (portAnswers($ports[$which]) === true) {
        refuse("something still answers on port {$ports[$which]}; this test never drives a server it did not start");
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS'], $env['PORTAL_ENV']);
    $state['procs'][$which] = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
            '-d', 'display_errors=1', '-d', 'html_errors=0', '-d', 'opcache.enable=0', '-d', 'max_execution_time=' . $pageSeconds,
            '-S', '127.0.0.1:' . $ports[$which], '-t', $which === 'main' ? $root . $sep . $webFolder : $root, $child . $sep . 'router-' . $which . '.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']],
        $pipes,
        $child,
        $env
    );
    if (is_resource($state['procs'][$which]) === false) {
        refuse('the web server could not be started');
    }
    $deadline = microtime(true) + 10;
    while (portAnswers($ports[$which]) === false && microtime(true) < $deadline) {
        usleep(100000);
    }
    if (portAnswers($ports[$which]) === false) {
        refuse('the web server never answered on port ' . $ports[$which]);
    }
}

/**
 * One real HTTP request to a server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and
 * is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 * @param list<string>             $headers Extra request headers, each as "Name: value".
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null, array $headers = [], string $which = 'main'): array
{
    global $ports, $state, $pageSeconds;
    $handle = curl_init('http://127.0.0.1:' . $ports[$which] . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $pageSeconds + 30,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => array_merge(['Expect:', 'Accept-Language: en'], $headers),
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
    if (preg_match('/^X-Installer-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

/** An answer in a few words, for a failure message. */
function said(array $answer): string
{
    return 'HTTP ' . $answer['status'] . ($answer['location'] === '' ? '' : ' to ' . $answer['location']) . ', page "' . page($answer) . '"' . (message($answer) === '' ? '' : ', said: ' . message($answer));
}

/** Which of the installer's pages an answer is ('' when it is not one of them). */
function page(array $answer): string
{
    return preg_match('/id="install-page" data-install-page="([a-z]+)"/', $answer['body'], $m) === 1 ? $m[1] : '';
}

/** The installer's message on a page, as text ('' when there is none). */
function message(array $answer): string
{
    return preg_match('/<div class="alert alert-danger install-card" role="alert" id="install-error">(.*?)<\/div>/s', $answer['body'], $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
}

/** A page's words, with the tags taken out and the spaces tidied. */
function words(array $answer): string
{
    $body = (string) preg_replace('#<(script|style)\b.*?</\1>#s', ' ', $answer['body']);

    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** The form token a page carries. */
function token(array $answer): string
{
    return preg_match('/name="install_token" value="([0-9a-f]{64})"/', $answer['body'], $m) === 1 ? $m[1] : '';
}

/** The mark a page's forms carry for the database the page was drawn for ('' when it has none). */
function mark(array $answer): string
{
    return preg_match('/name="install_for" value="([0-9a-f]{64})"/', $answer['body'], $m) === 1 ? $m[1] : '';
}

/** POST one of the installer's forms with the hidden fields of the page it sits on, fetched now, as a browser would. */
function post(string $jar, string $formPage, array $fields): array
{
    return postFrom($jar, req($jar, 'GET', $formPage), $fields);
}

/**
 * POST a form with the hidden fields of a page that was fetched EARLIER: a
 * tab left open, or a page the browser's Back button brought back. The
 * token, and the mark for the database the page was drawn for, are that
 * page's and not today's.
 */
function postFrom(string $jar, array $page, array $fields): array
{
    $fields['install_token'] = token($page);
    if (mark($page) !== '' && array_key_exists('install_for', $fields) === false) {
        $fields['install_for'] = mark($page);
    }

    return req($jar, 'POST', '/', $fields);
}

/**
 * A database exactly as it is: how many tables, how many rows in all, and
 * one mark made from every table's name, its number of rows and its
 * checksum (the database's own, over everything in the table). Two answers
 * are equal only when nothing in the database has changed.
 *
 * @return array{tables:int, rows:int, mark:string}
 */
function asItIs(string $database, ?mysqli $on = null): array
{
    global $server;
    $on    = $on ?? $server;
    $lines = [];
    $rows  = 0;
    try {
        $names = [];
        $found = $on->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $on->real_escape_string($database) . "' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        while (($row = $found->fetch_row()) !== null) {
            $names[] = (string) $row[0];
        }
        foreach ($names as $name) {
            $quoted = '`' . str_replace('`', '``', $database) . '`.`' . str_replace('`', '``', $name) . '`';
            $count  = (int) $on->query('SELECT COUNT(*) FROM ' . $quoted)->fetch_row()[0];
            $sum    = (string) ($on->query('CHECKSUM TABLE ' . $quoted)->fetch_row()[1] ?? 'none');
            $rows  += $count;
            $lines[] = $name . '=' . $count . '/' . $sum;
        }
    } catch (mysqli_sql_exception $e) {
        // A database that cannot be read is never "as it was".
        return ['tables' => -1, 'rows' => -1, 'mark' => 'could not be read: ' . $e->getMessage() . ' ' . bin2hex(random_bytes(4))];
    }

    return ['tables' => count($lines), 'rows' => $rows, 'mark' => md5(implode("\n", $lines))];
}

/** A database's state in a few words, for a check's name or its failure message. */
function inWords(array $asItIs): string
{
    return $asItIs['tables'] . ' tables, ' . $asItIs['rows'] . ' rows, mark ' . substr($asItIs['mark'], 0, 8);
}

/** A new, empty cookie jar: a new visitor. */
function jar(string $who): string
{
    global $child, $sep;

    return $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
}

/** What the code file of a copy holds now. @return array{code:string, wrongTries:int}|null */
function codeIn(string $root): ?array
{
    return installCodeRead($root . DIRECTORY_SEPARATOR . '_auth_keys');
}

/** The code in a copy's file now. When there is no code file, a made-up one: the checks that need the real code then fail by themselves. */
function codeOf(string $root): string
{
    return (string) (codeIn($root)['code'] ?? 'XXXXXXXXXXXXXXXX');
}

/** A code that is certainly not the one given: its first letter changed, the other fifteen kept. */
function oneLetterOut(string $code): string
{
    return (($code[0] ?? 'A') === 'A' ? 'B' : 'A') . substr($code, 1);
}

/** Type the right code as a new visitor would. Returns the answer to the form. */
function giveCode(string $jar, string $root): array
{
    return post($jar, '/', ['action' => 'owner_code', 'owner_code' => installCodeAsShown(codeOf($root))]);
}

/** The names in a folder, sorted, as "a, b, c" ('(no such folder)' when it is not there). */
function names(string $folder): string
{
    if (is_dir($folder) === false) {
        return '(no such folder)';
    }
    $names = array_values(array_diff(scandir($folder) ?: [], ['.', '..']));
    sort($names);

    return implode(', ', $names);
}

/** The first page with everything that differs between two visitors taken out: the token, and any message. */
function shape(array $answer): string
{
    $body = (string) preg_replace('/name="install_token" value="[0-9a-f]{64}"/', 'name="install_token" value="T"', $answer['body']);

    return (string) preg_replace('/\s*<div class="alert alert-danger install-card" role="alert" id="install-error">.*?<\/div>/s', '', $body);
}

/** PHP's own error text in a page, or a path of this machine: '' when there is none. */
function leak(array $answer): string
{
    global $child, $repo;
    foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Stack trace', 'Uncaught', ' on line ', $child, $repo, '/private/'] as $mark) {
        if (str_contains($answer['body'], $mark) === true) {
            return $mark;
        }
    }

    return '';
}

/**
 * What PHP's error log has gained, since this was last asked, that is a
 * warning, a notice or worse. Asked once more before part L begins, to step
 * over part U: that part makes the portal's own backup fail on purpose, and
 * the portal rightly logs it.
 */
function errorLog(): string
{
    global $child, $sep, $state;
    $lines = [];
    // No file at all means PHP has logged nothing at all.
    $log   = $child . $sep . 'php-errors.log';
    $all   = is_file($log) === true ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [];
    foreach (array_slice($all, $state['logRead']) as $line) {
        if (preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)|Uncaught/', $line) === 1) {
            $lines[] = $line;
        }
    }
    $state['logRead'] = count($all);

    return implode(' || ', array_slice($lines, 0, 5));
}

// The router of the website as it should be: imitates the web folder's .htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/installer-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Installer-Selftest: __TOKEN__');
$root = (string) realpath((string) $_SERVER['DOCUMENT_ROOT']);
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
// Required at the TOP LEVEL, never inside a function: the portal's start-up sets $mysqli and $SETTINGS as globals.
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
file_put_contents($child . $sep . 'router-main.php', str_replace('__TOKEN__', $state['token'], $router));
// The website pointed at the wrong folder: mark the answer, then let PHP's own server run whatever was asked for.
file_put_contents($child . $sep . 'router-wrong.php', "<?php\n// Written by tools/installer-selftest.php for ONE run. Test only.\nheader('X-Installer-Selftest: " . $state['token'] . "');\nreturn false;\n");

// =============================================================================
// S, continued: the same helpers, on folders of this run's own
// =============================================================================
head('S, continued. On scratch folders: which rules files are written again, a read-only code file, and 200 wrong codes at once');

// Each case is ONE private folder with one state of its rules file, in a root of its own that ships the real refuse file.
$cutShort = substr($shippedRefuse, 0, (int) strrpos($shippedRefuse, 'Require all denied'));
$cases    = [
    'no file at all'                          => [null, 'written again'],
    'an empty file'                           => ['', 'written again'],
    'only spaces and blank lines'             => [" \n\t\n\r\n", 'written again'],
    'a comment only'                          => ["# Refuse every request for anything in this folder.\n", 'written again'],
    'the shipped file, cut short before its rule' => [$cutShort, 'written again'],
    'somebody\'s own, which refuses'           => ["# Mine.\r\nRequire all denied\r\nOptions -Indexes\r\n", 'left alone, refuses'],
    'somebody\'s own, which does not refuse'   => ["Options -Indexes\n", 'left alone, does not refuse'],
];
$wrongCases = [];
$caseNumber = 0;
foreach ($cases as $what => [$text, $expected]) {
    $unitRoot = $child . $sep . 'unit-rules-' . (++$caseNumber);
    mkdir($unitRoot . $sep . '_core', 0700, true);
    mkdir($unitRoot . $sep . '_uploads', 0700);
    file_put_contents($unitRoot . $sep . '_core' . $sep . '.htaccess', $shippedRefuse);
    $rulesFile = $unitRoot . $sep . '_uploads' . $sep . '.htaccess';
    if ($text !== null) {
        file_put_contents($rulesFile, $text);
    }
    $one  = installProtectFolder($unitRoot, '_uploads');
    $now  = (string) @file_get_contents($rulesFile);
    $what2 = $one['problem'] !== '' ? 'a problem: ' . $one['problem']
        : ($one['written'] === true && $now === $shippedRefuse && $one['refuses'] === true ? 'written again'
            : ($one['written'] === false && $now === $text ? 'left alone, ' . ($one['refuses'] === true ? 'refuses' : 'does not refuse') : 'something else'));
    if ($what2 !== $expected) {
        $wrongCases[] = $what . ': ' . $what2;
    }
}
ok('S14 a rules file that holds NO rule is written again (no file, an empty one, blank lines, a comment only, the shipped file cut short before its rule); one that holds a rule is left exactly as it is, and whether it holds the refusal is reported truthfully',
    $wrongCases === [] && installRulesOf($cutShort) === [] && strlen($cutShort) > 50, implode(' | ', $wrongCases));
// All three folders at once: one missing, one with a comment only, one with somebody's own rules that do not refuse.
$unitRoot = $child . $sep . 'unit-rules-all';
mkdir($unitRoot . $sep . '_uploads', 0700, true);
mkdir($unitRoot . $sep . '_backups', 0700);
file_put_contents($unitRoot . $sep . '_uploads' . $sep . '.htaccess', "Options -Indexes\n");
file_put_contents($unitRoot . $sep . '_backups' . $sep . '.htaccess', "# A comment only.\n");
$all = installProtectPrivateFolders($unitRoot);
file_put_contents($unitRoot . $sep . '.channel', "production\n");
ok('S15 the three private folders together: the one that was missing is made, the two without a rule are given the file, and the one with somebody\'s own rules is named as NOT holding the refusal; and a .channel file is "there but not used" only when the folder\'s name or nothing decided',
    $all === ['made' => ['_auth_keys'], 'written' => ['_auth_keys', '_backups'], 'own' => ['_uploads'], 'problems' => []]
    && installChannelFileIgnored($unitRoot, 'assumed') === true && installChannelFileIgnored($unitRoot, 'folder') === true && installChannelFileIgnored($unitRoot, 'file') === false
    && installChannelFileIgnored($unitRoot, 'environment') === false && installChannelFileIgnored($unitRoot . $sep . '_uploads', 'assumed') === false, json_encode($all));

// The code file, made read-only by its owner (the code page suggests it), and then one that cannot be removed at all.
$unitRoot = $child . $sep . 'unit-code';
$unitAuth = $unitRoot . $sep . '_auth_keys';
mkdir($unitRoot, 0700);
$made     = installCodeEnsure($unitRoot, $unitAuth);
chmod(installCodePath($unitAuth), 0440);
$readOnlyJudged = [installCodeJudge($unitAuth, 'QQQQ-QQQQ-QQQQ-QQQQ'), (int) (installCodeRead($unitAuth)['wrongTries'] ?? -1), installCodeJudge($unitAuth, (string) (installCodeRead($unitAuth)['code'] ?? ''))];
$removed  = installCodeRemove($unitAuth);
$goneNow  = file_exists(installCodePath($unitAuth)) === false;
installCodeEnsure($unitRoot, $unitAuth);
chmod($unitAuth, 0500);
$stuck    = installCodeRemove($unitAuth);
chmod($unitAuth, 0700);
ok('S16 a code file its owner made read-only still judges (a wrong code is refused and not counted, the right one accepted) and is still REMOVED at the end; and where a code file cannot be removed at all, the answer is "not removed", never "done"',
    $made === '' && $readOnlyJudged === [INSTALL_CODE_WRONG, 0, INSTALL_CODE_RIGHT] && $removed === true && $goneNow === true && $stuck === false && is_file(installCodePath($unitAuth)) === true,
    json_encode([$made, $readOnlyJudged, $removed, $goneNow, $stuck]));

// 200 wrong codes at the same moment. Eight processes send 25 each, and each first does what every page view does
// (makes sure there is a code file); four more do nothing but that, over and over, from before the guessing starts
// until it is done. The eight wait for the same instant to start. The count is kept in the file, so: exactly twenty
// new codes, nothing left over, and nobody ever finds the file empty or half written.
$stormRoot = $child . $sep . 'storm';
$stormAuth = $stormRoot . $sep . '_auth_keys';
mkdir($stormRoot, 0700);
installCodeEnsure($stormRoot, $stormAuth);
$stormFirst = (string) (installCodeRead($stormAuth)['code'] ?? '');
file_put_contents($child . $sep . 'storm-worker.php', <<<'WORKER'
<?php
// Written by tools/installer-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
[, $helpers, $root, $role, $count, $startAt] = $argv;
require $helpers;
$auth    = $root . DIRECTORY_SEPARATOR . '_auth_keys';
$answers = [];
$codes   = [];
if ($role === 'guess') {
    // Every guesser waits for the same instant, so that the 200 codes arrive together.
    while (microtime(true) < (float) $startAt) {
        usleep(500);
    }
    for ($i = 0; $i < (int) $count; $i++) {
        if (installCodeEnsure($root, $auth) !== '') {
            $answers['could not make sure of a code file'] = ($answers['could not make sure of a code file'] ?? 0) + 1;
        }
        $answer           = installCodeJudge($auth, 'QQQQ-QQQQ-QQQQ-QQQQ');
        $answers[$answer] = ($answers[$answer] ?? 0) + 1;
    }
} else {
    $looks = 0;
    while (is_file($root . DIRECTORY_SEPARATOR . 'stop') === false && $looks < 5000000) {
        if (installCodeEnsure($root, $auth) !== '') {
            $answers['could not make sure of a code file'] = ($answers['could not make sure of a code file'] ?? 0) + 1;
        }
        $now = installCodeRead($auth);
        if ($now === null) {
            $answers['found no code file'] = ($answers['found no code file'] ?? 0) + 1;
        } else {
            $codes[$now['code']] = true;
        }
        $looks++;
        usleep(50);
    }
    $answers['looks'] = $looks;
}
echo json_encode(['answers' => $answers, 'codes' => array_keys($codes)]);
WORKER);
$stormProcs = [];
$stormStart = microtime(true) + 3.0;
foreach (array_merge(array_fill(0, 8, 'guess'), array_fill(0, 4, 'look')) as $i => $role) {
    $stormProcs[$i] = ['role' => $role, 'proc' => proc_open([PHP_BINARY, $child . $sep . 'storm-worker.php', $webSrc . $sep . '_install' . $sep . 'owner_code.php', $stormRoot, $role, '25', (string) $stormStart],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $child . $sep . 'server-output.log', 'a']], $stormPipes, $child), 'pipe' => null, 'out' => null];
    $stormProcs[$i]['pipe'] = $stormPipes[1] ?? null;
}
// The guessers end by themselves; then the lookers are told to stop. Nobody is left running: each is closed by the
// process proc_open() gave, and a looker gives up by itself after five million looks whatever happens here.
$stormTally = ['guessers' => 0, 'lookers' => 0, 'answers' => [], 'codes' => [], 'looks' => 0, 'broken' => 0];
foreach (['guess', 'look'] as $role) {
    if ($role === 'look') {
        touch($stormRoot . $sep . 'stop');
    }
    foreach ($stormProcs as $one) {
        if ($one['role'] !== $role) {
            continue;
        }
        $out = is_resource($one['proc']) === true && is_resource($one['pipe']) === true ? json_decode((string) stream_get_contents($one['pipe']), true) : null;
        if (is_resource($one['pipe']) === true) {
            fclose($one['pipe']);
        }
        if (is_resource($one['proc']) === true) {
            proc_close($one['proc']);
        }
        if (is_array($out) === false) {
            $stormTally['broken']++;

            continue;
        }
        $stormTally[$role === 'guess' ? 'guessers' : 'lookers']++;
        foreach ($out['answers'] as $answer => $times) {
            if ($answer === 'looks') {
                $stormTally['looks'] += (int) $times;
            } else {
                $stormTally['answers'][$answer] = ($stormTally['answers'][$answer] ?? 0) + (int) $times;
            }
        }
        foreach ($out['codes'] as $seen) {
            $stormTally['codes'][$seen] = true;
        }
    }
}
$stormLast = installCodeRead($stormAuth);
ksort($stormTally['answers']);
ok('S17 200 wrong codes sent at the same moment by eight processes, with four more reading the code file all the while (' . $stormTally['looks'] . ' looks): exactly 20 new codes and 180 plain refusals, nothing left on the count, '
    . 'and nobody ever found the file empty or half written; so no code got more than ten guesses',
    $stormTally['guessers'] === 8 && $stormTally['lookers'] === 4 && $stormTally['broken'] === 0 && $stormTally['answers'] === [INSTALL_CODE_REPLACED => 20, INSTALL_CODE_WRONG => 180]
    && $stormLast !== null && $stormLast['wrongTries'] === 0 && $stormLast['code'] !== $stormFirst && $stormFirst !== '' && $stormTally['looks'] > 100 && count($stormTally['codes']) <= 21,
    json_encode(['answers' => $stormTally['answers'], 'processes that answered' => $stormTally['guessers'] + $stormTally['lookers'], 'left in the file' => $stormLast['wrongTries'] ?? null, 'codes seen' => count($stormTally['codes'])]));

$rootA = makeCopy('a', 'httpdocs');
$rootB = makeCopy('b', 'httpdocs');
$authA = $rootA . $sep . '_auth_keys';
$authB = $rootB . $sep . '_auth_keys';
$version = (string) (require $webSrc . $sep . '_core' . $sep . 'version.php');
echo 'scratch folder ' . $child . "\ndatabases " . implode(' and ', $databases) . ' on ' . $dbLogin['host'] . ':' . $dbLogin['port'] . ' (' . (string) (q('', 'SELECT VERSION() AS v')[0]['v'] ?? '?') . '), PHP ' . PHP_VERSION . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

// What a real database login looks like in a form: a stranger who knows one must still get nowhere.
$realLogin  = static fn (string $database): array => ['db_host' => $dbLogin['host'], 'db_user' => $dbLogin['user'], 'db_pass' => $dbLogin['pass'], 'db_name' => $database, 'db_port' => (string) $dbLogin['port']];
$realLoginB = $realLogin($databases['b']);

// =============================================================================
// A. A STRANGER AT A FRESH UPLOAD
// =============================================================================
head('A. A stranger at a fresh upload, with no code');

// Opened directly, before anything else has touched copy B: the website is pointed at the wrong folder.
startServer('wrong', $rootB);
$direct = [req('', 'GET', '/_install/index.php', null, [], 'wrong'), req('', 'GET', '/_install/', null, [], 'wrong'), req('', 'GET', '/_install/index.php?step=2', null, [], 'wrong'),
    req('', 'POST', '/_install/index.php', $realLogin($databases['b']) + ['action' => 'db_config'], [], 'wrong')];
$refused = array_filter($direct, static fn (array $a): bool => $a['status'] === 403 && str_contains($a['body'], '<h1>The website is pointing at the wrong folder</h1>') === true
    && str_contains($a['body'], '<form') === false && substr_count($a['body'], '<h1') === 1 && leak($a) === '');
ok('A01 opened directly (a website pointed at the folder that holds _core) the installer refuses, by GET and by POST, with what to do and no form', count($refused) === 4, implode(' | ', array_map('said', $direct)));
ok('A02 and it writes nothing: no _auth_keys folder, no code, no database', is_dir($authB) === false && databaseExists($databases['b']) === false, names($rootB));

// The same misdirected website, reached through the web folder's own name. PHP's server runs /httpdocs/index.php, which
// hands the request to the installer exactly as it does on a website that is set up correctly: only the ADDRESS shows it.
$through = [
    'GET /httpdocs/'                         => req('', 'GET', '/httpdocs/', null, [], 'wrong'),
    'GET /httpdocs/index.php?step=1'         => req('', 'GET', '/httpdocs/index.php?step=1', null, [], 'wrong'),
    'GET /httpdocs/?step=2.5'                => req('', 'GET', '/httpdocs/?step=2.5', null, [], 'wrong'),
    'GET /%68ttpdocs/ (a letter encoded)'    => req('', 'GET', '/%68ttpdocs/', null, [], 'wrong'),
    'GET /httpdocs/index.php/more'           => req('', 'GET', '/httpdocs/index.php/more', null, [], 'wrong'),
    'GET /httpdocs/index.php?step=1#top'     => req('', 'GET', '/httpdocs/index.php?next=/a/b#top', null, [], 'wrong'),
    'POST /httpdocs/ the database step'      => req('', 'POST', '/httpdocs/', $realLoginB + ['action' => 'db_config'], [], 'wrong'),
    'POST /httpdocs/index.php a code'        => req('', 'POST', '/httpdocs/index.php', ['action' => 'owner_code', 'owner_code' => 'ABCD-EFGH-JKMN-PQRS'], [], 'wrong'),
];
$let = [];
foreach ($through as $what => $answer) {
    if ($answer['status'] !== 403 || str_contains($answer['body'], '<h1>The installer will not run at this address</h1>') === false || str_contains($answer['body'], '<code>httpdocs</code>') === false
        || str_contains($answer['body'], 'What to do, in your hosting panel:') === false || str_contains($answer['body'], '<form') === true || substr_count($answer['body'], '<h1') !== 1 || leak($answer) !== '') {
        $let[] = $what . ': ' . said($answer) . ' ' . leak($answer);
    }
}
// Three more spellings that some web servers and some disks read as that same folder: in capitals, with two slashes in
// front (which PHP's own parse_url() takes for a host name, not a folder), and with a full stop after the name (which
// Windows drops). Whether this server finds the folder that way or not, the installer's own pages must not come back.
$spellings = ['/HTTPDOCS/index.php' => null, '//httpdocs/index.php' => null, '/httpdocs./index.php' => null];
foreach (array_keys($spellings) as $spelling) {
    $spellings[$spelling] = req('', 'GET', $spelling, null, [], 'wrong');
}
$answeredAsInstaller = array_filter($spellings, static fn (array $a): bool => page($a) !== '' || str_contains($a['body'], 'name="owner_code"') === true || str_contains($a['body'], 'install_token') === true);
ok('A15 reached through the web folder\'s own name on that misdirected website (/httpdocs/, its index.php, a letter of the name encoded, with more after it; by GET and by POST) the installer refuses each time, names the folder '
    . 'to point the website at, says what to do in the hosting panel, and offers no form; in capitals, with two slashes in front, and with a full stop after the name, it does not answer as the installer either',
    $let === [] && $answeredAsInstaller === [], implode(' | ', array_slice($let, 0, 3)) . ' | other spellings: ' . implode(' | ', array_map(static fn (string $s, array $a): string => $s . ' ' . said($a), array_keys($spellings), $spellings)));
ok('A16 and that wrote nothing either: still no _auth_keys folder, no code, no database', is_dir($authB) === false && databaseExists($databases['b']) === false, names($rootB));
stopServer('wrong');

startServer('main', $rootA, 'httpdocs');
$first = req('', 'GET', '/');
ok('A03 the first page anybody gets is the code page: one top-level heading, a box for the code, and where the file is in a hosting panel\'s file manager',
    $first['status'] === 200 && page($first) === 'code' && substr_count($first['body'], '<h1') === 1 && str_contains($first['body'], 'name="owner_code"') === true
    && str_contains(words($first), 'Open the folder _auth_keys') === true && str_contains(words($first), 'Open the file install-code.php with View or Edit') === true
    && str_contains(words($first), 'file manager') === true, said($first));
$said = [];
foreach ([PHP_VERSION, $version, 'mysqli', 'sodium', 'PORTAL_ENV', '.channel', 'ASSUMED', 'httpdocs', 'localhost', (string) $dbLogin['port'], 'Database host'] as $detail) {
    if (str_contains($first['body'], $detail) === true) {
        $said[] = $detail;
    }
}
ok('A17 the code page says what to do when somebody else keeps the code changing (make the file read-only once it is read), and what a long pause costs (the database details are typed again; what was built is kept)',
    preg_match('/<p[^>]*id="install-code-readonly"[^>]*>(.*?)<\/p>/s', $first['body'], $m) === 1 && str_contains(strip_tags($m[1]), 'set the file install-code.php to read-only in the file manager after you have read it') === true
    && preg_match('/<p[^>]*id="install-code-again"[^>]*>(.*?)<\/p>/s', $first['body'], $m) === 1 && str_contains(strip_tags($m[1]), 'The database details have to be typed again too; what was already built in the database is kept.') === true);
ok('A04 it says nothing about the server: not the PHP version, not the portal\'s version, not a path, not the web folder\'s name, not which copy this is, and no PHP error text',
    $said === [] && leak($first) === '', 'said: ' . implode(', ', $said) . ' ' . leak($first));
$codeA = codeIn($rootA) ?? ['code' => 'XXXXXXXXXXXXXXXX', 'wrongTries' => -1];
ok('A05 that first visit wrote the code where only the owner can read it: _auth_keys holds the "refuse everything" file and the code file, and nothing else exists yet',
    names($authA) === '.htaccess, install-code.php' && $codeA['wrongTries'] === 0 && @file_get_contents($authA . $sep . '.htaccess') === $shippedRefuse
    && is_dir($rootA . $sep . '_uploads') === false && is_dir($rootA . $sep . '_backups') === false, names($authA));
ok('A06 the page does not show the code', str_contains($first['body'], $codeA['code']) === false && str_contains($first['body'], installCodeAsShown($codeA['code'])) === false);

// Every step's address, by GET: with no cookie at all, and with a session that has not given the code.
$wanderer  = jar('wanderer');
req($wanderer, 'GET', '/');
$addresses = ['/?step=1', '/?step=1.5', '/?step=2', '/?step=2.5', '/?step=3', '/?step=4', '/?step=4.5', '/?step=5', '/?step=6', '/?step=99', '/?step=abc', '/?step[]=2', '/?step=2&action=db_config'];
$other     = [];
foreach (['' => 'no cookie', $wanderer => 'a session without the code'] as $jarUsed => $who) {
    foreach ($addresses as $address) {
        $answer = req((string) $jarUsed, 'GET', $address);
        if ($answer['status'] !== 200 || shape($answer) !== shape($first) || leak($answer) !== '') {
            $other[] = $who . ' ' . $address . ': ' . said($answer) . ' ' . leak($answer);
        }
    }
}
ok('A07 every step\'s address by GET, ' . count($addresses) . ' of them, with no cookie and with a session that has not given the code: each answer is the first page, to the letter', $other === [], implode(' | ', array_slice($other, 0, 4)));

// Every form, by POST, with hand-made fields. With a valid token of the wanderer's own session, and with none.
$forms = [
    'the database step, with a real login' => $realLogin($databases['a']) + ['action' => 'db_config', 'step' => '2'],
    'the database step, lists for text'    => ['action' => 'db_config', 'db_host' => ['x'], 'db_user' => ['y'], 'db_pass' => ['z'], 'db_name' => ['n'], 'db_port' => ['1']],
    'wipe and start again'                 => ['action' => 'install_action', 'install_action_choice' => 'drop', 'drop_confirm' => '127.0.0.1:' . $ports['main'], 'step' => '2.5'],
    'carry on with what is there'          => ['action' => 'install_action', 'install_action_choice' => 'continue'],
    'the kind of organisation'             => ['action' => 'select_industry', 'industry' => 'church'],
    'build the tables'                     => ['action' => 'install_schema', 'step' => '3'],
    'the first administrator'              => ['action' => 'create_admin', 'admin_name' => 'A Stranger', 'admin_email' => 'stranger@installer-selftest.invalid', 'admin_pass' => 'Stranger-pass-1234!', 'admin_pass2' => 'Stranger-pass-1234!'],
    'web addresses'                        => ['action' => 'web_addresses', 'portal_address' => 'https://stranger.installer-selftest.invalid', 'support_email' => ''],
    'the finish, with a made-up code'      => ['action' => 'finalize', 'owner_code' => 'ABCD-EFGH-JKMN-PQRS', 'step' => '5'],
    'the finish, with a list for the code' => ['action' => 'finalize', 'owner_code' => ['A']],
    'an action nobody wrote'               => ['action' => 'make_me_administrator'],
    'a list for the action'                => ['action' => ['db_config']],
];
$other = [];
foreach ($forms as $what => $fields) {
    foreach (['with this session\'s form token' => true, 'with no token' => false] as $how => $withToken) {
        $answer = $withToken === true ? post($wanderer, '/', $fields) : req($wanderer, 'POST', '/', $fields);
        if ($answer['status'] !== 200 || shape($answer) !== shape($first) || leak($answer) !== '') {
            $other[] = $what . ' ' . $how . ': ' . said($answer) . ' ' . leak($answer);
        }
    }
}
ok('A08 every form by POST, ' . count($forms) . ' hand-made ones, each with a valid form token and with none: each answer is the first page, and nothing else', $other === [], implode(' | ', array_slice($other, 0, 4)));
ok('A09 and nothing was created: no database, no file beyond the two, no folder for uploads or backups', databaseExists($databases['a']) === false && names($authA) === '.htaccess, install-code.php'
    && is_dir($rootA . $sep . '_uploads') === false && is_dir($rootA . $sep . '_backups') === false, names($authA) . '; database there: ' . json_encode(databaseExists($databases['a'])));
ok('A10 a code posted to the finish by somebody who has not passed the first page is not even looked at: the count of wrong tries is still nought', (codeIn($rootA)['wrongTries'] ?? -1) === 0 && (codeIn($rootA)['code'] ?? '') === $codeA['code']);

// The code file, asked for over the web, where nothing protects it.
startServer('wrong', $rootA);
$fetched = req('', 'GET', '/_auth_keys/install-code.php', null, [], 'wrong');
ok('A11 install-code.php fetched over the web (a website pointed at the wrong folder, on a server that obeys no rules file) prints NOTHING, though the file holds the code',
    $fetched['status'] === 200 && $fetched['body'] === '' && str_contains((string) @file_get_contents($authA . $sep . 'install-code.php'), $codeA['code']) === true, 'HTTP ' . $fetched['status'] . ', ' . strlen($fetched['body']) . ' bytes');
$helpers = [req('', 'GET', '/_install/owner_code.php', null, [], 'wrong'), req('', 'GET', '/_install/install_common.php', null, [], 'wrong')];
ok('A12 the installer\'s two helper files print nothing either', $helpers[0]['status'] === 200 && $helpers[0]['body'] === '' && $helpers[1]['status'] === 200 && $helpers[1]['body'] === '');
stopServer('wrong');

// A server where the installer cannot write: the folder that holds _core is read-only and there is no _auth_keys yet.
chmod($rootB, 0500);
startServer('main', $rootB, 'httpdocs');
$stuck = req('', 'GET', '/');
chmod($rootB, 0700);
ok('A13 where the installer cannot write its code file it says so, says what to do in a file manager, offers "Try again" and no box for a code; and it names no path',
    page($stuck) === 'code' && str_contains($stuck['body'], 'id="install-code-problem"') === true && str_contains(words($stuck), 'the folder _auth_keys is not there and could not be made') === true
    && str_contains(words($stuck), 'What to do:') === true && str_contains($stuck['body'], 'name="owner_code"') === false && str_contains($stuck['body'], '>Try again</a>') === true
    && leak($stuck) === '' && is_dir($authB) === false, said($stuck));

// A warning raised INSIDE the installer, planted in this scratch copy only: reading a variable that does not exist. The
// server was started with "print every error", so only the installer itself can be keeping it off the page.
$indexB  = $rootB . $sep . '_install' . $sep . 'index.php';
$asGiven = (string) file_get_contents($indexB);
$planted = str_replace("require_once __DIR__ . DIRECTORY_SEPARATOR . 'owner_code.php';", "require_once __DIR__ . DIRECTORY_SEPARATOR . 'owner_code.php';\necho \$installerSelftestMarker918; // planted by tools/installer-selftest.php", $asGiven, $plantedTimes);
file_put_contents($indexB, $planted);
$warned = req('', 'GET', '/');
file_put_contents($indexB, $asGiven);
$logged = is_file($child . $sep . 'php-errors.log') === true ? (string) file_get_contents($child . $sep . 'php-errors.log') : '';
ok('A14 PHP\'s own error text is never printed into the installer\'s pages: a warning planted inside it is raised (the error log has it) and the page shows none of it',
    $plantedTimes === 1 && page($warned) === 'code' && str_contains($warned['body'], 'installerSelftestMarker918') === false && leak($warned) === ''
    && str_contains($logged, 'installerSelftestMarker918') === true, said($warned) . ' ' . leak($warned) . '; planted ' . $plantedTimes . '; in the log: ' . json_encode(str_contains($logged, 'installerSelftestMarker918')));
errorLog(); // step over the planted warning
startServer('main', $rootA, 'httpdocs');

// =============================================================================
// C. THE CODE
// =============================================================================
head('C. The code: wrong tries, the tenth, and who is let in');

$owner = jar('owner');
$in    = giveCode($owner, $rootA);
$after = req($owner, 'GET', '/?step=1');
ok('C01 the right code lets its session in: sent on to the checks, which that session can now see', $in['status'] === 302 && $in['location'] === '?step=1' && page($after) === 'checks', said($in) . ' then ' . said($after));

$guesser  = jar('guesser');
$far      = post($guesser, '/', ['action' => 'owner_code', 'owner_code' => 'ZZZZ-ZZZZ-ZZZZ-ZZZZ']);
$close    = post($guesser, '/', ['action' => 'owner_code', 'owner_code' => installCodeAsShown(oneLetterOut($codeA['code']))]);
ok('C02 a wrong code is refused, and the answer to a code with fifteen letters right is the answer to one with none right, to the letter',
    page($far) === 'code' && str_starts_with(message($far), 'That is not the code in the file.') === true && shape($far) === shape($close) && message($far) === message($close), said($far) . ' | ' . said($close));
ok('C03 the refusal says nothing of how close the guess was, and nothing of the server', preg_match('/\d+ (of|out of|letters? (right|correct|wrong))|close|nearly|almost/i', message($close)) !== 1 && leak($close) === '', message($close));
$blank = post($guesser, '/', ['action' => 'owner_code', 'owner_code' => ' - - ']);
ok('C04 the tries are counted in the FILE: two wrong codes are two, and an empty box is not a try', (codeIn($rootA)['wrongTries'] ?? -1) === 2 && page($blank) === 'code' && str_contains(message($blank), 'Type the code') === true,
    'count ' . json_encode(codeIn($rootA)['wrongTries'] ?? null) . '; ' . said($blank));
// Each from a NEW session: throwing the cookie away must not start the count again.
for ($i = 3; $i <= 9; $i++) {
    post(jar('guess' . $i), '/', ['action' => 'owner_code', 'owner_code' => installCodeAsShown(oneLetterOut($codeA['code']))]);
}
$nine = codeIn($rootA);
ok('C05 seven more, each from a new session with no cookie of the last: the count is nine and the code is still the same one', ($nine['wrongTries'] ?? -1) === 9 && ($nine['code'] ?? '') === $codeA['code'], json_encode($nine));
ok('C06 the owner\'s session is still let in while the code stands', page(req($owner, 'GET', '/?step=1')) === 'checks');
$tenth = post(jar('guess10'), '/', ['action' => 'owner_code', 'owner_code' => installCodeAsShown(oneLetterOut($codeA['code']))]);
$new   = codeIn($rootA);
ok('C07 the tenth wrong try writes a NEW code into the file, and says so', $new !== null && $new['code'] !== $codeA['code'] && $new['wrongTries'] === 0 && page($tenth) === 'code'
    && str_contains(message($tenth), 'has written a NEW code into the same file') === true, json_encode($new) . ' ' . said($tenth));
$old = post(jar('late'), '/', ['action' => 'owner_code', 'owner_code' => installCodeAsShown($codeA['code'])]);
ok('C08 the old code, which was right a moment ago, is now refused', page($old) === 'code' && str_starts_with(message($old), 'That is not the code in the file.') === true, said($old));
$asked = req($owner, 'GET', '/?step=2');
ok('C09 and the session that was let in by the old code is asked again, and told why', page($asked) === 'code' && str_contains($asked['body'], 'id="install-code-changed"') === true
    && str_contains(words($asked), 'The code has changed since you typed it.') === true, said($asked));
$before = (string) file_get_contents($owner);
$in     = giveCode($owner, $rootA);
ok('C10 the new code lets it in again, the count goes back to nought, and the session is given a new number as it is let in',
    $in['status'] === 302 && page(req($owner, 'GET', '/?step=2')) === 'database' && (codeIn($rootA)['wrongTries'] ?? -1) === 0
    && preg_match('/PHPSESSID\s+(\S+)/', $before, $was) === 1 && preg_match('/PHPSESSID\s+(\S+)/', (string) file_get_contents($owner), $is) === 1 && $was[1] !== $is[1], said($in));
$noToken = req($owner, 'POST', '/', $realLogin($databases['a']) + ['action' => 'db_config']);
$wrongToken = req($owner, 'POST', '/', $realLogin($databases['a']) + ['action' => 'db_config', 'install_token' => str_repeat('0', 64)]);
ok('C11 even with the code, a form that does not carry this session\'s token is not acted on: the database step with a real login, sent with no token and with a wrong one, creates nothing and says why',
    databaseExists($databases['a']) === false && str_contains(message($noToken), 'That form could not be used') === true && str_contains(message($wrongToken), 'That form could not be used') === true
    && page(req($owner, 'GET', '/?step=3')) === 'database', said($noToken) . ' | ' . said($wrongToken));
// The code form itself, without the token. The RIGHT code is used: if the token were not asked for here, it would let in.
$noTokenJar = jar('notoken');
req($noTokenJar, 'GET', '/');
$rightCode  = installCodeAsShown(codeOf($rootA));
$bare       = [
    'the right code, no token'      => req($noTokenJar, 'POST', '/', ['action' => 'owner_code', 'owner_code' => $rightCode]),
    'the right code, a wrong token' => req($noTokenJar, 'POST', '/', ['action' => 'owner_code', 'owner_code' => $rightCode, 'install_token' => str_repeat('0', 64)]),
    'a wrong code, no token'        => req($noTokenJar, 'POST', '/', ['action' => 'owner_code', 'owner_code' => 'ZZZZ-ZZZZ-ZZZZ-ZZZZ']),
];
$actedOn = array_filter($bare, static fn (array $a): bool => $a['status'] !== 200 || page($a) !== 'code' || str_contains(message($a), 'That form could not be used') === false);
ok('C13 the code form is not acted on without this session\'s token either: the RIGHT code sent with no token, and with a wrong one, lets nobody in, and a wrong code sent that way is not counted as a try',
    $actedOn === [] && page(req($noTokenJar, 'GET', '/?step=1')) === 'code' && (codeIn($rootA)['wrongTries'] ?? -1) === 0 && codeOf($rootA) === str_replace('-', '', $rightCode),
    implode(' | ', array_map('said', $bare)) . '; count ' . json_encode(codeIn($rootA)['wrongTries'] ?? null));
// A dot is not allowed in a session number, and PHP raises two warnings when it is handed one.
$logBefore = errorLog();
$bad       = req('', 'GET', '/', null, ['Cookie: PHPSESSID=not.a.session.number']);
$logAfter  = errorLog();
ok('C12 a session cookie PHP itself would refuse gets the first page; PHP is never handed it, so no warning is raised at all, shown or not',
    $bad['status'] === 200 && shape($bad) === shape($first) && leak($bad) === '' && $logBefore === '' && $logAfter === '', said($bad) . ' ' . leak($bad) . ' log: ' . $logBefore . $logAfter);

// =============================================================================
// I. ONE WHOLE INSTALLATION, WITH NO CHANNEL FILE
// =============================================================================
head('I. One whole installation: no .channel file, a web folder named httpdocs');

$checks = req($owner, 'GET', '/?step=1');
$row    = static fn (array $answer, string $check): string => preg_match('/<li class="install-check" data-check="' . $check . '">(.*?)<\/li>/s', $answer['body'], $m) === 1
    ? trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '(no such row)';
ok('I01 the checks: one top-level heading, the version shown now that the code was given, every row in order, and a way on',
    substr_count($checks['body'], '<h1') === 1 && str_contains(words($checks), 'version ' . $version) === true && str_contains($checks['body'], 'Needs attention') === false
    && str_contains($checks['body'], 'href="?step=1.5"') === true && leak($checks) === '', said($checks));
ok('I02 with no .channel file and a web folder of another name, the checks say the channel is ASSUMED, in amber, with the file to create and the word to put in it',
    str_contains($row($checks, 'channel'), 'Assumed') === true && str_contains($row($checks, 'channel'), 'This is the live portal. That is ASSUMED: nothing on this server says which copy this is.') === true
    && str_contains($row($checks, 'channel'), 'create a plain text file named .channel in the folder that holds _core') === true && str_contains($row($checks, 'channel'), 'that word is: live') === true, $row($checks, 'channel'));
file_put_contents($rootA . $sep . '.channel', "beta\n");
$stated = req($owner, 'GET', '/?step=1');
gone($rootA . $sep . '.channel');
ok('I03 with a .channel file saying beta, the same row says the word and that the file said it, and does not say assumed',
    str_contains($row($stated, 'channel'), 'This is a beta copy (a test copy, not the live portal). The file .channel, beside the _core folder, says so.') === true
    && stripos($row($stated, 'channel'), 'assumed') === false, $row($stated, 'channel'));
file_put_contents($rootA . $sep . '.channel', "production\n");
$unknown = req($owner, 'GET', '/?step=1');
gone($rootA . $sep . '.channel');
ok('I34 with a .channel file holding a word the portal does not know ("production"), the row still says ASSUMED, but says the file is there and was not used, and which words the portal knows: never "nothing says" to somebody who can see the file',
    str_contains($row($unknown, 'channel'), 'Assumed') === true && str_contains($row($unknown, 'channel'), 'This is the live portal. That is ASSUMED: there is a file named .channel beside the _core folder, but it was not used') === true
    && str_contains($row($unknown, 'channel'), 'The words the portal knows are live, alpha, beta and dev.') === true && str_contains($row($unknown, 'channel'), 'open the file .channel') === true
    && str_contains($row($unknown, 'channel'), 'create a plain text file') === false, $row($unknown, 'channel'));
rename($rootA . $sep . 'httpdocs' . $sep . '.htaccess', $rootA . $sep . 'httpdocs' . $sep . 'htaccess-set-aside');
$missing = req($owner, 'GET', '/?step=1');
rename($rootA . $sep . 'httpdocs' . $sep . 'htaccess-set-aside', $rootA . $sep . 'httpdocs' . $sep . '.htaccess');
ok('I04 when the web folder\'s rules file did not arrive, its row says so, says what to do in a file manager, and the way on is taken away',
    str_contains($row($missing, 'web_rules'), 'Needs attention') === true && str_contains($row($missing, 'web_rules'), 'show hidden files') === true
    && str_contains($missing['body'], 'href="?step=1.5"') === false && str_contains($missing['body'], '>Check again</a>') === true, $row($missing, 'web_rules'));

$kind = post($owner, '/?step=1.5', ['action' => 'select_industry', 'industry' => 'generic']);
ok('I05 the kind of organisation: one heading, and the choice leads to the database step', $kind['status'] === 302 && $kind['location'] === '?step=2' && substr_count(req($owner, 'GET', '/?step=1.5')['body'], '<h1') === 1, said($kind));

$wrongPass = post($owner, '/?step=2', ['db_pass' => 'not-the-password-918'] + $realLogin($databases['a']) + ['action' => 'db_config']);
ok('I06 the database step with a wrong password: refused in words that say what to check, nothing created, and the password is not printed back',
    page($wrongPass) === 'database' && str_contains(message($wrongPass), 'The database refused that user name and password.') === true && databaseExists($databases['a']) === false
    && str_contains($wrongPass['body'], 'not-the-password-918') === false && leak($wrongPass) === '', said($wrongPass));
$databasePage = req($owner, 'GET', '/?step=2');
ok('I35 the database page says what its button really does: it creates the database when there is none of that name and the user may, builds no table yet, and changes nothing in a database that is already there',
    preg_match('/<p[^>]*id="install-database-button"[^>]*>(.*?)<\/p>/s', $databasePage['body'], $m) === 1 && str_contains($m[1], 'the button creates it, empty') === true
    && str_contains($m[1], 'No table is built yet') === true && str_contains(words($databasePage), 'Nothing is built yet') === false && databaseExists($databases['a']) === false, said($databasePage));
$state['made'][] = $databases['a'];
$login = post($owner, '/?step=2', $realLogin($databases['a']) + ['action' => 'db_config']);
ok('I07 with the right login, a database that does not exist yet is made and the installer moves on to building the tables', $login['status'] === 302 && $login['location'] === '?step=3' && databaseExists($databases['a']) === true, said($login));
$tablesPage = req($owner, 'GET', '/?step=3');
$started    = microtime(true);
$built      = post($owner, '/?step=3', ['action' => 'install_schema']);
$tables     = n($databases['a'], "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'tbl%'", 's', [$databases['a']]);
ok('I08 the tables are built (' . $tables . ' of them, in ' . round(microtime(true) - $started) . ' s) and the installer moves on to the first administrator',
    page($tablesPage) === 'tables' && $built['status'] === 302 && $built['location'] === '?step=4' && $tables > 100, said($built));
ok('I09 the install script seeded ONE empty row for the portal\'s address and one for the support address, which is what the finish has to fill', setting($databases['a'], 'site.url') === '' && count(q($databases['a'], "SELECT 1 FROM tblSettings WHERE settingKey = 'site.url'")) === 1
    && count(q($databases['a'], "SELECT 1 FROM tblSettings WHERE settingKey = 'portal.support.email'")) === 1);

$password = 'Selftest-' . bin2hex(random_bytes(8)) . '-Aa1!';
$person   = ['action' => 'create_admin', 'admin_name' => 'First Administrator', 'admin_email' => ADMIN_EMAIL, 'admin_pass' => $password, 'admin_pass2' => $password];
$long     = post($owner, '/?step=4', ['admin_email' => str_repeat('a', 40) . '@installer-selftest.invalid'] + $person);
$weak     = post($owner, '/?step=4', ['admin_pass' => 'short', 'admin_pass2' => 'short'] + $person);
$unlike   = post($owner, '/?step=4', ['admin_pass2' => $password . 'x'] + $person);
ok('I10 the first administrator: an address too long to sign in with, a weak password and two passwords that differ are each refused in words, and no part of an account is saved',
    str_contains(message($long), 'longer than 50 characters') === true && str_contains(message($weak), 'That password cannot be used.') === true && str_contains(message($unlike), 'The two passwords are not the same.') === true
    && n($databases['a'], 'SELECT COUNT(*) AS n FROM tblUsers') === 0 && n($databases['a'], 'SELECT COUNT(*) AS n FROM tblLocalAccounts') === 0, said($long) . ' | ' . said($weak) . ' | ' . said($unlike));
$made    = post($owner, '/?step=4', $person);
$account = q($databases['a'], 'SELECT u.userID, u.isRootAdmin, u.isActive, l.username, l.isVerified, s.siteID, s.isSiteRootAdmin FROM tblUsers u JOIN tblLocalAccounts l ON l.userID = u.userID JOIN tblUserSites s ON s.userID = u.userID WHERE u.emailAddress = ?', 's', [ADMIN_EMAIL]);
ok('I11 a good one makes exactly one account: a global administrator, active, with a password it can sign in with and a place in the one organisation; then on to the web addresses',
    $made['status'] === 302 && $made['location'] === '?step=4.5' && count($account) === 1 && (int) $account[0]['isRootAdmin'] === 1 && (int) $account[0]['isActive'] === 1
    && $account[0]['username'] === ADMIN_EMAIL && (int) $account[0]['isVerified'] === 1 && (int) $account[0]['siteID'] === 1 && n($databases['a'], 'SELECT COUNT(*) AS n FROM tblUsers') === 1, said($made) . ' ' . json_encode($account));

$addressPage = req($owner, 'GET', '/?step=4.5');
ok('I12 the web addresses page offers the address in use for the person to check, and has one heading', page($addressPage) === 'addresses' && substr_count($addressPage['body'], '<h1') === 1
    && str_contains($addressPage['body'], 'name="portal_address"') === true && str_contains($addressPage['body'], 'value="http://127.0.0.1:' . $ports['main'] . '"') === true, said($addressPage));
$badAddresses = [
    'a path'             => ['https://installer-selftest.invalid/portal', 'must stop at the name'],
    'another scheme'     => ['ftp://installer-selftest.invalid', 'must begin with https:// or http://'],
    'a line break'       => ["https://installer-selftest.invalid\r\nBcc: somebody@installer-selftest.invalid", 'a space or a line break'],
    'a user name'        => ['https://admin@installer-selftest.invalid', 'must not hold a user name or password'],
    'not a host name'    => ['https://installer_selftest!.invalid', 'not a host name the portal can use'],
    'nothing at all'     => ['', 'Type the address people will use to reach this portal'],
];
$accepted = [];
foreach ($badAddresses as $what => [$typed, $reason]) {
    $answer = post($owner, '/?step=4.5', ['action' => 'web_addresses', 'portal_address' => $typed, 'support_email' => SUPPORT_TYPED]);
    if (page($answer) !== 'addresses' || str_contains(message($answer), $reason) === false || str_starts_with(message($answer), 'Nothing was saved.') === false || page(req($owner, 'GET', '/?step=5')) !== 'addresses') {
        $accepted[] = $what . ': ' . said($answer);
    }
}
ok('I13 a bad address is refused in words and nothing is kept: a path, another scheme, a line break, a user name, something that is not a host name, and nothing at all', $accepted === [], implode(' | ', $accepted));
$badSupport = post($owner, '/?step=4.5', ['action' => 'web_addresses', 'portal_address' => ADDRESS_TYPED, 'support_email' => 'not an address']);
ok('I14 a support address that is not an e-mail address is refused in words, and nothing is kept', page($badSupport) === 'addresses' && str_contains(message($badSupport), 'The support e-mail address is not an e-mail address') === true
    && page(req($owner, 'GET', '/?step=5')) === 'addresses', said($badSupport));
$typed = post($owner, '/?step=4.5', ['action' => 'web_addresses', 'portal_address' => ' HTTPS://Portal.Installer-Selftest.invalid/ ', 'support_email' => SUPPORT_TYPED]);
ok('I15 a good address, typed untidily, is accepted and leads to the finish; nothing has been written to the database yet', $typed['status'] === 302 && $typed['location'] === '?step=5' && setting($databases['a'], 'site.url') === '', said($typed));

$finish = req($owner, 'GET', '/?step=5');
ok('I16 the finish page says what it is about to set up: the database by name, the tidied address, the support address, that the channel is assumed; it asks for the code again; one heading',
    page($finish) === 'finish' && substr_count($finish['body'], '<h1') === 1 && str_contains($finish['body'], '<code>' . $databases['a'] . '</code>') === true && str_contains($finish['body'], '<code>' . ADDRESS_TYPED . '</code>') === true
    && str_contains($finish['body'], '<code>' . SUPPORT_TYPED . '</code>') === true && str_contains(words($finish), 'That is ASSUMED') === true && str_contains($finish['body'], 'name="owner_code"') === true, said($finish));
$noCode    = post($owner, '/?step=5', ['action' => 'finalize']);
$wrongCode = post($owner, '/?step=5', ['action' => 'finalize', 'owner_code' => installCodeAsShown(oneLetterOut(codeOf($rootA)))]);
ok('I17 the finish asks for the code AGAIN: with none, and with a wrong one, it refuses in words, though this session was let in',
    page($noCode) === 'finish' && str_contains(message($noCode), 'Type the installation code into the box to finish.') === true && page($wrongCode) === 'finish'
    && str_starts_with(message($wrongCode), 'That is not the code in the file.') === true && (codeIn($rootA)['wrongTries'] ?? -1) === 1, said($noCode) . ' | ' . said($wrongCode));
ok('I18 and neither attempt wrote anything: no database login, no key file, no lock, no folders, no address', names($authA) === '.htaccess, install-code.php' && is_dir($rootA . $sep . '_uploads') === false
    && is_dir($rootA . $sep . '_backups') === false && setting($databases['a'], 'site.url') === '' && setting($databases['a'], 'portal.installed_version') === '', names($authA));

// A FINISH THAT STOPS AT ITS LAST WRITE. A mistyped address is given; the _auth_keys folder is made read-only, so the
// database login (a new file) cannot be written, while the key (a file already there, see below) can. By then the finish
// has stored the mistyped address. The person then goes Back, corrects it, and finishes.
@file_put_contents($authA . $sep . 'enc.key', '');
post($owner, '/?step=4.5', ['action' => 'web_addresses', 'portal_address' => 'https://mistyped.installer-selftest.invalid', 'support_email' => 'mistyped@installer-selftest.invalid']);
chmod($authA, 0500);
$stopped = post($owner, '/?step=5', ['action' => 'finalize', 'owner_code' => installCodeAsShown(codeOf($rootA))]);
chmod($authA, 0750);
ok('I36 a finish that stops at its LAST write (the database login cannot be written) says so and what to check, and stays unfinished: no login, no lock, no version recorded, the code file still there. It had already stored the address typed',
    page($stopped) === 'finish' && str_contains(message($stopped), 'the file that holds the database login could not be written') === true && names($authA) === '.htaccess, enc.key, install-code.php'
    && setting($databases['a'], 'portal.installed_version') === '' && setting($databases['a'], 'site.url') === 'https://mistyped.installer-selftest.invalid'
    && setting($databases['a'], 'portal.support.email') === 'mistyped@installer-selftest.invalid', said($stopped) . '; ' . names($authA) . '; ' . setting($databases['a'], 'site.url'));
$corrected = post($owner, '/?step=4.5', ['action' => 'web_addresses', 'portal_address' => ADDRESS_TYPED, 'support_email' => SUPPORT_TYPED]);

// An EMPTY file where the key file goes, as a failed upload leaves one: it is no key, and must not be kept as one.
// (Emptied again here: the finish that stopped had written a key into it.)
@file_put_contents($authA . $sep . 'enc.key', '');
// And the owner has made the code file read-only, as the code page suggests against somebody who keeps sending wrong codes.
chmod($authA . $sep . 'install-code.php', 0440);
$done = post($owner, '/?step=5', ['action' => 'finalize', 'owner_code' => strtolower(codeOf($rootA))]);
ok('I37 the address corrected after that stopped finish REPLACES the one it had stored (this run filled it in itself), and the last page does not claim that the database "already had an address saved"',
    $corrected['status'] === 302 && page($done) === 'done' && setting($databases['a'], 'site.url') === ADDRESS_TYPED && setting($databases['a'], 'portal.support.email') === SUPPORT_TYPED
    && str_contains(words($done), 'already had an address saved') === false && str_contains(words($done), 'That is not the address you typed') === false, said($done) . '; stored: ' . setting($databases['a'], 'site.url'));
ok('I38 a code file its owner had made read-only is removed by the finish all the same, and the last page says each of the three private folders refuses and that the code has been deleted',
    is_file($authA . $sep . 'install-code.php') === false && str_contains(words($done), '_auth_keys, _uploads and _backups each hold a file that tells the web server to refuse every request for anything inside.') === true
    && str_contains(words($done), 'The installation code has been deleted.') === true && str_contains($done['body'], 'id="install-done-own-rules"') === false
    && str_contains($done['body'], 'id="install-done-code-left"') === false, names($authA));
ok('I19 with the right code (typed in small letters, without dashes) the installation finishes and its last page is drawn in the same answer: one heading, shown once',
    $done['status'] === 200 && page($done) === 'done' && substr_count($done['body'], '<h1') === 1 && str_contains(words($done), 'Installation complete') === true
    && str_contains(words($done), 'This page is shown only once') === true && leak($done) === '', said($done));
ok('I20 the last page says the address the portal will use in e-mails', str_contains(words($done), 'Every link in an e-mail the portal sends will begin with ' . ADDRESS_TYPED . '.') === true);
$doneChannel = preg_match('/<li class="install-check" data-done="channel" data-channel-source="([a-z]+)">(.*?)<\/li>/s', $done['body'], $m) === 1
    ? [$m[1], trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')))] : ['', '(no such row)'];
ok('I21 it says which copy this is and how that was decided: ASSUMED live, because nothing says; and it recommends the file .channel with the exact word to put in it',
    $doneChannel[0] === 'assumed' && str_contains($doneChannel[1], 'This is the live portal. That is ASSUMED: nothing on this server says which copy this is.') === true
    && str_contains($doneChannel[1], 'create a plain text file named .channel in the folder that holds _core (beside _core, not inside it), with one word on its first line. For the live portal that word is: live.') === true, json_encode($doneChannel));
ok('I22 it says to back up the key file together with the database, and what is lost without it', str_contains(words($done), 'Back up the key file together with the database.') === true
    && str_contains(words($done), 'A backup of the database is not complete without it') === true && str_contains(words($done), 'enc.key') === true);
ok('I23 and what to do next, with a button that goes to the portal', str_contains(words($done), 'What to do next:') === true && str_contains($done['body'], '<a href="/" class="btn btn-primary btn-lg">') === true);

$keyA = (string) @file_get_contents($authA . $sep . 'enc.key');
ok('I24 the finished installation has a FULL key file, 64 characters, each 0-9 or a-f, though an empty file of that name was in its place; and the last page does not claim a key was kept',
    preg_match('/^[0-9a-f]{64}$/', $keyA) === 1 && str_contains(words($done), 'a key file was already there') === false, strlen($keyA) . ' bytes');
$private = [];
foreach (['_auth_keys', '_uploads', '_backups'] as $folder) {
    $file = $rootA . $sep . $folder . $sep . '.htaccess';
    if (is_file($file) === false || file_get_contents($file) !== $shippedRefuse) {
        $private[] = $folder;
    }
}
ok('I25 _uploads and _backups exist, and they and _auth_keys each hold the "refuse everything" file, the same file that ships in _core', $private === [] && is_dir($rootA . $sep . '_uploads') === true && is_dir($rootA . $sep . '_backups') === true, 'wrong or missing in: ' . implode(', ', $private));
ok('I26 _auth_keys holds the database login, the key, the lock and the refuse file, and the code file is gone', names($authA) === '.htaccess, .installed, auth_creds.php, enc.key', names($authA));
$creds = is_file($authA . $sep . 'auth_creds.php') === true ? (require $authA . $sep . 'auth_creds.php') : [];
ok('I27 the database login written is the one that was typed', $creds === ['db_host' => $dbLogin['host'], 'db_user' => $dbLogin['user'], 'db_pass' => $dbLogin['pass'], 'db_name' => $databases['a'], 'db_port' => $dbLogin['port']], json_encode(array_keys((array) $creds)));
ok('I28 the database holds EXACTLY ONE site.url row, and it holds the address that was typed, tidied', setting($databases['a'], 'site.url') === ADDRESS_TYPED
    && count(q($databases['a'], "SELECT 1 FROM tblSettings WHERE settingKey = 'site.url'")) === 1, 'rows: ' . setting($databases['a'], 'site.url'));
ok('I29 and exactly one row for the support address, holding what was typed; the installed version is recorded', setting($databases['a'], 'portal.support.email') === SUPPORT_TYPED
    && count(q($databases['a'], "SELECT 1 FROM tblSettings WHERE settingKey = 'portal.support.email'")) === 1 && setting($databases['a'], 'portal.installed_version') === $version,
    setting($databases['a'], 'portal.support.email') . ' / ' . setting($databases['a'], 'portal.installed_version'));
ok('I30 the whole installation raised no PHP warning or notice, shown or not', errorLog() === '', errorLog());

$again = [req($owner, 'GET', '/?step=1'), req($owner, 'GET', '/?step=5'), post($owner, '/', ['action' => 'finalize', 'owner_code' => 'ABCD-EFGH-JKMN-PQRS']), req('', 'GET', '/?step=2')];
ok('I31 a second visit after the finish does not reach the installer by any step: the portal answers now', array_filter($again, static fn (array $a): bool => page($a) !== '' || str_contains($a['body'], 'install_token') === true) === [],
    implode(' | ', array_map('said', $again)));
startServer('wrong', $rootA);
$direct = [req('', 'GET', '/_install/index.php', null, [], 'wrong'), req('', 'POST', '/_install/index.php?step=5', ['action' => 'finalize'], [], 'wrong')];
stopServer('wrong');
ok('I32 and the installer itself, opened directly, refuses: "Already installed", by GET and by POST, with one heading and no form',
    count(array_filter($direct, static fn (array $a): bool => $a['status'] === 403 && str_contains($a['body'], '<h1>Already installed</h1>') === true && str_contains($a['body'], '<form') === false && substr_count($a['body'], '<h1') === 1)) === 2,
    implode(' | ', array_map(static fn (array $a): string => 'HTTP ' . $a['status'], $direct)));
ok('I33 nothing changed on that second visit: the same key, the same files, still one address row', (string) @file_get_contents($authA . $sep . 'enc.key') === $keyA && names($authA) === '.htaccess, .installed, auth_creds.php, enc.key'
    && setting($databases['a'], 'site.url') === ADDRESS_TYPED);

// =============================================================================
// U. THE UPGRADE PAGE OF THAT INSTALLATION
// =============================================================================
head('U. The upgrade page: the channel, the refuse files, and its four addresses');

/** Sign somebody in through the real sign-in page: the first administrator, unless another address is given. Returns the cookie jar. */
$signIn = static function (string $prefix = '', string $who = ADMIN_EMAIL) use ($password): string {
    $jar  = jar('admin');
    $form = req($jar, 'GET', $prefix . '/login');
    $tok  = preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $form['body'], $m) === 1 ? $m[1] : '';
    // If this does not sign anybody in (the installation never finished, say), the checks that follow fail by themselves.
    req($jar, 'POST', $prefix . '/login', ['csrf_token' => $tok, 'username' => $who, 'password' => $password]);

    return $jar;
};
// The upgrade page draws its own form only when an upgrade is waiting, and none is on a new installation: the token is
// taken from the page head, where every page of the portal carries it.
$csrf    = static fn (array $answer): string => preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $answer['body'], $m) === 1 ? $m[1] : '';
$copyRow = static fn (array $answer): string => preg_match('/<div[^>]*id="upgrade-channel"[^>]*>(.*?)<\/div>/s', $answer['body'], $m) === 1
    ? trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '(no such part)';

$admin   = $signIn();
$upgrade = req($admin, 'GET', '/admin/upgrade');
ok('U01 the administrator the installer made can sign in and open the upgrade page: one top-level heading', $upgrade['status'] === 200 && substr_count($upgrade['body'], '<h1') === 1 && str_contains($upgrade['body'], 'Upgrade Portal') === true, 'HTTP ' . $upgrade['status']);
ok('U02 it says which copy this is and how that was decided: live, ASSUMED, with the file to create and the word to put in it',
    str_contains($copyRow($upgrade), 'This is the live portal. That is ASSUMED: nothing on this server says which copy this is.') === true && str_contains($copyRow($upgrade), 'that word is: live') === true
    && str_contains($upgrade['body'], 'data-channel-source="assumed"') === true, $copyRow($upgrade));
file_put_contents($rootA . $sep . '.channel', "live\n");
$stated = req($admin, 'GET', '/admin/upgrade');
gone($rootA . $sep . '.channel');
ok('U03 with a .channel file saying live, it says the word and that the file said it, and does not say assumed',
    str_contains($copyRow($stated), 'This is the live portal. The file .channel, beside the _core folder, says so.') === true && stripos($copyRow($stated), 'assumed') === false
    && str_contains($stated['body'], 'data-channel-source="file"') === true, $copyRow($stated));

// An administrator of ONE organisation, who is not a global administrator: made straight in the database, with the same
// password. The upgrade page reshapes the database under every organisation, and now also writes files when it is opened.
q($databases['a'], 'INSERT INTO tblUsers (fullName, emailAddress, isAdmin, isRootAdmin, isActive, createdAt) VALUES (?, ?, 1, 0, 1, NOW())', 'ss', ['An Organisation\'s Own Administrator', ORG_ADMIN_EMAIL]);
$orgAdminId = (int) (q($databases['a'], 'SELECT userID FROM tblUsers WHERE emailAddress = ?', 's', [ORG_ADMIN_EMAIL])[0]['userID'] ?? 0);
q($databases['a'], 'INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified, createdAt) VALUES (?, ?, ?, 1, NOW())', 'iss', [$orgAdminId, ORG_ADMIN_EMAIL, password_hash($password, PASSWORD_DEFAULT)]);
q($databases['a'], 'INSERT INTO tblUserSites (userID, siteID, isSiteAdmin, isSiteRootAdmin, isActive) VALUES (?, 1, 1, 0, 1)', 'i', [$orgAdminId]);
gone($rootA . $sep . '_uploads' . $sep . '.htaccess');
$orgAdmin   = $signIn('', ORG_ADMIN_EMAIL);
$adminArea  = req($orgAdmin, 'GET', '/admin');
$notForThem = req($orgAdmin, 'GET', '/admin/upgrade');
$nobody     = req(jar('nobody'), 'GET', '/admin/upgrade');
ok('U12 the upgrade page does not open for an administrator of one organisation who is not a global administrator (they are signed in, and the Admin area opens for them), nor for somebody not signed in; and it wrote nothing for either: the missing refuse file is still missing',
    $orgAdminId > 0 && $adminArea['status'] === 200 && $notForThem['status'] === 403 && str_contains($notForThem['body'], 'Upgrade Portal') === false && str_contains($notForThem['body'], 'id="upgrade-channel"') === false
    && in_array($nobody['status'], [302, 401, 403], true) === true && str_contains($nobody['body'], 'id="upgrade-channel"') === false
    && is_file($rootA . $sep . '_uploads' . $sep . '.htaccess') === false, 'Admin area HTTP ' . $adminArea['status'] . '; upgrade page HTTP ' . $notForThem['status'] . '; not signed in HTTP ' . $nobody['status']);

gone($rootA . $sep . '_uploads' . $sep . '.htaccess');
gone($rootA . $sep . '_auth_keys' . $sep . '.htaccess');
@file_put_contents($rootA . $sep . '_backups' . $sep . '.htaccess', "# A comment only: no rule in it.\n");
$mended = req($admin, 'GET', '/admin/upgrade');
$back   = array_filter(['_auth_keys', '_uploads', '_backups'], static fn (string $folder): bool => @file_get_contents($rootA . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . '.htaccess') === $shippedRefuse);
ok('U04 a "refuse everything" file that was deleted (two of them), and one that held a comment and no rule, are put back when the page is opened, and the page says which',
    count($back) === 3 && preg_match('/id="upgrade-private-folders"[^>]*>(.*?)<\/div>/s', $mended['body'], $m) === 1 && str_contains(strip_tags($m[1]), '_auth_keys, _uploads and _backups') === true,
    'in order: ' . implode(', ', $back));
$own = "# Somebody's own rules for this folder.\nRequire all denied\nOptions -Indexes\n";
@file_put_contents($rootA . $sep . '_uploads' . $sep . '.htaccess', $own);
$left = req($admin, 'GET', '/admin/upgrade');
ok('U05 a rules file that holds a rule (somebody\'s own) is left exactly as it is, and the page then says nothing about the folders',
    @file_get_contents($rootA . $sep . '_uploads' . $sep . '.htaccess') === $own && str_contains($left['body'], 'id="upgrade-private-folders"') === false);
@file_put_contents($rootA . $sep . '_uploads' . $sep . '.htaccess', $shippedRefuse);

// The four addresses, with organisations told apart by the first part of the address. That way is switched ON in the scratch
// copy only: the portal marks it not ready, and this test does not say otherwise.
$plainTrail = str_contains($upgrade['body'], 'href="/admin"') === true;
$wrong      = req($admin, 'POST', '/admin/upgrade', ['csrf_token' => str_repeat('0', 64)]);
ok('U06 CONTROL, with one organisation and no first parts in use: the breadcrumb goes to /admin and a refused form goes back to /admin/upgrade', $plainTrail === true && $wrong['status'] === 302 && $wrong['location'] === '/admin/upgrade', 'HTTP ' . $wrong['status'] . ' to ' . $wrong['location']);
$siteFile = $rootA . $sep . '_core' . $sep . 'Site.php';
$switched = str_replace('public const FIRST_PART_MODE_READY = false;', 'public const FIRST_PART_MODE_READY = true;', (string) file_get_contents($siteFile), $replaced);
// If the switch is not the one line this expects, it is left alone and U07 to U10 fail by themselves.
if ($replaced === 1) {
    file_put_contents($siteFile, $switched);
}
q($databases['a'], 'INSERT INTO tblSites (siteID, siteKey, siteName, hostPattern, isActive) VALUES (?, ?, ?, NULL, 1)', 'iss', [ORG_ID, ORG_KEY, 'Installer Selftest North']);
q($databases['a'], "UPDATE tblSettings SET settingValue = 'true' WHERE settingKey = 'multisite.enabled' AND siteID IS NULL");
q($databases['a'], "UPDATE tblSettings SET settingValue = 'path' WHERE settingKey = 'multisite.detectionMode' AND siteID IS NULL");
$admin = $signIn('/' . ORG_KEY);
$here  = '/' . ORG_KEY . '/admin/upgrade';
$page  = req($admin, 'GET', $here);
ok('U07 at an organisation\'s own address the page opens, and its breadcrumb keeps the organisation\'s part', $page['status'] === 200 && str_contains($page['body'], 'href="/' . ORG_KEY . '/admin"') === true
    && str_contains($page['body'], 'href="/admin"') === false, 'HTTP ' . $page['status'] . ' ' . $page['location']);
$wrong = req($admin, 'POST', $here, ['csrf_token' => str_repeat('0', 64)]);
ok('U08 a form with a wrong token is sent back to the organisation\'s own upgrade page', $wrong['status'] === 302 && $wrong['location'] === $here, 'HTTP ' . $wrong['status'] . ' to ' . $wrong['location']);
@chmod($rootA . $sep . '_backups', 0500);
$failed = req($admin, 'POST', $here, ['csrf_token' => $csrf(req($admin, 'GET', $here))]);
@chmod($rootA . $sep . '_backups', 0750);
$shown  = req($admin, 'GET', $here);
ok('U09 when the snapshot cannot be taken, the refusal goes back to the organisation\'s own upgrade page, which says why', $failed['status'] === 302 && $failed['location'] === $here && str_contains($shown['body'], 'Backup failed, so the upgrade was not started.') === true,
    'HTTP ' . $failed['status'] . ' to ' . $failed['location']);
// A value that is not on its column's list of choices, saved the only way it can be: by a connection set to accept anything.
// (If the database is not there, because the installer under test is broken, the check below fails by itself.)
$modeWas = (string) (q($databases['a'], 'SELECT @@session.sql_mode AS m')[0]['m'] ?? '');
q($databases['a'], "CREATE TABLE tblZzInstallerSelftest (id INT PRIMARY KEY, e ENUM('a','b') NULL) ENGINE=InnoDB");
q($databases['a'], "SET SESSION sql_mode = ''");
q($databases['a'], "INSERT INTO tblZzInstallerSelftest VALUES (1, 'a'), (2, 'not on the list')");
q($databases['a'], 'SET SESSION sql_mode = ?', 's', [$modeWas]);
$stopped = req($admin, 'POST', $here, ['csrf_token' => $csrf(req($admin, 'GET', $here))]);
$shown   = req($admin, 'GET', $here);
q($databases['a'], 'DROP TABLE tblZzInstallerSelftest');
ok('U10 when the snapshot cannot be put back whole, the refusal goes back to the organisation\'s own upgrade page too', $stopped['status'] === 302 && $stopped['location'] === $here && str_contains($shown['body'], 'The upgrade was NOT started') === true,
    'HTTP ' . $stopped['status'] . ' to ' . $stopped['location']);
ok('U11 none of the three left the portal closed for an upgrade', setting($databases['a'], 'portal.maintenance.active') === '0', setting($databases['a'], 'portal.maintenance.active'));
stopServer('main');

// =============================================================================
// L. A SECOND INSTALLATION: A CHANNEL FILE, LOST SESSIONS, A KEY ALREADY THERE
// =============================================================================
head('L. A second installation: .channel says beta, the session is lost twice, a key file is already there');

errorLog(); // step over what part U made the portal log on purpose
file_put_contents($rootB . $sep . '.channel', "beta\n");
startServer('main', $rootB, 'httpdocs');
$second = jar('second');
req($second, 'GET', '/');
$in     = giveCode($second, $rootB);
$checks = req($second, 'GET', '/?step=1');
ok('L01 the checks say this is a beta copy, that the file said so, and do not say assumed', $in['status'] === 302 && str_contains($row($checks, 'channel'), 'This is a beta copy (a test copy, not the live portal). The file .channel, beside the _core folder, says so.') === true
    && stripos($row($checks, 'channel'), 'assumed') === false && str_contains($row($checks, 'channel'), 'For your information') === true, $row($checks, 'channel'));
post($second, '/?step=1.5', ['action' => 'select_industry', 'industry' => 'generic']);
$state['made'][] = $databases['b'];
$login = post($second, '/?step=2', $realLogin($databases['b']) + ['action' => 'db_config']);
$built = post($second, '/?step=3', ['action' => 'install_schema']);
ok('L02 the database is made and the tables are built', $login['location'] === '?step=3' && $built['status'] === 302 && $built['location'] === '?step=4', said($login) . ' | ' . said($built));

// The session is lost: a new visitor with no cookie, as after twenty quiet minutes on shared hosting.
$third = jar('third');
$lost  = req($third, 'GET', '/?step=4');
ok('L03 the session is lost after the tables were built: the installer just asks for the code again', page($lost) === 'code' && shape($lost) === shape(req('', 'GET', '/')), said($lost));
$in      = giveCode($third, $rootB);
$nowhere = req($third, 'GET', '/?step=4');
// Another program's table in the same database, named the way the portal names its own. The wipe would remove it.
q($databases['b'], 'CREATE TABLE tblSomebodyElsesInvoices (id INT PRIMARY KEY) ENGINE=InnoDB');
$shared  = post($third, '/?step=2', $realLogin($databases['b']) + ['action' => 'db_config']);
$kept    = page(req($third, 'GET', '/?step=2.5'));
$still   = n($databases['b'], "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tblSomebodyElsesInvoices'", 's', [$databases['b']]);
q($databases['b'], 'DROP TABLE tblSomebodyElsesInvoices');
ok('L04 a database that also holds a table the portal does not make is refused by name: not built into, not wiped, and nothing of the login is kept',
    page($shared) === 'database' && str_contains(message($shared), 'not everything in it is this portal\'s') === true && str_contains(message($shared), 'tblSomebodyElsesInvoices') === true
    && str_contains(message($shared), 'Give the portal a database of its own') === true && $kept === 'database' && $still === 1, said($shared) . '; then page "' . $kept . '"');
$login   = post($third, '/?step=2', $realLogin($databases['b']) + ['action' => 'db_config']);
ok('L05 with the code again and the database details again, a database that holds tables but never finished an installation is NOT refused for want of a sign-in nobody could give',
    $in['status'] === 302 && page($nowhere) === 'database' && $login['status'] === 302 && $login['location'] === '?step=2.5', said($login));
$existing = req($third, 'GET', '/?step=2.5');
ok('L06 the page for a database that is not empty opens (it used to end in an error for everybody), with one heading, and says what it found and the two choices',
    $existing['status'] === 200 && page($existing) === 'existing' && substr_count($existing['body'], '<h1') === 1 && str_contains($existing['body'], 'id="install-existing-unfinished"') === true
    && str_contains(words($existing), 'Carry on with what is there') === true && str_contains(words($existing), 'Wipe it and start again from nothing') === true && leak($existing) === '', said($existing));
// Three people are put into it, as a working portal whose version was never recorded would have: the page must show them.
q($databases['b'], "INSERT INTO tblUsers (fullName, emailAddress, isAdmin, isRootAdmin, isActive, createdAt) VALUES ('One', 'one@installer-selftest.invalid', 0, 0, 1, NOW()), ('Two', 'two@installer-selftest.invalid', 0, 0, 1, NOW()), ('Three', 'three@installer-selftest.invalid', 0, 0, 0, NOW())");
post($third, '/?step=2', $realLogin($databases['b']) + ['action' => 'db_config']);
$peopled  = req($third, 'GET', '/?step=2.5');
$accounts = static fn (array $answer): string => preg_match('/<p id="install-existing-accounts">(.*?)<\/p>/s', $answer['body'], $m) === 1
    ? trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '(no such part)';
ok('L21 that page never says "no installation was ever finished": it says no finished installation is RECORDED, names the database it is about, and shows how many accounts it holds (none at first; three once three people are in it)',
    str_contains(words($existing), 'no finished installation is recorded in it') === true && str_contains(words($existing), 'ever finished') === false
    && str_contains($accounts($existing), 'This page is about the database ' . $databases['b'] . ' on ' . $dbLogin['host'] . '. It holds no accounts') === true
    && str_contains($accounts($peopled), 'It holds 3 accounts: people who can sign in to a portal. Wiping it removes every one of them') === true
    && str_contains(words($peopled), 'no finished installation is recorded in it') === true, $accounts($existing) . ' | ' . $accounts($peopled));
$wipeUnsure = post($third, '/?step=2.5', ['action' => 'install_action', 'install_action_choice' => 'drop', 'drop_confirm' => 'not what was asked for']);
ok('L07 wiping without typing the text asked for wipes nothing and says so', page($wipeUnsure) === 'existing' && str_starts_with(message($wipeUnsure), 'Nothing was wiped') === true
    && n($databases['b'], "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'tbl%'", 's', [$databases['b']]) > 100, said($wipeUnsure));
// A row and a table that only a wipe removes, to see that the wipe happened; and a file somebody uploaded.
q($databases['b'], "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'selftest.installer.left', 'from before the wipe', '', 0)");
@mkdir($rootB . $sep . '_uploads' . $sep . 'photos', 0700, true);
@file_put_contents($rootB . $sep . '_uploads' . $sep . 'photos' . $sep . 'somebody.txt', 'uploaded before the wipe');
// And two things the wipe page promises to LEAVE: a table whose name does not begin with "tbl" (another program's, or a
// spreadsheet somebody imported), and a snapshot in _backups.
q($databases['b'], 'CREATE TABLE another_programs_notes (id INT PRIMARY KEY, note VARCHAR(80)) ENGINE=InnoDB');
q($databases['b'], "INSERT INTO another_programs_notes VALUES (1, 'not one of the portal\'s tables: a wipe leaves it')");
@mkdir($rootB . $sep . '_backups', 0700, true);
@file_put_contents($rootB . $sep . '_backups' . $sep . 'snapshot-before-the-wipe.sql', '-- a snapshot taken before the wipe');
$wipe  = post($third, '/?step=2.5', ['action' => 'install_action', 'install_action_choice' => 'drop', 'drop_confirm' => '127.0.0.1:' . $ports['main']]);
$built = post($third, '/?step=3', ['action' => 'install_schema']);
ok('L08 "wipe it and start again", with the text typed, wipes: the tables are rebuilt without the old row or the three people, and the uploaded file is gone', $wipe['status'] === 302 && $wipe['location'] === '?step=3' && $built['location'] === '?step=4'
    && setting($databases['b'], 'selftest.installer.left') === '' && n($databases['b'], 'SELECT COUNT(*) AS n FROM tblUsers') === 0 && file_exists($rootB . $sep . '_uploads' . $sep . 'photos') === false
    && is_dir($rootB . $sep . '_uploads') === true, said($wipe) . ' | ' . said($built));
ok('L22 the wipe removed ONLY what its page says: a table whose name does not begin with "tbl" still holds its row, and the snapshot in _backups is still there, byte for byte',
    (string) (q($databases['b'], 'SELECT note FROM another_programs_notes WHERE id = 1')[0]['note'] ?? '(gone)') === 'not one of the portal\'s tables: a wipe leaves it'
    && @file_get_contents($rootB . $sep . '_backups' . $sep . 'snapshot-before-the-wipe.sql') === '-- a snapshot taken before the wipe', names($rootB . $sep . '_backups'));
$made  = post($third, '/?step=4', ['admin_email' => 'second.admin@installer-selftest.invalid'] + $person);
$again = post($third, '/?step=3', ['action' => 'install_schema']);
ok('L09 the first administrator is made; and pressing "build the tables" a second time does NOT wipe again: the administrator is still there', $made['status'] === 302 && $made['location'] === '?step=4.5'
    && $again['location'] === '?step=4' && n($databases['b'], 'SELECT COUNT(*) AS n FROM tblUsers') === 1, said($made) . ' | ' . said($again));

// Lost a second time, now that an administrator exists.
$fourth = jar('fourth');
ok('L10 the session is lost again, after the administrator was made: the installer asks for the code again', page(req($fourth, 'GET', '/?step=4.5')) === 'code');
giveCode($fourth, $rootB);
$login = post($fourth, '/?step=2', $realLogin($databases['b']) + ['action' => 'db_config']);
post($fourth, '/?step=2.5', ['action' => 'install_action', 'install_action_choice' => 'continue']);
$adminPage = req($fourth, 'GET', '/?step=4');
ok('L11 and is let through again, and the administrator page says an administrator is already there and offers to keep it', $login['location'] === '?step=2.5' && page($adminPage) === 'administrator'
    && str_contains($adminPage['body'], 'id="install-administrator-exists"') === true && str_contains($adminPage['body'], 'href="?step=4.5"') === true, said($login) . ' | ' . said($adminPage));
$same = post($fourth, '/?step=4', ['admin_email' => 'second.admin@installer-selftest.invalid'] + $person);
ok('L12 making the same administrator a second time is refused in plain words, with what to do, and changes nothing', page($same) === 'administrator' && str_contains(message($same), 'An account with that e-mail address is already in this database') === true
    && n($databases['b'], 'SELECT COUNT(*) AS n FROM tblUsers') === 1 && n($databases['b'], 'SELECT COUNT(*) AS n FROM tblLocalAccounts') === 1, said($same));
// =============================================================================
// W. A CHOICE BELONGS TO ONE DATABASE
// =============================================================================
head('W. A wipe chosen for one database is never carried out on another');

// Database ONE is this run's third: one table that has one of the portal's names, holding one row. Only a wipe removes it.
// Database TWO is the second installation's, as it stands now: every table built, one administrator, no version recorded,
// and a file somebody uploaded. Every check below must leave TWO exactly as it is here.
$one = $databases['c'];
$two = $databases['b'];
$state['made'][] = $one;
try {
    $server->query('CREATE DATABASE `' . $one . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
} catch (mysqli_sql_exception $e) {
    echo 'FAIL — the third database could not be made: ' . $e->getMessage() . "\n";
    $fail++;
}
q($one, 'CREATE TABLE tblUsers (id INT PRIMARY KEY, note VARCHAR(80)) ENGINE=InnoDB');
q($one, "INSERT INTO tblUsers VALUES (1, 'database ONE: only a wipe removes this row')");
q($two, "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'selftest.installer.two', 'database TWO, as it was', '', 0)");
@mkdir($rootB . $sep . '_uploads' . $sep . 'kept', 0700, true);
$uploaded = $rootB . $sep . '_uploads' . $sep . 'kept' . $sep . 'uploaded-to-two.txt';
@file_put_contents($uploaded, 'a file somebody uploaded to the portal in database TWO');
$confirm   = '127.0.0.1:' . $ports['main'];
$typeIn    = static fn (string $jar, string $database): array => post($jar, '/?step=2', $realLogin($database) + ['action' => 'db_config']);
$chooseWipe = static fn (string $jar): array => post($jar, '/?step=2.5', ['action' => 'install_action', 'install_action_choice' => 'drop', 'drop_confirm' => $confirm]);
$press     = ['action' => 'install_schema', 'step' => '3'];
$oneAsItWas = asItIs($one);
$twoAsItWas = asItIs($two);
$untouched  = static fn (): bool => asItIs($two) === $twoAsItWas && n($two, 'SELECT COUNT(*) AS n FROM tblUsers') === 1 && setting($two, 'selftest.installer.two') === 'database TWO, as it was'
    && @file_get_contents($uploaded) === 'a file somebody uploaded to the portal in database TWO';
echo 'before, database ONE (' . $one . '): ' . inWords($oneAsItWas) . "\n" . 'before, database TWO (' . $two . '): ' . inWords($twoAsItWas) . "\n";

$wiper = jar('wiper');
req($wiper, 'GET', '/');
giveCode($wiper, $rootB);
$toOne      = $typeIn($wiper, $one);
$choiceOne  = req($wiper, 'GET', '/?step=2.5');
$wipeForOne = $chooseWipe($wiper);
$buildOne   = req($wiper, 'GET', '/?step=3');
$warning    = preg_match('/<div class="alert alert-warning" id="install-tables-wipe" role="alert">(.*?)<\/div>/s', $buildOne['body'], $m) === 1
    ? trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '(no warning)';
ok('W01 with "wipe" chosen and confirmed for database ONE, the build page says so in the warning colour, names that database, and no longer says that a table already there is kept; a build page with no wipe held (the first installation\'s) says the opposite',
    $toOne['location'] === '?step=2.5' && page($choiceOne) === 'existing' && $wipeForOne['location'] === '?step=3' && page($buildOne) === 'tables'
    && str_starts_with($warning, 'This will WIPE the database ' . $one . ' on ' . $dbLogin['host'] . ' first.') === true && str_contains($warning, 'It cannot be undone.') === true
    && str_contains(words($buildOne), 'A table that is already there is kept') === false && str_contains(words($buildOne), 'A setting that already has a value keeps it') === false
    && str_contains($tablesPage['body'], 'id="install-tables-wipe"') === false && str_contains(words($tablesPage), 'A table that is already there is kept, with what is in it.') === true
    && str_contains($tablesPage['body'], '<code>' . $databases['a'] . '</code>') === true, said($buildOne) . ' | ' . $warning);

// THE CHECK'S OWN SCENARIO (its evidence 07, W4). Back; database TWO typed; the build step opened by its address, which
// is also where the browser's Back button lands; "Build the tables" pressed, with that page's own token and mark.
req($wiper, 'GET', '/?step=2');
$toTwo     = $typeIn($wiper, $two);
$byAddress = req($wiper, 'GET', '/?step=3');
$pressed   = postFrom($wiper, $byAddress, $press);
ok('W02 the scenario that wiped a database on 13 October: "wipe" confirmed for ONE, Back, database TWO typed, the build step opened by its address, "Build the tables" pressed. TWO is exactly as it was (' . inWords($twoAsItWas)
    . ', its administrator, its uploaded file); nothing was built; the answer is the choice page, for TWO',
    $toTwo['location'] === '?step=2.5' && page($byAddress) === 'existing' && str_contains($byAddress['body'], 'id="install-tables-wipe"') === false && page($pressed) === 'existing'
    && str_contains(message($pressed), 'no choice has been made for it yet') === true && str_contains($pressed['body'], '<code>' . $two . '</code>') === true && $untouched() === true && asItIs($one) === $oneAsItWas,
    said($byAddress) . ' | ' . said($pressed) . ' | TWO now: ' . inWords(asItIs($two)));
echo 'after W02, database TWO: ' . inWords(asItIs($two)) . "\n";

// THE BROWSER'S BACK BUTTON. The build page that was drawn for ONE ("This will WIPE…") is still on the screen, and its
// button is pressed: with the mark that page carries, with no mark at all, and with today's token and that old mark.
$stale  = postFrom($wiper, $buildOne, $press);
$bare   = req($wiper, 'POST', '/', $press + ['install_token' => token($byAddress)]);
$forged = req($wiper, 'POST', '/', $press + ['install_token' => token($byAddress), 'install_for' => mark($buildOne)]);
ok('W03 the browser\'s Back button: the "Build the tables" page drawn for ONE is still on the screen and is pressed after TWO was typed (with its own mark, with none, and with ONE\'s mark on today\'s form). Each is refused as a page drawn for another database; TWO is as it was',
    mark($buildOne) !== '' && mark($buildOne) !== mark($byAddress) && page($stale) === 'existing' && str_contains(message($stale), 'drawn for a different database') === true
    && str_contains(message($bare), 'drawn for a different database') === true && str_contains(message($forged), 'drawn for a different database') === true && $untouched() === true && asItIs($one) === $oneAsItWas,
    said($stale) . ' | ' . said($bare) . ' | ' . said($forged));
echo 'after W03, database TWO: ' . inWords(asItIs($two)) . "\n";

// THE CHOICE PAGE SKIPPED BY ADDRESS, by somebody who never chose anything: a new session, the code, database TWO.
$skipper = jar('skipper');
req($skipper, 'GET', '/');
giveCode($skipper, $rootB);
$typeIn($skipper, $two);
$skipped = req($skipper, 'GET', '/?step=3');
$pressed = postFrom($skipper, $skipped, $press);
ok('W04 the choice page skipped by address, by somebody who chose nothing at all: the build step shows the choice page and builds nothing, where it used to build straight into the database',
    page($skipped) === 'existing' && page($pressed) === 'existing' && $pressed['status'] === 200 && str_contains(message($pressed), 'no choice has been made for it yet') === true && $untouched() === true,
    said($skipped) . ' | ' . said($pressed));
echo 'after W04, database TWO: ' . inWords(asItIs($two)) . "\n";

// THE SAME DATABASE TYPED AGAIN. The wipe is confirmed for ONE; then ONE's own details are typed once more. That is
// asking the question again, so the earlier answer is gone.
$typeIn($wiper, $one);
$chooseWipe($wiper);
$held    = req($wiper, 'GET', '/?step=3');
$again   = $typeIn($wiper, $one);
$skipped = req($wiper, 'GET', '/?step=3');
$pressed = postFrom($wiper, $skipped, $press);
ok('W05 the same database typed again: the wipe confirmed for it a moment ago is forgotten, the build step asks again, and ONE still holds its row',
    str_contains($held['body'], 'id="install-tables-wipe"') === true && $again['location'] === '?step=2.5' && page($skipped) === 'existing' && page($pressed) === 'existing'
    && asItIs($one) === $oneAsItWas && n($one, 'SELECT COUNT(*) AS n FROM tblUsers') === 1, said($skipped) . ' | ' . said($pressed) . ' | ONE now: ' . inWords(asItIs($one)));
echo 'after W05, database ONE: ' . inWords(asItIs($one)) . "\n";

// TWO TABS, the choice page. One tab shows the choice page for ONE. In another tab (the same session) TWO is typed.
// Back in the first tab, "wipe" is ticked and the confirming text typed: the page it is on describes ONE.
$tabOne    = req($wiper, 'GET', '/?step=2.5');
$typeIn($wiper, $two);
$confirmed = postFrom($wiper, $tabOne, ['action' => 'install_action', 'install_action_choice' => 'drop', 'drop_confirm' => $confirm]);
$then      = req($wiper, 'GET', '/?step=3');
$pressed   = postFrom($wiper, $then, $press);
ok('W06 two tabs: the choice page for ONE is open in one tab, TWO is typed in another, then "wipe" is confirmed in the first. No wipe is recorded for TWO: the answer says the page was drawn for another database and shows the choice page for TWO; the build step still asks; TWO is as it was',
    str_contains($tabOne['body'], '<code>' . $one . '</code>') === true && page($confirmed) === 'existing' && $confirmed['status'] === 200 && str_contains(message($confirmed), 'drawn for a different database') === true
    && str_contains($confirmed['body'], '<code>' . $two . '</code>') === true && page($then) === 'existing' && page($pressed) === 'existing' && $untouched() === true, said($confirmed) . ' | ' . said($then) . ' | ' . said($pressed));
echo 'after W06, database TWO: ' . inWords(asItIs($two)) . "\n";

// TWO TABS, the build page. One tab is on the build page for ONE, wipe held. In the other, TWO is typed and "carry on"
// is chosen for it. The first tab's button ("This will WIPE…") is then pressed.
$typeIn($wiper, $one);
$chooseWipe($wiper);
$tabOne  = req($wiper, 'GET', '/?step=3');
$typeIn($wiper, $two);
post($wiper, '/?step=2.5', ['action' => 'install_action', 'install_action_choice' => 'continue']);
$pressed = postFrom($wiper, $tabOne, $press);
$tabTwo  = req($wiper, 'GET', '/?step=3');
ok('W07 two tabs, the other way round: the build page for ONE (wipe held) is open in one tab; in another, TWO is typed and "carry on" chosen; the first tab\'s button is pressed. Refused as a page for another database, nothing wiped, nothing built; and the build page for TWO names TWO and says nothing of a wipe',
    str_contains($tabOne['body'], 'id="install-tables-wipe"') === true && str_contains(message($pressed), 'drawn for a different database') === true && $pressed['status'] === 200 && $untouched() === true
    && page($tabTwo) === 'tables' && str_contains($tabTwo['body'], 'id="install-tables-wipe"') === false && str_contains($tabTwo['body'], '<code>' . $two . '</code>') === true
    && str_contains(words($tabTwo), 'A table that is already there is kept, with what is in it.') === true && asItIs($one) === $oneAsItWas, said($pressed) . ' | ' . said($tabTwo));
echo 'after W07, database TWO: ' . inWords(asItIs($two)) . "\n";

// And "carry on" really does build, and removes nothing. (The one build in this part: everything above was refused.)
$carried = post($wiper, '/?step=3', $press);
ok('W08 "carry on with what is there", chosen for TWO, builds over it and removes nothing: the same tables, the administrator, the row from before, the other program\'s table and the uploaded file are all still there; and ONE was never wiped by anything above',
    $carried['status'] === 302 && $carried['location'] === '?step=4' && asItIs($two)['tables'] === $twoAsItWas['tables'] && n($two, 'SELECT COUNT(*) AS n FROM tblUsers') === 1
    && setting($two, 'selftest.installer.two') === 'database TWO, as it was' && n($two, 'SELECT COUNT(*) AS n FROM another_programs_notes') === 1
    && @file_get_contents($uploaded) === 'a file somebody uploaded to the portal in database TWO' && asItIs($one) === $oneAsItWas, said($carried) . ' | TWO now: ' . inWords(asItIs($two)));
echo 'after W08 (the one real build), database TWO: ' . inWords(asItIs($two)) . '; database ONE: ' . inWords(asItIs($one)) . "\n";

// THE SAME NAME ON ANOTHER SERVER. Needs a second server; when none was named this check is not run (the last line says so).
// It comes last, after the one real build: by now TWO has been built over, so it is compared with how it stands here.
if ($otherServer !== null) {
    $state['madeOther'][] = $one;
    try {
        $otherServer->query('CREATE DATABASE `' . $one . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $otherServer->select_db($one);
        $otherServer->query('CREATE TABLE tblUsers (id INT PRIMARY KEY, note VARCHAR(80)) ENGINE=InnoDB');
        $otherServer->query("INSERT INTO tblUsers VALUES (1, 'the same name on ANOTHER server: nobody chose to wipe this')");
    } catch (mysqli_sql_exception $e) {
        echo 'the database on the second server could not be made: ' . $e->getMessage() . "\n";
    }
    $otherAsItWas = asItIs($one, $otherServer);
    $twoBuiltOver = asItIs($two);
    $typeIn($wiper, $one);
    $chooseWipe($wiper);
    $tabOne    = req($wiper, 'GET', '/?step=3');
    $toOther   = post($wiper, '/?step=2', ['db_host' => $otherLogin['host'], 'db_port' => (string) $otherLogin['port']] + $realLogin($one) + ['action' => 'db_config']);
    $byAddress = req($wiper, 'GET', '/?step=3');
    $pressed   = postFrom($wiper, $byAddress, $press);
    $stale     = postFrom($wiper, $tabOne, $press);
    ok('W09 the same database name on another server (' . $otherLogin['host'] . ':' . $otherLogin['port'] . '): "wipe" confirmed for it on the first server, then the same name typed for the second, the build step opened by its address and pressed, and the first server\'s build page pressed as well. '
        . 'The database on the second server is exactly as it was (' . inWords($otherAsItWas) . '), and so is the one on the first',
        $otherAsItWas['tables'] === 1 && str_contains($tabOne['body'], 'id="install-tables-wipe"') === true && $toOther['location'] === '?step=2.5' && page($byAddress) === 'existing' && page($pressed) === 'existing'
        && str_contains(message($stale), 'drawn for a different database') === true && asItIs($one, $otherServer) === $otherAsItWas && asItIs($one) === $oneAsItWas && asItIs($two) === $twoBuiltOver,
        said($toOther) . ' | ' . said($byAddress) . ' | ' . said($pressed) . ' | ' . said($stale));
    echo 'after W09, the database on the second server: ' . inWords(asItIs($one, $otherServer)) . "\n";
}

// This database "already holds" an address for the portal, as one being carried on with might: it must be kept.
q($databases['b'], "UPDATE tblSettings SET settingValue = 'https://kept.installer-selftest.invalid' WHERE settingKey = 'site.url' AND siteID IS NULL");
$typed = post($fourth, '/?step=4.5', ['action' => 'web_addresses', 'portal_address' => 'portal-b.installer-selftest.invalid', 'support_email' => '']);
ok('L13 a bare host name is taken to mean https, and the support address may be left empty', $typed['status'] === 302 && $typed['location'] === '?step=5'
    && str_contains(req($fourth, 'GET', '/?step=5')['body'], '<code>https://portal-b.installer-selftest.invalid</code>') === true, said($typed));

// A key file that was already there: any length is a working key, and secrets may already be stored with it.
$olderKey = 'an older key file of some other length, written before this installer ran';
@file_put_contents($authB . $sep . 'enc.key', $olderKey);
// Two rules files that were there before the finish. In _uploads, one that holds a comment and NO rule (an upload cut
// short): it refuses nothing and must be written again. In _backups, one of somebody's own that holds a rule, but not
// the refusal: it must be left exactly as it is, and the last page must not claim that folder refuses anything.
$ownRules = "# Somebody's own rules for this folder. They do not refuse anything.\nOptions -Indexes\n";
@file_put_contents($rootB . $sep . '_uploads' . $sep . '.htaccess', "# Refuse every request for anything in this folder.\n");
@file_put_contents($rootB . $sep . '_backups' . $sep . '.htaccess', $ownRules);
// First a finish that stops at its last write, as in the first installation: the database login cannot be written.
chmod($authB, 0500);
$stopped = post($fourth, '/?step=5', ['action' => 'finalize', 'owner_code' => installCodeAsShown(codeOf($rootB))]);
chmod($authB, 0750);
ok('L23 a finish that stops at its last write on a database that ALREADY held an address: it says so, and the address that was there is still there (this run did not fill it in, so this run may not replace it)',
    page($stopped) === 'finish' && str_contains(message($stopped), 'the file that holds the database login could not be written') === true
    && setting($databases['b'], 'site.url') === 'https://kept.installer-selftest.invalid' && is_file($authB . $sep . 'auth_creds.php') === false, said($stopped) . '; ' . setting($databases['b'], 'site.url'));
$done = post($fourth, '/?step=5', ['action' => 'finalize', 'owner_code' => installCodeAsShown(codeOf($rootB))]);
ok('L14 the installation finishes, and a key file that was already there is KEPT, to the byte, and the last page says so', page($done) === 'done' && @file_get_contents($authB . $sep . 'enc.key') === $olderKey
    && str_contains(words($done), 'a key file was already there, and it was kept') === true, said($done));
$doneChannel = preg_match('/<li class="install-check" data-done="channel" data-channel-source="([a-z]+)">(.*?)<\/li>/s', $done['body'], $m) === 1
    ? [$m[1], trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')))] : ['', '(no such row)'];
ok('L15 with the file, the last page says the word and who said it: a beta copy, the file .channel; it does not say assumed and does not ask for a file to be created',
    $doneChannel[0] === 'file' && str_contains($doneChannel[1], 'This is a beta copy (a test copy, not the live portal). The file .channel, beside the _core folder, says so.') === true
    && stripos($doneChannel[1], 'assumed') === false && str_contains($doneChannel[1], 'create a plain text file') === false, json_encode($doneChannel));
ok('L16 an address the database ALREADY held is kept, not replaced by the one typed, and the last page says which is in use and that the typed one was not',
    setting($databases['b'], 'site.url') === 'https://kept.installer-selftest.invalid' && str_contains(words($done), 'will begin with https://kept.installer-selftest.invalid.') === true
    && str_contains(words($done), 'That is not the address you typed (https://portal-b.installer-selftest.invalid). This database already had an address saved, and it was kept.') === true, setting($databases['b'], 'site.url'));
ok('L17 still one address row; no support address; no code file; the refuse file in _auth_keys', count(q($databases['b'], "SELECT 1 FROM tblSettings WHERE settingKey = 'site.url'")) === 1
    && setting($databases['b'], 'portal.support.email') === '' && names($authB) === '.htaccess, .installed, auth_creds.php, enc.key'
    && @file_get_contents($authB . $sep . '.htaccess') === $shippedRefuse, setting($databases['b'], 'site.url') . ' ; ' . names($authB));
$private = preg_match('/<div class="alert [a-z-]+" id="install-done-private">(.*?)<\/div>/s', $done['body'], $m) === 1
    ? trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '(no such part)';
ok('L24 a rules file that held a comment and no rule (_uploads) was written again by the finish; one of somebody\'s own that holds a rule (_backups) was left exactly as it is; and the last page says which two folders refuse and that _backups does NOT hold the refusal',
    @file_get_contents($rootB . $sep . '_uploads' . $sep . '.htaccess') === $shippedRefuse && @file_get_contents($rootB . $sep . '_backups' . $sep . '.htaccess') === $ownRules
    && str_contains($private, '_auth_keys and _uploads each hold a file that tells the web server to refuse every request for anything inside.') === true
    && str_contains($private, '_backups holds a rules file (.htaccess) of its own, which the installer left as it is. It does not hold the line Require all denied') === true
    && str_contains($done['body'], 'class="alert alert-warning" id="install-done-private"') === true, $private);

// CONTROL for L05: the same wizard, pointed at a database that holds a FINISHED portal, still asks who is signed in.
gone($authB . $sep . 'auth_creds.php');
gone($authB . $sep . '.installed');
$fifth = jar('fifth');
req($fifth, 'GET', '/');
giveCode($fifth, $rootB);
$login = post($fifth, '/?step=2', $realLogin($databases['b']) + ['action' => 'db_config']);
ok('L18 CONTROL: a database that holds a FINISHED portal still demands a signed-in global administrator, code or no code, and keeps nothing of the login it was given',
    page($login) === 'database' && str_contains(message($login), 'There is already a portal installed on this database') === true && page(req($fifth, 'GET', '/?step=3')) === 'database', said($login));
ok('L19 the second installation raised no PHP warning or notice either', errorLog() === '', errorLog());

// Every link and every form on every page of the installer that was drawn above goes somewhere real.
$strays = [];
foreach (['code' => $first, 'checks' => $checks, 'organisation' => req($fifth, 'GET', '/?step=1.5'), 'database' => $login, 'existing' => $existing, 'tables' => $tablesPage,
    'administrator' => $adminPage, 'addresses' => $addressPage, 'finish' => $finish, 'done' => $done] as $name => $answer) {
    if (page($answer) !== $name) {
        $strays[] = $name . ' is page "' . page($answer) . '"';
    }
    if ($keepPages !== '') {
        // Each page as it was answered, for somebody to open in a browser. The codes on them are of databases that no longer exist.
        file_put_contents($keepPages . $sep . 'installer-page-' . $name . '.html', $answer['body']);
    }
    preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/', $answer['body'], $links);
    foreach ($links[1] as $href) {
        if (preg_match('/^(\?step=(1|1\.5|2|3|4|4\.5)|\/)$/', $href) !== 1) {
            $strays[] = $name . ': a link to "' . $href . '"';
        }
    }
    preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/s', $answer['body'], $formsFound, PREG_SET_ORDER);
    foreach ($formsFound as $form) {
        if (str_contains($form[1], 'method="post"') === false || str_contains($form[1], 'action=') === true || str_contains($form[2], 'name="install_token"') === false
            || preg_match('/name="action" value="(owner_code|select_industry|db_config|install_action|install_schema|create_admin|web_addresses|finalize)"/', $form[2]) !== 1
            || preg_match('/<button type="submit"/', $form[2]) !== 1) {
            $strays[] = $name . ': a form that is not one of the installer\'s own';
        }
    }
    if (substr_count($answer['body'], '<h1') !== 1) {
        $strays[] = $name . ': ' . substr_count($answer['body'], '<h1') . ' top-level headings';
    }
}
ok('L20 on all ten of the installer\'s pages: one top-level heading; every link goes to a step or to the portal; every form posts to the installer, carries the token, names a real action and has a button',
    $strays === [], implode(' | ', array_slice($strays, 0, 5)));

// =============================================================================
// Done
// =============================================================================
cleanup();
$otherGone = true;
if ($otherServer !== null) {
    try {
        $otherGone = $otherServer->query("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $databases['c'] . "'")->num_rows === 0;
    } catch (mysqli_sql_exception) {
        $otherGone = false;
    }
}
ok('Z01 the databases this run made are gone again (three, and the one on the second server when there was one), and so is its scratch folder',
    databaseExists($databases['a']) === false && databaseExists($databases['b']) === false && databaseExists($databases['c']) === false && $otherGone === true && file_exists($child) === false);

$total  = $pass + $fail;
$notRun = $otherServer === null ? ' One check was NOT run (W09, the same database name on another server): no second server was named with SELFTEST_DB_PORT_2.' : '';
echo "\n" . str_repeat('=', 78) . "\n";
if ($total !== $expectedChecks) {
    echo "FAIL — {$total} checks ran, but a full run makes {$expectedChecks}. A block was skipped or ran twice; the run reached its end.\n";
    exit(1);
}
if ($fail > 0) {
    echo "FAIL — {$fail} of {$total} checks failed.{$notRun} The run reached its end.\n";
    exit(1);
}
echo "PASS — all {$total} checks passed.{$notRun} The run reached its end.\n";
exit(0);
