<?php
// Path: tools/access-pages-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Who may see and do what, on the REAL pages — self-test 🚪💷
 * (#478, #570, #545, #587, and the access gaps of roll-out package B2a)
 * -----------------------------------------------------------------------------
 * `tools/app-switch-selftest.php` and `tools/expense-rules-selftest.php`
 * prove the rules and read the code as text. Reading text cannot see a
 * condition somebody later wraps round a call: with the words
 * `$isAdmin === false &&` put in front of the own-claim test, or the app
 * gate skipped whenever a request carried an API key, both of those files
 * still passed (found by the independent check of 11 October 2026). This
 * file is what fails then. It runs the portal and asks it.
 *
 * It is a trimmed copy of the page proofs written while roll-out package B
 * was built, kept as a test so the proof can be run again.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file, starts PHP's built-in web server on it behind a small
 * router that imitates `.htaccess`, signs real accounts in through the real
 * sign-in page, and sends real requests. Then it checks:
 *
 *   A. #478, one row per `api` folder: with ONLY that folder's app switched
 *      off, one of its endpoints answers 403 naming THAT app; with every app
 *      on, none is refused as switched off. The app each folder belongs to
 *      is written out by hand here, so a folder tied to the wrong app fails.
 *   B. #478, API keys: a key is judged by its OWN organisation's switch,
 *      whatever address it is sent to; a made-up key is never a way round.
 *   C. #478, a folder with no line in `AppRegistry::API_FOLDER_APPS` is
 *      refused and its handler never runs (the folder is made in the scratch
 *      copy only), while the same handler in a listed folder does run.
 *   D. #478 and #570: a switched-off app's stray pages show the "disabled"
 *      page, which names the right app and keeps its links inside the
 *      organisation's address; `/unsubscribe` answers whatever the
 *      Newsletter switch says.
 *   E. #545, through the real handlers: nobody approves, rejects or pays
 *      their own claim — a lead, a treasurer and an ADMINISTRATOR are each
 *      tried — and nothing is recorded; somebody else can; a claim with a
 *      required approver still needs them; the claim's own page tells such
 *      a claimant who decides it.
 *
 * Roll-out package B2a (12 October 2026) added parts F to L, and its fix
 * round the same day added to H, I, J and K. Each sends the request an
 * OUTSIDER would send (a member or administrator of the other organisation,
 * an account with no membership, or nobody at all), then the one an insider
 * would:
 *   F. An administrator of one organisation cannot approve or reject
 *      another organisation's event registration.
 *   G. Only an organisation's own people record or delete a head count.
 *   H. The six addresses that show venue bookings are closed to a member of
 *      another organisation, each with the answer an unknown venue gets.
 *      So are the two places the first round missed: the page for one
 *      venue, and the strip of booked days on the calendar page, which a
 *      signed-out visitor could see. Each is tried as a member of the other
 *      organisation, as an account with no membership, and as nobody.
 *   I. Live chat and the viewer ping (#548): an internal event's chat is
 *      answered only for somebody who may see the event, AND only when the
 *      browser says one of the portal's own pages made the request
 *      (nineteen ways a request can arrive, each with a member's cookie); a
 *      public event's still works with no sign-in, from anywhere; a name
 *      longer than its column is cut, not refused; the old ping address was
 *      never reachable.
 *   J. A child's check-in and a decision card are saved whatever event
 *      number comes with them; only the organisation's own number is kept,
 *      and not that of a deleted event or one copied in from outside.
 *   K. An account that is switched off or erased while signed in: its open
 *      session is ENDED on its next request (Auth::ensureSession()), whether
 *      that request is a GET or a POST and whatever headers it carries, and
 *      switching the account back on does not revive it. With the controls:
 *      sign-in, sign-out, two-step sign-in, the invitation page, API keys,
 *      maintenance mode, the upgrade page, and a count of the look-ups made.
 *   L. A treasurer is told who pays their own approved claim; and the
 *      Expenses addresses stay inside the organisation's own address.
 *
 * Roll-out package H1 (12 October 2026, #587) added part M:
 *   M. Withdrawing a claim, through the real page, reading back what is
 *      STORED. The claimant withdraws a pending claim and its status reads
 *      Withdrawn; a second press, and an approved, a paid and a rejected
 *      claim, are each refused with nothing changed; somebody else's claim
 *      and another organisation's claim get exactly the answer a made-up
 *      number gets; a claim one approver had already approved keeps that
 *      approval and can no longer be decided or paid; and the approver's
 *      list, the treasurer's list, both dashboard numbers, Admin → Reports,
 *      the API list and its status filter, and the export are compared
 *      before and after. Until then the only check of this page looked at
 *      the address the person was sent to, which is how a button that had
 *      never once worked went unnoticed.
 *      Its fix round the same day added: a press with no form token, a
 *      made-up one and another signed-in person's is refused and the claim
 *      stays Pending; every message the withdraw page leaves is read from
 *      the page the person is SENT TO, where it must appear once and not
 *      again on a reload, with its characters written as text; the line
 *      about a withdrawn claim's stored document names the stage that
 *      document was made with; and when the e-mail to approvers fails after
 *      the withdrawal is stored, the person is still told "withdrawn".
 *
 * Roll-out package F2 (12 October 2026, #511) put ONE gate in front of every
 * page: a signed-in person who is not a member of the organisation an
 * address opens is refused there (HTTP 403) before any page runs. So the
 * outsiders in parts F to I now meet two locks, the gate at the door and
 * the rule inside each page, and a request can only ever show the first.
 * So those four parts are proved in two steps:
 *   - Parts F to I run with the gate stood down in the SCRATCH COPY only
 *     (membersGate()), so that what answers is the rule inside each page.
 *     Those are the checks package B2a wrote, unchanged. (An outsider's
 *     "403" there would otherwise come from the gate, and the check would
 *     go on passing with the page's own rule taken out.)
 *   - Then part "F to I, at the door" puts the gate back, as the portal
 *     really runs, and sends the same outsiders to the same addresses: each
 *     is refused (403) with none of A's data, and nothing is written.
 * Why both: with only the door tested, a page's own rule could be removed
 * and no check here would fail; the next change to the gate would then
 * reopen the page with nothing to say so.
 * WHAT THAT COSTS: in parts F to I the members' own requests (the controls)
 * also run with the gate stood down. Every other part runs on the code
 * exactly as shipped, and `tools/site-modes-selftest.php` is the gate's own
 * test, members included.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Every row it makes is numbered 916000 to 916999 or belongs
 *   to one of its two made-up organisations (the one account the invitation
 *   page creates gets a number of its own, and is found by its made-up
 *   address); they are removed at the START (in case an earlier run was
 *   interrupted) and at the END.
 * - It sets two portal-wide settings for the run (`multisite.enabled`,
 *   `multisite.detectionMode`), so the two organisations are told apart by
 *   the first part of the address, and puts them back. So do NOT run it at
 *   the same moment as `tools/feed-admin-selftest.php` on the same database:
 *   that test changes the same two settings.
 * - Part K changes two more things for a short while, and puts both back
 *   (the clean-up does it too, if the run stops early): it switches
 *   maintenance mode on (`portal.maintenance.active`), and it switches on
 *   the database SERVER's query log, written to a table, so it can count
 *   statements. So the database account must be allowed to do that (the
 *   root account of a throwaway server is), and nothing else should be
 *   using that server while this runs: its statements would be counted too.
 * - For parts F to I it rewrites ONE line of `web/_core/SiteGate.php` in
 *   its scratch copy, and puts the file back before the "at the door" part. The repository's file is never written. It
 *   refuses to go on if that line is not found exactly once.
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
 * - Organisations told apart any other way than the first part of the
 *   address.
 * - MariaDB: not run. MySQL 8.0.36 and 8.4.11: run on both.
 * - An API key whose organisation has NO row for an app's switch, anywhere:
 *   that needs a portal-wide row deleted, so it is checked instead inside a
 *   rolled-back transaction by `tools/app-switch-selftest.php` (part 9).
 * - That mail is sent, or what the dashboard shows beyond the two expense
 *   numbers part M reads.
 * - Part M: a database server started WITHOUT strict checking, where the
 *   fault of #587 was a stored EMPTY status and a page that said
 *   "withdrawn". This file passes or fails there like anywhere else (its
 *   failure line prints what was stored), but it is not started that way by
 *   itself. `tools/expense-withdraw-selftest.php` plants that case.
 * - Part M: that the e-mail step can really fail the way the test makes it
 *   fail. The test makes its scratch copy's `ExpenseMailer::notify()` throw
 *   on request; what a mail service does when it is down is not tried. Nor
 *   is a failure of the activity line, which has the same guard.
 * - Part M: a message left by a page other than the withdraw page. The two
 *   "written as text" checks put their own words into the person's session
 *   file, which is where every page's message waits.
 * - Two people deciding at the same moment.
 * - Part I: that a BROWSER sends its sign-in cookie with the chat box's
 *   requests. The test sends the cookie itself; the script that decides it
 *   in a browser is web/public_html/assets/js/livechat-widget.js.
 * - Part I: that a browser really writes `Sec-Fetch-Site`, `Origin` and
 *   `Referer` as the rows say. The test writes those headers itself. In a
 *   browser a page's script cannot write `Sec-Fetch-Site`, and cannot make
 *   `Origin` or `Referer` name an address other than its own page's; it CAN
 *   have `Origin` say `null` and leave `Referer` out, and both are rows
 *   here. A program that is not a browser can write all three as it likes,
 *   which is why the rule also needs the person's own cookie.
 * - Part I: a portal reached over https, or through a proxy. The test's
 *   server is plain http on 127.0.0.1.
 * - Part K: sign-in through Microsoft or Google (it needs their servers);
 *   the installation wizard (it never loads the class being tested); and a
 *   database that is part way through upgrading.
 * - Part H: a venue manager who is not an administrator; and the calendar's
 *   "weekdays" and "weekend" views, which draw their days with the same
 *   code as the week and day views that ARE tried.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/access-pages-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9068). The database must already
 *         hold `web/_sql/full_schema.sql`. Needs PHP's curl extension and
 *         `proc_open()`.
 * Exit:   0 only when every check ran and passed; 1 otherwise, including
 *         every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/478
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/545
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/570
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/548
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/587
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 285;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
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
$port    = ($portRaw === false || $portRaw === '') ? 9068 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

// The app list, read from the real files: used only for each app's display
// name and setting key. WHICH app a folder belongs to is written out by hand
// further down, never taken from the class being tested.
define('PORTAL_CORE', $webSrc . DIRECTORY_SEPARATOR . '_core');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'App.php';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'AppRegistry.php';
$apps = Portal\Core\AppRegistry::all();

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
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
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
const ORG_A  = 916001;
const ORG_B  = 916002;
const ADDR_A = '/selftest-access-a';
const ADDR_B = '/selftest-access-b';
const LEAD = 916011; // member of A; the ONLY lead of department DEPT; also a treasurer of A
const APPR = 916012; // member of A; a plain approver of DEPT
const ADM  = 916013; // a site administrator of A
const TRS  = 916014; // member of A; a treasurer of A
const MEM  = 916015; // member of A; in DEPT with no flag
const DEPT = 916101;
const DEPT_B = 916102; // a department of B, so that part M can give a member of A a claim in B
// Roll-out package B2a (12 October 2026): the "other organisation" people, and
// the rows parts F to L need.
const BMEM = 916021; // member of B ONLY, nothing else
const BADM = 916022; // a site administrator of B ONLY
const GADM = 916023; // a global administrator (isRootAdmin), no membership row anywhere
const NOMEM = 916024; // an active account that is a member of NO organisation
// Part K (the session look-up): each is a member of A unless it says otherwise.
const PX  = 916031; // switched off while signed in, then switched back on
const QX  = 916032; // erased while signed in, through the real erasure pages
const GX  = 916033; // a global administrator, switched off while signed in during maintenance
const TF  = 916034; // signs in with two steps (a backup code)
const TF2 = 916035; // the same, but switched off while waiting at the second step
const PY  = 916036; // switched off; its first requests afterwards are a POST, and a GET with an "Authorization" header
/** The statement Auth::ensureSession() makes for a signed-in request, as the database's query log shows it. */
const LOOKUP_LIKE = 'SELECT isActive FROM tblUsers WHERE userID = %';
const EV_INT   = 916401; // A: internal, published
const EV_PUB   = 916402; // A: public, published
const EV_DRAFT = 916403; // A: a draft, marked public on purpose so only the draft rule refuses it
const EV_B     = 916404; // B: public, published, so on A's address only "wrong organisation" refuses it
const EV_GONE     = 916405; // A: public and published, but DELETED
const EV_IMPORTED = 916406; // A: public and published, but copied in from an outside calendar
const EV_NONE  = 916499; // matches no event
const VENUE    = 916701; // A's hall
const BOOKING  = 916711; // one booking of it, today
const BOOKING_LATER = 916712; // a second booking, on the day of the events, so one calendar view holds both
/** In every venue row's text, so "is any of A's venue data in this answer?" is one search. */
const VENUE_MARK = 'ACCESS-VENUE';
/** The made-up folder and handler part C plants in the scratch copy. */
const UNLISTED_FOLDER = 'zz-selftest-unlisted';
const PLANTED_ACTION  = 'zz-selftest-ping';
const PLANTED_MARK    = 'ZZ-SELFTEST-HANDLER-RAN';
/** Part M: the file that, while it exists in the scratch copy's `_core`, makes that copy's e-mail step throw; and what it throws. */
const MAIL_FAILS_FILE = '.access-pages-selftest-mail-fails';
const MAIL_FAILS_MARK = 'ACCESS-W-THE-EMAIL-STEP-WAS-MADE-TO-FAIL';

function removeWorld(): void
{
    // Parts F to L (package B2a). Rows that point at an event, a venue or a
    // child go first; the events themselves take their registrations with them.
    // Part K: the erasure request the real pages file, the invitation, and
    // the account the invitation page creates (it gets a number of its own,
    // so it is found by its made-up address).
    q('DELETE a FROM tblErasureAudit a JOIN tblErasureRequest r ON r.requestID = a.requestID WHERE r.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblErasureRequest WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblEmailLog WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblInvitation WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblTotpBackupCodes WHERE userID BETWEEN 916000 AND 916999');
    q("DELETE s FROM tblUserSites s JOIN tblUsers u ON u.userID = s.userID WHERE u.emailAddress = 'invited.access@selftest.invalid'");
    q("DELETE a FROM tblActivityLogs a JOIN tblUsers u ON u.userID = a.userID WHERE u.emailAddress = 'invited.access@selftest.invalid'");
    q("DELETE l FROM tblLocalAccounts l JOIN tblUsers u ON u.userID = l.userID WHERE u.emailAddress = 'invited.access@selftest.invalid'");
    q("DELETE FROM tblUsers WHERE emailAddress = 'invited.access@selftest.invalid'");
    q('DELETE FROM tblLiveRateLimits WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblLiveChatMessages WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblLivePrompts WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblLivestreamSessions WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE c FROM tblKidCheckins c JOIN tblKidProfiles k ON k.childID = c.childID WHERE k.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblKidProfiles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblSalvationCards WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblAttendanceSessions WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblAttendanceServiceTypes WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblVenueBookings WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblVenueUsageTypes WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblVenueStatuses WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblVenues WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblEvents WHERE siteID IN (?, ?) OR eventID BETWEEN 916000 AND 916999', 'ii', [ORG_A, ORG_B]);
    q('DELETE p FROM tblExpenseClaimPayments p JOIN tblExpenseClaims c ON c.claimID = p.claimID WHERE c.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE a FROM tblExpenseClaimApprovals a JOIN tblExpenseClaims c ON c.claimID = a.claimID WHERE c.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblExpenseClaims WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblApiKeys WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUserRoles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblRoles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUserDepts WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblDepts WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 916000 AND 916999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblErrors WHERE userID BETWEEN 916000 AND 916999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUserSites WHERE siteID IN (?, ?) OR userID BETWEEN 916000 AND 916999', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUsers WHERE userID BETWEEN 916000 AND 916999');
    q('DELETE FROM tblSettings WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // The two per-endpoint switches part C adds for its planted handler.
    q("DELETE FROM tblSettings WHERE siteID IS NULL AND settingKey IN ('api." . UNLISTED_FOLDER . '.' . PLANTED_ACTION . ".enabled', 'api.tours." . PLANTED_ACTION . ".enabled')");
    q('DELETE FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
}

/** One organisation's own value for a setting (replacing any it had). */
function siteSetting(int $site, string $key, string $value): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss', [$site, $key, $value, $value]);
}

function claim(int $id, int $by, string $title): void
{
    q(
        "INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status) VALUES (?, ?, ?, ?, ?, CURDATE(), 12.50, 'Pending')",
        'iiiis', [$id, ORG_A, $by, DEPT, $title]
    );
}

/** A claim's status and how many approvals and payments are recorded against it. */
function claimState(int $id): string
{
    $row = one(
        'SELECT c.status, (SELECT COUNT(*) FROM tblExpenseClaimApprovals a WHERE a.claimID = c.claimID) AS approvals, '
        . '(SELECT COUNT(*) FROM tblExpenseClaimPayments p WHERE p.claimID = c.claimID) AS payments FROM tblExpenseClaims c WHERE c.claimID = ?',
        'i', [$id]
    );

    return $row === null ? 'missing' : $row['status'] . ', ' . $row['approvals'] . ' approval(s), ' . $row['payments'] . ' payment(s)';
}

// =============================================================================
// 🧰 The server, and one request
// =============================================================================
$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false, 'queryLog' => null];

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
        // The database's query log, exactly as part K found it.
        if (is_array($state['queryLog']) === true) {
            // Both values were checked when they were read (0 or 1; capital letters and commas only).
            q('SET GLOBAL general_log = ' . (int) $state['queryLog']['general_log']);
            q("SET GLOBAL log_output = '" . $state['queryLog']['log_output'] . "'");
            q('TRUNCATE TABLE mysql.general_log');
        }
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
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.access-pages-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else
 * and is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 * @param list<string>             $extra Whole header lines to add, such as
 *                                        the ones a browser writes to say
 *                                        where a request came from.
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null, string $bearer = '', ?string $json = null, array $extra = []): array
{
    global $port, $state;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:'];
    if ($bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    // `$extra` is a list of whole header lines. One that names the body's
    // type replaces the usual "application/json".
    $typed = array_filter($extra, static fn (string $line): bool => stripos($line, 'Content-Type:') === 0) !== [];
    if ($json !== null && $typed === false) {
        $headers[] = 'Content-Type: application/json';
    }
    $headers = array_merge($headers, $extra);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($jar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $json ?? http_build_query($post ?? []));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle), 'head' => ''];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Access-Pages-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

/**
 * 🚪 Stand the members-only gate down, or put it back, in the SCRATCH COPY
 * only (roll-out package F2, #511). See the note in this file's header for
 * why the outsiders' requests are made both ways.
 *
 * Standing it down swaps the one line that refuses a non-member for a plain
 * `return`, so the request reaches the page as it did before the gate
 * existed. Everything else the gate does stays in place.
 */
function membersGate(bool $inPlace): void
{
    global $child, $sep, $state;
    $file = $child . $sep . 'web' . $sep . '_core' . $sep . 'SiteGate.php';
    if (isset($state['gateFile']) === false) {
        $state['gateFile'] = (string) file_get_contents($file);
    }
    $refusal = 'self::notAMember($db, $userId, $own, $path);';
    if (substr_count($state['gateFile'], $refusal) !== 1) {
        refuse('web/_core/SiteGate.php does not hold the line "' . $refusal . '" exactly once, so the members-only gate cannot be stood down for the page-level checks');
    }
    file_put_contents($file, $inPlace === true ? $state['gateFile'] : str_replace($refusal, 'return;', $state['gateFile']));
    clearstatcache();
}

/** Is this what the gate's refusal looks like: HTTP 403, and nothing of organisation A's in it? */
function refusedAtTheDoor(array $answer): bool
{
    return $answer['status'] === 403 && str_contains($answer['body'], 'ACCESS') === false && str_contains($answer['body'], 'Selftest access A') === false;
}

/** The form token on a page. */
function token(string $html): string
{
    if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** POST a form with a fresh token read from `$formPage`, as a browser would. */
function post(string $jar, string $formPage, string $to, array $fields): array
{
    $fields['csrf_token'] = token(req($jar, 'GET', $formPage)['body']);

    return req($jar, 'POST', $to, $fields);
}

/**
 * A JSON POST, the way the chat box script sends one. An empty `$jar` sends
 * no cookie. `$extra` is a list of whole header lines (see req()).
 */
function postJson(string $jar, string $path, array $payload, array $extra = []): array
{
    return req($jar, 'POST', $path, null, '', (string) json_encode($payload), $extra);
}

/** The `data` part of a JSON answer, or [] when there is none. */
function data(array $answer): array
{
    $json = json_decode($answer['body'], true);

    return (is_array($json) === true && is_array($json['data'] ?? null) === true) ? $json['data'] : [];
}

/**
 * An answer with the one part that changes on every request (the time in
 * `meta`) taken out. Two answers that are the same after this are the same
 * answer, which is how "a refused event looks exactly like a made-up
 * number" is checked.
 */
function stable(array $answer): string
{
    $json = json_decode($answer['body'], true);
    if (is_array($json) === true) {
        unset($json['meta']);

        return $answer['status'] . ' ' . json_encode($json);
    }

    return $answer['status'] . ' ' . trim($answer['body']);
}

/** "status: message" of a JSON answer (or the start of the page's text). */
function said(array $answer): string
{
    $json = json_decode($answer['body'], true);

    return $answer['status'] . ': ' . (is_array($json) === true ? (string) ($json['message'] ?? '') : mb_substr(trim(strip_tags($answer['body'])), 0, 80));
}

/** The page's top heading, as text. */
function heading(array $answer): string
{
    return preg_match('#<h1[^>]*>(.*?)</h1>#s', $answer['body'], $h) === 1 ? trim(strip_tags($h[1])) : '(no heading)';
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

q("INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES (?, 'selftest-access-a', 'Selftest access A', 1), (?, 'selftest-access-b', 'Selftest access B', 1)", 'ii', [ORG_A, ORG_B]);
foreach ([LEAD => 'Lead', APPR => 'Approver', ADM => 'Admin', TRS => 'Treasurer', MEM => 'Member'] as $id => $name) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, 'Selftest access ' . $name, 'u' . $id . '.access@selftest.invalid']);
}
foreach ([[LEAD, 0], [APPR, 0], [ADM, 1], [TRS, 0], [MEM, 0]] as [$user, $admin]) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)', 'iii', [$user, ORG_A, $admin]);
}
q("INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES (?, ?, 'Selftest access dept', 'SAD', 1)", 'ii', [DEPT, ORG_A]);
foreach ([[LEAD, 1, 0], [APPR, 0, 1], [MEM, 0, 0], [ADM, 0, 0]] as [$user, $lead, $approver]) {
    q('INSERT INTO tblUserDepts (userID, deptID, siteID, isDeptLead, isApprover, isMandatoryApprover) VALUES (?, ?, ?, ?, ?, 0)', 'iiiii', [$user, DEPT, ORG_A, $lead, $approver]);
}
q("INSERT INTO tblRoles (roleID, siteID, roleKey, roleName, isStandard) VALUES (916201, ?, 'treasurer', 'Treasurer', 1)", 'i', [ORG_A]);
q('INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, 916201, ?), (?, 916201, ?)', 'iiii', [LEAD, ORG_A, TRS, ORG_A]);
// 👥 Package B2a: a member and an administrator of B ONLY, a global administrator with no membership anywhere,
//    and an ordinary account with no membership anywhere (it gets no row in the next statement).
foreach ([BMEM => 'B member', BADM => 'B admin', GADM => 'Global admin', NOMEM => 'No membership'] as $id => $name) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, ?)', 'issi', [$id, 'Selftest access ' . $name, 'u' . $id . '.access@selftest.invalid', $id === GADM ? 1 : 0]);
}
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0), (?, ?, 1, 1, 0)', 'iiii', [BMEM, ORG_B, BADM, ORG_B]);
// K: six more accounts. GX is a global administrator with no membership; the rest are members of A.
// (No name here may contain the words "switched off": part A searches whole answers for them, and api/users/list prints names.)
foreach ([PX => 'Paused', QX => 'Erased', GX => 'Global paused', TF => 'Two step', TF2 => 'Two step paused', PY => 'Paused poster'] as $id => $name) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, ?)', 'issi', [$id, 'Selftest access ' . $name, 'u' . $id . '.access@selftest.invalid', $id === GX ? 1 : 0]);
    if ($id !== GX) {
        q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [$id, ORG_A]);
    }
}
// Two-step sign-in, finished here with a one-time backup code (the code is
// stored as a password hash, so the test can make one; the authenticator
// secret is encrypted with a key the test does not hold).
$backupCode = 'ACCESS-' . bin2hex(random_bytes(6));
foreach ([TF, TF2] as $id) {
    q("UPDATE tblUsers SET totpEnabled = 1, totpSecret = 'not-used-by-this-test' WHERE userID = ?", 'i', [$id]);
    q('INSERT INTO tblTotpBackupCodes (userID, codeHash, isUsed) VALUES (?, ?, 0)', 'is', [$id, password_hash($backupCode, PASSWORD_DEFAULT)]);
}
// An invitation to join A, not yet accepted.
$inviteToken = bin2hex(random_bytes(32));
q("INSERT INTO tblInvitation (siteID, email, tokenHash, intendedRole, expiresAt) VALUES (?, 'invited.access@selftest.invalid', ?, 'user', DATE_ADD(NOW(), INTERVAL 1 DAY))", 'is', [ORG_A, hash('sha256', $inviteToken)]);
// 📅 Events. Dates are worked out from today, never written as fixed dates.
$today    = date('Y-m-d');
$eventDay = date('Y-m-d', strtotime('+9 days'));
foreach ([[EV_INT, ORG_A, 'internal', 'published', 0], [EV_PUB, ORG_A, 'public', 'published', 1], [EV_DRAFT, ORG_A, 'draft', 'draft', 1], [EV_B, ORG_B, 'other-org', 'published', 1]] as [$id, $site, $kind, $status, $public]) {
    q(
        'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, eventTimezone, status, isPublic, isDeleted) '
        . "VALUES (?, ?, ?, ?, 'ACCESS description', ?, ?, 'UTC', 'UTC', ?, ?, 0)",
        'iisssssi', [$id, $site, 'access-' . $kind, 'ACCESS ' . $kind . ' event', $eventDay . ' 10:00:00', $eventDay . ' 11:00:00', $status, $public]
    );
}
// J: two more events of A whose number a check-in or a decision card must NOT keep: one deleted, and one
//    copied in from an outside calendar (the calendar's own row is not needed: nothing links the two tables).
q(
    'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, eventTimezone, status, isPublic, isDeleted) '
    . "VALUES (?, ?, 'access-deleted', 'ACCESS deleted event', 'ACCESS description', ?, ?, 'UTC', 'UTC', 'published', 1, 1)",
    'iiss', [EV_GONE, ORG_A, $eventDay . ' 10:00:00', $eventDay . ' 11:00:00']
);
q(
    'INSERT INTO tblEvents (eventID, siteID, externalFeedID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, eventTimezone, status, isPublic, isDeleted) '
    . "VALUES (?, ?, 916901, 'access-imported', 'ACCESS imported event', 'ACCESS description', ?, ?, 'UTC', 'UTC', 'published', 1, 0)",
    'iiss', [EV_IMPORTED, ORG_A, $eventDay . ' 10:00:00', $eventDay . ' 11:00:00']
);
// F: one pending registration on A's internal event, one on B's event.
q("INSERT INTO tblEventRegistrations (registrationID, eventID, fullName, status) VALUES (916501, ?, 'ACCESS registration A', 'pending'), (916502, ?, 'ACCESS registration B', 'pending')", 'ii', [EV_INT, EV_B]);
// G: a service type of A and two recorded sessions of it.
q("INSERT INTO tblAttendanceServiceTypes (serviceTypeID, siteID, typeName, typeSlug, isActive) VALUES (916601, ?, 'ACCESS service', 'access-service', 1)", 'i', [ORG_A]);
q("INSERT INTO tblAttendanceSessions (sessionID, siteID, serviceTypeID, sessionDate, notes) VALUES (916611, ?, 916601, ?, 'ACCESS-ATT seeded one'), (916612, ?, 916601, ?, 'ACCESS-ATT seeded two')", 'isis', [ORG_A, $today, ORG_A, $today]);
// H: A's hall, with one booking today. Every text carries VENUE_MARK.
q('INSERT INTO tblVenues (venueID, siteID, venueName, isActive) VALUES (?, ?, ?, 1)', 'iis', [VENUE, ORG_A, VENUE_MARK . ' hall']);
q('INSERT INTO tblVenueUsageTypes (usageTypeID, siteID, venueID, typeName) VALUES (916721, ?, ?, ?)', 'iis', [ORG_A, VENUE, VENUE_MARK . ' use']);
q('INSERT INTO tblVenueStatuses (statusID, siteID, statusName) VALUES (916731, ?, ?)', 'is', [ORG_A, VENUE_MARK . ' status']);
q("INSERT INTO tblVenueBookings (bookingID, siteID, venueID, bookingDate, usageTypeID, statusID, startTime, endTime, notes) VALUES (?, ?, ?, ?, 916721, 916731, '09:00:00', '12:00:00', ?)", 'iiiss', [BOOKING, ORG_A, VENUE, $today, VENUE_MARK . ' note']);
q("INSERT INTO tblVenueBookings (bookingID, siteID, venueID, bookingDate, usageTypeID, statusID, startTime, endTime, notes) VALUES (?, ?, ?, ?, 916721, 916731, '09:00:00', '12:00:00', ?)", 'iiiss', [BOOKING_LATER, ORG_A, VENUE, $eventDay, VENUE_MARK . ' note']);
// I: an approved chat message and a live prompt on A's internal event and on its public one.
foreach ([EV_INT => 'internal', EV_PUB => 'public'] as $id => $kind) {
    q("INSERT INTO tblLiveChatMessages (siteID, eventID, sessionToken, displayName, body, status) VALUES (?, ?, ?, 'Seeded', ?, 'approved')", 'iiss', [ORG_A, $id, str_repeat('ab', 16), 'ACCESS-CHAT ' . $kind . ' message']);
    q("INSERT INTO tblLivePrompts (siteID, eventID, promptType, title, expiresAt) VALUES (?, ?, 'announcement', ?, DATE_ADD(NOW(), INTERVAL 1 DAY))", 'iis', [ORG_A, $id, 'ACCESS-CHAT ' . $kind . ' prompt']);
}
// J: five children of A, none checked in.
foreach ([916801, 916802, 916803, 916804, 916805] as $id) {
    q('INSERT INTO tblKidProfiles (childID, siteID, fullName, isActive) VALUES (?, ?, ?, 1)', 'iis', [$id, ORG_A, 'ACCESS child ' . $id]);
}
// L: three APPROVED claims, to be opened by their own claimants.
claim(916301, LEAD, 'ACCESS lead own claim');
claim(916302, MEM, 'ACCESS member claim');
claim(916303, ADM, 'ACCESS admin own claim');
claim(916304, MEM, 'ACCESS claim to reject');
claim(916305, LEAD, 'ACCESS lead second claim');
claim(916307, TRS, 'ACCESS treasurer approved claim');
claim(916308, MEM, 'ACCESS member approved claim');
claim(916309, ADM, 'ACCESS admin approved claim');
q("UPDATE tblExpenseClaims SET status = 'Approved' WHERE claimID IN (916307, 916308, 916309)");
// One API key belonging to each organisation (the length ApiKey::findByPlaintext() accepts).
$keyA = 'wbms_' . bin2hex(random_bytes(16));
$keyB = 'wbms_' . bin2hex(random_bytes(16));
foreach ([[ORG_A, $keyA], [ORG_B, $keyB]] as [$site, $plain]) {
    q(
        "INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, isActive, createdByID) VALUES (?, 'Selftest access key', ?, ?, 'assets:read', 1, NULL)",
        'iss', [$site, hash('sha256', $plain), substr($plain, 0, 12)]
    );
}
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

// 📋 WHICH APP EACH `api` FOLDER BELONGS TO — written out by hand, NOT read
//    from AppRegistry::API_FOLDER_APPS: taking the expected answers from the
//    list being checked would agree with it even when it was wrong. The
//    second value is the endpoint asked (a read wherever the folder has one,
//    so nothing is written when the app is on). null = belongs to the portal
//    itself, never refused.
$folders = [
    'announcements'   => ['announcements', 'list'],
    'assets'          => ['assets', 'list'],
    'attendance'      => ['attendance', 'list'],
    'calendar'        => ['calendar', 'hub-resources'],
    'documents'       => ['documents', 'list'],
    'events'          => ['calendar', 'list'],
    'expenses'        => ['expenses', 'list'],
    'leadership'      => ['leadership', 'list'],
    'livechat'        => ['cop-live-chat', 'list'],
    'livestream'      => ['livestream', 'ping'],
    'noticeboard'     => ['noticeboard', 'list'],
    'prayer-requests' => ['prayer-requests', 'list'],
    'push'            => [null, 'subscribe'],
    'service-plans'   => ['service-plans', 'hymn-search'],
    'tasks'           => ['tasks', 'list'],
    'tours'           => [null, 'active'],
    'users'           => [null, 'list'],
    'venues'          => ['venues', 'availability'],
    'worship'         => ['worship', 'state'],
];
$switchable = [];
foreach ($folders as [$slug]) {
    if ($slug !== null) {
        $switchable[$slug] = (string) ($apps[$slug]['settingKey'] ?? '');
    }
}
/** Every app in the table switched on for an organisation. */
$allOn = static function (int $site) use ($switchable): void {
    foreach ($switchable as $settingKey) {
        siteSetting($site, $settingKey, 'true');
    }
};
$allOn(ORG_A);
$allOn(ORG_B);
siteSetting(ORG_A, 'expenses.enabled', 'true');

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (['lead' => LEAD, 'appr' => APPR, 'adm' => ADM, 'trs' => TRS, 'mem' => MEM, 'bmem' => BMEM, 'badm' => BADM, 'gadm' => GADM, 'nomem' => NOMEM, 'py' => PY, 'px' => PX, 'qx' => QX, 'gx' => GX, 'tf' => TF, 'tf2' => TF2] as $who => $id) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-access-' . $who, $hash]);
}

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'access-pages-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.access-pages-selftest-owner', $state['token']); // the marker FIRST
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
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/access-pages-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// 🧪 Part C's planted handler, in the SCRATCH COPY only: once in a folder that
//    has no line in the list, once in a listed folder (tours, which belongs
//    to no app), each with its per-endpoint switch on.
$planted = "<?php\n// Planted by tools/access-pages-selftest.php in its scratch copy for one run.\ndeclare(strict_types=1);\n\\Portal\\Core\\ApiResponse::success(['mark' => '" . PLANTED_MARK . "']);\n";
mkdir($child . $sep . 'web' . $sep . '_apps' . $sep . UNLISTED_FOLDER . $sep . 'api', 0700, true);
file_put_contents($child . $sep . 'web' . $sep . '_apps' . $sep . UNLISTED_FOLDER . $sep . 'api' . $sep . PLANTED_ACTION . '.php', $planted);
file_put_contents($child . $sep . 'web' . $sep . '_apps' . $sep . 'tours' . $sep . 'api' . $sep . PLANTED_ACTION . '.php', $planted);
foreach ([UNLISTED_FOLDER, 'tours'] as $folder) {
    q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, 'true', 'true', 0)", 's', ['api.' . $folder . '.' . PLANTED_ACTION . '.enabled']);
}

// 🧪 Part M's planted failure, in the SCRATCH COPY only: the copy's
//    `ExpenseMailer::notify()` throws for as long as a marker file exists
//    beside it. Nothing in the portal can be asked to make the e-mail step
//    fail, and "what does the person read when it does?" is exactly the
//    question (it used to be "Please try again", about a claim that WAS
//    withdrawn). If the method no longer opens the way this expects, the run
//    stops here rather than carry on with a check that could not fail.
$mailerFile = $child . $sep . 'web' . $sep . '_core' . $sep . 'ExpenseMailer.php';
$mailerOpen = "    public static function notify(int \$claimID, string \$event, array \$extra = []): void\n    {\n";
$mailerNow  = str_replace(
    $mailerOpen,
    $mailerOpen . "        if (is_file(__DIR__ . DIRECTORY_SEPARATOR . '" . MAIL_FAILS_FILE . "') === true) { throw new \\RuntimeException('" . MAIL_FAILS_MARK . "'); } // planted by tools/access-pages-selftest.php\n",
    (string) file_get_contents($mailerFile),
    $mailerPlanted
);
if ($mailerPlanted !== 1) {
    refuse('the scratch copy\'s e-mail step could not be made to fail on request: ExpenseMailer::notify() no longer opens the way part M expects');
}
file_put_contents($mailerFile, $mailerNow);

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/access-pages-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Access-Pages-Selftest: __TOKEN__');
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
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
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

echo "Who may see and do what, on the real pages — self-test (#478, #570, #545, #587, roll-out package B2a)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

/** Sign one account in through the real sign-in page of one organisation's address; returns its cookie jar. */
function signIn(string $who, string $address): string
{
    global $child, $sep, $password;
    $file = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $tok  = token(req($file, 'GET', $address . '/login')['body']);
    $in   = req($file, 'POST', $address . '/login', ['csrf_token' => $tok, 'username' => 'selftest-access-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-access-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }

    return $file;
}
$jar = [];
foreach (['lead', 'appr', 'adm', 'trs', 'mem', 'gadm', 'nomem'] as $who) {
    $jar[$who] = signIn($who, ADDR_A);
}
// B's two people sign in on B's own address, and are then sent to A's.
foreach (['bmem', 'badm'] as $who) {
    $jar[$who] = signIn($who, ADDR_B);
}
$nobody = $child . $sep . 'jar-nobody.txt';

// =============================================================================
head('A. #478 — one row per api folder: only that folder\'s app off, then every app on');
// =============================================================================
ok('the table below has a row for each of the 19 api folders on disk, and no other', (static function () use ($folders, $webSrc): bool {
    $onDisk = array_map(static fn (string $dir): string => basename(dirname($dir)), (array) glob($webSrc . '/_apps/*/api', GLOB_ONLYDIR));
    sort($onDisk);
    $rows = array_keys($folders);
    sort($rows);

    return $onDisk === $rows && count($rows) === 19;
})(), 'A new api folder needs a row here as well as a line in AppRegistry::API_FOLDER_APPS.');
foreach ($folders as $folder => [$slug, $action]) {
    $path = ADDR_A . '/api/' . $folder . '/' . $action;
    if ($slug === null) {
        // Belongs to the portal itself: never refused as switched off, even with EVERY app off.
        foreach ($switchable as $settingKey) {
            siteSetting(ORG_A, $settingKey, 'false');
        }
        $answer = req($jar['adm'], 'GET', $path);
        $allOn(ORG_A);
        ok(
            sprintf('api/%s belongs to no app: with EVERY app switched off it is still not refused as switched off', $folder),
            $answer['status'] > 0 && str_contains($answer['body'], 'switched off') === false && str_contains($answer['body'], 'This API endpoint is disabled') === false,
            said($answer)
        );
        continue;
    }
    $name = (string) ($apps[$slug]['name'] ?? '(app not registered)');
    siteSetting(ORG_A, $switchable[$slug], 'false');
    $off = req($jar['adm'], 'GET', $path);
    siteSetting(ORG_A, $switchable[$slug], 'true');
    $on = req($jar['adm'], 'GET', $path);
    ok(
        sprintf('api/%s: with ONLY %s off, /api/%s/%s answers 403 "The %s app is switched off for this organisation."', $folder, $name, $folder, $action, $name),
        said($off) === '403: The ' . $name . ' app is switched off for this organisation.',
        said($off)
    );
    ok(
        sprintf('api/%s: with every app on, the same address is not refused as switched off', $folder),
        $on['status'] > 0 && str_contains($on['body'], 'switched off') === false,
        said($on)
    );
}
siteSetting(ORG_A, $switchable['assets'], 'false');
$answer = req($jar['adm'], 'GET', ADDR_A . '/api/v1/assets');
ok('the second way in, /api/v1/assets, is refused the same way with Asset Tracker off', said($answer) === '403: The Asset Tracker app is switched off for this organisation.', said($answer));
$answer = req($jar['adm'], 'GET', ADDR_A . '/api/assets/no-such-endpoint');
ok('an endpoint that does not exist is still "not found" (404) first, switched off or not', $answer['status'] === 404, said($answer));

// =============================================================================
head('B. #478 — an API key is judged by its OWN organisation\'s switch (Asset Tracker: off in A, on in B)');
// =============================================================================
$offSentence = '403: The Asset Tracker app is switched off for this organisation.';
$answer = req('', 'GET', ADDR_B . '/api/assets/list', null, $keyA);
ok('A\'s key sent to B\'s address (where the app is ON): refused, 403 switched off', said($answer) === $offSentence, said($answer));
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, $keyA);
ok('A\'s key sent to A\'s own address: refused, 403 switched off', said($answer) === $offSentence, said($answer));
$answer = req('', 'GET', ADDR_B . '/api/v1/assets', null, $keyA);
ok('A\'s key through /api/v1/assets on B\'s address: refused, 403 switched off', said($answer) === $offSentence, said($answer));
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, $keyB);
ok('B\'s key sent to A\'s address (where the app is OFF): answered 200, because B has it on', $answer['status'] === 200, said($answer));
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, 'wbms_' . bin2hex(random_bytes(16)));
ok('a made-up key sent to A\'s address is no way round: refused, 403 switched off', said($answer) === $offSentence, said($answer));
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, 'not-a-portal-key');
ok('…and neither is any other "Authorization" header', said($answer) === $offSentence, said($answer));
siteSetting(ORG_A, $switchable['assets'], 'true');
$answer = req('', 'GET', ADDR_B . '/api/assets/list', null, $keyA);
ok('Asset Tracker switched back on in A: A\'s key is answered 200', $answer['status'] === 200, said($answer));
siteSetting(ORG_B, $switchable['assets'], 'false');
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, $keyB);
ok('…and with it now off in B instead, B\'s key is refused at A\'s address (where it is on)', said($answer) === $offSentence, said($answer));
siteSetting(ORG_B, $switchable['assets'], 'true');

// =============================================================================
head('C. #478 — an api folder with no line in the list is refused, and its handler never runs');
// =============================================================================
$answer = req($jar['adm'], 'GET', ADDR_A . '/api/tours/' . PLANTED_ACTION);
ok('control: the planted handler DOES run from a listed folder (api/tours)', $answer['status'] === 200 && str_contains($answer['body'], PLANTED_MARK) === true, said($answer));
$answer = req($jar['adm'], 'GET', ADDR_A . '/api/' . UNLISTED_FOLDER . '/' . PLANTED_ACTION);
ok('the same handler in a folder with no line: 403 "This API endpoint is disabled", and it did not run', said($answer) === '403: This API endpoint is disabled' && str_contains($answer['body'], PLANTED_MARK) === false, said($answer));
$answer = req('', 'GET', ADDR_A . '/api/' . UNLISTED_FOLDER . '/' . PLANTED_ACTION, null, $keyA);
ok('…and the same for an API key', $answer['status'] === 403 && str_contains($answer['body'], PLANTED_MARK) === false, said($answer));

// =============================================================================
head('D. #478 and #570 — a switched-off app\'s stray pages; the "disabled" page names the right app');
// =============================================================================
siteSetting(ORG_A, 'calendar.enabled', 'false');
siteSetting(ORG_A, 'newsletter.enabled', 'false');
$answer = req($nobody, 'GET', ADDR_A . '/attend');
ok('Calendar off: /attend, signed out, is the "disabled" page (403) and its heading says "Calendar is disabled"', $answer['status'] === 403 && heading($answer) === 'Calendar is disabled', $answer['status'] . ' heading: ' . heading($answer));
$answer = req($nobody, 'GET', ADDR_A . '/calendar.ics');
ok('/calendar.ics is the "disabled" page too, not the feed page\'s own "Invalid token."', $answer['status'] === 403 && heading($answer) === 'Calendar is disabled' && str_contains($answer['body'], 'Invalid token.') === false, $answer['status'] . ' heading: ' . heading($answer));
$answer = req($jar['adm'], 'GET', ADDR_A . '/account/calendar-feed');
ok('/account/calendar-feed (the page that hands out the feed link) is the "disabled" page', $answer['status'] === 403 && heading($answer) === 'Calendar is disabled', $answer['status'] . ' heading: ' . heading($answer));
$answer = req($jar['adm'], 'GET', ADDR_A . '/calendar');
ok('#570: /calendar, signed in with a full menu drawn, says "Calendar is disabled" and not another app\'s name', $answer['status'] === 403 && heading($answer) === 'Calendar is disabled', $answer['status'] . ' heading: ' . heading($answer));
ok('#570: both links on that page stay inside the organisation\'s own address', str_contains($answer['body'], 'href="' . ADDR_A . '/admin/apps"') === true && str_contains($answer['body'], 'href="' . ADDR_A . '/" class="btn btn-primary"') === true);
ok('#570: the description under the heading is Calendar\'s own', str_contains($answer['body'], htmlspecialchars((string) ($apps['calendar']['description'] ?? '(none)'), ENT_QUOTES, 'UTF-8')) === true);
$answer = req($nobody, 'GET', ADDR_A . '/unsubscribe');
ok('Newsletter off: /unsubscribe is NOT the "disabled" page — people can always stop mail', $answer['status'] > 0 && $answer['status'] !== 403 && str_contains($answer['body'], ' is disabled</h1>') === false, 'HTTP ' . $answer['status'] . ' heading: ' . heading($answer));
siteSetting(ORG_A, 'calendar.enabled', 'true');
$answer = req($nobody, 'GET', ADDR_A . '/attend');
ok('Calendar back on: /attend is no longer the "disabled" page', $answer['status'] > 0 && $answer['status'] !== 403 && str_contains($answer['body'], ' is disabled</h1>') === false, 'HTTP ' . $answer['status']);
$answer = req($nobody, 'GET', ADDR_A . '/calendar.ics');
ok('…and /calendar.ics is answered by the feed page itself again ("Invalid token." without a personal token)', $answer['status'] === 403 && trim($answer['body']) === 'Invalid token.', 'HTTP ' . $answer['status'] . ' ' . mb_substr(trim(strip_tags($answer['body'])), 0, 60));

// =============================================================================
// E. #545 — through the real handlers
// =============================================================================
// The two sentences, written out by hand (not read from ExpenseRules).
$whoDecides  = 'It must be decided by the other leads and required approvers of its department or, when there are none, by another approver or an administrator.';
$ownDecision = 'You cannot approve or reject your own expense claim. ' . $whoDecides;
$ownPayment  = 'You cannot record payment of your own expense claim. Another treasurer or an administrator must pay it.';
$ownPageNote = 'This is your own claim, so you cannot decide it yourself. ' . $whoDecides;
$approve     = ADDR_A . '/expenses/approve';
$treasury    = ADDR_A . '/expenses/treasury';
$untouched   = 'Pending, 0 approval(s), 0 payment(s)';

head('E1. #545 — a lead cannot approve or reject their own claim; somebody else can');
$list = req($jar['lead'], 'GET', $approve);
ok('the lead opens the approvals page (200)', $list['status'] === 200, 'HTTP ' . $list['status']);
ok('their own two claims are NOT listed there', str_contains($list['body'], 'ACCESS lead own claim') === false && str_contains($list['body'], 'ACCESS lead second claim') === false);
ok('…a member\'s claim in their department IS (so the list still works)', str_contains($list['body'], 'ACCESS member claim') === true);
ok('…and the page says why, in one line', str_contains($list['body'], 'Your own claims are not listed here: somebody else must decide them.') === true);
$answer = post($jar['lead'], $approve, $approve . '/save', ['claimID' => 916301, 'decision' => 'Approved', 'comments' => '']);
ok('the lead POSTs an approval of their OWN claim to the real handler: redirected, nothing recorded', $answer['status'] === 302 && claimState(916301) === $untouched, 'HTTP ' . $answer['status'] . '; claim: ' . claimState(916301));
ok('…and the next page shows the refusal sentence', str_contains(req($jar['lead'], 'GET', $approve)['body'], $ownDecision) === true);
post($jar['lead'], $approve, $approve . '/save', ['claimID' => 916301, 'decision' => 'Rejected', 'comments' => '']);
ok('the lead cannot REJECT their own claim either: nothing recorded', claimState(916301) === $untouched, claimState(916301));
req($jar['lead'], 'GET', $approve); // show, and so clear, that message
post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916301, 'decision' => 'Approved', 'comments' => 'ok']);
ok('ANOTHER approver of the department approves it: Approved on that one approval (its only lead is the claimant)', claimState(916301) === 'Approved, 1 approval(s), 0 payment(s)', claimState(916301));
ok('…recorded as that approver\'s decision', (one('SELECT userID, decision, approverRole FROM tblExpenseClaimApprovals WHERE claimID = 916301') ?? []) === ['userID' => APPR, 'decision' => 'Approved', 'approverRole' => 'dept_approver']);

head('E2. #545 — the claimant, who is also a treasurer, cannot pay it; another treasurer can');
$list = req($jar['lead'], 'GET', $treasury);
ok('the claimant opens the treasury page (200: they hold the Treasurer role)', $list['status'] === 200, 'HTTP ' . $list['status']);
ok('their own approved claim is NOT listed there, and the page says why', str_contains($list['body'], 'ACCESS lead own claim') === false && str_contains($list['body'], 'Your own claims are not listed here: somebody else must pay them.') === true);
$answer = post($jar['lead'], $treasury, $treasury . '/save', ['claimID' => 916301, 'payRef' => ['SELF-PAY-1'], 'comments' => '']);
ok('the claimant POSTs a payment of their OWN claim to the real handler: redirected, nothing recorded', $answer['status'] === 302 && claimState(916301) === 'Approved, 1 approval(s), 0 payment(s)', 'HTTP ' . $answer['status'] . '; claim: ' . claimState(916301));
ok('…and the next page shows the refusal sentence', str_contains(req($jar['lead'], 'GET', $treasury)['body'], $ownPayment) === true);
ok('ANOTHER treasurer sees that claim in their list', str_contains(req($jar['trs'], 'GET', $treasury)['body'], 'ACCESS lead own claim') === true);
post($jar['trs'], $treasury, $treasury . '/save', ['claimID' => 916301, 'payRef' => ['BACS-1'], 'comments' => '']);
ok('…and pays it: Reimbursed, with one payment recorded as theirs', claimState(916301) === 'Reimbursed, 1 approval(s), 1 payment(s)' && (int) (one('SELECT paidByID FROM tblExpenseClaimPayments WHERE claimID = 916301')['paidByID'] ?? 0) === TRS, claimState(916301));

head('E3. #545 — an ADMINISTRATOR who is the claimant is refused too');
$list = req($jar['adm'], 'GET', $approve);
ok('the administrator\'s own claim is NOT in their approvals list, though the lead\'s second claim is', str_contains($list['body'], 'ACCESS admin own claim') === false && str_contains($list['body'], 'ACCESS lead second claim') === true);
$answer = post($jar['adm'], $approve, $approve . '/save', ['claimID' => 916303, 'decision' => 'Approved', 'comments' => '']);
ok('the administrator POSTs an approval of their OWN claim: redirected, nothing recorded', $answer['status'] === 302 && claimState(916303) === $untouched, 'HTTP ' . $answer['status'] . '; claim: ' . claimState(916303));
ok('…and is shown the refusal sentence', str_contains(req($jar['adm'], 'GET', $approve)['body'], $ownDecision) === true);
post($jar['adm'], $approve, $approve . '/save', ['claimID' => 916303, 'decision' => 'Rejected', 'comments' => '']);
ok('the administrator cannot REJECT their own claim either: nothing recorded', claimState(916303) === $untouched, claimState(916303));
req($jar['adm'], 'GET', $approve);
post($jar['lead'], $approve, $approve . '/save', ['claimID' => 916303, 'decision' => 'Approved', 'comments' => '']);
ok('the department\'s lead approves it: Approved', claimState(916303) === 'Approved, 1 approval(s), 0 payment(s)', claimState(916303));
post($jar['adm'], $treasury, $treasury . '/save', ['claimID' => 916303, 'payRef' => ['SELF-PAY-2'], 'comments' => '']);
ok('the administrator POSTs a payment of their OWN claim: nothing recorded', claimState(916303) === 'Approved, 1 approval(s), 0 payment(s)', claimState(916303));
ok('…and is shown the refusal sentence', str_contains(req($jar['adm'], 'GET', $treasury)['body'], $ownPayment) === true);
post($jar['trs'], $treasury, $treasury . '/save', ['claimID' => 916303, 'payRef' => ['BACS-3'], 'comments' => '']);
ok('a treasurer pays it: Reimbursed', claimState(916303) === 'Reimbursed, 1 approval(s), 1 payment(s)', claimState(916303));

head('E4. #545 — what must not break');
post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916302, 'decision' => 'Approved', 'comments' => '']);
ok('a member\'s claim, approved by the plain approver: still Pending (its lead has not approved)', claimState(916302) === 'Pending, 1 approval(s), 0 payment(s)', claimState(916302));
post($jar['adm'], $approve, $approve . '/save', ['claimID' => 916302, 'decision' => 'Approved', 'comments' => '']);
ok('…approved by an administrator as well: STILL Pending (an administrator never stands in for the lead)', claimState(916302) === 'Pending, 2 approval(s), 0 payment(s)', claimState(916302));
post($jar['lead'], $approve, $approve . '/save', ['claimID' => 916302, 'decision' => 'Approved', 'comments' => '']);
ok('…approved by the lead: Approved', claimState(916302) === 'Approved, 3 approval(s), 0 payment(s)', claimState(916302));
post($jar['trs'], $treasury, $treasury . '/save', ['claimID' => 916302, 'payRef' => ['BACS-2'], 'comments' => '']);
ok('a treasurer pays somebody else\'s approved claim as before: Reimbursed', claimState(916302) === 'Reimbursed, 3 approval(s), 1 payment(s)', claimState(916302));
post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916304, 'decision' => 'Rejected', 'comments' => 'no receipt']);
ok('rejecting somebody else\'s claim works as before: Rejected at once', claimState(916304) === 'Rejected, 1 approval(s), 0 payment(s)', claimState(916304));
$answer = post($jar['mem'], $approve, $approve . '/save', ['claimID' => 916305, 'decision' => 'Approved', 'comments' => '']);
ok('somebody with no authority at all is still refused, with the old sentence, and nothing is recorded', $answer['status'] === 302 && claimState(916305) === $untouched && str_contains(req($jar['mem'], 'GET', $approve)['body'], 'Access denied — Approver or Admin role required.') === true, claimState(916305));

head('E5. #545 — the claim\'s own page');
$own   = req($jar['lead'], 'GET', ADDR_A . '/expenses/view?id=916305')['body'];
$other = req($jar['appr'], 'GET', ADDR_A . '/expenses/view?id=916305')['body'];
ok('another approver is offered "Go to Approvals" on the lead\'s pending claim, and is told nothing about "your own claim"', str_contains($other, 'Go to Approvals') === true && str_contains($other, 'This is your own claim') === false);
ok('the claimant (a lead) is NOT offered "Go to Approvals" on it', str_contains($own, 'ACCESS lead second claim') === true && str_contains($own, 'Go to Approvals') === false);
ok('…and is told, in one sentence, who decides it', str_contains($own, $ownPageNote) === true);
claim(916306, MEM, 'ACCESS member second claim');
$plain = req($jar['mem'], 'GET', ADDR_A . '/expenses/view?id=916306')['body'];
ok('an ordinary claimant, who never had the button, is shown neither the button nor the sentence', str_contains($plain, 'ACCESS member second claim') === true && str_contains($plain, 'Go to Approvals') === false && str_contains($plain, 'This is your own claim') === false);

// =============================================================================
// F to L — roll-out package B2a (12 October 2026): access gaps
// =============================================================================
// The pattern in F, G and H is the same: the page asked "is somebody signed
// in?" (or "is this person an administrator HERE?") and never "does the row
// they named belong to the organisation in the address?". Each part sends
// the request an outsider would send, then the one an insider would.

// 🚪 Parts F to I: the members-only gate is stood down in the scratch copy,
//    so that what answers each outsider is the rule inside the page. It is
//    put back, and the same outsiders are sent again, in the part after I.
//    See the note about roll-out package F2 at the top of this file.
membersGate(false);

// -----------------------------------------------------------------------------
head('F. Registrations: an administrator of one organisation cannot decide another organisation\'s registration');
// -----------------------------------------------------------------------------
/** A registration's status and who reviewed it. */
$regState = static function (int $id): string {
    $row = one('SELECT status, reviewedByID, reviewedAt FROM tblEventRegistrations WHERE registrationID = ?', 'i', [$id]);

    return $row === null ? 'missing' : $row['status'] . ', reviewer ' . ($row['reviewedByID'] ?? 'none') . ', reviewed ' . ($row['reviewedAt'] === null ? 'never' : 'yes');
};
$actA = ADDR_A . '/admin/calendar/registrations/act';
$actB = ADDR_B . '/admin/calendar/registrations/act';
$answer = post($jar['badm'], ADDR_B . '/account', $actB, ['registrationID' => 916501, 'eventID' => EV_INT, 'action' => 'approve']);
ok(
    'B\'s administrator, on B\'s address, posts the numbers of A\'s event and its registration: 404 "Registration not found", and the row is untouched',
    $answer['status'] === 404 && trim($answer['body']) === 'Registration not found' && $regState(916501) === 'pending, reviewer none, reviewed never',
    'HTTP ' . $answer['status'] . ' "' . mb_substr(trim($answer['body']), 0, 60) . '"; row: ' . $regState(916501)
);
$madeUp = post($jar['badm'], ADDR_B . '/account', $actB, ['registrationID' => 916599, 'eventID' => EV_NONE, 'action' => 'approve']);
ok('…which is exactly the answer for an event and a registration that do not exist', $madeUp['status'] === $answer['status'] && $madeUp['body'] === $answer['body'], 'HTTP ' . $madeUp['status'] . ' "' . mb_substr(trim($madeUp['body']), 0, 60) . '"');
$answer = post($jar['badm'], ADDR_A . '/account', $actA, ['registrationID' => 916501, 'eventID' => EV_INT, 'action' => 'reject']);
ok('the same person on A\'s address is no administrator there: 403, row untouched', $answer['status'] === 403 && $regState(916501) === 'pending, reviewer none, reviewed never', 'HTTP ' . $answer['status'] . '; row: ' . $regState(916501));
$answer = post($jar['adm'], ADDR_A . '/account', $actA, ['registrationID' => 916501, 'eventID' => EV_B, 'action' => 'approve']);
ok('A\'s own administrator naming B\'s event beside A\'s registration: 404, row untouched', $answer['status'] === 404 && $regState(916501) === 'pending, reviewer none, reviewed never', 'HTTP ' . $answer['status'] . '; row: ' . $regState(916501));
$answer = post($jar['adm'], ADDR_A . '/account', $actA, ['registrationID' => 916501, 'eventID' => EV_INT, 'action' => 'approve']);
ok('control: A\'s own administrator approves it, and it is recorded as theirs', $answer['status'] === 302 && $regState(916501) === 'approved, reviewer ' . ADM . ', reviewed yes', 'HTTP ' . $answer['status'] . '; row: ' . $regState(916501));
ok('…and is sent back to the list inside A\'s own address', $answer['location'] === ADDR_A . '/admin/calendar/registrations?eventID=' . EV_INT . '&status=pending', 'Location: ' . $answer['location']);
$answer = post($jar['badm'], ADDR_B . '/account', $actB, ['registrationID' => 916502, 'eventID' => EV_B, 'action' => 'waitlist']);
ok('control: B\'s administrator still decides B\'s own registration, on B\'s address', $answer['status'] === 302 && $regState(916502) === 'waitlisted, reviewer ' . BADM . ', reviewed yes' && str_starts_with($answer['location'], ADDR_B . '/admin/calendar/registrations?') === true, 'HTTP ' . $answer['status'] . ' ' . $answer['location'] . '; row: ' . $regState(916502));

// -----------------------------------------------------------------------------
head('G. Attendance: only the organisation\'s own people record or delete a head count');
// -----------------------------------------------------------------------------
$record = ADDR_A . '/attendance/record';
/** How many of A's sessions carry this note. */
$sessionsNoted = static fn (string $note): int => (int) one('SELECT COUNT(*) AS n FROM tblAttendanceSessions WHERE siteID = ? AND notes = ?', 'is', [ORG_A, $note])['n'];
$deleted       = static fn (int $id): int => (int) one('SELECT isDeleted FROM tblAttendanceSessions WHERE sessionID = ?', 'i', [$id])['isDeleted'];
$newSession    = static fn (string $note): array => ['action' => 'create', 'serviceTypeID' => 916601, 'sessionDate' => date('Y-m-d'), 'groups' => ['Adults'], 'counts' => [7], 'notes' => $note];
$answer = req($jar['bmem'], 'GET', $record);
ok('a member of B opens A\'s "record attendance" form: 403', $answer['status'] === 403, 'HTTP ' . $answer['status']);
$answer = post($jar['bmem'], ADDR_A . '/account', $record . '/save', $newSession('ACCESS-ATT outsider'));
ok('…posts a new head count to A\'s save address: 403, and no session is written', $answer['status'] === 403 && $sessionsNoted('ACCESS-ATT outsider') === 0, 'HTTP ' . $answer['status'] . '; sessions written: ' . $sessionsNoted('ACCESS-ATT outsider'));
$answer = post($jar['bmem'], ADDR_A . '/account', $record . '/delete', ['sessionID' => 916611]);
ok('…posts A\'s session number to A\'s delete address: 403, and the session is not deleted', $answer['status'] === 403 && $deleted(916611) === 0, 'HTTP ' . $answer['status'] . '; isDeleted: ' . $deleted(916611));
$answer = req($jar['mem'], 'GET', $record);
ok('control: a member of A opens the form (200)', $answer['status'] === 200, 'HTTP ' . $answer['status']);
$answer = post($jar['mem'], $record, $record . '/save', $newSession('ACCESS-ATT member'));
ok('control: …records a head count (any active member may)', $answer['status'] === 302 && $sessionsNoted('ACCESS-ATT member') === 1, 'HTTP ' . $answer['status'] . '; sessions written: ' . $sessionsNoted('ACCESS-ATT member'));
$answer = post($jar['mem'], $record, $record . '/delete', ['sessionID' => 916611]);
ok('control: …and deletes a session', $answer['status'] === 302 && $deleted(916611) === 1, 'HTTP ' . $answer['status'] . '; isDeleted: ' . $deleted(916611));
$answer = post($jar['gadm'], $record, $record . '/save', $newSession('ACCESS-ATT global'));
ok('control: a global administrator, a member of no organisation, records one too', $answer['status'] === 302 && $sessionsNoted('ACCESS-ATT global') === 1, 'HTTP ' . $answer['status'] . '; sessions written: ' . $sessionsNoted('ACCESS-ATT global'));
$answer = post($jar['gadm'], $record, $record . '/delete', ['sessionID' => 916612]);
ok('control: …and deletes one', $answer['status'] === 302 && $deleted(916612) === 1, 'HTTP ' . $answer['status'] . '; isDeleted: ' . $deleted(916612));

// -----------------------------------------------------------------------------
head('H. Venue bookings: all six viewer addresses are closed to another organisation\'s member');
// -----------------------------------------------------------------------------
$year       = substr($today, 0, 4);
$venuePages = [
    'one booking'            => '/venues/booking?id=' . BOOKING,
    'the year schedule'      => '/venues?venue=' . VENUE . '&year=' . $year,
    'the CSV export'         => '/venues/export?venue=' . VENUE . '&year=' . $year,
    'the printable schedule' => '/venues/schedule-pdf?venue=' . VENUE . '&year=' . $year,
];
$range        = '?from=' . $today . '&to=' . $today;
foreach ($venuePages as $what => $path) {
    $outsider = req($jar['bmem'], 'GET', ADDR_A . $path);
    $insider  = req($jar['mem'], 'GET', ADDR_A . $path);
    ok(
        sprintf('%s: a member of B is answered "page not found" (404) with none of A\'s venue data in it', $what),
        $outsider['status'] === 404 && str_contains($outsider['body'], VENUE_MARK) === false,
        'HTTP ' . $outsider['status'] . (str_contains($outsider['body'], VENUE_MARK) === true ? ', and the answer carries A\'s venue data' : '')
    );
    ok(sprintf('control, %s: a member of A still sees it', $what), $insider['status'] === 200 && str_contains($insider['body'], VENUE_MARK) === true, 'HTTP ' . $insider['status']);
}
$availability = ADDR_A . '/api/venues/availability' . $range;
$unknownVenue = req($jar['mem'], 'GET', $availability . '&venueID=916799');
foreach (['naming A\'s venue' => '&venueID=' . VENUE, 'naming no venue (every venue of A)' => ''] as $what => $extra) {
    $outsider = req($jar['bmem'], 'GET', $availability . $extra);
    ok(
        sprintf('the availability endpoint, %s: a member of B gets the answer an unknown venue gets (400 "invalid-range") and no data', $what),
        said($outsider) === '400: invalid-range' && stable($outsider) === stable($unknownVenue) && str_contains($outsider['body'], VENUE_MARK) === false,
        said($outsider) . ' (an unknown venue: ' . said($unknownVenue) . ')'
    );
}
$insider = req($jar['mem'], 'GET', $availability . '&venueID=' . VENUE);
ok('control: a member of A is answered with the booking', $insider['status'] === 200 && str_contains($insider['body'], VENUE_MARK) === true, said($insider));
$check        = ADDR_A . '/api/venues/check?start=' . $today . 'T09:30&end=' . $today . 'T10:30&tz=UTC&venueID=';
$outsider     = req($jar['bmem'], 'GET', $check . VENUE);
$unknownVenue = req($jar['mem'], 'GET', $check . '916799');
$insider      = req($jar['mem'], 'GET', $check . VENUE);
ok(
    'the "is it booked?" endpoint: a member of B gets exactly the answer an unknown venue gets ("venue-missing")',
    $outsider['status'] === 200 && (data($outsider)['classification'] ?? '') === 'venue-missing' && stable($outsider) === stable($unknownVenue),
    $outsider['status'] . ' ' . (data($outsider)['classification'] ?? '(none)') . ' (an unknown venue: ' . (data($unknownVenue)['classification'] ?? '(none)') . ')'
);
ok('control: a member of A gets a real answer about the venue', $insider['status'] === 200 && in_array(data($insider)['classification'] ?? 'venue-missing', ['venue-missing', ''], true) === false, $insider['status'] . ' ' . (data($insider)['classification'] ?? '(none)'));
$answer = req($jar['adm'], 'GET', ADDR_A . '/venues/booking?id=' . BOOKING);
ok('control: somebody who manages venues still gets the form to change the booking', $answer['status'] === 200 && str_contains($answer['body'], 'venues/booking-save') === true, 'HTTP ' . $answer['status']);
$answer = req($jar['gadm'], 'GET', ADDR_A . '/venues/booking?id=' . BOOKING);
ok('control: a global administrator, a member of no organisation, sees the booking too', $answer['status'] === 200 && str_contains($answer['body'], VENUE_MARK) === true, 'HTTP ' . $answer['status']);

// The two places the first round missed (found by the independent check,
// 12 October 2026): the page for ONE VENUE, and the strip of booked days on
// the CALENDAR page, which is a public address.
/** Was this answer a redirect to the sign-in page? */
$toSignIn = static fn (array $answer): bool => $answer['status'] === 302 && str_contains($answer['location'], 'login') === true;
/**
 * A page with everything that differs from one request to the next taken
 * out: form tokens and other long random values, and the venue number that
 * was asked for. Two pages that are the same after this are the same page.
 */
$samePage = static fn (array $answer): string => $answer['status'] . ' ' . (string) preg_replace(['/[0-9a-f]{32,}/', '/[A-Za-z0-9+\/]{20,}={0,2}/', '/9167\d\d/'], ['#', '#', 'N'], $answer['body']);
$venuePage = ADDR_A . '/venues/venue?id=';
foreach (['a member of B' => $jar['bmem'], 'an account with no membership anywhere' => $jar['nomem']] as $who => $file) {
    $outsider = req($file, 'GET', $venuePage . VENUE);
    $unknown  = req($file, 'GET', $venuePage . '916799');
    ok(
        sprintf('the page for one venue: %s gets exactly the page an unknown venue number gets (404), with none of A\'s venue data', $who),
        $outsider['status'] === 404 && $samePage($outsider) === $samePage($unknown) && str_contains($outsider['body'], VENUE_MARK) === false,
        'HTTP ' . $outsider['status'] . ' (an unknown venue: HTTP ' . $unknown['status'] . ')' . ($samePage($outsider) === $samePage($unknown) ? '' : ', and the two pages differ') . (str_contains($outsider['body'], VENUE_MARK) === true ? ', and the answer carries A\'s venue data' : '')
    );
}
$outsider = req($nobody, 'GET', $venuePage . VENUE);
ok('…and nobody at all is sent to sign-in, with no venue data (a control: this page always asked for a sign-in)', $toSignIn($outsider) === true && str_contains($outsider['body'], VENUE_MARK) === false, 'HTTP ' . $outsider['status'] . ' ' . $outsider['location']);
$insider = req($jar['mem'], 'GET', $venuePage . VENUE);
ok('control: a member of A still opens the venue\'s page, with its coming bookings', $insider['status'] === 200 && str_contains($insider['body'], VENUE_MARK . ' hall') === true && str_contains($insider['body'], VENUE_MARK . ' use') === true, 'HTTP ' . $insider['status']);
// Each view that holds both an event and a venue booking: the month, week,
// day and year of the events' day (worked out from today, like every date
// here).
//
// The list used to be month and week only. With the strip left on for
// everybody on the day view alone, or on the year view alone, every check
// still passed (two faults planted by the independent check, 12 October
// 2026). The year view also needed its own sign: it writes no venue name,
// only the key above the calendar and a coloured edge on each booked day.
/** What a page shows of the bookings: the venue's name, the key or a day's chips, a booked day's coloured edge. */
$venueSigns = static fn (string $body): array => array_keys(array_filter([
    'the venue\'s name'              => str_contains($body, VENUE_MARK),
    'the key or a day\'s chips'      => str_contains($body, 'portal-venue-'),
    'a booked day\'s coloured edge' => str_contains($body, 'box-shadow: inset 3px 0 0'),
]));
foreach (['month', 'week', 'day', 'year'] as $calendarView) {
    $calendar = ADDR_A . '/calendar?view=' . $calendarView . '&date=' . $eventDay;
    foreach (['nobody at all' => $nobody, 'a member of B' => $jar['bmem'], 'an account with no membership anywhere' => $jar['nomem']] as $who => $file) {
        $page = req($file, 'GET', $calendar);
        ok(
            sprintf('the calendar page, %s view, %s: opens (200) with the public event listed, and with none of A\'s venue bookings on it', $calendarView, $who),
            $page['status'] === 200 && str_contains($page['body'], 'ACCESS public event') === true && $venueSigns($page['body']) === [],
            'HTTP ' . $page['status'] . '; the public event ' . (str_contains($page['body'], 'ACCESS public event') === true ? 'is' : 'is NOT') . ' listed; on the page: ' . ($venueSigns($page['body']) === [] ? 'no sign of a booking' : implode(', ', $venueSigns($page['body'])))
        );
    }
    $page = req($jar['mem'], 'GET', $calendar);
    // A control that could not fail would prove nothing about the rows
    // above, so it names the sign each view must show.
    $expectedSign = $calendarView === 'year' ? 'a booked day\'s coloured edge' : 'the venue\'s name';
    ok(
        sprintf('control, %s view: a member of A still sees the booked days (%s, and the key), and both events', $calendarView, $expectedSign),
        $page['status'] === 200 && in_array($expectedSign, $venueSigns($page['body']), true) === true && str_contains($page['body'], 'portal-venue-legend') === true
            && str_contains($page['body'], 'ACCESS public event') === true && str_contains($page['body'], 'ACCESS internal event') === true,
        'HTTP ' . $page['status'] . '; on the page: ' . ($venueSigns($page['body']) === [] ? 'no sign of a booking' : implode(', ', $venueSigns($page['body'])))
    );
}
// "The rest of the calendar page is unchanged": for somebody who may not see
// the bookings, the page must be the very page they get when the Venues app
// is switched off for the organisation.
$calendar = ADDR_A . '/calendar?view=month&date=' . $eventDay;
$withApp  = req($nobody, 'GET', $calendar);
siteSetting(ORG_A, $switchable['venues'], 'false');
$withoutApp = req($nobody, 'GET', $calendar);
siteSetting(ORG_A, $switchable['venues'], 'true');
ok('…and for a signed-out visitor the calendar page is exactly the page they get with the Venues app switched off', $withApp['status'] === 200 && $samePage($withApp) === $samePage($withoutApp), 'HTTP ' . $withApp['status'] . ' and ' . $withoutApp['status'] . '; ' . strlen($withApp['body']) . ' and ' . strlen($withoutApp['body']) . ' bytes');

// -----------------------------------------------------------------------------
head('I. Live chat and livestream: an internal event\'s chat only for people who may see the event (#548)');
// -----------------------------------------------------------------------------
// "Nobody" below is a request with NO cookie at all: what a program, or a
// page written to call the portal from somewhere else, sends when nobody is
// signed in. (The portal's own chat box script never does that: on another
// website it asks that website, not the portal.)
$chat   = ADDR_A . '/api/livechat';
$ping   = ADDR_A . '/api/livestream/ping';
$newTok = static fn (): string => bin2hex(random_bytes(16));
$viewerSessions = static fn (int $event): int => (int) one('SELECT COUNT(*) AS n FROM tblLivestreamSessions WHERE eventID = ?', 'i', [$event])['n'];
$sentBy = static fn (string $name): int => (int) one('SELECT COUNT(*) AS n FROM tblLiveChatMessages WHERE displayName = ?', 's', [$name])['n'];
$list    = req('', 'GET', $chat . '/list?eventID=' . EV_INT . '&sinceID=0');
$nothing = req('', 'GET', $chat . '/list?eventID=' . EV_NONE . '&sinceID=0');
ok(
    'nobody asks for the approved messages of A\'s INTERNAL event: no message, and the same answer as a made-up event number',
    $list['status'] === 200 && data($list) === ['messages' => [], 'lastID' => 0, 'count' => 0] && stable($list) === stable($nothing) && str_contains($list['body'], 'ACCESS-CHAT') === false,
    mb_substr(stable($list), 0, 200)
);
$prompts = req('', 'GET', $chat . '/prompts?eventID=' . EV_INT);
$nothing = req('', 'GET', $chat . '/prompts?eventID=' . EV_NONE);
ok(
    'nobody asks for its prompts: none, and the same answer as a made-up event number',
    $prompts['status'] === 200 && data($prompts) === ['prompts' => [], 'count' => 0] && stable($prompts) === stable($nothing) && str_contains($prompts['body'], 'ACCESS-CHAT') === false,
    mb_substr(stable($prompts), 0, 200)
);
// The four numbers nobody may start a viewer session for, or post into.
$closed = ['A\'s internal event' => EV_INT, 'A\'s draft' => EV_DRAFT, 'B\'s event, on A\'s address' => EV_B, 'a made-up number' => EV_NONE];
$pingAnswers = [];
$sendAnswers = [];
foreach ($closed as $what => $event) {
    $tok      = $newTok();
    $pinged   = postJson('', $ping, ['sessionToken' => $tok, 'eventID' => $event]);
    $sent     = postJson('', $chat . '/send', ['sessionToken' => $tok, 'eventID' => $event, 'displayName' => 'ACCESS outsider', 'body' => 'should never be stored']);
    $pingAnswers[] = $pinged['status'] . ' ' . trim($pinged['body']);
    $sendAnswers[] = said($sent);
    ok(
        sprintf('nobody pings %s, then sends: 404 and no viewer session; "Unknown session" (401) and no message', $what),
        $pinged['status'] === 404 && trim($pinged['body']) === '{"error":"event"}' && $viewerSessions($event) === 0 && said($sent) === '401: Unknown session' && $sentBy('ACCESS outsider') === 0,
        'ping: ' . $pinged['status'] . ' ' . trim($pinged['body']) . ', sessions ' . $viewerSessions($event) . '; send: ' . said($sent) . ', messages ' . $sentBy('ACCESS outsider')
    );
}
ok('…and all four got one and the same pair of answers, so the reply never says which kind of number it was', count(array_unique($pingAnswers)) === 1 && count(array_unique($sendAnswers)) === 1, implode(' | ', array_unique(array_merge($pingAnswers, $sendAnswers))));
// A viewer session made BEFORE this rule existed (the ping used to store any number it was sent).
$oldTok = $newTok();
q('INSERT INTO tblLivestreamSessions (siteID, eventID, sessionToken) VALUES (?, ?, ?)', 'iis', [ORG_A, EV_INT, $oldTok]);
$sent = postJson('', $chat . '/send', ['sessionToken' => $oldTok, 'eventID' => EV_INT, 'displayName' => 'ACCESS old session', 'body' => 'should never be stored']);
ok('a viewer session for the internal event that already exists does not keep the door open: "Unknown session", no message', said($sent) === '401: Unknown session' && $sentBy('ACCESS old session') === 0, said($sent) . ', messages ' . $sentBy('ACCESS old session'));
// The PUBLIC event: everything still works with no cookie, as on the public website.
$tok    = $newTok();
$pinged = postJson('', $ping, ['sessionToken' => $tok, 'eventID' => EV_PUB]);
$sent   = postJson('', $chat . '/send', ['sessionToken' => $tok, 'eventID' => EV_PUB, 'displayName' => 'ACCESS public viewer', 'body' => 'hello from the website']);
ok('control, the PUBLIC event with no cookie: the ping is recorded and a message is accepted', $pinged['status'] === 200 && trim($pinged['body']) === '{"ok":true}' && $viewerSessions(EV_PUB) === 1 && $sent['status'] === 200 && $sentBy('ACCESS public viewer') === 1, 'ping: ' . $pinged['status'] . ' ' . trim($pinged['body']) . '; send: ' . said($sent));
$list    = req('', 'GET', $chat . '/list?eventID=' . EV_PUB . '&sinceID=0');
$prompts = req('', 'GET', $chat . '/prompts?eventID=' . EV_PUB);
ok('control: …and its approved messages and its prompts are answered', str_contains($list['body'], 'ACCESS-CHAT public message') === true && str_contains($prompts['body'], 'ACCESS-CHAT public prompt') === true && str_contains($list['body'] . $prompts['body'], 'internal') === false, $list['status'] . ' / ' . $prompts['status']);
// A NAME LONGER THAN ITS COLUMN. The server used to cut a name at 60
// characters; the column holds 40. The chat box stops at 40, so only a
// direct caller met it: a name of more than 40 characters got a bare 500 and
// the message was lost (found by the independent check, 12 October 2026).
q('DELETE FROM tblLiveRateLimits WHERE siteID = ?', 'i', [ORG_A]);
$longNames = [];
foreach ([41, 60] as $length) {
    $name        = mb_substr('ACCESS name of ' . $length . ' ' . str_repeat('n', 60), 0, $length);
    $sent        = postJson('', $chat . '/send', ['sessionToken' => $tok, 'eventID' => EV_PUB, 'displayName' => $name, 'body' => 'a long name']);
    $longNames[] = $length . ' characters: ' . $sent['status'] . ', stored under its first 40: ' . $sentBy(mb_substr($name, 0, 40));
}
ok(
    'a chat name of 41 characters, and one of 60, is cut to the 40 the column holds and the message is stored (each used to answer 500)',
    $longNames === ['41 characters: 200, stored under its first 40: 1', '60 characters: 200, stored under its first 40: 1'],
    implode(' | ', $longNames)
);
$pinged = postJson('', $ping, ['sessionToken' => $newTok()]);
ok('control: a ping that names no event is still recorded, as a viewer of no particular event', $pinged['status'] === 200 && trim($pinged['body']) === '{"ok":true}', $pinged['status'] . ' ' . trim($pinged['body']));
// Signed in, inside the portal: a member of A may use the internal event's chat; a member of B may not.
// "Inside the portal" is something the BROWSER says: `Sec-Fetch-Site:
// same-origin` when the page was served from the address being asked. A
// browser sends that header only to an https address or to its own machine
// (which 127.0.0.1 is); to a portal on plain http it sends none, and `Origin`
// or `Referer` decides, as in the "older browser" rows further down. The
// test writes the header itself here.
$fromPortal = ['Sec-Fetch-Site: same-origin'];
$tok    = $newTok();
$list   = req($jar['mem'], 'GET', $chat . '/list?eventID=' . EV_INT . '&sinceID=0', null, '', null, $fromPortal);
$pinged = postJson($jar['mem'], $ping, ['sessionToken' => $tok, 'eventID' => EV_INT], $fromPortal);
$sent   = postJson($jar['mem'], $chat . '/send', ['sessionToken' => $tok, 'eventID' => EV_INT, 'displayName' => 'ACCESS member viewer', 'body' => 'hello from inside'], $fromPortal);
ok(
    'a signed-in member of A reads, joins and posts in the INTERNAL event\'s chat (inside the portal it works)',
    str_contains($list['body'], 'ACCESS-CHAT internal message') === true && $pinged['status'] === 200 && $sent['status'] === 200 && $sentBy('ACCESS member viewer') === 1,
    'list ' . $list['status'] . '; ping ' . $pinged['status'] . ' ' . trim($pinged['body']) . '; send ' . said($sent)
);
$tok    = $newTok();
$list   = req($jar['bmem'], 'GET', $chat . '/list?eventID=' . EV_INT . '&sinceID=0', null, '', null, $fromPortal);
$pinged = postJson($jar['bmem'], $ping, ['sessionToken' => $tok, 'eventID' => EV_INT], $fromPortal);
$sent   = postJson($jar['bmem'], $chat . '/send', ['sessionToken' => $tok, 'eventID' => EV_INT, 'displayName' => 'ACCESS other-org viewer', 'body' => 'should never be stored'], $fromPortal);
ok(
    'a signed-in member of B, on A\'s address and from the portal\'s own page, gets what nobody gets: no message, 404, "Unknown session", nothing stored',
    data($list) === ['messages' => [], 'lastID' => 0, 'count' => 0] && $pinged['status'] === 404 && said($sent) === '401: Unknown session' && $sentBy('ACCESS other-org viewer') === 0,
    'list ' . mb_substr(stable($list), 0, 80) . '; ping ' . $pinged['status'] . '; send ' . said($sent)
);

// ---- WHERE THE REQUEST CAME FROM (the fix round of 12 October 2026) ----------
// The first version of the rule rested on the sign-in cookie alone. A browser
// sends that cookie to the portal from ANY page under the same registered
// name, and these endpoints ask for no form token, so a page on another
// address of the same site could post into an internal event's chat through
// a signed-in member who merely opened it (proved in a real browser by the
// independent check). Now the sign-in counts, for the four endpoints, only
// when the browser says one of the portal's own pages made the request:
// `Sec-Fetch-Site`, or for an older browser `Origin`, or failing that
// `Referer`. Each row below is one way a request can arrive, with a MEMBER
// OF A's cookie attached every time.
$ownAddress = 'http://127.0.0.1:' . $port;
$livePage   = $ownAddress . ADDR_A . '/live?eventID=' . EV_INT;
$round      = 0;
/**
 * The four endpoints, asked once each with one cookie and one set of
 * headers: what each answered, and how many messages were stored.
 */
$ask = static function (string $file, int $event, string $kind, array $headers) use ($chat, $ping, $newTok, $sentBy, &$round): string {
    // The chat's own limit on sends (five a minute from one address) is not
    // what is being tested, so each round starts with none counted.
    q('DELETE FROM tblLiveRateLimits WHERE siteID = ?', 'i', [ORG_A]);
    $round++;
    $name    = 'ACCESS origin ' . $round;
    $tok     = $newTok();
    $list    = req($file, 'GET', $chat . '/list?eventID=' . $event . '&sinceID=0', null, '', null, $headers);
    $prompts = req($file, 'GET', $chat . '/prompts?eventID=' . $event, null, '', null, $headers);
    $pinged  = postJson($file, $ping, ['sessionToken' => $tok, 'eventID' => $event], $headers);
    $sent    = postJson($file, $chat . '/send', ['sessionToken' => $tok, 'eventID' => $event, 'displayName' => $name, 'body' => 'round ' . $round], $headers);
    $empty   = data($list) === ['messages' => [], 'lastID' => 0, 'count' => 0] && data($prompts) === ['prompts' => [], 'count' => 0];
    $full    = str_contains($list['body'], 'ACCESS-CHAT ' . $kind . ' message') === true && str_contains($prompts['body'], 'ACCESS-CHAT ' . $kind . ' prompt') === true;

    return 'messages and prompts ' . ($full === true ? 'answered' : ($empty === true ? 'empty' : 'MIXED')) . ', ping ' . $pinged['status'] . ', send ' . $sent['status'] . ', stored ' . $sentBy($name);
};
$answered = 'messages and prompts answered, ping 200, send 200, stored 1';
$refused  = 'messages and prompts empty, ping 404, send 401, stored 0';
// [what the browser sent, the headers, what must happen for the INTERNAL event]
$arrivals = [
    ['the portal\'s own page (Sec-Fetch-Site: same-origin)', ['Sec-Fetch-Site: same-origin'], $answered],
    ['a page on another address of the SAME site (Sec-Fetch-Site: same-site)', ['Sec-Fetch-Site: same-site'], $refused],
    ['the same, sent as plain text so the browser asks no permission first', ['Sec-Fetch-Site: same-site', 'Content-Type: text/plain;charset=UTF-8'], $refused],
    ['a page on a different website (Sec-Fetch-Site: cross-site)', ['Sec-Fetch-Site: cross-site'], $refused],
    ['an address typed by hand (Sec-Fetch-Site: none)', ['Sec-Fetch-Site: none'], $refused],
    ['a browser that says "same-site" but whose Origin and Referer both name the portal: the first header decides', ['Sec-Fetch-Site: same-site', 'Origin: ' . $ownAddress, 'Referer: ' . $livePage], $refused],
    // The next row, and the "null" and X-Forwarded-Host rows below, were
    // added after three faults planted by the independent check (12 October
    // 2026) passed every row there was: a value the code does not know
    // skipped instead of refused, `Origin: null` skipped so that `Referer`
    // decided, and the address compared against taken from a header any
    // sender can write.
    ['a Sec-Fetch-Site value that is not one of the four (same-origin-x), with the portal\'s own address as Origin: an unknown value is a no, not a reason to look further', ['Sec-Fetch-Site: same-origin-x', 'Origin: ' . $ownAddress], $refused],
    ['NO header that says where it came from: unsure means no', [], $refused],
    ['an older browser, Origin is the portal\'s own address', ['Origin: ' . $ownAddress], $answered],
    ['an older browser, Origin is another port of the same name', ['Origin: http://127.0.0.1:1'], $refused],
    ['an older browser, Origin is the same name over https', ['Origin: https://127.0.0.1:' . $port], $refused],
    ['an older browser, Origin is another website', ['Origin: http://elsewhere.invalid'], $refused],
    ['an older browser, Origin is "null" (a boxed-in frame)', ['Origin: null'], $refused],
    ['an older browser, Origin is "null" but Referer names the portal\'s own /live page: Origin decides', ['Origin: null', 'Referer: ' . $livePage], $refused],
    ['an older browser, Origin is another website, which the request also names in X-Forwarded-Host: only the address really asked counts', ['X-Forwarded-Host: elsewhere.invalid', 'Origin: http://elsewhere.invalid'], $refused],
    ['an older browser, Origin is another website but Referer names the portal: Origin decides', ['Origin: http://elsewhere.invalid', 'Referer: ' . $livePage], $refused],
    ['an older browser, no Origin, Referer is the portal\'s own /live page', ['Referer: ' . $livePage], $answered],
    ['an older browser, no Origin, Referer is a page somewhere else', ['Referer: http://elsewhere.invalid/watch'], $refused],
    ['an older browser, no Origin, Referer has the portal\'s name in front of another website\'s', ['Referer: http://127.0.0.1:' . $port . '@elsewhere.invalid/live'], $refused],
];
foreach ($arrivals as [$what, $headers, $expected]) {
    $got = $ask($jar['mem'], EV_INT, 'internal', $headers);
    ok(sprintf('a member of A\'s cookie, the INTERNAL event, from %s: %s', $what, $expected === $answered ? 'answered' : 'treated as nobody'), $got === $expected, $got);
}
// TWO PROMISES NO REQUEST HERE CAN SHOW, so they are read from the class
// itself (the file the server's copy was made from).
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'LiveChat.php';
// 1. The comment above LiveChat::eventOpenToViewer() says its fourth argument
//    has no default on purpose. Given a default of false, every endpoint
//    here still passes true and every row above still passes, while the next
//    endpoint written without it would be an open door.
$fourth = (new ReflectionMethod(Portal\Core\LiveChat::class, 'eventOpenToViewer'))->getParameters()[3] ?? null;
ok(
    'LiveChat::eventOpenToViewer() takes "own pages only" as its fourth argument with NO default, so a caller that forgets it gets an error, not an open door',
    $fourth !== null && $fourth->getName() === 'ownPagesOnly' && $fourth->isOptional() === false && $fourth->isDefaultValueAvailable() === false,
    $fourth === null ? 'there is no fourth argument' : '$' . $fourth->getName() . ($fourth->isOptional() === true ? ' is optional' : ' must be given')
);
// 2. Three addresses the comparison used to read as the portal's own. The
//    test's server has a port in its address, so a port written as 0 cannot
//    be tried against it; the rule is asked directly instead, as a portal at
//    https://portal.example.org would hear it.
$cameFromOwnPages = new ReflectionMethod(Portal\Core\LiveChat::class, 'requestCameFromOwnPages');
$heard            = static function (array $headers) use ($cameFromOwnPages): string {
    $kept = $_SERVER;
    foreach (['HTTP_SEC_FETCH_SITE', 'HTTP_ORIGIN', 'HTTP_REFERER', 'HTTP_X_FORWARDED_PROTO'] as $key) {
        unset($_SERVER[$key]);
    }
    $_SERVER = ['HTTP_HOST' => 'portal.example.org', 'HTTPS' => 'on'] + $headers + $_SERVER;
    $answer  = $cameFromOwnPages->invoke(null) === true ? 'own page' : 'no';
    $_SERVER = $kept;

    return $answer;
};
$oddAddresses = [
    'control, Origin is the portal\'s own address'                    => $heard(['HTTP_ORIGIN' => 'https://portal.example.org']),
    'control, Referer is a page at the portal\'s own address'         => $heard(['HTTP_REFERER' => 'https://portal.example.org/live']),
    'Origin with the portal\'s name after "another website\\@"'       => $heard(['HTTP_ORIGIN' => 'https://elsewhere.invalid\\@portal.example.org']),
    'Referer with the portal\'s name after "another website\\@"'      => $heard(['HTTP_REFERER' => 'https://elsewhere.invalid\\@portal.example.org/live']),
    'Referer at the portal\'s own name with the port written as 0'    => $heard(['HTTP_REFERER' => 'https://portal.example.org:0/live']),
];
// What the function underneath hands back for the two odd ones: '' (its own
// word for "not an address"), not an address that merely fails to match.
$spelling = new ReflectionMethod(Portal\Core\LiveChat::class, 'schemeNameAndPort');
$spelt    = [$spelling->invoke(null, 'https://elsewhere.invalid\\@portal.example.org'), $spelling->invoke(null, 'https://portal.example.org:0/live'), $spelling->invoke(null, 'https://portal.example.org:443/live')];
ok(
    'the address comparison refuses an address with a user part ("name@") and one whose port is written as 0; each of the three used to count as the portal\'s own page',
    array_values($oddAddresses) === ['own page', 'own page', 'no', 'no', 'no'] && $spelt === ['', '', 'https://portal.example.org'],
    implode('; ', array_map(static fn (string $what, string $answer): string => $what . ': ' . $answer, array_keys($oddAddresses), $oddAddresses)) . '; read as: ' . json_encode($spelt, JSON_UNESCAPED_SLASHES)
);
// The "send" endpoint's OWN test. In every refused row above the ping is
// refused first, so no viewer session exists and "send" would say no for
// that reason alone: with the rule taken out of "send" only, every row
// still passed (found by planting exactly that). Here the viewer session
// already exists, as one made before the rule did, so only the rule can
// refuse.
$heldTok = $newTok();
q('INSERT INTO tblLivestreamSessions (siteID, eventID, sessionToken) VALUES (?, ?, ?)', 'iis', [ORG_A, EV_INT, $heldTok]);
$sends = [];
foreach ([['Sec-Fetch-Site: same-site'], [], ['Sec-Fetch-Site: same-origin']] as $headers) {
    q('DELETE FROM tblLiveRateLimits WHERE siteID = ?', 'i', [ORG_A]);
    $sends[] = postJson($jar['mem'], $chat . '/send', ['sessionToken' => $heldTok, 'eventID' => EV_INT, 'displayName' => 'ACCESS held session', 'body' => 'held'], $headers)['status'];
}
ok(
    'a member of A\'s cookie and a viewer session that ALREADY exists: "send" itself refuses another address of the same site and a request with no header (401, 401), and accepts the portal\'s own page (200); one message stored',
    $sends === [401, 401, 200] && $sentBy('ACCESS held session') === 1,
    implode(', ', $sends) . '; stored ' . $sentBy('ACCESS held session')
);
// A PUBLIC event answers as before, wherever the request came from.
foreach ([['a different website', ['Sec-Fetch-Site: cross-site']], ['another address of the same site', ['Sec-Fetch-Site: same-site']], ['nowhere it can name', []]] as [$what, $headers]) {
    $got = $ask($jar['mem'], EV_PUB, 'public', $headers);
    ok(sprintf('control: the PUBLIC event still answers a request from %s', $what), $got === $answered, $got);
}
// The "can manage events" half of the rule: an administrator's own DRAFT.
$drafts = [];
foreach ([['Sec-Fetch-Site: same-origin'], ['Sec-Fetch-Site: same-site'], []] as $headers) {
    $drafts[] = postJson($jar['adm'], $ping, ['sessionToken' => $newTok(), 'eventID' => EV_DRAFT], $headers)['status'];
}
ok('an administrator of A pings their own DRAFT: recorded from the portal\'s own page (200), refused from another address of the same site and with no header (404, 404)', $drafts === [200, 404, 404], implode(', ', $drafts));
q('DELETE FROM tblLiveRateLimits WHERE siteID = ?', 'i', [ORG_A]);

/** Is the chat box on the /live page for this event? `$extra` is a list of header lines, as for req(). */
$box = static fn (string $who, int $event, array $extra = []): string => (static function (array $page): string {
    return $page['status'] . (str_contains($page['body'], 'data-livechat-widget') === true ? '+box' : '');
})(req($who, 'GET', ADDR_A . '/live?eventID=' . $event, null, '', null, $extra));
$boxes = [$box($jar['bmem'], EV_INT), $box($jar['bmem'], EV_DRAFT), $box($jar['bmem'], EV_NONE), $box($jar['bmem'], EV_PUB)];
ok('the /live page, for a member of B: no chat box for A\'s internal event, its draft or a made-up number (all the same); a box for the public event', $boxes === ['200', '200', '200', '200+box'], 'internal, draft, made-up, public: ' . implode(', ', $boxes));
$boxes = [$box($jar['mem'], EV_INT), $box($jar['mem'], EV_DRAFT), $box($jar['mem'], EV_PUB)];
ok('control: a member of A gets the box for the internal and the public event, and not for the draft', $boxes === ['200+box', '200', '200+box'], 'internal, draft, public: ' . implode(', ', $boxes));
ok('control: an administrator of A gets the box for the draft too (the #503 rule is kept)', $box($jar['adm'], EV_DRAFT) === '200+box', $box($jar['adm'], EV_DRAFT));
// /live is a page a person OPENS, so where they came from must not matter to it.
$boxes = [$box($jar['mem'], EV_INT, ['Sec-Fetch-Site: cross-site']), $box($jar['mem'], EV_INT, ['Sec-Fetch-Site: none']), $box($jar['mem'], EV_INT, ['Sec-Fetch-Site: same-site'])];
ok('control: /live still draws the box for a member of A who arrives from a link on another website, from a bookmark, or from another address of the same site', $boxes === ['200+box', '200+box', '200+box'], implode(', ', $boxes));
// The old copy of the ping handler (web/_apps/api/livestream-ping.php, deleted
// by package B2a) could only ever have been asked for at one of these two
// addresses. Neither reaches a handler, before the deletion or after it: the
// first is "no such endpoint", the second is not a whole API address.
foreach (['/api/api/livestream-ping' => '404: API endpoint not found', '/api/livestream-ping' => '400: Invalid API path'] as $oldAddress => $refusal) {
    $tok    = $newTok();
    $answer = postJson('', ADDR_A . $oldAddress, ['sessionToken' => $tok, 'token' => $tok, 'eventID' => EV_PUB]);
    ok(
        sprintf('the deleted old ping handler was never reachable: %s answers "%s" and records no viewer', $oldAddress, $refusal),
        said($answer) === $refusal && one('SELECT COUNT(*) AS n FROM tblLivestreamSessions WHERE sessionToken = ?', 's', [$tok])['n'] === 0,
        said($answer)
    );
}

// -----------------------------------------------------------------------------
head('F to I, at the door: with the members-only gate back, the same outsiders are refused before any page runs (#511)');
// -----------------------------------------------------------------------------
membersGate(true);
// F. B's administrator, with a form token from their own organisation's page, posts to A's handler.
$regBefore = $regState(916502);
$answer    = post($jar['badm'], ADDR_B . '/account', $actA, ['registrationID' => 916502, 'eventID' => EV_B, 'action' => 'approve']);
ok('at the door, F: B\'s administrator posting to A\'s registrations handler is refused (403), and nothing is changed', refusedAtTheDoor($answer) === true && $regState(916502) === $regBefore, 'HTTP ' . $answer['status'] . '; row: ' . $regState(916502));
// G. A member of B at A's attendance form and its two handlers.
$doorAnswers = [
    req($jar['bmem'], 'GET', $record),
    post($jar['bmem'], ADDR_B . '/account', $record . '/save', $newSession('ACCESS-ATT at the door')),
    post($jar['bmem'], ADDR_B . '/account', $record . '/delete', ['sessionID' => 916611]),
];
ok(
    'at the door, G: a member of B is refused (403) on A\'s attendance form, its save address and its delete address, and no session is written',
    array_filter($doorAnswers, 'refusedAtTheDoor') === $doorAnswers && $sessionsNoted('ACCESS-ATT at the door') === 0,
    implode(', ', array_column($doorAnswers, 'status')) . '; sessions written: ' . $sessionsNoted('ACCESS-ATT at the door')
);
// H. Every venue address, and the calendar page, for a member of B and for an account with no membership.
$notRefused = [];
foreach ([$jar['bmem'], $jar['nomem']] as $outsiderJar) {
    foreach (array_merge(array_values($venuePages), [
        '/api/venues/availability' . $range . '&venueID=' . VENUE, '/api/venues/availability' . $range,
        '/api/venues/check?start=' . $today . 'T09:30&end=' . $today . 'T10:30&tz=UTC&venueID=' . VENUE,
        '/venues/venue?id=' . VENUE, '/venues/venue?id=916799',
    ]) as $path) {
        $atDoor = req($outsiderJar, 'GET', ADDR_A . $path);
        if (refusedAtTheDoor($atDoor) === false) {
            $notRefused[] = $path . ' gave HTTP ' . $atDoor['status'];
        }
    }
}
ok('at the door, H: a member of B and an account with no membership are refused (403) on every venue address of A, with none of A\'s data', $notRefused === [], implode('; ', $notRefused));
$notRefused = [];
foreach (['month', 'week', 'day', 'year'] as $calendarView) {
    foreach ([$jar['bmem'], $jar['nomem']] as $outsiderJar) {
        $atDoor = req($outsiderJar, 'GET', ADDR_A . '/calendar?view=' . $calendarView . '&date=' . $eventDay);
        if (refusedAtTheDoor($atDoor) === false) {
            $notRefused[] = $calendarView . ' view gave HTTP ' . $atDoor['status'];
        }
    }
}
ok('at the door, H: the same two are refused (403) on A\'s calendar page in every view, public though it is (they can sign out to see it)', $notRefused === [], implode('; ', $notRefused));
// I. The chat's three addresses and the /live page, for a member of B.
$tok         = $newTok();
$doorAnswers = [
    req($jar['bmem'], 'GET', $chat . '/list?eventID=' . EV_INT . '&sinceID=0', null, '', null, $fromPortal),
    postJson($jar['bmem'], $ping, ['sessionToken' => $tok, 'eventID' => EV_INT], $fromPortal),
    postJson($jar['bmem'], $chat . '/send', ['sessionToken' => $tok, 'eventID' => EV_INT, 'displayName' => 'ACCESS at the door viewer', 'body' => 'should never be stored'], $fromPortal),
];
ok(
    'at the door, I: a signed-in member of B is refused (403) on A\'s chat list, viewer ping and chat send, and nothing is stored',
    array_filter($doorAnswers, 'refusedAtTheDoor') === $doorAnswers && $sentBy('ACCESS at the door viewer') === 0,
    implode(', ', array_column($doorAnswers, 'status'))
);
$boxes = [$box($jar['bmem'], EV_INT), $box($jar['bmem'], EV_DRAFT), $box($jar['bmem'], EV_NONE), $box($jar['bmem'], EV_PUB)];
ok('at the door, I: the /live page refuses a member of B (403) for A\'s internal event, its draft, a made-up number and the public event alike', $boxes === ['403', '403', '403', '403'], 'internal, draft, made-up, public: ' . implode(', ', $boxes));
// A control, so that "refused" above cannot be a server that refuses everybody.
$answer = req($jar['mem'], 'GET', ADDR_A . '/venues/venue?id=' . VENUE);
ok('at the door, control: with the gate back, a member of A still opens the venue\'s page', $answer['status'] === 200 && str_contains($answer['body'], VENUE_MARK) === true, 'HTTP ' . $answer['status']);

// -----------------------------------------------------------------------------
head('J. A child\'s check-in and a decision card are never lost over a bad event number');
// -----------------------------------------------------------------------------
/** What was stored for a child: "no row", or the event number kept on the check-in. */
$checkin = static function (int $childId): string {
    $rows = q('SELECT eventID FROM tblKidCheckins WHERE childID = ?', 'i', [$childId]);

    return count($rows) === 0 ? 'no row' : count($rows) . ' row(s), event ' . ($rows[0]['eventID'] ?? 'none');
};
$card = static function (string $name): string {
    $rows = q('SELECT eventID FROM tblSalvationCards WHERE siteID = ? AND fullName = ?', 'is', [ORG_A, $name]);

    return count($rows) === 0 ? 'no row' : count($rows) . ' row(s), event ' . ($rows[0]['eventID'] ?? 'none');
};
$kids = ADDR_A . '/kids/checkin';
// The deleted and the imported event are this organisation's OWN rows, so only
// the "not deleted, not imported" half of the look-up refuses them. A fault
// planted by the independent check (that half taken out) passed every check
// here until these two rows were added.
foreach ([[916801, EV_B, 'another organisation\'s event number', 'none'], [916802, EV_NONE, 'a number that matches no event', 'none'], [916804, EV_GONE, 'this organisation\'s own DELETED event', 'none'], [916805, EV_IMPORTED, 'this organisation\'s own event that was copied in from an outside calendar', 'none'], [916803, EV_INT, 'this organisation\'s own event', (string) EV_INT]] as [$childId, $event, $what, $kept]) {
    $answer = post($jar['adm'], $kids, $kids . '/do', ['childID' => $childId, 'eventID' => $event]);
    ok(
        sprintf('a child is checked in with %s: the check-in IS saved, with event "%s"', $what, $kept),
        $answer['status'] === 302 && $checkin($childId) === '1 row(s), event ' . $kept,
        'HTTP ' . $answer['status'] . '; stored: ' . $checkin($childId)
    );
}
$cardPage = ADDR_A . '/decision-card';
foreach ([['ACCESS card other organisation', EV_B, 'another organisation\'s event number', 'none'], ['ACCESS card made-up', EV_NONE, 'a number that matches no event', 'none'], ['ACCESS card deleted event', EV_GONE, 'this organisation\'s own DELETED event', 'none'], ['ACCESS card imported event', EV_IMPORTED, 'this organisation\'s own event that was copied in from an outside calendar', 'none'], ['ACCESS card own event', EV_INT, 'this organisation\'s own event', (string) EV_INT]] as [$name, $event, $what, $kept]) {
    $answer = post($nobody, $cardPage, $cardPage . '/save', ['fullName' => $name, 'eventID' => $event, 'decision' => 'first-time']);
    ok(
        sprintf('a visitor sends a decision card with %s: the card IS saved, with event "%s"', $what, $kept),
        $answer['status'] === 200 && str_contains($answer['body'], 'Thank you') === true && $card($name) === '1 row(s), event ' . $kept,
        'HTTP ' . $answer['status'] . '; stored: ' . $card($name)
    );
}

// -----------------------------------------------------------------------------
head('K. A switched-off or erased account: its open session is ended on its next request');
// -----------------------------------------------------------------------------
// Auth::ensureSession() asks, once for each request that carries a signed-in
// session, whether the account is still switched on, and ENDS the session
// when it is not. Everything below is a real sign-in through the real
// sign-in page, with real cookies.
$account = ADDR_A . '/account';
/** Was this answer a redirect to the sign-in page? */
$sentToSignIn = static fn (array $answer): bool => $answer['status'] === 302 && str_contains($answer['location'], 'login') === true;
/** The session identifier a cookie jar holds ('' when none). */
$sessionId = static function (string $file): string {
    foreach ((array) @file($file) as $line) {
        $columns = explode("\t", rtrim((string) $line, "\r\n"));
        if (count($columns) === 7 && $columns[5] === 'PHPSESSID') {
            return $columns[6];
        }
    }

    return '';
};
/** Is a session's stored data still on disk? (This run's server keeps its sessions in the scratch folder.) */
$sessionStored = static function (string $id) use ($child, $sep): bool {
    // PHP remembers what it last learnt about a file. Without this, a file
    // the SERVER has since deleted would still be reported as there.
    clearstatcache();

    return $id !== '' && is_file($child . $sep . 'sessions' . $sep . 'sess_' . $id) === true;
};
$setActive     = static function (int $userId, int $flag): void {
    q('UPDATE tblUsers SET isActive = ? WHERE userID = ?', 'ii', [$flag, $userId]);
};
$endedRows = static fn (int $userId): int => (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = 'SessionEndedInactiveAccount'", 'i', [$userId])['n'];

// 📜 The database's own query log, switched on for this part: it is how "one
//    look-up for a signed-in request, none for anybody else" is COUNTED
//    rather than assumed. Put back by the clean-up.
$logBefore = one('SELECT @@GLOBAL.general_log AS general_log, @@GLOBAL.log_output AS log_output');
if ($logBefore === null || in_array((int) $logBefore['general_log'], [0, 1], true) === false || preg_match('/^[A-Z,]+$/', (string) $logBefore['log_output']) !== 1) {
    refuse('the database would not say how its query log is set');
}
$state['queryLog'] = ['general_log' => (int) $logBefore['general_log'], 'log_output' => (string) $logBefore['log_output']];
q("SET GLOBAL log_output = 'TABLE'");
q('SET GLOBAL general_log = 1');
$logEmpty = static function (): void {
    q('TRUNCATE TABLE mysql.general_log');
};
/** How many times the portal ran the look-up since the log was last emptied. */
$lookups = static fn (): int => (int) one("SELECT COUNT(*) AS n FROM mysql.general_log WHERE command_type = 'Execute' AND thread_id <> CONNECTION_ID() AND CONVERT(argument USING utf8mb4) LIKE ?", 's', [LOOKUP_LIKE])['n'];
/** Every statement the portal sent since then, and how many of them name the accounts table. */
$sent = static fn (): array => array_map('intval', (array) one(
    "SELECT COUNT(*) AS commands, COALESCE(SUM(CONVERT(argument USING utf8mb4) LIKE '%tblUsers%'), 0) AS namingAccounts FROM mysql.general_log "
    . "WHERE command_type IN ('Query', 'Execute') AND thread_id <> CONNECTION_ID()"
));

head('K1. Switched off while signed in: three open sessions, three kinds of address');
$open = [signIn('px', ADDR_A), signIn('px', ADDR_A), signIn('px', ADDR_A)];
// "Anybody may send in an event" is switched off for A, so the public
// submission page shows its form only to somebody signed in. That page
// decides "signed in or not" by reading the session itself; it never asks
// Auth::check().
siteSetting(ORG_A, 'calendar.publicSubmit.allowAnonymous', 'false');
$submit   = ADDR_A . '/calendar/submit';
$statuses = [req($open[0], 'GET', $account)['status'], req($open[1], 'GET', ADDR_A . '/api/assets/list')['status'], req($open[2], 'GET', $submit)['status']];
$oldId    = $sessionId($open[0]);
$savedCookie = $open[0] . '.saved-before';
copy($open[0], $savedCookie);
ok('control: while the account is on, its three sessions open a protected page, a data endpoint and the submission form (200 each)', $statuses === [200, 200, 200] && $sessionStored($oldId) === true, implode(', ', $statuses) . '; session stored: ' . ($sessionStored($oldId) === true ? 'yes' : 'no'));
$setActive(PX, 0);
$answer = req($open[0], 'GET', $account);
ok('the account is switched off: its next request to a protected page is sent to sign-in', $sentToSignIn($answer) === true, 'HTTP ' . $answer['status'] . ' ' . $answer['location']);
ok(
    '…and the session was ENDED, not just refused: its stored data is deleted and the browser was handed a new, empty session',
    $sessionStored($oldId) === false && $sessionId($open[0]) !== '' && $sessionId($open[0]) !== $oldId,
    'old session stored: ' . ($sessionStored($oldId) === true ? 'yes' : 'no') . '; the cookie ' . ($sessionId($open[0]) === $oldId ? 'did not change' : 'changed')
);
$answer = req($open[1], 'GET', ADDR_A . '/api/assets/list');
ok('its second session asks a data endpoint: 401 "Authentication required"', said($answer) === '401: Authentication required', said($answer));
$answer = req($open[2], 'GET', $submit);
ok('its third session opens the page that reads the session directly: treated as signed out (sent to sign-in)', $sentToSignIn($answer) === true, 'HTTP ' . $answer['status'] . ' ' . $answer['location']);
ok('each of the three endings is written to the activity log, once, against the account', $endedRows(PX) === 3, $endedRows(PX) . ' row(s)');

head('K2. Switching the account back on does not bring an old session back');
$setActive(PX, 1);
$answer = req($savedCookie, 'GET', $account);
ok('the cookie saved before the switch-off, used again after the account is back on: still signed out', $sentToSignIn($answer) === true, 'HTTP ' . $answer['status'] . ' ' . $answer['location']);
$answer = req($open[1], 'GET', $account);
ok('…and so is another of its old sessions', $sentToSignIn($answer) === true, 'HTTP ' . $answer['status'] . ' ' . $answer['location']);
// Signing in again, in the SAME browser whose session was ended: the new, empty session must carry a working form token.
$tok    = token(req($open[0], 'GET', ADDR_A . '/login')['body']);
$in     = req($open[0], 'POST', ADDR_A . '/login', ['csrf_token' => $tok, 'username' => 'selftest-access-px', 'password' => $password]);
$answer = req($open[0], 'GET', $account);
ok('the person signs in again in the same browser, and is in (the ended session left a working form token behind)', $in['status'] === 302 && str_contains($in['location'], 'login') === false && $answer['status'] === 200, 'sign-in: HTTP ' . $in['status'] . ' ' . $in['location'] . '; page: HTTP ' . $answer['status']);
req($open[0], 'GET', ADDR_A . '/logout');
$answer = req($open[0], 'GET', $account);
ok('control: signing out still works', $sentToSignIn($answer) === true, 'HTTP ' . $answer['status'] . ' ' . $answer['location']);
siteSetting(ORG_A, 'calendar.publicSubmit.allowAnonymous', 'true');

head('K3. Erased while signed in, through the real erasure pages');
$erased  = signIn('qx', ADDR_A);
$erasedId = $sessionId($erased);
post($erased, ADDR_A . '/account/delete', ADDR_A . '/account/delete/confirm', ['confirm_phrase' => 'DELETE MY ACCOUNT']);
$request = one('SELECT requestID, status FROM tblErasureRequest WHERE userID = ? ORDER BY requestID DESC LIMIT 1', 'i', [QX]);
$answer  = req($erased, 'GET', $account);
ok('the person asks for their account to be deleted on the real page: the request waits for an administrator, and they are still signed in', ($request['status'] ?? '') === 'pending_review' && $answer['status'] === 200, 'request: ' . ($request['status'] ?? 'none') . '; page: HTTP ' . $answer['status']);
post($jar['adm'], ADDR_A . '/admin/erasure-requests', ADDR_A . '/admin/erasure-requests/process', ['requestID' => (int) ($request['requestID'] ?? 0), 'action' => 'execute']);
$after = one(
    'SELECT (SELECT status FROM tblErasureRequest WHERE requestID = ?) AS status, u.isActive, (SELECT COUNT(*) FROM tblLocalAccounts l WHERE l.userID = u.userID) AS signIns FROM tblUsers u WHERE u.userID = ?',
    'ii', [(int) ($request['requestID'] ?? 0), QX]
);
ok('an administrator carries it out on the real page: the erasure completes, the account is switched off and nothing is left to sign in with', $after === ['status' => 'completed', 'isActive' => 0, 'signIns' => 0], json_encode($after));
$answer = req($erased, 'GET', $account);
ok('the erased person\'s next click is signed out, and their session\'s stored data is deleted', $sentToSignIn($answer) === true && $sessionStored($erasedId) === false, 'HTTP ' . $answer['status'] . ' ' . $answer['location'] . '; old session stored: ' . ($sessionStored($erasedId) === true ? 'yes' : 'no'));

head('K4. Maintenance mode: a switched-off global administrator no longer walks in; the upgrade page still works');
$global  = signIn('gx', ADDR_A);
$upgrade = ADDR_A . '/admin/upgrade';
$row     = one("SELECT settingID, settingValue FROM tblSettings WHERE settingKey = 'portal.maintenance.active' AND siteID IS NULL");
if ($row === null) {
    refuse('the portal-wide setting portal.maintenance.active is not in this database');
}
$state['settings']['portal.maintenance.active'] = $row; // the clean-up puts it back even if this part stops early
q("UPDATE tblSettings SET settingValue = '1' WHERE settingID = ?", 'i', [(int) $row['settingID']]);
$closed = [req('', 'GET', $account)['status'], req('', 'GET', ADDR_A . '/login')['status']];
ok('control, maintenance on: a signed-out visitor gets the holding page (503); the sign-in page still opens (200)', $closed === [503, 200], implode(', ', $closed));
$walksIn = [req($global, 'GET', $account)['status'], req($global, 'GET', $upgrade)['status']];
ok('control: a global administrator who is switched ON walks in (200), and the upgrade page opens for them (200)', $walksIn === [200, 200], implode(', ', $walksIn));
$setActive(GX, 0);
$answer = req($global, 'GET', $account);
ok('the global administrator is switched off: their open session now gets the holding page (503) like anybody else', $answer['status'] === 503, 'HTTP ' . $answer['status']);
$answer = req($global, 'GET', $upgrade);
ok('…and the upgrade address sends them to sign-in instead of opening', $sentToSignIn($answer) === true, 'HTTP ' . $answer['status'] . ' ' . $answer['location']);
q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [(string) $row['settingValue'], (int) $row['settingID']]);
$answer = req($jar['gadm'], 'GET', $upgrade);
ok('control, maintenance off again: the upgrade page opens for a global administrator', $answer['status'] === 200, 'HTTP ' . $answer['status']);

head('K5. What it costs, and what it must leave alone');
$logEmpty();
$statuses = [];
for ($i = 0; $i < 20; $i++) {
    $statuses[] = req($jar['mem'], 'GET', $account)['status'];
}
$cost = $sent();
ok('an active member opens a protected page 20 times: 200 every time, and exactly 20 look-ups (one for each signed-in request, never more)', array_unique($statuses) === [200] && $lookups() === 20, 'statuses: ' . implode(',', array_unique($statuses)) . '; look-ups: ' . $lookups());
// Not a check: the measured cost, printed so a later run can be compared with it.
echo '        for the record: ' . round($cost['commands'] / 20, 1) . ' statements for each of those requests in all, ' . round($lookups() / 20, 1) . ' of them the look-up ("' . str_replace('%', '<account number> LIMIT 1', LOOKUP_LIKE) . '").' . "\n";
$logEmpty();
$public = [req('', 'GET', ADDR_A . '/login'), req('', 'GET', $submit), req('', 'GET', ADDR_A . '/decision-card')];
$cost   = $sent();
ok(
    'a signed-out visitor opens three public pages: 200 each, NO look-up, and not one statement that names the accounts table',
    array_column($public, 'status') === [200, 200, 200] && $lookups() === 0 && $cost['namingAccounts'] === 0 && $cost['commands'] > 0,
    'statuses: ' . implode(',', array_column($public, 'status')) . '; look-ups: ' . $lookups() . '; statements naming tblUsers: ' . $cost['namingAccounts'] . ' of ' . $cost['commands']
);
ok('#507 is not disturbed: the signed-out sign-in page may be kept offline, a signed-in page is not marked', preg_match('/^X-Offline-Copy:\s*allow\s*$/mi', $public[0]['head']) === 1 && preg_match('/^X-Offline-Copy:/mi', req($jar['mem'], 'GET', $account)['head']) === 0);
$logEmpty();
$answer = req('', 'GET', ADDR_A . '/api/assets/list', null, $keyA);
ok('an API key is answered as before (200), with no look-up: a key is not a person and uses no session', $answer['status'] === 200 && $lookups() === 0, said($answer) . '; look-ups: ' . $lookups());
// Two-step sign-in.
$twoStep = $child . $sep . 'jar-tf.txt';
$tok     = token(req($twoStep, 'GET', ADDR_A . '/login')['body']);
$logEmpty();
$in      = req($twoStep, 'POST', ADDR_A . '/login', ['csrf_token' => $tok, 'username' => 'selftest-access-tf', 'password' => $password]);
$form    = req($twoStep, 'GET', ADDR_A . '/auth/2fa/verify');
ok('two-step sign-in: after the password the person waits at the code page, with no look-up made about them', $in['status'] === 302 && str_contains($in['location'], '2fa/verify') === true && $form['status'] === 200 && $lookups() === 0, 'sign-in: HTTP ' . $in['status'] . ' ' . $in['location'] . '; code page: HTTP ' . $form['status'] . '; look-ups: ' . $lookups());
$done   = req($twoStep, 'POST', ADDR_A . '/auth/2fa/verify', ['csrf_token' => token($form['body']), 'code' => $backupCode, 'use_backup' => '1']);
$answer = req($twoStep, 'GET', $account);
ok('…they give their code and are signed in (two-step sign-in still works)', $done['status'] === 302 && str_contains($done['location'], '2fa') === false && $answer['status'] === 200, 'code: HTTP ' . $done['status'] . ' ' . $done['location'] . '; page: HTTP ' . $answer['status']);
$twoStepOff = $child . $sep . 'jar-tf2.txt';
$tok        = token(req($twoStepOff, 'GET', ADDR_A . '/login')['body']);
req($twoStepOff, 'POST', ADDR_A . '/login', ['csrf_token' => $tok, 'username' => 'selftest-access-tf2', 'password' => $password]);
$form = req($twoStepOff, 'GET', ADDR_A . '/auth/2fa/verify');
$setActive(TF2, 0); // switched off in the minute between the password and the code
req($twoStepOff, 'POST', ADDR_A . '/auth/2fa/verify', ['csrf_token' => token($form['body']), 'code' => $backupCode, 'use_backup' => '1']);
$answer = req($twoStepOff, 'GET', $account);
ok('somebody switched off between their password and their code gets no further than their next request', $form['status'] === 200 && $sentToSignIn($answer) === true && $endedRows(TF2) === 1, 'code page: HTTP ' . $form['status'] . '; next page: HTTP ' . $answer['status'] . ' ' . $answer['location'] . '; endings logged: ' . $endedRows(TF2));
// The invitation page signs a NEW person in part way through a request.
$invited = $child . $sep . 'jar-invited.txt';
$page    = req($invited, 'GET', ADDR_A . '/auth/invite?token=' . $inviteToken);
$accept  = req($invited, 'POST', ADDR_A . '/auth/invite', ['csrf_token' => token($page['body']), 'token' => $inviteToken, 'fullName' => 'Selftest access Invited', 'username' => 'selftest-access-invited', 'password' => $password, 'passwordConfirm' => $password]);
$answer  = req($invited, 'GET', $account);
ok('the invitation page still signs a new person in: they accept, and their next page opens', $page['status'] === 200 && $accept['status'] === 302 && $answer['status'] === 200, 'invitation: HTTP ' . $page['status'] . '; accept: HTTP ' . $accept['status'] . '; page: HTTP ' . $answer['status']);

head('K6. Switched off, and the next request is not a plain GET');
// Every request K1 to K4 make from a switched-off account is a GET with no
// "Authorization" header. Two faults planted by the independent check passed
// all of them: the look-up made only for GET requests, and the look-up
// skipped whenever a request carried such a header. These checks are what
// fail then.
$poster  = signIn('py', ADDR_A);
$header  = signIn('py', ADDR_A);
$formTok = token(req($poster, 'GET', $record)['body']); // the form and its token, read while the account is still on
$warm    = req($header, 'GET', $account)['status'];
$setActive(PY, 0);
$fields  = $newSession('ACCESS-ATT switched-off poster') + ['csrf_token' => $formTok];
$answer  = req($poster, 'POST', $record . '/save', $fields);
ok(
    'a switched-off account\'s FIRST request is a POST, carrying a form token it read beforehand: sent to sign-in, and nothing is written',
    $formTok !== '' && $sentToSignIn($answer) === true && $sessionsNoted('ACCESS-ATT switched-off poster') === 0,
    'token read: ' . ($formTok !== '' ? 'yes' : 'no') . '; HTTP ' . $answer['status'] . ' ' . $answer['location'] . '; sessions written: ' . $sessionsNoted('ACCESS-ATT switched-off poster')
);
$answer = req($header, 'GET', $account, null, 'not-a-key');
ok(
    'its other session asks for a protected page with an "Authorization" header added to its cookie: sent to sign-in all the same',
    $warm === 200 && $sentToSignIn($answer) === true,
    'before the switch-off: HTTP ' . $warm . '; after: HTTP ' . $answer['status'] . ' ' . $answer['location']
);
ok('…and both endings are in the activity log', $endedRows(PY) === 2, $endedRows(PY) . ' row(s)');
// The log goes back to how it was straight away (the clean-up would do it too).
q('SET GLOBAL general_log = ' . (int) $state['queryLog']['general_log']);
q("SET GLOBAL log_output = '" . $state['queryLog']['log_output'] . "'");
q('TRUNCATE TABLE mysql.general_log');
$state['queryLog'] = null;

// -----------------------------------------------------------------------------
head('L. Expenses: the treasurer\'s own approved claim, and addresses that stay inside the organisation');
// -----------------------------------------------------------------------------
// The sentence, written out by hand (not read from ExpenseRules).
$ownPayPageNote = 'This is your own claim, so you cannot record its payment yourself. Another treasurer or an administrator must pay it.';
$view  = static fn (string $who, int $claimId): string => req($who, 'GET', ADDR_A . '/expenses/view?id=' . $claimId)['body'];
$page  = $view($jar['trs'], 916307);
ok('a treasurer opens their OWN approved claim: no "Go to Treasury" button, and one sentence saying who pays it', str_contains($page, 'ACCESS treasurer approved claim') === true && str_contains($page, 'Go to Treasury') === false && str_contains($page, $ownPayPageNote) === true);
$page = $view($jar['adm'], 916309);
ok('an administrator opening their own approved claim is told the same', str_contains($page, 'ACCESS admin approved claim') === true && str_contains($page, 'Go to Treasury') === false && str_contains($page, $ownPayPageNote) === true);
$page = $view($jar['mem'], 916308);
ok('an ordinary claimant, who never had the button, is shown neither the button nor the sentence', str_contains($page, 'ACCESS member approved claim') === true && str_contains($page, 'Go to Treasury') === false && str_contains($page, 'This is your own claim') === false);
$page = $view($jar['trs'], 916308);
ok('the treasurer on SOMEBODY ELSE\'S approved claim: the button, and no "your own claim" sentence', str_contains($page, 'ACCESS member approved claim') === true && str_contains($page, 'Go to Treasury') === true && str_contains($page, 'This is your own claim') === false);
ok('…and that button points inside A\'s own address', str_contains($page, 'href="' . ADDR_A . '/expenses/treasury"') === true);
// Organisations are told apart by the first part of the address in this run,
// so a bare "/expenses/…" would land on the DEFAULT organisation.
$answer = post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916305, 'decision' => 'Approved', 'comments' => 'ok']);
ok('approving a claim on A\'s address lands back on A\'s own approvals page', $answer['status'] === 302 && $answer['location'] === $approve, 'HTTP ' . $answer['status'] . ' Location: ' . $answer['location']);
$page = req($jar['appr'], 'GET', $approve)['body'];
ok('the approvals page lists a claim to decide, and posts its form inside A\'s own address', str_contains($page, 'ACCESS member second claim') === true && str_contains($page, 'action="' . $approve . '/save"') === true, 'the claim was ' . (str_contains($page, 'ACCESS member second claim') === true ? '' : 'NOT ') . 'listed; the form target was ' . (str_contains($page, 'action="' . $approve . '/save"') === true ? '' : 'NOT ') . $approve . '/save');
$page = req($jar['mem'], 'GET', ADDR_A . '/expenses/submit')['body'];
ok('the submit page posts its form inside A\'s own address', str_contains($page, 'action="' . ADDR_A . '/expenses/submit/save"') === true);
$answer = post($jar['trs'], $treasury, $treasury . '/save', ['claimID' => 916308, 'payRef' => ['BACS-L'], 'comments' => '']);
ok('recording a payment on A\'s address lands back on A\'s own treasury page', $answer['status'] === 302 && $answer['location'] === $treasury && claimState(916308) === 'Reimbursed, 0 approval(s), 1 payment(s)', 'HTTP ' . $answer['status'] . ' Location: ' . $answer['location'] . '; claim: ' . claimState(916308));
// (A check used to end this part: withdraw a claim, and look at the address
// the person was sent to. It passed for as long as the button had never
// worked (#587), because being sent back to the claim's page is also what
// happens when the withdrawal FAILS. Part M replaces it.)

// =============================================================================
head('M. #587 — withdrawing a claim through the real page, reading back what is STORED');
// =============================================================================
/** One claim for this part, in any state and either organisation. */
$mClaim = static function (int $id, int $by, string $title, string $status = 'Pending', int $site = ORG_A, int $dept = DEPT): void {
    q(
        'INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status) VALUES (?, ?, ?, ?, ?, CURDATE(), 12.50, ?)',
        'iiiiss', [$id, $site, $by, $dept, $title, $status]
    );
};
/** Everything stored about one claim: every column of its row, and every approval, payment, receipt and line item. Equal before and after = nothing changed. */
$storedAll = static fn (int $id): string => (string) json_encode([
    one('SELECT * FROM tblExpenseClaims WHERE claimID = ?', 'i', [$id]),
    q('SELECT * FROM tblExpenseClaimApprovals WHERE claimID = ? ORDER BY approvalID', 'i', [$id]),
    q('SELECT * FROM tblExpenseClaimPayments WHERE claimID = ? ORDER BY payID', 'i', [$id]),
    q('SELECT * FROM tblExpenseClaimFiles WHERE claimID = ? ORDER BY fileID', 'i', [$id]),
    q('SELECT * FROM tblExpenseClaimItems WHERE claimID = ? ORDER BY itemID', 'i', [$id]),
]);
/** The stored status, in quotes, so an EMPTY one (what a server without strict checking stored before migration 211) shows as ''. */
$storedStatus = static fn (int $id): string => var_export(one('SELECT status FROM tblExpenseClaims WHERE claimID = ?', 'i', [$id])['status'] ?? '(no such claim)', true);
/** The one-off messages a page shows, each as "type: words". Every Expenses page draws them with the same block. */
$alerts = static function (string $body): array {
    preg_match_all('#<div class="alert alert-(\w+) alert-dismissible fade show">\s*(.*?)\s*<button#s', $body, $found, PREG_SET_ORDER);

    return array_map(static fn (array $one): string => $one[1] . ': ' . html_entity_decode(trim($one[2]), ENT_QUOTES, 'UTF-8'), $found);
};
/**
 * The one-off message waiting for this person, read (and so cleared) from
 * the approvals page. Used to clear anything left over, and for what the
 * approver's and treasurer's own save pages say.
 */
$message = static fn (string $who): string => $alerts(req($who, 'GET', $approve)['body'])[0] ?? '(no message)';
/**
 * Press Withdraw as a browser would, then go where the portal sends the
 * person, as a browser does. Gives back where that was and what THAT PAGE
 * told them. The page is then loaded a second time: a one-off message must
 * not be there again, and if it is, the answer says so (and so no longer
 * matches what a check expects). Two messages on one page show as two.
 *
 * `$token`: null sends a fresh form token read from the person's own page;
 * '' sends none; anything else is sent exactly as given.
 *
 * (Until the fix round of 12 October 2026 this read the message from the
 * approvals page, because the claim's page and the submit page showed no
 * messages at all. So it proved the words were left, not that anybody was
 * shown them.)
 */
$withdraw = static function (string $who, int $id, ?string $token = null) use ($message, $alerts): string {
    $message($who); // read, and so clear, anything an earlier request left
    $to     = ADDR_A . '/expenses/withdraw/save';
    $answer = $token === null
        ? post($who, ADDR_A . '/expenses/submit', $to, ['claimID' => $id])
        : req($who, 'POST', $to, $token === '' ? ['claimID' => $id] : ['claimID' => $id, 'csrf_token' => $token]);
    if ($answer['location'] === '') {
        return 'HTTP ' . $answer['status'] . ', sent nowhere';
    }
    $shown = $alerts(req($who, 'GET', $answer['location'])['body']);
    $again = $alerts(req($who, 'GET', $answer['location'])['body']);

    return 'HTTP ' . $answer['status'] . ' to ' . $answer['location'] . ' — ' . ($shown === [] ? '(no message)' : implode(' | ', $shown))
        . ($again === [] ? '' : ' — AND AGAIN ON A RELOAD: ' . implode(' | ', $again));
};
/**
 * Leave a one-off message waiting for a signed-in person, by adding it to
 * their session file: the same two values a save page sets before it sends
 * them on. The session is found from the person's own cookie. False when it
 * could not be done, which the check using it reports.
 */
$leave = static function (string $who, string $words) use ($child, $sep): bool {
    foreach ((array) file($who, FILE_IGNORE_NEW_LINES) as $line) {
        $cells = explode("\t", (string) $line);
        $file  = $child . $sep . 'sessions' . $sep . 'sess_' . ($cells[6] ?? '');
        if (count($cells) === 7 && preg_match('/^[A-Za-z0-9,-]+$/', $cells[6]) === 1 && is_file($file) === true) {
            return file_put_contents($file, 'flash_msg|' . serialize($words) . 'flash_type|' . serialize('warning'), FILE_APPEND) !== false;
        }
    }

    return false;
};
/** The claim numbers a list page shows, in order. */
$listed = static function (string $who, string $address): array {
    preg_match_all('#</span>\#(\d+)#', req($who, 'GET', $address)['body'], $found);
    $ids = array_map('intval', $found[1]);
    sort($ids);

    return $ids;
};
/** The number on one of the dashboard's cards, or null when the card is not shown. */
$card = static function (string $who, string $label): ?int {
    $body = req($who, 'GET', ADDR_A . '/dashboard')['body'];

    return preg_match('#<div class="h4 mb-0 fw-bold">(\d+)</div>\s*<small class="text-muted">' . preg_quote($label, '#') . '</small>#', $body, $m) === 1 ? (int) $m[1] : null;
};
/** Admin → Reports, as the administrator sees it: the two totals, and the "by status" bars. */
$reports = static function () use ($jar): array {
    $body    = req($jar['adm'], 'GET', ADDR_A . '/admin/reports')['body'];
    $section = preg_match('#Expense Claims by Status</h5>(.*?)<!-- 📊 Top Activity Types#s', $body, $m) === 1 ? $m[1] : '';
    preg_match_all('#<span>([A-Za-z]*)</span>\s*<span>(\d+) \(\d+%\)</span>#', $section, $bars);

    return [
        'claims' => preg_match('#<div class="h3 mb-0 text-warning">([\d,]+)</div>\s*<small class="text-muted">Expense Claims</small>#', $body, $m) === 1 ? $m[1] : '(not shown)',
        'value'  => preg_match('#Total value: <strong>&pound;([\d,.]+)</strong>#', $section, $m) === 1 ? $m[1] : '(not shown)',
        'bars'   => array_combine($bars[1], array_map('intval', $bars[2])),
    ];
};
/** The claimant's own claims from the API, as claim number => status; `$filter` is the `status` filter, or '' for none. */
$api = static function (string $filter) use ($jar): array {
    $answer = req($jar['mem'], 'GET', ADDR_A . '/api/expenses/list?limit=100' . ($filter === '' ? '' : '&status=' . $filter));

    return ['http' => $answer['status'], 'claims' => array_column((array) (data($answer)['claims'] ?? []), 'status', 'claimID')];
};
/** One claim's status in the export an administrator downloads, or a word saying why there is none. */
$exported = static function (int $id) use ($jar, $approve): string {
    $answer = req($jar['adm'], 'GET', ADDR_A . '/expenses/api/export?csrf_token=' . token(req($jar['adm'], 'GET', $approve)['body']));
    foreach ((array) preg_split('/\R/', $answer['body']) as $line) {
        $cells = str_getcsv((string) $line, ',', '"', '');
        if ((string) ($cells[0] ?? '') === (string) $id) {
            return (string) end($cells);
        }
    }

    return $answer['status'] === 200 ? '(not in the export)' : 'HTTP ' . $answer['status'];
};
/** Everything a withdrawal should and should not move, read in one go. */
$picture = static fn (): array => [
    'approver'  => $listed($jar['appr'], $approve),
    'treasurer' => $listed($jar['trs'], $treasury),
    'admCard'   => $card($jar['adm'], 'Pending Claims'),
    'memCard'   => $card($jar['mem'], 'My Pending Claims'),
    'reports'   => $reports(),
    'api'       => $api(''),
    'apiW'      => $api('Withdrawn'),
    'apiP'      => $api('Pending'),
    'export'    => $exported(916311),
];

q("INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES (?, ?, 'Selftest access dept B', 'SADB', 1)", 'ii', [DEPT_B, ORG_B]);
$mClaim(916311, MEM, 'ACCESS-W claim to withdraw');
$mClaim(916312, MEM, 'ACCESS-W approved claim', 'Approved');
$mClaim(916313, MEM, 'ACCESS-W paid claim', 'Reimbursed');
$mClaim(916314, MEM, 'ACCESS-W rejected claim', 'Rejected');
$mClaim(916315, LEAD, 'ACCESS-W somebody else\'s claim');
$mClaim(916316, MEM, 'ACCESS-W own claim in organisation B', 'Pending', ORG_B, DEPT_B);
$mClaim(916317, MEM, 'ACCESS-W part-approved claim');
$mClaim(916318, MEM, 'ACCESS-W claim pressed with no form token');
$mClaim(916319, MEM, 'ACCESS-W claim pressed with a made-up form token');
$mClaim(916320, MEM, 'ACCESS-W claim pressed with another person\'s form token');
// The history each of those would really have, so "nothing changed" has something to lose.
q("INSERT INTO tblExpenseClaimApprovals (claimID, userID, decision, approverRole) VALUES (916312, ?, 'Approved', 'dept_lead'), (916313, ?, 'Approved', 'dept_lead'), (916314, ?, 'Rejected', 'dept_approver')", 'iii', [LEAD, LEAD, APPR]);
q("INSERT INTO tblExpenseClaimPayments (claimID, payReference, payAmount, paidByID) VALUES (916313, 'ACCESS-W-BACS', 12.50, ?)", 'i', [TRS]);
q("INSERT INTO tblExpenseClaimFiles (claimID, originalFilename, storedFilename) VALUES (916311, 'receipt.jpg', 'access-w-receipt.jpg')");
q("INSERT INTO tblExpenseClaimItems (claimID, itemName, unitCost, quantity, lineTotal) VALUES (916311, 'ACCESS-W item', 12.50, 1, 12.50)");
// "Last changed" two days back, so that a claim which was written to shows it.
q('UPDATE tblExpenseClaims SET updatedAt = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE claimID BETWEEN 916311 AND 916320');

// --- M1: the claimant withdraws a pending claim -------------------------------
$before  = $picture();
$rowWas  = (array) one('SELECT * FROM tblExpenseClaims WHERE claimID = 916311');
$kidsWas = [q('SELECT * FROM tblExpenseClaimFiles WHERE claimID = 916311'), q('SELECT * FROM tblExpenseClaimItems WHERE claimID = 916311')];
$said    = $withdraw($jar['mem'], 916311);
ok(
    'the claimant presses Withdraw on their own pending claim: sent to that claim\'s page inside A\'s own address, and THAT PAGE says "withdrawn successfully", once (a reload does not say it again)',
    $said === 'HTTP 302 to ' . ADDR_A . '/expenses/view?id=916311 — success: Claim #916311 has been withdrawn successfully.',
    $said
);
ok(
    '…and the STORED status reads back Withdrawn (#587: it used to stay Pending, or be stored EMPTY on a server without strict checking)',
    $storedStatus(916311) === "'Withdrawn'",
    'stored status: ' . $storedStatus(916311) . ($storedStatus(916311) === "''" ? ' — an EMPTY status, which the approver\'s list and the dashboard do not show' : '')
);
$rowNow = (array) one('SELECT * FROM tblExpenseClaims WHERE claimID = 916311');
ok(
    '…its "last changed" time moved, every other column of the claim is as it was, and its receipt and line item are still there',
    $rowNow['updatedAt'] !== $rowWas['updatedAt'] && array_diff_key($rowNow, ['status' => 1, 'updatedAt' => 1]) === array_diff_key($rowWas, ['status' => 1, 'updatedAt' => 1])
        && [q('SELECT * FROM tblExpenseClaimFiles WHERE claimID = 916311'), q('SELECT * FROM tblExpenseClaimItems WHERE claimID = 916311')] === $kidsWas && count($kidsWas[0]) === 1 && count($kidsWas[1]) === 1
);
$after = $picture();
$page  = $view($jar['mem'], 916311);
ok(
    'the claimant\'s page for it shows a grey "Withdrawn" badge and no Withdraw button',
    preg_match('#<span class="badge bg-secondary fs-5">\s*Withdrawn\s*</span>#', $page) === 1 && str_contains($page, 'Withdraw Claim') === false && str_contains($page, 'expenses/withdraw/save') === false
);
$all = $storedAll(916311);
$said = $withdraw($jar['mem'], 916311);
ok(
    'a SECOND press is refused, the claim\'s page says why (once), and not one thing stored about the claim changes (its "last changed" time included)',
    $said === 'HTTP 302 to ' . ADDR_A . '/expenses/view?id=916311 — warning: This claim cannot be withdrawn — it is no longer in Pending status.' && $storedAll(916311) === $all,
    $said . '; stored status: ' . $storedStatus(916311)
);

// --- M2: before and after that ONE withdrawal ---------------------------------
ok(
    'the approver\'s list held the claim before and does not now; every other claim on it is still there',
    array_values(array_diff($before['approver'], $after['approver'])) === [916311] && array_diff($after['approver'], $before['approver']) === [] && in_array(916317, $after['approver'], true) === true,
    'before: ' . json_encode($before['approver']) . ' after: ' . json_encode($after['approver'])
);
ok(
    'the treasurer\'s list is exactly as it was (it never held the claim), and still lists the approved one',
    $before['treasurer'] === $after['treasurer'] && in_array(916312, $after['treasurer'], true) === true && in_array(916311, $after['treasurer'], true) === false,
    'before: ' . json_encode($before['treasurer']) . ' after: ' . json_encode($after['treasurer'])
);
ok(
    'the dashboard: the administrator\'s "Pending Claims" and the claimant\'s "My Pending Claims" are each one fewer',
    is_int($before['admCard']) === true && $after['admCard'] === $before['admCard'] - 1 && is_int($before['memCard']) === true && $after['memCard'] === $before['memCard'] - 1,
    'administrator: ' . json_encode($before['admCard']) . ' then ' . json_encode($after['admCard']) . '; claimant: ' . json_encode($before['memCard']) . ' then ' . json_encode($after['memCard'])
);
$barsWas = $before['reports']['bars'];
$barsNow = $after['reports']['bars'];
ok(
    'Admin → Reports, "by status": Withdrawn has a bar of its own showing 1 (there was none), Pending is one fewer, and every other bar is as it was',
    isset($barsWas['Withdrawn']) === false && ($barsNow['Withdrawn'] ?? null) === 1 && ($barsNow['Pending'] ?? null) === ($barsWas['Pending'] ?? 0) - 1
        && array_diff_key($barsNow, ['Withdrawn' => 1, 'Pending' => 1]) === array_diff_key($barsWas, ['Pending' => 1]) && count($barsWas) >= 3,
    'before: ' . json_encode($barsWas) . ' after: ' . json_encode($barsNow)
);
// The owner's decision (Salem874, 12 October 2026): a withdrawn claim still
// counts in "Total claims" and "Total value", exactly as a rejected claim
// already does. Those two figures count every claim ever made.
ok(
    '…and "Expense Claims" and "Total value" are UNCHANGED: a withdrawn claim still counts in both, as a rejected one does (owner\'s decision, 12 October 2026)',
    $before['reports']['claims'] === $after['reports']['claims'] && $before['reports']['value'] === $after['reports']['value']
        && preg_match('/^[\d,]+$/', $after['reports']['claims']) === 1 && preg_match('/^[\d,]+\.\d\d$/', $after['reports']['value']) === 1 && (float) str_replace(',', '', $after['reports']['value']) >= 12.5,
    'claims: ' . $before['reports']['claims'] . ' then ' . $after['reports']['claims'] . '; value: ' . $before['reports']['value'] . ' then ' . $after['reports']['value']
);
ok(
    'the API list showed the claim as Pending and now shows it as Withdrawn',
    $before['api']['http'] === 200 && ($before['api']['claims'][916311] ?? null) === 'Pending' && $after['api']['http'] === 200 && ($after['api']['claims'][916311] ?? null) === 'Withdrawn',
    'before: HTTP ' . $before['api']['http'] . ' ' . json_encode($before['api']['claims'][916311] ?? null) . '; after: HTTP ' . $after['api']['http'] . ' ' . json_encode($after['api']['claims'][916311] ?? null)
);
ok(
    '…its filter accepts ?status=Withdrawn (200, where it used to answer 400): nothing before, exactly this claim after',
    $before['apiW'] === ['http' => 200, 'claims' => []] && $after['apiW'] === ['http' => 200, 'claims' => [916311 => 'Withdrawn']],
    'before: ' . json_encode($before['apiW']) . ' after: ' . json_encode($after['apiW'])
);
ok(
    '…and ?status=Pending listed the claim before and does not now',
    isset($before['apiP']['claims'][916311]) === true && isset($after['apiP']['claims'][916311]) === false && isset($after['apiP']['claims'][916317]) === true
);
ok('the export an administrator downloads said Pending for it and now says Withdrawn', $before['export'] === 'Pending' && $after['export'] === 'Withdrawn', 'before: ' . $before['export'] . ' after: ' . $after['export']);
$page = $view($jar['appr'], 916311);
ok('an approver who opens the withdrawn claim sees the Withdrawn badge and is offered no "Go to Approvals"', str_contains($page, 'ACCESS-W claim to withdraw') === true && preg_match('#<span class="badge bg-secondary fs-5">\s*Withdrawn\s*</span>#', $page) === 1 && str_contains($page, 'Go to Approvals') === false);
$page = $view($jar['trs'], 916311);
ok('a treasurer who opens it is offered no "Go to Treasury"', str_contains($page, 'ACCESS-W claim to withdraw') === true && str_contains($page, 'Go to Treasury') === false);

// --- M3: only while it is pending ----------------------------------------------
$notPending = 'HTTP 302 to ' . ADDR_A . '/expenses/view?id=%d — warning: This claim cannot be withdrawn — it is no longer in Pending status.';
foreach ([916312 => 'an APPROVED claim', 916313 => 'a PAID claim', 916314 => 'a REJECTED claim'] as $id => $what) {
    $all  = $storedAll($id);
    $said = $withdraw($jar['mem'], $id);
    ok(
        $what . ' is refused, and not one thing stored about it changes: status, "last changed" time, approvals and payments',
        $said === sprintf($notPending, $id) && $storedAll($id) === $all,
        $said . '; stored status: ' . $storedStatus($id)
    );
}

// --- M4: only the claimant, and only in their own organisation ------------------
$noSuchClaim = 'HTTP 302 to ' . ADDR_A . '/expenses/submit — danger: Claim not found.';
$said = $withdraw($jar['mem'], 916399);
ok('a claim number that matches no claim: sent back to the submit page, and THAT PAGE says "Claim not found", once (a reload does not say it again)', $said === $noSuchClaim, $said);
foreach (['mem' => 'an ordinary member', 'appr' => 'an approver of its department', 'adm' => 'an ADMINISTRATOR'] as $who => $what) {
    $all  = $storedAll(916315);
    $said = $withdraw($jar[$who], 916315);
    ok(
        'somebody else\'s pending claim, tried by ' . $what . ': answered EXACTLY as a claim that does not exist, and nothing stored changes',
        $said === $noSuchClaim && $storedAll(916315) === $all && $storedStatus(916315) === "'Pending'",
        $said . '; stored status: ' . $storedStatus(916315)
    );
}
$all  = $storedAll(916316);
$said = $withdraw($jar['mem'], 916316);
ok(
    'the person\'s OWN pending claim in organisation B, asked for on A\'s address: answered exactly as a claim that does not exist, and nothing stored changes',
    $said === $noSuchClaim && $storedAll(916316) === $all && $storedStatus(916316) === "'Pending'",
    $said . '; stored status: ' . $storedStatus(916316)
);

// --- M5: a claim one approver has already approved ------------------------------
post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916317, 'decision' => 'Approved', 'comments' => 'ok']);
ok('a claim one approver has approved is still Pending (its lead has not), with that approval on record', claimState(916317) === 'Pending, 1 approval(s), 0 payment(s)', claimState(916317));
$said = $withdraw($jar['mem'], 916317);
ok(
    '…its claimant may still withdraw it: Withdrawn, and the approval already given is KEPT, as that approver\'s',
    claimState(916317) === 'Withdrawn, 1 approval(s), 0 payment(s)' && (one('SELECT userID, decision FROM tblExpenseClaimApprovals WHERE claimID = 916317') ?? []) === ['userID' => APPR, 'decision' => 'Approved'],
    $said . '; claim: ' . claimState(916317)
);
$all = $storedAll(916317);
$message($jar['lead']);
post($jar['lead'], $approve, $approve . '/save', ['claimID' => 916317, 'decision' => 'Approved', 'comments' => 'late']);
$said = $message($jar['lead']);
ok('the lead it was waiting for can no longer APPROVE it: refused, nothing recorded', $storedAll(916317) === $all && $said === 'warning: Claim not found or not in Pending status.', $said . '; claim: ' . claimState(916317));
post($jar['lead'], $approve, $approve . '/save', ['claimID' => 916317, 'decision' => 'Rejected', 'comments' => 'late']);
ok('…nor REJECT it: nothing recorded', $storedAll(916317) === $all, claimState(916317));
$message($jar['trs']);
post($jar['trs'], $treasury, $treasury . '/save', ['claimID' => 916317, 'payRef' => ['ACCESS-W-LATE'], 'comments' => '']);
$said = $message($jar['trs']);
ok('…and a treasurer cannot record a payment for it: refused, nothing recorded', $storedAll(916317) === $all && str_contains($said, 'not in Approved status') === true, $said . '; claim: ' . claimState(916317));

// --- M6: the form token -------------------------------------------------------
// Until the fix round of 12 October 2026 every press in this part carried a
// fresh, valid token, so the page could lose its form-token test and this
// file still passed. Without that test any other website could make a
// signed-in claimant's browser withdraw their own pending claim, and a
// withdrawal cannot be undone.
$badToken   = 'HTTP 302 to ' . ADDR_A . '/expenses/view?id=%d — danger: Invalid CSRF token.';
$otherToken = token(req($jar['appr'], 'GET', $approve)['body']);
foreach ([
    916318 => ['with NO form token', ''],
    916319 => ['with a MADE-UP form token', bin2hex(random_bytes(32))],
    916320 => ['with ANOTHER signed-in person\'s form token', $otherToken],
] as $id => [$what, $sent]) {
    $all  = $storedAll($id);
    $said = $withdraw($jar['mem'], $id, $sent);
    ok(
        'the claimant presses Withdraw on their own pending claim ' . $what . ': refused, the claim\'s page says so (once), and the STORED status still reads Pending with nothing else changed',
        $said === sprintf($badToken, $id) && $storedStatus($id) === "'Pending'" && $storedAll($id) === $all && ($sent === '' || strlen($sent) === 64),
        $said . '; stored status: ' . $storedStatus($id) . '; token sent: ' . strlen($sent) . ' characters'
    );
}
$said = $withdraw($jar['mem'], 916318);
ok(
    'control: the first of those three claims, pressed with the person\'s OWN fresh token, IS withdrawn (so only the token refused it)',
    $said === 'HTTP 302 to ' . ADDR_A . '/expenses/view?id=916318 — success: Claim #916318 has been withdrawn successfully.' && $storedStatus(916318) === "'Withdrawn'",
    $said . '; stored status: ' . $storedStatus(916318)
);

// --- M7: a waiting message is written as text, on both pages people land on ----
// None of the withdraw page's own messages holds a character that matters in
// HTML, so these two leave one that does.
$nasty = '<b>ACCESS-W "left" & \'waiting\'</b>';
foreach ([ADDR_A . '/expenses/view?id=916311' => 'the claim\'s page', ADDR_A . '/expenses/submit' => 'the submit page'] as $address => $what) {
    $message($jar['mem']);
    $left = $leave($jar['mem'], $nasty);
    $body = req($jar['mem'], 'GET', $address)['body'];
    ok(
        $what . ' shows a waiting message with its <, >, &, " and \' written as text, never as markup',
        $left === true && $alerts($body) === ['warning: ' . $nasty] && str_contains($body, htmlspecialchars($nasty, ENT_QUOTES, 'UTF-8')) === true && str_contains($body, '<b>ACCESS-W') === false,
        $left === true ? 'the page showed: ' . json_encode($alerts($body)) . (str_contains($body, '<b>ACCESS-W') === true ? ' — and the raw <b> is in the page' : '') : 'the message could not be left in the session file'
    );
}

// --- M8: the line about the stored document --------------------------------------
// A document is made when a claim is submitted, approved, rejected or paid,
// and NOT when it is withdrawn. Rows as ExpensePdf::generate() writes them:
// a withdrawn claim has the one made at submission; an approved claim has
// two; and the paid claim is given a document with no row at all.
q("UPDATE tblExpenseClaims SET fileName = CONCAT('claim_', claimID, '.pdf') WHERE claimID IN (916311, 916312, 916313)");
q(
    "INSERT INTO tblExpenseClaimFiles (claimID, originalFilename, storedFilename, fileType, stage) VALUES "
    . "(916311, 'Claim_916311_Pending.pdf', 'claim_916311.pdf', 'application/pdf', 'Pending'), "
    . "(916312, 'Claim_916312_Pending.pdf', 'claim_916312.pdf', 'application/pdf', 'Pending'), "
    . "(916312, 'Claim_916312_Approved.pdf', 'claim_916312.pdf', 'application/pdf', 'Approved')"
);
$page = $view($jar['mem'], 916311);
ok(
    'a WITHDRAWN claim\'s page says its stored document was last generated for status Pending and was not remade, and nowhere says it was generated for Withdrawn',
    str_contains($page, '(Last generated for status: Pending. It was not remade when the claim was withdrawn.)') === true && str_contains($page, 'Last generated for status: Withdrawn') === false,
    preg_match('#\(Last generated for status:[^)]*\)#', $page, $m) === 1 ? 'the page says: ' . $m[0] : 'the page has no such line'
);
$pageApproved = $view($jar['mem'], 916312);
$pagePaid     = $view($jar['mem'], 916313);
ok(
    '…an approved claim\'s page names the NEWEST document\'s stage (Approved, not the earlier Pending), and a document with no recorded stage gets no such line at all',
    str_contains($pageApproved, '(Last generated for status: Approved)') === true && str_contains($pagePaid, 'claim_916313.pdf') === true && str_contains($pagePaid, 'Last generated for status') === false,
    (preg_match('#\(Last generated for status:[^)]*\)#', $pageApproved, $m) === 1 ? 'approved claim: ' . $m[0] : 'approved claim: no such line')
        . (preg_match('#\(Last generated for status:[^)]*\)#', $pagePaid, $m) === 1 ? '; paid claim: ' . $m[0] : '; paid claim: no such line')
);

// --- M9: the e-mail to approvers fails AFTER the withdrawal is stored ---------------
// The scratch copy's e-mail step throws while the marker file exists (see
// where the copy is made). By then the claim is withdrawn, so that is what
// the person must read; and the failure must be on record for the
// administrator, not lost.
$mailMarker = dirname($mailerFile) . $sep . MAIL_FAILS_FILE;
file_put_contents($mailMarker, '1');
$said = $withdraw($jar['mem'], 916319);
unlink($mailMarker);
ok(
    'the e-mail step fails after the withdrawal is stored: the claim IS stored Withdrawn, and the claim\'s page says "withdrawn successfully", not "Please try again"',
    $storedStatus(916319) === "'Withdrawn'" && $said === 'HTTP 302 to ' . ADDR_A . '/expenses/view?id=916319 — success: Claim #916319 has been withdrawn successfully.',
    $said . '; stored status: ' . $storedStatus(916319)
);
$filed = (int) (one('SELECT COUNT(*) AS n FROM tblErrors WHERE siteID = ? AND errorTitle = ?', 'is', [ORG_A, MAIL_FAILS_MARK])['n'] ?? 0);
ok('…and that failure is on record for the administrator: exactly one line, filed against this organisation (so the step really did fail)', $filed === 1, $filed . ' line(s) found');

// =============================================================================
head('Nothing went wrong behind the pages, and everything is put back');
// =============================================================================
$log = (string) @file_get_contents($child . $sep . 'php-errors.log');
$bad = array_values(array_filter((array) preg_split('/\R/', $log), static fn (string $line): bool => preg_match('/PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught/', $line) === 1));
ok('the server\'s PHP error log holds no warning, notice or fatal error', $bad === [], count($bad) . ' found: ' . implode(' | ', array_map(static fn (string $line): string => mb_substr($line, 0, 220), array_slice($bad, 0, 4))));

$before = $state['settings'];
cleanup();
$left = (int) (one(
    'SELECT (SELECT COUNT(*) FROM tblSites WHERE siteID IN (?, ?)) + (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 916000 AND 916999) '
    . "+ (SELECT COUNT(*) FROM tblExpenseClaims WHERE claimID BETWEEN 916000 AND 916999) + (SELECT COUNT(*) FROM tblSettings WHERE settingKey LIKE '%zz-selftest%') "
    . '+ (SELECT COUNT(*) FROM tblEvents WHERE eventID BETWEEN 916000 AND 916999) + (SELECT COUNT(*) FROM tblVenues WHERE venueID BETWEEN 916000 AND 916999) '
    . '+ (SELECT COUNT(*) FROM tblKidProfiles WHERE childID BETWEEN 916000 AND 916999) + (SELECT COUNT(*) FROM tblAttendanceServiceTypes WHERE serviceTypeID BETWEEN 916000 AND 916999) '
    . "+ (SELECT COUNT(*) FROM tblSalvationCards WHERE fullName LIKE 'ACCESS card%') + (SELECT COUNT(*) FROM tblLiveChatMessages WHERE displayName LIKE 'ACCESS%' OR body LIKE 'ACCESS-CHAT%') AS n",
    'ii', [ORG_A, ORG_B]
)['n'] ?? -1);
$settingsBack = true;
foreach ($before as $key => $row) {
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
