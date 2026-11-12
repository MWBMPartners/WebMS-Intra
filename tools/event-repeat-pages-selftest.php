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
 *   • Two administrators changing the same series' pattern at the same moment.
 *     Section R checks that a confirmation is refused when the series has moved
 *     under it, but it does so by moving the series ITSELF between the two
 *     requests — the built-in server runs one worker here, so a genuine overlap
 *     is impossible and a test of one would pass on broken code.
 *   • Two people creating the same series at once. The built-in server runs
 *     with one worker here (PHP_CLI_SERVER_WORKERS is unset), so two requests
 *     never overlap and such a test would pass on broken code.
 *   • Section T (#691) drives the hidden-dates restore on the bulk edit page,
 *     INCLUDING one genuine race: this run holds the series' row lock on its own
 *     connection while the real restore waits on it over HTTP. That needs no
 *     second worker, because the two events that must be ordered are this
 *     connection's commit and the page's read, not two requests.
 *   • Section U (#998, #1000) drives the events API with an administrator's
 *     signed-in session only, never with an API key, and only the half of the
 *     reason rule the API has: it takes no reason of its own (#1002), so it
 *     can only EMPTY one. Its race (U39-U41) is the section T kind: this
 *     connection holds the row while the real save waits on it.
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
const MEMBER = 918013;  // NOT an administrator at all: section R checks the page refuses them
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
    // ♻️ Section T removes `uq_event_site_slug` for a moment to reach the one
    //    case the key otherwise makes impossible, and puts it back in a
    //    `finally`. A run killed in between would leave the database without
    //    it, so the key is put back here too — AFTER this run's rows are gone,
    //    because the planted duplicate address would stop it being re-added.
    $hasKey = one(
        "SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
        . "AND TABLE_NAME = 'tblEvents' AND INDEX_NAME = 'uq_event_site_slug'"
    );
    if ((int) ($hasKey['n'] ?? 1) === 0) {
        $db->query('ALTER TABLE `tblEvents` ADD UNIQUE KEY `uq_event_site_slug` (`siteID`, `eventSlug`)');
    }
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
// 👤 An ordinary member, deliberately NOT an administrator. Section R checks
//    that the pattern page refuses them — both the page and a post straight at
//    it, because a gate that only hides a button is not a gate (#675).
q(
    'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)',
    'iss',
    [MEMBER, 'Mo Member', 'u' . MEMBER . '.evrep@selftest.invalid']
);
q(
    'INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)',
    'ii',
    [MEMBER, ORG]
);

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
foreach (['admin' => ADMIN, 'plain' => PLAIN, 'member' => MEMBER] as $who => $id) {
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
foreach (['admin', 'plain', 'member'] as $who) {
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
// N6 — #670's two remaining symptoms, measured rather than assumed.
// -----------------------------------------------------------------------------
// #670 says a repeating series' feed "sends the pattern only", so cancelling,
// moving or renaming ONE date is invisible to a subscriber. N06 to N08 above
// already disprove that for MOVING and REMOVING. These two cover the rest, so
// the whole of that issue is answered by something that runs rather than by
// reading `$patternStillTrue`:
//
//   • CANCELLING one date. TWO things would stop the collapse and they MASK
//     EACH OTHER, which is worth stating because it decides what N21 actually
//     proves: `status` is one of the columns the guard requires to be
//     identical across the run, AND the feed's own status filter drops a
//     cancelled event before the guard sees it. Measured by planting: with
//     `status` taken out of the compared list, N21 still passes — the
//     cancelled row simply is not in the run, so the date lists differ and the
//     collapse fails on that instead. So N21 proves the BEHAVIOUR (a
//     subscriber is not left seeing a cancelled date) and not which of the two
//     carries it. `export.php`'s own note says the same thing about the pair,
//     and it is right: removing either alone changes nothing observable.
//   • RENAMING one date — the rendered-field comparison should break the
//     collapse, because the collapsed entry would otherwise carry row 0's name
//     and speak for a date that no longer has it.
//
// Each starts from a series the feed really does collapse, so neither check can
// pass vacuously — the mistake N05 and N09 exist to rule out.
createEvent($jar['admin'], 'EVREP Guard cancelled', '2029-10-14T10:00', '2029-10-14T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'isPublic'        => '1',
]);
$cancelledSeries = seriesOf('EVREP Guard cancelled')[0] ?? 0;
$beforeCancel    = feedRrules($jar['admin']);
ok(
    'N20 the cancelled-date series starts out collapsed, so the next check is not vacuous',
    count(array_filter($beforeCancel, static fn (string $l): bool => str_contains($l, 'COUNT=4') === true)) >= 1,
    implode(' | ', $beforeCancel)
);
q("UPDATE tblEvents SET status = 'cancelled' WHERE seriesID = ? ORDER BY startDateTime DESC LIMIT 1", 'i', [$cancelledSeries]);
$afterCancel = feedRrules($jar['admin']);
ok(
    'N21 #670: CANCELLING one date of a series stops the pattern being published, so a subscriber is not left seeing it',
    count(array_filter($afterCancel, static fn (string $l): bool => str_contains($l, 'COUNT=4') === true)) === 0,
    implode(' | ', $afterCancel)
);

createEvent($jar['admin'], 'EVREP Guard renamed', '2029-10-21T10:00', '2029-10-21T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'isPublic'        => '1',
]);
$renamedSeries = seriesOf('EVREP Guard renamed')[0] ?? 0;
$beforeRename  = feedRrules($jar['admin']);
ok(
    'N22 the renamed-date series starts out collapsed too',
    count(array_filter($beforeRename, static fn (string $l): bool => str_contains($l, 'COUNT=4') === true)) >= 1,
    implode(' | ', $beforeRename)
);
q("UPDATE tblEvents SET eventName = 'EVREP Guard renamed ONE DATE' WHERE seriesID = ? ORDER BY startDateTime DESC LIMIT 1", 'i', [$renamedSeries]);
$feedBody = req($jar['admin'], 'GET', '/calendar/export?all=1')['body'];
ok(
    'N23 #670: RENAMING one date stops the pattern being published, and the new name really reaches the subscriber',
    count(array_filter(feedRrules($jar['admin']), static fn (string $l): bool => str_contains($l, 'COUNT=4') === true)) === 0
        && str_contains($feedBody, 'SUMMARY:EVREP Guard renamed ONE DATE') === true
        && substr_count($feedBody, 'SUMMARY:EVREP Guard renamed') === 4,
    'entries carrying the series name: ' . substr_count($feedBody, 'SUMMARY:EVREP Guard renamed')
        . '; the changed one is ' . (str_contains($feedBody, 'SUMMARY:EVREP Guard renamed ONE DATE') === true ? 'present' : 'MISSING')
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
// R. Changing the pattern of a series that already exists (#669)
// =============================================================================
// Repeats used to be offered on CREATION only, so this section is the other
// half of the feature. It drives /calendar/manage/series-repattern over real
// HTTP and then reads the rows back out of the database, because a preview can
// say anything — what matters is what is in the diary afterwards.
//
// `tools/series-repattern-selftest.php` proves the DECISION with no database:
// which dates may be moved, added, removed or left alone. This section proves
// the page really does what the decision says, and that nothing is written
// before somebody confirms it.
head('R. Changing the pattern of a series that already exists (#669)');

/** Every row of a named series, soft-deleted ones included. */
function repatRows(string $name): array
{
    return q(
        'SELECT eventID, eventName, eventSlug, startDateTime, endDateTime, patternStart, patternEnd, isDeleted, '
        . 'deletedAt, seriesID, locationName, categoryID, timezone, status '
        . 'FROM tblEvents WHERE siteID = ? AND eventName = ? ORDER BY startDateTime, eventID',
        'is',
        [ORG, $name]
    );
}

/** Just the starts of the rows still live. */
function repatLiveStarts(string $name): array
{
    $out = [];
    foreach (repatRows($name) as $row) {
        if ((int) $row['isDeleted'] === 0) {
            $out[] = (string) $row['startDateTime'];
        }
    }

    return $out;
}

/** Open the repattern page for one series, with a fresh form token. */
function repatOpen(string $jar, int $seriesId): array
{
    $page = req($jar, 'GET', '/calendar/manage/series-repattern?seriesID=' . $seriesId);

    return [
        'status' => $page['status'],
        'body'   => $page['body'],
        'token'  => token($page['body']),
        'digest' => preg_match('/name="planDigest" value="([0-9a-f]{64})"/', $page['body'], $m) === 1 ? $m[1] : '',
    ];
}

/**
 * Post a pattern to the page.
 *
 * The token is taken fresh from the page first, every time, because
 * `Auth::verifyCsrf()` replaces it on every acceptance.
 *
 * @param array<string,string> $fields
 */
function repatPost(string $jar, int $seriesId, array $fields, bool $confirm = false, ?string $digest = null): array
{
    $page = repatOpen($jar, $seriesId);
    $post = array_merge(
        ['csrf_token' => $page['token'], 'seriesID' => (string) $seriesId],
        $fields
    );
    if ($confirm === true) {
        $post['confirm']    = '1';
        $post['planDigest'] = $digest ?? '';
    }
    $sent = req($jar, 'POST', '/calendar/manage/series-repattern', $post);

    return [
        'status'   => $sent['status'],
        'location' => $sent['location'],
        'body'     => $sent['body'],
        'digest'   => preg_match('/name="planDigest" value="([0-9a-f]{64})"/', $sent['body'], $m) === 1 ? $m[1] : '',
    ];
}

/** The series number a named set of events belongs to. */
function repatSeries(string $name): int
{
    $row = one(
        'SELECT seriesID FROM tblEvents WHERE siteID = ? AND eventName = ? AND seriesID IS NOT NULL LIMIT 1',
        'is',
        [ORG, $name]
    );

    return $row === null ? 0 : (int) $row['seriesID'];
}

// -----------------------------------------------------------------------------
// R1 — the create handler records where the pattern put each date.
// -----------------------------------------------------------------------------
// Migration 232's column, filled by the save handler. Without it every date
// would read as "origin not recorded" and the page would fall back to
// re-reading the rule — which works, but is the weaker of the two answers.
createEvent($jar['admin'], 'EVREP Repat shift', '2029-01-07T10:00', '2029-01-07T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
]);
$shiftRows = repatRows('EVREP Repat shift');
ok('R01 the weekly series was created with four dates', count($shiftRows) === 4, (string) count($shiftRows));
$allMarked = true;
foreach ($shiftRows as $row) {
    if ((string) $row['patternStart'] !== (string) $row['startDateTime']) {
        $allMarked = false;
    }
}
ok('R02 every date records where the pattern put it, equal to its own start', $allMarked,
    implode(' | ', array_map(static fn (array $r): string => (string) $r['patternStart'] . ' vs ' . (string) $r['startDateTime'], $shiftRows)));

$shiftSeries = repatSeries('EVREP Repat shift');
ok('R03 those dates belong to a series', $shiftSeries > 0, (string) $shiftSeries);

// -----------------------------------------------------------------------------
// R4 — the page opens, and offers the controls the handler reads.
// -----------------------------------------------------------------------------
$open = repatOpen($jar['admin'], $shiftSeries);
ok('R04 the page opens for an administrator', $open['status'] === 200, 'HTTP ' . $open['status']);
foreach (['patternStart', 'patternEnd', 'repeatFrequency', 'repeatCount', 'repeatUntil', 'repeatDays[]', 'repeatMonthlyMode', 'repeatWeekOfMonth'] as $field) {
    ok("R05 the form offers {$field}", str_contains($open['body'], 'name="' . $field . '"'));
}
ok('R06 it says plainly that only future dates change',
    str_contains(text($open['body']), 'Only dates in the future are ever changed'));

// -----------------------------------------------------------------------------
// R7 — A PREVIEW WRITES NOTHING. The whole design rests on this.
// -----------------------------------------------------------------------------
$before  = repatRows('EVREP Repat shift');
$preview = repatPost($jar['admin'], $shiftSeries, [
    'patternStart'    => '2029-01-07T10:30',
    'patternEnd'      => '2029-01-07T11:30',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
]);
ok('R07 the preview answers 200', $preview['status'] === 200, 'HTTP ' . $preview['status']);
if (getenv('EVREP_DEBUG_R') !== false) {
    echo "---- preview body alerts ----\n";
    if (preg_match_all('/<div class="alert alert-(\w+)[^"]*">(.*?)<\/div>/s', $preview['body'], $mm, PREG_SET_ORDER) > 0) {
        foreach ($mm as $hit) { echo '  [' . $hit[1] . '] ' . mb_substr(trim(text($hit[2])), 0, 300) . "\n"; }
    } else { echo "  (no alert boxes)\n"; }
    echo '  has "What would change": ' . (str_contains($preview['body'], 'What would change') ? 'yes' : 'no') . "\n";
    echo '  has the form at all: ' . (str_contains($preview['body'], 'name="repeatFrequency"') ? 'yes' : 'no') . "\n";
    echo '  body length: ' . strlen($preview['body']) . "\n";
    echo '  selected frequency: ' . (preg_match('/value="(\w+)"\s*selected/', $preview['body'], $sf) === 1 ? $sf[1] : '?') . "\n";
    echo '  patternStart value shown: ' . (preg_match('/id="patternStart"[^>]*value="([^"]*)"/', $preview['body'], $ps) === 1 ? $ps[1] : '?') . "\n";
    echo "---- end ----\n";
}
ok('R08 the preview says four dates would be re-timed',
    preg_match('/Re-timed\s*<span class="badge bg-primary">4<\/span>/', $preview['body']) === 1);
ok('R09 the preview offers a confirm button', str_contains($preview['body'], 'Make these changes'));
ok('R10 THE PREVIEW CHANGED NOTHING IN THE DATABASE', repatRows('EVREP Repat shift') === $before);
ok('R11 the preview carries a fingerprint of itself', $preview['digest'] !== '');

// 🧠 A SECOND PREVIEW MUST STILL WRITE NOTHING, even though it carries the
//    first preview's fingerprint. This is not a contrived case: the hidden
//    fingerprint field sits inside the SAME form as the pattern boxes, so a
//    real browser pressing "Show me what would change" again posts it — and
//    does NOT post `confirm`, because that is the name of the other button.
//
//    Added after planting: dropping the `confirm` requirement altogether was
//    caught by NOTHING in this section, because the only request carrying a
//    fingerprint was one that also asked to confirm. The fingerprint check was
//    quietly doing both jobs, so the gate that is supposed to mean "they
//    pressed the right button" could be removed without a single check
//    noticing.
$beforeSecond = repatRows('EVREP Repat shift');
$second       = repatPost($jar['admin'], $shiftSeries, [
    'patternStart'    => '2029-01-07T10:30',
    'patternEnd'      => '2029-01-07T11:30',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
    'planDigest'      => $preview['digest'],
]);
ok('R11b a second preview carrying the fingerprint WRITES NOTHING, because it did not confirm',
    repatRows('EVREP Repat shift') === $beforeSecond);
ok('R11c and it shows the preview again rather than a success message',
    str_contains($second['body'], 'Make these changes'));

// -----------------------------------------------------------------------------
// R12 — confirming applies it, and only the times move.
// -----------------------------------------------------------------------------
// Taken BEFORE the change, so R18b can tell a rule this page wrote from the
// one the create handler left behind.
$ruleBeforeShift = (int) (ruleOfSeries($shiftSeries)['ruleID'] ?? 0);
$done = repatPost($jar['admin'], $shiftSeries, [
    'patternStart'    => '2029-01-07T10:30',
    'patternEnd'      => '2029-01-07T11:30',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
], true, $preview['digest']);
ok('R12 confirming redirects away from the form', $done['status'] === 302, 'HTTP ' . $done['status']);
// 📡 THE OTHER BRANCH of the sentence about subscribers, and the only change in
//    this test that reaches it: four dates RE-TIMED, none added, every one of
//    them already published — so no drafts remain and the feed really will
//    collapse the series into one entry. S15d covers the opposite case.
//    Without this check the "everything is published" wording was never
//    exercised at all, which planting found: making the page claim drafts
//    remained when none did failed nothing.
$shiftFlash = flashOn(req($jar['admin'], 'GET', '/calendar/manage/series-edit?seriesID=' . $shiftSeries)['body']);
ok('R12b and the message says subscribers now see ONE repeating entry, because nothing is left in draft',
    str_contains($shiftFlash, 'now sees one repeating entry') === true
    && str_contains($shiftFlash, 'still sees each date on its own') === false
    && str_contains($shiftFlash, 'added as drafts') === false,
    $shiftFlash);
$after = repatRows('EVREP Repat shift');
ok('R13 all four dates now start at 10:30',
    repatLiveStarts('EVREP Repat shift') === ['2029-01-07 10:30:00', '2029-01-14 10:30:00', '2029-01-21 10:30:00', '2029-01-28 10:30:00'],
    implode(', ', repatLiveStarts('EVREP Repat shift')));
ok('R14 each date ends at 11:30 too',
    count(array_filter($after, static fn (array $r): bool => str_ends_with((string) $r['endDateTime'], '11:30:00'))) === 4);
// 🧠 The recorded origin MOVES WITH the date. If it did not, every date would
//    read as hand-moved the next time somebody changed the pattern, and the
//    second change would silently do nothing at all.
ok('R15 the recorded origin moved with the dates',
    count(array_filter($after, static fn (array $r): bool => (string) $r['patternStart'] === (string) $r['startDateTime'])) === 4,
    implode(' | ', array_map(static fn (array $r): string => (string) $r['patternStart'], $after)));
// 🧠 AND THE RECORDED FINISH, which is the half a vacuous check missed. The
//    first attempt at this pinned it on a series whose pattern change re-timed
//    NOTHING, so leaving `patternEnd` behind on a move passed every check. It
//    belongs here, on the one change that really does re-time four dates and
//    give them a new finish — 11:30 where they ended at 11:00.
//
//    Getting this wrong is the opposite fault to the one comparing the finish
//    was added to fix: a stale `patternEnd` makes every date this page re-times
//    read as hand-edited next time, so the page would silently stop working
//    after one use.
ok('R15b the recorded FINISH moved with them too',
    count(array_filter($after, static fn (array $r): bool => (string) $r['patternEnd'] === (string) $r['endDateTime'])) === 4,
    implode(' | ', array_map(static fn (array $r): string => (string) $r['patternEnd'] . ' vs ' . (string) $r['endDateTime'], $after)));
// Not vacuous: the finish really did change, so R15b is comparing against a
// value that moved rather than one that happened to stay put.
ok('R15c and that finish is the new one, not the old',
    count(array_filter($after, static fn (array $r): bool => str_ends_with((string) $r['patternEnd'], '11:30:00'))) === 4);
ok('R16 the name and venue were not touched',
    count(array_filter($after, static fn (array $r): bool => (string) $r['locationName'] === 'The selftest hall')) === 4);
ok('R17 nothing was removed', count(array_filter($after, static fn (array $r): bool => (int) $r['isDeleted'] === 1)) === 0);
// The series holds exactly the new pattern's dates, so the feed may still
// publish one repeating entry.
//
// 🧠 THE ROW'S IDENTITY, NOT ITS MERE EXISTENCE. The first version of these two
// checks asked only whether a rule was there and whether it promised four
// dates — and both passed while the page was broken, because the rule written
// when the series was CREATED was still sitting there saying exactly that. A
// check satisfied by a row the change never touched is not a check. So the old
// row's number is taken first, and the new one must be a different row.
$shiftRule = ruleOfSeries($shiftSeries);
ok('R18 the series keeps a rule, because it holds exactly the pattern\'s dates', $shiftRule !== null);
ok('R18b and it is a NEW rule row, written by this change rather than left over',
    $shiftRule !== null && (int) $shiftRule['ruleID'] > $ruleBeforeShift,
    ($shiftRule === null ? 'no rule' : 'ruleID ' . (string) $shiftRule['ruleID']) . ', was ' . $ruleBeforeShift);
ok('R19 and that rule promises four dates, not more',
    $shiftRule !== null && (int) $shiftRule['maxOccurrences'] === 4,
    $shiftRule === null ? 'no rule' : (string) $shiftRule['maxOccurrences']);
ok('R19b and its first date is the re-timed one',
    $shiftRule !== null && (string) $shiftRule['startDate'] === '2029-01-07',
    $shiftRule === null ? 'no rule' : (string) $shiftRule['startDate']);

// -----------------------------------------------------------------------------
// R20 — A DATE WITH ATTENDANCE AGAINST IT IS NEVER REMOVED.
// -----------------------------------------------------------------------------
// The headline refusal. Over thirty tables cascade off an event, so removing a
// date takes its attendance register with it.
createEvent($jar['admin'], 'EVREP Repat attached', '2029-02-04T09:00', '2029-02-04T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
$attachedRows   = repatRows('EVREP Repat attached');
$attachedSeries = repatSeries('EVREP Repat attached');
ok('R20 the series for the attachment check has three dates', count($attachedRows) === 3, (string) count($attachedRows));

$secondDate = (int) $attachedRows[1]['eventID'];
// tblEventAttendance is keyed by the EVENT, not by the organisation, and has
// no status column — the row's existence is the attendance.
q(
    'INSERT INTO tblEventAttendance (eventID, userID, dayDate, markedByID) VALUES (?, ?, ?, ?)',
    'iisi',
    [$secondDate, ADMIN, '2029-02-11', ADMIN]
);
ok('R21 one attendance mark now exists against the middle date',
    (int) one('SELECT COUNT(*) AS n FROM tblEventAttendance WHERE eventID = ?', 'i', [$secondDate])['n'] === 1);

// Cut the run to ONE date: the pattern now wants neither of the other two.
$shrink = repatPost($jar['admin'], $attachedSeries, [
    'patternStart'    => '2029-02-04T09:00',
    'patternEnd'      => '2029-02-04T10:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '1',
]);
ok('R22 the preview says only ONE date would be removed, not two',
    preg_match('/Removed\s*<span class="badge bg-danger">1<\/span>/', $shrink['body']) === 1);
ok('R23 and it names the record that protects the other one',
    str_contains(text($shrink['body']), 'attendance has been marked'));

repatPost($jar['admin'], $attachedSeries, [
    'patternStart'    => '2029-02-04T09:00',
    'patternEnd'      => '2029-02-04T10:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '1',
], true, $shrink['digest']);
$attachedAfter = repatRows('EVREP Repat attached');
ok('R24 THE DATE CARRYING ATTENDANCE IS STILL LIVE',
    count(array_filter($attachedAfter, static fn (array $r): bool => (int) $r['eventID'] === $secondDate && (int) $r['isDeleted'] === 0)) === 1);
ok('R25 its attendance mark survived',
    (int) one('SELECT COUNT(*) AS n FROM tblEventAttendance WHERE eventID = ?', 'i', [$secondDate])['n'] === 1);
ok('R26 the third date was removed', count(array_filter($attachedAfter, static fn (array $r): bool => (int) $r['isDeleted'] === 1)) === 1);
// 🧠 REMOVED MEANS HIDDEN, NOT DELETED. The row is still there, so nothing
//    cascaded and an administrator can put it back.
ok('R27 the removed date is still a row, with a deletion time',
    count($attachedAfter) === 3
    && count(array_filter($attachedAfter, static fn (array $r): bool => (int) $r['isDeleted'] === 1 && $r['deletedAt'] !== null)) === 1);
ok('R28 the rule was withdrawn, because the series no longer matches one pattern',
    ruleOfSeries($attachedSeries) === null);

// -----------------------------------------------------------------------------
// R29 — a date somebody moved by hand is not dragged back onto the pattern.
// -----------------------------------------------------------------------------
createEvent($jar['admin'], 'EVREP Repat byhand', '2029-03-04T09:00', '2029-03-04T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
$byHandRows   = repatRows('EVREP Repat byhand');
$byHandSeries = repatSeries('EVREP Repat byhand');
$movedByHand  = (int) $byHandRows[1]['eventID'];
// Moved to a different time of day, leaving its recorded origin alone — which
// is exactly what the ordinary edit page does.
q('UPDATE tblEvents SET startDateTime = ?, endDateTime = ? WHERE eventID = ?', 'ssi',
    ['2029-03-11 18:00:00', '2029-03-11 19:00:00', $movedByHand]);

$byHand = repatPost($jar['admin'], $byHandSeries, [
    'patternStart'    => '2029-03-04T09:30',
    'patternEnd'      => '2029-03-04T10:30',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
ok('R29 the preview says the hand-moved date is left alone',
    str_contains(text($byHand['body']), 'it was moved by hand'));
ok('R30 only the other two would be re-timed',
    preg_match('/Re-timed\s*<span class="badge bg-primary">2<\/span>/', $byHand['body']) === 1);
repatPost($jar['admin'], $byHandSeries, [
    'patternStart'    => '2029-03-04T09:30',
    'patternEnd'      => '2029-03-04T10:30',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
], true, $byHand['digest']);
$byHandAfter = one('SELECT startDateTime FROM tblEvents WHERE eventID = ?', 'i', [$movedByHand]);
ok('R31 THE HAND-MADE CHANGE SURVIVED THE PATTERN CHANGE',
    (string) $byHandAfter['startDateTime'] === '2029-03-11 18:00:00',
    (string) $byHandAfter['startDateTime']);

// -----------------------------------------------------------------------------
// R32 — extending a run, and the dates it adds are full copies.
// -----------------------------------------------------------------------------
createEvent($jar['admin'], 'EVREP Repat extend', '2029-04-01T14:00', '2029-04-01T15:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$extendSeries = repatSeries('EVREP Repat extend');
$extend       = repatPost($jar['admin'], $extendSeries, [
    'patternStart'    => '2029-04-01T14:00',
    'patternEnd'      => '2029-04-01T15:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
]);
ok('R32 the preview says two dates would be added',
    preg_match('/Added\s*<span class="badge bg-success">2<\/span>/', $extend['body']) === 1);
repatPost($jar['admin'], $extendSeries, [
    'patternStart'    => '2029-04-01T14:00',
    'patternEnd'      => '2029-04-01T15:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
], true, $extend['digest']);
$extendAfter = repatRows('EVREP Repat extend');
ok('R33 the series now holds four dates',
    repatLiveStarts('EVREP Repat extend') === ['2029-04-01 14:00:00', '2029-04-08 14:00:00', '2029-04-15 14:00:00', '2029-04-22 14:00:00'],
    implode(', ', repatLiveStarts('EVREP Repat extend')));
// 🧠 A NEW DATE IS A FULL EVENT, not a stub. The column list for the copy is
//    read from the database rather than typed, so this check is what proves
//    that really happens — a stub would be missing the venue and the category.
$newOnes = array_values(array_filter($extendAfter, static fn (array $r): bool => in_array((string) $r['startDateTime'], ['2029-04-15 14:00:00', '2029-04-22 14:00:00'], true)));
ok('R34 both new dates exist', count($newOnes) === 2, (string) count($newOnes));
ok('R35 each new date carries the venue',
    count(array_filter($newOnes, static fn (array $r): bool => (string) $r['locationName'] === 'The selftest hall')) === 2);
ok('R36 each new date carries the time zone',
    count(array_filter($newOnes, static fn (array $r): bool => (string) $r['timezone'] === ZONE)) === 2);
ok('R37 each new date is in the same series',
    count(array_filter($newOnes, static fn (array $r): bool => (int) $r['seriesID'] === $extendSeries)) === 2);
ok('R38 each new date ends an hour after it starts',
    count(array_filter($newOnes, static fn (array $r): bool => str_ends_with((string) $r['endDateTime'], '15:00:00'))) === 2);
ok('R39 each new date records where the pattern put it',
    count(array_filter($newOnes, static fn (array $r): bool => (string) $r['patternStart'] === (string) $r['startDateTime'])) === 2);
ok('R40 each new date has its own address',
    count(array_unique(array_map(static fn (array $r): string => (string) $r['eventSlug'], $extendAfter))) === count($extendAfter));
ok('R41 and none of them is hidden',
    count(array_filter($newOnes, static fn (array $r): bool => (int) $r['isDeleted'] === 0)) === 2);

// -----------------------------------------------------------------------------
// R42 — the confirmation is checked against the preview it was given.
// -----------------------------------------------------------------------------
// Two administrators on one series is the ordinary case. The person agreed to a
// SPECIFIC list of changes, so if the series moves under them the change must
// be refused rather than applied to a different world.
createEvent($jar['admin'], 'EVREP Repat race', '2029-05-06T08:00', '2029-05-06T09:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
$raceSeries = repatSeries('EVREP Repat race');
$racePrev   = repatPost($jar['admin'], $raceSeries, [
    'patternStart'    => '2029-05-06T08:30',
    'patternEnd'      => '2029-05-06T09:30',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
ok('R42 the preview says three dates would be re-timed',
    preg_match('/Re-timed\s*<span class="badge bg-primary">3<\/span>/', $racePrev['body']) === 1);
// Somebody else hides one of them in the meantime.
$raceRows = repatRows('EVREP Repat race');
q('UPDATE tblEvents SET isDeleted = 1, deletedAt = NOW() WHERE eventID = ?', 'i', [(int) $raceRows[2]['eventID']]);
$raceStale = repatPost($jar['admin'], $raceSeries, [
    'patternStart'    => '2029-05-06T08:30',
    'patternEnd'      => '2029-05-06T09:30',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
], true, $racePrev['digest']);
ok('R43 a confirmation that no longer matches is refused', $raceStale['status'] === 200, 'HTTP ' . $raceStale['status']);
ok('R44 and it says the series changed while they were looking at it',
    str_contains(text($raceStale['body']), 'changed while you were looking at it'));
ok('R45 NOTHING WAS WRITTEN by the refused confirmation',
    repatLiveStarts('EVREP Repat race') === ['2029-05-06 08:00:00', '2029-05-13 08:00:00'],
    implode(', ', repatLiveStarts('EVREP Repat race')));
// 🧠 An EMPTY fingerprint must be refused too, not treated as "no check
//    needed". `hash_equals('', '')` is true, which is exactly how the OAuth
//    `state` hole of 27 October 2026 worked.
$raceEmpty = repatPost($jar['admin'], $raceSeries, [
    'patternStart'    => '2029-05-06T08:30',
    'patternEnd'      => '2029-05-06T09:30',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
], true, '');
ok('R46 a confirmation with an EMPTY fingerprint is refused',
    str_contains(text($raceEmpty['body']), 'changed while you were looking at it'));
ok('R47 and that wrote nothing either',
    repatLiveStarts('EVREP Repat race') === ['2029-05-06 08:00:00', '2029-05-13 08:00:00'],
    implode(', ', repatLiveStarts('EVREP Repat race')));

// -----------------------------------------------------------------------------
// R48 — the refusals that keep the page honest.
// -----------------------------------------------------------------------------
// A pattern anchored in the past can change nothing, because a date that has
// happened is always left alone. Refusing says so; allowing it would show an
// empty preview and look like a fault.
$pastAnchor = repatPost($jar['admin'], $shiftSeries, [
    'patternStart'    => '2020-01-05T10:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
ok('R48 a pattern starting in the past is refused with the reason',
    str_contains(text($pastAnchor['body']), 'has to start in the future'));
ok('R49 and it offers no confirm button', str_contains($pastAnchor['body'], 'Make these changes') === false);

// A frequency the generator cannot use.
$noFreq = repatPost($jar['admin'], $shiftSeries, [
    'patternStart'    => '2029-06-03T10:00',
    'repeatFrequency' => 'whenever',
    'repeatCount'     => '3',
]);
ok('R50 a frequency the portal does not know is refused',
    str_contains(text($noFreq['body']), 'Choose how often'));

// Another organisation's series. There is no foreign key tying a series to an
// organisation, so the database would accept this.
$foreign = repatOpen($jar['admin'], OTHER_SERIES);
ok('R51 another organisation\'s series cannot be opened', $foreign['status'] === 302, 'HTTP ' . $foreign['status']);
ok('R52 and the refusal does not say whether it exists',
    str_contains($foreign['body'], 'Other org series') === false);
$foreignPost = req($jar['admin'], 'POST', '/calendar/manage/series-repattern', [
    'csrf_token'      => $open['token'],
    'seriesID'        => (string) OTHER_SERIES,
    'patternStart'    => '2029-07-01T10:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
    'confirm'         => '1',
]);
ok('R53 posting another organisation\'s series number changes nothing there',
    (int) one('SELECT COUNT(*) AS n FROM tblEvents WHERE seriesID = ?', 'i', [OTHER_SERIES])['n'] === 0
    && $foreignPost['status'] === 302,
    'HTTP ' . $foreignPost['status']);

// A form token that is missing or wrong.
$noToken = req($jar['admin'], 'POST', '/calendar/manage/series-repattern', [
    'seriesID'        => (string) $shiftSeries,
    'patternStart'    => '2029-08-05T10:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
ok('R54 a post with no form token is refused', str_contains(text($noToken['body']), 'had expired'));
ok('R55 and the series was not touched',
    repatLiveStarts('EVREP Repat shift') === ['2029-01-07 10:30:00', '2029-01-14 10:30:00', '2029-01-21 10:30:00', '2029-01-28 10:30:00'],
    implode(', ', repatLiveStarts('EVREP Repat shift')));

// -----------------------------------------------------------------------------
// R56 — a series holding an imported event is not ours to re-pattern.
// -----------------------------------------------------------------------------
// `FeedImporter` is the ONE writer of an imported event (#514). The guard is
// in the page's own SELECT, so it is proved here by making one.
$importFeed = (int) (one('SELECT MAX(feedID) AS m FROM tblExternalFeeds')['m'] ?? 0) + 1;
q(
    "INSERT INTO tblExternalFeeds (feedID, siteID, name, url, isActive) VALUES (?, ?, 'EVREP repat feed', 'https://example.invalid/r.ics', 1)",
    'ii',
    [$importFeed, ORG]
);
q(
    'UPDATE tblEvents SET externalFeedID = ?, externalUid = ? WHERE eventID = ?',
    'isi',
    [$importFeed, 'evrep-repat-imported', (int) $byHandRows[2]['eventID']]
);
$withImported = repatOpen($jar['admin'], $byHandSeries);
ok('R56 a series holding an imported date cannot be re-patterned', $withImported['status'] === 302, 'HTTP ' . $withImported['status']);
q('UPDATE tblEvents SET externalFeedID = NULL, externalUid = NULL WHERE eventID = ?', 'i', [(int) $byHandRows[2]['eventID']]);
q('DELETE FROM tblExternalFeeds WHERE feedID = ?', 'i', [$importFeed]);
ok('R57 and it opens again once that date is no longer imported',
    repatOpen($jar['admin'], $byHandSeries)['status'] === 200);

// -----------------------------------------------------------------------------
// R58 — somebody who is not an administrator cannot reach it at all.
// -----------------------------------------------------------------------------
// #675's lesson: a page with no gate beyond being signed in gave four of six
// account kinds a bare refusal only when they pressed the button. This page
// adds and removes events in bulk, so the gate is worth pinning.
$asMember = repatOpen($jar['member'], $shiftSeries);
ok('R58 an ordinary member is refused the page', $asMember['status'] === 403, 'HTTP ' . $asMember['status']);
$memberPost = req($jar['member'], 'POST', '/calendar/manage/series-repattern', [
    'csrf_token'      => $open['token'],
    'seriesID'        => (string) $shiftSeries,
    'patternStart'    => '2029-09-02T10:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
    'confirm'         => '1',
]);
ok('R59 and a member POSTING straight to it is refused too', $memberPost['status'] === 403, 'HTTP ' . $memberPost['status']);
ok('R60 which wrote nothing',
    repatLiveStarts('EVREP Repat shift') === ['2029-01-07 10:30:00', '2029-01-14 10:30:00', '2029-01-21 10:30:00', '2029-01-28 10:30:00'],
    implode(', ', repatLiveStarts('EVREP Repat shift')));

// -----------------------------------------------------------------------------
// R61 — the series list offers the page at all.
// -----------------------------------------------------------------------------
// A feature nothing links to is a feature nobody has. #675 again.
$seriesList = req($jar['admin'], 'GET', '/calendar/manage/series');
ok('R61 the series list links to the pattern page',
    str_contains($seriesList['body'], 'calendar/manage/series-repattern'));

// =============================================================================
// S. What the independent check of 31 October 2026 found, now guarded (#669)
// =============================================================================
// Six real problems, five of them reachable over HTTP. Every check in this
// section exists because the first version of this page got that thing wrong,
// so each one is a tripwire over a fault that really happened rather than a
// hypothetical.
head('S. What the independent check found, now guarded (#669)');

// -----------------------------------------------------------------------------
// S1 — A DATE CARRYING AN RSVP IS NEVER REMOVED.
// -----------------------------------------------------------------------------
// The headline finding. `SeriesRepattern::ATTACHMENTS` was built from the
// schema's FOREIGN KEY declarations, and `tblEventRSVPs` has NO foreign key —
// its `eventID` is a plain NOT NULL column. So the list that exists to protect
// records silently missed the table holding every member's going/maybe/no
// answer, which is one of the things the owner's decision of 31 October names
// out loud. The page offered to remove such a date with no reason beside it.
//
// 🧠 THIS CHECK DOES A SECOND JOB, and it is the reason the table was chosen.
//    Section R only ever creates a `tblEventAttendance` row, which is the FIRST
//    entry in the constant — so a one-line
//    `if ($table !== 'tblEventAttendance') { continue; }` in the page's
//    attachment loop passed all 202 checks while making thirty-odd kinds of
//    record invisible to it.
//
//    BE EXACT ABOUT THE COVERAGE, because the first version of this comment was
//    not: it claimed `tblEventRSVPs` was the LAST entry and that protecting a
//    date through it was what made such a plant fail. `tblEventRSVPs` is entry
//    33 of 34; the last is `tblZoomMeeting`, and a loop stopping ONE table
//    short still passed every check. Measured by the independent check, which
//    planted exactly that.
//
//    So three entries are exercised now — the FIRST (`tblEventAttendance`, in
//    section R), the 33rd (`tblEventRSVPs`, here) and the LAST
//    (`tblZoomMeeting`, below). WHAT IS STILL NOT COVERED: a loop that skips
//    some table in the MIDDLE. Nothing here can see that, and no honest wording
//    pretends otherwise — `check_series_attachments_complete.py` guards the
//    LIST against the schema, not the page against the list.
createEvent($jar['admin'], 'EVREP Repat rsvp', '2029-06-03T09:00', '2029-06-03T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
$rsvpRows   = repatRows('EVREP Repat rsvp');
$rsvpSeries = repatSeries('EVREP Repat rsvp');
ok('S01 the series for the RSVP check has three dates', count($rsvpRows) === 3, (string) count($rsvpRows));

$rsvpDate = (int) $rsvpRows[1]['eventID'];
q(
    'INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, guestCount, status) '
    . "VALUES (?, ?, ?, 'going', 0, 'confirmed')",
    'iii',
    [$rsvpDate, ADMIN, ORG]
);
ok('S02 one RSVP now exists against the middle date',
    (int) one('SELECT COUNT(*) AS n FROM tblEventRSVPs WHERE eventID = ?', 'i', [$rsvpDate])['n'] === 1);

$rsvpShrink = repatPost($jar['admin'], $rsvpSeries, [
    'patternStart'    => '2029-06-03T09:00',
    'patternEnd'      => '2029-06-03T10:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '1',
]);
ok('S03 the preview offers to remove only ONE date, not two',
    preg_match('/Removed\s*<span class="badge bg-danger">1<\/span>/', $rsvpShrink['body']) === 1);
ok('S04 and it names the RSVPs as what protects the other',
    str_contains(text($rsvpShrink['body']), 'people have said whether they are coming'));

repatPost($jar['admin'], $rsvpSeries, [
    'patternStart'    => '2029-06-03T09:00',
    'patternEnd'      => '2029-06-03T10:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '1',
], true, $rsvpShrink['digest']);
ok('S05 THE DATE CARRYING AN RSVP IS STILL LIVE',
    (int) one('SELECT isDeleted FROM tblEvents WHERE eventID = ?', 'i', [$rsvpDate])['isDeleted'] === 0);
// 🧠 With no foreign key nothing cascades, so the RSVP row does not even go: it
//    would be left pointing at a date nobody can see. That is why 'no rule' is
//    the most dangerous of the three ON DELETE values, not the mildest.
ok('S06 and its RSVP still points at a date that is really there',
    (int) one(
        'SELECT COUNT(*) AS n FROM tblEventRSVPs r JOIN tblEvents e ON e.eventID = r.eventID '
        . 'WHERE r.eventID = ? AND e.isDeleted = 0',
        'i',
        [$rsvpDate]
    )['n'] === 1);

// 🔚 AND THE LAST ENTRY IN THE CONSTANT, `tblZoomMeeting`. A booked Zoom
//    meeting also names its event with no foreign key, so it is the other half
//    of the same fault — and being last, it is what a loop stopping one table
//    short would miss.
$zoomAccount = (int) (one('SELECT MAX(accountID) AS m FROM tblZoomAccount')['m'] ?? 0) + 1;
q(
    "INSERT INTO tblZoomAccount (accountID, siteID, zoomUserId, refreshTokenEnc) VALUES (?, ?, 'evrep-zoom', 'x')",
    'ii',
    [$zoomAccount, ORG]
);
// 🧠 ITS OWN SERIES, deliberately. The first version attached the meeting to the
//    third date of the RSVP series — which the shrink just above had already
//    removed — so the check was asking about a date that was hidden before it
//    began. An ordering coupling between two checks in one section is the
//    quietest way to make both meaningless.
createEvent($jar['admin'], 'EVREP Repat zoom', '2029-06-05T20:00', '2029-06-05T21:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$zoomSeries = repatSeries('EVREP Repat zoom');
$zoomRows   = repatRows('EVREP Repat zoom');
$zoomDate   = (int) $zoomRows[1]['eventID'];
q(
    "INSERT INTO tblZoomMeeting (siteID, eventID, accountID, zoomMeetingId, joinUrl, isRecurring) "
    . "VALUES (?, ?, ?, '900900900', 'https://example.invalid/j/900900900', 0)",
    'iii',
    [ORG, $zoomDate, $zoomAccount]
);
ok('S06b a Zoom meeting now exists against the second date of its own series',
    (int) one('SELECT COUNT(*) AS n FROM tblZoomMeeting WHERE eventID = ?', 'i', [$zoomDate])['n'] === 1);

$zoomShrink = repatPost($jar['admin'], $zoomSeries, [
    'patternStart'    => '2029-06-05T20:00',
    'patternEnd'      => '2029-06-05T21:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '1',
]);
ok('S06c the preview names the Zoom meeting as what protects that date',
    str_contains(text($zoomShrink['body']), 'a Zoom meeting is booked for it'));
ok('S06d and so it offers to remove nothing at all',
    preg_match('/Removed\s*<span class="badge bg-danger">/', $zoomShrink['body']) !== 1);
repatPost($jar['admin'], $zoomSeries, [
    'patternStart'    => '2029-06-05T20:00',
    'patternEnd'      => '2029-06-05T21:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '1',
], true, $zoomShrink['digest']);
ok('S06e THE DATE CARRYING A ZOOM MEETING IS STILL LIVE',
    (int) one('SELECT isDeleted FROM tblEvents WHERE eventID = ?', 'i', [$zoomDate])['isDeleted'] === 0);

// -----------------------------------------------------------------------------
// S7 — SHORTEN THEN EXTEND AGAIN. The address of a removed date is kept.
// -----------------------------------------------------------------------------
// `uq_event_site_slug (siteID, eventSlug)` applies to soft-deleted rows too, and
// a date this page hides keeps its address for ever. The first version built an
// address from the series' stem, the date and the date's POSITION in the add
// list, so re-adding a day that had been removed collided: the whole change was
// rolled back and the administrator was told "Something went wrong … please try
// again", which retrying could never cure. It was intermittent, because the
// position in the list changes from one run to the next.
createEvent($jar['admin'], 'EVREP Repat slug', '2029-07-01T11:00', '2029-07-01T12:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$slugSeries = repatSeries('EVREP Repat slug');
$slugFields = [
    'patternStart'    => '2029-07-01T11:00',
    'patternEnd'      => '2029-07-01T12:00',
    'repeatFrequency' => 'weekly',
];

/** Apply one pattern to the slug series: preview, then confirm it. */
$slugApply = static function (string $count) use ($jar, $slugSeries, $slugFields): array {
    $prev = repatPost($jar['admin'], $slugSeries, $slugFields + ['repeatCount' => $count]);
    return repatPost($jar['admin'], $slugSeries, $slugFields + ['repeatCount' => $count], true, $prev['digest']);
};

// 🧠 THE ORDER OF THESE THREE CHANGES IS THE WHOLE POINT, and getting it wrong
//    is why the first version of this section caught NOTHING.
//
//    The collision is between two addresses THIS PAGE builds. A date created by
//    the save handler gets `<slug>-2`, `<slug>-3` … so removing one of those and
//    re-adding the day cannot clash — the page's own address for that day has a
//    different shape. Planting the old position-based address back passed all
//    226 checks against a series whose removed dates came from the save handler.
//
//    So the series must be EXTENDED first, which is what gives two days an
//    address built by this page; then shortened, which hides them while they keep
//    those addresses for ever; then extended again over the same two days.
$slugApply('4');
ok('S07 extending to four dates worked',
    repatLiveStarts('EVREP Repat slug') === ['2029-07-01 11:00:00', '2029-07-08 11:00:00', '2029-07-15 11:00:00', '2029-07-22 11:00:00'],
    implode(', ', repatLiveStarts('EVREP Repat slug')));
$pageBuilt = [];
foreach (repatRows('EVREP Repat slug') as $row) {
    if (in_array((string) $row['startDateTime'], ['2029-07-15 11:00:00', '2029-07-22 11:00:00'], true) === true) {
        $pageBuilt[] = (string) $row['eventSlug'];
    }
}
ok('S08 the two added dates have addresses this page built', count($pageBuilt) === 2, implode(', ', $pageBuilt));

$slugApply('2');
ok('S09 shortening back to two dates worked',
    repatLiveStarts('EVREP Repat slug') === ['2029-07-01 11:00:00', '2029-07-08 11:00:00'],
    implode(', ', repatLiveStarts('EVREP Repat slug')));
ok('S10 and the two hidden dates kept those addresses',
    count(array_filter(
        repatRows('EVREP Repat slug'),
        static fn (array $r): bool => (int) $r['isDeleted'] === 1 && in_array((string) $r['eventSlug'], $pageBuilt, true)
    )) === 2);

$regrown = $slugApply('4');
ok('S11 RE-EXTENDING OVER THE SAME TWO DAYS SUCCEEDS', $regrown['status'] === 302, 'HTTP ' . $regrown['status']);
ok('S12 and the series really holds four live dates again',
    repatLiveStarts('EVREP Repat slug') === ['2029-07-01 11:00:00', '2029-07-08 11:00:00', '2029-07-15 11:00:00', '2029-07-22 11:00:00'],
    implode(', ', repatLiveStarts('EVREP Repat slug')));
ok('S12b every address in that series is unique, hidden rows included',
    count(array_unique(array_map(static fn (array $r): string => (string) $r['eventSlug'], repatRows('EVREP Repat slug'))))
    === count(repatRows('EVREP Repat slug')));
// 🧠 Not vacuous: the failure mode being guarded is a rolled-back change, so
//    prove the page did not merely refuse quietly. Six rows means four live and
//    the two originally hidden — the re-added days are NEW rows, because the
//    hidden ones keep their own addresses.
ok('S12c the two days really came back as new rows rather than being un-hidden',
    count(repatRows('EVREP Repat slug')) === 6, (string) count(repatRows('EVREP Repat slug')));

// -----------------------------------------------------------------------------
// S13 — A NEW DATE IS NEVER BORN CANCELLED, and carries nobody's details.
// -----------------------------------------------------------------------------
// The copy took EVERY column of the template row but four. So an administrator
// who had cancelled one week by hand — the ordinary reason to cancel one week —
// then extended the run and silently got new dates that were ALREADY CANCELLED,
// carrying that date's cancellation reason and the name of whoever cancelled it.
//
// And the privacy half: `submitterName`/`submitterEmail` are free text for a
// member of the public who submitted an event, and `GdprEraser::catalogue()`
// clears neither column on `tblEvents` — so the copies could not have been
// erased on request.
createEvent($jar['admin'], 'EVREP Repat history', '2029-08-05T19:00', '2029-08-05T20:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$histSeries = repatSeries('EVREP Repat history');
$histRows   = repatRows('EVREP Repat history');
q(
    "UPDATE tblEvents SET status = 'cancelled', cancelReason = ?, statusChangedByID = ?, statusChangedAt = NOW(), "
    . "submissionStatus = 'approved', submitterName = ?, submitterEmail = ?, submittedAt = NOW(), moderatedByID = ?, "
    . 'moderationNote = ? WHERE eventID = ?',
    'sissisi',
    [
        'Boiler broken this week only',
        ADMIN,
        'Jo Public',
        'jo.public@selftest.invalid',
        ADMIN,
        'let through on the nod',
        (int) $histRows[0]['eventID'],
    ]
);
ok('S13 the first date is now cancelled by hand, with a submitter against it',
    (string) one('SELECT status FROM tblEvents WHERE eventID = ?', 'i', [(int) $histRows[0]['eventID']])['status'] === 'cancelled');

// 🌐 #493 P1: every existing date of the series is ON the public website,
//    with a window, so a copy that took the template's values would show it.
q("UPDATE tblEvents SET `showOnWebsite` = 1, `websiteFrom` = '2029-01-01', `websiteUntil` = '2029-12-31' WHERE seriesID = ?",
    'i', [$histSeries]);

$histFields = [
    'patternStart'    => '2029-08-05T19:00',
    'patternEnd'      => '2029-08-05T20:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
];
$histPrev    = repatPost($jar['admin'], $histSeries, $histFields);
$histApplied = repatPost($jar['admin'], $histSeries, $histFields, true, $histPrev['digest']);
// 🪤 The path is written out here rather than taken from the answer's own
//    `Location`, because `req()` prefixes the organisation's part itself and
//    the handler's `Location` already carries it: passing it straight through
//    doubles that part and lands on a page with no message at all (#536 — "never
//    join two built addresses"). The first version of this check did exactly
//    that and reported "(no message shown)", which reads identically to the
//    real fault it was looking for.
$histLanding = req($jar['admin'], 'GET', '/calendar/manage/series-edit?seriesID=' . $histSeries);
$histFlash   = flashOn($histLanding['body']);

$born = q(
    'SELECT eventID, status, cancelReason, statusChangedByID, statusChangedAt, submissionStatus, submitterName, '
    . 'submitterEmail, submittedAt, moderatedByID, moderationNote, createdByID, `showOnWebsite`, `websiteFrom`, `websiteUntil` '
    . 'FROM tblEvents WHERE siteID = ? AND eventName = ? AND startDateTime IN (?, ?)',
    'isss',
    [ORG, 'EVREP Repat history', '2029-08-19 19:00:00', '2029-08-26 19:00:00']
);
ok('S14 the two new dates were created', count($born) === 2, (string) count($born));
// 🚦 BOTH NEW DATES ARE DRAFTS (#692, the owner's decision of 31 October 2026).
//    This check used to require 'published', which was the old behaviour; it
//    is written as `=== 'draft'` rather than `!== 'cancelled'` on purpose,
//    because "not cancelled" would pass on either answer and this is the whole
//    of what #692 changed. The two states #692 did NOT want are still
//    impossible, since a date that is a draft is neither cancelled nor
//    postponed.
ok('S15 BOTH NEW DATES ARE DRAFTS, so members cannot see them yet',
    count(array_filter($born, static fn (array $r): bool => (string) $r['status'] === 'draft')) === 2,
    implode(', ', array_map(static fn (array $r): string => (string) $r['status'], $born)));
// 🗣️ And SAYING so is half the feature. A page that quietly creates drafts
//    sends somebody looking for the dates on the public calendar, where they
//    are not — which is exactly the shape of a message that is "not untrue"
//    and still misleads. Both the preview and the message afterwards are
//    pinned, because they are written in two different places and either could
//    be dropped on its own.
ok('S15b the PREVIEW says the new dates arrive as drafts, before anything is written',
    str_contains($histPrev['body'], 'arrives as a draft') === true,
    'the preview did not say it');
// 📡 THE SENTENCE ABOUT SUBSCRIBERS, which was WRONG until the independent
//    check caught it on 31 October 2026. The rule row IS written — the pattern
//    does describe this series' dates — so the old message said "Anybody
//    subscribing to this calendar now sees one repeating entry". But
//    `calendar/export.php` reads a series' run with `AND e.status =
//    'published'` and only collapses it when the PUBLISHED dates match the
//    rule. While any date is a draft, and since #692 every added date is one,
//    a subscriber sees each date on its own. So the message claimed the
//    opposite of what the feed does, for exactly the change just made.
//
//    Pinned on the WORDS a subscriber's situation is described by, and the
//    count, because the number of drafts is what decides the wording.
ok('S15d and it says subscribers still see each date on its own until the drafts are published',
    str_contains($histFlash, 'still sees each date on its own') === true
    && str_contains($histFlash, 'will see one repeating entry once') === true
    && str_contains($histFlash, 'draft dates are published') === true
    && str_contains($histFlash, 'now sees one repeating entry') === false,
    $histFlash);
ok('S15c and the message AFTERWARDS says they were added as drafts',
    str_contains($histFlash, 'added as drafts') === true,
    'HTTP ' . $histLanding['status'] . ' -> ' . $histLanding['location'] . '; message: ' . $histFlash
    . '; the page ' . (str_contains($histLanding['body'], 'Bulk Edit') === true ? 'IS' : 'is NOT') . ' the bulk edit page');
ok('S16 neither carries the other date\'s cancellation trail',
    count(array_filter($born, static fn (array $r): bool => $r['cancelReason'] === null
        && $r['statusChangedByID'] === null && $r['statusChangedAt'] === null)) === 2);
// 🧠 The privacy half, checked as its own thing: a name and an e-mail address
//    belonging to a member of the public must not be copied onto rows they had
//    nothing to do with, because nothing erases those two columns.
ok('S17 NEITHER CARRIES THE SUBMITTER\'S NAME OR E-MAIL',
    count(array_filter($born, static fn (array $r): bool => $r['submitterName'] === null
        && $r['submitterEmail'] === null && $r['submissionStatus'] === null
        && $r['submittedAt'] === null && $r['moderatedByID'] === null
        && $r['moderationNote'] === null)) === 2);
ok('S18 and each one is recorded as created by whoever made the change',
    count(array_filter($born, static fn (array $r): bool => (int) $r['createdByID'] === ADMIN)) === 2);
// 🌐 #493 P1 (D-057; the D-033 precedent): a date the pattern ADDS is one
//    nobody has looked at, so it is OFF the public website with no window,
//    although every date it was copied from is ON with one. Planted, taking
//    the template's value instead turns this red.
ok('S18w NEITHER NEW DATE IS ON THE PUBLIC WEBSITE, and neither carries the template\'s website dates',
    count(array_filter($born, static fn (array $r): bool => (int) $r['showOnWebsite'] === 0
        && $r['websiteFrom'] === null && $r['websiteUntil'] === null)) === 2
    && (int) (one('SELECT COUNT(*) AS n FROM tblEvents WHERE seriesID = ? AND `showOnWebsite` = 1', 'i', [$histSeries])['n'] ?? 0) > 0,
    json_encode(array_map(static fn (array $r): array => [$r['showOnWebsite'], $r['websiteFrom'], $r['websiteUntil']], $born)));

// -----------------------------------------------------------------------------
// S18b — A NEW DATE DOES NOT INHERIT ANOTHER DATE'S REGISTRATION WINDOW.
// -----------------------------------------------------------------------------
// The second round of the independent check measured this: the copy took every
// column but a listed few, so `registrationClosesAt` came across from the
// template — a moment just before the TEMPLATE's own date. A date added four
// weeks later therefore arrived with registration ALREADY CLOSED, and both
// `calendar/event-register.php` and `event-register-save.php` treat that column
// as a hard gate, so a member opening the new date was simply told registration
// had closed, with nothing to explain it.
//
// 🧠 `registrationEnabled` is in the same list for a reason worth keeping: the
//    CREATE handler writes none of these columns, so every date it made has
//    them empty. A copy carrying them would differ from its own siblings.
createEvent($jar['admin'], 'EVREP Repat regwin', '2030-01-06T19:00', '2030-01-06T20:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$regSeries = repatSeries('EVREP Repat regwin');
$regRows   = repatRows('EVREP Repat regwin');
q(
    'UPDATE tblEvents SET registrationEnabled = 1, registrationOpensAt = ?, registrationClosesAt = ?, '
    . 'capacityCount = 12, registrationRetentionDays = 7 WHERE eventID = ?',
    'ssi',
    ['2029-12-01 00:00:00', '2030-01-05 23:59:59', (int) $regRows[0]['eventID']]
);
ok('S18b the first date now has a registration window that closes before the others',
    (string) one('SELECT registrationClosesAt FROM tblEvents WHERE eventID = ?', 'i', [(int) $regRows[0]['eventID']])['registrationClosesAt']
    === '2030-01-05 23:59:59');

$regFields = [
    'patternStart'    => '2030-01-06T19:00',
    'patternEnd'      => '2030-01-06T20:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
];
$regPrev = repatPost($jar['admin'], $regSeries, $regFields);
repatPost($jar['admin'], $regSeries, $regFields, true, $regPrev['digest']);

$regNew = q(
    'SELECT startDateTime, registrationEnabled, registrationOpensAt, registrationClosesAt, capacityCount, '
    . 'registrationRetentionDays, patternStart, patternEnd, endDateTime, timezone '
    . 'FROM tblEvents WHERE siteID = ? AND eventName = ? AND startDateTime IN (?, ?)',
    'isss',
    [ORG, 'EVREP Repat regwin', '2030-01-20 19:00:00', '2030-01-27 19:00:00']
);
ok('S18c the two new dates were created', count($regNew) === 2, (string) count($regNew));
ok('S18d NEITHER INHERITED A REGISTRATION WINDOW THAT HAD ALREADY CLOSED',
    count(array_filter($regNew, static fn (array $r): bool => $r['registrationClosesAt'] === null
        && $r['registrationOpensAt'] === null)) === 2,
    implode(' | ', array_map(static fn (array $r): string => (string) $r['registrationClosesAt'], $regNew)));
ok('S18e nor the rest of that one date\'s registration settings',
    count(array_filter($regNew, static fn (array $r): bool => (int) $r['registrationEnabled'] === 0
        && $r['capacityCount'] === null && $r['registrationRetentionDays'] === null)) === 2);
// 🧠 A check of its own for the recorded FINISH on an ADDED date. It had none:
//    planting that branch away only failed the slug checks, by accident of this
//    section's order, so re-ordering section S would have lost the coverage.
ok('S18f each new date records BOTH origins, matching its own readings',
    count(array_filter($regNew, static fn (array $r): bool =>
        (string) $r['patternStart'] === (string) $r['startDateTime']
        && (string) $r['patternEnd'] === (string) $r['endDateTime'])) === 2,
    implode(' | ', array_map(static fn (array $r): string => (string) $r['patternEnd'] . ' vs ' . (string) $r['endDateTime'], $regNew)));
ok('S18g and the zone the readings were made in',
    count(array_filter($regNew, static fn (array $r): bool => (string) $r['timezone'] === ZONE)) === 2);

// -----------------------------------------------------------------------------
// S18h — the added date's zone must be the one its READING was made in.
// -----------------------------------------------------------------------------
// 🧠 S18g ON ITS OWN PROVES NOTHING ABOUT THIS, and planting showed it: every
//    event in this made-up world carries the same zone, so taking the zone from
//    the template row gives the identical answer and the check passes either
//    way. It was the fifth vacuous check found in this package.
//
//    The readings in the plan are all generated in the zone of the series'
//    EARLIEST date. The copy used to take `timezone` from the TEMPLATE row,
//    which is a different row whenever the earliest date is one the page may
//    not touch. So this builds exactly that: the earliest date is pinned by an
//    RSVP (making it untouchable, so the template moves to the second date),
//    and the second date is given a different zone by hand. The two sources
//    then disagree, and only one answer keeps the stored reading meaning what
//    it says.
createEvent($jar['admin'], 'EVREP Repat zones', '2030-03-03T10:00', '2030-03-03T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
$zoneSeries = repatSeries('EVREP Repat zones');
$zoneRows   = repatRows('EVREP Repat zones');
// The earliest date is pinned, so it cannot be the template...
q(
    'INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, guestCount, status) '
    . "VALUES (?, ?, ?, 'going', 0, 'confirmed')",
    'iii',
    [(int) $zoneRows[0]['eventID'], ADMIN, ORG]
);
// ...and the date that WILL be the template is given a different zone.
q('UPDATE tblEvents SET timezone = ? WHERE eventID = ?', 'si', ['Europe/Paris', (int) $zoneRows[1]['eventID']]);
ok('S18h the template date and the earliest date now hold different zones',
    (string) one('SELECT timezone FROM tblEvents WHERE eventID = ?', 'i', [(int) $zoneRows[1]['eventID']])['timezone'] === 'Europe/Paris'
    && (string) one('SELECT timezone FROM tblEvents WHERE eventID = ?', 'i', [(int) $zoneRows[0]['eventID']])['timezone'] === ZONE);

$zoneFields = [
    'patternStart'    => '2030-03-03T10:00',
    'patternEnd'      => '2030-03-03T11:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '5',
];
$zonePrev = repatPost($jar['admin'], $zoneSeries, $zoneFields);
repatPost($jar['admin'], $zoneSeries, $zoneFields, true, $zonePrev['digest']);
$zoneNew = q(
    'SELECT startDateTime, timezone FROM tblEvents WHERE siteID = ? AND eventName = ? AND startDateTime IN (?, ?)',
    'isss',
    [ORG, 'EVREP Repat zones', '2030-03-24 10:00:00', '2030-03-31 10:00:00']
);
ok('S18i the two dates were added', count($zoneNew) === 2, (string) count($zoneNew));
ok('S18j EACH ONE CARRIES THE ZONE ITS READING WAS MADE IN, not the template\'s',
    count(array_filter($zoneNew, static fn (array $r): bool => (string) $r['timezone'] === ZONE)) === 2,
    implode(' | ', array_map(static fn (array $r): string => (string) $r['timezone'], $zoneNew)));

// -----------------------------------------------------------------------------
// S19 — A HAND-CHANGED FINISH TIME IS A HAND EDIT.
// -----------------------------------------------------------------------------
// The class compared the recorded origin with the START only, while its own
// docblock promised it caught "a changed TIME". So somebody who lengthened one
// future service from an hour to three had it silently shortened again by the
// next pattern change, and the preview called that date "re-timed" rather than
// leaving it alone — the owner's rule 2 broken.
createEvent($jar['admin'], 'EVREP Repat endedit', '2029-09-02T15:00', '2029-09-02T16:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
$endSeries = repatSeries('EVREP Repat endedit');
$endRows   = repatRows('EVREP Repat endedit');
$lengthened = (int) $endRows[1]['eventID'];
// Lengthened by hand, exactly as the ordinary edit page would: the finish moves
// and the recorded origin is left alone.
q('UPDATE tblEvents SET endDateTime = ? WHERE eventID = ?', 'si', ['2029-09-09 18:00:00', $lengthened]);

$endFields = [
    'patternStart'    => '2029-09-02T15:00',
    'patternEnd'      => '2029-09-02T16:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
];
$endPrev = repatPost($jar['admin'], $endSeries, $endFields);
ok('S19 the preview reports the lengthened date as left alone, not re-timed',
    str_contains(text($endPrev['body']), 'it was moved by hand'));
repatPost($jar['admin'], $endSeries, $endFields, true, $endPrev['digest']);
ok('S20 THE HAND-CHANGED FINISH TIME SURVIVED',
    (string) one('SELECT endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$lengthened])['endDateTime']
    === '2029-09-09 18:00:00',
    (string) one('SELECT endDateTime FROM tblEvents WHERE eventID = ?', 'i', [$lengthened])['endDateTime']);
// 🧠 And the opposite direction, so this guard cannot over-reach: the OTHER
//    dates of the same series — the ones nobody touched — must still be
//    treated as the pattern's own.
//
//    WHAT THIS CHECK DOES NOT PROVE, stated because its first version claimed
//    it did: this pattern re-times nothing (the readings are unchanged), so it
//    says nothing about whether a move keeps the recorded finish in step. That
//    is R15b, on a change that really does re-time.
$untouched = one(
    'SELECT startDateTime, endDateTime, patternStart, patternEnd FROM tblEvents WHERE eventID = ?',
    'i',
    [(int) $endRows[2]['eventID']]
);
ok('S21 the dates nobody edited still read as the pattern\'s own',
    (string) $untouched['patternStart'] === (string) $untouched['startDateTime']
    && (string) $untouched['patternEnd'] === (string) $untouched['endDateTime'],
    (string) $untouched['patternStart'] . ' / ' . (string) $untouched['patternEnd']);

// -----------------------------------------------------------------------------
// S21b — two dates on one day: the sentence a person really sees.
// -----------------------------------------------------------------------------
// 🧠 `plan()` used to build this sentence itself while `reasonWords()` held a
//    second copy that nothing reached, and the class self-test exercised only
//    the unreachable one — "a test holding its own copy of the thing it tests",
//    in miniature. The wording is shared now, and this is the check that reads
//    what the PAGE prints.
//
//    It also pins the other half of the fix: a same-day pair gets no "change
//    this one by hand" link, because opening the editor is not the answer when
//    the question is which of two dates the pattern means.
createEvent($jar['admin'], 'EVREP Repat sameday', '2030-05-05T09:00', '2030-05-05T10:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
$sameSeries = repatSeries('EVREP Repat sameday');
$sameRows   = repatRows('EVREP Repat sameday');
// Moved by hand onto the FIRST date's day, so that day now holds two.
q('UPDATE tblEvents SET startDateTime = ?, endDateTime = ? WHERE eventID = ?', 'ssi',
    ['2030-05-05 19:00:00', '2030-05-05 20:00:00', (int) $sameRows[1]['eventID']]);
$samePrev = repatPost($jar['admin'], $sameSeries, [
    'patternStart'    => '2030-05-05T09:30',
    'patternEnd'      => '2030-05-05T10:30',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
ok('S21b the page says more than one date falls on that day, naming the day',
    str_contains(text($samePrev['body']), 'more than one date of this series falls on 2030-05-05'));
ok('S21c and it does not call that "moved by hand"',
    substr_count(text($samePrev['body']), 'it was moved by hand') === 0);
ok('S21d neither of the two is re-timed or removed',
    preg_match('/Re-timed\s*<span class="badge bg-primary">1<\/span>/', $samePrev['body']) === 1
    && preg_match('/Removed\s*<span class="badge bg-danger">/', $samePrev['body']) !== 1);
// Not vacuous: prove the kept list really holds those two, so S21c cannot pass
// because nothing was listed at all.
ok('S21e and both are listed as left alone',
    preg_match('/Left exactly as they are\s*<span class="badge bg-secondary">2<\/span>/', $samePrev['body']) === 1);

// -----------------------------------------------------------------------------
// S22 — the remedy the page offers actually works.
// -----------------------------------------------------------------------------
// The only thing the page offers for a date it refuses to touch is a link, and
// that link was built as `?eventID=`. `calendar/manage/index.php` reads
// `$_GET['edit']` and reads `eventID` NOWHERE, so it opened the unfiltered list
// with no event selected. This is the repository's own "name things by the
// right identifier" trap, and nothing in 202 checks looked at the href.
$keepPage = repatPost($jar['admin'], $rsvpSeries, [
    'patternStart'    => '2029-06-03T09:00',
    'patternEnd'      => '2029-06-03T10:00',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '1',
]);
ok('S22 the by-hand link addresses the event the way that page reads it',
    preg_match('/calendar\/manage\?edit=' . $rsvpDate . '"/', $keepPage['body']) === 1
    || str_contains($keepPage['body'], 'calendar/manage?edit=' . $rsvpDate));
ok('S23 and it does NOT use the name that page ignores',
    str_contains($keepPage['body'], '?eventID=') === false);
// 🧠 Not vacuous: prove the link is really drawn for this date, so S23 cannot
//    pass because the whole block is missing. The #645 shape in a check.
ok('S24 the link is really there to be checked',
    str_contains($keepPage['body'], 'change this one by hand'));

// =============================================================================
// T. Bringing a hidden date back (#691)
// =============================================================================
// Until #691 a date hidden by a pattern change could not be brought back by
// anything in the portal. `Portal\Core\HiddenSeriesDates` now lists a series'
// hidden dates on its bulk edit page and brings one back AS A DRAFT. Every check
// here hides dates through the REAL pattern page, presses the REAL button, and
// reads the rows back out of the database, because the page's own message is
// not evidence of what happened (D-065).
head('T. Bringing a hidden date back (#691)');

// 📝 The exact words each kept-status answer must show (D-146, D-147). Typed
//    here on purpose, NOT read from `HiddenSeriesDates::words()`: a check that
//    asks the class what to expect cannot notice the class saying the wrong
//    thing, which is how a swapped message passed round 3.
const RESTORE_WORDS_CANCELLED = 'That date is back in the series, still cancelled, because it had been cancelled before it was '
    . 'hidden. Members see it as cancelled, with its reason if it was given one.';
const RESTORE_WORDS_SAME_DAY_CANCELLED = 'That date is back in the series, still cancelled, as you confirmed, because it had been '
    . 'cancelled before it was hidden. Members see it as cancelled, with its reason if it was given one. '
    . 'This series now has two dates on that day — hide or move one of them if that is not what you want.';
const RESTORE_WORDS_POSTPONED = 'That date is back in the series, still postponed, because it had been postponed before it was '
    . 'hidden. Members see it as postponed, with its reason if it was given one.';
const RESTORE_WORDS_SAME_DAY_POSTPONED = 'That date is back in the series, still postponed, as you confirmed, because it had been '
    . 'postponed before it was hidden. Members see it as postponed, with its reason if it was given one. '
    . 'This series now has two dates on that day — hide or move one of them if that is not what you want.';

/** One row of tblEvents, read fresh. */
function restRow(int $eventId): ?array
{
    return one(
        'SELECT eventID, siteID, seriesID, eventSlug, startDateTime, isDeleted, deletedAt, status, updatedByID '
        . 'FROM tblEvents WHERE eventID = ?',
        'i',
        [$eventId]
    );
}

/** The bulk edit page of one series, as the person in `$jar` sees it. */
function restPage(string $jar, int $seriesId): array
{
    return req($jar, 'GET', '/calendar/manage/series-edit?seriesID=' . $seriesId);
}

/**
 * Press "Bring back as a draft" for one date, the way the page's own form does,
 * with a fresh form token unless `$token` says otherwise. Hands back the answer
 * AND the message the page then shows, read from the page it redirects to.
 */
function restPress(string $jar, int $seriesId, int $eventId, ?string $token = null, bool $confirmSameDay = false): array
{
    // The token from the Manage Events page: one token serves the whole
    // session, and this page answers even when the series named has gone, so
    // a refusal below is never just a missing token.
    $fresh = managePage($jar)['token'];
    $post  = ['seriesID' => (string) $seriesId, 'action' => 'restore', 'restoreEventID' => (string) $eventId];
    if ($confirmSameDay === true) {
        // The field the "Bring it back anyway" form carries (D-144).
        $post['confirmSameDay'] = '1';
    }
    if ($token !== '') {
        $post['csrf_token'] = $token ?? $fresh;
    }
    $sent  = req($jar, 'POST', '/calendar/manage/series-edit', $post);
    $after = req($jar, 'GET', $sent['location'] === '' ? '/calendar/manage/series' : (string) preg_replace('#^.*?' . preg_quote(ADDR, '#') . '#', '', $sent['location']));

    return ['status' => $sent['status'], 'location' => $sent['location'], 'message' => flashOn($after['body']), 'body' => $after['body']];
}

/**
 * Every `SeriesDateRestored` description for one date, WHOLE. A check that
 * only looked for the numbers passed with the event's web address appended
 * (found by the independent check), so the description is compared exactly.
 *
 * @return list<string>
 */
function restLogLines(int $eventId): array
{
    return array_map(
        static fn (array $r): string => (string) $r['activityDescription'],
        q(
            "SELECT activityDescription FROM tblActivityLogs WHERE siteID = ? AND activityType = 'SeriesDateRestored' "
            . 'AND activityDescription LIKE ? ORDER BY logID',
            'is',
            [ORG, 'Event #' . $eventId . ' %']
        )
    );
}

/**
 * Start one POST WITHOUT waiting for it, so this run can hold a lock while the
 * real page waits on it. `restAsyncFinish()` collects it.
 *
 * @param array<string,string> $post
 *
 * @return array{multi:CurlMultiHandle, handle:CurlHandle, running:int}
 */
function restAsyncStart(string $jar, string $path, array $post): array
{
    global $port;
    $handle = curl_init('http://127.0.0.1:' . $port . ADDR . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 120, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:'],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
    ]);
    $multi = curl_multi_init();
    curl_multi_add_handle($multi, $handle);
    $running = 1;
    $until   = microtime(true) + 2.0;
    while (microtime(true) < $until && $running > 0) {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);
    }

    return ['multi' => $multi, 'handle' => $handle, 'running' => $running];
}

/** Let a request started by `restAsyncStart()` finish; hands back its HTTP status. */
function restAsyncFinish(array $async): int
{
    $running = $async['running'];
    $until   = microtime(true) + 60;
    while ($running > 0 && microtime(true) < $until) {
        curl_multi_exec($async['multi'], $running);
        curl_multi_select($async['multi'], 0.2);
    }
    $status = (int) curl_getinfo($async['handle'], CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($async['multi'], $async['handle']);
    curl_multi_close($async['multi']);

    return $status;
}

/**
 * The form token a signed-in person's page carries in its `<meta>` tag, for a
 * person who cannot open Manage Events. A REAL token, so a refusal of their
 * post is the gate's doing and not a missing token's.
 */
function restMetaToken(string $jar): string
{
    $page = req($jar, 'GET', '/calendar')['body'];

    return preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})"/', $page, $m) === 1 ? $m[1] : '';
}

/** Hidden rows of a named series, earliest first. */
function restHidden(string $name): array
{
    return array_values(array_filter(repatRows($name), static fn (array $r): bool => (int) $r['isDeleted'] === 1));
}

$restFields = [
    'patternStart'    => '2029-10-07T10:00',
    'patternEnd'      => '2029-10-07T11:00',
    'repeatFrequency' => 'weekly',
];
/** Apply a count to the T series through the real pattern page: preview, then confirm. */
$restApply = static function (int $seriesId, array $fields, string $count) use ($jar): array {
    $prev = repatPost($jar['admin'], $seriesId, $fields + ['repeatCount' => $count]);

    return repatPost($jar['admin'], $seriesId, $fields + ['repeatCount' => $count], true, $prev['digest']);
};

// -----------------------------------------------------------------------------
// T1 — hide two dates by a real pattern change.
// -----------------------------------------------------------------------------
createEvent($jar['admin'], 'EVREP Restore', '2029-10-07T10:00', '2029-10-07T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '4',
]);
$restSeries = repatSeries('EVREP Restore');
ok('T01 the series for the restore checks has four live dates',
    repatLiveStarts('EVREP Restore') === ['2029-10-07 10:00:00', '2029-10-14 10:00:00', '2029-10-21 10:00:00', '2029-10-28 10:00:00'],
    implode(', ', repatLiveStarts('EVREP Restore')));
$restApply($restSeries, $restFields, '2');
$restHiddenRows = restHidden('EVREP Restore');
ok('T02 shortening it to two dates HID the last two (read back from the database)',
    count($restHiddenRows) === 2
    && (string) $restHiddenRows[0]['startDateTime'] === '2029-10-21 10:00:00'
    && (string) $restHiddenRows[1]['startDateTime'] === '2029-10-28 10:00:00',
    json_encode($restHiddenRows));
$restThird  = (int) ($restHiddenRows[0]['eventID'] ?? 0);
$restFourth = (int) ($restHiddenRows[1]['eventID'] ?? 0);
$restThirdSlug = (string) ($restHiddenRows[0]['eventSlug'] ?? '');

// -----------------------------------------------------------------------------
// T3 — the bulk edit page lists them, with the button.
// -----------------------------------------------------------------------------
$restOpen = restPage($jar['admin'], $restSeries);
ok('T03 the bulk edit page opens for an administrator', $restOpen['status'] === 200, 'HTTP ' . $restOpen['status']);
ok('T04 it lists BOTH hidden dates under "Hidden dates"',
    str_contains($restOpen['body'], 'Hidden dates')
    && str_contains($restOpen['body'], 'data-hidden-event="' . $restThird . '"')
    && str_contains($restOpen['body'], 'data-hidden-event="' . $restFourth . '"'));
ok('T05 each has a button that posts that date to this page',
    preg_match('/name="restoreEventID" value="' . $restThird . '"/', $restOpen['body']) === 1
    && preg_match('/name="restoreEventID" value="' . $restFourth . '"/', $restOpen['body']) === 1);
ok('T06 the page says a date comes back as a draft',
    str_contains(text($restOpen['body']), 'A date you bring back arrives as a draft'));
// 🧠 The live dates are NOT offered back: a check that "the two hidden ones are
//    listed" would pass on a page listing every date of the series.
$restLive = array_values(array_filter(repatRows('EVREP Restore'), static fn (array $r): bool => (int) $r['isDeleted'] === 0));
ok('T07 a LIVE date is not listed as hidden',
    str_contains($restOpen['body'], 'data-hidden-event="' . (int) $restLive[0]['eventID'] . '"') === false);

// -----------------------------------------------------------------------------
// T8 — refused for somebody who is not an administrator, and without a token.
// -----------------------------------------------------------------------------
$restMemberPage = restPage($jar['member'], $restSeries);
ok('T08 a member who is not an administrator is refused the page (403)',
    $restMemberPage['status'] === 403, 'HTTP ' . $restMemberPage['status']);
$restMemberPost = req($jar['member'], 'POST', '/calendar/manage/series-edit', [
    'csrf_token'     => restMetaToken($jar['member']),
    'seriesID'       => (string) $restSeries,
    'action'         => 'restore',
    'restoreEventID' => (string) $restThird,
]);
ok('T09 ...and a post from them straight at the handler is refused too (403)',
    $restMemberPost['status'] === 403, 'HTTP ' . $restMemberPost['status']);
ok('T09b (premise) the member really did send a valid-looking form token, so T10 is about the gate',
    restMetaToken($jar['member']) !== '');
ok('T10 ...and the date is STILL HIDDEN (read back)',
    (int) (restRow($restThird)['isDeleted'] ?? -1) === 1);

$restNoToken = restPress($jar['admin'], $restSeries, $restThird, '');
ok('T11 a press with NO form token changes nothing: the date is still hidden',
    (int) (restRow($restThird)['isDeleted'] ?? -1) === 1, json_encode($restNoToken));
$restBadToken = restPress($jar['admin'], $restSeries, $restThird, str_repeat('0', 64));
ok('T12 a press with a WRONG form token changes nothing either',
    (int) (restRow($restThird)['isDeleted'] ?? -1) === 1, json_encode($restBadToken));

// 🛡️ THE BULK UPDATE CANNOT REACH A HIDDEN DATE. Its checkbox list shows live
//    dates only, but its write had no `isDeleted = 0`, so a forged number
//    changed a hidden date's status. Posted with a LIVE date beside it, so the
//    check also proves the update itself ran.
$restBulkLive = (int) one(
    'SELECT eventID FROM tblEvents WHERE seriesID = ? AND isDeleted = 0 ORDER BY startDateTime LIMIT 1',
    'i',
    [$restSeries]
)['eventID'];
req($jar['admin'], 'POST', '/calendar/manage/series-edit', [
    'csrf_token' => managePage($jar['admin'])['token'],
    'seriesID'   => (string) $restSeries,
    'eventIDs'   => [(string) $restBulkLive, (string) $restThird],
    'bulkStatus' => 'postponed',
]);
ok('T12b a bulk update naming a HIDDEN date leaves that date exactly as it was (read back: still hidden, still published)',
    (int) (restRow($restThird)['isDeleted'] ?? -1) === 1 && (string) (restRow($restThird)['status'] ?? '') === 'published',
    json_encode(restRow($restThird)));
ok('T12c ...while the LIVE date posted beside it WAS updated, so the update really ran',
    (string) (restRow($restBulkLive)['status'] ?? '') === 'postponed');
q("UPDATE tblEvents SET status = 'published' WHERE eventID = ?", 'i', [$restBulkLive]);

// -----------------------------------------------------------------------------
// T13 — bringing one back.
// -----------------------------------------------------------------------------
$restBefore = restRow($restThird);
$restDone   = restPress($jar['admin'], $restSeries, $restThird);
$restAfter  = restRow($restThird);
ok('T13 the press answers with a redirect back to the bulk edit page',
    $restDone['status'] === 302 && str_contains($restDone['location'], 'calendar/manage/series-edit?seriesID=' . $restSeries),
    json_encode($restDone));
ok('T14 THE DATE IS LIVE AGAIN (isDeleted = 0, read back)', (int) ($restAfter['isDeleted'] ?? -1) === 0, json_encode($restAfter));
ok('T15 its deletion time is emptied', $restAfter !== null && $restAfter['deletedAt'] === null);
ok('T16 it comes back as a DRAFT, though it was published when it was hidden',
    (string) ($restBefore['status'] ?? '') === 'published' && (string) ($restAfter['status'] ?? '') === 'draft',
    (string) ($restBefore['status'] ?? '') . ' → ' . (string) ($restAfter['status'] ?? ''));
ok('T17 it is the SAME row: same number, same address, same series, same time',
    $restAfter !== null
    && (string) $restAfter['eventSlug'] === $restThirdSlug
    && (int) $restAfter['seriesID'] === $restSeries
    && (string) $restAfter['startDateTime'] === '2029-10-21 10:00:00');
ok('T18 it records who brought it back', (int) ($restAfter['updatedByID'] ?? 0) === ADMIN);
ok('T19 the message says it is back as a draft', str_contains($restDone['message'], 'back in the series as a draft'), $restDone['message']);
ok('T20 the OTHER hidden date was not touched', (int) (restRow($restFourth)['isDeleted'] ?? -1) === 1);
ok('T21 the activity log records it, by number only — the WHOLE description, compared exactly',
    restLogLines($restThird) === ['Event #' . $restThird . ' of series #' . $restSeries . ' brought back as a draft'],
    json_encode(restLogLines($restThird)));
ok('T22 the page no longer lists it as hidden',
    str_contains(restPage($jar['admin'], $restSeries)['body'], 'data-hidden-event="' . $restThird . '"') === false);

$restAgain = restPress($jar['admin'], $restSeries, $restThird);
ok('T23 pressing it again (a second administrator a moment later) says it is already back, and changes nothing',
    str_contains($restAgain['message'], 'already back') && restRow($restThird) === $restAfter, $restAgain['message']);

// -----------------------------------------------------------------------------
// T24 — another organisation's date, and a number that does not exist, get the
//       SAME answer, and the other organisation's row is untouched.
// -----------------------------------------------------------------------------
q(
    "INSERT INTO tblEvents (siteID, seriesID, eventName, eventSlug, startDateTime, endDateTime, timezone, status, isDeleted, deletedAt) "
    // 🧠 ON A DAY THIS SERIES DOES NOT USE. On 21 October (live again by now)
    //    it would be refused as "day taken" whatever the organisation scope
    //    said, so the check could pass for a reason that has nothing to do
    //    with what it is about.
    . "VALUES (?, ?, 'EVREP Other org hidden', 'evrep-other-org-hidden', '2029-12-30 10:00:00', '2029-12-30 11:00:00', ?, 'published', 1, NOW())",
    'iis',
    [OTHER, OTHER_SERIES, ZONE]
);
$restOtherId = (int) $db->insert_id;
$restMissing = (int) one('SELECT COALESCE(MAX(eventID), 0) + 1000 AS n FROM tblEvents')['n'];
$restTheirs  = restPress($jar['admin'], $restSeries, $restOtherId);
$restNone    = restPress($jar['admin'], $restSeries, $restMissing);
ok('T24 ANOTHER ORGANISATION\'S hidden date is not brought back (read back)',
    (int) (restRow($restOtherId)['isDeleted'] ?? -1) === 1 && (string) (restRow($restOtherId)['status'] ?? '') === 'published');
ok('T25 and the answer is WORD FOR WORD the answer for a number that does not exist',
    $restTheirs['message'] === $restNone['message'] && $restTheirs['status'] === $restNone['status']
    && str_contains($restNone['message'], 'not a hidden date of this series'),
    $restTheirs['message'] . ' | ' . $restNone['message']);
// The other organisation's series cannot even be opened from here: the page's
// own gate, which the restore sits behind.
$restOtherSeries = req($jar['admin'], 'POST', '/calendar/manage/series-edit', [
    'csrf_token' => managePage($jar['admin'])['token'],
    'seriesID' => (string) OTHER_SERIES, 'action' => 'restore', 'restoreEventID' => (string) $restOtherId,
]);
ok('T26 naming the other organisation\'s SERIES as well changes nothing either',
    (int) (restRow($restOtherId)['isDeleted'] ?? -1) === 1, json_encode($restOtherSeries['status']));

// -----------------------------------------------------------------------------
// T27 — THE DAY IS TAKEN: extend the series over the same weeks again.
// -----------------------------------------------------------------------------
// The case that really happens. The pattern page makes a NEW row for 28 October
// (the hidden one keeps its address for ever), so bringing the hidden one back
// would put that service in the diary twice.
$restApply($restSeries, $restFields, '4');
ok('T27 extending to four dates again re-added 28 October as a NEW row',
    repatLiveStarts('EVREP Restore') === ['2029-10-07 10:00:00', '2029-10-14 10:00:00', '2029-10-21 10:00:00', '2029-10-28 10:00:00']
    && (int) (restRow($restFourth)['isDeleted'] ?? -1) === 1,
    implode(', ', repatLiveStarts('EVREP Restore')));
$restDayPage = restPage($jar['admin'], $restSeries);
ok('T28 the page lists the hidden 28 October, says its day is taken, and offers only the ORDINARY button so far',
    str_contains($restDayPage['body'], 'data-hidden-event="' . $restFourth . '"')
    && preg_match('/name="restoreEventID" value="' . $restFourth . '"/', $restDayPage['body']) === 1
    && str_contains(text($restDayPage['body']), 'already has a date on that day'));
ok('T28b ...and NO "Bring it back anyway" until a first press has been refused (D-144)',
    str_contains($restDayPage['body'], 'data-restore-anyway=') === false
    && str_contains($restDayPage['body'], 'name="confirmSameDay"') === false);
// The FIRST press, the ordinary button.
$restDay = restPress($jar['admin'], $restSeries, $restFourth);
ok('T29 the FIRST press is REFUSED: the hidden date stays hidden (read back)',
    (int) (restRow($restFourth)['isDeleted'] ?? -1) === 1);
ok('T30 and only ONE live date sits on 28 October',
    count(array_filter(repatLiveStarts('EVREP Restore'), static fn (string $s): bool => str_starts_with($s, '2029-10-28'))) === 1);
ok('T31 and the message says plainly why', str_contains($restDay['message'], 'already has a date on that day'), $restDay['message']);

// 🤝 D-144: after that refusal the page offers a separate, deliberate choice.
ok('T31b the page drawn after the refusal offers "Bring it back anyway" for THAT date, carrying the confirmation',
    preg_match('/<form[^>]*data-restore-anyway="' . $restFourth . '"/', $restDay['body']) === 1
    && str_contains($restDay['body'], 'name="confirmSameDay" value="1"')
    && str_contains(text($restDay['body']), 'Bring it back anyway'));
ok('T31c ...and only once: drawn again, the page no longer offers it',
    str_contains(restPage($jar['admin'], $restSeries)['body'], 'data-restore-anyway=') === false);

// A forged confirmation, from somebody who may not manage the series, and
// from an administrator's browser without the form token: both refused.
$restForged = req($jar['member'], 'POST', '/calendar/manage/series-edit', [
    'csrf_token'     => restMetaToken($jar['member']),
    'seriesID'       => (string) $restSeries,
    'action'         => 'restore',
    'restoreEventID' => (string) $restFourth,
    'confirmSameDay' => '1',
]);
ok('T31d a CONFIRMED press from a member who is not an administrator is refused (403) and changes nothing',
    $restForged['status'] === 403 && (int) (restRow($restFourth)['isDeleted'] ?? -1) === 1, 'HTTP ' . $restForged['status']);
restPress($jar['admin'], $restSeries, $restFourth, '', true);
ok('T31e a CONFIRMED press WITHOUT the form token changes nothing',
    (int) (restRow($restFourth)['isDeleted'] ?? -1) === 1);

// The deliberate second press itself.
$restAnyway = restPress($jar['admin'], $restSeries, $restFourth, null, true);
$restAnywayRow = restRow($restFourth);
ok('T31f THE CONFIRMED PRESS BRINGS IT BACK, as a draft (read back)',
    (int) ($restAnywayRow['isDeleted'] ?? -1) === 0 && (string) ($restAnywayRow['status'] ?? '') === 'draft',
    json_encode($restAnywayRow));
ok('T31g ...so 28 October now holds TWO live dates of the series',
    count(array_filter(repatLiveStarts('EVREP Restore'), static fn (string $s): bool => str_starts_with($s, '2029-10-28'))) === 2);
ok('T31h ...and the message says plainly that the series now has two dates on that day',
    str_contains($restAnyway['message'], 'now has two dates on that day'), $restAnyway['message']);
ok('T31i ...and the activity log records that it was confirmed — the whole description, compared exactly',
    restLogLines($restFourth) === ['Event #' . $restFourth . ' of series #' . $restSeries
        . ' brought back as a draft, confirmed onto a day the series already had a date on'],
    json_encode(restLogLines($restFourth)));

// The confirmation on a day with NO clash is simply an ordinary restore: it
// skips nothing, and the words do not claim two dates on one day.
createEvent($jar['admin'], 'EVREP Restore confirm', '2030-04-07T10:00', '2030-04-07T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$confirmSeries = repatSeries('EVREP Restore confirm');
$restApply($confirmSeries, ['patternStart' => '2030-04-07T10:00', 'patternEnd' => '2030-04-07T11:00', 'repeatFrequency' => 'weekly'], '1');
$confirmHidden = restHidden('EVREP Restore confirm');
$confirmId     = (int) ($confirmHidden[0]['eventID'] ?? 0);
$confirmPress  = restPress($jar['admin'], $confirmSeries, $confirmId, null, true);
ok('T31j the confirmation on a day with NO clash restores it like an ordinary press (read back: live, draft)',
    count($confirmHidden) === 1
    && (int) (restRow($confirmId)['isDeleted'] ?? -1) === 0 && (string) (restRow($confirmId)['status'] ?? '') === 'draft');
ok('T31k ...with the ORDINARY words, which do not claim two dates on one day',
    str_contains($confirmPress['message'], 'back in the series as a draft')
    && str_contains($confirmPress['message'], 'two dates') === false, $confirmPress['message']);

// -----------------------------------------------------------------------------
// T32 — TWO ADMINISTRATORS: the restore waits for the series' lock and then
//       sees what the other committed.
// -----------------------------------------------------------------------------
// This connection holds the SERIES row (the lock every restore must pass
// through) and, while holding it, gives the series a live date on the hidden
// date's day — what a second administrator's restore or pattern change would
// do. The real restore runs over HTTP meanwhile. With the lock it WAITS, then
// reads the committed day and refuses; without it it would run straight
// through on the old world and put two dates on one day.
createEvent($jar['admin'], 'EVREP Restore race', '2029-11-04T10:00', '2029-11-04T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$raceSeries = repatSeries('EVREP Restore race');
$restApply($raceSeries, ['patternStart' => '2029-11-04T10:00', 'patternEnd' => '2029-11-04T11:00', 'repeatFrequency' => 'weekly'], '1');
$raceHidden = restHidden('EVREP Restore race');
$raceId     = (int) ($raceHidden[0]['eventID'] ?? 0);
ok('T32 the race series has one hidden date to bring back', count($raceHidden) === 1, json_encode($raceHidden));

$raceToken = token(restPage($jar['admin'], $raceSeries)['body']);
$db->begin_transaction();
q('SELECT seriesID FROM tblEventSeries WHERE seriesID = ? FOR UPDATE', 'i', [$raceSeries]);

$raceHandle = curl_init('http://127.0.0.1:' . $port . ADDR . '/calendar/manage/series-edit');
curl_setopt_array($raceHandle, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 120, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:'],
    CURLOPT_COOKIEJAR => $jar['admin'], CURLOPT_COOKIEFILE => $jar['admin'], CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'csrf_token' => $raceToken, 'seriesID' => (string) $raceSeries, 'action' => 'restore', 'restoreEventID' => (string) $raceId,
    ]),
]);
$raceMulti = curl_multi_init();
curl_multi_add_handle($raceMulti, $raceHandle);
$raceRunning = 1;
$raceUntil   = microtime(true) + 2.0;
while (microtime(true) < $raceUntil && $raceRunning > 0) {
    curl_multi_exec($raceMulti, $raceRunning);
    curl_multi_select($raceMulti, 0.1);
}
// 🧠 THE PREMISE, ASSERTED: two seconds on, the request must still be waiting.
//    A sleep is a hope; this is the state the sleep was meant to produce. If it
//    has already finished, the restore did not wait for the series, and every
//    check below would be about a race that never happened.
ok('T33 the restore is WAITING for the series while another writer holds it', $raceRunning > 0);
q(
    "INSERT INTO tblEvents (siteID, seriesID, eventName, eventSlug, startDateTime, endDateTime, timezone, status, isDeleted) "
    . "VALUES (?, ?, 'EVREP Restore race', 'evrep-restore-race-second-writer', '2029-11-11 10:00:00', '2029-11-11 11:00:00', ?, 'draft', 0)",
    'iis',
    [ORG, $raceSeries, ZONE]
);
$db->commit();
$raceUntil = microtime(true) + 60;
while ($raceRunning > 0 && microtime(true) < $raceUntil) {
    curl_multi_exec($raceMulti, $raceRunning);
    curl_multi_select($raceMulti, 0.2);
}
$raceStatus = (int) curl_getinfo($raceHandle, CURLINFO_HTTP_CODE);
curl_multi_remove_handle($raceMulti, $raceHandle);
curl_multi_close($raceMulti);
$raceAfter = req($jar['admin'], 'GET', '/calendar/manage/series-edit?seriesID=' . $raceSeries);
ok('T34 once the other writer commits, the restore REFUSES: the date is still hidden (read back)',
    (int) (restRow($raceId)['isDeleted'] ?? -1) === 1, 'HTTP ' . $raceStatus);
ok('T35 ...one live date on 11 November, not two',
    count(array_filter(repatLiveStarts('EVREP Restore race'), static fn (string $s): bool => str_starts_with($s, '2029-11-11'))) === 1);
ok('T36 ...and the message says the day is taken', str_contains(flashOn($raceAfter['body']), 'already has a date on that day'),
    flashOn($raceAfter['body']));

// -----------------------------------------------------------------------------
// T36b — THE SERIES LOCK IS HELD FOR THE WHOLE RESTORE, not just one statement.
// -----------------------------------------------------------------------------
// T33-T36 cannot tell this apart: a locking read OUTSIDE a transaction still
// WAITS for another holder, so with `begin_transaction()` moved down to the
// write they all still passed (found by the independent check). The property
// that matters is that the series row is still held while the restore is part
// way through. So this connection holds the hidden DATE's row, the restore
// (having taken the series row in step 1) waits at step 3, and this connection
// then asks for the series row with NOWAIT: refused (3572) only if the restore
// is still holding it.
createEvent($jar['admin'], 'EVREP Restore nowait', '2030-05-05T10:00', '2030-05-05T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$nowaitSeries = repatSeries('EVREP Restore nowait');
$restApply($nowaitSeries, ['patternStart' => '2030-05-05T10:00', 'patternEnd' => '2030-05-05T11:00', 'repeatFrequency' => 'weekly'], '1');
$nowaitHidden = restHidden('EVREP Restore nowait');
$nowaitId     = (int) ($nowaitHidden[0]['eventID'] ?? 0);
ok('T36b the lock-holding check has one hidden date', count($nowaitHidden) === 1);
$nowaitToken = managePage($jar['admin'])['token'];
$db->begin_transaction();
q('SELECT eventID FROM tblEvents WHERE eventID = ? FOR UPDATE', 'i', [$nowaitId]);
$nowaitAsync = restAsyncStart($jar['admin'], '/calendar/manage/series-edit', [
    'csrf_token' => $nowaitToken, 'seriesID' => (string) $nowaitSeries, 'action' => 'restore', 'restoreEventID' => (string) $nowaitId,
]);
ok('T36c (premise) the restore is WAITING for the date this connection holds', $nowaitAsync['running'] > 0);
$nowaitCode = 0;
try {
    q('SELECT seriesID FROM tblEventSeries WHERE seriesID = ? FOR UPDATE NOWAIT', 'i', [$nowaitSeries]);
} catch (mysqli_sql_exception $e) {
    $nowaitCode = (int) $e->getCode();
}
ok('T36d WHILE IT WAITS, THE SERIES ROW IS STILL HELD BY THE RESTORE: asking for it with NOWAIT is refused (3572)',
    $nowaitCode === 3572, 'error code ' . $nowaitCode);
$db->rollback();
restAsyncFinish($nowaitAsync);
ok('T36e once released, the restore finishes and the date is back (read back)',
    (int) (restRow($nowaitId)['isDeleted'] ?? -1) === 0);

// -----------------------------------------------------------------------------
// T36f — THE PATTERN PAGE TAKES THE SAME LOCK AND RE-CHECKS (#691).
// -----------------------------------------------------------------------------
// The pattern page works out its plan WITHOUT a lock. A date brought back in
// the window between that plan and the page's own transaction would otherwise
// leave two live dates on one day: the plan adds a date onto a day that has
// just been filled. This connection holds the series row while the page is
// confirming, brings the hidden date back meanwhile (exactly what a restore
// writes), and commits; the page must then refuse.
createEvent($jar['admin'], 'EVREP Repat lock', '2030-06-02T10:00', '2030-06-02T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$plockSeries = repatSeries('EVREP Repat lock');
$plockFields = ['patternStart' => '2030-06-02T10:00', 'patternEnd' => '2030-06-02T11:00', 'repeatFrequency' => 'weekly'];
$restApply($plockSeries, $plockFields, '1');
$plockHidden = restHidden('EVREP Repat lock');
$plockId     = (int) ($plockHidden[0]['eventID'] ?? 0);
ok('T36f the pattern-lock series has its second date hidden', count($plockHidden) === 1
    && (string) $plockHidden[0]['startDateTime'] === '2030-06-09 10:00:00');
$plockPreview = repatPost($jar['admin'], $plockSeries, $plockFields + ['repeatCount' => '2']);
ok('T36g (premise) the preview would ADD 9 June back', preg_match('/Added\s*<span class="badge bg-success">1<\/span>/', $plockPreview['body']) === 1);
$plockToken = token(repatOpen($jar['admin'], $plockSeries)['body']);
$db->begin_transaction();
q('SELECT seriesID FROM tblEventSeries WHERE seriesID = ? FOR UPDATE', 'i', [$plockSeries]);
$plockAsync = restAsyncStart($jar['admin'], '/calendar/manage/series-repattern', $plockFields + [
    'csrf_token' => $plockToken, 'seriesID' => (string) $plockSeries, 'repeatCount' => '2',
    'confirm' => '1', 'planDigest' => $plockPreview['digest'],
]);
ok('T36h (premise) the pattern change is WAITING for the series this connection holds', $plockAsync['running'] > 0);
// 🧠 CAUGHT, NOT LEFT TO CRASH THE RUN. With the page's series lock removed
//    (planted), the page locks this series' DATE rows first and then waits on
//    the series row this connection holds, so this write is chosen as the
//    victim of a deadlock. A check whose failure is an uncaught exception is
//    a crash with a label, so the deadlock is turned into a red check here.
$plockDeadlock = '';
try {
    q("UPDATE tblEvents SET isDeleted = 0, deletedAt = NULL, status = 'draft' WHERE eventID = ?", 'i', [$plockId]);
    $db->commit();
} catch (mysqli_sql_exception $e) {
    $plockDeadlock = 'error ' . (int) $e->getCode();
    $db->rollback();
}
restAsyncFinish($plockAsync);
ok('T36h2 the page waited on the SERIES row before touching any date, so this write met no deadlock',
    $plockDeadlock === '', $plockDeadlock);
ok('T36i THE PATTERN CHANGE REFUSED: 9 June holds ONE live date, the one brought back, not two',
    count(array_filter(repatLiveStarts('EVREP Repat lock'), static fn (string $s): bool => str_starts_with($s, '2030-06-09'))) === 1,
    implode(', ', repatLiveStarts('EVREP Repat lock')));
ok('T36j ...and nothing was added at all (no new row)', count(repatRows('EVREP Repat lock')) === 2,
    (string) count(repatRows('EVREP Repat lock')));

// -----------------------------------------------------------------------------
// T36k — A DATE CANCELLED BEFORE IT WAS HIDDEN COMES BACK STILL CANCELLED (D-146).
// -----------------------------------------------------------------------------
// Cancelled through the real bulk update. The REASON is written with SQL. That
// was the only way when this was written (nothing under web/ wrote
// `cancelReason` until #998); it is kept, because what this checks is the
// restore, and section U drives the reason's own writer.
createEvent($jar['admin'], 'EVREP Restore cancelled', '2030-07-07T10:00', '2030-07-07T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$cancSeries = repatSeries('EVREP Restore cancelled');
$cancRows   = repatRows('EVREP Restore cancelled');
$cancId     = (int) ($cancRows[1]['eventID'] ?? 0);
req($jar['admin'], 'POST', '/calendar/manage/series-edit', [
    'csrf_token' => managePage($jar['admin'])['token'],
    'seriesID'   => (string) $cancSeries,
    'eventIDs'   => [(string) $cancId],
    'bulkStatus' => 'cancelled',
]);
q("UPDATE tblEvents SET cancelReason = 'EVREP the hall is flooded' WHERE eventID = ?", 'i', [$cancId]);
$restApply($cancSeries, ['patternStart' => '2030-07-07T10:00', 'patternEnd' => '2030-07-07T11:00', 'repeatFrequency' => 'weekly'], '1');
$cancBefore = one('SELECT isDeleted, status, cancelReason FROM tblEvents WHERE eventID = ?', 'i', [$cancId]);
ok('T36k (premise) the second date was cancelled, with a reason, and then hidden by a pattern change',
    (int) ($cancBefore['isDeleted'] ?? 0) === 1 && (string) ($cancBefore['status'] ?? '') === 'cancelled'
    && (string) ($cancBefore['cancelReason'] ?? '') === 'EVREP the hall is flooded', json_encode($cancBefore));
$cancPage = restPage($jar['admin'], $cancSeries);
ok('T36l the page shows it was Cancelled and its button says it comes back still cancelled',
    str_contains(text($cancPage['body']), 'Bring back, still cancelled'));
$cancPress = restPress($jar['admin'], $cancSeries, $cancId);
$cancAfter = one('SELECT isDeleted, status, cancelReason FROM tblEvents WHERE eventID = ?', 'i', [$cancId]);
ok('T36m IT COMES BACK STILL CANCELLED, WITH ITS REASON (read back)',
    (int) ($cancAfter['isDeleted'] ?? -1) === 0 && (string) ($cancAfter['status'] ?? '') === 'cancelled'
    && (string) ($cancAfter['cancelReason'] ?? '') === 'EVREP the hall is flooded', json_encode($cancAfter));
ok('T36n ...and the message says so, word for word',
    $cancPress['message'] === RESTORE_WORDS_CANCELLED, $cancPress['message']);
ok('T36o ...and the activity log says so, compared exactly',
    restLogLines($cancId) === ['Event #' . $cancId . ' of series #' . $cancSeries . ' brought back still cancelled'],
    json_encode(restLogLines($cancId)));

// -----------------------------------------------------------------------------
// T36p — THE SAME RULE ON THE CONFIRMED PATH, AND FOR A POSTPONED DATE (D-146, D-147).
// -----------------------------------------------------------------------------
// The independent check of round 3 found the CONFIRMED path pinned by nothing:
// making a cancelled date come back as a draft after "Bring it back anyway",
// or giving it the ordinary same-day words, left every check green. And D-147
// (postponed stays postponed) needs its own proof on both paths.
//
// One helper builds each world the same way: a weekly series of `$count`
// dates; the dates after the first are given `$status` through the REAL bulk
// update and a reason with SQL (written before #998 gave the column a
// writer; section U drives that); a real pattern change cuts the series to
// ONE date, hiding the rest.
/** @return array{series:int, rows:list<array<string,mixed>>} */
$keptWorld = static function (string $name, string $firstStart, string $firstEnd, int $count, string $status, string $reason) use ($jar, $restApply): array {
    createEvent($jar['admin'], $name, $firstStart, $firstEnd, ['repeatFrequency' => 'weekly', 'repeatCount' => (string) $count]);
    $series = repatSeries($name);
    $rows   = repatRows($name);
    $later  = array_map(static fn (array $r): string => (string) $r['eventID'], array_slice($rows, 1));
    req($jar['admin'], 'POST', '/calendar/manage/series-edit', [
        'csrf_token' => managePage($jar['admin'])['token'],
        'seriesID'   => (string) $series,
        'eventIDs'   => $later,
        'bulkStatus' => $status,
    ]);
    foreach ($later as $id) {
        q('UPDATE tblEvents SET cancelReason = ? WHERE eventID = ?', 'si', [$reason, (int) $id]);
    }
    $restApply($series, ['patternStart' => $firstStart, 'patternEnd' => $firstEnd, 'repeatFrequency' => 'weekly'], '1');

    return ['series' => $series, 'rows' => repatRows($name)];
};

// -- A cancelled date, brought back onto a TAKEN day with the confirmation.
$ccWorld = $keptWorld('EVREP Restore cancday', '2030-08-04T10:00', '2030-08-04T11:00', 2, 'cancelled', 'EVREP the roof leaks');
$ccId    = (int) ($ccWorld['rows'][1]['eventID'] ?? 0);
// Extend over the same week again: the pattern page makes a NEW row for 11 August.
$restApply($ccWorld['series'], ['patternStart' => '2030-08-04T10:00', 'patternEnd' => '2030-08-04T11:00', 'repeatFrequency' => 'weekly'], '2');
$ccBefore = one('SELECT isDeleted, status, cancelReason FROM tblEvents WHERE eventID = ?', 'i', [$ccId]);
ok('T36p (premise) the cancelled date is hidden and its day now has a NEW live date',
    (int) ($ccBefore['isDeleted'] ?? 0) === 1 && (string) ($ccBefore['status'] ?? '') === 'cancelled'
    && count(array_filter(repatLiveStarts('EVREP Restore cancday'), static fn (string $x): bool => str_starts_with($x, '2030-08-11'))) === 1,
    json_encode($ccBefore));
$ccFirst = restPress($jar['admin'], $ccWorld['series'], $ccId);
ok('T36q the first press is refused for the day', (int) (restRow($ccId)['isDeleted'] ?? -1) === 1, $ccFirst['message']);
$ccPress = restPress($jar['admin'], $ccWorld['series'], $ccId, null, true);
$ccAfter = one('SELECT isDeleted, status, cancelReason FROM tblEvents WHERE eventID = ?', 'i', [$ccId]);
ok('T36r CONFIRMED onto the taken day, IT COMES BACK STILL CANCELLED WITH ITS REASON (read back)',
    (int) ($ccAfter['isDeleted'] ?? -1) === 0 && (string) ($ccAfter['status'] ?? '') === 'cancelled'
    && (string) ($ccAfter['cancelReason'] ?? '') === 'EVREP the roof leaks', json_encode($ccAfter));
ok('T36s ...with the same-day CANCELLED words, word for word', $ccPress['message'] === RESTORE_WORDS_SAME_DAY_CANCELLED, $ccPress['message']);
ok('T36t ...and the log line, compared exactly',
    restLogLines($ccId) === ['Event #' . $ccId . ' of series #' . $ccWorld['series']
        . ' brought back still cancelled, confirmed onto a day the series already had a date on'],
    json_encode(restLogLines($ccId)));

// -- Postponed (D-147): an ordinary restore, then a confirmed one onto a taken day.
$ppWorld = $keptWorld('EVREP Restore postponed', '2030-09-01T10:00', '2030-09-01T11:00', 3, 'postponed', 'EVREP the minister is ill');
$ppSecond = (int) ($ppWorld['rows'][1]['eventID'] ?? 0);
$ppThird  = (int) ($ppWorld['rows'][2]['eventID'] ?? 0);
ok('T36u (premise) two postponed dates, each with a reason, were hidden by the pattern change',
    count(restHidden('EVREP Restore postponed')) === 2
    && (string) (restRow($ppSecond)['status'] ?? '') === 'postponed' && (string) (restRow($ppThird)['status'] ?? '') === 'postponed');
ok('T36v the button for a postponed date says it comes back still postponed',
    str_contains(text(restPage($jar['admin'], $ppWorld['series'])['body']), 'Bring back, still postponed'));
$ppPress = restPress($jar['admin'], $ppWorld['series'], $ppSecond);
$ppAfter = one('SELECT isDeleted, status, cancelReason FROM tblEvents WHERE eventID = ?', 'i', [$ppSecond]);
ok('T36w A POSTPONED DATE COMES BACK STILL POSTPONED, WITH ITS REASON (read back)',
    (int) ($ppAfter['isDeleted'] ?? -1) === 0 && (string) ($ppAfter['status'] ?? '') === 'postponed'
    && (string) ($ppAfter['cancelReason'] ?? '') === 'EVREP the minister is ill', json_encode($ppAfter));
ok('T36x ...with the postponed words, word for word', $ppPress['message'] === RESTORE_WORDS_POSTPONED, $ppPress['message']);
ok('T36y ...and the log line, compared exactly',
    restLogLines($ppSecond) === ['Event #' . $ppSecond . ' of series #' . $ppWorld['series'] . ' brought back still postponed'],
    json_encode(restLogLines($ppSecond)));
// Extend to three again: the page makes a NEW row for 15 September, so the
// hidden postponed third date's day is taken.
$restApply($ppWorld['series'], ['patternStart' => '2030-09-01T10:00', 'patternEnd' => '2030-09-01T11:00', 'repeatFrequency' => 'weekly'], '3');
ok('T36z (premise) the third date is still hidden and its day has a NEW live date',
    (int) (restRow($ppThird)['isDeleted'] ?? 0) === 1
    && count(array_filter(repatLiveStarts('EVREP Restore postponed'), static fn (string $x): bool => str_starts_with($x, '2030-09-15'))) === 1,
    implode(', ', repatLiveStarts('EVREP Restore postponed')));
restPress($jar['admin'], $ppWorld['series'], $ppThird);
$ppConf      = restPress($jar['admin'], $ppWorld['series'], $ppThird, null, true);
$ppConfAfter = one('SELECT isDeleted, status, cancelReason FROM tblEvents WHERE eventID = ?', 'i', [$ppThird]);
ok('T36za CONFIRMED onto the taken day, A POSTPONED DATE COMES BACK STILL POSTPONED WITH ITS REASON (read back)',
    (int) ($ppConfAfter['isDeleted'] ?? -1) === 0 && (string) ($ppConfAfter['status'] ?? '') === 'postponed'
    && (string) ($ppConfAfter['cancelReason'] ?? '') === 'EVREP the minister is ill', json_encode($ppConfAfter));
ok('T36zb ...with the same-day POSTPONED words, word for word', $ppConf['message'] === RESTORE_WORDS_SAME_DAY_POSTPONED, $ppConf['message']);
ok('T36zc ...and the log line, compared exactly',
    restLogLines($ppThird) === ['Event #' . $ppThird . ' of series #' . $ppWorld['series']
        . ' brought back still postponed, confirmed onto a day the series already had a date on'],
    json_encode(restLogLines($ppThird)));

// -----------------------------------------------------------------------------
// T37 — THE ADDRESS IS TAKEN. Unreachable while the unique key exists; proved,
//       then reached by removing the key, which the portal never does.
// -----------------------------------------------------------------------------
createEvent($jar['admin'], 'EVREP Restore slug', '2029-12-02T10:00', '2029-12-02T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$slugRestSeries = repatSeries('EVREP Restore slug');
$restApply($slugRestSeries, ['patternStart' => '2029-12-02T10:00', 'patternEnd' => '2029-12-02T11:00', 'repeatFrequency' => 'weekly'], '1');
$slugHidden   = restHidden('EVREP Restore slug');
$slugHiddenId = (int) ($slugHidden[0]['eventID'] ?? 0);
$slugAddress  = (string) ($slugHidden[0]['eventSlug'] ?? '');
ok('T37 the address series has one hidden date', count($slugHidden) === 1 && $slugAddress !== '', json_encode($slugHidden));

// 🧠 THE PREMISE THAT MAKES THIS CASE UNREACHABLE, MEASURED: the database will
//    not give a second row the hidden date's address. If this ever stops being
//    true, the check below is no longer about a lost key.
$slugRefused = false;
try {
    q(
        "INSERT INTO tblEvents (siteID, eventName, eventSlug, startDateTime, timezone, status) VALUES (?, 'EVREP Slug thief', ?, '2030-01-01 10:00:00', ?, 'draft')",
        'iss',
        [ORG, $slugAddress, ZONE]
    );
} catch (mysqli_sql_exception $e) {
    $slugRefused = (int) $e->getCode() === 1062;
}
ok('T38 PREMISE: while uq_event_site_slug exists the database REFUSES a second holder of a hidden date\'s address',
    $slugRefused);

$db->query('ALTER TABLE `tblEvents` DROP INDEX `uq_event_site_slug`');
try {
    q(
        "INSERT INTO tblEvents (siteID, eventName, eventSlug, startDateTime, timezone, status) VALUES (?, 'EVREP Slug thief', ?, '2030-01-01 10:00:00', ?, 'draft')",
        'iss',
        [ORG, $slugAddress, ZONE]
    );
    $slugThief = (int) $db->insert_id;
    $slugPress = restPress($jar['admin'], $slugRestSeries, $slugHiddenId);
    ok('T39 with the key lost and the address taken, the date is NOT brought back (read back)',
        (int) (restRow($slugHiddenId)['isDeleted'] ?? -1) === 1);
    ok('T40 ...and the message says the address is in use', str_contains($slugPress['message'], 'web address'), $slugPress['message']);
    q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [$slugThief]);
} finally {
    q("DELETE FROM tblEvents WHERE eventName = 'EVREP Slug thief'");
    $db->query('ALTER TABLE `tblEvents` ADD UNIQUE KEY `uq_event_site_slug` (`siteID`, `eventSlug`)');
}
// 🧠 NOT VACUOUS: with the thief gone the same press succeeds, so T39 was
//    refused for the ADDRESS and for nothing else about that date.
$slugOk = restPress($jar['admin'], $slugRestSeries, $slugHiddenId);
ok('T41 with the address free again the same date IS brought back, as a draft',
    (int) (restRow($slugHiddenId)['isDeleted'] ?? -1) === 0 && (string) (restRow($slugHiddenId)['status'] ?? '') === 'draft',
    $slugOk['message']);

// -----------------------------------------------------------------------------
// T42 — a series that has GONE: its hidden dates stay hidden.
// -----------------------------------------------------------------------------
createEvent($jar['admin'], 'EVREP Restore gone', '2030-02-03T10:00', '2030-02-03T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$goneSeries = repatSeries('EVREP Restore gone');
$restApply($goneSeries, ['patternStart' => '2030-02-03T10:00', 'patternEnd' => '2030-02-03T11:00', 'repeatFrequency' => 'weekly'], '1');
$goneHidden = restHidden('EVREP Restore gone');
$goneId     = (int) ($goneHidden[0]['eventID'] ?? 0);
ok('T42 the series to delete has one hidden date', count($goneHidden) === 1);
// Deleted through the real Series page, which unlinks its dates first.
$seriesListPage = req($jar['admin'], 'GET', '/calendar/manage/series');
req($jar['admin'], 'POST', '/calendar/manage/series', [
    'csrf_token' => token($seriesListPage['body']), 'action' => 'delete', 'seriesID' => (string) $goneSeries,
]);
ok('T43 the series really is gone', one('SELECT seriesID FROM tblEventSeries WHERE seriesID = ?', 'i', [$goneSeries]) === null);
restPress($jar['admin'], $goneSeries, $goneId);
ok('T44 its hidden date is NOT brought back by naming the gone series (read back)',
    (int) (restRow($goneId)['isDeleted'] ?? -1) === 1);
restPress($jar['admin'], $restSeries, $goneId);
ok('T45 ...nor by naming another series of the organisation',
    (int) (restRow($goneId)['isDeleted'] ?? -1) === 1);

// -----------------------------------------------------------------------------
// T46 — an organisation that has been SWITCHED OFF: nothing comes back into it.
// -----------------------------------------------------------------------------
// The token is taken while the organisation is still on, so a refusal below is
// not merely a missing token. Be exact about what this proves: the portal turns
// a switched-off organisation away before any page runs (`Site`, `SiteGate`),
// so this checks the BEHAVIOUR, and `HiddenSeriesDates::restore()`'s own test
// of `tblSites.isActive` is a second line behind it that this check cannot
// reach on its own.
createEvent($jar['admin'], 'EVREP Restore org', '2030-03-03T10:00', '2030-03-03T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '2',
]);
$orgSeries = repatSeries('EVREP Restore org');
$restApply($orgSeries, ['patternStart' => '2030-03-03T10:00', 'patternEnd' => '2030-03-03T11:00', 'repeatFrequency' => 'weekly'], '1');
$orgHidden = restHidden('EVREP Restore org');
$orgId     = (int) ($orgHidden[0]['eventID'] ?? 0);
ok('T46 the organisation check has one hidden date', count($orgHidden) === 1);
$orgToken = managePage($jar['admin'])['token'];
q('UPDATE tblSites SET isActive = 0 WHERE siteID = ?', 'i', [ORG]);
try {
    restPress($jar['admin'], $orgSeries, $orgId, $orgToken);
} finally {
    q('UPDATE tblSites SET isActive = 1 WHERE siteID = ?', 'i', [ORG]);
}
ok('T47 with the organisation switched off, its hidden date is NOT brought back (read back)',
    (int) (restRow($orgId)['isDeleted'] ?? -1) === 1);

// =============================================================================
// U. Why a date is cancelled or postponed (#998), and a hidden date is not
//    edited (#1000)
// =============================================================================
// #998: until it, nothing under web/ wrote `tblEvents.cancelReason`, so the
// reason members read under the "cancelled" / "postponed" banner could only be
// typed into the database by hand. `Portal\Core\EventCancelReason` is now the
// one rule and the one writer. #1000: the single-event edit page and its save
// handler did not refuse a HIDDEN date, whose status decides what it comes
// back as (D-145 to D-147).
//
// Every check drives the REAL pages over HTTP and reads the row back out of
// the database (D-065). The event page is read as a MEMBER, because members
// are who the reason is for.
head('U. Cancellation reasons (#998) and hidden dates on the edit page (#1000)');

/** One event's status, reason and name, read fresh. */
function reasonRow(int $eventId): ?array
{
    return one(
        'SELECT eventID, eventName, eventSlug, status, cancelReason, isDeleted, seriesID, startDateTime, updatedByID '
        . 'FROM tblEvents WHERE eventID = ?',
        'i',
        [$eventId]
    );
}

/** The message the page a post redirected to shows, read from that page. */
function messageAfter(string $jar, array $sent): string
{
    $to = $sent['location'] === '' ? '/calendar/manage' : (string) preg_replace('#^.*?' . preg_quote(ADDR, '#') . '#', '', $sent['location']);

    return flashOn(req($jar, 'GET', $to)['body']);
}

/**
 * The full set of fields the Manage Events form posts for an update, so a
 * check changes only what it names. `cancelReason` is included — the form
 * always sends the box — unless `$withoutReason` says otherwise.
 */
function reasonEdit(string $jar, int $eventId, array $changes, bool $withoutReason = false): array
{
    $row  = (array) one(
        'SELECT eventID, eventName, startDateTime, endDateTime, timezone, status, locationName, seriesID, isPublic, cancelReason '
        . 'FROM tblEvents WHERE eventID = ?',
        'i',
        [$eventId]
    );
    $post = editPostFor($row, managePage($jar)['token']);
    if ($withoutReason === true) {
        unset($post['cancelReason']);
    }

    return req($jar, 'POST', '/calendar/manage/save', array_merge($post, $changes));
}

/** @return array<string,string> */
function editPostFor(array $row, string $token): array
{
    return [
        'csrf_token'    => $token,
        'action'        => 'update',
        'eventID'       => (string) ($row['eventID'] ?? '0'),
        'eventName'     => (string) ($row['eventName'] ?? 'EVREP Reason'),
        'description'   => 'Made by tools/event-repeat-pages-selftest.php.',
        'startDateTime' => str_replace(' ', 'T', substr((string) ($row['startDateTime'] ?? '2031-05-04 10:00:00'), 0, 16)),
        'endDateTime'   => str_replace(' ', 'T', substr((string) ($row['endDateTime'] ?? '2031-05-04 11:00:00'), 0, 16)),
        'timezone'      => (string) ($row['timezone'] ?? ZONE),
        'status'        => (string) ($row['status'] ?? 'published'),
        'locationName'  => (string) ($row['locationName'] ?? ''),
        'seriesID'      => (string) ($row['seriesID'] ?? ''),
        'isPublic'      => ((int) ($row['isPublic'] ?? 1)) === 1 ? '1' : '',
        'cancelReason'  => (string) ($row['cancelReason'] ?? ''),
    ];
}

/** The event's own page, read by a member. */
function memberEventPage(string $jar, int $eventId): string
{
    $slug = (string) (reasonRow($eventId)['eventSlug'] ?? '');

    return req($jar, 'GET', '/calendar/event?slug=' . rawurlencode($slug))['body'];
}

$notKept = 'The reason for cancelling or postponing was not kept, because a reason is kept only while an event is cancelled or postponed.';

// -----------------------------------------------------------------------------
// U01-U09 — the single-event form.
// -----------------------------------------------------------------------------
createEvent($jar['admin'], 'EVREP Reason one', '2031-05-04T10:00', '2031-05-04T11:00', ['isPublic' => '1']);
$reasonId = (int) (eventsNamed('EVREP Reason one')[0]['eventID'] ?? 0);
ok('U01 (premise) a published one-off event with no reason exists',
    $reasonId > 0 && (string) (reasonRow($reasonId)['status'] ?? '') === 'published'
    && reasonRow($reasonId)['cancelReason'] === null, json_encode(reasonRow($reasonId)));

$editForm = req($jar['admin'], 'GET', '/calendar/manage?edit=' . $reasonId)['body'];
ok('U02 the edit form offers the reason box, named as the handler reads it',
    preg_match('/<textarea[^>]*name="cancelReason"/', $editForm) === 1
    && str_contains((string) file_get_contents(dirname(__DIR__) . '/web/_apps/calendar/manage/save.php'), "\$_POST['cancelReason']"));

// A reason holding markup, so the page's escaping is tested by the same row.
$sent = reasonEdit($jar['admin'], $reasonId, ['status' => 'cancelled', 'cancelReason' => "  EVREP the hall is <b>flooded</b>  "]);
ok('U03 CANCELLED WITH A REASON: stored, trimmed, exactly as typed (read back)',
    (string) (reasonRow($reasonId)['status'] ?? '') === 'cancelled'
    && (string) (reasonRow($reasonId)['cancelReason'] ?? '') === 'EVREP the hall is <b>flooded</b>',
    'HTTP ' . $sent['status'] . ' ' . json_encode(reasonRow($reasonId)));
$memberPage = memberEventPage($jar['member'], $reasonId);
ok('U04 a member sees the cancelled banner and the reason on the event page',
    str_contains($memberPage, 'This event has been cancelled') && str_contains(text($memberPage), 'EVREP the hall is <b>flooded</b>'),
    mb_substr(text($memberPage), 0, 400));
ok('U05 ...and the reason is ESCAPED: the markup shows as text, never as markup',
    str_contains($memberPage, 'EVREP the hall is &lt;b&gt;flooded&lt;/b&gt;') && str_contains($memberPage, '<b>flooded</b>') === false);
$editAgain = req($jar['admin'], 'GET', '/calendar/manage?edit=' . $reasonId)['body'];
ok('U06 the edit form shows the stored reason in its box, escaped',
    str_contains($editAgain, 'EVREP the hall is &lt;b&gt;flooded&lt;/b&gt;</textarea>'));

// Postponed, with a two-line reason as a browser sends it (Windows line ends).
reasonEdit($jar['admin'], $reasonId, ['status' => 'postponed', 'cancelReason' => "EVREP the minister is ill\r\nA new date will follow"]);
ok('U07 POSTPONED WITH A REASON: stored, with the line end made plain (read back)',
    (string) (reasonRow($reasonId)['status'] ?? '') === 'postponed'
    && (string) (reasonRow($reasonId)['cancelReason'] ?? '') === "EVREP the minister is ill\nA new date will follow",
    json_encode(reasonRow($reasonId)));
$memberPage = memberEventPage($jar['member'], $reasonId);
ok('U08 a member sees the postponed banner and both lines of the reason',
    str_contains($memberPage, 'This event has been postponed')
    && preg_match('/EVREP the minister is ill<br\s*\/?>\s*A new date will follow/', $memberPage) === 1,
    mb_substr(text($memberPage), 0, 400));

// A post WITHOUT the field (not the form, which always sends it) keeps the
// reason: "did it arrive" (#677).
reasonEdit($jar['admin'], $reasonId, ['status' => 'cancelled'], true);
ok('U09 a post that does not carry the reason field keeps the stored reason (read back)',
    (string) (reasonRow($reasonId)['status'] ?? '') === 'cancelled'
    && (string) (reasonRow($reasonId)['cancelReason'] ?? '') === "EVREP the minister is ill\nA new date will follow",
    json_encode(reasonRow($reasonId)));

// Back to published, with the old reason still in the box (as the form draws it).
$sent = reasonEdit($jar['admin'], $reasonId, ['status' => 'published']);
ok('U10 BACK TO PUBLISHED: the reason is removed (read back)',
    (string) (reasonRow($reasonId)['status'] ?? '') === 'published' && reasonRow($reasonId)['cancelReason'] === null,
    json_encode(reasonRow($reasonId)));
ok('U11 ...and the message says so, word for word',
    str_contains(messageAfter($jar['admin'], $sent), $notKept), messageAfter($jar['admin'], $sent));

// Too long: refused, nothing changed.
reasonEdit($jar['admin'], $reasonId, ['status' => 'cancelled', 'cancelReason' => 'EVREP short']);
$sent = reasonEdit($jar['admin'], $reasonId, ['status' => 'postponed', 'eventName' => 'EVREP Reason renamed', 'cancelReason' => str_repeat('x', 1001)]);
ok('U12 a reason of 1,001 characters is REFUSED and nothing is changed (read back)',
    (string) (reasonRow($reasonId)['status'] ?? '') === 'cancelled'
    && (string) (reasonRow($reasonId)['cancelReason'] ?? '') === 'EVREP short'
    && (string) (reasonRow($reasonId)['eventName'] ?? '') === 'EVREP Reason one',
    json_encode(reasonRow($reasonId)));
ok('U13 ...with the reason why', str_contains(messageAfter($jar['admin'], $sent), 'is 1,001 characters long; it can be at most 1,000'));
reasonEdit($jar['admin'], $reasonId, ['cancelReason' => str_repeat('y', 1000)]);
ok('U14 ...while exactly 1,000 characters is accepted (the limit is not off by one)',
    (string) (reasonRow($reasonId)['cancelReason'] ?? '') === str_repeat('y', 1000));
// A request chooses the TYPE of what it sends: an array must be refused, not crash.
$sent = reasonEdit($jar['admin'], $reasonId, ['status' => 'postponed', 'cancelReason' => ['x']]);
ok('U15 a reason posted as an ARRAY is refused politely and nothing is changed',
    $sent['status'] === 302 && (string) (reasonRow($reasonId)['status'] ?? '') === 'cancelled'
    && str_contains(messageAfter($jar['admin'], $sent), 'could not be read'), 'HTTP ' . $sent['status']);

// A NEW event: published with a typed reason keeps none and says so; a new
// CANCELLED repeating series carries the reason on every date it makes.
$made = createEvent($jar['admin'], 'EVREP Reason new published', '2031-06-01T10:00', '2031-06-01T11:00', ['cancelReason' => 'EVREP nobody reads this']);
$newPub = eventsNamed('EVREP Reason new published')[0] ?? [];
ok('U16 a NEW published event with a reason typed keeps no reason, and the message says so',
    isset($newPub['eventID']) === true && reasonRow((int) $newPub['eventID'])['cancelReason'] === null
    && str_contains($made['message'], $notKept), $made['message']);
createEvent($jar['admin'], 'EVREP Reason new series', '2031-07-06T10:00', '2031-07-06T11:00', [
    'status'          => 'cancelled',
    'cancelReason'    => 'EVREP the building is closed',
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
$newSeriesRows = q(
    "SELECT status, cancelReason FROM tblEvents WHERE siteID = ? AND eventName = 'EVREP Reason new series'",
    'i',
    [ORG]
);
ok('U17 a NEW cancelled series: all three dates are cancelled WITH the reason (read back)',
    count($newSeriesRows) === 3 && count(array_filter($newSeriesRows, static fn (array $r): bool
        => (string) $r['status'] === 'cancelled' && (string) $r['cancelReason'] === 'EVREP the building is closed')) === 3,
    json_encode($newSeriesRows));

// A reason of "0" is a real reason. The event page tested it with `empty()`,
// which treats the text "0" as empty, so it was stored and never shown
// (round 2 of #998's check).
createEvent($jar['admin'], 'EVREP Reason zero', '2031-06-08T10:00', '2031-06-08T11:00', ['isPublic' => '1']);
$zeroId = (int) (eventsNamed('EVREP Reason zero')[0]['eventID'] ?? 0);
reasonEdit($jar['admin'], $zeroId, ['status' => 'cancelled', 'cancelReason' => '0']);
ok('U17b a reason of "0" is stored (read back)', (string) (reasonRow($zeroId)['cancelReason'] ?? '') === '0', json_encode(reasonRow($zeroId)));
ok('U17c ...and a member SEES it under the cancelled banner',
    preg_match('/This event has been cancelled<\/h2>\s*<p class="mb-1">0<\/p>/', memberEventPage($jar['member'], $zeroId)) === 1);

// -----------------------------------------------------------------------------
// U18-U25 — the series bulk update.
// -----------------------------------------------------------------------------
createEvent($jar['admin'], 'EVREP Reason bulk', '2031-09-07T10:00', '2031-09-07T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
    'isPublic'        => '1',
]);
$bulkSeries = repatSeries('EVREP Reason bulk');
$bulkIds    = array_map(static fn (array $r): int => (int) $r['eventID'], eventsNamed('EVREP Reason bulk'));
$bulkPost   = static function (array $fields) use ($jar, $bulkSeries): array {
    $sent = req($jar['admin'], 'POST', '/calendar/manage/series-edit', array_merge([
        'csrf_token' => managePage($jar['admin'])['token'],
        'seriesID'   => (string) $bulkSeries,
    ], $fields));

    return ['sent' => $sent, 'message' => messageAfter($jar['admin'], $sent)];
};
ok('U18 (premise) a three-date series to bulk-edit, none with a reason', count($bulkIds) === 3 && $bulkSeries > 0);
$bulkPage = restPage($jar['admin'], $bulkSeries)['body'];
ok('U19 the bulk-edit page offers the reason box', preg_match('/<textarea[^>]*name="bulkCancelReason"/', $bulkPage) === 1);

$did = $bulkPost(['eventIDs' => [(string) $bulkIds[1], (string) $bulkIds[2]], 'bulkStatus' => 'cancelled', 'bulkCancelReason' => 'EVREP the heating has failed']);
ok('U20 BULK CANCELLED WITH A REASON: both chosen dates carry it, the third does not (read back)',
    (string) reasonRow($bulkIds[1])['cancelReason'] === 'EVREP the heating has failed'
    && (string) reasonRow($bulkIds[2])['cancelReason'] === 'EVREP the heating has failed'
    && (string) reasonRow($bulkIds[2])['status'] === 'cancelled'
    && reasonRow($bulkIds[0])['cancelReason'] === null && (string) reasonRow($bulkIds[0])['status'] === 'published',
    json_encode([reasonRow($bulkIds[0]), reasonRow($bulkIds[1]), reasonRow($bulkIds[2])]) . ' ' . $did['message']);
ok('U21 a member sees the bulk reason on one of those dates\' pages',
    str_contains(text(memberEventPage($jar['member'], $bulkIds[1])), 'EVREP the heating has failed'));

$bulkPost(['eventIDs' => [(string) $bulkIds[1]], 'bulkStatus' => 'postponed']);
ok('U22 bulk POSTPONED with the box empty: the date keeps its own reason (read back)',
    (string) reasonRow($bulkIds[1])['status'] === 'postponed'
    && (string) reasonRow($bulkIds[1])['cancelReason'] === 'EVREP the heating has failed', json_encode(reasonRow($bulkIds[1])));

$did = $bulkPost(['eventIDs' => [(string) $bulkIds[0]], 'bulkCancelReason' => 'EVREP stray words']);
ok('U23 a reason with Status left at "No change" is REFUSED, nothing changed (read back)',
    reasonRow($bulkIds[0])['cancelReason'] === null && (string) reasonRow($bulkIds[0])['status'] === 'published'
    && str_contains($did['message'], 'A reason is kept only for Cancelled or Postponed dates'), $did['message']);
$did = $bulkPost(['eventIDs' => [(string) $bulkIds[2]], 'bulkStatus' => 'published', 'bulkCancelReason' => 'EVREP stray words']);
ok('U24 a reason with Status set to Published is REFUSED, and the date stays cancelled with its reason (read back)',
    (string) reasonRow($bulkIds[2])['status'] === 'cancelled'
    && (string) reasonRow($bulkIds[2])['cancelReason'] === 'EVREP the heating has failed', $did['message']);

// A number posted by hand for ANOTHER series' date (U17's, cancelled with a
// reason): its status is not changed (the UPDATE says this series), and its
// reason must not be either.
$otherSeriesDate = (int) (q("SELECT eventID FROM tblEvents WHERE siteID = ? AND eventName = 'EVREP Reason new series' ORDER BY eventID LIMIT 1", 'i', [ORG])[0]['eventID'] ?? 0);
$bulkPost(['eventIDs' => [(string) $bulkIds[2], (string) $otherSeriesDate], 'bulkStatus' => 'cancelled', 'bulkCancelReason' => 'EVREP replaced']);
ok('U25 a forged number from ANOTHER series keeps its own reason; this series\' date takes the new one (read back)',
    (string) reasonRow($otherSeriesDate)['cancelReason'] === 'EVREP the building is closed'
    && (string) reasonRow($bulkIds[2])['cancelReason'] === 'EVREP replaced',
    json_encode([reasonRow($otherSeriesDate), reasonRow($bulkIds[2])]));

$did = $bulkPost(['eventIDs' => [(string) $bulkIds[1], (string) $bulkIds[2]], 'bulkStatus' => 'published']);
ok('U26 BULK PUBLISHED: both dates lose their reasons (read back)',
    reasonRow($bulkIds[1])['cancelReason'] === null && reasonRow($bulkIds[2])['cancelReason'] === null
    && (string) reasonRow($bulkIds[1])['status'] === 'published', json_encode([reasonRow($bulkIds[1]), reasonRow($bulkIds[2])]));
ok('U27 ...and the message counts them, word for word',
    str_contains($did['message'], '2 dates lost the reasons they had for being cancelled or postponed, because a reason is kept only while a date is cancelled or postponed.'),
    $did['message']);

// -----------------------------------------------------------------------------
// U28 — the events API: a status that carries no reason empties it.
// -----------------------------------------------------------------------------
reasonEdit($jar['admin'], $reasonId, ['status' => 'cancelled', 'cancelReason' => 'EVREP via the form']);
$apiToken = managePage($jar['admin'])['token'];
$apiHandle = curl_init('http://127.0.0.1:' . $port . ADDR . '/api/events/update?id=' . $reasonId);
curl_setopt_array($apiHandle, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
    CURLOPT_COOKIEJAR => $jar['admin'], CURLOPT_COOKIEFILE => $jar['admin'], CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['Expect:', 'Content-Type: application/json', 'X-CSRF-Token: ' . $apiToken],
    CURLOPT_POSTFIELDS => (string) json_encode(['eventID' => $reasonId, 'eventName' => 'EVREP Reason one']),
]);
$apiNameOnly = (string) curl_exec($apiHandle);
$apiNameCode = (int) curl_getinfo($apiHandle, CURLINFO_HTTP_CODE);
ok('U28 the API changing only the NAME leaves the reason alone (read back)',
    $apiNameCode === 200 && (string) reasonRow($reasonId)['cancelReason'] === 'EVREP via the form',
    'HTTP ' . $apiNameCode . ' ' . mb_substr($apiNameOnly, 0, 300));
$apiToken = managePage($jar['admin'])['token'];
curl_setopt($apiHandle, CURLOPT_HTTPHEADER, ['Expect:', 'Content-Type: application/json', 'X-CSRF-Token: ' . $apiToken]);
curl_setopt($apiHandle, CURLOPT_POSTFIELDS, (string) json_encode(['eventID' => $reasonId, 'status' => 'published']));
$apiAnswer = (string) curl_exec($apiHandle);
$apiCode   = (int) curl_getinfo($apiHandle, CURLINFO_HTTP_CODE);
ok('U29 the API setting status to published EMPTIES the reason (read back)',
    $apiCode === 200 && (string) reasonRow($reasonId)['status'] === 'published' && reasonRow($reasonId)['cancelReason'] === null,
    'HTTP ' . $apiCode . ' ' . mb_substr($apiAnswer, 0, 300) . ' ' . json_encode(reasonRow($reasonId)));

// -----------------------------------------------------------------------------
// U30-U40 — a hidden date cannot be edited (#1000).
// -----------------------------------------------------------------------------
// Hidden through the REAL Delete button, which is one of the three ways a date
// is hidden (D-143).
$hideWithButton = static function (int $eventId) use ($jar): void {
    req($jar['admin'], 'POST', '/calendar/manage/delete', ['csrf_token' => managePage($jar['admin'])['token'], 'eventID' => (string) $eventId]);
};
$hiddenSeriesDate = $bulkIds[2];
$bulkPost(['eventIDs' => [(string) $hiddenSeriesDate], 'bulkStatus' => 'cancelled', 'bulkCancelReason' => 'EVREP hidden reason']);
$hideWithButton($hiddenSeriesDate);
$hiddenBefore = reasonRow($hiddenSeriesDate);
ok('U30 (premise) a cancelled date with a reason, hidden by the Delete button',
    (int) ($hiddenBefore['isDeleted'] ?? 0) === 1 && (string) ($hiddenBefore['status'] ?? '') === 'cancelled'
    && (string) ($hiddenBefore['cancelReason'] ?? '') === 'EVREP hidden reason', json_encode($hiddenBefore));

$hiddenPage = req($jar['admin'], 'GET', '/calendar/manage?edit=' . $hiddenSeriesDate);
ok('U31 OPENING ITS EDIT ADDRESS draws NO edit form for it',
    $hiddenPage['status'] === 200 && str_contains($hiddenPage['body'], 'name="action" value="update"') === false
    && str_contains($hiddenPage['body'], 'name="eventID" value="' . $hiddenSeriesDate . '"') === false,
    'HTTP ' . $hiddenPage['status']);
ok('U32 ...and says why, word for word, with a link to its series\' bulk-edit page',
    str_contains(text($hiddenPage['body']), 'This date is hidden, so it cannot be edited. Bring it back first, from the "Hidden dates" card at the foot of its series\' bulk-edit page, and then edit it.')
    && str_contains($hiddenPage['body'], 'calendar/manage/series-edit?seriesID=' . $bulkSeries . '"'),
    mb_substr(text($hiddenPage['body']), 0, 600));

$hiddenSave = reasonEdit($jar['admin'], $hiddenSeriesDate, ['status' => 'published', 'eventName' => 'EVREP Edited while hidden', 'cancelReason' => '']);
$hiddenAfter = reasonRow($hiddenSeriesDate);
ok('U33 POSTING THE FORM ANYWAY CHANGES NOTHING: still hidden, still cancelled, same name and reason (read back)',
    $hiddenAfter === $hiddenBefore, json_encode($hiddenAfter));
ok('U34 ...it answers on the series\' bulk-edit page, where the Hidden dates card is, saying nothing was saved',
    str_contains($hiddenSave['location'], '/calendar/manage/series-edit?seriesID=' . $bulkSeries)
    && messageAfter($jar['admin'], $hiddenSave) === 'This date is hidden, so it cannot be edited. Bring it back first, from the "Hidden dates" card at the foot of its series\' bulk-edit page, and then edit it. Nothing was saved.',
    $hiddenSave['location']);

// A hidden ONE-OFF event has no series, so no card: the words say so.
createEvent($jar['admin'], 'EVREP Reason hidden one-off', '2031-10-05T10:00', '2031-10-05T11:00');
$oneOff = (int) (eventsNamed('EVREP Reason hidden one-off')[0]['eventID'] ?? 0);
$hideWithButton($oneOff);
$oneOffBefore = reasonRow($oneOff);
$oneOffPage   = req($jar['admin'], 'GET', '/calendar/manage?edit=' . $oneOff)['body'];
ok('U35 a hidden ONE-OFF event: no edit form, and words that do not send anybody to a card that is not there',
    (int) ($oneOffBefore['isDeleted'] ?? 0) === 1 && str_contains($oneOffPage, 'name="action" value="update"') === false
    && str_contains(text($oneOffPage), 'This event is hidden, so it cannot be edited. It is not part of a series, so it has no "Hidden dates" card')
    && str_contains($oneOffPage, 'series-edit?seriesID=') === false);
reasonEdit($jar['admin'], $oneOff, ['eventName' => 'EVREP Edited while hidden']);
ok('U36 ...and posting its form changes nothing (read back)', reasonRow($oneOff) === $oneOffBefore, json_encode(reasonRow($oneOff)));

// -----------------------------------------------------------------------------
// U36b-U36d — WHY THE FIRST ASK EXISTS AT ALL. The second ask, under the lock,
// already refuses a hidden date, so the first one changes no row and no
// message: deleting it leaves U33-U36 green (measured). What it does change is
// that it comes BEFORE the picture uploads are stored, so a refused save
// leaves no file behind. That is only visible by posting a picture.
// -----------------------------------------------------------------------------
$uploadsDir = $child . $sep . 'web' . $sep . '_uploads' . $sep . 'calendar';
$pictureCount = static function () use ($uploadsDir): int {
    return count(glob($uploadsDir . DIRECTORY_SEPARATOR . '*') ?: []);
};
$picture = $child . $sep . 'evrep-picture.png';
// A real 1 x 1 PNG, so the handler's own type check accepts it.
file_put_contents($picture, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$postWithPicture = static function (string $jarFile, array $fields) use ($port, $picture): int {
    $handle = curl_init('http://127.0.0.1:' . $port . ADDR . '/calendar/manage/save');
    $fields['heroImage'] = new CURLFile($picture, 'image/png', 'evrep-picture.png');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jarFile, CURLOPT_COOKIEFILE => $jarFile, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Expect:'], CURLOPT_POSTFIELDS => $fields,
    ]);
    curl_exec($handle);

    return (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
};
$livePost = editPostFor((array) one(
    'SELECT eventID, eventName, startDateTime, endDateTime, timezone, status, locationName, seriesID, isPublic, cancelReason FROM tblEvents WHERE eventID = ?',
    'i',
    [$reasonId]
), managePage($jar['admin'])['token']);
$beforeLive = $pictureCount();
$postWithPicture($jar['admin'], $livePost);
ok('U36b (premise) a picture posted with a LIVE event\'s form IS stored, so the check below can see one',
    $pictureCount() === $beforeLive + 1, $beforeLive . ' then ' . $pictureCount());
$hiddenPost = editPostFor((array) one(
    'SELECT eventID, eventName, startDateTime, endDateTime, timezone, status, locationName, seriesID, isPublic, cancelReason FROM tblEvents WHERE eventID = ?',
    'i',
    [$oneOff]
), managePage($jar['admin'])['token']);
$beforeHidden = $pictureCount();
$postWithPicture($jar['admin'], $hiddenPost);
ok('U36c a picture posted with a HIDDEN event\'s form is NOT stored: the refusal comes before the upload',
    $pictureCount() === $beforeHidden, $beforeHidden . ' then ' . $pictureCount());
ok('U36d ...and the hidden event is still unchanged (read back)', reasonRow($oneOff) === $oneOffBefore, json_encode(reasonRow($oneOff)));

// ANOTHER ORGANISATION's event: the ordinary "not found" — no hidden-date
// words, which would confirm it exists — and nothing changes.
q(
    "INSERT INTO tblEvents (siteID, eventName, eventSlug, startDateTime, endDateTime, timezone, status, isDeleted) "
    . "VALUES (?, 'EVREP Other org event', 'evrep-other-org-event', '2031-11-02 10:00:00', '2031-11-02 11:00:00', ?, 'cancelled', 1)",
    'is',
    [OTHER, ZONE]
);
$otherEvent  = (int) (one("SELECT eventID FROM tblEvents WHERE siteID = ? AND eventSlug = 'evrep-other-org-event'", 'i', [OTHER])['eventID'] ?? 0);
$otherBefore = reasonRow($otherEvent);
$otherPage   = req($jar['admin'], 'GET', '/calendar/manage?edit=' . $otherEvent)['body'];
$nonePage    = req($jar['admin'], 'GET', '/calendar/manage?edit=99999999')['body'];
ok('U37 ANOTHER ORGANISATION\'S hidden event: no form and NO hidden-date words — the same as a number that does not exist',
    str_contains($otherPage, 'name="action" value="update"') === false && str_contains($otherPage, 'data-hidden-date-refusal') === false
    && str_contains($nonePage, 'data-hidden-date-refusal') === false && str_contains($otherPage, 'EVREP Other org event') === false);
$otherSave = req($jar['admin'], 'POST', '/calendar/manage/save', array_merge(
    editPostFor(['eventID' => $otherEvent, 'eventName' => 'EVREP Taken over'], managePage($jar['admin'])['token']),
    ['status' => 'published']
));
ok('U38 ...posting to it changes nothing (read back), and says only that it could not be found',
    reasonRow($otherEvent) === $otherBefore
    && messageAfter($jar['admin'], $otherSave) === 'That event could not be found here, so nothing was saved.',
    json_encode(reasonRow($otherEvent)));

// -----------------------------------------------------------------------------
// U36e-U36h — THE BULK UPDATE AND A HIDDEN DATE, AND WHAT ITS MESSAGE COUNTS
// (round 2 of #998's check). Two dates are cancelled with a reason; the second
// is then hidden. A hand-made bulk post naming BOTH, Cancelled with a new
// reason, must change only the live one: the writer's own `isDeleted = 0`
// was pinned by nothing (removing it left every check green and overwrote the
// hidden date's reason, which is what it comes back with — D-146). And the
// live date was ALREADY cancelled, so the status UPDATE changes no column of
// it: the message must count the date that matched and the date that took the
// reason, not the UPDATE's changed rows ("0 event(s) updated" was measured).
// -----------------------------------------------------------------------------
createEvent($jar['admin'], 'EVREP Reason bulk hidden', '2032-01-04T10:00', '2032-01-04T11:00', [
    'repeatFrequency' => 'weekly',
    'repeatCount'     => '3',
]);
$bhSeries = repatSeries('EVREP Reason bulk hidden');
$bhIds    = array_map(static fn (array $r): int => (int) $r['eventID'], eventsNamed('EVREP Reason bulk hidden'));
$bhPost   = static function (array $fields) use ($jar, $bhSeries): string {
    $sent = req($jar['admin'], 'POST', '/calendar/manage/series-edit', array_merge([
        'csrf_token' => managePage($jar['admin'])['token'],
        'seriesID'   => (string) $bhSeries,
    ], $fields));

    return messageAfter($jar['admin'], $sent);
};
$bhPost(['eventIDs' => [(string) $bhIds[0], (string) $bhIds[1]], 'bulkStatus' => 'cancelled', 'bulkCancelReason' => 'EVREP first reason']);
$hideWithButton($bhIds[1]);
ok('U36e (premise) two dates cancelled with a reason, the second then hidden',
    (string) reasonRow($bhIds[0])['cancelReason'] === 'EVREP first reason' && (int) reasonRow($bhIds[1])['isDeleted'] === 1
    && (string) reasonRow($bhIds[1])['cancelReason'] === 'EVREP first reason',
    json_encode([reasonRow($bhIds[0]), reasonRow($bhIds[1])]));
$bhMessage = $bhPost(['eventIDs' => [(string) $bhIds[0], (string) $bhIds[1]], 'bulkStatus' => 'cancelled', 'bulkCancelReason' => 'EVREP second reason']);
ok('U36f THE HIDDEN DATE KEEPS ITS OWN REASON when its number is posted with a new one (read back)',
    (int) reasonRow($bhIds[1])['isDeleted'] === 1 && (string) reasonRow($bhIds[1])['cancelReason'] === 'EVREP first reason',
    json_encode(reasonRow($bhIds[1])));
ok('U36g ...while the live date, already cancelled, takes the new reason (read back)',
    (string) reasonRow($bhIds[0])['cancelReason'] === 'EVREP second reason', json_encode(reasonRow($bhIds[0])));
ok('U36h ...and the message counts ONE date updated and ONE carrying the reason, word for word',
    $bhMessage === '1 event(s) updated successfully. 1 date now carries the reason you gave.', $bhMessage);

// -----------------------------------------------------------------------------
// U39 — THE SECOND ASK, UNDER THE LOCK. The first refusal reads without a
// lock; between it and the write the date can be hidden. This connection
// holds the row's lock while the real save runs (its first read is a plain
// read, so it passes), hides the date and commits; the save's locking read
// then sees it hidden and must refuse. One worker is enough: the two events
// that must be ordered are this connection's commit and the page's locked
// read, not two requests (the section T precedent).
// -----------------------------------------------------------------------------
createEvent($jar['admin'], 'EVREP Reason race', '2031-12-07T10:00', '2031-12-07T11:00', ['repeatFrequency' => 'weekly', 'repeatCount' => '2']);
$raceId     = (int) (eventsNamed('EVREP Reason race')[1]['eventID'] ?? 0);
$raceSeries = repatSeries('EVREP Reason race');
$raceRow    = (array) one(
    'SELECT eventID, eventName, startDateTime, endDateTime, timezone, status, locationName, seriesID, isPublic, cancelReason FROM tblEvents WHERE eventID = ?',
    'i',
    [$raceId]
);
$racePost   = array_merge(editPostFor($raceRow, managePage($jar['admin'])['token']), ['eventName' => 'EVREP Race renamed', 'status' => 'cancelled', 'cancelReason' => 'EVREP race reason']);
// The post carries a PICTURE (round 2 of #998's check): the first ask passes
// (the date is live), so the picture IS stored before the save waits on the
// lock, and the refusal under the lock must delete it again.
$picturesBeforeRace = $pictureCount();
$racePost['heroImage'] = new CURLFile($picture, 'image/png', 'evrep-picture.png');
$raceHandle = curl_init('http://127.0.0.1:' . $port . ADDR . '/calendar/manage/save');
curl_setopt_array($raceHandle, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 120, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:'],
    CURLOPT_COOKIEJAR => $jar['admin'], CURLOPT_COOKIEFILE => $jar['admin'], CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $racePost,
]);
$db->begin_transaction();
q('SELECT eventID FROM tblEvents WHERE eventID = ? FOR UPDATE', 'i', [$raceId]);
$raceMulti = curl_multi_init();
curl_multi_add_handle($raceMulti, $raceHandle);
$raceRunning = 1;
$raceUntil   = microtime(true) + 2.0;
while (microtime(true) < $raceUntil && $raceRunning > 0) {
    curl_multi_exec($raceMulti, $raceRunning);
    curl_multi_select($raceMulti, 0.1);
}
$raceAsync = ['multi' => $raceMulti, 'handle' => $raceHandle, 'running' => $raceRunning];
ok('U39 (premise) the save is WAITING on this connection\'s lock, so the order below is real', $raceAsync['running'] > 0);
q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID = ?', 'i', [$raceId]);
$db->commit();
restAsyncFinish($raceAsync);
$raceAfter = reasonRow($raceId);
ok('U40 HIDDEN WHILE THE SAVE WAITED: nothing was written (read back)',
    (string) ($raceAfter['eventName'] ?? '') === 'EVREP Reason race' && (string) ($raceAfter['status'] ?? '') === 'published'
    && $raceAfter['cancelReason'] === null, json_encode($raceAfter));
$raceMessage = flashOn(restPage($jar['admin'], $raceSeries)['body']);
ok('U41 ...and the answer is the hidden-date refusal, not "updated successfully"',
    $raceMessage === 'This date is hidden, so it cannot be edited. Bring it back first, from the "Hidden dates" card at the foot of its series\' bulk-edit page, and then edit it. Nothing was saved.',
    $raceMessage);
ok('U41b ...and the picture that save had already stored was deleted again, not left on disk',
    $pictureCount() === $picturesBeforeRace, $picturesBeforeRace . ' then ' . $pictureCount());

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
