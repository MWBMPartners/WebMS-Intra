<?php
// Path: tools/event-address-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Every way an event is created gets a free web address 🔤🧪 (#883)
 * -----------------------------------------------------------------------------
 * An event's web address (`tblEvents.eventSlug`) must be unique in its
 * organisation — `uq_event_site_slug (siteID, eventSlug)` — and that key
 * counts DELETED events too: a deleted event keeps its address for ever.
 *
 * Until #883 four places made the address, each with its own code, and the
 * events API made it from the NAME ALONE, so a second event created through
 * the API with a name any event had ever had was refused by the database as a
 * bare HTTP 500 (`api-spec.json` even said so). #877 fixed the calendar file
 * import; #883 moved the API, the Manage Events form (and the dates of a
 * repeating event it makes), the series pattern page and the public
 * suggestion form onto ONE helper, `Events::saveUnderFreeSlug()`, which asks
 * the database for the first free address and asks again if another writer
 * takes it between the question and the save.
 *
 * This test drives each REAL path over HTTP and reads the database back:
 *
 *   A  the events API with an API KEY: the same name on the same day twice,
 *      and on another day — every one 201, each at its own address
 *   B  the events API SIGNED IN (a session and the form token): the same
 *   C  a DELETED event holding the address: the API, the form and the
 *      suggestion form each save at the next free address, never refused
 *   D  the Manage Events form: a repeated name, and a weekly series whose
 *      every date gets its OWN date in its address, asked of the database —
 *      with deleted events holding both the new and the OLD shape of its
 *      second date's address (the old code ran into the old one and that
 *      date was not saved)
 *   E  the public suggestion form: the same name twice, both saved
 *   F  the series pattern page: a date it adds whose address a deleted event
 *      holds is saved at `-2`, and the change is not rolled back
 *   R  A REAL SECOND WRITER, for the API, the form and the series page: this
 *      test holds the address in an OPEN TRANSACTION on its own connection,
 *      sends the real request in the background, CONFIRMS (asked of the
 *      database, never assumed from a sleep) that the request's INSERT is
 *      waiting on that address, then commits. The request must ask again and
 *      save at `-2`. The series page writes inside one transaction, where
 *      "is it free" reads an old snapshot that cannot see the committed row —
 *      the case `freeSlug()`'s list of refused addresses exists for.
 *   P  THE PLANTS, each in the CHILD copy of the code, each proved applied:
 *      one save attempt instead of three (every real race goes red); no list
 *      of refused addresses (the series page's race goes red, the API's does
 *      not — that is the point of the list); the address refusal no longer
 *      recognised (the API's race becomes a 500); and the events API's old
 *      name-only stem put back (section A's addresses lose their date). The
 *      duplicate-name 500 itself is measured against the old code (below).
 *
 * MEASURING THE CODE BEFORE #883. Set SELFTEST_WEB_FROM to a folder holding
 * an older `web/` (for example `git archive <commit> web | tar -x -C <dir>`)
 * and the same scenarios run against it; the plants are then skipped, because
 * they patch lines only the new code has. That is how "before" was measured
 * for the commit; it is expected to be red.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It runs ONE web worker. Two administrators pressing Save at once is not
 *     two requests here; what matters to the address is the ORDER of two
 *     things (another row arriving between asking and saving), and section R
 *     makes exactly that order happen with one request and one held
 *     transaction, and checks the premise first.
 *   - The calendar file import is not driven here: it has its own loop and
 *     its own test (`tools/calendar-import-selftest.php`, section R).
 *   - It does not exhaust the fifty numbered addresses to reach the random
 *     ending; that branch is read, not driven.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/event-address-selftest.php
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
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/883
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'db' => null, 'user' => 0, 'key' => 0];

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

// Where the portal's code comes from: this repository, or an older copy when
// measuring the code before #883 (see the header).
$webFrom   = getenv('SELFTEST_WEB_FROM') !== false && getenv('SELFTEST_WEB_FROM') !== ''
    ? rtrim((string) getenv('SELFTEST_WEB_FROM'), '/\\') : $repo . $sep . 'web';
$measuring = $webFrom !== $repo . $sep . 'web';
if (is_file($webFrom . $sep . '_core' . $sep . 'Events.php') === false) {
    refuse("SELFTEST_WEB_FROM ({$webFrom}) does not hold a portal web/ folder");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$state['db'] = $db;

$tables = (int) ($db->query(
    'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

$siteId = (int) ($db->query('SELECT MIN(`siteID`) AS s FROM `tblSites`')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation');
}
const ZONE = 'Europe/London';

/** Remove every event this test makes; the names all begin "P883 ". */
$clearEvents = static function () use ($db): void {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P883 %'");
    $db->query("DELETE FROM `tblEventSeries` WHERE `seriesName` LIKE 'P883 %'");
};

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'event-address-' . bin2hex(random_bytes(6));
$state['child'] = $child;

register_shutdown_function(static function () use (&$state, $clearEvents): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['db'] instanceof mysqli) {
        try {
            // The series rule rows go with their series (ON DELETE CASCADE).
            $state['db']->query("DELETE r FROM `tblRecurrenceRules` r JOIN `tblEventSeries` s ON s.`seriesID` = r.`seriesID` WHERE s.`seriesName` LIKE 'P883 %'");
            $clearEvents();
            if ($state['key'] > 0) {
                $state['db']->query('DELETE FROM `tblApiKeys` WHERE `keyID` = ' . (int) $state['key']);
            }
        } catch (Throwable $e) {
            echo "  ! could not remove this test's events or API key; remove rows named 'P883 %' by hand.\n";
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
    "<?php\n// Written by tools/event-address-selftest.php for ONE run.\nreturn "
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
// Written by tools/event-address-selftest.php for ONE run. Test only.
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
$port = 18830 + random_int(0, 150);
$jar  = $child . $sep . 'cookies.txt';

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
 * The options for one request: redirects NOT followed (a redirect is something
 * the caller sees), the cookie jar unless a bearer key is sent instead.
 *
 * @param array{bearer?:string, json?:bool} $o
 */
$curlFor = static function (string $method, string $path, array|string $post, array $o) use ($port, $jar) {
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:', 'Accept-Language: en'];
    if (($o['bearer'] ?? '') !== '') {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    if (($o['json'] ?? false) === true) {
        $headers[] = 'Content-Type: application/json';
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
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, is_array($post) === true ? http_build_query($post) : $post);
    }
    return $handle;
};

/** Read a finished handle's answer. */
$answerOf = static function ($handle, string $raw): array {
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr($raw, 0, $headSize);
    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $m) === 1 ? $m[1] : '',
        'body'     => substr($raw, $headSize),
    ];
};

/** One request. */
$http = static function (string $method, string $path, array|string $post = [], array $o = []) use ($curlFor, $answerOf): array {
    $handle = $curlFor($method, $path, $post, $o);
    $raw    = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    return $answerOf($handle, (string) $raw);
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

// ── The administrator and the API key ───────────────────────────────────────
$email      = 'event.address@selftest.invalid';
$signInName = 'selftest-event-address';
$password   = 'Sel' . bin2hex(random_bytes(8)) . '!9x';

/** Remove an earlier run's administrator and everything that names them. */
$removeAdmin = static function (int $who) use ($db): void {
    $db->query("DELETE FROM `tblEvents` WHERE `createdByID` = {$who} OR `submittedByID` = {$who}");
    $rs = $db->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers'"
    );
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . '` WHERE `' . $row['COLUMN_NAME'] . "` = {$who}");
        } catch (Throwable $e) {
            // A table whose own children still name the row.
        }
    }
    $rs->free();
    try {
        $db->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
    } catch (Throwable $e) {
        refuse("an earlier run's test administrator (#{$who}) could not be removed");
    }
};
$old = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
$old->bind_param('s', $email);
$old->execute();
foreach ($old->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $removeAdmin((int) $row['userID']);
}
$old->close();
$db->query("DELETE FROM `tblApiKeys` WHERE `name` = 'P883 event address key'");
$clearEvents();

$ins = $db->prepare(
    'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) '
    . "VALUES ('Event Address Admin', ?, 1, 1, 1, NOW())"
);
$ins->bind_param('s', $email);
$ins->execute();
$userId = (int) $db->insert_id;
$ins->close();
$state['user'] = $userId;
$db->query(
    'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) '
    . "VALUES ({$siteId}, {$userId}, 1, 1, 1)"
);
$hash = password_hash($password, PASSWORD_DEFAULT);
$acc  = $db->prepare(
    'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)'
);
$acc->bind_param('iss', $userId, $signInName, $hash);
$acc->execute();
$acc->close();

$apiKey = 'wbms_' . bin2hex(random_bytes(16));
$keyIns = $db->prepare(
    'INSERT INTO `tblApiKeys` (`siteID`, `name`, `keyHash`, `keyPrefix`, `scopes`, `isActive`, `createdByID`) '
    . "VALUES (?, 'P883 event address key', ?, ?, 'events:read,events:write', 1, NULL)"
);
$keyHash   = hash('sha256', $apiKey);
$keyPrefix = substr($apiKey, 0, 12);
$keyIns->bind_param('iss', $siteId, $keyHash, $keyPrefix);
$keyIns->execute();
$state['key'] = (int) $db->insert_id;
$keyIns->close();

$signIn = static function () use ($http, $tokenOn, $signInName, $password, $jar): void {
    @unlink($jar);
    $token = $tokenOn($http('GET', '/login')['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => $signInName, 'password' => $password]);
    if (($in['status'] !== 302 && $in['status'] !== 303) || str_contains($in['location'], 'login') === true) {
        refuse('the test administrator could not sign in (status ' . $in['status'] . ')');
    }
};
$signIn();

// ── The ways in ─────────────────────────────────────────────────────────────

/** The body the events API is sent for one event. */
$apiBody = static fn (string $name, string $start): array => [
    'eventName'     => $name,
    'startDateTime' => $start,
    'timezone'      => ZONE,
    'status'        => 'published',
];

/** Create through the events API with the key. Answers [status, slug or ''] — plus the raw body. */
$apiKeyCreate = static function (string $name, string $start) use ($http, $apiKey, $apiBody): array {
    $r    = $http('POST', '/api/events/create', (string) json_encode($apiBody($name, $start)), ['bearer' => $apiKey, 'json' => true]);
    $json = json_decode($r['body'], true);
    return [
        'status' => $r['status'],
        'slug'   => is_array($json) === true ? (string) ($json['data']['eventSlug'] ?? '') : '',
        'body'   => $r['body'],
    ];
};

/** Create through the events API signed in: the form token goes in the body. */
$apiSessionCreate = static function (string $name, string $start) use ($http, $tokenOn, $apiBody): array {
    $token = $tokenOn($http('GET', '/calendar/manage')['body']);
    $body  = $apiBody($name, $start) + ['csrf_token' => $token];
    $r     = $http('POST', '/api/events/create', (string) json_encode($body), ['json' => true]);
    $json  = json_decode($r['body'], true);
    return [
        'status' => $r['status'],
        'slug'   => is_array($json) === true ? (string) ($json['data']['eventSlug'] ?? '') : '',
        'body'   => $r['body'],
    ];
};

/** The coloured message the Manage Events page shows after a save. */
$flashOn = static function (string $html): string {
    if (preg_match('/<div class="alert alert-(\w+) alert-dismissible[^"]*">(.*?)<button/s', $html, $m) !== 1) {
        return '(no message shown)';
    }
    return $m[1] . ': ' . trim((string) preg_replace('/\s+/', ' ', strip_tags($m[2])));
};

/** The fields the Manage Events form posts for one event. */
$formFields = static fn (string $token, string $name, string $start, string $end, array $extra): array => array_merge([
    'csrf_token'    => $token,
    'action'        => 'create',
    'eventName'     => $name,
    'description'   => 'Made by tools/event-address-selftest.php.',
    'startDateTime' => $start,
    'endDateTime'   => $end,
    'timezone'      => ZONE,
    'status'        => 'published',
    'locationName'  => 'The selftest hall',
], $extra);

/** Create through the Manage Events form. Answers the redirect status and the message. */
$formCreate = static function (string $name, string $start, string $end, array $extra = []) use ($http, $tokenOn, $flashOn, $formFields): array {
    $token = $tokenOn($http('GET', '/calendar/manage')['body']);
    $sent  = $http('POST', '/calendar/manage/save', $formFields($token, $name, $start, $end, $extra));
    return ['status' => $sent['status'], 'message' => $flashOn($http('GET', '/calendar/manage')['body'])];
};

/** Suggest an event through the public form, signed in (so no anti-robot check). */
$suggest = static function (string $name, string $start) use ($http, $tokenOn): array {
    $token = $tokenOn($http('GET', '/calendar/submit')['body']);
    $sent  = $http('POST', '/calendar/submit-save', [
        'csrf_token'    => $token,
        'eventName'     => $name,
        'startDateTime' => $start,
        'endDateTime'   => '',
        'locationName'  => '',
        'description'   => 'Suggested by tools/event-address-selftest.php.',
    ]);
    return ['status' => $sent['status'], 'location' => $sent['location']];
};

/** The addresses of every event with this name, oldest first. */
$slugsOf = static function (string $name) use ($db): array {
    $q = $db->prepare('SELECT `eventSlug` FROM `tblEvents` WHERE `eventName` = ? ORDER BY `eventID`');
    $q->bind_param('s', $name);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    return array_map(static fn (array $r): string => (string) $r['eventSlug'], $rows);
};

/** The live (not deleted) start readings and addresses of a named run of events. */
$liveOf = static function (string $name) use ($db): array {
    $q = $db->prepare(
        'SELECT `startDateTime`, `eventSlug` FROM `tblEvents` WHERE `eventName` = ? AND `isDeleted` = 0 ORDER BY `startDateTime`'
    );
    $q->bind_param('s', $name);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    return array_map(static fn (array $r): string => substr((string) $r['startDateTime'], 0, 10) . ' = ' . $r['eventSlug'], $rows);
};

/** A DELETED event holding an address, as one left behind by somebody deleting it. */
$deletedHolder = static function (string $slug) use ($db, $siteId): void {
    $q = $db->prepare(
        'INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, `isDeleted`, `deletedAt`) '
        . "VALUES (?, 'P883 deleted holder', ?, '2031-01-01 10:00:00', 'Europe/London', 'published', 1, NOW())"
    );
    $q->bind_param('is', $siteId, $slug);
    $q->execute();
    $q->close();
};

/** The series a named run of events belongs to. */
$seriesOf = static function (string $name) use ($db): int {
    $q = $db->prepare('SELECT `seriesID` FROM `tblEvents` WHERE `eventName` = ? AND `seriesID` IS NOT NULL LIMIT 1');
    $q->bind_param('s', $name);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return $row === null ? 0 : (int) $row['seriesID'];
};

/**
 * The series pattern page: preview a weekly pattern of `$count` dates, then
 * confirm it. Answers the confirm's status and where it sent the browser.
 */
$repattern = static function (int $seriesId, string $start, string $end, string $count, bool $background = false) use ($http, $tokenOn, $curlFor) {
    $page   = $http('GET', '/calendar/manage/series-repattern?seriesID=' . $seriesId);
    $fields = [
        'csrf_token'      => $tokenOn($page['body']),
        'seriesID'        => (string) $seriesId,
        'patternStart'    => $start,
        'patternEnd'      => $end,
        'repeatFrequency' => 'weekly',
        'repeatCount'     => $count,
    ];
    $preview = $http('POST', '/calendar/manage/series-repattern', $fields);
    $digest  = preg_match('/name="planDigest" value="([0-9a-f]{64})"/', $preview['body'], $m) === 1 ? $m[1] : '';
    $fields['csrf_token'] = $tokenOn($preview['body']) !== '' ? $tokenOn($preview['body']) : $fields['csrf_token'];
    $fields['confirm']    = '1';
    $fields['planDigest'] = $digest;
    if ($background === true) {
        return $curlFor('POST', '/calendar/manage/series-repattern', $fields, []);
    }
    $done = $http('POST', '/calendar/manage/series-repattern', $fields);
    return ['status' => $done['status'], 'location' => $done['location'], 'digest' => $digest];
};

/**
 * 🏁 A REAL SECOND WRITER. Hold `$slug` in an OPEN transaction on a connection
 *    of this test's own, send `$handle` in the background, wait until its
 *    INSERT is really waiting on the key — asked of the database
 *    (`INNODB_TRX`), never assumed from a sleep (#735: a sleep is a hope) —
 *    then commit and let the request finish.
 *
 * @return array{waiting:bool, stillRunning:bool, answer:array}
 */
$realRace = static function (string $slug, $handle) use ($db, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, $siteId, $answerOf): array {
    /** How many INSERTs into tblEvents MySQL reports as waiting on a lock. */
    $lockWaits = static fn (): int => (int) ($db->query(
        "SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE `trx_state` = 'LOCK WAIT' "
        . "AND `trx_query` LIKE '%INSERT INTO %tblEvents%'"
    )->fetch_assoc()['n'] ?? 0);

    // ⚠️ `INNODB_TRX` IS A CACHE, AND ASKING TOO OFTEN KEEPS IT OLD. MySQL
    //    refreshes it only when more than 0.1 seconds have passed since it
    //    was last READ, so a loop asking every 0.1 seconds or faster can be
    //    shown the same old answer for ever. The first version of this test
    //    asked that fast: straight after one race it was still shown THAT
    //    race's request waiting, took it for the new request, committed
    //    before the new request had reached the database — and the request
    //    then saw the row and went straight to `-2`, so the race was never
    //    run. Found only because a planted single save attempt still "won"
    //    it. So every question here is at least 0.25 seconds after the last,
    //    and before each race this waits for an answer of NOTHING waiting:
    //    after that, a reading that shows a wait is newer than the last race.
    //    (Matching this race's own event name instead does not work:
    //    `trx_query` is cut at 1,024 characters, and the series page's INSERT
    //    names every column before its values.)
    $clear = microtime(true) + 10;
    usleep(250000);
    while ($lockWaits() > 0) {
        if (microtime(true) > $clear) {
            refuse('MySQL still reported an INSERT into tblEvents waiting on a lock ten seconds after the last race');
        }
        usleep(250000);
    }

    $holder = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $holder->begin_transaction();
    $q = $holder->prepare(
        'INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`) '
        . "VALUES (?, 'P883 race holder', ?, '2031-01-01 10:00:00', 'Europe/London', 'published')"
    );
    $q->bind_param('is', $siteId, $slug);
    $q->execute();
    $q->close();

    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $handle);
    $running = 1;
    $waiting = false;
    $until   = microtime(true) + 20;
    while (microtime(true) < $until) {
        curl_multi_exec($mh, $running);
        if ($running === 0) {
            break;
        }
        $n = $lockWaits();
        if ($n > 0) {
            $waiting = true;
            break;
        }
        // Not faster than the cache refreshes (see above). The request keeps
        // running meanwhile: a waiting INSERT does not need this loop.
        $next = microtime(true) + 0.25;
        while (microtime(true) < $next) {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.05);
        }
    }
    $stillRunning = $running > 0;
    $holder->commit();
    $holder->close();
    while ($running > 0) {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.2);
        }
    }
    $raw    = (string) curl_multi_getcontent($handle);
    $answer = $answerOf($handle, $raw);
    curl_multi_remove_handle($mh, $handle);
    curl_multi_close($mh);
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'P883 race holder'");

    return ['waiting' => $waiting, 'stillRunning' => $stillRunning, 'answer' => $answer];
};

// ── The scenarios, each reduced to what the checks and the plants compare ───

/** A and B and C for the API. */
$scnApi = static function () use ($clearEvents, $apiKeyCreate, $apiSessionCreate, $deletedHolder, $slugsOf, $signIn): array {
    $clearEvents();
    $signIn();
    $a1 = $apiKeyCreate('P883 API service', '2031-06-01T10:00:00');
    $a2 = $apiKeyCreate('P883 API service', '2031-06-01T10:00:00');
    $a3 = $apiKeyCreate('P883 API service', '2031-06-08T10:00:00');
    $b1 = $apiSessionCreate('P883 API service', '2031-06-01T18:00:00');
    $deletedHolder('p883-api-after-delete-2031-07-01');
    $c1 = $apiKeyCreate('P883 API after delete', '2031-07-01T10:00:00');
    return [
        'statuses' => [$a1['status'], $a2['status'], $a3['status'], $b1['status'], $c1['status']],
        'answered' => [$a1['slug'], $a2['slug'], $a3['slug'], $b1['slug'], $c1['slug']],
        'stored'   => $slugsOf('P883 API service'),
        'deleted'  => $slugsOf('P883 API after delete'),
        'firstBad' => $a2['status'] === 201 ? '' : substr($a2['body'], 0, 300),
    ];
};

/** D: the Manage Events form, one-off and repeating. */
$scnForm = static function () use ($clearEvents, $formCreate, $deletedHolder, $slugsOf, $liveOf, $signIn): array {
    $clearEvents();
    $signIn();
    $d1 = $formCreate('P883 Form event', '2031-08-01T10:00', '2031-08-01T11:00');
    $d2 = $formCreate('P883 Form event', '2031-08-01T10:00', '2031-08-01T11:00');
    $deletedHolder('p883-form-after-delete-2031-08-02');
    $d3 = $formCreate('P883 Form after delete', '2031-08-02T10:00', '2031-08-02T11:00');
    // A weekly series of three. Two deleted events hold addresses its SECOND
    // date could want: the new shape (its own date) and the old one (the
    // FIRST date's address plus `-2`, which before #883 was never asked of
    // the database). The new code must step past the first; the old code
    // ran into the second and that date was not saved — measured against
    // the code before #883 with SELFTEST_WEB_FROM.
    $deletedHolder('p883-form-weekly-2031-08-10');
    $deletedHolder('p883-form-weekly-2031-08-03-2');
    $d4 = $formCreate('P883 Form weekly', '2031-08-03T10:00', '2031-08-03T11:00', [
        'repeatFrequency' => 'weekly',
        'repeatCount'     => '3',
    ]);
    return [
        'statuses' => [$d1['status'], $d2['status'], $d3['status'], $d4['status']],
        'messages' => [$d2['message'], $d4['message']],
        'one'      => $slugsOf('P883 Form event'),
        'deleted'  => $slugsOf('P883 Form after delete'),
        'weekly'   => $liveOf('P883 Form weekly'),
    ];
};

/** E and C for the suggestion form. */
$scnSuggest = static function () use ($clearEvents, $suggest, $deletedHolder, $slugsOf, $signIn): array {
    $clearEvents();
    $signIn();
    $e1 = $suggest('P883 Suggested', '2031-09-01T19:00');
    $e2 = $suggest('P883 Suggested', '2031-09-01T19:00');
    $deletedHolder('p883-suggested-after-delete-2031-09-02');
    $e3 = $suggest('P883 Suggested after delete', '2031-09-02T19:00');
    $ok = static fn (array $r): string => $r['status'] . ' ' . (str_contains($r['location'], 'submitted=1') === true ? 'submitted' : $r['location']);
    return [
        'answers' => [$ok($e1), $ok($e2), $ok($e3)],
        'stored'  => $slugsOf('P883 Suggested'),
        'deleted' => $slugsOf('P883 Suggested after delete'),
    ];
};

/** F: the series pattern page adds a date whose address a deleted event holds. */
$scnSeries = static function () use ($clearEvents, $formCreate, $deletedHolder, $liveOf, $seriesOf, $repattern, $signIn): array {
    $clearEvents();
    $signIn();
    $formCreate('P883 Series', '2031-10-05T10:00', '2031-10-05T11:00', ['repeatFrequency' => 'weekly', 'repeatCount' => '2']);
    $series = $seriesOf('P883 Series');
    $deletedHolder('p883-series-2031-10-19');
    $r = $repattern($series, '2031-10-05T10:00', '2031-10-05T11:00', '4');
    return [
        'series' => $series,
        'done'   => $r['status'] . ' ' . (str_contains($r['location'], 'series-edit') === true ? 'to the series' : $r['location']),
        'live'   => $liveOf('P883 Series'),
    ];
};

/** R: the three real races. */
$scnRaces = static function () use (
    $clearEvents, $signIn, $realRace, $curlFor, $apiKey, $apiBody, $http, $tokenOn, $formFields, $liveOf, $seriesOf, $formCreate, $repattern, $flashOn, $slugsOf
): array {
    $clearEvents();
    $signIn();
    // The API, with the key.
    $api = $realRace(
        'p883-race-api-2031-11-01',
        $curlFor('POST', '/api/events/create', (string) json_encode($apiBody('P883 Race API', '2031-11-01T10:00:00')), ['bearer' => $apiKey, 'json' => true])
    );
    $apiJson = json_decode($api['answer']['body'], true);

    // The Manage Events form.
    $token = $tokenOn($http('GET', '/calendar/manage')['body']);
    $form  = $realRace(
        'p883-race-form-2031-11-02',
        $curlFor('POST', '/calendar/manage/save', $formFields($token, 'P883 Race form', '2031-11-02T10:00', '2031-11-02T11:00', []), [])
    );
    $formMessage = $flashOn($http('GET', '/calendar/manage')['body']);

    // The series pattern page, which writes inside ONE transaction.
    $formCreate('P883 Race series', '2031-11-02T10:00', '2031-11-02T11:00', ['repeatFrequency' => 'weekly', 'repeatCount' => '2']);
    $series = $seriesOf('P883 Race series');
    $seriesRace = $realRace(
        'p883-race-series-2031-11-16',
        $repattern($series, '2031-11-02T10:00', '2031-11-02T11:00', '3', true)
    );

    return [
        'premises' => [
            'api'    => [$api['waiting'], $api['stillRunning']],
            'form'   => [$form['waiting'], $form['stillRunning']],
            'series' => [$seriesRace['waiting'], $seriesRace['stillRunning']],
        ],
        'api'       => $api['answer']['status'] . ' ' . (is_array($apiJson) === true ? (string) ($apiJson['data']['eventSlug'] ?? ($apiJson['error'] ?? $apiJson['message'] ?? '')) : substr($api['answer']['body'], 0, 120)),
        'form'      => $form['answer']['status'] . ' ' . implode(', ', $slugsOf('P883 Race form')),
        'formMsg'   => $formMessage,
        'series'    => $seriesRace['answer']['status'] . ' ' . (str_contains($seriesRace['answer']['location'], 'series-edit') === true ? 'to the series' : 'stayed on the page'),
        'seriesNew' => $liveOf('P883 Race series'),
    ];
};

// ── A. and B. and C. The events API ─────────────────────────────────────────
echo "A. The events API: the same name on the same day, with a key and signed in\n";
$api = $scnApi();
check('A1 every create answers 201 — the same name twice on one day included, never a 500',
    $api['statuses'], [201, 201, 201, 201, 201]);
if ($api['firstBad'] !== '') {
    echo '      the second create answered: ' . $api['firstBad'] . "\n";
}
check('A2 the answer gives each event its own address: name and date, `-2` for the second on that day',
    array_slice($api['answered'], 0, 3),
    ['p883-api-service-2031-06-01', 'p883-api-service-2031-06-01-2', 'p883-api-service-2031-06-08']);
check('B1 signed in (a session and the form token), a third on the same day is `-3`',
    $api['answered'][3], 'p883-api-service-2031-06-01-3');
check('A3 ...and the database holds exactly the addresses the answers gave',
    $api['stored'], array_slice($api['answered'], 0, 4));
check('C1 a DELETED event holding the address: the API saves at `-2` rather than being refused',
    [$api['answered'][4], $api['deleted']], ['p883-api-after-delete-2031-07-01-2', ['p883-api-after-delete-2031-07-01-2']]);

// ── D. The Manage Events form ───────────────────────────────────────────────
echo "D. The Manage Events form, one-off and repeating\n";
$form = $scnForm();
check('D1 every save is accepted and sends the browser back (302)', $form['statuses'], [302, 302, 302, 302]);
check('D2 the same name on the same day twice: the second is `-2` (this form gave `-1` before #883)',
    $form['one'], ['p883-form-event-2031-08-01', 'p883-form-event-2031-08-01-2']);
check('D3 ...and the page says the second was saved, not an error',
    str_starts_with($form['messages'][0], 'success:'), true);
check('C2 a DELETED event holding the address: the form saves at `-2`',
    $form['deleted'], ['p883-form-after-delete-2031-08-02-2']);
check('D4 a weekly series: EVERY date is saved, each address carrying ITS OWN date — the one a deleted event held at `-2`',
    $form['weekly'],
    [
        '2031-08-03 = p883-form-weekly-2031-08-03',
        '2031-08-10 = p883-form-weekly-2031-08-10-2',
        '2031-08-17 = p883-form-weekly-2031-08-17',
    ]);
check('D5 ...and the page says the two dates after the first were both added',
    [str_starts_with($form['messages'][1], 'success:'), str_contains($form['messages'][1], ' 2 more date(s) were added.')], [true, true]);

// ── E. The public suggestion form ───────────────────────────────────────────
echo "E. The public suggestion form\n";
$sug = $scnSuggest();
check('E1 every suggestion is accepted (302 back to the form, "submitted")',
    $sug['answers'], ['302 submitted', '302 submitted', '302 submitted']);
check('E2 the same name on the same day twice gets two addresses, name and date, then `-2` — asked of the database, not four random characters',
    $sug['stored'], ['p883-suggested-2031-09-01', 'p883-suggested-2031-09-01-2']);
check('C3 a DELETED event holding the address: the suggestion is saved at `-2`',
    $sug['deleted'], ['p883-suggested-after-delete-2031-09-02-2']);

// ── F. The series pattern page ──────────────────────────────────────────────
echo "F. The series pattern page adds a date whose address a deleted event holds\n";
$ser = $scnSeries();
check('F0 PREMISE: the form made the two-date series the page works on', $ser['series'] > 0, true);
check('F1 the change is applied, not rolled back (302 to the series)', $ser['done'], '302 to the series');
check('F2 the two added dates carry their own date in the address, the held one at `-2`',
    $ser['live'],
    [
        '2031-10-05 = p883-series-2031-10-05',
        '2031-10-12 = p883-series-2031-10-12',
        '2031-10-19 = p883-series-2031-10-19-2',
        '2031-10-26 = p883-series-2031-10-26',
    ]);

// ── R. Real races ───────────────────────────────────────────────────────────
echo "R. Another writer takes the address between the question and the save\n";
$races = $scnRaces();
check('R0 PREMISE: each request\'s INSERT was really waiting on the address this test held, before the test let go',
    $races['premises'], ['api' => [true, true], 'form' => [true, true], 'series' => [true, true]]);
check('R1 THE API: once the other row is committed it asks again and creates the event at `-2` (201)',
    $races['api'], '201 p883-race-api-2031-11-01-2');
check('R2 THE FORM: the same, at `-2`', $races['form'], '302 p883-race-form-2031-11-02-2');
check('R3 ...and says it was saved', str_starts_with($races['formMsg'], 'success:'), true);
check('R4 THE SERIES PAGE, inside its own transaction: the change is applied, not rolled back',
    $races['series'], '302 to the series');
check('R5 ...and the added date is at `-2`, beside the other writer\'s',
    $races['seriesNew'],
    [
        '2031-11-02 = p883-race-series-2031-11-02',
        '2031-11-09 = p883-race-series-2031-11-09',
        '2031-11-16 = p883-race-series-2031-11-16-2',
    ]);

// ── P. The plants ───────────────────────────────────────────────────────────
if ($measuring === true) {
    echo "P. Skipped: measuring an older web/ (SELFTEST_WEB_FROM), whose lines the plants do not match.\n";
} else {
    echo "P. The plants: each fault put back in the child copy must turn its section red\n";
    $eventsPath = $child . $sep . 'web' . $sep . '_core' . $sep . 'Events.php';
    $apiPath    = $child . $sep . 'web' . $sep . '_apps' . $sep . 'events' . $sep . 'api' . $sep . 'create.php';
    $originals  = [$eventsPath => (string) file_get_contents($eventsPath), $apiPath => (string) file_get_contents($apiPath)];

    /** Replace exactly one copy of `$find`, proving the plant applied. */
    $plant = static function (string $path, string $find, string $replace, string $what) use ($originals, $startServer, $waitForServer): void {
        if (substr_count($originals[$path], $find) !== 1) {
            refuse('section P could not find exactly one copy of ' . $what . ' in the copied file');
        }
        file_put_contents($path, str_replace($find, $replace, $originals[$path]));
        clearstatcache(true, $path);
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

    // (a) ONE TRY: the address taken in between refuses the event.
    $plant($eventsPath, 'public const SLUG_SAVE_TRIES = 3;', 'public const SLUG_SAVE_TRIES = 1;', 'the number of save attempts');
    $p = $scnRaces();
    check('P1 with one save attempt, the real races still reach their premise', $p['premises'], $races['premises']);
    check('P2 ...and the API answers 409 rather than saving at `-2` (R1 would have gone red)',
        str_starts_with($p['api'], '409 '), true);
    check('P3 ...and the form saves nothing and says why (R2 and R3 would have gone red)',
        [$p['form'], str_starts_with($p['formMsg'], 'danger:') && str_contains($p['formMsg'], 'every web address tried')],
        ['302 ', true]);
    check('P4 ...and the series change is rolled back: the page is drawn again and the series still has two dates (R4 would have gone red)',
        [$p['series'], count($p['seriesNew'])], ['200 stayed on the page', 2]);
    $restore();

    // (b) NO LIST OF REFUSED ADDRESSES: harmless outside a transaction, fatal
    //     inside one, because "is it free" reads the transaction's old snapshot.
    $plant($eventsPath, 'if (in_array($candidate, $refused, true) === true) {', 'if (false) {', 'the refused-address test');
    $p = $scnRaces();
    check('P5 with no list of refused addresses, the API\'s race is still won — it writes outside a transaction',
        $p['api'], '201 p883-race-api-2031-11-01-2');
    check('P6 ...but the series page, inside its transaction, re-sends the refused address and is rolled back (R4 would have gone red)',
        [$p['premises']['series'], $p['series'], count($p['seriesNew'])], [[true, true], '200 stayed on the page', 2]);
    $restore();

    // (c) THE ADDRESS REFUSAL NOT RECOGNISED: every one is thrown straight back.
    $plant($eventsPath, "str_contains(\$refusal->getMessage(), 'uq_event_site_slug') === true;", "str_contains(\$refusal->getMessage(), 'uq_event_site_slug_x') === true;", 'the address-refusal test');
    $p = $scnRaces();
    check('P7 with the address refusal unrecognised, the API\'s race is not retried and is not a clear 409 (a 500)',
        str_starts_with($p['api'], '500 '), true);
    $restore();

    // (d) THE API'S OLD ADDRESS: the name alone.
    $plant(
        $apiPath,
        '$slugStem = Events::slugStem($eventName, $startSql);',
        "\$slugStem = substr(strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', \$eventName), '-')), 0, 100);",
        'the API\'s address stem'
    );
    $p = $scnApi();
    check('P8 with the name-only stem back in the API, the addresses lose their date (A2 would have gone red)',
        array_slice($p['answered'], 0, 3), ['p883-api-service', 'p883-api-service-2', 'p883-api-service-3']);
    $restore();

    // The fault #883 is named for — a duplicate name answered as a bare 500 —
    // is measured against the OLD code itself rather than planted here: run
    // this test with SELFTEST_WEB_FROM pointing at the code before #883 (see
    // the header), and section A goes red with 500s. A plant cannot stand in
    // for it faithfully, because the new handler catches the refusal.

    $after = $scnRaces();
    check('P9 with every plant taken out, the real races are green again', [$after['api'], $after['form'], $after['series']],
        ['201 p883-race-api-2031-11-01-2', '302 p883-race-form-2031-11-02-2', '302 to the series']);
}

$clearEvents();
echo "\n" . ($failed === 0 ? 'PASS' : 'FAIL') . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
