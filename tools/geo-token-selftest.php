<?php
// Path: tools/geo-token-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A background request that spends the form token hands back the new one (#1134)
 * -----------------------------------------------------------------------------
 * `Auth::verifyCsrf()` replaces the session's one form token every time it
 * accepts one (#768). Four handlers that answer JSON to a script on a page
 * spent it and returned nothing, so the next ordinary form post on the same
 * page was refused:
 *
 *   geo/lookup            the "Look up coordinates" button on eight pages
 *   geo/w3w-suggest       the what3words suggestions, every keystroke burst
 *   privacy/consent       the cookie banner, shown to every NEW visitor
 *   worship/plan/reorder  the second drag of a service plan row
 *
 * THIS TEST DRIVES THE REAL HANDLERS OVER HTTP (one worker, a scratch copy of
 * web/, the same shape as help-pages-selftest.php). For each one it requires:
 *   1. the answer carries a `csrfToken`, non-empty and DIFFERENT from the token
 *      that was sent (for every answer given after the token was spent,
 *      refusals included);
 *   2. a real form posted with that new token is ACCEPTED (the directory
 *      profile save; for the banner, the sign-in form);
 *   3. in a second session the OLD token is REFUSED after the call. Without
 *      this the check would pass on a handler that never rotated anything.
 *
 * WHAT IT CANNOT SEE: whether the page's SCRIPT writes the answer's token into
 * the page. That is JavaScript; `tools/geo-lookup-csrf-selftest.mjs` runs the
 * real script in a real browser. The look-up never reaches the internet: the
 * test pre-fills the look-up cache for one public address.
 *
 * Needs: SELFTEST_DB_NAME (starting selftest_), optionally SELFTEST_DB_HOST /
 * USER / PASS / PORT, SELFTEST_WEB_DIR (an existing scratch parent folder) and
 * SELFTEST_WEB_PORT (default 9664). Run it alone.
 *
 * GEO_TOKEN_PLANT=lookup|w3w|consent|reorder removes the token line from that
 * handler in the SCRATCH copy only, so a section can be seen to go red.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const ADMIN = 934001;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$sep       = DIRECTORY_SEPARATOR;
$repo      = (string) realpath(dirname(__DIR__));
$webSrc    = $repo . $sep . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse('the scratch parent folder and the repository must not contain each other');
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9664 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}
$plant = (string) getenv('GEO_TOKEN_PLANT');

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$env0 = static fn (string $k, string $d): string => getenv($k) !== false ? (string) getenv($k) : $d;
$db   = new mysqli($env0('SELFTEST_DB_HOST', '127.0.0.1'), $env0('SELFTEST_DB_USER', 'root'), $env0('SELFTEST_DB_PASS', ''), $dbName, (int) $env0('SELFTEST_DB_PORT', '3306'));
$db->set_charset('utf8mb4');

$pass = 0;
$fail = 0;
function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail;
    if ($passed === true) {
        $pass++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $fail++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}
function head(string $t): void
{
    echo "\n=== {$t} ===\n";
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
    $r    = $stmt->get_result();
    $rows = $r === false ? [] : $r->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function portAnswers(int $port): bool
{
    $s = @fsockopen('127.0.0.1', $port, $en, $et, 0.5);
    if (is_resource($s) === true) {
        fclose($s);

        return true;
    }

    return false;
}

$state = ['started' => false, 'cleaned' => false, 'proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'plans' => [], 'cacheHash' => '', 'w3w' => []];

function removeUser(): void
{
    q('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['tblLocalAccounts', 'tblUserSites', 'tblUsers'] as $t) {
        q("DELETE FROM `{$t}` WHERE userID = " . ADMIN);
    }
    q('SET FOREIGN_KEY_CHECKS = 1');
}

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
    }
    try {
        foreach ($state['plans'] as $planId) {
            q('DELETE FROM tblServicePlanItems WHERE planID = ?', 'i', [$planId]);
            q('DELETE FROM tblServicePlans WHERE planID = ?', 'i', [$planId]);
        }
        if ($state['cacheHash'] !== '') {
            q('DELETE FROM tblGeocodeCache WHERE queryHash = ?', 's', [$state['cacheHash']]);
        }
        foreach ($state['w3w'] as $key => $value) {
            q('UPDATE tblSettings SET settingValue = ? WHERE siteID IS NULL AND settingKey = ?', 'ss', [$value, $key]);
        }
        removeUser();
        clearSignInThrottle();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.geo-token-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}
register_shutdown_function('cleanup');

/**
 * One real HTTP request. Redirects are not followed. An answer without this
 * run's header is status -1.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null): array
{
    global $port, $state;
    $h = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:', 'Accept-Language: en'],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($h);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($h)];
    }
    $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $head = substr((string) $raw, 0, $size);
    if (preg_match('/^X-Geo-Token-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer from a server this run did not start'];
    }

    return [
        'status'   => (int) curl_getinfo($h, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S*)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $size),
    ];
}
function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}
/** @return array<string,mixed> */
function json(array $answer): array
{
    $d = json_decode($answer['body'], true);

    return is_array($d) === true ? $d : [];
}
function newJar(): string
{
    global $child, $sep;

    return $child . $sep . 'jar-' . bin2hex(random_bytes(4)) . '.txt';
}

// =============================================================================
// 🏗️ The world, the scratch copy and the server
// =============================================================================
$state['started'] = true;
removeUser();
$site = (int) (q('SELECT MIN(siteID) AS s FROM tblSites WHERE isActive = 1')[0]['s'] ?? 0);
if ($site <= 0) {
    refuse('the database has no organisation: load web/_sql/full_schema.sql first');
}
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 1, 1)', 'iss',
    [ADMIN, 'Geo Token Selftest', 'selftest-geo-token@geo-token.selftest.invalid']);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 1, 1)', 'ii', [ADMIN, $site]);
q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [ADMIN, 'selftest-geo-token', $hash]);

// The look-up must not reach the internet: fill the cache for one PUBLIC address
// under the key Geocoder::cacheKey() would use (no country, so '' is the key's country).
$address   = '1 Selftest Road, Testville';
$keyText   = json_encode(['', $address], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$cacheHash = hash('sha256', 'forward|' . mb_strtolower(trim((string) $keyText)));
$state['cacheHash'] = $cacheHash;
q('DELETE FROM tblGeocodeCache WHERE queryHash = ?', 's', [$cacheHash]);
q("INSERT INTO tblGeocodeCache (queryHash, direction, latitude, longitude, provider, addressKind) VALUES (?, 'forward', 51.5, -0.12, 'selftest', 'public')", 's', [$cacheHash]);

// what3words "configured" so the throttled and empty-query answers are reachable.
foreach (['w3w.enabled' => 'true', 'w3w.apiKey' => 'selftest-key-never-sent'] as $key => $value) {
    $old = q('SELECT settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey = ?', 's', [$key]);
    if ($old === []) {
        refuse("the setting {$key} is not seeded");
    }
    $state['w3w'][$key] = (string) $old[0]['settingValue'];
    q('UPDATE tblSettings SET settingValue = ? WHERE siteID IS NULL AND settingKey = ?', 'ss', [$value, $key]);
}

$child = $parent . $sep . 'geo-token-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse('could not make a NEW scratch folder');
}
$state['child'] = $child;
file_put_contents($child . $sep . '.geo-token-selftest-owner', $state['token']);
mkdir($child . $sep . 'web', 0700);
$tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        refuse('web/ holds a link (' . $rel . ')');
    }
    $info->isDir() === true ? mkdir($child . $sep . 'web' . $sep . $rel, 0700) : copy((string) $path, $child . $sep . 'web' . $sep . $rel);
}
mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
$creds = [
    'db_host' => $env0('SELFTEST_DB_HOST', '127.0.0.1'), 'db_user' => $env0('SELFTEST_DB_USER', 'root'),
    'db_pass' => $env0('SELFTEST_DB_PASS', ''), 'db_name' => $dbName, 'db_port' => (int) $env0('SELFTEST_DB_PORT', '3306'),
];
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// 🌱 A planted fault: the named handler, in the SCRATCH copy only, stops
//    returning the token. The repository file is never touched.
if ($plant !== '') {
    $files = ['lookup' => 'geo/lookup.php', 'w3w' => 'geo/w3w-suggest.php', 'consent' => 'privacy/consent.php', 'reorder' => 'worship/plan-reorder.php'];
    if (isset($files[$plant]) === false) {
        refuse('GEO_TOKEN_PLANT must be one of: ' . implode(', ', array_keys($files)));
    }
    $f   = $child . $sep . 'web' . $sep . '_apps' . $sep . str_replace('/', $sep, $files[$plant]);
    $src = (string) file_get_contents($f);
    $cut = preg_replace('/\$body\[\'csrfToken\'\] = Auth::csrfToken\(\);/', '', $src, -1, $n);
    if ($n < 1) {
        refuse("the plant '{$plant}' found no token line to remove");
    }
    file_put_contents($f, $cut);
    echo "PLANT '{$plant}' applied to the scratch copy ({$n} line removed).\n";
}

$router = <<<'ROUTER'
<?php
declare(strict_types=1);
header('X-Geo-Token-Selftest: __TOKEN__');
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
file_put_contents($child . $sep . 'router.php', str_replace('__TOKEN__', $state['token'], $router));

$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc']     = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']],
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
echo "\nA background request that spends the form token returns the new one — self-test (#1134)\n";
echo 'PHP ' . PHP_VERSION . ', database ' . (string) q('SELECT VERSION() AS v')[0]['v'] . ', port ' . $port . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

/**
 * The sign-in page refuses an address after a few failed tries, counted from
 * the activity log. This run fails a sign-in ON PURPOSE (C03, and the planted
 * runs), so the scratch database's records for the test machine's own address
 * are cleared before each sign-in: nothing here is about that limit.
 */
function clearSignInThrottle(): void
{
    q("DELETE FROM tblActivityLogs WHERE visitorIP = '127.0.0.1'");
}

/** Sign in through the real page; returns the jar. */
function signIn(): string
{
    global $password;
    clearSignInThrottle();
    $jar = newJar();
    $a   = req($jar, 'POST', '/login', ['csrf_token' => token(req($jar, 'GET', '/login')['body']), 'username' => 'selftest-geo-token', 'password' => $password]);
    if ($a['status'] !== 302 || str_contains($a['location'], '/login') === true) {
        refuse('signing in did not work (HTTP ' . $a['status'] . ' ' . $a['location'] . ')');
    }

    return $jar;
}
/** A page whose HTML carries the session's form token. */
function pageToken(string $jar): string
{
    return token(req($jar, 'GET', '/directory/my-settings')['body']);
}
/** A real form post. 302 = accepted; the directory save answers 400 "Bad request" to a refused token. */
function saveWith(string $jar, string $tok): array
{
    return req($jar, 'POST', '/directory/save', ['csrf_token' => $tok, 'displayBio' => 'geo token selftest']);
}
function isNewToken(array $d, string $sent): bool
{
    $t = $d['csrfToken'] ?? '';

    return is_string($t) === true && preg_match('/^[0-9a-f]{64}$/', $t) === 1 && $t !== $sent;
}

$jar0 = signIn();
$t    = pageToken($jar0);
ok('S00 premise: the signed-in directory page carries a form token and the plain save accepts one', $t !== '' && saveWith($jar0, $t)['status'] === 302, 'token=' . ($t === '' ? 'none' : 'ok'));

// =============================================================================
head('G. geo/lookup (#1134: the "Look up coordinates" button)');
// =============================================================================
$jar = signIn();
$t0  = pageToken($jar);
$a   = req($jar, 'POST', '/geo/lookup', ['csrf_token' => $t0, 'address' => $address, 'addressKind' => 'public']);
$d   = json($a);
ok('G01 a look-up answered from the cache is ok with the position', ($d['ok'] ?? false) === true && (float) ($d['lat'] ?? 0) === 51.5, substr($a['body'], 0, 200));
ok('G02 that answer carries a NEW csrfToken, different from the one sent', isNewToken($d, $t0), substr($a['body'], 0, 200));
$s = saveWith($jar, (string) ($d['csrfToken'] ?? ''));
ok('G03 a real form posted with the answer\'s token is ACCEPTED', $s['status'] === 302, 'HTTP ' . $s['status']);
$jar = signIn();
$t0  = pageToken($jar);
req($jar, 'POST', '/geo/lookup', ['csrf_token' => $t0, 'address' => $address, 'addressKind' => 'public']);
ok('G04 the OLD token is refused after the look-up (the token really rotated)', saveWith($jar, $t0)['status'] === 400);
$jar = signIn();
$t0  = pageToken($jar);
$d   = json(req($jar, 'POST', '/geo/lookup', ['csrf_token' => $t0]));
ok('G05 the "not_found" refusal (no address) also carries a new token', ($d['reason'] ?? '') === 'not_found' && isNewToken($d, $t0), json_encode($d) ?: '');
$d2 = json(req($jar, 'POST', '/geo/lookup', ['csrf_token' => 'wrong']));
ok('G06 a refused token (not spent) answers "csrf" and hands back NO token', ($d2['reason'] ?? '') === 'csrf' && isset($d2['csrfToken']) === false, json_encode($d2) ?: '');

// =============================================================================
head('W. geo/w3w-suggest (what3words switched on with a stand-in key)');
// =============================================================================
$jar = signIn();
$t0  = pageToken($jar);
$d   = json(req($jar, 'POST', '/geo/w3w-suggest', ['csrf_token' => $t0, 'q' => '']));
ok('W01 an empty query answers ok with a NEW token', ($d['ok'] ?? false) === true && isNewToken($d, $t0), json_encode($d) ?: '');
$t1 = (string) ($d['csrfToken'] ?? '');
$d  = json(req($jar, 'POST', '/geo/w3w-suggest', ['csrf_token' => $t1, 'q' => '']));
ok('W02 a second call at once is THROTTLED, and that refusal carries a new token too', ($d['reason'] ?? '') === 'throttled' && isNewToken($d, $t1), json_encode($d) ?: '');
$s = saveWith($jar, (string) ($d['csrfToken'] ?? ''));
ok('W03 a real form posted with the last answer\'s token is ACCEPTED', $s['status'] === 302, 'HTTP ' . $s['status']);
$jar = signIn();
$t0  = pageToken($jar);
req($jar, 'POST', '/geo/w3w-suggest', ['csrf_token' => $t0, 'q' => '']);
ok('W04 the OLD token is refused after the call', saveWith($jar, $t0)['status'] === 400);
q("UPDATE tblSettings SET settingValue = 'false' WHERE siteID IS NULL AND settingKey = 'w3w.enabled'");
$jar = signIn();
$t0  = pageToken($jar);
$d   = json(req($jar, 'POST', '/geo/w3w-suggest', ['csrf_token' => $t0, 'q' => 'abc']));
ok('W05 the "disabled" answer (what3words off) carries a new token', ($d['reason'] ?? '') === 'disabled' && isNewToken($d, $t0), json_encode($d) ?: '');
q("UPDATE tblSettings SET settingValue = 'true' WHERE siteID IS NULL AND settingKey = 'w3w.enabled'");

// =============================================================================
head('C. privacy/consent (#1134: the cookie banner, then sign in as a new visitor)');
// =============================================================================
// MEASUREMENT: a brand-new visitor opens /login (token T0), presses Accept on
// the banner (the page script posts T0), then signs in. Before the change the
// banner's answer carried no token, so the sign-in form still held T0, which
// the banner had just spent.
$visitor = newJar();
$t0      = token(req($visitor, 'GET', '/login')['body']);
$a       = req($visitor, 'POST', '/privacy/consent', ['csrf_token' => $t0, 'type' => 'cookies', 'decision' => 'accept']);
$d       = json($a);
ok('C01 the banner\'s Accept is recorded (status ok)', ($d['status'] ?? '') === 'ok', substr($a['body'], 0, 200));
clearSignInThrottle();
$login           = req($visitor, 'POST', '/login', ['csrf_token' => $t0, 'username' => 'selftest-geo-token', 'password' => $password]);
$signedInWithOld = $login['status'] === 302 && str_contains($login['location'], '/login') === false;
echo 'MEASURED: signing in with the form\'s ORIGINAL token after pressing Accept: HTTP ' . $login['status']
    . ($login['location'] !== '' ? ' -> ' . $login['location'] : '') . ' => ' . ($signedInWithOld === true ? 'SIGNED IN' : 'REFUSED') . "\n";
ok('C02 the answer carries a NEW csrfToken, different from the one sent', isNewToken($d, $t0), substr($a['body'], 0, 200));
ok('C03 the OLD token (the one on the page) is refused at sign-in after Accept (the token really rotated)', $signedInWithOld === false);
$visitor = newJar();
$t0      = token(req($visitor, 'GET', '/login')['body']);
$d       = json(req($visitor, 'POST', '/privacy/consent', ['csrf_token' => $t0, 'type' => 'cookies', 'decision' => 'reject']));
clearSignInThrottle();
$login   = req($visitor, 'POST', '/login', ['csrf_token' => (string) ($d['csrfToken'] ?? ''), 'username' => 'selftest-geo-token', 'password' => $password]);
ok('C04 "Necessary only" then sign in with the answer\'s token SIGNS IN', $login['status'] === 302 && str_contains($login['location'], '/login') === false, 'HTTP ' . $login['status'] . ' ' . $login['location']);
$visitor = newJar();
$t0      = token(req($visitor, 'GET', '/login')['body']);
$d       = json(req($visitor, 'POST', '/privacy/consent', ['csrf_token' => $t0, 'type' => 'nonsense', 'decision' => 'accept']));
ok('C05 the "bad_request" refusal (after the token was spent) carries a new token', ($d['status'] ?? '') === 'bad_request' && isNewToken($d, $t0), json_encode($d) ?: '');
$d = json(req($visitor, 'POST', '/privacy/consent', ['csrf_token' => 'wrong', 'type' => 'cookies', 'decision' => 'accept']));
ok('C06 a refused token (not spent) answers "csrf_failed" and hands back NO token', ($d['status'] ?? '') === 'csrf_failed' && isset($d['csrfToken']) === false, json_encode($d) ?: '');

// =============================================================================
head('R. worship/plan/reorder (#1134: the second drag)');
// =============================================================================
q('INSERT INTO tblServicePlans (siteID, name, isActive) VALUES (?, ?, 1)', 'is', [$site, 'Geo token selftest plan']);
$planId           = (int) $db->insert_id;
$state['plans'][] = $planId;
$items            = [];
foreach ([1, 2, 3] as $n) {
    q("INSERT INTO tblServicePlanItems (planID, sortOrder, itemType, slideTitle) VALUES (?, ?, 'text', ?)", 'iis', [$planId, $n, 'Item ' . $n]);
    $items[] = (int) $db->insert_id;
}
$jar = signIn();
$t0  = pageToken($jar);
$d1  = json(req($jar, 'POST', '/worship/plan/reorder', ['csrf_token' => $t0, 'planID' => $planId, 'items' => array_reverse($items)]));
ok('R01 the first drag is saved (ok) and the answer carries a NEW token', ($d1['ok'] ?? false) === true && isNewToken($d1, $t0), json_encode($d1) ?: '');
$d2 = json(req($jar, 'POST', '/worship/plan/reorder', ['csrf_token' => (string) ($d1['csrfToken'] ?? ''), 'planID' => $planId, 'items' => $items]));
ok('R02 the SECOND drag, using the first answer\'s token, is saved too', ($d2['ok'] ?? false) === true && isNewToken($d2, (string) ($d1['csrfToken'] ?? '')), json_encode($d2) ?: '');
$order = array_map(static fn (array $r): int => (int) $r['itemID'], q('SELECT itemID FROM tblServicePlanItems WHERE planID = ? ORDER BY sortOrder', 'i', [$planId]));
ok('R03 the stored order is the second drag\'s', $order === $items, json_encode($order) ?: '');
$s = saveWith($jar, (string) ($d2['csrfToken'] ?? ''));
ok('R04 a real form posted with the last answer\'s token is ACCEPTED', $s['status'] === 302, 'HTTP ' . $s['status']);
$jar = signIn();
$t0  = pageToken($jar);
req($jar, 'POST', '/worship/plan/reorder', ['csrf_token' => $t0, 'planID' => $planId, 'items' => $items]);
ok('R05 the OLD token is refused after a drag', saveWith($jar, $t0)['status'] === 400);
$jar = signIn();
$t0  = pageToken($jar);
$bad = [
    'input'    => ['planID' => 0, 'items' => $items],
    'plan'     => ['planID' => 99999999, 'items' => $items],
    'empty'    => ['planID' => $planId, 'items' => ['x']],
    'mismatch' => ['planID' => $planId, 'items' => [$items[0], 99999999]],
];
$tok = $t0;
foreach ($bad as $why => $fields) {
    $d = json(req($jar, 'POST', '/worship/plan/reorder', ['csrf_token' => $tok] + $fields));
    ok("R06 the '{$why}' refusal (token already spent) carries a new token", ($d['error'] ?? '') === $why && isNewToken($d, $tok), json_encode($d) ?: '');
    $tok = (string) ($d['csrfToken'] ?? '');
}
// forbidden: a signed-in person who is neither an administrator nor a coordinator.
q('UPDATE tblUsers SET isAdmin = 0, isRootAdmin = 0 WHERE userID = ' . ADMIN);
q('UPDATE tblUserSites SET isSiteAdmin = 0, isSiteRootAdmin = 0 WHERE userID = ' . ADMIN);
$jar = signIn();
$t0  = pageToken($jar);
$d   = json(req($jar, 'POST', '/worship/plan/reorder', ['csrf_token' => $t0, 'planID' => $planId, 'items' => $items]));
ok("R06 the 'forbidden' refusal (token already spent) carries a new token", ($d['error'] ?? '') === 'forbidden' && isNewToken($d, $t0), json_encode($d) ?: '');

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
