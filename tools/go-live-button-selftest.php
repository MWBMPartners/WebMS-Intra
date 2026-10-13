<?php
// Path: tools/go-live-button-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The Host Console's "Send We're live" button, on the REAL pages 📣🎙️ (#612)
 * -----------------------------------------------------------------------------
 * WHAT WENT WRONG
 * The button's form (`web/_apps/admin/host-console/event.php`) posted to
 * `admin/livestream`. That is the livestream DASHBOARD page, which reads nothing
 * posted to it, so pressing the button did nothing, with or without a form token.
 * The code that acts on it is `web/_apps/admin/livestream/index.php`, which
 * answers at `admin/livestream/channels`. Mending the address switches on
 * something that has never worked on any installation: a "we are live" push
 * notification to members. That is why this test exists, and why it is careful.
 *
 * NO REAL PUSH IS EVER SENT
 * The handler calls `WebPush::sendToChannel()` directly (a static call), so there
 * is no place to hand it a stand-in sender, which is how `tools/import-notifier-
 * selftest.php` does it (that class takes the sender as an argument). This test
 * stands the stand-in in at the other end instead: every push subscription it
 * makes that could be sent anything has an address starting `http://` (the one
 * `https://` device of part K is made on a host that never resolves, is deleted
 * before anything could address it, and cannot be sent to anyway: see part K).
 * `WebPush::send()` refuses any address
 * that is not https, BEFORE it encrypts anything or opens a connection, and says
 * so by recording the outcome -1 on that subscription (`lastHttpStatus = -1`,
 * `failCount` one higher). That makes the subscription row a recorder:
 *
 *   - a device that was ADDRESSED has failCount one higher and lastHttpStatus -1;
 *   - a device that was not addressed is untouched.
 *
 * The handler's own words, "Push sent - 0 delivered, N failed, 0 pruned", count
 * the devices addressed. There are three further locks, and the test checks the
 * first and last itself: (1) it refuses to start unless every subscription it
 * makes is an `http://` address; (2) the sender's own curl call allows https
 * only; (3) the host names end in `.invalid`, which never resolve. Afterwards it
 * checks that no subscription row carries any outcome except -1: a real answer
 * from a push service would show as another number, and fails the run.
 *
 * WHAT THE SENDER WAS HANDED (added after the independent check of 13 October 2026).
 * The device rows say WHO was addressed but not WHAT was said, and a test that cannot
 * read the words cannot tell that a push names the installation instead of the
 * organisation. So one line is put at the top of `WebPush::send()` in the SCRATCH
 * COPY only (the repository's file is never touched): it writes the device, the
 * words, how long the push is kept, its urgency and its collapse key to a file,
 * and, only for the second server of part I, pauses a quarter of a second so that
 * presses which overlap really overlap. It opens no connection; the sender then
 * refuses the `http://` address exactly as before. If the line cannot be put in
 * exactly once, the test does not run.
 *
 * The settings that make Web Push look "configured" (`push.enabled` and the three
 * key and contact settings) are made-up text for the run and put back as found.
 * No key is ever used: the refusal comes first.
 *
 * WHAT IT DOES
 * Copies `web/` into a NEW scratch folder, starts PHP's built-in web server on it
 * behind a router that imitates `.htaccess`, signs made-up accounts in through the
 * real sign-in page and, on the real pages, checks:
 *
 *   A. The form. An administrator opens the Host Console page of an event; the
 *      test READS the form from the page (its address and every hidden field) and
 *      posts exactly that. The address must be `admin/livestream/channels`; the
 *      person lands back on that event's Host Console page and is told how many
 *      devices were addressed. ONE push: the devices of signed-in members that
 *      subscribed to the livestream channel in THIS organisation are addressed,
 *      once each (a device with no account is not). A member who switched "We're live now" off (through the real
 *      notification preferences page), a device that only asked for reminders, a
 *      device that is switched off, and the other organisation's devices are not.
 *      The push says "<the organisation's own name> is live now" (not the
 *      installation's name), names the channel, links to the live page for the
 *      event, is kept 900 seconds, is urgent, and has the organisation's collapse
 *      key. A person whose ACCOUNT is switched off, and one whose MEMBERSHIP of the
 *      organisation is switched off, are not addressed either.
 *   B. A second press. It sends again; a fourth press within fifteen minutes is
 *      refused by the handler's own limit and sends nothing.
 *   C. Who may not. A member is refused (403) and nothing is sent; a signed-out
 *      visitor is sent to sign in. (An event's coordinator may send it for their
 *      event: part J.)
 *   D. The form token. No token, another person's token and a made-up one are
 *      refused (400) and nothing is sent; a GET sends nothing, even with every
 *      field in the address. The same form sent twice (a double-click that got
 *      past the button): the second is refused with a plain sentence and a link
 *      back, and sends nothing.
 *   E. The come-back address. The Host Console's own address is accepted; nine
 *      other shapes (a whole address elsewhere, a "//" address, a backslash form,
 *      extra text after the number, a word for the number, a different page, a
 *      path with "..", a javascript address and an empty one) are not, and the
 *      person is never sent anywhere off this portal. One line break after the
 *      right address is read as the right address (harmless; pinned).
 *   F. Switching it off lasts. A member who switched "We're live now" off, then
 *      pressed Save on the Account page's own "Notification Preferences" card
 *      (three switches of its own), is still switched off; every other stored
 *      choice is kept.
 *   G. The button switches itself off when its form is sent (the page's own small
 *      script; run in Node.js with stand-ins when it is installed).
 *   H. A second organisation (told apart by sign-in session). Its administrator
 *      cannot open the first organisation's Host Console (404); pressing the
 *      button for THEIR organisation addresses only THEIR devices, in THEIR
 *      organisation's name, never the first organisation's; and the first
 *      organisation's administrator likewise addresses only the first's.
 *   I. Six presses at the same moment (a second server with eight workers, every
 *      send made to take a quarter of a second): at most three of them send.
 *   J. An event's coordinator may send it, for that event only (owner's decision of
 *      13 October 2026). A coordinator of the event who is not an administrator is
 *      shown the button and sends; a coordinator of ANOTHER event, a coordinator of
 *      an event of another organisation, an ordinary member, a made-up, negative,
 *      non-numeric or missing event number are all refused (403) and nothing is
 *      sent or counted; an administrator naming a made-up or another
 *      organisation's event is told so and nothing is sent; an administrator with
 *      no event number still sends (the channels page's own button posts none).
 *      The console draws the button exactly when the handler accepts. A coordinator
 *      cannot open the livestream channels page or use any of its other actions.
 *      One line is written to the activity log: who pressed it and for which event,
 *      and nothing of the push.
 *   K. Only a signed-in device can ask for notices (owner's decision of 13 October
 *      2026). The subscribe address refuses a request from nobody, and from a
 *      visitor with a real session and form token, in plain words (401) and stores
 *      nothing; a signed-in member subscribing works as before; a device with no
 *      account is left out of the push and its row is still there; the unsubscribe
 *      address still works for a person who is not signed in (keyed by the
 *      device's own address). This part lives here, not in tools/webpush-selftest.php,
 *      because that file is standalone (no database, no web server, no sign-in) and
 *      cannot call a page.
 *   L. The run left no row of its own behind, and every setting it switched is
 *      back as found.
 *
 * WHAT THIS CANNOT PROVE
 *   - That a real push service accepts the message, that a phone shows it, or what
 *     it looks like: nothing is ever sent. What is proved is WHO is addressed.
 *   - What the message looks like on a phone. The test reads the words the sender
 *     was handed, not what a push service or a phone does with them.
 *   - Apache: the router only imitates `.htaccess`. MariaDB. A real browser.
 *
 * SAFETY
 *   - It refuses unless the database's name starts with `selftest_`. It removes
 *     its own rows (accounts 920001 to 920012, organisation 920901, events 920401,
 *     920402 and 920403, channels 920601 and 920602, subscriptions 920501 to 920520) and
 *     every activity-log, error and rate-limit row made after the start.
 *   - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *     temporary folder), outside the repository, deleted at the end only if it
 *     still holds this run's marker. `web/_auth_keys` is never read or copied.
 *   - The server is started with its command as a list (no shell), stopped by its
 *     process number, refuses to start if anything answers on its port, and every
 *     answer must carry this run's header.
 *   - Do NOT run it at the same moment as another page-driving self-test on the
 *     same database.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_USER=… \
 *         SELFTEST_DB_PASS=… SELFTEST_WEB_PORT=9751 php tools/go-live-button-selftest.php
 *         (it also uses the port after that one, for the server with workers in part I)
 *         The database must already hold `web/_sql/full_schema.sql`. Needs PHP's
 *         curl extension (with curl_multi), `proc_open()` and `posix_kill()`; Node.js is
 *         used for two small parts when it is installed (and the run says so when not).
 * Exit:   0 only if every check passed AND the run reached its end (it prints
 *         "REACHED THE END" last); 1 if a check failed; 2 if it could not run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/612
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const GLB_ADMIN   = 920001;   // administrator of organisation 1
const GLB_ON      = 920002;   // member of 1, subscribed, "We're live now" left on
const GLB_OFF     = 920003;   // member of 1, subscribed, "We're live now" switched OFF on the real page
const GLB_REM     = 920004;   // member of 1, subscribed to REMINDERS only
const GLB_PLAIN   = 920005;   // member of 1, nothing else (tries to press)
const GLB_COORD   = 920006;   // member of 1 and coordinator of its event, not an administrator
const GLB_ADMIN_B = 920007;   // administrator of the SECOND organisation only
const GLB_MEMBER_B = 920008;  // member of the second organisation, subscribed
const GLB_GONE    = 920009;   // member of 1 whose ACCOUNT has been switched off, subscribed
const GLB_LEFT    = 920010;   // member of 1 whose MEMBERSHIP of 1 has been switched off, subscribed
const GLB_COORD2  = 920011;   // member of 1 and coordinator of the SECOND event of 1 only (not of the first)
const GLB_COORDX  = 920012;   // member of 1 who is a coordinator of an event of the OTHER organisation
const GLB_COORDW  = 920013;   // member of 1 who WAS a coordinator of the first event until their place was withdrawn
const GLB_COORDD  = 920014;   // member of 1 who is a coordinator of a DELETED event and of an IMPORTED one
const GLB_LAST_USER = 920014;
const GLB_EVENT   = 920401;   // organisation 1's event
const GLB_EVENT_B = 920402;   // the second organisation's event
const GLB_EVENT_2 = 920403;   // organisation 1's SECOND event (a coordinator of the first is not a coordinator of this)
const GLB_EVENT_DEL = 920404; // organisation 1's event that has been DELETED
const GLB_EVENT_IMP = 920405; // organisation 1's event that was copied in from an outside calendar (read-only)
const GLB_FIRST_EVENT = 920401, GLB_LAST_EVENT = 920409;
/** How many checks a full run makes (with Node.js; two fewer without it). Bring it up to date whenever a check is added or taken out. */
const GLB_EXPECTED_CHECKS = 127;
const GLB_ORG_B   = 920901;
const GLB_CHANNEL = 920601, GLB_CHANNEL_B = 920602;
/** Subscriptions: ON, OFF, anonymous, reminders only, switched off, then the second organisation's two, then the two people who have left. */
const GLB_S_ON = 920501, GLB_S_OFF = 920502, GLB_S_ANON = 920503, GLB_S_REM = 920504, GLB_S_DEAD = 920505, GLB_S_B = 920506, GLB_S_BANON = 920507, GLB_S_GONE = 920508, GLB_S_LEFT = 920509;
/** A second device of a member of 1, and of a member of the second organisation: with the signed-out devices now left out, these are what is addressed. */
const GLB_S_ON2 = 920510, GLB_S_B2 = 920511;
/** The device a signed-in person subscribes in part K; its number is not known in advance, so it is found by this address. */
const GLB_SUBSCRIBE_ENDPOINT = 'https://push.selftest.invalid/subscribed-by-the-test';
/** What the organisations are called for the run, and the installation's own name, which must NOT be what a push says. */
const GLB_NAME_A = 'GLB first organisation', GLB_NAME_B = 'GLB second organisation', GLB_NAME_INSTALLATION = 'GLB installation name';
const GLB_FIRST_SUB = 920501, GLB_LAST_SUB = 920520;
/** The settings this run switches, with what it switches them to. They are put back as found. */
const GLB_SETTINGS = [
    'push.enabled' => 'true', 'push.vapidPublicKey' => 'selftest-public-key-not-real', 'push.vapidPrivateKey' => 'selftest-private-key-not-real',
    'push.contact' => 'mailto:selftest@selftest.invalid', 'multisite.enabled' => 'false', 'multisite.detectionMode' => 'session',
    'site.name' => GLB_NAME_INSTALLATION,
    // So that the subscribe address (part K) accepts the made-up https address of the test's own device; see part K for why that
    // device can still never be sent anything.
    'push.endpointHostAllowlist' => 'selftest.invalid',
];
/** The flash the handler leaves, as the page shows it, for N devices addressed and all refused before any network call. */
function glb_sentWords(int $addressed): string
{
    return 'Push sent — 0 delivered, ' . $addressed . ' failed, 0 pruned.';
}

/**
 * What this run needs to put back. `putBack` maps a setting to [value, isSensitive] as found; the three marks are
 * the highest activity-log, error-table and rate-limit numbers at the start (everything above is this run's).
 */
$glb = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'port' => 0, 'errMark' => null, 'logMark' => null, 'hitMark' => null,
    'cleaned' => false, 'pass' => 0, 'fail' => 0, 'putBack' => [], 'proc2' => null, 'siteNameA' => null, 'siteStampA' => null, 'nodeSkipped' => false];

function glb_refuse(string $why): never
{
    echo 'REFUSED. ' . $why . "\nNothing was run, and nothing is proved.\n";
    exit(2);
}

/** Report one check. `$detail` is shown only on failure. */
function glb_check(bool $passed, string $label, string $detail = ''): void
{
    global $glb;
    if ($passed === true) {
        $glb['pass']++;
        echo 'PASS — ' . $label . "\n";
        return;
    }
    $glb['fail']++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

function glb_db(): mysqli
{
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = new mysqli(
            (string) (getenv('SELFTEST_DB_HOST') !== false ? getenv('SELFTEST_DB_HOST') : '127.0.0.1'),
            (string) (getenv('SELFTEST_DB_USER') !== false ? getenv('SELFTEST_DB_USER') : 'root'),
            (string) (getenv('SELFTEST_DB_PASS') !== false ? getenv('SELFTEST_DB_PASS') : ''),
            (string) getenv('SELFTEST_DB_NAME'),
            (int) (getenv('SELFTEST_DB_PORT') !== false ? getenv('SELFTEST_DB_PORT') : 3306)
        );
        if ($db->connect_errno !== 0) {
            glb_refuse('Could not connect to the test database: ' . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
    }
    return $db;
}

/** Remove every row this test makes. Safe to run twice. */
function glb_tidyDatabase(): void
{
    global $glb;
    $db = glb_db();
    $db->query('SET SESSION foreign_key_checks = 0');
    $db->query('DELETE FROM tblPushSubscriptions WHERE subID BETWEEN ' . GLB_FIRST_SUB . ' AND ' . GLB_LAST_SUB . ' OR userID BETWEEN ' . GLB_ADMIN . ' AND ' . GLB_LAST_USER);
    $db->query('DELETE FROM tblEventCoordinators WHERE eventID BETWEEN ' . GLB_FIRST_EVENT . ' AND ' . GLB_LAST_EVENT);
    $db->query('DELETE FROM tblLivestreamSchedule WHERE channelID IN (' . GLB_CHANNEL . ', ' . GLB_CHANNEL_B . ')');
    $db->query('DELETE FROM tblLivestreamChannel WHERE channelID IN (' . GLB_CHANNEL . ', ' . GLB_CHANNEL_B . ')');
    $db->query('DELETE FROM tblEvents WHERE eventID BETWEEN ' . GLB_FIRST_EVENT . ' AND ' . GLB_LAST_EVENT);
    $db->query("DELETE FROM tblPushSubscriptions WHERE endpoint = '" . GLB_SUBSCRIBE_ENDPOINT . "'");
    if ($glb['logMark'] !== null) {
        $db->query('DELETE FROM tblActivityLogs WHERE logID > ' . (int) $glb['logMark']);
    }
    $db->query('DELETE FROM tblActivityLogs WHERE userID BETWEEN ' . GLB_ADMIN . ' AND ' . GLB_LAST_USER);
    foreach (['tblLocalAccounts', 'tblUserSites', 'tblUsers'] as $table) {
        $db->query('DELETE FROM `' . $table . '` WHERE userID BETWEEN ' . GLB_ADMIN . ' AND ' . GLB_LAST_USER);
    }
    $db->query('DELETE FROM tblUserSites WHERE siteID = ' . GLB_ORG_B);
    $db->query('DELETE FROM tblSites WHERE siteID = ' . GLB_ORG_B);
    if ($glb['errMark'] !== null) {
        $db->query('DELETE FROM tblErrors WHERE errorID > ' . (int) $glb['errMark']);
    }
    if ($glb['hitMark'] !== null) {
        $db->query('DELETE FROM tblApiRateLimits WHERE hitID > ' . (int) $glb['hitMark']);
    }
    $db->query('SET SESSION foreign_key_checks = 1');
    if ($glb['siteNameA'] !== null) {
        // The row's "last changed" stamp is set back too (naming it in the statement stops the database stamping it again),
        // so that the organisation's row is byte for byte as it was found.
        $statement = $db->prepare('UPDATE tblSites SET siteName = ?, updatedAt = ? WHERE siteID = 1');
        $statement->bind_param('ss', $glb['siteNameA'], $glb['siteStampA']);
        $statement->execute();
    }
    foreach ($glb['putBack'] as $key => [$value, $sensitive]) {
        $statement = $db->prepare('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingKey = ? AND siteID IS NULL');
        $statement->bind_param('sis', $value, $sensitive, $key);
        $statement->execute();
    }
}

function glb_removeFolder(string $folder): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $path => $info) {
        ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
    }
    @rmdir($folder);
}

/**
 * Stop the second server (the one with several workers, part I) and every worker it started, each by its OWN process
 * number: the workers are found as the children of the server process that proc_open() gave, never by a name.
 */
function glb_stopWorkers(): void
{
    global $glb;
    if (is_resource($glb['proc2']) === false) {
        return;
    }
    $master = (int) proc_get_status($glb['proc2'])['pid'];
    $table  = (string) shell_exec('ps -A -o pid=,ppid= 2>/dev/null');
    foreach (preg_split('/\R/', trim($table)) ?: [] as $line) {
        if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $found) === 1 && (int) $found[2] === $master && (int) $found[1] > 1) {
            posix_kill((int) $found[1], 15);   // 15 = SIGTERM
        }
    }
    proc_terminate($glb['proc2']);
    proc_close($glb['proc2']);
    $glb['proc2'] = null;
}

/** Stop the server, delete the scratch folder, tidy the database. Also runs if the script dies. */
function glb_cleanup(): void
{
    global $glb;
    if ($glb['cleaned'] === true) {
        return;
    }
    $glb['cleaned'] = true;
    glb_stopWorkers();
    if (is_resource($glb['proc']) === true) {
        proc_terminate($glb['proc']);   // by its own process number
        proc_close($glb['proc']);
    }
    try {
        glb_tidyDatabase();
    } catch (Throwable $e) {
        echo 'The database could not be tidied: ' . $e->getMessage() . "\n";
    }
    if ($glb['child'] !== '' && is_link($glb['child']) === false && @file_get_contents($glb['child'] . '/.go-live-button-selftest-owner') === $glb['token']) {
        glb_removeFolder($glb['child']);
    }
}

function glb_portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);
        return true;
    }
    return false;
}

/**
 * Send one request as one signed-in person (the cookie jar says who; '' = nobody). Redirects are never followed.
 * An answer without this run's header came from somewhere else and comes back as status -1.
 *
 * @param array<string,string>|null $post Form fields.
 * @return array{status:int, location:string, body:string}
 */
function glb_request(string $jar, string $method, string $path, ?array $post = null, int $port = 0): array
{
    global $glb;
    $handle = curl_init('http://127.0.0.1:' . ($port > 0 ? $port : $glb['port']) . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:'],
    ];
    if ($jar !== '') {
        $options[CURLOPT_COOKIEJAR]  = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    curl_setopt_array($handle, $options);
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
    if (preg_match('/^X-Go-Live-Selftest:\s*' . $glb['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start'];
    }
    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S*)\s*$/mi', $head, $found) === 1 ? $found[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
}

/** The form token in a sign-in form. */
function glb_formToken(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $found) === 1 ? $found[1] : '';
}

/** The form token a signed-in person's page carries in its `<meta name="csrf-token">` tag (64 characters), or ''. */
function glb_metaToken(string $jar, string $path = '/'): string
{
    $answer = glb_request($jar, 'GET', $path);
    return preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $answer['body'], $found) === 1 ? $found[1] : '';
}

/** The words of the first coloured message box on a page, as plain text, or ''. */
function glb_flash(string $html): string
{
    if (preg_match('/<div class="alert alert-(\w+)">(.*?)<\/div>/s', $html, $found) !== 1) {
        return '';
    }
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($found[2]), ENT_QUOTES, 'UTF-8')));
}

/**
 * Read the "Send We're live" form off a Host Console page: its address, every hidden field, and whether its button is switched off.
 *
 * @return array{found:bool, action:string, fields:array<string,string>, disabled:bool}
 */
function glb_readForm(string $html, string $marker = 'notify_live'): array
{
    $none = ['found' => false, 'action' => '', 'fields' => [], 'disabled' => false];
    if (preg_match_all('#<form\b([^>]*)>(.*?)</form>#s', $html, $forms, PREG_SET_ORDER) < 1) {
        return $none;
    }
    foreach ($forms as $form) {
        if (preg_match('/name="action" value="' . preg_quote($marker, '/') . '"/', $form[2]) !== 1) {
            continue;
        }
        $action = preg_match('/\baction="([^"]*)"/', $form[1], $found) === 1 ? html_entity_decode($found[1], ENT_QUOTES, 'UTF-8') : '';
        preg_match_all('/<input\b[^>]*type="hidden"[^>]*name="([^"]*)"[^>]*value="([^"]*)"/', $form[2], $inputs, PREG_SET_ORDER);
        $fields = [];
        foreach ($inputs as $input) {
            $fields[$input[1]] = html_entity_decode($input[2], ENT_QUOTES, 'UTF-8');
        }
        return ['found' => true, 'action' => $action, 'fields' => $fields, 'disabled' => preg_match('/<button\b[^>]*\bdisabled\b/', $form[2]) === 1];
    }
    return $none;
}

/** The path of the Host Console page for an event, as the portal writes it. */
function glb_consolePath(int $eventId): string
{
    return '/admin/host-console/event?id=' . $eventId;
}

/**
 * Press the button as a browser would: open the event's Host Console page, read the form off it, and post exactly that
 * (apart from `$change`, which replaces or, with null, removes a field). Redirects are not followed.
 *
 * @param array<string,string|null> $change
 * @return array{page:array{status:int,location:string,body:string}, form:array<string,mixed>, answer:array{status:int,location:string,body:string}|null}
 */
function glb_press(string $jar, int $eventId, array $change = [], ?string $actionInstead = null): array
{
    $page = glb_request($jar, 'GET', glb_consolePath($eventId));
    $form = glb_readForm($page['body']);
    if ($form['found'] === false) {
        return ['page' => $page, 'form' => $form, 'answer' => null];
    }
    $fields = $form['fields'];
    foreach ($change as $name => $value) {
        if ($value === null) {
            unset($fields[$name]);
        } else {
            $fields[$name] = $value;
        }
    }
    return ['page' => $page, 'form' => $form, 'answer' => glb_request($jar, 'POST', $actionInstead ?? $form['action'], $fields)];
}

/**
 * Every subscription this run made: subID => [failCount, lastHttpStatus, isActive]. A device ADDRESSED by a push has failCount
 * one higher and lastHttpStatus -1 (see the header); an untouched one has 0 and null.
 *
 * @return array<int,array{0:int,1:?int,2:int}>
 */
function glb_subs(): array
{
    $out = [];
    $rs  = glb_db()->query('SELECT subID, failCount, lastHttpStatus, isActive FROM tblPushSubscriptions WHERE subID BETWEEN ' . GLB_FIRST_SUB . ' AND ' . GLB_LAST_SUB . ' ORDER BY subID');
    foreach ($rs as $row) {
        $out[(int) $row['subID']] = [(int) $row['failCount'], $row['lastHttpStatus'] === null ? null : (int) $row['lastHttpStatus'], (int) $row['isActive']];
    }
    return $out;
}

/** The ids of the subscriptions that have been addressed this many times (all with outcome -1). @return list<int> */
function glb_addressed(int $times = 1): array
{
    return array_keys(array_filter(glb_subs(), static fn (array $s): bool => $s[0] === $times && $s[1] === -1));
}

/** How many subscriptions have been addressed at all (any count above zero). */
function glb_anyAddressed(): int
{
    return count(array_filter(glb_subs(), static fn (array $s): bool => $s[0] > 0 || $s[1] !== null));
}

/** Put every subscription back to "never addressed", and forget the handler's rate-limit hits, so each part starts clean. */
function glb_reset(): void
{
    global $glb;
    glb_db()->query('UPDATE tblPushSubscriptions SET failCount = 0, lastFailureAt = NULL, lastHttpStatus = NULL, lastUsedAt = NULL WHERE subID BETWEEN ' . GLB_FIRST_SUB . ' AND ' . GLB_LAST_SUB);
    glb_db()->query('DELETE FROM tblApiRateLimits WHERE hitID > ' . (int) $glb['hitMark']);
    glb_clearPushes();
}

/** How many times the handler has recorded a go-live press since the run began, for an organisation. */
function glb_hits(int $siteId): int
{
    global $glb;
    $bucket    = 'pushgolive:' . $siteId;
    $statement = glb_db()->prepare('SELECT COUNT(*) FROM tblApiRateLimits WHERE bucket = ? AND hitID > ?');
    $mark      = (int) $glb['hitMark'];
    $statement->bind_param('si', $bucket, $mark);
    $statement->execute();
    return (int) $statement->get_result()->fetch_row()[0];
}

/**
 * What the sender was handed since the log was last emptied: one entry per device, each with the device's number, the words
 * sent (title, body, link, tag), how long it is kept, its urgency and its collapse key. Read from the file the recorder
 * (put into the scratch copy of the sender, see the header) writes.
 *
 * @return list<array{subID:int, payload:array<string,mixed>, ttl:int, urgency:string, topic:?string}>
 */
function glb_pushes(): array
{
    global $glb;
    $out = [];
    foreach (file($glb['child'] . '/pushes.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry) === true) {
            $out[] = ['subID' => (int) $entry['subID'], 'payload' => (array) json_decode((string) $entry['json'], true), 'ttl' => (int) $entry['ttl'], 'urgency' => (string) $entry['urgency'], 'topic' => $entry['topic']];
        }
    }
    return $out;
}

/** Empty the recorder's log. */
function glb_clearPushes(): void
{
    global $glb;
    file_put_contents($glb['child'] . '/pushes.jsonl', '');
}

/** The numbers of the devices the sender was handed, in order. @return list<int> */
function glb_pushedTo(): array
{
    return array_map(static fn (array $p): int => $p['subID'], glb_pushes());
}

/**
 * Run a small Node.js program with arguments and read the JSON it prints; null when Node.js is not installed.
 *
 * @param list<string> $arguments
 * @return array<string,mixed>|null
 */
function glb_node(string $program, array $arguments): ?array
{
    global $glb;
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        return null;
    }
    $file = $glb['child'] . '/node-' . md5($program) . '.mjs';
    file_put_contents($file, $program);
    $run = proc_open([$node, $file, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($run) === false) {
        return ['error' => 'Node.js could not be started'];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($run);
    $json = json_decode(trim($out), true);
    return is_array($json) === true ? $json : ['error' => 'no JSON from Node.js: ' . mb_substr($out . $err, 0, 300)];
}

/**
 * The page's own script that switches the button off when the form is sent, run in Node.js with stand-ins for the form and
 * the button. It is not a browser: it shows the script wires a "submit" handler that disables the button, nothing more.
 */
const GLB_NODE_SUBMIT_PROGRAM = <<<'NODEJS'
// Written by tools/go-live-button-selftest.php for ONE run (deleted with its folder). Test only.
import { readFileSync } from 'node:fs';
const html = readFileSync(process.argv[2], 'utf8');
const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((m) => m[1]).filter((s) => s.includes('golive-form'));
if (scripts.length !== 1) { console.log(JSON.stringify({ error: 'found ' + scripts.length + ' scripts about the form, expected 1' })); process.exit(2); }
const button = { disabled: false };
let onSubmit = null;
const form = {
    addEventListener: (type, fn) => { if (type === 'submit') { onSubmit = fn; } },
    querySelector: (selector) => (selector === 'button[type="submit"]' ? button : null),
};
new Function('document', scripts[0])({ getElementById: (id) => (id === 'golive-form' ? form : null) });
const wired = onSubmit !== null;
const before = button.disabled;
if (wired) { onSubmit(); }
console.log(JSON.stringify({ wired, before, after: button.disabled }));
NODEJS;

/**
 * Send several requests AT THE SAME MOMENT (curl_multi), each as its own signed-in person. Returns status and Location per request.
 *
 * @param list<array{jar:string, path:string, fields:array<string,string>, port:int}> $requests
 * @return list<array{status:int, location:string}>
 */
function glb_requestMany(array $requests): array
{
    global $glb;
    $multi   = curl_multi_init();
    $handles = [];
    foreach ($requests as $i => $one) {
        $handle = curl_init('http://127.0.0.1:' . $one['port'] . $one['path']);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:'],
            CURLOPT_COOKIEJAR => $one['jar'], CURLOPT_COOKIEFILE => $one['jar'],
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($one['fields']),
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$i] = $handle;
    }
    do {
        $state = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $state === CURLM_OK);
    $out = [];
    foreach ($handles as $i => $handle) {
        $raw  = (string) curl_multi_getcontent($handle);
        $head = substr($raw, 0, (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE));
        $mine = preg_match('/^X-Go-Live-Selftest:\s*' . $glb['token'] . '\s*$/mi', $head) === 1;
        $out[$i] = [
            'status'   => $mine === true ? (int) curl_getinfo($handle, CURLINFO_HTTP_CODE) : -1,
            'location' => preg_match('/^Location:\s*(\S*)\s*$/mi', $head, $found) === 1 ? $found[1] : '',
        ];
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);
    return $out;
}

/** Sign one of the made-up accounts in through the real sign-in page, into a cookie jar of its own. */
function glb_signIn(string $who, string $jarName): string
{
    global $glb, $password;
    $jar   = $glb['child'] . '/jar-' . $jarName . '.txt';
    $token = glb_formToken(glb_request($jar, 'GET', '/login')['body']);
    $in    = glb_request($jar, 'POST', '/login', ['csrf_token' => $token, 'username' => 'selftest-golive-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        glb_refuse("Signing in as selftest-golive-{$who} did not work (HTTP {$in['status']} {$in['location']}).");
    }
    return $jar;
}

// =============================================================================
// 🚦 Setting up
// =============================================================================

if (str_starts_with((string) getenv('SELFTEST_DB_NAME'), 'selftest_') === false) {
    glb_refuse("Set SELFTEST_DB_NAME to a throwaway database whose name starts with selftest_ (got '" . (string) getenv('SELFTEST_DB_NAME') . "').");
}
$glb['port'] = (int) (getenv('SELFTEST_WEB_PORT') !== false ? getenv('SELFTEST_WEB_PORT') : 0);
if ($glb['port'] < 1024 || $glb['port'] > 65535) {
    glb_refuse('Set SELFTEST_WEB_PORT to a free port for the test web server.');
}
if (glb_portAnswers($glb['port']) === true) {
    glb_refuse('Something already answers on port ' . $glb['port'] . '.');
}
foreach (['curl_init', 'proc_open', 'posix_kill', 'curl_multi_init'] as $needed) {
    if (function_exists($needed) === false) {
        glb_refuse("This PHP has no {$needed}().");
    }
}
$db = glb_db();
if ((int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetch_row()[0] < 200) {
    glb_refuse('This database has fewer than 200 tables, so it was not built from web/_sql/full_schema.sql.');
}

$webSource = (string) realpath(__DIR__ . '/../web');
$parent    = (string) realpath((string) (getenv('SELFTEST_WEB_DIR') !== false && getenv('SELFTEST_WEB_DIR') !== '' ? getenv('SELFTEST_WEB_DIR') : sys_get_temp_dir()));
if ($parent === '' || str_starts_with($parent . '/', dirname($webSource) . '/') === true || str_starts_with($webSource . '/', $parent . '/') === true) {
    glb_refuse('The scratch folder must be outside the repository (set SELFTEST_WEB_DIR).');
}

register_shutdown_function('glb_cleanup');
$glb['errMark'] = (int) $db->query('SELECT COALESCE(MAX(errorID), 0) FROM tblErrors')->fetch_row()[0];
$glb['logMark'] = (int) $db->query('SELECT COALESCE(MAX(logID), 0) FROM tblActivityLogs')->fetch_row()[0];
$glb['hitMark'] = (int) $db->query('SELECT COALESCE(MAX(hitID), 0) FROM tblApiRateLimits')->fetch_row()[0];
// Settings first: read what each is, so that the clean-up can put it back whatever happens next.
foreach (array_keys(GLB_SETTINGS) as $key) {
    $statement = $db->prepare('SELECT settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL');
    $statement->bind_param('s', $key);
    $statement->execute();
    $row = $statement->get_result()->fetch_row();
    if ($row === null) {
        glb_refuse("This database has no portal-wide {$key} setting, so it was not built from web/_sql/full_schema.sql.");
    }
    $glb['putBack'][$key] = [(string) $row[0], (int) $row[1]];
}
glb_tidyDatabase();

// 📣 Web Push looks configured for this run (made-up text; no key is ever used, because every push is refused first).
//    The private key's row is marked "not secret" for the run, so that the portal reads it as plain text instead of
//    trying to decrypt it with a key it was not written with; the clean-up puts the mark back.
foreach (GLB_SETTINGS as $key => $value) {
    $statement = $db->prepare('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingKey = ? AND siteID IS NULL');
    $sensitive = 0;
    $statement->bind_param('sis', $value, $sensitive, $key);
    $statement->execute();
}

// 🏢 A second organisation (nothing treats it as one until part F switches several organisations on). Each organisation
//    has a name of its own, and the installation's name (the `site.name` setting, in GLB_SETTINGS above) is different
//    again, so a push that names the wrong one can be told. The first organisation's own name is put back at the end.
$glb['siteNameA'] = (string) $db->query('SELECT siteName FROM tblSites WHERE siteID = 1')->fetch_row()[0];
$glb['siteStampA'] = (string) $db->query('SELECT updatedAt FROM tblSites WHERE siteID = 1')->fetch_row()[0];
$statement = $db->prepare('UPDATE tblSites SET siteName = ? WHERE siteID = 1');
$nameA     = GLB_NAME_A;
$statement->bind_param('s', $nameA);
$statement->execute();
$statement = $db->prepare("INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES (?, 'glb-second', ?, 1)");
$orgB      = GLB_ORG_B;
$nameB     = GLB_NAME_B;
$statement->bind_param('is', $orgB, $nameB);
$statement->execute();

// 👥 Ten accounts, signed in with a password made for this run: [name, full name, site admin, organisation].
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
$people   = [
    GLB_ADMIN    => ['admin', 'Ada Admin', 1, 1],
    GLB_ON       => ['on', 'Olive On', 0, 1],
    GLB_OFF      => ['off', 'Otto Off', 0, 1],
    GLB_REM      => ['rem', 'Remy Reminders', 0, 1],
    GLB_PLAIN    => ['plain', 'Pat Plain', 0, 1],
    GLB_COORD    => ['coord', 'Cory Coordinator', 0, 1],
    GLB_ADMIN_B  => ['adminb', 'Bea Admin-B', 1, GLB_ORG_B],
    GLB_MEMBER_B => ['memberb', 'Ben Member-B', 0, GLB_ORG_B],
    GLB_GONE     => ['gone', 'Gail Gone', 0, 1],
    GLB_LEFT     => ['left', 'Len Left', 0, 1],
    GLB_COORD2   => ['coord2', 'Cora Coordinator-Two', 0, 1],
    GLB_COORDX   => ['coordx', 'Xavier Coordinator-Elsewhere', 0, 1],
    GLB_COORDW   => ['coordw', 'Wanda Coordinator-Withdrawn', 0, 1],
    GLB_COORDD   => ['coordd', 'Dale Coordinator-Deleted', 0, 1],
];
foreach ($people as $id => [$who, $name, $isSiteAdmin, $org]) {
    $statement = $db->prepare('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)');
    $email     = 'u' . $id . '.golive@selftest.invalid';
    $statement->bind_param('iss', $id, $name, $email);
    $statement->execute();
    $statement = $db->prepare('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)');
    $statement->bind_param('iii', $id, $org, $isSiteAdmin);
    $statement->execute();
    $statement = $db->prepare('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)');
    $username  = 'selftest-golive-' . $who;
    $statement->bind_param('iss', $id, $username, $hash);
    $statement->execute();
}

// Two people who have left: one whose ACCOUNT is switched off, one whose MEMBERSHIP of the first organisation is switched off.
$db->query('UPDATE tblUsers SET isActive = 0 WHERE userID = ' . GLB_GONE);
$db->query('UPDATE tblUserSites SET isActive = 0 WHERE userID = ' . GLB_LEFT . ' AND siteID = 1');

// 📅 One event of each organisation (dates worked out from today), and the coordinator's place on the first.
$day   = date('Y-m-d', strtotime('+9 days'));
$start = $day . ' 10:00:00';
$end   = $day . ' 11:00:00';
foreach ([GLB_EVENT => [1, 'glb-event'], GLB_EVENT_B => [GLB_ORG_B, 'glb-event-b'], GLB_EVENT_2 => [1, 'glb-event-2']] as $id => [$org, $slug]) {
    $statement = $db->prepare(
        'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
        . "VALUES (?, ?, ?, 'GLB livestream', 'GLB description', ?, ?, 'UTC', 'published', 1, 0)"
    );
    $statement->bind_param('iisss', $id, $org, $slug, $start, $end);
    $statement->execute();
}
// Coordinators: GLB_COORD of the first event; GLB_COORD2 of the second event of the first organisation ONLY; GLB_COORDX of the
// other organisation's event (so a person who passes Auth::isCoordinatorOf() for it, in the wrong organisation).
// Three more kinds of person who must NOT be able to send (added after the independent check of 13 October 2026, which showed
// the test passed even when each of these could): GLB_COORDW coordinated the first event but their place has been WITHDRAWN;
// GLB_COORDD coordinates an event that has been DELETED and one that was IMPORTED from an outside calendar (read-only).
$deletedId  = GLB_EVENT_DEL;
$importedId = GLB_EVENT_IMP;
foreach ([[$deletedId, 'glb-event-deleted', 1, 'NULL'], [$importedId, 'glb-event-imported', 0, '916901']] as [$extraId, $extraSlug, $extraDeleted, $extraFeed]) {
    $statement = $db->prepare(
        'INSERT INTO tblEvents (eventID, siteID, externalFeedID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
        . "VALUES (?, 1, " . $extraFeed . ", ?, 'GLB livestream', 'GLB description', ?, ?, 'UTC', 'published', 1, ?)"
    );
    $statement->bind_param('isssi', $extraId, $extraSlug, $start, $end, $extraDeleted);
    $statement->execute();
}
foreach ([[GLB_EVENT, GLB_COORD], [GLB_EVENT_2, GLB_COORD2], [GLB_EVENT_B, GLB_COORDX], [GLB_EVENT, GLB_COORDW], [GLB_EVENT_DEL, GLB_COORDD], [GLB_EVENT_IMP, GLB_COORDD]] as [$coordEvent, $coordUser]) {
    $statement = $db->prepare('INSERT INTO tblEventCoordinators (eventID, userID) VALUES (?, ?)');
    $statement->bind_param('ii', $coordEvent, $coordUser);
    $statement->execute();
}
$db->query('UPDATE tblEventCoordinators SET revokedAt = NOW() WHERE userID = ' . GLB_COORDW);

// 📺 A channel for each organisation, "live" every day of the week all day, in UTC, so that the run is live whatever time it is run.
foreach ([GLB_CHANNEL => 1, GLB_CHANNEL_B => GLB_ORG_B] as $channel => $org) {
    $statement = $db->prepare("INSERT INTO tblLivestreamChannel (channelID, siteID, name, platform) VALUES (?, ?, 'GLB channel', 'custom')");
    $statement->bind_param('ii', $channel, $org);
    $statement->execute();
    for ($dow = 0; $dow <= 6; $dow++) {
        $statement = $db->prepare("INSERT INTO tblLivestreamSchedule (channelID, dayOfWeek, startTime, endTime, timezone, isActive) VALUES (?, ?, '00:00:00', '23:59:59', 'UTC', 1)");
        $statement->bind_param('ii', $channel, $dow);
        $statement->execute();
    }
}

// 🔔 Push subscriptions. EVERY ENDPOINT IS http://, WHICH THE SENDER REFUSES BEFORE ANY NETWORK CALL (see the header).
//    [subID, organisation, account or null, channels, active]
$subs = [
    [GLB_S_ON, 1, GLB_ON, '["livestream","reminders"]', 1],
    [GLB_S_OFF, 1, GLB_OFF, '["livestream","reminders"]', 1],
    [GLB_S_ANON, 1, null, '["livestream"]', 1],
    [GLB_S_REM, 1, GLB_REM, '["reminders"]', 1],
    [GLB_S_DEAD, 1, GLB_ON, '["livestream"]', 0],
    [GLB_S_B, GLB_ORG_B, GLB_MEMBER_B, '["livestream","reminders"]', 1],
    [GLB_S_BANON, GLB_ORG_B, null, '["livestream"]', 1],
    [GLB_S_GONE, 1, GLB_GONE, '["livestream"]', 1],
    [GLB_S_LEFT, 1, GLB_LEFT, '["livestream"]', 1],
    [GLB_S_ON2, 1, GLB_ON, '["livestream"]', 1],
    [GLB_S_B2, GLB_ORG_B, GLB_MEMBER_B, '["livestream"]', 1],
];
foreach ($subs as [$id, $org, $user, $channels, $active]) {
    $endpoint  = 'http://push-' . $id . '.selftest.invalid/endpoint-' . $id;
    $statement = $db->prepare('INSERT INTO tblPushSubscriptions (subID, siteID, userID, endpoint, p256dhKey, authKey, channels, isActive) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $key       = 'selftest-not-a-key';
    $statement->bind_param('iiissssi', $id, $org, $user, $endpoint, $key, $key, $channels, $active);
    $statement->execute();
}
// Part L reads the device COUNTS from Admin, Integrations, Push, so no device but the run's own may be in these two organisations.
$others = (int) $db->query('SELECT COUNT(*) FROM tblPushSubscriptions WHERE siteID IN (1, ' . GLB_ORG_B . ') AND subID NOT BETWEEN ' . GLB_FIRST_SUB . ' AND ' . GLB_LAST_SUB)->fetch_row()[0];
if ($others !== 0) {
    glb_refuse('This database already holds ' . $others . ' push device(s) that are not this test\'s, so the counts on the Integrations page cannot be checked. Use a database built for this run.');
}
// Lock one of the three: nothing may start unless every subscription of the run is an http:// address.
$real = (int) $db->query("SELECT COUNT(*) FROM tblPushSubscriptions WHERE subID BETWEEN " . GLB_FIRST_SUB . ' AND ' . GLB_LAST_SUB . " AND endpoint NOT LIKE 'http://%.selftest.invalid/%'")->fetch_row()[0];
if ($real !== 0) {
    glb_refuse('A subscription of this run has an address that is not http://...selftest.invalid. Refusing: a real push could be sent.');
}

// 📁 The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . '/go-live-button-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    glb_refuse("Could not make a NEW scratch folder '{$child}'.");
}
$glb['child'] = $child;
file_put_contents($child . '/.go-live-button-selftest-owner', $glb['token']);   // the marker FIRST
mkdir($child . '/web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSource, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $relative = substr((string) $path, strlen($webSource) + 1);
    if (in_array(explode('/', $relative)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        glb_refuse('web/ holds a link (' . $relative . '); refusing to copy it.');
    }
    if ($info->isDir() === true) {
        mkdir($child . '/web/' . $relative, 0700);
    } else {
        copy((string) $path, $child . '/web/' . $relative);
        $copied++;
    }
}
if ($copied < 100) {
    glb_refuse("The copy of web/ holds only {$copied} files.");
}
mkdir($child . '/web/_auth_keys', 0700);
mkdir($child . '/sessions', 0700);
file_put_contents($child . '/web/_auth_keys/auth_creds.php', "<?php\n// Written by tools/go-live-button-selftest.php for one run; deleted with its folder.\nreturn " . var_export([
    'db_host' => (string) (getenv('SELFTEST_DB_HOST') !== false ? getenv('SELFTEST_DB_HOST') : '127.0.0.1'),
    'db_user' => (string) (getenv('SELFTEST_DB_USER') !== false ? getenv('SELFTEST_DB_USER') : 'root'),
    'db_pass' => (string) (getenv('SELFTEST_DB_PASS') !== false ? getenv('SELFTEST_DB_PASS') : ''),
    'db_name' => (string) getenv('SELFTEST_DB_NAME'),
    'db_port' => (int) (getenv('SELFTEST_DB_PORT') !== false ? getenv('SELFTEST_DB_PORT') : 3306),
], true) . ";\n");
file_put_contents($child . '/web/_auth_keys/enc.key', bin2hex(random_bytes(32)));

// 🎙️ THE RECORDER (scratch copy only; the repository's sender is not touched). One line is put at the top of
//    `WebPush::send()` in the COPY: it writes what the sender was handed (the device, the words, how long it is
//    kept, how urgent, its collapse key) to a file, and, when the environment variable GLB_SLOW_MS is set (only
//    for the second server, part I), waits that long, so that presses which overlap really do overlap. It opens
//    no connection and changes nothing else: the sender then goes on to refuse the `http://` address exactly as
//    before. If the line cannot be put in exactly once, the test does not run at all.
$senderFile = $child . '/web/_core/WebPush.php';
$senderText = (string) file_get_contents($senderFile);
$anchor     = "public static function send(array \$sub, string \$json, int \$ttl, string \$urgency = 'normal', ?string \$topic = null): int\n    {\n        \$endpoint = (string) (\$sub['endpoint'] ?? '');\n";
if (substr_count($senderText, $anchor) !== 1) {
    glb_refuse('The start of WebPush::send() is not where the test expects it, so the recorder cannot be put in. Nothing was run.');
}
$recorder = "        // go-live-button-selftest RECORDER (scratch copy only): what the sender was handed. No connection is opened here.\n"
    . "        @file_put_contents(" . var_export($child . '/pushes.jsonl', true) . ", json_encode(['subID' => (int) (\$sub['subID'] ?? 0), 'json' => \$json, 'ttl' => \$ttl, 'urgency' => \$urgency, 'topic' => \$topic]) . \"\\n\", FILE_APPEND | LOCK_EX);\n"
    . "        if ((int) getenv('GLB_SLOW_MS') > 0) { usleep((int) getenv('GLB_SLOW_MS') * 1000); }\n";
file_put_contents($senderFile, str_replace($anchor, $anchor . $recorder, $senderText));
file_put_contents($child . '/pushes.jsonl', '');

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/go-live-button-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Go-Live-Selftest: __TOKEN__');
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
file_put_contents($child . '/router.php', str_replace('__TOKEN__', $glb['token'], $router));

// The server: its command as a LIST (no shell in between) and ONE process, so nothing can outlive it.
$environment = getenv();
unset($environment['PHP_CLI_SERVER_WORKERS']);
$environment['PORTAL_ENV'] = 'prod';
$glb['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . '/sessions', '-d', 'error_log=' . $child . '/php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $glb['port'], '-t', $child . '/web/public_html', $child . '/router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . '/server-output.log', 'a'], 2 => ['file', $child . '/server-output.log', 'a']],
    $pipes,
    $child,
    $environment
);
if (is_resource($glb['proc']) === false) {
    glb_refuse('The web server could not be started.');
}
for ($waited = 0; $waited < 150 && glb_portAnswers($glb['port']) === false; $waited++) {
    usleep(100000);
}
if (glb_portAnswers($glb['port']) === false) {
    glb_refuse('The web server never answered on port ' . $glb['port'] . '.');
}

echo "The Host Console's \"Send We're live\" button — self-test (#612)\n";
echo str_repeat('=', 78) . "\n";
printf("Server process %d, port %d, PHP %s, database server %s. No real push can be sent: every device that could be sent anything is an http:// address (part K's one https device is deleted before anything could address it).\n", (int) proc_get_status($glb['proc'])['pid'], $glb['port'], PHP_VERSION, $db->server_info);

$jar = [];
foreach (['admin', 'off', 'plain', 'coord', 'coord2', 'coordx', 'coordw', 'coordd'] as $who) {
    $jar[$who] = glb_signIn($who, $who);
}

// "We're live now" is switched OFF by the member, on the real notification preferences page, the way a person does it:
// every switch left on except that one.
$prefsPage = glb_request($jar['off'], 'GET', '/account/notifications');
$prefs     = [];
foreach (['emailDigest', 'eventReminders', 'eventRsvpConfirmation', 'expenseStatusUpdates', 'expenseApproverNudges', 'announcementsNew', 'prayerModeration', 'accountSecurity',
    'givingStatements', 'taskReminders', 'rotaReminders', 'approvalRequests', 'pushServiceReminders', 'pushApprovals'] as $key) {
    $prefs[$key] = '1';
}
$saved = glb_request($jar['off'], 'POST', '/account/notifications/save', ['csrf_token' => glb_metaToken($jar['off'], '/account/notifications'), 'prefs' => $prefs, 'sabbathHonour' => 'inherit']);

// =============================================================================
// 🧪 The checks
// =============================================================================

echo "\nA. The button, on the Host Console page\n";
$storedPrefs = json_decode((string) ($db->query('SELECT notifyPrefs FROM tblUsers WHERE userID = ' . GLB_OFF)->fetch_row()[0] ?? ''), true);
glb_check(
    $prefsPage['status'] === 200 && $saved['status'] === 302 && is_array($storedPrefs) && ($storedPrefs['pushLivestream'] ?? null) === false && ($storedPrefs['pushServiceReminders'] ?? null) === true,
    'one member switched "We\'re live now" OFF on the real notification preferences page (and left the others on)',
    'page HTTP ' . $prefsPage['status'] . ', save HTTP ' . $saved['status'] . ', stored ' . json_encode($storedPrefs)
);
glb_reset();
$first = glb_press($jar['admin'], GLB_EVENT);
$form  = $first['form'];
glb_check($first['page']['status'] === 200 && $form['found'] === true, 'an administrator is shown the Host Console page of an event, with the button', 'HTTP ' . $first['page']['status']);
glb_check($form['disabled'] === false, 'the button is switched on (Web Push is set up and a channel is in its live window)');
glb_check(
    $form['action'] === '/admin/livestream/channels',
    'the form posts to admin/livestream/channels, the page that acts on it (not admin/livestream, the dashboard)',
    'it posts to "' . $form['action'] . '"'
);
glb_check(
    isset($form['fields']['csrf_token']) === true && strlen($form['fields']['csrf_token']) === 64 && ($form['fields']['action'] ?? '') === 'notify_live'
        && ($form['fields']['eventID'] ?? '') === (string) GLB_EVENT && ($form['fields']['returnTo'] ?? '') === glb_consolePath(GLB_EVENT),
    'it carries a form token, the action, the event number and the Host Console address to come back to',
    json_encode($form['fields']) ?: ''
);
$answer = $first['answer'] ?? ['status' => 0, 'location' => '', 'body' => 'the form was not found'];
glb_check(
    $answer['status'] === 302 && $answer['location'] === glb_consolePath(GLB_EVENT),
    'pressing it lands the person back on that event\'s Host Console page',
    'HTTP ' . $answer['status'] . ' to "' . $answer['location'] . '"'
);
$back = glb_request($jar['admin'], 'GET', $answer['location'] !== '' ? $answer['location'] : glb_consolePath(GLB_EVENT));
glb_check(glb_flash($back['body']) === glb_sentWords(2), 'and tells them how many devices were addressed: two', glb_flash($back['body']));
glb_check(
    glb_addressed(1) === [GLB_S_ON, GLB_S_ON2],
    'ONE push went to this organisation\'s subscribers: the two devices of the member who left it on, once each (a device with no account is no longer one of them)',
    json_encode(glb_subs()) ?: ''
);
$subs = glb_subs();
glb_check(
    $subs[GLB_S_OFF] === [0, null, 1] && $subs[GLB_S_REM] === [0, null, 1] && $subs[GLB_S_DEAD] === [0, null, 0] && $subs[GLB_S_B] === [0, null, 1] && $subs[GLB_S_BANON] === [0, null, 1]
        && $subs[GLB_S_GONE] === [0, null, 1] && $subs[GLB_S_LEFT] === [0, null, 1] && $subs[GLB_S_B2] === [0, null, 1] && $subs[GLB_S_ANON] === [0, null, 1],
    'and to nobody else: not the member who switched it off, not a device that asked for reminders only, not a device switched off, not the other organisation\'s devices, '
        . 'not the device of a person whose ACCOUNT is switched off, not the device of a person whose MEMBERSHIP of this organisation is switched off, '
        . 'not the device with NO account (it is still stored, untouched)',
    json_encode($subs) ?: ''
);
$sent = glb_pushes();
glb_check(array_column($sent, 'subID') === [GLB_S_ON, GLB_S_ON2], 'the sender was handed the push for exactly those two devices (read from the recorder in the scratch copy of the sender)', json_encode(array_column($sent, 'subID')) ?: '');
$first_ = $sent[0] ?? ['payload' => [], 'ttl' => 0, 'urgency' => '', 'topic' => null];
glb_check(
    ($first_['payload']['title'] ?? '') === GLB_NAME_A . ' is live now' && str_contains(json_encode($sent) ?: '', GLB_NAME_INSTALLATION) === false,
    'the push is in the ORGANISATION\'s own name ("' . GLB_NAME_A . ' is live now"), not the installation\'s ("' . GLB_NAME_INSTALLATION . '")',
    json_encode($first_['payload']) ?: ''
);
glb_check(
    ($first_['payload']['body'] ?? '') === 'GLB channel' && ($first_['payload']['url'] ?? '') === '/live?eventID=' . GLB_EVENT && ($first_['payload']['tag'] ?? '') === 'golive'
        && $first_['ttl'] === 900 && $first_['urgency'] === 'high' && $first_['topic'] === 'golive1',
    'and says the channel\'s name, links to the live page for this event, is kept 900 seconds, is urgent, and has the organisation\'s collapse key (a newer one replaces an unseen older one)',
    json_encode($first_) ?: ''
);
glb_check(glb_hits(1) === 1, 'the handler recorded exactly one press for this organisation (its limit of three in fifteen minutes counts them)', 'hits ' . glb_hits(1));
$statuses = array_values(array_unique(array_filter(array_column(glb_subs(), 1), static fn ($s): bool => $s !== null)));
glb_check($statuses === [-1], 'every address was refused by the sender\'s own safety check before any network call (the only outcome on record is -1)', json_encode($statuses) ?: '');

echo "\nB. A second press, and the limit\n";
$second = glb_press($jar['admin'], GLB_EVENT);
glb_check(
    ($second['answer']['status'] ?? 0) === 302 && glb_addressed(2) === [GLB_S_ON, GLB_S_ON2] && glb_hits(1) === 2,
    'a second press SENDS AGAIN: the same two devices are addressed a second time (nothing remembers the first push), and it counts as a second press',
    json_encode(glb_subs()) ?: ''
);
glb_press($jar['admin'], GLB_EVENT);
glb_check(glb_addressed(3) === [GLB_S_ON, GLB_S_ON2] && glb_hits(1) === 3, 'a third press does the same');
$fourth = glb_press($jar['admin'], GLB_EVENT);
$words  = glb_flash(glb_request($jar['admin'], 'GET', glb_consolePath(GLB_EVENT))['body']);
glb_check(
    ($fourth['answer']['status'] ?? 0) === 302 && glb_addressed(3) === [GLB_S_ON, GLB_S_ON2] && glb_hits(1) === 3 && str_starts_with($words, 'Too many "we\'re live" pushes sent recently'),
    'a fourth press within fifteen minutes is refused by the handler\'s own limit: it says so, addresses nobody, and the person lands back on the page',
    $words . ' / ' . json_encode(glb_subs())
);

echo "\nC. Who may not press it\n";
glb_reset();
$fieldsOf = static fn (int $event): array => ['action' => 'notify_live', 'eventID' => (string) $event, 'returnTo' => glb_consolePath($event)];
$memberToken = glb_metaToken($jar['plain'], '/');
glb_check(strlen($memberToken) === 64, 'an ordinary member has a genuine form token of their own (read from a page they may open)', 'got ' . strlen($memberToken));
$answer = glb_request($jar['plain'], 'POST', $form['action'], ['csrf_token' => $memberToken] + $fieldsOf(GLB_EVENT));
glb_check($answer['status'] === 403 && glb_anyAddressed() === 0 && glb_hits(1) === 0, 'an ordinary member, with their own good token, is refused (403) and nothing is sent', 'HTTP ' . $answer['status']);
$answer = glb_request($jar['plain'], 'GET', glb_consolePath(GLB_EVENT));
glb_check($answer['status'] === 403 && glb_readForm($answer['body'])['found'] === false, 'and is not shown the button: the Host Console page refuses them too (403)', 'HTTP ' . $answer['status']);
// (An event coordinator who is not an administrator may send it for THEIR event: part J.)
$answer = glb_request('', 'POST', $form['action'], ['csrf_token' => str_repeat('a', 64)] + $fieldsOf(GLB_EVENT));
glb_check(in_array($answer['status'], [301, 302, 303], true) && str_contains($answer['location'], 'login') === true && glb_anyAddressed() === 0, 'a signed-out visitor is sent to sign in and nothing is sent', 'HTTP ' . $answer['status'] . ' to "' . $answer['location'] . '"');

echo "\nD. The form token, and a GET\n";
glb_reset();
$cases = ['no form token at all' => null, 'an empty token' => '', 'a made-up token' => str_repeat('0', 64), 'another person\'s good token' => $memberToken];
foreach ($cases as $words => $token) {
    $pressed = glb_press($jar['admin'], GLB_EVENT, ['csrf_token' => $token]);
    glb_check(($pressed['answer']['status'] ?? 0) === 400 && glb_anyAddressed() === 0 && glb_hits(1) === 0, 'a press with ' . $words . ' is refused (400) and nothing is sent', 'HTTP ' . ($pressed['answer']['status'] ?? '-'));
}
$query = http_build_query(['action' => 'notify_live', 'eventID' => (string) GLB_EVENT, 'returnTo' => glb_consolePath(GLB_EVENT), 'csrf_token' => $first['form']['fields']['csrf_token'] ?? '']);
$answer = glb_request($jar['admin'], 'GET', $form['action'] . '?' . $query);
glb_check($answer['status'] === 200 && glb_anyAddressed() === 0 && glb_hits(1) === 0, 'a GET of the same address, with every field in it, sends nothing (it just shows the channels page)', 'HTTP ' . $answer['status']);
$old = glb_request($jar['admin'], 'POST', '/admin/livestream', ['csrf_token' => glb_metaToken($jar['admin'], '/')] + $fieldsOf(GLB_EVENT));
glb_check(glb_anyAddressed() === 0 && glb_hits(1) === 0, 'and the address the form used to post to, admin/livestream, still sends nothing (it is the dashboard)', 'HTTP ' . $old['status']);

glb_reset();
$once   = glb_press($jar['admin'], GLB_EVENT);
$again  = glb_request($jar['admin'], 'POST', $once['form']['action'] ?? $form['action'], $once['form']['fields'] ?? []);
glb_check(
    ($once['answer']['status'] ?? 0) === 302 && $again['status'] === 400 && glb_hits(1) === 1 && glb_addressed(1) === [GLB_S_ON, GLB_S_ON2],
    'the SAME form sent twice (a double-click that got past the button): the first press works, the second is refused (400, its form token is used up) and sends nothing',
    'HTTP ' . ($once['answer']['status'] ?? '-') . ' then ' . $again['status']
);
glb_check(
    str_contains($again['body'], 'This page was out of date, so nothing was changed. If you pressed a button twice, only the first press counted.') === true
        && str_contains($again['body'], 'href="' . glb_consolePath(GLB_EVENT) . '"') === true,
    'and the refusal is a plain sentence with a link back to the Host Console page for that event (not a bare "Bad request")',
    mb_substr(strip_tags($again['body']), 0, 160)
);
$hand = glb_request($jar['admin'], 'POST', $form['action'], ['csrf_token' => str_repeat('0', 64), 'action' => 'notify_live', 'returnTo' => 'https://evil.example' . glb_consolePath(GLB_EVENT)]);
glb_check(
    $hand['status'] === 400 && str_contains($hand['body'], 'evil') === false && str_contains($hand['body'], 'href="/admin/livestream/channels"') === true,
    'a refused press that names an address elsewhere as the way back does not put it in the page: the link goes to the channels page instead',
    mb_substr($hand['body'], 0, 200)
);

echo "\nE. The come-back address\n";
$good = glb_consolePath(GLB_EVENT);
$accepted = ['the Host Console\'s own address' => $good];
$refused  = [
    'a whole address on another website' => 'https://evil.example' . $good,
    'an address with two slashes at the front' => '//evil.example' . $good,
    'a backslash form' => '/\\evil.example' . $good,
    'the right page with more after the number' => $good . '&next=https://evil.example',
    'the right page with a word for a number' => '/admin/host-console/event?id=abc',
    'a different page of the portal' => '/admin/settings',
    'the right page with a path added' => '/admin/host-console/event/../../settings?id=' . GLB_EVENT,
    'a javascript address' => 'javascript:alert(1)',
    'an empty address' => '',
];
foreach ($accepted as $words => $address) {
    glb_reset();
    $pressed = glb_press($jar['admin'], GLB_EVENT, ['returnTo' => $address]);
    glb_check(($pressed['answer']['location'] ?? '') === $address, $words . ' is accepted: the person is sent back to it', 'sent to "' . ($pressed['answer']['location'] ?? '-') . '"');
}
// One line break after the right address: the handler's pattern ends in "$", which allows ONE trailing line break, so this
// is accepted. It is harmless (PHP strips the line break from the header, so the person is sent to the right page, as
// here) and is not a way to anywhere else: nothing can follow the break. Pinned so that a change is noticed.
glb_reset();
$pressed = glb_press($jar['admin'], GLB_EVENT, ['returnTo' => $good . "\n"]);
glb_check(($pressed['answer']['location'] ?? '') === $good, 'the right address with ONE line break after it is read as the right address (harmless: the line break is dropped from the header)', 'sent to "' . ($pressed['answer']['location'] ?? '-') . '"');
foreach ($refused as $words => $address) {
    glb_reset();
    $pressed  = glb_press($jar['admin'], GLB_EVENT, ['returnTo' => $address]);
    $location = $pressed['answer']['location'] ?? '-';
    // Where the person may end up: the default (the dashboard), or no redirect at all. Never anywhere else, and never off this portal.
    $stays    = $location === '/admin/livestream' || ($location === '' && ($pressed['answer']['status'] ?? 0) === 200);
    glb_check($stays === true && str_contains($location, 'evil') === false, $words . ' is not accepted: the person is not sent there', 'HTTP ' . ($pressed['answer']['status'] ?? '-') . ' to "' . $location . '"');
}

echo "\nF. Switching it off lasts\n";
glb_reset();
// The member who switched "We're live now" off (in the set-up above) opens their ACCOUNT page and presses Save on its own
// "Notification Preferences" card without touching anything about push. That card has three switches of its own.
$accountPage = glb_request($jar['off'], 'GET', '/account');
$card        = glb_readForm($accountPage['body'], 'update_notifications');
glb_check($accountPage['status'] === 200 && $card['found'] === true && isset($card['fields']['csrf_token']) === true, 'the member opens their Account page and finds the "Notification Preferences" card', 'HTTP ' . $accountPage['status']);
$savedCard = glb_request($jar['off'], 'POST', $card['action'], $card['fields'] + ['emailDigest' => '1', 'eventReminders' => '1']);
$storedNow = json_decode((string) ($db->query('SELECT notifyPrefs FROM tblUsers WHERE userID = ' . GLB_OFF)->fetch_row()[0] ?? ''), true);
glb_check($savedCard['status'] === 302, 'pressing Save on that card works (it is redirected back to the Account page)', 'HTTP ' . $savedCard['status'] . ' to "' . $savedCard['location'] . '"');
glb_check(
    is_array($storedNow) === true && ($storedNow['emailDigest'] ?? null) === true && ($storedNow['eventReminders'] ?? null) === true && ($storedNow['expenseUpdates'] ?? null) === false,
    'the card\'s own three choices were saved as pressed',
    json_encode($storedNow) ?: ''
);
glb_check(
    is_array($storedNow) === true && ($storedNow['pushLivestream'] ?? 'missing') === false && ($storedNow['pushServiceReminders'] ?? 'missing') === true && count($storedNow) >= 15,
    'and every other choice the member had stored is still there: "We\'re live now" is still OFF (it used to be thrown away, and a missing choice counts as ON)',
    json_encode($storedNow) ?: ''
);
$pressed = glb_press($jar['admin'], GLB_EVENT);
glb_check(($pressed['answer']['status'] ?? 0) === 302 && glb_addressed(1) === [GLB_S_ON, GLB_S_ON2], 'so a press now still does NOT address that member\'s device', json_encode(glb_subs()) ?: '');

echo "\nG. The button switches itself off when pressed\n";
$consolePage = glb_request($jar['admin'], 'GET', glb_consolePath(GLB_EVENT));
$channelsPage = glb_request($jar['admin'], 'GET', '/admin/livestream/channels');
foreach (['the Host Console page' => $consolePage, 'the livestream channels page' => $channelsPage] as $words => $pageAnswer) {
    glb_check(str_contains($pageAnswer['body'], 'id="golive-form"') === true && preg_match('#getElementById\(\'golive-form\'\)(?:(?!</script>).)*?addEventListener\(\'submit\'#s', $pageAnswer['body']) === 1, $words . ': the "We\'re live" form has the page\'s own small script that runs when it is sent', 'HTTP ' . $pageAnswer['status']);
    file_put_contents($glb['child'] . '/page.html', $pageAnswer['body']);
    $ran = glb_node(GLB_NODE_SUBMIT_PROGRAM, [$glb['child'] . '/page.html']);
    if ($ran === null) {
        $glb['nodeSkipped'] = true;
        echo "SKIPPED — Node.js was not found, so the script on {$words} was NOT run (only read, in the line above).\n";
        continue;
    }
    glb_check(($ran['wired'] ?? false) === true && ($ran['before'] ?? true) === false && ($ran['after'] ?? false) === true, $words . ': sending the form switches the button off (run in Node.js with stand-ins for the form and the button)', json_encode($ran) ?: '');
}

echo "\nH. A second organisation, with an administrator of its own\n";
foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'session'] as $key => $value) {
    $statement = $db->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL');
    $statement->bind_param('ss', $value, $key);
    $statement->execute();
}
$jar['admin1'] = glb_signIn('admin', 'admin1');
$jar['admin2'] = glb_signIn('adminb', 'admin2');
glb_reset();
$theirs = glb_request($jar['admin2'], 'GET', glb_consolePath(GLB_EVENT));
glb_check($theirs['status'] === 404 && glb_readForm($theirs['body'])['found'] === false, 'the second organisation\'s administrator cannot open the first organisation\'s Host Console: "Event not found" (404), no button', 'HTTP ' . $theirs['status']);
$ownPage = glb_request($jar['admin2'], 'GET', glb_consolePath(GLB_EVENT_B));
glb_check($ownPage['status'] === 200 && glb_readForm($ownPage['body'])['found'] === true, 'they can open their own organisation\'s Host Console');
// Pressing, for the first organisation's event number: the handler runs for THEIR organisation.
$token  = glb_metaToken($jar['admin2'], '/');
$answer = glb_request($jar['admin2'], 'POST', '/admin/livestream/channels', ['csrf_token' => $token] + $fieldsOf(GLB_EVENT));
glb_check(
    $answer['status'] === 302 && glb_anyAddressed() === 0 && glb_hits(GLB_ORG_B) === 0 && glb_hits(1) === 0,
    'posting the button\'s fields by hand, with the FIRST organisation\'s event number, is refused: the event is not in THEIR organisation, so nothing is sent to anyone and nothing is counted against either organisation (before, it sent to their own devices with a link to the other organisation\'s event)',
    json_encode(glb_subs()) ?: ''
);
glb_reset();
$own = glb_press($jar['admin2'], GLB_EVENT_B);
glb_check(($own['answer']['status'] ?? 0) === 302 && glb_addressed(1) === [GLB_S_B, GLB_S_B2], 'their own button, on their own console, addresses the same two of THEIR devices', json_encode(glb_subs()) ?: '');
$titlesB = array_values(array_unique(array_map(static fn (array $p): string => (string) ($p['payload']['title'] ?? ''), glb_pushes())));
glb_check($titlesB === [GLB_NAME_B . ' is live now'], 'and those devices are told "' . GLB_NAME_B . ' is live now": the SECOND organisation\'s own name, not the first\'s and not the installation\'s', json_encode($titlesB) ?: '');
glb_reset();
$ours = glb_press($jar['admin1'], GLB_EVENT);
glb_check(($ours['answer']['status'] ?? 0) === 302 && glb_addressed(1) === [GLB_S_ON, GLB_S_ON2], 'and the first organisation\'s administrator addresses only the FIRST organisation\'s two devices, none of the second\'s', json_encode(glb_subs()) ?: '');
$titlesA = array_values(array_unique(array_map(static fn (array $p): string => (string) ($p['payload']['title'] ?? ''), glb_pushes())));
glb_check($titlesA === [GLB_NAME_A . ' is live now'], 'and they are told "' . GLB_NAME_A . ' is live now": the FIRST organisation\'s own name, each organisation its own', json_encode($titlesA) ?: '');
$theirEvent = glb_request($jar['admin1'], 'GET', glb_consolePath(GLB_EVENT_B));
glb_check($theirEvent['status'] === 404, 'and cannot open the second organisation\'s Host Console either (404)', 'HTTP ' . $theirEvent['status']);

echo "\nI. Six presses at the same moment\n";
// A second server with eight workers, so that requests really overlap, and every send made to take a quarter of a second
// (the recorder in the scratch copy of the sender pauses when GLB_SLOW_MS is set) so that the old way (check the limit,
// send, THEN count) would let every press through. Six administrator sessions, each with its own form token.
glb_reset();
$port2 = $glb['port'] + 1;
if (glb_portAnswers($port2) === true) {
    glb_refuse('Something already answers on port ' . $port2 . ', which the test needs for the server with workers.');
}
$environment2 = $environment;
$environment2['PHP_CLI_SERVER_WORKERS'] = '8';
$environment2['GLB_SLOW_MS']            = '250';
$glb['proc2'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . '/sessions', '-d', 'error_log=' . $child . '/php-errors2.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $port2, '-t', $child . '/web/public_html', $child . '/router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . '/server-output2.log', 'a'], 2 => ['file', $child . '/server-output2.log', 'a']],
    $pipes2,
    $child,
    $environment2
);
for ($waited = 0; $waited < 150 && glb_portAnswers($port2) === false; $waited++) {
    usleep(100000);
}
glb_check(is_resource($glb['proc2']) === true && glb_portAnswers($port2) === true, 'a second server with eight workers is running (so that six requests can really overlap)');
$six = [];
for ($n = 0; $n < 6; $n++) {
    $sixJar = glb_signIn('admin', 'admin-six-' . $n);
    $sixForm = glb_readForm(glb_request($sixJar, 'GET', glb_consolePath(GLB_EVENT), null, $port2)['body']);
    $six[] = ['jar' => $sixJar, 'path' => $sixForm['action'], 'fields' => $sixForm['fields'], 'port' => $port2];
}
glb_check(count(array_filter($six, static fn (array $one): bool => $one['path'] === '/admin/livestream/channels')) === 6, 'six administrator sessions each have the button and a form token of their own');
$answers = glb_requestMany($six);
glb_check(array_column($answers, 'status') === [302, 302, 302, 302, 302, 302], 'all six presses are answered at the same moment, each landing back on the console', json_encode($answers) ?: '');
glb_check(
    glb_hits(1) === 3 && glb_addressed(3) === [GLB_S_ON, GLB_S_ON2] && count(glb_pushes()) === 6,
    'at most THREE of the six sent: three presses counted, each of the two devices addressed three times (six sends in all, not twelve), the rest were refused by the limit',
    'presses counted ' . glb_hits(1) . '; ' . json_encode(glb_subs()) . '; sends ' . count(glb_pushes())
);
glb_stopWorkers();

echo "\nJ. An event's coordinator may send it, for that event only\n";
glb_reset();
$mark = (int) $db->query('SELECT COALESCE(MAX(logID), 0) FROM tblActivityLogs')->fetch_row()[0];
$coordPage = glb_request($jar['coord'], 'GET', glb_consolePath(GLB_EVENT));
$coordForm = glb_readForm($coordPage['body']);
glb_check($coordPage['status'] === 200 && $coordForm['found'] === true && $coordForm['disabled'] === false && $coordForm['action'] === '/admin/livestream/channels', 'a coordinator of the event, who is not an administrator, is shown the Host Console page WITH the button, switched on, posting to the right address', 'HTTP ' . $coordPage['status']);
$sentByCoord = glb_press($jar['coord'], GLB_EVENT);
glb_check(
    ($sentByCoord['answer']['status'] ?? 0) === 302 && ($sentByCoord['answer']['location'] ?? '') === glb_consolePath(GLB_EVENT) && glb_addressed(1) === [GLB_S_ON, GLB_S_ON2] && glb_hits(1) === 1,
    'and pressing it SENDS: one push to the same two devices, counted as one press against the organisation, and the coordinator lands back on the console',
    'HTTP ' . ($sentByCoord['answer']['status'] ?? '-') . '; ' . json_encode(glb_subs())
);
$noteRows = $db->query("SELECT userID, activityDescription FROM tblActivityLogs WHERE logID > " . $mark . " AND activityType = 'GoLivePushPressed'")->fetch_all(MYSQLI_ASSOC);
glb_check(
    count($noteRows) === 1 && (int) $noteRows[0]['userID'] === GLB_COORD && $noteRows[0]['activityDescription'] === 'userID=' . GLB_COORD . ', eventID=' . GLB_EVENT,
    'one line was written to the activity log saying WHO pressed it and for which event',
    json_encode($noteRows) ?: ''
);
$noteText = (string) ($noteRows[0]['activityDescription'] ?? '') . ' ' . (string) ($db->query("SELECT activityType FROM tblActivityLogs WHERE logID > " . $mark . " AND activityType = 'GoLivePushPressed'")->fetch_row()[0] ?? '');
glb_check(
    str_contains($noteText, 'is live now') === false && str_contains($noteText, 'selftest.invalid') === false && str_contains($noteText, 'GLB channel') === false && str_contains($noteText, '/live') === false && preg_match('/\d{6}.*\d{6}.*\d{6}/', $noteText) !== 1,
    'and the line holds nothing of the push: no title, no words, no link, no device',
    $noteText
);
$adminLines = (int) $db->query("SELECT COUNT(*) FROM tblActivityLogs WHERE logID > " . $mark . " AND activityType = 'GoLivePushPressed'")->fetch_row()[0];
glb_reset();
glb_press($jar['admin'], GLB_EVENT);
glb_check((int) $db->query("SELECT COUNT(*) FROM tblActivityLogs WHERE logID > " . $mark . " AND activityType = 'GoLivePushPressed'")->fetch_row()[0] === $adminLines + 1 && glb_addressed(1) === [GLB_S_ON, GLB_S_ON2], 'an administrator still sends it for the event, and that is on the log too', json_encode(glb_subs()) ?: '');

glb_reset();
$fieldsFor = static fn (int $event): array => ['action' => 'notify_live', 'eventID' => (string) $event, 'returnTo' => glb_consolePath($event)];
$post = static function (string $who, array $fields) use ($jar, $form): array {
    return glb_request($jar[$who], 'POST', $form['action'], ['csrf_token' => glb_metaToken($jar[$who], '/')] + $fields);
};
$logBefore = (int) $db->query('SELECT COUNT(*) FROM tblActivityLogs WHERE activityType = \'GoLivePushPressed\'')->fetch_row()[0];
$refusals = [
    'a coordinator of ANOTHER event of the same organisation, naming this one' => ['coord2', $fieldsFor(GLB_EVENT)],
    'a coordinator of an event of ANOTHER organisation, naming that event in this organisation' => ['coordx', $fieldsFor(GLB_EVENT_B)],
    'the same person naming this organisation\'s event, which they do not coordinate' => ['coordx', $fieldsFor(GLB_EVENT)],
    'an ordinary member, naming the event' => ['plain', $fieldsFor(GLB_EVENT)],
    'a coordinator with a made-up event number' => ['coord', $fieldsFor(999999999)],
    'a coordinator with a negative event number' => ['coord', $fieldsFor(-5)],
    'a coordinator with a word for the event number' => ['coord', ['action' => 'notify_live', 'eventID' => 'abc', 'returnTo' => glb_consolePath(GLB_EVENT)]],
    'a coordinator with NO event number' => ['coord', ['action' => 'notify_live', 'returnTo' => glb_consolePath(GLB_EVENT)]],
    'a coordinator whose place on the event was WITHDRAWN' => ['coordw', $fieldsFor(GLB_EVENT)],
    'the coordinator of a DELETED event, naming it' => ['coordd', $fieldsFor(GLB_EVENT_DEL)],
    'the coordinator of an IMPORTED event, naming it' => ['coordd', $fieldsFor(GLB_EVENT_IMP)],
];
foreach ($refusals as $words => [$who, $fields]) {
    $answer = $post($who, $fields);
    glb_check($answer['status'] === 403 && glb_anyAddressed() === 0 && glb_hits(1) === 0, $words . ': refused (403), nothing sent, nothing counted', 'HTTP ' . $answer['status'] . '; ' . json_encode(glb_subs()));
}
glb_check((int) $db->query('SELECT COUNT(*) FROM tblActivityLogs WHERE activityType = \'GoLivePushPressed\'')->fetch_row()[0] === $logBefore, 'and none of those refusals wrote a "pressed" line to the log');
$badAdmin = glb_press($jar['admin'], GLB_EVENT, ['eventID' => '999999999']);
glb_check(
    ($badAdmin['answer']['status'] ?? 0) === 302 && glb_anyAddressed() === 0 && glb_hits(1) === 0
        && str_starts_with(glb_flash(glb_request($jar['admin'], 'GET', glb_consolePath(GLB_EVENT))['body']), 'That event was not found in this organisation, so nothing was sent'),
    'an ADMINISTRATOR naming a made-up event is told so in words, and nothing is sent or counted',
    'HTTP ' . ($badAdmin['answer']['status'] ?? '-') . '; ' . json_encode(glb_subs())
);
$otherEvent = glb_press($jar['admin'], GLB_EVENT, ['eventID' => (string) GLB_EVENT_B]);
glb_flash(glb_request($jar['admin'], 'GET', glb_consolePath(GLB_EVENT))['body']);
glb_check(($otherEvent['answer']['status'] ?? 0) === 302 && glb_anyAddressed() === 0 && glb_hits(1) === 0, 'and so is an administrator naming ANOTHER ORGANISATION\'s event: nothing sent or counted', json_encode(glb_subs()) ?: '');
$noEvent = glb_press($jar['admin'], GLB_EVENT, ['eventID' => null]);
glb_check(($noEvent['answer']['status'] ?? 0) === 302 && glb_addressed(1) === [GLB_S_ON, GLB_S_ON2] && glb_hits(1) === 1, 'an administrator with NO event number still sends (the livestream channels page\'s own button posts none): the link is then the live page without an event', json_encode(glb_subs()) ?: '');
glb_check((glb_pushes()[0]['payload']['url'] ?? '') === '/live', 'and its link is /live, with no event number', json_encode(glb_pushes()[0] ?? []) ?: '');

// Console and handler agree, for each kind of person: the button is there exactly when a press is accepted.
glb_reset();
$agree = [
    'a coordinator of the event' => ['coord', GLB_EVENT, true],
    'a coordinator of another event, looking at this one' => ['coord2', GLB_EVENT, false],
    'a coordinator of an event of another organisation, looking at this one' => ['coordx', GLB_EVENT, false],
    'an ordinary member' => ['plain', GLB_EVENT, false],
    'a coordinator whose place was withdrawn' => ['coordw', GLB_EVENT, false],
    'the coordinator of a deleted event, looking at it' => ['coordd', GLB_EVENT_DEL, false],
    'the coordinator of an imported event, looking at it' => ['coordd', GLB_EVENT_IMP, false],
];
foreach ($agree as $words => [$who, $event, $should]) {
    $page   = glb_request($jar[$who], 'GET', glb_consolePath($event));
    $drawn  = glb_readForm($page['body'])['found'];
    glb_check($drawn === $should, $words . ': the console ' . ($should ? 'draws' : 'does NOT draw') . ' the button (and the handler ' . ($should ? 'accepts' : 'refuses') . ' them, above)', 'HTTP ' . $page['status'] . ', drawn ' . json_encode($drawn));
}
// A coordinator is held to the same form token as everybody (the independent check showed the test passed when the token was
// asked of administrators only): no token, an empty one and a made-up one send nothing.
glb_reset();
$tokenless = ['no form token at all' => null, 'an empty form token' => '', 'a made-up form token' => str_repeat('0', 64)];
foreach ($tokenless as $words => $token) {
    $try = glb_press($jar['coord'], GLB_EVENT, ['csrf_token' => $token]);
    glb_check(($try['answer']['status'] ?? 0) === 400 && glb_anyAddressed() === 0 && glb_hits(1) === 0, 'a COORDINATOR pressing with ' . $words . ' is refused (400) and nothing is sent or counted', 'HTTP ' . ($try['answer']['status'] ?? '-'));
}

// The limit of three presses in fifteen minutes holds for a coordinator as for an administrator, whoever presses, and a press
// the limit refuses is NOT written to the log as pressed. A coordinator and an administrator press in turn, five times.
glb_reset();
$logMark = (int) $db->query('SELECT COALESCE(MAX(logID), 0) FROM tblActivityLogs')->fetch_row()[0];
$turns   = [['coord', 'a coordinator'], ['admin', 'an administrator'], ['coord', 'a coordinator'], ['admin', 'an administrator'], ['coord', 'a coordinator']];
$said    = [];
foreach ($turns as $n => [$who]) {
    glb_press($jar[$who], GLB_EVENT);
    $said[$n] = glb_flash(glb_request($jar[$who], 'GET', glb_consolePath(GLB_EVENT))['body']);
}
glb_check(
    glb_addressed(3) === [GLB_S_ON, GLB_S_ON2] && glb_hits(1) === 3,
    'a coordinator and an administrator pressing in turn, five presses: THREE are sent (each of the two devices reached three times) and three are counted, the fourth and fifth are not sent',
    json_encode(glb_subs()) . ' hits ' . glb_hits(1)
);
glb_check(
    str_starts_with($said[3], 'Too many "we\'re live" pushes') === true && str_starts_with($said[4], 'Too many "we\'re live" pushes') === true && str_starts_with($said[0], 'Push sent') === true,
    'and the fourth press (an administrator\'s) and the fifth (a coordinator\'s) are both told the limit was reached',
    json_encode($said)
);
$lines = $db->query("SELECT userID FROM tblActivityLogs WHERE logID > " . $logMark . " AND activityType = 'GoLivePushPressed' ORDER BY logID")->fetch_all(MYSQLI_NUM);
glb_check(
    count($lines) === 3 && array_map(static fn (array $row): int => (int) $row[0], $lines) === [GLB_COORD, GLB_ADMIN, GLB_COORD],
    'and the log holds exactly three "pressed" lines for those five presses, the three that were sent (a coordinator, an administrator, a coordinator): the two the limit refused were not logged as pressed',
    json_encode($lines)
);
glb_reset();

$own2 = glb_request($jar['coord2'], 'GET', glb_consolePath(GLB_EVENT_2));
glb_check($own2['status'] === 200 && glb_readForm($own2['body'])['found'] === true, 'a coordinator of the SECOND event is shown the button on THEIR event\'s console', 'HTTP ' . $own2['status']);

// Everything else on that page stays for administrators: opening it, and every other action, for a coordinator who really can send.
$channelsBefore = (int) $db->query('SELECT COUNT(*) FROM tblLivestreamChannel WHERE channelID IN (' . GLB_CHANNEL . ', ' . GLB_CHANNEL_B . ')')->fetch_row()[0];
$schedulesBefore = (string) $db->query('SELECT GROUP_CONCAT(CONCAT(scheduleID, ":", isActive) ORDER BY scheduleID) FROM tblLivestreamSchedule WHERE channelID = ' . GLB_CHANNEL)->fetch_row()[0];
$scheduleOne = (int) $db->query('SELECT MIN(scheduleID) FROM tblLivestreamSchedule WHERE channelID = ' . GLB_CHANNEL)->fetch_row()[0];
$open = glb_request($jar['coord'], 'GET', '/admin/livestream/channels');
glb_check($open['status'] === 403 && str_contains($open['body'], 'Add a channel') === false, 'a coordinator cannot OPEN the livestream channels page (403)', 'HTTP ' . $open['status']);
$others = [
    'add a channel' => ['action' => 'add_channel', 'name' => 'GLB planted channel', 'platform' => 'custom'],
    'delete a channel' => ['action' => 'delete_channel', 'channelID' => (string) GLB_CHANNEL],
    'add a schedule slot' => ['action' => 'add_schedule', 'channelID' => (string) GLB_CHANNEL, 'dayOfWeek' => '1', 'startTime' => '01:00', 'endTime' => '02:00', 'timezone' => 'UTC'],
    'switch a schedule slot on or off' => ['action' => 'toggle_schedule', 'scheduleID' => (string) $scheduleOne],
    'delete a schedule slot' => ['action' => 'delete_schedule', 'scheduleID' => (string) $scheduleOne],
];
foreach ($others as $words => $fields) {
    $answer = $post('coord', $fields + ['eventID' => (string) GLB_EVENT]);
    glb_check($answer['status'] === 403, 'a coordinator cannot ' . $words . ' (403), even with their own event number alongside', 'HTTP ' . $answer['status']);
}
$channelsAfter = (int) $db->query('SELECT COUNT(*) FROM tblLivestreamChannel WHERE channelID IN (' . GLB_CHANNEL . ', ' . GLB_CHANNEL_B . ')')->fetch_row()[0];
$planted = (int) $db->query("SELECT COUNT(*) FROM tblLivestreamChannel WHERE name = 'GLB planted channel'")->fetch_row()[0];
$schedulesAfter = (string) $db->query('SELECT GROUP_CONCAT(CONCAT(scheduleID, ":", isActive) ORDER BY scheduleID) FROM tblLivestreamSchedule WHERE channelID = ' . GLB_CHANNEL)->fetch_row()[0];
glb_check($channelsBefore === $channelsAfter && $planted === 0 && $schedulesBefore === $schedulesAfter, 'and none of those changed anything: the channels and every schedule slot are exactly as they were', $channelsBefore . '/' . $channelsAfter . '/' . $planted);
glb_check(glb_anyAddressed() === 0 && glb_hits(1) === 0, 'and nothing was sent or counted by them');
$db->query("DELETE FROM tblLivestreamChannel WHERE name = 'GLB planted channel'");

// Odd shapes. A POSTED VALUE THAT IS A LIST (`csrf_token[]=x`, `action[]=x`, `returnTo[]=x`) is refused the ordinary way and
// raises no PHP warning and no error: it used to give "Server Error" (500) for a form token and a PHP warning for an action, and
// since this page started letting people who are not administrators past its first line, any signed-in person could do either.
glb_reset();
$errorLog = static function (): string {
    global $glb;
    return (string) @file_get_contents($glb['child'] . '/php-errors.log');
};
$logBefore = strlen($errorLog());
$plainToken = glb_metaToken($jar['plain'], '/');
$listToken = glb_request($jar['plain'], 'POST', $form['action'], ['csrf_token' => ['x'], 'action' => 'notify_live', 'eventID' => (string) GLB_EVENT, 'returnTo' => glb_consolePath(GLB_EVENT)]);
glb_check(
    $listToken['status'] === 400 && str_contains($listToken['body'], 'This page was out of date') === true && glb_anyAddressed() === 0 && glb_hits(1) === 0,
    'a form token sent as a LIST is refused like a wrong token (400, the "out of date" page), not answered with a server error (500); nothing is sent',
    'HTTP ' . $listToken['status']
);
$listAction = glb_request($jar['plain'], 'POST', $form['action'], ['csrf_token' => $plainToken, 'action' => ['notify_live'], 'eventID' => (string) GLB_EVENT]);
glb_check($listAction['status'] === 403 && glb_anyAddressed() === 0, 'an action sent as a LIST, by an ordinary member, is refused the ordinary way (403): an unknown action', 'HTTP ' . $listAction['status']);
$adminToken  = glb_metaToken($jar['admin'], '/');
$listAdmin   = glb_request($jar['admin'], 'POST', $form['action'], ['csrf_token' => $adminToken, 'action' => ['notify_live'], 'eventID' => (string) GLB_EVENT]);
glb_check($listAdmin['status'] === 302 && $listAdmin['location'] === '/admin/livestream' && glb_anyAddressed() === 0 && glb_hits(1) === 0, 'the same sent by an administrator is an unknown action too: quietly back to the dashboard, nothing sent or counted', 'HTTP ' . $listAdmin['status'] . ' to "' . $listAdmin['location'] . '"');
$listBack    = glb_request($jar['admin'], 'POST', $form['action'], ['csrf_token' => glb_metaToken($jar['admin'], '/'), 'action' => 'notify_live', 'eventID' => (string) GLB_EVENT, 'returnTo' => [glb_consolePath(GLB_EVENT)]]);
glb_check($listBack['status'] === 302 && $listBack['location'] === '/admin/livestream', 'and a come-back address sent as a list is not an address: the person lands on the default page', 'HTTP ' . $listBack['status'] . ' to "' . $listBack['location'] . '"');
$newErrors = substr($errorLog(), $logBefore);
glb_check(
    str_contains($newErrors, 'Array to string') === false && str_contains($newErrors, 'TypeError') === false && str_contains($newErrors, 'Uncaught') === false,
    'and none of those four wrote a PHP warning or error to the server\'s error log',
    mb_substr($newErrors, 0, 300)
);
glb_reset();

echo "\nK. Only a signed-in device can ask for notices\n";
glb_reset();
$devices = static function (string $endpoint) use ($db): int {
    $statement = $db->prepare('SELECT COUNT(*) FROM tblPushSubscriptions WHERE endpoint = ?');
    $statement->bind_param('s', $endpoint);
    $statement->execute();
    return (int) $statement->get_result()->fetch_row()[0];
};
// A device made up for this part. The address is https and on the allowed list ONLY so that the subscribe address accepts it
// (the list is switched to "selftest.invalid" for the run); it can still never be sent anything: the host never resolves, the
// sender only allows https to a host on that list, the made-up signing key cannot sign, the keys below are not a real key
// pair, and the row is deleted at the end of this part, before anything else could address it.
// The body also carries another person's account number under every name a careless handler might read (the administrator's):
// the device must be stored against the SIGNED-IN person's account, never against one named in the request.
$subscribeBody = static fn (): string => json_encode([
    'userID' => GLB_ADMIN, 'user_id' => GLB_ADMIN, 'userId' => GLB_ADMIN,
    'endpoint' => GLB_SUBSCRIBE_ENDPOINT,
    'keys' => ['p256dh' => rtrim(strtr(base64_encode("\x04" . random_bytes(64)), '+/', '-_'), '='), 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')],
    'channels' => ['livestream'],
], JSON_THROW_ON_ERROR) ?: '';
$subscribe = static function (string $jarFile, ?string $csrf) use ($subscribeBody): array {
    global $glb;
    $handle = curl_init('http://127.0.0.1:' . $glb['port'] . '/api/push/subscribe');
    $headers = ['Expect:', 'Content-Type: application/json'];
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $subscribeBody()];
    if ($jarFile !== '') {
        $options[CURLOPT_COOKIEJAR]  = $jarFile;
        $options[CURLOPT_COOKIEFILE] = $jarFile;
    }
    curl_setopt_array($handle, $options);
    $raw  = (string) curl_exec($handle);
    $size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $json = json_decode(substr($raw, $size), true);
    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'json' => is_array($json) ? $json : [], 'mine' => preg_match('/^X-Go-Live-Selftest:\s*' . $glb['token'] . '\s*$/mi', substr($raw, 0, $size)) === 1];
};
$nobody = $subscribe('', null);
glb_check($nobody['status'] === 401 && $nobody['mine'] === true && str_contains((string) ($nobody['json']['error'] ?? ''), 'Please sign in') && $devices(GLB_SUBSCRIBE_ENDPOINT) === 0, 'a request from nobody (no session at all) is refused (401) in plain words, and stores nothing', 'HTTP ' . $nobody['status'] . ' ' . json_encode($nobody['json']));
$visitorJar = $glb['child'] . '/jar-visitor.txt';
$visitorPage = glb_request($visitorJar, 'GET', '/login');
$visitorToken = glb_formToken($visitorPage['body']);
$visitor = $subscribe($visitorJar, $visitorToken);
glb_check($visitor['status'] === 401 && str_contains((string) ($visitor['json']['error'] ?? ''), 'Please sign in') && $devices(GLB_SUBSCRIBE_ENDPOINT) === 0, 'a visitor with a session and a real form token (the sign-in page\'s) is refused the same way, and nothing is stored', 'HTTP ' . $visitor['status'] . ' ' . json_encode($visitor['json']));
$memberToken = glb_metaToken($jar['plain'], '/');
$signedIn = $subscribe($jar['plain'], $memberToken);
$stored = $db->query("SELECT userID, siteID, channels, isActive FROM tblPushSubscriptions WHERE endpoint = '" . GLB_SUBSCRIBE_ENDPOINT . "'")->fetch_assoc();
glb_check(
    $signedIn['status'] === 200 && ($signedIn['json']['ok'] ?? false) === true && $stored !== null && (int) $stored['userID'] === GLB_PLAIN && (int) $stored['siteID'] === 1 && $stored['channels'] === '["livestream"]' && (int) $stored['isActive'] === 1,
    'a SIGNED-IN member subscribing works as before: the device is stored for THAT member (even though the request names the administrator\'s account number), in this organisation, on the livestream channel',
    'HTTP ' . $signedIn['status'] . ' ' . json_encode($signedIn['json']) . ' ' . json_encode($stored)
);
// The unsubscribe address, for a person who is NOT signed in: it is keyed by the device's own address (a long, unguessable
// address the push service made up), so a visitor's session and form token are enough to switch a device off if they hold
// that address. This is unchanged and deliberate: it lets a device with no account switch itself off.
$unsubHandle = curl_init('http://127.0.0.1:' . $glb['port'] . '/api/push/unsubscribe');
curl_setopt_array($unsubHandle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
    CURLOPT_HTTPHEADER => ['Expect:', 'Content-Type: application/json', 'X-CSRF-Token: ' . glb_formToken(glb_request($visitorJar, 'GET', '/login')['body'])],
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['endpoint' => GLB_SUBSCRIBE_ENDPOINT]), CURLOPT_COOKIEJAR => $visitorJar, CURLOPT_COOKIEFILE => $visitorJar]);
$unsubAnswer = json_decode((string) curl_exec($unsubHandle), true);
$activeNow   = (int) $db->query("SELECT isActive FROM tblPushSubscriptions WHERE endpoint = '" . GLB_SUBSCRIBE_ENDPOINT . "'")->fetch_row()[0];
glb_check(is_array($unsubAnswer) && ($unsubAnswer['ok'] ?? false) === true && $activeNow === 0, 'the UNSUBSCRIBE address, unchanged, still works for a person who is not signed in, because they hold the device\'s own address: the device is switched off (not deleted)', json_encode($unsubAnswer) . ' active=' . $activeNow);
$db->query("DELETE FROM tblPushSubscriptions WHERE endpoint = '" . GLB_SUBSCRIBE_ENDPOINT . "'");
$before = glb_subs();
$press = glb_press($jar['admin'], GLB_EVENT);
$after = glb_subs();
glb_check(
    ($press['answer']['status'] ?? 0) === 302 && glb_addressed(1) === [GLB_S_ON, GLB_S_ON2] && $after[GLB_S_ANON] === [0, null, 1] && $after[GLB_S_BANON] === [0, null, 1],
    'a device with NO account is left out of the push (organisation 1\'s and the other organisation\'s) ...',
    json_encode($after) ?: ''
);
glb_check(
    count($before) === count($after) && (int) $db->query('SELECT COUNT(*) FROM tblPushSubscriptions WHERE subID IN (' . GLB_S_ANON . ', ' . GLB_S_BANON . ') AND userID IS NULL')->fetch_row()[0] === 2,
    '... and its row is still there, still with no account: nothing was deleted'
);

echo "\nL. What an administrator is told about the devices\n";
// Admin, Integrations, Push counted every stored device that was switched on, including devices with no account that are never
// sent anything now, so the number overstated who a notice would reach. It now counts only a device that WILL be sent notices
// (the same rule as the sender's query) and says in words how many switched-on devices never will be. Read from the real page,
// as the first organisation's administrator. The run's own devices are the only ones in this database (checked at the start).
// (No glb_reset() here: part M reads the outcomes the devices have recorded, over the whole run.)
$integrations = glb_request($jar['admin1'], 'GET', '/admin/integrations/push');
$numbers = [];
if (preg_match_all('#<div class="row py-2[^"]*">\s*<div class="col-6">(.*?)</div>\s*<div class="col-6 text-end">(\d+)</div>#s', $integrations['body'], $rows, PREG_SET_ORDER) > 0) {
    foreach ($rows as $row) {
        $numbers[trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($row[1]), ENT_QUOTES, 'UTF-8')))] = (int) $row[2];
    }
}
$numberFor = static function (string $startsWith) use ($numbers): ?int {
    foreach ($numbers as $label => $count) {
        if (str_starts_with($label, $startsWith) === true) {
            return $count;
        }
    }
    return null;
};
glb_check($integrations['status'] === 200 && count($numbers) >= 6, 'the administrator opens Admin, Integrations, Push and the device counts are on the page', 'HTTP ' . $integrations['status'] . ', ' . count($numbers) . ' number(s)');
glb_check(
    $numberFor('Will be sent notices — livestream channel') === 3 && $numberFor('Will be sent notices — reminders channel') === 3 && $numberFor('Will be sent notices — announcements channel') === 0,
    'the per-channel numbers count only devices that will be sent notices: livestream 3, reminders 3, announcements 0 (a device with no account, one whose account or membership is switched off, and one switched off, are not in them)',
    json_encode($numbers)
);
glb_check($numberFor('Total devices that will be sent notices') === 4, 'the total counts the four devices that will be sent anything (the member\'s two, one of a member who switched a kind of notice off, and a reminders-only one)', json_encode($numbers));
glb_check(
    $numberFor('Switched on but never sent anything') === 3 && $numberFor('Pruned') === 1,
    'and a separate number says how many switched-on devices will NEVER be sent anything (3: no account, account switched off, membership switched off); the switched-off device is the one pruned',
    json_encode($numbers)
);
glb_check(
    str_contains($integrations['body'], '(anonymous)') === false && str_contains($integrations['body'], 'to everyone who has reminders switched on') === true,
    'the "starting soon" switch is no longer labelled "(anonymous)" (no device without an account is sent anything): it says it goes to everyone who has reminders switched on',
    ''
);

echo "\nM. The end\n";
$statuses = array_values(array_unique(array_filter(array_column(glb_subs(), 1), static fn ($s): bool => $s !== null)));
glb_check($statuses === [-1], 'over the whole run, the only outcome any device ever recorded is -1 (refused before any network call): a real answer from a push service would be another number', json_encode($statuses) ?: '');
$errors = (int) $db->query('SELECT COUNT(*) FROM tblErrors WHERE errorID > ' . (int) $glb['errMark'] . " AND (requestURL LIKE '%livestream%' OR requestURL LIKE '%host-console%')")->fetch_row()[0];
glb_check($errors === 0, 'the portal recorded no error about these pages during the run', $errors . ' row(s)');
glb_cleanup();
$left = (int) glb_db()->query(
    'SELECT (SELECT COUNT(*) FROM tblPushSubscriptions WHERE subID BETWEEN ' . GLB_FIRST_SUB . ' AND ' . GLB_LAST_SUB . ') + '
    . '(SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN ' . GLB_ADMIN . ' AND ' . GLB_LAST_USER . ') + '
    . '(SELECT COUNT(*) FROM tblUserSites WHERE userID BETWEEN ' . GLB_ADMIN . ' AND ' . GLB_LAST_USER . ' OR siteID = ' . GLB_ORG_B . ') + '
    . '(SELECT COUNT(*) FROM tblLocalAccounts WHERE userID BETWEEN ' . GLB_ADMIN . ' AND ' . GLB_LAST_USER . ') + '
    . '(SELECT COUNT(*) FROM tblEvents WHERE eventID BETWEEN ' . GLB_FIRST_EVENT . ' AND ' . GLB_LAST_EVENT . ') + '
    . '(SELECT COUNT(*) FROM tblEventCoordinators WHERE eventID BETWEEN ' . GLB_FIRST_EVENT . ' AND ' . GLB_LAST_EVENT . ' OR userID BETWEEN ' . GLB_ADMIN . ' AND ' . GLB_LAST_USER . ') + '
    . '(SELECT COUNT(*) FROM tblLivestreamChannel WHERE channelID IN (' . GLB_CHANNEL . ', ' . GLB_CHANNEL_B . ')) + '
    . '(SELECT COUNT(*) FROM tblLivestreamSchedule WHERE channelID IN (' . GLB_CHANNEL . ', ' . GLB_CHANNEL_B . ')) + '
    . '(SELECT COUNT(*) FROM tblSites WHERE siteID = ' . GLB_ORG_B . ') + '
    . '(SELECT COUNT(*) FROM tblActivityLogs WHERE logID > ' . (int) $glb['logMark'] . ') + '
    . '(SELECT COUNT(*) FROM tblErrors WHERE errorID > ' . (int) $glb['errMark'] . ') + '
    . '(SELECT COUNT(*) FROM tblApiRateLimits WHERE hitID > ' . (int) $glb['hitMark'] . ')'
)->fetch_row()[0];
glb_check($left === 0, 'every row this run made has been removed: accounts, memberships, events, channels and schedules, devices, the second organisation, and every activity, error and rate-limit row written since the start', $left . ' row(s) left');
glb_check(is_dir($glb['child']) === false, 'and the scratch folder is gone');
glb_check((string) glb_db()->query('SELECT siteName FROM tblSites WHERE siteID = 1')->fetch_row()[0] === $glb['siteNameA'], 'and the first organisation\'s own name is back as it was found');
$now = [];
foreach (array_keys(GLB_SETTINGS) as $key) {
    $row = glb_db()->query("SELECT settingValue, isSensitive FROM tblSettings WHERE settingKey = '" . $key . "' AND siteID IS NULL")->fetch_row();
    $now[$key] = [(string) ($row[0] ?? ''), (int) ($row[1] ?? -1)];
}
glb_check($now === $glb['putBack'], 'and the eight settings the run switched (Web Push, its three key and contact settings, the installation\'s name, the allowed push hosts, and several organisations on and how they are told apart) are back as they were found', json_encode($now) . ' against ' . json_encode($glb['putBack']));

// The number of checks a full run makes. A different number means a part was skipped or ran twice, so it is a failure of its own.
// Without Node.js the two checks that run the page scripts do not run, so two fewer are expected (and the last line says so).
$expectedChecks = GLB_EXPECTED_CHECKS - ($glb['nodeSkipped'] === true ? 2 : 0);
if ($glb['pass'] + $glb['fail'] !== $expectedChecks) {
    $glb['fail']++;
    printf("FAIL — this run made %d checks and a full run makes %d (a part was skipped, ran twice, or checks were added without bringing GLB_EXPECTED_CHECKS up to date)\n", $glb['pass'] + $glb['fail'] - 1, $expectedChecks);
}
printf("\n%d passed, %d failed.\n", $glb['pass'], $glb['fail']);
if ($glb['fail'] > 0) {
    echo "FAILED\n";
    exit(1);
}
echo $glb['nodeSkipped'] === true ? "REACHED THE END (the page scripts were NOT run: no Node.js found)\n" : "REACHED THE END\n";
exit(0);
