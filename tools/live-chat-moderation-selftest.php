<?php
// Path: tools/live-chat-moderation-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Live chat moderation from the Admin page, on the REAL page and handler 💬🛡️ (#612)
 * -----------------------------------------------------------------------------
 * WHAT WENT WRONG
 * The moderation page (`web/_apps/admin/live/chat.php`) sent its request to
 * `api/live/chat/moderate`. No file answers that address. The handler is
 * `web/_apps/livechat/api/moderate.php`, at `api/livechat/moderate`. So every
 * press of Approve, Hide or Flag failed with "API endpoint not found", and
 * nothing on the page said so until somebody pressed a button during a real
 * livestream. The page and the handler had never been run together: each was
 * checked, if at all, on its own.
 *
 * WHAT THIS TEST DOES
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file, starts PHP's built-in web server on it behind a small router
 * that imitates `.htaccess`, signs made-up accounts in through the real sign-in
 * page, and then does what an administrator's browser does:
 *
 *   1. Opens the moderation page and READS, from what the page sent back, the
 *      address its script posts to, the form token it carries, the names of
 *      the three fields the script sends, and the buttons it draws.
 *   2. Posts exactly that (JSON, with those field names, to that address) and
 *      looks at the answer and at the stored message.
 *
 * It takes the address from the page, not from this file, on purpose: a copy of
 * the address written here would keep passing while the page went on pointing
 * somewhere else. That is the fault this test exists for.
 *
 * CHECKS
 *   A. Hide: the page offers the button, the request reaches the handler, the
 *      stored message is "hidden", it records who did it and when, and the
 *      message is no longer in the queue the page draws.
 *   B. Approve and Flag the same way. A flagged message stays in the queue,
 *      marked flagged, and is not offered a Flag button again.
 *   C. The form token. The handler replaces the token when it accepts one, so a
 *      second press with the SAME token is refused ("CSRF check failed") and
 *      changes nothing. **Since #634 the handler SENDS THE REPLACEMENT BACK**,
 *      in `meta.csrfToken`, and that is checked here — a spent token still
 *      fails, but the page is now told the new one. This paragraph used to say
 *      the handler "does not send the next one back", which was true when it
 *      was written and became untrue with the fix; the check below asserted the
 *      same thing and had to be reversed with it. The page ALSO reloads after a
 *      successful press, for a reason that has nothing to do with tokens (an
 *      approved or hidden message has left the queue); that is checked in the
 *      page's own text, since this test cannot run JavaScript, and a reloaded
 *      page is checked to carry a different token that works.
 *   D. Who may: a member sees "Forbidden" on the page. The member's own, genuine
 *      form token (read from a page they may open) is sent to the handler, which
 *      must refuse it with ITS OWN words, "Admin or stream_moderator role
 *      required", so that the refusal cannot be the form token's. (The first
 *      version took the token from the sign-in page, which for a signed-in person
 *      is a bare redirect: the token was empty, and the refusal seen was the
 *      token's, so the test passed with the role test deleted from the handler.)
 *      A made-up message number is answered "Message not found".
 *   E. The page's own script, run in Node.js with stand-ins for the browser
 *      (where Node.js is installed; otherwise this part says SKIPPED): the moment
 *      any button is pressed EVERY button is disabled; a refusal shows the
 *      handler's words and then reloads; a success reloads; a network failure
 *      shows a message and brings the buttons back without a reload.
 *   F. The trail of links at the top of the six Maintenance pages marks exactly
 *      ONE entry as the current page (the last), and "Maintenance" in it is plain
 *      words, not a link and not "current".
 *   G. A second organisation (told apart by sign-in session) with an
 *      administrator of its own: it sees only its own queue, and each
 *      organisation's administrator is refused ("Message not found") when
 *      hiding the other's message, which stays untouched. This is what fails when
 *      the organisation test is dropped from the handler's UPDATE, or from the
 *      page's query (the first version had one organisation, and passed both).
 *   H. The run reached its end and left no row of its own behind: every table
 *      the run touched is counted, the activity log and error table above the
 *      numbers noted at the start included, and the three settings it changes
 *      are as they were found.
 *
 * WHAT THIS CANNOT PROVE
 *   - That a real browser draws the buttons or reloads the page. Part E runs the
 *     page's script in Node.js, which is not a browser: nothing is drawn, a
 *     reload is only counted, and `alert` is a stand-in. Without Node.js the
 *     script is only READ in the page's text (the fields it builds, the reload
 *     after a success), and the run says so in its last line.
 *   - A screen reader. Part F counts the markup it would read.
 *   - A real livestream: the messages here are made up, in a made-up event.
 *   - Apache: the router only imitates `.htaccess`. MariaDB.
 *   - Rate limits or what a viewer sees; this is the moderator's side only.
 *
 * SAFETY
 *   - It refuses unless the database's name starts with `selftest_`. It removes
 *     its own rows (accounts 919001 to 919004, organisation 919901, events
 *     919401 and 919402, messages 919501 to 919520) at the start and at the
 *     end, plus every activity-log and error-table row made after the start.
 *     Three settings are switched for the run (live chat on; several
 *     organisations on, told apart by sign-in session) and put back.
 *   - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *     temporary folder), outside the repository, deleted at the end only if it
 *     still holds this run's marker. `web/_auth_keys` is never read or copied.
 *   - The server is started with its command as a list (no shell) and is
 *     stopped by that process number. It refuses to start if anything already
 *     answers on its port, and every answer must carry this run's header.
 *   - Do NOT run it at the same moment as another page-driving self-test on the
 *     same database.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_USER=… \
 *         SELFTEST_DB_PASS=… SELFTEST_WEB_PORT=9750 php tools/live-chat-moderation-selftest.php
 *         The database must already hold `web/_sql/full_schema.sql`. Needs PHP's
 *         curl extension and `proc_open()`.
 * Exit:   0 only if every check passed AND the run reached its end (it prints
 *         "REACHED THE END" last; "REACHED THE END (the page's script was NOT run: no
 *         Node.js found)" when part E was skipped); 1 if a check failed; 2 if it
 *         could not run.
 *
 * Why a file of its own and not a part of `tools/access-pages-selftest.php`: that
 * file runs 293 checks over two organisations and stands the members-only gate
 * down for some of them; this one needs neither, and a second package edits the
 * big file's check count. Keeping it apart keeps both easy to read.
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

const LCM_ADMIN   = 919001;   // a site administrator of organisation 1
const LCM_MEMBER  = 919002;   // an ordinary member of organisation 1
const LCM_ADMIN_B = 919003;   // a site administrator of the SECOND organisation only
const LCM_ROOT    = 919004;   // a global administrator (may open the Maintenance pages)
const LCM_EVENT   = 919401;   // organisation 1's livestream
const LCM_EVENT_B = 919402;   // the second organisation's livestream
const LCM_ORG_B   = 919901;   // the second organisation
/** Message numbers. Each check uses its own, so one cannot hide another's result. */
const LCM_HIDE = 919501, LCM_APPROVE = 919502, LCM_FLAG = 919503, LCM_STALE = 919504, LCM_REFUSED = 919505, LCM_SECOND = 919506;
/** The second organisation's two messages, and the one part E hides from the page's script. */
const LCM_B_ONE = 919507, LCM_B_TWO = 919508, LCM_NODE = 919509;
/** The range of message numbers this test owns, for the clean-up. */
const LCM_FIRST_MESSAGE = 919501, LCM_LAST_MESSAGE = 919520;
const LCM_MARK = 'LCM-CHAT-BODY';
/** The settings this run switches, with what it switches them to. They are put back as found. */
const LCM_SETTINGS = ['chat.enabled' => 'true', 'multisite.enabled' => 'false', 'multisite.detectionMode' => 'session'];

/**
 * What this run needs to put back. `putBack` maps a setting to the value found;
 * `logMark` and `errMark` are the highest activity-log and error-table numbers
 * at the start (everything above them is this run's); `nodeSkipped` is set when
 * Node.js was not found.
 */
$lcm = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'port' => 0, 'errMark' => null, 'logMark' => null, 'cleaned' => false,
    'pass' => 0, 'fail' => 0, 'putBack' => [], 'nodeSkipped' => false];

/**
 * The Node.js program of part E. It is written into the scratch folder at run time.
 * It is not a browser: nothing is drawn, a reload is only counted, `alert` only records.
 */
const LCM_NODE_PROGRAM = <<<'NODEJS'
// Written by tools/live-chat-moderation-selftest.php for ONE run (deleted with its folder). Test only.
import { readFileSync } from 'node:fs';

const [, , htmlFile, base, cookie, scenario, firstId] = process.argv;
const watchdog = setTimeout(() => { console.log(JSON.stringify({ error: 'timed out' })); process.exit(3); }, 25000);
const html = readFileSync(htmlFile, 'utf8');
// The tag is matched WITH its attributes: since #625 the page's script carries the page's nonce
// (<script nonce="...">), because a browser refuses a script written into a page without it. The
// pattern used to be the bare <script>, which would now find nothing. This test does not judge the
// nonce: tools/page-scripts-selftest.php does, for every page it reaches.
const scripts = [...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]).filter((s) => s.includes('js-mod-btn'));
if (scripts.length !== 1) { console.log(JSON.stringify({ error: 'found ' + scripts.length + ' moderation scripts, expected 1' })); process.exit(2); }

const seen = { reloads: 0, alerts: [], requests: [] };
const buttons = [...html.matchAll(/<button[^>]*class="[^"]*js-mod-btn[^"]*"[^>]*data-action="(\w+)"[^>]*data-message-id="(\d+)"/g)].map((m) => {
    const row = { style: {} };
    const attrs = { 'data-action': m[1], 'data-message-id': m[2] };
    const button = {
        disabled: false, row, handlers: [], action: m[1], id: m[2],
        getAttribute: (name) => (name in attrs ? attrs[name] : null),
        addEventListener: (type, fn) => { if (type === 'click') { button.handlers.push(fn); } },
        closest: () => row,
        // A disabled button cannot be clicked in a browser, so the stand-in ignores the click too.
        click: () => { if (!button.disabled) { button.handlers.forEach((fn) => fn()); } },
        pretendMessage: (id) => { attrs['data-message-id'] = String(id); },
    };
    return button;
});
const fakeDocument = { querySelectorAll: (selector) => (selector === '.js-mod-btn' ? buttons : []) };
const fakeWindow = { location: { reload: () => { seen.reloads += 1; } } };
const fakeAlert = (text) => { seen.alerts.push(String(text)); };
const fakeFetch = async (address, options) => {
    seen.requests.push({ address, method: options.method, body: JSON.parse(options.body) });
    const answer = await fetch(base + address, { method: options.method, headers: { ...options.headers, Cookie: cookie }, body: options.body, redirect: 'manual' });
    const text = await answer.text();
    return { ok: answer.ok, status: answer.status, json: async () => JSON.parse(text) };
};
new Function('document', 'window', 'alert', 'fetch', scripts[0])(fakeDocument, fakeWindow, fakeAlert, fakeFetch);

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const result = { buttons: buttons.length, wired: buttons.filter((b) => b.handlers.length === 1).length };
if (scenario === 'press') {
    const target = buttons.find((b) => b.action === 'hide' && b.id === firstId);
    const other = buttons.find((b) => b.id !== firstId);
    target.click();
    result.disabledAtOnce = buttons.filter((b) => b.disabled).length;
    other.click();                         // a second message, pressed before the first answer is back
    await wait(1200);
    result.requestsSent = seen.requests.length;
    result.fields = Object.keys(seen.requests[0]?.body ?? {}).sort().join(',');
    result.address = seen.requests[0]?.address ?? '';
    result.rowFaded = target.row.style.opacity === '0.4';
} else if (scenario === 'refused') {
    const target = buttons.find((b) => b.action === 'hide');
    target.pretendMessage(999999999);      // a message that is not there (the page was drawn before it went)
    target.click();
    result.disabledAtOnce = buttons.filter((b) => b.disabled).length;
    await wait(1200);
    result.requestsSent = seen.requests.length;
} else if (scenario === 'offline') {
    buttons.find((b) => b.action === 'hide').click();
    await wait(600);
    result.disabledAfter = buttons.filter((b) => b.disabled).length;
    result.requestsSent = seen.requests.length;
}
result.reloads = seen.reloads;
result.alerts = seen.alerts;
clearTimeout(watchdog);
console.log(JSON.stringify(result));
process.exit(0);
NODEJS;

function lcm_refuse(string $why): never
{
    echo 'REFUSED. ' . $why . "\nNothing was run, and nothing is proved.\n";
    exit(2);
}

/** Report one check. `$detail` is shown only on failure. */
function lcm_check(bool $passed, string $label, string $detail = ''): void
{
    global $lcm;
    if ($passed === true) {
        $lcm['pass']++;
        echo 'PASS — ' . $label . "\n";
        return;
    }
    $lcm['fail']++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

function lcm_db(): mysqli
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
            lcm_refuse('Could not connect to the test database: ' . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
    }
    return $db;
}

/** A message as stored, or null. */
function lcm_message(int $id): ?array
{
    $statement = lcm_db()->prepare('SELECT status, moderatedByID, moderatedAt FROM tblLiveChatMessages WHERE messageID = ?');
    $statement->bind_param('i', $id);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $row;
}

/** Remove every row this test makes. Safe to run twice. */
function lcm_tidyDatabase(): void
{
    global $lcm;
    $db = lcm_db();
    $db->query('SET SESSION foreign_key_checks = 0');
    $db->query('DELETE FROM tblLiveChatMessages WHERE messageID BETWEEN ' . LCM_FIRST_MESSAGE . ' AND ' . LCM_LAST_MESSAGE . ' OR eventID IN (' . LCM_EVENT . ', ' . LCM_EVENT_B . ')');
    $db->query('DELETE FROM tblEvents WHERE eventID IN (' . LCM_EVENT . ', ' . LCM_EVENT_B . ')');
    // The portal writes some activity lines with no account (the moderation handler's own line
    // names nobody), so deleting by account number left seven behind in the first version.
    // Everything written after the start is this run's, whoever it names.
    if ($lcm['logMark'] !== null) {
        $db->query('DELETE FROM tblActivityLogs WHERE logID > ' . (int) $lcm['logMark']);
    }
    $db->query('DELETE FROM tblActivityLogs WHERE userID BETWEEN ' . LCM_ADMIN . ' AND ' . LCM_ROOT);
    foreach (['tblLocalAccounts', 'tblUserSites', 'tblUsers'] as $table) {
        $db->query('DELETE FROM `' . $table . '` WHERE userID BETWEEN ' . LCM_ADMIN . ' AND ' . LCM_ROOT);
    }
    $db->query('DELETE FROM tblUserSites WHERE siteID = ' . LCM_ORG_B);
    $db->query('DELETE FROM tblSites WHERE siteID = ' . LCM_ORG_B);
    // The portal records PHP warnings in its own error table. Rows made during
    // this run are this run's; rows from before it are left alone.
    if ($lcm['errMark'] !== null) {
        $db->query('DELETE FROM tblErrors WHERE errorID > ' . (int) $lcm['errMark']);
    }
    $db->query('SET SESSION foreign_key_checks = 1');
    // The switches are put back as they were found (live chat is off in a new installation).
    foreach ($lcm['putBack'] as $key => $value) {
        $statement = $db->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL');
        $statement->bind_param('ss', $value, $key);
        $statement->execute();
    }
}

function lcm_removeFolder(string $folder): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $path => $info) {
        ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
    }
    @rmdir($folder);
}

/** Stop the server, delete the scratch folder, tidy the database. Also runs if the script dies. */
function lcm_cleanup(): void
{
    global $lcm;
    if ($lcm['cleaned'] === true) {
        return;
    }
    $lcm['cleaned'] = true;
    if (is_resource($lcm['proc']) === true) {
        proc_terminate($lcm['proc']);   // by its own process number
        proc_close($lcm['proc']);
    }
    try {
        lcm_tidyDatabase();
    } catch (Throwable $e) {
        echo 'The database could not be tidied: ' . $e->getMessage() . "\n";
    }
    // Only a folder that still holds this run's marker.
    if ($lcm['child'] !== '' && is_link($lcm['child']) === false && @file_get_contents($lcm['child'] . '/.live-chat-moderation-selftest-owner') === $lcm['token']) {
        lcm_removeFolder($lcm['child']);
    }
}

function lcm_portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);
        return true;
    }
    return false;
}

/**
 * Send one request as one signed-in person (the cookie jar says who). Redirects
 * are never followed. An answer without this run's header came from somewhere
 * else and comes back as status -1, which no check expects.
 *
 * @param array<string,string>|null $post Form fields; ignored when `$json` is set.
 * @return array{status:int, location:string, body:string}
 */
function lcm_request(string $jar, string $method, string $path, ?array $post = null, ?string $json = null): array
{
    global $lcm;
    $headers = ['Expect:'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $handle = curl_init('http://127.0.0.1:' . $lcm['port'] . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $json ?? http_build_query($post ?? []));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Live-Chat-Moderation-Selftest:\s*' . $lcm['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start'];
    }
    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $found) === 1 ? $found[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
}

/** The form token in a sign-in form. */
function lcm_formToken(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $found) === 1 ? $found[1] : '';
}

/** The form token a signed-in person's page carries in its `<meta name="csrf-token">` tag (64 characters), or ''. */
function lcm_metaToken(string $jar, string $path = '/'): string
{
    $answer = lcm_request($jar, 'GET', $path);
    return preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $answer['body'], $found) === 1 ? $found[1] : '';
}

/** A cookie jar written by curl, as the "Cookie:" header line a browser would send. */
function lcm_cookieHeader(string $jar): string
{
    $pairs = [];
    foreach (file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        // curl marks HttpOnly cookies with a "#HttpOnly_" prefix; every other "#" line is a comment.
        if (str_starts_with($line, '#HttpOnly_') === true) {
            $line = substr($line, strlen('#HttpOnly_'));
        } elseif (str_starts_with($line, '#') === true) {
            continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) >= 7) {
            $pairs[] = $parts[5] . '=' . $parts[6];
        }
    }
    return implode('; ', $pairs);
}

/**
 * Run the moderation page's OWN script in Node.js (part E). The page's HTML is
 * saved, the one script holding `js-mod-btn` is cut out of it and run with
 * stand-ins for the browser things it touches (the buttons, `alert`,
 * `window.location.reload`, `fetch`). Its `fetch` goes to the real server.
 *
 * @param string $scenario 'press' (Hide the message `$id`, then try a second button), 'refused' (press a message that does not exist),
 *                         or 'offline' (the server cannot be reached)
 * @return array<string,mixed>|null What the script did, or null when Node.js is not installed.
 */
function lcm_runScript(string $jar, string $scenario, int $id): ?array
{
    global $lcm;
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        return null;
    }
    $page = lcm_request($jar, 'GET', '/admin/live/chat');
    file_put_contents($lcm['child'] . '/page.html', $page['body']);
    $program = $lcm['child'] . '/page-script.mjs';
    file_put_contents($program, LCM_NODE_PROGRAM);
    $base = $scenario === 'offline' ? 'http://127.0.0.1:1' : 'http://127.0.0.1:' . $lcm['port'];
    $run  = proc_open(
        [$node, $program, $lcm['child'] . '/page.html', $base, lcm_cookieHeader($jar), $scenario, (string) $id],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (is_resource($run) === false) {
        return ['error' => 'Node.js could not be started'];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($run);
    $json = json_decode(trim($out), true);
    return is_array($json) === true ? $json : ['error' => 'no JSON from Node.js: ' . mb_substr($out . $err, 0, 300)];
}

/** The JSON an API answered with, or [] when it is not JSON. */
function lcm_json(array $answer): array
{
    $json = json_decode($answer['body'], true);
    return is_array($json) === true ? $json : [];
}

/** "HTTP status: message" of an answer, for the words of a failure. */
function lcm_said(array $answer): string
{
    $json = lcm_json($answer);
    return 'HTTP ' . $answer['status'] . ': ' . (string) ($json['message'] ?? mb_substr(trim(strip_tags($answer['body'])), 0, 120));
}

/**
 * Open the moderation page and read from it what a browser would use: the
 * address the script posts to, the form token it was given, the fields the
 * script sends, whether it reloads after a success, and the buttons drawn.
 *
 * @return array{status:int, body:string, url:string, token:string, fields:bool, reloads:bool, buttons:list<array{0:int,1:string}>}
 */
function lcm_readPage(string $jar): array
{
    $answer = lcm_request($jar, 'GET', '/admin/live/chat');
    $body   = $answer['body'];
    preg_match_all('/<button[^>]*data-action="(\w+)"[^>]*data-message-id="(\d+)"/s', $body, $buttons, PREG_SET_ORDER);
    return [
        'status'  => $answer['status'],
        'body'    => $body,
        // Anchored on the moderation script's own button class: the page's header
        // holds other scripts with a fetch() and a token of their own.
        'url'     => preg_match("/querySelectorAll\\('\\.js-mod-btn'\\).*?fetch\\('([^']+)'/s", $body, $found) === 1 ? $found[1] : '',
        'token'   => preg_match('/\'use strict\';\s*var csrfToken = "([0-9a-f]{64})"/', $body, $found) === 1 ? $found[1] : '',
        // The three field names the script puts in the request, in its own words.
        'fields'  => preg_match('/JSON\.stringify\(\{\s*messageID:\s*messageId,\s*action:\s*action,\s*csrf_token:\s*csrfToken\s*\}\)/', $body) === 1
            && preg_match("/'Content-Type':\s*'application\\/json'/", $body) === 1
            && preg_match("/method:\s*'POST'/", $body) === 1,
        // What the script does with a success: it must go and fetch a fresh token.
        'reloads' => preg_match('/setTimeout\(function \(\) \{ window\.location\.reload\(\); \}, 250\);/', $body) === 1,
        'buttons' => array_map(static fn (array $one): array => [(int) $one[2], $one[1]], $buttons),
    ];
}

/**
 * Press one button the way the page's script does: post
 * `{messageID, action, csrf_token}` as JSON to the address the page gave, with
 * the token the page gave (or `$tokenInstead`).
 *
 * @param array{url:string, token:string} $page What lcm_readPage() read.
 */
function lcm_post(string $jar, array $page, int $messageId, string $action, ?string $tokenInstead = null, ?string $urlInstead = null): array
{
    return lcm_request(
        $jar,
        'POST',
        $urlInstead ?? $page['url'],
        null,
        (string) json_encode(['messageID' => $messageId, 'action' => $action, 'csrf_token' => $tokenInstead ?? $page['token']])
    );
}

// =============================================================================
// 🚦 Setting up
// =============================================================================

if (str_starts_with((string) getenv('SELFTEST_DB_NAME'), 'selftest_') === false) {
    lcm_refuse("Set SELFTEST_DB_NAME to a throwaway database whose name starts with selftest_ (got '" . (string) getenv('SELFTEST_DB_NAME') . "').");
}
$lcm['port'] = (int) (getenv('SELFTEST_WEB_PORT') !== false ? getenv('SELFTEST_WEB_PORT') : 0);
if ($lcm['port'] < 1024 || $lcm['port'] > 65535) {
    lcm_refuse('Set SELFTEST_WEB_PORT to a free port for the test web server.');
}
if (lcm_portAnswers($lcm['port']) === true) {
    lcm_refuse('Something already answers on port ' . $lcm['port'] . '.');
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        lcm_refuse("This PHP has no {$needed}().");
    }
}
$db = lcm_db();
if ((int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetch_row()[0] < 200) {
    lcm_refuse('This database has fewer than 200 tables, so it was not built from web/_sql/full_schema.sql.');
}

$webSource = (string) realpath(__DIR__ . '/../web');
$parent    = (string) realpath((string) (getenv('SELFTEST_WEB_DIR') !== false && getenv('SELFTEST_WEB_DIR') !== '' ? getenv('SELFTEST_WEB_DIR') : sys_get_temp_dir()));
if ($parent === '' || str_starts_with($parent . '/', dirname($webSource) . '/') === true || str_starts_with($webSource . '/', $parent . '/') === true) {
    lcm_refuse('The scratch folder must be outside the repository (set SELFTEST_WEB_DIR).');
}

register_shutdown_function('lcm_cleanup');
$lcm['errMark'] = (int) $db->query('SELECT COALESCE(MAX(errorID), 0) FROM tblErrors')->fetch_row()[0];
$lcm['logMark'] = (int) $db->query('SELECT COALESCE(MAX(logID), 0) FROM tblActivityLogs')->fetch_row()[0];
// Settings first: read what each is, so that the clean-up can put it back whatever happens next.
foreach (array_keys(LCM_SETTINGS) as $key) {
    $statement = $db->prepare('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL');
    $statement->bind_param('s', $key);
    $statement->execute();
    $row = $statement->get_result()->fetch_row();
    if ($row === null) {
        lcm_refuse("This database has no portal-wide {$key} setting, so it was not built from web/_sql/full_schema.sql.");
    }
    $lcm['putBack'][$key] = (string) $row[0];
}
lcm_tidyDatabase();

// 💬 The live chat app is switched off in a new installation, and while it is off
//    its pages answer "App disabled". Switch it on for this run; tidy puts it back.
//    Several organisations stay OFF until part G switches them on (and tidy puts that back too).
foreach (LCM_SETTINGS as $key => $value) {
    $statement = $db->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL');
    $statement->bind_param('ss', $value, $key);
    $statement->execute();
}

// 🏢 A second organisation, used by part G. Nothing treats it as one until
//    "several organisations" is switched on, which part G does.
$statement = $db->prepare("INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES (?, 'lcm-second', 'LCM second organisation', 1)");
$orgB      = LCM_ORG_B;
$statement->bind_param('i', $orgB);
$statement->execute();

// 👥 Four accounts, signed in with a password made for this run: [name, full name, site admin, global admin, organisation].
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
$people   = [
    LCM_ADMIN   => ['admin', 'Ada Admin', 1, 0, 1],
    LCM_MEMBER  => ['member', 'Mel Member', 0, 0, 1],
    LCM_ADMIN_B => ['adminb', 'Bea Admin-B', 1, 0, LCM_ORG_B],
    LCM_ROOT    => ['root', 'Rae Root', 0, 1, 1],
];
foreach ($people as $id => [$who, $name, $isSiteAdmin, $isRoot, $org]) {
    $statement = $db->prepare('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, ?)');
    $email     = 'u' . $id . '.livechat@selftest.invalid';
    $statement->bind_param('issi', $id, $name, $email, $isRoot);
    $statement->execute();
    $statement = $db->prepare('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)');
    $statement->bind_param('iii', $id, $org, $isSiteAdmin);
    $statement->execute();
    $statement = $db->prepare('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)');
    $username  = 'selftest-livechat-' . $who;
    $statement->bind_param('iss', $id, $username, $hash);
    $statement->execute();
}

// 💬 One event of each organisation and their waiting messages. The date is worked out from today.
$day       = date('Y-m-d', strtotime('+9 days'));
$statement = $db->prepare(
    'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
    . "VALUES (?, 1, 'lcm-event', 'LCM livestream', 'LCM description', ?, ?, 'UTC', 'published', 1, 0)"
);
$start = $day . ' 10:00:00';
$end   = $day . ' 11:00:00';
$eventId = LCM_EVENT;
$statement->bind_param('iss', $eventId, $start, $end);
$statement->execute();
// ... and the second organisation's own event.
$statement = $db->prepare(
    'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
    . "VALUES (?, ?, 'lcm-event-b', 'LCM second livestream', 'LCM description', ?, ?, 'UTC', 'published', 1, 0)"
);
$eventB = LCM_EVENT_B;
$statement->bind_param('iiss', $eventB, $orgB, $start, $end);
$statement->execute();
foreach ([LCM_HIDE => 1, LCM_APPROVE => 1, LCM_FLAG => 1, LCM_STALE => 1, LCM_REFUSED => 1, LCM_SECOND => 1, LCM_NODE => 1, LCM_B_ONE => LCM_ORG_B, LCM_B_TWO => LCM_ORG_B] as $id => $org) {
    $statement = $db->prepare("INSERT INTO tblLiveChatMessages (messageID, siteID, eventID, sessionToken, displayName, body, status) VALUES (?, ?, ?, ?, 'Viewer', ?, 'pending')");
    $session   = str_repeat('ab', 16);
    $text      = LCM_MARK . ' ' . $id;
    $event     = $org === 1 ? LCM_EVENT : LCM_EVENT_B;
    $statement->bind_param('iiiss', $id, $org, $event, $session, $text);
    $statement->execute();
}

// 📁 The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . '/live-chat-moderation-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    lcm_refuse("Could not make a NEW scratch folder '{$child}'.");
}
$lcm['child'] = $child;
file_put_contents($child . '/.live-chat-moderation-selftest-owner', $lcm['token']);   // the marker FIRST
mkdir($child . '/web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSource, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $relative = substr((string) $path, strlen($webSource) + 1);
    if (in_array(explode('/', $relative)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        lcm_refuse('web/ holds a link (' . $relative . '); refusing to copy it.');
    }
    if ($info->isDir() === true) {
        mkdir($child . '/web/' . $relative, 0700);
    } else {
        copy((string) $path, $child . '/web/' . $relative);
        $copied++;
    }
}
if ($copied < 100) {
    lcm_refuse("The copy of web/ holds only {$copied} files.");
}
mkdir($child . '/web/_auth_keys', 0700);
mkdir($child . '/sessions', 0700);
file_put_contents($child . '/web/_auth_keys/auth_creds.php', "<?php\n// Written by tools/live-chat-moderation-selftest.php for one run; deleted with its folder.\nreturn " . var_export([
    'db_host' => (string) (getenv('SELFTEST_DB_HOST') !== false ? getenv('SELFTEST_DB_HOST') : '127.0.0.1'),
    'db_user' => (string) (getenv('SELFTEST_DB_USER') !== false ? getenv('SELFTEST_DB_USER') : 'root'),
    'db_pass' => (string) (getenv('SELFTEST_DB_PASS') !== false ? getenv('SELFTEST_DB_PASS') : ''),
    'db_name' => (string) getenv('SELFTEST_DB_NAME'),
    'db_port' => (int) (getenv('SELFTEST_DB_PORT') !== false ? getenv('SELFTEST_DB_PORT') : 3306),
], true) . ";\n");
file_put_contents($child . '/web/_auth_keys/enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/live-chat-moderation-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Live-Chat-Moderation-Selftest: __TOKEN__');
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
file_put_contents($child . '/router.php', str_replace('__TOKEN__', $lcm['token'], $router));

// The server: its command as a LIST (no shell in between) and ONE process, so
// nothing can outlive it.
$environment = getenv();
unset($environment['PHP_CLI_SERVER_WORKERS']);
$environment['PORTAL_ENV'] = 'prod';
$lcm['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . '/sessions', '-d', 'error_log=' . $child . '/php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $lcm['port'], '-t', $child . '/web/public_html', $child . '/router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . '/server-output.log', 'a'], 2 => ['file', $child . '/server-output.log', 'a']],
    $pipes,
    $child,
    $environment
);
if (is_resource($lcm['proc']) === false) {
    lcm_refuse('The web server could not be started.');
}
for ($waited = 0; $waited < 150 && lcm_portAnswers($lcm['port']) === false; $waited++) {
    usleep(100000);
}
if (lcm_portAnswers($lcm['port']) === false) {
    lcm_refuse('The web server never answered on port ' . $lcm['port'] . '.');
}

echo "Live chat moderation from the Admin page — self-test (#612)\n";
echo str_repeat('=', 78) . "\n";
printf("Server process %d, port %d, PHP %s, database server %s.\n", (int) proc_get_status($lcm['proc'])['pid'], $lcm['port'], PHP_VERSION, $db->server_info);

/** Sign one of the made-up accounts in through the real sign-in page, into a cookie jar of its own. */
function lcm_signIn(string $who, string $jarName): string
{
    global $lcm, $password;
    $jar   = $lcm['child'] . '/jar-' . $jarName . '.txt';
    $token = lcm_formToken(lcm_request($jar, 'GET', '/login')['body']);
    $in    = lcm_request($jar, 'POST', '/login', ['csrf_token' => $token, 'username' => 'selftest-livechat-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        lcm_refuse("Signing in as selftest-livechat-{$who} did not work (HTTP {$in['status']} {$in['location']}).");
    }
    return $jar;
}

$jar = [];
foreach (['admin', 'member', 'root'] as $who) {
    $jar[$who] = lcm_signIn($who, $who);
}

// =============================================================================
// 🧪 The checks
// =============================================================================

echo "\nA. Hide a message from the page\n";
$page = lcm_readPage($jar['admin']);
lcm_check($page['status'] === 200, 'an administrator is shown the moderation page', 'HTTP ' . $page['status'] . ' ' . mb_substr(trim(strip_tags($page['body'])), 0, 200));
lcm_check($page['fields'] === true, 'the page\'s script builds a POST of JSON with the fields messageID, action and csrf_token', 'the script text no longer has that shape');
lcm_check($page['token'] !== '', 'the page carries a form token');
lcm_check($page['url'] !== '', 'the page\'s script names an address to post to', 'no fetch() address found in the page');
lcm_check(in_array([LCM_HIDE, 'hide'], $page['buttons'], true) === true, 'the page draws a Hide button for the waiting message', json_encode($page['buttons']) ?: '');
lcm_check(str_contains($page['body'], LCM_MARK . ' ' . LCM_HIDE) === true, 'and shows the message text in the queue');
$answer = lcm_post($jar['admin'], $page, LCM_HIDE, 'hide');
$json   = lcm_json($answer);
lcm_check(
    $answer['status'] === 200 && ($json['status'] ?? '') === 'ok' && ($json['data']['status'] ?? '') === 'hidden' && ($json['data']['messageID'] ?? 0) === LCM_HIDE,
    'the request the page makes (to the address the page gives) reaches the handler and is answered "hidden"',
    'address ' . $page['url'] . ' — ' . lcm_said($answer)
);
$stored = lcm_message(LCM_HIDE);
lcm_check(($stored['status'] ?? '') === 'hidden', 'the stored message is now "hidden"', json_encode($stored) ?: 'no such row');
lcm_check((int) ($stored['moderatedByID'] ?? 0) === LCM_ADMIN && ($stored['moderatedAt'] ?? null) !== null, 'it records who hid it and when');
$later = lcm_readPage($jar['admin']);
lcm_check(str_contains($later['body'], LCM_MARK . ' ' . LCM_HIDE) === false, 'a hidden message is no longer in the queue the page draws');

echo "\nB. Approve and flag\n";
$page   = lcm_readPage($jar['admin']);
$answer = lcm_post($jar['admin'], $page, LCM_APPROVE, 'approve');
lcm_check(($stored = lcm_message(LCM_APPROVE))['status'] === 'approved' && $answer['status'] === 200, 'Approve stores "approved"', lcm_said($answer));
$page = lcm_readPage($jar['admin']);
lcm_check(str_contains($page['body'], LCM_MARK . ' ' . LCM_APPROVE) === false, 'an approved message leaves the queue');
lcm_check(in_array([LCM_FLAG, 'flag'], $page['buttons'], true) === true, 'a waiting message is offered a Flag button');
$answer = lcm_post($jar['admin'], $page, LCM_FLAG, 'flag');
lcm_check(($stored = lcm_message(LCM_FLAG))['status'] === 'flagged' && $answer['status'] === 200, 'Flag stores "flagged"', lcm_said($answer));
$page = lcm_readPage($jar['admin']);
lcm_check(str_contains($page['body'], LCM_MARK . ' ' . LCM_FLAG) === true, 'a flagged message stays in the queue');
lcm_check(in_array([LCM_FLAG, 'flag'], $page['buttons'], true) === false && in_array([LCM_FLAG, 'approve'], $page['buttons'], true) === true, 'and is offered Approve and Hide, not Flag again');

echo "\nC. The form token is used up by a press\n";
$page  = lcm_readPage($jar['admin']);
$first = lcm_post($jar['admin'], $page, LCM_STALE, 'hide');
lcm_check($first['status'] === 200 && lcm_message(LCM_STALE)['status'] === 'hidden', 'the first press with a page\'s token works', lcm_said($first));
$second = lcm_post($jar['admin'], $page, LCM_SECOND, 'hide');
lcm_check(
    $second['status'] === 403 && lcm_message(LCM_SECOND)['status'] === 'pending',
    'a second press with the SAME token is refused (403) and changes nothing: this is why the page has to reload',
    lcm_said($second)
);
// CHANGED BY #634. This check used to assert the OPPOSITE — that the answer
// carried no new token, "so the page cannot use one from it". That was true of
// the code at the time, and asserting it made the fault the expected
// behaviour: a test that passes only while the bug is present. The handler now
// hands the replacement back, so the check asserts that instead.
//
// The press above still fails with the SAME token, which is right and
// unchanged: the point is that the page is now TOLD the new one, not that a
// spent token starts working.
$firstToken = lcm_json($first)['meta']['csrfToken'] ?? null;
lcm_check(
    is_string($firstToken) === true
        && $firstToken !== ''
        && $firstToken !== $page['token'],
    'the first answer carries the REPLACEMENT token, and it differs from the one just spent (so the page can keep working without reloading)',
    'meta.csrfToken was: ' . var_export($firstToken, true)
);
lcm_check($page['reloads'] === true, 'the page\'s script reloads after a success (read in its text; this test cannot run JavaScript)');
$fresh = lcm_readPage($jar['admin']);
lcm_check($fresh['token'] !== '' && $fresh['token'] !== $page['token'], 'the reloaded page carries a DIFFERENT token');
$third = lcm_post($jar['admin'], $fresh, LCM_SECOND, 'hide');
lcm_check($third['status'] === 200 && lcm_message(LCM_SECOND)['status'] === 'hidden', 'and the press that was refused works with it', lcm_said($third));

echo "\nD. Who may, and what is refused\n";
$memberPage = lcm_request($jar['member'], 'GET', '/admin/live/chat');
lcm_check($memberPage['status'] === 403 && str_contains($memberPage['body'], LCM_MARK) === false, 'a member is refused the moderation page (403) and shown no message', 'HTTP ' . $memberPage['status']);
// The member's OWN form token, read from a page they may open. It must be a real one: with
// an empty token the handler refuses for the token and the role test is never what is tested.
$memberToken = lcm_metaToken($jar['member'], '/');
lcm_check(strlen($memberToken) === 64, 'the member has a genuine form token of their own (64 characters, read from a page they may open)', 'got ' . strlen($memberToken) . ' character(s)');
$page   = lcm_readPage($jar['admin']);
$answer = lcm_post($jar['member'], ['url' => $page['url'], 'token' => $memberToken], LCM_REFUSED, 'hide');
lcm_check(
    $answer['status'] === 403 && (lcm_json($answer)['message'] ?? '') === 'Admin or stream_moderator role required' && lcm_message(LCM_REFUSED)['status'] === 'pending',
    'a member\'s request, with their own good token, is refused (403) by the handler\'s ROLE test, in its own words, and the message is unchanged',
    lcm_said($answer)
);
$answer = lcm_post($jar['admin'], $page, 999999999, 'hide');
lcm_check($answer['status'] === 404 && (lcm_json($answer)['message'] ?? '') === 'Message not found', 'a made-up message number is answered "Message not found" (404)', lcm_said($answer));
$page   = lcm_readPage($jar['admin']);
$answer = lcm_post($jar['admin'], $page, LCM_REFUSED, 'delete');
lcm_check($answer['status'] === 400 && lcm_message(LCM_REFUSED)['status'] === 'pending', 'an action the handler does not know is refused (400) and changes nothing', lcm_said($answer));

echo "\nE. The page's own script, run in Node.js\n";
$press = lcm_runScript($jar['admin'], 'press', LCM_NODE);
if ($press === null) {
    $lcm['nodeSkipped'] = true;
    echo "SKIPPED — Node.js was not found, so the page's script was NOT run (it is only read, in parts A and C). Install Node.js to run this part.\n";
} else {
    lcm_check(isset($press['error']) === false && ($press['wired'] ?? -1) === ($press['buttons'] ?? -2) && ($press['buttons'] ?? 0) >= 4, 'the script wires every button the page draws (' . ($press['buttons'] ?? '?') . ')', json_encode($press) ?: '');
    lcm_check(($press['disabledAtOnce'] ?? -1) === ($press['buttons'] ?? -2), 'the moment one button is pressed EVERY button on the page is disabled, not only the pressed one', json_encode($press) ?: '');
    lcm_check(($press['requestsSent'] ?? -1) === 1, 'so a second message pressed before the first answer is back sends nothing (one request in all)', json_encode($press) ?: '');
    lcm_check(($press['address'] ?? '') === $page['url'] && ($press['fields'] ?? '') === 'action,csrf_token,messageID', 'the request it sends goes to the address the page gave, with the three fields', json_encode($press) ?: '');
    lcm_check(($press['reloads'] ?? -1) === 1 && ($press['alerts'] ?? null) === [] && ($press['rowFaded'] ?? false) === true, 'after a success the row fades and the page reloads once, with no box shown', json_encode($press) ?: '');
    lcm_check((lcm_message(LCM_NODE)['status'] ?? '') === 'hidden', 'and the message the script pressed is stored "hidden"');
    $refused = lcm_runScript($jar['admin'], 'refused', 0);
    lcm_check(
        ($refused['alerts'] ?? null) === ['Message not found'] && ($refused['reloads'] ?? -1) === 1 && ($refused['requestsSent'] ?? -1) === 1,
        'after a refusal ("Message not found") a box shows the handler\'s words AND the page reloads, so no button is left saying "CSRF check failed"',
        json_encode($refused) ?: ''
    );
    $offline = lcm_runScript($jar['admin'], 'offline', 0);
    lcm_check(
        count($offline['alerts'] ?? []) === 1 && str_starts_with((string) ($offline['alerts'][0] ?? ''), 'Network error') === true && ($offline['reloads'] ?? -1) === 0 && ($offline['disabledAfter'] ?? -1) === 0,
        'when no answer comes back at all, a box says so, the buttons come back, and the page does NOT reload (the token may still be good)',
        json_encode($offline) ?: ''
    );
}

echo "\nF. The trail of links at the top of the Maintenance pages\n";
foreach (['health', 'backup-check', 'backup', 'retention', 'offsite-backup', 'demo-data'] as $maintenancePage) {
    $answer = lcm_request($jar['root'], 'GET', '/admin/maintenance/' . $maintenancePage);
    $trail  = preg_match('#<nav aria-label="breadcrumb"[^>]*>(.*?)</nav>#s', $answer['body'], $nav) === 1 ? $nav[1] : '';
    preg_match_all('#<li class="breadcrumb-item([^"]*)"([^>]*)>(.*?)</li>#s', $trail, $items, PREG_SET_ORDER);
    $current = array_keys(array_filter($items, static fn (array $item): bool => str_contains($item[2], 'aria-current="page"') === true));
    $last    = count($items) - 1;
    $words   = array_map(static fn (array $item): string => trim(strip_tags($item[3])), $items);
    $plain   = array_values(array_filter($items, static fn (array $item): bool => trim(strip_tags($item[3])) === 'Maintenance'));
    lcm_check($answer['status'] === 200 && count($items) >= 4, '/admin/maintenance/' . $maintenancePage . ' opens (as a global administrator) and draws a trail', 'HTTP ' . $answer['status'] . ', ' . count($items) . ' entries');
    lcm_check($current === [$last], 'its trail marks exactly ONE entry as the current page, the last (' . ($words[$last] ?? '?') . ')', 'entries marked current: ' . json_encode($current) . ' of ' . json_encode($words));
    lcm_check(
        count($plain) === 1 && str_contains($plain[0][3], '<a') === false && str_contains($plain[0][2], 'aria-current') === false,
        '"Maintenance" in it is plain words: not a link, and not marked current',
        json_encode($plain) ?: ''
    );
}

echo "\nG. A second organisation, with an administrator of its own\n";
// Several organisations, told apart by the person's sign-in (the way a new installation is set up).
// Both administrators sign in again AFTER the switch, so each session is made the way it would be.
foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'session'] as $key => $value) {
    $statement = $db->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingKey = ? AND siteID IS NULL');
    $statement->bind_param('ss', $value, $key);
    $statement->execute();
}
$jar['admin1'] = lcm_signIn('admin', 'admin1');
$jar['admin2'] = lcm_signIn('adminb', 'admin2');
$one = lcm_readPage($jar['admin1']);
$two = lcm_readPage($jar['admin2']);
lcm_check($one['status'] === 200 && $two['status'] === 200, 'both organisations\' administrators are shown the moderation page', 'HTTP ' . $one['status'] . ' and ' . $two['status']);
lcm_check(
    str_contains($one['body'], LCM_MARK . ' ' . LCM_REFUSED) === true && str_contains($one['body'], LCM_MARK . ' ' . LCM_B_ONE) === false && str_contains($one['body'], LCM_MARK . ' ' . LCM_B_TWO) === false,
    'the first organisation\'s queue holds its own waiting message and none of the second organisation\'s',
    'this fails when the page\'s query no longer asks for its own organisation'
);
lcm_check(
    str_contains($two['body'], LCM_MARK . ' ' . LCM_B_ONE) === true && str_contains($two['body'], LCM_MARK . ' ' . LCM_B_TWO) === true && str_contains($two['body'], LCM_MARK . ' ' . LCM_REFUSED) === false,
    'the second organisation\'s queue holds its own two messages and none of the first\'s'
);
$answer = lcm_post($jar['admin2'], $two, LCM_REFUSED, 'hide');
lcm_check(
    $answer['status'] === 404 && (lcm_json($answer)['message'] ?? '') === 'Message not found' && lcm_message(LCM_REFUSED)['status'] === 'pending' && lcm_message(LCM_REFUSED)['moderatedByID'] === null,
    'the second organisation\'s administrator, pressing Hide on the FIRST organisation\'s message, gets "Message not found" and it is untouched',
    lcm_said($answer) . '; this fails when the handler no longer asks for its own organisation'
);
$one    = lcm_readPage($jar['admin1']);
$answer = lcm_post($jar['admin1'], $one, LCM_B_TWO, 'hide');
lcm_check(
    $answer['status'] === 404 && (lcm_json($answer)['message'] ?? '') === 'Message not found' && lcm_message(LCM_B_TWO)['status'] === 'pending',
    'and the first organisation\'s administrator, pressing Hide on the SECOND\'s, gets "Message not found" and it is untouched',
    lcm_said($answer)
);
$two    = lcm_readPage($jar['admin2']);
$answer = lcm_post($jar['admin2'], $two, LCM_B_ONE, 'hide');
$stored = lcm_message(LCM_B_ONE);
lcm_check(
    $answer['status'] === 200 && ($stored['status'] ?? '') === 'hidden' && (int) ($stored['moderatedByID'] ?? 0) === LCM_ADMIN_B,
    'the same second-organisation administrator CAN hide their own organisation\'s message (so the refusals above are real ones)',
    lcm_said($answer)
);

echo "\nH. The end\n";
$errors = (int) $db->query('SELECT COUNT(*) FROM tblErrors WHERE errorID > ' . (int) $lcm['errMark'] . " AND (requestURL LIKE '%livechat%' OR requestURL LIKE '%admin/live%')")->fetch_row()[0];
lcm_check($errors === 0, 'the portal recorded no error about live chat during the run', $errors . ' row(s)');
$lines = (int) $db->query('SELECT COUNT(*) FROM tblActivityLogs WHERE logID > ' . (int) $lcm['logMark'])->fetch_row()[0];
lcm_check($lines > 0, 'the run did write activity lines of its own (so the clean-up below has something to prove)', $lines . ' line(s)');
lcm_cleanup();
$left = (int) lcm_db()->query(
    'SELECT (SELECT COUNT(*) FROM tblLiveChatMessages WHERE messageID BETWEEN ' . LCM_FIRST_MESSAGE . ' AND ' . LCM_LAST_MESSAGE . ') + '
    . '(SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN ' . LCM_ADMIN . ' AND ' . LCM_ROOT . ') + '
    . '(SELECT COUNT(*) FROM tblUserSites WHERE userID BETWEEN ' . LCM_ADMIN . ' AND ' . LCM_ROOT . ' OR siteID = ' . LCM_ORG_B . ') + '
    . '(SELECT COUNT(*) FROM tblLocalAccounts WHERE userID BETWEEN ' . LCM_ADMIN . ' AND ' . LCM_ROOT . ') + '
    . '(SELECT COUNT(*) FROM tblEvents WHERE eventID IN (' . LCM_EVENT . ', ' . LCM_EVENT_B . ')) + '
    . '(SELECT COUNT(*) FROM tblSites WHERE siteID = ' . LCM_ORG_B . ') + '
    . '(SELECT COUNT(*) FROM tblActivityLogs WHERE logID > ' . (int) $lcm['logMark'] . ') + '
    . '(SELECT COUNT(*) FROM tblErrors WHERE errorID > ' . (int) $lcm['errMark'] . ')'
)->fetch_row()[0];
lcm_check($left === 0, 'every row this run made has been removed: messages, accounts, memberships, events, the second organisation, and every activity line and error row written since the start', $left . ' row(s) left');
lcm_check(is_dir($lcm['child']) === false, 'and the scratch folder is gone');
$now = [];
foreach (array_keys(LCM_SETTINGS) as $key) {
    $now[$key] = (string) (lcm_db()->query("SELECT settingValue FROM tblSettings WHERE settingKey = '" . $key . "' AND siteID IS NULL")->fetch_row()[0] ?? '');
}
lcm_check($now === $lcm['putBack'], 'and the three settings the run switched (live chat, several organisations and how they are told apart) are back as they were found', json_encode($now) . ' against ' . json_encode($lcm['putBack']));

printf("\n%d passed, %d failed.\n", $lcm['pass'], $lcm['fail']);
if ($lcm['fail'] > 0) {
    echo "FAILED\n";
    exit(1);
}
echo $lcm['nodeSkipped'] === true ? "REACHED THE END (the page's script was NOT run: no Node.js found)\n" : "REACHED THE END\n";
exit(0);
