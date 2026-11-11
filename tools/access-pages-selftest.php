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
 * #529 (10 November 2026, D-106) added part Q: who sees an organisation's
 * attendance totals — the reports page, its spreadsheet and the cards on
 * /attendance — for somebody signed out, an ordinary member, a member holding
 * another role, two accounts of another organisation (at the door and with the
 * door stood down), an account in no organisation, the holder of the role the
 * permission ships with, an administrator and a global administrator; every
 * refusal compared with a made-up address; and the permission re-pointed on
 * the real Roles page and put back.
 *
 * Roll-out package H1 (12 October 2026, #587) added part M:
 *  L2. #675 — the Expenses spreadsheet. The Export button on the
 *      approvals page is offered only to people the download serves, and
 *      the handler still refuses everybody else on its own; an
 *      administrator really gets a CSV, of their OWN organisation's claims;
 *      the download does not spend the approvals form's token (#634); a
 *      token that is no good lands on a real page rather than a 404; the
 *      `approver` role opens the button and the file together, which is how
 *      the two are shown to be reading one rule; and the ApiRouter address
 *      for the same file stays shut.
 *  L3. #648 (D-103) — the Approver role sees only its own departments'
 *      claims. An approver of one department holding the role opens and
 *      downloads that department's claims and not another's; a claim they
 *      may not open is answered byte for byte as a made-up number is; the
 *      role with no department opens nothing and downloads the heading row;
 *      administrators, a global administrator and the treasurer see both
 *      claims and download both; the approvals list follows the DECIDING
 *      reach; and the claimant always opens their own.
 *  L4. #867 — a receipt opens for exactly the people who may open its claim:
 *      the claimant, an approver of the department, a treasurer and both
 *      kinds of administrator get the file's own bytes with its stored type,
 *      as an attachment, with nosniff, no-store and a sandboxing page rule;
 *      everybody else gets byte for byte what a made-up receipt gets, after
 *      the same database work; a receipt of another claim, the claim's own
 *      generated PDF, stored names that leave the folder (../, an absolute
 *      path, a link) and numbers that are not plain numbers are refused; a
 *      label that could break the header gives one well-formed RFC 6266 /
 *      5987 header; and the claim page, the approvals list and the treasury
 *      list link to where the receipts are. Round 2 of its check added: the
 *      OTHER organisation's administrator and treasurer, through THEIR OWN
 *      address, are refused this organisation's receipt and claim page (only
 *      the organisation condition can refuse them there); and D-130 — opening
 *      a claim page, a receipt, the spreadsheet or the API's claim list
 *      writes exactly one activity record with the person and the claim (or
 *      what was exported), while a refusal leaves the same trace as a
 *      made-up number: none. Round 3 tries the other organisation's people on
 *      a claim of EVERY status the column allows, pins the API list's scope
 *      words (an administrator's ?all=true, an API key, a member's own), the
 *      claimant's own looks and the spreadsheet's form-token refusal, and
 *      covers the rest of D-130: the approvals, treasury and own-claims lists,
 *      the change form, and the report builder's preview, run and export.
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
 * #514 part 9 (13 October 2026) added part N:
 *   N. The dashboard's card for events copied in from an outside calendar
 *      that are waiting for approval, read from the REAL dashboard page with
 *      organisations told apart by the first part of the address: an
 *      administrator of the organisation is shown the card, its number and a
 *      link that keeps the organisation's part of the address; a member's
 *      page holds no trace of it (a number would tell them that hidden
 *      events exist); another organisation's administrator is shown nothing
 *      about this one; and the card goes when nothing is waiting, a paused
 *      calendar's event not counting. Also, through the real pages: the
 *      copy of "my data" hands an administrator the record of when they
 *      were told, and a member none; and pressing Save on the notification
 *      preferences page, while push is not set up and its switches are
 *      therefore not shown, switches NONE of the three push choices off
 *      (the new one, and the two older ones, which that Save used to switch
 *      off: the independent check of part 9 ran it, 13 October 2026). Who
 *      is E-MAILED or PUSHED, and when, is
 *      `tools/import-notifier-selftest.php`'s subject.
 *
 * #834 (7 November 2026) added part P:
 *   P. A member's own claims, listed on the Expenses page: their own claims
 *      in THIS organisation, newest submitted first, ten to a page; never
 *      another member's, nor their own in another organisation they belong
 *      to; each linking to the claim's own page inside the organisation's
 *      address; a plain sentence for somebody with none; a page number that
 *      is not a positive whole number shows page 1; and no error row.
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
 *   numbers part M reads and the one card part N reads. (This line used to
 *   say "beyond the two expense numbers" only; part N is what changed it.)
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
 * @version   1.5.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/478
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/545
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/570
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/548
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/587
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 521; // 509 until #867's round 4 added twelve (each kind of list, spreadsheet, API answer and preview showing exactly ONE claim and showing NONE, and the change form of a claim that can no longer be changed); 488 until #867's round 3 added twenty-one (the other organisation's people tried on a claim in EVERY status — ten in place of four — the API list's scope words, the claimant's own looks, the spreadsheet's second refusal, and D-130's lists, change form and reports); 474 until #867's round 2 added fourteen (the other organisation's administrator and treasurer at their own address, a label in another script, and D-130's activity records); 433 until #867 added part L4 (forty-one: who may open a receipt, the refusals compared with a made-up one, the database work, a receipt of another claim, the generated PDF, five stored-path and eight request tricks, the header, and the links); 427 until #529 round 3 added six (a second delete, the list's count, a page delete by an administrator, and an API key both ways); 416 until #529 round 2 added D-114's eight and three coverage pins; 408 until #529 round 1 added D-109's five and a flagged outsider's three; 369 until #529 added part Q (thirty-nine); 361 until #648's round 1 added eight more to L3 (the rest of the existence-leak class); 348 until #648 added part L3 (fifteen) and took two treasurer/lead refusals out of L2 (D-040); 334 until #792 added the api/translation row (two checks); 336 until #834 added part P (twelve)

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

/**
 * 🔍 A PATTERN SEARCH THAT CANNOT BE MISTAKEN FOR "NOT THERE" (#829).
 *
 * `preg_match()` and `preg_match_all()` answer `false` when the search could
 * not RUN at all — PCRE's JIT stack ran out, or the backtracking limit was
 * reached — and every search in this file was read with `=== 1`, `!== 1`,
 * `> 0` or by looking at the matches it filled in. So a search that never ran
 * gave exactly the answer of a search that found nothing, and a check asking
 * for ABSENCE ("the page does NOT show…") passed on a page it never read.
 * That is not hypothetical: `tools/db-strict-mode-selftest.php` hit the JIT
 * limit on the admin dashboard once that page grew past roughly 20-30 KB, and
 * its "shows NO alert" check went on passing whatever the page drew (#792).
 *
 * These two answer EXACTLY what PHP's functions answer whenever the search
 * runs, so no check here asks anything different. When it cannot run they
 * record a RED check naming the pattern, how much text it was searching and
 * PHP's own reason, and then THROW, so no caller is ever handed an answer it
 * could read as "absent" or as "present". The red line comes first on
 * purpose: a bare uncaught exception is a crash with a label, not a check
 * that reports.
 *
 * Deliberately NOT used inside the router this file writes out for its test
 * web server (that code runs in another process, where these functions do
 * not exist, and it searches only a short request path).
 *
 * @param mixed $found Filled in exactly as `preg_match()` fills its third argument.
 */
function ap_find(string $pattern, string $subject, mixed &$found = null, int $flags = 0, int $offset = 0): int
{
    $answer = preg_match($pattern, $subject, $found, $flags, $offset);
    if ($answer === false) {
        ap_searchCouldNotRun($pattern, $subject);
    }

    return $answer;
}

/**
 * The same, for `preg_match_all()`: the number of matches when the search
 * ran, and a red check plus a thrown error when it could not.
 *
 * @param mixed $found Filled in exactly as `preg_match_all()` fills its third argument.
 */
function ap_findAll(string $pattern, string $subject, mixed &$found = null, int $flags = 0, int $offset = 0): int
{
    $answer = preg_match_all($pattern, $subject, $found, $flags, $offset);
    if ($answer === false) {
        ap_searchCouldNotRun($pattern, $subject);
    }

    return $answer;
}

/**
 * The same again for `preg_replace()` and `preg_split()`, because they are
 * the same fault in another form (#829): `preg_replace()` answers `null` when
 * it cannot run and `preg_split()` answers `false`, and this file turned both
 * into "nothing" with `(string)`, `(array)` or `?: []`. Text that has been
 * tidied to an EMPTY string contains nothing, so a check that then asks the
 * tidied text for ABSENCE ("the statement does not select `moderatorID`")
 * passed without ever having read it.
 */
function ap_replace(string|array $pattern, string|array $replacement, string $subject, int $limit = -1): string
{
    $answer = preg_replace($pattern, $replacement, $subject, $limit);
    if ($answer === null) {
        ap_searchCouldNotRun(is_array($pattern) === true ? implode(' ', $pattern) : $pattern, $subject);
    }

    return $answer;
}

/** `preg_split()`, with the same refusal to read "could not run" as "nothing". @return list<string> */
function ap_split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array
{
    $answer = preg_split($pattern, $subject, $limit, $flags);
    if ($answer === false) {
        ap_searchCouldNotRun($pattern, $subject);
    }

    return $answer;
}

/** Record the red check, then stop the caller reading anything into the failure. */
function ap_searchCouldNotRun(string $pattern, string $subject): never
{
    $why = preg_last_error_msg();
    ok('a pattern search COULD NOT RUN, so its check was not answered (#829)', false, 'pattern ' . $pattern . ' over ' . strlen($subject) . ' bytes: ' . $why . ' — this is NOT "not found"');
    throw new RuntimeException('A pattern search could not run (' . $why . '), so the check that asked it has no answer: ' . $pattern);
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
/**
 * The statement Auth::ensureSession() makes for a signed-in request, as the database's query log shows it.
 * Since #598 (D-104) it also reads `signInVersion`, in the SAME statement, so the count of look-ups
 * per request is unchanged: still exactly one.
 */
const LOOKUP_LIKE = 'SELECT `isActive`, `signInVersion` FROM `tblUsers` WHERE `userID` = %';
const EV_INT   = 916401; // A: internal, published
const EV_PUB   = 916402; // A: public, published
const EV_DRAFT = 916403; // A: a draft, marked public on purpose so only the draft rule refuses it
const EV_B     = 916404; // B: public, published, so on A's address only "wrong organisation" refuses it
const EV_GONE     = 916405; // A: public and published, but DELETED
const EV_IMPORTED = 916406; // A: public and published, but copied in from an outside calendar
const EV_NONE  = 916499; // matches no event
// Part N (#514 part 9): two outside calendars of A, three events copied in
// from them, and an approval row for each (waiting, at first).
const FEED_N_ON     = 916951; // A: an outside calendar, switched on
const FEED_N_PAUSED = 916952; // A: an outside calendar, PAUSED
const EV_N1 = 916451;         // from the switched-on calendar
const EV_N2 = 916452;         // from the switched-on calendar
const EV_N3 = 916453;         // from the paused calendar
const APPR_N1 = 916461;
const APPR_N2 = 916462;
const APPR_N3 = 916463;
/** In the title of every part N event, so "is any of it in this page?" is one search. */
const IMPORT_MARK = 'ACCESS-WAITING-EVENT';
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
    // Part N: the record of who was told, then the outside calendars. Their
    // approval rows go with them (the link cascades), and with the events
    // removed on the line after.
    q('DELETE FROM tblExternalApprovalNotices WHERE siteID IN (?, ?) OR userID BETWEEN 916000 AND 916999', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblExternalFeeds WHERE siteID IN (?, ?) OR feedID BETWEEN 916000 AND 916999', 'ii', [ORG_A, ORG_B]);
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
        "INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status, `currency`) VALUES (?, ?, ?, ?, ?, CURDATE(), 12.50, 'Pending', 'EUR')",
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
    if (ap_find('/^X-Access-Pages-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => ap_find('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
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
    if (ap_find('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return ap_find('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
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
    return ap_find('#<h1[^>]*>(.*?)</h1>#s', $answer['body'], $h) === 1 ? trim(strip_tags($h[1])) : '(no heading)';
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
        'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
        . "VALUES (?, ?, ?, ?, 'ACCESS description', ?, ?, 'UTC', ?, ?, 0)",
        'iisssssi', [$id, $site, 'access-' . $kind, 'ACCESS ' . $kind . ' event', $eventDay . ' 10:00:00', $eventDay . ' 11:00:00', $status, $public]
    );
}
// J: two more events of A whose number a check-in or a decision card must NOT keep: one deleted, and one
//    copied in from an outside calendar (the calendar's own row is not needed: nothing links the two tables).
q(
    'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
    . "VALUES (?, ?, 'access-deleted', 'ACCESS deleted event', 'ACCESS description', ?, ?, 'UTC', 'published', 1, 1)",
    'iiss', [EV_GONE, ORG_A, $eventDay . ' 10:00:00', $eventDay . ' 11:00:00']
);
q(
    'INSERT INTO tblEvents (eventID, siteID, externalFeedID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
    . "VALUES (?, ?, 916901, 'access-imported', 'ACCESS imported event', 'ACCESS description', ?, ?, 'UTC', 'published', 1, 0)",
    'iiss', [EV_IMPORTED, ORG_A, $eventDay . ' 10:00:00', $eventDay . ' 11:00:00']
);
// F: one pending registration on A's internal event, one on B's event.
q("INSERT INTO tblEventRegistrations (registrationID, eventID, fullName, status) VALUES (916501, ?, 'ACCESS registration A', 'pending'), (916502, ?, 'ACCESS registration B', 'pending')", 'ii', [EV_INT, EV_B]);
// G: a service type of A and two recorded sessions of it.
q("INSERT INTO tblAttendanceServiceTypes (serviceTypeID, siteID, typeName, typeSlug, isActive) VALUES (916601, ?, 'ACCESS service', 'access-service', 1)", 'i', [ORG_A]);
q("INSERT INTO tblAttendanceSessions (sessionID, siteID, serviceTypeID, sessionDate, notes) VALUES (916611, ?, 916601, ?, 'ACCESS-ATT seeded one'), (916612, ?, 916601, ?, 'ACCESS-ATT seeded two')", 'isis', [ORG_A, $today, ORG_A, $today]);
// H: A's hall, with one booking today. Every text carries VENUE_MARK.
// 🕰️ The zone is named because tblVenues.timezone has no default since #782 (migration 273); London is
//    the value this row always got from the old default, so nothing this test sees changes.
q("INSERT INTO tblVenues (venueID, siteID, venueName, countryCode, timezone, isActive) VALUES (?, ?, ?, 'GB', ?, 1)", 'iiss', [VENUE, ORG_A, VENUE_MARK . ' hall', 'Europe/London']);
// 💷 Organisation A keeps its money in EUROS (#966): no currency is built in any
//    more, and P4 and the reports total below prove its pages say so and never
//    print a pound sign. Organisation B has none chosen.
q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, \'org.currency\', \'EUR\', \'\', 0)', 'i', [ORG_A]);
q('INSERT INTO tblVenueUsageTypes (usageTypeID, siteID, venueID, typeName) VALUES (916721, ?, ?, ?)', 'iis', [ORG_A, VENUE, VENUE_MARK . ' use']);
q('INSERT INTO tblVenueStatuses (statusID, siteID, statusName) VALUES (916731, ?, ?)', 'is', [ORG_A, VENUE_MARK . ' status']);
q("INSERT INTO tblVenueBookings (bookingID, siteID, venueID, bookingDate, usageTypeID, statusID, startTime, endTime, notes, `currency`) VALUES (?, ?, ?, ?, 916721, 916731, '09:00:00', '12:00:00', ?, 'EUR')", 'iiiss', [BOOKING, ORG_A, VENUE, $today, VENUE_MARK . ' note']);
q("INSERT INTO tblVenueBookings (bookingID, siteID, venueID, bookingDate, usageTypeID, statusID, startTime, endTime, notes, `currency`) VALUES (?, ?, ?, ?, 916721, 916731, '09:00:00', '12:00:00', ?, 'EUR')", 'iiiss', [BOOKING_LATER, ORG_A, VENUE, $eventDay, VENUE_MARK . ' note']);
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
    // D-075 (#792): translation follows its own app's switch. Its one endpoint
    // takes POST only, so a GET here is answered "Method not allowed" (405)
    // while the app is ON — which is still "not refused as switched off" —
    // and nothing is ever translated or charged by this table.
    'translation'     => ['translation', 'content'],
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
ok('the table below has a row for each of the 20 api folders on disk, and no other', (static function () use ($folders, $webSrc): bool {
    $onDisk = array_map(static fn (string $dir): string => basename(dirname($dir)), (array) glob($webSrc . '/_apps/*/api', GLOB_ONLYDIR));
    sort($onDisk);
    $rows = array_keys($folders);
    sort($rows);

    return $onDisk === $rows && count($rows) === 20;
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
ok('somebody with no authority at all is still refused, with the sentence that names who can decide (#648), and nothing is recorded', $answer['status'] === 302 && claimState(916305) === $untouched && str_contains(req($jar['mem'], 'GET', $approve)['body'], 'Access denied — only an administrator, or a lead or approver of a department, can decide expense claims.') === true, claimState(916305));

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
// D-114 (#529 round 2): a member deletes only a session THEY recorded, so the
// control deletes the one just recorded. Until then this deleted 916611, a
// seeded session with no recorder, which only an administrator or a holder of
// the attendance permission may now delete (part Q).
$ownSession = (int) (one('SELECT sessionID FROM tblAttendanceSessions WHERE siteID = ? AND notes = ?', 'is', [ORG_A, 'ACCESS-ATT member'])['sessionID'] ?? 0);
$answer     = post($jar['mem'], $record, $record . '/delete', ['sessionID' => $ownSession]);
ok('control: …and deletes the session they recorded', $answer['status'] === 302 && $ownSession > 0 && $deleted($ownSession) === 1, 'HTTP ' . $answer['status'] . '; isDeleted: ' . ($ownSession > 0 ? $deleted($ownSession) : 'no such session'));
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
$samePage = static fn (array $answer): string => $answer['status'] . ' ' . (string) ap_replace(['/[0-9a-f]{32,}/', '/[A-Za-z0-9+\/]{20,}={0,2}/', '/9167\d\d/'], ['#', '#', 'N'], $answer['body']);
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
if ($logBefore === null || in_array((int) $logBefore['general_log'], [0, 1], true) === false || ap_find('/^[A-Z,]+$/', (string) $logBefore['log_output']) !== 1) {
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
ok('#507 is not disturbed: the signed-out sign-in page may be kept offline, a signed-in page is not marked', ap_find('/^X-Offline-Copy:\s*allow\s*$/mi', $public[0]['head']) === 1 && ap_find('/^X-Offline-Copy:/mi', req($jar['mem'], 'GET', $account)['head']) === 0);
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
head('L2. #675 — the Expenses spreadsheet: the button is offered only to people it serves');
// =============================================================================
/**
 * WHY THIS PART EXISTS, because the issue that led to it was wrong three times
 * over and the corrections are the useful part.
 *
 * #675 said `expenses/export` was a handler that had "never answered a
 * request", that "nothing links to it", and that four `api.expenses.*` switches
 * were seeded with no handler behind them. Measured on 30 October 2026, all
 * three were false:
 *
 *   * it answers 200 with a real CSV;
 *   * a live Export button on this very page links to it;
 *   * migration 186 deletes those four switches and the install script folds
 *     that DELETE in, so no installation has held them for a long time.
 *
 * What the measuring DID find was worse than the issue's version, and it is
 * what this part guards. The button was drawn unconditionally. This page has no
 * gate beyond being signed in — it simply shows an empty list to somebody with
 * nothing to decide — so every member saw the button, and the handler then
 * refused most of them with a bare, unstyled `Forbidden`: no portal page, no
 * message, no way back. Four of the six kinds of account tested were refused
 * that way, and they included the department leads and department approvers
 * this page exists to serve.
 *
 * The rule now lives in ONE place, `ExpenseRules::mayExportClaims()`, which the
 * button and the handler both ask. So the two halves worth checking are: the
 * button is offered to exactly the people the download serves, and the handler
 * still refuses everybody else on its own — because a gate that is only a
 * hidden button is not a gate at all.
 *
 * It also pins the two things most likely to be "tidied" into a fault later:
 * the download must NOT spend the form token (#634), and the ApiRouter address
 * must stay shut.
 */
$exportAddress = ADDR_A . '/expenses/api/export';

/** The approvals page, and the token it is carrying right now. */
$approvalsNow = static function (string $who) use (&$jar, $approve): array {
    $page = req($jar[$who], 'GET', $approve);

    return ['html' => $page['body'], 'token' => token($page['body'])];
};

/** Is the Export button drawn on this copy of the approvals page? */
$buttonDrawn = static fn (string $html): bool
    => ap_find('#href="[^"]*/expenses/api/export[^"]*"#i', $html) === 1;

// --- who is offered the button -----------------------------------------------
// An administrator, a global administrator and — since D-040, built with #648 —
// a treasurer may export; the three below may not. The handler's rule is "an
// administrator, a treasurer, or the `approver` ROLE", and none of these three
// hold either role: APPR has `tblUserDepts.isApprover`, which is a different
// thing with a confusingly similar name, and LEAD is a department lead (LEAD is
// ALSO a treasurer in this world, so it is offered the button too).
// (Until #648 TRS was listed here as refused: D-040 was decided on 1 November
// 2026 and not built.)
foreach (['adm' => true, 'gadm' => true, 'trs' => true, 'lead' => true, 'appr' => false, 'mem' => false] as $who => $mayExport) {
    $page = $approvalsNow($who);
    ok(
        sprintf('the Export button is %s for "%s"', $mayExport === true ? 'SHOWN' : 'not shown', $who),
        $buttonDrawn($page['html']) === $mayExport,
        'button ' . ($buttonDrawn($page['html']) === true ? 'was' : 'was NOT') . ' drawn'
    );
}

// --- the handler refuses on its own, button or no button ---------------------
// This is the half a hidden button cannot provide. Each of the two asks for
// the address directly, carrying a perfectly good form token.
foreach (['appr', 'mem'] as $who) {
    $page   = $approvalsNow($who);
    $answer = req($jar[$who], 'GET', $exportAddress . '?csrf_token=' . urlencode($page['token']));
    ok(
        sprintf('"%s" asking for the address directly, with a valid token, is still refused 403', $who),
        $answer['status'] === 403,
        'HTTP ' . $answer['status']
    );
}

// --- what an administrator actually gets ------------------------------------
$page = $approvalsNow('adm');
$csv  = req($jar['adm'], 'GET', $exportAddress . '?csrf_token=' . urlencode($page['token']));
$type = ap_find('/^Content-Type:\s*(.+)$/mi', $csv['head'], $m) === 1 ? strtolower(trim($m[1])) : '';
ok('an administrator gets HTTP 200 and a CSV file, not a web page', $csv['status'] === 200 && str_contains($type, 'text/csv') === true, 'HTTP ' . $csv['status'] . ' type: ' . ($type === '' ? 'none' : $type));
ok('…sent as a download, with a dated file name', ap_find('/^Content-Disposition:[^\n]*attachment[^\n]*expenses-export-\d{4}-\d{2}-\d{2}\.csv/mi', $csv['head']) === 1, 'Content-Disposition: ' . (ap_find('/^Content-Disposition:\s*(.+)$/mi', $csv['head'], $d) === 1 ? trim($d[1]) : 'none'));
// The heading row is checked by name and in order. A reordering is a silent
// break for anybody whose spreadsheet or script reads it by position.
$firstLine = trim((string) strtok($csv['body'], "\n"));
ok('…the heading row names its columns, in order', str_ends_with($firstLine, 'claimID,Claimant,Department,Title,Date,Amount,Currency,Status') === true, 'first line: ' . $firstLine);
ok('…and the organisation\'s own claims are in it', str_contains($csv['body'], 'ACCESS member approved claim') === true);

// --- it is one organisation's claims, not the installation's -----------------
// A claim of B's, with a title nothing else uses, must not appear in A's file.
// DEPT_B is not created until part M, so this part makes its own department
// of B and takes it away again. Using DEPT_B here fails on the link to
// tblDepts, which is how that was found rather than guessed.
q("INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES (?, ?, 'ACCESS L2 B dept', 'L2B', 1)", 'ii', [916392, ORG_B]);
q(
    "INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status, `currency`) VALUES (?, ?, ?, ?, 'ACCESS L2 B-ONLY CLAIM', CURDATE(), 99.99, 'Pending', 'EUR')",
    'iiii', [916391, ORG_B, BMEM, 916392]
);
$page = $approvalsNow('adm');
$csv  = req($jar['adm'], 'GET', $exportAddress . '?csrf_token=' . urlencode($page['token']));
ok('the file holds A\'s claims only: a claim of B\'s is not in it', $csv['status'] === 200 && str_contains($csv['body'], 'ACCESS L2 B-ONLY CLAIM') === false, 'HTTP ' . $csv['status']);
q('DELETE FROM tblExpenseClaims WHERE claimID = ?', 'i', [916391]);
q('DELETE FROM tblDepts WHERE deptID = ?', 'i', [916392]);

// --- an organisation with NO claims still gets the heading row --------------
// Found by the independent check on 30 October 2026, not by me.
// CsvExporter::download() derives its headings from `array_keys($rows[0])` and
// writes NONE when there are no rows, so an administrator of a new
// organisation downloaded three bytes — the byte-order mark alone — while the
// API description promised a heading row and a column order. The handler now
// passes the headings explicitly. B has no claims of its own at this point,
// which is what makes this testable without inventing anything.
$bExport = req($jar['badm'], 'GET', ADDR_B . '/expenses/api/export?csrf_token=' . urlencode(token(req($jar['badm'], 'GET', ADDR_B . '/expenses/approve')['body'])));
$bFirst  = trim((string) strtok($bExport['body'], "\n"));
ok(
    'an organisation with no claims gets the heading row, not an empty file',
    $bExport['status'] === 200 && str_ends_with($bFirst, 'claimID,Claimant,Department,Title,Date,Amount,Currency,Status') === true && strlen($bExport['body']) > 10,
    'HTTP ' . $bExport['status'] . ', ' . strlen($bExport['body']) . ' bytes, first line: ' . $bFirst
);

// --- #634: the download must not spend the form token -----------------------
// The approve/reject form on this page is built from the same token, and
// Auth::verifyCsrf() REPLACES a token whenever it accepts one. An earlier
// version of the handler used it, so downloading the list stopped the next
// approval from working, and approving first broke the download link. Nothing
// reported it as a token problem: somebody simply saw a money page fail.
// A download cannot be handed a replacement token, because the answer is the
// file itself — which is why verifyCsrfWithoutSpending() exists here.
$page = $approvalsNow('adm');
$first = req($jar['adm'], 'GET', $exportAddress . '?csrf_token=' . urlencode($page['token']));
$again = req($jar['adm'], 'GET', $exportAddress . '?csrf_token=' . urlencode($page['token']));
ok('#634: the same token downloads the file twice — the download does not spend it', $first['status'] === 200 && $again['status'] === 200, 'first HTTP ' . $first['status'] . ', again HTTP ' . $again['status']);
// NOT post(), and this is the whole point of the check. post() reads a FRESH
// token off the page before submitting, which is a reasonable thing for a
// harness to do and completely wrong here: it hides the fault. Measured by
// planting it — with the handler put back to the spending Auth::verifyCsrf(),
// the version of this check that used post() still PASSED, because the
// approval went in carrying a token fetched after the download had replaced
// the old one. The real sequence is one page load, one token, two uses of it:
// the person opens the approvals page, presses Export, then presses Approve on
// the form that page had already drawn.
$decision = req($jar['adm'], 'POST', $approve . '/save', ['csrf_token' => $page['token'], 'claimID' => 916306, 'decision' => 'Approved', 'comments' => 'after the download']);
ok('#634: …and the approval the SAME page already carried still goes through afterwards', $decision['status'] === 302 && $decision['location'] === $approve && claimState(916306) === 'Pending, 1 approval(s), 0 payment(s)', 'HTTP ' . $decision['status'] . ' Location: ' . $decision['location'] . '; claim: ' . claimState(916306));

// --- a token that is no good -------------------------------------------------
// #612's lesson: the handler used to send the person to 'expenses', which
// nothing answers, so an expired token ended in "page not found" instead of the
// message it had just set. It must land back on a real page, inside A's own
// address.
$bad = req($jar['adm'], 'GET', $exportAddress . '?csrf_token=not-a-real-token');
ok('a token that is no good sends an administrator back to A\'s own approvals page, not to a 404', $bad['status'] === 302 && $bad['location'] === $approve, 'HTTP ' . $bad['status'] . ' Location: ' . ($bad['location'] === '' ? 'none' : $bad['location']));

// --- the `approver` role is what counts -------------------------------------
// Giving the department approver the ROLE must open both halves at once. This
// is what shows the button and the handler are reading the SAME rule: if the
// page kept its own copy, one of the two would stay as it was.
q("INSERT INTO tblRoles (roleID, siteID, roleKey, roleName, isStandard) VALUES (916299, ?, 'approver', 'Expense Approver', 1)", 'i', [ORG_A]);
q('INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, 916299, ?)', 'ii', [APPR, ORG_A]);
$page  = $approvalsNow('appr');
$asRole = req($jar['appr'], 'GET', $exportAddress . '?csrf_token=' . urlencode($page['token']));
ok('given the `approver` role, the department approver is offered the button AND the file', $buttonDrawn($page['html']) === true && $asRole['status'] === 200 && str_contains((string) strtok($asRole['body'], "\n"), 'claimID') === true, 'button ' . ($buttonDrawn($page['html']) === true ? 'drawn' : 'NOT drawn') . ', HTTP ' . $asRole['status']);
q('DELETE FROM tblUserRoles WHERE userID = ? AND roleID = 916299', 'i', [APPR]);
q('DELETE FROM tblRoles WHERE roleID = 916299');
$page = $approvalsNow('appr');
ok('…and taking the role away again puts both back as they were', $buttonDrawn($page['html']) === false && req($jar['appr'], 'GET', $exportAddress . '?csrf_token=' . urlencode($page['token']))['status'] === 403);

// --- the ApiRouter address stays shut ---------------------------------------
// The SAME FILE also sits at `api/expenses/export`, which ApiRouter would
// serve. `api.expenses.export.enabled` is deliberately not seeded (owner,
// 30 October 2026), so that address answers 403 and the page route above is the
// only way in. Seeding it would publish a second address for the same CSV that
// a bearer token still could not use, because the handler needs a session and
// reads its token from the query string.
$page = $approvalsNow('adm');
$viaApi = req($jar['adm'], 'GET', ADDR_A . '/api/expenses/export?csrf_token=' . urlencode($page['token']));
ok('the ApiRouter address is shut for an administrator too: 403, and no file', $viaApi['status'] === 403 && str_contains(strtolower($viaApi['head']), 'text/csv') === false, 'HTTP ' . $viaApi['status']);

// =============================================================================
head('L3. #648 — the Approver role sees only its own departments\' claims (D-103)');
// =============================================================================
/**
 * WHAT WAS WRONG. The organisation-wide Approver role (the `expenses.approve`
 * capability) opened ANY claim of the organisation on the claim page — line
 * items, suppliers, receipts — and the spreadsheet download gave it every
 * claim, while the approvals list and the decision handler kept it to the
 * departments its holder approves for. The owner's answer (D-103, 10 November
 * 2026): the role sees and downloads only those departments' claims;
 * administrators and treasurers see everything. A claim somebody may not open
 * is answered EXACTLY as a claim that does not exist.
 *
 * THE WORLD. A second department of A, L3_DEPT, in which nobody of this test
 * holds a flag, and two pending claims made by LEAD: one in DEPT (where APPR is
 * an approver) and one in L3_DEPT (where APPR is nothing). LEAD makes them so
 * that neither APPR nor MEM nor TRS is ever the claimant — "it is their own" is
 * a ground every one of these checks must not be able to lean on.
 */
const L3_DEPT       = 916105;
const L3_OWN_DEPT   = 916396; // LEAD's claim in DEPT
const L3_OTHER_DEPT = 916395; // LEAD's claim in L3_DEPT
const L3_NO_CLAIM   = 916398; // matches no claim at all
const L3_MEM_CLAIM  = 916394; // MEM's OWN claim, in L3_DEPT, where MEM has no row at all
const L3_ROLE       = 916297;
q("INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES (?, ?, 'ACCESS L3 second dept', 'L3D', 1)", 'ii', [L3_DEPT, ORG_A]);
q(
    "INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status, `currency`) VALUES "
    . "(?, ?, ?, ?, 'ACCESS L3 CLAIM IN THE APPROVERS DEPT', CURDATE(), 31.00, 'Pending', 'EUR'), "
    . "(?, ?, ?, ?, 'ACCESS L3 CLAIM IN ANOTHER DEPT', CURDATE(), 32.00, 'Pending', 'EUR'), "
    . "(?, ?, ?, ?, 'ACCESS L3 MEMBERS OWN CLAIM', CURDATE(), 33.00, 'Pending', 'EUR')",
    'iiiiiiiiiiii', [L3_OWN_DEPT, ORG_A, LEAD, DEPT, L3_OTHER_DEPT, ORG_A, LEAD, L3_DEPT, L3_MEM_CLAIM, ORG_A, MEM, L3_DEPT]
);
// Every claim needs a line to be a claim anybody could edit; the edit checks
// below must get past the handler's own checks to reach the claim lookup.
q("INSERT INTO tblExpenseClaimItems (claimID, itemName, quantity, unitCost, lineTotal) VALUES (?, 'L3 line', 1, 31.00, 31.00), (?, 'L3 line', 1, 32.00, 32.00)", 'ii', [L3_OWN_DEPT, L3_OTHER_DEPT]);
$l3Own   = 'ACCESS L3 CLAIM IN THE APPROVERS DEPT';
$l3Other = 'ACCESS L3 CLAIM IN ANOTHER DEPT';
/** Grant or take away the `approver` role in A. */
$l3Role = static function (int $userId, bool $give): void {
    if (one('SELECT roleID FROM tblRoles WHERE roleID = ?', 'i', [L3_ROLE]) === null) {
        q("INSERT INTO tblRoles (roleID, siteID, roleKey, roleName, isStandard) VALUES (?, ?, 'approver', 'Expense Approver', 1)", 'ii', [L3_ROLE, ORG_A]);
    }
    q('DELETE FROM tblUserRoles WHERE userID = ? AND roleID = ?', 'ii', [$userId, L3_ROLE]);
    if ($give === true) {
        q('INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, ?, ?)', 'iii', [$userId, L3_ROLE, ORG_A]);
    }
};
/** The claim page, as HTTP status and body. */
$l3Open = static fn (string $who, int $claimId): array => req($jar[$who], 'GET', ADDR_A . '/expenses/view?id=' . $claimId);
/**
 * A page with the parts that differ between ANY two answers removed: the
 * per-answer script nonce, by NAME (never a 32-hex pattern, which would blind
 * the comparison to a real difference of that length — CLAUDE.md, 31 October
 * 2026). Nothing else is removed, so a 404 that said anything about the claim
 * — its title, its number, "not allowed" — would make the two differ.
 */
$l3Bare = static fn (string $body): string => ap_replace('/\snonce="[^"]*"/', ' nonce=""', $body);
/**
 * The response HEADERS with the parts that differ between any two answers
 * taken out — the date, a cookie, and the nonce inside the page rule — so an
 * extra header on one refusal (a hint, a different cache rule) is seen.
 */
$l3Head = static function (string $head): string {
    $keep = [];
    foreach (preg_split('/\r?\n/', trim($head)) as $line) {
        if (ap_find('/^(Date|Set-Cookie):/i', $line) === 1) {
            continue;
        }
        $keep[] = ap_replace("/'nonce-[^']*'/", "'nonce-'", $line);
    }

    return implode("\n", $keep);
};
/** The one-off message waiting on a page, read (and so cleared) from it. */
$l3Flash = static function (string $who, string $page) use (&$jar): string {
    $body = req($jar[$who], 'GET', $page)['body'];

    return ap_find('#<div class="alert alert-(\w+) alert-dismissible fade show">\s*(.*?)\s*<button#s', $body, $m) === 1
        ? $m[1] . ': ' . html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8')
        : '(no message)';
};
/** The spreadsheet, fetched with a fresh token from the person's own approvals page. */
$l3Csv = static function (string $who) use (&$jar, $approve, $exportAddress): array {
    $page = req($jar[$who], 'GET', $approve);

    return req($jar[$who], 'GET', $exportAddress . '?csrf_token=' . urlencode(token($page['body'])));
};

// --- the role holder who approves for DEPT ------------------------------------
$l3Role(APPR, true);
$own   = $l3Open('appr', L3_OWN_DEPT);
$other = $l3Open('appr', L3_OTHER_DEPT);
$none  = $l3Open('appr', L3_NO_CLAIM);
ok(
    'L3-1. an approver of DEPT holding the Approver role opens a claim of DEPT',
    $own['status'] === 200 && str_contains($own['body'], $l3Own) === true,
    'HTTP ' . $own['status']
);
ok(
    'L3-2. …and does NOT open a claim of a department they do not approve for: HTTP 404, and the claim is nowhere in the answer (D-103)',
    $other['status'] === 404 && str_contains($other['body'], $l3Other) === false && str_contains($other['body'], (string) L3_OTHER_DEPT) === false,
    'HTTP ' . $other['status']
);
ok(
    'L3-3. …and that refusal is EXACTLY the answer a made-up claim number gets — same status, same page byte for byte apart from the nonce, and the same headers apart from the date, a cookie and the nonce',
    $other['status'] === $none['status'] && $l3Bare($other['body']) === $l3Bare($none['body']) && strlen($none['body']) > 200
        && $l3Head($other['head']) === $l3Head($none['head']) && str_contains($l3Head($none['head']), 'Content-Type') === true,
    'statuses ' . $other['status'] . '/' . $none['status'] . ', lengths ' . strlen($other['body']) . '/' . strlen($none['body'])
        . '; headers only in one: ' . implode(' | ', array_merge(
            array_diff(explode("\n", $l3Head($other['head'])), explode("\n", $l3Head($none['head']))),
            array_diff(explode("\n", $l3Head($none['head'])), explode("\n", $l3Head($other['head'])))
        ))
);
// --- …and the SAME DATABASE WORK, counted in the server's own query log ------
// The claim page works out the reach BEFORE it reads the claim, so a real
// claim out of reach and a made-up number send the same statements, in the
// same order. Numbers are blanked, because the claim number differs. The log
// is switched on for these two requests only and put back as part K does
// (the clean-up puts it back too if the run stops early).
$l3LogWas = one('SELECT @@GLOBAL.general_log AS general_log, @@GLOBAL.log_output AS log_output');
if ($l3LogWas === null || ap_find('/^[A-Z,]+$/', (string) $l3LogWas['log_output']) !== 1) {
    refuse('the database would not say how its query log is set');
}
$state['queryLog'] = ['general_log' => (int) $l3LogWas['general_log'], 'log_output' => (string) $l3LogWas['log_output']];
q("SET GLOBAL log_output = 'TABLE'");
q('SET GLOBAL general_log = 1');
/** The statements the portal sent for one request, numbers blanked. @return list<string> */
$l3Statements = static function (string $who, int $claimId) use ($l3Open): array {
    q('TRUNCATE TABLE mysql.general_log');
    $l3Open($who, $claimId);
    $rows = q("SELECT CONVERT(argument USING utf8mb4) AS a FROM mysql.general_log WHERE command_type IN ('Query', 'Execute') AND thread_id <> CONNECTION_ID() ORDER BY event_time, thread_id");

    return array_map(static fn (array $r): string => ap_replace('/\d+/', '0', (string) $r['a']), $rows);
};
$sqlOther = $l3Statements('appr', L3_OTHER_DEPT);
$sqlNone  = $l3Statements('appr', L3_NO_CLAIM);
q('SET GLOBAL general_log = ' . (int) $state['queryLog']['general_log']);
q("SET GLOBAL log_output = '" . $state['queryLog']['log_output'] . "'");
q('TRUNCATE TABLE mysql.general_log');
$state['queryLog'] = null;
$readsClaim = static fn (array $sql): bool => array_filter($sql, static fn (string $a): bool => str_contains($a, 'FROM tblExpenseClaims EC')) !== [];
$readsDepts = static fn (array $sql): bool => array_filter($sql, static fn (string $a): bool => str_contains($a, 'FROM tblUserDepts')) !== [];
ok(
    'L3-3b. …and the SAME DATABASE WORK: the same statements, in the same order, numbers aside — the department read and the claim read in both',
    $sqlOther === $sqlNone && $readsClaim($sqlNone) === true && $readsDepts($sqlNone) === true,
    count($sqlOther) . ' statements against ' . count($sqlNone) . '; first difference: '
        . (string) (array_values(array_diff_assoc($sqlOther, $sqlNone))[0] ?? array_values(array_diff_assoc($sqlNone, $sqlOther))[0] ?? 'none')
);
$csv = $l3Csv('appr');
ok(
    'L3-4. the role holder\'s spreadsheet holds DEPT\'s claim and NOT the other department\'s (D-103: filtered in the query)',
    $csv['status'] === 200 && str_contains($csv['body'], $l3Own) === true && str_contains($csv['body'], $l3Other) === false,
    'HTTP ' . $csv['status'] . '; own ' . (str_contains($csv['body'], $l3Own) === true ? 'in' : 'NOT in') . ', other ' . (str_contains($csv['body'], $l3Other) === true ? 'IN' : 'not in')
);
$list = req($jar['appr'], 'GET', $approve)['body'];
ok(
    'L3-5. …which is what their approvals list shows them: DEPT\'s claim listed, the other department\'s not',
    str_contains($list, $l3Own) === true && str_contains($list, $l3Other) === false
);
ok(
    'L3-6. …and on DEPT\'s claim they are offered "Go to Approvals", because they may decide it',
    str_contains($own['body'], 'Go to Approvals') === true
);

// --- the role holder with NO department ---------------------------------------
// MEM is a member of A, in DEPT with no flag. With the role and nothing else,
// they reach no department: no claim page, and a file with the heading alone.
$l3Role(MEM, true);
$memOwn = $l3Open('mem', L3_OWN_DEPT);
$memCsv = $l3Csv('mem');
$memPage = req($jar['mem'], 'GET', $approve)['body'];
ok(
    'L3-7. the Approver role on its own, with no department, opens no claim of anybody else: HTTP 404',
    $memOwn['status'] === 404 && str_contains($memOwn['body'], $l3Own) === false,
    'HTTP ' . $memOwn['status']
);
ok(
    'L3-8. …is still offered the Export button (who may download is unchanged), and the file is the heading row and nothing else',
    $buttonDrawn($memPage) === true && $memCsv['status'] === 200
        && trim(ap_replace('/^\xEF\xBB\xBF/', '', $memCsv['body'])) === 'claimID,Claimant,Department,Title,Date,Amount,Currency,Status',
    'HTTP ' . $memCsv['status'] . ', body: ' . mb_substr(trim($memCsv['body']), 0, 160)
);
$l3Role(MEM, false);

// --- the department approver WITHOUT the role: as before ----------------------
$l3Role(APPR, false);
$plainOwn   = $l3Open('appr', L3_OWN_DEPT);
$plainOther = $l3Open('appr', L3_OTHER_DEPT);
ok(
    'L3-9. without the role, the department approver still opens DEPT\'s claim (#542) and still not the other department\'s, and still has no spreadsheet',
    $plainOwn['status'] === 200 && $plainOther['status'] === 404 && $l3Csv('appr')['status'] === 403,
    'HTTP ' . $plainOwn['status'] . '/' . $plainOther['status']
);

// --- administrators and the treasurer keep seeing everything -----------------
foreach (['adm' => 'an administrator', 'gadm' => 'a global administrator', 'trs' => 'a treasurer'] as $who => $what) {
    $a = $l3Open($who, L3_OWN_DEPT);
    $b = $l3Open($who, L3_OTHER_DEPT);
    $f = $l3Csv($who);
    ok(
        'L3-10. ' . $what . ' opens BOTH claims, and the spreadsheet holds both',
        $a['status'] === 200 && $b['status'] === 200 && str_contains($b['body'], $l3Other) === true
            && $f['status'] === 200 && str_contains($f['body'], $l3Own) === true && str_contains($f['body'], $l3Other) === true,
        'claim pages HTTP ' . $a['status'] . '/' . $b['status'] . ', file HTTP ' . $f['status']
    );
}
$trsList = req($jar['trs'], 'GET', $approve)['body'];
$admList = req($jar['adm'], 'GET', $approve)['body'];
ok(
    'L3-11. the approvals list is the DECIDING reach: an administrator is offered both claims, a treasurer (who pays, not decides) neither',
    str_contains($admList, $l3Own) === true && str_contains($admList, $l3Other) === true
        && str_contains($trsList, $l3Own) === false && str_contains($trsList, $l3Other) === false
);
// MEM holds no role and no flag at this point (L3-8 took the role away), and
// has no row at all in L3_DEPT — so "it is their own" is the ONLY ground on
// which they can open this claim. (The first version used LEAD, who is also a
// treasurer, so deleting the claimant ground left it green.)
$memOwnClaim = $l3Open('mem', L3_MEM_CLAIM);
ok(
    'L3-12. a claimant with no role and no flag opens their own claim in a department they have nothing to do with',
    $memOwnClaim['status'] === 200 && str_contains($memOwnClaim['body'], 'ACCESS L3 MEMBERS OWN CLAIM') === true
        && (int) one('SELECT COUNT(*) AS n FROM tblUserRoles WHERE userID = ?', 'i', [MEM])['n'] === 0
        && (int) one('SELECT COUNT(*) AS n FROM tblUserDepts WHERE userID = ? AND (isDeptLead = 1 OR isApprover = 1 OR isMandatoryApprover = 1)', 'i', [MEM])['n'] === 0,
    'HTTP ' . $memOwnClaim['status']
);
ok(
    'L3-13. an ordinary member with no role and no flag is answered 404 for a real claim, exactly as for a made-up one (it used to be 403, which said the claim was real)',
    $l3Open('mem', L3_OTHER_DEPT)['status'] === 404
        && $l3Bare($l3Open('mem', L3_OTHER_DEPT)['body']) === $l3Bare($l3Open('mem', L3_NO_CLAIM)['body'])
);

$trsPage = $l3Open('trs', L3_OWN_DEPT);
ok(
    'L3-14. a treasurer opening somebody else\'s pending claim is NOT offered "Go to Approvals": they pay claims, they do not decide them',
    $trsPage['status'] === 200 && str_contains($trsPage['body'], $l3Own) === true && str_contains($trsPage['body'], 'Go to Approvals') === false,
    'HTTP ' . $trsPage['status']
);

// --- every other expense address: somebody else's claim is a made-up number ----
// (#648 round 1: the class, not one instance. Enumerated with
// `git grep -n "403\|Forbidden\|http_response_code" web/_apps/expenses/`.)
$editOther = req($jar['mem'], 'GET', ADDR_A . '/expenses/edit?id=' . L3_OWN_DEPT);
$editNone  = req($jar['mem'], 'GET', ADDR_A . '/expenses/edit?id=' . L3_NO_CLAIM);
ok(
    'L3-15. the edit form: somebody else\'s real claim gets EXACTLY what a made-up number gets — 404, the same page and the same headers (it was 403)',
    $editOther['status'] === 404 && $editNone['status'] === 404 && $l3Bare($editOther['body']) === $l3Bare($editNone['body'])
        && $l3Head($editOther['head']) === $l3Head($editNone['head']) && str_contains($editOther['body'], $l3Own) === false,
    'HTTP ' . $editOther['status'] . '/' . $editNone['status']
);
ok(
    'L3-15b. …while the claimant opens the same edit form (so the 404 above is about who asked, not a broken page)',
    req($jar['lead'], 'GET', ADDR_A . '/expenses/edit?id=' . L3_OWN_DEPT)['status'] === 200
);
$editSave = static function (int $claimId) use (&$jar, $l3Flash): string {
    $answer = post($jar['mem'], ADDR_A . '/expenses/submit', ADDR_A . '/expenses/edit/save', [
        'claimID' => $claimId, 'claimTitle' => 'L3 HIJACKED TITLE', 'deptID' => DEPT,
        'itemDesc' => ['x'], 'itemQty' => ['1'], 'itemUnit' => ['1.00'],
    ]);

    return 'HTTP ' . $answer['status'] . ' to ' . $answer['location'] . ' — ' . $l3Flash('mem', $answer['location'] === '' ? ADDR_A . '/expenses/submit' : $answer['location']);
};
$saveOther = $editSave(L3_OWN_DEPT);
$saveNone  = $editSave(L3_NO_CLAIM);
ok(
    'L3-16. the edit form\'s save: somebody else\'s real claim gets EXACTLY the redirect and the message a made-up number gets, and nothing is changed',
    $saveOther === $saveNone && str_contains($saveNone, 'That claim could not be found.') === true
        && one('SELECT claimTitle FROM tblExpenseClaims WHERE claimID = ?', 'i', [L3_OWN_DEPT])['claimTitle'] === $l3Own,
    $saveOther . '  ||  ' . $saveNone
);
$deleteAs = static function (int $claimId) use (&$jar): array {
    $page = req($jar['mem'], 'GET', ADDR_A . '/expenses/submit');

    return postJson($jar['mem'], ADDR_A . '/api/expenses/delete', ['claimID' => $claimId, 'csrf_token' => token($page['body'])]);
};
$delOther = $deleteAs(L3_OWN_DEPT);
$delNone  = $deleteAs(L3_NO_CLAIM);
ok(
    'L3-17. the delete API: somebody else\'s real claim gets EXACTLY what a made-up number gets — 404 "Expense claim not found" (it was 403) — and the claim is still there',
    $delOther['status'] === 404 && $delNone['status'] === 404 && stable($delOther) === stable($delNone) && said($delNone) === '404: Expense claim not found'
        && (int) one('SELECT COUNT(*) AS n FROM tblExpenseClaims WHERE claimID = ?', 'i', [L3_OWN_DEPT])['n'] === 1,
    said($delOther) . '  ||  ' . said($delNone)
);
$decide = static function (string $who, int $claimId) use (&$jar, $approve, $l3Flash): string {
    $answer = post($jar[$who], $approve, $approve . '/save', ['claimID' => $claimId, 'decision' => 'Approved', 'comments' => 'L3']);

    return 'HTTP ' . $answer['status'] . ' to ' . $answer['location'] . ' — ' . $l3Flash($who, $approve);
};
$approvalsOn = static fn (int $claimId): int => (int) one('SELECT COUNT(*) AS n FROM tblExpenseClaimApprovals WHERE claimID = ?', 'i', [$claimId])['n'];
// #915: the Approver role with NO department used to pass the handler's first
// check, then hear "Forbidden" for a real pending claim and "Claim not found"
// for a made-up number. The role is no longer a way past that check.
$l3Role(MEM, true);
$roleOther = $decide('mem', L3_OTHER_DEPT);
$roleNone  = $decide('mem', L3_NO_CLAIM);
$l3Role(MEM, false);
ok(
    'L3-18. the decision handler: the Approver role with no department gets the SAME answer for a real pending claim and a made-up number — refused before any claim is looked up — and nothing is recorded (#915)',
    $roleOther === $roleNone && str_contains($roleNone, 'Access denied — only an administrator, or a lead or approver of a department, can decide expense claims.') === true
        && $approvalsOn(L3_OTHER_DEPT) === 0,
    $roleOther . '  ||  ' . $roleNone
);
$deptOther = $decide('appr', L3_OTHER_DEPT);
$deptNone  = $decide('appr', L3_NO_CLAIM);
ok(
    'L3-19. the decision handler: an approver of DEPT gets the SAME answer for another department\'s real pending claim as for a made-up number — "Claim not found or not in Pending status." (it was a bare "Forbidden") — and nothing is recorded',
    $deptOther === $deptNone && str_contains($deptNone, 'Claim not found or not in Pending status.') === true && $approvalsOn(L3_OTHER_DEPT) === 0,
    $deptOther . '  ||  ' . $deptNone
);

// =============================================================================
head('L4. #867 — a receipt opens for exactly the people who may open its claim');
// =============================================================================
/**
 * WHAT WAS WRONG. The claim page listed each receipt's label, kind and size
 * with no link, and nothing served the receipts folder (outside the web root
 * on purpose), so an approver or a treasurer could not look at the evidence
 * they were deciding or paying against. `expenses/receipt?claim=&id=` now
 * sends one receipt, asking the claim page's own rule
 * (`ExpenseRules::reachToSee()` and `mayViewClaim()`).
 *
 * THE WORLD. L3's three claims, each given a receipt FILE in the scratch
 * copy's receipts folder and a row, so every answer can be compared with the
 * real bytes: LEAD's claim in DEPT (APPR approves for DEPT), LEAD's claim in
 * L3_DEPT (where APPR, MEM and TRS hold nothing), and MEM's own claim in
 * L3_DEPT (so MEM gets in on "it is mine" ALONE — no department, no role).
 * Plus rows no correct portal writes but a restored or hand-edited database
 * could hold: a stored name climbing out with `../`, an absolute path, `..`,
 * a LINK inside the folder to a secret outside it, a row whose file is gone,
 * and the claim's generated-PDF row (it has a stage) with a file of its name
 * planted in the receipts folder — so only the "no stage" condition stands
 * between it and being served.
 *
 * WHAT IT CANNOT DO: drive a browser, so it proves the HEADERS a browser acts
 * on (attachment, nosniff, a sandboxing page rule) rather than what a browser
 * then does with them.
 */
const L4_R_OWN   = 916861; // receipt of L3_OWN_DEPT (LEAD's claim, DEPT)
const L4_R_OTHER = 916862; // receipt of L3_OTHER_DEPT (LEAD's claim, L3_DEPT)
const L4_R_MEM   = 916863; // receipt of L3_MEM_CLAIM (MEM's own claim)
const L4_R_PDF   = 916864; // the generated PDF's row on L3_OWN_DEPT (it has a stage)
const L4_R_UP    = 916865; // stored name climbing out with ../
const L4_R_ABS   = 916866; // stored name an absolute path
const L4_R_DOTS  = 916867; // stored name '..'
const L4_R_LINK  = 916868; // a plain name, but a LINK to a file outside the folder
const L4_R_GONE  = 916869; // a row whose file is not there
const L4_R_NAME  = 916870; // a label with quotes, line breaks and non-ASCII letters
const L4_R_TYPE  = 916871; // a stored type with something after it, over HTML bytes
const L4_R_CJK   = 916872; // a label written wholly in another script
const L4_NO_FILE = 916879; // matches no receipt at all

$l4Folder = $child . $sep . 'web' . $sep . '_uploads' . $sep . 'expenses';
if (is_dir($l4Folder) === false && mkdir($l4Folder, 0700, true) === false) {
    refuse('the scratch copy\'s receipts folder could not be made');
}
$l4Secret = $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key';
/** What each receipt file really holds, read back from disk below; binary on purpose. */
$l4Bytes = [
    L4_R_OWN   => "%PDF-1.4\n" . random_bytes(3000) . "\n%%EOF\n",
    L4_R_OTHER => "\x89PNG\r\n\x1a\n" . random_bytes(1500),
    L4_R_MEM   => "\xFF\xD8\xFF\xE0" . random_bytes(2500),
    L4_R_NAME  => "%PDF-1.4 the label test\n",
    L4_R_TYPE  => "<html><body><script>document.title='ran'</script></body></html>",
    L4_R_CJK   => "%PDF-1.4 another script\n",
    916873     => "%PDF-1.4 a receipt of the other organisation\n", // L4_B_RECEIPT, defined further down
];
/** The type each one is served with: the stored type, or octet-stream when the stored one is not a bare type/subtype. */
$l4Types = [
    L4_R_OWN => 'application/pdf', L4_R_OTHER => 'image/png', L4_R_MEM => 'image/jpeg',
    L4_R_NAME => 'application/pdf', L4_R_TYPE => 'application/octet-stream',
    L4_R_CJK => 'application/pdf', 916873 => 'application/pdf',
];
$l4Label = "Jane \"Smith\";\r\nX-Injected: yes\r\n café ünïcode.pdf";
/** One receipt row, and its file when it has bytes. Stage and type may be NULL. */
$l4Add = static function (int $id, int $claim, string $label, string $stored, ?string $type, ?string $stage, ?string $bytes) use ($l4Folder, $sep): void {
    if ($bytes !== null && file_put_contents($l4Folder . $sep . $stored, $bytes) !== strlen($bytes)) {
        refuse('a receipt file could not be written into the scratch copy');
    }
    q(
        'INSERT INTO `tblExpenseClaimFiles` (`fileID`, `claimID`, `fileLabel`, `storedFilename`, `fileSize`, `fileType`, `stage`) VALUES (?, ?, ?, ?, ?, ?, ?)',
        'iississ', [$id, $claim, $label, $stored, $bytes === null ? 0 : strlen($bytes), $type, $stage]
    );
};
$l4Add(L4_R_OWN, L3_OWN_DEPT, 'Claim_' . L3_OWN_DEPT . '_Receipt_1_2026-11-11.pdf', L3_OWN_DEPT . '_1700000000_receipt1.pdf', 'application/pdf', null, $l4Bytes[L4_R_OWN]);
$l4Add(L4_R_OTHER, L3_OTHER_DEPT, 'Claim_' . L3_OTHER_DEPT . '_Receipt_1_2026-11-11.png', L3_OTHER_DEPT . '_1700000000_receipt1.png', 'image/png', null, $l4Bytes[L4_R_OTHER]);
$l4Add(L4_R_MEM, L3_MEM_CLAIM, 'Claim_' . L3_MEM_CLAIM . '_Receipt_1_2026-11-11.jpg', L3_MEM_CLAIM . '_1700000000_receipt1.jpg', 'image/jpeg', null, $l4Bytes[L4_R_MEM]);
$l4Add(L4_R_PDF, L3_OWN_DEPT, 'Claim_' . L3_OWN_DEPT . '_Pending.pdf', 'claim_' . L3_OWN_DEPT . '.pdf', 'application/pdf', 'Pending', "%PDF-1.4 the claim's own form\n");
$l4Add(L4_R_UP, L3_OWN_DEPT, 'up.pdf', '..' . $sep . '..' . $sep . '_auth_keys' . $sep . 'enc.key', 'application/pdf', null, null);
// A SHORT absolute path that exists on every machine this runs on: the key
// file's own path is under the scratch folder, which can be longer than the
// column's 255 characters (it was, on a deeply nested scratch folder, and the
// insert failed). L4-16 also requires no file is sent at all for this row.
$l4Add(L4_R_ABS, L3_OWN_DEPT, 'abs.pdf', '/etc/hosts', 'application/pdf', null, null);
$l4Add(L4_R_DOTS, L3_OWN_DEPT, 'dots.pdf', '..', 'application/pdf', null, null);
$l4Add(L4_R_LINK, L3_OWN_DEPT, 'link.pdf', L3_OWN_DEPT . '_1700000000_receipt9.pdf', 'application/pdf', null, null);
if (symlink($l4Secret, $l4Folder . $sep . L3_OWN_DEPT . '_1700000000_receipt9.pdf') === false) {
    refuse('a link could not be made inside the scratch copy\'s receipts folder');
}
$l4Add(L4_R_GONE, L3_OWN_DEPT, 'gone.pdf', L3_OWN_DEPT . '_1700000000_receipt8.pdf', 'application/pdf', null, null);
$l4Add(L4_R_NAME, L3_OWN_DEPT, $l4Label, L3_OWN_DEPT . '_1700000000_receipt2.pdf', 'application/pdf', null, $l4Bytes[L4_R_NAME]);
$l4Add(L4_R_TYPE, L3_OWN_DEPT, 'Claim_' . L3_OWN_DEPT . '_Receipt_3_2026-11-11.html', L3_OWN_DEPT . '_1700000000_receipt3.pdf', 'text/html; charset=utf-8', null, $l4Bytes[L4_R_TYPE]);
$secretBytes = (string) file_get_contents($l4Secret);
if (strlen($secretBytes) < 32) {
    refuse('the scratch copy\'s key file is not there to plant a link to');
}

/** One receipt, as status, body and headers. */
$l4Get = static fn (string $who, int $claimId, int $fileId): array => req($who, 'GET', ADDR_A . '/expenses/receipt?claim=' . $claimId . '&id=' . $fileId);
/** One header's value, or '' when it is not there (the LAST, as a browser takes it). */
$l4Header = static function (array $answer, string $name): string {
    ap_findAll('/^' . preg_quote($name, '/') . ':[ \t]*(.*?)[ \t]*\r?$/mi', $answer['head'], $m);

    return (string) (end($m[1]) ?: '');
};
/** Served exactly: 200, the file's own bytes, the stored type, attachment, nosniff, no caching, sandboxed. */
$l4Served = static function (array $answer, int $fileId) use ($l4Bytes, $l4Types, $l4Header): bool {
    return $answer['status'] === 200
        && $answer['body'] === $l4Bytes[$fileId]
        && $l4Header($answer, 'Content-Type') === $l4Types[$fileId]
        && str_starts_with($l4Header($answer, 'Content-Disposition'), 'attachment; filename="') === true
        && strtolower($l4Header($answer, 'X-Content-Type-Options')) === 'nosniff'
        && str_contains($l4Header($answer, 'Cache-Control'), 'no-store') === true
        && str_contains($l4Header($answer, 'Content-Security-Policy'), 'sandbox') === true
        && $l4Header($answer, 'Content-Length') === (string) strlen($l4Bytes[$fileId]);
};
/** Why an answer was not served, for a failure line. */
$l4Why = static fn (array $answer): string => 'HTTP ' . $answer['status'] . ', ' . strlen($answer['body']) . ' bytes, type "'
    . $l4Header($answer, 'Content-Type') . '", disposition "' . $l4Header($answer, 'Content-Disposition') . '"';
/** Two answers are the SAME answer: status, page (nonce aside) and headers (date, cookie and nonce aside). */
$l4Same = static fn (array $x, array $y): bool => $x['status'] === $y['status']
    && $l3Bare($x['body']) === $l3Bare($y['body']) && $l3Head($x['head']) === $l3Head($y['head']);
/** No answer may hold any receipt's bytes, or the key file a link points at. */
$l4Leaks = static function (array $answer) use ($l4Bytes, $secretBytes): bool {
    foreach ($l4Bytes as $bytes) {
        if (str_contains($answer['body'], $bytes) === true) {
            return true;
        }
    }

    return str_contains($answer['body'], $secretBytes);
};

// --- who may open it, and gets exactly the file ------------------------------
foreach ([
    ['L4-1. the CLAIMANT opens a receipt of their own claim — on "it is mine" alone: MEM has no department or role that reaches it', 'mem', L3_MEM_CLAIM, L4_R_MEM],
    ['L4-2. an APPROVER of the claim\'s department opens its receipt', 'appr', L3_OWN_DEPT, L4_R_OWN],
    ['L4-3. a TREASURER opens a receipt of a department they hold nothing in', 'trs', L3_OTHER_DEPT, L4_R_OTHER],
    ['L4-4. an ADMINISTRATOR of the organisation opens a receipt', 'adm', L3_OTHER_DEPT, L4_R_OTHER],
    ['L4-5. a GLOBAL administrator opens a receipt', 'gadm', L3_OWN_DEPT, L4_R_OWN],
] as [$label, $who, $claimId, $fileId]) {
    $answer = $l4Get($jar[$who], $claimId, $fileId);
    ok($label . ': HTTP 200, the file\'s own bytes, its stored type, attachment, nosniff, no-store and a sandboxing page rule', $l4Served($answer, $fileId), $l4Why($answer));
}

// --- who may not, and gets EXACTLY what a receipt that does not exist gets --
// Each refused person is compared with THEMSELVES asking for a made-up receipt
// number on the same claim, and for a made-up claim as well — the answer must
// be the same in status, page and headers, and hold none of the bytes.
$l4Refused = static function (string $label, string $who, int $claimId, int $fileId, int $wantStatus) use (&$jar, $l4Get, $l4Same, $l4Leaks, $l3Head): void {
    $real  = $l4Get($jar[$who], $claimId, $fileId);
    $none  = $l4Get($jar[$who], $claimId, L4_NO_FILE);
    $none2 = $l4Get($jar[$who], L3_NO_CLAIM, L4_NO_FILE);
    ok(
        $label . ': HTTP ' . $wantStatus . ', byte for byte what a made-up receipt and a made-up claim get, and none of the file',
        $real['status'] === $wantStatus && $l4Same($real, $none) === true && $l4Same($real, $none2) === true
            && $l4Leaks($real) === false && strlen($none['body']) > 200,
        'HTTP ' . $real['status'] . '/' . $none['status'] . '/' . $none2['status'] . ', lengths ' . strlen($real['body']) . '/' . strlen($none['body']) . '/' . strlen($none2['body'])
            . '; headers only in one: ' . implode(' | ', array_diff(explode("\n", $l3Head($real['head'])), explode("\n", $l3Head($none['head']))))
    );
};
$l4Refused('L4-6. an approver of ANOTHER department (APPR, on a claim of L3_DEPT)', 'appr', L3_OTHER_DEPT, L4_R_OTHER, 404);
$l4Refused('L4-7. an ORDINARY member of the claim\'s own department (MEM, on LEAD\'s claim in DEPT)', 'mem', L3_OWN_DEPT, L4_R_OWN, 404);
$l3Role(MEM, true);
$l4Refused('L4-8. the Approver ROLE with no department (MEM holding it, D-103)', 'mem', L3_OWN_DEPT, L4_R_OWN, 404);
$l3Role(MEM, false);
// Outsiders are refused at the organisation's door first (403, the same for
// every address); with that gate stood down, the PAGE's own answer is tried.
foreach (['bmem' => 'a MEMBER of the other organisation', 'badm' => 'an ADMINISTRATOR of the other organisation', 'nomem' => 'an account in NO organisation'] as $who => $words) {
    $l4Refused('L4-9. ' . $words . ', at the door', $who, L3_OWN_DEPT, L4_R_OWN, 403);
}
membersGate(false);
foreach (['bmem' => 'a MEMBER of the other organisation', 'badm' => 'an ADMINISTRATOR of the other organisation', 'nomem' => 'an account in NO organisation'] as $who => $words) {
    $l4Refused('L4-10. ' . $words . ', with the door stood down so the page itself answers', $who, L3_OWN_DEPT, L4_R_OWN, 404);
}
membersGate(true);
// Signed out: sent to sign in, and the only difference between a real and a
// made-up receipt is the numbers the visitor typed, carried in the address
// they are sent back to.
$outReal = $l4Get($nobody, L3_OWN_DEPT, L4_R_OWN);
$outNone = $l4Get($nobody, L3_NO_CLAIM, L4_NO_FILE);
$digits  = static fn (string $text): string => ap_replace('/[0-9]/', '0', $text);
ok(
    'L4-11. a SIGNED-OUT visitor is sent to sign in, with the same answer for a real receipt as a made-up one (numbers aside), and none of the file',
    $outReal['status'] === 302 && str_contains($outReal['location'], '/login') === true
        && $outReal['status'] === $outNone['status'] && $digits($l3Head($outReal['head'])) === $digits($l3Head($outNone['head']))
        && $digits($outReal['body']) === $digits($outNone['body']) && $l4Leaks($outReal) === false,
    'HTTP ' . $outReal['status'] . ' to ' . $outReal['location'] . ' / HTTP ' . $outNone['status'] . ' to ' . $outNone['location']
);

// --- the same database work for "not yours" and "not there" ----------------
// The reach is worked out BEFORE anything is looked up, as on the claim page,
// so a real receipt out of reach and a made-up number send the same
// statements. The query log is handled exactly as L3-3b handles it.
$l4LogWas = one('SELECT @@GLOBAL.general_log AS general_log, @@GLOBAL.log_output AS log_output');
if ($l4LogWas === null || ap_find('/^[A-Z,]+$/', (string) $l4LogWas['log_output']) !== 1) {
    refuse('the database would not say how its query log is set');
}
$state['queryLog'] = ['general_log' => (int) $l4LogWas['general_log'], 'log_output' => (string) $l4LogWas['log_output']];
q("SET GLOBAL log_output = 'TABLE'");
q('SET GLOBAL general_log = 1');
/** The statements the portal sent for one receipt request, numbers blanked. @return list<string> */
$l4Statements = static function (string $who, int $claimId, int $fileId) use (&$jar, $l4Get): array {
    q('TRUNCATE TABLE mysql.general_log');
    $l4Get($jar[$who], $claimId, $fileId);
    $rows = q("SELECT CONVERT(argument USING utf8mb4) AS a FROM mysql.general_log WHERE command_type IN ('Query', 'Execute') AND thread_id <> CONNECTION_ID() ORDER BY event_time, thread_id");

    return array_map(static fn (array $r): string => ap_replace('/\d+/', '0', (string) $r['a']), $rows);
};
$sqlOtherR = $l4Statements('appr', L3_OTHER_DEPT, L4_R_OTHER);
$sqlNoneR  = $l4Statements('appr', L3_NO_CLAIM, L4_NO_FILE);
q('SET GLOBAL general_log = ' . (int) $state['queryLog']['general_log']);
q("SET GLOBAL log_output = '" . $state['queryLog']['log_output'] . "'");
q('TRUNCATE TABLE mysql.general_log');
$state['queryLog'] = null;
$readsFile = static fn (array $sql): bool => array_filter($sql, static fn (string $a): bool => str_contains($a, 'FROM `tblExpenseClaimFiles`')) !== [];
ok(
    'L4-12. …and the SAME DATABASE WORK: the same statements, in the same order, numbers aside — the department read and the receipt read in both',
    $sqlOtherR === $sqlNoneR && $readsFile($sqlNoneR) === true && $readsDepts($sqlNoneR) === true,
    count($sqlOtherR) . ' statements against ' . count($sqlNoneR) . '; first difference: '
        . (string) (array_values(array_diff_assoc($sqlOtherR, $sqlNoneR))[0] ?? array_values(array_diff_assoc($sqlNoneR, $sqlOtherR))[0] ?? 'none')
);

// --- a receipt number that belongs to ANOTHER claim -------------------------
// TRS may open BOTH claims, so only "this receipt is not this claim's" can
// refuse it — and it must look exactly like a receipt that does not exist.
$crossed = $l4Get($jar['trs'], L3_OWN_DEPT, L4_R_OTHER);
$crossedNone = $l4Get($jar['trs'], L3_OWN_DEPT, L4_NO_FILE);
ok(
    'L4-13. a receipt number from ANOTHER claim is refused even for a treasurer who may open both claims: 404, byte for byte a made-up receipt, and none of the file',
    $crossed['status'] === 404 && $l4Same($crossed, $crossedNone) === true && $l4Leaks($crossed) === false,
    $l4Why($crossed)
);
$crossed2 = $l4Get($jar['appr'], L3_OWN_DEPT, L4_R_MEM);
ok(
    'L4-14. …and an approver naming a claim they MAY open with a receipt of one they may not gets the same 404',
    $crossed2['status'] === 404 && $l4Same($crossed2, $l4Get($jar['appr'], L3_OWN_DEPT, L4_NO_FILE)) === true && $l4Leaks($crossed2) === false,
    $l4Why($crossed2)
);

// --- the claim's own generated PDF is not a receipt -------------------------
// A file of its stored name is planted in the receipts folder, a state only a
// restore or a hand edit could make, so that "the row has no stage" is the
// ONLY thing refusing it here.
file_put_contents($l4Folder . $sep . 'claim_' . L3_OWN_DEPT . '.pdf', "%PDF-1.4 the claim's own form\n");
$pdfRow = $l4Get($jar['adm'], L3_OWN_DEPT, L4_R_PDF);
ok(
    'L4-15. the claim\'s GENERATED PDF row (it has a stage) is not served by the receipt address, even with a file of its name in the receipts folder',
    $pdfRow['status'] === 404 && str_contains($pdfRow['body'], 'the claim\'s own form') === false,
    $l4Why($pdfRow)
);

// --- path tricks ------------------------------------------------------------
// Every one asked by an ADMINISTRATOR, who may open the claim, so only the
// path rule can refuse it.
foreach ([
    [L4_R_UP, 'a stored name climbing out of the folder with ../'],
    [L4_R_ABS, 'a stored name that is an absolute path'],
    [L4_R_DOTS, 'a stored name that is ".."'],
    [L4_R_LINK, 'a plain stored name that is a LINK to a file outside the folder'],
    [L4_R_GONE, 'a row whose file is not there'],
] as [$fileId, $words]) {
    $answer = $l4Get($jar['adm'], L3_OWN_DEPT, $fileId);
    ok(
        'L4-16. ' . $words . ': refused with 404 to somebody who may open the claim, and nothing of the key file is sent',
        $answer['status'] === 404 && str_contains($answer['body'], $secretBytes) === false && $l4Header($answer, 'Content-Disposition') === '',
        $l4Why($answer)
    );
}
$l4Logged = (int) one("SELECT COUNT(*) AS n FROM `tblErrors` WHERE `errorCode` = 'RECEIPT_UNAVAILABLE' AND `siteID` = ?", 'i', [ORG_A])['n'];
ok(
    'L4-17. …and each of those five is recorded for an administrator as a receipt that could not be opened (a receipt row with no usable file is lost evidence)',
    $l4Logged === 5,
    $l4Logged . ' record(s)'
);
foreach ([
    'id=' . L4_R_OWN . 'abc'              => 'a number with letters after it (which (int) would read as the real number)',
    'id[]=' . L4_R_OWN                    => 'the number sent as a LIST',
    'id=0' . L4_R_OWN                     => 'the number with a leading zero',
    'id=-' . L4_R_OWN                     => 'a negative number',
    'id=' . L4_R_OWN . '.0'               => 'a decimal number',
    'id=..%2F' . L4_R_OWN                 => 'a path in place of the number',
    'id=' . L4_R_OWN . '%00'              => 'a NUL byte after the number',
] as $query => $words) {
    $answer = req($jar['adm'], 'GET', ADDR_A . '/expenses/receipt?claim=' . L3_OWN_DEPT . '&' . $query);
    ok('L4-18. ' . $words . ': 404, and the receipt is not sent', $answer['status'] === 404 && $l4Leaks($answer) === false, $l4Why($answer));
}
$answer = req($jar['adm'], 'GET', ADDR_A . '/expenses/receipt?claim=' . L3_OWN_DEPT . 'x&id=' . L4_R_OWN);
ok('L4-19. a claim number with letters after it: 404, and the receipt is not sent', $answer['status'] === 404 && $l4Leaks($answer) === false, $l4Why($answer));

// --- a stored label that could break the header ------------------------------
$named = $l4Get($jar['adm'], L3_OWN_DEPT, L4_R_NAME);
$disp  = $l4Header($named, 'Content-Disposition');
ok(
    'L4-20. a label holding quotes, a semicolon, two line breaks and non-ASCII letters gives ONE well-formed header: the plain name only letters, digits, dot, underscore and hyphen, the full name percent-encoded (RFC 5987), and no second header smuggled in',
    $l4Served($named, L4_R_NAME) === true
        && ap_find('/^attachment; filename="[A-Za-z0-9._-]+"; filename\*=UTF-8\'\'[A-Za-z0-9%._~-]+$/D', $disp) === 1
        && ap_findAll('/^Content-Disposition:/mi', $named['head']) === 1
        && ap_find('/^X-Injected:/mi', $named['head']) === 0,
    'disposition: ' . $disp
);
ok(
    'L4-21. …and the encoded name decodes to the label exactly, with only the line breaks taken out',
    ap_find('/filename\*=UTF-8\'\'(\S+)$/', $disp, $enc) === 1 && rawurldecode($enc[1]) === str_replace("\r\n", '', $l4Label),
    'decoded: ' . (isset($enc[1]) === true ? rawurldecode($enc[1]) : '(none)')
);
$typed = $l4Get($jar['adm'], L3_OWN_DEPT, L4_R_TYPE);
ok(
    'L4-22. a stored type with something after it ("text/html; charset=utf-8", over HTML bytes) is sent as application/octet-stream, as an attachment, sandboxed',
    $l4Served($typed, L4_R_TYPE),
    $l4Why($typed)
);

// --- the links ---------------------------------------------------------------
$l4Href = static fn (int $claimId, int $fileId): string => 'href="' . ADDR_A . '/expenses/receipt?claim=' . $claimId . '&amp;id=' . $fileId . '"';
// A row whose stage is EMPTY TEXT rather than NULL: nothing writes one, but
// the claim page's link test and the address's `stage IS NULL` must agree
// about it, or the page offers a link the address answers with 404.
const L4_R_EMPTY = 916874;
$l4Add(L4_R_EMPTY, L3_OWN_DEPT, 'Claim_' . L3_OWN_DEPT . '_Empty_stage.pdf', L3_OWN_DEPT . '_1700000000_receipt5.pdf', 'application/pdf', '', "%PDF-1.4 empty stage\n");
$page = $l3Open('appr', L3_OWN_DEPT);
ok(
    'L4-23. the claim page links each RECEIPT to its address, and draws no link for the generated-PDF row or a row whose stage is empty text — the same rows the address refuses (still listed by name)',
    $page['status'] === 200 && str_contains($page['body'], $l4Href(L3_OWN_DEPT, L4_R_OWN)) === true
        && str_contains($page['body'], 'data-receipt-link="' . L4_R_PDF . '"') === false
        && str_contains($page['body'], 'data-receipt-link="' . L4_R_EMPTY . '"') === false
        && $l4Get($jar['adm'], L3_OWN_DEPT, L4_R_EMPTY)['status'] === 404
        && str_contains($page['body'], 'Claim_' . L3_OWN_DEPT . '_Pending.pdf') === true,
    'HTTP ' . $page['status']
);
$memPage = $l3Open('mem', L3_MEM_CLAIM);
ok(
    'L4-24. the claimant\'s own claim page links their receipt, and following that link gives them the file',
    $memPage['status'] === 200 && str_contains($memPage['body'], $l4Href(L3_MEM_CLAIM, L4_R_MEM)) === true
        && $l4Served(req($jar['mem'], 'GET', html_entity_decode(ap_find('/href="([^"]*expenses\/receipt\?claim=' . L3_MEM_CLAIM . '[^"]*)"/', $memPage['body'], $hm) === 1 ? $hm[1] : '/none', ENT_QUOTES, 'UTF-8')), L4_R_MEM) === true,
    'HTTP ' . $memPage['status']
);
$apprList = req($jar['appr'], 'GET', $approve);
ok(
    'L4-25. the approvals list links the claim number to the claim\'s own page, where the receipts are',
    $apprList['status'] === 200 && str_contains($apprList['body'], 'href="' . ADDR_A . '/expenses/view?id=' . L3_OWN_DEPT . '" data-claim-link="' . L3_OWN_DEPT . '"') === true,
    'HTTP ' . $apprList['status']
);
q("UPDATE `tblExpenseClaims` SET `status` = 'Approved' WHERE `claimID` = ?", 'i', [L3_OTHER_DEPT]);
$trsList = req($jar['trs'], 'GET', ADDR_A . '/expenses/treasury');
q("UPDATE `tblExpenseClaims` SET `status` = 'Pending' WHERE `claimID` = ?", 'i', [L3_OTHER_DEPT]);
ok(
    'L4-26. the treasury list links an approved claim\'s number to the claim\'s own page, which the treasurer can open and whose receipt they can open',
    $trsList['status'] === 200 && str_contains($trsList['body'], 'href="' . ADDR_A . '/expenses/view?id=' . L3_OTHER_DEPT . '" data-claim-link="' . L3_OTHER_DEPT . '"') === true
        && str_contains($l3Open('trs', L3_OTHER_DEPT)['body'], $l4Href(L3_OTHER_DEPT, L4_R_OTHER)) === true,
    'HTTP ' . $trsList['status']
);
$refusedPage = $l3Open('mem', L3_OWN_DEPT);
ok(
    'L4-27. somebody who may not open the claim is shown no receipt address at all (the claim page\'s own 404)',
    $refusedPage['status'] === 404 && str_contains($refusedPage['body'], 'expenses/receipt') === false,
    'HTTP ' . $refusedPage['status']
);

// --- a label wholly in another script keeps its extension -------------------
$l4Add(L4_R_CJK, L3_OWN_DEPT, '領収書.pdf', L3_OWN_DEPT . '_1700000000_receipt4.pdf', 'application/pdf', null, $l4Bytes[L4_R_CJK]);
$cjk = $l4Get($jar['adm'], L3_OWN_DEPT, L4_R_CJK);
ok(
    'L4-28. a label written wholly in another script still gives a plain name WITH its extension (receipt-<n>.pdf), and the full name encoded',
    $l4Served($cjk, L4_R_CJK) === true
        && $l4Header($cjk, 'Content-Disposition') === 'attachment; filename="receipt-' . L4_R_CJK . '.pdf"; filename*=UTF-8\'\'' . rawurlencode('領収書.pdf'),
    'disposition: ' . $l4Header($cjk, 'Content-Disposition')
);

// --- the OTHER organisation's administrator and treasurer, at THEIR OWN address
// (round 1 of the independent check, 11 November 2026.) Both "see every claim"
// on their own organisation, so `mayViewClaim()` says yes to them; on B's
// address `Site::id()` is B, so the ONLY thing between them and A's claim is
// the organisation condition in the statement (`EC.siteID = ?`). L4-9 and
// L4-10 ask on A's address, where that condition can never be reached: with
// it replaced by `? > 0` all 474 checks stayed green while B's administrator
// downloaded A's receipt. These ask on B's address, compared with the same
// person asking for a made-up receipt and claim there.
const L4_BTRS      = 916025; // a member of B only, holding B's treasurer role
const L4_BTRS_ROLE = 916202;
q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [L4_BTRS, 'Selftest access B treasurer', 'u' . L4_BTRS . '.access@selftest.invalid']);
q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 0, 0)', 'ii', [L4_BTRS, ORG_B]);
q("INSERT INTO `tblRoles` (`roleID`, `siteID`, `roleKey`, `roleName`, `isStandard`) VALUES (?, ?, 'treasurer', 'Treasurer', 1)", 'ii', [L4_BTRS_ROLE, ORG_B]);
q('INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES (?, ?, ?)', 'iii', [L4_BTRS, L4_BTRS_ROLE, ORG_B]);
q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [L4_BTRS, 'selftest-access-btrs', $hash]);
$jar['btrs'] = signIn('btrs', ADDR_B);
// ONE CLAIM OF A IN EVERY STATUS THE COLUMN ALLOWS, each with a receipt
// (round 2 of the check): with the condition planted as
// `(EC.siteID = ? OR EC.status <> 'Pending')`, a check made only with a
// Pending claim stayed green while B's people opened every other claim of A.
// The statuses are read from the column itself, so a status added later is
// covered without editing this list; the five known ones must be among them.
$l4StatusType = (string) (one("SELECT `COLUMN_TYPE` AS t FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblExpenseClaims' AND `COLUMN_NAME` = 'status'")['t'] ?? '');
ap_findAll("/'([^']*)'/", $l4StatusType, $l4StatusMatch);
$l4Statuses = $l4StatusMatch[1] ?? [];
if (array_diff(['Pending', 'Approved', 'Rejected', 'Reimbursed', 'Withdrawn'], $l4Statuses) !== []) {
    refuse('the statuses of tblExpenseClaims.status could not be read (' . $l4StatusType . ')');
}
/** status => [claim number, receipt number, the receipt's bytes] */
$l4ByStatus = [];
foreach (array_values($l4Statuses) as $i => $status) {
    $claimId = 916381 + $i;
    $fileId  = 916851 + $i;
    $bytes   = '%PDF-1.4 a receipt of a ' . $status . ' claim ' . bin2hex(random_bytes(8)) . "\n";
    q(
        'INSERT INTO `tblExpenseClaims` (`claimID`, `siteID`, `userID`, `deptID`, `claimTitle`, `claimDate`, `totalAmount`, `status`, `currency`) VALUES (?, ?, ?, ?, ?, CURDATE(), 7.00, ?, \'EUR\')',
        'iiiiss', [$claimId, ORG_A, LEAD, DEPT, 'ACCESS L4 ' . strtoupper($status) . ' CLAIM', $status]
    );
    $l4Add($fileId, $claimId, 'Claim_' . $claimId . '_Receipt_1_2026-11-11.pdf', $claimId . '_1700000000_receipt1.pdf', 'application/pdf', null, $bytes);
    $l4ByStatus[$status] = [$claimId, $fileId, $bytes];
}
foreach (['badm' => 'the ADMINISTRATOR', 'btrs' => 'the TREASURER'] as $who => $words) {
    $crossNone     = req($jar[$who], 'GET', ADDR_B . '/expenses/receipt?claim=' . L3_NO_CLAIM . '&id=' . L4_NO_FILE);
    $crossPageNone = req($jar[$who], 'GET', ADDR_B . '/expenses/view?id=' . L3_NO_CLAIM);
    foreach ($l4ByStatus as $status => [$claimId, $fileId, $bytes]) {
        $cross     = req($jar[$who], 'GET', ADDR_B . '/expenses/receipt?claim=' . $claimId . '&id=' . $fileId);
        $crossPage = req($jar[$who], 'GET', ADDR_B . '/expenses/view?id=' . $claimId);
        ok(
            'L4-29. ' . $words . ' of the other organisation, through THAT organisation\'s own address, asking for this organisation\'s ' . strtoupper($status)
                . ' claim: the receipt is a 404 byte for byte a made-up receipt there with none of the file, and the claim page is a 404 byte for byte a made-up claim there with nothing of the claim',
            $cross['status'] === 404 && $l4Same($cross, $crossNone) === true && str_contains($cross['body'], $bytes) === false && strlen($crossNone['body']) > 200
                && $crossPage['status'] === 404 && $l4Same($crossPage, $crossPageNone) === true
                && str_contains($crossPage['body'], 'ACCESS L4 ' . strtoupper($status) . ' CLAIM') === false && strlen($crossPageNone['body']) > 200,
            'receipt ' . $l4Why($cross) . '; page HTTP ' . $crossPage['status']
        );
    }
}
// …and the same claims DO open for this organisation's own treasurer, so the
// refusals above are about the organisation and not a broken fixture.
$l4OwnOpen = true;
foreach ($l4ByStatus as $status => [$claimId, $fileId, $bytes]) {
    $mine = req($jar['trs'], 'GET', ADDR_A . '/expenses/receipt?claim=' . $claimId . '&id=' . $fileId);
    $l4OwnOpen = $l4OwnOpen && $mine['status'] === 200 && $mine['body'] === $bytes
        && $l3Open('trs', $claimId)['status'] === 200;
}
ok('L4-30. …while this organisation\'s own treasurer opens every one of those claims and receipts on its own address', $l4OwnOpen);
// The positive half, so the two checks above are not "B's administrator and
// treasurer cannot open anything": each opens a claim of B's own through B's
// address, and the treasurer its receipt.
// (DEPT_B is not made until part M, so this part makes its own department of B.)
const L4_B_DEPT    = 916106;
const L4_B_CLAIM   = 916393;
const L4_B_RECEIPT = 916873;
q("INSERT INTO `tblDepts` (`deptID`, `siteID`, `deptName`, `deptCode`, `isActive`) VALUES (?, ?, 'ACCESS L4 B dept', 'L4B', 1)", 'ii', [L4_B_DEPT, ORG_B]);
q(
    "INSERT INTO `tblExpenseClaims` (`claimID`, `siteID`, `userID`, `deptID`, `claimTitle`, `claimDate`, `totalAmount`, `status`, `currency`) VALUES (?, ?, ?, ?, 'ACCESS L4 CLAIM OF B', CURDATE(), 9.00, 'Pending', 'EUR')",
    'iiii', [L4_B_CLAIM, ORG_B, BMEM, L4_B_DEPT]
);
$l4Add(L4_B_RECEIPT, L4_B_CLAIM, 'Claim_' . L4_B_CLAIM . '_Receipt_1_2026-11-11.pdf', L4_B_CLAIM . '_1700000000_receipt1.pdf', 'application/pdf', null, $l4Bytes[L4_B_RECEIPT]);
ok(
    'L4-31. …while B\'s administrator opens B\'s own claim page, and B\'s treasurer B\'s own receipt, through B\'s address',
    req($jar['badm'], 'GET', ADDR_B . '/expenses/view?id=' . L4_B_CLAIM)['status'] === 200
        && $l4Served(req($jar['btrs'], 'GET', ADDR_B . '/expenses/receipt?claim=' . L4_B_CLAIM . '&id=' . L4_B_RECEIPT), L4_B_RECEIPT) === true
);

// --- D-130: every look at an expense record is in the activity log -----------
// The owner (11 November 2026): opening a claim's page, downloading the
// spreadsheet and opening a receipt are each recorded, with who looked and
// which claim (or which export). A refusal records NOTHING, so it leaves the
// same trace as a made-up number — any difference in the log would be the
// existence leak the 404 exists to prevent.
/** The highest log number now, so a check can read only what came after. */
$l4LogMark = static fn (): int => (int) (one('SELECT COALESCE(MAX(`logID`), 0) AS m FROM `tblActivityLogs`')['m'] ?? 0);
/** Every record written since a mark, as "userID|siteID|type|words" lines. @return list<string> */
$l4LogSince = static function (int $mark): array {
    return array_map(
        static fn (array $r): string => (string) $r['userID'] . '|' . (string) $r['siteID'] . '|' . (string) $r['activityType'] . '|' . (string) $r['activityDescription'],
        q('SELECT `userID`, `siteID`, `activityType`, `activityDescription` FROM `tblActivityLogs` WHERE `logID` > ? ORDER BY `logID`', 'i', [$mark])
    );
};
/** Only the expense records among them. @param list<string> $lines @return list<string> */
$l4Expense = static fn (array $lines): array => array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, '|Expense') === true));

$m = $l4LogMark();
$l3Open('appr', L3_OWN_DEPT);
$seen = $l4LogSince($m);
ok(
    'L4-32. opening a claim\'s page writes EXACTLY ONE ExpenseClaimView record, naming the person, in the organisation, with the claim number and nothing else of the claim',
    $l4Expense($seen) === [APPR . '|' . ORG_A . '|ExpenseClaimView|Opened expense claim #' . L3_OWN_DEPT],
    implode(' || ', $seen)
);
$m = $l4LogMark();
$l4Get($jar['appr'], L3_OWN_DEPT, L4_R_OWN);
$seen = $l4LogSince($m);
ok(
    'L4-33. opening a receipt writes EXACTLY ONE ExpenseReceiptOpen record with the receipt and claim numbers — no file name, label or amount',
    $l4Expense($seen) === [APPR . '|' . ORG_A . '|ExpenseReceiptOpen|Opened receipt #' . L4_R_OWN . ' of expense claim #' . L3_OWN_DEPT],
    implode(' || ', $seen)
);
// A refusal and a made-up number: the SAME trace, which is no expense record.
$traceOf = static function (callable $request) use ($l4LogMark, $l4LogSince): array {
    $mark = $l4LogMark();
    $request();

    return $l4LogSince($mark);
};
$refusedR = $traceOf(static fn () => $l4Get($jar['appr'], L3_OTHER_DEPT, L4_R_OTHER));
$noneR    = $traceOf(static fn () => $l4Get($jar['appr'], L3_NO_CLAIM, L4_NO_FILE));
$refusedV = $traceOf(static fn () => $l3Open('mem', L3_OWN_DEPT));
$noneV    = $traceOf(static fn () => $l3Open('mem', L3_NO_CLAIM));
$crossR   = $traceOf(static fn () => req($jar['badm'], 'GET', ADDR_B . '/expenses/receipt?claim=' . L3_OWN_DEPT . '&id=' . L4_R_OWN));
$crossN   = $traceOf(static fn () => req($jar['badm'], 'GET', ADDR_B . '/expenses/receipt?claim=' . L3_NO_CLAIM . '&id=' . L4_NO_FILE));
ok(
    'L4-34. a REFUSED receipt and a made-up one leave the same trace in the log — no expense record — and so does another organisation\'s administrator on their own address',
    $refusedR === $noneR && $l4Expense($refusedR) === [] && $crossR === $crossN && $l4Expense($crossR) === [],
    implode(' || ', $refusedR) . '  ##  ' . implode(' || ', $noneR) . '  ##  ' . implode(' || ', $crossR)
);
ok(
    'L4-35. a REFUSED claim page and a made-up one leave the same trace in the log — no expense record',
    $refusedV === $noneV && $l4Expense($refusedV) === [],
    implode(' || ', $refusedV) . '  ##  ' . implode(' || ', $noneV)
);
/**
 * The spreadsheet, with the log read for the DOWNLOAD ALONE. The form token
 * comes from the approvals page, and since round 3 that page records a look
 * of its own when it lists any claim, so the token is fetched BEFORE the mark.
 * @return array{0: array, 1: list<string>} the answer, and every record the download wrote
 */
$l4Csv = static function (string $who) use (&$jar, $approve, $exportAddress, $l4LogMark, $l4LogSince): array {
    $tok  = token(req($jar[$who], 'GET', $approve)['body']);
    $mark = $l4LogMark();
    $answer = req($jar[$who], 'GET', $exportAddress . '?csrf_token=' . urlencode($tok));

    return [$answer, $l4LogSince($mark)];
};
[$csv, $seen] = $l4Csv('trs');
$exported = $l4Expense($seen);
ok(
    'L4-36. downloading the expenses spreadsheet writes EXACTLY ONE ExpenseClaimsExport record saying what was exported (every department, how many claims) and none of the rows',
    $csv['status'] === 200 && count($exported) === 1
        && ap_find('/^' . TRS . '\|' . ORG_A . '\|ExpenseClaimsExport\|Downloaded the expenses spreadsheet: every department, \d+ claim\(s\)$/D', $exported[0]) === 1,
    'HTTP ' . $csv['status'] . '; ' . implode(' || ', $seen)
);
// APPR downloads the file only while holding the Approver role (L3 took it
// away again), so it is given for this one download and taken back.
$l3Role(APPR, true);
[$csvAppr, $apprSeen] = $l4Csv('appr');
$exported = $l4Expense($apprSeen);
$l3Role(APPR, false);
ok(
    'L4-37. …and for an approver it names the department numbers the file covered',
    $csvAppr['status'] === 200 && $exported === [APPR . '|' . ORG_A . '|ExpenseClaimsExport|Downloaded the expenses spreadsheet: department(s) ' . DEPT . ', '
        . (substr_count(trim($csvAppr['body']), "\n")) . ' claim(s)'],
    implode(' || ', $exported)
);
// The token is fetched FIRST: the Expenses page it comes from records a look
// of its own (the member's list of claims), which is not the download's.
$memTok   = token(req($jar['mem'], 'GET', ADDR_A . '/expenses/submit')['body']);
$refusedX = $traceOf(static fn () => req($jar['mem'], 'GET', $exportAddress . '?csrf_token=' . urlencode($memTok)));
ok(
    'L4-38. a REFUSED spreadsheet download writes no expense record',
    $l4Expense($refusedX) === [],
    implode(' || ', $refusedX)
);
$m    = $l4LogMark();
$list = req($jar['mem'], 'GET', ADDR_A . '/api/expenses/list?status=Pending');
$listed = $l4Expense($l4LogSince($m));
ok(
    'L4-39. the API\'s list of claims (an export of claim rows) writes EXACTLY ONE ExpenseClaimsListApi record saying whose claims, the filter and how many — none of the rows',
    $list['status'] === 200 && count($listed) === 1
        && ap_find('/^' . MEM . '\|' . ORG_A . '\|ExpenseClaimsListApi\|Listed expense claims through the API: own claims, status Pending, page 1 of \d+, ' . count(data($list)['claims'] ?? []) . ' claim\(s\)$/D', $listed[0]) === 1,
    'HTTP ' . $list['status'] . '; ' . implode(' || ', $listed)
);

// --- round 3 of the check: what was pinned by nothing ------------------------
/** Only the expense records a request wrote. @return list<string> */
$l4ExpenseOf = static fn (callable $request): array => $l4Expense($traceOf($request));
// The API list's SCOPE words. With `$everyClaim = true;` deleted every
// request was recorded as "own claims" — untrue in the reassuring direction —
// and nothing noticed, because only a member's own list was tried.
$allList = null;
$allSeen = $l4ExpenseOf(static function () use (&$allList, &$jar): void {
    $allList = req($jar['adm'], 'GET', ADDR_A . '/api/expenses/list?all=true');
});
ok(
    'L4-40. an ADMINISTRATOR\'s ?all=true list is recorded as "every claim of the organisation", with the count it returned',
    $allList['status'] === 200 && count($allSeen) === 1
        && ap_find('/^' . ADM . '\|' . ORG_A . '\|ExpenseClaimsListApi\|Listed expense claims through the API: every claim of the organisation, page 1 of \d+, ' . count(data($allList)['claims'] ?? []) . ' claim\(s\)$/D', $allSeen[0]) === 1
        && count(data($allList)['claims'] ?? []) > 0,
    'HTTP ' . $allList['status'] . '; ' . implode(' || ', $allSeen)
);
$l4Key = 'wbms_' . bin2hex(random_bytes(16));
q(
    "INSERT INTO `tblApiKeys` (`siteID`, `name`, `keyHash`, `keyPrefix`, `scopes`, `isActive`, `createdByID`) VALUES (?, 'Selftest access expenses key', ?, ?, 'expenses:read', 1, NULL)",
    'iss', [ORG_A, hash('sha256', $l4Key), substr($l4Key, 0, 12)]
);
$keyList = null;
$keySeen = $l4ExpenseOf(static function () use (&$keyList, $l4Key): void {
    $keyList = req('', 'GET', ADDR_A . '/api/expenses/list', null, $l4Key);
});
ok(
    'L4-41. a list asked for with an API KEY is recorded as "every claim of the organisation", naming no person (a key has none) but naming the KEY by its number — never its secret',
    $keyList['status'] === 200 && count($keySeen) === 1
        && ap_find('/^\|\d*\|ExpenseClaimsListApi\|Listed expense claims through the API with API key #'
            . (int) (one('SELECT `keyID` FROM `tblApiKeys` WHERE `keyHash` = ?', 's', [hash('sha256', $l4Key)])['keyID'] ?? -1)
            . ': every claim of the organisation, page 1 of \d+, ' . count(data($keyList)['claims'] ?? []) . ' claim\(s\)$/D', $keySeen[0]) === 1
        && str_contains($keySeen[0], $l4Key) === false && str_contains($keySeen[0], substr($l4Key, 5)) === false
        && count(data($keyList)['claims'] ?? []) > 0,
    'HTTP ' . $keyList['status'] . '; ' . implode(' || ', $keySeen)
);
// The CLAIMANT's own looks: the help says "you included".
$ownSeen = $l4ExpenseOf(static function () use ($l3Open, $l4Get, &$jar): void {
    $l3Open('mem', L3_MEM_CLAIM);
    $l4Get($jar['mem'], L3_MEM_CLAIM, L4_R_MEM);
});
ok(
    'L4-42. the CLAIMANT opening their own claim page and their own receipt writes one record for each, naming them',
    $ownSeen === [
        MEM . '|' . ORG_A . '|ExpenseClaimView|Opened expense claim #' . L3_MEM_CLAIM,
        MEM . '|' . ORG_A . '|ExpenseReceiptOpen|Opened receipt #' . L4_R_MEM . ' of expense claim #' . L3_MEM_CLAIM,
    ],
    implode(' || ', $ownSeen)
);
// The spreadsheet's SECOND refusal: somebody allowed the file who sends a
// form token that is no good. L4-38's member never got past the first one.
$badTok = null;
$badSeen = $l4ExpenseOf(static function () use (&$badTok, &$jar, $exportAddress): void {
    $badTok = req($jar['trs'], 'GET', $exportAddress . '?csrf_token=' . str_repeat('0', 64));
});
ok(
    'L4-43. a treasurer sending a form token that is no good is sent back without the file and writes no expense record',
    $badTok['status'] === 302 && str_contains($badTok['body'], 'Claimant') === false && $badSeen === [],
    'HTTP ' . $badTok['status'] . '; ' . implode(' || ', $badSeen)
);

// --- D-130 is ALL expense viewing: the lists, the change form, the reports ----
/** How many claims a list page drew (each claim number is a link since #867). */
$l4Drawn = static fn (string $body): int => ap_findAll('/data-claim-link="\d+"/', $body);
$page = null;
$seen = $l4ExpenseOf(static function () use (&$page, &$jar, $approve): void {
    $page = req($jar['appr'], 'GET', $approve);
});
ok(
    'L4-44. the APPROVALS LIST writes one record with how many claims it showed, and nothing of them',
    $page['status'] === 200 && $l4Drawn($page['body']) > 0
        && $seen === [APPR . '|' . ORG_A . '|ExpenseApprovalsListView|Opened the expense approvals list: ' . $l4Drawn($page['body']) . ' claim(s) shown'],
    'drawn ' . $l4Drawn($page['body']) . '; ' . implode(' || ', $seen)
);
$seen = $l4ExpenseOf(static function () use (&$page, &$jar, $approve): void {
    $page = req($jar['mem'], 'GET', $approve);
});
ok(
    'L4-45. …and an approvals list that showed NO claim (a member with nothing to decide) writes nothing',
    $page['status'] === 200 && $l4Drawn($page['body']) === 0 && $seen === [],
    implode(' || ', $seen)
);
$seen = $l4ExpenseOf(static function () use (&$page, &$jar): void {
    $page = req($jar['trs'], 'GET', ADDR_A . '/expenses/treasury');
});
ok(
    'L4-46. the TREASURY LIST writes one record with how many claims it showed',
    $page['status'] === 200 && $l4Drawn($page['body']) > 0
        && $seen === [TRS . '|' . ORG_A . '|ExpenseTreasuryListView|Opened the expense treasury list: ' . $l4Drawn($page['body']) . ' claim(s) shown'],
    'drawn ' . $l4Drawn($page['body']) . '; ' . implode(' || ', $seen)
);
$seen = $l4ExpenseOf(static function () use (&$page, &$jar): void {
    $page = req($jar['mem'], 'GET', ADDR_A . '/expenses/treasury');
});
ok('L4-47. …and a member REFUSED the treasury list writes nothing', $page['status'] === 403 && $seen === [], 'HTTP ' . $page['status'] . '; ' . implode(' || ', $seen));
$seen = $l4ExpenseOf(static function () use (&$page, &$jar): void {
    $page = req($jar['mem'], 'GET', ADDR_A . '/expenses/submit');
});
$ownShown = ap_findAll('#href="' . preg_quote(ADDR_A, '#') . '/expenses/view\?id=\d+"#', $page['body']);
ok(
    'L4-48. the claimant\'s OWN LIST on the Expenses page writes one record with the page and how many claims it showed',
    $page['status'] === 200 && $ownShown > 0
        && $seen === [MEM . '|' . ORG_A . '|ExpenseOwnClaimsView|Opened their own expense claims list: page 1, ' . $ownShown . ' claim(s) shown'],
    'shown ' . $ownShown . '; ' . implode(' || ', $seen)
);
$seen = $l4ExpenseOf(static function () use (&$page, &$jar): void {
    $page = req($jar['mem'], 'GET', ADDR_A . '/expenses/edit?id=' . L3_MEM_CLAIM);
});
ok(
    'L4-49. the CHANGE FORM of a claim writes one record with the claim number',
    $page['status'] === 200 && $seen === [MEM . '|' . ORG_A . '|ExpenseClaimEditView|Opened the change form of expense claim #' . L3_MEM_CLAIM],
    'HTTP ' . $page['status'] . '; ' . implode(' || ', $seen)
);
$editRefused = $traceOf(static fn () => req($jar['appr'], 'GET', ADDR_A . '/expenses/edit?id=' . L3_MEM_CLAIM));
$editNone    = $traceOf(static fn () => req($jar['appr'], 'GET', ADDR_A . '/expenses/edit?id=' . L3_NO_CLAIM));
ok(
    'L4-50. …and somebody else\'s change form leaves the same trace as a made-up number: no expense record',
    $editRefused === $editNone && $l4Expense($editRefused) === [],
    implode(' || ', $editRefused) . '  ##  ' . implode(' || ', $editNone)
);
// The report builder. A preview of the expenses source records; a preview of
// another source does not (D-130 is about Expenses only); a saved report's
// Run and CSV export already record themselves, with the source.
// The form token is fetched OUTSIDE the traced request, from a page that
// records nothing of Expenses (the builder's own list).
$preview = static function (string $definition, string $tok) use (&$jar): array {
    return req($jar['adm'], 'POST', ADDR_A . '/admin/reports/builder/preview', ['csrf_token' => $tok, 'definition' => $definition]);
};
$builderTok = static fn (): string => token(req($jar['adm'], 'GET', ADDR_A . '/admin/reports/builder')['body']);
$prev = null;
$tok  = $builderTok();
$seen = $l4ExpenseOf(static function () use (&$prev, $preview, $tok): void {
    $prev = $preview('{"v":1,"source":"expenses","columns":["claimTitle","status"]}', $tok);
});
$prevRows = count((json_decode($prev['body'], true)['rows'] ?? []));
ok(
    'L4-51. the report builder\'s PREVIEW of expense claims writes one record with how many rows it showed, and nothing of them',
    $prev['status'] === 200 && $prevRows > 0
        && $seen === [ADM . '|' . ORG_A . '|ExpenseReportPreview|Previewed a report of expense claims: ' . $prevRows . ' row(s) shown'],
    'HTTP ' . $prev['status'] . ' ' . substr($prev['body'], 0, 120) . '; ' . implode(' || ', $seen)
);
$tok  = $builderTok();
$seen = $l4ExpenseOf(static function () use (&$prev, $preview, $tok): void {
    $prev = $preview('{"v":1,"source":"users","columns":["fullName"]}', $tok);
});
ok(
    'L4-52. …and a preview of ANOTHER source writes no expense record (D-130 is about Expenses only)',
    $prev['status'] === 200 && (json_decode($prev['body'], true)['status'] ?? '') === 'ok' && $seen === [],
    'HTTP ' . $prev['status'] . ' ' . substr($prev['body'], 0, 120) . '; ' . implode(' || ', $seen)
);
const L4_REPORT = 916890;
q(
    "INSERT INTO `tblReportDefinitions` (`reportID`, `siteID`, `reportName`, `sourceKey`, `definition`, `isShared`, `createdByID`) VALUES (?, ?, 'ACCESS L4 expenses report', 'expenses', ?, 1, ?)",
    'iisi', [L4_REPORT, ORG_A, '{"v":1,"source":"expenses","columns":["claimTitle","status"]}', ADM]
);
$runSeen = $traceOf(static fn () => req($jar['adm'], 'GET', ADDR_A . '/admin/reports/builder/run?id=' . L4_REPORT));
$expSeen = $traceOf(static fn () => req($jar['adm'], 'GET', ADDR_A . '/admin/reports/builder/export?id=' . L4_REPORT));
ok(
    'L4-53. a saved expenses report\'s RUN and CSV EXPORT each record themselves with the source (ReportRun / ReportExport, source=expenses)',
    in_array(ADM . '|' . ORG_A . '|ReportRun|reportID=' . L4_REPORT . ' source=expenses', $runSeen, true) === true
        && array_filter($expSeen, static fn (string $l): bool => str_starts_with($l, ADM . '|' . ORG_A . '|ReportExport|reportID=' . L4_REPORT . ' source=expenses')) !== [],
    implode(' || ', $runSeen) . '  ##  ' . implode(' || ', $expSeen)
);
q('DELETE FROM `tblReportDefinitions` WHERE `reportID` = ?', 'i', [L4_REPORT]);

// --- round 4 of the check: EXACTLY ONE claim, and NONE ---------------------
// Every look that lists claims records only when it showed at least one. The
// fixtures above all showed several, so `> 0` changed to `> 1` (a ONE-claim
// look recorded nothing — the missed-look direction D-130 cares about) or to
// `>= 0` (an empty list recorded) left every check green. Each kind of list
// is now tried showing exactly one claim, and showing none.
//   L4_ONE1: a member of A, approver of L4_ONE_DEPT only (one claim there, by
//            LEAD), and the owner of exactly one claim.
//   L4_ONE2: a member of A who owns nothing and approves nothing.
const L4_ONE1 = 916026;
const L4_ONE2 = 916027;
const L4_ONE_DEPT = 916107;
const L4_ONE_CLAIM = 916379; // LEAD's claim in L4_ONE_DEPT
const L4_ONE_OWN   = 916380; // L4_ONE1's own claim, in DEPT
$l4OneTitle = 'ACCESS L4 THE ONE CLAIM';
foreach ([L4_ONE1 => ['one1', 'One claim'], L4_ONE2 => ['one2', 'No claims']] as $id => [$who, $name]) {
    q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, 'Selftest access ' . $name, 'u' . $id . '.access@selftest.invalid']);
    q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 0, 0)', 'ii', [$id, ORG_A]);
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-access-' . $who, $hash]);
}
q("INSERT INTO `tblDepts` (`deptID`, `siteID`, `deptName`, `deptCode`, `isActive`) VALUES (?, ?, 'ACCESS L4 one-claim dept', 'L41', 1)", 'ii', [L4_ONE_DEPT, ORG_A]);
q('INSERT INTO `tblUserDepts` (`userID`, `deptID`, `siteID`, `isDeptLead`, `isApprover`, `isMandatoryApprover`) VALUES (?, ?, ?, 0, 1, 0)', 'iii', [L4_ONE1, L4_ONE_DEPT, ORG_A]);
q(
    "INSERT INTO `tblExpenseClaims` (`claimID`, `siteID`, `userID`, `deptID`, `claimTitle`, `claimDate`, `totalAmount`, `status`, `currency`) VALUES "
    . "(?, ?, ?, ?, ?, CURDATE(), 3.00, 'Pending', 'EUR'), (?, ?, ?, ?, 'ACCESS L4 ONE1 OWN CLAIM', CURDATE(), 4.00, 'Pending', 'EUR')",
    'iiiisiiii', [L4_ONE_CLAIM, ORG_A, LEAD, L4_ONE_DEPT, $l4OneTitle, L4_ONE_OWN, ORG_A, L4_ONE1, DEPT]
);
$jar['one1'] = signIn('one1', ADDR_A);
$jar['one2'] = signIn('one2', ADDR_A);
/** Load a page and return [answer, expense records it wrote]. */
$l4Look = static function (string $who, string $path) use (&$jar, $l4ExpenseOf): array {
    $answer = null;
    $seen   = $l4ExpenseOf(static function () use (&$answer, &$jar, $who, $path): void {
        $answer = req($jar[$who], 'GET', $path);
    });

    return [$answer, $seen];
};

// The approvals list (its empty case is L4-45).
[$page, $seen] = $l4Look('one1', $approve);
ok(
    'L4-54. an APPROVALS LIST showing exactly ONE claim writes exactly one record saying 1',
    $page['status'] === 200 && $l4Drawn($page['body']) === 1
        && $seen === [L4_ONE1 . '|' . ORG_A . '|ExpenseApprovalsListView|Opened the expense approvals list: 1 claim(s) shown'],
    'drawn ' . $l4Drawn($page['body']) . '; ' . implode(' || ', $seen)
);
// The treasury list: B's treasurer on B's address, where B has one claim; it
// is made Approved for the one-claim case and Pending again for the empty one.
q("UPDATE `tblExpenseClaims` SET `status` = 'Approved' WHERE `claimID` = ?", 'i', [L4_B_CLAIM]);
$bOne = null;
$bSeen = $l4ExpenseOf(static function () use (&$bOne, &$jar): void {
    $bOne = req($jar['btrs'], 'GET', ADDR_B . '/expenses/treasury');
});
q("UPDATE `tblExpenseClaims` SET `status` = 'Pending' WHERE `claimID` = ?", 'i', [L4_B_CLAIM]);
ok(
    'L4-55. a TREASURY LIST showing exactly ONE claim writes exactly one record saying 1',
    $bOne['status'] === 200 && $l4Drawn($bOne['body']) === 1
        && $bSeen === [L4_BTRS . '|' . ORG_B . '|ExpenseTreasuryListView|Opened the expense treasury list: 1 claim(s) shown'],
    'drawn ' . $l4Drawn($bOne['body']) . '; ' . implode(' || ', $bSeen)
);
$bNone = null;
$bSeen = $l4ExpenseOf(static function () use (&$bNone, &$jar): void {
    $bNone = req($jar['btrs'], 'GET', ADDR_B . '/expenses/treasury');
});
ok(
    'L4-56. …and a treasury list showing NONE writes nothing',
    $bNone['status'] === 200 && $l4Drawn($bNone['body']) === 0 && $bSeen === [],
    'drawn ' . $l4Drawn($bNone['body']) . '; ' . implode(' || ', $bSeen)
);
// The claimant's own list on the Expenses page.
$ownLinks = static fn (string $body): int => ap_findAll('#href="' . preg_quote(ADDR_A, '#') . '/expenses/view\?id=\d+"#', $body);
[$page, $seen] = $l4Look('one1', ADDR_A . '/expenses/submit');
ok(
    'L4-57. the OWN LIST showing exactly ONE claim writes exactly one record saying page 1 and 1',
    $page['status'] === 200 && $ownLinks($page['body']) === 1
        && $seen === [L4_ONE1 . '|' . ORG_A . '|ExpenseOwnClaimsView|Opened their own expense claims list: page 1, 1 claim(s) shown'],
    'shown ' . $ownLinks($page['body']) . '; ' . implode(' || ', $seen)
);
[$page, $seen] = $l4Look('one2', ADDR_A . '/expenses/submit');
ok(
    'L4-58. …and the Expenses page of somebody with NO claims writes nothing',
    $page['status'] === 200 && $ownLinks($page['body']) === 0 && $seen === [],
    implode(' || ', $seen)
);
// The report builder's preview.
$filtered = static fn (string $title): string => (string) json_encode([
    'v' => 1, 'source' => 'expenses', 'columns' => ['claimTitle', 'status'],
    'filters' => ['conjunction' => 'AND', 'rows' => [['col' => 'claimTitle', 'op' => 'eq', 'vals' => [$title]]]],
]);
$tok  = $builderTok();
$seen = $l4ExpenseOf(static function () use (&$prev, $preview, $tok, $filtered, $l4OneTitle): void {
    $prev = $preview($filtered($l4OneTitle), $tok);
});
ok(
    'L4-59. a PREVIEW of expense claims showing exactly ONE row writes exactly one record saying 1',
    count(json_decode($prev['body'], true)['rows'] ?? []) === 1
        && $seen === [ADM . '|' . ORG_A . '|ExpenseReportPreview|Previewed a report of expense claims: 1 row(s) shown'],
    substr($prev['body'], 0, 120) . '; ' . implode(' || ', $seen)
);
$tok  = $builderTok();
$seen = $l4ExpenseOf(static function () use (&$prev, $preview, $tok, $filtered): void {
    $prev = $preview($filtered('ACCESS L4 NO SUCH CLAIM TITLE'), $tok);
});
ok(
    'L4-60. …and a preview of expense claims showing NO row writes nothing',
    (json_decode($prev['body'], true)['status'] ?? '') === 'ok' && count(json_decode($prev['body'], true)['rows'] ?? []) === 0 && $seen === [],
    substr($prev['body'], 0, 120) . '; ' . implode(' || ', $seen)
);
// The API's list: the same rule (round 4 made it agree with the pages).
[$page, $seen] = $l4Look('one1', ADDR_A . '/api/expenses/list');
ok(
    'L4-61. the API list returning exactly ONE claim writes exactly one record saying 1',
    count(data($page)['claims'] ?? []) === 1
        && $seen === [L4_ONE1 . '|' . ORG_A . '|ExpenseClaimsListApi|Listed expense claims through the API: own claims, page 1 of 1, 1 claim(s)'],
    implode(' || ', $seen)
);
[$page, $seen] = $l4Look('one2', ADDR_A . '/api/expenses/list');
ok(
    'L4-62. …and the API list returning NO claim writes nothing',
    $page['status'] === 200 && count(data($page)['claims'] ?? ['x']) === 0 && $seen === [],
    'HTTP ' . $page['status'] . '; ' . implode(' || ', $seen)
);
// The spreadsheet: L4_ONE1 holding the Approver role reaches one department
// with one claim in it; L4_ONE2 holding it reaches none (the heading row).
$l3Role(L4_ONE1, true);
$l3Role(L4_ONE2, true);
[$oneCsv, $oneCsvSeen]   = $l4Csv('one1');
[$noneCsv, $noneCsvSeen] = $l4Csv('one2');
$l3Role(L4_ONE1, false);
$l3Role(L4_ONE2, false);
ok(
    'L4-63. a SPREADSHEET holding exactly ONE claim writes exactly one record saying 1',
    $oneCsv['status'] === 200 && substr_count(trim($oneCsv['body']), "\n") === 1
        && $l4Expense($oneCsvSeen) === [L4_ONE1 . '|' . ORG_A . '|ExpenseClaimsExport|Downloaded the expenses spreadsheet: department(s) ' . L4_ONE_DEPT . ', 1 claim(s)'],
    'HTTP ' . $oneCsv['status'] . '; ' . implode(' || ', $oneCsvSeen)
);
ok(
    'L4-64. …and a spreadsheet holding NO claim (the heading row alone) writes nothing',
    $noneCsv['status'] === 200 && substr_count(trim($noneCsv['body']), "\n") === 0 && $l4Expense($noneCsvSeen) === [],
    'HTTP ' . $noneCsv['status'] . '; ' . implode(' || ', $noneCsvSeen)
);
// The change form of a claim its owner can NO LONGER change still records:
// the help promises the claim's number whatever its state.
[$page, $seen] = $l4Look('mem', ADDR_A . '/expenses/edit?id=916308');
ok(
    'L4-65. the CHANGE FORM of the person\'s own claim that can NO LONGER be changed (already decided) still writes one record with the claim number',
    $page['status'] === 200 && str_contains($page['body'], 'name="claimTitle"') === false
        && in_array((string) (one('SELECT `status` FROM `tblExpenseClaims` WHERE `claimID` = 916308')['status'] ?? ''), ['Approved', 'Reimbursed', 'Rejected'], true) === true
        && $seen === [MEM . '|' . ORG_A . '|ExpenseClaimEditView|Opened the change form of expense claim #916308'],
    'HTTP ' . $page['status'] . '; ' . implode(' || ', $seen)
);
q('DELETE FROM `tblExpenseClaims` WHERE `claimID` IN (?, ?)', 'ii', [L4_ONE_CLAIM, L4_ONE_OWN]);
q('DELETE FROM `tblUserDepts` WHERE `deptID` = ?', 'i', [L4_ONE_DEPT]);
q('DELETE FROM `tblDepts` WHERE `deptID` = ?', 'i', [L4_ONE_DEPT]);

q('DELETE FROM `tblErrors` WHERE `errorCode` = ? AND `siteID` = ?', 'si', ['RECEIPT_UNAVAILABLE', ORG_A]);
q('DELETE FROM `tblExpenseClaimFiles` WHERE `fileID` BETWEEN ? AND ?', 'ii', [L4_R_OWN, L4_NO_FILE]);
q('DELETE FROM `tblExpenseClaims` WHERE `claimID` = ?', 'i', [L4_B_CLAIM]);
// The one-claim-per-status claims (their receipt rows go with them, by the link's cascade) and the API key.
q('DELETE FROM `tblExpenseClaims` WHERE `claimID` BETWEEN 916381 AND 916389');
q('DELETE FROM `tblApiKeys` WHERE `keyHash` = ?', 's', [hash('sha256', $l4Key)]);
q('DELETE FROM `tblDepts` WHERE `deptID` = ?', 'i', [L4_B_DEPT]);
// L4_BTRS stays signed in and is removed with the rest of the made-up world;
// its role row goes now, so no later part meets a treasurer of B it did not make.
q('DELETE FROM `tblUserRoles` WHERE `roleID` = ?', 'i', [L4_BTRS_ROLE]);
q('DELETE FROM `tblRoles` WHERE `roleID` = ?', 'i', [L4_BTRS_ROLE]);

q('DELETE FROM tblUserRoles WHERE roleID = ?', 'i', [L3_ROLE]);
q('DELETE FROM tblRoles WHERE roleID = ?', 'i', [L3_ROLE]);
q('DELETE FROM tblExpenseClaimItems WHERE claimID IN (?, ?, ?)', 'iii', [L3_OWN_DEPT, L3_OTHER_DEPT, L3_MEM_CLAIM]);
q('DELETE FROM tblExpenseClaims WHERE claimID IN (?, ?, ?)', 'iii', [L3_OWN_DEPT, L3_OTHER_DEPT, L3_MEM_CLAIM]);
q('DELETE FROM tblDepts WHERE deptID = ?', 'i', [L3_DEPT]);

// =============================================================================
head('M. #587 — withdrawing a claim through the real page, reading back what is STORED');
// =============================================================================
/** One claim for this part, in any state and either organisation. */
$mClaim = static function (int $id, int $by, string $title, string $status = 'Pending', int $site = ORG_A, int $dept = DEPT): void {
    q(
        'INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status, `currency`) VALUES (?, ?, ?, ?, ?, CURDATE(), 12.50, ?, \'EUR\')',
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
    ap_findAll('#<div class="alert alert-(\w+) alert-dismissible fade show">\s*(.*?)\s*<button#s', $body, $found, PREG_SET_ORDER);

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
        if (count($cells) === 7 && ap_find('/^[A-Za-z0-9,-]+$/', $cells[6]) === 1 && is_file($file) === true) {
            return file_put_contents($file, 'flash_msg|' . serialize($words) . 'flash_type|' . serialize('warning'), FILE_APPEND) !== false;
        }
    }

    return false;
};
/**
 * The claim numbers a list page shows, in order. Since #867 each number is a
 * LINK to the claim's own page (`<a href=…>#123</a>`), so the link's opening
 * tag may sit between the label and the number; without that this read an
 * empty list on both pages and the before/after comparisons below became
 * `[] === []`.
 */
$listed = static function (string $who, string $address): array {
    ap_findAll('#</span>(?:<a\b[^>]*>)?\#(\d+)#', req($who, 'GET', $address)['body'], $found);
    $ids = array_map('intval', $found[1]);
    sort($ids);

    return $ids;
};
/** The number on one of the dashboard's cards, or null when the card is not shown. */
$card = static function (string $who, string $label): ?int {
    $body = req($who, 'GET', ADDR_A . '/dashboard')['body'];

    return ap_find('#<div class="h4 mb-0 fw-bold">(\d+)</div>\s*<small class="text-muted">' . preg_quote($label, '#') . '</small>#', $body, $m) === 1 ? (int) $m[1] : null;
};
/** Admin → Reports, as the administrator sees it: the two totals, and the "by status" bars. */
$reports = static function () use ($jar): array {
    $body    = req($jar['adm'], 'GET', ADDR_A . '/admin/reports')['body'];
    $section = ap_find('#Expense Claims by Status</h5>(.*?)<!-- 📊 Top Activity Types#s', $body, $m) === 1 ? $m[1] : '';
    ap_findAll('#<span>([A-Za-z]*)</span>\s*<span>(\d+) \(\d+%\)</span>#', $section, $bars);

    return [
        'claims' => ap_find('#<div class="h3 mb-0 text-warning">([\d,]+)</div>\s*<small class="text-muted">Expense Claims</small>#', $body, $m) === 1 ? $m[1] : '(not shown)',
        'value'  => ap_find('#Total value: <strong>€([\d,.]+)</strong>#', $section, $m) === 1 ? $m[1] : '(not shown)',
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
    foreach ((array) ap_split('/\R/', $answer['body']) as $line) {
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
q("INSERT INTO tblExpenseClaimFiles (claimID, fileLabel, storedFilename) VALUES (916311, 'Claim_916311_Receipt_1_2026-11-03.jpg', 'access-w-receipt.jpg')");
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
    ap_find('#<span class="badge bg-secondary fs-5">\s*Withdrawn\s*</span>#', $page) === 1 && str_contains($page, 'Withdraw Claim') === false && str_contains($page, 'expenses/withdraw/save') === false
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
        && ap_find('/^[\d,]+$/', $after['reports']['claims']) === 1 && ap_find('/^[\d,]+\.\d\d$/', $after['reports']['value']) === 1 && (float) str_replace(',', '', $after['reports']['value']) >= 12.5,
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
ok('an approver who opens the withdrawn claim sees the Withdrawn badge and is offered no "Go to Approvals"', str_contains($page, 'ACCESS-W claim to withdraw') === true && ap_find('#<span class="badge bg-secondary fs-5">\s*Withdrawn\s*</span>#', $page) === 1 && str_contains($page, 'Go to Approvals') === false);
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

// --- M5b: #642 — a malformed decision must record NOTHING -----------------------
// (M5b rather than M6: M1 to M9 were already in use, and this sits straight after
//  M5. Two blocks were both labelled M6 for a while, which a review spotted.)
//
// This is the BEHAVIOUR half of #642, through the real page over HTTP. The
// handler used to read the decision as
//     ($_POST['decision'] ?? '') === 'Rejected' ? 'Rejected' : 'Approved'
// so everything that was not the exact word "Rejected" recorded an APPROVAL —
// and if it was the last approval a claim needed, a claim meant to be rejected
// became payable.
//
// Why it is here and not only in tools/expense-rules-selftest.php: that file
// reads the CODE, so it can only ever say the code looks right. These checks
// post real requests with a real signed-in approver and read the database back,
// which is the only thing that proves the page refuses them. (The first version
// of this work claimed no such harness existed and left the behaviour untested;
// a review on 27 October 2026 pointed out that this file already posts to this
// exact address, so it was a handful of lines all along.)
$mClaim(916321, MEM, 'ACCESS-642 decision sent as a list');
$mClaim(916322, MEM, 'ACCESS-642 no decision sent at all');
$mClaim(916323, MEM, 'ACCESS-642 decision in the wrong case');
$mClaim(916324, MEM, 'ACCESS-642 claim number sent as a list');
$mClaim(916325, MEM, 'ACCESS-642 control, a real rejection');

$before642 = [
    916321 => $storedAll(916321),
    916322 => $storedAll(916322),
    916323 => $storedAll(916323),
    916324 => $storedAll(916324),
];

// A request chooses the TYPE of what it sends as well as the value, and any
// non-empty array read as a number is 1. This is the shape that was a live
// two-factor bypass in this codebase a week before #642 was found.
$message($jar['appr']);
post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916321, 'decision' => ['Rejected'], 'comments' => 'sent as a list']);
$said = $message($jar['appr']);
ok(
    'M5b #642 a decision sent as a LIST records nothing, and says so: the claim is untouched and still Pending',
    $storedAll(916321) === $before642[916321] && claimState(916321) === 'Pending, 0 approval(s), 0 payment(s)'
        && str_contains($said, 'No decision was recorded') === true,
    $said . '; claim: ' . claimState(916321)
);

post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916322, 'comments' => 'no decision field']);
$said = $message($jar['appr']);
ok(
    'M5b #642 NO decision field at all records nothing — this used to record an approval',
    $storedAll(916322) === $before642[916322] && claimState(916322) === 'Pending, 0 approval(s), 0 payment(s)'
        && str_contains($said, 'No decision was recorded') === true,
    $said . '; claim: ' . claimState(916322)
);

post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916323, 'decision' => 'rejected', 'comments' => 'wrong case']);
$said = $message($jar['appr']);
ok(
    'M5b #642 "rejected" in the wrong case records nothing — this used to record an APPROVAL of a claim somebody meant to reject',
    $storedAll(916323) === $before642[916323] && claimState(916323) === 'Pending, 0 approval(s), 0 payment(s)'
        && str_contains($said, 'No decision was recorded') === true,
    $said . '; claim: ' . claimState(916323)
);

// The claim NUMBER, for the same reason: `(int) ['x']` is 1, silently, so this
// used to act on whichever claim is number 1 rather than the one sent.
post($jar['appr'], $approve, $approve . '/save', ['claimID' => [916324], 'decision' => 'Rejected', 'comments' => 'number as a list']);
$said = $message($jar['appr']);
ok(
    'M5b #642 a claim NUMBER sent as a list records nothing against that claim, nor against claim 1',
    $storedAll(916324) === $before642[916324]
        && (one('SELECT COUNT(*) AS n FROM tblExpenseClaimApprovals WHERE claimID = 1')['n'] ?? 0) === 0
        && str_contains($said, 'Invalid claim ID') === true,
    $said . '; claim: ' . claimState(916324)
);

// CONTROL. Without this the four above would also pass on a page that refuses
// every decision, which would be its own serious fault.
post($jar['appr'], $approve, $approve . '/save', ['claimID' => 916325, 'decision' => 'Rejected', 'comments' => 'a real rejection']);
ok(
    'M5b #642 CONTROL: a properly spelled rejection still works, so the guard refuses only what it should',
    claimState(916325) === 'Rejected, 1 approval(s), 0 payment(s)',
    claimState(916325)
);

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
    "INSERT INTO tblExpenseClaimFiles (claimID, fileLabel, storedFilename, fileType, stage) VALUES "
    . "(916311, 'Claim_916311_Pending.pdf', 'claim_916311.pdf', 'application/pdf', 'Pending'), "
    . "(916312, 'Claim_916312_Pending.pdf', 'claim_916312.pdf', 'application/pdf', 'Pending'), "
    . "(916312, 'Claim_916312_Approved.pdf', 'claim_916312.pdf', 'application/pdf', 'Approved')"
);
$page = $view($jar['mem'], 916311);
ok(
    'a WITHDRAWN claim\'s page says its stored document was last generated for status Pending and was not remade, and nowhere says it was generated for Withdrawn',
    str_contains($page, '(Last generated for status: Pending. It was not remade when the claim was withdrawn.)') === true && str_contains($page, 'Last generated for status: Withdrawn') === false,
    ap_find('#\(Last generated for status:[^)]*\)#', $page, $m) === 1 ? 'the page says: ' . $m[0] : 'the page has no such line'
);
$pageApproved = $view($jar['mem'], 916312);
$pagePaid     = $view($jar['mem'], 916313);
ok(
    '…an approved claim\'s page names the NEWEST document\'s stage (Approved, not the earlier Pending), and a document with no recorded stage gets no such line at all',
    str_contains($pageApproved, '(Last generated for status: Approved)') === true && str_contains($pagePaid, 'claim_916313.pdf') === true && str_contains($pagePaid, 'Last generated for status') === false,
    (ap_find('#\(Last generated for status:[^)]*\)#', $pageApproved, $m) === 1 ? 'approved claim: ' . $m[0] : 'approved claim: no such line')
        . (ap_find('#\(Last generated for status:[^)]*\)#', $pagePaid, $m) === 1 ? '; paid claim: ' . $m[0] : '; paid claim: no such line')
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
head('N. #514 part 9 — the dashboard card for imported events waiting for approval');
// =============================================================================
// Two events of A's switched-on outside calendar are waiting, and one of its
// paused calendar. The card must say 2 (a paused calendar's event can be
// shown to nobody whatever is decided, so nobody is nudged about it).
siteSetting(ORG_A, 'approvals.enabled', '1');
q(
    "INSERT INTO tblExternalFeeds (feedID, siteID, name, url, isActive) VALUES (?, ?, 'ACCESS outside calendar', 'https://calendar.selftest.invalid/access-on.ics', 1), "
    . "(?, ?, 'ACCESS paused outside calendar', 'https://calendar.selftest.invalid/access-paused.ics', 0)",
    'iiii', [FEED_N_ON, ORG_A, FEED_N_PAUSED, ORG_A]
);
foreach ([[EV_N1, FEED_N_ON, APPR_N1], [EV_N2, FEED_N_ON, APPR_N2], [EV_N3, FEED_N_PAUSED, APPR_N3]] as [$eventId, $feedId, $approvalId]) {
    q(
        'INSERT INTO tblEvents (eventID, siteID, externalFeedID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
        . "VALUES (?, ?, ?, ?, ?, 'ACCESS description', ?, ?, 'UTC', 'published', 0, 0)",
        'iiissss', [$eventId, ORG_A, $feedId, 'access-waiting-' . $eventId, IMPORT_MARK . ' ' . $eventId, $eventDay . ' 10:00:00', $eventDay . ' 11:00:00']
    );
    q(
        'INSERT INTO tblExternalEventApprovals (approvalID, siteID, feedID, eventID, origin, originID, requestHash, contentHash, status, reason, requestedLevel, '
        . 'requestedDetail, requestedWebsite, requestedAudienceSummary, snapTitle, snapStart, snapEnd, snapTimezone, snapIsAllDay, createdAt) '
        . "VALUES (?, ?, ?, ?, 'rule', 1, ?, ?, 'pending', 'new_match', 'public', 'basic', 0, '', ?, ?, ?, 'UTC', 0, UTC_TIMESTAMP())",
        'iiiisssss',
        [$approvalId, ORG_A, $feedId, $eventId, hash('sha256', 'access-request-' . $approvalId), hash('sha256', 'access-content-' . $approvalId), IMPORT_MARK . ' ' . $eventId, $eventDay . ' 10:00:00', $eventDay . ' 11:00:00']
    );
}
// ("portal-dashboard-hero" in the checks below is the dashboard's own opening
// block: it shows that the page read really is the dashboard, so that "the
// card is not there" cannot be passed by an error page.)
/** The card's words, as the dashboard writes them. */
$importsLabel = 'Imported events waiting for approval';
/** The whole card as the dashboard draws it: its link, then its number, then its words. Gives the link and the number, or null. */
$importsCard = static function (string $body) use ($importsLabel): ?array {
    return ap_find(
        '#<a href="([^"]*)" class="text-decoration-none">\s*<div class="card border-warning h-100">(?:(?!</a>).)*?<div class="h4 mb-0 fw-bold">(\d+)</div>\s*<small class="text-muted">'
        . preg_quote($importsLabel, '#') . '</small>#s',
        $body, $m
    ) === 1 ? ['url' => html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), 'value' => (int) $m[2]] : null;
};
$dashAdm = req($jar['adm'], 'GET', ADDR_A . '/dashboard');
ok(
    'A\'s administrator: the dashboard shows the card, with the number 2 (the paused calendar\'s event is not counted) and a link that keeps A\'s part of the address: '
    . ADDR_A . '/approvals#imports',
    $dashAdm['status'] === 200 && $importsCard($dashAdm['body']) === ['url' => ADDR_A . '/approvals#imports', 'value' => 2],
    'HTTP ' . $dashAdm['status'] . '; the card read: ' . json_encode($importsCard($dashAdm['body']))
);
$dashMem = req($jar['mem'], 'GET', ADDR_A . '/dashboard');
ok(
    'a member of A: the dashboard page holds NO trace of it: not the card, not its words, not its link, not a waiting event\'s title',
    $dashMem['status'] === 200 && str_contains($dashMem['body'], $importsLabel) === false && str_contains($dashMem['body'], 'approvals#imports') === false
    && str_contains($dashMem['body'], IMPORT_MARK) === false && str_contains($dashMem['body'], 'portal-dashboard-hero') === true,
    'HTTP ' . $dashMem['status'] . (str_contains($dashMem['body'], $importsLabel) === true ? '; the card\'s words ARE in the member\'s page' : '')
);
$dashBOwn = req($jar['badm'], 'GET', ADDR_B . '/dashboard');
$dashBatA = req($jar['badm'], 'GET', ADDR_A . '/dashboard');
ok(
    'B\'s administrator: their own dashboard (B has nothing waiting) shows no card, and A\'s address refuses them (403) with nothing of A\'s card in the answer',
    $dashBOwn['status'] === 200 && str_contains($dashBOwn['body'], $importsLabel) === false && str_contains($dashBOwn['body'], 'approvals#imports') === false
    && $dashBatA['status'] === 403 && str_contains($dashBatA['body'], $importsLabel) === false && str_contains($dashBatA['body'], IMPORT_MARK) === false,
    'own dashboard HTTP ' . $dashBOwn['status'] . '; A\'s address HTTP ' . $dashBatA['status']
);
// Both are decided: only the paused calendar's event is left waiting.
q("UPDATE tblExternalEventApprovals SET status = 'approved' WHERE approvalID IN (?, ?)", 'ii', [APPR_N1, APPR_N2]);
$dashNone = req($jar['adm'], 'GET', ADDR_A . '/dashboard');
ok(
    'once both are decided the card is gone from the administrator\'s dashboard (not a card saying 0), though the paused calendar\'s event is still waiting',
    $dashNone['status'] === 200 && str_contains($dashNone['body'], $importsLabel) === false && str_contains($dashNone['body'], 'portal-dashboard-hero') === true
    && (int) (one("SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE siteID = ? AND status = 'pending'", 'i', [ORG_A])['n'] ?? 0) === 1,
    'HTTP ' . $dashNone['status']
);

// --- N: the copy of "my data" --------------------------------------------------
// The scheduled job's memory of who has been told is a row about a person,
// so the page that hands somebody everything held about them must hand that
// over too. One row is planted for A's administrator, as the job would write it.
q(
    "INSERT INTO tblExternalApprovalNotices (siteID, userID, emailLocalDate, emailOutcome, emailAt, pushUpToApprovalID, pushAt) VALUES (?, ?, ?, 'sent', UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())",
    'iisi', [ORG_A, ADM, $eventDay, APPR_N2]
);
$exportOf = static function (string $who): ?array {
    $answer = req($who, 'GET', ADDR_A . '/account/data-export');
    $data   = json_decode($answer['body'], true);

    return $answer['status'] === 200 && is_array($data) === true ? ($data['data']['importedEventNotices'] ?? null) : null;
};
$exportAdm = $exportOf($jar['adm']);
ok(
    'the copy of "my data": A\'s administrator is handed the record of when they were told, with the organisation, the day (on its own calendar) and what became of the e-mail',
    is_array($exportAdm) === true && count($exportAdm) === 1 && (int) $exportAdm[0]['siteID'] === ORG_A && $exportAdm[0]['siteName'] === 'Selftest access A'
    && $exportAdm[0]['lastEmailDay'] === $eventDay && $exportAdm[0]['lastEmailOutcome'] === 'sent' && ap_find('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $exportAdm[0]['lastPushAtUtc']) === 1
    && array_keys($exportAdm[0]) === ['siteID', 'siteName', 'lastEmailDay', 'lastEmailOutcome', 'lastEmailClaimedAtUtc', 'lastPushAtUtc'],
    json_encode($exportAdm)
);
ok('…and a member, who is never told, is handed an empty list there, not somebody else\'s record', $exportOf($jar['mem']) === [], json_encode($exportOf($jar['mem'])));

// --- N: Save on the notification preferences page ---------------------------------
// Push is not set up in this portal, so the page shows none of the push
// switches. Pressing Save must not switch a push choice off because of that:
// not the new one (imported events waiting for approval), and not the two
// older ones ("We're live now" and "Service reminders"), which it used to.
//
// 🔖 SINCE #667 THE PAGE CARRIES NOTHING FOR THEM, and that is the point. The
//    save used to store "off" for every listed key the form did not send, so
//    the page carried each ON push choice in a hidden field. Now the save
//    changes only the switches a form MARKS as drawn (`prefsShown[<key>]`),
//    so an undrawn push choice is left exactly as stored — including "not
//    stored", which every reader takes as ON. These two checks pin that: no
//    push field or marker on the page, and every push choice as it was.
/** Every field a browser would send when Save is pressed on the page as shown: ticked boxes, chosen buttons and hidden fields. */
$fieldsOf = static function (string $html): array {
    $fields = [];
    ap_findAll('/<input\b[^>]*>/i', $html, $inputs);
    foreach ($inputs[0] as $input) {
        if (ap_find('/\bname="([^"]+)"/', $input, $n) !== 1 || ap_find('/\bvalue="([^"]*)"/', $input, $v) !== 1) {
            continue;
        }
        $type = ap_find('/\btype="([^"]+)"/', $input, $t) === 1 ? strtolower($t[1]) : 'text';
        if (($type === 'checkbox' || $type === 'radio') && ap_find('/\bchecked\b/', $input) !== 1) {
            continue;
        }
        $fields[html_entity_decode($n[1], ENT_QUOTES, 'UTF-8')] = html_entity_decode($v[1], ENT_QUOTES, 'UTF-8');
    }

    return $fields;
};
/** The three push choices and "Approval requests", as stored for A's administrator: true, false, or "not stored". */
$storedChoices = static function (): array {
    $prefs = (array) json_decode((string) (one('SELECT notifyPrefs FROM tblUsers WHERE userID = ?', 'i', [ADM])['notifyPrefs'] ?? 'null'), true);
    $out   = [];
    foreach (['pushLivestream', 'pushServiceReminders', 'pushApprovals', 'approvalRequests'] as $key) {
        $out[$key] = $prefs[$key] ?? 'not stored';
    }

    return $out;
};
/** Which of the three push choices the page carries at all — as a ticked field OR as a "this form drew me" marker. */
$carried = static fn (array $fields): array => array_values(array_filter(
    ['pushLivestream', 'pushServiceReminders', 'pushApprovals'],
    static fn (string $key): bool => isset($fields['prefs[' . $key . ']']) === true || isset($fields['prefsShown[' . $key . ']']) === true
));
q('UPDATE tblUsers SET notifyPrefs = NULL WHERE userID = ?', 'i', [ADM]);
$prefsPage = req($jar['adm'], 'GET', ADDR_A . '/account/notifications');
$fields    = $fieldsOf($prefsPage['body']);
$saved     = req($jar['adm'], 'POST', ADDR_A . '/account/notifications/save', $fields);
// "approvalRequests" is drawn and ticked (nothing stored reads as on), so it
// IS written — which is what proves the save really happened, rather than
// "not stored" for the push choices meaning the post was refused.
ok(
    'notification preferences, push not set up: the page shows no push switch and carries nothing for any of the three, and pressing Save leaves '
    . '"pushLivestream", "pushServiceReminders" and "pushApprovals" exactly as stored (here: not stored, which reads as ON) while it saves "Approval requests" (#667)',
    $prefsPage['status'] === 200 && str_contains($prefsPage['body'], 'Browser push notifications are not configured') === true
    && $carried($fields) === [] && ($fields['prefsShown[approvalRequests]'] ?? '') === '1' && isset($fields['csrf_token']) === true && $saved['status'] === 302
    && $storedChoices() === ['pushLivestream' => 'not stored', 'pushServiceReminders' => 'not stored', 'pushApprovals' => 'not stored', 'approvalRequests' => true],
    'page HTTP ' . $prefsPage['status'] . '; save HTTP ' . $saved['status'] . '; carried: ' . json_encode($carried($fields)) . '; stored: ' . json_encode($storedChoices())
);
q('UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?', 'si', ['{"pushApprovals":false,"pushLivestream":false,"pushServiceReminders":true,"approvalRequests":true}', ADM]);
$prefsPage = req($jar['adm'], 'GET', ADDR_A . '/account/notifications');
$fields    = $fieldsOf($prefsPage['body']);
$saved     = req($jar['adm'], 'POST', ADDR_A . '/account/notifications/save', $fields);
ok(
    '…and a push choice somebody had switched OFF stays off: nothing is carried for any of the three, and Save leaves each as it was, '
    . 'with "Approval requests" still on',
    $carried($fields) === [] && ($fields['prefs[approvalRequests]'] ?? '') === '1' && $saved['status'] === 302
    && $storedChoices() === ['pushLivestream' => false, 'pushServiceReminders' => true, 'pushApprovals' => false, 'approvalRequests' => true],
    'save HTTP ' . $saved['status'] . '; carried: ' . json_encode($carried($fields)) . '; stored: ' . json_encode($storedChoices())
);
q('UPDATE tblUsers SET notifyPrefs = NULL WHERE userID = ?', 'i', [ADM]);

// =============================================================================
head('O. #677 — each organisation has its OWN postal address and support e-mail');
// =============================================================================
/**
 * THE ACCEPTANCE TEST FOR #677, which is a question about two parties rather
 * than one setting: "two organisations on one installation can each hold their
 * own address, and neither sees the other's".
 *
 * All nine `org.*` keys and `portal.support.email` used to be written with
 * nothing in `siteID` — the row EVERY organisation uses — on a page called
 * Organisation settings. Only the portal's own web address (`site.url`) really
 * is shared, because it decides where every e-mailed link points (#500).
 *
 * Two things here are easy to get wrong and are checked deliberately:
 *
 *   * **An ordinary administrator's Save must not touch `site.url`.** The page
 *     disables that box for them, and a disabled control IS NOT SUBMITTED, so
 *     the handler sees no `portalAddress` field. Had it read the value instead
 *     of asking whether the field arrived, an absent box would read as empty,
 *     `PortalAddress::check()` accepts empty as "clear it", and an ordinary
 *     administrator pressing Save would have wiped the portal's address for
 *     every organisation. That is the #667 shape, and it is what the second
 *     group of checks below exists for.
 *   * **The support e-mail falls back to the installation's value.** An
 *     organisation that has not set one must still get a Support link in its
 *     e-mails, which is why migration 231 leaves the installation's row alone
 *     rather than copying it down to everybody.
 */
$orgPage = ADDR_A . '/admin/settings/organisation';
$orgSave = ADDR_A . '/admin/settings/organisation/save';
$bOrgPage = ADDR_B . '/admin/settings/organisation';
$bOrgSave = ADDR_B . '/admin/settings/organisation/save';

/** One setting's value at one level, read straight out of the database. */
$settingAt = static function (string $key, ?int $site): string {
    $row = $site === null
        ? one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key])
        : one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = ?', 'si', [$key, $site]);

    return $row === null ? '(no row)' : (string) $row['settingValue'];
};

/** Post the postal-address form as `$who`, on `$page`/`$save`. */
$savePostal = static function (string $who, string $page, string $save, string $line1, string $city) use (&$jar): array {
    return post($jar[$who], $page, $save, [
        'addressLine1' => $line1,
        'addressLine2' => '',
        'city'         => $city,
        'region'       => '',
        'postcode'     => 'CB1 1AA',
        'countryCode'  => 'GB',
        'latitude'     => '',
        'longitude'    => '',
        'what3words'   => '',
    ]);
};

// Start from a known installation-wide value, so "unchanged" means something.
//
// WHAT IS PUT BACK, AND WHY IT HAS TO BE. These four rows are SEEDED by
// full_schema.sql, so they belong to the database this test was handed rather
// than to the made-up world it builds. The first version of this part simply
// deleted them at the end, which left the four rows ABSENT — a fresh install
// has them and the database did not any more. Worse, the suite's closing
// clean-up check could not see it: that check compares only the keys recorded
// by the `setting()` helper, and this part writes with raw `q()`, so the run
// still reported every check passing while the fixture had been quietly made
// poorer. Found by the independent check of 30 October 2026.
//
// So: read whatever is there first, and put exactly that back at the end —
// including "there was no row", which is restored by leaving none.
$installRowsBefore = [];
foreach (['site.url', 'portal.support.email', 'org.address.line1', 'org.address.city'] as $key) {
    $row = one('SELECT settingValue, defaultValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    $installRowsBefore[$key] = $row;
}
q("DELETE FROM tblSettings WHERE settingKey IN ('site.url', 'portal.support.email', 'org.address.line1', 'org.address.city') AND siteID IS NULL");
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES "
    . "(NULL, 'site.url', 'https://installation.invalid', '', 0), "
    . "(NULL, 'portal.support.email', 'installation@selftest.invalid', '', 0), "
    . "(NULL, 'org.address.line1', 'INSTALLATION LINE ONE', '', 0), "
    . "(NULL, 'org.address.city', 'INSTALLATION CITY', '', 0)");

// --- each organisation keeps its own address --------------------------------
$answer = $savePostal('adm', $orgPage, $orgSave, 'A STREET', 'A CITY');
ok('A\'s administrator — not a global one — may save their own organisation\'s address', $answer['status'] === 302 && $answer['location'] === $orgPage, 'HTTP ' . $answer['status'] . ' Location: ' . ($answer['location'] === '' ? 'none' : $answer['location']));
ok('…and it is stored against A, not installation-wide', $settingAt('org.address.line1', ORG_A) === 'A STREET' && $settingAt('org.address.line1', null) === 'INSTALLATION LINE ONE', 'A: ' . $settingAt('org.address.line1', ORG_A) . ' | installation: ' . $settingAt('org.address.line1', null));

$answer = $savePostal('badm', $bOrgPage, $bOrgSave, 'B STREET', 'B CITY');
ok('B\'s administrator saves a different address on B\'s own address', $answer['status'] === 302 && $settingAt('org.address.line1', ORG_B) === 'B STREET', 'HTTP ' . $answer['status'] . ' | B: ' . $settingAt('org.address.line1', ORG_B));
ok('…and A\'s address is untouched by it — THE WHOLE POINT OF #677', $settingAt('org.address.line1', ORG_A) === 'A STREET' && $settingAt('org.address.city', ORG_A) === 'A CITY', 'A line1: ' . $settingAt('org.address.line1', ORG_A) . ' | A city: ' . $settingAt('org.address.city', ORG_A));

$page = req($jar['adm'], 'GET', $orgPage)['body'];
ok('A\'s page shows A\'s address and no trace of B\'s', str_contains($page, 'A STREET') === true && str_contains($page, 'B STREET') === false);
$page = req($jar['badm'], 'GET', $bOrgPage)['body'];
ok('B\'s page shows B\'s address and no trace of A\'s', str_contains($page, 'B STREET') === true && str_contains($page, 'A STREET') === false);

// --- the portal's own address stays installation-wide and untouched ---------
ok('an ordinary administrator saving the address did NOT touch the portal\'s web address', $settingAt('site.url', null) === 'https://installation.invalid', 'site.url is now: ' . $settingAt('site.url', null));
$answer = post($jar['adm'], $orgPage, $orgSave, ['form' => 'web-addresses', 'supportEmail' => 'a-support@selftest.invalid']);
ok('an ordinary administrator may set their OWN organisation\'s support e-mail address', $answer['status'] === 302 && $settingAt('portal.support.email', ORG_A) === 'a-support@selftest.invalid', 'HTTP ' . $answer['status'] . ' | A: ' . $settingAt('portal.support.email', ORG_A));
ok('…and the installation\'s support address is left as it was, so organisations without one still have a Support link', $settingAt('portal.support.email', null) === 'installation@selftest.invalid' && $settingAt('portal.support.email', ORG_B) === '(no row)', 'installation: ' . $settingAt('portal.support.email', null) . ' | B: ' . $settingAt('portal.support.email', ORG_B));
// 🚨 THE REGRESSION GUARD FOR THE BUG THIS SECTION ACTUALLY FOUND. The save
//    just above carried a support address and NO portal-address box, because
//    the page disables that box for an ordinary administrator and a disabled
//    control is not submitted. The first version of the #677 handler guarded
//    only who may SEND that field and then wrote the result regardless, so
//    `?? ''` gave an empty string, PortalAddress::check() read empty as "no
//    address", and this save WIPED `site.url` for every organisation. Every
//    e-mailed link would then have been built from whatever host the next
//    request happened to arrive at, which a visitor can choose (#500).
ok('…and that support-only save did NOT clear the portal\'s web address (it sent no such box)', $settingAt('site.url', null) === 'https://installation.invalid', 'site.url is now: [' . $settingAt('site.url', null) . ']');
$page = req($jar['badm'], 'GET', $bOrgPage)['body'];
ok('B, which has set none, is shown the installation\'s support address as the one in use', str_contains($page, 'installation@selftest.invalid') === true && str_contains($page, 'a-support@selftest.invalid') === false);

// --- and the one setting that really is shared ------------------------------
// A forged request: an ordinary administrator sending the field the page does
// not give them. It must be refused outright, with nothing saved — never
// quietly ignored, because this is a boundary between two parties (#668).
$answer = post($jar['adm'], $orgPage, $orgSave, ['form' => 'web-addresses', 'portalAddress' => 'https://hijacked.invalid', 'supportEmail' => 'x@selftest.invalid']);
ok('an ordinary administrator who SENDS the portal address anyway is refused, and nothing is saved', $answer['status'] === 302 && $settingAt('site.url', null) === 'https://installation.invalid' && $settingAt('portal.support.email', ORG_A) === 'a-support@selftest.invalid', 'HTTP ' . $answer['status'] . ' | site.url: ' . $settingAt('site.url', null) . ' | A support: ' . $settingAt('portal.support.email', ORG_A));
$answer = post($jar['gadm'], $orgPage, $orgSave, ['form' => 'web-addresses', 'portalAddress' => 'https://chosen.invalid', 'supportEmail' => 'g@selftest.invalid']);
ok('a GLOBAL administrator may change the portal\'s web address, and it is stored installation-wide', $answer['status'] === 302 && $settingAt('site.url', null) === 'https://chosen.invalid', 'HTTP ' . $answer['status'] . ' | site.url: ' . $settingAt('site.url', null));

// Put the four installation-wide rows back EXACTLY as they were found, values
// and all — see the note where they were read. A key that had no row keeps
// none. The organisation-level rows this part created go with the made-up
// world, which removeWorld() takes away by siteID.
q("DELETE FROM tblSettings WHERE settingKey IN ('site.url', 'portal.support.email', 'org.address.line1', 'org.address.city') AND siteID IS NULL");
foreach ($installRowsBefore as $key => $row) {
    if ($row === null) {
        continue;
    }
    q(
        'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, ?)',
        'sssi',
        [$key, (string) $row['settingValue'], (string) $row['defaultValue'], (int) $row['isSensitive']]
    );
}
$putBack = 0;
foreach (array_keys($installRowsBefore) as $key) {
    $now  = one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    $then = $installRowsBefore[$key];
    $same = ($now === null && $then === null)
        || ($now !== null && $then !== null && (string) $now['settingValue'] === (string) $then['settingValue']);
    if ($same === true) {
        $putBack++;
    }
}
ok('clean-up: the four installation-wide rows this part borrowed are back exactly as they were', $putBack === 4, $putBack . ' of 4 match');

// =============================================================================
head('P. #834 — a member\'s own claims, listed on the Expenses page');
// =============================================================================
/**
 * Until #834 nothing listed a member's claims: `/expenses/submit` was a form
 * only, so the claim's own page — the only place a pending claim can be
 * changed or withdrawn — could not be reached by an ordinary member.
 *
 * The world for this part is its own, made here so nothing earlier can have
 * changed it: three new accounts, and claims with SUBMITTED times set by hand
 * so that "newest first" and "highest number first" give different orders
 * (the oldest claim has the lowest number, the newest the middle one).
 *   PC1 — a member of A AND of B: twelve claims in A (so the list has two
 *         pages) and one in B.
 *   PC2 — a member of A with one claim of their own: somebody else's claim,
 *         as far as PC1 is concerned.
 *   PC0 — a member of A with no claims at all.
 * Each condition of the page's query has a check that fails without it:
 * P2 and P10 without `userID`, P3 and P8 without `siteID`.
 */
const PC1 = 916041;
const PC2 = 916042;
const PC0 = 916043;
/** A department of A whose name needs escaping, so the list's department column is checked as text. */
const DEPT_P      = 916103;
const DEPT_P_NAME = 'ACCESS-P dept <b>Hall & "Grounds"</b>';
/**
 * Every error row written from here on is part P's: P12 counts rows above
 * this mark for either organisation. It must NOT filter by account, because
 * `Logger::exception()` — what the list's failed read calls — records no
 * account at all (round 1 of the check found the first version did, so it
 * could not fail on this page's own errors).
 */
$pErrorMark = (int) (one('SELECT COALESCE(MAX(errorID), 0) AS m FROM tblErrors')['m'] ?? 0);
q('INSERT INTO tblDepts (deptID, siteID, deptName, deptCode, isActive) VALUES (?, ?, ?, \'SAP\', 1)', 'iis', [DEPT_P, ORG_A, DEPT_P_NAME]);
foreach ([PC1 => 'P claimant', PC2 => 'P other claimant', PC0 => 'P no claims'] as $id => $name) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, 'Selftest access ' . $name, 'u' . $id . '.access@selftest.invalid']);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [$id, ORG_A]);
}
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [PC1, ORG_B]);
foreach (['pc1' => PC1, 'pc2' => PC2, 'pc0' => PC0] as $who => $id) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-access-' . $who, $hash]);
}
/** One claim for this part, submitted `$daysAgo` days ago. */
$pClaim = static function (int $id, int $by, string $title, string $status, int $daysAgo, string $total, int $site = ORG_A, int $dept = DEPT): void {
    q(
        'INSERT INTO tblExpenseClaims (claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status, createdAt, `currency`) '
        . 'VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY), \'EUR\')',
        'iiiisssi', [$id, $site, $by, $dept, $title, $total, $status, $daysAgo]
    );
};
$pTitle = 'ACCESS-P newest & "quoted" <i>claim</i>';
$pClaim(916341, PC1, 'ACCESS-P oldest of the three', 'Approved', 3, '45.10');
$pClaim(916342, PC1, $pTitle, 'Pending', 1, '1234.50', ORG_A, DEPT_P);
$pClaim(916343, PC1, 'ACCESS-P withdrawn one', 'Withdrawn', 2, '7.00');
for ($k = 1; $k <= 9; $k++) {
    $pClaim(916350 + $k, PC1, 'ACCESS-P filler ' . $k, 'Reimbursed', 10 + $k, '1.00');
}
$pClaim(916344, PC1, 'ACCESS-P mine in organisation B', 'Pending', 1, '9.99', ORG_B, DEPT_B);
$pClaim(916345, PC2, 'ACCESS-P somebody else\'s claim', 'Pending', 1, '5.00');
foreach (['pc1', 'pc2', 'pc0'] as $who) {
    $jar[$who] = signIn($who, ADDR_A);
}
/** The claim numbers the "Your claims" list shows, in the order it shows them. */
$listed = static function (string $body): array {
    ap_findAll('#<div class="portal-data-row" data-claim-id="(\d+)">#', $body, $found);

    return array_map('intval', $found[1]);
};
/** The HTML of one listed claim's row, from its marker to the next row (or the end of the list). */
$rowOf = static function (string $body, int $id): string {
    $start = strpos($body, 'data-claim-id="' . $id . '"');
    if ($start === false) {
        return '';
    }
    $end = strpos($body, 'data-claim-id="', $start + 20);

    return substr($body, $start, ($end === false ? strlen($body) : $end) - $start);
};

$pageOne = req($jar['pc1'], 'GET', ADDR_A . '/expenses/submit');
$ids     = $listed($pageOne['body']);
ok(
    'P1. a member sees their own claims in this organisation, newest SUBMITTED first, ten to a page',
    $pageOne['status'] === 200 && $ids === [916342, 916343, 916341, 916351, 916352, 916353, 916354, 916355, 916356, 916357],
    'HTTP ' . $pageOne['status'] . '; listed: ' . json_encode($ids)
);
ok(
    'P2. …and NOT another member\'s claim in the same organisation',
    str_contains($pageOne['body'], 'somebody else') === false && str_contains($pageOne['body'], 'data-claim-id="916345"') === false
);
ok(
    'P3. …nor their OWN claim in the other organisation they belong to',
    str_contains($pageOne['body'], 'ACCESS-P mine in organisation B') === false && str_contains($pageOne['body'], 'data-claim-id="916344"') === false
);
$row = $rowOf($pageOne['body'], 916342);
ok(
    'P4. a listed claim shows its total in the organisation\'s own currency (euros, never a pound sign: #966), its status badge in the claim page\'s colour, and its title and department written as text',
    str_contains($row, '€1,234.50') === true && str_contains($row, '&pound;') === false && str_contains($row, '£') === false && str_contains($row, 'class="badge bg-warning">Pending</span>') === true
        && str_contains($row, htmlspecialchars($pTitle, ENT_QUOTES, 'UTF-8')) === true && str_contains($pageOne['body'], '<i>claim</i>') === false
        && str_contains($row, htmlspecialchars(DEPT_P_NAME, ENT_QUOTES, 'UTF-8')) === true && str_contains($pageOne['body'], DEPT_P_NAME) === false
        && str_contains($rowOf($pageOne['body'], 916343), 'class="badge bg-secondary">Withdrawn</span>') === true,
    'the row: ' . mb_substr(trim(ap_replace('/\s+/', ' ', strip_tags($row))), 0, 160)
);
$href = ADDR_A . '/expenses/view?id=916342';
$view = req($jar['pc1'], 'GET', $href);
ok(
    'P5. each claim links to its own page inside A\'s address, and the link opens the claim (with its Withdraw button, since it is pending and the visitor\'s own)',
    str_contains($row, 'href="' . $href . '"') === true && $view['status'] === 200
        && str_contains($view['body'], htmlspecialchars($pTitle, ENT_QUOTES, 'UTF-8')) === true && str_contains($view['body'], 'Withdraw Claim') === true,
    'link ' . (str_contains($row, 'href="' . $href . '"') === true ? 'found' : 'NOT found') . '; the claim page answered HTTP ' . $view['status']
);
$pageTwo = req($jar['pc1'], 'GET', ADDR_A . '/expenses/submit?page=2');
ok(
    'P6. the older claims are on page 2, the two pages link to each other inside A\'s address, and page 1 has no "Newer" link',
    $listed($pageTwo['body']) === [916358, 916359]
        && str_contains($pageOne['body'], 'href="' . ADDR_A . '/expenses/submit?page=2"') === true && str_contains($pageOne['body'], 'Newer claims') === false
        && str_contains($pageTwo['body'], 'href="' . ADDR_A . '/expenses/submit?page=1"') === true && str_contains($pageTwo['body'], 'Older claims') === false,
    'page 2 listed ' . json_encode($listed($pageTwo['body']))
);
$pastEnd = req($jar['pc1'], 'GET', ADDR_A . '/expenses/submit?page=999');
$oddPage = req($jar['pc1'], 'GET', ADDR_A . '/expenses/submit?page[]=2');
ok(
    'P7. a page past the end shows the LAST page (not "no claims"), and a page number sent as a list is page 1, not an error',
    $listed($pastEnd['body']) === [916358, 916359] && $oddPage['status'] === 200 && $listed($oddPage['body']) === $ids,
    'past the end: ' . json_encode($listed($pastEnd['body'])) . '; as a list: HTTP ' . $oddPage['status'] . ' ' . json_encode($listed($oddPage['body']))
);
$onB = req($jar['pc1'], 'GET', ADDR_B . '/expenses/submit');
ok(
    'P8. the same member on B\'s own address sees B\'s claim, and none of A\'s',
    $onB['status'] === 200 && $listed($onB['body']) === [916344] && str_contains($onB['body'], 'href="' . ADDR_B . '/expenses/view?id=916344"') === true,
    'HTTP ' . $onB['status'] . '; listed: ' . json_encode($listed($onB['body']))
);
$none = req($jar['pc0'], 'GET', ADDR_A . '/expenses/submit');
ok(
    'P9. a member with no claims is told so in a plain sentence, with no list, and the form is still there',
    $none['status'] === 200 && str_contains($none['body'], 'You have not made any expense claims here yet.') === true
        && $listed($none['body']) === [] && str_contains($none['body'], 'id="your-claims-list"') === false
        && str_contains($none['body'], 'action="' . ADDR_A . '/expenses/submit/save"') === true,
    'HTTP ' . $none['status'] . '; listed: ' . json_encode($listed($none['body']))
);
$other = req($jar['pc2'], 'GET', ADDR_A . '/expenses/submit');
ok(
    'P10. the other member sees their own one claim, and none of the first member\'s',
    $listed($other['body']) === [916345] && str_contains($other['body'], 'ACCESS-P oldest') === false,
    'listed: ' . json_encode($listed($other['body']))
);
// Round 1 of the check: replacing the page-number test with a bare `(int)`
// left every check above green, while these four made the list say "could
// not be read" (a negative OFFSET is an SQL error) and wrote an error row.
$badPages = [];
foreach (['0', '-1', 'abc', ''] as $asked) {
    $answer = req($jar['pc1'], 'GET', ADDR_A . '/expenses/submit?page=' . rawurlencode($asked));
    if ($answer['status'] !== 200 || $listed($answer['body']) !== $ids || str_contains($answer['body'], 'could not be read') === true) {
        $badPages[] = "page='{$asked}': HTTP {$answer['status']}, listed " . json_encode($listed($answer['body'])) . (str_contains($answer['body'], 'could not be read') === true ? ', said "could not be read"' : '');
    }
}
ok('P11. a page number of 0, -1, "abc" or nothing at all shows page 1, and never "could not be read"', $badPages === [], implode('; ', $badPages));
$pErrors = q('SELECT errorTitle FROM tblErrors WHERE errorID > ? AND siteID IN (?, ?)', 'iii', [$pErrorMark, ORG_A, ORG_B]);
ok(
    'P12. nothing went wrong behind any page of this part: no error row was written for organisation A or B since it began',
    $pErrors === [],
    count($pErrors) . ' row(s): ' . implode(' | ', array_map(static fn (array $r): string => mb_substr((string) $r['errorTitle'], 0, 120), array_slice($pErrors, 0, 3)))
);

// =============================================================================
head('Q. #529 / D-106 — who sees an organisation\'s attendance totals');
// =============================================================================
// D-106 (the owner, 10 November 2026): administrators, plus whoever the
// organisation points the permission "See the attendance totals"
// (`attendance.reports`) at on Admin, Roles — shipped pointing at the role
// `attendance_coordinator`, which nobody holds until it is granted. An ordinary
// member, and an account from another organisation, get the SAME answer as an
// address that does not exist. Three addresses show the totals: the reports
// page, the spreadsheet download, and the cards on /attendance.
//
// "THE SAME ANSWER" IS MEASURED, NOT ASSUMED: every refusal is compared with
// the answer the SAME account gets for a made-up address in the same folder,
// with the same query string. Two things are taken out first, and only two:
// the script nonce, which is fresh on every request (#625), and the address
// itself, which the language menu writes back into the page. Anything else
// that differed would be a way to tell the page exists.
//
// THE PEOPLE, each holding exactly one ground (#616's lesson: one account
// holding several cannot tell them apart):
//   MEM   a member of A, nothing else                         → refused
//   LEAD  a member of A who holds ANOTHER standard role       → refused
//   ACO   a member of A holding the shipped role              → sees them
//   ACOB  a member of B holding the shipped role in B only    → refused in A
//   ADM   a site administrator of A                           → sees them
//   GADM  a global administrator, no membership row           → sees them
//   BMEM  a member of B only                                  → refused in A
//   LADM  a member of B only, holding the portal-wide `isAdmin`
//         flag (what Admin, Users sets)                       → refused in A
//
// ⚠️ LADM IS THE ONLY PERSON WHO MAKES THE MEMBERSHIP RULE MATTER (#529 round
//    1). `App::isAdmin()` answers TRUE for that flag on ANY organisation's
//    address, so for this one account the "must be a member of A" half of
//    `mayViewTotals()` is all that stands between it and A's figures. Without
//    LADM, replacing that half with `$userId > 0` left every check green,
//    because every other outsider is refused by the permission half as well.
const ACO  = 916051;
const ACOB = 916052;
const LADM = 916053;
/** In the one headcount group and the one note this part records, so "is it in this answer?" is one search. */
const ATT_Q_MARK = 'ACCESS-Q';
// ⚠️ NO NAME HERE MAY BEGIN WITH "A": every name is prefixed "Selftest access ",
//    and refusedAtTheDoor() looks for "Selftest access A" — organisation A's own
//    name — in the refusal page, which names the signed-in account. The first
//    version called these two "Attendance coordinator…" and the door's correct
//    refusal read as a leak.
foreach ([ACO => 'Q coordinator', ACOB => 'Q coordinator in B'] as $id => $name) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, 'Selftest access ' . $name, 'u' . $id . '.access@selftest.invalid']);
}
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0), (?, ?, 1, 0, 0)', 'iiii', [ACO, ORG_A, ACOB, ORG_B]);
// The shipped role, in each organisation. Inserted by hand because this world's
// two organisations are made after the install script ran, so migration 271's
// seed never reached them — a new organisation made through the portal gets it
// from Roles::STANDARD.
q("INSERT INTO tblRoles (roleID, siteID, roleKey, roleName, isStandard) VALUES (916202, ?, 'attendance_coordinator', 'Attendance Coordinator', 1), (916203, ?, 'attendance_coordinator', 'Attendance Coordinator', 1)", 'ii', [ORG_A, ORG_B]);
q('INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, 916202, ?), (?, 916203, ?)', 'iiii', [ACO, ORG_A, ACOB, ORG_B]);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 1, 0)', 'iss', [LADM, 'Selftest access Q flagged administrator of B', 'u' . LADM . '.access@selftest.invalid']);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [LADM, ORG_B]);
foreach (['aco' => ACO, 'acob' => ACOB, 'ladm' => LADM] as $who => $id) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-access-' . $who, $hash]);
}
$jar['aco']  = signIn('aco', ADDR_A);
$jar['acob'] = signIn('acob', ADDR_B);
$jar['ladm'] = signIn('ladm', ADDR_B);
// One session of A, today, with one headcount group and a note carrying the mark.
// Its number is well clear of 916612: part G records sessions through the real
// page, and those take the NEXT numbers (916613 and on). 916613 was tried first
// and collided.
q("INSERT INTO tblAttendanceSessions (sessionID, siteID, serviceTypeID, sessionDate, notes) VALUES (916690, ?, 916601, ?, ?)", 'iss', [ORG_A, $today, ATT_Q_MARK . ' note']);
q('INSERT INTO tblAttendanceCounts (sessionID, groupLabel, headcount, sortOrder) VALUES (916690, ?, 4321, 0)', 's', [ATT_Q_MARK . ' group']);
// D-114 (#529 round 2): sessions with a RECORDER. 916691 is the ordinary
// member's own, so "a member never sees a figure" is tested on a session they
// recorded themselves as well as on somebody else's. 916694 is the newest, so
// "Copy from last" copies ITS group label. Every figure here is distinctive, so
// the raw number can be searched for (#529 round 2: a check that looked only
// for "4,321" passed on a page printing 4321).
// 916685 is deleted by A's administrator through the PAGE, and 916686 by an
// API key (#529 round 3). Both are numbered BELOW 916694 on purpose: "Copy
// from last" copies the highest-numbered session of the day, and Q25 needs
// that to be 916694's label (916695 was tried first and took its place).
foreach ([[916691, MEM, 4319, ' own'], [916692, LEAD, 4317, ' lead one'], [916693, LEAD, 4316, ' lead two'], [916694, LEAD, 4318, ' copied'], [916685, LEAD, 4315, ' lead three'], [916686, LEAD, 4314, ' lead four']] as [$sid, $by, $n, $label]) {
    q('INSERT INTO tblAttendanceSessions (sessionID, siteID, serviceTypeID, sessionDate, notes, createdByID) VALUES (?, ?, 916601, ?, ?, ?)', 'iissi', [$sid, ORG_A, $today, ATT_Q_MARK . $label . ' note', $by]);
    q('INSERT INTO tblAttendanceCounts (sessionID, groupLabel, headcount, sortOrder) VALUES (?, ?, ?, 0)', 'isi', [$sid, ATT_Q_MARK . $label, $n]);
}
/** A page's text with every run of 32 or more hex characters (form tokens, nonces) taken out, so a number can be searched for without a token happening to contain it. */
$qNoTokens = static fn (string $body): string => ap_replace('/[0-9a-f]{32,}/i', '', $body);
/** Does a page show any of these figures, raw or with a thousands comma? */
$qShowsFigure = static function (string $body, array $figures) use ($qNoTokens): bool {
    $plain = $qNoTokens($body);
    foreach ($figures as $n) {
        if (str_contains($plain, (string) $n) === true || str_contains($plain, number_format($n)) === true) {
            return true;
        }
    }

    return false;
};

$qReport = ADDR_A . '/attendance/report';
$qExport = ADDR_A . '/attendance/export';
$qMadeUp = ADDR_A . '/attendance/zz-no-such-page';
/**
 * The WHOLE answer — status line, every header, and the body — with only the
 * parts allowed to differ taken out: the `Date:` header, the script nonce (in
 * the page and in the Content-Security-Policy header), and the address itself.
 *
 * ⚠️ HEADERS ARE PART OF THE ANSWER (#529 round 1). The first version compared
 *    the status and the body only, so `header('X-Report: attendance');` put
 *    above the gate — a page announcing itself before refusing — left every
 *    check green.
 */
$qPlain = static function (array $answer, string $path): string {
    $head = ap_replace('/^Date:[^\r\n]*\r?\n/mi', '', $answer['head']);
    $head = ap_replace("/'nonce-[^']+'/", "'nonce-'", $head);
    $body = ap_replace('/ nonce="[0-9a-f]+"/', ' nonce=""', $answer['body']);

    return str_replace($path, '{ADDRESS}', $head . "\n" . $body);
};
/** Is `$asked` (for `$path`) the same answer as a made-up address in the same folder, with the same query? Returns '' or why not. */
$qLikeMadeUp = static function (string $jarFile, string $path, string $query) use ($qPlain, $qMadeUp): string {
    $asked  = req($jarFile, 'GET', $path . $query);
    $madeUp = req($jarFile, 'GET', $qMadeUp . $query);
    $a      = $qPlain($asked, $path);
    $b      = $qPlain($madeUp, $qMadeUp);
    if ($a === $b) {
        return '';
    }
    $at = 0;
    while ($at < min(strlen($a), strlen($b)) && $a[$at] === $b[$at]) {
        $at++;
    }

    return 'HTTP ' . $asked['status'] . ' against ' . $madeUp['status'] . '; first difference at byte ' . $at
        . ': "' . substr($a, max(0, $at - 40), 100) . '" against "' . substr($b, max(0, $at - 40), 100) . '"';
};
$qToken = static fn (string $who): string => '?csrf_token=' . token(req($jar[$who], 'GET', ADDR_A . '/attendance')['body']);

// --- signed out ---------------------------------------------------------------
foreach (['the reports page' => $qReport, 'the spreadsheet' => $qExport] as $what => $path) {
    $answer = req($nobody, 'GET', $path);
    ok(
        'Q1. signed out, ' . $what . ' sends the visitor to sign in and shows no figure',
        $answer['status'] === 302 && str_contains($answer['location'], 'login') === true && str_contains($answer['body'], ATT_Q_MARK) === false,
        'HTTP ' . $answer['status'] . ' ' . $answer['location']
    );
}

// --- a member of A, and a member holding another role: "page not found" -------
foreach (['mem' => 'an ordinary member of A', 'lead' => 'a member of A holding a different role (treasurer)'] as $who => $what) {
    $answer = req($jar[$who], 'GET', $qReport);
    ok('Q2. ' . $what . ': the reports page answers 404 and shows no figure', $answer['status'] === 404 && str_contains($answer['body'], ATT_Q_MARK) === false, 'HTTP ' . $answer['status']);
    $why = $qLikeMadeUp($jar[$who], $qReport, '');
    ok('Q3. ' . $what . ': …and that 404 is the same answer as a made-up address', $why === '', $why);
    $query = $qToken($who);
    $why   = $qLikeMadeUp($jar[$who], $qExport, $query);
    ok('Q4. ' . $what . ': the spreadsheet, asked with a GOOD form token, is the same answer as a made-up address', $why === '', $why);
}
$page = req($jar['mem'], 'GET', ADDR_A . '/attendance');
ok(
    'Q5. the attendance page shows a member no reports card, no "Total Headcount This Month" and no download button',
    $page['status'] === 200 && str_contains($page['body'], 'Total Headcount This Month') === false
        && str_contains($page['body'], ADDR_A . '/attendance/report"') === false && str_contains($page['body'], ADDR_A . '/attendance/export?') === false,
    'HTTP ' . $page['status']
);
ok('Q5b. …while still showing the member the page itself (recording is not affected)', str_contains($page['body'], ADDR_A . '/attendance/record"') === true);

// --- D-109 (#917): no session's own headcount for an ordinary member ----------
ok(
    'Q20. D-109: the session list shows a member no headcount column, no session\'s figure (raw or with a comma, their own session\'s included) and no Edit button',
    $qShowsFigure($page['body'], [4321, 4319, 4317, 4316, 4318]) === false && str_contains($page['body'], '>Headcount</div>') === false
        && str_contains($page['body'], '?edit=') === false && str_contains($page['body'], 'ACCESS service') === true,
    'HTTP ' . $page['status']
);
$qRecord = ADDR_A . '/attendance/record';
$why     = $qLikeMadeUp($jar['mem'], $qRecord, '?edit=916690');
ok(
    'Q21. D-109: a member opening the form for an EXISTING session gets the same answer as a made-up address (404)',
    $why === '' && req($jar['mem'], 'GET', $qRecord . '?edit=916690')['status'] === 404,
    $why
);
$qStored = static fn (): int => (int) (one('SELECT COALESCE(SUM(headcount), -1) AS n FROM tblAttendanceCounts WHERE sessionID = 916690')['n'] ?? -1);
$qUpdate = static fn (string $who, int $session, int $count): array => post($jar[$who], $qRecord, $qRecord . '/save', [
    'action' => 'update', 'sessionID' => $session, 'serviceTypeID' => 916601, 'sessionDate' => $today,
    'groups' => [ATT_Q_MARK . ' group'], 'counts' => [$count], 'notes' => ATT_Q_MARK . ' note', // the note too: an update writes every field, and an absent note is stored as none
]);
// The message is read on /attendance, where the handler sends the person: that
// page prints it since #921 was fixed in #529 round 2 (until then it printed
// none, and this read it on the record page).
$real    = $qUpdate('mem', 916690, 1);
$realMsg = req($jar['mem'], 'GET', ADDR_A . '/attendance')['body'];
$fake    = $qUpdate('mem', 916699, 1);
$fakeMsg = req($jar['mem'], 'GET', ADDR_A . '/attendance')['body'];
ok(
    'Q22. D-109: a member posting new counts over an EXISTING session is answered as for a session that does not exist, and nothing is written',
    $real['status'] === 302 && $real['location'] === $fake['location'] && str_contains($realMsg, 'Attendance session not found.') === true
        && str_contains($fakeMsg, 'Attendance session not found.') === true && $qStored() === 4321,
    'HTTP ' . $real['status'] . ' ' . $real['location'] . ' against ' . $fake['location'] . '; stored ' . $qStored()
);
$page = req($jar['aco'], 'GET', ADDR_A . '/attendance');
$form = req($jar['aco'], 'GET', $qRecord . '?edit=916690');
ok(
    'Q23. D-109: the holder of the permission sees each session\'s headcount and Edit button, and the edit form shows the stored count',
    str_contains($page['body'], '4,321') === true && str_contains($page['body'], '?edit=916690') === true
        && $form['status'] === 200 && str_contains($form['body'], 'value="4321"') === true,
    'list HTTP ' . $page['status'] . ', form HTTP ' . $form['status']
);
$answer = $qUpdate('aco', 916690, 4322);
ok('Q24. D-109: …and can correct it', $answer['status'] === 302 && $qStored() === 4322, 'HTTP ' . $answer['status'] . '; stored ' . $qStored());

// --- coverage pins (#529 round 2): three more places a figure could reach a member
$page = req($jar['mem'], 'GET', $qRecord . '?copyFrom=last&serviceTypeID=916601');
ok(
    'Q25. "Copy from last" as a member copies the last session\'s group labels and none of its figures',
    $page['status'] === 200 && str_contains($page['body'], 'value="' . ATT_Q_MARK . ' copied"') === true && $qShowsFigure($page['body'], [4318, 4321, 4319, 4317, 4316]) === false,
    'HTTP ' . $page['status']
);
$listAs = static fn (string $who): array => req($jar[$who], 'GET', ADDR_A . '/api/attendance/list?limit=100');
$answer = $listAs('mem');
$rows   = data($answer)['sessions'] ?? [];
$ids    = array_map(static fn (array $r): int => (int) ($r['sessionID'] ?? 0), $rows);
$keys   = array_unique(array_merge([], ...array_map('array_keys', $rows)));
ok(
    'Q26. api/attendance/list as a member: no deleted session, no notes, no "who last changed it", and no figure',
    $answer['status'] === 200 && in_array(916691, $ids, true) === true && in_array(916612, $ids, true) === false
        && in_array('notes', $keys, true) === false && in_array('updatedByID', $keys, true) === false
        && str_contains($answer['body'], ATT_Q_MARK) === false && $qShowsFigure($answer['body'], [4322, 4319, 4317, 4316, 4318]) === false,
    'HTTP ' . $answer['status'] . '; keys: ' . implode(',', $keys) . '; has 916612: ' . var_export(in_array(916612, $ids, true), true)
);
$answer = $listAs('aco');
$ids    = array_map(static fn (array $r): int => (int) ($r['sessionID'] ?? 0), data($answer)['sessions'] ?? []);
$liveCount = (int) one('SELECT COUNT(*) AS n FROM tblAttendanceSessions WHERE siteID = ? AND isDeleted = 0', 'i', [ORG_A])['n'];
$allCount  = (int) one('SELECT COUNT(*) AS n FROM tblAttendanceSessions WHERE siteID = ?', 'i', [ORG_A])['n'];
$counted   = (int) (data($listAs('mem'))['pagination']['totalItems'] ?? -1);
ok(
    'Q26c. …and its count of sessions leaves the deleted ones out too, so it cannot say how many exist',
    $allCount > $liveCount && $counted === $liveCount,
    'counted ' . $counted . '; not deleted ' . $liveCount . '; all ' . $allCount
);
ok(
    'Q26b. …while the holder of the permission still gets every session with its notes, deleted ones included (unchanged)',
    $answer['status'] === 200 && in_array(916612, $ids, true) === true && str_contains($answer['body'], ATT_Q_MARK . ' own note') === true,
    'HTTP ' . $answer['status']
);

// --- D-114: who may delete a recorded session ---------------------------------
// The control for one session is its hidden field; a member is offered it only
// on the session they recorded.
$control = static fn (string $body, int $sid): bool => str_contains($body, 'name="sessionID" value="' . $sid . '"');
$memList = req($jar['mem'], 'GET', ADDR_A . '/attendance')['body'];
ok(
    'Q27. D-114: a member is offered the delete control on the session they recorded, and not on another member\'s or on one with no recorder',
    $control($memList, 916691) === true && $control($memList, 916692) === false && $control($memList, 916690) === false
);
$acoList = req($jar['aco'], 'GET', ADDR_A . '/attendance')['body'];
ok('Q27b. D-114: the holder of the permission is offered it on every session', $control($acoList, 916691) === true && $control($acoList, 916692) === true && $control($acoList, 916690) === true);
$qDelete = static fn (string $who, int $sid): array => post($jar[$who], ADDR_A . '/attendance', ADDR_A . '/attendance/record/delete', ['sessionID' => $sid]);
$real    = $qDelete('mem', 916692);
$realMsg = req($jar['mem'], 'GET', ADDR_A . '/attendance')['body'];
$fake    = $qDelete('mem', 916699);
$fakeMsg = req($jar['mem'], 'GET', ADDR_A . '/attendance')['body'];
ok(
    'Q28. D-114: a member deleting another member\'s session is answered exactly as for a session that does not exist, and it is not deleted',
    $real['status'] === 302 && $real['location'] === $fake['location'] && str_contains($realMsg, 'Attendance session not found.') === true
        && str_contains($fakeMsg, 'Attendance session not found.') === true && $deleted(916692) === 0,
    'HTTP ' . $real['status'] . ' ' . $real['location'] . ' against ' . $fake['location'] . '; isDeleted ' . $deleted(916692)
);
$answer = $qDelete('mem', 916691);
$msg    = req($jar['mem'], 'GET', ADDR_A . '/attendance')['body'];
ok(
    'Q29. D-114: the member deletes the session they recorded, and the message names no figure',
    $answer['status'] === 302 && $deleted(916691) === 1 && str_contains($msg, 'Attendance record deleted.') === true && $qShowsFigure($msg, [4319]) === false,
    'HTTP ' . $answer['status'] . '; isDeleted ' . $deleted(916691)
);
$updatedBy = static fn (int $sid): int => (int) (one('SELECT updatedByID FROM tblAttendanceSessions WHERE sessionID = ?', 'i', [$sid])['updatedByID'] ?? 0);
$answer    = $qDelete('mem', 916691);
$msg       = req($jar['mem'], 'GET', ADDR_A . '/attendance')['body'];
ok(
    'Q29b. #529 round 3: the member deleting their own session a SECOND time is told it cannot be found',
    $answer['status'] === 302 && str_contains($msg, 'Attendance session not found.') === true && $updatedBy(916691) === MEM,
    'who deleted it, as recorded: ' . $updatedBy(916691)
);
$answer = $qDelete('adm', 916691);
$msg    = req($jar['adm'], 'GET', ADDR_A . '/attendance')['body'];
ok(
    'Q29c. …and an administrator deleting it again is told the same, and the record of who deleted it is NOT overwritten',
    $answer['status'] === 302 && str_contains($msg, 'Attendance session not found.') === true && $updatedBy(916691) === MEM,
    'who deleted it, as recorded: ' . $updatedBy(916691) . ' (expected ' . MEM . ')'
);
$answer = $qDelete('aco', 916693);
ok('Q30. D-114: the holder of the permission deletes a session somebody else recorded', $answer['status'] === 302 && $deleted(916693) === 1, 'HTTP ' . $answer['status']);
/** The same delete through the API, signed in, with the page's form token in the header a script sends. */
$apiDelete = static fn (string $who, int $sid): array => postJson($jar[$who], ADDR_A . '/api/attendance/delete', ['sessionID' => $sid], ['X-CSRF-TOKEN: ' . token(req($jar[$who], 'GET', ADDR_A . '/attendance')['body'])]);
$real = $apiDelete('mem', 916692);
$fake = $apiDelete('mem', 916699);
ok(
    'Q31. D-114: through api/attendance/delete, a member deleting another member\'s session gets the same answer as a session that does not exist, and nothing is written',
    $real['status'] === 404 && stable($real) === stable($fake) && $deleted(916692) === 0,
    said($real) . ' against ' . said($fake)
);
$answer = $apiDelete('lead', 916692);
ok('Q32. D-114: through the API, the member who recorded it deletes it', $answer['status'] === 200 && $deleted(916692) === 1, said($answer));
$answer = $apiDelete('adm', 916694);
ok('Q33. D-114: through the API, an administrator deletes one too (unchanged)', $answer['status'] === 200 && $deleted(916694) === 1, said($answer));
$answer = $qDelete('adm', 916685);
ok('Q34. D-114: A\'s administrator deletes a session somebody else recorded, through the PAGE', $answer['status'] === 302 && $deleted(916685) === 1 && $updatedBy(916685) === ADM, 'HTTP ' . $answer['status']);
// D-115: an API key holding attendance:write may delete ANY session; one
// without that scope may not. The key is made here, for A, and goes with the
// world (removeWorld deletes A's keys).
$keyAW = 'wbms_' . bin2hex(random_bytes(16));
q(
    "INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, isActive, createdByID) VALUES (?, 'Selftest attendance write key', ?, ?, 'attendance:write', 1, NULL)",
    'iss', [ORG_A, hash('sha256', $keyAW), substr($keyAW, 0, 12)]
);
$answer = req('', 'POST', ADDR_A . '/api/attendance/delete', null, $keyA, (string) json_encode(['sessionID' => 916686]));
ok('Q35. D-115: an API key WITHOUT attendance:write is refused, and the session is not deleted', $answer['status'] === 403 && $deleted(916686) === 0, said($answer));
$answer = req('', 'POST', ADDR_A . '/api/attendance/delete', null, $keyAW, (string) json_encode(['sessionID' => 916686]));
ok('Q36. D-115: an API key WITH attendance:write deletes a session nobody gave it, as before', $answer['status'] === 200 && $deleted(916686) === 1, said($answer));

// --- another organisation's accounts ------------------------------------------
// At the door: SiteGate refuses a non-member on EVERY address, so the report is
// the same refusal as a made-up address.
foreach (['bmem' => 'a member of B', 'acob' => 'a member of B holding the shipped role in B'] as $who => $what) {
    $answer = req($jar[$who], 'GET', $qReport);
    ok('Q6. at the door, ' . $what . ' on A\'s address: refused by the members-only gate, nothing of A\'s in it', refusedAtTheDoor($answer) === true && str_contains($answer['body'], ATT_Q_MARK) === false, 'HTTP ' . $answer['status']);
    $why = $qLikeMadeUp($jar[$who], $qReport, '');
    ok('Q7. at the door, ' . $what . ': the reports page is the same answer as a made-up address', $why === '', $why);
    $why = $qLikeMadeUp($jar[$who], $qExport, '');
    ok('Q7b. at the door, ' . $what . ': so is the spreadsheet', $why === '', $why);
}
// With the gate stood down, the PAGE's own rule answers — so the rule is proved
// to hold by itself, and a role held in B never opens A's totals.
membersGate(false);
foreach (['bmem' => 'a member of B', 'acob' => 'a member of B holding the shipped role in B', 'nomem' => 'an account in no organisation', 'ladm' => 'a member of B holding the portal-wide administrator flag'] as $who => $what) {
    $answer = req($jar[$who], 'GET', $qReport);
    ok('Q8. without the door, ' . $what . ': the reports page itself answers 404, nothing of A\'s in it', $answer['status'] === 404 && str_contains($answer['body'], ATT_Q_MARK) === false, 'HTTP ' . $answer['status']);
    $why = $qLikeMadeUp($jar[$who], $qReport, '');
    ok('Q9. without the door, ' . $what . ': the same answer as a made-up address', $why === '', $why);
    $why = $qLikeMadeUp($jar[$who], $qExport, '');
    ok('Q9b. without the door, ' . $what . ': the spreadsheet too', $why === '', $why);
}
membersGate(true);

// --- the people who see them --------------------------------------------------
foreach (['aco' => 'the holder of the shipped role', 'adm' => 'A\'s administrator', 'gadm' => 'a global administrator with no membership row'] as $who => $what) {
    $answer = req($jar[$who], 'GET', $qReport);
    ok('Q10. ' . $what . ' opens the reports page and sees the totals', $answer['status'] === 200 && heading($answer) === 'Attendance Reports' && str_contains($answer['body'], ATT_Q_MARK . ' group') === true, 'HTTP ' . $answer['status'] . ' heading: ' . heading($answer));
    $answer = req($jar[$who], 'GET', $qExport . $qToken($who));
    ok(
        'Q11. ' . $what . ' downloads the spreadsheet, with the session in it',
        $answer['status'] === 200 && ap_find('/^Content-Type:\s*text\/csv/mi', $answer['head']) === 1 && str_contains($answer['body'], ATT_Q_MARK . ' note') === true,
        'HTTP ' . $answer['status'] . ' ' . mb_substr($answer['body'], 0, 80)
    );
}
$page = req($jar['aco'], 'GET', ADDR_A . '/attendance');
ok(
    'Q12. the attendance page shows the role holder the reports card, "Total Headcount This Month" and the download button',
    $page['status'] === 200 && str_contains($page['body'], 'Total Headcount This Month') === true
        && str_contains($page['body'], ADDR_A . '/attendance/report"') === true && str_contains($page['body'], ADDR_A . '/attendance/export?') === true,
    'HTTP ' . $page['status']
);

// --- re-pointed on the real Roles page ----------------------------------------
// The permission follows the ROLE the organisation chooses, read live: pointed
// at treasurer, the treasurer is let in and the Attendance Coordinator is not.
$answer = post($jar['adm'], ADDR_A . '/admin/roles', ADDR_A . '/admin/roles/save', ['action' => 'capability', 'capability' => 'attendance.reports', 'roleKeys' => ['treasurer']]);
ok('Q13. A\'s administrator points "See the attendance totals" at Treasurer on the Roles page', $answer['status'] === 302, 'HTTP ' . $answer['status']);
$answer = req($jar['lead'], 'GET', $qReport);
ok('Q14. …and the treasurer now opens the reports page', $answer['status'] === 200 && str_contains($answer['body'], ATT_Q_MARK . ' group') === true, 'HTTP ' . $answer['status']);
$why = $qLikeMadeUp($jar['aco'], $qReport, '');
ok('Q15. …while the Attendance Coordinator now gets the made-up-address answer', $why === '' && req($jar['aco'], 'GET', $qReport)['status'] === 404, $why);
$answer = post($jar['adm'], ADDR_A . '/admin/roles', ADDR_A . '/admin/roles/save', ['action' => 'capability_reset', 'capability' => 'attendance.reports']);
ok('Q16. put back to the role it came with: the Attendance Coordinator is let in again', $answer['status'] === 302 && req($jar['aco'], 'GET', $qReport)['status'] === 200, 'HTTP ' . $answer['status']);

// --- the settings page that used to hold the old choice -----------------------
// It used to REFUSE the whole save when `reportsVisibleTo` was missing; the
// field is gone, so a save without it must work, and one that still carries it
// (a page left open across the upgrade) must not bring the deleted setting back.
$oldSettingRows = static fn (): int => (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = 'attend.reports.visibleTo'")['n'];
$page = req($jar['adm'], 'GET', ADDR_A . '/admin/settings/attendance');
ok(
    'Q17. the attendance settings page no longer offers the old choice, and names the permission instead',
    $page['status'] === 200 && str_contains($page['body'], 'reportsVisibleTo') === false && str_contains($page['body'], 'See the attendance totals') === true,
    'HTTP ' . $page['status']
);
$answer = post($jar['adm'], ADDR_A . '/admin/settings/attendance', ADDR_A . '/admin/settings/attendance/save', ['scope' => 'site', 'visibleTo' => 'admins', 'retentionDays' => '30']);
$after  = req($jar['adm'], 'GET', ADDR_A . '/admin/settings/attendance');
ok(
    'Q18. saving it without the old field works ("Saved for your organisation.") and writes no old-setting row',
    $answer['status'] === 302 && str_contains($after['body'], 'Saved for your organisation.') === true && $oldSettingRows() === 0,
    'HTTP ' . $answer['status'] . '; old-setting rows: ' . $oldSettingRows()
);
$answer = post($jar['adm'], ADDR_A . '/admin/settings/attendance', ADDR_A . '/admin/settings/attendance/save', ['scope' => 'site', 'visibleTo' => 'admins', 'retentionDays' => '30', 'reportsVisibleTo' => 'members']);
ok('Q19. …and a save still carrying "reportsVisibleTo=members" is accepted without writing it back', $answer['status'] === 302 && $oldSettingRows() === 0, 'HTTP ' . $answer['status'] . '; old-setting rows: ' . $oldSettingRows());

// =============================================================================
head('Nothing went wrong behind the pages, and everything is put back');
// =============================================================================
$log = (string) @file_get_contents($child . $sep . 'php-errors.log');
$bad = array_values(array_filter((array) ap_split('/\R/', $log), static fn (string $line): bool => ap_find('/PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught/', $line) === 1));
ok('the server\'s PHP error log holds no warning, notice or fatal error', $bad === [], count($bad) . ' found: ' . implode(' | ', array_map(static fn (string $line): string => mb_substr($line, 0, 220), array_slice($bad, 0, 4))));

$before = $state['settings'];
cleanup();
$left = (int) (one(
    'SELECT (SELECT COUNT(*) FROM tblSites WHERE siteID IN (?, ?)) + (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 916000 AND 916999) '
    . "+ (SELECT COUNT(*) FROM tblExpenseClaims WHERE claimID BETWEEN 916000 AND 916999) + (SELECT COUNT(*) FROM tblSettings WHERE settingKey LIKE '%zz-selftest%') "
    . '+ (SELECT COUNT(*) FROM tblEvents WHERE eventID BETWEEN 916000 AND 916999) + (SELECT COUNT(*) FROM tblVenues WHERE venueID BETWEEN 916000 AND 916999) '
    . '+ (SELECT COUNT(*) FROM tblExternalFeeds WHERE feedID BETWEEN 916000 AND 916999) + (SELECT COUNT(*) FROM tblExternalEventApprovals WHERE approvalID BETWEEN 916000 AND 916999) '
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
