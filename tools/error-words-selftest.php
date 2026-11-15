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

// Tidy: the rows this run wrote.
$db->query('DELETE FROM `tblErrors` WHERE `errorID` > ' . $mark);

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed\n", $passes, $failures);
const EW_EXPECTED_CHECKS = 25;
if ($passes + $failures !== EW_EXPECTED_CHECKS) {
    printf("FAIL: %d checks were made, %d expected - a check was skipped or added without changing EW_EXPECTED_CHECKS\n", $passes + $failures, EW_EXPECTED_CHECKS);
    exit(1);
}
exit($failures === 0 ? 0 : 1);
