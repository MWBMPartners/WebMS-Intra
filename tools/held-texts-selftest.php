<?php
// Path: tools/held-texts-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A text held for quiet hours — self-test 📱🕯️🧪 (#610)
 * -----------------------------------------------------------------------------
 * A text message that is not urgent is not sent to a person who is inside
 * their Sabbath quiet hours. It used to be recorded as "queued" and then
 * never looked at again. Now `Portal\Core\Sms` holds it, and the scheduled
 * job (`web/_apps/cron/mail-queue.php`) sends it once those quiet hours have
 * ended, or records it as not sent.
 *
 * Text messages cost money and land on phones, and every way of getting this
 * wrong is silent: a text sent twice, a text about "this morning" sent the
 * next evening, months of old held texts all sent by the first run after an
 * upgrade. None of those makes a page fail. So this file builds a small
 * made-up world in a throwaway database, runs the REAL class at moments it
 * chooses, records what WOULD be handed to the provider, and reads the
 * database.
 *
 * IT SENDS NOTHING, IN TWO WAYS AT ONCE
 *   - Every check that hands a text over does it through `RecordingSms`
 *     below, which extends the real class and puts a recorder where the
 *     provider is. Everything else is the real class's own code.
 *   - The settings this file gives the portal name NO provider account (no
 *     account number, no key, no "from" number). So the real class, which
 *     part J runs through the real job page, stops before it contacts
 *     anybody: its own answer is "not configured".
 *
 * THE CLOCK IS THE DATABASE'S, AND THIS FILE SETS IT
 *   The class asks the database for the moment, for everything (see
 *   Sms::nowOnDatabaseClock()). MySQL lets one connection be told what the
 *   moment is (`SET timestamp`), so each check runs at an exact moment, and
 *   none depends on when this file is run.
 *   EVERY CONNECTION THIS FILE MAKES IS PUT IN A TIME ZONE THAT IS NOT UTC
 *   AND THAT CHANGES ITS CLOCKS (Pacific/Auckland, and on the clock-change
 *   nights the zone being tested). That is what NOW() and CURDATE() read.
 *   A stored moment that was written by the wrong clock is then wrong by
 *   hours, and a check fails. PHP's own zone is set to a third one
 *   (America/St_Johns), which nothing may depend on.
 *
 * EVERY DATE IS WORKED OUT FROM TODAY. The "ordinary" weekend is the first
 * one at least three days ahead that is nowhere near a clock change. The
 * clock-change nights are found in PHP's own time-zone data for the next
 * two years, never written down (two, not one: see clockChanges() for the
 * day in October 2026 when one year was not enough). A test written in December passes in
 * London whatever the code does, because London is on UTC that month; that
 * is why each change night is found, and why Santiago is here as well.
 *
 * THE MADE-UP WORLD
 *   One organisation (and a second, for the limit), whose quiet hours are
 *   Friday 18:00 to Saturday 18:00 on a clock in Europe/London, and people
 *   who each have a confirmed number for texts:
 *     follow    follows the portal's quiet hours
 *     on        has chosen "always"
 *     off       has switched quiet hours off for themselves
 *     changed   changes their number while a text waits
 *     optout    stops texts of that kind while one waits
 *     gone      whose account is deleted while a text waits
 *     switch    switches quiet hours off while a text waits
 *     verify    asks for a code to confirm a number
 *     member    is a member of the organisation, with a membership row
 *     leaver    whose membership is switched off while a text waits
 *     global    a global administrator with no membership row
 *     offacct   whose account is switched off while a text waits
 *   Nobody has a membership row until part H's last checks: on an
 *   installation with one organisation nobody needs one, and that is how
 *   every other part runs.
 *   The numbers are in the range the UK keeps for television drama
 *   (07700 900xxx), which belongs to nobody.
 *
 * WHAT IT CHECKS (each must fail when its behaviour is broken; the faults
 * that were planted to prove that are listed in the build's evidence)
 *   A  held, then sent ONCE when the quiet hours end; the record at each step
 *   B  never held: a critical alert, a code to confirm a number, an
 *      EMERGENCY NOTICE (the owner's decision of 13 October 2026), a person
 *      who switched quiet hours off, quiet hours off for everybody, a text
 *      for no named person
 *   C  the use-by moment: by kind, from the caller, never more than 48
 *      hours; not readable or already past means not kept; a text past it
 *      is recorded as not sent and never handed over
 *   D  never twice: a second run started inside the first (and one that is
 *      ended while handing a text over), two processes at the same moment,
 *      the lock, a run ended while handing a text over, a text the
 *      provider refused
 *   E  texts held BEFORE this change are never sent: by the job, and by the
 *      next text the portal handles when no job is running; and a text a
 *      dead run had taken is settled by that next text too
 *   F  new files on a database that has not been upgraded; then the
 *      migration, which is safe to run twice and leaves the table exactly
 *      as the install script makes it
 *   G  never a flood: the most in one run (a refused text counts), the
 *      time limit, and the day's limit counted on the day a text is SENT
 *   H  what is true of the person when it comes due: number (changed, or
 *      no longer confirmed), kind, a deleted account, quiet hours switched
 *      off, texts switched off for the installation; and who may still be
 *      sent an organisation's text: an account switched off (and one
 *      switched on again before the text comes due), a person who left, an
 *      organisation switched off, a global administrator, an installation
 *      with one organisation, where nobody has a membership, and a run in
 *      which the question cannot be asked at all
 *   I  a restored backup: held texts that come back are recorded as not
 *      sent, in a rehearsal nothing changes, through the real backup class;
 *      and putting back ANOTHER table leaves the waiting texts alone
 *   J  the real job page: the token, its own numbers in the one JSON
 *      answer (plain numbers only), that neither step can stop the other,
 *      20 texts a call, and that the mail step comes first
 *   K  both clock-change nights, in London and in Santiago, with the
 *      database's clock in that same zone
 *   L  the words, the month's cost, that nothing written names anybody,
 *      and two sentences people are shown: the Notification preferences
 *      page (the real lines of the page, with and without a scheduled
 *      job), the broadcast page ("held", not "skipped"), the list of
 *      recent texts (the moment a text was handed over), Account, SMS and
 *      the broadcast page's first paragraph; and every sentence the class
 *      records, against its copy written out in this file
 *
 * WHAT THIS CANNOT PROVE
 *   - A real text-message provider. No text leaves the machine. What Twilio,
 *     MessageBird or AWS do with a text, and what they charge, is not
 *     tested here or anywhere.
 *   - The job page reached over HTTP through the router. Part J runs the
 *     real page FILE in a process of its own, with the portal's real
 *     classes, but without the router and the sign-in gate in front of it.
 *     `tools/mail-queue-selftest.php` and `tools/mail-queue-pages-selftest.php`
 *     call that page over HTTP, and since this change they run the texts
 *     step every time they do.
 *   - Two runs in two processes at exactly the same instant. D2 starts two
 *     processes on one signal and D1 starts a second run INSIDE the first,
 *     at the one point where it matters; neither can order two statements
 *     to the microsecond.
 *   - Whether a late text is still TRUE. The portal cannot read a text; the
 *     lengths in Sms::HELD_USE_WITHIN are a judgement by kind.
 *   - MariaDB. Run on MySQL 8.0.36 and 8.4.11.
 *
 * SAFETY
 *   - It writes rows, so it REFUSES unless the database's name starts with
 *     `selftest_`. Give it a database of its own, built from
 *     `web/_sql/full_schema.sql`. It refuses a database that already holds a
 *     text message that is not its own, because the class works through
 *     every held text it finds.
 *   - Every row it makes belongs to organisation 934001 or 934002, or to a
 *     person numbered 934100 to 934999. They are removed at the START (in
 *     case an earlier run was interrupted) and at the END.
 *   - Part F DROPS the two new columns and the key from tblSmsMessage for a
 *     moment and puts them back by running the migration. Part I takes a
 *     backup of the whole database and puts it back. Part J renames two
 *     tables for a moment (and part H a third, the table of memberships),
 *     and for one check puts a TRIGGER on the table of
 *     texts (`selftest_held_texts_hook`) that writes to a table of its own
 *     (`selftestHeldTextsProbe`), so the database account must be allowed
 *     to make a trigger (the root account of a throwaway server is). Part
 *     H switches one of its own accounts and its own organisation off and
 *     on again. All are put back, by the clean-up too.
 *   - It never loads `bootstrap.php` and never reads `web/_auth_keys`.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_texts SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/held-texts-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root). SELFTEST_WEB_DIR runs it against another copy of `web/`
 *         (that is how a fault is planted without touching the checkout).
 *         Needs `proc_open()`.
 * Exit:   0 only when every check ran, none failed, and the last line says
 *         the run reached its end; 1 otherwise, including every refusal.
 *
 * @package   Portal\Tools
 * @author    Salem874 (MWBM Partners Ltd, t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/610
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 117;

/** What a child process prints before its findings, so they can be found in whatever else it printed. */
const RESULT_MARK = '@@held-texts-selftest@@';

// 🧯 Anything thrown outside a check becomes a plain FAIL line and exit 1,
//    not a stack trace. Registered before any class file is loaded.
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

$sep     = DIRECTORY_SEPARATOR;
$repoDir = dirname(__DIR__);
$webDir  = getenv('SELFTEST_WEB_DIR') !== false && (string) getenv('SELFTEST_WEB_DIR') !== '' ? rtrim((string) getenv('SELFTEST_WEB_DIR'), $sep) : $repoDir . $sep . 'web';
$coreDir = $webDir . $sep . '_core' . $sep;
if (is_file($coreDir . 'Sms.php') === false) {
    echo "REFUSED — no _core/Sms.php under '{$webDir}'. Nothing was checked.\n";
    exit(1);
}
// Some of the portal's classes read this.
define('PORTAL_CORE', rtrim($coreDir, $sep));

// PHP's own zone: a third one, half an hour off the hour. Nothing under test
// may depend on it.
date_default_timezone_set('America/St_Johns');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** One connection to the throwaway database, in the given time zone. */
function connect(string $zone): mysqli
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
    $stmt = $link->prepare('SET time_zone = ?');
    $stmt->bind_param('s', $zone);
    $stmt->execute();
    $stmt->close();

    return $link;
}

/** The organisation every text here belongs to, a second one, and the installation's own first. */
const ORG     = 934001;
const ORG2    = 934002;
const ORG_ONE = 1;

// =============================================================================
// 🧒 THE CHILD THAT RUNS THE REAL JOB PAGE (part J)
// =============================================================================
// This same file, run as `php held-texts-selftest.php --child`, with what to
// do in the SELFTEST_JOB variable. It loads the portal's own classes one by
// one as they are asked for, never through `bootstrap.php` (which reads the
// real `web/_auth_keys/` and would connect to the developer's own portal),
// then runs the real page file at the top level, as the router does. The
// page prints its one line and ends the process itself.
$job = ($argv[1] ?? '') === '--child' ? (array) json_decode((string) getenv('SELFTEST_JOB'), true) : [];
if (($job['task'] ?? '') === 'page') {
    ini_set('log_errors', '1');
    ini_set('error_log', (string) $job['errorLog']);
    spl_autoload_register(static function (string $class) use ($coreDir, $job): void {
        $prefix = 'Portal\\Core\\';
        if (str_starts_with($class, $prefix) === false) {
            return;
        }
        $name = substr($class, strlen($prefix));
        // `missing` stands for a half-finished upload: the class file is not there.
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1 || in_array($name, (array) ($job['missing'] ?? []), true) === true) {
            return;
        }
        if (is_file($coreDir . $name . '.php') === true) {
            require_once $coreDir . $name . '.php';
        }
    });
    // The mail step tidies a folder of attached files under this. It is given
    // an empty scratch folder, so that it can never look at a real one.
    define('PORTAL_ROOT', (string) $job['root']);
    $mysqli = connect((string) $job['zone']);
    $mysqli->query('SET timestamp = ' . (int) $job['timestamp']);
    $SETTINGS = (array) $job['settings'];
    \Portal\Core\App::init($mysqli, $SETTINGS);
    \Portal\Core\Site::reset();
    \Portal\Core\Site::init($mysqli, $SETTINGS, ORG_ONE);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/cron/mail-queue?token=' . (string) $job['token'];
    $_SERVER['QUERY_STRING']   = 'token=' . (string) $job['token'];
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_GET['token']             = (string) $job['token'];
    $_REQUEST['token']         = (string) $job['token'];
    require $webDir . $sep . '_apps' . $sep . 'cron' . $sep . 'mail-queue.php';
    exit(0); // not reached: the page ends the process
}

// The classes, one by one, never through `bootstrap.php`: see above. This is
// everything Sms::send() and Sms::sendHeld() can reach (`Site.php` since the
// fix round: it is asked whether a person may still be sent an
// organisation's text). A missing one is a loud "class not found".
// `DbBackup.php` is for part I; `MailQueue.php` and `MailRoutes.php` are for
// the two sentences in part L that ask whether the scheduled job is running.
foreach (['App.php', 'Sabbath.php', 'Site.php', 'Sms.php', 'DbBackup.php', 'MailQueue.php', 'MailRoutes.php'] as $classFile) {
    require_once $coreDir . $classFile;
}

use Portal\Core\App;
use Portal\Core\DbBackup;
use Portal\Core\MailQueue;
use Portal\Core\Sabbath;
use Portal\Core\Site;
use Portal\Core\Sms;

/**
 * 📼 The real class, with a recorder where the provider is. Nothing else is
 * changed: `providerSend()` is the one method the real class lets a class
 * that extends it replace.
 */
final class RecordingSms extends Sms
{
    /** @var list<array{0: string, 1: string}> Every text handed over: its number and its words. */
    public static array $calls = [];

    /** @var string What the "provider" does: 'accept', 'refuse', or 'throw' (the run is ended in the middle). */
    public static string $answer = 'accept';

    /** @var \Closure|null Run once, inside the next hand-over: how a second run is started in the middle of the first. */
    public static ?\Closure $during = null;

    /** @var string A file to add one line to for each text handed over (two processes share it in D2). */
    public static string $file = '';

    /** @var int How long each hand-over takes, in microseconds, so that two processes overlap. */
    public static int $slow = 0;

    protected static function providerSend(string $provider, array $settings, string $number, string $body): array
    {
        self::$calls[] = [$number, $body];
        if (self::$file !== '') {
            file_put_contents(self::$file, json_encode([$number, $body]) . "\n", FILE_APPEND | LOCK_EX);
        }
        if (self::$slow > 0) {
            usleep(self::$slow);
        }
        if (self::$during !== null) {
            $hook         = self::$during;
            self::$during = null;
            $hook();
        }
        if (self::$answer === 'throw') {
            // The words carry the number on purpose: L checks they are never written down.
            throw new RuntimeException('the run was ended while handing over a text for ' . $number);
        }
        if (self::$answer === 'refuse') {
            return ['ok' => false, 'ref' => null, 'costPence' => null, 'error' => 'twilio-http-500'];
        }

        return ['ok' => true, 'ref' => 'SMZQX' . count(self::$calls), 'costPence' => 4, 'error' => null];
    }
}

// =============================================================================
// 🧒 THE CHILD THAT IS ONE OF TWO RUNS AT THE SAME MOMENT (D2)
// =============================================================================
if (($job['task'] ?? '') === 'race') {
    $mysqli = connect((string) $job['zone']);
    $mysqli->query('SET timestamp = ' . (int) $job['timestamp']);
    $SETTINGS = (array) $job['settings'];
    App::init($mysqli, $SETTINGS);
    Site::reset();
    Site::init($mysqli, $SETTINGS, ORG_ONE);
    RecordingSms::$file = (string) $job['file'];
    RecordingSms::$slow = 4000;
    // 🚦 Wait for the starting signal, so that both begin together.
    $until = microtime(true) + 30;
    while (is_file((string) $job['waitFor']) === false && microtime(true) < $until) {
        usleep(500);
    }
    $out = ['sent' => 0, 'failed' => 0, 'notSent' => 0];
    // WITHOUT the one-run-at-a-time lock: the claim alone must be enough.
    RecordingSms::sendDue($mysqli, (array) $SETTINGS['sms'], 1000, microtime(true) + 60, $out);
    echo RESULT_MARK . json_encode($out) . "\n";
    exit(0);
}

// =============================================================================
// 🧾 Counting, and small tools
// =============================================================================
/** The zone every ordinary connection here is in: not UTC, and it changes its clocks. */
const DB_ZONE = 'Pacific/Auckland';
/** The zone the quiet hours are worked out in. */
const QUIET_ZONE = 'Europe/London';
/** The second zone for the clock-change nights: its clocks change at midnight, on a Saturday night. */
const ZONE_TWO = 'America/Santiago';
/** The job's token in this run. */
const JOB_TOKEN = 'selftest-held-texts-token-ZQX';
/** J11's trigger and the table it writes to. Not named `tbl…`, so no backup ever holds it. */
const PROBE_HOOK  = 'selftest_held_texts_hook';
const PROBE_TABLE = 'selftestHeldTextsProbe';

// `$mysqli` is a global on purpose: `App::db()` falls back to it.
$mysqli = connect(DB_ZONE);

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
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . mb_substr($detail, 0, 1800)) . "\n";
}

function head(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

/** A line that tells the reader something and is NOT a check. */
function note(string $text): void
{
    echo 'NOTE — ' . $text . "\n";
}

/** @param list<mixed> $params @return list<array<string,mixed>> */
function q(string $sql, string $types = '', array $params = [], ?mysqli $on = null): array
{
    global $mysqli;
    $stmt = ($on ?? $mysqli)->prepare($sql);
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

// =============================================================================
// 🗺️ The made-up world
// =============================================================================
/** @var array<string, int> The people, by what each is here for. */
const WHO = [
    'follow'  => 934101,
    'on'      => 934102,
    'off'     => 934103,
    'changed' => 934104,
    'optout'  => 934105,
    'gone'    => 934106,
    'switch'  => 934107,
    'verify'  => 934108,
    // The fix round's people: who may still be sent an organisation's text.
    'member'  => 934109, // a member of the organisation, with a membership row
    'leaver'  => 934110, // whose membership row is switched off while a text waits
    'global'  => 934111, // a global administrator with no membership row
    'offacct' => 934112, // whose ACCOUNT is switched off while a text waits
];
const EVERY_KIND = 'critical_alerts,rota_changes,emergency_comms,newsletter_digest,prayer_assignment';

/**
 * EVERY SENTENCE THE CLASS RECORDS, WRITTEN OUT HERE, word for word.
 *
 * The checks used to take these words from the class itself
 * (`Sms::NOT_SENT_...`). Two sentences swapped in the class then passed
 * every check: a switched-off account was recorded as "not a member" and
 * nothing noticed (the second check's fault N2). So the checks that are
 * about WHICH reason is recorded compare with these, and L9 holds every
 * one of the class's sentences to its copy here. Changing a sentence in the
 * class means changing it here, on purpose.
 *
 * @var array<string, string>
 */
const SAYS = [
    'SENT_AFTER_HOLD'      => 'Held for quiet hours, then sent when they had ended.',
    'NOT_SENT_USE_BY'      => 'Not sent: quiet hours. It was held, and its use-by moment passed before it could be sent.',
    'NOT_SENT_OLD'         => 'Not sent: quiet hours. It was held before the portal could send a held text afterwards, so it has no use-by moment and is never sent.',
    'NOT_SENT_NOT_KEPT'    => 'Not sent: quiet hours. It could not be kept for later: its use-by moment could not be read, or had already passed.',
    'NOT_SENT_NO_ROOM'     => 'Not sent: quiet hours. It could not be kept for later: the database has not been upgraded to hold a use-by moment.',
    'NOT_SENT_PERSON'      => 'Not sent: quiet hours. When they ended, the person no longer had an account.',
    'NOT_SENT_ACCOUNT_OFF' => 'Not sent: quiet hours. When they ended, the person\'s account had been switched off.',
    'NOT_SENT_NOT_MEMBER'  => 'Not sent: quiet hours. When they ended, the person was not a member of this organisation.',
    'NOT_SENT_ORG_OFF'     => 'Not sent: quiet hours. When they ended, the organisation had been switched off.',
    'NOT_SENT_NUMBER'      => 'Not sent: quiet hours. When they ended, this number was not the person\'s confirmed number for texts.',
    'NOT_SENT_OPTED_OUT'   => 'Not sent: quiet hours. When they ended, the person no longer wanted texts of this kind.',
    'NOT_SENT_RESTORED'    => 'Not sent: quiet hours. It came back with a restored backup: it was waiting when the backup was taken and may already have been sent since, so it is not sent again.',
    // The same four reasons as the NOT_SENT_* ones above, in the words for a
    // text sent AT ONCE: no quiet hours, and no "when they ended" (#610).
    'AT_ONCE_PERSON'       => 'Not sent: the person no longer has an account.',
    'AT_ONCE_ACCOUNT_OFF'  => 'Not sent: the person\'s account has been switched off.',
    'AT_ONCE_NOT_MEMBER'   => 'Not sent: the person is not a member of this organisation.',
    'AT_ONCE_ORG_OFF'      => 'Not sent: the organisation has been switched off.',
    'UNKNOWN_INTERRUPTED'  => 'Not known whether it was sent: a run of the scheduled job was ended while handing this held text to the provider. It is not sent again.',
];

/** A person's name. It must never be written anywhere by the code under test. */
function nameOf(int $userId): string
{
    return 'Textperson ZQXN' . $userId;
}

/** A person's number for texts: the UK's range for television drama, which belongs to nobody. */
function numberOf(int $userId): string
{
    return '+447700900' . str_pad((string) ($userId % 1000), 3, '0', STR_PAD_LEFT);
}

$state = ['built' => false, 'cleaned' => false, 'jobRow' => false, 'jobRan' => null, 'firstActivityId' => 0, 'tableText' => ''];

/** Take everything this test made out of the database, and put back what it changed. Safe to run when nothing was made. */
function removeWorld(): void
{
    global $mysqli, $webDir, $sep;
    $mysqli->query('SET timestamp = DEFAULT');
    // Part J renames two tables for a moment, and H12 a third; put them back first.
    foreach (['tblSmsMessage', 'tblMailQueue', 'tblUserSites'] as $table) {
        $hidden = $mysqli->query("SHOW TABLES LIKE '" . $table . "ZqHidden'");
        if ($hidden->num_rows > 0) {
            $mysqli->query('RENAME TABLE ' . $table . 'ZqHidden TO ' . $table);
        }
        $hidden->free();
    }
    // Part F drops the two new columns; the migration puts them back.
    $has = $mysqli->query("SHOW COLUMNS FROM tblSmsMessage LIKE 'heldUseBy'");
    if ($has->num_rows === 0) {
        runSqlFile($webDir . $sep . '_sql' . $sep . '219_held_texts.sql');
    }
    $has->free();
    // Locks this connection may still hold (D3, J3, J7).
    $mysqli->query("DO RELEASE_ALL_LOCKS()");
    // J11 puts a trigger on the table of texts for a moment, and keeps what
    // it saw in a table of its own.
    $mysqli->query('DROP TRIGGER IF EXISTS ' . PROBE_HOOK);
    $mysqli->query('DROP TABLE IF EXISTS ' . PROBE_TABLE);

    q('DELETE FROM tblSmsMessage WHERE siteID IN (?, ?) OR recipientUserID BETWEEN 934100 AND 934999', 'ii', [ORG, ORG2]);
    q('DELETE FROM tblUserSmsPreference WHERE siteID IN (?, ?) OR userID BETWEEN 934100 AND 934999', 'ii', [ORG, ORG2]);
    q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 934100 AND 934999 OR siteID IN (?, ?)', 'ii', [ORG, ORG2]);
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 934100 AND 934999 OR siteID IN (?, ?)', 'ii', [ORG, ORG2]);
    q('DELETE FROM tblUsers WHERE userID BETWEEN 934100 AND 934999');
    q('DELETE FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG, ORG2]);
}

/** Run one .sql file of this tree, as the upgrade page does: every statement, in order. */
function runSqlFile(string $file): void
{
    global $mysqli;
    $sql = (string) file_get_contents($file);
    if ($mysqli->multi_query($sql) === true) {
        do {
            $result = $mysqli->store_result();
            if (($result instanceof mysqli_result) === true) {
                $result->free();
            }
        } while ($mysqli->more_results() === true && $mysqli->next_result() === true);
    }
}

/** Put back what was changed outside the made-up world, ONCE. Also a shutdown function. */
function cleanup(): void
{
    global $state, $mysqli, $scratch;
    if ($state['built'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    removeWorld();
    // Part J's pages record "the mail job ran" and write activity lines.
    if ($state['jobRow'] === true) {
        q("UPDATE tblMailRouteState SET jobLastRanAt = ? WHERE routeKey = 'installation'", 's', [$state['jobRan']]);
    } else {
        q("DELETE FROM tblMailRouteState WHERE routeKey = 'installation'");
    }
    q("DELETE FROM tblActivityLogs WHERE logID > ? AND activityType IN ('HeldTextsRun', 'MailQueueRun')", 'i', [$state['firstActivityId']]);
    q("INSERT INTO tblMigrations (filename) VALUES ('219_held_texts.sql') ON DUPLICATE KEY UPDATE filename = filename");
    removeFolder($scratch);
}

/** Remove a scratch folder and everything in it. */
function removeFolder(string $dir): void
{
    if ($dir === '' || is_dir($dir) === false) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() === true ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

// =============================================================================
// 🕰️ Moments
// =============================================================================

/**
 * The clock changes of one zone in the next `$days` days, from PHP's own
 * time-zone data: the moment of each, and the offset from UTC before and
 * after it, in seconds.
 *
 * WHY THE WINDOW IS AN ARGUMENT, AND WHY PART K ASKS FOR TWO YEARS.
 * It used to be twelve months for every caller, and part K then failed for a
 * few days each year. A zone changes its clocks twice a year, so in any
 * twelve months there is normally one change of each kind -- but only if the
 * window does not open just after one of them. Run on 26 October 2026, the
 * day after Britain put its clocks back, the next "clocks go back" night in
 * Europe/London was 31 October 2027: five days beyond twelve months. Part K
 * found a "forward" night and no "back" night, and reported a fault in code
 * that was perfectly correct. Two years always holds at least two changes of
 * each kind, whatever the day it is run.
 *
 * `ordinarySaturday()` keeps the shorter window on purpose: it only looks
 * 400 days ahead for a quiet weekend, and a change further off than that
 * cannot disqualify a day inside it.
 *
 * @return list<array{ts:int, before:int, after:int}>
 */
function clockChanges(string $zone, int $days = 366): array
{
    $from  = time();
    $steps = (new DateTimeZone($zone))->getTransitions($from, $from + $days * 86400);
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
 * Friday 18:00 and Saturday 18:00 on a clock in `$zone`, for the weekend
 * whose Saturday is `$saturday` ('Y-m-d'). Worked out here with PHP's own
 * dates, sharing no code with Sabbath.
 *
 * @return array{0:int, 1:int}
 */
function weekendOf(string $saturday, string $zone): array
{
    $tz  = new DateTimeZone($zone);
    $sat = new DateTimeImmutable($saturday . ' 18:00:00', $tz);
    $fri = (new DateTimeImmutable($saturday . ' 12:00:00', $tz))->modify('-1 day')->setTime(18, 0, 0);

    return [$fri->getTimestamp(), $sat->getTimestamp()];
}

/**
 * An ordinary weekend: the first Saturday at least three days ahead that is
 * at least nine days from every clock change in the three zones used here.
 */
function ordinarySaturday(): string
{
    $changes = array_merge(clockChanges(QUIET_ZONE), clockChanges(DB_ZONE), clockChanges(ZONE_TWO));
    $day     = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+3 days');
    for ($i = 0; $i < 400; $i++, $day = $day->modify('+1 day')) {
        if ($day->format('N') !== '6') {
            continue;
        }
        $noon = $day->setTime(12, 0, 0)->getTimestamp();
        $near = false;
        foreach ($changes as $change) {
            if (abs($change['ts'] - $noon) < 9 * 86400) {
                $near = true;
            }
        }
        if ($near === false) {
            return $day->format('Y-m-d');
        }
    }
    throw new RuntimeException('no ordinary Saturday was found in the next 400 days');
}

/** The next Saturday that is the first day of a month: the Friday before it is the last day of the month before. */
function saturdayTheFirst(): string
{
    $month = (new DateTimeImmutable('first day of next month', new DateTimeZone('UTC')))->setTime(12, 0, 0);
    for ($i = 0; $i < 120; $i++, $month = $month->modify('first day of next month')) {
        if ($month->format('N') === '6') {
            return $month->format('Y-m-d');
        }
    }
    throw new RuntimeException('no Saturday the first was found in the next ten years');
}

/** A moment as the database stores a moment in UTC. */
function utc(int $epoch): string
{
    return gmdate('Y-m-d H:i:s', $epoch);
}

/** A moment as a clock in `$zone` shows it: what NOW() gives on a connection in that zone. */
function onClock(int $epoch, string $zone): string
{
    return (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i:s');
}

// =============================================================================
// 🎬 One "request", one run, and what the record says
// =============================================================================

/**
 * The settings a request finds. No provider account is named anywhere, so
 * the real class cannot contact anybody.
 *
 * @param array{quiet?:string, zone?:string, endOffset?:int, sms?:string, cap?:int, multi?:string} $o
 *        `multi` 'true' is an installation with SEVERAL organisations; the
 *        default is one, where nobody needs a membership row.
 *
 * @return array<string, mixed>
 */
function settingsFor(array $o = []): array
{
    return [
        'sms' => [
            'enabled' => $o['sms'] ?? '1', 'provider' => 'twilio', 'dailyCap' => (string) ($o['cap'] ?? 100), 'fromNumber' => '',
            'twilio' => ['sid' => '', 'token' => ''], 'messagebird' => ['apiKey' => ''], 'aws' => ['accessKey' => '', 'secret' => '', 'region' => 'eu-west-1'],
        ],
        'portal' => ['sabbath' => [
            'enabled' => $o['quiet'] ?? '1', 'method' => 'fixed', 'timezone' => $o['zone'] ?? QUIET_ZONE,
            'start_offset_minutes' => '0', 'end_offset_minutes' => (string) ($o['endOffset'] ?? 0),
        ]],
        'maintenance' => ['cronToken' => JOB_TOKEN],
        'multisite'   => ['enabled' => $o['multi'] ?? 'false', 'detectionMode' => 'path'],
        'site'        => ['timezoneKeptAt' => ''],
    ];
}

/** Start the portal's own classes as a request would find them. */
function portal(array $o = []): array
{
    global $mysqli;
    $settings            = settingsFor($o);
    $GLOBALS['SETTINGS'] = $settings;
    App::init($mysqli, $settings);
    newProcess();

    return $settings;
}

/**
 * Every request, and every run of the job, is a new PHP process, and Site
 * keeps "your organisations" for the length of one. So Site is started
 * afresh before each text and each run here, as a new process would find
 * it: without this a person who left an organisation in the middle of this
 * file would still be remembered as a member.
 */
function newProcess(): void
{
    global $mysqli;
    Site::reset();
    Site::init($mysqli, (array) $GLOBALS['SETTINGS'], ORG_ONE);
}

/** Set the moment, on the database's clock, for everything that follows on this connection. */
function at(int $epoch, ?mysqli $on = null): void
{
    global $mysqli;
    ($on ?? $mysqli)->query('SET timestamp = ' . $epoch);
}

/**
 * ONE REQUEST that sends one text through the real Sms::send().
 *
 * "Once a request" is a flag inside the class; a new request starts with it
 * down, so it is put down here before every text.
 *
 * @return array{0: bool, 1: string} What send() answered, and lastOutcome().
 */
function text(string $who, string $kind, string $body, ?string $useBy = null, int $org = ORG): array
{
    (new ReflectionProperty(Sms::class, 'settledInRequest'))->setValue(null, false);
    newProcess();
    $userId = WHO[$who];
    $went   = RecordingSms::send($org, numberOf($userId), $body, $kind, $userId, $useBy);

    return [$went, RecordingSms::lastOutcome()];
}

/** One run of the held texts, as the scheduled job makes it. */
function run(int $most = 20, float $seconds = 8.0): array
{
    global $mysqli;
    newProcess();

    return RecordingSms::sendHeld($mysqli, $most, $seconds);
}

/** The numbers of a run in which nothing happened and `$left` texts are still held. */
function quietRun(int $left): array
{
    return ['ran' => 1, 'sent' => 0, 'failed' => 0, 'notSent' => 0, 'left' => $left, 'notReady' => 0, 'error' => 0];
}

/** The row of the text with these words. */
function rowOf(string $body): ?array
{
    return one('SELECT * FROM tblSmsMessage WHERE body = ? ORDER BY messageID DESC LIMIT 1', 's', [$body]);
}

/** What a row says of itself, in the four columns that tell the story. */
function story(?array $row): array
{
    return $row === null ? ['no row'] : [$row['status'], $row['errorMsg'], $row['sentAt'], $row['claimedAt']];
}

/** How many times a text with these words was handed to the provider. */
function handed(string $body): int
{
    return count(array_filter(RecordingSms::$calls, static fn (array $c): bool => $c[1] === $body));
}

/** Start a part with nothing left over from the one before. */
function fresh(array $o = []): array
{
    q('DELETE FROM tblSmsMessage WHERE siteID IN (?, ?)', 'ii', [ORG, ORG2]);
    RecordingSms::$calls  = [];
    RecordingSms::$answer = 'accept';
    RecordingSms::$during = null;

    return portal($o);
}

/** A text held the way the OLD code held it: "queued", the reason, a "sent at" although it never went, and no use-by moment. */
function oldHeld(string $who, string $body, int $heldAt): void
{
    $stamp = onClock($heldAt, DB_ZONE);
    q(
        "INSERT INTO tblSmsMessage (siteID, recipientUserID, recipientNumber, body, category, status, provider, errorMsg, sentAt, createdAt) VALUES (?, ?, ?, ?, 'prayer_assignment', 'queued', 'twilio', 'quiet-hours', ?, ?)",
        'iissss',
        [ORG, WHO[$who], numberOf(WHO[$who]), $body, $stamp, $stamp]
    );
}

/**
 * Run this same file as a child process, and wait for it.
 *
 * @param array<string, mixed> $job
 *
 * @return array{out: string, code: int}
 */
function child(array $job): array
{
    $started = startChild($job);

    return finishChild($started);
}

/** @param array<string, mixed> $job @return array{0: resource, 1: array<int, resource>} */
function startChild(array $job): array
{
    $env = getenv();
    $env['SELFTEST_JOB'] = (string) json_encode($job);
    $process = proc_open([PHP_BINARY, __FILE__, '--child'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (is_resource($process) === false) {
        throw new RuntimeException('a child process could not be started');
    }
    fclose($pipes[0]);

    return [$process, $pipes];
}

/** @param array{0: resource, 1: array<int, resource>} $started @return array{out: string, code: int} */
function finishChild(array $started): array
{
    [$process, $pipes] = $started;
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['out' => $out, 'code' => proc_close($process)];
}

/**
 * The REAL job page, run once in a process of its own.
 *
 * @param array{token?: string, o?: array<string, mixed>, missing?: list<string>, zone?: string} $how
 *
 * @return array{json: mixed, out: string, log: string}
 */
function page(int $epoch, array $how = []): array
{
    global $scratch, $sep;
    $log = $scratch . $sep . 'page-errors.log';
    @unlink($log);
    $ran = child([
        'task' => 'page', 'zone' => $how['zone'] ?? DB_ZONE, 'timestamp' => $epoch, 'settings' => settingsFor($how['o'] ?? []),
        'token' => $how['token'] ?? JOB_TOKEN, 'missing' => $how['missing'] ?? [], 'errorLog' => $log, 'root' => $scratch . $sep . 'portal-root',
    ]);

    return ['json' => json_decode(trim($ran['out']), true), 'out' => $ran['out'], 'log' => is_file($log) === true ? (string) file_get_contents($log) : ''];
}

/**
 * The texts step's own numbers, out of the job page's answer: every entry
 * whose name begins "texts", under the name Sms::sendHeld() gives it.
 *
 * @return array<string, mixed>
 */
function textsOf(mixed $json): array
{
    $out = [];
    foreach (is_array($json) === true ? $json : [] as $name => $value) {
        if (str_starts_with((string) $name, 'texts') === true) {
            $out[lcfirst(substr((string) $name, 5))] = $value;
        }
    }

    return $out;
}

// =============================================================================
// 🏗️ Build the world
// =============================================================================
head('Set-up');
$foreign = (int) (one('SELECT COUNT(*) AS n FROM tblSmsMessage WHERE siteID NOT IN (?, ?)', 'ii', [ORG, ORG2])['n'] ?? 0);
if ($foreign > 0) {
    echo "REFUSED — this database already holds {$foreign} text message(s) that are not this test's own. The class works through every held text it finds,\n";
    echo "so the test only runs on a database of its own, built from web/_sql/full_schema.sql. Nothing was checked.\n";
    exit(1);
}
$scratch = sys_get_temp_dir() . $sep . 'held-texts-selftest-' . getmypid();
@mkdir($scratch . $sep . 'portal-root', 0700, true);
// The parent's own error log: what the class writes when a run is stopped (D4).
ini_set('log_errors', '1');
ini_set('error_log', $scratch . $sep . 'errors.log');

register_shutdown_function('cleanup');
$state['built'] = true;
removeWorld();
$jobRow                   = one("SELECT jobLastRanAt FROM tblMailRouteState WHERE routeKey = 'installation'");
$state['jobRow']          = $jobRow !== null;
$state['jobRan']          = $jobRow['jobLastRanAt'] ?? null;
$state['firstActivityId'] = (int) (one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'] ?? 0);
$tableText                = static fn (): string => (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) (one('SHOW CREATE TABLE tblSmsMessage')['Create Table'] ?? ''));
$state['tableText']       = $tableText();

q('INSERT INTO tblSites (siteID, siteKey, siteName, timezone, isActive) VALUES (?, ?, ?, ?, 1), (?, ?, ?, ?, 1)', 'isssisss', [
    ORG, 'selftest-texts-a', 'Texts Church ZQXOA', QUIET_ZONE, ORG2, 'selftest-texts-b', 'Texts Chapel ZQXOB', QUIET_ZONE,
]);
/** Add one person with a confirmed number for texts in an organisation. */
$addPerson = static function (int $userId, string $quiet = 'inherit', int $org = ORG): void {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin, sabbathHonour) VALUES (?, ?, ?, 1, 0, 0, ?)', 'isss', [$userId, nameOf($userId), 'p' . $userId . '@texts.selftest.invalid', $quiet]);
    q('INSERT INTO tblUserSmsPreference (siteID, userID, phoneNumber, isVerified, categories) VALUES (?, ?, ?, 1, ?)', 'iiss', [$org, $userId, numberOf($userId), EVERY_KIND]);
};
foreach (WHO as $what => $userId) {
    $addPerson($userId, ['on' => 'on', 'off' => 'off'][$what] ?? 'inherit');
}
q('INSERT INTO tblUserSmsPreference (siteID, userID, phoneNumber, isVerified, categories) VALUES (?, ?, ?, 1, ?)', 'iiss', [ORG2, WHO['off'], numberOf(WHO['off']), EVERY_KIND]);
q('UPDATE tblUsers SET isRootAdmin = 1 WHERE userID = ?', 'i', [WHO['global']]);
// NOBODY has a membership row yet. On an installation with one organisation
// nobody needs one, and every part but H's last three runs that way.

$saturday  = ordinarySaturday();
[$W0, $W1] = weekendOf($saturday, QUIET_ZONE); // quiet hours: from $W0 to $W1, both included
$FRI20     = $W0 + 7200;                       // Friday 20:00 on the quiet hours' clock: well inside
$wednesday = $W1 + 4 * 86400 - 6 * 3600;       // the Wednesday after, at noon: well outside
portal();
at($FRI20);
$inside = Sabbath::isQuietNow(WHO['follow'], $FRI20) === true && Sabbath::isQuietNow(WHO['follow'], $W1) === true && Sabbath::isQuietNow(WHO['follow'], $W1 + 1) === false
    && Sabbath::isQuietNow(WHO['follow'], $W0 - 1) === false && Sabbath::isQuietNow(WHO['off'], $FRI20) === false && Sabbath::isQuietNow(WHO['follow'], $wednesday) === false;
$clock = one('SELECT UNIX_TIMESTAMP() AS e, @@session.time_zone AS z, @@global.time_zone AS g, NOW() AS n, UTC_TIMESTAMP() AS u');
ok(
    'set-up: the ordinary weekend is a real one (quiet from Friday 18:00 to Saturday 18:00 on a London clock, and Sabbath agrees with this file\'s own dates), and this connection\'s clock is set, is NOT in UTC, and is in a zone that changes its clocks',
    $inside === true && $W1 - $W0 === 86400 && (int) $clock['e'] === $FRI20 && $clock['z'] === DB_ZONE && $clock['n'] === onClock($FRI20, DB_ZONE) && $clock['u'] === utc($FRI20)
        && $clock['n'] !== $clock['u'] && clockChanges(DB_ZONE) !== [],
    'Sabbath agrees: ' . json_encode($inside) . '; the clock: ' . json_encode($clock) . '; wanted ' . $FRI20
);
note('the weekend used is Saturday ' . $saturday . '. This connection is in ' . DB_ZONE . '; the database server\'s own default zone is ' . json_encode($clock['g']) . '; PHP is in ' . date_default_timezone_get() . '.');
note('the code under test is ' . $webDir . '.');

// =============================================================================
head('A. Held, then sent once when the quiet hours end');
// =============================================================================
fresh();
at($FRI20);
$A = 'ZQXT A a prayer request to pray for';
[$went, $outcome] = text('follow', 'prayer_assignment', $A);
$held             = rowOf($A);
ok(
    'A1 a text that is not urgent, for a person inside their quiet hours, is HELD: send() answers false and "held", nothing is handed to the provider, and the record says queued, "quiet-hours", NOT sent (an empty "sent at"), untaken, with no cost',
    $went === false && $outcome === 'held' && RecordingSms::$calls === [] && $held !== null && $held['status'] === 'queued' && $held['errorMsg'] === Sms::HELD_REASON
        && $held['sentAt'] === null && $held['claimedAt'] === null && $held['providerRef'] === null && $held['costPence'] === null && (int) $held['recipientUserID'] === WHO['follow'],
    json_encode([$went, $outcome, RecordingSms::$calls, $held])
);
ok(
    'A2 its use-by moment is stored IN UTC, 48 hours after it was held, on a connection whose own clock is 12 or 13 hours from UTC; and "created at" is on that connection\'s clock, as it always was',
    $held !== null && $held['heldUseBy'] === utc($FRI20 + 172800) && $held['createdAt'] === onClock($FRI20, DB_ZONE),
    'use-by ' . json_encode($held['heldUseBy'] ?? null) . ', wanted ' . utc($FRI20 + 172800) . '; created ' . json_encode($held['createdAt'] ?? null) . ', wanted ' . onClock($FRI20, DB_ZONE)
);
$during = [];
foreach ([$FRI20 + 60, $W1 - 6 * 3600, $W1 - 1, $W1] as $moment) {
    at($moment);
    $during[] = run();
}
ok(
    'A3 four runs of the job DURING the quiet hours, the last at their final second: nothing is sent, nothing is changed, and each run says one text is still held',
    $during === array_fill(0, 4, quietRun(1)) && RecordingSms::$calls === [] && rowOf($A) === $held,
    json_encode($during) . '; ' . json_encode(story(rowOf($A)))
);
at($W1 + 1);
$first = run();
$sent  = rowOf($A);
ok(
    'A4 the first run after they end, one second later: the text is handed to the provider ONCE, to the same number, with the same words',
    $first === ['ran' => 1, 'sent' => 1, 'failed' => 0, 'notSent' => 0, 'left' => 0, 'notReady' => 0, 'error' => 0] && RecordingSms::$calls === [[numberOf(WHO['follow']), $A]],
    json_encode($first) . '; ' . json_encode(RecordingSms::$calls)
);
ok(
    'A5 its record now says SENT, in plain words that it was held first, with the provider\'s reference and the cost; "taken" is that moment in UTC and "sent at" is that moment on the connection\'s own clock (not the moment it was written)',
    $sent !== null && $sent['status'] === 'sent' && $sent['errorMsg'] === Sms::SENT_AFTER_HOLD && $sent['providerRef'] === 'SMZQX1' && (int) $sent['costPence'] === 4
        && $sent['claimedAt'] === utc($W1 + 1) && $sent['sentAt'] === onClock($W1 + 1, DB_ZONE) && $sent['createdAt'] === onClock($FRI20, DB_ZONE) && $sent['heldUseBy'] === utc($FRI20 + 172800),
    json_encode($sent)
);
$after = [];
foreach ([$W1 + 2, $W1 + 301, $W1 + 3600, $W1 + 86400, $W1 + 7 * 86400] as $moment) {
    at($moment);
    $after[] = run();
}
ok(
    'A6 five more runs, up to a week later: it is never handed over again, and its record does not change',
    $after === array_fill(0, 5, quietRun(0)) && handed($A) === 1 && rowOf($A) === $sent,
    json_encode($after) . '; handed over ' . handed($A) . ' time(s)'
);

// =============================================================================
head('B. What is never held');
// =============================================================================
fresh();
at($FRI20);
$B1 = 'ZQXT B a critical alert';
[$went, $outcome] = text('follow', 'critical_alerts', $B1);
ok(
    'B1 a CRITICAL ALERT for a person inside their quiet hours is sent at once: handed to the provider in the same request, recorded as sent, and no held row is made',
    $went === true && $outcome === 'sent' && handed($B1) === 1 && (rowOf($B1)['status'] ?? '') === 'sent' && rowOf($B1)['heldUseBy'] === null && rowOf($B1)['sentAt'] === onClock($FRI20, DB_ZONE)
        && (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'queued'")['n'] === 0,
    json_encode([$went, $outcome, story(rowOf($B1))])
);
$codeSent = RecordingSms::startVerification(ORG, WHO['verify'], '+447700900777');
$codeText = RecordingSms::$calls[1] ?? ['', ''];
ok(
    'B2 the code that confirms a number is sent at once inside quiet hours too (it runs out after ten minutes)',
    $codeSent === true && $codeText[0] === '+447700900777' && str_contains($codeText[1], 'verification code') === true && (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'queued'")['n'] === 0,
    json_encode([$codeSent, $codeText[0]])
);
$B3 = 'ZQXT B for a person who switched quiet hours off';
[$went, $outcome] = text('off', 'rota_changes', $B3);
ok(
    'B3 a person who has switched quiet hours OFF for themselves is never held: an ordinary text goes at once',
    $went === true && $outcome === 'sent' && handed($B3) === 1 && (rowOf($B3)['status'] ?? '') === 'sent',
    json_encode([$went, $outcome, story(rowOf($B3))])
);
$B4 = 'ZQXT B for a person who chose always';
[$went, $outcome] = text('on', 'rota_changes', $B4);
ok(
    'B4 a person who chose "always" is held like one who follows the portal\'s setting',
    $went === false && $outcome === 'held' && handed($B4) === 0 && (rowOf($B4)['status'] ?? '') === 'queued',
    json_encode([$went, $outcome, story(rowOf($B4))])
);
at($wednesday);
$B5 = 'ZQXT B on an ordinary Wednesday';
[$went, $outcome] = text('follow', 'rota_changes', $B5);
ok(
    'B5 outside quiet hours nobody is held: the same person, the same kind of text, on a Wednesday',
    $went === true && $outcome === 'sent' && handed($B5) === 1,
    json_encode([$went, $outcome, story(rowOf($B5))])
);
fresh(['quiet' => '0']);
at($FRI20);
$B6 = 'ZQXT B with quiet hours off for everybody';
[$went, $outcome] = text('follow', 'rota_changes', $B6);
ok(
    'B6 with quiet hours switched off for the installation nobody is held, on a Friday evening',
    $went === true && $outcome === 'sent' && handed($B6) === 1,
    json_encode([$went, $outcome, story(rowOf($B6))])
);
fresh();
at($FRI20);
$B7    = 'ZQXT B for no named person';
$threw = '';
try {
    $went = RecordingSms::send(ORG, '+447700900555', $B7, 'rota_changes', null);
} catch (Throwable $e) {
    // A fault here must read as this check failing, not as the test stopping.
    $threw = get_class($e);
    $went  = null;
}
ok(
    'B7 a text for no named person cannot be "inside quiet hours" and goes at once, as it always did',
    $threw === '' && $went === true && RecordingSms::lastOutcome() === 'sent' && handed($B7) === 1 && (rowOf($B7) ?? ['recipientUserID' => 0])['recipientUserID'] === null,
    'threw ' . json_encode($threw) . '; ' . json_encode([$went, RecordingSms::lastOutcome(), story(rowOf($B7))])
);

fresh();
at($FRI20);
$B8 = 'ZQXT B an emergency notice on a Friday evening';
[$went, $outcome] = text('follow', 'emergency_comms', $B8);
$B8on             = 'ZQXT B an emergency notice for a person who chose always';
[$wentOn]         = text('on', 'emergency_comms', $B8on);
ok(
    'B8 an EMERGENCY NOTICE is never held (the owner\'s decision of 13 October 2026): for a person inside their quiet hours, and for one who chose "always", it is handed to the provider in the same request and recorded as sent, with no use-by moment and no held row',
    $went === true && $outcome === 'sent' && $wentOn === true && handed($B8) === 1 && handed($B8on) === 1 && (rowOf($B8)['status'] ?? '') === 'sent' && rowOf($B8)['heldUseBy'] === null
        && rowOf($B8)['sentAt'] === onClock($FRI20, DB_ZONE) && (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'queued'")['n'] === 0,
    json_encode([$went, $outcome, $wentOn, story(rowOf($B8)), story(rowOf($B8on))])
);
fresh();
at($FRI20);
q("UPDATE tblUserSmsPreference SET categories = 'critical_alerts' WHERE siteID = ? AND userID NOT IN (?, ?, ?)", 'iiii', [ORG, WHO['follow'], WHO['on'], WHO['off']]);
(new ReflectionProperty(Sms::class, 'settledInRequest'))->setValue(null, false);
$urgent = RecordingSms::sendCategory(ORG, 'emergency_comms', 'ZQXT B an emergency broadcast on a Friday evening');
q('UPDATE tblUserSmsPreference SET categories = ? WHERE siteID = ?', 'si', [EVERY_KIND, ORG]);
ok(
    'B9 an emergency BROADCAST on a Friday evening reaches all three people who asked for that kind at once, the two inside their quiet hours included: 3 sent, none held, none skipped',
    $urgent === ['sent' => 3, 'skipped' => 0, 'held' => 0] && count(RecordingSms::$calls) === 3 && (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'queued'")['n'] === 0,
    json_encode([$urgent, count(RecordingSms::$calls)])
);
ok(
    'B10 the kinds that are never held are exactly two, a critical alert and an emergency notice: the list the class decides by holds those and no other',
    Sms::NEVER_HELD === ['critical_alerts', 'emergency_comms'],
    json_encode(Sms::NEVER_HELD)
);

// =============================================================================
head('C. The use-by moment');
// =============================================================================
// (An emergency notice used to be the two-hour kind here. It is never held
// now, so the two-hour kind in these checks is 'general': a kind the portal
// knows nothing about, which is treated as being about now.)
fresh();
at($FRI20);
$kinds = ['general' => 7200, 'a_kind_added_later' => 7200, 'rota_changes' => 172800, 'newsletter_digest' => 172800, 'prayer_assignment' => 172800];
$got   = [];
foreach ($kinds as $kind => $seconds) {
    text('follow', $kind, 'ZQXT C1 ' . $kind);
    $got[$kind] = rowOf('ZQXT C1 ' . $kind)['heldUseBy'] ?? null;
}
ok(
    'C1 the use-by moment goes by the KIND of text: two hours for a kind the portal does not know, 48 hours for a rota change, a digest and a prayer request',
    $got === array_map(static fn (int $seconds): string => utc($FRI20 + $seconds), $kinds),
    json_encode($got)
);

fresh();
at($FRI20);
$C2 = 'ZQXT C2 see you at nine this evening';
text('follow', 'general', $C2);
at($FRI20 + 7199);
$before = run();
$still  = story(rowOf($C2));
at($FRI20 + 7200);
$atUseBy = run();
$expired = rowOf($C2);
ok(
    'C2 a text of a kind that keeps two hours, held on Friday evening: one second before its use-by moment it is still held; AT that moment it is recorded as not sent, in plain words that begin "Not sent: quiet hours", with an empty "sent at", and it was never handed over',
    $before === quietRun(1) && $still === ['queued', Sms::HELD_REASON, null, null] && $atUseBy === ['ran' => 1, 'sent' => 0, 'failed' => 0, 'notSent' => 1, 'left' => 0, 'notReady' => 0, 'error' => 0]
        && story($expired) === ['failed', Sms::NOT_SENT_USE_BY, null, null] && str_starts_with((string) $expired['errorMsg'], 'Not sent: quiet hours') === true && RecordingSms::$calls === [],
    json_encode([$before, $still, $atUseBy, story($expired), RecordingSms::$calls])
);
at($W1 + 1);
$late = run();
at($W1 + 86400);
$later = run();
ok(
    'C3 when the quiet hours end, and a day after, that text is still not sent: a text about Friday evening never arrives on Saturday evening',
    $late === quietRun(0) && $later === quietRun(0) && RecordingSms::$calls === [] && rowOf($C2) === $expired,
    json_encode([$late, $later, RecordingSms::$calls])
);

fresh();
at($W1 - 3600);
$C4 = 'ZQXT C4 written an hour before the quiet hours end';
text('follow', 'general', $C4);
at($W1 + 1);
$soon = run();
ok(
    'C4 a two-hour text written one hour before the quiet hours end IS sent when they end: it is still inside its two hours',
    $soon['sent'] === 1 && handed($C4) === 1 && (rowOf($C4)['status'] ?? '') === 'sent',
    json_encode([$soon, story(rowOf($C4))])
);

fresh();
at($FRI20);
$C5a = 'ZQXT C5 with its own use-by, three hours on';
$C5b = 'ZQXT C5 with its own use-by, five days on';
$C5c = 'ZQXT C5 a reminder of something at Saturday noon';
text('follow', 'general', $C5a, utc($FRI20 + 10800));
text('follow', 'general', $C5b, utc($FRI20 + 5 * 86400));
text('follow', 'prayer_assignment', $C5c, utc($W1 - 6 * 3600));
ok(
    'C5 a use-by moment given by the caller is the one stored, exactly, whatever the kind; one that is five days away is cut to 48 hours',
    (rowOf($C5a)['heldUseBy'] ?? '') === utc($FRI20 + 10800) && (rowOf($C5b)['heldUseBy'] ?? '') === utc($FRI20 + 172800) && (rowOf($C5c)['heldUseBy'] ?? '') === utc($W1 - 6 * 3600),
    json_encode([rowOf($C5a)['heldUseBy'] ?? null, rowOf($C5b)['heldUseBy'] ?? null, rowOf($C5c)['heldUseBy'] ?? null])
);
at($W1 + 1);
$mixed = run();
ok(
    'C6 when the quiet hours end, the reminder of something at Saturday noon is NOT sent (its caller\'s moment has passed) although its kind keeps for 48 hours; the text with five days is sent',
    handed($C5c) === 0 && story(rowOf($C5c)) === ['failed', Sms::NOT_SENT_USE_BY, null, null] && handed($C5b) === 1 && handed($C5a) === 0 && $mixed['sent'] === 1 && $mixed['notSent'] === 2,
    json_encode([$mixed, story(rowOf($C5a)), story(rowOf($C5b)), story(rowOf($C5c))])
);

fresh();
at($FRI20);
$unreadable = ['not known', '2027-02-31 10:00:00', '2027-06-01', '', utc($FRI20 - 60), utc($FRI20)];
$notKept    = [];
foreach ($unreadable as $i => $given) {
    $body      = 'ZQXT C7 use-by number ' . $i;
    $notKept[] = array_merge(text('follow', 'prayer_assignment', $body, $given), story(rowOf($body)), [rowOf($body)['heldUseBy'] ?? null]);
}
ok(
    'C7 a use-by moment that cannot be read (words, a date that does not exist, a date with no time, nothing) or that has already passed: the text is NOT kept. It is recorded at once as not sent, with the reason, and nothing is held',
    $notKept === array_fill(0, count($unreadable), [false, 'not-sent', 'failed', Sms::NOT_SENT_NOT_KEPT, null, null, null]) && RecordingSms::$calls === []
        && (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'queued'")['n'] === 0,
    json_encode($notKept)
);

fresh();
at($FRI20);
$C8a = 'ZQXT C8 found by a job that came back just in time';
$C8b = 'ZQXT C8 found by a job that came back too late';
text('follow', 'prayer_assignment', $C8a);
at($FRI20 + 172800 - 1);
$justInTime = run();
at($FRI20);
text('follow', 'prayer_assignment', $C8b);
at($FRI20 + 172800);
$tooLate = run();
ok(
    'C8 the job was away for two days: a text found one second inside its 48 hours is sent; one found AT 48 hours is recorded as not sent and never handed over',
    $justInTime['sent'] === 1 && handed($C8a) === 1 && $tooLate['sent'] === 0 && $tooLate['notSent'] === 1 && handed($C8b) === 0 && story(rowOf($C8b)) === ['failed', Sms::NOT_SENT_USE_BY, null, null],
    json_encode([$justInTime, $tooLate, story(rowOf($C8b))])
);
at($FRI20);
$C9 = 'ZQXT C9 sent at once, with a use-by that has passed';
[$went, $outcome] = text('off', 'rota_changes', $C9, utc($FRI20 - 3600));
ok(
    'C9 a use-by moment makes no difference to a text that is sent at once (the use-by is for a HELD text; this pins what the class says of itself)',
    $went === true && $outcome === 'sent' && handed($C9) === 1 && rowOf($C9)['heldUseBy'] === null,
    json_encode([$went, $outcome, story(rowOf($C9))])
);

// =============================================================================
head('D. Never twice');
// =============================================================================
$settings = fresh();
at($FRI20);
foreach ([1, 2, 3] as $n) {
    text('follow', 'prayer_assignment', 'ZQXT D1 text ' . $n);
}
// A second run, on a connection of its own, started in the MIDDLE of the
// first: after the first has read all three rows and handed over one. The
// first then tries to take the other two, which the second has taken. Its
// clock is one second on, so a claim that only wrote "the moment" again
// would count as a change.
$second = connect(DB_ZONE);
at($W1 + 61, $second);
at($W1 + 60);
$out1 = ['sent' => 0, 'failed' => 0, 'notSent' => 0];
$out2 = ['sent' => 0, 'failed' => 0, 'notSent' => 0];
RecordingSms::$during = static function () use ($second, $settings, &$out2): void {
    RecordingSms::sendDue($second, (array) $settings['sms'], 100, microtime(true) + 30, $out2);
};
RecordingSms::sendDue($mysqli, (array) $settings['sms'], 100, microtime(true) + 30, $out1);
$bodies = array_column(RecordingSms::$calls, 1);
sort($bodies);
ok(
    'D1 a second run that starts in the middle of the first (without the lock, so that only the claim stands between them): three texts, three hand-overs, each text ONCE; the first run sent one and the second the other two',
    $bodies === ['ZQXT D1 text 1', 'ZQXT D1 text 2', 'ZQXT D1 text 3'] && $out1['sent'] === 1 && $out2['sent'] === 2
        && (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'sent' AND siteID = ?", 'i', [ORG])['n'] === 3,
    json_encode([$bodies, $out1, $out2])
);

// The same, but the second run is ENDED while handing its text over, so that
// text is left taken and unfinished. The first run read it before it was
// taken, and now comes to it.
fresh();
at($FRI20);
$D1a = 'ZQXT D1b the first run hands this one over';
$D1b = 'ZQXT D1b the second run is ended while handing this one over';
text('follow', 'prayer_assignment', $D1a);
text('follow', 'prayer_assignment', $D1b);
at($W1 + 60);
$out1 = ['sent' => 0, 'failed' => 0, 'notSent' => 0];
$out2 = ['sent' => 0, 'failed' => 0, 'notSent' => 0];
RecordingSms::$during = static function () use ($second, $settings, &$out2): void {
    RecordingSms::$answer = 'throw';
    try {
        RecordingSms::sendDue($second, (array) $settings['sms'], 100, microtime(true) + 30, $out2);
    } catch (Throwable $ended) {
        // The second run is over. Its text stays taken.
    }
    RecordingSms::$answer = 'accept';
};
RecordingSms::sendDue($mysqli, (array) $settings['sms'], 100, microtime(true) + 30, $out1);
ok(
    'D1b a second run takes a text and is ended before the provider answers; the first run, which had read that text while it was still free, comes to it next and does NOT hand it over again. It stays taken by the second run, and each text was handed over once',
    handed($D1a) === 1 && handed($D1b) === 1 && $out1['sent'] === 1 && story(rowOf($D1b)) === ['queued', Sms::HELD_REASON, null, utc($W1 + 61)] && (rowOf($D1a)['status'] ?? '') === 'sent',
    json_encode([handed($D1a), handed($D1b), $out1, story(rowOf($D1b))])
);

fresh();
at($FRI20);
for ($n = 1; $n <= 40; $n++) {
    text('follow', 'prayer_assignment', 'ZQXT D2 text ' . str_pad((string) $n, 2, '0', STR_PAD_LEFT));
}
$raceFile = $scratch . $sep . 'race.txt';
$goFile   = $scratch . $sep . 'go';
@unlink($raceFile);
@unlink($goFile);
$runners = [];
foreach ([$W1 + 60, $W1 + 61] as $moment) {
    $runners[] = startChild(['task' => 'race', 'zone' => DB_ZONE, 'timestamp' => $moment, 'settings' => $settings, 'file' => $raceFile, 'waitFor' => $goFile]);
}
usleep(700000); // both are connected and waiting
touch($goFile);
$each = [];
foreach ($runners as $runner) {
    $done   = finishChild($runner);
    $at     = strrpos($done['out'], RESULT_MARK);
    $each[] = $at === false ? ['no result: ' . mb_substr($done['out'], 0, 300)] : (array) json_decode(substr($done['out'], $at + strlen(RESULT_MARK)), true);
}
$lines = is_file($raceFile) === true ? array_values(array_filter(explode("\n", (string) file_get_contents($raceFile)))) : [];
ok(
    'D2 two processes started on one signal, both working through the same 40 held texts without the lock: 40 hand-overs, 40 different texts, 40 rows recorded as sent, none left held',
    count($lines) === 40 && count(array_unique($lines)) === 40 && (int) ($each[0]['sent'] ?? -1) + (int) ($each[1]['sent'] ?? -1) === 40
        && (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'sent' AND siteID = ?", 'i', [ORG])['n'] === 40
        && (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'queued' AND siteID = ?", 'i', [ORG])['n'] === 0,
    count($lines) . ' hand-overs, ' . count(array_unique($lines)) . ' different; the two processes said ' . json_encode($each)
);
note('D2: one process handed over ' . json_encode($each[0]['sent'] ?? null) . ' and the other ' . json_encode($each[1]['sent'] ?? null) . '. (When one of them says 0 or 40 they did not overlap in this run, and D1 is the proof.)');

fresh();
at($FRI20);
$D3 = 'ZQXT D3 while another run holds the lock';
text('follow', 'prayer_assignment', $D3);
at($W1 + 60);
$second->query("SELECT GET_LOCK(CONCAT('portal-held-texts:', SHA1(DATABASE())), 0)")->free();
$beforeD3 = rowOf($D3);
$locked   = run();
$afterD3  = rowOf($D3);
$second->query("DO RELEASE_LOCK(CONCAT('portal-held-texts:', SHA1(DATABASE())))");
$freed = run();
ok(
    'D3 while another run holds the lock a run does NOTHING (it says it did not run, counts nothing, and the text is not touched); once the lock is free the text is sent',
    $locked === ['ran' => 0, 'sent' => 0, 'failed' => 0, 'notSent' => 0, 'left' => 0, 'notReady' => 0, 'error' => 0] && $afterD3 === $beforeD3 && $beforeD3['status'] === 'queued' && $freed['ran'] === 1 && $freed['sent'] === 1 && handed($D3) === 1,
    json_encode([$locked, $freed])
);

fresh();
at($FRI20);
$D4 = 'ZQXT D4 the run is ended while handing this one over';
text('follow', 'prayer_assignment', $D4);
at($W1 + 60);
RecordingSms::$answer = 'throw';
$threw = '';
try {
    $ended = run();
} catch (Throwable $e) {
    $threw = get_class($e);
    $ended = [];
}
RecordingSms::$answer = 'accept';
$taken = rowOf($D4);
ok(
    'D4 a run that is ended between taking a text and hearing from the provider does NOT throw (it can never stop the mail step), says it was stopped, and leaves the text TAKEN: still "queued", with the moment it was taken',
    $threw === '' && ($ended['ran'] ?? 0) === 1 && ($ended['error'] ?? 0) === 1 && ($ended['sent'] ?? -1) === 0 && story($taken) === ['queued', Sms::HELD_REASON, null, utc($W1 + 60)],
    'threw ' . json_encode($threw) . '; ' . json_encode([$ended, story($taken)])
);
$tries = [];
foreach ([$W1 + 61, $W1 + 360, $W1 + 60 + 599] as $moment) {
    at($moment);
    $tries[] = run();
}
ok(
    'D5 nobody can tell whether that text went, so it is NOT sent again: three later runs, the last 9 minutes 59 seconds on, hand nothing over and leave it as it is (counted as still held)',
    $tries === array_fill(0, 3, quietRun(1)) && handed($D4) === 1 && rowOf($D4) === $taken,
    json_encode($tries) . '; handed over ' . handed($D4)
);
at($W1 + 60 + 600);
$settled = run();
at($W1 + 60 + 3600);
$never = run();
ok(
    'D6 ten minutes after it was taken it is recorded as "not known whether it was sent", in those words, and it is still never handed over again',
    $settled['notSent'] === 1 && $settled['sent'] === 0 && $settled['left'] === 0 && story(rowOf($D4)) === ['failed', Sms::UNKNOWN_INTERRUPTED, null, utc($W1 + 60)] && $never === quietRun(0) && handed($D4) === 1,
    json_encode([$settled, $never, story(rowOf($D4))])
);

fresh();
at($FRI20);
$D7 = 'ZQXT D7 the provider refuses this one';
text('follow', 'prayer_assignment', $D7);
at($W1 + 60);
RecordingSms::$answer = 'refuse';
$refused              = run();
RecordingSms::$answer = 'accept';
at($W1 + 360);
$again = run();
$row   = rowOf($D7);
ok(
    'D7 a text the provider REFUSES is recorded as failed, saying it was held and then refused and why, and is not tried again (a refusal is often a time-out, and a text that timed out may have gone)',
    $refused['failed'] === 1 && $refused['sent'] === 0 && $again === quietRun(0) && handed($D7) === 1 && $row['status'] === 'failed'
        && $row['errorMsg'] === 'Held for quiet hours, then refused by the provider: twilio-http-500' && $row['costPence'] === null && $row['sentAt'] === onClock($W1 + 60, DB_ZONE),
    json_encode([$refused, $again, story($row)])
);

// =============================================================================
head('E. Texts held before this change are never sent');
// =============================================================================
fresh();
// Three rows as the OLD code left them: one 200 days old, one three days
// old, and one ten minutes old, which is the one that could tempt.
oldHeld('follow', 'ZQXT E1 held 200 days ago', $wednesday - 200 * 86400);
oldHeld('follow', 'ZQXT E1 held three days ago', $wednesday - 3 * 86400);
oldHeld('off', 'ZQXT E1 held ten minutes ago', $wednesday - 600);
at($wednesday);
$firstRun = run();
$oldRows  = q("SELECT status, errorMsg, sentAt, claimedAt, heldUseBy FROM tblSmsMessage WHERE body LIKE 'ZQXT E1 %' ORDER BY messageID");
$secondRun = run();
ok(
    'E1 the first run after the upgrade, on a Wednesday, with three texts the old code left "queued": NONE is handed to the provider, however new, and each is recorded as not sent, in plain words, with its untrue "sent at" emptied',
    $firstRun === ['ran' => 1, 'sent' => 0, 'failed' => 0, 'notSent' => 3, 'left' => 0, 'notReady' => 0, 'error' => 0] && RecordingSms::$calls === []
        && $oldRows === array_fill(0, 3, ['status' => 'failed', 'errorMsg' => Sms::NOT_SENT_OLD, 'sentAt' => null, 'claimedAt' => null, 'heldUseBy' => null]) && $secondRun === quietRun(0),
    json_encode([$firstRun, $oldRows, RecordingSms::$calls])
);

fresh();
oldHeld('follow', 'ZQXT E2 an old held text, and no job', $wednesday - 86400);
at($FRI20);
$E2 = 'ZQXT E2 a text past its use-by, and no job';
text('follow', 'general', $E2);
at($wednesday);
// NO run of the job. The next text the portal handles corrects the record.
$E2c = 'ZQXT E2 the next text the portal handles';
[$went] = text('off', 'rota_changes', $E2c);
ok(
    'E2 on an installation where NO scheduled job runs, the record is still corrected: the next text the portal handles records both the old held text and the one past its use-by as not sent, and hands over only itself',
    $went === true && RecordingSms::$calls === [[numberOf(WHO['off']), $E2c]] && story(rowOf('ZQXT E2 an old held text, and no job')) === ['failed', Sms::NOT_SENT_OLD, null, null]
        && story(rowOf($E2)) === ['failed', Sms::NOT_SENT_USE_BY, null, null],
    json_encode([RecordingSms::$calls, story(rowOf('ZQXT E2 an old held text, and no job')), story(rowOf($E2))])
);

fresh();
at($FRI20);
$E3 = 'ZQXT E3 taken by a run that died, and no run ever followed';
text('follow', 'prayer_assignment', $E3);
at($W1 + 60);
RecordingSms::$answer = 'throw';
run();
RecordingSms::$answer = 'accept';
$takenE3 = rowOf($E3);
// NO run of the job from here on. Five minutes later the portal handles a
// text, and again at 9 minutes 59 seconds: both too soon to say the run is
// dead, because a live run is given ten minutes. A month later it handles
// another. (The 9:59 was added after the second check: a tidy that gave up
// on a run after 5 minutes 1 second passed the five-minute look.)
at($W1 + 360);
text('off', 'rota_changes', 'ZQXT E3 a text five minutes later');
$fiveMinutes = rowOf($E3);
at($W1 + 60 + 599);
text('off', 'rota_changes', 'ZQXT E3 a text 9 minutes 59 seconds later');
$nearlyTen = rowOf($E3);
at($W1 + 60 + 30 * 86400);
text('off', 'rota_changes', 'ZQXT E3 a text a month later');
ok(
    'E3 a run takes a text and dies, and NO run ever follows: five minutes later, and again 9 minutes 59 seconds later, an ordinary text leaves it alone (the run might still be alive); a month later the next text the portal handles records it as "not known whether it was sent". It was handed over once and is never sent again',
    story($takenE3) === ['queued', Sms::HELD_REASON, null, utc($W1 + 60)] && $fiveMinutes === $takenE3 && $nearlyTen === $takenE3
        && story(rowOf($E3)) === ['failed', SAYS['UNKNOWN_INTERRUPTED'], null, utc($W1 + 60)] && handed($E3) === 1,
    json_encode([story($takenE3), story($fiveMinutes), story($nearlyTen), story(rowOf($E3)), handed($E3)])
);

// =============================================================================
head('F. New files on a database that has not been upgraded; then the migration');
// =============================================================================
fresh();
$migration = $webDir . $sep . '_sql' . $sep . '219_held_texts.sql';
// The table as it was before migration 219.
$mysqli->query('ALTER TABLE tblSmsMessage DROP KEY idx_sms_held, DROP COLUMN claimedAt, DROP COLUMN heldUseBy');
q("DELETE FROM tblMigrations WHERE filename = '219_held_texts.sql'");
$stamp = onClock($wednesday - 86400, DB_ZONE);
foreach (['ZQXT F an old held text, 1', 'ZQXT F an old held text, 2'] as $body) {
    q("INSERT INTO tblSmsMessage (siteID, recipientUserID, recipientNumber, body, category, status, provider, errorMsg, sentAt, createdAt) VALUES (?, ?, ?, ?, 'prayer_assignment', 'queued', 'twilio', 'quiet-hours', ?, ?)", 'iissss', [ORG, WHO['follow'], numberOf(WHO['follow']), $body, $stamp, $stamp]);
}
at($FRI20);
$F1    = 'ZQXT F1 written before the upgrade was run';
$threw = '';
try {
    [$went, $outcome] = text('follow', 'prayer_assignment', $F1);
} catch (Throwable $e) {
    $threw = get_class($e) . ': ' . $e->getMessage();
    $went  = null;
    $outcome = '';
}
ok(
    'F1 new files, old database: a text for a person in their quiet hours cannot be kept (there is nowhere to keep its use-by moment), so it is NOT held. It is recorded as not sent, saying why, and the request is not broken',
    $threw === '' && $went === false && $outcome === 'not-sent' && [rowOf($F1)['status'] ?? '', rowOf($F1)['errorMsg'] ?? '', rowOf($F1)['sentAt'] ?? null] === ['failed', Sms::NOT_SENT_NO_ROOM, null] && RecordingSms::$calls === [],
    'threw ' . json_encode($threw) . '; ' . json_encode([$went, $outcome, rowOf($F1)])
);
at($wednesday);
$threw = '';
try {
    $notReady = run();
} catch (Throwable $e) {
    $threw    = get_class($e) . ': ' . $e->getMessage();
    $notReady = [];
}
ok(
    'F2 the job\'s run on that database does not throw and does not count a fault: it says "not ready", sends nothing, and leaves the two old held texts for the upgrade',
    $threw === '' && $notReady === ['ran' => 1, 'sent' => 0, 'failed' => 0, 'notSent' => 0, 'left' => 2, 'notReady' => 1, 'error' => 0] && RecordingSms::$calls === [],
    'threw ' . json_encode($threw) . '; ' . json_encode($notReady)
);
runSqlFile($migration);
$upgraded = q("SELECT status, errorMsg, sentAt, claimedAt, heldUseBy FROM tblSmsMessage WHERE body LIKE 'ZQXT F an old held text%' ORDER BY messageID");
ok(
    'F3 THE UPGRADE: migration 219 records both old held texts as not sent, in the same words the job uses, and empties their untrue "sent at"',
    $upgraded === array_fill(0, 2, ['status' => 'failed', 'errorMsg' => Sms::NOT_SENT_OLD, 'sentAt' => null, 'claimedAt' => null, 'heldUseBy' => null]),
    json_encode($upgraded)
);
ok(
    'F4 after the migration the table is EXACTLY as the install script makes it: every column, description and key, in the same order',
    $tableText() === $state['tableText'],
    "now:\n" . $tableText() . "\ninstall script:\n" . $state['tableText']
);
at($FRI20);
$F5 = 'ZQXT F5 held by the new code';
text('follow', 'prayer_assignment', $F5);
$heldNew = rowOf($F5);
runSqlFile($migration);
runSqlFile($migration);
ok(
    'F5 the migration is safe to run again, twice more: the table is unchanged, a text held by the NEW code is not touched (it has a use-by moment), and the migration is recorded once',
    $tableText() === $state['tableText'] && rowOf($F5) === $heldNew && $heldNew['status'] === 'queued'
        && (int) one("SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = '219_held_texts.sql'")['n'] === 1,
    json_encode([story(rowOf($F5)), one("SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = '219_held_texts.sql'")])
);
at($W1 + 60);
$afterUpgrade = run();
ok(
    'F6 the first run after the quiet hours end sends the one text the new code held, and none of the old ones',
    $afterUpgrade['sent'] === 1 && $afterUpgrade['notSent'] === 0 && RecordingSms::$calls === [[numberOf(WHO['follow']), $F5]],
    json_encode([$afterUpgrade, RecordingSms::$calls])
);

// =============================================================================
head('G. Never a flood');
// =============================================================================
fresh();
at($FRI20);
for ($n = 1; $n <= 30; $n++) {
    text('follow', 'prayer_assignment', 'ZQXT G1 text ' . $n);
}
at($W1 + 60);
$runOne = run(20);
at($W1 + 360);
$runTwo = run(20);
ok(
    'G1 thirty texts are due at once and a run hands over at most 20: the first run sends 20 and says 10 are left, the next sends the 10; each text once',
    $runOne['sent'] === 20 && $runOne['left'] === 10 && $runTwo['sent'] === 10 && $runTwo['left'] === 0 && count(RecordingSms::$calls) === 30 && count(array_unique(array_column(RecordingSms::$calls, 1))) === 30,
    json_encode([$runOne, $runTwo, count(RecordingSms::$calls)])
);

fresh();
at($FRI20);
for ($n = 1; $n <= 30; $n++) {
    text('follow', 'prayer_assignment', 'ZQXT G7 text ' . $n);
}
at($W1 + 60);
RecordingSms::$answer = 'refuse';
$refusedRun           = run(20);
RecordingSms::$answer = 'accept';
ok(
    'G7 thirty texts are due and the provider REFUSES every one: a run still hands over 20 and no more (a refused text counts towards the most in one run, or a failing provider would be handed every held text at once), and says 10 are left',
    $refusedRun === ['ran' => 1, 'sent' => 0, 'failed' => 20, 'notSent' => 0, 'left' => 10, 'notReady' => 0, 'error' => 0] && count(RecordingSms::$calls) === 20,
    json_encode([$refusedRun, count(RecordingSms::$calls)])
);

fresh();
at($FRI20);
text('follow', 'prayer_assignment', 'ZQXT G2 due, but the time is up');
text('follow', 'general', 'ZQXT G2 past its use-by');
at($W1 + 60);
$noTime = run(20, 0.0);
ok(
    'G2 a run whose time is already up starts no text (the due one stays held) but still corrects the record of the one past its use-by',
    $noTime === ['ran' => 1, 'sent' => 0, 'failed' => 0, 'notSent' => 1, 'left' => 1, 'notReady' => 0, 'error' => 0] && RecordingSms::$calls === []
        && story(rowOf('ZQXT G2 past its use-by')) === ['failed', Sms::NOT_SENT_USE_BY, null, null],
    json_encode([$noTime, RecordingSms::$calls])
);

fresh(['cap' => 3]);
at($FRI20);
for ($n = 1; $n <= 5; $n++) {
    text('follow', 'prayer_assignment', 'ZQXT G3 held text ' . $n);
}
at($W1 + 10);
text('off', 'rota_changes', 'ZQXT G3 sent at once, 1');
text('off', 'rota_changes', 'ZQXT G3 sent at once, 2');
at($W1 + 60);
$dayOne      = run();
$countDayOne = RecordingSms::sentTodayCount(ORG);
at($W1 + 360);
$dayOneAgain = run();
ok(
    'G3 THE DAY\'S LIMIT (3) when held texts come due: two texts have already gone today, so the run sends ONE of the five and leaves four held; the count for today is 3; a second run that day sends none',
    $dayOne['sent'] === 1 && $dayOne['left'] === 4 && $countDayOne === 3 && $dayOneAgain === quietRun(4),
    json_encode([$dayOne, $countDayOne, $dayOneAgain])
);
at($W1 + 60 + 86400);
$countDayTwo = RecordingSms::sentTodayCount(ORG);
$dayTwo      = run();
ok(
    'G4 the next day (on the database\'s own clock) the count starts again and the run sends three more, no more, leaving one. The text sent yesterday, which was WRITTEN the day before that, was counted on the day it was sent',
    $countDayTwo === 0 && $dayTwo['sent'] === 3 && $dayTwo['left'] === 1 && RecordingSms::sentTodayCount(ORG) === 3,
    json_encode([$countDayTwo, $dayTwo, RecordingSms::sentTodayCount(ORG)])
);
at($FRI20 + 172800);
$lastOne  = run();
$heldSent = count(array_filter(RecordingSms::$calls, static fn (array $c): bool => str_starts_with($c[1], 'ZQXT G3 held text') === true));
ok(
    'G5 the fifth text reaches its use-by moment with no room left that day: it is recorded as not sent. Four of the five went, each once, and never more than three texts on one day',
    $lastOne['sent'] === 0 && $lastOne['notSent'] === 1 && $lastOne['left'] === 0 && $heldSent === 4 && count(RecordingSms::$calls) === 6
        && (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE errorMsg = ? AND siteID = ?", 'si', [Sms::NOT_SENT_USE_BY, ORG])['n'] === 1,
    json_encode([$lastOne, $heldSent, count(RecordingSms::$calls)])
);
at($FRI20 + 172800 + 60);
[$went, $outcome] = text('off', 'rota_changes', 'ZQXT G6 one more today');
$capRow           = rowOf('ZQXT G6 one more today');
[$wentTwo]        = text('off', 'rota_changes', 'ZQXT G6 for the other organisation', null, ORG2);
ok(
    'G6 the limit still refuses a text written when the day is full, as it always did ("daily-cap"), and it is counted for each organisation by itself: the second organisation\'s text goes',
    $went === false && $outcome === 'failed' && $capRow['status'] === 'failed' && $capRow['errorMsg'] === 'daily-cap' && $wentTwo === true,
    json_encode([$went, $outcome, story($capRow), $wentTwo])
);

// =============================================================================
head('H. The person\'s choices while it waited');
// =============================================================================
fresh();
at($FRI20);
$H1 = 'ZQXT H1 for a person who then changes their number';
$H2 = 'ZQXT H2 for a person who then stops texts of this kind';
$H3 = 'ZQXT H3 a kind nobody opts in to';
$H4 = 'ZQXT H4 for a person whose account is then deleted';
text('changed', 'prayer_assignment', $H1);
text('optout', 'prayer_assignment', $H2);
text('optout', 'general', $H3, utc($FRI20 + 172800));
text('gone', 'prayer_assignment', $H4);
// While they wait: a new number that is not yet confirmed; one kind
// switched off; an account deleted (the link empties the row's person).
q("UPDATE tblUserSmsPreference SET phoneNumber = '+447700900888', isVerified = 0 WHERE siteID = ? AND userID = ?", 'ii', [ORG, WHO['changed']]);
q("UPDATE tblUserSmsPreference SET categories = 'critical_alerts,rota_changes' WHERE siteID = ? AND userID = ?", 'ii', [ORG, WHO['optout']]);
q('DELETE FROM tblUsers WHERE userID = ?', 'i', [WHO['gone']]);
at($W1 + 60);
$choices = run();
ok(
    'H1 a person changed their number while a text waited: it is NOT sent to the old number (which may be somebody else\'s by now), and the record says why',
    handed($H1) === 0 && story(rowOf($H1)) === ['failed', Sms::NOT_SENT_NUMBER, null, null],
    json_encode(story(rowOf($H1)))
);
q('UPDATE tblUserSmsPreference SET isVerified = 1 WHERE siteID = ? AND userID = ?', 'ii', [ORG, WHO['changed']]);
at($FRI20);
$H1b = 'ZQXT H1 held for the number as it was, which is then replaced by a confirmed one';
q("UPDATE tblUserSmsPreference SET phoneNumber = ? WHERE siteID = ? AND userID = ?", 'sii', [numberOf(WHO['changed']), ORG, WHO['changed']]);
text('changed', 'prayer_assignment', $H1b);
q("UPDATE tblUserSmsPreference SET phoneNumber = '+447700900889' WHERE siteID = ? AND userID = ?", 'ii', [ORG, WHO['changed']]);
at($W1 + 120);
$replaced = run();
ok(
    'H2 the same when the new number IS confirmed: the held text was for the old number, so it is not sent, to either',
    handed($H1b) === 0 && story(rowOf($H1b)) === ['failed', Sms::NOT_SENT_NUMBER, null, null] && $replaced['notSent'] === 1,
    json_encode([$replaced, story(rowOf($H1b))])
);
ok(
    'H3 a person stopped texts of that kind while one waited: it is not sent, and the record says so; a text of a kind nobody opts in to still goes to that same person',
    handed($H2) === 0 && story(rowOf($H2)) === ['failed', Sms::NOT_SENT_OPTED_OUT, null, null] && handed($H3) === 1 && (rowOf($H3)['status'] ?? '') === 'sent',
    json_encode([story(rowOf($H2)), story(rowOf($H3))])
);
$goneRow = rowOf($H4);
ok(
    'H4 a person\'s account was deleted while a text waited: it is not sent, and the record says so',
    handed($H4) === 0 && $goneRow['recipientUserID'] === null && story($goneRow) === ['failed', Sms::NOT_SENT_PERSON, null, null] && $choices['sent'] === 1 && $choices['notSent'] === 3,
    json_encode([$choices, $goneRow['recipientUserID'], story($goneRow)])
);
$addPerson(WHO['gone']); // put back for the parts that follow
foreach (['changed', 'optout'] as $what) {
    q('UPDATE tblUserSmsPreference SET phoneNumber = ?, isVerified = 1, categories = ? WHERE siteID = ? AND userID = ?', 'ssii', [numberOf(WHO[$what]), EVERY_KIND, ORG, WHO[$what]]);
}

fresh();
at($FRI20);
$H5 = 'ZQXT H5 for a person who then switches quiet hours off';
text('switch', 'prayer_assignment', $H5);
q("UPDATE tblUsers SET sabbathHonour = 'off' WHERE userID = ?", 'i', [WHO['switch']]);
at($W1 - 6 * 3600);
$switched = run();
ok(
    'H5 a person switches quiet hours off for themselves while a text waits: the next run sends it, on Saturday at noon, although the portal\'s quiet hours are still on. Their own choice decides, with no new switch',
    $switched['sent'] === 1 && handed($H5) === 1 && Sabbath::isQuietNow(WHO['follow'], $W1 - 6 * 3600) === true,
    json_encode($switched)
);
q("UPDATE tblUsers SET sabbathHonour = 'inherit' WHERE userID = ?", 'i', [WHO['switch']]);

fresh();
at($FRI20);
$H6 = 'ZQXT H6 held, and then texts are switched off';
text('follow', 'prayer_assignment', $H6);
portal(['sms' => '0']);
at($W1 + 60);
$off = run();
portal();
at($W1 + 360);
$onAgain = run();
ok(
    'H6 text messages are switched off for the installation when the quiet hours end: nothing is sent and the text stays held; switched on again five minutes later, it is sent',
    $off === quietRun(1) && $onAgain['sent'] === 1 && handed($H6) === 1,
    json_encode([$off, $onAgain])
);

// ---- Who may still be sent an organisation's text (the fix round). --------
// ONE organisation first, which is how every part above ran: nobody has a
// membership row, and nobody needs one.
fresh();
at($FRI20);
$H7  = 'ZQXT H7 for a person whose account is then switched off';
$H7b = 'ZQXT H7 for a person with no membership row, where there is one organisation';
text('offacct', 'prayer_assignment', $H7);
text('member', 'prayer_assignment', $H7b);
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [WHO['offacct']]);
at($W1 + 60);
$accountOff = run();
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [WHO['offacct']]);
ok(
    'H7 a person\'s ACCOUNT was switched off while a text waited: it is not sent, and the record says so in its own sentence. On an installation with one organisation a person with no membership row is still sent theirs',
    handed($H7) === 0 && story(rowOf($H7)) === ['failed', SAYS['NOT_SENT_ACCOUNT_OFF'], null, null] && handed($H7b) === 1 && $accountOff['sent'] === 1 && $accountOff['notSent'] === 1,
    json_encode([$accountOff, story(rowOf($H7)), story(rowOf($H7b))])
);

// SEVERAL organisations. Three people are members of this one; `follow` has
// never been; `global` is a global administrator with no membership row.
fresh(['multi' => 'true']);
foreach (['member', 'leaver', 'offacct'] as $what) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [WHO[$what], ORG]);
}
at($FRI20);
$H8 = ['member' => 'ZQXT H8 for a member', 'leaver' => 'ZQXT H8 for a person who then leaves', 'follow' => 'ZQXT H8 for a person who was never a member',
    'global' => 'ZQXT H8 for a global administrator', 'offacct' => 'ZQXT H8 for a member whose account is then switched off'];
foreach ($H8 as $what => $body) {
    text($what, 'prayer_assignment', $body);
}
q('UPDATE tblUserSites SET isActive = 0 WHERE userID = ? AND siteID = ?', 'ii', [WHO['leaver'], ORG]);
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [WHO['offacct']]);
at($W1 + 60);
$several = run();
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [WHO['offacct']]);
// THIS CHECK WAS CHANGED TWICE IN ONE DAY, which is worth recording. When
// send() asked the person question BEFORE holding, a person who was never a
// member was refused as the text was sent, in the present-tense words, and
// this check was rewritten to say so. That order was then found to be wrong
// (it lost a text for anybody suspended and reinstated inside two days: see
// M9), so the question moved to after the hold -- and these texts are all
// written INSIDE quiet hours, so every one of them is held and every refusal
// happens on the held path again, in the "when they ended" words. The lesson
// is not about this check: it is that moving a question between two moments
// changes what is true, and every test that reads those words has to be read
// again. Nothing here is loosened; all four refusals are still asserted
// word for word.
ok(
    'H8 with SEVERAL organisations, a text goes only to somebody who could open that organisation\'s pages: a member and a global administrator are sent theirs; and because these are all written inside quiet hours, each refusal happens when the text comes DUE -- somebody who left while it waited, somebody who was never a member, and somebody whose account was switched off, each in the "when they ended" words',
    handed($H8['member']) === 1 && handed($H8['global']) === 1 && handed($H8['leaver']) === 0 && handed($H8['follow']) === 0 && handed($H8['offacct']) === 0
        && story(rowOf($H8['leaver'])) === ['failed', SAYS['NOT_SENT_NOT_MEMBER'], null, null]
        && story(rowOf($H8['follow'])) === ['failed', SAYS['NOT_SENT_NOT_MEMBER'], null, null]
        && story(rowOf($H8['offacct'])) === ['failed', SAYS['NOT_SENT_ACCOUNT_OFF'], null, null]
        && $several['sent'] === 2 && $several['notSent'] === 3,
    json_encode([$several, array_map(static fn (string $body): array => story(rowOf($body)), $H8)])
);

fresh(['multi' => 'true']);
at($FRI20);
$H9  = 'ZQXT H9 for a member of an organisation that is then switched off';
$H9g = 'ZQXT H9 for a global administrator, of that same organisation';
text('member', 'prayer_assignment', $H9);
text('global', 'prayer_assignment', $H9g);
q('UPDATE tblSites SET isActive = 0 WHERE siteID = ?', 'i', [ORG]);
at($W1 + 60);
$orgOff = run();
q('UPDATE tblSites SET isActive = 1 WHERE siteID = ?', 'i', [ORG]);
at($W1 + 360);
$orgOnAgain = run();
ok(
    'H9 the ORGANISATION was switched off while its texts waited: nobody is sent one, not its member and not a global administrator, and each record says the organisation had been switched off. Switching it on again does not bring them back',
    handed($H9) === 0 && handed($H9g) === 0 && story(rowOf($H9)) === ['failed', SAYS['NOT_SENT_ORG_OFF'], null, null] && story(rowOf($H9g)) === ['failed', SAYS['NOT_SENT_ORG_OFF'], null, null]
        && $orgOff['notSent'] === 2 && $orgOff['sent'] === 0 && $orgOnAgain === quietRun(0),
    json_encode([$orgOff, $orgOnAgain, story(rowOf($H9)), story(rowOf($H9g))])
);
q('DELETE FROM tblUserSites WHERE siteID = ?', 'i', [ORG]);

fresh();
at($FRI20);
$H10 = 'ZQXT H10 for a number that is then no longer confirmed';
text('changed', 'prayer_assignment', $H10);
// The SAME number, but no longer confirmed (which is how the account page
// leaves a number while its code has not been typed in).
q('UPDATE tblUserSmsPreference SET isVerified = 0 WHERE siteID = ? AND userID = ?', 'ii', [ORG, WHO['changed']]);
at($W1 + 60);
$unconfirmed = run();
q('UPDATE tblUserSmsPreference SET isVerified = 1 WHERE siteID = ? AND userID = ?', 'ii', [ORG, WHO['changed']]);
ok(
    'H10 the number is the same but is NO LONGER CONFIRMED when the quiet hours end: the text is not sent, and the record says the number was not the person\'s confirmed number',
    handed($H10) === 0 && story(rowOf($H10)) === ['failed', Sms::NOT_SENT_NUMBER, null, null] && $unconfirmed['notSent'] === 1 && $unconfirmed['sent'] === 0,
    json_encode([$unconfirmed, story(rowOf($H10))])
);

// An account switched off and ON AGAIN while the quiet hours still run. The
// question "may this person still be sent it?" belongs to the moment the
// text comes due, not to every run before it.
fresh();
at($FRI20);
$H11 = 'ZQXT H11 for a person whose account is off for two hours on the Saturday morning';
text('offacct', 'prayer_assignment', $H11);
$heldH11 = rowOf($H11);
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [WHO['offacct']]);
at($W1 - 6 * 3600);
$whileOff = run();
$stillH11 = rowOf($H11);
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [WHO['offacct']]);
at($W1 + 60);
$backOn = run();
ok(
    'H11 an account is switched off and on again WHILE the quiet hours still run: a run of the job in between leaves the text held, the same in every column (nothing is decided before the text comes due), and when the quiet hours end it is sent',
    $whileOff === quietRun(1) && $stillH11 === $heldH11 && $heldH11['status'] === 'queued' && $backOn['sent'] === 1 && handed($H11) === 1 && (rowOf($H11)['status'] ?? '') === 'sent',
    json_encode([$whileOff, story($stillH11), $backOn, story(rowOf($H11))])
);

// The membership question cannot be asked at all: the table it reads is
// hidden for one run. A text is not sent on a guess.
fresh(['multi' => 'true']);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [WHO['member'], ORG]);
at($FRI20);
$H12 = 'ZQXT H12 due while nobody can say who is a member';
text('member', 'prayer_assignment', $H12);
$heldH12 = rowOf($H12);
$mysqli->query('RENAME TABLE tblUserSites TO tblUserSitesZqHidden');
at($W1 + 60);
$threw = '';
try {
    $cannotAsk = run();
} catch (Throwable $e) {
    $threw     = get_class($e);
    $cannotAsk = [];
}
$afterH12 = rowOf($H12);
$mysqli->query('RENAME TABLE tblUserSitesZqHidden TO tblUserSites');
at($W1 + 360);
$canAsk = run();
q('DELETE FROM tblUserSites WHERE siteID = ?', 'i', [ORG]);
ok(
    'H12 when the portal cannot ask whether the person is a member (the membership table is hidden for one run), the text is NOT sent on a guess: the run does not throw and says a fault stopped it, nothing is handed over, and the text is still held and untaken, the same in every column. The next run, with the table back, sends it',
    $threw === '' && ($cannotAsk['error'] ?? 0) === 1 && ($cannotAsk['sent'] ?? -1) === 0 && ($cannotAsk['left'] ?? -1) === 1 && $afterH12 === $heldH12 && $heldH12['claimedAt'] === null
        && $canAsk['sent'] === 1 && handed($H12) === 1,
    'threw ' . json_encode($threw) . '; ' . json_encode([$cannotAsk, story($afterH12), $canAsk, handed($H12)])
);

// =============================================================================
head('I. A restored backup');
// =============================================================================
fresh();
at($FRI20);
$I1a = 'ZQXT I1 held when the backup comes back';
$I1b = 'ZQXT I1 sent at once, long ago';
text('follow', 'prayer_assignment', $I1a);
text('off', 'rota_changes', $I1b);
$sentRow = rowOf($I1b);
$moved   = Sms::holdRestored($mysqli);
at($W1 + 60);
$afterRestore = run();
ok(
    'I1 the statement a restore runs: every held text is recorded as not sent, in plain words that say it came back with a backup and may already have gone; a text that was sent is not touched; and the job then sends nothing',
    $moved === 1 && story(rowOf($I1a)) === ['failed', Sms::NOT_SENT_RESTORED, null, null] && rowOf($I1b) === $sentRow && $afterRestore === quietRun(0) && handed($I1a) === 0,
    json_encode([$moved, story(rowOf($I1a)), $afterRestore])
);

// The real thing, with the real backup class: a text is HELD when the backup
// is taken, it then GOES, and the backup is put back.
fresh();
at($FRI20);
$I2 = 'ZQXT I2 held when the backup was taken, sent before it was put back';
text('follow', 'prayer_assignment', $I2);
$mysqli->query('SET timestamp = DEFAULT'); // the backup class reads the real clock for its own names
@mkdir($scratch . $sep . 'backups', 0700, true);
$backup = new DbBackup($mysqli, $scratch . $sep . 'backups');
$snap   = $backup->snapshot('held texts self-test');
$dir    = (string) ($snap['directory'] ?? '');
at($W1 + 60);
$wentOnce = run();
$mysqli->query('SET timestamp = DEFAULT');
$asSent    = rowOf($I2);
$rehearsal = ($snap['success'] ?? false) === true ? $backup->restoreAll($dir, true) : ['error' => 'no snapshot'];
$tryWords  = implode(' ', (array) ($rehearsal['warnings'] ?? []));
ok(
    'I2 a backup is taken while a text is held, and the text then goes. A REHEARSAL of putting that backup back changes nothing (the text is still recorded as sent) and says that 1 text message WOULD be recorded as not sent',
    ($snap['success'] ?? false) === true && $wentOnce['sent'] === 1 && ($rehearsal['success'] ?? false) === true && rowOf($I2) === $asSent && $asSent['status'] === 'sent'
        && str_contains($tryWords, '1 text message that was held for quiet hours') === true && str_contains($tryWords, 'would be recorded as NOT SENT') === true,
    'snapshot ' . json_encode($snap['error'] ?? null) . '; rehearsal ' . json_encode($rehearsal['error'] ?? null) . '; it said: ' . $tryWords
);
$oneTable = ($snap['success'] ?? false) === true ? $backup->restoreTable($dir, 'tblSmsMessage') : ['error' => 'no snapshot'];
$mysqli->query("SET time_zone = '" . DB_ZONE . "'"); // a restore puts the connection's zone back to what it found; say it again all the same
at($W1 + 120);
$afterOne = run();
ok(
    'I3 putting back ONLY the table of text messages brings the row back as it was in the backup, held, and the restore records it as not sent and says so. The job then does NOT send it a second time',
    ($oneTable['success'] ?? false) === true && story(rowOf($I2)) === ['failed', Sms::NOT_SENT_RESTORED, null, null] && str_contains((string) ($oneTable['notice'] ?? ''), '1 text message') === true
        && str_contains((string) ($oneTable['notice'] ?? ''), 'has been recorded as NOT SENT') === true && $afterOne === quietRun(0) && handed($I2) === 1,
    'restore ' . json_encode($oneTable) . '; ' . json_encode([story(rowOf($I2)), $afterOne, handed($I2)])
);
$mysqli->query('SET timestamp = DEFAULT');
$whole = ($snap['success'] ?? false) === true ? $backup->restoreAll($dir, false) : ['error' => 'no snapshot'];
$mysqli->query("SET time_zone = '" . DB_ZONE . "'");
$said = implode(' ', (array) ($whole['warnings'] ?? []));
at($W1 + 180);
$afterWhole = run();
ok(
    'I4 the same for a restore of the WHOLE database: the text comes back held, is recorded as not sent, the restore says so, and the job does not send it again. It was handed to the provider once in all',
    ($whole['success'] ?? false) === true && story(rowOf($I2)) === ['failed', Sms::NOT_SENT_RESTORED, null, null] && str_contains($said, '1 text message that was held for quiet hours') === true
        && str_contains($said, 'has been recorded as NOT SENT') === true && $afterWhole === quietRun(0) && handed($I2) === 1,
    'restore ' . json_encode($whole['error'] ?? null) . '; it said: ' . $said . '; ' . json_encode([story(rowOf($I2)), $afterWhole, handed($I2)])
);

// A DIFFERENT table is put back from that backup while a text is waiting
// now. The texts were not touched by the restore, so they must not be.
fresh();
at($FRI20);
$I5 = 'ZQXT I5 held while another table is put back';
text('follow', 'prayer_assignment', $I5);
$heldI5 = rowOf($I5);
$mysqli->query('SET timestamp = DEFAULT');
$other = ($snap['success'] ?? false) === true ? $backup->restoreTable($dir, 'tblUserSmsPreference') : ['error' => 'no snapshot'];
$mysqli->query("SET time_zone = '" . DB_ZONE . "'");
$afterOther = rowOf($I5);
at($W1 + 60);
$sentI5 = run();
ok(
    'I5 putting back a DIFFERENT table from a backup leaves the texts that are waiting NOW alone: the held text is the same in every column, the restore says nothing of text messages, and it is sent when the quiet hours end',
    ($other['success'] ?? false) === true && $afterOther === $heldI5 && $heldI5['status'] === 'queued' && str_contains((string) ($other['notice'] ?? ''), 'text message') === false
        && $sentI5['sent'] === 1 && handed($I5) === 1,
    'restore ' . json_encode($other) . '; ' . json_encode([story($afterOther), $sentI5])
);

// =============================================================================
head('J. The real job page');
// =============================================================================
fresh();
at($FRI20);
$J1 = 'ZQXT J1 due when the job is called';
$J2 = 'ZQXT J2 past its use-by when the job is called';
text('follow', 'prayer_assignment', $J1);
text('follow', 'general', $J2);
oldHeld('follow', 'ZQXT J3 held by the old code', $FRI20 - 30 * 86400);
$asHeld = rowOf($J1);
$wrong  = page($W1 + 60, ['token' => 'not-the-token']);
ok(
    'J1 the job\'s page with a WRONG token answers "forbidden" and nothing else, and the texts step does not run: the due text is untouched',
    $wrong['json'] === ['status' => 'forbidden'] && rowOf($J1) === $asHeld && story(rowOf($J2))[0] === 'queued',
    $wrong['out'] . ' ' . json_encode(story(rowOf($J1)))
);
$right   = page($W1 + 60);
$notInts = array_filter(array_diff_key((array) $right['json'], ['status' => 1]), static fn (mixed $v): bool => is_int($v) === false);
ok(
    'J2 with the right token the page answers ONE JSON document, a status word and plain numbers only: the mail step\'s status and numbers as before, and the texts step\'s own numbers, each under a name that begins "texts" (one handed over, two recorded as not sent, none left)',
    is_array($right['json']) === true && ($right['json']['status'] ?? '') === 'ok' && ($right['json']['ran'] ?? 0) === 1 && array_key_exists('leftQueued', $right['json']) === true
        && textsOf($right['json']) === ['ran' => 1, 'sent' => 0, 'failed' => 1, 'notSent' => 2, 'left' => 0, 'notReady' => 0, 'error' => 0] && $notInts === [] && substr_count(trim($right['out']), "\n") === 0,
    $right['out']
);
ok(
    'J3 the due text reached the REAL class\'s hand-over through the page, once: it was taken, and the provider\'s own answer is "not configured", because this test names no provider account. So nothing left the machine. The other two are recorded as not sent',
    array_slice(story(rowOf($J1)), 0, 2) === ['failed', 'Held for quiet hours, then refused by the provider: twilio-not-configured'] && rowOf($J1)['claimedAt'] === utc($W1 + 60)
        && story(rowOf($J2)) === ['failed', Sms::NOT_SENT_USE_BY, null, null] && story(rowOf('ZQXT J3 held by the old code')) === ['failed', Sms::NOT_SENT_OLD, null, null],
    json_encode([story(rowOf($J1)), story(rowOf($J2)), story(rowOf('ZQXT J3 held by the old code'))])
);
$activity = q("SELECT activityType, activityDescription FROM tblActivityLogs WHERE logID > ? AND activityType = 'HeldTextsRun' ORDER BY logID", 'i', [$state['firstActivityId']]);
$private  = [numberOf(WHO['follow']), substr(numberOf(WHO['follow']), 3), 'ZQXT', nameOf(WHO['follow']), 'Textperson'];
$leaked   = array_values(array_filter($private, static fn (string $p): bool => str_contains($right['out'] . json_encode($activity) . $right['log'], $p) === true));
ok(
    'J4 the texts step writes its own line in the activity log, of numbers only; and neither that line, nor the page\'s answer, nor the error log holds a phone number, a name or any part of a text',
    count($activity) === 1 && $activity[0]['activityDescription'] === 'Held texts: 0 sent after quiet hours, 1 refused by the provider, 2 recorded as not sent, 0 still held' && $leaked === [],
    json_encode($activity) . '; found: ' . json_encode($leaked)
);

fresh();
at($FRI20);
$J5 = 'ZQXT J5 due while the mail step finds another run busy';
text('follow', 'prayer_assignment', $J5);
$second->query("SELECT GET_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())), 0)")->free();
$busy = page($W1 + 60);
$second->query("DO RELEASE_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())))");
ok(
    'J5 the mail step finds another run busy and does nothing: the page still says "busy" for mail, AND the texts step ran (the due text was taken and handed over)',
    ($busy['json']['status'] ?? '') === 'busy' && ($busy['json']['ran'] ?? -1) === 0 && (textsOf($busy['json'])['ran'] ?? 0) === 1 && (textsOf($busy['json'])['failed'] ?? 0) === 1 && rowOf($J5)['claimedAt'] === utc($W1 + 60),
    $busy['out']
);

fresh();
at($FRI20);
$J6 = 'ZQXT J6 due while the mail step is stopped by a fault';
text('follow', 'prayer_assignment', $J6);
$mysqli->query('RENAME TABLE tblMailQueue TO tblMailQueueZqHidden');
$mailFault = page($W1 + 60);
$mysqli->query('RENAME TABLE tblMailQueueZqHidden TO tblMailQueue');
ok(
    'J6 the mail step is stopped by a fault (its table is not there): the page says "error" for mail, AND the texts step still ran and dealt with the due text',
    ($mailFault['json']['status'] ?? '') === 'error' && textsOf($mailFault['json']) === ['ran' => 1, 'sent' => 0, 'failed' => 1, 'notSent' => 0, 'left' => 0, 'notReady' => 0, 'error' => 0]
        && rowOf($J6)['claimedAt'] === utc($W1 + 60),
    $mailFault['out']
);

fresh();
$mysqli->query('RENAME TABLE tblSmsMessage TO tblSmsMessageZqHidden');
$textFault = page($W1 + 60);
$mysqli->query('RENAME TABLE tblSmsMessageZqHidden TO tblSmsMessage');
ok(
    'J7 the TEXTS step is stopped by a fault (its table is not there): the mail step\'s answer is untouched ("ok", with its numbers), the texts numbers say a fault stopped the run, and the error log names the kind of fault and its number but none of its words',
    ($textFault['json']['status'] ?? '') === 'ok' && ($textFault['json']['ran'] ?? 0) === 1 && textsOf($textFault['json']) === ['ran' => 1, 'sent' => 0, 'failed' => 0, 'notSent' => 0, 'left' => 0, 'notReady' => 0, 'error' => 1]
        && str_contains($textFault['log'], 'Held texts: the run was stopped: mysqli_sql_exception (1146)') === true && str_contains($textFault['log'], 'tblSmsMessage') === false,
    $textFault['out'] . ' / log: ' . $textFault['log']
);

fresh();
at($FRI20);
$J8 = 'ZQXT J8 due while the class file is missing';
text('follow', 'prayer_assignment', $J8);
$asHeld  = rowOf($J8);
$noClass = page($W1 + 60, ['missing' => ['Sms']]);
ok(
    'J8 a half-finished upload (the class file of the texts step is missing): the mail step\'s answer is still printed, "ok", and the texts numbers say the step was stopped; the due text is untouched',
    ($noClass['json']['status'] ?? '') === 'ok' && textsOf($noClass['json']) === ['ran' => 0, 'sent' => 0, 'failed' => 0, 'notSent' => 0, 'left' => 0, 'notReady' => 0, 'error' => 1]
        && rowOf($J8) === $asHeld && str_contains($noClass['log'], 'the held texts step stopped: Error') === true,
    $noClass['out'] . ' / log: ' . $noClass['log']
);
$second->query("SELECT GET_LOCK(CONCAT('portal-held-texts:', SHA1(DATABASE())), 0)")->free();
$textsBusy = page($W1 + 60);
$second->query("DO RELEASE_LOCK(CONCAT('portal-held-texts:', SHA1(DATABASE())))");
ok(
    'J9 another run of the texts step holds its lock: the page\'s mail step is "ok", the texts step says it did not run, and the due text is untouched',
    ($textsBusy['json']['status'] ?? '') === 'ok' && (textsOf($textsBusy['json'])['ran'] ?? 1) === 0 && rowOf($J8) === $asHeld,
    $textsBusy['out']
);

// Twenty-five texts due through the real page, with something watching the
// ORDER of the two steps: a trigger that writes down, at the moment each
// text is taken, whether the mail step had already recorded "the job ran"
// (it does that first of all) and whether it was no longer running (its
// lock was free). The record of the job is emptied first, so that a mark
// can only be this call's own.
fresh();
at($FRI20);
for ($n = 1; $n <= 25; $n++) {
    text('follow', 'prayer_assignment', 'ZQXT J10 text ' . $n);
}
$mysqli->query('DROP TRIGGER IF EXISTS ' . PROBE_HOOK);
$mysqli->query('DROP TABLE IF EXISTS ' . PROBE_TABLE);
$mysqli->query('CREATE TABLE ' . PROBE_TABLE . ' (probeID INT NOT NULL AUTO_INCREMENT PRIMARY KEY, mailMarkSet INT NOT NULL, mailLockFree INT NOT NULL) ENGINE=InnoDB');
$mysqli->query(
    'CREATE TRIGGER ' . PROBE_HOOK . ' BEFORE UPDATE ON tblSmsMessage FOR EACH ROW BEGIN IF NEW.claimedAt IS NOT NULL AND OLD.claimedAt IS NULL THEN '
    . 'INSERT INTO ' . PROBE_TABLE . " (mailMarkSet, mailLockFree) VALUES ((SELECT COUNT(*) FROM tblMailRouteState WHERE routeKey = 'installation' AND jobLastRanAt IS NOT NULL), "
    . "IS_FREE_LOCK(CONCAT('portal-mail-queue:', SHA1(DATABASE())))); END IF; END"
);
q("DELETE FROM tblMailRouteState WHERE routeKey = 'installation'");
$many  = page($W1 + 60);
$probe = q('SELECT mailMarkSet, mailLockFree FROM ' . PROBE_TABLE . ' ORDER BY probeID');
$mysqli->query('DROP TRIGGER IF EXISTS ' . PROBE_HOOK);
$mysqli->query('DROP TABLE IF EXISTS ' . PROBE_TABLE);
ok(
    'J10 twenty-five texts are due and the real page is called once: it hands over 20 and no more, says 5 are left, and 20 rows are taken',
    ($many['json']['status'] ?? '') === 'ok' && textsOf($many['json']) === ['ran' => 1, 'sent' => 0, 'failed' => 20, 'notSent' => 0, 'left' => 5, 'notReady' => 0, 'error' => 0]
        && (int) one('SELECT COUNT(*) AS n FROM tblSmsMessage WHERE claimedAt IS NOT NULL AND siteID = ?', 'i', [ORG])['n'] === 20,
    $many['out']
);
ok(
    'J11 THE MAIL STEP COMES FIRST: at the moment each of those 20 texts was taken, the mail step had already recorded that the job ran, and was no longer running',
    count($probe) === 20 && array_values(array_unique(array_map(static fn (array $r): string => (int) $r['mailMarkSet'] . '/' . (int) $r['mailLockFree'], $probe))) === ['1/1'],
    count($probe) . ' text(s) seen being taken; "mark set / lock free" for each: ' . json_encode(array_count_values(array_map(static fn (array $r): string => (int) $r['mailMarkSet'] . '/' . (int) $r['mailLockFree'], $probe)))
);

// =============================================================================
head('K. Both clock-change nights, in two zones, with the database\'s clock in that same zone');
// =============================================================================
// For each zone, the night the clocks go BACK (an hour on the clock happens
// twice) and the night they go FORWARD (an hour does not exist). X is the
// moment of the change. The quiet hours are made to end AFTER it, by the
// installation's own "end offset" setting, so the change is inside them.
$zoneChecks = 0;
foreach ([QUIET_ZONE, ZONE_TWO] as $zone) {
    $nights = ['back' => null, 'forward' => null];
    foreach (clockChanges($zone, 732) as $change) {
        $kind          = $change['after'] < $change['before'] ? 'back' : 'forward';
        $nights[$kind] ??= $change;
    }
    foreach ($nights as $kind => $change) {
        if ($change === null) {
            ok('K ' . $zone . ': PHP\'s own time-zone data names a night in the next two years when the clocks go ' . $kind, false, 'none found');
            continue;
        }
        $zoneChecks++;
        $X   = $change['ts'];
        $tz  = new DateTimeZone($zone);
        // The Saturday of that weekend, on that zone's clock: the change is
        // in the small hours of the Sunday, or at the Saturday's midnight.
        $sat = (new DateTimeImmutable('@' . ($X - 7 * 3600)))->setTimezone($tz);
        $sat = $sat->format('N') === '7' ? $sat->modify('-1 day') : $sat;
        [$k0, $k1] = weekendOf($sat->format('Y-m-d'), $zone);
        $name      = 'K ' . $zone . ', clocks go ' . $kind . ' (' . gmdate('j M Y H:i', $X) . ' UTC)';
        $stmt      = $mysqli->prepare('SET time_zone = ?');
        $stmt->bind_param('s', $zone);
        $stmt->execute();
        $stmt->close();

        // ---- 1. The quiet hours end half an hour AFTER the change.
        $endOffset = intdiv($X + 1800 - $k1, 60);
        fresh(['zone' => $zone, 'endOffset' => $endOffset]);
        $end    = $X + 1800;
        $agrees = $sat->format('N') === '6' && $k0 < $X && $X < $end && ($X + 1800 - $k1) % 60 === 0
            && Sabbath::isQuietNow(WHO['follow'], $end) === true && Sabbath::isQuietNow(WHO['follow'], $end + 1) === false && Sabbath::isQuietNow(WHO['follow'], $k0) === true && Sabbath::isQuietNow(WHO['follow'], $k0 - 1) === false;
        at($X - 3600);
        $K1 = 'ZQXT K keeps, held an hour before the clocks change, ' . $zone . ' ' . $kind;
        text('follow', 'prayer_assignment', $K1);
        $stored = rowOf($K1)['heldUseBy'] ?? null;
        at($X - 900);
        $early      = run();
        $earlyClock = (string) one('SELECT NOW() AS n')['n'];
        ok(
            $name . ': the weekend is real and the change is inside the quiet hours; a text held an hour before the change has its use-by moment stored in UTC, exactly 48 hours on; a quarter of an hour before the change the quiet hours are still on and nothing is sent',
            $agrees === true && $stored === utc($X - 3600 + 172800) && $early === quietRun(1) && RecordingSms::$calls === [],
            'this file and Sabbath agree: ' . json_encode($agrees) . '; use-by ' . json_encode($stored) . ', wanted ' . utc($X - 3600 + 172800) . '; ' . json_encode($early)
        );
        // Three quarters of an hour AFTER the change. On the night the
        // clocks go back, the clock on the wall reads the same as it did a
        // quarter of an hour BEFORE the change: the hour is happening for
        // the second time. The quiet hours ended a quarter of an hour ago.
        at($X + 2700);
        $lateClock = (string) one('SELECT NOW() AS n')['n'];
        $late      = run();
        $row       = rowOf($K1);
        ok(
            $name . ': three quarters of an hour after the change the quiet hours have ended and the text is sent, once' . ($kind === 'back' ? ', although the database\'s own clock reads exactly what it read when the text was still held (' . $earlyClock . ')' : '')
                . '; "taken" is that moment in UTC and "sent at" is what that clock read',
            $late['sent'] === 1 && handed($K1) === 1 && $row['status'] === 'sent' && $row['claimedAt'] === utc($X + 2700) && $row['sentAt'] === onClock($X + 2700, $zone)
                && ($kind === 'back' ? $earlyClock === $lateClock : $earlyClock !== $lateClock),
            json_encode([$late, story($row), 'the clock before: ' . $earlyClock, 'after: ' . $lateClock, 'wanted taken ' . utc($X + 2700)])
        );

        // ---- 2. The quiet hours end an hour and a half after the change,
        //         and a text's two hours run out INSIDE them, an hour after
        //         the change: its two hours span the change.
        fresh(['zone' => $zone, 'endOffset' => intdiv($X + 5400 - $k1, 60)]);
        at($X - 3600);
        $K2 = 'ZQXT K about now, held an hour before the clocks change, ' . $zone . ' ' . $kind;
        text('follow', 'general', $K2);
        $stored = rowOf($K2)['heldUseBy'] ?? null;
        at($X + 3599);
        $oneSecondLeft = run();
        $stillHeld     = story(rowOf($K2));
        ok(
            $name . ': a text that keeps for two hours, held an hour before the change, is still held one second before those two EXACT hours are up (an hour after the change, less a second)',
            $stored === utc($X + 3600) && $oneSecondLeft === quietRun(1) && $stillHeld === ['queued', Sms::HELD_REASON, null, null],
            'use-by ' . json_encode($stored) . ', wanted ' . utc($X + 3600) . '; ' . json_encode([$oneSecondLeft, $stillHeld])
        );
        at($X + 3600);
        $up = run();
        at($X + 5400 + 60);
        $ended = run();
        ok(
            $name . ': at two exact hours it is recorded as not sent, and when the quiet hours end half an hour later it is not sent; it was never handed over',
            $up['notSent'] === 1 && $up['sent'] === 0 && story(rowOf($K2)) === ['failed', Sms::NOT_SENT_USE_BY, null, null] && $ended === quietRun(0) && RecordingSms::$calls === [],
            json_encode([$up, $ended, story(rowOf($K2)), RecordingSms::$calls])
        );
    }
}
$mysqli->query("SET time_zone = '" . DB_ZONE . "'");
ok(
    'K all four nights were found and tried: back and forward, in ' . QUIET_ZONE . ' and in ' . ZONE_TWO,
    $zoneChecks === 4,
    $zoneChecks . ' of 4'
);

// =============================================================================
head('L. The words, the month\'s cost, and what is written down');
// =============================================================================
$words = (new ReflectionClass(Sms::class))->getConstants();
$said  = array_filter($words, static fn ($value, string $name): bool => is_string($value) === true && (str_starts_with($name, 'NOT_SENT_') === true || in_array($name, ['SENT_AFTER_HOLD', 'UNKNOWN_INTERRUPTED'], true) === true), ARRAY_FILTER_USE_BOTH);
$tooLong  = array_keys(array_filter($said, static fn (string $value): bool => mb_strlen($value) > 255));
$notPlain = array_keys(array_filter($said, static fn (string $value, string $name): bool => str_starts_with($name, 'NOT_SENT_') === true && str_starts_with($value, 'Not sent: quiet hours. ') === false, ARRAY_FILTER_USE_BOTH));
ok(
    'L1 every sentence the class records fits the column it is stored in (255 characters; the connection is strict, so a longer one would be an error), and every "not sent" one begins "Not sent: quiet hours."',
    count($said) === 13 && $tooLong === [] && $notPlain === [],
    count($said) . ' sentences; too long: ' . json_encode($tooLong) . '; not beginning with the words: ' . json_encode($notPlain)
);
$atOnce = array_filter($words, static fn ($value, string $name): bool => is_string($value) === true && str_starts_with($name, 'AT_ONCE_') === true, ARRAY_FILTER_USE_BOTH);
$atOnceLong  = array_keys(array_filter($atOnce, static fn (string $value): bool => mb_strlen($value) > 255));
$atOnceWrong = array_keys(array_filter($atOnce, static fn (string $value): bool => str_starts_with($value, 'Not sent: ') === false || str_contains($value, 'quiet hours') === true));
ok(
    'L1b the four sentences for a text sent AT ONCE fit the same column, begin "Not sent: ", and none of them mentions quiet hours (a text that was never held has none), and each is word for word the copy written out in this file',
    count($atOnce) === 4 && $atOnceLong === [] && $atOnceWrong === []
        && $atOnce['AT_ONCE_PERSON'] === SAYS['AT_ONCE_PERSON'] && $atOnce['AT_ONCE_ACCOUNT_OFF'] === SAYS['AT_ONCE_ACCOUNT_OFF']
        && $atOnce['AT_ONCE_NOT_MEMBER'] === SAYS['AT_ONCE_NOT_MEMBER'] && $atOnce['AT_ONCE_ORG_OFF'] === SAYS['AT_ONCE_ORG_OFF'],
    count($atOnce) . ' sentences; too long: ' . json_encode($atOnceLong) . '; wrongly worded: ' . json_encode($atOnceWrong)
);
ok(
    'L2 the sentence migration 219 writes on an old held text is, word for word, the one the class writes',
    str_contains((string) file_get_contents($migration), "'" . Sms::NOT_SENT_OLD . "'") === true,
    Sms::NOT_SENT_OLD
);

fresh();
at($FRI20);
q("UPDATE tblUserSmsPreference SET categories = 'critical_alerts' WHERE siteID = ? AND userID NOT IN (?, ?, ?)", 'iiii', [ORG, WHO['follow'], WHO['on'], WHO['off']]);
(new ReflectionProperty(Sms::class, 'settledInRequest'))->setValue(null, false);
$counts = RecordingSms::sendCategory(ORG, 'rota_changes', 'ZQXT L3 a rota change for everybody who asked');
q('UPDATE tblUserSmsPreference SET categories = ? WHERE siteID = ?', 'si', [EVERY_KIND, ORG]);
ok(
    'L3 a broadcast to everybody who asked for a kind, on a Friday evening: the one person outside quiet hours gets it now, the two inside are held, and the answer says so (1 sent, 2 that did not go now, of which 2 are held)',
    $counts === ['sent' => 1, 'skipped' => 2, 'held' => 2] && count(RecordingSms::$calls) === 1 && RecordingSms::$calls[0][0] === numberOf(WHO['off'])
        && (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'queued' AND siteID = ?", 'i', [ORG])['n'] === 2,
    json_encode([$counts, RecordingSms::$calls])
);

// The month's cost. A weekend whose Saturday is the first of a month, with
// the database's clock on the quiet hours' own zone, so that the Friday
// evening is in one month and the Saturday evening in the next.
$first = saturdayTheFirst();
[$m0, $m1] = weekendOf($first, QUIET_ZONE);
$mysqli->query("SET time_zone = '" . QUIET_ZONE . "'");
fresh();
at($m0 + 7200);
$L4a = 'ZQXT L4 sent at once on the last evening of a month';
$L4b = 'ZQXT L4 held on the last evening of a month';
text('off', 'rota_changes', $L4a);
text('follow', 'prayer_assignment', $L4b);
$lastMonth = RecordingSms::monthSpendPence(ORG);
at($m1 + 60);
$newMonthBefore = RecordingSms::monthSpendPence(ORG);
run();
$newMonthAfter = RecordingSms::monthSpendPence(ORG);
$mysqli->query("SET time_zone = '" . DB_ZONE . "'");
ok(
    'L4 THE COST: a text held on the last evening of a month and sent on the first of the next costs 4p in the month it WENT. Last month\'s spend was the one text sent at once (4p); the new month\'s is 0p before the run and 4p after it',
    $lastMonth === 4 && $newMonthBefore === 0 && $newMonthAfter === 4 && (int) rowOf($L4b)['costPence'] === 4 && substr((string) rowOf($L4b)['createdAt'], 0, 7) !== substr((string) rowOf($L4b)['sentAt'], 0, 7),
    json_encode([$lastMonth, $newMonthBefore, $newMonthAfter, rowOf($L4b)['createdAt'], rowOf($L4b)['sentAt']]) . ' (the weekend of Saturday ' . $first . ')'
);

$errors = is_file($scratch . $sep . 'errors.log') === true ? (string) file_get_contents($scratch . $sep . 'errors.log') : '';
$found  = array_values(array_filter(['+447700900', '7700900', 'ZQXT', 'Textperson', 'the run was ended while handing over'], static fn (string $p): bool => str_contains($errors, $p) === true));
ok(
    'L5 what this whole run wrote to the error log: the one stopped run (D4) is there as a kind of fault and a number, and no line holds a phone number, a name, a text, or the fault\'s own words (which, in D4, quoted the number)',
    str_contains($errors, 'Held texts: the run was stopped: RuntimeException (0)') === true && $found === [],
    'found: ' . json_encode($found) . '; the log: ' . mb_substr($errors, 0, 600)
);

// The Notification preferences page: the REAL lines of the page that say what
// becomes of a held text, run with no record of the scheduled job and then
// with the job recorded as having just run.
$prefsPage = (string) file_get_contents($webDir . $sep . '_apps' . $sep . 'auth' . $sep . 'account' . $sep . 'notifications.php');
$from      = strpos($prefsPage, '<p class="small text-muted">', (int) strpos($prefsPage, 'Sabbath quiet hours per-user override'));
$lines     = $from === false ? '' : substr($prefsPage, $from, (int) strpos($prefsPage, '</p>', $from) + 4 - $from);
$readPage  = static function () use ($lines, $mysqli): string {
    MailQueue::forget(); // the answer is read once a request; this is a new one
    ob_start();
    eval('use Portal\Core\MailQueue; ?>' . $lines);

    return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ob_get_clean())));
};
at($wednesday);
q("DELETE FROM tblMailRouteState WHERE routeKey = 'installation'");
$saysNoJob = $readPage();
q("INSERT INTO tblMailRouteState (routeKey, jobLastRanAt) VALUES ('installation', UTC_TIMESTAMP())");
$saysJob = $readPage();
q("DELETE FROM tblMailRouteState WHERE routeKey = 'installation'");
MailQueue::forget();
$never = 'Three things are never held and are sent at once, whatever the hour: a critical alert, an emergency notice, and the code that confirms your number.';
ok(
    'L6 the Notification preferences page says what it KNOWS on this installation and promises no more. The scheduled job has run in the last 15 minutes: a held text "is kept, and sent once your quiet hours have ended". It has not: the text is kept, and "is sent only if that job runs before the text is two days old" (it no longer says "it is not sent afterwards either", which a job that runs once an hour made untrue). Never the other state\'s sentence; and in both, that a critical alert, an emergency notice and the code are never held',
    str_contains($lines, 'MailQueue::jobIsRunning($mysqli)') === true
        && str_contains($saysNoJob, 'It is kept. Whether it is sent afterwards depends on the portal\'s scheduled job, which has not run in the last 15 minutes: the text is sent only if that job runs before the text is two days old. An administrator can check that the job is set up.') === true
        && str_contains($saysNoJob, 'sent once your quiet hours have ended') === false && str_contains($saysNoJob . $saysJob, 'not sent afterwards either') === false
        && str_contains($saysJob, 'It is kept, and sent once your quiet hours have ended, unless by then it is too old to be worth sending (nothing waits more than two days).') === true
        && str_contains($saysJob, 'has not run in the last 15 minutes') === false
        && str_contains($saysNoJob, $never) === true && str_contains($saysJob, $never) === true && str_contains($saysJob, 'a text message that is not urgent is not sent to you') === true,
    "no run in the last 15 minutes: " . $saysNoJob . "\n        the job ran just now: " . $saysJob
);

// The broadcast page's sentence. `skipped` has always included the held.
$sendPage = (string) file_get_contents($webDir . $sep . '_apps' . $sep . 'admin' . $sep . 'sms' . $sep . 'send.php');
$told     = [
    Sms::broadcastSummary(['sent' => 3, 'skipped' => 3, 'held' => 2], true),
    Sms::broadcastSummary(['sent' => 3, 'skipped' => 3, 'held' => 2], false),
    Sms::broadcastSummary(['sent' => 4, 'skipped' => 1, 'held' => 0], true),
    Sms::broadcastSummary(['sent' => 0, 'skipped' => 1, 'held' => 1], true),
    Sms::broadcastSummary($counts, true), // what the real broadcast of L3 answered: 1 sent, 2 held
];
ok(
    'L7 the broadcast page no longer calls a held text "skipped": it says how many were sent, how many are held, and how many were skipped (only the rest). With the scheduled job having run in the last 15 minutes it says the portal sends the held ones itself ("do not send the broadcast again"). Without, it says so, and that a held text is sent only if the job runs before the text is two days old, and to look at Recent messages before sending again: it does NOT say they will not be sent. The page prints exactly this sentence',
    $told === [
        'Broadcast complete: 3 sent, 2 held for quiet hours (the portal sends them itself when the person\'s quiet hours end, so do not send the broadcast again), 1 skipped.',
        'Broadcast complete: 3 sent, 2 held for quiet hours (the portal\'s scheduled job, which sends them, has not run in the last 15 minutes: a held text is sent only if that job runs before the text is two days old, so look at Recent messages before sending the broadcast again), 1 skipped.',
        'Broadcast complete: 4 sent, 1 skipped.',
        'Broadcast complete: 0 sent, 1 held for quiet hours (the portal sends it itself when the person\'s quiet hours end, so do not send the broadcast again), 0 skipped.',
        'Broadcast complete: 1 sent, 2 held for quiet hours (the portal sends them itself when the person\'s quiet hours end, so do not send the broadcast again), 0 skipped.',
    ] && Sms::broadcastSummary(['sent' => 0, 'skipped' => 1, 'held' => 1], false) === 'Broadcast complete: 0 sent, 1 held for quiet hours (the portal\'s scheduled job, which sends it, has not run in the last 15 minutes: a held text is sent only if that job runs before the text is two days old, so look at Recent messages before sending the broadcast again), 0 skipped.'
        && str_contains(implode(' ', $told), 'NOT going to be sent') === false
        && str_contains($sendPage, 'Sms::broadcastSummary($result, MailQueue::jobIsRunning(App::db()))') === true && str_contains($sendPage, 'sent, %d skipped') === false,
    json_encode($told)
);

// The list of recent texts on Admin, SMS. The page's own question is taken
// out of the page file and asked of the database as it stands after L4: one
// text sent at once on a Friday evening, one held then and sent on the
// Saturday.
$listPage = (string) file_get_contents($webDir . $sep . '_apps' . $sep . 'admin' . $sep . 'sms' . $sep . 'index.php');
$listSql  = preg_match('/\$stmt = \$db->prepare\(\s*(\'SELECT m\.messageID.*?LIMIT 50\')\s*\);/s', $listPage, $foundSql) === 1 ? (string) eval('return ' . $foundSql[1] . ';') : '';
$listed   = [];
if ($listSql !== '') {
    foreach (q($listSql, 'i', [ORG]) as $m) {
        // The page's own line, word for word (the check below holds the page to it).
        $shownAt             = (string) ($m['sentAt'] ?? $m['createdAt']);
        $listed[$m['body']] = [$m['status'], $shownAt];
    }
}
ok(
    'L8 the list of recent texts asks for the moment each text was handed over and shows that one: the text held on the Friday evening and sent on the Saturday is listed as sent at the Saturday moment, not at the moment it was written; a text sent at once shows the moment it was written, which is the same thing',
    str_contains($listPage, "\$shownAt = (string) (\$m['sentAt'] ?? \$m['createdAt']);") === true && str_contains($listPage, "date('d/m H:i', (int) strtotime(\$shownAt))") === true
        && str_contains($listPage, "strtotime((string) \$m['createdAt'])") === false
        && ($listed[$L4b] ?? null) === ['sent', onClock($m1 + 60, QUIET_ZONE)] && ($listed[$L4a] ?? null) === ['sent', onClock($m0 + 7200, QUIET_ZONE)] && onClock($m1 + 60, QUIET_ZONE) !== onClock($m0 + 7200, QUIET_ZONE),
    'the page\'s question: ' . json_encode($listSql) . '; what it lists: ' . json_encode($listed)
);

// Every sentence the class records, against its copy written out at the top
// of this file (SAYS).
$inClass = [];
foreach (SAYS as $name => $words) {
    $inClass[$name] = defined(Sms::class . '::' . $name) === true ? constant(Sms::class . '::' . $name) : null;
}
ok(
    'L9 each of the 17 sentences the class records is, word for word, the one written out in this file: none reworded, none swapped with another (13 for a held text, and since #610\'s follow-up 4 for a text refused at once)',
    $inClass === SAYS && count(SAYS) === 17 && count(array_unique(SAYS)) === 17,
    json_encode(array_diff_assoc(array_map('strval', $inClass), SAYS))
);

// Two more sentences people are shown, read from the real page files: the
// paragraph under the kinds on Account, SMS, and the first paragraph of the
// broadcast page. What a visitor reads is taken out (the PHP inside a link
// is cut, then the tags), so a sentence that only survives in a comment
// does not count.
$shown = static function (string $file, string $opening): string {
    $from = strpos($file, $opening);
    if ($from === false) {
        return '';
    }
    $html = substr($file, $from, (int) strpos($file, '</p>', $from) + 4 - $from);

    return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/<\?php.*?\?>/s', '', $html))));
};
$accountPage  = (string) file_get_contents($webDir . $sep . '_apps' . $sep . 'account' . $sep . 'sms.php');
$accountSays  = $shown($accountPage, '<p class="small text-muted mt-2">');
$broadcastSay = $shown($sendPage, '<p class="text-secondary">');
ok(
    'L10 two more pages say that an emergency notice is never held, and send people to the one page that says what becomes of a held text: Account, SMS ("Critical alerts and emergency comms are sent at once ...", with a link the portal builds to Notification preferences) and the first paragraph of the broadcast page; neither says "defer" or "ignore quiet hours" any more',
    $accountSays === 'Critical alerts and emergency comms are sent at once, whatever the hour. The other kinds are held back while you are in your Sabbath quiet hours; the Notification preferences page says what becomes of them afterwards.'
        && str_contains($accountPage, "<a href=\"<?php echo htmlspecialchars(Site::url('account/notifications'), ENT_QUOTES, 'UTF-8'); ?>\">Notification preferences</a>") === true
        && $broadcastSay === 'Sends to every verified, opted-in subscriber for the selected category. Critical alerts and emergency comms are sent at once, whatever the hour. Any other kind is held back for a person who is in their Sabbath quiet hours; the line shown after sending says how many were held and what becomes of them.',
    'Account, SMS: ' . json_encode($accountSays) . '; the broadcast page: ' . json_encode($broadcastSay)
);

// =============================================================================
head('M. A text sent AT ONCE, for somebody who may not be sent one');
// =============================================================================
// Until the owner's decision of 26 October 2026 this was never asked. Only a
// HELD text was asked again when it came due (part H); a text sent at once
// went to whatever number sat in the preferences table, however long ago its
// person had been switched off or removed from the organisation. These checks
// run on a WEDNESDAY, well outside anybody's quiet hours, so nothing here can
// be held and the only thing being tested is the new question.
fresh();
at($wednesday);
$M1 = 'ZQXT M1 at once, for an account that is switched off';
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [WHO['offacct']]);
[$wentM1, $outM1] = text('offacct', 'rota_changes', $M1);
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [WHO['offacct']]);
ok(
    'M1 a text sent at once to a person whose ACCOUNT has been switched off is not handed to the provider, is recorded as not sent in its own present-tense sentence, and is NOT held (there is nothing to wait for)',
    $wentM1 === false && $outM1 === 'not-sent' && handed($M1) === 0
        && story(rowOf($M1)) === ['failed', SAYS['AT_ONCE_ACCOUNT_OFF'], null, null],
    json_encode([$wentM1, $outM1, handed($M1), story(rowOf($M1))])
);

$M2 = 'ZQXT M2 at once, for somebody with no account at all';
q('DELETE FROM tblUserSmsPreference WHERE userID = ?', 'i', [WHO['gone']]);
q('INSERT INTO tblUserSmsPreference (siteID, userID, phoneNumber, isVerified, categories) VALUES (?, ?, ?, 1, ?)', 'iiss', [ORG, WHO['gone'], numberOf(WHO['gone']), EVERY_KIND]);
q('DELETE FROM tblUsers WHERE userID = ?', 'i', [WHO['gone']]);
[$wentM2, $outM2] = text('gone', 'rota_changes', $M2);
ok(
    'M2 a text sent at once for a person who no longer has an account is not sent, says so, and its record NAMES NO PERSON (the record points at the table of people, so naming one who has gone is refused by the database; the number, the words, the kind and the reason are all still kept)',
    $wentM2 === false && $outM2 === 'not-sent' && handed($M2) === 0
        && story(rowOf($M2)) === ['failed', SAYS['AT_ONCE_PERSON'], null, null]
        && rowOf($M2)['recipientUserID'] === null
        && (string) rowOf($M2)['recipientNumber'] === numberOf(WHO['gone'])
        && (string) rowOf($M2)['category'] === 'rota_changes',
    json_encode([$wentM2, $outM2, handed($M2), story(rowOf($M2)), rowOf($M2)['recipientUserID'] ?? 'null'])
);

// The kinds that are NEVER held are asked this question too: a switched-off
// account should not be sent a critical alert either.
fresh();
at($wednesday);
$M3 = 'ZQXT M3 a critical alert for an account that is switched off';
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [WHO['offacct']]);
[$wentM3, $outM3] = text('offacct', 'critical_alerts', $M3);
$M3b = 'ZQXT M3b an emergency notice for the same switched-off account';
[$wentM3b] = text('offacct', 'emergency_comms', $M3b);
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [WHO['offacct']]);
ok(
    'M3 the kinds that are NEVER held are asked the same question: a critical alert and an emergency notice for a switched-off account are both refused, not sent at once',
    $wentM3 === false && $outM3 === 'not-sent' && handed($M3) === 0 && $wentM3b === false && handed($M3b) === 0
        && story(rowOf($M3)) === ['failed', SAYS['AT_ONCE_ACCOUNT_OFF'], null, null]
        && story(rowOf($M3b)) === ['failed', SAYS['AT_ONCE_ACCOUNT_OFF'], null, null],
    json_encode([$wentM3, $outM3, $wentM3b, story(rowOf($M3)), story(rowOf($M3b))])
);

// A caller that names NO person has chosen the number itself: there is no
// account to judge, and such a text goes exactly as it did before.
fresh();
at($wednesday);
$M4 = 'ZQXT M4 at once, with no person named';
(new ReflectionProperty(Sms::class, 'settledInRequest'))->setValue(null, false);
newProcess();
$wentM4 = RecordingSms::send(ORG, '+447700900777', $M4, 'rota_changes', null);
ok(
    'M4 a text with NO person named is still sent: the caller chose the number, and the portal has no account to ask about',
    $wentM4 === true && RecordingSms::lastOutcome() === 'sent' && handed($M4) === 1,
    json_encode([$wentM4, RecordingSms::lastOutcome(), handed($M4), story(rowOf($M4))])
);

// A person in good standing is unaffected: the question must refuse nobody it
// should not. This is the check that would fail if the new gate were too tight.
$M5 = 'ZQXT M5 at once, for a person in good standing';
[$wentM5, $outM5] = text('follow', 'rota_changes', $M5);
ok(
    'M5 a person whose account is on is sent their text exactly as before: the new question refuses nobody it should not',
    $wentM5 === true && $outM5 === 'sent' && handed($M5) === 1 && story(rowOf($M5))[0] === 'sent',
    json_encode([$wentM5, $outM5, handed($M5), story(rowOf($M5))])
);

// SEVERAL organisations: the membership questions are asked at once too.
fresh(['multi' => 'true']);
foreach (['member', 'leaver'] as $what) {
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [WHO[$what], ORG]);
}
at($wednesday);
q('UPDATE tblUserSites SET isActive = 0 WHERE userID = ? AND siteID = ?', 'ii', [WHO['leaver'], ORG]);
$M6 = ['member' => 'ZQXT M6 at once, for a member', 'leaver' => 'ZQXT M6 at once, for somebody who has left',
    'follow' => 'ZQXT M6 at once, for somebody who was never a member', 'global' => 'ZQXT M6 at once, for a global administrator'];
$sentM6 = [];
foreach ($M6 as $what => $body) {
    [$sentM6[$what]] = text($what, 'rota_changes', $body);
}
ok(
    'M6 with SEVERAL organisations the same two membership questions are asked of a text sent at once: a member and a global administrator are sent theirs; somebody who has left, and somebody who was never a member, are refused and recorded as not a member of this organisation',
    $sentM6['member'] === true && $sentM6['global'] === true && $sentM6['leaver'] === false && $sentM6['follow'] === false
        && handed($M6['member']) === 1 && handed($M6['global']) === 1 && handed($M6['leaver']) === 0 && handed($M6['follow']) === 0
        && story(rowOf($M6['leaver'])) === ['failed', SAYS['AT_ONCE_NOT_MEMBER'], null, null]
        && story(rowOf($M6['follow'])) === ['failed', SAYS['AT_ONCE_NOT_MEMBER'], null, null],
    json_encode([$sentM6, array_map(static fn (string $body): array => story(rowOf($body)), $M6)])
);

$M7 = 'ZQXT M7 at once, for a member of an organisation that is switched off';
q('UPDATE tblSites SET isActive = 0 WHERE siteID = ?', 'i', [ORG]);
[$wentM7, $outM7] = text('member', 'rota_changes', $M7);
q('UPDATE tblSites SET isActive = 1 WHERE siteID = ?', 'i', [ORG]);
ok(
    'M7 with several organisations, a text sent at once for a member of an organisation that has been switched off is refused, and says which of the two reasons it was',
    $wentM7 === false && $outM7 === 'not-sent' && handed($M7) === 0
        && story(rowOf($M7)) === ['failed', SAYS['AT_ONCE_ORG_OFF'], null, null],
    json_encode([$wentM7, $outM7, story(rowOf($M7))])
);

// THE ORDER: A PERSON IS JUDGED AT THE MOMENT THEIR TEXT WOULD GO.
// This was built the other way round first -- the question asked before the
// hold -- on the stated grounds that holding a text for somebody who may not
// be sent one "would only put off recording the same answer by up to two
// days". That reason was WRONG: the held path asks when the text comes DUE,
// not when it was written, so the two moments can give different answers. The
// independent check measured what the first order cost, and M9 below is that
// case. So: a text going NOW is judged now; a HELD text is judged when it
// comes due, by the same one question in different words.
fresh();
at($FRI20);
$M8 = 'ZQXT M8 inside quiet hours, for an account that is switched off';
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [WHO['offacct']]);
[$wentM8, $outM8] = text('offacct', 'rota_changes', $M8);
$heldM8 = (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'queued' AND body = ?", 's', [$M8])['n'];
at($W1 + 60);
$dueM8 = run();
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [WHO['offacct']]);
ok(
    'M8 inside quiet hours the text is HELD even for a switched-off account, because the question belongs at the moment it would go; when it comes due the account is still off, so it is recorded as not sent in the "when they ended" words, never handed over',
    $wentM8 === false && $outM8 === 'held' && $heldM8 === 1 && handed($M8) === 0
        && story(rowOf($M8)) === ['failed', SAYS['NOT_SENT_ACCOUNT_OFF'], null, null]
        && $dueM8['notSent'] === 1,
    json_encode([$wentM8, $outM8, $heldM8, handed($M8), story(rowOf($M8)), $dueM8])
);

// THE CASE THE FIRST ORDER LOST. A suspension lifted inside two days is an
// ordinary thing. Asking before the hold refused this text outright; asking
// when it comes due delivers it, which is what the portal did before #610's
// follow-up and must go on doing.
fresh();
at($FRI20);
$M9 = 'ZQXT M9 held on the Friday, the account switched back on by the Saturday';
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [WHO['offacct']]);
[$wentM9, $outM9] = text('offacct', 'rota_changes', $M9);
$heldM9 = (int) one("SELECT COUNT(*) AS n FROM tblSmsMessage WHERE status = 'queued' AND body = ?", 's', [$M9])['n'];
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [WHO['offacct']]);
at($W1 + 60);
$dueM9 = run();
ok(
    'M9 a text held while the account was switched off IS SENT when the quiet hours end, if the account has been switched back on by then: the question is asked again at the moment it would go, and the answer has changed',
    $wentM9 === false && $outM9 === 'held' && $heldM9 === 1
        && handed($M9) === 1 && $dueM9['sent'] === 1 && $dueM9['notSent'] === 0
        && story(rowOf($M9))[0] === 'sent' && story(rowOf($M9))[1] === SAYS['SENT_AFTER_HOLD'],
    json_encode([$wentM9, $outM9, $heldM9, handed($M9), $dueM9, story(rowOf($M9))])
);

// THE DAY'S LIMIT IS REACHED AND THE ACCOUNT HAS GONE. That refusal is
// written before anything asks about the person, so it is the one place that
// used to name somebody the database would not accept -- which ended the whole
// request, and with it a broadcast part way through. It also used to stamp a
// "sent at" on a text nobody had sent. Both found by the independent check, in
// a line this package did not write.
fresh(['cap' => 0]);
at($wednesday);
$M10 = 'ZQXT M10 the day\'s limit is reached and the account has gone';
// NO PREFERENCE ROW IS LEFT BEHIND, and that is worth writing down, because
// the first version of this check tried to make one. tblUserSmsPreference's
// userID is NOT NULL and its key cascades, so deleting the account takes the
// preference row with it: there is no such thing as a stored number with no
// person. What CAN still happen is a caller holding an account number it read
// a moment ago -- a broadcast part way through its list when the account is
// deleted -- which is exactly how send() is reached with a person who has
// gone. So this gives send() the number and the old account number directly,
// as the real caller would.
if ((int) one('SELECT COUNT(*) AS n FROM tblUsers WHERE userID = ?', 'i', [WHO['gone']])['n'] === 0) {
    $addPerson(WHO['gone']);
}
$keptNumber = numberOf(WHO['gone']);
q('DELETE FROM tblUsers WHERE userID = ?', 'i', [WHO['gone']]);
$crashedM10 = false;
try {
    // Named, so the record would carry the person -- which is the point: the
    // database will not accept a name that has gone, and record() has to keep
    // the row and drop the name instead of ending the request.
    (new ReflectionProperty(Sms::class, 'settledInRequest'))->setValue(null, false);
    newProcess();
    $wentM10 = RecordingSms::send(ORG, $keptNumber, $M10, 'rota_changes', WHO['gone']);
    $outM10  = RecordingSms::lastOutcome();
} catch (\Throwable $stopped) {
    $crashedM10 = get_class($stopped) . ': ' . $stopped->getMessage();
    $wentM10 = null;
    $outM10  = null;
}
ok(
    'M10 with the day\'s limit reached and the account deleted, the text is recorded as refused by the limit WITHOUT ending the request: the record names no person (the database would refuse one who has gone) and carries no "sent at", because nothing was ever handed over',
    $crashedM10 === false && $wentM10 === false && $outM10 === 'failed' && handed($M10) === 0
        && story(rowOf($M10)) === ['failed', 'daily-cap', null, null]
        && rowOf($M10)['recipientUserID'] === null
        && (string) rowOf($M10)['recipientNumber'] === $keptNumber,
    json_encode([$crashedM10, $wentM10, $outM10, story(rowOf($M10)), rowOf($M10)['recipientUserID'] ?? 'null'])
);

// =============================================================================
head('Everything is put back');
// =============================================================================
$second->close();
cleanup();
$left = (int) (one(
    'SELECT (SELECT COUNT(*) FROM tblSites WHERE siteID IN (?, ?)) + (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 934100 AND 934999) + (SELECT COUNT(*) FROM tblSmsMessage) '
    . '+ (SELECT COUNT(*) FROM tblUserSites WHERE userID BETWEEN 934100 AND 934999) '
    . "+ (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . PROBE_TABLE . "') "
    . "+ (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '" . PROBE_HOOK . "') "
    . "+ (SELECT COUNT(*) FROM tblUserSmsPreference WHERE userID BETWEEN 934100 AND 934999) + (SELECT COUNT(*) FROM tblActivityLogs WHERE activityType = 'HeldTextsRun') AS n",
    'ii', [ORG, ORG2]
)['n'] ?? -1);
$jobNow = one("SELECT jobLastRanAt FROM tblMailRouteState WHERE routeKey = 'installation'");
ok(
    'clean-up: the made-up world is gone (people, memberships, the trigger and its table), the table of text messages is empty and exactly as the install script makes it, the migration is recorded once, and the record of the mail job is as it was',
    $left === 0 && $tableText() === $state['tableText'] && (int) one("SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = '219_held_texts.sql'")['n'] === 1
        && ($state['jobRow'] === true ? ($jobNow['jobLastRanAt'] ?? null) === $state['jobRan'] : $jobNow === null) && is_dir($scratch) === false,
    'rows left: ' . $left . '; the mail job\'s record now ' . json_encode($jobNow)
);

echo "\n{$pass} passed, {$fail} failed; " . ($pass + $fail) . ' ran of ' . EXPECTED_CHECKS . " expected.\n";
if (($pass + $fail) !== EXPECTED_CHECKS) {
    echo "FAIL — the number of checks that ran is not the number this file expects. A block was skipped or ran twice,\n";
    echo "or a check was added without updating the number at the top.\n";
    exit(1);
}
if ($fail > 0) {
    echo "FAIL — {$fail} check(s) failed. Read the FAIL lines above.\n";
    exit(1);
}
echo "PASS — all {$pass} checks passed, and the run reached its end.\n";
exit(0);
