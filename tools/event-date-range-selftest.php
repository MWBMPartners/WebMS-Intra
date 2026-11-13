<?php
// Path: tools/event-date-range-selftest.php
/**
 * -----------------------------------------------------------------------------
 * An event's dates fall between 1900 and at least 2200 📅🧪 (#1045, D-181)
 * -----------------------------------------------------------------------------
 * The owner decided on 12 November 2026 (D-181): "from 1900 until 2200 (or at
 * least 50 years ahead, whichever is further)". So an event may start from
 * 1 January 1900 00:00:00 to the last second of 31 December of the LATER of
 * 2200 and this year plus fifty, and `Portal\Core\EventDateRange` is the ONE
 * place that decides it. Its end is held to the same range (the builder's
 * choice, recorded on #1045 and in the class header).
 *
 * This test proves the rule and then drives every writer of an event's start
 * over real HTTP, reading the database back:
 *
 *   U  the rule itself, with the current year GIVEN — so it is checked as it
 *      will be in 2150, 2151 and 2160, when this year plus fifty passes 2200
 *   A  the events API, create: both edges, years 1 and 9999, an end in 9999,
 *      and a value carrying an offset judged AFTER it is put onto the
 *      event's clock (`1900-01-01T00:30+01:00` is 23:30 on 31 December 1899)
 *   B  the events API, update: a start or end moved outside is refused and
 *      nothing changes; a stored date written before the range existed does
 *      NOT block an unrelated change
 *   C  Manage Events: both edges, years 1, 9999 and 10000; a repeat any of
 *      whose dates would fall outside — a first date the pattern MOVES past
 *      the edge, ten dates from the last December, an "until" five years
 *      past it, a last date whose END spills — refused WHOLE with nothing
 *      stored (the moved case also with ONE date, so a check skipping the
 *      first date cannot pass), beside a repeat wholly inside that is made in
 *      full; a start in ISO basic form judged by the reading actually stored;
 *      and a value the reading helper cannot read — an offset at either edge,
 *      a five-digit year, an offset end on an EDIT — refused in plain words
 *      with nothing stored, since only the reading is ever bound
 *   D  the public "suggest an event" form: refused with `?err=range`, and the
 *      form shows the range in words
 *   E  the series pattern page: a pattern outside the range is refused before
 *      any preview
 *   F  the CSV import: both edges, year 500 and an end in 9999, each refused
 *      row given the range in its reason
 *   P  THE PLANTS, each in the CHILD copy of `web/`, each proved applied: the
 *      call removed from each writer in turn, and the rule itself made to
 *      accept everything. Each must turn its own checks red.
 *
 * The outside-calendar importer is checked in `tools/feed-importer-selftest.php`
 * part N, because it is driven by a real refresh, not a web request.
 *
 * ⚠️ THE LAST YEAR IS WORKED OUT, NEVER WRITTEN DOWN. Every expected value
 *    here is computed from the year the test runs in, so the test stays right
 *    when that year plus fifty passes 2200. It reads the year on UTC's
 *    calendar while the server reads its own organisation's; the two differ
 *    for a few hours at New Year, and only from the 2150s does that move the
 *    far edge — a run at that moment could then disagree by one year.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/event-date-range-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and `proc_open()`. It REFUSES rather than
 *         skipping when anything is missing, because a skipped test reads
 *         exactly like a passing one. The harness (copy of `web/`, router,
 *         server, administrator, API key) is the one
 *         `tools/event-address-selftest.php` uses.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1045
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
// measuring older code (see the header).
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

/** Remove every event this test makes; the names all begin "P1045 ". */
$clearEvents = static function () use ($db): void {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P1045 %'");
    $db->query("DELETE FROM `tblEventSeries` WHERE `seriesName` LIKE 'P1045 %'");
};

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'event-date-range-' . bin2hex(random_bytes(6));
$state['child'] = $child;

register_shutdown_function(static function () use (&$state, $clearEvents): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['db'] instanceof mysqli) {
        try {
            // The series rule rows go with their series (ON DELETE CASCADE).
            $state['db']->query("DELETE r FROM `tblRecurrenceRules` r JOIN `tblEventSeries` s ON s.`seriesID` = r.`seriesID` WHERE s.`seriesName` LIKE 'P1045 %'");
            $clearEvents();
            if ($state['key'] > 0) {
                $state['db']->query('DELETE FROM `tblApiKeys` WHERE `keyID` = ' . (int) $state['key']);
            }
        } catch (Throwable $e) {
            echo "  ! could not remove this test's events or API key; remove rows named 'P1045 %' by hand.\n";
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
    "<?php\n// Written by tools/event-date-range-selftest.php for ONE run.\nreturn "
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
// Written by tools/event-date-range-selftest.php for ONE run. Test only.
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
$port = 19450 + random_int(0, 150);
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
 * @param array{bearer?:string, json?:bool, files?:array<string,string>} $o
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
        if (($o['files'] ?? []) !== [] && is_array($post) === true) {
            // A file upload (the CSV import): curl builds a multipart body.
            foreach ($o['files'] as $field => $path) {
                $post[$field] = new CURLFile($path, 'text/csv', basename($path));
            }
            curl_setopt($handle, CURLOPT_POSTFIELDS, $post);
        } else {
            curl_setopt($handle, CURLOPT_POSTFIELDS, is_array($post) === true ? http_build_query($post) : $post);
        }
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
$email      = 'event.date.range@selftest.invalid';
$signInName = 'selftest-event-date-range';
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
$db->query("DELETE FROM `tblApiKeys` WHERE `name` = 'P1045 date range key'");
$clearEvents();

$ins = $db->prepare(
    'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) '
    . "VALUES ('Event Date Range Admin', ?, 1, 1, 1, NOW())"
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
    . "VALUES (?, 'P1045 date range key', ?, ?, 'events:read,events:write', 1, NULL)"
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

// ⚠️ THE FAR YEAR IS WORKED OUT HERE FROM TODAY, by the owner's own words,
//    not by asking the class under test — so a class that worked it out
//    wrongly cannot agree with itself here. See the header on New Year.
$L     = max(2200, (int) gmdate('Y') + 50);
$range = 'between 1 January 1900 and 31 December ' . $L;

/** How many live (not deleted) events carry this exact name. */
$countNamed = static function (string $name) use ($db): int {
    $q = $db->prepare('SELECT COUNT(*) AS n FROM `tblEvents` WHERE `eventName` = ? AND `isDeleted` = 0');
    $q->bind_param('s', $name);
    $q->execute();
    $n = (int) ($q->get_result()->fetch_assoc()['n'] ?? 0);
    $q->close();
    return $n;
};

/** The one stored row of a named event, or null. */
$rowNamed = static function (string $name) use ($db): ?array {
    $q = $db->prepare('SELECT `eventID`, `startDateTime`, `endDateTime`, `seriesID` FROM `tblEvents` WHERE `eventName` = ? AND `isDeleted` = 0 ORDER BY `eventID` LIMIT 1');
    $q->bind_param('s', $name);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return $row === null ? null : $row;
};

/** The events API, with the key. Answers the status and the decoded body. */
$api = static function (string $action, array $body) use ($http, $apiKey): array {
    $r = $http('POST', '/api/events/' . $action, (string) json_encode($body), ['bearer' => $apiKey, 'json' => true]);
    return ['status' => $r['status'], 'body' => $r['body']];
};

/** Create through the events API; answers [status, stored start or null, body]. */
$apiCreate = static function (string $name, string $start, ?string $end = null) use ($api, $rowNamed): array {
    $body = ['eventName' => $name, 'startDateTime' => $start, 'timezone' => ZONE, 'status' => 'published'];
    if ($end !== null) {
        $body['endDateTime'] = $end;
    }
    $r   = $api('create', $body);
    $row = $rowNamed($name);
    return ['status' => $r['status'], 'stored' => $row === null ? null : (string) $row['startDateTime'], 'body' => $r['body']];
};

/** The coloured message the Manage Events page shows after a save. */
$flashOn = static function (string $html): string {
    if (preg_match('/<div class="alert alert-(\w+) alert-dismissible[^"]*">(.*?)<button/s', $html, $m) !== 1) {
        return '(no message shown)';
    }
    return $m[1] . ': ' . trim((string) preg_replace('/\s+/', ' ', strip_tags($m[2])));
};

/** Create through the Manage Events form. Answers [saved count, message]. */
$formCreate = static function (string $name, string $start, string $end = '', array $extra = []) use ($http, $tokenOn, $flashOn, $countNamed): array {
    $token = $tokenOn($http('GET', '/calendar/manage')['body']);
    $http('POST', '/calendar/manage/save', array_merge([
        'csrf_token'    => $token,
        'action'        => 'create',
        'eventName'     => $name,
        'description'   => 'Made by tools/event-date-range-selftest.php.',
        'startDateTime' => $start,
        'endDateTime'   => $end,
        'timezone'      => ZONE,
        'status'        => 'published',
        'locationName'  => 'The selftest hall',
    ], $extra));
    return ['saved' => $countNamed($name), 'message' => $flashOn($http('GET', '/calendar/manage')['body'])];
};

/** Suggest an event through the public form, signed in. Answers where it sent the browser. */
$suggest = static function (string $name, string $start, string $end = '') use ($http, $tokenOn): string {
    $token = $tokenOn($http('GET', '/calendar/submit')['body']);
    $sent  = $http('POST', '/calendar/submit-save', [
        'csrf_token'    => $token,
        'eventName'     => $name,
        'startDateTime' => $start,
        'endDateTime'   => $end,
        'locationName'  => '',
        'description'   => 'Suggested by tools/event-date-range-selftest.php.',
    ]);
    return $sent['location'];
};

/** Preview a weekly pattern on the series page. Answers the page body. */
$repatternPreview = static function (int $seriesId, string $start, string $end, string $count) use ($http, $tokenOn): string {
    $page = $http('GET', '/calendar/manage/series-repattern?seriesID=' . $seriesId);
    return $http('POST', '/calendar/manage/series-repattern', [
        'csrf_token'      => $tokenOn($page['body']),
        'seriesID'        => (string) $seriesId,
        'patternStart'    => $start,
        'patternEnd'      => $end,
        'repeatFrequency' => 'weekly',
        'repeatCount'     => $count,
    ])['body'];
};

/** One CSV import: preview then confirm. Answers both pages' bodies. */
$importCsv = static function (string $csvText) use ($http, $tokenOn, $child, $sep): array {
    $file = $child . $sep . 'import-' . bin2hex(random_bytes(4)) . '.csv';
    file_put_contents($file, $csvText);
    $page    = $http('GET', '/calendar/manage/import');
    $preview = $http('POST', '/calendar/manage/import', ['csrf_token' => $tokenOn($page['body']), 'action' => 'preview'], ['files' => ['csv_file' => $file]]);
    $token   = $tokenOn($preview['body']) !== '' ? $tokenOn($preview['body']) : $tokenOn($page['body']);
    $confirm = $http('POST', '/calendar/manage/import', ['csrf_token' => $token, 'action' => 'confirm']);
    return ['preview' => $preview['body'], 'confirm' => $confirm['body']];
};

/** Change one file in the CHILD copy; answers what was there, to put back. */
$plant = static function (string $rel, string $from, string $to) use ($child, $sep): string {
    $path = $child . $sep . 'web' . $sep . str_replace('/', $sep, $rel);
    $was  = (string) file_get_contents($path);
    if (substr_count($was, $from) !== 1) {
        refuse("the plant for {$rel} did not find exactly one copy of the text it replaces — the code moved, so this plant tests nothing");
    }
    file_put_contents($path, str_replace($from, $to, $was));
    if ((string) file_get_contents($path) === $was) {
        refuse("the plant for {$rel} was not applied");
    }
    return $was;
};
$unplant = static function (string $rel, string $was) use ($child, $sep): void {
    file_put_contents($child . $sep . 'web' . $sep . str_replace('/', $sep, $rel), $was);
};

// ═════════════════════════════════════════════════════════════════════════════
echo "\nU  The rule, with the current year given\n";
// ═════════════════════════════════════════════════════════════════════════════
require_once $repo . $sep . 'web' . $sep . '_core' . $sep . 'EventDateRange.php';
class_alias(\Portal\Core\EventDateRange::class, 'EventDateRange');

foreach ([2026 => 2200, 2149 => 2200, 2150 => 2200, 2151 => 2201, 2160 => 2210, 2300 => 2350] as $now => $want) {
    check("U1 in {$now} the last allowed year is {$want} (the later of 2200 and {$now} + 50)", EventDateRange::lastYear($now), $want);
}
check('U2 with no year given it is worked out from today', EventDateRange::lastYear(), $L);
// The edges in 2160, when this year plus fifty has passed 2200.
check('U3 in 2160, 31 December 2210 23:59:59 is inside', EventDateRange::contains('2210-12-31 23:59:59', 2160), true);
check('U3b in 2160, 1 January 2211 is outside', EventDateRange::contains('2211-01-01 00:00:00', 2160), false);
check('U3c but TODAY, 31 December 2210 is inside only if this year plus fifty reaches it', EventDateRange::contains('2210-12-31 23:59:59'), $L >= 2210);
foreach ([
    ['1899-12-31 23:59:59', false], ['1900-01-01 00:00:00', true],
    [$L . '-12-31 23:59:59', true], [($L + 1) . '-01-01 00:00:00', false],
    ['0001-01-01 00:00:00', false], ['9999-12-31 23:59:59', false],
    ['-0001-11-30 00:00:00', false], ['10000-01-01 00:00:00', false], ['', false], ['rubbish', false],
] as [$reading, $inside]) {
    check('U4 "' . $reading . '" is ' . ($inside === true ? 'inside' : 'outside'), EventDateRange::contains($reading), $inside);
}
check('U5 no end (null) is not judged', EventDateRange::whyRefused(['2030-06-01 10:00:00', null]), null);
check('U5b an end in 9999 is refused even with a good start', EventDateRange::whyRefused(['2030-06-01 10:00:00', '9999-12-31 10:00:00']) !== null, true);
check('U6 the reason names the range, with the worked-out year', str_contains((string) EventDateRange::whyRefused(['1899-12-31 10:00:00']), $range), true);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nA  The events API, create\n";
// ═════════════════════════════════════════════════════════════════════════════
$apiCases = [
    // name                         start                         end            wanted status, wanted stored start
    ['P1045 api 1899',            '1899-12-31T23:59:59',          null,          400, null],
    ['P1045 api 1900',            '1900-01-01T00:00:00',          null,          201, '1900-01-01 00:00:00'],
    ['P1045 api last day',        $L . '-12-31T23:59:59',         null,          201, $L . '-12-31 23:59:59'],
    ['P1045 api day after',       ($L + 1) . '-01-01T00:00:00',   null,          400, null],
    ['P1045 api year 1',          '0001-01-01T10:00',             null,          400, null],
    ['P1045 api year 9999',       '9999-12-31T10:00',             null,          400, null],
    ['P1045 api end 9999',        '2030-06-01T10:00',             '9999-12-31T10:00', 400, null],
    // ⚠️ Judged on the STORED reading, after the offset is put onto the
    //    event's clock: London kept Greenwich time through 1899-1900, so
    //    00:30 at +01:00 is 23:30 on 31 December 1899 there.
    ['P1045 api offset into 1899', '1900-01-01T00:30:00+01:00',   null,          400, null],
    ['P1045 api offset into 1900', '1899-12-31T23:30:00-01:00',   null,          201, '1900-01-01 00:30:00'],
];
$apiCreateRun = static function () use ($apiCases, $apiCreate, $clearEvents): array {
    $clearEvents();
    $out = [];
    foreach ($apiCases as [$name, $start, $end]) {
        $r = $apiCreate($name, $start, $end);
        $out[$name] = [$r['status'], $r['stored'], $r['body']];
    }
    return $out;
};
$got = $apiCreateRun();
foreach ($apiCases as [$name, $start, $end, $status, $stored]) {
    check("A {$name}: {$start}" . ($end === null ? '' : " to {$end}") . " answers {$status}", $got[$name][0], $status);
    check("A {$name}: stored " . var_export($stored, true), $got[$name][1], $stored);
}
check('A the 400 names the range', str_contains($got['P1045 api 1899'][2], $range), true);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nB  The events API, update\n";
// ═════════════════════════════════════════════════════════════════════════════
$apiUpdateRun = static function () use ($apiCreate, $api, $rowNamed, $db): array {
    $apiCreate('P1045 api to move', '2030-06-01T10:00', '2030-06-01T11:00');
    $id   = (int) ($rowNamed('P1045 api to move')['eventID'] ?? 0);
    $out  = [];
    $r    = $api('update', ['eventID' => $id, 'startDateTime' => '9999-12-31T10:00', 'endDateTime' => '9999-12-31T11:00']);
    $out['start9999'] = [$r['status'], (string) ($rowNamed('P1045 api to move')['startDateTime'] ?? '')];
    $r    = $api('update', ['eventID' => $id, 'endDateTime' => '9999-12-31T11:00']);
    $out['end9999'] = [$r['status'], (string) ($rowNamed('P1045 api to move')['endDateTime'] ?? '')];
    $r    = $api('update', ['eventID' => $id, 'startDateTime' => '2100-01-01T10:00', 'endDateTime' => '2100-01-01T11:00']);
    $out['start2100'] = [$r['status'], (string) ($rowNamed('P1045 api to move')['startDateTime'] ?? '')];
    // A row written before the range existed, put there by hand.
    $db->query("UPDATE `tblEvents` SET `startDateTime` = '0500-01-01 10:00:00', `endDateTime` = NULL WHERE `eventID` = {$id}");
    $r    = $api('update', ['eventID' => $id, 'eventName' => 'P1045 api renamed']);
    $out['rename'] = [$r['status'], $rowNamed('P1045 api renamed') !== null];
    return $out;
};
$upd = $apiUpdateRun();
check('B a start moved to 9999 answers 400', $upd['start9999'][0], 400);
check('B and the stored start is unchanged', $upd['start9999'][1], '2030-06-01 10:00:00');
check('B an end moved to 9999 answers 400', $upd['end9999'][0], 400);
check('B and the stored end is unchanged', $upd['end9999'][1], '2030-06-01 11:00:00');
check('B a start moved to 2100 is accepted', $upd['start2100'], [200, '2100-01-01 10:00:00']);
check('B a stored year-500 date does NOT block renaming the event', $upd['rename'], [200, true]);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nC  Manage Events\n";
// ═════════════════════════════════════════════════════════════════════════════
$formCases = [
    ['P1045 form 1899',      '1899-12-31T23:59',            '',                 0],
    ['P1045 form 1900',      '1900-01-01T00:00',            '',                 1],
    ['P1045 form last day',  $L . '-12-31T23:59',           '',                 1],
    ['P1045 form day after', ($L + 1) . '-01-01T00:00',     '',                 0],
    ['P1045 form year 1',    '0001-01-01T10:00',            '',                 0],
    ['P1045 form year 9999', '9999-12-31T10:00',            '',                 0],
    ['P1045 form end 9999',  '2030-06-01T10:00',            '9999-12-31T10:00', 0],
];
$formRun = static function () use ($formCases, $formCreate): array {
    $out = [];
    foreach ($formCases as [$name, $start, $end]) {
        $out[$name] = $formCreate($name, $start, $end);
    }
    return $out;
};
$form = $formRun();
foreach ($formCases as [$name, $start, $end, $saved]) {
    check("C {$name}: {$start}" . ($end === '' ? '' : " to {$end}") . ' ' . ($saved === 1 ? 'is saved' : 'is NOT saved'), $form[$name]['saved'], $saved);
    if ($saved === 0) {
        check("C {$name}: the message names the range", str_contains($form[$name]['message'], $range), true);
    }
}

// A REPEAT IS JUDGED WHOLE, BEFORE ANYTHING IS WRITTEN. If any date it would
// make — the first one included, after the pattern moves it — falls outside
// the range, the whole save is refused and NOTHING is stored: no event, no
// series, no shortened run. Round 1 capped and skipped instead, which made
// fewer dates than were asked for with a cheerful message, and never checked
// a first date the pattern had moved (round 2 of #1045's check measured
// 2200-12-30 22:00 with Wednesdays ticked stored as 2200-12-31 22:00 to
// 2201-01-01 01:00, "created successfully").
/** Post one repeat; answers how many rows of that name exist and the message. */
$repeatRun = static function (string $name, string $start, string $end, array $repeat) use ($formCreate, $db): array {
    $made = $formCreate($name, $start, $end, ['repeatFrequency' => 'weekly'] + $repeat);
    $q = $db->prepare('SELECT COUNT(*) AS n FROM `tblEventSeries` WHERE `seriesName` = ?');
    $q->bind_param('s', $name);
    $q->execute();
    $series = (int) ($q->get_result()->fetch_assoc()['n'] ?? 0);
    $q->close();
    return ['events' => $made['saved'], 'series' => $series, 'message' => $made['message']];
};
// The weekday of 31 December of the last allowed year, as the form numbers
// it (0 = Sunday), worked out rather than assumed.
$lastDayWeekday = (string) (int) (new DateTimeImmutable($L . '-12-31', new DateTimeZone('UTC')))->format('w');
$repeatCases = [
    // name                     start                     end                       repeat fields
    ['P1045 repeat moved',      $L . '-12-30T22:00',      $L . '-12-31T01:00',      ['repeatCount' => '3', 'repeatDays' => [$lastDayWeekday]]],
    // ⚠️ THE SAME, ONE DATE ONLY. With count 3 every later date is past the
    //    edge too, so a guard that skipped the first date still refused it
    //    (round 3 of the check: `array_slice(..., 1)` left 97/97). With count
    //    1 the moved first date is the ONLY date — plant P4d.
    ['P1045 repeat moved 1',    $L . '-12-30T22:00',      $L . '-12-31T01:00',      ['repeatCount' => '1', 'repeatDays' => [$lastDayWeekday]]],
    ['P1045 repeat count 10',   $L . '-12-10T10:00',      $L . '-12-10T11:00',      ['repeatCount' => '10']],
    ['P1045 repeat until',      $L . '-12-10T10:00',      $L . '-12-10T11:00',      ['repeatUntil' => ($L + 5) . '-12-31']],
    ['P1045 repeat spill',      $L . '-12-24T23:00',      $L . '-12-25T00:30',      ['repeatCount' => '3']],
];
$repeatAll = static function () use ($repeatCases, $repeatRun): array {
    $out = [];
    foreach ($repeatCases as [$name, $start, $end, $repeat]) {
        $out[$name] = $repeatRun($name, $start, $end, $repeat);
    }
    return $out;
};
$reps = $repeatAll();
check('C premise: the moved case really is moved — 30 December is not the ticked weekday',
    (string) (int) (new DateTimeImmutable($L . '-12-30', new DateTimeZone('UTC')))->format('w') !== $lastDayWeekday, true);
foreach ($repeatCases as [$name]) {
    check("C {$name}: refused WHOLE — no event and no series stored", [$reps[$name]['events'], $reps[$name]['series']], [0, 0]);
    check("C {$name}: and the message names the range and says nothing was saved",
        str_contains($reps[$name]['message'], $range) && str_contains($reps[$name]['message'], 'Nothing was saved.'), true);
}
// The control: a repeat wholly inside the range, near the edge, is made in
// full, so the refusals above are the range and not the repeat itself.
$inside = $repeatRun('P1045 repeat inside', $L . '-12-03T10:00', $L . '-12-03T11:00', ['repeatCount' => '3']);
check('C premise: three weekly dates from 3 December ' . $L . ' are all made, in one series', [$inside['events'], $inside['series']], [3, 1]);

// The reading that is STORED decides, not the "T"-to-space text: ISO basic
// form is read as 2030 by I18n::wallClockFromInput() and stored as 2030 by
// MySQL (measured on 8.0.36: '20300601T100000' -> 2030-06-01 10:00:00).
// Round 1 judged the text and refused it as out of range.
$basicRun = static function () use ($formCreate, $rowNamed): array {
    $made = $formCreate('P1045 form basic', '20300601T100000');
    $row  = $rowNamed('P1045 form basic');
    return [$made['saved'], $row === null ? null : (string) $row['startDateTime'], $made['message']];
};
$basic = $basicRun();
check('C a start in ISO basic form (20300601T100000) is saved, as 2030-06-01 10:00:00', [$basic[0], $basic[1]], [1, '2030-06-01 10:00:00']);
check('C and it is not refused for the range', str_contains($basic[2], $range), false);

// ONLY WHAT I18n::wallClockFromInput() READS IS STORED (#1045 round 3). The
// handler used to bind the RAW posted text, and MySQL converted a value
// carrying an offset: measured, `{last}-12-31T23:30:00-05:00` was stored as
// 1 January of the year after, past the range, "created successfully"; and
// `1900-01-01T00:30:00+01:00` failed at the INSERT with a bare error 1292.
// The form's box never sends an offset, so such a value is refused in plain
// words, nothing stored — on create AND on edit, start AND end.
$unreadable = 'is not a date and time this form can save';
$formEdit = static function (int $eventId, string $name, string $start, string $end) use ($http, $tokenOn, $flashOn): string {
    $token = $tokenOn($http('GET', '/calendar/manage')['body']);
    $http('POST', '/calendar/manage/save', [
        'csrf_token'    => $token,
        'action'        => 'update',
        'eventID'       => (string) $eventId,
        'eventName'     => $name,
        'description'   => 'Edited by tools/event-date-range-selftest.php.',
        'startDateTime' => $start,
        'endDateTime'   => $end,
        'timezone'      => ZONE,
        'status'        => 'published',
        'locationName'  => 'The selftest hall',
    ]);
    return $flashOn($http('GET', '/calendar/manage')['body']);
};
$offsetRun = static function () use ($formCreate, $formEdit, $rowNamed): array {
    $L   = $GLOBALS['L'];
    $out = [
        'start' => $formCreate('P1045 offset start', $L . '-12-31T23:30:00-05:00'),
        'end'   => $formCreate('P1045 offset end', '2030-06-01T10:00', $L . '-12-31T23:30:00-05:00'),
        '1900'  => $formCreate('P1045 offset 1900', '1900-01-01T00:30:00+01:00'),
        '10000' => $formCreate('P1045 offset 10000', '10000-01-01T10:00'),
    ];
    $formCreate('P1045 offset edit', '2030-06-01T10:00', '2030-06-01T11:00');
    $row     = $rowNamed('P1045 offset edit');
    $message = $formEdit((int) ($row['eventID'] ?? 0), 'P1045 offset edit', '2030-06-01T10:00', $L . '-12-31T23:30:00-05:00');
    $after   = $rowNamed('P1045 offset edit');
    $out['edit'] = ['saved' => $after === null ? 0 : 1, 'message' => $message,
        'end' => $after === null ? null : (string) $after['endDateTime']];
    return $out;
};
$off = $offsetRun();
foreach (['start' => 'a start carrying an offset (-05:00) at the far edge', 'end' => 'an end carrying an offset at the far edge',
          '1900' => '1900-01-01T00:30:00+01:00', '10000' => 'a five-digit year'] as $k => $what) {
    check("C {$what} is refused in plain words and nothing is stored",
        [$off[$k]['saved'], str_contains($off[$k]['message'], $unreadable), str_contains($off[$k]['message'], 'Nothing was saved.')],
        [0, true, true]);
}
check('C an EDIT whose end carries an offset is refused in plain words and the stored end is unchanged',
    [str_contains($off['edit']['message'], $unreadable), $off['edit']['end']], [true, '2030-06-01 11:00:00']);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nD  The public \"suggest an event\" form\n";
// ═════════════════════════════════════════════════════════════════════════════
$suggestRun = static function () use ($suggest, $countNamed): array {
    return [
        '1899'  => [$suggest('P1045 suggest 1899', '1899-12-31T23:59'), $countNamed('P1045 suggest 1899')],
        '1900'  => [$suggest('P1045 suggest 1900', '1900-01-01T00:00'), $countNamed('P1045 suggest 1900')],
        'after' => [$suggest('P1045 suggest after', ($GLOBALS['L'] + 1) . '-01-01T00:00'), $countNamed('P1045 suggest after')],
        'end'   => [$suggest('P1045 suggest end', '2030-06-01T10:00', '9999-12-31T10:00'), $countNamed('P1045 suggest end')],
    ];
};
$sug = $suggestRun();
check('D 31 December 1899 is sent back with err=range and not saved', [str_contains($sug['1899'][0], 'err=range'), $sug['1899'][1]], [true, 0]);
check('D 1 January 1900 is accepted and saved', [str_contains($sug['1900'][0], 'submitted=1'), $sug['1900'][1]], [true, 1]);
check('D the day after the last allowed one is sent back with err=range', [str_contains($sug['after'][0], 'err=range'), $sug['after'][1]], [true, 0]);
check('D an end in 9999 is sent back with err=range', [str_contains($sug['end'][0], 'err=range'), $sug['end'][1]], [true, 0]);
check('D the form, shown with err=range, names the range',
    str_contains($http('GET', '/calendar/submit?err=range')['body'], htmlspecialchars($range, ENT_QUOTES, 'UTF-8')), true);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nE  The series pattern page\n";
// ═════════════════════════════════════════════════════════════════════════════
$formCreate('P1045 series base', '2031-10-05T10:00', '2031-10-05T11:00', ['repeatFrequency' => 'weekly', 'repeatCount' => '3']);
$baseSeries = (int) ($rowNamed('P1045 series base')['seriesID'] ?? 0);
check('E premise: the series to change exists', $baseSeries > 0, true);
$repattRun = static function () use ($repatternPreview, &$baseSeries, $range): array {
    // A century past the far edge rather than 9999: with the checks planted
    //    away, P6 needs a pattern the page can otherwise preview, and the
    //    generator's own ceiling arithmetic is not this test's business.
    $farY = $GLOBALS['L'] + 100;
    $far  = $repatternPreview($baseSeries, $farY . '-01-04T10:00', $farY . '-01-04T11:00', '3');
    $good = $repatternPreview($baseSeries, '2032-01-04T10:00', '2032-01-04T11:00', '3');
    return [
        'far'  => [str_contains($far, htmlspecialchars($range, ENT_QUOTES, 'UTF-8')), preg_match('/name="planDigest" value="[0-9a-f]{64}"/', $far) === 1],
        'good' => preg_match('/name="planDigest" value="[0-9a-f]{64}"/', $good) === 1,
    ];
};
$rp = $repattRun();
check('E a pattern starting a century past the far edge is refused with the range, and no preview is offered', $rp['far'], [true, false]);
check('E premise: an ordinary pattern IS previewed, so the refusal above is the range and not the page', $rp['good'], true);
// A pattern whose start fits but whose last date would END past the range:
// only the check of every date the pattern makes can see it.
$spillPreview = static function () use ($repatternPreview, &$baseSeries, $range): array {
    $L    = $GLOBALS['L'];
    $body = $repatternPreview($baseSeries, $L . '-12-24T23:00', $L . '-12-25T00:30', '3');
    return [str_contains($body, htmlspecialchars($range, ENT_QUOTES, 'UTF-8')), preg_match('/name="planDigest" value="[0-9a-f]{64}"/', $body) === 1];
};
check('E a pattern whose last date would end past the range is refused, with no preview', $spillPreview(), [true, false]);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nF  The CSV import\n";
// ═════════════════════════════════════════════════════════════════════════════
$csvRun = static function () use ($importCsv, $countNamed, $range): array {
    $L   = $GLOBALS['L'];
    $out = $importCsv(
        "eventName,startDateTime,endDateTime,status\n"
        . "P1045 csv 1899,1899-12-31 10:00,,draft\n"
        . "P1045 csv 1900,1900-01-01 10:00,,draft\n"
        . "P1045 csv last day,{$L}-12-31 10:00,,draft\n"
        . 'P1045 csv day after,' . ($L + 1) . "-01-01 10:00,,draft\n"
        . "P1045 csv year 500,0500-01-01 10:00,,draft\n"
        . "P1045 csv end 9999,2030-06-01 10:00,9999-12-31 10:00,draft\n"
    );
    $said = substr_count($out['preview'], htmlspecialchars($range, ENT_QUOTES, 'UTF-8'));
    return [
        'saved' => [
            $countNamed('P1045 csv 1899'), $countNamed('P1045 csv 1900'), $countNamed('P1045 csv last day'),
            $countNamed('P1045 csv day after'), $countNamed('P1045 csv year 500'), $countNamed('P1045 csv end 9999'),
        ],
        'said' => $said,
    ];
};
$csv = $csvRun();
check('F only 1 January 1900 and the last allowed day are imported', $csv['saved'], [0, 1, 1, 0, 0, 0]);
check('F the preview gives the range as the reason for each of the four refused rows', $csv['said'] >= 4, true);

// ═════════════════════════════════════════════════════════════════════════════
echo "\nP  The plants: each writer's call removed, and the rule made to accept everything\n";
// ═════════════════════════════════════════════════════════════════════════════
// Each plant is in the CHILD copy, proved applied, and put back afterwards.
// The server runs with opcache off, so a changed file is read on the next
// request. Each must make the fault come back where its own checks look.

$was = $plant('_apps/events/api/create.php', '$rangeProblem = EventDateRange::whyRefused([$startSql, $endSql]);', '$rangeProblem = null;');
$pg  = $apiCreateRun();
$unplant('_apps/events/api/create.php', $was);
check('P1 create without its call: year 9999 is stored again (the check goes red)', [$pg['P1045 api year 9999'][0], $pg['P1045 api year 9999'][1]], [201, '9999-12-31 10:00:00']);

$was = $plant('_apps/events/api/update.php', "ApiResponse::error(\$rangeProblem . ' Nothing was changed.', 400);", '// planted: no refusal');
$clearEvents();
$pg  = $apiUpdateRun();
$unplant('_apps/events/api/update.php', $was);
check('P2 update without its call: a start in 9999 is stored again', $pg['start9999'], [200, '9999-12-31 10:00:00']);

$was = $plant('_apps/calendar/manage/save.php', 'if ($rangeProblem !== null) {', 'if (false) {');
$clearEvents();
$pg  = $formRun();
$unplant('_apps/calendar/manage/save.php', $was);
check('P3 Manage Events without its call: 31 December 1899 is saved again', $pg['P1045 form 1899']['saved'], 1);

// The whole-repeat refusal taken away — which is round 1's behaviour less its
// cap and skip, both now deleted: every repeat case is stored again.
$was = $plant('_apps/calendar/manage/save.php', 'if ($repeatRangeProblem !== null) {', 'if (false) {');
$clearEvents();
$pg  = $repeatAll();
$unplant('_apps/calendar/manage/save.php', $was);
check('P4 Manage Events without the whole-repeat refusal: the moved first date is stored past the range again',
    $pg['P1045 repeat moved']['events'] > 0, true);
check('P4b and the count-10 repeat near the edge is stored again (all ten)', $pg['P1045 repeat count 10']['events'], 10);

// The raw posted text kept instead of the reading, as in round 1: the range
// check then judges the text, and the 2030 basic-form start reads as no year.
$was = $plant('_apps/calendar/manage/save.php', '$startDateTime = $startRead;', '// planted: the raw text is kept');
$clearEvents();
$pg  = $basicRun();
$unplant('_apps/calendar/manage/save.php', $was);
check('P4c judging the text rather than the reading: the 2030 basic-form start is refused as out of range again',
    [$pg[0], str_contains($pg[2], $range)], [0, true]);

// The whole-repeat check made to skip the first date (round 3's finding).
$was = $plant('_apps/calendar/manage/save.php', "foreach (\$worked['dates'] as \$wouldMake) {", "foreach (array_slice(\$worked['dates'], 1) as \$wouldMake) {");
$clearEvents();
$pg  = $repeatAll();
$unplant('_apps/calendar/manage/save.php', $was);
check('P4d the whole-repeat check skipping the first date: the one-date moved repeat is stored past the range again',
    $pg['P1045 repeat moved 1']['events'], 1);

// Round 2's fallback to the text put back: an unreadable value is no longer
// refused, the raw text is bound, and MySQL converts the offset past the edge.
$was = $plant('_apps/calendar/manage/save.php', '$startRead = I18n::wallClockFromInput($startDateTime);',
    "\$startRead = I18n::wallClockFromInput(\$startDateTime) ?? trim(str_replace('T', ' ', \$startDateTime));");
$plant('_apps/calendar/manage/save.php', "\$endRead   = \$endDateTime === '' ? '' : I18n::wallClockFromInput(\$endDateTime);",
    "\$endRead   = \$endDateTime === '' ? '' : (I18n::wallClockFromInput(\$endDateTime) ?? trim(str_replace('T', ' ', \$endDateTime)));");
$clearEvents();
$pg  = $offsetRun();
$unplant('_apps/calendar/manage/save.php', $was);
check('P4e the text fallback back: the offset start is STORED past the range again', [$pg['start']['saved'], $rowNamed('P1045 offset start')['startDateTime'] ?? null],
    [1, ($L + 1) . '-01-01 04:30:00']);

$was = $plant('_apps/calendar/submit-save.php', 'if (EventDateRange::whyRefused([$startUtc, $endUtc]) !== null) {', 'if (false) {');
$clearEvents();
$pg  = $suggestRun();
$unplant('_apps/calendar/submit-save.php', $was);
check('P5 the suggestion form without its call: 31 December 1899 is saved again', $pg['1899'][1], 1);

$clearEvents();
$formCreate('P1045 series base', '2031-10-05T10:00', '2031-10-05T11:00', ['repeatFrequency' => 'weekly', 'repeatCount' => '3']);
$baseSeries = (int) ($rowNamed('P1045 series base')['seriesID'] ?? 0);
// The page asks twice — the first date, then every date — so the far case
// needs both taken away, and the spill case needs only the second.
$everyDate = "if (EventDateRange::whyRefused([(string) \$newDate['start'], \$newDate['end'] === null ? null : (string) \$newDate['end']]) !== null) {";
$was = $plant('_apps/calendar/manage/series-repattern.php', '} elseif (EventDateRange::whyRefused([$anchor, $anchorEnd]) !== null) {', '} elseif (false) {');
$plant('_apps/calendar/manage/series-repattern.php', $everyDate, 'if (false) {');
$pg  = $repattRun();
$unplant('_apps/calendar/manage/series-repattern.php', $was);
check('P6 the pattern page without either call: that pattern is previewed again', $pg['far'], [false, true]);
$was = $plant('_apps/calendar/manage/series-repattern.php', $everyDate, 'if (false) {');
$pg  = $spillPreview();
$unplant('_apps/calendar/manage/series-repattern.php', $was);
check('P6b without the every-date check: the spilling pattern is previewed again', $pg, [false, true]);


$was = $plant('_apps/calendar/manage/import.php', 'if (\Portal\Core\EventDateRange::contains($out) === false) {', 'if (false) {');
$clearEvents();
$pg  = $csvRun();
$unplant('_apps/calendar/manage/import.php', $was);
check('P7 the import without its range test: 31 December 1899 is imported again', $pg['saved'][0], 1);

$was = $plant('_core/EventDateRange.php', 'return $year >= self::FIRST_YEAR && $year <= self::lastYear($currentYear);', 'return true;');
$clearEvents();
$pgA = $apiCreateRun();
$pgS = $suggestRun();
$unplant('_core/EventDateRange.php', $was);
check('P8 the rule accepting every year: the API stores 1899 and the suggestion form takes it', [$pgA['P1045 api 1899'][0], $pgS['1899'][1]], [201, 1]);

// And back to how it should be: one writer re-checked with every plant removed.
$clearEvents();
$back = $apiCreateRun();
check('P9 with every plant removed, year 9999 is refused again', $back['P1045 api year 9999'][0], 400);

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
