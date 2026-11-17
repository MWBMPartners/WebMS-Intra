<?php
// Path: tools/error-words-selftest.php
/**
 * -----------------------------------------------------------------------------
 * An error's own words keep no e-mail address, the fallback line keeps only
 * numbers, and an anonymous form's error line names nobody — self-test 🧹
 * (#1007, #990, #1044; the owner's decision D-174)
 * -----------------------------------------------------------------------------
 * WHAT THIS HOLDS IN PLACE
 * ------------------------
 * A mail server's refusal, a provider's error and a database's "Duplicate
 * entry '…'" quote what they were given, very often somebody's e-mail address.
 * An Errors-page row names no person, so no erasure can find it (#1007), and
 * the owner chose to take addresses out as the words are written (D-174).
 * And when the database cannot take a row, Logger writes one line to PHP's own
 * log instead - which the hosting company keeps where no erasure reaches - so
 * that line now carries the kind of record and its numbers only (#990).
 *
 * WHAT IT DOES
 * ------------
 * It loads the REAL `Portal\Core\Logger` (and what it uses) against a real
 * MySQL database built from `web/_sql/full_schema.sql`, calls it as the portal
 * does, and reads back what was stored:
 *   A  `Logger::withoutAddresses()` on the shapes a refusal really takes, and
 *      the stated limits it cannot see (asserted as limits, so a change to
 *      them is noticed rather than assumed).
 *   B  `errorPlatform()`: an address in the code, title and detail is
 *      "[address]" in the stored row; the row still keeps the network address
 *      and browser (it is NOT a naming-nobody row).
 *   C  `errorNamingNobody()` (#1044): the row has no account, network
 *      address, browser or headers, and keeps the page address and words.
 *   D  The fallback line, with the connection closed: an activity record's
 *      line carries the type and numbers and NOT its words or the network
 *      address; an error's carries the title (address taken out) and where it
 *      happened, and NOT the detail's trace, the address or the page address;
 *      an audit record's carries no network address.
 *   E  The fallback line's REASON - the database's own words, here made to
 *      quote an address by a trigger - has the address taken out.
 *   F  The pattern is linear: in a child PHP with the pattern JIT switched off
 *      (pcre.jit=0), 1 MB of "a.a.a…" is cleaned in under half a second (the
 *      first version took about 156 s there).
 *   H  (#1096) recordableAddress(): an e-mail address typed into a search box or
 *      written into a path is "[address]" in the stored page address and the
 *      headers, the page itself stays named. Before G, which silences headers.
 *   G  After `Logger::promiseAnonymity()` (#1044 round 2), an ordinary
 *      errorPlatform() line and an ordinary activity() record both name
 *      nobody, though the session names a member. Last, because the promise
 *      cannot be taken back within a request.
 *
 * The anonymous prayer form's own path (a bot check that cannot reach its
 * provider) is driven over HTTP by section N of
 * `tools/erasure-pages-selftest.php` (N55-N58); this file proves the logger.
 *
 * WHAT IT CANNOT PROVE: the alert e-mail and the outside error monitor are not
 * sent (no recipients, no monitor configured); that both receive the cleaned
 * words is by reading writeErrorRow(), which cleans them before either is
 * called. Whether any OTHER personal detail in an error's words (a name, a
 * number) is kept: D-174 is about e-mail addresses, and nothing here judges
 * the rest.
 *
 * Needs: SELFTEST_DB_HOST, SELFTEST_DB_PORT, SELFTEST_DB_USER, SELFTEST_DB_PASS
 * and SELFTEST_DB_NAME naming a database built from full_schema.sql. Rows it
 * writes are deleted again at the end.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1007
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$core = dirname(__DIR__) . $sep . 'web' . $sep . '_core';
spl_autoload_register(static function (string $class) use ($core, $sep): void {
    if (str_starts_with($class, 'Portal\\Core\\') === true) {
        $file = $core . $sep . substr($class, strlen('Portal\\Core\\')) . '.php';
        if (is_file($file) === true) {
            require $file;
        }
    }
});
if (defined('PORTAL_ROOT') === false) {
    define('PORTAL_ROOT', dirname(__DIR__) . $sep . 'web');
}

use Portal\Core\Logger;

$passes   = 0;
$failures = 0;
function ew_check(string $label, bool $good, string $detail = ''): void
{
    global $passes, $failures;
    if ($good === true) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label}\n" . ($detail !== '' ? "        {$detail}\n" : '');
    }
}

$name = getenv('SELFTEST_DB_NAME');
if ($name === false || $name === '') {
    fwrite(STDERR, "REFUSED: SELFTEST_DB_NAME is not set (a database built from web/_sql/full_schema.sql).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connect = static fn (): mysqli => new mysqli(
    (string) (getenv('SELFTEST_DB_HOST') ?: '127.0.0.1'),
    (string) (getenv('SELFTEST_DB_USER') ?: 'root'),
    (string) (getenv('SELFTEST_DB_PASS') ?: ''),
    $name,
    (int) (getenv('SELFTEST_DB_PORT') ?: 3306)
);
$db   = $connect();
$mark = (int) $db->query('SELECT COALESCE(MAX(`errorID`), 0) FROM `tblErrors`')->fetch_row()[0];
Logger::useConnection($db);

// A request as the portal would see it: a visitor's address and browser.
$_SERVER['REMOTE_ADDR']     = '203.0.113.77';
$_SERVER['HTTP_USER_AGENT'] = 'P1044TestBrowser/1.0';
$_SERVER['REQUEST_URI']     = '/prayer-requests/anonymous-save';
$_SERVER['HTTP_HOST']       = 'portal.example.invalid';

/** The newest error row written since this run began. */
$lastError = static function () use ($db, $mark): ?array {
    $row = $db->query('SELECT * FROM `tblErrors` WHERE `errorID` > ' . $mark . ' ORDER BY `errorID` DESC LIMIT 1')->fetch_assoc();

    return is_array($row) === true ? $row : null;
};

echo "A  Logger::withoutAddresses()\n";
$cases = [
    '550 5.1.1 <jane.doe@example.org>: Recipient address rejected' => '550 5.1.1 <[address]>: Recipient address rejected',
    "Duplicate entry 'bob+tag@mail.example.co.uk' for key 'username'" => "Duplicate entry '[address]' for key 'username'",
    'two: a@b.io, c.d@e.f.g'                                         => 'two: [address], [address]',
    "zo\u{eb}@ex\u{e4}mple.de refused"                               => '[address] refused',
    "bad bytes \xff\xfe then x@y.org"                                => "bad bytes \xff\xfe then [address]",
    'nothing personal here'                                          => 'nothing personal here',
];
foreach ($cases as $in => $want) {
    $got = Logger::withoutAddresses($in);
    ew_check('A an address is taken out of "' . substr(preg_replace('/[^\x20-\x7e]/', '?', $in) ?? '', 0, 50) . '"', $got === $want, 'got: ' . $got);
}
// The stated limit, asserted so a change is seen: no dot after the @.
ew_check('A the stated limit: "root@localhost" (no dot after the @) is NOT recognised', Logger::withoutAddresses('to root@localhost') === 'to root@localhost');

echo "B  errorPlatform(): the words are cleaned, the row is otherwise ordinary\n";
Logger::errorPlatform('Mail', 'Warning', 'REFUSED <pat@example.net>', 'Refused jane.doe@example.org', "Detail line one for sam@example.com\nline two");
$row = $lastError();
$all = (string) ($row['errorCode'] ?? '') . (string) ($row['errorTitle'] ?? '') . (string) ($row['errorDetail'] ?? '');
ew_check('B the row was written', $row !== null && (string) $row['errorPlatform'] === 'Mail');
ew_check('B no e-mail address in the stored code, title or detail', $row !== null && str_contains($all, '@') === false, $all);
ew_check('B each place an address stood now reads "[address]"', $row !== null && (string) $row['errorTitle'] === 'Refused [address]'
    && (string) $row['errorCode'] === 'REFUSED <[address]>' && str_starts_with((string) $row['errorDetail'], 'Detail line one for [address]'), $all);
ew_check('B an ordinary error row still keeps the network address and browser', $row !== null
    && (string) $row['visitorIP'] === '203.0.113.77' && (string) $row['userAgent'] === 'P1044TestBrowser/1.0' && $row['requestHeaders'] !== null,
    json_encode($row) ?: '');

echo "C  errorNamingNobody(): nothing of the sender (#1044)\n";
$_SESSION = ['user_id' => 42];
Logger::errorNamingNobody('Captcha', 'Warning', 'JSON_FAIL', 'siteverify response was not valid JSON', 'answer quoted x@example.org');
$row = $lastError();
ew_check('C the row was written', $row !== null && (string) $row['errorCode'] === 'JSON_FAIL');
foreach (['userID', 'visitorIP', 'userAgent', 'requestHeaders'] as $col) {
    ew_check('C the row holds no ' . $col, $row !== null && $row[$col] === null, (string) ($row[$col] ?? ''));
}
ew_check('C it keeps the page address and the words, address taken out', $row !== null
    && (string) $row['requestURL'] !== '' && (string) $row['errorDetail'] === 'answer quoted [address]', json_encode($row) ?: '');
unset($_SESSION);

echo "D  the fallback line in PHP's own log (#990)\n";
$logFile = tempnam(sys_get_temp_dir(), 'p1044-log-');
ini_set('error_log', (string) $logFile);
$dead = $connect();
$dead->close();
Logger::useConnection($dead);
Logger::activity('P1044Fallback', 'Typed Sign-in Name and tom@example.org', 77);
Logger::errorPlatform('PHP', 'Error', '8', 'Undefined thing near ann@example.org', "/srv/portal/page.php:12\n#0 trace mentioning secret-value-99");
Logger::audit('tblUsers', 5, 'update', ['emailAddress' => 'old@example.org'], ['emailAddress' => 'new@example.org'], 77);
Logger::useConnection($db);
$lines  = array_values(array_filter(explode("\n", (string) file_get_contents((string) $logFile)), static fn (string $l): bool => str_contains($l, 'Logger could not record')));
$byKind = ['activity' => '', 'error' => '', 'audit' => ''];
foreach ($lines as $line) {
    if (str_contains($line, 'record an activity record')) {
        $byKind['activity'] = $line;
    } elseif (str_contains($line, 'record an error ')) {
        $byKind['error'] = $line;
    } elseif (str_contains($line, 'record an audit trail record')) {
        $byKind['audit'] = $line;
    }
}
ew_check('D premise: one fallback line for each of the three records', count($lines) === 3 && in_array('', $byKind, true) === false, implode(' || ', $lines));
ew_check('D the activity line carries its type and numbers', str_contains($byKind['activity'], 'type=P1044Fallback') && str_contains($byKind['activity'], 'user=77'), $byKind['activity']);
ew_check('D the activity line carries NOT its words', str_contains($byKind['activity'], 'Typed Sign-in Name') === false && str_contains($byKind['activity'], '@') === false, $byKind['activity']);
ew_check('D the error line says what went wrong and where, the address taken out',
    str_contains($byKind['error'], 'title=Undefined thing near [address]') && str_contains($byKind['error'], 'where=/srv/portal/page.php:12'), $byKind['error']);
ew_check('D the error line carries NOT the rest of the trace, nor the page address', str_contains($byKind['error'], 'secret-value-99') === false
    && str_contains($byKind['error'], 'anonymous-save') === false && str_contains($byKind['error'], '@') === false, $byKind['error']);
foreach ($byKind as $kind => $line) {
    ew_check('D the ' . $kind . ' line carries no network address', str_contains($line, '203.0.113.77') === false, $line);
}
@unlink((string) $logFile);

echo "E  the fallback line's REASON (the database's own words) loses its addresses too\n";
// A database refusal that QUOTES the value it refused, made with a trigger on
// the activity log, so the reason Logger receives holds an address.
$logFile = tempnam(sys_get_temp_dir(), 'p1044-log-');
ini_set('error_log', (string) $logFile);
$db->query('DROP TRIGGER IF EXISTS `p1044_refuse_activity`');
$db->query("CREATE TRIGGER `p1044_refuse_activity` BEFORE INSERT ON `tblActivityLogs` FOR EACH ROW "
    . "BEGIN IF NEW.`activityType` = 'P1044Reason' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused the value rae@example.org'; END IF; END");
Logger::activity('P1044Reason', 'words', 77);
$db->query('DROP TRIGGER IF EXISTS `p1044_refuse_activity`');
$line = (string) file_get_contents((string) $logFile);
ew_check('E premise: the database refused the record, quoting a value with an address in it', str_contains($line, 'refused the value'), $line);
ew_check('E the fallback line\'s reason has the address taken out', str_contains($line, 'refused the value [address]') && str_contains($line, '@') === false, $line);
@unlink((string) $logFile);

echo "F  a long run of address-like characters is cleaned in linear time, with the pattern JIT OFF\n";
// In a child PHP with pcre.jit=0 (some hosts set it): the first version of the
// pattern took about 1 s on 80 KB and 156 s on 1 MB there (measured 16
// November 2026); the bound below is far above linear and far below that.
$child = PHP_BINARY . ' -d pcre.jit=0 -r ' . escapeshellarg(
    'require ' . var_export($core . $sep . 'Logger.php', true) . ';'
    . '$w = str_repeat("a.", 500000); $t = microtime(true); $o = \\Portal\\Core\\Logger::withoutAddresses($w . " x@y.org");'
    . 'echo ini_get("pcre.jit"), "|", round(microtime(true) - $t, 3), "|", substr($o, -10);'
);
$said  = (string) shell_exec($child);
$parts = explode('|', trim($said));
ew_check('F with the JIT off, 1 MB of "a.a.a…" and an address is cleaned in under half a second, and the address still goes',
    count($parts) === 3 && $parts[0] === '0' && (float) $parts[1] < 0.5 && $parts[2] === ' [address]', $said);

echo "H  a request's address is cleaned before it is stored or sent (#1096)\n";
$ra = static fn (string $a): string => Logger::recordableAddress($a);
$addrCases = [
    'an address typed into a search'              => ['/admin/users?q=bob@example.com', '/admin/users?q=[address]'],
    'the same address, percent-encoded'           => ['/admin/users?q=bob%40example.com', '/admin/users?q=[address]'],
    'a name typed there is left byte-identical'   => ['/admin/users?q=Bob+Smith&page=2', '/admin/users?q=Bob+Smith&page=2'],
    'a job token and an address, both cut'        => ['/x?token=abc&q=a@b.co', '/x?token=[removed]&q=[address]'],
    'an address as a parameter NAME'              => ['/x?a%40b.co=1', '/x?[address]=1'],
    'an address in an array parameter'            => ['/x?q[]=a@b.co', '/x?q[]=[address]'],
    'an address as a path part'                   => ['/unsubscribe/a@b.co/x', '/unsubscribe/[address]/x'],
    'no query at all'                             => ['/admin/users', '/admin/users'],
    'an empty value'                              => ['/x?q=&r=1', '/x?q=&r=1'],
    'malformed percent text is left as it came'   => ['/x?q=%zz&r=1', '/x?q=%zz&r=1'],
    'a fragment is kept and does not break it'    => ['/x?q=a@b.co#top', '/x?q=[address]#top'],
    'empty'                                       => ['', ''],
];
foreach ($addrCases as $label => [$in, $want]) {
    $got = $ra($in);
    ew_check('H ' . $label, $got === $want, 'got: ' . $got);
}
// The stated limit, asserted so a change is seen: an address encoded TWICE is read once.
ew_check('H the stated limit: a double-encoded address (%2540) is NOT recognised', $ra('/x?q=a%2540b.co') === '/x?q=a%2540b.co');

// Linear time, with the pattern JIT off (as part F).
$child = PHP_BINARY . ' -d pcre.jit=0 -r ' . escapeshellarg(
    'require ' . var_export($core . $sep . 'Logger.php', true) . ';'
    . '$v = str_repeat("a.", 100000); $t = microtime(true); $o = \\Portal\\Core\\Logger::recordableAddress("/x?q=" . $v . "&r=y@z.org");'
    . 'echo round(microtime(true) - $t, 3), "|", substr($o, -12);'
);
$said  = explode('|', trim((string) shell_exec($child)));
ew_check('H a 200 KB value is cleaned in under half a second with the JIT off, and a later address still goes',
    count($said) === 2 && (float) $said[0] < 0.5 && $said[1] === '&r=[address]', implode('|', $said));

// The real writer: a fault on a search page, reached from a search page.
$_SERVER['REQUEST_URI']  = '/admin/users?q=bob@example.com&page=2';
$_SERVER['HTTP_REFERER'] = 'https://portal.example.invalid/admin/users?q=ann@example.com';
$_SERVER['HTTP_X_PROBE'] = 'quoting zed@example.com';
Logger::errorPlatform('PHP', 'Error', '8', 'a fault on a search page', 'detail');
$row  = $lastError();
$hold = $row === null ? '' : (string) $row['requestURL'] . ' ' . (string) $row['requestHeaders'];
ew_check('H the stored row holds no address anywhere in its page address or headers', $row !== null && str_contains($hold, '@') === false && str_contains($hold, 'example.com') === false, $hold);
ew_check('H the stored page address still names the page and the other parameter', $row !== null && (string) $row['requestURL'] === '/admin/users?q=[address]&page=2', (string) ($row['requestURL'] ?? ''));
$hdrs = $row === null ? [] : (array) json_decode((string) $row['requestHeaders'], true);
$ref  = '';
foreach ($hdrs as $hn => $hv) {
    if (strtolower((string) $hn) === 'referer') {
        $ref = (string) $hv;
    }
}
ew_check('H the Referer keeps its host and path', $ref === 'https://portal.example.invalid/admin/users', $ref);
unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_X_PROBE']);
$_SERVER['REQUEST_URI'] = '/prayer-requests/anonymous-save';

// ErrorMonitor: nothing here can be sent to a service, so pin by PHP's own
// tokeniser (presence only, D-065) that currentUrl() asks recordableAddress().
$tok      = token_get_all((string) file_get_contents($core . $sep . 'ErrorMonitor.php'));
$inFn     = false;
$seenCall = false;
$depth    = 0;
$n        = count($tok);
for ($i = 0; $i < $n; $i++) {
    $t = $tok[$i];
    if ($inFn === false && is_array($t) === true && $t[0] === T_FUNCTION) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tok[$j]) === true && $tok[$j][0] === T_STRING) {
                $inFn = $tok[$j][1] === 'currentUrl';
                break;
            }
        }
        continue;
    }
    if ($inFn === true) {
        if ($t === '{') {
            $depth++;
        }
        if ($t === '}') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
        if (is_array($t) === true && $t[0] === T_STRING && $t[1] === 'recordableAddress') {
            $seenCall = true;
        }
    }
}
ew_check('H ErrorMonitor::currentUrl() calls Logger::recordableAddress() (the outside service gets the cleaned address)', $seenCall === true);

echo "G  once a request PROMISES anonymity, every error and activity record of it names nobody (#1044)\n";
// LAST, because the promise cannot be taken back within a request (here: this
// process). A signed-in member, as on the anonymous form.
$_SESSION = ['user_id' => 42];
$actMark  = (int) $db->query('SELECT COALESCE(MAX(`logID`), 0) FROM `tblActivityLogs`')->fetch_row()[0];
Logger::promiseAnonymity();
Logger::errorPlatform('MySQL', 'Error', 'PR_ANON_INSERT_FAIL', 'a statement would not run', '');
$row = $lastError();
ew_check('G an ordinary errorPlatform() line, after the promise, holds no account, network address, browser or headers', $row !== null
    && (string) $row['errorCode'] === 'PR_ANON_INSERT_FAIL' && $row['userID'] === null && $row['visitorIP'] === null
    && $row['userAgent'] === null && $row['requestHeaders'] === null, json_encode($row) ?: '');
Logger::activity('P1044Promised', 'an ordinary activity record');
$act = $db->query('SELECT * FROM `tblActivityLogs` WHERE `logID` > ' . $actMark . " AND `activityType` = 'P1044Promised'")->fetch_assoc();
ew_check('G an ordinary activity() record, after the promise, names nobody though the session names a member', is_array($act) === true
    && $act['userID'] === null && $act['visitorIP'] === null && $act['sessionID'] === null && $act['sessionDataSnapshot'] === null, json_encode($act) ?: '');
$db->query('DELETE FROM `tblActivityLogs` WHERE `logID` > ' . $actMark);
unset($_SESSION);

// Tidy: the rows this run wrote.
$db->query('DELETE FROM `tblErrors` WHERE `errorID` > ' . $mark);

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed\n", $passes, $failures);
const EW_EXPECTED_CHECKS = 48;
if ($passes + $failures !== EW_EXPECTED_CHECKS) {
    printf("FAIL: %d checks were made, %d expected - a check was skipped or added without changing EW_EXPECTED_CHECKS\n", $passes + $failures, EW_EXPECTED_CHECKS);
    exit(1);
}
exit($failures === 0 ? 0 : 1);
