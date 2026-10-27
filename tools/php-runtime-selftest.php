<?php
// Path: tools/php-runtime-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test: the PHP version judgement (#640) 🐘
 * -----------------------------------------------------------------------------
 * Checks Portal\Core\PhpRuntime, the ONE place that decides whether the PHP
 * serving a request is new enough to run this portal and whether it is older
 * than the October 2026 security fix (CVE-2026-109618 and CVE-2026-109619, the
 * pair reported as "PHP2Shell").
 *
 * WHY IT IS WORTH TESTING A CLASS THIS SMALL
 * ------------------------------------------
 * Because it is the thing that will TELL A CUSTOMER they may have a serious
 * security problem. A judgement that is wrong in the alarming direction sends
 * people to their hosting company for nothing and teaches them to ignore the
 * next warning; wrong in the reassuring direction and it is worse than having
 * no check at all. Both directions are tested here, which is the point: a
 * check that only ever proves the good case proves almost nothing.
 *
 * It needs NO DATABASE and NO SERVER. PhpRuntime depends on nothing but PHP,
 * so this file can run anywhere, which is also a test of that promise: if
 * somebody gives the class a dependency, this file stops working.
 *
 * Run it with:
 *     php tools/php-runtime-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/640
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR
    . '_core' . DIRECTORY_SEPARATOR . 'PhpRuntime.php';

use Portal\Core\PhpRuntime;

$passed = 0;
$failed = 0;

/**
 * ✅ One check.
 *
 * @param string $what What is being checked, in words.
 * @param bool   $ok   Whether it held.
 * @param string $saw  What was actually seen, for a failure message.
 */
function check(string $what, bool $ok, string $saw = ''): void
{
    global $passed, $failed;
    if ($ok === true) {
        $passed++;
        echo "  ok    $what\n";

        return;
    }
    $failed++;
    echo "  FAIL  $what" . ($saw !== '' ? " — saw: $saw" : '') . "\n";
}

echo "PHP version judgement self-test (#640)\n";
echo 'Running on PHP ' . PHP_VERSION . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n\n";

// =============================================================================
// A. Too old to run the portal at all
// =============================================================================
echo "A. Older than the portal can run on\n";

foreach (['8.3.36', '8.2.35', '8.1.99', '7.4.33'] as $old) {
    $a = PhpRuntime::classify($old);
    check("$old is 'crit' overall", $a['state'] === 'crit', $a['state']);
    check("$old says so on the SUPPORT side", $a['support']['state'] === 'crit', $a['support']['state']);
}

// 🧠 8.3.36 carries the October fix for its own line, and is STILL too old to
//    run the portal. The two judgements are separate on purpose, and this is
//    the case that proves it: a reader must not be told "you are fine" because
//    the security half is happy.
$a = PhpRuntime::classify('8.3.36');
check("8.3.36 is fixed on the SECURITY side but still 'crit' overall",
    $a['security']['state'] === 'ok' && $a['state'] === 'crit',
    $a['security']['state'] . ' / ' . $a['state']);

// =============================================================================
// B. The owner's exact worry: the floor version passes, and is unpatched
// =============================================================================
echo "\nB. The floor version (8.4.0) — what the installer used to accept silently\n";

$a = PhpRuntime::classify('8.4.0');
check("8.4.0 can RUN the portal (support is not 'crit')", $a['support']['state'] !== 'crit', $a['support']['state']);
check("8.4.0 is WARNED about on the security side", $a['security']['state'] === 'warn', $a['security']['state']);
check('8.4.0 names 8.4.27 as the release that fixed it', $a['security']['fixedIn'] === '8.4.27', (string) $a['security']['fixedIn']);
check("8.4.0 is 'warn' overall, never 'ok'", $a['state'] === 'warn', $a['state']);

// =============================================================================
// C. Each side of the fix, for every line that got one
// =============================================================================
echo "\nC. Exactly at, just below and just above each fixed release\n";

foreach (PhpRuntime::PHP2SHELL_FIXED_IN as $series => $fixed) {
    // 🧮 This section works "one below" and "one above" out by arithmetic, so
    //    it only applies to a plain three-part number. The 8.6 line's entry is
    //    a RELEASE CANDIDATE (8.6.0RC4), and subtracting one from "0RC4" gives
    //    the nonsense "8.6.-1" — which the class then read as fine, failing a
    //    check about a version that cannot exist. Pre-release entries are
    //    checked by hand in section C3 instead, where the real neighbours
    //    (RC3, beta1, the finished release) are named.
    if (preg_match('/^\d+\.\d+\.\d+$/', $fixed) !== 1) {
        echo "  note  $series is pinned at $fixed, a pre-release — see section C3\n";
        continue;
    }

    $parts = explode('.', $fixed);
    $below = $parts[0] . '.' . $parts[1] . '.' . (string) ((int) $parts[2] - 1);
    $above = $parts[0] . '.' . $parts[1] . '.' . (string) ((int) $parts[2] + 1);

    $at = PhpRuntime::classify($fixed);
    $lo = PhpRuntime::classify($below);
    $hi = PhpRuntime::classify($above);

    check("$fixed itself counts as fixed", $at['security']['state'] === 'ok', $at['security']['state']);
    check("$below (one below) is warned about", $lo['security']['state'] === 'warn', $lo['security']['state']);
    check("$above (one above) counts as fixed", $hi['security']['state'] === 'ok', $hi['security']['state']);
}

// =============================================================================
// C2. The fixed-release numbers themselves, written out
// =============================================================================
echo "\nC2. The fixed-release numbers, pinned by hand\n";

// 🧠 WHY THIS SECTION EXISTS, AND WHY SECTION C IS NOT ENOUGH.
//    Section C loops over PHP2SHELL_FIXED_IN and works out "one below" and "one
//    above" FROM THAT CONSTANT. So it proves the comparison is self-consistent
//    and NOTHING about whether the numbers are right. A review on 27 October
//    2026 planted `'8.5' => '8.5.99'` and `'8.5' => '8.5.1'` and section C
//    passed both — 66 of 66 green while the class told every 8.5 customer
//    something false, in the reassuring direction for the second one.
//
//    These numbers are therefore written out by hand, from the PHP project's
//    own announcements of 21 October 2026. If a number is ever changed, this
//    section fails and whoever changed it has to change it here too, on
//    purpose, having looked it up.
$expectedFixes = [
    '8.6' => '8.6.0RC4',
    '8.5' => '8.5.12',
    '8.4' => '8.4.27',
    '8.3' => '8.3.36',
    '8.2' => '8.2.35',
];
foreach ($expectedFixes as $series => $fixed) {
    check(
        "the fix for the $series line is recorded as $fixed",
        (PhpRuntime::PHP2SHELL_FIXED_IN[$series] ?? '') === $fixed,
        (string) (PhpRuntime::PHP2SHELL_FIXED_IN[$series] ?? '(missing)')
    );
}
check(
    'no other release line has been added without being pinned here',
    count(PhpRuntime::PHP2SHELL_FIXED_IN) === count($expectedFixes),
    (string) count(PhpRuntime::PHP2SHELL_FIXED_IN) . ' lines listed'
);

// =============================================================================
// C3. A test build is not a finished release
// =============================================================================
echo "\nC3. Pre-releases — the case this file got wrong once\n";

// 🧠 THE FAULT THIS SECTION EXISTS FOR. The first version of this class said
//    any line newer than the newest it knew about must contain the fix,
//    "because it was published later". PHP 8.6 disproves that: its pre-releases
//    began on 2 July 2026 and RC3 came out on 8 October, both BEFORE the
//    21 October fix. Eight builds predate it (Alpha 1-3, Beta 1-3, RC2, RC3 —
//    there was never an RC1). PHP's own RC4 announcement tells everyone on an earlier
//    8.6.0 build to update immediately. So 8.6.0RC3 was being shown a green
//    panel saying it carried a fix it did not have.
$a = PhpRuntime::classify('8.6.0RC3');
check("8.6.0RC3 is WARNED about (it predates the fix)", $a['security']['state'] === 'warn', $a['security']['state']);
check("8.6.0RC3 is never 'ok' overall", $a['state'] !== 'ok', $a['state']);

$a = PhpRuntime::classify('8.6.0beta1');
check('8.6.0beta1 is warned about too', $a['security']['state'] === 'warn', $a['security']['state']);

$a = PhpRuntime::classify('8.6.0RC4');
check('8.6.0RC4 DOES carry the fix', $a['security']['state'] === 'ok', $a['security']['state']);
check('…but is still flagged as a test build, not a finished release',
    $a['support']['state'] === 'warn'
    && stripos((string) $a['support']['headline'], 'test build') !== false,
    $a['support']['state'] . ' / ' . (string) $a['support']['headline']);

$a = PhpRuntime::classify('8.6.0');
check('the finished 8.6.0 is fine on both counts', $a['state'] === 'ok', $a['state']);

// A release candidate of a FIXED release is not that release.
$a = PhpRuntime::classify('8.5.12RC1');
check('8.5.12RC1 does not count as 8.5.12', $a['security']['state'] === 'warn', $a['security']['state']);

// 🧠 And the durable rule, which is what stops this fault coming back when
//    this file goes stale about 8.7: a PRE-RELEASE of a line it has never
//    heard of is "cannot tell", not "fine".
$a = PhpRuntime::classify('8.7.0RC1');
check('a pre-release of an UNKNOWN newer line is not waved through',
    $a['security']['state'] === 'warn', $a['security']['state']);
$a = PhpRuntime::classify('8.7.0');
check('…while a FINISHED release of that line is fine',
    $a['security']['state'] === 'ok', $a['security']['state']);

// =============================================================================
// D. The version this machine is actually running
// =============================================================================
echo "\nD. The running PHP, judged by the same rules\n";

$live = PhpRuntime::classify();
check('the running version is read back', $live['version'] !== '', $live['version']);
check('the running version is judged, not merely printed',
    in_array($live['state'], ['ok', 'warn', 'crit'], true) === true, $live['state']);
echo '  note  this machine runs PHP ' . $live['version'] . ' and is judged: '
    . $live['state'] . ' — ' . $live['headline'] . "\n";

// =============================================================================
// E. A line newer than this file knows about
// =============================================================================
echo "\nE. A release line published after this file was written\n";

$a = PhpRuntime::classify('8.9.0');
check('8.9.0 counts as carrying the fix', $a['security']['state'] === 'ok', $a['security']['state']);
check('8.9.0 is not warned about for support', $a['support']['state'] === 'ok', $a['support']['state']);
check('8.9.0 admits nobody here has checked it',
    stripos((string) $a['security']['detail'], 'nobody here has checked') !== false, '');
check('8.9.0 is described as a FINISHED release, which is why it counts as fixed',
    stripos((string) $a['security']['detail'], 'finished release') !== false, '');

// 🧠 A FUTURE LINE MUST NOT BE WARNED ABOUT. This is the "going stale makes it
//    less informative, never wrong" promise in the class's header, and it is
//    the one a careless edit would break — somebody adding a "newer than
//    anything we know is suspicious" rule would make every customer on a
//    future PHP see a security warning that is simply false.
$a = PhpRuntime::classify('9.0.0');
check('9.0.0 is not warned about either', $a['state'] === 'ok', $a['state']);

// =============================================================================
// F. Versions that are not clean three-part numbers
// =============================================================================
echo "\nF. Versions as real servers report them\n";

// A distribution's own build. Debian and Red Hat append their packaging
// label, and the three numbers in front are what matters.
$a = PhpRuntime::classify('8.4.27-1~deb13u1');
check('a Debian build of 8.4.27 counts as 8.4.27', $a['security']['state'] === 'ok', $a['security']['state']);
check('the version is reported without the packaging label', $a['version'] === '8.4.27', $a['version']);
check('the version as reported is kept whole in "raw"', $a['raw'] === '8.4.27-1~deb13u1', $a['raw']);

// A release candidate of the FIXED release is not the fixed release.
$a = PhpRuntime::classify('8.4.26RC1');
check('8.4.26RC1 is warned about', $a['security']['state'] === 'warn', $a['security']['state']);

// Two parts only. Padding to .0 is the cautious reading: the oldest release of
// that line. Reading it as the newest would tell somebody they were safe on
// the strength of a number that does not say so.
$a = PhpRuntime::classify('8.4');
check('a bare "8.4" is treated as 8.4.0 and warned about',
    $a['version'] === '8.4.0' && $a['security']['state'] === 'warn',
    $a['version'] . ' / ' . $a['security']['state']);

// Nothing readable at all.
foreach (['', 'unknown', 'PHP', 'x.y.z'] as $junk) {
    $a = PhpRuntime::classify($junk);
    check("'" . $junk . "' gives 'warn', never 'ok'", $a['state'] === 'warn', $a['state']);
    check("'" . $junk . "' admits it could not be read",
        stripos((string) $a['headline'], 'could not be read') !== false
        || stripos((string) $a['security']['headline'], 'unknown') !== false, (string) $a['headline']);
}

// =============================================================================
// G. The honest-wording promise
// =============================================================================
echo "\nG. The wording never overstates what a version number can prove\n";

// 🧠 This is the check that matters most, and the easiest to lose. A version
//    number CANNOT tell a vendor-patched build from an unpatched one, so the
//    product must never say "you are vulnerable" / "you are at risk" / "this
//    site is insecure". If somebody tightens the wording for impact, this
//    fails. The file header explains why that is not an improvement.
$banned = ['you are vulnerable', 'is vulnerable', 'you are at risk', 'this site is insecure',
           'your site is insecure', 'has been compromised', 'is compromised'];

$allWords = '';
foreach (['8.4.0', '8.4.26', '8.5.11', '8.1.0', '', '8.9.0', '8.5.12'] as $v) {
    $a = PhpRuntime::classify($v);
    $allWords .= ' ' . $a['headline'] . ' ' . $a['detail']
        . ' ' . $a['security']['headline'] . ' ' . $a['security']['detail']
        . ' ' . $a['support']['headline'] . ' ' . $a['support']['detail'];
}
$allWords = strtolower($allWords);
foreach ($banned as $phrase) {
    check('nothing anywhere says "' . $phrase . '"', strpos($allWords, $phrase) === false);
}

// And the warning must actually tell somebody what to DO, or it is just noise.
$a = PhpRuntime::classify('8.4.0');
check('the warning says to ask the hosting company',
    stripos((string) $a['security']['detail'], 'hosting company') !== false, '');
check('the warning admits the server may already be fixed',
    stripos((string) $a['security']['detail'], 'may already be fixed') !== false, '');
check('the warning carries the official PHP page',
    strpos((string) $a['security']['detail'], 'php.net') !== false, '');
check('the warning names the identifiers a host will recognise',
    strpos((string) $a['security']['detail'], 'CVE-2026-109618') !== false, '');

// =============================================================================
// H. The decision the owner can reverse in one place
// =============================================================================
echo "\nH. Blocking an install is one constant, and it is currently off\n";

check('BLOCK_INSTALL_WHEN_UNPATCHED exists',
    defined('Portal\Core\PhpRuntime::BLOCK_INSTALL_WHEN_UNPATCHED') === true);
check('it is false, so an unpatched PHP never stops an installation',
    PhpRuntime::BLOCK_INSTALL_WHEN_UNPATCHED === false,
    var_export(PhpRuntime::BLOCK_INSTALL_WHEN_UNPATCHED, true));

// The link has to be the PHP project's own page, not a security company's
// write-up: it is the one source that cannot go out of date about PHP's
// releases, and the owner asked for an official page.
check('the advisory link is php.net',
    strpos(PhpRuntime::PHP2SHELL_ADVISORY, 'https://www.php.net/') === 0,
    PhpRuntime::PHP2SHELL_ADVISORY);

// =============================================================================
// I. There is only ONE place that decides
// =============================================================================
echo "\nI. No page writes its own PHP version rule\n";

// 🧠 The whole value of this class is that four pages agree. If one of them
//    goes back to comparing PHP_VERSION itself, they can disagree — and a
//    security judgement that disagrees with itself is worse than none. This
//    repeats what tools/audit-checks/check_php_version_one_place.py does, so
//    that running this file alone still catches it.
$watched = [
    'web/_install/index.php',
    'web/_apps/admin/system-info/index.php',
    'web/_apps/admin/index.php',
    'web/_apps/cron/_health-probes.php',
];
$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
foreach ($watched as $file) {
    $code = (string) file_get_contents($root . $file);
    // Comments are stripped first: these files EXPLAIN the old rule in prose,
    // and a check that reads prose judges the wrong thing. (That exact mistake
    // was made on this project on 27 October 2026 and is written down.)
    $bare = '';
    foreach (token_get_all($code) as $token) {
        if (is_array($token) === true
            && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
            continue;
        }
        $bare .= is_array($token) === true ? $token[1] : $token;
    }
    // Both spellings. `phpversion()` is the obvious alternative to the
    //    PHP_VERSION constant, and a review on 27 October 2026 planted
    //    `version_compare(phpversion(), '8.4.0', '<')` and found that neither
    //    this check nor the Python tripwire noticed it.
    //    The integer spelling too (`PHP_VERSION_ID < 80400`). A round-2 review
    //    planted it and found that this check's own label — "compares the PHP
    //    version itself nowhere" — passed on it, while the Python tripwire
    //    caught it. A label has to be true of what the check actually does.
    $hits = preg_match_all(
        '/version_compare\s*\(\s*(?:PHP_VERSION|phpversion\s*\(\s*\))|PHP_VERSION_ID\s*[<>=!]/',
        $bare
    );
    check("$file compares the PHP version itself nowhere", $hits === 0, (string) $hits . ' time(s)');
    check("$file asks PhpRuntime", strpos($bare, 'PhpRuntime::classify') !== false);
}

// =============================================================================
echo "\n";
echo $failed === 0
    ? "ALL $passed CHECKS PASSED\n"
    : ($passed + $failed) . " checks, $failed FAILED\n";

exit($failed === 0 ? 0 : 1);
