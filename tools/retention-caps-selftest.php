<?php
// Path: tools/retention-caps-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A retention period a clear-out USES is held to the installation's — self-test
 * 🧹🔒 (#685, #722)
 * -----------------------------------------------------------------------------
 * `tools/setting-limit-selftest.php` proves the RULE (`Portal\Core\SettingLimit`)
 * with no database at all. This proves the two things that rule cannot:
 *
 *   1. that the READERS the nightly clear-out actually uses apply it, against
 *      rows really in a database — including rows this portal would now refuse
 *      to let anybody store; and
 *   2. that the SAVE path judges the ceiling live, with the installation's own
 *      row LOCKED, which needs two connections at once and therefore cannot be
 *      proved by a page test at all.
 *
 * WHY 1 NEEDS A DATABASE AND NOT A FIXTURE ARRAY. #685 was exactly the case
 * where the stored row disagrees with what any form would now accept: an
 * organisation that chose 1000 days while that was allowed, or that had a row
 * written by hand or restored from a whole-database backup. A SAVE-TIME CAP IS
 * NOT A CAP. So every figure here is put in with SQL, which is the only way to
 * reach the state the issue is about.
 *
 * WHY 2 IS A SEPARATE SECTION, AND WHY IT IS NOT IN THE PAGE TEST. #687
 * measured two ordinary overlapping web requests storing fifty years under a
 * ceiling of seven, 60 pairs out of 60, because the gate compared against the
 * request's settings snapshot taken at start-up. `access-pages-selftest.php`
 * and the other HTTP harnesses run the built-in server with ONE worker, so two
 * requests never overlap and such a test PASSES ON THE BROKEN CODE — the lesson
 * the expense payment race is written up under. Section D holds the lock from a
 * second process instead, and the committed check is the one that can fail.
 *
 * WHAT IS CHECKED
 *   A. #685 — `AnonymousCheckins::readRetentionDays()`, every row of the rule's
 *      own table, each figure inserted by SQL. Including that a SHORTER
 *      organisation figure survives, which is what stops the cap being a clamp
 *      to one number.
 *   B. #714/#722 — `TranscriptRetention::daysForSite()`, the same, plus "keep
 *      for ever" surviving where BOTH levels mean it. That last one could not
 *      happen before #722: the class worked its own arithmetic out against a
 *      ceiling that never answers 0, so an installation choosing "for ever" had
 *      every transcript deleted at ten years, under a comment in the sweep
 *      saying it kept them.
 *   C. `TranscriptRetention::saveForSite()` — refuses above the installation and
 *      writes nothing, refuses above the hard maximum in different words,
 *      stores a shorter figure, and clears the organisation's own row.
 *   D. THE LOCK — a second process holds the installation's row and raises the
 *      ceiling; a save of a longer figure must WAIT for it and then refuse.
 *   E. the page and its handler, read as text: both of #668's questions asked,
 *      a box never prefilled above its own `max`, a refusal that exits without
 *      writing, and the refusal recorded.
 *
 * WHAT THIS CANNOT PROVE
 *   - Nothing here runs over HTTP, so it says nothing about what the page looks
 *     like, whether its gate refuses a non-administrator, or whether the form
 *     token is checked. Section E reads those as text, which is weaker.
 *   - Section D proves the lock SERIALISES two savers and that the second sees
 *     the first's ceiling. It does not run many simultaneous pairs; #687's
 *     116-pair measurement is the precedent for that and was done by hand.
 *   - It runs one engine and one version, whichever the caller points it at.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_retcaps SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/retention-caps-selftest.php
 *
 * Exit:   0 only when every check ran and passed; 1 otherwise, including when
 *         the number of checks is not the number expected.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/685
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const RC_EXPECTED_CHECKS = 64;

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    exit(1);
}

$rcHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$rcUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$rcPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$rcPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

$rcCore = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
require_once $rcCore . 'InstallationSettings.php';
require_once $rcCore . 'App.php';
require_once $rcCore . 'SettingLimit.php';
require_once $rcCore . 'AnonymousCheckins.php';
require_once $rcCore . 'TranscriptRetention.php';

use Portal\Core\AnonymousCheckins;
use Portal\Core\App;
use Portal\Core\InstallationSettings;
use Portal\Core\TranscriptRetention;

// -----------------------------------------------------------------------------
// 🔒 SECOND ROLE: holding the installation's row for section D.
//
//    The same file, started again with an argument, because the lock has to be
//    held by a DIFFERENT CONNECTION while this one runs — and a lock that is
//    not held by somebody else proves nothing. It takes the lock through the
//    portal's own method, so a change to that method is felt here too; then it
//    raises the ceiling and waits before committing, which is the window the
//    saver has to be caught in.
// -----------------------------------------------------------------------------
if (($argv[1] ?? '') === '--hold-ceiling') {
    $holdTo      = (string) ($argv[2] ?? '7');
    $holdForMs   = (int) ($argv[3] ?? 3000);
    $holder      = new mysqli($rcHost, $rcUser, $rcPass, $dbName, $rcPort);
    $holder->begin_transaction();
    TranscriptRetention::installationCeilingLocked($holder);
    $up  = $holder->prepare('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
    $key = TranscriptRetention::KEY;
    $up->bind_param('ss', $holdTo, $key);
    $up->execute();
    $up->close();
    usleep($holdForMs * 1000);
    $holder->commit();
    $holder->close();
    exit(0);
}

$mysqli = new mysqli($rcHost, $rcUser, $rcPass, $dbName, $rcPort);

$rcPasses   = 0;
$rcFailures = 0;

function rc_check(string $what, bool $isOk, string $detail = ''): void
{
    global $rcPasses, $rcFailures;
    if ($isOk === true) {
        $rcPasses++;
        echo '  ✓ ' . $what . "\n";

        return;
    }
    $rcFailures++;
    echo '  ✗ ' . $what . ($detail === '' ? '' : '  —  ' . $detail) . "\n";
}

function rc_head(string $text): void
{
    echo "\n" . $text . "\n";
}

/**
 * 🧰 Put one setting in BY SQL, at one or both levels, and stand the layers up
 *    exactly as `bootstrap.php` does.
 *
 * The rows are what the readers go to the database for; the layers are what
 * `SettingLimit` reads for the installation's side. Both have to be set or the
 * fixture is only half a world — and the two must AGREE, or the test is
 * measuring a state the portal cannot be in, which is #645's lesson.
 */
function rc_world(mysqli $db, string $dotKey, string $installation, ?string $own, int $siteId = 1): void
{
    $stmt = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ?');
    $stmt->bind_param('s', $dotKey);
    $stmt->execute();
    $stmt->close();

    $ins = $db->prepare(
        'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) '
        . 'VALUES (?, ?, ?, ?, 0)'
    );
    $nullSite = null;
    $blank    = '';
    $ins->bind_param('isss', $nullSite, $dotKey, $installation, $blank);
    $ins->execute();
    if ($own !== null) {
        $ins->bind_param('isss', $siteId, $dotKey, $own, $blank);
        $ins->execute();
    }
    $ins->close();

    // The nested shape `assign_setting()` in bootstrap.php produces.
    $nest = static function (string $key, string $value): array {
        $parts = array_reverse(explode('.', $key));
        $node  = $value;
        foreach ($parts as $part) {
            $node = [$part => $node];
        }

        return $node;
    };
    InstallationSettings::remember($nest($dotKey, $installation));
    // The merged array is the organisation's own value where it has one, and
    // the installation's where it has not — which is what bootstrap produces.
    App::init($db, $nest($dotKey, $own ?? $installation));
}

$AK = AnonymousCheckins::RETENTION_KEY;
$TK = TranscriptRetention::KEY;

// 🏢 A SECOND ORGANISATION, and it has to be a real row: `tblSettings.siteID`
//    is linked to `tblSites` with `ON DELETE CASCADE`, so the database refuses
//    a settings row for an organisation that does not exist. Without a second
//    one the per-organisation lookup is pinned by nothing — a reader that
//    ignored its `$siteId` argument entirely would pass every check here.
$mysqli->query(
    'INSERT INTO `tblSites` (`siteID`, `siteName`, `siteKey`) VALUES (2, \'Second organisation\', \'second\') '
    . 'ON DUPLICATE KEY UPDATE `siteName` = VALUES(`siteName`)'
);

// =============================================================================
rc_head('A. #685 — the attendance clear-out holds every organisation to the installation');
// =============================================================================
// Each figure is INSERTED BY SQL, which is the only way to reach the state the
// issue is about: a row stored before the save-time refusal existed, written by
// hand, or restored from a whole-database backup.
rc_world($mysqli, $AK, '90', '1000');
rc_check('an organisation storing 1000 days is cleared at the installation\'s 90',
    AnonymousCheckins::readRetentionDays(1) === 90, (string) AnonymousCheckins::readRetentionDays(1));

rc_world($mysqli, $AK, '90', '0');
rc_check('"keep for ever" does NOT survive an installation that allows 90 days',
    AnonymousCheckins::readRetentionDays(1) === 90, (string) AnonymousCheckins::readRetentionDays(1));

rc_world($mysqli, $AK, '90', '-1');
rc_check('a negative figure is read as "for ever" and then capped like one',
    AnonymousCheckins::readRetentionDays(1) === 90, (string) AnonymousCheckins::readRetentionDays(1));

// 🚨 THE CAP MUST NOT BE A CLAMP TO ONE NUMBER. Without this check the whole
//    section passes on a reader that simply answers the installation's figure
//    whatever an organisation chose — which would silently LENGTHEN retention
//    for everybody who had chosen less, the opposite of the point.
rc_world($mysqli, $AK, '90', '30');
rc_check('an organisation holding ITSELF to 30 days keeps 30',
    AnonymousCheckins::readRetentionDays(1) === 30, (string) AnonymousCheckins::readRetentionDays(1));

rc_world($mysqli, $AK, '0', '1000');
rc_check('where the INSTALLATION allows for ever, 1000 days is allowed',
    AnonymousCheckins::readRetentionDays(1) === 1000, (string) AnonymousCheckins::readRetentionDays(1));

rc_world($mysqli, $AK, '0', '0');
rc_check('…and "for ever" survives where BOTH levels mean it',
    AnonymousCheckins::readRetentionDays(1) === 0, (string) AnonymousCheckins::readRetentionDays(1));

rc_world($mysqli, $AK, '90', null);
rc_check('an organisation with no row of its own follows the installation',
    AnonymousCheckins::readRetentionDays(1) === 90, (string) AnonymousCheckins::readRetentionDays(1));

// 🪤 A SECOND ORGANISATION, because with one the per-organisation lookup is
//    pinned by nothing: a reader that ignored `$siteId` entirely would pass
//    every check above.
rc_world($mysqli, $AK, '90', '1000');
$ins = $mysqli->prepare('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (2, ?, \'10\', \'\', 0)');
$ins->bind_param('s', $AK);
$ins->execute();
$ins->close();
rc_check('organisation 2\'s own 10 days is not confused with organisation 1\'s',
    AnonymousCheckins::readRetentionDays(2) === 10, (string) AnonymousCheckins::readRetentionDays(2));
rc_check('…and organisation 1 is still held to 90',
    AnonymousCheckins::readRetentionDays(1) === 90, (string) AnonymousCheckins::readRetentionDays(1));

// The stored figure, read at the organisation's OWN level, is what lets the page
// say a figure is being held down. It must NOT fall back to the installation's.
rc_check('ownRetentionDays() answers the stored 1000 for organisation 1',
    AnonymousCheckins::ownRetentionDays($mysqli, 1) === 1000,
    var_export(AnonymousCheckins::ownRetentionDays($mysqli, 1), true));
rc_world($mysqli, $AK, '90', null);
rc_check('…and null, never the installation\'s 90, for an organisation with no row',
    AnonymousCheckins::ownRetentionDays($mysqli, 1) === null,
    var_export(AnonymousCheckins::ownRetentionDays($mysqli, 1), true));

// =============================================================================
rc_head('B. #714/#722 — the transcript clear-out, and the "for ever" that could not happen');
// =============================================================================
rc_world($mysqli, $TK, '30', '9999');
rc_check('a hand-written transcript period above the ceiling is held to it',
    TranscriptRetention::daysForSite(1) === 30, (string) TranscriptRetention::daysForSite(1));

rc_world($mysqli, $TK, '30', '0');
rc_check('"for ever" does not survive a ceiling of 30 days',
    TranscriptRetention::daysForSite(1) === 30, (string) TranscriptRetention::daysForSite(1));

rc_world($mysqli, $TK, '30', '7');
rc_check('an organisation holding itself to 7 days keeps 7',
    TranscriptRetention::daysForSite(1) === 7, (string) TranscriptRetention::daysForSite(1));

// 🚨 THIS IS THE ONE THAT COULD NOT PASS BEFORE #722. `installationAllows()`
//    never answers 0 — it hands back the hard maximum where "for ever" is
//    stored, because what a form's `max` needs is a number of days — so the old
//    arithmetic here could never return 0, and `run_transcript_sweep()`'s
//    branch reading `$days <= 0` as "keeps them for ever, and the installation
//    allows that" was unreachable. An installation that chose for ever had every
//    transcript deleted at ten years.
rc_world($mysqli, $TK, '0', null);
rc_check('an installation that chooses "keep for ever" really keeps them for ever',
    TranscriptRetention::daysForSite(1) === 0, (string) TranscriptRetention::daysForSite(1));
rc_check('…and `installationAllowsForEver()` says so, where `installationAllows()` says 3650',
    TranscriptRetention::installationAllowsForEver() === true
    && TranscriptRetention::installationAllows() === TranscriptRetention::HARD_MAX_DAYS,
    (string) TranscriptRetention::installationAllows());

rc_world($mysqli, $TK, '0', '90');
rc_check('an organisation may still choose 90 days under an installation that allows for ever',
    TranscriptRetention::daysForSite(1) === 90, (string) TranscriptRetention::daysForSite(1));

rc_world($mysqli, $TK, '365', null);
rc_check('and with a real ceiling, "for ever" is not offered',
    TranscriptRetention::installationAllowsForEver() === false);

// 🪤 AN UNUSABLE OWN ROW MUST FOLLOW THE INSTALLATION, NOT THE BUILT-IN
//    DEFAULT — #668's first trap, and it bit this method's first version.
//    Answering the DAYS ceiling alone gave an installation that had chosen
//    "keep for ever" a period of 365 days for any organisation whose own row
//    held nonsense, because `installationAllows()` never answers 0. Both
//    ceiling questions are needed, and this is the only check that can tell.
rc_world($mysqli, $TK, '0', '-5');
rc_check('an unusable own figure under a "for ever" installation keeps them for ever',
    TranscriptRetention::daysForSite(1) === 0, (string) TranscriptRetention::daysForSite(1));
rc_world($mysqli, $TK, '30', 'twelve');
rc_check('…and under a real ceiling it follows that ceiling, never the 365-day default',
    TranscriptRetention::daysForSite(1) === 30, (string) TranscriptRetention::daysForSite(1));

// =============================================================================
rc_head('C. The save path: refuse with the reason, never clamp, and write nothing');
// =============================================================================
rc_world($mysqli, $TK, '90', null);

$refused = TranscriptRetention::saveForSite($mysqli, 1, 365);
rc_check('365 days is REFUSED on an installation allowing 90',
    $refused['saved'] === false && $refused['refusal'] === 'above-installation', $refused['message']);
rc_check('…the words say what the limit is and what to do about it',
    str_contains($refused['message'], '90 days') === true
    && str_contains($refused['message'], 'not saved') === true
    && str_contains($refused['message'], 'Ask whoever runs this installation') === true, $refused['message']);
rc_check('…and they never name the setting key or who set the limit',
    str_contains($refused['message'], $TK) === false
    && stripos($refused['message'], 'global administrator') === false, $refused['message']);
rc_check('…and NOTHING was written: no row for this organisation',
    TranscriptRetention::ownDays($mysqli, 1) === null,
    var_export(TranscriptRetention::ownDays($mysqli, 1), true));

$forEver = TranscriptRetention::saveForSite($mysqli, 1, 0);
rc_check('"for ever" is refused where the installation does not allow it',
    $forEver['saved'] === false && $forEver['refusal'] === 'above-installation', $forEver['message']);

$tooBig = TranscriptRetention::saveForSite($mysqli, 1, 99999);
rc_check('a figure above the hard maximum in code is refused, in DIFFERENT words',
    $tooBig['saved'] === false && $tooBig['refusal'] === 'above-hard-max'
    && str_contains($tooBig['message'], 'installation') === false, $tooBig['message']);

$ok = TranscriptRetention::saveForSite($mysqli, 1, 30);
rc_check('a SHORTER figure is stored',
    $ok['saved'] === true && TranscriptRetention::ownDays($mysqli, 1) === 30,
    var_export(TranscriptRetention::ownDays($mysqli, 1), true));
rc_check('…and the clear-out then uses it',
    TranscriptRetention::daysForSite(1) === 30, (string) TranscriptRetention::daysForSite(1));

$again = TranscriptRetention::saveForSite($mysqli, 1, 60);
rc_check('saving again CHANGES the one row rather than adding a second',
    $again['saved'] === true && TranscriptRetention::ownDays($mysqli, 1) === 60
    && rc_row_count($mysqli, $TK, 1) === 1, (string) rc_row_count($mysqli, $TK, 1));

$cleared = TranscriptRetention::saveForSite($mysqli, 1, null);
rc_check('an empty box removes the organisation\'s own row, so it follows the installation again',
    $cleared['saved'] === true && $cleared['cleared'] === true
    && TranscriptRetention::ownDays($mysqli, 1) === null
    && TranscriptRetention::daysForSite(1) === 90,
    var_export(TranscriptRetention::ownDays($mysqli, 1), true));

rc_world($mysqli, $TK, '0', null);
$forEverOk = TranscriptRetention::saveForSite($mysqli, 1, 0);
rc_check('"for ever" IS allowed where the installation itself allows for ever',
    $forEverOk['saved'] === true && TranscriptRetention::daysForSite(1) === 0, $forEverOk['message']);

/** How many rows of one setting one organisation has. */
function rc_row_count(mysqli $db, string $key, int $siteId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) AS n FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
    $stmt->bind_param('si', $key, $siteId);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();

    return $n;
}

// =============================================================================
rc_head('D. THE LOCK — a save waits for whoever is changing the ceiling, then obeys it');
// =============================================================================
// 🔒 WHY THIS IS HERE AT ALL (#687, measured rather than argued). A gate that
//    compares against the request's settings snapshot lets two overlapping
//    ordinary web requests store fifty years under a ceiling of seven — 60
//    pairs out of 60 — because neither sees what the other has just committed.
//    So the save re-reads the installation's row LIVE, `FOR UPDATE`, INSIDE the
//    transaction that writes. A lock outside a transaction is decoration (#697).
//
//    The second process holds that row and raises the ceiling to 7 while this
//    one tries to store 3000. With the lock, this one WAITS, then sees 7 and
//    refuses. Without it, it reads the old ceiling, succeeds, and the database
//    ends up holding 3000 under a ceiling of 7 — which is the fault.
//
//    ⚠️ WHAT THIS SECTION DOES NOT CATCH, measured by planting it: moving the
//       locked read OUTSIDE the transaction leaves every check here GREEN. A
//       `SELECT … FOR UPDATE` still blocks on the holder and still reads the
//       committed 7, so this scenario is answered correctly — what is lost is
//       that the lock is RELEASED at once by the implicit commit, leaving a
//       window between reading the ceiling and writing the figure. That is
//       #697's "a lock outside a transaction is decoration", and the only
//       thing that catches it is the ORDERING check in section E. Both are
//       needed, and neither covers the other's half.
rc_world($mysqli, $TK, '3650', null);

$holdMs  = 3000;
$cmd     = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
         . ' --hold-ceiling 7 ' . $holdMs;
$env     = [
    'SELFTEST_DB_NAME' => $dbName,
    'SELFTEST_DB_HOST' => $rcHost,
    'SELFTEST_DB_USER' => $rcUser,
    'SELFTEST_DB_PASS' => $rcPass,
    'SELFTEST_DB_PORT' => (string) $rcPort,
];
$pipes   = [];
$holder  = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env + $_ENV);
rc_check('the second process started, so there is really another connection holding the row',
    is_resource($holder) === true);

// Long enough for the holder to have taken the lock and raised the ceiling, and
// well short of the moment it commits.
usleep(800_000);

$started = microtime(true);
$raced   = TranscriptRetention::saveForSite($mysqli, 1, 3000);
$waited  = microtime(true) - $started;

if (is_resource($holder) === true) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($holder);
}

// ⚠️ THIS CHECK IS CONTEXT, NOT THE PROOF, and saying so is the point.
//    MEASURED: with ` FOR UPDATE` deleted from the locked read, this check still
//    PASSED — the save waited anyway, because the holder's `UPDATE` on
//    `uq_settings_key_site` locks the gap beside its own row, so the saver's
//    INSERT for organisation 1 blocked instead. So "it waited" cannot tell a
//    real lock from a coincidence of a neighbouring gap, and a test that stopped
//    here would have reported the lock as proved while the fault was live. The
//    THREE checks below are the discriminators: in the planted state they went
//    red with 3000 days STORED under a ceiling of 7 and the save reported as
//    successful — #687's fault, exactly.
rc_check('the save did not finish before the other connection committed',
    $waited > 1.0, 'it took ' . number_format($waited, 2) . 's, and the holder was still in its '
    . number_format($holdMs / 1000, 1) . 's window when this started');
rc_check('…and then refused, because by the time it could read, the ceiling was 7',
    $raced['saved'] === false && $raced['refusal'] === 'above-installation', $raced['message']);
rc_check('…naming the LIVE ceiling of 7, not the 3650 this request started with',
    str_contains($raced['message'], '7 days') === true
    && str_contains($raced['message'], '3650') === false, $raced['message']);
rc_check('…and 3000 is NOT in the database',
    TranscriptRetention::ownDays($mysqli, 1) === null,
    var_export(TranscriptRetention::ownDays($mysqli, 1), true));

// The committed ceiling really is 7 afterwards, so the holder did what it said
// it would. Without this the two checks above could pass on a holder that had
// quietly failed and a saver refusing for some other reason.
$check = $mysqli->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
$check->bind_param('s', $TK);
$check->execute();
$ceilingNow = (string) ($check->get_result()->fetch_assoc()['settingValue'] ?? '');
$check->close();
rc_check('the other connection\'s change really did commit, so the window was real',
    $ceilingNow === '7', $ceilingNow);

// =============================================================================
rc_head('E. The page asks both questions, and the handler acts on the answer');
// =============================================================================
// Sections A to D exercise behaviour. They cannot tell you the page still asks
// the right questions or that its handler acts on the refusal. Comments are
// stripped first, so a call that has been commented out never counts.
$rcStrip = static function (string $path): string {
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $t) {
        if (is_array($t) === true && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) === true) {
            continue;
        }
        $out .= is_array($t) === true ? $t[1] : $t;
    }

    return $out;
};
$pageFile    = dirname(__DIR__) . '/web/_apps/admin/settings/transcription/index.php';
$handlerFile = dirname(__DIR__) . '/web/_apps/admin/settings/transcription/save.php';
$page        = $rcStrip($pageFile);
$handler     = $rcStrip($handlerFile);
$pageRaw     = (string) file_get_contents($pageFile);

// TWO QUESTIONS, NOT ONE. `min="0"` offers for-ever whatever the max says, so a
// page that asked only for the ceiling in days would offer a control its own
// Save refuses.
rc_check('the page asks what the installation ALLOWS, in days',
    str_contains($page, 'TranscriptRetention::installationAllows()') === true);
rc_check('…and SEPARATELY whether it allows "for ever"',
    str_contains($page, 'TranscriptRetention::installationAllowsForEver()') === true);
rc_check('the box\'s max is the installation\'s ceiling, never the hard maximum in code',
    preg_match('/max="<\?php echo \(int\) \$ceilingDays; \?>"/', $pageRaw) === 1);
rc_check('…and its min is 0 only where "for ever" is allowed',
    preg_match('/min="<\?php echo \$mayKeepForEver === true \? 0 : 1; \?>"/', $pageRaw) === 1);

// 🚨 NEVER PREFILL A VALUE THE BOX\'S OWN `max` FORBIDS. A browser then refuses
//    to submit the whole form, so the only way to use the page is to shorten
//    the period — the one thing an administrator must not be forced into. The
//    value shown is the stored figure held to the ceiling, and the whole
//    expression is pinned, because `min(` alone passes on `min($ownDays, 99999)`.
rc_check('the value shown is the stored figure held to the ceiling',
    preg_match('/\$shown\s*=\s*\(string\)\s*\(\s*\$mayKeepForEver\s*===\s*false\s*&&\s*\$ownDays\s*===\s*0\s*\?\s*\$ceilingDays\s*:\s*min\(\$ownDays,\s*\$ceilingDays\)\s*\)\s*;/', $page) === 1);
rc_check('…read at the organisation\'s OWN level, not from the merged settings',
    str_contains($page, 'TranscriptRetention::ownDays($mysqli, $siteId)') === true
    && str_contains($page, "App::settings('transcription") === false);
rc_check('…and the page says so when a stored figure is being held down',
    str_contains($page, '$heldDown') === true
    && str_contains($pageRaw, 'longer than this installation allows') === true);

// The handler must ASK WHETHER THE FIELD ARRIVED, never only what it holds: a
// disabled control is not submitted, and reading an absent field as empty is
// how `site.url` was cleared for every organisation once (#677).
rc_check('the handler asks whether the field ARRIVED before reading it',
    preg_match('/if\s*\(\s*array_key_exists\(\s*\x27transcriptDays\x27\s*,\s*\$_POST\s*\)\s*===\s*false\s*\)\s*\{/', $handler) === 1);
rc_check('the handler checks the form token BEFORE anything with a side effect',
    (($c = strpos($handler, 'Auth::verifyCsrf')) !== false)
    && (($l = strpos($handler, 'Logger::activity')) !== false)
    && (($s = strpos($handler, 'TranscriptRetention::saveForSite')) !== false)
    && $c < $l && $c < $s);

// 🚨 PIN THE WHOLE CONDITION, not the call. The independent check of #668 proved
//    that asking only whether the text is present passes on four different
//    disabled gates: `if (false)`, `&& false` added to the condition, a refusal
//    that never exits so it logs "nothing was saved" and then writes anyway, and
//    a refusal that silently CLAMPS instead.
rc_check('it hands the posted number to saveForSite() exactly as typed, or null for an empty box',
    preg_match('/\$result\s*=\s*TranscriptRetention::saveForSite\(\$mysqli,\s*\$siteId,\s*\$days\)\s*;/', $handler) === 1);
rc_check('…and the condition on the answer is EXACTLY "was it saved", with nothing added to weaken it',
    preg_match('/if\s*\(\s*\$result\[\x27saved\x27\]\s*===\s*false\s*\)\s*\{/', $handler) === 1);
rc_check('it does NOT convert 0 itself: the zero rule belongs in SettingLimit, read at both levels',
    preg_match('/\$days\s*===\s*0\s*\?\s*TranscriptRetention::HARD_MAX_DAYS/', $handler) !== 1);
rc_check('it RECORDS a refusal', str_contains($handler, "'TranscriptRetentionRefused'") === true);
rc_check('…and records a save too, so a log search by type is not a count of refusals only',
    str_contains($handler, "'TranscriptRetentionSaved'") === true);

// The refusal block must END the request. One that logs and falls through writes
// anyway while the record says nothing was saved — the worst of the four shapes.
$refusalBlock = (static function (string $code): string {
    $at = strpos($code, "if (\$result['saved'] === false) {");
    if ($at === false) {
        return '';
    }
    $close = strpos($code, "\n}", $at);

    return $close === false ? '' : substr($code, $at, $close - $at);
})($handler);
rc_check('the refusal block ends the request rather than falling through',
    $refusalBlock !== '' && str_contains($refusalBlock, 'exit();') === true,
    $refusalBlock === '' ? 'the block could not be found, so nothing was checked' : 'block is ' . strlen($refusalBlock) . ' chars');
// `=(?!=)` — an ASSIGNMENT, not a comparison. Without the lookahead this matches
// the `$days === 0` inside the refusal's own message and fails on correct code.
rc_check('…and it does not quietly CLAMP the number on the way past',
    $refusalBlock !== '' && preg_match('/\$days\s*=(?!=)/', $refusalBlock) !== 1);

// 🚧 #685's NOTICE HALF, on the attendance page. The cap SHORTENS retention of
//    data already held, so the page has to say when the number it shows is not
//    the number the organisation chose — otherwise it reads "90 days — your
//    organisation's own choice" to somebody who chose 1000, which is two true
//    halves making a false sentence. Nothing else checks that sentence.
$attendance = (string) file_get_contents(dirname(__DIR__) . '/web/_apps/admin/settings/attendance/index.php');
rc_check('the attendance page reads the stored figure at the organisation\'s OWN level',
    str_contains($attendance, 'AnonymousCheckins::ownRetentionDays($mysqli, $siteId)') === true);
rc_check('…and says so when that figure is being held down by the installation',
    preg_match('/\$retentionHeldDown\s*=\s*\$ownRetentionMeant\s*!==\s*null\s*&&\s*\$ownRetentionMeant\s*!==\s*\(int\)\s*\$effectiveRetention\s*;/', $attendance) === 1
    && str_contains($attendance, 'longer than this installation allows') === true);

// -----------------------------------------------------------------------------
// 🚧 "0 MEANS KEEP FOR EVER" — THE SENTENCE #685 MADE UNTRUE AND LEFT STANDING
// -----------------------------------------------------------------------------
// Measured on the rule itself: 0 is the LARGEST value for this setting, so it
// survives ONLY where the installation allows for ever. With the installation
// at 90 — which is what `full_schema.sql` seeds, so every fresh installation —
// an organisation storing 0 is cleared at 90 DAYS. Yet
// `admin/maintenance/retention.php` went on ending its paragraph "Each
// organisation uses its own setting, and 0 means keep for ever", and the
// personal-data catalogue said the same in BOTH its `reason` and its `period`.
//
// Those last two feed `inventory()`, which is the "what we hold about you"
// answer a person receives, so the overstatement was a promise made to a data
// subject. #706's own rule is that a `period` must be checked against the
// purging code; #685 changed the purging code.
//
// ⚠️ THE CHECK IS A POSITIVE CLAUSE FIRST, THEN THE ABSENCE. A check made only
//    of absences passes when the sentence stops being printed at all (#694's
//    section-P lesson), and this page's whole job is to say what the clear-out
//    will do — so it has to still SAY something. The forbidden wording is the
//    bare assertion with no condition attached; the conditional forms
//    ("only where the installation allows it", "does NOT mean keep for ever
//    here") are what the page and the catalogue say now and must keep saying.
// ⚠️ THE CARD IS READ WITH ITS PHP COMMENTS STRIPPED, and the first version of
//    this check was not — so it went red on the correct page, because the
//    comment explaining the fix QUOTES the untrue sentence it replaced. That
//    quote is the valuable part of the comment (it is how the next reader knows
//    what not to put back), so the check has to look at the PROSE the page
//    prints, not at the file.
$maintenanceCard = $rcStrip(dirname(__DIR__) . '/web/_apps/admin/maintenance/retention.php');
rc_check('the maintenance card still states what happens to anonymous check-in detail at all',
    str_contains($maintenanceCard, 'attend.detailRetentionDays') === true
    && str_contains($maintenanceCard, 'AnonymousCheckins::readRetentionDays(Site::id())') === true);
rc_check('…and asks the installation\'s own allowance rather than describing it',
    str_contains($maintenanceCard, 'SettingLimit::installationAllowsForEver(') === true
    && str_contains($maintenanceCard, 'SettingLimit::installationAllows(') === true);
rc_check('…and never asserts bare "0 means keep for ever", which #685 made untrue',
    str_contains($maintenanceCard, 'its own setting, and 0 means keep for ever') === false
    && str_contains($maintenanceCard, '0 means keep for ever.') === false);
// ⚠️ THE CATALOGUE IS ASKED FOR ITS ASSEMBLED VALUES, NEVER SEARCHED AS SOURCE.
//    The first version of these three searched the file for
//    'only where the installation allows it too' and went red on the correct
//    entry, because the sentence is built from two concatenated string
//    literals and the phrase straddles the join. That is the repository's own
//    rule — print the outputs by calling the real code rather than
//    transcribing them — and it matters more here than usual, because these
//    two sentences are what `inventory()` hands a data subject.
$rcCatalogueEntry = (require $rcCore . 'personal-data-catalogue.php')['tblAnonymousCheckins'];
$rcReason = (string) $rcCatalogueEntry['reason'];
$rcPeriod = (string) $rcCatalogueEntry['period'];
rc_check('the personal-data catalogue still gives tblAnonymousCheckins a period at all',
    str_contains($rcPeriod, 'attend.detailRetentionDays') === true
    && str_contains($rcPeriod, '90 days by default') === true);
rc_check('…and neither its reason nor its period claims 0 keeps for ever unconditionally',
    str_contains($rcReason, '0 means keep for ever') === false
    && str_contains($rcPeriod, '0 means keep for ever') === false
    && str_contains($rcPeriod, '0 = keep for ever') === false);
rc_check('…and both name the installation\'s cap, which is the only true form',
    str_contains($rcReason, 'held down to') === true
    && str_contains($rcPeriod, 'only where the installation allows it too') === true,
    $rcPeriod);

// 🔒 And the lock itself, pinned WHOLE: the five words are what make section D
//    able to fail, and ` FOR UPDATE` is one edit away from being gone.
$retention = (string) file_get_contents($rcCore . 'TranscriptRetention.php');
rc_check('the live ceiling is read with the installation\'s own row LOCKED',
    str_contains($retention, "'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL FOR UPDATE'") === true);
rc_check('…and the transaction is opened BEFORE that read, because a lock outside one is decoration',
    (($b = strpos($retention, '$db->begin_transaction();')) !== false)
    && (($r = strpos($retention, 'self::installationCeilingLocked($db)')) !== false)
    && $b < $r, 'begin at ' . var_export($b ?? null, true) . ', locked read at ' . var_export($r ?? null, true));
rc_check('…and the refusal inside the transaction rolls it back',
    preg_match('/if\s*\(\$reason\s*!==\s*\x27\x27\)\s*\{\s*\$db->rollback\(\);/', $rcStrip($rcCore . 'TranscriptRetention.php')) === 1);

// =============================================================================
echo "\n" . $rcPasses . ' passed, ' . $rcFailures . ' failed, ' . ($rcPasses + $rcFailures)
    . ' ran of ' . RC_EXPECTED_CHECKS . " expected.\n";
if ($rcPasses + $rcFailures !== RC_EXPECTED_CHECKS) {
    echo "FAIL — the number of checks that ran is not the number this file expects.\n";
    exit(1);
}
if ($rcFailures > 0) {
    exit(1);
}
echo 'PASS — all ' . RC_EXPECTED_CHECKS . " checks passed.\n";
exit(0);
