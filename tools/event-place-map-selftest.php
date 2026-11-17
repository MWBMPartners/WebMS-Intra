<?php
// Path: tools/event-place-map-selftest.php
/**
 * -----------------------------------------------------------------------------
 * An event held at somebody's home sends no map request to OpenStreetMap (#1088)
 * -----------------------------------------------------------------------------
 * Until #1088 the event page (`calendar/event`, open to visitors) widened its
 * page rule with the OpenStreetMap tile host and drew a map whenever the event
 * had a position, whoever was looking and whatever anybody's maps choice said.
 * Where an event is held is whatever its organiser typed, and the form
 * already asked "This is someone's home" or "This is a public place" (D-183),
 * but never stored the answer. Now it is stored (`tblEvents.locationNotAHome`,
 * migration 303), and `OutsideServices::allowsEventPlaceMap()` shows a map
 * only for a place marked not anybody's home (D-128) and only when the maps
 * rule allows it for the person looking (D-058, D-072).
 *
 * This test drives the REAL pages over HTTP and reads the database back:
 *
 *   E01 a HOME event with a position: for a member who allows maps, a member
 *       who switched them off and a visitor, the page rule names no tile host
 *       and the page has no map element; the typed address is still shown
 *   E02 the same event saved as PUBLIC: stored 1; the allowing member and the
 *       visitor get the tile host and the map; the member who switched maps
 *       off does not (her own choice)
 *   E03 installation, or organisation, maps set to no: no map for anybody,
 *       public or not
 *   E04 the edit form's radio pair starts from the stored value
 *   E05 an update that did not carry the field keeps the stored value
 *   E06 a position typed by hand (no address look-up) on a home: no map
 *   E07 a repeat's dates, and a date the pattern page adds, carry the mark
 *   E08 migration 303 run twice changes nothing and gives the column
 *       full_schema.sql gives
 *   P   PLANTS, each in the throw-away copy of web/, each proved applied and
 *       each required to turn the check meant to catch it red
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It never reaches OpenStreetMap: it looks at the page rule and the markup
 *     the page sends, which are what make a browser ask.
 *   - It runs ONE web worker, so two people saving at once is not tested.
 *   - Whether the person who ticked "public place" was right is a human fact.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/event-place-map-selftest.php
 *         The database must already hold `web/_sql/full_schema.sql`. It
 *         REFUSES rather than skipping when anything is missing.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1088
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'db' => null, 'users' => [], 'setting' => null];

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
$webFrom = $repo . $sep . 'web';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$state['db'] = $db;
$tables = (int) ($db->query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}
$siteId = (int) ($db->query('SELECT MIN(`siteID`) AS s FROM `tblSites`')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation');
}
const ZONE = 'Europe/London';
const MAP_KEY = 'privacy.outside.memberMap';

// The installation's own maps setting, remembered so this run can put it back.
$orig = $db->query("SELECT `settingValue` FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` = '" . MAP_KEY . "'")->fetch_assoc();
if ($orig === null) {
    refuse('the installation has no ' . MAP_KEY . ' row; load full_schema.sql first');
}
$state['setting'] = (string) $orig['settingValue'];

/** Installation-wide maps setting: 'no' (not allowed), 'off' (allowed, members choose; the shipped default) or 'on'. */
$setInstall = static function (string $v) use ($db): void {
    $s = $db->prepare("UPDATE `tblSettings` SET `settingValue` = ? WHERE `siteID` IS NULL AND `settingKey` = '" . MAP_KEY . "'");
    $s->bind_param('s', $v);
    $s->execute();
    $s->close();
};
/** The organisation's own row: 'no', 'off', 'on', or null for none. */
$setOrg = static function (?string $v) use ($db, $siteId): void {
    $db->query("DELETE FROM `tblSettings` WHERE `siteID` = {$siteId} AND `settingKey` = '" . MAP_KEY . "'");
    if ($v !== null) {
        $s = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, '" . MAP_KEY . "', ?, ?, 0)");
        $s->bind_param('iss', $siteId, $v, $v);
        $s->execute();
        $s->close();
    }
};

/** Remove a test person and every row that names them (the activity log holds links to them). */
$removeUser = static function (mysqli $conn, int $who): void {
    $conn->query("DELETE FROM `tblEvents` WHERE `createdByID` = {$who} OR `submittedByID` = {$who}");
    $rs = $conn->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers'"
    );
    foreach ($rs->fetch_all(MYSQLI_ASSOC) as $row) {
        try {
            $conn->query('DELETE FROM `' . $row['TABLE_NAME'] . '` WHERE `' . $row['COLUMN_NAME'] . "` = {$who}");
        } catch (Throwable $e) {
            // A table whose own children still name the row.
        }
    }
    $conn->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
};

$clearEvents = static function () use ($db): void {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P2C %'");
    $db->query("DELETE FROM `tblEventSeries` WHERE `seriesName` LIKE 'P2C %'");
};

$child = sys_get_temp_dir() . $sep . 'event-place-map-' . bin2hex(random_bytes(6));
$state['child'] = $child;

register_shutdown_function(static function () use (&$state, $clearEvents, $setInstall, $setOrg, $removeUser): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['db'] instanceof mysqli) {
        try {
            $state['db']->query("DELETE r FROM `tblRecurrenceRules` r JOIN `tblEventSeries` s ON s.`seriesID` = r.`seriesID` WHERE s.`seriesName` LIKE 'P2C %'");
            $clearEvents();
            $setOrg(null);
            if ($state['setting'] !== null) {
                $setInstall($state['setting']);
            }
            foreach ($state['users'] as $who) {
                $removeUser($state['db'], (int) $who);
            }
        } catch (Throwable $e) {
            echo "  ! could not clean up fully; remove events named 'P2C %' and users @p2c.selftest.invalid by hand.\n";
        }
    }
    if ($state['child'] !== null && is_dir($state['child']) === true) {
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

// ── A copy of web/, so a planted fault never touches the repository ─────────
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
    "<?php\n// Written by tools/event-place-map-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

$router = <<<'ROUTER'
<?php
// Written by tools/event-place-map-selftest.php for ONE run. Test only.
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

$port = 18990 + random_int(0, 150);

$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc'] = proc_open(
    [
        PHP_BINARY,
        '-d', 'session.save_path=' . $child . $sep . 'sessions',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120',
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

/**
 * One request. `$jar` names a cookie file (a person), or '' for a visitor with
 * no cookies at all. Answers status, redirect, the page rule and the body.
 */
$http = static function (string $method, string $path, array $post = [], string $jar = '') use ($port): array {
    $h = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ['Expect:', 'Accept-Language: en'],
    ]);
    if ($jar !== '') {
        curl_setopt($h, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($h, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($h);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'csp' => '', 'body' => 'no answer: ' . curl_error($h)];
    }
    $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $head = substr((string) $raw, 0, $size);
    return [
        'status'   => (int) curl_getinfo($h, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $m) === 1 ? $m[1] : '',
        'csp'      => preg_match('/^Content-Security-Policy:\s*(.+)$/mi', $head, $m) === 1 ? trim($m[1]) : '',
        'body'     => substr((string) $raw, $size),
    ];
};
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    return preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) === 1 ? $m[1] : '';
};
for ($i = 0, $up = false; $i < 80 && $up === false; $i++) {
    $up = $http('GET', '/login')['status'] > 0;
    $up === false && usleep(250000);
}
if ($up === false) {
    refuse('the test server never answered');
}

// ── The people: an administrator, a member who allows maps, one who does not ─
$password = 'Sel' . bin2hex(random_bytes(8)) . '!9x';
$people   = [];
$makePerson = static function (string $key, bool $admin, ?string $mapsChoice) use ($removeUser, $db, $siteId, $password, &$state, $child, $sep, $http, $tokenOn, &$people): void {
    $email = "p2c-{$key}@p2c.selftest.invalid";
    $old = $db->query("SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = '{$email}'");
    foreach ($old->fetch_all(MYSQLI_ASSOC) as $row) {
        $u = (int) $row['userID'];
        $removeUser($db, $u);
    }
    $isAdmin = $admin === true ? 1 : 0;
    $ins = $db->prepare('INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, 1, ?, ?, NOW())');
    $name = 'P2C ' . $key;
    $ins->bind_param('ssii', $name, $email, $isAdmin, $isAdmin);
    $ins->execute();
    $id = (int) $db->insert_id;
    $ins->close();
    $state['users'][] = $id;
    $db->query("INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES ({$siteId}, {$id}, 1, {$isAdmin}, {$isAdmin})");
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $user = 'p2c-' . $key;
    $acc = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
    $acc->bind_param('iss', $id, $user, $hash);
    $acc->execute();
    $acc->close();
    if ($mapsChoice !== null) {
        $db->query("INSERT INTO `tblUserOutsideServicePref` (`userID`, `serviceKey`, `choice`) VALUES ({$id}, 'memberMap', '{$mapsChoice}')");
    }
    $jar = $child . $sep . 'jar-' . $key . '.txt';
    $token = $tokenOn($http('GET', '/login', [], $jar)['body']);
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => $user, 'password' => $password], $jar);
    if (($in['status'] !== 302 && $in['status'] !== 303) || str_contains($in['location'], 'login') === true) {
        refuse("{$key} could not sign in (status " . $in['status'] . ')');
    }
    $people[$key] = ['id' => $id, 'jar' => $jar];
};
$makePerson('admin', true, null);
$makePerson('v', false, 'on');    // allows maps
$makePerson('x', false, 'off');   // switched maps off
$adminJar = $people['admin']['jar'];

// ── Making and reading events ────────────────────────────────────────────────
$clearEvents();
$setInstall('off');
$setOrg(null);

$day = 0;
/** Save a new event through the real form. Answers its row, or null. */
$make = static function (string $name, ?string $kind, array $extra = []) use ($http, $tokenOn, $adminJar, $db, &$day): ?array {
    $day++;
    $token = $tokenOn($http('GET', '/calendar/manage', [], $adminJar)['body']);
    $date  = sprintf('2032-%02d-%02d', 1 + intdiv($day, 25), 1 + ($day % 25));
    $fields = array_merge([
        'csrf_token' => $token, 'action' => 'create', 'eventName' => $name, 'description' => 'A test.',
        'startDateTime' => $date . 'T10:00', 'endDateTime' => $date . 'T11:00', 'timezone' => ZONE,
        'status' => 'published', 'isPublic' => '1', 'locationName' => 'The test hall',
        'locationAddress' => '12 Typed Street, Testville',
    ], $extra);
    if ($kind !== null) {
        $fields['locationAddressKind'] = $kind;
    }
    $http('POST', '/calendar/manage/save', $fields, $adminJar);
    $q = $db->prepare('SELECT `eventID`, `eventSlug`, `locationNotAHome` FROM `tblEvents` WHERE `eventName` = ? ORDER BY `eventID` LIMIT 1');
    $q->bind_param('s', $name);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return $row;
};
$stored = static function (int $id) use ($db): ?int {
    $r = $db->query('SELECT `locationNotAHome` AS m FROM `tblEvents` WHERE `eventID` = ' . $id)->fetch_assoc();
    return $r === null ? null : (int) $r['m'];
};
/** What one viewer is sent for an event's page. */
$look = static function (string $slug, string $who) use ($http, $people): array {
    $r = $http('GET', '/calendar/event?slug=' . rawurlencode($slug), [], $who === 'visitor' ? '' : $people[$who]['jar']);
    return [
        'status' => $r['status'],
        'tile'   => str_contains($r['csp'], 'tile.openstreetmap.org'),
        'map'    => str_contains($r['body'], 'id="eventMap"'),
        'addr'   => str_contains($r['body'], '12 Typed Street'),
    ];
};
$viewers = ['v', 'x', 'visitor'];
$sees = static function (string $slug) use ($look, $viewers): array {
    $out = [];
    foreach ($viewers as $who) {
        $o = $look($slug, $who);
        $out[$who] = $o['status'] === 200 ? [$o['tile'], $o['map'], $o['addr']] : ['status ' . $o['status']];
    }
    return $out;
};
$coords = ['locationGeoLat' => '51.5007000', 'locationGeoLng' => '-0.1246000'];

// ── E01 a home ──────────────────────────────────────────────────────────────
echo "E01. An event at a home\n";
$home = $make('P2C Home', 'home', $coords);
check('E01a the home event was saved', $home !== null, true);
check('E01b stored as 0 (a home)', $home === null ? null : (int) $home['locationNotAHome'], 0);
$homeSlug = (string) ($home['eventSlug'] ?? '');
check('E01c nobody — member who allows maps, member who does not, visitor — gets the tile host or a map; the address is still written (page rule, map element, address)',
    $sees($homeSlug),
    ['v' => [false, false, true], 'x' => [false, false, true], 'visitor' => [false, false, true]]);
$none = $make('P2C Home no kind', null, $coords);
check('E01d a post that names no kind is a home too (0)', $none === null ? null : (int) $none['locationNotAHome'], 0);

// ── E02 a public place ──────────────────────────────────────────────────────
echo "E02. The same, marked as a public place\n";
$pub = $make('P2C Public', 'public', $coords);
$pubId = (int) ($pub['eventID'] ?? 0);
$pubSlug = (string) ($pub['eventSlug'] ?? '');
check('E02a stored as 1', $pub === null ? null : (int) $pub['locationNotAHome'], 1);
check('E02b the allowing member and the visitor get the tile host and the map; the member who switched maps off gets neither (her own choice)',
    $sees($pubSlug),
    ['v' => [true, true, true], 'x' => [false, false, true], 'visitor' => [true, true, true]]);

// ── E03 the tiers above ─────────────────────────────────────────────────────
echo "E03. The installation or the organisation says no\n";
$setInstall('no');
check('E03a installation says no: no map for anybody, public place or not',
    [$sees($pubSlug), $sees($homeSlug)],
    [
        ['v' => [false, false, true], 'x' => [false, false, true], 'visitor' => [false, false, true]],
        ['v' => [false, false, true], 'x' => [false, false, true], 'visitor' => [false, false, true]],
    ]);
$setInstall('off');
$setOrg('no');
check('E03b organisation says no (installation allows): no map for anybody on the public place',
    $sees($pubSlug), ['v' => [false, false, true], 'x' => [false, false, true], 'visitor' => [false, false, true]]);
$setOrg(null);
check('E03c back to allowed: the public place is mapped again (the checks above were not vacuous)',
    $look($pubSlug, 'visitor')['map'], true);

// ── E04 the form ────────────────────────────────────────────────────────────
echo "E04. The edit form starts from the stored value\n";
$form = static function (int $id) use ($http, $adminJar): string {
    $b = $http('GET', '/calendar/manage?edit=' . $id, [], $adminJar)['body'];
    $pub  = preg_match('/<input[^>]*id="locationAddressKindPublic"[^>]*>/', $b, $p) === 1 ? $p[0] : '';
    $home = preg_match('/<input[^>]*id="locationAddressKindHome"[^>]*>/', $b, $h) === 1 ? $h[0] : '';
    return (str_contains($home, ' checked') ? 'home ' : '') . (str_contains($pub, ' checked') ? 'public' : '');
};
check('E04a a public event: only the public radio is checked', $form($pubId), 'public');
check('E04b a home event: only the home radio is checked', $form((int) ($home['eventID'] ?? 0)), 'home ');

// ── E05 an update without the field ─────────────────────────────────────────
echo "E05. An update that did not carry the field keeps what is stored\n";
$update = static function (int $id, bool $withKind, string $kind = 'home') use ($http, $tokenOn, $adminJar): void {
    $token = $tokenOn($http('GET', '/calendar/manage?edit=' . $id, [], $adminJar)['body']);
    $f = [
        'csrf_token' => $token, 'action' => 'update', 'eventID' => (string) $id, 'eventName' => 'P2C Public',
        'description' => 'Edited.', 'startDateTime' => '2032-01-02T10:00', 'endDateTime' => '2032-01-02T11:00',
        'timezone' => ZONE, 'status' => 'published', 'isPublic' => '1', 'locationName' => 'The test hall',
        'locationAddress' => '12 Typed Street, Testville', 'locationGeoLat' => '51.5007000', 'locationGeoLng' => '-0.1246000',
    ];
    if ($withKind === true) {
        $f['locationAddressKind'] = $kind;
    }
    $http('POST', '/calendar/manage/save', $f, $adminJar);
};
$update($pubId, false);
check('E05a no field posted: still 1', $stored($pubId), 1);
$update($pubId, true, 'home');
check('E05b "home" posted: now 0 (the field arriving is honoured)', $stored($pubId), 0);
$update($pubId, true, 'public');
check('E05c "public" posted: 1 again', $stored($pubId), 1);

// ── E06 a hand-typed position on a home ─────────────────────────────────────
echo "E06. A position typed by hand, on a home\n";
$typed = $make('P2C Typed home', 'home', ['locationAddress' => '', 'locationName' => 'A living room'] + $coords);
$typedSlug = (string) ($typed['eventSlug'] ?? '');
check('E06a no map and no tile host for anybody, though the position came from no address look-up',
    array_map(static fn (array $r): array => [$r[0] ?? null, $r[1] ?? null], $sees($typedSlug)),
    ['v' => [false, false], 'x' => [false, false], 'visitor' => [false, false]]);

// ── E07 a repeat and a pattern-added date ───────────────────────────────────
echo "E07. A series copy and a date the pattern adds carry the mark\n";
$make('P2C Series', 'public', $coords + ['repeatFrequency' => 'weekly', 'repeatCount' => '3']);
$marks = static function (string $name) use ($db): array {
    $q = $db->prepare('SELECT `locationNotAHome` AS m FROM `tblEvents` WHERE `eventName` = ? AND `isDeleted` = 0 ORDER BY `startDateTime`');
    $q->bind_param('s', $name);
    $q->execute();
    $out = array_map(static fn (array $r): int => (int) $r['m'], $q->get_result()->fetch_all(MYSQLI_ASSOC));
    $q->close();
    return $out;
};
check('E07a the form made 3 dates, all marked public', $marks('P2C Series'), [1, 1, 1]);
$seriesId = (int) ($db->query("SELECT `seriesID` AS s FROM `tblEvents` WHERE `eventName` = 'P2C Series' AND `seriesID` IS NOT NULL LIMIT 1")->fetch_assoc()['s'] ?? 0);
$firstStart = (string) ($db->query("SELECT `startDateTime` AS s FROM `tblEvents` WHERE `seriesID` = {$seriesId} ORDER BY `startDateTime` LIMIT 1")->fetch_assoc()['s'] ?? '');
$repattern = static function (int $sid, string $start, string $end, string $count) use ($http, $tokenOn, $adminJar): int {
    $page   = $http('GET', '/calendar/manage/series-repattern?seriesID=' . $sid, [], $adminJar);
    $fields = ['csrf_token' => $tokenOn($page['body']), 'seriesID' => (string) $sid, 'patternStart' => $start,
        'patternEnd' => $end, 'repeatFrequency' => 'weekly', 'repeatCount' => $count];
    $preview = $http('POST', '/calendar/manage/series-repattern', $fields, $adminJar);
    $digest  = preg_match('/name="planDigest" value="([0-9a-f]{64})"/', $preview['body'], $m) === 1 ? $m[1] : '';
    $fields['csrf_token'] = $tokenOn($preview['body']) !== '' ? $tokenOn($preview['body']) : $fields['csrf_token'];
    $fields['confirm']    = '1';
    $fields['planDigest'] = $digest;
    return $http('POST', '/calendar/manage/series-repattern', $fields, $adminJar)['status'];
};
$startLocal = str_replace(' ', 'T', substr($firstStart, 0, 16));
$endLocal   = substr($startLocal, 0, 11) . '11:00';
$repStatus  = $repattern($seriesId, $startLocal, $endLocal, '5');
check('E07b PREMISE: the pattern page applied a change (a redirect)', $repStatus === 302 || $repStatus === 303, true);
check('E07c the series now holds 5 dates, and the two the pattern added carry the mark too', $marks('P2C Series'), [1, 1, 1, 1, 1]);

// ── E08 the migration ───────────────────────────────────────────────────────
echo "E08. Migration 303\n";
$colFacts = static function () use ($db): array {
    $r = $db->query(
        "SELECT `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`, `COLUMN_COMMENT`, `ORDINAL_POSITION` FROM `information_schema`.`COLUMNS` "
        . "WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblEvents' AND `COLUMN_NAME` = 'locationNotAHome'"
    )->fetch_assoc();
    return $r ?? [];
};
$runFile = static function (string $path) use ($db): void {
    $db->multi_query((string) file_get_contents($path));
    do {
        $res = $db->store_result();
        if ($res instanceof mysqli_result) {
            $res->free();
        }
    } while ($db->more_results() === true && $db->next_result() === true);
};
$clearEvents();
$fresh = $colFacts();
$file  = $repo . $sep . 'web' . $sep . '_sql' . $sep . '303_event_place_not_a_home.sql';
check('E08a the migration file exists', is_file($file), true);
$runFile($file);
check('E08b run on a database that already has the column: nothing changes', $colFacts(), $fresh);
$db->query('ALTER TABLE `tblEvents` DROP COLUMN `locationNotAHome`');
check('E08c PREMISE: the column is really gone', $colFacts(), []);
$runFile($file);
check('E08d run on a database without it: the column comes back exactly as full_schema.sql defines it', $colFacts(), $fresh);
$runFile($file);
check('E08e run a second time: nothing changes', $colFacts(), $fresh);
$marked = (int) $db->query("SELECT COUNT(*) AS n FROM `tblMigrations` WHERE `filename` = '303_event_place_not_a_home.sql'")->fetch_assoc()['n'];
check('E08f the migration is recorded once', $marked, 1);

// ── P. Plants, in the throw-away copy ───────────────────────────────────────
echo "P. Each check can go red\n";
$edit = static function (string $rel, string $from, string $to) use ($child, $sep): ?string {
    $path = $child . $sep . 'web' . $sep . str_replace('/', $sep, $rel);
    $orig = (string) file_get_contents($path);
    if (substr_count($orig, $from) !== 1) {
        return null;
    }
    file_put_contents($path, str_replace($from, $to, $orig));
    return $orig;
};
$restore = static function (string $rel, string $orig) use ($child, $sep): void {
    file_put_contents($child . $sep . 'web' . $sep . str_replace('/', $sep, $rel), $orig);
};
$plant = static function (string $label, string $rel, string $from, string $to, callable $probe, $healthy) use ($edit, $restore): void {
    $orig = $edit($rel, $from, $to);
    check("{$label} PREMISE: the fault was applied", $orig !== null, true);
    if ($orig === null) {
        return;
    }
    $got = $probe();
    $restore($rel, $orig);
    check("{$label} the check meant to catch it goes red (the answer differs from the healthy one)", $got !== $healthy, true);
};
$setInstall('off');

// E08 cleared the events, so make the two the plants look at afresh.
$home = $make('P2C Plant home', 'home', $coords);
$pub  = $make('P2C Plant public', 'public', $coords);
$homeSlug = (string) ($home['eventSlug'] ?? '');
$pubSlug  = (string) ($pub['eventSlug'] ?? '');
$pubId    = (int) ($pub['eventID'] ?? 0);
$healthyHome = $sees($homeSlug);
check('P0 PREMISE: the healthy page gives a home no map and a public place one', [$healthyHome['visitor'][1], $sees($pubSlug)['visitor'][1]], [false, true]);
$plant('P1 the page draws the map container whatever was decided', '_apps/calendar/event.php',
    "'showMap' => \$showPlaceMap,", "'showMap' => true,", static fn (): array => $sees($homeSlug), $healthyHome);
$plant('P2 the page rule widened outside the decision', '_apps/calendar/event.php',
    "if (\$showPlaceMap === true) {\n    \$cspImgExtra", "if (true) {\n    \$cspImgExtra", static fn (): array => $sees($homeSlug), $healthyHome);
$healthyPub = $sees($pubSlug);
$plant('P3 the decision ignores who is looking', '_core/OutsideServices.php',
    "self::viewerId(), Site::id()));\n        } catch (\\Throwable \$e) {\n            self::logOnce('whether an event place",
    "null, Site::id()));\n        } catch (\\Throwable \$e) {\n            self::logOnce('whether an event place",
    static fn (): array => $sees($pubSlug), $healthyPub);
$plant('P4 an update ignores whether the field arrived', '_apps/calendar/manage/save.php',
    'if ($kindArrived === true) {', 'if (true) {',
    static function () use ($update, $pubId, $stored): ?int {
        $update($pubId, false);
        $r = $stored($pubId);
        return $r;
    }, 1);
$update($pubId, true, 'public');
$plantSeries = static function () use ($make, $marks): array {
    $make('P2C Plant series', 'public', ['locationGeoLat' => '51.5', 'locationGeoLng' => '-0.12', 'repeatFrequency' => 'weekly', 'repeatCount' => '2']);
    return $marks('P2C Plant series');
};
$plant('P5 the series copy drops the column', '_apps/calendar/manage/save.php',
    "    \$insertColumns[] = 'locationNotAHome';\n    \$insertTypes    .= 'i';\n    \$insertValues[]  = \$notAHomeFlag;\n",
    "    \$notAHomeLater = \$notAHomeFlag;\n",
    $plantSeries, [1, 1]);

echo "\nPassed: {$passed}, failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
