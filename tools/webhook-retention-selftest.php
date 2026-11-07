<?php
// Path: tools/webhook-retention-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A webhook's payload is kept for a bounded time 🧾 (#713)
 * -----------------------------------------------------------------------------
 * Exercises `Portal\Core\WebhookRetention` and the webhook part of
 * `run_retention_sweep()` against a REAL MySQL database.
 *
 * WHAT WAS WRONG. Two tables kept a webhook's whole body for ever. On the way
 * in, `tblWebhookEvent.payload` is the entire raw HTTP body of a payment
 * webhook, and a Stripe or PayPal event routinely carries a donor's name,
 * e-mail address and postal address. On the way out,
 * `tblWebhookDeliveries.payload` demonstrably carries a child's name, a raw
 * e-mail address or a prayer-request subject. Nothing removed either: no
 * DELETE against either table anywhere under `web/`, no foreign key on
 * `tblWebhookEvent` at all, and for a delivery only the cascade when an
 * administrator deleted the parent webhook.
 *
 * NEITHER TABLE HAS A COLUMN LINKING A ROW TO A PERSON, so an erasure request
 * cannot reach them and an instruction promising it would be the #535 fault. A
 * time limit is the only mechanism there is.
 *
 * WHAT THIS PROVES THAT A CODE-READING CHECK CANNOT
 *   - the three-tier cap really binds: an organisation cannot keep payloads
 *     longer than the installation allows, including when its row was written
 *     by hand or restored from a backup (section B);
 *   - "for ever" survives only where BOTH levels mean it (section B);
 *   - the sweep empties what it should AND NOT what it should not. Section D is
 *     the one that matters: a delivery still PENDING or FAILED must never be
 *     emptied, because `cron/webhook-retry.php` re-sends from the stored
 *     payload - empty it and every later retry sends nothing for ever while
 *     reporting that it tried.
 *
 * WHAT IT CANNOT PROVE, said plainly:
 *   - It does not run the admin page. The two save-time refusals (#668's "a
 *     save-time cap is not a cap" works both ways) are covered by reading, not
 *     by HTTP. That is a gap and it is recorded as one.
 *   - It does not prove `Payments::ingestWebhook()` stores a body only once
 *     verified: that needs a signed Stripe or PayPal request. Section E reads
 *     the statement's own column list instead and says so.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_webhookret SELFTEST_DB_PORT=… \
 *             php tools/webhook-retention-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/713
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const WR_EXPECTED_CHECKS = 33;

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
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

function wr_check(string $what, bool $isOk, string $detail = ''): void
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

function wr_head(string $text): void
{
    echo "\n" . $text . "\n";
}

$core = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
      . DIRECTORY_SEPARATOR;
require_once $core . 'InstallationSettings.php';
require_once $core . 'App.php';
require_once $core . 'SettingLimit.php';
require_once $core . 'WebhookRetention.php';
require_once $core . 'TranscriptRetention.php';
// 🧒 #816: named in `_retention-sweep.php`'s list of what it needs.
require_once $core . 'RegistrationRetention.php';

use Portal\Core\App;
use Portal\Core\InstallationSettings;
use Portal\Core\TranscriptRetention;
use Portal\Core\WebhookRetention;

/** Stand the two layers up the way bootstrap does: installation alone, and merged. */
function wr_layers(mysqli $db, ?string $installation, ?string $own): void
{
    $layer = $installation === null ? [] : ['webhooks' => ['payload' => ['retentionDays' => $installation]]];
    // The merged array is the organisation's own value where it has one, and
    // the installation's where it has not — which is what bootstrap produces.
    $effective = $own ?? $installation;
    $merged = $effective === null ? [] : ['webhooks' => ['payload' => ['retentionDays' => $effective]]];
    InstallationSettings::remember($layer);
    App::init($db, $merged);
}

// -----------------------------------------------------------------------------
wr_head('A. The built-in answer when nobody has chosen');
// -----------------------------------------------------------------------------
wr_layers($mysqli, null, null);
wr_check('the default is 90 days, not "for ever"',
    WebhookRetention::daysForThisOrganisation() === 90,
    (string) WebhookRetention::daysForThisOrganisation());
wr_check('and "for ever" is NOT allowed by default',
    WebhookRetention::installationAllowsForEver() === false);

// -----------------------------------------------------------------------------
wr_head('B. The installation caps the organisation, and never the other way round');
// -----------------------------------------------------------------------------
wr_layers($mysqli, '30', '7');
wr_check('an organisation may hold itself to LESS than the installation',
    WebhookRetention::daysForThisOrganisation() === 7,
    (string) WebhookRetention::daysForThisOrganisation());

wr_layers($mysqli, '30', '365');
wr_check('and may NOT hold itself to more: the installation wins',
    WebhookRetention::daysForThisOrganisation() === 30,
    (string) WebhookRetention::daysForThisOrganisation());
wr_check('a figure above the ceiling is refused with a reason, not clamped',
    WebhookRetention::whyNotAllowed(365) !== '',
    WebhookRetention::whyNotAllowed(365));
wr_check('and a figure at or under the ceiling is allowed',
    WebhookRetention::whyNotAllowed(30) === '');

// ♾️ Zero is the LARGEST value, which is #668's trap in both directions.
wr_layers($mysqli, '30', '0');
wr_check('"for ever" is REFUSED where the installation sets a period',
    WebhookRetention::daysForThisOrganisation() === 30
    && WebhookRetention::whyNotAllowed(0) !== '',
    'effective ' . WebhookRetention::daysForThisOrganisation()
    . '; why: ' . WebhookRetention::whyNotAllowed(0));

wr_layers($mysqli, '0', '0');
wr_check('and allowed where the installation itself allows for ever',
    WebhookRetention::daysForThisOrganisation() === 0
    && WebhookRetention::whyNotAllowed(0) === '');

wr_layers($mysqli, '0', '45');
wr_check('an organisation may still choose a period under an unlimited installation',
    WebhookRetention::daysForThisOrganisation() === 45,
    (string) WebhookRetention::daysForThisOrganisation());

// -----------------------------------------------------------------------------
wr_head('C. The clear-out reads ONE organisation at a time, capped');
// -----------------------------------------------------------------------------
// A scheduled job has no current organisation and $SETTINGS is merged once for
// the whole run (#683), so the job must ask by number. Here the ROW is written
// by hand, which is exactly the case a save-time cap cannot reach (#668).
$mysqli->query('DELETE FROM tblSettings WHERE settingKey = ' . "'" . WebhookRetention::KEY . "'");
$mysqli->query(
    "INSERT INTO tblSettings (siteID, settingKey, settingValue) VALUES "
    . "(NULL, '" . WebhookRetention::KEY . "', '30'), (1, '" . WebhookRetention::KEY . "', '9999')"
);
wr_layers($mysqli, '30', '30');
wr_check('a hand-written row ABOVE the ceiling is still held to the ceiling',
    WebhookRetention::daysForSite(1) === 30, (string) WebhookRetention::daysForSite(1));

$mysqli->query('UPDATE tblSettings SET settingValue = ' . "'5'" . ' WHERE siteID = 1 AND settingKey = '
    . "'" . WebhookRetention::KEY . "'");
wr_check('and a shorter row of its own is honoured',
    WebhookRetention::daysForSite(1) === 5, (string) WebhookRetention::daysForSite(1));

$mysqli->query('DELETE FROM tblSettings WHERE siteID = 1 AND settingKey = '
    . "'" . WebhookRetention::KEY . "'");
wr_check('an organisation with no row of its own follows the installation',
    WebhookRetention::daysForSite(1) === 30, (string) WebhookRetention::daysForSite(1));

wr_check('incoming payment events use the installation and nothing else',
    WebhookRetention::daysForPaymentEvents() === 30,
    (string) WebhookRetention::daysForPaymentEvents());

// -----------------------------------------------------------------------------
wr_head('D. The sweep empties what it should, and NOT what it must not');
// -----------------------------------------------------------------------------
$mysqli->query('DELETE FROM tblWebhookDeliveries');
$mysqli->query('DELETE FROM tblWebhookEvent');
$mysqli->query('DELETE FROM tblWebhooks');
$mysqli->query(
    "INSERT INTO tblWebhooks (webhookID, siteID, name, eventTypes, targetUrl, signingSecret, isActive) "
    . "VALUES (9901, 1, 'test', 'all', 'https://example.com/h', 'secret', 1)"
);
// Four deliveries, all old enough to be past the period. Only the first two may
// be emptied; the other two are still going to be retried.
foreach ([['delivered', 'D1'], ['dead', 'D2'], ['pending', 'D3'], ['failed', 'D4']] as [$status, $mark]) {
    $stmt = $mysqli->prepare(
        'INSERT INTO tblWebhookDeliveries (webhookID, eventType, payload, responseSnippet, status, createdAt) '
        . 'VALUES (9901, ?, ?, ?, ?, NOW() - INTERVAL 400 DAY)'
    );
    $type = 'prayer-requests.created';
    $body = '{"subject":"' . $mark . ' a person named here"}';
    $snip = $mark . ' whatever the other system said';
    $stmt->bind_param('ssss', $type, $body, $snip, $status);
    $stmt->execute();
    $stmt->close();
}
// Two incoming events: one old, one recent.
$mysqli->query(
    "INSERT INTO tblWebhookEvent (provider, eventType, providerRef, payload, verified, receivedAt) VALUES "
    . "('stripe', 'charge.succeeded', 'evt_old', '{\"name\":\"an old donor\"}', 1, NOW() - INTERVAL 400 DAY),"
    . "('stripe', 'charge.succeeded', 'evt_new', '{\"name\":\"a recent donor\"}', 1, NOW() - INTERVAL 1 DAY)"
);

// 🔁 THE REAL CODE, not a copy of it. `_retention-sweep.php` only defines
//    functions, so it can be required here, and `run_webhook_payload_sweep()`
//    exists as its own function precisely so this test can call it. A first
//    draft of this test called a shim that repeated the two statements, which
//    would have tested nothing but itself.
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps'
    . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . '_retention-sweep.php';
$cleared = run_webhook_payload_sweep();

$left = static function (string $status) use ($mysqli): array {
    $stmt = $mysqli->prepare(
        'SELECT payload, responseSnippet FROM tblWebhookDeliveries WHERE status = ? LIMIT 1'
    );
    $stmt->bind_param('s', $status);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [(string) ($row['payload'] ?? 'MISSING'), (string) ($row['responseSnippet'] ?? '')];
};

[$dp, $ds] = $left('delivered');
wr_check('a DELIVERED delivery past the period has its payload emptied', $dp === '', $dp);
wr_check('and its stored reply emptied too', $ds === '', $ds);
[$xp] = $left('dead');
wr_check('a DEAD delivery past the period is emptied as well', $xp === '', $xp);

[$pp] = $left('pending');
wr_check('A PENDING DELIVERY IS LEFT ALONE, so the retry can still send it',
    str_contains($pp, 'D3') === true, $pp);
[$fp] = $left('failed');
wr_check('and a FAILED one is left alone for the same reason',
    str_contains($fp, 'D4') === true, $fp);

$oldEvent = (string) ($mysqli->query(
    "SELECT COALESCE(payload, 'EMPTIED') AS p FROM tblWebhookEvent WHERE providerRef = 'evt_old'"
)->fetch_assoc()['p'] ?? '');
$newEvent = (string) ($mysqli->query(
    "SELECT COALESCE(payload, 'EMPTIED') AS p FROM tblWebhookEvent WHERE providerRef = 'evt_new'"
)->fetch_assoc()['p'] ?? '');
wr_check('an incoming event past the period has its body emptied', $oldEvent === 'EMPTIED', $oldEvent);
wr_check('a RECENT incoming event keeps its body', str_contains($newEvent, 'recent donor') === true,
    $newEvent);

// 🔁 The row itself must survive: for an incoming event it IS the replay guard,
//    and for a delivery it is the record that something was sent.
$rows = (int) $mysqli->query('SELECT COUNT(*) AS n FROM tblWebhookDeliveries')->fetch_assoc()['n'];
$evts = (int) $mysqli->query('SELECT COUNT(*) AS n FROM tblWebhookEvent')->fetch_assoc()['n'];
wr_check('every row is still there — the payload is emptied, nothing is deleted',
    $rows === 4 && $evts === 2, 'deliveries ' . $rows . ', events ' . $evts);
wr_check('and the sweep reported what it emptied', $cleared === 3, 'reported ' . $cleared);

$mysqli->query('DELETE FROM tblWebhookDeliveries');
$mysqli->query('DELETE FROM tblWebhookEvent');
$mysqli->query('DELETE FROM tblWebhooks WHERE webhookID = 9901');
$mysqli->query('DELETE FROM tblSettings WHERE settingKey = ' . "'" . WebhookRetention::KEY . "'");

// -----------------------------------------------------------------------------
wr_head('E. The incoming body is stored only once the signature has verified');
// -----------------------------------------------------------------------------
// Read rather than exercised: a signed Stripe or PayPal request cannot be made
// here. So this reads the one statement and says what it is reading.
$payments = (string) file_get_contents($core . 'Payments.php');
wr_check('the insert binds a body that is NULL unless the signature verified',
    str_contains($payments, '$storedBody = $verified === true ? $rawBody : null;') === true
    && str_contains($payments, '$storedBody, $verifiedFlag') === true,
    'the pinned lines are not both present');
wr_check('and the row is still written for a refused request, so the attempt is on record',
    str_contains($payments, 'signature not verified, so the body was not kept') === true);

// -----------------------------------------------------------------------------
// 🪤 EVERY IDENTIFIER IN THE SQL BELOW IS BACKTICKED, and the first draft of
//    this section was not: `fullText` IS the reserved word `FULLTEXT` (MySQL
//    ignores case in keywords), so a bare reference does not parse and the test
//    died on the fixture. That is the #276 fault — the one that shipped a
//    statement unable to save a transcript, throwing after the outside provider
//    had been called and charged — reproduced in a test written in the same
//    session as the guard for it.
//
//    `tools/audit-checks/check_reserved_words_quoted.py` could not see it,
//    because it read only `web/`. It reads `tools/` too now.
// -----------------------------------------------------------------------------
wr_head('F. A transcript is deleted past its period, and the recording is not (#714)');
// -----------------------------------------------------------------------------
// The same three-tier shape as the webhook period above, and the same reason for
// a time limit: no column says whose words a transcript holds, and it is a
// record of a meeting involving other people. What differs is that the rows are
// DELETED rather than emptied — a transcript with no words is not a record of
// anything — and that the search copy must go with it.
$mysqli->query('DELETE FROM tblSettings WHERE settingKey = ' . "'" . TranscriptRetention::KEY . "'");
$mysqli->query(
    "INSERT INTO tblSettings (siteID, settingKey, settingValue) VALUES "
    . "(NULL, '" . TranscriptRetention::KEY . "', '30'), (1, '" . TranscriptRetention::KEY . "', '9999')"
);
$trLayer = ['transcription' => ['retentionDays' => '30']];
$GLOBALS['SETTINGS'] = $trLayer;
InstallationSettings::remember($trLayer);
App::init($mysqli, $trLayer);

wr_check('a hand-written transcript period above the ceiling is still held to it',
    TranscriptRetention::daysForSite(1) === 30, (string) TranscriptRetention::daysForSite(1));
// `whyNotAllowed()` is gone: it was a reader for a per-organisation page that
// does not exist (#687's "a public method nothing calls" shape, found by an
// independent check). What IS live is the clear-out's own capping, so that is
// what is checked: "for ever" survives only where the installation allows it.
wr_check('"for ever" in an organisation\'s own row does NOT survive a period at the installation',
    TranscriptRetention::daysForSite(1) !== 0, (string) TranscriptRetention::daysForSite(1));

$mysqli->query('DELETE FROM `tblTranscriptSearch`');
$mysqli->query('DELETE FROM `tblTranscript`');
$mysqli->query('DELETE FROM `tblRecording` WHERE `recordingID` IN (9801, 9802, 9803)');

// Four recordings, one transcript each — `uq_t_recording` allows only one per
// recording, which a first draft of this fixture tripped over by giving two
// transcripts the same recording.
//
// 🪤 A SECOND ORGANISATION, because without one the `r.siteID = ?` predicate was
//    pinned by NOTHING. Measured by an independent check: replacing it with
//    `(r.siteID = ? OR 1 = 1)` — scoping gone, placeholder count unchanged —
//    left all 32 checks green. One organisation's period deleting another's
//    transcripts was invisible. Organisation 2 keeps its transcripts for 900
//    days, so its 400-day-old one must SURVIVE a run in which organisation 1's
//    goes.
$mysqli->query("INSERT IGNORE INTO `tblSites` (`siteID`, `siteName`) VALUES (2, 'second org')");
$mysqli->query(
    "INSERT INTO `tblRecording` (`recordingID`, `siteID`, `title`, `recordedAt`) VALUES "
    . "(9801, 1, 'an old talk',               NOW() - INTERVAL 400 DAY),"
    . "(9802, 1, 'a recent talk',             NOW() - INTERVAL 2 DAY),"
    . "(9803, 1, 'a talk still in the queue', NOW() - INTERVAL 400 DAY),"
    . "(9804, 2, 'another org\'s old talk',    NOW() - INTERVAL 400 DAY)"
);
$mysqli->query(
    "INSERT INTO `tblTranscript` (`transcriptID`, `recordingID`, `status`, `fullText`, `generatedAt`) VALUES "
    // Past the period: must go, with its search copy.
    . "(9901, 9801, 'completed', 'the old words somebody spoke',    NOW() - INTERVAL 400 DAY),"
    // Inside the period: must stay.
    . "(9902, 9802, 'completed', 'the recent words somebody spoke', NOW() - INTERVAL 2 DAY),"
    // Never finished, so no date and no words: must be left alone even though
    // its recording is old, or a recording still waiting in the queue would be
    // quietly dropped from it.
    . "(9903, 9803, 'queued',    NULL,                              NULL),"
    // Another organisation's, equally old. It must survive, because that
    // organisation keeps transcripts for 900 days.
    . "(9904, 9804, 'completed', 'what the other org said',         NOW() - INTERVAL 400 DAY)"
);
// 🪤 AND ITS `queuedAt` IS BACKDATED TOO. Left at its default of "now", the
//    queued row was not past the period on ANY column, so the check below could
//    not fail: an independent check changed the sweep's basis from
//    `generatedAt` to `queuedAt` and that check stayed green. Backdated, the
//    row is past the period on `queuedAt` and inside it on `generatedAt`
//    (which is NULL), so it is only safe while the sweep judges the right
//    column.
$mysqli->query('UPDATE `tblTranscript` SET `queuedAt` = NOW() - INTERVAL 400 DAY WHERE `transcriptID` = 9903');
// 🪤 THE PERIODS FOR THE SWEEP, and the first version of this fixture got them
//    wrong in a way the new check caught: it left the installation's ceiling at
//    30 days and gave organisation 2 a row of 900, expecting 900 to apply. The
//    cap meant organisation 2 was held to 30 as well, so its transcript was
//    rightly deleted and the check went red about CORRECT behaviour.
//
//    To test SCOPING rather than CAPPING, the ceiling has to allow the longer
//    period: the installation allows 900, organisation 1 holds itself to 30,
//    and organisation 2 has no row of its own so it inherits 900. Then
//    organisation 1's 400-day-old transcript goes and organisation 2's stays,
//    which can only happen if the statement really is scoped by organisation.
$mysqli->query('DELETE FROM `tblSettings` WHERE `settingKey` = ' . "'" . TranscriptRetention::KEY . "'");
$mysqli->query(
    "INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`) VALUES "
    . "(NULL, '" . TranscriptRetention::KEY . "', '900'), (1, '" . TranscriptRetention::KEY . "', '30')"
);
$trSweep = ['transcription' => ['retentionDays' => '900']];
$GLOBALS['SETTINGS'] = $trSweep;
InstallationSettings::remember($trSweep);
App::init($mysqli, $trSweep);
$mysqli->query(
    // `recordingID` and `siteID` are NOT NULL on this table too — it carries its
    // own copies so a search can be scoped without joining back.
    "INSERT INTO `tblTranscriptSearch` (`transcriptID`, `recordingID`, `siteID`, `body`) VALUES "
    . "(9901, 9801, 1, 'the old words somebody spoke'),"
    . "(9902, 9802, 1, 'the recent words somebody spoke')"
);

$gone = run_transcript_sweep();

$there = static fn (int $id): bool => (int) ($mysqli->query(
    'SELECT COUNT(*) AS n FROM tblTranscript WHERE transcriptID = ' . $id
)->fetch_assoc()['n'] ?? 0) > 0;
$mirror = static fn (int $id): bool => (int) ($mysqli->query(
    'SELECT COUNT(*) AS n FROM tblTranscriptSearch WHERE transcriptID = ' . $id
)->fetch_assoc()['n'] ?? 0) > 0;

wr_check('the transcript past the period is gone', $there(9901) === false);
wr_check('AND ITS SEARCH COPY WENT WITH IT, so the words cannot outlive the original',
    $mirror(9901) === false);
wr_check('the recent transcript is untouched', $there(9902) === true && $mirror(9902) === true);
// 🪧 WHAT THIS CHECK CAN AND CANNOT FAIL ON, because the distinction was
//    measured rather than assumed. It goes red if the sweep judges the WRONG
//    COLUMN (the row's `queuedAt` is backdated past the period on purpose, so a
//    sweep reading that column would take it). It CANNOT go red for deleting
//    the statement's `generatedAt IS NOT NULL` clause, because `NULL < <date>`
//    is NULL in SQL and the comparison already excludes the row — an
//    independent check deleted that clause and all 33 checks stayed green. The
//    clause is documentation; the three-valued logic is the protection.
wr_check('a transcript still in the queue is left alone, so it is not dropped from it',
    $there(9903) === true);
wr_check('ANOTHER ORGANISATION\'S TRANSCRIPT IS UNTOUCHED, because its own period is longer',
    $there(9904) === true,
    'organisation 2 keeps transcripts for 900 days and its 400-day-old one was deleted anyway');
wr_check('THE RECORDINGS THEMSELVES ARE ALL STILL THERE',
    (int) $mysqli->query('SELECT COUNT(*) AS n FROM tblRecording WHERE recordingID IN (9801, 9802, 9803, 9804)')
        ->fetch_assoc()['n'] === 4);
wr_check('and the sweep reported what it deleted', $gone === 1, 'reported ' . $gone);

$mysqli->query('DELETE FROM `tblTranscriptSearch`');
$mysqli->query('DELETE FROM `tblTranscript`');
$mysqli->query('DELETE FROM `tblRecording` WHERE `recordingID` IN (9801, 9802, 9803, 9804)');
$mysqli->query('DELETE FROM `tblSettings` WHERE `settingKey` = ' . "'" . TranscriptRetention::KEY . "'");
$mysqli->query('DELETE FROM `tblSites` WHERE `siteID` = 2');

echo "\n" . str_repeat('=', 78) . "\n";
$ran = $passes + $failures;
echo $passes . ' passed, ' . $failures . ' failed, ' . $ran . ' ran of '
    . WR_EXPECTED_CHECKS . " expected.\n";
if ($failures === 0 && $ran === WR_EXPECTED_CHECKS) {
    echo "PASS — a webhook payload is capped, cleared out, and never cleared from a delivery"
        . " still to be retried. REACHED THE END.\n";
    exit(0);
}
echo 'FAIL' . ($ran !== WR_EXPECTED_CHECKS
    ? " — the run did not reach its end, or a check was added without changing"
        . " WR_EXPECTED_CHECKS.\n"
    : ".\n");
exit(1);
