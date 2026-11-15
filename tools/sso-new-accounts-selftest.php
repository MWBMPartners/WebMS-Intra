<?php
// Path: tools/sso-new-accounts-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test: may a Microsoft or Google sign-in make a new account? (#1042) 🔑🆕
 * -----------------------------------------------------------------------------
 * Asks `Portal\Core\SsoNewAccounts` directly — `levelAllows()` for one level's
 * stored value and `allowedFrom()` for the two levels together — with the
 * values a settings row can really hold and a few it should never hold.
 *
 * WHY IT EXISTS. Two rules in that class were pinned by NOTHING, found by the
 * independent check of #1042 round 2: planting both faults at once left
 * `tools/two-step-selftest.php` at 172 of 172. That test drives the callbacks
 * over HTTP with the setting at '1', '0' or missing in the places a page can
 * put it, and none of its cases reaches the two rules below:
 *
 *   1. NOTHING STORED MEANS ALLOW (D-185: "default: allow"). The opposite of
 *      `auth.password.selfAdd`, on purpose: this guards what the portal has
 *      always done, so files reaching a server before "Run migrations", or an
 *      installation layer start-up never loaded, must change nothing. Wrong
 *      here, and every new member is turned away on upgrade day.
 *   2. A VALUE STORED BUT NOT PLAINLY '1' MEANS REFUSE. Somebody chose
 *      something; reading an unrecognised choice as the stricter one is the
 *      recoverable direction. Wrong here, and a row reading 'no', 'off' or a
 *      typo quietly makes accounts the organisation tried to stop.
 *
 * Section C states each case's expected answer in a table written by hand, so
 * the class's rule is never derived from the class (a test holding its own
 * copy of the logic tests nothing, #647). The two counts are pinned, so a
 * deleted case shows as a wrong count rather than a quieter pass.
 *
 * It needs NO DATABASE and NO SERVER: the two methods take plain arrays.
 *
 * Run it with:
 *     php tools/sso-new-accounts-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1042
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR
    . '_core' . DIRECTORY_SEPARATOR . 'SsoNewAccounts.php';

use Portal\Core\SsoNewAccounts;

/** How many checks a full run makes. A different number means a case was added or lost without saying so. */
const EXPECTED_CHECKS = 27;

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
    echo "  FAIL  $what\n";
    if ($saw !== '') {
        echo "        saw: $saw\n";
    }
}

/** A settings array holding `auth.sso.createAccounts` as `$value`, nested the way `$SETTINGS` holds dotted keys. */
function holding(mixed $value): array
{
    return ['auth' => ['sso' => ['createAccounts' => $value]]];
}

// =============================================================================
echo "\nA. One level's stored value (levelAllows)\n";
// =============================================================================
// [what is stored, the answer it must give, in words]
$levelCases = [
    [null,    true,  'nothing stored (null) allows - D-185, "default: allow"'],
    ['1',     true,  "'1' allows"],
    [' 1 ',   true,  "' 1 ' (spaces round it) allows - the value is trimmed"],
    ['0',     false, "'0' refuses"],
    ['yes',   false, "'yes' refuses - not plainly '1', so read as the stricter choice"],
    ['true',  false, "'true' refuses - not plainly '1'"],
    ['',      false, "'' (stored, but empty) refuses - a row IS there, and it does not say '1'"],
    ['1.0',   false, "'1.0' refuses - not plainly '1'"],
    [1,       true,  'the number 1 allows'],
    [0,       false, 'the number 0 refuses'],
    [true,    true,  'true allows'],
    [false,   false, 'false refuses'],
    [['1'],   false, "a list holding '1' refuses - not a value at all"],
];
foreach ($levelCases as [$stored, $expected, $words]) {
    $got = SsoNewAccounts::levelAllows($stored);
    check('A ' . $words, $got === $expected, 'answered ' . var_export($got, true) . ' for ' . var_export($stored, true));
}

// =============================================================================
echo "\nB. The two levels together (allowedFrom): the stricter wins\n";
// =============================================================================
// [the merged settings ($SETTINGS), the installation layer, expected, words]
$pairCases = [
    [[],              null,            true,  'installation layer never loaded (null) and nothing in the merged settings: allows'],
    [holding('1'),    null,            true,  "installation layer never loaded, merged '1': allows"],
    [holding('0'),    null,            false, "installation layer never loaded, merged '0' (an organisation refusing): refuses"],
    [[],              [],              true,  'the key missing at both levels: allows'],
    [['auth' => []],  ['auth' => ['sso' => []]], true, 'the key missing under parts that do exist: allows'],
    [holding('1'),    holding('1'),    true,  "both '1': allows"],
    [holding('1'),    holding('0'),    false, "installation '0' beats merged '1': refuses (the stricter level wins)"],
    [holding('0'),    holding('1'),    false, "merged '0' (the organisation's own row) beats installation '1': refuses"],
    [[],              holding('0'),    false, "installation '0' and nothing merged: refuses"],
    [holding('1'),    holding('yes'),  false, "installation 'yes' (unrecognised) refuses even with merged '1'"],
    [holding('true'), holding('1'),    false, "merged 'true' (unrecognised) refuses even with installation '1'"],
    [holding(' 1 '),  holding(1),      true,  "merged ' 1 ' and installation the number 1: allows"],
    [holding(true),   [],              true,  'merged true and the installation key missing: allows'],
];
foreach ($pairCases as [$merged, $layer, $expected, $words]) {
    $got = SsoNewAccounts::allowedFrom($merged, $layer);
    check('B ' . $words, $got === $expected, 'answered ' . var_export($got, true));
}

// =============================================================================
echo "\nC. Nothing was lost\n";
// =============================================================================
check('C the case tables still hold 13 one-level cases and 13 two-level cases (a deleted case would test nothing and say nothing)',
    count($levelCases) === 13 && count($pairCases) === 13,
    count($levelCases) . ' and ' . count($pairCases));

$ran = $passed + $failed;
echo "\n{$passed} passed, {$failed} failed, {$ran} ran of " . EXPECTED_CHECKS . " expected.\n";
if ($ran !== EXPECTED_CHECKS) {
    echo "FAIL — the number of checks that ran is not the number this file expects.\n";
    exit(1);
}
if ($failed > 0) {
    echo "FAIL — read the FAIL lines above.\n";
    exit(1);
}
echo "PASS — all {$passed} checks passed.\n";
exit(0);
