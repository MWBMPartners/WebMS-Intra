<?php
// Path: tools/feed-admin-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test for the outside-calendar administrator pages' service class 🖥️🧪
 * -----------------------------------------------------------------------------
 * #514 part P8 gives every organisation's administrators pages to add an
 * outside calendar, choose who sees it, write a rule, and approve or
 * decline a date that is about to be shown more widely. Every one of those
 * pages does its actual work through ONE class, `Portal\Core\FeedAdmin`
 * (plus a small helper, `Portal\Core\ExternalAudience`, for "who may see
 * it" lists) — see that file's own header for why. This is that class's
 * own test, in the same spirit as `tools/feed-resolver-selftest.php`: a
 * small made-up world in a throwaway database, the REAL class called
 * directly, and the REAL rows checked afterwards.
 *
 * WHY IT IS ITS OWN FILE
 * ----------------------
 * `tools/feed-resolver-selftest.php` tests `FeedResolver` and says plainly
 * in its own header that it cannot prove anything about the administrator
 * pages. Part 7's decision D3 was "one file per class under test"; this
 * file is that rule followed for part P8.
 *
 * THREE PARTS
 * -----------
 * **Part S** (chunk P8-2) reads the page files themselves, as text: no page
 * under `web/_apps/admin/calendar/` or `web/_apps/approvals/` may call the
 * part 7 and list-writing methods that must only ever run inside
 * FeedAdmin's locked sequence (see part S below for the list and why).
 * **Part D** calls `FeedAdmin` and `ExternalAudience` directly against the
 * database — no web server, no session, no HTTP. **Part W** (chunk P8-2
 * onwards) drives the REAL pages through PHP's built-in web server, on a
 * scratch copy of `web/`, signed in for real with real form tokens, while
 * the database's own clock runs seven hours behind UTC — see "PART W" below
 * for exactly how it keeps that safe.
 *
 * ONE SECTION THAT IS NOT ABOUT PART 8: W59, the #534 table
 * ---------------------------------------------------------
 * #534 asked for a table of who is shown ONE event (six kinds of viewer by
 * five kinds of event) proved on the real pages: the event's page, the
 * single-event download and the two Team Hub data endpoints. Part W is the
 * portal's only harness that drives real pages, and already has the two
 * organisations and the accounts, so the table lives here as W59 rather
 * than in a second harness of its own (roll-out plan, decision 4, 11
 * October 2026). The expected answers are written out by hand inside W59,
 * and W59 carries its own control: it takes the rule out of one page of
 * the run's scratch copy and checks that the table would notice. W59 also
 * checks the one other page the #534 sweep found naming an internal event
 * to outsiders: the read-only card of a venue booking.
 *
 * PART 10 OF #514: ROLES, USER GROUPS AND DEPARTMENTS (13 October 2026)
 * -------------------------------------------------------------------
 * Part 10 lets a "who may see it" list name a role (#516), a user group or
 * a department (#517). That changed the rule (`EventVisibility`), its fast
 * copy (`FeedAdmin::whoCanSee()`), the list class and the picker, and this
 * file with them:
 *   - the made-up world gained eight accounts (RH, UH, DH, XF, XO, DN, XB
 *     and XI), two roles, three user groups and four departments — see the
 *     constants for what each is for;
 *   - after part 10's independent check it gained six more (KP, KR, KU, KD,
 *     KS and KL) and ONE NUMBER SHARED BY SIX KINDS: a role, a user group, a
 *     department, a small group and a leadership role of organisation A all
 *     carry the number SN, which is also KP's account number. The check had
 *     shown that with every number different, a rule or a copy that read a
 *     row of one kind as another kind's number passed every check here;
 *   - R5, the oracle (D17): the copy's answer equals the real rule's for
 *     every account, before and after each save, with the three kinds on
 *     lists — and in several places the answer is also WRITTEN OUT BY HAND,
 *     because a mistake made the same way in the rule and in the copy would
 *     agree with itself. Those places: the part 10 control; all three kinds
 *     at once; another organisation's three on a list; rows of the new kinds
 *     FILED UNDER another organisation; and R9, a date listed for each of the
 *     six kinds in turn, all naming the shared number;
 *   - R6, what may be saved (D3, D7): each kind for this organisation;
 *     another organisation's refused with the one sentence; a retired user
 *     group or switched-off department not freshly added, but kept;
 *   - R7 (D15): every kind the database can hold is one the forms offer;
 *   - R8 (D1, D8, W60): the real forms offer this organisation's and none of
 *     another's, a number of another's posted by hand saves nothing, and on
 *     the real calendar page the holder of a role is shown the event while a
 *     member who holds only ANOTHER organisation's role is not.
 * What these cannot show is said under "WHAT THIS CANNOT PROVE" below.
 *
 * `SELFTEST_ONLY=S`, `=D` or `=W` runs one part only, which is handy while
 * working on it. Such a run can NEVER pass: it always ends with exit 1 and
 * says so, because the parts it skipped proved nothing.
 *
 * PART W — HOW IT STAYS SAFE (the #514 part 8 plan, section 11.1)
 * ---------------------------------------------------------------
 * - Its scratch folder is a NEW child it makes itself, named
 *   `feed-admin-selftest-<process number>-<16 random hex characters>`, inside
 *   `SELFTEST_WEB_DIR` (or, when that is not set, the system's temporary
 *   folder). It refuses to start, having made nothing, when the repository
 *   is inside that folder or the folder is inside the repository. The first
 *   thing written into the child is a marker holding this run's random
 *   token; at the end the child is deleted ONLY if the marker still holds
 *   it, and nothing else is ever deleted (challenge H2). W0 then checks the
 *   folder holds exactly the entries it held before the run.
 *   On a busy computer, other programs create and delete files in the
 *   system's temporary folder all the time, and W0 would then fail for a
 *   reason that is not this test's. So point `SELFTEST_WEB_DIR` at a quiet
 *   folder of your own; a W0 failure caused by somebody else is a false
 *   FAIL, never a false PASS.
 * - The copy of `web/` leaves out `_auth_keys`, `_uploads`, `_backups` and
 *   `_libraries`. The copy's own `_auth_keys` holds a throwaway connection
 *   file (from the `SELFTEST_DB_*` variables) and a random encryption key —
 *   the real `web/_auth_keys` is never read or written.
 * - The web server is started by this file with its command as a LIST (no
 *   shell in between), so the process number it gets is the server's own,
 *   and it is stopped by that number — never by a name, because other
 *   projects on the same computer run PHP servers too. It refuses to start
 *   when anything already answers on its port or on the "closed" port next
 *   to it (a leftover server, or somebody else's). Every answer must carry a
 *   header with this run's token; one without it came from a server this
 *   run did not start, and fails W0 (challenge M7).
 * - Nothing reaches the network. Every calendar address in the made-up
 *   world ends in `.internal`, which `SafeFetch` refuses before any lookup.
 *   The one address that passes the check, `http://p8-selftest.test/…`, is
 *   pointed by the test's router at a CLOSED port on this computer, so the
 *   refresh that follows fails at once with "connection refused"
 *   (`SafeFetch::$testResolverOverride`, null in the running portal).
 * - The settings it changes (organisations told apart by the first part of
 *   the address) and the database's own time zone (set to -07:00, so a page
 *   still reading a server-zone value shows the wrong hour) are recorded
 *   first and put back at the end. The clean-up runs from `finally` AND from
 *   a shutdown function (which still runs after a fatal error or an exit()),
 *   and does nothing the second time. WHAT IT CANNOT SURVIVE: being killed
 *   outright (SIGKILL). Then nothing runs at all: the web server is left
 *   running, the scratch child stays, and the database's zone and the two
 *   multisite settings stay changed. The next run refuses (the port still
 *   answers, and a database zone of -07:00 is refused as a leftover), so the
 *   state is put back by hand — never silently adopted.
 * - Each account signs in through the real sign-in page with a password
 *   made up for this run (never printed), one cookie jar per account, kept
 *   inside the scratch child.

 * NO CHECK MAY PASS BY NOT RUNNING (fix round 1 of P8-1, checker H1)
 * -----------------------------------------------------------------------
 * The first version of this file had a check that always passed
 * (`pa_ok(..., true)`), a block that only ran "if a waiting row happened
 * to exist" (it never did), and a finish that asked only for 32 passes
 * out of 121. A later edit could have removed an organisation check and
 * this file would still have said PASS. So now:
 *   - every scenario builds its state through a REAL write path, and a
 *     set-up that does not produce the state it needs is itself a FAIL,
 *     never a quiet skip;
 *   - every refusal is checked by its EXACT sentence, not just "it said
 *     no";
 *   - `pa_finish()` fails unless every heading was reached (S1-S4, D1 to
 *     D39, and W0 to W60 — there is no W17: the plan's list goes from W16
 *     to W18, with "W8 also" folded into W8; D33 and W20 are the "Partly
 *     read" flag, added by chunk P8-2's follow-up; S2 and W21-W25 came with
 *     fix round 1; S3, S4, D34 and W26-W38 with chunk P8-3; D35, D36, W39
 *     and W40 with chunk P8-3's fix round 1; D37 and W41 with its fix round
 *     2; D38 and W42 with fix round 3; W43 with fix round 4; D39 and
 *     W44-W52 with chunk P8-4; W53 and W54 with its fix round 1; W55 and W56 after the final whole-part check; W57 and W58 after its last narrow check;
 *     W59, the #534 table, with roll-out package B; W60, roles, user groups
 *     and departments on the real form, with #514 part 10) AND exactly
 *     `EXPECTED_CHECKS`
 *     checks ran — fewer means something was skipped, more means something
 *     ran twice or was added without the number being updated. Either way
 *     the run fails and says which.
 *
 * TIME ZONES
 * ----------
 * Every "is this within five seconds of now" is asked of the DATABASE, in
 * SQL, against `UTC_TIMESTAMP()` — never PHP's clock read with
 * `strtotime()`, which would depend on PHP's own zone. The file is run in
 * three PHP zones as part of its proof (UTC, `-d date.timezone=
 * Pacific/Auckland`, `-d date.timezone=America/New_York`) and must pass
 * in all three. The DATABASE session itself runs thirteen hours ahead of
 * UTC for the whole run (`pa_connect()`; checked by D0), so a `NOW()` used
 * where `UTC_TIMESTAMP()` belongs is thirteen hours out — for the places a
 * check reads: D19 (the lock's clock), D39 (importedEvent()'s run-out flag,
 * and whoCanSee()'s "still in date" test, added after the final fault run
 * found that one unchecked — its m-X05), and part W's W8/W13 (the importer's
 * "last fetched", with the database server itself at -07:00). A NOW() in a
 * place no check reads would NOT be caught; that is what m-X05 was.
 *
 * TWO PROCESSES
 * -------------
 * A few checks need a lock held by somebody ELSE while this process is in
 * the middle of a save — as a refresh or a second administrator would. A
 * second connection in this process can hold a lock while a call waits for
 * it, but it cannot act in the MIDDLE of that call. For those checks this
 * file starts ITSELF again as a separate process (`--hold-lock`, see
 * `pa_withHolder()`): it takes the calendar's lock, waits, changes the
 * address or deletes the calendar, and commits. It is started with its
 * command as a list (no shell), waited for, and stopped by its own process
 * if it overstays — never by a name.
 *
 * SAFETY
 * ------
 * It writes to the database it is given, so it REFUSES unless the
 * database's name starts with `selftest_`. Every row it makes is numbered
 * 910000 or higher, or belongs to one of the two made-up organisations
 * (an approval row is numbered by the database itself, but always belongs
 * to a calendar numbered 910000+); the made-up world is removed at the
 * START of the run (in case an earlier run was interrupted) and again at
 * the END. It never reads anything under `web/_auth_keys` and never loads
 * `bootstrap.php`: the connection comes only from these environment
 * variables —
 *
 *   SELFTEST_DB_HOST (default 127.0.0.1)   SELFTEST_DB_PORT (default 3306)
 *   SELFTEST_DB_USER (default root)        SELFTEST_DB_PASS (default empty)
 *   SELFTEST_DB_NAME (required; must start with selftest_)
 *
 * A second connection is opened in a few checks, only to hold a
 * calendar's row lock the way a refresh in progress would. It is always
 * committed and closed again in a `finally`.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - Part D alone proves nothing a page does (the 403/404 answers, form
 *   tokens, addresses, redirects); part W does, for the pages built so far
 *   (chunk P8-2: the calendar list, the calendar form, the calendar's own
 *   page and the buttons' handler; chunk P8-3: the choice page, the rule
 *   page, the calendar page's rules section and per-date buttons, the
 *   event page's "Manage who sees this" link and the series page's
 *   refusal; chunk P8-4: the preview page, the decision handler, the
 *   Approvals inbox's section, the calendar page's waiting dates, the
 *   guide and the help pages, the API description, the API keys page and
 *   the upgrade notice).
 * - Part W cannot fill the calendar FORM's refusal box with outside text:
 *   none of that form's refusals quotes what was typed. S4 reads every
 *   message box's source instead (each must print through `$esc(`).
 * - W38 sends raw addresses to the TEST server's router, which imitates
 *   `.htaccess`; it proves the router's own fence and rules, not Apache's.
 *   A raw NUL byte cannot be sent at all (PHP's built-in server drops the
 *   connection before the router runs), so only its encoded form is.
 * - Part W runs PHP's built-in server behind a router that IMITATES
 *   `web/public_html/.htaccess`; real Apache is not run. Only one of the
 *   three ways organisations can be told apart is used (the first part of
 *   the address); the session and sub-domain modes are not proved (#502).
 *   Browsers running the pages' scripts, and screen readers, are not
 *   proved: the checks read the HTML, and the pages are built to work
 *   without their scripts.
 * - Part S reads the page files as text. It cannot see a forbidden call
 *   whose method name is built at run time from pieces, or one made through
 *   a helper that lives outside the two folders it reads.
 * - That PHP's time limit really runs out: it would take 90 seconds of
 *   processor time per check. D21 proves instead WHEN the limit is raised
 *   (and, through a counter FeedAdmin keeps for this, when it is NOT).
 * - A database connection lost part way through a bulk decision.
 * - W52's upgrade notice is shown by REPLAYING migration 206 on a database
 *   that already has it (its row in tblMigrations deleted for the run, then
 *   put back exactly). It proves the card's condition, count and wording on
 *   the real page; it does not prove an upgrade from an older release, whose
 *   206 run really adds the column (the migration harness does not either —
 *   it starts every phase from today's full_schema.sql).
 * - The message after a decision is drawn at the top of the page the
 *   browser returns to (the inbox, or the calendar's page), while the
 *   address jumps to the "#imports" or "#waiting" section. The checks read
 *   the message's words; they cannot see whether a person looking at a
 *   small screen notices it.
 * - Part 10's page check (W60) signs in ONE holder of a role and one member
 *   who holds only another organisation's role, user group and department.
 *   A user group's and a department's own members are not signed in: for
 *   them, "who is shown the event" rests on part D's oracle and on
 *   `tools/event-visibility-selftest.php`, which ask the rule directly.
 *   W60 reads the picker's HTML; it does not show how the six groups of
 *   boxes look on a phone, or that the new-tab links behave in a browser.
 * - How long `FeedAdmin::whoCanSee()` takes with roles, user groups and
 *   departments on a list. Nothing here times it (its docblock quotes one
 *   measurement made by hand when part 10 was built).
 * - Real Google or Microsoft 365 calendars (NOT PROVEN anywhere in #514 —
 *   the owner's decision of 21 September 2026).
 * - MariaDB is not tested. MySQL 8.0.36 and 8.4.11 have been used.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_p8 SELFTEST_DB_PORT=<a throwaway server's port> SELFTEST_WEB_DIR=/a/quiet/folder \
 *             php -d memory_limit=512M tools/feed-admin-selftest.php
 *         ⚠️ Part W needs a THROWAWAY DATABASE SERVER OF ITS OWN (for example
 *         `docker run --rm` of mysql:8.0.36), not just a throwaway database:
 *         it sets the whole server's time zone while it runs, so it refuses
 *         a server that holds any database other than MySQL's own and
 *         `selftest_…` ones (fix round 1, M3). Do not point it at a shared
 *         development server on the default port 3306.
 *         Part W also reads SELFTEST_WEB_PORT (default 9058; the port after
 *         it must be free too), and needs PHP's curl, dom and sodium
 *         extensions, `proc_open()`, and a database account that may run
 *         `SET GLOBAL time_zone` (it refuses, rather than skip, if not).
 * Exit:   0 only when every heading ran, exactly EXPECTED_CHECKS checks
 *         ran, and every one passed; 1 otherwise — including every refusal
 *         to run and every one-part run, because a check that did not run
 *         has proved nothing.
 *
 * The database this is given must already hold the portal's tables: load
 * `web/_sql/full_schema.sql` into it first (nothing else is needed).
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/534
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🧯 Anything thrown outside the checks becomes a plain FAIL line and exit
//    1, not a stack trace — registered before any class file loads, since a
//    syntax error in one of them is thrown by the `require` below.
set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    exit(1);
});

// =============================================================================
// 🛑 Refuse anything but a throwaway database — BEFORE any class is loaded
// =============================================================================
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test writes rows, so it only ever runs on a throwaway database. Nothing was checked.\n";
    exit(1);
}

// Classes, one by one, never through `bootstrap.php` — the exact list and
// order the settled plan names (section 11.1): App, and since #604 Site
// (FeedResolver's organisationZone() asks Site for the organisation's zone,
// and Site reaches the database through App), ErrorMonitor, Logger,
// SafeFetch, ApiKey, EventVisibility, FeedResolver, FeedImporter (not
// called directly by FeedAdmin, but part P8-2 will change it, and loading
// it here now costs nothing), ExternalAudience, FeedAdmin.
// Part 10 of #514 adds Roles, UserGroups and Departments: ExternalAudience
// and FeedAdmin::whoCanSee() now ask them who holds a role and who is in a
// user group or department. (EventVisibility loads the three itself when
// nothing else has — two other self-tests load it from a list like this one
// that does not name them — but ExternalAudience and FeedAdmin do not, so
// they are named here.) None of the three runs anything when loaded.
$coreDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
foreach (['App.php', 'Site.php', 'ErrorMonitor.php', 'Logger.php', 'SafeFetch.php', 'ApiKey.php', 'Roles.php', 'UserGroups.php', 'Departments.php',
    'EventVisibility.php', 'FeedResolver.php', 'FeedImporter.php', 'ExternalAudience.php', 'FeedAdmin.php'] as $classFile) {
    require_once $coreDir . $classFile;
}

use Portal\Core\EventVisibility;
use Portal\Core\ExternalAudience;
use Portal\Core\FeedAdmin;
use Portal\Core\FeedResolver;
use Portal\Core\SafeFetch;
use Portal\Core\Site;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// `$mysqli` is a global on purpose: `App::db()` falls back to it, which is
// how `Site` reaches the organisation's row for `FeedResolver::organisationZone()`.
$mysqli = pa_connect();

// 🕰️ Tell `Site` where this database keeps an organisation's time zone, as the
//    portal's own start-up does (#604). Start-up hands Site the settings it has
//    just loaded, and Site looks in them for the mark migration 217 leaves:
//    with it, an organisation's zone is read from its own row
//    (`tblSites.timezone`); without it, from the old `site.timezone` setting.
//    This file never loads start-up, so it does that one step itself, from the
//    mark as the DATABASE holds it. It is run again after a check changes an
//    organisation's zone, as a new request would be: Site remembers a zone it
//    has already looked up for the length of one request.
function pa_startSite(): void
{
    global $mysqli;
    $mark = $mysqli->query("SELECT settingValue FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt' AND siteID IS NULL")->fetch_row();
    Site::init($mysqli, ['site' => ['timezoneKeptAt' => (string) ($mark[0] ?? '')]], 1);
}
pa_startSite();
if (Site::timezoneFacts()['inRow'] === false) {
    echo "REFUSED — this database does not hold migration 217's mark, so an organisation's zone would be read from the old setting. Build it from web/_sql/full_schema.sql. Nothing was checked.\n";
    exit(1);
}

// 🔒 A second PROCESS that holds one calendar's row lock, the way a refresh
//    in progress does, then does one thing and commits (fix round 2,
//    checker round 2 LOW 8 — the checker's probes/races.php showed two
//    branches the builder had called untestable CAN be run this way). This
//    same file, started again by pa_withHolder() with `--hold-lock`; it
//    never builds or removes the made-up world, and only ever touches a
//    calendar numbered 910000-919999 of the throwaway database the parent
//    was given (the refusal above has already run for it too).
if (($argv[1] ?? '') === '--hold-lock') {
    pa_holdLockChild($mysqli, $argv);
    exit(0);
}

// =============================================================================
// 🔢 How many checks this file runs — EXACTLY (see "No check may pass by not
//    running" in the header). Change them only together with a check.
// =============================================================================
const EXPECTED_S      = 11;   // part S: S1 pages never write around the service layer (4); S2 the importer's UTC writes (2, fix round 1); S3 the server filter (1, chunk P8-3, + its call site 2, P8-3 fix round 1) and S4 message boxes (2, chunk P8-3)
const EXPECTED_D      = 609;  // part D: 500 from chunk P8-1, + D25's R13-L1 case and D33's three (chunk P8-2), + D34 (chunk P8-3), + D35 (2) and D36 (2) (P8-3 fix round 1), + D37 (3) (fix round 2), + D38 (fix round 3), + D39 (6, chunk P8-4; + 2 in its fix round 1; + 1 in fix round 2; + 1 after the final fault run, m-X05; + 3 after the final whole-part check, F1 and F3); #514 part 10 (roles, user groups and departments, 13 October 2026) adds 71: D1 (1), D3 (3), D7 (16), D8 (5), D15 (1) and D17 (45: its second control, and the R5 oracle cases); the fixes after part 10's independent check add 12 more to D17: six for rows of the new kinds filed under another organisation, and six for "one number, six kinds" (R9)
const EXPECTED_W      = 314;  // part W: 81 for chunk P8-2's pages (W0-W16, W18-W25); chunk P8-3 grows W21 by 6 and adds W26-W38 (82); its fix round 1 adds a W31 check, W39 (4) and W40 (4); fix round 2 adds W41 (5); fix round 3 adds W42 (3); fix round 4 grows W21 by 2 and adds W43 (1); chunk P8-4 grows W21 by 5 and adds W44 (5), W45 (6), W46 (5), W47 (2), W48 (5), W49 (5), W50 (4), W51 (2) and W52 (5); its fix round 1 adds 2 to W45, 3 to W46, 3 to W48, 1 to W49, 1 to W50, 1 to W52, and W53 (2) and W54 (1); fix round 2 adds 2 to W45, 1 to W48 and 1 to W53; fix round 3 adds 1 to W45; the final whole-part check's fixes add 1 to W50, and W55 (2) and W56 (1); the last narrow check's fixes add 1 to W55, and W57 (4) and W58 (1); W59, the #534 table (roll-out package B, 11 October 2026), adds 32: its set-up (1), six viewers by four addresses (24), its own control (2), an API key answered as before (2, added after the independent check) and the venue booking card (3); roll-out package B2a (12 October 2026) adds a seventh W59 viewer, on a crew (4), and changes the Team Hub and venue card rows it made stricter; roll-out package F2 adds 5 "W59 at the door" checks (the members-only gate put back); #514 part 10 adds W60 (9); #514 part 11 adds 2 to W50 (the guide's "told" section, and the approvals help's two switches)
const EXPECTED_CHECKS = EXPECTED_S + EXPECTED_D + EXPECTED_W;

// 🧩 One part only (SELFTEST_ONLY=S, D or W), or all of them. A one-part run
//    can never pass — pa_finish() says so and exits 1 — because the parts it
//    skipped proved nothing. Anything else in the variable is refused.
$onlyPartRaw = strtoupper(trim((string) getenv('SELFTEST_ONLY')));
if ($onlyPartRaw !== '' && in_array($onlyPartRaw, ['S', 'D', 'W'], true) === false) {
    echo "REFUSED — SELFTEST_ONLY must be S, D or W (or not set at all); got '{$onlyPartRaw}'. Nothing was checked.\n";
    exit(1);
}
define('PA_ONLY', $onlyPartRaw);

/** Does this run include part `$part` ('S', 'D' or 'W')? */
function pa_wants(string $part): bool
{
    return PA_ONLY === '' || PA_ONLY === $part;
}

// =============================================================================
// 🗺️ The made-up world (every number 910000 or higher)
// =============================================================================
const ORG_A = 910001; // Europe/London
const ORG_B = 910002; // America/Santiago
const M   = 910011;   // an ordinary member of A, not an administrator
const SA  = 910012;   // a site administrator of A
const SB  = 910013;   // a site administrator of B
const GA  = 910014;   // a global administrator (isRootAdmin), no membership row anywhere
const LA  = 910015;   // the OLD portal-wide isAdmin flag only; a member of A, not a site administrator
const P_ID = 910016;  // a member of A, used on "who may see it" lists
const SGM = 910017;   // a member of A AND of small group SG1
const PB  = 910018;   // a member of B only — with an old RA1 term in A never tidied away (fix round 4 part B)
// 👥 More kinds of viewer (fix round 4, check round 4 M2). The seven-viewer
//    panel D17 used before could not see a leadership-role holder or a
//    member of any group but SG1, so a count that ignored roles or keyed
//    every group alike passed. D17's oracle now asks the real rule about
//    EVERY account below, so each kind of person the rule treats
//    differently must exist here — or the oracle is blind to it again.
const L_ID = 910019;  // a member of A who holds leadership role RA1 now
const X_ID = 910020;  // a member of A in small group SG3 (and in no other)
const E_ID = 910021;  // a FORMER member of A (membership ended), now an active member of B, whose old SG1 row and RA1 term in A were never tidied away
const Q_ID = 910022;  // a member of A who LEFT SG1 (status 'ended') and whose RA1 term ended 30 days ago
const O_ID = 910023;  // a member of A in the switched-off group SG2 only
const F_ID = 910024;  // a member of A whose RA1 term starts in 30 days, with a join request to SG3 not yet approved
const I_ID = 910025;  // a SWITCHED-OFF account (tblUsers.isActive = 0) still holding an active membership of A and the only term of RA2 (fix round 4 part B)
// 👥 Fix round 5 (check round 5, M1): the kinds of person the round 4 world
//    lacked, each with a role of their own so each can be the ONLY one who
//    moves in a D17 case.
const H_ID  = 910026; // a member of A holding a current term of RA3 — a role that is SWITCHED OFF (leadership/manage/save.php can do that)
const R_ID  = 910027; // a member of A whose RA6 term was REMOVED the way leadership/delete.php leaves it: isActive = 0, end date still empty
const TS_ID = 910028; // a member of A whose RA4 term STARTS TODAY
const TY_ID = 910029; // a member of A whose RA4 term ENDED YESTERDAY (the control beside TS)
const TE_ID = 910030; // a member of A whose RA5 term ENDS TODAY
const TT_ID = 910031; // a member of A whose RA5 term STARTS TOMORROW (the control beside TE)
// 👥 Fix round 6 (check round 6, L2): small-group LEADERS. The Small Groups
//    app writes tblSmallGroupMembers.memberRole as 'leader', 'co-leader' or
//    'member' (SmallGroups.php, e.g. its isLeader() test); the round 5 world
//    held only 'member' rows, so a copy that forgot leaders, or a rule
//    change about them, passed.
const LD_ID = 910032; // a member of A who is the LEADER of SG5 — SG5's only row
const CL_ID = 910033; // a member of A who is the CO-LEADER of SG6 — SG6's only row
const LO_ID = 910034; // a member of A who is the LEADER of the switched-off group SG2 (and in no other group)
const CO_ID = 910035; // a member of A who is a CO-LEADER of the switched-off group SG2 (and in no other group) — fix round 7, check round 7 L3
// 👥 Part 10 of #514 (13 October 2026): a list can now name a ROLE (#516), a
//    USER GROUP or a DEPARTMENT (#517). Each kind of person the rule treats
//    differently through those three must exist here, or D17's oracle is
//    blind to it — exactly the lesson of fix round 4 above. Nobody below is
//    in any small group or holds any leadership role, so each moves only
//    when a list names one of the three new kinds.
const RH_ID = 910036; // a member of A who holds role RO1
const UH_ID = 910037; // a member of A in user group UG1 (in use)
const DH_ID = 910038; // a member of A in department DP1 (switched on)
const XF_ID = 910039; // a FORMER member of A (membership row switched off) who still holds RO1 and is still in UG1 and DP1 — never tidied away
const XO_ID = 910040; // a member of A in the RETIRED user group UG2 and the SWITCHED-OFF department DP2, and nothing else
const DN_ID = 910041; // a member of A in department DP3 only, whose on/off flag is EMPTY (the column allows it; it reads as switched off)
const XB_ID = 910042; // an active member of BOTH A and B who holds B's role ROB and is in B's user group UGB and department DPB — and nothing of A's
const XI_ID = 910043; // a SWITCHED-OFF account (tblUsers.isActive = 0) with an active membership of A, still holding RO1 and in UG1 and DP1
const RO1 = 910131;   // role of A (roles have no on/off switch)
const ROB = 910132;   // role of B
const UG1 = 910141;   // user group of A, in use
const UG2 = 910142;   // user group of A, RETIRED (isActive = 0)
const UGB = 910143;   // user group of B, in use
const DP1 = 910151;   // department of A, switched on
const DP2 = 910152;   // department of A, switched OFF
const DP3 = 910153;   // department of A whose on/off flag is EMPTY
const DPB = 910154;   // department of B, switched on
// 🔢 ONE NUMBER, SIX KINDS (added after part 10's independent check, 13
//    October 2026). A list row names something in TWO parts, a kind and a
//    number, and six tables count their rows separately: on a real
//    installation the same number is a role, a user group, a department, a
//    small group, a leadership role AND somebody's account all at once (a
//    fresh install already holds roles 1 to 14). Until this was added every
//    number in this world was different, so a rule or a copy that read a row
//    of one kind as another kind's number passed all 920 checks — the check
//    planted exactly that, three ways, and it did. So organisation A now has
//    one of EACH carrying the number SN, each with one person of its own who
//    is in nothing else, and D17 lists an event for each kind in turn.
const SN    = 910044; // THE SHARED NUMBER. It is KP's account number, and the number of a role, a user group, a department, a small group and a leadership role of A
const KP_ID = 910044; // a member of A whose own ACCOUNT NUMBER is the shared number; in no group, holds nothing
const KR_ID = 910045; // a member of A who holds the ROLE numbered SN, and nothing else
const KU_ID = 910046; // a member of A in the USER GROUP numbered SN only
const KD_ID = 910047; // a member of A in the DEPARTMENT numbered SN only
const KS_ID = 910048; // a member of A in the SMALL GROUP numbered SN only
const KL_ID = 910049; // a member of A who holds the LEADERSHIP ROLE numbered SN (no dates, so it is current), and nothing else
const SG1 = 910101;   // small group of A, switched on
const SG2 = 910102;   // small group of A, switched OFF
const SGB = 910103;   // small group of B
const SG3 = 910104;   // a second small group of A, switched on (X is in it)
const SG4 = 910105;   // a small group of A, switched on, whose ONLY rows are an ENDED membership (Q) and a PENDING join request (F) — so it lets nobody in
const SG5 = 910106;   // a small group of A, switched on, whose only row is its LEADER (LD)
const SG6 = 910107;   // a small group of A, switched on, whose only row is its CO-LEADER (CL)
const RA1 = 910111;   // leadership role of A
const RB1 = 910112;   // leadership role of B
const RA2 = 910113;   // a leadership role of A held ONLY by the switched-off account I — so it lets nobody in
const RA3 = 910114;   // a leadership role of A that is SWITCHED OFF, with one current term (H)
const RA4 = 910115;   // a leadership role of A: TS's term starts today, TY's ended yesterday
const RA5 = 910116;   // a leadership role of A: TE's term ends today, TT's starts tomorrow
const RA6 = 910117;   // a leadership role of A whose only term (R) was removed the leadership/delete.php way
const CA1 = 910121;   // event category of A
const CA2 = 910122;   // event category of A
const CB1 = 910123;   // event category of B
const FA1 = 910201;   // calendar of A, Members
const FA2 = 910202;   // calendar of A, Public
const FB1 = 910203;   // calendar of B, Public
const SITE_ZONE   = 'Europe/London';
const SITE_ZONE_B = 'America/Santiago';

// 🏷️ Every name organisation B owns carries this word, so "does A's answer
//    contain anything of B's?" is a plain text search of the answer — the
//    distinctive-content test D1 asks for, not only a count.
const B_MARK = 'BONLY';

// 💬 The sentences the class must answer with, word for word.
const MSG_ACTOR    = 'Only an administrator of this organisation can change this.';
const MSG_NF_CAL   = 'That calendar could not be found.';
const MSG_NF_EVENT = 'That event could not be found.';
const MSG_NF_RULE  = 'That rule could not be found.';
const MSG_PRIVATE  = 'This event is marked private in the outside calendar. Showing it is your decision, not a rule\'s.';
// decide()'s one sentence about a repeating event's other dates — THE
// OWNER'S DECISION of 28 September 2026 (the plan's top section, item 10),
// which replaced every "N other dates … followed" count. Word for word; it
// follows every other sentence of the message, and appears only when a date
// of a repeating event was really decided ("events" for more than one).
const MSG_OTHER_ONE  = ' Other dates of the same repeating event may follow this decision. The list of waiting dates shows where each one now stands.';
const MSG_OTHER_MANY = ' Other dates of the same repeating events may follow this decision. The list of waiting dates shows where each one now stands.';
// removeChoice()'s sentence, said the way the resolver decides (fix round 1
// of chunk P8-3, check finding L2): a date's own choice, then the choice for
// every date, then the rules, then the calendar — each choice only while it
// is in force. The first wording ("These dates go back to the rules and the
// calendar's own setting") was untrue for one date under a choice for every
// date. Three cases: every date; one date when a choice for every date
// EXISTS for its identity; one date when none does. (Fix round 1 chose the
// last two by whether the date repeats; fix round 2, check finding F1, by
// whether a choice for every date exists — one can be left over after the
// event stops repeating, and the resolver still applies it.)
const MSG_REMOVED_SERIES           = "Choice removed. Each date now follows its own choice while one is in force, otherwise the rules and the calendar's own setting.";
const MSG_REMOVED_DATE_UNDER_EVERY = "Choice removed. This date now follows the choice for every date while one is in force, otherwise the rules and the calendar's own setting.";
const MSG_REMOVED_DATE_ALONE       = "Choice removed. This date now follows the rules and the calendar's own setting.";

$GLOBALS['pa_pass']     = 0;
$GLOBALS['pa_fail']     = 0;
$GLOBALS['pa_skip']     = 0;
/** @var list<string> What could NOT be proved on this server, named in the last line. */
$GLOBALS['pa_notProven'] = [];
$GLOBALS['pa_headings'] = [];
// Which part's heading was printed last ('S', 'D' or 'W'), and each part's
// own pass and fail counts, so a run can say how each part did.
$GLOBALS['pa_part']     = 'D';
$GLOBALS['pa_byPart']   = ['S' => [0, 0, 0], 'D' => [0, 0, 0], 'W' => [0, 0, 0]];
$GLOBALS['pa_nextId']   = ['event' => 910300, 'choice' => 910500, 'rule' => 910600, 'cond' => 910700, 'feed' => 910210];

// =============================================================================
// 🛠️ Small helpers (the same shape as tools/feed-resolver-selftest.php's)
// =============================================================================

function pa_connect(): mysqli
{
    $db = new mysqli(
        getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
        getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
        getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
        (string) getenv('SELFTEST_DB_NAME'),
        getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
    );
    $db->set_charset('utf8mb4');
    // ⏰ The DATABASE session runs thirteen hours ahead of UTC, for the
    //    whole run (fix round 2, checker round 2 LOW 4). Every moment this
    //    class writes or compares must use UTC_TIMESTAMP(); a NOW() slipped
    //    in instead would then be thirteen hours out and fail a check — on a
    //    session left at UTC the two are identical, and the first version
    //    of this file could not tell them apart. (No column of these tables
    //    is of MySQL's TIMESTAMP type, which the session zone would also
    //    shift; every one is DATETIME.) D0 proves the setting took.
    $db->query("SET time_zone = '+13:00'");

    return $db;
}

/**
 * ⏭️ A check that could NOT be made on THIS server. Not a pass, not a failure.
 *
 * It counts towards the part's total, so `EXPECTED_D` and friends stay right
 * whichever engine the run is against — the alternative was two sets of
 * expected numbers, which would rot.
 *
 * WHY THIS EXISTS AT ALL. This file had only pass and fail until 28 October
 * 2026, which was fine while MySQL was the only engine it ever saw. Running it
 * against MariaDB 11.4 for #487 found one check that cannot be made there:
 * `performance_schema` is OFF by default on MariaDB, and MariaDB has no
 * `PS_CURRENT_THREAD_ID()` at all. Forcing that check to pass would have been
 * a lie, and failing it would have said the PORTAL was broken when it is the
 * TEST's instrument that is missing — the portal itself queries
 * `performance_schema` nowhere.
 *
 * A SKIP IS NOT A PASS. The run still ends with a loud line naming everything
 * that was skipped, and the reason is printed beside each one.
 */
function pa_skipped(string $label, string $why): void
{
    $part = $GLOBALS['pa_part'];
    $GLOBALS['pa_skip']++;
    $GLOBALS['pa_byPart'][$part][2]++;
    $GLOBALS['pa_notProven'][] = $label;
    echo 'SKIPPED — ' . $label . "\n        " . $why . " THIS IS NOT A PASS.\n";
}

function pa_ok(string $label, bool $passed, string $detail = ''): void
{
    $part = $GLOBALS['pa_part'];
    if ($passed === true) {
        $GLOBALS['pa_pass']++;
        $GLOBALS['pa_byPart'][$part][0]++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $GLOBALS['pa_fail']++;
    $GLOBALS['pa_byPart'][$part][1]++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

/**
 * A heading. "S1 — …", "Dn — …" and "Wn — …" headings are recorded as
 * reached (pa_finish() needs every one of them), and the letter decides
 * which part the checks after it are counted under.
 */
function pa_heading(string $text): void
{
    if (preg_match('/^([SDW])(\d+) /', $text, $m) === 1) {
        $GLOBALS['pa_headings'][$m[1] . (int) $m[2]] = true;
        $GLOBALS['pa_part'] = $m[1];
    }
    echo "\n=== " . $text . " ===\n";
}

/**
 * Every heading a run of the given parts must reach. There is no W17: the
 * plan's list (section 11.3) goes from W16 to W18, and its "W8 also" line is
 * checked inside W8.
 *
 * @return list<string>
 */
function pa_requiredHeadings(): array
{
    $want = [];
    if (pa_wants('S') === true) {
        $want[] = 'S1';
        $want[] = 'S2';
        $want[] = 'S3';
        $want[] = 'S4';
    }
    if (pa_wants('D') === true) {
        for ($d = 1; $d <= 39; $d++) {
            $want[] = 'D' . $d;
        }
    }
    if (pa_wants('W') === true) {
        // W26-W38 came with chunk P8-3; W39 and W40 with its fix round 1; W41 with fix round 2; W42 with fix round 3; W43 with fix round 4;
        // W44-W52 with chunk P8-4; W53 and W54 with its fix round 1; W55 and W56 after the final whole-part check; W57 and W58 after its last narrow check.
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43,
            44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58,
            // W59 is the #534 table (roll-out package B, 11 October 2026), not a part 8 check: see its heading.
            59,
            // W60 is #514 part 10: roles, user groups and departments on the real form (13 October 2026).
            60] as $w) {
            $want[] = 'W' . $w;
        }
    }

    return $want;
}

/** @param list<mixed> $params @return list<array<string,mixed>> */
function pa_q(string $sql, string $types = '', array $params = []): array
{
    global $mysqli;
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = ($result === false) ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** @param list<mixed> $params */
function pa_one(string $sql, string $types = '', array $params = []): ?array
{
    $rows = pa_q($sql, $types, $params);

    return $rows === [] ? null : $rows[0];
}

/**
 * The end: exit 0 only when every part ran, every heading was reached,
 * exactly EXPECTED_CHECKS checks ran, and none failed. A one-part run
 * (SELFTEST_ONLY) always ends with exit 1, after saying how its part did.
 */
function pa_finish(): never
{
    $ran = $GLOBALS['pa_pass'] + $GLOBALS['pa_fail'] + $GLOBALS['pa_skip'];
    echo "\n" . $GLOBALS['pa_pass'] . ' passed, ' . $GLOBALS['pa_fail'] . ' failed, '
        . $GLOBALS['pa_skip'] . " skipped.\n";
    $expected = ['S' => EXPECTED_S, 'D' => EXPECTED_D, 'W' => EXPECTED_W];
    foreach (['S', 'D', 'W'] as $part) {
        if (pa_wants($part) === true) {
            [$p, $f, $k] = $GLOBALS['pa_byPart'][$part];
            echo "Part {$part}: {$p} passed, {$f} failed, {$k} skipped, " . ($p + $f + $k) . ' ran of ' . $expected[$part] . " expected.\n";
        } else {
            echo "Part {$part}: not run (SELFTEST_ONLY=" . PA_ONLY . ").\n";
        }
    }
    $bad     = false;
    $missing = [];
    foreach (pa_requiredHeadings() as $heading) {
        if (isset($GLOBALS['pa_headings'][$heading]) === false) {
            $missing[] = $heading;
        }
    }
    if ($missing !== []) {
        echo 'FAIL — these parts never ran: ' . implode(', ', $missing) . " — a skipped block never reads as a pass.\n";
        $bad = true;
    }
    if (PA_ONLY !== '') {
        [$p, $f, $k] = $GLOBALS['pa_byPart'][PA_ONLY];
        if (($p + $f + $k) !== $expected[PA_ONLY]) {
            echo 'FAIL — part ' . PA_ONLY . ' ran ' . ($p + $f + $k) . ' checks, but this file expects exactly ' . $expected[PA_ONLY] . ".\n";
        }
        echo 'FAIL — only part ' . PA_ONLY . ' was asked for (SELFTEST_ONLY). A run that skips parts can never pass, so this exits 1.' . "\n";
        exit(1);
    }
    if ($ran !== EXPECTED_CHECKS) {
        echo 'FAIL — ' . $ran . ' checks ran, but this file expects exactly ' . EXPECTED_CHECKS
            . '. If a check was added or removed on purpose, update EXPECTED_S, EXPECTED_D or EXPECTED_W to match; otherwise a block was skipped or ran twice.' . "\n";
        $bad = true;
    }
    if ($GLOBALS['pa_fail'] > 0) {
        echo 'FAIL — ' . $GLOBALS['pa_fail'] . " check(s) failed.\n";
        $bad = true;
    }
    if ($bad === true) {
        exit(1);
    }
    if ($GLOBALS['pa_skip'] > 0) {
        echo 'PASS, BUT ' . $GLOBALS['pa_skip'] . ' CHECK(S) COULD NOT BE MADE ON THIS SERVER AND ARE NOT PROVEN: '
            . implode('; ', $GLOBALS['pa_notProven']) . ".\n";
        exit(0);
    }
    echo 'PASS — all ' . $GLOBALS['pa_pass'] . " checks passed; every heading (S1-S4, D1-D39, W0-W60) ran.\n";
    exit(0);
}

function pa_zone(string $name = SITE_ZONE): DateTimeZone
{
    return new DateTimeZone($name);
}

function pa_todayLocal(string $zone = SITE_ZONE): DateTimeImmutable
{
    return new DateTimeImmutable('now', pa_zone($zone));
}

/**
 * True when the stored UTC moment `$column` of the ONE row `$sql` finds is
 * within `$seconds` of the database's own now — asked of the database, so
 * PHP's zone cannot change the answer.
 *
 * @param list<mixed> $params
 */
function pa_nearDbNow(string $table, string $column, string $where, string $types, array $params, int $seconds = 5): bool
{
    $row = pa_one("SELECT ABS(TIMESTAMPDIFF(SECOND, {$column}, UTC_TIMESTAMP())) AS d FROM {$table} WHERE {$where}", $types, $params);

    return $row !== null && $row['d'] !== null && (int) $row['d'] <= $seconds;
}

/** Does `$value` (any shape) contain `$needle` anywhere, as text? */
function pa_has(mixed $value, string $needle): bool
{
    return str_contains(serialize($value), $needle);
}

/** The prepared-statement and SELECT counters of the test's own connection. */
function pa_counters(): array
{
    $out = ['exec' => 0, 'select' => 0];
    foreach (pa_q("SHOW SESSION STATUS WHERE Variable_name IN ('Com_stmt_execute','Com_select')") as $r) {
        $out[$r['Variable_name'] === 'Com_select' ? 'select' : 'exec'] = (int) $r['Value'];
    }

    return $out;
}

/**
 * How many statements `$call` sent, less what reading the counters itself
 * costs (measured, never assumed).
 *
 * @return array{exec:int, select:int}
 */
function pa_statementsOf(callable $call): array
{
    $c1 = pa_counters();
    $c2 = pa_counters();
    $overheadExec   = $c2['exec'] - $c1['exec'];
    $overheadSelect = $c2['select'] - $c1['select'];
    $before = pa_counters();
    $call();
    $after = pa_counters();

    return [
        'exec'   => ($after['exec'] - $before['exec']) - $overheadExec,
        'select' => ($after['select'] - $before['select']) - $overheadSelect,
    ];
}

// =============================================================================
// 🧹 Building and removing the made-up world
// =============================================================================

function pa_removeWorld(): void
{
    pa_q('DELETE FROM tblExternalEventApprovals WHERE feedID BETWEEN 910000 AND 919999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE c FROM tblExternalRuleConditions c JOIN tblExternalFeedRules r ON r.ruleID = c.ruleID WHERE r.feedID BETWEEN 910000 AND 919999 OR r.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblExternalFeedRules WHERE feedID BETWEEN 910000 AND 919999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblExternalEventChoices WHERE feedID BETWEEN 910000 AND 919999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblExternalAudienceMembers WHERE feedID BETWEEN 910000 AND 919999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE t FROM tblExternalEventTags t JOIN tblEvents e ON e.eventID = t.eventID WHERE e.eventID BETWEEN 910000 AND 919999 OR e.siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblExternalCategoryMap WHERE feedID BETWEEN 910000 AND 919999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // RSVPs have no link to the event that would remove them with it; part
    // W's delete check makes one (chunk P8-2).
    pa_q('DELETE FROM tblEventRSVPs WHERE eventID BETWEEN 910000 AND 919999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // W59 (the #534 table) makes one hall with three bookings. None of these
    // rows is deleted with its organisation (and a booking is not deleted
    // with its event), so they go here, before the organisations do.
    pa_q('DELETE FROM tblVenueBookings WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblVenueUsageTypes WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblVenueStatuses WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblVenues WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblEvents WHERE eventID BETWEEN 910000 AND 919999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // Part W's series-page check (W35, chunk P8-3) makes three series. Their
    // link to the organisation has no "delete with it", so they go before
    // the organisations do, and after the events that pointed at them.
    pa_q('DELETE FROM tblEventSeries WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblExternalFeeds WHERE feedID BETWEEN 910000 AND 919999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblSmallGroupMembers WHERE groupID BETWEEN 910000 AND 919999');
    pa_q('DELETE FROM tblSmallGroups WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // Before the roles they point at (fix round 4: the world now holds terms).
    pa_q('DELETE FROM tblLeadershipAssignments WHERE siteID IN (?, ?) OR userID BETWEEN 910000 AND 910099', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblLeadershipRoles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblEventCategories WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblApiKeys WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // …and any line of the two made-up organisations that names nobody (the
    // part 8 pages wrote such lines until chunk P8-4's fix round 1).
    pa_q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 910000 AND 910099 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // Part 10 of #514: who holds a role or is in a user group or department,
    // then the roles, user groups and departments themselves. The holdings
    // would go with the membership rows below; the DEPARTMENTS would not go
    // with their organisation (that link has no "delete with it"), and one
    // left behind would stop the organisation being removed at all.
    pa_q('DELETE FROM tblUserRoles WHERE siteID IN (?, ?) OR userID BETWEEN 910000 AND 910099', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblUserGroups WHERE siteID IN (?, ?) OR userID BETWEEN 910000 AND 910099', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblUserDepts WHERE siteID IN (?, ?) OR userID BETWEEN 910000 AND 910099', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblRoles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblGroups WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblDepts WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblUserSites WHERE siteID IN (?, ?) OR userID BETWEEN 910000 AND 910099', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblUsers WHERE userID BETWEEN 910000 AND 910099');
    // Every setting of the two made-up organisations (part W's
    // calendar.enabled) — no real organisation has these numbers.
    pa_q('DELETE FROM tblSettings WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    pa_q('DELETE FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
}

/**
 * A fresh world: two organisations, thirty-nine accounts, eight small groups,
 * eight leadership roles (with twelve terms), three roles, four user groups,
 * five departments (part 10 of #514; one of each kind shares one number, see
 * the constant SN), three categories, three
 * calendars (no events, choices,
 * rules or approvals yet — each check adds its own). Called at the START
 * of EVERY D check, so no check depends on another's leftovers or on the
 * order they run in. Everything organisation B owns is named with B_MARK.
 */
function pa_resetWorld(): void
{
    pa_removeWorld();
    // Each organisation's zone goes in its OWN ROW, the one place it is kept
    // since #604. (It used to be given here as a `site.timezone` setting.)
    pa_q(
        "INSERT INTO tblSites (siteID, siteKey, siteName, timezone, isActive) VALUES (?, 'selftest-p8-a', 'Selftest P8 A', ?, 1), (?, 'selftest-p8-b', 'Selftest P8 B BONLY', ?, 1)",
        'isis', [ORG_A, SITE_ZONE, ORG_B, SITE_ZONE_B]
    );
    pa_startSite();
    pa_q(
        'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES '
        . "(?, 'Selftest M', 'm.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest SA', 'sa.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest SB BONLY', 'sb.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest GA', 'ga.p8@selftest.invalid', 1, 0, 1), "
        . "(?, 'Selftest LA', 'la.p8@selftest.invalid', 1, 1, 0), "
        . "(?, 'Selftest P', 'p.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest SGM', 'sgm.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest PB BONLY', 'pb.p8@selftest.invalid', 1, 0, 0)",
        'iiiiiiii', [M, SA, SB, GA, LA, P_ID, SGM, PB]
    );
    pa_q(
        'INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES '
        . '(?, ?, 1, 0, 0), (?, ?, 1, 1, 0), (?, ?, 1, 1, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0)',
        'iiiiiiiiiiii', [M, ORG_A, SA, ORG_A, SB, ORG_B, LA, ORG_A, P_ID, ORG_A, SGM, ORG_A]
    );
    // PB is deliberately a member of B ONLY.
    pa_q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [PB, ORG_B]);

    pa_q(
        "INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, isActive) VALUES "
        . "(?, ?, 'Selftest SG1', 'selftest-p8-sg1', 1), (?, ?, 'Selftest SG2', 'selftest-p8-sg2', 0), (?, ?, 'Selftest SGB BONLY', 'selftest-p8-sgb', 1)",
        'iiiiii', [SG1, ORG_A, SG2, ORG_A, SGB, ORG_B]
    );
    pa_q("INSERT INTO tblSmallGroupMembers (siteID, groupID, userID, status) VALUES (?, ?, ?, 'active')", 'iii', [ORG_A, SG1, SGM]);
    pa_q(
        "INSERT INTO tblLeadershipRoles (roleID, siteID, roleName, roleSlug, isActive) VALUES "
        . "(?, ?, 'Selftest RA1', 'selftest-p8-ra1', 1), (?, ?, 'Selftest RB1 BONLY', 'selftest-p8-rb1', 1), "
        . "(?, ?, 'Selftest RA2', 'selftest-p8-ra2', 1)",
        'iiiiii', [RA1, ORG_A, RB1, ORG_B, RA2, ORG_A]
    );

    // 👥 The other kinds of viewer (fix round 4, check round 4 M2 — see the
    //    constants). Each is a case the visibility rule answers differently:
    //    a current role holder (L), a member of a second group (X), a former
    //    member whose old group row and role term were left behind and who
    //    is now a member of B (E — the rule must not let a former member in
    //    through them, nor count a membership of ANOTHER organisation), a
    //    member who left a group and whose term ended (Q), a member of a
    //    switched-off group (O), a member whose term has not started and
    //    whose join request is still waiting (F), and a switched-off ACCOUNT
    //    that still has an active membership and a role term (I, added in
    //    part B for the people-comparing copy). PB, a member of B ONLY, keeps
    //    an old RA1 term in A: E is also in SG1, so only PB tests "a
    //    membership of ANOTHER organisation counted" on its own (the first
    //    part B fault run found that fault uncaught without it). The term dates are worked out from
    //    TODAY in the organisation's zone — the same "today" the rule is
    //    given — thirty days either side, so no date in this file goes stale
    //    and a run that crosses midnight still reads the same.
    pa_q(
        'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES '
        . "(?, 'Selftest L', 'l.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest X', 'x.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest E', 'e.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest Q', 'q.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest O', 'o.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest F', 'f.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest I', 'i.p8@selftest.invalid', 0, 0, 0)",
        'iiiiiii', [L_ID, X_ID, E_ID, Q_ID, O_ID, F_ID, I_ID]
    );
    pa_q(
        'INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES '
        . '(?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 0, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), '
        . '(?, ?, 1, 0, 0), (?, ?, 1, 0, 0)',
        'iiiiiiiiiiiiiiii', [L_ID, ORG_A, X_ID, ORG_A, E_ID, ORG_A, Q_ID, ORG_A, O_ID, ORG_A, F_ID, ORG_A, E_ID, ORG_B, I_ID, ORG_A]
    );
    pa_q("INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, isActive) VALUES (?, ?, 'Selftest SG3', 'selftest-p8-sg3', 1)", 'ii', [SG3, ORG_A]);
    pa_q(
        'INSERT INTO tblSmallGroupMembers (siteID, groupID, userID, status) VALUES '
        . "(?, ?, ?, 'active'), (?, ?, ?, 'active'), (?, ?, ?, 'ended'), (?, ?, ?, 'active'), (?, ?, ?, 'pending')",
        'iiiiiiiiiiiiiii', [ORG_A, SG3, X_ID, ORG_A, SG1, E_ID, ORG_A, SG1, Q_ID, ORG_A, SG2, O_ID, ORG_A, SG3, F_ID]
    );
    $monthAgo  = pa_todayLocal()->modify('-30 days')->format('Y-m-d');
    $monthAway = pa_todayLocal()->modify('+30 days')->format('Y-m-d');
    pa_q(
        'INSERT INTO tblLeadershipAssignments (siteID, roleID, userID, startDate, endDate, isActive) VALUES '
        . '(?, ?, ?, NULL, NULL, 1), (?, ?, ?, NULL, NULL, 1), (?, ?, ?, NULL, ?, 1), (?, ?, ?, ?, NULL, 1), (?, ?, ?, NULL, NULL, 1), '
        . '(?, ?, ?, NULL, NULL, 1)',
        'iiiiiiiiisiiisiiiiii', [ORG_A, RA1, L_ID, ORG_A, RA1, E_ID, ORG_A, RA1, Q_ID, $monthAgo, ORG_A, RA1, F_ID, $monthAway, ORG_A, RA2, I_ID, ORG_A, RA1, PB]
    );

    // 👥 Fix round 5 (check round 5, M1) — the people the round 4 world
    //    lacked. Without each of them a copy that got that case wrong (or a
    //    later change to the rule about it) passed every check:
    //    - H holds a current term of RA3, and RA3 itself is SWITCHED OFF, as
    //      leadership/manage/save.php can leave a role;
    //    - R's RA6 term was REMOVED the way leadership/delete.php removes one:
    //      it runs `UPDATE tblLeadershipAssignments SET isActive = 0,
    //      updatedByID = ? WHERE assignmentID = ? AND siteID = ?` — the end
    //      date is left EMPTY, so only isActive says the term is over;
    //    - TS's RA4 term STARTS today and TE's RA5 term ENDS today — the two
    //      edges the rule counts as "current" (start <= today <= end) — with
    //      TY (ended yesterday) and TT (starts tomorrow) beside them as the
    //      controls one day outside;
    //    - SG4 holds ONLY an ended membership (Q) and a pending join request
    //      (F), so listing it lets nobody in.
    //    "Today" is the organisation's date by PHP's clock, as the oracle's;
    //    D17 waits out the last two minutes before local midnight so these
    //    edges cannot move between building the world and asking the rule.
    pa_q(
        'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES '
        . "(?, 'Selftest H', 'h.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest R', 'r.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest TS', 'ts.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest TY', 'ty.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest TE', 'te.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest TT', 'tt.p8@selftest.invalid', 1, 0, 0)",
        'iiiiii', [H_ID, R_ID, TS_ID, TY_ID, TE_ID, TT_ID]
    );
    pa_q(
        'INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES '
        . '(?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0)',
        'iiiiiiiiiiii', [H_ID, ORG_A, R_ID, ORG_A, TS_ID, ORG_A, TY_ID, ORG_A, TE_ID, ORG_A, TT_ID, ORG_A]
    );
    pa_q("INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, isActive) VALUES (?, ?, 'Selftest SG4', 'selftest-p8-sg4', 1)", 'ii', [SG4, ORG_A]);
    pa_q(
        "INSERT INTO tblSmallGroupMembers (siteID, groupID, userID, status) VALUES (?, ?, ?, 'ended'), (?, ?, ?, 'pending')",
        'iiiiii', [ORG_A, SG4, Q_ID, ORG_A, SG4, F_ID]
    );
    pa_q(
        "INSERT INTO tblLeadershipRoles (roleID, siteID, roleName, roleSlug, isActive) VALUES "
        . "(?, ?, 'Selftest RA3', 'selftest-p8-ra3', 0), (?, ?, 'Selftest RA4', 'selftest-p8-ra4', 1), "
        . "(?, ?, 'Selftest RA5', 'selftest-p8-ra5', 1), (?, ?, 'Selftest RA6', 'selftest-p8-ra6', 1)",
        'iiiiiiii', [RA3, ORG_A, RA4, ORG_A, RA5, ORG_A, RA6, ORG_A]
    );
    $today5    = pa_todayLocal()->format('Y-m-d');
    $yesterday = pa_todayLocal()->modify('-1 day')->format('Y-m-d');
    $tomorrow  = pa_todayLocal()->modify('+1 day')->format('Y-m-d');
    pa_q(
        'INSERT INTO tblLeadershipAssignments (siteID, roleID, userID, startDate, endDate, isActive, updatedByID) VALUES '
        . '(?, ?, ?, NULL, NULL, 1, NULL), (?, ?, ?, NULL, NULL, 0, ?), (?, ?, ?, ?, NULL, 1, NULL), '
        . '(?, ?, ?, NULL, ?, 1, NULL), (?, ?, ?, NULL, ?, 1, NULL), (?, ?, ?, ?, NULL, 1, NULL)',
        'iiiiiiiiiisiiisiiisiiis',
        [ORG_A, RA3, H_ID, ORG_A, RA6, R_ID, SA, ORG_A, RA4, TS_ID, $today5, ORG_A, RA4, TY_ID, $yesterday, ORG_A, RA5, TE_ID, $today5, ORG_A, RA5, TT_ID, $tomorrow]
    );

    // 👥 Fix round 6 (check round 6, L2) — small-group leaders, written with
    //    the memberRole values the Small Groups app uses: LD leads SG5 and CL
    //    co-leads SG6 (each the group's only row, so adding the group moves
    //    only them), and LO leads SG2, which is switched off (so a rule
    //    change letting a switched-off group's leaders in shows up). CO is
    //    a co-leader of SG2 (fix round 7, check round 7 L3), so a rule
    //    change letting a switched-off group's CO-LEADERS in shows up too.
    pa_q(
        'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES '
        . "(?, 'Selftest LD', 'ld.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest CL', 'cl.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest LO', 'lo.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest CO', 'co.p8@selftest.invalid', 1, 0, 0)",
        'iiii', [LD_ID, CL_ID, LO_ID, CO_ID]
    );
    pa_q(
        'INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0)',
        'iiiiiiii', [LD_ID, ORG_A, CL_ID, ORG_A, LO_ID, ORG_A, CO_ID, ORG_A]
    );
    pa_q(
        "INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, isActive) VALUES "
        . "(?, ?, 'Selftest SG5', 'selftest-p8-sg5', 1), (?, ?, 'Selftest SG6', 'selftest-p8-sg6', 1)",
        'iiii', [SG5, ORG_A, SG6, ORG_A]
    );
    pa_q(
        'INSERT INTO tblSmallGroupMembers (siteID, groupID, userID, memberRole, status) VALUES '
        . "(?, ?, ?, 'leader', 'active'), (?, ?, ?, 'co-leader', 'active'), (?, ?, ?, 'leader', 'active'), (?, ?, ?, 'co-leader', 'active')",
        'iiiiiiiiiiii', [ORG_A, SG5, LD_ID, ORG_A, SG6, CL_ID, ORG_A, SG2, LO_ID, ORG_A, SG2, CO_ID]
    );

    // 👥 Part 10 of #514 — roles, user groups and departments, and the
    //    people who hold them (see the constants). ORDER MATTERS: an
    //    account, its membership row, and the role, group or department
    //    must all exist BEFORE the holding, because every holding is tied to
    //    its membership row AND to the thing held by two-column links (a
    //    holding of B's role filed under A cannot be written at all — which
    //    is part of what keeps another organisation's role from counting).
    //    Everything B owns carries B_MARK. XB is NOT marked: XB is a member
    //    of A as well, so XB's own name may rightly appear on A's pages.
    pa_q(
        'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES '
        . "(?, 'Selftest RH', 'rh.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest UH', 'uh.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest DH', 'dh.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest XF', 'xf.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest XO', 'xo.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest DN', 'dn.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest XB', 'xb.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest XI', 'xi.p8@selftest.invalid', 0, 0, 0)",
        'iiiiiiii', [RH_ID, UH_ID, DH_ID, XF_ID, XO_ID, DN_ID, XB_ID, XI_ID]
    );
    // XF's row in A is switched OFF (a former member); XB has a row in each organisation.
    pa_q(
        'INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES '
        . '(?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 0, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), '
        . '(?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0)',
        'iiiiiiiiiiiiiiiiii',
        [RH_ID, ORG_A, UH_ID, ORG_A, DH_ID, ORG_A, XF_ID, ORG_A, XO_ID, ORG_A, DN_ID, ORG_A, XB_ID, ORG_A, XB_ID, ORG_B, XI_ID, ORG_A]
    );
    pa_q(
        "INSERT INTO tblRoles (roleID, siteID, roleKey, roleName, isStandard) VALUES (?, ?, 'selftest_p8_ro1', 'Selftest RO1', 0), (?, ?, 'selftest_p8_rob', 'Selftest ROB BONLY', 0)",
        'iiii', [RO1, ORG_A, ROB, ORG_B]
    );
    pa_q(
        "INSERT INTO tblGroups (groupID, siteID, groupName, isActive) VALUES (?, ?, 'Selftest UG1', 1), (?, ?, 'Selftest UG2', 0), (?, ?, 'Selftest UGB BONLY', 1)",
        'iiiiii', [UG1, ORG_A, UG2, ORG_A, UGB, ORG_B]
    );
    pa_q(
        "INSERT INTO tblDepts (deptID, siteID, deptName, isActive) VALUES (?, ?, 'Selftest DP1', 1), (?, ?, 'Selftest DP2', 0), (?, ?, 'Selftest DP3', NULL), (?, ?, 'Selftest DPB BONLY', 1)",
        'iiiiiiii', [DP1, ORG_A, DP2, ORG_A, DP3, ORG_A, DPB, ORG_B]
    );
    pa_q(
        'INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?), (?, ?, ?)',
        'iiiiiiiiiiii', [RH_ID, RO1, ORG_A, XF_ID, RO1, ORG_A, XI_ID, RO1, ORG_A, XB_ID, ROB, ORG_B]
    );
    pa_q(
        'INSERT INTO tblUserGroups (userID, groupID, siteID) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?), (?, ?, ?), (?, ?, ?)',
        'iiiiiiiiiiiiiii', [UH_ID, UG1, ORG_A, XF_ID, UG1, ORG_A, XI_ID, UG1, ORG_A, XO_ID, UG2, ORG_A, XB_ID, UGB, ORG_B]
    );
    pa_q(
        'INSERT INTO tblUserDepts (userID, deptID, siteID) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?), (?, ?, ?), (?, ?, ?), (?, ?, ?)',
        'iiiiiiiiiiiiiiiiii', [DH_ID, DP1, ORG_A, XF_ID, DP1, ORG_A, XI_ID, DP1, ORG_A, XO_ID, DP2, ORG_A, DN_ID, DP3, ORG_A, XB_ID, DPB, ORG_B]
    );

    // 🔢 One number, six kinds (see the constant SN). A role, a user group, a
    //    department, a small group and a leadership role of A, ALL numbered
    //    SN, all switched on, each with exactly one person — and SN is also
    //    KP's own account number. The ONLY thing that keeps, say, KR (who
    //    holds role SN) away from an event listed for "department SN" is the
    //    rule, and the copy, reading the row's KIND. The names sort after
    //    the others of their kind where that keeps older expectations simple;
    //    D1 writes the order out.
    pa_q(
        'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES '
        . "(?, 'Selftest KP', 'kp.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest KR', 'kr.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest KU', 'ku.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest KD', 'kd.p8@selftest.invalid', 1, 0, 0), "
        . "(?, 'Selftest KS', 'ks.p8@selftest.invalid', 1, 0, 0), (?, 'Selftest KL', 'kl.p8@selftest.invalid', 1, 0, 0)",
        'iiiiii', [KP_ID, KR_ID, KU_ID, KD_ID, KS_ID, KL_ID]
    );
    pa_q(
        'INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES '
        . '(?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0), (?, ?, 1, 0, 0)',
        'iiiiiiiiiiii', [KP_ID, ORG_A, KR_ID, ORG_A, KU_ID, ORG_A, KD_ID, ORG_A, KS_ID, ORG_A, KL_ID, ORG_A]
    );
    pa_q("INSERT INTO tblRoles (roleID, siteID, roleKey, roleName, isStandard) VALUES (?, ?, 'selftest_p8_sn', 'Selftest SN role', 0)", 'ii', [SN, ORG_A]);
    pa_q("INSERT INTO tblGroups (groupID, siteID, groupName, isActive) VALUES (?, ?, 'Selftest SN user group', 1)", 'ii', [SN, ORG_A]);
    pa_q("INSERT INTO tblDepts (deptID, siteID, deptName, isActive) VALUES (?, ?, 'Selftest SN department', 1)", 'ii', [SN, ORG_A]);
    pa_q("INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, isActive) VALUES (?, ?, 'Selftest SN small group', 'selftest-p8-sn', 1)", 'ii', [SN, ORG_A]);
    pa_q("INSERT INTO tblLeadershipRoles (roleID, siteID, roleName, roleSlug, isActive) VALUES (?, ?, 'Selftest SN leadership role', 'selftest-p8-sn', 1)", 'ii', [SN, ORG_A]);
    pa_q('INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, ?, ?)', 'iii', [KR_ID, SN, ORG_A]);
    pa_q('INSERT INTO tblUserGroups (userID, groupID, siteID) VALUES (?, ?, ?)', 'iii', [KU_ID, SN, ORG_A]);
    pa_q('INSERT INTO tblUserDepts (userID, deptID, siteID) VALUES (?, ?, ?)', 'iii', [KD_ID, SN, ORG_A]);
    pa_q("INSERT INTO tblSmallGroupMembers (siteID, groupID, userID, status) VALUES (?, ?, ?, 'active')", 'iii', [ORG_A, SN, KS_ID]);
    pa_q('INSERT INTO tblLeadershipAssignments (siteID, roleID, userID, startDate, endDate, isActive) VALUES (?, ?, ?, NULL, NULL, 1)', 'iii', [ORG_A, SN, KL_ID]);

    pa_q(
        "INSERT INTO tblEventCategories (categoryID, siteID, categoryName, categorySlug, isActive) VALUES "
        . "(?, ?, 'Selftest CA1', 'selftest-p8-ca1', 1), (?, ?, 'Selftest CA2', 'selftest-p8-ca2', 1), (?, ?, 'Selftest CB1 BONLY', 'selftest-p8-cb1', 1)",
        'iiiiii', [CA1, ORG_A, CA2, ORG_A, CB1, ORG_B]
    );
    pa_feed(FA1, ORG_A, 'members');
    pa_feed(FA2, ORG_A, 'public');
    pa_feed(FB1, ORG_B, 'public', ['name' => 'Selftest calendar BONLY']);
}

/**
 * A calendar row. Its stored address ends in `.internal`, which
 * `SafeFetch` refuses before any network use (`SafeFetch.php:154`) — so
 * nothing in this file can reach the network, and an UNCHANGED address
 * posted back must NOT be re-checked (fix round 1, checker M4: this file
 * would fail if it were).
 *
 * @param array<string,mixed> $opts name, url, fetchEveryMins, timezone, categoryID, website, apiOptOut, active
 */
function pa_feed(int $feedId, int $orgId, string $level, array $opts = []): int
{
    global $mysqli;
    $stmt = $mysqli->prepare(
        'INSERT INTO tblExternalFeeds (feedID, siteID, name, url, fetchEveryMins, timezone, categoryID, audienceLevel, '
        . 'websiteOptIn, apiOptOut, isActive, nextFetchAt, createdAt) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $name       = (string) ($opts['name'] ?? ('Selftest calendar ' . $feedId));
    $url        = (string) ($opts['url'] ?? ('https://p8-selftest.internal/' . $feedId . '.ics'));
    $every      = (int) ($opts['fetchEveryMins'] ?? 360);
    $timezone   = $opts['timezone'] ?? null;
    $categoryId = $opts['categoryID'] ?? null;
    $website    = (int) ($opts['website'] ?? 0);
    $apiOptOut  = (int) ($opts['apiOptOut'] ?? 0);
    $active     = (int) ($opts['active'] ?? 1);
    $stmt->bind_param('iissisisiii', $feedId, $orgId, $name, $url, $every, $timezone, $categoryId, $level, $website, $apiOptOut, $active);
    $stmt->execute();
    $stmt->close();

    return $feedId;
}

function pa_newFeed(int $orgId, string $level, array $opts = []): int
{
    // A number the database has already given out is skipped (chunk P8-2's
    // follow-up). Part W's W19 adds a calendar through the REAL page, and the
    // database numbers that one itself: on a freshly built database it takes
    // the next free number after the world's, which is exactly the number
    // this counter would hand out next — the first follow-up run failed on
    // that clash ("Duplicate entry '910217'") while a second run on the same
    // database, whose counter had moved on, passed.
    while (pa_one('SELECT feedID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$GLOBALS['pa_nextId']['feed']]) !== null) {
        $GLOBALS['pa_nextId']['feed']++;
    }

    return pa_feed($GLOBALS['pa_nextId']['feed']++, $orgId, $level, $opts);
}

/** The calendar form's post for a calendar's CURRENT stored values, with `$changes` on top. */
function pa_calPost(int $feedId, array $changes = []): array
{
    $row = pa_one('SELECT name, url, fetchEveryMins, timezone, categoryID, audienceLevel, websiteOptIn, apiOptOut FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feedId]);

    return $changes + [
        'name' => (string) $row['name'], 'url' => (string) $row['url'], 'fetchEveryMins' => (int) $row['fetchEveryMins'],
        'timezone' => (string) ($row['timezone'] ?? ''), 'categoryID' => $row['categoryID'] === null ? '' : (string) $row['categoryID'],
        'audienceLevel' => (string) $row['audienceLevel'], 'websiteOptIn' => (string) $row['websiteOptIn'], 'apiOptOut' => (string) $row['apiOptOut'],
    ];
}

/** @param list<array{0:string,1:int}> $members */
function pa_list(int $feedId, string $ownerType, int $ownerId, array $members): void
{
    $org = (int) pa_one('SELECT siteID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feedId])['siteID'];
    foreach ($members as [$kind, $refId]) {
        $userId = ($kind === 'person') ? $refId : null;
        pa_q(
            'INSERT INTO tblExternalAudienceMembers (siteID, feedID, ownerType, ownerID, kind, refID, userID, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            'iisisii', [$org, $feedId, $ownerType, $ownerId, $kind, $refId, $userId]
        );
    }
}

/**
 * A copied-in event the way the importer would leave it BEFORE the resolver
 * runs: hidden, basic, not for API keys. Same shape (and same proven SQL)
 * as `tools/feed-resolver-selftest.php`'s `fr_event()`, with one addition —
 * `categoryID` — because the administrator pages show and match on it.
 *
 * `$opts`: id, day (offset from today, default 7), clock, description,
 * location, url, key (recurrence key), private, duplicate, deleted,
 * categoryID, tags, noIdentity (true = externalUidHash left NULL,
 * challenge M3).
 */
function pa_event(int $feedId, string $uid, string $title, array $opts = []): int
{
    global $mysqli;
    $id   = (int) ($opts['id'] ?? $GLOBALS['pa_nextId']['event']++);
    $org  = (int) pa_one('SELECT siteID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feedId])['siteID'];
    $zone = ($org === ORG_B) ? SITE_ZONE_B : SITE_ZONE;
    $day  = pa_todayLocal($zone)->modify('+' . (int) ($opts['day'] ?? 7) . ' days')->format('Y-m-d');
    $start = $day . ' ' . ($opts['clock'] ?? '19:00:00');
    $end   = $day . ' 21:00:00';
    $categoryId = $opts['categoryID'] ?? null;

    $stmt = $mysqli->prepare(
        'INSERT INTO tblEvents (eventID, siteID, externalFeedID, externalUid, externalUidHash, externalRecurrenceKey, '
        . 'eventSlug, eventName, description, locationName, externalUrl, startDateTime, endDateTime, timezone, '
        . 'status, isPublic, isDeleted, importLevel, importDetail, importApiOptOut, externalPrivate, '
        . 'externalDuplicate, categoryID, externalLastSeenAt) VALUES '
        . '(?, ?, ?, ?, ' . ((($opts['noIdentity'] ?? false) === true) ? 'NULL' : 'UNHEX(SHA2(?, 256))')
        . ", ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', 0, ?, 'hidden', 'basic', 1, ?, ?, ?, UTC_TIMESTAMP())"
    );
    // 🪤 mysqli_stmt::bind_param() binds by reference, so every value is
    //    worked out into its OWN variable first, then the list spread.
    $recurrenceKey = (string) ($opts['key'] ?? '');
    $eventSlug     = 'imp-selftest-p8-' . $id;
    $description   = $opts['description'] ?? 'A description';
    $location      = $opts['location'] ?? 'A hall';
    $url           = $opts['url'] ?? 'https://example.invalid/e';
    $isDeleted     = (int) ($opts['deleted'] ?? 0);
    $isPrivate     = (int) ($opts['private'] ?? 0);
    $isDuplicate   = (int) ($opts['duplicate'] ?? 0);
    if (($opts['noIdentity'] ?? false) === true) {
        $params = [
            $id, $org, $feedId, $uid, $recurrenceKey, $eventSlug, $title,
            $description, $location, $url, $start, $end, $zone,
            $isDeleted, $isPrivate, $isDuplicate, $categoryId,
        ];
        $stmt->bind_param('iiissssssssssiiii', ...$params);
    } else {
        $params = [
            $id, $org, $feedId, $uid, $uid, $recurrenceKey, $eventSlug, $title,
            $description, $location, $url, $start, $end, $zone,
            $isDeleted, $isPrivate, $isDuplicate, $categoryId,
        ];
        $stmt->bind_param('iiisssssssssssiiii', ...$params);
    }
    $stmt->execute();
    $stmt->close();
    foreach ((array) ($opts['tags'] ?? []) as $tag) {
        pa_q('INSERT INTO tblExternalEventTags (eventID, tag) VALUES (?, ?)', 'is', [$id, (string) $tag]);
    }

    return $id;
}

/** A weekly series on one calendar, sharing one identity. Returns the eventIDs, earliest first. */
function pa_series(int $feedId, string $uid, string $title, array $opts = []): array
{
    $ids   = [];
    $count = (int) ($opts['count'] ?? 4);
    for ($week = 0; $week < $count; $week++) {
        $rowOpts        = $opts;
        $rowOpts['day'] = ((int) ($opts['day'] ?? 7)) + ($week * 7);
        $rowOpts['key'] = ($opts['recurrenceKeys'][$week] ?? ('W' . $week));
        unset($rowOpts['recurrenceKeys'], $rowOpts['titles'], $rowOpts['count']);
        $ids[] = pa_event($feedId, $uid, (string) ($opts['titles'][$week] ?? $title), $rowOpts);
    }

    return $ids;
}

/** A choice row made by hand (only where the check is about READING one). */
function pa_choice(int $feedId, string $scope, string $uid, string $level, array $opts = []): int
{
    global $mysqli;
    $id  = (int) ($opts['id'] ?? $GLOBALS['pa_nextId']['choice']++);
    $org = (int) pa_one('SELECT siteID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feedId])['siteID'];
    $stmt = $mysqli->prepare(
        'INSERT INTO tblExternalEventChoices (choiceID, siteID, feedID, scope, externalUidHash, externalRecurrenceKey, audienceLevel, detailLevel, '
        . 'websiteOptIn, apiOptOut, fromDate, toDate, overridesPrivateMark, note, createdByID, createdAt, updatedByID, updatedAt) '
        . 'VALUES (?, ?, ?, ?, UNHEX(SHA2(?, 256)), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), NULL, NULL)'
    );
    $key       = ($scope === 'date') ? (string) ($opts['key'] ?? '') : '';
    $detail    = (string) ($opts['detail'] ?? 'basic');
    $website   = (int) ($opts['website'] ?? 0);
    $apiOptOut = (int) ($opts['apiOptOut'] ?? 0);
    $from      = $opts['from'] ?? null;
    $to        = $opts['to'] ?? null;
    $override  = (int) ($opts['override'] ?? 0);
    $note      = $opts['note'] ?? null;
    $by        = $opts['by'] ?? SA;
    $params    = [$id, $org, $feedId, $scope, $uid, $key, $level, $detail, $website, $apiOptOut, $from, $to, $override, $note, $by];
    $stmt->bind_param('iiisssssiissisi', ...$params);
    $stmt->execute();
    $stmt->close();

    return $id;
}

/** A run row, as the importer would leave one. */
function pa_run(int $feedId, string $message, int $awaiting = 0): void
{
    $org = (int) pa_one('SELECT siteID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feedId])['siteID'];
    pa_q(
        "INSERT INTO tblExternalFeedRuns (feedID, siteID, startedAt, finishedAt, outcome, message, awaitingApproval, triggeredBy) "
        . "VALUES (?, ?, UTC_TIMESTAMP() - INTERVAL 1 HOUR, UTC_TIMESTAMP() - INTERVAL 1 HOUR, 'ok', ?, ?, 'schedule')",
        'iisi', [$feedId, $org, $message, $awaiting]
    );
}

/**
 * A refresh as the importer records one, for the "Partly read" flag (chunk
 * P8-2's follow-up): a run row with this outcome, and — only for a refresh
 * that READ the calendar ('ok' or 'partial') — the calendar row's
 * `lastRunCapped` set the way `FeedImporter::stampSuccess()` sets it. An
 * 'unchanged' or 'failed' refresh leaves it alone, exactly as the importer
 * does (recordUnchanged() and recordFailure() never write it).
 */
function pa_capRun(int $feedId, int $orgId, string $outcome, int $minutesAgo): void
{
    pa_q(
        "INSERT INTO tblExternalFeedRuns (feedID, siteID, startedAt, finishedAt, outcome, message, awaitingApproval, triggeredBy) "
        . "VALUES (?, ?, UTC_TIMESTAMP() - INTERVAL ? MINUTE, UTC_TIMESTAMP() - INTERVAL ? MINUTE, ?, 'capped-flag run', 0, 'schedule')",
        'iiiis', [$feedId, $orgId, $minutesAgo, $minutesAgo, $outcome]
    );
    if ($outcome === 'ok' || $outcome === 'partial') {
        pa_q('UPDATE tblExternalFeeds SET lastRunCapped = ? WHERE feedID = ?', 'ii', [$outcome === 'partial' ? 1 : 0, $feedId]);
    }
}

/** @return array<string,mixed> One event's stored answer. */
function pa_ev(int $eventId): array
{
    return pa_one(
        'SELECT eventID, importLevel, importDetail, importWebsite, importApiOptOut, importAudienceType, importAudienceID, '
        . 'importSource, importSourceID, importRecheckAt, categoryID FROM tblEvents WHERE eventID = ?', 'i', [$eventId]
    ) ?? [];
}

/** "level/source" of one event, for short checks. */
function pa_ls(int $eventId): string
{
    $e = pa_ev($eventId);

    return ($e['importLevel'] ?? '?') . '/' . ($e['importSource'] ?? '?');
}

function pa_apprWithStatus(int $eventId, string $status): array
{
    return pa_q(
        'SELECT approvalID, requestHash, contentHash, reason, origin, originID, requestedLevel, decidedAt, decisionNote, createdAt '
        . 'FROM tblExternalEventApprovals WHERE eventID = ? AND status = ? ORDER BY approvalID',
        'is', [$eventId, $status]
    );
}

function pa_status(int $approvalId): string
{
    return (string) (pa_one('SELECT status FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [$approvalId])['status'] ?? '(gone)');
}

/** A decision item, exactly as a page would post it back, from an approval row. */
function pa_item(array $approvalRow): array
{
    return ['approvalID' => (int) $approvalRow['approvalID'], 'requestHash' => (string) $approvalRow['requestHash'], 'contentHash' => (string) $approvalRow['contentHash']];
}

/** The ONE waiting row of an event, or a FAIL-able null. */
function pa_pending(int $eventId): ?array
{
    return pa_apprWithStatus($eventId, 'pending')[0] ?? null;
}

/** The full-detail fingerprint a choice page would post for this event (its stored category included). */
function pa_cf(int $eventId): string
{
    $row = pa_one('SELECT eventName, description, locationName, externalUrl, categoryID FROM tblEvents WHERE eventID = ?', 'i', [$eventId]);

    return FeedResolver::fullContentHash($row, $row['categoryID'] === null ? null : (int) $row['categoryID']);
}

/** Every calendar of the made-up world, however it was made. @return list<int> */
function pa_worldFeeds(): array
{
    return array_map(
        static fn (array $r): int => (int) $r['feedID'],
        pa_q('SELECT feedID FROM tblExternalFeeds WHERE feedID BETWEEN 910000 AND 919999 OR siteID IN (?, ?) ORDER BY feedID', 'ii', [ORG_A, ORG_B])
    );
}

/**
 * A checksum of every #514 table for a set of calendars, plus the import*
 * columns of their events — the plan's "unchanged" (section 11.2's intro).
 * Taken before and after a refused write; identical means nothing at all
 * was written.
 *
 * @param list<int> $feedIds
 */
function pa_fingerprint(array $feedIds): string
{
    global $mysqli;
    if ($feedIds === []) {
        return 'empty';
    }
    $ph    = implode(',', array_fill(0, count($feedIds), '?'));
    $types = str_repeat('i', count($feedIds));
    $parts = [];
    foreach ([
        "SELECT * FROM tblExternalFeeds WHERE feedID IN ($ph) ORDER BY feedID",
        "SELECT * FROM tblExternalAudienceMembers WHERE feedID IN ($ph) ORDER BY audienceMemberID",
        "SELECT * FROM tblExternalCategoryMap WHERE feedID IN ($ph) ORDER BY mapID",
        "SELECT * FROM tblExternalEventChoices WHERE feedID IN ($ph) ORDER BY choiceID",
        "SELECT * FROM tblExternalFeedRules WHERE feedID IN ($ph) ORDER BY ruleID",
        "SELECT c.* FROM tblExternalRuleConditions c JOIN tblExternalFeedRules r ON r.ruleID = c.ruleID WHERE r.feedID IN ($ph) ORDER BY c.conditionID",
        "SELECT * FROM tblExternalEventApprovals WHERE feedID IN ($ph) ORDER BY approvalID",
        "SELECT eventID, importLevel, importDetail, importWebsite, importApiOptOut, importAudienceType, importAudienceID, "
        . "importSource, importSourceID, importRecheckAt, categoryID FROM tblEvents WHERE externalFeedID IN ($ph) ORDER BY eventID",
    ] as $sql) {
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$feedIds);
        $stmt->execute();
        // `serialize()`, not `json_encode()`: several of these columns are
        //    BINARY(32) identity hashes, raw bytes that are not valid
        //    UTF-8, and JSON has no way to carry them.
        $parts[] = serialize($stmt->get_result()->fetch_all(MYSQLI_NUM));
        $stmt->close();
    }

    return hash('sha256', implode('|', $parts));
}

/** The whole made-up world's fingerprint — every calendar, including any a write just added. */
function pa_fp(): string
{
    return implode(',', pa_worldFeeds()) . '#' . pa_fingerprint(pa_worldFeeds());
}

/**
 * Fix round 7 (check round 7, M2): give the OTHER calendars — organisation
 * B's FB1, and A's own FA2 — category-map rows under the SAME words (or
 * their twins) before a save of FA1's map, and return what they hold. A
 * save that lost its calendar number (the "default" DELETE, or the SELECT
 * … FOR UPDATE, without `feedID = ?`) would delete or rewrite them; the
 * caller compares pa_otherMaps() afterwards. `$words` are word => [FB1's
 * spelling, FA2's spelling].
 *
 * @param array<string, array{0:string, 1:string}> $words
 *
 * @return list<array<string,mixed>>
 */
function pa_seedOtherMaps(array $words): array
{
    foreach ($words as [$bWord, $aWord]) {
        pa_q(
            'INSERT INTO tblExternalCategoryMap (siteID, feedID, externalCategory, categoryID) VALUES (?, ?, ?, ?), (?, ?, ?, ?)',
            'iisiiisi', [ORG_B, FB1, $bWord, CB1, ORG_A, FA2, $aWord, CA2]
        );
    }

    return pa_otherMaps();
}

/** Every map row of FB1 and FA2, in a fixed order. @return list<array<string,mixed>> */
function pa_otherMaps(): array
{
    return pa_q('SELECT feedID, siteID, externalCategory, categoryID FROM tblExternalCategoryMap WHERE feedID IN (?, ?) ORDER BY feedID, externalCategory', 'ii', [FB1, FA2]);
}

/**
 * Hold calendars' row locks on a SECOND connection, the way a refresh in
 * progress does, while `$body` runs on the test's own connection with a
 * short lock wait (so a regression fails in seconds, never hangs). Both
 * are put back in a `finally`, whatever `$body` does.
 *
 * @param list<int> $feedIds
 */
function pa_whileLocked(array $feedIds, int $waitSeconds, callable $body): mixed
{
    global $mysqli;
    $second = pa_connect();
    $second->begin_transaction();
    try {
        foreach ($feedIds as $fid) {
            $second->query('SELECT feedID FROM tblExternalFeeds WHERE feedID = ' . (int) $fid . ' FOR UPDATE');
        }
        $mysqli->query('SET SESSION innodb_lock_wait_timeout = ' . (int) $waitSeconds);

        return $body();
    } finally {
        $mysqli->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        $second->commit();
        $second->close();
    }
}

/**
 * Call `$call`; answer what it returned or threw, and how long it took.
 *
 * @return array{result:mixed, threw:?Throwable, seconds:float}
 */
function pa_timed(callable $call): array
{
    $t = microtime(true);
    try {
        $result = $call();
        $threw  = null;
    } catch (Throwable $e) {
        $result = null;
        $threw  = $e;
    }

    return ['result' => $result, 'threw' => $threw, 'seconds' => microtime(true) - $t];
}

/**
 * The child side of `--hold-lock`: lock, wait, act, commit.
 * Arguments: <feedID> <seconds to hold> <action: none | delete | seturl:<address>> <flag file>.
 *
 * @param list<string> $argv
 */
function pa_holdLockChild(mysqli $db, array $argv): void
{
    $feedId = (int) ($argv[2] ?? 0);
    $hold   = (float) ($argv[3] ?? 0);
    $action = (string) ($argv[4] ?? 'none');
    $flag   = (string) ($argv[5] ?? '');
    if ($feedId < 910000 || $feedId > 919999 || $flag === '' || $hold <= 0 || $hold > 10) {
        echo "REFUSED — --hold-lock needs a made-up calendar (910000-919999), 0-10 seconds and a flag file.\n";
        exit(1);
    }
    $db->begin_transaction();
    $db->query('SELECT feedID FROM tblExternalFeeds WHERE feedID = ' . $feedId . ' FOR UPDATE');
    file_put_contents($flag, "locked\n");
    usleep((int) ($hold * 1000000));
    if (str_starts_with($action, 'seturl:') === true) {
        $url  = substr($action, 7);
        $stmt = $db->prepare('UPDATE tblExternalFeeds SET url = ? WHERE feedID = ?');
        $stmt->bind_param('si', $url, $feedId);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'delete') {
        $db->query('DELETE FROM tblExternalFeeds WHERE feedID = ' . $feedId);
    }
    $db->commit();
    file_put_contents($flag, "committed\n", FILE_APPEND);
}

/**
 * Run `$body` while a separate process (this file with `--hold-lock`)
 * holds calendar `$feedId`'s row lock for `$hold` seconds and then does
 * `$action`. Started with the command as a LIST (no shell); waited for,
 * and stopped by its own process if it overstays — never by a name.
 *
 * @return array{result:mixed, holderOk:bool, holderLog:string}
 */
function pa_withHolder(int $feedId, float $hold, string $action, callable $body): array
{
    $flag = tempnam(sys_get_temp_dir(), 'p8holder');
    $log  = $flag . '.log';
    file_put_contents($flag, '');
    $proc = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=256M', __FILE__, '--hold-lock', (string) $feedId, (string) $hold, $action, $flag],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes
    );
    $result = null;
    $ok     = false;
    try {
        if (is_resource($proc) === false) {
            return ['result' => null, 'holderOk' => false, 'holderLog' => 'could not start the lock holder'];
        }
        // Up to 60 seconds for the separate process to START and take its
        // lock (fix round 3). The first version allowed 10: on a machine
        // busy with four other test runs, one start in ten took longer —
        // PHP loading this 3,000-line file and ten classes, then
        // connecting — and the check failed with nothing wrong in the code
        // (seen in the flakiness proof, run 7). Waiting longer here costs
        // nothing when all is well: the holder's own hold only starts once
        // it has the lock, so the timing the checks rely on is unchanged.
        $startedAt = microtime(true);
        $deadline  = $startedAt + 60;
        while (str_contains((string) @file_get_contents($flag), 'locked') === false && microtime(true) < $deadline) {
            if (proc_get_status($proc)['running'] === false) {
                break; // it ended without taking the lock — its log says why
            }
            usleep(20000);
        }
        if (str_contains((string) @file_get_contents($flag), 'locked') === false) {
            return ['result' => null, 'holderOk' => false, 'holderLog' => sprintf('the lock holder never took the lock (%.1f s): ', microtime(true) - $startedAt) . (string) @file_get_contents($log)];
        }
        $result = $body();
    } finally {
        if (is_resource($proc) === true) {
            $stopBy = microtime(true) + $hold + 15;
            while (proc_get_status($proc)['running'] === true && microtime(true) < $stopBy) {
                usleep(20000);
            }
            if (proc_get_status($proc)['running'] === true) {
                proc_terminate($proc);
            }
            proc_close($proc);
        }
        $ok   = str_contains((string) @file_get_contents($flag), 'committed');
        $text = (string) @file_get_contents($log);
        @unlink($flag);
        @unlink($log);
    }

    return ['result' => $result, 'holderOk' => $ok, 'holderLog' => $text];
}

/** A short, readable account of a result for a FAIL line. */
function pa_show(mixed $value): string
{
    $clean = is_array($value) ? $value : ['value' => $value];
    if (isset($clean['failure']) === true && $clean['failure'] instanceof Throwable) {
        $clean['failure'] = get_class($clean['failure']) . ': ' . $clean['failure']->getMessage();
    }
    $text = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    return mb_substr((string) $text, 0, 600);
}

// =============================================================================
// 📄 PART S — the pages never write around the service layer (chunk P8-2)
// =============================================================================
//
// WHY THIS EXISTS (P8-1 check round 1, LOW 10; carried into chunk P8-2)
// ---------------------------------------------------------------------------
// Five methods change who may see an imported event, or the lists that
// decide it, and are PUBLIC only because FeedAdmin (in another class) has to
// call them:
//   ExternalAudience::save()  and  ::remove()        — the "who may see it" lists
//   FeedResolver::decideApproval()                    — approve / decline a date
//   FeedResolver::withdrawForOrigin()                 — withdraw waiting dates
//   FeedResolver::onAddressChanged()                  — send approvals back
// Each must only ever run INSIDE FeedAdmin's one locked sequence
// (FeedAdmin::inFeedLock(): transaction, the calendar's row lock by number AND
// organisation, the write, a resolve with the DATABASE's clock, commit). A
// page that called one directly would write without the lock and without the
// resolve — the exact fault the service class exists to make impossible —
// and nothing else would notice, because every file and address still
// exists. So this part reads every page file of the two folders that hold
// the outside-calendar pages and the Approvals inbox, and FAILS if any of
// them makes such a call.
//
// WHY IN THIS FILE, NOT AN AUDIT SCRIPT: the owner decided on 25 September
// 2026 that this self-test runs on every pull request (plan top section, item
// 8); a new audit script would need its own line in the workflow file.
//
// WHAT IT READS, AND WHAT IT CANNOT SEE
// ---------------------------------------------------------------------------
// It reads the PHP code of every `.php` file under the two folders, with the
// comments taken out (a comment that explains why a call is NOT made here
// must not trip it). It catches: `ExternalAudience::save(` / `::remove(`
// (with or without the namespace, spaces or a line break before the
// bracket); `::decideApproval(`, `::withdrawForOrigin(`, `::onAddressChanged(`
// through ANY class name; ANY alias of either class — `use … FeedResolver
// as X`, and the grouped form `use Portal\Core\{Auth, ExternalAudience as
// EA}` too (fix round 1, L3: the grouped form used to slip through, and the
// alias then called `EA::save(`); any of the five methods called through a
// class held in a variable (`$c::save(`) or written in brackets
// (`(ExternalAudience::class)::save(`); part 7's three called through an
// object (`$o->decideApproval(`); ANY `class_alias(` at all, whatever it
// names (chunk P8-3: the header used to imply a fixed-name alias was caught
// when it was not — a page has no reason to make an alias); and the method
// named as text for a callable (`[FeedResolver::class, 'decideApproval']`,
// `'Portal\Core\ExternalAudience::save'`). PHP method names ignore case, so
// the search does too.
// It CANNOT see a method name built at run time from pieces, `eval()`, a
// call to `save(`/`remove(` through an object (`$o->save(` is far too common
// a shape to forbid; ExternalAudience's methods are static anyway), or a call
// made through a helper that lives outside these two folders. Those would be
// deliberate evasions, not accidents; review has to catch them.

/** The two folders the pages live in, relative to the repository root. */
const PS_FOLDERS = ['web/_apps/admin/calendar', 'web/_apps/approvals'];

/**
 * Every forbidden call in one piece of PHP source, with its line number.
 * Comments (and any HTML outside the PHP tags) are blanked first, keeping the
 * line numbers, so an explanation of the rule never reads as a breach of it.
 *
 * @return list<array{line:int, text:string}>
 */
function ps_findBypass(string $source): array
{
    $code     = ps_codeOnly($source);
    $patterns = [
        '/\bExternalAudience\s*::\s*(save|remove)\s*\(/i',
        '/::\s*(decideApproval|withdrawForOrigin|onAddressChanged)\s*\(/i',
        // Any alias, in a plain or a grouped `use` (fix round 1, L3).
        '/\b(ExternalAudience|FeedResolver)\s+as\s+[A-Za-z_]/i',
        // A class held in a variable (`$c = ExternalAudience::class; $c::save(`).
        '/\$[A-Za-z_]\w*\s*::\s*(save|remove|decideApproval|withdrawForOrigin|onAddressChanged)\s*\(/i',
        // A class written in brackets, and part 7's three through an object (chunk P8-3).
        '/\)\s*::\s*(save|remove|decideApproval|withdrawForOrigin|onAddressChanged)\s*\(/i',
        '/->\s*(decideApproval|withdrawForOrigin|onAddressChanged)\s*\(/i',
        // Any class_alias() at all (chunk P8-3; see the note above).
        '/\bclass_alias\s*\(/i',
        '/[\'"](decideApproval|withdrawForOrigin|onAddressChanged)[\'"]/i',
        '/ExternalAudience::class\s*,\s*[\'"](save|remove)[\'"]/i',
        '/[\'"][^\'"]*ExternalAudience::(save|remove)[\'"]/i',
    ];
    $found = [];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$text, $offset]) {
                $found[] = ['line' => substr_count(substr($code, 0, (int) $offset), "\n") + 1, 'text' => trim((string) $text)];
            }
        }
    }

    return $found;
}

/**
 * PHP source with its comments (and any HTML outside the PHP tags) blanked,
 * keeping the line numbers — so an explanation of a rule never reads as a
 * breach of it. String contents are KEPT (SQL lives in strings).
 */
function ps_codeOnly(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) === true) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT || $token[0] === T_INLINE_HTML) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $code .= $token[1];
            continue;
        }
        $code .= $token;
    }

    return $code;
}

/**
 * Every SQL function the importer writes into `lastFetchedAt`, in order
 * (fix round 1, L2): "UTC_TIMESTAMP" three times is right. W8 proves ONE of
 * the three writes through a real refresh (the failure path); the other two
 * (a file found unchanged, and a successful read) need a real calendar
 * download, which part W never makes — so this reads the source instead,
 * comments left out, and a `NOW()` (or any other function) put back into any
 * of the three fails it.
 *
 * @return list<string>
 */
function ps_lastFetchedWrites(string $source): array
{
    preg_match_all('/\blastFetchedAt\s*=\s*([A-Za-z_]+)\s*\(/', ps_codeOnly($source), $m);

    return array_map('strtoupper', $m[1]);
}

/** Part S: prove the search works, prove it read the real pages, then read them. */
function ps_run(): void
{
    pa_heading('S1 — pages never write around the service layer');

    // 🧪 The search must FIRE on every shape of the call (the "no check may
    //    run nothing" rule: a search that finds nothing is only worth
    //    something once it is shown to find things).
    $planted = [
        'ExternalAudience::save($db, 1, 2, "feed", 2, [], 3);',
        "ExternalAudience::remove(\n    \$db, 1, 2, 'feed', 2);",
        '\\Portal\\Core\\ExternalAudience::SAVE($db, 1, 2, "feed", 2, [], 3);',
        'FeedResolver::decideApproval($db, 1, 1, "approved", 1, "", "a", "b");',
        '\\Portal\\Core\\FeedResolver::withdrawForOrigin($db, 1, "choice", 2);',
        'FeedResolver :: onAddressChanged ($db, 1, $now);',
        'R::decideApproval($db, 1, 1, "approved", 1, "", "a", "b");',
        'use Portal\\Core\\FeedResolver as R;',
        'call_user_func([FeedResolver::class, \'decideApproval\'], $db);',
        'call_user_func([ExternalAudience::class, "save"], $db);',
        'call_user_func(\'Portal\\Core\\ExternalAudience::remove\', $db);',
        // Fix round 1, L3: the grouped-import alias, and a class held in a variable.
        "use Portal\\Core\\{Auth, ExternalAudience as EA};\nEA::save(\$db, 1, 2, 'feed', 2, [], 3);",
        'use Portal\\Core\\{FeedAdmin, FeedResolver as FR, Site};',
        '$cls = FeedResolver::class; $cls::withdrawForOrigin($db, 1, "rule", 2);',
        // Chunk P8-3 (after P8-2's check round 2): a fixed-name class_alias(),
        // a class in brackets, and a call through an object.
        "class_alias(ExternalAudience::class, 'EA');\nEA::save(\$db, 1, 2, 'feed', 2, [], 3);",
        '(ExternalAudience::class)::save($db, 1, 2, "feed", 2, [], 3);',
        '$resolver->decideApproval($db, 1, 1, "approved", 1, "", "a", "b");',
    ];
    $missed = [];
    foreach ($planted as $i => $line) {
        if (ps_findBypass("<?php\n" . $line . "\n") === []) {
            $missed[] = '#' . $i . ' ' . $line;
        }
    }
    pa_ok('S1 the search finds every planted shape of a forbidden call (' . count($planted) . ' shapes)', $missed === [], 'not found: ' . implode(' | ', $missed));

    // …and must NOT fire on an explanation in a comment, or on the calls the
    //    pages legitimately make, or the class would cry wolf and be ignored.
    $innocent = "<?php\n// ExternalAudience::save(\$db) is never called here.\n"
        . "/* FeedResolver::decideApproval( lives in FeedAdmin */\n"
        . "/** @see FeedResolver::onAddressChanged() */\n"
        . "\$a = FeedAdmin::saveChoice(\$db, 1, 'date', 1, 1, []);\n"
        . "\$b = ExternalAudience::options(\$db, 1);\n\$c = ExternalAudience::current(\$db, 1, 1, 'feed', 1);\n"
        . "\$d = FeedAdmin::decide(\$db, 1, 1, 'approved', '', []);\n\$e = FeedAdmin::saveCalendar(\$db, 1, 1, 1, []);\n"
        . "\$f = ExternalAudience::stateLabel('ok');\n?>\n<p>Decisions go through FeedResolver::decideApproval( inside FeedAdmin.</p>\n";
    $falseAlarms = ps_findBypass($innocent);
    pa_ok('S1 the search ignores comments, page HTML and the calls the pages are meant to make', $falseAlarms === [], pa_show($falseAlarms));

    // 📂 Coverage: which files were read. A folder that moved, or a pattern
    //    that matched no file, would otherwise read as "nothing found".
    $root    = dirname(__DIR__);
    $files   = [];
    foreach (PS_FOLDERS as $folder) {
        $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
        if (is_dir($dir) === false) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $path => $info) {
            if ($info->isFile() === true && strtolower($info->getExtension()) === 'php') {
                $files[$folder . substr((string) $path, strlen($dir))] = (string) $path;
            }
        }
    }
    ksort($files);
    $mustRead = [
        'web/_apps/admin/calendar/feeds.php', 'web/_apps/admin/calendar/feeds-save.php', 'web/_apps/admin/calendar/feeds-edit.php',
        'web/_apps/admin/calendar/feeds-view.php', 'web/_apps/admin/calendar/_audience-picker.php', 'web/_apps/approvals/index.php',
        // Chunk P8-4's four new files: the decision handler is the one that
        // decides, so it above all must go through FeedAdmin::decide().
        'web/_apps/admin/calendar/feeds-preview.php', 'web/_apps/admin/calendar/feeds-approvals-act.php',
        'web/_apps/admin/calendar/_approval-rows.php', 'web/_apps/approvals/_imports-section.php',
    ];
    $notRead = array_values(array_diff(str_replace('/', DIRECTORY_SEPARATOR, $mustRead), array_map(
        static fn (string $k): string => str_replace('/', DIRECTORY_SEPARATOR, $k),
        array_keys($files)
    )));
    pa_ok(
        'S1 it read every page file of both folders (' . count($files) . ' files), including the five chunk P8-2 files, the Approvals inbox and chunk P8-4\'s four',
        $notRead === [] && count($files) >= count($mustRead),
        'not read: ' . implode(', ', $notRead)
    );

    // 🔎 The real search.
    $breaches = [];
    foreach ($files as $label => $path) {
        foreach (ps_findBypass((string) file_get_contents($path)) as $hit) {
            $breaches[] = $label . ':' . $hit['line'] . ' ' . $hit['text'];
        }
    }
    pa_ok('S1 no page calls ExternalAudience::save()/remove() or FeedResolver::decideApproval()/withdrawForOrigin()/onAddressChanged() directly', $breaches === [], implode(' | ', $breaches));

    // -------------------------------------------------------------------------
    pa_heading('S2 — the importer writes "last fetched" in UTC, all three times');
    $sample = "<?php\n// lastFetchedAt = NOW() in a comment does not count\n\$a = 'UPDATE t SET lastFetchedAt = NOW(), x = 1';\n"
        . "\$b = 'UPDATE t SET lastFetchedAt = UTC_TIMESTAMP()';\n\$c = 'UPDATE t SET lastFetchedAt=sysdate()';\n";
    pa_ok(
        'S2 the reading finds each write\'s function (a planted NOW(), UTC_TIMESTAMP() and SYSDATE()), and ignores one in a comment',
        ps_lastFetchedWrites($sample) === ['NOW', 'UTC_TIMESTAMP', 'SYSDATE'],
        pa_show(ps_lastFetchedWrites($sample))
    );
    $importer = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'FeedImporter.php';
    $writes   = ps_lastFetchedWrites((string) @file_get_contents($importer));
    pa_ok(
        'S2 web/_core/FeedImporter.php writes lastFetchedAt exactly three times, every time with UTC_TIMESTAMP() (challenge M4; fix round 1, L2)',
        $writes === ['UTC_TIMESTAMP', 'UTC_TIMESTAMP', 'UTC_TIMESTAMP'],
        pa_show($writes)
    );

    // -------------------------------------------------------------------------
    pa_heading('S3 — part W only shares a database server with throwaway databases');
    // The firing control for part W's server check (chunk P8-3, after P8-2's
    // check round 2): the name filter itself, asked about the names it must
    // let through and the ones it must not. A filter that lower-cased names
    // ('SYS' on a Linux server is a real, separate database) or dropped the
    // underscore ('selftestprod') let somebody's database through, and a
    // normal run could not notice.
    $names = [
        'mysql' => true, 'information_schema' => true, 'performance_schema' => true, 'sys' => true, 'selftest_p8' => true,
        'SYS' => false, 'MySQL' => false, 'Information_Schema' => false, 'selftestprod' => false, 'Selftest_p8' => false,
        'devapp' => false, 'portal' => false, 'xselftest_p8' => false, ' selftest_p8' => false,
    ];
    $wrong = [];
    foreach ($names as $name => $allowed) {
        if (pw_isThrowawayServerDatabase((string) $name) !== $allowed) {
            $wrong[] = "'{$name}' should be " . ($allowed === true ? 'allowed' : 'refused');
        }
    }
    pa_ok('S3 the filter allows exactly MySQL\'s own four (exact names) and selftest_ databases, and refuses ' . (count($names) - 5) . ' look-alikes', $wrong === [], implode(' | ', $wrong));
    // The CALL SITE as well (fix round 1 of chunk P8-3, check finding L4).
    // The check above asks the filter itself, so a letter-case change put
    // back WHERE part W asks it — `pw_isThrowawayServerDatabase()` given a
    // lower-cased name, the shape of the original fault — passed every check,
    // and that run went on to change a server holding a `SYS` database. So
    // this reads pw_preflight()'s own code (comments left out): exactly one
    // call, given `$name` exactly as MySQL listed it, and no function that
    // changes letter case anywhere in that function. A text guard only: it
    // reads this file, it cannot see a change made somewhere pw_preflight()
    // does not contain (that is what the filter's own check above is for).
    $sampleCallBad = [
        '$name = (string) $row[0]; if (pw_isThrowawayServerDatabase(strtolower($name)) === false) { $others[] = $name; }',
        '$name = strtolower((string) $row[0]); if (pw_isThrowawayServerDatabase($name) === false) { $others[] = $name; }',
        '$name = (string) $row[0]; if (pw_isThrowawayServerDatabase(mb_strtolower($name)) === false) { $others[] = $name; }',
        '$name = (string) $row[0]; $others[] = $name;',
    ];
    $sampleCallGood = '$name = (string) $row[0]; if (pw_isThrowawayServerDatabase($name) === false) { $others[] = $name; }';
    $caught = 0;
    foreach ($sampleCallBad as $sample) {
        if (ps_callSiteProblems($sample) !== []) {
            $caught++;
        }
    }
    pa_ok(
        'S3 the call-site reading flags each of ' . count($sampleCallBad) . ' planted shapes (a lower-cased name at the call, a lower-cased name before it, mb_strtolower, no call at all) and passes the right one',
        $caught === count($sampleCallBad) && ps_callSiteProblems($sampleCallGood) === [],
        pa_show(['caught' => $caught, 'good' => ps_callSiteProblems($sampleCallGood)])
    );
    $selfSource = (string) file_get_contents(__FILE__);
    $preStart   = strpos($selfSource, "\nfunction pw_preflight(): void");
    $preEnd     = ($preStart === false) ? false : strpos($selfSource, "\nfunction ", $preStart + 10);
    $preBody    = ($preStart === false || $preEnd === false) ? '' : substr($selfSource, $preStart, $preEnd - $preStart);
    pa_ok(
        'S3 where part W asks the filter (pw_preflight), it passes each database name exactly as MySQL listed it',
        $preBody !== '' && ps_callSiteProblems($preBody) === [],
        $preBody === '' ? 'pw_preflight() was not found in this file' : implode(' | ', ps_callSiteProblems($preBody))
    );

    // -------------------------------------------------------------------------
    pa_heading('S4 — every message box on the part 8 pages prints its text escaped');
    // A message box (`role="alert"`) shows a flash or a refusal, and those can
    // quote outside text — the importer can quote a calendar's own time-zone
    // name, the category matching quotes a posted word. Part W fills most of
    // them with hostile text (W21), but a box whose sentence never quotes
    // anything today (the calendar form's refusal box) cannot be caught that
    // way. So this reads the pages: every PHP "echo" on a line with
    // `role="alert"` must print through `$esc(`. A text guard only — it reads
    // the source, it does not run the page. (This comment never writes the
    // PHP closing tag itself: in a line comment it would end PHP mode.)
    $sampleBad  = '<div class="alert alert-danger" role="alert"><?php echo $refusal; ?></div>' . "\n"
        . '<div class="alert alert-<?php echo $esc($t); ?>" role="alert"><?php echo $flashMsg; ?></div>';
    $sampleGood = '<div class="alert alert-<?php echo $esc($t); ?>" role="alert"><?php echo $esc($flashMsg); ?></div>';
    pa_ok(
        'S4 the reading flags a message box printing raw text (two planted) and passes an escaped one',
        count(ps_rawAlertEchoes($sampleBad)) === 2 && ps_rawAlertEchoes($sampleGood) === [],
        pa_show([ps_rawAlertEchoes($sampleBad), ps_rawAlertEchoes($sampleGood)])
    );
    $alertBreaches = [];
    $alertLines    = 0;
    foreach (glob(dirname(__DIR__) . '/web/_apps/admin/calendar/feeds*.php') ?: [] as $page) {
        $source       = (string) file_get_contents($page);
        $alertLines  += substr_count($source, 'role="alert"');
        foreach (ps_rawAlertEchoes($source) as $hit) {
            $alertBreaches[] = basename($page) . ': ' . $hit;
        }
    }
    pa_ok(
        'S4 every message box on the outside-calendar pages (' . $alertLines . ' found) prints through $esc()',
        // At least the eight boxes the six pages hold today (chunk P8-3), so a
        // reading that found no pages at all cannot pass.
        $alertBreaches === [] && $alertLines >= 8,
        implode(' | ', $alertBreaches) . ' (message boxes found: ' . $alertLines . ')'
    );
}

/**
 * What is wrong with how a piece of code asks the server-database filter
 * (S3's call-site check, fix round 1 of chunk P8-3): it must call
 * `pw_isThrowawayServerDatabase()` exactly once, on `$name`, where `$name` is
 * the listed name as it came, with no function that changes letter case
 * anywhere in the code read. Comments are left out first. An empty list
 * means nothing is wrong.
 *
 * @return list<string>
 */
function ps_callSiteProblems(string $code): array
{
    $code     = ps_codeOnly('<?php ' . $code);
    $problems = [];
    $calls    = preg_match_all('/pw_isThrowawayServerDatabase\s*\(([^)]*)\)/', $code, $cm);
    if ($calls !== 1) {
        $problems[] = 'the filter is called ' . (int) $calls . ' times, not once';
    } elseif (trim($cm[1][0]) !== '$name') {
        $problems[] = 'the filter is given "' . trim($cm[1][0]) . '", not $name';
    }
    if (str_contains($code, '$name = (string) $row[0];') === false) {
        $problems[] = 'the name is not taken exactly as listed ($name = (string) $row[0];)';
    }
    if (preg_match('/\b(?:mb_)?str(?:to)?(?:lower|upper)\s*\(|\b(?:uc|lc)first\s*\(|\bucwords\s*\(|\bstrn?casecmp\s*\(|\bmb_convert_case\s*\(/i', $code, $fm) === 1) {
        $problems[] = 'a letter-case function is used: ' . $fm[0];
    }

    return $problems;
}

/**
 * The `<?php echo … ?>` pieces on `role="alert"` lines that do NOT print
 * through `$esc(` (S4).
 *
 * @return list<string>
 */
function ps_rawAlertEchoes(string $source): array
{
    $hits = [];
    foreach (preg_split('/\R/', $source) as $line) {
        if (str_contains((string) $line, 'role="alert"') === false) {
            continue;
        }
        if (preg_match_all('/<\?php\s+echo\s+(.*?);?\s*\?>/', (string) $line, $m) > 0) {
            foreach ($m[1] as $expr) {
                if (str_starts_with(trim($expr), '$esc(') === false) {
                    $hits[] = trim($expr);
                }
            }
        }
    }

    return $hits;
}


// =============================================================================
// 🌐 PART W — helpers: the scratch folder, the server, requests, clean-up
// =============================================================================
// See "PART W — HOW IT STAYS SAFE" in the file header for the rules these
// follow and why (the #514 part 8 plan, section 11.1; challenge findings H2,
// M4, M7, L2 and L10).

/** The organisations' own address prefixes (multi-site "path" mode). */
const PW_A = '/selftest-p8-a';
const PW_B = '/selftest-p8-b';

/** Everything part W made or changed, so the clean-up can put it back. */
$GLOBALS['pw'] = [
    'started'      => false,  // pw_setup() began (so the clean-up has work to do)
    'cleaned'      => false,  // the clean-up has run (it never runs twice)
    'token'        => '',     // this run's random token: marker file + response header
    'parent'       => '',     // the scratch PARENT folder (real path)
    'child'        => '',     // the NEW child folder this run made
    'childMade'    => false,
    'repo'         => '',     // the repository folder (real path)
    'parentBefore' => null,   // the parent's entry names before anything was made
    'markerHeld'   => null,   // at deletion: did the marker still hold this run's token?
    'childRemoved' => null,
    'proc'         => null,   // the web server's process, from proc_open()
    'port'         => 0,
    'closedPort'   => 0,
    'base'         => '',
    'settings'     => [],     // settingKey => the global row before (null = there was none)
    'dbZone'       => null,   // @@GLOBAL.time_zone before
    'dbZoneSet'    => false,
    'errorsFrom'   => 0,      // the highest tblErrors number before part W
    'password'     => '',     // made up for this run, never printed
    'answers'      => 0,      // HTTP answers received
    'without'      => [],     // answers that did NOT carry this run's header
    'problems'     => [],     // anything the clean-up could not do
    'jars'         => [],
    'migrationRows' => [],    // W52 (chunk P8-4): tblMigrations' rows W52 deletes (206; 207 since fix round 1), each put back exactly
    'activityFrom' => 0,      // the highest tblActivityLogs number before part W (W54, chunk P8-4 fix round 1)
];

/**
 * Stop part W with a refusal: exit 1 at once. The shutdown clean-up still
 * runs (it is registered before anything is made), so whatever was made or
 * changed is put back; a refusal that comes before anything was made has
 * nothing to put back.
 */
function pw_refuse(string $why): never
{
    echo "\nREFUSED — part W: {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

/** The names in a folder, sorted, without "." and "..". @return list<string> */
function pw_names(string $dir): array
{
    $names = @scandir($dir);
    if ($names === false) {
        return [];
    }
    $names = array_values(array_diff($names, ['.', '..']));
    sort($names, SORT_STRING);

    return $names;
}

/** Does anything answer on this local port? */
function pw_portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/**
 * Copy `web/` into the scratch child, leaving out the server-managed
 * folders. A link anywhere inside is REFUSED rather than followed: there is
 * none today, and following one could copy (or later delete) something
 * outside the copy.
 *
 * @param list<string> $skipTop
 */
function pw_copyTree(string $from, string $to, array $skipTop): int
{
    if (@mkdir($to, 0700) === false) {
        throw new RuntimeException('could not make ' . $to);
    }
    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $info) use ($from, $skipTop): bool {
            $rel = substr($info->getPathname(), strlen($from) + 1);

            return in_array(explode(DIRECTORY_SEPARATOR, $rel)[0], $skipTop, true) === false;
        }
    );
    $copied = 0;
    foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
        $rel = substr((string) $path, strlen($from) + 1);
        if ($info->isLink() === true) {
            throw new RuntimeException('web/ holds a link (' . $rel . '); refusing to copy it');
        }
        $target = $to . DIRECTORY_SEPARATOR . $rel;
        if ($info->isDir() === true) {
            if (@mkdir($target, 0700) === false) {
                throw new RuntimeException('could not make ' . $target);
            }
            continue;
        }
        if (@copy((string) $path, $target) === false) {
            throw new RuntimeException('could not copy ' . $rel);
        }
        $copied++;
    }

    return $copied;
}

/** Delete a folder this run made, never following a link out of it. */
function pw_deleteTree(string $dir): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $path => $info) {
        if ($info->isLink() === true || $info->isFile() === true) {
            @unlink((string) $path);
        } elseif ($info->isDir() === true) {
            @rmdir((string) $path);
        }
    }
    @rmdir($dir);
}

/** A connection with NO session zone of its own, so it has the database's global zone. */
function pw_plainConnect(): mysqli
{
    $db = new mysqli(
        getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
        getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
        getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
        (string) getenv('SELFTEST_DB_NAME'),
        getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
    );
    $db->set_charset('utf8mb4');

    return $db;
}

/**
 * One real HTTP request to the server this run started. Every answer is
 * checked for this run's header; one without it came from somewhere else and
 * is recorded for W0. Redirects are never followed (the checks read them).
 *
 * `$asIs` (chunk P8-3, W38) sends the address exactly as written: curl
 * otherwise tidies "/../" and "/./" away before sending, so a check of the
 * test server's own fence would never reach the server with them.
 *
 * `$bearer` (W59) sends an API key in the `Authorization` header, for the
 * two checks that ask as a key instead of as a signed-in person.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function pw_req(string $jar, string $method, string $path, ?array $post = null, bool $asIs = false, string $bearer = ''): array
{
    $pw =& $GLOBALS['pw'];
    $h  = curl_init($pw['base'] . $path);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '*',
        CURLOPT_HTTPHEADER     => $bearer === '' ? ['Expect:'] : ['Expect:', 'Authorization: Bearer ' . $bearer],
    ]);
    if ($asIs === true) {
        curl_setopt($h, CURLOPT_PATH_AS_IS, true);
    }
    if ($method === 'POST') {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($h);
    $pw['answers']++;
    if ($raw === false) {
        $pw['without'][] = $method . ' ' . $path . ' (no answer: ' . curl_error($h) . ')';

        return ['status' => 0, 'location' => '', 'body' => '', 'head' => ''];
    }
    $headSize = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $status   = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
    $head     = substr((string) $raw, 0, $headSize);
    $body     = substr((string) $raw, $headSize);
    // The LAST header block is the answer (an interim "100 Continue" would
    // come first; the Expect header above asks curl not to wait for one).
    $blocks = preg_split("/\r\n\r\n(?=HTTP\/)/", trim($head));
    $last   = (string) end($blocks);
    $mark   = preg_match('/^X-Feed-Admin-Selftest:\s*([0-9a-f]+)\s*$/mi', $last, $m) === 1 ? $m[1] : '';
    if ($mark !== $pw['token']) {
        $pw['without'][] = $method . ' ' . $path . ' (status ' . $status . ')';
    }
    $location = preg_match('/^Location:\s*(\S+)\s*$/mi', $last, $l) === 1 ? $l[1] : '';

    return ['status' => $status, 'location' => $location, 'body' => $body, 'head' => $last];
}

/** The form token on a page: a form's hidden field, else the page's meta tag. */
/**
 * 🚪 Stand the members-only gate down, or put it back, in THIS RUN'S SCRATCH
 * COPY only (roll-out package F2, 12 October 2026, #511).
 *
 * WHY. Since F2 a signed-in person who is not a member of the organisation
 * an address opens is refused at the door (web/_core/SiteGate.php, HTTP 403)
 * before any page runs. Part W sends two such outsiders to organisation A's
 * pages: SB (an administrator of B only) and PB (a member of B only). With
 * the gate in place every one of their requests is answered 403 by the gate,
 * so a check that expects the PAGE to refuse them would go on passing with
 * the page's own rule taken out, and the checks that expect the page to
 * SHOW them A's public event would fail.
 *
 * So part W runs with the gate stood down, and what answers is the rule
 * inside each page, exactly as these checks were written. Then, at the end
 * of W59, the gate is put back and the same two outsiders are sent to the
 * same addresses: each is refused at the door ("W59 at the door" checks).
 * With only the door tested, a page's own rule could be removed and nothing
 * here would fail.
 *
 * WHAT THAT COSTS: the members' own requests in part W also run with the
 * gate stood down. `tools/site-modes-selftest.php` is the gate's own test,
 * members included.
 *
 * Standing it down swaps the one line that refuses a non-member for a plain
 * `return`; everything else the gate does stays. The file's time stamp is
 * moved on with each write, so the server reads the new text at once (see
 * the two opcache settings where the server is started). The run is refused
 * if that line is not there exactly once.
 */
function pw_membersGate(bool $inPlace): void
{
    $pw   =& $GLOBALS['pw'];
    $file = $pw['child'] . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'SiteGate.php';
    if (isset($pw['gateFile']) === false) {
        $pw['gateFile'] = (string) file_get_contents($file);
        $pw['gateTime'] = (int) filemtime($file);
    }
    $refusal = 'self::notAMember($db, $userId, $own, $path);';
    if (substr_count($pw['gateFile'], $refusal) !== 1) {
        pw_refuse('web/_core/SiteGate.php does not hold the line "' . $refusal . '" exactly once, so the members-only gate cannot be stood down for the page checks');
    }
    file_put_contents($file, $inPlace === true ? $pw['gateFile'] : str_replace($refusal, 'return;', $pw['gateFile']));
    $pw['gateTime'] += 60;
    touch($file, $pw['gateTime']);
    clearstatcache(true, $file);
}

function pw_token(string $html): string
{
    if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return '';
}

/** A fresh token for `$jar` from `$path` (tokens change on every successful check). */
function pw_freshToken(string $jar, string $path): string
{
    return pw_token(pw_req($jar, 'GET', $path)['body']);
}

/** Follow one redirect with the same cookie jar and answer the page it leads to. */
function pw_follow(string $jar, array $answer): array
{
    if ($answer['location'] === '' || str_starts_with($answer['location'], '/') === false) {
        return ['status' => 0, 'location' => '', 'body' => '', 'head' => ''];
    }

    return pw_req($jar, 'GET', $answer['location']);
}

/** Text as a page prints it (the pages escape every message). */
function pw_esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/** The page's own content: what sits between <main …> and </main>. */
function pw_main(string $html): string
{
    return preg_match('#<main\b[^>]*>(.*)</main>#s', $html, $m) === 1 ? $m[1] : '';
}

/**
 * A 404 (or any) page with the parts that change on EVERY request taken out:
 * the form tokens and the script nonces. Two answers that are the same after
 * this are the same answer.
 */
function pw_normalise(string $html): string
{
    $html = (string) preg_replace('/[0-9a-f]{64}/', 'TOKEN', $html);

    return (string) preg_replace('/nonce="[^"]*"/', 'nonce="NONCE"', $html);
}

/**
 * The visible form fields of a page's content that have neither a matching
 * `<label for>` nor an `aria-label`, and how many fields were looked at.
 *
 * @return array{bad: list<string>, count: int}
 */
function pw_unlabelled(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    $doc      = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xp     = new DOMXPath($doc);
    $labels = [];
    foreach ($xp->query('//label[@for]') as $label) {
        $labels[$label->getAttribute('for')] = true;
    }
    $bad   = [];
    $count = 0;
    foreach ($xp->query('//main//input | //main//select | //main//textarea') as $el) {
        if ($el->nodeName === 'input' && strtolower($el->getAttribute('type')) === 'hidden') {
            continue;
        }
        $count++;
        if (trim($el->getAttribute('aria-label')) !== '') {
            continue;
        }
        $id = $el->getAttribute('id');
        if ($id !== '' && isset($labels[$id]) === true) {
            continue;
        }
        $bad[] = $el->nodeName . '#' . $id . '[' . $el->getAttribute('name') . ']';
    }

    return ['bad' => $bad, 'count' => $count];
}

/**
 * May part W share its database server with a database of this name? Only
 * MySQL's own four (named EXACTLY as MySQL lists them, in lower case) and the
 * test's throwaway `selftest_…` ones (fix round 1 of P8-2, M3; tightened in
 * chunk P8-3 after its check round 2).
 *
 * WHY EXACTLY: the first version compared the names in lower case. On a Linux
 * MySQL server (where letter case in database names matters) `SYS` or `MySQL`
 * is a separate, real database that could belong to somebody — and part W
 * treated it as MySQL's own and went on to change the server's time zone
 * (measured by the check). MySQL itself always lists its own four in lower
 * case, so an exact comparison loses nothing. The prefix is compared exactly
 * too, underscore included: `selftestprod` is not a throwaway database.
 */
function pw_isThrowawayServerDatabase(string $name): bool
{
    return in_array($name, ['mysql', 'information_schema', 'performance_schema', 'sys'], true) === true
        || str_starts_with($name, 'selftest_') === true;
}

/**
 * The checks that must come BEFORE part W makes or changes anything at all —
 * not a folder, not a database row (challenge H2 and M7): the scratch parent
 * folder must exist, must not contain the repository and must not be inside
 * it (compared as real paths ending in a separator, so "/a/b" is never taken
 * to contain "/a/bc"); and nothing may already answer on the test's port or
 * on the closed port beside it. Each failure is a refusal (exit 1).
 */
function pw_preflight(): void
{
    $pw =& $GLOBALS['pw'];
    foreach (['curl_init', 'proc_open', 'sodium_crypto_secretbox'] as $needed) {
        if (function_exists($needed) === false) {
            pw_refuse("this PHP has no {$needed}(), which part W needs");
        }
    }
    if (class_exists('DOMDocument') === false) {
        pw_refuse('this PHP has no DOM extension, which part W needs');
    }

    $parentRaw = getenv('SELFTEST_WEB_DIR');
    $parentRaw = ($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw;
    $parent    = realpath($parentRaw);
    if ($parent === false || is_dir($parent) === false) {
        pw_refuse("the scratch parent folder '{$parentRaw}' does not exist or is not a folder");
    }
    $repo = realpath(dirname(__DIR__));
    if ($repo === false) {
        pw_refuse('the repository folder could not be found');
    }
    $sep         = DIRECTORY_SEPARATOR;
    $parentSlash = rtrim($parent, $sep) . $sep;
    $repoSlash   = rtrim($repo, $sep) . $sep;
    if (str_starts_with($repoSlash, $parentSlash) === true) {
        pw_refuse("the repository is inside the scratch parent folder '{$parent}' — choose a folder outside it with SELFTEST_WEB_DIR");
    }
    if (str_starts_with($parentSlash, $repoSlash) === true) {
        pw_refuse("the scratch parent folder '{$parent}' is inside the repository — choose a folder outside it with SELFTEST_WEB_DIR");
    }

    $port = getenv('SELFTEST_WEB_PORT');
    $port = ($port === false || $port === '') ? 9058 : (int) $port;
    if ($port < 1024 || $port > 65534) {
        pw_refuse('SELFTEST_WEB_PORT must be between 1024 and 65534');
    }
    $closed = $port + 1;
    if (pw_portAnswers($port) === true) {
        pw_refuse("something already answers on port {$port} — a leftover server from a crashed run, or another project's; this test never drives a server it did not start");
    }
    if (pw_portAnswers($closed) === true) {
        pw_refuse("something answers on port {$closed}, which this test needs CLOSED (a refused connection is how a calendar download fails at once)");
    }

    // 🗄️ A database server of its own (fix round 1, M3). Part W sets the
    //    SERVER's own time zone (SET GLOBAL time_zone, step 5 of pw_setup()),
    //    not just this test database's — for the whole run, and for good if
    //    the run is killed. On a server that also holds somebody's real
    //    databases, every application using it would get NOW() seven or eight
    //    hours out meanwhile, and could store wrong times. So part W refuses,
    //    before it changes anything, unless every database it can see is one
    //    of MySQL's own or a throwaway `selftest_…` one. (The check found on 28
    //    September 2026 that nothing stopped this: the only guard was the
    //    test database's own name.)
    //    WHAT IT CANNOT SEE: a database the account is not allowed to list
    //    (an account that may run SET GLOBAL can normally list them all), and
    //    one created after this check has run.
    //    Which names count is decided by pw_isThrowawayServerDatabase(). Part
    //    S tests that function on its own (S3), so a fault INSIDE it fails a
    //    check. A fault HERE — a name changed before it is asked about, the
    //    shape of the original letter-case fault — is caught only because S3
    //    also reads this function's code (fix round 1 of chunk P8-3, check
    //    finding L4: the first wording claimed both, and the check showed such
    //    a fault passing every check while it changed a server's zone). Keep
    //    the line that takes the name, and the one call, exactly as they are.
    global $mysqli;
    $others = [];
    $listed = $mysqli->query('SHOW DATABASES');
    while (($row = $listed->fetch_row()) !== null) {
        $name = (string) $row[0];
        if (pw_isThrowawayServerDatabase($name) === false) {
            $others[] = $name;
        }
    }
    if ($others !== []) {
        pw_refuse('the database server also holds ' . count($others) . ' other database' . (count($others) === 1 ? '' : 's')
            . " (for example '" . implode("', '", array_slice($others, 0, 3)) . "'). Part W changes the SERVER's own time zone for its "
            . 'whole run, which would put every other application on this server that does not set its own zone hours out — '
            . 'until the run ends, or, if the run were killed, until the server restarts or somebody sets it back. Run it on a '
            . 'throwaway database server of its own, holding nothing but selftest_ databases');
    }

    // 🧟 A run killed outright (SIGKILL, a power cut) cannot put anything
    //    back: its web server keeps running (refused just above, through the
    //    port), its scratch child stays, and the database's own zone stays at
    //    -07:00 with the two multisite settings changed. Measured on 28
    //    September 2026 (chunk P8-2's harness control). Once somebody stops
    //    that server, the NEXT run would record the leftovers as the values
    //    to put back — so a database already at -07:00 is refused here, and
    //    the message says what to put back by hand. (The two settings cannot
    //    be told apart from an installation that really uses them; the zone
    //    is the sign.)
    $zoneNow = (string) (pa_one('SELECT @@GLOBAL.time_zone AS z')['z'] ?? '');
    if ($zoneNow === '-07:00') {
        pw_refuse("the database's own time zone is already -07:00, which is what a run of this test that was killed part-way leaves behind. "
            . "Set it back by hand (SET GLOBAL time_zone = '<its usual value>'), check multisite.enabled and multisite.detectionMode "
            . "are back to what they were, delete any leftover feed-admin-selftest-* folder whose marker file you recognise, and run again");
    }

    $pw['parent']     = $parent;
    $pw['repo']       = $repo;
    $pw['port']       = $port;
    $pw['closedPort'] = $closed;
    $pw['base']       = 'http://127.0.0.1:' . $port;
}

/**
 * Build everything part W needs, in the plan's order (section 11.1, steps 1
 * to 5). Anything that does not come out as it must is a refusal: the rest
 * of part W would prove nothing on top of it.
 */
function pw_setup(): void
{
    global $mysqli;
    $pw =& $GLOBALS['pw'];
    // (pw_run() has already registered the clean-up, before building anything.)
    $pw['token'] = bin2hex(random_bytes(16));

    // 1️⃣ The scratch folder (challenge H2). pw_preflight() has already
    //    refused a bad parent folder; the child is made here.
    $sep                = DIRECTORY_SEPARATOR;
    $parent             = (string) $pw['parent'];
    $repo               = (string) $pw['repo'];
    $pw['parentBefore'] = pw_names($parent);
    $child = $parent . $sep . 'feed-admin-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
    if (file_exists($child) === true || @mkdir($child, 0700) === false) {
        pw_refuse("could not make a NEW scratch folder '{$child}' (it may already exist; this test never reuses or empties a folder it did not make)");
    }
    $pw['child']     = $child;
    $pw['childMade'] = true;
    // The marker FIRST: only a folder holding this run's token is ever deleted.
    file_put_contents($child . $sep . '.feed-admin-selftest-owner', $pw['token']);

    // 2️⃣ The copy of web/, with a throwaway connection file and key.
    $copied = pw_copyTree($repo . $sep . 'web', $child . $sep . 'web', ['_auth_keys', '_uploads', '_backups', '_libraries']);
    if ($copied < 100) {
        pw_refuse("the copy of web/ holds only {$copied} files");
    }
    $keys = $child . $sep . 'web' . $sep . '_auth_keys';
    mkdir($keys, 0700);
    $creds = [
        'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
        'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
        'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
        'db_name' => (string) getenv('SELFTEST_DB_NAME'),
        'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
    ];
    file_put_contents($keys . $sep . 'auth_creds.php', "<?php\n// Written by tools/feed-admin-selftest.php for one test run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
    file_put_contents($keys . $sep . 'enc.key', bin2hex(random_bytes(32)));
    mkdir($child . $sep . 'sessions', 0700);

    // 4️⃣ The ports were chosen and checked by pw_preflight(); checked
    //    AGAIN here, just before the server starts, in case something began
    //    listening while the world was being built.
    $port   = (int) $pw['port'];
    $closed = (int) $pw['closedPort'];
    if (pw_portAnswers($port) === true || pw_portAnswers($closed) === true) {
        pw_refuse("something began answering on port {$port} or {$closed} while part W was getting ready; this test never drives a server it did not start");
    }

    // 3️⃣ The router: imitates web/public_html/.htaccess, marks every answer
    //    with this run's token, and points the one test name at the closed
    //    port (challenge L2). Written by this file, into the scratch child.
    //
    //    STATIC FILES ARE SERVED BY THE ROUTER ITSELF (fix round 1, L4). The
    //    first version answered an existing file with `return false`, which
    //    asks PHP's built-in server to serve it. Two problems, both measured:
    //    without `-t` the server looked in the wrong folder (the scratch
    //    child, not web/public_html) and answered 404; and even with `-t`
    //    (measured on PHP 8.5.10) it serves the file but DROPS every header the
    //    router had set — so the run's marker was missing and W0 would have
    //    failed for a reason that has nothing to do with the pages. Now the
    //    router sends the file itself, with the marker. What it serves is
    //    held inside web/public_html by its real path (a "/../" in the address
    //    cannot reach anything outside), and a .php file is never sent as
    //    text: only the three entry points .htaccess allows are run, the rest
    //    get 404 as before. A folder is run through its own index.php (the
    //    only one is api-docs/), or refused.
    $router = <<<'ROUTER'
<?php
// Written by tools/feed-admin-selftest.php for ONE test run (deleted with its folder).
// Imitates web/public_html/.htaccess for PHP's built-in server. Test only.
declare(strict_types=1);
header('X-Feed-Admin-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
$entryPoints = ['/index.php', '/api-docs/index.php', '/error.php'];
// Any PHP-like ending, in any letter case (chunk P8-3, after P8-2's check
// round 2): the first rule matched ".php" in lower case only, so on a disk
// that ignores letter case (macOS) "/manifest.PHP" RAN a page Apache would
// never run directly, and a ".phtml" file would have been sent as text.
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, $entryPoints, true) === false) { http_response_code(404); exit('404'); }
// Which PHP file this request runs. It is REQUIRED AT THE TOP LEVEL of this
// router, never inside a function: the portal's bootstrap sets `$mysqli` and
// `$SETTINGS` as globals, and a file required inside a function would make
// them that function's locals instead (the Router reads them as globals).
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
$inside = ($real !== false && ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)));
if ($inside === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') {
        $target = $real; // one of the three entry points (anything else was refused above)
    } else {
        $types = ['css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8', 'json' => 'application/json',
            'map' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
            'ttf' => 'font/ttf', 'txt' => 'text/plain; charset=UTF-8', 'html' => 'text/html; charset=UTF-8', 'xml' => 'application/xml',
            'webmanifest' => 'application/manifest+json'];
        header('Content-Type: ' . ($types[strtolower(pathinfo($real, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($real));
        readfile($real);
        return true;
    }
} elseif ($inside === true && is_dir($real) === true) {
    if (is_file($real . DIRECTORY_SEPARATOR . 'index.php') === false) {
        http_response_code(403);
        exit('403');
    }
    $target = $real . DIRECTORY_SEPARATOR . 'index.php';
}
require_once __DIR__ . '/web/_core/SafeFetch.php';
\Portal\Core\SafeFetch::$testResolverOverride = ['p8-selftest.test' => ['ips' => ['127.0.0.1'], 'port' => __CLOSED_PORT__]];
$_SERVER['SCRIPT_NAME']     = substr($target, strlen($root));
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
ROUTER;
    $router = str_replace(['__TOKEN__', '__CLOSED_PORT__'], [$pw['token'], (string) $closed], $router);
    file_put_contents($child . $sep . 'router.php', $router);

    // 5️⃣ Settings and the database's own zone — recorded first, put back by
    //    the clean-up (challenge L10).
    $errorsFrom        = pa_one('SELECT COALESCE(MAX(errorID), 0) AS n FROM tblErrors');
    $pw['errorsFrom']  = (int) ($errorsFrom['n'] ?? 0);
    $pw['activityFrom'] = (int) (pa_one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'] ?? 0);
    foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'path'] as $key => $value) {
        $row = pa_one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
        $pw['settings'][$key] = $row;
        if ($row === null) {
            pa_q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);
        } else {
            pa_q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$value, (int) $row['settingID']]);
        }
    }
    $pw['dbZone'] = (string) (pa_one('SELECT @@GLOBAL.time_zone AS z')['z'] ?? '');
    try {
        $mysqli->query("SET GLOBAL time_zone = '-07:00'");
    } catch (Throwable $e) {
        pw_refuse("the database account may not change the database's own time zone (SET GLOBAL time_zone: " . $e->getMessage()
            . '). A W13 that silently ran on UTC would prove nothing, so this refuses rather than skip');
    }
    $pw['dbZoneSet'] = true;
    $probe = pw_plainConnect();
    $seen  = (string) ($probe->query('SELECT @@session.time_zone AS z')->fetch_assoc()['z'] ?? '');
    $probe->close();
    if ($seen !== '-07:00') {
        pw_refuse("a new connection sees the time zone '{$seen}', not '-07:00'");
    }

    // 🔑 Sign-ins, with a password made up for this run.
    $pw['password'] = bin2hex(random_bytes(12)) . 'Aa1!';
    $hash           = password_hash($pw['password'], PASSWORD_DEFAULT);
    // SB (a site administrator of organisation B ONLY) signs in too, since
    // chunk P8-3's fix round 1 (check finding M1): W39 proves the pages ask
    // "an administrator of THIS organisation?", not "an administrator
    // anywhere?".
    // PB (a member of organisation B ONLY, and nothing else) signs in for W59,
    // the #534 table: "a member of another organisation" must be exactly that,
    // not also an administrator somewhere, as SB is.
    // RH (holds A's role RO1) and XB (a member of A who holds only B's role,
    // user group and department) sign in for W60, part 10 of #514: who is
    // shown an event through a role, on the real calendar page.
    foreach (['sa' => SA, 'm' => M, 'la' => LA, 'ga' => GA, 'sgm' => SGM, 'p' => P_ID, 'sb' => SB, 'pb' => PB, 'rh' => RH_ID, 'xb' => XB_ID] as $who => $userId) {
        pa_q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$userId, 'p8w-' . $who, $hash]);
    }

    // 🚀 The server (challenge M7): the command as a LIST, so no shell sits in
    //    between and the process number is the server's own; one process (no
    //    PHP_CLI_SERVER_WORKERS), so nothing can outlive it.
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = 'prod';
    $log  = $child . $sep . 'server-output.log';
    $proc = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log',
            '-d', 'log_errors=1', '-d', 'display_errors=0',
            // W59's control changes ONE page of the scratch copy part way
            // through the run. PHP keeps compiled pages in memory (opcache)
            // and by default looks at a file again only every two seconds,
            // so the changed page could be answered from the old copy. These
            // two settings make it look every time. They change nothing a
            // page does; a PHP without opcache ignores them.
            '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=0',
            '-S', '127.0.0.1:' . $port,
            // `-t`: the server's own document root is the copy's web root
            // (fix round 1, L4; it used to be the scratch child).
            '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $child,
        $env
    );
    if (is_resource($proc) === false) {
        pw_refuse('the web server could not be started');
    }
    $pw['proc'] = $proc;
    $deadline   = microtime(true) + 10;
    while (pw_portAnswers($port) === false && microtime(true) < $deadline) {
        if (proc_get_status($proc)['running'] === false) {
            break;
        }
        usleep(100000);
    }
    if (pw_portAnswers($port) === false) {
        pw_refuse('the web server never answered on port ' . $port . ' (its own output: ' . substr((string) @file_get_contents($log), 0, 300) . ')');
    }
    $first = pw_req($child . $sep . 'jar-probe.txt', 'GET', '/');
    if ($pw['without'] !== []) {
        pw_refuse("the first answer on port {$port} did not carry this run's header — it came from a server this run did not start");
    }
    echo 'Part W: server started (process ' . (int) proc_get_status($proc)['pid'] . ", port {$port}, the closed port {$closed}); first answer: HTTP {$first['status']}.\n";
}

/**
 * Sign one account in through the real sign-in page and answer its cookie
 * jar. A sign-in that does not end in a redirect away from the sign-in page
 * is a refusal: every check after it would be about the wrong person.
 */
function pw_signIn(string $who): string
{
    $pw  =& $GLOBALS['pw'];
    $jar = $pw['child'] . DIRECTORY_SEPARATOR . 'jar-' . $who . '.txt';
    $get = pw_req($jar, 'GET', PW_A . '/login');
    $tok = pw_token($get['body']);
    $r   = pw_req($jar, 'POST', PW_A . '/login', ['csrf_token' => $tok, 'username' => 'p8w-' . $who, 'password' => $pw['password']]);
    if ($r['status'] !== 302 || str_contains($r['location'], 'login') === true) {
        pw_refuse("signing in as p8w-{$who} did not work (HTTP {$r['status']}, Location '{$r['location']}')");
    }
    $pw['jars'][$who] = $jar;

    return $jar;
}

/**
 * Put back tblMigrations' rows that W52 deletes EXACTLY as they were (chunk
 * P8-4): the same number, time and person. W52 deletes a row so the upgrade
 * page offers that migration again (206; since fix round 1 also 207), and
 * the upgrade then writes a NEW row (now, by the made-up global
 * administrator) — which this replaces with the original. Nothing happens
 * for a file W52 never recorded.
 */
function pw_restoreMigrations(): void
{
    foreach ((array) ($GLOBALS['pw']['migrationRows'] ?? []) as $row) {
        if (is_array($row) === false) {
            continue;
        }
        pa_q('DELETE FROM tblMigrations WHERE filename = ?', 's', [(string) $row['filename']]);
        pa_q(
            'INSERT INTO tblMigrations (migrationID, filename, executedAt, executedByID) VALUES (?, ?, ?, ?)',
            'issi', [(int) $row['migrationID'], (string) $row['filename'], (string) $row['executedAt'], $row['executedByID'] === null ? null : (int) $row['executedByID']]
        );
    }
}

/**
 * Put back everything part W changed, ONCE, whether the run ended normally,
 * with a refusal, or with a fatal error (it is also a shutdown function).
 * Every step is attempted even if one before it fails; what could not be
 * done is recorded for W0.
 */
function pw_cleanup(): void
{
    global $mysqli;
    $pw =& $GLOBALS['pw'];
    if ($pw['started'] === false || $pw['cleaned'] === true) {
        return;
    }
    $pw['cleaned'] = true;

    // 1. The server, by the process proc_open() gave — never by a name.
    if (is_resource($pw['proc']) === true) {
        try {
            proc_terminate($pw['proc']);
            $until = microtime(true) + 5;
            while (proc_get_status($pw['proc'])['running'] === true && microtime(true) < $until) {
                usleep(50000);
            }
            if (proc_get_status($pw['proc'])['running'] === true) {
                proc_terminate($pw['proc'], 9);
            }
            proc_close($pw['proc']);
        } catch (Throwable $e) {
            $pw['problems'][] = 'stopping the server: ' . $e->getMessage();
        }
        $pw['proc'] = null;
    }

    // 2. The settings and the database's own zone.
    foreach ($pw['settings'] as $key => $before) {
        try {
            if ($before === null) {
                pa_q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]);
            } else {
                pa_q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
            }
        } catch (Throwable $e) {
            $pw['problems'][] = "putting back {$key}: " . $e->getMessage();
        }
    }
    if ($pw['dbZoneSet'] === true) {
        try {
            $stmt = $mysqli->prepare('SET GLOBAL time_zone = ?');
            $stmt->bind_param('s', $pw['dbZone']);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            $pw['problems'][] = 'putting back the database time zone: ' . $e->getMessage();
        }
    }

    // 2b. The migration rows W52 deletes (chunk P8-4), in case the run
    //     stopped between W52 deleting one and putting it back.
    try {
        pw_restoreMigrations();
    } catch (Throwable $e) {
        $pw['problems'][] = 'putting back the migration rows: ' . $e->getMessage();
    }

    // 3. The made-up world, and the rows the run wrote about it. The error
    //    rows are only this run's (numbered after the start) and only about
    //    its own accounts or organisations.
    try {
        pa_q(
            'DELETE FROM tblErrors WHERE errorID > ? AND (userID BETWEEN 910000 AND 910099 OR siteID IN (?, ?))',
            'iii', [$pw['errorsFrom'], ORG_A, ORG_B]
        );
        pa_removeWorld();
    } catch (Throwable $e) {
        $pw['problems'][] = 'removing the made-up world: ' . $e->getMessage();
    }

    // 4. The scratch child — ONLY if it is the one this run made AND its
    //    marker still holds this run's token (challenge H2).
    if ($pw['childMade'] === true) {
        $child  = (string) $pw['child'];
        $prefix = $pw['parent'] . DIRECTORY_SEPARATOR . 'feed-admin-selftest-' . getmypid() . '-';
        $marker = @file_get_contents($child . DIRECTORY_SEPARATOR . '.feed-admin-selftest-owner');
        $pw['markerHeld'] = ($marker === $pw['token']);
        if (str_starts_with($child, $prefix) === true && $pw['markerHeld'] === true && is_link($child) === false) {
            pw_deleteTree($child);
        } else {
            $pw['problems'][] = 'the scratch folder was NOT deleted: it is not the one this run made, or its marker does not hold this run\'s token';
        }
        $pw['childRemoved'] = (file_exists($child) === false);
    }
}

/**
 * Part W: the real pages (the plan's section 11.3, chunk P8-2's W0-W19).
 * The checks run in the order that keeps each one's starting state simple
 * (the refusals first, while nothing has been changed), not in number order.
 */
function pw_run(): void
{
    global $mysqli;
    $pw =& $GLOBALS['pw'];
    // Refusals that need nothing made first come first of all.
    pw_preflight();
    // The clean-up is registered BEFORE anything is made or changed (the
    // world below included), so it also runs after a fatal error or a
    // refusal's exit(), which skip any `finally`.
    $pw['started'] = true;
    register_shutdown_function('pw_cleanup');

    // 🗺️ The made-up world, plus what the pages need on top of it.
    pa_resetWorld();
    pa_q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, 'calendar.enabled', 'true', 'false', 0)", 'i', [ORG_A]);
    // Chunk P8-4's inbox checks visit the Approvals app (plan 11.3): switched
    // on for A explicitly, rather than trusting the portal-wide default; W49
    // switches it off and on again.
    pa_q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, 'approvals.enabled', 'true', 'true', 0)", 'i', [ORG_A]);
    $ev1 = pa_event(FA1, 'w-one@p8', 'W15 title Qx7 one', ['tags' => ['wword']]);
    $ev2 = pa_event(FA2, 'w-two@p8', 'W18 title Zq3 public');
    // Worked out through the real write path: an unchanged save resolves
    // every event of the calendar in the same call.
    $set1 = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1));
    $set2 = FeedAdmin::saveCalendar($mysqli, FA2, ORG_A, SA, pa_calPost(FA2));
    if ($set1['ok'] !== true || $set2['ok'] !== true || pa_ls($ev1) !== 'members/calendar' || pa_ls($ev2) !== 'public/calendar') {
        pw_refuse('the made-up world did not come out as it must: ' . pa_ls($ev1) . ' / ' . pa_ls($ev2));
    }
    $month = substr((string) pa_one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$ev1])['startDateTime'], 0, 7);

    try {
        pw_setup();
        // 🚪 F2: part W asks the pages themselves. See pw_membersGate().
        pw_membersGate(false);
        $sa  = pw_signIn('sa');
        $m   = pw_signIn('m');
        $la  = pw_signIn('la');
        $ga  = pw_signIn('ga');
        $sgm = pw_signIn('sgm');
        $p   = pw_signIn('p');
        $out = $pw['child'] . DIRECTORY_SEPARATOR . 'jar-signed-out.txt';

        $list    = PW_A . '/admin/calendar/feeds';
        $save    = PW_A . '/admin/calendar/feeds/save';
        $edit    = PW_A . '/admin/calendar/feeds/edit';
        $view    = PW_A . '/admin/calendar/feeds/view';
        $actions = ['pause', 'resume', 'remove', 'refresh', 'categories'];

        // ---------------------------------------------------------------------
        pa_heading('W1 — the server answers, and the list shows the calendars');
        $w1 = pw_req($sa, 'GET', $list);
        pa_ok('W1 SA opens the calendar list: 200, with FA1\'s name on it', $w1['status'] === 200 && str_contains($w1['body'], 'Selftest calendar ' . FA1), 'HTTP ' . $w1['status']);

        // ---------------------------------------------------------------------
        pa_heading('W2 — an administrator opens every page');
        foreach ([$edit, $edit . '?id=' . FA1, $view . '?id=' . FA1] as $url) {
            $r = pw_req($sa, 'GET', $url);
            pa_ok('W2 SA: ' . $url . ' answers 200', $r['status'] === 200, 'HTTP ' . $r['status'] . ' ' . $r['location']);
        }

        // ---------------------------------------------------------------------
        pa_heading('W3 — nobody else gets in: a member, the old flag, a visitor');
        $fp3 = pa_fp();
        foreach (['M' => $m, 'LA' => $la] as $name => $jar) {
            $gets = [];
            foreach ([$list, $edit, $edit . '?id=' . FA1, $view . '?id=' . FA1] as $url) {
                $gets[] = pw_req($jar, 'GET', $url)['status'];
            }
            pa_ok("W3 {$name}: the list, the form (new and FA1) and FA1's page all answer 403", $gets === [403, 403, 403, 403], pa_show($gets));
            $posts = [];
            foreach ($actions as $action) {
                // A VALID token of this person's own session, read from the
                // page they were just refused — so the refusal is the
                // administrator check, not the token.
                $tok     = pw_freshToken($jar, $list);
                $posts[] = $action . ':' . pw_req($jar, 'POST', $save, ['csrf_token' => $tok, 'action' => $action, 'feedID' => FA1, 'back' => 'list', 'map' => [['word' => 'wword', 'choice' => (string) CA1]]])['status'];
            }
            $tok     = pw_freshToken($jar, $list);
            $posts[] = 'edit:' . pw_req($jar, 'POST', $edit . '?id=' . FA1, pa_calPost(FA1, ['csrf_token' => $tok, 'action' => 'save', 'name' => 'W3 should not save']))['status'];
            $tok     = pw_freshToken($jar, $list);
            $posts[] = 'add:' . pw_req($jar, 'POST', $edit, ['csrf_token' => $tok, 'action' => 'save', 'name' => 'W3 should not add', 'url' => 'https://p8-selftest.internal/w3.ics', 'fetchEveryMins' => '360', 'audienceLevel' => 'members'])['status'];
            pa_ok(
                "W3 {$name}: every button of feeds/save (with a valid token) and both form posts answer 403",
                $posts === ['pause:403', 'resume:403', 'remove:403', 'refresh:403', 'categories:403', 'edit:403', 'add:403'],
                pa_show($posts)
            );
        }
        pa_ok('W3 nothing was changed by any of those', pa_fp() === $fp3);
        // Signed out: the Router sends a visitor to the sign-in page before
        // the page runs. It is the ORGANISATION'S OWN sign-in page
        // ("/selftest-p8-a/login…"), with the address asked for coming back
        // through `redirect`. (This check used to expect the bare "/login",
        // which is what Auth::requireLogin() sent until commit 0c302af, #536
        // part 1, made the sign-in redirect keep the organisation's part of
        // the address, as the house rule asks. The code was right and this
        // expectation was out of date: it failed from that commit on.)
        $signIn = PW_A . '/login?redirect=';
        $w3out  = pw_req($out, 'GET', $list);
        pa_ok(
            'W3 signed out: a redirect to the organisation\'s own sign-in page, carrying the address asked for back',
            $w3out['status'] === 302 && str_starts_with($w3out['location'], $signIn) === true
            && urldecode(substr($w3out['location'], strlen($signIn))) === $list,
            'HTTP ' . $w3out['status'] . ' ' . $w3out['location']
        );

        // ---------------------------------------------------------------------
        pa_heading('W4 — another organisation\'s calendar looks exactly like one that does not exist');
        $fp4   = pa_fp();
        $pairs = [];
        foreach (['edit' => $edit . '?id=', 'view' => $view . '?id='] as $what => $prefix) {
            $b = pw_req($sa, 'GET', $prefix . FB1);
            $n = pw_req($sa, 'GET', $prefix . '919999');
            pa_ok(
                "W4 GET {$what} with B's calendar and with 919999: both 404, and the two pages are identical once the token and the nonce are taken out",
                $b['status'] === 404 && $n['status'] === 404 && pw_normalise($b['body']) === pw_normalise($n['body']) && $b['body'] !== '',
                'HTTP ' . $b['status'] . '/' . $n['status'] . ' same=' . (pw_normalise($b['body']) === pw_normalise($n['body']) ? 'yes' : 'no')
            );
        }
        $bPost = pw_req($sa, 'POST', $edit . '?id=' . FB1, pa_calPost(FB1, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'save', 'name' => 'W4 must not save BONLY']));
        $nPost = pw_req($sa, 'POST', $edit . '?id=919999', pa_calPost(FB1, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'save', 'name' => 'W4 must not save BONLY']));
        // The form looks the calendar up BEFORE it reads anything posted
        // (plan 3.2, rule 1), so a post to another organisation's calendar
        // gets the same 404 page as the GET — not a flash. The plan's W4
        // wording ("the same flash") fits the button handler; for this
        // self-posting form the equivalent is the same 404.
        pa_ok(
            'W4 POST the form to B\'s calendar and to 919999: both the same 404 page',
            $bPost['status'] === 404 && $nPost['status'] === 404 && pw_normalise($bPost['body']) === pw_normalise($nPost['body']),
            'HTTP ' . $bPost['status'] . '/' . $nPost['status']
        );
        foreach ($actions as $action) {
            $seen = [];
            foreach ([FB1, 919999] as $number) {
                $tok    = pw_freshToken($sa, $list);
                $r      = pw_req($sa, 'POST', $save, ['csrf_token' => $tok, 'action' => $action, 'feedID' => $number, 'back' => 'view', 'map' => [['word' => 'bonlyword', 'choice' => 'none']]]);
                $page   = pw_follow($sa, $r);
                $seen[] = [$r['status'], $r['location'], str_contains($page['body'], pw_esc('That calendar could not be found.'))];
            }
            pa_ok(
                "W4 feeds/save {$action} with B's calendar and with 919999: the same redirect (to the list) and the same \"That calendar could not be found.\"",
                $seen[0] === $seen[1] && $seen[0][0] === 302 && $seen[0][1] === $list && $seen[0][2] === true,
                pa_show($seen)
            );
        }
        pa_ok('W4 nothing was changed by any of those (B\'s calendar included)', pa_fp() === $fp4);

        // ---------------------------------------------------------------------
        pa_heading('W5 — every post needs the form token');
        $fp5       = pa_fp();
        $tokenText = pw_esc('Invalid or expired form token. Nothing was changed. Please try again.');
        foreach ($actions as $action) {
            $results = [];
            foreach (['none' => null, 'wrong' => str_repeat('0', 64)] as $kind => $bad) {
                $fields = ['action' => $action, 'feedID' => FA1, 'back' => 'view', 'map' => [['word' => 'wword', 'choice' => (string) CA1]]];
                if ($bad !== null) {
                    $fields['csrf_token'] = $bad;
                }
                pw_freshToken($sa, $list); // the session holds a real token, so the refusal is about THIS post's token
                $r         = pw_req($sa, 'POST', $save, $fields);
                $page      = pw_follow($sa, $r);
                $results[] = $kind . ':' . $r['status'] . ':' . ($r['location'] === $list ? 'list' : $r['location']) . ':' . (str_contains($page['body'], $tokenText) ? 'message' : 'no message');
            }
            pa_ok("W5 feeds/save {$action} with no token and with a wrong one: refused with the token sentence, back to the list", $results === ['none:302:list:message', 'wrong:302:list:message'], pa_show($results));
        }
        foreach (['new' => $edit, 'FA1' => $edit . '?id=' . FA1] as $label => $url) {
            $results = [];
            foreach (['none' => null, 'wrong' => str_repeat('0', 64)] as $kind => $bad) {
                $fields = pa_calPost(FA1, ['action' => 'save', 'name' => 'W5 must not save']);
                if ($bad !== null) {
                    $fields['csrf_token'] = $bad;
                }
                pw_freshToken($sa, $url);
                $r         = pw_req($sa, 'POST', $url, $fields);
                $page      = pw_follow($sa, $r);
                $results[] = $kind . ':' . $r['status'] . ':' . ($r['location'] === $url ? 'self' : $r['location']) . ':' . (str_contains($page['body'], $tokenText) ? 'message' : 'no message');
            }
            pa_ok("W5 the form ({$label}) with no token and with a wrong one: refused with the token sentence, back to the form", $results === ['none:302:self:message', 'wrong:302:self:message'], pa_show($results));
        }
        pa_ok('W5 nothing was changed by any of those', pa_fp() === $fp5);

        // ---------------------------------------------------------------------
        pa_heading('W7 — a refused form comes back with what was typed');
        $fp7 = pa_fp();
        $typedUrl = 'https://p8-selftest.internal/w7-typed.ics';
        $w7 = pw_req($sa, 'POST', $edit, [
            'csrf_token' => pw_freshToken($sa, $edit), 'action' => 'save', 'name' => '   ', 'url' => $typedUrl,
            'fetchEveryMins' => '360', 'timezone' => '', 'categoryID' => '', 'audienceLevel' => 'members',
        ]);
        pa_ok(
            'W7 an empty name: 200, "Please give the calendar a name." in an alert, the typed address still in its field, no "Database error."',
            $w7['status'] === 200 && str_contains($w7['body'], 'role="alert">' . pw_esc('Please give the calendar a name.'))
            && str_contains($w7['body'], 'value="' . pw_esc($typedUrl) . '"') && str_contains($w7['body'], 'Database error') === false,
            'HTTP ' . $w7['status']
        );
        pa_ok('W7 nothing was saved', pa_fp() === $fp7);

        // ---------------------------------------------------------------------
        pa_heading('W12 — every field has a label; the address is not remembered');
        foreach (['the new-calendar form' => $edit, 'FA1\'s form' => $edit . '?id=' . FA1, 'FA1\'s page' => $view . '?id=' . FA1] as $label => $url) {
            $r = pw_req($sa, 'GET', $url);
            $u = pw_unlabelled($r['body']);
            pa_ok("W12 {$label}: every visible input, select and text box has a label or an aria-label ({$u['count']} looked at)", $r['status'] === 200 && $u['bad'] === [] && $u['count'] > 0, implode(', ', $u['bad']));
        }
        $w12 = pw_req($sa, 'GET', $edit . '?id=' . FA1);
        pa_ok('W12 the calendar address field carries autocomplete="off"', preg_match('/<input[^>]*id="feed-url"[^>]*autocomplete="off"/s', $w12['body']) === 1);

        // ---------------------------------------------------------------------
        pa_heading('W10 — every address keeps the organisation\'s own prefix');
        foreach (['the list' => $list, 'the new-calendar form' => $edit, 'FA1\'s form' => $edit . '?id=' . FA1, 'FA1\'s page' => $view . '?id=' . FA1] as $label => $url) {
            $main = pw_main(pw_req($sa, 'GET', $url)['body']);
            preg_match_all('/\b(?:href|action)="([^"]*)"/', $main, $mm);
            $bad = [];
            $n   = 0;
            foreach ($mm[1] as $address) {
                $address = html_entity_decode($address, ENT_QUOTES, 'UTF-8');
                if (str_starts_with($address, '/') === false || str_starts_with($address, '/assets/') === true) {
                    continue;
                }
                $n++;
                if (str_starts_with($address, PW_A . '/') === false) {
                    $bad[] = $address;
                }
            }
            pa_ok("W10 {$label}: every link and form address ({$n}) starts with " . PW_A . '/', $main !== '' && $n > 0 && $bad === [], implode(', ', $bad));
        }
        // Three links followed from the list, found by what they point at:
        // FA1's "Open", FA1's "Settings" and "Add an outside calendar".
        $listMain = pw_main(pw_req($sa, 'GET', $list)['body']);
        preg_match_all('/href="([^"]*)"/', $listMain, $all);
        $hrefs    = array_map(static fn (string $h): string => html_entity_decode($h, ENT_QUOTES, 'UTF-8'), $all[1]);
        $followed = [];
        foreach ([$view . '?id=' . FA1, $edit . '?id=' . FA1, $edit] as $wanted) {
            $followed[] = in_array($wanted, $hrefs, true) === true ? pw_req($sa, 'GET', $wanted)['status'] : 'no such link on the list';
        }
        pa_ok('W10 three links followed from the list (Open, Settings, Add an outside calendar) each answer 200', $followed === [200, 200, 200], pa_show($followed));

        // ---------------------------------------------------------------------
        pa_heading('W6 — saving a calendar as Selected groups, not sent to API keys');
        $w6 = pw_req($sa, 'POST', $edit . '?id=' . FA1, pa_calPost(FA1, [
            'csrf_token' => pw_freshToken($sa, $edit . '?id=' . FA1), 'action' => 'save', 'audienceLevel' => 'groups', 'apiOptOut' => '1',
            'audience' => ['small_group' => [SG1], 'person' => [P_ID]],
        ]));
        $row6 = pa_one('SELECT audienceLevel, apiOptOut FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1]);
        pa_ok(
            'W6 SA saves FA1 as Selected groups {SG1, P} with "Don\'t show via API": 302 to ' . $view . '?id=' . FA1 . ', stored so',
            $w6['status'] === 302 && $w6['location'] === $view . '?id=' . FA1 && $row6['audienceLevel'] === 'groups' && (int) $row6['apiOptOut'] === 1,
            'HTTP ' . $w6['status'] . ' ' . $w6['location'] . ' ' . pa_show($row6)
        );
        $w6page = pw_follow($sa, $w6);
        pa_ok('W6 the calendar page shows "Saved."', str_contains($w6page['body'], 'role="alert">Saved.'));
        pa_ok(
            'W6 the list shows "Selected groups: 1 small group, 1 named person · not sent to API keys"',
            str_contains(pw_req($sa, 'GET', $list)['body'], pw_esc('Selected groups: 1 small group, 1 named person · not sent to API keys'))
        );

        // ---------------------------------------------------------------------
        pa_heading('W15 — who sees the event, end to end');
        $cal = PW_A . '/calendar?view=month&date=' . $month;
        $title1 = 'W15 title Qx7 one';
        pa_ok('W15 SGM (in SG1) sees FA1\'s event on the organisation\'s calendar page', str_contains(pw_req($sgm, 'GET', $cal)['body'], $title1));
        pa_ok('W15 P (named on the list) sees it', str_contains(pw_req($p, 'GET', $cal)['body'], $title1));
        pa_ok('W15 M (a member, in no chosen group) does not', str_contains(pw_req($m, 'GET', $cal)['body'], $title1) === false);
        pa_q('UPDATE tblUserSites SET isActive = 0 WHERE userID = ? AND siteID = ?', 'ii', [P_ID, ORG_A]);
        $pGone = str_contains(pw_req($p, 'GET', $cal)['body'], $title1);
        pa_q('UPDATE tblUserSites SET isActive = 1 WHERE userID = ? AND siteID = ?', 'ii', [P_ID, ORG_A]);
        pa_ok('W15 P, once their membership is switched off, does not', $pGone === false);

        // ---------------------------------------------------------------------
        pa_heading('W8 — Refresh now, with the database\'s clock seven hours behind UTC');
        $runsBefore = (int) pa_one('SELECT COUNT(*) AS n FROM tblExternalFeedRuns WHERE feedID = ?', 'i', [FA1])['n'];
        $w8 = pw_req($sa, 'POST', $save, ['csrf_token' => pw_freshToken($sa, $view . '?id=' . FA1), 'action' => 'refresh', 'feedID' => FA1, 'back' => 'view']);
        $runsAfter = (int) pa_one('SELECT COUNT(*) AS n FROM tblExternalFeedRuns WHERE feedID = ?', 'i', [FA1])['n'];
        $w8page = pw_follow($sa, $w8);
        pa_ok(
            'W8 SA presses Refresh now on FA1: 302 back to the calendar page, whose message is the importer\'s refusal of a .internal address; one new run row',
            $w8['status'] === 302 && $w8['location'] === $view . '?id=' . FA1
            && str_contains($w8page['body'], 'role="alert">' . pw_esc(SafeFetch::REFUSED_MESSAGE)) && $runsAfter === $runsBefore + 1,
            'HTTP ' . $w8['status'] . ' ' . $w8['location'] . " runs {$runsBefore}->{$runsAfter}"
        );
        $near = pa_one('SELECT ABS(TIMESTAMPDIFF(SECOND, lastFetchedAt, UTC_TIMESTAMP())) AS d FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1]);
        $plain = pw_plainConnect();
        $st    = $plain->prepare('SELECT TIMESTAMPDIFF(MINUTE, NOW(), lastFetchedAt) AS m, @@session.time_zone AS z FROM tblExternalFeeds WHERE feedID = ?');
        $fa1   = FA1;
        $st->bind_param('i', $fa1);
        $st->execute();
        $skew = $st->get_result()->fetch_assoc();
        $st->close();
        $plain->close();
        pa_ok(
            'W8 the refresh wrote lastFetchedAt in UTC: within 60 seconds of UTC_TIMESTAMP(), and about seven hours from NOW() on a -07:00 connection (challenge M4)',
            $near !== null && $near['d'] !== null && (int) $near['d'] <= 60 && ($skew['z'] ?? '') === '-07:00' && (int) $skew['m'] >= 415 && (int) $skew['m'] <= 425,
            pa_show([$near, $skew])
        );
        pa_ok(
            'W8 the History section lists the run as "Failed", with the plain-English reason',
            preg_match('/<section id="history".*?' . preg_quote('>Failed<', '/') . '.*?' . preg_quote(pw_esc(SafeFetch::REFUSED_MESSAGE), '/') . '.*?<\/section>/s', $w8page['body']) === 1
        );

        // ---------------------------------------------------------------------
        pa_heading('W13 — "Last tried" is the newest run, in the organisation\'s own time');
        $run13  = pa_one('SELECT startedAt FROM tblExternalFeedRuns WHERE feedID = ? ORDER BY startedAt DESC, runID DESC LIMIT 1', 'i', [FA1]);
        $london = new DateTimeZone(SITE_ZONE);
        $want13 = 'Last tried ' . pw_esc(FeedAdmin::showMoment((string) $run13['startedAt'], $london));
        $wrong  = 'Last tried ' . (new DateTimeImmutable((string) $run13['startedAt'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('-07:00'))->format('D j M Y, H:i');
        $listBody13 = pw_req($sa, 'GET', $list)['body'];
        $viewBody13 = pw_req($sa, 'GET', $view . '?id=' . FA1)['body'];
        pa_ok(
            'W13 the list and the calendar page both show "' . html_entity_decode($want13) . '" (the run\'s UTC start in London time, with its abbreviation), never the -07:00 hour',
            str_contains($listBody13, $want13) && str_contains($viewBody13, $want13)
            && str_contains($listBody13, $wrong) === false && str_contains($viewBody13, $wrong) === false,
            'wanted ' . $want13 . ' / must not show ' . $wrong
        );
        // A hand-set run on the most recent 1 July (London is always on
        // summer time then), worked out from today so it never goes stale.
        $today13 = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $julyYear = ((int) $today13->format('n') >= 8) ? (int) $today13->format('Y') : (int) $today13->format('Y') - 1;
        $summer  = sprintf('%04d-07-01 12:00:00', $julyYear);
        pa_q(
            "INSERT INTO tblExternalFeedRuns (feedID, siteID, startedAt, finishedAt, outcome, message, awaitingApproval, triggeredBy) VALUES (?, ?, ?, ?, 'ok', 'W13 summer run', 0, 'schedule')",
            'iiss', [FA1, ORG_A, $summer, $summer]
        );
        $hist = pw_req($sa, 'GET', $view . '?id=' . FA1)['body'];
        pa_ok(
            'W13 a hand-set run at ' . $summer . ' UTC shows in History as 13:00 BST, London\'s summer time',
            str_contains($hist, pw_esc(FeedAdmin::showMoment($summer, $london))) && str_contains($hist, ', 13:00 BST'),
            FeedAdmin::showMoment($summer, $london)
        );

        // ---------------------------------------------------------------------
        pa_heading('W9 — waiting and stale counts are live');
        $w9cal = pa_newFeed(ORG_A, 'members', ['name' => 'W9 calendar']);
        $w9a   = pa_event($w9cal, 'w9-a@p8', 'W9 one');
        $w9b   = pa_event($w9cal, 'w9-b@p8', 'W9 two');
        $r9    = FeedAdmin::saveRule($mysqli, $w9cal, 0, ORG_A, SA, ['name' => 'W9 rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W9 ']]]);
        pa_run($w9cal, 'W9 run', 0);
        pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE eventID = ?', 'i', [$w9a]);
        $pending9 = (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE feedID = ? AND status = 'pending'", 'i', [$w9cal])['n'];
        $listBody9 = pw_req($sa, 'GET', $list)['body'];
        $rowStart  = strpos($listBody9, 'id="feed-' . $w9cal . '"');
        $rowText   = ($rowStart === false) ? '' : substr($listBody9, $rowStart, 6000);
        $nextRow   = strpos($rowText, 'class="portal-data-row"', 20);
        $rowText   = ($nextRow === false) ? $rowText : substr($rowText, 0, $nextRow);
        pa_ok(
            'W9 with two waiting rows (a real rule) and the run row saying 0: the list\'s row for that calendar shows "2 waiting for approval"',
            $r9['ok'] === true && $pending9 === 2 && str_contains($rowText, '2 waiting for approval'),
            "rule ok=" . var_export($r9['ok'], true) . " pending={$pending9}"
        );
        pa_ok(
            'W9 with one stored answer run out: the calendar page shows "1 event is waiting for the scheduled refresh to update who can see it."',
            str_contains(pw_req($sa, 'GET', $view . '?id=' . $w9cal)['body'], pw_esc('1 event is waiting for the scheduled refresh to update who can see it.'))
        );

        // ---------------------------------------------------------------------
        pa_heading('W16 — category matching, end to end');
        $v16 = pw_req($sa, 'GET', $view . '?id=' . FA1)['body'];
        $idx = (preg_match('/name="map\[(\d+)\]\[word\]" value="wword"/', $v16, $mi) === 1) ? $mi[1] : null;
        $w16 = ($idx === null) ? ['status' => 0, 'location' => ''] : pw_req($sa, 'POST', $save, [
            'csrf_token' => pw_token($v16), 'action' => 'categories', 'feedID' => FA1, 'back' => 'view',
            'map' => [$idx => ['word' => 'wword', 'choice' => (string) CA1]],
        ]);
        pa_ok(
            'W16 the word "wword" is on FA1\'s page; saving it as CA1 goes back to the page\'s #categories',
            $idx !== null && $w16['status'] === 302 && $w16['location'] === $view . '?id=' . FA1 . '#categories' && (int) (pa_ev($ev1)['categoryID'] ?? 0) === CA1,
            'HTTP ' . $w16['status'] . ' ' . $w16['location'] . ' categoryID=' . (string) (pa_ev($ev1)['categoryID'] ?? 'null')
        );
        $slug = 'imp-selftest-p8-' . $ev1;
        pa_ok('W16 the event\'s own page shows CA1\'s name', str_contains(pw_req($sa, 'GET', PW_A . '/calendar/event?slug=' . $slug)['body'], 'Selftest CA1'));
        pa_ok(
            'W16 the calendar page\'s category filter for CA1 lists the event, and the filter for CA2 does not',
            str_contains(pw_req($sa, 'GET', $cal . '&category=' . CA1)['body'], $title1) && str_contains(pw_req($sa, 'GET', $cal . '&category=' . CA2)['body'], $title1) === false
        );

        // ---------------------------------------------------------------------
        pa_heading('W11 — a global administrator manages another organisation');
        $bList = PW_B . '/admin/calendar/feeds';
        $w11   = pw_req($ga, 'GET', $bList);
        pa_ok('W11 GA opens ' . $bList . ': 200, with FB1 on it', $w11['status'] === 200 && str_contains($w11['body'], 'Selftest calendar BONLY'), 'HTTP ' . $w11['status']);
        $bEdit = PW_B . '/admin/calendar/feeds/edit?id=' . FB1;
        $w11s  = pw_req($ga, 'POST', $bEdit, pa_calPost(FB1, ['csrf_token' => pw_freshToken($ga, $bEdit), 'action' => 'save', 'name' => 'W11 renamed BONLY']));
        pa_ok(
            'W11 GA saves FB1\'s settings: 302 to B\'s own address for FB1\'s page, and the name is stored',
            $w11s['status'] === 302 && $w11s['location'] === PW_B . '/admin/calendar/feeds/view?id=' . FB1
            && (string) pa_one('SELECT name FROM tblExternalFeeds WHERE feedID = ?', 'i', [FB1])['name'] === 'W11 renamed BONLY',
            'HTTP ' . $w11s['status'] . ' ' . $w11s['location']
        );

        // ---------------------------------------------------------------------
        pa_heading('W18 — pause, resume and delete');
        $cal2   = PW_A . '/calendar?view=month&date=' . substr((string) pa_one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$ev2])['startDateTime'], 0, 7);
        $title2 = 'W18 title Zq3 public';
        $before18 = str_contains(pw_req($out, 'GET', $cal2)['body'], $title2);
        $pause  = pw_req($sa, 'POST', $save, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'pause', 'feedID' => FA2, 'back' => 'list']);
        $paused = (int) pa_one('SELECT isActive FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA2])['isActive'];
        $hidden = str_contains(pw_req($out, 'GET', $cal2)['body'], $title2) === false;
        pa_ok(
            'W18 SA pauses FA2: 302 to the list, isActive = 0, and its public event (seen by a signed-out visitor before) is gone from the calendar page',
            $before18 === true && $pause['status'] === 302 && strtok($pause['location'], '#') === $list && $paused === 0 && $hidden === true,
            pa_show([$before18, $pause['status'], $pause['location'], $paused, $hidden])
        );
        $resume = pw_req($sa, 'POST', $save, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'resume', 'feedID' => FA2, 'back' => 'list']);
        pa_ok(
            'W18 resume: 302 to the list, isActive = 1, and the event is back',
            $resume['status'] === 302 && strtok($resume['location'], '#') === $list
            && (int) pa_one('SELECT isActive FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA2])['isActive'] === 1
            && str_contains(pw_req($out, 'GET', $cal2)['body'], $title2),
            pa_show([$resume['status'], $resume['location']])
        );
        $del   = pa_newFeed(ORG_A, 'public', ['name' => 'W18 throwaway']);
        $delEv = pa_event($del, 'w18-del@p8', 'W18 throwaway event');
        pa_q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response) VALUES (?, ?, ?, 'going')", 'iii', [$delEv, M, ORG_A]);
        $gone  = pw_req($sa, 'POST', $save, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'remove', 'feedID' => $del, 'back' => 'list']);
        pa_ok(
            'W18 delete a throwaway calendar: 302 to the list; the calendar, its event and the RSVP on it are all gone',
            $gone['status'] === 302 && $gone['location'] === $list
            && pa_one('SELECT feedID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$del]) === null
            && pa_one('SELECT eventID FROM tblEvents WHERE eventID = ?', 'i', [$delEv]) === null
            && pa_one('SELECT rsvpID FROM tblEventRSVPs WHERE eventID = ?', 'i', [$delEv]) === null,
            pa_show([$gone['status'], $gone['location']])
        );

        // ---------------------------------------------------------------------
        pa_heading('W19 — adding a calendar and changing an address, with no network');
        $add = pw_req($sa, 'POST', $edit, [
            'csrf_token' => pw_freshToken($sa, $edit), 'action' => 'save', 'name' => 'P8 added', 'url' => 'http://p8-selftest.test/a.ics',
            'fetchEveryMins' => '360', 'timezone' => '', 'categoryID' => '', 'audienceLevel' => 'members',
        ]);
        $newId  = (preg_match('#^' . preg_quote($view, '#') . '\?id=(\d+)$#', $add['location'], $mn) === 1) ? (int) $mn[1] : 0;
        $newRow = ($newId > 0) ? pa_one('SELECT siteID, name, audienceLevel FROM tblExternalFeeds WHERE feedID = ?', 'i', [$newId]) : null;
        $newRuns = ($newId > 0) ? pa_q('SELECT outcome, message FROM tblExternalFeedRuns WHERE feedID = ?', 'i', [$newId]) : [];
        $newPage = pw_follow($sa, $add);
        $newMsg  = (string) ($newRuns[0]['message'] ?? '');
        pa_ok(
            'W19 SA adds "P8 added" at http://p8-selftest.test/a.ics: 302 to the NEW calendar\'s page, which answers 200; stored at Members for organisation A',
            $add['status'] === 302 && $newId > 0 && $newPage['status'] === 200 && $newRow !== null
            && (int) $newRow['siteID'] === ORG_A && $newRow['name'] === 'P8 added' && $newRow['audienceLevel'] === 'members',
            'HTTP ' . $add['status'] . ' ' . $add['location'] . ' ' . pa_show($newRow)
        );
        pa_ok(
            'W19 exactly one failed run row (the download refused at once — no network), and the message reads "Calendar added. " + that run\'s sentence',
            count($newRuns) === 1 && ($newRuns[0]['outcome'] ?? '') === 'failed' && $newMsg !== ''
            && str_contains($newPage['body'], 'role="alert">' . pw_esc('Calendar added. ' . $newMsg)),
            pa_show($newRuns)
        );
        // One of FA1's dates approved under the CURRENT request, through a
        // real choice save with the date seen on the page.
        $c19 = FeedAdmin::saveChoice($mysqli, $ev1, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [$ev1 . ':' . pa_cf($ev1)]]);
        // FA1 was refreshed a moment ago (W8); a manual refresh within a minute
        // of the last is refused ("refreshed a moment ago"), which would skip
        // the refresh this check needs. So the TEST moves FA1's history back
        // ten minutes — a change to test data only.
        pa_q('UPDATE tblExternalFeedRuns SET startedAt = startedAt - INTERVAL 10 MINUTE WHERE feedID = ?', 'i', [FA1]);
        pa_q("UPDATE tblExternalFeeds SET lastContentHash = SHA2('w19', 256) WHERE feedID = ?", 'i', [FA1]);
        $addr = pw_req($sa, 'POST', $edit . '?id=' . FA1, pa_calPost(FA1, [
            'csrf_token' => pw_freshToken($sa, $edit . '?id=' . FA1), 'action' => 'save', 'url' => 'http://p8-selftest.test/b.ics',
            'audience' => ['small_group' => [SG1], 'person' => [P_ID]],
        ]));
        $addrPage = pw_follow($sa, $addr);
        $last19   = pa_one('SELECT outcome, message FROM tblExternalFeedRuns WHERE feedID = ? ORDER BY runID DESC LIMIT 1', 'i', [FA1]);
        $feed19   = pa_one('SELECT url, lastContentHash FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1]);
        pa_ok(
            'W19 SA changes FA1\'s address while one date is approved: the message says "1 widened event went back for approval because the address changed." and gives the refresh\'s failure sentence; the file fingerprint is emptied',
            $c19['ok'] === true && $addr['status'] === 302 && $addr['location'] === $view . '?id=' . FA1
            && str_contains($addrPage['body'], pw_esc('Saved. 1 widened event went back for approval because the address changed. ' . (string) ($last19['message'] ?? '')))
            && ($last19['outcome'] ?? '') === 'failed' && $feed19['url'] === 'http://p8-selftest.test/b.ics' && $feed19['lastContentHash'] === null,
            pa_show([$c19['ok'], $addr['status'], $addr['location'], $last19, $feed19])
        );

        // ---------------------------------------------------------------------
        pa_heading('W20 — the "Partly read" badge (chunk P8-2\'s follow-up)');
        // Two calendars, each with its refreshes recorded as the importer
        // records them: one whose NEWEST reading was capped, one whose capped
        // reading is OLDER and was followed by a full one. The badge belongs
        // to the first only. On the calendar page the badge sits beside the
        // name, so only the part before the History section is read (History
        // itself rightly labels every capped refresh "Partly read").
        $capFeed  = pa_newFeed(ORG_A, 'members', ['name' => 'W20 capped newest']);
        $fullFeed = pa_newFeed(ORG_A, 'members', ['name' => 'W20 capped then full']);
        pa_capRun($capFeed, ORG_A, 'ok', 120);
        pa_capRun($capFeed, ORG_A, 'partial', 60);
        pa_capRun($fullFeed, ORG_A, 'partial', 120);
        pa_capRun($fullFeed, ORG_A, 'ok', 60);
        $list20 = pw_req($sa, 'GET', $list)['body'];
        $row20  = static function (string $body, int $feedId): string {
            $at = strpos($body, 'id="feed-' . $feedId . '"');
            if ($at === false) {
                return '';
            }
            $text = substr($body, $at, 8000);
            $next = strpos($text, 'class="portal-data-row"', 20);

            return ($next === false) ? $text : substr($text, 0, $next);
        };
        $head20 = static function (string $body): string {
            $at = strpos($body, '<section id="history"');

            return ($at === false) ? '' : substr($body, 0, $at);
        };
        $capView  = pw_req($sa, 'GET', $view . '?id=' . $capFeed)['body'];
        $fullView = pw_req($sa, 'GET', $view . '?id=' . $fullFeed)['body'];
        $badge    = 'data-badge="partly-read"';
        pa_ok(
            'W20 newest reading capped: "Partly read" on the calendar\'s list row and beside its name on its own page',
            str_contains($row20($list20, $capFeed), $badge) && str_contains($head20($capView), $badge) && str_contains($head20($capView), '>Partly read</span>'),
            'list row: ' . (str_contains($row20($list20, $capFeed), $badge) ? 'badge' : 'no badge') . ', page: ' . (str_contains($head20($capView), $badge) ? 'badge' : 'no badge')
        );
        pa_ok(
            'W20 an older capped reading then a full one: no badge on the list row or beside the name (its History still lists the older capped refresh)',
            $row20($list20, $fullFeed) !== '' && str_contains($row20($list20, $fullFeed), $badge) === false && $head20($fullView) !== ''
            && str_contains($head20($fullView), $badge) === false && str_contains(substr($fullView, (int) strpos($fullView, '<section id="history"')), '>Partly read<'),
            'list row: ' . (str_contains($row20($list20, $fullFeed), $badge) ? 'badge' : 'no badge') . ', page: ' . (str_contains($head20($fullView), $badge) ? 'badge' : 'no badge')
        );

        // ---------------------------------------------------------------------
        pa_heading('W21 — outside and organisation text is escaped on every page (fix round 1, M2; grown in chunks P8-3 and P8-4)');
        // Every piece of text these pages show that somebody OUTSIDE the page
        // wrote — an outside calendar's event titles, category words, time
        // zones and messages, and the organisation's own names for its
        // categories, rules, groups, roles and people, the calendar's name and
        // address, a refusal that quotes a posted word, and a refused form's
        // typed values — gets its own hostile text, numbered so each can be
        // found: `<script>`, an attribute break-out `"><img onerror>`, and
        // `<svg onload>`. Each page must show every piece it displays only in
        // its escaped form, and none of the raw forms anywhere. (The check of
        // 28 September 2026 found that removing an escape from an event
        // title, a category word, a run message or a person's name in the
        // picker passed every committed check; its own probe is the model.)
        $hostile = static fn (int $n): string => '<script>alert(' . $n . ')</script>"><img src=x onerror=alert(' . $n . ')><svg onload=alert(' . $n . ')>';
        $short   = static fn (int $n): string => '"><svg onload=alert(' . $n . ')>'; // for the 50- and 64-character zone columns
        $probe   = pa_newFeed(ORG_A, 'groups', [
            'name'     => 'W21 ' . $hostile(101),
            'url'      => 'https://p8-selftest.internal/w21.ics?x=' . $hostile(102),
            'timezone' => 'Z' . $short(103),
        ]);
        pa_q('UPDATE tblExternalFeeds SET lastFetchMessage = ?, lastFetchOk = 0 WHERE feedID = ?', 'si', ['Msg ' . $hostile(104), $probe]);
        pa_q(
            "INSERT INTO tblExternalFeedRuns (feedID, siteID, startedAt, finishedAt, outcome, message, awaitingApproval, triggeredBy) "
            . "VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'failed', ?, 0, 'manual')",
            'iis', [$probe, ORG_A, 'Run ' . $hostile(105)]
        );
        $w21ev = pa_event($probe, 'w21@p8', 'Title ' . $hostile(106), ['tags' => ['w' . $hostile(107)]]);
        pa_q('UPDATE tblEvents SET timezone = ?, categoryID = ? WHERE eventID = ?', 'sii', ['E' . $short(108), CA2, $w21ev]);
        pa_q('UPDATE tblEventCategories SET categoryName = ? WHERE categoryID = ?', 'si', ['Cat ' . $hostile(109), CA2]);
        $w21rule = pa_one('SELECT COALESCE(MAX(ruleID), 919000) + 1 AS n FROM tblExternalFeedRules')['n'];
        pa_q(
            "INSERT INTO tblExternalFeedRules (ruleID, siteID, feedID, name, isActive, audienceLevel, createdAt) VALUES (?, ?, ?, ?, 1, 'members', UTC_TIMESTAMP())",
            'iiis', [(int) $w21rule, ORG_A, $probe, 'Rule ' . $hostile(110)]
        );
        pa_q("UPDATE tblEvents SET importSource = 'rule', importSourceID = ? WHERE eventID = ?", 'ii', [(int) $w21rule, $w21ev]);
        pa_q('UPDATE tblSmallGroups SET groupName = ? WHERE groupID = ?', 'si', ['Grp ' . $hostile(111), SG1]);
        pa_q('UPDATE tblSmallGroups SET groupName = ? WHERE groupID = ?', 'si', ['Off ' . $hostile(112), SG2]);
        pa_q('UPDATE tblUsers SET fullName = ? WHERE userID = ?', 'si', ['Person ' . $hostile(113), P_ID]);
        pa_q('UPDATE tblLeadershipRoles SET roleName = ? WHERE roleID = ?', 'si', ['Role ' . $hostile(114), RA1]);
        // The calendar's saved list: SG1, P, and the switched-off SG2 (drawn
        // under "No longer in effect" on the form, and in "Chosen:" on its page).
        pa_list($probe, 'feed', $probe, [['small_group', SG1], ['person', P_ID], ['small_group', SG2]]);
        // 🧩 Chunk P8-3 (carried from P8-2's check round 2, item 1). The
        //    first W21 never put hostile text in three places that can show
        //    outside text — removing their escape passed every check:
        //    G1 the LIST's message box (a refusal quoting a posted word, sent
        //       back to the list);
        //    G2 the list's "Last result:" and G3 the calendar page's — both
        //       shown for a calendar that has a message but no history row
        //       yet (after an upgrade, say), so a second calendar is made
        //       with exactly that;
        //    and the new pages of chunk P8-3: the rules section (a rule's
        //    condition, 118), the choice page, a refused choice (the typed
        //    note, 119), the rule page, and a rule preview (the typed name
        //    120, an exception's value 122, and the matched event's title).
        //    G4, the calendar FORM's refusal box, cannot be filled from
        //    here: none of that form's refusals quotes what was typed. Part
        //    S's S4 reads every message box's source instead.
        pa_q(
            "INSERT INTO tblExternalRuleConditions (ruleID, isException, matchField, matchType, matchValue) VALUES (?, 0, 'title', 'contains', ?)",
            'is', [(int) $w21rule, 'Cond ' . $hostile(118)]
        );
        $probe2 = pa_newFeed(ORG_A, 'members', ['name' => 'W21 never refreshed']);
        pa_q('UPDATE tblExternalFeeds SET lastFetchMessage = ?, lastFetchOk = 0 WHERE feedID = ?', 'si', ['Msg2 ' . $hostile(123), $probe2]);
        // 🧩 Chunk P8-4: the waiting dates (the Approvals inbox and the
        //    calendar page's "Waiting for approval"), and the preview of a
        //    request and of a date. A calendar at Selected groups with SG3
        //    only (125) — a calendar cannot be "Only administrators" — so
        //    that BOTH a rule asking for Everyone at full detail and a choice
        //    naming people outside SG3 are wider, and both wait: the rule's row
        //    shows the hostile title (126) and — on its preview — the
        //    description (127, with a line break), the location (128), the
        //    link (129) and the category (109); the choice's row is a second
        //    date (130) and names the hostile SG1 (111) and P (113).
        // The category (109) is the calendar's own default: the resolver gives
        // an event its category from the category map or, failing that, the
        // calendar's default — never from what the event row held before.
        $probe4 = pa_newFeed(ORG_A, 'groups', ['name' => 'W21 P84 ' . $hostile(125), 'categoryID' => CA2]);
        pa_list($probe4, 'feed', $probe4, [['small_group', SG3]]);
        $w21r   = pa_event($probe4, 'w21-p84r@p8', 'Rule84 ' . $hostile(126), [
            'description' => 'Desc ' . $hostile(127) . "\nSecond line", 'location' => 'Loc ' . $hostile(128),
            'url' => 'https://example.invalid/p84?' . $hostile(129),
        ]);
        $w21c   = pa_event($probe4, 'w21-p84c@p8', 'Choice84 ' . $hostile(130));
        $w21rr  = FeedAdmin::saveRule($mysqli, $probe4, 0, ORG_A, SA, ['name' => 'W21 P84 rule', 'audienceLevel' => 'public', 'detailLevel' => 'full', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'Rule84']]]);
        $w21cc  = FeedAdmin::saveChoice($mysqli, $w21c, 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [P_ID]]]);
        $w21pr  = pa_pending($w21r);
        $w21pc  = pa_pending($w21c);
        if ($w21rr['ok'] !== true || $w21cc['ok'] !== true || $w21pr === null || $w21pc === null) {
            pw_refuse('W21\'s waiting dates could not be built: ' . pa_show([$w21rr, $w21cc, $w21pr, $w21pc]));
        }

        $pages = [
            'the list'              => pw_req($sa, 'GET', $list)['body'],
            'the calendar\'s page'  => pw_req($sa, 'GET', $view . '?id=' . $probe)['body'],
            'the calendar\'s form'  => pw_req($sa, 'GET', $edit . '?id=' . $probe)['body'],
        ];
        // A FeedAdmin refusal that quotes a posted word, shown on the page.
        $tok = pw_token($pages['the calendar\'s page']);
        $pages['a refusal quoting a word'] = pw_follow($sa, pw_req($sa, 'POST', $save, [
            'csrf_token' => $tok, 'action' => 'categories', 'feedID' => $probe, 'back' => 'view',
            'map' => [['word' => 'q' . $hostile(115), 'choice' => '919999']],
        ]))['body'];
        // A refused form, drawn again with what was typed.
        $pages['a refused form'] = pw_req($sa, 'POST', $edit . '?id=' . $probe, [
            'csrf_token' => pw_freshToken($sa, $edit . '?id=' . $probe), 'action' => 'save', 'name' => 'Typed ' . $hostile(116),
            'url' => 'https://p8-selftest.internal/typed.ics?y=' . $hostile(117), 'fetchEveryMins' => '360', 'timezone' => '',
            'categoryID' => '919999', 'audienceLevel' => 'members',
        ])['body'];
        // G1: the same kind of refusal, sent back to the LIST.
        $pages['the list, showing a refusal that quotes a word'] = pw_follow($sa, pw_req($sa, 'POST', $save, [
            'csrf_token' => pw_freshToken($sa, $list), 'action' => 'categories', 'feedID' => $probe, 'back' => 'list',
            'map' => [['word' => 'l' . $hostile(124), 'choice' => '919999']],
        ]))['body'];
        // G3: a calendar with a message and no history row.
        $pages['the page of a calendar with no history'] = pw_req($sa, 'GET', $view . '?id=' . $probe2)['body'];
        // The choice page, and a refused choice drawn again with the typed note
        // ("Selected groups" with nobody chosen is refused before the note is
        // read, and the page keeps every typed value).
        $choiceW21 = PW_A . '/admin/calendar/feeds/choice?event=' . $w21ev . '&scope=date';
        $pages['the choice page'] = pw_req($sa, 'GET', $choiceW21)['body'];
        $pages['a refused choice'] = pw_req($sa, 'POST', $choiceW21, [
            'csrf_token' => pw_token($pages['the choice page']), 'action' => 'save', 'audienceLevel' => 'groups', 'detailLevel' => 'basic',
            'note' => 'Note ' . $hostile(119),
        ])['body'];
        // The rule page for the probe's rule, and a preview of a draft whose
        // one condition matches the probe's event (its title starts "Title").
        $ruleW21 = PW_A . '/admin/calendar/feeds/rule?feed=' . $probe;
        $pages['the rule page'] = pw_req($sa, 'GET', $ruleW21 . '&rule=' . (int) $w21rule)['body'];
        $pages['a rule preview'] = pw_req($sa, 'POST', $ruleW21, [
            'csrf_token' => pw_token($pages['the rule page']), 'action' => 'preview', 'name' => 'Draft ' . $hostile(120), 'isActive' => '1',
            'audienceLevel' => 'members', 'detailLevel' => 'basic',
            'conditions' => [
                0  => ['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'Title', 'isException' => '0'],
                10 => ['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'Exc ' . $hostile(122), 'isException' => '1'],
            ],
        ])['body'];
        // A choice for every date left over after an event stopped repeating
        // (fix round 4 of chunk P8-3, check finding R4-2): its box names the
        // hostile SG1 and P from its list, on the one date's own page and on
        // its "every date" page (which has no picker, so 111 and 113 there
        // come from the box alone). Built the way the importer leaves it:
        // the old dates deleted, one date with the same UID and no key.
        $lo21 = pa_newFeed(ORG_A, 'members', ['name' => 'W21 leftover calendar']);
        [$lo21a, $lo21b] = pa_series($lo21, 'w21-left@p8', 'W21 leftover club', ['count' => 2, 'day' => 150]);
        $lo21set = FeedAdmin::saveCalendar($mysqli, $lo21, ORG_A, SA, pa_calPost($lo21));
        $lo21c   = FeedAdmin::saveChoice($mysqli, $lo21a, 'series', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [P_ID]]]);
        pa_q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID IN (?, ?)', 'ii', [$lo21a, $lo21b]);
        $lo21one = pa_event($lo21, 'w21-left@p8', 'W21 leftover club', ['day' => 151]);
        $lo21re  = FeedAdmin::saveCalendar($mysqli, $lo21, ORG_A, SA, pa_calPost($lo21));
        if ($lo21set['ok'] !== true || $lo21c['ok'] !== true || $lo21re['ok'] !== true) {
            pw_refuse('W21\'s leftover choice could not be built: ' . pa_show([$lo21set['ok'], $lo21c, $lo21re['ok']]));
        }
        $pages['the leftover\'s date page']       = pw_req($sa, 'GET', PW_A . '/admin/calendar/feeds/choice?event=' . $lo21one . '&scope=date')['body'];
        $pages['the leftover\'s every-date page'] = pw_req($sa, 'GET', PW_A . '/admin/calendar/feeds/choice?event=' . $lo21one . '&scope=series')['body'];
        // Chunk P8-4's pages (see $probe4 above).
        $pages['the Approvals inbox']                       = pw_req($sa, 'GET', PW_A . '/approvals')['body'];
        $pages['the calendar page\'s waiting dates']        = pw_req($sa, 'GET', $view . '?id=' . $probe4)['body'];
        $pages['the preview of a request']                  = pw_req($sa, 'GET', PW_A . '/admin/calendar/feeds/preview?approval=' . (int) $w21pr['approvalID'])['body'];
        $pages['the preview of a Selected-groups request']  = pw_req($sa, 'GET', PW_A . '/admin/calendar/feeds/preview?approval=' . (int) $w21pc['approvalID'])['body'];
        $pages['the preview of a date']                     = pw_req($sa, 'GET', PW_A . '/admin/calendar/feeds/preview?event=' . $w21c)['body'];
        // Which pieces each page must show (escaped): [number, long or short].
        // The choice and rule pages show the organisation's switched-on groups,
        // roles and people in their picker (111, 114, 113); SG2 (112) is
        // switched off and on neither page's list, so it is not offered there.
        $expect = [
            'the list'                 => [[101, 'l'], [102, 'l'], [104, 'l'], [123, 'l']],
            'the calendar\'s page'     => [[101, 'l'], [102, 'l'], [104, 'l'], [105, 'l'], [106, 'l'], [107, 'l'], [108, 's'], [109, 'l'], [110, 'l'], [111, 'l'], [112, 'l'], [113, 'l'], [118, 'l']],
            'the calendar\'s form'     => [[101, 'l'], [102, 'l'], [103, 's'], [109, 'l'], [111, 'l'], [112, 'l'], [113, 'l'], [114, 'l']],
            'a refusal quoting a word' => [[115, 'l']],
            'a refused form'           => [[116, 'l'], [117, 'l']],
            'the list, showing a refusal that quotes a word' => [[124, 'l']],
            'the page of a calendar with no history'         => [[123, 'l']],
            'the choice page'          => [[101, 'l'], [106, 'l'], [109, 'l'], [110, 'l'], [111, 'l'], [113, 'l'], [114, 'l']],
            'a refused choice'         => [[119, 'l']],
            'the rule page'            => [[101, 'l'], [110, 'l'], [111, 'l'], [113, 'l'], [114, 'l'], [118, 'l']],
            'a rule preview'           => [[101, 'l'], [106, 'l'], [120, 'l'], [122, 'l']],
            'the leftover\'s date page'       => [[111, 'l'], [113, 'l']],
            'the leftover\'s every-date page' => [[111, 'l'], [113, 'l']],
            // Chunk P8-4.
            'the Approvals inbox'                      => [[111, 'l'], [113, 'l'], [125, 'l'], [126, 'l'], [130, 'l']],
            'the calendar page\'s waiting dates'       => [[111, 'l'], [113, 'l'], [125, 'l'], [126, 'l'], [130, 'l']],
            'the preview of a request'                 => [[109, 'l'], [125, 'l'], [126, 'l'], [127, 'l'], [128, 'l'], [129, 'l']],
            'the preview of a Selected-groups request' => [[109, 'l'], [111, 'l'], [113, 'l'], [125, 'l'], [130, 'l']],
            'the preview of a date'                    => [[109, 'l'], [125, 'l'], [130, 'l']],
        ];
        $raw = ['<script>alert(', '<img src=x onerror=alert(', '<svg onload=alert('];
        foreach ($pages as $label => $body) {
            $found = [];
            foreach ($raw as $needle) {
                if (stripos($body, $needle) !== false) {
                    $found[] = $needle;
                }
            }
            $missing = [];
            foreach ($expect[$label] as [$n, $kind]) {
                $text = ($kind === 's') ? $short($n) : $hostile($n);
                if (str_contains($body, pw_esc($text)) === false) {
                    $missing[] = $n;
                }
            }
            pa_ok(
                "W21 {$label}: every hostile piece it shows (" . count($expect[$label]) . ') appears only escaped, and no raw <script>, <img onerror> or <svg onload> at all',
                $body !== '' && $found === [] && $missing === [],
                'raw found: [' . implode(', ', $found) . '] escaped form missing for: [' . implode(', ', $missing) . ']'
            );
        }

        // ---------------------------------------------------------------------
        pa_heading('W22 — "back" never sends anybody anywhere but the list or the same calendar (fix round 1, L1)');
        // `back` is read as a WORD — "view" returns to the same calendar's
        // page, anything else to the list — never used as an address. Every
        // other value, including full addresses on other sites, one starting
        // "//" (which a browser treats as another site), a path inside the
        // portal, a script address and a list, must land on the list.
        $landed = [];
        foreach (['https://evil.example/', '//evil.example/', '/admin/users', 'javascript:alert(1)', 'view?id=' . FB1, 'VIEW', ' view', '', 'list', ['view']] as $backValue) {
            $r = pw_req($sa, 'POST', $save, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'categories', 'feedID' => FA1, 'back' => $backValue, 'map' => []]);
            $landed[] = $r['location'];
        }
        pa_ok(
            'W22 ten other values of back (other sites, "//…", a portal path, javascript:, a list …) all land on the list, at this calendar\'s row',
            count(array_unique($landed)) === 1 && $landed[0] === $list . '#feed-' . FA1,
            pa_show($landed)
        );
        $toView = pw_req($sa, 'POST', $save, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'categories', 'feedID' => FA1, 'back' => 'view', 'map' => []]);
        pa_ok('W22 back=view lands on the SAME calendar\'s own page', $toView['location'] === $view . '?id=' . FA1 . '#categories', $toView['location']);

        // ---------------------------------------------------------------------
        pa_heading('W23 — a real file from the web root is served, and marked as this run\'s (fix round 1, L4)');
        $asset   = pw_req($sa, 'GET', '/assets/css/portal.css');
        $onDisk  = (string) @file_get_contents(dirname(__DIR__) . '/web/public_html/assets/css/portal.css');
        pa_ok(
            'W23 /assets/css/portal.css answers 200 with the file\'s exact bytes, as text/css (its marker header is checked by W0 with every other answer)',
            $asset['status'] === 200 && $onDisk !== '' && $asset['body'] === $onDisk && preg_match('/^Content-Type:\s*text\/css/mi', $asset['head']) === 1,
            'HTTP ' . $asset['status'] . ', ' . strlen($asset['body']) . ' bytes (file ' . strlen($onDisk) . ')'
        );

        // ---------------------------------------------------------------------
        pa_heading('W24 — a calendar\'s name never replaces an entry of the page trail (fix round 1, L5)');
        // The trail is keyed by its words, so a calendar called "Dashboard"
        // or "Settings" used to overwrite that entry. Read the trail's items.
        $trail = static function (string $html): array {
            if (preg_match('#<nav aria-label="breadcrumb"[^>]*>(.*?)</nav>#s', $html, $nav) !== 1) {
                return [];
            }
            preg_match_all('#<li class="breadcrumb-item[^"]*"[^>]*>(.*?)</li>#s', $nav[1], $items);

            return array_map(static fn (string $i): string => html_entity_decode(trim(strip_tags($i)), ENT_QUOTES, 'UTF-8'), $items[1]);
        };
        $dash = pa_newFeed(ORG_A, 'members', ['name' => 'Dashboard']);
        $sett = pa_newFeed(ORG_A, 'members', ['name' => 'Settings']);
        $t1   = $trail(pw_req($sa, 'GET', $view . '?id=' . $dash)['body']);
        $t2   = $trail(pw_req($sa, 'GET', $edit . '?id=' . $sett)['body']);
        pa_ok('W24 a calendar named "Dashboard": its page\'s trail is Dashboard › Admin › Outside calendars › “Dashboard”', $t1 === ['Dashboard', 'Admin', 'Outside calendars', '“Dashboard”'], pa_show($t1));
        pa_ok('W24 a calendar named "Settings": its form\'s trail is Dashboard › Admin › Outside calendars › “Settings” › Settings', $t2 === ['Dashboard', 'Admin', 'Outside calendars', '“Settings”', 'Settings'], pa_show($t2));

        // ---------------------------------------------------------------------
        pa_heading('W25 — the rows let long text shrink (fix round 1, M1; a text guard only)');
        // WHAT THIS PROVES, AND WHAT IT CANNOT: it reads the HTML, so it can
        // only see that each row's text column carries `min-width: 0` (and
        // the list's buttons `flex: 0 0 auto`) — the two settings that keep a
        // long address from pushing the buttons out of the row. That the row
        // then LAYS OUT correctly was proved in a real browser with
        // screenshots (fix round 1's evidence, 768 to 1440 pixels and phone
        // width); nothing committed lays a page out.
        $bad = [];
        foreach (['the list' => $list, 'FA1\'s page' => $view . '?id=' . FA1] as $label => $url) {
            $main = pw_main(pw_req($sa, 'GET', $url)['body']);
            $rows = preg_match_all('#<div class="portal-data-row-main[^"]*"([^>]*)>#', $main, $mm);
            foreach ($mm[1] as $attrs) {
                if (str_contains($attrs, 'min-width: 0') === false) {
                    $bad[] = $label . ': a row text column without min-width: 0';
                }
            }
            if ($rows === 0) {
                $bad[] = $label . ': no rows found at all';
            }
            if ($label === 'the list') {
                preg_match_all('#<div class="portal-data-row-aside[^"]*"([^>]*)>#', $main, $aa);
                foreach ($aa[1] as $attrs) {
                    if (str_contains($attrs, 'flex: 0 0 auto') === false) {
                        $bad[] = $label . ': a button column that may shrink';
                    }
                }
            }
        }
        pa_ok('W25 every row text column on the list and FA1\'s page may shrink (min-width: 0), and the list\'s buttons keep their width', $bad === [], implode(' | ', array_unique($bad)));

        // =====================================================================
        // 🧭 CHUNK P8-3 — the choice and rule pages, the calendar page's new
        //    parts, the event page's link and the series page (the plan's
        //    section 11.3, "P8-3"). Each check makes its own calendars, so
        //    none depends on what the checks above left behind. (W21 above
        //    renamed SG1, CA2, P and RA1 to hostile text; nothing below
        //    reads those names.)
        // =====================================================================
        $choice  = PW_A . '/admin/calendar/feeds/choice';
        $ruleP   = PW_A . '/admin/calendar/feeds/rule';
        $london  = new DateTimeZone(SITE_ZONE);
        // Work every event of a calendar out through the REAL write path: an
        // unchanged save of the calendar resolves all its events (as at the
        // start of part W). A failed set-up fails the check that uses it.
        $resolve = static function (int $feedId) use ($mysqli): bool {
            return FeedAdmin::saveCalendar($mysqli, $feedId, ORG_A, SA, pa_calPost($feedId))['ok'] === true;
        };
        // One row of a portal-data-list, found by its id, up to the next row.
        $rowOf = static function (string $body, string $idAttr): string {
            $at = strpos($body, 'id="' . $idAttr . '"');
            if ($at === false) {
                return '';
            }
            $text = substr($body, $at, 8000);
            $next = strpos($text, 'class="portal-data-row"', 1);

            return ($next === false) ? $text : substr($text, 0, $next);
        };
        // Every `data-confirm` sentence on a page, as a browser reads it (the
        // templates write some apostrophes as typed, which is valid inside a
        // double-quoted attribute, so the raw text is decoded, not compared).
        $confirmsOn = static function (string $body): array {
            preg_match_all('/data-confirm="([^"]*)"/', $body, $mm);

            return array_map(static fn (string $v): string => html_entity_decode($v, ENT_QUOTES, 'UTF-8'), $mm[1]);
        };
        // The `seen[]` values a choice page posts back, in page order.
        $seenOn = static function (string $body): array {
            preg_match_all('/name="seen\[\]" value="([^"]*)"/', $body, $mm);

            return array_map(static fn (string $v): string => html_entity_decode($v, ENT_QUOTES, 'UTF-8'), $mm[1]);
        };

        // ---------------------------------------------------------------------
        pa_heading('W26 — the choice and rule pages: nobody else gets in');
        $c26  = pa_newFeed(ORG_A, 'members', ['name' => 'W26 calendar']);
        $e26  = pa_event($c26, 'w26@p8', 'W26 event');
        $r26  = FeedAdmin::saveRule($mysqli, $c26, 0, ORG_A, SA, ['name' => 'W26 rule', 'audienceLevel' => 'hidden', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W26']]]);
        $ch26 = FeedAdmin::saveChoice($mysqli, $e26, 'date', ORG_A, SA, ['audienceLevel' => 'hidden']);
        $r26id       = (int) ($r26['ruleId'] ?? 0);
        $choiceUrl26 = $choice . '?event=' . $e26 . '&scope=date';
        $ruleNew26   = $ruleP . '?feed=' . $c26;
        $ruleOld26   = $ruleNew26 . '&rule=' . $r26id;
        $condition26 = [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W26', 'isException' => '0']];
        $posts26     = [
            'choice save'   => [$choiceUrl26, ['action' => 'save', 'audienceLevel' => 'public', 'detailLevel' => 'basic']],
            'choice remove' => [$choiceUrl26, ['action' => 'remove']],
            'rule preview'  => [$ruleNew26, ['action' => 'preview', 'name' => 'W26 not saved', 'isActive' => '1', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'conditions' => $condition26]],
            'rule save'     => [$ruleNew26, ['action' => 'save', 'name' => 'W26 not saved', 'isActive' => '1', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'conditions' => $condition26]],
            'rule delete'   => [$ruleOld26, ['action' => 'delete']],
        ];
        $fp26 = pa_fp();
        foreach (['M' => $m, 'LA' => $la] as $name => $jar) {
            $gets = [];
            foreach ([$choiceUrl26, $ruleNew26, $ruleOld26] as $url) {
                $gets[] = pw_req($jar, 'GET', $url)['status'];
            }
            pa_ok("W26 {$name}: the choice page and the rule page (new and saved) all answer 403", $gets === [403, 403, 403], pa_show($gets));
            $posts = [];
            foreach ($posts26 as $label => [$url, $fields]) {
                // A VALID token of this person's own session, so the refusal
                // is the administrator check, not the token (as in W3).
                $fields['csrf_token'] = pw_freshToken($jar, $list);
                $posts[] = $label . ':' . pw_req($jar, 'POST', $url, $fields)['status'];
            }
            pa_ok(
                "W26 {$name}: every post to the two pages (with a valid token of their own) answers 403",
                $posts === ['choice save:403', 'choice remove:403', 'rule preview:403', 'rule save:403', 'rule delete:403'],
                pa_show($posts)
            );
        }
        pa_ok('W26 the set-up worked (a rule and a choice, through FeedAdmin), and nothing was changed by any of those', $r26['ok'] === true && $ch26['ok'] === true && $r26id > 0 && pa_fp() === $fp26, pa_show([$r26['ok'], $ch26['ok']]));
        $w26out = [];
        foreach ([$choiceUrl26, $ruleOld26] as $url) {
            $r        = pw_req($out, 'GET', $url);
            // The organisation's own sign-in page: see W3 for why this is not the bare "/login" any more.
            $w26out[] = $r['status'] === 302 && str_starts_with($r['location'], PW_A . '/login?redirect=') === true
                && urldecode(substr($r['location'], strlen(PW_A . '/login?redirect='))) === $url;
        }
        pa_ok('W26 signed out: both pages send the visitor to the organisation\'s own sign-in page, carrying the address asked for back', $w26out === [true, true], pa_show($w26out));

        // ---------------------------------------------------------------------
        pa_heading('W27 — another organisation\'s event, calendar or rule looks exactly like one that does not exist');
        $eB27   = pa_event(FB1, 'w27-b@p8', 'W27 BONLY event');
        $rB27   = FeedAdmin::saveRule($mysqli, FB1, 0, ORG_B, SB, ['name' => 'W27 BONLY rule', 'audienceLevel' => 'hidden', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W27']]]);
        $rA27   = FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, ['name' => 'W27 rule of FA2', 'audienceLevel' => 'hidden', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W27 matches nothing']]]);
        $noId27 = pa_event($c26, 'w27-noid@p8', 'W27 no identity', ['noIdentity' => true]);
        $rB27id = (int) ($rB27['ruleId'] ?? 0);
        $rA27id = (int) ($rA27['ruleId'] ?? 0);
        $fp27   = pa_fp();
        $pairs27 = [
            'the choice page with B\'s event'                                        => [$choice . '?event=' . $eB27 . '&scope=date', $choice . '?event=919999&scope=date'],
            'the choice page with an event that has no identity yet (challenge M3)'  => [$choice . '?event=' . $noId27 . '&scope=date', $choice . '?event=919999&scope=date'],
            'the choice page for "every date" of an event that does not repeat'     => [$choice . '?event=' . $e26 . '&scope=series', $choice . '?event=919999&scope=series'],
            'the choice page with an unknown scope'                                  => [$choice . '?event=' . $e26 . '&scope=all', $choice . '?event=919999&scope=all'],
            'the rule page with B\'s calendar'                                       => [$ruleP . '?feed=' . FB1, $ruleP . '?feed=919999'],
            'the rule page with B\'s rule under A\'s calendar'                       => [$ruleNew26 . '&rule=' . $rB27id, $ruleNew26 . '&rule=919999'],
            'the rule page with FA2\'s rule asked for under another calendar (L15)'  => [$ruleNew26 . '&rule=' . $rA27id, $ruleNew26 . '&rule=919999'],
        ];
        foreach ($pairs27 as $label => [$asked, $missing]) {
            $ra = pw_req($sa, 'GET', $asked);
            $rb = pw_req($sa, 'GET', $missing);
            pa_ok(
                "W27 {$label}: 404, the same page as for a number that does not exist",
                $ra['status'] === 404 && $rb['status'] === 404 && $ra['body'] !== '' && pw_normalise($ra['body']) === pw_normalise($rb['body']),
                'HTTP ' . $ra['status'] . '/' . $rb['status'] . ' same=' . (pw_normalise($ra['body']) === pw_normalise($rb['body']) ? 'yes' : 'no')
            );
        }
        $postPairs27 = [
            'a choice saved for B\'s event'                     => [$choice . '?event=' . $eB27 . '&scope=date', $choice . '?event=919999&scope=date', ['action' => 'save', 'audienceLevel' => 'public', 'detailLevel' => 'basic']],
            'a rule added to B\'s calendar'                     => [$ruleP . '?feed=' . FB1, $ruleP . '?feed=919999', $posts26['rule save'][1]],
            'B\'s rule deleted through A\'s calendar'           => [$ruleNew26 . '&rule=' . $rB27id, $ruleNew26 . '&rule=919999', ['action' => 'delete']],
            'FA2\'s rule deleted through another calendar'      => [$ruleNew26 . '&rule=' . $rA27id, $ruleNew26 . '&rule=919999', ['action' => 'delete']],
        ];
        foreach ($postPairs27 as $label => [$asked, $missing, $fields]) {
            $ra = pw_req($sa, 'POST', $asked, ['csrf_token' => pw_freshToken($sa, $list)] + $fields);
            $rb = pw_req($sa, 'POST', $missing, ['csrf_token' => pw_freshToken($sa, $list)] + $fields);
            pa_ok(
                "W27 POST: {$label}: 404, the same page as for a number that does not exist",
                $ra['status'] === 404 && $rb['status'] === 404 && pw_normalise($ra['body']) === pw_normalise($rb['body']),
                'HTTP ' . $ra['status'] . '/' . $rb['status']
            );
        }
        pa_ok('W27 the set-up worked, and nothing was changed by any of those (B\'s rule included)', $rB27id > 0 && $rA27id > 0 && pa_fp() === $fp27);

        // ---------------------------------------------------------------------
        pa_heading('W28 — every post to the choice and rule pages needs the form token');
        $fp28 = pa_fp();
        foreach ($posts26 as $label => [$url, $fields]) {
            $results = [];
            foreach (['none' => null, 'wrong' => str_repeat('0', 64)] as $kind => $bad) {
                $f = $fields;
                if ($bad !== null) {
                    $f['csrf_token'] = $bad;
                }
                pw_freshToken($sa, $url); // the session holds a real token, so the refusal is about THIS post's token
                $r         = pw_req($sa, 'POST', $url, $f);
                $page      = pw_follow($sa, $r);
                $results[] = $kind . ':' . $r['status'] . ':' . ($r['location'] === $url ? 'self' : $r['location']) . ':' . (str_contains($page['body'], $tokenText) ? 'message' : 'no message');
            }
            pa_ok("W28 {$label} with no token and with a wrong one: refused with the token sentence, back to the same page", $results === ['none:302:self:message', 'wrong:302:self:message'], pa_show($results));
        }
        pa_ok('W28 nothing was changed by any of those', pa_fp() === $fp28);

        // ---------------------------------------------------------------------
        pa_heading('W29 — the choice page lists the dates it covers');
        $c29 = pa_newFeed(ORG_A, 'members', ['name' => 'W29 calendar']);
        [$s1, $s2, $s3] = pa_series($c29, 'w29@p8', 'W29 series', ['count' => 3, 'titles' => ['W29 series', 'W29 series CHANGED', 'W29 series']]);
        $set29 = $resolve($c29);
        $p29   = pw_req($sa, 'GET', $choice . '?event=' . $s1 . '&scope=series');
        $want29 = [$s1 . ':' . pa_cf($s1), $s2 . ':' . pa_cf($s2), $s3 . ':' . pa_cf($s3)];
        pa_ok(
            'W29 every date of a repeating event: 200, "The dates it covers (3)", a row for each date, and each date\'s own seen value (its number and its full-detail fingerprint)',
            $set29 === true && $p29['status'] === 200 && str_contains($p29['body'], 'The dates it covers (3)')
            && $rowOf($p29['body'], 'date-' . $s1) !== '' && $rowOf($p29['body'], 'date-' . $s2) !== '' && $rowOf($p29['body'], 'date-' . $s3) !== ''
            && $seenOn($p29['body']) === $want29,
            pa_show(['status' => $p29['status'], 'seen' => $seenOn($p29['body'])])
        );
        $differs = 'Details differ from the date you opened';
        pa_ok(
            'W29 only the date whose title differs from the one opened says "' . $differs . '"',
            str_contains($rowOf($p29['body'], 'date-' . $s2), $differs) && str_contains($rowOf($p29['body'], 'date-' . $s1), $differs) === false
            && str_contains($rowOf($p29['body'], 'date-' . $s3), $differs) === false
        );
        $one29 = pw_req($sa, 'GET', $choice . '?event=' . $s2 . '&scope=date');
        pa_ok(
            'W29 one date of it: "The date it covers", ONE row (its own) and ONE seen value — a sibling listed here could be approved unseen (challenge L17)',
            $one29['status'] === 200 && str_contains($one29['body'], 'The date it covers') && substr_count(pw_main($one29['body']), 'id="date-') === 1
            && $rowOf($one29['body'], 'date-' . $s2) !== '' && $seenOn($one29['body']) === [$s2 . ':' . pa_cf($s2)],
            pa_show($seenOn($one29['body']))
        );

        // ---------------------------------------------------------------------
        pa_heading('W30 — saving a choice through the page');
        // (a) The plan's D11(c), through the page: dates 1 and 2 approved,
        //     date 3 waiting, date 4 declined — then the page saved exactly as
        //     it stands, so its seen[] carries all four.
        $c30 = pa_newFeed(ORG_A, 'members', ['name' => 'W30 calendar']);
        $d1  = pa_event($c30, 'w30c@p8', 'W30 date 1', ['day' => 7, 'key' => 'W0']);
        $d2  = pa_event($c30, 'w30c@p8', 'W30 date 2', ['day' => 14, 'key' => 'W1']);
        $d3  = pa_event($c30, 'w30c@p8', 'W30 date 3', ['day' => 21, 'key' => 'W2']);
        $d4  = pa_event($c30, 'w30c@p8', 'W30 date 4', ['day' => 28, 'key' => 'W3']);
        $set30   = $resolve($c30);
        $first30 = FeedAdmin::saveChoice($mysqli, $d1, 'series', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [$d1 . ':' . pa_cf($d1), $d2 . ':' . pa_cf($d2)]]);
        $pend30  = pa_pending($d4);
        $decl30  = ($pend30 === null) ? ['decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, 'declined', '', [pa_item($pend30)]);
        // A SUMMER decision time, set by hand as W13 does (fix round 1 of chunk
        // P8-3, check finding L3). The real decision is "now", and London is on
        // UTC from late October to late March, so a page showing the moment in
        // UTC instead of the organisation's zone passed this check all winter.
        // The most recent 1 July, worked out from today so it never goes stale;
        // a change to test data only.
        $todayW30 = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $declAt   = sprintf('%04d-07-01 12:00:00', ((int) $todayW30->format('n') >= 8) ? (int) $todayW30->format('Y') : (int) $todayW30->format('Y') - 1);
        pa_q("UPDATE tblExternalEventApprovals SET decidedAt = ? WHERE eventID = ? AND status = 'declined'", 'si', [$declAt, $d4]);
        $series30 = $choice . '?event=' . $d1 . '&scope=series';
        $page30  = pw_req($sa, 'GET', $series30)['body'];
        $states  = [];
        foreach ([$d1, $d2, $d3, $d4] as $id) {
            $row      = $rowOf($page30, 'date-' . $id);
            $states[] = str_contains($row, 'Waiting for approval') ? 'waiting' : (str_contains($row, 'A request was declined on ') ? 'declined' : '-');
        }
        pa_ok(
            'W30 the page shows date 3 "Waiting for approval" and date 4 "A request was declined on ' . FeedAdmin::showMoment($declAt, $london) . '" (in the organisation\'s time), and nothing on dates 1 and 2',
            $set30 === true && $first30['ok'] === true && $decl30['decided'] === 1 && $states === ['-', '-', 'waiting', 'declined']
            && str_contains($rowOf($page30, 'date-' . $d4), 'A request was declined on ' . pw_esc(FeedAdmin::showMoment($declAt, $london)))
            && str_contains(FeedAdmin::showMoment($declAt, $london), ', 13:00 BST'),
            pa_show(['states' => $states, 'declinedAt' => $declAt])
        );
        $save30  = pw_req($sa, 'POST', $series30, ['csrf_token' => pw_token($page30), 'action' => 'save', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'seen' => $seenOn($page30)]);
        $after30 = pw_follow($sa, $save30);
        pa_ok(
            'W30 saved as it stands: 302 to the calendar page\'s events, whose message says "2 dates that were waiting or declined are now approved."; dates 3 and 4 are now Everyone',
            $save30['status'] === 302 && $save30['location'] === $view . '?id=' . $c30 . '#events'
            && str_contains($after30['body'], pw_esc(' 2 dates that were waiting or declined are now approved.'))
            && pa_ev($d3)['importLevel'] === 'public' && pa_ev($d4)['importLevel'] === 'public',
            pa_show([$save30['status'], $save30['location'], pa_ls($d3), pa_ls($d4)])
        );
        // (b) A single-date choice posted with seen values for OTHER dates —
        //     its own sibling, B's event, and another calendar's — by hand.
        $x30  = pa_series($c30, 'w30x@p8', 'W30 x', ['count' => 2, 'day' => 35]);
        $eB30 = pa_event(FB1, 'w30-b@p8', 'W30 BONLY event');
        $set30b = $resolve($c30);
        $xUrl = $choice . '?event=' . $x30[0] . '&scope=date';
        $t30  = pw_req($sa, 'POST', $xUrl, [
            'csrf_token' => pw_freshToken($sa, $xUrl), 'action' => 'save', 'audienceLevel' => 'public', 'detailLevel' => 'basic',
            'seen' => [$x30[0] . ':' . pa_cf($x30[0]), $x30[1] . ':' . pa_cf($x30[1]), $eB30 . ':' . pa_cf($eB30), $s1 . ':' . pa_cf($s1)],
        ]);
        pa_ok(
            'W30 a single-date choice posted with extra seen values (its sibling, B\'s event, another calendar\'s event): only its own date is approved',
            $set30b === true && $t30['status'] === 302 && pa_ev($x30[0])['importLevel'] === 'public' && pa_ls($x30[1]) === 'members/calendar'
            && pa_apprWithStatus($x30[1], 'approved') === [] && pa_apprWithStatus($eB30, 'approved') === [] && pa_apprWithStatus($s1, 'approved') === [],
            pa_show([$t30['status'], pa_ls($x30[0]), pa_ls($x30[1])])
        );
        // (c) A private-marked date (on a Public calendar, so the private mark
        //     is the only thing in the way — the D11(a) shape).
        $c30p   = pa_newFeed(ORG_A, 'public', ['name' => 'W30 public calendar']);
        $pv     = pa_event($c30p, 'w30p@p8', 'W30 private event', ['private' => 1]);
        $set30p = $resolve($c30p);
        $pvUrl  = $choice . '?event=' . $pv . '&scope=date';
        $pvPage = pw_req($sa, 'GET', $pvUrl)['body'];
        pa_ok(
            'W30 a private-marked date: the page shows the warning word for word, and a tick box with its own label',
            $set30p === true && preg_match('#id="choice-private-warning">(.*?)</div>#s', $pvPage, $wm) === 1
            && str_contains(html_entity_decode($wm[1], ENT_QUOTES, 'UTF-8'), MSG_PRIVATE)
            && str_contains($pvPage, 'id="choice-private" name="overridesPrivateMark"')
            && str_contains($pvPage, '<label class="form-check-label" for="choice-private">I have checked, and I want to show it</label>')
        );
        $fp30   = pa_fp();
        $noTick = pw_req($sa, 'POST', $pvUrl, ['csrf_token' => pw_token($pvPage), 'action' => 'save', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'seen' => $seenOn($pvPage), 'note' => 'W30 typed note']);
        pa_ok(
            'W30 saved without the tick: 200, the warning as the refusal, the typed note kept, nothing written',
            $noTick['status'] === 200 && str_contains($noTick['body'], 'role="alert">' . pw_esc(MSG_PRIVATE)) && str_contains($noTick['body'], 'value="W30 typed note"') && pa_fp() === $fp30,
            'HTTP ' . $noTick['status']
        );
        $tick = pw_req($sa, 'POST', $pvUrl, ['csrf_token' => pw_token($noTick['body']), 'action' => 'save', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'seen' => $seenOn($pvPage), 'overridesPrivateMark' => '1']);
        $pvChoice = pa_one("SELECT overridesPrivateMark AS o FROM tblExternalEventChoices WHERE feedID = ? AND scope = 'date'", 'i', [$c30p]);
        pa_ok(
            'W30 with the tick: 302 to the calendar page, the choice stores overridesPrivateMark = 1, and the date is Everyone',
            $tick['status'] === 302 && $tick['location'] === $view . '?id=' . $c30p . '#events' && pa_ev($pv)['importLevel'] === 'public' && (int) ($pvChoice['o'] ?? 0) === 1,
            pa_show([$tick['status'], $tick['location'], pa_ls($pv), $pvChoice])
        );
        // (d) A refusal keeps what was typed. The two days are worked out from
        //     today, so the check never goes stale.
        $from30 = pa_todayLocal()->modify('+10 days')->format('Y-m-d');
        $to30   = pa_todayLocal()->modify('+9 days')->format('Y-m-d');
        $fp30d  = pa_fp();
        $win30  = pw_req($sa, 'POST', $xUrl, [
            'csrf_token' => pw_freshToken($sa, $xUrl), 'action' => 'save', 'audienceLevel' => 'members', 'detailLevel' => 'basic',
            'fromDate' => $from30, 'toDate' => $to30, 'note' => 'W30 window note',
        ]);
        pa_ok(
            'W30 a last day before the first: 200, "The last day cannot be before the first day.", both days and the note still typed in, nothing written',
            $win30['status'] === 200 && str_contains($win30['body'], 'role="alert">' . pw_esc('The last day cannot be before the first day.'))
            && str_contains($win30['body'], 'value="' . $from30 . '"') && str_contains($win30['body'], 'value="' . $to30 . '"')
            && str_contains($win30['body'], 'value="W30 window note"') && pa_fp() === $fp30d,
            'HTTP ' . $win30['status']
        );
        // (e) Remove this choice — and once more, when there is none.
        $xPage = pw_req($sa, 'GET', $xUrl)['body'];
        pa_ok(
            'W30 a date with a choice and no choice for every date: the page offers "Remove this choice", asking first with "…then follows the rules and the calendar\'s own setting" (fix round 2, F1: chosen by whether a choice for every date exists)',
            $confirmsOn($xPage) === ["Remove this choice? This date then follows the rules and the calendar's own setting."]
            && str_contains($xPage, '>Remove this choice</button>'),
            pa_show($confirmsOn($xPage))
        );
        $rm     = pw_req($sa, 'POST', $xUrl, ['csrf_token' => pw_token($xPage), 'action' => 'remove']);
        $rmPage = pw_follow($sa, $rm);
        $xLeft  = pa_one("SELECT choiceID FROM tblExternalEventChoices WHERE feedID = ? AND scope = 'date' AND externalUidHash = UNHEX(SHA2('w30x@p8', 256))", 'i', [$c30]);
        pa_ok(
            'W30 Remove this choice: 302 to the calendar page, "Choice removed. …", the choice gone, and the date back to the calendar\'s own setting',
            $rm['status'] === 302 && $rm['location'] === $view . '?id=' . $c30 . '#events'
            && str_contains($rmPage['body'], pw_esc(MSG_REMOVED_DATE_ALONE))
            && $xLeft === null && pa_ls($x30[0]) === 'members/calendar',
            pa_show([$rm['status'], $rm['location'], $xLeft, pa_ls($x30[0])])
        );
        $rm2     = pw_req($sa, 'POST', $xUrl, ['csrf_token' => pw_freshToken($sa, $xUrl), 'action' => 'remove']);
        $rm2Page = pw_follow($sa, $rm2);
        pa_ok(
            'W30 removing it again: back to the same page, with "There is no choice to remove."',
            $rm2['status'] === 302 && $rm2['location'] === $xUrl && str_contains($rm2Page['body'], 'role="alert">There is no choice to remove.'),
            pa_show([$rm2['status'], $rm2['location']])
        );

        // ---------------------------------------------------------------------
        pa_heading('W31 — the rule page: refusals keep what was typed; Preview saves nothing; save and delete');
        $c31    = pa_newFeed(ORG_A, 'members', ['name' => 'W31 calendar']);
        $o31    = pa_event($c31, 'w31-open@p8', 'W31 open day');
        $pl31   = pa_event($c31, 'w31-plan@p8', 'W31 planning');
        $set31  = $resolve($c31);
        $rNew   = $ruleP . '?feed=' . $c31;
        $base31 = [
            'name' => 'W31 typed name', 'isActive' => '1', 'audienceLevel' => 'members', 'detailLevel' => 'basic',
            'conditions' => [0 => ['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W31 open', 'isException' => '0']],
        ];
        $row31  = static fn (string $field, string $type, string $value, string $exception = '0'): array => ['matchField' => $field, 'matchType' => $type, 'matchValue' => $value, 'isException' => $exception];
        $eleven = [];
        for ($i = 0; $i <= 10; $i++) {
            $eleven[$i] = $row31('title', 'contains', 'W31 c' . $i);
        }
        $cases31 = [
            'no name'                                      => [['name' => '   '], 'Please give the rule a name.'],
            'a field that is not on the list'              => [['conditions' => [0 => $row31('colour', 'contains', 'W31 open')]], 'Choose a field and a way of matching from the lists.'],
            'a value that is not readable text'            => [['conditions' => [0 => $row31('title', 'contains', "W31 \xff\xfe")]], 'One of the values contains characters that cannot be read. Please type it again.'],
            'a value of invisible characters only'         => [['conditions' => [0 => $row31('title', 'contains', "\u{200B}\u{200B}")]], 'One of the values is empty once spaces and invisible characters are removed.'],
            'a category condition that is not "is exactly"' => [['conditions' => [0 => $row31('category', 'contains', 'outreach')]], "A category condition can only use 'is exactly'."],
            'eleven conditions'                            => [['conditions' => $eleven], 'A rule can have at most 10 conditions and 10 exceptions.'],
            'an exception and no condition'                => [['conditions' => [10 => $row31('title', 'contains', 'W31 planning', '1')]], 'A rule needs at least one condition that events must match.'],
            'a day that does not exist'                    => [['fromDate' => pa_todayLocal()->format('Y') . '-02-30'], 'Enter the dates as real calendar dates.'],
            'a last day before the first'                  => [['fromDate' => $from30, 'toDate' => $to30], 'The last day cannot be before the first day.'],
            '"Selected groups" with nobody chosen'         => [['audienceLevel' => 'groups'], 'Choose at least one group or person.'],
        ];
        foreach ($cases31 as $label => [$changes, $message]) {
            $fpCase = pa_fp();
            $r      = pw_req($sa, 'POST', $rNew, ['csrf_token' => pw_freshToken($sa, $rNew), 'action' => 'save'] + $changes + $base31);
            // What was typed comes back: the name, or (when the name is the
            // fault) the condition's value.
            $kept = ($label === 'no name')
                ? str_contains($r['body'], 'maxlength="255" value="W31 open"')
                : str_contains($r['body'], 'id="rule-name" name="name" required maxlength="120" value="W31 typed name"');
            pa_ok(
                "W31 {$label}: 200, \"{$message}\", what was typed still in the form, nothing written",
                $set31 === true && $r['status'] === 200 && str_contains($r['body'], 'role="alert">' . pw_esc($message)) && $kept === true && pa_fp() === $fpCase,
                'HTTP ' . $r['status'] . ' kept=' . ($kept === true ? 'yes' : 'no')
            );
        }
        $fp31p   = pa_fp();
        $prev31  = pw_req($sa, 'POST', $rNew, ['csrf_token' => pw_freshToken($sa, $rNew), 'action' => 'preview'] + $base31);
        $section = (preg_match('#<section class="mb-4" id="rule-preview".*?</section>#s', $prev31['body'], $sm) === 1) ? $sm[0] : '';
        preg_match_all('#<span class="badge bg-light text-dark border ms-1 text-wrap text-start">([^<]*)</span>#', $section, $lm);
        $labels31 = array_map(static fn (string $l): string => html_entity_decode($l, ENT_QUOTES, 'UTF-8'), $lm[1]);
        pa_ok(
            'W31 Preview: 200, the heading "What this rule would do now (nothing has been saved)", ONE line — "W31 open day", with one of the outcome labels — the typed name kept, nothing written',
            $prev31['status'] === 200 && str_contains($section, '<h2 class="h5" id="rule-preview-heading">What this rule would do now (nothing has been saved)</h2>')
            && str_contains($section, 'W31 open day') && str_contains($section, 'W31 planning') === false
            && count($labels31) === 1 && in_array($labels31[0], array_values(FeedAdmin::OUTCOME_LABELS), true) === true
            && str_contains($prev31['body'], 'value="W31 typed name"') && pa_fp() === $fp31p,
            pa_show(['labels' => $labels31])
        );
        $prevApi = pw_req($sa, 'POST', $rNew, ['csrf_token' => pw_freshToken($sa, $rNew), 'action' => 'preview', 'apiOptOut' => '1'] + $base31);
        $apiLine = 'These events will not be sent to API keys.';
        pa_ok(
            'W31 Preview with "Don\'t show via API" ticked says "' . $apiLine . '"; without it, it does not',
            str_contains($prevApi['body'], $apiLine) && str_contains($prev31['body'], $apiLine) === false
        );
        // The preview's dates (fix round 1 of chunk P8-3, check finding L1):
        // an all-day event reads "(all day)", and an event in another zone
        // keeps that zone's name — the page used to print "00:00" for the
        // first and a bare "19:00" for the second, which an administrator
        // would read as the organisation's own time.
        $c31z = pa_newFeed(ORG_A, 'members', ['name' => 'W31 zones calendar']);
        $ad31 = pa_event($c31z, 'w31-allday@p8', 'W31z all day', ['day' => 50]);
        $ny31 = pa_event($c31z, 'w31-ny@p8', 'W31z New York', ['day' => 51]);
        pa_q("UPDATE tblEvents SET isAllDay = 1, startDateTime = CONCAT(DATE(startDateTime), ' 00:00:00') WHERE eventID = ?", 'i', [$ad31]);
        pa_q("UPDATE tblEvents SET timezone = 'America/New_York' WHERE eventID = ?", 'i', [$ny31]);
        $setZ  = $resolve($c31z);
        $zUrl  = $ruleP . '?feed=' . $c31z;
        $prevZ = pw_req($sa, 'POST', $zUrl, ['csrf_token' => pw_freshToken($sa, $zUrl), 'action' => 'preview', 'conditions' => [0 => $row31('title', 'contains', 'W31z')]] + $base31);
        $secZ  = (preg_match('#<section class="mb-4" id="rule-preview".*?</section>#s', $prevZ['body'], $zm) === 1) ? $zm[0] : '';
        $wantAll = FeedAdmin::showWallClock((string) pa_one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$ad31])['startDateTime'], SITE_ZONE, true, $london);
        $wantNy  = FeedAdmin::showWallClock((string) pa_one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$ny31])['startDateTime'], 'America/New_York', false, $london);
        pa_ok(
            'W31 Preview prints an all-day event as "… (all day)" and keeps another zone\'s name ("… 19:00 (America/New_York)")',
            $setZ === true && $prevZ['status'] === 200 && str_contains($wantAll, '(all day)') && str_contains($wantNy, ', 19:00 (America/New_York)')
            && str_contains($secZ, pw_esc($wantAll)) && str_contains($secZ, pw_esc($wantNy)),
            pa_show(['wanted' => [$wantAll, $wantNy], 'shown' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($secZ))), 0, 300)])
        );
        $sv31    = pw_req($sa, 'POST', $rNew, ['csrf_token' => pw_freshToken($sa, $rNew), 'action' => 'save', 'name' => 'W31 open rule'] + $base31);
        $nr31    = (int) (pa_one('SELECT ruleID FROM tblExternalFeedRules WHERE feedID = ? AND name = ?', 'is', [$c31, 'W31 open rule'])['ruleID'] ?? 0);
        $sv31pg  = pw_follow($sa, $sv31);
        $ruleRow = $rowOf($sv31pg['body'], 'rule-' . $nr31);
        pa_ok(
            'W31 Add rule: 302 to the calendar page\'s rules, "Saved." shown, and the rule listed there with its condition in words',
            $sv31['status'] === 302 && $sv31['location'] === $view . '?id=' . $c31 . '#rules' && $nr31 > 0
            && str_contains($sv31pg['body'], 'role="alert">Saved.') && str_contains($ruleRow, 'W31 open rule') && str_contains($ruleRow, pw_esc("Title contains 'W31 open'")),
            pa_show([$sv31['status'], $sv31['location'], $nr31])
        );
        $edit31 = pw_req($sa, 'GET', $rNew . '&rule=' . $nr31);
        $delete31Ask = "Delete this rule? Events it was showing will follow any other rule that matches them, or else the calendar's own setting.";
        pa_ok(
            'W31 the saved rule\'s own page: 200, its name and condition drawn, and "Delete this rule" asking first',
            $edit31['status'] === 200 && str_contains($edit31['body'], 'value="W31 open rule"') && str_contains($edit31['body'], 'maxlength="255" value="W31 open"')
            && $confirmsOn($edit31['body']) === [$delete31Ask],
            'HTTP ' . $edit31['status'] . ' ' . pa_show($confirmsOn($edit31['body']))
        );
        $del31   = pw_req($sa, 'POST', $rNew . '&rule=' . $nr31, ['csrf_token' => pw_token($edit31['body']), 'action' => 'delete']);
        $del31pg = pw_follow($sa, $del31);
        pa_ok(
            'W31 Delete: 302 to the calendar page\'s rules, "Rule deleted. …", and the rule and its condition gone; its event follows the calendar again',
            $del31['status'] === 302 && $del31['location'] === $view . '?id=' . $c31 . '#rules'
            && str_contains($del31pg['body'], pw_esc("Rule deleted. Events it was showing now follow any other rule that matches them, or else the calendar's own setting."))
            && pa_one('SELECT ruleID FROM tblExternalFeedRules WHERE ruleID = ?', 'i', [$nr31]) === null
            && pa_one('SELECT conditionID FROM tblExternalRuleConditions WHERE ruleID = ?', 'i', [$nr31]) === null && pa_ls($o31) === 'members/calendar',
            pa_show([$del31['status'], $del31['location'], pa_ls($o31)])
        );

        // ---------------------------------------------------------------------
        pa_heading('W32 — the "not sent to API keys" line on both forms when the calendar is opted out (C10 item 3)');
        $c32   = pa_newFeed(ORG_A, 'members', ['name' => 'W32 opted-out calendar', 'apiOptOut' => 1]);
        $e32   = pa_event($c32, 'w32@p8', 'W32 event');
        $set32 = $resolve($c32);
        $ch32  = pw_req($sa, 'GET', $choice . '?event=' . $e32 . '&scope=date')['body'];
        $ru32  = pw_req($sa, 'GET', $ruleP . '?feed=' . $c32)['body'];
        pa_ok(
            'W32 opted out: the choice form and the rule form each show their line, word for word',
            $set32 === true
            && str_contains($ch32, 'id="choice-calendar-api-note">' . pw_esc('This calendar is set not to show via API, so these dates will not be sent to API keys whatever you choose here.') . '</div>')
            && str_contains($ru32, 'id="rule-calendar-api-note">' . pw_esc('This calendar is set not to show via API, so the events this rule matches will not be sent to API keys whatever you choose here.') . '</div>')
        );
        $ch26b = pw_req($sa, 'GET', $choiceUrl26);
        $ru26b = pw_req($sa, 'GET', $ruleNew26);
        pa_ok(
            'W32 not opted out (W26\'s calendar): both forms answer 200 and neither shows the line',
            $ch26b['status'] === 200 && $ru26b['status'] === 200 && str_contains($ch26b['body'], 'calendar-api-note') === false && str_contains($ru26b['body'], 'calendar-api-note') === false
        );

        // ---------------------------------------------------------------------
        pa_heading('W33 — the calendar\'s own page: per-date buttons, the identity-less note and the rules section');
        $one33  = pa_event($c29, 'w33-one@p8', 'W33 single event', ['day' => 40]);
        $noId33 = pa_event($c29, 'w33-noid@p8', 'W33 no identity', ['noIdentity' => true, 'day' => 41]);
        $rule33 = FeedAdmin::saveRule($mysqli, $c29, 0, ORG_A, SA, ['name' => 'W33 rule', 'audienceLevel' => 'hidden', 'isActive' => '0', 'conditions' => [
            ['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W33 nothing'],
            ['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W33 never', 'isException' => '1'],
        ]]);
        $rule33id = (int) ($rule33['ruleId'] ?? 0);
        $v33      = pw_req($sa, 'GET', $view . '?id=' . $c29)['body'];
        $dateLink = static fn (int $id, string $scope): string => 'href="' . pw_esc($choice . '?event=' . $id . '&scope=' . $scope) . '"';
        $seriesOk = true;
        foreach ([$s1, $s2, $s3] as $id) {
            $row = $rowOf($v33, 'event-' . $id);
            if (str_contains($row, $dateLink($id, 'date') . '>Set this date…') === false || str_contains($row, $dateLink($id, 'series') . '>Set every date…') === false) {
                $seriesOk = false;
            }
        }
        pa_ok('W33 each date of the repeating event has "Set this date…" (scope=date) and "Set every date…" (scope=series)', $rule33id > 0 && $seriesOk === true);
        $oneRow = $rowOf($v33, 'event-' . $one33);
        pa_ok(
            'W33 an event that does not repeat has "Set this date…" only',
            str_contains($oneRow, $dateLink($one33, 'date') . '>Set this date…') && str_contains($oneRow, 'Set every date…') === false
        );
        $noIdRow = $rowOf($v33, 'event-' . $noId33);
        pa_ok(
            'W33 an event with no identity yet: the note "This event can have its own setting after the calendar\'s next successful refresh." and no choice link anywhere on the page (challenge M3)',
            str_contains(html_entity_decode($noIdRow, ENT_QUOTES, 'UTF-8'), "This event can have its own setting after the calendar's next successful refresh.")
            && str_contains($v33, 'event=' . $noId33 . '&amp;') === false && str_contains($noIdRow, 'Set this date') === false
        );
        $r33Row = $rowOf($v33, 'rule-' . $rule33id);
        pa_ok(
            'W33 the Rules section: "Add a rule", and the rule\'s row — name, Off, its conditions in words, who can see matching events, and Edit',
            str_contains($v33, '<section id="rules"') && str_contains($v33, 'href="' . pw_esc($ruleP . '?feed=' . $c29) . '">Add a rule</a>')
            && str_contains($r33Row, 'W33 rule') && str_contains($r33Row, '>Off</span>')
            && str_contains($r33Row, pw_esc("Title contains 'W33 nothing', except title contains 'W33 never'"))
            && str_contains($r33Row, 'Who can see matching events: ' . pw_esc(FeedAdmin::LEVEL_LABELS['hidden']))
            && str_contains($r33Row, 'href="' . pw_esc($ruleP . '?feed=' . $c29 . '&rule=' . $rule33id) . '">Edit'),
            mb_substr(strip_tags($r33Row), 0, 300)
        );

        // ---------------------------------------------------------------------
        pa_heading('W34 — "Manage who sees this" on the event page: for an administrator of the organisation only');
        $c34   = pa_newFeed(ORG_A, 'public', ['name' => 'W34 public calendar']);
        $e34   = pa_event($c34, 'w34@p8', 'W34 public event');
        $n34   = pa_event($c34, 'w34-noid@p8', 'W34 no identity', ['noIdentity' => true]);
        $set34 = $resolve($c34);
        $evUrl = static fn (int $id): string => PW_A . '/calendar/event?slug=imp-selftest-p8-' . $id;
        $href34 = 'href="' . pw_esc($choice . '?event=' . $e34 . '&scope=date') . '"';
        $seen34 = [];
        foreach (['SA' => $sa, 'GA' => $ga, 'M' => $m, 'LA' => $la] as $name => $jar) {
            $r             = pw_req($jar, 'GET', $evUrl($e34));
            $seen34[$name] = $r['status'] . ':' . (str_contains($r['body'], 'Manage who sees this') ? 'link' : 'no link') . (str_contains($r['body'], $href34) ? ':to the choice page' : '');
        }
        pa_ok(
            'W34 SA and GA see the link, to the choice page for that date; M and LA (the old flag only) see the page without it',
            $set34 === true && $seen34 === ['SA' => '200:link:to the choice page', 'GA' => '200:link:to the choice page', 'M' => '200:no link', 'LA' => '200:no link'],
            pa_show($seen34)
        );
        pa_ok('W34 the link answers 200 for SA', pw_req($sa, 'GET', $choice . '?event=' . $e34 . '&scope=date')['status'] === 200);
        $n34page = pw_req($sa, 'GET', $evUrl($n34));
        pa_ok('W34 an imported event with no identity yet: its page shows no link, even to SA', $n34page['status'] === 200 && str_contains($n34page['body'], 'Manage who sees this') === false, 'HTTP ' . $n34page['status']);

        // ---------------------------------------------------------------------
        pa_heading('W39 — an administrator of ANOTHER organisation (SB) gets nothing of A\'s (fix round 1 of P8-3, M1)');
        // SB is a site administrator of organisation B ONLY. Every gate on
        // these pages must ask "is this person an administrator of THIS
        // organisation?" — the organisation in the address — and not merely
        // "an administrator somewhere?". A gate of the second kind lets B's
        // administrator read A's events and A's whole picker of groups, roles
        // and people (the check showed it: fault N05 passed every check before
        // this, because W3 and W26 refused only M and LA, and GA is a global
        // administrator who may enter every organisation). Saving would still
        // be refused by FeedAdmin's own check (D29 includes SB); reading is
        // what leaked. Every answer must be 403 — the same as M and LA get.
        $sb     = pw_signIn('sb');
        $fp39   = pa_fp();
        $gets39 = [];
        foreach ([
            'the list'                     => $list,
            'the new-calendar form'        => $edit,
            'FA1\'s form'                  => $edit . '?id=' . FA1,
            'FA1\'s page'                  => $view . '?id=' . FA1,
            'the choice page (one date)'   => $choiceUrl26,
            'the choice page (every date)' => $choice . '?event=' . $s1 . '&scope=series',
            'the rule page (new)'          => $ruleNew26,
            'the rule page (saved)'        => $ruleOld26,
        ] as $label => $url) {
            $gets39[$label] = pw_req($sb, 'GET', $url)['status'];
        }
        pa_ok('W39 SB: every one of A\'s outside-calendar pages answers 403 (' . count($gets39) . ' asked)', array_values(array_unique($gets39)) === [403], pa_show($gets39));
        $posts39 = [];
        foreach ($actions as $action) {
            // A VALID token of SB's own session, so the refusal is the
            // administrator check, not the token (as in W3 and W26).
            $posts39['feeds/save ' . $action] = pw_req($sb, 'POST', $save, [
                'csrf_token' => pw_freshToken($sb, $list), 'action' => $action, 'feedID' => FA1, 'back' => 'list',
                'map' => [['word' => 'wword', 'choice' => (string) CA1]],
            ])['status'];
        }
        $posts39['the form (FA1)'] = pw_req($sb, 'POST', $edit . '?id=' . FA1, pa_calPost(FA1, ['csrf_token' => pw_freshToken($sb, $list), 'action' => 'save', 'name' => 'W39 must not save']))['status'];
        $posts39['the form (new)'] = pw_req($sb, 'POST', $edit, ['csrf_token' => pw_freshToken($sb, $list), 'action' => 'save', 'name' => 'W39 must not add', 'url' => 'https://p8-selftest.internal/w39.ics', 'fetchEveryMins' => '360', 'audienceLevel' => 'members'])['status'];
        foreach ($posts26 as $label => [$url, $fields]) {
            $posts39[$label] = pw_req($sb, 'POST', $url, ['csrf_token' => pw_freshToken($sb, $list)] + $fields)['status'];
        }
        pa_ok(
            'W39 SB: every post — the five buttons, both calendar forms, and the choice and rule pages\' five posts, each with a valid token of SB\'s own — answers 403 (' . count($posts39) . ' sent)',
            count($posts39) === 12 && array_values(array_unique($posts39)) === [403],
            pa_show($posts39)
        );
        pa_ok('W39 nothing was changed by any of those', pa_fp() === $fp39);
        $sbEvent = pw_req($sb, 'GET', $evUrl($e34));
        pa_ok(
            'W39 SB opens A\'s public imported event: 200, its title shown, and no "Manage who sees this" link (that is for A\'s administrators only)',
            $sbEvent['status'] === 200 && str_contains($sbEvent['body'], 'W34 public event') && str_contains($sbEvent['body'], 'Manage who sees this') === false,
            'HTTP ' . $sbEvent['status']
        );

        // ---------------------------------------------------------------------
        pa_heading('W40 — the choice page says truly what decides a date, and so does Remove (fix round 1 of P8-3, L2)');
        // The resolver decides a date by its own choice first, then the choice
        // for every date, then the rules, then the calendar's own setting —
        // each choice only while it is in force. The page used to say "No
        // choice yet: this date follows the rules and the calendar's own
        // setting." even when a choice for every date decided it (its own
        // "Now:" line said so beside it), and Remove promised the date would
        // "go back to the rules" when it went back to the choice for every
        // date. Each case is shown here, and the last one is checked against
        // what the resolver really did.
        $c40 = pa_newFeed(ORG_A, 'members', ['name' => 'W40 calendar']);
        [$q1, $q2] = pa_series($c40, 'w40@p8', 'W40 series', ['count' => 2, 'day' => 100]);
        $set40       = $resolve($c40);
        $dateUrl40   = $choice . '?event=' . $q1 . '&scope=date';
        $seriesUrl40 = $choice . '?event=' . $q1 . '&scope=series';
        $says        = static fn (string $body, string $text): bool => str_contains(html_entity_decode($body, ENT_QUOTES, 'UTF-8'), $text);
        $noneDate    = "No choice yet: this date follows the rules and the calendar's own setting.";
        $noneSeries  = "No choice for every date yet: each date follows its own choice while one is in force, otherwise the rules and the calendar's own setting.";
        $underSeries = "This date has no choice of its own, so the choice for every date decides it while that choice is in force, otherwise the rules and the calendar's own setting.";
        $hasDate     = 'A choice for this date already exists; saving replaces it.';
        $hasSeries   = 'A choice for every date already exists; saving replaces it. A date with a choice of its own follows that one instead while it is in force.';
        $askDate     = "Remove this choice? This date then follows the choice for every date while one is in force, otherwise the rules and the calendar's own setting.";
        $askSeries   = "Remove this choice? Each date then follows its own choice while one is in force, otherwise the rules and the calendar's own setting.";
        $page40a = pw_req($sa, 'GET', $dateUrl40)['body'];
        $page40b = pw_req($sa, 'GET', $seriesUrl40)['body'];
        pa_ok(
            'W40 no choice at all: the date page and the every-date page each say the rules and the calendar\'s own setting decide',
            $set40 === true && $says($page40a, $noneDate) && $says($page40b, $noneSeries)
        );
        $sc40    = FeedAdmin::saveChoice($mysqli, $q1, 'series', ORG_A, SA, ['audienceLevel' => 'hidden']);
        $page40c = pw_req($sa, 'GET', $dateUrl40)['body'];
        pa_ok(
            'W40 a choice for every date and none for this date: the date page says the choice for every date decides it — never "No choice yet" — as its "Now:" line does',
            $sc40['ok'] === true && pa_ls($q1) === 'hidden/series' && $says($page40c, $underSeries) && $says($page40c, 'No choice yet') === false
            && $says($page40c, '(' . FeedAdmin::sourceLabel('series') . ')')
            // A REPEATING date never gets the "no longer repeats" box (fix
            // round 3, check finding R3-3).
            && str_contains($page40c, 'id="choice-leftover"') === false,
            pa_show([$sc40['ok'], pa_ls($q1)])
        );
        $dc40    = FeedAdmin::saveChoice($mysqli, $q1, 'date', ORG_A, SA, ['audienceLevel' => 'members']);
        $page40d = pw_req($sa, 'GET', $dateUrl40)['body'];
        $page40e = pw_req($sa, 'GET', $seriesUrl40)['body'];
        pa_ok(
            'W40 both choices: each page says its own choice exists, the every-date page adds that a date with its own choice follows that one, and each Remove asks in the resolver\'s order',
            $dc40['ok'] === true && pa_ls($q1) === 'members/date' && $says($page40d, $hasDate) && $confirmsOn($page40d) === [$askDate]
            && str_contains($page40d, 'id="choice-leftover"') === false
            && $says($page40e, $hasSeries) && $confirmsOn($page40e) === [$askSeries],
            pa_show([$dc40['ok'], pa_ls($q1), $confirmsOn($page40d), $confirmsOn($page40e)])
        );
        $rm40   = pw_req($sa, 'POST', $dateUrl40, ['csrf_token' => pw_token($page40d), 'action' => 'remove']);
        $rm40pg = pw_follow($sa, $rm40);
        pa_ok(
            'W40 removing the date\'s own choice: "' . MSG_REMOVED_DATE_UNDER_EVERY . '" — and the date really is decided by the choice for every date again',
            $rm40['status'] === 302 && str_contains($rm40pg['body'], pw_esc(MSG_REMOVED_DATE_UNDER_EVERY)) && pa_ls($q1) === 'hidden/series' && pa_ls($q2) === 'hidden/series',
            pa_show([$rm40['status'], $rm40['location'], pa_ls($q1), pa_ls($q2)])
        );

        // ---------------------------------------------------------------------
        pa_heading('W41 — a choice for every date left over after an event stops repeating: shown, and Remove only (fix round 2 of P8-3, F1)');
        // The resolver finds a choice for every date by the event's identity
        // alone. When an outside calendar turns a repeating event into a
        // one-off but keeps its UID, the importer marks the old dates deleted
        // and brings in one date with no recurrence key — and the old choice
        // keeps deciding it. Before fix round 2 nothing on the pages showed
        // that choice or could remove it, and the sentences said the date
        // followed the rules. The importer's two steps are done here by hand,
        // exactly as it does them (a real refresh needs a real download).
        $c41 = pa_newFeed(ORG_A, 'members', ['name' => 'W41 calendar']);
        [$o1, $o2] = pa_series($c41, 'w41@p8', 'W41 club', ['count' => 2, 'day' => 110]);
        $plain41 = pa_event($c41, 'w41-plain@p8', 'W41 plain one-off', ['day' => 112]);
        $set41   = $resolve($c41);
        $all41   = FeedAdmin::saveChoice($mysqli, $o1, 'series', ORG_A, SA, ['audienceLevel' => 'hidden']);
        pa_q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID IN (?, ?)', 'ii', [$o1, $o2]);
        $one41   = pa_event($c41, 'w41@p8', 'W41 club', ['day' => 111]);
        $set41b  = $resolve($c41);
        // B's own one-off with a leftover choice of B's, for the "not found" check.
        [$bo41]  = pa_series(FB1, 'w41-b@p8', 'W41 BONLY club', ['count' => 1, 'day' => 110]);
        $ball41  = FeedAdmin::saveChoice($mysqli, $bo41, 'series', ORG_B, SB, ['audienceLevel' => 'hidden']);
        pa_q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID = ?', 'i', [$bo41]);
        $bone41  = pa_event(FB1, 'w41-b@p8', 'W41 BONLY club', ['day' => 111]);
        $date41  = $choice . '?event=' . $one41 . '&scope=date';
        $every41 = $choice . '?event=' . $one41 . '&scope=series';
        $pDate41 = pw_req($sa, 'GET', $date41)['body'];
        $box41   = (preg_match('#id="choice-leftover">(.*?)</div>#s', $pDate41, $bm) === 1)
            ? html_entity_decode(trim((string) preg_replace('/\s+/', ' ', strip_tags($bm[1]))), ENT_QUOTES, 'UTF-8') : '';
        pa_ok(
            'W41 the one date left: its page says the choice for every date decides it, and shows that choice ("' . FeedAdmin::LEVEL_LABELS['hidden'] . '", is still saved, its days) with a link to see or remove it; a one-off with no leftover shows no such box',
            $set41 === true && $all41['ok'] === true && $set41b === true && $ball41['ok'] === true && pa_ls($one41) === 'hidden/series'
            && $says($pDate41, $underSeries) && str_contains($box41, 'is still saved: ' . FeedAdmin::LEVEL_LABELS['hidden'] . ', with no first or last day.')
            && str_contains($box41, "except while the date's own choice is in force")
            && str_contains($pDate41, 'href="' . pw_esc($every41) . '">See or remove that choice</a>')
            // A one-off with NO leftover gets no box (fix round 3, R3-3).
            && str_contains(pw_req($sa, 'GET', $choice . '?event=' . $plain41 . '&scope=date')['body'], 'id="choice-leftover"') === false,
            pa_show(['now' => pa_ls($one41), 'box' => $box41])
        );
        $pEvery41 = pw_req($sa, 'GET', $every41);
        $sbEvery  = pw_req($sb, 'GET', $every41)['status'];
        pa_ok(
            'W41 its "every date" page answers: the choice shown, Remove offered (asking with the every-date sentence), and no form to make a new choice; SB (another organisation) gets 403 there',
            $pEvery41['status'] === 200 && str_contains($pEvery41['body'], 'id="choice-leftover"') && str_contains($pEvery41['body'], 'id="choice-form"') === false
            && $confirmsOn($pEvery41['body']) === [$askSeries] && $sbEvery === 403,
            pa_show([$pEvery41['status'], $confirmsOn($pEvery41['body']), $sbEvery])
        );
        $miss41  = pw_req($sa, 'GET', $choice . '?event=919999&scope=series');
        $plainR  = pw_req($sa, 'GET', $choice . '?event=' . $plain41 . '&scope=series');
        $bR41    = pw_req($sa, 'GET', $choice . '?event=' . $bone41 . '&scope=series');
        pa_ok(
            'W41 "every date" of a one-off WITHOUT a leftover choice, and of B\'s one-off WITH one: both 404, the same page as for a number that does not exist',
            $miss41['status'] === 404 && $plainR['status'] === 404 && $bR41['status'] === 404
            && pw_normalise($plainR['body']) === pw_normalise($miss41['body']) && pw_normalise($bR41['body']) === pw_normalise($miss41['body']),
            'HTTP ' . $plainR['status'] . '/' . $bR41['status'] . '/' . $miss41['status']
        );
        $fp41     = pa_fp();
        $save41   = pw_req($sa, 'POST', $every41, ['csrf_token' => pw_token($pEvery41['body']), 'action' => 'save', 'audienceLevel' => 'public', 'detailLevel' => 'basic']);
        $save41pg = pw_follow($sa, $save41);
        pa_ok(
            'W41 trying to SAVE a choice for every date there: refused with its own sentence, back to the same page, nothing written',
            $save41['status'] === 302 && $save41['location'] === $every41
            && str_contains($save41pg['body'], pw_esc('A new choice for every date cannot be made for an event that does not repeat. Nothing was changed.')) && pa_fp() === $fp41,
            pa_show([$save41['status'], $save41['location']])
        );
        $rm41   = pw_req($sa, 'POST', $every41, ['csrf_token' => pw_token($save41pg['body']), 'action' => 'remove']);
        $rm41pg = pw_follow($sa, $rm41);
        $left41 = pa_one("SELECT choiceID FROM tblExternalEventChoices WHERE feedID = ? AND scope = 'series'", 'i', [$c41]);
        pa_ok(
            'W41 Remove: 302 to the calendar page with "' . MSG_REMOVED_SERIES . '"; the leftover is gone, the date follows the calendar again, and that "every date" page now answers 404',
            $rm41['status'] === 302 && $rm41['location'] === $view . '?id=' . $c41 . '#events' && str_contains($rm41pg['body'], pw_esc(MSG_REMOVED_SERIES))
            && $left41 === null && pa_ls($one41) === 'members/calendar' && pw_req($sa, 'GET', $every41)['status'] === 404,
            pa_show([$rm41['status'], $rm41['location'], $left41, pa_ls($one41)])
        );

        // ---------------------------------------------------------------------
        pa_heading('W42 — the leftover box tells the truth while the date\'s own choice is not in force, and of a leftover whose days are over (fix round 3 of P8-3, R3-1)');
        // A choice counts only while it is in force (between its first and
        // last day). So while the one date's OWN choice has not started, or
        // has ended, the leftover choice for every date decides it — and
        // round 2's box said the leftover decided it "unless the date has a
        // choice of its own", contradicting the "Now:" line beside it. And a
        // leftover whose own last day has passed decides nothing, so it must
        // never be said to "still apply". Probe P1's shape, through the page.
        $c42 = pa_newFeed(ORG_A, 'members', ['name' => 'W42 calendar']);
        [$m1, $m2] = pa_series($c42, 'w42@p8', 'W42 club', ['count' => 2, 'day' => 120]);
        [$n1, $n2] = pa_series($c42, 'w42-ended@p8', 'W42 old club', ['count' => 2, 'day' => 130]);
        $set42   = $resolve($c42);
        $start42 = pa_todayLocal()->modify('-10 days')->format('Y-m-d');
        $first42 = pa_todayLocal()->modify('-20 days')->format('Y-m-d');
        $ended42 = pa_todayLocal()->modify('-2 days')->format('Y-m-d');
        $soon42  = pa_todayLocal()->modify('+30 days')->format('Y-m-d');
        $all42   = FeedAdmin::saveChoice($mysqli, $m1, 'series', ORG_A, SA, ['audienceLevel' => 'hidden', 'fromDate' => $start42]);
        $old42   = FeedAdmin::saveChoice($mysqli, $n1, 'series', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG3]], 'fromDate' => $first42, 'toDate' => $ended42]);
        pa_q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID IN (?, ?, ?, ?)', 'iiii', [$m1, $m2, $n1, $n2]);
        $one42    = pa_event($c42, 'w42@p8', 'W42 club', ['day' => 121]);
        $oldOne42 = pa_event($c42, 'w42-ended@p8', 'W42 old club', ['day' => 131]);
        $set42b   = $resolve($c42);
        $url42    = $choice . '?event=' . $one42 . '&scope=date';
        $boxOf    = static function (string $body): string {
            return (preg_match('#id="choice-leftover">(.*?)</div>#s', $body, $bm) === 1)
                ? html_entity_decode(trim((string) preg_replace('/\s+/', ' ', strip_tags($bm[1]))), ENT_QUOTES, 'UTF-8') : '';
        };
        $dayIn    = static fn (string $ymd): string => DateTimeImmutable::createFromFormat('!Y-m-d', $ymd)->format('D j M Y');
        $rule42   = "That choice decides this date while it is in force, except while the date's own choice is in force.";
        $saved42  = 'is still saved: ' . FeedAdmin::LEVEL_LABELS['hidden'] . ', from ' . $dayIn($start42) . '.';
        $nyet42   = FeedAdmin::saveChoice($mysqli, $one42, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'fromDate' => $soon42]);
        $p42a     = pw_req($sa, 'GET', $url42)['body'];
        pa_ok(
            'W42 the date\'s own choice NOT YET in force: the leftover decides it (the "Now:" line says so), and the box agrees — "' . $rule42 . '", with the leftover\'s first day',
            $set42 === true && $all42['ok'] === true && $old42['ok'] === true && $set42b === true && $nyet42['ok'] === true && pa_ls($one42) === 'hidden/series'
            && $says($p42a, '(' . FeedAdmin::sourceLabel('series') . ')') && str_contains($boxOf($p42a), $saved42) && str_contains($boxOf($p42a), $rule42)
            && str_contains($boxOf($p42a), 'unless the date has a choice of its own') === false,
            pa_show(['now' => pa_ls($one42), 'box' => $boxOf($p42a)])
        );
        $end42 = FeedAdmin::saveChoice($mysqli, $one42, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'toDate' => $ended42]);
        $p42b  = pw_req($sa, 'GET', $url42)['body'];
        pa_ok(
            'W42 the date\'s own choice ENDED: the leftover decides it again, and the box still agrees with the "Now:" line',
            $end42['ok'] === true && pa_ls($one42) === 'hidden/series' && $says($p42b, '(' . FeedAdmin::sourceLabel('series') . ')')
            && str_contains($boxOf($p42b), $saved42) && str_contains($boxOf($p42b), $rule42) && str_contains($boxOf($p42b), 'unless the date has a choice of its own') === false,
            pa_show(['now' => pa_ls($one42), 'box' => $boxOf($p42b)])
        );
        $p42c   = pw_req($sa, 'GET', $choice . '?event=' . $oldOne42 . '&scope=date')['body'];
        $box42c = $boxOf($p42c);
        pa_ok(
            'W42 a leftover whose last day has passed: the date follows the calendar (as the "Now:" line says); the box says the leftover "is still saved", gives its days and its group, and never that it still applies',
            pa_ls($oldOne42) === 'members/calendar' && $says($p42c, '(' . FeedAdmin::sourceLabel('calendar') . ')')
            && str_contains($box42c, 'is still saved: ' . FeedAdmin::LEVEL_LABELS['groups']) && str_contains($box42c, 'Selftest SG3')
            && str_contains($box42c, 'from ' . $dayIn($first42) . ' until ' . $dayIn($ended42) . '.') && str_contains($box42c, 'still applies') === false,
            pa_show(['now' => pa_ls($oldOne42), 'box' => $box42c])
        );

        // ---------------------------------------------------------------------
        pa_heading('W43 — the leftover box names each chosen group and person with a state word that is true there (fix round 4 of P8-3, R4-1)');
        // A leftover choice can never be saved, so ExternalAudience's
        // "…will be removed when you save" would be untrue in its box. Its
        // list here holds: SG3 (switched on), SG2 (switched off), PB (a
        // member of B only, so "someone no longer in this organisation" —
        // the shape left when an administrator takes a person off) and I
        // (an account that is switched off: "no longer a member"), a role
        // number that names no role of this organisation ("a role that no
        // longer exists", with nothing added), and the retired user group
        // UG2 ("(switched off)"). The FIVE the form would refuse to add
        // (SG2, PB, I, the role and UG2) are written in by hand, as a list
        // left from earlier days would hold them. (Fix round 4 said "the
        // two"; it wrote three — corrected in chunk P8-4's step 0, R5-2.)
        // UNTIL PART 10 OF #514 the role row read "a role #5 (not used by
        // this version)": roles were a kind no form offered. They are
        // offered now, so that row is simply one that no longer exists
        // here, and UG2 was added to show a part-10 kind's own state word.
        // The words "(not used by this version)" are still on these pages
        // for a kind the database knows and the code does not; no such row
        // can be made today, so no page check can show them (D8 checks the
        // label itself).
        // The WHOLE "Chosen: …" sentence is compared exactly, in the order
        // the rows were saved (chunk P8-4's step 0, check finding R5-1):
        // looking only for pieces let a switched-on group read "(switched
        // off)", and an unused kind say "…when you save" again, while every
        // check passed.
        $c43 = pa_newFeed(ORG_A, 'members', ['name' => 'W43 calendar']);
        [$t1, $t2] = pa_series($c43, 'w43@p8', 'W43 club', ['count' => 2, 'day' => 140]);
        $set43  = $resolve($c43);
        $all43  = FeedAdmin::saveChoice($mysqli, $t1, 'series', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG3]]]);
        $id43   = (int) ($all43['choiceId'] ?? 0);
        pa_list($c43, 'choice', $id43, [['small_group', SG2], ['person', PB], ['person', I_ID], ['role', 5], ['user_group', UG2]]);
        pa_q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID IN (?, ?)', 'ii', [$t1, $t2]);
        $one43  = pa_event($c43, 'w43@p8', 'W43 club', ['day' => 141]);
        $set43b = $resolve($c43);
        $boxes43 = [
            'date page'       => $boxOf(pw_req($sa, 'GET', $choice . '?event=' . $one43 . '&scope=date')['body']),
            'every-date page' => $boxOf(pw_req($sa, 'GET', $choice . '?event=' . $one43 . '&scope=series')['body']),
        ];
        $sg2Name = (string) pa_one('SELECT groupName FROM tblSmallGroups WHERE groupID = ?', 'i', [SG2])['groupName'];
        $chosen43 = 'Chosen: Selftest SG3, ' . $sg2Name . ' (switched off), someone no longer in this organisation, Selftest I (no longer a member), a role that no longer exists, Selftest UG2 (switched off).';
        $bad43   = [];
        foreach ($boxes43 as $where => $box) {
            foreach ([
                ' ' . $chosen43 . ' That choice decides this date',
            ] as $want) {
                if (str_contains($box, $want) === false) {
                    $bad43[] = $where . ' lacks "' . $want . '"';
                }
            }
            foreach (['when you save', 'organisation (', '(('] as $never) {
                if (str_contains($box, $never) === true) {
                    $bad43[] = $where . ' says "' . $never . '"';
                }
            }
        }
        pa_ok(
            'W43 on both pages the box\'s "Chosen:" sentence is exactly right — a switched-on group by name alone, a switched-off group "(switched off)", a person no longer in the organisation by that label alone, a person who left "(no longer a member)", a role that names nothing here by its label alone, a retired user group "(switched off)" — never "…when you save", with no brackets inside brackets',
            $set43 === true && $all43['ok'] === true && $id43 > 0 && $set43b === true && $bad43 === [],
            implode(' | ', $bad43) . ($bad43 === [] ? '' : ' || the date page\'s box: ' . mb_substr($boxes43['date page'], 0, 600))
        );

        // ---------------------------------------------------------------------
        pa_heading('W35 — the series page refuses a series holding imported events, exactly like one that does not exist (challenge M5)');
        // Three series made by hand (the series page itself is not part 8's):
        // one of A's own, one of A's holding an imported event, and one of B's.
        pa_q(
            "INSERT INTO tblEventSeries (seriesID, siteID, seriesName, seriesSlug) VALUES (919701, ?, 'W35 own series', 'w35-own'), (919702, ?, 'W35 imported series', 'w35-imp'), (919703, ?, 'W35 series BONLY', 'w35-b')",
            'iii', [ORG_A, ORG_A, ORG_B]
        );
        $e35 = pa_event($c34, 'w35@p8', 'W35 imported, in a series');
        pa_q('UPDATE tblEvents SET seriesID = 919702 WHERE eventID = ?', 'i', [$e35]);
        $se35    = PW_A . '/calendar/manage/series-edit?seriesID=';
        $answers = [];
        foreach (['a series holding an imported event' => 919702, 'a number that does not exist' => 919999, 'B\'s series' => 919703] as $label => $id) {
            $r = pw_req($sa, 'GET', $se35 . $id);
            // The page sends everybody to its bare list address (#536's, not
            // part 8's); the message is read from the organisation's own list.
            $answers['GET ' . $label] = [$r['status'], $r['location'], str_contains(pw_req($sa, 'GET', PW_A . '/calendar/manage/series')['body'], 'Series not found.')];
        }
        foreach (['a series holding an imported event' => 919702, 'a number that does not exist' => 919999] as $label => $id) {
            $r = pw_req($sa, 'POST', $se35 . $id, ['csrf_token' => pw_freshToken($sa, $list), 'seriesID' => (string) $id]);
            $answers['POST ' . $label] = [$r['status'], $r['location'], str_contains(pw_req($sa, 'GET', PW_A . '/calendar/manage/series')['body'], 'Series not found.')];
        }
        $first35 = reset($answers);
        pa_ok(
            'W35 a series holding an imported event, a number that does not exist and B\'s series (GET, and POST for the first two): the same redirect each time, and "Series not found."',
            count(array_unique(array_map('serialize', $answers))) === 1 && $first35[0] === 302 && $first35[2] === true,
            pa_show($answers)
        );
        $own35 = pw_req($sa, 'GET', $se35 . '919701');
        pa_ok('W35 the organisation\'s own series still opens: 200, with its name', $own35['status'] === 200 && str_contains($own35['body'], 'W35 own series'), 'HTTP ' . $own35['status'] . ' ' . $own35['location']);

        // ---------------------------------------------------------------------
        pa_heading('W36 — who sees it, both directions, end to end through the choice page (acceptance 8)');
        $c36a = pa_newFeed(ORG_A, 'members', ['name' => 'W36 members calendar']);
        // Days no other check uses: a month page shows only the first few events
        // of a crowded day (the made-up world puts most of its events seven
        // days ahead), so a date there can be cut off the page while visible.
        [$a1, $a2] = pa_series($c36a, 'w36a@p8', 'W36a', ['count' => 2, 'day' => 60, 'titles' => ['W36a first Kv8', 'W36a second Kv8'], 'description' => 'W36a secret description Jy4']);
        $c36b = pa_newFeed(ORG_A, 'public', ['name' => 'W36 public calendar']);
        [$b1, $b2] = pa_series($c36b, 'w36b@p8', 'W36b', ['count' => 2, 'day' => 75, 'titles' => ['W36b first Hw2', 'W36b second Hw2']]);
        $set36 = ($resolve($c36a) === true && $resolve($c36b) === true);
        // A signed-out visitor's month page for the month the date falls in.
        $sees = static function (int $id, string $title) use ($out): bool {
            $month = substr((string) pa_one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$id])['startDateTime'], 0, 7);

            return str_contains(pw_req($out, 'GET', PW_A . '/calendar?view=month&date=' . $month)['body'], $title);
        };
        $beforeA = [$sees($a1, 'W36a first Kv8'), $sees($a2, 'W36a second Kv8')];
        pa_ok('W36 before: a signed-out visitor sees neither date of the Members calendar\'s repeating event', $set36 === true && $beforeA === [false, false], pa_show($beforeA));
        $pageA  = pw_req($sa, 'GET', $choice . '?event=' . $a1 . '&scope=series')['body'];
        $saveA  = pw_req($sa, 'POST', $choice . '?event=' . $a1 . '&scope=series', ['csrf_token' => pw_token($pageA), 'action' => 'save', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'seen' => $seenOn($pageA)]);
        $afterA = [$sees($a1, 'W36a first Kv8'), $sees($a2, 'W36a second Kv8')];
        pa_ok('W36 a choice for every date saved through the page as Everyone, "title, date and time only": 302, and the visitor now sees both dates', $saveA['status'] === 302 && $afterA === [true, true], pa_show([$saveA['status'], $saveA['location'], $afterA]));
        $evOut = pw_req($out, 'GET', PW_A . '/calendar/event?slug=imp-selftest-p8-' . $a1);
        $evSa  = pw_req($sa, 'GET', PW_A . '/calendar/event?slug=imp-selftest-p8-' . $a1);
        pa_ok(
            'W36 the first date\'s own page: the visitor sees its title and no description, while SA (inside the calendar\'s own audience) sees the description',
            $evOut['status'] === 200 && str_contains($evOut['body'], 'W36a first Kv8') && str_contains($evOut['body'], 'W36a secret description Jy4') === false
            && str_contains($evSa['body'], 'W36a secret description Jy4'),
            'HTTP ' . $evOut['status'] . '/' . $evSa['status']
        );
        $beforeB = [$sees($b1, 'W36b first Hw2'), $sees($b2, 'W36b second Hw2')];
        $pageB   = pw_req($sa, 'GET', $choice . '?event=' . $b1 . '&scope=date')['body'];
        $saveB   = pw_req($sa, 'POST', $choice . '?event=' . $b1 . '&scope=date', ['csrf_token' => pw_token($pageB), 'action' => 'save', 'audienceLevel' => 'hidden', 'detailLevel' => 'basic', 'seen' => $seenOn($pageB)]);
        $afterB  = [$sees($b1, 'W36b first Hw2'), $sees($b2, 'W36b second Hw2')];
        pa_ok(
            'W36 the other way, on the Public calendar: a choice for ONE date, "Only administrators" — the visitor saw both dates before; afterwards the first is gone and the second remains',
            $beforeB === [true, true] && $saveB['status'] === 302 && $afterB === [false, true],
            pa_show([$beforeB, $saveB['status'], $afterB])
        );

        // ---------------------------------------------------------------------
        pa_heading('W37 — the new pages: every field has a label, every address keeps the organisation\'s prefix');
        foreach ([
            'the choice page (every date)' => $choice . '?event=' . $s1 . '&scope=series',
            'the choice page (one date)'   => $choice . '?event=' . $s2 . '&scope=date',
            'the rule page (new)'          => $ruleP . '?feed=' . $c29,
            'the rule page (saved)'        => $ruleP . '?feed=' . $c29 . '&rule=' . $rule33id,
        ] as $label => $url) {
            $r = pw_req($sa, 'GET', $url);
            $u = pw_unlabelled($r['body']);
            pa_ok("W37 {$label}: every visible input, select and text box has a label or an aria-label ({$u['count']} looked at)", $r['status'] === 200 && $u['bad'] === [] && $u['count'] > 0, implode(', ', $u['bad']));
            $main = pw_main($r['body']);
            preg_match_all('/\b(?:href|action)="([^"]*)"/', $main, $mm);
            $bad = [];
            $n   = 0;
            foreach ($mm[1] as $address) {
                $address = html_entity_decode($address, ENT_QUOTES, 'UTF-8');
                if (str_starts_with($address, '/') === false || str_starts_with($address, '/assets/') === true) {
                    continue;
                }
                $n++;
                if (str_starts_with($address, PW_A . '/') === false) {
                    $bad[] = $address;
                }
            }
            pa_ok("W37 {$label}: every link and form address ({$n}) starts with " . PW_A . '/', $main !== '' && $n > 0 && $bad === [], implode(', ', $bad));
        }
        preg_match_all('/href="([^"]*)"/', pw_main(pw_req($sa, 'GET', $view . '?id=' . $c29)['body']), $all37);
        $hrefs37    = array_map(static fn (string $h): string => html_entity_decode($h, ENT_QUOTES, 'UTF-8'), $all37[1]);
        $followed37 = [];
        foreach ([$choice . '?event=' . $s1 . '&scope=date', $choice . '?event=' . $s1 . '&scope=series', $ruleP . '?feed=' . $c29, $ruleP . '?feed=' . $c29 . '&rule=' . $rule33id] as $wanted) {
            $followed37[] = in_array($wanted, $hrefs37, true) === true ? pw_req($sa, 'GET', $wanted)['status'] : 'no such link on the page';
        }
        pa_ok('W37 four links followed from the calendar page (Set this date…, Set every date…, Add a rule, Edit) each answer 200', $followed37 === [200, 200, 200, 200], pa_show($followed37));

        // ---------------------------------------------------------------------
        pa_heading('W38 — the test web server\'s own rules hold against raw addresses (carried from P8-2\'s check round 2)');
        // Every address below is sent EXACTLY as written (curl would otherwise
        // tidy "/../" away). The targets are real files of this run's scratch
        // copy that sit OUTSIDE its web root: the full install script and the
        // copy's own throwaway encryption key. A raw NUL byte cannot be sent
        // at all — PHP's built-in server drops that connection before the
        // router runs (measured in P8-2's check round 2) — so only its
        // encoded form, %00, is sent.
        // WHAT IT CANNOT PROVE: the router's dot-file rule refuses every
        // address holding "/." — every "/../" included — BEFORE the web-root
        // fence is reached, and the router never decodes %2e. So no address
        // reaches the fence while the dot rule stands: removing the fence
        // ALONE fails no check here (it is the second wall). Removing the dot
        // rule fails the dot-file check; removing both shows the files and
        // fails the first check.
        $keyText = (string) @file_get_contents($pw['child'] . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'enc.key');
        $rawSeen = static function (array $paths, array $statuses, array $mustNotShow) use ($out): array {
            $wrong = [];
            foreach ($paths as $path) {
                $r = pw_req($out, 'GET', $path, null, true);
                $leak = false;
                foreach ($mustNotShow as $text) {
                    if ($text !== '' && str_contains($r['body'], $text) === true) {
                        $leak = true;
                    }
                }
                if (in_array($r['status'], $statuses, true) === false || $leak === true) {
                    $wrong[] = $path . ' → HTTP ' . $r['status'] . ($leak === true ? ' and SHOWED what it must not' : '');
                }
            }

            return $wrong;
        };
        $outside = ['CREATE TABLE', $keyText];
        $w38 = $rawSeen(['/../../web/_sql/full_schema.sql', '/assets/../../../web/_sql/full_schema.sql', '/../../web/_auth_keys/enc.key', '/assets/css/../../../../web/_auth_keys/enc.key', '/api-docs/../manifest.php'], [403, 404], array_merge($outside, ['"short_name"']));
        pa_ok('W38 "/../" out of the web root (to the install script and the encryption key of this run\'s copy): refused, nothing shown', $keyText !== '' && $w38 === [], implode(' | ', $w38));
        $w38 = $rawSeen(['/%2e%2e/%2e%2e/web/_sql/full_schema.sql', '/assets/%2E%2E/%2E%2E/%2E%2E/web/_auth_keys/enc.key', '/..%2f..%2fweb%2f_sql%2ffull_schema.sql'], [403, 404], $outside);
        pa_ok('W38 the same with the dots and slashes written as %2e and %2f: refused, nothing shown', $w38 === [], implode(' | ', $w38));
        $w38 = $rawSeen(["/..\\..\\web\\_sql\\full_schema.sql", '/..%5c..%5cweb%5c_auth_keys%5cenc.key', "/assets\\..\\..\\..\\web\\_auth_keys\\enc.key"], [403, 404], $outside);
        pa_ok('W38 the same with backslashes, plain and as %5c: refused, nothing shown', $w38 === [], implode(' | ', $w38));
        $w38 = $rawSeen(['/.htaccess', '/../../.feed-admin-selftest-owner', '/.git/config', '/assets/css/../../.htaccess'], [403], ['RewriteRule', (string) $pw['token']]);
        pa_ok('W38 dot files ("/.htaccess", the run\'s own marker, "/.git/…"): 403, nothing shown', $w38 === [], implode(' | ', $w38));
        $w38 = $rawSeen(['/manifest.php', '/manifest.PHP', '/openapi.php', '/x.phtml', '/x.phar', '/index.php5', '/assets/css/portal.css%00.php'], [404], ['<?php', '"short_name"']);
        pa_ok('W38 a PHP file other than the three entry points, in any letter case or with a .phtml, .phar or .php5 ending: 404, never run and never sent as text', $w38 === [], implode(' | ', $w38));
        $w38 = $rawSeen(['/manifest.php%00', '/manifest.php%00.css', '/index.php%00.json'], [403, 404], ['<?php', '"short_name"']);
        pa_ok('W38 an encoded NUL after a PHP file\'s name: refused, the file never run and never sent', $w38 === [], implode(' | ', $w38));

        // =====================================================================
        // 🧩 CHUNK P8-4 — the preview, the decisions, the inbox, the guide and
        //    the notices (the plan's section 11.3, "P8-4", as item 10 of its
        //    top sections reworded it: a decision NEVER says how many other
        //    dates "followed", and a waiting row's sibling line is a present
        //    fact, never a prediction).
        // =====================================================================
        $preview = PW_A . '/admin/calendar/feeds/preview';
        $act     = PW_A . '/admin/calendar/feeds/approvals/act';
        $inbox   = PW_A . '/approvals';
        $help84  = PW_A . '/help/calendar-imports';
        /** A waiting row posted back exactly as its own Approve or Decline button posts it. */
        $post84 = static fn (array $row, int $feed, string $decision, string $token, string $back = 'approvals'): array => [
            'csrf_token' => $token, 'decision' => $decision, 'approvalID' => (int) $row['approvalID'],
            'requestHash' => (string) $row['requestHash'], 'contentHash' => (string) $row['contentHash'],
            'back' => $back, 'feed' => $feed, 'note' => '',
        ];
        /** One waiting row as a tick box of the bulk form carries it: "number:request:content". */
        $tick84 = static fn (array $row): string => (int) $row['approvalID'] . ':' . (string) $row['requestHash'] . ':' . (string) $row['contentHash'];
        /** A page's text as a reader sees it: tags out, entities decoded, every run of white space one space. */
        $plain84 = static fn (string $html): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        /** One `<section id="…">` of a page (these sections hold no section of their own), or ''. */
        $section84 = static fn (string $html, string $id): string
            => preg_match('#<section\b[^>]*\bid="' . preg_quote($id, '#') . '"[^>]*>.*?</section>#s', $html, $mm) === 1 ? $mm[0] : '';
        /** The page's message box (the first alert): [its kind, its text], or ['', ''] when there is none. */
        $flash84 = static function (string $html) use ($plain84): array {
            if (preg_match('#<div class="alert alert-(success|warning|danger|info)\b[^"]*"[^>]*>(.*?)(?:<button|</div>)#s', $html, $mm) === 1) {
                return [$mm[1], $plain84($mm[2])];
            }

            return ['', ''];
        };
        /** Every link and form address in `$html` that starts with "/" (assets aside): how many, and those without the organisation's prefix. */
        $prefix84 = static function (string $html): array {
            preg_match_all('/\b(?:href|action)="([^"]*)"/', $html, $mm);
            $bad = [];
            $n   = 0;
            foreach ($mm[1] as $address) {
                $address = html_entity_decode($address, ENT_QUOTES, 'UTF-8');
                if (str_starts_with($address, '/') === false || str_starts_with($address, '/assets/') === true) {
                    continue;
                }
                $n++;
                if (str_starts_with($address, PW_A . '/') === false) {
                    $bad[] = $address;
                }
            }

            return ['count' => $n, 'bad' => $bad];
        };
        /** How deep forms sit inside forms in `$html` (1 = never one inside another). HTML forbids a form inside a form. */
        $depth84 = static function (string $html): int {
            preg_match_all('#<(/?)form\b#i', $html, $mm);
            $depth = 0;
            $max   = 0;
            foreach ($mm[1] as $slash) {
                $depth += ($slash === '/') ? -1 : 1;
                $max    = max($max, $depth);
            }

            return $max;
        };
        $predicts84 = '/\b(will follow|applies to them|applies to it|followed)\b/i';
        /**
         * The hidden fields of the ONE form on a page that carries waiting date
         * `$approvalId` (its own Approve/Decline form), exactly as the page has
         * them — so a check posts what a browser would post, and a wrong value
         * in the template (the check's N08) is what gets sent (fix round 1).
         *
         * @return array<string,string>
         */
        $rowForm84 = static function (string $html, int $approvalId): array {
            $fields = [];
            if (preg_match_all('#<form\b[^>]*>(.*?)</form>#s', $html, $forms) > 0) {
                foreach ($forms[1] as $inner) {
                    if (str_contains($inner, 'name="approvalID" value="' . $approvalId . '"') === false) {
                        continue;
                    }
                    preg_match_all('#<input type="hidden" name="([^"]+)" value="([^"]*)"#', $inner, $inputs, PREG_SET_ORDER);
                    foreach ($inputs as $input) {
                        $fields[$input[1]] = html_entity_decode($input[2], ENT_QUOTES, 'UTF-8');
                    }
                    break;
                }
            }

            return $fields;
        };

        // ---------------------------------------------------------------------
        pa_heading('W44 — the preview and the decisions: nobody else gets in, and another organisation\'s request looks like one that does not exist (chunk P8-4)');
        $c44  = pa_newFeed(ORG_A, 'members', ['name' => 'W44 calendar']);
        $e44  = pa_event($c44, 'w44@p8', 'W44 open evening');
        $r44  = FeedAdmin::saveRule($mysqli, $c44, 0, ORG_A, SA, ['name' => 'W44 rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W44']]]);
        $cB44 = pa_newFeed(ORG_B, 'members', ['name' => 'W44 BONLY calendar']);
        $eB44 = pa_event($cB44, 'w44-b@p8', 'W44 BONLY evening');
        $rB44 = FeedAdmin::saveRule($mysqli, $cB44, 0, ORG_B, SB, ['name' => 'W44 BONLY rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W44']]]);
        $pA44 = pa_pending($e44);
        $pB44 = pa_pending($eB44);
        if ($r44['ok'] !== true || $rB44['ok'] !== true || $pA44 === null || $pB44 === null) {
            pw_refuse('W44 could not build one waiting date in A and one in B: ' . pa_show([$r44, $rB44]));
        }
        $who44 = [];
        foreach (['M' => $m, 'LA' => $la, 'SB' => $sb] as $name => $jar) {
            $who44[$name] = [
                pw_req($jar, 'GET', $preview . '?approval=' . (int) $pA44['approvalID'])['status'],
                pw_req($jar, 'GET', $preview . '?event=' . $e44)['status'],
            ];
        }
        $outPrev44 = pw_req($out, 'GET', $preview . '?approval=' . (int) $pA44['approvalID']);
        pa_ok(
            'W44 the preview answers 403 to M, LA (the old portal-wide flag only) and SB (an administrator of B only), for a request and for a date; a signed-out visitor is sent to sign in',
            $who44 === ['M' => [403, 403], 'LA' => [403, 403], 'SB' => [403, 403]] && $outPrev44['status'] === 302 && str_contains($outPrev44['location'], 'login') === true,
            pa_show([$who44, $outPrev44['status'], $outPrev44['location']])
        );
        $nf44 = [];
        foreach ([
            'B\'s request'    => '?approval=' . (int) $pB44['approvalID'],
            'no such request' => '?approval=919999',
            'B\'s date'       => '?event=' . $eB44,
            'no such date'    => '?event=919999',
            'both at once'    => '?approval=' . (int) $pA44['approvalID'] . '&event=' . $e44,
            'neither'         => '',
        ] as $label => $query) {
            $r             = pw_req($sa, 'GET', $preview . $query);
            $nf44[$label] = [$r['status'], pw_normalise($r['body'])];
        }
        $distinct44 = count(array_unique(array_column($nf44, 1)));
        pa_ok(
            'W44 SA: the preview of B\'s request or B\'s date, of a number that does not exist, and with both or neither given, is the same 404 page every time',
            array_values(array_unique(array_column($nf44, 0))) === [404] && $distinct44 === 1 && $nf44['neither'][1] !== '',
            pa_show(array_map(static fn (array $x): int => $x[0], $nf44)) . ' different pages: ' . $distinct44
        );
        $fp44   = pa_fp();
        $acts44 = [];
        foreach (['M' => $m, 'LA' => $la, 'SB' => $sb] as $name => $jar) {
            // A VALID token of their own (the guide is open to everybody), so a
            // gate that let them through would reach FeedAdmin and answer 302,
            // not 403 (the plan's control 5).
            $tok            = pw_freshToken($jar, $help84);
            $acts44[$name] = [$tok !== '', pw_req($jar, 'POST', $act, $post84($pA44, $c44, 'approved', $tok))['status']];
        }
        pa_ok(
            'W44 the decision handler answers 403 to M, LA and SB, each posting A\'s waiting date with a VALID form token of their own; the date is still waiting and nothing changed',
            $acts44 === ['M' => [true, 403], 'LA' => [true, 403], 'SB' => [true, 403]] && pa_status((int) $pA44['approvalID']) === 'pending' && pa_fp() === $fp44,
            pa_show($acts44)
        );
        $tok44 = [];
        foreach (['no token' => '', 'a wrong token' => str_repeat('a', 64)] as $label => $token) {
            $r              = pw_req($sa, 'POST', $act, $post84($pA44, $c44, 'approved', $token));
            $tok44[$label] = [$r['status'], $r['location'], $flash84(pw_follow($sa, $r)['body'])];
        }
        $wantTok44 = [302, $inbox . '#imports', ['danger', 'Invalid or expired form token. Nothing was changed. Please try again.']];
        pa_ok(
            'W44 SA with no form token, or a wrong one: 302 back to the inbox, which says "Invalid or expired form token. Nothing was changed. Please try again."; the date still waiting',
            $tok44['no token'] === $wantTok44 && $tok44['a wrong token'] === $wantTok44 && pa_status((int) $pA44['approvalID']) === 'pending' && pa_fp() === $fp44,
            pa_show($tok44)
        );
        $bPost44 = pw_req($sa, 'POST', $act, $post84($pB44, $cB44, 'approved', pw_freshToken($sa, $inbox)));
        $bSays44 = $flash84(pw_follow($sa, $bPost44)['body']);
        $nPost44 = pw_req($sa, 'POST', $act, ['approvalID' => 919999] + $post84($pB44, $cB44, 'approved', pw_freshToken($sa, $inbox)));
        $nSays44 = $flash84(pw_follow($sa, $nPost44)['body']);
        $changed44 = ['warning', 'Approved 0. 1 had changed since you opened the page; please review it again.'];
        pa_ok(
            'W44 SA posting B\'s waiting date (B\'s own fingerprints, a valid token): back to the inbox with "Approved 0. 1 had changed since you opened the page; please review it again." — exactly what a number that does not exist gets — and B\'s date is still waiting',
            $bPost44['status'] === 302 && $bPost44['location'] === $inbox . '#imports' && $bSays44 === $changed44 && $nSays44 === $changed44
            && pa_status((int) $pB44['approvalID']) === 'pending',
            pa_show([$bPost44['status'], $bPost44['location'], $bSays44, $nSays44])
        );

        // ---------------------------------------------------------------------
        pa_heading('W45 — the preview shows what was asked for, and only that (chunk P8-4)');
        // The calendar's default category, CA1, is what the resolver gives its
        // events (no category map here).
        $c45  = pa_newFeed(ORG_A, 'members', ['name' => 'W45 calendar', 'categoryID' => CA1]);
        $s45  = pa_series($c45, 'w45-s@p8', 'W45 weekly talk', ['count' => 3, 'description' => "First line\nSecond line", 'location' => 'W45 hall', 'url' => 'https://example.invalid/w45-talk']);
        $ch45 = pa_event($c45, 'w45-c@p8', 'W45 weekly concert', ['location' => 'W45 old place', 'day' => 12]);
        $b45  = pa_event($c45, 'w45-b@p8', 'W45 basic evening', ['description' => 'W45 hidden words', 'location' => 'W45 hidden place', 'url' => 'https://example.invalid/w45-hidden']);
        $rf45 = FeedAdmin::saveRule($mysqli, $c45, 0, ORG_A, SA, ['name' => 'W45 full', 'audienceLevel' => 'public', 'detailLevel' => 'full', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W45 weekly']]]);
        $rb45 = FeedAdmin::saveRule($mysqli, $c45, 0, ORG_A, SA, ['name' => 'W45 basic', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W45 basic']]]);
        $pf45 = pa_pending($s45[0]);
        $pb45 = pa_pending($b45);
        $pc45 = pa_pending($ch45);
        if ($rf45['ok'] !== true || $rb45['ok'] !== true || $pf45 === null || $pb45 === null || $pc45 === null) {
            pw_refuse('W45 could not build its waiting dates: ' . pa_show([$rf45, $rb45]));
        }
        $full45 = pw_req($sa, 'GET', $preview . '?approval=' . (int) $pf45['approvalID']);
        $fm45   = pw_main($full45['body']);
        pa_ok(
            'W45 a request at full detail: 200, headed "What everyone will see, including people who are not signed in", with the title, the category, the description as text with its line break kept, the location — and the link as PLAIN TEXT, never a clickable link',
            $full45['status'] === 200 && str_contains($fm45, 'What everyone will see, including people who are not signed in') === true
            && str_contains($fm45, '>W45 weekly talk<') === true && str_contains($fm45, '>Selftest CA1<') === true
            && str_contains($fm45, "First line<br />\nSecond line") === true && str_contains($fm45, 'W45 hall') === true
            && str_contains($fm45, '<span class="font-monospace">https://example.invalid/w45-talk</span>') === true
            && str_contains($fm45, 'href="https://example.invalid/w45-talk"') === false && preg_match('#<a\b[^>]*>[^<]*example\.invalid#', $fm45) !== 1,
            'HTTP ' . $full45['status']
        );
        pa_ok(
            'W45 it says why the date waits and since when, and — as a fact about now, never a prediction — "2 other dates of this repeating event are waiting for the same decision."; Back goes to the inbox',
            str_contains($fm45, pw_esc('Why: ' . FeedAdmin::REASON_LABELS[(string) $pf45['reason']])) === true && str_contains($fm45, 'Waiting since ') === true
            && str_contains($fm45, '2 other dates of this repeating event are waiting for the same decision.') === true
            && preg_match($predicts84, $plain84($fm45)) !== 1 && str_contains($fm45, 'href="' . $inbox . '#imports"') === true,
            mb_substr($plain84($fm45), 0, 600)
        );
        $bas45 = pw_main(pw_req($sa, 'GET', $preview . '?approval=' . (int) $pb45['approvalID'])['body']);
        pa_ok(
            'W45 a request at "Title, date and time only" for Everyone, on a Members calendar: the title, and "…left out for people outside the calendar\'s own audience; people inside it see them as well." (the final check\'s F2) — none of the three is on the page, and no sibling line for a one-off date',
            str_contains($bas45, 'W45 basic evening') === true
            && str_contains($plain84($bas45), 'At this level of detail the description, the location and the link are left out for people outside the calendar\'s own audience; people inside it see them as well.') === true
            && str_contains($bas45, 'W45 hidden words') === false && str_contains($bas45, 'W45 hidden place') === false && str_contains($bas45, 'w45-hidden') === false
            && str_contains($bas45, 'id="preview-siblings"') === false,
            mb_substr($plain84($bas45), 0, 400)
        );
        // An approved date whose location then changes: at full detail that
        // sends it back for approval, and the preview names what changed.
        $dec45 = FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($pc45)]);
        pa_q('UPDATE tblEvents SET locationName = ? WHERE eventID = ?', 'si', ['W45 new place', $ch45]);
        $re45  = FeedAdmin::saveCalendar($mysqli, $c45, ORG_A, SA, pa_calPost($c45));
        $pc45b = pa_pending($ch45);
        $chg45 = $pc45b === null ? '' : pw_main(pw_req($sa, 'GET', $preview . '?approval=' . (int) $pc45b['approvalID'])['body']);
        pa_ok(
            'W45 a date approved, then its location changed (so it waits again): its preview says "Changed since it was last decided: the location." — and nothing else changed',
            $dec45['decided'] === 1 && $re45['ok'] === true && $pc45b !== null && str_contains($chg45, 'Changed since it was last decided: the location.') === true
            && str_contains($chg45, 'W45 new place') === true,
            mb_substr($plain84($chg45), 0, 500)
        );
        $g45  = pa_newFeed(ORG_A, 'members', ['name' => 'W45 groups calendar']);
        $ge45 = pa_event($g45, 'w45-g@p8', 'W45 group supper');
        $gs45 = FeedAdmin::saveCalendar($mysqli, $g45, ORG_A, SA, pa_calPost($g45, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG3]]]));
        $date45 = pw_req($sa, 'GET', $preview . '?event=' . $ge45);
        $dm45   = pw_main($date45['body']);
        pa_ok(
            'W45 the preview of a date as it is set now (?event=): 200, "What the selected groups and people will see", "A date as it is set now", the chosen group by name, and Back to the calendar page\'s events',
            $gs45['ok'] === true && $date45['status'] === 200 && str_contains($dm45, 'What the selected groups and people will see') === true
            && str_contains($dm45, 'A date as it is set now') === true && str_contains($dm45, 'Chosen: Selftest SG3') === true
            && str_contains($dm45, 'href="' . pw_esc($view . '?id=' . $g45 . '#events') . '"') === true,
            'HTTP ' . $date45['status'] . ' ' . mb_substr($plain84($dm45), 0, 400)
        );
        // Fix round 1 (the check's LOW 3, its probe X1g): once that waiting
        // request is APPROVED, its preview says when — and never "Changed
        // since it was last decided", which would no longer be true (it was
        // last decided on these very details).
        $dec45b = ($pc45b === null) ? ['decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($pc45b)]);
        $apr45  = ($pc45b === null) ? '' : pw_main(pw_req($sa, 'GET', $preview . '?approval=' . (int) $pc45b['approvalID'])['body']);
        pa_ok(
            'W45 the same request once approved: "It was approved on …" and NO "Changed since it was last decided" line (the check\'s LOW 3)',
            $dec45b['decided'] === 1 && str_contains($apr45, 'It was approved on ') === true && str_contains($apr45, 'Changed since it was last decided') === false,
            mb_substr($plain84($apr45), 0, 500)
        );
        // Fix round 1 (the check's Found 1): the date preview (?event=) was
        // linked from nowhere. The choice page now links it, beside the date.
        $chp45 = pw_req($sa, 'GET', PW_A . '/admin/calendar/feeds/choice?event=' . $ge45 . '&scope=date')['body'];
        $lnk45 = preg_match('#<a\b[^>]*\bid="choice-preview"[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>#s', $chp45, $mlk) === 1 ? html_entity_decode($mlk[1], ENT_QUOTES, 'UTF-8') : '';
        $lbl45 = ($lnk45 === '') ? '' : $plain84($mlk[2]);
        $fol45 = ($lnk45 === '') ? ['status' => 0, 'body' => ''] : pw_req($sa, 'GET', $lnk45);
        pa_ok(
            'W45 the choice page links "Preview what people see" (fix round 2: a label true in every state) to ' . $preview . '?event=N for its date, and the link answers 200 with "A date as it is set now"',
            $lnk45 === $preview . '?event=' . $ge45 && $lbl45 === 'Preview what people see' && $fol45['status'] === 200 && str_contains($fol45['body'], 'A date as it is set now') === true,
            pa_show([$lnk45, $lbl45, $fol45['status']])
        );
        // Fix round 2 (check round 2's LOW 1, its probe X3): the stored answer
        // is not what anybody sees while the calendar is PAUSED (hidden from
        // everyone, administrators included) or once the date's answer has RUN
        // OUT (administrators only until the scheduled refresh). The previews
        // say so at the top, in the calendar pages' own words. Paused through
        // the real page (and resumed after); run out by moving the date's
        // recheck time into the past, as the clock would.
        $paused45 = 'That calendar is paused. Its events are hidden until you resume it.';
        $runout45 = 'Until the scheduled refresh next runs, this date is shown only to administrators.';
        $pauseOk45 = [];
        foreach ([$g45, $c45] as $feedNo) {
            $pauseOk45[] = pw_req($sa, 'POST', $save, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'pause', 'feedID' => $feedNo, 'back' => 'list'])['status'];
        }
        $pd45 = $plain84(pw_main(pw_req($sa, 'GET', $preview . '?event=' . $ge45)['body']));
        $pa45 = $plain84(pw_main(pw_req($sa, 'GET', $preview . '?approval=' . (int) $pf45['approvalID'])['body']));
        foreach ([$g45, $c45] as $feedNo) {
            $pauseOk45[] = pw_req($sa, 'POST', $save, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'resume', 'feedID' => $feedNo, 'back' => 'list'])['status'];
        }
        $after45 = $plain84(pw_main(pw_req($sa, 'GET', $preview . '?event=' . $ge45)['body']));
        // Fix round 3 (check round 3's LOW, its N1 and N1b): the REQUEST's
        // preview is read again once its calendar runs, so a notice that
        // shows whatever the calendar's state — or reads the wrong column —
        // fails here, not only one that never shows.
        $afterA45 = $plain84(pw_main(pw_req($sa, 'GET', $preview . '?approval=' . (int) $pf45['approvalID'])['body']));
        pa_ok(
            'W45 the calendar paused through the page: the date\'s preview AND a request\'s preview say "Paused — ' . $paused45 . '"; once resumed, NEITHER says so (fix round 2, LOW 1; fix round 3)',
            $pauseOk45 === [302, 302, 302, 302] && str_contains($pd45, 'Paused ' . $paused45) === true && str_contains($pa45, 'Paused ' . $paused45) === true
            && str_contains($after45, $paused45) === false && str_contains($afterA45, $paused45) === false
            && str_contains($afterA45, 'What everyone will see, including people who are not signed in') === true && str_contains($pd45, $runout45) === false,
            pa_show([$pauseOk45, mb_substr($pd45, 0, 260), mb_substr($pa45, 0, 260), mb_substr($afterA45, 0, 200)])
        );
        pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE eventID = ?', 'i', [$ge45]);
        $ro45 = $plain84(pw_main(pw_req($sa, 'GET', $preview . '?event=' . $ge45)['body']));
        // Fix round 3 (check round 3's Found, its N4): paused AND run out at
        // once. Nobody sees the date while the calendar is paused, so ONLY the
        // paused notice may show — "shown only to administrators" would be
        // untrue beside it. Paused through the page first, and the recheck
        // time set into the past after that, so nothing the pause does can
        // renew it; resumed again before the next check.
        $both45ok = [pw_req($sa, 'POST', $save, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'pause', 'feedID' => $g45, 'back' => 'list'])['status']];
        pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE eventID = ?', 'i', [$ge45]);
        $both45 = $plain84(pw_main(pw_req($sa, 'GET', $preview . '?event=' . $ge45)['body']));
        $both45ok[] = pw_req($sa, 'POST', $save, ['csrf_token' => pw_freshToken($sa, $list), 'action' => 'resume', 'feedID' => $g45, 'back' => 'list'])['status'];
        pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE eventID = ?', 'i', [$ge45]);
        pa_ok(
            'W45 the calendar paused AND the date\'s answer run out: the date\'s preview shows the paused notice ONLY, never "' . $runout45 . '" beside it (fix round 3)',
            $both45ok === [302, 302] && str_contains($both45, 'Paused ' . $paused45) === true && str_contains($both45, $runout45) === false
            && str_contains($both45, 'Waiting for the scheduled refresh') === false,
            pa_show([$both45ok, mb_substr($both45, 0, 300)])
        );
        $rs45 = FeedAdmin::saveCalendar($mysqli, $g45, ORG_A, SA, pa_calPost($g45, ['audience' => ['small_group' => [SG3]]]));
        $rf45 = $plain84(pw_main(pw_req($sa, 'GET', $preview . '?event=' . $ge45)['body']));
        pa_ok(
            'W45 the date\'s stored answer run out: its preview says "Waiting for the scheduled refresh — ' . $runout45 . '"; once worked out again, no such notice (fix round 2, LOW 1)',
            str_contains($ro45, 'Waiting for the scheduled refresh ' . $runout45) === true && str_contains($ro45, $paused45) === false
            && $rs45['ok'] === true && str_contains($rf45, $runout45) === false,
            pa_show([mb_substr($ro45, 0, 300), $rs45['ok']])
        );
        $pre45 = $prefix84($fm45 . $dm45);
        pa_ok('W45 every link on the preview pages (' . $pre45['count'] . ') keeps the organisation\'s prefix ' . PW_A . '/', $pre45['count'] > 0 && $pre45['bad'] === [], implode(', ', $pre45['bad']));

        // ---------------------------------------------------------------------
        pa_heading('W46 — deciding one date from the inbox, end to end; a page that is out of date; the refusals (chunk P8-4)');
        $c46  = pa_newFeed(ORG_A, 'members', ['name' => 'W46 calendar']);
        // A day no other check uses: a month page shows only the first few
        // events of a crowded day (W36's lesson), so the date could be cut
        // off the page while visible.
        $e46  = pa_event($c46, 'w46@p8', 'W46 harvest supper Kq9', ['day' => 101]);
        $cal46 = PW_A . '/calendar?view=month&date=' . substr((string) pa_one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$e46])['startDateTime'], 0, 7);
        $x46  = pa_event($c46, 'w46-x@p8', 'W46 stale evening');
        $n46  = pa_event($c46, 'w46-n@p8', 'W46 note evening'); // fix round 1: the note and line-break checks
        $r46  = FeedAdmin::saveRule($mysqli, $c46, 0, ORG_A, SA, ['name' => 'W46 rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W46']]]);
        $p46  = pa_pending($e46);
        $px46 = pa_pending($x46);
        $pn46 = pa_pending($n46);
        if ($r46['ok'] !== true || $p46 === null || $px46 === null || $pn46 === null) {
            pw_refuse('W46 could not build its waiting dates: ' . pa_show($r46));
        }
        $seenBefore46 = str_contains(pw_req($out, 'GET', $cal46)['body'], 'W46 harvest supper Kq9');
        $a46    = pw_req($sa, 'POST', $act, $post84($p46, $c46, 'approved', pw_freshToken($sa, $inbox)));
        $says46 = $flash84(pw_follow($sa, $a46)['body']);
        $seenAfter46 = str_contains(pw_req($out, 'GET', $cal46)['body'], 'W46 harvest supper Kq9');
        $log46  = array_column(pa_q("SELECT activityDescription FROM tblActivityLogs WHERE userID = ? AND activityType = 'ImportApprovalDecided'", 'i', [SA]), 'activityDescription');
        pa_ok(
            'W46 SA approves a waiting date on the inbox: 302 to ' . $inbox . '#imports, which says exactly "Approved 1."; the date is approved; a signed-out visitor did not see it on the calendar page before and does now',
            $a46['status'] === 302 && $a46['location'] === $inbox . '#imports' && $says46 === ['success', 'Approved 1.']
            && pa_status((int) $p46['approvalID']) === 'approved' && $seenBefore46 === false && $seenAfter46 === true,
            pa_show([$a46['status'], $a46['location'], $says46, $seenBefore46, $seenAfter46])
        );
        pa_ok(
            'W46 the activity log holds "Approval #' . (int) $p46['approvalID'] . ' approved" — the number only, never the title',
            in_array('Approval #' . (int) $p46['approvalID'] . ' approved', $log46, true) === true && str_contains(implode(' ', $log46), 'harvest') === false,
            pa_show($log46)
        );
        $stale46 = $post84($px46, $c46, 'approved', pw_freshToken($sa, $inbox));
        $stale46['contentHash'] = str_repeat('0', 64);
        $s46 = $flash84(pw_follow($sa, pw_req($sa, 'POST', $act, $stale46))['body']);
        pa_ok(
            'W46 a page that is out of date (a fresh token, but fingerprints that no longer match): "Approved 0. 1 had changed since you opened the page; please review it again.", and the date still waiting',
            $s46 === ['warning', 'Approved 0. 1 had changed since you opened the page; please review it again.'] && pa_status((int) $px46['approvalID']) === 'pending',
            pa_show($s46)
        );
        $refusals46 = [];
        $odd46      = $post84($px46, $c46, 'maybe', pw_freshToken($sa, $inbox));
        $refusals46['an unknown decision'] = $flash84(pw_follow($sa, pw_req($sa, 'POST', $act, $odd46))['body']);
        $refusals46['nothing ticked'] = $flash84(pw_follow($sa, pw_req($sa, 'POST', $act, [
            'csrf_token' => pw_freshToken($sa, $inbox), 'decision' => 'approved', 'back' => 'approvals', 'feed' => 0, 'note' => '',
        ]))['body']);
        $refusals46['201 ticked'] = $flash84(pw_follow($sa, pw_req($sa, 'POST', $act, [
            'csrf_token' => pw_freshToken($sa, $inbox), 'decision' => 'approved', 'back' => 'approvals', 'feed' => 0, 'note' => '',
            'items' => array_fill(0, 201, $tick84($px46)),
        ]))['body']);
        pa_ok(
            'W46 refusals, each with nothing done: an unknown decision ("Unknown action. Nothing was changed."), the bulk form with nothing ticked ("No date was ticked. Nothing was changed."), and 201 ticked ("You can decide at most 200 dates at once. Nothing was changed.")',
            $refusals46 === [
                'an unknown decision' => ['danger', 'Unknown action. Nothing was changed.'],
                'nothing ticked'      => ['warning', 'No date was ticked. Nothing was changed.'],
                '201 ticked'          => ['danger', 'You can decide at most 200 dates at once. Nothing was changed.'],
            ] && pa_status((int) $px46['approvalID']) === 'pending',
            pa_show($refusals46)
        );
        $mixed46 = $flash84(pw_follow($sa, pw_req($sa, 'POST', $act, [
            'csrf_token' => pw_freshToken($sa, $inbox), 'decision' => 'declined', 'back' => 'approvals', 'feed' => 0, 'note' => '',
            'items' => ['not-a-date', $tick84($px46)],
        ]))['body']);
        pa_ok(
            'W46 a ticked list with one entry that cannot be read and one good date: the good one is declined, and the message says so — "Declined 1. 1 could not be read; please try again."',
            $mixed46 === ['warning', 'Declined 1. 1 could not be read; please try again.'] && pa_status((int) $px46['approvalID']) === 'declined',
            pa_show($mixed46)
        );
        // Fix round 1 (the check's N05): a note one character too long is
        // REFUSED through the page, never cut short (plan L3) — and the date
        // is left waiting.
        $note46 = $flash84(pw_follow($sa, pw_req($sa, 'POST', $act, ['note' => str_repeat('é', 501)] + $post84($pn46, $c46, 'approved', pw_freshToken($sa, $inbox))))['body']);
        pa_ok(
            'W46 a 501-character note through the page: refused with "Note can be at most 500 characters.", the date still waiting (the check\'s N05)',
            $note46 === ['danger', 'Note can be at most 500 characters.'] && pa_status((int) $pn46['approvalID']) === 'pending',
            pa_show($note46)
        );
        // Fix round 1 (the check's N09): `back=feed` returns to the calendar's
        // own page only for a calendar of THIS organisation; B's number, and
        // a number that does not exist, go to the list, the same for both.
        $backs46 = [];
        foreach (['own' => $c46, 'B\'s' => $cB44, 'none' => 919999] as $label => $feedNo) {
            $backs46[$label] = pw_req($sa, 'POST', $act, [
                'csrf_token' => pw_freshToken($sa, $inbox), 'decision' => 'maybe', 'back' => 'feed', 'feed' => $feedNo,
            ])['location'];
        }
        pa_ok(
            'W46 back=feed: this organisation\'s calendar → its own page (#waiting); B\'s calendar and a number that does not exist → the calendar list, the same for both (the check\'s N09)',
            $backs46 === ['own' => $view . '?id=' . $c46 . '#waiting', 'B\'s' => $list, 'none' => $list],
            pa_show($backs46)
        );
        // Fix round 1 (the check's LOW 5): a fingerprint or a number with a
        // line break on the end "could not be read" — single and ticked —
        // and never reaches the database (the date stays waiting).
        $nl46 = [];
        $one46 = $post84($pn46, $c46, 'approved', pw_freshToken($sa, $inbox));
        $one46['contentHash'] .= "\n";
        $nl46['a fingerprint, single'] = $flash84(pw_follow($sa, pw_req($sa, 'POST', $act, $one46))['body']);
        $nl46['a fingerprint, ticked'] = $flash84(pw_follow($sa, pw_req($sa, 'POST', $act, [
            'csrf_token' => pw_freshToken($sa, $inbox), 'decision' => 'approved', 'back' => 'approvals', 'feed' => 0, 'note' => '',
            'items' => [$tick84($pn46) . "\n"],
        ]))['body']);
        $num46 = $post84($pn46, $c46, 'approved', pw_freshToken($sa, $inbox));
        $num46['approvalID'] = (string) $pn46['approvalID'] . "\n";
        $nl46['the number'] = $flash84(pw_follow($sa, pw_req($sa, 'POST', $act, $num46))['body']);
        $unread46 = ['warning', 'Nothing was decided. 1 could not be read; please try again.'];
        pa_ok(
            'W46 a fingerprint (single or ticked) or an approval number with a line break on the end: "Nothing was decided. 1 could not be read; please try again.", the date still waiting (the check\'s LOW 5)',
            $nl46 === ['a fingerprint, single' => $unread46, 'a fingerprint, ticked' => $unread46, 'the number' => $unread46]
            && pa_status((int) $pn46['approvalID']) === 'pending',
            pa_show($nl46)
        );

        // ---------------------------------------------------------------------
        pa_heading('W47 — the bulk form: every ticked date in ONE decision (chunk P8-4; challenge M2, as item 10 of the plan reworded it)');
        $c47  = pa_newFeed(ORG_A, 'members', ['name' => 'W47 calendar']);
        $a47  = pa_series($c47, 'w47-a@p8', 'W47 choir practice', ['count' => 3]);
        $b47  = pa_series($c47, 'w47-b@p8', 'W47 youth club', ['count' => 3, 'day' => 9]);
        $r47  = FeedAdmin::saveRule($mysqli, $c47, 0, ORG_A, SA, ['name' => 'W47 rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W47']]]);
        $pa47 = array_map('pa_pending', $a47);
        $pb47 = array_map('pa_pending', $b47);
        if ($r47['ok'] !== true || in_array(null, $pa47, true) === true || in_array(null, $pb47, true) === true) {
            pw_refuse('W47 could not build its two waiting series: ' . pa_show($r47));
        }
        $bulk47 = static fn (array $rows): array => [
            'csrf_token' => pw_freshToken($sa, $inbox), 'decision' => 'approved', 'back' => 'approvals', 'feed' => 0, 'note' => '',
            'items' => array_map($tick84, $rows),
        ];
        $all47     = pw_req($sa, 'POST', $act, $bulk47($pa47));
        $allSays47 = $flash84(pw_follow($sa, $all47)['body']);
        $allNow47  = array_map(static fn (array $r): string => pa_status((int) $r['approvalID']), $pa47);
        pa_ok(
            'W47 all three dates of a series ticked: 302 to the inbox, exactly "Approved 3." and the one always-true sentence — no "changed" (no date settled by another is reported as changed) and no "followed" — and all three approved',
            $all47['status'] === 302 && $all47['location'] === $inbox . '#imports' && $allSays47 === ['success', 'Approved 3.' . MSG_OTHER_ONE]
            && preg_match('/changed|followed/i', $allSays47[1]) !== 1 && $allNow47 === ['approved', 'approved', 'approved'],
            pa_show([$all47['status'], $allSays47, $allNow47])
        );
        $one47     = pw_req($sa, 'POST', $act, $bulk47([$pb47[0]]));
        $oneSays47 = $flash84(pw_follow($sa, $one47)['body']);
        $oneNow47  = array_map(static fn (array $r): string => pa_status((int) $r['approvalID']), $pb47);
        pa_ok(
            'W47 one of three ticked: exactly "Approved 1." and the always-true sentence ("Other dates of the same repeating event may follow this decision. …"), never a count of dates that "followed"; the other two, waiting for the very same decision, now stand approved too',
            $one47['status'] === 302 && $oneSays47 === ['success', 'Approved 1.' . MSG_OTHER_ONE] && preg_match('/followed/i', $oneSays47[1]) !== 1
            && $oneNow47 === ['approved', 'approved', 'approved'],
            pa_show([$oneSays47, $oneNow47])
        );

        // ---------------------------------------------------------------------
        pa_heading('W48 — the inbox section: administrators of the organisation only; A\'s dates only; "(paused)"; labelled; no form inside a form (chunk P8-4)');
        $c48 = pa_newFeed(ORG_A, 'members', ['name' => 'W48 calendar']);
        $s48 = pa_series($c48, 'w48@p8', 'W48 study evening', ['count' => 3]);
        $r48 = FeedAdmin::saveRule($mysqli, $c48, 0, ORG_A, SA, ['name' => 'W48 rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W48']]]);
        $p48 = pa_pending($s48[0]);
        if ($r48['ok'] !== true || $p48 === null) {
            pw_refuse('W48 could not build its waiting series: ' . pa_show($r48));
        }
        $who48 = [];
        foreach (['SA' => $sa, 'GA' => $ga, 'M' => $m, 'LA' => $la] as $name => $jar) {
            $r             = pw_req($jar, 'GET', $inbox);
            $who48[$name] = [$r['status'], str_contains($r['body'], 'id="imports"'), str_contains($r['body'], 'Imported events waiting for approval')];
        }
        pa_ok(
            'W48 ' . $inbox . ': SA and GA see the "Imported events waiting for approval" section; M and LA (the old portal-wide flag only) get the page without it',
            $who48 === ['SA' => [200, true, true], 'GA' => [200, true, true], 'M' => [200, false, false], 'LA' => [200, false, false]],
            pa_show($who48)
        );
        $sec48 = $section84(pw_req($sa, 'GET', $inbox)['body'], 'imports');
        pa_ok(
            'W48 the section lists A\'s waiting dates with their calendar\'s name, and — as a fact about now — "2 other dates of this repeating event are waiting for the same decision."; nothing of B\'s, and no prediction anywhere in it',
            str_contains($sec48, 'id="imports-approval-' . (int) $p48['approvalID'] . '"') === true && str_contains($sec48, 'W48 study evening') === true
            && str_contains($sec48, 'From W48 calendar') === true && str_contains($sec48, '2 other dates of this repeating event are waiting for the same decision.') === true
            && str_contains($sec48, B_MARK) === false && preg_match($predicts84, $plain84($sec48)) !== 1,
            mb_substr($plain84($sec48), 0, 500)
        );
        pa_q('UPDATE tblExternalFeeds SET isActive = 0 WHERE feedID = ?', 'i', [$c48]);
        $paused48 = $section84(pw_req($sa, 'GET', $inbox)['body'], 'imports');
        pa_q('UPDATE tblExternalFeeds SET isActive = 1 WHERE feedID = ?', 'i', [$c48]);
        pa_ok('W48 a paused calendar\'s waiting dates still show, marked "From W48 calendar (paused)" (challenge L9)', str_contains($paused48, 'From W48 calendar (paused)') === true);
        $u48   = pw_unlabelled('<main>' . $sec48 . '</main>');
        $pre48 = $prefix84($sec48);
        pa_ok(
            'W48 every field of the section has a label (' . $u48['count'] . ' looked at) and every address in it (' . $pre48['count'] . ') keeps the organisation\'s prefix',
            $sec48 !== '' && $u48['count'] > 0 && $u48['bad'] === [] && $pre48['count'] > 0 && $pre48['bad'] === [],
            pa_show([$u48['bad'], $pre48['bad']])
        );
        $bulkInner48 = preg_match('#<form\b[^>]*\bid="imports-bulk"[^>]*>(.*?)</form>#s', $sec48, $bm) === 1 ? $bm[1] : null;
        $ticks48     = substr_count($sec48, 'name="items[]"');
        pa_ok(
            'W48 no form sits inside another; the bulk form holds no tick box, and every tick box (' . $ticks48 . ') joins it from outside with form="imports-bulk", carrying the date\'s number and both fingerprints',
            $depth84($sec48) === 1 && $bulkInner48 !== null && str_contains($bulkInner48, 'items[]') === false && $ticks48 > 0
            && substr_count($sec48, 'form="imports-bulk"') === $ticks48 && str_contains($sec48, 'value="' . $tick84($p48) . '"') === true,
            pa_show(['depth' => $depth84($sec48), 'ticks' => $ticks48, 'joined' => substr_count($sec48, 'form="imports-bulk"')])
        );
        // Fix round 1 (the check's Found 2 and 6): the section's words are true
        // for a date the outside calendar marks private too (it waits even at
        // the calendar's own level, since it starts from "administrators
        // only"), and a tick box's hidden label reads "Title, date" — no
        // stray space before the comma.
        pa_ok(
            'W48 the section says dates wait when shown more widely than the calendar\'s own setting "— or, for a date the outside calendar marks private, to anyone but administrators"',
            str_contains($plain84($sec48), 'more widely than the calendar\'s own setting — or, for a date the outside calendar marks private, to anyone but administrators.') === true,
            mb_substr($plain84($sec48), 0, 300)
        );
        // Fix round 2 (check round 2's Found, its M12): the same words, pinned
        // on the calendar page's waiting section and on the approvals help.
        $wait48 = $plain84($section84(pw_req($sa, 'GET', $view . '?id=' . $c48)['body'], 'waiting'));
        $help48 = $plain84(pw_main(pw_req($m, 'GET', PW_A . '/help/approvals')['body']));
        pa_ok(
            'W48 the calendar page\'s waiting section and the approvals help both carry the private-date words ("… — or, for a date / an event the outside calendar marks private, to anyone but administrators")',
            str_contains($wait48, 'Dates a rule or a choice would show more widely than this calendar\'s own setting — or, for a date the outside calendar marks private, to anyone but administrators.') === true
            && str_contains($help48, 'to more people than its calendar\'s own setting — or, for an event the outside calendar marks private, to anyone but administrators.') === true,
            pa_show([mb_substr($wait48, 0, 200), mb_strlen($help48)])
        );
        // Fix round 1 (addendum): the calendar page's rules text and the rule
        // page said "more widely than before", which is not what the resolver
        // compares with. Both now say it the same way as the inbox.
        $said48  = 'Showing an event more widely than the calendar\'s own setting — or, for a date the outside calendar marks private, to anyone but administrators — waits for an administrator\'s approval.';
        $rules48 = $plain84($section84(pw_req($sa, 'GET', $view . '?id=' . $c48)['body'], 'rules'));
        $rule48  = $plain84(pw_main(pw_req($sa, 'GET', PW_A . '/admin/calendar/feeds/rule?feed=' . $c48)['body']));
        pa_ok(
            'W48 the calendar page\'s rules text and the rule page both say "Showing an event more widely than the calendar\'s own setting — or, for a date the outside calendar marks private, to anyone but administrators — waits…", and neither says "than before"',
            str_contains($rules48, $said48) === true && str_contains($rule48, $said48) === true
            && str_contains($rules48, 'than before') === false && str_contains($rule48, 'than before') === false,
            pa_show([mb_substr($rules48, 0, 300), mb_substr($rule48, 0, 300)])
        );
        $label48 = preg_match('#<label class="form-check-label" for="imports-approval-' . (int) $p48['approvalID'] . '-tick">(.*?)</label>#s', $sec48, $ml48) === 1 ? $plain84($ml48[1]) : '';
        pa_ok(
            'W48 a tick box\'s label reads "W48 study evening, <date> — tick to include it…", with no space before the comma (the check\'s Found 6)',
            str_starts_with($label48, 'W48 study evening, ') === true && str_contains($label48, ' — tick to include it in the decision above') === true,
            $label48
        );

        // ---------------------------------------------------------------------
        pa_heading('W49 — the Approvals app switched off: dates are decided on the calendar\'s own page (chunk P8-4)');
        $c49 = pa_newFeed(ORG_A, 'members', ['name' => 'W49 calendar']);
        $e49 = pa_event($c49, 'w49-a@p8', 'W49 first evening');
        $f49 = pa_event($c49, 'w49-b@p8', 'W49 second evening');
        $r49 = FeedAdmin::saveRule($mysqli, $c49, 0, ORG_A, SA, ['name' => 'W49 rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W49']]]);
        $p49 = pa_pending($e49);
        $q49 = pa_pending($f49);
        if ($r49['ok'] !== true || $p49 === null || $q49 === null) {
            pw_refuse('W49 could not build its waiting dates: ' . pa_show($r49));
        }
        $waitLink49 = static function (string $html, int $feed): string {
            return preg_match('#<a\s+href="([^"]*)"\s+data-waiting-link="' . $feed . '"#', $html, $mm) === 1 ? html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8') : '';
        };
        pa_q("UPDATE tblSettings SET settingValue = 'false' WHERE siteID = ? AND settingKey = 'approvals.enabled'", 'i', [ORG_A]);
        try {
            $off49  = pw_req($sa, 'GET', $inbox);
            $list49 = pw_req($sa, 'GET', $list)['body'];
            // The switched-off page is recognised by its "… is disabled"
            // heading and the Approvals app's OWN description. Not by the name
            // in the heading. When this check was written that page
            // (Router::renderAppDisabled(), not part 8's) named the WRONG app:
            // the navigation template it includes reuses the same `$appName`
            // variable in its loop, so the heading showed the last app the
            // menu listed ("Forms Builder is disabled" here). Found by this
            // check on 5 October 2026; fixed as #570 on 11 October 2026, and
            // `tools/app-switch-selftest.php` now guards the cause. This check
            // was left asserting what it always did: HTTP 403, the "… is
            // disabled" heading, and the Approvals app's OWN description.
            $seen49 = [
                'status'   => $off49['status'],
                'disabled' => str_contains($off49['body'], ' is disabled</h1>') && str_contains($off49['body'], 'Generic inbox for pending workflow approval steps'),
                'note'     => str_contains($list49, 'id="feeds-approvals-off-note"'),
                'words'    => str_contains($plain84($list49), 'The Approvals app is switched off for this organisation, so imported events waiting for approval are listed on each calendar\'s own page instead.'),
                'link'     => $waitLink49($list49, $c49),
            ];
            pa_ok(
                'W49 with the app switched off, ' . $inbox . ' answers the portal\'s "app is disabled" page (403); the calendar list says why, and its waiting count links to the calendar\'s own page (#waiting)',
                $seen49 === ['status' => 403, 'disabled' => true, 'note' => true, 'words' => true, 'link' => $view . '?id=' . $c49 . '#waiting'],
                pa_show($seen49) . ' the page says: ' . mb_substr($plain84(pw_main($off49['body'])), 0, 300)
            );
            $view49 = pw_req($sa, 'GET', $view . '?id=' . $c49)['body'];
            $wait49 = $section84($view49, 'waiting');
            $u49    = pw_unlabelled('<main>' . $wait49 . '</main>');
            $pre49  = $prefix84($wait49);
            pa_ok(
                'W49 the calendar page\'s "Waiting for approval" section lists both dates, labelled (' . $u49['count'] . ' fields), every address with the prefix, no form inside a form, its bulk form returning to this calendar (back=feed)',
                str_contains($wait49, 'id="waiting-approval-' . (int) $p49['approvalID'] . '"') === true && str_contains($wait49, 'id="waiting-approval-' . (int) $q49['approvalID'] . '"') === true
                && $u49['count'] > 0 && $u49['bad'] === [] && $pre49['count'] > 0 && $pre49['bad'] === [] && $depth84($wait49) === 1
                && preg_match('#<form\b[^>]*\bid="waiting-bulk"[^>]*>.*?name="back" value="feed".*?name="feed" value="' . $c49 . '".*?</form>#s', $wait49) === 1,
                pa_show([$u49['bad'], $pre49['bad'], $depth84($wait49)])
            );
            // As the inbox row would post it (back=approvals, its own calendar).
            $d49    = pw_req($sa, 'POST', $act, $post84($p49, $c49, 'approved', pw_freshToken($sa, $view . '?id=' . $c49)));
            $says49 = $flash84(pw_follow($sa, $d49)['body']);
            pa_ok(
                'W49 a decision posted with back=approvals while the app is off goes back to the calendar\'s own page (' . $view . '?id=' . $c49 . '#waiting), which says "Approved 1."; never to the switched-off inbox',
                $d49['status'] === 302 && $d49['location'] === $view . '?id=' . $c49 . '#waiting' && $says49 === ['success', 'Approved 1.']
                && pa_status((int) $p49['approvalID']) === 'approved',
                pa_show([$d49['status'], $d49['location'], $says49])
            );
            $pv49 = pw_main(pw_req($sa, 'GET', $preview . '?approval=' . (int) $q49['approvalID'])['body']);
            pa_ok('W49 the preview\'s Back goes to the calendar\'s own page (#waiting) while the app is off', str_contains($pv49, 'href="' . pw_esc($view . '?id=' . $c49 . '#waiting') . '"') === true);
        } finally {
            pa_q("UPDATE tblSettings SET settingValue = 'true' WHERE siteID = ? AND settingKey = 'approvals.enabled'", 'i', [ORG_A]);
        }
        $listOn49 = pw_req($sa, 'GET', $list)['body'];
        pa_ok(
            'W49 switched back on: the note is gone and the waiting count links to ' . $inbox . '#imports again',
            str_contains($listOn49, 'id="feeds-approvals-off-note"') === false && $waitLink49($listOn49, $c49) === $inbox . '#imports',
            $waitLink49($listOn49, $c49)
        );
        // Fix round 1 (the check's N08): with the app ON, a date decided with
        // its own button on the CALENDAR's page goes back to that page — the
        // row's form says back=feed. Posted exactly as the page draws it.
        $form49 = $rowForm84(pw_req($sa, 'GET', $view . '?id=' . $c49)['body'], (int) $q49['approvalID']);
        $d49b   = ($form49 === []) ? ['status' => 0, 'location' => ''] : pw_req($sa, 'POST', $act, $form49 + ['decision' => 'approved', 'note' => '']);
        pa_ok(
            'W49 with the app ON, a date approved with its own button on the calendar\'s page (the form posted as the page draws it: back=' . ($form49['back'] ?? '?') . ') goes back to that page, #waiting — not to the inbox (the check\'s N08)',
            ($form49['back'] ?? '') === 'feed' && $d49b['status'] === 302 && $d49b['location'] === $view . '?id=' . $c49 . '#waiting'
            && pa_status((int) $q49['approvalID']) === 'approved',
            pa_show([$form49, $d49b['status'], $d49b['location']])
        );

        // ---------------------------------------------------------------------
        pa_heading('W50 — the Outside Calendars guide and the help pages (chunk P8-4; C10 item 6, challenge L11)');
        // The scheduled refresh's key gets a value made up for this run, so
        // the check can show the guide never prints it. Recorded first, so the
        // clean-up puts the real one back (W0 checks it).
        if (array_key_exists('feeds.cron_token', $pw['settings']) === false) {
            $pw['settings']['feeds.cron_token'] = pa_one("SELECT settingID, settingValue FROM tblSettings WHERE settingKey = 'feeds.cron_token' AND siteID IS NULL");
        }
        $key50 = 'k' . bin2hex(random_bytes(12));
        pa_q(
            "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'feeds.cron_token', ?, '', 1) "
            . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)',
            's', [$key50]
        );
        $b7 = 'An API key now receives an event from an outside calendar exactly when a signed-out visitor to your portal could see it, and in the same detail: '
            . 'every event from a calendar set to Public, whether or not "Also show on the public website" is ticked. That website box now controls only the '
            . 'countdown widget and the sitemap. To stop a calendar\'s events being sent through API keys, tick "Don\'t show via API" on that calendar (you can '
            . 'also tick it on a single date, a repeating event or a rule — one tick anywhere is enough).';
        $item9 = 'When a choice or rule starts or ends, the events it covers are shown only to administrators until the scheduled refresh next runs. '
            . 'If the scheduled refresh is not set up, they stay that way.';
        $g50  = pw_req($out, 'GET', $help84);
        $gm50 = pw_main($g50['body']);
        $gt50 = $plain84($gm50);
        pa_ok(
            'W50 ' . $help84 . ' answers 200 to a signed-out visitor, with part 7\'s API-key paragraph (B7) and the scheduled-refresh sentence (plan 7.3 item 9), both word for word',
            $g50['status'] === 200 && str_contains($gt50, $b7) === true && str_contains($gt50, $item9) === true,
            'HTTP ' . $g50['status'] . ' B7 ' . (str_contains($gt50, $b7) ? 'found' : 'MISSING') . ', item 9 ' . (str_contains($gt50, $item9) ? 'found' : 'MISSING')
        );
        $ids50   = ['adding', 'levels', 'detail', 'choices', 'rules', 'approval', 'repeating', 'private', 'dates', 'categories', 'pausing', 'history',
            'scheduled', 'upgrades', 'not-imported', 'approvals-off', 'api-keys'];
        $where50 = [];
        foreach ($ids50 as $id) {
            $at            = strpos($gm50, '<section id="' . $id . '"');
            $where50[$id] = $at === false ? -1 : $at;
        }
        $ordered50 = array_values($where50);
        $sorted50  = $ordered50;
        sort($sorted50);
        pa_ok(
            'W50 the guide\'s seventeen sections are all there, in the plan\'s order (7.3 items 1-17), under exactly one top-level heading',
            in_array(-1, $ordered50, true) === false && $ordered50 === $sorted50 && substr_count($gm50, '<h1') === 1,
            pa_show($where50)
        );
        $pre50 = $prefix84($gm50);
        pa_ok(
            'W50 the scheduled-refresh address is a made-up domain labelled as an example; the real key\'s value is nowhere on the page; every link (' . $pre50['count'] . ') keeps the prefix',
            str_contains($gm50, 'https://portal.example.org/cron/import-feeds?key=YOUR-KEY') === true && str_contains($gt50, 'That is an example') === true
            && str_contains($g50['body'], $key50) === false
            && $pre50['count'] > 0 && $pre50['bad'] === [],
            pa_show($pre50['bad'])
        );
        // #514 part 11 (13 October 2026) turned the next check round. Until then
        // this place REFUSED the words "notification", "07:00" and "quiet hours"
        // anywhere on the guide ("no notifications are described (part 9 builds
        // them)"), because the rule was never to describe what is not built.
        // Part 9 IS built (commit 013a014), so the guide must describe it, and
        // this check now REQUIRES the "told" section to say what
        // Portal\Core\ImportNotifier does: web/_core/ImportNotifier.php (its
        // header; FIRST_HOUR and the "at or after" test in run(); MAX_EVENTS_LISTED;
        // dashboardCard(); the push carries a number only) and
        // auth/account/notifications.php (the two switches, and its own quiet
        // hours sentence). Taking any one of these sentences out of the guide
        // fails it.
        $toldHtml50 = $section84($gm50, 'told');
        $told50     = $plain84($toldHtml50);
        $toldSaid50 = [
            'three ways, members get none' => str_contains($told50, 'Administrators of your organisation are told in three ways that imported events are waiting. A member gets none of them.'),
            'the dashboard card'           => str_contains($told50, 'A card on the dashboard, Imported events waiting for approval, with the number.'),
            'one e-mail a day with titles' => str_contains($told50, 'One e-mail a day, on a day when something is waiting. It lists each waiting event\'s title and time (up to 20; the rest are counted)'),
            'a push notification'          => str_contains($told50, 'A push notification to your phone or computer'),
            'the push gives a number only' => str_contains($told50, 'it gives a number only, never a title.'),
            'at or after 07:00, own clock' => str_contains($told50, 'The e-mail goes on the first run of the scheduled refresh at or after 07:00 on your organisation\'s own clock'),
            'nothing held for quiet hours' => str_contains($told50, 'Nothing is held for quiet hours. The e-mail and the push notification are sent like every other e-mail and push message of the portal, whether or not the person is in their Sabbath quiet hours.'),
            'how each is switched off'     => str_contains($told50, 'Approval requests switches off the daily e-mail')
                && str_contains($told50, 'Imported events waiting for approval switches off the push notification.')
                && str_contains($told50, 'Notification preferences page'),
            'the preferences link'         => str_contains($toldHtml50, 'href="' . PW_A . '/account/notifications"'),
        ];
        pa_ok(
            'W50 the guide\'s "How administrators are told" section says what part 9 does: three ways and members get none; the dashboard card; one e-mail a day with each event\'s title and time; a push notification with a number only; the e-mail at or after 07:00 on the organisation\'s own clock; nothing held for quiet hours; and where each is switched off',
            in_array(false, $toldSaid50, true) === false,
            pa_show($toldSaid50)
        );
        $hi50 = pw_main(pw_req($m, 'GET', PW_A . '/help')['body']);
        $hc50 = $section84(pw_req($m, 'GET', PW_A . '/help/calendar')['body'], 'imported');
        $ha50 = pw_main(pw_req($m, 'GET', PW_A . '/help/approvals')['body']);
        pa_ok(
            'W50 the Help Centre has an "Outside Calendars" card linking to the guide; the calendar help\'s "Imported calendars" section links to it; the approvals help\'s "Imported Events" section links to the inbox\'s section and to the guide',
            str_contains($hi50, 'href="' . $help84 . '"') === true && str_contains($hi50, 'Outside Calendars') === true
            && str_contains($hc50, 'href="' . $help84 . '"') === true
            && str_contains($ha50, 'id="imported"') === true && str_contains($ha50, 'href="' . $inbox . '#imports"') === true
            && str_contains($ha50, 'href="' . $help84 . '#approval"') === true,
            pa_show([strlen($hi50), strlen($hc50), strlen($ha50)])
        );
        // #514 part 11: the approvals help says how administrators hear, and
        // names the same two switches, and links to the guide's section.
        $haPlain50 = $plain84($ha50);
        pa_ok(
            'W50 the approvals help says administrators are told (dashboard card, one e-mail a day, a push notification), names the two switches "Approval requests" and "Imported events waiting for approval", and links to the guide\'s "told" section',
            str_contains($haPlain50, 'Administrators are told when dates are waiting: a card on the dashboard, one e-mail a day on a day when something is waiting, and a push notification when something newer starts waiting.') === true
            && str_contains($haPlain50, 'Approval requests also switches off that daily e-mail, and Imported events waiting for approval switches off the push notification.') === true
            && str_contains($ha50, 'href="' . $help84 . '#told"') === true,
            mb_substr($haPlain50, 0, 200)
        );
        // Fix round 1 (the check's MEDIUM 1 and Found 2, 3, 4): the guide says
        // only what the pages offer. A calendar has THREE levels ("Only
        // administrators" belongs to choices and rules); the detail setting
        // belongs to choices and rules; a private date waits too; it is the
        // EVENT whose words match nothing that gets the default category; and
        // with the Approvals app off it points at the calendar list, not at
        // the switched-off inbox.
        $lv50 = $section84($gm50, 'levels');
        $ao50 = $section84($gm50, 'approvals-off');
        $said50 = [
            'three levels'      => substr_count($lv50, '<li>') === 3 && str_contains($plain84($lv50), 'they offer one more: Only administrators.'),
            'detail'            => str_contains($plain84($section84($gm50, 'detail')), 'A choice or a rule also says how much of an event people outside the calendar\'s own audience may see.'),
            'private waits'     => str_contains($plain84($section84($gm50, 'approval')), 'or, for an event the outside calendar marks private, to anyone but administrators'),
            'default category'  => str_contains($plain84($section84($gm50, 'categories')), 'an event none of whose words is matched gets the calendar\'s default category, if it has one.'),
            'approvals off'     => str_contains($ao50, 'href="' . $list . '"') && str_contains($ao50, '/approvals') === false,
        ];
        pa_ok(
            'W50 the guide\'s corrected sentences: a calendar\'s three levels (choices and rules add "Only administrators"), the detail setting on choices and rules, private dates waiting, the default category for an EVENT with no matched word, and Approvals-off pointing at the calendar list, never the inbox',
            in_array(false, $said50, true) === false,
            pa_show($said50)
        );
        // The final whole-part check (Found; its N33): the private paragraph's
        // "Rules never show it" — the claim the choice page's sentences rest on
        // — and the new sentence that a date filed under the same event as a
        // one-off can follow its decision, are pinned word for word.
        $pv50 = $plain84($section84($gm50, 'private'));
        $rp50 = $plain84($section84($gm50, 'repeating'));
        pa_ok(
            'W50 the guide says "An event the outside calendar marks private is shown only to administrators. Rules never show it." and that "deciding the one-off can settle it too"',
            str_contains($pv50, 'An event the outside calendar marks private is shown only to administrators. Rules never show it.') === true
            && str_contains($rp50, 'The same can happen to a date that the outside calendar files under the same event as a one-off date (it sometimes lists one changed date of an event that way): deciding the one-off can settle it too.') === true,
            pa_show([mb_substr($pv50, 0, 200), mb_substr($rp50, 0, 400)])
        );

        // ---------------------------------------------------------------------
        pa_heading('W51 — the API sentences: the API description and the API keys page (chunk P8-4; challenge L11)');
        $apiSentence = 'Events copied in from an outside calendar reach an API key exactly when a signed-out visitor could see them, in the same detail, '
            . 'unless the calendar — or a choice or rule covering the event — is set \'Don\'t show via API\'.';
        $o51 = pw_req($out, 'GET', PW_A . '/openapi.json');
        $j51 = json_decode($o51['body'], true);
        pa_ok(
            'W51 ' . PW_A . '/openapi.json answers 200 with JSON whose description carries the sentence about outside calendars, word for word',
            $o51['status'] === 200 && is_array($j51) === true && str_contains((string) ($j51['info']['description'] ?? ''), $apiSentence) === true,
            'HTTP ' . $o51['status']
        );
        $k51 = pw_main(pw_req($sa, 'GET', PW_A . '/admin/integrations/api-keys')['body']);
        pa_ok(
            'W51 the API keys page says "An API key receives your events from outside calendars exactly as a signed-out visitor sees them. …" and links to Outside calendars with the prefix',
            str_contains($plain84($k51), 'An API key receives your events from outside calendars exactly as a signed-out visitor sees them. To stop a calendar\'s events being sent through API keys, tick \'Don\'t show via API\' on that calendar in Outside calendars.') === true
            && str_contains($k51, 'href="' . $list . '"') === true,
            mb_substr($plain84($k51), 0, 300)
        );

        // ---------------------------------------------------------------------
        pa_heading('W52 — the upgrade notice about API keys (chunk P8-4; plan 7.1, challenge H1 — #561 is committed, so this runs)');
        // WHAT IS CHANGED, AND PUT BACK:
        //   - four portal-wide settings: portal.upgrade.backup.enabled (set
        //     to '0', so no snapshot is written), portal.maintenance.active
        //     and .message (the upgrade switches maintenance on, then off),
        //     and portal.installed_version (the upgrade writes it). Recorded
        //     in $pw['settings'] first — so the clean-up puts them back even
        //     after a crash, and W0 checks it — AND put back straight after
        //     each run here, so no later request ever meets maintenance;
        //   - tblMigrations' row for 206 (and, once, for 207 — fix round 1):
        //     deleted before a run so the page offers it again (each replays
        //     as a no-op: 206's one-time update runs only in the run that adds
        //     its column, and 207 holds only address rows, written "insert,
        //     or set them to the same values"), written afresh by the run, then replaced by the
        //     ORIGINAL row — the same number, time and person
        //     (pw_restoreMigrations(), also in the clean-up);
        //   - API keys and "Don't show via API" boxes of the made-up world's
        //     calendars (removed with the world).
        // THE SAFETY CATCH: the upgrade runs EVERY pending migration. So each
        // run first reads the page and goes ahead only when it offers exactly
        // one — 206. If anything else were pending, this check fails without
        // pressing the button, rather than run migrations on this database.
        $file206  = '206_external_calendar_choices_rules_approvals.sql';
        $upgrade  = PW_A . '/admin/upgrade';
        $keys52   = ['portal.upgrade.backup.enabled', 'portal.maintenance.active', 'portal.maintenance.message', 'portal.installed_version'];
        foreach ($keys52 as $key) {
            if (array_key_exists($key, $pw['settings']) === false) {
                $pw['settings'][$key] = pa_one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
            }
        }
        // 207 too since fix round 1 (the check's N07): an upgrade that applies
        // something OTHER than 206 must show no card.
        $file207  = '207_calendar_feed_admin_pages.sql';
        foreach ([$file206, $file207] as $file) {
            $pw['migrationRows'][$file] = pa_one('SELECT migrationID, filename, executedAt, executedByID FROM tblMigrations WHERE filename = ?', 's', [$file]);
            if ($pw['migrationRows'][$file] === null) {
                unset($pw['migrationRows'][$file]);
                pw_refuse('W52: this database has no tblMigrations row for ' . $file . ', so the upgrade check cannot put things back as they were');
            }
        }
        pa_q(
            "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'portal.upgrade.backup.enabled', '0', '1', 0) "
            . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)'
        );
        /** Put back the maintenance flag, its message, the installed version and the migration rows (backup.enabled stays '0' until W52 ends). */
        $putBack52 = static function () use ($keys52): void {
            $pw =& $GLOBALS['pw'];
            foreach ($keys52 as $key) {
                if ($key === 'portal.upgrade.backup.enabled') {
                    continue;
                }
                $before = $pw['settings'][$key];
                if ($before === null) {
                    pa_q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
                } else {
                    pa_q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
                }
            }
            pw_restoreMigrations();
        };
        /** One upgrade run by GA of ONE migration ($file, 206 by default): [offered exactly that one?, the answer, the card's text or '' when there is none]. */
        $run52 = static function (?string $file = null) use ($ga, $upgrade, $file206, $putBack52, $plain84): array {
            $file = $file ?? $file206;
            pa_q('DELETE FROM tblMigrations WHERE filename = ?', 's', [$file]);
            try {
                $get     = pw_req($ga, 'GET', $upgrade);
                $offered = preg_match('/Run 1 Pending Migration\b/', $get['body']) === 1 && str_contains($get['body'], '<li><code>' . $file . '</code></li>') === true;
                $post    = ['status' => 0, 'body' => '', 'location' => '', 'head' => ''];
                if ($offered === true) {
                    $post = pw_req($ga, 'POST', $upgrade, ['csrf_token' => pw_token($get['body'])]);
                }
            } finally {
                $putBack52();
            }
            $card = preg_match('#<div class="card mb-4 border-info" id="upgrade-api-notice">(.*?)</a>#s', $post['body'], $mm) === 1 ? $plain84($mm[1]) : '';
            $link = preg_match('#id="upgrade-api-notice".*?<a href="([^"]*)"#s', $post['body'], $ml) === 1 ? html_entity_decode($ml[1], ENT_QUOTES, 'UTF-8') : '';

            return [
                'offered' => $offered,
                'status'  => $post['status'],
                'table'   => str_contains($post['body'], 'Migration Results') === true
                    && preg_match('#<code>' . preg_quote($file, '#') . '</code></td>\s*<td>\s*<span class="badge bg-success">OK</span>#', $post['body']) === 1,
                'card'    => $card,
                'link'    => $link,
            ];
        };
        $tail52   = ' If you have given a key to a website and do not want that, tick \'Don\'t show via API\' on each calendar.';
        $cards52  = [];
        try {
            // Only the calendars this check makes may count: every other
            // Everyone calendar of A and B gets "Don't show via API" first.
            $c52a = pa_newFeed(ORG_A, 'public', ['name' => 'W52 public calendar one']);
            $c52b = pa_newFeed(ORG_A, 'public', ['name' => 'W52 public calendar two']);
            $c52B = pa_newFeed(ORG_B, 'public', ['name' => 'W52 BONLY public calendar']);
            pa_q("UPDATE tblExternalFeeds SET apiOptOut = 1 WHERE siteID IN (?, ?) AND audienceLevel = 'public' AND feedID NOT IN (?, ?, ?)", 'iiiii', [ORG_A, ORG_B, $c52a, $c52b, $c52B]);
            pa_q('DELETE FROM tblApiKeys WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
            pa_q("INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, isActive) VALUES (?, 'W52 key', SHA2(?, 256), 'w52a', 'events:read', 1)", 'is', [ORG_A, 'w52a-' . $pw['token']]);

            $count52 = FeedAdmin::apiUpgradeNotice($mysqli);
            $cards52['two in A'] = $run52();
            pa_ok(
                'W52 two qualifying calendars in A: only 206 was offered; GA\'s run answers 200 with the results table (206: OK) and the card "2 outside calendars now send their public events to API keys." + what to do, with a button to Outside calendars; no line about several organisations',
                $count52 === ['calendars' => 2, 'organisations' => 1] && $cards52['two in A']['offered'] === true && $cards52['two in A']['status'] === 200
                && $cards52['two in A']['table'] === true
                && $cards52['two in A']['card'] === 'Outside calendars and API keys 2 outside calendars now send their public events to API keys.' . $tail52 . ' Open Outside calendars'
                && $cards52['two in A']['link'] === $list,
                pa_show([$count52, $cards52['two in A']])
            );
            pa_q("INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, isActive) VALUES (?, 'W52 BONLY key', SHA2(?, 256), 'w52b', 'events:read', 1)", 'is', [ORG_B, 'w52b-' . $pw['token']]);
            $count52b = FeedAdmin::apiUpgradeNotice($mysqli);
            $cards52['two organisations'] = $run52();
            pa_ok(
                'W52 with a key in B too (three calendars, two organisations): "3 outside calendars now send …" and "These calendars belong to 2 organisations; open Outside calendars in each one."',
                $count52b === ['calendars' => 3, 'organisations' => 2] && $cards52['two organisations']['offered'] === true && $cards52['two organisations']['status'] === 200
                && $cards52['two organisations']['card'] === 'Outside calendars and API keys 3 outside calendars now send their public events to API keys.' . $tail52
                    . ' These calendars belong to 2 organisations; open Outside calendars in each one. Open Outside calendars',
                pa_show([$count52b, $cards52['two organisations']])
            );
            pa_q('DELETE FROM tblApiKeys WHERE siteID = ?', 'i', [ORG_B]);
            pa_q('UPDATE tblExternalFeeds SET apiOptOut = 1 WHERE feedID = ?', 'i', [$c52b]);
            $count52c = FeedAdmin::apiUpgradeNotice($mysqli);
            $cards52['one'] = $run52();
            pa_ok(
                'W52 one qualifying calendar: the singular sentence, written out — "1 outside calendar now sends its public events to API keys." (challenge L14)',
                $count52c === ['calendars' => 1, 'organisations' => 1] && $cards52['one']['offered'] === true && $cards52['one']['status'] === 200
                && $cards52['one']['card'] === 'Outside calendars and API keys 1 outside calendar now sends its public events to API keys.' . $tail52 . ' Open Outside calendars',
                pa_show([$count52c, $cards52['one']])
            );
            // The check's N07: an upgrade that applies another migration (207
            // here), with that same calendar still qualifying, shows NO card —
            // the card belongs only to the run that applied 206.
            $count52e = FeedAdmin::apiUpgradeNotice($mysqli);
            $cards52['207 only'] = $run52($file207);
            pa_ok(
                'W52 an upgrade that applies only ' . $file207 . ', with one calendar still qualifying: the results table (207: OK) and NO card (the check\'s N07)',
                $count52e === ['calendars' => 1, 'organisations' => 1] && $cards52['207 only']['offered'] === true && $cards52['207 only']['status'] === 200
                && $cards52['207 only']['table'] === true && $cards52['207 only']['card'] === '' && $cards52['207 only']['link'] === '',
                pa_show([$count52e, $cards52['207 only']])
            );
            pa_q('UPDATE tblExternalFeeds SET apiOptOut = 1 WHERE feedID = ?', 'i', [$c52a]);
            $count52d = FeedAdmin::apiUpgradeNotice($mysqli);
            $cards52['none'] = $run52();
            pa_ok(
                'W52 every Everyone calendar\'s "Don\'t show via API" ticked: the results table is drawn and there is no card',
                $count52d === ['calendars' => 0, 'organisations' => 0] && $cards52['none']['offered'] === true && $cards52['none']['status'] === 200
                && $cards52['none']['table'] === true && $cards52['none']['card'] === '' && $cards52['none']['link'] === '',
                pa_show([$count52d, $cards52['none']])
            );
        } finally {
            $before52 = $pw['settings']['portal.upgrade.backup.enabled'];
            if ($before52 === null) {
                pa_q("DELETE FROM tblSettings WHERE settingKey = 'portal.upgrade.backup.enabled' AND siteID IS NULL");
            } else {
                pa_q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$before52['settingValue'], (int) $before52['settingID']]);
            }
            $putBack52();
        }
        $back52 = [];
        foreach ($keys52 as $key) {
            $back52[$key] = (pa_one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]) === $pw['settings'][$key]);
        }
        $rows52 = [];
        foreach ([$file206, $file207] as $file) {
            $rows52[$file] = pa_one('SELECT migrationID, filename, executedAt, executedByID FROM tblMigrations WHERE filename = ?', 's', [$file]);
        }
        pa_ok(
            'W52 afterwards everything is as it was: the four settings (maintenance switched off as before), and the rows of 206 and 207 with their original numbers, times and people',
            in_array(false, $back52, true) === false && $rows52 === $pw['migrationRows'],
            pa_show([$back52, $rows52, $pw['migrationRows']])
        );

        // ---------------------------------------------------------------------
        pa_heading('W53 — the calendar page\'s page numbers: a huge or junk one never breaks it, and each list keeps the other\'s page (fix round 1 of P8-4, check LOW 4)');
        // 101 events, all waiting for one rule, so BOTH lists have two pages.
        $c53 = pa_newFeed(ORG_A, 'members', ['name' => 'W53 calendar']);
        for ($i = 0; $i < 101; $i++) {
            pa_event($c53, 'w53-' . $i . '@p8', 'W53 evening ' . $i, ['day' => 300 + $i]);
        }
        $r53 = FeedAdmin::saveRule($mysqli, $c53, 0, ORG_A, SA, ['name' => 'W53 rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W53']]]);
        $odd53 = [];
        foreach (['&wpage=9223372036854775807', '&page=9223372036854775807', '&wpage=92233720368547759', '&wpage=abc', '&page=-3', '&wpage=1.5', '&page=100001'] as $q) {
            $r53x        = pw_req($sa, 'GET', $view . '?id=' . $c53 . $q);
            $odd53[$q] = [$r53x['status'], str_contains($r53x['body'], 'Waiting for approval <span class="text-muted small">(101)</span>')];
        }
        pa_ok(
            'W53 seven odd page numbers (the largest whole number, one just under it, letters, negative, a fraction, one past the limit): every one answers 200 with the page drawn',
            $r53['ok'] === true && array_values(array_unique(array_map('json_encode', $odd53))) === [json_encode([200, true])],
            pa_show($odd53)
        );
        $hrefs53 = static function (string $html): array {
            preg_match_all('#<a href="([^"]*)">(Earlier events|Later events|Earlier waiting dates|Later waiting dates)</a>#', $html, $mm, PREG_SET_ORDER);
            $out = [];
            foreach ($mm as $m) {
                $out[$m[2]] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }

            return $out;
        };
        $first53  = $hrefs53(pw_req($sa, 'GET', $view . '?id=' . $c53)['body']);
        $second53 = $hrefs53(pw_req($sa, 'GET', $view . '?id=' . $c53 . '&page=2&wpage=2')['body']);
        pa_ok(
            'W53 each list\'s page links keep the other list\'s page: on page 2 of both, "Earlier events" keeps wpage=2 and "Earlier waiting dates" keeps page=2; on the first page, the "Later" links carry only their own number',
            $first53 === ['Later events' => $view . '?id=' . $c53 . '&page=2#events', 'Later waiting dates' => $view . '?id=' . $c53 . '&wpage=2#waiting']
            && $second53 === ['Earlier events' => $view . '?id=' . $c53 . '&wpage=2#events', 'Earlier waiting dates' => $view . '?id=' . $c53 . '&page=2#waiting'],
            pa_show([$first53, $second53])
        );
        // Fix round 2 (check round 2's LOW 2, its probe X2b): one list on page
        // 1 and the other on page 2 — the only way a "Later" link has another
        // page number to carry.
        $wonly53 = $hrefs53(pw_req($sa, 'GET', $view . '?id=' . $c53 . '&wpage=2')['body']);
        $ponly53 = $hrefs53(pw_req($sa, 'GET', $view . '?id=' . $c53 . '&page=2')['body']);
        pa_ok(
            'W53 the "Later" links keep the other list\'s page: on waiting page 2, "Later events" is &page=2&wpage=2#events; on events page 2, "Later waiting dates" is &page=2&wpage=2#waiting',
            $wonly53 === ['Later events' => $view . '?id=' . $c53 . '&page=2&wpage=2#events', 'Earlier waiting dates' => $view . '?id=' . $c53 . '#waiting']
            && $ponly53 === ['Earlier events' => $view . '?id=' . $c53 . '#events', 'Later waiting dates' => $view . '?id=' . $c53 . '&page=2&wpage=2#waiting'],
            pa_show([$wonly53, $ponly53])
        );

        // ---------------------------------------------------------------------
        pa_heading('W54 — every activity line part 8\'s pages write names the administrator who acted (fix round 1 of P8-4, check LOW 6)');
        // Part W has, by now, done each of these through the real pages: add
        // and save a calendar (W19, W6), pause, resume and delete one (W18),
        // Refresh now (W8), save the category matching (W16), save and remove
        // a choice (W30), save and delete a rule (W31), decide a date (W46).
        // Every such line written since part W began must carry the person —
        // SA, or GA where a global administrator acted — never nobody
        // ("System" on the Activity page).
        $types54 = ['ExternalFeedAdded', 'ExternalFeedSaved', 'ExternalFeedPause', 'ExternalFeedResume', 'ExternalFeedRemove', 'ExternalFeedRefresh',
            'ExternalCategoryMapSaved', 'ExternalChoiceSaved', 'ExternalChoiceRemoved', 'ExternalRuleSaved', 'ExternalRuleDeleted', 'ImportApprovalDecided'];
        $seen54 = array_fill_keys($types54, []);
        foreach (pa_q(
            'SELECT activityType, userID FROM tblActivityLogs WHERE logID > ? AND siteID = ? AND activityType IN (' . implode(',', array_fill(0, count($types54), '?')) . ')',
            'ii' . str_repeat('s', count($types54)),
            array_merge([(int) $pw['activityFrom'], ORG_A], $types54)
        ) as $row) {
            $seen54[(string) $row['activityType']][] = $row['userID'] === null ? 'nobody' : (int) $row['userID'];
        }
        $bad54 = [];
        foreach ($seen54 as $type => $users) {
            if ($users === [] || array_diff($users, [SA, GA]) !== []) {
                $bad54[$type] = $users;
            }
        }
        pa_ok(
            'W54 each of the ' . count($types54) . ' kinds of activity line was written at least once since part W began, and every one names SA or GA — none says nobody',
            $bad54 === [],
            pa_show(['wrong or missing' => $bad54, 'counts' => array_map('count', $seen54)])
        );

        // ---------------------------------------------------------------------
        pa_heading('W55 — the choice page tells the truth about a date the outside calendar marks private (the final whole-part check, F1)');
        // Probe 1's shape: a Members calendar, a Members rule matching the
        // date, and a private date. Rules never reach it, so with no choice
        // it is shown only to administrators — the page, its Remove
        // confirmation and the message after Remove must all say so.
        $c55  = pa_newFeed(ORG_A, 'members', ['name' => 'W55 calendar']);
        $a55  = pa_event($c55, 'w55-a@p8', 'W55 private talk', ['private' => 1]);
        $b55  = pa_event($c55, 'w55-b@p8', 'W55 private meeting', ['private' => 1]);
        $r55  = FeedAdmin::saveRule($mysqli, $c55, 0, ORG_A, SA, ['name' => 'W55 rule', 'audienceLevel' => 'members', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W55']]]);
        $k55  = FeedAdmin::saveChoice($mysqli, $b55, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'overridesPrivateMark' => '1', 'seen' => [$b55 . ':' . pa_cf($b55)]]);
        $pa55 = $plain84(pw_main(pw_req($sa, 'GET', $choice . '?event=' . $a55 . '&scope=date')['body']));
        $pb55 = pw_req($sa, 'GET', $choice . '?event=' . $b55 . '&scope=date')['body'];
        $cf55 = preg_match('#data-confirm="([^"]*)"\s+data-confirm-destructive="true">Remove this choice</button>#', $pb55, $mc55) === 1 ? html_entity_decode($mc55[1], ENT_QUOTES, 'UTF-8') : '';
        pa_ok(
            'W55 a private date with no choice: the page says "No choice yet: the outside calendar marks this date private, so it is shown only to administrators." (never "follows the rules"); one with a choice: Remove asks "Remove this choice? The outside calendar marks this date private, so it is then shown only to administrators."',
            $r55['ok'] === true && $k55['ok'] === true
            && str_contains($pa55, 'No choice yet: the outside calendar marks this date private, so it is shown only to administrators.') === true
            && str_contains($pa55, 'follows the rules') === false
            && $cf55 === 'Remove this choice? The outside calendar marks this date private, so it is then shown only to administrators.',
            pa_show([mb_substr($pa55, 0, 400), $cf55])
        );
        $rm55 = pw_req($sa, 'POST', $choice . '?event=' . $b55 . '&scope=date', ['csrf_token' => pw_token($pb55), 'action' => 'remove']);
        $vw55 = $flash84(pw_follow($sa, $rm55)['body']);
        pa_ok(
            'W55 Remove through the page: back to the calendar page with "Choice removed. The outside calendar marks this date private, so it is now shown only to administrators." — and the date is indeed administrators only',
            $rm55['status'] === 302 && $vw55 === ['success', 'Choice removed. The outside calendar marks this date private, so it is now shown only to administrators.']
            && pa_ls($b55) === 'hidden/private',
            pa_show([$rm55['status'], $vw55, pa_ls($b55)])
        );
        // The last narrow check's K08: the choice page says so for a date the
        // outside calendar lists TWICE — a rule or a choice can only narrow it.
        $t55  = pa_event($c55, 'w55-t@p8', 'W55 twice evening', ['duplicate' => 1]);
        $ts55 = FeedAdmin::saveCalendar($mysqli, $c55, ORG_A, SA, pa_calPost($c55));
        $pt55 = pw_req($sa, 'GET', $choice . '?event=' . $t55 . '&scope=date');
        pa_ok(
            'W55 a date the outside calendar lists twice: its choice page says "…lists this date twice, so a rule or a choice can show it more narrowly, but never more widely."',
            $ts55['ok'] === true && $pt55['status'] === 200
            && str_contains($plain84(pw_main($pt55['body'])), "No choice yet: this date follows the rules and the calendar's own setting. The outside calendar lists this date twice, so a rule or a choice can show it more narrowly, but never more widely.") === true,
            'HTTP ' . $pt55['status'] . ' ' . mb_substr($plain84(pw_main($pt55['body'])), 0, 400)
        );

        // ---------------------------------------------------------------------
        pa_heading('W56 — the preview never says details are hidden from people who see them (the final whole-part check, F2)');
        // Probe 3's shape: a Members calendar and a date-choice "Members,
        // Title, date and time only" whose description holds a room code.
        // Members ARE the calendar's own audience, so they see the whole
        // event: the preview must show the description and say why.
        $c56 = pa_newFeed(ORG_A, 'members', ['name' => 'W56 calendar']);
        $e56 = pa_event($c56, 'w56@p8', 'W56 members talk', ['description' => 'W56 room code 1234', 'location' => 'W56 room 5']);
        $s56 = FeedAdmin::saveCalendar($mysqli, $c56, ORG_A, SA, pa_calPost($c56));
        $k56 = FeedAdmin::saveChoice($mysqli, $e56, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'detailLevel' => 'basic', 'seen' => [$e56 . ':' . pa_cf($e56)]]);
        $ev56 = pa_ev($e56);
        $pv56 = $plain84(pw_main(pw_req($sa, 'GET', $preview . '?event=' . $e56)['body']));
        pa_ok(
            'W56 a Members date at "Title, date and time only" on a Members calendar: its preview shows the description and location, says members see them because they are inside the calendar\'s own audience, and never "are left out"',
            $s56['ok'] === true && $k56['ok'] === true && ($ev56['importLevel'] ?? '') === 'members' && ($ev56['importDetail'] ?? '') === 'basic'
            && str_contains($pv56, 'What members of this organisation will see') === true
            && str_contains($pv56, 'W56 room code 1234') === true && str_contains($pv56, 'W56 room 5') === true
            && str_contains($pv56, 'everyone named here is inside it, so they see the description, the location and the link as well.') === true
            && str_contains($pv56, 'are left out') === false,
            pa_show([$ev56['importLevel'] ?? null, $ev56['importDetail'] ?? null, mb_substr($pv56, 0, 500)])
        );

        // ---------------------------------------------------------------------
        pa_heading('W57 — the preview\'s other cases: a private date, a list of a choice\'s own, a date that is gone (the last narrow check before the commit)');
        // W56 pinned one case of the preview's "who really sees the details"
        // (everyone inside the calendar's own audience). The last narrow check
        // planted faults in the other cases and they passed (its K01, K03,
        // K04). Each case below is checked for its exact sentence AND for what
        // must not be on the page.
        $insideWords57  = 'everyone named here is inside it';
        $privateWords57 = 'The outside calendar marks this date private, so at this level of detail the description, the location and the link are left out for everyone but administrators.';
        $bothWords57    = 'At this level of detail the description, the location and the link are left out for people outside the calendar\'s own audience; people inside it see them as well.';
        $goneWords57    = 'At this level of detail the description, the location and the link are left out for people outside the calendar\'s own audience. The date itself can no longer be read (it may have been removed from the outside calendar), so this page cannot say more about who sees them.';
        $text57 = static fn (string $query): string => $plain84(pw_main(pw_req($sa, 'GET', $preview . $query)['body']));
        // (a) A private date a choice shows to Members at "Title, date and time
        //     only", on a Members calendar: members are inside the calendar's
        //     own audience, yet for a PRIVATE date they do not see the details.
        $c57  = pa_newFeed(ORG_A, 'members', ['name' => 'W57 calendar']);
        $pv57 = pa_event($c57, 'w57-pv@p8', 'W57 private shown', ['private' => 1, 'description' => 'W57 private words Xk2', 'location' => 'W57 private room']);
        $pq57 = pa_event($c57, 'w57-pq@p8', 'W57 private asked', ['private' => 1, 'description' => 'W57 asked words Xk3', 'day' => 9]);
        $s57  = FeedAdmin::saveCalendar($mysqli, $c57, ORG_A, SA, pa_calPost($c57));
        $k57  = FeedAdmin::saveChoice($mysqli, $pv57, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'detailLevel' => 'basic', 'overridesPrivateMark' => '1', 'seen' => [$pv57 . ':' . pa_cf($pv57)]]);
        $ta57 = $text57('?event=' . $pv57);
        pa_ok(
            'W57 a PRIVATE date shown to Members at "Title, date and time only": the preview says the details are "left out for everyone but administrators", shows none of them, and never says everyone named is inside (the check\'s K03)',
            $s57['ok'] === true && $k57['ok'] === true && pa_ls($pv57) === 'members/date' && (pa_ev($pv57)['importDetail'] ?? '') === 'basic'
            && str_contains($ta57, 'What members of this organisation will see') === true && str_contains($ta57, $privateWords57) === true
            && str_contains($ta57, 'W57 private words Xk2') === false && str_contains($ta57, 'W57 private room') === false
            && str_contains($ta57, $insideWords57) === false,
            pa_show([pa_ls($pv57), mb_substr($ta57, 0, 500)])
        );
        // (b) The same for a REQUEST (not yet approved) about a private date:
        //     the page must read the date's private mark for a request too.
        $kq57 = FeedAdmin::saveChoice($mysqli, $pq57, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'detailLevel' => 'basic', 'overridesPrivateMark' => '1']);
        $rq57 = pa_pending($pq57);
        $tb57 = ($rq57 === null) ? '' : $text57('?approval=' . (int) $rq57['approvalID']);
        pa_ok(
            'W57 a waiting REQUEST to show a private date to Members at "Title, date and time only": its preview says "left out for everyone but administrators", never that everyone named is inside (the check\'s K04)',
            $kq57['ok'] === true && $rq57 !== null && str_contains($tb57, $privateWords57) === true
            && str_contains($tb57, $insideWords57) === false && str_contains($tb57, 'W57 asked words Xk3') === false,
            mb_substr($tb57, 0, 500)
        );
        // (c) A Selected-groups calendar (SG3) and a date whose choice names its
        //     OWN list (SG3 and one more person): not everyone on that list is
        //     in the calendar's own audience, so the page may not say they are.
        $g57  = pa_newFeed(ORG_A, 'members', ['name' => 'W57 groups calendar']);
        $ge57 = pa_event($g57, 'w57-g@p8', 'W57 group evening', ['description' => 'W57 group words Xk4']);
        $gs57 = FeedAdmin::saveCalendar($mysqli, $g57, ORG_A, SA, pa_calPost($g57, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG3]]]));
        $gk57 = FeedAdmin::saveChoice($mysqli, $ge57, 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'detailLevel' => 'basic', 'audience' => ['small_group' => [SG3], 'person' => [SGM]], 'seen' => [$ge57 . ':' . pa_cf($ge57)]]);
        $gv57 = pa_ev($ge57);
        $tc57 = $text57('?event=' . $ge57);
        pa_ok(
            'W57 a Selected-groups calendar and a date using a list of its choice\'s own, at "Title, date and time only": the both-ways sentence ("…left out for people outside the calendar\'s own audience; people inside it see them as well."), no description, and never "everyone named here is inside it" (the check\'s K01)',
            $gs57['ok'] === true && $gk57['ok'] === true && ($gv57['importLevel'] ?? '') === 'groups' && ($gv57['importAudienceType'] ?? '') === 'choice' && ($gv57['importDetail'] ?? '') === 'basic'
            && str_contains($tc57, 'What the selected groups and people will see') === true && str_contains($tc57, $bothWords57) === true
            && str_contains($tc57, 'W57 group words Xk4') === false && str_contains($tc57, $insideWords57) === false,
            pa_show([$gv57['importLevel'] ?? null, $gv57['importAudienceType'] ?? null, mb_substr($tc57, 0, 500)])
        );
        // (d) A request on an Everyone calendar (the website box asked for): the
        //     audience is inside, and a request keeps no copy of the details.
        //     Once the date is gone from the outside calendar, whether it was
        //     private is unknown — so the page stops claiming anything about
        //     people inside.
        $e57  = pa_newFeed(ORG_A, 'public', ['name' => 'W57 everyone calendar']);
        $ev57 = pa_event($e57, 'w57-e@p8', 'W57 website evening');
        $er57 = FeedAdmin::saveRule($mysqli, $e57, 0, ORG_A, SA, ['name' => 'W57 website', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'websiteOptIn' => '1', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W57 website']]]);
        $ep57 = pa_pending($ev57);
        $td57 = ($ep57 === null) ? '' : $text57('?approval=' . (int) $ep57['approvalID']);
        pa_q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID = ?', 'i', [$ev57]);
        $te57 = ($ep57 === null) ? '' : $text57('?approval=' . (int) $ep57['approvalID']);
        pa_ok(
            'W57 a request on an Everyone calendar: "…everyone named here is inside it… A request at this detail keeps no copy of them, so they are not shown here."; once its date is gone from the outside calendar: "The date itself can no longer be read…", and no claim that everyone is inside',
            $er57['ok'] === true && $ep57 !== null
            && str_contains($td57, $insideWords57) === true && str_contains($td57, 'A request at this detail keeps no copy of them, so they are not shown here.') === true
            && str_contains($te57, $goneWords57) === true && str_contains($te57, $insideWords57) === false,
            pa_show([mb_substr($td57, 0, 400), mb_substr($te57, 0, 400)])
        );

        // ---------------------------------------------------------------------
        pa_heading('W58 — "A request was declined on …" is worded as history, true even when the dates show to everyone again (the last narrow check before the commit)');
        // The check's sequence: a rule shows a two-date series to Everyone at
        // "Title, date and time only" and is approved; edited to "Full details"
        // (a new request) and DECLINED; edited back, so the older approval is
        // in force again and both dates show to everyone. The newest decision
        // about each date is still the decline, so the badge shows — worded as
        // something that happened, never as "Declined on …" (which read as
        // "declined now", beside dates everyone could see).
        $c58 = pa_newFeed(ORG_A, 'members', ['name' => 'W58 calendar']);
        [$q58a, $q58b] = pa_series($c58, 'w58@p8', 'W58 mirror talk', ['count' => 2, 'day' => 44]);
        $rule58 = ['name' => 'W58 rule', 'isActive' => '1', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'W58 mirror']]];
        $s58  = FeedAdmin::saveRule($mysqli, $c58, 0, ORG_A, SA, $rule58);
        $ok58 = [($p = pa_pending($q58a)) === null ? -1 : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($p)])['decided']];
        $rule58['detailLevel'] = 'full';
        FeedAdmin::saveRule($mysqli, $c58, (int) ($s58['ruleId'] ?? 0), ORG_A, SA, $rule58);
        $ok58[] = ($p = pa_pending($q58a)) === null ? -1 : FeedAdmin::decide($mysqli, ORG_A, SA, 'declined', '', [pa_item($p)])['decided'];
        $mid58  = [pa_ls($q58a), pa_ls($q58b)];
        $rule58['detailLevel'] = 'basic';
        $b58    = FeedAdmin::saveRule($mysqli, $c58, (int) ($s58['ruleId'] ?? 0), ORG_A, SA, $rule58);
        $page58 = pw_req($sa, 'GET', $choice . '?event=' . $q58a . '&scope=series')['body'];
        $rows58 = [$plain84($rowOf($page58, 'date-' . $q58a)), $plain84($rowOf($page58, 'date-' . $q58b))];
        pa_ok(
            'W58 approved at basic, declined at full, the rule put back: both dates are Everyone again, and each row says "A request was declined on <date>" — the page nowhere says "Declined on" and has no red badge on these rows',
            $s58['ok'] === true && $ok58 === [1, 1] && $mid58 === ['members/calendar', 'members/calendar'] && $b58['ok'] === true
            && pa_ls($q58a) === 'public/rule' && pa_ls($q58b) === 'public/rule'
            && str_contains($rows58[0], 'A request was declined on ') === true && str_contains($rows58[1], 'A request was declined on ') === true
            && str_contains(pw_main($page58), 'Declined on ') === false && str_contains($rowOf($page58, 'date-' . $q58a), 'bg-danger') === false,
            pa_show([$ok58, $mid58, pa_ls($q58a), pa_ls($q58b), $rows58])
        );

        // ---------------------------------------------------------------------
        pa_heading('W60 — roles, user groups and departments on the real form: offered for this organisation only, another\'s refused, and shown to the right people (#514 part 10, check R8)');
        // Part 10 of #514 lets a list name a role, a user group or a
        // department. Three things are asked of the REAL pages here:
        //   1. the form offers this organisation's — by name, escaped — and
        //      nothing of organisation B's, nothing retired or switched off;
        //   2. a number of B's posted by hand is refused with the one
        //      sentence, and nothing at all is saved;
        //   3. once saved, the calendar's event is on the organisation's
        //      calendar page for RH (who holds the role) and NOT for XB — a
        //      member of this organisation who holds a role, a user group
        //      and a department of ORGANISATION B — nor for M.
        // The names carry hostile text (601 to 603), so a missing escape in
        // the picker for one of the new kinds shows here.
        $hostile60 = static fn (int $n): string => 'W60 <script>alert(' . $n . ')</script>"><img src=x onerror=alert(' . $n . ')>';
        pa_q('UPDATE tblRoles SET roleName = ? WHERE roleID = ?', 'si', [$hostile60(601), RO1]);
        pa_q('UPDATE tblGroups SET groupName = ? WHERE groupID = ?', 'si', [$hostile60(602), UG1]);
        pa_q('UPDATE tblDepts SET deptName = ? WHERE deptID = ?', 'si', [$hostile60(603), DP1]);
        $c60    = pa_newFeed(ORG_A, 'members', ['name' => 'W60 calendar']);
        $title60 = 'W60 title Kq9 roles';
        // 📅 The event gets a day of its OWN (200 days ahead; no other check
        //    uses that day), and the checks below first make sure it really
        //    is alone there. The month page shows at most three events on a
        //    day and then "+ N more", and events that start at the same
        //    time come back in no promised order. The first version of this
        //    check used the default day, which a dozen other checks share:
        //    it passed every ordinary run and then, in one run with a fault
        //    planted elsewhere, showed the title to NOBODY — the title was
        //    simply not among the three drawn. A page check that depends on
        //    luck is worse than none, so the day is checked, not assumed.
        $e60    = pa_event($c60, 'w60@p8', $title60, ['day' => 200]);
        $set60  = $resolve($c60);
        $form60 = $edit . '?id=' . $c60;
        $page60 = pw_req($sa, 'GET', $form60);
        // The picker's own block: from its opening element to the script that follows it.
        $picker60 = (preg_match('/<div[^>]*id="feed-picker".*?<script\b/s', $page60['body'], $mp60) === 1) ? $mp60[0] : '';
        $box60    = static fn (string $kind, int $id): bool => preg_match(
            '/<input[^>]*id="feed-' . $kind . '-' . $id . '"[^>]*name="audience\[' . $kind . '\]\[\]"[^>]*value="' . $id . '"/s', $picker60
        ) === 1;
        $offered60 = [];
        foreach ([['role', RO1], ['user_group', UG1], ['department', DP1], ['role', SN], ['user_group', SN], ['department', SN],
            ['role', ROB], ['user_group', UGB], ['department', DPB],
            ['user_group', UG2], ['department', DP2], ['department', DP3]] as [$kind60, $id60]) {
            $offered60[$kind60 . ':' . $id60] = $box60($kind60, $id60);
        }
        pa_ok(
            'W60 the form offers A\'s roles, user groups and departments — RO1, UG1, DP1 and the three that share the number SN, so two boxes of each kind and no more — and not B\'s three, the retired UG2, the switched-off DP2 or DP3 (empty flag); nothing of B\'s is named anywhere in the picker',
            $set60 === true && $page60['status'] === 200 && $picker60 !== ''
            && $offered60 === [
                'role:' . RO1 => true, 'user_group:' . UG1 => true, 'department:' . DP1 => true,
                'role:' . SN => true, 'user_group:' . SN => true, 'department:' . SN => true,
                'role:' . ROB => false, 'user_group:' . UGB => false, 'department:' . DPB => false,
                'user_group:' . UG2 => false, 'department:' . DP2 => false, 'department:' . DP3 => false,
            ]
            && substr_count($picker60, 'name="audience[role][]"') === 2 && substr_count($picker60, 'name="audience[user_group][]"') === 2
            && substr_count($picker60, 'name="audience[department][]"') === 2 && str_contains($picker60, B_MARK) === false,
            'HTTP ' . $page60['status'] . ' ' . pa_show($offered60)
        );
        $at60 = [];
        foreach (['Small groups', 'Leadership roles', 'Roles', 'User groups', 'Departments', 'Named people'] as $legend60) {
            $at60[] = strpos($picker60, '>' . $legend60 . '</legend>');
        }
        $sorted60 = $at60;
        sort($sorted60);
        pa_ok(
            'W60 six groups of boxes, in this order: Small groups, Leadership roles, Roles, User groups, Departments, Named people',
            in_array(false, $at60, true) === false && $at60 === $sorted60 && substr_count($picker60, '</legend>') === 6,
            pa_show($at60)
        );
        // Each new kind's plain line: what it is, and a link to where it is
        // managed — an address that keeps the organisation's own prefix,
        // opens in a new tab (the form's ticks are not lost), says so, and
        // really answers for this administrator.
        $lines60 = [];
        foreach (['role' => ['admin/roles', 'Admin → Roles', 'It is not a leadership role.'], 'user_group' => ['admin/groups', 'Admin → Groups', 'It is not a small group.'],
            'department' => ['admin/departments', 'Admin → Departments', 'whatever part they play in it.']] as $kind60 => [$route60, $text60, $says60]) {
            $href60 = PW_A . '/' . $route60;
            $lines60[$kind60] = [
                'fieldset points at the line' => str_contains($picker60, 'aria-describedby="feed-' . $kind60 . '-about"') === true,
                'the line and its link' => preg_match(
                    '/<p[^>]*id="feed-' . $kind60 . '-about"[^>]*>[^<]*' . preg_quote(pw_esc($says60), '/') . '\s+Managed under\s+<a href="' . preg_quote($href60, '/')
                    . '" target="_blank" rel="noopener">' . preg_quote(pw_esc($text60), '/') . '<\/a>\s+\(opens in a new tab\)\.\s*<\/p>/s', $picker60
                ) === 1,
                'the link answers' => pw_req($sa, 'GET', $href60)['status'],
            ];
        }
        $line60 = ['fieldset points at the line' => true, 'the line and its link' => true, 'the link answers' => 200];
        pa_ok(
            'W60 under Roles, User groups and Departments: one plain line saying what it is, and a link to Admin → Roles, Groups or Departments with this organisation\'s prefix, opening in a new tab and saying so — and each link answers 200 for SA',
            $lines60 === ['role' => $line60, 'user_group' => $line60, 'department' => $line60],
            pa_show($lines60)
        );
        $raw60 = [];
        foreach ([601, 602, 603] as $n60) {
            if (str_contains($picker60, pw_esc($hostile60($n60))) === false) {
                $raw60[] = $n60 . ' is not shown in its escaped form';
            }
            foreach (['<script>alert(' . $n60 . ')', 'onerror=alert(' . $n60 . ')>'] as $bad60) {
                if (str_contains($page60['body'], $bad60) === true) {
                    $raw60[] = $n60 . ' appears raw: ' . $bad60;
                }
            }
        }
        pa_ok('W60 the role\'s, user group\'s and department\'s names (hostile text 601 to 603) are shown only in their escaped form', $raw60 === [], implode(' | ', $raw60));
        // The choice form and the rule form draw the SAME partial under
        // their own prefixes ("choice", "rule"). Each must offer the same
        // three boxes, and nothing of B's.
        $others60 = [];
        foreach (['choice' => $choice . '?event=' . $e60 . '&scope=date', 'rule' => $ruleP . '?feed=' . $c60] as $prefix60 => $url60) {
            $got60   = pw_req($sa, 'GET', $url60);
            $block60 = (preg_match('/<div[^>]*id="' . $prefix60 . '-picker".*?<script\b/s', $got60['body'], $mo60) === 1) ? $mo60[0] : '';
            $others60[$prefix60] = [
                'status' => $got60['status'],
                'A\'s three boxes' => str_contains($block60, 'id="' . $prefix60 . '-role-' . RO1 . '"') === true
                    && str_contains($block60, 'id="' . $prefix60 . '-user_group-' . UG1 . '"') === true
                    && str_contains($block60, 'id="' . $prefix60 . '-department-' . DP1 . '"') === true,
                'nothing of B\'s, retired or switched off' => $block60 !== '' && str_contains($block60, B_MARK) === false
                    && str_contains($block60, '-role-' . ROB . '"') === false && str_contains($block60, '-user_group-' . UGB . '"') === false
                    && str_contains($block60, '-department-' . DPB . '"') === false && str_contains($block60, '-user_group-' . UG2 . '"') === false
                    && str_contains($block60, '-department-' . DP2 . '"') === false && str_contains($block60, '-department-' . DP3 . '"') === false,
                'six groups' => substr_count($block60, '</legend>'),
            ];
        }
        $other60 = ['status' => 200, 'A\'s three boxes' => true, 'nothing of B\'s, retired or switched off' => true, 'six groups' => 6];
        pa_ok(
            'W60 the choice form and the rule form offer the same: A\'s role, user group and department, six groups of boxes, and nothing of B\'s, retired or switched off',
            $others60 === ['choice' => $other60, 'rule' => $other60],
            pa_show($others60)
        );

        // 2. A number of organisation B's, posted by hand, one kind at a
        //    time, together with one of A's own that would be accepted.
        $fp60      = pa_fp();
        $refused60 = [];
        foreach (['role' => [RO1, ROB], 'user_group' => [UG1, UGB], 'department' => [DP1, DPB]] as $kind60 => $ids60) {
            $r60 = pw_req($sa, 'POST', $form60, pa_calPost($c60, [
                'csrf_token' => pw_freshToken($sa, $form60), 'action' => 'save', 'audienceLevel' => 'groups', 'audience' => [$kind60 => $ids60],
            ]));
            $refused60[$kind60] = $r60['status'] . ':' . (str_contains($r60['body'], 'role="alert">' . pw_esc(ExternalAudience::REFUSED_MESSAGE)) === true ? 'the one sentence' : 'NOT the sentence')
                . ':' . (str_contains($r60['body'], B_MARK) === true ? 'names B' : 'names nothing of B');
        }
        $rows60 = (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE feedID = ?", 'i', [$c60])['n'];
        $want60 = '200:the one sentence:names nothing of B';
        pa_ok(
            'W60 B\'s role, user group and department, each posted by hand beside one of A\'s own: the form is drawn again with "' . ExternalAudience::REFUSED_MESSAGE . '", B is named nowhere, and NOTHING is saved (no list row, the calendar still Members)',
            $refused60 === ['role' => $want60, 'user_group' => $want60, 'department' => $want60] && $rows60 === 0 && pa_fp() === $fp60
            && pa_one('SELECT audienceLevel FROM tblExternalFeeds WHERE feedID = ?', 'i', [$c60])['audienceLevel'] === 'members',
            pa_show([$refused60, 'list rows' => $rows60])
        );

        // 3. A's own three, saved through the form; then who is shown the event.
        $rh60 = pw_signIn('rh');
        $xb60 = pw_signIn('xb');
        $start60 = (string) pa_one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$e60])['startDateTime'];
        $cal60   = PW_A . '/calendar?view=month&date=' . substr($start60, 0, 7);
        // How many events of this organisation (deleted ones aside) start on
        // that day: it must be this one alone — see where the event is made.
        $sameDay60 = (int) pa_one('SELECT COUNT(*) AS n FROM tblEvents WHERE siteID = ? AND isDeleted = 0 AND startDateTime >= ? AND startDateTime <= ?', 'iss', [ORG_A, substr($start60, 0, 10) . ' 00:00:00', substr($start60, 0, 10) . ' 23:59:59'])['n'];
        // While the calendar is still at Members, every member of A is shown
        // it — XB and M included. That is the control: it proves the title
        // would be found if it were there, so "not shown" below means refused.
        $before60 = [str_contains(pw_req($rh60, 'GET', $cal60)['body'], $title60), str_contains(pw_req($xb60, 'GET', $cal60)['body'], $title60), str_contains(pw_req($m, 'GET', $cal60)['body'], $title60)];
        $s60 = pw_req($sa, 'POST', $form60, pa_calPost($c60, [
            'csrf_token' => pw_freshToken($sa, $form60), 'action' => 'save', 'audienceLevel' => 'groups',
            'audience' => ['role' => [RO1], 'user_group' => [UG1], 'department' => [DP1]],
        ]));
        $saved60 = array_map(
            static fn (array $r): string => $r['kind'] . ':' . $r['refID'],
            pa_q("SELECT kind, refID FROM tblExternalAudienceMembers WHERE feedID = ? AND ownerType = 'feed' AND ownerID = ? ORDER BY audienceMemberID", 'ii', [$c60, $c60])
        );
        pa_ok(
            'W60 SA saves the calendar as Selected groups {RO1, UG1, DP1} through the form: 302 to its page, the three rows stored, and the list reads "Selected groups: 1 role, 1 user group, 1 department"',
            $s60['status'] === 302 && $s60['location'] === $view . '?id=' . $c60 && $saved60 === ['role:' . RO1, 'user_group:' . UG1, 'department:' . DP1]
            && pa_ls($e60) === 'groups/calendar'
            && str_contains(pw_req($sa, 'GET', $list)['body'], pw_esc('Selected groups: 1 role, 1 user group, 1 department')) === true,
            'HTTP ' . $s60['status'] . ' ' . $s60['location'] . ' ' . pa_show([$saved60, pa_ls($e60)])
        );
        // 🔒 Organisation B's role, user group and department are now written
        //    onto this calendar's list BY HAND (the form has just refused
        //    them, above). XB holds exactly those three, in B, and is a
        //    member here. THIS is what makes "XB is not shown the event" a
        //    real proof: the first version of this check left the list at
        //    A's three, and a planted fault in the rule that judged a role
        //    by the HOLDING'S organisation instead of the event's passed it
        //    unnoticed — XB holds nothing of A's, so with only A's three on
        //    the list nothing could let XB in whatever the rule did. Found by
        //    that planted fault on 13 October 2026, before this was committed.
        pa_list($c60, 'feed', $c60, [['role', ROB], ['user_group', UGB], ['department', DPB]]);
        $listed60 = (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE feedID = ? AND ownerType = 'feed' AND ownerID = ?", 'ii', [$c60, $c60])['n'];
        $after60 = [str_contains(pw_req($rh60, 'GET', $cal60)['body'], $title60), str_contains(pw_req($xb60, 'GET', $cal60)['body'], $title60), str_contains(pw_req($m, 'GET', $cal60)['body'], $title60)];
        pa_ok(
            'W60 on the organisation\'s calendar page: at Members RH, XB and M were all shown the event; listed for A\'s role, user group and department AND (by hand) organisation B\'s three, RH (who holds A\'s role) still is — XB (a member here who holds exactly B\'s three) and M are not',
            $sameDay60 === 1 && $before60 === [true, true, true] && $listed60 === 6 && $after60 === [true, false, false],
            pa_show(['events of A that day (must be 1)' => $sameDay60, 'at Members' => $before60, 'rows on the list' => $listed60, 'at Selected groups' => $after60])
        );
        // …and taking the role off RH takes the event off RH's page at once.
        pa_q('DELETE FROM tblUserRoles WHERE userID = ? AND roleID = ?', 'ii', [RH_ID, RO1]);
        $without60 = str_contains(pw_req($rh60, 'GET', $cal60)['body'], $title60);
        pa_q('INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, ?, ?)', 'iii', [RH_ID, RO1, ORG_A]);
        pa_ok('W60 the role taken off RH: the very next time RH opens the calendar page the event is not there (and it is back once the role is)', $without60 === false && str_contains(pw_req($rh60, 'GET', $cal60)['body'], $title60) === true);

        // ---------------------------------------------------------------------
        pa_heading('W59 — #534: who is shown ONE event, on the four addresses that show one (seven viewers by five events)');
        // NOT a part 8 check. #534 asked for a table of who may open a single
        // event, proved on the real pages; this file holds the portal's only
        // harness that drives real pages (a scratch copy of web/, real
        // sign-ins, one cookie jar per account) and already has two
        // organisations and these kinds of account, so the table lives here
        // rather than in a second harness saying the same thing (roll-out plan,
        // decision 4, 11 October 2026).
        //
        // FOUR ADDRESSES: the event's own page, the single-event download, and
        // the two Team Hub data endpoints (which had NO viewer test at all
        // until #534: any signed-in account, of any organisation, was answered).
        // FIVE EVENTS of organisation A, all the portal's own (not imported):
        // public; internal; a draft; a deleted one; and a number that matches
        // nothing. The draft is marked PUBLIC on purpose, so its column tests
        // the draft rule on its own and the internal column tests the
        // membership rule on its own.
        // SEVEN VIEWERS: nobody; PB, a member of organisation B only, on A's
        // address; M, a member of A; P, a member of A who is also a
        // coordinator of all four events; SGM, a member of A who is on a crew
        // of the public, internal and draft events and has no other rights;
        // SA, a site administrator of A; GA, a global administrator with no
        // membership anywhere.
        //
        // THE TWO TEAM HUB ENDPOINTS ANSWER ONLY THE EVENT'S OWN TEAM (roll-out
        // package B2a, 12 October 2026), as the Team Hub page always did.
        // Before that they applied the event rule alone, so M (a member not on
        // the team) and, for the public event, PB were answered. Two rules now
        // meet on those two rows, and the table keeps them apart:
        //   - M is a member, so the EVENT rule says yes to the public and the
        //     internal event; M is on no team, so only the TEAM rule refuses.
        //   - PB is put on a crew of the INTERNAL event (an outside helper), so
        //     the TEAM rule says yes there; PB is not a member of A, so only
        //     the EVENT rule refuses. PB is on no crew of the public event, so
        //     there the team rule refuses.
        //
        // An answer is written "200+shown" (HTTP 200 AND the event's own
        // marker word is in the answer), or the bare status of a refusal. A
        // refusal that still carried the marker would read "404+shown" and
        // fail: the status alone is not trusted.
        $w59 = ['public' => 919001, 'internal' => 919002, 'draft' => 919003, 'deleted' => 919004, 'missing' => 919009];
        $day59 = pa_todayLocal()->modify('+9 days')->format('Y-m-d');
        foreach ([['public', 'published', 1, 0], ['internal', 'published', 0, 0], ['draft', 'draft', 1, 0], ['deleted', 'published', 1, 1]] as [$kind59, $status59, $public59, $deleted59]) {
            pa_q(
                'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'iisssssssii',
                [$w59[$kind59], ORG_A, 'w59-' . $kind59, 'W59 ' . $kind59 . ' w59mark' . $w59[$kind59], 'W59 description', $day59 . ' 10:00:00', $day59 . ' 11:00:00', SITE_ZONE, $status59, $public59, $deleted59]
            );
            pa_q("INSERT INTO tblEventHubResources (eventID, section, resourceType, title, body) VALUES (?, 'W59', 'note', ?, 'W59 team note')", 'is', [$w59[$kind59], 'Hub note w59mark' . $w59[$kind59]]);
            pa_q("INSERT INTO tblEventHubVideos (eventID, provider, videoRef, title) VALUES (?, 'youtube', 'w59ref', ?)", 'is', [$w59[$kind59], 'Hub video w59mark' . $w59[$kind59]]);
            pa_q('INSERT INTO tblEventCoordinators (eventID, userID, grantedByID) VALUES (?, ?, ?)', 'iii', [$w59[$kind59], P_ID, SA]);
        }
        // Crews: SGM on a crew of the public, internal and draft events; PB on a
        // crew of the internal event only. (Removed with their events.)
        foreach ([[919201, 'public', SGM], [919202, 'internal', SGM], [919203, 'draft', SGM], [919204, 'internal', PB]] as [$crew59, $kind59, $person59]) {
            pa_q("INSERT INTO tblEventCrews (crewID, eventID, name) VALUES (?, ?, 'W59 crew')", 'ii', [$crew59, $w59[$kind59]]);
            pa_q("INSERT INTO tblEventCrewMembers (crewID, userID, role) VALUES (?, ?, 'participant')", 'ii', [$crew59, $person59]);
        }
        $pb59 = pw_signIn('pb');
        $made59 = pa_one(
            'SELECT (SELECT COUNT(*) FROM tblEvents WHERE eventID BETWEEN 919001 AND 919004 AND siteID = ? AND externalFeedID IS NULL) AS events, '
            . '(SELECT COUNT(*) FROM tblEventHubResources WHERE eventID BETWEEN 919001 AND 919004) AS notes, '
            . '(SELECT COUNT(*) FROM tblEventHubVideos WHERE eventID BETWEEN 919001 AND 919004) AS videos, '
            . '(SELECT COUNT(*) FROM tblEventCoordinators WHERE eventID BETWEEN 919001 AND 919004 AND userID = ? AND revokedAt IS NULL) AS coordinating, '
            . '(SELECT COUNT(*) FROM tblEvents WHERE eventID = 919009) AS missing, '
            . '(SELECT COUNT(*) FROM tblUserSites WHERE userID = ? AND siteID = ?) AS pbInA, '
            . '(SELECT COUNT(*) FROM tblUserSites WHERE userID = ? AND siteID = ? AND isActive = 1) AS pbInB, '
            . '(SELECT COUNT(*) FROM tblUserSites WHERE userID = ?) AS gaAnywhere, '
            . '(SELECT COUNT(*) FROM tblEventCrewMembers cm JOIN tblEventCrews c ON c.crewID = cm.crewID WHERE cm.userID = ? AND c.eventID IN (919001, 919002, 919003)) AS sgmCrews, '
            . '(SELECT GROUP_CONCAT(c.eventID) FROM tblEventCrewMembers cm JOIN tblEventCrews c ON c.crewID = cm.crewID WHERE cm.userID = ?) AS pbCrewOf, '
            . '(SELECT COUNT(*) FROM tblEventCrewMembers cm JOIN tblEventCrews c ON c.crewID = cm.crewID WHERE cm.userID = ? AND c.eventID BETWEEN 919001 AND 919004) AS mCrews',
            'iiiiiiiiii', [ORG_A, P_ID, PB, ORG_A, PB, ORG_B, GA, SGM, PB, M]
        );
        pa_ok(
            'W59 set-up: four events of A (public, internal, draft, deleted), each with a Team Hub note and video and P as coordinator; number 919009 matches nothing; PB belongs to B only and is on a crew of the internal event only; SGM is on a crew of the public, internal and draft events; M is on none; GA belongs to no organisation',
            $made59 === ['events' => 4, 'notes' => 4, 'videos' => 4, 'coordinating' => 4, 'missing' => 0, 'pbInA' => 0, 'pbInB' => 1, 'gaAnywhere' => 0, 'sgmCrews' => 3, 'pbCrewOf' => '919002', 'mCrews' => 0],
            pa_show($made59)
        );
        $ask59 = static function (string $jar, string $address, string $kind) use ($w59): string {
            $path = [
                'page'          => PW_A . '/calendar/event?slug=' . ($kind === 'missing' ? 'w59-no-such-event' : 'w59-' . $kind),
                'download'      => PW_A . '/calendar/export?id=' . $w59[$kind],
                'hub-resources' => PW_A . '/api/calendar/hub-resources?eventID=' . $w59[$kind],
                'hub-videos'    => PW_A . '/api/calendar/hub-videos?eventID=' . $w59[$kind],
            ][$address];
            $answer = pw_req($jar, 'GET', $path);
            // Any of this run's markers in the answer counts, not only the
            // asked event's own: a refusal that showed a DIFFERENT event
            // would be just as wrong.
            return $answer['status'] . (str_contains($answer['body'], 'w59mark') === true ? '+shown' : '');
        };
        // 📋 THE EXPECTED TABLE — written out by hand from the rule
        //    (EventVisibility in "session" mode, the draft rule, and
        //    ApiAuth::requireRead()), BEFORE the first run, never worked out by
        //    code: a loop that computed it would be a second copy of the rule
        //    and agree with it even when both were wrong.
        //    Columns: public, internal, draft, deleted, a number that matches nothing.
        //      - public: shown to everybody, signed in or not (the two hub
        //        endpoints need a sign-in first: 401 for nobody, whatever the event);
        //      - internal: only an active member of A, or an administrator of it;
        //      - draft: only somebody who can manage events (App::isAdmin()). A
        //        coordinator is NOT that, on any of these four addresses;
        //      - deleted, missing: nobody, and the SAME answer as each other
        //        and as a refused event, so the answer never says which it is;
        //      - the two hub endpoints ALSO need the viewer to be on that
        //        event's own team (Auth::isEventTeamMember(): an administrator,
        //        a coordinator, a crew member, somebody with a job on it).
        //        Both rules must say yes. Not on the team is the same 404.
        $expected59 = [
            'nobody (signed out)' => [
                'page'          => ['200+shown', '404', '404', '404', '404'],
                'download'      => ['200+shown', '404', '404', '404', '404'],
                'hub-resources' => ['401', '401', '401', '401', '401'],
                'hub-videos'    => ['401', '401', '401', '401', '401'],
            ],
            // Hub rows: public, PB is on no team of it (the team rule refuses);
            // internal, PB IS on a crew of it but is not a member of A (the
            // event rule refuses).
            'PB, a member of ANOTHER organisation only' => [
                'page'          => ['200+shown', '404', '404', '404', '404'],
                'download'      => ['200+shown', '404', '404', '404', '404'],
                'hub-resources' => ['404', '404', '404', '404', '404'],
                'hub-videos'    => ['404', '404', '404', '404', '404'],
            ],
            // Hub rows: M may see the public and the internal event, and is
            // on the team of neither (the team rule refuses both).
            'M, a member' => [
                'page'          => ['200+shown', '200+shown', '404', '404', '404'],
                'download'      => ['200+shown', '200+shown', '404', '404', '404'],
                'hub-resources' => ['404', '404', '404', '404', '404'],
                'hub-videos'    => ['404', '404', '404', '404', '404'],
            ],
            // On a crew of the public, internal and draft events: both rules
            // say yes for the first two. Being on the draft's crew does not
            // open the draft: only somebody who can manage events sees one.
            'SGM, a member on a crew of the public, internal and draft events' => [
                'page'          => ['200+shown', '200+shown', '404', '404', '404'],
                'download'      => ['200+shown', '200+shown', '404', '404', '404'],
                'hub-resources' => ['200+shown', '200+shown', '404', '404', '404'],
                'hub-videos'    => ['200+shown', '200+shown', '404', '404', '404'],
            ],
            'P, a member who coordinates all four events' => [
                'page'          => ['200+shown', '200+shown', '404', '404', '404'],
                'download'      => ['200+shown', '200+shown', '404', '404', '404'],
                'hub-resources' => ['200+shown', '200+shown', '404', '404', '404'],
                'hub-videos'    => ['200+shown', '200+shown', '404', '404', '404'],
            ],
            'SA, a site administrator' => [
                'page'          => ['200+shown', '200+shown', '200+shown', '404', '404'],
                'download'      => ['200+shown', '200+shown', '200+shown', '404', '404'],
                'hub-resources' => ['200+shown', '200+shown', '200+shown', '404', '404'],
                'hub-videos'    => ['200+shown', '200+shown', '200+shown', '404', '404'],
            ],
            'GA, a global administrator' => [
                'page'          => ['200+shown', '200+shown', '200+shown', '404', '404'],
                'download'      => ['200+shown', '200+shown', '200+shown', '404', '404'],
                'hub-resources' => ['200+shown', '200+shown', '200+shown', '404', '404'],
                'hub-videos'    => ['200+shown', '200+shown', '200+shown', '404', '404'],
            ],
        ];
        // The jars are taken from where pw_signIn() recorded them, not from the
        // short variables set at the top of this function: an earlier check
        // reuses `$p` for something else (W58), and a jar variable quietly
        // holding a different value would test the wrong person.
        $jars59 = [
            'nobody (signed out)'                         => $out,
            'PB, a member of ANOTHER organisation only'   => $pb59,
            'M, a member'                                 => (string) $pw['jars']['m'],
            'SGM, a member on a crew of the public, internal and draft events' => (string) $pw['jars']['sgm'],
            'P, a member who coordinates all four events' => (string) $pw['jars']['p'],
            'SA, a site administrator'                    => (string) $pw['jars']['sa'],
            'GA, a global administrator'                  => (string) $pw['jars']['ga'],
        ];
        $kinds59 = ['public', 'internal', 'draft', 'deleted', 'missing'];
        foreach ($expected59 as $viewer59 => $byAddress59) {
            foreach ($byAddress59 as $address59 => $want59) {
                $got59 = [];
                foreach ($kinds59 as $kind59) {
                    $got59[] = $ask59($jars59[$viewer59], $address59, $kind59);
                }
                pa_ok(
                    'W59 ' . $viewer59 . ' — ' . $address59 . ': public, internal, draft, deleted, missing answer ' . implode(', ', $want59),
                    $got59 === $want59,
                    'got ' . implode(', ', $got59)
                );
            }
        }
        // 🧪 THE TABLE'S OWN CONTROL. In THIS RUN'S SCRATCH COPY (never the
        //    repository), the shared rule is taken out of one of the four
        //    pages, and the cell the rule exists for is asked again: PB, a
        //    member of another organisation only (and on a crew of A's
        //    internal event, so the team rule is not what refuses), must now
        //    be SHOWN A's internal event's Team Hub note. If that does not happen, the table
        //    above could not have noticed the rule missing, and every "404" in
        //    it proves nothing. The page is then put back byte for byte and
        //    must refuse again. The file's time stamp is moved on each write so
        //    the server reads the new text at once (see the two opcache
        //    settings where the server is started).
        $hub59  = $pw['child'] . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps' . DIRECTORY_SEPARATOR . 'calendar' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'hub-resources.php';
        $real59 = (string) file_get_contents($hub59);
        $rule59 = "\$visibility = EventVisibility::where('e', \$ruleMode, \$viewerId, date('Y-m-d'));";
        $none59 = "\$visibility = ['sql' => '', 'types' => '', 'params' => []];";
        $time59 = (int) filemtime($hub59);
        try {
            file_put_contents($hub59, str_replace($rule59, $none59, $real59));
            touch($hub59, $time59 + 60);
            clearstatcache(true, $hub59);
            $leak59 = $ask59($pb59, 'hub-resources', 'internal');
        } finally {
            file_put_contents($hub59, $real59);
            touch($hub59, $time59 + 120);
            clearstatcache(true, $hub59);
        }
        pa_ok(
            'W59 control: with the shared rule taken out of the scratch copy\'s hub-resources page, PB IS shown A\'s internal event\'s Team Hub note — so the table above can tell',
            substr_count($real59, $rule59) === 1 && $leak59 === '200+shown',
            'the rule\'s line was found ' . substr_count($real59, $rule59) . ' time(s); PB was answered ' . $leak59
        );
        pa_ok(
            'W59 control: with the page put back exactly as it was, PB is refused again',
            (string) file_get_contents($hub59) === $real59 && $ask59($pb59, 'hub-resources', 'internal') === '404',
            'PB was answered ' . $ask59($pb59, 'hub-resources', 'internal')
        );
        // 🔑 AN API KEY IS ANSWERED AS BEFORE #534. The table above asks only
        //    as people. The two hub endpoints exist for an outside system that
        //    reads them with a key (scope `eventhub:read`), and a key is
        //    answered for the organisation's own events whether public or
        //    internal — that did not change. Without these two checks, a
        //    version that applied the people's rule to keys as well (so a key
        //    stopped being answered for internal events) passed every check
        //    in this file. A key of the OTHER organisation is still "not
        //    found", as it always was.
        $keyA59 = 'wbms_' . bin2hex(random_bytes(16));
        $keyB59 = 'wbms_' . bin2hex(random_bytes(16));
        foreach ([[ORG_A, $keyA59], [ORG_B, $keyB59]] as [$org59, $plain59]) {
            pa_q(
                "INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, isActive, createdByID) VALUES (?, 'W59 key', ?, ?, 'eventhub:read', 1, NULL)",
                'iss', [$org59, hash('sha256', $plain59), substr($plain59, 0, 12)]
            );
        }
        // B's key is judged by B's own Calendar switch (#478), so B has it on for these two requests.
        pa_q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, 'calendar.enabled', 'true', 'false', 0)", 'i', [ORG_B]);
        $asKey59 = static function (string $key, string $address, string $kind) use ($w59, $out): string {
            $answer = pw_req($out, 'GET', PW_A . '/api/calendar/' . $address . '?eventID=' . $w59[$kind], null, false, $key);

            return $answer['status'] . (str_contains($answer['body'], 'w59mark') === true ? '+shown' : '');
        };
        foreach (['hub-resources', 'hub-videos'] as $address59) {
            $gotA59 = [];
            $gotB59 = [];
            foreach ($kinds59 as $kind59) {
                $gotA59[] = $asKey59($keyA59, $address59, $kind59);
                $gotB59[] = $asKey59($keyB59, $address59, $kind59);
            }
            pa_ok(
                'W59 an API key of organisation A — ' . $address59 . ': public, internal, draft, deleted, missing answer 200+shown, 200+shown, 404, 404, 404 (as before #534); a key of organisation B gets 404 for all five',
                $gotA59 === ['200+shown', '200+shown', '404', '404', '404'] && $gotB59 === ['404', '404', '404', '404', '404'],
                "A's key: " . implode(', ', $gotA59) . " | B's key: " . implode(', ', $gotB59)
            );
        }

        // 🏛️ ONE MORE PAGE OF THE SAME KIND, found by the #534 sweep: the
        //    read-only card of a venue booking (what anybody who is not a
        //    venue manager gets) printed "Linked event: <name>" for whoever
        //    opened it — any signed-in account, for an internal event and a
        //    draft alike. It now names the event only to somebody the shared
        //    rule would show that event to. Three bookings of one hall, linked
        //    to the public, the internal and the draft event above. Only the
        //    page's own content is read (pw_main()), never its menu.
        //    WHO MAY OPEN A BOOKING AT ALL changed with roll-out package B2a
        //    (12 October 2026): only the organisation's own members and venue
        //    managers (Venues::canView()). So PB, who belongs to another
        //    organisation only, used to get the card with the public event
        //    named, and now gets "page not found" for all three bookings.
        //    tools/access-pages-selftest.php (part H) tests that rule on all
        //    six venue addresses; here it is one row.
        pa_q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, 'venues.enabled', '1', '0', 0)", 'i', [ORG_A]);
        pa_q("INSERT INTO tblVenues (venueID, siteID, venueName, isActive) VALUES (919101, ?, 'W59 hall', 1)", 'i', [ORG_A]);
        pa_q("INSERT INTO tblVenueUsageTypes (usageTypeID, siteID, venueID, typeName) VALUES (919111, ?, 919101, 'W59 use')", 'i', [ORG_A]);
        pa_q("INSERT INTO tblVenueStatuses (statusID, siteID, statusName) VALUES (919121, ?, 'W59 status')", 'i', [ORG_A]);
        foreach ([919131 => 'public', 919132 => 'internal', 919133 => 'draft'] as $booking59 => $kind59) {
            pa_q(
                'INSERT INTO tblVenueBookings (bookingID, siteID, venueID, bookingDate, usageTypeID, statusID, eventID) VALUES (?, ?, 919101, ?, 919111, 919121, ?)',
                'iisi', [$booking59, ORG_A, $day59, $w59[$kind59]]
            );
        }
        $card59 = static function (string $jar, int $booking): string {
            $answer = pw_req($jar, 'GET', PW_A . '/venues/booking?id=' . $booking);
            $main   = pw_main($answer['body']);
            // "hall" proves the card itself was drawn; "+event" that it names the linked event.
            return $answer['status'] . (str_contains($main, 'W59 hall') === true ? '+hall' : '') . (str_contains($main, 'w59mark') === true ? '+event' : '');
        };
        $pbCards59 = [$card59($pb59, 919131), $card59($pb59, 919132), $card59($pb59, 919133)];
        pa_ok(
            'W59 venue booking card, PB (another organisation only): "page not found" for all three bookings, and neither the hall nor any event is named',
            $pbCards59 === ['404', '404', '404'],
            'public, internal, draft: ' . implode(', ', $pbCards59)
        );
        $mCards59 = [$card59((string) $pw['jars']['m'], 919131), $card59((string) $pw['jars']['m'], 919132), $card59((string) $pw['jars']['m'], 919133)];
        pa_ok(
            'W59 venue booking card, M (a member): the public and the internal event are named; the draft is not',
            $mCards59 === ['200+hall+event', '200+hall+event', '200+hall'],
            'public, internal, draft: ' . implode(', ', $mCards59)
        );
        $form59 = pw_main(pw_req((string) $pw['jars']['sa'], 'GET', PW_A . '/venues/booking?id=919133')['body']);
        pa_ok(
            'W59 venue booking, SA (who manages bookings): still gets the form, with the organisation\'s events to choose from, the draft included',
            str_contains($form59, 'name="eventID"') === true && str_contains($form59, 'w59mark' . $w59['draft']) === true && str_contains($form59, 'w59mark' . $w59['internal']) === true
        );

        // 🚪 F2, AT THE DOOR. Everything above ran with the members-only gate
        //    stood down in the scratch copy. Here it is put back, as the
        //    portal really runs, and the two outsiders are sent to the same
        //    addresses again. See pw_membersGate().
        pw_membersGate(true);
        $door59 = pw_req($sb, 'GET', $evUrl($e34));
        pa_ok(
            'W59 at the door: SB (an administrator of B only) is refused (403) on A\'s public imported event, and its title is not shown',
            $door59['status'] === 403 && str_contains($door59['body'], 'W34 public event') === false,
            'HTTP ' . $door59['status']
        );
        $door59 = pw_req($sb, 'POST', $act, ['csrf_token' => pw_freshToken($sb, PW_B . '/account')]);
        pa_ok('W59 at the door: SB, with a form token from their own organisation\'s page, is refused (403) by A\'s decision handler', $door59['status'] === 403, 'HTTP ' . $door59['status']);
        $notRefused59 = [];
        foreach (['page', 'download', 'hub-resources', 'hub-videos'] as $address59) {
            foreach ($kinds59 as $kind59) {
                $got59 = $ask59($pb59, $address59, $kind59);
                if ($got59 !== '403') {
                    $notRefused59[] = $address59 . ', ' . $kind59 . ': ' . $got59;
                }
            }
        }
        pa_ok('W59 at the door: PB (a member of B only) is refused (403) on all four addresses for all five events, the public one included, and none is shown', $notRefused59 === [], implode('; ', $notRefused59));
        $pbCards59 = [$card59($pb59, 919131), $card59($pb59, 919132), $card59($pb59, 919133)];
        pa_ok('W59 at the door: PB is refused (403) on all three venue booking cards', $pbCards59 === ['403', '403', '403'], implode(', ', $pbCards59));
        // A control, so that "refused" above cannot be a server that refuses everybody.
        pa_ok('W59 at the door, control: with the gate back, M (a member) is still shown the public and the internal event', $ask59((string) $pw['jars']['m'], 'page', 'public') === '200+shown' && $ask59((string) $pw['jars']['m'], 'page', 'internal') === '200+shown');

        // ---------------------------------------------------------------------
        pa_heading('W14 — no error recorded about any part 8 file');
        // What counts: a line of PHP's own error log, or a row of the
        // portal's error table, that names a part 8 FILE (a warning, a
        // notice or an exception raised in it), or any PHP error or
        // exception row recorded while one of the part 8 PAGES was being
        // drawn (a fault in the template a page includes, say).
        // What does not: the "Outside address fetch did not succeed" notices
        // SafeFetch records for every failed download (platform SafeFetch).
        // W8 and W19 make downloads fail on purpose, so those rows are
        // expected; their titles name only the host, never a file.
        // Chunk P8-3 adds its two pages and the two pages it changes (named
        // with their folder, so another file called "event.php" never counts).
        // Chunk P8-4 adds its four new files and the six it changes; and the
        // addresses of its pages join "while a part 8 page was being drawn".
        $files   = ['feeds.php', 'feeds-edit.php', 'feeds-view.php', 'feeds-save.php', '_audience-picker.php', 'FeedAdmin.php', 'ExternalAudience.php', 'FeedImporter.php',
            'feeds-choice.php', 'feeds-rule.php', 'calendar/event.php', 'manage/series-edit.php',
            'feeds-preview.php', 'feeds-approvals-act.php', '_approval-rows.php', '_imports-section.php', 'approvals/index.php',
            'help/calendar-imports.php', 'help/calendar.php', 'help/approvals.php', 'help/index.php', '_install/upgrade.php', 'integrations/api-keys.php'];
        $pages14 = ['/admin/calendar/feeds', '/approvals', '/help', '/admin/upgrade', '/admin/integrations/api-keys'];
        $logText = (string) @file_get_contents($pw['child'] . DIRECTORY_SEPARATOR . 'php-errors.log');
        $logHits = [];
        foreach (preg_split('/\R/', $logText) as $line) {
            foreach ($files as $file) {
                if (str_contains((string) $line, $file) === true) {
                    $logHits[] = mb_substr((string) $line, 0, 200);
                    break;
                }
            }
        }
        $dbHits  = [];
        $dbRows  = pa_q('SELECT errorID, errorPlatform, errorTitle, errorDetail, requestURL FROM tblErrors WHERE errorID > ?', 'i', [$pw['errorsFrom']]);
        foreach ($dbRows as $row) {
            $text   = (string) $row['errorTitle'] . ' ' . (string) $row['errorDetail'];
            $onPage = false;
            foreach ($pages14 as $address) {
                if ((string) $row['errorPlatform'] === 'PHP' && str_contains((string) $row['requestURL'], $address) === true) {
                    $onPage = true;
                }
            }
            $names  = false;
            foreach ($files as $file) {
                if (str_contains($text, $file) === true) {
                    $names = true;
                    break;
                }
            }
            if ($names === true || $onPage === true) {
                $dbHits[] = '#' . $row['errorID'] . ' ' . (string) $row['errorPlatform'] . ': ' . mb_substr((string) $row['errorTitle'], 0, 120);
            }
        }
        pa_ok(
            'W14 the server\'s PHP error log and the portal\'s error table hold nothing about a part 8 file (' . count($dbRows) . ' error rows looked at)',
            $logHits === [] && $dbHits === [],
            pa_show(['log' => $logHits, 'errors' => $dbHits])
        );
    } catch (Throwable $e) {
        pa_ok('part W ran to the end', false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        pw_cleanup();
    }

    // -------------------------------------------------------------------------
    pa_heading('W0 — the scratch folder, the server and the clean-up (checked last)');
    pa_ok(
        'W0 the scratch child is gone, deleted only because its marker still held this run\'s token',
        $pw['markerHeld'] === true && $pw['childRemoved'] === true && file_exists((string) $pw['child']) === false,
        pa_show(['markerHeld' => $pw['markerHeld'], 'removed' => $pw['childRemoved']])
    );
    $after = pw_names((string) $pw['parent']);
    pa_ok(
        'W0 the scratch PARENT holds exactly the entries it held before the run (' . count($after) . ')',
        is_array($pw['parentBefore']) === true && $after === $pw['parentBefore'],
        'gained: ' . implode(', ', array_diff($after, (array) $pw['parentBefore'])) . ' | lost: ' . implode(', ', array_diff((array) $pw['parentBefore'], $after))
    );
    pa_ok(
        'W0 every answer (' . $pw['answers'] . ') carried this run\'s header, so all came from the server this run started',
        $pw['answers'] > 0 && $pw['without'] === [],
        implode(' | ', array_slice($pw['without'], 0, 5))
    );
    $zoneNow  = (string) (pa_one('SELECT @@GLOBAL.time_zone AS z')['z'] ?? '');
    $settings = [];
    foreach ($pw['settings'] as $key => $before) {
        $now            = pa_one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]);
        $settings[$key] = ($now === $before);
    }
    pa_ok(
        'W0 the clean-up put everything back: the settings it changed, the database\'s own time zone, the made-up world, and nothing it could not do',
        $pw['problems'] === [] && $zoneNow === $pw['dbZone'] && $settings !== [] && in_array(false, $settings, true) === false
        && pa_one('SELECT siteID FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]) === null
        && is_resource($pw['proc']) === false && pw_portAnswers((int) $pw['port']) === false
        // Chunk P8-4: every migration row W52 recorded is back exactly as it was.
        && array_filter((array) $pw['migrationRows'], static fn (array $row): bool => pa_one('SELECT migrationID, filename, executedAt, executedByID FROM tblMigrations WHERE filename = ?', 's', [(string) $row['filename']]) !== $row) === [],
        pa_show(['problems' => $pw['problems'], 'zone' => [$zoneNow, $pw['dbZone']], 'settings' => $settings])
    );
}

echo '#514 part P8 — FeedAdmin/ExternalAudience self-test, parts S, D and W' . (PA_ONLY === '' ? '' : ' (ONLY part ' . PA_ONLY . ' this time)')
    . ' — ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo 'PHP ' . PHP_VERSION . ' (PHP zone ' . date_default_timezone_get() . '), database ' . (string) (pa_one('SELECT VERSION() AS v')['v'] ?? '?') . "\n";

// =============================================================================
// 📄 PART S — the pages never write around the service layer
// =============================================================================
if (pa_wants('S') === true) {
    ps_run();
}

pa_removeWorld(); // in case an earlier run was interrupted

// =============================================================================
// 🗄️ PART D — the service class, against the database
// =============================================================================
if (pa_wants('D') === true) {
try {

// =============================================================================
// D0 — the test's own clocks
// =============================================================================
pa_heading('D0 — the test\'s own clocks');
$skew = pa_one('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS m, @@session.time_zone AS z');
pa_ok(
    'D0 the test\'s database session runs 13 hours ahead of UTC, so NOW() and UTC_TIMESTAMP() differ (checker round 2, LOW 4)',
    ($skew['z'] ?? '') === '+13:00' && (int) ($skew['m'] ?? 0) === 780,
    pa_show($skew)
);

// =============================================================================
// D1 — reads are organisation-scoped
// =============================================================================
// Organisation B gets real rows of the SAME shape as A's — a waiting row, a
// rule, a series choice, category words, a run, a Selected-groups list —
// every one named with B_MARK, and B's events carry the SAME identity text
// as A's (so their identity hashes are identical: only the organisation
// and the calendar tell them apart). Then EVERY public read, called as A,
// must never return anything of B's — checked by B_MARK in the answer,
// not only by counting.
pa_heading('D1 — reads are organisation-scoped');
pa_resetWorld();
$evA = pa_event(FA1, 'shared-one@p8', 'D1 ALPHA event', ['categoryID' => CA1, 'tags' => ['alphaword']]);
[$sA1, $sA2] = pa_series(FA1, 'shared-series@p8', 'D1 ALPHA series', ['count' => 2]);
pa_q('INSERT INTO tblExternalCategoryMap (siteID, feedID, externalCategory, categoryID) VALUES (?, ?, ?, ?)', 'iisi', [ORG_A, FA1, 'alphaword', CA1]);
$ruleA   = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D1 ALPHA rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D1 ALPHA event']]]);
$choiceA = FeedAdmin::saveChoice($mysqli, $sA1, 'series', ORG_A, SA, ['audienceLevel' => 'members', 'note' => 'ALPHA note']);
pa_run(FA1, 'ALPHA run');
$fa2Save = FeedAdmin::saveCalendar($mysqli, FA2, ORG_A, SA, pa_calPost(FA2, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]));

pa_q("UPDATE tblExternalFeeds SET audienceLevel = 'members' WHERE feedID = ?", 'i', [FB1]);
$evB = pa_event(FB1, 'shared-one@p8', 'D1 BONLY event', ['categoryID' => CB1, 'tags' => ['bonlyword']]);
[$sB1, $sB2] = pa_series(FB1, 'shared-series@p8', 'D1 BONLY series', ['count' => 2]);
pa_q('INSERT INTO tblExternalCategoryMap (siteID, feedID, externalCategory, categoryID) VALUES (?, ?, ?, ?)', 'iisi', [ORG_B, FB1, 'bonlyword', CB1]);
$ruleB   = FeedAdmin::saveRule($mysqli, FB1, 0, ORG_B, SB, ['name' => 'D1 BONLY rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D1 BONLY event']]]);
$choiceB = FeedAdmin::saveChoice($mysqli, $sB1, 'series', ORG_B, SB, ['audienceLevel' => 'members', 'note' => 'BONLY note']);
pa_run(FB1, 'BONLY run');
$fbGroups = pa_newFeed(ORG_B, 'groups', ['name' => 'Selftest groups calendar BONLY']);
pa_list($fbGroups, 'feed', $fbGroups, [['small_group', SGB], ['leadership_role', RB1], ['person', PB]]);
$pendA = pa_pending($evA);
$pendB = pa_pending($evB);
pa_ok(
    'D1 set-up: A and B each have a rule, a series choice and a real waiting row, all made through FeedAdmin',
    $ruleA['ok'] === true && $ruleB['ok'] === true && $choiceA['ok'] === true && $choiceB['ok'] === true
    && $fa2Save['ok'] === true && $pendA !== null && $pendB !== null,
    pa_show([$ruleA, $ruleB, $choiceA, $choiceB, $fa2Save])
);

$calA = FeedAdmin::calendar($mysqli, FA1, ORG_A);
pa_ok(
    'D1 calendar(): B\'s number and 919999 -> null as A; A\'s own found, holding nothing of B\'s',
    FeedAdmin::calendar($mysqli, FB1, ORG_A) === null && FeedAdmin::calendar($mysqli, 919999, ORG_A) === null
    && $calA !== null && (int) $calA['waiting'] === 1 && pa_has($calA, B_MARK) === false
);
$calsA   = FeedAdmin::calendars($mysqli, ORG_A);
$calsIds = array_map(static fn (array $c): int => (int) $c['feedID'], $calsA);
sort($calsIds);
$fa2Row  = array_values(array_filter($calsA, static fn (array $c): bool => (int) $c['feedID'] === FA2))[0] ?? [];
pa_ok(
    'D1 calendars(A): exactly A\'s two calendars (FA2 with its own list), nothing of B\'s',
    $calsIds === [FA1, FA2] && pa_has($calsA, B_MARK) === false
    && array_map(static fn (array $m): string => $m['kind'] . ':' . $m['refID'], $fa2Row['list'] ?? []) === ['small_group:' . SG1],
    pa_show($calsIds)
);
$runsA = FeedAdmin::runs($mysqli, FA1, ORG_A);
pa_ok(
    'D1 runs(): B\'s calendar -> none as A; A\'s own has its one run, nothing of B\'s',
    FeedAdmin::runs($mysqli, FB1, ORG_A) === [] && count($runsA) === 1 && $runsA[0]['message'] === 'ALPHA run' && pa_has($runsA, B_MARK) === false
);
$evsAB = FeedAdmin::events($mysqli, FB1, ORG_A, 1);
pa_ok('D1 events(): B\'s calendar as A -> no rows AND a total of 0', $evsAB['rows'] === [] && $evsAB['total'] === 0, pa_show(['total' => $evsAB['total'], 'rows' => count($evsAB['rows'])]));
// One of A's events hand-edited to point at B's category (the forms and
// the map would never do this) — its category NAME must still never be
// B's word (fix round 2, checker round 2 LOW 3 / fault N17).
pa_q('UPDATE tblEvents SET categoryID = ? WHERE eventID = ?', 'ii', [CB1, $sA2]);
$evsA = FeedAdmin::events($mysqli, FA1, ORG_A, 1);
pa_ok(
    'D1 events(): A\'s own lists its three events, total 3, nothing of B\'s',
    count($evsA['rows']) === 3 && $evsA['total'] === 3 && pa_has($evsA, B_MARK) === false,
    pa_show(['total' => $evsA['total']])
);
$evRowsA = [];
foreach ($evsA['rows'] as $row) {
    $evRowsA[(int) $row['eventID']] = $row;
}
pa_ok(
    'D1 events(): an event pointing at B\'s category shows NO category name; A\'s own category is named',
    array_key_exists('categoryName', $evRowsA[$sA2] ?? []) === true && $evRowsA[$sA2]['categoryName'] === null
    && ($evRowsA[$evA]['categoryName'] ?? '') === 'Selftest CA1',
    pa_show(array_map(static fn (array $r): ?string => $r['categoryName'], $evRowsA))
);
$impA = FeedAdmin::importedEvent($mysqli, $evA, ORG_A);
pa_ok(
    'D1 importedEvent(): B\'s event and 919999 -> null as A; A\'s own found',
    FeedAdmin::importedEvent($mysqli, $evB, ORG_A) === null && FeedAdmin::importedEvent($mysqli, 919999, ORG_A) === null
    && $impA !== null && $impA['eventName'] === 'D1 ALPHA event'
);
$eventSA    = FeedAdmin::importedEvent($mysqli, $sA1, ORG_A);
$choiceForA = FeedAdmin::choiceFor($mysqli, $eventSA, 'series');
// A forged event array: B's calendar and identity, with A's organisation —
// what a page could only ever build by mistake. The SQL itself must still
// find nothing, which is what proves the organisation is in it.
$forgedB           = FeedAdmin::importedEvent($mysqli, $sB1, ORG_B) ?? [];
$forgedB['siteID'] = ORG_A;
pa_ok(
    'D1 choiceFor(): A\'s own series choice (not B\'s, though the identity is the same); B\'s calendar with A\'s organisation -> null',
    $choiceForA !== null && (int) $choiceForA['choiceID'] === (int) ($choiceA['choiceId'] ?? -1) && $choiceForA['note'] === 'ALPHA note'
    && $forgedB !== [] && FeedAdmin::choiceFor($mysqli, $forgedB, 'series') === null
);
$covA   = FeedAdmin::datesCovered($mysqli, $eventSA, 'series');
$covIds = array_column($covA, 'eventID');
sort($covIds);
pa_ok(
    'D1 datesCovered(): A\'s series gives exactly A\'s two dates (B\'s share the identity); B\'s calendar with A\'s organisation -> none',
    $covIds === [$sA1, $sA2] && pa_has($covA, B_MARK) === false && FeedAdmin::datesCovered($mysqli, $forgedB, 'series') === []
);
$rulesA = FeedAdmin::rules($mysqli, FA1, ORG_A);
pa_ok(
    'D1 rules(): B\'s calendar -> none as A; A\'s own has exactly its one rule',
    FeedAdmin::rules($mysqli, FB1, ORG_A) === [] && count($rulesA) === 1 && $rulesA[0]['name'] === 'D1 ALPHA rule'
);
pa_ok(
    'D1 rule(): B\'s rule -> null as A (by B\'s calendar or A\'s), 919999 -> null, A\'s own found',
    FeedAdmin::rule($mysqli, (int) $ruleB['ruleId'], FB1, ORG_A) === null && FeedAdmin::rule($mysqli, (int) $ruleB['ruleId'], FA1, ORG_A) === null
    && FeedAdmin::rule($mysqli, 919999, FA1, ORG_A) === null && FeedAdmin::rule($mysqli, (int) $ruleA['ruleId'], FA1, ORG_A) !== null
);
$tagsA = FeedAdmin::tags($mysqli, FA1, ORG_A);
pa_ok(
    'D1 tags(): B\'s calendar -> none as A (neither its event words nor its map); A\'s own is exactly its one word',
    FeedAdmin::tags($mysqli, FB1, ORG_A) === [] && array_column($tagsA, 'word') === ['alphaword'],
    pa_show(FeedAdmin::tags($mysqli, FB1, ORG_A))
);
$catsA   = FeedAdmin::categories($mysqli, ORG_A);
$catsIds = array_column($catsA, 'categoryID');
sort($catsIds);
pa_ok('D1 categories(A): exactly CA1 and CA2, nothing of B\'s', $catsIds === [CA1, CA2] && pa_has($catsA, B_MARK) === false);
$pA  = FeedAdmin::pendingApprovals($mysqli, ORG_A, null, 50);
$pAB = FeedAdmin::pendingApprovals($mysqli, ORG_A, FB1, 50);
pa_ok(
    'D1 pendingApprovals(A): only A\'s one waiting row, total 1, nothing of B\'s; asked for B\'s calendar -> none, total 0',
    count($pA['rows']) === 1 && (int) $pA['rows'][0]['approvalID'] === (int) $pendA['approvalID'] && $pA['total'] === 1
    && pa_has($pA, B_MARK) === false && $pAB['rows'] === [] && $pAB['total'] === 0,
    pa_show(['A' => $pA['total'], 'AB' => $pAB['total']])
);
pa_ok(
    'D1 approval(): B\'s row and 919999 -> null as A; A\'s own found',
    FeedAdmin::approval($mysqli, (int) $pendB['approvalID'], ORG_A) === null && FeedAdmin::approval($mysqli, 919999, ORG_A) === null
    && FeedAdmin::approval($mysqli, (int) $pendA['approvalID'], ORG_A) !== null
);
$optsA   = ExternalAudience::options($mysqli, ORG_A);
$peopleA = array_column($optsA['person'], 'userID');
sort($peopleA);
// Every current member of A — the former member E is not one (fix round 4
// added L, X, Q, O and F to the world, and E, whose membership ended).
$wantPeople = [M, SA, LA, P_ID, SGM, L_ID, X_ID, Q_ID, O_ID, F_ID, H_ID, R_ID, TS_ID, TY_ID, TE_ID, TT_ID, LD_ID, CL_ID, LO_ID, CO_ID, // + fix round 5's six, fix round 6's three and fix round 7's one
    RH_ID, UH_ID, DH_ID, XO_ID, DN_ID, XB_ID, // + part 10's six current members of A (XF is a former member, XI a switched-off account)
    KP_ID, KR_ID, KU_ID, KD_ID, KS_ID, KL_ID]; // + the six "one number, six kinds" members (see the constant SN)
sort($wantPeople);
pa_ok(
    'D1 options(A): A\'s switched-on groups, roles and members only — nothing of B\'s, not the switched-off SG2 or RA3, not the former members E and XF, not the switched-off accounts I and XI',
    // SN last in each: the small group and the leadership role that carry the
    // shared number are named "Selftest SN …", which sorts after the others.
    array_column($optsA['small_group'], 'groupID') === [SG1, SG3, SG4, SG5, SG6, SN] && array_column($optsA['leadership_role'], 'roleID') === [RA1, RA2, RA4, RA5, RA6, SN]
    && $peopleA === $wantPeople && pa_has($optsA, B_MARK) === false,
    pa_show([$peopleA, array_column($optsA['small_group'], 'groupID'), array_column($optsA['leadership_role'], 'roleID')])
);
// Part 10 of #514 (check R8's data): the three new kinds are offered, for
// THIS organisation only, and only what could be freshly added today — the
// retired user group UG2, the switched-off department DP2 and the department
// with an empty flag DP3 are left out, and so is everything of B's. Asked as
// B, the answer is B's three and none of A's. The keys come in the order the
// picker draws them.
$optsB = ExternalAudience::options($mysqli, ORG_B);
pa_ok(
    'D1 options(A), part 10: exactly A\'s two roles (RO1 and the one with the shared number), its two user groups in use and its two switched-on departments, by name and in name order — not UG2 (retired), DP2 (switched off), DP3 (empty flag) or anything of B\'s; as B, exactly ROB, UGB and DPB; six kinds in the picker\'s order',
    $optsA['role'] === [['roleID' => RO1, 'roleName' => 'Selftest RO1'], ['roleID' => SN, 'roleName' => 'Selftest SN role']]
    && $optsA['user_group'] === [['groupID' => SN, 'groupName' => 'Selftest SN user group'], ['groupID' => UG1, 'groupName' => 'Selftest UG1']]
    && $optsA['department'] === [['deptID' => DP1, 'deptName' => 'Selftest DP1'], ['deptID' => SN, 'deptName' => 'Selftest SN department']]
    && array_column($optsB['role'], 'roleID') === [ROB] && array_column($optsB['user_group'], 'groupID') === [UGB] && array_column($optsB['department'], 'deptID') === [DPB]
    && array_keys($optsA) === ['small_group', 'leadership_role', 'role', 'user_group', 'department', 'person']
    && array_keys($optsA) === ExternalAudience::OFFERED_KINDS,
    pa_show([$optsA['role'], $optsA['user_group'], $optsA['department'], array_keys($optsA)])
);
$bKey = ExternalAudience::ownerKey($fbGroups, 'feed', $fbGroups);
pa_ok(
    'D1 current()/currentMany(): B\'s list read as A -> empty; read as B -> its three entries (a control that it exists)',
    ExternalAudience::current($mysqli, ORG_A, $fbGroups, 'feed', $fbGroups) === []
    && ExternalAudience::currentMany($mysqli, ORG_A, [['feedID' => $fbGroups, 'ownerType' => 'feed', 'ownerID' => $fbGroups]]) === [$bKey => []]
    && count(ExternalAudience::current($mysqli, ORG_B, $fbGroups, 'feed', $fbGroups)) === 3
);
// A's OWN list naming B's group, role and person by number (a hand edit —
// the forms would refuse it): each must read "gone", never B's name.
pa_list(FA2, 'feed', FA2, [['small_group', SGB], ['leadership_role', RB1], ['person', PB]]);
$listA2 = [];
foreach (ExternalAudience::current($mysqli, ORG_A, FA2, 'feed', FA2) as $m) {
    $listA2[$m['kind'] . ':' . $m['refID']] = $m['state'] . '|' . $m['label'];
}
pa_ok(
    'D1 A\'s own list naming B\'s group, role and person: each "gone" with the plain words, never B\'s name',
    $listA2 === [
        'small_group:' . SG1     => 'ok|Selftest SG1',
        'small_group:' . SGB     => 'gone|a small group that no longer exists',
        'leadership_role:' . RB1 => 'gone|a leadership role that no longer exists',
        'person:' . PB           => 'gone|someone no longer in this organisation',
    ],
    pa_show($listA2)
);
// Numeric words come back as TEXT, and a word known only to the map is
// listed too (fix round 8, check round 8 L2 and L5 / fault C8-17). PHP
// turns an array key such as "2026" or "0" into a number; handed on as one,
// the house escaping (htmlspecialchars under strict_types) would throw.
pa_event(FA1, 'd1-num@p8', 'D1 ALPHA numbers', ['tags' => ['2026', '0']]);
pa_q('INSERT INTO tblExternalCategoryMap (siteID, feedID, externalCategory, categoryID) VALUES (?, ?, ?, ?)', 'iisi', [ORG_A, FA1, 'maponly', CA2]);
$tagsNum = FeedAdmin::tags($mysqli, FA1, ORG_A);
$byWord1 = [];
foreach ($tagsNum as $t) {
    $byWord1[$t['word']] = $t;
}
pa_ok(
    'D1 tags(): "0" and "2026" come back as TEXT (is_string), in order with the others; "maponly" — in the map, on no live event — is listed as mapped and not present',
    array_column($tagsNum, 'word') === ['0', '2026', 'alphaword', 'maponly']
    && array_filter(array_column($tagsNum, 'word'), static fn ($w): bool => is_string($w) === false) === []
    && ($byWord1['maponly']['mapped'] ?? null) === true && ($byWord1['maponly']['present'] ?? null) === false
    && ($byWord1['2026']['present'] ?? null) === true && ($byWord1['2026']['mapped'] ?? null) === false,
    pa_show($tagsNum)
);
// approval()'s "last superseded" row belongs to the SAME request only (fix
// round 8, check round 8 L5 / fault C8-20). Two superseded copies of A's
// waiting row are written by hand: an older one of the same request, and a
// NEWER one of a different request. The line must name the older one.
$copyAppr = static function (int $approvalId, string $requestHash, int $daysAgo): int {
    global $mysqli;
    $cols = 'siteID, feedID, eventID, origin, originID, contentHash, reason, requestedLevel, requestedDetail, requestedWebsite, '
        . 'requestedAudienceSummary, snapTitle, snapStart, snapEnd, snapTimezone, snapIsAllDay, snapCategoryID, snapDescription, snapLocation, snapUrl, createdAt';
    pa_q(
        "INSERT INTO tblExternalEventApprovals ({$cols}, requestHash, status, updatedAt) "
        . "SELECT {$cols}, ?, 'superseded', UTC_TIMESTAMP() - INTERVAL ? DAY FROM tblExternalEventApprovals WHERE approvalID = ?",
        'sii', [$requestHash, $daysAgo, $approvalId]
    );

    return (int) $mysqli->insert_id;
};
$sameReq1  = $copyAppr((int) $pendA['approvalID'], (string) $pendA['requestHash'], 2);
$otherReq1 = $copyAppr((int) $pendA['approvalID'], hash('sha256', 'a different request'), 1);
$appr1     = FeedAdmin::approval($mysqli, (int) $pendA['approvalID'], ORG_A);
pa_ok(
    'D1 approval(): "last superseded" is the older row of the SAME request, never the newer row of another request',
    (int) ($appr1['lastSuperseded']['approvalID'] ?? 0) === $sameReq1 && $otherReq1 > $sameReq1,
    pa_show([$sameReq1, $otherReq1, $appr1['lastSuperseded'] ?? null])
);

// =============================================================================
// D2 — writes are organisation-scoped
// =============================================================================
pa_heading('D2 — writes are organisation-scoped');
pa_resetWorld();
pa_q("UPDATE tblExternalFeeds SET audienceLevel = 'members' WHERE feedID = ?", 'i', [FB1]);
$evA   = pa_event(FA1, 'd2-event@p8', 'D2 event');
$evA2  = pa_event(FA1, 'd2-choice@p8', 'D2 choice event');
$evB   = pa_event(FB1, 'd2-b@p8', 'D2 BONLY event');
$rA    = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D2 rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D2 event']]]);
$rA2   = FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, ['name' => 'D2b rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D2b']]]);
$cA2   = FeedAdmin::saveChoice($mysqli, $evA2, 'date', ORG_A, SA, ['audienceLevel' => 'members']);
$rB    = FeedAdmin::saveRule($mysqli, FB1, 0, ORG_B, SB, ['name' => 'D2 BONLY rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D2 BONLY']]]);
$pendA = pa_pending($evA);
$pendB = pa_pending($evB);
pa_ok(
    'D2 set-up: A has a rule, a choice and a real waiting row; B has a real waiting row',
    $rA['ok'] === true && $rA2['ok'] === true && $cA2['ok'] === true && $rB['ok'] === true && $pendA !== null && $pendB !== null
);
$ruleIdA  = (int) $rA['ruleId'];
$postA    = pa_calPost(FA1, ['name' => 'renamed by B']);
$ruleForm = ['name' => 'x', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'x']]];
$fp2      = pa_fp();

// Each write, as B's administrator in B, with (1) A's number and (2) a
// number that does not exist: the SAME status and the SAME sentence.
$writesAsB = [
    'saveCalendar'    => [static fn (): array => FeedAdmin::saveCalendar($mysqli, FA1, ORG_B, SB, $postA),
                          static fn (): array => FeedAdmin::saveCalendar($mysqli, 919999, ORG_B, SB, $postA), MSG_NF_CAL],
    'saveCategoryMap' => [static fn (): array => FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_B, SB, ['map' => [['word' => 'x', 'choice' => 'none']]]),
                          static fn (): array => FeedAdmin::saveCategoryMap($mysqli, 919999, ORG_B, SB, ['map' => [['word' => 'x', 'choice' => 'none']]]), MSG_NF_CAL],
    'saveChoice'      => [static fn (): array => FeedAdmin::saveChoice($mysqli, $evA, 'date', ORG_B, SB, ['audienceLevel' => 'public']),
                          static fn (): array => FeedAdmin::saveChoice($mysqli, 919999, 'date', ORG_B, SB, ['audienceLevel' => 'public']), MSG_NF_EVENT],
    'removeChoice'    => [static fn (): array => FeedAdmin::removeChoice($mysqli, $evA2, 'date', ORG_B, SB),
                          static fn (): array => FeedAdmin::removeChoice($mysqli, 919999, 'date', ORG_B, SB), MSG_NF_EVENT],
    'saveRule'        => [static fn (): array => FeedAdmin::saveRule($mysqli, FA1, $ruleIdA, ORG_B, SB, $ruleForm),
                          static fn (): array => FeedAdmin::saveRule($mysqli, 919999, 0, ORG_B, SB, $ruleForm), MSG_NF_CAL],
    'deleteRule'      => [static fn (): array => FeedAdmin::deleteRule($mysqli, $ruleIdA, FA1, ORG_B, SB),
                          static fn (): array => FeedAdmin::deleteRule($mysqli, 1, 919999, ORG_B, SB), MSG_NF_CAL],
    'previewRule'     => [static fn (): array => FeedAdmin::previewRule($mysqli, FA1, $ruleIdA, ORG_B, SB, $ruleForm),
                          static fn (): array => FeedAdmin::previewRule($mysqli, 919999, 0, ORG_B, SB, $ruleForm), MSG_NF_CAL],
];
foreach ($writesAsB as $name => [$withA, $withMissing, $want]) {
    $a = $withA();
    $b = $withMissing();
    pa_ok(
        "D2 {$name}() as B with A's number and with 919999: status not_found and exactly \"{$want}\" both times",
        $a['status'] === 'not_found' && $a['message'] === $want && $b['status'] === 'not_found' && $b['message'] === $want,
        pa_show([$a, $b])
    );
}
$dA = FeedAdmin::decide($mysqli, ORG_B, SB, 'approved', '', [pa_item($pendA)]);
$dM = FeedAdmin::decide($mysqli, ORG_B, SB, 'approved', '', [['approvalID' => 999999999, 'requestHash' => str_repeat('a', 64), 'contentHash' => str_repeat('b', 64)]]);
$wantChanged = 'Approved 0. 1 had changed since you opened the page; please review it again.';
pa_ok(
    'D2 decide() as B on A\'s waiting row and on a missing one: nothing decided, counted "changed", the same sentence both times',
    $dA['decided'] === 0 && $dA['changed'] === 1 && $dA['message'] === $wantChanged && $dM['decided'] === 0 && $dM['changed'] === 1 && $dM['message'] === $wantChanged,
    pa_show([$dA, $dM])
);
$r8 = FeedAdmin::previewRule($mysqli, FA1, (int) $rA2['ruleId'], ORG_A, SA, $ruleForm);
pa_ok('D2 previewRule(FA1, a rule of FA2 — same organisation) -> not_found, "That rule could not be found." (challenge L15)', $r8['status'] === 'not_found' && $r8['message'] === MSG_NF_RULE, pa_show($r8));
pa_ok('D2 nothing at all was written by any of the above', pa_fp() === $fp2);

// 🔒 Checker M1: with A's calendar LOCKED by another connection (a refresh
//    in progress), B's call with A's number must answer "not found" AT
//    ONCE — never queue behind A's lock. The lock wait is set to 2 seconds
//    on the test's own connection, so a regression fails in 2 seconds
//    (a lock-wait error, or a slow answer) instead of hanging.
//    The same for A deciding B's waiting row while B's calendar is locked:
//    "changed" at once, and never "could not be decided".
//    A CONTROL proves the lock really is held: A's own write to A's
//    calendar in the same window MUST wait and fail.
$lockResults = pa_whileLocked([FA1, FB1], 2, static function () use ($writesAsB, $mysqli, $pendA, $pendB, $evA2): array {
    $out = [];
    foreach ($writesAsB as $name => [$withA]) {
        $out[$name] = pa_timed($withA);
    }
    $out['decide(A item as B)'] = pa_timed(static fn (): array => FeedAdmin::decide($mysqli, ORG_B, SB, 'approved', '', [pa_item($pendA)]));
    $out['decide(B item as A)'] = pa_timed(static fn (): array => FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($pendB)]));
    $out['control'] = pa_timed(static fn (): array => FeedAdmin::saveChoice($mysqli, $evA2, 'date', ORG_A, SA, ['audienceLevel' => 'hidden']));

    return $out;
});
foreach ($writesAsB as $name => [, , $want]) {
    $t = $lockResults[$name];
    pa_ok(
        "D2 {$name}() as B with A's number while A's calendar is locked: \"{$want}\" at once (no lock wait)",
        $t['threw'] === null && $t['seconds'] < 1.5 && ($t['result']['status'] ?? '') === 'not_found' && ($t['result']['message'] ?? '') === $want,
        ($t['threw'] !== null ? get_class($t['threw']) . ': ' . $t['threw']->getMessage() : pa_show($t['result'])) . sprintf(' (%.2fs)', $t['seconds'])
    );
}
foreach (['decide(A item as B)', 'decide(B item as A)'] as $name) {
    $t = $lockResults[$name];
    pa_ok(
        "D2 {$name} while the row's calendar is locked: \"changed\" at once, nothing left undone",
        $t['threw'] === null && $t['seconds'] < 1.5 && ($t['result']['changed'] ?? -1) === 1 && ($t['result']['notDone'] ?? -1) === 0 && ($t['result']['decided'] ?? -1) === 0,
        ($t['threw'] !== null ? get_class($t['threw']) . ': ' . $t['threw']->getMessage() : pa_show($t['result'])) . sprintf(' (%.2fs)', $t['seconds'])
    );
}
pa_ok(
    'D2 control: in the same window, A\'s OWN write to A\'s locked calendar does wait and fail — so the lock was really held',
    $lockResults['control']['threw'] instanceof mysqli_sql_exception && $lockResults['control']['seconds'] >= 1.5,
    pa_show($lockResults['control']['result']) . sprintf(' (%.2fs)', $lockResults['control']['seconds'])
);
pa_ok('D2 nothing was written while the calendars were locked, either', pa_fp() === $fp2 && pa_status((int) $pendB['approvalID']) === 'pending');

// =============================================================================
// D3 — adding a calendar
// =============================================================================
pa_heading('D3 — adding a calendar');
pa_resetWorld();
$r = FeedAdmin::addCalendar($mysqli, ORG_A, SA, [
    'name' => 'D3 calendar', 'url' => 'https://1.2.3.4/d3.ics', 'fetchEveryMins' => 60, 'timezone' => 'Europe/Paris',
    'categoryID' => (string) CA1, 'audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]], 'websiteOptIn' => '1', 'apiOptOut' => '1',
]);
$fid3 = (int) ($r['feedId'] ?? 0);
$row3 = pa_one('SELECT * FROM tblExternalFeeds WHERE feedID = ?', 'i', [$fid3]);
pa_ok(
    'D3 a valid new calendar: level, zone, category, API box and creator stored; the website box dropped at groups; next refresh within 5s of the database\'s now; its list saved',
    $r['ok'] === true && $r['message'] === 'Calendar added.' && $row3 !== null && (int) $row3['siteID'] === ORG_A
    && $row3['audienceLevel'] === 'groups' && $row3['timezone'] === 'Europe/Paris' && (int) $row3['categoryID'] === CA1
    && (int) $row3['websiteOptIn'] === 0 && (int) $row3['apiOptOut'] === 1 && (int) $row3['createdByID'] === SA && (int) $row3['isActive'] === 1
    && $row3['url'] === 'https://1.2.3.4/d3.ics'
    && pa_nearDbNow('tblExternalFeeds', 'nextFetchAt', 'feedID = ?', 'i', [$fid3])
    && pa_nearDbNow('tblExternalFeeds', 'createdAt', 'feedID = ?', 'i', [$fid3])
    && array_map(static fn (array $m): string => $m['kind'] . ':' . $m['refID'], FeedAdmin::calendar($mysqli, $fid3, ORG_A)['list'] ?? []) === ['small_group:' . SG1],
    pa_show($r)
);
// A list posted with it is NOT saved at any level but Selected groups (fix
// round 8, check round 8 L5 / fault C8-19): a list can name people, so
// none may be kept that nothing uses.
$r3b = FeedAdmin::addCalendar($mysqli, ORG_A, SA, ['name' => 'D3 public', 'url' => 'WEBCAL://1.2.3.4/d3b.ics#frag', 'fetchEveryMins' => 60, 'timezone' => '', 'audienceLevel' => 'public', 'websiteOptIn' => '1', 'audience' => ['small_group' => [SG1], 'person' => [P_ID]]]);
$row3b = pa_one('SELECT websiteOptIn, url, timezone FROM tblExternalFeeds WHERE feedID = ?', 'i', [(int) ($r3b['feedId'] ?? 0)]);
pa_ok(
    'D3 at Everyone the website box IS stored (the control for the one above); the address is stored in its tidied form; the list posted with it is not saved',
    $r3b['ok'] === true && $row3b !== null && (int) $row3b['websiteOptIn'] === 1 && $row3b['url'] === 'https://1.2.3.4/d3b.ics' && $row3b['timezone'] === null
    && (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE ownerType = 'feed' AND ownerID = ?", 'i', [(int) ($r3b['feedId'] ?? 0)])['n'] === 0,
    pa_show([$r3b, $row3b])
);

$countBefore3 = (int) pa_one('SELECT COUNT(*) AS n FROM tblExternalFeeds WHERE siteID = ?', 'i', [ORG_A])['n'];
$fp3          = pa_fp();
pa_q('UPDATE tblEventCategories SET isActive = 0 WHERE categoryID = ?', 'i', [CA2]); // a RETIRED category of A, for the LOW 3 case
$ok3   = ['name' => 'x', 'url' => 'https://1.2.3.4/x.ics', 'fetchEveryMins' => 60, 'timezone' => '', 'audienceLevel' => 'public'];
$cases3 = [
    'an empty name'                        => [['name' => '   '] + $ok3, 'Please give the calendar a name.'],
    'a name of only zero-width spaces'     => [['name' => "\u{200B}\u{200B}"] + $ok3, 'Please give the calendar a name.'],
    'a name that is not valid text'        => [['name' => "Caf\xC3"] + $ok3, 'The name contains characters that cannot be read. Please type it again.'],
    'an address in a private network'      => [['url' => 'https://x.internal/a.ics'] + $ok3, SafeFetch::REFUSED_MESSAGE],
    'an address of 2001 characters'        => [['url' => 'https://1.2.3.4/' . str_repeat('a', 2001 - 16)] + $ok3, 'Address can be at most 2000 characters.'],
    '14 minutes'                           => [['fetchEveryMins' => 14] + $ok3, 'Choose between 15 minutes and 7 days (10080 minutes).'],
    '10081 minutes'                        => [['fetchEveryMins' => 10081] + $ok3, 'Choose between 15 minutes and 7 days (10080 minutes).'],
    'the zone Mars/Base'                   => [['timezone' => 'Mars/Base'] + $ok3, 'Choose a time zone from the list.'],
    'B\'s category CB1'                    => [['categoryID' => (string) CB1] + $ok3, 'Choose a category from the list.'],
    'a retired category of A, newly chosen' => [['categoryID' => (string) CA2] + $ok3, 'Choose a category from the list.'],
    'Selected groups with nobody chosen'   => [['audienceLevel' => 'groups'] + $ok3, 'Choose at least one group or person.'],
    'the level "hidden"'                   => [['audienceLevel' => 'hidden'] + $ok3, 'Choose who can see its events.'],
    'B\'s small group on the list'         => [['audienceLevel' => 'groups', 'audience' => ['small_group' => [SGB]]] + $ok3, ExternalAudience::REFUSED_MESSAGE],
    // Part 10 of #514: the three new kinds reach the same refusal through a
    // real save, each posted beside one of A's own that would be accepted.
    'B\'s role on the list (part 10)'        => [['audienceLevel' => 'groups', 'audience' => ['role' => [RO1, ROB]]] + $ok3, ExternalAudience::REFUSED_MESSAGE],
    'B\'s user group on the list (part 10)'  => [['audienceLevel' => 'groups', 'audience' => ['user_group' => [UG1, UGB]]] + $ok3, ExternalAudience::REFUSED_MESSAGE],
    'B\'s department on the list (part 10)'  => [['audienceLevel' => 'groups', 'audience' => ['department' => [DP1, DPB]]] + $ok3, ExternalAudience::REFUSED_MESSAGE],
];
foreach ($cases3 as $label => [$post3, $want3]) {
    $r3 = FeedAdmin::addCalendar($mysqli, ORG_A, SA, $post3);
    pa_ok("D3 refused: {$label} -> \"{$want3}\"", $r3['ok'] === false && $r3['status'] === 'refused' && $r3['message'] === $want3, pa_show($r3));
}
pa_q('UPDATE tblEventCategories SET isActive = 1 WHERE categoryID = ?', 'i', [CA2]);
pa_ok(
    'D3 none of the refused cases added a calendar or changed anything',
    (int) pa_one('SELECT COUNT(*) AS n FROM tblExternalFeeds WHERE siteID = ?', 'i', [ORG_A])['n'] === $countBefore3 && pa_fp() === $fp3
);
// A calendar that STOPS being Selected groups loses its list rows — they
// can name people, and nothing would use them (fix round 8, check round 8
// L5 / fault C8-18).
$r3d = FeedAdmin::saveCalendar($mysqli, $fid3, ORG_A, SA, pa_calPost($fid3, ['audienceLevel' => 'members']));
pa_ok(
    'D3 the groups calendar changed to Members: saved, and its list rows are gone',
    $r3d['ok'] === true && (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE ownerType = 'feed' AND ownerID = ?", 'i', [$fid3])['n'] === 0,
    pa_show($r3d)
);

// =============================================================================
// D4 — changing a calendar resolves in the same call
// =============================================================================
pa_heading('D4 — changing a calendar resolves in the same call');
pa_resetWorld();
$ev4 = pa_event(FA1, 'd4@p8', 'D4 event');
$r4  = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'public', 'websiteOptIn' => '1']));
pa_ok(
    'D4 Members -> Everyone with the website box: resolved in the same call — the event is public with the website on when the method returns',
    $r4['ok'] === true && $r4['message'] === 'Saved.' && pa_ev($ev4)['importLevel'] === 'public' && (int) pa_ev($ev4)['importWebsite'] === 1
    && (int) pa_one('SELECT websiteOptIn AS w FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['w'] === 1,
    pa_show($r4)
);
$r4b = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'members', 'websiteOptIn' => '1']));
pa_ok(
    'D4 the website box posted TICKED at Members is stored 0, and the event follows (members, no website)',
    $r4b['ok'] === true && (int) pa_one('SELECT websiteOptIn AS w FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['w'] === 0
    && pa_ev($ev4)['importLevel'] === 'members' && (int) pa_ev($ev4)['importWebsite'] === 0,
    pa_show($r4b)
);
pa_ok(
    'D4 the calendar\'s updatedAt is within 5 s of the database\'s UTC now and updatedByID is SA (fix round 3; the session runs 13 hours ahead)',
    pa_nearDbNow('tblExternalFeeds', 'updatedAt', 'feedID = ?', 'i', [FA1])
    && (int) pa_one('SELECT updatedByID AS u FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['u'] === SA
);

// =============================================================================
// D5 — the API box (C10 item 3)
// =============================================================================
pa_heading('D5 — the API box');
pa_resetWorld();
$ev51 = pa_event(FA2, 'd5-1@p8', 'D5 event 1');
$ev52 = pa_event(FA2, 'd5-2@p8', 'D5 event 2');
$r5   = FeedAdmin::saveCalendar($mysqli, FA2, ORG_A, SA, pa_calPost(FA2, ['apiOptOut' => '1', 'websiteOptIn' => '1']));
pa_ok('D5 ticking the API box: every live event importApiOptOut = 1', $r5['ok'] === true && (int) pa_ev($ev51)['importApiOptOut'] === 1 && (int) pa_ev($ev52)['importApiOptOut'] === 1, pa_show($r5));
pa_ok(
    'D5 audienceLabel(): exactly "Everyone · also on the public website · not sent to API keys"',
    FeedAdmin::audienceLabel(FeedAdmin::calendar($mysqli, FA2, ORG_A)) === 'Everyone · also on the public website · not sent to API keys',
    FeedAdmin::audienceLabel(FeedAdmin::calendar($mysqli, FA2, ORG_A))
);
$r5b = FeedAdmin::saveCalendar($mysqli, FA2, ORG_A, SA, pa_calPost(FA2, ['apiOptOut' => '0']));
pa_ok('D5 clearing the API box: every live event importApiOptOut = 0', $r5b['ok'] === true && (int) pa_ev($ev51)['importApiOptOut'] === 0 && (int) pa_ev($ev52)['importApiOptOut'] === 0);
$r5c = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [P_ID]]]));
pa_ok(
    'D5 audienceLabel(): "Members" for members; for Selected groups, the counts-only summary (no names)',
    $r5c['ok'] === true && FeedAdmin::audienceLabel(['audienceLevel' => 'members', 'apiOptOut' => 0]) === 'Members'
    && FeedAdmin::audienceLabel(FeedAdmin::calendar($mysqli, FA1, ORG_A)) === 'Selected groups: 1 small group, 1 named person',
    FeedAdmin::audienceLabel(FeedAdmin::calendar($mysqli, FA1, ORG_A))
);

// =============================================================================
// D6 — a new address (C10 item 8), and an unchanged one (checker M4, LOW 2)
// =============================================================================
pa_heading('D6 — a new address');
pa_resetWorld();
$evSeen    = pa_event(FA1, 'd6-seen@p8', 'D6 seen event', ['day' => 3]);
$evWaiting = pa_event(FA1, 'd6-waiting@p8', 'D6 waiting event', ['day' => 5]);
$rSave6    = FeedAdmin::saveChoice($mysqli, $evSeen, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [$evSeen . ':' . pa_cf($evSeen)]]);
$rRule6    = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D6 rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D6 waiting']]]);
pa_ok(
    'D6 set-up: one date approved through a real choice save, one waiting through a real rule',
    $rSave6['ok'] === true && pa_ls($evSeen) === 'public/date' && $rRule6['ok'] === true && pa_ev($evWaiting)['importSource'] === 'waiting',
    pa_ls($evSeen) . ' ' . pa_ls($evWaiting)
);
// The database's clock is pinned three days back for the save, so the
// copy's date can only be the pinned moment if the DATABASE's clock was
// handed to onAddressChanged() (C10 item 2; checker LOW 6 / fault F30).
$pinned6 = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-3 days');
$mysqli->query('SET timestamp = ' . $pinned6->getTimestamp());
try {
    $back6  = (string) (pa_one('SELECT UTC_TIMESTAMP() AS t')['t'] ?? '');
    $r6     = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['url' => 'http://5.6.7.8/new.ics']));
    $feed6  = pa_one('SELECT lastContentHash, url, (nextFetchAt <= UTC_TIMESTAMP()) AS due FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1]);
} finally {
    $mysqli->query('SET timestamp = DEFAULT');
}
$want6 = $pinned6->format('Y-m-d H:i:s');
$copy6 = array_values(array_filter(pa_apprWithStatus($evSeen, 'pending'), static fn (array $a): bool => $a['reason'] === 'address_changed'));
pa_ok('D6 the database clock pin took', $back6 === $want6, "asked {$want6}, read back {$back6}");
pa_ok(
    'D6 a new address: sentBack=1, the approved row superseded, a pending copy (address_changed), lastContentHash cleared, due now, refreshNeeded, the exact sentence',
    $r6['ok'] === true && $r6['sentBack'] === 1 && $r6['refreshNeeded'] === true
    && $r6['message'] === 'Saved. 1 widened event went back for approval because the address changed.'
    && $feed6['lastContentHash'] === null && (int) $feed6['due'] === 1 && $feed6['url'] === 'http://5.6.7.8/new.ics'
    && count(pa_apprWithStatus($evSeen, 'superseded')) === 1 && count($copy6) === 1,
    pa_show([$r6, $feed6])
);
pa_ok('D6 the copy is dated with the DATABASE\'s clock (the pinned moment), not PHP\'s', ($copy6[0]['createdAt'] ?? '') === $want6, (string) ($copy6[0]['createdAt'] ?? 'none'));

pa_resetWorld();
pa_q("UPDATE tblExternalFeeds SET lastContentHash = SHA2('x', 256) WHERE feedID = ?", 'i', [FA1]);
$r6b = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['timezone' => 'Europe/Paris']));
pa_ok(
    'D6 only the zone changed: lastContentHash cleared, refreshNeeded true, sentBack 0',
    $r6b['ok'] === true && $r6b['sentBack'] === 0 && $r6b['refreshNeeded'] === true
    && pa_one('SELECT lastContentHash AS h FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['h'] === null,
    pa_show($r6b)
);
pa_resetWorld();
pa_q("UPDATE tblExternalFeeds SET lastContentHash = SHA2('x', 256) WHERE feedID = ?", 'i', [FA1]);
$r6c = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['name' => 'FA1 renamed']));
pa_ok(
    'D6 only the name changed: lastContentHash untouched, refreshNeeded false, "Saved."',
    $r6c['ok'] === true && $r6c['refreshNeeded'] === false && $r6c['message'] === 'Saved.'
    && pa_one('SELECT lastContentHash AS h FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['h'] !== null,
    pa_show($r6c)
);

// Checker M4, half 1: an UNCHANGED stored address that the address check
// would now refuse must not block an unrelated save — here narrowing
// Everyone to Members. (`.internal` is refused by SafeFetch before any
// look-up, so a re-check could never pass.)
pa_resetWorld();
$badUrl = 'https://calendar.p8-selftest.internal/x.ics';
pa_q("UPDATE tblExternalFeeds SET audienceLevel = 'public', url = ? WHERE feedID = ?", 'si', [$badUrl, FA1]);
$ev6d = pa_event(FA1, 'd6d@p8', 'D6d event');
pa_q("UPDATE tblEvents SET importLevel = 'public' WHERE eventID = ?", 'i', [$ev6d]);
$r6d = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'members']));
pa_ok(
    'D6 an unchanged address the check would now refuse does not block narrowing Everyone -> Members (address kept byte for byte)',
    $r6d['ok'] === true && $r6d['refreshNeeded'] === false && pa_ev($ev6d)['importLevel'] === 'members'
    && pa_one('SELECT url FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['url'] === $badUrl,
    pa_show($r6d)
);
// Checker M4, half 2: a CHANGED address is still checked.
$fp6e = pa_fp();
$r6e  = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['url' => 'https://other.p8-selftest.internal/y.ics']));
pa_ok(
    'D6 a CHANGED address is still checked: a private-network one is refused with SafeFetch\'s sentence, nothing written',
    $r6e['ok'] === false && $r6e['status'] === 'refused' && $r6e['message'] === SafeFetch::REFUSED_MESSAGE && pa_fp() === $fp6e,
    pa_show($r6e)
);
// Checker LOW 2: an address stored by the released feeds-save.php exactly
// as typed (with a "#…" part the tidying drops). A rename posting it back
// exactly — or in another spelling of the SAME address — is not an address
// change: nothing goes back for approval, and the stored text is kept.
pa_resetWorld();
$legacy = 'https://1.2.3.4/legacy.ics#section';
pa_q("UPDATE tblExternalFeeds SET url = ?, lastContentHash = SHA2('x', 256) WHERE feedID = ?", 'si', [$legacy, FA1]);
$ev6f  = pa_event(FA1, 'd6f@p8', 'D6f event');
$rS6f  = FeedAdmin::saveChoice($mysqli, $ev6f, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [$ev6f . ':' . pa_cf($ev6f)]]);
$r6f   = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['name' => 'FA1 renamed only']));
$r6g   = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['url' => 'HTTPS://1.2.3.4/legacy.ics']));
pa_ok(
    'D6 an old-style stored address posted back (exactly, or as another spelling of it): no address change — sentBack 0, no refresh forced, the widened date still shown, the stored text kept',
    $rS6f['ok'] === true && $r6f['ok'] === true && $r6f['sentBack'] === 0 && $r6f['refreshNeeded'] === false
    && $r6g['ok'] === true && $r6g['sentBack'] === 0 && $r6g['refreshNeeded'] === false
    && pa_ls($ev6f) === 'public/date' && pa_one('SELECT url FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['url'] === $legacy,
    pa_show([$r6f, $r6g, pa_ls($ev6f)])
);

// Two administrators at once (fix round 2, checker round 2 LOW 8): a
// separate process locks FA1 as a refresh would, and — while this save is
// between its first read and its lock — changes FA1's address. This save
// posted the address it had READ (so it was never checked); under the lock
// it now differs from what is stored, so the save must refuse and write
// nothing of its own — not put back an address nobody checked.
pa_resetWorld();
$orig6h = pa_one('SELECT url, name FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1]);
$race6h = pa_withHolder(FA1, 2.0, 'seturl:https://5.6.7.8/other.ics', static fn (): array => FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['name' => 'D6h renamed during the race'])));
$row6h  = pa_one('SELECT url, name FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1]);
pa_ok(
    'D6 someone else changes the address while this save waits for the lock: refused, word for word, and nothing of this save written (checker round 2, LOW 8)',
    $race6h['holderOk'] === true && ($race6h['result']['ok'] ?? true) === false
    && ($race6h['result']['message'] ?? '') === "Someone else changed this calendar's address while you were editing it. Please open it again."
    && $row6h['name'] === $orig6h['name'] && $row6h['url'] === 'https://5.6.7.8/other.ics',
    pa_show([$race6h['result'], $row6h, $race6h['holderLog']])
);
// The same race, but this save posts a genuinely DIFFERENT address, which
// it checked: that address wins, and it is the one stored.
pa_resetWorld();
$race6i = pa_withHolder(FA1, 2.0, 'seturl:https://5.6.7.8/other.ics', static fn (): array => FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['url' => 'https://9.9.9.9/mine.ics', 'name' => 'D6i mine'])));
$row6i  = pa_one('SELECT url, name FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1]);
pa_ok(
    'D6 the same race with a genuinely different, checked address posted: this save wins, and the stored address is the checked one',
    $race6i['holderOk'] === true && ($race6i['result']['ok'] ?? false) === true && ($race6i['result']['refreshNeeded'] ?? false) === true
    && $row6i['url'] === 'https://9.9.9.9/mine.ics' && $row6i['name'] === 'D6i mine',
    pa_show([$race6i['result'], $row6i, $race6i['holderLog']])
);

// =============================================================================
// D7 — lists (ExternalAudience::fromPost / save)
// =============================================================================
pa_heading('D7 — lists');
pa_resetWorld();
// A "gone" person: SGM's only membership row moves to organisation B.
pa_q('UPDATE tblUserSites SET siteID = ? WHERE userID = ? AND siteID = ?', 'iii', [ORG_B, SGM, ORG_A]);
$cases7 = [
    'SGB (another organisation\'s group)'          => [['audience' => ['small_group' => [SGB]]], []],
    'RB1 (another organisation\'s role)'           => [['audience' => ['leadership_role' => [RB1]]], []],
    'PB (a member of another organisation only)'   => [['audience' => ['person' => [PB]]], []],
    'an account with no membership at all'          => [['audience' => ['person' => [999999]]], []],
    'a NEW switched-off group SG2'                  => [['audience' => ['small_group' => [SG2]]], []],
    // Until part 10 of #514 this case posted the kind "role", which no form
    // offered. Roles are offered now, so the case posts a kind that exists
    // nowhere — and the role cases are below.
    'a kind that does not exist ("household")'      => [['audience' => ['household' => [1]]], []],
    'a value that is not a whole number'            => [['audience' => ['person' => ['12abc']]], []],
    // Part 10 of #514, check R6: the three new kinds are held to the same
    // rules as the old ones. Another organisation's is refused, a number
    // that names nothing is refused, and one that is retired or switched off
    // (or whose on/off flag is empty) cannot be freshly ADDED.
    'ROB (another organisation\'s role)'            => [['audience' => ['role' => [ROB]]], []],
    'UGB (another organisation\'s user group)'      => [['audience' => ['user_group' => [UGB]]], []],
    'DPB (another organisation\'s department)'      => [['audience' => ['department' => [DPB]]], []],
    'a role number that names nothing'              => [['audience' => ['role' => [999999]]], []],
    'a role of A posted together with a role of B'  => [['audience' => ['role' => [RO1, ROB]]], []],
    'a NEW retired user group UG2'                  => [['audience' => ['user_group' => [UG2]]], []],
    'a NEW switched-off department DP2'             => [['audience' => ['department' => [DP2]]], []],
    'a NEW department whose on/off flag is empty, DP3' => [['audience' => ['department' => [DP3]]], []],
    'a small group\'s number posted as a user group' => [['audience' => ['user_group' => [SG1]]], []],
    'a leadership role\'s number posted as a role'   => [['audience' => ['role' => [RA1]]], []],
    'a GONE person already on the list, posted back' => [['audience' => ['person' => [SGM]]], [['kind' => 'person', 'refID' => SGM, 'label' => 'someone no longer in this organisation', 'state' => 'gone']]],
    // Fix round 2, checker round 2 LOW 1: the same for a GROUP and a ROLE
    // of another organisation that are already on the list (a hand edit,
    // or an import) — kept, their members would start seeing A's events.
    'another organisation\'s group already on the list, posted back' => [['audience' => ['small_group' => [SGB]]], [['kind' => 'small_group', 'refID' => SGB, 'label' => 'a small group that no longer exists', 'state' => 'gone']]],
    'another organisation\'s role already on the list, posted back' => [['audience' => ['leadership_role' => [RB1]]], [['kind' => 'leadership_role', 'refID' => RB1, 'label' => 'a leadership role that no longer exists', 'state' => 'gone']]],
    // Until part 10 this case was "a not_offered row already on the list,
    // posted back" (a department, number 1). A department is offered now;
    // one that is not this organisation's is "gone", and posting it back is
    // still refused — for each of the three new kinds.
    'another organisation\'s role already on the list, posted back (part 10)' => [['audience' => ['role' => [ROB]]], [['kind' => 'role', 'refID' => ROB, 'label' => 'a role that no longer exists', 'state' => 'gone']]],
    'another organisation\'s user group already on the list, posted back (part 10)' => [['audience' => ['user_group' => [UGB]]], [['kind' => 'user_group', 'refID' => UGB, 'label' => 'a user group that no longer exists', 'state' => 'gone']]],
    'another organisation\'s department already on the list, posted back (part 10)' => [['audience' => ['department' => [DPB]]], [['kind' => 'department', 'refID' => DPB, 'label' => 'a department that no longer exists', 'state' => 'gone']]],
    'a row of a kind the forms do not offer, already on the list, posted back' => [['audience' => ['household' => [1]]], [['kind' => 'household', 'refID' => 1, 'label' => 'an entry #1', 'state' => 'not_offered']]],
];
foreach ($cases7 as $label => [$post7, $current7]) {
    $r7 = ExternalAudience::fromPost($mysqli, ORG_A, $post7, $current7);
    pa_ok("D7 refused with the one sentence: {$label}", $r7['ok'] === false && $r7['message'] === ExternalAudience::REFUSED_MESSAGE && $r7['members'] === [], pa_show($r7));
}
$r7a = ExternalAudience::fromPost($mysqli, ORG_A, ['audience' => ['small_group' => [SG2]]], [['kind' => 'small_group', 'refID' => SG2, 'label' => 'x', 'state' => 'switched_off']]);
pa_ok('D7 kept: SG2 already on the list, though switched off', $r7a['ok'] === true && $r7a['members'] === [['kind' => 'small_group', 'refID' => SG2]], pa_show($r7a));
pa_q('UPDATE tblUserSites SET isActive = 0 WHERE userID = ? AND siteID = ?', 'ii', [P_ID, ORG_A]);
$r7b = ExternalAudience::fromPost($mysqli, ORG_A, ['audience' => ['person' => [P_ID]]], [['kind' => 'person', 'refID' => P_ID, 'label' => 'x', 'state' => 'left']]);
pa_ok('D7 kept: P already on the list after their membership was switched off', $r7b['ok'] === true && $r7b['members'] === [['kind' => 'person', 'refID' => P_ID]], pa_show($r7b));
pa_q('UPDATE tblUserSites SET isActive = 1 WHERE userID = ? AND siteID = ?', 'ii', [P_ID, ORG_A]);
// Part 10 of #514, check R6 — what IS accepted. Each new kind for this
// organisation; and a retired user group, a switched-off department and one
// with an empty flag when they are ALREADY on the list (they stay until an
// administrator takes them off, exactly as SG2 does above).
$r7p = ExternalAudience::fromPost($mysqli, ORG_A, ['audience' => ['role' => [RO1], 'user_group' => [UG1], 'department' => [DP1]]], []);
pa_ok(
    'D7 accepted (part 10): A\'s role RO1, user group UG1 and department DP1, each freshly added',
    $r7p['ok'] === true && $r7p['message'] === ''
    && $r7p['members'] === [['kind' => 'role', 'refID' => RO1], ['kind' => 'user_group', 'refID' => UG1], ['kind' => 'department', 'refID' => DP1]],
    pa_show($r7p)
);
$r7q = ExternalAudience::fromPost($mysqli, ORG_A, ['audience' => ['user_group' => [UG2], 'department' => [DP2, DP3]]], [
    ['kind' => 'user_group', 'refID' => UG2, 'label' => 'x', 'state' => 'switched_off'],
    ['kind' => 'department', 'refID' => DP2, 'label' => 'x', 'state' => 'switched_off'],
    ['kind' => 'department', 'refID' => DP3, 'label' => 'x', 'state' => 'switched_off'],
]);
pa_ok(
    'D7 kept (part 10): the retired UG2, the switched-off DP2 and DP3 (empty flag), each already on the list',
    $r7q['ok'] === true
    && $r7q['members'] === [['kind' => 'user_group', 'refID' => UG2], ['kind' => 'department', 'refID' => DP2], ['kind' => 'department', 'refID' => DP3]],
    pa_show($r7q)
);
// …and "already on the list" is judged kind by kind: UG2 on the list as a
// USER GROUP does not let the same number through as a department.
$r7r = ExternalAudience::fromPost($mysqli, ORG_A, ['audience' => ['department' => [UG2]]], [['kind' => 'user_group', 'refID' => UG2, 'label' => 'x', 'state' => 'switched_off']]);
pa_ok('D7 refused (part 10): a number that is on the list as a user group, posted as a department', $r7r['ok'] === false && $r7r['message'] === ExternalAudience::REFUSED_MESSAGE && $r7r['members'] === [], pa_show($r7r));
$many7 = [];
for ($i = 0; $i < 51; $i++) {
    $gid = 910900 + $i;
    pa_q("INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, isActive) VALUES (?, ?, 'X', ?, 1)", 'iis', [$gid, ORG_A, 'x-' . $gid]);
    $many7[] = $gid;
}
$r7c = ExternalAudience::fromPost($mysqli, ORG_A, ['audience' => ['small_group' => $many7]], []);
pa_ok('D7 51 entries -> exactly "A list can name at most 50 groups and people."', $r7c['ok'] === false && $r7c['message'] === 'A list can name at most 50 groups and people.', pa_show($r7c));
ExternalAudience::save($mysqli, ORG_A, FA1, 'feed', FA1, [['kind' => 'small_group', 'refID' => SG1], ['kind' => 'person', 'refID' => P_ID]], SA);
ExternalAudience::save($mysqli, ORG_A, FA1, 'feed', FA1, [['kind' => 'person', 'refID' => P_ID]], SA);
$saved7 = pa_q('SELECT kind, refID, userID, createdByID FROM tblExternalAudienceMembers WHERE feedID = ? AND ownerType = ? AND ownerID = ?', 'isi', [FA1, 'feed', FA1]);
pa_ok(
    'D7 save() REPLACES the owner\'s rows; a person row carries userID and createdByID; createdAt within 5s of the database\'s now',
    count($saved7) === 1 && $saved7[0]['kind'] === 'person' && (int) $saved7[0]['userID'] === P_ID && (int) $saved7[0]['createdByID'] === SA
    && pa_nearDbNow('tblExternalAudienceMembers', 'createdAt', 'feedID = ? AND ownerType = ? AND ownerID = ?', 'isi', [FA1, 'feed', FA1]),
    pa_show($saved7)
);

// =============================================================================
// D8 — list states
// =============================================================================
pa_heading('D8 — list states');
pa_resetWorld();
pa_q("UPDATE tblExternalFeeds SET audienceLevel = 'groups' WHERE feedID = ?", 'i', [FA1]);
ExternalAudience::save($mysqli, ORG_A, FA1, 'feed', FA1, [
    ['kind' => 'small_group', 'refID' => SG1], ['kind' => 'small_group', 'refID' => SG2],
    ['kind' => 'leadership_role', 'refID' => RA1], ['kind' => 'person', 'refID' => P_ID],
    ['kind' => 'person', 'refID' => LA], ['kind' => 'person', 'refID' => SGM],
], SA);
pa_list(FA1, 'feed', FA1, [['small_group', 910199], ['department', 1]]); // a group that never existed; a department number that names nothing in A
pa_q('UPDATE tblUserSites SET isActive = 0 WHERE userID = ? AND siteID = ?', 'ii', [P_ID, ORG_A]);   // P's membership here switched off
pa_q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [LA]);                                  // LA's ACCOUNT switched off
pa_q('UPDATE tblUserSites SET siteID = ? WHERE userID = ? AND siteID = ?', 'iii', [ORG_B, SGM, ORG_A]); // SGM now only in B
$list8 = [];
foreach (ExternalAudience::current($mysqli, ORG_A, FA1, 'feed', FA1) as $m) {
    $list8[$m['kind'] . ':' . $m['refID']] = $m['state'] . '|' . $m['label'];
}
pa_ok(
    'D8 ok and switched_off: labelled with the group\'s and role\'s own names',
    ($list8['small_group:' . SG1] ?? '') === 'ok|Selftest SG1' && ($list8['small_group:' . SG2] ?? '') === 'switched_off|Selftest SG2'
    && ($list8['leadership_role:' . RA1] ?? '') === 'ok|Selftest RA1',
    pa_show($list8)
);
pa_ok(
    'D8 left: a person whose membership here is switched off, and one whose account is, both "left" (with their names)',
    ($list8['person:' . P_ID] ?? '') === 'left|Selftest P' && ($list8['person:' . LA] ?? '') === 'left|Selftest LA',
    pa_show($list8)
);
pa_ok(
    'D8 gone: a group that no longer exists, and a PERSON now only in another organisation — never named (challenge L16)',
    ($list8['small_group:910199'] ?? '') === 'gone|a small group that no longer exists'
    && ($list8['person:' . SGM] ?? '') === 'gone|someone no longer in this organisation',
    pa_show($list8)
);
// Until part 10 of #514 the department row above read "not_offered": no form
// offered departments. They are offered now, so a department number that
// names nothing in this organisation is "gone", like the made-up small group
// beside it, and both are removed by the save further down. "not_offered" is
// kept for a kind the DATABASE knows and the code does not. No such kind
// exists today (D15 checks the two lists are equal), so the label is asked
// for directly, with a made-up kind, through the private method that decides
// every label — reached by reflection, which reads it and changes nothing.
$label8 = (new ReflectionMethod(ExternalAudience::class, 'labelled'))->invoke(null, ['kind' => 'household', 'refID' => 7]);
pa_ok(
    'D8 a department number that names nothing here is "gone" (it was "not_offered" before part 10); a kind the forms do not offer is still shown as "an entry #7", "not_offered", with the words saying it will be removed on save (checker LOW 5)',
    ($list8['department:1'] ?? '') === 'gone|a department that no longer exists'
    && $label8 === ['kind' => 'household', 'refID' => 7, 'label' => 'an entry #7', 'state' => 'not_offered']
    && ExternalAudience::stateLabel('not_offered') === '(not used by this version, so it will be removed when you save)'
    && ExternalAudience::stateLabel('gone') === '(will be removed when you save)',
    pa_show([$list8, $label8])
);
$ruleOwner8 = pa_q("INSERT INTO tblExternalAudienceMembers (siteID, feedID, ownerType, ownerID, kind, refID, createdAt) VALUES (?, ?, 'rule', 910601, 'small_group', ?, UTC_TIMESTAMP())", 'iii', [ORG_A, FA1, SG1]);
$many8 = ExternalAudience::currentMany($mysqli, ORG_A, [
    ['feedID' => FA1, 'ownerType' => 'feed', 'ownerID' => FA1], ['feedID' => FA1, 'ownerType' => 'rule', 'ownerID' => 910601],
    ['feedID' => FA1, 'ownerType' => 'choice', 'ownerID' => 910999],
]);
pa_ok(
    'D8 currentMany() gives, for each owner asked for, exactly what current() gives (and an empty list for one with none)',
    $many8 === [
        ExternalAudience::ownerKey(FA1, 'feed', FA1)       => ExternalAudience::current($mysqli, ORG_A, FA1, 'feed', FA1),
        ExternalAudience::ownerKey(FA1, 'rule', 910601)    => ExternalAudience::current($mysqli, ORG_A, FA1, 'rule', 910601),
        ExternalAudience::ownerKey(FA1, 'choice', 910999)  => [],
    ]
    && count($many8[ExternalAudience::ownerKey(FA1, 'rule', 910601)]) === 1,
    pa_show(array_map('count', $many8))
);
// Saving the list (the picker posts the live and kept entries, never a
// gone or not_offered one) REMOVES the gone and not_offered rows — the
// decided behaviour (checker LOW 5), announced beforehand by the labels
// above. (Since part 10 both rows removed here are "gone": the made-up
// small group and the department number that names nothing.)
$r8 = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audience' => [
    'small_group' => [SG1, SG2], 'leadership_role' => [RA1], 'person' => [P_ID, LA],
]]));
$after8 = array_map(static fn (array $m): string => $m['kind'] . ':' . $m['refID'] . ':' . $m['state'], ExternalAudience::current($mysqli, ORG_A, FA1, 'feed', FA1));
pa_ok(
    'D8 saving the list removes the gone rows (the made-up small group and the department that names nothing) and keeps the rest',
    $r8['ok'] === true && $after8 === [
        'small_group:' . SG1 . ':ok', 'small_group:' . SG2 . ':switched_off', 'leadership_role:' . RA1 . ':ok',
        'person:' . P_ID . ':left', 'person:' . LA . ':left',
    ],
    pa_show([$r8, $after8])
);

// ---- Part 10 of #514: the same states for a role, a user group and a department ----
// One list holding every state the three new kinds can be in: A's own (ok);
// a retired user group, a switched-off department and one whose on/off flag
// is empty (switched_off, all three kept on save); organisation B's role,
// user group and department and a role number that never existed (gone —
// and the four must read EXACTLY alike, with none of B's names); and a user
// group and a department that have no name (shown by their number, never as
// a tick box with nothing beside it). B's three and the made-up role are
// written in by hand: the form refuses them (D7).
pa_resetWorld();
pa_q("UPDATE tblExternalFeeds SET audienceLevel = 'groups' WHERE feedID = ?", 'i', [FA1]);
pa_q('INSERT INTO tblGroups (groupID, siteID, groupName, isActive) VALUES (910149, ?, NULL, 1)', 'i', [ORG_A]);   // no name at all
pa_q("INSERT INTO tblDepts (deptID, siteID, deptName, isActive) VALUES (910159, ?, '   ', 1)", 'i', [ORG_A]);    // a name of spaces only
ExternalAudience::save($mysqli, ORG_A, FA1, 'feed', FA1, [
    ['kind' => 'role', 'refID' => RO1], ['kind' => 'user_group', 'refID' => UG1], ['kind' => 'user_group', 'refID' => UG2],
    ['kind' => 'department', 'refID' => DP1], ['kind' => 'department', 'refID' => DP2], ['kind' => 'department', 'refID' => DP3],
    ['kind' => 'user_group', 'refID' => 910149], ['kind' => 'department', 'refID' => 910159],
], SA);
pa_list(FA1, 'feed', FA1, [['role', ROB], ['user_group', UGB], ['department', DPB], ['role', 919998]]);
$states8p = static function () use ($mysqli): array {
    $out = [];
    foreach (ExternalAudience::current($mysqli, ORG_A, FA1, 'feed', FA1) as $m) {
        $out[$m['kind'] . ':' . $m['refID']] = $m['state'] . '|' . $m['label'];
    }

    return $out;
};
$list8p = $states8p();
pa_ok(
    'D8 part 10, ok and switched_off: A\'s role, user group and department by their own names; the retired UG2, the switched-off DP2 and DP3 (empty flag) "switched_off" with theirs',
    ($list8p['role:' . RO1] ?? '') === 'ok|Selftest RO1' && ($list8p['user_group:' . UG1] ?? '') === 'ok|Selftest UG1'
    && ($list8p['department:' . DP1] ?? '') === 'ok|Selftest DP1' && ($list8p['user_group:' . UG2] ?? '') === 'switched_off|Selftest UG2'
    && ($list8p['department:' . DP2] ?? '') === 'switched_off|Selftest DP2' && ($list8p['department:' . DP3] ?? '') === 'switched_off|Selftest DP3',
    pa_show($list8p)
);
pa_ok(
    'D8 part 10, gone: organisation B\'s role, user group and department read exactly like a number that never existed — "a role that no longer exists" and so on — and B\'s names appear nowhere',
    ($list8p['role:' . ROB] ?? '') === 'gone|a role that no longer exists' && ($list8p['role:919998'] ?? '') === 'gone|a role that no longer exists'
    && ($list8p['user_group:' . UGB] ?? '') === 'gone|a user group that no longer exists'
    && ($list8p['department:' . DPB] ?? '') === 'gone|a department that no longer exists' && pa_has($list8p, B_MARK) === false,
    pa_show($list8p)
);
$opts8p = ExternalAudience::options($mysqli, ORG_A);
pa_ok(
    'D8 part 10, no name: a user group with no name and a department named with spaces only are shown as "User group #910149" and "Department #910159", on the saved list and among the options',
    ($list8p['user_group:910149'] ?? '') === 'ok|User group #910149' && ($list8p['department:910159'] ?? '') === 'ok|Department #910159'
    && in_array(['groupID' => 910149, 'groupName' => 'User group #910149'], $opts8p['user_group'], true) === true
    && in_array(['deptID' => 910159, 'deptName' => 'Department #910159'], $opts8p['department'], true) === true,
    pa_show([$list8p, $opts8p['user_group'], $opts8p['department']])
);
// The picker would post the live and the kept entries, never a gone one.
$r8p = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audience' => [
    'role' => [RO1], 'user_group' => [UG1, UG2, 910149], 'department' => [DP1, DP2, DP3, 910159],
]]));
$after8p = array_map(static fn (array $m): string => $m['kind'] . ':' . $m['refID'] . ':' . $m['state'], ExternalAudience::current($mysqli, ORG_A, FA1, 'feed', FA1));
pa_ok(
    'D8 part 10: saving the list removes B\'s three and the made-up role, and keeps A\'s — the retired and switched-off ones included',
    $r8p['ok'] === true && $after8p === [
        'role:' . RO1 . ':ok', 'user_group:' . UG1 . ':ok', 'user_group:' . UG2 . ':switched_off', 'user_group:910149:ok',
        'department:' . DP1 . ':ok', 'department:' . DP2 . ':switched_off', 'department:' . DP3 . ':switched_off', 'department:910159:ok',
    ],
    pa_show([$r8p, $after8p])
);
// A role, user group or department DELETED while it is on a list (each of
// their own pages can do that once nobody holds it; here the rows are simply
// removed, and the holdings go with them): the entry stays on the list,
// reads "no longer exists", and — D17 — lets nobody in.
pa_q('DELETE FROM tblRoles WHERE roleID = ?', 'i', [RO1]);
pa_q('DELETE FROM tblGroups WHERE groupID = ?', 'i', [UG1]);
pa_q('DELETE FROM tblDepts WHERE deptID = ?', 'i', [DP1]);
$gone8p = $states8p();
pa_ok(
    'D8 part 10: a role, user group and department deleted while on the list each read "… that no longer exists" (gone), with no name',
    ($gone8p['role:' . RO1] ?? '') === 'gone|a role that no longer exists' && ($gone8p['user_group:' . UG1] ?? '') === 'gone|a user group that no longer exists'
    && ($gone8p['department:' . DP1] ?? '') === 'gone|a department that no longer exists',
    pa_show($gone8p)
);

// Fix round 2, checker round 2 M1: a CHOICE and a RULE with the SAME number
// on the same calendar. Choices and rules are numbered by two separate
// counters, so on a real database this is ordinary, not contrived. Each has
// its own list, and every reader and every save must keep them apart —
// otherwise rule #R's page would show choice #R's people, and saving it
// would write them onto the rule. A list filed under a choice numbered the
// same as the CALENDAR is added too, for the calendar's own list.
pa_resetWorld();
$rCal8m = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]));
$ev8r   = pa_event(FA1, 'd8-same-rule@p8', 'D8 same rule');
$ev8c   = pa_event(FA1, 'd8-same-choice@p8', 'D8 same choice');
$rule8m = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D8 same', 'audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'leadership_role' => [RA1]], 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D8 same rule']]]);
$same8  = (int) ($rule8m['ruleId'] ?? 0);
pa_choice(FA1, 'date', 'd8-same-choice@p8', 'members', ['id' => $same8, 'key' => '']);
$cho8m  = FeedAdmin::saveChoice($mysqli, $ev8c, 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [P_ID]]]);
pa_list(FA1, 'choice', FA1, [['person', SGM]]); // a list under a choice numbered like the CALENDAR
$keys8 = static fn (array $list): array => array_map(static fn (array $m): string => $m['kind'] . ':' . $m['refID'], $list);
$wantRule8   = ['small_group:' . SG1, 'leadership_role:' . RA1];
$wantChoice8 = ['small_group:' . SG1, 'person:' . P_ID];
pa_ok(
    'D8 set-up: a rule and a choice with the SAME number on one calendar, each saved through FeedAdmin with its own list, each leaving a date waiting',
    $rCal8m['ok'] === true && $rule8m['ok'] === true && $cho8m['ok'] === true && (int) ($cho8m['choiceId'] ?? 0) === $same8
    && pa_pending($ev8r) !== null && pa_pending($ev8c) !== null,
    pa_show([$rule8m, $cho8m])
);
$many8m = ExternalAudience::currentMany($mysqli, ORG_A, [
    ['feedID' => FA1, 'ownerType' => 'rule', 'ownerID' => $same8], ['feedID' => FA1, 'ownerType' => 'choice', 'ownerID' => $same8],
    ['feedID' => FA1, 'ownerType' => 'feed', 'ownerID' => FA1],
]);
pa_ok(
    'D8 same number: current() and currentMany() give the rule, the choice and the calendar EACH only its own list',
    $keys8(ExternalAudience::current($mysqli, ORG_A, FA1, 'rule', $same8)) === $wantRule8
    && $keys8(ExternalAudience::current($mysqli, ORG_A, FA1, 'choice', $same8)) === $wantChoice8
    && $keys8($many8m[ExternalAudience::ownerKey(FA1, 'rule', $same8)] ?? []) === $wantRule8
    && $keys8($many8m[ExternalAudience::ownerKey(FA1, 'choice', $same8)] ?? []) === $wantChoice8
    && $keys8($many8m[ExternalAudience::ownerKey(FA1, 'feed', FA1)] ?? []) === ['small_group:' . SG1],
    pa_show(array_map($keys8, $many8m))
);
$cal8m  = FeedAdmin::calendar($mysqli, FA1, ORG_A);
$cals8m = array_values(array_filter(FeedAdmin::calendars($mysqli, ORG_A), static fn (array $c): bool => (int) $c['feedID'] === FA1))[0] ?? [];
$rows8m = [];
foreach (FeedAdmin::pendingApprovals($mysqli, ORG_A, FA1, 50)['rows'] as $row) {
    $rows8m[(int) $row['eventID']] = $keys8($row['names']);
}
pa_ok(
    'D8 same number: calendar() and calendars() show the calendar\'s own list; the inbox names the rule\'s and the choice\'s people separately',
    $keys8($cal8m['list'] ?? []) === ['small_group:' . SG1] && $keys8($cals8m['list'] ?? []) === ['small_group:' . SG1]
    && ($rows8m[$ev8r] ?? []) === $wantRule8 && ($rows8m[$ev8c] ?? []) === $wantChoice8,
    pa_show($rows8m)
);
$rule8m2 = FeedAdmin::saveRule($mysqli, FA1, $same8, ORG_A, SA, ['name' => 'D8 same', 'audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [SGM]], 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D8 same rule']]]);
$afterRule8   = [$keys8(ExternalAudience::current($mysqli, ORG_A, FA1, 'rule', $same8)), $keys8(ExternalAudience::current($mysqli, ORG_A, FA1, 'choice', $same8))];
$cho8m2 = FeedAdmin::saveChoice($mysqli, $ev8c, 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]);
$afterChoice8 = [$keys8(ExternalAudience::current($mysqli, ORG_A, FA1, 'rule', $same8)), $keys8(ExternalAudience::current($mysqli, ORG_A, FA1, 'choice', $same8))];
pa_ok(
    'D8 same number: saving the rule\'s list leaves the choice\'s untouched, and saving the choice\'s leaves the rule\'s untouched',
    $rule8m2['ok'] === true && $cho8m2['ok'] === true
    && $afterRule8 === [['small_group:' . SG1, 'person:' . SGM], $wantChoice8]
    && $afterChoice8 === [['small_group:' . SG1, 'person:' . SGM], ['small_group:' . SG1]],
    pa_show([$afterRule8, $afterChoice8])
);

// =============================================================================
// D9 — category matching
// =============================================================================
pa_heading('D9 — category matching');
pa_resetWorld();
$evOutreach = pa_event(FA1, 'd9-1@p8', 'D9 outreach', ['tags' => ['outreach']]);
$evYouth    = pa_event(FA1, 'd9-2@p8', 'D9 youth', ['tags' => ['youth']]);
$evCafe     = pa_event(FA1, 'd9-3@p8', 'D9 cafe', ['tags' => ['cafe']]);
// The calendar has NO default category here, on purpose. The resolver
// (part 7, unchanged by part 8) treats a word mapped to "none" exactly
// like an unmapped word: it skips it and falls back to the calendar's own
// default category (FeedResolver::categoryFor()). So with a default set,
// "none" and "default" land in the same place — found by this fix round
// and reported for the commissioning session, since the calendar page's
// wording for "none" (P8-2) depends on it. This check proves only what
// FeedAdmin stores and that the events follow.
// Organisation B's calendar FB1 and A's other calendar FA2 hold rows under
// the SAME three words first (fix round 7, check round 7 M2): the save is
// FA1's, so they must come through untouched.
$others9 = pa_seedOtherMaps(['outreach' => ['outreach', 'outreach'], 'youth' => ['youth', 'youth'], 'cafe' => ['cafe', 'cafe']]);
$r9 = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [
    ['word' => 'outreach', 'choice' => (string) CA1],
    ['word' => 'youth', 'choice' => 'none'],
    ['word' => 'cafe', 'choice' => 'default'],
]]);
pa_ok(
    'D9 a map with CA1 / none / default stores as specified, and the events follow in the same call',
    $r9['ok'] === true && $r9['message'] === 'Saved.'
    && (int) pa_one('SELECT categoryID FROM tblExternalCategoryMap WHERE feedID = ? AND externalCategory = ?', 'is', [FA1, 'outreach'])['categoryID'] === CA1
    && pa_one('SELECT categoryID FROM tblExternalCategoryMap WHERE feedID = ? AND externalCategory = ?', 'is', [FA1, 'youth'])['categoryID'] === null
    && pa_one('SELECT * FROM tblExternalCategoryMap WHERE feedID = ? AND externalCategory = ?', 'is', [FA1, 'cafe']) === null
    && (int) pa_ev($evOutreach)['categoryID'] === CA1 && pa_ev($evYouth)['categoryID'] === null && pa_ev($evCafe)['categoryID'] === null,
    pa_show($r9)
);
pa_ok(
    'D9 the rows under the same words in organisation B\'s calendar FB1 and in A\'s other calendar FA2 are untouched — neither deleted by the "default" word nor rewritten by the category one',
    count($others9) === 6 && pa_otherMaps() === $others9,
    pa_show(['before' => $others9, 'after' => pa_otherMaps()])
);
$fp9 = pa_fp();
$r9b = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'outreach', 'choice' => (string) CB1]]]);
pa_ok('D9 B\'s category CB1 -> exactly "Choose a category from the list for \'outreach\'.", nothing changed', $r9b['ok'] === false && $r9b['message'] === "Choose a category from the list for 'outreach'." && pa_fp() === $fp9, pa_show($r9b));
pa_q('UPDATE tblEventCategories SET isActive = 0 WHERE categoryID = ?', 'i', [CA2]);
$r9c = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'outreach', 'choice' => (string) CA2]]]);
pa_q('UPDATE tblEventCategories SET isActive = 1 WHERE categoryID = ?', 'i', [CA2]);
pa_ok('D9 a retired category newly chosen -> exactly "Choose a category from the list for \'outreach\'.", nothing changed', $r9c['ok'] === false && $r9c['message'] === "Choose a category from the list for 'outreach'." && pa_fp() === $fp9, pa_show($r9c));
// Every row FA1's saves wrote carries organisation A's number, and tags()
// — which reads by calendar AND organisation — reads them all back (fix
// round 8, check round 8 L5 / fault C8-09: a new row stamped with the
// wrong organisation would vanish from the form, and be deleted on the
// next save).
$rows9  = pa_q('SELECT externalCategory, siteID FROM tblExternalCategoryMap WHERE feedID = ? ORDER BY externalCategory', 'i', [FA1]);
$tags9  = array_values(array_filter(FeedAdmin::tags($mysqli, FA1, ORG_A), static fn (array $t): bool => $t['mapped'] === true));
pa_ok(
    'D9 every map row FA1\'s save wrote carries organisation A\'s number, and tags() reads each one back as mapped',
    count($rows9) === 2 && array_unique(array_map(static fn (array $r): int => (int) $r['siteID'], $rows9)) === [ORG_A]
    && array_column($tags9, 'word') === array_column($rows9, 'externalCategory'),
    pa_show([$rows9, $tags9])
);
// A word KEEPS its stored category after that category is switched off
// (fix round 8, check round 8 L3 — the keep rule the calendar's own
// category has had since fix round 1, LOW 3). "youth" is put on CA2; CA2
// is switched off; the form posted back UNCHANGED, with a new word added,
// saves and keeps "youth" on CA2. Choosing the switched-off CA2 for a
// DIFFERENT word is still refused, naming that word; so is another
// organisation's category, naming the word.
$r9d = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'youth', 'choice' => (string) CA2]]]);
pa_q('UPDATE tblEventCategories SET isActive = 0 WHERE categoryID = ?', 'i', [CA2]);
$r9e = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [
    ['word' => 'outreach', 'choice' => (string) CA1], ['word' => 'youth', 'choice' => (string) CA2], ['word' => 'art', 'choice' => (string) CA1],
]]);
$map9 = [];
foreach (pa_q('SELECT externalCategory, categoryID FROM tblExternalCategoryMap WHERE feedID = ?', 'i', [FA1]) as $r) {
    $map9[(string) $r['externalCategory']] = $r['categoryID'] === null ? null : (int) $r['categoryID'];
}
pa_ok(
    'D9 "youth" on CA2, CA2 then switched off: the form posted back unchanged plus a new word saves — "youth" keeps CA2, "art" gets CA1, exactly "Saved."',
    $r9d['ok'] === true && $r9e['ok'] === true && $r9e['message'] === 'Saved.'
    && ($map9['youth'] ?? null) === CA2 && ($map9['art'] ?? null) === CA1 && ($map9['outreach'] ?? null) === CA1,
    pa_show([$r9d, $r9e, $map9])
);
$fp9b = pa_fp();
$r9f  = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'art', 'choice' => (string) CA2]]]);
$r9g  = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'youth', 'choice' => (string) CB1]]]);
pa_q('UPDATE tblEventCategories SET isActive = 1 WHERE categoryID = ?', 'i', [CA2]);
pa_ok(
    'D9 the switched-off CA2 chosen NEW for "art" is refused naming "art"; B\'s CB1 for "youth" is refused naming "youth"; nothing changed',
    $r9f['ok'] === false && $r9f['message'] === "Choose a category from the list for 'art'."
    && $r9g['ok'] === false && $r9g['message'] === "Choose a category from the list for 'youth'." && pa_fp() === $fp9b,
    pa_show([$r9f, $r9g])
);
// A stored category of ANOTHER organisation (a hand edit — the forms can
// never store one) is not "kept unchanged": the keep rule is only for this
// organisation's own categories (fix round 8, L3; the calendar's own
// category has the same limit, D31).
pa_q('UPDATE tblExternalCategoryMap SET categoryID = ? WHERE feedID = ? AND externalCategory = ?', 'iis', [CB1, FA1, 'youth']);
$r9h = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'youth', 'choice' => (string) CB1]]]);
pa_q('UPDATE tblExternalCategoryMap SET categoryID = ? WHERE feedID = ? AND externalCategory = ?', 'iis', [CA2, FA1, 'youth']);
pa_ok(
    'D9 "youth" stored (by hand) with B\'s CB1 and posted back unchanged: not kept — refused naming "youth"',
    $r9h['ok'] === false && $r9h['message'] === "Choose a category from the list for 'youth'.",
    pa_show($r9h)
);
pa_ok(
    'D9 and after every later D9 save too, organisation B\'s FB1 rows and A\'s FA2 rows under the same words are untouched',
    pa_otherMaps() === $others9,
    pa_show(['before' => $others9, 'after' => pa_otherMaps()])
);

// =============================================================================
// D10 — choice basics
// =============================================================================
pa_heading('D10 — choice basics');
pa_resetWorld();
[$s1, $s2, $s3, $s4] = pa_series(FA1, 'd10-series@p8', 'D10 series');
$oneOff = pa_event(FA1, 'd10-oneoff@p8', 'D10 one-off', ['day' => 20]);
$uid10  = pa_one('SELECT externalUidHash AS h FROM tblEvents WHERE eventID = ?', 'i', [$s1])['h'];
$r10    = FeedAdmin::saveChoice($mysqli, $s1, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'fromDate' => '', 'toDate' => '']);
$c10    = pa_one('SELECT choiceID, scope, externalRecurrenceKey AS k, externalUidHash AS h FROM tblExternalEventChoices WHERE choiceID = ?', 'i', [(int) ($r10['choiceId'] ?? 0)]);
pa_ok(
    'D10 a date choice is stored with the event\'s identity and ITS OWN recurrence key',
    $r10['ok'] === true && $c10 !== null && $c10['scope'] === 'date' && $c10['k'] === 'W0' && $c10['h'] === $uid10,
    pa_show($r10)
);
$r10b = FeedAdmin::saveChoice($mysqli, $s1, 'date', ORG_A, SA, ['audienceLevel' => 'hidden']);
pa_ok(
    'D10 saving again updates the SAME row',
    $r10b['ok'] === true && (int) $r10b['choiceId'] === (int) $c10['choiceID']
    && (int) pa_one('SELECT COUNT(*) AS n FROM tblExternalEventChoices WHERE feedID = ?', 'i', [FA1])['n'] === 1
);
// Its own moments are the DATABASE's UTC clock, never NOW() — this test's
// session runs 13 hours ahead (D0), so a NOW() would be far out (fix round
// 3, checker round 3 LOW 5 / fault X17).
pa_ok(
    'D10 the choice\'s createdAt (when made) and updatedAt (when saved again) are within 5 s of the database\'s UTC now, and name who did each',
    pa_nearDbNow('tblExternalEventChoices', 'createdAt', 'choiceID = ?', 'i', [(int) $c10['choiceID']])
    && pa_nearDbNow('tblExternalEventChoices', 'updatedAt', 'choiceID = ?', 'i', [(int) $c10['choiceID']])
    && (int) pa_one('SELECT createdByID AS c FROM tblExternalEventChoices WHERE choiceID = ?', 'i', [(int) $c10['choiceID']])['c'] === SA
    && (int) pa_one('SELECT updatedByID AS u FROM tblExternalEventChoices WHERE choiceID = ?', 'i', [(int) $c10['choiceID']])['u'] === SA
);
$r10s = FeedAdmin::saveChoice($mysqli, $s2, 'series', ORG_A, SA, ['audienceLevel' => 'members']);
$c10s = pa_one('SELECT scope, externalRecurrenceKey AS k, externalUidHash AS h FROM tblExternalEventChoices WHERE choiceID = ?', 'i', [(int) ($r10s['choiceId'] ?? 0)]);
pa_ok('D10 a SERIES choice is stored with the identity and the recurrence key \'\' (never the opened date\'s)', $r10s['ok'] === true && $c10s !== null && $c10s['scope'] === 'series' && $c10s['k'] === '' && $c10s['h'] === $uid10, pa_show($c10s));
$fp10 = pa_fp();
$r10c = FeedAdmin::saveChoice($mysqli, $s2, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'fromDate' => '2026-02-30']);
$r10d = FeedAdmin::saveChoice($mysqli, $s2, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'fromDate' => (new DateTimeImmutable('+10 days'))->format('Y-m-d'), 'toDate' => (new DateTimeImmutable('+1 day'))->format('Y-m-d')]);
pa_ok(
    'D10 window refusals, word for word: an impossible date; the last day before the first',
    $r10c['ok'] === false && $r10c['message'] === 'Enter the dates as real calendar dates.'
    && $r10d['ok'] === false && $r10d['message'] === 'The last day cannot be before the first day.' && pa_fp() === $fp10,
    pa_show([$r10c, $r10d])
);
$r10e = FeedAdmin::saveChoice($mysqli, $oneOff, 'series', ORG_A, SA, ['audienceLevel' => 'public']);
pa_ok('D10 a series choice for a one-off event -> not_found, "That event could not be found."', $r10e['status'] === 'not_found' && $r10e['message'] === MSG_NF_EVENT, pa_show($r10e));
$r10f = FeedAdmin::saveChoice($mysqli, $s3, 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]);
$l10f = ExternalAudience::current($mysqli, ORG_A, FA1, 'choice', (int) ($r10f['choiceId'] ?? 0));
$r10g = FeedAdmin::saveChoice($mysqli, $s3, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'audience' => ['small_group' => [SG1]]]);
pa_ok(
    'D10 the list is kept only at Selected groups (a list posted at Members is not stored)',
    $r10f['ok'] === true && count($l10f) === 1 && $r10g['ok'] === true
    && (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE ownerType = 'choice' AND ownerID = ?", 'i', [(int) $r10g['choiceId']])['n'] === 0
);
$r10h = FeedAdmin::saveChoice($mysqli, $s3, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'websiteOptIn' => '1']);
$w10h = (int) pa_one('SELECT websiteOptIn AS w FROM tblExternalEventChoices WHERE choiceID = ?', 'i', [(int) ($r10h['choiceId'] ?? 0)])['w'];
$r10i = FeedAdmin::saveChoice($mysqli, $s4, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'websiteOptIn' => '1']);
$w10i = (int) pa_one('SELECT websiteOptIn AS w FROM tblExternalEventChoices WHERE choiceID = ?', 'i', [(int) ($r10i['choiceId'] ?? 0)])['w'];
$r10j = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D10 rule', 'audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]], 'websiteOptIn' => '1', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'nothing matches this']]]);
$w10j = (int) pa_one('SELECT websiteOptIn AS w FROM tblExternalFeedRules WHERE ruleID = ?', 'i', [(int) ($r10j['ruleId'] ?? 0)])['w'];
pa_ok(
    'D10 the website box: stored 0 on a choice at Members and on a rule at Selected groups, even when posted ticked; stored 1 at Everyone',
    $r10h['ok'] === true && $w10h === 0 && $r10i['ok'] === true && $w10i === 1 && $r10j['ok'] === true && $w10j === 0,
    pa_show([$w10h, $w10i, $w10j])
);
$r10k = FeedAdmin::saveChoice($mysqli, $s3, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'apiOptOut' => '1']);
pa_ok('D10 the API box on a choice makes the covered date importApiOptOut = 1', $r10k['ok'] === true && (int) pa_ev($s3)['importApiOptOut'] === 1);

// =============================================================================
// D11 — choice and seen (C10 item 4)
// =============================================================================
pa_heading('D11 — choice and seen');
pa_resetWorld();
// (a) a private-marked date. FA2 is already Public, so a Public choice is
//     NOT wider than the calendar's own setting — the private mark is the
//     only thing standing in the way here.
$evPrivate = pa_event(FA2, 'd11a@p8', 'D11 private', ['private' => 1]);
$fp11a     = pa_fp();
$ra        = FeedAdmin::saveChoice($mysqli, $evPrivate, 'date', ORG_A, SA, ['audienceLevel' => 'public']);
$fp11a2    = pa_fp();
$rb        = FeedAdmin::saveChoice($mysqli, $evPrivate, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'overridesPrivateMark' => '1', 'seen' => [$evPrivate . ':' . pa_cf($evPrivate)]]);
pa_ok(
    'D11a a private-marked date: refused without the tick, with the warning word for word and nothing written',
    $ra['ok'] === false && $ra['status'] === 'refused' && $ra['message'] === MSG_PRIVATE && $fp11a2 === $fp11a,
    pa_show($ra)
);
pa_ok(
    'D11a with the tick: accepted, the choice stores overridesPrivateMark = 1, and the date is public',
    $rb['ok'] === true && pa_ev($evPrivate)['importLevel'] === 'public'
    && (int) pa_one('SELECT overridesPrivateMark AS o FROM tblExternalEventChoices WHERE choiceID = ?', 'i', [(int) ($rb['choiceId'] ?? 0)])['o'] === 1,
    pa_show($rb)
);

// (b) a single-date choice's `seen` naming other dates too. The resolver
//     would ignore most of these on its own; `seenKept` shows FeedAdmin's
//     OWN filter at work, so losing that filter fails here (fault F16).
pa_resetWorld();
[$b1, $b2, $b3, $b4] = pa_series(FA1, 'd11b-series@p8', 'D11b series');
$evFA2 = pa_event(FA2, 'd11b-fa2@p8', 'D11b FA2 event');
$evB   = pa_event(FB1, 'd11b-b@p8', 'D11b BONLY event');
$b1cf  = pa_cf($b1);
$rSeen = FeedAdmin::saveChoice($mysqli, $b1, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [
    $b1 . ':' . $b1cf, $b2 . ':' . pa_cf($b2), $evFA2 . ':' . pa_cf($evFA2), $evB . ':' . pa_cf($evB), $b3 . ':notahash', 'garbage', ['an' => 'array'],
]]);
pa_ok(
    'D11b FeedAdmin keeps ONLY the choice\'s own date from seen[] (seenKept = [its own date])',
    $rSeen['ok'] === true && ($rSeen['seenKept'] ?? null) === [$b1],
    pa_show($rSeen['seenKept'] ?? null)
);
pa_ok(
    'D11b only its own date is approved: no sibling, no other calendar\'s event, no bad hash gets an approved row',
    pa_ev($b1)['importLevel'] === 'public' && pa_apprWithStatus($b2, 'approved') === [] && pa_apprWithStatus($evFA2, 'approved') === []
    && pa_apprWithStatus($evB, 'approved') === [] && pa_apprWithStatus($b3, 'approved') === []
);
pa_ok('D11b datesCovered() for a single date returns exactly that one date', array_column(FeedAdmin::datesCovered($mysqli, FeedAdmin::importedEvent($mysqli, $b1, ORG_A), 'date'), 'eventID') === [$b1]);

// (c) approvedBySave counts only rows under the choice's OWN request.
pa_resetWorld();
$c1 = pa_event(FA1, 'd11c@p8', 'D11c date 1', ['day' => 7, 'key' => 'W0']);
$c2 = pa_event(FA1, 'd11c@p8', 'D11c date 2', ['day' => 14, 'key' => 'W1']);
$c3 = pa_event(FA1, 'd11c@p8', 'D11c date 3 own', ['day' => 21, 'key' => 'W2']);
$c4 = pa_event(FA1, 'd11c@p8', 'D11c date 4 own', ['day' => 28, 'key' => 'W3']);
$rFirst = FeedAdmin::saveChoice($mysqli, $c1, 'series', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [$c1 . ':' . pa_cf($c1), $c2 . ':' . pa_cf($c2)]]);
pa_ok(
    'D11c set-up: dates 1 & 2 approved, 3 & 4 waiting (new_match); the first save says "2 dates wait for approval …" and nothing about waiting dates now approved',
    $rFirst['ok'] === true && pa_ev($c1)['importLevel'] === 'public' && pa_ev($c2)['importLevel'] === 'public'
    && pa_ev($c3)['importSource'] === 'waiting' && pa_ev($c4)['importSource'] === 'waiting'
    && ($rFirst['resolved']['approvedBySave'] ?? -1) === 0
    && str_contains($rFirst['message'], ' 2 dates wait for approval because they changed while the page was open or were not on the page.')
    && str_contains($rFirst['message'], 'now approved') === false,
    pa_show($rFirst)
);
$c4pending = pa_pending($c4);
$rDecl     = ($c4pending === null) ? ['decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, 'declined', '', [pa_item($c4pending)]);
$rAll      = FeedAdmin::saveChoice($mysqli, $c1, 'series', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => array_map(static fn (int $id): string => $id . ':' . pa_cf($id), [$c1, $c2, $c3, $c4])]);
pa_ok(
    'D11c re-saving with all four seen: approvedBySave = 2, and the message says "2 dates that were waiting or declined are now approved."',
    $rDecl['decided'] === 1 && $rAll['ok'] === true && ($rAll['resolved']['approvedBySave'] ?? -1) === 2
    && str_contains($rAll['message'], ' 2 dates that were waiting or declined are now approved.')
    && count(pa_apprWithStatus($c4, 'superseded')) === 1 && count(array_filter(pa_apprWithStatus($c4, 'approved'), static fn (array $a): bool => $a['reason'] === 'choice')) === 1,
    pa_show([$rDecl, $rAll])
);

// (d) a listed date whose title changed after its fingerprint was taken.
pa_resetWorld();
$dEv = pa_event(FA1, 'd11d@p8', 'D11d event');
$dCf = pa_cf($dEv);
pa_q('UPDATE tblEvents SET eventName = ? WHERE eventID = ?', 'si', ['D11d event CHANGED', $dEv]);
$rD = FeedAdmin::saveChoice($mysqli, $dEv, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'detailLevel' => 'full', 'seen' => [$dEv . ':' . $dCf]]);
pa_ok(
    'D11d a stale fingerprint: the date waits (content_changed), and the singular sentence says so',
    $rD['ok'] === true && pa_ev($dEv)['importSource'] === 'waiting'
    && count(array_filter(pa_apprWithStatus($dEv, 'pending'), static fn (array $a): bool => $a['reason'] === 'content_changed')) === 1
    && str_contains($rD['message'], ' 1 date waits for approval because it changed while the page was open or was not on the page.'),
    pa_show($rD)
);

// (e) datesCovered(): the waiting / declined state, "differs", and the fingerprint.
pa_resetWorld();
[$e1, $e2] = pa_series(FA1, 'd11e@p8', 'D11e series', ['count' => 2, 'titles' => ['D11e series', 'D11e series CHANGED']]);
$rE        = FeedAdmin::saveChoice($mysqli, $e1, 'series', ORG_A, SA, ['audienceLevel' => 'public']);
$e1pending = pa_pending($e1);
$e2pending = pa_pending($e2);
$rE2       = ($e1pending === null) ? ['decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, 'declined', '', [pa_item($e1pending)]);
$e1decl    = pa_apprWithStatus($e1, 'declined')[0] ?? null;
$byId      = [];
foreach (FeedAdmin::datesCovered($mysqli, FeedAdmin::importedEvent($mysqli, $e1, ORG_A), 'series') as $c) {
    $byId[$c['eventID']] = $c;
}
pa_ok(
    'D11e set-up: both dates waited through a real choice save; date 1 was then declined',
    $rE['ok'] === true && $e1pending !== null && $e2pending !== null && $rE2['decided'] === 1 && $e1decl !== null,
    pa_show([$rE, $rE2])
);
pa_ok(
    'D11e datesCovered(): date 1 "declined" with the decline\'s own date; date 2 (different details) still "waiting"',
    ($byId[$e1]['state'] ?? '') === 'declined' && ($byId[$e1]['declinedAt'] ?? '') === (string) ($e1decl['decidedAt'] ?? 'x')
    && ($byId[$e2]['state'] ?? '') === 'waiting' && array_key_exists('declinedAt', $byId[$e2] ?? []) === true && $byId[$e2]['declinedAt'] === null,
    pa_show($byId)
);
pa_ok(
    'D11e datesCovered(): "differs" is true exactly for the changed date; cf equals fullContentHash() with the stored category',
    ($byId[$e1]['differs'] ?? true) === false && ($byId[$e2]['differs'] ?? false) === true
    && ($byId[$e1]['cf'] ?? '') === pa_cf($e1) && ($byId[$e2]['cf'] ?? '') === pa_cf($e2)
);

// (f) Fix round 2, checker round 2 LOW 6: a choice whose first day is still
//     AHEAD, re-saved with its waiting date on the page. The resolver records
//     the approval now (its step 11b), but shows nothing until the choice
//     starts — so the message may say "approved", never "shown".
pa_resetWorld();
$f11  = pa_event(FA1, 'd11f@p8', 'D11f event');
FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'x', 'choice' => 'none']]]); // any locked write: the event is worked out (Members)
$rF1  = FeedAdmin::saveChoice($mysqli, $f11, 'date', ORG_A, SA, ['audienceLevel' => 'public']);
$lsF1 = pa_ls($f11);
// Saved again exactly as it is: the date was ALREADY waiting under this
// choice, so it is not NEWLY waiting — the sentence must not repeat (fix
// round 3, checker round 3 LOW 5 / fault X08).
$rF1b = FeedAdmin::saveChoice($mysqli, $f11, 'date', ORG_A, SA, ['audienceLevel' => 'public']);
$lsF1b = pa_ls($f11);
$ahead = (new DateTimeImmutable('now', pa_zone()))->modify('+2 days')->format('Y-m-d');
$rF2  = FeedAdmin::saveChoice($mysqli, $f11, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'fromDate' => $ahead, 'seen' => [$f11 . ':' . pa_cf($f11)]]);
pa_ok(
    'D11f a Members date asked for Everyone waits; the message says so, and nothing else',
    $rF1['ok'] === true && $lsF1 === 'members/waiting'
    && $rF1['message'] === 'Saved. 1 date waits for approval because it changed while the page was open or was not on the page.',
    pa_show($rF1) . ' ' . $lsF1
);
pa_ok(
    'D11f saved again unchanged: the date is still waiting, but not NEWLY — exactly "Saved."',
    $rF1b['ok'] === true && $lsF1b === 'members/waiting' && $rF1b['message'] === 'Saved.',
    pa_show($rF1b) . ' ' . $lsF1b
);
pa_ok(
    'D11f re-saved to start in two days, the date seen: its approval is recorded but it is NOT shown yet — "Saved. 1 date that was waiting or declined is now approved." exactly',
    $rF2['ok'] === true && ($rF2['resolved']['approvedBySave'] ?? -1) === 1 && pa_ls($f11) === 'members/calendar'
    && $rF2['message'] === 'Saved. 1 date that was waiting or declined is now approved.',
    pa_show($rF2) . ' ' . pa_ls($f11)
);

// (g) The "waits for approval because it changed while the page was open or
//     was not on the page" sentence is about THIS choice's dates only (fix
//     round 2, checker round 2 LOW 6). A date of the same calendar that a
//     RULE had shown, whose details then changed, goes back to waiting in
//     the same resolve — but it was never this choice's, so the sentence
//     must not count it. Both dates DID change who can see them (the rule's
//     went back from Everyone to Members while it waits; the choice's went
//     to Everyone), and the true count says so.
pa_resetWorld();
$g11x = pa_event(FA1, 'd11g-rule@p8', 'D11g ruled');
$g11y = pa_event(FA1, 'd11g-choice@p8', 'D11g chosen');
FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D11g', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D11g ruled']]]);
$g11p = pa_pending($g11x);
$g11d = ($g11p === null) ? ['decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($g11p)]);
$lsG1 = pa_ls($g11x) . ' ' . pa_ls($g11y);
pa_q('UPDATE tblEvents SET eventName = ? WHERE eventID = ?', 'si', ['D11g ruled, details changed', $g11x]);
$rG = FeedAdmin::saveChoice($mysqli, $g11y, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [$g11y . ':' . pa_cf($g11y)]]);
pa_ok(
    'D11g a rule\'s date going back to waiting during a choice save is not reported as this choice\'s waiting date: exactly "Saved. 2 dates changed who can see them."',
    $g11d['decided'] === 1 && $lsG1 === 'public/rule members/calendar' && $rG['ok'] === true
    && ($rG['resolved']['newPending'] ?? 0) === 1 && pa_ls($g11x) === 'members/waiting' && pa_ls($g11y) === 'public/date'
    && $rG['message'] === 'Saved. 2 dates changed who can see them.',
    pa_show($rG) . ' ' . $lsG1 . ' -> ' . pa_ls($g11x) . ' ' . pa_ls($g11y)
);

// =============================================================================
// D12 — removing a choice (C10 item 7) — the plan's combined scenario
// =============================================================================
// A Selected-groups series choice with two list rows and one waiting date,
// all through real write paths: the CALENDAR is set to Selected groups
// [SG1], and the choice asks for [SG1 + P] — a list that names somebody
// new, which the resolver counts as wider (FeedResolver::isWider()), so a
// date not seen on the page waits. (The first build believed this could
// not happen; the check proved it can — fix round 1, checker M2.)
pa_heading('D12 — removing a choice');
pa_resetWorld();
// Date 2 has a title of its own, so it cannot simply FOLLOW date 1's
// approval as an identical repeat (owner answer 6) — it must wait.
[$g1, $g2] = pa_series(FA1, 'd12-series@p8', 'D12 series', ['count' => 2, 'titles' => ['D12 series', 'D12 series, its own details']]);
$rCal12    = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]));
$rSave12   = FeedAdmin::saveChoice($mysqli, $g1, 'series', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [P_ID]], 'seen' => [$g1 . ':' . pa_cf($g1)]]);
$choice12  = (int) ($rSave12['choiceId'] ?? 0);
$wait12    = pa_pending($g2);
pa_ok(
    'D12 set-up: the calendar at Selected groups [SG1]; a series choice [SG1 + P] with two list rows; date 1 (seen) shown, date 2 waiting under the choice',
    $rCal12['ok'] === true && $rSave12['ok'] === true && pa_ls($g1) === 'groups/series' && pa_ev($g2)['importSource'] === 'waiting'
    && $wait12 !== null && $wait12['origin'] === 'choice' && (int) $wait12['originID'] === $choice12 && $wait12['requestedLevel'] === 'groups'
    && (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE ownerType = 'choice' AND ownerID = ?", 'i', [$choice12])['n'] === 2,
    pa_show([$rCal12, $rSave12, pa_ls($g1), pa_ls($g2)])
);
$rRemove12 = FeedAdmin::removeChoice($mysqli, $g1, 'series', ORG_A, SA);
pa_ok(
    'D12 removing: the waiting row withdrawn, the list rows gone, the choice gone, both dates back to the calendar\'s own setting',
    $rRemove12['ok'] === true && $rRemove12['message'] === MSG_REMOVED_SERIES
    && $wait12 !== null && pa_status((int) $wait12['approvalID']) === 'withdrawn'
    && (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE ownerType = 'choice' AND ownerID = ?", 'i', [$choice12])['n'] === 0
    && pa_one('SELECT choiceID FROM tblExternalEventChoices WHERE choiceID = ?', 'i', [$choice12]) === null
    && pa_ls($g1) === 'groups/calendar' && pa_ls($g2) === 'groups/calendar',
    pa_show([$rRemove12, pa_ls($g1), pa_ls($g2)])
);
$rRemove12b = FeedAdmin::removeChoice($mysqli, $g1, 'series', ORG_A, SA);
pa_ok('D12 removing it again -> not_found, "There is no choice to remove."', $rRemove12b['status'] === 'not_found' && $rRemove12b['message'] === 'There is no choice to remove.', pa_show($rRemove12b));

// =============================================================================
// D13 — deleting a rule (C10 item 7) — the plan's combined scenario
// =============================================================================
pa_heading('D13 — deleting a rule');
pa_resetWorld();
$dr1     = pa_event(FA1, 'd13-1@p8', 'D13 outreach one');
$dr2     = pa_event(FA1, 'd13-2@p8', 'D13 outreach two');
$rCal13  = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]));
$rRule13 = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D13 rule', 'audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [P_ID]], 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D13 outreach']]]);
$rule13  = (int) ($rRule13['ruleId'] ?? 0);
$w13a    = pa_pending($dr1);
$w13b    = pa_pending($dr2);
pa_ok(
    'D13 set-up: a Selected-groups rule [SG1 + P] on a Selected-groups [SG1] calendar leaves both events waiting under the rule, with its two list rows',
    $rCal13['ok'] === true && $rRule13['ok'] === true && $rRule13['message'] === 'Saved. 2 events of this calendar are waiting for approval.'
    && $w13a !== null && $w13b !== null && $w13a['origin'] === 'rule' && (int) $w13a['originID'] === $rule13
    && (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE ownerType = 'rule' AND ownerID = ?", 'i', [$rule13])['n'] === 2,
    pa_show([$rCal13, $rRule13])
);
$rDelete13 = FeedAdmin::deleteRule($mysqli, $rule13, FA1, ORG_A, SA);
pa_ok(
    'D13 deleting: both waiting rows withdrawn, the list rows gone, the rule and its conditions gone, the events back to the calendar\'s setting',
    $rDelete13['ok'] === true && $rDelete13['message'] === "Rule deleted. Events it was showing now follow any other rule that matches them, or else the calendar's own setting."
    && $w13a !== null && $w13b !== null && pa_status((int) $w13a['approvalID']) === 'withdrawn' && pa_status((int) $w13b['approvalID']) === 'withdrawn'
    && (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE ownerType = 'rule' AND ownerID = ?", 'i', [$rule13])['n'] === 0
    && pa_one('SELECT ruleID FROM tblExternalFeedRules WHERE ruleID = ?', 'i', [$rule13]) === null
    && pa_one('SELECT conditionID FROM tblExternalRuleConditions WHERE ruleID = ?', 'i', [$rule13]) === null
    && pa_ls($dr1) === 'groups/calendar' && pa_ls($dr2) === 'groups/calendar',
    pa_show($rDelete13)
);
$rDelete13b = FeedAdmin::deleteRule($mysqli, $rule13, FA1, ORG_A, SA);
pa_ok('D13 deleting it again -> not_found, "That rule could not be found."', $rDelete13b['status'] === 'not_found' && $rDelete13b['message'] === MSG_NF_RULE, pa_show($rDelete13b));
// When ANOTHER rule also matches, a deleted rule's events follow that rule,
// not the calendar (fix round 7, check round 7 L2): the resolver takes the
// narrowest matching rule. An Everyone calendar; a Members rule and a
// narrower Selected-groups rule both match; the event follows the groups
// rule. Deleting that one leaves it on the Members rule — not Everyone.
pa_resetWorld();
$dr13x   = pa_event(FA2, 'd13x@p8', 'D13x shared');
$rWide13 = FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, ['name' => 'D13x members', 'audienceLevel' => 'members', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D13x']]]);
$rNarrow13 = FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, ['name' => 'D13x groups', 'audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]], 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D13x']]]);
$lsBefore13 = pa_ls($dr13x);
$rDelete13x = FeedAdmin::deleteRule($mysqli, (int) ($rNarrow13['ruleId'] ?? 0), FA2, ORG_A, SA);
pa_ok(
    'D13 deleting the narrower of two matching rules: the event now follows the OTHER rule (Members), not the calendar (Everyone), and the message says exactly that it may',
    ($rWide13['ok'] ?? false) === true && ($rNarrow13['ok'] ?? false) === true && $lsBefore13 === 'groups/rule'
    && $rDelete13x['ok'] === true && pa_ls($dr13x) === 'members/rule'
    && $rDelete13x['message'] === "Rule deleted. Events it was showing now follow any other rule that matches them, or else the calendar's own setting.",
    pa_show([$lsBefore13, pa_ls($dr13x), $rDelete13x['message'] ?? null])
);

// =============================================================================
// D14 — rule refusals (C10 item 10)
// =============================================================================
pa_heading('D14 — rule refusals');
pa_resetWorld();
$base14 = ['name' => 'D14 rule', 'audienceLevel' => 'public', 'isActive' => '1'];
$good14 = ['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'x'];
$cases14 = [
    'only an exception'           => [$base14 + ['conditions' => [$good14 + ['isException' => 1]]], 'A rule needs at least one condition that events must match.'],
    'a zero-width-space value'    => [$base14 + ['conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => "\u{200B}"]]], 'One of the values is empty once spaces and invisible characters are removed.'],
    'a value of invalid bytes'    => [$base14 + ['conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => "Caf\xC3"]]], 'One of the values contains characters that cannot be read. Please type it again.'],
    'category with "contains"'    => [$base14 + ['conditions' => [['matchField' => 'category', 'matchType' => 'contains', 'matchValue' => 'x']]], "A category condition can only use 'is exactly'."],
    'category with "word"'        => [$base14 + ['conditions' => [['matchField' => 'category', 'matchType' => 'word', 'matchValue' => 'x']]], "A category condition can only use 'is exactly'."],
    'the last day before the first' => [$base14 + ['conditions' => [$good14], 'fromDate' => (new DateTimeImmutable('+10 days'))->format('Y-m-d'), 'toDate' => (new DateTimeImmutable('+1 day'))->format('Y-m-d')], 'The last day cannot be before the first day.'],
    '11 conditions'               => [$base14 + ['conditions' => array_fill(0, 11, $good14)], 'A rule can have at most 10 conditions and 10 exceptions.'],
    'the field "description"'     => [$base14 + ['conditions' => [['matchField' => 'description', 'matchType' => 'contains', 'matchValue' => 'x']]], 'Choose a field and a way of matching from the lists.'],
    'the match "starts"'          => [$base14 + ['conditions' => [['matchField' => 'title', 'matchType' => 'starts', 'matchValue' => 'x']]], 'Choose a field and a way of matching from the lists.'],
    'an empty name'               => [['name' => '', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [$good14]], 'Please give the rule a name.'],
    'a name of only zero-width spaces' => [['name' => "\u{200B}", 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [$good14]], 'Please give the rule a name.'],
];
$fp14 = pa_fp();
foreach ($cases14 as $label => [$post14, $want14]) {
    $rs = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $post14);
    $rp = FeedAdmin::previewRule($mysqli, FA1, 0, ORG_A, SA, $post14);
    pa_ok(
        "D14 refused by BOTH saveRule and previewRule, word for word: {$label}",
        $rs['ok'] === false && $rs['status'] === 'refused' && $rs['message'] === $want14 && $rp['ok'] === false && $rp['message'] === $want14,
        pa_show([$rs, $rp])
    );
}
pa_ok('D14 none of those created a rule or changed anything', (int) pa_one('SELECT COUNT(*) AS n FROM tblExternalFeedRules WHERE feedID = ?', 'i', [FA1])['n'] === 0 && pa_fp() === $fp14);
$rSpares = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $base14 + ['conditions' => [$good14, ['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => '   ']]]);
$saved14 = FeedAdmin::rule($mysqli, (int) ($rSpares['ruleId'] ?? 0), FA1, ORG_A);
pa_ok('D14 a spaces-only value row is IGNORED (a spare row), not refused: saved with one condition', $rSpares['ok'] === true && $saved14 !== null && count($saved14['conditions']) === 1, pa_show($rSpares));
pa_ok(
    'D14 the guard: the rule saveRule accepted is one FeedResolver::ruleIsValid() accepts too',
    $saved14 !== null && FeedResolver::ruleIsValid(['audienceLevel' => $saved14['audienceLevel'], 'detailLevel' => $saved14['detailLevel'], 'conditions' => $saved14['conditions']]) === true
);
$rAgain14 = FeedAdmin::saveRule($mysqli, FA1, (int) ($rSpares['ruleId'] ?? 0), ORG_A, SA, $base14 + ['conditions' => [$good14]]);
pa_ok(
    'D14 the rule\'s createdAt (when made) and updatedAt (when saved again) are within 5 s of the database\'s UTC now (fix round 3; the session runs 13 hours ahead)',
    $rAgain14['ok'] === true
    && pa_nearDbNow('tblExternalFeedRules', 'createdAt', 'ruleID = ?', 'i', [(int) ($rSpares['ruleId'] ?? 0)])
    && pa_nearDbNow('tblExternalFeedRules', 'updatedAt', 'ruleID = ?', 'i', [(int) ($rSpares['ruleId'] ?? 0)]),
    pa_show($rAgain14)
);

// =============================================================================
// D15 — labels are complete
// =============================================================================
pa_heading('D15 — labels are complete');
pa_ok('D15 array_keys(OUTCOME_LABELS) === FeedResolver::PREVIEW_OUTCOMES (same words, same order)', array_keys(FeedAdmin::OUTCOME_LABELS) === FeedResolver::PREVIEW_OUTCOMES, pa_show(array_keys(FeedAdmin::OUTCOME_LABELS)));

function pa_enumValues(string $table, string $column): array
{
    $row = pa_one('SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', [$table, $column]);
    preg_match_all("/'([^']*)'/", (string) ($row['t'] ?? ''), $m);

    return $m[1];
}

$sourceEnum = pa_enumValues('tblEvents', 'importSource');
$reasonEnum = pa_enumValues('tblExternalEventApprovals', 'reason');
pa_ok('D15 SOURCE_LABELS keys = the tblEvents.importSource ENUM', $sourceEnum !== [] && array_diff($sourceEnum, array_keys(FeedAdmin::SOURCE_LABELS)) === [] && array_diff(array_keys(FeedAdmin::SOURCE_LABELS), $sourceEnum) === [], pa_show($sourceEnum));
pa_ok('D15 REASON_LABELS keys = the tblExternalEventApprovals.reason ENUM', $reasonEnum !== [] && array_diff($reasonEnum, array_keys(FeedAdmin::REASON_LABELS)) === [] && array_diff(array_keys(FeedAdmin::REASON_LABELS), $reasonEnum) === [], pa_show($reasonEnum));
// Part 10 of #514, check R7: EVERY kind of list entry the database can hold
// is one the forms offer — and the other way round. Read twice: from the
// database this run was given (what really enforces it), and from the text
// of web/_sql/full_schema.sql (what a new installation will be built from),
// so a kind added to either, with ExternalAudience forgotten, fails here
// instead of being quietly "not used by this version" for ever. The rule
// and its fast copy must learn a new kind too; D17's oracle is what notices
// when only one of them has.
$kindEnum   = pa_enumValues('tblExternalAudienceMembers', 'kind');
$schemaText = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_sql' . DIRECTORY_SEPARATOR . 'full_schema.sql');
$schemaKinds = [];
if (preg_match('/CREATE TABLE IF NOT EXISTS `tblExternalAudienceMembers` \(.*?`kind`\s+ENUM\(([^)]*)\)/s', $schemaText, $mKind) === 1) {
    preg_match_all("/'([^']*)'/", $mKind[1], $mKinds);
    $schemaKinds = $mKinds[1];
}
$sameKinds = static fn (array $have): bool => $have !== [] && array_diff($have, ExternalAudience::OFFERED_KINDS) === [] && array_diff(ExternalAudience::OFFERED_KINDS, $have) === [];
pa_ok(
    'D15 ExternalAudience::OFFERED_KINDS = the tblExternalAudienceMembers.kind ENUM, in this database AND in full_schema.sql (six kinds, none twice)',
    $sameKinds($kindEnum) === true && $sameKinds($schemaKinds) === true
    && count(ExternalAudience::OFFERED_KINDS) === 6 && count(array_unique(ExternalAudience::OFFERED_KINDS)) === 6,
    pa_show(['database' => $kindEnum, 'full_schema.sql' => $schemaKinds, 'offered' => ExternalAudience::OFFERED_KINDS])
);
pa_ok(
    'D15 the two C10 item 9 wordings, exactly',
    FeedAdmin::OUTCOME_LABELS['duplicate_not_applied'] === 'Listed twice in the outside calendar; rules are not applied to it'
    && FeedAdmin::OUTCOME_LABELS['declined_before'] === 'Declined before, with the same details'
);
$sourceOk = true;
foreach (FeedAdmin::SOURCE_LABELS as $key => $words) {
    if ($key !== 'rule' && FeedAdmin::sourceLabel($key) !== $words) {
        $sourceOk = false;
    }
}
pa_ok(
    'D15 sourceLabel(): every source in words; a rule named; a deleted rule said so; no source "Not worked out yet" (checker LOW 1)',
    $sourceOk === true && FeedAdmin::sourceLabel('rule', 'Youth events') === 'Rule: Youth events'
    && FeedAdmin::sourceLabel('rule', null) === 'A rule that has since been deleted'
    && FeedAdmin::sourceLabel(null) === 'Not worked out yet' && FeedAdmin::sourceLabel('nonsense') === 'Not worked out yet'
);

// =============================================================================
// D16 — the preview writes nothing
// =============================================================================
pa_heading('D16 — the preview writes nothing');
pa_resetWorld();
$p16a = pa_event(FA1, 'd16-1@p8', 'D16 alpha match');
$p16b = pa_event(FA1, 'd16-2@p8', 'D16 alpha match two');
$p16c = pa_event(FA1, 'd16-3@p8', 'D16 alpha match three');
$c16  = FeedAdmin::saveChoice($mysqli, $p16b, 'date', ORG_A, SA, ['audienceLevel' => 'members']);
$fp16 = pa_fp();
$rPrev = FeedAdmin::previewRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D16', 'audienceLevel' => 'public', 'apiOptOut' => '1', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D16 alpha']]]);
$outcomes16 = [];
$labelsOk   = true;
foreach (($rPrev['lines'] ?? []) as $line) {
    $outcomes16[(int) $line['eventID']] = $line['outcome'];
    if ($line['label'] !== (FeedAdmin::OUTCOME_LABELS[$line['outcome']] ?? null)) {
        $labelsOk = false;
    }
}
ksort($outcomes16);
pa_ok(
    'D16 the preview: three lines with the right outcomes and labels, the API box reported, NOTHING written',
    $c16['ok'] === true && $rPrev['ok'] === true && $rPrev['message'] === 'What this rule would do now (nothing has been saved)'
    && $outcomes16 === [$p16a => 'waits_for_approval', $p16b => 'covered_by_choice', $p16c => 'waits_for_approval']
    && $labelsOk === true && ($rPrev['apiOptOut'] ?? null) === 1 && pa_fp() === $fp16,
    pa_show($rPrev)
);

// =============================================================================
// D17 — waiting counts are live (C10 item 5)
// =============================================================================
pa_heading('D17 — waiting counts are live');
pa_resetWorld();
$w17a = pa_event(FA1, 'd17-1@p8', 'D17 wants it one');
$w17b = pa_event(FA1, 'd17-2@p8', 'D17 wants it two');
$r17  = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D17', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D17 wants it']]]);
pa_run(FA1, 'a run that says nothing is waiting', 0); // the newest run row says 0 — the count must NOT come from it
$fa1In17 = array_values(array_filter(FeedAdmin::calendars($mysqli, ORG_A), static fn (array $c): bool => (int) $c['feedID'] === FA1))[0] ?? [];
pa_ok(
    'D17 two waiting rows while the newest run row says 0: calendar()[waiting] = 2, and calendars() agrees',
    $r17['ok'] === true && (FeedAdmin::calendar($mysqli, FA1, ORG_A)['waiting'] ?? -1) === 2 && ($fa1In17['waiting'] ?? -1) === 2
);
$p17 = pa_pending($w17a);
$d17 = ($p17 === null) ? ['decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, 'declined', '', [pa_item($p17)]);
pa_ok('D17 after one is decided, waiting drops to 1', $d17['decided'] === 1 && (FeedAdmin::calendar($mysqli, FA1, ORG_A)['waiting'] ?? -1) === 1);

// THE "CHANGED WHO CAN SEE IT" COUNT, CHECKED AGAINST THE REAL RULE (the
// owner's decision of 25 September 2026, fix round 3). FeedAdmin keeps its
// own short copy of the visibility rule (FeedAdmin::whoCanSee()) to count
// the events whose audience a save really changed. A copy can drift from
// the rule it copies, so every check here is an ORACLE check: before and
// after the save, the REAL rule — EventVisibility::where(), exactly as every
// page uses it — is asked about each of the calendar's events for EVERY
// account in the made-up world, in each mode that has a person (session,
// token, invite; and account 0, nobody signed in, in each of them), and in
// each mode that has none (the public website, a signed-out visitor, an API
// key, the bulk members' list). The message's count must equal EXACTLY the
// number of events whose answer changed for anybody, and the expected
// number written here.
//
// Fix round 4 (check round 4, M2): this used to ask a fixed panel of seven
// viewers. The panel held no leadership-role holder and nobody in a group
// other than SG1, so a count that left roles out, or read every group as
// the same group, still passed (the checker's faults C4-01 and C4-02).
// Asking every account closes that — but ONLY while the world holds every
// kind of person the rule treats differently. That is why pa_resetWorld()
// adds L, X, E, Q, O and F, and why D17 first checks they are there and
// that the real rule tells them apart. PART 10 OF #514 (13 October 2026)
// made the role, user-group and department kinds match people, and added
// holders of those kinds to the world for exactly this reason: RH, UH, DH,
// XF, XO, DN, XB and XI (see the constants, and the "R5" cases further
// down). A SEVENTH kind would need the same — FeedAdmin::whoCanSee() says so.
//
// Fix round 5 (check round 5, M1): comparing CHANGE COUNTS is not enough.
// One person counted wrongly hides whenever somebody else on the same event
// also moves — the event is "changed" either way. So every oracle check now
// also compares the copy's OWN ANSWER with the real rule's, directly, for
// every event, before and after the save (pa_copySig() and pa_realSigs()):
// for Selected groups the copy's set of account numbers must equal exactly
// the accounts the real rule lets in, one by one. And the world gained the
// people round 4 lacked (a switched-off role's holder, a term removed the
// way leadership/delete.php removes one, terms starting and ending today
// with controls a day either side, a group of only ended and pending rows),
// each with a case where they are the only one who moves, plus two cases
// with the database clock pinned just after local midnight.
//
// The B-cases below (B1-B6) are list edits that let nobody new in and shut
// nobody out, so the true count is 0. They were written in fix round 4
// part A, expecting 0, and FAILED there: the copy then compared list
// ENTRIES, not the people they let in (check round 4, M1). Part B (the
// owner's decision of 27 September 2026) made the copy compare PEOPLE, and
// they pass. They are the cases that would catch a return to the old way.

/**
 * Every view the oracle asks about: name => [mode, account]. The accounts
 * are READ FROM THE DATABASE, not listed here, so an account added to the
 * world later is asked about without anybody remembering to add it.
 *
 * @return array<string, array{0:string, 1:int}>
 */
function pa_fullViews(): array
{
    $views = [
        'website'      => [EventVisibility::MODE_WEBSITE, 0],
        'signed out'   => [EventVisibility::MODE_ANONYMOUS, 0],
        'API key'      => [EventVisibility::MODE_KEY, 0],
        'bulk members' => [EventVisibility::MODE_BULK_MEMBERS, 0],
    ];
    $accounts = [0];
    foreach (pa_q('SELECT userID FROM tblUsers WHERE userID BETWEEN 910000 AND 910099 ORDER BY userID') as $r) {
        $accounts[] = (int) $r['userID'];
    }
    foreach ($accounts as $u) {
        foreach ([EventVisibility::MODE_SESSION, EventVisibility::MODE_TOKEN, EventVisibility::MODE_INVITE] as $mode) {
            $views[$mode . ':' . $u] = [$mode, $u];
        }
    }

    return $views;
}

/**
 * eventID => the names of every view the REAL rule lets see it, in the
 * fixed order of pa_fullViews() (so two readings compare as plain lists).
 *
 * The person modes are asked twice: once per account through `where()`,
 * and once for all accounts together through `whereForColumn()` — the form
 * a job that checks many recipients in one statement uses. The two must
 * agree. A disagreement is not a count fault but it would make "the truth"
 * uncertain, so it is recorded in $GLOBALS['pa_formsDisagree'] and fails
 * the oracle check that was reading.
 *
 * `$today` is the date given to the real rule (null = the organisation's
 * date now, by PHP's clock).
 *
 * @return array<int, list<string>>
 */
function pa_full(int $feedId, ?string $today = null): array
{
    // The date the real rule is given: the organisation's date by default;
    // the pinned-clock cases pass the pinned date instead (fix round 5).
    $today ??= pa_todayLocal()->format('Y-m-d');
    $views = pa_fullViews();
    $out   = [];
    foreach (pa_q('SELECT eventID FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 0 ORDER BY eventID', 'i', [$feedId]) as $r) {
        $out[(int) $r['eventID']] = [];
    }
    foreach ($views as $name => [$mode, $viewer]) {
        $w = EventVisibility::where('e', $mode, $viewer, $today);
        foreach (pa_q('SELECT e.eventID FROM tblEvents e WHERE e.externalFeedID = ? AND e.isDeleted = 0' . $w['sql'], 'i' . $w['types'], array_merge([$feedId], $w['params'])) as $r) {
            $out[(int) $r['eventID']][] = $name;
        }
    }
    foreach ([EventVisibility::MODE_SESSION, EventVisibility::MODE_TOKEN, EventVisibility::MODE_INVITE] as $mode) {
        $w = EventVisibility::whereForColumn('e', 'u.userID', $mode, $today);
        $byColumn = [];
        foreach (pa_q(
            'SELECT e.eventID, u.userID FROM tblEvents e JOIN tblUsers u ON u.userID BETWEEN 910000 AND 910099 '
            . 'WHERE e.externalFeedID = ? AND e.isDeleted = 0' . $w['sql'],
            'i' . $w['types'],
            array_merge([$feedId], $w['params'])
        ) as $r) {
            $byColumn[(int) $r['eventID'] . '|' . $mode . ':' . (int) $r['userID']] = true;
        }
        foreach ($out as $id => $names) {
            foreach ($views as $name => [$viewMode, $viewer]) {
                if ($viewMode !== $mode || $viewer === 0) {
                    continue;
                }
                $saysWhere  = in_array($name, $names, true);
                $saysColumn = isset($byColumn[$id . '|' . $name]);
                if ($saysWhere !== $saysColumn) {
                    $GLOBALS['pa_formsDisagree'][] = 'event ' . $id . ', ' . $name . ': where() ' . ($saysWhere === true ? 'lets in' : 'keeps out')
                        . ', whereForColumn() ' . ($saysColumn === true ? 'lets in' : 'keeps out');
                }
            }
        }
    }

    return $out;
}

/**
 * The COPY's own answer — FeedAdmin::whoCanSee(), given the date
 * FeedAdmin::ruleToday() works out — for every event of one calendar.
 *
 * Both methods are private, and stay private: they are parts of a save, not
 * something any other code should call, and making them public only for a
 * test would invite exactly that. So they are reached here through
 * reflection, the way D21 already reaches inFeedLock(). Reflection reads
 * the method as it is; it changes nothing in FeedAdmin.
 *
 * `ruleToday()` is asked too (not given the test's own date) so that a
 * wrong "today" in the copy shows up here as a wrong answer.
 *
 * @return array<int,string> eventID => the copy's signature
 */
function pa_copySig(int $feedId): array
{
    global $mysqli;
    $siteId = (int) pa_one('SELECT siteID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feedId])['siteID'];
    $today  = (new ReflectionMethod(FeedAdmin::class, 'ruleToday'))->invoke(null, $mysqli, $siteId);

    return (new ReflectionMethod(FeedAdmin::class, 'whoCanSee'))->invoke(null, $mysqli, $feedId, $siteId, $today);
}

/**
 * The REAL rule's answers (pa_full()), put into the copy's own words, one
 * text per event, so the two can be compared directly (fix round 5, check
 * round 5 M1). Built only from what the real rule said about each view,
 * and from EventVisibility::isAdminOfSite() for who the administrators are.
 *
 * WHAT IT READS, in this order:
 *   - nobody at all sees it                           -> "nobody"
 *   - the signed-out visitor sees it                  -> "public|website=W|keys=K",
 *       W and K being whether the website and an API key see it
 *   - else the newsletter (bulk members) list sees it -> "members"
 *   - else: the accounts that see it in TOKEN mode — the one person mode
 *     with no administrator shortcut, so exactly the people the list lets
 *     in, administrators included when they are on it. None -> "admins";
 *     some -> "groups|<their numbers, sorted>".
 *
 * WHAT IT REFUSES TO IGNORE (fix round 6, check round 6 L3). The copy's
 * words promise more than the one view each branch looks at, so where the
 * real rule says something those words cannot describe, the answer is
 * "MIXED: …", which no copy signature can equal, and the direct check
 * fails instead of the difference being thrown away:
 *   - the website or an API key sees an event the signed-out visitor does
 *     not (the copy's words give those two a say only at Public);
 *   - at Public, some person view (any account, or nobody signed in, in
 *     session, token or invite mode) or the newsletter list does NOT see
 *     it (the copy's "public" means everybody);
 *   - below Public: invite mode says something different from session mode
 *     for any account, or the non-administrators who see it in session
 *     mode are not exactly those of token mode (the copy has one people set
 *     for all person modes).
 *
 * What it still does not compare, said plainly: at Members, it does not
 * check person by person that each member sees the event and nobody else
 * does (only that the newsletter list sees it and the signed-out visitor,
 * the website and API keys do not). A rule change there is still caught
 * by the count comparison whenever it moves somebody, since the count and
 * the answer both come from the real rule.
 *
 * @param array<int, list<string>> $full pa_full()'s answer
 *
 * @return array<int,string> eventID => signature
 */
function pa_realSigs(array $full): array
{
    global $mysqli;
    $admins = [];
    foreach (pa_q('SELECT userID FROM tblUsers WHERE userID BETWEEN 910000 AND 910099') as $r) {
        if (EventVisibility::isAdminOfSite($mysqli, (int) $r['userID'], ORG_A) === true) {
            $admins[(int) $r['userID']] = true;
        }
    }
    // Every person view the oracle asks about (every account, and nobody
    // signed in, in session, token and invite), from the same list pa_full()
    // uses — so "everybody" here means exactly who the oracle asked.
    $personViews = [];
    foreach (pa_fullViews() as $name => [$mode, $unusedViewer]) {
        if (in_array($mode, [EventVisibility::MODE_SESSION, EventVisibility::MODE_TOKEN, EventVisibility::MODE_INVITE], true) === true) {
            $personViews[] = $name;
        }
    }
    $people = static function (array $names, string $mode): array {
        $ids = [];
        foreach ($names as $name) {
            if (str_starts_with($name, $mode . ':') === true && (int) substr($name, strlen($mode) + 1) > 0) {
                $ids[] = (int) substr($name, strlen($mode) + 1);
            }
        }
        sort($ids);

        return $ids;
    };
    $out = [];
    foreach ($full as $id => $names) {
        $has = array_flip($names);
        if ($names === []) {
            $sig = 'nobody';
        } elseif (isset($has['signed out']) === true) {
            $missing = array_values(array_filter($personViews, static fn (string $v): bool => isset($has[$v]) === false));
            if ($missing !== [] || isset($has['bulk members']) === false) {
                $sig = 'MIXED: an Everyone event not seen by [' . implode(', ', $missing) . (isset($has['bulk members']) === false ? ', bulk members' : '') . ']';
            } else {
                $sig = 'public|website=' . (isset($has['website']) === true ? 1 : 0) . '|keys=' . (isset($has['API key']) === true ? 1 : 0);
            }
        } elseif (isset($has['website']) === true || isset($has['API key']) === true) {
            $sig = 'MIXED: the website or an API key sees an event the signed-out visitor does not';
        } elseif (isset($has['bulk members']) === true) {
            $sig = 'members';
        } else {
            $session = $people($names, EventVisibility::MODE_SESSION);
            $token   = $people($names, EventVisibility::MODE_TOKEN);
            $invite  = $people($names, EventVisibility::MODE_INVITE);
            $notAdmin = static fn (array $ids): array => array_values(array_filter($ids, static fn (int $u): bool => isset($admins[$u]) === false));
            if ($invite !== $session) {
                $sig = 'MIXED: invite mode says [' . implode(',', $invite) . '], session mode [' . implode(',', $session) . ']';
            } elseif ($notAdmin($session) !== $notAdmin($token)) {
                $sig = 'MIXED: non-administrators in session mode [' . implode(',', $notAdmin($session)) . '], in token mode [' . implode(',', $notAdmin($token)) . ']';
            } elseif ($token === []) {
                $sig = 'admins';
            } else {
                $sig = 'groups|' . implode(',', $token);
            }
        }
        $out[$id] = $sig;
    }

    return $out;
}

/**
 * Run a save between two full readings. TWO checks:
 *   1. the count it reports, the number of events whose answer changed for
 *      anybody, and `$expected` all agree, and the message is exactly
 *      `$message` (on a failure the detail names, per event, exactly which
 *      views gained or lost it);
 *   2. (fix round 5) the copy's own answer for every event equals the real
 *      rule's, BEFORE and AFTER the save — so one person counted wrongly is
 *      caught even when somebody else on the same event also moves.
 *
 * `$today` is the date the real rule is given (null = the organisation's
 * date now); the pinned-clock cases pass the pinned date.
 */
function pa_oracle(string $label, int $feedId, callable $save, int $expected, string $message, ?string $today = null): array
{
    $GLOBALS['pa_formsDisagree'] = [];
    $before     = pa_full($feedId, $today);
    $copyBefore = pa_copySig($feedId);
    $result     = $save();
    $after      = pa_full($feedId, $today);
    $copyAfter  = pa_copySig($feedId);
    $changed = 0;
    $moved   = [];
    foreach ($before + $after as $id => $unused) {
        $b = $before[$id] ?? [];
        $a = $after[$id] ?? [];
        if ($b !== $a) {
            $changed++;
            $moved[$id] = 'gained [' . implode(', ', array_diff($a, $b)) . '] lost [' . implode(', ', array_diff($b, $a)) . ']';
        }
    }
    pa_ok(
        $label,
        ($result['ok'] ?? false) === true && ($result['visibilityChanged'] ?? -1) === $expected && $changed === $expected
        && ($result['message'] ?? '') === $message && $GLOBALS['pa_formsDisagree'] === [],
        pa_show([
            'count' => $result['visibilityChanged'] ?? null, 'events whose audience really changed' => $changed, 'expected' => $expected,
            'message' => $result['message'] ?? null, 'who moved' => $moved, 'where() and whereForColumn() disagree' => $GLOBALS['pa_formsDisagree'],
        ])
    );
    $differ = [];
    foreach ([['before', $copyBefore, pa_realSigs($before)], ['after', $copyAfter, pa_realSigs($after)]] as [$when, $copy, $real]) {
        foreach ($copy + $real as $id => $unused) {
            if (($copy[$id] ?? null) !== ($real[$id] ?? null)) {
                $differ[] = $when . ', event ' . $id . ': the copy says "' . ($copy[$id] ?? '(no answer)') . '", the real rule says "' . ($real[$id] ?? '(no answer)') . '"';
            }
        }
    }
    pa_ok($label . ' — and the copy\'s own answer equals the real rule\'s for every event, before and after', $differ === [], pa_show($differ));

    return $result;
}

/** A fresh world, `$n` events on `$feedId` worked out at the calendar's own setting, their IDs. */
function pa_quietWorld(int $n, string $tag, int $feedId = FA1): array
{
    global $mysqli;
    pa_resetWorld();
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $ids[] = pa_event($feedId, $tag . '-' . $i . '@p8', $tag . ' event ' . $i);
    }
    FeedAdmin::saveCategoryMap($mysqli, $feedId, ORG_A, SA, ['map' => [['word' => 'x', 'choice' => 'none']]]);

    return $ids;
}

$rule17 = static fn (string $name, string $level, string $match, array $extra = []): array => $extra + [
    'name' => $name, 'audienceLevel' => $level, 'isActive' => '1',
    'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => $match]],
];

// ---- The oracle's world holds every kind of viewer, and the real rule tells them apart (fix round 4, M2) ----
// The world's role terms start and end TODAY (fix round 5). If this part
// began in the last two minutes before the organisation's midnight, "today"
// could move between building a world and asking the rule, and a correct
// copy could fail. So wait (at most two minutes) until just after midnight,
// and say so.
$toMidnight17 = pa_todayLocal()->modify('tomorrow')->getTimestamp() - time();
if ($toMidnight17 >= 0 && $toMidnight17 < 120) {
    echo 'NOTE — D17 waits ' . ($toMidnight17 + 5) . " s for the organisation's midnight to pass, so terms that start or end today cannot move mid-check.\n";
    sleep($toMidnight17 + 5);
}
pa_resetWorld();
$today17  = pa_todayLocal()->format('Y-m-d');
$views17  = pa_fullViews();
$kinds17  = [
    'L holds RA1 now'                        => pa_one('SELECT 1 AS x FROM tblLeadershipAssignments WHERE userID = ? AND roleID = ? AND siteID = ? AND isActive = 1 AND startDate IS NULL AND endDate IS NULL', 'iii', [L_ID, RA1, ORG_A]),
    'X is in SG3, which is on'               => pa_one("SELECT 1 AS x FROM tblSmallGroupMembers JOIN tblSmallGroups sg ON sg.groupID = tblSmallGroupMembers.groupID AND sg.isActive = 1 WHERE tblSmallGroupMembers.userID = ? AND tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.status = 'active'", 'ii', [X_ID, SG3]),
    'E left A but kept an SG1 row and a term' => pa_one("SELECT 1 AS x FROM tblUserSites us JOIN tblSmallGroupMembers ON tblSmallGroupMembers.userID = us.userID AND tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.status = 'active' JOIN tblLeadershipAssignments la ON la.userID = us.userID AND la.roleID = ? AND la.endDate IS NULL WHERE us.userID = ? AND us.siteID = ? AND us.isActive = 0", 'iiii', [SG1, RA1, E_ID, ORG_A]),
    'E is an active member of B now'         => pa_one('SELECT 1 AS x FROM tblUserSites WHERE userID = ? AND siteID = ? AND isActive = 1', 'ii', [E_ID, ORG_B]),
    'PB, of B only, has an old RA1 term in A' => pa_one('SELECT 1 AS x FROM tblLeadershipAssignments la WHERE la.userID = ? AND la.roleID = ? AND la.siteID = ? AND NOT EXISTS (SELECT 1 FROM tblUserSites us WHERE us.userID = la.userID AND us.siteID = ?)', 'iiii', [PB, RA1, ORG_A, ORG_A]),
    'I is switched off, yet a member of A holding RA2' => pa_one('SELECT 1 AS x FROM tblUsers u JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 JOIN tblLeadershipAssignments la ON la.userID = u.userID AND la.roleID = ? AND la.endDate IS NULL WHERE u.userID = ? AND u.isActive = 0', 'iii', [ORG_A, RA2, I_ID]),
    'Q left SG1 and Q\'s term ended'         => pa_one("SELECT 1 AS x FROM tblSmallGroupMembers JOIN tblLeadershipAssignments la ON la.userID = tblSmallGroupMembers.userID AND la.roleID = ? AND la.endDate < ? WHERE tblSmallGroupMembers.userID = ? AND tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.status = 'ended'", 'isii', [RA1, $today17, Q_ID, SG1]),
    'O is in SG2, which is off'              => pa_one("SELECT 1 AS x FROM tblSmallGroupMembers JOIN tblSmallGroups sg ON sg.groupID = tblSmallGroupMembers.groupID AND sg.isActive = 0 WHERE tblSmallGroupMembers.userID = ? AND tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.status = 'active'", 'ii', [O_ID, SG2]),
    'F\'s term has not started; F waits to join SG3' => pa_one("SELECT 1 AS x FROM tblSmallGroupMembers JOIN tblLeadershipAssignments la ON la.userID = tblSmallGroupMembers.userID AND la.roleID = ? AND la.startDate > ? WHERE tblSmallGroupMembers.userID = ? AND tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.status = 'pending'", 'isii', [RA1, $today17, F_ID, SG3]),
    'RA2 is held by I alone'                 => pa_one('SELECT COUNT(*) AS n FROM tblLeadershipAssignments WHERE roleID = ? AND userID <> ?', 'ii', [RA2, I_ID])['n'] === 0 ? ['x' => 1] : null,
    // Fix round 5 (check round 5, M1):
    'H holds a current term of RA3, which is switched off' => pa_one('SELECT 1 AS x FROM tblLeadershipAssignments la JOIN tblLeadershipRoles lr ON lr.roleID = la.roleID AND lr.isActive = 0 WHERE la.userID = ? AND la.roleID = ? AND la.isActive = 1 AND la.startDate IS NULL AND la.endDate IS NULL', 'ii', [H_ID, RA3]),
    'R\'s RA6 term was removed as leadership/delete.php does (isActive 0, no end date), and is RA6\'s only term' => pa_one('SELECT 1 AS x FROM tblLeadershipAssignments WHERE userID = ? AND roleID = ? AND isActive = 0 AND endDate IS NULL AND (SELECT COUNT(*) FROM tblLeadershipAssignments WHERE roleID = ?) = 1', 'iii', [R_ID, RA6, RA6]),
    'TS\'s RA4 term starts today; TY\'s ended yesterday; nobody else holds RA4' => pa_one('SELECT 1 AS x FROM tblLeadershipAssignments a JOIN tblLeadershipAssignments b ON b.roleID = a.roleID AND b.userID = ? AND b.endDate = ? AND b.startDate IS NULL WHERE a.userID = ? AND a.roleID = ? AND a.startDate = ? AND a.endDate IS NULL AND (SELECT COUNT(*) FROM tblLeadershipAssignments WHERE roleID = ?) = 2', 'isiisi', [TY_ID, pa_todayLocal()->modify('-1 day')->format('Y-m-d'), TS_ID, RA4, $today17, RA4]),
    'TE\'s RA5 term ends today; TT\'s starts tomorrow; nobody else holds RA5' => pa_one('SELECT 1 AS x FROM tblLeadershipAssignments a JOIN tblLeadershipAssignments b ON b.roleID = a.roleID AND b.userID = ? AND b.startDate = ? AND b.endDate IS NULL WHERE a.userID = ? AND a.roleID = ? AND a.endDate = ? AND a.startDate IS NULL AND (SELECT COUNT(*) FROM tblLeadershipAssignments WHERE roleID = ?) = 2', 'isiisi', [TT_ID, pa_todayLocal()->modify('+1 day')->format('Y-m-d'), TE_ID, RA5, $today17, RA5]),
    'SG4 is on and holds only Q (ended) and F (pending)' => pa_one("SELECT 1 AS x FROM tblSmallGroups sg WHERE sg.groupID = ? AND sg.isActive = 1 AND (SELECT GROUP_CONCAT(CONCAT(userID, ':', status) ORDER BY userID) FROM tblSmallGroupMembers WHERE groupID = sg.groupID) = ?", 'is', [SG4, Q_ID . ':ended,' . F_ID . ':pending']),
    // Fix round 6 (check round 6, L2):
    'LD leads SG5 (on), its only row' => pa_one("SELECT 1 AS x FROM tblSmallGroups sg WHERE sg.groupID = ? AND sg.isActive = 1 AND (SELECT GROUP_CONCAT(CONCAT(userID, ':', memberRole, ':', status)) FROM tblSmallGroupMembers WHERE groupID = sg.groupID) = ?", 'is', [SG5, LD_ID . ':leader:active']),
    'CL co-leads SG6 (on), its only row' => pa_one("SELECT 1 AS x FROM tblSmallGroups sg WHERE sg.groupID = ? AND sg.isActive = 1 AND (SELECT GROUP_CONCAT(CONCAT(userID, ':', memberRole, ':', status)) FROM tblSmallGroupMembers WHERE groupID = sg.groupID) = ?", 'is', [SG6, CL_ID . ':co-leader:active']),
    'LO leads SG2, which is switched off, and is in no other group' => pa_one("SELECT 1 AS x FROM tblSmallGroupMembers JOIN tblSmallGroups sg ON sg.groupID = tblSmallGroupMembers.groupID AND sg.isActive = 0 WHERE tblSmallGroupMembers.userID = ? AND tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.memberRole = 'leader' AND tblSmallGroupMembers.status = 'active' AND (SELECT COUNT(*) FROM tblSmallGroupMembers WHERE userID = ?) = 1", 'iii', [LO_ID, SG2, LO_ID]),
    'CO co-leads SG2, which is switched off, and is in no other group' => pa_one("SELECT 1 AS x FROM tblSmallGroupMembers JOIN tblSmallGroups sg ON sg.groupID = tblSmallGroupMembers.groupID AND sg.isActive = 0 WHERE tblSmallGroupMembers.userID = ? AND tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.memberRole = 'co-leader' AND tblSmallGroupMembers.status = 'active' AND (SELECT COUNT(*) FROM tblSmallGroupMembers WHERE userID = ?) = 1", 'iii', [CO_ID, SG2, CO_ID]),
    // Part 10 of #514 — roles, user groups and departments. Each statement
    // starts from the table that holds the HOLDING (and names tblUserGroups
    // with no short name: the column checker's trap, see UserGroups.php).
    'RH is an active member of A and holds RO1' => pa_one('SELECT 1 AS x FROM tblUserRoles ur JOIN tblUserSites us ON us.userID = ur.userID AND us.siteID = ur.siteID AND us.isActive = 1 JOIN tblUsers u ON u.userID = ur.userID AND u.isActive = 1 WHERE ur.userID = ? AND ur.roleID = ? AND ur.siteID = ?', 'iii', [RH_ID, RO1, ORG_A]),
    'UH is an active member of A in UG1, which is in use' => pa_one('SELECT 1 AS x FROM tblGroups g JOIN tblUserGroups ON tblUserGroups.groupID = g.groupID AND tblUserGroups.siteID = g.siteID JOIN tblUserSites us ON us.userID = tblUserGroups.userID AND us.siteID = g.siteID AND us.isActive = 1 WHERE tblUserGroups.userID = ? AND g.groupID = ? AND g.siteID = ? AND g.isActive = 1', 'iii', [UH_ID, UG1, ORG_A]),
    'DH is an active member of A in DP1, which is switched on' => pa_one('SELECT 1 AS x FROM tblDepts d JOIN tblUserDepts ud ON ud.deptID = d.deptID AND ud.siteID = d.siteID JOIN tblUserSites us ON us.userID = ud.userID AND us.siteID = d.siteID AND us.isActive = 1 WHERE ud.userID = ? AND d.deptID = ? AND d.siteID = ? AND d.isActive = 1', 'iii', [DH_ID, DP1, ORG_A]),
    'XF left A (membership row switched off) but still holds RO1 and is still in UG1 and DP1' => pa_one('SELECT 1 AS x FROM tblUserSites us JOIN tblUserRoles ur ON ur.userID = us.userID AND ur.roleID = ? JOIN tblUserGroups ON tblUserGroups.userID = us.userID AND tblUserGroups.groupID = ? JOIN tblUserDepts ud ON ud.userID = us.userID AND ud.deptID = ? WHERE us.userID = ? AND us.siteID = ? AND us.isActive = 0', 'iiiii', [RO1, UG1, DP1, XF_ID, ORG_A]),
    'XI is switched off, yet an active member of A holding RO1 and in UG1 and DP1' => pa_one('SELECT 1 AS x FROM tblUsers u JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 JOIN tblUserRoles ur ON ur.userID = u.userID AND ur.roleID = ? JOIN tblUserGroups ON tblUserGroups.userID = u.userID AND tblUserGroups.groupID = ? JOIN tblUserDepts ud ON ud.userID = u.userID AND ud.deptID = ? WHERE u.userID = ? AND u.isActive = 0', 'iiiii', [ORG_A, RO1, UG1, DP1, XI_ID]),
    'XO is an active member of A in UG2 (retired) and DP2 (switched off), and holds no role' => pa_one('SELECT 1 AS x FROM tblGroups g JOIN tblUserGroups ON tblUserGroups.groupID = g.groupID JOIN tblUserDepts ud ON ud.userID = tblUserGroups.userID JOIN tblDepts d ON d.deptID = ud.deptID AND d.isActive = 0 WHERE tblUserGroups.userID = ? AND g.groupID = ? AND g.isActive = 0 AND d.deptID = ? AND (SELECT COUNT(*) FROM tblUserRoles WHERE userID = ?) = 0', 'iiii', [XO_ID, UG2, DP2, XO_ID]),
    'DN is an active member of A in DP3 only, whose on/off flag is empty' => pa_one('SELECT 1 AS x FROM tblDepts d JOIN tblUserDepts ud ON ud.deptID = d.deptID WHERE ud.userID = ? AND d.deptID = ? AND d.isActive IS NULL AND (SELECT COUNT(*) FROM tblUserDepts WHERE userID = ?) = 1', 'iii', [DN_ID, DP3, DN_ID]),
    'XB is an active member of BOTH A and B' => pa_one('SELECT 1 AS x FROM tblUserSites a JOIN tblUserSites b ON b.userID = a.userID AND b.siteID = ? AND b.isActive = 1 WHERE a.userID = ? AND a.siteID = ? AND a.isActive = 1', 'iii', [ORG_B, XB_ID, ORG_A]),
    'XB holds B\'s role ROB and is in B\'s UGB and DPB — and holds nothing of A\'s' => pa_one('SELECT 1 AS x FROM tblUserRoles ur JOIN tblUserGroups ON tblUserGroups.userID = ur.userID AND tblUserGroups.groupID = ? AND tblUserGroups.siteID = ? JOIN tblUserDepts ud ON ud.userID = ur.userID AND ud.deptID = ? AND ud.siteID = ? WHERE ur.userID = ? AND ur.roleID = ? AND ur.siteID = ? AND (SELECT COUNT(*) FROM tblUserRoles WHERE userID = ? AND siteID = ?) = 0 AND (SELECT COUNT(*) FROM tblUserGroups WHERE userID = ? AND siteID = ?) = 0 AND (SELECT COUNT(*) FROM tblUserDepts WHERE userID = ? AND siteID = ?) = 0', 'iiiiiiiiiiiii', [UGB, ORG_B, DPB, ORG_B, XB_ID, ROB, ORG_B, XB_ID, ORG_A, XB_ID, ORG_A, XB_ID, ORG_A]),
    // One number, six kinds (added after part 10's independent check). The
    // six tables each hold a row numbered SN for organisation A, switched on,
    // with exactly the one person named — checked, not assumed: a world in
    // which they did NOT share the number would let a rule or a copy that
    // mixes the kinds up pass every case below.
    'a role, a user group, a department, a small group and a leadership role of A are ALL numbered SN and switched on, and SN is KP\'s account number' => pa_one('SELECT 1 AS x FROM tblRoles r JOIN tblGroups g ON g.groupID = r.roleID AND g.siteID = r.siteID AND g.isActive = 1 JOIN tblDepts d ON d.deptID = r.roleID AND d.siteID = r.siteID AND d.isActive = 1 JOIN tblSmallGroups sg ON sg.groupID = r.roleID AND sg.siteID = r.siteID AND sg.isActive = 1 JOIN tblLeadershipRoles lr ON lr.roleID = r.roleID AND lr.siteID = r.siteID AND lr.isActive = 1 JOIN tblUserSites us ON us.userID = r.roleID AND us.siteID = r.siteID AND us.isActive = 1 WHERE r.roleID = ? AND r.siteID = ?', 'ii', [SN, ORG_A]),
    'role SN is held by KR alone; user group SN holds KU alone; department SN holds KD alone' => pa_one('SELECT 1 AS x FROM tblUserRoles ur JOIN tblUserGroups ON tblUserGroups.groupID = ur.roleID JOIN tblUserDepts ud ON ud.deptID = ur.roleID WHERE ur.roleID = ? AND ur.userID = ? AND tblUserGroups.userID = ? AND ud.userID = ? AND (SELECT COUNT(*) FROM tblUserRoles WHERE roleID = ?) = 1 AND (SELECT COUNT(*) FROM tblUserGroups WHERE groupID = ?) = 1 AND (SELECT COUNT(*) FROM tblUserDepts WHERE deptID = ?) = 1', 'iiiiiii', [SN, KR_ID, KU_ID, KD_ID, SN, SN, SN]),
    'small group SN holds KS alone; leadership role SN is held by KL alone, with no dates' => pa_one("SELECT 1 AS x FROM tblSmallGroupMembers JOIN tblLeadershipAssignments la ON la.roleID = tblSmallGroupMembers.groupID AND la.userID = ? AND la.isActive = 1 AND la.startDate IS NULL AND la.endDate IS NULL WHERE tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.userID = ? AND tblSmallGroupMembers.status = 'active' AND (SELECT COUNT(*) FROM tblSmallGroupMembers WHERE groupID = ?) = 1 AND (SELECT COUNT(*) FROM tblLeadershipAssignments WHERE roleID = ?) = 1", 'iiiii', [KL_ID, SN, KS_ID, SN, SN]),
    'KP, KR, KU, KD, KS and KL each hold or belong to exactly ONE thing (KP to none)' => pa_one('SELECT 1 AS x FROM (SELECT (SELECT COUNT(*) FROM tblUserRoles WHERE userID BETWEEN ? AND ?) + (SELECT COUNT(*) FROM tblUserGroups WHERE userID BETWEEN ? AND ?) + (SELECT COUNT(*) FROM tblUserDepts WHERE userID BETWEEN ? AND ?) + (SELECT COUNT(*) FROM tblSmallGroupMembers WHERE userID BETWEEN ? AND ?) + (SELECT COUNT(*) FROM tblLeadershipAssignments WHERE userID BETWEEN ? AND ?) AS n) t WHERE t.n = 5', 'iiiiiiiiii', [KP_ID, KL_ID, KP_ID, KL_ID, KP_ID, KL_ID, KP_ID, KL_ID, KP_ID, KL_ID]),
];
pa_ok(
    'D17 the oracle asks about all 39 accounts and nobody-signed-in in session, token and invite, plus the website, signed out, an API key and the bulk list (124 views) — and the world holds each of the ' . count($kinds17) . ' kinds of viewer listed here',
    count($views17) === 124 && in_array(null, $kinds17, true) === false,
    pa_show(['views' => count($views17), 'missing' => array_keys(array_filter($kinds17, static fn (?array $v): bool => $v === null))])
);
// A control that the kinds are DIFFERENT to the real rule, not just present:
// one event on a list naming every group and role of A. Signed in (session),
// SGM and X (current group members), L (current role holder), TS (term
// starts today), TE (term ends today), LD (leader of SG5) and CL (co-leader
// of SG6) see it; E (former member), Q (left
// the group, term ended), O (switched-off group), F (term not started,
// request waiting), I (switched-off account), H (holds a switched-off
// role), R (term removed as leadership/delete.php does), TY (term ended
// yesterday), TT (term starts tomorrow), LO (leader of the switched-off
// SG2) and CO (co-leader of SG2) do not; the administrators SA
// and GA see everything; a member on no list (M) does not. So a later
// change to the rule about any of these people fails here even if no case
// below isolates it. SG2 and RA3 are switched on for the save and off again
// straight after — the list form rightly refuses to ADD a switched-off group
// or role (D7), so this is how one comes to be switched off while on a
// list: in the Small Groups or Leadership app, later.
$ctl17 = pa_event(FA1, 'd17-ctl@p8', 'D17 control event');
pa_q('UPDATE tblSmallGroups SET isActive = 1 WHERE groupID = ?', 'i', [SG2]);
pa_q('UPDATE tblLeadershipRoles SET isActive = 1 WHERE roleID = ?', 'i', [RA3]);
$ctlSave17 = FeedAdmin::saveChoice($mysqli, $ctl17, 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1, SG2, SG3, SG4, SG5, SG6], 'leadership_role' => [RA1, RA3, RA4, RA5, RA6]]]);
pa_q('UPDATE tblSmallGroups SET isActive = 0 WHERE groupID = ?', 'i', [SG2]);
pa_q('UPDATE tblLeadershipRoles SET isActive = 0 WHERE roleID = ?', 'i', [RA3]);
$seen17 = array_values(array_filter(pa_full(FA1)[$ctl17] ?? [], static fn (string $n): bool => str_starts_with($n, EventVisibility::MODE_SESSION . ':')));
$want17 = array_map(static fn (int $u): string => EventVisibility::MODE_SESSION . ':' . $u, [SA, GA, SGM, L_ID, X_ID, TS_ID, TE_ID, LD_ID, CL_ID]);
sort($seen17);
sort($want17);
pa_ok(
    'D17 control: signed in, exactly SA, GA, SGM, X, L, TS, TE, LD and CL see an event listed for every group and role of A (SG2 and RA3 switched off since) — not M, E, Q, O, F, I, H, R, TY, TT, LO or CO',
    ($ctlSave17['ok'] ?? false) === true && $seen17 === $want17,
    pa_show([$ctlSave17['message'] ?? null, $seen17])
);
// The same control for part 10 of #514: one event on a list naming A's role,
// both its user groups and all three of its departments — and, written in by
// hand afterwards (the form refuses them, D7), organisation B's role, user
// group and department. Signed in, RH (holds the role), UH (in the user group
// in use) and DH (in the switched-on department) see it, with the
// administrators SA and GA. NOT: XF (a former member who still holds all
// three), XI (a switched-off account that does), XO (in the retired group
// and the switched-off department), DN (in the department whose flag is
// empty), XB (a member of A who holds all three IN B), or M. UG2, DP2 and DP3
// are switched on for the save and put back straight after, as SG2 and RA3
// are above. The expected names are written out by hand, so this does not
// depend on the copy and the rule merely agreeing with each other.
$ctl17p = pa_event(FA1, 'd17-ctlp@p8', 'D17 part 10 control event');
pa_q('UPDATE tblGroups SET isActive = 1 WHERE groupID = ?', 'i', [UG2]);
pa_q('UPDATE tblDepts SET isActive = 1 WHERE deptID IN (?, ?)', 'ii', [DP2, DP3]);
$ctlSave17p = FeedAdmin::saveChoice($mysqli, $ctl17p, 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['role' => [RO1], 'user_group' => [UG1, UG2], 'department' => [DP1, DP2, DP3]]]);
pa_q('UPDATE tblGroups SET isActive = 0 WHERE groupID = ?', 'i', [UG2]);
pa_q('UPDATE tblDepts SET isActive = 0 WHERE deptID = ?', 'i', [DP2]);
pa_q('UPDATE tblDepts SET isActive = NULL WHERE deptID = ?', 'i', [DP3]);
pa_list(FA1, 'choice', (int) ($ctlSave17p['choiceId'] ?? 0), [['role', ROB], ['user_group', UGB], ['department', DPB]]);
$seen17p = array_values(array_filter(pa_full(FA1)[$ctl17p] ?? [], static fn (string $n): bool => str_starts_with($n, EventVisibility::MODE_SESSION . ':')));
$want17p = array_map(static fn (int $u): string => EventVisibility::MODE_SESSION . ':' . $u, [SA, GA, RH_ID, UH_ID, DH_ID]);
sort($seen17p);
sort($want17p);
pa_ok(
    'D17 control, part 10: signed in, exactly SA, GA, RH, UH and DH see an event listed for A\'s role, user groups and departments (UG2, DP2 and DP3 switched off since) and, by hand, B\'s three — not M, XF, XI, XO, DN or XB; and the copy says exactly "groups|RH,UH,DH"',
    ($ctlSave17p['ok'] ?? false) === true && (int) ($ctlSave17p['choiceId'] ?? 0) > 0 && $seen17p === $want17p
    && (pa_copySig(FA1)[$ctl17p] ?? '') === 'groups|' . implode(',', [RH_ID, UH_ID, DH_ID]),
    pa_show([$ctlSave17p['message'] ?? null, $seen17p, pa_copySig(FA1)[$ctl17p] ?? null])
);

// ---- Check round 2's scenario and the calendar-wide waiting sentence ----
$m17  = pa_quietWorld(1, 'D17w');
$r17m = pa_oracle(
    'D17 an Everyone rule on a Members calendar: the event only WAITS, nobody\'s view changes (count 0), exactly "Saved. 1 event of this calendar is waiting for approval."',
    FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17 widen', 'public', 'D17w')), 0,
    'Saved. 1 event of this calendar is waiting for approval.'
);
pa_event(FA1, 'd17-quiet@p8', 'D17 quiet event');
FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'y', 'choice' => 'none']]]); // worked out first, like the others
pa_oracle(
    'D17 then a rule that asks for nothing new: count 0, exactly "Saved. 1 event of this calendar is waiting for approval." — the calendar\'s waiting count, not the rule\'s',
    FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17 quiet', 'members', 'D17 quiet')), 0,
    'Saved. 1 event of this calendar is waiting for approval.'
);

// ---- Saves that change NOBODY's view: every one must count 0 (check round 3's probe cases) ----
pa_quietWorld(1, 'D17a');
pa_oracle('D17 a Members rule with full detail on a Members calendar: count 0, "Saved."', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17a', 'members', 'D17a', ['detailLevel' => 'full'])), 0, 'Saved.');
pa_quietWorld(1, 'D17b');
pa_oracle('D17 a Members "title, date and time only" rule: count 0, "Saved."', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17b', 'members', 'D17b', ['detailLevel' => 'basic'])), 0, 'Saved.');
$q3 = pa_quietWorld(1, 'D17c');
pa_oracle('D17 a Members choice on a Members calendar: count 0, "Saved."', FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $q3[0], 'date', ORG_A, SA, ['audienceLevel' => 'members']), 0, 'Saved.');
pa_quietWorld(1, 'D17d');
FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]));
pa_oracle('D17 a Selected-groups rule with exactly the calendar\'s own list: count 0, "Saved."', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17d', 'groups', 'D17d', ['audience' => ['small_group' => [SG1]]])), 0, 'Saved.');
pa_quietWorld(1, 'D17e');
pa_oracle('D17 a Members rule with "Don\'t show via API" ticked, on a Members calendar: count 0 (keys never see a Members event), "Saved."', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17e', 'members', 'D17e', ['apiOptOut' => '1'])), 0, 'Saved.');
pa_quietWorld(20, 'D17f');
pa_oracle('D17 twenty events and one catch-all Members "title only" rule: count 0, "Saved."', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17f', 'members', 'D17f', ['detailLevel' => 'basic'])), 0, 'Saved.');
pa_quietWorld(1, 'D17g');
pa_q('UPDATE tblExternalFeeds SET isActive = 0 WHERE feedID = ?', 'i', [FA1]);
pa_oracle('D17 a narrowing rule saved on a PAUSED calendar: nobody sees its events before or after, count 0, "Saved."', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17g', 'groups', 'D17g', ['audience' => ['small_group' => [SG1]]])), 0, 'Saved.');

// ---- Saves that DO change who can see: each counted, and matching the real rule ----
$r1 = pa_quietWorld(1, 'D17h');
pa_oracle('D17 Members -> Everyone, approved (a choice with its date on the page): count 1, "Saved. 1 date changed who can see it."', FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $r1[0], 'date', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [$r1[0] . ':' . pa_cf($r1[0])]]), 1, 'Saved. 1 date changed who can see it.');
pa_quietWorld(1, 'D17i', FA2);
pa_oracle('D17 Everyone -> Members (a narrowing rule on a Public calendar): count 1, "Saved. 1 event changed who can see it."', FA2, static fn (): array => FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, $rule17('D17i', 'members', 'D17i')), 1, 'Saved. 1 event changed who can see it.');
pa_resetWorld();
pa_q('UPDATE tblExternalFeeds SET apiOptOut = 1 WHERE feedID = ?', 'i', [FA2]);
pa_event(FA2, 'd17j@p8', 'D17j event');
FeedAdmin::saveCategoryMap($mysqli, FA2, ORG_A, SA, ['map' => [['word' => 'x', 'choice' => 'none']]]);
pa_oracle('D17 Everyone -> Members on a calendar kept out of the API (so only the signed-out visitor loses it): count 1', FA2, static fn (): array => FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, $rule17('D17j', 'members', 'D17j')), 1, 'Saved. 1 event changed who can see it.');
pa_quietWorld(1, 'D17k');
$r17k = pa_oracle('D17 a Selected-groups rule [SG1] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17k', 'groups', 'D17k', ['audience' => ['small_group' => [SG1]]])), 1, 'Saved. 1 event changed who can see it.');
pa_oracle('D17 that rule\'s list edited to add a named person (no event column moves): count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, (int) ($r17k['ruleId'] ?? 0), ORG_A, SA, $rule17('D17k', 'groups', 'D17k', ['audience' => ['small_group' => [SG1], 'person' => [P_ID]]])), 1, 'Saved. 1 event changed who can see it.');
$r17c = pa_quietWorld(1, 'D17o');
pa_oracle('D17 a Selected-groups CHOICE [SG1] narrowing a Members date: count 1', FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $r17c[0], 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]), 1, 'Saved. 1 date changed who can see it.');
pa_oracle('D17 that choice\'s list edited to add a named person: count 1', FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $r17c[0], 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [P_ID]]]), 1, 'Saved. 1 date changed who can see it.');
$r4 = pa_quietWorld(1, 'D17l', FA2);
pa_oracle('D17 the public-website box ticked on an Everyone date (approved, the date on the page): count 1', FA2, static fn (): array => FeedAdmin::saveChoice($mysqli, $r4[0], 'date', ORG_A, SA, ['audienceLevel' => 'public', 'websiteOptIn' => '1', 'seen' => [$r4[0] . ':' . pa_cf($r4[0])]]), 1, 'Saved. 1 date changed who can see it.');
pa_oracle('D17 …and cleared again: count 1', FA2, static fn (): array => FeedAdmin::saveChoice($mysqli, $r4[0], 'date', ORG_A, SA, ['audienceLevel' => 'public']), 1, 'Saved. 1 date changed who can see it.');
pa_quietWorld(1, 'D17m', FA2);
pa_oracle('D17 "Don\'t show via API" ticked by a rule on an Everyone event: count 1 (API keys lose it)', FA2, static fn (): array => FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, $rule17('D17m', 'public', 'D17m', ['apiOptOut' => '1'])), 1, 'Saved. 1 event changed who can see it.');
$r6 = pa_quietWorld(1, 'D17n');
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE eventID = ?', 'i', [$r6[0]]);
pa_oracle('D17 an answer that had RUN OUT (administrators only) worked out again by a save: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17n', 'members', 'nothing matches this')), 1, 'Saved. 1 event changed who can see it.');

// ---- Fix round 4 (check round 4, M2): changes a seven-viewer panel could not see ----
// Each list edit starts from a Selected-groups rule [SG1] narrowing a
// Members event (itself an oracle check, count 1).
$one17 = 'Saved. 1 event changed who can see it.';
$sg1   = static fn (string $tag): array => pa_oracle(
    'D17 set-up for ' . $tag . ': a Selected-groups rule [SG1] narrowing a Members event: count 1',
    FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17($tag, 'groups', $tag, ['audience' => ['small_group' => [SG1]]])), 1, $one17
);
$edit17 = static fn (array $made, string $tag, array $audience): callable => static fn (): array => FeedAdmin::saveRule(
    $mysqli, FA1, (int) ($made['ruleId'] ?? 0), ORG_A, SA, $rule17($tag, 'groups', $tag, ['audience' => $audience])
);

pa_quietWorld(1, 'D17p');
$r17p = $sg1('D17p');
pa_oracle('D17 a leadership role added to that list, [SG1] -> [SG1, RA1]: L (holds RA1 now) gains it: count 1', FA1, $edit17($r17p, 'D17p', ['small_group' => [SG1], 'leadership_role' => [RA1]]), 1, $one17);
pa_oracle('D17 …and the role taken off again, [SG1, RA1] -> [SG1]: L loses it: count 1', FA1, $edit17($r17p, 'D17p', ['small_group' => [SG1]]), 1, $one17);

pa_quietWorld(1, 'D17q');
$r17q = $sg1('D17q');
pa_oracle('D17 a second group added, [SG1] -> [SG1, SG3]: X gains it: count 1', FA1, $edit17($r17q, 'D17q', ['small_group' => [SG1, SG3]]), 1, $one17);

pa_quietWorld(1, 'D17r');
$r17r = $sg1('D17r');
pa_oracle('D17 groups swapped, [SG1] -> [SG3]: SGM loses it and X gains it — one event: count 1', FA1, $edit17($r17r, 'D17r', ['small_group' => [SG3]]), 1, $one17);

// ---- List edits that let NOBODY new in and shut nobody out: the true count is 0 (check round 4, B1-B6) ----
pa_quietWorld(1, 'D17s');
$r17s = $sg1('D17s');
pa_oracle('D17 B1 SGM named as well as SG1, [SG1] -> [SG1, SGM] — SGM is in SG1 already: count 0, "Saved."', FA1, $edit17($r17s, 'D17s', ['small_group' => [SG1], 'person' => [SGM]]), 0, 'Saved.');

pa_quietWorld(1, 'D17t');
$r17t = pa_oracle('D17 set-up for B2: a Selected-groups rule [SG1, SG3] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17t', 'groups', 'D17t', ['audience' => ['small_group' => [SG1, SG3]]])), 1, $one17);
pa_q('UPDATE tblSmallGroups SET isActive = 0 WHERE groupID = ?', 'i', [SG3]); // switched off since, in the Small Groups app
pa_oracle('D17 B2 SG3 switched off since; the list tidied, [SG1, SG3] -> [SG1]: count 0, "Saved."', FA1, $edit17($r17t, 'D17t', ['small_group' => [SG1]]), 0, 'Saved.');

pa_quietWorld(1, 'D17u');
$r17u = pa_oracle('D17 set-up for B3: a Selected-groups rule [SG1, P] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17u', 'groups', 'D17u', ['audience' => ['small_group' => [SG1], 'person' => [P_ID]]])), 1, $one17);
pa_q('UPDATE tblUserSites SET isActive = 0 WHERE userID = ? AND siteID = ?', 'ii', [P_ID, ORG_A]); // P's membership here ended since
pa_oracle('D17 B3 P\'s membership ended since; the list tidied, [SG1, P] -> [SG1]: count 0, "Saved."', FA1, $edit17($r17u, 'D17u', ['small_group' => [SG1]]), 0, 'Saved.');

pa_quietWorld(1, 'D17v');
$r17v = $sg1('D17v');
pa_oracle('D17 B4 a role held only by a switched-off account (I) added, [SG1] -> [SG1, RA2]: count 0, "Saved."', FA1, $edit17($r17v, 'D17v', ['small_group' => [SG1], 'leadership_role' => [RA2]]), 0, 'Saved.');

pa_quietWorld(1, 'D17x');
$r17x = $sg1('D17x');
pa_q('DELETE FROM tblLeadershipAssignments WHERE userID = ? AND roleID = ?', 'ii', [L_ID, RA1]); // L steps down: RA1 is now held only on paper (E, Q, F)
pa_oracle('D17 B4b a role held only on paper added (a former member now in B, a member of B only, an ended term, a term not started), [SG1] -> [SG1, RA1]: count 0, "Saved."', FA1, $edit17($r17x, 'D17x', ['small_group' => [SG1], 'leadership_role' => [RA1]]), 0, 'Saved.');

pa_quietWorld(1, 'D17y');
$r17y = pa_oracle('D17 set-up for B5: a Selected-groups rule [SG1, P] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17y', 'groups', 'D17y', ['audience' => ['small_group' => [SG1], 'person' => [P_ID]]])), 1, $one17);
pa_oracle('D17 B5 the same list posted in another order, [P, SG1]: count 0, "Saved."', FA1, $edit17($r17y, 'D17y', ['person' => [P_ID], 'small_group' => [SG1]]), 0, 'Saved.');

$c17 = pa_quietWorld(1, 'D17z');
pa_oracle('D17 set-up for B6: a Selected-groups CHOICE [SG1] narrowing a Members date: count 1', FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $c17[0], 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]), 1, 'Saved. 1 date changed who can see it.');
pa_oracle('D17 B6 that choice names SGM as well, [SG1] -> [SG1, SGM] — SGM is in SG1 already: count 0, "Saved."', FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $c17[0], 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [SGM]]]), 0, 'Saved.');

// ---- The API box when the calendar itself is kept out of the API ----
pa_resetWorld();
pa_q('UPDATE tblExternalFeeds SET apiOptOut = 1 WHERE feedID = ?', 'i', [FA2]);
pa_event(FA2, 'd17-c1@p8', 'D17c1 event');
FeedAdmin::saveCategoryMap($mysqli, FA2, ORG_A, SA, ['map' => [['word' => 'x', 'choice' => 'none']]]);
pa_oracle('D17 an Everyone rule ticking "Don\'t show via API" on a calendar already kept out of the API: count 0, "Saved."', FA2, static fn (): array => FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, $rule17('D17c1', 'public', 'D17c1', ['apiOptOut' => '1'])), 0, 'Saved.');
// The calendar's own box ticked by something that did NOT work the events
// out again (a hand edit here; any later page or tool that writes the
// column directly). FeedAdmin itself always works them out again, so this
// state cannot arise through it today — but the rule reads the calendar's
// box LIVE, so keys lost the event the moment it was ticked, and the save
// that folds the box into the event's own copy changes nobody's view. A
// count that judged keys by the event's copy alone would say 1 (the
// checker's C4-03, harmless until this case existed).
pa_quietWorld(1, 'D17c2', FA2);
pa_q('UPDATE tblExternalFeeds SET apiOptOut = 1 WHERE feedID = ?', 'i', [FA2]);
pa_oracle('D17 the calendar\'s own API box ticked outside FeedAdmin, then an unrelated rule save works the event out again: count 0, "Saved."', FA2, static fn (): array => FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, $rule17('D17c2', 'members', 'nothing matches this')), 0, 'Saved.');

// ---- A private-marked event: the mark hides it until somebody decides (it has no rule to follow) ----
pa_resetWorld();
pa_event(FA1, 'd17-d1@p8', 'D17d1 event', ['private' => 1]);
FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'x', 'choice' => 'none']]]);
pa_oracle('D17 a Members rule on a calendar whose only event is private-marked: count 0, "Saved."', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17d1', 'members', 'D17d1')), 0, 'Saved.');

// ---- Run-out answers worked out again, at Everyone and at Selected groups ----
$e17 = pa_quietWorld(1, 'D17e1', FA2);
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE eventID = ?', 'i', [$e17[0]]);
pa_oracle('D17 an Everyone answer that had RUN OUT, worked out again by an unrelated rule save: count 1', FA2, static fn (): array => FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, $rule17('D17e1', 'members', 'nothing matches this')), 1, $one17);
$e17 = pa_quietWorld(1, 'D17e2');
$sg1('D17e2');
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE eventID = ?', 'i', [$e17[0]]);
pa_oracle('D17 a Selected-groups answer that had RUN OUT, worked out again by an unrelated rule save: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17e2x', 'members', 'nothing matches this')), 1, $one17);

// ---- The rule's PLURAL sentence, word for word (check round 4 LOW 1) ----
pa_quietWorld(2, 'D17e3', FA2);
pa_oracle('D17 one Members rule narrowing TWO Everyone events: count 2, exactly "Saved. 2 events changed who can see them."', FA2, static fn (): array => FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, $rule17('D17e3', 'members', 'D17e3')), 2, 'Saved. 2 events changed who can see them.');
// …and where the resolver's own "answers rewritten" number differs from the
// count: a rule edit that narrows two events and only changes how much
// detail a third shows. The resolver rewrites three answers; two events
// changed who can see them. A sentence that printed the resolver's number
// would say 3 (the checker's C4-07; its round-3 cousin was rejected for
// exactly this).
pa_quietWorld(3, 'D17e4', FA2);
$r17e4 = pa_oracle('D17 set-up: a Members "title only" rule narrowing the third of three Everyone events: count 1', FA2, static fn (): array => FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, $rule17('D17e4', 'members', 'D17e4 event 2', ['detailLevel' => 'basic'])), 1, $one17);
pa_oracle(
    'D17 that rule widened to all three, with full detail: two events narrowed, the third only shows more detail — count 2, exactly "Saved. 2 events changed who can see them."',
    FA2, static fn (): array => FeedAdmin::saveRule($mysqli, FA2, (int) ($r17e4['ruleId'] ?? 0), ORG_A, SA, $rule17('D17e4', 'members', 'D17e4', ['detailLevel' => 'full'])), 2,
    'Saved. 2 events changed who can see them.'
);

// ---- Part B (the owner's decision of 27 September 2026): the edges of a copy that compares PEOPLE ----
// A list that lets NOBODY in gives exactly the "administrators only"
// answer (see FeedAdmin::whoCanSee()), so moving a hidden date to such a
// list changes nobody's view. A copy that kept "groups with nobody" apart
// from "hidden" would say 1.
$h17 = pa_quietWorld(1, 'D17h2');
pa_oracle('D17 set-up: a Members date hidden by a choice: count 1', FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $h17[0], 'date', ORG_A, SA, ['audienceLevel' => 'hidden']), 1, 'Saved. 1 date changed who can see it.');
pa_oracle(
    'D17 that hidden date moved to Selected groups whose list lets nobody in (RA2: only a switched-off account holds it), the date on the page: count 0, "Saved."',
    FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $h17[0], 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['leadership_role' => [RA2]], 'seen' => [$h17[0] . ':' . pa_cf($h17[0])]]), 0, 'Saved.'
);
// A list is read by owner TYPE and number together: a choice can have the
// same number as a calendar or a rule. Here a list owned by CHOICE number
// 910201 — the calendar FA1's own number — names P (written by hand; it
// stands for a real choice that happens to share the number). The date
// moves from the calendar's list [SG1] to a choice's [SG1, P], approved on
// the page (a RULE adding P would only wait for approval, changing
// nobody's view — the first version of this case did that and rightly
// counted 0): P gains it. A copy that read lists by number alone would
// think P could see it before, and say 0.
$ot17 = pa_quietWorld(1, 'D17ot');
FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]));
pa_list(FA1, 'choice', FA1, [['person', P_ID]]);
pa_oracle(
    'D17 a date moved from the calendar\'s list [SG1] to a choice\'s [SG1, P] (on the page), while a CHOICE numbered like the calendar has a list naming P: P gains it — count 1',
    FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $ot17[0], 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [P_ID]], 'seen' => [$ot17[0] . ':' . pa_cf($ot17[0])]]), 1,
    'Saved. 1 date changed who can see it.'
);
// Every member named: all of them still see it, but the newsletter list
// (bulk members) only carries Everyone and Members events, so it loses it.
// The list is READ FROM THE DATABASE — every active account with an active
// membership of A — never written out here (fix round 6, check round 6
// L1): round 4 wrote ten names, the world later grew to sixteen members,
// and the case quietly stopped naming everyone.
pa_quietWorld(1, 'D17all');
$everyone17 = array_map(
    static fn (array $r): int => (int) $r['userID'],
    pa_q('SELECT u.userID FROM tblUsers u JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 WHERE u.isActive = 1 ORDER BY u.userID', 'i', [ORG_A])
);
pa_oracle(
    'D17 a Selected-groups rule naming EVERY active member of A (' . count($everyone17) . ', read from the database) narrows a Members event: every member still sees it, the newsletter list loses it — count 1',
    FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17all', 'groups', 'D17all', ['audience' => ['person' => $everyone17]])), 1, $one17
);
// An administrator named alone: in the portal SA sees every event anyway,
// but a personal calendar feed (token mode) switches the administrator
// branch off, so hiding the date takes it out of SA's feed.
$sa17 = pa_quietWorld(1, 'D17sa');
pa_oracle('D17 set-up: a Selected-groups CHOICE naming only the administrator SA on a Members date: count 1', FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $sa17[0], 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['person' => [SA]]]), 1, 'Saved. 1 date changed who can see it.');
pa_oracle(
    'D17 that date then hidden: count 1 — only SA\'s personal feed loses it (in the portal SA sees everything either way)',
    FA1, static fn (): array => FeedAdmin::saveChoice($mysqli, $sa17[0], 'date', ORG_A, SA, ['audienceLevel' => 'hidden']), 1, 'Saved. 1 date changed who can see it.'
);

// ---- Fix round 5 (check round 5, M1; its scenarios S1, S1b, S2-S7): each new kind of person is the ONLY one who moves ----
// A role SWITCHED OFF since it was listed (leadership/manage/save.php can
// do that) lets nobody in: tidying it off the list changes nobody's view.
// RA3 is switched on only for the set-up save — the list form refuses to
// ADD a switched-off role — then off again, as the Leadership app would.
pa_quietWorld(1, 'D17s1');
pa_q('UPDATE tblLeadershipRoles SET isActive = 1 WHERE roleID = ?', 'i', [RA3]);
$s1 = pa_oracle('D17 set-up for S1: a Selected-groups rule [SG1, RA3] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17s1', 'groups', 'D17s1', ['audience' => ['small_group' => [SG1], 'leadership_role' => [RA3]]])), 1, $one17);
pa_q('UPDATE tblLeadershipRoles SET isActive = 0 WHERE roleID = ?', 'i', [RA3]);
pa_oracle('D17 S1 RA3 switched off since; the list tidied, [SG1, RA3] -> [SG1] — H was let in by nothing: count 0, "Saved."', FA1, $edit17($s1, 'D17s1', ['small_group' => [SG1]]), 0, 'Saved.');
// …and a list whose ONLY entry is that switched-off role reads like hidden:
// setting the rule to Hidden changes nobody's view.
pa_quietWorld(1, 'D17s1b');
pa_q('UPDATE tblLeadershipRoles SET isActive = 1 WHERE roleID = ?', 'i', [RA3]);
$s1b = pa_oracle('D17 set-up for S1b: a Selected-groups rule [RA3] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17s1b', 'groups', 'D17s1b', ['audience' => ['leadership_role' => [RA3]]])), 1, $one17);
pa_q('UPDATE tblLeadershipRoles SET isActive = 0 WHERE roleID = ?', 'i', [RA3]);
pa_oracle('D17 S1b that rule set to Hidden while RA3 is switched off: only administrators saw it before and after — count 0, "Saved."', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, (int) ($s1b['ruleId'] ?? 0), ORG_A, SA, $rule17('D17s1b', 'hidden', 'D17s1b')), 0, 'Saved.');
// A term REMOVED the way leadership/delete.php removes one (isActive 0,
// end date empty) lets nobody in.
pa_quietWorld(1, 'D17s2');
$s2 = $sg1('D17s2');
pa_oracle('D17 S2 RA6 added, [SG1] -> [SG1, RA6] — its only term (R) was removed as leadership/delete.php does: count 0, "Saved."', FA1, $edit17($s2, 'D17s2', ['small_group' => [SG1], 'leadership_role' => [RA6]]), 0, 'Saved.');
// A term that ENDS today still counts today; one that STARTS today counts
// from today. Each is the only one who moves; the control a day outside
// (TT starts tomorrow; TY ended yesterday) must not move.
pa_quietWorld(1, 'D17s3');
$s3 = $sg1('D17s3');
pa_oracle('D17 S3 RA5 added, [SG1] -> [SG1, RA5]: TE (term ends TODAY) gains it, TT (starts tomorrow) does not — count 1', FA1, $edit17($s3, 'D17s3', ['small_group' => [SG1], 'leadership_role' => [RA5]]), 1, $one17);
pa_quietWorld(1, 'D17s4');
$s4 = $sg1('D17s4');
pa_oracle('D17 S4 RA4 added, [SG1] -> [SG1, RA4]: TS (term starts TODAY) gains it, TY (ended yesterday) does not — count 1', FA1, $edit17($s4, 'D17s4', ['small_group' => [SG1], 'leadership_role' => [RA4]]), 1, $one17);
pa_quietWorld(1, 'D17s5');
pa_q('DELETE FROM tblLeadershipAssignments WHERE userID = ? AND roleID = ?', 'ii', [TS_ID, RA4]); // RA4 now held only by TY
$s5 = $sg1('D17s5');
pa_oracle('D17 S5 RA4 added when its only term ENDED YESTERDAY (TY): count 0, "Saved."', FA1, $edit17($s5, 'D17s5', ['small_group' => [SG1], 'leadership_role' => [RA4]]), 0, 'Saved.');
pa_quietWorld(1, 'D17s6');
pa_q('DELETE FROM tblLeadershipAssignments WHERE userID = ? AND roleID = ?', 'ii', [TE_ID, RA5]); // RA5 now held only by TT
$s6 = $sg1('D17s6');
pa_oracle('D17 S6 RA5 added when its only term STARTS TOMORROW (TT): count 0, "Saved."', FA1, $edit17($s6, 'D17s6', ['small_group' => [SG1], 'leadership_role' => [RA5]]), 0, 'Saved.');
// A group whose only rows are an ENDED membership and a PENDING join
// request lets nobody in.
pa_quietWorld(1, 'D17s7');
$s7 = $sg1('D17s7');
pa_oracle('D17 S7 SG4 added, [SG1] -> [SG1, SG4] — SG4 holds only an ended membership (Q) and a pending request (F): count 0, "Saved."', FA1, $edit17($s7, 'D17s7', ['small_group' => [SG1, SG4]]), 0, 'Saved.');

// ---- Fix round 6 (check round 6, L2): small-group LEADERS are let in like members ----
// Each is the ONLY one who moves. A copy that counted only 'member' rows
// (the checker's C6-K04) says 0 here; a rule change letting a switched-off
// group's leaders in (V6-02) makes S10's tidy-up move LO.
pa_quietWorld(1, 'D17s8');
$s8 = $sg1('D17s8');
pa_oracle('D17 S8 SG5 added, [SG1] -> [SG1, SG5] — SG5\'s only row is its LEADER (LD): LD gains it — count 1', FA1, $edit17($s8, 'D17s8', ['small_group' => [SG1, SG5]]), 1, $one17);
pa_quietWorld(1, 'D17s9');
$s9 = $sg1('D17s9');
pa_oracle('D17 S9 SG6 added, [SG1] -> [SG1, SG6] — SG6\'s only row is its CO-LEADER (CL): CL gains it — count 1', FA1, $edit17($s9, 'D17s9', ['small_group' => [SG1, SG6]]), 1, $one17);
// SG2 (O a member, LO its leader) switched on for the set-up save only —
// the list form refuses to ADD a switched-off group — then off again, as
// the Small Groups app would; tidying it off the list then moves nobody.
pa_quietWorld(1, 'D17s10');
pa_q('UPDATE tblSmallGroups SET isActive = 1 WHERE groupID = ?', 'i', [SG2]);
$s10 = pa_oracle('D17 set-up for S10: a Selected-groups rule [SG1, SG2] narrowing a Members event (O and LO let in while SG2 is on): count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17s10', 'groups', 'D17s10', ['audience' => ['small_group' => [SG1, SG2]]])), 1, $one17);
pa_q('UPDATE tblSmallGroups SET isActive = 0 WHERE groupID = ?', 'i', [SG2]);
pa_oracle('D17 S10 SG2 switched off since; the list tidied, [SG1, SG2] -> [SG1] — its leader LO was let in by nothing: count 0, "Saved."', FA1, $edit17($s10, 'D17s10', ['small_group' => [SG1]]), 0, 'Saved.');

// ---- Part 10 of #514, check R5: roles, user groups and departments ----
// Until part 10 the rule and the copy both answered "nobody" for these three
// kinds. Both now let people in, and the two must agree for EVERY account,
// before and after every save — that is what each pa_oracle() call checks.
// The copy uses the very same pieces of SQL as the rule for "holds this
// role" and "is in this group", so the mistakes left for it to make are: a
// kind left out, the wrong organisation handed to a piece, or the wrong
// list rows read. Each case below is built so one of those shows.
//
// Agreement alone is not enough: a mistake made the same way in both would
// agree perfectly. So two cases also compare the answer with the account
// numbers WRITTEN OUT BY HAND (P10d and P10i), as the control above does.
//
// First, each new kind of person is the ONLY one who moves.
pa_quietWorld(1, 'D17P10a');
$p10a = $sg1('D17P10a');
pa_oracle('D17 R5 a role added, [SG1] -> [SG1, RO1]: RH gains it — not XF (a former member) or XI (a switched-off account), who hold RO1 too — count 1', FA1, $edit17($p10a, 'D17P10a', ['small_group' => [SG1], 'role' => [RO1]]), 1, $one17);
pa_oracle('D17 R5 …and the role taken off again, [SG1, RO1] -> [SG1]: RH loses it — count 1', FA1, $edit17($p10a, 'D17P10a', ['small_group' => [SG1]]), 1, $one17);
pa_quietWorld(1, 'D17P10b');
$p10b = $sg1('D17P10b');
pa_oracle('D17 R5 a user group added, [SG1] -> [SG1, UG1]: UH gains it — count 1', FA1, $edit17($p10b, 'D17P10b', ['small_group' => [SG1], 'user_group' => [UG1]]), 1, $one17);
pa_quietWorld(1, 'D17P10c');
$p10c = $sg1('D17P10c');
pa_oracle('D17 R5 a department added, [SG1] -> [SG1, DP1]: DH gains it — count 1', FA1, $edit17($p10c, 'D17P10c', ['small_group' => [SG1], 'department' => [DP1]]), 1, $one17);
// All three at once, and the answer written out by hand: SGM (in SG1), RH,
// UH and DH — nobody else. A copy and a rule that BOTH let XF, XI, XO, DN or
// XB in would agree with each other and still fail here.
$e10d = pa_quietWorld(1, 'D17P10d');
$p10d = $sg1('D17P10d');
pa_oracle('D17 R5 all three kinds added at once, [SG1] -> [SG1, RO1, UG1, DP1]: one event — count 1', FA1, $edit17($p10d, 'D17P10d', ['small_group' => [SG1], 'role' => [RO1], 'user_group' => [UG1], 'department' => [DP1]]), 1, $one17);
$byHand10d = 'groups|' . implode(',', [SGM, RH_ID, UH_ID, DH_ID]);
pa_ok(
    'D17 R5 …and for that event both the copy and the real rule say exactly "groups|SGM,RH,UH,DH", as written out by hand',
    (pa_copySig(FA1)[$e10d[0]] ?? '') === $byHand10d && (pa_realSigs(pa_full(FA1))[$e10d[0]] ?? '') === $byHand10d,
    pa_show([pa_copySig(FA1), pa_realSigs(pa_full(FA1)), $byHand10d])
);

// A user group RETIRED since it was listed (Admin, Groups can do that) lets
// nobody in: tidying it off the list changes nobody's view. A copy that
// forgot the on/off test would say UH could see the event before, and count
// 1 — and its answer before the save would differ from the real rule's.
pa_quietWorld(1, 'D17P10e');
$p10e = pa_oracle('D17 set-up for R5 "retired": a Selected-groups rule [SG1, UG1] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17P10e', 'groups', 'D17P10e', ['audience' => ['small_group' => [SG1], 'user_group' => [UG1]]])), 1, $one17);
pa_q('UPDATE tblGroups SET isActive = 0 WHERE groupID = ?', 'i', [UG1]);
pa_oracle('D17 R5 UG1 retired since; the list tidied, [SG1, UG1] -> [SG1] — UH was let in by nothing: count 0, "Saved."', FA1, $edit17($p10e, 'D17P10e', ['small_group' => [SG1]]), 0, 'Saved.');
// The same for a department SWITCHED OFF since…
pa_quietWorld(1, 'D17P10f');
$p10f = pa_oracle('D17 set-up for R5 "switched off": a Selected-groups rule [SG1, DP1] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17P10f', 'groups', 'D17P10f', ['audience' => ['small_group' => [SG1], 'department' => [DP1]]])), 1, $one17);
pa_q('UPDATE tblDepts SET isActive = 0 WHERE deptID = ?', 'i', [DP1]);
pa_oracle('D17 R5 DP1 switched off since; the list tidied, [SG1, DP1] -> [SG1] — DH was let in by nothing: count 0, "Saved."', FA1, $edit17($p10f, 'D17P10f', ['small_group' => [SG1]]), 0, 'Saved.');
// …and for a department whose on/off flag was EMPTIED since (the column
// allows it): empty reads as switched off, in the rule and in the copy.
pa_quietWorld(1, 'D17P10g');
$p10g = pa_oracle('D17 set-up for R5 "empty flag": a Selected-groups rule [SG1, DP1] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17P10g', 'groups', 'D17P10g', ['audience' => ['small_group' => [SG1], 'department' => [DP1]]])), 1, $one17);
pa_q('UPDATE tblDepts SET isActive = NULL WHERE deptID = ?', 'i', [DP1]);
pa_oracle('D17 R5 DP1\'s on/off flag emptied since; the list tidied, [SG1, DP1] -> [SG1] — an empty flag lets nobody in: count 0, "Saved."', FA1, $edit17($p10g, 'D17P10g', ['small_group' => [SG1]]), 0, 'Saved.');
// A list whose ONLY entry is a retired user group reads like hidden: setting
// the rule to Hidden changes nobody's view (the twin of S1b above).
pa_quietWorld(1, 'D17P10h');
$p10h = pa_oracle('D17 set-up for R5 "only a retired group": a Selected-groups rule [UG1] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17P10h', 'groups', 'D17P10h', ['audience' => ['user_group' => [UG1]]])), 1, $one17);
pa_q('UPDATE tblGroups SET isActive = 0 WHERE groupID = ?', 'i', [UG1]);
pa_oracle('D17 R5 that rule set to Hidden while UG1 is retired: only administrators saw it before and after — count 0, "Saved."', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, (int) ($p10h['ruleId'] ?? 0), ORG_A, SA, $rule17('D17P10h', 'hidden', 'D17P10h')), 0, 'Saved.');

// ANOTHER ORGANISATION'S role, user group and department, written onto the
// list by hand (the form refuses them, D7). XB is an active member of A who
// holds all three IN B. Neither the rule nor the copy may let XB in. The
// answer before the save is written out by hand — exactly SGM, through SG1 —
// and the save then tidies the three rows away, changing nobody's view. A
// copy (or a rule) that judged a holding by the HOLDER'S organisation, or
// by the holding's own, would name XB before the save.
$e10i = pa_quietWorld(1, 'D17P10i');
$p10i = $sg1('D17P10i');
pa_list(FA1, 'rule', (int) ($p10i['ruleId'] ?? 0), [['role', ROB], ['user_group', UGB], ['department', DPB]]);
$byHand10i = 'groups|' . SGM;
pa_ok(
    'D17 R5 organisation B\'s role, user group and department on A\'s list: XB, a member of A who holds all three in B, is let in by none — the copy and the real rule both say exactly "groups|SGM", as written out by hand',
    (int) ($p10i['ruleId'] ?? 0) > 0 && count(ExternalAudience::current($mysqli, ORG_A, FA1, 'rule', (int) ($p10i['ruleId'] ?? 0))) === 4
    && (pa_copySig(FA1)[$e10i[0]] ?? '') === $byHand10i && (pa_realSigs(pa_full(FA1))[$e10i[0]] ?? '') === $byHand10i,
    pa_show([pa_copySig(FA1), pa_realSigs(pa_full(FA1)), $byHand10i])
);
pa_oracle('D17 R5 …and the save that tidies those three rows away changes nobody\'s view: count 0, "Saved."', FA1, $edit17($p10i, 'D17P10i', ['small_group' => [SG1]]), 0, 'Saved.');

// A role DELETED while it is on a list (its holdings go with it): the entry
// stays, lets nobody in, and tidying it away changes nobody's view.
pa_quietWorld(1, 'D17P10j');
$p10j = pa_oracle('D17 set-up for R5 "deleted": a Selected-groups rule [SG1, RO1] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17P10j', 'groups', 'D17P10j', ['audience' => ['small_group' => [SG1], 'role' => [RO1]]])), 1, $one17);
pa_q('DELETE FROM tblRoles WHERE roleID = ?', 'i', [RO1]);
pa_oracle('D17 R5 RO1 deleted since; the list tidied, [SG1, RO1] -> [SG1] — a role that no longer exists lets nobody in: count 0, "Saved."', FA1, $edit17($p10j, 'D17P10j', ['small_group' => [SG1]]), 0, 'Saved.');

// ---- A row of a new kind filed under ANOTHER organisation, on a list in use (added after part 10's independent check) ----
// The copy's docblock says the oracle is there to catch "which list rows are
// read". For the three new kinds it could not: the check made the copy's
// role branch read list rows filed under ANY organisation (`am.siteID = ?`
// turned into a test that is always true) and all 920 checks still passed,
// because no list in this world held such a row. Here one does: three rows
// written by hand onto the list of a rule of organisation A, but FILED UNDER
// ORGANISATION B (their own `siteID` says B), naming B's role, user group and
// department. XB holds exactly those three in B and is an active member of
// A. The real rule refuses the rows (it reads a list row only when the row's
// organisation is the event's), so the copy must too. The answer is written
// out by hand: SGM alone, through SG1.
//   No form can file a row under another organisation; these stand for a
//   hand edit or a faulty restore. A save never removes them either — it
//   only ever replaces rows of its OWN organisation — so they are still
//   there afterwards, still letting nobody in. That is checked as well.
$e10k    = pa_quietWorld(1, 'D17P10k');
$p10k    = $sg1('D17P10k');
$rule10k = (int) ($p10k['ruleId'] ?? 0);
pa_q(
    'INSERT INTO tblExternalAudienceMembers (siteID, feedID, ownerType, ownerID, kind, refID, userID, createdAt) VALUES '
    . "(?, ?, 'rule', ?, 'role', ?, NULL, UTC_TIMESTAMP()), (?, ?, 'rule', ?, 'user_group', ?, NULL, UTC_TIMESTAMP()), (?, ?, 'rule', ?, 'department', ?, NULL, UTC_TIMESTAMP())",
    'iiiiiiiiiiii', [ORG_B, FA1, $rule10k, ROB, ORG_B, FA1, $rule10k, UGB, ORG_B, FA1, $rule10k, DPB]
);
$filed10k = static fn (): array => array_map(
    static fn (array $r): string => $r['kind'] . ':' . $r['refID'],
    pa_q("SELECT kind, refID FROM tblExternalAudienceMembers WHERE feedID = ? AND ownerType = 'rule' AND ownerID = ? AND siteID = ? ORDER BY audienceMemberID", 'iii', [FA1, $rule10k, ORG_B])
);
$byHand10k = 'groups|' . SGM;
$want10k   = ['role:' . ROB, 'user_group:' . UGB, 'department:' . DPB];
pa_ok(
    'D17 a role, a user group and a department row FILED UNDER organisation B on a list of A\'s rule: XB, a member of A who holds all three in B, is let in by none — the copy and the real rule both say exactly "groups|SGM", as written out by hand',
    $rule10k > 0 && $filed10k() === $want10k
    && (pa_copySig(FA1)[$e10k[0]] ?? '') === $byHand10k && (pa_realSigs(pa_full(FA1))[$e10k[0]] ?? '') === $byHand10k,
    pa_show([$filed10k(), pa_copySig(FA1), pa_realSigs(pa_full(FA1)), $byHand10k])
);
pa_oracle('D17 …and a save of that rule\'s list changes nobody\'s view: count 0, "Saved."', FA1, $edit17($p10k, 'D17P10k', ['small_group' => [SG1]]), 0, 'Saved.');
pa_ok(
    'D17 …and after that save the three rows are still filed under B (a save only replaces rows of its own organisation), and the copy and the real rule still say exactly "groups|SGM"',
    $filed10k() === $want10k && (pa_copySig(FA1)[$e10k[0]] ?? '') === $byHand10k && (pa_realSigs(pa_full(FA1))[$e10k[0]] ?? '') === $byHand10k,
    pa_show([$filed10k(), pa_copySig(FA1), pa_realSigs(pa_full(FA1))])
);

// ---- One number, six kinds: a list row of one kind is never read as another (check R9; added after part 10's independent check) ----
// The check took `am.kind = 'role'` out of the real rule, so that a list row
// of ANY kind was read as a role number, and all 920 checks here passed. It
// did the same to the copy alone: passed. And it made the rule AND the copy
// both read a department row as a user group number: passed again, the two
// agreeing with each other. Nothing was wrong in the code. This world simply
// could not tell: no two things in it shared a number, so "role 910131" and
// "department 910131" were never both real. On a real installation they are
// (six tables count their rows separately; a fresh install already holds
// roles 1 to 14).
//
// So a role, a user group, a department, a small group and a leadership role
// of A now all carry ONE number, SN, which is also KP's account number; each
// has one person of its own (see the constants, and the four lines D17 checks
// at its top). Six dates are each listed, through the real save, for ONE of
// those kinds and nothing else. For each, the copy and the real rule must
// both name exactly that kind's own person — WRITTEN OUT BY HAND, because a
// mistake made the same way in the rule and the copy agrees with itself.
// Every pair of kinds is covered, both ways round, including the two pairs
// that share a column name and so are the easiest to mix up: a role and a
// leadership role ("roleID"), a user group and a small group ("groupID").
$k17     = pa_quietWorld(6, 'D17K');
$oneKind17 = [
    ['person', KP_ID, 'a PERSON', 'KP, whose account number it is'],
    ['role', KR_ID, 'a ROLE', 'KR, who holds that role'],
    ['user_group', KU_ID, 'a USER GROUP', 'KU, who is in that user group'],
    ['department', KD_ID, 'a DEPARTMENT', 'KD, who is in that department'],
    ['small_group', KS_ID, 'a SMALL GROUP', 'KS, who is in that small group'],
    ['leadership_role', KL_ID, 'a LEADERSHIP ROLE', 'KL, who holds that leadership role'],
];
$saved17k = [];
foreach ($oneKind17 as $i17 => [$kind17k, $unusedPerson, $unusedWords, $unusedWho]) {
    $saved17k[$kind17k] = FeedAdmin::saveChoice($mysqli, $k17[$i17], 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => [$kind17k => [SN]]]);
}
// The PERSON branch is held a little differently. It reads the row's
// `userID` column, which the portal fills for a person's row only, so on the
// portal's own rows dropping that branch's kind test changes no answer. The
// ROLE row just saved is therefore given M's number in that column, by hand
// (M is a member of A on no list): with the person branch's kind test
// dropped, in the rule or in the copy, M would be let in as if named.
$roleChoice17k = (int) ($saved17k['role']['choiceId'] ?? 0);
pa_q("UPDATE tblExternalAudienceMembers SET userID = ? WHERE feedID = ? AND ownerType = 'choice' AND ownerID = ? AND kind = 'role' AND refID = ?", 'iiii', [M, FA1, $roleChoice17k, SN]);
$marked17k = (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE feedID = ? AND ownerType = 'choice' AND ownerID = ? AND kind = 'role' AND userID = ?", 'iii', [FA1, $roleChoice17k, M])['n'];
$copy17k = pa_copySig(FA1);
$real17k = pa_realSigs(pa_full(FA1));
foreach ($oneKind17 as $i17 => [$kind17k, $person17k, $words17k, $who17k]) {
    $want17k = 'groups|' . $person17k;
    pa_ok(
        'D17 R9 a date listed only for ' . $words17k . ' numbered SN: exactly ' . $who17k . ' — the copy and the real rule both say "groups|' . $person17k . '", as written out by hand, naming none of the five others whose own thing carries the same number'
            . ($kind17k === 'role' ? ' (nor M, whose number that role row carries in its userID column, where it must count for nothing)' : ''),
        ($saved17k[$kind17k]['ok'] ?? false) === true && $marked17k === 1 && ($copy17k[$k17[$i17]] ?? '') === $want17k && ($real17k[$k17[$i17]] ?? '') === $want17k,
        pa_show(['saved' => $saved17k[$kind17k]['message'] ?? null, 'role rows carrying M (must be 1)' => $marked17k, 'the copy' => $copy17k[$k17[$i17]] ?? null, 'the real rule' => $real17k[$k17[$i17]] ?? null, 'by hand' => $want17k])
    );
}

// ---- The copy's "today", with the database clock PINNED (fix round 5, K12-K14; fix round 6, M1) ----
// FeedAdmin::ruleToday() must give the ORGANISATION's date (Europe/London
// here) from the DATABASE's clock. A wrong "today" is only visible at a
// moment when the wrong zone is on a different DATE from London, so each
// pin sits where one family of mistakes shows, and cannot see the other:
//
//   1. 00:30 London on 15 July (summer time). UTC and every zone BEHIND
//      London (New York; a US server's connection) are still on the
//      previous day, so a date taken there is caught. Zones AHEAD (Berlin,
//      a fixed +01:00, the test connection's +13:00 — which is what
//      `SELECT CURDATE()` would use) are on the same day as London and are
//      NOT caught here.
//   2. 00:30 London on the night the clocks go back — still summer time,
//      90 minutes before the change. The same as pin 1, on that night.
//   3. 23:30 London that same Sunday — winter time, AFTER the change (fix
//      round 6, check round 6 M1). Every zone AHEAD of London — Berlin, a
//      fixed +01:00, and the +13:00 test connection's CURDATE() — is
//      already on the NEXT day, so a date taken there is caught. UTC equals
//      London in winter, so a UTC date is NOT caught here (pins 1 and 2
//      catch it).
//
// Pins 1 and 2 ("behind"): RA4's only term (TY) ended the previous LOCAL
// day — adding RA4 must change nothing; RA5's only term (TS) starts on the
// pinned local day — adding RA5 must let TS in. Pin 3 ("ahead"): RA4's
// only term (TT) starts the NEXT local day — adding RA4 must change
// nothing; RA5's only term (TE) ends on the pinned local day — adding RA5
// must still let TE in. Each pin also checks ruleToday() directly, and
// first checks that the pin really discriminates (UTC on the day before,
// or Berlin and CURDATE() on the day after) — so a pin moved to a harmless
// moment fails rather than quietly checking nothing. A "today" a day or
// more AHEAD passes pins 1 and 2's two saves by chance; the direct date
// check and pin 3 catch it (and so do the unpinned S3-S6).
//
// Because each pin sets the database clock itself, what these cases see
// does not depend on the time of day the self-test is run — check round 6
// found `CURDATE()` caught only by an evening run of the unpinned cases.
// What is NOT tested: the change hour itself (01:00-02:00, when London's
// clock repeats an hour) and the night the clocks go forward; neither can
// move the DATE, as both happen after 01:00. All pins are in LAST year, so
// they are always in the past (the events are dated from the real today,
// so they stay ahead of the pinned clock), and no date here goes stale.
// pa_full() is given the same pinned date.
$lastYear17 = (int) pa_todayLocal()->format('Y') - 1;
$backNight17 = new DateTimeImmutable('last sunday of october ' . $lastYear17, pa_zone());
$pins17 = [
    'a summer night (15 July, 00:30 London)' => [new DateTimeImmutable($lastYear17 . '-07-15 00:30:00', pa_zone()), 'behind'],
    'the night the clocks go back (00:30 London, still summer time)' => [$backNight17->setTime(0, 30), 'behind'],
    'the day the clocks went back (23:30 London, winter time, after the change)' => [$backNight17->setTime(23, 30), 'ahead'],
];
foreach ($pins17 as $what17 => [$pin17, $kind17]) {
    $pinDay17  = $pin17->format('Y-m-d');
    $prevDay17 = $pin17->modify('-1 day')->format('Y-m-d');
    $nextDay17 = $pin17->modify('+1 day')->format('Y-m-d');
    $utcDay17    = $pin17->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    $berlinDay17 = $pin17->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    pa_quietWorld(1, 'D17pin');
    pa_q('DELETE FROM tblLeadershipAssignments WHERE roleID IN (?, ?)', 'ii', [RA4, RA5]);
    if ($kind17 === 'behind') {
        // RA4: only TY, ended the previous local day. RA5: only TS, starts on the pinned local day.
        pa_q(
            'INSERT INTO tblLeadershipAssignments (siteID, roleID, userID, startDate, endDate, isActive) VALUES (?, ?, ?, NULL, ?, 1), (?, ?, ?, ?, NULL, 1)',
            'iiisiiis', [ORG_A, RA4, TY_ID, $prevDay17, ORG_A, RA5, TS_ID, $pinDay17]
        );
        $ra4Says17 = 'its only term ended the previous LOCAL day';
        $ra5Says17 = 'its only term starts on the pinned LOCAL day: TS gains it';
    } else {
        // RA4: only TT, starts the next local day. RA5: only TE, ends on the pinned local day.
        pa_q(
            'INSERT INTO tblLeadershipAssignments (siteID, roleID, userID, startDate, endDate, isActive) VALUES (?, ?, ?, ?, NULL, 1), (?, ?, ?, NULL, ?, 1)',
            'iiisiiis', [ORG_A, RA4, TT_ID, $nextDay17, ORG_A, RA5, TE_ID, $pinDay17]
        );
        $ra4Says17 = 'its only term starts the NEXT local day';
        $ra5Says17 = 'its only term ends on the pinned LOCAL day: TE still holds it and gains it';
    }
    $mysqli->query('SET timestamp = ' . $pin17->getTimestamp());
    try {
        $copyDay17 = (new ReflectionMethod(FeedAdmin::class, 'ruleToday'))->invoke(null, $mysqli, ORG_A);
        $curDay17  = (string) (pa_one('SELECT CURDATE() AS d')['d'] ?? '');
        pa_ok(
            'D17 pinned at ' . $what17 . ': ruleToday() gives the LOCAL date ' . $pinDay17 . ($kind17 === 'behind'
                ? ' (UTC is still on the day before, so the pin can catch a date taken in UTC or a zone behind)'
                : ' (Berlin and this test connection\'s CURDATE() are already on the next day, so the pin can catch a date taken in a zone ahead)'),
            $copyDay17 === $pinDay17 && ($kind17 === 'behind'
                ? $utcDay17 === $prevDay17
                : ($berlinDay17 === $nextDay17 && $curDay17 === $nextDay17 && $utcDay17 === $pinDay17)),
            pa_show(['ruleToday' => $copyDay17, 'local' => $pinDay17, 'utc' => $utcDay17, 'berlin' => $berlinDay17, 'CURDATE() on the test connection' => $curDay17])
        );
        $pinRule17 = pa_oracle('D17 pinned at ' . $what17 . ', set-up: a Selected-groups rule [SG1] narrowing a Members event: count 1', FA1, static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $rule17('D17pin', 'groups', 'D17pin', ['audience' => ['small_group' => [SG1]]])), 1, $one17, $pinDay17);
        pa_oracle('D17 pinned at ' . $what17 . ': RA4 added — ' . $ra4Says17 . ': count 0, "Saved."', FA1, $edit17($pinRule17, 'D17pin', ['small_group' => [SG1], 'leadership_role' => [RA4]]), 0, 'Saved.', $pinDay17);
        pa_oracle('D17 pinned at ' . $what17 . ': RA5 added — ' . $ra5Says17 . ' — count 1', FA1, $edit17($pinRule17, 'D17pin', ['small_group' => [SG1], 'leadership_role' => [RA4, RA5]]), 1, $one17, $pinDay17);
    } finally {
        $mysqli->query('SET timestamp = DEFAULT');
    }
}

// =============================================================================
// D18 — the stale line (C10 item 6)
// =============================================================================
pa_heading('D18 — the stale line');
pa_resetWorld();
$staleEv = pa_event(FA1, 'd18-1@p8', 'D18 stale');
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE eventID = ?', 'i', [$staleEv]);
// A REMOVED (soft-deleted) event past its recheck moment too: it is shown
// nowhere, so it must never be counted in the stale line (fix round 8,
// check round 8 L5 / faults C8-15, C8-16). The count must stay 1.
$staleGone = pa_event(FA1, 'd18-gone@p8', 'D18 stale but removed', ['deleted' => 1]);
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE eventID = ?', 'i', [$staleGone]);
$fa1In18 = array_values(array_filter(FeedAdmin::calendars($mysqli, ORG_A), static fn (array $c): bool => (int) $c['feedID'] === FA1))[0] ?? [];
$evRow18 = FeedAdmin::events($mysqli, FA1, ORG_A, 1)['rows'][0] ?? [];
pa_ok(
    'D18 a row one minute overdue -> stale = 1 (calendar(), calendars() and the events list all agree) — a removed event just as overdue is not counted',
    (int) FeedAdmin::calendar($mysqli, FA1, ORG_A)['stale'] === 1 && (int) ($fa1In18['stale'] ?? -1) === 1 && ($evRow18['stale'] ?? null) === true
);
// An hour AHEAD of UTC is still eleven hours BEHIND this session's own
// clock (D0: +13:00), so a comparison made with NOW() instead of
// UTC_TIMESTAMP() would call it stale — all three readers are checked
// (fix round 2, checker round 2 LOW 4 / fault N18).
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() + INTERVAL 1 HOUR WHERE eventID = ?', 'i', [$staleEv]);
$fa1In18b = array_values(array_filter(FeedAdmin::calendars($mysqli, ORG_A), static fn (array $c): bool => (int) $c['feedID'] === FA1))[0] ?? [];
$evRow18b = FeedAdmin::events($mysqli, FA1, ORG_A, 1)['rows'][0] ?? [];
pa_ok(
    'D18 a row an hour ahead -> stale = 0 in calendar(), calendars() and the events list',
    (int) FeedAdmin::calendar($mysqli, FA1, ORG_A)['stale'] === 0 && (int) ($fa1In18b['stale'] ?? -1) === 0 && ($evRow18b['stale'] ?? null) === false
);
pa_ok(
    'D18 the message helper, word for word: nothing for 0, the singular for 1, the plural for 3 (checker LOW 1)',
    FeedAdmin::waitingForRefreshMessage(0) === ''
    && FeedAdmin::waitingForRefreshMessage(1) === '1 event is waiting for the scheduled refresh to update who can see it.'
    && FeedAdmin::waitingForRefreshMessage(3) === '3 events are waiting for the scheduled refresh to update who can see them.'
);

// =============================================================================
// D19 — one clock (C10 item 2)
// =============================================================================
pa_heading('D19 — one clock');
pa_resetWorld();
$pinned19 = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-3 days');
$want19   = $pinned19->format('Y-m-d H:i:s');
$mysqli->query('SET timestamp = ' . $pinned19->getTimestamp());
try {
    $back19    = (string) (pa_one('SELECT UTC_TIMESTAMP() AS t')['t'] ?? '');
    $clockEv   = pa_event(FA1, 'd19@p8', 'D19 event');
    $r19       = FeedAdmin::saveChoice($mysqli, $clockEv, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [$clockEv . ':' . pa_cf($clockEv)]]);
    $decided19 = pa_one('SELECT decidedAt FROM tblExternalEventApprovals WHERE eventID = ? ORDER BY approvalID DESC LIMIT 1', 'i', [$clockEv])['decidedAt'] ?? null;
    $decideEv  = pa_event(FA1, 'd19b@p8', 'D19b event');
    $rRule19   = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D19b', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D19b']]]);
    $p19       = pa_pending($decideEv);
    $rDec19    = ($p19 === null) ? ['decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($p19)]);
    $decided19b = ($p19 === null) ? null : (pa_one('SELECT decidedAt FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [(int) $p19['approvalID']])['decidedAt'] ?? null);
} finally {
    $mysqli->query('SET timestamp = DEFAULT');
}
pa_ok('D19 the database clock pin took', $back19 === $want19, "asked {$want19}, read back {$back19}");
pa_ok('D19 saveChoice(): the approved row\'s decidedAt equals the pinned moment', $r19['ok'] === true && (string) $decided19 === $want19, pa_show($r19) . ' decidedAt=' . (string) $decided19);
pa_ok('D19 decide(): decidedAt equals the pinned moment', $rRule19['ok'] === true && $rDec19['decided'] === 1 && (string) $decided19b === $want19, (string) $decided19b);

// The rule PREVIEW uses the database's clock too (plan section 4.1; fix
// round 2, checker round 2 LOW 4 / fault N20). A draft rule whose last day
// was five days ago: by the real clock it has ended; with the database's
// clock pinned ten days back it has not. Only the database's clock gives
// "waits for approval" while pinned — PHP's clock, shifted or not, cannot.
pa_resetWorld();
$prevEv   = pa_event(FA1, 'd19p@p8', 'D19 preview event');
$lastDay  = (new DateTimeImmutable('now', pa_zone()))->modify('-5 days')->format('Y-m-d');
$draft19  = ['name' => 'D19p', 'audienceLevel' => 'public', 'isActive' => '1', 'toDate' => $lastDay, 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D19 preview']]];
$outcome19 = static fn (array $r): string => (string) ($r['lines'][0]['outcome'] ?? 'none');
$realClock = FeedAdmin::previewRule($mysqli, FA1, 0, ORG_A, SA, $draft19);
$mysqli->query('SET timestamp = ' . (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 days')->getTimestamp());
try {
    $pinnedClock = FeedAdmin::previewRule($mysqli, FA1, 0, ORG_A, SA, $draft19);
} finally {
    $mysqli->query('SET timestamp = DEFAULT');
}
pa_ok(
    'D19 previewRule() follows the database\'s clock: a rule that ended five days ago previews as ended, and as "waits for approval" with the database pinned ten days back',
    $outcome19($realClock) === 'rule_not_active_yet_or_ended' && $outcome19($pinnedClock) === 'waits_for_approval',
    pa_show([$outcome19($realClock), $outcome19($pinnedClock)])
);

// =============================================================================
// D20 — deciding (C10 item 1)
// =============================================================================
pa_heading('D20 — deciding');
pa_resetWorld();
$dEv1 = pa_event(FA1, 'd20-1@p8', 'D20 one');
$dEv2 = pa_event(FA1, 'd20-2@p8', 'D20 two');
$r20  = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D20', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D20']]]);
$p20a = pa_pending($dEv1);
$p20b = pa_pending($dEv2);
pa_ok('D20 set-up: two real waiting rows', $r20['ok'] === true && $p20a !== null && $p20b !== null);
$rApprove = ($p20a === null) ? ['decided' => -1, 'message' => ''] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', 'Looks fine', [pa_item($p20a)]);
$row20a   = ($p20a === null) ? null : pa_one('SELECT status, decidedByID, decisionNote FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [(int) $p20a['approvalID']]);
pa_ok(
    'D20 approve one: decided = 1, exactly "Approved 1." (a one-off date, so no "Other dates …" sentence), approved by SA with the note, the event widened in the same call',
    $rApprove['decided'] === 1 && $rApprove['message'] === 'Approved 1.' && $row20a !== null && $row20a['status'] === 'approved'
    && (int) $row20a['decidedByID'] === SA && $row20a['decisionNote'] === 'Looks fine' && pa_ev($dEv1)['importLevel'] === 'public'
    && ($p20a !== null && pa_nearDbNow('tblExternalEventApprovals', 'decidedAt', 'approvalID = ?', 'i', [(int) $p20a['approvalID']])),
    pa_show($rApprove)
);
$rDecline = ($p20b === null) ? ['decided' => -1, 'message' => ''] : FeedAdmin::decide($mysqli, ORG_A, SA, 'declined', '', [pa_item($p20b)]);
pa_ok(
    'D20 decline the other: decided = 1, exactly "Declined 1." (a one-off date, no "Other dates …" sentence), the row declined, the event back at the calendar\'s own setting',
    $rDecline['decided'] === 1 && $rDecline['message'] === 'Declined 1.' && $p20b !== null && pa_status((int) $p20b['approvalID']) === 'declined'
    && pa_ls($dEv2) === 'members/calendar',
    pa_show($rDecline) . ' ' . pa_ls($dEv2)
);

pa_resetWorld();
$staleEv20 = pa_event(FA1, 'd20-stale@p8', 'D20 stale');
FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D20s', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D20 stale']]]);
$ps20   = pa_pending($staleEv20);
$rStale = ($ps20 === null) ? ['changed' => -1, 'message' => ''] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [['approvalID' => (int) $ps20['approvalID'], 'requestHash' => str_repeat('a', 64), 'contentHash' => $ps20['contentHash']]]);
pa_ok(
    'D20 a stale REQUEST fingerprint: changed = 1 with the exact sentence, the row still waiting',
    $ps20 !== null && $rStale['changed'] === 1 && $rStale['decided'] === 0
    && $rStale['message'] === 'Approved 0. 1 had changed since you opened the page; please review it again.'
    && pa_status((int) $ps20['approvalID']) === 'pending' && pa_ev($staleEv20)['importSource'] === 'waiting',
    pa_show($rStale)
);
// Checker M5: a stale CONTENT fingerprint — the event's details changed
// after the page was opened, and a real resolve has since refreshed the
// waiting row. Deciding with what the page showed must NOT go through.
$opened20 = ($ps20 === null) ? null : pa_item($ps20);
pa_q('UPDATE tblEvents SET eventName = ? WHERE eventID = ?', 'si', ['D20 stale, details changed', $staleEv20]);
$rResolve20 = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'any', 'choice' => 'none']]]); // any real locked write resolves
$after20    = ($ps20 === null) ? null : pa_one('SELECT status, contentHash FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [(int) $ps20['approvalID']]);
$rStaleC    = ($opened20 === null) ? ['changed' => -1, 'message' => ''] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [$opened20]);
pa_ok(
    'D20 a stale CONTENT fingerprint (details changed since the page opened): changed = 1, the row still waiting (checker M5)',
    $rResolve20['ok'] === true && $after20 !== null && $after20['status'] === 'pending' && $after20['contentHash'] !== $opened20['contentHash']
    && $rStaleC['changed'] === 1 && $rStaleC['decided'] === 0 && pa_status((int) $ps20['approvalID']) === 'pending'
    && pa_ev($staleEv20)['importSource'] === 'waiting',
    pa_show([$rResolve20, $rStaleC])
);

// B's waiting row, made through B's own real write path, decided AS A.
pa_resetWorld();
pa_q("UPDATE tblExternalFeeds SET audienceLevel = 'members' WHERE feedID = ?", 'i', [FB1]);
$bEv20 = pa_event(FB1, 'd20-b@p8', 'D20 BONLY event');
$rB20  = FeedAdmin::saveRule($mysqli, FB1, 0, ORG_B, SB, ['name' => 'D20 BONLY', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D20 BONLY']]]);
$bp20  = pa_pending($bEv20);
pa_ok('D20 set-up: B has a real waiting row (B\'s rule widening B\'s Members calendar)', $rB20['ok'] === true && $bp20 !== null, pa_show($rB20));
$rAsA = ($bp20 === null) ? ['changed' => -1, 'decided' => -1, 'message' => ''] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($bp20)]);
pa_ok(
    'D20 B\'s row decided AS A: changed = 1 (the same answer as a stale one), nothing decided, B\'s row and event untouched',
    $bp20 !== null && $rAsA['changed'] === 1 && $rAsA['decided'] === 0
    && $rAsA['message'] === 'Approved 0. 1 had changed since you opened the page; please review it again.'
    && pa_status((int) $bp20['approvalID']) === 'pending' && pa_ev($bEv20)['importSource'] === 'waiting',
    pa_show($rAsA)
);

// Checks that need NO database come before any SQL: a CLOSED connection
// proves it (part 7's E6 method) — any statement would throw an Error
// about the closed connection instead of the answer checked for here.
$closed = pa_connect();
$closed->close();
$maybeThrew = null;
try {
    FeedAdmin::decide($closed, ORG_A, SA, 'maybe', '', [['approvalID' => 1, 'requestHash' => 'x', 'contentHash' => 'y']]);
} catch (Throwable $e) {
    $maybeThrew = $e;
}
pa_ok('D20 the word "maybe" throws InvalidArgumentException BEFORE any SQL (a closed connection is never touched)', $maybeThrew instanceof InvalidArgumentException, $maybeThrew === null ? 'did not throw' : get_class($maybeThrew) . ': ' . $maybeThrew->getMessage());
$noteThrew = null;
$noteLong  = null;
$bulkLong  = null;
try {
    $noteLong = FeedAdmin::decide($closed, ORG_A, SA, 'approved', str_repeat('n', 501), [['approvalID' => 1, 'requestHash' => 'x', 'contentHash' => 'y']]);
    $bulkLong = FeedAdmin::decide($closed, ORG_A, SA, 'approved', '', array_fill(0, FeedAdmin::MAX_BULK + 1, ['approvalID' => 1, 'requestHash' => 'x', 'contentHash' => 'y']));
} catch (Throwable $e) {
    $noteThrew = $e;
}
pa_ok(
    'D20 a 501-character note and 201 dates are refused, word for word, BEFORE any SQL (checker M3, LOW 4)',
    $noteThrew === null && ($noteLong['status'] ?? '') === 'refused' && ($noteLong['message'] ?? '') === 'Note can be at most 500 characters.'
    && ($bulkLong['status'] ?? '') === 'refused' && ($bulkLong['message'] ?? '') === 'You can decide at most 200 dates at once.',
    $noteThrew !== null ? get_class($noteThrew) . ': ' . $noteThrew->getMessage() : pa_show([$noteLong, $bulkLong])
);

// Siblings: three waiting dates of one series, identical request and content.
pa_resetWorld();
[$sib1, $sib2, $sib3] = pa_series(FA1, 'd20-sib@p8', 'D20 series', ['count' => 3]);
FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D20sib', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D20 series']]]);
$first20 = pa_pending($sib1);
$rSib    = ($first20 === null) ? ['decided' => -1, 'message' => ''] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($first20)]);
$sib2row = pa_apprWithStatus($sib2, 'approved')[0] ?? null;
pa_ok(
    'D20 one of three identical dates decided: decided = 1, the other two approved and public, exactly "Approved 1." + the one "Other dates of the same repeating event may follow …" sentence (one event, singular), and the result carries no `followed` count (fix round 12, the owner\'s decision)',
    $rSib['decided'] === 1 && $rSib['message'] === 'Approved 1.' . MSG_OTHER_ONE && array_key_exists('followed', $rSib) === false
    // `decidedIds` joined the result in chunk P8-4 (the numbers of the dates
    // really decided, for the activity log — D39 checks what it holds); the
    // rest of the shape, and the absence of `followed`, are unchanged.
    && array_keys($rSib) === ['ok', 'status', 'message', 'decided', 'changed', 'notDone', 'failure', 'decidedIds']
    && pa_ev($sib2)['importLevel'] === 'public' && pa_ev($sib3)['importLevel'] === 'public',
    pa_show($rSib)
);
pa_ok(
    'D20 the followers keep their own reason (new_match) and name the decided row in their note',
    $first20 !== null && $sib2row !== null && $sib2row['reason'] === 'new_match'
    && $sib2row['decisionNote'] === 'Follows the decision on another date of this repeating event (approval #' . (int) $first20['approvalID'] . ')',
    pa_show($sib2row)
);
// Two repeating events at once: "events", plural.
pa_resetWorld();
$serX = pa_series(FA1, 'd20-x@p8', 'D20 plural', ['count' => 2]);
$serY = pa_series(FA1, 'd20-y@p8', 'D20 plural', ['count' => 2]);
FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D20pl', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D20 plural']]]);
$px = pa_pending($serX[0]);
$py = pa_pending($serY[0]);
$rPl = ($px === null || $py === null) ? ['message' => ''] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($px), pa_item($py)]);
pa_ok('D20 one date of each of two repeating events: exactly "Approved 2." + the sentence with "repeating events", plural', $rPl['message'] === 'Approved 2.' . MSG_OTHER_MANY, pa_show($rPl));

// A rule whose last day passed since the last refresh (fix round 8, check
// round 8 L1, its probe P4). A rule "Everyone" whose last day is tomorrow;
// the dates of each series wait. The database clock is moved three days on
// (as a real day passes), and a decision is made before any refresh has
// re-worked the calendar. The same resolve WITHDRAWS the other dates' rows
// — the rule has run out. These cases once checked a count of dates that
// "did not follow this decision"; since fix round 12 (the owner's decision,
// plan item 10) the message carries no count, only the one always-true
// sentence, so they now check that sentence word for word AND, as before,
// exactly where every date stands afterwards. Six cases:
//   - an approval, and the same as a DECLINE (fix round 9, R9-L2 / C9-01);
//   - one date of each of TWO series: "repeating events" (C9-02);
//   - a TWO-date series, so exactly ONE other date (fix round 10, R10-L2;
//     it once pinned the singular "1 other date", and now pins that the
//     one sentence is the same whatever the number of other dates);
//   - TWO rules matching the series, one ending (check round 10's P1): the
//     request CHANGES, so the same resolve withdraws the old rows AND files
//     new waiting ones — the dates wait again, in the inbox (3 of them).
//     This is why no sentence may claim they "no longer wait" (fix round
//     10, R10-L1), and one reason the owner chose a sentence that makes no
//     claim about any one date;
//   - the control, with the rule still in date, where all three follow.
// [decision, series, dates per series, clock moved, a second rule with no end]
$cases20 = [
    'approve, ended'                      => ['approved', 1, 3, true, false],
    'decline, ended'                      => ['declined', 1, 3, true, false],
    'approve two series, ended'           => ['approved', 2, 3, true, false],
    'approve a two-date series, ended'    => ['approved', 1, 2, true, false],
    'approve, two rules, one ended'       => ['approved', 1, 3, true, true],
    'approve, in date (control)'          => ['approved', 1, 3, false, false],
];
foreach ($cases20 as $what20 => [$decision20, $series20, $dates20, $clockMoved20, $secondRule20]) {
    pa_resetWorld();
    $sers20 = [];
    for ($k = 0; $k < $series20; $k++) {
        $sers20[] = pa_series(FA1, 'd20-p4-' . $k . '@p8', 'D20 p4 youth', ['count' => $dates20]);
    }
    $last20 = pa_todayLocal()->modify('+1 day')->format('Y-m-d');
    $rr20   = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D20 p4', 'audienceLevel' => 'public', 'isActive' => '1', 'toDate' => $last20, 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D20 p4 youth']]]);
    if ($secondRule20 === true) {
        $rr20b = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D20 p4 general', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D20 p4 youth']]]);
        $rr20['ok'] = ($rr20['ok'] ?? false) === true && ($rr20b['ok'] ?? false) === true;
    }
    $items20 = [];
    foreach ($sers20 as $ser20) {
        $p20 = pa_pending($ser20[0]);
        if ($p20 !== null) {
            $items20[] = pa_item($p20);
        }
    }
    if ($clockMoved20 === true) {
        $mysqli->query('SET timestamp = UNIX_TIMESTAMP() + 3 * 86400');
    }
    try {
        $d20 = (count($items20) !== $series20) ? ['message' => '', 'decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, $decision20, '', $items20);
    } finally {
        $mysqli->query('SET timestamp = DEFAULT');
    }
    $waiting20 = (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE feedID = ? AND status = 'pending'", 'i', [FA1])['n'];
    // The newest row of each date: with one rule, the decided date carries
    // the decision and the others are withdrawn (or all follow, in the
    // control); with two rules, every date's newest row waits again.
    $st20 = [];
    foreach ($sers20 as $ser20) {
        $st20[] = array_map(static fn (int $e): string => (string) (pa_one('SELECT status FROM tblExternalEventApprovals WHERE eventID = ? ORDER BY approvalID DESC LIMIT 1', 'i', [$e])['status'] ?? ''), $ser20);
    }
    $verb20    = ($decision20 === 'approved') ? 'Approved' : 'Declined';
    $want20    = $verb20 . ' ' . $series20 . '.' . ($series20 === 1 ? MSG_OTHER_ONE : MSG_OTHER_MANY);
    if ($clockMoved20 === true) {
        // One rule: the other dates' rows are withdrawn and nothing waits.
        // Two rules, one ended: the request changed, so every date of the
        // series waits again under the new request — 3 in the inbox.
        $wantWaiting20 = ($secondRule20 === true) ? $dates20 : 0;
        $wantSt20      = ($secondRule20 === true)
            ? array_fill(0, $series20, array_fill(0, $dates20, 'pending'))
            : array_fill(0, $series20, array_merge([$decision20], array_fill(0, $dates20 - 1, 'withdrawn')));
        pa_ok(
            'D20 ' . $what20 . ' — the rule\'s last day has passed since the last refresh: decided ' . $series20 . ', ' . $wantWaiting20
            . ' date(s) waiting afterwards, every date where it should be, and exactly "' . $want20 . '"',
            ($rr20['ok'] ?? false) === true && $d20['decided'] === $series20
            && $waiting20 === $wantWaiting20 && $st20 === $wantSt20 && $d20['message'] === $want20,
            pa_show([$d20['message'] ?? null, 'waiting' => $waiting20, $st20])
        );
    } else {
        pa_ok(
            'D20 ' . $what20 . ': all three approved, nothing left waiting, exactly "' . $want20 . '"',
            ($rr20['ok'] ?? false) === true && $d20['decided'] === 1 && $waiting20 === 0
            && $st20 === [['approved', 'approved', 'approved']]
            && $d20['message'] === $want20,
            pa_show([$d20['message'] ?? null, 'waiting' => $waiting20, $st20])
        );
    }
}

// Check round 11's Q1 (kept in fix round 12 for where every date stands;
// its message check now expects the one sentence). Two rules: "D20 q1
// hall" asks for the dates whose location contains "hall", and its last
// day is tomorrow; "D20 q1 all" asks for every date of the series, with no
// end. So the two hall dates W0 and W1 wait under one request ({both
// rules}) and the chapel date W2 under another ({the second rule}). Three
// days on — the first rule has ended, no refresh since — W0 and W2 are
// decided together, in ONE call. The same resolve withdraws W1's old row
// (its request changed) and files a NEW row for W1 under W2's request,
// which follows W2's decision at once. Fix rounds 8-10 judged W1 by its
// OLD, withdrawn row and reported that it "did not follow" — one of the
// three counting methods the owner's decision retired (plan item 10). The
// same for a decline.
foreach (['approved', 'declined'] as $decision20q) {
    pa_resetWorld();
    $w20q = [
        pa_event(FA1, 'd20-q1@p8', 'D20 q1 youth', ['day' => 7, 'key' => 'W0', 'location' => 'Hall A']),
        pa_event(FA1, 'd20-q1@p8', 'D20 q1 youth', ['day' => 14, 'key' => 'W1', 'location' => 'Hall A']),
        pa_event(FA1, 'd20-q1@p8', 'D20 q1 youth', ['day' => 21, 'key' => 'W2', 'location' => 'Chapel']),
    ];
    $last20q = pa_todayLocal()->modify('+1 day')->format('Y-m-d');
    $rq1     = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D20 q1 hall', 'audienceLevel' => 'public', 'isActive' => '1', 'toDate' => $last20q, 'conditions' => [
        ['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D20 q1 youth'],
        ['matchField' => 'location', 'matchType' => 'contains', 'matchValue' => 'hall'],
    ]]);
    $rq2     = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D20 q1 all', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [
        ['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D20 q1 youth'],
    ]]);
    $old20q = array_map(static fn (int $e): ?array => pa_pending($e), $w20q);
    // The set-up must really give the hall dates one request and the
    // chapel date another, or this case would prove nothing.
    $set20q = ($rq1['ok'] ?? false) === true && ($rq2['ok'] ?? false) === true
        && in_array(null, $old20q, true) === false
        && $old20q[0]['requestHash'] === $old20q[1]['requestHash']
        && $old20q[0]['requestHash'] !== $old20q[2]['requestHash'];
    $mysqli->query('SET timestamp = UNIX_TIMESTAMP() + 3 * 86400');
    try {
        $d20q = ($set20q === false) ? ['message' => '', 'decided' => -1]
            : FeedAdmin::decide($mysqli, ORG_A, SA, $decision20q, '', [pa_item($old20q[0]), pa_item($old20q[2])]);
    } finally {
        $mysqli->query('SET timestamp = DEFAULT');
    }
    $newest20q  = array_map(static fn (int $e): array => pa_one('SELECT approvalID, status, reason FROM tblExternalEventApprovals WHERE eventID = ? ORDER BY approvalID DESC LIMIT 1', 'i', [$e]) ?? [], $w20q);
    $waiting20q = (int) pa_one("SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE feedID = ? AND status = 'pending'", 'i', [FA1])['n'];
    $verb20q    = ($decision20q === 'approved') ? 'Approved' : 'Declined';
    $want20q    = $verb20q . ' 2.' . MSG_OTHER_ONE;
    pa_ok(
        'D20 ' . $decision20q . ', two rules, one ended, W0 and W2 decided together — set-up: the hall dates wait under one request and the chapel date under another, and afterwards W1\'s old row is withdrawn and a NEW row for W1 carries the decision, as following another date (series_match)',
        $set20q === true && pa_status((int) $old20q[1]['approvalID']) === 'withdrawn'
        && (int) ($newest20q[1]['approvalID'] ?? 0) > (int) $old20q[1]['approvalID']
        && ($newest20q[1]['status'] ?? '') === $decision20q && ($newest20q[1]['reason'] ?? '') === 'series_match',
        pa_show([$set20q, $newest20q])
    );
    pa_ok(
        'D20 ' . $decision20q . ', two rules, one ended, W0 and W2 decided together: decided 2, changed 0, nothing waiting, every date\'s newest row "' . $decision20q . '", exactly "' . $want20q . '" (one repeating event, singular)',
        $d20q['decided'] === 2 && ($d20q['changed'] ?? -1) === 0 && $waiting20q === 0
        && array_column($newest20q, 'status') === [$decision20q, $decision20q, $decision20q]
        && $d20q['message'] === $want20q,
        pa_show([$d20q['message'] ?? null, 'waiting' => $waiting20q, $newest20q])
    );
}

// Two dates of one four-date series decided together (added in fix round
// 11 to count each other date once; since fix round 12 there is no count).
// Both are decided — neither is settled by the other and then reported as
// "changed", because every item of a calendar is decided before its one
// resolve (challenge M2) — and the sentence names ONE repeating event.
pa_resetWorld();
$ser20t = pa_series(FA1, 'd20-twice@p8', 'D20 twice', ['count' => 4]);
$rr20t  = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D20 twice', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D20 twice']]]);
$pt0    = pa_pending($ser20t[0]);
$pt1    = pa_pending($ser20t[1]);
$d20t   = ($rr20t['ok'] !== true || $pt0 === null || $pt1 === null) ? ['message' => '', 'decided' => -1, 'changed' => -1]
    : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($pt0), pa_item($pt1)]);
pa_ok(
    'D20 two dates of one four-date series decided together: decided 2, changed 0, all four approved, exactly "Approved 2." + the singular sentence',
    $d20t['decided'] === 2 && $d20t['changed'] === 0
    && array_map(static fn (int $e): string => (string) (pa_one('SELECT status FROM tblExternalEventApprovals WHERE eventID = ? ORDER BY approvalID DESC LIMIT 1', 'i', [$e])['status'] ?? ''), $ser20t) === ['approved', 'approved', 'approved', 'approved']
    && $d20t['message'] === 'Approved 2.' . MSG_OTHER_ONE,
    pa_show($d20t)
);

// =============================================================================
// D21 — the lock is taken before anything is written
// =============================================================================
// A second connection holds FA1's row lock (as a refresh in progress does);
// the test's own connection waits at most 1 second. EVERY locked write on
// FA1 must then fail with a lock-wait error and leave FA1's data exactly as
// it was. Which of these can tell a MISSING lock statement apart (challenge
// L5) — MEASURED, not reasoned (fix round 2, checker round 2 LOW 7; the
// first version of this comment named decide() as well, and was wrong):
// with the lock statement removed (fault control 7), ONLY removeChoice()
// and deleteRule() stop waiting — they touch only child rows by UPDATE or
// DELETE, which take no lock on the calendar row. Every other call here
// still waits: saveCalendar updates the locked row itself; saveChoice,
// saveCategoryMap and saveRule insert child rows whose foreign-key check
// waits on it; and decide() waits too, because the resolve that follows
// its decisions writes rows whose foreign-key check does the same. All
// seven are called, so the lock is proved for every write, but only
// those two catch the missing statement.
pa_heading('D21 — the lock is taken before anything is written');
pa_resetWorld();
$lockEv  = pa_event(FA1, 'd21@p8', 'D21 event');
$lockEv2 = pa_event(FA1, 'd21-choice@p8', 'D21 choice event');
$rR21    = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D21 rule', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D21 event']]]);
$rC21    = FeedAdmin::saveChoice($mysqli, $lockEv2, 'date', ORG_A, SA, ['audienceLevel' => 'members']);
$rD21    = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D21 delete me', 'audienceLevel' => 'members', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'matches nothing']]]);
$p21     = pa_pending($lockEv);
pa_ok('D21 set-up: a waiting row, a choice to remove and a rule to delete, all made through FeedAdmin', $rR21['ok'] === true && $rC21['ok'] === true && $rD21['ok'] === true && $p21 !== null);
$fp21    = pa_fp();
$calls21 = [
    'saveCalendar'    => static fn (): array => FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['name' => 'D21 renamed'])),
    'saveChoice'      => static fn (): array => FeedAdmin::saveChoice($mysqli, $lockEv, 'date', ORG_A, SA, ['audienceLevel' => 'public']),
    'saveCategoryMap' => static fn (): array => FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'x', 'choice' => 'none']]]),
    'saveRule'        => static fn (): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D21 new', 'audienceLevel' => 'members', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'x']]]),
    'removeChoice'    => static fn (): array => FeedAdmin::removeChoice($mysqli, $lockEv2, 'date', ORG_A, SA),
    'deleteRule'      => static fn (): array => FeedAdmin::deleteRule($mysqli, (int) $rD21['ruleId'], FA1, ORG_A, SA),
    'decide'          => static fn (): array => FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($p21)]),
];
$locked21 = pa_whileLocked([FA1], 1, static function () use ($calls21): array {
    $out = [];
    foreach ($calls21 as $name => $call) {
        $out[$name] = pa_timed($call);
    }

    return $out;
});
foreach ($calls21 as $name => $call) {
    $t = $locked21[$name];
    pa_ok(
        "D21 {$name}() with the calendar locked elsewhere: waits for the lock and fails (a lock-wait error)",
        $t['threw'] instanceof mysqli_sql_exception,
        $t['threw'] === null ? 'returned ' . pa_show($t['result']) : get_class($t['threw']) . ': ' . $t['threw']->getMessage()
    );
}
pa_ok('D21 and nothing at all was written by any of them', pa_fp() === $fp21);
$after21 = [];
foreach ($calls21 as $name => $call) {
    $res              = $call();
    $after21[$name] = ($res['ok'] ?? false) === true;
}
pa_ok('D21 control: once the other connection lets go, every one of the same calls succeeds', array_filter($after21, static fn (bool $v): bool => $v === false) === [], pa_show($after21));

// ⏱️ Checker LOW 12: PHP's time limit is raised inside the ONE locked
//    sequence, for every write — not only for decide(). A command line
//    has no limit (0), so one is set here for the check and put back.
$limitBefore = (int) ini_get('max_execution_time');
try {
    set_time_limit(20);
    $r21a    = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'y', 'choice' => 'none']]]);
    $raised  = (int) ini_get('max_execution_time');
    set_time_limit(300);
    $r21b    = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'z', 'choice' => 'none']]]);
    $kept    = (int) ini_get('max_execution_time');
    set_time_limit(0);
    $r21c    = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'w', 'choice' => 'none']]]);
    $none    = (int) ini_get('max_execution_time');
} finally {
    set_time_limit($limitBefore);
}
pa_ok(
    'D21 a locked write (not decide) raises a 20-second limit to 90, never lowers 300, and leaves "no limit" (0) alone (checker LOW 12)',
    $r21a['ok'] === true && $raised === 90 && $r21b['ok'] === true && $kept === 300 && $r21c['ok'] === true && $none === 0,
    pa_show([$raised, $kept, $none])
);
// …and it is raised ONLY when too low: at exactly 90 it is left alone, so
// PHP's count is not restarted at every lock of a long bulk decision (fix
// round 2, checker round 2 LOW 5 / fault N16). A restart leaves no trace
// in `max_execution_time`, so FeedAdmin counts its own calls to
// set_time_limit() in a private property, read here through reflection.
$raises = new ReflectionProperty(FeedAdmin::class, 'timeLimitRaises');
try {
    set_time_limit(90);
    $n0 = (int) $raises->getValue();
    $r21d = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'v', 'choice' => 'none']]]);
    $r21e = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'u', 'choice' => 'none']]]);
    $n1 = (int) $raises->getValue();
    set_time_limit(20);
    $r21f = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 't', 'choice' => 'none']]]);
    $n2 = (int) $raises->getValue();
} finally {
    set_time_limit($limitBefore);
}
pa_ok(
    'D21 two locked writes with the limit already at 90 call set_time_limit() NOT AT ALL; one with 20 calls it exactly once',
    $r21d['ok'] === true && $r21e['ok'] === true && $r21f['ok'] === true && $n1 === $n0 && $n2 === $n0 + 1,
    pa_show([$n0, $n1, $n2])
);

// The after-resolve step runs AFTER the resolve and BEFORE the commit (fix
// round 3, checker round 3 LOW 3 / faults X01, X02). Moving it after the
// commit changes no answer one process can see, so the ORDER is pinned by
// reading inFeedLock()'s own source: in its body, the resolve, then the
// step, then the commit, each exactly once.
$src21 = (string) file_get_contents(dirname(__DIR__) . '/web/_core/FeedAdmin.php');
$from21 = strpos($src21, 'private static function inFeedLock(');
$body21 = ($from21 === false) ? '' : substr($src21, $from21, (int) strpos($src21, "\n    }\n", $from21) - $from21);
$at21 = static fn (string $needle): int => ($body21 !== '' && substr_count($body21, $needle) === 1) ? (int) strpos($body21, $needle) : -1;
$posResolve = $at21('FeedResolver::resolveFeed(');
$posHook    = $at21('$afterResolve($result)');
$posCommit  = $at21('$db->commit();');
pa_ok(
    'D21 in inFeedLock()\'s own source: the resolve, then the after-resolve step, then the commit — each exactly once',
    $posResolve > 0 && $posHook > $posResolve && $posCommit > $posHook,
    pa_show([$posResolve, $posHook, $posCommit])
);
// …and it may only ADD result keys. A step that tries to replace one (here
// `resolved`) is refused with a LogicException, and the whole write — the
// work's own change included — is rolled back. A step adding a new key is
// merged. inFeedLock() is private, so it is reached through reflection.
$lock21 = new ReflectionMethod(FeedAdmin::class, 'inFeedLock');
$name21 = (string) pa_one('SELECT name FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['name'];
$bad21  = pa_timed(static fn () => $lock21->invoke(
    null, $mysqli, FA1, ORG_A,
    static function () use ($mysqli): array {
        $mysqli->query("UPDATE tblExternalFeeds SET name = 'D21 written by the work' WHERE feedID = " . FA1);

        return ['ok' => true, 'status' => 'saved'];
    },
    static fn (array $done): array => ['resolved' => 'replaced']
));
$good21 = $lock21->invoke(null, $mysqli, FA1, ORG_A, static fn (): array => ['ok' => true, 'status' => 'saved'], static fn (array $done): array => ['extra' => 'added']);
pa_ok(
    'D21 an after-resolve step that tries to replace "resolved" is refused (LogicException) and the work\'s write is rolled back; one adding a new key is merged',
    $bad21['threw'] instanceof LogicException && (string) pa_one('SELECT name FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['name'] === $name21
    && ($good21['extra'] ?? null) === 'added' && is_array($good21['resolved'] ?? null),
    pa_show([$bad21['threw'] === null ? 'no throw' : get_class($bad21['threw']) . ': ' . $bad21['threw']->getMessage(), $good21['extra'] ?? null])
);
// The guard covers EVERY key already in the result, not a short list of
// famous ones (fix round 4, check round 4 LOW 2 / fault C4-08, which
// guarded only ok, status and resolved and let the step win for the rest).
// Here the step tries to replace a key the WORK set — `ruleId`, the kind of
// answer a page acts on — and must be refused and rolled back the same way.
$bad21w = pa_timed(static fn () => $lock21->invoke(
    null, $mysqli, FA1, ORG_A,
    static function () use ($mysqli): array {
        $mysqli->query("UPDATE tblExternalFeeds SET name = 'D21 written by the work (2)' WHERE feedID = " . FA1);

        return ['ok' => true, 'status' => 'saved', 'ruleId' => 5];
    },
    static fn (array $done): array => ['ruleId' => 99]
));
pa_ok(
    'D21 an after-resolve step that tries to replace a key the WORK set ("ruleId") is refused (LogicException) and the work\'s write is rolled back',
    $bad21w['threw'] instanceof LogicException && (string) pa_one('SELECT name FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['name'] === $name21,
    pa_show([$bad21w['threw'] === null ? 'no throw' : get_class($bad21w['threw']) . ': ' . $bad21w['threw']->getMessage(), $bad21w['result'] ?? null])
);

// =============================================================================
// D22 — the upgrade count
// =============================================================================
pa_heading('D22 — the upgrade count');
pa_resetWorld();
pa_q("UPDATE tblExternalFeeds SET isActive = 1, apiOptOut = 0, audienceLevel = 'public' WHERE feedID IN (?, ?)", 'ii', [FA1, FA2]);
pa_q("INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, isActive) VALUES (?, 'D22 key', SHA2('d22a',256), 'd22a', 'events:read', 1)", 'i', [ORG_A]);
$base22 = FeedAdmin::apiUpgradeNotice($mysqli);
pa_ok('D22 base: 2 calendars, 1 organisation', $base22['calendars'] === 2 && $base22['organisations'] === 1, pa_show($base22));
$keyId = (int) pa_one('SELECT keyID FROM tblApiKeys WHERE siteID = ?', 'i', [ORG_A])['keyID'];
pa_q('UPDATE tblApiKeys SET scopes = ? WHERE keyID = ?', 'si', ['attendance:read', $keyId]);
pa_ok('D22 only attendance:read -> 0', FeedAdmin::apiUpgradeNotice($mysqli)['calendars'] === 0);
pa_q('UPDATE tblApiKeys SET scopes = ? WHERE keyID = ?', 'si', ['events:*', $keyId]);
pa_ok('D22 events:* -> 2', FeedAdmin::apiUpgradeNotice($mysqli)['calendars'] === 2);
pa_q('UPDATE tblApiKeys SET scopes = ?, isActive = 0 WHERE keyID = ?', 'si', ['events:read', $keyId]);
pa_ok('D22 the key switched off -> 0', FeedAdmin::apiUpgradeNotice($mysqli)['calendars'] === 0);
pa_q('UPDATE tblApiKeys SET isActive = 1, expiresAt = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE keyID = ?', 'i', [$keyId]);
pa_ok('D22 the key expired yesterday -> 0', FeedAdmin::apiUpgradeNotice($mysqli)['calendars'] === 0);
// Checker LOW 7: "expired?" is the DATABASE's question, so PHP's own zone
// must not change the answer. Asked with PHP set far ahead of UTC and far
// behind it; a PHP-clock comparison gets one of the four wrong.
$zoneBefore = date_default_timezone_get();
$answers22  = [];
try {
    foreach (['soon' => '+ INTERVAL 30 MINUTE', 'gone' => '- INTERVAL 30 MINUTE'] as $when => $offset) {
        pa_q("UPDATE tblApiKeys SET expiresAt = UTC_TIMESTAMP() {$offset} WHERE keyID = ?", 'i', [$keyId]);
        foreach (['Pacific/Auckland', 'America/Los_Angeles'] as $zone) {
            date_default_timezone_set($zone);
            $answers22[$when . ' in ' . $zone] = FeedAdmin::apiUpgradeNotice($mysqli)['calendars'];
        }
    }
} finally {
    date_default_timezone_set($zoneBefore);
}
pa_ok(
    'D22 a key expiring in 30 minutes counts and one expired 30 minutes ago does not — whatever PHP\'s zone (checker LOW 7)',
    $answers22 === ['soon in Pacific/Auckland' => 2, 'soon in America/Los_Angeles' => 2, 'gone in Pacific/Auckland' => 0, 'gone in America/Los_Angeles' => 0],
    pa_show($answers22)
);
pa_q('UPDATE tblApiKeys SET expiresAt = NULL WHERE keyID = ?', 'i', [$keyId]);
pa_q('UPDATE tblExternalFeeds SET apiOptOut = 1 WHERE feedID = ?', 'i', [FA1]);
pa_ok('D22 one calendar ticked "Don\'t show via API" -> 1', FeedAdmin::apiUpgradeNotice($mysqli)['calendars'] === 1);
pa_q('UPDATE tblExternalFeeds SET apiOptOut = 0, isActive = 0 WHERE feedID = ?', 'i', [FA1]);
pa_ok('D22 one calendar paused -> 1', FeedAdmin::apiUpgradeNotice($mysqli)['calendars'] === 1);
pa_q("UPDATE tblExternalFeeds SET isActive = 1, audienceLevel = 'members' WHERE feedID = ?", 'i', [FA1]);
pa_ok('D22 one calendar set to Members -> 1', FeedAdmin::apiUpgradeNotice($mysqli)['calendars'] === 1);
pa_q("UPDATE tblExternalFeeds SET audienceLevel = 'public' WHERE feedID = ?", 'i', [FA1]);
pa_q("UPDATE tblExternalFeeds SET isActive = 1, apiOptOut = 0, audienceLevel = 'public' WHERE feedID = ?", 'i', [FB1]);
pa_q("INSERT INTO tblApiKeys (siteID, name, keyHash, keyPrefix, scopes, isActive) VALUES (?, 'D22 B key', SHA2('d22b',256), 'd22b', 'events:read', 1)", 'i', [ORG_B]);
$both22 = FeedAdmin::apiUpgradeNotice($mysqli);
pa_ok('D22 B with its own key and public calendar -> 3 calendars, 2 organisations', $both22['calendars'] === 3 && $both22['organisations'] === 2, pa_show($both22));

// =============================================================================
// D23 — moments on both clock-change nights
// =============================================================================
pa_heading('D23 — moments on both clock-change nights');

function pa_clockChange(string $zoneName, DateTimeImmutable $from, DateTimeImmutable $to, bool $goingBack): ?DateTimeImmutable
{
    $zone  = new DateTimeZone($zoneName);
    $spans = $zone->getTransitions($from->getTimestamp(), $to->getTimestamp());
    if ($spans === false) {
        return null;
    }
    $wasSummer = false;
    foreach ($spans as $i => $span) {
        if ($i === 0) {
            $wasSummer = ($span['isdst'] === true);
            continue;
        }
        $isSummer = ($span['isdst'] === true);
        $wanted   = ($goingBack === true) ? ($isSummer === false && $wasSummer === true) : ($isSummer === true && $wasSummer === false);
        if ($wanted === true) {
            return (new DateTimeImmutable('@' . $span['ts']))->setTimezone(new DateTimeZone('UTC'));
        }
        $wasSummer = $isSummer;
    }

    return null;
}

$nowUtc23   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$later23    = $nowUtc23->modify('+13 months');
$londonZone = pa_zone(SITE_ZONE);
$springFwd  = pa_clockChange(SITE_ZONE, $nowUtc23, $later23, false);
$autumnBack = pa_clockChange(SITE_ZONE, $nowUtc23, $later23, true);
$sBefore = $springFwd === null ? 'none' : FeedAdmin::showMoment($springFwd->modify('-30 minutes')->format('Y-m-d H:i:s'), $londonZone);
$sAfter  = $springFwd === null ? 'none' : FeedAdmin::showMoment($springFwd->modify('+30 minutes')->format('Y-m-d H:i:s'), $londonZone);
pa_ok('D23 the spring night (found from PHP\'s own zone data, after today): 00:30 GMT, then 02:30 BST', $springFwd !== null && str_ends_with($sBefore, '00:30 GMT') && str_ends_with($sAfter, '02:30 BST'), "{$sBefore} | {$sAfter}");
$aBefore = $autumnBack === null ? 'none' : FeedAdmin::showMoment($autumnBack->modify('-30 minutes')->format('Y-m-d H:i:s'), $londonZone);
$aAfter  = $autumnBack === null ? 'none' : FeedAdmin::showMoment($autumnBack->modify('+30 minutes')->format('Y-m-d H:i:s'), $londonZone);
pa_ok('D23 the autumn night: the same wall clock (01:30) twice, told apart by BST then GMT', $autumnBack !== null && str_ends_with($aBefore, '01:30 BST') && str_ends_with($aAfter, '01:30 GMT'), "{$aBefore} | {$aAfter}");
$santiago23 = FeedAdmin::showMoment('2026-01-15 12:00:00', pa_zone(SITE_ZONE_B));
pa_ok('D23 an America/Santiago moment: 12:00 UTC in January is 09:00 local (summer time there)', $santiago23 === 'Thu 15 Jan 2026, 09:00 -03', $santiago23);
pa_ok('D23 null, and text that is not a moment, show "never"', FeedAdmin::showMoment(null, $londonZone) === 'never' && FeedAdmin::showMoment('not a date', $londonZone) === 'never');

// =============================================================================
// D24 — inbox rows
// =============================================================================
pa_heading('D24 — inbox rows');
pa_resetWorld();
pa_q("UPDATE tblExternalFeeds SET audienceLevel = 'members' WHERE feedID = ?", 'i', [FB1]);
$n24 = pa_event(FA1, 'd24-names@p8', 'D24 names');
[$dr24a, $dr24b, $dr24c] = pa_series(FA1, 'd24@p8', 'D24 series', ['count' => 3]);
$pv24  = pa_event(FA2, 'd24-private@p8', 'D24 private', ['private' => 1]);
$bEv24 = pa_event(FB1, 'd24-b@p8', 'D24 BONLY');
// Shape (a): the calendar at Selected groups [SG1]; a date choice asking
// for [SG1 + P] — a list naming somebody new, so wider, so it waits.
$rCal24 = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]));
$rCh24  = FeedAdmin::saveChoice($mysqli, $n24, 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1], 'person' => [P_ID]]]);
$rRu24  = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D24', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D24 series']]]);
// Shape (b): a private-marked event starts at "administrators only", so
// even a Selected-groups choice is wider, and waits.
$rCh24b = FeedAdmin::saveChoice($mysqli, $pv24, 'date', ORG_A, SA, ['audienceLevel' => 'groups', 'overridesPrivateMark' => '1', 'audience' => ['small_group' => [SG1]]]);
$rB24   = FeedAdmin::saveRule($mysqli, FB1, 0, ORG_B, SB, ['name' => 'D24 BONLY', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D24 BONLY']]]);
$pn24   = pa_pending($n24);
$ppv24  = pa_pending($pv24);
pa_ok(
    'D24 set-up: two real waiting Selected-groups rows (shapes a and b), three waiting dates of one series, and one of B\'s',
    $rCal24['ok'] === true && $rCh24['ok'] === true && $rRu24['ok'] === true && $rCh24b['ok'] === true && $rB24['ok'] === true
    && $pn24 !== null && $pn24['requestedLevel'] === 'groups' && $ppv24 !== null && $ppv24['requestedLevel'] === 'groups'
    && pa_pending($dr24a) !== null && pa_pending($dr24b) !== null && pa_pending($dr24c) !== null && pa_pending($bEv24) !== null,
    pa_show([$rCal24, $rCh24, $rRu24, $rCh24b, $rB24])
);
$pend24 = FeedAdmin::pendingApprovals($mysqli, ORG_A, null, 50);
$by24   = [];
foreach ($pend24['rows'] as $row) {
    $by24[(int) $row['eventID']] = $row;
}
pa_ok(
    'D24 pendingApprovals(A): exactly A\'s five waiting rows, total 5, nothing of B\'s',
    count($pend24['rows']) === 5 && $pend24['total'] === 5 && pa_has($pend24, B_MARK) === false
    && isset($by24[$n24], $by24[$pv24], $by24[$dr24a], $by24[$dr24b], $by24[$dr24c]) === true,
    pa_show(['total' => $pend24['total'], 'events' => array_keys($by24)])
);
pa_ok(
    'D24 siblingsWaiting: 2 for each date of the three-date series, 0 for a one-off',
    (int) ($by24[$dr24a]['siblingsWaiting'] ?? -1) === 2 && (int) ($by24[$dr24b]['siblingsWaiting'] ?? -1) === 2
    && (int) ($by24[$dr24c]['siblingsWaiting'] ?? -1) === 2 && (int) ($by24[$n24]['siblingsWaiting'] ?? -1) === 0,
    pa_show(array_map(static fn (array $r): int => (int) $r['siblingsWaiting'], $by24))
);
$labels24 = static fn (array $row): array => array_map(static fn (array $m): string => $m['state'] . '|' . $m['label'], $row['names'] ?? ['x']);
pa_ok(
    'D24 names for a Selected-groups request are the list\'s live labels — shape (a) [SG1, P]; shape (b) [SG1]; none for a public request',
    $labels24($by24[$n24] ?? []) === ['ok|Selftest SG1', 'ok|Selftest P'] && $labels24($by24[$pv24] ?? []) === ['ok|Selftest SG1']
    && ($by24[$dr24a]['names'] ?? ['x']) === [],
    pa_show(array_map($labels24, $by24))
);
pa_ok(
    'D24 no row carries the raw identity bytes or the internal total (only what a page needs)',
    array_key_exists('uidHash', $pend24['rows'][0] ?? ['uidHash' => 1]) === false && array_key_exists('totalRows', $pend24['rows'][0] ?? ['totalRows' => 1]) === false
);
$only24 = FeedAdmin::pendingApprovals($mysqli, ORG_A, FA2, 50);
pa_ok('D24 one calendar only: FA2 gives its one row, total 1', count($only24['rows']) === 1 && (int) $only24['rows'][0]['eventID'] === $pv24 && $only24['total'] === 1);
$page24 = FeedAdmin::pendingApprovals($mysqli, ORG_A, null, 2, 4);
$past24 = FeedAdmin::pendingApprovals($mysqli, ORG_A, null, 2, 10);
pa_ok('D24 paging: 2 rows from the fifth gives 1 row, total 5; a page past the end gives no rows, total still 5', count($page24['rows']) === 1 && $page24['total'] === 5 && $past24['rows'] === [] && $past24['total'] === 5, pa_show([count($page24['rows']), $page24['total'], $past24['total']]));
// Checker M6: three statements for the inbox, however many rows and lists.
$stmts24 = pa_statementsOf(static fn () => FeedAdmin::pendingApprovals($mysqli, ORG_A, null, 50));
pa_ok('D24 pendingApprovals() of five rows (two lists from two owners) sends at most 3 statements (checker M6)', $stmts24['exec'] <= 3 && $stmts24['select'] <= 3, pa_show($stmts24));
$g24a = pa_newFeed(ORG_A, 'groups');
$g24b = pa_newFeed(ORG_A, 'groups');
pa_list($g24a, 'feed', $g24a, [['small_group', SG1], ['person', P_ID], ['leadership_role', RA1]]);
pa_list($g24b, 'feed', $g24b, [['small_group', SG2], ['person', SGM]]);
$stmtsC24 = pa_statementsOf(static fn () => FeedAdmin::calendars($mysqli, ORG_A));
pa_ok('D24 calendars() with three Selected-groups calendars sends at most 5 statements (checker M6)', $stmtsC24['exec'] <= 5 && $stmtsC24['select'] <= 5, pa_show($stmtsC24));
// Erasure reaches the names: GdprEraser deletes a person's list rows by
// userID; the inbox then no longer shows them.
pa_q('DELETE FROM tblExternalAudienceMembers WHERE userID = ?', 'i', [P_ID]);
$after24 = array_values(array_filter(FeedAdmin::pendingApprovals($mysqli, ORG_A, null, 50)['rows'], static fn (array $r): bool => (int) $r['eventID'] === $n24))[0] ?? null;
pa_ok('D24 after a person\'s list rows are erased, their name is gone from the inbox', $after24 !== null && $labels24($after24) === ['ok|Selftest SG1'], pa_show($after24 === null ? null : $labels24($after24)));
ExternalAudience::remove($mysqli, ORG_A, FA1, 'choice', (int) ($rCh24['choiceId'] ?? 0));
$after24b = array_values(array_filter(FeedAdmin::pendingApprovals($mysqli, ORG_A, null, 50)['rows'], static fn (array $r): bool => (int) $r['eventID'] === $n24))[0] ?? null;
pa_ok('D24 after ExternalAudience::remove() of that list, no names at all', $after24b !== null && $after24b['names'] === []);

// =============================================================================
// D25 — bulk decisions (challenge M2; checker LOW 4)
// =============================================================================
pa_heading('D25 — bulk decisions');
pa_resetWorld();
$confirmEv = pa_event(FA1, 'd25-confirm@p8', 'D25 confirm');
$cBefore   = (int) (pa_one("SHOW SESSION STATUS LIKE 'Com_commit'")['Value'] ?? 0);
FeedAdmin::saveChoice($mysqli, $confirmEv, 'date', ORG_A, SA, ['audienceLevel' => 'members']);
$cAfter = (int) (pa_one("SHOW SESSION STATUS LIKE 'Com_commit'")['Value'] ?? 0);
pa_ok('D25 set-up: one inFeedLock call adds exactly one to Com_commit', ($cAfter - $cBefore) === 1);

/** Two calendars of A, with a waiting three-date series on FA1 and one waiting date on FA2 (made Members first, so Public is wider). */
function pa_bulkWorld(string $tag): array
{
    global $mysqli;
    pa_resetWorld();
    pa_q("UPDATE tblExternalFeeds SET audienceLevel = 'members' WHERE feedID = ?", 'i', [FA2]);
    $series = pa_series(FA1, $tag . '-series@p8', $tag . ' series', ['count' => 3]);
    $single = pa_event(FA2, $tag . '-single@p8', $tag . ' single');
    FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => $tag . 'a', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => $tag . ' series']]]);
    FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, ['name' => $tag . 'b', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => $tag . ' single']]]);
    $rows = [];
    foreach (array_merge($series, [$single]) as $eid) {
        $rows[] = pa_pending($eid);
    }

    return ['events' => array_merge($series, [$single]), 'rows' => $rows, 'ok' => in_array(null, $rows, true) === false];
}

$w25    = pa_bulkWorld('D25a');
$before = (int) (pa_one("SHOW SESSION STATUS LIKE 'Com_commit'")['Value'] ?? 0);
$rBulk  = $w25['ok'] ? FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', array_map('pa_item', $w25['rows'])) : ['decided' => -1];
$after  = (int) (pa_one("SHOW SESSION STATUS LIKE 'Com_commit'")['Value'] ?? 0);
pa_ok(
    'D25 four items across two calendars (three dates of one series, one one-off date): decided 4, changed 0, exactly "Approved 4." + the singular sentence, exactly two commits (one lock and one resolve per calendar)',
    $w25['ok'] === true && $rBulk['decided'] === 4 && $rBulk['changed'] === 0 && $rBulk['notDone'] === 0
    && $rBulk['message'] === 'Approved 4.' . MSG_OTHER_ONE && ($after - $before) === 2,
    pa_show($rBulk) . ' commits=' . ($after - $before)
);
$w25b = pa_bulkWorld('D25b');
$rTwo = $w25b['ok'] ? FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($w25b['rows'][0]), pa_item($w25b['rows'][1])]) : ['decided' => -1];
pa_ok(
    'D25 two of three ticked: decided 2, changed 0 (neither settled by the other and called "changed" — challenge M2), the third approved too, exactly "Approved 2." + the singular sentence',
    $w25b['ok'] === true && $rTwo['decided'] === 2 && $rTwo['changed'] === 0 && $rTwo['message'] === 'Approved 2.' . MSG_OTHER_ONE
    && pa_status((int) $w25b['rows'][2]['approvalID']) === 'approved',
    pa_show($rTwo)
);
$w25c  = pa_bulkWorld('D25c');
$rDecl = $w25c['ok'] ? FeedAdmin::decide($mysqli, ORG_A, SA, 'declined', '', [pa_item($w25c['rows'][0])]) : ['decided' => -1];
pa_ok(
    'D25 the same with declined: decided 1, nothing "changed", exactly "Declined 1." + the singular sentence, all three declined and back at the calendar\'s setting',
    $w25c['ok'] === true && $rDecl['decided'] === 1 && $rDecl['changed'] === 0
    && $rDecl['message'] === 'Declined 1.' . MSG_OTHER_ONE
    && pa_status((int) $w25c['rows'][1]['approvalID']) === 'declined' && pa_ls($w25c['events'][2]) === 'members/calendar',
    pa_show($rDecl)
);
$w25d   = pa_bulkWorld('D25d');
$items4 = $w25d['ok'] ? array_map('pa_item', $w25d['rows']) : [];
if ($items4 !== []) {
    $items4[0]['requestHash'] = str_repeat('f', 64);
}
$rStale4 = $w25d['ok'] ? FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', $items4) : ['decided' => -1];
pa_ok(
    'D25 one stale item among four: changed 1, the other three decided, the sentence LAST (after "1 had changed …")',
    $w25d['ok'] === true && $rStale4['changed'] === 1 && $rStale4['decided'] === 3
    && $rStale4['message'] === 'Approved 3. 1 had changed since you opened the page; please review it again.' . MSG_OTHER_ONE,
    pa_show($rStale4)
);
// Checker LOW 4 (a): the same date listed twice is decided ONCE, never
// reported "changed since you opened the page".
$w25e = pa_bulkWorld('D25e');
$rDup = $w25e['ok'] ? FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($w25e['rows'][3]), pa_item($w25e['rows'][3])]) : ['decided' => -1];
pa_ok('D25 the same (one-off) date listed twice: decided 1, changed 0, exactly "Approved 1."', $w25e['ok'] === true && $rDup['decided'] === 1 && $rDup['changed'] === 0 && $rDup['message'] === 'Approved 1.', pa_show($rDup));
// (b) more than MAX_BULK: refused, nothing changed (a real connection this time).
$w25f  = pa_bulkWorld('D25f');
$fp25f = pa_fp();
$rMany = $w25f['ok'] ? FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', array_fill(0, FeedAdmin::MAX_BULK + 1, pa_item($w25f['rows'][3]))) : ['status' => ''];
pa_ok('D25 more than 200 dates in one go: refused, word for word, nothing changed', $rMany['status'] === 'refused' && $rMany['message'] === 'You can decide at most 200 dates at once.' && pa_fp() === $fp25f, pa_show($rMany));
// (c) THREE calendars, the SECOND locked elsewhere (fix round 2, checker
//     round 2 LOW 2): the first calendar's decision is real and is
//     reported; the second fails; the THIRD is never attempted — and both
//     of those dates are counted as not done (faults N13, N14).
$w25g  = pa_bulkWorld('D25g');
$fa3   = pa_newFeed(ORG_A, 'members');
$ev25t = pa_event($fa3, 'd25g-third@p8', 'D25g third');
$rR25t = FeedAdmin::saveRule($mysqli, $fa3, 0, ORG_A, SA, ['name' => 'D25g c', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D25g third']]]);
$p25t  = pa_pending($ev25t);
$ok25g = $w25g['ok'] === true && $rR25t['ok'] === true && $p25t !== null;
$rPart = $ok25g ? pa_whileLocked([FA2], 1, static fn (): array => FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($w25g['rows'][0]), pa_item($w25g['rows'][3]), pa_item($p25t)])) : ['decided' => -1];
pa_ok(
    'D25 three calendars, the second locked elsewhere: the first\'s decision stands and is reported; the second and third are "not done" (2), in plain words; the third was never attempted',
    $ok25g === true && $rPart['decided'] === 1 && $rPart['notDone'] === 2 && $rPart['changed'] === 0
    && $rPart['message'] === 'Approved 1. 2 could not be decided this time; please try again.' . MSG_OTHER_ONE
    && ($rPart['failure'] ?? null) instanceof mysqli_sql_exception
    && pa_status((int) $w25g['rows'][0]['approvalID']) === 'approved' && pa_status((int) $w25g['rows'][3]['approvalID']) === 'pending'
    && pa_status((int) $p25t['approvalID']) === 'pending' && pa_ls($ev25t) === 'members/waiting',
    pa_show($rPart)
);
// (c2) The "Other dates …" sentence counts ONLY dates really decided (P8-1
//      check round 13, finding R13-L1; carried into chunk P8-2). In the case
//      above both undone dates are one-off dates, so a fault that added the
//      repeating events of calendars that could NOT be decided to the
//      sentence went unseen (the checker's planted fault C13-05 survived).
//      Here the decided calendar (FA2, listed FIRST so it is reached first)
//      holds a ONE-OFF date, and the calendar that cannot be decided (FA1,
//      held locked elsewhere) holds a date of a REPEATING event. No decision
//      was made for that repeating event, so the sentence must not appear.
$w25m = pa_bulkWorld('D25m');
$r13  = $w25m['ok'] ? pa_whileLocked([FA1], 1, static fn (): array => FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($w25m['rows'][3]), pa_item($w25m['rows'][0])])) : ['decided' => -1];
pa_ok(
    'D25 a one-off date decided, a repeating event\'s date on a calendar that could not be decided: exactly "Approved 1. 1 could not be decided this time; please try again." with NO "Other dates …" sentence (R13-L1)',
    $w25m['ok'] === true && $r13['decided'] === 1 && $r13['notDone'] === 1 && $r13['changed'] === 0
    && $r13['message'] === 'Approved 1. 1 could not be decided this time; please try again.'
    && ($r13['failure'] ?? null) instanceof mysqli_sql_exception
    && pa_status((int) $w25m['rows'][3]['approvalID']) === 'approved' && pa_status((int) $w25m['rows'][0]['approvalID']) === 'pending',
    pa_show($r13)
);
// (d) the FIRST calendar cannot be locked: nothing was committed, so the
//     error is thrown (the page then says nothing was changed — true).
$w25h  = pa_bulkWorld('D25h');
$fp25h = pa_fp();
$tFirst = $w25h['ok'] ? pa_whileLocked([FA1], 1, static fn (): array => pa_timed(static fn (): array => FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($w25h['rows'][0]), pa_item($w25h['rows'][3])]))) : ['threw' => null];
pa_ok('D25 the FIRST calendar locked elsewhere: the lock-wait error is thrown, and nothing at all was changed', $w25h['ok'] === true && $tFirst['threw'] instanceof mysqli_sql_exception && pa_fp() === $fp25h, pa_show($tFirst['result'] ?? null));

// (e) A calendar DELETED between decide()'s look-up and its lock (fix round
//     2, checker round 2 LOW 8): a separate process holds FA2's lock, then
//     deletes FA2 and commits. The first calendar is decided; FA2's date
//     then counts as "changed" (it has gone) — no error, nothing left
//     undone, and no transaction left open on this connection.
//     HOW "no transaction left open" is read (fix round 3, checker round 3
//     LOW 1): from performance_schema.events_transactions_current for THIS
//     session — a live view. The first version read
//     information_schema.INNODB_TRX, which MySQL caches for the whole server
//     and refreshes at most every 0.1 s, so another connection reading it a
//     moment earlier made this check see a transaction that had already
//     ended. The instrument this needs is on by default in MySQL 8.0 — the
//     check below proves it is on, and that it DOES see an open
//     transaction, before trusting its "none".
//     And the call must really have WAITED on the holder's lock (at least
//     one second of the holder's 1.5): a calendar already gone at the
//     look-up gives the same answer at once, and would prove nothing here.
//     NOT EVERY SERVER HAS THIS INSTRUMENT, which is why the next few lines
//     ask before using it (#487, 28 October 2026). `performance_schema` is on
//     by default in MySQL 8.0 and 8.4, and **OFF by default in MariaDB**,
//     which also has no `PS_CURRENT_THREAD_ID()` function at all — its
//     equivalent is a look-up in `performance_schema.threads`. Running this
//     file against MariaDB 11.4 therefore died here with
//     "FUNCTION … PS_CURRENT_THREAD_ID does not exist" and stopped the whole
//     run, 923 checks short of the end.
//
//     The two checks below are SKIPPED when the instrument is missing rather
//     than forced or failed. Forcing them would be a lie; failing them would
//     say the PORTAL was broken on MariaDB when it is this TEST's instrument
//     that is absent — the portal queries `performance_schema` nowhere, which
//     was checked rather than assumed.
$psReady = pa_one("SELECT @@performance_schema AS on_") ['on_'] ?? '0';
$psHasFn = (string) $psReady === '1'
    && (pa_one("SELECT COUNT(*) AS n FROM information_schema.routines WHERE routine_schema = 'performance_schema' AND routine_name = 'ps_current_thread_id'")['n'] ?? '0') !== '0';
// MySQL exposes `PS_CURRENT_THREAD_ID()` as a built-in rather than a routine,
// so the routines look-up above can say "no" on a server that has it. Ask the
// function itself, and treat an error as "not available".
if ((string) $psReady === '1') {
    try {
        pa_one('SELECT PS_CURRENT_THREAD_ID() AS t');
        $psHasFn = true;
    } catch (Throwable $e) {
        $psHasFn = false;
    }
}
$openTrx = static fn (): int => (int) (pa_one(
    "SELECT COUNT(*) AS n FROM performance_schema.events_transactions_current WHERE THREAD_ID = PS_CURRENT_THREAD_ID() AND STATE = 'ACTIVE' AND AUTOCOMMIT = 'NO'"
)['n'] ?? -1);
if ($psHasFn === false) {
    pa_skipped(
        'D25 the live transaction view is switched on here, and does see an open transaction on this connection (and none once it ends)',
        'this server has no live transaction view to ask: @@performance_schema is ' . json_encode($psReady)
        . ' and PS_CURRENT_THREAD_ID() is not available (MariaDB has neither by default).'
    );
} else {
    $instr25 = pa_one(
        "SELECT (SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME = 'transaction') AS i, "
        . "(SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME = 'events_transactions_current') AS c"
    );
    $mysqli->begin_transaction();
    $seenOpen25 = $openTrx();
    $mysqli->rollback();
    $seenClosed25 = $openTrx();
    pa_ok(
        'D25 the live transaction view is switched on here, and does see an open transaction on this connection (and none once it ends)',
        ($instr25['i'] ?? '') === 'YES' && ($instr25['c'] ?? '') === 'YES' && $seenOpen25 === 1 && $seenClosed25 === 0,
        pa_show([$instr25, $seenOpen25, $seenClosed25])
    );
}
$w25i  = pa_bulkWorld('D25i');
$race25 = $w25i['ok'] ? pa_withHolder(FA2, 1.5, 'delete', static fn (): array => pa_timed(static fn (): array => FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($w25i['rows'][0]), pa_item($w25i['rows'][3])]))) : ['result' => null, 'holderOk' => false, 'holderLog' => 'set-up failed'];
$dec25  = $race25['result']['result'] ?? [];
// -2 means "this server has no live transaction view", not "a transaction is
// open". The clause below accepts it and the LABEL says so, so a run on
// MariaDB never claims to have checked something it could not.
$open25 = $psHasFn === true ? $openTrx() : -2;
pa_ok(
    'D25 a calendar deleted between the look-up and its lock: the call waited on the lock, then "Approved 1. 1 had changed … Other dates …", no error, nothing "not done"'
    . ($open25 === -2
        ? ' (whether a transaction was left open was NOT CHECKED: this server has no live transaction view)'
        : ', no transaction left open'),
    $w25i['ok'] === true && $race25['holderOk'] === true
    && is_array($race25['result']) && array_key_exists('threw', $race25['result']) && $race25['result']['threw'] === null
    && ($race25['result']['seconds'] ?? 0) >= 1.0
    && ($dec25['decided'] ?? -1) === 1 && ($dec25['changed'] ?? -1) === 1 && ($dec25['notDone'] ?? -1) === 0
    && array_key_exists('failure', $dec25) && $dec25['failure'] === null
    && ($dec25['message'] ?? '') === 'Approved 1. 1 had changed since you opened the page; please review it again.' . MSG_OTHER_ONE
    && pa_one('SELECT feedID FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA2]) === null
    && ($open25 === 0 || $open25 === -2),
    pa_show([$dec25, $race25['holderLog'], $open25, $race25['result']['seconds'] ?? null, ($race25['result']['threw'] ?? null) instanceof Throwable ? $race25['result']['threw']->getMessage() : null])
);
// (f) A stale request fingerprint on ONE date of a three-date series (fix
//     round 3, checker round 3 LOW 2 / fault X09): nothing is decided, so
//     NO sibling followed — all three dates still wait — and, although the
//     date is one of a repeating event, NO "Other dates …" sentence (fix
//     round 12: never after "Approved 0.").
$w25j = pa_bulkWorld('D25j');
$stale25 = $w25j['ok'] ? pa_item($w25j['rows'][0]) : [];
if ($stale25 !== []) {
    $stale25['requestHash'] = str_repeat('0', 64);
}
$rStale25 = $w25j['ok'] ? FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [$stale25]) : ['decided' => -1];
pa_ok(
    'D25 a stale fingerprint on one date of a three-date series: decided 0, exactly "Approved 0. 1 had changed …" with no "Other dates …" sentence, all three still waiting',
    $w25j['ok'] === true && $rStale25['decided'] === 0 && $rStale25['changed'] === 1
    && $rStale25['message'] === 'Approved 0. 1 had changed since you opened the page; please review it again.'
    && pa_status((int) $w25j['rows'][0]['approvalID']) === 'pending' && pa_status((int) $w25j['rows'][1]['approvalID']) === 'pending'
    && pa_status((int) $w25j['rows'][2]['approvalID']) === 'pending',
    pa_show($rStale25)
);

// (g) Fix round 12: ONE series on each of two calendars, with the SAME
//     identity, decided in one call. They are two repeating events (a
//     calendar's event is its own), so the sentence says "events" — the
//     plural across calendars.
pa_resetWorld();
pa_q("UPDATE tblExternalFeeds SET audienceLevel = 'members' WHERE feedID = ?", 'i', [FA2]);
$s25k1 = pa_series(FA1, 'd25k-same@p8', 'D25k series', ['count' => 2]);
$s25k2 = pa_series(FA2, 'd25k-same@p8', 'D25k series', ['count' => 2]);
$r25k1 = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D25k a', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D25k series']]]);
$r25k2 = FeedAdmin::saveRule($mysqli, FA2, 0, ORG_A, SA, ['name' => 'D25k b', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D25k series']]]);
$p25k1 = pa_pending($s25k1[0]);
$p25k2 = pa_pending($s25k2[0]);
$d25k  = ($r25k1['ok'] !== true || $r25k2['ok'] !== true || $p25k1 === null || $p25k2 === null) ? ['message' => '', 'decided' => -1, 'changed' => -1]
    : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($p25k1), pa_item($p25k2)]);
pa_ok(
    'D25 one date of a series on EACH of two calendars (same identity): decided 2, changed 0, exactly "Approved 2." + the sentence with "repeating events", plural',
    $d25k['decided'] === 2 && $d25k['changed'] === 0 && $d25k['message'] === 'Approved 2.' . MSG_OTHER_MANY,
    pa_show($d25k)
);

// =============================================================================
// D26 — a row with no identity (challenge M3)
// =============================================================================
pa_heading('D26 — a row with no identity');
pa_resetWorld();
$noId = pa_event(FA1, 'd26@p8', 'D26 no identity', ['noIdentity' => true]);
pa_ok('D26 importedEvent() -> null for a row with no identity', FeedAdmin::importedEvent($mysqli, $noId, ORG_A) === null);
$rChoice26 = FeedAdmin::saveChoice($mysqli, $noId, 'date', ORG_A, SA, ['audienceLevel' => 'public']);
pa_ok(
    'D26 saveChoice() for it -> not_found, "That event could not be found.", no choice row',
    $rChoice26['status'] === 'not_found' && $rChoice26['message'] === MSG_NF_EVENT && pa_one('SELECT choiceID FROM tblExternalEventChoices WHERE feedID = ?', 'i', [FA1]) === null,
    pa_show($rChoice26)
);
$row26 = array_values(array_filter(FeedAdmin::events($mysqli, FA1, ORG_A, 1)['rows'], static fn (array $r): bool => (int) $r['eventID'] === $noId))[0] ?? null;
pa_ok('D26 events() lists it with hasIdentity = false', $row26 !== null && $row26['hasIdentity'] === false);

// =============================================================================
// D27 — twin words (challenge M6)
// =============================================================================
pa_heading('D27 — twin words');
pa_resetWorld();
$evCafe27  = pa_event(FA1, 'd27-1@p8', 'D27 cafe evt', ['tags' => ['cafe']]);
$evCafe27b = pa_event(FA1, 'd27-2@p8', 'D27 cafe accent evt', ['tags' => ['café']]);
// Organisation B's FB1 holds "café" and A's other calendar FA2 holds
// "cafe" — twins of every word posted below (fix round 7, check round 7
// M2). Every D27 save is FA1's, so both rows must come through untouched.
$others27 = pa_seedOtherMaps(['cafe' => ['café', 'cafe']]);
$fp27      = pa_fp();
$rConflict = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'cafe', 'choice' => (string) CA1], ['word' => 'café', 'choice' => (string) CA2]]]);
pa_ok(
    'D27 twins mapped to different categories: refused with the twin sentence, word for word; no row written',
    $rConflict['ok'] === false && $rConflict['message'] === "'cafe' and 'café' count as the same word here, so they must use the same setting." && pa_fp() === $fp27,
    pa_show($rConflict)
);
// What FA1 stores, as [spelling, categoryID] rows — the message is checked
// against THIS, never against what was posted (fix round 7, check round 7 M1).
$stored27 = static fn (): array => array_map(
    static fn (array $r): array => [(string) $r['externalCategory'], $r['categoryID'] === null ? null : (int) $r['categoryID']],
    pa_q('SELECT externalCategory, categoryID FROM tblExternalCategoryMap WHERE feedID = ? ORDER BY mapID', 'i', [FA1])
);
// The sentence promises only what is always true (fix round 8, check round
// 8 L4): which spelling is stored and MATCHES, and that the other spelling
// alone counts as not mapped — never which category an event ends up with,
// because an event's FIRST mapped word in alphabetical order wins.
$tail27 = static fn (string $cat): string => " so only 'café' is stored, with the category '{$cat}'. Events tagged 'café' match this word; events tagged 'cafe' (and not 'café') do not, so for them this word counts as not mapped.";
// (a) Both twins at "default" with nothing stored: nothing is stored, so
//     nothing may be said about what is stored.
$rDefault27 = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'cafe', 'choice' => 'default'], ['word' => 'café', 'choice' => 'default']]]);
pa_ok(
    'D27 (a) both twins left at "default", nothing stored before: nothing stored after, and exactly "Saved." — no claim that anything is stored',
    $rDefault27['ok'] === true && $rDefault27['message'] === 'Saved.' && $stored27() === [],
    pa_show([$rDefault27, $stored27()])
);
$rSame = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => '  Café  ', 'choice' => (string) CA1], ['word' => 'cafe', 'choice' => (string) CA1]]]);
pa_ok(
    'D27 both to CA1, nothing stored before: exactly one row, the first posted spelling lower-cased and trimmed ("café"), and the message names THAT spelling and what each spelling\'s events get',
    $rSame['ok'] === true && $stored27() === [['café', CA1]]
    && $rSame['message'] === "Saved. 'cafe' counts as the same word here as 'café'," . $tail27('Selftest CA1'),
    pa_show([$rSame, $stored27()])
);
pa_ok('D27 the event tagged with the stored spelling gets CA1; its twin keeps the calendar\'s default (none)', (int) pa_ev($evCafe27b)['categoryID'] === CA1 && pa_ev($evCafe27)['categoryID'] === null, pa_show([pa_ev($evCafe27)['categoryID'], pa_ev($evCafe27b)['categoryID']]));
// (b) A twin ALREADY stored ("café", CA1), and both spellings posted to CA2
//     with the OTHER spelling first — the realistic path. The stored row
//     keeps its own spelling; the message must name "café", not "cafe".
$rStoredTwin27 = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'cafe', 'choice' => (string) CA2], ['word' => 'café', 'choice' => (string) CA2]]]);
pa_ok(
    'D27 (b) "café" already stored, both spellings posted to CA2 ("cafe" first): still one row, "café", now CA2; the message names "café" as stored (the first version named "cafe" — the reverse)',
    $rStoredTwin27['ok'] === true && $stored27() === [['café', CA2]]
    && $rStoredTwin27['message'] === "Saved. 'cafe' counts as the same word here as 'café'," . $tail27('Selftest CA2')
    && (int) pa_ev($evCafe27b)['categoryID'] === CA2 && pa_ev($evCafe27)['categoryID'] === null,
    pa_show([$rStoredTwin27, $stored27(), pa_ev($evCafe27)['categoryID'], pa_ev($evCafe27b)['categoryID']])
);
// (c) Only the OTHER spelling posted ("cafe" -> CA1) while "café" is
//     stored: the stored row changes, so the message must say so rather
//     than a bare "Saved.".
$rOtherSpelling27 = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'cafe', 'choice' => (string) CA1]]]);
pa_ok(
    'D27 (c) only "cafe" posted (CA1) while "café" is stored: the stored "café" row now CA1, and the message says "café" is what is stored and which events get CA1',
    $rOtherSpelling27['ok'] === true && $stored27() === [['café', CA1]]
    && $rOtherSpelling27['message'] === "Saved. 'cafe' counts as the same word here as 'café'," . $tail27('Selftest CA1')
    && (int) pa_ev($evCafe27b)['categoryID'] === CA1 && pa_ev($evCafe27)['categoryID'] === null,
    pa_show([$rOtherSpelling27, $stored27()])
);
// (d) The same, to "none": the stored row is kept with no category, and
//     neither spelling's events get one from it.
$rNone27 = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'cafe', 'choice' => 'none']]]);
pa_ok(
    'D27 (d) only "cafe" posted as "none" while "café" is stored: the "café" row kept with no category, and the message says so',
    $rNone27['ok'] === true && $stored27() === [['café', null]]
    && $rNone27['message'] === "Saved. 'cafe' counts as the same word here as 'café', so only 'café' is stored, with no category: no event gets a category from this word, whichever spelling it is tagged with."
    && pa_ev($evCafe27b)['categoryID'] === null && pa_ev($evCafe27)['categoryID'] === null,
    pa_show([$rNone27, $stored27()])
);
// (e) Both twins at "default" while one IS stored: the stored row goes
//     (the database's own comparison), and nothing is said about storing.
$rDefault27b = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'cafe', 'choice' => 'default'], ['word' => 'café', 'choice' => 'default']]]);
pa_ok(
    'D27 (e) both twins at "default" while "café" is stored: the row removed, exactly "Saved."',
    $rDefault27b['ok'] === true && $rDefault27b['message'] === 'Saved.' && $stored27() === [],
    pa_show([$rDefault27b, $stored27()])
);
// A form big enough that a GROUP_CONCAT of its indexes would pass 1,024
// bytes (fix round 7, check round 7 L1: 299 "cafe" and one "café" on
// another category slipped past the refusal): it must be refused, naming
// the two DIFFERENT spellings, with nothing written.
$big27 = [];
for ($i = 0; $i < 299; $i++) {
    $big27[] = ['word' => 'cafe', 'choice' => (string) CA1];
}
$big27[] = ['word' => 'café', 'choice' => (string) CA2];
$fpBig27  = pa_fp();
$rBig27   = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => $big27]);
pa_ok(
    'D27 a hand-made form of 299 "cafe" (CA1) and one "café" (CA2): refused with the twin sentence naming two different spellings, nothing written',
    $rBig27['ok'] === false && $rBig27['message'] === "'cafe' and 'café' count as the same word here, so they must use the same setting." && pa_fp() === $fpBig27,
    pa_show($rBig27)
);
// One word listed twice with different settings: never "'cafe' and 'cafe'".
$rTwice27 = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'cafe', 'choice' => (string) CA1], ['word' => 'Cafe', 'choice' => (string) CA2]]]);
pa_ok(
    'D27 one word listed twice with different settings: refused, naming it once — exactly "\'cafe\' is listed more than once with different settings; give it one setting."',
    $rTwice27['ok'] === false && $rTwice27['message'] === "'cafe' is listed more than once with different settings; give it one setting." && pa_fp() === $fpBig27,
    pa_show($rTwice27)
);
pa_ok(
    'D27 after every save above, the twin rows of organisation B\'s FB1 ("café") and of A\'s other calendar FA2 ("cafe") are untouched',
    count($others27) === 2 && pa_otherMaps() === $others27,
    pa_show(['before' => $others27, 'after' => pa_otherMaps()])
);
// THREE spellings of one word (fix round 8, check round 8 L4 / faults
// C8-01, C8-02): the verb is plural, the list reads "'a' and 'b'", and the
// events part reads "'a' or 'b'" — not events carrying both words at once.
pa_q('DELETE FROM tblExternalCategoryMap WHERE feedID = ?', 'i', [FA1]);
$rThree27 = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'cafe', 'choice' => (string) CA1], ['word' => 'café', 'choice' => (string) CA1], ['word' => 'cafè', 'choice' => (string) CA1]]]);
pa_ok(
    'D27 three spellings to CA1, nothing stored: one row ("cafe", the first posted), and the sentence reads as English — plural verb, "and" between the spellings, "or" for the events',
    $rThree27['ok'] === true && $stored27() === [['cafe', CA1]]
    && $rThree27['message'] === "Saved. 'café' and 'cafè' count as the same word here as 'cafe', so only 'cafe' is stored, with the category 'Selftest CA1'. Events tagged 'cafe' match this word; events tagged 'café' or 'cafè' (and not 'cafe') do not, so for them this word counts as not mapped.",
    pa_show([$rThree27, $stored27()])
);
// An event tagged "art" AND "café", with "art" on CA2: the resolver gives it
// CA2 — its FIRST mapped word in alphabetical order — even though "café"
// is on CA1. The sentence must not have promised otherwise.
pa_q('DELETE FROM tblExternalCategoryMap WHERE feedID = ?', 'i', [FA1]);
$evArt27 = pa_event(FA1, 'd27-art@p8', 'D27 art and cafe evt', ['tags' => ['art', 'café']]);
$rArt27  = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'art', 'choice' => (string) CA2], ['word' => 'café', 'choice' => (string) CA1], ['word' => 'cafe', 'choice' => (string) CA1]]]);
pa_ok(
    'D27 an event tagged "art" and "café" ("art" on CA2, "café" on CA1) gets CA2, while the event tagged only "café" gets CA1 — and the sentence says only that "café" events MATCH the word',
    $rArt27['ok'] === true && (int) pa_ev($evArt27)['categoryID'] === CA2 && (int) pa_ev($evCafe27b)['categoryID'] === CA1
    && $rArt27['message'] === "Saved. 'cafe' counts as the same word here as 'café'," . $tail27('Selftest CA1'),
    pa_show([$rArt27, pa_ev($evArt27)['categoryID'], pa_ev($evCafe27b)['categoryID']])
);
// A twin posted with the category its stored twin KEEPS although that
// category has been switched off since (fix round 9, check round 9 R9-L1 /
// fault C9-06). "café" is stored on CA2; CA2 is switched off; "cafe" is
// posted with CA2. The keep rule compares words the database's way, so the
// stored twin counts and the save is accepted — and the sentence must name
// CA2, not say "no category" (the round 8 version looked names up among
// switched-on categories only). A keep rule comparing byte for byte would
// refuse this save instead.
pa_q('DELETE FROM tblExternalCategoryMap WHERE feedID = ?', 'i', [FA1]);
$rKeep27a = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'café', 'choice' => (string) CA2]]]);
pa_q('UPDATE tblEventCategories SET isActive = 0 WHERE categoryID = ?', 'i', [CA2]);
$rKeep27b = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'cafe', 'choice' => (string) CA2]]]);
$storedKeep27 = $stored27();
pa_q('UPDATE tblEventCategories SET isActive = 1 WHERE categoryID = ?', 'i', [CA2]);
pa_ok(
    'D27 "café" stored on CA2, CA2 switched off, "cafe" posted with CA2: saved (the stored twin keeps it), the row still "café" on CA2, and the sentence names "Selftest CA2" — never "no category"',
    $rKeep27a['ok'] === true && $rKeep27b['ok'] === true && $storedKeep27 === [['café', CA2]]
    && $rKeep27b['message'] === "Saved. 'cafe' counts as the same word here as 'café'," . $tail27('Selftest CA2'),
    pa_show([$rKeep27a, $rKeep27b, $storedKeep27])
);
// Twins only the TABLE's collation makes (fix round 8, check round 8 L5 /
// faults C8-07, C8-08): "straße" and "strase" are one word under
// utf8mb4_general_ci — the column's — and two under MySQL 8's default
// utf8mb4_0900_ai_ci and under utf8mb4_unicode_ci (both read "ß" as "ss").
// First proved on this database, so the case cannot quietly stop meaning
// anything; then the form must refuse them on different categories.
$coll27 = pa_one("SELECT 'straße' = 'strase' COLLATE utf8mb4_general_ci AS g, 'straße' = 'strase' COLLATE utf8mb4_0900_ai_ci AS a, 'straße' = 'strase' COLLATE utf8mb4_unicode_ci AS u");
pa_ok(
    'D27 on this database "straße" and "strase" are ONE word under utf8mb4_general_ci (the column\'s) and TWO under utf8mb4_0900_ai_ci and utf8mb4_unicode_ci',
    (int) ($coll27['g'] ?? -1) === 1 && (int) ($coll27['a'] ?? -1) === 0 && (int) ($coll27['u'] ?? -1) === 0,
    pa_show($coll27)
);
$fpStr27 = pa_fp();
$rStr27  = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => 'straße', 'choice' => (string) CA1], ['word' => 'strase', 'choice' => (string) CA2]]]);
pa_ok(
    'D27 "straße" (CA1) and "strase" (CA2) — twins only under the column\'s collation — refused with the twin sentence, nothing written',
    $rStr27['ok'] === false && $rStr27['message'] === "'straße' and 'strase' count as the same word here, so they must use the same setting." && pa_fp() === $fpStr27,
    pa_show($rStr27)
);

// =============================================================================
// D28 — lengths (challenge L3; checker M3) — refused, never cut short
// =============================================================================
// Accented letters on purpose: a limit counted in BYTES would refuse these
// at half the length, and one that cuts would store them broken.
pa_heading('D28 — lengths');
pa_resetWorld();
$fp28 = pa_fp();
$over = FeedAdmin::addCalendar($mysqli, ORG_A, SA, ['name' => str_repeat('é', 121), 'url' => 'https://1.2.3.4/d28.ics', 'fetchEveryMins' => 60, 'timezone' => '', 'audienceLevel' => 'public']);
pa_ok('D28 a calendar name of 121 characters: "Name can be at most 120 characters.", nothing written', $over['ok'] === false && $over['message'] === 'Name can be at most 120 characters.' && pa_fp() === $fp28, pa_show($over));
$at = FeedAdmin::addCalendar($mysqli, ORG_A, SA, ['name' => str_repeat('é', 120), 'url' => 'https://1.2.3.4/' . str_repeat('a', 2000 - 16), 'fetchEveryMins' => 60, 'timezone' => '', 'audienceLevel' => 'public']);
$atRow = pa_one('SELECT name, url FROM tblExternalFeeds WHERE feedID = ?', 'i', [(int) ($at['feedId'] ?? 0)]);
pa_ok(
    'D28 a name of exactly 120 characters and an address of exactly 2000: saved, and read back byte for byte',
    $at['ok'] === true && $atRow !== null && $atRow['name'] === str_repeat('é', 120) && $atRow['url'] === 'https://1.2.3.4/' . str_repeat('a', 2000 - 16),
    pa_show($at)
);
$ev28 = pa_event(FA1, 'd28@p8', 'D28 event');
$fp28 = pa_fp();
$noteOver = FeedAdmin::saveChoice($mysqli, $ev28, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'note' => str_repeat('é', 256)]);
pa_ok('D28 a choice note of 256 characters: "Note can be at most 255 characters.", nothing written', $noteOver['ok'] === false && $noteOver['message'] === 'Note can be at most 255 characters.' && pa_fp() === $fp28, pa_show($noteOver));
$noteAt = FeedAdmin::saveChoice($mysqli, $ev28, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'note' => str_repeat('é', 255)]);
pa_ok('D28 a choice note of exactly 255: saved and read back byte for byte', $noteAt['ok'] === true && (pa_one('SELECT note FROM tblExternalEventChoices WHERE choiceID = ?', 'i', [(int) ($noteAt['choiceId'] ?? 0)])['note'] ?? '') === str_repeat('é', 255));
$fp28 = pa_fp();
$ruleNameOver = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => str_repeat('é', 121), 'audienceLevel' => 'members', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'x']]]);
$valueOver    = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D28r', 'audienceLevel' => 'members', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => str_repeat('é', 256)]]]);
pa_ok(
    'D28 a rule name of 121 and a condition value of 256: each refused with its own sentence, nothing written',
    $ruleNameOver['message'] === 'Name can be at most 120 characters.' && $valueOver['message'] === 'Value can be at most 255 characters.' && pa_fp() === $fp28,
    pa_show([$ruleNameOver, $valueOver])
);
$ruleAt = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => str_repeat('é', 120), 'audienceLevel' => 'members', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => str_repeat('é', 255)]]]);
$ruleAtRow = FeedAdmin::rule($mysqli, (int) ($ruleAt['ruleId'] ?? 0), FA1, ORG_A);
pa_ok(
    'D28 a rule name of exactly 120 and a value of exactly 255: saved and read back byte for byte',
    $ruleAt['ok'] === true && $ruleAtRow !== null && $ruleAtRow['name'] === str_repeat('é', 120) && ($ruleAtRow['conditions'][0]['matchValue'] ?? '') === str_repeat('é', 255)
);
$fp28 = pa_fp();
$wordOver = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => str_repeat('é', 101), 'choice' => 'none']]]);
$wordAt   = FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, SA, ['map' => [['word' => str_repeat('é', 100), 'choice' => 'none']]]);
pa_ok(
    'D28 a category word of 101: "A category word can be at most 100 characters."; of exactly 100: saved byte for byte',
    $wordOver['ok'] === false && $wordOver['message'] === 'A category word can be at most 100 characters.' && $wordAt['ok'] === true
    && pa_one('SELECT mapID FROM tblExternalCategoryMap WHERE feedID = ? AND externalCategory = ?', 'is', [FA1, str_repeat('é', 100)]) !== null,
    pa_show([$wordOver, $wordAt])
);
// Checker M3: the approval note. Part 7 would CUT a longer one to 500; here
// it is refused first, and 500 is stored exactly.
$apEv = pa_event(FA1, 'd28-note@p8', 'D28 note event');
FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D28n', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D28 note event']]]);
$apRow = pa_pending($apEv);
$fp28  = pa_fp();
$noteOver2 = ($apRow === null) ? ['status' => ''] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', str_repeat('é', 501), [pa_item($apRow)]);
pa_ok(
    'D28 an approval note of 501 characters: "Note can be at most 500 characters.", nothing decided or written (checker M3)',
    $apRow !== null && $noteOver2['status'] === 'refused' && $noteOver2['message'] === 'Note can be at most 500 characters.' && pa_fp() === $fp28 && pa_status((int) $apRow['approvalID']) === 'pending',
    pa_show($noteOver2)
);
$noteAt2 = ($apRow === null) ? ['decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', str_repeat('é', 500), [pa_item($apRow)]);
pa_ok(
    'D28 an approval note of exactly 500: decided, and stored byte for byte — never cut',
    $noteAt2['decided'] === 1 && (pa_one('SELECT decisionNote AS n FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [(int) ($apRow['approvalID'] ?? 0)])['n'] ?? '') === str_repeat('é', 500)
);

// =============================================================================
// D29 — the actor (challenge L1)
// =============================================================================
// EVERY write, called by an ordinary member (M), by the old portal-wide
// flag alone (LA), and by B's administrator (SB) against A's things — each
// refused with the one sentence, and nothing at all changed. Every call is
// set up so that the SAME call by A's administrator DOES change something
// (proved at the end), so a lost actor check could not hide behind a call
// that would have done nothing anyway.
pa_heading('D29 — the actor');
pa_resetWorld();
$ev29      = pa_event(FA1, 'd29@p8', 'D29 event');
$ev29c     = pa_event(FA1, 'd29-choice@p8', 'D29 choice event');
$ev29p     = pa_event(FA1, 'd29-pending@p8', 'D29 pending event');
$c29       = FeedAdmin::saveChoice($mysqli, $ev29c, 'date', ORG_A, SA, ['audienceLevel' => 'hidden']);
$rule29    = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D29 rule', 'audienceLevel' => 'members', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'matches nothing']]]);
$ruleP29   = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D29 widen', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D29 pending']]]);
$pend29    = pa_pending($ev29p);
pa_ok('D29 set-up: a choice to remove, a rule to delete and a waiting row, all made through FeedAdmin', $c29['ok'] === true && $rule29['ok'] === true && $ruleP29['ok'] === true && $pend29 !== null);
$writes29 = [
    'addCalendar'     => static fn (int $who): array => FeedAdmin::addCalendar($mysqli, ORG_A, $who, ['name' => 'D29 new', 'url' => 'https://1.2.3.4/d29.ics', 'fetchEveryMins' => 60, 'timezone' => '', 'audienceLevel' => 'members']),
    'saveCalendar'    => static fn (int $who): array => FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, $who, pa_calPost(FA1, ['name' => 'D29 renamed'])),
    'saveCategoryMap' => static fn (int $who): array => FeedAdmin::saveCategoryMap($mysqli, FA1, ORG_A, $who, ['map' => [['word' => 'd29word', 'choice' => 'none']]]),
    'saveChoice'      => static fn (int $who): array => FeedAdmin::saveChoice($mysqli, $ev29, 'date', ORG_A, $who, ['audienceLevel' => 'hidden']),
    'removeChoice'    => static fn (int $who): array => FeedAdmin::removeChoice($mysqli, $ev29c, 'date', ORG_A, $who),
    'saveRule'        => static fn (int $who): array => FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, $who, ['name' => 'D29 another', 'audienceLevel' => 'members', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'x']]]),
    'deleteRule'      => static fn (int $who): array => FeedAdmin::deleteRule($mysqli, (int) $rule29['ruleId'], FA1, ORG_A, $who),
    'previewRule'     => static fn (int $who): array => FeedAdmin::previewRule($mysqli, FA1, 0, ORG_A, $who, ['name' => 'D29 preview', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D29']]]),
    'decide'          => static fn (int $who): array => FeedAdmin::decide($mysqli, ORG_A, $who, 'approved', '', [pa_item($pend29)]),
];
$fp29 = pa_fp();
foreach (['M (an ordinary member)' => M, 'LA (the old portal-wide flag only)' => LA, 'SB (B\'s administrator, in A)' => SB] as $whoLabel => $who) {
    foreach ($writes29 as $name => $call) {
        $res = $call($who);
        pa_ok(
            "D29 {$name}() by {$whoLabel}: refused, \"" . MSG_ACTOR . '"',
            ($res['ok'] ?? true) === false && ($res['status'] ?? '') === 'refused' && ($res['message'] ?? '') === MSG_ACTOR,
            pa_show($res)
        );
    }
}
pa_ok('D29 none of those 27 calls changed anything at all', pa_fp() === $fp29);
$bySA = [];
foreach ($writes29 as $name => $call) {
    $res         = $call(SA);
    $bySA[$name] = ($res['ok'] ?? false) === true && ($name !== 'decide' || ($res['decided'] ?? 0) === 1);
}
pa_ok('D29 A\'s administrator (SA): every one of the same nine calls proceeds', array_filter($bySA, static fn (bool $v): bool => $v === false) === [], pa_show($bySA));
$rGA = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, GA, pa_calPost(FA1, ['name' => 'D29 renamed by GA']));
pa_ok('D29 a global administrator (GA) proceeds too', $rGA['ok'] === true && pa_one('SELECT name FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['name'] === 'D29 renamed by GA', pa_show($rGA));

// =============================================================================
// D30 — a shorter interval (challenge L8; checker LOW 8)
// =============================================================================
pa_heading('D30 — a shorter interval');
pa_resetWorld();
pa_q('UPDATE tblExternalFeeds SET nextFetchAt = UTC_TIMESTAMP() + INTERVAL 6 DAY, fetchEveryMins = 10080 WHERE feedID = ?', 'i', [FA1]);
$r30 = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['fetchEveryMins' => 15]));
pa_ok(
    'D30 shortened from 10080 to 15: the next refresh is at most 15 minutes after the database\'s now',
    $r30['ok'] === true && (int) pa_one('SELECT (nextFetchAt <= UTC_TIMESTAMP() + INTERVAL 905 SECOND) AS ok FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['ok'] === 1,
    pa_show($r30)
);
$next30 = static fn (): string => (string) pa_one('SELECT nextFetchAt AS n FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['n'];
pa_resetWorld();
pa_q('UPDATE tblExternalFeeds SET nextFetchAt = UTC_TIMESTAMP() + INTERVAL 10 MINUTE, fetchEveryMins = 15 WHERE feedID = ?', 'i', [FA1]);
$n30a = $next30();
$r30b = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['fetchEveryMins' => 10080]));
pa_ok('D30 lengthened from 15 to 10080: the next refresh unchanged', $r30b['ok'] === true && $next30() === $n30a);
// Checker LOW 8: a calendar that keeps failing has its next try pushed far
// out (the importer's back-off). A rename — or a LONGER interval — must not
// pull that try forward again.
pa_resetWorld();
pa_q('UPDATE tblExternalFeeds SET nextFetchAt = UTC_TIMESTAMP() + INTERVAL 2 DAY, fetchEveryMins = 360, consecutiveFailures = 4 WHERE feedID = ?', 'i', [FA1]);
$n30c = $next30();
$r30c = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['name' => 'D30 renamed']));
$n30d = $next30();
$r30d = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['fetchEveryMins' => 1440]));
pa_ok(
    'D30 a failing calendar\'s back-off (next try in two days): a rename, and a longer interval, leave it exactly where it is (checker LOW 8)',
    $r30c['ok'] === true && $r30d['ok'] === true && $n30d === $n30c && $next30() === $n30c,
    pa_show([$n30c, $n30d, $next30()])
);

// =============================================================================
// D31 — stored values this server would not offer today (challenge L7; checker LOW 3)
// =============================================================================
pa_heading('D31 — a stored zone this server does not list');
pa_resetWorld();
$missingZone = 'Asia/Calcutta';
$otherOld    = 'Asia/Saigon';
$listed31    = DateTimeZone::listIdentifiers();
pa_ok('D31 the two old zone names used here are really absent from this server\'s list (else this check proves nothing)', in_array($missingZone, $listed31, true) === false && in_array($otherOld, $listed31, true) === false);
pa_q("UPDATE tblExternalFeeds SET timezone = ?, lastContentHash = SHA2('x', 256) WHERE feedID = ?", 'si', [$missingZone, FA1]);
$r31 = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['name' => 'D31 renamed']));
pa_ok(
    'D31 saving with the SAME unlisted zone: accepted, lastContentHash untouched, no refresh forced',
    $r31['ok'] === true && $r31['refreshNeeded'] === false && pa_one('SELECT lastContentHash AS h FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['h'] !== null,
    pa_show($r31)
);
$r31b = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['timezone' => $otherOld]));
pa_ok('D31 a DIFFERENT unlisted zone is still refused: "Choose a time zone from the list."', $r31b['ok'] === false && $r31b['message'] === 'Choose a time zone from the list.', pa_show($r31b));
// Checker LOW 3: the same trap for the default category.
pa_q('UPDATE tblExternalFeeds SET categoryID = ? WHERE feedID = ?', 'ii', [CA2, FA1]);
pa_q('UPDATE tblEventCategories SET isActive = 0 WHERE categoryID = ?', 'i', [CA2]);
$r31c = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'public']));
pa_ok(
    'D31 a calendar whose stored category has since been switched off can still be saved with it (checker LOW 3)',
    $r31c['ok'] === true && (int) pa_one('SELECT categoryID FROM tblExternalFeeds WHERE feedID = ?', 'i', [FA1])['categoryID'] === CA2,
    pa_show($r31c)
);
$r31d = FeedAdmin::saveCalendar($mysqli, FA2, ORG_A, SA, pa_calPost(FA2, ['categoryID' => (string) CA2]));
pa_ok('D31 but choosing that switched-off category NEW for another calendar is refused: "Choose a category from the list."', $r31d['ok'] === false && $r31d['message'] === 'Choose a category from the list.', pa_show($r31d));
pa_q('UPDATE tblExternalFeeds SET categoryID = ? WHERE feedID = ?', 'ii', [CB1, FA1]);
$r31e = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1));
pa_ok('D31 a stored category of ANOTHER organisation (a hand edit) is not kept "unchanged": refused', $r31e['ok'] === false && $r31e['message'] === 'Choose a category from the list.', pa_show($r31e));

// =============================================================================
// D32 — a paused calendar (challenge L9)
// =============================================================================
pa_heading('D32 — a paused calendar');
pa_resetWorld();
$ev32 = pa_event(FA1, 'd32@p8', 'D32 event');
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE eventID = ?', 'i', [$ev32]);
pa_q('UPDATE tblExternalFeeds SET isActive = 0 WHERE feedID = ?', 'i', [FA1]);
$fa1In32 = array_values(array_filter(FeedAdmin::calendars($mysqli, ORG_A), static fn (array $c): bool => (int) $c['feedID'] === FA1))[0] ?? [];
pa_ok('D32 a paused calendar with an expired stored answer: stale = 0 (calendar() and calendars() agree)', (int) FeedAdmin::calendar($mysqli, FA1, ORG_A)['stale'] === 0 && (int) ($fa1In32['stale'] ?? -1) === 0);
pa_q('UPDATE tblExternalFeeds SET isActive = 1 WHERE feedID = ?', 'i', [FA1]);
pa_ok('D32 switched on: stale = 1', (int) FeedAdmin::calendar($mysqli, FA1, ORG_A)['stale'] === 1);
pa_resetWorld();
$ev32b = pa_event(FA1, 'd32b@p8', 'D32b event');
$r32   = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D32', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D32b']]]);
pa_q('UPDATE tblExternalFeeds SET isActive = 0 WHERE feedID = ?', 'i', [FA1]);
$row32 = array_values(array_filter(FeedAdmin::pendingApprovals($mysqli, ORG_A, null, 50)['rows'], static fn (array $r): bool => (int) $r['eventID'] === $ev32b))[0] ?? null;
pa_ok('D32 pendingApprovals() rows of a paused calendar carry feedActive = 0', $r32['ok'] === true && $row32 !== null && (int) $row32['feedActive'] === 0, pa_show($row32));

// =============================================================================
// D33 — the "Partly read" flag (chunk P8-2's follow-up)
// =============================================================================
// calendar() and calendars() return `lastRunCapped`: whether the newest
// refresh that READ the calendar took only part of it. It must follow the
// NEWEST reading — an older capped reading never drives it — and a later
// refresh that read nothing new (unchanged, or failed) must not clear it,
// because the events shown are still that partial reading's.
pa_heading('D33 — the "Partly read" flag');
pa_resetWorld();
$flag33 = static function (int $feedId): array {
    global $mysqli;
    $one  = FeedAdmin::calendar($mysqli, $feedId, ORG_A);
    $many = array_values(array_filter(FeedAdmin::calendars($mysqli, ORG_A), static fn (array $c): bool => (int) $c['feedID'] === $feedId))[0] ?? [];

    return [
        ($one === null || array_key_exists('lastRunCapped', $one) === false) ? null : (int) $one['lastRunCapped'],
        array_key_exists('lastRunCapped', $many) === true ? (int) $many['lastRunCapped'] : null,
    ];
};
pa_capRun(FA1, ORG_A, 'ok', 120);
pa_capRun(FA1, ORG_A, 'partial', 60);
pa_ok('D33 the newest reading was capped: lastRunCapped = 1, from calendar() and calendars() alike', $flag33(FA1) === [1, 1], pa_show($flag33(FA1)));
pa_capRun(FA2, ORG_A, 'partial', 120);
pa_capRun(FA2, ORG_A, 'ok', 60);
pa_ok('D33 an older capped reading followed by a full one: lastRunCapped = 0 from both (an older run never drives it)', $flag33(FA2) === [0, 0], pa_show($flag33(FA2)));
$fa33 = pa_newFeed(ORG_A, 'members');
pa_capRun($fa33, ORG_A, 'partial', 120);
pa_capRun($fa33, ORG_A, 'unchanged', 60);
pa_capRun($fa33, ORG_A, 'failed', 30);
pa_ok(
    'D33 a capped reading followed by an unchanged and a failed refresh (neither read anything new): still 1 — the events shown are still that partial reading',
    $flag33($fa33) === [1, 1],
    pa_show($flag33($fa33))
);

// =============================================================================
// D34 — events() marks the dates of a repeating event (chunk P8-3)
// =============================================================================
// The calendar page offers "Set every date…" only for a date of a repeating
// event (its recurrence key is not empty); a one-off has no series, and its
// series choice page answers "not found". events() says which is which.
pa_heading('D34 — events() marks the dates of a repeating event');
pa_resetWorld();
$s34 = pa_series(FA1, 'd34-series@p8', 'D34 series', ['count' => 2]);
$o34 = pa_event(FA1, 'd34-one@p8', 'D34 one-off');
$f34 = [];
foreach (FeedAdmin::events($mysqli, FA1, ORG_A, 1)['rows'] as $row34) {
    $f34[(int) $row34['eventID']] = $row34['inSeries'] ?? null;
}
pa_ok(
    'D34 events(): both series dates carry inSeries = true, the one-off false (as true and false, never as numbers)',
    ($f34[$s34[0]] ?? null) === true && ($f34[$s34[1]] ?? null) === true && ($f34[$o34] ?? null) === false,
    pa_show($f34)
);

// =============================================================================
// D35 — previewRule() lines carry each event's own zone and all-day flag
// =============================================================================
// Fix round 1 of chunk P8-3, check finding L1. The rule page printed every
// preview line as a plain clock time in the organisation's zone, because the
// resolver's lines carry only the start as written: an all-day event read
// "00:00", and a New York event lost its zone. FeedAdmin now adds each line's
// `timezone` and `isAllDay` with ONE read for the whole calendar — never one
// per line, which the second check counts.
pa_heading('D35 — previewRule() lines carry each event\'s own zone and all-day flag');
pa_resetWorld();
$z1 = pa_event(FA1, 'd35-allday@p8', 'D35 all day');
$z2 = pa_event(FA1, 'd35-ny@p8', 'D35 New York');
$z3 = pa_event(FA1, 'd35-plain@p8', 'D35 plain');
pa_q("UPDATE tblEvents SET isAllDay = 1, startDateTime = CONCAT(DATE(startDateTime), ' 00:00:00') WHERE eventID = ?", 'i', [$z1]);
pa_q("UPDATE tblEvents SET timezone = 'America/New_York' WHERE eventID = ?", 'i', [$z2]);
$post35 = ['name' => 'D35 draft', 'isActive' => '1', 'audienceLevel' => 'members', 'detailLevel' => 'basic',
    'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D35']]];
$p35  = FeedAdmin::previewRule($mysqli, FA1, 0, ORG_A, SA, $post35);
$by35 = [];
foreach (($p35['lines'] ?? []) as $line35) {
    $by35[(int) $line35['eventID']] = [$line35['timezone'] ?? 'missing', $line35['isAllDay'] ?? 'missing'];
}
pa_ok(
    'D35 the all-day line says isAllDay = 1; the New York line keeps America/New_York; a plain line has the organisation\'s zone and 0',
    ($p35['ok'] ?? false) === true && count($by35) === 3
    && ($by35[$z1] ?? null) === [SITE_ZONE, 1] && ($by35[$z2] ?? null) === ['America/New_York', 0] && ($by35[$z3] ?? null) === [SITE_ZONE, 0],
    pa_show($by35)
);
$few35 = pa_statementsOf(static fn () => FeedAdmin::previewRule($mysqli, FA1, 0, ORG_A, SA, $post35));
for ($i35 = 0; $i35 < 5; $i35++) {
    pa_event(FA1, 'd35-extra-' . $i35 . '@p8', 'D35 extra ' . $i35);
}
$lines35 = count(FeedAdmin::previewRule($mysqli, FA1, 0, ORG_A, SA, $post35)['lines'] ?? []);
$many35  = pa_statementsOf(static fn () => FeedAdmin::previewRule($mysqli, FA1, 0, ORG_A, SA, $post35));
pa_ok(
    'D35 the same number of statements for 3 lines and for 8 (the zone and all-day flag are one read, never one per line)',
    $lines35 === 8 && $few35 === $many35,
    pa_show(['lines' => $lines35, '3 lines' => $few35, '8 lines' => $many35])
);

// =============================================================================
// D36 — removing a choice: the sentence follows the resolver's order
// =============================================================================
// Fix round 1 of chunk P8-3, check finding L2. Removing ONE date's choice
// under a choice for every date sends that date back to the choice for every
// date (the resolver's step 3) — not to "the rules and the calendar's own
// setting", which is what the message used to promise. Checked against what
// the resolver really did, for both kinds of date.
pa_heading('D36 — removing a choice: the sentence follows the resolver\'s order');
pa_resetWorld();
[$h1, $h2] = pa_series(FA1, 'd36@p8', 'D36 series', ['count' => 2]);
$all36    = FeedAdmin::saveChoice($mysqli, $h1, 'series', ORG_A, SA, ['audienceLevel' => 'hidden']);
$own36    = FeedAdmin::saveChoice($mysqli, $h1, 'date', ORG_A, SA, ['audienceLevel' => 'members']);
$before36 = [pa_ls($h1), pa_ls($h2)];
$rm36     = FeedAdmin::removeChoice($mysqli, $h1, 'date', ORG_A, SA);
pa_ok(
    'D36 one date\'s own choice removed under a choice for every date: "' . MSG_REMOVED_DATE_UNDER_EVERY . '", and the date is decided by the choice for every date again',
    $all36['ok'] === true && $own36['ok'] === true && $before36 === ['members/date', 'hidden/series']
    && $rm36['ok'] === true && $rm36['message'] === MSG_REMOVED_DATE_UNDER_EVERY && pa_ls($h1) === 'hidden/series' && pa_ls($h2) === 'hidden/series',
    pa_show([$before36, $rm36, pa_ls($h1), pa_ls($h2)])
);
$o36   = pa_event(FA1, 'd36-one@p8', 'D36 one-off');
$oc36  = FeedAdmin::saveChoice($mysqli, $o36, 'date', ORG_A, SA, ['audienceLevel' => 'hidden']);
$orm36 = FeedAdmin::removeChoice($mysqli, $o36, 'date', ORG_A, SA);
pa_ok(
    'D36 a date that does not repeat: "' . MSG_REMOVED_DATE_ALONE . '", and it follows the calendar again',
    $oc36['ok'] === true && $orm36['ok'] === true && $orm36['message'] === MSG_REMOVED_DATE_ALONE && pa_ls($o36) === 'members/calendar',
    pa_show([$orm36, pa_ls($o36)])
);

// =============================================================================
// D37 — a choice for every date left over after the event stops repeating
// =============================================================================
// Fix round 2 of chunk P8-3, check finding F1, in the shape of the check's
// probe P01a. The resolver finds a choice for every date by the event's
// identity alone, so when an outside calendar turns a repeating event into a
// one-off with the same UID (the importer marks the old dates deleted and
// brings in one date with no recurrence key — done here by hand, the same
// way), the old choice still decides the one date left. removeChoice()'s
// sentence must say so, and the resolver must agree; the leftover must be
// removable through the one-off, and a NEW one can never be made for it.
pa_heading('D37 — a choice for every date left over after the event stops repeating');
pa_resetWorld();
[$k1, $k2] = pa_series(FA1, 'd37@p8', 'D37 club', ['count' => 2]);
$all37 = FeedAdmin::saveChoice($mysqli, $k1, 'series', ORG_A, SA, ['audienceLevel' => 'hidden']);
pa_q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID IN (?, ?)', 'ii', [$k1, $k2]);
$one37 = pa_event(FA1, 'd37@p8', 'D37 club', ['day' => 40]);
$cal37 = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1));
$left37 = pa_ls($one37);
$own37 = FeedAdmin::saveChoice($mysqli, $one37, 'date', ORG_A, SA, ['audienceLevel' => 'members']);
$mid37 = pa_ls($one37);
$rm37  = FeedAdmin::removeChoice($mysqli, $one37, 'date', ORG_A, SA);
pa_ok(
    'D37 the one date left is decided by the old choice for every date; removing its own choice says "' . MSG_REMOVED_DATE_UNDER_EVERY . '", and the resolver agrees',
    $all37['ok'] === true && $cal37['ok'] === true && $left37 === 'hidden/series' && $own37['ok'] === true && $mid37 === 'members/date'
    && $rm37['ok'] === true && $rm37['message'] === MSG_REMOVED_DATE_UNDER_EVERY && pa_ls($one37) === 'hidden/series',
    pa_show([$left37, $mid37, $rm37, pa_ls($one37)])
);
$fp37  = pa_fp();
$new37 = FeedAdmin::saveChoice($mysqli, $one37, 'series', ORG_A, SA, ['audienceLevel' => 'public']);
pa_ok(
    'D37 a NEW choice for every date cannot be made for that one-off: "not found", and nothing written',
    $new37['ok'] === false && $new37['status'] === 'not_found' && pa_fp() === $fp37,
    pa_show($new37)
);
$rmAll37 = FeedAdmin::removeChoice($mysqli, $one37, 'series', ORG_A, SA);
$again37 = FeedAdmin::removeChoice($mysqli, $one37, 'series', ORG_A, SA);
pa_ok(
    'D37 the leftover is removed through the one-off ("' . MSG_REMOVED_SERIES . '") and the date follows the calendar again; removing it a second time is "' . MSG_NF_EVENT . '"',
    $rmAll37['ok'] === true && $rmAll37['message'] === MSG_REMOVED_SERIES && pa_ls($one37) === 'members/calendar'
    && $again37['ok'] === false && $again37['status'] === 'not_found' && $again37['message'] === MSG_NF_EVENT,
    pa_show([$rmAll37, $again37, pa_ls($one37)])
);

// =============================================================================
// D38 — the same UID in two calendars of one organisation
// =============================================================================
// Fix round 3 of chunk P8-3, check finding R3-2, in the shape of the check's
// probe P2. An event's identity is the UID alone, so an invitation that sits
// in two calendars of the same organisation has the same identity in both.
// removeChoice() looks for a choice for every date in THIS calendar only, as
// the resolver does; a look-up widened to the whole organisation would find
// FA2's and say FA1's date follows it, which is untrue.
pa_heading('D38 — the same UID in two calendars: removing one date\'s choice looks for a choice for every date in its own calendar only');
pa_resetWorld();
[$u1, $u2] = pa_series(FA2, 'd38@p8', 'D38 shared invitation', ['count' => 2]);
$all38 = FeedAdmin::saveChoice($mysqli, $u1, 'series', ORG_A, SA, ['audienceLevel' => 'hidden']);
$one38 = pa_event(FA1, 'd38@p8', 'D38 shared invitation', ['day' => 40]);
$own38 = FeedAdmin::saveChoice($mysqli, $one38, 'date', ORG_A, SA, ['audienceLevel' => 'hidden']);
$rm38  = FeedAdmin::removeChoice($mysqli, $one38, 'date', ORG_A, SA);
pa_ok(
    'D38 FA2 has a choice for every date of the same UID; removing FA1\'s date choice says "' . MSG_REMOVED_DATE_ALONE . '", and the resolver agrees (FA1\'s own setting)',
    $all38['ok'] === true && $own38['ok'] === true && pa_ls($u1) === 'hidden/series'
    && $rm38['ok'] === true && $rm38['message'] === MSG_REMOVED_DATE_ALONE && pa_ls($one38) === 'members/calendar',
    pa_show([$rm38, pa_ls($one38), pa_ls($u1)])
);

// =============================================================================
// D39 — the fields chunk P8-4's pages read (chunk P8-4)
// =============================================================================
// Chunk P8-4 added five small READS to FeedAdmin, because its pages had no
// other way to say something true without reaching into the database
// themselves (which no page may do — part S, S1):
//   - importedEvent() now carries `importAudienceType` / `importAudienceID`,
//     so the preview of a date names the people its Selected-groups answer
//     lets in;
//   - pendingApprovals() rows carry `reason`, so each waiting row says WHY;
//   - approval() carries `siblingsWaiting` (the same group pendingApprovals()
//     counts) for the preview page, and its `lastSuperseded` carries the old
//     snapshot's fields, so the preview can say what changed;
//   - decide() answers `decidedIds`, so the decision handler can write one
//     activity line per date it really decided — never one for a date that
//     merely FOLLOWED a decision, or one that had changed.
pa_heading('D39 — the fields chunk P8-4\'s pages read');
pa_resetWorld();
$g39 = pa_event(FA1, 'd39-g@p8', 'D39 group date');
$c39 = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1, ['audienceLevel' => 'groups', 'audience' => ['small_group' => [SG1]]]));
$i39 = FeedAdmin::importedEvent($mysqli, $g39, ORG_A) ?? [];
$s39 = pa_ev($g39);
pa_ok(
    'D39 importedEvent() names whose list a Selected-groups answer uses, exactly as stored (the calendar\'s own list here)',
    $c39['ok'] === true && ($s39['importAudienceType'] ?? null) === 'feed' && (int) ($s39['importAudienceID'] ?? 0) === FA1
    && ($i39['importAudienceType'] ?? null) === $s39['importAudienceType'] && (int) ($i39['importAudienceID'] ?? 0) === (int) $s39['importAudienceID'],
    pa_show([$c39['ok'], $s39['importAudienceType'] ?? null, $s39['importAudienceID'] ?? null, $i39['importAudienceType'] ?? null, $i39['importAudienceID'] ?? null])
);

pa_resetWorld();
$ser39  = pa_series(FA1, 'd39-s@p8', 'D39 series', ['count' => 3]);
$club39 = pa_event(FA1, 'd39-club@p8', 'D39 club', ['description' => 'Old words']);
$rule39 = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D39', 'audienceLevel' => 'public', 'detailLevel' => 'full', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D39']]]);
$p39    = [];
foreach (array_merge($ser39, [$club39]) as $eid) {
    $p39[$eid] = pa_pending($eid);
}
$list39 = FeedAdmin::pendingApprovals($mysqli, ORG_A, FA1, 50);
$why39  = [];
$sib39  = [];
foreach ($list39['rows'] as $row) {
    $stored = pa_one('SELECT reason FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [(int) $row['approvalID']]);
    $why39[(int) $row['eventID']] = [(string) ($row['reason'] ?? '(missing)'), (string) ($stored['reason'] ?? '(none)')];
    $one = FeedAdmin::approval($mysqli, (int) $row['approvalID'], ORG_A);
    $sib39[(int) $row['eventID']] = [(int) $row['siblingsWaiting'], (int) ($one['siblingsWaiting'] ?? -1)];
}
$whyOk39 = count($why39) === 4;
foreach ($why39 as [$given, $stored]) {
    if ($given !== $stored || $given === '' || isset(FeedAdmin::REASON_LABELS[$given]) === false) {
        $whyOk39 = false;
    }
}
pa_ok(
    'D39 pendingApprovals() gives each of the four waiting rows its stored reason, one REASON_LABELS has words for',
    $rule39['ok'] === true && in_array(null, $p39, true) === false && $whyOk39 === true,
    pa_show($why39)
);
pa_ok(
    'D39 approval() counts the same waiting siblings as pendingApprovals(): 2 for each date of the three-date series, 0 for the one-off',
    ($sib39[$ser39[0]] ?? null) === [2, 2] && ($sib39[$ser39[1]] ?? null) === [2, 2] && ($sib39[$ser39[2]] ?? null) === [2, 2] && ($sib39[$club39] ?? null) === [0, 0],
    pa_show($sib39)
);
$dec39  = FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($p39[$ser39[0]]), pa_item($p39[$club39])]);
$ids39  = (array) ($dec39['decidedIds'] ?? []);
sort($ids39);
$want39 = [(int) $p39[$ser39[0]]['approvalID'], (int) $p39[$club39]['approvalID']];
sort($want39);
$after39 = FeedAdmin::approval($mysqli, (int) $p39[$ser39[0]]['approvalID'], ORG_A);
pa_ok(
    'D39 decide() of one series date and the one-off: decidedIds is exactly those two — not the two dates that followed (they are approved, but nobody decided them) — and a decided row has 0 waiting siblings',
    $dec39['decided'] === 2 && $ids39 === $want39 && pa_status((int) $p39[$ser39[1]]['approvalID']) === 'approved'
    && pa_status((int) $p39[$ser39[2]]['approvalID']) === 'approved' && (int) ($after39['siblingsWaiting'] ?? -1) === 0,
    pa_show(['ids' => $ids39, 'want' => $want39, 'followers' => [pa_status((int) $p39[$ser39[1]]['approvalID']), pa_status((int) $p39[$ser39[2]]['approvalID'])]])
);
// The one-off's description changes; at full detail that sends it back for
// approval, and the decided row is replaced ("superseded").
pa_q('UPDATE tblEvents SET description = ? WHERE eventID = ?', 'si', ['New words', $club39]);
$re39   = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1));
$new39  = pa_pending($club39);
$view39 = $new39 === null ? null : FeedAdmin::approval($mysqli, (int) $new39['approvalID'], ORG_A);
$old39  = is_array($view39['lastSuperseded'] ?? null) === true ? $view39['lastSuperseded'] : [];
pa_ok(
    'D39 approval()\'s lastSuperseded carries the replaced decision\'s snapshot: its number, the old description, location, link and category, the detail it was asked at, and when it was decided',
    $re39['ok'] === true && $new39 !== null && (int) ($old39['approvalID'] ?? 0) === (int) $p39[$club39]['approvalID']
    && ($old39['snapDescription'] ?? null) === 'Old words' && ($old39['snapLocation'] ?? null) === 'A hall'
    && ($old39['snapUrl'] ?? null) === 'https://example.invalid/e' && array_key_exists('snapCategoryID', $old39) === true && $old39['snapCategoryID'] === null
    && ($old39['requestedDetail'] ?? null) === 'full' && ($old39['decidedAt'] ?? null) !== null
    && pa_status((int) $p39[$club39]['approvalID']) === 'superseded',
    pa_show(['new' => $new39 === null ? null : (int) $new39['approvalID'], 'old' => $old39, 'oldStatus' => pa_status((int) $p39[$club39]['approvalID'])])
);
$stale39 = $new39 === null ? ['decidedIds' => ['x']] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [['approvalID' => (int) $new39['approvalID'], 'requestHash' => (string) $new39['requestHash'], 'contentHash' => str_repeat('0', 64)]]);
pa_ok(
    'D39 a date that had changed is not in decidedIds (none decided, 1 changed), and it is still waiting',
    ($stale39['decidedIds'] ?? ['x']) === [] && ($stale39['changed'] ?? 0) === 1 && $new39 !== null && pa_status((int) $new39['approvalID']) === 'pending',
    pa_show($stale39)
);

// --- Chunk P8-4 fix round 1 (the check's N06 and LOW 5) ---
// N06: approval()'s sibling count must hold the details to the same test as
// pendingApprovals(): three dates of one series wait for one rule, but the
// third's title differs — so its details (its content fingerprint) differ,
// and it is nobody's sibling. A count that ignored the details called all
// three siblings of each other.
pa_resetWorld();
$df39 = pa_series(FA1, 'd39-diff@p8', 'D39 differ', ['count' => 3, 'titles' => ['D39 differ talk', 'D39 differ talk', 'D39 differ talk RENAMED']]);
$rd39 = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D39 differ', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D39 differ']]]);
$pd39 = array_map('pa_pending', $df39);
$ad39 = [];
$ld39 = [];
foreach (FeedAdmin::pendingApprovals($mysqli, ORG_A, FA1, 50)['rows'] as $row) {
    $ld39[(int) $row['eventID']] = (int) $row['siblingsWaiting'];
}
foreach ($pd39 as $i => $row) {
    $ad39[$i] = $row === null ? -1 : (int) (FeedAdmin::approval($mysqli, (int) $row['approvalID'], ORG_A)['siblingsWaiting'] ?? -1);
}
pa_ok(
    'D39 three waiting dates of one series under one rule, the third with a different title: approval() counts 1 sibling for each of the first two and 0 for the third — exactly as pendingApprovals() does (the check\'s N06)',
    $rd39['ok'] === true && in_array(null, $pd39, true) === false && $ad39 === [1, 1, 0]
    && [$ld39[$df39[0]] ?? -1, $ld39[$df39[1]] ?? -1, $ld39[$df39[2]] ?? -1] === [1, 1, 0],
    pa_show(['approval' => $ad39, 'pendingApprovals' => $ld39])
);

// LOW 5 (in FeedAdmin): a "seen" entry whose fingerprint has a line break on
// the end is unreadable and dropped — so the date waits for the SAME reason
// as a date nobody saw — never kept and then filed as "content_changed"
// (which is what `$` instead of `\z` did). Control: the exact fingerprint
// is seen, so that date applies at once.
pa_resetWorld();
$sa39 = pa_event(FA1, 'd39-seen-a@p8', 'D39 seen exact');
$sb39 = pa_event(FA1, 'd39-seen-b@p8', 'D39 seen newline');
$sc39 = pa_event(FA1, 'd39-seen-c@p8', 'D39 seen none');
$ca39 = FeedAdmin::saveChoice($mysqli, $sa39, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [$sa39 . ':' . pa_cf($sa39)]]);
$cb39 = FeedAdmin::saveChoice($mysqli, $sb39, 'date', ORG_A, SA, ['audienceLevel' => 'public', 'seen' => [$sb39 . ':' . pa_cf($sb39) . "\n"]]);
$cc39 = FeedAdmin::saveChoice($mysqli, $sc39, 'date', ORG_A, SA, ['audienceLevel' => 'public']);
$wb39 = pa_pending($sb39);
$wc39 = pa_pending($sc39);
pa_ok(
    'D39 a seen fingerprint with a line break on the end is dropped as unreadable: that date waits for the same reason as an unseen one (not "content_changed"); the exactly-seen control date is shown at once',
    $ca39['ok'] === true && $cb39['ok'] === true && $cc39['ok'] === true && pa_ls($sa39) === 'public/date'
    && $wb39 !== null && $wc39 !== null && (string) $wb39['reason'] === (string) $wc39['reason'] && (string) $wb39['reason'] !== 'content_changed',
    pa_show([pa_ls($sa39), $wb39['reason'] ?? null, $wc39['reason'] ?? null])
);

// Fix round 2 (check round 2's LOW 1): importedEvent() says whether the
// calendar is switched on (`feedActive`) and whether the date's stored
// answer has run out (`stale`) — the latter by the DATABASE's clock. This
// session runs 13 hours ahead of UTC (D0), so a `NOW()` where
// `UTC_TIMESTAMP()` belongs would call an answer that runs out in an hour's
// time "run out" already.
pa_resetWorld();
$st39 = pa_event(FA1, 'd39-state@p8', 'D39 state');
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() + INTERVAL 1 HOUR WHERE eventID = ?', 'i', [$st39]);
$fresh39 = FeedAdmin::importedEvent($mysqli, $st39, ORG_A) ?? [];
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE eventID = ?', 'i', [$st39]);
$past39 = FeedAdmin::importedEvent($mysqli, $st39, ORG_A) ?? [];
pa_q('UPDATE tblEvents SET importRecheckAt = NULL WHERE eventID = ?', 'i', [$st39]);
pa_q('UPDATE tblExternalFeeds SET isActive = 0 WHERE feedID = ?', 'i', [FA1]);
$off39 = FeedAdmin::importedEvent($mysqli, $st39, ORG_A) ?? [];
pa_ok(
    'D39 importedEvent(): stale is 0 for an answer that runs out in an hour, 1 for one that ran out an hour ago, 0 for none; feedActive is 1, then 0 once the calendar is paused',
    (int) ($fresh39['stale'] ?? -1) === 0 && (int) ($past39['stale'] ?? -1) === 1 && (int) ($off39['stale'] ?? -1) === 0
    && (int) ($fresh39['feedActive'] ?? -1) === 1 && (int) ($off39['feedActive'] ?? -1) === 0,
    pa_show([[$fresh39['stale'] ?? null, $fresh39['feedActive'] ?? null], [$past39['stale'] ?? null], [$off39['stale'] ?? null, $off39['feedActive'] ?? null]])
);

// The final fault run's m-X05: whoCanSee() — the copy behind every save's
// "N dates changed who can see them" — must judge "still in date" by the
// database's UTC clock too. Its `inDate` test with NOW() in place of
// UTC_TIMESTAMP() passed every check before this one; then saving
// "Everyone, last day today" on a Members calendar's date said "1 date
// changed who can see it", which was untrue. Asked here directly (through
// pa_copySig(), by reflection), in this session thirteen hours AHEAD of
// UTC (D0): an answer that runs out in two hours is still in date, so the
// date reads exactly as with no end at all; one that ran out two hours ago
// reads as administrators only.
pa_resetWorld();
$wc39 = pa_event(FA1, 'd39-whocansee@p8', 'D39 who can see');
$wr39 = FeedAdmin::saveCalendar($mysqli, FA1, ORG_A, SA, pa_calPost(FA1));
pa_q('UPDATE tblEvents SET importRecheckAt = NULL WHERE eventID = ?', 'i', [$wc39]);
$wNone39 = pa_copySig(FA1)[$wc39] ?? '(none)';
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() + INTERVAL 2 HOUR WHERE eventID = ?', 'i', [$wc39]);
$wSoon39 = pa_copySig(FA1)[$wc39] ?? '(none)';
pa_q('UPDATE tblEvents SET importRecheckAt = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE eventID = ?', 'i', [$wc39]);
$wGone39 = pa_copySig(FA1)[$wc39] ?? '(none)';
$zone39  = (string) (pa_one('SELECT @@session.time_zone AS z')['z'] ?? '');
pa_ok(
    'D39 whoCanSee(), in a session at ' . $zone39 . ': a Members date whose answer runs out in two hours (UTC) reads exactly as one with no end ("' . $wNone39 . '"); one that ran out two hours ago reads "admins" (the final fault run\'s m-X05)',
    $wr39['ok'] === true && pa_ls($wc39) === 'members/calendar' && $zone39 !== 'UTC' && $zone39 !== '+00:00'
    && $wNone39 === 'members' && $wSoon39 === $wNone39 && $wGone39 === 'admins',
    pa_show(['zone' => $zone39, 'none' => $wNone39, 'in 2 hours' => $wSoon39, '2 hours ago' => $wGone39])
);

// The final whole-part check, F1: removing a choice from a date the outside
// calendar marks PRIVATE used to say the date "now follows the rules and
// the calendar's own setting" — but rules never reach a private date; it
// goes back to administrators only. The message must say what the resolver
// does, and the resolver must agree (probe 1's shape: a Members calendar
// and a Members rule that matches the date).
pa_resetWorld();
$pv39  = pa_event(FA1, 'd39-private@p8', 'D39 private talk', ['private' => 1]);
$pvs39 = pa_series(FA1, 'd39-private-s@p8', 'D39 private series talk', ['count' => 2, 'private' => 1, 'day' => 30]);
$pvr39 = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D39 talks', 'audienceLevel' => 'members', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D39']]]);
$pvc39 = FeedAdmin::saveChoice($mysqli, $pv39, 'date', ORG_A, SA, ['audienceLevel' => 'members', 'overridesPrivateMark' => '1', 'seen' => [$pv39 . ':' . pa_cf($pv39)]]);
$pvShown39 = pa_ls($pv39);
$pvRm39 = FeedAdmin::removeChoice($mysqli, $pv39, 'date', ORG_A, SA);
$pvsC39 = FeedAdmin::saveChoice($mysqli, $pvs39[0], 'series', ORG_A, SA, ['audienceLevel' => 'hidden']);
$pvsRm39 = FeedAdmin::removeChoice($mysqli, $pvs39[0], 'series', ORG_A, SA);
pa_ok(
    'D39 removing a choice from a PRIVATE date: "Choice removed. The outside calendar marks this date private, so it is now shown only to administrators." — and the resolver agrees (hidden/private, the matching Members rule ignored); for every date of a private series the sentence says so too (the final check\'s F1)',
    $pvr39['ok'] === true && $pvc39['ok'] === true && $pvShown39 === 'members/date'
    && ($pvRm39['message'] ?? '') === 'Choice removed. The outside calendar marks this date private, so it is now shown only to administrators.'
    && pa_ls($pv39) === 'hidden/private'
    && $pvsC39['ok'] === true
    && ($pvsRm39['message'] ?? '') === "Choice removed. Each date now follows its own choice while one is in force, otherwise the rules and the calendar's own setting. A date the outside calendar marks private is shown only to administrators, unless its own choice shows it."
    && pa_ls($pvs39[0]) === 'hidden/private' && pa_ls($pvs39[1]) === 'hidden/private',
    pa_show([$pvShown39, $pvRm39['message'] ?? null, pa_ls($pv39), $pvsRm39['message'] ?? null, pa_ls($pvs39[0])])
);
// …and a date the outside calendar lists TWICE: a widening rule leaves it
// at the calendar's own setting, so the message must not promise the rules.
pa_resetWorld();
$tw39  = pa_event(FA1, 'd39-twice@p8', 'D39 twice talk', ['duplicate' => 1]);
$twr39 = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, ['name' => 'D39 wide', 'audienceLevel' => 'public', 'isActive' => '1', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D39']]]);
$twc39 = FeedAdmin::saveChoice($mysqli, $tw39, 'date', ORG_A, SA, ['audienceLevel' => 'hidden']);
$twRm39 = FeedAdmin::removeChoice($mysqli, $tw39, 'date', ORG_A, SA);
pa_ok(
    'D39 removing a choice from a date the outside calendar lists TWICE: the message adds "…lists this date twice, so a rule or a choice can show it more narrowly, but never more widely." — and the widening Everyone rule indeed leaves it at Members',
    $twr39['ok'] === true && $twc39['ok'] === true
    && ($twRm39['message'] ?? '') === "Choice removed. This date now follows the rules and the calendar's own setting. The outside calendar lists this date twice, so a rule or a choice can show it more narrowly, but never more widely."
    && str_starts_with(pa_ls($tw39), 'members/') === true,
    pa_show([$twRm39['message'] ?? null, pa_ls($tw39)])
);

// The final whole-part check, F3 (its probe 2): a date declined under one
// request and since shown under a NEWER, approved request must not read
// "Declined on …" on the choice page's list of dates.
pa_resetWorld();
[$fd1, $fd2] = pa_series(FA1, 'd39-f3@p8', 'D39 f3 talk', ['count' => 2]);
$f3rule = ['name' => 'D39 f3', 'isActive' => '1', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'conditions' => [['matchField' => 'title', 'matchType' => 'contains', 'matchValue' => 'D39 f3']]];
$f3r = FeedAdmin::saveRule($mysqli, FA1, 0, ORG_A, SA, $f3rule);
$f3d = ($p = pa_pending($fd2)) === null ? ['decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, 'declined', '', [pa_item($p)]);
$f3before = array_column(FeedAdmin::datesCovered($mysqli, FeedAdmin::importedEvent($mysqli, $fd1, ORG_A) ?? [], 'series'), 'state');
$f3rule['detailLevel'] = 'full';
$f3e = FeedAdmin::saveRule($mysqli, FA1, (int) ($f3r['ruleId'] ?? 0), ORG_A, SA, $f3rule);
$f3a = ($p = pa_pending($fd1)) === null ? ['decided' => -1] : FeedAdmin::decide($mysqli, ORG_A, SA, 'approved', '', [pa_item($p)]);
$f3after = array_column(FeedAdmin::datesCovered($mysqli, FeedAdmin::importedEvent($mysqli, $fd1, ORG_A) ?? [], 'series'), 'state');
pa_ok(
    'D39 a series declined under one request ("declined" on both dates), then approved under a newer one (both now Everyone): the choice page\'s list no longer says "declined" (the final check\'s F3)',
    $f3r['ok'] === true && $f3d['decided'] === 1 && $f3before === ['declined', 'declined'] && $f3e['ok'] === true && $f3a['decided'] === 1
    && pa_ls($fd1) === 'public/rule' && pa_ls($fd2) === 'public/rule' && $f3after === ['', ''],
    pa_show([$f3before, $f3after, pa_ls($fd1), pa_ls($fd2)])
);


} catch (Throwable $e) {
    pa_ok('the self-test ran to the end', false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    // 🧹 Never throws from here: a failed clean-up is one more FAIL line,
    //    and the summary still runs.
    try {
        $mysqli->query('SET timestamp = DEFAULT');
        $mysqli->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        pa_removeWorld();
        echo "\nThe made-up world was removed.\n";
    } catch (Throwable $e) {
        pa_ok('the made-up world was removed afterwards', false, get_class($e) . ': ' . $e->getMessage());
    }
}
} // end of part D

// =============================================================================
// 🌐 PART W — the real pages, through PHP's built-in web server
// =============================================================================
if (pa_wants('W') === true) {
    pw_run();
}

pa_finish();
