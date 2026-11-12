<?php
// Path: tools/geocoder-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The geocoder's answers, fresh and cached, over real HTTP 📍 (#1034, #1035)
 * -----------------------------------------------------------------------------
 * `Portal\Core\Geocoder::forward()` turns an address into a map position. Until
 * this test NOTHING drove it at all (`grep -rln Geocoder tools/` found no test),
 * because both providers are outside companies at addresses fixed in the class.
 *
 * HOW IT REACHES A "PROVIDER" WITHOUT THE INTERNET
 * It copies `web/` to a folder of its own (as every page test here does) and,
 * in THAT COPY ONLY, changes the two host constants in `Geocoder.php` to point
 * at a stand-in served by PHP on this machine. Nothing else in the copy is
 * changed, and the test REFUSES unless each constant was found exactly once —
 * so if the class is ever restructured, the test stops rather than quietly
 * testing a stand-in for nothing. The portal's code is not given a way to be
 * pointed elsewhere: a setting or a variable that redirected look-ups would be
 * a way to send members' addresses to a server somebody else chose.
 *
 * WHAT IT COVERS
 *   A  a FRESH answer (Nominatim leg) through the real `/geo/lookup` page has
 *      a position, and is written to the cache
 *   B  the same address again comes from the cache, the stand-in is NOT asked,
 *      and the answer is IDENTICAL to the fresh one — one shape (#1033)
 *   C  the same for the Google leg
 *   D  a real caller that STORES: saving a venue with automatic look-up on and
 *      no position typed stores the stand-in's position on the venue
 *   E  answers that are not a real position — empty, not a number, off the
 *      Earth, infinite — are "no answer": nothing cached, and a venue saved
 *      with such an address gets NO position rather than 0,0
 *   F  a bad Google answer still falls through to Nominatim
 *   G  over-reach guard: exactly 0,0 from the provider IS a real place and is
 *      kept
 *   H  a cache row with no coordinates is not served as 0,0; the provider is
 *      asked and the row is overwritten
 *   J  the LONGITUDE is judged in its own right (a good latitude with an
 *      empty, wordy, off-the-Earth or infinite longitude), and every bad
 *      latitude and longitude is ALSO tried on the Google leg alone, with the
 *      key set, so each leg is shown to judge its own answer
 *   K  the too-strict direction: real positions with a negative latitude, a
 *      negative longitude, and the exact edges ±90 / ±180, on both legs, are
 *      kept; just outside the edges (by 0.0000001) is refused
 *   L  "Test connection" on Admin, Integrations, Geocoding really asks a
 *      provider every time it is pressed — it used to be answered from the
 *      cache after the first success, so it said "Connected" without
 *      contacting anybody (#1034 round 3)
 *   I  the country a look-up is limited to is part of the cache key (#1035):
 *      the same address limited to Great Britain and to the US is asked of
 *      the provider twice and cached twice; "GB" and "gb" share one row
 *
 * WHAT #1033 CLAIMED, AND WHY SECTION A EXISTS ANYWAY
 * The issue said a fresh answer had no `lat`/`lng`. Measured before any change,
 * that was not so — a fresh answer from either leg had both, and #1033 was
 * closed as not a fault. The faults that WERE there are #1034 (sections E, F
 * and H) and #1035 (section I), each measured red on the code before the fix
 * (see the commit). Sections A-D stay because they are the only thing that
 * would notice the issue's claim becoming TRUE later.
 *
 * WHAT IT CANNOT DO, said plainly
 *   It does not reach Google or OpenStreetMap, so it cannot notice either
 *   company changing the shape of its answer. The stand-in answers in the
 *   shape each documents today.
 *   It drives ONE worker, so two look-ups at once are untested.
 *   It drives two callers (`/geo/lookup` and a venue save). The other three
 *   (event, organisation, small group) are read, not driven: each asks
 *   `$geocoded !== null` before it reads a position, so "no answer" leaves
 *   them storing nothing.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_geocoder SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/geocoder-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and `proc_open()`. It REFUSES rather than
 *         skipping when anything is missing, because a skipped test reads
 *         exactly like a passing one. It changes three installation-wide
 *         settings (`geo.autoGeocode`, `geo.google.apiKey`,
 *         `geo.nominatim.lastCallAt`) and puts each back as it found it.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1034
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'stub' => null, 'child' => null, 'restore' => null];

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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}

$tables = (int) ($db->query(
    'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

// Every address this test looks up carries this word, so its cache rows and
// venues can be found and removed without touching anybody else's.
const GT_MARK = 'Selftest1033';

// ── The three settings it changes, read first so they can be put back ──────
$settingKeys = ['geo.autoGeocode', 'geo.google.apiKey', 'geo.nominatim.lastCallAt'];
$settingsBefore = [];
foreach ($settingKeys as $key) {
    $st = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
    $st->bind_param('s', $key);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row === null) {
        refuse("the installation-wide setting {$key} is not seeded, so this test cannot switch it");
    }
    $settingsBefore[$key] = $row['settingValue'];
    // An organisation's own row would win over the installation's, and this
    // test changes only the installation's.
    $own = $db->prepare('SELECT COUNT(*) AS n FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NOT NULL');
    $own->bind_param('s', $key);
    $own->execute();
    if ((int) $own->get_result()->fetch_assoc()['n'] > 0) {
        refuse("an organisation has its own {$key} row, which would decide instead of the one this test sets");
    }
    $own->close();
}

$setSetting = static function (string $key, ?string $value) use ($db): void {
    $st = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
    $st->bind_param('ss', $value, $key);
    $st->execute();
    $st->close();
};

/** Remove this test's cache rows and venues. Children of a venue cascade. */
$tidyRows = static function () use ($db): void {
    $like = '%' . GT_MARK . '%';
    $st = $db->prepare('DELETE FROM `tblGeocodeCache` WHERE `queryText` LIKE ?');
    $st->bind_param('s', $like);
    $st->execute();
    $st->close();
    $st = $db->prepare('DELETE FROM `tblVenues` WHERE `venueName` LIKE ?');
    $st->bind_param('s', $like);
    $st->execute();
    $st->close();
};

$state['restore'] = static function () use ($setSetting, $settingsBefore, $tidyRows): void {
    foreach ($settingsBefore as $key => $value) {
        $setSetting($key, $value);
    }
    $tidyRows();
};

$child = sys_get_temp_dir() . $sep . 'geocoder-selftest-' . bin2hex(random_bytes(6));
$state['child'] = $child;

register_shutdown_function(static function () use (&$state): void {
    foreach (['proc', 'stub'] as $which) {
        if (is_resource($state[$which]) === true) {
            proc_terminate($state[$which]);
            proc_close($state[$which]);
        }
    }
    if ($state['restore'] !== null) {
        try {
            ($state['restore'])();
        } catch (Throwable $e) {
            echo 'FAIL — the settings or rows could not be put back: ' . $e->getMessage() . "\n";
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
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($state['child']);
    }
});

// ── A copy of web/ ───────────────────────────────────────────────────────────
mkdir($child, 0700, true);
$copied = 0;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $to = $child . $sep . 'web' . substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    if ($entry->isDir() === true) {
        mkdir($to, 0700, true);
        continue;
    }
    copy($entry->getPathname(), $to);
    $copied++;
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}

// 🔌 SELFTEST_WEB_PORT first, as tools/run-all-selftests.sh gives every page
//    test a port of its own so two never collide; the stand-in takes the next
//    one up. Without it, a port is chosen here.
$portRaw = getenv('SELFTEST_WEB_PORT');
if ($portRaw !== false && $portRaw !== '') {
    if (preg_match('/^\d+$/', (string) $portRaw) !== 1 || (int) $portRaw < 1024 || (int) $portRaw > 65534) {
        refuse('SELFTEST_WEB_PORT must be a number between 1024 and 65534 (the stand-in takes the next port up)');
    }
    $port = (int) $portRaw;
} else {
    $port = 8960 + random_int(0, 200);
}
$stubPort = $port + 1;

// 🔀 Point the COPY's two provider addresses at the stand-in. Each original
//    must be found exactly once, or the test is not testing what it says.
$geoPath = $child . $sep . 'web' . $sep . '_core' . $sep . 'Geocoder.php';
$geoSrc  = (string) file_get_contents($geoPath);
$swaps = [
    "'https://maps.googleapis.com/maps/api/geocode/json'" => "'http://127.0.0.1:{$stubPort}/google'",
    "'https://nominatim.openstreetmap.org'"               => "'http://127.0.0.1:{$stubPort}/nominatim'",
];
foreach ($swaps as $from => $to) {
    $found = substr_count($geoSrc, $from);
    if ($found !== 1) {
        refuse("the copy of Geocoder.php holds {$from} {$found} time(s), not once, so the stand-in cannot be put in its place");
    }
    $geoSrc = str_replace($from, $to, $geoSrc);
}
file_put_contents($geoPath, $geoSrc);

@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/geocoder-selftest.php for ONE run.\nreturn "
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
// Written by tools/geocoder-selftest.php for ONE run. Test only.
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

// ── The stand-in provider ────────────────────────────────────────────────────
// It answers by a WORD in the address asked about, in each provider's own
// shape (Google: numbers in `geometry.location`; Nominatim: TEXT in a list).
// It records only WHICH leg was asked, never the address (the portal's rule
// is that an address is never logged, and a test should not be the exception).
$stub = <<<'STUB'
<?php
declare(strict_types=1);
$uri   = (string) ($_SERVER['REQUEST_URI'] ?? '');
$leg   = str_starts_with($uri, '/google') ? 'google' : 'nominatim';
$asked = strtolower((string) ($_GET['address'] ?? $_GET['q'] ?? ''));
file_put_contents(__DIR__ . '/stub-calls.log', $leg . "\n", FILE_APPEND);
header('Content-Type: application/json');

$pos = ['google' => [51.5007292, -0.1246254], 'nominatim' => ['52.2053', '0.1218']][$leg];
if ($leg === 'nominatim' && strtolower((string) ($_GET['countrycodes'] ?? '')) === 'us') { $pos = ['39.7817', '-89.6501']; }
if (str_contains($asked, 'googlejunk')) { $pos = $leg === 'google' ? ['abc', 'def'] : $pos; }
elseif (str_contains($asked, 'emptyjunk'))  { $pos = ['', '']; }
elseif (str_contains($asked, 'wordjunk'))   { $pos = ['abc', 'def']; }
elseif (str_contains($asked, 'offearth'))   { $pos = $leg === 'google' ? [200, 500] : ['95', '0']; }
elseif (str_contains($asked, 'infinite'))   { $pos = ['1e999', '0']; }
elseif (str_contains($asked, 'nullisland')) { $pos = $leg === 'google' ? [0, 0] : ['0.0000000', '0.0000000']; }

// `pos[<lat>,<lng>]` in the address: answer exactly that, on both legs — or,
// with `gonly` in the address too, on the GOOGLE leg only, so Nominatim gives
// its ordinary place and a Google answer is judged on its own. Google sends
// numbers as JSON numbers (anything that is not a finite number stays text,
// as a broken answer would); Nominatim always sends text.
if (preg_match('/pos\[([^,\]]*),([^\]]*)\]/', $asked, $m) === 1
    && ($leg === 'google' || str_contains($asked, 'gonly') === false)) {
    $pos = [$m[1], $m[2]];
    if ($leg === 'google') {
        $pos = array_map(
            static fn ($v) => (is_numeric($v) === true && is_finite((float) $v) === true) ? (float) $v : $v,
            $pos
        );
    }
}

if ($leg === 'google') {
    echo json_encode(['status' => 'OK', 'results' => [[
        'formatted_address' => 'Stand-in place (google)',
        'geometry' => ['location' => ['lat' => $pos[0], 'lng' => $pos[1]]],
    ]]]);
    exit;
}
echo json_encode([['lat' => $pos[0], 'lon' => $pos[1], 'display_name' => 'Stand-in place (nominatim)']]);
STUB;
file_put_contents($child . $sep . 'stub.php', $stub);
touch($child . $sep . 'stub-calls.log');

/** Which legs the stand-in has been asked, since the log was last emptied. */
$stubCalls = static function () use ($child, $sep): array {
    $raw = trim((string) file_get_contents($child . $sep . 'stub-calls.log'));
    return $raw === '' ? [] : explode("\n", $raw);
};
$stubReset = static function () use ($child, $sep): void {
    file_put_contents($child . $sep . 'stub-calls.log', '');
};

$serverDescriptors = static fn (string $log): array => [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $log, 'a'],
    2 => ['file', $log, 'a'],
];
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';

$state['stub'] = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $stubPort, $child . $sep . 'stub.php'],
    $serverDescriptors($child . $sep . 'stub.log'),
    $pipes,
    $child,
    $env
);
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
    $serverDescriptors($child . $sep . 'server.log'),
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false || is_resource($state['stub']) === false) {
    refuse('could not start PHP\'s web server');
}

$jar = $child . $sep . 'cookies.txt';

/** One request. `CURLOPT_FOLLOWLOCATION` is off: a redirect is a fact here. */
$http = static function (string $method, string $path, array $post = []) use ($port, $jar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '*',
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
    ]);
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

$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) === 1) {
        return $m[1];
    }
    return '';
};

$up = false;
for ($i = 0; $i < 80; $i++) {
    if ($http('GET', '/login')['status'] > 0) {
        $up = true;
        break;
    }
    usleep(250000);
}
if ($up === false) {
    refuse('the test server never answered');
}

// ── An administrator, so the venue page is open to them ─────────────────────
$removeMember = static function (int $who) use ($db): void {
    if ($who <= 0) {
        return;
    }
    $rs = $db->query(
        "SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers' AND COLUMN_NAME = 'userID'"
    );
    while ($row = $rs->fetch_assoc()) {
        $table = (string) $row['TABLE_NAME'];
        try {
            $db->query("DELETE FROM `{$table}` WHERE `userID` = {$who}");
        } catch (Throwable $e) {
            // A table whose parent has already gone, or a different shape.
        }
    }
    $rs->free();
    try {
        $db->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
    } catch (Throwable $e) {
        echo "  ! could not remove the test administrator ({$who}); remove it by hand.\n";
    }
};

$email    = 'geocoder.selftest@selftest.invalid';
$password = 'Geo' . bin2hex(random_bytes(8)) . '!7x';
$old = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
$old->bind_param('s', $email);
$old->execute();
$leftovers = $old->get_result();
while ($row = $leftovers->fetch_assoc()) {
    $removeMember((int) $row['userID']);
}
$old->close();
$tidyRows();

$siteId = (int) ($db->query('SELECT MIN(`siteID`) AS s FROM `tblSites`')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation');
}
$ins = $db->prepare(
    'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `createdAt`) '
    . "VALUES ('Geocoder Test Admin', ?, 1, 1, NOW())"
);
$ins->bind_param('s', $email);
$ins->execute();
$userId = (int) $db->insert_id;
$ins->close();
$db->query("INSERT IGNORE INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES ({$siteId}, {$userId}, 1)");
$hash = password_hash($password, PASSWORD_DEFAULT);
$signInName = 'selftest-geocoder';
$acc = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
$acc->bind_param('iss', $userId, $signInName, $hash);
$acc->execute();
$acc->close();
$prevRestore = $state['restore'];
$state['restore'] = static function () use ($prevRestore, $removeMember, $userId): void {
    $prevRestore();
    $removeMember($userId);
};

$page  = $http('GET', '/login');
$token = $tokenOn($page['body']);
if ($token === '') {
    refuse('the sign-in page carried no form token');
}
$in = $http('POST', '/login', ['csrf_token' => $token, 'username' => $signInName, 'password' => $password]);
if ($in['status'] !== 302 && $in['status'] !== 303) {
    refuse('the test administrator could not sign in (answer ' . $in['status'] . ')');
}

/** A fresh form token: `Auth::verifyCsrf()` replaces it on every acceptance. */
$freshToken = static function () use ($http, $tokenOn): string {
    $p = $http('GET', '/venues/manage');
    $t = $tokenOn($p['body']);
    if ($t === '') {
        refuse('/venues/manage (answer ' . $p['status'] . ') carried no form token');
    }
    return $t;
};

/** Ask the real `/geo/lookup` page. Returns the decoded JSON, or null. */
$lookup = static function (string $address, string $country = '') use ($http, $freshToken): ?array {
    $answer = $http('POST', '/geo/lookup', ['csrf_token' => $freshToken(), 'address' => $address, 'countryCode' => $country]);
    $decoded = json_decode($answer['body'], true);
    return is_array($decoded) === true ? $decoded : null;
};

/**
 * The cache key the PORTAL builds for a forward look-up (#1035). Asked of the
 * class itself through reflection, never worked out here: a test holding its
 * own copy of the rule it tests would pass while the two drifted apart (#647).
 * The class is loaded from the repository; building a key reaches nothing
 * outside it, so no database or start-up is needed.
 */
require_once $repo . $sep . 'web' . $sep . '_core' . $sep . 'Geocoder.php';
foreach (['forwardKeyText', 'cacheKey'] as $method) {
    if (method_exists(\Portal\Core\Geocoder::class, $method) === false) {
        refuse("Geocoder has no {$method}(), so this test cannot ask it which cache key a look-up uses");
    }
}
$portalKey =static function (string $address, string $country): string {
    $text = new ReflectionMethod(\Portal\Core\Geocoder::class, 'forwardKeyText');
    $key  = new ReflectionMethod(\Portal\Core\Geocoder::class, 'cacheKey');
    return (string) $key->invoke(null, 'forward', (string) $text->invoke(null, trim($address), $country !== '' ? $country : null));
};

/** How many forward cache rows hold this address, whatever their key. */
$rowsFor = static function (string $address) use ($db): int {
    $st = $db->prepare("SELECT COUNT(*) AS n FROM `tblGeocodeCache` WHERE `direction` = 'forward' AND `queryText` = ?");
    $st->bind_param('s', $address);
    $st->execute();
    $n = (int) $st->get_result()->fetch_assoc()['n'];
    $st->close();
    return $n;
};

/** What the cache holds for one address and country: null when there is no row. */
$cacheRow = static function (string $address, string $country = '') use ($db, $portalKey): ?array {
    $hash = $portalKey($address, $country);
    $st = $db->prepare('SELECT `latitude`, `longitude`, `provider` FROM `tblGeocodeCache` WHERE `queryHash` = ?');
    $st->bind_param('s', $hash);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row;
};

/** Save a NEW venue through the real handler; return its stored position. */
$saveVenue = static function (string $name, string $line1) use ($http, $freshToken, $db): ?array {
    $answer = $http('POST', '/venues/venue-save', [
        'csrf_token'   => $freshToken(),
        'action'       => 'save',
        'venueID'      => '0',
        'venueName'    => $name,
        'addressLine1' => $line1,
        'city'         => 'Testtown',
        'postcode'     => 'TE1 1ST',
        'countryCode'  => 'GB',
        'timezone'     => 'Europe/London',
        'latitude'     => '',
        'longitude'    => '',
    ]);
    if ($answer['status'] !== 302 && $answer['status'] !== 303) {
        return ['saved' => false, 'status' => $answer['status']];
    }
    $st = $db->prepare('SELECT `latitude`, `longitude`, `geocodeSource` FROM `tblVenues` WHERE `venueName` = ?');
    $st->bind_param('s', $name);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row === null ? ['saved' => false, 'status' => $answer['status']] : $row + ['saved' => true];
};

$setSetting('geo.autoGeocode', 'true');
$setSetting('geo.google.apiKey', '');
$setSetting('geo.nominatim.lastCallAt', '');

// ── A ────────────────────────────────────────────────────────────────────────
echo "A. A FRESH answer (Nominatim) through the real /geo/lookup page\n";
$addrN = GT_MARK . ' Fresh Nominatim Road';
check('A0 premise: nothing is cached for this address yet', $cacheRow($addrN), null);
$stubReset();
$fresh = $lookup($addrN);
check('A1 the stand-in was asked, once, on the Nominatim leg', $stubCalls(), ['nominatim']);
check('A2 the page answered ok', $fresh['ok'] ?? null, true);
check('A3 THE #1033 QUESTION: a fresh answer carries a latitude',
    isset($fresh['lat']) === true ? (float) $fresh['lat'] : null, 52.2053);
check('A4 ...and a longitude', isset($fresh['lng']) === true ? (float) $fresh['lng'] : null, 0.1218);
check('A5 ...from the leg that gave it', $fresh['source'] ?? null, 'nominatim');
check('A6 the cache now holds that position',
    $cacheRow($addrN), ['latitude' => '52.2053000', 'longitude' => '0.1218000', 'provider' => 'nominatim']);

// ── B ────────────────────────────────────────────────────────────────────────
echo "B. The same address again comes from the cache, in the SAME shape\n";
$stubReset();
$cached = $lookup($addrN);
check('B1 the stand-in was NOT asked (so this answer is the cached one)', $stubCalls(), []);
check('B2 ONE SHAPE: the cached answer is identical to the fresh one', $cached, $fresh);

// ── C ────────────────────────────────────────────────────────────────────────
echo "C. The Google leg, fresh and then cached\n";
$setSetting('geo.google.apiKey', 'selftest-not-a-real-key');
$addrG = GT_MARK . ' Fresh Google Road';
$stubReset();
$freshG = $lookup($addrG);
check('C1 the stand-in was asked, once, on the Google leg', $stubCalls(), ['google']);
check('C2 a fresh Google answer carries its position',
    [isset($freshG['lat']) ? (float) $freshG['lat'] : null, isset($freshG['lng']) ? (float) $freshG['lng'] : null],
    [51.5007292, -0.1246254]);
check('C3 ...and says it came from Google', $freshG['source'] ?? null, 'google');
$stubReset();
check('C4 ONE SHAPE: the cached Google answer is identical to the fresh one', $lookup($addrG), $freshG);
check('C5 ...and the stand-in was not asked for it', $stubCalls(), []);
$setSetting('geo.google.apiKey', '');

// ── D ────────────────────────────────────────────────────────────────────────
echo "D. A real caller that STORES: a venue saved with no position typed\n";
$stubReset();
$venue = $saveVenue(GT_MARK . ' Fresh Venue', GT_MARK . ' Fresh Venue Lane');
check('D1 the venue was saved', $venue['saved'] ?? false, true);
check('D2 THE ISSUE\'S "DONE WHEN": the first live look-up stored the stand-in\'s position',
    [$venue['latitude'] ?? null, $venue['longitude'] ?? null], ['52.2053000', '0.1218000']);
check('D3 ...recorded as found by Nominatim', $venue['geocodeSource'] ?? null, 'nominatim');
check('D4 ...and it was a FRESH look-up, not a cached one', $stubCalls(), ['nominatim']);

// ── E ────────────────────────────────────────────────────────────────────────
echo "E. An answer that is not a real position is \"no answer\", never 0,0\n";
$bad = [
    'emptyjunk' => 'empty coordinates',
    'wordjunk'  => 'coordinates that are not numbers',
    'offearth'  => 'a latitude off the Earth',
    'infinite'  => 'an infinite latitude',
];
$n = 0;
foreach ($bad as $word => $what) {
    $n++;
    $addr = GT_MARK . ' ' . $word . ' Street';
    $answer = $lookup($addr);
    check("E{$n}a {$what}: /geo/lookup says not found", [$answer['ok'] ?? null, $answer['reason'] ?? null], [false, 'not_found']);
    // Counted by ADDRESS, not by key, so a row filed under any key at all is seen.
    check("E{$n}b ...and nothing is cached, so it is not served for ever", $rowsFor($addr), 0);
    $v = $saveVenue(GT_MARK . ' Venue ' . $word, $addr);
    // ⚠️ array_key_exists, never `??`: a stored NULL is the answer wanted
    //    here, and `??` would read it as "no such column".
    check("E{$n}c ...and a venue at that address is saved with NO position, not 0,0",
        [
            $v['saved'] ?? false,
            array_key_exists('latitude', $v) === true ? $v['latitude'] : 'missing',
            array_key_exists('longitude', $v) === true ? $v['longitude'] : 'missing',
        ],
        [true, null, null]);
}

// ── F ────────────────────────────────────────────────────────────────────────
echo "F. A bad Google answer still falls through to Nominatim\n";
$setSetting('geo.google.apiKey', 'selftest-not-a-real-key');
$stubReset();
$fall = $lookup(GT_MARK . ' googlejunk Fresh Way');
check('F1 both legs were asked, Google first', $stubCalls(), ['google', 'nominatim']);
check('F2 the answer is Nominatim\'s position', [$fall['source'] ?? null, isset($fall['lat']) ? (float) $fall['lat'] : null], ['nominatim', 52.2053]);
$setSetting('geo.google.apiKey', '');

// ── G ────────────────────────────────────────────────────────────────────────
echo "G. Over-reach guard: exactly 0,0 from the provider is a real place\n";
$zero = $lookup(GT_MARK . ' nullisland Point');
check('G1 it is an answer, not "not found"', $zero['ok'] ?? null, true);
check('G2 ...of 0,0', [isset($zero['lat']) ? (float) $zero['lat'] : null, isset($zero['lng']) ? (float) $zero['lng'] : null], [0.0, 0.0]);
$setSetting('geo.google.apiKey', 'selftest-not-a-real-key');
$stubReset();
$zeroG = $lookup(GT_MARK . ' nullisland Google Point');
check('G3 ...and from Google too: only Google asked, and its 0,0 kept',
    [$stubCalls(), $zeroG['source'] ?? null, isset($zeroG['lat']) ? (float) $zeroG['lat'] : null, isset($zeroG['lng']) ? (float) $zeroG['lng'] : null],
    [['google'], 'google', 0.0, 0.0]);
$setSetting('geo.google.apiKey', '');

// ── H ────────────────────────────────────────────────────────────────────────
echo "H. A cache row with no coordinates is a miss, not 0,0\n";
$addrH = GT_MARK . ' Fresh Hollow Row';
$hashH = $portalKey($addrH, '');
$st = $db->prepare(
    "INSERT INTO `tblGeocodeCache` (`queryHash`, `direction`, `queryText`, `latitude`, `longitude`, `formatted`, `provider`) "
    . "VALUES (?, 'forward', ?, NULL, NULL, NULL, 'nominatim')"
);
$st->bind_param('ss', $hashH, $addrH);
$st->execute();
$st->close();
check('H0 premise: the cache row exists, with no coordinates',
    $cacheRow($addrH), ['latitude' => null, 'longitude' => null, 'provider' => 'nominatim']);
$stubReset();
$hollow = $lookup($addrH);
check('H1 the provider was asked rather than the empty row believed', $stubCalls(), ['nominatim']);
check('H2 the answer is the provider\'s position, not 0,0',
    [isset($hollow['lat']) ? (float) $hollow['lat'] : null, isset($hollow['lng']) ? (float) $hollow['lng'] : null], [52.2053, 0.1218]);
check('H3 ...and the row now holds it', $cacheRow($addrH),
    ['latitude' => '52.2053000', 'longitude' => '0.1218000', 'provider' => 'nominatim']);
// The row started at hitCount 1 and was then overwritten once (cachePut adds
// 1). A row nobody could USE is not a hit, so cacheGet() must not add one.
$hitsH = (int) ($db->query("SELECT `hitCount` FROM `tblGeocodeCache` WHERE `queryHash` = '" . $db->real_escape_string($hashH) . "'")->fetch_assoc()['hitCount'] ?? -1);
check('H4 an unusable row was not counted as a hit (1 at the start, +1 when overwritten)', $hitsH, 2);

/** A lookup's position as two floats, or [null, null]. */
$posOf = static fn (?array $a): array => [
    isset($a['lat']) === true ? (float) $a['lat'] : null,
    isset($a['lng']) === true ? (float) $a['lng'] : null,
];

// ── J ────────────────────────────────────────────────────────────────────────
echo "J. Each coordinate is judged in its own right, on each leg\n";
// A good latitude (52.2) with a bad longitude, then a good longitude with a
// bad latitude. The first set is what the earlier fixtures never tried: every
// one of them broke the LATITUDE, so a check on the latitude alone passed.
$badPairs = [
    'J1' => ['52.2', '',      'an empty longitude'],
    'J2' => ['52.2', 'abc',   'a longitude that is a word'],
    'J3' => ['52.2', '500',   'a longitude off the Earth'],
    'J4' => ['52.2', '-500',  'a negative longitude off the Earth'],
    'J5' => ['52.2', '1e999', 'an infinite longitude'],
    'J6' => ['',     '0.5',   'an empty latitude'],
    'J7' => ['abc',  '0.5',   'a latitude that is a word'],
    'J8' => ['200',  '500',   'both off the Earth'],
    'J9' => ['1e999', '0.5',  'an infinite latitude'],
];
foreach ($badPairs as $id => [$la, $ln, $what]) {
    $addr = GT_MARK . " {$id} pos[{$la},{$ln}]";
    $setSetting('geo.google.apiKey', '');
    $stubReset();
    $a = $lookup($addr);
    check("{$id}a {$what}, from Nominatim: not found, and not cached",
        [$stubCalls(), $a['ok'] ?? null, $a['reason'] ?? null, $rowsFor($addr)], [['nominatim'], false, 'not_found', 0]);
    $setSetting('geo.google.apiKey', 'selftest-not-a-real-key');
    $stubReset();
    $g = $lookup(GT_MARK . " {$id} gonly pos[{$la},{$ln}]");
    check("{$id}b {$what}, from GOOGLE (key set): refused, so Nominatim's place is used",
        [$stubCalls(), $g['source'] ?? null, $posOf($g)], [['google', 'nominatim'], 'nominatim', [52.2053, 0.1218]]);
}
$setSetting('geo.google.apiKey', '');

// ── K ────────────────────────────────────────────────────────────────────────
echo "K. Real positions anywhere on the Earth are kept; just outside it is not\n";
// Every earlier GOOD fixture had a positive latitude between 39 and 53 and a
// longitude near 0, so a rule refusing the southern half of the world, or
// anything east of 10 degrees, passed. These are the too-strict direction.
$goodPairs = [
    'K1' => [-33.8688, 151.2093, 'Sydney: southern and far east'],
    'K2' => [40.7128, -74.006, 'New York: west'],
    'K3' => [-34.6037, -58.3816, 'Buenos Aires: south and west'],
    'K4' => [90.0, 180.0, 'the exact edges 90 and 180'],
    'K5' => [-90.0, -180.0, 'the exact edges -90 and -180'],
    'K6' => [90.0, -180.0, 'the exact edges 90 and -180'],
    'K7' => [-90.0, 180.0, 'the exact edges -90 and 180'],
];
foreach ($goodPairs as $id => [$la, $ln, $what]) {
    $setSetting('geo.google.apiKey', '');
    $stubReset();
    $n = $lookup(GT_MARK . " {$id} pos[{$la},{$ln}]");
    check("{$id}a {$what}, from Nominatim: kept as given",
        [$stubCalls(), $n['source'] ?? null, $posOf($n)], [['nominatim'], 'nominatim', [(float) $la, (float) $ln]]);
    $setSetting('geo.google.apiKey', 'selftest-not-a-real-key');
    $stubReset();
    $g = $lookup(GT_MARK . " {$id} google pos[{$la},{$ln}]");
    check("{$id}b {$what}, from Google: kept as given, and Nominatim never asked",
        [$stubCalls(), $g['source'] ?? null, $posOf($g)], [['google'], 'google', [(float) $la, (float) $ln]]);
}
$setSetting('geo.google.apiKey', '');
$justOutside = [
    'K8'  => ['90.0000001', '0.5', 'a latitude 0.0000001 above 90'],
    'K9'  => ['-90.0000001', '0.5', 'a latitude 0.0000001 below -90'],
    'K10' => ['10.5', '180.0000001', 'a longitude 0.0000001 above 180'],
    'K11' => ['10.5', '-180.0000001', 'a longitude 0.0000001 below -180'],
];
foreach ($justOutside as $id => [$la, $ln, $what]) {
    $a = $lookup(GT_MARK . " {$id} pos[{$la},{$ln}]");
    check("{$id} {$what}: not found", [$a['ok'] ?? null, $a['reason'] ?? null], [false, 'not_found']);
}
$vK = $saveVenue(GT_MARK . ' Venue K south-east', GT_MARK . ' K12 pos[-33.8688,151.2093]');
check('K12 a venue at a southern, eastern position stores it as given',
    [$vK['saved'] ?? false, $vK['latitude'] ?? null, $vK['longitude'] ?? null], [true, '-33.8688000', '151.2093000']);

// ── L ────────────────────────────────────────────────────────────────────────
echo "L. \"Test connection\" asks a provider every time, never the cache\n";
// The fixed address testConnection() looks up. A row for it may already be
// in this database from somewhere else; it is left as found.
$testAddress = 'Westminster, London, United Kingdom';
$testHash    = $portalKey($testAddress, '');
$hadTestRow  = $cacheRow($testAddress) !== null;
$pressTest = static function () use ($http, $freshToken): array {
    return $http('POST', '/admin/integrations/geocoding/test', ['csrf_token' => $freshToken()]);
};
$stubReset();
$press1 = $pressTest();
check('L1 the first press asks Nominatim (no Google key) and comes back to the page',
    [$stubCalls(), $press1['status']], [['nominatim'], 302]);
check('L2 PREMISE: the answer is now cached, so a cache-first look-up would not ask again',
    $cacheRow($testAddress) !== null, true);
$stubReset();
$pressTest();
check('L3 THE POINT: the second press asks the provider again rather than the cache',
    $stubCalls(), ['nominatim']);
$shown = $http('GET', '/admin/integrations/geocoding');
check('L4 ...and the page says which provider answered',
    str_contains($shown['body'], 'via nominatim'), true);
if ($hadTestRow === false) {
    $st = $db->prepare('DELETE FROM `tblGeocodeCache` WHERE `queryHash` = ?');
    $st->bind_param('s', $testHash);
    $st->execute();
    $st->close();
}

// ── I ────────────────────────────────────────────────────────────────────────
echo "I. The country a look-up is limited to is part of the cache key (#1035)\n";
// The stand-in puts a US-limited Nominatim look-up in Springfield, Illinois,
// and any other in Cambridge, so a shared row shows up as the wrong place.
$addrI = GT_MARK . ' Springfield Fresh';
$stubReset();
$gb = $lookup($addrI, 'GB');
$us = $lookup($addrI, 'US');
// Not a premise: with the country left out of the key, the US look-up is
// handed the GB row and this goes red (measured), as well as I2.
check('I1 the two countries get their own places, not the first one asked',
    [isset($gb['lat']) ? (float) $gb['lat'] : null, isset($us['lat']) ? (float) $us['lat'] : null], [52.2053, 39.7817]);
check('I2 THE #1035 QUESTION: the second country was asked of the provider, not served the first one\'s row',
    $stubCalls(), ['nominatim', 'nominatim']);
check('I3 ...so the cache holds one row per country', $rowsFor($addrI), 2);
$stubReset();
$gbAgain = $lookup($addrI, 'gb');
check('I4 GB and gb are one country: the repeat is served from the cache', $stubCalls(), []);
check('I5 ...with the GB answer', $gbAgain, $gb);
$stubReset();
$lookup($addrI);
check('I6 a look-up limited to NO country is asked of the provider and filed as its own row',
    [$stubCalls(), $rowsFor($addrI)], [['nominatim'], 3]);
check('I7 ...the three rows are the three keys the portal builds',
    [$cacheRow($addrI, '') !== null, $cacheRow($addrI, 'gb') !== null, $cacheRow($addrI, 'us') !== null], [true, true, true]);

$errors = trim((string) @file_get_contents($child . $sep . 'php-errors.log'));
check('Z1 the portal wrote nothing to PHP\'s error log while this ran', $errors, '');

echo "\n";
echo ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "It reaches no real provider: a stand-in on this machine answers in each\n";
echo "provider's documented shape, so a change in Google's or OpenStreetMap's own\n";
echo "answers would not be seen here. It drives /geo/lookup and the venue save;\n";
echo "the event, organisation and small-group savers are read, not driven.\n";
exit($failed === 0 ? 0 : 1);
