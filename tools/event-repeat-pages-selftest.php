<?php
// Path: tools/event-repeat-pages-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Repeating events, on the real pages — self-test 🔁 (#569, migration 227)
 * -----------------------------------------------------------------------------
 * `tools/event-recurrence-selftest.php` proves the DATE RULES: clock changes in
 * three zones, the hour that does not exist, 29 February skipped rather than
 * moved, both ceilings. It calls the generator directly and needs no database.
 *
 * WHAT THIS FILE IS FOR, AND WHY IT HAD TO EXIST SEPARATELY
 * -----------------------------------------------------------------------------
 * None of that proves the FEATURE works. The generator can be perfect while the
 * form posts `repeat_frequency` and the handler reads `repeatFrequency`, and
 * every check in the other file still passes. That gap is not hypothetical:
 * while this file was being written it caught a real one — the form printed
 * `EventRecurrence::MAX_YEARS`, a constant that had just been renamed, so every
 * create form raised a fatal error. `php -l` cannot see an undefined constant,
 * the 43 generator checks did not touch the form, and nothing else would have
 * noticed until a customer pressed "Create Event".
 *
 * So this test drives the real pages over real HTTP: it signs in, asks for the
 * form, posts it, and then reads the rows back OUT OF THE DATABASE rather than
 * believing the page's own cheerful message.
 *
 * THE FIELD NAMES ARE CROSS-CHECKED, NOT LISTED TWICE
 * -----------------------------------------------------------------------------
 * Section A reads every `$_POST['repeat…']` name out of the save handler itself
 * and requires each one to appear in the form the portal really served. A list
 * typed into this file would be a third place to keep in step, and a test
 * holding its own copy of what it is testing proves nothing — this project has
 * been caught by exactly that before.
 *
 * WHAT IT DOES NOT COVER, said plainly
 * -----------------------------------------------------------------------------
 *   • Changing the pattern of a series that already exists. The form offers
 *     repeats on CREATION only, deliberately, so there is nothing to test.
 *   • Two people creating the same series at once. The built-in server runs
 *     with one worker here (PHP_CLI_SERVER_WORKERS is unset), so two requests
 *     never overlap and such a test would pass on broken code.
 *   • An imported event being copied. `FeedImporter` is the only thing that
 *     creates one and the form cannot reach that path; the `externalFeedID IS
 *     NULL` guard on both new statements is what states the rule, and
 *     `check_event_visibility.py` is what keeps it there.
 *
 * HOW TO RUN IT
 * -----------------------------------------------------------------------------
 *   docker run --rm -d --name evrep-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=1 \
 *     -e MYSQL_DATABASE=selftest_evrep -p 3399:3306 mysql:8.0.36
 *   # load web/_sql/full_schema.sql and every numbered migration, then:
 *   SELFTEST_DB_NAME=selftest_evrep SELFTEST_DB_PORT=3399 \
 *     SELFTEST_WEB_DIR=/tmp SELFTEST_WEB_PORT=9077 \
 *     php tools/event-repeat-pages-selftest.php
 *
 * `--rm` matters: a plain `docker rm` leaves the container's data behind in a
 * separate volume, and this machine once held 415 of them taking 101 GB.
 *
 * @package   WebMS Intra
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes and deletes rows");
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
$port    = ($portRaw === false || $portRaw === '') ? 9077 : (int) $portRaw;
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
const ORG   = 918001;
const OTHER = 918002;   // another organisation on the same installation, whose series must stay its own
const OTHER_SERIES = 918501;
const ADMIN = 918011;   // an administrator of the organisation: creates the events
const PLAIN = 918012;   // an ordinary administrator for the settings-page checks
const ZONE  = 'Europe/London';

/**
 * 🌐 The first part of every address this run asks for.
 *
 * WHY THIS IS HERE, because the first version of this file had no address and
 * every check failed in a way that looked like the feature was broken: with no
 * organisation in the address, `Site::preDetect()` falls back to organisation
 * 1, so the pages worked perfectly and created 368 events under SOMEBODY
 * ELSE'S organisation while every query in this file — all filtered on ORG —
 * found nothing. A test whose world is wrong reports a fault in the code.
 *
 * So this run switches the installation to telling organisations apart by the
 * first part of the address (recorded and put back by the clean-up) and asks
 * for everything under its own.
 */
const ADDR = '/selftest-evrep';

$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'started' => false, 'cleaned' => false, 'settings' => []];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Everything this run put in the database, taken out again. */
function removeWorld(): void
{
    global $db;
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    // Section N plants this to make ONE copy of a series fail. Dropped here
    // too, in case a run was interrupted while it was in place.
    $db->query('DROP TRIGGER IF EXISTS `selftest_evrep_block_one_date`');
    q('DELETE FROM tblRecurrenceRules WHERE siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblEvents WHERE siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblEventSeries WHERE siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 918000 AND 918999');
    q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 918000 AND 918999 OR siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblErrors WHERE userID BETWEEN 918000 AND 918999 OR siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblUserSites WHERE siteID IN (?, ?) OR userID BETWEEN 918000 AND 918999', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblUsers WHERE userID BETWEEN 918000 AND 918999');
    q('DELETE FROM tblSettings WHERE siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    q('DELETE FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG, OTHER]);
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}

/** Put back everything this run changed, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
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
        // The installation-wide settings this run borrowed, put back exactly
        // as they were — including "there was no row at all".
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
    // The scratch child: only the one this run made, and only while it still
    // holds this run's marker.
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.event-repeat-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and is
 * returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . ADDR . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ['Expect:'], CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
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
    if (preg_match('/^X-Event-Repeat-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
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

/** A page's text, with the tags taken out and the spaces tidied. */
function text(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}

/** Every event of one name, oldest date first, as the database holds them. */
function eventsNamed(string $name): array
{
    return q(
        'SELECT eventID, eventName, eventSlug, startDateTime, endDateTime, timezone, seriesID, '
        . 'locationName, categoryID, status, isPublic '
        . 'FROM tblEvents WHERE siteID = ? AND eventName = ? ORDER BY startDateTime, eventID',
        'is',
        [ORG, $name]
    );
}

/** Just the start readings of those events. */
function startsNamed(string $name): array
{
    return array_map(static fn (array $r): string => (string) $r['startDateTime'], eventsNamed($name));
}

/**
 * Set an INSTALLATION-WIDE setting for this run, remembering what it was.
 *
 * The clean-up puts every one of these back, including "there was no row at
 * all" — these rows belong to the whole installation and a throwaway database
 * is still somebody's database.
 */
function installSetting(string $key, string $value): void
{
    global $state;
    if (array_key_exists($key, $state['settings']) === false) {
        $state['settings'][$key] = one(
            'SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL',
            's',
            [$key]
        );
    }
    $row = one('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    if ($row === null) {
        q(
            'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)',
            'sss',
            [$key, $value, $value]
        );

        return;
    }
    q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$value, (int) $row['settingID']]);
}

/** One organisation's own value for a setting (replacing any it had). */
function siteSetting(int $site, string $key, string $value): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
    q(
        'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)',
        'isss',
        [$site, $key, $value, $value]
    );
}

// =============================================================================
// 🌍 Build the world
// =============================================================================
removeWorld();

q(
    "INSERT INTO tblSites (siteID, siteKey, siteName, isActive, timezone) VALUES "
    . "(?, 'selftest-evrep', 'Selftest repeats', 1, ?), "
    . "(?, 'selftest-evrep-other', 'Selftest repeats other', 1, ?)",
    'isis',
    [ORG, ZONE, OTHER, ZONE]
);
// 🛡️ A series belonging to the OTHER organisation. Section L posts its
//    number and nothing of this organisation's may end up in it.
q(
    "INSERT INTO tblEventSeries (seriesID, siteID, seriesName, seriesSlug) VALUES (?, ?, 'Other org series', 'other-org-series')",
    'ii',
    [OTHER_SERIES, OTHER]
);
foreach ([ADMIN => 'Ada Admin', PLAIN => 'Pat Plain'] as $id => $name) {
    q(
        'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 1, 0)',
        'iss',
        [$id, $name, 'u' . $id . '.evrep@selftest.invalid']
    );
    q(
        'INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 1, 0)',
        'ii',
        [$id, ORG]
    );
}
siteSetting(ORG, 'calendar.enabled', 'true');

// 🌐 Organisations told apart by the first part of the address, so this run's
//    requests reach ITS organisation rather than organisation 1. Recorded
//    first and put back by the clean-up, because these are installation-wide.
foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'path'] as $key => $value) {
    $row                     = one('SELECT settingID, settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    $state['settings'][$key] = $row;
    if ($row === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);
    } else {
        q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [$value, (int) $row['settingID']]);
    }
}

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (['admin' => ADMIN, 'plain' => PLAIN] as $who => $id) {
    q(
        'INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)',
        'iss',
        [$id, 'selftest-evrep-' . $who, $hash]
    );
}

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'event-repeat-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.event-repeat-selftest-owner', $state['token']); // the marker FIRST
register_shutdown_function('cleanup');
$state['started'] = true;

mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
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
mkdir($child . $sep . 'web' . $sep . '_uploads' . $sep . 'calendar', 0700, true);
mkdir($child . $sep . 'sessions', 0700);

$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/event-repeat-pages-selftest.php for one run; deleted with its folder.\nreturn "
    . var_export($creds, true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/event-repeat-pages-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Event-Repeat-Selftest: __TOKEN__');
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
    [
        PHP_BINARY,
        '-d', 'session.save_path=' . $child . $sep . 'sessions',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1',
        '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . $port,
        '-t', $child . $sep . 'web' . $sep . 'public_html',
        $child . $sep . 'router.php',
    ],
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $child . $sep . 'server-output.log', 'a'],
        2 => ['file', $child . $sep . 'server-output.log', 'a'],
    ],
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

echo "Repeating events, on the real pages — self-test (#569)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port
    . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v']
    . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

$jar = [];
/** Sign one account in, in a browser of its own. */
$signIn = static function (string $who) use (&$jar, $child, $sep, $password): array {
    $jar[$who] = $child . $sep . 'jar-' . $who . '.txt';
    $tok       = token(req($jar[$who], 'GET', '/login')['body']);

    return req($jar[$who], 'POST', '/login', [
        'csrf_token' => $tok,
        'username'   => 'selftest-evrep-' . $who,
        'password'   => $password,
    ]);
};
foreach (['admin', 'plain'] as $who) {
    $in = $signIn($who);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-evrep-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
}

/**
 * Ask for the Manage Events page and hand back its HTML and a fresh form token.
 *
 * The token is taken FRESH every time on purpose: `Auth::verifyCsrf()` replaces
 * it on every acceptance, so re-using one from an earlier page is how a test
 * starts failing for a reason that has nothing to do with what it checks.
 *
 * @return array{body:string, token:string}
 */
function managePage(string $jar): array
{
    $page = req($jar, 'GET', '/calendar/manage');

    return ['body' => $page['body'], 'token' => token($page['body'])];
}

/**
 * Create one event through the real form, and hand back where it landed and the
 * message it put up.
 *
 * @param array<string,mixed> $extra
 *
 * @return array{status:int, location:string, message:string}
 */
function createEvent(string $jar, string $name, string $start, string $end, array $extra = []): array
{
    $page = managePage($jar);
    $post = array_merge([
        'csrf_token'    => $page['token'],
        'action'        => 'create',
        'eventName'     => $name,
        'description'   => 'Made by tools/event-repeat-pages-selftest.php.',
        'startDateTime' => $start,
        'endDateTime'   => $end,
        'timezone'      => ZONE,
        'status'        => 'published',
        'locationName'  => 'The selftest hall',
    ], $extra);
    $sent = req($jar, 'POST', '/calendar/manage/save', $post);

    // The message lands in the session and is shown on the page it redirects
    // to, so it is read from there rather than from the redirect itself.
    $after = req($jar, 'GET', '/calendar/manage');

    return [
        'status'   => $sent['status'],
        'location' => $sent['location'],
        'message'  => flashOn($after['body']),
    ];
}

/**
 * The message Manage Events shows in its coloured box.
 *
 * It has to match `alert alert-<type> alert-dismissible fade show`, which is
 * what that page really writes — an earlier version of this helper required
 * the class list to END after the type, found nothing, and handed back
 * '(no message shown)'. That did not merely lose two checks: it made the
 * NEGATIVE check D04 ("the message does not mention extra dates") pass for the
 * wrong reason, because an empty string mentions nothing. A check that passes
 * when the thing it reads is missing is worse than no check.
 *
 * It is also anchored on `alert-dismissible`, because the same page draws
 * ordinary `alert alert-warning` boxes about events with an unknown time zone,
 * and matching the first alert on the page would read one of those instead.
 */
function flashOn(string $html): string
{
    if (preg_match('/<div class="alert alert-(\w+) alert-dismissible[^"]*">(.*?)<button/s', $html, $m) !== 1) {
        return '(no message shown)';
    }

    return trim(text($m[2]));
}

// =============================================================================
// A. The form really offers the controls, and its names match the handler's
// =============================================================================
head('A. The form and the handler agree about every field name');

$form = managePage($jar['admin']);

ok(
    'A01 the Manage Events page is served and carries a form token',
    $form['body'] !== '' && $form['token'] !== '',
    'token=' . $form['token'] . ' body=' . mb_substr(text($form['body']), 0, 200)
);

// 🔎 Every repeat field the SAVE HANDLER reads, taken out of the handler
//    itself. A list typed here would be a third copy to keep in step.
$handlerSource = (string) file_get_contents(dirname(__DIR__) . '/web/_apps/calendar/manage/save.php');
preg_match_all('/\$_POST\[\s*\'(repeat[A-Za-z]*)\'\s*\]/', $handlerSource, $found);
$fieldsRead = array_values(array_unique($found[1]));
sort($fieldsRead);

ok(
    'A02 the save handler really reads some repeat fields (the search works at all)',
    count($fieldsRead) >= 5,
    'found: ' . implode(', ', $fieldsRead)
);

$missing = [];
foreach ($fieldsRead as $field) {
    // `repeatDays` is posted as a list, so the form writes `repeatDays[]`.
    $asWritten = $field === 'repeatDays' ? 'repeatDays[]' : $field;
    if (str_contains($form['body'], 'name="' . $asWritten . '"') === false) {
        $missing[] = $asWritten;
    }
}
// ⚠️ RENAMING `repeatFrequency` ON THE FORM FAILS A03 AND NOTHING ELSE —
//    measured rather than assumed. Every other section posts the field names
//    itself, so the series is still created perfectly, while a real person's
//    form would silently create a one-off event.
//
//    Do NOT widen that into "A03 is the only check that catches a renamed
//    field", which is what this comment said first: H02 pins `repeatCount`'s
//    own attributes and A04 pins the five frequency values, so those names are
//    covered twice over. A03 is the only guard for the REST of them.
ok(
    'A03 every repeat field the handler reads is on the form the portal served',
    $missing === [],
    'not on the form: ' . implode(', ', $missing) . ' (handler reads: ' . implode(', ', $fieldsRead) . ')'
);

ok(
    'A04 the form offers "Does not repeat" and all five frequencies',
    str_contains($form['body'], 'Does not repeat') === true
    && str_contains($form['body'], 'value="weekly"') === true
    && str_contains($form['body'], 'value="fortnightly"') === true
    && str_contains($form['body'], 'value="monthly"') === true
    && str_contains($form['body'], 'value="quarterly"') === true
    && str_contains($form['body'], 'value="yearly"') === true
);

// =============================================================================
// B. A weekly repeat really creates the dates
// =============================================================================
head('B. A weekly repeat creates real events, a series and a rule');

$made = createEvent($jar['admin'], 'EVREP Weekly service', '2027-01-03T10:00', '2027-01-03T11:30', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
]);

ok('B01 the save redirects rather than showing an error', $made['status'] === 302, 'HTTP ' . $made['status']);

$weekly = eventsNamed('EVREP Weekly service');
ok(
    'B02 four events exist in the database, not one',
    count($weekly) === 4,
    count($weekly) . ' event(s); message was: ' . $made['message']
);
ok(
    'B03 they are the four consecutive Sundays, at the same time each week',
    startsNamed('EVREP Weekly service') === [
        '2027-01-03 10:00:00', '2027-01-10 10:00:00', '2027-01-17 10:00:00', '2027-01-24 10:00:00',
    ],
    implode(' | ', startsNamed('EVREP Weekly service'))
);

$seriesIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['seriesID'], $weekly)));
ok(
    'B04 all four belong to ONE series, the first occurrence included',
    count($seriesIds) === 1 && $seriesIds[0] > 0,
    'seriesID(s): ' . implode(', ', $seriesIds)
);

$series = $seriesIds[0] ?? 0;
$rule   = one('SELECT * FROM tblRecurrenceRules WHERE seriesID = ?', 'i', [$series]);
ok(
    'B05 a recurrence rule was written for that series — nothing in the portal ever wrote one before',
    $rule !== null && (string) $rule['frequency'] === 'weekly' && (int) $rule['maxOccurrences'] === 4,
    $rule === null ? 'no rule row at all' : json_encode($rule)
);
ok(
    'B06 the rule belongs to this organisation',
    $rule !== null && (int) $rule['siteID'] === ORG,
    $rule === null ? 'no rule row' : 'siteID=' . $rule['siteID']
);

// =============================================================================
// C. Each copy carries the rest of the event
// =============================================================================
head('C. Each date is a full event, not a stub');

$everyEndIsRight = true;
$everySlugUnique = [];
foreach ($weekly as $row) {
    if (substr((string) $row['endDateTime'], 11) !== '11:30:00') {
        $everyEndIsRight = false;
    }
    $everySlugUnique[] = (string) $row['eventSlug'];
}
ok('C01 every date keeps the same finishing time on the clock', $everyEndIsRight === true, json_encode(array_column($weekly, 'endDateTime')));
ok('C02 every date has its own address (slug)', count(array_unique($everySlugUnique)) === 4, implode(', ', $everySlugUnique));
ok(
    'C03 every date carries the venue, zone, status and visibility of the first',
    count(array_unique(array_column($weekly, 'locationName'))) === 1
    && array_unique(array_column($weekly, 'locationName'))[0] === 'The selftest hall'
    && count(array_unique(array_column($weekly, 'timezone'))) === 1
    && count(array_unique(array_column($weekly, 'status'))) === 1,
    json_encode(array_map(static fn (array $r): array => [$r['locationName'], $r['timezone'], $r['status']], $weekly))
);

// =============================================================================
// D. Not asking for a repeat makes exactly one event
// =============================================================================
head('D. Not asking for a repeat changes nothing');

$made = createEvent($jar['admin'], 'EVREP One-off', '2027-02-10T19:00', '2027-02-10T20:00');
$once = eventsNamed('EVREP One-off');
ok('D01 one event, and only one', count($once) === 1, count($once) . ' event(s)');
ok('D02 it belongs to no series', $once !== [] && (int) ($once[0]['seriesID'] ?? 0) === 0, json_encode($once[0]['seriesID'] ?? null));
ok(
    'D03 no rule was written for it',
    (int) one('SELECT COUNT(*) AS n FROM tblRecurrenceRules WHERE siteID = ?', 'i', [ORG])['n'] === 1,
    'rules for this organisation: ' . one('SELECT COUNT(*) AS n FROM tblRecurrenceRules WHERE siteID = ?', 'i', [ORG])['n']
);
ok(
    'D04 a message IS shown, and it does not mention extra dates',
    $made['message'] !== '(no message shown)'
    && str_contains($made['message'], 'created successfully') === true
    && str_contains($made['message'], 'more date') === false,
    $made['message']
);

// =============================================================================
// E. The message tells the truth about what was made
// =============================================================================
head('E. The message says what really happened');

$made = createEvent($jar['admin'], 'EVREP Fortnightly', '2027-03-02T18:00', '2027-03-02T19:00', [
    'repeatFrequency' => 'fortnightly',
    'repeatCount'     => '3',
]);
ok(
    'E01 three dates, a fortnight apart',
    startsNamed('EVREP Fortnightly') === ['2027-03-02 18:00:00', '2027-03-16 18:00:00', '2027-03-30 18:00:00'],
    implode(' | ', startsNamed('EVREP Fortnightly'))
);
ok(
    'E02 the message says 2 more dates were added — the number really made, not the number asked for',
    str_contains($made['message'], '2 more date') === true,
    $made['message']
);

// =============================================================================
// F. Monthly by the nth weekday, over HTTP
// =============================================================================
head('F. The same weekday each month, through the form');

createEvent($jar['admin'], 'EVREP Second Tuesday', '2027-04-13T19:30', '2027-04-13T21:00', [
    'repeatFrequency'   => 'monthly',
    'repeatMonthlyMode' => 'weekday',
    'repeatWeekOfMonth' => '2',
    'repeatDays'        => ['2'],
    'repeatCount'       => '3',
]);
ok(
    'F01 the second Tuesday of three months running',
    startsNamed('EVREP Second Tuesday') === ['2027-04-13 19:30:00', '2027-05-11 19:30:00', '2027-06-08 19:30:00'],
    implode(' | ', startsNamed('EVREP Second Tuesday'))
);

// =============================================================================
// G. Clock changes, on the real pages
// =============================================================================
head('G. The clocks change and the service stays at 10:00');

// 28 March 2027 is the Sunday the clocks go FORWARD in London; 31 October 2027
// is the Sunday they go back. A weekly 10:00 service must read 10:00 on both
// sides of each. This is the nominal rule, proved here through the form rather
// than against the generator.
createEvent($jar['admin'], 'EVREP Spring forward', '2027-03-21T10:00', '2027-03-21T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
ok(
    'G01 across the spring change, every date still reads 10:00',
    startsNamed('EVREP Spring forward') === ['2027-03-21 10:00:00', '2027-03-28 10:00:00', '2027-04-04 10:00:00'],
    implode(' | ', startsNamed('EVREP Spring forward'))
);

createEvent($jar['admin'], 'EVREP Autumn back', '2027-10-24T10:00', '2027-10-24T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
ok(
    'G02 across the autumn change, every date still reads 10:00',
    startsNamed('EVREP Autumn back') === ['2027-10-24 10:00:00', '2027-10-31 10:00:00', '2027-11-07 10:00:00'],
    implode(' | ', startsNamed('EVREP Autumn back'))
);

// =============================================================================
// H. The organisation's own ceiling really bites
// =============================================================================
head('H. The configurable ceiling, end to end');

// The ceiling was a constant in the class until 29 October 2026. The owner
// asked for it to be settable per organisation and per installation. These
// checks are the only ones that prove the SETTING reaches the generator through
// a real page — the generator's own test proves the arithmetic, not the wiring.
siteSetting(ORG, 'calendar.repeat.maxOccurrences', '5');
siteSetting(ORG, 'calendar.repeat.maxYears', '1');

$capForm = managePage($jar['admin']);
ok(
    'H01 the form tells the person the numbers in force HERE, not the built-in defaults',
    str_contains(text($capForm['body']), 'up to 1 year or 5 dates') === true,
    'the form says: ' . (preg_match('/Dates are made for[^.]*\./', text($capForm['body']), $m) === 1 ? $m[0] : 'nothing matching')
);
ok(
    'H02 and the "stop after this many dates" box will not accept more than the ceiling',
    str_contains($capForm['body'], 'name="repeatCount" id="repeatCount" min="1"') === true
    && preg_match('/name="repeatCount"[^>]*max="5"/', $capForm['body']) === 1,
    preg_match('/<input[^>]*name="repeatCount"[^>]*>/', $capForm['body'], $m) === 1 ? $m[0] : 'no repeatCount input found'
);

$made = createEvent($jar['admin'], 'EVREP Endless', '2027-05-02T09:00', '2027-05-02T10:00', [
    'repeatFrequency' => 'weekly',
]);
ok(
    'H03 an endless weekly repeat stops at this organisation\'s 5 dates, not at the default 200',
    count(eventsNamed('EVREP Endless')) === 5,
    count(eventsNamed('EVREP Endless')) . ' event(s) made; message: ' . $made['message']
);
ok(
    'H04 and the person is TOLD the series was cut short',
    str_contains($made['message'], '4 more date') === true
    && (str_contains($made['message'], 'Only the first 5 dates') === true
        || str_contains($made['message'], 'next 1 year') === true),
    $made['message']
);

// A repeat asking for MORE than the ceiling cannot have it, however it is
// posted — the form's `max` is a courtesy, this is the control.
$made = createEvent($jar['admin'], 'EVREP Greedy', '2027-06-06T09:00', '2027-06-06T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '400',
]);
ok(
    'H05 posting a count of 400 past the form still gives only 5 dates',
    count(eventsNamed('EVREP Greedy')) === 5,
    count(eventsNamed('EVREP Greedy')) . ' event(s)'
);

// And the years ceiling, on its own: a monthly repeat with no end under a
// one-year horizon.
$made = createEvent($jar['admin'], 'EVREP Horizon', '2027-07-04T15:00', '2027-07-04T16:00', [
    'repeatFrequency' => 'monthly',
]);
$horizon = startsNamed('EVREP Horizon');
ok(
    'H06 the years ceiling stops a monthly repeat a year out',
    count($horizon) === 5 && str_starts_with((string) end($horizon), '2027-11') === true,
    count($horizon) . ' date(s), last ' . (string) end($horizon)
);

// Put the organisation's ceiling back to the installation default so that the
// settings-page checks below start from a known place.
q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey LIKE ?', 'is', [ORG, 'calendar.repeat.%']);

// =============================================================================
// I. The settings page that changes the ceiling
// =============================================================================
head('I. The page that sets the ceiling');

$settingsPage = req($jar['admin'], 'GET', '/admin/settings/repeats');
ok('I01 the page is served to an administrator', $settingsPage['status'] === 200, 'HTTP ' . $settingsPage['status']);
ok(
    'I02 it says which level the numbers in force came from',
    str_contains(text($settingsPage['body']), 'installation-wide default') === true,
    mb_substr(text($settingsPage['body']), 0, 300)
);
ok(
    'I03 it says plainly that lowering a limit deletes nothing',
    str_contains(text($settingsPage['body']), 'never deletes anything') === true,
    'that sentence is not on the page'
);

// Saving for this organisation.
$saved = req($jar['admin'], 'POST', '/admin/settings/repeats/save', [
    'csrf_token'     => token($settingsPage['body']),
    'scope'          => 'site',
    'maxOccurrences' => '12',
    'maxYears'       => '2',
]);
$stored = q(
    'SELECT settingKey, settingValue FROM tblSettings WHERE siteID = ? AND settingKey LIKE ? ORDER BY settingKey',
    'is',
    [ORG, 'calendar.repeat.%']
);
ok(
    'I04 saving writes this organisation\'s own two rows',
    $saved['status'] === 302 && count($stored) === 2
    && (string) $stored[0]['settingValue'] === '12' && (string) $stored[1]['settingValue'] === '2',
    'HTTP ' . $saved['status'] . ' ' . json_encode($stored)
);

// Saving twice must not pile up rows — the unique key on (settingKey, siteID)
// is what makes ON DUPLICATE KEY UPDATE right here.
$page = req($jar['admin'], 'GET', '/admin/settings/repeats');
req($jar['admin'], 'POST', '/admin/settings/repeats/save', [
    'csrf_token'     => token($page['body']),
    'scope'          => 'site',
    'maxOccurrences' => '13',
    'maxYears'       => '2',
]);
$storedAgain = q('SELECT settingValue FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [ORG, 'calendar.repeat.maxOccurrences']);
ok(
    'I05 saving again changes the row rather than adding another',
    count($storedAgain) === 1 && (string) $storedAgain[0]['settingValue'] === '13',
    json_encode($storedAgain)
);

// A number out of range is REFUSED, not clamped, while the person is still
// there to be told.
$page   = req($jar['admin'], 'GET', '/admin/settings/repeats');
$refuse = req($jar['admin'], 'POST', '/admin/settings/repeats/save', [
    'csrf_token'     => token($page['body']),
    'scope'          => 'site',
    'maxOccurrences' => '99999',
    'maxYears'       => '2',
]);
$after = req($jar['admin'], 'GET', '/admin/settings/repeats');
$still = q('SELECT settingValue FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [ORG, 'calendar.repeat.maxOccurrences']);
ok(
    'I06 a number past the hard maximum is refused and nothing is written',
    (string) ($still[0]['settingValue'] ?? '') === '13'
    && str_contains(text($after['body']), 'Nothing was saved') === true,
    'stored ' . json_encode($still) . '; message: ' . mb_substr(text($after['body']), 0, 200)
);

// Zero is refused too: a ceiling of none would make the controls do nothing.
$page = req($jar['admin'], 'GET', '/admin/settings/repeats');
req($jar['admin'], 'POST', '/admin/settings/repeats/save', [
    'csrf_token'     => token($page['body']),
    'scope'          => 'site',
    'maxOccurrences' => '0',
    'maxYears'       => '2',
]);
$still = q('SELECT settingValue FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [ORG, 'calendar.repeat.maxOccurrences']);
ok('I07 zero is refused as well', (string) ($still[0]['settingValue'] ?? '') === '13', json_encode($still));

// A posted ARRAY must not raise an error: a request chooses the TYPE of what it
// sends as well as the value, and this project has already shipped one sign-in
// bypass that began exactly there.
$page  = req($jar['admin'], 'GET', '/admin/settings/repeats');
$array = req($jar['admin'], 'POST', '/admin/settings/repeats/save', [
    'csrf_token'     => token($page['body']),
    'scope'          => 'site',
    'maxOccurrences' => ['12'],
    'maxYears'       => '2',
]);
$still = q('SELECT settingValue FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [ORG, 'calendar.repeat.maxOccurrences']);
ok(
    'I08 a posted array is refused cleanly rather than raising an error',
    $array['status'] === 302 && (string) ($still[0]['settingValue'] ?? '') === '13',
    'HTTP ' . $array['status'] . ' stored ' . json_encode($still)
);

// An administrator of one organisation may NOT change the installation-wide
// default, and is refused by the handler rather than merely not shown a button.
$page    = req($jar['plain'], 'GET', '/admin/settings/repeats');
$beforeG = one('SELECT settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey = ?', 's', ['calendar.repeat.maxOccurrences']);
req($jar['plain'], 'POST', '/admin/settings/repeats/save', [
    'csrf_token'     => token($page['body']),
    'scope'          => 'global',
    'maxOccurrences' => '7',
    'maxYears'       => '7',
]);
$afterG = one('SELECT settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey = ?', 's', ['calendar.repeat.maxOccurrences']);
ok(
    'I09 an administrator who is not a global one cannot change the installation-wide default',
    ($beforeG['settingValue'] ?? null) === ($afterG['settingValue'] ?? null),
    'before ' . json_encode($beforeG) . ' after ' . json_encode($afterG)
);
ok(
    'I10 and the refusal is recorded',
    (int) one(
        'SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = ?',
        's',
        ['RepeatCeilingSaveRefused']
    )['n'] >= 1,
    'no refusal was recorded in the activity log'
);

// =============================================================================
// K. The installation has the last word over an organisation
// =============================================================================
head('K. The installation caps the organisation, on the real pages');

// -----------------------------------------------------------------------------
// THE RULE, and why it is checked over HTTP as well as in the class
// -----------------------------------------------------------------------------
// The owner set this on 29 October 2026: for a LIMIT, the installation's value
// takes precedence. An organisation may hold itself to a LOWER ceiling and can
// never raise its own above what the installation allows.
//
// The reason it matters that this is checked on the real pages, and not only in
// EventRecurrence's own test, is that the two levels MAY BE DIFFERENT PEOPLE —
// a hosting provider and its customers. So the question is not just "does the
// arithmetic take the smaller number" but "can an organisation's administrator
// get a bigger one past the save handler". A `max` on an input proves nothing;
// anything can be posted.
//
// The installation is set to a LOW ceiling and the organisation asks for a
// HIGH one. Both rows exist, so this cannot pass by accident.
installSetting('calendar.repeat.maxOccurrences', '6');
installSetting('calendar.repeat.maxYears', '1');
siteSetting(ORG, 'calendar.repeat.maxOccurrences', '500');
siteSetting(ORG, 'calendar.repeat.maxYears', '9');

$made = createEvent($jar['admin'], 'EVREP Capped', '2027-08-01T09:00', '2027-08-01T10:00', [
    'repeatFrequency' => 'weekly',
]);
ok(
    'K01 an organisation asking for 500 dates gets the installation\'s 6',
    count(eventsNamed('EVREP Capped')) === 6,
    count(eventsNamed('EVREP Capped')) . ' event(s) made; message: ' . $made['message']
);

$capForm = managePage($jar['admin']);
ok(
    'K02 the create form quotes the installation\'s numbers, not the organisation\'s wish',
    str_contains(text($capForm['body']), 'up to 1 year or 6 dates') === true,
    'the form says: ' . (preg_match('/Dates are made for[^.]*\./', text($capForm['body']), $m) === 1 ? $m[0] : 'nothing matching')
);

// An organisation may hold itself to LESS. That is what keeps the organisation
// level worth having, and it has to keep working while the cap is in place.
siteSetting(ORG, 'calendar.repeat.maxOccurrences', '3');
siteSetting(ORG, 'calendar.repeat.maxYears', '1');
createEvent($jar['admin'], 'EVREP Lower', '2027-09-05T09:00', '2027-09-05T10:00', [
    'repeatFrequency' => 'weekly',
]);
ok(
    'K03 an organisation may hold itself to fewer dates than the installation allows',
    count(eventsNamed('EVREP Lower')) === 3,
    count(eventsNamed('EVREP Lower')) . ' event(s)'
);

// The settings page has to SAY what the installation allows, because the person
// reading it may have no way to ask whoever set it.
$repeatsPage = req($jar['admin'], 'GET', '/admin/settings/repeats');
ok(
    'K04 the settings page says what the installation allows and that it cannot be exceeded',
    str_contains(text($repeatsPage['body']), 'This installation allows at most 6 dates') === true
    && str_contains(text($repeatsPage['body']), 'but not higher') === true,
    mb_substr(text($repeatsPage['body']), 0, 400)
);
ok(
    'K05 and the organisation\'s box offers no more than the installation allows',
    preg_match('/id="site-dates"[^>]*max="6"/', $repeatsPage['body']) === 1,
    preg_match('/<input[^>]*id="site-dates"[^>]*>/', $repeatsPage['body'], $m) === 1 ? $m[0] : 'no site-dates input'
);

// 🚧 THE CONTROL ITSELF: posting a number above the installation's limit, past
//    the form, must be REFUSED — not clamped, not stored.
$before  = one('SELECT settingValue FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [ORG, 'calendar.repeat.maxOccurrences']);
$pushed  = req($jar['admin'], 'POST', '/admin/settings/repeats/save', [
    'csrf_token'     => token($repeatsPage['body']),
    'scope'          => 'site',
    'maxOccurrences' => '400',
    'maxYears'       => '1',
]);
$after   = one('SELECT settingValue FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [ORG, 'calendar.repeat.maxOccurrences']);
$shown   = req($jar['admin'], 'GET', '/admin/settings/repeats');
ok(
    'K06 posting 400 dates against an installation limit of 6 is refused and nothing is written',
    ($before['settingValue'] ?? '') === ($after['settingValue'] ?? '')
    && $pushed['status'] === 302,
    'before ' . json_encode($before) . ' after ' . json_encode($after) . ' HTTP ' . $pushed['status']
);
ok(
    'K07 and the refusal SAYS whose limit it is, rather than quietly reducing the number',
    str_contains(text($shown['body']), 'what this installation allows') === true
    && str_contains(text($shown['body']), 'Nothing was saved') === true,
    mb_substr(text($shown['body']), 0, 400)
);
ok(
    'K08 the attempt to exceed the installation\'s limit is recorded',
    (int) one(
        'SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = ? AND activityDescription LIKE ?',
        'ss',
        ['RepeatCeilingSaveRefused', '%this installation allows%']
    )['n'] >= 1,
    'no record of the refusal'
);

// The years ceiling is judged on its own, so an organisation can be inside the
// limit on one and outside on the other.
$page    = req($jar['admin'], 'GET', '/admin/settings/repeats');
$yearsNo = req($jar['admin'], 'POST', '/admin/settings/repeats/save', [
    'csrf_token'     => token($page['body']),
    'scope'          => 'site',
    'maxOccurrences' => '3',
    'maxYears'       => '8',
]);
$shown   = req($jar['admin'], 'GET', '/admin/settings/repeats');
ok(
    'K09 a horizon above the installation\'s is refused on its own terms',
    str_contains(text($shown['body']), 'How far ahead has to be a whole number of years between 1 and 1') === true,
    mb_substr(text($shown['body']), 0, 300)
);

// 🏢 A GLOBAL administrator raising the installation's limit really does raise
//    what an organisation may then have. The whole point of three tiers.
installSetting('calendar.repeat.maxOccurrences', '40');
installSetting('calendar.repeat.maxYears', '2');
q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey LIKE ?', 'is', [ORG, 'calendar.repeat.%']);
createEvent($jar['admin'], 'EVREP Raised', '2027-10-03T09:00', '2027-10-03T10:00', [
    'repeatFrequency' => 'weekly',
]);
ok(
    'K10 raising the installation\'s limit raises what an organisation gets',
    count(eventsNamed('EVREP Raised')) === 40,
    count(eventsNamed('EVREP Raised')) . ' event(s)'
);

// And an organisation that has chosen NOTHING follows the installation rather
// than the built-in default. This is the direction the first version got
// wrong: resolving "not chosen" to the default made the default cap the
// installation, so an installation could lower a limit but never raise one.
// With the installation at 2 years, a monthly repeat must reach 2 years — not
// the built-in 3, and not stop short at 1.
createEvent($jar['admin'], 'EVREP Follows', '2027-11-07T09:00', '2027-11-07T10:00', [
    'repeatFrequency' => 'monthly',
]);
$follows = startsNamed('EVREP Follows');
ok(
    'K11 an organisation that has chosen nothing follows the installation\'s horizon',
    count($follows) === 25 && str_starts_with((string) end($follows), '2029-11') === true,
    count($follows) . ' date(s), last ' . (string) end($follows)
);

// =============================================================================
// L. The four faults the independent check measured
// =============================================================================
head('L. What the independent check found, now guarded');

/** The one recurrence rule of a series, as the feed will read it. */
function ruleOfSeries(int $seriesId): ?array
{
    return one('SELECT * FROM tblRecurrenceRules WHERE seriesID = ? ORDER BY ruleID DESC LIMIT 1', 'i', [$seriesId]);
}

/** The series a named event's rows belong to. */
function seriesOf(string $name): array
{
    return array_values(array_unique(array_map(
        static fn (array $r): int => (int) ($r['seriesID'] ?? 0),
        eventsNamed($name)
    )));
}

// -----------------------------------------------------------------------------
// L1 — a posted seriesID belonging to ANOTHER organisation must not be used.
// -----------------------------------------------------------------------------
// The repeat block read `$_POST['seriesID']` again, straight past the
// validation that had already refused it and set `$seriesID = null` (#514 D5).
// Measured: the first occurrence correctly got NULL and the COPIES landed in
// the other organisation's series, with a rule row owned by this organisation
// attached to it. There is no foreign key tying a series to a site, so the
// database accepted it.
$made = createEvent($jar['admin'], 'EVREP Foreign series', '2028-01-09T09:00', '2028-01-09T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
    'seriesID'        => (string) OTHER_SERIES,
]);
$foreign = eventsNamed('EVREP Foreign series');
ok(
    'L01 all three dates were still created',
    count($foreign) === 3,
    count($foreign) . ' event(s); message: ' . $made['message']
);
ok(
    'L02 not one of them is in the other organisation\'s series',
    in_array(OTHER_SERIES, seriesOf('EVREP Foreign series'), true) === false,
    'series used: ' . implode(', ', seriesOf('EVREP Foreign series'))
);
ok(
    'L03 no rule row was attached to the other organisation\'s series',
    ruleOfSeries(OTHER_SERIES) === null,
    json_encode(ruleOfSeries(OTHER_SERIES))
);
ok(
    'L04 the other organisation gained no events at all',
    (int) one('SELECT COUNT(*) AS n FROM tblEvents WHERE siteID = ?', 'i', [OTHER])['n'] === 0,
    one('SELECT COUNT(*) AS n FROM tblEvents WHERE siteID = ?', 'i', [OTHER])['n'] . ' event(s) in the other organisation'
);

// -----------------------------------------------------------------------------
// L2 — a seriesID that does not exist must not give a 500 or a half-made event.
// -----------------------------------------------------------------------------
// Measured before the fix: HTTP 500 after the event row was already committed,
// a foreign-key error in tblErrors, the member on an error page, one event, no
// repeats, nothing rolled back.
$made = createEvent($jar['admin'], 'EVREP Missing series', '2028-02-06T09:00', '2028-02-06T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
    'seriesID'        => '987654',
]);
ok(
    'L05 a series number that does not exist does not give an error page',
    $made['status'] === 302,
    'HTTP ' . $made['status']
);
ok(
    'L06 and the series is still created properly, with all three dates',
    count(eventsNamed('EVREP Missing series')) === 3,
    count(eventsNamed('EVREP Missing series')) . ' event(s)'
);

// -----------------------------------------------------------------------------
// L3 — THE RULE ROW MUST DESCRIBE THE DIARY, because the feed is built from it.
// -----------------------------------------------------------------------------
// `calendar/export.php` turns this one row into ONE repeating entry for every
// subscriber. Two faults were measured with the installation's limit at 5:
//   • both stop fields left empty (what the form invites) stored no count and
//     no end date, so the feed sent an ENDLESS weekly series while the diary
//     held five dates;
//   • `repeatCount=400` posted past the form stored 400, so the installation
//     published a 400-date series having capped the organisation at five.
// That second one is a route past the installation's limit — into what the
// installation publishes rather than into tblEvents, which is worse.
siteSetting(ORG, 'calendar.repeat.maxOccurrences', '5');
siteSetting(ORG, 'calendar.repeat.maxYears', '1');

// 👁️ `isPublic` matters here and nowhere else in this file: the
//    whole-calendar feed is built with ANONYMOUS visibility even for a signed-in
//    requester, so it carries public events only. Without this the feed held no
//    repeating entry at all — and L10, which looks for a BAD line, passed
//    happily on an empty feed. L11 is what caught that; a check that looks only
//    for the absence of something passes when there is nothing at all.
createEvent($jar['admin'], 'EVREP Feed endless', '2028-03-05T09:00', '2028-03-05T10:00', [
    'repeatFrequency' => 'weekly',
    'isPublic'        => '1',
]);
$series = seriesOf('EVREP Feed endless')[0] ?? 0;
$rule   = ruleOfSeries($series);
$inDiary = count(eventsNamed('EVREP Feed endless'));
ok(
    'L07 with both stop fields empty, the rule counts exactly the dates in the diary',
    $rule !== null && (int) $rule['maxOccurrences'] === $inDiary && $inDiary === 5,
    'diary ' . $inDiary . ', rule ' . json_encode($rule === null ? null : $rule['maxOccurrences'])
);
ok(
    'L08 and it carries no end date, so the feed cannot publish an UNTIL past the diary',
    $rule !== null && ($rule['endDate'] === null || (string) $rule['endDate'] === ''),
    'endDate=' . json_encode($rule === null ? null : $rule['endDate'])
);

createEvent($jar['admin'], 'EVREP Feed greedy', '2028-04-02T09:00', '2028-04-02T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '400',
    'isPublic'        => '1',
]);
$series  = seriesOf('EVREP Feed greedy')[0] ?? 0;
$rule    = ruleOfSeries($series);
$inDiary = count(eventsNamed('EVREP Feed greedy'));
ok(
    'L09 a count of 400 posted past the form is NOT what the feed publishes',
    $rule !== null && (int) $rule['maxOccurrences'] === $inDiary && $inDiary === 5,
    'diary ' . $inDiary . ', rule says ' . json_encode($rule === null ? null : $rule['maxOccurrences'])
);

// And the real feed, read over HTTP: the COUNT it sends must match the diary.
//
// `?all=1` is required — without it `calendar/export.php` has nothing selected
// and answers 404, which is how the first version of these two checks came to
// read an empty body. L11 caught it; L10, which looks for a BAD line, had
// passed on the 404 page.
$feed = req($jar['admin'], 'GET', '/calendar/export?all=1');
ok(
    'L09b the feed itself was served',
    $feed['status'] === 200,
    'HTTP ' . $feed['status']
);
preg_match_all('/RRULE:[^\r\n]+/', $feed['body'], $rrules);
$greedyRrule = '';
foreach ($rrules[0] as $line) {
    if (str_contains($line, 'COUNT=400') === true) {
        $greedyRrule = $line;
    }
}
ok(
    'L10 the feed really does carry repeating entries, and none claims 400 dates',
    $rrules[0] !== [] && $greedyRrule === '',
    count($rrules[0]) . ' RRULE line(s); the bad one: ' . ($greedyRrule === '' ? '(none)' : $greedyRrule)
);
ok(
    'L11 and no repeating entry is endless (every one carries a COUNT or an UNTIL)',
    $rrules[0] !== [] && count(array_filter(
        $rrules[0],
        static fn (string $l): bool => str_contains($l, 'COUNT=') === false && str_contains($l, 'UNTIL=') === false
    )) === 0,
    count($rrules[0]) . ' RRULE line(s): ' . implode(' | ', $rrules[0])
);

q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey LIKE ?', 'is', [ORG, 'calendar.repeat.%']);

// -----------------------------------------------------------------------------
// L4 — the rule row must not describe a pattern that was never made.
// -----------------------------------------------------------------------------
// `dayOfWeek` and `dayOfMonth` were written regardless of frequency. The form
// shows every box at once on purpose, so "every month, the same date" with a
// weekday box also ticked is ORDINARY — and it stored both, while
// `export.php` prefers `dayOfWeek`, publishing "every Monday of every month"
// against one date a month in the diary.
createEvent($jar['admin'], 'EVREP Monthly date', '2028-05-15T09:00', '2028-05-15T10:00', [
    'repeatFrequency'   => 'monthly',
    'repeatMonthlyMode' => 'day',
    'repeatDays'        => ['1'],   // a weekday ticked that does NOT apply
    'repeatCount'       => '3',
]);
$rule = ruleOfSeries(seriesOf('EVREP Monthly date')[0] ?? 0);
ok(
    'L12 "every month, the same date" stores the date and NOT the ticked weekday',
    $rule !== null && ($rule['dayOfWeek'] === null || (string) $rule['dayOfWeek'] === '')
    && (int) $rule['dayOfMonth'] === 15,
    json_encode($rule === null ? null : ['dayOfWeek' => $rule['dayOfWeek'], 'dayOfMonth' => $rule['dayOfMonth']])
);
ok(
    'L13 and the dates really are the 15th of three months',
    startsNamed('EVREP Monthly date') === ['2028-05-15 09:00:00', '2028-06-15 09:00:00', '2028-07-15 09:00:00'],
    implode(' | ', startsNamed('EVREP Monthly date'))
);

// A weekly series must not store a day of the month, which means nothing for it.
createEvent($jar['admin'], 'EVREP Weekly clean', '2028-06-04T09:00', '2028-06-04T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$rule = ruleOfSeries(seriesOf('EVREP Weekly clean')[0] ?? 0);
ok(
    'L14 a weekly rule stores no day of the month, no week of the month and no month',
    $rule !== null && $rule['dayOfMonth'] === null && $rule['weekOfMonth'] === null && $rule['monthOfYear'] === null,
    json_encode($rule === null ? null : [$rule['dayOfMonth'], $rule['weekOfMonth'], $rule['monthOfYear']])
);

// Monthly by the nth weekday with TWO ticked: the generator uses only the
// first, so the rule must name only the first.
createEvent($jar['admin'], 'EVREP Nth two days', '2028-07-11T19:00', '2028-07-11T20:00', [
    'repeatFrequency'   => 'monthly',
    'repeatMonthlyMode' => 'weekday',
    'repeatWeekOfMonth' => '2',
    'repeatDays'        => ['2', '4'],
    'repeatCount'       => '2',
]);
$rule = ruleOfSeries(seriesOf('EVREP Nth two days')[0] ?? 0);
ok(
    'L15 an nth-weekday rule names only the weekday the dates were actually made on',
    $rule !== null && (string) $rule['dayOfWeek'] === '2' && (int) $rule['weekOfMonth'] === 2
    && $rule['dayOfMonth'] === null,
    json_encode($rule === null ? null : ['dayOfWeek' => $rule['dayOfWeek'], 'weekOfMonth' => $rule['weekOfMonth'], 'dayOfMonth' => $rule['dayOfMonth']])
);

// -----------------------------------------------------------------------------
// L5 — the message must not say a series was cut short when it was not.
// -----------------------------------------------------------------------------
// Measured: asking for 3 dates under a ceiling of 5 produced "Only the first 3
// dates were made. Extend the series later to add more." Nothing was cut short.
$made = createEvent($jar['admin'], 'EVREP Asked three', '2028-08-06T09:00', '2028-08-06T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
ok(
    'L16 asking for 3 dates and getting 3 is not reported as the series being cut short',
    str_contains($made['message'], '2 more date') === true
    && str_contains($made['message'], 'Only the first') === false
    && str_contains($made['message'], 'Extend the series') === false,
    $made['message']
);

// And the opposite direction, so the message has not simply been switched off.
siteSetting(ORG, 'calendar.repeat.maxOccurrences', '4');
$made = createEvent($jar['admin'], 'EVREP Really cut', '2028-09-03T09:00', '2028-09-03T10:00', [
    'repeatFrequency' => 'weekly',
]);
ok(
    'L17 a series the ceiling really did cut short still says so',
    str_contains($made['message'], 'Only the first 4 dates') === true,
    $made['message']
);
q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey LIKE ?', 'is', [ORG, 'calendar.repeat.%']);

// =============================================================================
// M. What round two of the independent check found
// =============================================================================
head('M. The diary and the published pattern must be the same series');

// -----------------------------------------------------------------------------
// M1 — the typed start and the chosen pattern can disagree in one click.
// -----------------------------------------------------------------------------
// The form's weekday boxes are independent of the date typed above them, and
// nothing makes them agree. Start Wednesday with THURSDAY ticked and the
// generator produces Thursdays; the old code threw the first of them away
// (`array_slice($dates, 1)` assumed date 0 was the event just inserted) and
// left the diary holding the typed Wednesday — a day the pattern never makes.
// The rule row then described Thursdays, so a subscriber expanding it saw a
// date the portal did not have, and never saw the one it did.
$made = createEvent($jar['admin'], 'EVREP Pattern wins', '2028-10-04T19:00', '2028-10-04T20:00', [
    'repeatFrequency' => 'weekly',
    'repeatDays'      => ['4'],   // Thursday, while the start above is a Wednesday
    'repeatCount'     => '4',
]);
ok(
    'M01 the diary holds four Thursdays, with nothing on the typed Wednesday',
    startsNamed('EVREP Pattern wins') === [
        '2028-10-05 19:00:00', '2028-10-12 19:00:00', '2028-10-19 19:00:00', '2028-10-26 19:00:00',
    ],
    implode(' | ', startsNamed('EVREP Pattern wins'))
);
ok(
    'M02 and the person is told the first date moved, rather than it changing silently',
    str_contains($made['message'], 'first date was moved to 5 October 2028') === true,
    $made['message']
);
ok(
    'M03 the end of the moved first date moved with it',
    (string) (eventsNamed('EVREP Pattern wins')[0]['endDateTime'] ?? '') === '2028-10-05 20:00:00',
    json_encode(eventsNamed('EVREP Pattern wins')[0]['endDateTime'] ?? null)
);

// The same shape for monthly by weekday, where the start is nowhere near the
// nth weekday asked for.
createEvent($jar['admin'], 'EVREP First Monday', '2028-12-20T19:00', '2028-12-20T20:00', [
    'repeatFrequency'   => 'monthly',
    'repeatMonthlyMode' => 'weekday',
    'repeatWeekOfMonth' => '1',
    'repeatDays'        => ['1'],
    'repeatCount'       => '3',
]);
ok(
    'M04 "the first Monday" from a Wednesday start gives three first Mondays',
    startsNamed('EVREP First Monday') === [
        '2029-01-01 19:00:00', '2029-02-05 19:00:00', '2029-03-05 19:00:00',
    ],
    implode(' | ', startsNamed('EVREP First Monday'))
);

// -----------------------------------------------------------------------------
// M2 — the week does not start on the same day in both halves.
// -----------------------------------------------------------------------------
// `EventRecurrence` counts "every other week" from SUNDAY. RFC 5545 §3.3.10
// defaults WKST to MONDAY, and WKST only matters for a weekly rule with an
// interval above 1. So "Sunday and Wednesday, every fortnight" — an ordinary
// church pattern — put six dates in the diary while the feed described a
// different six, three of them wrong. `export.php` now declares WKST=SU.
createEvent($jar['admin'], 'EVREP Fortnight SuWe', '2028-11-05T10:00', '2028-11-05T11:00', [
    'repeatFrequency' => 'fortnightly',
    'repeatDays'      => ['0', '3'],
    'repeatCount'     => '6',
    'isPublic'        => '1',
]);
ok(
    'M05 the diary holds the fortnightly Sunday-and-Wednesday run',
    startsNamed('EVREP Fortnight SuWe') === [
        '2028-11-05 10:00:00', '2028-11-08 10:00:00', '2028-11-19 10:00:00',
        '2028-11-22 10:00:00', '2028-12-03 10:00:00', '2028-12-06 10:00:00',
    ],
    implode(' | ', startsNamed('EVREP Fortnight SuWe'))
);

$feed = req($jar['admin'], 'GET', '/calendar/export?all=1');
preg_match_all('/RRULE:[^\r\n]+/', $feed['body'], $rr);
$fortnightly = '';
foreach ($rr[0] as $line) {
    if (str_contains($line, 'INTERVAL=2') === true && str_contains($line, 'BYDAY=SU,WE') === true) {
        $fortnightly = $line;
    }
}
ok(
    'M06 the published rule for it declares the week starting on Sunday',
    $fortnightly !== '' && str_contains($fortnightly, 'WKST=SU') === true,
    $fortnightly === '' ? 'no fortnightly RRULE in the feed at all' : $fortnightly
);

// And WKST is only declared where it can change anything, so no other series'
// output has quietly changed shape.
$weeklyOnce = '';
foreach ($rr[0] as $line) {
    if (str_contains($line, 'FREQ=WEEKLY') === true && str_contains($line, 'INTERVAL=') === false) {
        $weeklyOnce = $line;
    }
}
ok(
    'M07 a plain weekly rule, where the week start cannot matter, does not declare it',
    $weeklyOnce !== '' && str_contains($weeklyOnce, 'WKST=') === false,
    $weeklyOnce === '' ? 'no plain weekly RRULE in the feed' : $weeklyOnce
);

// -----------------------------------------------------------------------------
// M3 — a rule row may exist only where the series IS the pattern.
// -----------------------------------------------------------------------------
// `export.php` reads a series' rule with `ORDER BY ruleID LIMIT 1` and then
// skips every other event of that series. So adding dates to a series that
// already has a rule would publish the OLD pattern and make the new dates
// vanish from every subscriber's calendar. Attaching to an existing series
// therefore removes its rule and writes none.
$firstSeries = seriesOf('EVREP Pattern wins')[0] ?? 0;
ok(
    'M08 the series this run created does carry a rule',
    ruleOfSeries($firstSeries) !== null,
    'no rule on series ' . $firstSeries
);

createEvent($jar['admin'], 'EVREP Joins existing', '2029-04-04T18:00', '2029-04-04T19:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
    'seriesID'        => (string) $firstSeries,
]);
ok(
    'M09 joining an existing series removes its now-untrue rule rather than adding a second',
    ruleOfSeries($firstSeries) === null
    && (int) one('SELECT COUNT(*) AS n FROM tblRecurrenceRules WHERE seriesID = ?', 'i', [$firstSeries])['n'] === 0,
    one('SELECT COUNT(*) AS n FROM tblRecurrenceRules WHERE seriesID = ?', 'i', [$firstSeries])['n'] . ' rule row(s)'
);
ok(
    'M10 and the new dates were still created',
    count(eventsNamed('EVREP Joins existing')) === 3,
    count(eventsNamed('EVREP Joins existing')) . ' event(s)'
);

// -----------------------------------------------------------------------------
// M4 — every published repeating entry must describe exactly its own rows.
// -----------------------------------------------------------------------------
// The one sweeping check of this package: for every series in this
// organisation that carries a rule, the rule's COUNT must equal the number of
// events really in that series. That is the invariant the whole of section L
// and this section exist to protect, asked once, of everything.
// A NULL count is treated as a mismatch ON PURPOSE rather than skipped: this
// portal's writer always sets one, so a rule without a count did not come from
// here. `(int) null` being 0 would have made that look like a count of zero,
// which is why it is named separately.
$mismatches = [];
foreach (q(
    'SELECT r.seriesID, r.maxOccurrences, '
    . '(SELECT COUNT(*) FROM tblEvents e WHERE e.seriesID = r.seriesID AND e.isDeleted = 0) AS rows_in_series '
    . 'FROM tblRecurrenceRules r WHERE r.siteID = ?',
    'i',
    [ORG]
) as $row) {
    if ($row['maxOccurrences'] === null) {
        $mismatches[] = 'series ' . $row['seriesID'] . ': rule has no count at all';
        continue;
    }
    if ((int) $row['maxOccurrences'] !== (int) $row['rows_in_series']) {
        $mismatches[] = 'series ' . $row['seriesID'] . ': rule says ' . $row['maxOccurrences']
            . ', diary holds ' . $row['rows_in_series'];
    }
}
ok(
    'M11 every rule in this organisation counts exactly the events in its own series',
    $mismatches === [],
    implode('; ', $mismatches)
);

// =============================================================================
// N. The feed checks a pattern against its series before publishing it
// =============================================================================
head('N. A pattern is published only while it is still true');

// -----------------------------------------------------------------------------
// WHY THIS SECTION EXISTS
// -----------------------------------------------------------------------------
// Round 3 of the independent check measured the invariant being broken by two
// ORDINARY actions, neither of which goes anywhere near the repeat code:
//
//   • Attaching a one-off event to a series that has a rule, using the Series
//     drop-down on the event form. The series then held five rows against a
//     rule saying four — and the added event was published NOWHERE, because
//     collapsing skips every other row of the series. A public event invisible
//     in every subscriber's calendar.
//   • Moving ONE occurrence, which is how a coordinator moves a single date.
//     The feed went on publishing the original pattern, including a date the
//     diary had never held.
//
// The save handler cannot know about every later edit, so the guard now lives
// where a rule is USED: `export.php` expands the stored pattern and refuses to
// publish it unless it produces exactly this series' run as this reader may see
// it — the same dates, the same lengths, and the same rendered details. These
// checks drive both failures through real pages and read the real feed.
//
// (An earlier version of this comment described a counts-and-earliest-row test,
// which is what the guard did for one round before it was measured insufficient:
// moving a date leaves the count unchanged.)

/** Every RRULE line in the served feed. */
function feedRrules(string $jar): array
{
    $feed = req($jar, 'GET', '/calendar/export?all=1');
    if ($feed['status'] !== 200) {
        return ['(feed not served: HTTP ' . $feed['status'] . ')'];
    }
    preg_match_all('/RRULE:[^\r\n]+/', $feed['body'], $m);

    return $m[0];
}

/** Whether the served feed names an event, by its SUMMARY line. */
function feedNames(string $jar, string $name): bool
{
    $feed = req($jar, 'GET', '/calendar/export?all=1');

    return $feed['status'] === 200 && str_contains($feed['body'], 'SUMMARY:' . $name) === true;
}

// N1 — a ruled series is collapsed while it is still true.
createEvent($jar['admin'], 'EVREP Guard base', '2029-06-03T10:00', '2029-06-03T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'isPublic'        => '1',
]);
$guardSeries = seriesOf('EVREP Guard base')[0] ?? 0;
$before      = feedRrules($jar['admin']);
ok(
    'N01 while the series matches its rule, the feed does publish it as one repeating entry',
    count(array_filter($before, static fn (string $l): bool => str_contains($l, 'COUNT=4') === true)) >= 1
    && feedNames($jar['admin'], 'EVREP Guard base') === true,
    implode(' | ', $before)
);

// N2 — attaching an ordinary one-off event to that series breaks the pattern.
//      The feed must stop collapsing it AND must carry the added event.
createEvent($jar['admin'], 'EVREP Guard extra', '2029-07-10T19:00', '2029-07-10T20:00', [
    'seriesID' => (string) $guardSeries,
    'isPublic' => '1',
]);
ok(
    'N02 the series really did gain a row its rule does not count',
    (int) one('SELECT COUNT(*) AS n FROM tblEvents WHERE seriesID = ? AND isDeleted = 0', 'i', [$guardSeries])['n'] === 5
    && (int) (ruleOfSeries($guardSeries)['maxOccurrences'] ?? 0) === 4,
    'rows ' . one('SELECT COUNT(*) AS n FROM tblEvents WHERE seriesID = ? AND isDeleted = 0', 'i', [$guardSeries])['n']
    . ', rule ' . json_encode(ruleOfSeries($guardSeries)['maxOccurrences'] ?? null)
);
ok(
    'N03 and the added event IS in the feed, instead of being skipped by a collapse',
    feedNames($jar['admin'], 'EVREP Guard extra') === true,
    'the feed does not name it: it was swallowed by the series collapse'
);
ok(
    'N04 the now-untrue pattern is no longer published',
    count(array_filter(
        feedRrules($jar['admin']),
        static fn (string $l): bool => str_contains($l, 'COUNT=4') === true
    )) === 0,
    implode(' | ', feedRrules($jar['admin']))
);

// N3 — moving ONE occurrence of a ruled series has the same effect. Done with
//      SQL rather than the edit form on purpose: what is being tested is the
//      FEED's behaviour for a given state of the diary, and the state is the
//      input. (The edit form reaching that state is its own path.)
createEvent($jar['admin'], 'EVREP Guard moved', '2029-09-02T10:00', '2029-09-02T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'isPublic'        => '1',
]);
$movedSeries = seriesOf('EVREP Guard moved')[0] ?? 0;
ok(
    'N05 it starts out collapsed, so the check below is not vacuous',
    count(array_filter(
        feedRrules($jar['admin']),
        static fn (string $l): bool => str_contains($l, 'COUNT=4') === true
    )) >= 1,
    implode(' | ', feedRrules($jar['admin']))
);

// Move the EARLIEST date out of the run, which is the case that also breaks
// DTSTART rather than only the count.
q(
    'UPDATE tblEvents SET startDateTime = ?, endDateTime = ? WHERE seriesID = ? AND startDateTime = ?',
    'ssis',
    ['2029-11-11 10:00:00', '2029-11-11 11:00:00', $movedSeries, '2029-09-02 10:00:00']
);
$after = feedRrules($jar['admin']);
ok(
    'N06 a series whose earliest date has moved is not published as the old pattern',
    count(array_filter($after, static fn (string $l): bool => str_contains($l, 'COUNT=4') === true)) === 0,
    implode(' | ', $after)
);
ok(
    'N07 and every one of its dates is in the feed on its own, the moved one included',
    substr_count(
        req($jar['admin'], 'GET', '/calendar/export?all=1')['body'],
        'SUMMARY:EVREP Guard moved'
    ) === 4,
    substr_count(req($jar['admin'], 'GET', '/calendar/export?all=1')['body'], 'SUMMARY:EVREP Guard moved')
    . ' entries for a series of 4'
);

// N4 — a soft-deleted occurrence counts as gone, so the pattern must go too.
createEvent($jar['admin'], 'EVREP Guard deleted', '2029-10-07T10:00', '2029-10-07T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'isPublic'        => '1',
]);
q(
    'UPDATE tblEvents SET isDeleted = 1 WHERE seriesID = ? ORDER BY startDateTime DESC LIMIT 1',
    'i',
    [seriesOf('EVREP Guard deleted')[0] ?? 0]
);
$feedNow = feedRrules($jar['admin']);
ok(
    'N08 a series with a date removed is not published as a pattern that still counts it',
    count(array_filter($feedNow, static fn (string $l): bool => str_contains($l, 'COUNT=4') === true)) === 0,
    implode(' | ', $feedNow)
);

// -----------------------------------------------------------------------------
// N5 — the third leg of the invariant in the save handler: if a date cannot be
//      created, the rule is withdrawn. Round 3 found this leg had NO check at
//      all — replacing it with `if (false)` left all 81 green, because nothing
//      in the suite ever made a copy fail.
// -----------------------------------------------------------------------------
// A trigger is the reliable way to make exactly one copy fail: the slug the
// copies use cannot be predicted from outside the request.
$db->query(
    "CREATE TRIGGER `selftest_evrep_block_one_date` BEFORE INSERT ON `tblEvents` "
    . "FOR EACH ROW BEGIN "
    . "IF NEW.startDateTime = '2029-12-16 09:00:00' THEN "
    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'selftest: this one date refuses to save'; "
    . "END IF; END"
);
$made = createEvent($jar['admin'], 'EVREP Guard partial', '2029-12-02T09:00', '2029-12-02T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'isPublic'        => '1',
]);
$db->query('DROP TRIGGER IF EXISTS `selftest_evrep_block_one_date`');

$partialSeries = seriesOf('EVREP Guard partial')[0] ?? 0;
ok(
    'N09 one date really did fail to save, so this check is not vacuous',
    count(eventsNamed('EVREP Guard partial')) === 3,
    count(eventsNamed('EVREP Guard partial')) . ' event(s) made; message: ' . $made['message']
);
ok(
    'N10 and the rule was withdrawn rather than left over-promising',
    $partialSeries > 0 && ruleOfSeries($partialSeries) === null,
    'series ' . $partialSeries . ' rule: ' . json_encode(ruleOfSeries($partialSeries))
);
ok(
    'N11 the withdrawal is recorded, with the numbers that did not match',
    (int) one(
        'SELECT COUNT(*) AS n FROM tblErrors WHERE siteID = ? AND errorCode = ?',
        'is',
        [ORG, 'REPEAT_RULE_WITHDRAWN']
    )['n'] >= 1,
    'nothing recorded under REPEAT_RULE_WITHDRAWN'
);

// -----------------------------------------------------------------------------
// N6 — a series HALF IN THE PAST is still published as one pattern, and that
//      pattern BEGINS WHERE THE SERIES BEGINS.
// -----------------------------------------------------------------------------
// The whole-calendar feed carries only events from now on. An earlier version
// of this guard compared the pattern against the rows IN HAND, so a series that
// had already begun could never match and every ongoing weekly service went out
// one entry per date — safe, but exactly the series a subscriber most wants as
// one tidy entry. The owner chose on 29 October 2026 to make it work.
//
// What has to be true now, and the second half is the one that can go wrong
// silently: the series IS collapsed, and the single entry starts at the
// series' OWN first date rather than at the first date this download happens to
// carry. Starting it at the first future row while counting the whole run would
// advertise dates past the real end of the series.
//
// The dates are worked out from today rather than written down, so this cannot
// rot into "a series wholly in the past" next year. 35 days is exactly five
// weeks, so an 8-date weekly series always straddles today whatever the weekday.
$pastStartStamp = strtotime('-35 days 09:00');
$pastStart      = date('Y-m-d\TH:i', $pastStartStamp);
createEvent($jar['admin'], 'EVREP Guard halfpast', $pastStart, date('Y-m-d\TH:i', strtotime('-35 days 10:00')), [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '8',
    'isPublic'        => '1',
]);
$halfPast = eventsNamed('EVREP Guard halfpast');
$pastRows = count(array_filter($halfPast, static fn (array $r): bool => (string) $r['startDateTime'] < gmdate('Y-m-d H:i:s')));
ok(
    'N13 the series really does straddle today, so the checks below are not vacuous',
    count($halfPast) === 8 && $pastRows > 0 && $pastRows < 8,
    count($halfPast) . ' row(s), of which ' . $pastRows . ' in the past'
);

$halfBody = req($jar['admin'], 'GET', '/calendar/export?all=1')['body'];
ok(
    'N14 it IS published as one repeating entry, counting the whole run of 8',
    substr_count($halfBody, 'SUMMARY:EVREP Guard halfpast') === 1
    && count(array_filter(
        feedRrules($jar['admin']),
        static fn (string $l): bool => str_contains($l, 'COUNT=8') === true
    )) >= 1,
    substr_count($halfBody, 'SUMMARY:EVREP Guard halfpast') . ' entries; rrules: '
    . implode(' | ', feedRrules($jar['admin']))
);
ok(
    'N15 and that entry begins at the series\' OWN first date, which is in the past',
    str_contains($halfBody, date('Ymd\THis', $pastStartStamp)) === true,
    'the feed does not carry ' . date('Ymd\THis', $pastStartStamp)
    . ', so the pattern was started somewhere other than the series\' first date'
);
ok(
    'N16 there is exactly one DTSTART for it, at the series\' first date',
    substr_count($halfBody, 'DTSTART;TZID=' . ZONE . ':' . date('Ymd\THis', $pastStartStamp)) === 1,
    'expected exactly one DTSTART at the series\' first date'
);

// -----------------------------------------------------------------------------
// N7 — THE PRIVACY HALF. A series holding a date this reader may not see must
//      not be published as a pattern that counts it.
// -----------------------------------------------------------------------------
// This is the check that was missing, and a planted fault proved it: reading
// the series' run WITHOUT the visibility rule left all 113 checks green, and
// `check_event_visibility.py` was satisfied too, because the rule's name still
// appeared in a comment nearby. So neither guard could see the leak.
//
// The whole-calendar download answers under the ANONYMOUS rule whoever asks, so
// marking one occurrence as not public is enough to put it out of reach. The
// pattern then expands to four dates against three rows in hand, so the series
// is not collapsed — and the date nobody may see is not advertised by a COUNT.
createEvent($jar['admin'], 'EVREP Guard private', '2032-01-04T10:00', '2032-01-04T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'isPublic'        => '1',
]);
$privateRun = eventsNamed('EVREP Guard private');
ok(
    'N17 it starts out collapsed, so the checks below are not vacuous',
    substr_count(
        req($jar['admin'], 'GET', '/calendar/export?all=1')['body'],
        'SUMMARY:EVREP Guard private'
    ) === 1,
    substr_count(req($jar['admin'], 'GET', '/calendar/export?all=1')['body'], 'SUMMARY:EVREP Guard private') . ' entries'
);

// The THIRD date becomes members-only, so the public feed may not carry it.
q(
    'UPDATE tblEvents SET isPublic = 0 WHERE eventID = ?',
    'i',
    [(int) ($privateRun[2]['eventID'] ?? 0)]
);
$privateBody = req($jar['admin'], 'GET', '/calendar/export?all=1')['body'];
ok(
    'N18 the series is no longer published as one pattern counting four dates',
    count(array_filter(
        feedRrules($jar['admin']),
        static fn (string $l): bool => str_contains($l, 'COUNT=4') === true
    )) === 0,
    implode(' | ', feedRrules($jar['admin']))
);
ok(
    'N19 only the three dates this reader may see are in the feed',
    substr_count($privateBody, 'SUMMARY:EVREP Guard private') === 3,
    substr_count($privateBody, 'SUMMARY:EVREP Guard private') . ' entries, expected 3'
);
// ⚠️ THIS CHECK LOOKS FOR THE DATE'S OWN TEXT ONLY, which is narrower than its
//    name suggested at first. With the visibility rule removed from the run
//    query the hidden date is advertised through the pattern's COUNT, and the
//    text never appears — so this alone would pass. N18 and N19 are what catch
//    that, measured by planting it. The label now says what it checks.
ok(
    'N20 and the hidden date is not written out anywhere in the feed either',
    str_contains($privateBody, '20320118T100000') === false,
    'the feed carries 2032-01-18, which this reader may not see'
);

// N6 — WKST is declared only where it can change an expansion. Round 3 found
//      that removing the WEEKLY guard left every check green, so the claim
//      "no other series' output changes" was unpinned.
createEvent($jar['admin'], 'EVREP Guard quarterly', '2030-01-08T09:00', '2030-01-08T10:00', [
    'repeatFrequency'   => 'quarterly',
    'repeatMonthlyMode' => 'day',
    'repeatCount'       => '3',
    'isPublic'          => '1',
]);
$quarterly = '';
foreach (feedRrules($jar['admin']) as $line) {
    if (str_contains($line, 'FREQ=MONTHLY') === true && str_contains($line, 'INTERVAL=3') === true) {
        $quarterly = $line;
    }
}
ok(
    'N12 a quarterly rule is published, and does NOT declare a week start',
    $quarterly !== '' && str_contains($quarterly, 'WKST=') === false,
    $quarterly === '' ? 'no quarterly RRULE in the feed at all' : $quarterly
);

// =============================================================================
// P. An occurrence edited WITHOUT moving its start
// =============================================================================
head('P. Editing one date, not only moving it');

// -----------------------------------------------------------------------------
// WHY THIS SECTION EXISTS
// -----------------------------------------------------------------------------
// Round 4 of the independent check measured the same fault round 3 had found,
// reached through a different column. The guard compared START readings only,
// so renaming the third occurrence of a public series and extending it by two
// hours left the start alone — the series still collapsed, the renamed event
// appeared in NO subscriber's calendar, and the feed went on advertising the
// old finishing time for a date that had changed.
//
// The guard now compares the ends as well, and every field the published entry
// actually renders. These checks drive the REAL update handler, because
// "editing one date" is an action the form itself advertises.

/** Edit one event through the real update handler, changing only what is named. */
function editEvent(string $jar, array $row, array $changes): array
{
    $page = managePage($jar);
    $post = array_merge([
        'csrf_token'      => $page['token'],
        'action'          => 'update',
        'eventID'         => (string) $row['eventID'],
        'eventName'       => (string) $row['eventName'],
        'description'     => 'Made by tools/event-repeat-pages-selftest.php.',
        'startDateTime'   => str_replace(' ', 'T', substr((string) $row['startDateTime'], 0, 16)),
        'endDateTime'     => str_replace(' ', 'T', substr((string) $row['endDateTime'], 0, 16)),
        'timezone'        => (string) $row['timezone'],
        'status'          => (string) $row['status'],
        'locationName'    => (string) $row['locationName'],
        'seriesID'        => (string) ($row['seriesID'] ?? ''),
        'isPublic'        => ((int) $row['isPublic']) === 1 ? '1' : '',
    ], $changes);

    return req($jar, 'POST', '/calendar/manage/save', $post);
}

createEvent($jar['admin'], 'EVREP Edited one', '2031-02-02T10:00', '2031-02-02T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'isPublic'        => '1',
]);
ok(
    'P01 it starts out collapsed, so the checks below are not vacuous',
    count(array_filter(
        feedRrules($jar['admin']),
        static fn (string $l): bool => str_contains($l, 'COUNT=4') === true
    )) >= 1,
    implode(' | ', feedRrules($jar['admin']))
);

// Rename the THIRD date and lengthen it, leaving its start exactly as it was.
$third = eventsNamed('EVREP Edited one')[2] ?? null;
$sent  = editEvent($jar['admin'], (array) $third, [
    'eventName'   => 'EVREP Harvest special',
    'endDateTime' => '2031-02-16T13:00',
]);
$edited = one(
    'SELECT eventName, startDateTime, endDateTime, seriesID FROM tblEvents WHERE eventID = ?',
    'i',
    [(int) ($third['eventID'] ?? 0)]
);
ok(
    'P02 the edit landed: renamed and lengthened, with its start untouched and still in the series',
    $edited !== null
    && (string) $edited['eventName'] === 'EVREP Harvest special'
    && (string) $edited['startDateTime'] === '2031-02-16 10:00:00'
    && (string) $edited['endDateTime'] === '2031-02-16 13:00:00'
    && (int) $edited['seriesID'] > 0,
    'HTTP ' . $sent['status'] . ' ' . json_encode($edited)
);
ok(
    'P03 the renamed date is in the feed under its own name, not swallowed by the series',
    feedNames($jar['admin'], 'EVREP Harvest special') === true,
    'the feed does not name it: the collapse is still hiding it'
);
ok(
    'P04 the series is no longer published as one pattern',
    count(array_filter(
        feedRrules($jar['admin']),
        static fn (string $l): bool => str_contains($l, 'COUNT=4') === true
    )) === 0,
    implode(' | ', feedRrules($jar['admin']))
);
ok(
    'P05 and the feed states the new finishing time, not the old one',
    str_contains(req($jar['admin'], 'GET', '/calendar/export?all=1')['body'], '20310216T130000') === true,
    'no 13:00 end for 2031-02-16 anywhere in the feed'
);

// The other direction: an edit that changes NOTHING the entry renders must not
// stop the collapse, or every series would fall apart at the first save.
createEvent($jar['admin'], 'EVREP Edited harmless', '2031-05-04T10:00', '2031-05-04T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
    'isPublic'        => '1',
]);
$second = eventsNamed('EVREP Edited harmless')[1] ?? null;
editEvent($jar['admin'], (array) $second, []);   // saved with every field as it was
ok(
    'P06 re-saving one date unchanged leaves the series collapsed',
    count(array_filter(
        feedRrules($jar['admin']),
        static fn (string $l): bool => str_contains($l, 'COUNT=3') === true
    )) >= 1,
    implode(' | ', feedRrules($jar['admin']))
);

// -----------------------------------------------------------------------------
// EACH GUARD NEEDS ITS OWN CASE, which P02–P05 above do NOT give it.
// -----------------------------------------------------------------------------
// Those checks rename AND lengthen the same occurrence, so the two halves of
// the guard mask each other: with the END comparison removed the name still
// differs, and with the FIELD comparison removed the end still differs. Both
// faults were planted and ALL 106 checks stayed green — which is exactly the
// shape this project treats as a test proving nothing.
//
// So each is isolated below by changing one thing and nothing else.

// P11 — the END alone. Lengthen one occurrence, same name, same everything.
createEvent($jar['admin'], 'EVREP Longer only', '2031-03-02T10:00', '2031-03-02T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
    'isPublic'        => '1',
]);
$lonely = eventsNamed('EVREP Longer only');
ok(
    'P11 it starts out collapsed: one entry for three dates',
    substr_count(
        req($jar['admin'], 'GET', '/calendar/export?all=1')['body'],
        'SUMMARY:EVREP Longer only'
    ) === 1,
    substr_count(req($jar['admin'], 'GET', '/calendar/export?all=1')['body'], 'SUMMARY:EVREP Longer only') . ' entries'
);
editEvent($jar['admin'], (array) ($lonely[1] ?? []), ['endDateTime' => '2031-03-09T13:00']);
ok(
    'P12 lengthening ONE date, with nothing else changed, stops the collapse',
    substr_count(
        req($jar['admin'], 'GET', '/calendar/export?all=1')['body'],
        'SUMMARY:EVREP Longer only'
    ) === 3,
    substr_count(req($jar['admin'], 'GET', '/calendar/export?all=1')['body'], 'SUMMARY:EVREP Longer only')
    . ' entries, expected 3'
);
ok(
    'P13 and the feed states the new finishing time for that date',
    str_contains(req($jar['admin'], 'GET', '/calendar/export?all=1')['body'], '20310309T130000') === true,
    'no 13:00 end for 2031-03-09 in the feed'
);

// P14 — the NAME alone. Rename one occurrence, same dates, same times.
createEvent($jar['admin'], 'EVREP Renamed only', '2031-04-06T10:00', '2031-04-06T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
    'isPublic'        => '1',
]);
$renameable = eventsNamed('EVREP Renamed only');
ok(
    'P14 it starts out collapsed: one entry for three dates',
    substr_count(
        req($jar['admin'], 'GET', '/calendar/export?all=1')['body'],
        'SUMMARY:EVREP Renamed only'
    ) === 1,
    substr_count(req($jar['admin'], 'GET', '/calendar/export?all=1')['body'], 'SUMMARY:EVREP Renamed only') . ' entries'
);
editEvent($jar['admin'], (array) ($renameable[1] ?? []), ['eventName' => 'EVREP Renamed guest speaker']);
$afterRename = req($jar['admin'], 'GET', '/calendar/export?all=1')['body'];
ok(
    'P15 renaming ONE date, with nothing else changed, stops the collapse',
    substr_count($afterRename, 'SUMMARY:EVREP Renamed only') === 2,
    substr_count($afterRename, 'SUMMARY:EVREP Renamed only') . ' entries under the old name, expected 2'
);
ok(
    'P16 and the renamed date appears under its own name',
    str_contains($afterRename, 'SUMMARY:EVREP Renamed guest speaker') === true,
    'the renamed date is not in the feed under its new name'
);

// -----------------------------------------------------------------------------
// P2 — "Or stop after this date", which no check in this file had ever posted.
// -----------------------------------------------------------------------------
// Round 4 found `repeatUntil` appeared nowhere in this test, although the form
// offers it and the save handler parses it. A whole branch of the feature was
// untested.
$made = createEvent($jar['admin'], 'EVREP Until a date', '2031-08-03T09:00', '2031-08-03T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatUntil'     => '2031-08-24',
    'isPublic'        => '1',
]);
ok(
    'P07 "stop after this date" gives every date up to and including it',
    startsNamed('EVREP Until a date') === [
        '2031-08-03 09:00:00', '2031-08-10 09:00:00', '2031-08-17 09:00:00', '2031-08-24 09:00:00',
    ],
    implode(' | ', startsNamed('EVREP Until a date'))
);
ok(
    'P08 and nothing claims the series was cut short, because it ended where it was told to',
    str_contains($made['message'], 'Only the first') === false
    && str_contains($made['message'], 'Extend the series') === false,
    $made['message']
);
$untilRule = ruleOfSeries(seriesOf('EVREP Until a date')[0] ?? 0);
ok(
    'P09 its rule counts the four dates, and still stores no end date',
    $untilRule !== null && (int) $untilRule['maxOccurrences'] === 4
    && ($untilRule['endDate'] === null || (string) $untilRule['endDate'] === ''),
    json_encode($untilRule === null ? null : [$untilRule['maxOccurrences'], $untilRule['endDate']])
);
ok(
    'P10 and it is published as one pattern counting exactly those four',
    count(array_filter(
        feedRrules($jar['admin']),
        static fn (string $l): bool => str_contains($l, 'COUNT=4') === true
    )) >= 1,
    implode(' | ', feedRrules($jar['admin']))
);

// =============================================================================
// Q. The three things round 5 found unpinned
// =============================================================================
head('Q. What nothing was watching');

// -----------------------------------------------------------------------------
// Q1 — THE MAP POSITION IS RENDERED, and a comment said it was not.
// -----------------------------------------------------------------------------
// The calendar file carries a GEO line. A comment listed the map position as an
// example of something that "does not reach the file", so the two coordinate
// columns were left out of the comparison: moving one occurrence's pin left the
// series collapsed and published that one pin for every date. Measured by the
// independent check, and false in the direction of claiming more safety than
// there was.
createEvent($jar['admin'], 'EVREP Guard pin', '2032-03-07T10:00', '2032-03-07T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'isPublic'        => '1',
    'locationGeoLat'  => '52.2000000',
    'locationGeoLng'  => '0.1200000',
]);
$pinned = eventsNamed('EVREP Guard pin');
ok(
    'Q01 it starts out collapsed and carries a map position',
    substr_count(
        req($jar['admin'], 'GET', '/calendar/export?all=1')['body'],
        'SUMMARY:EVREP Guard pin'
    ) === 1
    && str_contains(req($jar['admin'], 'GET', '/calendar/export?all=1')['body'], 'GEO:52.2') === true,
    'entries: ' . substr_count(req($jar['admin'], 'GET', '/calendar/export?all=1')['body'], 'SUMMARY:EVREP Guard pin')
);

q(
    'UPDATE tblEvents SET locationGeoLat = 1.0000000, locationGeoLng = 2.0000000 WHERE eventID = ?',
    'i',
    [(int) ($pinned[1]['eventID'] ?? 0)]
);
$pinBody = req($jar['admin'], 'GET', '/calendar/export?all=1')['body'];
ok(
    'Q02 moving ONE occurrence\'s pin stops the collapse',
    substr_count($pinBody, 'SUMMARY:EVREP Guard pin') === 4,
    substr_count($pinBody, 'SUMMARY:EVREP Guard pin') . ' entries, expected 4'
);
ok(
    'Q03 and the moved pin is published as its own, not the first date\'s',
    str_contains($pinBody, 'GEO:1.0') === true,
    'the feed does not carry the moved pin'
);

// -----------------------------------------------------------------------------
// Q2 — a DRAFT occurrence inside a ruled series.
// -----------------------------------------------------------------------------
// WHAT GUARDS THIS, precisely, because it is not what it looks like. Round 5
// found that removing the `status = 'published'` filter from the run query left
// every check green, and these checks do not catch it either — measured. That is
// not a hole: `status` is one of the columns the pattern comparison requires to
// be identical across the run, so a drafted occurrence is refused there whether
// the query filtered it out or not. The two are redundant for this case.
//
// So what Q05 and Q06 pin is the BEHAVIOUR — a drafted date neither collapses
// the series nor gets advertised — and not either mechanism on its own. Said
// plainly rather than left looking like a guard it is not.
createEvent($jar['admin'], 'EVREP Guard draft', '2032-06-06T10:00', '2032-06-06T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'isPublic'        => '1',
]);
ok(
    'Q04 it starts out collapsed, so the check below is not vacuous',
    substr_count(
        req($jar['admin'], 'GET', '/calendar/export?all=1')['body'],
        'SUMMARY:EVREP Guard draft'
    ) === 1,
    substr_count(req($jar['admin'], 'GET', '/calendar/export?all=1')['body'], 'SUMMARY:EVREP Guard draft') . ' entries'
);
q(
    'UPDATE tblEvents SET status = ? WHERE eventID = ?',
    'si',
    ['draft', (int) (eventsNamed('EVREP Guard draft')[2]['eventID'] ?? 0)]
);
$draftBody = req($jar['admin'], 'GET', '/calendar/export?all=1')['body'];
// ⚠️ COUNTED BY THIS SERIES' OWN ENTRIES, not by looking for a COUNT=4 line
//    anywhere in the feed. That was the first version and it failed for a
//    reason worth keeping: several other series in this file also publish
//    COUNT=4, so "no COUNT=4 in the feed" is not a statement about THIS series
//    at all. One entry means collapsed; three mean listed date by date.
ok(
    'Q05 drafting one date stops the collapse rather than advertising its date',
    substr_count($draftBody, 'SUMMARY:EVREP Guard draft') === 3,
    substr_count($draftBody, 'SUMMARY:EVREP Guard draft') . ' entries, expected 3 (1 would mean still collapsed)'
);
ok(
    'Q06 and the drafted date itself is nowhere in the feed',
    str_contains($draftBody, '20320620T100000') === false,
    'the feed carries 2032-06-20, which is only a draft'
);

// -----------------------------------------------------------------------------
// Q3 — the published entry's IDENTIFIER must be the series' first occurrence,
//      and must not change as dates pass.
// -----------------------------------------------------------------------------
// The entry used to take its dates from the series' first row and its
// identifier, link and last-changed time from the first FUTURE row. So the
// entry was identified by one occurrence and dated at another, the identifier
// changed every time a date passed, and the same series went out under two
// different identifiers depending on which address was asked. A subscribing
// client saw a delete-and-re-add each week.
$idStart = date('Y-m-d\TH:i', strtotime('-21 days 14:00'));
createEvent($jar['admin'], 'EVREP Guard uid', $idStart, date('Y-m-d\TH:i', strtotime('-21 days 15:00')), [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '6',
    'isPublic'        => '1',
]);
$uidRun   = eventsNamed('EVREP Guard uid');
$firstId  = (int) ($uidRun[0]['eventID'] ?? 0);
$allBody  = req($jar['admin'], 'GET', '/calendar/export?all=1')['body'];
$seriesId = seriesOf('EVREP Guard uid')[0] ?? 0;
$oneBody  = req($jar['admin'], 'GET', '/calendar/export?series=' . $seriesId)['body'];
ok(
    'Q07 the whole-calendar feed identifies the entry by the series\' FIRST occurrence',
    $firstId > 0 && str_contains($allBody, 'UID:event-' . $firstId . '@') === true,
    'expected UID:event-' . $firstId . '; the feed has: '
    . (preg_match('/UID:event-\d+@/', $allBody, $m) === 1 ? $m[0] : 'no UID at all')
);
ok(
    'Q08 and the single-series feed gives it the SAME identifier',
    str_contains($oneBody, 'UID:event-' . $firstId . '@') === true,
    'the two addresses disagree about the identifier'
);
ok(
    'Q09 the identifier is not that of the first date still to come',
    count($uidRun) === 6
    && str_contains($allBody, 'UID:event-' . (int) ($uidRun[3]['eventID'] ?? 0) . '@') === false,
    'the entry is identified by a later occurrence, so it will change as dates pass'
);

// =============================================================================
// J. Nothing fell over on the way
// =============================================================================
// DELIBERATELY LAST, whatever its letter: it reads the server's error log for
// the WHOLE run, so anything after it would go unwatched. It sat between I and
// K for a while and K's requests were outside it.
head('J. Nothing fell over on the way');

// ⚠️ WHY THIS GUARD IS SHAPED THE WAY IT IS.
//    The checks below all look for the ABSENCE of a phrase, so they would all
//    pass on an empty string if the path were simply wrong — the vacuous-check
//    shape this project flags elsewhere, and the independent check flagged it
//    here.
//
//    But requiring the file to EXIST is wrong too, and that was the first
//    attempt: PHP creates it only when it actually writes something, so a clean
//    run leaves no file at all and the guard failed on a perfectly good run.
//
//    What has to be true is that a log COULD have been written: the folder the
//    server was told to write it in is there and writable. Then "no file" means
//    "nothing was logged", which is the answer these checks want.
$logPath  = $child . $sep . 'php-errors.log';
$couldLog = is_dir(dirname($logPath)) === true && is_writable(dirname($logPath)) === true;
ok(
    'J00 the server could have written an error log, so the checks below mean something',
    $couldLog === true,
    'the folder for ' . $logPath . ' is missing or not writable, so an empty log proves nothing'
);
$errorLog = is_file($logPath) === true ? (string) file_get_contents($logPath) : '';
// A fatal error, an undefined constant or an undefined method would all land
// here, and every page would still have returned something. This is the check
// that would have caught the renamed constant in the form.
$serious = [];
foreach ([
    'PHP Fatal error', 'Uncaught Error', 'Undefined constant', 'Undefined method',
    'Undefined variable', 'Undefined array key', 'Uncaught mysqli_sql_exception',
] as $phrase) {
    if (str_contains($errorLog, $phrase) === true) {
        $serious[] = $phrase;
    }
}
ok(
    'J01 the server\'s error log holds no fatal error, undefined constant or undefined variable',
    $serious === [],
    implode(', ', $serious) . "\n        " . mb_substr($errorLog, 0, 700)
);

// ⚠️ Section N makes ONE date fail on purpose, with a trigger, to prove the
//    rule is withdrawn when it does. Those two records are expected; anything
//    else here is not. They are named rather than the list being emptied,
//    because "ignore everything" is how a real fault hides.
$expectedCodes = ['REPEAT_DATE_FAILED', 'REPEAT_RULE_WITHDRAWN'];
$portalErrors  = array_values(array_filter(
    q('SELECT errorCode, errorTitle, errorDetail FROM tblErrors WHERE siteID = ? ORDER BY errorID', 'i', [ORG]),
    static fn (array $r): bool => in_array((string) $r['errorCode'], $expectedCodes, true) === false
));
ok(
    'J02 no date of any series failed to save, beyond the one section N breaks on purpose',
    $portalErrors === [],
    json_encode($portalErrors)
);


echo "\n" . ($pass + $fail) . " checks: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
