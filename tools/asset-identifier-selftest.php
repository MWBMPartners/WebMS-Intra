<?php
// Path: tools/asset-identifier-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Asset identifier check digits and check characters 🔢
 * -----------------------------------------------------------------------------
 * Proves the two check routines in `Portal\Core\AssetRegister` against
 * published examples. NEEDS NO DATABASE: both routines are pure arithmetic over
 * a string, which is also why this test can be this short.
 *
 * WHY IT EXISTS. `gmnMod1021Check()` was referred to BY NAME from its caller
 * for months and did not exist; the caller told the user "GMN check-character
 * validation is not implemented yet — value accepted without verification".
 * Writing it was easy. Writing it CORRECTLY is the part that needed proving,
 * because a check-digit routine that is almost right is worse than none at
 * all: it refuses valid identifiers, and the person typing one has no way to
 * tell that the portal is wrong rather than their value.
 *
 * So section F does not test that the function "returns something". It tests
 * it against the five examples published with the GS1 reference
 * implementation, including GS1's own worked example 95212349521234 → R9.
 *
 * WHAT IT CANNOT DO
 *   - It cannot prove the algorithm IS the standard. It proves this
 *     implementation agrees with the reference implementation's published
 *     examples on all five, which is the strongest evidence available without
 *     the paid specification document.
 *   - It does not test the format regexes, which live in the database.
 *   - It does not reach the pages that show these warnings.
 *
 * Usage:  php tools/asset-identifier-selftest.php
 * Exit:   0 only when every check ran and passed; 1 otherwise.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$repo = dirname(__DIR__);
require_once $repo . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'AssetRegister.php';

$passed = 0;
$failed = 0;

function ok(string $id, string $words): void
{
    global $passed;
    $passed++;
    echo "PASS — {$id} {$words}\n";
}

function bad(string $id, string $words, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "FAIL — {$id} {$words}\n";
    if ($detail !== '') {
        echo "         {$detail}\n";
    }
}

// 🔐 Both routines are private, which is right: they are implementation detail
//    of validateIdentifier(). Reflection is used rather than making them
//    public just so a test can see them — widening a method's visibility for a
//    test changes the thing being tested.
// No setAccessible(): a private method has been reachable through
// reflection without it since PHP 8.1, and the call is deprecated in 8.5,
// where it printed a warning on every run of this test.
$gmn = new ReflectionMethod(\Portal\Core\AssetRegister::class, 'gmnMod1021Check');
$call = static fn (string $v): ?bool => $gmn->invoke(null, $v);

echo "=== F. Global Model Number check character pair (GS1 General Specifications 7.9.5) ===\n";

// The five examples published with the GS1 reference implementation
// (aidc-toolkit/gs1, test/check.test.ts). The first is GS1's own worked
// example. Each body is given with the pair the standard says it produces, so
// the WHOLE identifier must verify.
$vectors = [
    'F01' => ['95212349521234', 'R9', "GS1's own worked example"],
    'F02' => ['9521234ABCDEFabcdef', '8T', 'mixed digits and both letter cases'],
    'F03' => ['!"%&\'()*+,-./0123456789:;<=>', 'TH', 'the first 28 characters of the allowed set'],
    'F04' => ['?ABCDEFGHIJKLMNOPQRSTUVWXYZ_', 'EP', 'the next 28, including the underscore'],
    'F05' => ['abcdefghijklmnopqrstuvwxyz', '5A', 'the 26 lower-case letters'],
];
foreach ($vectors as $id => [$body, $pair, $words]) {
    $result = $call($body . $pair);
    if ($result === true) {
        ok($id, "{$words}: '{$body}' + '{$pair}' verifies");
    } else {
        bad($id, "{$words}: '{$body}' + '{$pair}' should verify", 'got ' . var_export($result, true));
    }
}

// 🧪 The other direction, which is the one that matters for a typing mistake:
//    change one character of the body and the pair must no longer match.
//    Without this, a function that simply answered "true" would pass F01-F05.
echo "\n=== F2. A mistyped character is caught (the whole point) ===\n";
$wrongBodies = [
    'F06' => ['95212349521235R9', 'one digit changed'],
    'F07' => ['95212349521234R8', 'the check pair itself changed'],
    'F08' => ['A5212349521234R9', 'the first character changed'],
];
foreach ($wrongBodies as $id => [$whole, $words]) {
    $result = $call($whole);
    if ($result === false) {
        ok($id, "{$words}: '{$whole}' is refused");
    } else {
        bad($id, "{$words}: '{$whole}' should be refused", 'got ' . var_export($result, true));
    }
}

echo "\n=== F3. \"Cannot tell\" is answered as null, never as a refusal ===\n";
// A value this scheme cannot check must NOT be reported as wrong: the caller
// turns null into "accepted unchecked" and false into "this is wrong".
$cannotTell = [
    'F09' => ['ab', 'shorter than a body plus a pair'],
    'F10' => ['', 'nothing at all'],
    'F11' => [str_repeat('9', 29) . 'R9', 'a body longer than the 28 weights the standard defines'],
    'F12' => ['95212349521~34R9', 'a character the standard does not allow'],
];
foreach ($cannotTell as $id => [$whole, $words]) {
    $result = $call($whole);
    if ($result === null) {
        ok($id, "{$words}: answered \"cannot tell\"");
    } else {
        bad($id, "{$words}: should answer null", 'got ' . var_export($result, true));
    }
}

echo "\n=== G. The GS1 mod-10 check digit, for comparison ===\n";
$mod10 = new ReflectionMethod(\Portal\Core\AssetRegister::class, 'gs1Mod10Check');
$m = static fn (string $v): ?bool => $mod10->invoke(null, $v);

// 5012345678900 is a widely published valid EAN-13.
if ($m('5012345678900') === true) {
    ok('G01', 'a valid EAN-13 verifies');
} else {
    bad('G01', 'a valid EAN-13 should verify', 'got ' . var_export($m('5012345678900'), true));
}
if ($m('5012345678901') === false) {
    ok('G02', 'the same number with a wrong check digit is refused');
} else {
    bad('G02', 'a wrong check digit should be refused', 'got ' . var_export($m('5012345678901'), true));
}
if ($m('50123456789AB') === null) {
    ok('G03', 'a value that is not all digits answers "cannot tell"');
} else {
    bad('G03', 'a non-numeric value should answer null', 'got ' . var_export($m('50123456789AB'), true));
}

echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
