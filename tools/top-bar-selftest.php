<?php
/**
 * -----------------------------------------------------------------------------
 * File:        tools/top-bar-selftest.php
 * Description: Stands a real portal up over HTTP with EVERY app switched on
 *              and a long organisation name, signs in a root administrator
 *              and an ordinary member, and has tools/top-bar-selftest.mjs
 *              check in a real browser that the shared top bar fits the
 *              screen at 320, 390, 768, 1024 and 1440 — closed and with its
 *              Apps menu open — and that the cookie banner and the "Install
 *              app" prompt never sit on top of each other.
 * Package:     Portal\Tools
 * Author:      MWBM Partners Ltd (t/a MWservices)
 * Copyright:   2026-present MWBM Partners Ltd (t/a MWservices) - All Rights Reserved
 * Version:     1.0.0
 * Link:        https://github.com/MWBMPartners/WebMS-Intra/issues/822
 * -----------------------------------------------------------------------------
 *
 * WHY THIS EXISTS (#822)
 *   The top bar drew one entry per app. With every app on that was 41 entries
 *   for a root administrator and 35 for an ordinary member, the bar does not
 *   wrap, and every page was 5,091px wide on a 1024px screen. A long
 *   organisation name alone made a phone 826px wide. Every earlier audit missed
 *   both, because none of them ran signed in with a CROWDED bar — which is
 *   exactly the state this test builds. The issue asked for "a signed-in,
 *   all-apps-on layout-audit run" so that it cannot come back unseen; this is
 *   that run, cut down to the one thing it is for.
 *
 * WHAT IT CHANGES IN THE DATABASE — exactly, because it is easy to overstate
 *   PUT BACK at the end, whatever happens (a failure, a refusal, Ctrl-C):
 *     * every two-part `<app>.enabled` setting, all switched on for the run;
 *     * `multisite.enabled`, switched on for the second pass;
 *     * the first organisation's name, made long for the run;
 *     * every `isSensitive = 1` setting, which is EMPTIED for the run (a fresh
 *       key cannot read a secret encrypted with another, and the portal then
 *       logs a critical error on every page) and written back byte for byte.
 *   LEFT BEHIND, on purpose:
 *     * four accounts (two per pass), with their membership and activity
 *       rows: once an account has looked at a page, rows point at it and
 *       deleting it is refused (tools/visual-audit.php learned that);
 *     * a second organisation, `top-bar-second`, made only when the database
 *       has just one — deleting an organisation would take rows other tables
 *       point at. A second run finds and reuses it.
 *   Hence the `selftest_` name rule: this is a throwaway database.
 *
 * FOR PLANTING A FAULT
 *   TOP_BAR_SELFTEST_WEB may name another copy of `web/` to serve instead of
 *   this repository's. That is how a planted fault is proved to turn this test
 *   red without editing the working tree.
 *
 * TWO PASSES: one organisation, then several (`multisite.enabled` on), so
 *   the organisation switcher is on the bar too — the case where the brand's
 *   copy of the name was squeezed to a sliver (#822 round 1).
 *
 * Usage:  SELFTEST_DB_NAME=selftest_topbar SELFTEST_DB_PORT=3306 \
 *           php tools/top-bar-selftest.php
 *         Optional: SELFTEST_DB_HOST (127.0.0.1), SELFTEST_DB_USER (root),
 *         SELFTEST_DB_PASS (empty), SELFTEST_WEB_PORT (9682), CHROME_PATH.
 *
 * WHAT IT NEEDS
 *   A throwaway MySQL database whose name starts `selftest_`, loaded from
 *   web/_sql/full_schema.sql; node 22 or newer; and Chrome, Chromium or Edge.
 *   It REFUSES (exit 2) without any of them, never passes.
 *
 * WHAT IT CANNOT SEE: listed in tools/top-bar-selftest.mjs's header.
 */

declare(strict_types=1);

$sep   = DIRECTORY_SEPARATOR;
$repo  = dirname(__DIR__);
$state = ['proc' => null, 'child' => null, 'restore' => null];

function refuse(string $why): never
{
    fwrite(STDERR, "REFUSED TO RUN: {$why}\n");
    exit(2);
}

// 🔌 Everything needed, checked before anything is built.
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("PHP here has no {$needed}()");
    }
}
exec('command -v node 2>/dev/null', $nodeOut, $nodeCode);
if ($nodeCode !== 0) {
    refuse('node is not on PATH (node 22 or newer drives the browser)');
}
$measurer = $repo . $sep . 'tools' . $sep . 'top-bar-selftest.mjs';
if (is_file($measurer) === false) {
    refuse('tools/top-bar-selftest.mjs is missing');
}
$web = getenv('TOP_BAR_SELFTEST_WEB') !== false ? (string) getenv('TOP_BAR_SELFTEST_WEB') : $repo . $sep . 'web';
if (is_dir($web . $sep . 'public_html') === false) {
    refuse("'{$web}' is not a copy of web/ (it has no public_html folder)");
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}')");
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
$port   = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9682;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (\Throwable $e) {
    refuse("could not reach the database '{$dbName}' on {$dbHost}:{$dbPort} — " . $e->getMessage());
}
$tables = (int) ($db->query("SELECT COUNT(*) AS `n` FROM information_schema.tables WHERE table_schema = '"
    . $db->real_escape_string($dbName) . "'")->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("'{$dbName}' holds only {$tables} tables. Load web/_sql/full_schema.sql into it first");
}

// 🧹 Put everything back and take everything down, whatever happens —
//    including Ctrl-C, which does not run a shutdown function in PHP CLI.
$cleanUp = static function () use (&$state, $db): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    if (is_callable($state['restore']) === true) {
        ($state['restore'])($db);
        $state['restore'] = null;
    }
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
if (function_exists('pcntl_signal') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM, SIGHUP] as $sig) {
        pcntl_signal($sig, static function () use ($cleanUp): void {
            $cleanUp();
            exit(130);
        });
    }
}

// 🧪 THE CROWDED TEST WORLD. Remember what is there first, so it goes back.
$switches = [];
$found = $db->query("SELECT `settingID`, `settingValue` FROM `tblSettings` WHERE `siteID` IS NULL "
    . "AND `settingKey` REGEXP '^[A-Za-z0-9_-]+\\\\.enabled$' AND `settingKey` <> 'multisite.enabled'");
while (($row = $found->fetch_assoc()) !== null) {
    $switches[(int) $row['settingID']] = (string) $row['settingValue'];
}
$found->free();
$site = $db->query('SELECT `siteID`, `siteName` FROM `tblSites` ORDER BY `siteID` LIMIT 1')->fetch_assoc();
if ($site === null) {
    refuse('the database has no organisation in tblSites');
}
$siteId       = (int) $site['siteID'];
$originalName = (string) $site['siteName'];
// The multisite switch and every secret, as they are now, to be written back.
$multisite = $db->query("SELECT `settingID`, `settingValue` FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` = 'multisite.enabled'")->fetch_assoc();
if ($multisite === null) {
    refuse('the database has no installation-wide multisite.enabled setting');
}
$secrets = [];
$found = $db->query("SELECT `settingID`, `settingValue` FROM `tblSettings` WHERE `isSensitive` = 1 AND `settingValue` <> ''");
while (($row = $found->fetch_assoc()) !== null) {
    $secrets[(int) $row['settingID']] = (string) $row['settingValue'];
}
$found->free();
$putBack = $switches + $secrets + [(int) $multisite['settingID'] => (string) $multisite['settingValue']];
$state['restore'] = static function (mysqli $db) use ($putBack, $siteId, $originalName): void {
    try {
        $put = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingID` = ?');
        foreach ($putBack as $settingId => $value) {
            $put->bind_param('si', $value, $settingId);
            $put->execute();
        }
        $put->close();
        $name = $db->prepare('UPDATE `tblSites` SET `siteName` = ? WHERE `siteID` = ?');
        $name->bind_param('si', $originalName, $siteId);
        $name->execute();
        $name->close();
    } catch (\Throwable $e) {
        fwrite(STDERR, 'NOTE: could not put the settings back: ' . $e->getMessage() . "\n");
    }
};
$on = $db->prepare("UPDATE `tblSettings` SET `settingValue` = '1' WHERE `settingID` = ?");
foreach (array_keys($switches) as $settingId) {
    $on->bind_param('i', $settingId);
    $on->execute();
}
$on->close();
// Seventy-five characters with no break a browser would choose before the
// end — the shape that made a phone 826px wide.
$longName = 'The Very Long Named Community Organisation of Saint Somewhere-on-the-Marsh';
$rename = $db->prepare('UPDATE `tblSites` SET `siteName` = ? WHERE `siteID` = ?');
$rename->bind_param('si', $longName, $siteId);
$rename->execute();
$rename->close();
echo 'Switched on ' . count($switches) . " app switches and gave the organisation a long name\n";

// 🔑 A fresh key cannot read a secret encrypted with another, and the portal
//    then logs a critical error on every page (tools/visual-audit.php,
//    measured). So the secrets are emptied for the run — and written back at
//    the end from `$secrets` above. **A real installation must never do this.**
$empty = $db->prepare("UPDATE `tblSettings` SET `settingValue` = '' WHERE `settingID` = ?");
foreach (array_keys($secrets) as $settingId) {
    $empty->bind_param('i', $settingId);
    $empty->execute();
}
$empty->close();
echo 'Emptied ' . count($secrets) . " stored secret(s) for the run; they are written back at the end\n";

// 📁 A copy of web/, with throwaway credentials, served by PHP's own server
//    behind a router that imitates web/public_html/.htaccess.
$child = sys_get_temp_dir() . $sep . 'top-bar-selftest-' . bin2hex(random_bytes(6));
$state['child'] = $child;
mkdir($child . $sep . 'sessions', 0700, true);
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $to = $child . $sep . 'web' . substr($entry->getPathname(), strlen($web));
    if ($entry->isDir() === true) {
        @mkdir($to, 0700, true);
        continue;
    }
    copy($entry->getPathname(), $to);
}
@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/top-bar-selftest.php for ONE run; deleted with its folder.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

$router = <<<'ROUTER'
<?php
// Written by tools/top-bar-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') {
        $target = $real;
    } else {
        // A router must send the Content-Type itself, or every stylesheet goes
        // out as text/html and the browser refuses it — and a bar measured
        // without its stylesheet proves nothing (tools/visual-audit.php).
        $types = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'css' => 'text/css',
            'js' => 'text/javascript', 'mjs' => 'text/javascript', 'json' => 'application/json',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'map' => 'application/json',
            'txt' => 'text/plain', 'webmanifest' => 'application/manifest+json'];
        header('Content-Type: ' . ($types[strtolower(pathinfo($real, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        readfile($real);
        return true;
    }
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
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'log_errors=1', '-d', 'display_errors=0',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-S', '127.0.0.1:' . $port,
        '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('could not start PHP\'s web server');
}

/**
 * One request with a cookie jar; answers [status, headers, body]. Redirects are
 * NOT followed, so the session cookie on a sign-in's 302 is not thrown away.
 */
function topBarCall(int $port, string $jar, string $path, ?array $post = null): array
{
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw    = curl_exec($ch);
    $text   = is_string($raw) === true ? $raw : '';
    $size   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, substr($text, 0, $size), substr($text, $size)];
}

// ⏳ Wait by ASKING for a page, never by sleeping blind.
$probeJar = tempnam(sys_get_temp_dir(), 'tbs-');
$up = false;
for ($i = 0; $i < 60; $i++) {
    if (topBarCall($port, $probeJar, '/login')[0] > 0) {
        $up = true;
        break;
    }
    usleep(500000);
}
@unlink($probeJar);
if ($up === false) {
    refuse("the portal did not answer on 127.0.0.1:{$port} within 30 seconds");
}

/**
 * Make an account and sign it in the way a person does; answer its session
 * cookie as NAME=VALUE, or null with the reason printed.
 *
 * ⚠️ `password_hash()` here is allowed ONLY because this is `tools/`:
 *    `Auth::setPassword()` is the one writer under `web/` (#728).
 */
function topBarAccount(mysqli $db, int $port, string $label, bool $admin): ?string
{
    $who      = 'top-bar-' . $label . '-' . bin2hex(random_bytes(4));
    $password = bin2hex(random_bytes(12));
    $flag     = $admin === true ? 1 : 0;
    // A long name too, so the person's own name in the bar is tested at length.
    $name     = $admin === true ? 'Alexandra Root-Administrator Worthington' : 'Morgan Ordinary-Member Fairweather';
    $email    = $who . '@example.invalid';
    $stmt = $db->prepare('INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, ?, 1, ?, ?)');
    $stmt->bind_param('ssii', $name, $email, $flag, $flag);
    $stmt->execute();
    $userId = (int) $db->insert_id;
    $stmt->close();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
    $stmt->bind_param('iss', $userId, $who, $hash);
    $stmt->execute();
    $stmt->close();
    $sites = $db->query('SELECT `siteID` FROM `tblSites`');
    while (($row = $sites->fetch_assoc()) !== null) {
        $siteId = (int) $row['siteID'];
        $stmt = $db->prepare('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isSiteAdmin`, `isSiteRootAdmin`, `isActive`) VALUES (?, ?, ?, ?, 1)');
        $stmt->bind_param('iiii', $userId, $siteId, $flag, $flag);
        $stmt->execute();
        $stmt->close();
    }
    $sites->free();

    $jar = tempnam(sys_get_temp_dir(), 'tbs-');
    [, , $form] = topBarCall($port, $jar, '/login');
    if (preg_match('/name="csrf_token"\s+value="([0-9a-f]{32,})"/', $form, $m) !== 1) {
        echo "  could not find the sign-in form's token\n";
        @unlink($jar);
        return null;
    }
    // POST ONCE: the form token is spent by the first acceptance (#768).
    [, $head] = topBarCall($port, $jar, '/login', ['csrf_token' => $m[1], 'username' => $who, 'password' => $password]);
    [$status, $where] = topBarCall($port, $jar, '/account');
    // 🏢 With several organisations, somebody who belongs to more than one is
    //    sent to the chooser first. Choose the first one, the way a person
    //    does: by posting the chooser's own form.
    if ($status === 302 && preg_match('/^Location:\s*(\S+)/mi', $where, $loc) === 1) {
        [, , $chooser] = topBarCall($port, $jar, (string) (parse_url($loc[1], PHP_URL_PATH) ?: '/'));
        if (preg_match('/<form[^>]*action="([^"]+)".*?name="csrf_token" value="([0-9a-f]+)".*?name="site_id" value="(\d+)"/s', $chooser, $f) === 1) {
            topBarCall($port, $jar, html_entity_decode($f[1]), ['csrf_token' => $f[2], 'site_id' => $f[3]]);
        }
        [$status] = topBarCall($port, $jar, '/account');
    }
    @unlink($jar);
    if ($status !== 200 || preg_match('/^Set-Cookie:\s*([^=]+)=([^;\r\n]+)/mi', $head, $c) !== 1) {
        echo "  signing in the {$label} account did not work (/account answered {$status})\n";
        return null;
    }
    return trim($c[1]) . '=' . trim($c[2]);
}

$failures = 0;
foreach (['one organisation' => false, 'several organisations' => true] as $pass => $several) {
    echo "\n=== {$pass} ===\n";
    if ($several === true) {
        // A second organisation if there is not one already, then the switch.
        $count = (int) ($db->query('SELECT COUNT(*) AS `n` FROM `tblSites`')->fetch_assoc()['n'] ?? 0);
        if ($count < 2) {
            $db->query("INSERT INTO `tblSites` (`siteKey`, `siteName`, `isActive`) VALUES ('top-bar-second', 'North Fellowship of the Wider Regional Partnership', 1)");
        }
        $flip = $db->prepare("UPDATE `tblSettings` SET `settingValue` = 'true' WHERE `settingID` = ?");
        $msId = (int) $multisite['settingID'];
        $flip->bind_param('i', $msId);
        $flip->execute();
        $flip->close();
    }
    foreach (['root administrator' => true, 'ordinary member' => false] as $label => $admin) {
        echo "\n";
        $cookie = topBarAccount($db, $port, $admin === true ? 'root' : 'member', $admin);
        if ($cookie === null) {
            refuse("could not sign in the {$label} account ({$pass}), so the bar could not be looked at");
        }
        // 30 apps: the premise that the bar really is crowded. The fault showed at
        // 1024 with about a dozen; with every switch on the measured menus held 39
        // (root administrator) and 34 (ordinary member) on 7 November 2026.
        $cmd = sprintf('node %s --base=%s --cookie=%s --label=%s --min-apps=30 --switcher=%s 2>&1',
            escapeshellarg($measurer), escapeshellarg('http://127.0.0.1:' . $port), escapeshellarg($cookie),
            escapeshellarg($label . ', ' . $pass), $several === true ? '1' : '0');
        $lines = [];
        $code  = 0;
        exec($cmd, $lines, $code);
        echo implode("\n", $lines) . "\n";
        if ($code === 2) {
            refuse("the browser half could not run for the {$label} account (see above)");
        }
        if ($code !== 0) {
            $failures++;
        }
    }
}

// ↩️ PUT EVERYTHING BACK NOW, AND CHECK IT, rather than trusting the shutdown
//    function to have done it: the first version's header said the database
//    was "left as it was found" while it emptied every secret for good
//    (#822 round 1). Whatever this reads back differently is a failure.
($state['restore'])($db);
$state['restore'] = null;
// ⚠️ Checked against the three snapshots SEPARATELY, never against
//    `$putBack`: that is the list the restore itself uses, so a value left out
//    of it would be left out of this check too, and the check would pass on
//    the very fault it exists for (planted: it did).
$expected = [];
foreach ([$switches, $secrets, [(int) $multisite['settingID'] => (string) $multisite['settingValue']]] as $snapshot) {
    foreach ($snapshot as $settingId => $value) {
        $expected[] = [$settingId, $value];
    }
}
$wrong = [];
$read  = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingID` = ?');
foreach ($expected as [$settingId, $value]) {
    $read->bind_param('i', $settingId);
    $read->execute();
    $now = $read->get_result()->fetch_assoc();
    if ($now === null || (string) $now['settingValue'] !== $value) {
        $wrong[] = (string) $settingId;
    }
}
$read->close();
$nameNow = (string) ($db->query('SELECT `siteName` FROM `tblSites` WHERE `siteID` = ' . $siteId)->fetch_assoc()['siteName'] ?? '');
echo "\n";
if ($wrong === [] && $nameNow === $originalName) {
    echo '  ✓ the ' . count($expected) . " setting(s) it changed, the secrets among them, and the organisation's name are back as they were\n";
} else {
    echo '  ✗ NOT put back: ' . ($wrong !== [] ? 'setting id(s) ' . implode(', ', $wrong) : '')
        . ($nameNow !== $originalName ? ' the organisation\'s name' : '') . "\n";
    $failures++;
}

echo "\n" . ($failures === 0
    ? "PASS: for every account run, with one organisation and with several, the top bar fits the screen closed and open;\n"
      . "      with Bootstrap working, focus or a resting pointer opens no menu on the bar and Enter/Escape work on Apps;\n"
      . "      with scripts off or Bootstrap blocked, the Apps menu opens on hover and every menu on focus; and the notices do not overlap\n"
    : "FAIL: {$failures} account run(s) found a fault (the ✗ lines above)\n");
exit($failures === 0 ? 0 : 1);
