<?php
// Path: tools/public-door-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The public door, over real HTTP 🚪🧪 (#493 P4a; D-056, D-057, D-087, D-094,
 * D-095, D-097, D-098)
 * -----------------------------------------------------------------------------
 * Drives the REAL door — `web/public_html/index.php` → `Site::preDetect()` →
 * `Portal\Core\PublicDoor` — in a copy of the portal served by PHP's own web
 * server, and the REAL card on Admin → Public website that turns it on,
 * replaces its name and switches it off. Every result is read back from the
 * database or from the answer itself, never from a message.
 *
 * WHAT IT COVERS
 *   K  the card: the door starts OFF (D-097); turning it on, replacing its
 *      name (the old name stops at once) and switching it off, each recorded;
 *      replacing or switching off refused without the "I understand" tick;
 *      who may (the page's own gate) and what the card shows.
 *   G  the gate: door off, an unknown, replaced or removed name, a name in
 *      capitals, an inactive organisation (by name and by public address),
 *      Calendar off (events refused, posters served) and Noticeboard off (the
 *      reverse, media included), maintenance (503), a wrong method, every bad
 *      `limit`, an unknown address after the name, and the portal's own
 *      address not stored (the /w/ form refuses, a public address still works).
 *   R  every refusal byte-identical: status, every header but Date (and Host,
 *      which PHP's own test server adds and Apache does not), and the body.
 *   X  organisations never cross: each name sees only its own; a session for
 *      organisation B changes nothing when asking A's name; A's public address
 *      with B's name in the path; B's name on A's members' address in "own
 *      address each" mode; the other organisation's media; multi-organisation
 *      switched off.
 *   C  content: nothing unticked, outside its dates, deleted, draft,
 *      cancelled, members-only, finished or another organisation's; a
 *      copied-in event under each rule (and its details withheld when its
 *      calendar says so); an event on its own clock; no database number,
 *      creator, telephone, e-mail, map position or what3words anywhere in
 *      the answer; posters with their media pointed at the door, an outside
 *      https picture passed through, a plain-http link dropped, another
 *      organisation's upload never named; media served only while its poster
 *      is on the public website, and stopping the moment it is unticked.
 *   H  headers on the live portal: CORS, CORP, the content rule
 *      (frame-ancestors 'none' on data even when the organisation lists
 *      websites that may frame its page), Referrer-Policy, caching,
 *      X-Robots-Tag, no X-Frame-Options or COOP left over from start-up, NO
 *      Set-Cookie, and no session file made by any door request.
 *   S  every captured feed answer satisfies its schema
 *      (`tools/audit-checks/check_public_door_schema.py --answers`).
 *   E  error.php and the API description, which start the portal WITHOUT
 *      index.php: on a public address both give the door's 404 (D-098).
 *   T  a test copy (PORTAL_ENV=alpha, D-087): no sign-in, a member who may
 *      not use the test copy, a made-up cookie, a tester whose session has
 *      outlived PHP's session lifetime — all refused; a tester
 *      served with `private, no-store` and `Vary: Cookie`; every answer
 *      `noindex, nofollow, noarchive`; "open to anybody" only when BOTH the
 *      installation's limit and the organisation's choice say so (written
 *      straight into the database: the general settings editor refuses both,
 *      PublicWebsite::LOCKED_KEYS, and the card is P4d); no
 *      session file made, even for a made-up cookie.
 *   P  the plants: each gate and each header taken out of a COPY of the
 *      portal, and the scenario that should catch it re-run. The expected
 *      value of a plant check is the value UNDER the fault, as
 *      tools/public-website-tick-selftest.php's P-checks do, and every plant
 *      must find its text exactly once or it fails loudly.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - frame-ancestors from `PublicWebsiteSetup::frameAncestorsFor()` belongs
 *     to the framed PAGE, which is package P4c. Here it pins only that data
 *     answers say `frame-ancestors 'none'` whatever the organisation lists.
 *   - No real outside website, real browser, DNS name or TLS certificate is
 *     involved: a public address is imitated by sending its name in the Host
 *     header to the test server. What browsers do with CORS, CORP and the
 *     sign-in cookie is not tested.
 *   - One web worker, so two administrators pressing the card at once is not
 *     exercised; the writers' answers to that race are read, not run.
 *   - Caches in between, and the minute `max-age=60` allows, are not tested.
 *   - With PHP's "files" session store only: the guarantee that a made-up
 *     cookie makes no session file is the door's own guard, which cannot see
 *     any other store.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         [SELFTEST_WEB_PORT=9561] php tools/public-door-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql` (with
 *         migration 263). Needs PHP's curl extension, `proc_open()` and
 *         python3. It REFUSES rather than skipping when anything is missing.
 *
 * Exit:   0 only when every check ran and passed AND the number of checks is
 *         the number expected; 1 otherwise; 2 when it refused to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/493
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const PDS_EXPECTED_CHECKS = 126;

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'restore' => []];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

function check(string $name, mixed $got, mixed $want): void
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
$python = trim((string) shell_exec('command -v python3'));
if ($python === '') {
    refuse('this test needs python3 to run the schema check over the answers it captures');
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$hasTable = static fn (string $t): bool => (int) ($db->query(
    "SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = '" . $db->real_escape_string($t) . "'"
)->fetch_assoc()['n'] ?? 0) === 1;
if ($hasTable('tblPublicDoorNames') === false || $hasTable('tblPublicHosts') === false) {
    refuse('this database was not built from the current install script (migration 263 is missing)');
}
if ((int) ($db->query('SELECT MIN(`siteID`) AS `s` FROM `tblSites`')->fetch_assoc()['s'] ?? 0) !== 1) {
    refuse('organisation 1 must exist: the door is tested against the default organisation');
}

// ── Settings this test changes, put back at the end whatever happens ────────
$installationRow = static function (string $key) use ($db): ?string {
    $s = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
    $s->bind_param('s', $key);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return $r === null ? null : (string) $r['settingValue'];
};
// An existing row is UPDATED rather than replaced, so its seeded defaultValue
// survives the run; null deletes it (and putting back an absent row deletes).
$setInstallation = static function (string $key, ?string $value) use ($db): void {
    if ($value === null) {
        $d = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
        $d->bind_param('s', $key);
        $d->execute();
        $d->close();
        return;
    }
    $u = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
    $u->bind_param('ss', $value, $key);
    $u->execute();
    $u->close();
    $exists = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
    $exists->bind_param('s', $key);
    $exists->execute();
    $n = (int) ($exists->get_result()->fetch_assoc()['n'] ?? 0);
    $exists->close();
    if ($n === 0) {
        $i = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, '', 0)");
        $i->bind_param('ss', $key, $value);
        $i->execute();
        $i->close();
    }
};
$setOwn = static function (int $site, string $key, ?string $value) use ($db): void {
    $d = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
    $d->bind_param('si', $key, $site);
    $d->execute();
    $d->close();
    if ($value !== null) {
        $i = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, '', 0)");
        $i->bind_param('iss', $site, $key, $value);
        $i->execute();
        $i->close();
    }
};
$touched = ['multisite.enabled', 'multisite.detectionMode', 'site.url', 'public.door.testCopies.permitted',
    'public.events.copiedRule', 'portal.maintenance.active', 'public.frame.permitted', 'calendar.enabled', 'noticeboard.enabled'];
foreach ($touched as $key) {
    $state['restore'][$key] = $installationRow($key);
}

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'pds-http-' . bin2hex(random_bytes(6));
$state['child'] = $child;
$cleanUp = static function () use (&$state, $setInstallation, $db): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    foreach ($state['restore'] as $key => $value) {
        try {
            $setInstallation($key, $value);
        } catch (Throwable $e) {
            echo "  ! could not put the setting {$key} back; set it by hand.\n";
        }
    }
    $state['restore'] = [];
    if ($state['child'] !== null && is_dir($state['child']) === true) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($state['child'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($state['child']);
        $state['child'] = null;
    }
};
register_shutdown_function($cleanUp);
mkdir($child, 0700, true);
$copied = 0;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $to = $child . $sep . 'web' . substr($entry->getPathname(), strlen($repo . $sep . 'web'));
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
@mkdir($child . $sep . 'web' . $sep . '_uploads' . $sep . 'noticeboard', 0700, true);
$sessions = $child . $sep . 'sessions';
mkdir($sessions, 0700);
$answers = $child . $sep . 'answers';
mkdir($answers, 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/public-door-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/public-door-selftest.php for ONE run. Test only.
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
ROUTER);

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9560 + random_int(0, 30);
$base = 'http://127.0.0.1:' . $port;
$channel = 'prod';

$startServer = static function () use (&$state, &$channel, $child, $sep, $port, $sessions): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
        usleep(400000);
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = $channel;
    $state['proc'] = proc_open(
        [
            PHP_BINARY,
            '-d', 'session.save_path=' . $sessions,
            '-d', 'session.save_handler=files',
            '-d', 'error_log=' . $child . $sep . 'php-errors.log',
            '-d', 'log_errors=1', '-d', 'display_errors=0',
            '-d', 'max_execution_time=120',
            '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
            '-S', '127.0.0.1:' . $port,
            '-t', $child . $sep . 'web' . $sep . 'public_html',
            $child . $sep . 'router.php',
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
        $pipes,
        $child,
        $env
    );
    if (is_resource($state['proc']) === false) {
        refuse('could not start PHP\'s web server');
    }
};

/**
 * One request. Options: host (the Host header: a public address is imitated
 * this way), jar (a cookie file to send and keep), cookie (a raw Cookie
 * header), post (an array), head (true for HEAD). Redirects are NOT followed.
 *
 * @return array{status:int, headers:array<string,string>, lines:list<string>, body:string}
 */
$http = static function (string $method, string $path, array $opt = []) use ($port): array {
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = [];
    if (isset($opt['host']) === true) {
        $headers[] = 'Host: ' . $opt['host'];
    }
    if (isset($opt['cookie']) === true) {
        $headers[] = 'Cookie: ' . $opt['cookie'];
    }
    $curl = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method,
    ];
    if (isset($opt['jar']) === true) {
        $curl[CURLOPT_COOKIEJAR]  = $opt['jar'];
        $curl[CURLOPT_COOKIEFILE] = $opt['jar'];
    }
    if ($method === 'HEAD') {
        $curl[CURLOPT_NOBODY] = true;
    }
    if (isset($opt['post']) === true) {
        $curl[CURLOPT_POSTFIELDS] = http_build_query($opt['post']);
    }
    curl_setopt_array($handle, $curl);
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'headers' => [], 'lines' => [], 'body' => 'no answer: ' . curl_error($handle)];
    }
    $size  = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head  = substr((string) $raw, 0, $size);
    $lines = [];
    $named = [];
    foreach (preg_split('/\r?\n/', trim($head)) ?: [] as $i => $line) {
        if ($i === 0 || $line === '') {
            continue;
        }
        $lines[] = $line;
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $key = strtolower(trim($name));
        $named[$key] = isset($named[$key]) === true ? $named[$key] . "\n" . trim($value) : trim($value);
    }

    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'headers' => $named, 'lines' => $lines,
        'body' => substr((string) $raw, $size)];
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

/** A refusal's whole answer as one text: status, every header but Date and Host, and the body. */
$signature = static function (array $r): string {
    $keep = array_values(array_filter($r['lines'], static fn (string $l): bool
        => preg_match('/^(date|host):/i', $l) !== 1));
    sort($keep);
    return $r['status'] . "\n" . implode("\n", $keep) . "\n\n" . $r['body'];
};

/** How many session files exist. */
$sessionFiles = static function () use ($sessions): int {
    return count(glob($sessions . DIRECTORY_SEPARATOR . 'sess_*') ?: []);
};

// ── The organisations ───────────────────────────────────────────────────────
// A = organisation 1 (UTC, whatever its zone is). B = a second one on a clock
// far from UTC. C = an INACTIVE one with a door and a public address, so
// "inactive" is the only thing standing in its way.
foreach (['selftest-door-b', 'selftest-door-c'] as $key) {
    $old = $db->query("SELECT `siteID` FROM `tblSites` WHERE `siteKey` = '{$key}'")->fetch_assoc();
    if ($old !== null) {
        $sid = (int) $old['siteID'];
        foreach (['tblNoticeboardUploads', 'tblNoticeboardPosters', 'tblEvents', 'tblSettings', 'tblUserSites', 'tblRoles'] as $t) {
            $db->query("DELETE FROM `{$t}` WHERE `siteID` = {$sid}");
        }
        $db->query("DELETE FROM `tblSites` WHERE `siteID` = {$sid}");
    }
}
$db->query("INSERT INTO `tblSites` (`siteKey`, `siteName`, `timezone`, `isActive`) VALUES ('selftest-door-b', 'Selftest Door B', 'Pacific/Auckland', 1)");
$siteB = (int) $db->insert_id;
$db->query("INSERT INTO `tblSites` (`siteKey`, `siteName`, `timezone`, `isActive`) VALUES ('selftest-door-c', 'Selftest Door C', 'UTC', 0)");
$siteC = (int) $db->insert_id;
$siteA = 1;
$zoneA = (string) ($db->query('SELECT `timezone` FROM `tblSites` WHERE `siteID` = 1')->fetch_assoc()['timezone'] ?? 'UTC');
try {
    $clockA = new DateTimeZone($zoneA);
} catch (Throwable $e) {
    $clockA = new DateTimeZone('UTC');
}
$clockB  = new DateTimeZone('Pacific/Auckland');
$nowA    = new DateTimeImmutable('now', $clockA);
$todayA  = $nowA->format('Y-m-d');
$yesterA = $nowA->modify('-1 day')->format('Y-m-d');
$tomorA  = $nowA->modify('+1 day')->format('Y-m-d');

$db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` IN (1, {$siteB}, {$siteC})");
$db->query("DELETE FROM `tblPublicHosts` WHERE `siteID` IN (1, {$siteB}, {$siteC}) OR `hostName` LIKE '%.selftest.test'");
$db->query("UPDATE `tblSites` SET `hostPattern` = NULL WHERE `siteID` = 1");
foreach (['calendar.enabled', 'noticeboard.enabled', 'public.door.testCopies.open', 'public.frame.origins'] as $k) {
    $db->query("DELETE FROM `tblSettings` WHERE `siteID` IN (1, {$siteB}, {$siteC}) AND `settingKey` = '{$k}'");
}
$setInstallation('multisite.enabled', 'true');
$setInstallation('multisite.detectionMode', 'session');
$setInstallation('site.url', $base);
$setInstallation('public.door.testCopies.permitted', 'testers');
$setInstallation('public.events.copiedRule', 'event');
$setInstallation('portal.maintenance.active', '0');
$setInstallation('public.frame.permitted', 'yes');
// Both apps on for everybody, installation-wide (each test that switches one off does it per organisation).
$setInstallation('calendar.enabled', $installationRow('calendar.enabled') ?? 'true');
$setInstallation('noticeboard.enabled', $installationRow('noticeboard.enabled') ?? 'true');

// ── The people ──────────────────────────────────────────────────────────────
//    root     A's OWN global administrator (with the portal-wide Admin flag, so
//             the test-copy gate lets them in): drives the card. NOT the
//             installation's global administrator, who belongs to every
//             organisation and is sent to the organisation chooser first.
//    tester   the portal-wide Admin flag — the test-copy gate lets them in
//    member   a plain member of A — the test-copy gate does not
//    memberB  a plain member of B only — for "a session for B asking A's name"
$removeMember = static function (int $who) use ($db): void {
    $rs = $db->query(
        "SELECT `TABLE_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE` WHERE `TABLE_SCHEMA` = DATABASE() "
        . "AND `REFERENCED_TABLE_NAME` = 'tblUsers' AND `COLUMN_NAME` = 'userID'"
    );
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . "` WHERE `userID` = {$who}");
        } catch (Throwable $e) {
            // A table of a different shape.
        }
    }
    try {
        $db->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
    } catch (Throwable $e) {
        echo "  ! could not remove test person {$who}; remove it by hand.\n";
    }
};
$people = [];
foreach ([
    'root'    => ['isAdmin' => 1, 'isRoot' => 0, 'site' => $siteA, 'siteRoot' => 1],
    'tester'  => ['isAdmin' => 1, 'isRoot' => 0, 'site' => $siteA],
    'member'  => ['isAdmin' => 0, 'isRoot' => 0, 'site' => $siteA],
    'memberB' => ['isAdmin' => 0, 'isRoot' => 0, 'site' => $siteB],
] as $role => $flags) {
    $email = "pds.{$role}@selftest.invalid";
    $old   = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
    $old->bind_param('s', $email);
    $old->execute();
    foreach ($old->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $removeMember((int) $row['userID']);
    }
    $old->close();
    $ins  = $db->prepare('INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, 1, ?, ?, NOW())');
    $name = 'Selftest PDS ' . $role;
    $ins->bind_param('ssii', $name, $email, $flags['isAdmin'], $flags['isRoot']);
    $ins->execute();
    $id = (int) $db->insert_id;
    $ins->close();
    $db->query("INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES ({$flags['site']}, {$id}, 1, 0, " . (int) ($flags['siteRoot'] ?? 0) . ")");
    $password = 'Pds' . bin2hex(random_bytes(8)) . '!9x';
    $login    = 'selftest-pds-' . $role;
    $db->query("DELETE FROM `tblLocalAccounts` WHERE `username` = '{$login}'");
    $acc  = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $acc->bind_param('iss', $id, $login, $hash);
    $acc->execute();
    $acc->close();
    $people[$role] = ['id' => $id, 'login' => $login, 'password' => $password, 'jar' => $child . $sep . 'jar-' . $role . '.txt'];
}
$state['people'] = array_column($people, 'id');
register_shutdown_function(static function () use (&$state, $removeMember): void {
    foreach ($state['people'] ?? [] as $who) {
        $removeMember((int) $who);
    }
});

$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
};
/** Sign a person in; their cookie file is then theirs for the rest of the run. */
$signIn = static function (string $role) use ($http, $tokenOn, $people): string {
    $jar = $people[$role]['jar'];
    @unlink($jar);
    $page  = $http('GET', '/login', ['jar' => $jar]);
    $token = $tokenOn($page['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['jar' => $jar, 'post' => ['csrf_token' => $token, 'username' => $people[$role]['login'],
        'password' => $people[$role]['password']]]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse("signing in as {$role} did not answer a redirect (HTTP {$in['status']})");
    }
    return $jar;
};
/** The session cookie a person's file holds, as a Cookie header. */
$cookieOf = static function (string $jar): string {
    foreach (file($jar) ?: [] as $line) {
        $parts = explode("\t", trim($line));
        if (count($parts) === 7 && $parts[5] === 'PHPSESSID') {
            return 'PHPSESSID=' . $parts[6];
        }
    }
    return '';
};
/** Press a button on the card as `$role`. */
$card = static function (string $role, string $action, array $more = []) use ($http, $tokenOn, $people): array {
    $jar   = $people[$role]['jar'];
    $page  = $http('GET', '/admin/public-website', ['jar' => $jar]);
    $token = $tokenOn($page['body']);
    $r     = $http('POST', '/admin/public-website/save', ['jar' => $jar, 'post' => ['csrf_token' => $token, 'action' => $action] + $more]);
    return ['status' => $r['status'], 'page' => $http('GET', '/admin/public-website', ['jar' => $jar])['body']];
};
$doorName = static function (int $site) use ($db): ?string {
    $r = $db->query("SELECT `publicName` FROM `tblPublicDoorNames` WHERE `siteID` = {$site}")->fetch_assoc();
    return $r === null ? null : (string) $r['publicName'];
};
$logged = static function (string $type, int $userId) use ($db): int {
    $s = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` = ? AND `userID` = ?');
    $s->bind_param('si', $type, $userId);
    $s->execute();
    $n = (int) ($s->get_result()->fetch_assoc()['n'] ?? 0);
    $s->close();
    return $n;
};

// ── The content ─────────────────────────────────────────────────────────────
$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'PDS %'");
$db->query("DELETE FROM `tblNoticeboardUploads` WHERE `storedName` LIKE '%' AND `siteID` IN (1, {$siteB}) AND `fileSize` = 4242");
$db->query("DELETE FROM `tblNoticeboardPosters` WHERE `title` LIKE 'PDS %'");
$db->query("DELETE FROM `tblExternalFeeds` WHERE `name` = 'PDS outside calendar'");

/** An event straight into the database: published, public, ticked, unless `$set` says otherwise. */
$makeEvent = static function (string $name, int $site, string $zone, string $start, ?string $end, array $set = []) use ($db): int {
    $slug = 'pds-' . bin2hex(random_bytes(5));
    $stmt = $db->prepare("INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `endDateTime`, `timezone`, `status`, `isPublic`, `showOnWebsite`) VALUES (?, ?, ?, ?, ?, ?, 'published', 1, 1)");
    $stmt->bind_param('isssss', $site, $name, $slug, $start, $end, $zone);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    foreach ($set as $col => $val) {
        $u = $db->prepare('UPDATE `tblEvents` SET `' . $col . '` = ? WHERE `eventID` = ?');
        $u->bind_param('si', $val, $id);
        $u->execute();
        $u->close();
    }
    return $id;
};
$setEv = static function (int $id, array $set) use ($db): void {
    foreach ($set as $col => $val) {
        $u = $db->prepare('UPDATE `tblEvents` SET `' . $col . '` = ? WHERE `eventID` = ?');
        $u->bind_param('si', $val, $id);
        $u->execute();
        $u->close();
    }
};
$at = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');

$later = $nowA->modify('+2 days')->setTime(10, 0);
$eShown = $makeEvent('PDS A shown', $siteA, $zoneA, $at($later), $at($later->modify('+2 hours')), [
    'description' => 'Bring a dish.', 'locationName' => 'PDS Hall', 'locationAddress' => '1 PDS Street',
    'locationWebURL' => 'https://hall.selftest.invalid/', 'locationPhone' => '01234 PDS PHONE',
    'locationEmail' => 'pds-hall-secret@selftest.invalid', 'locationGeoLat' => '51.1234567', 'locationGeoLng' => '-1.7654321',
    'locationW3W' => 'pds.secret.words', 'hostOrgName' => 'PDS HOST ORG', 'capacity' => '77',
]);
$eRunning   = $makeEvent('PDS A running now', $siteA, $zoneA, $at($nowA->modify('-1 hour')), $at($nowA->modify('+2 hours')),
    ['description' => '<b>"Tom & Jerry\'s"</b></script>']);
// Two all-day events with no end time, OFF the website until C18 ticks them, so
// they change no other check's list: one today (kept all of today), one
// yesterday (gone).
$eAllDayToday = $makeEvent('PDS A all day today', $siteA, $zoneA, $todayA . ' 00:00:00', null, ['isAllDay' => '1', 'showOnWebsite' => '0']);
$eAllDayPast  = $makeEvent('PDS A all day yesterday', $siteA, $zoneA, $yesterA . ' 00:00:00', null, ['isAllDay' => '1', 'showOnWebsite' => '0']);
$eUnticked  = $makeEvent('PDS A unticked', $siteA, $zoneA, $at($later), null, ['showOnWebsite' => '0']);
$eUntil     = $makeEvent('PDS A until yesterday', $siteA, $zoneA, $at($later), null, ['websiteUntil' => $yesterA]);
$eFrom      = $makeEvent('PDS A from tomorrow', $siteA, $zoneA, $at($later), null, ['websiteFrom' => $tomorA]);
$eDeleted   = $makeEvent('PDS A deleted', $siteA, $zoneA, $at($later), null, ['isDeleted' => '1']);
$eDraft     = $makeEvent('PDS A draft', $siteA, $zoneA, $at($later), null, ['status' => 'draft']);
$eCancelled = $makeEvent('PDS A cancelled', $siteA, $zoneA, $at($later), null, ['status' => 'cancelled']);
$eMembers   = $makeEvent('PDS A members only', $siteA, $zoneA, $at($later), null, ['isPublic' => '0']);
$eFinished  = $makeEvent('PDS A finished', $siteA, $zoneA, $at($nowA->modify('-1 day -3 hours')), $at($nowA->modify('-1 day -1 hour')));
$laterB = (new DateTimeImmutable('now', $clockB))->modify('+3 days')->setTime(18, 0);
$eB     = $makeEvent('PDS B shown', $siteB, 'Pacific/Auckland', $at($laterB), null);
$db->query("INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, `isPublic`, `showOnWebsite`) "
    . "VALUES ({$siteC}, 'PDS C shown', 'pds-c-" . bin2hex(random_bytes(4)) . "', '" . $at($later) . "', 'UTC', 'published', 1, 1)");
// A copied-in event: public, its calendar's website box ticked, its details
// withheld (basic detail, and marked private in its own calendar: on a public
// calendar basic detail alone is NOT withheld, see EventVisibility's
// buildFullDetail()), the "yes" coming from the CALENDAR.
$db->query("INSERT INTO `tblExternalFeeds` (`siteID`, `name`, `url`, `audienceLevel`, `websiteOptIn`, `isActive`) "
    . "VALUES ({$siteA}, 'PDS outside calendar', 'https://calendar.selftest.invalid/pds.ics', 'public', 1, 1)");
$feedId = (int) $db->insert_id;
$eCopied = $makeEvent('PDS A copied in', $siteA, $zoneA, $at($later), null, ['description' => 'PDS COPIED DESCRIPTION', 'locationName' => 'PDS copied place']);
$db->query("UPDATE `tblEvents` SET `externalFeedID` = {$feedId}, `importLevel` = 'public', `importDetail` = 'basic', `importWebsite` = 1, "
    . "`externalPrivate` = 1, `importSource` = 'calendar', `importRecheckAt` = NULL, `isPublic` = 0, `showOnWebsite` = 0 WHERE `eventID` = {$eCopied}");

// Posters and uploads. A tiny real PNG, written into the COPY's upload folder.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$upload = static function (int $site) use ($db, $png, $child, $sep): string {
    $stored = bin2hex(random_bytes(16)) . '.png';
    file_put_contents($child . $sep . 'web' . $sep . '_uploads' . $sep . 'noticeboard' . $sep . $stored, $png);
    $db->query("INSERT INTO `tblNoticeboardUploads` (`siteID`, `storedName`, `mimeType`, `fileSize`) VALUES ({$site}, '{$stored}', 'image/png', 4242)");
    return $stored;
};
$upA  = $upload($siteA);
$upA2 = $upload($siteA);
$upB  = $upload($siteB);
$makePoster = static function (string $title, int $site, array $set = []) use ($db): int {
    $db->query("INSERT INTO `tblNoticeboardPosters` (`siteID`, `title`, `showOnWebsite`) VALUES ({$site}, '" . $db->real_escape_string($title) . "', 1)");
    $id = (int) $db->insert_id;
    foreach ($set as $col => $val) {
        $u = $db->prepare('UPDATE `tblNoticeboardPosters` SET `' . $col . '` = ? WHERE `posterID` = ?');
        $u->bind_param('si', $val, $id);
        $u->execute();
        $u->close();
    }
    return $id;
};
$pShown = $makePoster('PDS poster shown', $siteA, ['mediaType' => 'image', 'mediaUrl' => '/noticeboard/media?f=' . $upA,
    'scheduleType' => 'weekly', 'weekday' => '2', 'eventTime' => '10:30:00', 'location' => 'PDS Hall', 'link' => 'https://events.selftest.invalid/toddlers']);
$pUnticked = $makePoster('PDS poster unticked', $siteA, ['mediaType' => 'image', 'mediaUrl' => '/noticeboard/media?f=' . $upA2, 'showOnWebsite' => '0']);
$pOutside  = $makePoster('PDS poster outside picture', $siteA, ['mediaType' => 'image', 'mediaUrl' => 'https://images.selftest.invalid/a.png',
    'link' => 'http://plain.selftest.invalid/', 'scheduleType' => 'once', 'eventDate' => $tomorA]);
$pBorrow   = $makePoster('PDS poster naming B upload', $siteA, ['mediaType' => 'image', 'mediaUrl' => '/noticeboard/media?f=' . $upB]);
$pDeleted  = $makePoster('PDS poster deleted', $siteA, ['isDeleted' => '1']);
$pUntil    = $makePoster('PDS poster until yesterday', $siteA, ['websiteUntil' => $yesterA]);
$pB        = $makePoster('PDS poster B', $siteB, ['mediaType' => 'image', 'mediaUrl' => '/noticeboard/media?f=' . $upB]);

$hostA = 'door-a.selftest.test';
$hostB = 'door-b.selftest.test';
$hostC = 'door-c.selftest.test';

$startServer();
$waitForServer();

/** A feed's titles, or null when it did not answer 200 JSON. */
$titles = static function (array $r, string $list): ?array {
    if ($r['status'] !== 200) {
        return null;
    }
    $j = json_decode($r['body'], true);
    if (is_array($j) === false || isset($j[$list]) === false) {
        return null;
    }
    $out = array_map(static fn (array $one): string => (string) $one['title'], $j[$list]);
    sort($out);
    return $out;
};
$capture = static function (string $kind, string $label, array $r) use ($answers): void {
    if ($r['status'] === 200) {
        file_put_contents($answers . DIRECTORY_SEPARATOR . $kind . '-' . $label . '.json', $r['body']);
    }
};

// =============================================================================
echo "K. The card on Admin → Public website (D-097: the door starts off)\n";
// =============================================================================
$signIn('root');
$k0 = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']]);
check('K01 every organisation\'s door starts OFF: no name row, and the card says Off with a "Turn on" button',
    [$doorName($siteA), str_contains($k0['body'], 'data-public-door="off"'), str_contains($k0['body'], 'data-door-form="on"')],
    [null, true, true]);
$k1 = $card('root', 'door_on');
$nameA1 = $doorName($siteA);
check('K02 "Turn on the public door" makes a 24-character random name, recorded as PublicDoorTurnedOn, and the card shows its addresses',
    [preg_match('/^[0-9a-f]{24}$/', (string) $nameA1), $logged('PublicDoorTurnedOn', $people['root']['id']),
        str_contains($k1['page'], 'data-public-door="on"'), str_contains($k1['page'], $base . '/w/' . $nameA1 . '/events.json'),
        str_contains($k1['page'], $base . '/w/' . $nameA1 . '/posters.json')],
    [1, 1, true, true, true]);
$k3 = $card('root', 'door_on');
check('K03 turning it on again changes nothing and says so', [$doorName($siteA), str_contains($k3['page'], 'already on')], [$nameA1, true]);
$k4 = $card('root', 'door_replace');
check('K04 "Replace the name" without the "I understand" tick changes nothing', [$doorName($siteA), str_contains($k4['page'], 'tick the box')], [$nameA1, true]);
$k5 = $card('root', 'door_off');
check('K05 "Switch the public door off" without the tick changes nothing, and both refusals are recorded (PublicWebsiteSetupRefused)',
    [$doorName($siteA), $logged('PublicWebsiteSetupRefused', $people['root']['id'])], [$nameA1, 2]);
check('K06 the card warns, before the buttons are pressed, that replacing or switching off stops every pasted copy',
    [str_contains($k1['page'], 'Every copy pasted into a website with the old name stops working at once'),
        // Round 2 of P4a's check: the switch-off words must not offer a new
        // name as protection for a PUBLIC address, which answers again the
        // moment the door is back on.
        str_contains($k1['page'], 'a public address carries no name, so it answers again the moment the door is back on')],
    [true, true]);
$signIn('member');
$kMember = $card('member', 'door_off', ['understood' => '1']);
check('K07 a plain member cannot open the card or switch the door off (the page\'s own gate, maySetUp())', [$kMember['status'], $doorName($siteA)], [403, $nameA1]);

// =============================================================================
echo "G. The gate\n";
// =============================================================================
$feedA = static fn (string $what = 'events.json', array $opt = []) => $http('GET', '/w/' . (string) $doorName($siteA) . '/' . $what, $opt);
$g1 = $feedA();
$capture('events', 'a-first', $g1);
check('G01 with the door on, /w/{name}/events.json answers 200 JSON in the events format',
    [$g1['status'], $g1['headers']['content-type'] ?? '', json_decode($g1['body'], true)['format'] ?? null],
    [200, 'application/json; charset=utf-8', 'webms-public-events']);
$g2 = $feedA('posters.json');
$capture('posters', 'a-first', $g2);
check('G02 posters.json answers 200 JSON in the posters format', [$g2['status'], json_decode($g2['body'], true)['format'] ?? null], [200, 'webms-public-posters']);

$refusals = [];
$refusals['unknown name'] = $http('GET', '/w/' . str_repeat('ab', 12) . '/events.json');
$refusals['name in capitals'] = $http('GET', '/w/' . strtoupper((string) $nameA1) . '/events.json');
$refusals['malformed name'] = $http('GET', '/w/not-a-name/events.json');
$refusals['no name at all'] = $http('GET', '/w/');
$refusals['unknown address after the name'] = $feedA('secrets.json');
$refusals['the bare name'] = $feedA('');
$refusals['a trailing slash'] = $feedA('events.json/');
$refusals['a wrong method'] = $http('POST', '/w/' . $nameA1 . '/events.json', ['post' => ['x' => '1']]);
foreach (['0', '51', '-1', 'abc', '01', '1.5', '999'] as $bad) {
    $refusals['limit=' . $bad] = $feedA('events.json?limit=' . rawurlencode($bad));
}
$refusals['limit as a list'] = $feedA('events.json?limit[]=1');
check('G03 an unknown name, a name in capitals, a malformed or missing name, an unknown address, a trailing slash, a POST and every bad limit are all 404',
    array_values(array_unique(array_map(static fn (array $r): int => $r['status'], $refusals))), [404]);
check('G04 HEAD is answered like GET', $http('HEAD', '/w/' . $nameA1 . '/events.json')['status'], 200);
$one = json_decode($feedA('events.json?limit=1')['body'], true);
$fifty = $feedA('events.json?limit=50');
check('G05 limit=1 gives one event; limit=50 is accepted', [count($one['events'] ?? []), $fifty['status']], [1, 200]);

$card('root', 'door_replace', ['understood' => '1']);
$nameA2 = $doorName($siteA);
$refusals['a replaced name'] = $http('GET', '/w/' . $nameA1 . '/events.json');
check('G06 replacing the name: a new name, the OLD one refused at once, the new one answers, recorded as PublicDoorNameReplaced',
    [$nameA2 !== $nameA1 && preg_match('/^[0-9a-f]{24}$/', (string) $nameA2) === 1, $refusals['a replaced name']['status'],
        $feedA()['status'], $logged('PublicDoorNameReplaced', $people['root']['id'])],
    [true, 404, 200, 1]);
$card('root', 'door_off', ['understood' => '1']);
$refusals['the door switched off'] = $http('GET', '/w/' . $nameA2 . '/events.json');
check('G07 switching the door off deletes the row; the last name is refused; recorded as PublicDoorTurnedOff',
    [$doorName($siteA), $refusals['the door switched off']['status'], $logged('PublicDoorTurnedOff', $people['root']['id'])], [null, 404, 1]);
$card('root', 'door_on');
$nameA = (string) $doorName($siteA);
check('G08 turning it on again gives a NEW name (so a copy pasted before it was switched off stays stopped)',
    [$nameA !== $nameA2, $http('GET', '/w/' . $nameA2 . '/events.json')['status'], $feedA()['status']], [true, 404, 200]);
$checkPage = (static function () use ($http, $tokenOn, $people): string {
    $jar  = $people['root']['jar'];
    $page = $http('GET', '/admin/public-website', ['jar' => $jar]);
    $http('POST', '/admin/public-website/check', ['jar' => $jar, 'post' => ['csrf_token' => $tokenOn($page['body'])]]);
    return $http('GET', '/admin/public-website', ['jar' => $jar])['body'];
})();
check('G08b "Check my set-up" says the door is on and gives its events address — and still never ticks whether a website shows it',
    [str_contains($checkPage, 'Switched on. Its events are read from ' . $base . '/w/' . $nameA . '/events.json'),
        str_contains($checkPage, 'Whether your website really shows it is not tested by this check'),
        preg_match('/data-check-state="ok"[^>]*>\s*<span[^>]*>[^<]*<\/span>\s*<strong>The public door/', $checkPage)],
    [true, true, 0]);

// B and C, written straight into the database, with public addresses.
$nameB = bin2hex(random_bytes(12));
$nameC = bin2hex(random_bytes(12));
$db->query("INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES ({$siteB}, '{$nameB}', UTC_TIMESTAMP()), ({$siteC}, '{$nameC}', UTC_TIMESTAMP())");
$db->query("INSERT INTO `tblPublicHosts` (`siteID`, `hostName`, `changedAtUtc`) VALUES ({$siteA}, '{$hostA}', UTC_TIMESTAMP()), ({$siteB}, '{$hostB}', UTC_TIMESTAMP()), ({$siteC}, '{$hostC}', UTC_TIMESTAMP())");
$refusals['an inactive organisation by name'] = $http('GET', '/w/' . $nameC . '/events.json');
$refusals['an inactive organisation by public address'] = $http('GET', '/events.json', ['host' => $hostC]);
check('G09 an INACTIVE organisation answers nothing, by name or by its public address (it has a door, a public address and a ticked event)',
    [$refusals['an inactive organisation by name']['status'], $refusals['an inactive organisation by public address']['status']], [404, 404]);
$pubA = $http('GET', '/events.json', ['host' => $hostA]);
$capture('events', 'a-public-host', $pubA);
check('G10 A\'s public address answers /events.json with A\'s events', [$pubA['status'], in_array('PDS A shown', $titles($pubA, 'events') ?? [], true)], [200, true]);
$refusals['a public address, path not a door address'] = $http('GET', '/login', ['host' => $hostA]);
$refusals['a public address, its root'] = $http('GET', '/', ['host' => $hostA]);
check('G11 a public address answers door requests ONLY (D-098): /login and / there are the door\'s 404, never a portal page',
    [$refusals['a public address, path not a door address']['status'], $refusals['a public address, its root']['status']], [404, 404]);

// Round 1 of P4a's check: "Replace the name" stops nothing on a public address,
// which carries no name. The card and the message must say so.
$kBefore = $http('GET', '/admin/public-website', ['jar' => $people['root']['jar']])['body'];
$k8 = $card('root', 'door_replace', ['understood' => '1']);
$refusals['the old name after a replace with a public address set'] = $http('GET', '/w/' . $nameA . '/events.json');
$nameA = (string) $doorName($siteA);
check('K08 with a public address set, replacing the name stops the old /w/ name but NOT the public address, and the card and the message say exactly that',
    [$refusals['the old name after a replace with a public address set']['status'], $http('GET', '/events.json', ['host' => $hostA])['status'],
        str_contains($kBefore, 'data-door-replace-public="1"'), str_contains($kBefore, 'Every copy pasted into a website with the old name stops working at once'),
        str_contains($k8['page'], 'is NOT affected by the name'), $feedA()['status']],
    [404, 200, true, false, true, 200]);
$k9 = $card('root', 'door_replace');
check('K09 with a public address set, the refusal for a missing tick says what the card\'s tick says: the public address is NOT stopped by a new name',
    [$doorName($siteA), str_contains($k9['page'], 'copies using your public address will not'),
        // The refusal's OWN words, whole: the switch-off tick on the same page
        // rightly says "every copy pasted into a website will stop working".
        str_contains($k9['page'], 'tick the box to say you understand that every copy pasted into a website will stop working')],
    [$nameA, true, false]);
// Round 2 of P4a's check: switching the door off and on again does NOT protect a
// leaked PUBLIC address — it answers again the moment the door is back on.
$card('root', 'door_off', ['understood' => '1']);
$offStatus = $http('GET', '/events.json', ['host' => $hostA])['status'];
$card('root', 'door_on');
$nameA = (string) $doorName($siteA);
check('K10 a public address is refused while the door is off and answers again the moment it is back on — which is why the card offers changing or removing it, not a new name, as the way to stop it',
    [$offStatus, $http('GET', '/events.json', ['host' => $hostA])['status'], $feedA()['status']], [404, 200, 200]);

// Round 1 of P4a's check (D-098): error.php and api-docs start the portal
// WITHOUT index.php, and on a real server .htaccess sends every refused .php,
// dotfile and 403/404/500/503 to error.php. On a public address both must give
// the door's identical 404, with no cookie and no session file.
$eBefore = $sessionFiles();
$refusals['error.php on a public address'] = $http('GET', '/error.php?code=404', ['host' => $hostA]);
$refusals['error.php (500) on a public address'] = $http('GET', '/error.php?code=500', ['host' => $hostA]);
$refusals['api-docs on a public address'] = $http('GET', '/api-docs/index.php', ['host' => $hostA]);
$refusals['error.php on a switched-off organisation\'s public address'] = $http('GET', '/error.php?code=404', ['host' => $hostC]);
check('E01 on a public address error.php (404 and 500) and the API description answer the door\'s 404 — no portal page, no menu, no cookie, no session file — and on the inactive C\'s address too',
    [$refusals['error.php on a public address']['status'], $refusals['error.php (500) on a public address']['status'],
        $refusals['api-docs on a public address']['status'], $refusals['error.php on a switched-off organisation\'s public address']['status'],
        $refusals['api-docs on a public address']['headers']['set-cookie'] ?? '', $sessionFiles() - $eBefore],
    [404, 404, 404, 404, '', 0]);
$eMembers = $http('GET', '/error.php?code=404');
check('E02 on the members\' own address error.php still draws the portal\'s own error page (the hand-off reaches door requests only)',
    [$eMembers['status'], str_contains($eMembers['body'], '<html'), $eMembers['body'] === "Not found.\n"], [404, true, false]);

$setOwn($siteA, 'calendar.enabled', 'false');
$refusals['Calendar switched off'] = $feedA();
check('G12 with Calendar switched off for A, events.json is refused while posters.json still answers',
    [$refusals['Calendar switched off']['status'], $feedA('posters.json')['status']], [404, 200]);
$setOwn($siteA, 'calendar.enabled', null);
$setOwn($siteA, 'noticeboard.enabled', 'false');
$refusals['Noticeboard switched off'] = $feedA('posters.json');
$refusals['Noticeboard off, media'] = $feedA('media/' . $upA);
check('G13 with the Noticeboard switched off for A, posters.json AND its media are refused while events.json still answers',
    [$refusals['Noticeboard switched off']['status'], $refusals['Noticeboard off, media']['status'], $feedA()['status']], [404, 404, 200]);
check('G14 switching A\'s Noticeboard off leaves B\'s alone', $http('GET', '/w/' . $nameB . '/posters.json')['status'], 200);
$setOwn($siteA, 'noticeboard.enabled', null);

$setInstallation('portal.maintenance.active', '1');
$m1 = $feedA();
$m2 = $http('GET', '/w/' . $nameB . '/posters.json');
$m3 = $http('GET', '/w/' . str_repeat('cd', 12) . '/events.json');
check('G15 while closed for maintenance every door answer is a 503 with Retry-After and no-store, the same for every organisation and an unknown name',
    [$m1['status'], $m1['headers']['retry-after'] ?? '', $m1['headers']['cache-control'] ?? '', $signature($m1) === $signature($m2), $signature($m1) === $signature($m3)],
    [503, '60', 'no-store', true, true]);
$setInstallation('portal.maintenance.active', '0');

$setInstallation('site.url', null);
$refusals['the portal\'s own address not stored'] = $feedA();
check('G16 with the portal\'s own address NOT stored, the /w/ form refuses (its links would be built from a host the visitor chose); the public address still answers',
    [$refusals['the portal\'s own address not stored']['status'], $http('GET', '/events.json', ['host' => $hostA])['status']], [404, 200]);
$setInstallation('site.url', $base);

// =============================================================================
echo "X. Organisations never cross\n";
// =============================================================================
$xa = $feedA();
$xb = $http('GET', '/w/' . $nameB . '/events.json');
$capture('events', 'b', $xb);
check('X01 A\'s name gives A\'s events only and B\'s name gives B\'s only',
    [$titles($xa, 'events'), $titles($xb, 'events')], [['PDS A running now', 'PDS A shown'], ['PDS B shown']]);
$jarB = $signIn('memberB');
$withB = $http('GET', '/w/' . $nameA . '/events.json', ['jar' => $jarB]);
check('X02 a signed-in session for B asking A\'s name gets exactly A\'s answer (the door never reads the session)',
    [$titles($withB, 'events'), $titles($withB, 'events') === $titles($xa, 'events')], [['PDS A running now', 'PDS A shown'], true]);
$refusals['A\'s public address with B\'s name'] = $http('GET', '/w/' . $nameB . '/events.json', ['host' => $hostA]);
check('X03 A\'s public address never resolves a name: /w/{B\'s name}/events.json there is refused', $refusals['A\'s public address with B\'s name']['status'], 404);
$refusals['A\'s media through B\'s door'] = $http('GET', '/w/' . $nameB . '/media/' . $upA);
$refusals['B\'s media through A\'s door'] = $feedA('media/' . $upB);
check('X04 one organisation\'s media is never served through the other\'s door (even when one of A\'s ticked posters names B\'s upload)',
    [$refusals['A\'s media through B\'s door']['status'], $refusals['B\'s media through A\'s door']['status'],
        $http('GET', '/w/' . $nameB . '/media/' . $upB)['status']], [404, 404, 200]);
// "Own address each": A and B each have a members' address.
$db->query("UPDATE `tblSites` SET `hostPattern` = 'members-a.selftest.test' WHERE `siteID` = 1");
$db->query("UPDATE `tblSites` SET `hostPattern` = 'members-b.selftest.test' WHERE `siteID` = {$siteB}");
$setInstallation('multisite.detectionMode', 'subdomain');
$refusals['B\'s name on A\'s members\' address'] = $http('GET', '/w/' . $nameB . '/events.json', ['host' => 'members-a.selftest.test']);
$xOwnA = $http('GET', '/w/' . $nameA . '/events.json', ['host' => 'members-a.selftest.test']);
$xOwnB = $http('GET', '/w/' . $nameB . '/events.json', ['host' => 'members-b.selftest.test']);
check('X05 in "own address each" mode B\'s name on A\'s members\' address is refused, while each name answers on its own',
    [$refusals['B\'s name on A\'s members\' address']['status'], $titles($xOwnA, 'events'), $titles($xOwnB, 'events')],
    [404, ['PDS A running now', 'PDS A shown'], ['PDS B shown']]);
$setInstallation('multisite.detectionMode', 'session');
$db->query("UPDATE `tblSites` SET `hostPattern` = NULL WHERE `siteID` IN (1, {$siteB})");
$setInstallation('multisite.enabled', 'false');
$refusals['B\'s name with multi-organisation off'] = $http('GET', '/w/' . $nameB . '/events.json');
check('X06 with multi-organisation switched off only organisation 1\'s name is honoured',
    [$refusals['B\'s name with multi-organisation off']['status'], $feedA()['status']], [404, 200]);
$setInstallation('multisite.enabled', 'true');
$setInstallation('multisite.detectionMode', 'path');
$xBefore = $sessionFiles();
$refusals['a door address behind an organisation prefix'] = $http('GET', '/selftest-door-b/w/' . $nameB . '/events.json');
check('X07 in "own path each" mode, /{B\'s key}/w/{B\'s name}/events.json is the door\'s 404 — decided before the session, so no session file',
    [$refusals['a door address behind an organisation prefix']['status'], $sessionFiles() - $xBefore], [404, 0]);
$xBefore = $sessionFiles();
$refusals['a door address behind a prefix with a doubled slash'] = $http('GET', '/selftest-door-b//w/' . $nameB . '/events.json');
$refusals['a door address behind an INACTIVE organisation\'s prefix'] = $http('GET', '/selftest-door-c/w/' . $nameC . '/events.json');
$doubled = $http('GET', '//w/' . $nameA . '/events.json');
check('X08 repeated slashes count as one (/{key}//w/... refused, //w/{name}/... answered as the door) and an INACTIVE organisation\'s prefix is refused too — all before any session',
    [$refusals['a door address behind a prefix with a doubled slash']['status'], $refusals['a door address behind an INACTIVE organisation\'s prefix']['status'],
        $doubled['status'], $doubled['headers']['set-cookie'] ?? '', $sessionFiles() - $xBefore], [404, 404, 200, '', 0]);
$setInstallation('multisite.detectionMode', 'session');

// =============================================================================
echo "C. What the door gives out\n";
// =============================================================================
$cBody = $feedA('events.json?limit=50')['body'];
$cJson = json_decode($cBody, true);
$shown = null;
foreach ($cJson['events'] ?? [] as $one) {
    if ($one['title'] === 'PDS A shown') {
        $shown = $one;
    }
}
check('C01 never shown: unticked, outside its dates (either side), deleted, draft, cancelled, members-only, finished, or another organisation\'s',
    array_values(array_intersect(['PDS A unticked', 'PDS A until yesterday', 'PDS A from tomorrow', 'PDS A deleted', 'PDS A draft',
        'PDS A cancelled', 'PDS A members only', 'PDS A finished', 'PDS B shown', 'PDS C shown', 'PDS A copied in'],
        array_column($cJson['events'] ?? [], 'title'))), []);
check('C02 an event still running on its own clock IS shown, and they come soonest first',
    array_column($cJson['events'] ?? [], 'title'), ['PDS A running now', 'PDS A shown']);
check('C03 one event carries exactly the published fields, its id is its slug (not a number), and its full details where allowed',
    [array_keys($shown ?? []), is_string($shown['id'] ?? null) && str_starts_with((string) $shown['id'], 'pds-'), $shown['description'] ?? null,
        $shown['location'] ?? null, $shown['detailsLimited'] ?? null],
    [['id', 'title', 'startsAt', 'endsAt', 'allDay', 'timezone', 'detailsLimited', 'description', 'location'], true, 'Bring a dish.',
        ['name' => 'PDS Hall', 'address' => '1 PDS Street', 'url' => 'https://hall.selftest.invalid/'], false]);
$leaks = [];
foreach (['01234 PDS PHONE', 'pds-hall-secret', '51.1234567', 'pds.secret.words', 'PDS HOST ORG', '"eventID"', '"siteID"', 'createdBy', '"capacity"'] as $needle) {
    if (str_contains($cBody, $needle) === true) {
        $leaks[] = $needle;
    }
}
check('C04 the answer holds no telephone, e-mail, map position, what3words, host organisation, database number, creator or capacity', $leaks, []);
$startA = (new DateTimeImmutable($at($later), $clockA))->format('Y-m-d\TH:i:sP');
$bEvent = json_decode($xb['body'], true)['events'][0] ?? [];
check('C05 times are on the event\'s own clock with its offset: A\'s on A\'s, and B\'s 18:00 in Pacific/Auckland with Auckland\'s offset',
    [$shown['startsAt'] ?? null, $bEvent['startsAt'] ?? null, $bEvent['timezone'] ?? null, json_decode($xb['body'], true)['organisation']['timezone'] ?? null],
    [$startA, $laterB->format('Y-m-d\TH:i:sP'), 'Pacific/Auckland', 'Pacific/Auckland']);
$setInstallation('public.events.copiedRule', 'calendar');
$copiedUnder = static function () use ($feedA): ?array {
    foreach (json_decode($feedA('events.json?limit=50')['body'], true)['events'] ?? [] as $one) {
        if ($one['title'] === 'PDS A copied in') {
            return $one;
        }
    }
    return null;
};
$cc = $copiedUnder();
check('C06 under "one box per calendar" the calendar-ticked copied-in event IS shown — title, date and time only, as its calendar withholds details',
    [$cc !== null, $cc['detailsLimited'] ?? null, array_key_exists('description', $cc ?? []) === true ? $cc['description'] : 'missing',
        array_key_exists('location', $cc ?? []) === true ? $cc['location'] : 'missing'], [true, true, null, null]);
$capture('events', 'a-calendar-rule', $feedA('events.json?limit=50'));
$setInstallation('public.events.copiedRule', 'event');
check('C07 under "one tick per event" the same event is NOT shown', $copiedUnder(), null);
$setEv($eCopied, ['importSource' => 'date']);
check('C08 under "one tick per event" a copied-in event whose "yes" came from a choice about that date IS shown', $copiedUnder() !== null, true);
$setEv($eCopied, ['importSource' => 'calendar']);
$setOwn($siteA, 'public.events.copiedRule', 'calendar');
check('C09 an organisation row looser than the installation is held down when READ (copiedRuleFor caps it)', $copiedUnder(), null);
$setOwn($siteA, 'public.events.copiedRule', null);

$pr   = $feedA('posters.json');
$pj   = json_decode($pr['body'], true);
$byT  = [];
foreach ($pj['posters'] ?? [] as $one) {
    $byT[$one['title']] = $one;
}
$capture('posters', 'a', $pr);
check('C10 posters: only ticked, in-date, undeleted posters of A',
    $titles($pr, 'posters'), ['PDS poster naming B upload', 'PDS poster outside picture', 'PDS poster shown']);
check('C11 a poster\'s upload is pointed at the DOOR\'s own media address; its day, time, place and https link are given',
    [$byT['PDS poster shown']['media'] ?? null, $byT['PDS poster shown']['when'] ?? null, $byT['PDS poster shown']['time'] ?? null,
        $byT['PDS poster shown']['location'] ?? null, $byT['PDS poster shown']['link'] ?? null],
    [['type' => 'image', 'url' => 'https://' . $hostA . '/media/' . $upA], ['type' => 'weekly', 'weekday' => 2], '10:30', 'PDS Hall',
        'https://events.selftest.invalid/toddlers']);
check('C12 an outside https picture is passed through; a plain-http link is dropped; a poster naming ANOTHER organisation\'s upload gets no media',
    // array_key_exists, never `?? 'missing'`: `??` reads a key holding null
    // as absent, which is exactly the value being pinned here.
    [$byT['PDS poster outside picture']['media']['url'] ?? null,
        array_key_exists('link', $byT['PDS poster outside picture'] ?? []) === true ? $byT['PDS poster outside picture']['link'] : 'missing',
        $byT['PDS poster outside picture']['when'] ?? null,
        array_key_exists('media', $byT['PDS poster naming B upload'] ?? []) === true ? $byT['PDS poster naming B upload']['media'] : 'missing'],
    ['https://images.selftest.invalid/a.png', null, ['type' => 'once', 'date' => $tomorA], null]);
check('C13 nothing in the posters answer names a database number or the poster\'s sort order',
    [str_contains($pr['body'], 'posterID'), str_contains($pr['body'], 'sortOrder'), str_contains($pr['body'], '"siteID"')], [false, false, false]);
$md = $feedA('media/' . $upA);
check('C14 the door serves a ticked poster\'s upload: the file\'s own bytes, as image/png',
    [$md['status'], $md['body'] === $png, $md['headers']['content-type'] ?? ''], [200, true, 'image/png']);
$refusals['an unticked poster\'s media'] = $feedA('media/' . $upA2);
$refusals['media that is not an upload'] = $feedA('media/' . str_repeat('e', 32) . '.png');
check('C15 an unticked poster\'s upload, and a name that is no upload, are refused', [$refusals['an unticked poster\'s media']['status'],
    $refusals['media that is not an upload']['status']], [404, 404]);
$db->query("UPDATE `tblNoticeboardPosters` SET `showOnWebsite` = 0 WHERE `posterID` = {$pShown}");
$refusals['media the moment its poster is unticked'] = $feedA('media/' . $upA);
check('C16 the moment its poster is unticked, the media stops answering and the poster leaves the feed',
    [$refusals['media the moment its poster is unticked']['status'], in_array('PDS poster shown', $titles($feedA('posters.json'), 'posters') ?? [], true)], [404, false]);
$db->query("UPDATE `tblNoticeboardPosters` SET `showOnWebsite` = 1 WHERE `posterID` = {$pShown}");
check('C17 ticked again, it answers again', $feedA('media/' . $upA)['status'], 200);
$allDayShown = static function () use ($setEv, $eAllDayToday, $eAllDayPast, $titles, $feedA): array {
    $setEv($eAllDayToday, ['showOnWebsite' => '1']);
    $setEv($eAllDayPast, ['showOnWebsite' => '1']);
    $t = array_values(array_intersect(['PDS A all day today', 'PDS A all day yesterday'], $titles($feedA('events.json?limit=50'), 'events') ?? []));
    $setEv($eAllDayToday, ['showOnWebsite' => '0']);
    $setEv($eAllDayPast, ['showOnWebsite' => '0']);
    return $t;
};
check('C18 an all-day event with no end time is kept through the whole of its own day, and gone the day after', $allDayShown(), ['PDS A all day today']);
$hexBody = $feedA('events.json?limit=50')['body'];
check('C19 markup and quotes inside a value are written as \\u escapes: no live <b>, </script>, & or \' in the answer, and the value still reads back exactly',
    [str_contains($hexBody, '<b>'), str_contains($hexBody, '</script>'), str_contains($hexBody, '&'), str_contains($hexBody, "'"),
        (static function () use ($hexBody): ?string {
            foreach (json_decode($hexBody, true)['events'] ?? [] as $one) {
                if ($one['title'] === 'PDS A running now') {
                    return $one['description'];
                }
            }
            return null;
        })()],
    [false, false, false, false, '<b>"Tom & Jerry\'s"</b></script>']);

// =============================================================================
echo "H. Headers on the live portal\n";
// =============================================================================
$setOwn($siteA, 'public.frame.origins', 'https://www.selftest.invalid');
$hf = $feedA();
$hm = $feedA('media/' . $upA);
$want = [
    'access-control-allow-origin' => '*', 'cross-origin-resource-policy' => 'cross-origin',
    'content-security-policy' => "default-src 'none'; frame-ancestors 'none'; sandbox", 'referrer-policy' => 'no-referrer',
    'cache-control' => 'public, max-age=60', 'x-robots-tag' => 'noindex', 'x-content-type-options' => 'nosniff',
];
$pick = static function (array $r) use ($want): array {
    $out = [];
    foreach (array_keys($want) as $k) {
        $out[$k] = $r['headers'][$k] ?? '';
    }
    return $out;
};
check('H01 a feed answer: readable and loadable by any website, data never a page (frame-ancestors \'none\' although A lists a framing website), no Referer, one minute\'s caching, noindex',
    $pick($hf), $want);
check('H02 a media answer carries the same headers', $pick($hm), $want);
$absent = static fn (array $r): array => [
    'set-cookie' => $r['headers']['set-cookie'] ?? '', 'x-frame-options' => $r['headers']['x-frame-options'] ?? '',
    'cross-origin-opener-policy' => $r['headers']['cross-origin-opener-policy'] ?? '',
    'access-control-allow-credentials' => $r['headers']['access-control-allow-credentials'] ?? '', 'vary' => $r['headers']['vary'] ?? '',
];
$none = ['set-cookie' => '', 'x-frame-options' => '', 'cross-origin-opener-policy' => '', 'access-control-allow-credentials' => '', 'vary' => ''];
check('H03 no Set-Cookie, X-Frame-Options, COOP, credentials header or Vary on a feed, a media answer or a refusal',
    [$absent($hf), $absent($hm), $absent($refusals['unknown name'])], [$none, $none, $none]);
$r404 = $refusals['unknown name'];
check('H04 the refusal: 404, plain text "Not found.", no-store, readable by any website, noindex',
    [$r404['status'], $r404['body'], $r404['headers']['content-type'] ?? '', $r404['headers']['cache-control'] ?? '',
        $r404['headers']['access-control-allow-origin'] ?? '', $r404['headers']['x-robots-tag'] ?? ''],
    [404, "Not found.\n", 'text/plain; charset=utf-8', 'no-store', '*', 'noindex, nofollow, noarchive']);
$before = $sessionFiles();
$feedA();
$feedA('posters.json');
$feedA('media/' . $upA);
$http('GET', '/events.json', ['host' => $hostA]);
$http('GET', '/w/' . str_repeat('ab', 12) . '/events.json');
$http('GET', '/w/' . $nameA . '/events.json', ['cookie' => 'PHPSESSID=' . str_repeat('z', 32)]);
check('H05 no door request makes a session file — a made-up cookie included', $sessionFiles() - $before, 0);
$setOwn($siteA, 'public.frame.origins', null);

// =============================================================================
echo "R. Every refusal is byte-identical\n";
// =============================================================================
$ref = $signature($refusals['unknown name']);
$differ = [];
foreach ($refusals as $kind => $r) {
    if ($signature($r) !== $ref) {
        $differ[] = $kind;
    }
}
check('R01 all ' . count($refusals) . ' refusals give the same status, headers (Date and the test server\'s Host aside) and body', $differ, []);

/**
 * One request sent over a raw socket with an ABSOLUTE-FORM request line
 * (`GET http://host/path HTTP/1.1`), which HTTP servers must accept and curl
 * will not send. Same answer shape as $http. Round 3 of P4a's check: the door
 * step had stopped recognising these, so a valid name got a portal 404 and a
 * session file.
 */
$rawAbsolute = static function (string $host, string $path) use ($port): array {
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5);
    if ($sock === false) {
        return ['status' => 0, 'headers' => [], 'lines' => [], 'body' => 'no answer'];
    }
    fwrite($sock, 'GET http://' . $host . $path . " HTTP/1.1\r\nHost: " . $host . "\r\nConnection: close\r\n\r\n");
    $raw = (string) stream_get_contents($sock);
    fclose($sock);
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $rows  = explode("\r\n", $head);
    $first = array_shift($rows);
    $named = [];
    $lines = [];
    foreach ($rows as $line) {
        if ($line === '') {
            continue;
        }
        $lines[] = $line;
        [$n, $v] = array_pad(explode(':', $line, 2), 2, '');
        $named[strtolower(trim($n))] = trim($v);
    }
    // PHP's test server answers HTTP/1.1 with a chunked body only when it has
    // no length; the door always knows its length, so the body is plain.
    return ['status' => (int) (explode(' ', (string) $first)[1] ?? 0), 'headers' => $named, 'lines' => $lines, 'body' => $body];
};
$absBefore = $sessionFiles();
$absValid   = $rawAbsolute('127.0.0.1:' . $port, '/w/' . $nameA . '/events.json');
$absUnknown = $rawAbsolute('127.0.0.1:' . $port, '/w/' . str_repeat('ab', 12) . '/events.json');
$absPublic  = $rawAbsolute($hostA, '/events.json');
$absLogin   = $rawAbsolute($hostA, '/login');
check('R02 an ABSOLUTE-FORM request line (GET http://host/…) is the door too: a valid name 200 JSON, an unknown name the identical 404, a public address 200, a portal page on a public address the identical 404 — and no session file',
    [$absValid['status'], json_decode($absValid['body'], true)['format'] ?? null, $signature($absUnknown) === $ref, $absPublic['status'],
        $signature($absLogin) === $ref, $sessionFiles() - $absBefore],
    [200, 'webms-public-events', true, 200, true, 0]);

// =============================================================================
echo "S. Every captured answer satisfies its schema\n";
// =============================================================================
$schemaRun = static function () use ($python, $repo, $answers): array {
    $out = [];
    exec(escapeshellarg($python) . ' -I ' . escapeshellarg($repo . '/tools/audit-checks/check_public_door_schema.py') . ' --answers '
        . escapeshellarg($answers) . ' 2>&1', $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
};
$sr = $schemaRun();
check('S01 check_public_door_schema.py --answers passes over ' . count(glob($answers . '/*.json') ?: []) . ' captured answers', [$sr['code'], str_contains($sr['out'], 'captured answer')], [0, true]);

// =============================================================================
echo "T. A test copy (PORTAL_ENV=alpha, D-087)\n";
// =============================================================================
$channel = 'alpha';
$startServer();
$waitForServer();
$tBefore = $sessionFiles();
$tNone   = $feedA();
$tForged = $http('GET', '/w/' . $nameA . '/events.json', ['cookie' => 'PHPSESSID=' . str_repeat('q', 32)]);
check('T01 with no sign-in, and with a made-up cookie, the test copy\'s door refuses — and makes no session file',
    [$tNone['status'], $tForged['status'], $sessionFiles() - $tBefore], [404, 404, 0]);
$alphaRefusal = $http('GET', '/w/' . str_repeat('ab', 12) . '/events.json');
check('T02 a D-087 refusal is the same answer as an unknown name on the test copy, and says noindex, nofollow, noarchive',
    [$signature($tNone) === $signature($alphaRefusal), $tNone['headers']['x-robots-tag'] ?? ''], [true, 'noindex, nofollow, noarchive']);
$jarM = $signIn('member');
$jarT = $signIn('tester');
$tMember = $http('GET', '/w/' . $nameA . '/events.json', ['cookie' => $cookieOf($jarM)]);
$tTester = $http('GET', '/w/' . $nameA . '/events.json', ['cookie' => $cookieOf($jarT)]);
$capture('events', 'alpha-tester', $tTester);
check('T03 a signed-in member the test-copy gate does not let in is refused; a tester is served',
    [$tMember['status'], $tTester['status'], $titles($tTester, 'events')], [404, 200, ['PDS A running now', 'PDS A shown']]);
check('T04 the tester\'s answer depends on who asked: private, no-store, Vary: Cookie, noindex, nofollow, noarchive, and NO Set-Cookie',
    [$tTester['headers']['cache-control'] ?? '', $tTester['headers']['vary'] ?? '', $tTester['headers']['x-robots-tag'] ?? '', $tTester['headers']['set-cookie'] ?? ''],
    ['private, no-store', 'Cookie', 'noindex, nofollow, noarchive', '']);
$setOwn($siteA, 'public.door.testCopies.open', 'anybody');
check('T05 an organisation choosing "anybody" under an installation limit of "testers" is still refused (the stricter wins)', $feedA()['status'], 404);
$setInstallation('public.door.testCopies.permitted', 'either');
$tOpen = $feedA();
check('T06 only when the installation permits AND the organisation chose "anybody" does the door answer with no sign-in — one minute\'s caching, still noindex, nofollow, noarchive',
    [$tOpen['status'], $tOpen['headers']['cache-control'] ?? '', $tOpen['headers']['x-robots-tag'] ?? ''], [200, 'public, max-age=60', 'noindex, nofollow, noarchive']);
$setOwn($siteB, 'public.door.testCopies.open', null);
check('T07 that choice is A\'s alone: B, with no choice of its own, is still refused with no sign-in', $http('GET', '/w/' . $nameB . '/events.json')['status'], 404);
$setInstallation('public.door.testCopies.permitted', 'testers');
$setOwn($siteA, 'public.door.testCopies.open', null);
$jarRoot = $signIn('root');
$tCard = $http('GET', '/admin/public-website', ['jar' => $jarRoot])['body'];
check('T08 on the test copy the card says the door answers signed-in testers only, and why a real website shows nothing', str_contains($tCard, 'data-door-testers-only="1"'), true);
$testerFile = $sessions . DIRECTORY_SEPARATOR . 'sess_' . substr($cookieOf($people['tester']['jar']), strlen('PHPSESSID='));
$ageOut = static function (bool $stale) use ($testerFile): void {
    clearstatcache();
    touch($testerFile, $stale === true ? time() - ((int) ini_get('session.gc_maxlifetime') + 600) : time());
};
$ageOut(true);
$tStale = $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])]);
$ageOut(false);
check('T09 a tester whose session file is older than PHP\'s session lifetime is nobody: refused (the file is still there, as it is until PHP\'s clear-out runs)',
    [is_file($testerFile), $tStale['status'], $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['status']], [true, 404, 200]);
$channel = 'prod';
$startServer();
$waitForServer();

/**
 * Round 1 of P4a's check: D-087's two settings must be refused by the general
 * settings editor, or a global administrator could open a test copy's door
 * there with no card and no check. Asked of the REAL lock in the COPY, in a
 * separate PHP process, so a plant made in the copy is what answers. (The
 * settings-locks self-test drives the editor itself, but it finds its keys BY
 * READING this lock, so a key taken out of it is simply no longer tested
 * there — which is why this check exists.)
 */
$askLocks = static function () use ($child, $sep): mixed {
    $file = $child . $sep . 'web' . $sep . '_core' . $sep . 'PublicWebsite.php';
    $code = 'require ' . var_export($file, true) . ';'
        . '$o = [];'
        . 'foreach (["public.door.testCopies.permitted", "public.door.testCopies.open"] as $k) {'
        . '  $pg = \\Portal\\Core\\PublicWebsite::lockedPageFor($k);'
        . '  $o[$k] = [\\Portal\\Core\\PublicWebsite::lockedInGeneralEditor($k), $pg["route"] ?? null, $pg["installationOnly"] ?? null];'
        . '}'
        . 'echo json_encode($o);';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
    return json_decode((string) $out, true) ?? (string) $out;
};
check('L01 the general settings editor refuses both of D-087\'s settings and sends them to Admin, Public website (the installation\'s limit for the installation\'s global administrator alone)',
    $askLocks(), ['public.door.testCopies.permitted' => [true, 'admin/public-website', true],
        'public.door.testCopies.open' => [true, 'admin/public-website', false]]);

// =============================================================================
echo "P. The plants: each guard taken out of a COPY, and its scenario re-run\n";
// =============================================================================
$plant = static function (string $relPath, string|array $from, string|array $to, callable $scenario, string $env = 'prod') use ($child, $sep, $startServer, $waitForServer, &$channel): array {
    $path   = $child . $sep . 'web' . $sep . str_replace('/', $sep, $relPath);
    $before = (string) file_get_contents($path);
    $after  = $before;
    foreach ((array) $from as $i => $one) {
        $count = substr_count($after, $one);
        if ($count !== 1) {
            echo "      ! PLANT NOT APPLIED: the text was found {$count} times, not once, in {$relPath}: " . substr(str_replace("\n", '\n', $one), 0, 90) . "\n";
            return ['applied' => false, 'result' => null];
        }
        $after = str_replace($one, ((array) $to)[$i], $after);
    }
    file_put_contents($path, $after);
    $channel = $env;
    $startServer();
    $waitForServer();
    $result = $scenario();
    file_put_contents($path, $before);
    $channel = 'prod';
    $startServer();
    $waitForServer();
    return ['applied' => true, 'result' => $result];
};
$status = static fn (array $r): int => $r['status'];

// The gate's questions.
$p = $plant('_core/PublicDoor.php', "if (PublicWebsiteSetup::doorStateFor(\$db, \$siteId)['state'] !== 'on') {", 'if (false) {',
    static function () use ($db, $siteA, $http, $nameA): int {
        $db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` = {$siteA}");
        $s = $http('GET', '/events.json', ['host' => 'door-a.selftest.test'])['status'];
        $db->query("INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES ({$siteA}, '{$nameA}', UTC_TIMESTAMP())");
        return $s;
    });
check('P01 with the "door on?" question taken out, a door switched off answers on its public address again', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (AppRegistry::isEnabledForSite($app, $siteId) === false) {', 'if (false) {',
    static function () use ($setOwn, $siteA, $feedA): int {
        $setOwn($siteA, 'calendar.enabled', 'false');
        $s = $feedA()['status'];
        $setOwn($siteA, 'calendar.enabled', null);
        return $s;
    });
check('P02 with the app switch taken out, events answer with Calendar switched off', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (Maintenance::isActive() === true) {', 'if (false) {',
    static function () use ($setInstallation, $feedA): int {
        $setInstallation('portal.maintenance.active', '1');
        $s = $feedA()['status'];
        $setInstallation('portal.maintenance.active', '0');
        return $s;
    });
check('P03 with the maintenance question taken out, the door answers 200 while the portal is closed', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "if (\$method !== 'GET' && \$method !== 'HEAD') {", 'if (false) {',
    static fn (): int => $http('POST', '/w/' . $nameA . '/events.json', ['post' => ['x' => '1']])['status']);
check('P04 with the method question taken out, a POST is answered', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'return $n <= self::MAX_LIMIT ? $n : null;', 'return $n;',
    static fn (): int => $feedA('events.json?limit=51')['status']);
check('P05 with the ceiling on limit taken out, limit=51 is answered', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (Site::doorOnPublicHost() === false && PortalAddress::visitorCanChooseHost() === true) {', 'if (false) {',
    static function () use ($setInstallation, $feedA, $base): int {
        $setInstallation('site.url', null);
        $s = $feedA()['status'];
        $setInstallation('site.url', $base);
        return $s;
    });
check('P06 with the "own address stored?" question taken out, /w/ answers when its links would follow the visitor\'s host', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', '$siteId = Site::doorSiteId();', '$siteId = Site::id();',
    static fn (): int => $http('GET', '/w/' . str_repeat('ab', 12) . '/events.json')['status']);
check('P07 reading Site::id() instead of doorSiteId(), an UNKNOWN name falls back to organisation 1 and is served', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', 'WHERE n.`publicName` = ? AND s.`isActive` = 1 LIMIT 1', 'WHERE n.`publicName` = ? LIMIT 1',
    static fn (): int => $http('GET', '/w/' . $nameC . '/events.json')['status']);
check('P08 with "active organisations only" taken out of the name look-up, the inactive organisation answers', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/Site.php', "if (\$hostRow['active'] === true && self::doorOrganisationAllowed(", 'if (self::doorOrganisationAllowed(',
    static fn (): int => $http('GET', '/events.json', ['host' => 'door-c.selftest.test'])['status']);
check('P08b with "active organisations only" taken out of the public-address step, the inactive organisation answers on its public address', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/Site.php', "if (\$hostRow !== null && \$hostRow['usable'] === true) {", 'if (false) {',
    static fn (): int => $http('GET', '/w/' . $nameB . '/events.json', ['host' => 'door-a.selftest.test'])['status']);
check('P09 if a public address stopped being recognised first, B\'s name on A\'s public address would be served', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/Site.php', "if (\$owner !== null && (int) \$owner['siteID'] !== \$siteId) {", 'if (false) {',
    static function () use ($db, $siteB, $setInstallation, $http, $nameB): int {
        $db->query("UPDATE `tblSites` SET `hostPattern` = 'members-a.selftest.test' WHERE `siteID` = 1");
        $setInstallation('multisite.detectionMode', 'subdomain');
        $s = $http('GET', '/w/' . $nameB . '/events.json', ['host' => 'members-a.selftest.test'])['status'];
        $setInstallation('multisite.detectionMode', 'session');
        $db->query("UPDATE `tblSites` SET `hostPattern` = NULL WHERE `siteID` = 1");
        return $s;
    });
check('P10 with the "another organisation\'s members\' address" question taken out, B\'s name answers on A\'s', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/Site.php', 'return $multi === true || $siteId === 1;', 'return true;',
    static function () use ($setInstallation, $http, $nameB): int {
        $setInstallation('multisite.enabled', 'false');
        $s = $http('GET', '/w/' . $nameB . '/events.json')['status'];
        $setInstallation('multisite.enabled', 'true');
        return $s;
    });
check('P11 with "organisation 1 only when multi-organisation is off" taken out, B\'s name answers', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (Gatekeeper::mayUseTestCopy($db, self::peekSignedInAccount(), PORTAL_ENV) === false) {', 'if (false) {',
    static fn (): int => $feedA()['status'], 'alpha');
check('P12 with D-087\'s tester question taken out, a test copy\'s door answers anybody', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', "return \$own === 'anybody';", 'return true;',
    static function () use ($setInstallation, $feedA): int {
        $setInstallation('public.door.testCopies.permitted', 'either');
        $s = $feedA()['status'];
        $setInstallation('public.door.testCopies.permitted', 'testers');
        return $s;
    }, 'alpha');
check('P13 with D-087\'s reader made to ignore the ORGANISATION\'s choice, a limit of "either" alone opens the test copy\'s door to anybody', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$limit === null || (string) (\$limit['settingValue'] ?? '') !== 'either') {", 'if (false) {',
    static function () use ($setOwn, $siteA, $feedA): int {
        $setOwn($siteA, 'public.door.testCopies.open', 'anybody');
        $s = $feedA()['status'];
        $setOwn($siteA, 'public.door.testCopies.open', null);
        return $s;
    }, 'alpha');
check('P13b with D-087\'s reader made to ignore the INSTALLATION\'s limit, the organisation\'s "anybody" alone opens the test copy\'s door', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/Gatekeeper.php', "if (is_array(\$row) === false || (int) \$row['isActive'] !== 1) {\n            return false;\n        }",
    "if (is_array(\$row) === false) {\n            return false;\n        }",
    static function () use ($db, $people, $cookieOf, $feedA): int {
        $db->query("UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = {$people['tester']['id']}");
        $s = $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['status'];
        $db->query("UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = {$people['tester']['id']}");
        return $s;
    }, 'alpha');
check('P14 with "an account switched off is nobody" taken out of the test-copy rule, a switched-off tester\'s old session is served', [$p['applied'], $p['result']], [true, 200]);
check('P14b (and with the rule in place, that switched-off tester is refused)', (static function () use ($db, $people, $cookieOf, $feedA, &$channel, $startServer, $waitForServer): int {
    $channel = 'alpha';
    $startServer();
    $waitForServer();
    $db->query("UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = {$people['tester']['id']}");
    $s = $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['status'];
    $db->query("UPDATE `tblUsers` SET `isActive` = 1 WHERE `userID` = {$people['tester']['id']}");
    $channel = 'prod';
    $startServer();
    $waitForServer();
    return $s;
})(), 404);

// The readers.
$p = $plant('_core/PublicDoor.php', "e.`isDeleted` = 0 AND e.`status` = \\'published\\' ", "1 = 1 AND e.`status` IN (\\'published\\', \\'draft\\', \\'cancelled\\') ",
    static fn (): array => array_values(array_intersect(['PDS A deleted', 'PDS A draft', 'PDS A cancelled'], $titles($feedA('events.json?limit=50'), 'events') ?? [])));
check('P15 with "published and not deleted" taken out, the deleted, draft and cancelled events are given out', [$p['applied'], $p['result']],
    [true, ['PDS A deleted', 'PDS A draft', 'PDS A cancelled']]);
$p = $plant('_core/PublicDoor.php', "'ELSE COALESCE(e.`endDateTime`, e.`startDateTime`) END) >= ' . \$now['sql']", "'ELSE COALESCE(e.`endDateTime`, e.`startDateTime`) END) >= ' . \$now['sql'] . ' - INTERVAL 30 DAY'",
    static fn (): bool => in_array('PDS A finished', $titles($feedA('events.json?limit=50'), 'events') ?? [], true));
check('P16 with "not finished yet" loosened, a finished event is given out', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicDoor.php', '$row  = EventVisibility::redact($row, (int) $row[\'canSeeFull\'] === 1);', '$row  = EventVisibility::redact($row, true);',
    static function () use ($setInstallation, $copiedUnder): mixed {
        $setInstallation('public.events.copiedRule', 'calendar');
        $one = $copiedUnder();
        $setInstallation('public.events.copiedRule', 'event');
        return $one['description'] ?? null;
    });
check('P17 with the full-detail rule taken out, a copied-in event\'s withheld description is given out', [$p['applied'], $p['result']], [true, 'PDS COPIED DESCRIPTION']);
$p = $plant('_core/PublicDoor.php', "PublicWebsite::copiedRuleFor(\$db, \$siteId));\n        \$full", "EventVisibility::COPIED_BY_CALENDAR);\n        \$full",
    static fn (): bool => $copiedUnder() !== null);
check('P18 with the copied-in rule in force replaced by the looser one, the calendar-ticked event is given out under "one tick per event"', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicDoor.php', "if (\$used === false) {\n            return null;\n        }", "if (false) {\n            return null;\n        }",
    static fn (): int => $feedA('media/' . $upA2)['status']);
check('P19 with "a public poster uses it" taken out, an unticked poster\'s upload is served', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'SELECT `mimeType` FROM `tblNoticeboardUploads` WHERE `siteID` = ? AND `storedName` = ? LIMIT 1',
    'SELECT `mimeType` FROM `tblNoticeboardUploads` WHERE `siteID` + 0 * ? >= 0 AND `storedName` = ? LIMIT 1',
    static fn (): int => $feedA('media/' . $upB)['status']);
check('P20 with "this organisation\'s upload" taken out of the media reader, B\'s upload is served through A\'s door (A\'s ticked poster names it)', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', 'if (isset($own[$stored]) === false || self::isMediaName($stored) === false || $mediaBase === \'\') {',
    'if (self::isMediaName($stored) === false || $mediaBase === \'\') {',
    static fn (): mixed => (static function () use ($feedA): mixed {
        foreach (json_decode($feedA('posters.json')['body'], true)['posters'] ?? [] as $one) {
            if ($one['title'] === 'PDS poster naming B upload') {
                return $one['media'];
            }
        }
        return 'missing';
    })());
check('P21 with "this organisation\'s upload" taken out of the posters reader, A\'s feed names B\'s upload', [$p['applied'], $p['result']],
    [true, ['type' => 'image', 'url' => 'https://door-a.selftest.test/media/' . $upB]]);

// The headers.
$hdr = static fn (string $name, string $what = 'events.json', array $opt = []): callable => static fn (): string => $feedA($what, $opt)['headers'][$name] ?? '';
$p = $plant('_core/PublicDoor.php', "header('Content-Type: ' . \$contentType);\n        header('Access-Control-Allow-Origin: *');",
    "header('Content-Type: ' . \$contentType);", $hdr('access-control-allow-origin'));
check('P22 with Access-Control-Allow-Origin taken out, a feed carries none', [$p['applied'], $p['result']], [true, '']);
$p = $plant('_core/PublicDoor.php', "header('Access-Control-Allow-Origin: *');\n        header('Cross-Origin-Resource-Policy: cross-origin');\n        header('Content-Security-Policy: ' . self::DATA_CSP);\n        header('Referrer-Policy: no-referrer');\n        header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly",
    "header('Access-Control-Allow-Origin: *');\n        header('Content-Security-Policy: ' . self::DATA_CSP);\n        header('Referrer-Policy: no-referrer');\n        header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly",
    $hdr('cross-origin-resource-policy', 'media/' . $upA));
check('P23 with Cross-Origin-Resource-Policy taken out, media carries none (start-up\'s same-origin is removed, not left behind)', [$p['applied'], $p['result']], [true, '']);
$p = $plant('_core/PublicDoor.php', "header('Content-Security-Policy: ' . self::DATA_CSP);\n        header('Referrer-Policy: no-referrer');\n        header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly",
    "header('Referrer-Policy: no-referrer');\n        header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly", $hdr('content-security-policy'));
check('P24 with the content rule taken out, a feed carries none', [$p['applied'], $p['result']], [true, '']);
$p = $plant('_core/PublicDoor.php', "header('Referrer-Policy: no-referrer');\n        header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly",
    "header('X-Content-Type-Options: nosniff');\n        if (\$testersOnly", $hdr('referrer-policy'));
check('P25 with Referrer-Policy taken out, a feed carries none (start-up\'s is removed, not left behind)', [$p['applied'], $p['result']], [true, '']);
$p = $plant('_core/PublicDoor.php', "header('Cache-Control: public, max-age=60');", "header('Cache-Control: public, max-age=86400');", $hdr('cache-control'));
check('P26 with the minute\'s caching changed, a feed says so', [$p['applied'], $p['result']], [true, 'public, max-age=86400']);
$p = $plant('_core/PublicDoor.php', "'noindex, nofollow, noarchive' : 'noindex'));", "'noindex, nofollow, noarchive' : 'all'));", $hdr('x-robots-tag'));
check('P27 with the live robots line changed, a feed says so', [$p['applied'], $p['result']], [true, 'all']);
$p = $plant('_core/PublicDoor.php', "'Set-Cookie', 'X-Frame-Options', 'Cross-Origin-Opener-Policy',", "'Set-Cookie', 'Cross-Origin-Opener-Policy',", $hdr('x-frame-options'));
check('P28 with X-Frame-Options no longer removed, start-up\'s SAMEORIGIN reaches a feed', [$p['applied'], $p['result']], [true, 'SAMEORIGIN']);
$p = $plant('_core/PublicDoor.php', "'Set-Cookie', 'X-Frame-Options', 'Cross-Origin-Opener-Policy',", "'Set-Cookie', 'X-Frame-Options',", $hdr('cross-origin-opener-policy'));
check('P29 with Cross-Origin-Opener-Policy no longer removed, start-up\'s reaches a feed', [$p['applied'], $p['result']], [true, 'same-origin']);
$p = $plant('_core/PublicDoor.php', "header('Cache-Control: private, no-store');", "header('Cache-Control: public, max-age=60');",
    static fn (): string => $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['headers']['cache-control'] ?? '', 'alpha');
check('P30 with the tester\'s private caching changed, a test copy\'s answer for one person may be kept for everybody', [$p['applied'], $p['result']], [true, 'public, max-age=60']);
$p = $plant('_core/PublicDoor.php', "header('X-Robots-Tag: ' . (\$testCopy === true ? 'noindex, nofollow, noarchive' : 'noindex'));",
    "header('X-Robots-Tag: noindex');",
    static fn (): string => $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['headers']['x-robots-tag'] ?? '', 'alpha');
check('P31 with the test copy\'s robots line taken out, a test copy says only noindex', [$p['applied'], $p['result']], [true, 'noindex']);
$p = $plant('public_html/index.php', ["if (Site::isDoorRequest() === true) {\n    PublicDoor::answer(\$mysqli);\n}", "Auth::ensureSession();\n"],
    ['', "Auth::ensureSession();\nif (Site::isDoorRequest() === true) {\n    PublicDoor::answer(\$mysqli);\n}\n"],
    static function () use ($sessionFiles, $feedA): int {
        $before = $sessionFiles();
        $feedA();
        return $sessionFiles() - $before;
    });
check('P32 with the door answered AFTER the session start, a door request leaves a session file behind', [$p['applied'], ($p['result'] ?? 0) > 0], [true, true]);
$p = $plant('_core/PublicDoor.php', "if (PublicWebsiteSetup::doorStateFor(\$db, \$siteId)['state'] !== 'on') {\n            self::notFound();",
    "if (PublicWebsiteSetup::doorStateFor(\$db, \$siteId)['state'] !== 'on') {\n            http_response_code(404);\n            echo 'door off';\n            exit();",
    static function () use ($db, $siteA, $http, $nameA, $signature, $ref): bool {
        $db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` = {$siteA}");
        $s = $signature($http('GET', '/events.json', ['host' => 'door-a.selftest.test'])) === $ref;
        $db->query("INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES ({$siteA}, '{$nameA}', UTC_TIMESTAMP())");
        return $s;
    });
check('P33 with one refusal given words of its own, the byte-for-byte comparison tells it apart', [$p['applied'], $p['result']], [true, false]);
$faultAt = "\$org   = self::organisationFacts(\$db, \$siteId);\n        \$today = self::organisationToday(\$siteId);";
$p = $plant('_core/PublicDoor.php', $faultAt, "throw new \\mysqli_sql_exception('planted fault', 2006);\n        " . $faultAt,
    static fn (): bool => $signature($feedA()) === $ref);
check('P34 a planted database fault in the events reader gives the SAME 404 as every other refusal', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/PublicDoor.php', [$faultAt, "        } catch (\\Throwable \$e) {\n            error_log('[WebMS-Intra] PublicDoor: a request"],
    ["throw new \\mysqli_sql_exception('planted fault', 2006);\n        " . $faultAt, "        } catch (\\LogicException \$e) {\n            error_log('[WebMS-Intra] PublicDoor: a request"],
    static fn (): int => $feedA()['status']);
check('P35 with the door\'s catch narrowed, the same fault escapes as a 500 — the catch is what turns a fault into the identical 404', [$p['applied'], $p['result']], [true, 500]);
$p = $plant('_core/PublicDoor.php', "'id'             => (string) \$row['eventSlug'],", "'id'             => (string) \$row['eventSlug'], 'eventID' => 1,",
    static function () use ($feedA, $answers, $schemaRun): int {
        $r = $feedA();
        file_put_contents($answers . DIRECTORY_SEPARATOR . 'events-planted.json', $r['body']);
        $out = $schemaRun()['code'];
        @unlink($answers . DIRECTORY_SEPARATOR . 'events-planted.json');
        return $out;
    });
check('P36 with a database number added to each event, the schema check over the captured answer fails', [$p['applied'], $p['result']], [true, 1]);

// Round 1 of P4a's check: the new hand-offs and guards, each planted out.
$p = $plant('public_html/error.php', "if (class_exists('Portal\\\\Core\\\\Site', false) === true && \\Portal\\Core\\Site::isDoorRequest() === true) {",
    'if (false) {', static fn (): bool => $signature($http('GET', '/error.php?code=404', ['host' => 'door-a.selftest.test'])) === $ref);
check('P37 with error.php\'s hand-off taken out, a public address draws something other than the door\'s 404', [$p['applied'], $p['result']], [true, false]);
$p = $plant('public_html/api-docs/index.php', 'if (\\Portal\\Core\\Site::isDoorRequest() === true) {', 'if (false) {',
    static fn (): int => $http('GET', '/api-docs/index.php', ['host' => 'door-a.selftest.test'])['status']);
check('P38 with the API description\'s hand-off taken out, it answers 200 on a public address', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "if (\$lifetime > 0 && (\$touched === false || \$touched + \$lifetime < time())) {", 'if (false) {',
    static function () use ($ageOut, $feedA, $cookieOf, $people): int {
        $ageOut(true);
        $s = $feedA('events.json', ['cookie' => $cookieOf($people['tester']['jar'])])['status'];
        $ageOut(false);
        return $s;
    }, 'alpha');
check('P39 with the session-lifetime question taken out, an expired tester session is served on a test copy', [$p['applied'], $p['result']], [true, 200]);
$p = $plant('_core/PublicDoor.php', "'ELSE COALESCE(e.`endDateTime`, e.`startDateTime`) END) >= ' . \$now['sql']",
    "'ELSE COALESCE(e.`endDateTime`, e.`startDateTime`) END) >= ' . \$now['sql'] . ' AND COALESCE(e.`endDateTime`, e.`startDateTime`) >= CAST(NOW() AS DATETIME)'",
    $allDayShown);
check('P40 judging an all-day event by its stored midnight again, it vanishes at the start of its own day', [$p['applied'], $p['result']], [true, []]);
$p = $plant('_core/PublicDoor.php', '| JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT', '',
    static fn (): bool => str_contains($feedA('events.json?limit=50')['body'], '</script>'));
check('P41 with the JSON_HEX_ flags taken out, a live </script> reaches the answer', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_core/Site.php', "if (\$isW === false && \$multi === true && \$mode === self::MODE_PATH", "if (false && \$isW === false && \$multi === true && \$mode === self::MODE_PATH",
    static function () use ($setInstallation, $http, $nameB, $sessionFiles): int {
        $setInstallation('multisite.detectionMode', 'path');
        $before = $sessionFiles();
        $http('GET', '/selftest-door-b/w/' . $nameB . '/events.json');
        $setInstallation('multisite.detectionMode', 'session');
        return $sessionFiles() - $before;
    });
check('P42 with the prefixed /w/ form left to the router, the refusal comes after the session start and leaves a session file', [$p['applied'], ($p['result'] ?? 0) > 0], [true, true]);
$hostFault = ["SELECT h.`siteID`, h.`hostName`, s.`isActive` FROM", "SELECT h.`siteID`, h.`hostNameX`, s.`isActive` FROM"];
$failureState = static function () use ($http, $feedA, $sessionFiles): array {
    $before = $sessionFiles();
    $out    = [];
    // Round 3 of P4a's check: a request carrying a STALE session cookie made
    // an empty session file in this state (Site::preDetect()'s session peek),
    // so the last two carry one, on the members' address and a public one.
    $stale = 'PHPSESSID=' . str_repeat('k', 32);
    foreach ([['/login', 'door-a.selftest.test', null], ['/api-docs/index.php', 'door-b.selftest.test', null],
        ['/api-docs/index.php', 'door-c.selftest.test', null], ['/error.php?code=404', 'door-b.selftest.test', null], ['/login', null, null],
        ['/login', null, $stale], ['/error.php?code=404', 'door-a.selftest.test', $stale]] as [$path, $host, $cookie]) {
        $opt = $host === null ? [] : ['host' => $host];
        if ($cookie !== null) {
            $opt['cookie'] = $cookie;
        }
        $r = $http('GET', $path, $opt);
        $out[] = [$r['status'], $r['body'], $r['headers']['set-cookie'] ?? ''];
    }
    return ['answers' => $out, 'door' => $feedA()['status'], 'session files made' => $sessionFiles() - $before];
};
$tryAgain = [503, "Please try again in a moment.\n", ''];
$p = $plant('_core/PublicWebsiteSetup.php', $hostFault[0], $hostFault[1], $failureState);
check('P43 with the public-address look-up FAILING (a planted database fault), EVERY way in — index.php on a public and on the members\' address, the API description on B\'s and switched-off C\'s address, error.php — gives the same plain "try again": no page, no organisation named, no cookie, and no session file even for a request carrying a stale session cookie; and the door refuses',
    [$p['applied'], $p['result']], [true, ['answers' => [$tryAgain, $tryAgain, $tryAgain, $tryAgain, $tryAgain, $tryAgain, $tryAgain],
        'door' => 404, 'session files made' => 0]]);
/** Several edits across files at once, each found exactly once, then put back byte for byte. */
$plantMany = static function (array $edits, callable $scenario) use ($child, $sep, $startServer, $waitForServer): array {
    $was = [];
    foreach ($edits as [$rel, $from, $to]) {
        $path = $child . $sep . 'web' . $sep . str_replace('/', $sep, $rel);
        $text = $was[$path] ?? (string) file_get_contents($path);
        $was[$path] ??= $text;
        if (substr_count($text, $from) !== 1) {
            echo "      ! PLANT NOT APPLIED: the text was found " . substr_count($text, $from) . " times in {$rel}\n";
            foreach ($was as $p => $t) {
                file_put_contents($p, $t);
            }
            return ['applied' => false, 'result' => null];
        }
        file_put_contents($path, str_replace($from, $to, $text));
    }
    $startServer();
    $waitForServer();
    $result = $scenario();
    foreach ($was as $p => $t) {
        file_put_contents($p, $t);
    }
    $startServer();
    $waitForServer();
    return ['applied' => true, 'result' => $result];
};
$faultEdit = ['_core/PublicWebsiteSetup.php', $hostFault[0], $hostFault[1]];
$p = $plantMany([$faultEdit, ['public_html/index.php', "if (Site::publicAddressUnknown() === true) {\n    PublicDoor::tryAgain();\n}\n", '']],
    static function () use ($http, $sessionFiles): int {
        $before = $sessionFiles();
        $http('GET', '/login', ['host' => 'door-a.selftest.test']);
        return $sessionFiles() - $before;
    });
check('P46 with index.php\'s failure-state answer taken out, the same fault leaves a session file on what may be a public address', [$p['applied'], ($p['result'] ?? 0) > 0], [true, true]);
$p = $plantMany([$faultEdit, ['public_html/error.php', "&& \\Portal\\Core\\Site::publicAddressUnknown() === true) {", '&& false) {']],
    static function () use ($http, $sessionFiles): int {
        $before = $sessionFiles();
        $http('GET', '/error.php?code=404', ['host' => 'door-b.selftest.test']);
        return $sessionFiles() - $before;
    });
check('P47 with error.php\'s failure-state answer taken out, the same fault leaves a session file', [$p['applied'], ($p['result'] ?? 0) > 0], [true, true]);
$p = $plantMany([$faultEdit, ['public_html/api-docs/index.php', 'if (\\Portal\\Core\\Site::publicAddressUnknown() === true) {', 'if (false) {']],
    static fn (): int => $http('GET', '/api-docs/index.php', ['host' => 'door-c.selftest.test'])['status']);
check('P48 with the API description\'s failure-state answer taken out, the same fault draws it (200) on switched-off C\'s address', [$p['applied'], $p['result']], [true, 200]);
$p = $plantMany([$faultEdit, ['_core/Site.php', "        if (\$doorLookupFailed === true) {\n            if (\$multi !== null) {", "        if (false) {\n            if (\$multi !== null) {"]],
    static function () use ($http, $sessionFiles): int {
        $before = $sessionFiles();
        $http('GET', '/login', ['cookie' => 'PHPSESSID=' . str_repeat('m', 32)]);
        return $sessionFiles() - $before;
    });
check('P49 with Site::preDetect() no longer stopping before its session peek in the failure state, a stale session cookie makes a session file', [$p['applied'], ($p['result'] ?? 0) > 0], [true, true]);
$p = (static function () use ($child, $sep, $hostFault, $startServer, $waitForServer, $http): array {
    $site  = $child . $sep . 'web' . $sep . '_core' . $sep . 'Site.php';
    $setup = $child . $sep . 'web' . $sep . '_core' . $sep . 'PublicWebsiteSetup.php';
    $was   = [file_get_contents($site), file_get_contents($setup)];
    $one   = "\$doorLookupFailed = \$door === 'failed';";
    if (substr_count($was[0], $one) !== 1 || substr_count($was[1], $hostFault[0]) !== 1) {
        echo "      ! PLANT NOT APPLIED: P44's text was not found exactly once\n";
        return ['applied' => false, 'result' => null];
    }
    file_put_contents($site, str_replace($one, "\$doorLookupFailed = false;", $was[0]));
    file_put_contents($setup, str_replace($hostFault[0], $hostFault[1], $was[1]));
    $startServer();
    $waitForServer();
    $r = $http('GET', '/login', ['host' => 'door-a.selftest.test']);
    file_put_contents($site, $was[0]);
    file_put_contents($setup, $was[1]);
    $startServer();
    $waitForServer();
    return ['applied' => true, 'result' => [$r['status'], str_contains($r['body'], 'name="password"')]];
})();
check('P44 with the fail-closed rule taken out as well, the same fault puts the sign-in page on a public address', [$p['applied'], $p['result']], [true, [200, true]]);

$p = $plant('_core/PublicWebsite.php', "self::FRAME_PERMITTED_KEY,\n        self::DOOR_TEST_PERMITTED_KEY, self::DOOR_TEST_OPEN_KEY];", 'self::FRAME_PERMITTED_KEY];',
    static fn (): array => array_map(static fn (array $one): bool => $one[0], (array) $askLocks()));
check('P45 with D-087\'s two settings taken out of the editor lock, the general settings editor would store them', [$p['applied'], $p['result']],
    [true, ['public.door.testCopies.permitted' => false, 'public.door.testCopies.open' => false]]);

// ── Tidy up ─────────────────────────────────────────────────────────────────
$db->query("DELETE FROM `tblPublicDoorNames` WHERE `siteID` IN (1, {$siteB}, {$siteC})");
$db->query("DELETE FROM `tblPublicHosts` WHERE `siteID` IN (1, {$siteB}, {$siteC})");
$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'PDS %'");
$db->query("DELETE FROM `tblExternalFeeds` WHERE `name` = 'PDS outside calendar'");
$db->query("DELETE FROM `tblNoticeboardUploads` WHERE `storedName` IN ('{$upA}', '{$upA2}', '{$upB}')");
$db->query("DELETE FROM `tblNoticeboardPosters` WHERE `title` LIKE 'PDS %'");
foreach (['calendar.enabled', 'noticeboard.enabled', 'public.door.testCopies.open', 'public.frame.origins', 'public.events.copiedRule'] as $k) {
    $db->query("DELETE FROM `tblSettings` WHERE `siteID` IN (1, {$siteB}, {$siteC}) AND `settingKey` = '{$k}'");
}
$db->query("DELETE FROM `tblSettings` WHERE `siteID` IN ({$siteB}, {$siteC})");
$db->query("DELETE FROM `tblUserSites` WHERE `siteID` IN ({$siteB}, {$siteC})");
$db->query("DELETE FROM `tblRoles` WHERE `siteID` IN ({$siteB}, {$siteC})");
foreach ($state['people'] as $who) {
    $removeMember((int) $who);
}
$state['people'] = [];
$db->query("DELETE FROM `tblSites` WHERE `siteID` IN ({$siteB}, {$siteC})");
$cleanUp();

$total = $passed + $failed;
echo "\n{$passed} of {$total} checks passed.\n";
if ($failed > 0) {
    exit(1);
}
if ($total !== PDS_EXPECTED_CHECKS) {
    echo "But " . PDS_EXPECTED_CHECKS . " were expected: a block was skipped or ran twice.\n";
    exit(1);
}
exit(0);
