<?php
// Path: tools/erasure-review-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The free text a person decides about, on a real database 🧑‍⚖️🧪 (#724)
 * -----------------------------------------------------------------------------
 * Seven columns hold free text that no automatic erasure rule touches, because
 * whether it matters depends on the request in front of you. A person holding
 * the `data_controller` role looks at each line and chooses: empty it, or keep
 * it. An erasure request cannot be completed while one is undecided.
 *
 * This runs the real `Portal\Core\ErasureReview` and the real
 * `Portal\Core\GdprEraser::execute()` against a throwaway database.
 *
 * WHAT IT PROVES
 * --------------
 *   A  The marked set is the seven, read from the catalogue, with a usable
 *      reason for each.
 *   B  The items offered are the person's OWN rows and nobody else's, a cell
 *      with nothing in it is not an item, and a row reached only through an
 *      "acted on it" column is flagged as such.
 *   C  While a line is undecided the request REFUSES TO RUN AT ALL — nothing
 *      is erased, the request goes back to pending review, and the line in the
 *      report says who can deal with it and where.
 *   D  "Keep it" changes nothing and is recorded. "Empty it" really empties the
 *      cell, and the text reaches NEITHER the decisions table NOR the audit
 *      trail.
 *   E  A decision can be changed from keep to empty, and the unique key means
 *      one row per line rather than two.
 *   F  Once every line is decided the request runs to the end and is CLOSED,
 *      and its audit chain still verifies.
 *   G  A NOT NULL column cannot be emptied, and that is caught: nothing is
 *      recorded, so the decision can never claim a line was emptied while the
 *      text is still there. This is the quiet failure #715 is about.
 *   H  A database that has not had migration 242 does NOT block the erasure —
 *      it runs, closes, and says in the report that the review was not
 *      checked. (#694's "ask whether the table is there"; #697's "do not turn
 *      a silent loss into a blocked recovery".)
 *   J  MORE LINES THAN ONE PAGE SHOWS. The count that decides whether a
 *      request may close has no limit on it, so one line past the page-full
 *      still refuses — measured with `SHOW_AT_MOST + 1` rows, which is the
 *      fault as it was found. And that line is still reachable, so the
 *      request can be finished.
 *   K  ONE ORGANISATION'S ROWS. Another organisation's words are neither
 *      shown, counted nor emptiable, and the two tables that record no
 *      organisation at all are marked so the page can label them.
 *   I  The two gates, the organisation filter, the form token and the close
 *      rule are where they are supposed to be, WHOLE CONDITIONS pinned with
 *      comments stripped by `token_get_all()` — so `&& false` on any of them
 *      fails this test.
 *
 * WHAT IT CANNOT PROVE, said plainly
 * ----------------------------------
 *   - THE RACE AT THE CLOSE. `GdprEraser::closeRequest()` asks the same
 *     question again inside the transaction that closes a request. Reaching it
 *     means a reviewable row being INSERTED between the refusal at the start of
 *     a run and the close at its end, and this test runs one request at a time.
 *     What is proved is the question itself, over every answer it can give, and
 *     that the call is there with its whole condition.
 *   - THE PAGES OVER HTTP. The gate, the organisation filter and the form
 *     token are pinned AS TEXT here. Nothing in this file starts a web server,
 *     so no 403, no redirect and no rendered page has ever been requested.
 *     That is the gap that let all three of them ship with nothing guarding
 *     them, and a text pin is the cheap half of the remedy, not the whole of
 *     it.
 *   - A ROW ABOUT THE PERSON THAT CARRIES NO ACCOUNT NUMBER OF THEIRS. It
 *     cannot be found by anything, review included, and section B records that
 *     as a limit rather than a gap: a response card about the erased person,
 *     given to somebody else, is not offered.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=<port> SELFTEST_DB_PASS=<pw> \
 *             php tools/erasure-review-selftest.php
 *
 *         The database is emptied and rebuilt by whoever creates it, and its
 *         name must start with selftest_.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/724
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    exit(2);
}

// 📁 A scratch folder to stand in for the portal's own: the erasure's
//    giving-statement step deletes files under PORTAL_ROOT, so it must never
//    be the real one.
$scratchParent = getenv('SELFTEST_WEB_DIR') !== false ? (string) getenv('SELFTEST_WEB_DIR') : sys_get_temp_dir();
$scratch       = rtrim($scratchParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    . 'erasure-review-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (mkdir($scratch . DIRECTORY_SEPARATOR . '_uploads', 0700, true) === false) {
    echo "REFUSED — could not make the scratch folder {$scratch}. Nothing was checked.\n";
    exit(2);
}
define('PORTAL_ROOT', $scratch);

// Classes one by one, never through bootstrap.php, so this test never reads
// the portal's real connection file or its encryption key. The list is the
// same chain gdpr-eraser-selftest.php needs, because it runs the same code.
$coreDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
require_once $coreDir . 'App.php';
require_once $coreDir . 'GivingRetention.php';
require_once $coreDir . 'GdprEraser.php';
require_once $coreDir . 'ErasureReview.php';
require_once $coreDir . 'MailQueue.php';
require_once $coreDir . 'PortalAddress.php';
require_once $coreDir . 'Giving.php';
require_once $coreDir . 'Site.php';
require_once $coreDir . 'Logger.php';
require_once $coreDir . 'AnonymousCheckins.php';

use Portal\Core\ErasureReview;
use Portal\Core\GdprEraser;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// `$mysqli` is a global on purpose: `App::db()` falls back to it.
$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$mysqli->set_charset('utf8mb4');
\Portal\Core\Site::init($mysqli, ['site' => ['timezoneKeptAt' => '']], 1);

$GLOBALS['erv_pass'] = 0;
$GLOBALS['erv_fail'] = 0;

register_shutdown_function(static function () use ($scratch): void {
    // Only what this run made, and rmdir() refuses a folder that is not empty.
    @rmdir($scratch . DIRECTORY_SEPARATOR . '_uploads');
    @rmdir($scratch);
});

function erv_section(string $name): void
{
    echo "\n── {$name}\n";
}

function erv_check(string $what, bool $ok, string $detail = ''): void
{
    if ($ok === true) {
        $GLOBALS['erv_pass']++;
        echo "  ✓ {$what}\n";

        return;
    }
    $GLOBALS['erv_fail']++;
    echo "  ✗ {$what}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** One value from one query, or null. */
function erv_one(string $sql, string $types = '', array $values = []): mixed
{
    global $mysqli;
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $row === null ? null : $row[0];
}

function erv_run(string $sql): void
{
    global $mysqli;
    $mysqli->query($sql);
}

/**
 * Every word of one request's audit trail, joined in PHP.
 *
 * NOT `GROUP_CONCAT`, whose `group_concat_max_len` is 1,024 bytes by default:
 * a plant that wrote the emptied text into an audit line passed every check
 * because the evidence was cut off before it. Read the rows.
 */
function erv_allDetails(int $requestId): string
{
    global $mysqli;
    $stmt = $mysqli->prepare('SELECT `details` FROM `tblErasureAudit` WHERE `requestID` = ? ORDER BY `auditID`');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $rs  = $stmt->get_result();
    $all = [];
    while ($row = $rs->fetch_row()) {
        $all[] = (string) ($row[0] ?? '');
    }
    $stmt->close();

    return implode(' ', $all);
}

/** Every decision row of one request as one string, joined in PHP for the same reason. */
function erv_allDecisions(int $requestId): string
{
    global $mysqli;
    $stmt = $mysqli->prepare(
        'SELECT CONCAT_WS(\'|\', `tableName`, `columnName`, `rowKey`, `decision`, IFNULL(`decidedByID`, \'-\')) '
        . 'FROM `' . \Portal\Core\ErasureReview::DECISIONS_TABLE . '` WHERE `requestID` = ? ORDER BY `decisionID`'
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $rs  = $stmt->get_result();
    $all = [];
    while ($row = $rs->fetch_row()) {
        $all[] = (string) ($row[0] ?? '');
    }
    $stmt->close();

    return implode(' ', $all);
}

/**
 * A PHP file's CODE only — every comment removed by the tokeniser, never by a
 * regular expression. A guard's text can sit in a comment, and a check that
 * matched the comment would pass on deleted code (#569's lesson, and #724's
 * brief says so in as many words).
 */
function erv_codeOnly(string $path): string
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) === true
            && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === true
        ) {
            continue;
        }
        $out .= is_array($token) === true ? $token[1] : $token;
    }

    return $out;
}

// =============================================================================
// 🌍 The made-up world
// =============================================================================
// P  the person being erased
// C  a control: rows of their own that must not be touched
// DC the data controller, who decides
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');

$newUser = static function (string $name) use ($mysqli): int {
    $stmt = $mysqli->prepare(
        'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`) VALUES (?, ?, 1)'
    );
    $email = strtolower($name) . '-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $stmt->bind_param('ss', $name, $email);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
};
$personId = $newUser('Review Person');
$controlId = $newUser('Review Control');
$deciderId = $newUser('Review Decider');

$personEmail = (string) erv_one('SELECT `emailAddress` FROM `tblUsers` WHERE `userID` = ?', 'i', [$personId]);

// The seven lines of free text. Each `$fixtures` entry is
// [table, column, rowKey, the text, which columns name P].
$fixtures = [];

// A booking P made themselves: `bookedByID` is a LINK column and not an actor
// column (it left ACTOR_COLUMNS in #723), so this is the person's own row.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblResourceBooking` (`resourceID`, `bookedByID`, `approvedByID`, `startAt`, `endAt`, `notes`) '
    . "VALUES (9001, ?, NULL, '2026-11-04 10:00:00', '2026-11-04 11:00:00', ?)"
);
$ownNote = 'I need the small hall because my hearing aid whistles in the big one.';
$stmt->bind_param('is', $personId, $ownNote);
$stmt->execute();
$bookingOwn = (int) $stmt->insert_id;
$stmt->close();
$fixtures[] = ['tblResourceBooking', 'notes', $bookingOwn, $ownNote, ['bookedByID']];

// A booking SOMEBODY ELSE made that P merely approved. The note is the other
// person's words, and the page has to say so before anybody empties it.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblResourceBooking` (`resourceID`, `bookedByID`, `approvedByID`, `startAt`, `endAt`, `notes`) '
    . "VALUES (9001, ?, ?, '2026-11-05 10:00:00', '2026-11-05 11:00:00', ?)"
);
$othersNote = 'Control person: please leave the side door unlocked for me.';
$stmt->bind_param('iis', $controlId, $personId, $othersNote);
$stmt->execute();
$bookingApproved = (int) $stmt->insert_id;
$stmt->close();
$fixtures[] = ['tblResourceBooking', 'notes', $bookingApproved, $othersNote, ['approvedByID']];

// A booking of the CONTROL person's alone. Nothing here may ever be offered.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblResourceBooking` (`resourceID`, `bookedByID`, `startAt`, `endAt`, `notes`) '
    . "VALUES (9001, ?, '2026-11-06 10:00:00', '2026-11-06 11:00:00', 'Control person only')"
);
$stmt->bind_param('i', $controlId);
$stmt->execute();
$bookingControl = (int) $stmt->insert_id;
$stmt->close();

// A booking of P's with NOTHING in the note. Not an item: there is nothing to
// decide about. (The empty string as well as NULL, because a form posts '').
$stmt = $mysqli->prepare(
    'INSERT INTO `tblResourceBooking` (`resourceID`, `bookedByID`, `startAt`, `endAt`, `notes`) '
    . "VALUES (9001, ?, '2026-11-07 10:00:00', '2026-11-07 11:00:00', '')"
);
$stmt->bind_param('i', $personId);
$stmt->execute();
$bookingEmpty = (int) $stmt->insert_id;
$stmt->close();

// The licence seat — the owner's own example.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblAssetLicenseAssignments` (`licenseAssetID`, `userID`, `deviceName`, `seatLabel`) '
    . 'VALUES (9002, ?, ?, ?)'
);
$deviceName = "Jane's laptop";
$seatLabel  = "Jane's seat";
$stmt->bind_param('iss', $personId, $deviceName, $seatLabel);
$stmt->execute();
$seatRow = (int) $stmt->insert_id;
$stmt->close();
$fixtures[] = ['tblAssetLicenseAssignments', 'deviceName', $seatRow, $deviceName, ['userID']];
$fixtures[] = ['tblAssetLicenseAssignments', 'seatLabel', $seatRow, $seatLabel, ['userID']];

// A response card given to P for follow-up. Its prayer request is in SOMEBODY
// ELSE'S words — `assignedToID` is an actor column — which is exactly why a
// person looks at it rather than a rule.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblSalvationCards` (`siteID`, `fullName`, `assignedToID`, `prayerRequest`) '
    . 'VALUES (1, ?, ?, ?)'
);
$cardName   = 'Review Person';
$prayerText = 'Please pray for me, I have been sleeping badly since the diagnosis.';
$stmt->bind_param('sis', $cardName, $personId, $prayerText);
$stmt->execute();
$cardRow = (int) $stmt->insert_id;
$stmt->close();
$fixtures[] = ['tblSalvationCards', 'prayerRequest', $cardRow, $prayerText, ['assignedToID']];

// A group P started.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblSmallGroups` (`groupName`, `groupSlug`, `createdByID`, `meetingNotes`) '
    . 'VALUES (?, ?, ?, ?)'
);
$groupName  = 'Tuesday group';
$groupSlug  = 'tuesday-group-' . bin2hex(random_bytes(3));
$meetingNote = 'Review Person hosts; park on the drive, not the road.';
$stmt->bind_param('ssis', $groupName, $groupSlug, $personId, $meetingNote);
$stmt->execute();
$groupRow = (int) $stmt->insert_id;
$stmt->close();
$fixtures[] = ['tblSmallGroups', 'meetingNotes', $groupRow, $meetingNote, ['createdByID']];

// A recording P uploaded, with a presenter typed by hand.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblRecording` (`title`, `uploadedByID`, `presenterText`) VALUES (?, ?, ?)'
);
$recTitle    = 'Morning talk';
$recPresenter = 'Review Person';
$stmt->bind_param('sis', $recTitle, $personId, $recPresenter);
$stmt->execute();
$recordingRow = (int) $stmt->insert_id;
$stmt->close();
$fixtures[] = ['tblRecording', 'presenterText', $recordingRow, $recPresenter, ['uploadedByID']];

// A service item P was down to present.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblServicePlanItem` (`planID`, `presenterID`, `presenterText`) VALUES (9003, ?, ?)'
);
$itemPresenter = 'Review Person (standing in)';
$stmt->bind_param('is', $personId, $itemPresenter);
$stmt->execute();
$itemRow = (int) $stmt->insert_id;
$stmt->close();
$fixtures[] = ['tblServicePlanItem', 'presenterText', $itemRow, $itemPresenter, ['presenterID']];

// The request itself, at pending review, for this organisation.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `subjectName`, `status`, `dueBy`) '
    . "VALUES (1, ?, ?, 'Review Person', 'pending_review', DATE_ADD(NOW(), INTERVAL 20 DAY))"
);
$stmt->bind_param('is', $personId, $personEmail);
$stmt->execute();
$requestId = (int) $stmt->insert_id;
$stmt->close();

$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

echo "Erasure review self-test — database {$dbName}, request #{$requestId}, person #{$personId}\n";

// =============================================================================
erv_section('A — the marked set comes from the catalogue, and is usable');
// =============================================================================
$marked = ErasureReview::markedColumns();
$flat   = [];
foreach ($marked as $table => $columns) {
    foreach ($columns as $column => $why) {
        $flat[] = $table . '.' . $column;
    }
}
sort($flat);
$expected = [
    'tblAssetLicenseAssignments.deviceName',
    'tblAssetLicenseAssignments.seatLabel',
    'tblRecording.presenterText',
    'tblResourceBooking.notes',
    'tblSalvationCards.prayerRequest',
    'tblServicePlanItem.presenterText',
    'tblSmallGroups.meetingNotes',
];
// 📌 Written out rather than counted. A count passes when one marker is
//    swapped for another, which is a change in whose words get read.
erv_check('the marked columns are exactly the seven the owner agreed', $flat === $expected, implode(', ', $flat));
erv_check('every marker has a reason long enough to show somebody', ErasureReview::whyListUnusable() === '');
$reasonsAllThere = true;
foreach ($marked as $columns) {
    foreach ($columns as $why) {
        if (strlen(trim((string) $why)) < 40) {
            $reasonsAllThere = false;
        }
    }
}
erv_check('each reason is the catalogue\'s own words, not a label', $reasonsAllThere);
erv_check('the table a decision is kept in is in this database', ErasureReview::decisionsTableExists($mysqli) === true);

// =============================================================================
erv_section('B — the items offered are the person\'s rows and nobody else\'s');
// =============================================================================
$found = ErasureReview::itemsFor($mysqli, $requestId, $personId);
erv_check('nothing could not be looked at', $found['problems'] === [], implode('; ', $found['problems']));

$seen = [];
foreach ($found['items'] as $item) {
    $seen[(string) $item['table'] . '.' . (string) $item['column'] . '.' . (string) $item['rowKey']] = $item;
}
$wanted = [];
foreach ($fixtures as [$table, $column, $rowKey, $text, $matched]) {
    $wanted[] = $table . '.' . $column . '.' . (string) $rowKey;
}
sort($wanted);
$seenKeys = array_keys($seen);
sort($seenKeys);
erv_check(
    'every one of the eight planted lines is offered, and nothing else is',
    $seenKeys === $wanted,
    'offered: ' . implode(', ', $seenKeys)
);
erv_check(
    'the control person\'s own booking note is NOT offered',
    isset($seen['tblResourceBooking.notes.' . (string) $bookingControl]) === false
);
erv_check(
    'a note with nothing in it is not an item: there is nothing to decide',
    isset($seen['tblResourceBooking.notes.' . (string) $bookingEmpty]) === false
);
$textsRight = true;
foreach ($fixtures as [$table, $column, $rowKey, $text, $matched]) {
    $item = $seen[$table . '.' . $column . '.' . (string) $rowKey] ?? null;
    if ($item === null || (string) $item['text'] !== $text) {
        $textsRight = false;
    }
}
erv_check('each item carries the real text, whole, so somebody can judge it', $textsRight);
erv_check(
    'the booking P made themselves is NOT flagged as "they only acted on it"',
    (bool) $seen['tblResourceBooking.notes.' . (string) $bookingOwn]['actedOnly'] === false
);
erv_check(
    'the booking P only APPROVED is flagged: the words are somebody else\'s',
    (bool) $seen['tblResourceBooking.notes.' . (string) $bookingApproved]['actedOnly'] === true
);
erv_check(
    'the response card is flagged too — the only link is the worker it was given to',
    (bool) $seen['tblSalvationCards.prayerRequest.' . (string) $cardRow]['actedOnly'] === true
);
erv_check(
    'the matching column is named, so the page can say HOW the row is linked',
    $seen['tblSmallGroups.meetingNotes.' . (string) $groupRow]['matchedBy'] === ['createdByID']
);
erv_check('nothing is decided yet', (string) $seen['tblRecording.presenterText.' . (string) $recordingRow]['decision'] === '');

// 📏 A LIMIT, RECORDED RATHER THAN IMPLIED. A card ABOUT the erased person,
//    given to somebody ELSE, carries no account number of theirs anywhere, so
//    nothing — this review included — can find it. The catalogue says so; this
//    is the measurement.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$stmt = $mysqli->prepare(
    'INSERT INTO `tblSalvationCards` (`siteID`, `fullName`, `assignedToID`, `prayerRequest`) VALUES (1, ?, ?, ?)'
);
$aboutThem = 'Review Person asked for prayer about their job.';
$stmt->bind_param('sis', $cardName, $controlId, $aboutThem);
$stmt->execute();
$cardAboutThem = (int) $stmt->insert_id;
$stmt->close();
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
$afterOtherCard = ErasureReview::itemsFor($mysqli, $requestId, $personId);
$otherOffered   = false;
foreach ($afterOtherCard['items'] as $item) {
    if ((string) $item['table'] === 'tblSalvationCards' && (int) $item['rowKey'] === $cardAboutThem) {
        $otherOffered = true;
    }
}
erv_check(
    'A LIMIT: a card ABOUT the person, given to somebody else, is NOT offered and cannot be',
    $otherOffered === false
);
erv_run('DELETE FROM `tblSalvationCards` WHERE `cardID` = ' . (int) $cardAboutThem);

// =============================================================================
erv_section('C — nothing is erased while a line is undecided');
// =============================================================================
$why = ErasureReview::whyNotReadyToClose($mysqli, $requestId, $personId);
erv_check('the request is not ready to close', $why !== '');
erv_check(
    'the refusal counts the lines and names where they are',
    str_contains($why, '8 line(s)') === true && str_contains($why, 'tblSalvationCards.prayerRequest') === true,
    $why
);

// Claim the request the way the erasure page does, then run it.
erv_run("UPDATE `tblErasureRequest` SET `status` = 'processing', `claimedAt` = UTC_TIMESTAMP() WHERE `requestID` = " . (int) $requestId);
$outcome = GdprEraser::execute($requestId, $personId, $deciderId);
erv_check('the run refused: one step, and it failed', (int) $outcome['steps'] === 1 && (int) $outcome['failed'] === 1, json_encode($outcome));
erv_check('nothing was changed', (bool) $outcome['changed'] === false);
erv_check('nothing is left for anybody to do outside the portal', (array) $outcome['handWorkOnly'] === []);
erv_check(
    'the request is back at pending review',
    (string) erv_one('SELECT `status` FROM `tblErasureRequest` WHERE `requestID` = ?', 'i', [$requestId]) === 'pending_review'
);
erv_check(
    'the person\'s account was NOT emptied',
    (string) erv_one('SELECT `fullName` FROM `tblUsers` WHERE `userID` = ?', 'i', [$personId]) === 'Review Person'
);
erv_check(
    'their own booking note is still there',
    (string) erv_one('SELECT `notes` FROM `tblResourceBooking` WHERE `bookingID` = ?', 'i', [$bookingOwn]) === $ownNote
);
erv_check(
    'their organisation membership was not touched either (the walk never ran)',
    (int) erv_one("SELECT COUNT(*) FROM `tblErasureAudit` WHERE `requestID` = ? AND `action` IN ('delete','anonymise')", 'i', [$requestId]) === 0
);
$line = (string) erv_one(
    "SELECT `details` FROM `tblErasureAudit` WHERE `requestID` = ? AND `action` = 'failed' ORDER BY `auditID` DESC LIMIT 1",
    'i',
    [$requestId]
);
erv_check('the report says it refused before anything was changed', str_contains($line, 'refused before anything was changed') === true, $line);
erv_check('it names the role that has to deal with it', str_contains($line, 'Data Controller') === true);
erv_check('it names the page to do it on', str_contains($line, ErasureReview::PAGE_ROUTE) === true);
erv_check(
    'it says what to do when NOBODY holds the role (a fresh install grants it to nobody)',
    str_contains($line, 'roles page') === true
);
erv_check(
    'the refusal line does not quote any of the text being judged',
    str_contains($line, 'hearing aid') === false && str_contains($line, 'sleeping badly') === false
);

// =============================================================================
erv_section('D — keep it, and empty it');
// =============================================================================
$problem = ErasureReview::record(
    $mysqli,
    $requestId,
    $personId,
    'tblSalvationCards',
    'prayerRequest',
    $cardRow,
    ErasureReview::DECISION_KEEP,
    $deciderId
);
erv_check('a "keep it" is recorded', $problem === '', $problem);
erv_check(
    'the text is untouched',
    (string) erv_one('SELECT `prayerRequest` FROM `tblSalvationCards` WHERE `cardID` = ?', 'i', [$cardRow]) === $prayerText
);
erv_check(
    'the decision row says keep, with who and when',
    (string) erv_one(
        'SELECT CONCAT(`decision`, \'|\', `decidedByID`, \'|\', IF(`decidedAtUtc` IS NULL, \'no\', \'yes\')) '
        . 'FROM `tblErasureReviewDecisions` WHERE `requestID` = ? AND `tableName` = \'tblSalvationCards\'',
        'i',
        [$requestId]
    ) === 'keep|' . (string) $deciderId . '|yes'
);
$keepLine = (string) erv_one(
    "SELECT `details` FROM `tblErasureAudit` WHERE `requestID` = ? AND `action` = 'retain' "
    . "AND `tableName` = 'tblSalvationCards' ORDER BY `auditID` DESC LIMIT 1",
    'i',
    [$requestId]
);
erv_check('the report records the decision as KEPT', str_contains($keepLine, 'KEPT') === true, $keepLine);
erv_check('and says a person decided it, not a rule', str_contains($keepLine, 'No rule decides this column') === true);
erv_check('and names who', str_contains($keepLine, 'administrator #' . (string) $deciderId) === true);

$problem = ErasureReview::record(
    $mysqli,
    $requestId,
    $personId,
    'tblResourceBooking',
    'notes',
    $bookingOwn,
    ErasureReview::DECISION_EMPTY,
    $deciderId
);
erv_check('an "empty it" is recorded', $problem === '', $problem);
erv_check(
    'the cell really is empty afterwards',
    erv_one('SELECT `notes` FROM `tblResourceBooking` WHERE `bookingID` = ?', 'i', [$bookingOwn]) === null
);
erv_check(
    'the control person\'s booking note is untouched',
    (string) erv_one('SELECT `notes` FROM `tblResourceBooking` WHERE `bookingID` = ?', 'i', [$bookingControl]) === 'Control person only'
);
erv_check(
    'the OTHER booking P approved is untouched: one decision, one row',
    (string) erv_one('SELECT `notes` FROM `tblResourceBooking` WHERE `bookingID` = ?', 'i', [$bookingApproved]) === $othersNote
);

// 🔒 THE TEXT REACHES NEITHER RECORD. This is the #710 rule, measured on the
//    whole trail and the whole decisions table rather than on one line.
//
// ⚠️ READ ROW BY ROW, NEVER WITH `GROUP_CONCAT`, and that is a fix a plant
//    forced. The first version of this used GROUP_CONCAT, whose default
//    `group_concat_max_len` is 1,024 BYTES: the trail is far longer than that,
//    so the evidence was silently cut off and a plant that wrote the emptied
//    words straight into the audit line PASSED ALL 87 CHECKS. A check whose
//    evidence is truncated by the database is not a check.
$trail = (string) erv_allDetails($requestId);
erv_check('the emptied words are nowhere in the audit trail', str_contains($trail, 'hearing aid') === false);
erv_check('nor are the words of a kept line', str_contains($trail, 'sleeping badly') === false);
$decisionsDump = erv_allDecisions($requestId);
erv_check(
    'the decisions table holds no text either',
    str_contains($decisionsDump, 'hearing aid') === false && str_contains($decisionsDump, 'sleeping badly') === false
);
$emptyLine = (string) erv_one(
    "SELECT `details` FROM `tblErasureAudit` WHERE `requestID` = ? AND `action` = 'anonymise' "
    . "AND `tableName` = 'tblResourceBooking' ORDER BY `auditID` DESC LIMIT 1",
    'i',
    [$requestId]
);
erv_check('the report records it as EMPTIED', str_contains($emptyLine, 'EMPTIED') === true, $emptyLine);
erv_check('with the row it was on', str_contains($emptyLine, 'row ' . (string) $bookingOwn) === true);

// 🧾 An emptied line is no longer an item, so the page needs the decision list
//    as well — otherwise nothing would show that a press had worked.
$afterEmpty = ErasureReview::itemsFor($mysqli, $requestId, $personId);
$stillThere = false;
foreach ($afterEmpty['items'] as $item) {
    if ((string) $item['table'] === 'tblResourceBooking' && (int) $item['rowKey'] === $bookingOwn) {
        $stillThere = true;
    }
}
erv_check('an emptied line stops being an item', $stillThere === false);
$recorded = ErasureReview::decisionsRecorded($mysqli, $requestId);
$recordedKeys = [];
foreach ($recorded as $d) {
    $recordedKeys[] = (string) $d['table'] . '.' . (string) $d['column'] . '.' . (string) $d['rowKey'] . '=' . (string) $d['decision'];
}
sort($recordedKeys);
erv_check(
    'but it IS in the list of decisions recorded, so the page can show it',
    $recordedKeys === [
        'tblResourceBooking.notes.' . (string) $bookingOwn . '=empty',
        'tblSalvationCards.prayerRequest.' . (string) $cardRow . '=keep',
    ],
    implode(', ', $recordedKeys)
);

// =============================================================================
erv_section('E — refusals, and changing a decision');
// =============================================================================
$problem = ErasureReview::record($mysqli, $requestId, $personId, 'tblUsers', 'fullName', $personId, ErasureReview::DECISION_EMPTY, $deciderId);
erv_check('a line that is not a review item is refused', $problem !== '', $problem);
erv_check(
    'and the account it was aimed at is untouched',
    (string) erv_one('SELECT `fullName` FROM `tblUsers` WHERE `userID` = ?', 'i', [$personId]) === 'Review Person'
);
$problem = ErasureReview::record($mysqli, $requestId, $personId, 'tblResourceBooking', 'notes', $bookingApproved, 'maybe', $deciderId);
erv_check('a third answer is refused', $problem !== '', $problem);
erv_check(
    'and nothing was written for it',
    (int) erv_one(
        'SELECT COUNT(*) FROM `tblErasureReviewDecisions` WHERE `requestID` = ? AND `rowKey` = ?',
        'ii',
        [$requestId, $bookingApproved]
    ) === 0
);
$problem = ErasureReview::record($mysqli, $requestId, $personId, 'tblResourceBooking', 'notes', $bookingOwn, ErasureReview::DECISION_KEEP, $deciderId);
erv_check('a line that is already empty cannot now be "kept"', $problem !== '', $problem);
erv_check(
    'and its decision still reads "empty" — the record cannot become untrue',
    (string) erv_one(
        'SELECT `decision` FROM `tblErasureReviewDecisions` WHERE `requestID` = ? AND `rowKey` = ?',
        'ii',
        [$requestId, $bookingOwn]
    ) === 'empty'
);

// keep, then look again and empty: one row, not two (the unique key binds
// because every one of its four columns is NOT NULL — #487).
ErasureReview::record($mysqli, $requestId, $personId, 'tblSmallGroups', 'meetingNotes', $groupRow, ErasureReview::DECISION_KEEP, $deciderId);
$problem = ErasureReview::record($mysqli, $requestId, $personId, 'tblSmallGroups', 'meetingNotes', $groupRow, ErasureReview::DECISION_EMPTY, $deciderId);
erv_check('a kept line can be looked at again and emptied', $problem === '', $problem);
erv_check(
    'and there is ONE decision row for it, not two',
    (int) erv_one(
        'SELECT COUNT(*) FROM `tblErasureReviewDecisions` WHERE `requestID` = ? AND `tableName` = \'tblSmallGroups\'',
        'i',
        [$requestId]
    ) === 1
);
erv_check(
    'which now says empty',
    (string) erv_one(
        'SELECT `decision` FROM `tblErasureReviewDecisions` WHERE `requestID` = ? AND `tableName` = \'tblSmallGroups\'',
        'i',
        [$requestId]
    ) === 'empty'
);
erv_check(
    'and the group\'s notes are gone',
    erv_one('SELECT `meetingNotes` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [$groupRow]) === null
);

// 🪪 The decider's number is left out when they ARE the person being erased.
ErasureReview::record($mysqli, $requestId, $personId, 'tblRecording', 'presenterText', $recordingRow, ErasureReview::DECISION_KEEP, $personId);
$selfLine = (string) erv_one(
    "SELECT `details` FROM `tblErasureAudit` WHERE `requestID` = ? AND `tableName` = 'tblRecording' "
    . 'ORDER BY `auditID` DESC LIMIT 1',
    'i',
    [$requestId]
);
erv_check(
    'a decider who IS the person being erased is not named in the trail',
    str_contains($selfLine, 'not named here') === true && str_contains($selfLine, '#' . (string) $personId) === false,
    $selfLine
);
// 🪪 AND NOT ON THE DECISION ROW EITHER — the fix of 3 November 2026. The
//    audit line had always masked the number; the ROW stored it, while the
//    column's own comment, `full_schema.sql` and migration 242's header all
//    said it was left empty in this case. Measured by an independent check:
//    `decidedByID` really held the subject's own account number. Nothing had
//    ever asked this question, which is why a comment could promise it for a
//    whole package.
erv_check(
    'and their number is not written on the decision row either',
    erv_one(
        'SELECT `decidedByID` FROM `tblErasureReviewDecisions` WHERE `requestID` = ? AND `tableName` = ? LIMIT 1',
        'is',
        [$requestId, 'tblRecording']
    ) === null
);
// 📌 The other half, so this is not a clause that passes whatever happens: a
//    decider who is SOMEBODY ELSE is still written down. Without this check,
//    binding NULL unconditionally would pass the check above.
erv_check(
    'a decider who is somebody else IS written on the row',
    (int) erv_one(
        'SELECT `decidedByID` FROM `tblErasureReviewDecisions` WHERE `requestID` = ? AND `tableName` = ? LIMIT 1',
        'is',
        [$requestId, 'tblSmallGroups']
    ) === $deciderId
);

// =============================================================================
erv_section('G — a NOT NULL column cannot be emptied, and that is caught');
// =============================================================================
// This is the quiet failure #715 is about, made loud. The install script says
// this column is nullable and `check_erasure_review_items.py` keeps it that
// way; a real database that disagrees is what this guards.
erv_run('ALTER TABLE `tblServicePlanItem` MODIFY COLUMN `presenterText` VARCHAR(255) NOT NULL DEFAULT \'\'');
$problem = ErasureReview::record($mysqli, $requestId, $personId, 'tblServicePlanItem', 'presenterText', $itemRow, ErasureReview::DECISION_EMPTY, $deciderId);
erv_check('"empty it" is refused when the database will not empty it', $problem !== '', $problem);
// 📏 AND THE DATABASE'S OWN WORDS ARE NOT IN THE SENTENCE — only its NUMBER
//    and this class's own reading of it. `rowsHolding()` has always reported
//    the error NUMBER alone, "because a database message can quote a value,
//    and this one may be reported on a page"; `record()` put `$db->error` and
//    `$e->getMessage()` straight into five page-visible sentences until
//    3 November 2026, which is the same page and the same risk.
//
//    The clause is POSITIVE on purpose: "database error 1048" is wording only
//    this class produces, so passing the exception's own message through
//    instead turns this red. A clause that merely forbade some phrase of
//    MySQL's would be a clause that could not fail.
erv_check(
    'and the refusal carries the database\'s NUMBER and our own reading of it, never its words',
    str_contains($problem, 'database error 1048') === true
    && str_contains($problem, 'most likely declared NOT NULL') === true,
    $problem
);
erv_check(
    'the text is still there, and so nothing claims otherwise',
    (string) erv_one('SELECT `presenterText` FROM `tblServicePlanItem` WHERE `itemID` = ?', 'i', [$itemRow]) === $itemPresenter
);
erv_check(
    'NO decision row was written: the whole thing rolled back',
    (int) erv_one(
        'SELECT COUNT(*) FROM `tblErasureReviewDecisions` WHERE `requestID` = ? AND `tableName` = \'tblServicePlanItem\'',
        'i',
        [$requestId]
    ) === 0
);
erv_check(
    'and no audit line claims it was emptied',
    (int) erv_one(
        "SELECT COUNT(*) FROM `tblErasureAudit` WHERE `requestID` = ? AND `tableName` = 'tblServicePlanItem'",
        'i',
        [$requestId]
    ) === 0
);
// 🪢 AND NOW THE SAME THING ON A LOOSE SERVER, which is what the read-back is
//    really for. With a strict connection the UPDATE itself throws, so the
//    read-back never gets a turn — a plant that DELETED the read-back passed
//    all 87 checks until this sub-case was added. A server set to a loose
//    `sql_mode` does not throw: it writes an EMPTY STRING over the NULL and
//    warns, so "I told it to" and "it is empty" really come apart.
//
//    ⚠️ WHAT THIS DOES NOT CLAIM. The portal's own connection is made strict
//       in one place, `DbServer::connect()` (#593), so on an installation the
//       throw comes first and this is belt and braces. It is tested because a
//       guard nothing exercises is not a guard, and because a trigger or a
//       future column type could reach it with no sql_mode involved.
$strictWas = (string) erv_one('SELECT @@SESSION.sql_mode');
erv_run('SET SESSION sql_mode = \'\'');
erv_run('ALTER TABLE `tblServicePlanItem` MODIFY COLUMN `presenterText` VARCHAR(255) NOT NULL DEFAULT \'\'');
$problem = ErasureReview::record($mysqli, $requestId, $personId, 'tblServicePlanItem', 'presenterText', $itemRow, ErasureReview::DECISION_EMPTY, $deciderId);
erv_check('on a LOOSE server the read-back is what catches it', $problem !== '', $problem);
erv_check(
    'and it says the column is most likely NOT NULL rather than guessing',
    str_contains($problem, 'NOT NULL') === true,
    $problem
);
erv_check(
    'the text is still there on a loose server too, and nothing claims otherwise',
    (string) erv_one('SELECT `presenterText` FROM `tblServicePlanItem` WHERE `itemID` = ?', 'i', [$itemRow]) === $itemPresenter
);
erv_check(
    'and still NO decision row: the whole thing rolled back',
    (int) erv_one(
        'SELECT COUNT(*) FROM `tblErasureReviewDecisions` WHERE `requestID` = ? AND `tableName` = \'tblServicePlanItem\'',
        'i',
        [$requestId]
    ) === 0
);
$stmt = $mysqli->prepare('SET SESSION sql_mode = ?');
$stmt->bind_param('s', $strictWas);
$stmt->execute();
$stmt->close();

erv_run('ALTER TABLE `tblServicePlanItem` MODIFY COLUMN `presenterText` VARCHAR(255) DEFAULT NULL');
erv_check(
    'put back nullable, the same decision is accepted',
    ErasureReview::record($mysqli, $requestId, $personId, 'tblServicePlanItem', 'presenterText', $itemRow, ErasureReview::DECISION_EMPTY, $deciderId) === ''
);

// =============================================================================
erv_section('F — once every line is decided, the request runs and closes');
// =============================================================================
$left = ErasureReview::itemsFor($mysqli, $requestId, $personId);
foreach ($left['items'] as $item) {
    if ((string) $item['decision'] !== '') {
        continue;
    }
    $problem = ErasureReview::record(
        $mysqli,
        $requestId,
        $personId,
        (string) $item['table'],
        (string) $item['column'],
        (int) $item['rowKey'],
        ErasureReview::DECISION_KEEP,
        $deciderId
    );
    erv_check('the last undecided lines can be decided (' . (string) $item['table'] . ')', $problem === '', $problem);
}
erv_check('nothing is undecided now', ErasureReview::whyNotReadyToClose($mysqli, $requestId, $personId) === '');

// 🧩 THE THIRD REFUSAL REASON — "we could not tell" — AND IT WAS PINNED BY
//    NOTHING until 3 November 2026. An independent check narrowed the gate
//    from `if ($undecided === [] && $found['problems'] === [])` to
//    `if ($undecided === [])` and all 52 audit scripts and all 91 checks here
//    stayed green: no fixture had ever produced a `problems` entry AND THEN
//    asked for the refusal. A refusal path with no fixture that reaches it is
//    not tested, however loudly a docblock calls it out.
//
//    The fault it guards: a transient database fault hides a table's rows, the
//    review reads "nothing there", and the request closes with the text in it.
//    Reading "we could not tell" as "nothing there" is #647's -1-versus-0 rule
//    in a new place, and the cost of getting it right is one retry.
//
//    How this reaches it: drop the column, so the count asks for a column that
//    is not there (error 1054, which is NOT the 1146 that is deliberately
//    ignored). The state is deliberately "every line decided" — the check is
//    worthless unless the only thing refusing is the problem.
erv_run('ALTER TABLE `tblSmallGroups` DROP COLUMN `meetingNotes`');
$whyProblem = ErasureReview::whyNotReadyToClose($mysqli, $requestId, $personId);
erv_check(
    'a table that could NOT BE LOOKED AT refuses the close, with every line decided',
    $whyProblem !== '',
    $whyProblem
);
erv_check(
    'and it says so as "could not be looked at" rather than counting lines',
    str_contains($whyProblem, 'could not be looked at') === true
    && str_contains($whyProblem, 'line(s) of free text have not been decided') === false,
    $whyProblem
);
erv_check(
    'the refusal names the table and column, and no database message',
    str_contains($whyProblem, 'tblSmallGroups.meetingNotes') === true
    && str_contains($whyProblem, 'Unknown column') === false,
    $whyProblem
);
erv_run('ALTER TABLE `tblSmallGroups` ADD COLUMN `meetingNotes` VARCHAR(500) DEFAULT NULL');
erv_check(
    'with the column back, nothing is in the way again',
    ErasureReview::whyNotReadyToClose($mysqli, $requestId, $personId) === ''
);

erv_run("UPDATE `tblErasureRequest` SET `status` = 'processing', `claimedAt` = UTC_TIMESTAMP() WHERE `requestID` = " . (int) $requestId);
$outcome = GdprEraser::execute($requestId, $personId, $deciderId);
erv_check('the run finished with nothing failed', (int) $outcome['failed'] === 0, json_encode($outcome));
erv_check('it did many steps, not one', (int) $outcome['steps'] > 50, (string) $outcome['steps']);
erv_check(
    'the request is closed',
    (string) erv_one('SELECT `status` FROM `tblErasureRequest` WHERE `requestID` = ?', 'i', [$requestId]) === 'completed'
);
erv_check('the audit chain still verifies with the review lines in it', GdprEraser::verifyAuditChain($requestId) === true);
erv_check(
    'a line KEPT by a person is still there after the whole erasure ran',
    (string) erv_one('SELECT `prayerRequest` FROM `tblSalvationCards` WHERE `cardID` = ?', 'i', [$cardRow]) === $prayerText
);

// =============================================================================
erv_section('H — a database behind the files does not block an erasure');
// =============================================================================
// #694: the table arrives with migration 242, and files reach a server before
// anybody presses "Run migrations". #697: making a safety net real must not
// turn a silent loss into a blocked recovery.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$second = $newUser('Second Person');
$stmt = $mysqli->prepare(
    'INSERT INTO `tblResourceBooking` (`resourceID`, `bookedByID`, `startAt`, `endAt`, `notes`) '
    . "VALUES (9001, ?, '2026-11-08 10:00:00', '2026-11-08 11:00:00', 'Second person wrote this')"
);
$stmt->bind_param('i', $second);
$stmt->execute();
$stmt->close();
$secondEmail = (string) erv_one('SELECT `emailAddress` FROM `tblUsers` WHERE `userID` = ?', 'i', [$second]);
$stmt = $mysqli->prepare(
    'INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `subjectName`, `status`, `dueBy`) '
    . "VALUES (1, ?, ?, 'Second Person', 'processing', DATE_ADD(NOW(), INTERVAL 20 DAY))"
);
$stmt->bind_param('is', $second, $secondEmail);
$stmt->execute();
$secondRequest = (int) $stmt->insert_id;
$stmt->close();
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
erv_run("UPDATE `tblErasureRequest` SET `claimedAt` = UTC_TIMESTAMP() WHERE `requestID` = " . (int) $secondRequest);

erv_run('RENAME TABLE `tblErasureReviewDecisions` TO `selftest_hidden_review_decisions`');
erv_check('with the table gone, the review says nothing is in the way', ErasureReview::whyNotReadyToClose($mysqli, $secondRequest, $second) === '');
$outcome = GdprEraser::execute($secondRequest, $second, $deciderId);
erv_run('RENAME TABLE `selftest_hidden_review_decisions` TO `tblErasureReviewDecisions`');
erv_check('the erasure still ran and nothing failed', (int) $outcome['failed'] === 0, json_encode($outcome));
erv_check(
    'the request closed',
    (string) erv_one('SELECT `status` FROM `tblErasureRequest` WHERE `requestID` = ?', 'i', [$secondRequest]) === 'completed'
);
$skipLine = (string) erv_one(
    "SELECT `details` FROM `tblErasureAudit` WHERE `requestID` = ? AND `action` = 'skip' "
    . "AND `tableName` = 'tblErasureReviewDecisions' LIMIT 1",
    'i',
    [$secondRequest]
);
erv_check(
    'and the report says the free text was NOT checked, so nobody reads it as proof',
    str_contains($skipLine, 'were NOT checked on this run') === true,
    $skipLine
);
erv_check('which tells them to run the database upgrade', str_contains($skipLine, 'Admin, Upgrade') === true);

// =============================================================================
erv_section('J — more lines than one page shows, counted rather than listed');
// =============================================================================
// 📏 THE FAULT THIS MEASURES, and it was measured on a real database before it
//    was fixed: `rowsHolding()` ended `LIMIT 500` with no comment at all, and
//    `whyNotReadyToClose()` read that list. With 501 rows, 500 were offered,
//    NO problem was reported, deciding all 500 made the close answer '' — and
//    the request closed with one line of the person's own words still in it,
//    with nothing anywhere saying so.
//
//    It is not exotic: somebody who books a room every week for ten years has
//    about 520 such rows, and so does the volunteer who uploads each week's
//    recording with a presenter typed by hand.
//
//    The rule it pins: COUNTING AND SHOWING ARE TWO JOBS. A display cap may
//    never be able to change whether a request closes.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$many = $newUser('Review Many');
$manyEmail = (string) erv_one('SELECT `emailAddress` FROM `tblUsers` WHERE `userID` = ?', 'i', [$many]);
$stmt = $mysqli->prepare(
    'INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `subjectName`, `status`, `dueBy`) '
    . "VALUES (1, ?, ?, 'Review Many', 'pending_review', DATE_ADD(NOW(), INTERVAL 20 DAY))"
);
$stmt->bind_param('is', $many, $manyEmail);
$stmt->execute();
$manyRequest = (int) $stmt->insert_id;
$stmt->close();

// One more than a page holds, taken from the constant rather than typed: if
// the cap changes, this still measures the boundary.
$howMany = ErasureReview::SHOW_AT_MOST + 1;
$stmt = $mysqli->prepare(
    'INSERT INTO `tblResourceBooking` (`resourceID`, `bookedByID`, `startAt`, `endAt`, `notes`) '
    . "VALUES (9009, ?, '2026-12-01 10:00:00', '2026-12-01 11:00:00', ?)"
);
for ($n = 1; $n <= $howMany; $n++) {
    $note = 'Weekly booking ' . (string) $n . ': I need the small hall, my hearing aid whistles.';
    $stmt->bind_param('is', $many, $note);
    $stmt->execute();
}
$stmt->close();
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

$manyFound = ErasureReview::itemsFor($mysqli, $manyRequest, $many);
erv_check(
    'the COUNT sees all ' . (string) $howMany . ' lines, with no limit on it',
    (int) $manyFound['counts']['total'] === $howMany,
    json_encode($manyFound['counts'])
);
erv_check(
    'the PAGE shows a page-full and says it is showing fewer than there are',
    (int) $manyFound['counts']['shown'] === ErasureReview::SHOW_AT_MOST
    && (bool) $manyFound['counts']['capped'] === true,
    json_encode($manyFound['counts'])
);
erv_check(
    'all ' . (string) $howMany . ' are counted as undecided, not the ' . (string) ErasureReview::SHOW_AT_MOST . ' on screen',
    (int) $manyFound['counts']['undecided'] === $howMany
);
$manyWhy = ErasureReview::whyNotReadyToClose($mysqli, $manyRequest, $many);
erv_check(
    'the refusal counts all of them, not just the page-full',
    str_contains($manyWhy, (string) $howMany . ' line(s)') === true,
    $manyWhy
);

// Decide every line that IS on screen — exactly what somebody looking at the
// page would do, and exactly what used to make this request closeable.
foreach ($manyFound['items'] as $item) {
    ErasureReview::record(
        $mysqli,
        $manyRequest,
        $many,
        (string) $item['table'],
        (string) $item['column'],
        (int) $item['rowKey'],
        ErasureReview::DECISION_KEEP,
        $deciderId
    );
}
erv_check(
    'a page-full of decisions really was recorded',
    (int) erv_one(
        'SELECT COUNT(*) FROM `tblErasureReviewDecisions` WHERE `requestID` = ?',
        'i',
        [$manyRequest]
    ) === ErasureReview::SHOW_AT_MOST
);
// 🎯 THE MEASUREMENT. Before the fix this answered '' and the request closed.
$afterPage = ErasureReview::whyNotReadyToClose($mysqli, $manyRequest, $many);
erv_check(
    'THE REQUEST STILL REFUSES TO CLOSE: one line is left, and it was never on the page',
    $afterPage !== '' && str_contains($afterPage, '1 line(s)') === true,
    $afterPage
);
// And the one left is REACHABLE: undecided lines are ordered first, so the
// next look at the page shows it. A cap that hid it for ever would have made
// the request impossible to finish — the blocked-recovery shape of #697.
$manyLeft = ErasureReview::itemsFor($mysqli, $manyRequest, $many);
$firstUndecided = null;
foreach ($manyLeft['items'] as $item) {
    if ((string) $item['decision'] === '' && $firstUndecided === null) {
        $firstUndecided = $item;
    }
}
erv_check(
    'the line past the page-full is now the FIRST one shown, so it can be dealt with',
    $firstUndecided !== null && $manyLeft['items'][0]['decision'] === '',
    $firstUndecided === null ? 'no undecided item is shown at all' : 'ok'
);
erv_check(
    'and deciding it is accepted, so every line is reachable',
    ErasureReview::record(
        $mysqli,
        $manyRequest,
        $many,
        (string) $firstUndecided['table'],
        (string) $firstUndecided['column'],
        (int) $firstUndecided['rowKey'],
        ErasureReview::DECISION_KEEP,
        $deciderId
    ) === ''
);
erv_check(
    'with every one of the ' . (string) $howMany . ' decided, the request may close',
    ErasureReview::whyNotReadyToClose($mysqli, $manyRequest, $many) === ''
);

// =============================================================================
erv_section('K — one organisation\'s rows, and the two tables that cannot say');
// =============================================================================
// 🏛️ THE OWNER'S DECISION, 3 NOVEMBER 2026. An erasure request belongs to one
//    organisation and a person may belong to several. Without a filter the data
//    controller of organisation A was shown the same person's own words from
//    organisation B — in full, untruncated — and could empty them. Reading
//    somebody's words to decide about them is not the same act as erasing
//    them, and the two administration levels may be different parties (the
//    standing rule of 29 October 2026).
//
//    THE COST, accepted: a request for somebody in two organisations now needs
//    each organisation's own data controller to act.
$mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
$stmt = $mysqli->prepare(
    "INSERT INTO `tblSites` (`siteID`, `siteName`, `siteKey`, `timezone`, `isActive`) "
    . "VALUES (77, 'Review Other Org', 'review-other-org', 'Europe/London', 1) "
    . 'ON DUPLICATE KEY UPDATE `siteName` = VALUES(`siteName`)'
);
$stmt->execute();
$stmt->close();

$twoOrg = $newUser('Review TwoOrg');
// A group in THIS organisation, and one in the other. Both are theirs.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblSmallGroups` (`siteID`, `groupName`, `groupSlug`, `createdByID`, `meetingNotes`) '
    . 'VALUES (?, ?, ?, ?, ?)'
);
$hereNote  = 'This organisation: park on the drive, not the road.';
$thereNote = 'The OTHER organisation: my front door sticks, knock hard.';
$siteHere  = 1;
$siteThere = 77;
$slugHere  = 'two-org-here-' . bin2hex(random_bytes(3));
$slugThere = 'two-org-there-' . bin2hex(random_bytes(3));
$nameHere  = 'Here group';
$nameThere = 'There group';
$stmt->bind_param('issis', $siteHere, $nameHere, $slugHere, $twoOrg, $hereNote);
$stmt->execute();
$groupHere = (int) $stmt->insert_id;
$stmt->bind_param('issis', $siteThere, $nameThere, $slugThere, $twoOrg, $thereNote);
$stmt->execute();
$groupThere = (int) $stmt->insert_id;
$stmt->close();

// A booking of theirs. `tblResourceBooking` records NO organisation at all, so
// this one cannot be narrowed and has to be LABELLED instead.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblResourceBooking` (`resourceID`, `bookedByID`, `startAt`, `endAt`, `notes`) '
    . "VALUES (9010, ?, '2026-12-02 10:00:00', '2026-12-02 11:00:00', ?)"
);
$bookNote = 'No organisation is recorded against this row at all.';
$stmt->bind_param('is', $twoOrg, $bookNote);
$stmt->execute();
$bookingTwoOrg = (int) $stmt->insert_id;
$stmt->close();

$twoEmail = (string) erv_one('SELECT `emailAddress` FROM `tblUsers` WHERE `userID` = ?', 'i', [$twoOrg]);
$stmt = $mysqli->prepare(
    'INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `subjectName`, `status`, `dueBy`) '
    . "VALUES (1, ?, ?, 'Review TwoOrg', 'pending_review', DATE_ADD(NOW(), INTERVAL 20 DAY))"
);
$stmt->bind_param('is', $twoOrg, $twoEmail);
$stmt->execute();
$twoRequest = (int) $stmt->insert_id;
$stmt->close();
$mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

$twoFound = ErasureReview::itemsFor($mysqli, $twoRequest, $twoOrg);
$twoKeys  = [];
foreach ($twoFound['items'] as $item) {
    $twoKeys[(string) $item['table'] . '.' . (string) $item['rowKey']] = $item;
}
erv_check(
    'the group in THIS organisation is offered',
    isset($twoKeys['tblSmallGroups.' . (string) $groupHere]) === true,
    implode(', ', array_keys($twoKeys))
);
erv_check(
    'THE OTHER ORGANISATION\'S GROUP IS NOT OFFERED, and its words are not on this page',
    isset($twoKeys['tblSmallGroups.' . (string) $groupThere]) === false
);
$twoTexts = '';
foreach ($twoFound['items'] as $item) {
    $twoTexts .= (string) $item['text'] . ' ';
}
erv_check(
    'the other organisation\'s words are nowhere in what the page would print',
    str_contains($twoTexts, 'front door sticks') === false
);
erv_check(
    'and the count that decides the close does not see them either',
    (int) ErasureReview::countUndecided($mysqli, $twoRequest, $twoOrg)['total'] === 2,
    json_encode(ErasureReview::countUndecided($mysqli, $twoRequest, $twoOrg))
);
erv_check(
    'a row whose table DOES record an organisation is marked as this one\'s',
    (bool) $twoKeys['tblSmallGroups.' . (string) $groupHere]['oneOrgOnly'] === true
);
erv_check(
    'a row whose table records NONE is marked so the page can label it',
    isset($twoKeys['tblResourceBooking.' . (string) $bookingTwoOrg]) === true
    && (bool) $twoKeys['tblResourceBooking.' . (string) $bookingTwoOrg]['oneOrgOnly'] === false
);
// 📌 The decision cannot be slipped past the filter from the form either:
//    `record()` asks the same rule, so the other organisation's row is refused.
erv_check(
    'the other organisation\'s row cannot be emptied by posting its number',
    ErasureReview::record(
        $mysqli,
        $twoRequest,
        $twoOrg,
        'tblSmallGroups',
        'meetingNotes',
        $groupThere,
        ErasureReview::DECISION_EMPTY,
        $deciderId
    ) !== ''
);
erv_check(
    'and those words are still in the database, untouched',
    (string) erv_one('SELECT `meetingNotes` FROM `tblSmallGroups` WHERE `groupID` = ?', 'i', [$groupThere]) === $thereNote
);
// 🧾 The cost is said out loud, in ONE wording, where a reader meets it.
$note = ErasureReview::organisationScopeNote();
erv_check(
    'the class says what the narrowing costs somebody in two organisations',
    str_contains($note, 'more than one organisation') === true
    && str_contains($note, 'Data Controller') === true
);
erv_check(
    'and the refusal an administrator reads carries that same sentence',
    str_contains(ErasureReview::whyNotReadyToClose($mysqli, $twoRequest, $twoOrg), $note) === true
);
$reviewSource = (string) file_get_contents(
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps' . DIRECTORY_SEPARATOR
    . 'admin' . DIRECTORY_SEPARATOR . 'erasure' . DIRECTORY_SEPARATOR . 'review.php'
);
erv_check(
    'and the page prints it rather than keeping its own copy of the words',
    str_contains($reviewSource, 'ErasureReview::organisationScopeNote()') === true
    && str_contains($reviewSource, 'more than one organisation') === false
);
erv_check(
    'the page labels a row that is not tied to one organisation',
    str_contains($reviewSource, 'Not tied to one organisation') === true
    && str_contains($reviewSource, "oneOrgOnly'] ?? false) === false") === true
);

// =============================================================================
erv_section('I — the gates and the close rule are where they must be');
// =============================================================================
// Whole conditions, with comments stripped by the tokeniser. A check that
// matched the text of a guard passes when `&& false` is added to it, and one
// that matched a comment passes when the code is deleted.
$eraserCode = erv_codeOnly($coreDir . 'GdprEraser.php');
erv_check(
    'execute() asks whether a line is undecided, FIRST, and refuses on the whole condition',
    str_contains(
        $eraserCode,
        '$notReviewedYet = ErasureReview::whyNotReadyToClose($db, $requestId, $userId);'
    ) === true
    && str_contains($eraserCode, "if (\$notReviewedYet !== '') {") === true
);
// The POSITION, not just the presence: before the first thing that changes
// anything. #709's ordering trap is that the walk cuts the very links the
// review finds its rows by.
$askedAt  = strpos($eraserCode, 'ErasureReview::whyNotReadyToClose');
$demoAt   = strpos($eraserCode, 'self::eraseDemoDataRegisterEntries($requestId, $userId)');
$addressAt = strpos($eraserCode, 'self::addressToScrub($db, $requestId, $userId)');
erv_check(
    'and it is asked BEFORE the first step that changes anything',
    $askedAt !== false && $demoAt !== false && $addressAt !== false && $askedAt < $addressAt && $askedAt < $demoAt,
    (string) $askedAt . ' vs ' . (string) $addressAt . '/' . (string) $demoAt
);
$closeAt = strpos($eraserCode, 'private static function closeRequest');
$guardAt = strpos($eraserCode, 'ErasureReview::whyNotReadyToClose', (int) $closeAt);
erv_check(
    'closeRequest() — the ONE place a request is closed — asks it again',
    $closeAt !== false && $guardAt !== false
);
erv_check(
    'and throws on the whole condition rather than carrying on',
    $guardAt !== false
    && str_contains(
        substr($eraserCode, (int) $guardAt, 400),
        "if (\$notReviewedYet !== '') {\n                throw new \\RuntimeException("
    ) === true
);
erv_check(
    'the public door to the audit trail accepts only the review\'s four words',
    str_contains(
        $eraserCode,
        "if (in_array(\$action, ['anonymise', 'retain', 'skip', 'failed'], true) === false) {"
    ) === true
);

$appsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps' . DIRECTORY_SEPARATOR
    . 'admin' . DIRECTORY_SEPARATOR . 'erasure' . DIRECTORY_SEPARATOR;
foreach (['review.php', 'review-save.php'] as $pageFile) {
    $pageCode = erv_codeOnly($appsDir . $pageFile);
    // 🚪 SINCE #727 THE GATE IS THE CAPABILITY REGISTRY, not `Roles::has()`
    //    inline. The meaning is unchanged and that is the whole requirement:
    //    `Capabilities::CAPABILITIES['erasure.review']` ships with
    //    `['data_controller']` and declares `globalAdminFallsOpen => false`,
    //    so an organisation that has never opened the roles page is gated
    //    exactly as it was. The behaviour — including a global administrator
    //    without the role being refused — is proved by
    //    `tools/capabilities-selftest.php`, which asks `Capabilities::allows()`
    //    with the flag set; what is pinned HERE is that these two files ask
    //    that one question and refuse on the answer.
    erv_check(
        $pageFile . ' asks the capability registry, with the whole condition',
        str_contains($pageCode, 'if (App::can(Capabilities::ERASURE_REVIEW) === false) {') === true
    );
    erv_check(
        $pageFile . ' refuses, rather than merely testing',
        str_contains($pageCode, "if (App::can(Capabilities::ERASURE_REVIEW) === false) {\n    Router::renderError(403);\n    return;\n}") === true
    );
    // ⚠️ THE POINT OF THE EXCEPTION: `App::hasRole()` answers true for any
    //    global administrator with no role row (#516), which is exactly the
    //    person this role exists to keep out.
    erv_check(
        $pageFile . ' does NOT use App::hasRole(), which would defeat the role',
        str_contains($pageCode, 'App::hasRole(') === false
    );

    // 🏛️ THE ORGANISATION FILTER ON THE REQUEST LOOK-UP, pinned because
    //    NOTHING PINNED IT. An independent check removed `AND siteID = ?`
    //    from BOTH files (and the CSRF call from the handler) in one edit on
    //    3 November 2026, and all 52 audit scripts exited 0 and this test
    //    passed 91 of 91. Without it a data controller of organisation A
    //    could read and empty organisation B's members' own words.
    //
    //    The WHOLE WHERE CLAUSE is pinned as one string literal, not the
    //    words "siteID" somewhere in the file: a text search for a name is
    //    satisfied by a comment or by a second query. And the BIND is pinned
    //    as well, because a WHERE clause with a parameter nobody binds does
    //    not run at all — on this connection it throws, which is loud, but
    //    pinning both is what makes the pair the thing that is guarded.
    erv_check(
        $pageFile . ' looks the request up by BOTH its number and this organisation',
        str_contains($pageCode, 'FROM `tblErasureRequest` WHERE `requestID` = ? AND `siteID` = ? LIMIT 1') === true
    );
    erv_check(
        $pageFile . ' binds both of them, so the clause really runs',
        str_contains($pageCode, "\$stmt->bind_param('ii', \$requestId, \$siteId);") === true
    );
    // 🪪 And the organisation comes from the SESSION'S OPEN ORGANISATION, never
    //    from anything posted or typed. `Site::id()` is the one answer.
    erv_check(
        $pageFile . ' takes the organisation from Site::id() and not from the request',
        str_contains($pageCode, '$siteId = Site::id();') === true
        && str_contains($pageCode, "\$_POST['siteID']") === false
        && str_contains($pageCode, "\$_GET['siteID']") === false
    );
}

// 🔐 THE ANTI-FORGERY TOKEN on the one file that writes. Pinned as the WHOLE
//    condition together with the refusal it leads to: matching only
//    `Auth::verifyCsrf(` would pass with `|| false` beside it, and matching
//    only the condition would pass with the `exit` deleted — #668's section-D
//    lesson. Without this a page on another website could empty a line of
//    somebody's own words with one click.
$saveCode = erv_codeOnly($appsDir . 'review-save.php');
erv_check(
    'review-save.php refuses anything that is not a POST carrying this session\'s form token',
    str_contains(
        $saveCode,
        "if (\$_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf(\$_POST['csrf_token'] ?? '') === false) {\n"
        . "    http_response_code(400);\n"
        . "    exit('Bad request');\n"
        . '}'
    ) === true
);
// 📏 And `review.php` hands the token to BOTH buttons of every item, or the
//    handler above refuses every press and the page looks broken instead.
$pageCode = erv_codeOnly($appsDir . 'review.php');
erv_check(
    'review.php puts the form token in the forms it draws',
    str_contains($pageCode, '$csrf = Auth::csrfToken();') === true
);

// =============================================================================
erv_section('R — the row lock is real, and what it protects (#753)');
// =============================================================================
//
// 🔒 WHY THIS SECTION EXISTS. `ErasureReview::record()` takes a row lock
//    (`FOR UPDATE`) so that two Data Controllers cannot both decide one line.
//    **Until #753 that lock was exercised by NOTHING, and a comment in
//    `tools/erasure-pages-selftest.php` claimed it was "proved at class level
//    instead" — which was untrue.** Measured at the time: this file opened ONE
//    connection, and the string `FOR UPDATE` appeared in neither test.
//
// 🧭 HOW IT IS TESTED, AND WHY NOT BY RACING TWO PROCESSES. Racing was tried
//    first: two `proc_open` workers asking for opposite decisions on one line.
//    It worked, and it was NOT GOOD ENOUGH — measured over six runs with the
//    lock deliberately removed, it caught the fault ONCE. A guard that fires
//    one run in six reads like proof and is not, which is worse than none.
//
//    So this follows `tools/expense-payment-race-selftest.php` instead, which
//    is this repository's established answer to the same question: run the
//    statements in the method's own order from TWO CONNECTIONS, interleaved
//    DELIBERATELY. That proves the MECHANISM — the lock really makes a second
//    reader wait — and it is deterministic.
//
//    What it does NOT prove: that `record()` still CONTAINS those statements.
//    Section A's text pins do that, and this is why both kinds exist.

$lockText = 'Lock test — ' . bin2hex(random_bytes(6));
$lockName = 'Lock fixture';
$lockIns  = $mysqli->prepare(
    'INSERT INTO `tblSalvationCards` (`siteID`, `fullName`, `assignedToID`, `prayerRequest`) VALUES (1, ?, ?, ?)'
);
$lockIns->bind_param('sis', $lockName, $personId, $lockText);
$lockIns->execute();
$lockRow = (int) $lockIns->insert_id;
$lockIns->close();

// 🔌 A SECOND connection. This file otherwise opens one, which is exactly why
//    it could not test a lock before.
$second = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
// ⏱️ Three seconds, so a blocked read fails fast instead of waiting the
//    server's default fifty.
$second->query('SET SESSION innodb_lock_wait_timeout = 3');

// 1️⃣ The first decider holds the row, exactly as `record()` does.
$mysqli->begin_transaction();
$holdStmt = $mysqli->prepare(
    'SELECT `prayerRequest` FROM `tblSalvationCards` WHERE `cardID` = ? LIMIT 1 FOR UPDATE'
);
$holdStmt->bind_param('i', $lockRow);
$holdStmt->execute();
$holdStmt->get_result()->fetch_row();
$holdStmt->close();

// 2️⃣ The second decider asks for the same row the same way. It must WAIT and
//    then time out. Without `FOR UPDATE` on the first read it would answer at
//    once — which is the whole fault.
$blocked = false;
$answered = '';
try {
    $tryStmt = $second->prepare(
        'SELECT `prayerRequest` FROM `tblSalvationCards` WHERE `cardID` = ? LIMIT 1 FOR UPDATE'
    );
    $tryStmt->bind_param('i', $lockRow);
    $tryStmt->execute();
    $row = $tryStmt->get_result()->fetch_row();
    $answered = $row === null ? '(no row)' : (string) $row[0];
    $tryStmt->close();
} catch (\Throwable $ex) {
    $blocked = str_contains($ex->getMessage(), 'Lock wait timeout') === true;
    $answered = $ex->getMessage();
}
erv_check(
    'R1 a second decider asking for the same row WAITS and times out, so the lock is real rather than decoration',
    $blocked === true,
    'the second connection answered: ' . substr($answered, 0, 90)
);

// 3️⃣ The first decider finishes the job, through the real method.
$mysqli->rollback();
$lockProblem = ErasureReview::record(
    $mysqli,
    $requestId,
    $personId,
    'tblSalvationCards',
    'prayerRequest',
    $lockRow,
    ErasureReview::DECISION_EMPTY,
    $deciderId
);
erv_check('R2 and with the row free the first decision is recorded', $lockProblem === '', $lockProblem);

// 4️⃣ The second decider now tries the OPPOSITE decision on the same line. It
//    must be refused, because by the time it holds the row the cell is empty —
//    which is the thing the lock buys: a record can never say "the words are
//    still there" over a cell somebody else emptied.
$lateProblem = ErasureReview::record(
    $second,
    $requestId,
    $personId,
    'tblSalvationCards',
    'prayerRequest',
    $lockRow,
    ErasureReview::DECISION_KEEP,
    $deciderId
);
// 📣 The sentence it is refused WITH is deliberately general — "already
//    emptied, or the row changed, removed, or in another organisation — or it
//    could not be read just now" — because `record()` cannot tell those apart
//    from the outside, and this repository's rule is to say "the likeliest
//    reason is…" rather than assert one. A draft of this check looked for the
//    method's own internal words ("already empty") and went red on a correct
//    refusal.
erv_check(
    'R3 a second decider arriving after the line was emptied is REFUSED, and the reason names that possibility',
    $lateProblem !== '' && str_contains($lateProblem, 'already emptied') === true,
    'it answered: ' . $lateProblem
);

// 5️⃣ And the record agrees with the cell — the invariant all of this is for.
$lockCell = erv_one('SELECT `prayerRequest` FROM `tblSalvationCards` WHERE `cardID` = ?', 'i', [$lockRow]);
$lockSaid = (string) erv_one(
    'SELECT `decision` FROM `tblErasureReviewDecisions` WHERE `requestID` = ? AND `tableName` = '
    . "'tblSalvationCards' AND `rowKey` = ? LIMIT 1",
    'ii',
    [$requestId, $lockRow]
);
erv_check(
    'R4 the cell and the record agree: emptied, and recorded as emptied',
    ($lockCell === null || (string) $lockCell === '') && $lockSaid === 'empty',
    'the cell is ' . ($lockCell === null || (string) $lockCell === '' ? 'empty' : 'still there')
    . '; the record says "' . $lockSaid . '"'
);

// 🎯 R5 IS THE ONE THAT CATCHES REMOVAL, AND R1-R4 ARE NOT — this is the most
//    important sentence in the section.
//
//    **Measured: with ` FOR UPDATE` deleted from `record()`'s own SELECT, R1 to
//    R4 stayed GREEN on four runs out of four.** Of course they did: R1 takes
//    the lock IN THIS TEST, so it proves the DATABASE locks, not that
//    `record()` asks it to. Deterministic and vacuous, which is worse than
//    flaky — and it is exactly the shape this repository keeps finding, a check
//    that passes on the broken code while reading like proof.
//
//    So the guard against removal is a pin on the method's own text, the way
//    `tools/expense-payment-race-selftest.php` splits the same question: its
//    section A proves the mechanism and says in terms that it "does NOT load
//    the page ... that is section B's whole job". R1-R4 are the mechanism and
//    what it buys; R5 is the guard.
//
//    Comments are stripped by `token_get_all()` first, so a comment mentioning
//    `FOR UPDATE` — and there is one, directly above the statement — cannot
//    satisfy this.
$recordCode = erv_codeOnly($coreDir . 'ErasureReview.php');
erv_check(
    'R5 `record()` still reads the cell with FOR UPDATE — the pin that catches the lock being deleted, '
        . 'which R1 to R4 provably do not',
    str_contains(
        $recordCode,
        "'SELECT `' . \$column . '` FROM `' . \$table . '` WHERE `' . \$keyColumn . '` = ? LIMIT 1 FOR UPDATE'"
    ) === true
);
// 📏 And the transaction must still open BEFORE it, or the lock is decoration
//    (#697: "a lock outside a transaction is decoration", which shipped once
//    with an eleven-line comment claiming otherwise).
$beginAt = strpos($recordCode, '$db->begin_transaction()');
$lockAt  = strpos($recordCode, 'LIMIT 1 FOR UPDATE');
erv_check(
    'R6 ... and the transaction still opens BEFORE the lock is taken, without which the lock releases at once',
    $beginAt !== false && $lockAt !== false && $beginAt < $lockAt,
    'begin_transaction at ' . var_export($beginAt, true) . ', the lock at ' . var_export($lockAt, true)
);

$second->close();
erv_run(
    'DELETE FROM `tblErasureReviewDecisions` WHERE `requestID` = ' . (int) $requestId
    . ' AND `rowKey` = ' . (int) $lockRow
);
erv_run('DELETE FROM `tblSalvationCards` WHERE `cardID` = ' . (int) $lockRow);

echo "\n";
echo 'Passed: ' . (string) $GLOBALS['erv_pass'] . "   Failed: " . (string) $GLOBALS['erv_fail'] . "\n";
exit($GLOBALS['erv_fail'] === 0 ? 0 : 1);
