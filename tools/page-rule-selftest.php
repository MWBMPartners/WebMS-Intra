<?php
// Path: tools/page-rule-selftest.php
/**
 * The page rule and the referrer setting, read off real pages over HTTP (#1092 part, #1095).
 *
 *   A. Referrer-Policy: a page drawn through header.php sends exactly ONE, and it is the
 *      setting's value (#1095). A value that is not one of the eight standard tokens gets the
 *      default, never nothing. P3 plants the old fixed header back and must go red.
 *   B. /live: the page rule admits the player's own host (YouTube, Vimeo, Twitch) and not one
 *      the slot did not name. P4 plants the missing $cspFrameExtra and must go red.
 *   C. Livestream::frameOriginFor() answers only an https origin.
 *   D. The robot check (#1092): for each provider, the pages that draw its widget admit that
 *      provider's hosts in the page rule, and NO other provider's. P1 drops the append in
 *      header.php; P2 makes the class answer a second provider's hosts.
 *   E. The same, asked of a real browser: tools/page-rule-browser-selftest.mjs is run once per
 *      provider against this test's own server (needs node, a Chromium-family browser).
 *
 * Needs: SELFTEST_DB_NAME (selftest_*) holding full_schema.sql, curl, proc_open.
 * WHAT IT CANNOT DO: it reads the header; it does not prove a browser then plays the video.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 */

declare(strict_types=1);

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null];

function refuse(string $why): never
{
    fwrite(STDERR, "REFUSED TO RUN: {$why}\n");
    exit(1);
}

function ok(string $id, string $words): void
{
    global $passed;
    $passed++;
    echo "PASS — {$id} {$words}\n";
}

function bad(string $id, string $words, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "FAIL — {$id} {$words}\n";
    if ($detail !== '') {
        foreach (explode("\n", $detail) as $line) {
            echo "         {$line}\n";
        }
    }
}

// 🛡️ Refusals first, so a half-set-up run never looks like a pass.
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("PHP here has no {$needed}()");
    }
}

$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
$port   = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9671;

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (\Throwable $e) {
    refuse('cannot reach the database: ' . $e->getMessage());
}
$db->set_charset('utf8mb4');

$rows = $db->query("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = '" . $db->real_escape_string($dbName) . "'");
$tableCount = (int) ($rows !== false ? ($rows->fetch_assoc()['n'] ?? 0) : 0);
if ($tableCount < 100) {
    refuse("the database holds only {$tableCount} tables — load web/_sql/full_schema.sql first");
}

// 🗂️ A copy of web/, so a planted fault never touches the repository.
$child = sys_get_temp_dir() . $sep . 'page-rule-selftest-' . bin2hex(random_bytes(6));
$state['child'] = $child;

register_shutdown_function(static function () use (&$state, $sep): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_dir($state['child']) === true) {
        // Deliberately a plain recursive delete of a folder THIS run made, under
        // the system temp folder, whose name holds random bytes.
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

mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/page-rule-selftest.php for ONE run; deleted with its folder.\nreturn "
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
// Written by tools/page-rule-selftest.php for ONE run (deleted with its folder). Test only.
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
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('could not start PHP\'s web server');
}

/** Fetch a page and give back its body, or '' when it could not be fetched. */
$get = static function (string $path) use ($port): string {
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    // No curl_close(): it has had no effect since PHP 8.0 and is deprecated
    // in 8.5, where it printed a warning for every page this test fetched.
    return is_string($body) === true ? $body : '';
};

// Wait for the server, by asking it for a page rather than by sleeping blind.
$up = false;
for ($i = 0; $i < 60; $i++) {
    if ($get('/login') !== '') {
        $up = true;
        break;
    }
    usleep(250000);
}
if ($up === false) {
    refuse('the web server never answered on port ' . $port);
}

/** The content of one meta/link tag, or null when the tag is absent. */
$tag = static function (string $html, string $kind, string $name): ?string {
    if ($kind === 'canonical') {
        $found = preg_match('#<link\s+rel="canonical"\s+href="([^"]*)"#i', $html, $m);
        return $found === 1 ? $m[1] : null;
    }
    $attr  = $kind === 'property' ? 'property' : 'name';
    $found = preg_match('#<meta\s+' . $attr . '="' . preg_quote($name, '#') . '"\s+content="([^"]*)"#i', $html, $m);
    return $found === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
};

$setSetting = static function (string $key, string $value) use ($db): void {
    $stmt = $db->prepare(
        'INSERT INTO tblSettings (siteID, settingKey, settingValue, isSensitive, defaultValue) '
        . "VALUES (NULL, ?, ?, 0, '') "
        . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)'
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
};

$clearSetting = static function (string $key) use ($db): void {
    $stmt = $db->prepare('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
};

/** Fetch headers + body: [code, headers (lowercase name => list), body]; optional cookie jar. */
$fetch = static function (string $path, array $post = [], ?string $jar = null) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    $hdrs = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$hdrs): int {
            if (str_starts_with($line, 'HTTP/') === true) {
                $hdrs = [];
            }
            if (str_contains($line, ':') === true) {
                [$n, $v] = explode(':', $line, 2);
                $hdrs[strtolower(trim($n))][] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $hdrs, is_string($body) === true ? $body : ''];
};

$copyFile = static function (string $rel) use ($child, $sep): string {
    return $child . $sep . 'web' . $sep . str_replace('/', $sep, $rel);
};
/** Plant a fault in the COPY; answers the original text so it can be put back byte-identically. */
$plant = static function (string $rel, string $from, string $to) use ($copyFile): ?string {
    $p = $copyFile($rel);
    $orig = (string) file_get_contents($p);
    if (substr_count($orig, $from) !== 1) {
        return null;
    }
    file_put_contents($p, str_replace($from, $to, $orig));
    return $orig;
};
$restore = static function (string $rel, string $orig) use ($copyFile): void {
    file_put_contents($copyFile($rel), $orig);
};

echo "=== A. The referrer setting is the one that reaches the browser (#1095) ===\n";
$setSetting('site.url', 'https://portal.example.test');
$refs = static function () use ($fetch): array {
    [, $h] = $fetch('/help');
    return $h['referrer-policy'] ?? [];
};

$clearSetting('portal.headers.referrer_policy');
$r = $refs();
$r === ['strict-origin-when-cross-origin'] ? ok('A01', 'no setting: exactly one header, the default') : bad('A01', 'default wrong', var_export($r, true));
$setSetting('portal.headers.referrer_policy', 'no-referrer');
$r = $refs();
$r === ['no-referrer'] ? ok('A02', 'setting no-referrer: exactly one header, that value') : bad('A02', 'setting not honoured on a header.php page', var_export($r, true));
$setSetting('portal.headers.referrer_policy', 'No-Referrer ');
$r = $refs();
$r === ['no-referrer'] ? ok('A03', 'capitals and spaces are tolerated') : bad('A03', 'case/space', var_export($r, true));
$setSetting('portal.headers.referrer_policy', 'nothing-like-a-policy');
$r = $refs();
$r === ['strict-origin-when-cross-origin'] ? ok('A04', 'a junk value gets the default, not nothing') : bad('A04', 'junk value', var_export($r, true));
$setSetting('portal.headers.referrer_policy', '');
$r = $refs();
$r === [] ? ok('A05', 'an empty value still means send none') : bad('A05', 'empty value', var_export($r, true));
$setSetting('portal.headers.referrer_policy', 'no-referrer');
$o = $plant('_core/templates/header.php', '// (#1095) No Referrer-Policy is sent here.', "header('Referrer-Policy: strict-origin-when-cross-origin');\n// (#1095) No Referrer-Policy is sent here.");
if ($o === null) {
    bad('A06', 'plant P3 did not apply');
} else {
    $r = $refs();
    $restore('_core/templates/header.php', $o);
    $r !== ['no-referrer'] ? ok('A06', 'P3: the fixed header put back is caught (got ' . json_encode($r) . ')') : bad('A06', 'P3 not caught');
    $r = $refs();
    $r === ['no-referrer'] ? ok('A07', 'P3 restored: green again') : bad('A07', 'not restored', json_encode($r));
}

echo "\n=== B. /live admits the player's host ===\n";
$user  = 982301;
$pw    = bin2hex(random_bytes(12)) . 'Aa1!';
$uname = 'selftest-pagerule-' . bin2hex(random_bytes(3));
$jar   = $child . $sep . 'cookies.txt';
$tidy = static function () use ($db, $user): void {
    $db->query('DELETE FROM `tblLivestreamChannel` WHERE `name` LIKE \'pagerule-%\'');
    $links = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers'");
    foreach ($links->fetch_all(MYSQLI_ASSOC) as $l) {
        $db->query('DELETE FROM `' . str_replace('`', '', (string) $l['TABLE_NAME']) . '` WHERE `' . str_replace('`', '', (string) $l['COLUMN_NAME']) . '` = ' . $user);
    }
    $db->query('DELETE FROM `tblUsers` WHERE `userID` = ' . $user);
};
$tidy();
$stmt = $db->prepare("INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, 'Page-rule member', ?, 1, 0, 0)");
$em = $uname . '@selftest.invalid';
$stmt->bind_param('is', $user, $em);
$stmt->execute();
$stmt = $db->prepare('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, 0, 0)');
$stmt->bind_param('i', $user);
$stmt->execute();
$hash = password_hash($pw, PASSWORD_DEFAULT);
$stmt = $db->prepare('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)');
$stmt->bind_param('iss', $user, $uname, $hash);
$stmt->execute();
// The Livestream app is seeded OFF; a test has to put the portal into the state it means to test.
$setSetting('livestream.enabled', '1');
[, , $login] = $fetch('/login', [], $jar);
preg_match('#name="csrf_token"\s+value="([^"]+)"#', $login, $cm);
$fetch('/login', ['csrf_token' => $cm[1] ?? '', 'username' => $uname, 'password' => $pw], $jar);

/** Put ONE channel live all day today, on the organisation's clock; answers the frame-src sources, or null. */
$liveFrame = static function (string $platform, string $id) use ($db, $fetch, $jar): ?array {
    $db->query('DELETE FROM `tblLivestreamChannel` WHERE `name` LIKE \'pagerule-%\'');
    $zone = (string) ($db->query('SELECT `timezone` FROM `tblSites` WHERE `siteID` = 1')->fetch_assoc()['timezone'] ?? '');
    if ($zone === '') {
        $zone = 'UTC';
    }
    $dow  = (int) (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('w');
    $name = 'pagerule-' . $platform;
    $stmt = $db->prepare('INSERT INTO `tblLivestreamChannel` (`siteID`, `name`, `platform`, `channelOrVideoId`) VALUES (1, ?, ?, ?)');
    $stmt->bind_param('sss', $name, $platform, $id);
    $stmt->execute();
    $cid  = (int) $db->insert_id;
    $stmt = $db->prepare("INSERT INTO `tblLivestreamSchedule` (`channelID`, `dayOfWeek`, `startTime`, `endTime`, `timezone`, `isActive`) VALUES (?, ?, '00:00:00', '23:59:59', ?, 1)");
    $stmt->bind_param('iis', $cid, $dow, $zone);
    $stmt->execute();
    [$code, $h, $body] = $fetch('/live', [], $jar);
    if ($code !== 200 || str_contains($body, '<iframe') === false) {
        return null;
    }
    foreach ($h['content-security-policy'] ?? [] as $csp) {
        if (preg_match('/frame-src ([^;]*)/', $csp, $m) === 1) {
            return preg_split('/\s+/', trim($m[1])) ?: [];
        }
    }
    return null;
};
$f = $liveFrame('youtube', 'abc123');
($f !== null && in_array('https://www.youtube.com', $f, true) === true) ? ok('B01', 'YouTube slot: frame-src admits https://www.youtube.com') : bad('B01', 'YouTube host missing', json_encode($f));
($f !== null && in_array('https://challenges.cloudflare.com', $f, true) === true) ? ok('B02', 'the robot-check host is still there') : bad('B02', 'cloudflare lost', json_encode($f));
$f = $liveFrame('vimeo', '12345');
($f !== null && in_array('https://player.vimeo.com', $f, true) === true && in_array('https://www.youtube.com', $f, true) === false) ? ok('B03', 'Vimeo slot: admits player.vimeo.com and NOT YouTube') : bad('B03', 'vimeo', json_encode($f));
$f = $liveFrame('twitch', 'chan');
($f !== null && in_array('https://player.twitch.tv', $f, true) === true) ? ok('B04', 'Twitch slot: admits player.twitch.tv') : bad('B04', 'twitch', json_encode($f));
$o = $plant('_apps/live/index.php', '$cspFrameExtra = Livestream::frameOriginFor(', "\$cspFrameExtra = (static fn (?string \$u): string => '')(");
if ($o === null) {
    bad('B05', 'plant P4 did not apply');
} else {
    $f = $liveFrame('youtube', 'abc123');
    $restore('_apps/live/index.php', $o);
    ($f !== null && in_array('https://www.youtube.com', $f, true) === false) ? ok('B05', 'P4: dropping $cspFrameExtra is caught') : bad('B05', 'P4 not caught', json_encode($f));
    $f = $liveFrame('youtube', 'abc123');
    ($f !== null && in_array('https://www.youtube.com', $f, true) === true) ? ok('B06', 'P4 restored: green again') : bad('B06', 'not restored');
}
$tidy();

echo "\n=== C. frameOriginFor ===\n";
require_once $repo . $sep . 'web' . $sep . '_core' . $sep . 'Livestream.php';
$cases = [['https://www.youtube.com/embed/x?y=1', 'https://www.youtube.com'], ['http://a.example/x', ''], ['javascript:alert(1)', ''], ['', ''], [null, '']];
foreach ($cases as $i => [$in, $want]) {
    $got = \Portal\Core\Livestream::frameOriginFor($in);
    $got === $want ? ok('C0' . ($i + 1), 'frameOriginFor(' . json_encode($in) . ') = ' . json_encode($want)) : bad('C0' . ($i + 1), 'wrong', $got);
}


echo "\n=== D. The robot check's hosts are admitted for the ACTIVE provider only (#1092) ===\n";
/** Does one CSP source expression admit this https URL? scheme + host, optional leading *. wildcard, no path. */
$admits = static function (string $source, string $url): bool {
    $u = parse_url($url);
    if (is_array($u) === false || ($u['scheme'] ?? '') !== 'https' || isset($u['host']) === false) {
        return false;
    }
    if (preg_match('#^https://(\*\.)?([a-z0-9.-]+)$#i', $source, $m) !== 1) {
        return false;
    }
    $host = strtolower($u['host']);
    $want = strtolower($m[2]);
    return $m[1] === '' ? $host === $want : (str_ends_with($host, '.' . $want) === true);
};
/** The sources of one directive of the page's rule, or null when the directive is absent. */
$directive = static function (array $h, string $name): ?array {
    foreach ($h['content-security-policy'] ?? [] as $csp) {
        foreach (explode(';', $csp) as $part) {
            $words = preg_split('/\s+/', trim($part)) ?: [];
            if (($words[0] ?? '') === $name) {
                return array_slice($words, 1);
            }
        }
    }
    return null;
};
$allows = static function (?array $sources, string $url) use ($admits): bool {
    foreach ($sources ?? [] as $src) {
        if ($admits($src, $url) === true) {
            return true;
        }
    }
    return false;
};
$keyNames = ['turnstile', 'recaptcha', 'hcaptcha'];
$setProvider = static function (?string $which) use ($keyNames, $setSetting, $clearSetting): void {
    foreach ($keyNames as $k) {
        $clearSetting('auth.' . $k . '.siteKey');
        $clearSetting('auth.' . $k . '.secretKey');
    }
    $clearSetting('auth.captcha.priority');
    if ($which !== null) {
        $setSetting('auth.' . $which . '.siteKey', 'selftest-site-key-' . $which);
        $setSetting('auth.' . $which . '.secretKey', 'selftest-secret-key-' . $which);
    }
};
// Pages that draw a widget through header.php and answer anybody: the public decision card and
// the public event suggestion form (open to visitors, robot check required, both by default).
$setSetting('calendar.publicSubmit.enabled', 'true');
$setSetting('calendar.publicSubmit.allowAnonymous', 'true');
$setSetting('calendar.publicSubmit.requireCaptcha', 'true');
$widgetPages = ['/decision-card', '/calendar/submit'];
// What each provider's widget loads: script URL, frame URL, and (reCAPTCHA) its second script host.
$loads = [
    'turnstile' => ['https://challenges.cloudflare.com/turnstile/v0/api.js', 'https://challenges.cloudflare.com/cdn-cgi/challenge-platform/h/b/x'],
    'recaptcha' => ['https://www.google.com/recaptcha/api.js', 'https://www.google.com/recaptcha/api2/anchor', 'https://www.gstatic.com/recaptcha/releases/x/recaptcha__en.js'],
    'hcaptcha'  => ['https://js.hcaptcha.com/1/api.js', 'https://newassets.hcaptcha.com/captcha/v1/x/static/hcaptcha.html', 'https://hcaptcha.com/checksiteconfig'],
];
$collect = static function (string $path) use ($fetch): array {
    [, $h, $body] = $fetch($path);
    return [$h, $body];
};
$n = 0;
$nextId = static function () use (&$n): string {
    $n++;
    return 'D' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
};
$ownHost = ['turnstile' => 'cloudflare', 'recaptcha' => 'google.com/recaptcha', 'hcaptcha' => 'hcaptcha'];
foreach ($keyNames as $prov) {
    $setProvider($prov);
    foreach ($widgetPages as $page) {
        [$h, $body] = $collect($page);
        $id = $nextId();
        preg_match_all('#<script[^>]+src="(https://[^"]+)"#i', $body, $mm);
        $own = array_values(array_filter($mm[1], static fn (string $s): bool => str_contains($s, $ownHost[$prov])));
        if ($own === []) {
            bad($id, "{$prov} on {$page}: the page drew no {$prov} script (premise)", substr($body, 0, 160));
            continue;
        }
        $all = true;
        foreach ($own as $src) {
            if ($allows($directive($h, 'script-src'), $src) === false) {
                $all = false;
            }
        }
        $all === true ? ok($id, "{$prov} on {$page}: the widget's script is admitted by script-src") : bad($id, "{$prov} on {$page}: script refused by script-src", json_encode($directive($h, 'script-src')));
    }
}
// Every address a provider's widget goes on to load is admitted for it, in the right directive.
foreach ($keyNames as $prov) {
    $setProvider($prov);
    [$h] = $collect('/decision-card');
    $id = $nextId();
    $wants = [];
    foreach ($loads[$prov] as $i => $url) {
        $wants[] = [($i === 0 || str_ends_with($url, '.js') === true) ? 'script-src' : 'frame-src', $url];
    }
    if ($prov === 'recaptcha') {
        $wants[] = ['connect-src', 'https://www.google.com/recaptcha/api2/clr'];
    }
    if ($prov === 'hcaptcha') {
        $wants[] = ['style-src', 'https://newassets.hcaptcha.com/x.css'];
        $wants[] = ['connect-src', 'https://hcaptcha.com/checksiteconfig'];
    }
    $why = [];
    foreach ($wants as [$dir, $url]) {
        if ($allows($directive($h, $dir), $url) === false) {
            $why[] = "{$dir} refuses {$url}";
        }
    }
    $why === [] ? ok($id, "{$prov}: every address its widget loads is admitted") : bad($id, "{$prov}: something its widget loads is refused", implode("\n", $why));
}
// The inactive providers' hosts are ABSENT: only what the provider in use needs.
$foreign = [
    'turnstile' => ['https://www.gstatic.com/x', 'https://recaptcha.google.com/x', 'https://newassets.hcaptcha.com/x', 'https://hcaptcha.com/x', 'https://www.google.com/recaptcha/api.js'],
    'recaptcha' => ['https://newassets.hcaptcha.com/x', 'https://hcaptcha.com/x', 'https://js.hcaptcha.com/x'],
    'hcaptcha'  => ['https://www.gstatic.com/x', 'https://recaptcha.google.com/x', 'https://www.google.com/recaptcha/api.js'],
];
$foreignCheck = static function (string $prov) use ($setProvider, $collect, $directive, $allows, $foreign): array {
    $setProvider($prov);
    [$h] = $collect('/decision-card');
    $bad = [];
    foreach ($foreign[$prov] as $url) {
        foreach (['script-src', 'frame-src', 'style-src', 'connect-src'] as $dir) {
            if ($allows($directive($h, $dir), $url) === true) {
                $bad[] = "{$dir} admits {$url}";
            }
        }
    }
    return $bad;
};
foreach ($keyNames as $prov) {
    $id  = $nextId();
    $bad = $foreignCheck($prov);
    $bad === [] ? ok($id, "{$prov} active: no other provider's host is in the page rule") : bad($id, "{$prov} active but other hosts admitted", implode("\n", $bad));
}
// No provider: nothing from any of them.
$setProvider(null);
[$h] = $collect('/help');
$id   = $nextId();
$leak = [];
foreach (['https://www.gstatic.com/x', 'https://recaptcha.google.com/x', 'https://hcaptcha.com/x', 'https://newassets.hcaptcha.com/x', 'https://www.google.com/recaptcha/api.js'] as $url) {
    foreach (['script-src', 'frame-src', 'style-src', 'connect-src'] as $dir) {
        if ($allows($directive($h, $dir), $url) === true) {
            $leak[] = "{$dir} admits {$url}";
        }
    }
}
$leak === [] ? ok($id, 'no provider: none of the other providers\' hosts is in the page rule') : bad($id, 'a host is admitted with no provider', implode("\n", $leak));
// The test's own matcher: wildcard needs a sub-name, look-alikes and plain http are refused.
$id = $nextId();
($admits('https://*.hcaptcha.com', 'https://newassets.hcaptcha.com/a') === true
    && $admits('https://*.hcaptcha.com', 'https://hcaptcha.com/a') === false
    && $admits('https://hcaptcha.com', 'https://evil-hcaptcha.com/a') === false
    && $admits('https://hcaptcha.com', 'http://hcaptcha.com/a') === false)
    ? ok($id, 'the source matcher itself: wildcard, bare name, look-alike and scheme') : bad($id, 'matcher wrong');
// P1: the header stops adding the sources -> the widget's script is refused again.
$setProvider('recaptcha');
$o = $plant('_core/templates/header.php', '$captchaSources    = \Portal\Core\Captcha::cspSources();', '$captchaSources    = [\'script\' => \'\', \'frame\' => \'\', \'style\' => \'\', \'connect\' => \'\'];');
if ($o === null) {
    bad('D-P1', 'plant P1 did not apply');
} else {
    [$h] = $collect('/decision-card');
    $restore('_core/templates/header.php', $o);
    ($allows($directive($h, 'script-src'), 'https://www.gstatic.com/recaptcha/x.js') === false) ? ok('D-P1', 'P1: dropping the append is caught (reCAPTCHA script refused again)') : bad('D-P1', 'P1 not caught');
    [$h] = $collect('/decision-card');
    ($allows($directive($h, 'script-src'), 'https://www.gstatic.com/recaptcha/x.js') === true) ? ok('D-P1b', 'P1 restored: green again') : bad('D-P1b', 'not restored');
}
// P2: the class answers a second provider's hosts as well -> the "no other provider" check goes red.
$o = $plant('_core/Captcha.php', "            return self::RECAPTCHA_PAGE_RULE;", "            return ['script' => self::RECAPTCHA_PAGE_RULE['script'] . self::HCAPTCHA_HOSTS, 'frame' => '', 'style' => '', 'connect' => ''];");
if ($o === null) {
    bad('D-P2', 'plant P2 did not apply');
} else {
    $bad = $foreignCheck('recaptcha');
    $restore('_core/Captcha.php', $o);
    $bad !== [] ? ok('D-P2', 'P2: an extra provider\'s hosts are caught') : bad('D-P2', 'P2 not caught');
    $foreignCheck('recaptcha') === [] ? ok('D-P2b', 'P2 restored: green again') : bad('D-P2b', 'not restored');
}


echo "\n=== E. The same, asked of a real browser (securitypolicyviolation) ===\n";
$browserRun = static function (string $prov) use ($setProvider, $port, $repo, $sep): array {
    $setProvider($prov === 'none' ? null : $prov);
    $out = [];
    $code = 0;
    exec('node ' . escapeshellarg($repo . $sep . 'tools' . $sep . 'page-rule-browser-selftest.mjs') . ' '
        . escapeshellarg('http://127.0.0.1:' . $port) . ' ' . escapeshellarg($prov) . ' 2>&1', $out, $code);
    return [$code, $out];
};
$browserOk = true;
foreach (['turnstile', 'recaptcha', 'hcaptcha', 'none'] as $prov) {
    [$code, $out] = $browserRun($prov);
    if ($code === 2) {
        refuse('the browser check cannot run: ' . implode(' / ', array_slice($out, -2)));
    }
    $line = (string) end($out);
    $code === 0 ? ok('E-' . $prov, "browser, {$prov} active: " . $line) : bad('E-' . $prov, "browser, {$prov} active", implode("\n", array_filter($out, static fn (string $l): bool => str_starts_with($l, 'FAIL'))));
}
// P1 again, in the browser: the header stops adding the sources -> the browser refuses reCAPTCHA's script.
$o = $plant('_core/templates/header.php', '$captchaSources    = \Portal\Core\Captcha::cspSources();', '$captchaSources    = [\'script\' => \'\', \'frame\' => \'\', \'style\' => \'\', \'connect\' => \'\'];');
if ($o === null) {
    bad('E-P1', 'plant P1 did not apply');
} else {
    [$code] = $browserRun('recaptcha');
    $restore('_core/templates/header.php', $o);
    $code === 1 ? ok('E-P1', 'P1: the real browser goes red when the header drops the sources') : bad('E-P1', 'P1 not caught by the browser', 'exit ' . $code);
    [$code] = $browserRun('recaptcha');
    $code === 0 ? ok('E-P1b', 'P1 restored: the browser is green again') : bad('E-P1b', 'not restored', 'exit ' . $code);
}
// P3: reCAPTCHA loses its connect-src host -> the browser and the header both go red.
$o = $plant('_core/Captcha.php', "'connect' => ' https://www.google.com',", "'connect' => '',");
if ($o === null) {
    bad('E-P3', 'plant P3 did not apply');
} else {
    [$code] = $browserRun('recaptcha');
    [$h] = $collect('/decision-card');
    $restore('_core/Captcha.php', $o);
    ($code === 1 && $allows($directive($h, 'connect-src'), 'https://www.google.com/recaptcha/api2/clr') === false)
        ? ok('E-P3', 'P3: dropping reCAPTCHA\'s connect-src host is caught by the browser and by the header check')
        : bad('E-P3', 'P3 not caught', 'browser exit ' . $code);
    [$code] = $browserRun('recaptcha');
    $code === 0 ? ok('E-P3b', 'P3 restored: green again') : bad('E-P3b', 'not restored', 'exit ' . $code);
}
$setProvider(null);
foreach (['enabled', 'allowAnonymous', 'requireCaptcha'] as $k) {
    $clearSetting('calendar.publicSubmit.' . $k);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
