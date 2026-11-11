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
 * #968 ADDED TWO SECTIONS AND THEIR PLANTS
 *   M  the detail answer's working notes: `moderationNote` and the account
 *      numbers of whoever created, edited, suggested, moderated and last
 *      changed the status of the event, and `deletedAt`. A published,
 *      members-only event with every one filled in by hand (nothing in the
 *      portal writes `moderationNote` today) is read by an ordinary member, a
 *      key holding only `events:read` (both addresses), a key holding
 *      `events:write`, a global administrator and an administrator of this
 *      organisation only. The first three get NONE of the seven fields and
 *      the note nowhere in the answer; the last three get all seven, with the
 *      note as written and every account number the right one. Measured
 *      against f9197eb9 (the code before #968): six red — the member and the
 *      read-only key at both addresses were sent all seven, the note included.
 *   R  the event page (`/calendar/event`) and the calendar download
 *      (`/calendar/export`: one event, a series, the whole calendar) now
 *      name their columns instead of reading the whole row with `e.*`. Each
 *      is fetched for a public, a members-only, an all-day, a cancelled, a
 *      fully-shown imported, a briefly-shown imported and a repeating event,
 *      by a visitor, a member and an administrator; the right words must be
 *      there, the visitor's name and address and the note must be nowhere,
 *      and the same code fetched twice must come out identical once the
 *      random tokens are taken out (R8 — without it P13 would prove nothing).
 *   P9-P16  each of the seven field names taken off the detail handler's
 *      list, one at a time (the member and the read-only key are then sent
 *      exactly that field); the gate switched off both ways; `e.*` put back
 *      in the page and all three download lookups — every page and file must
 *      come out IDENTICAL to section R's, which is the before-and-after
 *      comparison; and a list one column too short, on each page, must show.
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
 *     needs a column none of them exercises would not be caught by P13.
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
// handler treats as "may manage events" for a key (it can already create,
// edit, delete and read the drafts of every event), so it is the one key
// that should still be sent the moderation fields.
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

/** The fields only a caller who may manage events is sent (detail.php, #968). */
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
        'readKey'   => $fieldsSeen($http('GET', '/api/events/detail?id=' . $moderated, [], ['bearer' => $apiKey])),
        'readKeyV1' => $fieldsSeen($http('GET', '/api/v1/events/' . $moderated, [], ['bearer' => $apiKey])),
        'writeKey'  => $fieldsSeen($http('GET', '/api/events/detail?id=' . $moderated, [], ['bearer' => $writeKey])),
        'admin'     => $fieldsSeen($http('GET', '/api/events/detail?id=' . $moderated, [], ['jar' => 'admin'])),
        'siteadmin' => $fieldsSeen($http('GET', '/api/events/detail?id=' . $moderated, [], ['jar' => 'siteadmin'])),
    ];
};

$wantIds = [
    $people['admin']['id'], $people['siteadmin']['id'], $people['member']['id'],
    $people['admin']['id'], $people['siteadmin']['id'],
];
$checkModerated = static function (string $prefix, array $m) use ($wantIds): void {
    echo "{$prefix}. The moderation note and the \"who did what\" numbers (#968)\n";
    foreach (['member' => 'an ordinary member, signed in', 'readKey' => 'a key holding only events:read',
              'readKeyV1' => 'the same key at /api/v1/events/{id}'] as $k => $who) {
        echo "      ({$who} was sent: " . json_encode($m[$k]['fields']) . ")\n";
        check("{$prefix}1-{$k} {$who} reads THIS event", $m[$k]['isTheEvent'], true);
        check("{$prefix}2-{$k} ...with none of the seven fields", $m[$k]['fields'], []);
        check("{$prefix}3-{$k} ...and the note nowhere in the answer", $m[$k]['noteAnywhere'], false);
    }
    foreach (['admin' => 'a global administrator, signed in', 'siteadmin' => 'an administrator of this organisation only',
              'writeKey' => 'a key holding events:write'] as $k => $who) {
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
// be too SHORT, which shows only as a blank on the page, so this renders both
// for a spread of events, to a visitor, a member and an administrator — and
// section P renders them again with `e.*` put back and requires every answer
// to be identical. Every event below also carries a visitor's name and
// address and a moderation note, which must appear in none of the answers.
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
$private = [
    'submitterName'  => VISITOR_NAME,
    'submitterEmail' => VISITOR_EMAIL,
    'moderationNote' => P968_NOTE,
];
$day = static fn (int $plus, string $time): string => $r0->modify('+' . $plus . ' days')->format('Y-m-d') . ' ' . $time;
$rEvents = [
    'public' => $p968Event($private + [
        'eventName' => 'P968 public open day', 'isPublic' => 1, 'isFeatured' => 1,
        'startDateTime' => $day(1, '10:00:00'), 'endDateTime' => $day(1, '16:00:00'),
        'description' => "Come and see.\nBring a friend.", 'categoryID' => 1, 'typeID' => 1,
        'locationName' => 'P968 Hall', 'locationAddress' => "1 Test Street\nTestville TE1 1ST",
        'locationWebURL' => 'https://hall.selftest.invalid/', 'locationPhone' => '01234 567890',
        'locationGeoLat' => '52.2053000', 'locationGeoLng' => '0.1218000',
        'hostOrgName' => 'P968 Host Trust', 'partnerOrgs' => '["P968 Partner A","P968 Partner B"]', 'capacity' => 40,
    ]),
    'members' => $p968Event($private + [
        'eventName' => 'P968 members meeting', 'isPublic' => 0,
        'startDateTime' => $day(2, '19:30:00'), 'endDateTime' => $day(2, '21:00:00'),
        'description' => 'For members.', 'locationName' => 'P968 Vestry',
    ]),
    'allday' => $p968Event($private + [
        'eventName' => 'P968 all day retreat', 'isPublic' => 1, 'isAllDay' => 1,
        'startDateTime' => $day(3, '00:00:00'), 'endDateTime' => $day(3, '23:59:59'), 'timezone' => 'Europe/London',
    ]),
    'cancelled' => $p968Event($private + [
        'eventName' => 'P968 cancelled concert', 'isPublic' => 1, 'status' => 'cancelled',
        'cancelReason' => 'P968 the roof leaks', 'statusChangedAt' => '2031-07-30 12:00:00',
        'startDateTime' => $day(4, '18:00:00'), 'endDateTime' => $day(4, '20:00:00'),
    ]),
    'imported' => $p968Event($private + [
        'eventName' => 'P968 copied in', 'isPublic' => 0,
        'startDateTime' => $day(5, '09:00:00'), 'endDateTime' => $day(5, '10:00:00'),
        'description' => 'From an outside calendar.', 'locationName' => 'P968 Elsewhere',
        'externalFeedID' => $feed, 'externalUid' => 'p968-full@selftest.invalid',
        'importLevel' => 'public', 'importDetail' => 'full', 'importSource' => 'calendar', 'importApiOptOut' => 0,
    ]),
    'importedBasic' => $p968Event($private + [
        'eventName' => 'P968 copied in briefly', 'isPublic' => 0,
        'startDateTime' => $day(6, '09:00:00'), 'endDateTime' => $day(6, '10:00:00'),
        'description' => 'Only its audience sees this.', 'locationName' => 'P968 Somewhere',
        'externalFeedID' => $feedMembers, 'externalUid' => 'p968-basic@selftest.invalid',
        'importLevel' => 'public', 'importDetail' => 'basic', 'importSource' => 'calendar', 'importApiOptOut' => 0,
    ]),
];
// The identity of an imported row is the SHA-256 of its UID, stored as bytes.
$db->query("UPDATE `tblEvents` SET `externalUidHash` = UNHEX(SHA2(`externalUid`, 256)) WHERE `externalFeedID` IN ({$feed}, {$feedMembers})");
for ($w = 0; $w < 3; $w++) {
    $rEvents['series' . $w] = $p968Event($private + [
        'eventName' => 'P968 Tuesday study', 'isPublic' => 1, 'seriesID' => $series,
        'startDateTime' => $r0->modify('+' . (7 * $w) . ' days')->format('Y-m-d') . ' 19:00:00',
        'endDateTime'   => $r0->modify('+' . (7 * $w) . ' days')->format('Y-m-d') . ' 20:00:00',
        'description'   => 'Weekly.', 'locationName' => 'P968 Library',
    ]);
}
$slugOf = [];
$q = $db->query("SELECT `eventID`, `eventSlug` FROM `tblEvents` WHERE `eventName` LIKE 'P968 %'");
while ($row = $q->fetch_assoc()) {
    $slugOf[(int) $row['eventID']] = (string) $row['eventSlug'];
}

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
    $out = [];
    foreach (['visitor' => 'r-visitor', 'member' => 'member', 'admin' => 'admin'] as $who => $jar) {
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
$rAgain = $renderAll();
check('R8 the same code rendered twice comes out identical once tokens are taken out (so a difference in section P is real)',
    $rendersDiffer($rClean, $rAgain), []);
if ($rendersDiffer($rClean, $rAgain) !== []) {
    echo '      first difference: ' . $firstDifference($rClean, $rAgain, $rendersDiffer($rClean, $rAgain)) . "\n";
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

    // (d) Each field put back, one at a time: the member and the read-only key
    //     must then be sent exactly that field (M2 would have gone red).
    foreach (P968_MANAGERS_ONLY as $field) {
        $plant968($detailPath, '/^    \'' . preg_quote($field, '/') . '\',\n/m', '', 1, "the {$field} line of the managers-only list");
        $m = $readModerated();
        check("P9-{$field} with {$field} left off the list, the member and the read-only key are sent it",
            [$m['member']['fields'], $m['readKey']['fields'], $m['readKeyV1']['fields']], [[$field], [$field], [$field]]);
        $restore968();
    }

    // (e) The gate itself switched off, both ways.
    $plant968($detailPath, '/if \(\$canManageEvents === false\) \{\n    foreach \(\$forManagersOnly/', "if (false) {\n    foreach (\$forManagersOnly", 1, 'the managers-only gate');
    $m = $readModerated();
    check('P10 with the gate never applied, the member and the read-only key are sent all seven, and the note',
        [$m['member']['fields'], $m['readKey']['fields'], $m['member']['noteAnywhere']], [P968_MANAGERS_ONLY, P968_MANAGERS_ONLY, true]);
    $restore968();
    $plant968($detailPath, '/if \(\$canManageEvents === false\) \{\n    foreach \(\$forManagersOnly/', "if (true) {\n    foreach (\$forManagersOnly", 1, 'the managers-only gate');
    $m = $readModerated();
    check('P11 with the gate applied to everybody, the administrators and the write key lose all seven (M5 would have gone red)',
        [$m['admin']['fields'], $m['siteadmin']['fields'], $m['writeKey']['fields']], [[], [], []]);
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
    check('P13 with `e.*` back (the code before #968), every page and file is identical to the named-column version',
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
    $plant968($exportPath, '/`e`\.`description`, /', '', 3, 'the download\'s description column');
    $short = $renderAll();
    check('P15 with the description left off the download\'s lists, the files differ (and no page does)',
        [in_array('visitor file public', $rendersDiffer($rClean, $short), true),
         array_values(array_filter($rendersDiffer($rClean, $short), static fn (string $k): bool => str_contains($k, ' page ')))],
        [true, []]);
    $restore968();

    $mAfter = $readModerated();
    check('P16 with every #968 plant taken out, the member and the read-only key are sent none of the seven again',
        [$mAfter['member']['fields'], $mAfter['readKey']['fields'], $mAfter['admin']['fields']], [[], [], P968_MANAGERS_ONLY]);
}

echo "\n" . ($failed === 0 ? 'PASS' : 'FAIL') . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
