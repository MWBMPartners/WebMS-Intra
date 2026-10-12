<?php
// Path: tools/erasure-pages-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The erasure pages, on the REAL pages — self-test 🗑️🚪
 * (roll-out package A2a)
 * -----------------------------------------------------------------------------
 * `tools/gdpr-coverage-selftest.php` reads the erasure code as text, and
 * `tools/gdpr-eraser-selftest.php` runs the erasure ENGINE on a database.
 * Neither opens a page. The independent check of 12 October 2026 planted four
 * faults in the pages and both of those tests, and every audit check, still
 * passed:
 *
 *   - "Delete my account" with its form-token test removed;
 *   - its "is this a root administrator?" test comparing a number with text,
 *     which is never true, so a root administrator was let through;
 *   - the administrator's erasure actions open to anybody signed in;
 *   - a request already at "processing" run a second time.
 *
 * This file is what fails then. It runs the portal and asks it. It is the
 * page proof written while the package was built, kept as a test so that it
 * can be run again, on the model of `tools/access-pages-selftest.php`.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file, starts PHP's built-in web server on it behind a small
 * router that imitates `.htaccess`, signs real accounts in through the real
 * sign-in page, and sends real requests. Then it checks:
 *
 *   A. "Delete my account": refused without the form token, with the wrong
 *      words, and for a root administrator; a good press files ONE request,
 *      already confirmed, and changes nothing else; a second press is told
 *      "already recorded" and files nothing.
 *   B. "Request data erasure": the request waits for its emailed link, and
 *      the page says in words what will happen to each kind of record.
 *   C. The administrator's actions: a member, an administrator of another
 *      organisation only, a press with no form token, and another
 *      organisation's request are each refused, for every action.
 *   D. "Confirm on the person's behalf": offered only on this organisation's
 *      waiting request; pressing it confirms, records who, and kills the link.
 *   E. A request stuck at "processing": refused while a run shows signs of
 *      life, and it cannot be RUN again either; put back once it has been
 *      quiet for long enough, and then run to the end.
 *   F. The administrator's run of the "Delete my account" request: that, and
 *      only that, empties the account. Other people's records are all still
 *      there, and the four pages that list them still SHOW them: a visitor's
 *      follow-up note, a pastoral visit, an approval on an expense claim, and
 *      a room booking.
 *
 * SAFETY
 * ------
 * - It writes and erases rows, so it REFUSES unless the database's name
 *   starts with `selftest_`. Every row it makes is numbered 917000 to 917999
 *   or belongs to one of its two made-up organisations; they are removed at
 *   the START (in case an earlier run was interrupted) and at the END.
 * - It sets two portal-wide settings for the run (`multisite.enabled`,
 *   `multisite.detectionMode`), so the two organisations are told apart by
 *   the first part of the address, and puts them back. So do NOT run it at
 *   the same moment as `tools/access-pages-selftest.php` or
 *   `tools/feed-admin-selftest.php` on the same database: they change the
 *   same two settings.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder). It refuses if that folder contains the repository or
 *   is inside it. The child is deleted at the end only if it still holds
 *   this run's marker. `web/_auth_keys` is never read or copied.
 * - The server is started with its command as a list (no shell), so the
 *   process number is the server's own, and it is stopped by that number,
 *   never by a name. It refuses to start if anything already answers on its
 *   port. Every answer must carry this run's header.
 * - The clean-up also runs from a shutdown function, so a crash part way
 *   still puts things back. WHAT IT CANNOT SURVIVE: being killed outright.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - Apache: the router only imitates `.htaccess`.
 * - That a confirmation email is sent: there is no mail service here, so the
 *   pages are seen saying that the email could NOT be sent.
 * - How a page looks in a browser, at any width.
 * - That an erased person's OPEN SESSION is ended. It is not: the portal
 *   keeps sessions as PHP's own files and nothing checks, page by page, that
 *   the account behind a session is still active. That needs a change to
 *   `Portal\Core\Auth`, which this package did not make.
 * - Two administrators pressing at the same moment.
 * - MariaDB. It has been run on MySQL 8.0.36 and 8.4.11.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/erasure-pages-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9071). The database must already
 *         hold `web/_sql/full_schema.sql`. Needs PHP's curl extension and
 *         `proc_open()`.
 * Exit:   0 only when every check ran and passed; 1 otherwise, including
 *         every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 38;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes and erases rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$repo      = (string) realpath(dirname(__DIR__));
$webSrc    = $repo . DIRECTORY_SEPARATOR . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR) === true
    || str_starts_with($parent . DIRECTORY_SEPARATOR, $repo . DIRECTORY_SEPARATOR) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9071 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$db->set_charset('utf8mb4');

$pass = 0;
$fail = 0;

/** ✅ Report one check. `$detail` is shown only on failure. */
function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail;
    if ($passed === true) {
        $pass++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $fail++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . mb_substr($detail, 0, 900)) . "\n";
}

function head(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

/** @param list<mixed> $params @return list<array<string,mixed>> */
function q(string $sql, string $types = '', array $params = []): array
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** @param list<mixed> $params */
function one(string $sql, string $types = '', array $params = []): ?array
{
    return q($sql, $types, $params)[0] ?? null;
}

// =============================================================================
// 🗺️ The made-up world
// =============================================================================
const ORG_A  = 917001; // the organisation every page is opened at
const ORG_B  = 917002; // another organisation on the same installation
const ADDR_A = '/selftest-erasure-a';

const ADM  = 917011; // an administrator of A
const ROOT = 917012; // a member of A who is a root administrator of the installation
const MEL  = 917013; // a member of A, and a busy volunteer: presses "Delete my account"
const NIA  = 917014; // a member of A: her request is stuck at "processing"
const CAL  = 917015; // a member of A whose records Mel worked on; files an ordinary request
const ADMB = 917016; // an administrator of B ONLY

const REQ_STUCK = 917101; // Nia's, at "processing", its run dead for 20 minutes
const REQ_LIVE  = 917102; // Cal's, at "processing", a run showed signs of life 1 minute ago
const REQ_B_PUT = 917103; // ORG_B's, at "processing", dead for 20 minutes
const REQ_B_ASK = 917104; // ORG_B's, waiting to be confirmed

const VISITOR = 917201;
const NOTE    = 917202;
const CASE_ID = 917203;
const VISIT   = 917204;
const DEPT    = 917205;
const CLAIM   = 917206;
const ROOM    = 917207;
const BOOKING = 917208;
const GIFTAID = 917209;

/** Every request this run's organisations hold for one account. */
function requestsOf(int $user): array
{
    return q('SELECT * FROM tblErasureRequest WHERE siteID IN (?, ?) AND userID = ? ORDER BY requestID', 'iii', [ORG_A, ORG_B, $user]);
}

function request(int $id): ?array
{
    return one('SELECT * FROM tblErasureRequest WHERE requestID = ?', 'i', [$id]);
}

function auditLines(int $id): int
{
    return (int) one('SELECT COUNT(*) AS n FROM tblErasureAudit WHERE requestID = ?', 'i', [$id])['n'];
}

function removeWorld(): void
{
    global $db;
    // Foreign-key checks are switched off for the clearing-up only: an erasure
    // leaves rows whose links have been emptied, and the order they could be
    // deleted in is not worth working out for a throwaway database.
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    q('DELETE a FROM tblErasureAudit a JOIN tblErasureRequest r ON r.requestID = a.requestID WHERE r.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblErasureRequest WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblVisitorContact WHERE contactID = ?', 'i', [NOTE]);
    q('DELETE FROM tblVisitor WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblCareAccessLog WHERE caseID = ?', 'i', [CASE_ID]);
    q('DELETE FROM tblCareVisit WHERE caseID = ?', 'i', [CASE_ID]);
    q('DELETE FROM tblCareCase WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblExpenseClaimApprovals WHERE claimID = ?', 'i', [CLAIM]);
    q('DELETE FROM tblExpenseClaims WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUserDepts WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblDepts WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblResourceBooking WHERE resourceID = ?', 'i', [ROOM]);
    q('DELETE FROM tblResource WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblGiftAidDeclaration WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 917000 AND 917999');
    q('DELETE FROM tblEmailLog WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 917000 AND 917999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblErrors WHERE userID BETWEEN 917000 AND 917999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUserSites WHERE siteID IN (?, ?) OR userID BETWEEN 917000 AND 917999', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUsers WHERE userID BETWEEN 917000 AND 917999');
    q('DELETE FROM tblSettings WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}

/** One organisation's own value for a setting (replacing any it had). */
function siteSetting(int $site, string $key, string $value): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss', [$site, $key, $value, $value]);
}

// =============================================================================
// 🧰 The server, and one request
// =============================================================================
$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Put back everything this run changed, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    // The server, by the process proc_open() gave — never by a name.
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        $until = microtime(true) + 5;
        while (proc_get_status($state['proc'])['running'] === true && microtime(true) < $until) {
            usleep(50000);
        }
        if (proc_get_status($state['proc'])['running'] === true) {
            proc_terminate($state['proc'], 9);
        }
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    try {
        foreach ($state['settings'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.erasure-pages-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started, at organisation A's
 * address. Redirects are never followed. An answer without this run's header
 * came from somewhere else and is returned as status -1, which no check
 * expects.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . ADDR_A . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:'],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Erasure-Pages-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start'];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
}

/** The form token on a page. */
function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** A page's text, with the tags taken out and the spaces tidied. */
function text(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}

/** The message a page shows in its coloured box: "[colour] words". */
function message(string $html): string
{
    return preg_match('/<div class="alert alert-(\w+)">(.*?)<\/div>/s', $html, $m) === 1
        ? '[' . $m[1] . '] ' . text($m[2]) : '(no message shown)';
}

/**
 * Press one of the administrator's buttons for one request, with a fresh form
 * token from the list page, and return the message the list then shows.
 */
function act(string $jar, int $requestId, string $action): string
{
    $list = req($jar, 'GET', '/admin/erasure-requests');
    req($jar, 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($list['body']), 'requestID' => $requestId, 'action' => $action]);

    return message(req($jar, 'GET', '/admin/erasure-requests')['body']);
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();

q("INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES (?, 'selftest-erasure-a', 'Selftest erasure A', 1), (?, 'selftest-erasure-b', 'Selftest erasure B', 1)", 'ii', [ORG_A, ORG_B]);
$people = [ADM => ['Ada Admin', 0], ROOT => ['Rae Root', 1], MEL => ['Mel Member', 0], NIA => ['Nia Member', 0], CAL => ['Cal Control', 0], ADMB => ['Bea Other', 0]];
foreach ($people as $id => [$name, $root]) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, ?)', 'issi', [$id, $name, 'u' . $id . '.erasure@selftest.invalid', $root]);
}
foreach ([[ADM, ORG_A, 1], [ROOT, ORG_A, 0], [MEL, ORG_A, 0], [NIA, ORG_A, 0], [CAL, ORG_A, 0], [ADMB, ORG_B, 1]] as [$user, $site, $admin]) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)', 'iii', [$user, $site, $admin]);
}
// Mel's own things, and other people's records that Mel worked on.
q("UPDATE tblUsers SET displayBio = 'I run the welcome team.', calendarToken = 'erasurepagesselftestcalendarkey0000001' WHERE userID = ?", 'i', [MEL]);
q("INSERT INTO tblGiftAidDeclaration (declarationID, siteID, donorID, address, postcode, validFrom) VALUES (?, ?, ?, '1 Made-up Road', 'ZZ1 1ZZ', '2025-04-06')", 'iii', [GIFTAID, ORG_A, CAL]);
q("INSERT INTO tblVisitor (visitorID, siteID, fullName, assignedToID, createdByID, notes) VALUES (?, ?, 'Vera Visitor', ?, ?, 'Came with her sister.')", 'iiii', [VISITOR, ORG_A, MEL, ADM]);
q("INSERT INTO tblVisitorContact (contactID, visitorID, contactedByID, method, summary) VALUES (?, ?, ?, 'call', 'Rang Vera; she will come on Sunday.')", 'iii', [NOTE, VISITOR, MEL]);
q("INSERT INTO tblCareCase (caseID, siteID, personUserID, summary, openedByID) VALUES (?, ?, ?, 'Cal is recovering from an operation.', ?)", 'iiii', [CASE_ID, ORG_A, CAL, ADM]);
q("INSERT INTO tblCareVisit (visitID, caseID, visitedByID, kind, notes) VALUES (?, ?, ?, 'visit', 'Visited Cal at home; in good spirits.')", 'iii', [VISIT, CASE_ID, MEL]);
q("INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES (?, ?, 'Selftest erasure dept', 'SED', 1)", 'ii', [DEPT, ORG_A]);
q("INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status) VALUES (?, ?, ?, ?, 'ERASURE Cal claim', CURDATE(), 12.50, 'Approved')", 'iiii', [CLAIM, ORG_A, CAL, DEPT]);
q("INSERT INTO tblExpenseClaimApprovals (claimID, userID, decision, comments) VALUES (?, ?, 'Approved', 'Receipts all present.')", 'ii', [CLAIM, MEL]);
q("INSERT INTO tblResource (resourceID, siteID, name, category, isActive) VALUES (?, ?, 'Selftest erasure hall', 'room', 1)", 'ii', [ROOM, ORG_A]);
q("INSERT INTO tblResourceBooking (bookingID, resourceID, bookedByID, startAt, endAt, purpose, status) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 3 DAY), DATE_ADD(NOW(), INTERVAL 75 HOUR), 'Welcome team supper', 'pending')", 'iii', [BOOKING, ROOM, MEL]);
// Requests that are already in some state when the run begins.
$seed = static function (int $id, int $site, int $user, string $status, ?int $minutesAgo): void {
    q(
        'INSERT INTO tblErasureRequest (requestID, siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy, claimedAt) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 MONTH), IF(? IS NULL, NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)))',
        'iiissssii',
        [$id, $site, $user, 'u' . $user . '.erasure@selftest.invalid', 'seeded', $status === 'pending_confirmation' ? str_repeat('b', 64) : null, $status, $minutesAgo, $minutesAgo]
    );
};
$seed(REQ_STUCK, ORG_A, NIA, 'processing', 20);
$seed(REQ_LIVE, ORG_A, CAL, 'processing', 1);
$seed(REQ_B_PUT, ORG_B, ADMB, 'processing', 20);
$seed(REQ_B_ASK, ORG_B, ADMB, 'pending_confirmation', null);

// Organisations told apart by the first part of the address: recorded first, put back by the clean-up.
foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'path'] as $key => $value) {
    $row                     = one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    $state['settings'][$key] = $row;
    if ($row === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);
    } else {
        q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$value, (int) $row['settingID']]);
    }
}
// The apps whose pages section F opens are switched off on a fresh install.
foreach (['care.enabled', 'visitors.enabled', 'resources.enabled', 'expenses.enabled'] as $appSwitch) {
    siteSetting(ORG_A, $appSwitch, 'true');
}

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
$logins   = ['adm' => ADM, 'root' => ROOT, 'mel' => MEL, 'nia' => NIA, 'cal' => CAL, 'admb' => ADMB];
foreach ($logins as $who => $id) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-erasure-' . $who, $hash]);
}

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'erasure-pages-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.erasure-pages-selftest-owner', $state['token']); // the marker FIRST
mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        refuse('web/ holds a link (' . $rel . '); refusing to copy it');
    }
    if ($info->isDir() === true) {
        mkdir($child . $sep . 'web' . $sep . $rel, 0700);
    } else {
        copy((string) $path, $child . $sep . 'web' . $sep . $rel);
        $copied++;
    }
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
// Mel's photo, in the scratch copy's uploads folder: the erasure must delete the FILE.
mkdir($child . $sep . 'web' . $sep . '_uploads' . $sep . 'directory', 0700, true);
$melPhoto = $child . $sep . 'web' . $sep . '_uploads' . $sep . 'directory' . $sep . MEL . '.jpg';
file_put_contents($melPhoto, 'made-up image');
q('UPDATE tblUsers SET displayPhoto = ? WHERE userID = ?', 'si', ['directory/' . MEL . '.jpg', MEL]);
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/erasure-pages-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/erasure-pages-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Erasure-Pages-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
// Required at the TOP LEVEL, never inside a function: the portal's start-up
// sets $mysqli and $SETTINGS as globals.
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') { $target = $real; } else { readfile($real); return true; }
}
$_SERVER['SCRIPT_NAME']     = substr($target, strlen($root));
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
ROUTER;
file_put_contents($child . $sep . 'router.php', str_replace('__TOKEN__', $state['token'], $router));

// The server: its command as a LIST (no shell in between), ONE process (no
// PHP_CLI_SERVER_WORKERS), so nothing can outlive it.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc']     = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('the web server could not be started');
}
$deadline = microtime(true) + 10;
while (portAnswers($port) === false && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false) {
    refuse('the web server never answered on port ' . $port);
}

echo "The erasure pages, on the real pages — self-test (roll-out package A2a)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

$jar    = [];
/** Sign one account in, in a browser of its own (`$browser` names the cookie jar; by default the account's). */
$signIn = static function (string $who, string $browser = '') use (&$jar, $child, $sep, $password): array {
    $browser       = $browser === '' ? $who : $browser;
    $jar[$browser] = $child . $sep . 'jar-' . $browser . '.txt';
    $tok           = token(req($jar[$browser], 'GET', '/login')['body']);

    return req($jar[$browser], 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-erasure-' . $who, 'password' => $password]);
};
foreach (array_keys($logins) as $who) {
    $in = $signIn($who);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-erasure-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
}

// =============================================================================
head('A. "Delete my account"');
// =============================================================================
$melBefore = one('SELECT * FROM tblUsers WHERE userID = ?', 'i', [MEL]);
$form      = req($jar['mel'], 'GET', '/account/delete');
$formText  = text($form['body']);
ok('the form says what it does: it sends a request, the typed words are the confirmation, the person stays signed in until it is carried out, and no law is stated as fact',
    $form['status'] === 200 && str_contains($formText, 'This sends a request. It does not delete your account straight away.')
    && str_contains($formText, 'That is your confirmation: there is no email to wait for')
    && str_contains($formText, 'you stay signed in') && str_contains($formText, 'you will not be able to sign in again')
    && str_contains($formText, 'where the law or the organisation\'s own duties require it')
    && str_contains($formText, 'because the law requires it') === false, mb_substr($formText, 0, 700));

$noToken = req($jar['mel'], 'POST', '/account/delete/confirm', ['confirm_phrase' => 'DELETE MY ACCOUNT']);
$told    = message(req($jar['mel'], 'GET', '/account/delete')['body']);
ok('with NO form token nothing is filed, and the person is told nothing was changed',
    requestsOf(MEL) === [] && str_contains($told, 'Nothing was changed'), 'HTTP ' . $noToken['status'] . ' ' . $told);

$form  = req($jar['mel'], 'GET', '/account/delete');
req($jar['mel'], 'POST', '/account/delete/confirm', ['csrf_token' => token($form['body']), 'confirm_phrase' => 'delete my account']);
$told = message(req($jar['mel'], 'GET', '/account/delete')['body']);
ok('with the wrong words (small letters) nothing is filed, and the page says why', requestsOf(MEL) === [] && str_contains($told, 'did not match'), $told);

$form = req($jar['root'], 'GET', '/account/delete');
req($jar['root'], 'POST', '/account/delete/confirm', ['csrf_token' => token($form['body']), 'confirm_phrase' => 'DELETE MY ACCOUNT']);
$told = message(req($jar['root'], 'GET', '/account/delete')['body']);
ok('a ROOT ADMINISTRATOR is refused, and nothing is filed', requestsOf(ROOT) === [] && str_contains($told, 'Root admins cannot'), $told);

$form  = req($jar['mel'], 'GET', '/account/delete');
$good  = token($form['body']);
$press = req($jar['mel'], 'POST', '/account/delete/confirm', ['csrf_token' => $good, 'confirm_phrase' => 'DELETE MY ACCOUNT', 'userID' => CAL, 'siteID' => ORG_B]);
$land  = req($jar['mel'], 'GET', '/account/my-data');
$filed = requestsOf(MEL);
ok('a good press files exactly ONE request: for the signed-in person (not the account number sent in a forged field), at this organisation, already confirmed, with no link token',
    count($filed) === 1 && (int) $filed[0]['siteID'] === ORG_A && $filed[0]['status'] === 'pending_review' && $filed[0]['confirmToken'] === null
    && $filed[0]['confirmedAt'] !== null && count(requestsOf(CAL)) === 1 /* Cal's seeded one only */, json_encode($filed));
ok('the page changes nothing itself: the account row is the same column for column, the sign-in details and the photo file are still there, and the person is still signed in',
    one('SELECT * FROM tblUsers WHERE userID = ?', 'i', [MEL]) === $melBefore && is_file($melPhoto) === true
    && (int) one('SELECT COUNT(*) AS n FROM tblLocalAccounts WHERE userID = ?', 'i', [MEL])['n'] === 1
    && req($jar['mel'], 'GET', '/account')['status'] === 200);
ok('the person is told what happens next: nothing erased yet, no email to wait for, an administrator reviews it',
    $press['status'] === 302 && str_contains(message($land['body']), 'nothing has been erased yet')
    && str_contains(message($land['body']), 'there is no email to wait for') && str_contains(message($land['body']), 'An administrator now reviews the request'),
    message($land['body']));

// A double-click: the very same press again, with the form token the first one used up.
req($jar['mel'], 'POST', '/account/delete/confirm', ['csrf_token' => $good, 'confirm_phrase' => 'DELETE MY ACCOUNT']);
$again = message(req($jar['mel'], 'GET', '/account/my-data')['body']);
ok('a DOUBLE-CLICK (the same press again) is told the request is already recorded, not "Nothing was changed. Please try again."; still one request',
    count(requestsOf(MEL)) === 1 && str_contains($again, 'already recorded') && str_contains($again, 'Please try again') === false, $again);
$form = req($jar['mel'], 'GET', '/account/delete');
req($jar['mel'], 'POST', '/account/delete/confirm', ['csrf_token' => token($form['body']), 'confirm_phrase' => 'DELETE MY ACCOUNT']);
ok('pressing it again later, from a fresh form, files no second request either', count(requestsOf(MEL)) === 1, count(requestsOf(MEL)) . ' requests');
$melRequest = (int) $filed[0]['requestID'];

// =============================================================================
head('B. "Request data erasure"');
// =============================================================================
$askPage = req($jar['cal'], 'GET', '/account/erasure-request');
$askText = text($askPage['body']);
ok('the page says in words what happens to each kind of record: "kept" for a Gift Aid declaration, and never "retaind"',
    $askPage['status'] === 200 && preg_match('/tblGiftAidDeclaration — 1 row\(s\) will be kept\s*\./', $askText) === 1
    && str_contains($askText, 'retaind') === false, mb_substr($askText, 0, 700));
req($jar['cal'], 'POST', '/account/erasure-request', ['csrf_token' => token($askPage['body'])]);
$askTold  = message(req($jar['cal'], 'GET', '/account/my-data')['body']);
$calAsked = array_values(array_filter(requestsOf(CAL), static fn (array $r): bool => $r['status'] === 'pending_confirmation'));
ok('it files a request that WAITS for the emailed link; with no mail service the person is told to ask an administrator to confirm it',
    count($calAsked) === 1 && strlen((string) $calAsked[0]['confirmToken']) === 64 && $calAsked[0]['confirmedAt'] === null
    && str_contains($askTold, 'could not be sent') && str_contains($askTold, 'ask an administrator to confirm it for you'), $askTold);
$calRequest = (int) ($calAsked[0]['requestID'] ?? 0);
$calToken   = (string) ($calAsked[0]['confirmToken'] ?? '');

// =============================================================================
head('C. The administrator\'s actions: who is refused');
// =============================================================================
$actions  = ['execute', 'cancel', 'putback', 'confirm'];
$snapshot = static fn (): array => q('SELECT requestID, status, userID, claimedAt IS NULL AS unclaimed, processedByID FROM tblErasureRequest WHERE siteID IN (?, ?) ORDER BY requestID', 'ii', [ORG_A, ORG_B]);
$auditAll = static fn (): int => (int) one('SELECT COUNT(*) AS n FROM tblErasureAudit a JOIN tblErasureRequest r ON r.requestID = a.requestID WHERE r.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B])['n'];
$before   = [$snapshot(), $auditAll()];

$codes = [];
foreach ($actions as $action) {
    foreach ([$melRequest, $calRequest, REQ_STUCK] as $target) {
        $own     = req($jar['cal'], 'GET', '/account/delete');
        $codes[] = req($jar['cal'], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($own['body']), 'requestID' => $target, 'action' => $action])['status'];
    }
}
ok('a MEMBER, with a good form token of her own, is refused every action on every request (HTTP 403), and nothing changes',
    array_unique($codes) === [403] && [$snapshot(), $auditAll()] === $before, 'HTTP codes: ' . implode(',', array_unique($codes)));

$codes = [];
foreach ($actions as $action) {
    $own     = req($jar['admb'], 'GET', '/account/delete');
    $codes[] = req($jar['admb'], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($own['body']), 'requestID' => $melRequest, 'action' => $action])['status'];
}
ok('an administrator of ANOTHER organisation only is refused too (HTTP 403), and nothing changes',
    array_unique($codes) === [403] && [$snapshot(), $auditAll()] === $before, 'HTTP codes: ' . implode(',', array_unique($codes)));

$codes = [];
foreach ($actions as $action) {
    $codes[] = req($jar['adm'], 'POST', '/admin/erasure-requests/process', ['requestID' => $melRequest, 'action' => $action])['status'];
}
ok('the administrator with NO form token is refused every action (HTTP 400), and nothing changes',
    array_unique($codes) === [400] && [$snapshot(), $auditAll()] === $before, 'HTTP codes: ' . implode(',', array_unique($codes)));

$said = [];
foreach ([['confirm', REQ_B_ASK], ['putback', REQ_B_PUT], ['execute', REQ_B_PUT], ['cancel', REQ_B_ASK]] as [$action, $target]) {
    $said[] = act($jar['adm'], $target, $action);
}
ok('ANOTHER organisation\'s request is "not found" for every action, and nothing changes',
    count(array_filter($said, static fn (string $m): bool => str_contains($m, 'Erasure request not found'))) === 4 && [$snapshot(), $auditAll()] === $before,
    implode(' | ', $said));

// =============================================================================
head('D. "Confirm on the person\'s behalf"');
// =============================================================================
$list = req($jar['adm'], 'GET', '/admin/erasure-requests');
ok('the list offers the button on this organisation\'s waiting request and on no other, with a prompt about checking who is asking',
    substr_count($list['body'], 'name="action" value="confirm"') === 1 && str_contains($list['body'], 'name="requestID" value="' . REQ_B_ASK . '"') === false
    && preg_match('/data-confirm="[^"]*checked, yourself, that the request really comes from them[^"]*"/', $list['body']) === 1,
    substr_count($list['body'], 'name="action" value="confirm"') . ' confirm buttons');
$told      = act($jar['adm'], $calRequest, 'confirm');
$confirmed = request($calRequest);
$line      = one('SELECT action, details FROM tblErasureAudit WHERE requestID = ? ORDER BY auditID DESC LIMIT 1', 'i', [$calRequest]);
ok('pressing it confirms the request, and ONE audit line records who confirmed and that they state they checked the person\'s identity',
    $confirmed['status'] === 'pending_review' && $confirmed['confirmToken'] === null && $confirmed['confirmedAt'] !== null
    && auditLines($calRequest) === 1 && ($line['action'] ?? '') === 'confirmed-on-behalf'
    && str_contains((string) ($line['details'] ?? ''), 'account #' . ADM) && str_contains((string) ($line['details'] ?? ''), 'states that they checked the person\'s identity')
    && str_contains($told, 'is confirmed and is now at pending review'), $told . ' ' . json_encode($line));
$link = req($jar['cal'], 'GET', '/account/erasure-confirm?token=' . $calToken);
ok('the link that was emailed (had it arrived) is dead afterwards, and pressing confirm again adds no second line',
    str_contains($link['body'], 'Link expired') && str_contains(act($jar['adm'], $calRequest, 'confirm'), 'no longer waiting') && auditLines($calRequest) === 1,
    mb_substr(text($link['body']), 0, 200));
ok('confirming erased nothing: the person\'s account is as it was', one('SELECT fullName, isActive FROM tblUsers WHERE userID = ?', 'i', [CAL]) === ['fullName' => 'Cal Control', 'isActive' => 1]);

// =============================================================================
head('E. A request stuck at "processing"');
// =============================================================================
$list = req($jar['adm'], 'GET', '/admin/erasure-requests');
ok('the list offers "Put back to waiting" on this organisation\'s two requests at "processing", and on no other',
    substr_count($list['body'], 'name="action" value="putback"') === 2, substr_count($list['body'], 'name="action" value="putback"') . ' buttons');
$told = act($jar['adm'], REQ_LIVE, 'putback');
ok('one whose run showed signs of life a minute ago is REFUSED, in plain words, and is unchanged',
    str_contains($told, 'was not put back') && str_contains($told, 'may still be going') && request(REQ_LIVE)['status'] === 'processing' && auditLines(REQ_LIVE) === 0, $told);
$told = act($jar['adm'], REQ_LIVE, 'execute');
ok('that live request cannot be RUN a second time either: refused, still "processing", not one audit line, the account untouched',
    str_contains($told, 'not in a reviewable state') && request(REQ_LIVE)['status'] === 'processing' && auditLines(REQ_LIVE) === 0
    && one('SELECT fullName FROM tblUsers WHERE userID = ?', 'i', [CAL])['fullName'] === 'Cal Control', $told);
$told = act($jar['adm'], REQ_STUCK, 'putback');
ok('one whose run has been dead for 20 minutes IS put back to "pending review", with one audit line',
    str_contains($told, 'is back at pending review') && request(REQ_STUCK)['status'] === 'pending_review' && auditLines(REQ_STUCK) === 1, $told);
$told = act($jar['adm'], REQ_STUCK, 'execute');
ok('...and can then be run to the end', str_contains($told, 'is completed: all') && request(REQ_STUCK)['status'] === 'completed'
    && one('SELECT fullName FROM tblUsers WHERE userID = ?', 'i', [NIA])['fullName'] === '[Deleted User]', $told);

// =============================================================================
head('F. The administrator runs the "Delete my account" request');
// =============================================================================
$pages = static fn (): array => [
    'note'     => text(req($GLOBALS['jar']['adm'], 'GET', '/visitors/profile?id=' . VISITOR)['body']),
    'visit'    => text(req($GLOBALS['jar']['adm'], 'GET', '/care/case?id=' . CASE_ID)['body']),
    'claim'    => text(req($GLOBALS['jar']['cal'], 'GET', '/expenses/view?id=' . CLAIM)['body']),
    'room'     => text(req($GLOBALS['jar']['adm'], 'GET', '/resources/resource?id=' . ROOM)['body']),
    'bookings' => text(req($GLOBALS['jar']['adm'], 'GET', '/resources/approvals')['body']),
];
$was = $pages();
ok('before the run, five pages show records Mel worked on, each with Mel\'s name: a follow-up note, a pastoral visit, an approval on Cal\'s expense claim, a room booking, and that booking in the approvals queue',
    str_contains($was['note'], 'Rang Vera; she will come on Sunday.') && str_contains($was['note'], 'Mel Member')
    && str_contains($was['visit'], 'Visited Cal at home; in good spirits.') && str_contains($was['visit'], 'Mel Member')
    && str_contains($was['claim'], 'Receipts all present.') && str_contains($was['claim'], 'Mel Member')
    && str_contains($was['room'], 'Welcome team supper') && str_contains($was['room'], 'Mel Member')
    && str_contains($was['bookings'], 'Welcome team supper') && str_contains($was['bookings'], 'Mel Member'),
    json_encode(array_map(static fn (string $t): string => mb_substr($t, 0, 160), $was)));

$told = act($jar['adm'], $melRequest, 'execute');
ok('the administrator\'s run of that request completes it', str_contains($told, 'is completed: all') && request($melRequest)['status'] === 'completed' && request($melRequest)['userID'] === null, $told);
$melNow = one('SELECT * FROM tblUsers WHERE userID = ?', 'i', [MEL]);
// PHP remembers what it last learnt about a file. This process looked at the
// photo in part A, and it is the SERVER that deletes it, so without this the
// answer below would be the remembered one. (The first run of this test
// failed here for exactly that reason, with the file correctly gone.)
clearstatcache(true, $melPhoto);
ok('...and THAT is what empties the account: name, address, biography, photo link and calendar key gone, the sign-in details gone, the photo FILE deleted',
    $melNow['fullName'] === '[Deleted User]' && $melNow['emailAddress'] === 'deleted-' . $melRequest . '@example.invalid' && $melNow['displayBio'] === null
    && $melNow['displayPhoto'] === null && $melNow['calendarToken'] === null && (int) $melNow['isActive'] === 0
    && (int) one('SELECT COUNT(*) AS n FROM tblLocalAccounts WHERE userID = ?', 'i', [MEL])['n'] === 0 && is_file($melPhoto) === false,
    'photo file still there: ' . json_encode(is_file($melPhoto)) . '; sign-in rows: ' . one('SELECT COUNT(*) AS n FROM tblLocalAccounts WHERE userID = ?', 'i', [MEL])['n']
    . '; photo line: ' . json_encode(one("SELECT action, recordKey, details FROM tblErasureAudit WHERE requestID = ? AND tableName = 'ProfileImageFiles'", 'i', [$melRequest]))
    . '; account: ' . json_encode($melNow));
$kept = [
    'visitor'  => one('SELECT fullName, assignedToID FROM tblVisitor WHERE visitorID = ?', 'i', [VISITOR]),
    'note'     => one('SELECT summary, contactedByID FROM tblVisitorContact WHERE contactID = ?', 'i', [NOTE]),
    'visit'    => one('SELECT notes, visitedByID FROM tblCareVisit WHERE visitID = ?', 'i', [VISIT]),
    'approval' => one('SELECT decision, comments, userID FROM tblExpenseClaimApprovals WHERE claimID = ?', 'i', [CLAIM]),
    'booking'  => one('SELECT purpose, status, bookedByID FROM tblResourceBooking WHERE bookingID = ?', 'i', [BOOKING]),
    'giftAid'  => one('SELECT donorID, address FROM tblGiftAidDeclaration WHERE declarationID = ?', 'i', [GIFTAID]),
];
ok('other people\'s records are all still in the database, each with only Mel\'s number taken off: the visitor, the note, the visit, the approval, the booking; and Cal\'s Gift Aid declaration is untouched',
    $kept === [
        'visitor'  => ['fullName' => 'Vera Visitor', 'assignedToID' => null],
        'note'     => ['summary' => 'Rang Vera; she will come on Sunday.', 'contactedByID' => null],
        'visit'    => ['notes' => 'Visited Cal at home; in good spirits.', 'visitedByID' => null],
        'approval' => ['decision' => 'Approved', 'comments' => 'Receipts all present.', 'userID' => null],
        'booking'  => ['purpose' => 'Welcome team supper', 'status' => 'pending', 'bookedByID' => null],
        'giftAid'  => ['donorID' => CAL, 'address' => '1 Made-up Road'],
    ], json_encode($kept));
$now = $pages();
ok('the VISITOR page still shows the follow-up note, with "Somebody no longer recorded" where Mel\'s name was',
    str_contains($now['note'], 'Rang Vera; she will come on Sunday.') && str_contains($now['note'], 'Somebody no longer recorded') && str_contains($now['note'], 'Mel Member') === false, mb_substr($now['note'], 0, 500));
ok('the PASTORAL page still shows the visit and its notes, the same way',
    str_contains($now['visit'], 'Visited Cal at home; in good spirits.') && str_contains($now['visit'], 'Somebody no longer recorded') && str_contains($now['visit'], 'Mel Member') === false, mb_substr($now['visit'], 0, 500));
ok('the EXPENSE CLAIM\'s page still shows the approval line, with "Approver\'s details removed" where Mel\'s name was',
    str_contains($now['claim'], 'Receipts all present.') && str_contains($now['claim'], 'Approver\'s details removed') && str_contains($now['claim'], 'Mel Member') === false, mb_substr($now['claim'], 0, 500));
ok('the ROOM\'s page still shows the booking, so the room does not look free for a time it is booked for',
    str_contains($now['room'], 'Welcome team supper') && str_contains($now['room'], 'Somebody no longer recorded') && str_contains($now['room'], 'Mel Member') === false, mb_substr($now['room'], 0, 500));
ok('the booking is still in the administrator\'s approvals queue, waiting for a decision',
    str_contains($now['bookings'], 'Welcome team supper') && str_contains($now['bookings'], 'Requested by somebody no longer recorded'), mb_substr($now['bookings'], 0, 500));
$back = $signIn('mel', 'mel-again');
ok('Mel cannot sign in again', $back['status'] !== 302 || str_contains($back['location'], 'login') === true, 'HTTP ' . $back['status'] . ' ' . $back['location']);
$lines = auditLines($melRequest);
$said  = [act($jar['adm'], $melRequest, 'cancel'), act($jar['adm'], $melRequest, 'execute'), act($jar['adm'], $melRequest, 'putback')];
ok('a completed request cannot be cancelled, run again or put back: it stays "completed" and gains no audit line',
    request($melRequest)['status'] === 'completed' && auditLines($melRequest) === $lines && str_contains($said[0], 'can no longer be cancelled')
    && str_contains($said[1], 'not in a reviewable state') && str_contains($said[2], 'no longer at "processing"'), implode(' | ', $said));

// =============================================================================
head('Nothing went wrong behind the pages, and everything is put back');
// =============================================================================
$log = (string) @file_get_contents($child . $sep . 'php-errors.log');
$bad = array_values(array_filter((array) preg_split('/\R/', $log), static fn (string $line): bool => preg_match('/PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught/', $line) === 1));
ok('the server\'s PHP error log holds no warning, notice or fatal error', $bad === [], count($bad) . ' found: ' . implode(' | ', array_map(static fn (string $line): string => mb_substr($line, 0, 220), array_slice($bad, 0, 3))));

$settingsBefore = $state['settings'];
cleanup();
$left = (int) (one(
    'SELECT (SELECT COUNT(*) FROM tblSites WHERE siteID IN (?, ?)) + (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 917000 AND 917999) '
    . '+ (SELECT COUNT(*) FROM tblErasureRequest WHERE siteID IN (?, ?)) + (SELECT COUNT(*) FROM tblSettings WHERE siteID IN (?, ?)) AS n',
    'iiiiii', [ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B]
)['n'] ?? -1);
$settingsBack = true;
foreach ($settingsBefore as $key => $row) {
    $settingsBack = $settingsBack && (one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]) === $row);
}
ok('clean-up: the made-up world is gone and the two portal-wide settings are as they were', $left === 0 && $settingsBack === true, 'rows left: ' . $left);
ok('clean-up: the server is stopped and the scratch folder is deleted', portAnswers($port) === false && file_exists($child) === false);

echo "\n{$pass} passed, {$fail} failed, " . ($pass + $fail) . ' ran of ' . EXPECTED_CHECKS . " expected.\n";
if (($pass + $fail) !== EXPECTED_CHECKS) {
    echo "FAIL — the number of checks that ran is not the number this file expects. A block was skipped or ran twice,\n";
    echo "or a check was added without updating the number at the top.\n";
    exit(1);
}
if ($fail > 0) {
    echo "FAIL — {$fail} check(s) failed. Read the FAIL lines above before releasing.\n";
    exit(1);
}
echo "PASS — all {$pass} checks passed.\n";
exit(0);
