<?php
// Path: tools/rate-limit-privacy-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A rate-limit row never holds a network address 🔒 (#712)
 * -----------------------------------------------------------------------------
 * Exercises `LiveChat::senderFingerprint()`, `LiveChat::recordSend()` and
 * `LiveChat::isRateLimited()` against a REAL MySQL database.
 *
 * WHAT WAS WRONG. `tblLiveRateLimits` recorded the viewer's network address
 * UNCHANGED, and nothing ever removed a row: no clear-out anywhere, no foreign
 * key that could cascade in, no scheduled job, no admin page. So every
 * installation kept a growing record of which addresses had used the live chat,
 * for ever, outside every retention rule the portal has - under an index named
 * `idx_ratelimit_prune`, for a prune that did not exist. `recordSend()`'s own
 * docblock claimed the pruning already happened; the time window only ever
 * narrowed the COUNT the throttle reads.
 *
 * WHY A TEST AND NOT ONLY A CHECK. The two halves fail differently.
 * `tools/audit-checks/check_no_raw_address_stored.py` reads the code and would
 * notice an address being stored again. It cannot tell you the THROTTLE STILL
 * WORKS once the value is hashed, which is the thing that would quietly break
 * and let somebody flood the chat - nothing would log it and no page would
 * change. That is what sections B and C are for.
 *
 * WHAT IT NEEDS: php and a throwaway MySQL database whose name starts with
 * `selftest_`, loaded from `web/_sql/full_schema.sql`. No web server, no
 * browser, no network.
 *
 * WHAT IT CANNOT PROVE, said plainly:
 *   - Nothing here touches `tblApiRateLimits`. Its two faults were in the
 *     bucket NAMES built by three pages (`auth/2fa/verify.php`,
 *     `push/api/subscribe.php`, `push/api/unsubscribe.php`), and reaching them
 *     needs the HTTP harness. The audit check covers them by reading the code,
 *     and names each one.
 *   - Section D's prune is opportunistic - about one send in 64 pays for it -
 *     so it is driven by repetition rather than by forcing the branch. With
 *     1,000 sends the chance of the prune never running at all is about one in
 *     eight million ((63/64)^1000). Not deterministic; stated rather than
 *     hidden.
 *   - It says nothing about `tblLiveChatMessages.senderIP`, which keeps the
 *     address RAW on purpose for the moderation screen. Changing that would be
 *     a different decision, not a fix.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_rl SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/rate-limit-privacy-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/712
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const RL_EXPECTED_CHECKS = 15;

/** The addresses this test uses. Documentation addresses (RFC 5737), never real ones. */
const RL_IP_ONE = '192.0.2.44';
const RL_IP_TWO = '198.51.100.7';

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test writes and deletes rows, so it only ever runs on a throwaway database.\n";
    exit(1);
}

$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);

$passes   = 0;
$failures = 0;

function rl_check(string $what, bool $isOk, string $detail = ''): void
{
    global $passes, $failures;
    if ($isOk === true) {
        $passes++;
        echo '  ✓ ' . $what . "\n";

        return;
    }
    $failures++;
    echo '  ✗ ' . $what . ($detail === '' ? '' : '  —  ' . $detail) . "\n";
}

function rl_head(string $text): void
{
    echo "\n" . $text . "\n";
}

// 🧰 The portal's own classes, loaded directly. LiveChat needs Settings for the
//    throttle window and Logger for a prepare failure; both are satisfied by
//    bootstrap's own files rather than by stubs, so what runs here is what runs
//    on a real request.
$core = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
      . DIRECTORY_SEPARATOR;
// 🧂 `AddressFingerprint` salts with the installation's key, read from
//    PORTAL_ROOT. There is no installed portal here, so the key file is absent
//    and the class falls back to a per-release salt — which is exactly the
//    documented fallback, and the test cares only that the value is stable and
//    is not the address.
if (defined('PORTAL_ROOT') === false) {
    define('PORTAL_ROOT', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web');
}
require_once $core . 'AddressFingerprint.php';
require_once $core . 'InstallationSettings.php';
require_once $core . 'App.php';
require_once $core . 'Settings.php';
require_once $core . 'Logger.php';
require_once $core . 'LiveChat.php';

use Portal\Core\App;
use Portal\Core\InstallationSettings;
use Portal\Core\LiveChat;

// 🪤 `Settings::get()` reads the GLOBAL `$SETTINGS` and nothing else - not
//    `App::init()`'s copy, which is what a first version of this test set. The
//    seeded default is 5 messages per window, so the caps were 5 and 15 rather
//    than the 3 and 9 the test was asserting, and two checks failed for a
//    reason that had nothing to do with the fix. Set the global.
$chatSettings = ['chat' => ['maxMsgsPerWindow' => '3', 'windowSeconds' => '60']];
$GLOBALS['SETTINGS'] = $chatSettings;
InstallationSettings::remember($chatSettings);
App::init($mysqli, $chatSettings);

$mysqli->query('DELETE FROM tblLiveRateLimits');

// -----------------------------------------------------------------------------
rl_head('A. What is stored is a fingerprint, and never the address');
// -----------------------------------------------------------------------------
$fp = LiveChat::senderFingerprint(RL_IP_ONE);
rl_check('it is 64 characters of hex', preg_match('/^[0-9a-f]{64}$/', $fp) === 1, $fp);
rl_check('it is not the address', $fp !== RL_IP_ONE);
rl_check('the same address always gives the same value',
    $fp === LiveChat::senderFingerprint(RL_IP_ONE));
rl_check('a different address gives a different value',
    $fp !== LiveChat::senderFingerprint(RL_IP_TWO));
rl_check('"no address" stays empty, so it can be kept as NULL',
    LiveChat::senderFingerprint('') === '');

LiveChat::recordSend($mysqli, 1, 'tok-A', RL_IP_ONE);
$row = $mysqli->query('SELECT senderFingerprint FROM tblLiveRateLimits ORDER BY limitID DESC LIMIT 1')
    ->fetch_assoc();
$stored = (string) ($row['senderFingerprint'] ?? '');
rl_check('a recorded send stores the fingerprint', $stored === $fp, 'stored: ' . $stored);
// The decisive one: whatever else changes, the stored value must be a
// fingerprint and nothing else.
//
// 🪤 ASK FOR THE SHAPE, NOT FOR A DOT. A first version asked
//    `LIKE '%.%'` and was labelled "nothing shaped like an address" — which an
//    IPv6 address passes straight through, because it has colons and no dots.
//    `RateLimiter::clientIp()` returns `REMOTE_ADDR` verbatim, so IPv6 is an
//    ordinary case and not an exotic one, and both fixtures here are IPv4 so
//    the generality was never exercised. An independent check measured it:
//    `2001:db8::dead:beef` gave a count of 0.
$notAFingerprint = (int) $mysqli->query(
    "SELECT COUNT(*) AS n FROM tblLiveRateLimits "
    . "WHERE senderFingerprint IS NOT NULL AND senderFingerprint NOT REGEXP '^[0-9a-f]{64}$'"
)->fetch_assoc()['n'];
rl_check('EVERY STORED VALUE IS A FINGERPRINT AND NOTHING ELSE — any address, of either '
    . 'family, would fail this', $notAFingerprint === 0,
    $notAFingerprint . ' row(s) hold something that is not 64 hex characters');

// -----------------------------------------------------------------------------
rl_head('B. The throttle still works by address after hashing');
// -----------------------------------------------------------------------------
// The IP cap is maxMsgs * 3 = 9. Each send comes from a DIFFERENT token, which
// is the case the address branch exists for: somebody opening a new tab to get
// a fresh token and carrying on.
$mysqli->query('DELETE FROM tblLiveRateLimits');
for ($i = 0; $i < 8; $i++) {
    LiveChat::recordSend($mysqli, 1, 'tok-B' . $i, RL_IP_ONE);
}
rl_check('eight sends from one address, each on its own token, are not throttled yet',
    LiveChat::isRateLimited($mysqli, 'tok-B-next', RL_IP_ONE) === false);
LiveChat::recordSend($mysqli, 1, 'tok-B8', RL_IP_ONE);
rl_check('the ninth takes it to the cap, so the address IS throttled',
    LiveChat::isRateLimited($mysqli, 'tok-B-next', RL_IP_ONE) === true);
rl_check('and a DIFFERENT address is not caught by it',
    LiveChat::isRateLimited($mysqli, 'tok-B-other', RL_IP_TWO) === false);

// -----------------------------------------------------------------------------
rl_head('C. The token branch still works, and is not confused by the hashing');
// -----------------------------------------------------------------------------
$mysqli->query('DELETE FROM tblLiveRateLimits');
for ($i = 0; $i < 3; $i++) {
    LiveChat::recordSend($mysqli, 1, 'tok-C', RL_IP_TWO);
}
rl_check('three sends on one token reach the per-token cap',
    LiveChat::isRateLimited($mysqli, 'tok-C', RL_IP_TWO) === true);
rl_check('while a different token from the same address is still allowed',
    LiveChat::isRateLimited($mysqli, 'tok-C2', RL_IP_TWO) === false);

// -----------------------------------------------------------------------------
rl_head('D. Old rows are cleared out — and only the old ones');
// -----------------------------------------------------------------------------
// 🪤 THIS SECTION'S FIRST VERSION COULD NOT FAIL, and an independent check
//    measured it. It inserted one day-old row, sent until that row went, and
//    then asked "are any recent rows left?" — but every row it had just created
//    was seconds old, so no positive horizon could ever remove one. Planted
//    with `$keepSeconds = 0`, a prune that wipes the whole throttle log on every
//    1-in-64 send, it passed 15 of 15 three runs running; planted with a
//    quarter of the window, the same.
//
//    So the fixture now has rows at KNOWN AGES EITHER SIDE of the horizon. The
//    window is 60 seconds and the prune keeps twice that, so:
//      - a row 180 seconds old is past the horizon and MUST go;
//      - a row 90 seconds old is inside it and MUST SURVIVE.
//    A horizon that is too generous leaves the first; one that is too keen
//    takes the second. Either way a check goes red.
$mysqli->query('DELETE FROM tblLiveRateLimits');
$plant = static function (string $token, int $ageSeconds) use ($mysqli): void {
    $stmt = $mysqli->prepare(
        'INSERT INTO tblLiveRateLimits (siteID, sessionToken, senderFingerprint, eventType, createdAt) '
        . 'VALUES (1, ?, REPEAT(?, 64), ?, NOW() - INTERVAL ? SECOND)'
    );
    $one  = 'a';
    $kind = 'chat.send';
    $stmt->bind_param('sssi', $token, $one, $kind, $ageSeconds);
    $stmt->execute();
    $stmt->close();
};
$plant('tok-OLD', 180);
$plant('tok-RECENT', 90);

$stillThere = static fn (string $token): bool => (int) ($mysqli->query(
    "SELECT COUNT(*) AS n FROM tblLiveRateLimits WHERE sessionToken = '" . $token . "'"
)->fetch_assoc()['n'] ?? 0) > 0;

rl_check('both planted rows are there to begin with',
    $stillThere('tok-OLD') === true && $stillThere('tok-RECENT') === true);

// The tidy-up runs on about one send in 64, so it is driven by repetition. With
// 1,000 sends the chance of it never running is (63/64)^1000, about 1 in 6.9
// million. Not deterministic, and said rather than hidden.
$ranAtAll = false;
for ($i = 0; $i < 1000; $i++) {
    LiveChat::recordSend($mysqli, 1, 'tok-D' . $i, RL_IP_ONE);
    if ($stillThere('tok-OLD') === false) {
        $ranAtAll = true;
        break;
    }
}
rl_check('THE ROW PAST THE HORIZON IS GONE (nothing cleared this table before #712)',
    $ranAtAll === true, 'still there after 1,000 sends');
rl_check('AND THE ROW INSIDE THE HORIZON SURVIVED — the throttle can still count it',
    $stillThere('tok-RECENT') === true,
    'the 90-second-old row was taken, so the tidy-up is reaching rows the throttle needs');

$mysqli->query('DELETE FROM tblLiveRateLimits');

// -----------------------------------------------------------------------------
echo "\n" . str_repeat('=', 78) . "\n";
$ran = $passes + $failures;
echo $passes . ' passed, ' . $failures . ' failed, ' . $ran . ' ran of '
    . RL_EXPECTED_CHECKS . " expected.\n";
if ($failures === 0 && $ran === RL_EXPECTED_CHECKS) {
    echo "PASS — no rate-limit row holds an address, and the throttle still works."
        . " REACHED THE END.\n";
    exit(0);
}
echo 'FAIL' . ($ran !== RL_EXPECTED_CHECKS
    ? " — the run did not reach its end, or a check was added without changing"
        . " RL_EXPECTED_CHECKS.\n"
    : ".\n");
exit(1);
