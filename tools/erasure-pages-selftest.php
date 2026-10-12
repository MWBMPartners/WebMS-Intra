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
 *   G. (Roll-out package A2b.) The giving-records settings page: a member is
 *      refused; a press with no form token, and a value outside its list,
 *      save nothing; an administrator of ANOTHER organisation cannot change
 *      this one's rule and can change their own; a change that would clear
 *      kept donor records is refused until the box is ticked; and the page
 *      has one top heading, a label for every field, and no ".php" address.
 *      Then the privacy page (this organisation's own number of years), and
 *      the retention sweep pressed on its staff page: refused to a member and
 *      to one organisation's administrator, and for a global administrator
 *      it removes a kept name and writes the line on the erasure's trail.
 *   H. (The fix round after the independent check, 12 October 2026.) A
 *      request whose run dealt with one organisation's giving records and
 *      then stopped CANNOT BE CANCELLED, and says why; nor can one whose run
 *      held no giving records but had already deleted the person's
 *      membership; one that was never run still can. The general settings editor refuses to change, add or
 *      delete a giving-records setting, for an organisation's administrator
 *      and a global one alike, except the installation-wide hold. That hold
 *      is shown apart from the organisation's own, and saving the page does
 *      not switch the organisation's own on. And the privacy page says
 *      "every gift" for an organisation that keeps the name on every gift,
 *      before it has confirmed a figure as well as after.
 *
 * TAKING PICTURES OF THE SETTINGS PAGE
 * ------------------------------------
 * Set SELFTEST_SNAPSHOT_FILE to the path of a file that does not exist yet.
 * Section G then saves the settings page, as an administrator sees it with
 * long text on it, where the test server can serve it without a sign-in,
 * writes that address into the file, and WAITS (for up to five minutes) until
 * the file is deleted. A browser can be pointed at the address meanwhile, at
 * any width. Without the variable nothing is saved and nothing waits. It is
 * here because how a page looks at phone and desktop widths has to be
 * checked in a real browser before a release, and this is the only place a
 * signed-in page is running to be looked at.
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
 *         root), SELFTEST_WEB_PORT (default 9071), SELFTEST_SNAPSHOT_FILE
 *         (see above; optional). The database must already
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
const EXPECTED_CHECKS = 57;

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
const ADDR_B = '/selftest-erasure-b';

const ADM  = 917011; // an administrator of A
const ROOT = 917012; // a member of A who is a root administrator of the installation
const MEL  = 917013; // a member of A, and a busy volunteer: presses "Delete my account"
const NIA  = 917014; // a member of A: her request is stuck at "processing"
const CAL  = 917015; // a member of A whose records Mel worked on; files an ordinary request
const ADMB = 917016; // an administrator of B ONLY
const GIO  = 917017; // a member of A who gives at A and at B: his run stops part way (section H)
const HAL  = 917018; // a member of A who gives at A: his request is never run, and is cancelled (section H)
const IVY  = 917019; // a member of A with NO giving records: her run stops after deleting her membership (section H)

const REQ_STUCK = 917101; // Nia's, at "processing", its run dead for 20 minutes
const REQ_LIVE  = 917102; // Cal's, at "processing", a run showed signs of life 1 minute ago
const REQ_B_PUT = 917103; // ORG_B's, at "processing", dead for 20 minutes
const REQ_B_ASK = 917104; // ORG_B's, waiting to be confirmed
const REQ_GIO   = 917901; // Gio's, at "pending review" (section H)
const REQ_HAL   = 917902; // Hal's, at "pending review" (section H)
const REQ_IVY   = 917903; // Ivy's, at "pending review" (section H)
const REQ_KEPT  = 917900; // ORG_A's, carried out long ago: the request a kept donor record belongs to (section G). Far from the others, because the requests the pages file take the numbers that follow them

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
    q('DELETE FROM tblGivingKeptDonors WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblGivingEntry WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblGivingCategory WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // Section H plants this to stop a run. Taken away here too, in case the
    // run was interrupted while it was in place.
    $db->query('DROP TRIGGER IF EXISTS `selftest_erasure_pages_stop_kept`');
    $db->query('DROP TRIGGER IF EXISTS `selftest_erasure_pages_stop_line`');
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
 * address (or another organisation's, when `$address` says so). Redirects are
 * never followed. An answer without this run's header came from somewhere
 * else and is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null, string $address = ADDR_A): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . $address . $path);
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
head('G. The giving-records settings page (roll-out package A2b)');
// =============================================================================
// Each organisation's rule for how long giving records keep a donor's name
// after the donor has asked to be erased. It decides when personal records are
// cleared, so who may change it, and whose, is what matters most here.
$givingPath = '/admin/settings/giving-records';
$givingRows = static fn (int $site): array => array_column(
    q("SELECT settingKey, settingValue FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'giving.retention.%' ORDER BY settingKey", 'i', [$site]),
    'settingValue',
    'settingKey'
);
/** The first coloured box on the settings page: "[colour] words". */
$givingSaid = static fn (string $html): string => preg_match('/<div class="alert alert-(\w+)" role="status">(.*?)<\/div>/s', $html, $m) === 1
    ? '[' . $m[1] . '] ' . text($m[2]) : '(no message shown)';
$givingForm = static fn (array $over = []): array => $over + ['countryCode' => '', 'scope' => 'receipted', 'years' => '', 'yearEndMonth' => '', 'yearEndDay' => ''];
// A country the data file has a figure for that is not below the minimum, and
// one with as many warnings as possible for the picture. Read from the file,
// so no country is named in this test.
$figures  = json_decode((string) file_get_contents($webSrc . $sep . '_core' . $sep . 'data' . $sep . 'giving-retention-countries.json'), true);
$plain    = null;
$awkward  = null;
foreach ((array) ($figures['countries'] ?? []) as $code => $row) {
    if ($plain === null && $row['yearsFound'] !== null && $row['yearsFound'] >= $figures['minimumSuggestedYears']) {
        $plain = ['code' => (string) $code] + $row;
    }
    $weight = strlen((string) $row['note']) + 200 * count((array) $row['sources']);
    if ($row['yearsFound'] !== null && $row['yearsFound'] < $figures['minimumSuggestedYears'] && $row['needsAdvice'] === true
        && in_array($row['erasureException'], ['weak', 'not-found'], true) === true && ($awkward === null || $weight > $awkward['weight'])
    ) {
        $awkward = ['code' => (string) $code, 'weight' => $weight] + $row;
    }
}
if ($plain === null || $awkward === null) {
    refuse('the country figures file holds no country to test the settings page with');
}

$memberSees = req($jar['cal'], 'GET', $givingPath);
$memberSave = req($jar['cal'], 'POST', $givingPath . '/save', $givingForm(['csrf_token' => token(req($jar['cal'], 'GET', '/account/delete')['body']), 'countryCode' => $plain['code']]));
ok('A MEMBER is refused the page and the save (HTTP 403 each), though their form token is a good one, and nothing is written',
    $memberSees['status'] === 403 && $memberSave['status'] === 403 && $givingRows(ORG_A) === []
    && str_contains($memberSees['body'], 'name="countryCode"') === false, 'HTTP ' . $memberSees['status'] . ' and ' . $memberSave['status'] . '; ' . json_encode($givingRows(ORG_A)));

$page     = req($jar['adm'], 'GET', $givingPath);
$pageText = text($page['body']);
preg_match_all('/<(?:input|select)\b[^>]*\bid="([^"]+)"/', $page['body'], $fields);
$unlabelled = array_values(array_filter($fields[1], static fn (string $id): bool => str_contains($page['body'], 'for="' . $id . '"') === false));
preg_match_all('/\b(?:href|action|src)="([^"]*\.(?:php|asp|aspx|jsp|cgi)(?:[?#][^"]*)?)"/i', $page['body'], $bareFiles);
ok('AN ADMINISTRATOR sees the page: no country set yet, so a cautious placeholder that is "not a finding"; "not confirmed"; "not legal advice"; and never a sentence saying what the law requires',
    $page['status'] === 200 && str_contains($pageText, 'Your organisation has not said which country it is in')
    && str_contains($pageText, 'cautious placeholder, not a finding') && str_contains($pageText, 'This figure has not been confirmed')
    && str_contains($pageText, 'nothing is cleared before then') && str_contains($pageText, 'This is our reading of the rules, not legal advice')
    && preg_match('/the law requires|required by law|legally required/i', $pageText) !== 1
    && str_contains($pageText, 'No erased donor\'s records are being kept'), mb_substr($pageText, 0, 900));
ok('...it has ONE top heading, a label for every field, no address ending in ".php", and its form posts to this organisation\'s own address',
    preg_match_all('/<h1\b/', $page['body']) === 1 && count($fields[1]) >= 7 && $unlabelled === [] && $bareFiles[1] === []
    && str_contains($page['body'], 'action="' . ADDR_A . $givingPath . '/save"') === true,
    'h1: ' . preg_match_all('/<h1\b/', $page['body']) . '; fields with no label: ' . implode(', ', $unlabelled) . '; bare files: ' . implode(', ', $bareFiles[1]));

$noToken = req($jar['adm'], 'POST', $givingPath . '/save', $givingForm(['countryCode' => $plain['code']]));
$told    = $givingSaid(req($jar['adm'], 'GET', $givingPath)['body']);
$page    = req($jar['adm'], 'GET', $givingPath);
req($jar['adm'], 'POST', $givingPath . '/save', $givingForm(['csrf_token' => token($page['body']), 'countryCode' => $plain['code'], 'scope' => 'everything']));
$toldList = $givingSaid(req($jar['adm'], 'GET', $givingPath)['body']);
$page     = req($jar['adm'], 'GET', $givingPath);
req($jar['adm'], 'POST', $givingPath . '/save', $givingForm(['csrf_token' => token($page['body']), 'countryCode' => $plain['code'], 'years' => '51']));
$toldYears = $givingSaid(req($jar['adm'], 'GET', $givingPath)['body']);
ok('with NO form token, with a choice that is not on the page, and with 51 years, nothing is saved, and each time the page says so',
    $givingRows(ORG_A) === [] && str_contains($told, 'Invalid or expired form token. Nothing was changed')
    && str_contains($toldList, 'has to be one of the two choices on the page. Nothing was saved')
    && str_contains($toldYears, 'whole number from 1 to 50') && str_contains($toldYears, 'Nothing was saved'),
    'HTTP ' . $noToken['status'] . ' | ' . $told . ' | ' . $toldList . ' | ' . $toldYears);

$page  = req($jar['adm'], 'GET', $givingPath);
$saved = req($jar['adm'], 'POST', $givingPath . '/save', $givingForm(['csrf_token' => token($page['body']), 'countryCode' => $plain['code'], 'yearEndMonth' => '3', 'yearEndDay' => '31',
    'siteID' => ORG_B, 'site' => ORG_B]));
$after = req($jar['adm'], 'GET', $givingPath);
$afterText = text($after['body']);
ok('a good save is for THIS organisation only, whatever else is posted: its country, year end and "not confirmed" are stored, the other organisation has no setting, and the page now shows the suggestion for that country with how sure the source is',
    $saved['status'] === 302 && $saved['location'] === ADDR_A . $givingPath && str_contains($givingSaid($after['body']), 'Saved for your organisation')
    && ($givingRows(ORG_A)['giving.retention.countryCode'] ?? '') === $plain['code'] && ($givingRows(ORG_A)['giving.retention.yearEnd'] ?? '') === '03-31'
    && ($givingRows(ORG_A)['giving.retention.confirmed'] ?? '') === 'false' && count($givingRows(ORG_A)) === 7 && $givingRows(ORG_B) === []
    && str_contains($afterText, 'Our suggestion for ' . $plain['name'] . ' is ' . $plain['yearsFound'] . ' years') && str_contains($afterText, 'How sure we are:')
    && str_contains($afterText, 'your year ends on 31 March'),
    'HTTP ' . $saved['status'] . ' ' . $saved['location'] . ' | ' . $givingSaid($after['body']) . ' | ' . json_encode([$givingRows(ORG_A), $givingRows(ORG_B)]));

// The administrator of organisation B: a good form token of their own, from
// their own organisation's copy of this page.
$ownPage   = req($jar['admb'], 'GET', $givingPath, null, ADDR_B);
$theirs    = token($ownPage['body']);
$beforeA   = $givingRows(ORG_A);
$trespass  = req($jar['admb'], 'POST', $givingPath . '/save', $givingForm(['csrf_token' => $theirs, 'countryCode' => '', 'hold' => '1']));
$peek      = req($jar['admb'], 'GET', $givingPath);
$ownSave   = req($jar['admb'], 'POST', $givingPath . '/save', $givingForm(['csrf_token' => $theirs, 'countryCode' => $awkward['code'], 'hold' => '1']), ADDR_B);
ok('AN ADMINISTRATOR OF ANOTHER ORGANISATION cannot open or change this organisation\'s rule (refused, and not one of its settings moves), and CAN save their own, which changes only their own',
    $ownPage['status'] === 200 && $theirs !== '' && $trespass['status'] !== 302 && $peek['status'] !== 200 && $givingRows(ORG_A) === $beforeA
    && $ownSave['status'] === 302 && $ownSave['location'] === ADDR_B . $givingPath
    && ($givingRows(ORG_B)['giving.retention.countryCode'] ?? '') === $awkward['code'] && ($givingRows(ORG_B)['giving.retention.hold'] ?? '') === 'true',
    'at A: HTTP ' . $trespass['status'] . ' and ' . $peek['status'] . '; at B: HTTP ' . $ownSave['status'] . ' ' . $ownSave['location'] . ' ' . json_encode($givingRows(ORG_B)));

// A kept donor record, long past any keep-until date, with a very long name.
// It belongs to an erasure request that was carried out long ago, as every
// real one does: that request's trail is where the clearing is recorded.
$longName = 'Bartholomew-Maximilian Featherstonehaugh-Cholmondeley of the Parish of Saint Peter-in-the-Great-Wilderness '
    . str_repeat('Wolfeschlegelsteinhausenbergerdorff', 3);
q("INSERT INTO tblErasureRequest (requestID, siteID, userID, subjectEmail, status, dueBy) VALUES (?, ?, NULL, 'deleted-917900@example.invalid', 'completed', NOW())", 'ii', [REQ_KEPT, ORG_A]);
q("INSERT INTO tblGivingKeptDonors (siteID, donorID, erasureRequestID, fullName, address, postcode, scope, lastGiftDate, keptGiftCount, createdAt) "
    . "VALUES (?, ?, ?, ?, '1 Made-up Road', 'ZZ1 1ZZ', 'receipted', '2001-02-03', 14, UTC_TIMESTAMP())", 'iiis', [ORG_A, NIA, REQ_KEPT, $longName]);
$page     = req($jar['adm'], 'GET', $givingPath);
$pageText = text($page['body']);
$beforeA  = $givingRows(ORG_A);
$slip     = req($jar['adm'], 'POST', $givingPath . '/save', $givingForm(['csrf_token' => token($page['body']), 'countryCode' => $plain['code'], 'confirmed' => '1', 'yearEndMonth' => '3', 'yearEndDay' => '31']));
$slipSaid = $givingSaid(req($jar['adm'], 'GET', $givingPath)['body']);
ok('a kept donor record is listed with the name, the last gift, the keep-until date and why it is still kept; and CONFIRMING a figure that would clear it at once is REFUSED until the box is ticked, with nothing saved',
    str_contains($pageText, $longName) && str_contains($pageText, '3 February 2001') && str_contains($pageText, 'Waiting: the number of years is not confirmed')
    && str_contains($slipSaid, 'remove the name and address of 1 erased donor') && str_contains($slipSaid, 'Nothing was saved')
    && $givingRows(ORG_A) === $beforeA && one('SELECT fullName, clearedAt FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [ORG_A, NIA]) === ['fullName' => $longName, 'clearedAt' => null],
    $slipSaid);
$page     = req($jar['adm'], 'GET', $givingPath);
req($jar['adm'], 'POST', $givingPath . '/save', $givingForm(['csrf_token' => token($page['body']), 'countryCode' => $plain['code'], 'confirmed' => '1', 'understood' => '1', 'yearEndMonth' => '3', 'yearEndDay' => '31']));
$done     = req($jar['adm'], 'GET', $givingPath);
ok('with the box ticked it saves: the figure is fixed as the organisation\'s own, the page says one record is now past its date, and that record is still there (the PAGE clears nothing; the retention job does)',
    str_contains($givingSaid($done['body']), 'Saved for your organisation') && str_contains($givingSaid($done['body']), '1 kept donor record is now past the keep-until date')
    && ($givingRows(ORG_A)['giving.retention.confirmed'] ?? '') === 'true' && ($givingRows(ORG_A)['giving.retention.years'] ?? '') === (string) $plain['yearsFound']
    && str_contains(text($done['body']), 'Past its date: cleared at the next retention run')
    && (string) one('SELECT fullName FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [ORG_A, NIA])['fullName'] === $longName,
    $givingSaid($done['body']) . ' | ' . json_encode($givingRows(ORG_A)));

// Two more pages this package changed. The privacy page, which every member
// can open, used to give one installation-wide number of years "to comply
// with HMRC requirements". And the staff page for the retention sweep, which
// only a global administrator may open, now counts the kept donor records.
$privacy     = req($jar['cal'], 'GET', '/privacy/policy');
$privacyText = text($privacy['body']);
$sweepPage   = req($jar['root'], 'GET', '/admin/maintenance/retention');
$sweepText   = text($sweepPage['body']);
ok('THE PRIVACY PAGE gives THIS organisation\'s confirmed number of years, names no country\'s tax office, and says the records are then cleared automatically; THE SWEEP PAGE shows a global administrator one kept donor record that would be cleared now',
    $privacy['status'] === 200 && str_contains($privacyText, 'keep your name and home address for ' . $plain['yearsFound'] . ' years after the end of the financial year of your last such gift')
    && str_contains($privacyText, 'They are then cleared automatically') && preg_match('/HMRC/', $privacyText) !== 1
    && $sweepPage['status'] === 200 && str_contains($sweepText, 'Giving records that kept an erased donor\'s name')
    && str_contains($sweepText, 'Erased donors whose name would be removed now: 1') && str_contains($sweepText, 'Nothing is deleted here'),
    'privacy: HTTP ' . $privacy['status'] . ' | sweep: HTTP ' . $sweepPage['status'] . ' ' . mb_substr($sweepText, (int) strpos($sweepText, 'Giving records that kept'), 400));

// 📸 Optional: leave the page where a browser can reach it, and wait.
$snapshotFile = getenv('SELFTEST_SNAPSHOT_FILE');
if ($snapshotFile !== false && $snapshotFile !== '' && file_exists((string) $snapshotFile) === false) {
    // The country with the most to say: a figure raised to the minimum,
    // doubtful sources, an unclear exception, a long note, several links.
    siteSetting(ORG_A, 'giving.retention.countryCode', $awkward['code']);
    siteSetting(ORG_A, 'giving.retention.confirmed', 'false');
    siteSetting(ORG_A, 'giving.retention.years', '');
    $picture = req($jar['adm'], 'GET', $givingPath)['body'];
    $saveAs  = $child . $sep . 'web' . $sep . 'public_html' . $sep . 'selftest-giving-records-snapshot.html';
    file_put_contents($saveAs, $picture);
    file_put_contents((string) $snapshotFile, 'http://127.0.0.1:' . $port . '/selftest-giving-records-snapshot.html' . "\n");
    echo '   the settings page is saved for a browser; waiting until ' . $snapshotFile . " is deleted (five minutes at most)\n";
    $until = time() + 300;
    while (file_exists((string) $snapshotFile) === true && time() < $until) {
        clearstatcache(true, (string) $snapshotFile);
        usleep(250000);
    }
    @unlink($saveAs);
    // Put the rule back as section G left it, for the check that follows.
    siteSetting(ORG_A, 'giving.retention.countryCode', $plain['code']);
    siteSetting(ORG_A, 'giving.retention.confirmed', 'true');
    siteSetting(ORG_A, 'giving.retention.years', (string) $plain['yearsFound']);
}

// The sweep itself, pressed on the staff page by a global administrator. A
// member, and an administrator of one organisation, are refused first.
$sweepPath   = '/admin/maintenance/retention';
$memberSweep = req($jar['cal'], 'POST', $sweepPath, ['csrf_token' => token(req($jar['cal'], 'GET', '/account/delete')['body'])]);
$adminSweep  = req($jar['adm'], 'POST', $sweepPath, ['csrf_token' => token(req($jar['adm'], 'GET', $givingPath)['body'])]);
$stillKept   = one('SELECT fullName, clearedAt FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [ORG_A, NIA]);
$pressed     = req($jar['root'], 'POST', $sweepPath, ['csrf_token' => token(req($jar['root'], 'GET', $sweepPath)['body'])]);
$pressedText = text($pressed['body']);
$nowCleared  = one('SELECT fullName, address, postcode, clearedAt FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [ORG_A, NIA]);
$trailLine   = one('SELECT action, tableName, details FROM tblErasureAudit WHERE requestID = ? ORDER BY auditID DESC LIMIT 1', 'i', [REQ_KEPT]);
ok('THE SWEEP, PRESSED ON ITS PAGE: a member and an organisation\'s own administrator are refused (HTTP 403) and the name stays; a global administrator\'s press removes the name and address, says so without calling it a deletion, and writes the line on that erasure\'s trail',
    $memberSweep['status'] === 403 && $adminSweep['status'] === 403 && $stillKept === ['fullName' => $longName, 'clearedAt' => null]
    && $pressed['status'] === 200 && str_contains($pressedText, 'Giving records: removed the name and address of 1 erased donor(s) whose keep-until date had passed')
    && $nowCleared !== null && $nowCleared['fullName'] === null && $nowCleared['address'] === null && $nowCleared['postcode'] === null && $nowCleared['clearedAt'] !== null
    && $trailLine !== null && $trailLine['action'] === 'anonymise' && $trailLine['tableName'] === 'GivingRecords'
    && str_contains((string) $trailLine['details'], 'has passed') && str_contains((string) $trailLine['details'], $longName) === false,
    'HTTP ' . $memberSweep['status'] . ', ' . $adminSweep['status'] . ', ' . $pressed['status'] . ' | ' . json_encode([$stillKept, $nowCleared, $trailLine]));

// =============================================================================
head('H. A cancel that is refused, settings that are locked, and the two holds (fix round, 12 October 2026)');
// =============================================================================
// Gio gives at both organisations. His run deals with organisation A (the
// request's own) and is stopped at B, by a trigger that refuses the kept-names
// row there. Organisation A is then in the state a cancel cannot undo: a gift
// that is not kept has already lost him, and nothing records which one.
foreach ([GIO => 'Gio Giver', HAL => 'Hal Hasnotrun', IVY => 'Ivy Nogifts'] as $id => $name) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, $name, 'u' . $id . '.erasure@selftest.invalid']);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [$id, ORG_A]);
}
$category = [];
foreach ([ORG_A, ORG_B] as $site) {
    q("INSERT INTO tblGivingCategory (siteID, name) VALUES (?, 'Selftest erasure offering')", 'i', [$site]);
    $category[$site] = (int) one('SELECT MAX(categoryID) AS id FROM tblGivingCategory WHERE siteID = ?', 'i', [$site])['id'];
}
$gift = static function (int $site, int $donor, string $date, int $pence) use ($category): int {
    q("INSERT INTO tblGivingEntry (siteID, donorID, categoryID, amountPence, donatedAt, method) VALUES (?, ?, ?, ?, ?, 'cash')", 'iiiis', [$site, $donor, $category[$site], $pence, $date]);

    return (int) one('SELECT MAX(entryID) AS id FROM tblGivingEntry WHERE siteID = ? AND donorID = ?', 'ii', [$site, $donor])['id'];
};
$donorOf = static fn (int $entry): ?int => (static fn (?array $row): ?int => $row === null || $row['donorID'] === null ? null : (int) $row['donorID'])(one('SELECT donorID FROM tblGivingEntry WHERE entryID = ?', 'i', [$entry]));
$recent  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-20 days')->format('Y-m-d');
foreach ([[ORG_A, GIO], [ORG_B, GIO], [ORG_A, HAL]] as [$site, $donor]) {
    q("INSERT INTO tblGiftAidDeclaration (siteID, donorID, address, postcode, validFrom) VALUES (?, ?, '2 Made-up Road', 'ZZ2 2ZZ', '2020-01-01')", 'ii', [$site, $donor]);
}
$gioKept   = $gift(ORG_A, GIO, $recent, 2500);         // inside his declaration: kept
$gioUnkept = $gift(ORG_A, GIO, '2019-06-01', 1500);    // before it: not kept
$gioAtB    = $gift(ORG_B, GIO, $recent, 3500);
$halUnkept = $gift(ORG_A, HAL, '2019-06-01', 4500);
$seed(REQ_GIO, ORG_A, GIO, 'pending_review', null);
$seed(REQ_HAL, ORG_A, HAL, 'pending_review', null);
// The rule at organisation A is confirmed by now (section G), and its sweep
// has been pressed. A hold keeps Gio's kept record where it is for this
// section, whatever its date.
siteSetting(ORG_A, 'giving.retention.hold', 'true');
$db->query('CREATE TRIGGER `selftest_erasure_pages_stop_kept` BEFORE INSERT ON `tblGivingKeptDonors` FOR EACH ROW BEGIN '
    . 'IF NEW.siteID = ' . ORG_B . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted by the self-test'; END IF; END");
$stopped = act($jar['adm'], REQ_GIO, 'execute');
$db->query('DROP TRIGGER IF EXISTS `selftest_erasure_pages_stop_kept`');
$gioRow  = one('SELECT fullName, clearedAt FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [ORG_A, GIO]);
ok('A RUN THAT STOPS AFTER ONE ORGANISATION\'S GIVING RECORDS: the page says it stopped, the request is back at "pending review", Gio is still a member by name, his name is already kept at organisation A, and the gift that is not kept there has already lost him',
    str_contains($stopped, 'stopped before it finished') && request(REQ_GIO)['status'] === 'pending_review'
    && one('SELECT fullName, isActive FROM tblUsers WHERE userID = ?', 'i', [GIO]) === ['fullName' => 'Gio Giver', 'isActive' => 1]
    && $gioRow === ['fullName' => 'Gio Giver', 'clearedAt' => null] && $donorOf($gioUnkept) === null && $donorOf($gioKept) === GIO && $donorOf($gioAtB) === GIO,
    $stopped . ' | ' . json_encode([request(REQ_GIO)['status'], $gioRow]));

$linesBefore = auditLines(REQ_GIO);
$refused     = act($jar['adm'], REQ_GIO, 'cancel');
$refusedLog  = (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = 'GdprErasureCancelRefused' AND userID = ?", 'i', [ADM])['n'];
ok('THAT REQUEST CANNOT BE CANCELLED: the page says so, says why, and says to run it again; the request is still at "pending review" with no new audit line; the kept name and the gifts are as they were; and the refusal is in the activity log',
    str_contains($refused, '[warning] This request cannot be cancelled: a run of it has already removed or changed some of this person\'s records and then stopped')
    && str_contains($refused, 'Run the request again to finish it') && str_contains($refused, 'Nothing was changed')
    && request(REQ_GIO)['status'] === 'pending_review' && auditLines(REQ_GIO) === $linesBefore && $refusedLog === 1
    && one('SELECT fullName, clearedAt FROM tblGivingKeptDonors WHERE siteID = ? AND donorID = ?', 'ii', [ORG_A, GIO]) === $gioRow
    && $donorOf($gioKept) === GIO && $donorOf($gioAtB) === GIO,
    $refused . ' | ' . json_encode([request(REQ_GIO)['status'], auditLines(REQ_GIO), $linesBefore, $refusedLog]));

$cancelled = act($jar['adm'], REQ_HAL, 'cancel');
ok('A REQUEST THAT WAS NEVER RUN CAN STILL BE CANCELLED, giving records or not: it is "cancelled", and Hal, his declaration and his gift are untouched',
    str_contains($cancelled, 'Erasure request cancelled') && request(REQ_HAL)['status'] === 'cancelled' && $donorOf($halUnkept) === HAL
    && one('SELECT fullName, isActive FROM tblUsers WHERE userID = ?', 'i', [HAL]) === ['fullName' => 'Hal Hasnotrun', 'isActive' => 1]
    && (int) one('SELECT COUNT(*) AS n FROM tblGivingKeptDonors WHERE donorID = ?', 'i', [HAL])['n'] === 0
    && one('SELECT address FROM tblGiftAidDeclaration WHERE siteID = ? AND donorID = ?', 'ii', [ORG_A, HAL])['address'] === '2 Made-up Road',
    $cancelled . ' | ' . json_encode(request(REQ_HAL)['status']));

// IVY HAS NO GIVING RECORDS AT ALL. Her run is stopped at the audit line of
// her account: the account is not emptied, but the walk has already deleted
// her membership of the organisation. (Added after the narrow check of 12
// October 2026: the cancel was refused only when GIVING records had been
// dealt with, so this request could be cancelled and she was left a member
// no longer, beside a request that said nothing had happened.)
$seed(REQ_IVY, ORG_A, IVY, 'pending_review', null);
$db->query('CREATE TRIGGER `selftest_erasure_pages_stop_line` BEFORE INSERT ON `tblErasureAudit` FOR EACH ROW BEGIN '
    . 'IF NEW.requestID = ' . REQ_IVY . " AND NEW.tableName = 'tblUsers' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted by the self-test'; END IF; END");
$ivyStopped = act($jar['adm'], REQ_IVY, 'execute');
$db->query('DROP TRIGGER IF EXISTS `selftest_erasure_pages_stop_line`');
$ivyMember  = (int) one('SELECT COUNT(*) AS n FROM tblUserSites WHERE userID = ?', 'i', [IVY])['n'];
$ivyGiving  = (int) one("SELECT COUNT(*) AS n FROM tblErasureAudit WHERE requestID = ? AND tableName IN ('GivingRecords', 'CountEnvelopes') AND action <> 'none'", 'i', [REQ_IVY])['n'];
$ivyLines   = auditLines(REQ_IVY);
$ivyRefused = act($jar['adm'], REQ_IVY, 'cancel');
$ivyAfter   = request(REQ_IVY)['status'];
$ivyDone    = act($jar['adm'], REQ_IVY, 'execute');
ok('A REQUEST WHOSE RUN HELD NO GIVING RECORDS BUT HAD ALREADY DELETED SOMETHING CANNOT BE CANCELLED EITHER: her run stopped with her account still hers and her organisation membership gone; the cancel is refused with the same plain message, the request stays at "pending review" with no new audit line; and run again it completes',
    str_contains($ivyStopped, 'stopped before it finished') && $ivyMember === 0 && $ivyGiving === 0
    && str_contains($ivyRefused, '[warning] This request cannot be cancelled: a run of it has already removed or changed some of this person\'s records and then stopped')
    && str_contains($ivyRefused, 'Run the request again to finish it') && $ivyAfter === 'pending_review'
    && str_contains($ivyDone, 'is completed') && request(REQ_IVY)['status'] === 'completed'
    && one('SELECT fullName, isActive FROM tblUsers WHERE userID = ?', 'i', [IVY]) === ['fullName' => '[Deleted User]', 'isActive' => 0],
    $ivyStopped . ' | members: ' . $ivyMember . ' | ' . $ivyRefused . ' | ' . $ivyAfter . ' | ' . $ivyDone);

$finished = act($jar['adm'], REQ_GIO, 'execute');
ok('RUN AGAIN, Gio\'s request finishes: completed, the account is emptied, and his name is kept at BOTH organisations',
    str_contains($finished, 'is completed') && request(REQ_GIO)['status'] === 'completed'
    && one('SELECT fullName, isActive FROM tblUsers WHERE userID = ?', 'i', [GIO]) === ['fullName' => '[Deleted User]', 'isActive' => 0]
    && array_column(q('SELECT siteID, fullName FROM tblGivingKeptDonors WHERE donorID = ? ORDER BY siteID', 'i', [GIO]), 'fullName', 'siteID') === [ORG_A => 'Gio Giver', ORG_B => 'Gio Giver'],
    $finished);

// THE GENERAL SETTINGS EDITOR. It has no un-tick and no second tick, so it
// may not change these rows. Each press below carries a good form token.
$idOf = static fn (?int $site, string $key): int => (int) (one(
    'SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID ' . ($site === null ? 'IS NULL' : '= ' . $site), 's', [$key]
)['settingID'] ?? 0);
$settingsPress = static function (string $jar, string $path, array $post): string {
    $page = req($jar, 'GET', '/settings');
    req($jar, 'POST', $path, ['csrf_token' => token($page['body'])] + $post);
    // This page's coloured box carries a close button, so its opening tag is
    // not the one message() looks for.
    $after = req($jar, 'GET', '/settings')['body'];

    return preg_match('/<div class="alert alert-(\w+) alert-dismissible fade show">(.*?)<\/div>/s', $after, $m) === 1
        ? '[' . $m[1] . '] ' . text($m[2]) : '(no message shown)';
};
$rowsBefore   = [$givingRows(ORG_A), $givingRows(ORG_B)];
$wideBefore   = array_column(q("SELECT settingKey, settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey LIKE 'giving.retention.%' ORDER BY settingKey"), 'settingValue', 'settingKey');
$listPage     = req($jar['adm'], 'GET', '/settings');
$saidEdit     = $settingsPress($jar['adm'], '/settings/save', ['settingID' => $idOf(ORG_A, 'giving.retention.hold'), 'settingKey' => 'giving.retention.hold', 'settingValue' => 'false']);
$saidYears    = $settingsPress($jar['adm'], '/settings/save', ['settingID' => $idOf(ORG_A, 'giving.retention.years'), 'settingKey' => 'giving.retention.years', 'settingValue' => '1']);
$saidAdd      = $settingsPress($jar['adm'], '/settings/save', ['settingID' => 0, 'settingKey' => 'Giving.Retention.somethingNew', 'settingValue' => 'true']);
$saidDelete   = $settingsPress($jar['adm'], '/settings', ['action' => 'delete', 'settingID' => $idOf(ORG_A, 'giving.retention.yearEnd')]);
$saidRootEdit = $settingsPress($jar['root'], '/settings/save', ['settingID' => $idOf(null, 'giving.retention.confirmed'), 'settingKey' => 'giving.retention.confirmed', 'settingValue' => 'true']);
$saidRootOwn  = $settingsPress($jar['root'], '/settings/save', ['settingID' => $idOf(ORG_A, 'giving.retention.hold'), 'settingKey' => 'giving.retention.hold', 'settingValue' => 'false']);
$saidRootDel  = $settingsPress($jar['root'], '/settings', ['action' => 'delete', 'settingID' => $idOf(null, 'giving.retention.hold')]);
$lockedWords  = 'It can only be changed at Admin, Giving records';
$wideNow      = array_column(q("SELECT settingKey, settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey LIKE 'giving.retention.%' ORDER BY settingKey"), 'settingValue', 'settingKey');
ok('THE GENERAL SETTINGS EDITOR REFUSES every change to a giving-records setting: an organisation\'s administrator editing the hold and the years, adding a new one, and deleting the year end; and a GLOBAL administrator editing the installation-wide "confirmed", editing an organisation\'s own hold, and deleting the installation-wide hold. Each time the page says where to change it, and not one of those rows moved',
    count(array_filter([$saidEdit, $saidYears, $saidAdd, $saidDelete, $saidRootEdit, $saidRootOwn, $saidRootDel], static fn (string $said): bool => str_contains($said, '[danger]') && str_contains($said, $lockedWords))) === 7
    && [$givingRows(ORG_A), $givingRows(ORG_B)] === $rowsBefore && $wideNow === $wideBefore && count($wideBefore) === 7
    && (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey LIKE 'giving.retention.something%'")['n'] === 0,
    implode(' | ', [$saidEdit, $saidYears, $saidAdd, $saidDelete, $saidRootEdit, $saidRootOwn, $saidRootDel]));
ok('...and the list offers no Edit or Delete button for those rows, only a link to the page where they are changed',
    $listPage['status'] === 200 && str_contains($listPage['body'], 'data-setting-key="giving.retention.hold"') === true
    && str_contains($listPage['body'], 'data-key="giving.retention.') === false && str_contains($listPage['body'], 'Delete setting: giving.retention.') === false
    && str_contains($listPage['body'], 'href="' . ADDR_A . '/admin/settings/giving-records">Changed at Admin, Giving records</a>') === true,
    'HTTP ' . $listPage['status']);

// THE ONE ROW LEFT OPEN: the installation-wide hold, for a global
// administrator. Switching it on holds every organisation. Recorded first, so
// that the clean-up puts it back even if this run stops here.
$state['settings']['giving.retention.hold'] = one("SELECT settingID, settingValue FROM tblSettings WHERE settingKey = 'giving.retention.hold' AND siteID IS NULL");
siteSetting(ORG_A, 'giving.retention.hold', 'false');
$saidWide  = $settingsPress($jar['root'], '/settings/save', ['settingID' => $idOf(null, 'giving.retention.hold'), 'settingKey' => 'giving.retention.hold', 'settingValue' => 'true']);
$wideHold  = (string) one("SELECT settingValue FROM tblSettings WHERE settingKey = 'giving.retention.hold' AND siteID IS NULL")['settingValue'];
$heldPage  = req($jar['adm'], 'GET', $givingPath);
$heldText  = text($heldPage['body']);
$ownBox    = preg_match('/<input class="form-check-input" type="checkbox" id="hold" name="hold" value="1"\s*(checked)?\s*aria-describedby="holdHelp">/', $heldPage['body'], $box) === 1 ? ($box[1] ?? '') : 'not found';
// Saved exactly as the page shows it: the hold box is not ticked, so a
// browser sends no "hold" field at all.
req($jar['adm'], 'POST', $givingPath . '/save', $givingForm(['csrf_token' => token($heldPage['body']), 'countryCode' => $plain['code'], 'years' => (string) $plain['yearsFound'],
    'confirmed' => '1', 'yearEndMonth' => '3', 'yearEndDay' => '31']));
$afterSave = $givingSaid(req($jar['adm'], 'GET', $givingPath)['body']);
$ownHold   = $givingRows(ORG_A)['giving.retention.hold'] ?? '(no row)';
q("UPDATE tblSettings SET settingValue = 'false' WHERE settingKey = 'giving.retention.hold' AND siteID IS NULL");
$liftedText = text(req($jar['adm'], 'GET', $givingPath)['body']);
ok('THE TWO HOLDS ARE SHOWN APART, AND A SAVE DOES NOT TURN ONE INTO THE OTHER: a global administrator switches the installation-wide hold on in the general editor; the organisation\'s page says the whole installation is held, with its OWN box unticked; saving the page as shown leaves the organisation\'s own hold off; and when the installation-wide hold is lifted the page no longer says anything is held',
    str_contains($saidWide, '[danger]') === false && $wideHold === 'true' && $heldPage['status'] === 200
    && str_contains($heldText, 'A hold is switched on for the whole installation') && str_contains($heldText, 'A hold is switched on for your organisation') === false
    && str_contains($heldText, 'A global administrator has also switched a hold on for the whole installation') && $ownBox === ''
    && str_contains($afterSave, 'Saved for your organisation') && $ownHold === 'false'
    && str_contains($liftedText, 'A hold is switched on') === false && str_contains($liftedText, 'This is your organisation\'s own hold'),
    $saidWide . ' | own box: "' . $ownBox . '" | ' . $afterSave . ' | own hold row: ' . $ownHold);

// THE PRIVACY PAGE, for an organisation that keeps the name on EVERY gift
// and has not confirmed a figure. It used to say only "gifts covered by a tax
// declaration, a tax receipt or a giving statement", which was untrue there.
siteSetting(ORG_A, 'giving.retention.scope', 'all');
siteSetting(ORG_A, 'giving.retention.confirmed', 'false');
$everyGift = text(req($jar['cal'], 'GET', '/privacy/policy')['body']);
siteSetting(ORG_A, 'giving.retention.confirmed', 'true');
$everyGiftConfirmed = text(req($jar['cal'], 'GET', '/privacy/policy')['body']);
siteSetting(ORG_A, 'giving.retention.scope', 'receipted');
$narrow    = text(req($jar['cal'], 'GET', '/privacy/policy')['body']);
ok('THE PRIVACY PAGE says "(for this organisation, every gift)" when the organisation keeps the name on every gift, BEFORE it has confirmed a figure as well as after, and does not say it for an organisation that keeps it only on tax gifts',
    str_contains($everyGift, 'a giving statement (for this organisation, every gift) keep your name and home address for a number of years')
    && str_contains($everyGift, 'has not yet confirmed how many years')
    && str_contains($everyGiftConfirmed, 'a giving statement (for this organisation, every gift) keep your name and home address for ' . $plain['yearsFound'] . ' years')
    && str_contains($narrow, 'every gift') === false,
    mb_substr($everyGift, (int) strpos($everyGift, 'One exception'), 420));

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
    . '+ (SELECT COUNT(*) FROM tblErasureRequest WHERE siteID IN (?, ?)) + (SELECT COUNT(*) FROM tblSettings WHERE siteID IN (?, ?)) '
    . '+ (SELECT COUNT(*) FROM tblGivingEntry WHERE siteID IN (?, ?)) + (SELECT COUNT(*) FROM tblGivingCategory WHERE siteID IN (?, ?)) '
    . '+ (SELECT COUNT(*) FROM tblGivingKeptDonors WHERE siteID IN (?, ?)) '
    . "+ (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'selftest\\_erasure\\_pages\\_stop\\_%') AS n",
    'iiiiiiiiiiii', [ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B]
)['n'] ?? -1);
$settingsBack = true;
foreach ($settingsBefore as $key => $row) {
    $settingsBack = $settingsBack && (one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]) === $row);
}
ok('clean-up: the made-up world is gone and the portal-wide settings this run changed are as they were', $left === 0 && $settingsBack === true, 'rows left: ' . $left);
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
