<?php
// Path: tools/erasure-pages-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The erasure pages, on the REAL pages — self-test 🗑️🚪
 * (roll-out packages A2a, A2b and B2b)
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
 *   I. (Roll-out package B2b, 12 October 2026.) Every redirect, link and
 *      form target on the eight erasure and "delete my account" pages stays
 *      inside the organisation's own address: each press on the account
 *      pages lands back inside A's address, and an administrator of B who
 *      presses a button on B's list lands back on B's list. They used to be
 *      bare addresses, which land on the default organisation. B's
 *      administrator also carries out one of B's requests: the browser is
 *      sent to B's own list, and that address opens.
 *   J. (#732, 4 November 2026.) The erasure REVIEW pages — the two that show
 *      a person's own words in full and let them be emptied — asked as real
 *      requests, because until then nothing asked them anything at all. A
 *      Data Controller of this organisation sees this organisation's queue
 *      and reads its lines; a GLOBAL ADMINISTRATOR who has not been granted
 *      the role is REFUSED, which is the whole reason the role exists and was
 *      the one behaviour nothing requested; an administrator of this
 *      organisation and an ordinary member are refused too; naming the other
 *      organisation's request by number is refused on both pages; a press
 *      with no form token, or with one that is not this session's, is
 *      refused; and a real decision empties the cell, is recorded naming who
 *      decided, changes what the page then shows, and reaches no record as
 *      words.
 *   K. (#703 round 8.) What a giver is told will happen to their own gifts.
 *   L. (#940; D-117, D-118.) How long the activity log is kept, as the privacy
 *      page and the privacy notice say it for THIS organisation: a number of
 *      days, the organisation's own or the installation's, or kept for ever by
 *      either's choice - every state drawn over HTTP and compared with literal
 *      words written in the test.
 *   M. (#977, 11 November 2026.) An account erased on request cannot be
 *      changed or switched back on through any page, by anybody: the Users
 *      page's save (Active ticked, a new address, a new name, the global
 *      administrator box), placing an account that belongs to no
 *      organisation, an organisation's own member list, and offboarding.
 *      Each is pressed by a GLOBAL administrator, refused with the reason,
 *      and leaves the account row byte for byte as it was, with the Gift Aid
 *      claim file still naming the giver from the kept record and the
 *      statements list still leaving them out; each page still works for a
 *      live account; every refusal is recorded.
 *   N. (#964, D-140.) Somebody WITHOUT an account who suggested an event: a
 *      visitor suggests one through the real form and an administrator
 *      approves it; filing a request for the address is refused without the
 *      "I checked who is asking" tick, for something that is not an address,
 *      for an ordinary member and without the form token; once filed, Execute
 *      empties the name and address on that address's suggestions at this
 *      organisation and clears the approval e-mail's send-log and mail-queue
 *      rows - and leaves alone the same address at another organisation, a
 *      longer address containing it, the address with an accent the database
 *      calls equal, and a MEMBER whose account uses the same address. Five
 *      faults planted in the running copy, each turning its check red.
 *   O. (#929, D-142.) A worker's own data download: My data's page, its
 *      JSON download and the full data copy each LIST the pastoral visits,
 *      care cases, DBS checks and children's check-ins and check-outs that
 *      carry the worker's name - kind, the day cut from the stored text,
 *      organisation
 *      and a fixed sentence - and hand over nothing about the other person,
 *      no record number, and nothing from an organisation the worker has
 *      left or that is switched off (counted instead, D-151). A member who
 *      made none sees none. A failed read of one kind, and of the person's
 *      organisations, is driven and must be named, never shown as none.
 *      Nine faults planted in the running copy, each turning its check red.
 *   P. (#1012.) A member's own DBS checks: the full data copy hands over
 *      both of Della's checks - number, type, certificate reference, dates,
 *      status, when recorded and notes, each exactly as stored - and never
 *      who recorded them; the check she recorded for another member is not
 *      handed over as hers; My data counts exactly the rows the copy holds;
 *      a member with none gets none; and the privacy policy's "Your rights"
 *      section is pinned as rendered. A failed read of the checks is DRIVEN
 *      (a column renamed for the moment): the copy refuses to answer, and My
 *      data and Request erasure say "could not be read" rather than leaving
 *      the row out. Nine faults planted, each turning its check red.
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
 * - That an erased person's OPEN SESSION is ended. It now is, on their
 *   next request (`Auth::ensureSession()`, since 12 October 2026), but this
 *   file does not test it: `tools/access-pages-selftest.php`, part K3, does,
 *   through these same pages.
 * - Two administrators pressing at the same moment.
 * - Part I presses three of the fifteen redirects on the administrator's
 *   handler (a request put back, a request that does not exist, a request
 *   carried out) and five of the eight on "Delete my account". The rest are
 *   held to the rule by reading their text:
 *   `tools/audit-checks/check_site_aware_addresses.py`. That check reads
 *   whether an address is built by the helper, not whether it is the RIGHT
 *   address, so a redirect it does not press could point at a page that
 *   does not exist and nothing here would say so.
 * - Part I: the link inside the confirmation EMAIL. It is built in
 *   `GdprEraser::fileRequest()`, not in a page, and carries no organisation
 *   in front; no mail is sent here, so it is not followed.
 * - Section J: a Data Controller of one organisation opening ANOTHER
 *   organisation's address. `SiteGate` refuses a non-member on every address
 *   of an organisation (#511) with the same HTTP 403 the capability gate
 *   gives, so such a request cannot say which gate refused it. The boundary
 *   is asked the way it really bites instead: at the person's own address,
 *   naming the other organisation's request by number.
 * - Section J: two people deciding the same line at the same moment, and a
 *   line past `ErasureReview::SHOW_AT_MOST`. The first needs two overlapping
 *   requests, which one worker cannot give, so such a test would pass on
 *   broken code; the second needs 501 rows. Both are proved at class level by
 *   `tools/erasure-review-selftest.php` (its row lock, and its section J).
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
const EXPECTED_CHECKS = 196; // #1012 check round 1 added five: the driven
                            // failed read (P6b the copy refuses, P6c My data
                            // and Request erasure say "could not be read")
                            // and three plants (P13b, P13c, P13d). 196.
                            // #1012 added section P (fourteen: the premise,
                            // the copy's two checks field by field, the
                            // recorder absent, another member's check absent,
                            // My data's count agreeing with the copy, a member
                            // with none, the privacy policy's "Your rights"
                            // pinned as rendered, six plants and the clean run).
                            // It also moved the pin-file check to after it,
                            // which changes no count. 191.
                            // #929 round 3 added three: the driven "no such
                            // table" view with the singular sentence (O9b),
                            // and its two plants (O18b, O18c). 177.
                            // #929 round 2 rebuilt section O as nineteen: the
                            // premise, seven observations, two DRIVEN read
                            // failures, nine plants and the clean run. 174.
                            // 155 until #929 added section O (thirteen: the
                            // premise, the list in the My data download, the
                            // records in an organisation left counted not listed,
                            // the full data copy, the page, nothing about the
                            // other person anywhere, a member who made none, five
                            // plants and the clean run after them).
                            // 133 until #964 added section N (sixteen: the
                            // premise through the real form and approval, the
                            // refusals, the filing, the list page, the run and
                            // what it did and did not reach, the report, a
                            // cancel, five plants and the clean run after them).
                            // #964 round 2 added three: the old activity
                            // records (N8b), and two plants - the Errors page
                            // left alone (N16) and the activity step reaching
                            // a member's record (N17). 152.
                            // #964 round 3 added three: the real form's note
                            // box and its warning (N3a), and two plants - the
                            // old record's description changed alone (N19) and
                            // the note box removed from the page (N20). 155.
                            // 117 until #977 added section M (sixteen: the
                            // premise, four saves of an erased account, the
                            // wording an ordinary administrator gets, the
                            // list page, placing, an organisation's page,
                            // offboarding, a live control for each page, the
                            // refusal records, and the state at the end).
                            // 80 until #703 (D-016) added two: the two writers
                            // record who made a visit and who recorded a DBS check
                            // BY NAME, driven through the real pages. #703 round 2
                            // added four: the case page (D-110) and the check-in and
                            // check-out pages (D-111) record the worker by name; a
                            // renamed worker is shown under the name they have NOW;
                            // and the privacy page says what keeping the number
                            // costs (D-113). 82 + 4 = 86. Round 3 added one: My
                            // data tells a worker what is kept (D-113). 87.
                            // Round 6 added one: the help page's list, pinned as
                            // rendered (My data and the privacy notice became
                            // rendered pins in place). 88.
                            // Round 7 added one: the pin file holds exactly the
                            // pins compared, each once (the JSON's schema was run
                            // by nothing). 89.
                            // Round 8 added section K: what a giver is told
                            // will happen to their own gifts, under both
                            // rules, then checked against a real erasure. 93.
                            // Round 9 added K5-K8: a record past its date, an
                            // envelope-only giver, words that follow the
                            // organisation's state, and both erased. 97.
                            // Round 10 added K9-K11: a hold at the organisation,
                            // one on the whole installation, and a giver in two
                            // organisations. 100.
                            // Round 11 added K11e (an organisation's name is
                            // text, never markup) and K12 (both holds). 102.
                            // Round 12 added K11r (which organisations a lose
                            // row names) and K13 (every hold state, one
                            // table). 104.
                            // #940 added section L: L1 (every activity-log state,
                            // one table, both privacy pages) and L2 (the words
                            // when the figure could not be read), L3 and L4 (the
                            // first steps' step 5 and the Retention page's card,
                            // pinned as rendered). 108.
                            // Round 2 added L5 (the Retention page's subtitle,
                            // which ignored "never"). 109.
                            // Round 3 added L6-L8 (the card's explanation in both
                            // views, and the three "Never delete" messages). 112.
                            // Round 4 added L9 (the organisation's form's own
                            // refusals, under never and under 30 days). 113.
                            // #927 added one: an expense claim that keeps the
                            // account number says so in its LABEL, on Request
                            // erasure and on My data, both pinned as rendered. 114.
                            // Its check round 1 added two: no My data badge
                            // states a removal as done (they say what WOULD
                            // happen), and the claim's label follows the
                            // database's own answer, measured by making the
                            // column emptiable for one request. 116.
                            // #941's check round 4 added one: the giving-records
                            // page says "No name was kept" for a nameless kept
                            // row beside a LIVE account, never that the account
                            // "had already been emptied". 117.

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
// 🧑‍⚖️ Section J (#732). FOUR PEOPLE, ONE GROUND EACH. The lesson this follows
//    is #616's: an account holding several grounds at once cannot tell them
//    apart, so each of these holds exactly one thing and nothing else. ADM
//    (an administrator of A, and nothing more) and ROOT (a global
//    administrator, and nothing more) are the other two grounds, and they
//    already exist above.
const DAN = 917021; // an ordinary member of A who holds the Data Controller role in A, and nothing else
const DEB = 917022; // an ordinary member of B who holds the Data Controller role in B, and nothing else
const FAY = 917024; // an ordinary member of A with no role and no flag at all
const PIP = 917023; // a member of BOTH organisations, with a line of free text in each. Never signs in
const GIO  = 917017; // a member of A who gives at A and at B: his run stops part way (section H)
const BEN  = 917020; // a member of B ONLY: his request is carried out by B's administrator (section I). Made there, not at the start, so B's list is as the earlier sections expect
const HAL  = 917018; // a member of A who gives at A: his request is never run, and is cancelled (section H)
const IVY  = 917019; // a member of A with NO giving records: her run stops after deleting her membership (section H)

const REQ_STUCK = 917101; // Nia's, at "processing", its run dead for 20 minutes
const REQ_LIVE  = 917102; // Cal's, at "processing", a run showed signs of life 1 minute ago
const REQ_B_PUT = 917103; // ORG_B's, at "processing", dead for 20 minutes
const REQ_B_ASK = 917104; // ORG_B's, waiting to be confirmed
const REQ_B_RUN = 917980; // ORG_B's, Ben's, at "pending review": carried out by B's administrator (section I). Far above the others for the same reason as REQ_KEPT below: 917105 onwards are taken by the requests the pages file
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

// 🧑‍⚖️ Section J's own rows (#732), and why these two tables.
//    `tblSalvationCards.prayerRequest` is marked `needsReview` in
//    `_core/personal-data-catalogue.php` AND the table records which
//    organisation a row belongs to, so it is the one that proves the
//    organisation boundary: the same person has a card in each organisation
//    and only one of them may ever be shown.
//    `tblResourceBooking.notes` is marked too and the table records NO
//    organisation, so its row is the one the page has to LABEL as possibly
//    belonging elsewhere. Between them they cover both halves of the rule.
const CARD_A    = 917210; // a response card in A naming Pip as the follow-up worker
const CARD_B    = 917211; // the same, in B
const BOOKING_J = 917212; // Pip's own booking of A's hall, with a note in her own words

// The three lines of free text, written out so that a check can look for them
// on a page and in a record. Deliberately free of quotes and ampersands: the
// page escapes those, and a check comparing escaped text with unescaped text
// fails for a reason that has nothing to do with what is being tested.
const CARD_TEXT_A = 'ERASURE REVIEW A SIDE please pray for my brother Tom who is in hospital';
const CARD_TEXT_B = 'ERASURE REVIEW B SIDE my neighbour Ruth has asked for a visit';
const NOTE_TEXT   = 'ERASURE REVIEW BOOKING NOTE Pip asked for the side room because of her hearing';

const ROLE_DC_A = 917030; // the Data Controller role, A's own copy of it
const ROLE_DC_B = 917031; // and B's
const ROLE_CARE_A = 917032; // the Care Team role, A's own copy: given to Mel in section F, so she can record a visit through the real page (#703)
const DBS_MEL     = 917213; // a DBS check Mel recorded for Cal (#703): it must keep her name after her erasure
const ROLE_KIDS_A = 917033; // the Kids Team role, A's own copy: given to Mel in section F, so she can check a child in (#703, D-111)
const CHILD       = 917214; // a child in A, checked in by Mel and out by Ada through the real pages (#703, D-111)
const VISIT_ADM   = 917215; // a visit Ada made under the name she had THEN: the page must show her name NOW (#703)

const REQ_PIP_A = 917910; // Pip's request, filed in A (section J)
// #703 round 8, section K: three givers who sign in, to see on THEIR OWN My data
// what an erasure will do with their gifts and envelopes.
const GWEN   = 917041; // one gift, no tax declaration or statement: it will lose her name
const DORA   = 917042; // one gift inside her tax declaration: it will keep her name
const BO     = 917043; // one of each, plus an envelope in an open count and one in a closed count
const REQ_BO = 917960; // Bo's request, carried out at the end of section K
// #703 round 9: the two cases round 8's outlook got wrong.
const PAT     = 917044; // gave under a declaration long ago and not since: his kept gift is already past its date
const EVE     = 917045; // no kept gift - only one from before her declaration - and an envelope in an open count the declaration covers
const REQ_PAT = 917961; // Pat's request, carried out in section K
const REQ_EVE = 917962; // Eve's request, carried out in section K
const QUIN    = 917046; // a member of BOTH organisations, a declared gift at each (round 10)
const RUE     = 917047; // a member of BOTH: a gift that loses the name at A only, an envelope that loses it at B only (round 12)
const REQ_PIP_B = 917911; // Pip's request, filed in B
// #977, section M: an erased account nobody may change, and three live
// accounts that each stand for "the same page still works for a live one".
// One ground each, so a control cannot pass for the wrong reason (#616).
const ZED     = 917048; // a giver at A, with a Gift Aid declaration; erased at the start of section M
const REQ_ZED = 917963; // Zed's request, carried out in section M
const LIV     = 917049; // a live member of A: the control for the edit page and for adding to an organisation
const UNP     = 917050; // a live account in NO organisation: the control for placing an account
const OFF     = 917051; // a live member of A: the control for offboarding
// #929, section O: a worker's own data download (D-142, D-151).
const WIL        = 917055; // a worker, member of A (and of switched-off C)
const NELL       = 917056; // a member of A who made none of these records
const CHK        = 917057; // the person Wil recorded a DBS check for
const ORG_C      = 917003; // a SWITCHED-OFF organisation Wil is still an active member of
const O_CASE_A   = 917231; // a care case Wil opened in A
const O_VISIT_A  = 917232; // a visit Wil made on it, 23:30 on the night Nuuk's clocks go forward
const O_CHILD    = 917233; // a child in A
const O_CHECKIN  = 917234; // that child's check-in, in AND out by Wil
const O_DBS      = 917235; // a DBS check Wil recorded for CHK
const O_CASE_B   = 917236; // a care case Wil opened in B
const O_VISIT_B  = 917237; // a visit Wil made on it
const O_CASE_C   = 917238; // a care case Wil opened in C
// #1012, section P: a member's own DBS checks in their full data copy.
const P_DELLA     = 917058; // a member of A with two DBS checks of her own, who also recorded one for Otto
const P_REX      = 917059; // a worker with an account, who recorded Della's first check
const P_OTTO     = 917060; // a member of A whose check Della recorded
const P_PENNY      = 917061; // a member of A with no DBS checks
const P_DBS_ONE  = 917241; // Della's first check (recorded by Rex, 23:30 on the night Nuuk's clocks go forward)
const P_DBS_TWO  = 917242; // Della's second, older check: expired, no reference, no notes, recorder known by name only
const P_DBS_OTTO = 917243; // Otto's check, recorded by Della

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
    // 🧑‍⚖️ Section J's rows (#732). The decisions go FIRST, while the requests
    //    they hang off are still here to be joined to: the links are switched
    //    off just above, so nothing cascades and a row that a real server
    //    would lose with its parent has to be removed deliberately here.
    //
    //    **Not all of them BY NAME, which a draft of this comment claimed.**
    //    Most go by their organisation (`WHERE siteID IN (?, ?)`), and the
    //    booking goes with its resource (`WHERE resourceID = ?`). Nothing is
    //    actually left behind — the end-of-run count checks each one — but
    //    "by name" would send the next reader looking for a line that is not
    //    there.
    q('DELETE d FROM tblErasureReviewDecisions d JOIN tblErasureRequest r ON r.requestID = d.requestID WHERE r.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblSalvationCards WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUserRoles WHERE siteID IN (?, ?) OR userID BETWEEN 917000 AND 917999', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblRoles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE a FROM tblErasureAudit a JOIN tblErasureRequest r ON r.requestID = a.requestID WHERE r.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblErasureRequest WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblVisitorContact WHERE contactID = ?', 'i', [NOTE]);
    q('DELETE FROM tblVisitor WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblCareAccessLog WHERE caseID = ?', 'i', [CASE_ID]);
    q('DELETE FROM tblCareVisit WHERE caseID = ?', 'i', [CASE_ID]);
    // Section O (#929): visits on any case of the two organisations. The links
    // are off, so deleting a case below would leave its visits behind.
    q('DELETE v FROM tblCareVisit v JOIN tblCareCase c ON c.caseID = v.caseID WHERE c.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblDbsChecks WHERE userID BETWEEN 917000 AND 917999 OR recordedByID BETWEEN 917000 AND 917999');
    q('DELETE c FROM tblKidCheckins c JOIN tblKidProfiles k ON k.childID = c.childID WHERE k.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblKidProfiles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
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
    q('DELETE ce FROM tblCountEnvelopes ce JOIN tblCountSessions cs ON cs.countSessionID = ce.countSessionID WHERE cs.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblCountSessions WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblGivingCategory WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // Section H plants this to stop a run. Taken away here too, in case the
    // run was interrupted while it was in place.
    $db->query('DROP TRIGGER IF EXISTS `selftest_erasure_pages_stop_kept`');
    $db->query('DROP TRIGGER IF EXISTS `selftest_erasure_pages_stop_line`');
    // Section M (#977) offboards one live account through the real page.
    q('DELETE FROM tblOffboarding WHERE userID BETWEEN 917000 AND 917999');
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 917000 AND 917999');
    q('DELETE FROM tblEmailLog WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // Section N (#964): the visitor's suggestion and the rows put in by hand,
    // one of them a send-log row recorded under NO organisation.
    q('DELETE FROM tblEmailLog WHERE emailLogID BETWEEN 917500 AND 917599');
    q('DELETE FROM tblMailQueue WHERE siteID IN (?, ?) OR queueID BETWEEN 917400 AND 917499', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblEvents WHERE siteID IN (?, ?) OR eventID BETWEEN 917300 AND 917399', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 917000 AND 917999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblErrors WHERE userID BETWEEN 917000 AND 917999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUserSites WHERE siteID IN (?, ?) OR userID BETWEEN 917000 AND 917999', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM tblUsers WHERE userID BETWEEN 917000 AND 917999');
    q('DELETE FROM tblSettings WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // Section O (#929): the switched-off organisation C and what it holds.
    q('DELETE v FROM tblCareVisit v JOIN tblCareCase c ON c.caseID = v.caseID WHERE c.siteID = ?', 'i', [ORG_C]);
    q('DELETE FROM tblCareCase WHERE siteID = ?', 'i', [ORG_C]);
    q('DELETE FROM tblUserSites WHERE siteID = ?', 'i', [ORG_C]);
    q('DELETE FROM tblSites WHERE siteID = ?', 'i', [ORG_C]);
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

/**
 * 🔩 Put `tblExpenseClaims.userID` back to what `full_schema.sql` declares,
 *    `INT NOT NULL`, if it is not that now (#927 check round 2).
 *
 * The label check makes that column emptiable for ONE request. A run killed
 * in that moment left it emptiable, and every later run on that database then
 * tested a schema nobody ships. So this is asked in three places: in the check
 * itself (`finally`), in cleanup() (a normal end, exit(), a fatal error, and a
 * SIGINT or SIGTERM, which the handler below turns into an exit), and at the
 * START of every run, which is the only thing that can repair a run killed
 * with SIGKILL - nothing runs in a process killed that way. Only ever on a
 * selftest_ database, which the top of this file insists on.
 */
function putClaimColumnBack(): void
{
    $now = one("SELECT `IS_NULLABLE` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() "
        . "AND `TABLE_NAME` = 'tblExpenseClaims' AND `COLUMN_NAME` = 'userID'");
    if (($now['IS_NULLABLE'] ?? 'NO') === 'YES') {
        q('ALTER TABLE `tblExpenseClaims` MODIFY `userID` INT NOT NULL');
    }
}

/**
 * 🔩 Put `tblCareVisit` back as `full_schema.sql` builds it, if section O
 *    (#929) left it otherwise: its `visitedAt` column renamed (the driven
 *    "one kind cannot be read") or the whole table renamed (the driven "no
 *    such table").
 *
 * #929 check round 2 measured the fault this exists for: each rename was put
 * back only by a `finally`, and the SIGINT/SIGTERM handler below ends the run
 * with exit(), which does NOT run a `finally` - so one interrupted run left
 * the column renamed, and every later run on that database failed. Asked in
 * the same three places as putClaimColumnBack() (its note says why each), and
 * BEFORE removeWorld(), which deletes from `tblCareVisit` and would itself
 * fail on a renamed table. Only ever on a selftest_ database.
 */
function putSectionOBack(): void
{
    $tables = q("SELECT `TABLE_NAME` AS `t` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() "
        . "AND `TABLE_NAME` IN ('tblCareVisit', 'tblCareVisitP929')");
    $names  = array_map(static fn (array $row): string => (string) $row['t'], $tables);
    if (in_array('tblCareVisitP929', $names, true) === true && in_array('tblCareVisit', $names, true) === false) {
        q('RENAME TABLE `tblCareVisitP929` TO `tblCareVisit`');
    }
    $column = one("SELECT COUNT(*) AS `n` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() "
        . "AND `TABLE_NAME` = 'tblCareVisit' AND `COLUMN_NAME` = 'visitedAtP929'");
    if ((int) ($column['n'] ?? 0) > 0) {
        q('ALTER TABLE `tblCareVisit` RENAME COLUMN `visitedAtP929` TO `visitedAt`');
    }
}

/**
 * 🔩 Put `tblDbsChecks.notes` back, if section P (#1012) left it renamed (its
 *    driven "the checks cannot be read"). Asked in the same places as
 *    putSectionOBack(), for the same reason: a `finally` does not run when the
 *    SIGINT/SIGTERM handler ends the run with exit(), and nothing runs at all
 *    after SIGKILL, so the start of the next run is the last repair. Only ever
 *    on a selftest_ database.
 */
function putSectionPBack(): void
{
    $column = one("SELECT COUNT(*) AS `n` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() "
        . "AND `TABLE_NAME` = 'tblDbsChecks' AND `COLUMN_NAME` = 'notesP1012'");
    if ((int) ($column['n'] ?? 0) > 0) {
        q('ALTER TABLE `tblDbsChecks` RENAME COLUMN `notesP1012` TO `notes`');
    }
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
        putClaimColumnBack();
        putSectionOBack();
        putSectionPBack();
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

/**
 * 🧾 #703 rounds 6-7: a part of a page AS THE BROWSER RECEIVES IT, for the pins
 * on what an erased person is told (D-113). Reading the source missed what PHP
 * prints into a region and anything just outside the cut, so each region is
 * taken from the PARSED document (`Dom\HTMLDocument`), never a search of the
 * markup.
 *
 * WHAT IS COMPARED, exactly (runs of white space made one, nothing else
 * changed): the region's own markup - each of its nodes' rendered HTML,
 * attributes included - AND the tag and attributes of every ANCESTOR of the
 * region up to and including `<body>`. Pinning the ancestor chain is what
 * catches a wrapper that hides the region, whatever the method (`hidden`,
 * `d-none`, `d-md-none`, `invisible`, `collapse`, `visually-hidden`, an inline
 * `display`, `visibility` or `opacity` style, `<noscript>`, `<template>`),
 * without a list of hiding methods to keep complete. (Round 6 checked three
 * such methods by name; a list like that reads as complete and is not, so it
 * was removed.)
 *
 * ONE EXCEPTION: `<body data-print-date>` holds today's date, so its value is
 * written as "(today)" when it is shaped like a date; see below.
 *
 * WHAT STAYS OUTSIDE, said plainly: a `<style>` rule or a script ELSEWHERE on
 * the page that hides or rewrites the region. Whether text is visible is a
 * question only a real browser can answer, and none runs here.
 *
 * EXACTLY ONE: `$pick` must answer the region's nodes only when there is
 * exactly ONE such region on the page, and [] otherwise. Taking the FIRST match
 * let a correct copy placed higher up pass while the real one was changed - a
 * lesson this repository has paid for twice (#485, #763). An empty answer
 * fails the pin.
 *
 * @param callable(\Dom\HTMLDocument): list<\Dom\Element> $pick
 */
function renderedRegion(string $html, callable $pick): string
{
    $doc   = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $nodes = $pick($doc);
    if ($nodes === []) {
        return '';
    }
    $chain = [];
    for ($at = $nodes[0]->parentElement; $at !== null; $at = $at->parentElement) {
        $attrs = '';
        foreach ($at->attributes as $attr) {
            // The ONE value that is allowed to differ: `<body data-print-date>`
            // is today's date (for a printed page's footer), so pinned as it
            // is it would fail every day after the pin was taken. Only that
            // attribute, and only a value shaped like a date; anything else in
            // it, and every other attribute, is compared exactly.
            $value  = ($attr->name === 'data-print-date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $attr->value) === 1) ? '(today)' : $attr->value;
            $attrs .= ' ' . $attr->name . '="' . $value . '"';
        }
        $chain[] = '<' . strtolower($at->tagName) . $attrs . '>';
        if (strtolower($at->tagName) === 'body') {
            break;
        }
    }
    $out = 'ANCESTORS: ' . implode(' ', array_reverse($chain)) . ' REGION: ';
    foreach ($nodes as $node) {
        $out .= $doc->saveHtml($node);
    }

    return trim((string) preg_replace('/\s+/', ' ', $out));
}

/** Where the pins are kept: a plain-text sidecar beside this file. */
const RENDERED_PINS = __DIR__ . '/erasure-pages-selftest.rendered-pins.txt';

/**
 * The pins, read from the sidecar: lines starting with `#` are notes, a line
 * `== <name>` starts a pin, and the next line is its value. Answers the pins,
 * and every problem with the FILE itself (a name given twice, a line that is
 * none of those) - checked once at the end by `pinFileProblems()`, together
 * with any pin the file holds that no check compared.
 *
 * #703 round 7: the pins used to be JSON with a schema beside it, and nothing
 * ran the schema - an extra key it forbade passed 88 of 88. The repository's
 * own schema validator is Python and this test is PHP, and all the schema
 * asked was "exactly these names, each once", which `pinFileProblems()` checks
 * directly; so the pins became a plain-text sidecar, as
 * tools/audit-checks/member-language.pinned.txt is, and the schema was deleted.
 *
 * @return array{pins: array<string, string>, problems: list<string>}
 */
function readRenderedPins(): array
{
    static $read = null;
    if ($read !== null) {
        return $read;
    }
    $pins     = [];
    $problems = [];
    $name     = null;
    $lines    = preg_split('/\R/', (string) @file_get_contents(RENDERED_PINS)) ?: [];
    foreach ($lines as $number => $line) {
        if ($name !== null) {
            if (isset($pins[$name]) === true) {
                $problems[] = 'the pin "' . $name . '" is given twice';
            }
            $pins[$name] = $line;
            $name        = null;
        } elseif (str_starts_with($line, '== ') === true) {
            $name = substr($line, 3);
        } elseif ($line !== '' && str_starts_with($line, '#') === false) {
            $problems[] = 'line ' . ($number + 1) . ' is neither a note, a pin\'s name nor its value';
        }
    }
    if ($name !== null) {
        $problems[] = 'the pin "' . $name . '" has no value';
    }

    return $read = ['pins' => $pins, 'problems' => $problems];
}

/** @var array<string, string> What each rendered pin received on this run. */
$renderedSeen = [];

/**
 * Compares one region with its pin. Changing what one of these says means
 * changing its pin, on purpose. To see what a run rendered, set
 * SELFTEST_WRITE_RENDERED_PINS to a file path: the run writes what it saw
 * there in the sidecar's own form (and still fails if it differs), so a
 * re-pin is a deliberate act of copying that file over the sidecar.
 */
function renderedPin(string $key, string $region): bool
{
    global $renderedSeen;
    $renderedSeen[$key] = $region;
    $dump = getenv('SELFTEST_WRITE_RENDERED_PINS');
    if (is_string($dump) && $dump !== '') {
        $out = "# Rendered pins for tools/erasure-pages-selftest.php (#703). See that file's renderedRegion().\n";
        foreach ($renderedSeen as $name => $value) {
            $out .= "\n== " . $name . "\n" . $value . "\n";
        }
        file_put_contents($dump, $out);
    }

    return $region !== '' && $region === (readRenderedPins()['pins'][$key] ?? null);
}

/**
 * Everything wrong with the sidecar as a whole: a problem reading it, and any
 * pin it holds that no check compared on this run (an extra pin is a pin
 * guarding nothing, which reads as cover). Asked once, after the last pin.
 *
 * @return list<string>
 */
function pinFileProblems(): array
{
    global $renderedSeen;
    $read     = readRenderedPins();
    $problems = $read['problems'];
    foreach (array_keys($read['pins']) as $name) {
        if (array_key_exists($name, $renderedSeen) === false) {
            $problems[] = 'the pin "' . $name . '" is compared by no check';
        }
    }

    return $problems;
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
// A SIGINT (Ctrl-C) or SIGTERM ends PHP WITHOUT running shutdown functions
// unless it is caught, so it is caught and turned into an exit, which does
// run cleanup(). (#927 check round 2: a run killed while the claim's column
// was emptiable left it so.) SIGKILL cannot be caught; the repair below, at
// the start of the next run, is what covers that.
if (function_exists('pcntl_async_signals') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function (int $signal): void {
            echo "\nFAIL — stopped by signal {$signal}; cleaning up before exiting.\n";
            exit(1);
        });
    }
}
putClaimColumnBack();
putSectionOBack();
putSectionPBack();
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
// #703 (D-016): a visit carries its visitor's NAME as well as number, as
// `care/visit-save.php` writes it - which section F drives for real. So does a
// DBS check Mel recorded, as `admin/safeguarding/dbs-save.php` writes it.
q("INSERT INTO tblCareVisit (visitID, caseID, visitedByID, visitedByName, kind, notes) VALUES (?, ?, ?, 'Mel Member', 'visit', 'Visited Cal at home; in good spirits.')", 'iii', [VISIT, CASE_ID, MEL]);
q("INSERT INTO tblDbsChecks (dbsCheckID, userID, dbsType, issuedDate, expiresAt, status, recordedByID, recordedByName) VALUES (?, ?, 'enhanced', '2025-01-10', '2028-01-10', 'valid', ?, 'Mel Member')", 'iii', [DBS_MEL, CAL, MEL]);
// A visit Ada made when her account had another name (#703): the name written
// on a visit is the name THEN, and while her account is live the page must
// show the name she has NOW, so a renamed worker is not shown under an old one.
q("INSERT INTO tblCareVisit (visitID, caseID, visitedByID, visitedByName, kind, notes) VALUES (?, ?, ?, 'Ada Earlier-Name', 'call', 'Ada rang Cal about the rota.')", 'iii', [VISIT_ADM, CASE_ID, ADM]);
q('INSERT INTO tblKidProfiles (childID, siteID, fullName, isActive) VALUES (?, ?, ?, 1)', 'iis', [CHILD, ORG_A, 'Erasure child Kit']);
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
// #940: the activity log's installation-wide figure is set to the built-in year
// for the run, because the privacy notice and the Retention page now PRINT it,
// and their pins are taken at 365 days. Another test on the same database could
// leave it elsewhere (retention-caps-selftest.php left 30 until #947 made it put
// every setting back), which made this test's pins fail for a reason that had
// nothing to do with the pages. Kept after #947 because the pins need a KNOWN
// figure whatever state the database arrives in, not only after that one test.
foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'path', 'audit.retentionDays' => '365'] as $key => $value) {
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
        // No opcache (#964): section N plants faults in the scratch copy of two
        // classes between requests, and a cached copy would be checked for a
        // newer file only every couple of seconds - the plant could miss, or
        // worse, the putting-back could. access-pages-selftest.php does the same.
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
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

// Part I (package B2b) checks where each press below sent the browser.
$landings = [];
$noToken  = req($jar['mel'], 'POST', '/account/delete/confirm', ['confirm_phrase' => 'DELETE MY ACCOUNT']);
$landings['"Delete my account" with no form token'] = $noToken['location'];
$told    = message(req($jar['mel'], 'GET', '/account/delete')['body']);
ok('with NO form token nothing is filed, and the person is told nothing was changed',
    requestsOf(MEL) === [] && str_contains($told, 'Nothing was changed'), 'HTTP ' . $noToken['status'] . ' ' . $told);

$form  = req($jar['mel'], 'GET', '/account/delete');
$landings['"Delete my account" with the wrong words'] = req($jar['mel'], 'POST', '/account/delete/confirm', ['csrf_token' => token($form['body']), 'confirm_phrase' => 'delete my account'])['location'];
$told = message(req($jar['mel'], 'GET', '/account/delete')['body']);
ok('with the wrong words (small letters) nothing is filed, and the page says why', requestsOf(MEL) === [] && str_contains($told, 'did not match'), $told);

$form = req($jar['root'], 'GET', '/account/delete');
$landings['"Delete my account" by a root administrator (refused)'] = req($jar['root'], 'POST', '/account/delete/confirm', ['csrf_token' => token($form['body']), 'confirm_phrase' => 'DELETE MY ACCOUNT'])['location'];
$told = message(req($jar['root'], 'GET', '/account/delete')['body']);
ok('a ROOT ADMINISTRATOR is refused, and nothing is filed', requestsOf(ROOT) === [] && str_contains($told, 'Root admins cannot'), $told);

$form  = req($jar['mel'], 'GET', '/account/delete');
$good  = token($form['body']);
$press = req($jar['mel'], 'POST', '/account/delete/confirm', ['csrf_token' => $good, 'confirm_phrase' => 'DELETE MY ACCOUNT', 'userID' => CAL, 'siteID' => ORG_B]);
$land  = req($jar['mel'], 'GET', '/account/my-data');
$filed = requestsOf(MEL);
$landings['"Delete my account", a good press'] = $press['location'];
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
$landings['"Delete my account", the same press again'] = req($jar['mel'], 'POST', '/account/delete/confirm', ['csrf_token' => $good, 'confirm_phrase' => 'DELETE MY ACCOUNT'])['location'];
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
// 🧾 #927: Cal's expense claim is "anonymised" and KEEPS his account number
//    on purpose (D-113). Its label used to be "kept, with some details about
//    you removed" - true word by word, and read as "nothing links it to me any
//    more". The label is GdprEraser::inventoryWords() now, which asks whether
//    the number stays (the rule the erasure itself follows), and the row is
//    pinned AS RENDERED, on both pages that print one, so the words cannot
//    drift back without the pin being changed on purpose. One `<li>` on Request
//    erasure, one `.row` on My data, each found exactly once.
$askClaimRow = renderedRegion($askPage['body'], static function (\Dom\HTMLDocument $doc): array {
    $rows = [];
    foreach ($doc->querySelectorAll('li') as $li) {
        $code = $li->querySelector('code');
        if ($code !== null && trim((string) $code->textContent) === 'tblExpenseClaims') {
            $rows[] = $li;
        }
    }

    return count($rows) === 1 ? $rows : [];
});
$calDataHtml  = (string) req($jar['cal'], 'GET', '/account/my-data')['body'];
$dataClaimRow = renderedRegion($calDataHtml, static function (\Dom\HTMLDocument $doc): array {
    $rows = [];
    foreach ($doc->querySelectorAll('.portal-data-list > .row') as $row) {
        $code = $row->querySelector('code');
        if ($code !== null && trim((string) $code->textContent) === 'tblExpenseClaims') {
            $rows[] = $row;
        }
    }

    return count($rows) === 1 ? $rows : [];
});
// Both compared on their own lines, so neither can be skipped by an && that
// stops early (which left a pin "compared by no check" once before).
$askClaimPinned  = renderedPin('request erasure tblExpenseClaims', $askClaimRow);
$dataClaimPinned = renderedPin('my-data tblExpenseClaims', $dataClaimRow);
ok('AN EXPENSE CLAIM THAT KEEPS THE ACCOUNT NUMBER SAYS SO IN ITS LABEL: Cal\'s claim row on Request erasure and on My data, each found exactly once and as pinned, says "still carrying your account number" beside the reason that explains it (#927, D-113)',
    $askClaimPinned === true && $dataClaimPinned === true
    && str_contains($askClaimRow, 'kept, with some details about you removed but still carrying your account number')
    && str_contains($dataClaimRow, 'kept, with some details about you removed but still carrying your account number'),
    mb_substr($askClaimRow, 0, 400) . ' | ' . mb_substr($dataClaimRow, 0, 400));

// 🕰️ #927 check round 1: My data lists what is held NOW. Its badge printed the
//    erasure's words bare, so a record still held read "deleted" and an
//    un-erased claim "kept, with some details about you removed" - untrue about
//    the present, in the reassuring direction. Every badge must be framed as
//    what WOULD happen. Read from two members' pages (Cal's and, before her
//    erasure, Mel's), and each must have badges, so the check cannot pass on
//    a page that drew none.
$badgesOf = static function (string $html): array {
    $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $out = [];
    foreach ($doc->querySelectorAll('.portal-data-list > .row .badge') as $badge) {
        $out[] = trim((string) preg_replace('/\s+/', ' ', (string) $badge->textContent));
    }

    return $out;
};
$badgesCal = $badgesOf($calDataHtml);
$badgesMel = $badgesOf((string) req($jar['mel'], 'GET', '/account/my-data')['body']);
$unframed  = array_values(array_filter(array_merge($badgesCal, $badgesMel),
    static fn (string $b): bool => preg_match('/^would be .+ if you ask to be erased$/', $b) !== 1));
ok('NO MY DATA BADGE STATES A REMOVAL AS DONE: every one, on two members\' pages, says what WOULD happen "if you ask to be erased" (#927 check round 1)',
    count($badgesCal) > 0 && count($badgesMel) > 0 && $unframed === [],
    count($badgesCal) . ' and ' . count($badgesMel) . ' badges; not framed: ' . json_encode($unframed));

// 🔢 #927 check round 1: THE LABEL FOLLOWS THE DATABASE'S OWN ANSWER, not a
//    list of tables. On a fresh schema "the claim keeps the number" and
//    "the table is tblExpenseClaims" agree, so only a changed column can tell
//    a rule from a hard-coded answer. The claim's `userID` is made NULLABLE
//    for one request (the erasure would then really drop the number, so the
//    label must stop saying it stays), then put back exactly as it was, and
//    the label must say it again. Each page load is its own PHP process, so
//    nothing remembered from an earlier request can answer for the database.
$claimColumn = static fn (): ?array => one(
    "SELECT `IS_NULLABLE`, `COLUMN_TYPE`, `COLUMN_DEFAULT`, `COLUMN_COMMENT` FROM `information_schema`.`COLUMNS` "
    . "WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblExpenseClaims' AND `COLUMN_NAME` = 'userID'"
);
$claimLabel = static function () use (&$jar): string {
    $doc = \Dom\HTMLDocument::createFromString((string) req($jar['cal'], 'GET', '/account/erasure-request')['body'], LIBXML_NOERROR);
    foreach ($doc->querySelectorAll('li') as $li) {
        $code = $li->querySelector('code');
        if ($code !== null && trim((string) $code->textContent) === 'tblExpenseClaims') {
            return trim((string) $li->querySelectorAll('strong')->item(1)?->textContent);
        }
    }

    return '(no claim row)';
};
$claimWas = $claimColumn();
q('ALTER TABLE `tblExpenseClaims` MODIFY `userID` INT NULL');
try {
    $labelWhenNullable = $claimLabel();
} finally {
    // Put back whatever happens in between; cleanup() and the start of the
    // next run ask the same function, for a run that never reaches here.
    putClaimColumnBack();
}
$labelWhenBack = $claimLabel();
ok('THE CLAIM\'S LABEL FOLLOWS THE DATABASE, NOT A LIST OF TABLES: with its account-number column made emptiable it says only "some details about you removed"; put back exactly as it was (checked), it says the number stays again (#927 check round 1)',
    ($claimWas['IS_NULLABLE'] ?? '') === 'NO' && $claimColumn() === $claimWas
    && $labelWhenNullable === 'kept, with some details about you removed'
    && $labelWhenBack === 'kept, with some details about you removed but still carrying your account number',
    json_encode(['column before' => $claimWas, 'column after' => $claimColumn(), 'emptiable' => $labelWhenNullable, 'put back' => $labelWhenBack]));
ok('the page says in words what happens to each kind of record: "kept" for a Gift Aid declaration, and never "retaind"',
    $askPage['status'] === 200 && preg_match('/tblGiftAidDeclaration — 1 row\(s\) will be kept\s*\./', $askText) === 1
    && str_contains($askText, 'retaind') === false, mb_substr($askText, 0, 700));
$landings['"Request data erasure", a good press'] = req($jar['cal'], 'POST', '/account/erasure-request', ['csrf_token' => token($askPage['body'])])['location'];
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
$confirmPage = $link['body']; // read again in part I
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
// 🧾 #703 (D-016): the two writers, DRIVEN. Mel is given the Care Team role
//    in A and records a visit through the real page; A's administrator records
//    a DBS check through the real page. Each row must carry the maker's NAME
//    read from their own account, because the erasure keeps that name and
//    empties the account. A check that only read the source could not see a
//    writer that stopped running; this one can.
q("INSERT INTO `tblRoles` (`roleID`, `siteID`, `roleKey`, `roleName`, `description`, `isStandard`) VALUES (?, ?, 'care_team', 'Care Team', 'Opens the confidential pastoral care register', 1)", 'ii', [ROLE_CARE_A, ORG_A]);
q('INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES (?, ?, ?)', 'iii', [MEL, ROLE_CARE_A, ORG_A]);
$caseForm = req($jar['mel'], 'GET', '/care/case?id=' . CASE_ID);
$visitSaved = req($jar['mel'], 'POST', '/care/visit-save', ['csrf_token' => token($caseForm['body']), 'caseID' => (string) CASE_ID, 'kind' => 'call', 'notes' => 'Mel rang Cal after the operation.']);
$melVisit = one('SELECT visitedByID, visitedByName FROM tblCareVisit WHERE caseID = ? AND notes = ?', 'is', [CASE_ID, 'Mel rang Cal after the operation.']);
ok('the pastoral visit page records who made a visit by NAME as well as number, read from their own account (#703)',
    $caseForm['status'] === 200 && $visitSaved['status'] === 302
    && $melVisit !== null && (int) $melVisit['visitedByID'] === MEL && $melVisit['visitedByName'] === 'Mel Member',
    'form HTTP ' . $caseForm['status'] . ', save HTTP ' . $visitSaved['status'] . ' ' . $visitSaved['location'] . '; row: ' . json_encode($melVisit));
$dbsForm  = req($jar['adm'], 'GET', '/admin/safeguarding/dbs');
$dbsSaved = req($jar['adm'], 'POST', '/admin/safeguarding/dbs/save', ['csrf_token' => token($dbsForm['body']), 'userID' => (string) CAL,
    'dbsType' => 'basic', 'referenceNumber' => 'P703-DRIVEN', 'issuedDate' => '2026-01-05', 'expiresAt' => '2029-01-05', 'status' => 'valid']);
$admDbs = one('SELECT recordedByID, recordedByName FROM tblDbsChecks WHERE userID = ? AND referenceNumber = ?', 'is', [CAL, 'P703-DRIVEN']);
// 🧾 #703 round 2: D-110 (who opened a care case) and D-111 (who checked a
//    child in, and out), each driven through its real page. Every accepted
//    post replaces the form token, so each press reads a fresh one first.
q("INSERT INTO `tblRoles` (`roleID`, `siteID`, `roleKey`, `roleName`, `description`, `isStandard`) VALUES (?, ?, 'kids_team', 'Kids Team', 'Runs children\'s check-in', 1)", 'ii', [ROLE_KIDS_A, ORG_A]);
q('INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES (?, ?, ?)', 'iii', [MEL, ROLE_KIDS_A, ORG_A]);
$careList  = req($jar['mel'], 'GET', '/care');
$caseSaved = req($jar['mel'], 'POST', '/care/case-save', ['csrf_token' => token($careList['body']), 'category' => 'family', 'summary' => 'P703 a case Mel opened about a family', 'personName' => 'Fen Family']);
$melCase   = one('SELECT caseID, openedByID, openedByName FROM tblCareCase WHERE siteID = ? AND summary = ?', 'is', [ORG_A, 'P703 a case Mel opened about a family']);
ok('the care register records who OPENED a case by NAME as well as number, read from their own account (#703, D-110)',
    $careList['status'] === 200 && $caseSaved['status'] === 302
    && $melCase !== null && (int) $melCase['openedByID'] === MEL && $melCase['openedByName'] === 'Mel Member',
    'list HTTP ' . $careList['status'] . ', save HTTP ' . $caseSaved['status'] . ' ' . $caseSaved['location'] . '; row: ' . json_encode($melCase));
// The check-in button is drawn only beside a search result, so search first.
$kidsForm  = req($jar['mel'], 'GET', '/kids/checkin?q=' . rawurlencode('Erasure child Kit'));
$checkedIn = req($jar['mel'], 'POST', '/kids/checkin/do', ['csrf_token' => token($kidsForm['body']), 'childID' => (string) CHILD, 'eventID' => '0']);
$badgeRow  = one('SELECT badgeCode FROM tblKidCheckins WHERE childID = ?', 'i', [CHILD]);
$outForm   = req($jar['adm'], 'GET', '/kids/checkout');
$checkedOut = req($jar['adm'], 'POST', '/kids/checkout', ['csrf_token' => token($outForm['body']), 'badgeCode' => (string) ($badgeRow['badgeCode'] ?? ''), 'pickupName' => 'Kit\'s grandmother']);
$kidRow    = one('SELECT checkedInByID, checkedInByName, checkedOutByID, checkedOutByName FROM tblKidCheckins WHERE childID = ?', 'i', [CHILD]);
ok('children\'s check-in records who checked a child IN and who checked them OUT by NAME as well as number, each read from their own account (#703, D-111)',
    $kidsForm['status'] === 200 && $checkedIn['status'] === 302 && $outForm['status'] === 200 && $checkedOut['status'] === 200
    && $kidRow !== null && (int) $kidRow['checkedInByID'] === MEL && $kidRow['checkedInByName'] === 'Mel Member'
    && (int) $kidRow['checkedOutByID'] === ADM && $kidRow['checkedOutByName'] === 'Ada Admin',
    'check-in form HTTP ' . $kidsForm['status'] . ', in HTTP ' . $checkedIn['status'] . ', out form HTTP ' . $outForm['status']
    . ', out HTTP ' . $checkedOut['status'] . '; row: ' . json_encode($kidRow));
ok('the DBS page records who recorded a check by NAME as well as number, read from their own account (#703)',
    $dbsForm['status'] === 200 && $dbsSaved['status'] === 302
    && $admDbs !== null && (int) $admDbs['recordedByID'] === ADM && $admDbs['recordedByName'] === 'Ada Admin',
    'form HTTP ' . $dbsForm['status'] . ', save HTTP ' . $dbsSaved['status'] . ' ' . $dbsSaved['location'] . '; row: ' . json_encode($admDbs));

// 🗣️ #703 round 3 (D-113): what a WORKER is told on My data before asking to
//    be erased. Mel has now made visits, opened a case, checked a child in and
//    recorded a DBS check, so her own page must say, for each, that her name
//    and number stay and can be matched back to her; and her account's own line
//    must say that SOME records keep the number. These are GdprEraser's own
//    reasons (inventory() shows those), which the round-2 check never read.
//    #703 round 6: pinned AS RENDERED, each row whole. Round 5 searched the
//    page's text for phrases, so a sentence the page appended to a row (M5), or
//    a row hidden by an attribute, left it green. The row is the element My data
//    draws for one kind of record (`.row` holding the table's name in <code>).
$myDataHtml = (string) req($jar['mel'], 'GET', '/account/my-data')['body'];
$myDataRow  = static fn (string $table): string => renderedRegion($myDataHtml, static function (\Dom\HTMLDocument $doc) use ($table): array {
    $rows = [];
    foreach ($doc->querySelectorAll('.portal-data-list > .row') as $row) {
        $code = $row->querySelector('code');
        if ($code !== null && trim((string) $code->textContent) === $table) {
            $rows[] = $row;
        }
    }

    return count($rows) === 1 ? $rows : [];
});
$myDataWrong = [];
foreach (['tblUsers', 'tblCareVisit', 'tblCareCase', 'tblKidCheckins', 'tblDbsChecks'] as $table) {
    $region = $myDataRow($table);
    if (renderedPin('my-data ' . $table, $region) === false) {
        $myDataWrong[] = $table . ($region === '' ? ' (not exactly one such row)' : ': ' . mb_substr($region, 0, 300));
    }
}
ok('MY DATA shows a worker, before they ask to be erased, exactly the pinned rows for their account and the visits, case, check-in and DBS check they made - each saying their name and number stay and can be matched back to them - each row found exactly once, its markup and every wrapper around it as pinned (#703, D-113)',
    $myDataWrong === [], implode(' | ', $myDataWrong));

$was = $pages();
// 🧾 #703: the name written on a visit is the name THEN. While the account is
//    live the page shows the name it has NOW (`care/case.php` asks
//    GdprEraser::emptiedAccountSql()), so this drives what the coverage test
//    could only read as text.
ok('while a worker\'s account is live, the pastoral page shows the name they have NOW, not the older name written on the visit (#703)',
    str_contains($was['visit'], 'Ada rang Cal about the rota.') && str_contains($was['visit'], 'Ada Admin')
    && str_contains($was['visit'], 'Ada Earlier-Name') === false, mb_substr($was['visit'], 0, 600));
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
    'visit'    => one('SELECT notes, visitedByID, visitedByName FROM tblCareVisit WHERE visitID = ?', 'i', [VISIT]),
    'driven'   => one('SELECT visitedByID, visitedByName FROM tblCareVisit WHERE caseID = ? AND notes = ?', 'is', [CASE_ID, 'Mel rang Cal after the operation.']),
    'dbs'      => one('SELECT userID, recordedByID, recordedByName FROM tblDbsChecks WHERE dbsCheckID = ?', 'i', [DBS_MEL]),
    'case'     => one('SELECT openedByID, openedByName FROM tblCareCase WHERE siteID = ? AND summary = ?', 'is', [ORG_A, 'P703 a case Mel opened about a family']),
    'checkin'  => one('SELECT checkedInByID, checkedInByName, checkedOutByID, checkedOutByName FROM tblKidCheckins WHERE childID = ?', 'i', [CHILD]),
    'approval' => one('SELECT decision, comments, userID FROM tblExpenseClaimApprovals WHERE claimID = ?', 'i', [CLAIM]),
    'booking'  => one('SELECT purpose, status, bookedByID FROM tblResourceBooking WHERE bookingID = ?', 'i', [BOOKING]),
    'giftAid'  => one('SELECT donorID, address FROM tblGiftAidDeclaration WHERE declarationID = ?', 'i', [GIFTAID]),
];
ok('other people\'s records are all still in the database, each with only Mel\'s number taken off: the visitor, the note, the approval, the booking; Cal\'s Gift Aid declaration is untouched; and the two pastoral visits, the DBS check, the care case and the child\'s check-in Mel made KEEP her number and her name (#703; D-016, D-110, D-111)',
    $kept === [
        'visitor'  => ['fullName' => 'Vera Visitor', 'assignedToID' => null],
        'note'     => ['summary' => 'Rang Vera; she will come on Sunday.', 'contactedByID' => null],
        // 🔁 CHANGED BY #703 (the owner's decision D-016, 3 November 2026):
        //    who made a pastoral visit, or recorded a safeguarding check, is
        //    KEPT. It used to expect `visitedByID` => null.
        'visit'    => ['notes' => 'Visited Cal at home; in good spirits.', 'visitedByID' => MEL, 'visitedByName' => 'Mel Member'],
        'driven'   => ['visitedByID' => MEL, 'visitedByName' => 'Mel Member'],
        'dbs'      => ['userID' => CAL, 'recordedByID' => MEL, 'recordedByName' => 'Mel Member'],
        // #703 round 2: the case Mel opened (D-110) and the child she checked in (D-111).
        'case'     => ['openedByID' => MEL, 'openedByName' => 'Mel Member'],
        'checkin'  => ['checkedInByID' => MEL, 'checkedInByName' => 'Mel Member', 'checkedOutByID' => ADM, 'checkedOutByName' => 'Ada Admin'],
        'approval' => ['decision' => 'Approved', 'comments' => 'Receipts all present.', 'userID' => null],
        // 🔁 CHANGED BY #723 (3 November 2026): the booker's stated reason is
        //    emptied now. It used to expect 'Welcome team supper' to survive —
        //    `bookedByID` was in ACTOR_COLUMNS, so the guard refused nullCols
        //    on it, and the owner's decision was that the booker is the row's
        //    SUBJECT. The booking itself, its times and its status are kept.
        'booking'  => ['purpose' => null, 'status' => 'pending', 'bookedByID' => null],
        'giftAid'  => ['donorID' => CAL, 'address' => '1 Made-up Road'],
    ], json_encode($kept));
$now = $pages();
ok('the VISITOR page still shows the follow-up note, with "Somebody no longer recorded" where Mel\'s name was',
    str_contains($now['note'], 'Rang Vera; she will come on Sunday.') && str_contains($now['note'], 'Somebody no longer recorded') && str_contains($now['note'], 'Mel Member') === false, mb_substr($now['note'], 0, 500));
// 🔁 CHANGED BY #703 (D-016). It used to require "Somebody no longer
//    recorded" and NO "Mel Member". Who made a visit is kept now, so the page
//    must still NAME Mel - from the name kept on the visit, because her own
//    account now reads "[Deleted User]" - and must never show that stand-in.
ok('the PASTORAL page still shows the visit and its notes, and still names Mel as who made it although her account is emptied (#703, D-016)',
    str_contains($now['visit'], 'Visited Cal at home; in good spirits.') && str_contains($now['visit'], 'Mel rang Cal after the operation.')
    && substr_count($now['visit'], 'Mel Member') >= 2 && str_contains($now['visit'], '[Deleted User]') === false
    && str_contains($now['visit'], 'Somebody no longer recorded') === false, mb_substr($now['visit'], 0, 500));
ok('the EXPENSE CLAIM\'s page still shows the approval line, with "Approver\'s details removed" where Mel\'s name was',
    str_contains($now['claim'], 'Receipts all present.') && str_contains($now['claim'], 'Approver\'s details removed') && str_contains($now['claim'], 'Mel Member') === false, mb_substr($now['claim'], 0, 500));
// 🔁 CHANGED BY #723, AND THE POINT OF THE CHECK IS NOW CLEARER. It used to
//    look for 'Welcome team supper', which was the booker's own words — those
//    are emptied now, so finding them would mean the erasure had failed. What
//    must still be true is the thing the check is NAMED for: the room does not
//    look free. `resources/resource.php` prints `$b['purpose'] ?? '(no purpose
//    given)'`, so the slot is still listed with that label, which is what this
//    now requires. The suite caught the stale expectation; no test in the #723
//    package could, because none of them renders this page.
ok('the ROOM\'s page still shows the booking, so the room does not look free for a time it is booked for',
    str_contains($now['room'], '(no purpose given)') && str_contains($now['room'], 'Somebody no longer recorded')
    && str_contains($now['room'], 'Welcome team supper') === false && str_contains($now['room'], 'Mel Member') === false,
    mb_substr($now['room'], 0, 500));
// 🔁 CHANGED BY #723 for the same reason. `resources/approvals.php` prints its
//    "Purpose:" paragraph only when the column is not empty, so an emptied
//    booking has no purpose line at all — the ROW is still there and still
//    awaiting a decision, which is what this check is for. Pinned by the
//    requester sentence, which survives, AND by the purpose being absent, so
//    the check fails in both directions: if the booking vanished, and if the
//    booker's words came back.
ok('the booking is still in the administrator\'s approvals queue, waiting for a decision, with no trace of what they wrote',
    str_contains($now['bookings'], 'Requested by somebody no longer recorded')
    && str_contains($now['bookings'], 'Welcome team supper') === false
    && str_contains($now['bookings'], 'Purpose:') === false, mb_substr($now['bookings'], 0, 500));
// 🗣️ D-113: the page every member can open says what keeping the number costs.
//    #703 round 6: pinned AS RENDERED - the whole "Retention policy" section,
//    from its heading to the next heading, so a sentence PHP prints and a
//    paragraph added after the list are inside the pin.
//    #703 round 7: the heading must appear EXACTLY ONCE, and the section is
//    drawn in EVERY branch the page has. Its giving sentence has two (the
//    organisation has confirmed how many years, or not), each with a "for this
//    organisation, every gift" choice inside, and the confirmed one says "year"
//    or "years"; round 6 drew only the unconfirmed one, so a reassurance added
//    to the confirmed branch was pinned by nothing.
//    #703 round 8: a section ends only at a heading of the SAME or a HIGHER
//    level than its own (an <h3> section at the next <h3>, <h2> or <h1>).
//    Ending at ANY heading let a new <h4> and a reassurance under it, placed
//    after the list, sit outside the pin with everything green (plant P3e).
//    The help page's region is found the same way now (P3f below).
$sectionAfter = static fn (string $heading): \Closure => static function (\Dom\HTMLDocument $doc) use ($heading): array {
    $found = [];
    foreach ($doc->querySelectorAll('h1, h2, h3, h4, h5, h6') as $h) {
        if (trim((string) preg_replace('/\s+/', ' ', (string) $h->textContent)) === $heading) {
            $found[] = $h;
        }
    }
    if (count($found) !== 1) {
        return [];
    }
    $level = (int) substr($found[0]->tagName, 1);
    $nodes = [$found[0]];
    for ($n = $found[0]->nextElementSibling; $n !== null; $n = $n->nextElementSibling) {
        if (preg_match('/^H([1-6])$/', $n->tagName, $m) === 1 && (int) $m[1] <= $level) {
            break;
        }
        $nodes[] = $n;
    }

    return $nodes;
};
$givingRowsNow = static fn (): array => q("SELECT settingKey, settingValue, defaultValue FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'giving.retention.%' ORDER BY settingKey", 'i', [ORG_A]);
$givingBefore  = $givingRowsNow();
$privacyStates = [
    'privacy retention, not confirmed'                   => [],
    'privacy retention, not confirmed, every gift'       => ['giving.retention.confirmed' => 'false', 'giving.retention.scope' => 'all'],
    'privacy retention, confirmed, 7 years'              => ['giving.retention.confirmed' => 'true', 'giving.retention.years' => '7'],
    'privacy retention, confirmed, every gift, 1 year'   => ['giving.retention.confirmed' => 'true', 'giving.retention.years' => '1', 'giving.retention.scope' => 'all'],
];
$privacyWrong = [];
$privacySeen  = [];
try {
    foreach ($privacyStates as $key => $values) {
        q("DELETE FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'giving.retention.%'", 'i', [ORG_A]);
        foreach ($values as $setting => $value) {
            siteSetting(ORG_A, $setting, $value);
        }
        $region            = renderedRegion((string) req($jar['cal'], 'GET', '/privacy/policy')['body'], $sectionAfter('Retention policy'));
        $privacySeen[$key] = $region;
        if (renderedPin($key, $region) === false) {
            $privacyWrong[] = $key . ($region === '' ? ' (not exactly one such section)' : ': ' . mb_substr($region, 0, 400));
        }
    }
} finally {
    // Put the organisation's giving settings back EXACTLY: a later section
    // counts them.
    q("DELETE FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'giving.retention.%'", 'i', [ORG_A]);
    foreach ($givingBefore as $row) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss',
            [ORG_A, $row['settingKey'], $row['settingValue'], $row['defaultValue']]);
    }
}
// The premise, first: four DIFFERENT sections, or the states were not drawn
// and every pin would be the same text pinned four times.
if (count(array_unique($privacySeen)) !== 4) {
    $privacyWrong[] = 'the four giving states drew ' . count(array_unique($privacySeen)) . ' different section(s), not four';
}
if ($givingRowsNow() !== $givingBefore) {
    $privacyWrong[] = 'the organisation\'s giving settings were not put back as they were';
}
ok('the PRIVACY page\'s "Retention policy" section, drawn in all four states of its giving sentence, is each time exactly the pinned markup a member receives, found once, inside exactly the pinned wrappers - what is kept on purpose, what the worker records keep, what is kept for security and history and how each is cleared (#703, D-113)',
    $privacyWrong === [], implode(' | ', $privacyWrong));
// 🗣️ The administrators' help: the whole section "What an erasure keeps, and
//    what it does not reach", rendered, from its heading to the next heading
//    of its level or higher, so the D-112 qualifier, every item AND anything
//    added after the list under the same heading are inside the pin. #703
//    round 8: it was the <ul> alone, and a paragraph added straight after it
//    passed (plant P3f). The heading must appear exactly once (round 7).
$helpRegion = renderedRegion((string) req($jar['adm'], 'GET', '/help/admin')['body'], $sectionAfter('What an erasure keeps, and what it does not reach'));
ok('the HELP page\'s section on what an erasure keeps, heading to next heading of its level, is exactly the pinned markup an administrator receives, the D-112 qualifier included, found once, inside exactly the pinned wrappers (#703)',
    renderedPin('help kept list', $helpRegion),
    $helpRegion === '' ? 'not exactly one such section' : mb_substr($helpRegion, 0, 600));
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
// #703 round 10: this section and the sweep below assume NO installation-wide
// hold - four of their checks failed when one was already on at the start. So
// the state is set here rather than assumed, recorded first so the clean-up
// puts it back (and recorded only ONCE: section H records the same row again).
if (array_key_exists('giving.retention.hold', $state['settings']) === false) {
    $state['settings']['giving.retention.hold'] = one("SELECT settingID, settingValue FROM tblSettings WHERE settingKey = 'giving.retention.hold' AND siteID IS NULL");
}
q("UPDATE tblSettings SET settingValue = 'false' WHERE settingKey = 'giving.retention.hold' AND siteID IS NULL");
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

// 🗣️ #941 check round 4: a kept row with NO name beside an account that was
//    NOT emptied - what an account name of '' (written by the Microsoft and
//    Google sign-ins when the provider sends none) now leaves, because a blank
//    is stored as NULL. The page said "No name was recorded: the account had
//    already been emptied" for every nameless row, which is untrue here. The
//    row is put beside Cal's live account and removed again straight away.
q("INSERT INTO `tblGivingKeptDonors` (`siteID`, `donorID`, `fullName`, `address`, `postcode`, `scope`, `lastGiftDate`, `keptGiftCount`, `createdAt`) "
    . "VALUES (?, ?, NULL, NULL, NULL, 'receipted', CURDATE(), 0, UTC_TIMESTAMP())", 'ii', [ORG_A, CAL]);
try {
    $namelessPage = req($jar['adm'], 'GET', $givingPath);
} finally {
    q('DELETE FROM `tblGivingKeptDonors` WHERE `siteID` = ? AND `donorID` = ?', 'ii', [ORG_A, CAL]);
}
ok('A KEPT ROW WITH NO NAME beside a live account is shown as "No name was kept", and the page never claims the account "had already been emptied" (#941 check round 4)',
    $namelessPage['status'] === 200 && str_contains($namelessPage['body'], '<em>No name was kept</em>')
    && str_contains($namelessPage['body'], 'had already been emptied') === false,
    'HTTP ' . $namelessPage['status'] . '; ' . mb_substr(text($namelessPage['body']), 0, 600));

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
// $currency: EUR by default (#966's fixture choice). A gift a Gift Aid CLAIM FILE check reads must be GBP,
//    because since #966 (D-139) the claim file holds gifts in pounds only (Giving::GIFT_AID_CURRENCY).
$gift = static function (int $site, int $donor, string $date, int $pence, string $currency = 'EUR') use ($category): int {
    q("INSERT INTO tblGivingEntry (siteID, donorID, categoryID, amountPence, `currency`, donatedAt, method) VALUES (?, ?, ?, ?, ?, ?, 'cash')", 'iiiiss', [$site, $donor, $category[$site], $pence, $currency, $date]);

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
// 🛑 And the CEILING (#687), which is the newest of these keys and is locked
//    here for a reason worth proving: a ceiling written in this editor would
//    skip GivingRetention::organisationsAboveCap(), the refusal that stops a
//    ceiling being set below a figure an organisation has already chosen. That
//    refusal is what makes it a ceiling rather than a wish (#668).
$saidCeiling  = $settingsPress($jar['root'], '/settings/save', ['settingID' => $idOf(null, 'giving.retention.maxYears'), 'settingKey' => 'giving.retention.maxYears', 'settingValue' => '3']);
$lockedWords  = 'It can only be changed at Admin, Giving records';
$wideNow      = array_column(q("SELECT settingKey, settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey LIKE 'giving.retention.%' ORDER BY settingKey"), 'settingValue', 'settingKey');
ok('THE GENERAL SETTINGS EDITOR REFUSES every change to a giving-records setting: an organisation\'s administrator editing the hold and the years, adding a new one, and deleting the year end; and a GLOBAL administrator editing the installation-wide "confirmed", editing an organisation\'s own hold, and deleting the installation-wide hold. Each time the page says where to change it, and not one of those rows moved',
    count(array_filter([$saidEdit, $saidYears, $saidAdd, $saidDelete, $saidRootEdit, $saidRootOwn, $saidRootDel, $saidCeiling], static fn (string $said): bool => str_contains($said, '[danger]') && str_contains($said, $lockedWords))) === 8
    && [$givingRows(ORG_A), $givingRows(ORG_B)] === $rowsBefore && $wideNow === $wideBefore
    // ⚠️ THE KEYS BY NAME, not `count($wideBefore) === 7`. The count was right
    //    until #687 seeded an eighth (`maxYears`) and then it was simply wrong,
    //    which is the staleness the owner's own note warns about ("Counts are
    //    not kept in this file ... they went stale within days"). A named list
    //    is also STRICTER: a count passes on the wrong seven keys, and it is
    //    exactly what a deliberate change to the set should invalidate - the
    //    same move made for the notification preferences in #612.
    && array_keys($wideBefore) === [
        'giving.retention.confirmed', 'giving.retention.confirmedOn', 'giving.retention.countryCode',
        'giving.retention.hold', 'giving.retention.maxYears', 'giving.retention.scope',
        'giving.retention.yearEnd', 'giving.retention.years',
    ]
    && (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey LIKE 'giving.retention.something%'")['n'] === 0,
    implode(' | ', [$saidEdit, $saidYears, $saidAdd, $saidDelete, $saidRootEdit, $saidRootOwn, $saidRootDel, $saidCeiling]));
ok('...and the list offers no Edit or Delete button for those rows, only a link to the page where they are changed',
    $listPage['status'] === 200 && str_contains($listPage['body'], 'data-setting-key="giving.retention.hold"') === true
    && str_contains($listPage['body'], 'data-key="giving.retention.') === false && str_contains($listPage['body'], 'Delete setting: giving.retention.') === false
    && str_contains($listPage['body'], 'href="' . ADDR_A . '/admin/settings/giving-records">Changed at Admin, Giving records</a>') === true,
    'HTTP ' . $listPage['status']);

// THE ONE ROW LEFT OPEN: the installation-wide hold, for a global
// administrator. Switching it on holds every organisation. Recorded first, so
// that the clean-up puts it back even if this run stops here.
if (array_key_exists('giving.retention.hold', $state['settings']) === false) {
    $state['settings']['giving.retention.hold'] = one("SELECT settingID, settingValue FROM tblSettings WHERE settingKey = 'giving.retention.hold' AND siteID IS NULL");
}
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
head('I. Every address on these pages stays inside the organisation\'s own address (roll-out package B2b)');
// =============================================================================
// Organisations are told apart by the first part of the address in this run.
// Until 12 October 2026 these eight pages wrote bare addresses
// ("/admin/erasure-requests"), which land on the DEFAULT organisation: an
// administrator of B who pressed a button on B's list was dropped onto
// another organisation's address. Every one is now built by Router::url().
/** `$name="…"` values on a page that contain `$needle`, de-duplicated. */
$attributes = static function (string $html, string $name, string $needle): array {
    preg_match_all('/\b' . $name . '="([^"]*)"/', $html, $m);

    return array_values(array_unique(array_filter(array_map(static fn (string $v): string => html_entity_decode($v, ENT_QUOTES, 'UTF-8'), $m[1]), static fn (string $v): bool => str_contains($v, $needle))));
};
$delete  = ADDR_A . '/account/delete';
$myData  = ADDR_A . '/account/my-data';
ok('every press on "Delete my account" and "Request data erasure" sends the browser back inside A\'s own address',
    $landings === [
        '"Delete my account" with no form token'               => $delete,
        '"Delete my account" with the wrong words'             => $delete,
        '"Delete my account" by a root administrator (refused)' => $delete,
        '"Delete my account", a good press'                    => $myData,
        '"Delete my account", the same press again'            => $myData,
        '"Request data erasure", a good press'                 => $myData,
    ], json_encode($landings, JSON_UNESCAPED_SLASHES));
$page = req($jar['adm'], 'GET', '/account/delete')['body'];
ok('the "Delete my account" page: its form, and its links to the account pages, are inside A\'s own address',
    $attributes($page, 'action', 'account/delete') === [$delete . '/confirm']
    && array_diff([ADDR_A . '/account/my-data', ADDR_A . '/account/erasure-request', ADDR_A . '/account/data-export'], $attributes($page, 'href', '/account/')) === []
    && str_contains($page, 'action="/account/') === false,
    'form target(s): ' . implode(', ', $attributes($page, 'action', 'account/delete')) . '; links: ' . implode(', ', $attributes($page, 'href', '/account/')));
$page  = req($jar['adm'], 'GET', '/account/my-data')['body'];
$page2 = req($jar['adm'], 'GET', '/account/erasure-request')['body'];
ok('the "My data" and "Request data erasure" pages: the download, the request and the cancel links are inside A\'s own address',
    array_diff([$myData . '?download=1', ADDR_A . '/account/erasure-request'], $attributes($page, 'href', '/account/')) === []
    && in_array($myData, $attributes($page2, 'href', '/account/'), true) === true,
    'My data: ' . implode(', ', $attributes($page, 'href', '/account/')) . '; request page: ' . implode(', ', $attributes($page2, 'href', '/account/')));
ok('the page the emailed link opens offers "Return to the portal" inside A\'s own address',
    str_contains($confirmPage, '<a href="' . ADDR_A . '/">Return to the portal</a>') === true, mb_substr(text($confirmPage), 0, 200));
// The administrator of B, on B's own address.
$process = ADDR_B . '/admin/erasure-requests/process';
$listB   = req($jar['admb'], 'GET', '/admin/erasure-requests', null, ADDR_B);
$buttons = substr_count($listB['body'], 'name="action" value="');
$reports = $attributes($listB['body'], 'href', 'erasure-requests/report');
ok('B\'s own list of requests: every button posts inside B\'s address, and every "Report" link stays there too',
    $listB['status'] === 200 && $buttons >= 2 && substr_count($listB['body'], 'action="' . $process . '"') === $buttons
    && $reports === [ADDR_B . '/admin/erasure-requests/report?id=' . REQ_B_PUT, ADDR_B . '/admin/erasure-requests/report?id=' . REQ_B_ASK],
    'HTTP ' . $listB['status'] . '; ' . $buttons . ' button(s), ' . substr_count($listB['body'], 'action="' . $process . '"') . ' posting to ' . $process . '; report links: ' . implode(', ', $reports));
$pressB = req($jar['admb'], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($listB['body']), 'requestID' => REQ_B_PUT, 'action' => 'putback'], ADDR_B);
$after  = req($jar['admb'], 'GET', '/admin/erasure-requests', null, ADDR_B);
ok('B\'s administrator presses "Put back to waiting" on B\'s list: it is done, and the browser lands back on B\'s own list, which says so',
    $pressB['status'] === 302 && $pressB['location'] === ADDR_B . '/admin/erasure-requests' && request(REQ_B_PUT)['status'] === 'pending_review'
    && str_contains(message($after['body']), 'is back at pending review'),
    'HTTP ' . $pressB['status'] . ' Location: ' . $pressB['location'] . '; request: ' . request(REQ_B_PUT)['status'] . '; ' . message($after['body']));
$pressB = req($jar['admb'], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($after['body']), 'requestID' => 917999, 'action' => 'cancel'], ADDR_B);
ok('…and a press that is REFUSED (a request number that does not exist) lands back on B\'s own list as well',
    $pressB['status'] === 302 && $pressB['location'] === ADDR_B . '/admin/erasure-requests', 'HTTP ' . $pressB['status'] . ' Location: ' . $pressB['location']);
$report = req($jar['admb'], 'GET', '/admin/erasure-requests/report?id=' . REQ_B_PUT, null, ADDR_B);
ok('the report page of one of B\'s requests: its download link, and its way back to the list, are inside B\'s address',
    $report['status'] === 200 && array_diff([ADDR_B . '/admin/erasure-requests/report?id=' . REQ_B_PUT . '&download=1', ADDR_B . '/admin/erasure-requests'], $attributes($report['body'], 'href', 'erasure-requests')) === [],
    'HTTP ' . $report['status'] . '; links: ' . implode(', ', $attributes($report['body'], 'href', 'erasure-requests')));
// WHERE "EXECUTE" LANDS. No check used to look, with or without the first
// part of the address. Two faults planted by the independent check (12
// October 2026) passed all 65: that redirect written as a bare address again,
// and that redirect pointed at an address that does not exist, which the
// address check cannot see either because the helper still builds it. So the
// press is made, and the address it gives is then OPENED.
// Ben and his request are made here, not at the start: the checks above
// count the buttons and the Report links on B's list.
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [BEN, 'Ben Elsewhere', 'u' . BEN . '.erasure@selftest.invalid']);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [BEN, ORG_B]);
$seed(REQ_B_RUN, ORG_B, BEN, 'pending_review', null);
$listB  = req($jar['admb'], 'GET', '/admin/erasure-requests', null, ADDR_B);
$pressB = req($jar['admb'], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($listB['body']), 'requestID' => REQ_B_RUN, 'action' => 'execute'], ADDR_B);
// The address the browser was given, asked for exactly as given.
$landed = str_starts_with($pressB['location'], '/') === true ? req($jar['admb'], 'GET', $pressB['location'], null, '') : ['status' => 0, 'location' => '', 'body' => ''];
ok('B\'s administrator presses "Execute" on one of B\'s requests: it is carried out, the browser is sent to B\'s own list, and that address opens (200) and says the request is completed',
    $pressB['status'] === 302 && $pressB['location'] === ADDR_B . '/admin/erasure-requests' && request(REQ_B_RUN)['status'] === 'completed'
    && $landed['status'] === 200 && str_contains(message($landed['body']), 'Erasure request #' . REQ_B_RUN . ' is completed'),
    'HTTP ' . $pressB['status'] . ' Location: ' . $pressB['location'] . '; request: ' . request(REQ_B_RUN)['status'] . '; following it: HTTP ' . $landed['status'] . ' ' . mb_substr(message($landed['body']), 0, 120));

// =============================================================================
head('J. The erasure review, as real requests (#732)');
// =============================================================================
// WHY THIS SECTION EXISTS, AND WHAT IT IS ANSWERING.
//
// `/admin/erasure-requests/review` and `/admin/erasure-requests/review-save`
// were driven by NO request anywhere until 4 November 2026.
// `tools/erasure-review-selftest.php` proves the behaviour at class level and
// pins the two pages' guards as TEXT, which is better than nothing and is not
// a request. The independent check of #724 measured the gap: it removed, in
// ONE edit, `AND siteID = ?` from the request look-up on BOTH pages and
// `Auth::verifyCsrf()` from the handler, and all 52 audit scripts exited 0,
// that self-test passed 91 of 91, and `php -l` was clean. Nothing noticed.
//
// A text pin is a claim about the SOURCE. Three things it cannot see: a gate
// that runs but on the wrong value, a page reachable by a verb nobody pinned,
// and an include that goes round the file whose text was checked. So this
// section asks the running portal instead.
//
// THE ONE BEHAVIOUR NOTHING ELSE REQUESTS. This page shows a person's own
// words IN FULL and lets them be emptied, and its gate is the portal's one
// deliberate exception: `App::can(Capabilities::ERASURE_REVIEW)`, whose
// registry entry declares `globalAdminFallsOpen => false`. So A GLOBAL
// ADMINISTRATOR WHO HAS NOT BEEN GRANTED THE DATA CONTROLLER ROLE MUST BE
// REFUSED — an installation's administrator and an organisation's may be
// different parties (the standing rule of 29 October 2026), and that refusal
// is the whole reason the role was created (#724). Check J2 is that request.
//
// THE FIXTURES MATTER MORE THAN THE ASSERTIONS, and two of this repository's
// own lessons shape them:
//
//   - #616: an account holding several grounds at once cannot tell them
//     apart. So each ground has a person who holds it AND NOTHING ELSE —
//     Dan (the Data Controller role in A), Deb (the role in B), Fay (an
//     ordinary member), ADM (an administrator of A) and ROOT (a global
//     administrator). Had one account carried two of those, a gate could be
//     deleted with every check still green.
//   - #645: a check that proves something is NOT shown passes just as
//     readily when there is nothing to show. So organisation B holds a REAL
//     line of free text this person can be matched to, and check J6 proves
//     B's own Data Controller really can read it. Without J6, J4 and J5 would
//     pass on an empty organisation B and prove nothing at all.
//
//     **BE EXACT ABOUT WHOSE WORDS THEY ARE — a draft called them "this same
//     person's own words" and that is FALSE, caught by the independent
//     check.** The only account column on `tblSalvationCards` is
//     `assignedToID`, which IS in `GdprEraser::ACTOR_COLUMNS`, so the card is
//     reached through the follow-up worker it was GIVEN to and the
//     `prayerRequest` is whoever filled the card in — a third party. The
//     file contradicted itself: check J5 nine lines below REQUIRES the page
//     to print "only says this person ACTED on the row", whose full sentence
//     is "The text may well be somebody else's, so emptying it would take
//     away a third party's words."
//
//     **What that changes about this section's reach:** the one "Empty it"
//     performed here (J10) is on a THIRD PARTY's prayer request reached
//     through an actor column — arguably the more dangerous case, and
//     legitimate to cover — while the one line genuinely in this person's own
//     words is the resource booking's note (`bookedByID` LEFT
//     `ACTOR_COLUMNS` under #723, measured with comments stripped, because it
//     is still NAMED in a comment inside that block recording its removal).
//     That line's "Keep it" is covered; its "Empty it" is not.
//
// WHY IT IS BUILT HERE AND NOT WITH THE REST OF THE WORLD: section D counts
// the buttons on A's list and section I pins B's Report links as an exact
// list, so a request added before them would fail checks that are about
// something else entirely. Ben is created late in section I for the same
// reason.
//
// WHAT THIS SECTION CANNOT SHOW
//   - A Data Controller of one organisation opening ANOTHER organisation's
//     address. `SiteGate` refuses a non-member on every address of an
//     organisation (#511), with the same HTTP 403 the capability gate gives,
//     so such a request cannot say WHICH gate refused it. The boundary is
//     therefore asked the way it really bites: Dan, at his own
//     organisation's address, naming organisation B's request by number.
//   - Two people deciding the same line at the same moment. The server runs
//     one worker (`PHP_CLI_SERVER_WORKERS` is unset above), so two requests
//     never overlap and such a test would PASS ON BROKEN CODE.
//     **AND THE ROW LOCK IS EXERCISED BY NOTHING AT ALL — a draft of this
//     comment said it was "proved at class level instead", which is untrue
//     and was caught by the independent check.** Measured:
//     `tools/erasure-review-selftest.php` opens ONE connection
//     (`grep -c "new mysqli"` answers 1) and one connection cannot exercise a
//     lock; the string `FOR UPDATE` appears in NEITHER test; and that test's
//     own "what it cannot prove" list never claims it. So the ` FOR UPDATE`
//     at `web/_core/ErasureReview.php:775` could be deleted and nothing in
//     this repository would go red.
//
//     **#753 COVERED IT, in section R of `tools/erasure-review-selftest.php`,
//     and HOW took three attempts worth knowing about.** Racing two processes
//     worked but caught the fault only ONE RUN IN SIX — a guard that fires
//     one in six reads like proof and is not. Demonstrating the mechanism from
//     two connections, interleaved deliberately (the
//     `tools/expense-payment-race-selftest.php` pattern), is deterministic but
//     was VACUOUS for removal: it takes the lock in the TEST, so it proves the
//     database locks, not that `record()` asks it to — measured, four runs of
//     four stayed green with ` FOR UPDATE` deleted. What catches removal is a
//     PIN on the method's own text, plus one that the transaction still opens
//     BEFORE the lock (#697). Both go red on every run. The mechanism checks
//     stay, because they are what shows the harm.
//   - A line past `ErasureReview::SHOW_AT_MOST`. That needs 501 rows of free
//     text and is proved in section J of `tools/erasure-review-selftest.php`.
//   - **THREE clauses here are belt and braces rather than guards, and the
//     section header must not read as though they were.** J1's two "the words
//     are not on the queue page" clauses and J2's queue-body clause cannot
//     fail by ANY single edit under `web/`, because the queue renders no item
//     text at all (`review.php`'s "COUNTS, NOT ITEMS" block) — so there is no
//     edit that could put the words there. Likewise J12's
//     `str_contains($logSaid, CARD_TEXT_A) === false`: `review-save.php` never
//     has that text in scope. They are worth keeping as tripwires for a FUTURE
//     change that starts listing items, but a clause that cannot fail today
//     makes a check look stronger than it is, and saying so is the only remedy
//     a comment can offer. **J12's audit-trail half IS alive** — a plant that
//     appended the cell's text to the audit line turned J12 red and nothing
//     else.
//   - **A non-zero "still to decide" count.** J13 pins "1 line(s) … 0 of them
//     still to decide", and nothing ever reads a non-zero one, although J5 is
//     standing in front of "2 line(s) … 2 of them still to decide". A
//     `countUndecided()` that always answered zero would leave this section
//     green. Display only: the rule that actually refuses a close is
//     `whyNotReadyToClose()`, a different method, covered by the class test.
//   - Whether an erasure RUN then refuses to close over an undecided line.
//     Section C of that same class-level test is what proves it.

// 🧑‍⚖️ The people. THREE grounds, one each — Dan (the role in A), Deb (the
//    role in B), Fay (an ordinary member) — plus Pip, who holds NO ground and
//    never signs in: the requests are about her. A draft counted "four people,
//    one ground each", which counted Pip as a ground.
foreach ([DAN => 'Dan Controller', DEB => 'Deb Controller', FAY => 'Fay Member', PIP => 'Pip Subject'] as $id => $name) {
    q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, $name, 'u' . $id . '.erasure@selftest.invalid']);
}
// Pip belongs to BOTH organisations, which is the case the boundary is about.
foreach ([[DAN, ORG_A], [FAY, ORG_A], [DEB, ORG_B], [PIP, ORG_A], [PIP, ORG_B]] as [$user, $site]) {
    q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 0, 0)', 'ii', [$user, $site]);
}
// 🔑 The Data Controller role, one copy per organisation, exactly as migration
//    241 seeds it for a real installation. These two organisations are made by
//    this test with plain SQL, so nothing has seeded it for them.
//
//    NOTHING IS WRITTEN TO `tblCapabilityMap` OR `tblCapabilityMapRole` ON
//    PURPOSE, so `Capabilities::roleKeysInForce()` falls back to the
//    registry's shipped set `['data_controller']` — an organisation that has
//    never opened the roles page is gated exactly as it was before #727, and
//    that is the commonest state a customer is in.
//
//    **A draft named one table, `tblCapabilityRoleMap`, WHICH DOES NOT
//    EXIST.** That is this repository's "name things by the right identifier"
//    fault: a search for it finds nothing, and nothing found reads exactly
//    like nothing to fix. It matters more than a typo here, because the two
//    real tables mean DIFFERENT things — `tblCapabilityMap`'s own schema
//    comment says a row there with no rows in `tblCapabilityMapRole` means
//    "nobody, deliberately", which is the empty set and NOT the same state as
//    "never chosen". A reader sent to a table that does not exist cannot tell
//    which of the two this fixture is in. It is "never chosen": neither table
//    holds a row.
foreach ([[ROLE_DC_A, ORG_A], [ROLE_DC_B, ORG_B]] as [$roleId, $site]) {
    q(
        'INSERT INTO `tblRoles` (`roleID`, `siteID`, `roleKey`, `roleName`, `description`, `isStandard`) '
        . "VALUES (?, ?, 'data_controller', 'Data Controller', 'Answerable for personal data', 1)",
        'ii',
        [$roleId, $site]
    );
}
q('INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES (?, ?, ?)', 'iii', [DAN, ROLE_DC_A, ORG_A]);
q('INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES (?, ?, ?)', 'iii', [DEB, ROLE_DC_B, ORG_B]);

// 📝 The three lines of free text. One card in each organisation, so that
//    "A cannot see B's" is a boundary rather than an empty table; and one
//    booking, on a table that records no organisation at all.
q(
    "INSERT INTO `tblSalvationCards` (`cardID`, `siteID`, `fullName`, `prayerRequest`, `assignedToID`, `status`) "
    . "VALUES (?, ?, 'Someone at A', ?, ?, 'assigned')",
    'iisi',
    [CARD_A, ORG_A, CARD_TEXT_A, PIP]
);
q(
    "INSERT INTO `tblSalvationCards` (`cardID`, `siteID`, `fullName`, `prayerRequest`, `assignedToID`, `status`) "
    . "VALUES (?, ?, 'Someone at B', ?, ?, 'assigned')",
    'iisi',
    [CARD_B, ORG_B, CARD_TEXT_B, PIP]
);
q(
    'INSERT INTO `tblResourceBooking` (`bookingID`, `resourceID`, `bookedByID`, `startAt`, `endAt`, `purpose`, `notes`, `status`) '
    . "VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 DAY), DATE_ADD(NOW(), INTERVAL 125 HOUR), 'Hearing loop test', ?, 'pending')",
    'iiis',
    [BOOKING_J, ROOM, PIP, NOTE_TEXT]
);
// One request in each organisation, both waiting for review.
$seed(REQ_PIP_A, ORG_A, PIP, 'pending_review', null);
$seed(REQ_PIP_B, ORG_B, PIP, 'pending_review', null);

// Sign-ins for the three new people, in browsers of their own.
foreach (['dan' => DAN, 'deb' => DEB, 'fay' => FAY] as $who => $id) {
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-erasure-' . $who, $hash]);
    $in = $signIn($who);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-erasure-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
}

$reviewUrlA = ADDR_A . '/admin/erasure-requests/review';
$reviewUrlB = ADDR_B . '/admin/erasure-requests/review';

/** Open the review page: the queue with no query, or one request with '?id=N'. */
$open = static fn (string $who, string $query = '', string $address = ADDR_A): array
    => req($jar[$who], 'GET', '/admin/erasure-requests/review' . $query, null, $address);

/** A form token read FRESH off the review page: `Auth::verifyCsrf()` replaces it on every acceptance. */
$reviewToken = static fn (string $who, int $requestId, string $address = ADDR_A): string
    => token($open($who, '?id=' . $requestId, $address)['body']);

/**
 * One press of "Empty it" or "Keep it". `$token` is sent EXACTLY as given, so
 * '' is a press carrying no form token at all.
 */
$pressReview = static fn (
    string $who,
    string $token,
    int $requestId,
    string $table,
    string $column,
    int $rowKey,
    string $decision,
    string $address = ADDR_A
): array => req($jar[$who], 'POST', '/admin/erasure-requests/review-save', [
    'csrf_token' => $token, 'requestID' => $requestId, 'tableName' => $table,
    'columnName' => $column, 'rowKey' => $rowKey, 'decision' => $decision,
], $address);

// What is really in the three cells, and what has really been recorded. Read
// from the database, never believed from the page.
$cardNow     = static fn (int $cardId): ?string => (one('SELECT `prayerRequest` FROM `tblSalvationCards` WHERE `cardID` = ?', 'i', [$cardId]) ?? [])['prayerRequest'] ?? null;
$noteNow     = static fn (int $bookingId): ?string => (one('SELECT `notes` FROM `tblResourceBooking` WHERE `bookingID` = ?', 'i', [$bookingId]) ?? [])['notes'] ?? null;
$decisionsOf = static fn (int $requestId): array => q('SELECT `tableName`, `columnName`, `rowKey`, `decision`, `decidedByID` FROM `tblErasureReviewDecisions` WHERE `requestID` = ? ORDER BY `decisionID`', 'i', [$requestId]);
/** All three lines still exactly as they were written, and nothing recorded about either request. */
$untouched = static fn (): bool => $cardNow(CARD_A) === CARD_TEXT_A && $cardNow(CARD_B) === CARD_TEXT_B
    && $noteNow(BOOKING_J) === NOTE_TEXT && $decisionsOf(REQ_PIP_A) === [] && $decisionsOf(REQ_PIP_B) === [];

// ── J1. The Data Controller of A sees A's queue, and only A's ───────────────
$listA = $open('dan');
ok('J1 the Data Controller of this organisation opens the review queue: it offers THIS organisation\'s open request, not the other organisation\'s, and shows no free text at all (the queue counts lines, it does not list them)',
    $listA['status'] === 200
    && str_contains($listA['body'], $reviewUrlA . '?id=' . REQ_PIP_A) === true
    && str_contains($listA['body'], '?id=' . REQ_PIP_B) === false
    && str_contains(text($listA['body']), CARD_TEXT_A) === false
    && str_contains(text($listA['body']), CARD_TEXT_B) === false,
    'HTTP ' . $listA['status'] . '; own request offered: '
    . (str_contains($listA['body'], $reviewUrlA . '?id=' . REQ_PIP_A) ? 'yes' : 'NO')
    . '; the other organisation\'s offered: ' . (str_contains($listA['body'], '?id=' . REQ_PIP_B) ? 'YES' : 'no'));

// ── J2. The decisive one: a global administrator with no role is refused ────
// `App::hasRole()` answers TRUE for any global administrator holding no role
// row (#516). That is why this gate asks the capability registry instead, and
// why `erasure.review` is the one capability that does NOT fall open for a
// global administrator. Rae holds that flag and NOTHING else.
$rootList = $open('root');
$rootOne  = $open('root', '?id=' . REQ_PIP_A);
ok('J2 a GLOBAL ADMINISTRATOR who has not been granted the Data Controller role is REFUSED (403) on the queue and on one request alike, and is shown none of the words',
    $rootList['status'] === 403 && $rootOne['status'] === 403
    && str_contains(text($rootList['body']), CARD_TEXT_A) === false
    && str_contains(text($rootOne['body']), CARD_TEXT_A) === false
    && str_contains(text($rootOne['body']), NOTE_TEXT) === false,
    'queue HTTP ' . $rootList['status'] . ', one request HTTP ' . $rootOne['status']
    . '; words shown: ' . (str_contains(text($rootOne['body']), CARD_TEXT_A) ? 'YES' : 'no'));

// ── J3. An administrator of this organisation, and an ordinary member ───────
$admList = $open('adm');
$admOne  = $open('adm', '?id=' . REQ_PIP_A);
$fayList = $open('fay');
$fayOne  = $open('fay', '?id=' . REQ_PIP_A);
ok('J3 an administrator of THIS organisation, and an ordinary member, are refused too (403 each, queue and request), and neither is shown the words',
    [$admList['status'], $admOne['status'], $fayList['status'], $fayOne['status']] === [403, 403, 403, 403]
    && str_contains(text($admOne['body']), CARD_TEXT_A) === false
    && str_contains(text($fayOne['body']), CARD_TEXT_A) === false,
    'administrator: ' . $admList['status'] . '/' . $admOne['status']
    . ', member: ' . $fayList['status'] . '/' . $fayOne['status']);

// ── J4. The boundary: asking for the other organisation's request by number ─
// This is the request the #724 checker's plant would have answered. `$siteId`
// comes from `Site::id()`, so the number in the address is the only thing an
// outsider controls — and `AND siteID = ?` is what makes it useless.
$crossed = $open('dan', '?id=' . REQ_PIP_B);
ok('J4 the Data Controller of A names the OTHER organisation\'s request by number: the page says it is not one of this organisation\'s, and shows neither organisation\'s words',
    $crossed['status'] === 200
    && str_contains(message($crossed['body']), 'That erasure request is not one of this organisation') === true
    && str_contains(text($crossed['body']), CARD_TEXT_B) === false
    && str_contains(text($crossed['body']), CARD_TEXT_A) === false
    && str_contains(text($crossed['body']), NOTE_TEXT) === false,
    'HTTP ' . $crossed['status'] . ' ' . message($crossed['body'])
    . '; the other organisation\'s words on the page: ' . (str_contains(text($crossed['body']), CARD_TEXT_B) ? 'YES — THEY LEAKED' : 'no')
    . '; this organisation\'s: ' . (str_contains(text($crossed['body']), CARD_TEXT_A) ? 'YES' : 'no')
    . '; the booking note: ' . (str_contains(text($crossed['body']), NOTE_TEXT) ? 'YES' : 'no'));

// ── J5. Their own request: the words in full, with the warnings ─────────────
$mine  = $open('dan', '?id=' . REQ_PIP_A);
$tMine = text($mine['body']);
ok('J5 their own organisation\'s request: this organisation\'s line is shown WHOLE with the catalogue\'s reason, the "they only ACTED on this row" warning and the "not tied to one organisation" label, and the other organisation\'s line is not there',
    $mine['status'] === 200
    && str_contains($tMine, CARD_TEXT_A) === true
    && str_contains($tMine, NOTE_TEXT) === true
    && str_contains($tMine, CARD_TEXT_B) === false
    && str_contains($tMine, 'Why no rule decides this:') === true
    && str_contains($tMine, 'only says this person ACTED on the row') === true
    && str_contains($tMine, 'Not tied to one organisation.') === true
    && str_contains($tMine, 'Some of it could not be looked at') === false,
    'HTTP ' . $mine['status'] . '; A line: ' . (str_contains($tMine, CARD_TEXT_A) ? 'shown' : 'MISSING')
    . '; B line: ' . (str_contains($tMine, CARD_TEXT_B) ? 'SHOWN' : 'not shown')
    . '; booking note: ' . (str_contains($tMine, NOTE_TEXT) ? 'shown' : 'MISSING'));

// ── J6. And B's own Data Controller really can read B's line ────────────────
// WITHOUT THIS CHECK J4 AND J5 PROVE NOTHING: "B's words are not on A's page"
// passes just as well when B has no words. This is the fixture half of the
// #645 lesson, made into a request.
$hers  = $open('deb', '?id=' . REQ_PIP_B, ADDR_B);
$tHers = text($hers['body']);
ok('J6 the OTHER organisation\'s own Data Controller opens its own request and reads its line in full, and not this organisation\'s — so what J4 and J5 refuse really is a boundary and not an empty table',
    $hers['status'] === 200
    && str_contains($tHers, CARD_TEXT_B) === true
    && str_contains($tHers, CARD_TEXT_A) === false,
    'HTTP ' . $hers['status'] . '; B line: ' . (str_contains($tHers, CARD_TEXT_B) ? 'shown' : 'MISSING')
    . '; A line: ' . (str_contains($tHers, CARD_TEXT_A) ? 'SHOWN' : 'not shown'));

// ── J7. The save, with no form token and with one that is not this session's ─
$noToken  = $pressReview('dan', '', REQ_PIP_A, 'tblSalvationCards', 'prayerRequest', CARD_A, 'empty');
$badToken = $pressReview('dan', str_repeat('a', 64), REQ_PIP_A, 'tblSalvationCards', 'prayerRequest', CARD_A, 'empty');
ok('J7 a press carrying NO form token, and one carrying a token that is not this session\'s, are both refused (400); nothing is recorded and all three lines are exactly as they were',
    $noToken['status'] === 400 && $badToken['status'] === 400 && $untouched() === true,
    'no token: HTTP ' . $noToken['status'] . ', wrong token: HTTP ' . $badToken['status']
    . '; A line now: ' . var_export($cardNow(CARD_A), true));

// ── J8. The save, by somebody without the capability ───────────────────────
// WITH A GOOD FORM TOKEN OF THEIR OWN, deliberately: a press with no token
// would be refused by the test above it, so the refusal would not tell us the
// gate is there. Each token is checked to be real for the same reason — a
// fixture whose condition cannot be true cannot test the guard.
$pressCodes = [];
$tokensReal = true;
foreach (['root', 'adm', 'fay'] as $who) {
    $ownToken   = token(req($jar[$who], 'GET', '/account/delete')['body']);
    $tokensReal = $tokensReal && strlen($ownToken) === 64;
    $pressCodes[$who] = $pressReview($who, $ownToken, REQ_PIP_A, 'tblSalvationCards', 'prayerRequest', CARD_A, 'empty')['status'];
}
ok('J8 a global administrator with no role, an administrator of this organisation and an ordinary member each press "Empty it" with a good form token of their own: all three are refused (403), nothing is recorded and no line is changed',
    $tokensReal === true
    && $pressCodes === ['root' => 403, 'adm' => 403, 'fay' => 403]
    && $untouched() === true,
    'tokens real: ' . ($tokensReal ? 'yes' : 'NO') . '; codes: ' . json_encode($pressCodes)
    . '; A line now: ' . var_export($cardNow(CARD_A), true));

// ── J9. The save, naming the other organisation's request ──────────────────
$crossPress = $pressReview('dan', $reviewToken('dan', REQ_PIP_A), REQ_PIP_B, 'tblSalvationCards', 'prayerRequest', CARD_B, 'empty');
$crossTold  = message($open('dan')['body']);
ok('J9 the Data Controller of A presses "Empty it" on the OTHER organisation\'s request: it is refused by name, the browser lands back on this organisation\'s own queue, the other organisation\'s line is untouched and nothing is recorded against it',
    $crossPress['status'] === 302 && $crossPress['location'] === $reviewUrlA
    && str_contains($crossTold, 'That erasure request is not one of this organisation') === true
    && $cardNow(CARD_B) === CARD_TEXT_B
    && $decisionsOf(REQ_PIP_B) === [],
    'HTTP ' . $crossPress['status'] . ' Location: ' . $crossPress['location'] . '; ' . $crossTold
    . '; B line now: ' . var_export($cardNow(CARD_B), true)
    . '; decisions against B: ' . count($decisionsOf(REQ_PIP_B)));

// ── J9b. YOUR OWN request number, the OTHER organisation's ROW ─────────────
//
// 🎯 THE PRESS THE SECTION WAS MISSING, and the independent check found it by
//    planting the one thing nothing else caught. J9 names organisation B's
//    REQUEST, which `review-save.php`'s own `AND siteID = ?` refuses before
//    `ErasureReview::record()` is ever reached. So J9 proves that gate and
//    says nothing about the next one.
//
//    This press names Dan's OWN request — which passes that gate — and pairs
//    it with organisation B's ROW KEY. The only thing left standing between
//    him and a third party's words is the per-row narrowing `record()` takes
//    for itself (`$siteColumn = self::siteColumnOf($db, $table);`, its own
//    look-up, separate from the two in `rowsHolding()` and `countHolding()`).
//
//    **Measured: with that one line changed to `$siteColumn = ''`, section J
//    passed 79 of 79 while this press really emptied organisation B's card.**
//    The product was never exposed — `tools/erasure-review-selftest.php`
//    turns red on exactly the two checks written for it — but a gap in the
//    HTTP test is still a gap, and this is a request only a real POST can
//    make.
$ownReqOtherRow = $pressReview('dan', $reviewToken('dan', REQ_PIP_A), REQ_PIP_A, 'tblSalvationCards', 'prayerRequest', CARD_B, 'empty');
ok('J9b the Data Controller of A presses "Empty it" naming HIS OWN request but the OTHER organisation\'s row: organisation B\'s words are still there and nothing is recorded against his request',
    $cardNow(CARD_B) === CARD_TEXT_B
    && $decisionsOf(REQ_PIP_A) === [],
    'HTTP ' . $ownReqOtherRow['status'] . ' Location: ' . $ownReqOtherRow['location']
    . '; B line now: ' . var_export($cardNow(CARD_B), true)
    . '; decisions against A: ' . json_encode($decisionsOf(REQ_PIP_A)));

// ── J10. A real decision really empties the cell ───────────────────────────
$emptyPress = $pressReview('dan', $reviewToken('dan', REQ_PIP_A), REQ_PIP_A, 'tblSalvationCards', 'prayerRequest', CARD_A, 'empty');
$afterEmpty = $open('dan', '?id=' . REQ_PIP_A);
$emptyRows  = $decisionsOf(REQ_PIP_A);
ok('J10 "Empty it" on this organisation\'s line: the cell really is empty in the database, the decision is recorded naming who gave it, and the OTHER organisation\'s line is untouched',
    $emptyPress['status'] === 302 && $emptyPress['location'] === $reviewUrlA . '?id=' . REQ_PIP_A
    && str_contains(message($afterEmpty['body']), 'That line is emptied') === true
    && $cardNow(CARD_A) === null
    && $cardNow(CARD_B) === CARD_TEXT_B
    && count($emptyRows) === 1
    && $emptyRows[0]['decision'] === 'empty'
    && (int) $emptyRows[0]['decidedByID'] === DAN,
    'HTTP ' . $emptyPress['status'] . ' Location: ' . $emptyPress['location'] . '; '
    . message($afterEmpty['body']) . '; A line now: ' . var_export($cardNow(CARD_A), true)
    . '; recorded: ' . json_encode($emptyRows));

// ── J11. And the page afterwards shows something different ─────────────────
$tAfterEmpty = text($afterEmpty['body']);
ok('J11 the page then shows the change: the words are gone from it, and the line appears under "Decisions recorded" as emptied, by the account that decided',
    str_contains($tAfterEmpty, CARD_TEXT_A) === false
    && str_contains($tAfterEmpty, 'Decisions recorded for this request') === true
    && preg_match('/tblSalvationCards\.prayerRequest\s+row\s+' . CARD_A . '\s+emptied\s+account\s+#' . DAN . '/', $tAfterEmpty) === 1,
    'words still on the page: ' . (str_contains($tAfterEmpty, CARD_TEXT_A) ? 'YES' : 'no')
    . '; ' . mb_substr((string) strstr($tAfterEmpty, 'Decisions recorded'), 0, 220));

// ── J12. The words reach no record ─────────────────────────────────────────
// THE POSITIVE CLAUSES COME FIRST ON PURPOSE. A check made only of absences
// passes the day the recording stops happening at all, which this repository
// has already been caught by: so each record is required to EXIST and to say
// what was decided, and only then required not to quote the words.
$auditSaid = implode(' ', array_map('strval', array_column(q('SELECT `details` FROM `tblErasureAudit` WHERE `requestID` = ?', 'i', [REQ_PIP_A]), 'details')));
$logSaid   = implode(' ', array_map('strval', array_column(q('SELECT `activityDescription` FROM `tblActivityLogs` WHERE `userID` = ?', 'i', [DAN]), 'activityDescription')));
ok('J12 the request\'s own trail and the activity log both record WHICH line was emptied and that a person decided it, and NEITHER holds the words themselves',
    str_contains($auditSaid, 'reviewed by hand and EMPTIED') === true
    && str_contains($logSaid, 'was reviewed by hand and EMPTIED') === true
    && str_contains($auditSaid, CARD_TEXT_A) === false
    && str_contains($logSaid, CARD_TEXT_A) === false,
    'trail: ' . mb_substr($auditSaid, 0, 300) . ' | log: ' . mb_substr($logSaid, 0, 300));

// ── J13. "Keep it" changes nothing, and the request is then fully decided ──
$keepPress = $pressReview('dan', $reviewToken('dan', REQ_PIP_A), REQ_PIP_A, 'tblResourceBooking', 'notes', BOOKING_J, 'keep');
$afterKeep = $open('dan', '?id=' . REQ_PIP_A);
$tKeep     = text($afterKeep['body']);
$keptRows  = array_values(array_filter($decisionsOf(REQ_PIP_A), static fn (array $r): bool => $r['decision'] === 'keep'));
ok('J13 "Keep it" on the line that is not tied to one organisation: the note is left exactly as it was, the decision is recorded as kept, and the page then says nothing is left to decide for this request',
    $keepPress['status'] === 302
    && str_contains(message($afterKeep['body']), 'recorded as kept') === true
    && $noteNow(BOOKING_J) === NOTE_TEXT
    && count($keptRows) === 1
    && (int) $keptRows[0]['rowKey'] === BOOKING_J
    && str_contains($tKeep, NOTE_TEXT) === true
    && preg_match('/1 line\(s\) of free text are held for this person,\s*0 of them still to decide/', $tKeep) === 1,
    'HTTP ' . $keepPress['status'] . '; ' . message($afterKeep['body'])
    . '; note now: ' . var_export($noteNow(BOOKING_J), true)
    . '; kept rows: ' . json_encode($keptRows)
    . '; counts sentence: ' . mb_substr((string) strstr($tKeep, 'line(s) of free text are held'), 0, 160));

// =============================================================================
head('K. What a giver is told will happen to their own gifts (#703 round 8)');
// =============================================================================
// My data and Request erasure used to count every gift WHERE donorID = ? and
// call the lot "kept", with a reason saying every other gift had "already
// lost" the name - untrue before an erasure has run, and untrue for a gift no
// tax declaration or statement covers, which the erasure takes the name off.
// They now show GdprEraser::givingOutlook(), which asks the keep step's own
// rules. Three givers, under both rules, and then one of them is really
// erased, to show the page said what the erasure then did.
$givingBefore8 = q("SELECT settingKey, settingValue, defaultValue FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'giving.retention.%' ORDER BY settingKey", 'i', [ORG_A]);
// #703 round 9: a known state for the whole section - the years confirmed (six),
// and no hold at either level - so a record past its date really is "due". The
// installation's own hold is put back at the end of the section.
$holdEverybody8 = one("SELECT settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'");
if ($holdEverybody8 !== null) {
    q("UPDATE tblSettings SET settingValue = 'false' WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'");
}
siteSetting(ORG_A, 'giving.retention.hold', 'false');
siteSetting(ORG_A, 'giving.retention.confirmed', 'true');
siteSetting(ORG_A, 'giving.retention.years', '6');
foreach (['gwen' => [GWEN, 'Gwen Giver'], 'dora' => [DORA, 'Dora Declared'], 'bo' => [BO, 'Bo Both'], 'pat' => [PAT, 'Pat Past'], 'eve' => [EVE, 'Eve Envelope']] as $who => [$id, $name]) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, $name, 'u' . $id . '.erasure@selftest.invalid']);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [$id, ORG_A]);
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-erasure-' . $who, $hash]);
    $in = $signIn($who);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-erasure-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
}
foreach ([DORA, BO] as $donor) {
    q("INSERT INTO tblGiftAidDeclaration (siteID, donorID, address, postcode, validFrom) VALUES (?, ?, '3 Made-up Road', 'ZZ3 3ZZ', '2020-01-01')", 'ii', [ORG_A, $donor]);
}
$gift(ORG_A, GWEN, $recent, 1000);        // no declaration at all: loses the name unless every gift is kept
$gift(ORG_A, DORA, $recent, 2000);        // inside her declaration: keeps it
$boKept   = $gift(ORG_A, BO, $recent, 3000);      // inside his declaration: keeps it
$boUnkept = $gift(ORG_A, BO, '2019-06-01', 4000); // before it: loses it unless every gift is kept
$session  = static function (string $status) use ($category, $recent): int {
    q('INSERT INTO tblCountSessions (siteID, serviceDate, categoryID, status) VALUES (?, ?, ?, ?)', 'isis', [ORG_A, $recent, $category[ORG_A], $status]);

    return (int) one('SELECT MAX(countSessionID) AS id FROM tblCountSessions WHERE siteID = ?', 'i', [ORG_A])['id'];
};
foreach (['open', 'closed'] as $status) {
    q("INSERT INTO tblCountEnvelopes (countSessionID, giverID, giverName, amount, method) VALUES (?, ?, 'Bo Both', 5.00, 'cash')", 'ii', [$session($status), BO]);
}
// Pat: a declaration from 2008 to 2010, a gift inside it (2009) and one after
// it (2012). Six years from the 2009 gift is long past.
q("INSERT INTO tblGiftAidDeclaration (siteID, donorID, address, postcode, validFrom, validTo) VALUES (?, ?, '4 Made-up Road', 'ZZ4 4ZZ', '2008-01-01', '2010-12-31')", 'ii', [ORG_A, PAT]);
$patKept   = $gift(ORG_A, PAT, '2009-06-01', 6000);
$patUnkept = $gift(ORG_A, PAT, '2012-06-01', 7000);
// Eve: a declaration, ONE envelope in an open count it covers, and one gift
// from BEFORE it, which loses her name. So her page has a gift row that loses
// the name beside an envelope that keeps it - the case where "None of your
// gifts will keep your name" was untrue (round 8's check, giver 950004).
q("INSERT INTO tblGiftAidDeclaration (siteID, donorID, address, postcode, validFrom) VALUES (?, ?, '5 Made-up Road', 'ZZ5 5ZZ', '2020-01-01')", 'ii', [ORG_A, EVE]);
$eveUnkept = $gift(ORG_A, EVE, '2019-06-01', 800);
q("INSERT INTO tblCountEnvelopes (countSessionID, giverID, giverName, amount, method) VALUES (?, ?, 'Eve Envelope', 6.00, 'cash')", 'ii', [$session('open'), EVE]);

/** A giver's own My data rows for gifts and envelopes, as "action:count" in page order, and their reasons. */
$givingShown = static function (string $html): array {
    $doc   = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $shown = ['tblGivingEntry' => [], 'tblCountEnvelopes' => [], 'tblGiftAidDeclaration' => [], 'reasons' => []];
    // 🔁 #927: My data's badge printed the instruction's own word ("retain",
    //    "anonymise"), which these checks read. It prints the member's words
    //    now (GdprEraser::inventoryWords()), so they are read back to the word
    //    the checks below were written in. EXACT words only: anything else is
    //    left as printed, so it fails every check rather than passing as one
    //    of them. Both "removed" spellings read as `anonymise` here - whether
    //    the number-keeping one is printed where it should be is pinned by K7
    //    and by the expense-claim pins in section B, not by these.
    $asWord = [
        'kept'                                                                            => 'retain',
        'kept, with some details about you removed'                                       => 'anonymise',
        'kept, with some details about you removed but still carrying your account number' => 'anonymise',
        'deleted'                                                                         => 'delete',
    ];
    foreach ($doc->querySelectorAll('.portal-data-list > .row') as $row) {
        $cells = [];
        foreach ($row->children as $cell) {
            $cells[] = trim((string) preg_replace('/\s+/', ' ', (string) $cell->textContent));
        }
        // #927 check round 1: the badge is framed as what WOULD happen
        // ("would be ... if you ask to be erased"). The frame is required here,
        // so a bare badge is left as printed and fails the checks below.
        $inside   = preg_match('/^would be (.+?),? if you ask to be erased$/', $cells[2] ?? '', $framed) === 1 ? $framed[1] : ($cells[2] ?? '');
        $cells[2] = $asWord[$inside] ?? ($cells[2] ?? '');
        if (isset($shown[$cells[0] ?? '']) === true && $cells[0] !== 'reasons') {
            $shown[$cells[0]][]  = ($cells[2] ?? '') . ':' . ($cells[1] ?? '');
            $shown['reasons'][] = $cells[0] . ' ' . ($cells[2] ?? '') . ': ' . ($cells[3] ?? '');
        }
    }

    return $shown;
};
$giversSee = static function (array $who3 = ['gwen', 'dora', 'bo']) use ($givingShown, &$jar): array {
    $seen = [];
    foreach ($who3 as $who) {
        $seen[$who] = $givingShown((string) req($jar[$who], 'GET', '/account/my-data')['body']);
    }

    return $seen;
};
$reasonsSay = static fn (array $seen, string $who, string $words): bool
    => array_filter($seen[$who]['reasons'], static fn (string $r): bool => str_contains($r, $words)) !== [];
$noAlreadyLost = static fn (array $seen): bool => str_contains(json_encode($seen), 'already lost') === false;

siteSetting(ORG_A, 'giving.retention.scope', 'receipted');
$receipted = $giversSee();
ok('K1 MY DATA, where only gifts a tax declaration or statement covers keep the name: Gwen\'s one gift is shown as losing her name and the page says none will keep it; Dora\'s is kept; Bo sees one gift and one envelope that will keep his name and one of each that will lose it; nothing says a gift has "already lost" a name (#703 round 8)',
    $receipted['gwen']['tblGivingEntry'] === ['anonymise:1'] && $receipted['gwen']['tblCountEnvelopes'] === []
    && $receipted['dora']['tblGivingEntry'] === ['retain:1']
    && $receipted['bo']['tblGivingEntry'] === ['retain:1', 'anonymise:1'] && $receipted['bo']['tblCountEnvelopes'] === ['retain:1', 'anonymise:1']
    && $reasonsSay($receipted, 'gwen', 'None of your gifts will keep your name') === true
    && $reasonsSay($receipted, 'dora', 'because a tax declaration or giving statement of yours covers its date') === true
    && $reasonsSay($receipted, 'bo', 'will lose your name, because no tax declaration or giving statement of yours covers their date') === true
    && $noAlreadyLost($receipted) === true,
    json_encode($receipted));

siteSetting(ORG_A, 'giving.retention.scope', 'all');
$every = $giversSee();
ok('K2 MY DATA, where every gift keeps the giver\'s name: every gift of all three is shown as kept, saying the organisation keeps the name on every gift; Bo\'s envelope in the closed count still loses his name, because its gift is already among his gifts (#703 round 8)',
    $every['gwen']['tblGivingEntry'] === ['retain:1'] && $every['dora']['tblGivingEntry'] === ['retain:1']
    && $every['bo']['tblGivingEntry'] === ['retain:2'] && $every['bo']['tblCountEnvelopes'] === ['retain:1', 'anonymise:1']
    && $reasonsSay($every, 'gwen', 'because the organisation keeps the giver\'s name on every gift') === true
    && $reasonsSay($every, 'gwen', 'None of your gifts') === false
    && $noAlreadyLost($every) === true,
    json_encode($every));

siteSetting(ORG_A, 'giving.retention.scope', 'receipted');
$boAsks  = (string) req($jar['bo'], 'GET', '/account/erasure-request')['body'];
$asksDoc = \Dom\HTMLDocument::createFromString($boAsks, LIBXML_NOERROR);
$asked   = [];
foreach ($asksDoc->querySelectorAll('li') as $li) {
    $code = $li->querySelector('code');
    if ($code !== null && in_array(trim((string) $code->textContent), ['tblGivingEntry', 'tblCountEnvelopes'], true) === true) {
        $strong  = $li->querySelectorAll('strong');
        $asked[] = trim((string) $code->textContent) . ' ' . trim((string) $strong->item(0)?->textContent) . ' ' . trim((string) $strong->item(1)?->textContent);
    }
}
ok('K3 REQUEST ERASURE says the same to Bo: one gift and one envelope "kept", one of each "kept, with some details about you removed" (#703 round 8)',
    $asked === ['tblGivingEntry 1 kept', 'tblGivingEntry 1 kept, with some details about you removed',
        'tblCountEnvelopes 1 kept', 'tblCountEnvelopes 1 kept, with some details about you removed'],
    json_encode($asked));

// Bo is really erased, under the same rule: what keeps his name afterwards is
// exactly what his page said would.
$seed(REQ_BO, ORG_A, BO, 'pending_review', null);
$boRun       = act($jar['adm'], REQ_BO, 'execute');
$boGifts     = q('SELECT entryID FROM tblGivingEntry WHERE donorID = ? ORDER BY entryID', 'i', [BO]);
$boEnvelopes = (int) one('SELECT COUNT(*) AS n FROM tblCountEnvelopes WHERE giverID = ?', 'i', [BO])['n'];
ok('K4 BO IS ERASED: afterwards the gifts and envelopes that still name him are exactly the ones his page said would keep his name - the declared gift and the envelope in the open count - and the others have lost it (#703 round 8)',
    request(REQ_BO)['status'] === 'completed'
    && array_map(static fn (array $r): int => (int) $r['entryID'], $boGifts) === [$boKept] && $donorOf($boUnkept) === null
    && $boEnvelopes === 1,
    $boRun . ' | ' . json_encode([request(REQ_BO)['status'], $boGifts, $boEnvelopes]));

// ── #703 round 9: a record already past its date, an envelope-only giver,
//    and words that follow the organisation's state ──
siteSetting(ORG_A, 'giving.retention.scope', 'receipted');
$late = $giversSee(['pat', 'eve', 'dora']);
$says = static fn (array $seen, string $who, string $words): bool
    => array_filter($seen[$who]['reasons'], static fn (string $r): bool => str_contains($r, $words)) !== [];
ok('K5 PAST ITS DATE: Pat\'s two gifts and his declaration are all shown as losing his name - one gift because no declaration covers it, the other because its keep-until date has already passed - and nothing says any of his will keep it; Eve, whose only gift loses her name but whose envelope in an open count will become a gift that keeps it, is told so and is NOT told that none of her gifts will; Dora\'s kept gift says it is kept until the keep-until date (#703 round 9)',
    $late['pat']['tblGivingEntry'] === ['anonymise:2'] && $late['pat']['tblGiftAidDeclaration'] === ['anonymise:1']
    && $says($late, 'pat', 'because at Selftest erasure A the keep-until date for them has already passed and no hold is on, so the erasure takes your name off as it runs') === true
    // #703 round 11: the organisation whose rule takes the name off is NAMED.
    && $says($late, 'pat', '1 because no tax declaration or giving statement of yours covers their date, and Selftest erasure A keeps the giver\'s name only on gifts one covers') === true
    && $says($late, 'pat', 'these gifts will keep your name') === false
    && $late['eve']['tblGivingEntry'] === ['anonymise:1'] && $late['eve']['tblCountEnvelopes'] === ['retain:1'] && $late['eve']['tblGiftAidDeclaration'] === ['retain:1']
    && $says($late, 'eve', 'each becomes a gift that keeps your name') === true && $says($late, 'eve', 'None of your') === false
    && $says($late, 'dora', 'At Selftest erasure A: 1 gift, for tax reasons, because a tax declaration or giving statement of yours covers its date, until the organisation\'s keep-until date.') === true,
    json_encode($late));

siteSetting(ORG_A, 'giving.retention.confirmed', 'false');
$unconfirmed = $giversSee(['dora']);
siteSetting(ORG_A, 'giving.retention.scope', 'all');
$everyGift   = $giversSee(['gwen']);
siteSetting(ORG_A, 'giving.retention.scope', 'receipted');
siteSetting(ORG_A, 'giving.retention.confirmed', 'true');
ok('K6 THE WORDS FOLLOW THE STATE: with the years not confirmed, Dora is told there is no end date and nothing is cleared until it is confirmed, and is NOT promised a keep-until date; under "every gift", Gwen\'s kept gift is NOT said to be kept "for tax reasons" (#703 round 9)',
    $says($unconfirmed, 'dora', 'with no end date yet: the organisation has not confirmed how many years it keeps such records') === true
    && $says($unconfirmed, 'dora', 'until the organisation\'s keep-until date') === false
    && $says($everyGift, 'gwen', 'because the organisation keeps the giver\'s name on every gift') === true
    && $says($everyGift, 'gwen', 'for tax reasons') === false,
    json_encode([$unconfirmed, $everyGift]));

$patAsks = \Dom\HTMLDocument::createFromString((string) req($jar['pat'], 'GET', '/account/erasure-request')['body'], LIBXML_NOERROR);
$patSaid = [];
foreach ($patAsks->querySelectorAll('li') as $li) {
    $code = $li->querySelector('code');
    if ($code !== null && in_array(trim((string) $code->textContent), ['tblGivingEntry', 'tblGiftAidDeclaration'], true) === true) {
        $strong    = $li->querySelectorAll('strong');
        $patSaid[] = trim((string) $code->textContent) . ' ' . trim((string) $strong->item(0)?->textContent) . ' ' . trim((string) $strong->item(1)?->textContent);
    }
}
// 🔁 CHANGED BY #927: the declaration's label used to be "kept, with some
//    details about you removed", and a declaration that loses the name kept
//    beside it still carries the account number (the database will not let
//    `donorID` be empty there; its reason says so). The label says so now too.
//    The gifts lose the number with the name, so theirs is unchanged.
ok('K7 REQUEST ERASURE says the same to Pat: his two gifts are "kept, with some details about you removed", and his declaration, which keeps his account number, says THAT; none simply "kept" (#703 round 9, #927)',
    $patSaid === ['tblGivingEntry 2 kept, with some details about you removed',
        'tblGiftAidDeclaration 1 kept, with some details about you removed but still carrying your account number'],
    json_encode($patSaid));

// ── #703 round 10: under a HOLD nothing is cleared, and the pages must say so.
//    A hold at the organisation, then one on the whole installation, then a
//    giver in two organisations whose states differ. ──
$sectionAfterDelete = $sectionAfter('What is kept');
$deleteSeen = static function () use (&$jar, $sectionAfterDelete): string {
    return renderedRegion((string) req($jar['gwen'], 'GET', '/account/delete')['body'], $sectionAfterDelete);
};
$patLines = static function () use (&$jar): array {
    $doc  = \Dom\HTMLDocument::createFromString((string) req($jar['pat'], 'GET', '/account/erasure-request')['body'], LIBXML_NOERROR);
    $said = [];
    foreach ($doc->querySelectorAll('li') as $li) {
        $code = $li->querySelector('code');
        if ($code !== null && in_array(trim((string) $code->textContent), ['tblGivingEntry', 'tblGiftAidDeclaration'], true) === true) {
            $strong = $li->querySelectorAll('strong');
            $said[] = trim((string) $code->textContent) . ' ' . trim((string) $strong->item(0)?->textContent) . ' ' . trim((string) $strong->item(1)?->textContent);
        }
    }

    return $said;
};
$heldLines = ['tblGivingEntry 1 kept', 'tblGivingEntry 1 kept, with some details about you removed', 'tblGiftAidDeclaration 1 kept'];

siteSetting(ORG_A, 'giving.retention.hold', 'true');
$orgHold     = $giversSee(['pat']);
$orgHoldAsks = $patLines();
$orgHoldDel  = $deleteSeen();
siteSetting(ORG_A, 'giving.retention.hold', 'false');
// Compared HERE, not at the end of the check's && chain, so it is always
// compared (an && that stops early left the pin "compared by no check").
$orgHoldPinned = renderedPin('delete what is kept', $orgHoldDel);
ok('K9 A HOLD AT THE ORGANISATION: Pat\'s gift past its date is shown as KEPT, for as long as the organisation\'s hold is on, and so is his declaration; Request erasure says the same; and Delete my account - the same page for everybody - says a hold keeps the name and never asserts removal, found once and as pinned (#703 round 10)',
    $orgHold['pat']['tblGivingEntry'] === ['retain:1', 'anonymise:1'] && $orgHold['pat']['tblGiftAidDeclaration'] === ['retain:1']
    // (The row's WORDS under each hold are pinned whole by the matrix, K13.)
    && $orgHoldAsks === $heldLines
    && $orgHoldPinned === true
    && str_contains($orgHoldDel, 'for as long as a hold is on') === true && str_contains($orgHoldDel, 'Only where the date has already passed and no hold is on') === true,
    json_encode([$orgHold, $orgHoldAsks]) . ' | ' . mb_substr($orgHoldDel, 0, 300));

$holdEverybodyNow = one("SELECT settingID FROM tblSettings WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'");
if ($holdEverybodyNow === null) {
    q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'giving.retention.hold', 'true', 'false', 0)");
} else {
    q("UPDATE tblSettings SET settingValue = 'true' WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'");
}
$wideHold     = $giversSee(['pat']);
$wideHoldAsks = $patLines();
$wideHoldDel  = $deleteSeen();
q("UPDATE tblSettings SET settingValue = 'false' WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'");
$wideHoldPinned = renderedPin('delete what is kept', $wideHoldDel);
ok('K10 A HOLD ON THE WHOLE INSTALLATION: Pat\'s gift is shown as kept for as long as the hold on the whole installation is on - said as the installation\'s, not the organisation\'s; Request erasure says the same; Delete my account is the same pinned section (#703 round 10)',
    $wideHold['pat']['tblGivingEntry'] === ['retain:1', 'anonymise:1'] && $wideHold['pat']['tblGiftAidDeclaration'] === ['retain:1']
    // (The row's WORDS are pinned whole by the matrix, K13; a prefix match
    // here let an untrue ending through.)
    && $wideHoldAsks === $heldLines
    && $wideHoldPinned === true,
    json_encode([$wideHold, $wideHoldAsks]));

// Quin gives at both organisations. A is on hold; B has confirmed six years
// and no hold. His rows must name each, in a fixed order, and read the same
// from either organisation.
$givingBeforeB10 = q("SELECT settingKey, settingValue, defaultValue FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'giving.retention.%' ORDER BY settingKey", 'i', [ORG_B]);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [QUIN, 'Quin Two', 'u' . QUIN . '.erasure@selftest.invalid']);
foreach ([ORG_A, ORG_B] as $site) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [QUIN, $site]);
    q("INSERT INTO tblGiftAidDeclaration (siteID, donorID, address, postcode, validFrom) VALUES (?, ?, '6 Made-up Road', 'ZZ6 6ZZ', '2020-01-01')", 'ii', [$site, QUIN]);
    $gift($site, QUIN, $recent, 900);
}
q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [QUIN, 'selftest-erasure-quin', $hash]);
$in = $signIn('quin');
if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
    refuse("signing in as selftest-erasure-quin did not work (HTTP {$in['status']} {$in['location']})");
}
// Rue (round 12): which organisations a LOSE row names. At A: a declaration
// from 2020, a gift from 2019 (outside it: lost at A) and an envelope in an
// open count it covers (kept). At B: a declaration, a gift inside it (kept)
// and an envelope in a CLOSED count (lost at B). So the gift row must name A
// and not B, and the envelope row B and not A.
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [RUE, 'Rue Rule', 'u' . RUE . '.erasure@selftest.invalid']);
foreach ([ORG_A, ORG_B] as $site) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [RUE, $site]);
    q("INSERT INTO tblGiftAidDeclaration (siteID, donorID, address, postcode, validFrom) VALUES (?, ?, '7 Made-up Road', 'ZZ7 7ZZ', '2020-01-01')", 'ii', [$site, RUE]);
}
$gift(ORG_A, RUE, '2019-06-01', 300);
$gift(ORG_B, RUE, $recent, 400);
foreach ([[ORG_A, 'open'], [ORG_B, 'closed']] as [$site, $status]) {
    q('INSERT INTO tblCountSessions (siteID, serviceDate, categoryID, status) VALUES (?, ?, ?, ?)', 'isis', [$site, $recent, $category[$site], $status]);
    $sessionId = (int) one('SELECT MAX(countSessionID) AS id FROM tblCountSessions WHERE siteID = ?', 'i', [$site])['id'];
    q("INSERT INTO tblCountEnvelopes (countSessionID, giverID, giverName, amount, method) VALUES (?, ?, 'Rue Rule', 2.00, 'cash')", 'ii', [$sessionId, RUE]);
}
q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [RUE, 'selftest-erasure-rue', $hash]);
$in = $signIn('rue');
if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
    refuse("signing in as selftest-erasure-rue did not work (HTTP {$in['status']} {$in['location']})");
}
q("DELETE FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'giving.retention.%'", 'i', [ORG_B]);
siteSetting(ORG_B, 'giving.retention.confirmed', 'true');
siteSetting(ORG_B, 'giving.retention.years', '6');
siteSetting(ORG_B, 'giving.retention.hold', 'false');
$rueSees = $givingShown((string) req($jar['rue'], 'GET', '/account/my-data')['body']);
siteSetting(ORG_A, 'giving.retention.hold', 'true');
// #703 round 11: organisation B's name, typed by ITS administrator, carries
// markup, an ampersand and a quote. Round 10 put organisation names into these
// member-facing sentences, and the two administrator levels can be different
// people, so the name must reach every member as text, never as markup.
$oddName = 'Selftest <b>B</b> & "Co"';
q('UPDATE tblSites SET siteName = ? WHERE siteID = ?', 'si', [$oddName, ORG_B]);
$quinDataHtml = (string) req($jar['quin'], 'GET', '/account/my-data')['body'];
$quinAskHtml  = (string) req($jar['quin'], 'GET', '/account/erasure-request')['body'];
$fromA = $givingShown($quinDataHtml);
$fromB = $givingShown((string) req($jar['quin'], 'GET', '/account/my-data', null, ADDR_B)['body']);
q("UPDATE tblSites SET siteName = 'Selftest erasure B' WHERE siteID = ?", 'i', [ORG_B]);
siteSetting(ORG_A, 'giving.retention.hold', 'false');
q("DELETE FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'giving.retention.%'", 'i', [ORG_B]);
foreach ($givingBeforeB10 as $row) {
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss',
        [ORG_B, $row['settingKey'], $row['settingValue'], $row['defaultValue']]);
}
// In the fixed order, by name: '<' sorts before 'e', so B's odd name comes first.
$quinGifts = 'tblGivingEntry retain: these gifts will keep your name, and any home address held for you is kept with them. '
    . 'At ' . $oddName . ': 1 gift, for tax reasons, because a tax declaration or giving statement of yours covers its date, '
    . 'until the organisation\'s keep-until date. '
    . 'At Selftest erasure A: 1 gift, for tax reasons, because a tax declaration or giving statement of yours covers its date, '
    . 'for as long as the organisation\'s hold is on, and once it is lifted, until the organisation\'s keep-until date: nothing is '
    . 'cleared while the hold is on.';
$rueRow = static fn (string $start): array => array_values(array_filter($rueSees['reasons'], static fn (string $r): bool => str_starts_with($r, $start)));
ok('K11r A LOSE ROW NAMES EXACTLY THE ORGANISATIONS IT IS ABOUT: Rue\'s gift that loses the name names A and not B; her envelope that loses it names B and not A (#703 round 12)',
    $rueSees['tblGivingEntry'] === ['retain:1', 'anonymise:1'] && $rueSees['tblCountEnvelopes'] === ['retain:1', 'anonymise:1']
    && $rueRow('tblGivingEntry anonymise') === ['tblGivingEntry anonymise: these gifts keep their amount, date and method and will lose your name, '
        . 'because no tax declaration or giving statement of yours covers their date, and Selftest erasure A keeps the giver\'s name only on gifts one covers']
    && $rueRow('tblCountEnvelopes anonymise') === ['tblCountEnvelopes anonymise: offering envelopes that will lose your name, because at Selftest '
        . 'erasure B the count it was in is closed (its gift is among your gifts), or the gift it will become will not keep your name'],
    json_encode($rueSees));

ok('K11 A GIVER IN TWO ORGANISATIONS: Quin\'s kept gifts are worded organisation by organisation, each NAMED, in a fixed order - on hold at A, until the keep-until date at B - and nothing says "this organisation"; the words are the same whether he looks from A or from B (#703 round 10)',
    $fromA['tblGivingEntry'] === ['retain:2'] && in_array($quinGifts, $fromA['reasons'], true) === true
    && $fromA === $fromB && str_contains(json_encode($fromA), 'this organisation') === false,
    json_encode($fromA) . ' | B: ' . json_encode($fromB));

/**
 * Whether the INVENTORY parts of a page (those holding a <code> cell with the
 * table's name - never the menu, whose organisation switcher lists B's name
 * too, which is how round 11's check passed with the reason escaped twice)
 * hold any <b> element, whether their TEXT holds the odd name exactly, and
 * whether their markup shows it escaped TWICE (`&amp;lt;`, `&amp;amp;`).
 */
$asTextOnly = static function (string $html, string $selector) use ($oddName): array {
    $doc     = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $markup  = 0;
    $text    = false;
    $twice   = false;
    foreach ($doc->querySelectorAll($selector) as $part) {
        if ($part->querySelector('code') === null) {
            continue;
        }
        $markup += count($part->querySelectorAll('b'));
        $text    = $text || str_contains((string) $part->textContent, $oddName);
        $raw     = $doc->saveHtml($part);
        $twice   = $twice || str_contains($raw, '&amp;lt;') || str_contains($raw, '&amp;amp;');
    }

    return ['markup' => $markup, 'nameAsText' => $text, 'escapedTwice' => $twice];
};
$dataEscaped = $asTextOnly($quinDataHtml, '.portal-data-list > .row');
$askEscaped  = $asTextOnly($quinAskHtml, 'li');
ok('K11e AN ORGANISATION\'S NAME IS TEXT, NEVER MARKUP: with B named `Selftest <b>B</b> & "Co"`, the INVENTORY items of My data and of Request erasure show that name, quote and ampersand included, as text, escaped once and not twice, and no <b> element appears in them (#703 rounds 11 and 12)',
    $dataEscaped === ['markup' => 0, 'nameAsText' => true, 'escapedTwice' => false]
    && $askEscaped === ['markup' => 0, 'nameAsText' => true, 'escapedTwice' => false],
    json_encode(['my data' => $dataEscaped, 'request erasure' => $askEscaped]));

// BOTH holds at once: the organisation's and the installation's. The words
// must name both - collapsing them to the organisation's alone tells a member
// its lifting would free the record, when the installation's still holds it.
siteSetting(ORG_A, 'giving.retention.hold', 'true');
q("UPDATE tblSettings SET settingValue = 'true' WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'");
$bothHold     = $giversSee(['pat']);
$bothHoldAsks = $patLines();
$bothHoldDel  = $deleteSeen();
q("UPDATE tblSettings SET settingValue = 'false' WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'");
siteSetting(ORG_A, 'giving.retention.hold', 'false');
$bothHoldPinned = renderedPin('delete what is kept', $bothHoldDel);
ok('K12 BOTH HOLDS AT ONCE: Pat\'s gift and declaration are shown as kept; Request erasure says the same; Delete my account is the same pinned section (the words, under every combination of holds, are pinned whole by the matrix, K13) (#703 rounds 11 and 12)',
    $bothHold['pat']['tblGivingEntry'] === ['retain:1', 'anonymise:1'] && $bothHold['pat']['tblGiftAidDeclaration'] === ['retain:1']
    && $bothHoldAsks === $heldLines
    && $bothHoldPinned === true,
    json_encode([$bothHold, $bothHoldAsks]));

// ── K13, #703 round 12: EVERY hold state's words, in ONE table. Rounds 10 and
//    11 each pinned the one state they were handed and left the next open. The
//    matrix walks: the organisation's hold on/off x the installation's hold
//    on/off x the years confirmed or not x the date passed or not (Pat's gift
//    is from 2009; Dora's is recent), and compares the WHOLE gift and
//    declaration rows with the literal text below. The words are written HERE,
//    not computed by the code under test (#647). ──
$gLead  = 'tblGivingEntry retain: these gifts will keep your name, and any home address held for you is kept with them. At Selftest erasure A: '
    . '1 gift, for tax reasons, because a tax declaration or giving statement of yours covers its date, ';
$dLead  = 'tblGiftAidDeclaration retain: your tax declarations stay unchanged, with your name kept beside them. At Selftest erasure A: '
    . '1 declaration, as the evidence for tax relief claimed on your gifts, ';
$patLost = 'tblGivingEntry anonymise: these gifts keep their amount, date and method and will lose your name, because no tax declaration or '
    . 'giving statement of yours covers their date, and Selftest erasure A keeps the giver\'s name only on gifts one covers';
$period = [
    'date'     => 'until the organisation\'s keep-until date.',
    'nodate'   => 'with no end date yet: the organisation has not confirmed how many years it keeps such records, and nothing is cleared until it does.',
    'own'      => 'for as long as the organisation\'s hold is on, and once it is lifted, ',
    'wide'     => 'for as long as the hold on the whole installation is on, and once whoever runs it lifts it, ',
    'both'     => 'for as long as the organisation\'s hold or the hold on the whole installation is on, and once both are lifted, ',
    'liftDate' => 'until the organisation\'s keep-until date',
    'liftNone' => 'with no end date until the organisation confirms how many years it keeps such records',
    'liftDue'  => 'the name goes - as the erasure runs, or at the next nightly clear-out after that if that scheduled job has been set up - '
        . 'because the keep-until date has already passed',
    'endOwn'   => ': nothing is cleared while the hold is on.',
    'endWide'  => ': nothing is cleared while that hold is on.',
    'endBoth'  => ': nothing is cleared while either hold is on.',
];
// The table: [organisation hold, installation hold, confirmed, who] => the period words.
$matrix = [];
foreach ([[false, false], [true, false], [false, true], [true, true]] as [$own, $wide]) {
    foreach ([true, false] as $confirmed) {
        foreach (['dora' => false, 'pat' => true] as $who => $passed) {
            $holdKey = $own === true && $wide === true ? 'both' : ($own === true ? 'own' : ($wide === true ? 'wide' : ''));
            $lifted  = $confirmed === false ? 'liftNone' : ($passed === true ? 'liftDue' : 'liftDate');
            $words   = $holdKey === ''
                ? ($confirmed === false ? $period['nodate'] : ($passed === true ? null : $period['date']))
                : $period[$holdKey] . $period[$lifted] . $period['end' . ucfirst($holdKey === 'own' ? 'Own' : ($holdKey === 'wide' ? 'Wide' : 'Both'))];
            $rows = $words === null
                // No hold, confirmed, past its date: due - everything loses the name as the erasure runs.
                ? ['tblGivingEntry anonymise: these gifts keep their amount, date and method and will lose your name, 1 because no tax '
                    . 'declaration or giving statement of yours covers their date, and Selftest erasure A keeps the giver\'s name only on gifts '
                    . 'one covers; 1 because at Selftest erasure A the keep-until date for them has already passed and no hold is on, so the '
                    . 'erasure takes your name off as it runs. None of your gifts will keep your name',
                    'tblGiftAidDeclaration anonymise: your tax declarations stay, with their dates and your account number, but the name kept '
                    . 'beside them is removed and the address and postcode, the network address each was made from, the signature and any notes '
                    . 'on them are emptied, because at Selftest erasure A the keep-until date for them has already passed and no hold is on, so '
                    . 'the erasure takes your name off as it runs']
                : array_merge([$gLead . $words], $who === 'pat' ? [$patLost] : [], [$dLead . $words]);
            $matrix[] = [$own, $wide, $confirmed, $who, $rows];
        }
    }
}
$matrixWrong = [];
$wideRow     = one("SELECT settingID FROM tblSettings WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'");
if ($wideRow === null) {
    q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'giving.retention.hold', 'false', 'false', 0)");
}
$seenFor = [];
foreach ($matrix as [$own, $wide, $confirmed, $who, $expected]) {
    $key = ($own ? 'own' : '-') . '/' . ($wide ? 'wide' : '-') . '/' . ($confirmed ? 'confirmed' : 'unconfirmed');
    if (isset($seenFor[$key]) === false) {
        siteSetting(ORG_A, 'giving.retention.hold', $own ? 'true' : 'false');
        q("UPDATE tblSettings SET settingValue = ? WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'", 's', [$wide ? 'true' : 'false']);
        siteSetting(ORG_A, 'giving.retention.confirmed', $confirmed ? 'true' : 'false');
        $seenFor[$key] = $giversSee(['dora', 'pat']);
    }
    $got = array_values(array_filter($seenFor[$key][$who]['reasons'],
        static fn (string $r): bool => str_starts_with($r, 'tblGivingEntry ') || str_starts_with($r, 'tblGiftAidDeclaration ')));
    if ($got !== $expected) {
        $matrixWrong[] = $key . ' ' . $who . ': ' . json_encode($got);
    }
}
siteSetting(ORG_A, 'giving.retention.hold', 'false');
q("UPDATE tblSettings SET settingValue = 'false' WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'");
siteSetting(ORG_A, 'giving.retention.confirmed', 'true');
ok('K13 EVERY HOLD STATE, IN ONE TABLE: for the organisation\'s hold on/off x the installation\'s on/off x the years confirmed or not x the date passed or not, the whole gift and declaration rows are exactly the literal words written in this test (16 cases) (#703 round 12)',
    count($matrix) === 16 && $matrixWrong === [], implode(' || ', $matrixWrong));

$seed(REQ_PAT, ORG_A, PAT, 'pending_review', null);
$seed(REQ_EVE, ORG_A, EVE, 'pending_review', null);
$patRun = act($jar['adm'], REQ_PAT, 'execute');
$eveRun = act($jar['adm'], REQ_EVE, 'execute');
$patDeclaration = one('SELECT address, postcode FROM tblGiftAidDeclaration WHERE donorID = ?', 'i', [PAT]);
ok('K8 PAT AND EVE ARE ERASED: no gift names Pat afterwards - the one past its date lost him in the same run, as his page said - and his declaration\'s address is gone; Eve\'s envelope still names her and her earlier gift does not, as hers said (#703 round 9)',
    request(REQ_PAT)['status'] === 'completed' && request(REQ_EVE)['status'] === 'completed'
    && $donorOf($patKept) === null && $donorOf($patUnkept) === null
    && $patDeclaration !== null && $patDeclaration['address'] === null && $patDeclaration['postcode'] === null
    && (int) one('SELECT COUNT(*) AS n FROM tblCountEnvelopes WHERE giverID = ?', 'i', [EVE])['n'] === 1 && $donorOf($eveUnkept) === null,
    $patRun . ' | ' . $eveRun . ' | ' . json_encode([request(REQ_PAT)['status'], request(REQ_EVE)['status'], $donorOf($patKept), $donorOf($patUnkept), $patDeclaration]));

if ($holdEverybody8 !== null) {
    q("UPDATE tblSettings SET settingValue = ? WHERE siteID IS NULL AND settingKey = 'giving.retention.hold'", 's', [(string) $holdEverybody8['settingValue']]);
}
// Put ORG_A's giving settings back exactly.
q("DELETE FROM tblSettings WHERE siteID = ? AND settingKey LIKE 'giving.retention.%'", 'i', [ORG_A]);
foreach ($givingBefore8 as $row) {
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss',
        [ORG_A, $row['settingKey'], $row['settingValue'], $row['defaultValue']]);
}

// =============================================================================
head('L. How long the activity log is kept, as a member reads it (#940; D-117, D-118)');
// =============================================================================
// Since #932 the activity log may be kept for ever - by the installation, or by
// an organisation where the installation allows it - and an organisation's own
// administrator sets its period within the installation's. The privacy page and
// the privacy notice print THIS organisation's real figure. Each state below is
// drawn over HTTP and its words compared with the LITERAL text written here,
// never computed by the code under test (#647), as K13 does for gifts. The
// table holds the states a plant has to get wrong to stay green: an
// organisation's own figure under the installation's (a page printing the
// installation's figure fails it); an organisation's "never" under an
// installation with a number (held to the number, so "for ever" fails it); and
// the two "for ever"s, which say WHO chose it.
//
// The whole "Retention" section of the privacy page is ALSO pinned as rendered,
// in every state, so an old sentence planted back BESIDE the new one fails too.
// The "could not be read" state is not reached over HTTP (it needs one read to
// fail while the page still draws). It IS driven in this process, by L2: here
// there is no `$mysqli`, so `App::db()` throws inside the class's own read, and
// memberFactsForSite() must answer "unknown" with no figure. (#940 round 1 said
// this state "cannot be driven" and compared only the words for hand-made
// facts, so a catch that answered 365 days left every check green.)
$alrLead = [
    'days'       => static fn (string $n): string => 'This organisation\'s activity log is deleted by a nightly clear-out after ' . $n
        . ', if that scheduled job has been set up. This organisation\'s administrators set that period, within the limit set for the whole portal.',
    'own-never'  => 'This organisation has chosen to keep its activity log for ever, so nothing deletes it.',
    'inst-never' => 'This portal keeps the activity log for ever, and this organisation has not chosen a shorter period, so nothing deletes it.',
];
$alrTail = ' A record made while you were using another organisation on this portal follows that organisation\'s period, and one that '
    . 'belongs to no organisation, such as one whose organisation has since been removed, follows the period set for the whole portal. '
    . 'If your account is erased, your account number, network address, browser and session details are taken off your own records, but '
    . 'what each record says is kept as it was written: some name people, by full name, e-mail address or account number, and those words '
    . 'stay for as long as the record does.';
$alrClause = [
    'days'       => static fn (string $n): string => 'the activity log, deleted here by a nightly clear-out after ' . $n . ', if that scheduled job has been set up',
    'own-never'  => 'the activity log, which this organisation has chosen to keep for ever, so nothing clears it',
    'inst-never' => 'the activity log, which this portal keeps for ever (this organisation has not chosen a shorter period), so nothing clears it',
];
$alrClauseTail = ' (a record made at another organisation on this portal follows that organisation\'s period, and one that belongs to no '
    . 'organisation the whole portal\'s)';
// [label, the installation's stored figure, this organisation's own (null = no row), state attribute, page words, notice words]
$alrTable = [
    ['installation 365 days, organisation follows it',   '365', null, 'days',               $alrLead['days']('365 days'), $alrClause['days']('365 days')],
    ['installation 365 days, organisation 45 days',      '365', '45', 'days',               $alrLead['days']('45 days'),  $alrClause['days']('45 days')],
    ['installation 1 day, organisation follows it',      '1',   null, 'days',               $alrLead['days']('1 day'),    $alrClause['days']('1 day')],
    ['installation 200 days, organisation chose never',  '200', '0',  'days',               $alrLead['days']('200 days'), $alrClause['days']('200 days')],
    ['installation never, organisation follows it',      '0',   null, 'installation-never', $alrLead['inst-never'],       $alrClause['inst-never']],
    ['installation never, organisation chose never',     '0',   '0',  'own-never',          $alrLead['own-never'],        $alrClause['own-never']],
    ['installation never, organisation 30 days',         '0',   '30', 'days',               $alrLead['days']('30 days'),  $alrClause['days']('30 days')],
];
// The installation's row was recorded and set to 365 at set-up (the clean-up
// puts the original back); this section changes it and returns it to 365.
$alrJar = $child . $sep . 'jar-section-l.txt';
/** The one element carrying `data-activity-log-retention`, as [state, words]; [] unless EXACTLY one. */
$alrOne = static function (string $html): array {
    $doc   = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $found = $doc->querySelectorAll('[data-activity-log-retention]');
    if (count($found) !== 1) {
        return [];
    }

    return [(string) $found[0]->getAttribute('data-activity-log-retention'), trim((string) preg_replace('/\s+/', ' ', (string) $found[0]->textContent))];
};
$alrWrong = [];
$alrSeen  = [];
try {
    foreach ($alrTable as [$label, $installation, $own, $stateWord, $pageWords, $noticeWords]) {
        q("UPDATE tblSettings SET settingValue = ? WHERE siteID IS NULL AND settingKey = 'audit.retentionDays'", 's', [$installation]);
        q("DELETE FROM tblSettings WHERE siteID = ? AND settingKey = 'audit.retentionDays'", 'i', [ORG_A]);
        if ($own !== null) {
            siteSetting(ORG_A, 'audit.retentionDays', $own);
        }
        $page   = req($alrJar, 'GET', '/privacy');
        $notice = req($alrJar, 'GET', '/privacy/policy');
        $got    = $alrOne((string) $page['body']);
        $gotN   = $alrOne((string) $notice['body']);
        if ($page['status'] !== 200 || $got !== [$stateWord, $pageWords . $alrTail]) {
            $alrWrong[] = $label . ' (privacy page, HTTP ' . $page['status'] . '): ' . json_encode($got);
        }
        if ($notice['status'] !== 200 || $gotN !== [$stateWord, $noticeWords . $alrClauseTail]) {
            $alrWrong[] = $label . ' (privacy notice, HTTP ' . $notice['status'] . '): ' . json_encode($gotN);
        }
        $region            = renderedRegion((string) $page['body'], $sectionAfter('Retention'));
        $alrSeen[$label]   = $region;
        if (renderedPin('privacy page retention, ' . $label, $region) === false) {
            $alrWrong[] = $label . ' (privacy page, the whole Retention section differs from its pin' . ($region === '' ? ': not exactly one such section' : '') . ')';
        }
    }
} finally {
    q("DELETE FROM tblSettings WHERE siteID = ? AND settingKey = 'audit.retentionDays'", 'i', [ORG_A]);
    q("UPDATE tblSettings SET settingValue = '365' WHERE siteID IS NULL AND settingKey = 'audit.retentionDays'");
}
// The premise: seven DIFFERENT sections, or the states were not really drawn.
if (count(array_unique($alrSeen)) !== count($alrTable)) {
    $alrWrong[] = 'the ' . count($alrTable) . ' states drew ' . count(array_unique($alrSeen)) . ' different Retention section(s)';
}
ok('L1 EVERY ACTIVITY-LOG STATE, IN ONE TABLE: for the installation\'s figure (days, one day, never) x this organisation\'s own (none, days, never), the privacy page and the privacy notice each say exactly the literal words written in this test, with the right state, and the privacy page\'s whole Retention section is its pinned markup (7 states) (#940)',
    count($alrTable) === 7 && $alrWrong === [], implode(' || ', $alrWrong));
// A figure that could not be read, DRIVEN: this process has no `$mysqli`, so
// the class's own read (`App::db()`) throws, and the REAL memberFactsForSite()
// must answer "unknown" with no figure - premise first, because the words below
// mean nothing unless that is the state a failed read produces. Then the words
// both pages print for that answer, compared with the literal text here.
require_once $repo . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'ActivityLogRetention.php';
try {
    $alrUnknown = \Portal\Core\ActivityLogRetention::memberFactsForSite($db, ORG_A);
} catch (\Throwable $e) {
    $alrUnknown = ['threw' => get_class($e) . ': ' . $e->getMessage()];
}
// THE PREMISE, asked of the thing that matters (#940 round 3): can the class's
// read get a connection at all? `App::db()` answers from a static that
// `App::init()` sets, and only then from a global `$mysqli`, so "no `$mysqli`"
// alone was not enough - with `App::init($db, [])` planted above the call the
// read succeeded while this still said the premise held. So ask App::db()
// itself, AFTER the call (the class has loaded App by then): it must THROW.
$alrPremise = 'App::db() gave a connection, so the read did not fail';
if (class_exists(\Portal\Core\App::class, false) === false) {
    $alrPremise = 'App was never loaded, so the class never reached its read';
} else {
    try {
        \Portal\Core\App::db();
    } catch (\Throwable $e) {
        $alrPremise = true;
    }
}
$alrUnknownPage   = isset($alrUnknown['state']) === true ? \Portal\Core\ActivityLogRetention::privacyPageWords($alrUnknown) : '';
$alrUnknownNotice = isset($alrUnknown['state']) === true ? \Portal\Core\ActivityLogRetention::privacyNoticeClause($alrUnknown) : '';
ok('L2 a figure that could not be read: memberFactsForSite() itself answers "unknown" with no figure when its read fails (driven here, where App::db() is proved to throw), and both pages then name NO figure and say it could not be read (#940)',
    $alrPremise === true
    && $alrUnknown === ['state' => \Portal\Core\ActivityLogRetention::MEMBER_UNKNOWN, 'days' => 0]
    && $alrUnknownPage === 'The activity log is deleted by a nightly clear-out after the period set for this organisation, if that scheduled job has been set up, unless that period is for ever, which it is only where the whole portal keeps the log for ever and this organisation has not chosen a number of days of its own. The period in force here could not be read just now.' . $alrTail
    && $alrUnknownNotice === 'the activity log, deleted by a nightly clear-out after the period set for this organisation, if that scheduled job has been set up, unless that period is for ever (only where the whole portal keeps the log for ever and this organisation has not chosen a number of days of its own)' . $alrClauseTail,
    ($alrPremise === true ? '' : 'PREMISE FAILED: ' . $alrPremise . ' | ') . json_encode([$alrUnknown, $alrUnknownPage, $alrUnknownNotice]));

// Two administrators' sentences #940 changed, pinned AS RENDERED so a reworded
// one cannot pass unseen (each is checked by nothing else): step 5 of the first
// steps (the clear-out skips an activity log set to never delete, and each
// organisation's administrator sets its own period), and the Retention page's
// note on which records follow the installation's figure.
$alrPick = static fn (string $selector): \Closure => static function (\Dom\HTMLDocument $doc) use ($selector): array {
    $found = $doc->querySelectorAll($selector);

    return count($found) === 1 ? [$found[0]] : [];
};
$alrSteps = req($jar['adm'], 'GET', '/help/admin-first-steps');
$alrStep5 = renderedRegion((string) $alrSteps['body'], $alrPick('#step5 .accordion-body > p'));
ok('L3 the first steps\' step 5 says the clear-out skips an activity log set to never delete, and who sets each organisation\'s period - exactly the pinned markup an administrator receives (#940)',
    $alrSteps['status'] === 200 && renderedPin('first steps, step 5', $alrStep5),
    'HTTP ' . $alrSteps['status'] . ' ' . mb_substr($alrStep5, 0, 600));
$alrRetention = req($jar['root'], 'GET', '/admin/maintenance/retention');
$alrNote      = renderedRegion((string) $alrRetention['body'], $alrPick('p[data-activity-log-in-force]'));
ok('L4 the Retention page\'s activity-log card, for a global administrator, is exactly the pinned markup: this organisation\'s figure, the installation\'s, and which records follow the installation\'s (#940)',
    $alrRetention['status'] === 200 && renderedPin('retention page, activity log card', $alrNote),
    'HTTP ' . $alrRetention['status'] . ' ' . mb_substr($alrNote, 0, 600));
$alrSubtitle = renderedRegion((string) $alrRetention['body'], $alrPick('p[data-retention-subtitle]'));
ok('L5 the Retention page\'s subtitle says the clear-out deletes rows older than the period that applies to each, and none whose activity-log period is "Never delete" - exactly the pinned markup (#940 round 2)',
    $alrRetention['status'] === 200 && renderedPin('retention page, subtitle', $alrSubtitle),
    'HTTP ' . $alrRetention['status'] . ' ' . mb_substr($alrSubtitle, 0, 600));
// #940 round 3: the card's explanation said "'Never delete' keeps every record
// for ever" under the INSTALLATION's form, where it is only the most an
// organisation may choose. Pinned as rendered in both views of the page: the
// global administrator's, and an organisation administrator's (ADM holds no
// global right, so the page shows ADM the organisation's form alone).
$alrExplained = renderedRegion((string) $alrRetention['body'], $alrPick('p[data-activity-log-explained]'));
ok('L6 the Retention card\'s explanation for a global administrator says the installation\'s "Never delete" is the most an organisation may choose, and an organisation with its own number of days is still cleared after it - exactly the pinned markup (#940 round 3)',
    $alrRetention['status'] === 200 && renderedPin('retention page, activity log explained, installation', $alrExplained),
    'HTTP ' . $alrRetention['status'] . ' ' . mb_substr($alrExplained, 0, 600));
$alrOrgView     = req($jar['adm'], 'GET', '/admin/maintenance/retention');
$alrOrgExplains = renderedRegion((string) $alrOrgView['body'], $alrPick('p[data-activity-log-explained]'));
ok('L7 the Retention card\'s explanation for an organisation administrator says "Never delete" keeps THIS organisation\'s records for ever, where the installation allows it - exactly the pinned markup (#940 round 3)',
    $alrOrgView['status'] === 200 && renderedPin('retention page, activity log explained, organisation', $alrOrgExplains),
    'HTTP ' . $alrOrgView['status'] . ' ' . mb_substr($alrOrgExplains, 0, 600));
// The three messages a global administrator can be shown about "Never delete"
// at the installation's level, each pressed through the real page and read back
// as shown, compared with literal words. The installation's figure is put back
// to 365 afterwards (and the clean-up restores the original).
$alrFlash = static function (string $html): string {
    return preg_match('/<div class="alert alert-\w+ alert-dismissible[^"]*">\s*(.*?)\s*<button/s', $html, $m) === 1
        ? trim((string) preg_replace('/\s+/', ' ', html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'))) : '(no message shown)';
};
$alrPress = static function (array $fields) use ($jar, $alrFlash): string {
    $form = req($jar['root'], 'GET', '/admin/maintenance/retention');
    req($jar['root'], 'POST', '/admin/maintenance/retention', ['csrf_token' => token($form['body']), 'action' => 'activity-retention-installation'] + $fields);

    return $alrFlash((string) req($jar['root'], 'GET', '/admin/maintenance/retention')['body']);
};
$alrMessages = [];
try {
    $alrMessages['a number of 0'] = [$alrPress(['choice' => 'days', 'days' => '0']),
        'A number of days has to be a whole number from 1 to 3650. For no time limit, choose "Never delete" instead. Nothing was saved.'];
    $alrMessages['a number above ten years'] = [$alrPress(['choice' => 'days', 'days' => '99999']),
        'A number of days can be at most 3650 (ten years). For no time limit, choose "Never delete" instead. Nothing was saved.'];
    $alrMessages['Never delete, saved'] = [$alrPress(['choice' => 'never']),
        'Saved. This installation now sets no time limit on the activity log. Records that belong to no organisation, and those of every '
        . 'organisation that has not chosen a number of days of its own, are never deleted; an organisation that has chosen a number of days '
        . 'is still cleared after it, and every organisation may choose a number of days or "Never delete" for itself.'];
} finally {
    q("UPDATE tblSettings SET settingValue = '365' WHERE siteID IS NULL AND settingKey = 'audit.retentionDays'");
}
$alrMessagesWrong = [];
foreach ($alrMessages as $case => [$got, $want]) {
    if ($got !== $want) {
        $alrMessagesWrong[] = $case . ': ' . $got;
    }
}
ok('L8 the three messages a global administrator is shown about "Never delete" for the installation (a 0 refused, a figure over ten years refused, Never delete saved) are exactly the literal words here, and none says the installation\'s choice keeps every record (#940 round 3)',
    count($alrMessages) === 3 && $alrMessagesWrong === [], implode(' || ', $alrMessagesWrong));
// #940 round 4: the ORGANISATION's form, pressed by an organisation
// administrator (ADM), with a 0, an EMPTY box and a figure past ten years,
// under an installation that keeps the log for ever AND under one of 30 days.
// Both forms used to share the installation's words, "from 1 to 3650 ... choose
// 'Never delete'", which under 30 days names a range the organisation may not
// use and a choice its form does not offer. Every case is written here
// literally, and nothing may be saved for the organisation.
$alrOrgPress = static function (array $fields, string $who = 'adm') use ($jar, $alrFlash): string {
    $form = req($jar[$who], 'GET', '/admin/maintenance/retention');
    req($jar[$who], 'POST', '/admin/maintenance/retention', ['csrf_token' => token($form['body']), 'action' => 'activity-retention-organisation'] + $fields);

    return $alrFlash((string) req($jar[$who], 'GET', '/admin/maintenance/retention')['body']);
};
$alrOrgOwnNow = static fn (): string => (string) json_encode(one("SELECT settingValue FROM tblSettings WHERE siteID = ? AND settingKey = 'audit.retentionDays'", 'i', [ORG_A]));
// Both rows L9 changes are recorded first and put back EXACTLY on every exit:
// the installation's (also restored by the clean-up) and the organisation's own.
$alrOrgRowBefore  = one("SELECT settingValue, defaultValue FROM tblSettings WHERE siteID = ? AND settingKey = 'audit.retentionDays'", 'i', [ORG_A]);
$alrInstRowBefore = one("SELECT settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey = 'audit.retentionDays'");
$alrUnderNever = 'A number of days has to be a whole number from 1 to 3650. For no time limit, choose "Never delete" instead. Nothing was saved.';
$alrUnder30    = 'A number of days has to be a whole number from 1 to 30. Nothing was saved.';
$alrOrgCases   = [];
try {
    // (string): PHP makes the key '0' an INT, and an int is never === '0'.
    foreach (['0' => 'never', '30' => '30 days'] as $installationKey => $named) {
        $installation = (string) $installationKey;
        q("UPDATE tblSettings SET settingValue = ? WHERE siteID IS NULL AND settingKey = 'audit.retentionDays'", 's', [$installation]);
        q("DELETE FROM tblSettings WHERE siteID = ? AND settingKey = 'audit.retentionDays'", 'i', [ORG_A]);
        $alrOrgCases['installation ' . $named . ', a 0'] = [$alrOrgPress(['choice' => 'days', 'days' => '0']),
            $installation === '0' ? $alrUnderNever : $alrUnder30];
        $alrOrgCases['installation ' . $named . ', an empty box'] = [$alrOrgPress(['choice' => 'days', 'days' => '']),
            $installation === '0' ? $alrUnderNever : $alrUnder30];
        $alrOrgCases['installation ' . $named . ', 99999'] = [$alrOrgPress(['choice' => 'days', 'days' => '99999']),
            $installation === '0'
                ? 'A number of days can be at most 3650 (ten years). For no time limit, choose "Never delete" instead. Nothing was saved.'
                : 'This installation allows at most 30 days, so that number was not saved. You can choose 30 or fewer. '
                    . 'Ask whoever runs this installation if you need more.'];
        $alrOrgCases['installation ' . $named . ', nothing saved for the organisation'] = [
            $alrOrgOwnNow(), 'null'];
    }
    // #940 round 4, narrow check: three more shapes, all under installation 30
    // (the loop above leaves it there).
    //  (a) NOT A WHOLE NUMBER: letters, a negative and a fraction. Each must
    //      get the ORGANISATION's words, never the installation's, and save
    //      nothing - a version wording only '0' and '' this way passed.
    foreach (['abc', '-1', '1.5'] as $alrBad) {
        $alrOrgCases['installation 30 days, "' . $alrBad . '"'] = [$alrOrgPress(['choice' => 'days', 'days' => $alrBad]), $alrUnder30];
    }
    $alrOrgCases['installation 30 days, after "abc", "-1" and "1.5", nothing saved'] = [$alrOrgOwnNow(), 'null'];
    //  (b) A GLOBAL ADMINISTRATOR pressing the ORGANISATION's form gets the
    //      organisation's words: it is the form that decides, not the person.
    $alrOrgCases['installation 30 days, a 0 pressed by a global administrator'] = [
        $alrOrgPress(['choice' => 'days', 'days' => '0'], 'root'), $alrUnder30];
    $alrOrgCases['installation 30 days, after the global administrator, nothing saved'] = [$alrOrgOwnNow(), 'null'];
    //  (c) THE ORGANISATION HOLDS A FIGURE OF ITS OWN (10). The range is still
    //      the installation's, 1 to 30 - the installation is the ceiling, not
    //      the organisation's own figure - and the stored 10 is left alone.
    siteSetting(ORG_A, 'audit.retentionDays', '10');
    $alrOrgCases['installation 30 days, organisation at 10, a 0'] = [$alrOrgPress(['choice' => 'days', 'days' => '0']), $alrUnder30];
    $alrOrgCases['installation 30 days, organisation at 10, its 10 unchanged'] = [$alrOrgOwnNow(), json_encode(['settingValue' => '10'])];
} finally {
    q("DELETE FROM tblSettings WHERE siteID = ? AND settingKey = 'audit.retentionDays'", 'i', [ORG_A]);
    if ($alrOrgRowBefore !== null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, \'audit.retentionDays\', ?, ?, 0)', 'iss',
            [ORG_A, (string) $alrOrgRowBefore['settingValue'], (string) $alrOrgRowBefore['defaultValue']]);
    }
    q("UPDATE tblSettings SET settingValue = ? WHERE siteID IS NULL AND settingKey = 'audit.retentionDays'", 's',
        [(string) ($alrInstRowBefore['settingValue'] ?? '365')]);
}
if (one("SELECT settingValue, defaultValue FROM tblSettings WHERE siteID = ? AND settingKey = 'audit.retentionDays'", 'i', [ORG_A]) !== $alrOrgRowBefore
    || one("SELECT settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey = 'audit.retentionDays'") !== $alrInstRowBefore) {
    $alrOrgCases['both rows L9 changed are put back exactly'] = ['not put back', 'put back'];
}
$alrOrgWrong = [];
foreach ($alrOrgCases as $case => [$got, $want]) {
    if ($got !== $want) {
        $alrOrgWrong[] = $case . ': ' . $got;
    }
}
ok('L9 the ORGANISATION\'s form refuses a 0, an empty box, letters, a negative, a fraction and a figure past ten years in its own words: under an installation that keeps the log for ever it offers "Never delete", under one of 30 days it gives 1 to 30 and never names "Never delete" - also when a global administrator presses it, and when the organisation holds a figure of its own - each exactly the literal words here, nothing is saved, and both rows are put back (#940 round 4)',
    count($alrOrgCases) === 16 && $alrOrgWrong === [], implode(' || ', $alrOrgWrong));

// =============================================================================
head('M. An erased account cannot be changed or switched back on (#977)');
// =============================================================================
// An erasure leaves the account row behind as "[Deleted User]", a made-up
// `deleted-<request>@example.invalid` address, switched off. The giving
// records an erasure keeps for tax hang off exactly that state: the Gift Aid
// claim file takes the kept name only while the account still reads as
// erased, and the statements list (with the refusal to write a new statement
// over the kept one) asks the same thing. Before #977 a global administrator
// could open the account on the Users page, tick Active or change the
// address, press Save, and both went - measured over HTTP with this section,
// run against the code from before the fix.
//
// So every page that can change an account is tried on an erased one, as a
// global administrator (the person nothing else stops), and each must refuse
// with the reason and leave the account row EXACTLY as it was; the claim file
// and the statements list are read again after every attempt. Each page is
// also pressed for a LIVE account, which must still work: a refusal that
// fired for everybody would pass every erased-account check here.
//
// WHAT THIS SECTION DOES NOT DRIVE, and why (each is recorded on #977):
// the users API's update (it 404s any account with no membership in the open
// organisation, and the erasure deletes every membership; AccountGuard's
// row 3a refuses it as well, and tools/account-guard-selftest.php pins that
// row); the roles, group and department pages (each writes a row that the
// database ties to a membership, which an erased account no longer has, so
// they fail without the guard too and a plant could not go red); the DBS
// page (it re-reads `isActive = 1` itself); and undoing an offboarding (the
// erasure deletes the offboarding record it needs).
const ERASED_WORDS = 'Nothing was changed. This account was erased on request, so it cannot be changed or switched back on.';
$todayM  = gmdate('Y-m-d');
$zedMail = 'deleted-' . REQ_ZED . '@example.invalid';
foreach (['giving.enabled' => 'true', 'offboarding.enabled' => 'true', 'giving.retention.hold' => 'false',
    'giving.retention.confirmed' => 'true', 'giving.retention.years' => '6', 'giving.retention.scope' => 'all'] as $key => $value) {
    siteSetting(ORG_A, $key, $value);
}
foreach ([ZED => ['Zed Zakes', true], LIV => ['Liv Live', true], OFF => ['Olly Offboard', true], UNP => ['Una Unplaced', false]] as $id => [$name, $member]) {
    q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, $name, 'u' . $id . '.erasure@selftest.invalid']);
    if ($member === true) {
        q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 0, 0)', 'ii', [$id, ORG_A]);
    }
}
q("INSERT INTO `tblGiftAidDeclaration` (`siteID`, `donorID`, `address`, `postcode`, `validFrom`) VALUES (?, ?, '9 Made-up Road', 'ZZ9 9ZZ', '2020-01-01')", 'ii', [ORG_A, ZED]);
$gift(ORG_A, ZED, $recent, 9773, 'GBP'); // 97.73: an amount nobody else in this world gives, so it can be found on a page; GBP, because the claim file holds pounds only (#966)

/** Zed's line of the Gift Aid claim file, found by the declaration's postcode (the name is what is being tested). */
$claimLineM = static function () use ($jar, $todayM): string {
    $csv = req($jar['root'], 'GET', '/giving/hmrc-export?from=2020-01-01&to=' . $todayM)['body'];
    foreach ((array) preg_split('/\R/', $csv) as $line) {
        if (str_contains((string) $line, 'ZZ9 9ZZ') === true) {
            return (string) $line;
        }
    }

    return '(no line)';
};
/** Does the statements list show Zed's gift? An erased donor is left out of it by the same test that refuses a new statement. */
$previewShowsZed = static fn (): bool => str_contains(text(req($jar['root'], 'GET', '/giving/statements?from=2020-01-01&to=' . $todayM)['body']), '97.73');
$zedRowM = static fn (): ?array => one('SELECT * FROM `tblUsers` WHERE `userID` = ?', 'i', [ZED]);
$flashOn = static function (string $html): string {
    $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $out = [];
    foreach ($doc->querySelectorAll('div.alert-dismissible') as $box) {
        $out[] = trim((string) preg_replace('/\s+/', ' ', (string) $box->textContent));
    }

    return implode(' | ', $out);
};

// The premise, both halves: the two probes SEE a live giver (so "not shown"
// later means something), and the erasure really left Zed in the state this
// section is about.
$liveClaim   = $claimLineM();
$livePreview = $previewShowsZed();
$seed(REQ_ZED, ORG_A, ZED, 'pending_review', null);
$zedRun   = act($jar['adm'], REQ_ZED, 'execute');
$zedAfter = $zedRowM();
$keptZed  = one('SELECT `fullName` FROM `tblGivingKeptDonors` WHERE `siteID` = ? AND `donorID` = ? AND `clearedAt` IS NULL', 'ii', [ORG_A, ZED]);
$claimAfterErasure   = $claimLineM();
$previewAfterErasure = $previewShowsZed();
ok('M0 THE PREMISE: before the erasure Zed\'s gift is in the statements list and the claim file names him; after it the request is completed, his account reads "[Deleted User]", ' . $zedMail . ', switched off, his name is KEPT for tax, the claim file still names him from the kept record, and the statements list leaves him out (#977)',
    $livePreview === true && str_contains($liveClaim, 'Zed') === true && str_contains($liveClaim, 'Zakes') === true
    && request(REQ_ZED)['status'] === 'completed'
    && $zedAfter !== null && $zedAfter['fullName'] === '[Deleted User]' && $zedAfter['emailAddress'] === $zedMail && (int) $zedAfter['isActive'] === 0
    && ($keptZed['fullName'] ?? null) === 'Zed Zakes'
    && str_contains($claimAfterErasure, 'Zed') === true && str_contains($claimAfterErasure, 'Zakes') === true
    && $previewAfterErasure === false,
    $zedRun . ' | ' . json_encode([$livePreview, $liveClaim, request(REQ_ZED)['status'] ?? null, $zedAfter, $keptZed, $claimAfterErasure, $previewAfterErasure]));

// The edit page's save, four ways, as a global administrator. Each attempt is
// judged against the row as it stood just before it; if the row DID change
// (the code from before #977) it is put back, so that each attempt is
// measured on its own and the next one starts from an erased account.
$saveM = static function (string $who, int $uid, array $fields) use ($jar, $flashOn): string {
    $tok = token(req($jar[$who], 'GET', '/admin/users')['body']);
    req($jar[$who], 'POST', '/admin/users/save', ['csrf_token' => $tok, 'action' => 'update', 'userID' => $uid, 'password' => ''] + $fields);

    return $flashOn(req($jar[$who], 'GET', '/admin/users')['body']);
};
$putZedBack = static function (array $row): void {
    q('UPDATE `tblUsers` SET `fullName` = ?, `emailAddress` = ?, `phoneNumber` = ?, `isActive` = ?, `isAdmin` = ?, `isRootAdmin` = ? WHERE `userID` = ?',
        'sssiiii', [$row['fullName'], $row['emailAddress'], $row['phoneNumber'], (int) $row['isActive'], (int) $row['isAdmin'], (int) $row['isRootAdmin'], ZED]);
};
$asErased = ['fullName' => '[Deleted User]', 'emailAddress' => $zedMail, 'phoneNumber' => (string) ($zedAfter['phoneNumber'] ?? '')];
$attemptsM = [
    'M1 Active ticked, nothing else changed'                => $asErased + ['isActive' => '1'],
    'M2 a changed e-mail address'                          => ['emailAddress' => 'zed.back@selftest.invalid'] + $asErased,
    'M3 a changed name'                                    => ['fullName' => 'Zed Zakes'] + $asErased,
    'M4 only the "global administrator" box ticked'        => $asErased + ['isRootAdmin' => '1'],
];
foreach ($attemptsM as $label => $fields) {
    $before  = $zedRowM();
    $said    = $saveM('root', ZED, $fields);
    $after   = $zedRowM();
    $claim   = $claimLineM();
    $preview = $previewShowsZed();
    if ($after !== $before) {
        $putZedBack((array) $before);
    }
    ok($label . ': a global administrator saving the ERASED account on the Users page is refused with the reason, the account row is byte for byte as it was, the claim file still names Zed from the kept record, and the statements list still leaves him out (#977)',
        str_contains($said, ERASED_WORDS) === true && $after === $before
        && $claim === $claimAfterErasure && $preview === false,
        json_encode(['said' => $said, 'rowChanged' => $after !== $before, 'after' => [$after['fullName'] ?? null, $after['emailAddress'] ?? null, $after['isActive'] ?? null, $after['isRootAdmin'] ?? null],
            'claim' => $claim, 'statementsListShowsZed' => $preview]));
}

// An administrator who is NOT global is told what an account outside their
// organisation always tells them, never that it was erased: the membership
// row is asked before the erased row (AccountGuard::decide(), row 3 then 3a).
$beforeAdm = $zedRowM();
$saidAdm   = $saveM('adm', ZED, $asErased + ['isActive' => '1']);
ok('M5 an ordinary administrator of A saving the erased account is told only "That account could not be found." - the words for any account outside their organisation - and nothing about an erasure; the row is unchanged (#977)',
    str_contains($saidAdm, 'That account could not be found.') === true && stripos($saidAdm, 'erased') === false && $zedRowM() === $beforeAdm,
    $saidAdm);

// The same page still saves a LIVE account.
$saidLiv = $saveM('root', LIV, ['fullName' => 'Liv Renamed', 'emailAddress' => 'u' . LIV . '.erasure@selftest.invalid', 'phoneNumber' => '', 'isActive' => '1']);
ok('M6 CONTROL: the same save, by the same global administrator, still changes a LIVE account (#977)',
    one('SELECT `fullName` FROM `tblUsers` WHERE `userID` = ?', 'i', [LIV])['fullName'] === 'Liv Renamed' && str_contains($saidLiv, 'updated successfully') === true,
    $saidLiv);

// The list page draws no Edit button (which is what fills the edit form) for
// the erased account, and says why; it still draws one for a live account.
$listZed = req($jar['root'], 'GET', '/admin/users?q=' . rawurlencode($zedMail))['body'];
$listLiv = req($jar['root'], 'GET', '/admin/users?q=' . rawurlencode('u' . LIV . '.erasure@selftest.invalid'))['body'];
ok('M7 the Users list offers NO Edit button for the erased account and says "Erased on request, cannot be changed"; a live account still has its Edit button (#977)',
    str_contains($listZed, $zedMail) === true && str_contains($listZed, 'data-uid="' . ZED . '"') === false
    && str_contains($listZed, 'Erased on request, cannot be changed') === true
    && str_contains($listLiv, 'data-uid="' . LIV . '"') === true && str_contains($listLiv, 'Erased on request') === false,
    json_encode([str_contains($listZed, $zedMail), str_contains($listZed, 'data-uid="' . ZED . '"'), str_contains($listZed, 'Erased on request'), str_contains($listLiv, 'data-uid="' . LIV . '"')]));

// Placing an account that belongs to no organisation. The erasure deleted
// every membership Zed had, so he matched that page's own test exactly.
$unplacedPage = req($jar['root'], 'GET', '/admin/users/unplaced')['body'];
$placeM = static function (int $uid) use ($jar, $flashOn): string {
    $tok = token(req($jar['root'], 'GET', '/admin/users/unplaced')['body']);
    req($jar['root'], 'POST', '/admin/users/unplaced/save', ['csrf_token' => $tok, 'userID' => $uid, 'siteID' => ORG_A]);

    return $flashOn(req($jar['root'], 'GET', '/admin/users/unplaced')['body']);
};
$beforePlace = $zedRowM();
$saidPlace   = $placeM(ZED);
$zedMembers  = (int) one('SELECT COUNT(*) AS `n` FROM `tblUserSites` WHERE `userID` = ?', 'i', [ZED])['n'];
if ($zedMembers > 0) {
    q('DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [ZED]); // so the later checks start from an erased account again
}
ok('M8 the "belongs to no organisation" page does not list the erased account (it lists a live one in the same state), and placing it by a hand-made request is refused with the reason: no membership is written and the account row is unchanged (#977)',
    str_contains($unplacedPage, 'Una Unplaced') === true && str_contains($unplacedPage, $zedMail) === false
    && str_contains($unplacedPage, 'value="' . ZED . '"') === false
    && str_contains($saidPlace, ERASED_WORDS) === true && $zedMembers === 0 && $zedRowM() === $beforePlace,
    json_encode([str_contains($unplacedPage, 'Una Unplaced'), str_contains($unplacedPage, $zedMail), $saidPlace, $zedMembers]));
$saidUnp = $placeM(UNP);
ok('M9 CONTROL: the same page still places a LIVE account that belongs to no organisation (#977)',
    (int) one('SELECT COUNT(*) AS `n` FROM `tblUserSites` WHERE `userID` = ? AND `siteID` = ?', 'ii', [UNP, ORG_A])['n'] === 1,
    $saidUnp);

// An organisation's own list of members (global administrators only).
$siteUsersM = static function (string $action, int $uid) use ($jar, $flashOn): string {
    $path = '/admin/sites/users?site=' . ORG_B;
    $tok  = token(req($jar['root'], 'GET', $path)['body']);
    req($jar['root'], 'POST', $path, ['csrf_token' => $tok, 'action' => $action, 'userID' => $uid]);

    return $flashOn(req($jar['root'], 'GET', $path)['body']);
};
$beforeSite = $zedRowM();
$saidSite   = $siteUsersM('add', ZED);
$zedAtB     = (int) one('SELECT COUNT(*) AS `n` FROM `tblUserSites` WHERE `userID` = ?', 'i', [ZED])['n'];
if ($zedAtB > 0) {
    q('DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [ZED]);
}
ok('M10 adding the erased account to an organisation on the organisation\'s Users page is refused with the reason: no membership is written and the account row is unchanged (#977)',
    str_contains($saidSite, ERASED_WORDS) === true && $zedAtB === 0 && $zedRowM() === $beforeSite,
    json_encode([$saidSite, $zedAtB]));
$saidLivAdd = $siteUsersM('add', LIV);
$livAtB     = (int) one('SELECT COUNT(*) AS `n` FROM `tblUserSites` WHERE `userID` = ? AND `siteID` = ?', 'ii', [LIV, ORG_B])['n'];
$saidLivRm  = $siteUsersM('remove', LIV);
$livAtBNow  = (int) one('SELECT COUNT(*) AS `n` FROM `tblUserSites` WHERE `userID` = ? AND `siteID` = ?', 'ii', [LIV, ORG_B])['n'];
ok('M11 CONTROL: the same page still adds a LIVE account to the organisation, and removes it again (#977)',
    $livAtB === 1 && $livAtBNow === 0 && str_contains($saidLivAdd, 'User added to site.') === true && str_contains($saidLivRm, 'User removed from site.') === true,
    json_encode([$livAtB, $livAtBNow, $saidLivAdd, $saidLivRm]));

// Offboarding: the page offers no form for the erased account and says why,
// and a hand-made post is refused. offboarding/index.php prints no message at
// all (it never has), so what the post SAYS is not read here; what it WROTE is.
$offPage    = req($jar['root'], 'GET', '/offboarding/user?id=' . ZED)['body'];
$offboardM  = static function (int $uid) use ($jar): void {
    $tok = token(req($jar['root'], 'GET', '/admin/users')['body']);
    req($jar['root'], 'POST', '/offboarding/do', ['csrf_token' => $tok, 'userID' => $uid, 'reason' => 'Selftest #977', 'dataDisposition' => 'retain', 'effectiveDate' => gmdate('Y-m-d')]);
};
$beforeOff = $zedRowM();
$offboardM(ZED);
$zedOffRows = (int) one('SELECT COUNT(*) AS `n` FROM `tblOffboarding` WHERE `userID` = ?', 'i', [ZED])['n'];
ok('M12 the offboarding page for the erased account shows the reason and no form, and a hand-made offboarding post for it writes no offboarding record and leaves the account row unchanged (#977)',
    str_contains(text($offPage), ERASED_WORDS) === true && str_contains($offPage, 'name="dataDisposition"') === false
    && $zedOffRows === 0 && $zedRowM() === $beforeOff,
    json_encode([mb_substr(text($offPage), 0, 300), str_contains($offPage, 'name="dataDisposition"'), $zedOffRows]));
$offboardM(OFF);
ok('M13 CONTROL: the same offboarding post still offboards a LIVE account: a record is written and the account is switched off (#977)',
    (int) one('SELECT COUNT(*) AS `n` FROM `tblOffboarding` WHERE `userID` = ?', 'i', [OFF])['n'] === 1
    && (int) one('SELECT `isActive` FROM `tblUsers` WHERE `userID` = ?', 'i', [OFF])['isActive'] === 0);

// Every refusal is on the record, in both places #518 records one. Seven:
// four saves, one placing, one organisation page, one offboarding. The
// activity-log line, which the open organisation's administrators read, says
// only that the account cannot be changed and never the word "erased"; the
// reason is in the security record, which only a global administrator sees.
$refusalLines = (int) one("SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `userID` = ? AND `activityType` = 'AccountChangeRefused' "
    . "AND `activityDescription` LIKE ? AND `activityDescription` LIKE '%. That account cannot be changed.' AND `activityDescription` NOT LIKE '%erased%'",
    'is', [ROOT, '%#' . ZED . '%'])['n'];
$securityRows = (int) one("SELECT COUNT(*) AS `n` FROM `tblErrors` WHERE `errorCode` = 'ACCOUNT_CHANGE_REFUSED' AND `userID` = ? "
    . "AND `errorDetail` LIKE '%Verdict: erased%' AND `errorDetail` LIKE ?", 'is', [ROOT, '%Target account number: ' . ZED . '%'])['n'];
ok('M14 every refusal is recorded: seven activity-log lines saying only that the account cannot be changed, and seven security records giving the reason, that it was erased (#977)',
    $refusalLines === 7 && $securityRows === 7,
    json_encode([$refusalLines, $securityRows]));

ok('M15 AFTER EVERYTHING: the erased account\'s row is byte for byte what the erasure left, the claim file\'s line for it is the same, and the statements list still leaves it out (#977)',
    $zedRowM() === $zedAfter && $claimLineM() === $claimAfterErasure && $previewShowsZed() === false,
    json_encode([$zedRowM(), $claimLineM(), $previewShowsZed()]));

// =============================================================================
head('N. Somebody without an account who suggested an event (#964, D-140)');
// =============================================================================
// A visitor who suggests an event gives a name and an e-mail address; approving
// it e-mails that address (D-134), which leaves it in the send log. They have no
// account, so an ordinary erasure cannot find any of it. D-140: an administrator
// files a request BY ADDRESS after checking who is asking, and that request
// empties the two columns and clears the approval e-mail's send-log and
// mail-queue rows - and NOTHING ELSE.
//
// HOW THIS IS ARRANGED. N1 drives the real public form and the real moderation
// handler once, so the rows the erasure must reach are the ones the portal
// really writes. Every other row is put in by hand, each standing for ONE
// thing the request must leave alone, and each differing from the real rows
// in ONE way only (#709's lesson: a fixture that differs in several ways tests
// only the first thing the code looks at):
//   - the same address at ORGANISATION B (an event, a waiting e-mail, a sent one);
//   - a LONGER address that merely contains it (an event, a waiting e-mail, a sent one);
//   - the address with an ACCENT, which this schema's own comparison treats as
//     the same address (measured: 'josé@x.org' = 'jose@x.org' is 1 under
//     utf8mb4_general_ci, and a LIKE matches it too);
//   - a MEMBER, May, whose account uses the SAME address, with mail of her own
//     to it (another subject) and one event row naming her as the suggester;
//   - the same address in other capitals and with a trailing space, which IS
//     the visitor's and must be emptied.
// Then the plants (N11-N20), each in the scratch copy the server runs, each
// required to turn ONE named observation red, and each put back byte for byte.
//
// WHAT THIS CANNOT SHOW. That the administrator really checked who was asking:
// the portal records their word for it and cannot test it. A mail job running
// at the moment of the erasure (MailQueue::eraseAddress() waits for it; section
// F8 of tools/mail-queue-selftest.php drives that wait for an account's run).
// And a message sent by the scheduled job through Microsoft 365 or Google,
// whose send-log row records whichever organisation the job was working in
// rather than the message's: such a row naming ANOTHER organisation is not
// reached (see GdprEraser::eraseEmailLogRecipients()).

const MAY        = 917052; // a member of A whose ACCOUNT uses the visitor's address
const VERA_ADDR  = 'p964.vera@selftest.invalid';
const VERA_TYPED = 'P964.Vera@Selftest.invalid';   // as the visitor typed it on the form
const VERA_LONG  = 'xp964.vera@selftest.invalid';  // a longer address containing hers
const VERA_ACCENT = 'p964.véra@selftest.invalid';  // the database's own comparison calls this the same
const EV_CASE    = 917301; // A, by the visitor's address in other capitals with a space after, waiting and DELETED
const EV_B       = 917302; // B, by the visitor's address
const EV_LONG    = 917303; // A, by the longer address
const EV_ACCENT  = 917304; // A, by the accented address
const EV_MEMBER  = 917305; // A, naming MAY as the suggester (signed in) AND the visitor's address
const MQ_A       = 917401; // A, the approval e-mail to the visitor, waiting
const MQ_B       = 917402; // B, the approval e-mail to the visitor, waiting
const MQ_MAY     = 917403; // A, May's own mail to the same address, waiting
const MQ_LONG    = 917404; // A, the approval e-mail to the longer address
const EL_NULL    = 917501; // the approval e-mail to the visitor, logged with NO organisation
const EL_B       = 917502; // B, the approval e-mail to the visitor
const EL_MAY     = 917503; // A, May's own mail to the same address
const EL_LONG    = 917504; // A, the approval e-mail to the longer address
// #964 round 2: activity records the public form wrote BEFORE #963 ("Event #N
// submitted by anonymous <address>"), and one Errors-page line. Numbered far
// above anything the pages write, so a real record never lands on one.
const AL_A       = 9170601; // A, no account, the old sentence with the visitor's address: emptied
const AL_B       = 9170602; // B, the same: left alone (D-150)
const AL_LONG    = 9170603; // A, the old sentence with the LONGER address: left alone
const AL_MEMBER  = 9170604; // A, the old sentence with the visitor's address, but naming MAY's account: left alone
const AL_OTHER   = 9170605; // A, the old sentence with the address and MORE words after it: left alone
const AL_TYPE    = 9170606; // A, the exact sentence under ANOTHER activity type: left alone
const ER_A       = 9170701; // A, an SMTP line on the Errors page quoting the visitor's address: left alone
const OLD_SENTENCE = 'Event #917399 submitted by anonymous ';

$approvalSubject = 'Your event suggestion has been approved'; // word for word, as EventSuggestions sends it
$n964Clear = static function (): void {
    // BY NUMBER, never by a range. The real suggestion N1 makes takes the
    // table's next number, and on a database an earlier run has used that is
    // ABOVE these (the counter remembers the numbers put in by hand), so a
    // range wiped the real event and its send-log row - found on the second run.
    q('DELETE FROM `tblEvents` WHERE `eventID` IN (?, ?, ?, ?, ?)', 'iiiii', [EV_CASE, EV_B, EV_LONG, EV_ACCENT, EV_MEMBER]);
    q('DELETE FROM `tblMailQueue` WHERE `queueID` IN (?, ?, ?, ?)', 'iiii', [MQ_A, MQ_B, MQ_MAY, MQ_LONG]);
    q('DELETE FROM `tblEmailLog` WHERE `emailLogID` IN (?, ?, ?, ?)', 'iiii', [EL_NULL, EL_B, EL_MAY, EL_LONG]);
    q('DELETE FROM `tblActivityLogs` WHERE `logID` IN (?, ?, ?, ?, ?, ?)', 'iiiiii', [AL_A, AL_B, AL_LONG, AL_MEMBER, AL_OTHER, AL_TYPE]);
    q('DELETE FROM `tblErrors` WHERE `errorID` = ?', 'i', [ER_A]);
};
/** Every row the request must leave alone, plus the two hand-made rows it must reach, put back as new. */
$n964Fixtures = static function () use ($n964Clear, $approvalSubject): void {
    $n964Clear();
    $event = static function (int $id, int $site, ?int $by, string $email): void {
        q('INSERT INTO `tblEvents` (`eventID`, `siteID`, `eventName`, `eventSlug`, `startDateTime`, `endDateTime`, `timezone`, '
            . '`status`, `submissionStatus`, `submittedByID`, `submitterName`, `submitterEmail`, `submittedAt`, `isDeleted`, `updatedAt`) '
            . "VALUES (?, ?, ?, ?, '2031-07-01 10:00:00', '2031-07-01 11:00:00', 'Europe/London', 'draft', 'pending', ?, ?, ?, '2026-10-01 09:00:00', ?, '2026-10-02 09:00:00')",
            'iissiisi', [$id, $site, 'P964 event ' . $id, 'p964-event-' . $id, $by, 'P964 Name ' . $id, $email, $id === EV_CASE ? 1 : 0]);
    };
    $event(EV_CASE, ORG_A, null, ' ' . strtoupper(VERA_ADDR) . ' ');
    $event(EV_B, ORG_B, null, VERA_ADDR);
    $event(EV_LONG, ORG_A, null, VERA_LONG);
    $event(EV_ACCENT, ORG_A, null, VERA_ACCENT);
    $event(EV_MEMBER, ORG_A, MAY, VERA_ADDR);
    $queued = static function (int $id, int $site, string $to, string $subject): void {
        q('INSERT INTO `tblMailQueue` (`queueID`, `siteID`, `status`, `nextAttemptAt`, `createdAt`, `statusAt`, `toRecipients`, `subject`, `lastError`) '
            . "VALUES (?, ?, 'held', UTC_TIMESTAMP() + INTERVAL 1 DAY, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, ?)",
            'iisss', [$id, $site, $to, $subject, '550 refused ' . $to]);
    };
    $queued(MQ_A, ORG_A, VERA_TYPED, $approvalSubject);
    $queued(MQ_B, ORG_B, VERA_ADDR, $approvalSubject);
    $queued(MQ_MAY, ORG_A, VERA_ADDR, 'P964 your rota for Sunday');
    $queued(MQ_LONG, ORG_A, VERA_LONG, $approvalSubject);
    $logged = static function (int $id, ?int $site, string $to, string $subject): void {
        q('INSERT INTO `tblEmailLog` (`emailLogID`, `siteID`, `provider`, `toRecipients`, `subject`, `status`, `errorDetail`) '
            . "VALUES (?, ?, 'smtp', ?, ?, 'failed', ?)", 'iisss', [$id, $site, $to, $subject, '550 refused ' . $to]);
    };
    $logged(EL_NULL, null, VERA_ADDR, $approvalSubject);
    $logged(EL_B, ORG_B, VERA_ADDR, $approvalSubject);
    $logged(EL_MAY, ORG_A, VERA_ADDR, 'P964 your rota for Sunday');
    $logged(EL_LONG, ORG_A, VERA_LONG, $approvalSubject);
    // Every column that ties a record to a browser is FILLED, on every one of
    // them (#964 round 3): the one the request reaches must come back with all
    // five empty, and the five it must not reach must come back unchanged.
    $activity = static function (int $id, int $site, ?int $user, string $type, string $text): void {
        q('INSERT INTO `tblActivityLogs` (`logID`, `siteID`, `userID`, `activityType`, `activityDescription`, '
            . '`visitorIP`, `userAgent`, `requestHeaders`, `sessionID`, `sessionDataSnapshot`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iiisssssss', [$id, $site, $user, $type, $text, '203.0.113.' . ($id % 250), 'P964Browser/' . $id,
                '{"Host":"p964.invalid","X-P964":"' . $id . '"}', hash('sha256', 'p964-' . $id), 'facts-1:{"p964":' . $id . '}']);
    };
    $activity(AL_A, ORG_A, null, 'EventSubmitted', OLD_SENTENCE . VERA_TYPED);
    $activity(AL_B, ORG_B, null, 'EventSubmitted', OLD_SENTENCE . VERA_ADDR);
    $activity(AL_LONG, ORG_A, null, 'EventSubmitted', OLD_SENTENCE . VERA_LONG);
    $activity(AL_MEMBER, ORG_A, MAY, 'EventSubmitted', OLD_SENTENCE . VERA_ADDR);
    $activity(AL_OTHER, ORG_A, null, 'EventSubmitted', OLD_SENTENCE . VERA_ADDR . ' and asked for a reply');
    $activity(AL_TYPE, ORG_A, null, 'EventEdited', OLD_SENTENCE . VERA_ADDR);
    q("INSERT INTO `tblErrors` (`errorID`, `siteID`, `errorPlatform`, `errorSeverity`, `errorCode`, `errorTitle`, `errorDetail`) "
        . "VALUES (?, ?, 'SMTP', 'Warning', '550', 'P964 refused', ?)", 'iis', [ER_A, ORG_A, '550 no such user <' . VERA_ADDR . '>']);
};
/** The rows that must NOT change, as they stand now. */
$n964Untouched = static fn (): array => [
    'events'  => q('SELECT * FROM `tblEvents` WHERE `eventID` IN (?, ?, ?, ?) ORDER BY `eventID`', 'iiii', [EV_B, EV_LONG, EV_ACCENT, EV_MEMBER]),
    'queue'   => q('SELECT `queueID`, `siteID`, `toRecipients`, `subject`, `lastError` FROM `tblMailQueue` WHERE `queueID` IN (?, ?, ?) ORDER BY `queueID`', 'iii', [MQ_B, MQ_MAY, MQ_LONG]),
    'log'     => q('SELECT * FROM `tblEmailLog` WHERE `emailLogID` IN (?, ?, ?) ORDER BY `emailLogID`', 'iii', [EL_B, EL_MAY, EL_LONG]),
    'account' => one('SELECT * FROM `tblUsers` WHERE `userID` = ?', 'i', [MAY]),
    'activity' => q('SELECT * FROM `tblActivityLogs` WHERE `logID` IN (?, ?, ?, ?, ?) ORDER BY `logID`', 'iiiii', [AL_B, AL_LONG, AL_MEMBER, AL_OTHER, AL_TYPE]),
    'errors'  => q('SELECT * FROM `tblErrors` WHERE `errorID` = ?', 'i', [ER_A]),
];

// The mail route for this section: SMTP with no mail server named, so nothing
// connects anywhere and every message is recorded in the send log as not sent
// (MailRoutes answers "not set up"). The route a fresh install starts on,
// Microsoft 365 with nothing filled in, records NOTHING, so the approval
// e-mail's own send-log row - the one the erasure must reach - would not
// exist. Both settings go back at the end with the others ($state['settings']).
foreach (['mail.provider' => 'smtp', 'mail.smtp.host' => ''] as $key => $value) {
    $row                     = one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    $state['settings'][$key] = $row;
    if ($row === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);
    } else {
        q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$value, (int) $row['settingID']]);
    }
}

// May: a member of A, signed in through the real page, whose ACCOUNT uses the
// visitor's address. She is also the ordinary member who is refused below.
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [MAY, 'May Sameaddress', VERA_ADDR]);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [MAY, ORG_A]);
q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [MAY, 'selftest-erasure-may', $hash]);
$in = $signIn('may');
if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
    refuse("signing in as selftest-erasure-may did not work (HTTP {$in['status']} {$in['location']})");
}

// ── N1. The real form and the real approval ───────────────────────────────────
$veraJar  = $child . $sep . 'jar-vera.txt';
$form     = req($veraJar, 'GET', '/calendar/submit');
$sent     = req($veraJar, 'POST', '/calendar/submit-save', [
    'csrf_token'     => token($form['body']),
    'eventName'      => 'P964 bring and share lunch',
    'startDateTime'  => '2031-06-14T12:30',
    'endDateTime'    => '2031-06-14T14:00',
    'locationName'   => 'The church hall',
    'description'    => 'Suggested by section N of tools/erasure-pages-selftest.php.',
    'submitterName'  => 'P964 Vera Visitor',
    'submitterEmail' => VERA_TYPED,
]);
$veraEvent = one("SELECT * FROM `tblEvents` WHERE `siteID` = ? AND `eventName` = 'P964 bring and share lunch' ORDER BY `eventID` DESC LIMIT 1", 'i', [ORG_A]);
$veraId    = (int) ($veraEvent['eventID'] ?? 0);
$modPage   = req($jar['adm'], 'GET', '/admin/calendar/moderation?status=pending');
$moderated = req($jar['adm'], 'POST', '/admin/calendar/moderate', ['csrf_token' => token($modPage['body']), 'eventID' => (string) $veraId, 'action' => 'approve']);
$veraEvent = one('SELECT * FROM `tblEvents` WHERE `eventID` = ?', 'i', [$veraId]);
// The approval e-mail's own send-log row, whatever became of the message (no
// mail route is set up here, so it is recorded as not sent - still a row TO
// the address, which is what the erasure has to reach).
$realLog = q('SELECT * FROM `tblEmailLog` WHERE `siteID` = ? AND `subject` = ? AND `toRecipients` = ?', 'iss', [ORG_A, $approvalSubject, VERA_TYPED]);
ok('N1 THE PREMISE: a visitor suggests an event through the real form and an administrator approves it through the real handler; the event holds the name and the address as typed, and the approval e-mail to that address is in the send log under its subject (#964)',
    $veraId > 0 && ($veraEvent['submitterName'] ?? '') === 'P964 Vera Visitor' && ($veraEvent['submitterEmail'] ?? '') === VERA_TYPED
        && $veraEvent['submittedByID'] === null && ($veraEvent['submissionStatus'] ?? '') === 'approved' && count($realLog) === 1,
    'submit ' . $sent['status'] . ' ' . $sent['location'] . ' | moderate ' . $moderated['status'] . ' | event ' . json_encode($veraEvent === null ? null : array_intersect_key($veraEvent, array_flip(['eventID', 'submitterName', 'submitterEmail', 'submissionStatus']))) . ' | log rows ' . count($realLog));

// ── N2. What is refused, and files nothing ───────────────────────────────────
$visitorRequests = static fn (): array => q("SELECT * FROM `tblErasureRequest` WHERE `siteID` IN (?, ?) AND `subjectKind` = 'visitor_address' ORDER BY `requestID`", 'ii', [ORG_A, ORG_B]);
$file = static function (string $who, array $fields) use (&$jar): array {
    $list = req($jar[$who], 'GET', '/admin/erasure-requests');
    $r    = req($jar[$who], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($list['body']), 'action' => 'file_visitor'] + $fields);

    return ['status' => $r['status'], 'message' => $r['status'] === 302 ? message(req($jar[$who], 'GET', '/admin/erasure-requests')['body']) : ''];
};
$howNote   = 'P964 replied from that address';
$noTick    = $file('adm', ['visitorEmail' => VERA_ADDR, 'howChecked' => $howNote]);
$noNote    = $file('adm', ['visitorEmail' => VERA_ADDR, 'checkedOffPortal' => '1', 'howChecked' => "  \n "]);
$longNote  = $file('adm', ['visitorEmail' => VERA_ADDR, 'checkedOffPortal' => '1', 'howChecked' => str_repeat('x', 301)]);
$notAddr   = $file('adm', ['visitorEmail' => 'not an address', 'checkedOffPortal' => '1', 'howChecked' => $howNote]);
$asArray   = $file('adm', ['visitorEmail' => [VERA_ADDR], 'checkedOffPortal' => '1', 'howChecked' => $howNote]);
$listAsMay = req($jar['may'], 'GET', '/admin/erasure-requests');
$byMay     = req($jar['may'], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token(req($jar['may'], 'GET', '/account/erasure-request')['body']), 'action' => 'file_visitor', 'visitorEmail' => VERA_ADDR, 'checkedOffPortal' => '1']);
$noToken   = req($jar['adm'], 'POST', '/admin/erasure-requests/process', ['action' => 'file_visitor', 'visitorEmail' => VERA_ADDR, 'checkedOffPortal' => '1']);
ok('N2 nothing is filed without the tick that says the administrator checked who is asking, without a note on how (only white space), with a note over 300 characters, for something that is not one address (typed, or sent as a list), by an ordinary member - even the one whose own account uses the address - or without the form token (#964, D-140, D-148)',
    $visitorRequests() === []
        && str_starts_with($noTick['message'], '[warning] Nothing was filed. Tick the box') === true
        && str_starts_with($noNote['message'], '[warning] Nothing was filed. Write a short note on how you checked') === true
        && str_starts_with($longNote['message'], '[warning] Nothing was filed: the note on how you checked is longer than 300 characters.') === true
        && str_starts_with($notAddr['message'], '[warning] Nothing was filed: that does not look like an e-mail address.') === true
        && str_starts_with($asArray['message'], '[warning] Nothing was filed: that does not look like an e-mail address.') === true
        && $listAsMay['status'] === 403 && $byMay['status'] === 403 && $noToken['status'] === 400,
    'no tick: ' . $noTick['message'] . ' | no note: ' . $noNote['message'] . ' | long note: ' . $longNote['message'] . ' | not an address: ' . $notAddr['message'] . ' | a list: ' . $asArray['message']
        . ' | May list ' . $listAsMay['status'] . ', post ' . $byMay['status'] . ' | no token ' . $noToken['status'] . ' | filed ' . count($visitorRequests()));

// ── N3a. The real form: the note box and its warning (#964 round 3, D-148) ─
// Section N used to post the fields directly, so deleting the whole note box
// and its warning from the page left every check green (the round-3 check did
// exactly that). This reads the form an administrator really gets, from the
// PARSED page, and files through it using the form's OWN field names: the
// e-mail box, the text box and the tick-box are found by what they ARE, and
// whatever name the page gives them is what is posted. Remove the note box and
// the filing below has no note to send, so it is refused - which is the point.
/**
 * The visitor form on the list page: what is wrong with it, and how to fill it.
 *
 * @return array{problems: list<string>, fields: array<string, string>, email: string, note: string, tick: string}
 */
$n964Form = static function () use (&$jar): array {
    $html = req($jar['adm'], 'GET', '/admin/erasure-requests')['body'];
    $doc  = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $out  = ['problems' => [], 'fields' => [], 'email' => '', 'note' => '', 'tick' => ''];
    $form = null;
    foreach ($doc->querySelectorAll('form') as $f) {
        if ($f->querySelector('input[name="action"][value="file_visitor"]') !== null) {
            $form = $f;
        }
    }
    if ($form === null) {
        $out['problems'][] = 'the visitor form is missing';

        return $out;
    }
    foreach ($form->querySelectorAll('input[type="hidden"]') as $h) {
        $out['fields'][(string) $h->getAttribute('name')] = (string) $h->getAttribute('value');
    }
    $out['email'] = (string) ($form->querySelector('input[type="email"]')?->getAttribute('name') ?? '');
    $out['tick']  = (string) ($form->querySelector('input[type="checkbox"]')?->getAttribute('name') ?? '');
    $noteBox      = $form->querySelector('input[type="text"], textarea');
    if ($noteBox === null) {
        $out['problems'][] = 'the note box or its warning is missing from the real form';

        return $out;
    }
    $out['note'] = (string) $noteBox->getAttribute('name');
    $helpId      = (string) $noteBox->getAttribute('aria-describedby');
    $help        = $helpId === '' ? null : $doc->getElementById($helpId);
    $helpText    = $help === null ? '' : text($help->innerHTML);
    if ($out['note'] !== 'howChecked' || $noteBox->hasAttribute('required') === false || $noteBox->getAttribute('maxlength') !== '300'
        || str_contains($helpText, 'kept with the request for ever') === false
        || str_contains($helpText, 'do not put the person') === false
    ) {
        $out['problems'][] = 'the note box or its warning is missing from the real form';
    }

    return $out;
};
/** File through the real form, as a browser would: only the fields the form has. */
$n964FileViaForm = static function (string $email, string $note) use (&$jar, $n964Form): array {
    $form   = $n964Form();
    $fields = $form['fields'];
    if ($form['email'] !== '') {
        $fields[$form['email']] = $email;
    }
    if ($form['note'] !== '') {
        $fields[$form['note']] = $note;
    }
    if ($form['tick'] !== '') {
        $fields[$form['tick']] = '1';
    }
    $r = req($jar['adm'], 'POST', '/admin/erasure-requests/process', $fields);

    return ['status' => $r['status'], 'message' => $r['status'] === 302 ? message(req($jar['adm'], 'GET', '/admin/erasure-requests')['body']) : '', 'form' => $form];
};
$realForm = $n964Form();
ok('N3a the real page\'s form has the note box - named howChecked, required, at most 300 characters - with the warning beside it that it is kept for ever and must not hold the person\'s details, and the form\'s own e-mail, note and tick fields are what the filing below posts (#964 round 3, D-148)',
    $realForm['problems'] === [] && $realForm['email'] === 'visitorEmail' && $realForm['tick'] === 'checkedOffPortal'
        && ($realForm['fields']['action'] ?? '') === 'file_visitor' && ($realForm['fields']['csrf_token'] ?? '') !== '',
    json_encode(['problems' => $realForm['problems'], 'email' => $realForm['email'], 'note' => $realForm['note'], 'tick' => $realForm['tick'], 'hidden' => array_keys($realForm['fields'])]));

// ── N3. Filed, once ─────────────────────────────────────────────────────────
$n964Fixtures();
$untouchedBefore = $n964Untouched();
$activityBefore  = (int) (one('SELECT COALESCE(MAX(`logID`), 0) AS m FROM `tblActivityLogs`')['m'] ?? 0);
$filed           = $n964FileViaForm('  p964.VERA@selftest.invalid ', "  P964 replied\n from that address ");
$again           = $file('adm', ['visitorEmail' => VERA_TYPED, 'checkedOffPortal' => '1', 'howChecked' => $howNote]);
$requests        = $visitorRequests();
$vReq            = $requests[0] ?? [];
$vId             = (int) ($vReq['requestID'] ?? 0);
$filedLine       = one('SELECT `action`, `details` FROM `tblErasureAudit` WHERE `requestID` = ? ORDER BY `auditID` LIMIT 1', 'i', [$vId]);
ok('N3 an administrator who ticks the box and notes how they checked files ONE request: for that address with the spaces dropped, no account, at pending review with no confirmation link, of the visitor kind; its report records who said they checked AND their note, as one line; a second filing for the same address in other capitals is told the first is already waiting (#964, D-140, D-148)',
    count($requests) === 1 && $vReq['subjectEmail'] === 'p964.VERA@selftest.invalid' && $vReq['userID'] === null
        && $vReq['status'] === 'pending_review' && $vReq['confirmToken'] === null && $vReq['confirmedAt'] !== null
        && (int) $vReq['siteID'] === ORG_A
        && str_starts_with($filed['message'], '[success] Erasure request #' . $vId . ' is filed for that address') === true
        && str_starts_with($again['message'], '[info] Nothing new was filed: erasure request #' . $vId . ' for that address') === true
        && ($filedLine['action'] ?? '') === 'filed'
        && str_contains((string) ($filedLine['details'] ?? ''), 'administrator with account #' . ADM . ' ') === true
        && str_contains((string) ($filedLine['details'] ?? ''), 'checked, outside the portal') === true
        && str_contains((string) ($filedLine['details'] ?? ''), 'noted how (D-148): "P964 replied from that address".') === true,
    json_encode($vReq) . ' | ' . $filed['message'] . ' | ' . $again['message'] . ' | ' . json_encode($filedLine));

// ── N4. The list page, and the one button that must not apply ───────────────
$listNow  = req($jar['adm'], 'GET', '/admin/erasure-requests')['body'];
$listDoc  = \Dom\HTMLDocument::createFromString($listNow, LIBXML_NOERROR);
$forThis  = static function (string $action) use ($listDoc, $vId): int {
    $n = 0;
    foreach ($listDoc->querySelectorAll('form') as $f) {
        $idField = $f->querySelector('input[name="requestID"]');
        $act     = $f->querySelector('input[name="action"]');
        if ($idField !== null && $act !== null && (int) $idField->getAttribute('value') === $vId && $act->getAttribute('value') === $action) {
            $n++;
        }
    }

    return $n;
};
$reviewLinks = 0;
foreach ($listDoc->querySelectorAll('a') as $a) {
    if (str_contains((string) $a->getAttribute('href'), 'review?id=' . $vId) === true) {
        $reviewLinks++;
    }
}
$byHandMsg = act($jar['adm'], $vId, 'finish_by_hand');
ok('N4 the list shows the request as event suggestions with no account, offers Execute and Cancel for it but neither "Dealt with by hand" nor "Review free text", and a hand-made "Dealt with by hand" press is refused with the reason and changes nothing (#964)',
    str_contains(text($listNow), 'Event suggestions, no account') === true
        && $forThis('execute') === 1 && $forThis('cancel') === 1 && $forThis('finish_by_hand') === 0 && $reviewLinks === 0
        && str_starts_with($byHandMsg, '[warning] Erasure request #' . $vId . ' is for a visitor\'s e-mail address') === true
        && request($vId)['status'] === 'pending_review' && auditLines($vId) === 1,
    'execute ' . $forThis('execute') . ', cancel ' . $forThis('cancel') . ', by hand ' . $forThis('finish_by_hand') . ', review links ' . $reviewLinks . ' | ' . $byHandMsg);

// ── N5-N9. Execute, and what it did ───────────────────────────────────────────
$veraBefore = one('SELECT * FROM `tblEvents` WHERE `eventID` = ?', 'i', [$veraId]);
$caseBefore = one('SELECT * FROM `tblEvents` WHERE `eventID` = ?', 'i', [EV_CASE]);
$runMsg     = act($jar['adm'], $vId, 'execute');
$vReqAfter  = request($vId);
ok('N5 Execute completes the request, and the request then holds no address: its own made-up one, no account, completed by this administrator (#964)',
    str_starts_with($runMsg, '[success] Erasure request #' . $vId . ' is completed: all 4 steps ran and none failed.') === true
        && $vReqAfter['status'] === 'completed' && $vReqAfter['subjectEmail'] === 'deleted-' . $vId . '@example.invalid'
        && $vReqAfter['userID'] === null && (int) $vReqAfter['processedByID'] === ADM && $vReqAfter['subjectKind'] === 'visitor_address',
    $runMsg . ' | ' . json_encode($vReqAfter));

$veraAfter = one('SELECT * FROM `tblEvents` WHERE `eventID` = ?', 'i', [$veraId]);
$caseAfter = one('SELECT * FROM `tblEvents` WHERE `eventID` = ?', 'i', [EV_CASE]);
$sameApartFromTwo = static function (?array $before, ?array $after): bool {
    if ($before === null || $after === null) {
        return false;
    }
    unset($before['submitterName'], $before['submitterEmail'], $after['submitterName'], $after['submitterEmail']);

    return $before === $after;
};
$untouchedAfter = $n964Untouched();
ok('N6 the name and address are emptied on BOTH of the visitor\'s suggestions at this organisation - the approved one from the form, and a waiting, deleted one in other capitals with a space after - and nothing else on either event changes, not even when it was last edited (#964)',
    $veraAfter['submitterName'] === null && $veraAfter['submitterEmail'] === null
        && $caseAfter['submitterName'] === null && $caseAfter['submitterEmail'] === null
        && $sameApartFromTwo($veraBefore, $veraAfter) === true && $sameApartFromTwo($caseBefore, $caseAfter) === true,
    json_encode([$veraAfter['submitterName'] ?? 'gone', $veraAfter['submitterEmail'] ?? 'gone', $caseAfter['submitterName'] ?? 'gone', $caseAfter['submitterEmail'] ?? 'gone']));
ok('N7 NOTHING ELSE IS REACHED: the same address at organisation B, a longer address that contains it, the address with an accent the database calls equal, an event naming a member, the member\'s own waiting and sent mail to the same address, the member\'s ACCOUNT, activity records at B, naming the member, under another type or with more words, and the Errors page\'s line quoting the address are all exactly as they were (#964, D-140, D-150)',
    $untouchedAfter === $untouchedBefore,
    'changed: ' . json_encode(array_keys(array_filter([
        'events'  => $untouchedBefore['events'] !== $untouchedAfter['events'],
        'queue'   => $untouchedBefore['queue'] !== $untouchedAfter['queue'],
        'log'     => $untouchedBefore['log'] !== $untouchedAfter['log'],
        'account' => $untouchedBefore['account'] !== $untouchedAfter['account'],
        'activity' => $untouchedBefore['activity'] !== $untouchedAfter['activity'],
        'errors'  => $untouchedBefore['errors'] !== $untouchedAfter['errors'],
    ]))));
$queueGone = one('SELECT COUNT(*) AS n FROM `tblMailQueue` WHERE `queueID` = ?', 'i', [MQ_A]);
$logRows   = q('SELECT `emailLogID`, `toRecipients`, `errorDetail`, `subject`, `status` FROM `tblEmailLog` WHERE `emailLogID` = ? OR `emailLogID` = ? ORDER BY `emailLogID`', 'ii', [(int) ($realLog[0]['emailLogID'] ?? 0), EL_NULL]);
$logClean  = count($logRows) === 2;
foreach ($logRows as $lr) {
    $logClean = $logClean && $lr['toRecipients'] === '[erased]' && $lr['errorDetail'] === '' && $lr['subject'] === $approvalSubject;
}
ok('N8 the approval e-mail still waiting for this organisation is deleted, and the send log keeps the rows of every approval e-mail to the address - the real one and one recorded under no organisation - with the address taken out and the reason text emptied (#964, D-140)',
    (int) ($queueGone['n'] ?? -1) === 0 && $logClean === true,
    'queue rows left ' . ($queueGone['n'] ?? '?') . ' | ' . json_encode($logRows));

$alA = one('SELECT `siteID`, `userID`, `activityType`, `activityDescription`, `visitorIP`, `userAgent`, `requestHeaders`, `sessionID`, `sessionDataSnapshot` '
    . 'FROM `tblActivityLogs` WHERE `logID` = ?', 'i', [AL_A]);
ok('N8b the activity record the public form wrote before #963 - "Event #N submitted by anonymous" and the address, in other capitals - is KEPT with the address replaced by "[erased]" and its network address, browser, request headers, session fingerprint and session facts ALL empty, still at this organisation, naming nobody (#964, round 3)',
    $alA === ['siteID' => ORG_A, 'userID' => null, 'activityType' => 'EventSubmitted', 'activityDescription' => OLD_SENTENCE . '[erased]',
        'visitorIP' => null, 'userAgent' => null, 'requestHeaders' => null, 'sessionID' => null, 'sessionDataSnapshot' => null],
    json_encode($alA));

$trail     = q('SELECT `action`, `tableName`, `recordKey`, `details` FROM `tblErasureAudit` WHERE `requestID` = ? ORDER BY `auditID`', 'i', [$vId]);
$shape     = array_map(static fn (array $t): string => $t['action'] . ' ' . $t['tableName'] . ' ' . (string) $t['recordKey'], $trail);
$trailText = mb_strtolower((string) json_encode($trail, JSON_UNESCAPED_UNICODE));
$activity  = mb_strtolower((string) json_encode(q('SELECT * FROM `tblActivityLogs` WHERE `logID` > ?', 'i', [$activityBefore]), JSON_UNESCAPED_UNICODE));
$report    = req($jar['adm'], 'GET', '/admin/erasure-requests/report?id=' . $vId);
ok('N9 the sealed report has one line per step, in order - filed, the suggestions (first, so one approved mid-run has no address left), the old activity records, the mail queue, the send log, completed - its chain verifies on the report page, and neither it nor any activity record written since holds the address anywhere (#964)',
    $shape === ['filed tblErasureRequest ', 'anonymise tblEvents 2 events', 'anonymise tblActivityLogs 1 records', 'delete tblMailQueue 1 deleted, 0 changed', 'anonymise tblEmailLog 2 rows, 0 error lines', 'completed tblErasureRequest ']
        && str_contains($trailText, 'p964.vera') === false && str_contains($activity, 'p964.vera') === false
        && $report['status'] === 200 && str_contains($report['body'], 'verified') === true,
    implode(' | ', $shape) . ' | report ' . $report['status']);

// ── N10. Run again for an address that holds nothing, then cancel one ───────
$otherFiled = $file('adm', ['visitorEmail' => 'p964.nobody@selftest.invalid', 'checkedOffPortal' => '1', 'howChecked' => 'P964 replied from that address']);
$otherId    = (int) (one("SELECT MAX(`requestID`) AS m FROM `tblErasureRequest` WHERE `siteID` = ? AND `subjectKind` = 'visitor_address'", 'i', [ORG_A])['m'] ?? 0);
$cancelMsg  = act($jar['adm'], $otherId, 'cancel');
ok('N10 a request for an address that suggested nothing can still be cancelled before it is run, and is (#964)',
    $otherId !== $vId && str_starts_with($otherFiled['message'], '[success]') === true
        && $cancelMsg === '[info] Erasure request cancelled.' && request($otherId)['status'] === 'cancelled',
    $otherFiled['message'] . ' | ' . $cancelMsg);

// ── N11-N15. The plants ───────────────────────────────────────────────────────
// Each changes ONE thing in the scratch copy the server runs, is proved
// applied, and must turn ONE named observation red; then the file is put back
// byte for byte. The observation is the same question N6-N8 ask, answered on a
// fresh set of rows and a fresh request each time.
$n964Observe = static function () use ($n964Fixtures, $n964Untouched, $n964FileViaForm, &$jar): array {
    $n964Fixtures();
    $before = $n964Untouched();
    $f      = $n964FileViaForm(VERA_ADDR, 'P964 replied from that address');
    $id     = (int) (one("SELECT MAX(`requestID`) AS m FROM `tblErasureRequest` WHERE `siteID` = ? AND `subjectKind` = 'visitor_address'", 'i', [ORG_A])['m'] ?? 0);
    $msg    = act($jar['adm'], $id, 'execute');
    $after  = $n964Untouched();
    $wrong  = $f['form']['problems'];
    if (str_starts_with((string) $f['message'], '[success]') === false) {
        $wrong[] = 'filing through the real form was refused: ' . $f['message'];
    }
    foreach (['events', 'queue', 'log', 'account', 'activity', 'errors'] as $part) {
        if ($before[$part] !== $after[$part]) {
            $wrong[] = 'untouched ' . $part . ' changed';
        }
    }
    if ((int) (one('SELECT COUNT(*) AS n FROM `tblMailQueue` WHERE `queueID` = ?', 'i', [MQ_A])['n'] ?? -1) !== 0) {
        $wrong[] = 'the waiting approval e-mail is still there';
    }
    if ((string) (one('SELECT `toRecipients` FROM `tblEmailLog` WHERE `emailLogID` = ?', 'i', [EL_NULL])['toRecipients'] ?? '') !== '[erased]') {
        $wrong[] = 'the sent approval e-mail still holds the address';
    }
    if (one('SELECT `submitterEmail` FROM `tblEvents` WHERE `eventID` = ?', 'i', [EV_CASE])['submitterEmail'] !== null) {
        $wrong[] = 'the suggestion still holds the address';
    }
    if ((string) (one('SELECT `activityDescription` FROM `tblActivityLogs` WHERE `logID` = ?', 'i', [AL_A])['activityDescription'] ?? '') !== OLD_SENTENCE . '[erased]') {
        $wrong[] = 'the old activity record still holds the address';
    }
    $browser = one('SELECT `visitorIP`, `userAgent`, `requestHeaders`, `sessionID`, `sessionDataSnapshot` FROM `tblActivityLogs` WHERE `logID` = ?', 'i', [AL_A]);
    if ($browser !== ['visitorIP' => null, 'userAgent' => null, 'requestHeaders' => null, 'sessionID' => null, 'sessionDataSnapshot' => null]) {
        $wrong[] = 'the old activity record still holds browser details';
    }
    if (request($id)['status'] !== 'completed') {
        $wrong[] = 'the request is not completed: ' . $msg;
    }

    return $wrong;
};
// The WHOLE note block - label, box and warning - as the page's source has it,
// so N20 removes exactly what the round-3 check removed. Read, not typed here,
// so a reworded warning does not stop the plant applying; an empty match makes
// the plant report "NOT APPLIED" rather than pass.
$n964NoteBlock = preg_match('/        <div class="col-md-7">\n.*?\n        <\/div>\n(?=        <div class="col-md-10">)/s',
    (string) file_get_contents($child . $sep . 'web' . $sep . '_apps' . $sep . 'admin' . $sep . 'erasure' . $sep . 'index.php'), $nb) === 1
    ? $nb[0] : 'NO NOTE BLOCK FOUND';
$plantFile = static fn (string $rel): string => $child . $sep . 'web' . $sep . str_replace('/', $sep, $rel);
$plants = [
    'N11 a match that trusts the database\'s comparison (the PHP test made always true): the accented and longer addresses\' events are emptied too' => [
        '_core/GdprEraser.php', ["if (\$wanted === '' || mb_strtolower(trim((string) \$row['submitterEmail'])) !== \$wanted) {" => 'if (false) {'], 'untouched events changed'],
    // BOTH statements carry the condition, so a plant that drops only one stays
    // green - each alone keeps a member's row out. That is the design; this
    // plant takes both away to show the condition is what does it.
    'N12 the "made without signing in" condition dropped from both statements: an event naming a MEMBER is emptied' => [
        '_core/GdprEraser.php', [
            "AND `submittedByID` IS NULL AND `externalFeedID` IS NULL '" => "AND `externalFeedID` IS NULL '",
            "'WHERE `eventID` = ? AND `submittedByID` IS NULL'"            => "'WHERE `eventID` = ?'",
        ], 'untouched events changed'],
    'N13 the mail-queue step left out: the waiting approval e-mail stays, and can still be sent' => [
        '_core/GdprEraser.php', ["\$outcomes[] = self::asOneStep(\$db, static fn (): string => self::eraseMailQueueMessages(\$requestId, \$address, \$subject, \$siteId));" => ''], 'the waiting approval e-mail is still there'],
    'N14 the send log\'s subject test dropped: the member\'s own sent mail to the same address loses it' => [
        '_core/GdprEraser.php', ["((string) \$row['subject'] !== \$onlySubject\n" => "(false\n"], 'untouched log changed'],
    'N15 the mail queue\'s organisation test dropped: organisation B\'s waiting approval e-mail is deleted' => [
        '_core/MailQueue.php', ["if (\$onlySiteId !== null && (int) (\$row['siteID'] ?? 0) !== \$onlySiteId) {" => 'if (false) {'], 'untouched queue changed'],
    // #964 round 2: the independent check made this exact edit and every
    // check stayed green, because nothing on the Errors page named the address.
    'N16 the send-log step looks at the Errors page for a visitor too: a mail server\'s line quoting the address - which says nothing about whose mail it was - is emptied' => [
        '_core/GdprEraser.php', ['if ($problem === null && $visitor === false) {' => 'if ($problem === null) {'], 'untouched errors changed'],
    'N17 the activity step no longer asks for a record naming NO account (both statements): the member\'s own record in the old sentence is changed' => [
        '_core/GdprEraser.php', [
            "AND `userID` IS NULL AND `activityDescription` LIKE ? FOR UPDATE'" => "AND `activityDescription` LIKE ? FOR UPDATE'",
            "' WHERE `logID` = ? AND `userID` IS NULL'" => "' WHERE `logID` = ?'",
        ], 'untouched activity changed'],
    // #964 round 3: the first version rewrote the description alone, and the
    // record still tied event #N to the visitor's network address and browser.
    'N19 the old activity record has its description changed ALONE: its network address, browser, headers and session details stay' => [
        '_core/GdprEraser.php', ['\'UPDATE `tblActivityLogs` SET `activityDescription` = ?, \' . $emptied . \' WHERE' => '\'UPDATE `tblActivityLogs` SET `activityDescription` = ? WHERE'],
        'the old activity record still holds browser details'],
    // #964 round 3: removing the whole note box and its warning from the page
    // left every check green while section N posted its fields directly.
    'N20 the note box and its warning taken off the real page: the form is missing them, and a filing through it is refused for want of a note' => [
        '_apps/admin/erasure/index.php', [$n964NoteBlock => ''], 'the note box or its warning is missing from the real form'],
];
foreach ($plants as $label => [$rel, $swaps, $expect]) {
    $path     = $plantFile($rel);
    $original = (string) file_get_contents($path);
    $applied  = true;
    foreach (array_keys($swaps) as $from) {
        $applied = $applied && substr_count($original, (string) $from) === 1;
    }
    $wrong    = ['(not run)'];
    if ($applied === true) {
        file_put_contents($path, strtr($original, $swaps));
        try {
            $wrong = $n964Observe();
        } finally {
            file_put_contents($path, $original);
        }
    }
    ok($label . ' - planted, the check goes red, and the file is put back (#964)',
        $applied === true && in_array($expect, $wrong, true) === true && (string) file_get_contents($path) === $original,
        ($applied === true ? 'applied' : 'NOT APPLIED: the text to change was not found exactly once') . ' | observed: ' . implode('; ', $wrong));
}
$cleanAgain = $n964Observe();
ok('N21 with every plant taken out again, the same fresh run is green: every observation the plants turned red holds (#964)',
    $cleanAgain === [], implode('; ', $cleanAgain));
$n964Clear();

// =============================================================================
head('O. A worker\'s own data download lists the records carrying their name, and nothing else from them (#929; D-142, D-151)');
// =============================================================================
// Since #703 a pastoral visit, a care case, a DBS check and a child's check-in
// or check-out KEEP the name and number of the worker who made them. D-142:
// the worker's own download LISTS each such record - kind, the DAY it was
// made, the organisation and a fixed sentence - and gives nothing else from
// it: no other person's name, no notes, no number. D-151: records in an
// organisation the worker cannot open here are counted, not listed.
//
// The world, one ground each (#616). Wil is a member of A ONLY and made one
// record of every kind, each on a fixed date. He also opened a case in B (he
// is no member there) and made a visit on it, and opened a case in C, a
// SWITCHED-OFF organisation he is still an active member of (#929 check round
// 1: "organisations you no longer belong to" was untrue of that one). Nell is
// a member of A who made none. Every detail of the OTHER people is a marked
// string, so its absence from the whole download is a measurement - and O1
// asks first that those strings really are in the database, or every "absent"
// below would pass on nothing.
//
// 🕰️ THE DAY, AND WHY A IS SET TO America/Nuuk FOR THIS SECTION. The download
//    gives the day only, cut from the stored text. A round trip through
//    strtotime() would read that text on the organisation's clock (start-up
//    sets PHP's zone to it), and on the night the clocks go forward at 23:00
//    a 23:30 reading lands on the NEXT DAY. Round 1's check used 01:30 on
//    29 March in an organisation on UTC (the default), where that is an
//    ordinary time, so a round-trip plant could not go red. Europe/London
//    would not do either now: there the round trip moves the HOUR, never the
//    day (measured with PHP's own zone data, O1 below asks it again). Nuuk
//    goes forward at 23:00 on 28 March 2026, which moves the day; the visit
//    is at 23:30 that evening, and the plant O10 must turn red.
// (The constants for this section are at the top of the file, with the others.)

$o929ZoneWas = one('SELECT `timezone` FROM `tblSites` WHERE `siteID` = ?', 'i', [ORG_A])['timezone'] ?? null;
q("UPDATE `tblSites` SET `timezone` = 'America/Nuuk' WHERE `siteID` = ?", 'i', [ORG_A]);
q("INSERT INTO `tblSites` (`siteID`, `siteKey`, `siteName`, `isActive`) VALUES (?, 'selftest-erasure-c', 'Selftest erasure C', 0)", 'i', [ORG_C]);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0), (?, ?, ?, 1, 0, 0), (?, ?, ?, 1, 0, 0)', 'issississ',
    [WIL, 'Wil Worker', 'u' . WIL . '.erasure@selftest.invalid', NELL, 'Nell None', 'u' . NELL . '.erasure@selftest.invalid', CHK, 'P929 Checked Person', 'u' . CHK . '.erasure@selftest.invalid']);
foreach ([[WIL, ORG_A], [NELL, ORG_A], [CHK, ORG_A], [WIL, ORG_C]] as [$id, $site]) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [$id, $site]);
}
q("INSERT INTO `tblCareCase` (`caseID`, `siteID`, `personName`, `category`, `summary`, `openedByID`, `openedByName`, `openedAt`) VALUES (?, ?, 'P929 Cared-For Person', 'bereavement', 'P929 secret summary', ?, 'Wil Worker', '2026-02-01 09:15:42')", 'iii', [O_CASE_A, ORG_A, WIL]);
q("INSERT INTO `tblCareVisit` (`visitID`, `caseID`, `visitedByID`, `visitedByName`, `visitedAt`, `kind`, `notes`) VALUES (?, ?, ?, 'Wil Worker', '2026-03-28 23:30:00', 'visit', 'P929 SECRET NOTE about the person')", 'iii', [O_VISIT_A, O_CASE_A, WIL]);
q("INSERT INTO `tblKidProfiles` (`childID`, `siteID`, `fullName`, `isActive`) VALUES (?, ?, 'P929 Child Name', 1)", 'ii', [O_CHILD, ORG_A]);
q("INSERT INTO `tblKidCheckins` (`checkinID`, `childID`, `badgeCode`, `checkedInAt`, `checkedInByID`, `checkedInByName`, `checkedOutAt`, `checkedOutByID`, `checkedOutByName`, `pickupName`) VALUES (?, ?, '929929', '2026-04-05 10:00:07', ?, 'Wil Worker', '2026-04-05 12:00:09', ?, 'Wil Worker', 'P929 Grandparent')", 'iiii', [O_CHECKIN, O_CHILD, WIL, WIL]);
q("INSERT INTO `tblDbsChecks` (`dbsCheckID`, `userID`, `dbsType`, `referenceNumber`, `issuedDate`, `expiresAt`, `status`, `recordedByID`, `recordedByName`, `recordedAt`, `notes`) VALUES (?, ?, 'enhanced-barred', 'P929-REF-SECRET', '2025-11-11', '2028-11-11', 'valid', ?, 'Wil Worker', '2026-01-20 14:00:31', 'P929 dbs note')", 'iii', [O_DBS, CHK, WIL]);
q("INSERT INTO `tblCareCase` (`caseID`, `siteID`, `personName`, `category`, `summary`, `openedByID`, `openedByName`, `openedAt`) VALUES (?, ?, 'P929 B Person', 'hospital', 'P929 B summary', ?, 'Wil Worker', '2025-12-01 08:00:00'), (?, ?, 'P929 C Person', 'family', 'P929 C summary', ?, 'Wil Worker', '2025-10-01 10:00:00')", 'iiiiii', [O_CASE_B, ORG_B, WIL, O_CASE_C, ORG_C, WIL]);
q("INSERT INTO `tblCareVisit` (`visitID`, `caseID`, `visitedByID`, `visitedByName`, `visitedAt`, `kind`, `notes`) VALUES (?, ?, ?, 'Wil Worker', '2025-12-02 08:30:00', 'call', 'P929 B note')", 'iii', [O_VISIT_B, O_CASE_B, WIL]);
foreach (['wil' => WIL, 'nell' => NELL] as $who => $id) {
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-erasure-' . $who, $hash]);
    $in = $signIn($who);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-erasure-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
}

// What the other people are, as stored, and the records' own numbers. None
// of these may appear ANYWHERE in Wil's downloads or his page. (The TIME of
// day, which the download leaves out, is not searched for: the full data copy
// carries Wil's own activity records, whose times could match by chance. The
// exact comparison of every entry, O2, is what pins it.)
$o929Secrets = ['P929 Cared-For Person', 'P929 secret summary', 'bereavement', 'P929 SECRET NOTE', 'P929 Child Name', 'P929 Grandparent',
    '929929', 'P929 Checked Person', 'P929-REF-SECRET', 'enhanced-barred', 'P929 dbs note', 'P929 B Person', 'P929 B summary', 'P929 B note',
    'P929 C Person', 'P929 C summary', 'Selftest erasure C', 'Selftest erasure B',
    (string) O_CASE_A, (string) O_VISIT_A, (string) O_CHILD, (string) O_CHECKIN, (string) O_DBS, (string) O_CASE_B, (string) O_VISIT_B, (string) O_CASE_C, (string) CHK];
$o929Premise = one(
    'SELECT (SELECT COUNT(*) FROM `tblCareCase` WHERE `caseID` = ? AND `personName` = \'P929 Cared-For Person\' AND `openedByID` = ?) '
    . '+ (SELECT COUNT(*) FROM `tblCareVisit` WHERE `visitID` = ? AND `notes` LIKE \'P929 SECRET NOTE%\' AND `visitedByID` = ?) '
    . '+ (SELECT COUNT(*) FROM `tblKidCheckins` k JOIN `tblKidProfiles` p ON p.`childID` = k.`childID` WHERE k.`checkinID` = ? AND p.`fullName` = \'P929 Child Name\' AND k.`checkedInByID` = ? AND k.`checkedOutByID` = ?) '
    . '+ (SELECT COUNT(*) FROM `tblDbsChecks` WHERE `dbsCheckID` = ? AND `referenceNumber` = \'P929-REF-SECRET\' AND `recordedByID` = ?) '
    . '+ (SELECT COUNT(*) FROM `tblCareCase` WHERE `caseID` = ? AND `siteID` = ? AND `openedByID` = ?) '
    . '+ (SELECT COUNT(*) FROM `tblCareCase` c JOIN `tblSites` s ON s.`siteID` = c.`siteID` JOIN `tblUserSites` us ON us.`siteID` = s.`siteID` AND us.`userID` = ? AND us.`isActive` = 1 WHERE c.`caseID` = ? AND s.`isActive` = 0) '
    . '+ (SELECT COUNT(*) FROM `tblSites` WHERE `siteID` = ? AND `timezone` = \'America/Nuuk\') AS `n`',
    'iiiiiiiiiiiiiii', [O_CASE_A, WIL, O_VISIT_A, WIL, O_CHECKIN, WIL, WIL, O_DBS, WIL, O_CASE_B, ORG_B, WIL, WIL, O_CASE_C, ORG_A]
);
// PHP's own zone data, asked here too: the plant O10 is only a test if a
// round trip in Nuuk moves this reading to the next day.
$o929RoundTrip = (static function (): array {
    $was = date_default_timezone_get();
    $out = [];
    foreach (['America/Nuuk', 'Europe/London'] as $zone) {
        date_default_timezone_set($zone);
        $out[$zone] = date('Y-m-d', (int) strtotime('2026-03-28 23:30:00'));
    }
    date_default_timezone_set($was);

    return $out;
})();
ok('O1 premise: Wil\'s five kinds of record, his case and visit in B, and his case in switched-off C (where he is still an active member) are in the database beside the other people\'s details; A is on America/Nuuk, where a strtotime() round trip moves the 23:30 visit to the next day (in London it would not); Wil and Nell can sign in (#929)',
    (int) ($o929Premise['n'] ?? 0) === 7 && $o929RoundTrip === ['America/Nuuk' => '2026-03-29', 'Europe/London' => '2026-03-28']
    && isset($jar['wil'], $jar['nell']) === true, json_encode([$o929Premise, $o929RoundTrip]));

// The list D-142 asks for, written out in the test - words included - so a
// change to any of them is a change somebody has to make here too.
$o929About = 'These records carry your name because you made them. They are usually about somebody else - a person being cared for, a person whose DBS check was recorded, or a child - so each is listed only by its kind, the day it was made and the organisation that holds it (a DBS check is not recorded against any one organisation, so none is given for it), and nothing else from the record is given (decision D-142). They keep your name and account number even if you are erased (decisions D-016, D-110 and D-111).';
$o929Rows = [
    'dbs-check-recorded' => ['kind' => 'dbs-check-recorded', 'what' => 'DBS (safeguarding) check', 'day' => '2026-01-20', 'organisation' => null,
        'yourPart' => 'Your name and account number are on this record as the person who recorded the check.'],
    'care-case-opened' => ['kind' => 'care-case-opened', 'what' => 'Pastoral care case', 'day' => '2026-02-01', 'organisation' => 'Selftest erasure A',
        'yourPart' => 'Your name and account number are on this record as the person who opened the case.'],
    'care-visit' => ['kind' => 'care-visit', 'what' => 'Pastoral care visit', 'day' => '2026-03-28', 'organisation' => 'Selftest erasure A',
        'yourPart' => 'Your name and account number are on this record as the person who made the visit.'],
    'child-checked-in' => ['kind' => 'child-checked-in', 'what' => 'Child\'s check-in', 'day' => '2026-04-05', 'organisation' => 'Selftest erasure A',
        'yourPart' => 'Your name and account number are on this record as the helper who checked the child in.'],
    'child-checked-out' => ['kind' => 'child-checked-out', 'what' => 'Child\'s check-out', 'day' => '2026-04-05', 'organisation' => 'Selftest erasure A',
        'yourPart' => 'Your name and account number are on this record as the helper who checked the child out.'],
    // Only in the driven "no such table" view, where C is switched ON for
    // the moment, so Wil can open it and its case is listed.
    'care-case-opened@C' => ['kind' => 'care-case-opened', 'what' => 'Pastoral care case', 'day' => '2025-10-01', 'organisation' => 'Selftest erasure C',
        'yourPart' => 'Your name and account number are on this record as the person who opened the case.'],
];
$o929NotListed = static fn (int $n): string => $n === 1
    ? '1 more record of these kinds carries your name in an organisation you cannot open here. It is not listed; the organisation that holds it can tell you about it.'
    : $n . ' more records of these kinds carry your name in organisations you cannot open here. They are not listed; the organisation that holds each one can tell you about it.';
$o929CouldNotRead = 'Some of these records could not be read just now, so this list may be incomplete. Please try again later.';
/** The whole answer expected, for a list of kinds shown, a count not listed, and the kinds that could not be read. */
$o929Answer = static function (array $kinds, int $notListed, array $unread) use ($o929About, $o929Rows, $o929NotListed): array {
    return [
        'about'          => $o929About,
        'records'        => array_values(array_map(static fn (string $k): array => $o929Rows[$k], $kinds)),
        'notListed'      => $notListed,
        'notListedWords' => $notListed === 0 ? null : $o929NotListed($notListed),
        'couldNotRead'   => $unread,
    ];
};

/** The three things one person is handed: My data's page, its JSON download, and the full data copy. */
$o929Fetch = static function (string $who) use (&$jar): array {
    $page   = req($jar[$who], 'GET', '/account/my-data');
    $json   = req($jar[$who], 'GET', '/account/my-data?download=1');
    $export = req($jar[$who], 'GET', '/account/data-export');

    return [
        'page'   => $page,
        'json'   => $json,
        'export' => $export,
        'mine'   => json_decode($json['body'], true)['recordsCarryingYourName'] ?? null,
        'copy'   => json_decode($export['body'], true)['data']['recordsCarryingYourName'] ?? null,
        'ok'     => $page['status'] === 200 && $json['status'] === 200 && $export['status'] === 200,
    ];
};
/**
 * What My data DRAWS in the card, read from the parsed page: each row's kind
 * and its four columns' text, and the exact text of the three sentences the
 * card can carry (about, not listed, could not be read). null when the card
 * is not on the page, [] when it is there more than once.
 */
$o929PageView = static function (string $html): ?array {
    $doc   = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $cards = $doc->querySelectorAll('[data-records-carrying-your-name]');
    if ($cards->length === 0) {
        return null;
    }
    if ($cards->length !== 1) {
        return [];
    }
    $card  = $cards->item(0);
    $say   = static function (string $selector) use ($card): ?string {
        $found = $card->querySelectorAll($selector);

        return $found->length === 1 ? trim((string) preg_replace('/\s+/', ' ', (string) $found->item(0)->textContent)) : ($found->length === 0 ? null : '(more than one)');
    };
    $rows = [];
    foreach ($card->querySelectorAll('.portal-data-list > .row') as $row) {
        $cells = [];
        foreach ($row->children as $cell) {
            $cells[] = trim((string) preg_replace('/\s+/', ' ', (string) $cell->textContent));
        }
        $rows[] = [(string) $row->getAttribute('data-record-kind'), $cells];
    }

    return ['rows' => $rows, 'about' => $say('[data-records-about]'), 'notListed' => $say('[data-records-not-listed]'), 'couldNotRead' => $say('[data-records-could-not-read]')];
};
/** What the page must draw for an expected answer. */
$o929PageFor = static function (array $answer) use ($o929CouldNotRead): array {
    return [
        'rows'         => array_map(static fn (array $r): array => [$r['kind'], [$r['what'], $r['day'], $r['organisation'] ?? 'Not held against an organisation', $r['yourPart']]], $answer['records']),
        'about'        => $answer['about'],
        'notListed'    => $answer['notListedWords'],
        'couldNotRead' => $answer['couldNotRead'] === [] ? null : $o929CouldNotRead,
    ];
};
/** Does one person's three answers all equal the expected answer? */
$o929Matches = static function (array $got, array $answer) use ($o929PageView, $o929PageFor): bool {
    return $got['ok'] === true && $got['mine'] === $answer && $got['copy'] === $answer && $o929PageView($got['page']['body']) === $o929PageFor($answer);
};
$o929Edit = static function (string $rel, string $from, string $to, callable $run) use ($plantFile) {
    $path     = $plantFile($rel);
    $original = (string) file_get_contents($path);
    if (substr_count($original, $from) !== 1) {
        return null;
    }
    file_put_contents($path, str_replace($from, $to, $original));
    try {
        return $run();
    } finally {
        file_put_contents($path, $original);
    }
};

/**
 * Everything section O asserts, as a list of what was WRONG (empty when right),
 * so a planted fault can be asked which observation it turned red. Two
 * failures are DRIVEN inside it every time, so a plant that breaks how they
 * are answered goes red too:
 *   - one kind cannot be read: a column of `tblCareVisit` is renamed for
 *     the moment (error 1054, a real database failure, not 1146);
 *   - the person's organisations cannot be read: the call is made to throw in
 *     the running copy (SiteGate asks the same question earlier in the same
 *     request and its answer is kept, so a database fault would stop the
 *     request at the gate instead of reaching this);
 *   - a table does not exist (error 1146, "the app is not installed"): the
 *     whole of `tblCareVisit` is renamed for the moment, and that kind must be
 *     NONE, not unread. Organisation C is switched ON for the same moment, so
 *     its case is listed and exactly ONE record is left counted - the only
 *     view that reaches the singular "1 more record" sentence.
 * Every rename is also put back by putSectionOBack(), at the start of a run
 * and in cleanup(), because a `finally` does not run when a signal ends it.
 */
$o929Observe = static function () use ($o929Fetch, $o929Answer, $o929Matches, $o929PageView, $o929Secrets, $o929Edit, $db): array {
    $wrong = [];
    $all   = ['dbs-check-recorded', 'care-case-opened', 'care-visit', 'child-checked-in', 'child-checked-out'];
    $wil   = $o929Fetch('wil');
    $nell  = $o929Fetch('nell');
    if ($wil['ok'] === false || $nell['ok'] === false) {
        $wrong[] = 'a download did not answer';
    }
    $mine = is_array($wil['mine']) === true ? $wil['mine'] : [];
    $full = $o929Answer($all, 3, []);
    if (($mine['records'] ?? null) !== $full['records'] || array_keys($mine) !== array_keys($full) || ($mine['about'] ?? null) !== $full['about']) {
        $wrong[] = 'wil list wrong';
    }
    if (($mine['notListed'] ?? null) !== 3 || ($mine['notListedWords'] ?? null) !== $full['notListedWords'] || ($mine['couldNotRead'] ?? null) !== []) {
        $wrong[] = 'not-listed count wrong';
    }
    if ($wil['copy'] !== $full) {
        $wrong[] = 'wil export list wrong';
    }
    if ($o929Matches($wil, $full) === false) {
        $wrong[] = 'wil page list wrong';
    }
    foreach ($o929Secrets as $secret) {
        foreach (['page', 'json', 'export'] as $part) {
            if (str_contains($wil[$part]['body'], $secret) === true) {
                $wrong[] = 'other person leaked';
                break 2;
            }
        }
    }
    $empty = $o929Answer([], 0, []);
    if ($nell['ok'] === false || $nell['mine'] !== $empty || $nell['copy'] !== $empty || $o929PageView($nell['page']['body']) !== null) {
        $wrong[] = 'nell sees something';
    }

    // One kind cannot be read: its records are neither listed nor counted,
    // the kind is named, and the page says so. B's visit drops out of the
    // count with the rest of the visits (3 becomes 2).
    $db->query('ALTER TABLE `tblCareVisit` RENAME COLUMN `visitedAt` TO `visitedAtP929`');
    try {
        $broken = $o929Fetch('wil');
    } finally {
        $db->query('ALTER TABLE `tblCareVisit` RENAME COLUMN `visitedAtP929` TO `visitedAt`');
    }
    if ($o929Matches($broken, $o929Answer(['dbs-check-recorded', 'care-case-opened', 'child-checked-in', 'child-checked-out'], 2, ['care-visit'])) === false) {
        $wrong[] = 'kind failure answered wrong';
    }

    // The person's organisations cannot be read: fail CLOSED. Nothing held by
    // an organisation is listed or counted, those four kinds are named, and
    // the DBS check (no organisation) is still listed.
    $closed = $o929Edit('_core/GdprEraser.php', 'foreach (Site::organisationsFor($userId, $db) as $org) {',
        'foreach ((static fn (): array => throw new \RuntimeException(\'p929 planted lookup failure\'))() as $org) {',
        static fn (): array => $o929Fetch('wil'));
    if ($closed === null || $o929Matches($closed, $o929Answer(['dbs-check-recorded'], 0, ['care-visit', 'care-case-opened', 'child-checked-in', 'child-checked-out'])) === false) {
        $wrong[] = 'lookup failure answered wrong';
    }

    // No such table: that kind is none (1146 is the one failure that means
    // "nothing held"), C's case is listed, and B's ONE case is counted.
    $db->query('RENAME TABLE `tblCareVisit` TO `tblCareVisitP929`');
    $db->query('UPDATE `tblSites` SET `isActive` = 1 WHERE `siteID` = ' . ORG_C);
    try {
        $gone = $o929Fetch('wil');
    } finally {
        $db->query('UPDATE `tblSites` SET `isActive` = 0 WHERE `siteID` = ' . ORG_C);
        putSectionOBack();
    }
    if ($o929Matches($gone, $o929Answer(['care-case-opened@C', 'dbs-check-recorded', 'care-case-opened', 'child-checked-in', 'child-checked-out'], 1, [])) === false) {
        $wrong[] = 'missing table answered wrong';
    }

    return array_values(array_unique($wrong));
};

$o929First = $o929Observe();
$o929Is = static fn (string $what): bool => in_array($what, $o929First, true) === false && in_array('a download did not answer', $o929First, true) === false;
ok('O2 Wil\'s My data JSON download lists exactly his five records - kind, the DAY only (cut from the stored text, so the 23:30 visit on the night Nuuk\'s clocks go forward stays on 28 March), organisation (none for a DBS check) and the fixed sentence - oldest first, with nothing else in each entry and the "about" words exactly as written here (#929, D-142)',
    $o929Is('wil list wrong'), implode('; ', $o929First));
ok('O3 the three records in organisations Wil cannot open here - B, where he is no member, and C, switched off though he is still a member - are COUNTED and not listed, and the sentence is exactly the one true of both (#929, D-151)',
    $o929Is('not-listed count wrong'), implode('; ', $o929First));
ok('O4 the full data copy (account/data-export) hands over the same answer as My data (#929)',
    $o929Is('wil export list wrong'), implode('; ', $o929First));
ok('O5 the My data PAGE draws the same five rows, and its "about" and "not listed" sentences exactly (#929)',
    $o929Is('wil page list wrong'), implode('; ', $o929First));
ok('O6 nothing from the records reaches Wil beyond kind, day and organisation - no cared-for person\'s or child\'s name, no notes, no case summary or category, no pickup name, no badge code, no DBS reference, type or subject, no time of day, no record number, and not even the NAME of an organisation he cannot open - anywhere in the page, the My data JSON or the full data copy (#929, D-142, D-151)',
    $o929Is('other person leaked'), implode('; ', $o929First));
ok('O7 Nell, who made none of these records, is handed an empty list in both downloads and sees no such card on her page (#929)',
    $o929Is('nell sees something'), implode('; ', $o929First));
ok('O8 DRIVEN: when one kind cannot be read (a real database error, not "no such table"), that kind is named as unread in both downloads, nothing of it is listed or counted, and the page says "could not be read" in exactly its words (#929)',
    $o929Is('kind failure answered wrong'), implode('; ', $o929First));
ok('O9b DRIVEN: when a table does not exist (the app not installed, error 1146) that kind is NONE, not unread; and with organisation C switched on its case is listed while B\'s ONE case is counted in the singular words, exactly (#929)',
    $o929Is('missing table answered wrong'), implode('; ', $o929First));
ok('O9 DRIVEN: when Wil\'s organisations cannot be read, it fails CLOSED - nothing held by an organisation is listed or counted, those kinds are named as unread, the DBS check (no organisation) is still listed, and the page says so (#929)',
    $o929Is('lookup failure answered wrong'), implode('; ', $o929First));

// Planted in the RUNNING copy (no opcache: see the server's start), each must
// turn its own observation red, and the file is put back byte for byte.
$o929Plants = [
    'O10 the day worked out through a strtotime() round trip on the organisation\'s clock, not cut from the stored text' => [
        '_core/GdprEraser.php', ["substr((string) \$row['onDate'], 0, 10)" => "date('Y-m-d', (int) strtotime((string) \$row['onDate']))"], 'wil list wrong'],
    'O11 the other person\'s NAME read and handed over (the case\'s personName carried into each entry)' => [
        '_core/GdprEraser.php', [
            "'sql'      => 'SELECT c.`openedAt` AS `onDate`, " => "'sql'      => 'SELECT c.`personName`, c.`openedAt` AS `onDate`, ",
            "'what'         => \$spec['what'],"                    => "'what'         => \$spec['what'] . json_encode(\$row),",
        ], 'other person leaked'],
    'O12 the record\'s NUMBER read and handed over (the visit\'s visitID carried into each entry)' => [
        '_core/GdprEraser.php', [
            "'sql'      => 'SELECT v.`visitedAt` AS `onDate`, " => "'sql'      => 'SELECT v.`visitID`, v.`visitedAt` AS `onDate`, ",
            "'what'         => \$spec['what'],"                   => "'what'         => \$spec['what'] . json_encode(\$row),",
        ], 'other person leaked'],
    'O13 ANOTHER organisation\'s records listed (the own-organisations test switched off)' => [
        '_core/GdprEraser.php', ['if ($own !== null && $siteId !== null && isset($own[$siteId]) === false) {' => 'if (false) {'], 'wil list wrong'],
    'O14 a record TYPE missing (no check-out is ever found)' => [
        '_core/GdprEraser.php', ["WHERE k.`checkedOutByID` = ?'," => "WHERE k.`checkedOutByID` = ? AND 1 = 0',"], 'wil list wrong'],
    'O15 the full data copy no longer asks for the list' => [
        '_apps/auth/account/data-export.php', ["'recordsCarryingYourName' => GdprEraser::recordsCarryingYourName(\$userId)," => ''], 'wil export list wrong'],
    'O16 the organisations lookup fails OPEN (a failure is taken as "every organisation")' => [
        '_core/GdprEraser.php', ["\$own        = [];\n                \$ownUnknown = true;" => '$own = null;'], 'lookup failure answered wrong'],
    'O17 every read failure answered as "none", as if the table were missing' => [
        '_core/GdprEraser.php', ['if ($problem instanceof \mysqli_sql_exception && $problem->getCode() === 1146) {' => 'if (true) {'], 'kind failure answered wrong'],
    'O18 the page\'s "could not be read" sentence deleted' => [
        '_apps/account/my-data.php', ['<p class="small text-warning mt-2 mb-0" data-records-could-not-read>Some of these records could not be read just now, so this list may be incomplete. Please try again later.</p>' => ''], 'kind failure answered wrong'],
    'O18b a missing table answered as "could not be read" rather than none' => [
        '_core/GdprEraser.php', ['$problem->getCode() === 1146) {' => '$problem->getCode() === -1) {'], 'missing table answered wrong'],
    'O18c the singular sentence never chosen ("1 more records ... organisations")' => [
        '_core/GdprEraser.php', ["\$out['notListedWords'] = \$out['notListed'] === 1" => "\$out['notListedWords'] = \$out['notListed'] === -1"], 'missing table answered wrong'],
];
foreach ($o929Plants as $label => [$rel, $swaps, $expect]) {
    $path     = $plantFile($rel);
    $original = (string) file_get_contents($path);
    $applied  = true;
    foreach (array_keys($swaps) as $from) {
        $applied = $applied && substr_count($original, (string) $from) === 1;
    }
    $wrong = ['(not run)'];
    if ($applied === true) {
        file_put_contents($path, strtr($original, $swaps));
        try {
            $wrong = $o929Observe();
        } finally {
            file_put_contents($path, $original);
        }
    }
    ok($label . ' - planted, the check goes red, and the file is put back (#929)',
        $applied === true && in_array($expect, $wrong, true) === true && (string) file_get_contents($path) === $original,
        ($applied === true ? 'applied' : 'NOT APPLIED: the text to change was not found exactly once') . ' | observed: ' . implode('; ', $wrong));
}
$o929Again = $o929Observe();
ok('O19 with every plant taken out again, the same observations are all green, the driven failures included (#929)', $o929Again === [], implode('; ', $o929Again));
q('UPDATE `tblSites` SET `timezone` = ? WHERE `siteID` = ?', 'si', [$o929ZoneWas, ORG_A]);

// =============================================================================
head('P. A member\'s own DBS checks are in their full data copy, and the privacy policy says what My data shows (#1012)');
// =============================================================================
// The DBS (safeguarding) checks recorded FOR a member - `tblDbsChecks.userID`,
// kept by an erasure - were counted on My data and never handed over in the
// full data copy (`account/data-export`). GdprEraser::dbsChecksAboutYou() is
// now the ONE place they are read: the copy hands over what it returns and My
// data's count asks it, so the two cannot disagree.
//
// What the copy hands over, written out here in full so a change to any field
// is a change somebody has to make here too: the record's number, the type,
// the certificate reference, the issue and expiry dates, the status, when it
// was recorded and its notes - each exactly as stored. What it never hands
// over: who RECORDED the check, by name or by number (another person; the
// rule this copy applies to every "who did this to you" column).
//
// The world, one ground each (#616). Della is a member of A with TWO checks:
// one recorded by Rex, a worker with an account, and an older expired one with
// no reference and no notes whose recorder is known only by a stored name.
// Della herself recorded a check for Otto, another member - so her copy LISTS
// that one (#929: kind and day only) and must hand over none of its details.
// Penny is a member of A with no checks at all. Every detail that must NOT reach
// Della is a marked string, and P1 asks first that each is really in the
// database, or every "absent" below would pass on nothing.
//
// 🕰️ A is put on America/Nuuk for this section, as section O does, and the
//    first check's `recordedAt` is 23:30 on 28 March 2026, inside the hour
//    Nuuk's clocks skip that night. A copy that turned the stored text into a
//    moment and back would move it to the next day; P11 plants exactly that.
$p1012ZoneWas = one('SELECT `timezone` FROM `tblSites` WHERE `siteID` = ?', 'i', [ORG_A])['timezone'] ?? null;
q("UPDATE `tblSites` SET `timezone` = 'America/Nuuk' WHERE `siteID` = ?", 'i', [ORG_A]);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0), (?, ?, ?, 1, 0, 0), (?, ?, ?, 1, 0, 0), (?, ?, ?, 1, 0, 0)', 'ississississ',
    [P_DELLA, 'P1012 Della Member', 'u' . P_DELLA . '.erasure@selftest.invalid', P_REX, 'P1012 Recorder Rex', 'u' . P_REX . '.erasure@selftest.invalid',
        P_OTTO, 'P1012 Otto Other', 'u' . P_OTTO . '.erasure@selftest.invalid', P_PENNY, 'P1012 Penny Plain', 'u' . P_PENNY . '.erasure@selftest.invalid']);
foreach ([P_DELLA, P_OTTO, P_PENNY] as $id) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [$id, ORG_A]);
}
q("INSERT INTO `tblDbsChecks` (`dbsCheckID`, `userID`, `dbsType`, `referenceNumber`, `issuedDate`, `expiresAt`, `status`, `recordedByID`, `recordedByName`, `recordedAt`, `notes`) VALUES "
    . "(?, ?, 'enhanced', 'P1012-REF-ONE', '2024-02-29', '2027-02-28', 'valid', ?, 'P1012 Recorder Rex', '2026-03-28 23:30:00', 'P1012 note about Della'), "
    . "(?, ?, 'basic', NULL, '2021-06-01', '2024-06-01', 'expired', NULL, 'P1012 Long-Gone Recorder', '2021-06-02 09:00:00', NULL), "
    . "(?, ?, 'standard', 'P1012-OTTO-REF', '2025-05-05', '2028-05-05', 'valid', ?, 'P1012 Della Member', '2026-05-05 10:00:00', 'P1012 note about Otto')",
    'iiiiiiii', [P_DBS_ONE, P_DELLA, P_REX, P_DBS_TWO, P_DELLA, P_DBS_OTTO, P_OTTO, P_DELLA]);
foreach (['p1012della' => P_DELLA, 'p1012penny' => P_PENNY] as $who => $id) {
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-erasure-' . $who, $hash]);
    $in = $signIn($who);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-erasure-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
}

$p1012Premise = one(
    'SELECT (SELECT COUNT(*) FROM `tblDbsChecks` WHERE `userID` = ? AND `dbsCheckID` IN (?, ?)) '
    . '+ (SELECT COUNT(*) FROM `tblDbsChecks` WHERE `dbsCheckID` = ? AND `recordedByID` = ? AND `recordedByName` = \'P1012 Recorder Rex\') '
    . '+ (SELECT COUNT(*) FROM `tblDbsChecks` WHERE `dbsCheckID` = ? AND `recordedByID` IS NULL AND `recordedByName` = \'P1012 Long-Gone Recorder\') '
    . '+ (SELECT COUNT(*) FROM `tblDbsChecks` WHERE `dbsCheckID` = ? AND `userID` = ? AND `recordedByID` = ? AND `referenceNumber` = \'P1012-OTTO-REF\') '
    . '+ (SELECT COUNT(*) FROM `tblDbsChecks` WHERE `userID` = ?) '
    . '+ (SELECT COUNT(*) FROM `tblSites` WHERE `siteID` = ? AND `timezone` = \'America/Nuuk\') AS `n`',
    'iiiiiiiiiii', [P_DELLA, P_DBS_ONE, P_DBS_TWO, P_DBS_ONE, P_REX, P_DBS_TWO, P_DBS_OTTO, P_OTTO, P_DELLA, P_PENNY, ORG_A]
);
// PHP's own zone data: P11 is only a test if a round trip in Nuuk moves this
// reading. (In London it would be an ordinary time, and nothing would move.)
$p1012RoundTrip = (static function (): string {
    $was = date_default_timezone_get();
    date_default_timezone_set('America/Nuuk');
    $moved = date('Y-m-d H:i:s', (int) strtotime('2026-03-28 23:30:00'));
    date_default_timezone_set($was);

    return $moved;
})();
ok('P1 premise: Della\'s two DBS checks (one recorded by Rex, one by a recorder known only by name), the check Della recorded for Otto, and Penny with none are in the database with every marked detail; A is on America/Nuuk, where a strtotime() round trip moves the 23:30 reading; Della and Penny can sign in (#1012)',
    (int) ($p1012Premise['n'] ?? -1) === 6 && $p1012RoundTrip !== '2026-03-28 23:30:00' && isset($jar['p1012della'], $jar['p1012penny']) === true,
    json_encode([$p1012Premise, $p1012RoundTrip]));

// What Della's copy must hold, written out in full: every field, every value
// exactly as stored, in the record's own order.
$p1012Expected = [
    ['dbsCheckID' => P_DBS_ONE, 'dbsType' => 'enhanced', 'referenceNumber' => 'P1012-REF-ONE', 'issuedDate' => '2024-02-29',
        'expiresAt' => '2027-02-28', 'status' => 'valid', 'recordedAtServerTime' => '2026-03-28 23:30:00', 'notes' => 'P1012 note about Della'],
    ['dbsCheckID' => P_DBS_TWO, 'dbsType' => 'basic', 'referenceNumber' => null, 'issuedDate' => '2021-06-01',
        'expiresAt' => '2024-06-01', 'status' => 'expired', 'recordedAtServerTime' => '2021-06-02 09:00:00', 'notes' => null],
];
// Who recorded Della's checks, by name and by number: in NONE of what she is handed.
// (Numbers are not searched for as text: section N's activity records are
// numbered 9170601 onwards, so a run's own later records can contain any short
// number by chance. The keys are searched for instead, and P2 pins every value.)
$p1012Recorder = ['P1012 Recorder Rex', 'P1012 Long-Gone Recorder', '"recordedByID"', '"recordedByName"'];
// Otto's check: in NONE of what Della is handed (she recorded it, so it is LISTED
// in her "records carrying your name" by kind and day, and nothing more).
$p1012Otto = ['P1012-OTTO-REF', 'P1012 note about Otto', 'P1012 Otto Other'];
// The privacy policy's "Your rights" section, as a member receives it.
$p1012Rights = static fn (string $html): string => renderedRegion($html, $sectionAfter('Your rights'));

/** One person's full data copy, My data page and My data JSON. */
$p1012Fetch = static function (string $who) use (&$jar): array {
    $export = req($jar[$who], 'GET', '/account/data-export');
    $page   = req($jar[$who], 'GET', '/account/my-data');
    $json   = req($jar[$who], 'GET', '/account/my-data?download=1');
    $copy   = json_decode($export['body'], true);
    $mine   = json_decode($json['body'], true);

    return [
        'export'    => $export,
        'page'      => $page,
        'json'      => $json,
        'hasKey'    => is_array($copy['data'] ?? null) === true && array_key_exists('dbsChecksAboutYou', $copy['data']) === true,
        'checks'    => $copy['data']['dbsChecksAboutYou'] ?? null,
        'inventory' => is_array($mine['inventory'] ?? null) === true ? $mine['inventory'] : null,
        'ok'        => $export['status'] === 200 && $page['status'] === 200 && $json['status'] === 200,
    ];
};
/**
 * My data's own rows for `tblDbsChecks`, as [count, first words of the reason],
 * from the JSON and from the drawn page, in the order shown.
 */
$p1012Counted = static function (array $got): array {
    $fromJson = [];
    foreach ((array) $got['inventory'] as $row) {
        if (($row['table'] ?? '') === 'tblDbsChecks') {
            $fromJson[] = [(int) $row['rows'], mb_substr((string) $row['reason'], 0, 24)];
        }
    }
    $fromPage = [];
    $doc      = \Dom\HTMLDocument::createFromString((string) $got['page']['body'], LIBXML_NOERROR);
    foreach ($doc->querySelectorAll('.portal-data-list > .row') as $row) {
        $cells = [];
        foreach ($row->children as $cell) {
            $cells[] = trim((string) preg_replace('/\s+/', ' ', (string) $cell->textContent));
        }
        if (($cells[0] ?? '') === 'tblDbsChecks') {
            $fromPage[] = [(int) ($cells[1] ?? -1), mb_substr((string) ($cells[3] ?? ''), 0, 24)];
        }
    }

    return ['json' => $fromJson, 'page' => $fromPage];
};

/** Everything section P asserts, as the list of what was WRONG (empty when right). */
$p1012Observe = static function () use ($p1012Fetch, $p1012Counted, $p1012Expected, $p1012Recorder, $p1012Otto, $p1012Rights, &$jar, $db): array {
    $wrong = [];
    $della  = $p1012Fetch('p1012della');
    $penny   = $p1012Fetch('p1012penny');
    if ($della['ok'] === false || $penny['ok'] === false) {
        $wrong[] = 'a download did not answer';
    }
    if ($della['hasKey'] === false || $della['checks'] !== $p1012Expected) {
        $wrong[] = 'copy wrong';
    }
    foreach ($p1012Recorder as $secret) {
        if (str_contains($della['export']['body'], $secret) === true) {
            $wrong[] = 'recorder leaked';
            break;
        }
    }
    foreach ($p1012Otto as $secret) {
        foreach (['export', 'page', 'json'] as $part) {
            if (str_contains($della[$part]['body'], $secret) === true) {
                $wrong[] = 'other member leaked';
                break 2;
            }
        }
    }
    // My data's count of the checks ABOUT Della must be the number of rows her
    // copy hands over - and the checks she RECORDED stay a row of their own.
    $copied  = is_array($della['checks']) === true ? count($della['checks']) : -1;
    $counted = $p1012Counted($della);
    $want    = [[$copied, 'Safeguarding record. Kep'], [1, 'DBS checks you recorded ']];
    if ($counted['json'] !== $want || $counted['page'] !== $want) {
        $wrong[] = 'count disagrees';
    }
    if ($penny['hasKey'] === false || $penny['checks'] !== [] || $p1012Counted($penny) !== ['json' => [], 'page' => []]) {
        $wrong[] = 'penny sees something';
    }
    // DRIVEN (#1012 check round 1): the checks cannot be read - a real
    // database error (1054, a column renamed for the moment), NOT "no such
    // table". The copy must refuse to answer rather than hand over an empty
    // list; My data and Request erasure must show the DBS row as "could not
    // be read" (no count), in the JSON too; the row for the checks Della
    // RECORDED is read separately and must still be counted.
    $db->query('ALTER TABLE `tblDbsChecks` RENAME COLUMN `notes` TO `notesP1012`');
    try {
        $broken = $p1012Fetch('p1012della');
        $asking = req($jar['p1012della'], 'GET', '/account/erasure-request');
    } finally {
        $db->query('ALTER TABLE `tblDbsChecks` RENAME COLUMN `notesP1012` TO `notes`');
        putSectionPBack();
    }
    if ($broken['export']['status'] === 200 || $broken['hasKey'] === true || str_contains($broken['export']['body'], 'dbsChecksAboutYou') === true) {
        $wrong[] = 'failure answered as none';
    }
    $unreadJson = [];
    foreach ((array) $broken['inventory'] as $row) {
        if (($row['table'] ?? '') === 'tblDbsChecks') {
            $unreadJson[] = ($row['couldNotRead'] ?? false) === true
                ? ['unread', $row['rows'], $row['note'] ?? null]
                : ['read', (int) $row['rows'], mb_substr((string) $row['reason'], 0, 24)];
        }
    }
    $unreadPage = [];
    $brokenDoc  = \Dom\HTMLDocument::createFromString((string) $broken['page']['body'], LIBXML_NOERROR);
    foreach ($brokenDoc->querySelectorAll('[data-inventory-could-not-read]') as $row) {
        $cells = [];
        foreach ($row->children as $cell) {
            $cells[] = trim((string) preg_replace('/\s+/', ' ', (string) $cell->textContent));
        }
        $unreadPage[] = $cells;
    }
    $unreadAsk = [];
    $askDoc    = \Dom\HTMLDocument::createFromString((string) $asking['body'], LIBXML_NOERROR);
    foreach ($askDoc->querySelectorAll('[data-inventory-could-not-read]') as $row) {
        $unreadAsk[] = trim((string) preg_replace('/\s+/', ' ', (string) $row->textContent));
    }
    $unreadWords = 'These records could not be read just now, so whether we hold any, and how many, is not known. Please try again later.';
    if ($broken['page']['status'] !== 200 || $asking['status'] !== 200
        || $unreadJson !== [['unread', null, $unreadWords], ['read', 1, 'DBS checks you recorded ']]
        || $unreadPage !== [['tblDbsChecks', '?', 'could not be read just now', $unreadWords]]
        || $unreadAsk !== ['tblDbsChecks — ' . $unreadWords]) {
        $wrong[] = 'inventory hid failure';
    }

    $policy = req($jar['p1012della'], 'GET', '/privacy/policy');
    $rights = $p1012Rights((string) $policy['body']);
    if ($policy['status'] !== 200 || $rights === '' || $rights !== (readRenderedPins()['pins']['privacy policy, your rights'] ?? null)) {
        $wrong[] = 'policy wrong';
    }

    return array_values(array_unique($wrong));
};

$p1012First = $p1012Observe();
$p1012Is    = static fn (string $what): bool => in_array($what, $p1012First, true) === false && in_array('a download did not answer', $p1012First, true) === false;
ok('P2 Della\'s full data copy (account/data-export) hands over BOTH her DBS checks under "dbsChecksAboutYou" - number, type, certificate reference, issue and expiry dates, status, when recorded and notes - every value exactly as stored (the 23:30 reading inside the hour Nuuk skips is unmoved), in order, with nothing else in each entry (#1012)',
    $p1012Is('copy wrong'), implode('; ', $p1012First));
ok('P3 neither recorder\'s NAME (Rex\'s, or the other recorder\'s stored name) and no field called recordedByID or recordedByName is anywhere in Della\'s full data copy (a recorder field under any OTHER name is caught by P2, whose match of every entry is exact) (#1012, D-154)',
    $p1012Is('recorder leaked'), implode('; ', $p1012First));
ok('P4 nothing of Otto\'s check - its reference, its notes, Otto\'s name - reaches Della in her copy, her My data page or its JSON, although she recorded it (#1012, #929)',
    $p1012Is('other member leaked'), implode('; ', $p1012First));
ok('P5 My data counts exactly the rows the copy hands over (2), in its JSON and on its page, and the check Della recorded for Otto stays a row of its own (1) (#1012)',
    $p1012Is('count disagrees'), implode('; ', $p1012First));
ok('P6 Penny, who has no DBS checks, is handed an empty "dbsChecksAboutYou" and My data shows her no DBS row (#1012)',
    $p1012Is('penny sees something'), implode('; ', $p1012First));
ok('P6b DRIVEN: when Della\'s checks cannot be read (a real database error, not "no such table"), the full data copy refuses to answer rather than handing over an empty list (#1012)',
    $p1012Is('failure answered as none'), implode('; ', $p1012First));
ok('P6c DRIVEN: in the same state My data (page and JSON) and Request erasure show the DBS row as "could not be read", with no count and in exactly its words, while the checks Della recorded are still counted (1) (#1012)',
    $p1012Is('inventory hid failure'), implode('; ', $p1012First));
// The pin is recorded through renderedPin() ONCE, here, on the clean page, so
// the pin file's own check counts it; the plants below compare without it.
$p1012RightsNow = $p1012Rights((string) req($jar['p1012della'], 'GET', '/privacy/policy')['body']);
ok('P7 the privacy policy\'s "Your rights" section - Article 15 now saying that both My data and the full copy also show the records carrying your name because you made them, listed only by kind, day and organisation, and only counted where you cannot open the organisation - is exactly the pinned markup a member receives, found once, inside exactly the pinned wrappers (#1012)',
    renderedPin('privacy policy, your rights', $p1012RightsNow) && $p1012Is('policy wrong'),
    $p1012RightsNow === '' ? 'not exactly one such section' : mb_substr($p1012RightsNow, 0, 600));

// Planted in the RUNNING copy (no opcache: see the server's start), each must
// turn its own observation red, and the file is put back byte for byte.
$p1012Plants = [
    'P8 the copy no longer hands the checks over (the rows missing)' => [
        '_apps/auth/account/data-export.php', ["'dbsChecksAboutYou' => GdprEraser::dbsChecksAboutYou(\$userId)," => ''], 'copy wrong'],
    'P9 the checks matched on EITHER column, so the check Della recorded for Otto is handed over as hers (another member\'s check included)' => [
        '_core/GdprEraser.php', ['FROM `tblDbsChecks` WHERE `userID` = ? ORDER BY `dbsCheckID`' => 'FROM `tblDbsChecks` WHERE ? IN (`userID`, `recordedByID`) ORDER BY `dbsCheckID`'], 'other member leaked'],
    'P10 who recorded each check read and handed over (a field that must be absent)' => [
        '_core/GdprEraser.php', ['`recordedAt`, `notes` \'' => '`recordedAt`, `notes`, `recordedByName` \'',
            "'notes'           => \$row['notes'] === null ? null : (string) \$row['notes']," => "'notes'           => \$row['notes'] === null ? null : (string) \$row['notes'], 'recordedByName' => \$row['recordedByName'],"], 'recorder leaked'],
    'P11 the time turned into a moment and back on the organisation\'s clock, not handed over as stored' => [
        '_core/GdprEraser.php', ["'recordedAtServerTime' => (string) \$row['recordedAt']," => "'recordedAtServerTime' => date('Y-m-d H:i:s', (int) strtotime((string) \$row['recordedAt'])),"], 'copy wrong'],
    'P12 My data counting the checks with its own query while the copy leaves expired checks out (the two allowed to disagree)' => [
        '_core/GdprEraser.php', ["if (\$table === 'tblDbsChecks' && \$col === 'userID' && \$via === null) {" => 'if (false) {',
            'FROM `tblDbsChecks` WHERE `userID` = ? ORDER BY `dbsCheckID`' => 'FROM `tblDbsChecks` WHERE `userID` = ? AND `status` <> \'expired\' ORDER BY `dbsCheckID`'], 'count disagrees'],
    'P13b the copy\'s "no such table" test widened to every failure, so a failed read hands over an empty list' => [
        '_core/GdprEraser.php', ['if ($failure->getCode() === 1146) {' => 'if (true) {'], 'failure answered as none'],
    'P13c My data\'s list skipping every failed read again, as before #1012 (any error taken as "no such table")' => [
        '_core/GdprEraser.php', ['return $problem instanceof \\mysqli_sql_exception && $problem->getCode() === 1146;' => 'return true;'], 'inventory hid failure'],
    'P13d My data\'s page drawing a failed read as an ordinary row (a count of 0)' => [
        '_apps/account/my-data.php', ["<?php if ((\$row['couldNotRead'] ?? false) === true): ?>" => '<?php if (false): ?>'], 'inventory hid failure'],
    'P13 the privacy policy\'s Article 15 sentence put back as it was before #1012' => [
        '_apps/privacy/policy.php', [' Both also show the records that carry your name because you made them, such as a pastoral visit, or a DBS check you recorded for somebody else. Those are usually about somebody else, so each is listed only by its kind, the day it was made and the organisation that holds it (a DBS check is not recorded against any one organisation, so none is given for it), and one held by an organisation you cannot open here is only counted.</li>' => '</li>'], 'policy wrong'],
];
foreach ($p1012Plants as $label => [$rel, $swaps, $expect]) {
    $path     = $plantFile($rel);
    $original = (string) file_get_contents($path);
    $applied  = true;
    foreach ($swaps as $from => $to) {
        $applied = $applied && substr_count($original, (string) $from) === 1;
    }
    $wrong = ['(not run)'];
    if ($applied === true) {
        file_put_contents($path, strtr($original, $swaps));
        try {
            $wrong = $p1012Observe();
        } finally {
            file_put_contents($path, $original);
        }
    }
    ok($label . ' - planted, the check goes red, and the file is put back (#1012)',
        $applied === true && in_array($expect, $wrong, true) === true && (string) file_get_contents($path) === $original,
        ($applied === true ? 'applied' : 'NOT APPLIED: the text to change was not found exactly once') . ' | observed: ' . implode('; ', $wrong));
}
$p1012Again = $p1012Observe();
ok('P14 with every plant taken out again, the same observations are all green (#1012)', $p1012Again === [], implode('; ', $p1012Again));
q('UPDATE `tblSites` SET `timezone` = ? WHERE `siteID` = ?', 'si', [$p1012ZoneWas, ORG_A]);

// The pin file as a whole, asked AFTER every rendered pin has been compared
// (#703 round 10: it sat after the help page's pin, before section K's, and so
// reported section K's pin as "compared by no check"; #1012 moved it again,
// to here after the last section, because section P compares a pin too).
ok('the rendered-pin file holds exactly the pins this test compares, each once, and nothing it does not understand (#703 round 7)',
    pinFileProblems() === [], implode(' | ', pinFileProblems()));

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
    . '+ (SELECT COUNT(*) FROM tblCountSessions WHERE siteID IN (?, ?)) '
    . '+ (SELECT COUNT(*) FROM tblDbsChecks WHERE userID BETWEEN 917000 AND 917999 OR recordedByID BETWEEN 917000 AND 917999) '
    . '+ (SELECT COUNT(*) FROM tblKidProfiles WHERE childID = ' . CHILD . ') '
    // Section O (#929).
    . '+ (SELECT COUNT(*) FROM tblCareVisit WHERE visitID BETWEEN 917230 AND 917239) '
    . '+ (SELECT COUNT(*) FROM tblCareCase WHERE caseID BETWEEN 917230 AND 917239) '
    . '+ (SELECT COUNT(*) FROM tblKidCheckins WHERE checkinID BETWEEN 917230 AND 917239) '
    . '+ (SELECT COUNT(*) FROM tblKidProfiles WHERE childID BETWEEN 917230 AND 917239) '
    . '+ (SELECT COUNT(*) FROM tblSites WHERE siteID = ' . ORG_C . ') '
    . '+ (SELECT COUNT(*) FROM tblOffboarding WHERE userID BETWEEN 917000 AND 917999) '
    // Section N (#964).
    . '+ (SELECT COUNT(*) FROM tblEvents WHERE siteID IN (' . ORG_A . ', ' . ORG_B . ') OR eventID BETWEEN 917300 AND 917399) '
    . '+ (SELECT COUNT(*) FROM tblMailQueue WHERE siteID IN (' . ORG_A . ', ' . ORG_B . ') OR queueID BETWEEN 917400 AND 917499) '
    . '+ (SELECT COUNT(*) FROM tblEmailLog WHERE emailLogID BETWEEN 917500 AND 917599) '
    // 🧑‍⚖️ Section J's own rows (#732). Counted here as well, because the links
    //    are switched off while the world is removed, so nothing is taken away
    //    by a parent going: each has to be deleted by name or it is left
    //    behind, and this is the check that would notice.
    . '+ (SELECT COUNT(*) FROM tblSalvationCards WHERE siteID IN (?, ?)) '
    . '+ (SELECT COUNT(*) FROM tblRoles WHERE siteID IN (?, ?)) '
    . '+ (SELECT COUNT(*) FROM tblUserRoles WHERE siteID IN (?, ?)) '
    . '+ (SELECT COUNT(*) FROM tblResourceBooking WHERE bookingID = ?) '
    . '+ (SELECT COUNT(*) FROM tblErasureReviewDecisions WHERE requestID IN (?, ?)) '
    . "+ (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'selftest\\_erasure\\_pages\\_stop\\_%') AS n",
    'iiiiiiiiiiiiiiiiiiiiiii',
    [ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B,
        ORG_A, ORG_B, ORG_A, ORG_B, ORG_A, ORG_B, BOOKING_J, REQ_PIP_A, REQ_PIP_B]
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
