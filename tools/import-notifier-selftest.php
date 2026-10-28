<?php
// Path: tools/import-notifier-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Who is told that imported events are waiting — self-test 🔔🧪 (#514, part 9)
 * -----------------------------------------------------------------------------
 * `Portal\Core\ImportNotifier` tells an organisation's administrators that
 * events copied in from an outside calendar are waiting for approval: a card
 * on the dashboard, one e-mail a day, and a push. Almost every way of getting
 * it wrong is silent. A message that goes twice, or to the wrong person, or an
 * hour out on the night the clocks change, makes no page fail. So this file
 * builds a small made-up world in a throwaway database, runs the REAL class
 * at moments it chooses, records what WOULD be sent, and reads the database.
 *
 * IT SENDS NOTHING. `ImportNotifier::run()` takes two stand-ins, one in place
 * of the mailer and one in place of the push sender; this file passes
 * recorders. One check (N21) runs the real senders, and only after proving
 * that neither a mail route nor push is set up in the database it was given,
 * so that nothing can leave the machine.
 *
 * THE MADE-UP WORLD
 * -----------------
 * Four organisations, each in its own time zone:
 *   A  Europe/London      two site administrators, a global administrator
 *                         who is a member, a plain member, an account with
 *                         only the old portal-wide "administrator" flag, a
 *                         switched-off administrator, and an administrator
 *                         whose membership row is switched off
 *   B  America/Santiago   one administrator, one member
 *   C  Pacific/Auckland   NO administrator of its own
 *   D  (switched off)     an administrator, and an event waiting that nobody
 *                         must ever be told about
 * plus two global administrators with no membership anywhere, and a
 * switched-off one. The installation's own first organisation (number 1) is
 * used for the check of an installation with one organisation.
 *
 * EVERY DATE IS WORKED OUT FROM TODAY. The "ordinary" day is the first
 * Wednesday at least three days ahead that is nowhere near a clock change
 * in any of the three zones. The clock-change nights are found in PHP's own
 * time-zone data (`DateTimeZone::getTransitions()`) for the next twelve
 * months, never written down. A test written in December passes in London
 * whatever the code does, because London is on UTC that month; that is why
 * Santiago and Auckland are here, and why each change night is found and
 * not assumed.
 *
 * WHAT IT CHECKS (each must fail when its behaviour is broken; the faults
 * that were planted to prove that are listed in the build's evidence)
 *   N1   waiting(): only waiting events of switched-on calendars of that
 *        organisation, and equal to the approvals list's rows not marked paused
 *   N2   recipients(): exactly the organisation's administrators; and every
 *        recipient passes the approvals list's own gate, for every account
 *   N3   an organisation with no administrator who can be e-mailed: every
 *        active global administrator is told as well
 *   N4   the dashboard card: who gets it, the number, where it leads
 *   N5   the daily e-mail: one for each administrator; it holds the number,
 *        the calendars, each waiting event's title and time, the
 *        organisation's name and link and when the list was made; and nothing
 *        of another organisation, a paused calendar, a decided event, a
 *        place, a description or a calendar's address
 *   N6   one e-mail a day; nothing at all when nothing is waiting
 *   N7   not before 07:00 on the organisation's own clock; no end hour
 *   N8   both clock-change nights, in London and in Santiago: the day
 *        before, the day itself and the day after; the use-by moment; and an
 *        event's time shown exactly as stored, in the hour that happens
 *        twice and in the hour that does not exist. Pinned to a FIXED year
 *        (CHANGE_NIGHT_ANCHOR), never to today — see #652
 *   N9   Auckland: 07:00 and 13:30 on one local day fall on two UTC dates
 *   N10  the e-mail switched off by the person: nothing, and no claim
 *   N11  quiet hours hold NOTHING: a person inside theirs is told at once
 *        (the owner's decision of 13 October 2026; the first build held them)
 *   N12  a second run that starts in the middle of the first; and a run
 *        whose own earlier read has gone stale
 *   N13  a sender that throws; a deadline that has passed, that passes
 *        during a send, and that a slow push call uses up
 *   N14  a refused send; a message the mail queue keeps, counted apart when
 *        no mail job is running to send it
 *   N15  the push: who, how often, what it says, where it leads, and the
 *        two numbers (people addressed; devices the push service took it for)
 *   N16  an administrator demoted between two runs, during one, and at the
 *        very moment their push is claimed
 *   N17  nothing that is logged names anybody or anything
 *   N18  the table not there yet (new files, the upgrade not run)
 *   N19  the migration: safe to run twice, the same table as the install
 *        script's, and the right collation on a database made with none
 *   N20  an installation with ONE organisation
 *   N21  the real senders, with neither mail nor push set up
 *   N22  no address to give: the link is left out, never guessed
 *   N23  more events than one message lists
 *   N24  a title with characters that mean something in HTML
 *   N25  organisations told apart by their own address
 *   N26  THE PUSH GOES BEFORE THE E-MAILS, and a claim is always followed by
 *        its call: a fault during the e-mails, and a fault during the push
 *        claims themselves, lose nobody's push; whoever was not reached is
 *        told by the next run; the fault's own words are not logged
 *   N27  the job's own organisation is the current one again after an
 *        ordinary run, a run with a fault and a run cut by its deadline; and
 *        the organisation in hand is the current one during every send
 *   N28  with no moment handed in, on a database connection whose time zone
 *        is NOT UTC: the day claimed is the organisation's true day, and
 *        the two "moment in UTC" columns are written in UTC
 *
 * N11 as first built was eight checks of the quiet-hours hold. N26 to N28
 * were added after the independent check of 13 October 2026, which planted
 * five faults that every test here passed; each now fails one of them.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - A real mail service or push service: the two real senders are one line
 *   each in ImportNotifier, checked by reading and by
 *   tools/audit-checks/check_static_calls.php. What the mail queue then does
 *   with a kept message is tools/mail-queue-selftest.php's subject.
 * - A real browser opening the push, or the service worker.
 * - The real dashboard PAGE: tools/access-pages-selftest.php reads that.
 * - The real scheduled job's output: tools/feed-importer-selftest.php runs
 *   the job and reads its lines.
 * - Two runs in two processes at exactly the same moment. N12 starts a
 *   second run inside the first, which is the same order of statements.
 * - MariaDB. Run on MySQL 8.0.36 and 8.4.11.
 * - Which of the two 01:30s a stored event time means on the night the
 *   clocks go back. Nothing stored says, and the message does not pretend to.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Give it a database of its own, built from
 *   `web/_sql/full_schema.sql`: it refuses a database that holds a waiting
 *   event that is not its own, because the class works through every
 *   organisation it finds.
 * - Every row it makes is numbered 930000 to 930999 or belongs to one of its
 *   four organisations. They are removed at the START (in case an earlier
 *   run was interrupted) and at the END. Two installation-wide settings are
 *   changed for the run and put back (`site.url`, and the first
 *   organisation's own `approvals.enabled`).
 * - N18 renames the notices table for a moment and N19 drops and re-creates
 *   it with the migration; both put it back, the clean-up too.
 * - N16 and N26 each put a TRIGGER on the notices table for a moment
 *   (`selftest_notifier_hook`), to make something happen at one exact
 *   statement of the pass. So the database account must be allowed to make
 *   a trigger (the root account of a throwaway server is). N14 writes "the
 *   mail job ran just now" into the mail route's row, and N28 changes THIS
 *   connection's time zone. All are put back, by the clean-up too.
 * - It never loads `bootstrap.php` and never reads `web/_auth_keys`.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_notifier SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/import-notifier-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default root).
 * Exit:   0 only when every check ran and none failed; 1 otherwise, including
 *         every refusal to run. A check that could not be made prints SKIPPED
 *         and is counted apart: it is NOT a pass, and the last line says so.
 *
 * @package   Portal\Tools
 * @author    Salem874 (MWBM Partners Ltd, t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🧯 Anything thrown outside a check becomes a plain FAIL line and exit 1,
//    not a stack trace. Registered before any class file is loaded.
set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    exit(1);
});

/** How many checks a full run makes (passed + failed + skipped). A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 134;

// =============================================================================
// 🛑 Refuse anything but a throwaway database — BEFORE any class is loaded
// =============================================================================
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test writes rows, so it only ever runs on a throwaway database. Nothing was checked.\n";
    exit(1);
}

// The classes, one by one, never through `bootstrap.php`: that reads the real
// `web/_auth_keys/` and would connect this test to whatever database the
// developer's own portal is pointed at. The list is everything
// ImportNotifier::run() can reach. A missing one is a loud "class not found".
// (`Sabbath.php` is here for THIS FILE only: N11 uses it to prove that the
// moment it tries really is inside a person's quiet hours. The class under
// test no longer reaches for it.)
$repoDir = dirname(__DIR__);
$coreDir = $repoDir . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
// AppRegistry reads the app definitions from this folder.
define('PORTAL_CORE', rtrim($coreDir, DIRECTORY_SEPARATOR));
foreach (['App.php', 'Settings.php', 'Site.php', 'ErrorMonitor.php', 'Logger.php', 'AppRegistry.php', 'EventVisibility.php',
    'FeedResolver.php', 'ExternalAudience.php', 'FeedAdmin.php', 'PortalAddress.php', 'Sabbath.php', 'WebPush.php',
    'MailQueue.php', 'MailRoutes.php', 'Mailer.php', 'ImportNotifier.php'] as $classFile) {
    require_once $coreDir . $classFile;
}

use Portal\Core\App;
use Portal\Core\EventVisibility;
use Portal\Core\FeedAdmin;
use Portal\Core\ImportNotifier;
use Portal\Core\Mailer;
use Portal\Core\MailQueue;
use Portal\Core\PortalAddress;
use Portal\Core\Sabbath;
use Portal\Core\Site;
use Portal\Core\WebPush;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** One connection to the throwaway database. N12 opens a second, to stand for another run of the job. */
function connect(): mysqli
{
    global $dbName;
    $link = new mysqli(
        getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
        getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
        getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
        $dbName,
        getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
    );
    $link->set_charset('utf8mb4');

    return $link;
}
// `$mysqli` is a global on purpose: `App::db()` and the logger fall back to it.
$mysqli = connect();

// =============================================================================
// 🧾 Counting, and small tools
// =============================================================================
$pass = 0;
$fail = 0;
$skip = 0;
/** @var list<string> What could not be proved in this run, for the last line. */
$notProven = [];

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
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . mb_substr($detail, 0, 1800)) . "\n";
}

/** A check that could NOT be made. Not a pass: counted apart and named in the last line. */
function skipped(string $label, string $why): void
{
    global $skip, $notProven;
    $skip++;
    $notProven[] = $label;
    echo 'SKIPPED — ' . $label . "\n        " . $why . " THIS IS NOT A PASS.\n";
}

function head(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

/** @param list<mixed> $params @return list<array<string,mixed>> */
function q(string $sql, string $types = '', array $params = []): array
{
    global $mysqli;
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** @param list<mixed> $params @return array<string,mixed>|null */
function one(string $sql, string $types = '', array $params = []): ?array
{
    return q($sql, $types, $params)[0] ?? null;
}

/** Does the text hold any of these? Returns the ones found. @param list<string> $needles @return list<string> */
function found(string $text, array $needles): array
{
    return array_values(array_filter($needles, static fn (string $needle): bool => $needle !== '' && str_contains($text, $needle) === true));
}

// =============================================================================
// 🗺️ The made-up world
// =============================================================================
const ORG_A   = 930001;
const ORG_B   = 930002;
const ORG_C   = 930003;
const ORG_D   = 930004;
const ORG_ONE = 1;        // the installation's own first organisation
const KEY_A   = 'selftest-notify-a';
const KEY_B   = 'selftest-notify-b';
const KEY_C   = 'selftest-notify-c';
const KEY_D   = 'selftest-notify-d';
const NAME_A  = 'Alpha Church ZQXOA';
const NAME_B  = 'Beta Chapel ZQXOB';
const NAME_C  = 'Gamma Hall ZQXOC';
const NAME_D  = 'Delta Closed ZQXOD';
const ZONE_A  = 'Europe/London';
const ZONE_B  = 'America/Santiago';
const ZONE_C  = 'Pacific/Auckland';

/**
 * 🕰️ The moment the clock-change checks (N8) count forward from. It is a
 * FIXED DATE, not today, and that is the whole point.
 *
 * It used to be `time()`. That made N8 ask for two clock changes in the
 * twelve months from whenever the test happened to run — and on
 * 28 October 2026 the window 28 Oct 2026 → 28 Oct 2027 held exactly ONE
 * London change (28 March 2027), because the next one (31 October 2027)
 * fell three days past the edge. Four checks went red, with nothing wrong
 * anywhere in the code, and they would have stayed red until late March
 * 2027 (#652). A check that cries wolf gets switched off, and then it
 * catches nothing.
 *
 * A twelve-month window from 1 January 2027 holds two changes in each of
 * the three zones. Named here with their real dates, so the next person
 * does not have to work them out — and so that a change to PHP's time-zone
 * data that moved any of them would be visible as a changed comment rather
 * than a mysterious failure:
 *
 *   Europe/London      Sun 28 Mar 2027 02:00 forward  ·  Sun 31 Oct 2027 01:00 back
 *   America/Santiago   Sat  3 Apr 2027 23:00 back     ·  Sun  5 Sep 2027 01:00 forward
 *   Pacific/Auckland   Sun  4 Apr 2027 02:00 back     ·  Sun 26 Sep 2027 03:00 forward
 *
 * Santiago is deliberately in the list: its change falls at 23:00 on a
 * SATURDAY, so the repeated hour is the last hour of the day rather than
 * one in the small hours, and the test exercises both shapes.
 *
 * Nothing about these checks needs the changes to be in the FUTURE —
 * `ImportNotifier::run()` is handed the moment to pretend it is
 * (`$nowUtc`), so the whole of N8 happens in 2027 whatever today's date
 * is. When this date is eventually in the past it is still a correct test
 * of clock-change behaviour; move it only if PHP's data for 2027 changes.
 */
const CHANGE_NIGHT_ANCHOR = '2027-01-01 00:00:00';

const A_ADM1   = 930101; // a site administrator of A
const A_ADM2   = 930102; // a site root administrator of A
const A_GLOB   = 930103; // a global administrator who is a member of A
const A_MEM    = 930104; // a plain member of A
const A_OLD    = 930105; // a member of A with ONLY the old portal-wide "administrator" flag
const A_OFF    = 930106; // a site administrator of A whose ACCOUNT is switched off
const A_ROWOFF = 930107; // a site administrator of A whose MEMBERSHIP ROW is switched off
const B_ADM    = 930111; // a site administrator of B
const B_MEM    = 930112; // a member of B
const G1       = 930121; // a global administrator, member of nothing
const G2       = 930122; // another
const G_OFF    = 930123; // a global administrator whose account is switched off
const C_MEM    = 930131; // a member of C
const C_ADM    = 930132; // made a site administrator of C part way through N3
const C_NOADDR = 930133; // a site administrator of C with nothing usable as an address (N3)
const D_ADM    = 930141; // a site administrator of D, the switched-off organisation
const ONE_ADM  = 930151; // a site administrator of the first organisation (N20)

const FEED_A1  = 930201; // A, switched on
const FEED_A2  = 930202; // A, switched on
const FEED_A3  = 930203; // A, PAUSED
const FEED_B1  = 930211;
const FEED_C1  = 930221;
const FEED_D1  = 930231;
const FEED_ONE = 930251;
const CAL_A1   = 'Youth diary ZQXCA1';
const CAL_A2   = 'Hall bookings ZQXCA2';
const CAL_A3   = 'Paused calendar ZQXCA3';
const CAL_B1   = 'Beta diary ZQXCB1';
const CAL_C1   = 'Gamma diary ZQXCC1';
const CAL_D1   = 'Delta diary ZQXCD1';
const CAL_ONE  = 'First diary ZQXCONE';

/** The address the test gives the portal. `.invalid` can never be a real host (RFC 2606). */
const PORTAL_URL = 'https://portal.selftest.invalid';

/** Every person's name and address in the world, by account. The names and addresses must never be logged. */
const PEOPLE = [
    A_ADM1   => ['Zebedee Quillfeather ZQXP01', 'a.adm1@notify.selftest.invalid'],
    A_ADM2   => ['Ottoline Marshwren ZQXP02', 'a.adm2@notify.selftest.invalid'],
    A_GLOB   => ['Ignatius Thornbury ZQXP03', 'a.glob@notify.selftest.invalid'],
    A_MEM    => ['Mabel Ordinary ZQXP04', 'a.mem@notify.selftest.invalid'],
    A_OLD    => ['Oswald Oldflag ZQXP05', 'a.old@notify.selftest.invalid'],
    A_OFF    => ['Septimus Switchedoff ZQXP06', 'a.off@notify.selftest.invalid'],
    A_ROWOFF => ['Rowena Rowoff ZQXP07', 'a.rowoff@notify.selftest.invalid'],
    B_ADM    => ['Benedicta Beta ZQXP11', 'b.adm@notify.selftest.invalid'],
    B_MEM    => ['Basil Betamember ZQXP12', 'b.mem@notify.selftest.invalid'],
    G1       => ['Gwendolyn Globalone ZQXP21', 'g1@notify.selftest.invalid'],
    G2       => ['Gideon Globaltwo ZQXP22', 'g2@notify.selftest.invalid'],
    G_OFF    => ['Gertrude Globaloff ZQXP23', 'g.off@notify.selftest.invalid'],
    C_MEM    => ['Clementine Gamma ZQXP31', 'c.mem@notify.selftest.invalid'],
    D_ADM    => ['Dorothea Delta ZQXP41', 'd.adm@notify.selftest.invalid'],
    ONE_ADM  => ['Ophelia Firstorg ZQXP51', 'one.adm@notify.selftest.invalid'],
];

/** The address of one account. */
function mailOf(int $userId): string
{
    return PEOPLE[$userId][1];
}

$state = ['built' => false, 'cleaned' => false, 'siteUrl' => null, 'oneApprovals' => null, 'firstErrorId' => 0, 'firstActivityId' => 0,
    'mailJobRow' => false, 'mailJobRan' => null];

/** The one trigger this file ever makes, put on the notices table for a moment by N16 and N26. */
const HOOK = 'selftest_notifier_hook';

/**
 * Make something happen at ONE exact statement of the pass: a trigger that
 * runs before each change to a row of the notices table. `$body` is what it
 * does. It replaces any hook already there.
 */
function hook(string $body): void
{
    global $mysqli;
    $mysqli->query('DROP TRIGGER IF EXISTS ' . HOOK);
    $mysqli->query('CREATE TRIGGER ' . HOOK . ' BEFORE UPDATE ON tblExternalApprovalNotices FOR EACH ROW BEGIN ' . $body . ' END');
}

/** Take the hook away again. */
function unhook(): void
{
    global $mysqli;
    $mysqli->query('DROP TRIGGER IF EXISTS ' . HOOK);
}

/** Say whether the mail queue's scheduled job "ran just now" (true) or has never run (false), as the portal reads it. */
function mailJobRuns(bool $runs): void
{
    // Only the one column is touched: the rest of the row is the mail
    // route's own record of what is wrong with it, which is not this test's.
    if ($runs === true) {
        q("INSERT INTO tblMailRouteState (routeKey, jobLastRanAt) VALUES ('installation', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE jobLastRanAt = UTC_TIMESTAMP()");
    } else {
        q("UPDATE tblMailRouteState SET jobLastRanAt = NULL WHERE routeKey = 'installation'");
    }
    MailQueue::forget(); // the answer is read once a request; this is a new one
}

/** Take everything this test made out of the database, and put back what it changed. Safe to run when nothing was made. */
function removeWorld(): void
{
    global $mysqli;
    // N28 changes this connection's time zone, and N16 and N26 put a trigger
    // on the notices table. Both go first, so that nothing below is done on
    // the wrong clock or refused by a hook left behind.
    $mysqli->query('SET time_zone = @@global.time_zone');
    $mysqli->query('DROP TRIGGER IF EXISTS ' . HOOK);
    // N18 and N19 move or drop the notices table; put it back first.
    $hidden = $mysqli->query("SHOW TABLES LIKE 'tblExternalApprovalNoticesHidden'");
    if ($hidden->num_rows > 0) {
        $mysqli->query('DROP TABLE IF EXISTS tblExternalApprovalNotices');
        $mysqli->query('RENAME TABLE tblExternalApprovalNoticesHidden TO tblExternalApprovalNotices');
    }
    $hidden->free();
    $there = $mysqli->query("SHOW TABLES LIKE 'tblExternalApprovalNotices'");
    if ($there->num_rows === 0) {
        runMigration();
    }
    $there->free();

    q('DELETE FROM tblExternalApprovalNotices WHERE siteID IN (?, ?, ?, ?) OR userID BETWEEN 930000 AND 930999', 'iiii', [ORG_A, ORG_B, ORG_C, ORG_D]);
    q('DELETE FROM tblExternalEventApprovals WHERE approvalID BETWEEN 930000 AND 939999 OR siteID IN (?, ?, ?, ?)', 'iiii', [ORG_A, ORG_B, ORG_C, ORG_D]);
    q('DELETE FROM tblEvents WHERE eventID BETWEEN 930000 AND 939999 OR siteID IN (?, ?, ?, ?)', 'iiii', [ORG_A, ORG_B, ORG_C, ORG_D]);
    q('DELETE FROM tblExternalFeeds WHERE feedID BETWEEN 930000 AND 930999 OR siteID IN (?, ?, ?, ?)', 'iiii', [ORG_A, ORG_B, ORG_C, ORG_D]);
    q('DELETE FROM tblErrors WHERE userID BETWEEN 930000 AND 930999 OR siteID IN (?, ?, ?, ?)', 'iiii', [ORG_A, ORG_B, ORG_C, ORG_D]);
    q("DELETE FROM tblErrors WHERE errorPlatform = 'ImportNotifier'");
    q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 930000 AND 930999 OR siteID IN (?, ?, ?, ?)', 'iiii', [ORG_A, ORG_B, ORG_C, ORG_D]);
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 930000 AND 930999 OR siteID IN (?, ?, ?, ?)', 'iiii', [ORG_A, ORG_B, ORG_C, ORG_D]);
    q('DELETE FROM tblUsers WHERE userID BETWEEN 930000 AND 930999');
    q('DELETE FROM tblSettings WHERE siteID IN (?, ?, ?, ?)', 'iiii', [ORG_A, ORG_B, ORG_C, ORG_D]);
    q('DELETE FROM tblSites WHERE siteID IN (?, ?, ?, ?)', 'iiii', [ORG_A, ORG_B, ORG_C, ORG_D]);
}

/** Run migration 218 from THIS tree's own file. */
function runMigration(): void
{
    global $mysqli, $repoDir;
    $file = $repoDir . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_sql' . DIRECTORY_SEPARATOR . '218_external_approval_notices.sql';
    $sql  = (string) file_get_contents($file);
    if ($mysqli->multi_query($sql) === true) {
        do {
            $result = $mysqli->store_result();
            if (($result instanceof mysqli_result) === true) {
                $result->free();
            }
        } while ($mysqli->more_results() === true && $mysqli->next_result() === true);
    }
}

/** Put back the two settings changed outside the made-up organisations, ONCE. Also a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['built'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    removeWorld();
    if ($state['siteUrl'] === null) {
        q("DELETE FROM tblSettings WHERE settingKey = 'site.url' AND siteID IS NULL");
    } else {
        q("UPDATE tblSettings SET settingValue = ? WHERE settingKey = 'site.url' AND siteID IS NULL", 's', [(string) $state['siteUrl']]);
    }
    // The mail route's row, as it was before N14 wrote "the job ran just now".
    if ($state['mailJobRow'] === true) {
        q("UPDATE tblMailRouteState SET jobLastRanAt = ? WHERE routeKey = 'installation'", 's', [$state['mailJobRan']]);
    } else {
        q("DELETE FROM tblMailRouteState WHERE routeKey = 'installation'");
    }
    MailQueue::forget();
    q("DELETE FROM tblSettings WHERE settingKey = 'approvals.enabled' AND siteID = ?", 'i', [ORG_ONE]);
    if ($state['oneApprovals'] !== null) {
        q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, 'approvals.enabled', ?, ?, 0)", 'iss', [ORG_ONE, (string) $state['oneApprovals'], (string) $state['oneApprovals']]);
    }
}
register_shutdown_function('cleanup');

/** One organisation's own value for a setting (replacing any it had). */
function siteSetting(int $site, string $key, string $value): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss', [$site, $key, $value, $value]);
}

/** The installation's `site.url` row: the address every link is built on. '' means "none set". */
function portalAddressSetting(string $value): void
{
    if (one("SELECT settingID FROM tblSettings WHERE settingKey = 'site.url' AND siteID IS NULL") === null) {
        q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'site.url', ?, '', 0)", 's', [$value]);
    } else {
        q("UPDATE tblSettings SET settingValue = ? WHERE settingKey = 'site.url' AND siteID IS NULL", 's', [$value]);
    }
    PortalAddress::forget();
}

$nextEvent    = 930300;
$nextApproval = 930400;

/**
 * One event of an outside calendar, with an approval row. `$status` is the
 * approval's: 'pending' means waiting. The row's number rises with every
 * call, as it does in the portal, so "newer" means "made later".
 *
 * @return int The approval's number.
 */
function waitingEvent(int $site, int $feed, string $title, string $wallStart, string $zone, bool $allDay = false, string $status = 'pending'): int
{
    global $nextEvent, $nextApproval;
    $eventId    = $nextEvent++;
    $approvalId = $nextApproval++;
    q(
        'INSERT INTO tblEvents (eventID, siteID, externalFeedID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
        . "VALUES (?, ?, ?, ?, ?, 'A description ZQXDESC', ?, ?, ?, 'published', 0, 0)",
        'iiisssss', [$eventId, $site, $feed, 'nw-' . $eventId, $title, $wallStart, $wallStart, $zone]
    );
    q(
        'INSERT INTO tblExternalEventApprovals (approvalID, siteID, feedID, eventID, origin, originID, requestHash, contentHash, status, reason, '
        . 'requestedLevel, requestedDetail, requestedWebsite, requestedAudienceSummary, snapTitle, snapStart, snapEnd, snapTimezone, snapIsAllDay, '
        . 'snapDescription, snapLocation, snapUrl, createdAt) '
        . "VALUES (?, ?, ?, ?, 'rule', 1, ?, ?, ?, 'new_match', 'public', 'full', 0, '', ?, ?, ?, ?, ?, 'A description ZQXDESC', 'A place ZQXPLACE', "
        . "'https://calendar.selftest.invalid/event-ZQXURL', UTC_TIMESTAMP())",
        'iiiisssssssi',
        [$approvalId, $site, $feed, $eventId, hash('sha256', 'request-' . $approvalId), hash('sha256', 'content-' . $approvalId), $status, $title, $wallStart, $wallStart, $zone, $allDay === true ? 1 : 0]
    );

    return $approvalId;
}

/** Remove waiting events by their approval numbers (the event goes too). @param list<int> $approvalIds */
function removeWaiting(array $approvalIds): void
{
    foreach ($approvalIds as $approvalId) {
        $row = one('SELECT eventID FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [$approvalId]);
        if ($row !== null) {
            q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [(int) $row['eventID']]); // the approval row cascades
        }
    }
}

/** Forget who has been told, for these organisations: the next run starts as if nobody ever was. @param list<int> $sites */
function forgetTold(array $sites = [ORG_A, ORG_B, ORG_C, ORG_D, ORG_ONE]): void
{
    foreach ($sites as $site) {
        q('DELETE FROM tblExternalApprovalNotices WHERE siteID = ?', 'i', [$site]);
    }
}

/** One person's row in the notices table, or null. @return array<string,mixed>|null */
function told(int $site, int $user): ?array
{
    return one('SELECT emailLocalDate, emailOutcome, pushUpToApprovalID FROM tblExternalApprovalNotices WHERE siteID = ? AND userID = ?', 'ii', [$site, $user]);
}

// =============================================================================
// 🕰️ Moments, worked out from today
// =============================================================================

/** A time on a clock in a zone, as a moment. */
function at(string $zone, string $localText): DateTimeImmutable
{
    return (new DateTimeImmutable($localText, new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'));
}

/** A calendar date some days from another, by the calendar alone (no zone, no clock). */
function dayFrom(string $date, int $days): string
{
    return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
}

/**
 * The clock changes of one zone in the twelve months after `$from`, from
 * PHP's own time-zone data. Each is the moment of the change and the offset
 * from UTC before and after it, in seconds.
 *
 * `$from` defaults to NOW, which is what `ordinaryDay()` wants: it is
 * looking for a day in the near future that is nowhere near a change.
 * N8 passes `CHANGE_NIGHT_ANCHOR` instead, because a test about clock
 * changes must not depend on which side of one today happens to fall —
 * see the comment on that constant (#652).
 *
 * @return list<array{ts:int, before:int, after:int}>
 */
function clockChanges(string $zone, ?int $from = null): array
{
    $from  = $from ?? time();
    $steps = (new DateTimeZone($zone))->getTransitions($from, $from + 366 * 86400);
    $out   = [];
    // The first entry is the state at `$from`, not a change.
    for ($i = 1, $n = is_array($steps) === true ? count($steps) : 0; $i < $n; $i++) {
        if ((int) $steps[$i]['offset'] !== (int) $steps[$i - 1]['offset']) {
            $out[] = ['ts' => (int) $steps[$i]['ts'], 'before' => (int) $steps[$i - 1]['offset'], 'after' => (int) $steps[$i]['offset']];
        }
    }

    return $out;
}

/**
 * An ordinary day: the first Wednesday at least three days ahead that is at
 * least five days from every clock change in the three zones. (A Wednesday,
 * so that it is nowhere near the weekend's quiet hours either.)
 */
function ordinaryDay(): string
{
    $changes = array_merge(clockChanges(ZONE_A), clockChanges(ZONE_B), clockChanges(ZONE_C));
    $day     = dayFrom(gmdate('Y-m-d'), 3);
    for ($i = 0; $i < 400; $i++, $day = dayFrom($day, 1)) {
        $noon = (new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('UTC')));
        if ($noon->format('N') !== '3') {
            continue;
        }
        $near = false;
        foreach ($changes as $change) {
            if (abs($change['ts'] - $noon->getTimestamp()) < 5 * 86400) {
                $near = true;
            }
        }
        if ($near === false) {
            return $day;
        }
    }
    throw new RuntimeException('no ordinary Wednesday was found in the next 400 days');
}

/** How the approvals list writes a stored clock time, with the zone the message adds. */
function shownTime(string $wall, string $zone): string
{
    return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, new DateTimeZone('UTC'))->format('D j M Y, H:i') . ' (' . $zone . ')';
}

// =============================================================================
// 🎬 One "request", and one run
// =============================================================================

/**
 * Start the portal's own classes as a request would find them. The job has
 * no organisation of its own in its address, so the current one is the
 * first organisation and there is no address part.
 *
 * @param array{multi?:string, mode?:string, sabbath?:array<string,string>} $o
 */
function portal(array $o = []): void
{
    global $mysqli;
    $mark     = $mysqli->query("SELECT settingValue FROM tblSettings WHERE settingKey = 'site.timezoneKeptAt' AND siteID IS NULL")->fetch_row();
    $settings = [
        'site'      => ['timezoneKeptAt' => (string) ($mark[0] ?? '')],
        'multisite' => ['enabled' => $o['multi'] ?? 'true', 'detectionMode' => $o['mode'] ?? 'path'],
        'portal'    => ['sabbath' => $o['sabbath'] ?? ['enabled' => '0']],
    ];
    $GLOBALS['SETTINGS'] = $settings;
    App::init($mysqli, $settings);
    Site::reset();
    Site::init($mysqli, $settings, ORG_ONE);
    PortalAddress::forget();
}

/** @var list<array{to:string, subject:string, html:string, options:array<string,mixed>}> Every message any run of this test would have sent. */
$allMail = [];
/** @var list<array<string,mixed>> Every push call any run of this test would have made. */
$allPush = [];

/**
 * One run of the REAL pass, with recorders in place of the two senders.
 *
 * @param DateTimeImmutable|null $nowUtc  The moment to run it at. Null hands the pass no moment, so that it reads its own clock (N28).
 * @param callable|null          $answer  What the mail recorder answers for one message (to, subject): a Mailer::OUTCOME_ value. It may throw.
 * @param callable|null          $during  Called while each message is "being sent" (to, subject), before the answer.
 * @param array{pushDuring?:callable, pushAnswer?:callable} $o
 *        `pushDuring` is called while a push is "being sent" (organisation, accounts); `pushAnswer` gives what the push
 *        sender answers (organisation, accounts), in WebPush's own shape. Left out, every push is "taken".
 *
 * @return array{out:array<string,mixed>, mail:list<array<string,mixed>>, push:list<array<string,mixed>>, threw:string,
 *               order:list<string>, siteAfter:int}
 *         Each message and push also records `siteNow`, the organisation that was the current one while it was being
 *         sent. `order` is what was sent, in the order it happened: "push <organisation>" or "mail <address>".
 *         `siteAfter` is the current organisation once the run has ended.
 */
function run(?DateTimeImmutable $nowUtc, ?callable $answer = null, ?callable $during = null, ?float $deadline = null, array $o = []): array
{
    global $mysqli, $allMail, $allPush;
    $mail  = [];
    $push  = [];
    $order = [];
    $mailer = static function (string $to, string $subject, string $html, array $options) use (&$mail, &$allMail, &$order, $answer, $during): string {
        $entry     = ['to' => $to, 'subject' => $subject, 'html' => $html, 'options' => $options, 'siteNow' => Site::id()];
        $mail[]    = $entry;
        $allMail[] = $entry;
        $order[]   = 'mail ' . $to;
        if ($during !== null) {
            $during($to, $subject);
        }

        return $answer !== null ? (string) $answer($to, $subject) : Mailer::OUTCOME_SENT;
    };
    $pusher = static function (int $siteId, string $channel, array $payload, int $ttl, string $urgency, ?string $topic, ?string $prefKey, ?array $userIds) use (&$push, &$allPush, &$order, $o): array {
        $said      = isset($o['pushAnswer']) === true ? (array) $o['pushAnswer']($siteId, (array) $userIds) : ['sent' => count((array) $userIds), 'failed' => 0, 'pruned' => 0];
        $entry     = ['siteId' => $siteId, 'channel' => $channel, 'payload' => $payload, 'ttl' => $ttl, 'urgency' => $urgency, 'topic' => $topic, 'prefKey' => $prefKey,
            'userIds' => $userIds, 'siteNow' => Site::id(), 'said' => $said];
        $push[]    = $entry;
        $allPush[] = $entry;
        $order[]   = 'push ' . $siteId;
        if (isset($o['pushDuring']) === true) {
            $o['pushDuring']($siteId, (array) $userIds);
        }

        return $said;
    };
    $threw = '';
    $out   = [];
    try {
        $out = ImportNotifier::run($mysqli, $deadline ?? (microtime(true) + 300), $nowUtc, $mailer, $pusher);
    } catch (Throwable $e) {
        $threw = get_class($e) . ': ' . $e->getMessage();
    }

    return ['out' => $out, 'mail' => $mail, 'push' => $push, 'threw' => $threw, 'order' => $order, 'siteAfter' => Site::id()];
}

/** The messages of one run that are about one organisation (its name opens the subject). @return list<array<string,mixed>> */
function mailAbout(array $run, string $orgName): array
{
    return array_values(array_filter($run['mail'], static fn (array $m): bool => str_starts_with((string) $m['subject'], '[' . $orgName . ']') === true));
}

/** The addresses those messages went to, sorted. @return list<string> */
function sentTo(array $run, string $orgName): array
{
    $to = array_map(static fn (array $m): string => (string) $m['to'], mailAbout($run, $orgName));
    sort($to);

    return $to;
}

/** The push calls of one run for one organisation. @return list<array<string,mixed>> */
function pushFor(array $run, int $siteId): array
{
    return array_values(array_filter($run['push'], static fn (array $p): bool => (int) $p['siteId'] === $siteId));
}

/** Sorted addresses of these accounts. @param list<int> $users @return list<string> */
function addresses(array $users): array
{
    $to = array_map('mailOf', $users);
    sort($to);

    return $to;
}

/** Sorted account numbers. @param list<int>|null $ids @return list<int> */
function sorted(?array $ids): array
{
    $ids = array_map('intval', (array) $ids);
    sort($ids);

    return $ids;
}

// =============================================================================
// 🏗️ Build the world
// =============================================================================
removeWorld();

$foreign = (int) (one("SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE status = 'pending'")['n'] ?? 0);
if ($foreign > 0) {
    echo "REFUSED — this database already holds {$foreign} waiting event(s) that are not this test's. The class works through every organisation it finds,\n";
    echo "so the test needs a database of its own, built from web/_sql/full_schema.sql. Nothing was checked.\n";
    exit(1);
}
portal();
if (Site::timezoneFacts()['inRow'] === false) {
    echo "REFUSED — this database does not hold migration 217's mark, so an organisation's zone would be read from the old setting. Build it from web/_sql/full_schema.sql. Nothing was checked.\n";
    exit(1);
}

$state['built']           = true;
$state['siteUrl']         = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'site.url' AND siteID IS NULL")['settingValue'] ?? null;
$state['oneApprovals']    = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'approvals.enabled' AND siteID = ?", 'i', [ORG_ONE])['settingValue'] ?? null;
$state['firstErrorId']    = (int) (one('SELECT COALESCE(MAX(errorID), 0) AS n FROM tblErrors')['n'] ?? 0);
$state['firstActivityId'] = (int) (one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'] ?? 0);
$globalsBefore            = array_map('intval', array_column(q('SELECT userID FROM tblUsers WHERE isActive = 1 AND isRootAdmin = 1'), 'userID'));
$mailJobBefore            = one("SELECT jobLastRanAt FROM tblMailRouteState WHERE routeKey = 'installation'");
$state['mailJobRow']      = $mailJobBefore !== null;
$state['mailJobRan']      = $mailJobBefore['jobLastRanAt'] ?? null;

q(
    'INSERT INTO tblSites (siteID, siteKey, siteName, timezone, isActive) VALUES (?, ?, ?, ?, 1), (?, ?, ?, ?, 1), (?, ?, ?, ?, 1), (?, ?, ?, ?, 0)',
    'isssisssisssisss',
    [ORG_A, KEY_A, NAME_A, ZONE_A, ORG_B, KEY_B, NAME_B, ZONE_B, ORG_C, KEY_C, NAME_C, ZONE_C, ORG_D, KEY_D, NAME_D, ZONE_A]
);
foreach (PEOPLE as $userId => [$name, $address]) {
    q(
        'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, ?, ?, ?)',
        'issiii',
        [$userId, $name, $address, in_array($userId, [A_OFF, G_OFF], true) === true ? 0 : 1, $userId === A_OLD ? 1 : 0, in_array($userId, [A_GLOB, G1, G2, G_OFF], true) === true ? 1 : 0]
    );
}
// [account, organisation, row switched on, site administrator, site root administrator]
foreach ([
    [A_ADM1, ORG_A, 1, 1, 0], [A_ADM2, ORG_A, 1, 0, 1], [A_GLOB, ORG_A, 1, 0, 0], [A_MEM, ORG_A, 1, 0, 0], [A_OLD, ORG_A, 1, 0, 0],
    [A_OFF, ORG_A, 1, 1, 0], [A_ROWOFF, ORG_A, 0, 1, 0], [B_ADM, ORG_B, 1, 1, 0], [B_MEM, ORG_B, 1, 0, 0], [C_MEM, ORG_C, 1, 0, 0],
    [D_ADM, ORG_D, 1, 1, 0], [ONE_ADM, ORG_ONE, 1, 1, 0],
] as [$userId, $site, $rowOn, $siteAdmin, $siteRoot]) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, ?, ?, ?)', 'iiiii', [$userId, $site, $rowOn, $siteAdmin, $siteRoot]);
}
foreach ([
    [FEED_A1, ORG_A, CAL_A1, 1], [FEED_A2, ORG_A, CAL_A2, 1], [FEED_A3, ORG_A, CAL_A3, 0], [FEED_B1, ORG_B, CAL_B1, 1],
    [FEED_C1, ORG_C, CAL_C1, 1], [FEED_D1, ORG_D, CAL_D1, 1], [FEED_ONE, ORG_ONE, CAL_ONE, 1],
] as [$feedId, $site, $name, $on]) {
    q(
        'INSERT INTO tblExternalFeeds (feedID, siteID, name, url, isActive) VALUES (?, ?, ?, ?, ?)',
        'iissi', [$feedId, $site, $name, 'https://calendar.selftest.invalid/secret-ZQXURL-' . $feedId . '.ics', $on]
    );
}
// The Approvals app: on for A, B, C and the first organisation.
foreach ([ORG_A, ORG_B, ORG_C, ORG_D, ORG_ONE] as $site) {
    siteSetting($site, 'approvals.enabled', '1');
}
portalAddressSetting(PORTAL_URL);

$plain = ordinaryDay();
// A's three waiting events, on switched-on calendars. One with a time in the
// organisation's own zone, one for a whole day, one in ANOTHER zone.
$wallT1 = dayFrom($plain, 10) . ' 19:30:00';
$wallT2 = dayFrom($plain, 11) . ' 00:00:00';
$wallT3 = dayFrom($plain, 12) . ' 20:00:00';
$apprT1 = waitingEvent(ORG_A, FEED_A1, 'Trustees closed session ZQXTA1', $wallT1, ZONE_A);
$apprT2 = waitingEvent(ORG_A, FEED_A1, 'Youth weekend ZQXTA2', $wallT2, ZONE_A, true);
$apprT3 = waitingEvent(ORG_A, FEED_A2, 'Hall hire ZQXTA3', $wallT3, ZONE_B);
// What must NOT be counted or shown: a paused calendar's event, and two decided ones.
waitingEvent(ORG_A, FEED_A3, 'Paused thing ZQXTPAUSED', $wallT1, ZONE_A);
waitingEvent(ORG_A, FEED_A1, 'Decided thing ZQXTDECIDED one', $wallT1, ZONE_A, false, 'approved');
waitingEvent(ORG_A, FEED_A1, 'Decided thing ZQXTDECIDED two', $wallT1, ZONE_A, false, 'declined');
$apprB1 = waitingEvent(ORG_B, FEED_B1, 'Beta secret ZQXTB1', $wallT1, ZONE_B);
$apprB2 = waitingEvent(ORG_B, FEED_B1, 'Beta secret ZQXTB2', $wallT1, ZONE_B);
$apprC1 = waitingEvent(ORG_C, FEED_C1, 'Gamma thing ZQXTC1', $wallT1, ZONE_C);
waitingEvent(ORG_D, FEED_D1, 'Delta thing ZQXTD1', $wallT1, ZONE_A);

/** Everything of the other organisations that must never appear in a message about A. */
$notAs = ['ZQXTB', 'ZQXCB', 'ZQXOB', KEY_B, 'ZQXTC', 'ZQXCC', 'ZQXOC', KEY_C, 'ZQXTD', 'ZQXCD', 'ZQXOD', KEY_D, 'ZQXTONE', 'ZQXCONE'];
/** What must never appear in any message: a paused calendar and its event, a decided event, a place, a description, an outside address. */
$neverShown = ['ZQXTPAUSED', 'ZQXCA3', 'ZQXTDECIDED', 'ZQXPLACE', 'ZQXDESC', 'ZQXURL', 'calendar.selftest.invalid'];
/** What must never be in a log line, an error row, an activity row or a push. */
//  Every made-up name in this file carries one of these marks (ZQXT a title,
//  ZQXC a calendar, ZQXO an organisation, ZQXP a person), so one search finds
//  any of them wherever it turns up.
$neverLogged = ['@', '://', 'ZQXT', 'ZQXC', 'ZQXO', 'ZQXP', 'ZQXPLACE', 'ZQXDESC', 'ZQXURL'];
$adminsA = [A_ADM1, A_ADM2, A_GLOB];

echo 'The ordinary day is ' . $plain . " (worked out from today). Nothing is sent: mail and push go to recorders inside this file.\n";

// =============================================================================
head('N1. waiting(): what counts as waiting');
// =============================================================================
$waitA  = ImportNotifier::waiting($mysqli, ORG_A);
$inboxA = FeedAdmin::pendingApprovals($mysqli, ORG_A, null, FeedAdmin::MAX_BULK);
$inboxOn = count(array_filter($inboxA['rows'], static fn (array $r): bool => (int) $r['feedActive'] === 1));
ok(
    'N1 A has three waiting events on switched-on calendars; the paused calendar\'s one and the two decided ones are not counted',
    $waitA['total'] === 3 && $waitA['newestId'] === $apprT3,
    json_encode($waitA)
);
ok(
    'N1 they are given by calendar, in the list\'s own order (by name), each with its number',
    array_map(static fn (array $c): array => [$c['feedID'], $c['name'], $c['n']], $waitA['byCalendar']) === [[FEED_A2, CAL_A2, 1], [FEED_A1, CAL_A1, 2]],
    json_encode($waitA['byCalendar'])
);
ok(
    'N1 the number equals the approvals list\'s rows that are not marked paused (the list itself shows four, one of them paused)',
    $inboxOn === $waitA['total'] && (int) $inboxA['total'] === 4,
    'list rows not paused: ' . $inboxOn . '; list total: ' . $inboxA['total'] . '; waiting(): ' . $waitA['total']
);
ok(
    'N1 another organisation\'s number is its own: B two, C one, and the first organisation none',
    ImportNotifier::waiting($mysqli, ORG_B)['total'] === 2 && ImportNotifier::waiting($mysqli, ORG_C)['total'] === 1 && ImportNotifier::waiting($mysqli, ORG_ONE)['total'] === 0,
    json_encode([ImportNotifier::waiting($mysqli, ORG_B), ImportNotifier::waiting($mysqli, ORG_C), ImportNotifier::waiting($mysqli, ORG_ONE)])
);

// =============================================================================
head('N2. recipients(): exactly the organisation\'s administrators');
// =============================================================================
$idsOf = static fn (array $people): array => sorted(array_column($people, 'userID'));
$recA  = ImportNotifier::recipients($mysqli, ORG_A);
ok(
    'N2 A\'s recipients are its two site administrators and the global administrator who is a member there, and nobody else: not a member, '
    . 'not the old-flag account, not a switched-off account, not a switched-off membership row, not B\'s administrator, not a global administrator who is not a member',
    $idsOf($recA) === [A_ADM1, A_ADM2, A_GLOB],
    json_encode($idsOf($recA))
);
ok(
    'N2 each comes with their address and their preferences as stored',
    array_column($recA, 'email') === [mailOf(A_ADM1), mailOf(A_ADM2), mailOf(A_GLOB)] && array_column($recA, 'notifyPrefs') === [null, null, null],
    json_encode($recA)
);
$notAdmins = [];
$missing   = [];
$worldUsers = array_merge(array_keys(PEOPLE), [0]);
foreach ([ORG_A, ORG_B, ORG_C, ORG_D, ORG_ONE] as $site) {
    $rec = $idsOf(ImportNotifier::recipients($mysqli, $site));
    foreach ($worldUsers as $userId) {
        $gate = EventVisibility::isAdminOfSite($mysqli, $userId, $site);
        if (in_array($userId, $rec, true) === true && $gate === false) {
            $notAdmins[] = $userId . ' in ' . $site;
        }
        // The other way round, for a member: an administrator there, by the
        // list's gate, with a switched-on membership row, IS a recipient.
        $member = one('SELECT 1 AS x FROM tblUserSites WHERE userID = ? AND siteID = ? AND isActive = 1', 'ii', [$userId, $site]) !== null;
        if ($gate === true && $member === true && in_array($userId, $rec, true) === false) {
            $missing[] = $userId . ' in ' . $site;
        }
    }
}
ok(
    'N2 for every account in the world and every organisation: a recipient always passes the approvals list\'s own gate (EventVisibility::isAdminOfSite())',
    $notAdmins === [],
    'recipients the gate refuses: ' . implode(', ', $notAdmins)
);
ok('N2 and a member the gate accepts is always a recipient', $missing === [], 'administrators left out: ' . implode(', ', $missing));
ok('N2 B\'s recipients are B\'s administrator alone', $idsOf(ImportNotifier::recipients($mysqli, ORG_B)) === [B_ADM], json_encode($idsOf(ImportNotifier::recipients($mysqli, ORG_B))));

// =============================================================================
head('N3. An organisation with no administrator who can be e-mailed');
// =============================================================================
$everyGlobal = sorted(array_merge($globalsBefore, [A_GLOB, G1, G2]));
ok(
    'N3 C has no administrator of its own: its recipients are every active global administrator (the switched-off one is left out)',
    $idsOf(ImportNotifier::recipients($mysqli, ORG_C)) === $everyGlobal,
    json_encode($idsOf(ImportNotifier::recipients($mysqli, ORG_C))) . ' expected ' . json_encode($everyGlobal)
);
// An administrator of C's own with nothing usable as an address: still told
// (a push needs no address), and the global administrators are ADDED.
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [C_NOADDR, 'Nobody Noaddress ZQXP33', 'no-address-zq-930133']);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 1, 0)', 'ii', [C_NOADDR, ORG_C]);
ok(
    'N3 with an administrator of its own who has no usable address: that administrator AND every active global administrator',
    $idsOf(ImportNotifier::recipients($mysqli, ORG_C)) === sorted(array_merge($everyGlobal, [C_NOADDR])),
    json_encode($idsOf(ImportNotifier::recipients($mysqli, ORG_C)))
);
forgetTold();
$runC = run(at(ZONE_C, $plain . ' 08:10:00'));
ok(
    'N3 at 08:10 in Auckland, C\'s message goes to every global administrator with an address, once each, and to nobody else',
    sentTo($runC, NAME_C) === addresses(array_values(array_diff($everyGlobal, $globalsBefore))) && $globalsBefore === [],
    json_encode(sentTo($runC, NAME_C)) . (count($globalsBefore) > 0 ? ' (this database already held global administrators: ' . json_encode($globalsBefore) . ')' : '')
);
// Now C gets an administrator of its own who CAN be e-mailed.
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [C_ADM, 'Cordelia Gammaadmin ZQXP32', 'c.adm@notify.selftest.invalid']);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 1, 0)', 'ii', [C_ADM, ORG_C]);
ok(
    'N3 once C has an administrator who can be e-mailed, only C\'s own administrators are recipients: no global administrator',
    $idsOf(ImportNotifier::recipients($mysqli, ORG_C)) === [C_ADM, C_NOADDR],
    json_encode($idsOf(ImportNotifier::recipients($mysqli, ORG_C)))
);
forgetTold();
$runC2 = run(at(ZONE_C, $plain . ' 08:10:00'));
ok(
    'N3 and then C\'s message goes to that administrator alone',
    sentTo($runC2, NAME_C) === ['c.adm@notify.selftest.invalid'],
    json_encode(sentTo($runC2, NAME_C))
);
// Back to "no administrator of its own" for the rest of the file.
q('DELETE FROM tblUserSites WHERE userID IN (?, ?)', 'ii', [C_ADM, C_NOADDR]);
q('DELETE FROM tblExternalApprovalNotices WHERE userID IN (?, ?)', 'ii', [C_ADM, C_NOADDR]);
q('DELETE FROM tblUsers WHERE userID IN (?, ?)', 'ii', [C_ADM, C_NOADDR]);

// =============================================================================
head('N4. The dashboard card');
// =============================================================================
portal();
$cardA = ImportNotifier::dashboardCard($mysqli, A_ADM1, ORG_A);
ok(
    'N4 A\'s administrator gets the card: the number 3, the label, a warning colour, and the Approvals list as its address',
    $cardA === ['label' => 'Imported events waiting for approval', 'value' => 3, 'icon' => 'fa-solid fa-stamp', 'color' => 'warning', 'url' => '/approvals#imports'],
    json_encode($cardA)
);
$noCard = [];
foreach ([A_MEM => 'a member', A_OLD => 'the old-flag account', A_OFF => 'a switched-off administrator', A_ROWOFF => 'an administrator whose membership row is off',
    B_ADM => 'B\'s administrator', 0 => 'nobody'] as $userId => $who) {
    if (ImportNotifier::dashboardCard($mysqli, $userId, ORG_A) !== null) {
        $noCard[] = $who;
    }
}
ok('N4 nobody else gets a card for A: a member, the old-flag account, a switched-off administrator, a switched-off membership row, B\'s administrator, nobody', $noCard === [], 'given a card: ' . implode(', ', $noCard));
ok(
    'N4 B\'s administrator gets B\'s card with B\'s number (2), and A\'s administrator gets none for B',
    (ImportNotifier::dashboardCard($mysqli, B_ADM, ORG_B)['value'] ?? null) === 2 && ImportNotifier::dashboardCard($mysqli, A_ADM1, ORG_B) === null,
    json_encode([ImportNotifier::dashboardCard($mysqli, B_ADM, ORG_B), ImportNotifier::dashboardCard($mysqli, A_ADM1, ORG_B)])
);
q("UPDATE tblExternalEventApprovals SET status = 'approved' WHERE approvalID IN (?, ?, ?)", 'iii', [$apprT1, $apprT2, $apprT3]);
$cardZero = ImportNotifier::dashboardCard($mysqli, A_ADM1, ORG_A);
q("UPDATE tblExternalEventApprovals SET status = 'pending' WHERE approvalID IN (?, ?, ?)", 'iii', [$apprT1, $apprT2, $apprT3]);
ok('N4 with nothing waiting there is no card at all, not a card saying 0', $cardZero === null, json_encode($cardZero));
siteSetting(ORG_A, 'approvals.enabled', '0');
$cardOff  = ImportNotifier::dashboardCard($mysqli, A_ADM1, ORG_A);
$routeOff = ImportNotifier::inboxRoute(ORG_A);
siteSetting(ORG_A, 'approvals.enabled', '1');
ok(
    'N4 when A has the Approvals app switched off the card leads to the outside calendars page, where each calendar lists its own',
    ($cardOff['url'] ?? '') === '/admin/calendar/feeds' && $routeOff === ['admin/calendar/feeds', ''] && ImportNotifier::inboxRoute(ORG_A) === ['approvals', '#imports'],
    json_encode([$cardOff, $routeOff])
);

// =============================================================================
head('N5. The daily e-mail: who gets it, what it holds, and what it must not');
// =============================================================================
portal();
forgetTold();
$nowN5 = at(ZONE_A, $plain . ' 08:10:00');
$run5  = run($nowN5);
$mailA = mailAbout($run5, NAME_A);
ok(
    'N5 at 08:10 in London the run does not throw, and the number of e-mails it reports is the number written (A\'s three, and C\'s, where it is evening)',
    $run5['threw'] === '' && ($run5['out']['emailed'] ?? -1) === count($run5['mail']) && count($mailA) === 3 && ($run5['out']['organisations'] ?? -1) === 3,
    json_encode([$run5['threw'], $run5['out'], count($run5['mail'])])
);
ok(
    'N5 exactly one message for each of A\'s three administrators, and none for a member, the old-flag account, a switched-off administrator or a switched-off membership row',
    sentTo($run5, NAME_A) === addresses($adminsA),
    json_encode(sentTo($run5, NAME_A))
);
$subjectsOk = count($mailA) === 3;
$bodyFaults = [];
$expectLink = PORTAL_URL . '/' . KEY_A . '/approvals#imports';
$expectT1   = 'Trustees closed session ZQXTA1 &mdash; ' . shownTime($wallT1, ZONE_A);
$expectT2   = 'Youth weekend ZQXTA2 &mdash; ' . DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wallT2, new DateTimeZone('UTC'))->format('D j M Y') . ' (all day)</li>';
$expectT3   = 'Hall hire ZQXTA3 &mdash; ' . shownTime($wallT3, ZONE_B);
$madeOn     = 'This list was made on ' . $nowN5->setTimezone(new DateTimeZone(ZONE_A))->format('D j M Y, H:i T') . ' (' . ZONE_A . ' time)';
foreach ($mailA as $m) {
    if ($m['subject'] !== '[' . NAME_A . '] 3 imported events are waiting for approval') {
        $subjectsOk = false;
    }
    foreach ([
        'the number' => '3 imported events are waiting for approval at ' . NAME_A . '.',
        'calendar one with its number' => CAL_A1 . ': 2 waiting',
        'calendar two with its number' => CAL_A2 . ': 1 waiting',
        'A\'s own link' => '<a href="' . $expectLink . '">',
        'the timed event, its own clock time and its zone named' => $expectT1,
        'the whole-day event, with no clock time' => $expectT2,
        'the event in another zone, NOT moved into London time' => $expectT3,
        'when the list was made' => $madeOn,
        'why they got it' => 'You are getting this because you are an administrator of ' . NAME_A . '.',
        'how to stop it' => 'switch off "Approval requests" on your Notification preferences page',
        'that the same switch stops the other e-mails about approvals' => 'That switch also stops the portal\'s other e-mails about approvals',
        'what a time is, and that a whole-day event has none' => 'Each time shown is the time on the event\'s own clock, with its time zone beside it. A whole-day event shows its date only.',
    ] as $what => $needle) {
        if (str_contains((string) $m['html'], $needle) === false) {
            $bodyFaults[] = $m['to'] . ' lacks ' . $what . ' (' . $needle . ')';
        }
    }
}
ok('N5 each subject names the organisation and the number, and nothing else', $subjectsOk, json_encode(array_column($mailA, 'subject')));
ok(
    'N5 each message holds the number, both calendars, A\'s name, A\'s link, EACH WAITING EVENT\'S TITLE AND TIME (in the event\'s own zone, named), when the list was made, why it came and how to stop it',
    $bodyFaults === [] && count($mailA) === 3,
    implode(' | ', $bodyFaults)
);
$leaks = [];
foreach ($run5['mail'] as $m) {
    $text = $m['subject'] . ' ' . $m['html'];
    if (str_starts_with((string) $m['subject'], '[' . NAME_A . ']') === true) {
        foreach (found($text, array_merge($notAs, $neverShown)) as $hit) {
            $leaks[] = $m['to'] . ' was shown ' . $hit;
        }
    }
}
ok(
    'N5 no message about A holds anything of B, C, D or the first organisation, a paused calendar or its event, a decided event, a place, a description, or a calendar\'s address',
    $leaks === [],
    implode(' | ', $leaks)
);
// At the same moment it is 04:10 in Santiago, so B is not e-mailed; C's
// (Auckland, the evening) goes to the global administrators only.
$wrong = [];
foreach ($run5['mail'] as $m) {
    $isA = str_starts_with((string) $m['subject'], '[' . NAME_A . ']');
    $isC = str_starts_with((string) $m['subject'], '[' . NAME_C . ']');
    if ($isA === false && $isC === false) {
        $wrong[] = $m['to'] . ' got "' . $m['subject'] . '"';
    }
    if ($isC === true && (in_array($m['to'], addresses([A_GLOB, G1, G2]), true) === false || found($m['subject'] . $m['html'], ['ZQXTA', 'ZQXCA', 'ZQXOA', 'ZQXTB', 'ZQXOB', KEY_A, KEY_B]) !== [])) {
        $wrong[] = $m['to'] . ' got C\'s message wrongly, or with another organisation\'s content';
    }
    if (in_array($m['to'], addresses([A_MEM, A_OLD, A_OFF, A_ROWOFF, B_MEM, C_MEM, D_ADM, G_OFF]), true) === true) {
        $wrong[] = $m['to'] . ' is not an administrator and got "' . $m['subject'] . '"';
    }
}
ok(
    'N5 nobody who is not an administrator is sent anything; a global administrator told about C is shown C\'s event and nothing of A\'s or B\'s; B (04:10 there) is not e-mailed yet',
    $wrong === [] && str_contains((string) (mailAbout($run5, NAME_C)[0]['html'] ?? ''), 'Gamma thing ZQXTC1') === true,
    implode(' | ', $wrong)
);
ok(
    'N5 A\'s push went BEFORE A\'s first e-mail: the run\'s first four sends are the push for A and then A\'s three messages',
    array_slice($run5['order'], 0, 4) === array_merge(['push ' . ORG_A], array_map(static fn (int $u): string => 'mail ' . mailOf($u), $adminsA)),
    json_encode(array_slice($run5['order'], 0, 6))
);
ok(
    'N5 each of A\'s three is recorded as told today, on A\'s own calendar, with the outcome "sent"',
    array_map(static fn (int $u): array => [told(ORG_A, $u)['emailLocalDate'] ?? null, told(ORG_A, $u)['emailOutcome'] ?? null], $adminsA) === array_fill(0, 3, [$plain, 'sent']),
    json_encode(array_map(static fn (int $u): ?array => told(ORG_A, $u), $adminsA))
);

// =============================================================================
head('N6. One e-mail a day, and nothing when nothing is waiting');
// =============================================================================
$again = run(at(ZONE_A, $plain . ' 12:00:00'));
$late  = run(at(ZONE_A, $plain . ' 23:59:00'));
ok('N6 a second run the same day (12:00) and a third (23:59) send A\'s administrators nothing more', mailAbout($again, NAME_A) === [] && mailAbout($late, NAME_A) === [], json_encode([sentTo($again, NAME_A), sentTo($late, NAME_A)]));
$next = run(at(ZONE_A, dayFrom($plain, 1) . ' 07:00:00'));
ok('N6 the next day at 07:00 each gets one again', sentTo($next, NAME_A) === addresses($adminsA), json_encode(sentTo($next, NAME_A)));
forgetTold();
q("UPDATE tblExternalEventApprovals SET status = 'approved' WHERE approvalID IN (?, ?, ?)", 'iii', [$apprT1, $apprT2, $apprT3]);
$none = run(at(ZONE_A, $plain . ' 08:10:00'));
$rowsNone = (int) (one('SELECT COUNT(*) AS n FROM tblExternalApprovalNotices WHERE siteID = ?', 'i', [ORG_A])['n'] ?? -1);
q("UPDATE tblExternalEventApprovals SET status = 'pending' WHERE approvalID IN (?, ?, ?)", 'iii', [$apprT1, $apprT2, $apprT3]);
ok('N6 with nothing waiting for A, A\'s administrators are sent nothing, pushed nothing, and nobody is claimed (no row is written)', mailAbout($none, NAME_A) === [] && pushFor($none, ORG_A) === [] && $rowsNone === 0, json_encode([sentTo($none, NAME_A), pushFor($none, ORG_A), $rowsNone]));
// A paused calendar's event alone is "nothing waiting" too.
q("UPDATE tblExternalEventApprovals SET status = 'approved' WHERE approvalID IN (?, ?, ?)", 'iii', [$apprT1, $apprT2, $apprT3]);
$pausedOnly = run(at(ZONE_A, $plain . ' 09:00:00'));
q("UPDATE tblExternalEventApprovals SET status = 'pending' WHERE approvalID IN (?, ?, ?)", 'iii', [$apprT1, $apprT2, $apprT3]);
ok('N6 with only a PAUSED calendar\'s event waiting, nobody is told either', mailAbout($pausedOnly, NAME_A) === [] && pushFor($pausedOnly, ORG_A) === [], json_encode([sentTo($pausedOnly, NAME_A), pushFor($pausedOnly, ORG_A)]));

// =============================================================================
head('N7. Not before 07:00 on the organisation\'s OWN clock, and no end hour');
// =============================================================================
foreach ([[ORG_A, NAME_A, ZONE_A, addresses($adminsA)], [ORG_B, NAME_B, ZONE_B, addresses([B_ADM])], [ORG_C, NAME_C, ZONE_C, addresses([A_GLOB, G1, G2])]] as [$site, $name, $zone, $expect]) {
    forgetTold();
    $before = run(at($zone, $plain . ' 06:59:00'));
    $onTime = run(at($zone, $plain . ' 07:00:00'));
    ok(
        'N7 ' . $zone . ': at 06:59 there nothing is e-mailed and no day is claimed; at 07:00 each administrator gets one',
        mailAbout($before, $name) === [] && sentTo($onTime, $name) === $expect,
        '06:59: ' . json_encode(sentTo($before, $name)) . '; 07:00: ' . json_encode(sentTo($onTime, $name))
    );
}
forgetTold();
$afternoon = run(at(ZONE_A, $plain . ' 14:00:00'));
ok('N7 a job that first runs at 14:00 still sends that day\'s message: there is no end hour', sentTo($afternoon, NAME_A) === addresses($adminsA), json_encode(sentTo($afternoon, NAME_A)));
forgetTold();
$lateFirst = run(at(ZONE_A, $plain . ' 23:58:00'));
ok('N7 and so does one that first runs at 23:58', sentTo($lateFirst, NAME_A) === addresses($adminsA), json_encode(sentTo($lateFirst, NAME_A)));

// =============================================================================
head('N8. Both clock-change nights, in London and in Santiago');
// =============================================================================
/**
 * One clock change in one zone: the day before, the day itself and the day
 * after, run in the order they happen, with two events whose stored times sit
 * in (or beside) the hour that happens twice or does not exist.
 *
 * @param list<string> $expect The addresses that must each get one message a day.
 */
$changeNight = static function (int $site, string $name, string $zone, int $feed, array $expect, array $change, string $label): void {
    $tz   = new DateTimeZone($zone);
    $utc  = new DateTimeZone('UTC');
    $t    = $change['ts'];
    $day  = (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d');
    $days = [dayFrom($day, -1), $day, dayFrom($day, 1)];
    $kind = $change['after'] > $change['before'] ? 'clocks go FORWARD' : 'clocks go BACK';

    // Two events on the change night. The first is half an hour before the
    // change on the clock; the second is one hour later BY THE CLOCK'S
    // FIGURES. When clocks go back the first is in the hour that happens
    // twice; when they go forward the second is in the hour that does not
    // exist. Either way the message must print the stored figures.
    $wallOne = (new DateTimeImmutable('@' . ($t - 1800)))->setTimezone($tz)->format('Y-m-d H:i:s');
    $wallTwo = (new DateTimeImmutable($wallOne, $utc))->modify('+1 hour')->format('Y-m-d H:i:s');
    $added   = [waitingEvent($site, $feed, 'Change night one ZQXTN1', $wallOne, $zone), waitingEvent($site, $feed, 'Change night two ZQXTN2', $wallTwo, $zone)];

    forgetTold([$site]);
    // Every moment to try, with how many messages it must produce.
    $probes = [];
    foreach ($days as $d) {
        $probes[] = [at($zone, $d . ' 06:59:00'), 0, $d . ' 06:59'];
        $probes[] = [at($zone, $d . ' 07:00:00'), count($expect), $d . ' 07:00'];
        $probes[] = [at($zone, $d . ' 23:30:00'), 0, $d . ' 23:30'];
    }
    foreach ([-1 => 'one second before the change', 1 => 'one second after the change', 1800 => 'half an hour after the change'] as $offset => $what) {
        $probes[] = [new DateTimeImmutable('@' . ($t + $offset)), 0, $what];
    }
    usort($probes, static fn (array $a, array $b): int => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

    $pattern = [];
    $useBy   = [];
    $stamps  = [];
    $times   = [];
    foreach ($probes as [$moment, $want, $what]) {
        $r   = run($moment);
        $got = sentTo($r, $name);
        if ($r['threw'] !== '' || ($want === 0 ? $got !== [] : $got !== $expect)) {
            $pattern[] = $what . ' (' . $moment->setTimezone($tz)->format('D H:i T') . '): wanted ' . $want . ', got ' . count($got) . ($r['threw'] === '' ? '' : ' and it threw ' . $r['threw']);
        }
        $local = $moment->setTimezone($tz);
        foreach (mailAbout($r, $name) as $m) {
            // The use-by moment must be the FIRST moment of the next day on
            // this organisation's clock: one second earlier is still today.
            $expires = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) ($m['options']['expiresAt'] ?? ''), $utc);
            $today   = $local->format('Y-m-d');
            if ($expires === false
                || $expires->setTimezone($tz)->format('Y-m-d') !== dayFrom($today, 1)
                || $expires->modify('-1 second')->setTimezone($tz)->format('Y-m-d') !== $today
                || $expires <= $moment) {
                $useBy[] = $what . ': use-by ' . json_encode($m['options']['expiresAt'] ?? null) . ' is not the start of ' . dayFrom($today, 1) . ' in ' . $zone;
            }
            $stamp = 'This list was made on ' . $local->format('D j M Y, H:i T') . ' (' . $zone . ' time)';
            if (str_contains((string) $m['html'], $stamp) === false) {
                $stamps[] = $what . ': lacks "' . $stamp . '"';
            }
            foreach (['Change night one ZQXTN1 &mdash; ' . shownTime($wallOne, $zone), 'Change night two ZQXTN2 &mdash; ' . shownTime($wallTwo, $zone)] as $needle) {
                if (str_contains((string) $m['html'], $needle) === false) {
                    $times[] = $what . ': lacks "' . $needle . '"';
                }
            }
        }
    }
    removeWaiting($added);

    $lengthOfDay = (int) ((at($zone, dayFrom($day, 1) . ' 12:00:00')->getTimestamp() - at($zone, dayFrom($day, -1) . ' 12:00:00')->getTimestamp()) / 3600);
    ok(
        'N8 ' . $label . ' (' . $kind . ' on ' . $day . '; the two days round it last ' . $lengthOfDay . ' hours, not 48): on each of the three days, nothing at 06:59, one each at 07:00, '
        . 'nothing at 23:30, and nothing a second or half an hour either side of the change',
        $pattern === [] && $lengthOfDay !== 48,
        implode(' | ', $pattern)
    );
    ok('N8 ' . $label . ': every message\'s use-by moment is the first moment of the organisation\'s NEXT day (one second earlier is still today)', $useBy === [] && $pattern === [], implode(' | ', array_slice($useBy, 0, 4)));
    ok('N8 ' . $label . ': every message says when the list was made, on the organisation\'s clock, with the zone\'s short and full name', $stamps === [] && $pattern === [], implode(' | ', array_slice($stamps, 0, 4)));
    ok(
        'N8 ' . $label . ': two events on the change night (' . substr($wallOne, 11, 5) . ' and ' . substr($wallTwo, 11, 5) . ' on the clock) are shown with exactly their stored figures and their zone, never moved',
        $times === [] && $pattern === [],
        implode(' | ', array_slice($times, 0, 4))
    );
};
// The anchor is a FIXED date (CHANGE_NIGHT_ANCHOR), never today, so these
// checks give the same answer whatever day of the year the test runs on.
$anchor = (new DateTimeImmutable(CHANGE_NIGHT_ANCHOR, new DateTimeZone('UTC')))->getTimestamp();

// 📌 The pinned nights, WRITTEN OUT BY HAND. This is not belt and braces: the
// four checks below each derive their night from PHP's time-zone data, so if a
// government moved a clock change and a data update followed, they would all
// still pass while testing a different night from the one the constant's
// comment names — and the comment would be quietly untrue. Deriving the
// expected dates from the same data would only prove the data agrees with
// itself. If this check ever fails, read the new dates off it and update the
// comment on CHANGE_NIGHT_ANCHOR to match (#652).
$pinned = [
    ZONE_A => ['2027-03-28 02:00 forward', '2027-10-31 01:00 back'],
    ZONE_B => ['2027-04-03 23:00 back', '2027-09-05 01:00 forward'],
];
foreach ($pinned as $zone => $want) {
    $got = [];
    foreach (clockChanges($zone, $anchor) as $change) {
        $got[] = (new DateTimeImmutable('@' . $change['ts']))->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i')
            . ' ' . ($change['after'] > $change['before'] ? 'forward' : 'back');
    }
    ok(
        'N8 ' . $zone . ': the twelve months from ' . CHANGE_NIGHT_ANCHOR . ' UTC hold exactly the two clock changes named beside '
        . 'CHANGE_NIGHT_ANCHOR (' . implode(' and ', $want) . '), on the organisation\'s own clock',
        $got === $want,
        'this PHP\'s time-zone data (' . timezone_version_get() . ') says: ' . json_encode($got)
    );
}
foreach ([[ORG_A, NAME_A, ZONE_A, FEED_A1, addresses($adminsA)], [ORG_B, NAME_B, ZONE_B, FEED_B1, addresses([B_ADM])]] as [$site, $name, $zone, $feed, $expect]) {
    $changes = clockChanges($zone, $anchor);
    foreach ([0, 1] as $index) {
        $label = $zone . ', change ' . ($index + 1);
        if (isset($changes[$index]) === false) {
            // Four either way, so the count at the end still holds. SKIPPED,
            // not FAIL: if a country abolishes its clock changes and a PHP
            // time-zone update follows, there is nothing wrong with this
            // portal — the check simply cannot be made, and saying FAIL for
            // that is how a check gets switched off (#652). Should this ever
            // print, pick a zone that still changes, or a year that does.
            foreach (['the sending pattern', 'the use-by moment', 'when the list was made', 'the change-night event times'] as $part) {
                skipped(
                    'N8 ' . $label . ': ' . $part,
                    'this PHP\'s time-zone data (' . timezone_version_get() . ') shows fewer than two clock changes for ' . $zone
                    . ' in the twelve months from ' . CHANGE_NIGHT_ANCHOR . ' UTC, so nothing could be tried.'
                );
            }
            continue;
        }
        $changeNight($site, $name, $zone, $feed, $expect, $changes[$index], $label);
    }
}

// =============================================================================
head('N9. Auckland: one local day that falls on two UTC dates');
// =============================================================================
forgetTold();
$nzMorning   = at(ZONE_C, $plain . ' 07:00:00');
$nzAfternoon = at(ZONE_C, $plain . ' 13:30:00');
$nz1 = run($nzMorning);
$nz2 = run($nzAfternoon);
ok(
    'N9 07:00 and 13:30 on one Auckland day are on two different UTC dates (' . $nzMorning->format('Y-m-d') . ' and ' . $nzAfternoon->format('Y-m-d') . '): one message each at 07:00, none at 13:30',
    $nzMorning->format('Y-m-d') !== $nzAfternoon->format('Y-m-d') && sentTo($nz1, NAME_C) === addresses([A_GLOB, G1, G2]) && mailAbout($nz2, NAME_C) === [],
    json_encode([sentTo($nz1, NAME_C), sentTo($nz2, NAME_C)])
);
ok(
    'N9 the day recorded is Auckland\'s (' . $plain . '), not the UTC date',
    (told(ORG_C, G1)['emailLocalDate'] ?? null) === $plain,
    json_encode(told(ORG_C, G1))
);

// =============================================================================
head('N10. The e-mail switched off by the person');
// =============================================================================
forgetTold();
q('UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?', 'si', ['{"approvalRequests":false}', A_ADM1]);
$off = run(at(ZONE_A, $plain . ' 08:10:00'));
ok(
    'N10 with "Approval requests" off, that administrator is e-mailed nothing and NO DAY IS CLAIMED for them; the other two get theirs',
    sentTo($off, NAME_A) === addresses([A_ADM2, A_GLOB]) && (told(ORG_A, A_ADM1)['emailLocalDate'] ?? null) === null,
    json_encode([sentTo($off, NAME_A), told(ORG_A, A_ADM1)])
);
ok('N10 the push is a separate switch: with only the e-mail off they are still pushed', in_array(A_ADM1, sorted(pushFor($off, ORG_A)[0]['userIds'] ?? []), true) === true, json_encode(pushFor($off, ORG_A)));
q('UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?', 'si', ['{"approvalRequests":true}', A_ADM1]);
$on = run(at(ZONE_A, $plain . ' 11:00:00'));
ok('N10 switched back on later the same day: the next run sends them that day\'s message, and nobody else a second', sentTo($on, NAME_A) === addresses([A_ADM1]), json_encode(sentTo($on, NAME_A)));
q('UPDATE tblUsers SET notifyPrefs = NULL WHERE userID = ?', 'i', [A_ADM1]);

// =============================================================================
head('N11. Quiet hours hold nothing');
// =============================================================================
// THE OWNER'S DECISION (13 October 2026): "Hold nothing". This notice is sent
// like every other e-mail of the portal, and so is its push, whether or not
// the person is in their Sabbath quiet hours. The first build held a person
// in their quiet hours and told them after the window; this section was then
// eight checks of that hold. It is ONE check now, and it is here so that the
// hold cannot come back unseen.
//
// The first Friday after the ordinary day, at 19:00. Quiet hours run from
// Friday 18:00 to Saturday 18:00 on the installation's quiet-hours clock
// (set to London here). Of A's three administrators one leaves quiet hours
// on "use the portal default", one chose "always", one chose "do not apply".
$friday = dayFrom($plain, 2);
q("UPDATE tblUsers SET sabbathHonour = 'inherit' WHERE userID = ?", 'i', [A_ADM1]);
q("UPDATE tblUsers SET sabbathHonour = 'off' WHERE userID = ?", 'i', [A_ADM2]);
q("UPDATE tblUsers SET sabbathHonour = 'on' WHERE userID = ?", 'i', [A_GLOB]);
portal(['sabbath' => ['enabled' => '1', 'method' => 'fixed', 'timezone' => ZONE_A]]);
forgetTold();
$quietMoment = at(ZONE_A, $friday . ' 19:00:00');
// What makes the check mean something: the moment really IS inside the quiet
// hours of two of the three, by the portal's own answer.
$insideQuiet = (new DateTimeImmutable($friday))->format('l') === 'Friday'
    && Sabbath::isQuietNow(A_ADM1, $quietMoment->getTimestamp()) === true
    && Sabbath::isQuietNow(A_GLOB, $quietMoment->getTimestamp()) === true
    && Sabbath::isQuietNow(A_ADM2, $quietMoment->getTimestamp()) === false;
$fri = run($quietMoment);
ok(
    'N11 Friday 19:00, inside the quiet hours of two of A\'s three administrators (the portal itself says so): ALL THREE are e-mailed and pushed at once, '
    . 'and each is recorded as told that day. Quiet hours hold nothing here',
    $insideQuiet === true && sentTo($fri, NAME_A) === addresses($adminsA) && sorted(pushFor($fri, ORG_A)[0]['userIds'] ?? []) === $adminsA
    && array_map(static fn (int $u): ?string => told(ORG_A, $u)['emailLocalDate'] ?? null, $adminsA) === [$friday, $friday, $friday],
    'inside quiet hours: ' . json_encode($insideQuiet) . '; e-mailed ' . json_encode(sentTo($fri, NAME_A)) . '; pushed ' . json_encode(pushFor($fri, ORG_A)[0]['userIds'] ?? null)
);
q("UPDATE tblUsers SET sabbathHonour = 'inherit' WHERE userID IN (?, ?, ?)", 'iii', [A_ADM1, A_ADM2, A_GLOB]);
portal();

// =============================================================================
head('N12. A second run that starts in the middle of the first');
// =============================================================================
forgetTold();
$nowN12  = at(ZONE_A, $plain . ' 08:10:00');
$nested  = null;
$started = false;
$run12 = run($nowN12, null, static function (string $to, string $subject) use (&$nested, &$started, $nowN12): void {
    if ($started === false) {
        $started = true;       // only once: the inner run's own sends must not start a third
        $nested  = run($nowN12);
    }
});
$both = array_merge(sentTo($run12, NAME_A), $nested === null ? [] : sentTo($nested, NAME_A));
sort($both);
ok(
    'N12 a second run begun while the first is sending its first message: between them each administrator gets exactly ONE message',
    $nested !== null && $both === addresses($adminsA) && count(mailAbout($run12, NAME_A)) + count(mailAbout($nested, NAME_A)) === 3,
    'first run: ' . json_encode(sentTo($run12, NAME_A)) . '; second run: ' . json_encode($nested === null ? null : sentTo($nested, NAME_A))
);
$pushedBoth = array_merge(...array_map(static fn (array $p): array => (array) $p['userIds'], array_merge(pushFor($run12, ORG_A), $nested === null ? [] : pushFor($nested, ORG_A))));
ok('N12 and each is addressed by exactly one push', sorted($pushedBoth) === $adminsA, json_encode($pushedBoth));

// A run whose OWN EARLIER READ has gone stale: another run told everybody
// after this one had read "nobody has been told". That is what two runs of
// the job at the same moment come to. It is made to happen here, every time,
// with the database's own tools: this connection starts a transaction that
// sees the table as it was, a SECOND connection then claims A's three (as
// another run would), and the pass is run on the first. Its reads still see
// "nobody told". Its claims do not go by what it read: each is one statement
// that looks at the row as it IS, and changes nothing.
forgetTold();
$mysqli->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
$otherRun = connect();
foreach ($adminsA as $userId) {
    $claim = $otherRun->prepare(
        "INSERT INTO tblExternalApprovalNotices (siteID, userID, emailLocalDate, emailOutcome, emailAt, pushUpToApprovalID, pushAt) VALUES (?, ?, ?, 'sent', UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())"
    );
    $site = ORG_A;
    $claim->bind_param('iisi', $site, $userId, $plain, $apprT3);
    $claim->execute();
    $claim->close();
}
$staleSees = (int) (one('SELECT COUNT(*) AS n FROM tblExternalApprovalNotices WHERE siteID = ?', 'i', [ORG_A])['n'] ?? -1);
$stale     = run($nowN12);
$mysqli->rollback();
$asClaimed = q('SELECT userID, emailLocalDate, emailOutcome, pushUpToApprovalID FROM tblExternalApprovalNotices WHERE siteID = ? ORDER BY userID', 'i', [ORG_A]);
$otherRun->close();
ok(
    'N12 a run whose own read is stale (it still reads "nobody told", while another run has just told all three): it e-mails A nobody and pushes A nobody, '
    . 'and leaves the other run\'s three rows exactly as they were',
    $staleSees === 0 && $stale['threw'] === '' && mailAbout($stale, NAME_A) === [] && pushFor($stale, ORG_A) === []
    && array_map(static fn (array $r): array => [(int) $r['userID'], $r['emailLocalDate'], $r['emailOutcome'], (int) $r['pushUpToApprovalID']], $asClaimed)
        === array_map(static fn (int $u): array => [$u, $plain, 'sent', $apprT3], $adminsA),
    'rows it could see: ' . $staleSees . '; e-mailed ' . json_encode(sentTo($stale, NAME_A)) . '; pushed ' . json_encode(pushFor($stale, ORG_A)) . '; rows now ' . json_encode($asClaimed)
);

// =============================================================================
head('N13. A sender that throws, and a deadline that has passed');
// =============================================================================
forgetTold();
$run13 = run(at(ZONE_A, $plain . ' 08:10:00'), static function (string $to, string $subject): string {
    if ($to === mailOf(A_ADM2) && str_starts_with($subject, '[' . NAME_A . ']') === true) {
        throw new RuntimeException('the mail service fell over while sending to ' . $to);
    }

    return Mailer::OUTCOME_SENT;
});
ok(
    'N13 the sender throws on the second person: the run does not throw, all three were tried, the first and third are recorded "sent" and the second "failed"',
    $run13['threw'] === '' && sentTo($run13, NAME_A) === addresses($adminsA)
    && [told(ORG_A, A_ADM1)['emailOutcome'] ?? null, told(ORG_A, A_ADM2)['emailOutcome'] ?? null, told(ORG_A, A_GLOB)['emailOutcome'] ?? null] === ['sent', 'failed', 'sent']
    && ($run13['out']['emailProblems'] ?? -1) === 1,
    json_encode([$run13['threw'], $run13['out'], told(ORG_A, A_ADM1), told(ORG_A, A_ADM2), told(ORG_A, A_GLOB)])
);
forgetTold();
$past = run(at(ZONE_A, $plain . ' 08:10:00'), null, null, microtime(true) - 1.0);
$rowsPast = (int) (one('SELECT COUNT(*) AS n FROM tblExternalApprovalNotices')['n'] ?? -1);
ok(
    'N13 a deadline that has already passed: nothing is sent, NOBODY IS CLAIMED (no row at all), and the run says organisations were not started',
    $past['threw'] === '' && $past['mail'] === [] && $past['push'] === [] && $rowsPast === 0 && ($past['out']['notStarted'] ?? 0) >= 1,
    json_encode([$past['out'], $rowsPast])
);
// The deadline passes while the FIRST person is being sent to. The recorder
// stays "in the send" until the deadline has certainly gone by, however long
// that is, so the check does not depend on how fast this machine is today:
// two seconds is far more than the run needs to reach its first send.
$deadline = microtime(true) + 2.0;
$cut = run(at(ZONE_A, $plain . ' 08:10:00'), null, static function (string $to, string $subject) use ($deadline): void {
    $left = $deadline - microtime(true);
    if ($left > -0.05) {
        usleep((int) (($left + 0.05) * 1000000));
    }
}, $deadline);
$claimedAfterCut = array_values(array_filter($adminsA, static fn (int $u): bool => (told(ORG_A, $u)['emailLocalDate'] ?? null) !== null));
ok(
    'N13 a deadline that passes during the first e-mail: the push had already gone to all three; that one person is e-mailed; the other two are NOT claimed for an e-mail; '
    . 'and the run says two people were not reached and two organisations not started',
    count($cut['mail']) === 1 && count($claimedAfterCut) === 1 && sorted(pushFor($cut, ORG_A)[0]['userIds'] ?? []) === $adminsA
    && ($cut['out']['notReached'] ?? -1) === 2 && ($cut['out']['notStarted'] ?? -1) === 2,
    json_encode([array_column($cut['mail'], 'to'), $claimedAfterCut, $cut['out']])
);
$rest  = run(at(ZONE_A, $plain . ' 08:15:00'));
$total = array_merge(array_column(mailAbout($cut, NAME_A), 'to'), sentTo($rest, NAME_A));
sort($total);
ok('N13 the next run e-mails everybody not reached, so that each has had exactly one, and pushes nobody a second time', $total === addresses($adminsA) && pushFor($rest, ORG_A) === [], json_encode([$total, pushFor($rest, ORG_A)]));
// A push call that uses up the pass's seconds: a push service that does not
// answer. One call is not cut short once it has begun (WebPush waits up to
// ten seconds for each device). What that costs must be time, never a
// message: the e-mails it left no time for are not started, nobody is
// claimed for one, and the next run sends them.
forgetTold();
$deadline = microtime(true) + 2.0;
$slowPush = run(at(ZONE_A, $plain . ' 08:10:00'), null, null, $deadline, ['pushDuring' => static function (int $siteId, array $userIds) use ($deadline): void {
    $left = $deadline - microtime(true);
    if ($left > -0.05) {
        usleep((int) (($left + 0.05) * 1000000));
    }
}]);
ok(
    'N13 a push call that outlasts the deadline: it was started in time and went to all three; NO e-mail was started after it and nobody is claimed for one; '
    . 'the run says three people were not reached and two organisations not started',
    $slowPush['threw'] === '' && sorted(pushFor($slowPush, ORG_A)[0]['userIds'] ?? []) === $adminsA && $slowPush['mail'] === []
    && array_map(static fn (int $u): ?string => told(ORG_A, $u)['emailLocalDate'] ?? null, $adminsA) === [null, null, null]
    && ($slowPush['out']['notReached'] ?? -1) === 3 && ($slowPush['out']['notStarted'] ?? -1) === 2,
    json_encode([$slowPush['order'], $slowPush['out']])
);
$afterSlow = run(at(ZONE_A, $plain . ' 08:15:00'));
ok(
    'N13 the next run sends each of those three the e-mail that was not started, once, and pushes nobody a second time',
    sentTo($afterSlow, NAME_A) === addresses($adminsA) && pushFor($afterSlow, ORG_A) === [],
    json_encode([sentTo($afterSlow, NAME_A), pushFor($afterSlow, ORG_A)])
);

// =============================================================================
head('N14. A refused send, and a message the mail queue keeps');
// =============================================================================
forgetTold();
$errorsBefore = (int) (one("SELECT COUNT(*) AS n FROM tblErrors WHERE errorCode = 'ImportNoticeEmailFailed'")['n'] ?? 0);
$refuse = static fn (string $to, string $subject): string => $to === mailOf(A_ADM1) ? Mailer::OUTCOME_REFUSED : Mailer::OUTCOME_SENT;
$run14  = run(at(ZONE_A, $plain . ' 08:10:00'), $refuse);
$errorsAfter = q("SELECT siteID, userID, errorSeverity, errorTitle, errorDetail FROM tblErrors WHERE errorCode = 'ImportNoticeEmailFailed' ORDER BY errorID");
ok(
    'N14 a send the mail service refuses is recorded "failed", is counted as a problem, and writes ONE line to the error log, against A, naming the account by number only',
    (told(ORG_A, A_ADM1)['emailOutcome'] ?? null) === 'failed' && ($run14['out']['emailProblems'] ?? -1) === 1 && count($errorsAfter) - $errorsBefore === 1
    && (int) (end($errorsAfter)['siteID'] ?? 0) === ORG_A && (int) (end($errorsAfter)['userID'] ?? 0) === A_ADM1 && (end($errorsAfter)['errorSeverity'] ?? '') === 'Warning',
    json_encode([told(ORG_A, A_ADM1), $run14['out'], array_slice($errorsAfter, -2)])
);
$retry = run(at(ZONE_A, $plain . ' 12:00:00'), $refuse);
ok('N14 it is NOT tried again the same day', mailAbout($retry, NAME_A) === [], json_encode(sentTo($retry, NAME_A)));
$tomorrow = run(at(ZONE_A, dayFrom($plain, 1) . ' 07:00:00'));
ok('N14 it goes the next day, with everybody else\'s', sentTo($tomorrow, NAME_A) === addresses($adminsA) && (told(ORG_A, A_ADM1)['emailOutcome'] ?? null) === 'sent', json_encode([sentTo($tomorrow, NAME_A), told(ORG_A, A_ADM1)]));
// "Kept" means the mail queue has the message. Whether it will ever GO
// depends on whether the mail queue's own scheduled job is running, so the
// run counts the two cases apart. First with the job running.
forgetTold();
mailJobRuns(true);
$errorsBefore = (int) (one("SELECT COUNT(*) AS n FROM tblErrors WHERE errorPlatform = 'ImportNotifier'")['n'] ?? 0);
$keep  = static fn (string $to, string $subject): string => $to === mailOf(A_ADM1) ? Mailer::OUTCOME_QUEUED : ($to === mailOf(A_ADM2) ? Mailer::OUTCOME_HELD : Mailer::OUTCOME_SENT);
$run14k = run(at(ZONE_A, $plain . ' 08:10:00'), $keep);
ok(
    'N14 a message the mail queue KEEPS (waiting, or held) is recorded "kept", never "sent": with the mail job running the run reports 2 kept, none under '
    . '"keptNoMailJob", and 1 or more e-mailed; and it writes no error line',
    [told(ORG_A, A_ADM1)['emailOutcome'] ?? null, told(ORG_A, A_ADM2)['emailOutcome'] ?? null, told(ORG_A, A_GLOB)['emailOutcome'] ?? null] === ['kept', 'kept', 'sent']
    && ($run14k['out']['kept'] ?? -1) === 2 && ($run14k['out']['keptNoMailJob'] ?? -1) === 0 && ($run14k['out']['emailed'] ?? -1) >= 1 && ($run14k['out']['emailProblems'] ?? -1) === 0
    && (int) (one("SELECT COUNT(*) AS n FROM tblErrors WHERE errorPlatform = 'ImportNotifier'")['n'] ?? 0) === $errorsBefore,
    json_encode([$run14k['out'], told(ORG_A, A_ADM1), told(ORG_A, A_ADM2), told(ORG_A, A_GLOB)])
);
$keptAgain = run(at(ZONE_A, $plain . ' 12:00:00'), $keep);
ok('N14 a kept message is not written a second time that day (the mail queue has it)', mailAbout($keptAgain, NAME_A) === [], json_encode(sentTo($keptAgain, NAME_A)));
// Now with NO mail job running: nothing works through the queue, so the two
// kept messages will not go unless a job is started before they lapse. They
// must not be counted under "kept", which reads as "it will go later".
forgetTold([ORG_A]);
mailJobRuns(false);
$run14n = run(at(ZONE_A, $plain . ' 08:10:00'), $keep);
ok(
    'N14 with NO mail job running, the same two are counted under "keptNoMailJob" and none under "kept"; what is stored for each person is still "kept", because that is what happened',
    ($run14n['out']['keptNoMailJob'] ?? -1) === 2 && ($run14n['out']['kept'] ?? -1) === 0
    && [told(ORG_A, A_ADM1)['emailOutcome'] ?? null, told(ORG_A, A_ADM2)['emailOutcome'] ?? null, told(ORG_A, A_GLOB)['emailOutcome'] ?? null] === ['kept', 'kept', 'sent'],
    json_encode([$run14n['out'], told(ORG_A, A_ADM1), told(ORG_A, A_ADM2)])
);
// B and C were told earlier on this same day by the runs above, so they are
// forgotten first: otherwise there would be no message of theirs to read.
forgetTold([ORG_B, ORG_C]);
$optionFaults = [];
foreach ([[ZONE_A, NAME_A, $run14k], [ZONE_C, NAME_C, run(at(ZONE_C, $plain . ' 08:10:00'))], [ZONE_B, NAME_B, run(at(ZONE_B, $plain . ' 08:10:00'))]] as [$zone, $name, $r]) {
    $expectUseBy = at($zone, dayFrom($plain, 1) . ' 00:00:00')->format('Y-m-d H:i:s');
    foreach (mailAbout($r, $name) as $m) {
        if (($m['options']['expiresAt'] ?? null) !== $expectUseBy || ($m['options']['repeatSafe'] ?? null) !== true || count($m['options']) !== 2) {
            $optionFaults[] = $name . ': ' . json_encode($m['options']) . ' (wanted use-by ' . $expectUseBy . ' UTC)';
        }
    }
    if (mailAbout($r, $name) === []) {
        $optionFaults[] = $name . ': no message was written, so nothing could be read';
    }
}
ok(
    'N14 every message is handed over with a USE-BY moment, the start of its organisation\'s next day (London, Auckland and Santiago each on its own clock), and marked safe to repeat',
    $optionFaults === [],
    implode(' | ', $optionFaults)
);

// =============================================================================
head('N15. The push');
// =============================================================================
// The first-part-of-the-address way of telling organisations apart is the
// one in use throughout this file (portal()).
forgetTold();
$night  = run(at(ZONE_A, $plain . ' 03:00:00'));
$pushA  = pushFor($night, ORG_A);
$first  = $pushA[0] ?? [];
ok(
    'N15 at 03:00 in London (no e-mail yet): ONE push call for A, to exactly its three administrators, on the "reminders" channel, gated by the "pushApprovals" preference',
    count($pushA) === 1 && sorted($first['userIds'] ?? []) === $adminsA && ($first['channel'] ?? '') === 'reminders' && ($first['prefKey'] ?? '') === 'pushApprovals'
    && ($first['topic'] ?? '') === 'feed-approvals' && ($first['ttl'] ?? 0) === 3600 && mailAbout($night, NAME_A) === [],
    json_encode($pushA)
);
$payload = (array) ($first['payload'] ?? []);
ok(
    'N15 the payload holds a number and NO name of anything: no event, calendar, organisation or person',
    ($payload['title'] ?? '') === 'Imported events waiting for approval' && ($payload['body'] ?? '') === '3 imported events are waiting for approval.'
    && ($payload['tag'] ?? '') === 'feed-approvals' && found((string) json_encode($payload), array_merge($neverLogged, ['ZQXOA', NAME_A])) === [],
    json_encode($payload)
);
ok(
    'N15 where it leads is a PATH (the service worker opens nothing else) carrying A\'s own part of the address, never B\'s and never none',
    ($payload['url'] ?? '') === '/' . KEY_A . '/approvals#imports',
    json_encode($payload['url'] ?? null)
);
$pushB = pushFor($night, ORG_B);
ok(
    'N15 B\'s push is its own: to B\'s administrator, saying 2, leading to B\'s part of the address',
    count($pushB) === 1 && sorted($pushB[0]['userIds']) === [B_ADM] && ($pushB[0]['payload']['body'] ?? '') === '2 imported events are waiting for approval.' && ($pushB[0]['payload']['url'] ?? '') === '/' . KEY_B . '/approvals#imports',
    json_encode($pushB)
);
$second = run(at(ZONE_A, $plain . ' 03:05:00'));
ok('N15 a second run: no push at all, for anybody', $second['push'] === [], json_encode($second['push']));
$newer = waitingEvent(ORG_A, FEED_A2, 'A newer one ZQXTA4', $wallT1, ZONE_A);
$third = run(at(ZONE_A, $plain . ' 03:10:00'));
ok(
    'N15 a NEWER waiting event: one more push for A, to all three, now saying 4; none for B, where nothing is new',
    count(pushFor($third, ORG_A)) === 1 && sorted(pushFor($third, ORG_A)[0]['userIds']) === $adminsA && (pushFor($third, ORG_A)[0]['payload']['body'] ?? '') === '4 imported events are waiting for approval.' && pushFor($third, ORG_B) === [],
    json_encode($third['push'])
);
ok('N15 each person\'s row remembers the newest event they were told about', (int) (told(ORG_A, A_ADM1)['pushUpToApprovalID'] ?? 0) === $newer, json_encode(told(ORG_A, A_ADM1)));
removeWaiting([$newer]);
$fewer = run(at(ZONE_A, $plain . ' 03:15:00'));
ok('N15 an event that stops waiting causes no push', $fewer['push'] === [], json_encode($fewer['push']));
// One waiting event, said in the singular.
q("UPDATE tblExternalEventApprovals SET status = 'approved' WHERE approvalID IN (?, ?)", 'ii', [$apprT1, $apprT2]);
forgetTold();
$single = run(at(ZONE_A, $plain . ' 08:10:00'));
q("UPDATE tblExternalEventApprovals SET status = 'pending' WHERE approvalID IN (?, ?)", 'ii', [$apprT1, $apprT2]);
ok(
    'N15 one waiting event is said in the singular, in the push and in the e-mail\'s subject',
    (pushFor($single, ORG_A)[0]['payload']['body'] ?? '') === '1 imported event is waiting for approval.' && (mailAbout($single, NAME_A)[0]['subject'] ?? '') === '[' . NAME_A . '] 1 imported event is waiting for approval',
    json_encode([pushFor($single, ORG_A)[0]['payload'] ?? null, mailAbout($single, NAME_A)[0]['subject'] ?? null])
);
// The push switched off by the person.
forgetTold();
q('UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?', 'si', ['{"pushApprovals":false}', A_ADM2]);
$pushOff = run(at(ZONE_A, $plain . ' 03:00:00'));
ok(
    'N15 with "pushApprovals" off, that person is not in the push and nothing is claimed for them',
    sorted(pushFor($pushOff, ORG_A)[0]['userIds'] ?? []) === [A_ADM1, A_GLOB] && told(ORG_A, A_ADM2) === null,
    json_encode([pushFor($pushOff, ORG_A), told(ORG_A, A_ADM2)])
);
q('UPDATE tblUsers SET notifyPrefs = NULL WHERE userID = ?', 'i', [A_ADM2]);
$pushOn = run(at(ZONE_A, $plain . ' 03:05:00'));
ok('N15 switched on again: the next run pushes that person alone', sorted(pushFor($pushOn, ORG_A)[0]['userIds'] ?? []) === [A_ADM2], json_encode(pushFor($pushOn, ORG_A)));
// The run's two push numbers. The push service takes NONE of A's three here
// (its sender says so), and all of everybody else's.
forgetTold();
$noneTaken = run(at(ZONE_A, $plain . ' 03:00:00'), null, null, null, ['pushAnswer' => static fn (int $siteId, array $userIds): array
    => $siteId === ORG_A ? ['sent' => 0, 'failed' => count($userIds), 'pruned' => 0] : ['sent' => count($userIds), 'failed' => 0, 'pruned' => 0]]);
$addressed = array_sum(array_map(static fn (array $p): int => count((array) $p['userIds']), $noneTaken['push']));
$taken     = array_sum(array_map(static fn (array $p): int => (int) $p['said']['sent'], $noneTaken['push']));
ok(
    'N15 the run\'s two push numbers say what is true: "pushPeople" is the people addressed (' . $addressed . '), and "pushAccepted" is only what the sender says the '
    . 'push service took (' . $taken . '): three fewer, because it took none of A\'s',
    ($noneTaken['out']['pushPeople'] ?? -1) === $addressed && ($noneTaken['out']['pushAccepted'] ?? -1) === $taken && $taken === $addressed - 3 && count(pushFor($noneTaken, ORG_A)) === 1,
    json_encode($noneTaken['out'])
);

// =============================================================================
head('N16. An administrator who stops being one');
// =============================================================================
forgetTold();
$day1 = run(at(ZONE_A, $plain . ' 08:10:00'));
q('UPDATE tblUserSites SET isSiteRootAdmin = 0 WHERE userID = ? AND siteID = ?', 'ii', [A_ADM2, ORG_A]);
$newer = waitingEvent(ORG_A, FEED_A2, 'Another newer one ZQXTA5', $wallT1, ZONE_A);
$day2  = run(at(ZONE_A, dayFrom($plain, 1) . ' 08:10:00'));
ok(
    'N16 demoted between two runs: on the second (a new day, and a newer event) they get no e-mail and no push; the other two get both',
    sentTo($day1, NAME_A) === addresses($adminsA) && sentTo($day2, NAME_A) === addresses([A_ADM1, A_GLOB]) && sorted(pushFor($day2, ORG_A)[0]['userIds'] ?? []) === [A_ADM1, A_GLOB],
    json_encode([sentTo($day2, NAME_A), pushFor($day2, ORG_A)])
);
removeWaiting([$newer]);
q('UPDATE tblUserSites SET isSiteRootAdmin = 1 WHERE userID = ? AND siteID = ?', 'ii', [A_ADM2, ORG_A]);
// Demoted DURING a run: while the first person's message is being sent.
forgetTold();
$during = run(at(ZONE_A, $plain . ' 08:10:00'), null, static function (string $to, string $subject): void {
    if ($to === mailOf(A_ADM1)) {
        q('UPDATE tblUserSites SET isSiteRootAdmin = 0 WHERE userID = ? AND siteID = ?', 'ii', [A_ADM2, ORG_A]);
    }
});
q('UPDATE tblUserSites SET isSiteRootAdmin = 1 WHERE userID = ? AND siteID = ?', 'ii', [A_ADM2, ORG_A]);
ok(
    'N16 demoted DURING a run, while the first person\'s e-mail is being sent: no e-mail is sent to them and no day is claimed for them (the approvals list\'s own gate is '
    . 'asked again just before each message). The push had gone to all three before any e-mail, while they still were an administrator',
    sentTo($during, NAME_A) === addresses([A_ADM1, A_GLOB]) && sorted(pushFor($during, ORG_A)[0]['userIds'] ?? []) === $adminsA
    && (told(ORG_A, A_ADM2)['emailLocalDate'] ?? null) === null && $during['order'][0] === 'push ' . ORG_A,
    json_encode([sentTo($during, NAME_A), pushFor($during, ORG_A), told(ORG_A, A_ADM2)])
);
// Demoted at the very moment their push is claimed. The hook takes their
// right away inside the statement that claims them, so they HAD passed the
// gate and they ARE claimed. The gate is asked again, for everybody claimed,
// just before the one call, and that is what must leave them out.
forgetTold();
hook('IF NEW.userID = ' . A_ADM2 . ' AND NEW.siteID = ' . ORG_A . ' AND NEW.pushUpToApprovalID > OLD.pushUpToApprovalID THEN '
    . 'UPDATE tblUserSites SET isSiteRootAdmin = 0 WHERE userID = ' . A_ADM2 . ' AND siteID = ' . ORG_A . '; END IF;');
$atClaim = run(at(ZONE_A, $plain . ' 03:00:00'));
unhook();
$wasClaimed = (int) (told(ORG_A, A_ADM2)['pushUpToApprovalID'] ?? 0) === $apprT3;
$wasDemoted = (int) (one('SELECT isSiteRootAdmin AS f FROM tblUserSites WHERE userID = ? AND siteID = ?', 'ii', [A_ADM2, ORG_A])['f'] ?? 1) === 0;
q('UPDATE tblUserSites SET isSiteRootAdmin = 1 WHERE userID = ? AND siteID = ?', 'ii', [A_ADM2, ORG_A]);
ok(
    'N16 demoted at the very moment their push is claimed (they had passed the gate, and were claimed): they are still left out of the push call, which goes to the other two',
    $wasClaimed === true && $wasDemoted === true && $atClaim['threw'] === '' && sorted(pushFor($atClaim, ORG_A)[0]['userIds'] ?? []) === [A_ADM1, A_GLOB],
    'claimed: ' . json_encode($wasClaimed) . '; demoted by the hook: ' . json_encode($wasDemoted) . '; pushed ' . json_encode(pushFor($atClaim, ORG_A)[0]['userIds'] ?? null)
);

// =============================================================================
head('N26. The push goes before the e-mails, and a claim is always followed by its call');
// =============================================================================
// WHAT WAS WRONG (found by the independent check, 13 October 2026). Each
// person's push was claimed as the pass went through the people, and the one
// call was made after every e-mail had been sent. A claim is never undone.
// So a run that was killed, or ended by a fault, in those seconds left
// people claimed for a push that no run would ever send.
//
// (a) A fault during the E-MAILS. The database refuses the second person's
//     e-mail claim, with words of its own that hold an address, a title and
//     a person's mark, to show that a fault's own words are never logged.
forgetTold();
$turnFaultsBefore = (int) (one("SELECT COUNT(*) AS n FROM tblErrors WHERE errorCode = 'ImportNoticeOrganisationFailed'")['n'] ?? 0);
hook('IF NEW.userID = ' . A_ADM2 . ' AND NEW.siteID = ' . ORG_A . " AND NEW.emailOutcome = 'claimed' THEN "
    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted fault for a.adm2@notify.selftest.invalid about ZQXTA1 and ZQXP02'; END IF;");
$faultMail = run(at(ZONE_A, $plain . ' 08:10:00'));
unhook();
ok(
    'N26 a fault that ends A\'s turn during its e-mails: the run does not throw, A\'s push had ALREADY gone to all three, the first person had their e-mail, '
    . 'the second and third got none, and the fault is counted',
    $faultMail['threw'] === '' && array_slice($faultMail['order'], 0, 2) === ['push ' . ORG_A, 'mail ' . mailOf(A_ADM1)] && sorted(pushFor($faultMail, ORG_A)[0]['userIds'] ?? []) === $adminsA
    && sentTo($faultMail, NAME_A) === addresses([A_ADM1]) && ($faultMail['out']['problems'] ?? -1) === 1,
    json_encode([$faultMail['threw'], $faultMail['order'], $faultMail['out']])
);
ok(
    'N26 one organisation\'s fault does not stop the others: in the same run C\'s administrators are still pushed and e-mailed',
    sentTo($faultMail, NAME_C) === addresses([A_GLOB, G1, G2]) && count(pushFor($faultMail, ORG_C)) === 1,
    json_encode([sentTo($faultMail, NAME_C), pushFor($faultMail, ORG_C)])
);
$turnFaults = q("SELECT siteID, userID, errorSeverity, errorCode, errorTitle, errorDetail FROM tblErrors WHERE errorCode = 'ImportNoticeOrganisationFailed' ORDER BY errorID");
$turnFault  = (array) end($turnFaults);
ok(
    'N26 the fault is on record once, against A, saying only what KIND of fault it was and where in the code: none of the fault\'s own words (which held an address, '
    . 'a title and a person\'s mark), and a sentence that is true of who is told next',
    count($turnFaults) - $turnFaultsBefore === 1 && (int) ($turnFault['siteID'] ?? 0) === ORG_A
    && preg_match('/\Amysqli_sql_exception at ImportNotifier\.php:\d+\.\z/', (string) ($turnFault['errorDetail'] ?? '')) === 1
    && found((string) json_encode($turnFault, JSON_UNESCAPED_SLASHES), array_merge($neverLogged, ['planted'])) === []
    && str_contains((string) ($turnFault['errorTitle'] ?? ''), 'Whoever had not been reached yet is told by the next run.') === true,
    json_encode($turnFault, JSON_UNESCAPED_SLASHES)
);
$faultMailNext = run(at(ZONE_A, $plain . ' 08:15:00'));
ok(
    'N26 the next run e-mails the two whose e-mail had not been sent, once each, and pushes nobody a second time',
    sentTo($faultMailNext, NAME_A) === addresses([A_ADM2, A_GLOB]) && pushFor($faultMailNext, ORG_A) === [],
    json_encode([sentTo($faultMailNext, NAME_A), pushFor($faultMailNext, ORG_A)])
);
// (b) A fault during the PUSH CLAIMS themselves: the database refuses the
//     second person's claim. The first person is claimed already, and a
//     claim is never undone, so the call must still be made for them.
forgetTold();
hook('IF NEW.userID = ' . A_ADM2 . ' AND NEW.siteID = ' . ORG_A . ' AND NEW.pushUpToApprovalID > OLD.pushUpToApprovalID THEN '
    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted fault: this push claim is refused'; END IF;");
$faultPush = run(at(ZONE_A, $plain . ' 08:10:00'));
unhook();
ok(
    'N26 a fault that ends A\'s turn during its push claims: the first person, already claimed, IS pushed in that same run; nobody after the fault is claimed; '
    . 'no e-mail of A was started; and the fault is counted',
    $faultPush['threw'] === '' && sorted(pushFor($faultPush, ORG_A)[0]['userIds'] ?? []) === [A_ADM1] && (int) (told(ORG_A, A_ADM1)['pushUpToApprovalID'] ?? 0) === $apprT3
    && (int) (told(ORG_A, A_ADM2)['pushUpToApprovalID'] ?? -1) === 0 && told(ORG_A, A_GLOB) === null && mailAbout($faultPush, NAME_A) === [] && ($faultPush['out']['problems'] ?? -1) === 1,
    json_encode([pushFor($faultPush, ORG_A), told(ORG_A, A_ADM1), told(ORG_A, A_ADM2), told(ORG_A, A_GLOB), $faultPush['out']])
);
$faultPushNext = run(at(ZONE_A, $plain . ' 08:15:00'));
ok(
    'N26 the next run pushes the two who were not claimed, and not the first again, and e-mails all three',
    sorted(pushFor($faultPushNext, ORG_A)[0]['userIds'] ?? []) === [A_ADM2, A_GLOB] && sentTo($faultPushNext, NAME_A) === addresses($adminsA),
    json_encode([pushFor($faultPushNext, ORG_A), sentTo($faultPushNext, NAME_A)])
);

// =============================================================================
head('N18. The table not there yet (new files, the database upgrade not run)');
// =============================================================================
forgetTold();
$errorsBefore = (int) (one('SELECT COUNT(*) AS n FROM tblErrors')['n'] ?? 0);
$mysqli->query('RENAME TABLE tblExternalApprovalNotices TO tblExternalApprovalNoticesHidden');
$noTable = run(at(ZONE_A, $plain . ' 08:10:00'));
$cardNoTable = ImportNotifier::dashboardCard($mysqli, A_ADM1, ORG_A);
$mysqli->query('RENAME TABLE tblExternalApprovalNoticesHidden TO tblExternalApprovalNotices');
ok(
    'N18 with the table missing, the run sends nothing, says "not set up", does not throw, and writes no error line',
    $noTable['threw'] === '' && $noTable['mail'] === [] && $noTable['push'] === [] && ($noTable['out']['setUp'] ?? true) === false
    && (int) (one('SELECT COUNT(*) AS n FROM tblErrors')['n'] ?? 0) === $errorsBefore,
    json_encode([$noTable['threw'], $noTable['out']])
);
ok('N18 the dashboard card needs no such table: it still shows the number', ($cardNoTable['value'] ?? null) === 3, json_encode($cardNoTable));

// =============================================================================
head('N20. An installation with ONE organisation');
// =============================================================================
// Multi-organisation switched off: the installation is its first
// organisation and nothing else can be opened.
$apprOne = waitingEvent(ORG_ONE, FEED_ONE, 'First organisation thing ZQXTONE1', $wallT1, 'UTC');
$nameOne = (string) (one('SELECT siteName FROM tblSites WHERE siteID = ?', 'i', [ORG_ONE])['siteName'] ?? '');
$zoneOne = Site::timezone(ORG_ONE);
portal(['multi' => 'false']);
forgetTold();
$oneRun  = run(at($zoneOne, $plain . ' 08:10:00'));
$oneMail = array_values(array_filter($oneRun['mail'], static fn (array $m): bool => $m['to'] === mailOf(ONE_ADM)));
ok(
    'N20 its administrator gets one message, with the plain address (no organisation part), and a push leading to the plain path',
    count($oneMail) === 1 && str_contains((string) $oneMail[0]['html'], '<a href="' . PORTAL_URL . '/approvals#imports">') === true
    && str_contains((string) $oneMail[0]['html'], 'First organisation thing ZQXTONE1') === true
    && (pushFor($oneRun, ORG_ONE)[0]['payload']['url'] ?? '') === '/approvals#imports' && in_array(ONE_ADM, sorted(pushFor($oneRun, ORG_ONE)[0]['userIds'] ?? []), true) === true,
    json_encode([array_column($oneRun['mail'], 'subject'), pushFor($oneRun, ORG_ONE)])
);
$others = array_values(array_filter($oneRun['mail'], static fn (array $m): bool => str_starts_with((string) $m['subject'], '[' . $nameOne . ']') === false));
ok(
    'N20 the other organisations in the database cannot be opened in such an installation, so nobody is told about them, and the run does not throw',
    $oneRun['threw'] === '' && $others === [] && array_values(array_filter($oneRun['push'], static fn (array $p): bool => (int) $p['siteId'] !== ORG_ONE)) === [] && ($oneRun['out']['organisations'] ?? -1) === 1,
    json_encode([$oneRun['threw'], array_column($others, 'subject'), $oneRun['out']])
);
removeWaiting([$apprOne]);
q('DELETE FROM tblExternalApprovalNotices WHERE siteID = ?', 'i', [ORG_ONE]);
portal();

// =============================================================================
head('N21. The real senders, with neither mail nor push set up');
// =============================================================================
// The ONLY place this file lets the real senders be reached. It first
// proves that neither can send anything from this database.
forgetTold();
if (Mailer::canSend() === true || WebPush::isConfigured() === true) {
    ok('N21 the real senders with nothing set up', false, 'REFUSED: this database HAS a mail route or push set up, so the real senders were not run. Give the test a database built from the install script alone.');
    ok('N21 … and nothing is claimed', false, 'as above');
} else {
    $errorsBefore = (int) (one('SELECT COUNT(*) AS n FROM tblErrors')['n'] ?? 0);
    $real = ['threw' => '', 'out' => []];
    try {
        $real['out'] = ImportNotifier::run($mysqli, microtime(true) + 60, at(ZONE_A, $plain . ' 08:10:00'));
    } catch (Throwable $e) {
        $real['threw'] = get_class($e) . ': ' . $e->getMessage();
    }
    ok(
        'N21 with no mail route and no push set up, the real pass says neither is ready, does not throw, and writes no error line',
        $real['threw'] === '' && ($real['out']['mailReady'] ?? true) === false && ($real['out']['pushReady'] ?? true) === false && ($real['out']['emailed'] ?? -1) === 0
        && (int) (one('SELECT COUNT(*) AS n FROM tblErrors')['n'] ?? 0) === $errorsBefore,
        json_encode($real)
    );
    ok(
        'N21 and NOBODY IS CLAIMED for a route that cannot send: no row is written, so the first run after mail or push is set up tells people',
        (int) (one('SELECT COUNT(*) AS n FROM tblExternalApprovalNotices')['n'] ?? -1) === 0,
        json_encode(q('SELECT * FROM tblExternalApprovalNotices'))
    );
}

// =============================================================================
head('N22. No address to give: the link is left out, never guessed');
// =============================================================================
forgetTold();
portalAddressSetting('');
portal();
$noAddr = run(at(ZONE_A, $plain . ' 08:10:00'));
$bodyNo = (string) (mailAbout($noAddr, NAME_A)[0]['html'] ?? '');
ok(
    'N22 with no portal address set (and a job has no request to borrow one from): the message still goes, holds NO link at all, and says where to look instead',
    count(mailAbout($noAddr, NAME_A)) === 3 && str_contains($bodyNo, '<a ') === false && str_contains($bodyNo, 'href') === false
    && str_contains($bodyNo, 'sign in to the portal and open Approvals') === true && str_contains($bodyNo, 'Trustees closed session ZQXTA1') === true,
    mb_substr($bodyNo, 0, 600)
);
ok(
    'N22 the push then names no page: the service worker opens the portal\'s front page',
    count(pushFor($noAddr, ORG_A)) === 1 && array_key_exists('url', (array) pushFor($noAddr, ORG_A)[0]['payload']) === false,
    json_encode(pushFor($noAddr, ORG_A))
);
siteSetting(ORG_A, 'approvals.enabled', '0');
forgetTold();
$noAddrOff = run(at(ZONE_A, $plain . ' 08:10:00'));
siteSetting(ORG_A, 'approvals.enabled', '1');
ok(
    'N22 and when the Approvals app is off for A, the words send them to Admin, Outside calendars',
    str_contains((string) (mailAbout($noAddrOff, NAME_A)[0]['html'] ?? ''), 'open Admin, then Outside calendars') === true,
    mb_substr((string) (mailAbout($noAddrOff, NAME_A)[0]['html'] ?? ''), 0, 600)
);
portalAddressSetting(PORTAL_URL);
portal();
siteSetting(ORG_A, 'approvals.enabled', '0');
forgetTold();
$linkOff = run(at(ZONE_A, $plain . ' 08:10:00'));
siteSetting(ORG_A, 'approvals.enabled', '1');
ok(
    'N22 with an address and the Approvals app off for A: the link and the push lead to A\'s outside calendars page',
    str_contains((string) (mailAbout($linkOff, NAME_A)[0]['html'] ?? ''), '<a href="' . PORTAL_URL . '/' . KEY_A . '/admin/calendar/feeds">') === true
    && (pushFor($linkOff, ORG_A)[0]['payload']['url'] ?? '') === '/' . KEY_A . '/admin/calendar/feeds',
    json_encode([pushFor($linkOff, ORG_A)[0]['payload'] ?? null])
);

// =============================================================================
head('N23. More events than one message lists');
// =============================================================================
forgetTold();
$many = [];
for ($i = 1; $i <= 30; $i++) {
    $many[] = waitingEvent(ORG_A, FEED_A2, 'Bulk event ZQXTBULK' . sprintf('%02d', $i) . 'x', $wallT1, ZONE_A);
}
$bulk     = run(at(ZONE_A, $plain . ' 08:10:00'));
$bodyBulk = (string) (mailAbout($bulk, NAME_A)[0]['html'] ?? '');
$listed   = substr_count($bodyBulk, '<li>');
// 33 waiting: Hall bookings has 31 (its own one and the thirty), Youth diary 2.
ok(
    'N23 with 33 waiting, the message lists exactly ' . ImportNotifier::MAX_EVENTS_LISTED . ' events, the oldest of the first calendar first, and then says how many more there are',
    $listed === ImportNotifier::MAX_EVENTS_LISTED && str_contains($bodyBulk, 'and ' . (33 - ImportNotifier::MAX_EVENTS_LISTED) . ' more are waiting that are not listed here.') === true
    && str_contains($bodyBulk, 'Hall hire ZQXTA3') === true && str_contains($bodyBulk, 'ZQXTBULK19x') === true && str_contains($bodyBulk, 'ZQXTBULK20x') === false,
    'listed ' . $listed . '; ' . mb_substr(strip_tags($bodyBulk), 0, 400)
);
ok(
    'N23 the numbers are still the true ones: the subject says 33, each calendar its own number, and the link is there',
    (mailAbout($bulk, NAME_A)[0]['subject'] ?? '') === '[' . NAME_A . '] 33 imported events are waiting for approval' && str_contains($bodyBulk, CAL_A2 . ': 31 waiting') === true
    && str_contains($bodyBulk, CAL_A1 . ': 2 waiting') === true && str_contains($bodyBulk, '<a href="' . $expectLink . '">') === true,
    (string) (mailAbout($bulk, NAME_A)[0]['subject'] ?? '')
);
removeWaiting($many);

// =============================================================================
head('N24. A title with characters that mean something in HTML');
// =============================================================================
forgetTold();
$rawTitle = 'Tea & <script>alert("ZQXTSCRIPT")</script> \'cake\'';
$nasty    = waitingEvent(ORG_A, FEED_A1, $rawTitle, $wallT1, ZONE_A);
q('UPDATE tblExternalFeeds SET name = ? WHERE feedID = ?', 'si', ['Youth <b>diary</b> ZQXCA1', FEED_A1]);
$esc      = run(at(ZONE_A, $plain . ' 08:10:00'));
$bodyEsc  = (string) (mailAbout($esc, NAME_A)[0]['html'] ?? '');
q('UPDATE tblExternalFeeds SET name = ? WHERE feedID = ?', 'si', [CAL_A1, FEED_A1]);
removeWaiting([$nasty]);
ok(
    'N24 the title and the calendar\'s name are written as text: the message holds the escaped characters and no tag of theirs',
    str_contains($bodyEsc, htmlspecialchars($rawTitle, ENT_QUOTES, 'UTF-8')) === true && str_contains($bodyEsc, '<script') === false
    && str_contains($bodyEsc, 'Youth &lt;b&gt;diary&lt;/b&gt; ZQXCA1') === true && str_contains($bodyEsc, '<b>diary') === false,
    mb_substr($bodyEsc, 0, 700)
);

// =============================================================================
head('N25. Organisations told apart by their own address');
// =============================================================================
forgetTold();
q("UPDATE tblSites SET hostPattern = 'alpha.selftest.invalid' WHERE siteID = ?", 'i', [ORG_A]);
portal(['mode' => 'subdomain']);
$host = run(at(ZONE_A, $plain . ' 08:10:00'));
q('UPDATE tblSites SET hostPattern = NULL WHERE siteID = ?', 'i', [ORG_A]);
ok(
    'N25 A\'s link carries A\'s own host name, and its push a path with no organisation part (the device subscribed on that host)',
    str_contains((string) (mailAbout($host, NAME_A)[0]['html'] ?? ''), '<a href="https://alpha.selftest.invalid/approvals#imports">') === true
    && (pushFor($host, ORG_A)[0]['payload']['url'] ?? '') === '/approvals#imports',
    json_encode([pushFor($host, ORG_A)[0]['payload'] ?? null])
);
$bodyB = (string) (mailAbout(run(at(ZONE_B, $plain . ' 08:10:00')), NAME_B)[0]['html'] ?? 'no message');
ok(
    'N25 B has no address of its own stored, so B\'s message holds no link (any other address would open the wrong organisation) and says where to look',
    str_contains($bodyB, 'href') === false && str_contains($bodyB, 'sign in to the portal and open Approvals') === true && str_contains($bodyB, 'Beta secret ZQXTB1') === true,
    mb_substr($bodyB, 0, 500)
);
portal();

// =============================================================================
head('N27. The job\'s own organisation is put back, and the organisation in hand is the current one');
// =============================================================================
// The pass makes each organisation the current one for its turn, so that a
// kept message and its send-log line are filed under the right one. The job
// then carries on with other work, so its own must be the current one again
// whatever happened: after an ordinary run, after a fault ended a turn,
// after a deadline cut a turn short, after a run that never started, and
// after a run in which the table was missing. (Every run here is started
// with the first organisation as the job's own: see portal().)
$afterEach = ['an ordinary run' => $run5['siteAfter'], 'a turn ended by a fault in its e-mails' => $faultMail['siteAfter'], 'a turn ended by a fault in its push claims' => $faultPush['siteAfter'],
    'a turn cut short by its deadline' => $cut['siteAfter'], 'a push call that outlasted the deadline' => $slowPush['siteAfter'], 'a run whose deadline had passed' => $past['siteAfter'],
    'a run with the table missing' => $noTable['siteAfter']];
$notBack = array_keys(array_filter($afterEach, static fn (int $site): bool => $site !== ORG_ONE));
ok(
    'N27 after each of seven kinds of run the current organisation is the job\'s own again (the first organisation), not the last one the pass was working on',
    $notBack === [] && count($afterEach) === 7,
    'not put back after: ' . implode('; ', $notBack) . ' ' . json_encode($afterEach)
);
$siteOf   = [NAME_A => ORG_A, NAME_B => ORG_B, NAME_C => ORG_C, NAME_D => ORG_D, $nameOne => ORG_ONE];
$notInHand = [];
foreach ($allMail as $m) {
    $name = preg_match('/\A\[(.*?)\] \d+ imported/', (string) $m['subject'], $found) === 1 ? $found[1] : '';
    if (($siteOf[$name] ?? -1) !== $m['siteNow']) {
        $notInHand[] = 'a message about "' . $name . '" was sent while organisation ' . $m['siteNow'] . ' was current';
    }
}
foreach ($allPush as $p) {
    if ((int) $p['siteId'] !== $p['siteNow']) {
        $notInHand[] = 'a push for ' . $p['siteId'] . ' was sent while organisation ' . $p['siteNow'] . ' was current';
    }
}
ok(
    'N27 in every run of this test, each message and each push was sent while ITS OWN organisation was the current one (' . count($allMail) . ' messages and ' . count($allPush) . ' pushes read)',
    $notInHand === [] && count($allMail) > 50 && count($allPush) > 20,
    implode(' | ', array_slice(array_unique($notInHand), 0, 4))
);

// =============================================================================
head('N28. The pass\'s own clock, on a database connection whose time zone is not UTC');
// =============================================================================
// Every other run in this file is handed its moment. The scheduled job hands
// none: the pass then reads the database's clock itself, and must read its
// UTC clock (UTC_TIMESTAMP()), not its local one (NOW()). On a server whose
// own time zone is UTC the two are the same, which is how a wrong reading
// passed every test (the independent check planted it, on a server set to
// -04:00). So this connection is set twelve hours away from UTC.
//
// "Now" is the real now here, so the organisation is given, for this check,
// a zone in which it is now between 09:00 and 15:00: after 07:00, and far
// from midnight. Read twelve hours wrong, that is either yesterday evening
// (a message, with yesterday as its day) or the small hours (no message at
// all). Either way the day claimed is not today's.
$dbNow  = new DateTimeImmutable((string) (one('SELECT UTC_TIMESTAMP() AS t')['t'] ?? 'now'), new DateTimeZone('UTC'));
$midday = '';
foreach (['Pacific/Kiritimati', 'Pacific/Auckland', 'Asia/Tokyo', 'Asia/Kolkata', 'Europe/London', 'America/Sao_Paulo', 'America/New_York',
    'America/Los_Angeles', 'Pacific/Honolulu', 'Pacific/Pago_Pago'] as $candidate) {
    $hour = (int) $dbNow->setTimezone(new DateTimeZone($candidate))->format('G');
    if ($hour >= 9 && $hour < 15) {
        $midday = $candidate;
        break;
    }
}
if ($midday === '') {
    ok('N28 with no moment handed in, the day claimed is the organisation\'s true day', false, 'no zone in this file\'s list is between 09:00 and 15:00 at ' . $dbNow->format('H:i') . ' UTC, so nothing could be tried');
    ok('N28 the two "moment in UTC" columns are written in UTC', false, 'as above');
} else {
    $trueDay = $dbNow->setTimezone(new DateTimeZone($midday))->format('Y-m-d');
    q('UPDATE tblSites SET timezone = ? WHERE siteID = ?', 'si', [$midday, ORG_A]);
    portal(); // a new request: Site remembers a zone for the length of one
    forgetTold([ORG_A]);
    $mysqli->query("SET time_zone = '-12:00'");
    $localClock = (string) (one('SELECT NOW() AS t')['t'] ?? '');
    $own        = run(null);
    $moments    = one(
        'SELECT TIMESTAMPDIFF(SECOND, emailAt, UTC_TIMESTAMP()) AS mail, TIMESTAMPDIFF(SECOND, pushAt, UTC_TIMESTAMP()) AS push FROM tblExternalApprovalNotices WHERE siteID = ? AND userID = ?',
        'ii', [ORG_A, A_ADM1]
    );
    $mysqli->query('SET time_zone = @@global.time_zone');
    $claimedDays = array_map(static fn (int $u): ?string => told(ORG_A, $u)['emailLocalDate'] ?? null, $adminsA);
    q('UPDATE tblSites SET timezone = ? WHERE siteID = ?', 'si', [ZONE_A, ORG_A]);
    portal();
    ok(
        'N28 with no moment handed in, on a connection whose own clock reads ' . substr($localClock, 0, 16) . ' while it is ' . $dbNow->format('Y-m-d H:i') . ' UTC: A (given the zone '
        . $midday . ' for this check, where it is the middle of the day) is e-mailed, and the day claimed for each person is its true day there, ' . $trueDay,
        $own['threw'] === '' && sentTo($own, NAME_A) === addresses($adminsA) && $claimedDays === [$trueDay, $trueDay, $trueDay]
        && abs((new DateTimeImmutable($localClock, new DateTimeZone('UTC')))->getTimestamp() - $dbNow->getTimestamp()) > 11 * 3600,
        'e-mailed ' . json_encode(sentTo($own, NAME_A)) . '; days claimed ' . json_encode($claimedDays)
    );
    ok(
        'N28 the two "moment in UTC" columns were written in UTC on that connection: each is within five minutes of the database\'s UTC clock, not twelve hours out',
        $moments !== null && (int) $moments['mail'] >= 0 && (int) $moments['mail'] <= 300 && (int) $moments['push'] >= 0 && (int) $moments['push'] <= 300,
        'seconds between each column and the UTC clock: ' . json_encode($moments)
    );
}

// =============================================================================
head('N17. Nothing that is logged names anybody or anything');
// =============================================================================
$kinds = [];
foreach ([$run5['out'], $run13['out'], $run14['out'], $noTable['out'], $past['out'], $faultMail['out'], $slowPush['out'], $run14n['out']] as $result) {
    foreach ($result as $key => $value) {
        if (is_int($value) === false && is_bool($value) === false) {
            $kinds[] = $key . ' is ' . gettype($value);
        }
    }
}
ok(
    'N17 what a run hands back to the job is whole numbers and yes/no only, so the job\'s line cannot hold an address, a name or a title',
    $kinds === [] && count($run5['out']) === 13,
    implode(', ', $kinds) . ' ' . json_encode(array_keys($run5['out']))
);
$errorRows    = q('SELECT * FROM tblErrors WHERE errorID > ?', 'i', [$state['firstErrorId']]);
$activityRows = q('SELECT * FROM tblActivityLogs WHERE logID > ?', 'i', [$state['firstActivityId']]);
$named = [];
foreach (array_merge($errorRows, $activityRows) as $row) {
    foreach (found((string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $neverLogged) as $hit) {
        $named[] = ($row['errorCode'] ?? $row['activityType'] ?? '?') . ' holds "' . $hit . '"';
    }
}
$ownRows = count(array_filter($errorRows, static fn (array $r): bool => ($r['errorPlatform'] ?? '') === 'ImportNotifier'));
ok(
    'N17 every error row and activity row written during this test (' . count($errorRows) . ' and ' . count($activityRows) . ') holds no "@", no "://", no person\'s name, '
    . 'no calendar\'s name, no organisation\'s name, and no event\'s title, place or description',
    $named === [] && $ownRows >= 2,
    implode(' | ', array_slice(array_unique($named), 0, 6)) . ($ownRows < 2 ? ' (fewer than two rows of the notifier\'s own were found, so there was too little to read)' : '')
);
$codes = array_values(array_unique(array_column($errorRows, 'errorCode')));
sort($codes);
ok(
    'N17 the two kinds of line it wrote are both there to be read (a failed send, and a turn ended by a fault), and it wrote no other kind',
    $codes === ['ImportNoticeEmailFailed', 'ImportNoticeOrganisationFailed'],
    json_encode($codes)
);
$dTold = array_values(array_filter($allMail, static fn (array $m): bool => str_contains($m['subject'] . $m['html'], 'ZQXOD') === true || str_contains($m['subject'] . $m['html'], 'ZQXTD') === true || $m['to'] === mailOf(D_ADM)));
ok(
    'N17 in every run of this test, the switched-off organisation D was never told about and its administrator was never sent anything (' . count($allMail) . ' messages and ' . count($allPush) . ' push calls read)',
    $dTold === [] && array_values(array_filter($allPush, static fn (array $p): bool => (int) $p['siteId'] === ORG_D)) === [] && count($allMail) > 50,
    json_encode(array_column($dTold, 'subject'))
);
$pushNamed = [];
foreach ($allPush as $p) {
    foreach (found((string) json_encode($p['payload'], JSON_UNESCAPED_SLASHES), array_merge($neverLogged, ['ZQXO'])) as $hit) {
        $pushNamed[] = $hit;
    }
}
ok('N17 no push in any run held a name, an address or a title', $pushNamed === [], implode(', ', array_unique($pushNamed)));

// =============================================================================
head('N29. Two calendars that commit out of order (#643, recorded as intended)');
// =============================================================================
// WHAT THIS PINS, and it pins a LIMIT rather than a feature, so read the
// reasoning before changing it.
//
// An approval's number comes from the database's own counter, which hands one
// out when the row is INSERTED, not when the change is COMMITTED. Each import
// runs in a long transaction holding only ITS OWN calendar's row lock
// (FeedImporter opens the transaction, takes the lock, and commits at the end),
// so two calendars importing at once can take numbers 100 and 101 and commit in
// the other order. Verified against the table and the importer on 28 October
// 2026: `approvalID INT NOT NULL AUTO_INCREMENT`, and nothing orders the two
// commits.
//
// `waiting()` reports `newestId` as the highest waiting number ACROSS the
// organisation's calendars, and `claimPush()` raises the mark only when it is
// still lower (`WHERE pushUpToApprovalID < ?`). So:
//
//   1. calendar A inserts approval 100; its transaction is still open;
//   2. calendar B inserts 101 and commits;
//   3. the job runs, sees 101, pushes, records "told up to 101";
//   4. calendar A commits. 100 is waiting — and 100 < 101, so no push.
//
// THE OWNER CHOSE TO ACCEPT THIS on 28 October 2026, after the premise was
// confirmed, and the reason is the house rule: NEVER TWICE COMES BEFORE ALWAYS
// DELIVERED. Every way of closing it risks a second push for an event somebody
// has already been told about, which is worse than what happens here — and what
// happens here loses nothing. The event is in the approvals list, the admin
// page's counts include it, and the next day's e-mail names it, because the
// e-mail is claimed by LOCAL DATE and not by approval number. The checks below
// hold all three of those, so a future change cannot quietly turn this into a
// lost event either.
//
// WHAT THESE CHECKS DO AND DO NOT PIN — measured, because the first version of
// this comment implied more.
//
// They state the behaviour truthfully and they hold the three "nothing is lost"
// guarantees, and THOSE are the load-bearing ones: if any change ever drops the
// late approval, stops counting it, or leaves it out of the next day's e-mail,
// one of them fails. That is the line between "a push is skipped" (accepted)
// and "an event is hidden" (not).
//
// They are NOT a tripwire against somebody redesigning the mark. Three plants
// were tried and none was caught: loosening `claimPush()`'s condition from `<`
// to `<>`, removing that condition altogether, and loosening the push gate from
// `<` to `!==`. All three leave this scenario unchanged, and the reason is
// structural rather than a gap in the test — the mark only ever RISES, and the
// gate compares it with the HIGHEST waiting number, which in this scenario is
// already the mark. So no change that still compares against "the highest
// number" can alter the outcome.
//
// A real fix for #643 would therefore have to change the data model — a mark
// per calendar, a mark per approval, or a moment instead of a number — and it
// would need its own checks. Do not read a green N29 as permission to change
// the mark: read it as "the current behaviour is still what was agreed, and
// nothing is being hidden".
//
// The one constraint that does not move: the fix must not be able to push twice.
// See #643.
forgetTold();

// 🕳️ Leave a gap in the numbering, so there is a number BELOW the one the job
//    is about to be told. That is what a late commit looks like from here.
$lateNumber = $nextApproval++;

$n29Early = waitingEvent(ORG_A, FEED_A1, 'Committed first ZQXN29A', $wallT1, ZONE_A);
$n29Run1  = run(at(ZONE_A, '2027-02-03 03:00:00'));
ok(
    'N29 the calendar that commits first is pushed about, and the mark is its number',
    count(pushFor($n29Run1, ORG_A)) === 1
        && (int) (told(ORG_A, A_ADM1)['pushUpToApprovalID'] ?? 0) === $n29Early,
    'pushes=' . json_encode(pushFor($n29Run1, ORG_A)) . ' told=' . json_encode(told(ORG_A, A_ADM1))
);

// 🐌 Now the other calendar commits, with its LOWER number.
$nextApproval = $lateNumber;
$n29Late      = waitingEvent(ORG_A, FEED_A2, 'Committed late ZQXN29B', $wallT1, ZONE_A);
$nextApproval = $n29Early + 1;
ok(
    'N29 the late approval really does carry a LOWER number than the mark',
    $n29Late < $n29Early,
    'late=' . $n29Late . ' early=' . $n29Early
);

$n29Run2 = run(at(ZONE_A, '2027-02-03 03:05:00'));
ok(
    'N29 no second push: the late one is skipped, which is the accepted limit',
    pushFor($n29Run2, ORG_A) === [],
    json_encode($n29Run2['push'])
);
ok(
    'N29 and the mark is NOT lowered to it, so nothing is pushed about twice later',
    (int) (told(ORG_A, A_ADM1)['pushUpToApprovalID'] ?? 0) === $n29Early,
    json_encode(told(ORG_A, A_ADM1))
);

// 🛟 NOTHING IS LOST. These three are what make the limit acceptable, so they
//    matter more than the two above: if any of them ever fails, the behaviour
//    has stopped being "a push is skipped" and become "an event is hidden".
// Measured against this organisation's OWN earlier events rather than an
// absolute total: earlier sections leave waiting events here on purpose, and a
// check that assumed otherwise would fail for a reason that has nothing to do
// with #643 (it did, first time round — the total was 5, not 2).
$n29Waiting = \Portal\Core\ImportNotifier::waiting($mysqli, ORG_A);
$n29LateRow = one('SELECT status FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [$n29Late]);
ok(
    'N29 the late approval is still WAITING — the push is skipped, the event is not',
    ($n29LateRow['status'] ?? '') === 'pending',
    json_encode($n29LateRow)
);
ok(
    'N29 and the count the admin page shows includes it',
    $n29Waiting['total'] >= 2
        && array_sum(array_map(static fn (array $c): int => $c['n'], $n29Waiting['byCalendar'])) === $n29Waiting['total'],
    json_encode($n29Waiting)
);
ok(
    'N29 and its calendar is still named in the breakdown the admin page uses',
    count(array_filter($n29Waiting['byCalendar'], static fn (array $c): bool => $c['feedID'] === FEED_A2)) === 1,
    json_encode($n29Waiting['byCalendar'])
);
// 📧 The e-mail is claimed per LOCAL DATE, not per approval number, so the next
//    day's message names the late event however low its number is. That is the
//    route by which nobody misses it.
$n29Run3 = run(at(ZONE_A, '2027-02-04 08:10:00'));
// The stand-in records the message as `html`, not `body`. Written out because
// the first version of this check read a key that does not exist, so it found
// nothing and read as a real failure of the e-mail.
$n29Mail = array_values(array_filter($n29Run3['mail'], static fn (array $m): bool => str_contains((string) ($m['html'] ?? ''), 'ZQXN29B')));
ok(
    'N29 the NEXT DAY\'s e-mail names the late event, so it is never missed',
    count($n29Mail) >= 1,
    'mails=' . count($n29Run3['mail']) . ' naming it=' . count($n29Mail)
);

removeWaiting([$n29Early, $n29Late]);
forgetTold();

// =============================================================================
head('N19. The migration');
// =============================================================================
$showTable = static function () use ($mysqli): string {
    $row = $mysqli->query('SHOW CREATE TABLE tblExternalApprovalNotices')->fetch_row();
    // The next number to hand out is not part of the table's shape.
    return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) ($row[1] ?? ''));
};
forgetTold();
$fromInstall = $showTable();
$migrationsBefore = (int) (one('SELECT COUNT(*) AS n FROM tblMigrations')['n'] ?? 0);
$twiceProblem = '';
try {
    runMigration();
    runMigration();
    if ($mysqli->errno !== 0) {
        $twiceProblem = $mysqli->error;
    }
} catch (Throwable $e) {
    $twiceProblem = get_class($e) . ': ' . $e->getMessage();
}
ok(
    'N19 the migration run twice over the installed table changes nothing: the same table, the same list of migrations, no error',
    $twiceProblem === '' && $showTable() === $fromInstall && (int) (one('SELECT COUNT(*) AS n FROM tblMigrations')['n'] ?? 0) === $migrationsBefore
    && (int) (one("SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = '218_external_approval_notices.sql'")['n'] ?? 0) === 1,
    $twiceProblem
);
// Now let the MIGRATION make the table, as it does on an upgraded database.
$mysqli->query('DROP TABLE tblExternalApprovalNotices');
q("DELETE FROM tblMigrations WHERE filename = '218_external_approval_notices.sql'");
$madeProblem = '';
try {
    runMigration();
} catch (Throwable $e) {
    $madeProblem = get_class($e) . ': ' . $e->getMessage();
}
$fromMigration = $showTable();
ok(
    'N19 the table the MIGRATION makes is the same, word for word, as the one the install script makes, and the migration records itself',
    $madeProblem === '' && $fromMigration === $fromInstall && $fromMigration !== ''
    && (int) (one("SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = '218_external_approval_notices.sql'")['n'] ?? 0) === 1,
    $madeProblem . "\n" . $fromMigration . "\n-- install script:\n" . $fromInstall
);
$dbCollation = (string) (one('SELECT @@collation_database AS c')['c'] ?? '');
$tableColl   = (string) (one("SELECT TABLE_COLLATION AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblExternalApprovalNotices'")['c'] ?? '');
$columnColl  = (string) (one("SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblExternalApprovalNotices' AND COLUMN_NAME = 'emailOutcome'")['c'] ?? '');
if ($dbCollation === 'utf8mb4_general_ci') {
    skipped(
        'N19 the table\'s collation on a database made with no collation of its own',
        'This database\'s own collation is already utf8mb4_general_ci, so a table that lost its COLLATE would look the same. Make the database with a plain CREATE DATABASE on a MySQL 8 server.'
    );
} else {
    ok(
        'N19 on a database whose own collation is ' . $dbCollation . ', the table the migration makes is utf8mb4_general_ci, and so is its one text column',
        $tableColl === 'utf8mb4_general_ci' && $columnColl === 'utf8mb4_general_ci',
        'table: ' . $tableColl . '; column: ' . $columnColl
    );
}
$links = q(
    "SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k "
    . "JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME "
    . "WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'tblExternalApprovalNotices' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME"
);
ok(
    'N19 its two links go with the person and with the organisation: deleting either removes the row',
    array_map(static fn (array $l): array => [$l['COLUMN_NAME'], $l['REFERENCED_TABLE_NAME'], $l['DELETE_RULE']], $links) === [['siteID', 'tblSites', 'CASCADE'], ['userID', 'tblUsers', 'CASCADE']],
    json_encode($links)
);

// =============================================================================
head('Everything is put back');
// =============================================================================
cleanup();
$left = (int) (one(
    'SELECT (SELECT COUNT(*) FROM tblSites WHERE siteID IN (?, ?, ?, ?)) + (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 930000 AND 930999) '
    . '+ (SELECT COUNT(*) FROM tblExternalFeeds WHERE feedID BETWEEN 930000 AND 930999) + (SELECT COUNT(*) FROM tblEvents WHERE eventID BETWEEN 930000 AND 939999) '
    . "+ (SELECT COUNT(*) FROM tblExternalEventApprovals WHERE status = 'pending') + (SELECT COUNT(*) FROM tblExternalApprovalNotices) "
    . "+ (SELECT COUNT(*) FROM tblErrors WHERE errorPlatform = 'ImportNotifier') AS n",
    'iiii', [ORG_A, ORG_B, ORG_C, ORG_D]
)['n'] ?? -1);
$urlBack = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'site.url' AND siteID IS NULL")['settingValue'] ?? null;
ok(
    'clean-up: the made-up world is gone, the notices table is in place and empty, and the portal\'s address setting is as it was',
    $left === 0 && $urlBack === $state['siteUrl'],
    'rows left: ' . $left . '; site.url now ' . json_encode($urlBack) . ', was ' . json_encode($state['siteUrl'])
);

echo "\n{$pass} passed, {$fail} failed, {$skip} skipped; " . ($pass + $fail + $skip) . ' ran of ' . EXPECTED_CHECKS . " expected.\n";
if (($pass + $fail + $skip) !== EXPECTED_CHECKS) {
    echo "FAIL — the number of checks that ran is not the number this file expects. A block was skipped or ran twice,\n";
    echo "or a check was added without updating the number at the top.\n";
    exit(1);
}
if ($fail > 0) {
    echo "FAIL — {$fail} check(s) failed. Read the FAIL lines above.\n";
    exit(1);
}
if ($skip > 0) {
    echo "PASS, BUT {$skip} CHECK(S) COULD NOT BE MADE AND ARE NOT PROVEN: " . implode('; ', $notProven) . ".\n";
    exit(0);
}
echo "PASS — all {$pass} checks passed, and none was skipped.\n";
exit(0);
