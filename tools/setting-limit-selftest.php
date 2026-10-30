<?php
// Path: tools/setting-limit-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The installation caps the organisation — self-test 🚧 (#668)
 * -----------------------------------------------------------------------------
 * `Portal\Core\SettingLimit` is the ONE place that resolves a LIMIT across the
 * three tiers: a hard maximum in code, then what the installation allows, then
 * what an organisation has chosen. Each tier can tighten the one above it and
 * none can loosen it.
 *
 * WHY THAT NEEDS A TEST RATHER THAN A READING. The rule is `min()`, which looks
 * impossible to get wrong, and two different faults have got it wrong already.
 * Be accurate about which reader made which, because an earlier version of
 * this comment blamed both on the same one and the independent check corrected
 * it:
 *
 *   * RESOLVING THE ORGANISATION'S MISSING VALUE TO THE BUILT-IN DEFAULT made
 *     the default cap the installation, so an installation could lower a limit
 *     but never raise one. That was the repeating-events reader (#668's first),
 *     and check H04 of `event-recurrence-selftest.php` caught it.
 *   * ZERO. For several settings 0 means "for ever" or "no limit", so it is the
 *     LARGEST value there is, not the smallest, and compared as a number it
 *     sails through any limit. That fault was THIS package's, not the earlier
 *     reader's — repeating events has no 0-means-unlimited setting, because
 *     `EventRecurrence::oneLimitOrNull()` reads anything below 1 as "not
 *     chosen". The independent check broke it in both directions.
 *
 * Both are pinned below. The second is the live one: `attend.detailRetentionDays`
 * is personal data and 0 means keep it for ever.
 *
 * What is checked:
 *
 *   A. the rule itself, every row of its table written out by hand — nothing
 *      chosen, chosen lower, chosen higher, the hard maximum, and a stored
 *      value that is not a number;
 *   B. zero, in both directions, because for a retention setting it is the
 *      largest value and for a size setting it is the smallest;
 *   C. `whyNotAllowed()` and the words it leads to: a refusal, never a clamp;
 *   D. the attendance save handler, read as text with its comments stripped:
 *      it asks the rule on the ORGANISATION's branch, does not ask it on the
 *      installation-wide branch (which is the ceiling being compared against,
 *      so capping it against itself would stop a global administrator raising
 *      it), refuses rather than writing, and records the refusal.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_limit SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/setting-limit-selftest.php
 *
 *         A database is needed only because `App::init()` takes a connection;
 *         nothing here reads or writes a row.
 *
 * Exit:   0 only when every check ran and passed; 1 otherwise, including when
 *         the number of checks is not the number expected.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/668
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 47;

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    exit(1);
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'SettingLimit.php';

$failures = 0;
$passes   = 0;

function check(string $what, bool $isOk, string $detail = ''): void
{
    global $failures, $passes;
    if ($isOk === true) {
        $passes++;
        echo '  PASS  ' . $what . "\n";

        return;
    }
    $failures++;
    echo '  FAIL  ' . $what . "\n";
    if ($detail !== '') {
        echo '          ' . $detail . "\n";
    }
}

function head(string $text): void
{
    echo "\n" . $text . "\n";
}

// 🧰 Stand the two layers up by hand. `InstallationSettings::remember()` is the
//    supported way in, and `App::init()` takes the merged array. Nothing is
//    read from the database, which is why the connection can be anything.
$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'InstallationSettings.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'App.php';

use Portal\Core\App;
use Portal\Core\InstallationSettings;
use Portal\Core\SettingLimit;

/** Put a value at a dotted key, the way bootstrap's assign_setting() does. */
$nest = static function (string $dotKey, mixed $value): array {
    $out  = [];
    $node = &$out;
    foreach (explode('.', $dotKey) as $part) {
        $node[$part] = [];
        $node        = &$node[$part];
    }
    $node = $value;

    return $out;
};

/** Set both layers: what the installation holds, and what this organisation sees merged. */
$world = static function (mixed $installation, mixed $merged) use ($mysqli, $nest): void {
    InstallationSettings::remember($installation === null ? [] : $nest('x.limit', $installation));
    App::init($mysqli, $merged === null ? [] : $nest('x.limit', $merged));
};

const KEY  = 'x.limit';
const DEF  = 90;
const MAXX = 3650;

// =============================================================================
head('A. The rule: each tier may tighten, none may loosen');
// =============================================================================
$world('365', '365');
check('the organisation has chosen nothing, so it follows the installation', SettingLimit::effective(KEY, DEF, MAXX) === 365, (string) SettingLimit::effective(KEY, DEF, MAXX));
check('…and installationAllows() says the same on its own', SettingLimit::installationAllows(KEY, DEF, MAXX) === 365);

$world('365', '30');
check('the organisation has chosen LOWER, which is always allowed', SettingLimit::effective(KEY, DEF, MAXX) === 30, (string) SettingLimit::effective(KEY, DEF, MAXX));

$world('365', '3000');
check('the organisation has chosen HIGHER, so it is capped at the installation', SettingLimit::effective(KEY, DEF, MAXX) === 365, (string) SettingLimit::effective(KEY, DEF, MAXX));

// 🚨 THE FAULT THAT WAS MADE FIRST TIME. The installation raises its limit; an
//    organisation that has chosen nothing must follow it UP. Resolving the
//    organisation's side to the built-in default would hand back 90, because
//    min(90, 365) is 90 — an installation able to lower a limit but never
//    raise one.
$world('365', '365');
check('an installation RAISING its limit reaches an organisation that has chosen nothing', SettingLimit::effective(KEY, DEF, MAXX) === 365 && SettingLimit::effective(KEY, DEF, MAXX) !== DEF);

$world(null, null);
check('nothing stored anywhere falls back to the built-in default', SettingLimit::effective(KEY, DEF, MAXX) === DEF, (string) SettingLimit::effective(KEY, DEF, MAXX));

$world('99999', '99999');
check('the hard maximum in code caps the INSTALLATION too', SettingLimit::effective(KEY, DEF, MAXX) === MAXX, (string) SettingLimit::effective(KEY, DEF, MAXX));
check('…and installationAllows() is capped as well', SettingLimit::installationAllows(KEY, DEF, MAXX) === MAXX);

$world('365', 'twelve');
check('a stored value that is not a number falls back to the default, not to zero', SettingLimit::effective(KEY, DEF, MAXX) === DEF, (string) SettingLimit::effective(KEY, DEF, MAXX));
$world('365', '-4');
check('a negative stored value falls back to the default', SettingLimit::effective(KEY, DEF, MAXX) === DEF, (string) SettingLimit::effective(KEY, DEF, MAXX));
$world('365', '  30  ');
check('a stored number with spaces round it is read', SettingLimit::effective(KEY, DEF, MAXX) === 30);
$world('365', 30);
check('a stored value that is already a whole number is read', SettingLimit::effective(KEY, DEF, MAXX) === 30);
$world('twelve', '30');
check('an INSTALLATION value that is not a number falls back to the default, and still caps', SettingLimit::effective(KEY, DEF, MAXX) === 30 && SettingLimit::installationAllows(KEY, DEF, MAXX) === DEF);

// =============================================================================
head('B. Zero, which means opposite things for different settings');
// =============================================================================
// Zero is KEPT rather than treated as "nothing stored": for several of these
// settings it is a real answer. What it MEANS is the caller's business, and
// this is where the live fault would be.
$world('365', '0');
check('zero is kept as zero, not turned into the default', SettingLimit::effective(KEY, DEF, MAXX) === 0, (string) SettingLimit::effective(KEY, DEF, MAXX));
check('…and that is exactly why a "0 means for ever" caller must convert it BEFORE comparing: as a number it passes a limit it should fail', SettingLimit::whyNotAllowed(0, KEY, DEF, MAXX) === '');
check('…while the same wish expressed as the hard maximum IS refused', SettingLimit::whyNotAllowed(MAXX, KEY, DEF, MAXX) === 'above-installation');
$world('0', '0');
check('without the flag, an installation that stored 0 answers 0 — which is why a 0-means-for-ever caller must pass the flag', SettingLimit::installationAllows(KEY, DEF, MAXX) === 0);

// 🚨 THE FAULT THE INDEPENDENT CHECK FOUND, in both directions. Everything
//    below passes `true` for $zeroIsLargest, which is how a "0 means for ever"
//    setting must be read — at BOTH levels. Converting only the posted number,
//    in the handler, is what broke it.
$world('0', '0');
check('the installation allows FOR EVER: its ceiling reads as the hard maximum, not as nothing', SettingLimit::installationAllows(KEY, DEF, MAXX, true) === MAXX);
check('…and it is reported as really allowing for ever', SettingLimit::installationAllowsForEver(KEY, DEF, MAXX) === true);
check('…so an organisation asking for ever is allowed', SettingLimit::whyNotAllowed(0, KEY, DEF, MAXX, true) === '');
check('…AND a TIGHTER number is allowed too — the half that was refusing every save', SettingLimit::whyNotAllowed(30, KEY, DEF, MAXX, true) === '');

$world('90', '90');
check('the installation allows 90 days: it does NOT allow for ever', SettingLimit::installationAllowsForEver(KEY, DEF, MAXX) === false);
check('…so an organisation asking for ever (0) is REFUSED — the half that let personal data be kept for ever', SettingLimit::whyNotAllowed(0, KEY, DEF, MAXX, true) === 'above-installation');
check('…while a tighter number is still allowed', SettingLimit::whyNotAllowed(30, KEY, DEF, MAXX, true) === '');

$world((string) MAXX, (string) MAXX);
check('an installation at the hard maximum still refuses FOR EVER, because for ever is longer than ten years', SettingLimit::whyNotAllowed(0, KEY, DEF, MAXX, true) === 'above-installation');
check('…and allows the hard maximum itself', SettingLimit::whyNotAllowed(MAXX, KEY, DEF, MAXX, true) === '');

$world('90', '1000');
check('effective() caps an organisation that ALREADY stored more than the installation allows', SettingLimit::effective(KEY, DEF, MAXX, true) === 90, (string) SettingLimit::effective(KEY, DEF, MAXX, true));
$world('90', '0');
check('…and caps one that already stored "for ever"', SettingLimit::effective(KEY, DEF, MAXX, true) === 90, (string) SettingLimit::effective(KEY, DEF, MAXX, true));
$world('0', '0');
check('…while "for ever" survives where BOTH levels mean it', SettingLimit::effective(KEY, DEF, MAXX, true) === 0);

// =============================================================================
head('C. Refusing, never clamping');
// =============================================================================
$world('365', '365');
check('a number at the installation\'s limit is allowed', SettingLimit::whyNotAllowed(365, KEY, DEF, MAXX) === '');
check('a number below it is allowed', SettingLimit::whyNotAllowed(10, KEY, DEF, MAXX) === '');
check('a number above it is refused, naming the installation', SettingLimit::whyNotAllowed(366, KEY, DEF, MAXX) === 'above-installation');
check('a number above the hard maximum is refused, naming that instead', SettingLimit::whyNotAllowed(99999, KEY, DEF, MAXX) === 'above-hard-max');
$words = SettingLimit::refusalWords('above-installation', 365, 'days');
check('the words say what the limit is, and tell the person what to do', str_contains($words, '365 days') === true && str_contains($words, 'not saved') === true && str_contains($words, 'Ask whoever runs this installation') === true, $words);
check('…and they never name the setting key or blame anybody', str_contains($words, KEY) === false && stripos($words, 'you cannot') === false, $words);
check('the hard-maximum words are different, and do not mention the installation', str_contains(SettingLimit::refusalWords('above-hard-max', 365, 'days'), 'installation') === false);
check('no reason gives no words', SettingLimit::refusalWords('', 365, 'days') === '');

// =============================================================================
head('D. The attendance handler asks the rule, on the right branch');
// =============================================================================
// Sections A to C exercise the RULE. They cannot tell you a handler still
// calls it, calls it on the right branch, or acts on the answer. Comments are
// stripped first, so a call that has been commented out never counts.
$handler = (string) file_get_contents(dirname(__DIR__) . '/web/_apps/admin/settings/attendance/save.php');
$code    = '';
foreach (token_get_all($handler) as $t) {
    if (is_array($t) === true && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
        continue;
    }
    $code .= is_array($t) === true ? $t[1] : $t;
}
// 🚨 PIN THE WHOLE CONDITION, not the call. The independent check proved that
//    asking only whether the text is present passes on FOUR different disabled
//    gates: `if (false)`, `&& false` added to the condition, a refusal that
//    never exits so it logs "nothing was saved" and then writes anyway, and a
//    refusal that silently CLAMPS instead. `.claude/CLAUDE.md` names the
//    `&& false` shape twice and this file walked into it anyway.
check('it asks SettingLimit::whyNotAllowed(), with the raw posted number and the zero flag', preg_match('/\$retentionRefusal\s*=\s*SettingLimit::whyNotAllowed\(\s*\$days\s*,[^;]*,\s*true\s*\)\s*;/', $code) === 1);
check('…and the condition on it is EXACTLY "is there a refusal", with nothing added to weaken it', preg_match('/if\s*\(\s*\$retentionRefusal\s*!==\s*\x27\x27\s*\)\s*\{/', $code) === 1);
check('it asks what the installation allows, to say so in the refusal', str_contains($code, 'SettingLimit::installationAllows(') === true);
// 🔁 This check used to require the handler to convert 0 itself. That was the
//    wrong place and the independent check proved it: the conversion now lives
//    in SettingLimit, read at BOTH levels, and the handler passes the number
//    exactly as typed. So what must be true here is the opposite — the handler
//    does NOT convert, because a handler that does is the fault coming back.
check('it does NOT convert 0 itself: the zero rule belongs in SettingLimit, read at both levels', preg_match('/\$days\s*===\s*0\s*\?\s*AnonymousCheckins::MAX_RETENTION_DAYS/', $code) !== 1);
check('it RECORDS a refusal', str_contains($code, "'AttendanceRetentionRefused'") === true);
// The refusal block must END the request. A block that logs and falls through
// writes the value anyway while the record says nothing was saved — the worst
// of the four shapes, because the log actively lies.
$refusalBlock = (static function (string $code): string {
    $at = strpos($code, "if (\$retentionRefusal !== '') {");
    if ($at === false) {
        return '';
    }
    $close = strpos($code, "\n}", $at);

    return $close === false ? '' : substr($code, $at, $close - $at);
})($code);
check('the refusal block ends the request rather than falling through to the write', $refusalBlock !== '' && str_contains($refusalBlock, 'exit();') === true, $refusalBlock === '' ? 'the block could not be found, so nothing was checked' : 'block is ' . strlen($refusalBlock) . ' chars');
// `=(?!=)` — an ASSIGNMENT, not a comparison. Without the lookahead this
// matched the `$days === 0` inside the refusal's own message and failed on
// correct code.
check('…and it does not quietly CLAMP the number on the way past', $refusalBlock !== '' && preg_match('/\$days\s*=(?!=)/', $refusalBlock) !== 1);
// 📍 The CALL, not the first mention. `attendance_settings_write_for_site` is
//    DEFINED earlier in the same file, so `strpos` on the bare name finds the
//    definition and the comparison is meaningless — it passed on a refusal
//    placed after the write. Matched on the call's own argument list instead.
check(
    'it stops rather than writing: the refusal exits before the per-organisation write',
    (($r = strpos($code, 'AttendanceRetentionRefused')) !== false)
        && (($w = strpos($code, 'attendance_settings_write_for_site($mysqli, $siteId, AnonymousCheckins::VISIBILITY_KEY')) !== false)
        && $r < $w,
    'refusal at ' . var_export($r ?? null, true) . ', write at ' . var_export($w ?? null, true)
);
// The installation-wide branch must NOT be capped against itself, or a global
// administrator could never raise the ceiling.
// 📍 The branch runs from its own `if` to the per-organisation write that
//    follows it. Both ends are matched on text unique to the CALL, not on the
//    bare function name — which is defined earlier in the file, so the first
//    version sliced backwards, `$globalBranch` was not the branch at all, and
//    the check PASSED with a cap planted in that branch. Measured by planting
//    it; this is the second time in this file that a bare `strpos` on a
//    function name found its definition.
// The branch ends at its OWN `exit()`, not at the per-organisation write
// further down: the cap this test is about sits BETWEEN the two, so slicing to
// the write swept it into the branch and the check failed on correct code.
// Third time in this file that a naive offset was wrong — take the end from
// something that belongs to the thing being measured.
$branchStart  = (int) strpos($code, "if (\$scope === 'global') {");
$branchEnd    = (int) strpos($code, 'exit();', $branchStart);
$globalBranch = $branchEnd > $branchStart ? substr($code, $branchStart, $branchEnd - $branchStart) : '';
check(
    'the installation-wide branch does NOT cap itself, so a global administrator can still raise the ceiling',
    $globalBranch !== '' && str_contains($globalBranch, 'SettingLimit::whyNotAllowed(') === false,
    $globalBranch === '' ? 'the branch could not be found, so nothing was checked' : 'branch is ' . strlen($globalBranch) . ' chars'
);
check('the page offers a max no larger than the installation allows, so it never offers what the handler refuses', str_contains((string) file_get_contents(dirname(__DIR__) . '/web/_apps/admin/settings/attendance/index.php'), 'max="<?php echo $installationAllowsDays; ?>"') === true);

// =============================================================================
echo "\n" . $passes . ' passed, ' . $failures . ' failed, ' . ($passes + $failures)
    . ' ran of ' . EXPECTED_CHECKS . " expected.\n";
if ($passes + $failures !== EXPECTED_CHECKS) {
    echo "FAIL — the number of checks that ran is not the number this file expects.\n";
    exit(1);
}
if ($failures > 0) {
    exit(1);
}
echo "PASS — all " . EXPECTED_CHECKS . " checks passed.\n";
exit(0);
