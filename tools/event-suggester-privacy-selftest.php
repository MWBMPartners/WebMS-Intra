<?php
// Path: tools/event-suggester-privacy-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A visitor who suggests an event: where their name and address go 📅🔒 (#963)
 * -----------------------------------------------------------------------------
 * Somebody who is not signed in can suggest an event on the public calendar
 * form, and must give a name and an e-mail address to do it. D-134 says the
 * address is used for ONE thing: telling them their suggestion was approved.
 *
 * WHAT WAS WRONG, measured with this test against the code before #963
 * (SELFTEST_WEB_FROM, below):
 *   - `GET /api/events/detail` selected `submitterName` and `submitterEmail`
 *     for everybody allowed to see the event. Once the suggestion was approved
 *     and published, any signed-in member of the organisation, and any API
 *     key holding only `events:read`, could read the visitor's name and
 *     address.
 *   - `calendar/submit-save.php` wrote the address into the activity log
 *     ("submitted by anonymous <address>") — a record kept for a year by
 *     default, and for ever if an administrator chooses "never" (#932).
 *   - The same handler sent the address to every connected service listening
 *     for `calendar.event.submitted`, as `submittedBy`, and a copy of what was
 *     sent is kept on the delivery row (`tblWebhookDeliveries.payload`).
 *
 * WHAT THIS PROVES, over real HTTP against a real database:
 *   A  a visitor (no session) suggests an event through the real form; the
 *      row KEEPS the name and address (D-134 needs the address to send the
 *      approval e-mail, so this half must not be "fixed" away) — and the
 *      activity record of the suggestion holds neither, in ANY column, while
 *      it still names the event and says a visitor sent it; and the webhook
 *      body holds neither, while it still carries the event's number and
 *      says `"submittedBy": "visitor"`.
 *   B  an administrator approves it through the real moderation handler; then
 *      the detail answer holds neither the name nor the address, and has no
 *      field for either, for an ordinary MEMBER signed in, for a key holding
 *      only `events:read` (by both the old address and `/api/v1/events/{id}`),
 *      and for the administrator too — nothing reads them through the API.
 *      Every one of those answers is first required to BE the event (status
 *      200, the right number, `submissionStatus` "approved"), so an absence
 *      cannot pass because the request reached nothing.
 *   C  the person who does need them still has them: the moderation page
 *      shows the administrator the name and the address — and refuses an
 *      ordinary member, so the page is not a second way round.
 *   P  the plants, each in a CHILD copy of web/, each proved applied: the two
 *      columns put back in the detail statement (B goes red for the member
 *      and the key), the old activity description put back (A's activity
 *      check goes red), the old `submittedBy` put back (A's webhook check
 *      goes red); then everything taken out again and green.
 *
 * #968 ADDED THREE SECTIONS AND THEIR PLANTS
 *   M  the detail answer's working notes: `moderationNote` and the account
 *      numbers of whoever created, edited, suggested, moderated and last
 *      changed the status of the event, and `deletedAt`. A published,
 *      members-only event with every one filled in by hand (nothing in the
 *      portal writes `moderationNote` today) is read, at BOTH addresses, by an
 *      ordinary member, a key holding only `events:read`, a key holding
 *      `events:write` and a global administrator, and at the old address by an
 *      administrator of this organisation only. D-136 (11 November 2026):
 *      PEOPLE ONLY — the member and EVERY key, the write key included, get
 *      NONE of the seven fields and the note nowhere in the answer; the two
 *      kinds of signed-in administrator get all seven, with the note as
 *      written and every account number the right one. (Round 1 of #968 gave
 *      the write key the fields; D-136 reversed that. The v1 probes for the
 *      member, the administrator and the write key were added by rounds 1 and
 *      2 of the independent check.) Measured against f9197eb9 (the
 *      code before #968), before the v1 member probe existed: six red — the
 *      member and the read-only key at both addresses were sent all seven, the
 *      note included.
 *   R  the event page (`/calendar/event`) and the calendar download
 *      (`/calendar/export`: one event, a series, the whole calendar) now
 *      name their columns instead of reading the whole row with `e.*`. THIS
 *      IS THE GUARANTEE that the lists are complete (round 4): EVERY column of
 *      tblEvents is filled with a distinct value, the columns read from
 *      information_schema while the test runs (R0 fails if one is left empty
 *      that has something to point at), across a public (with a what3words
 *      address), a members-only, an all-day, a cancelled, a fully-shown
 *      imported, a briefly-shown imported, a what3words-only, a
 *      website-ticked and a repeating event. Each is fetched by a visitor, a
 *      member and an administrator, with the three downloads; the right words
 *      must be there, the visitor's name and address and the note must be
 *      nowhere, and the same code fetched twice must come out identical (R8).
 *      P13 then fetches everything again with `e.*` put back and requires it
 *      IDENTICAL — so a column the code reads but the list leaves out shows,
 *      whatever spelling reaches it. The first version filled only a few
 *      columns, and round 1 of the check found `locationW3W` missing from the
 *      list with P13 green; rounds 2 and 3 found spellings past section C.
 *      P26 and P27 take every listed column off one at a time and require R
 *      to see each go. What R still cannot see: a column read but not
 *      listed that no event here makes show.
 *   C  a cheap TRIPWIRE on the column lists, read from the SOURCE with PHP's
 *      tokeniser (no database, no server) — not the guarantee, which is R:
 *      every `$event['…']` the page reads, and every
 *      `$row`/`$r`/`$ev`/`$events[0]` key the download reads, must be in that
 *      page's SELECT list, every listed column must be read, the download's
 *      three lists must agree, each list must hold NOTHING but backticked
 *      columns (no `e.*`, no un-backticked column — round 2 of the check), and
 *      every other use of the row (a key built at run time, the row passed to
 *      a function or copied) is pinned WITH ITS COUNT, so a new one or a
 *      second copy fails until a person has looked at it. The spellings it
 *      knows of reaching a variable without naming it (`$$`, `${…}`,
 *      `compact()` by any form of the name, `extract()`, `get_defined_vars()`,
 *      those names as strings, `call_user_func()`, `$GLOBALS`, `eval`) trip it
 *      in both files; PHP has more, which is why R is the guarantee. Its own
 *      header says what it cannot see.
 *   P8b-P25, P14c-P14d  a gate that lets a signed-in member through at v1 alone (P8b);
 *      each of the seven field names taken off the detail handler's list, one
 *      at a time (the member and the read-only key are then sent exactly that
 *      field); the gate switched off both ways, and put back to round 1's
 *      "a write key gets them" (P11b, D-136); `e.*` put back in the page and
 *      all three download lookups — every page and file must come out
 *      identical to section R's for R's own events; a list one column too
 *      short, on each page, must show in R; and section C's own plants
 *      (P17-P22): `locationW3W` and `cancelReason` off the page's list, an
 *      unread column added, the row copied and read by a run-time key,
 *      `updatedAt` off one of the download's three lists, a download read of
 *      a column none of them has; then round 2's: `e.*`, `` `e`.* `` and an
 *      un-backticked `e.locationEmail` in each of the four lists (P23, twelve
 *      plants), `$$nm`, `${'event'}`, `compact()` and `extract($GLOBALS)` on
 *      the page and `compact()` in the download (P24), and a second identical
 *      fetch into `$event` (P25).
 *
 * WHAT IT CANNOT DO, said plainly
 *   - The webhook body is read from `tblWebhookDeliveries.payload`, not caught
 *     at a receiver. `WebhookDispatcher::emit()` builds the body ONCE and
 *     hands that same string to the delivery row and to the send, so the row
 *     is what a receiver gets — but this test does not stand up a receiver,
 *     because the dispatcher (rightly, #615) refuses to post to this machine.
 *     The webhook points at a `.invalid` name and the send fails; the body is
 *     recorded before the send is tried.
 *   - It runs ONE web worker and one organisation.
 *   - It does not cover erasure of the stored name and address (#964).
 *   - Section R compares what the pages SHOW. It cannot see what a page holds
 *     in memory and does not print, which is the whole point of naming the
 *     columns; that half is guarded only by the column lists being written
 *     out in the source, which tools/audit-checks/check_sql_columns.py reads.
 *   - Section R's "identical" covers the events it makes. A page branch that
 *     needs a column none of them exercises is not caught by P13 — it is
 *     caught by section C, which reads the source instead.
 *
 * MEASURING THE CODE BEFORE #963. Set SELFTEST_WEB_FROM to a folder holding an
 * older `web/` (for example `git archive <commit> web | tar -x -C <dir>`); the
 * same scenario runs against it and the plants are skipped. It is expected to
 * be red, and that is how "before" was measured.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/event-suggester-privacy-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and `proc_open()`. It REFUSES rather than
 *         skipping when anything is missing, because a skipped test reads
 *         exactly like a passing one.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/963
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'db' => null, 'users' => [], 'key' => 0, 'writeKey' => 0, 'hook' => 0, 'events' => []];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

function check(string $name, $got, $want): void
{
    global $passed, $failed;
    if ($got === $want) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    echo '      wanted: ' . var_export($want, true) . "\n";
    echo '      got:    ' . var_export($got, true) . "\n";
}

// ── What it needs ────────────────────────────────────────────────────────────
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this test needs {$needed}(), which this PHP does not have");
    }
}

$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

$webFrom   = getenv('SELFTEST_WEB_FROM') !== false && getenv('SELFTEST_WEB_FROM') !== ''
    ? rtrim((string) getenv('SELFTEST_WEB_FROM'), '/\\') : $repo . $sep . 'web';
$measuring = $webFrom !== $repo . $sep . 'web';
if (is_file($webFrom . $sep . '_apps' . $sep . 'calendar' . $sep . 'submit-save.php') === false) {
    refuse("SELFTEST_WEB_FROM ({$webFrom}) does not hold a portal web/ folder");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$db->set_charset('utf8mb4');
$state['db'] = $db;

$tables = (int) ($db->query(
    'SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

$siteId = (int) ($db->query('SELECT MIN(`siteID`) AS `s` FROM `tblSites`')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation');
}

// The visitor. Both are distinctive so that finding either ANYWHERE in an
// answer or a row means it came from the suggestion and nowhere else.
const VISITOR_NAME  = 'Pennywhistle Quandary';
const VISITOR_EMAIL = 'p963.quandary@example.invalid';

/** Does this text hold the visitor's name or address, in any capitals? */
function holdsVisitor(string $text): bool
{
    return stripos($text, VISITOR_EMAIL) !== false || stripos($text, VISITOR_NAME) !== false;
}

/** Remove every event this test makes, and the activity rows naming them. */
$clearEvents = static function () use (&$state): void {
    $db = $state['db'];
    foreach ($state['events'] as $id) {
        $like = 'Event #' . (int) $id . ' %';
        $q = $db->prepare('DELETE FROM `tblActivityLogs` WHERE `activityDescription` LIKE ?');
        $q->bind_param('s', $like);
        $q->execute();
        $q->close();
    }
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P963 %'");
    $state['events'] = [];
};

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'event-suggester-' . bin2hex(random_bytes(6));
$state['child'] = $child;

/** #968: remove every event, series, rule and outside calendar sections M and R make. */
$clearP968 = static function () use (&$state): void {
    $db = $state['db'];
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P968 %'");
    // The rules go with their series (ON DELETE CASCADE).
    $db->query("DELETE FROM `tblEventSeries` WHERE `seriesSlug` LIKE 'p968-%'");
    $db->query("DELETE FROM `tblExternalFeeds` WHERE `name` LIKE 'P968 %'");
};

/** Remove an account and every row that names it (an earlier run's, or this one's). */
$removeUser = static function (int $who) use (&$state): void {
    $db = $state['db'];
    $db->query("DELETE FROM `tblEvents` WHERE `createdByID` = {$who} OR `submittedByID` = {$who} OR `moderatedByID` = {$who}");
    $rs = $db->query(
        "SELECT `TABLE_NAME`, `COLUMN_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE` "
        . "WHERE `TABLE_SCHEMA` = DATABASE() AND `REFERENCED_TABLE_NAME` = 'tblUsers'"
    );
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . '` WHERE `' . $row['COLUMN_NAME'] . "` = {$who}");
        } catch (Throwable $e) {
            // A table whose own children still name the row.
        }
    }
    $rs->free();
    $db->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
};

register_shutdown_function(static function () use (&$state, $clearEvents, $removeUser, $clearP968): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['db'] instanceof mysqli) {
        try {
            $clearEvents();
            if ($state['hook'] > 0) {
                // Its deliveries go with it (ON DELETE CASCADE).
                $state['db']->query('DELETE FROM `tblWebhooks` WHERE `webhookID` = ' . (int) $state['hook']);
            }
            if ($state['key'] > 0) {
                $state['db']->query('DELETE FROM `tblApiKeys` WHERE `keyID` = ' . (int) $state['key']);
            }
            if ($state['writeKey'] > 0) {
                $state['db']->query('DELETE FROM `tblApiKeys` WHERE `keyID` = ' . (int) $state['writeKey']);
            }
            $clearP968();
            foreach ($state['users'] as $who) {
                $removeUser((int) $who);
            }
        } catch (Throwable $e) {
            echo "  ! could not remove this test's rows; remove events named 'P963 %' and the p963 accounts by hand.\n";
        }
    }
    if ($state['child'] !== null && is_dir($state['child']) === true) {
        // A plain recursive delete of a folder THIS run made, under the system
        // temp folder, whose name holds random bytes.
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($state['child'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() === true && $entry->isLink() === false ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($state['child']);
    }
});

mkdir($child, 0700, true);
$copied = 0;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($webFrom, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $to = $child . $sep . 'web' . substr($entry->getPathname(), strlen($webFrom));
    if ($entry->isDir() === true) {
        @mkdir($to, 0700, true);
        continue;
    }
    copy($entry->getPathname(), $to);
    $copied++;
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}

@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/event-suggester-privacy-selftest.php for ONE run.\nreturn "
    . var_export([
        'db_host' => $dbHost,
        'db_user' => $dbUser,
        'db_pass' => $dbPass,
        'db_name' => $dbName,
        'db_port' => $dbPort,
    ], true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
$router = <<<'ROUTER'
<?php
// Written by tools/event-suggester-privacy-selftest.php for ONE run. Test only.
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
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
file_put_contents($child . $sep . 'router.php', $router);

// An unusual port range, so this cannot meet another test's server.
$port = 19630 + random_int(0, 150);

/** Start (or restart) the server against the child tree. */
$startServer = static function () use (&$state, $child, $sep, $port): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
        usleep(400000);
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = 'prod';
    $state['proc'] = proc_open(
        [
            PHP_BINARY,
            '-d', 'session.save_path=' . $child . $sep . 'sessions',
            '-d', 'error_log=' . $child . $sep . 'php-errors.log',
            '-d', 'log_errors=1', '-d', 'display_errors=0',
            '-d', 'max_execution_time=120',
            '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
            '-S', '127.0.0.1:' . $port,
            '-t', $child . $sep . 'web' . $sep . 'public_html',
            $child . $sep . 'router.php',
        ],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $child . $sep . 'server.log', 'a'],
            2 => ['file', $child . $sep . 'server.log', 'a'],
        ],
        $pipes,
        $child,
        $env
    );
    if (is_resource($state['proc']) === false) {
        refuse('could not start PHP\'s web server');
    }
};

/**
 * One request. Redirects are NOT followed (a redirect is something the caller
 * sees). Each kind of caller has its own cookie jar, named by `jar`; a bearer
 * key is sent with no cookies at all.
 *
 * @param array{jar?:string, bearer?:string} $o
 */
$http = static function (string $method, string $path, array $post = [], array $o = []) use ($port, $child, $sep): array {
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:', 'Accept-Language: en'];
    if (($o['bearer'] ?? '') !== '') {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '*',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if (($o['bearer'] ?? '') === '') {
        $jar = $child . $sep . 'jar-' . ($o['jar'] ?? 'visitor') . '.txt';
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $m) === 1 ? $m[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
};

/** The form token on a page, or '' when there is none. */
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) === 1) {
        return $m[1];
    }
    return '';
};

$waitForServer = static function () use ($http): void {
    for ($i = 0; $i < 80; $i++) {
        if ($http('GET', '/login')['status'] > 0) {
            return;
        }
        usleep(250000);
    }
    refuse('the test server never answered');
};

$startServer();
$waitForServer();

// ── The people, the key and the connected service ───────────────────────────
// An administrator (who moderates) and an ordinary member, each with a
// password; a key holding ONLY events:read; and one webhook listening for
// `calendar.event.submitted` alone.
$people = [
    'admin'  => ['email' => 'p963.admin@selftest.invalid',  'login' => 'selftest-p963-admin',  'admin' => 1],
    'member' => ['email' => 'p963.member@selftest.invalid', 'login' => 'selftest-p963-member', 'admin' => 0],
    // #968: an administrator of THIS organisation only — no portal-wide flag,
    // not a global administrator. App::isAdmin() says yes to them, so the
    // moderation page and Manage Events let them in; the detail answer must
    // treat them the same.
    'siteadmin' => ['email' => 'p968.siteadmin@selftest.invalid', 'login' => 'selftest-p968-siteadmin', 'admin' => 0, 'siteAdmin' => 1],
];
foreach ($people as $who => $p) {
    $old = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
    $old->bind_param('s', $p['email']);
    $old->execute();
    foreach ($old->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $removeUser((int) $row['userID']);
    }
    $old->close();
}
$db->query("DELETE FROM `tblApiKeys` WHERE `name` IN ('P963 read-only key', 'P968 write key')");
$db->query("DELETE FROM `tblWebhooks` WHERE `name` = 'P963 suggestion listener'");
$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P963 %'");

foreach ($people as $who => $p) {
    $ins = $db->prepare(
        'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) '
        . 'VALUES (?, ?, 1, ?, ?, NOW())'
    );
    $full = 'P963 ' . $who;
    $ins->bind_param('ssii', $full, $p['email'], $p['admin'], $p['admin']);
    $ins->execute();
    $uid = (int) $db->insert_id;
    $ins->close();
    $state['users'][] = $uid;
    $people[$who]['id'] = $uid;
    $mem = $db->prepare(
        'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, ?, ?)'
    );
    $siteAdmin = (int) ($p['siteAdmin'] ?? $p['admin']);
    $mem->bind_param('iiii', $siteId, $uid, $siteAdmin, $siteAdmin);
    $mem->execute();
    $mem->close();
    $people[$who]['password'] = 'Sel' . bin2hex(random_bytes(8)) . '!9x';
    $hash = password_hash($people[$who]['password'], PASSWORD_DEFAULT);
    $acc  = $db->prepare(
        'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)'
    );
    $acc->bind_param('iss', $uid, $p['login'], $hash);
    $acc->execute();
    $acc->close();
}

$apiKey = 'wbms_' . bin2hex(random_bytes(16));
$keyIns = $db->prepare(
    'INSERT INTO `tblApiKeys` (`siteID`, `name`, `keyHash`, `keyPrefix`, `scopes`, `isActive`, `createdByID`) '
    . "VALUES (?, 'P963 read-only key', ?, ?, 'events:read', 1, NULL)"
);
$keyHash   = hash('sha256', $apiKey);
$keyPrefix = substr($apiKey, 0, 12);
$keyIns->bind_param('iss', $siteId, $keyHash, $keyPrefix);
$keyIns->execute();
$state['key'] = (int) $db->insert_id;
$keyIns->close();

// #968: a key that may also WRITE events. `events:write` is what the detail
// handler treats as "may manage events" for a key — for the DRAFT rule only.
// Since D-136 it must NOT be sent the moderation fields: no API key is,
// whatever its scope. Section M requires them absent for it, at both
// addresses, and plant P11b puts round 1's "write key gets them" back.
$writeKey = 'wbms_' . bin2hex(random_bytes(16));
$keyIns   = $db->prepare(
    'INSERT INTO `tblApiKeys` (`siteID`, `name`, `keyHash`, `keyPrefix`, `scopes`, `isActive`, `createdByID`) '
    . "VALUES (?, 'P968 write key', ?, ?, 'events:read,events:write', 1, NULL)"
);
$keyHash   = hash('sha256', $writeKey);
$keyPrefix = substr($writeKey, 0, 12);
$keyIns->bind_param('iss', $siteId, $keyHash, $keyPrefix);
$keyIns->execute();
$state['writeKey'] = (int) $db->insert_id;
$keyIns->close();

// The send will fail (`.invalid` never resolves, and #615 refuses this
// machine anyway); the body is recorded on the delivery row BEFORE the send.
$hookIns = $db->prepare(
    'INSERT INTO `tblWebhooks` (`siteID`, `name`, `eventTypes`, `targetUrl`, `signingSecret`, `isActive`) '
    . "VALUES (?, 'P963 suggestion listener', 'calendar.event.submitted', 'https://p963-receiver.invalid/hook', ?, 1)"
);
$secret = bin2hex(random_bytes(16));
$hookIns->bind_param('is', $siteId, $secret);
$hookIns->execute();
$state['hook'] = (int) $db->insert_id;
$hookIns->close();

/** Sign one person in on their own cookie jar. */
$signIn = static function (string $who) use ($http, $tokenOn, &$people, $child, $sep): void {
    @unlink($child . $sep . 'jar-' . $who . '.txt');
    $token = $tokenOn($http('GET', '/login', [], ['jar' => $who])['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', [
        'csrf_token' => $token,
        'username'   => $people[$who]['login'],
        'password'   => $people[$who]['password'],
    ], ['jar' => $who]);
    if (($in['status'] !== 302 && $in['status'] !== 303) || str_contains($in['location'], 'login') === true) {
        refuse("the test {$who} could not sign in (status " . $in['status'] . ')');
    }
};

/**
 * The whole story once: a visitor suggests, the administrator approves, and
 * everybody reads. Answers what every check compares, so the plants can run
 * it again and compare the same things.
 */
$scenario = static function (string $label) use ($http, $tokenOn, $signIn, $db, $siteId, $apiKey, &$state): array {
    $out = [];
    $signIn('admin');
    $signIn('member');

    // ── A visitor suggests an event, with no session of any kind.
    $visitorJar = 'visitor-' . $label;
    $form  = $http('GET', '/calendar/submit', [], ['jar' => $visitorJar]);
    $token = $tokenOn($form['body']);
    $name  = 'P963 ' . $label . ' bring and share lunch';
    $sent  = $http('POST', '/calendar/submit-save', [
        'csrf_token'     => $token,
        'eventName'      => $name,
        'startDateTime'  => '2031-06-14T12:30',
        'endDateTime'    => '2031-06-14T14:00',
        'locationName'   => 'The church hall',
        'description'    => 'Suggested by tools/event-suggester-privacy-selftest.php.',
        'submitterName'  => VISITOR_NAME,
        'submitterEmail' => VISITOR_EMAIL,
    ], ['jar' => $visitorJar]);
    $out['submitted'] = $sent['status'] . ' ' . (str_contains($sent['location'], 'submitted=1') ? 'thanked' : $sent['location']);

    $q = $db->prepare(
        'SELECT `eventID`, `submitterName`, `submitterEmail`, `submittedByID`, `submissionStatus` '
        . 'FROM `tblEvents` WHERE `eventName` = ? AND `siteID` = ? ORDER BY `eventID` DESC LIMIT 1'
    );
    $q->bind_param('si', $name, $siteId);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if ($row === null) {
        refuse("the visitor's suggestion ({$label}) was not saved: " . $out['submitted']);
    }
    $id = (int) $row['eventID'];
    $state['events'][] = $id;
    $out['stored'] = [$row['submitterName'], $row['submitterEmail'], $row['submittedByID'], $row['submissionStatus']];

    // The activity record of THIS suggestion. Found by its type and the
    // event's number — which both the old and the new wording begin with —
    // and then EVERY column of it is searched, not only the description.
    $like = 'Event #' . $id . ' %';
    $q = $db->prepare(
        "SELECT * FROM `tblActivityLogs` WHERE `activityType` = 'EventSubmitted' AND `activityDescription` LIKE ? "
        . 'ORDER BY `logID` DESC'
    );
    $q->bind_param('s', $like);
    $q->execute();
    $logs = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    $out['logCount']    = count($logs);
    $out['logText']     = $logs === [] ? '' : (string) $logs[0]['activityDescription'];
    $out['logUser']     = $logs === [] ? 'no row' : $logs[0]['userID'];
    $out['logLeaks']    = $logs !== [] && holdsVisitor((string) json_encode($logs[0], JSON_UNESCAPED_UNICODE));

    // What the connected service was sent for THIS event.
    $q = $db->prepare(
        'SELECT `payload` FROM `tblWebhookDeliveries` WHERE `webhookID` = ? AND `eventType` = ? ORDER BY `deliveryID` DESC'
    );
    $type = 'calendar.event.submitted';
    $q->bind_param('is', $state['hook'], $type);
    $q->execute();
    $deliveries = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    $payload = null;
    foreach ($deliveries as $d) {
        $j = json_decode((string) $d['payload'], true);
        if (is_array($j) === true && (int) ($j['data']['eventID'] ?? 0) === $id) {
            $payload = $d['payload'];
            break;
        }
    }
    $out['hookFound']  = $payload !== null;
    $decoded           = $payload === null ? [] : (array) json_decode((string) $payload, true);
    $out['hookData']   = $decoded['data'] ?? null;
    $out['hookLeaks']  = $payload !== null && holdsVisitor((string) $payload);

    // ── The administrator approves it through the real moderation handler.
    $page  = $http('GET', '/admin/calendar/moderation?status=pending', [], ['jar' => 'admin']);
    $mod   = $http('POST', '/admin/calendar/moderate', [
        'csrf_token' => $tokenOn($page['body']),
        'eventID'    => (string) $id,
        'action'     => 'approve',
    ], ['jar' => 'admin']);
    $q = $db->prepare('SELECT `status`, `submissionStatus`, `isPublic` FROM `tblEvents` WHERE `eventID` = ?');
    $q->bind_param('i', $id);
    $q->execute();
    $after = $q->get_result()->fetch_assoc();
    $q->close();
    $out['approved'] = [$mod['status'], $after['status'] ?? null, $after['submissionStatus'] ?? null, (int) ($after['isPublic'] ?? -1)];

    // ── Everybody reads the event's detail.
    /** What one answer says about this event: is it the event, and does it carry the visitor? */
    $read = static function (array $r) use ($id): array {
        $j     = json_decode($r['body'], true);
        $event = is_array($j) === true ? ($j['data']['event'] ?? null) : null;
        $isIt  = $r['status'] === 200 && is_array($event) === true
            && (int) ($event['eventID'] ?? 0) === $id && ($event['submissionStatus'] ?? '') === 'approved';
        return [
            'isTheEvent' => $isIt,
            'leaks'      => holdsVisitor($r['body']),
            'fields'     => is_array($event) === true
                ? array_values(array_intersect(['submitterName', 'submitterEmail'], array_keys($event)))
                : ['(no event in the answer: ' . $r['status'] . ')'],
        ];
    };
    $out['member']  = $read($http('GET', '/api/events/detail?id=' . $id, [], ['jar' => 'member']));
    $out['key']     = $read($http('GET', '/api/events/detail?id=' . $id, [], ['bearer' => $apiKey]));
    $out['keyV1']   = $read($http('GET', '/api/v1/events/' . $id, [], ['bearer' => $apiKey]));
    $out['admin']   = $read($http('GET', '/api/events/detail?id=' . $id, [], ['jar' => 'admin']));

    // ── The person who needs them: the moderation page, approved list.
    $modPage = $http('GET', '/admin/calendar/moderation?status=approved', [], ['jar' => 'admin']);
    $out['moderationShows'] = [
        $modPage['status'],
        str_contains($modPage['body'], htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
        str_contains($modPage['body'], VISITOR_EMAIL),
        str_contains($modPage['body'], VISITOR_NAME),
    ];
    $memberMod = $http('GET', '/admin/calendar/moderation?status=approved', [], ['jar' => 'member']);
    $out['moderationMember'] = [$memberMod['status'], holdsVisitor($memberMod['body'])];
    return $out;
};

/** The checks, one place, so the clean run and the restored run ask the same. */
$checkAll = static function (string $prefix, array $s): void {
    echo "{$prefix}. A visitor suggests an event\n";
    check("{$prefix}1 the visitor is thanked (the form saved it)", $s['submitted'], '302 thanked');
    check("{$prefix}2 the row KEEPS the name and address, with no account — D-134 needs the address to tell them",
        $s['stored'], [VISITOR_NAME, VISITOR_EMAIL, null, 'pending']);
    echo '      (the activity record reads: "' . $s['logText'] . "\")\n";
    echo '      (the webhook was sent: ' . json_encode($s['hookData'], JSON_UNESCAPED_SLASHES) . ")\n";
    check("{$prefix}3 exactly one activity record of the suggestion, naming no account", [$s['logCount'], $s['logUser']], [1, null]);
    check("{$prefix}4 ...it says a visitor suggested this event, by number", (bool) preg_match('/^Event #\d+ suggested by a visitor/', $s['logText']), true);
    check("{$prefix}5 ...and NO column of it holds the visitor's name or address", $s['logLeaks'], false);
    check("{$prefix}6 the connected service was sent this event", [$s['hookFound'], (int) ($s['hookData']['eventID'] ?? 0) > 0], [true, true]);
    check("{$prefix}7 ...as \"submittedBy\": \"visitor\"", $s['hookData']['submittedBy'] ?? '(missing)', 'visitor');
    check("{$prefix}8 ...and the body holds neither the name nor the address", $s['hookLeaks'], false);

    echo "{$prefix}. The administrator approves it, and everybody reads it\n";
    check("{$prefix}9 approval goes through: published, approved, still members-only (D-092)",
        $s['approved'], [302, 'published', 'approved', 0]);
    foreach (['member' => 'an ordinary member, signed in', 'key' => 'a key holding only events:read',
              'keyV1' => 'the same key at /api/v1/events/{id}', 'admin' => 'the administrator, through the API'] as $k => $who) {
        check("{$prefix}10-{$k} {$who} reads THIS event", $s[$k]['isTheEvent'], true);
        check("{$prefix}11-{$k} ...with no field for the name or address", $s[$k]['fields'], []);
        check("{$prefix}12-{$k} ...and neither anywhere in the answer", $s[$k]['leaks'], false);
    }

    echo "{$prefix}. Whoever moderates still has them\n";
    check("{$prefix}13 the moderation page shows the administrator the event, the address and the name",
        $s['moderationShows'], [200, true, true, true]);
    check("{$prefix}14 ...and refuses an ordinary member, showing neither", $s['moderationMember'], [403, false]);
};

$clean = $scenario('clean');
$checkAll('A', $clean);
$clearEvents();

// ── M. The organisation's working notes about an event (#968) ───────────────
// The detail answer used to send, to every caller allowed to see the event,
// the moderation note and the account numbers of whoever created, edited,
// moderated and last changed the status of the event (and who suggested it).
// The event below is an ordinary published, members-only event of the
// organisation with EVERY one of those columns filled in by hand — nothing in
// the portal writes `moderationNote` today, so the test writes it, which is
// the only way to know where it would go if something did.
const P968_NOTE = 'P968 moderation note: the person who suggested this was rude on the telephone';

/** The fields only an administrator signed in to the portal is sent (detail.php, #968, D-136). */
const P968_MANAGERS_ONLY = [
    'moderationNote', 'createdByID', 'updatedByID', 'submittedByID',
    'moderatedByID', 'statusChangedByID', 'deletedAt',
];

$siteZone = (string) ($db->query('SELECT `timezone` FROM `tblSites` WHERE `siteID` = ' . $siteId)->fetch_assoc()['timezone'] ?? 'UTC');

/**
 * Make one event for sections M and R. Every name in `$fields` is a column
 * this test chose, never input; the values are bound.
 *
 * @param array<string,int|float|string|null> $fields
 */
$p968Event = static function (array $fields) use ($db, $siteId, $siteZone): int {
    static $n = 0;
    $n++;
    $fields += [
        'siteID'      => $siteId,
        'eventSlug'   => 'p968-' . $n . '-' . bin2hex(random_bytes(3)),
        'timezone'    => $siteZone,
        'status'      => 'published',
        'isPublic'    => 0,
        'endDateTime' => null,
    ];
    $cols  = array_keys($fields);
    $marks = implode(', ', array_fill(0, count($cols), '?'));
    $ins   = $db->prepare('INSERT INTO `tblEvents` (`' . implode('`, `', $cols) . '`) VALUES (' . $marks . ')');
    $vals  = array_map(static fn ($v) => $v === null ? null : (string) $v, array_values($fields));
    $ins->bind_param(str_repeat('s', count($vals)), ...$vals);
    $ins->execute();
    $id = (int) $db->insert_id;
    $ins->close();
    return $id;
};

$clearP968();
$moderated = $p968Event([
    'eventName'         => 'P968 moderated event',
    'startDateTime'     => '2031-07-05 10:00:00',
    'endDateTime'       => '2031-07-05 12:00:00',
    'description'       => 'An ordinary members-only event.',
    'createdByID'       => $people['admin']['id'],
    'updatedByID'       => $people['siteadmin']['id'],
    'submittedByID'     => $people['member']['id'],
    'submittedAt'       => '2031-06-01 09:00:00',
    'submissionStatus'  => 'approved',
    'moderatedByID'     => $people['admin']['id'],
    'moderatedAt'       => '2031-06-02 09:00:00',
    'moderationNote'    => P968_NOTE,
    'statusChangedByID' => $people['siteadmin']['id'],
    'statusChangedAt'   => '2031-06-03 09:00:00',
]);

/** Which of the managers-only fields one answer carries, and whether it is the event. */
$fieldsSeen = static function (array $r) use ($moderated): array {
    $j     = json_decode($r['body'], true);
    $event = is_array($j) === true ? ($j['data']['event'] ?? null) : null;
    if ($r['status'] !== 200 || is_array($event) === false || (int) ($event['eventID'] ?? 0) !== $moderated) {
        return ['isTheEvent' => false, 'fields' => ['(not this event: ' . $r['status'] . ')'], 'note' => null, 'ids' => null, 'noteAnywhere' => null];
    }
    return [
        'isTheEvent'   => true,
        'fields'       => array_values(array_intersect(P968_MANAGERS_ONLY, array_keys($event))),
        'note'         => $event['moderationNote'] ?? null,
        'ids'          => array_map(
            static fn (string $k) => array_key_exists($k, $event) === true && $event[$k] !== null ? (int) $event[$k] : null,
            ['createdByID', 'updatedByID', 'submittedByID', 'moderatedByID', 'statusChangedByID']
        ),
        'noteAnywhere' => str_contains($r['body'], 'rude on the telephone'),
    ];
};

/** Every caller reads the moderated event. */
$readModerated = static function () use ($http, $signIn, $apiKey, $writeKey, $moderated, $fieldsSeen): array {
    $signIn('member');
    $signIn('admin');
    $signIn('siteadmin');
    return [
        'member'    => $fieldsSeen($http('GET', '/api/events/detail?id=' . $moderated, [], ['jar' => 'member'])),
        // Round 1 of #968's check (X6): the v1 address was asked only with a
        // key, so a gate that let a signed-in member through at v1 alone
        // would have passed. ApiRouter defines PORTAL_API_V1 there, which is
        // exactly the kind of difference such a gate would key on.
        'memberV1'  => $fieldsSeen($http('GET', '/api/v1/events/' . $moderated, [], ['jar' => 'member'])),
        'readKey'   => $fieldsSeen($http('GET', '/api/events/detail?id=' . $moderated, [], ['bearer' => $apiKey])),
        'readKeyV1' => $fieldsSeen($http('GET', '/api/v1/events/' . $moderated, [], ['bearer' => $apiKey])),
        'writeKey'  => $fieldsSeen($http('GET', '/api/events/detail?id=' . $moderated, [], ['bearer' => $writeKey])),
        // Round 2 of #968's check: the v1 address was never asked by an
        // administrator session or by the write key, so a gate that differed
        // at v1 for either would have passed.
        'writeKeyV1' => $fieldsSeen($http('GET', '/api/v1/events/' . $moderated, [], ['bearer' => $writeKey])),
        'admin'     => $fieldsSeen($http('GET', '/api/events/detail?id=' . $moderated, [], ['jar' => 'admin'])),
        'adminV1'   => $fieldsSeen($http('GET', '/api/v1/events/' . $moderated, [], ['jar' => 'admin'])),
        'siteadmin' => $fieldsSeen($http('GET', '/api/events/detail?id=' . $moderated, [], ['jar' => 'siteadmin'])),
    ];
};

$wantIds = [
    $people['admin']['id'], $people['siteadmin']['id'], $people['member']['id'],
    $people['admin']['id'], $people['siteadmin']['id'],
];
$checkModerated = static function (string $prefix, array $m) use ($wantIds): void {
    echo "{$prefix}. The moderation note and the \"who did what\" numbers (#968, D-136: signed-in administrators only)\n";
    // D-136: NO API key gets them, whatever its scope — the write key is with
    // the member and the read-only key here, at both addresses.
    foreach (['member' => 'an ordinary member, signed in', 'memberV1' => 'the same member at /api/v1/events/{id}',
              'readKey' => 'a key holding only events:read', 'readKeyV1' => 'the same key at /api/v1/events/{id}',
              'writeKey' => 'a key holding events:write', 'writeKeyV1' => 'the write key at /api/v1/events/{id}'] as $k => $who) {
        echo "      ({$who} was sent: " . json_encode($m[$k]['fields']) . ")\n";
        check("{$prefix}1-{$k} {$who} reads THIS event", $m[$k]['isTheEvent'], true);
        check("{$prefix}2-{$k} ...with none of the seven fields", $m[$k]['fields'], []);
        check("{$prefix}3-{$k} ...and the note nowhere in the answer", $m[$k]['noteAnywhere'], false);
    }
    foreach (['admin' => 'a global administrator, signed in', 'adminV1' => 'the same administrator at /api/v1/events/{id}',
              'siteadmin' => 'an administrator of this organisation only'] as $k => $who) {
        check("{$prefix}4-{$k} {$who} reads THIS event", $m[$k]['isTheEvent'], true);
        check("{$prefix}5-{$k} ...with all seven fields", $m[$k]['fields'], P968_MANAGERS_ONLY);
        check("{$prefix}6-{$k} ...the note as written, and each account number the right one",
            [$m[$k]['note'], $m[$k]['ids']], [P968_NOTE, $wantIds]);
    }
};

$mClean = $readModerated();
$checkModerated('M', $mClean);

// ── R. The event page and the calendar download lose nothing (#968) ──────────
// calendar/event.php and calendar/export.php used to read the whole event row
// (`e.*`), so a visitor's name and address (#963) and the moderation note were
// in memory on two public pages. They now name their columns. A named list can
// be too SHORT, which shows only as a blank on the page — and nothing reading
// the source can promise to see every way a page reaches a column (round 3 of
// the check: `call_user_func('compact', 'event')` and `namespace\compact()`
// walked past section C). So THIS section is the guarantee, and it asks the
// question by behaviour (D-065):
//
//   EVERY column of tblEvents is filled with a distinct, recognisable value,
//   the column list being read from information_schema WHILE THE TEST RUNS,
//   so a column added later is filled without anybody remembering to. Then
//   the event page (to a visitor, a member, a global administrator and an
//   administrator of this organisation only) and the three downloads are
//   rendered with the named lists, and section P renders them
//   again with `e.*` put back and requires every answer to be IDENTICAL. A
//   column the code reads but the list leaves out holds a real value under
//   `e.*` and nothing under the named list, so the two renders differ —
//   whatever spelling the code uses to reach it.
//
// Where columns conflict, there is more than one event: timed and all-day,
// public and members-only, published and cancelled, imported in full and
// imported briefly, ticked for the website, a what3words-only location, and a
// three-date repeating series. Every event also carries a visitor's name and
// address and a moderation note, which must appear in none of the answers.
//
// AND SECTION P MEASURES THAT THIS IS TRUE (P26, P27): every column in each
// list is taken off on its own and R must see the difference. The first run of
// that sweep found two R could not see — `siteID` (read only to ask whether
// the viewer administers that organisation, which a GLOBAL administrator
// passes whatever it holds: hence the organisation-only administrator) and
// the download's `timezone` (it falls back to the organisation's zone, and
// every timed event was in that zone: hence the website event in Auckland).
//
// WHAT IT CANNOT SEE: a column the code reads but no event here makes matter
// (a page branch none of them reaches). The sweep proves every LISTED column
// matters here; a column read and NOT listed is caught only if one of these
// events makes it show.
$r0 = new DateTimeImmutable('2031-08-05 00:00:00', new DateTimeZone('UTC')); // a Tuesday
$db->query("INSERT INTO `tblEventSeries` (`siteID`, `seriesName`, `seriesSlug`) VALUES ({$siteId}, 'P968 Tuesday study', 'p968-tuesday-study')");
$series = (int) $db->insert_id;
$db->query("INSERT INTO `tblRecurrenceRules` (`siteID`, `seriesID`, `frequency`, `intervalVal`, `dayOfWeek`, `startDate`, `maxOccurrences`) "
    . "VALUES ({$siteId}, {$series}, 'weekly', 1, '2', '2031-08-05', 3)");
$db->query("INSERT INTO `tblExternalFeeds` (`siteID`, `name`, `url`, `audienceLevel`, `websiteOptIn`, `isActive`) "
    . "VALUES ({$siteId}, 'P968 outside calendar', 'https://calendar.selftest.invalid/p968.ics', 'public', 0, 1)");
$feed    = (int) $db->insert_id;
// A second outside calendar whose own audience is members, so its 'basic'
// event shows a visitor the title, date and time only (#514 section 1.4).
$db->query("INSERT INTO `tblExternalFeeds` (`siteID`, `name`, `url`, `audienceLevel`, `websiteOptIn`, `isActive`) "
    . "VALUES ({$siteId}, 'P968 members calendar', 'https://calendar.selftest.invalid/p968-members.ics', 'members', 0, 1)");
$feedMembers = (int) $db->insert_id;

// 🧾 Every column of tblEvents, as the database says it is NOW.
$eventColumns = $db->query(
    "SELECT `COLUMN_NAME`, `DATA_TYPE`, `COLUMN_TYPE`, `CHARACTER_MAXIMUM_LENGTH`, `EXTRA` "
    . "FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblEvents' "
    . 'ORDER BY `ORDINAL_POSITION`'
)->fetch_all(MYSQLI_ASSOC);
$eventLinks = [];
$rs = $db->query(
    "SELECT `COLUMN_NAME`, `REFERENCED_TABLE_NAME`, `REFERENCED_COLUMN_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE` "
    . "WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblEvents' AND `REFERENCED_TABLE_NAME` IS NOT NULL"
);
while ($row = $rs->fetch_assoc()) {
    $eventLinks[$row['COLUMN_NAME']] = [$row['REFERENCED_TABLE_NAME'], $row['REFERENCED_COLUMN_NAME']];
}
if (count($eventColumns) < 60) {
    refuse('information_schema lists only ' . count($eventColumns) . ' columns for tblEvents');
}

/**
 * A distinct, recognisable value for EVERY column, by its type, for one
 * event. `$seed` makes them distinct between events; the three dates of the
 * series share one seed, so their shown fields agree and the series is still
 * sent as one repeating entry.
 *
 * Columns that decide whether the row is the kind of event it is meant to
 * be are then set safely (not deleted, not imported unless meant to be, a
 * real zone, an address of its own); each scenario sets the rest.
 *
 * @return array<string,int|string|null>
 */
$fullRow = static function (int $seed) use ($eventColumns, $eventLinks, $db, $siteId, $siteZone): array {
    $row = [];
    foreach ($eventColumns as $i => $c) {
        $name  = (string) $c['COLUMN_NAME'];
        $extra = strtolower((string) $c['EXTRA']);
        if (str_contains($extra, 'auto_increment') === true
            || (str_contains($extra, 'generated') === true && str_contains($extra, 'default_generated') === false)) {
            continue; // the database fills these
        }
        if (isset($eventLinks[$name]) === true) {
            // A linked column needs a row that exists; the first one there is.
            [$table, $column] = $eventLinks[$name];
            $row[$name] = $db->query("SELECT MIN(`{$column}`) AS `v` FROM `{$table}`")->fetch_assoc()['v'];
            continue;
        }
        $tag  = $seed * 100 + $i;
        $type = strtolower((string) $c['DATA_TYPE']);
        if (in_array($type, ['varchar', 'char', 'text', 'mediumtext', 'longtext', 'tinytext'], true) === true) {
            $max        = (int) ($c['CHARACTER_MAXIMUM_LENGTH'] ?? 255);
            $row[$name] = substr('P968v' . $seed . ' ' . $name, 0, min($max, 200));
        } elseif ($type === 'tinyint') {
            $row[$name] = 1;
        } elseif (in_array($type, ['int', 'smallint', 'mediumint', 'bigint'], true) === true) {
            $row[$name] = 7000 + $tag;
        } elseif ($type === 'decimal') {
            $row[$name] = sprintf('%d.%07d', 10 + ($i % 40), $tag);
        } elseif ($type === 'datetime' || $type === 'timestamp') {
            $row[$name] = (new DateTimeImmutable('2031-09-01 00:00:00'))->modify('+' . $tag . ' minutes')->format('Y-m-d H:i:s');
        } elseif ($type === 'date') {
            $row[$name] = (new DateTimeImmutable('2031-09-01'))->modify('+' . ($i % 300) . ' days')->format('Y-m-d');
        } elseif ($type === 'enum') {
            preg_match("/^enum\\('([^']*)'/", (string) $c['COLUMN_TYPE'], $m);
            $row[$name] = $m[1] ?? null;
        } elseif ($type === 'binary' || $type === 'varbinary') {
            $row[$name] = random_bytes((int) ($c['CHARACTER_MAXIMUM_LENGTH'] ?? 32));
        } else {
            // A type this section does not know: say so, rather than leave a
            // new column empty and the comparison blind to it.
            refuse("section R does not know how to fill tblEvents.{$name} ({$type}); teach \$fullRow");
        }
    }
    // Safe settings for what the row IS. Each scenario overrides as it needs.
    return array_merge($row, [
        'siteID'          => $siteId,
        'seriesID'        => null,
        'eventSlug'       => 'p968-' . $seed . '-' . bin2hex(random_bytes(4)),
        'timezone'        => $siteZone,
        'status'          => 'published',
        'isAllDay'        => 0,
        'isPublic'        => 0,
        'isDeleted'       => 0,
        'externalFeedID'  => null,
        'externalPrivate' => 0,
        'externalDuplicate' => 0,
        'partnerOrgs'     => '["P968v' . $seed . ' partner"]',
        'locationGeoLat'  => sprintf('51.%07d', $seed),
        'locationGeoLng'  => sprintf('0.%07d', $seed),
        'submitterName'   => VISITOR_NAME,
        'submitterEmail'  => VISITOR_EMAIL,
        'moderationNote'  => P968_NOTE,
    ]);
};
$day = static fn (int $plus, string $time): string => $r0->modify('+' . $plus . ' days')->format('Y-m-d') . ' ' . $time;
$imported = static fn (int $feedId, string $uid, string $detail): array => [
    'externalFeedID' => $feedId, 'externalUid' => $uid, 'externalUidHash' => hash('sha256', $uid, true),
    'externalRecurrenceKey' => '', 'importLevel' => 'public', 'importDetail' => $detail,
    'importSource' => 'calendar', 'importApiOptOut' => 0, 'importAudienceType' => null, 'importAudienceID' => null,
];
$rEvents = [
    'public' => $p968Event(array_merge($fullRow(1), [
        'eventName' => 'P968 public open day', 'isPublic' => 1,
        'startDateTime' => $day(1, '10:00:00'), 'endDateTime' => $day(1, '16:00:00'),
        'description' => "Come and see.\nBring a friend.",
        'locationName' => 'P968 Hall', 'locationAddress' => "1 Test Street\nTestville TE1 1ST",
        'locationWebURL' => 'https://hall.selftest.invalid/', 'locationPhone' => '01234 567890',
        'locationGeoLat' => '52.2053000', 'locationGeoLng' => '0.1218000',
        'hostOrgName' => 'P968 Host Trust', 'partnerOrgs' => '["P968 Partner A","P968 Partner B"]', 'capacity' => 40,
        'locationW3W' => 'filled.count.soap',
    ])),
    // Round 1 of #968's check: an event whose ONLY location is a what3words
    // address. With `locationW3W` left off the page's list, its whole "Where"
    // card disappeared and nothing warned.
    'w3wOnly' => $p968Event(array_merge($fullRow(2), [
        'eventName' => 'P968 meet at the gate', 'isPublic' => 1,
        'startDateTime' => $day(7, '08:00:00'), 'endDateTime' => $day(7, '09:00:00'),
        'locationName' => null, 'locationAddress' => null, 'locationGeoLat' => null, 'locationGeoLng' => null,
        'locationW3W' => 'index.home.raft',
    ])),
    'members' => $p968Event(array_merge($fullRow(3), [
        'eventName' => 'P968 members meeting', 'isPublic' => 0,
        'startDateTime' => $day(2, '19:30:00'), 'endDateTime' => $day(2, '21:00:00'),
        'description' => 'For members.', 'locationName' => 'P968 Vestry',
    ])),
    'allday' => $p968Event(array_merge($fullRow(4), [
        'eventName' => 'P968 all day retreat', 'isPublic' => 1, 'isAllDay' => 1,
        'startDateTime' => $day(3, '00:00:00'), 'endDateTime' => $day(3, '23:59:59'), 'timezone' => 'Europe/London',
    ])),
    'cancelled' => $p968Event(array_merge($fullRow(5), [
        'eventName' => 'P968 cancelled concert', 'isPublic' => 1, 'status' => 'cancelled',
        'cancelReason' => 'P968 the roof leaks', 'statusChangedAt' => '2031-07-30 12:00:00',
        'startDateTime' => $day(4, '18:00:00'), 'endDateTime' => $day(4, '20:00:00'),
    ])),
    'imported' => $p968Event(array_merge($fullRow(6), $imported($feed, 'p968-full@selftest.invalid', 'full'), [
        'eventName' => 'P968 copied in', 'isPublic' => 0,
        'startDateTime' => $day(5, '09:00:00'), 'endDateTime' => $day(5, '10:00:00'),
        'description' => 'From an outside calendar.', 'locationName' => 'P968 Elsewhere',
    ])),
    'importedBasic' => $p968Event(array_merge($fullRow(7), $imported($feedMembers, 'p968-basic@selftest.invalid', 'basic'), [
        'eventName' => 'P968 copied in briefly', 'isPublic' => 0,
        'startDateTime' => $day(6, '09:00:00'), 'endDateTime' => $day(6, '10:00:00'),
        'description' => 'Only its audience sees this.', 'locationName' => 'P968 Somewhere',
    ])),
    // Ticked for the public website with no date limits, so the page's
    // search-engine markup (D-089) is drawn for it. In a zone that is NOT the
    // organisation's: the download falls back to the organisation's zone when
    // `timezone` is missing, so with every timed event in that zone a list
    // without `timezone` was invisible (the P27 sweep measured exactly that).
    'website' => $p968Event(array_merge($fullRow(8), [
        'eventName' => 'P968 on the website', 'isPublic' => 1, 'showOnWebsite' => 1,
        'timezone' => 'Pacific/Auckland',
        'websiteFrom' => null, 'websiteUntil' => null,
        'startDateTime' => $day(8, '11:00:00'), 'endDateTime' => $day(8, '12:00:00'),
    ])),
];
for ($w = 0; $w < 3; $w++) {
    $rEvents['series' . $w] = $p968Event(array_merge($fullRow(9), [
        'eventName' => 'P968 Tuesday study', 'isPublic' => 1, 'seriesID' => $series,
        'startDateTime' => $r0->modify('+' . (7 * $w) . ' days')->format('Y-m-d') . ' 19:00:00',
        'endDateTime'   => $r0->modify('+' . (7 * $w) . ' days')->format('Y-m-d') . ' 20:00:00',
        'description'   => 'Weekly.', 'locationName' => 'P968 Library',
    ]));
}
$slugOf = [];
$q = $db->query("SELECT `eventID`, `eventSlug` FROM `tblEvents` WHERE `eventName` LIKE 'P968 %'");
while ($row = $q->fetch_assoc()) {
    $slugOf[(int) $row['eventID']] = (string) $row['eventSlug'];
}

// Which columns ended up empty in EVERY one of section R's events. Only the
// ones that have nothing to point at may stay empty: a venue and a room (no
// venue exists in this database, and neither page reads them).
$emptyEverywhere = [];
$ids = implode(', ', array_map('intval', array_values($rEvents)));
foreach ($eventColumns as $c) {
    $name = (string) $c['COLUMN_NAME'];
    $n    = (int) $db->query("SELECT COUNT(`{$name}`) AS `n` FROM `tblEvents` WHERE `eventID` IN ({$ids})")->fetch_assoc()['n'];
    if ($n === 0) {
        $emptyEverywhere[] = $name;
    }
}
$mayStayEmpty = array_values(array_filter(['venueID', 'roomID'], static function (string $col) use ($db, $eventLinks): bool {
    [$table, $column] = $eventLinks[$col] ?? ['', ''];
    return $table !== '' && $db->query("SELECT MIN(`{$column}`) AS `v` FROM `{$table}`")->fetch_assoc()['v'] === null;
}));

/**
 * Take out what differs between two renders of the SAME code: the form token
 * and the script nonce (random on every answer, #625), and the moment a
 * calendar file was made (DTSTAMP). Nothing else is touched, so any other
 * difference is a real one.
 */
$normalise = static function (string $body): string {
    $body = (string) preg_replace('/\bnonce="[^"]*"/', 'nonce="N"', $body);
    $body = (string) preg_replace('/(name="csrf_token"\s+value=")[^"]*"/', '$1T"', $body);
    $body = (string) preg_replace('/(<meta\s+name="csrf-token"\s+content=")[^"]*"/i', '$1T"', $body);
    $body = (string) preg_replace('/\bdata-csrf="[^"]*"/', 'data-csrf="T"', $body);
    // The cookie banner's script (templates/footer.php) carries the form token too.
    $body = (string) preg_replace('/(\bvar csrf = ")[0-9a-f]*"/', '$1T"', $body);
    return (string) preg_replace('/^DTSTAMP:.*$/m', 'DTSTAMP:X', $body);
};

/** Every page and download in section R, to every kind of viewer. */
$renderAll = static function () use ($http, $signIn, $rEvents, $slugOf, $series, $normalise): array {
    $signIn('member');
    $signIn('admin');
    $signIn('siteadmin');
    $out = [];
    // The organisation's own administrator as well as a global one: the page
    // reads `siteID` only to ask whether the viewer administers THAT
    // organisation (the "Manage who sees this" link on an imported event),
    // and a global administrator passes that question whatever `siteID` holds
    // — so without this viewer, `siteID` could leave the list unseen (the P26
    // sweep measured exactly that).
    foreach (['visitor' => 'r-visitor', 'member' => 'member', 'admin' => 'admin', 'siteadmin' => 'siteadmin'] as $who => $jar) {
        foreach ($rEvents as $label => $id) {
            $page = $http('GET', '/calendar/event?slug=' . urlencode($slugOf[$id]), [], ['jar' => $jar]);
            $out["{$who} page {$label}"] = [$page['status'], $normalise($page['body'])];
            $ics = $http('GET', '/calendar/export?id=' . $id, [], ['jar' => $jar]);
            $out["{$who} file {$label}"] = [$ics['status'], $normalise($ics['body'])];
        }
        $s = $http('GET', '/calendar/export?series=' . $series, [], ['jar' => $jar]);
        $out["{$who} file series"] = [$s['status'], $normalise($s['body'])];
        $a = $http('GET', '/calendar/export?all=1', [], ['jar' => $jar]);
        $out["{$who} file all"] = [$a['status'], $normalise($a['body'])];
    }
    return $out;
};

/** Which renders differ between two runs (by key), so a failure names them. */
$rendersDiffer = static function (array $a, array $b): array {
    $diff = [];
    foreach ($a as $key => $render) {
        if (($b[$key] ?? null) !== $render) {
            $diff[] = $key;
        }
    }
    return $diff;
};

/** The first line where two renders of one key differ, so a red check says WHAT differs. */
$firstDifference = static function (array $a, array $b, array $keys): string {
    foreach ($keys as $key) {
        $x = explode("\n", (string) ($a[$key][1] ?? ''));
        $y = explode("\n", (string) ($b[$key][1] ?? ''));
        if (($a[$key][0] ?? null) !== ($b[$key][0] ?? null)) {
            return "{$key}: status " . var_export($a[$key][0] ?? null, true) . ' then ' . var_export($b[$key][0] ?? null, true);
        }
        foreach ($x as $i => $line) {
            if (($y[$i] ?? null) !== $line) {
                return "{$key}, line " . ($i + 1) . ":\n        was: " . trim($line) . "\n        now: " . trim((string) ($y[$i] ?? '(nothing)'));
            }
        }
        if (count($y) !== count($x)) {
            return "{$key}: " . count($x) . ' lines then ' . count($y);
        }
    }
    return '(no difference)';
};

// A render is only worth comparing if it IS the thing: status 200 and the
// expected words in it (a refusal page would compare equal to a refusal page).
$shown = static function (array $renders, string $key, string $text): bool {
    return ($renders[$key][0] ?? 0) === 200 && str_contains($renders[$key][1] ?? '', $text) === true;
};
$checkRenders = static function (string $prefix, array $r) use ($shown): void {
    echo "{$prefix}. The event page and the calendar download, with their columns named (#968)\n";
    check("{$prefix}1 a visitor sees the public event, with its address, host, partners, telephone and web link",
        [$shown($r, 'visitor page public', 'P968 public open day'), $shown($r, 'visitor page public', 'Testville TE1 1ST'),
         $shown($r, 'visitor page public', 'P968 Host Trust'), $shown($r, 'visitor page public', 'P968 Partner B'),
         $shown($r, 'visitor page public', '01234 567890'), $shown($r, 'visitor page public', 'hall.selftest.invalid')],
        [true, true, true, true, true, true]);
    check("{$prefix}2 the cancelled event says why, to a visitor", $shown($r, 'visitor page cancelled', 'P968 the roof leaks'), true);
    check("{$prefix}2b the what3words address shows as a link, and an event with ONLY a what3words address still has its \"Where\" card",
        [$shown($r, 'visitor page public', '///filled.count.soap'), $shown($r, 'visitor page w3wOnly', '///index.home.raft'),
         $shown($r, 'member page w3wOnly', '>Where</h5>')],
        [true, true, true]);
    check("{$prefix}3 a member sees the members-only event; a visitor is refused it",
        [$shown($r, 'member page members', 'P968 members meeting'), $r['visitor page members'][0]], [true, 404]);
    check("{$prefix}4 the imported event shows in full; the brief one shows its title to a visitor but not its details",
        [$shown($r, 'visitor page imported', 'From an outside calendar.'), $shown($r, 'visitor page importedBasic', 'P968 copied in briefly'),
         str_contains($r['visitor page importedBasic'][1], 'Only its audience sees this.')],
        [true, true, false]);
    check("{$prefix}5 the all-day and repeating events show", [$shown($r, 'member page allday', 'P968 all day retreat'),
        $shown($r, 'member page series1', 'P968 Tuesday study')], [true, true]);
    check("{$prefix}6 the calendar files carry the events: one entry with its map position, the series as one repeating entry, the public whole calendar",
        [$shown($r, 'visitor file public', 'SUMMARY:P968 public open day'), $shown($r, 'visitor file public', 'GEO:'),
         $shown($r, 'visitor file series', 'RRULE:'), $shown($r, 'visitor file all', 'SUMMARY:P968 public open day')],
        [true, true, true, true]);
    $leaks = [];
    foreach ($r as $key => [$status, $body]) {
        if (holdsVisitor($body) === true || str_contains($body, 'rude on the telephone') === true) {
            $leaks[] = $key;
        }
    }
    check("{$prefix}7 no page or file carries the visitor's name or address, or the moderation note", $leaks, []);
};

$rClean = $renderAll();
$checkRenders('R', $rClean);
echo '      (' . count($eventColumns) . ' columns of tblEvents read from information_schema; left empty in every event: '
    . json_encode($emptyEverywhere) . ")\n";
check('R0 every column of tblEvents holds a value in at least one of these events, except a link with nothing to point at',
    $emptyEverywhere, $mayStayEmpty);
$rAgain = $renderAll();
check('R8 the same code rendered twice comes out identical once tokens are taken out (so a difference in section P is real)',
    $rendersDiffer($rClean, $rAgain), []);
if ($rendersDiffer($rClean, $rAgain) !== []) {
    echo '      first difference: ' . $firstDifference($rClean, $rAgain, $rendersDiffer($rClean, $rAgain)) . "\n";
}


// ── C. The column lists are complete, checked by PHP's own tokeniser (#968) ──
// Round 1 of #968's check found the event page's list one column short:
// `locationW3W`, read twice with `?? ''` and `?? null`, so nothing warned and
// every what3words link vanished. The list had been built from a search for
// `$event['…']` that matched LETTERS only, and `W3W` holds a digit. Section R
// could not see it either, because none of its events had a what3words
// address: a fixture shows only what it exercises. So this section asks the
// SOURCE, mechanically, with token_get_all() rather than any pattern:
//
//   - every `$var['literal']` read of a variable that holds a row of the
//     lookup, and every `$events[0]['literal']`;
//   - every subscript that is NOT a literal string (a key built at run time),
//     which no reader of source can resolve — pinned below with its reason;
//   - every other use of the variable (copied to another name, passed to a
//     function, put in a list) — pinned below as a short token shape WITH HOW
//     MANY TIMES IT APPEARS, so a NEW way the row travels, or a second copy of
//     an old one (a second fetch into `$event`), fails here until a person has
//     looked at it (round 2 of the check: the shapes were de-duplicated, so a
//     second identical fetch was invisible);
//   - the spellings it KNOWS of reaching a variable without naming it:
//     `$$name`, `${'…'}`, `compact()`, `extract()`, `get_defined_vars()` (by
//     any form of the name, `namespace\compact` included), any of those
//     three names as a string, `call_user_func()`, `call_user_func_array()`,
//     `$GLOBALS` and `eval` — neither file uses any of them. This is a
//     TRIPWIRE for known spellings, not a proof: round 2 of the check found
//     `$$nm`, `${'event'}` and `compact('event')` past it, and round 3 found
//     `namespace\compact('event')` and `call_user_func('compact', 'event')`
//     past the round-2 version. PHP has more ways than any list. THE
//     GUARANTEE IS SECTION R, which fills every column and compares the
//     rendered pages with `e.*` put back, so a column reached by any spelling
//     shows up there.
//
// and requires each page's SELECT list and those reads to match in BOTH
// directions. The select list is read from the statement's text: each
// backticked `` `alias`.`column` `` (with its `AS` name, if any) is a name,
// found with `\w` (letters, digits, underscore). EVERYTHING ELSE between
// SELECT and FROM must be commas, spaces, the `%s` the visibility rule is put
// in through, and PHP's own `' . '` joins — anything left over (`e.*`,
// `` `e`.* ``, an un-backticked `e.locationEmail`, a function call) fails and
// is named (round 2 of the check: those three walked past, because only the
// backticked form was collected).
//
// WHAT IT CANNOT SEE, said plainly, and checked by hand on 11 November 2026:
//   - Any spelling of reaching the row that is not on its list (above).
//   - The columns of the `%s` argument: the visibility rule's own SQL
//     (EventVisibility's `canSeeFull` expression) is put into the list at run
//     time, so its text is not in the source this reads. It hands the page
//     one value, `canSeeFull`, which is allowed by name.
//   - A key built at run time. The one there is (`$row[$rendered]` in
//     export.php's `$patternStillTrue`) walks a literal list of nine names, all
//     in the list, and reads the SERIES-RUN query's rows (`$seriesRunForViewer`,
//     which names its own columns), not this lookup's.
//   - A row passed into a function in another file. The only one is
//     EventVisibility::redact() (both files), which empties a fixed list of
//     columns only where present and adds `detailsLimited`; it reads nothing.
//     Ical::emit() is never given a row: it is given `$entry` arrays built
//     from the keys collected here.
//   - Code in ANOTHER file that reads the row by its name. No template or
//     partial does (`grep -n '\$event\b' web/_core/templates/*.php
//     web/_core/partials/*.php` finds nothing); a new include that did would
//     not be seen here.
//   - Reads under a different variable name. export.php re-uses `$row` and
//     `$r` for the series-run rows; their keys are collected too, so they are
//     counted against this list — over-strict, never blind, and every one of
//     them is in the list today. A row copied to a NEW name is a new bare
//     use, and fails the pin.

/**
 * Every read of the row variables in one PHP source, by PHP's tokeniser.
 *
 * @param list<string> $rowVars Variable names, with the `$`.
 * @return array{keys: list<string>, runtime: list<string>, bare: array<string,int>, indirect: list<string>}
 */
function p968RowReads(string $source, array $rowVars): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn ($t): bool => is_array($t) === false || in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === false
    ));
    $text = static fn ($t): string => is_array($t) === true ? (string) $t[1] : (string) $t;
    $keys = [];
    $runtime = [];
    $bare = [];
    $indirect = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        // 🚫 Ways of reaching a variable without naming it. A bare `$` token is
        //    `$$name` or `${…}`; inside a string it is T_DOLLAR_OPEN_CURLY_BRACES.
        if ($t === '$' || (is_array($t) === true && $t[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
            $indirect[] = 'line ' . (is_array($tokens[$i + 1] ?? null) === true ? $tokens[$i + 1][2] : '?') . ': ' . $text($t) . $text($tokens[$i + 1] ?? '');
            continue;
        }
        if (is_array($t) === true && ($t[0] === T_EVAL || ($t[0] === T_VARIABLE && $t[1] === '$GLOBALS'))) {
            $indirect[] = 'line ' . $t[2] . ': ' . $t[1];
            continue;
        }
        // A call by any spelling of the name: plain, `\compact`,
        // `namespace\compact` (T_NAME_RELATIVE — round 3 of the check), or a
        // qualified name; and the two functions that call another by a
        // string (`call_user_func('compact', …)`, round 3).
        if (is_array($t) === true
            && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true) === true
            && in_array(strtolower((string) substr((string) strrchr('\\' . $t[1], '\\'), 1)), P968_INDIRECT_CALLS, true) === true
            && $text($tokens[$i + 1] ?? '') === '('
            && in_array($text($tokens[$i - 1] ?? ''), ['->', '?->', '::', 'function', 'new'], true) === false) {
            $indirect[] = 'line ' . $t[2] . ': ' . $t[1] . '(';
            continue;
        }
        // The name of one of them as a string, wherever it is: the way it is
        // handed to call_user_func(), array_map() or anything else.
        if (is_array($t) === true && $t[0] === T_CONSTANT_ENCAPSED_STRING
            && in_array(strtolower(ltrim(substr($t[1], 1, -1), '\\')), ['compact', 'extract', 'get_defined_vars'], true) === true) {
            $indirect[] = 'line ' . $t[2] . ': the string ' . $t[1];
            continue;
        }
        if (is_array($t) === false || $t[0] !== T_VARIABLE || in_array($t[1], $rowVars, true) === false) {
            continue;
        }
        $k = $i + 1;
        // Step over integer subscripts: `$events[0]['eventName']`.
        while ($text($tokens[$k] ?? '') === '[' && is_array($tokens[$k + 1] ?? null) === true
            && $tokens[$k + 1][0] === T_LNUMBER && $text($tokens[$k + 2] ?? '') === ']') {
            $k += 3;
        }
        if ($text($tokens[$k] ?? '') === '[') {
            $next = $tokens[$k + 1] ?? null;
            if (is_array($next) === true && $next[0] === T_CONSTANT_ENCAPSED_STRING && $text($tokens[$k + 2] ?? '') === ']') {
                $keys[] = substr($next[1], 1, -1);
                continue;
            }
            if ($text($next) !== ']') {
                $runtime[] = $t[1] . '[' . $text($next) . ']';
                continue;
            }
        }
        // A bare use: its shape is the two tokens before and the three after,
        // so an edit right beside one changes its shape and it must be looked
        // at again — a cost accepted for a pin a person has checked.
        $shape = [];
        for ($j = max(0, $i - 2); $j <= min($n - 1, $i + 3); $j++) {
            $shape[] = $j === $i ? '<' . $t[1] . '>' : $text($tokens[$j]);
        }
        $bare[] = implode(' ', $shape);
    }
    $keys = array_values(array_unique($keys));
    sort($keys);
    // COUNTED, not de-duplicated: a second copy of a pinned shape is a change.
    $bare = array_count_values($bare);
    ksort($bare);
    $runtime = array_values(array_unique($runtime));
    sort($runtime);
    return ['keys' => $keys, 'runtime' => $runtime, 'bare' => $bare, 'indirect' => $indirect];
}

/**
 * The names a SELECT list hands to PHP (the column, or its `AS` name), and
 * whatever else stands between SELECT and FROM. Reads every statement in
 * `$source` that starts with `$startsWith`, up to `FROM `tblEvents``.
 *
 * @return list<array{names: list<string>, leftover: string}> One per statement found.
 */
function p968SelectLists(string $source, string $startsWith): array
{
    $lists = [];
    $at = 0;
    $item = '/`(\w+)`\.`(\w+)`(?:\s+AS\s+`(\w+)`)?/';
    while (($pos = strpos($source, $startsWith, $at)) !== false) {
        $end = strpos($source, 'FROM `tblEvents`', $pos);
        if ($end === false) {
            break;
        }
        // The text of the list: drop the opening quote, and PHP's joins
        // between the pieces of the string (`' . '`, across a line break).
        $sql = (string) preg_replace("/'\\s*\\.\\s*'/", ' ', substr($source, $pos + 1, $end - $pos - 1));
        preg_match_all($item, $sql, $m, PREG_SET_ORDER);
        $names = array_map(static fn (array $x): string => ($x[3] ?? '') !== '' ? $x[3] : $x[2], $m);
        sort($names);
        $rest = (string) preg_replace($item, ' ', $sql);
        $rest = (string) preg_replace('/^\s*SELECT\b/', ' ', $rest);
        $rest = str_replace('%s', ' ', $rest);
        $rest = trim((string) preg_replace('/[\s,]+/', ' ', $rest));
        $lists[] = ['names' => $names, 'leftover' => $rest];
        $at = $end;
    }
    return $lists;
}

/** Functions that reach variables without naming them, or call another function by a string. */
const P968_INDIRECT_CALLS = ['compact', 'extract', 'get_defined_vars', 'call_user_func', 'call_user_func_array'];

/** Names the code reads that come from no column: the `%s` alias, and what redact() adds. */
const P968_NOT_COLUMNS = ['canSeeFull', 'detailsLimited'];

/** Every bare use of the row in event.php, and how many times, each looked at by a person (11 November 2026). */
const P968_EVENT_BARE = [
    ') ; <$event> = null ;'                   => 1, // starts empty
    ') ; <$event> = $stmt ->'                 => 1, // the lookup's one row
    '$isVisibleStatus = <$event> !== null &&' => 1, // found or not
    '$visible = <$event> !== null &&'         => 1, // found or not
    '; } <$event> = EventVisibility ::'       => 1, // replaced by redact()'s copy
    'redact ( <$event> , $canSeeFull )'       => 1, // redact(): empties, reads nothing
];

/** Every bare use of a row variable in export.php, and how many times, each looked at by a person. */
const P968_EXPORT_BARE = [
    '0 ; <$events> = [ ]'                     => 1, // starts empty
    ') ; <$row> = null ;'                     => 1, // starts empty
    ') ; <$row> = $stmt ->'                   => 1, // the single-event lookup's row
    'if ( <$row> !== null &&'                 => 1, // the draft rule
    ') { <$row> = null ;'                     => 1, // a draft refused
    'if ( <$row> === null )'                  => 1, // nothing for this viewer
    '; } <$events> [ ] ='                     => 1, // collected
    '] = <$row> ; } elseif'                   => 1, // collected
    'while ( <$r> = $result ->'               => 2, // series and whole-calendar rows
    ') { <$events> [ ] ='                     => 2, // collected, both loops
    '] = <$r> ; } $stmt'                      => 2, // collected, both loops
    'count ( <$events> ) === 0'               => 2, // nothing for this viewer, both branches
    '$rows as <$row> ) { if'                  => 1, // SERIES-RUN rows (another query)
    '( array <$r> ) : string'                 => 1, // SERIES-RUN rows (another query)
    'foreach ( <$events> as $ev )'            => 1, // each row becomes an entry
    '$events as <$ev> ) { $canSeeFull'        => 1, // each row becomes an entry
    '1 ; <$ev> = EventVisibility ::'          => 1, // replaced by redact()'s copy
    'redact ( <$ev> , $canSeeFull )'          => 1, // redact(): empties, reads nothing
];

/** Every key built at run time, each looked at by a person. */
const P968_EXPORT_RUNTIME = [
    '$row[$rendered]', // $patternStillTrue: nine literal names, SERIES-RUN rows
];

/**
 * Where two counted shape lists disagree: "shape: found N, pinned M".
 *
 * @param array<string,int> $found
 * @param array<string,int> $pinned
 * @return list<string>
 */
function p968ShapeDifferences(array $found, array $pinned): array
{
    $out = [];
    foreach (array_unique(array_merge(array_keys($found), array_keys($pinned))) as $shape) {
        $f = $found[$shape] ?? 0;
        $p = $pinned[$shape] ?? 0;
        if ($f !== $p) {
            $out[] = "{$shape}: found {$f}, pinned {$p}";
        }
    }
    sort($out);
    return $out;
}

/**
 * The whole of section C for one pair of sources, so the plants can ask the
 * same questions of a changed copy.
 */
$columnsCheck = static function (string $eventSrc, string $exportSrc): array {
    $page      = p968RowReads($eventSrc, ['$event']);
    $pageLists = p968SelectLists($eventSrc, "'SELECT `e`.`eventID`, `e`.`siteID`");
    $pageList  = $pageLists[0]['names'] ?? [];
    $pageReads = array_values(array_diff($page['keys'], P968_NOT_COLUMNS));

    $file      = p968RowReads($exportSrc, ['$row', '$r', '$ev', '$events']);
    $fileLists = p968SelectLists($exportSrc, "'SELECT `e`.`eventID`, `e`.`seriesID`");
    $fileList  = $fileLists[0]['names'] ?? [];
    $fileReads = array_values(array_diff($file['keys'], P968_NOT_COLUMNS));

    $leftovers = static fn (array $lists): array => array_values(array_filter(
        array_map(static fn (array $l): string => $l['leftover'], $lists),
        static fn (string $s): bool => $s !== ''
    ));
    return [
        'pageStatements' => count($pageLists),
        'pageLeftover'   => $leftovers($pageLists),
        'pageMissing'    => array_values(array_diff($pageReads, $pageList)),
        'pageUnread'     => array_values(array_diff($pageList, $pageReads)),
        'pageRuntime'    => $page['runtime'],
        'pageBare'       => p968ShapeDifferences($page['bare'], P968_EVENT_BARE),
        'pageIndirect'   => $page['indirect'],
        'fileStatements' => count($fileLists),
        'fileSame'       => count(array_unique(array_map(static fn (array $l): string => (string) json_encode($l['names']), $fileLists))) === 1,
        'fileLeftover'   => $leftovers($fileLists),
        'fileMissing'    => array_values(array_diff($fileReads, $fileList)),
        'fileUnread'     => array_values(array_diff($fileList, $fileReads)),
        'fileRuntime'    => $file['runtime'],
        'fileBare'       => p968ShapeDifferences($file['bare'], P968_EXPORT_BARE),
        'fileIndirect'   => $file['indirect'],
        'pageReadsW3W'   => in_array('locationW3W', $pageReads, true),
    ];
};

$checkColumns = static function (string $prefix, array $c): void {
    echo "{$prefix}. The column lists match what the code reads, by PHP's tokeniser (#968)\n";
    check("{$prefix}1 the event page has ONE such statement, and the tokeniser finds its locationW3W read (the round-1 miss)",
        [$c['pageStatements'], $c['pageReadsW3W']], [1, true]);
    check("{$prefix}1b ...and its list holds nothing but backticked columns (no `e.*`, no un-backticked column, no call)",
        $c['pageLeftover'], []);
    check("{$prefix}2 every event column the page reads is in its list", $c['pageMissing'], []);
    check("{$prefix}3 ...and every column in its list is read (so the list stays the page's, not a copy of the table)", $c['pageUnread'], []);
    check("{$prefix}4 ...no key built at run time, and every other use of the row is one a person has looked at, as many times as pinned",
        [$c['pageRuntime'], $c['pageBare']], [[], []]);
    check("{$prefix}4b ...and none of the spellings this tripwire knows for reaching a variable without naming it (section R is the guarantee)",
        $c['pageIndirect'], []);
    check("{$prefix}5 the download has THREE such statements, all with the same list",
        [$c['fileStatements'], $c['fileSame']], [3, true]);
    check("{$prefix}5b ...and each of the three holds nothing but backticked columns", $c['fileLeftover'], []);
    check("{$prefix}6 every column the download reads from a row is in that list, and every listed column is read",
        [$c['fileMissing'], $c['fileUnread']], [[], []]);
    check("{$prefix}7 ...and its run-time key and every other use of a row are ones a person has looked at, as many times as pinned",
        [$c['fileRuntime'], $c['fileBare']], [P968_EXPORT_RUNTIME, []]);
    check("{$prefix}7b ...and none of the spellings this tripwire knows for reaching a variable without naming it", $c['fileIndirect'], []);
};

$eventFile  = $webFrom . $sep . '_apps' . $sep . 'calendar' . $sep . 'event.php';
$exportFile = $webFrom . $sep . '_apps' . $sep . 'calendar' . $sep . 'export.php';
$cClean     = $columnsCheck((string) file_get_contents($eventFile), (string) file_get_contents($exportFile));
if ($measuring === true) {
    echo "C. Skipped: measuring an older web/ (SELFTEST_WEB_FROM), which read the whole row with `e.*`.\n";
} else {
    $checkColumns('C', $cClean);
}

// ── P. The plants ───────────────────────────────────────────────────────────
if ($measuring === true) {
    echo "P. Skipped: measuring an older web/ (SELFTEST_WEB_FROM), whose lines the plants do not match.\n";
} else {
    echo "P. The plants: each leak put back in the child copy must turn its checks red\n";
    $detailPath = $child . $sep . 'web' . $sep . '_apps' . $sep . 'events' . $sep . 'api' . $sep . 'detail.php';
    $submitPath = $child . $sep . 'web' . $sep . '_apps' . $sep . 'calendar' . $sep . 'submit-save.php';
    $originals  = [$detailPath => (string) file_get_contents($detailPath), $submitPath => (string) file_get_contents($submitPath)];

    /** Replace exactly one copy of `$find`, proving the plant applied. */
    $plant = static function (string $path, string $find, string $replace, string $what) use ($originals, $startServer, $waitForServer): void {
        if (substr_count($originals[$path], $find) !== 1) {
            refuse('section P could not find exactly one copy of ' . $what . ' in the copied file');
        }
        file_put_contents($path, str_replace($find, $replace, $originals[$path]));
        clearstatcache(true, $path);
        if (str_contains((string) file_get_contents($path), $replace) === false) {
            refuse('section P wrote ' . $what . ' and could not read it back');
        }
        $startServer();
        $waitForServer();
    };
    $restore = static function () use ($originals, $startServer, $waitForServer): void {
        foreach ($originals as $path => $text) {
            file_put_contents($path, $text);
            clearstatcache(true, $path);
        }
        $startServer();
        $waitForServer();
    };

    // (a) The detail statement selects the two columns again.
    $plant(
        $detailPath,
        "'e.submissionStatus, e.submittedByID, e.submittedAt, '",
        "'e.submissionStatus, e.submittedByID, e.submitterName, e.submitterEmail, e.submittedAt, '",
        'the detail statement\'s submission columns'
    );
    $p = $scenario('plant-a');
    check('P1 with the two columns back, the member reads the name and the address (A11/A12-member would have gone red)',
        [$p['member']['isTheEvent'], $p['member']['fields'], $p['member']['leaks']], [true, ['submitterName', 'submitterEmail'], true]);
    check('P2 ...and so does the read-only key (A11/A12-key would have gone red)',
        [$p['key']['fields'], $p['key']['leaks'], $p['keyV1']['leaks']], [['submitterName', 'submitterEmail'], true, true]);
    check('P3 ...while the activity record and the webhook stay clean (the plant reached only the detail answer)',
        [$p['logLeaks'], $p['hookLeaks']], [false, false]);
    $clearEvents();
    $restore();

    // (b) The old activity description.
    $plant(
        $submitPath,
        "Logger::activity('EventSubmitted', 'Event #' . \$newId . ' suggested by a visitor who was not signed in');",
        "Logger::activity('EventSubmitted', 'Event #' . \$newId . ' submitted by anonymous ' . \$submitterEmail);",
        'the visitor\'s activity description'
    );
    $p = $scenario('plant-b');
    check('P4 with the old wording back, the activity record holds the address (A4/A5 would have gone red)',
        [$p['logCount'], $p['logLeaks']], [1, true]);
    check('P5 ...while the webhook and the detail answer stay clean', [$p['hookLeaks'], $p['member']['leaks']], [false, false]);
    $clearEvents();
    $restore();

    // (c) The old `submittedBy`.
    $plant(
        $submitPath,
        "'submittedBy' => \$isLoggedIn === true ? ('user-' . \$submitterId) : 'visitor',",
        "'submittedBy' => \$isLoggedIn === true ? ('user-' . \$submitterId) : \$submitterEmail,",
        'the webhook\'s submittedBy'
    );
    $p = $scenario('plant-c');
    check('P6 with the old submittedBy back, the connected service is sent the address (A7/A8 would have gone red)',
        [$p['hookFound'], $p['hookData']['submittedBy'] ?? '(missing)', $p['hookLeaks']], [true, VISITOR_EMAIL, true]);
    check('P7 ...while the activity record and the detail answer stay clean', [$p['logLeaks'], $p['key']['leaks']], [false, false]);
    $clearEvents();
    $restore();

    $after = $scenario('restored');
    check('P8 with every plant taken out, nothing carries the visitor again',
        [$after['logLeaks'], $after['hookLeaks'], $after['member']['leaks'], $after['key']['leaks'], $after['keyV1']['leaks']],
        [false, false, false, false, false]);
    $clearEvents();

    // ── The #968 plants: sections M and R ──────────────────────────────────
    $eventPath  = $child . $sep . 'web' . $sep . '_apps' . $sep . 'calendar' . $sep . 'event.php';
    $exportPath = $child . $sep . 'web' . $sep . '_apps' . $sep . 'calendar' . $sep . 'export.php';
    $originals968 = [
        $detailPath => (string) file_get_contents($detailPath),
        $eventPath  => (string) file_get_contents($eventPath),
        $exportPath => (string) file_get_contents($exportPath),
    ];
    /**
     * Rewrite ONE copied file with a regular expression that must match
     * exactly `$times` times, and prove the new text is on disk.
     */
    $plant968 = static function (string $path, string $pattern, string $with, int $times, string $what) use ($originals968, $startServer, $waitForServer): void {
        $count = 0;
        $new   = (string) preg_replace($pattern, $with, $originals968[$path], -1, $count);
        if ($count !== $times) {
            refuse("section P (#968) found {$count} copies of {$what} where it expected {$times}");
        }
        file_put_contents($path, $new);
        clearstatcache(true, $path);
        if ((string) file_get_contents($path) !== $new || $new === $originals968[$path]) {
            refuse("section P (#968) wrote {$what} and could not read it back");
        }
        $startServer();
        $waitForServer();
    };
    $restore968 = static function () use ($originals968, $startServer, $waitForServer): void {
        foreach ($originals968 as $path => $text) {
            file_put_contents($path, $text);
            clearstatcache(true, $path);
        }
        $startServer();
        $waitForServer();
    };

    // (c2) Round 1 X6: a gate that never strips a signed-in member at v1.
    $plant968(
        $detailPath,
        '/^\$seesWorkingNotes = \$apiKeyRow === null && \$canManageEvents === true;$/m',
        "\$seesWorkingNotes = (\$apiKeyRow === null && \$canManageEvents === true) || (\$apiKeyRow === null && defined('PORTAL_API_V1') === true);",
        1,
        'the working-notes gate (v1 sessions let through)'
    );
    $m = $readModerated();
    check('P8b with a signed-in member at v1 never stripped, that member is sent all seven while the old address and the keys are not (M2-memberV1 would have gone red)',
        [$m['memberV1']['fields'], $m['member']['fields'], $m['readKeyV1']['fields']], [P968_MANAGERS_ONLY, [], []]);
    $restore968();

    // (d) Each field put back, one at a time: the member and the read-only key
    //     must then be sent exactly that field (M2 would have gone red).
    foreach (P968_MANAGERS_ONLY as $field) {
        $plant968($detailPath, '/^    \'' . preg_quote($field, '/') . '\',\n/m', '', 1, "the {$field} line of the managers-only list");
        $m = $readModerated();
        check("P9-{$field} with {$field} left off the list, the member and the read-only key are sent it",
            [$m['member']['fields'], $m['readKey']['fields'], $m['readKeyV1']['fields']], [[$field], [$field], [$field]]);
        $restore968();
    }

    // (e) The gate itself switched off, both ways, and put back to round 1's
    //     rule (D-136 replaced it).
    $plant968($detailPath, '/^\$seesWorkingNotes = \$apiKeyRow === null && \$canManageEvents === true;$/m', '$seesWorkingNotes = true;', 1, 'the working-notes gate');
    $m = $readModerated();
    check('P10 with the gate never applied, the member, the read-only key and the write key are sent all seven, and the note',
        [$m['member']['fields'], $m['readKey']['fields'], $m['writeKey']['fields'], $m['member']['noteAnywhere']],
        [P968_MANAGERS_ONLY, P968_MANAGERS_ONLY, P968_MANAGERS_ONLY, true]);
    $restore968();
    $plant968($detailPath, '/^\$seesWorkingNotes = \$apiKeyRow === null && \$canManageEvents === true;$/m', '$seesWorkingNotes = false;', 1, 'the working-notes gate');
    $m = $readModerated();
    check('P11 with the gate applied to everybody, both kinds of administrator lose all seven, at both addresses (M5 would have gone red)',
        [$m['admin']['fields'], $m['adminV1']['fields'], $m['siteadmin']['fields']], [[], [], []]);
    $restore968();
    $plant968($detailPath, '/^\$seesWorkingNotes = \$apiKeyRow === null && \$canManageEvents === true;$/m', '$seesWorkingNotes = $canManageEvents === true;', 1, 'the working-notes gate');
    $m = $readModerated();
    check('P11b with round 1\'s rule back ("a write key may manage events, so it gets them"), the write key is sent all seven at both addresses (M2-writeKey would have gone red; D-136)',
        [$m['writeKey']['fields'], $m['writeKeyV1']['fields'], $m['readKey']['fields'], $m['admin']['fields']],
        [P968_MANAGERS_ONLY, P968_MANAGERS_ONLY, [], P968_MANAGERS_ONLY]);
    $restore968();

    // (f) `e.*` put back in the event page and all three download lookups:
    //     the code as it was before #968. Every page and file must come out
    //     IDENTICAL to section R's — the named lists lost nothing. (Each
    //     plant rewrites only its own file, so the page's stays planted while
    //     the download's is planted.)
    $plant968(
        $eventPath,
        '/\'SELECT `e`\.`eventID`, `e`\.`siteID`,.*?`ef`\.`name` AS `importedFrom`, %s \'/s',
        "'SELECT e.*, c.categoryName, t.typeName, s.seriesName, s.seriesSlug, ef.name AS importedFrom, %s '",
        1,
        'the event page\'s column list'
    );
    $plant968(
        $exportPath,
        '/\'SELECT `e`\.`eventID`, `e`\.`seriesID`,.*?`e`\.`updatedAt`, %s \'\s*\. /s',
        "'SELECT e.*, %s ' . ",
        3,
        'the download\'s three column lists'
    );
    $before = $renderAll();
    $checkRenders('P12-', $before);
    check('P13 with `e.*` back (the code before #968), every page and file is identical to the named-column version — with every column filled, a column read but not listed would differ here, however the code reaches it',
        $rendersDiffer($rClean, $before), []);
    if ($rendersDiffer($rClean, $before) !== []) {
        echo '      first difference: ' . $firstDifference($rClean, $before, $rendersDiffer($rClean, $before)) . "\n";
    }
    $restore968();

    // (g) A list one column too SHORT must show: the telephone left off the
    //     page's list, the description off the download's.
    $plant968($eventPath, '/`e`\.`locationPhone`, /', '', 1, 'the event page\'s telephone column');
    $short = $renderAll();
    check('P14 with the telephone left off the page\'s list, the public event\'s page differs (and nothing else)',
        [in_array('visitor page public', $rendersDiffer($rClean, $short), true), $shown($short, 'visitor page public', '01234 567890'),
         array_values(array_filter($rendersDiffer($rClean, $short), static fn (string $k): bool => str_contains($k, ' file ')))],
        [true, false, []]);
    $restore968();
    // (g3) Round 3 of the check: the phone taken off the page's list AND read
    //      by a spelling section C's round-2 tripwire did not know —
    //      `call_user_func('compact', 'event')`, then `namespace\compact()`.
    //      Section R must go red whatever the spelling, because it compares
    //      what the page SHOWS with `e.*` put back. (Section C now trips on
    //      both too, but it is the tripwire; R is the guarantee.)
    $plantMany = static function (string $path, array $edits) use ($originals968, $startServer, $waitForServer): void {
        $text = $originals968[$path];
        foreach ($edits as [$find, $with, $times]) {
            if (substr_count($text, $find) !== $times) {
                refuse('section P (#968, R) found ' . substr_count($text, $find) . " copies of '{$find}' where it expected {$times}");
            }
            $text = str_replace($find, $with, $text);
        }
        file_put_contents($path, $text);
        clearstatcache(true, $path);
        if ((string) file_get_contents($path) !== $text) {
            refuse('section P (#968, R) could not read back a plant');
        }
        $startServer();
        $waitForServer();
    };
    foreach (["call_user_func('compact', 'event')" => 'P14c', "namespace\\compact('event')" => 'P14d'] as $spelling => $label) {
        $edits = [
            ['`e`.`locationPhone`, ', '', 1],
            ["\$hasLocationInfo = (\$event['locationName'] ?? '') !== ''", "\$p968c = {$spelling}; \$hasLocationInfo = (\$event['locationName'] ?? '') !== ''", 1],
            ["\$event['locationPhone']", "\$p968c['event']['locationPhone']", 3],
        ];
        $plantMany($eventPath, $edits);
        $short = $renderAll();
        check("{$label} with the phone off the page's list and read through {$spelling}, section R sees the page change (the phone is gone)",
            [in_array('visitor page public', $rendersDiffer($rClean, $short), true), $shown($short, 'visitor page public', '01234 567890')],
            [true, false]);
        $planted = $originals968[$eventPath];
        foreach ($edits as [$find, $with]) {
            $planted = str_replace($find, $with, $planted);
        }
        $c = $columnsCheck($planted, $originals968[$exportPath]);
        check("{$label}-C ...and section C's tripwire now names that spelling too", $c['pageIndirect'] !== [], true);
        $restore968();
    }

    // (g4) EVERY column in each list, taken off ONE AT A TIME: section R must
    //      see each one go. This is what makes "R is the guarantee" a measured
    //      statement rather than a hope: a listed column whose absence R cannot
    //      see is one R could not see missing either. The page's list is
    //      planted once per column; the download's three lists together.
    $sweep = static function (string $path, string $listStart, string $listEnd, int $lists) use ($originals968, $plantMany, $renderAll, $rendersDiffer, $rClean, $restore968): array {
        $text  = $originals968[$path];
        $at    = strpos($text, $listStart);
        $end   = $at === false ? false : strpos($text, $listEnd, $at);
        if ($at === false || $end === false) {
            refuse('section P (#968, sweep) could not find a column list');
        }
        preg_match_all('/`e`\.`(\w+)`, /', substr($text, $at, $end - $at), $m);
        $unseen = [];
        foreach (array_unique($m[1]) as $column) {
            $plantMany($path, [['`e`.`' . $column . '`, ', '', $lists]]);
            if ($rendersDiffer($rClean, $renderAll()) === []) {
                $unseen[] = $column;
            }
            $restore968();
        }
        return ['tried' => count(array_unique($m[1])), 'unseen' => $unseen];
    };
    $pageSweep = $sweep($eventPath, "'SELECT `e`.`eventID`, `e`.`siteID`", 'FROM `tblEvents`', 1);
    echo '      (the page: ' . $pageSweep['tried'] . ' columns tried, not seen when missing: ' . json_encode($pageSweep['unseen']) . ")\n";
    check('P26 every column of the event page\'s list, taken off on its own, changes what section R sees',
        [$pageSweep['tried'] >= 25, $pageSweep['unseen']], [true, []]);
    $fileSweep = $sweep($exportPath, "'SELECT `e`.`eventID`, `e`.`seriesID`", 'FROM `tblEvents`', 3);
    echo '      (the download: ' . $fileSweep['tried'] . ' columns tried, not seen when missing: ' . json_encode($fileSweep['unseen']) . ")\n";
    check('P27 every column of the download\'s lists, taken off all three at once, changes what section R sees',
        [$fileSweep['tried'] >= 14, $fileSweep['unseen']], [true, []]);

    // (g2) The round-1 fault itself: `locationW3W` off the page's list. R2b
    //      must go red — the link gone from the public event, and the
    //      what3words-only event's whole "Where" card gone.
    $plant968($eventPath, '/`e`\.`locationW3W`, /', '', 1, 'the event page\'s what3words column');
    $short = $renderAll();
    check('P14b with locationW3W off the page\'s list (round 1\'s finding), the link and the what3words-only "Where" card are gone',
        [$shown($short, 'visitor page public', '///filled.count.soap'), $shown($short, 'visitor page w3wOnly', '///index.home.raft'),
         $shown($short, 'member page w3wOnly', '>Where</h5>')],
        [false, false, false]);
    $restore968();
    $plant968($exportPath, '/`e`\.`description`, /', '', 3, 'the download\'s description column');
    $short = $renderAll();
    check('P15 with the description left off the download\'s lists, the files differ (and no page does)',
        [in_array('visitor file public', $rendersDiffer($rClean, $short), true),
         array_values(array_filter($rendersDiffer($rClean, $short), static fn (string $k): bool => str_contains($k, ' page ')))],
        [true, []]);
    $restore968();

    // (h) Section C's plants. These change TEXT handed to the check, not the
    //     server, so nothing is restarted; each is proved applied by its count.
    $realEvent  = $originals968[$eventPath];
    $realExport = $originals968[$exportPath];
    $swap = static function (string $src, string $find, string $with, int $times, string $what): string {
        if (substr_count($src, $find) !== $times) {
            refuse("section P (#968, C) found " . substr_count($src, $find) . " copies of {$what} where it expected {$times}");
        }
        return str_replace($find, $with, $src);
    };
    $c = $columnsCheck($swap($realEvent, '`e`.`locationW3W`, ', '', 1, 'locationW3W in the page list'), $realExport);
    check('P17 with locationW3W off the page\'s list (the round-1 fault), C2 names it', $c['pageMissing'], ['locationW3W']);
    $c = $columnsCheck($swap($realEvent, '`e`.`cancelReason`, ', '', 1, 'cancelReason in the page list'), $realExport);
    check('P18 with cancelReason off the page\'s list, C2 names it', $c['pageMissing'], ['cancelReason']);
    $c = $columnsCheck($swap($realEvent, '`e`.`status`, ', '`e`.`status`, `e`.`isPublic`, ', 1, 'status in the page list'), $realExport);
    check('P19 with a column selected that the page never reads (isPublic), C3 names it', $c['pageUnread'], ['isPublic']);
    $c = $columnsCheck($swap($realEvent, '$pageTitle   = $event[\'eventName\'];', '$pageTitle   = $event[\'eventName\']; $copy = $event; $k = \'x\'; $v = $event[$k];', 1, 'the page title line'), $realExport);
    check('P20 with the row copied to another name and read by a key built at run time, C4 reports both',
        [count($c['pageBare']) === 1, $c['pageRuntime']], [true, ['$event[$k]']]);
    $c = $columnsCheck($realEvent, $swap($realExport, '`e`.`status`, `e`.`updatedAt`, %s \'' . "\n" . '        . \'FROM `tblEvents` `e` WHERE `e`.`eventID`', '`e`.`status`, %s \'' . "\n" . '        . \'FROM `tblEvents` `e` WHERE `e`.`eventID`', 1, 'updatedAt in the single-event download list'));
    check('P21 with updatedAt off ONE of the download\'s three lists, C5 says they differ and C6 names it',
        [$c['fileSame'], $c['fileMissing']], [false, ['updatedAt']]);
    $c = $columnsCheck($realEvent, $swap($realExport, "\$location = trim((string) (\$ev['locationName'] ?? ''));", "\$location = trim((string) (\$ev['locationName'] ?? '') . (\$ev['locationW3W'] ?? ''));", 1, 'the download\'s location line'));
    check('P22 with the download reading a column none of its lists has (locationW3W, a name with a digit), C6 names it',
        $c['fileMissing'], ['locationW3W']);

    // (i) Round 2 of #968's check: anything in a SELECT list that is not a
    //     backticked column was invisible. Each of three forms, planted into
    //     each of the FOUR lists in turn (the page's, then the download's
    //     first, second and third), must be named by C1b or C5b.
    $nth = static function (string $src, string $find, string $with, int $which): string {
        $at = -1;
        for ($i = 0; $i < $which; $i++) {
            $at = strpos($src, $find, $at + 1);
            if ($at === false) {
                refuse("section P (#968, C) could not find copy {$which} of the download's list start");
            }
        }
        return substr($src, 0, $at) . $with . substr($src, $at + strlen($find));
    };
    $pageStart = "'SELECT `e`.`eventID`, `e`.`siteID`, ";
    $fileStart = "'SELECT `e`.`eventID`, `e`.`seriesID`, ";
    $listPlants = 0;
    foreach (['e.*', '`e`.*', 'e.locationEmail'] as $form) {
        $c = $columnsCheck($swap($realEvent, $pageStart, $pageStart . "{$form}, ", 1, 'the page list start'), $realExport);
        check("P23-page {$form} added to the event page's list, C1b names it", $c['pageLeftover'], [$form]);
        $listPlants++;
        for ($which = 1; $which <= 3; $which++) {
            $c = $columnsCheck($realEvent, $nth($realExport, $fileStart, $fileStart . "{$form}, ", $which));
            check("P23-file{$which} {$form} added to the download's list number {$which}, C5b names it", $c['fileLeftover'], [$form]);
            $listPlants++;
        }
    }
    check('P23 ...all twelve (three forms in four lists) were planted', $listPlants, 12);

    // (j) Round 2: reaching the row without naming it walked past the pins.
    $titleLine = "\$pageTitle   = \$event['eventName'];";
    foreach ([
        "\$nm = 'event'; \$leak = \$\$nm['submitterEmail'];" => '$$nm',
        "\$leak = \${'event'}['submitterEmail'];"            => "\${'event'}",
        "\$leak = compact('event');"                         => "compact('event')",
        "\$leak = \\extract(\$GLOBALS);"                     => 'extract($GLOBALS)',
    ] as $added => $label) {
        $c = $columnsCheck($swap($realEvent, $titleLine, $titleLine . ' ' . $added, 1, 'the page title line'), $realExport);
        check("P24 with {$label} on the event page, C4b reports it", $c['pageIndirect'] !== [], true);
    }
    $c = $columnsCheck($realEvent, $swap($realExport, "\$location = trim((string) (\$ev['locationName'] ?? ''));",
        "\$location = trim((string) (\$ev['locationName'] ?? '')); \$leak = compact('ev');", 1, 'the download\'s location line'));
    check('P24-file with compact(\'ev\') in the download, C7b reports it', $c['fileIndirect'] !== [], true);

    // (k) Round 2: the shapes were de-duplicated, so a second identical fetch
    //     into `$event` was invisible. Now their COUNT is pinned.
    $fetch = "\$event = \$stmt->get_result()->fetch_assoc();";
    $c = $columnsCheck($swap($realEvent, $fetch, $fetch . "\n    " . $fetch, 1, 'the page\'s fetch'), $realExport);
    check('P25 with a second identical fetch into $event, C4 reports the shape found twice where once is pinned',
        $c['pageBare'], [') ; <$event> = $stmt ->: found 2, pinned 1']);

    $mAfter = $readModerated();
    check('P16 with every #968 plant taken out, the member and the read-only key are sent none of the seven again',
        [$mAfter['member']['fields'], $mAfter['readKey']['fields'], $mAfter['admin']['fields']], [[], [], P968_MANAGERS_ONLY]);
}

echo "\n" . ($failed === 0 ? 'PASS' : 'FAIL') . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
