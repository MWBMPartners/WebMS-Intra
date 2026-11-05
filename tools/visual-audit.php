<?php
/**
 * -----------------------------------------------------------------------------
 * File:        tools/visual-audit.php
 * Description: Stands a real portal up over HTTP and looks at its pages in a
 *              real browser at five screen widths, reporting what a person
 *              would see wrong and writing a screenshot of each page at each
 *              width for the parts no machine can judge.
 * Package:     Portal\Tools
 * Author:      MWBM Partners Ltd (t/a MWservices)
 * Copyright:   2026-present MWBM Partners Ltd (t/a MWservices) - All Rights Reserved
 * Version:     1.0.0
 * Link:        https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 *
 * WHY THIS EXISTS
 *   The owner's rule is that nothing may look unfinished, careless or AI-made,
 *   and that the browser half of that check runs as a full audit before every
 *   release. Until 30 October 2026 it was reported as blocked for want of a
 *   headless browser. That was wrong: Microsoft Edge is installed, and Edge is
 *   Chromium.
 *
 * ONE COMMAND, ON PURPOSE
 *   This script does the whole job: copies `web/`, writes a throwaway
 *   `auth_creds.php` and encryption key, starts PHP's own web server behind a
 *   router that imitates `.htaccess`, waits for it by ASKING for a page rather
 *   than sleeping blind, drives `tools/layout-audit.mjs`, and takes the whole
 *   thing down again. A check somebody has to assemble by hand is a check that
 *   does not get run, which is the whole lesson of #646.
 *
 * WHAT IT NEEDS
 *   * A throwaway MySQL database whose name starts `selftest_`.
 *   * Microsoft Edge, and node 22 or newer. Nothing is installed: Edge is
 *     driven over the DevTools protocol using node's own built-in `WebSocket`.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_visual SELFTEST_DB_PORT=3408 \
 *           php tools/visual-audit.php
 *         Optional: SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER
 *         (default root), SELFTEST_DB_PASS (default empty),
 *         SELFTEST_WEB_PORT (default 9680), VISUAL_AUDIT_OUT (where the
 *         screenshots go; default a folder beside the temporary copy, which is
 *         deleted with it -- give a real path to keep them).
 *
 * WHAT THIS FIRST PASS DOES NOT DO, said plainly
 *   It looks at the pages a signed-OUT visitor sees. The owner's checklist
 *   covers pages behind a sign-in too (their decision of 4 October 2026), and
 *   those need a session: a member row, a posted sign-in, and the cookie handed
 *   to the browser. `layout-audit.mjs` already accepts `--cookie`, so the
 *   missing piece is this script creating the member and signing in. That is
 *   the next step and is NOT done here -- do not read a clean run as covering
 *   the signed-in portal.
 *
 *   It also cannot say whether a page looks GOOD. Visual judgement, brand
 *   consistency and whether a layout is attractive are not measurable; that is
 *   what the screenshots are for.
 *
 *   IT AUDITS FOUR DISTINCT PAGES, not eight. Of the eight addresses it tries,
 *   `/` and `/login` are the same page (`/` redirects), and three CANNOT answer
 *   200 in a default install and never will: `/visit` needs
 *   `visitors.public_capture_enabled`, `/unsubscribe` needs a token, and
 *   `error.php` returns whatever status it is given. The portal has around 30
 *   signed-out HTML pages in `tblRoutes` -- `/privacy`, `/photos`, `/projects`,
 *   `/calendar`, `/reset-password`, `/attend` and seventeen `/help/*` among
 *   them -- so this is a narrow first pass, not coverage.
 *
 *   IT DOES NOT CHECK the owner's other checklist items: favicon, Open Graph
 *   tags, the canonical address or the social preview. `check_page_head_tags.py`
 *   guards those statically. Worth knowing: `/login` draws its own `<head>` and
 *   carries none of them, and `/favicon.ico` answers 404 -- this audit calls it
 *   clean, because it is not looking.
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);
$state = ['proc' => null, 'child' => null];

function refuse(string $why): never
{
    fwrite(STDERR, "REFUSED TO RUN: {$why}\n");
    exit(1);
}

// 🔌 Everything this needs, checked before anything is built, so a refusal
//    names the one missing thing instead of failing half way through.
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("PHP here has no {$needed}(), which this test cannot work without");
    }
}
$edge = '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge';
if (is_file($edge) === false) {
    refuse("Microsoft Edge is not at {$edge}. This audit drives Edge headless; "
        . 'it does not fall back to anything, because a visual audit that silently '
        . 'checks nothing is worse than one that refuses');
}
exec('command -v node 2>/dev/null', $nodeOut, $nodeCode);
if ($nodeCode !== 0) {
    refuse('node is not on PATH. The browser is driven from a small node script '
        . 'using node\'s own built-in WebSocket (node 22 or newer), so that nothing '
        . 'has to be installed');
}
$harness = $repo . $sep . 'tools' . $sep . 'layout-audit.mjs';
if (is_file($harness) === false) {
    refuse("tools/layout-audit.mjs is missing, so there is nothing to drive the browser with");
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). "
        . 'This test installs a portal into it');
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
$port   = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9680;

$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
if ($db->connect_errno !== 0) {
    refuse("could not reach the database '{$dbName}' on {$dbHost}:{$dbPort} — {$db->connect_error}");
}
$have = $db->query("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = '"
    . $db->real_escape_string($dbName) . "'");
$rows = $have !== false ? (int) ($have->fetch_assoc()['n'] ?? 0) : 0;
if ($rows < 100) {
    refuse("'{$dbName}' holds only {$rows} tables. Load web/_sql/full_schema.sql and every "
        . 'numbered migration into it first');
}

$child = sys_get_temp_dir() . $sep . 'visual-audit-' . bin2hex(random_bytes(6));
$state['child'] = $child;

// 🧹 Take the server down and remove the copy, whatever happens.
//
// ⚠️ A shutdown function is NOT enough. PHP CLI does not run one on SIGINT, so
//    pressing Ctrl-C left the whole 22 MB copy of web/ on disk -- INCLUDING the
//    throwaway `auth_creds.php` (host, user, password, database) and `enc.key`.
//    The comment written into that file says "deleted with its folder", which
//    was untrue in exactly that case. `pcntl` is available in CLI here, so the
//    signals are caught and the same clean-up runs.
$cleanUp = static function () use (&$state, $sep): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
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
if (function_exists('pcntl_signal') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM, SIGHUP] as $sig) {
        pcntl_signal($sig, static function () use ($cleanUp): void {
            $cleanUp();
            exit(130);
        });
    }
}
register_shutdown_function(static function () use (&$state, $sep): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
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

@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/visual-audit.php for ONE run; deleted with its folder.\nreturn "
    . var_export([
        'db_host' => $dbHost,
        'db_user' => $dbUser,
        'db_pass' => $dbPass,
        'db_name' => $dbName,
        'db_port' => $dbPort,
    ], true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// 🔑 A FRESH KEY CANNOT READ A SECRET ENCRYPTED WITH ANY OTHER ONE, and
//    leaving those rows makes the portal log a CRITICAL error on every page —
//    so the audit failed for a reason that had nothing to do with the pages.
//
//    It happened the first time this ran against a database that had been used
//    for an earlier run: `Recordings::podcastToken()` had minted a token and
//    stored it encrypted, this run wrote a NEW key, and every page load then
//    recorded "1 stored secret cannot be read with this installation's key
//    file". The portal was behaving correctly; the FIXTURE was inconsistent.
//
//    This is a throwaway database and a throwaway key, so the honest fix is to
//    empty the secrets rather than pretend to hold them. **A real installation
//    keeps its key and must never do this** — which is why it lives here, in
//    the audit's own setup, and not anywhere under `web/`.
$secrets = $db->query('SELECT COUNT(*) AS `n` FROM `tblSettings` '
    . "WHERE `isSensitive` = 1 AND `settingValue` <> ''");
$howMany = (int) ($secrets->fetch_assoc()['n'] ?? 0);
$secrets->free();
if ($howMany > 0) {
    $db->query("UPDATE `tblSettings` SET `settingValue` = '' WHERE `isSensitive` = 1");
    echo "Emptied {$howMany} stored secret(s): this run minted a new key, so nothing\n"
        . "  could read them and every page would have logged a critical error about\n"
        . "  the fixture rather than about itself.\n";
}

// 🛣️ The router imitates web/public_html/.htaccess, as the other page tests do.
$router = <<<'ROUTER'
<?php
// Written by tools/visual-audit.php for ONE run (deleted with its folder). Test only.
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
        // ⚠️ SEND A CONTENT-TYPE. PHP's built-in server guesses one from the
        //    extension only when IT serves the file; once a router handles the
        //    request it sends `text/html` for everything. A browser will not
        //    render an SVG or a PNG served as text/html, so EVERY picture in
        //    the portal came out broken -- the sign-in page's logo rendered as
        //    a broken-image icon and was nearly reported as a real defect.
        //    For a VISUAL audit that is fatal twice over: every screenshot
        //    misleads, and a genuinely broken image is hidden among them.
        $types = [
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'ico' => 'image/x-icon', 'css' => 'text/css', 'js' => 'text/javascript',
            'mjs' => 'text/javascript', 'json' => 'application/json',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
            'map' => 'application/json', 'txt' => 'text/plain', 'webmanifest' => 'application/manifest+json',
        ];
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
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

/** Ask for a page; give back [status, body]. */
$get = static function (string $path) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    // No curl_close() here: it has had no effect since PHP 8.0 and PHP 8.5
    // raises a "deprecated" notice every time it runs (#673).
    return [$status, is_string($body) === true ? $body : ''];
};

// ⏳ Wait by ASKING for a page, never by sleeping blind.
$up = false;
for ($i = 0; $i < 60; $i++) {
    [$status] = $get('/login');
    if ($status > 0) {
        $up = true;
        break;
    }
    usleep(500000);
}
if ($up === false) {
    refuse("the portal did not answer on 127.0.0.1:{$port} within 30 seconds. "
        . 'Its log is in the temporary folder, which is about to be removed');
}

// 🔐 SIGN AN ADMINISTRATOR IN, so the pages behind a sign-in can be looked at
//    too. The owner's checklist covers them (their decision of 4 October
//    2026), and until now this audit could not reach a single one — it said so
//    in its own output, which is the only reason that was honest rather than
//    misleading.
//
//    ⚠️ THE PASSWORD IS HASHED HERE WITH `password_hash()` AND THAT IS ALLOWED
//       ONLY BECAUSE THIS IS `tools/`. `Auth::setPassword()` is the ONE place a
//       sign-in password is hashed and written under `web/` (#728), and
//       `check_one_password_writer.py` enforces it — its own header records
//       that it deliberately does not look outside `web/`, because a test
//       harness must be able to make an account. **Never copy this into a
//       page.**
//
//    It signs in the way a person does: fetch `/login`, take the form's token
//    out of the HTML, post the two fields, keep the cookie. Nothing here
//    reaches past the front door, so if the sign-in page itself breaks, this
//    fails rather than quietly auditing a signed-out portal and calling it
//    signed-in.
$cookie = null;
$signedInPages = [];
$password = bin2hex(random_bytes(12));
// ⚠️ A NAME OF THIS RUN'S OWN, so nothing has to be deleted first. Deleting a
//    previous run's account threw: once that account has looked at a page it
//    has rows in `tblActivityLogs`, which point at it, and the delete is
//    refused by the foreign key — correctly. The database is throwaway, so
//    leaving the old accounts costs nothing and removes a whole class of
//    failure from the setup.
$who = 'visual-audit-' . bin2hex(random_bytes(4));

$stmt = $db->prepare(
    'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) '
    . 'VALUES (?, ?, 1, 1, 1)'
);
$name  = 'Visual Audit';
$email = $who . '@example.invalid';
$stmt->bind_param('ss', $name, $email);
$stmt->execute();
$userId = (int) $db->insert_id;
$stmt->close();

$stmt = $db->prepare(
    'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) '
    . 'VALUES (?, ?, ?, 1)'
);
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt->bind_param('iss', $userId, $who, $hash);
$stmt->execute();
$stmt->close();

// Membership of every organisation there is, so no page is skipped for the
// wrong reason.
$sites = $db->query('SELECT `siteID` FROM `tblSites`');
while (($site = $sites->fetch_assoc()) !== null) {
    $stmt = $db->prepare(
        'INSERT INTO `tblUserSites` (`userID`, `siteID`, `isSiteAdmin`, `isSiteRootAdmin`, `isActive`) '
        . 'VALUES (?, ?, 1, 1, 1)'
    );
    $siteId = (int) $site['siteID'];
    $stmt->bind_param('ii', $userId, $siteId);
    $stmt->execute();
    $stmt->close();
}
$sites->free();

$jar = tempnam(sys_get_temp_dir(), 'va-cookie-');
$curlWithJar = static function (string $path, ?array $post = null, bool $wantHeaders = false) use ($port, $jar): array {
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    if ($wantHeaders === true) {
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    }
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $text   = is_string($body) === true ? $body : '';
    $head   = '';
    if ($wantHeaders === true) {
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr($text, 0, $size);
        $text = substr($text, $size);
    }
    return [$status, $text, $head];
};

// ⚠️ SAY WHY IT FAILED, not just that it did. The first version printed
//    "COULD NOT SIGN IN" and nothing else, which made the failure
//    undiagnosable — and a signed-in audit that silently becomes a signed-out
//    one is the quiet pass this whole script exists to stop.
$whyNot = '';
[$loginStatus, $loginHtml] = $curlWithJar('/login');
$token = '';
if (preg_match('/name="csrf_token"\s+value="([0-9a-f]{32,})"/', $loginHtml, $m) === 1) {
    $token = $m[1];
}
if ($loginStatus !== 200) {
    $whyNot = "the sign-in page itself answered {$loginStatus}";
} elseif ($token === '') {
    $whyNot = 'no form token could be found on the sign-in page';
}
if ($whyNot === '') {
    // ⚠️ POST ONCE. An earlier version posted twice — once for the body and
    //    once again with headers on to read the cookie — and the SECOND post
    //    was always refused, because `Auth::verifyCsrf()` spends the token on
    //    the first. So it set no cookie and the audit reported that it could
    //    not sign in. That is #768's own fault, committed here while fixing
    //    #768: a token is good for one acceptance, and asking twice is asking
    //    with a spent one.
    //
    //    Headers are on, and redirects are NOT followed for this one request:
    //    the session cookie is on the 302 itself, and following it throws the
    //    headers away.
    [$postStatus, $postBody, $signInHead] = $curlWithJar('/login', [
        'csrf_token' => $token, 'username' => $who, 'password' => $password,
    ], true);
    // Did it actually work? Asking a page that needs a sign-in is the only
    // honest test — a cookie file with something in it proves nothing.
    [$checkStatus] = $curlWithJar('/account');
    if ($checkStatus !== 200) {
        $whyNot = "the sign-in post answered {$postStatus} and /account then answered "
            . "{$checkStatus}; the account may not have been created as this portal expects";
    }
    if ($checkStatus === 200) {
        // ⚠️ READ IT FROM THE ANSWER'S OWN HEADERS, NOT FROM CURL'S JAR FILE.
        //    curl writes the jar when the handle is CLOSED, and PHP 8 frees a
        //    handle whenever it chooses — so the file was reliably empty at
        //    the moment it was read, and the audit reported "the session
        //    cookie could not be read" while the sign-in had actually worked.
        //    The header is there in the answer itself and needs nothing to be
        //    flushed.
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;\r\n]+)/mi', $signInHead, $c) === 1) {
            $cookie = trim($c[1]) . '=' . trim($c[2]);
        } elseif (preg_match('/^Set-Cookie:\s*([^=]+)=([^;\r\n]+)/mi', $postBody, $c) === 1) {
            $cookie = trim($c[1]) . '=' . trim($c[2]);
        }
        if ($cookie === null) {
            // The session is already established in the jar, so no new header
            // is sent. Ask curl for what it is holding, which it will tell us
            // without writing a file.
            $raw = $curlWithJar('/account', null, true)[2];
            if (preg_match('/Cookie:\s*(PHPSESSID|[A-Za-z0-9_]+)=([^;\r\n]+)/i', $raw, $c) === 1) {
                $cookie = trim($c[1]) . '=' . trim($c[2]);
            }
        }
    }
}
@unlink($jar);
if ($cookie !== null) {
    echo "Signed in as an administrator; pages behind the sign-in will be looked at too\n";
} else {
    echo "⚠️  COULD NOT SIGN IN, so only signed-out pages are covered by this run.\n"
        . "    Reason: " . ($whyNot !== '' ? $whyNot : 'the session cookie could not be read') . "\n";
}

// 📄 The pages a signed-OUT visitor can reach, TAKEN FROM THE ROUTES TABLE
//    RATHER THAN TYPED OUT (#569).
//
//    🚨 THE HAND-WRITTEN LIST WAS EIGHT ADDRESSES AND FOUR DISTINCT PAGES,
//       while the portal has FORTY-TWO signed-out pages a person can actually
//       open. Its own header said so — "a narrow first pass, not coverage" —
//       and that honesty is exactly why it had to stop being hand-written: a
//       list somebody maintains is a list that falls behind the moment a page
//       is added, and nothing would say so.
//
//       Now it asks the database. `isProtected = 0` is the portal's own answer
//       to "can a signed-out visitor reach this", so the audit cannot drift
//       from what the portal actually serves.
//
//    WHAT IS LEFT OUT, and why each is not a page a person looks at:
//      * `cron/…`      — scheduled jobs; they answer 403 without a token.
//      * `…/save`, `…-action`, `…/verify`, `api/…` — handlers that expect a
//        POST. A GET of one is not a page.
//      * `.ics`, `.json`, `.xml`, `.txt`, `manifest`, `openapi`, `robots`,
//        `sitemap` — machine-readable answers, not pages.
//      * `…/serve`, `…/media`, `…/track/…` — files and tracking pixels.
//      * Anything needing a token or an argument to mean anything —
//        `unsubscribe`, `auth/invite`, `calendar/rsvp-by-link`,
//        `account/erasure-confirm`, `privacy/consent`, `login/webauthn`.
//
//    Each surviving address is still asked for over HTTP FIRST: a page that
//    does not answer 200 is left out and SAID SO, because photographing an
//    error page and calling it clean is exactly the kind of quiet pass this
//    project keeps finding.
$notAPage = '~^cron/|/save$|-action$|/verify$|(^|/)api/|\.(ics|json|xml|txt)$'
    . '|^manifest|^openapi|^robots|^sitemap'
    . '|/serve$|/media$|/track/'
    . '|^unsubscribe$|^auth/invite$|^calendar/rsvp-by-link$|^account/erasure-confirm$'
    . '|^privacy/consent$|^login/webauthn$|^calendar/export$~';

$candidates = ['/' => 'the front door', '/error.php' => 'the stand-alone error page'];
$rows = $db->query('SELECT `routeKey` FROM `tblRoutes` WHERE `isProtected` = 0 ORDER BY `routeKey`');
while (($row = $rows->fetch_assoc()) !== null) {
    $key = (string) $row['routeKey'];
    if (preg_match($notAPage, $key) === 1) {
        continue;
    }
    $candidates['/' . $key] = 'a page a visitor can open';
}
$rows->free();

// 🔐 And the pages behind the sign-in, when there is a session to see them
//    with. Same filtering, plus the admin pages' own sub-handlers: a `/save`
//    or an `/api/` address is not a page whichever side of the door it is on.
if ($cookie !== null) {
    $rows = $db->query('SELECT `routeKey` FROM `tblRoutes` WHERE `isProtected` = 1 ORDER BY `routeKey`');
    while (($row = $rows->fetch_assoc()) !== null) {
        $key = (string) $row['routeKey'];
        if (preg_match($notAPage, $key) === 1) {
            continue;
        }
        // A page that acts rather than shows: deleting, running, exporting.
        if (preg_match('~/(delete|run|export|download|toggle|send|cancel|approve|reject|print)(/|$)~', $key) === 1) {
            continue;
        }
        $candidates['/' . $key] = 'a page behind the sign-in';
    }
    $rows->free();
}

// 📌 THE HIGH-WATER MARK, TAKEN BEFORE ANY PAGE IS FETCHED.
//
//    🚨 THIS USED TO COUNT EVERY ROW IN `tblErrors`, so ONE row left by an
//       earlier run made every later run fail — and the failure named a page
//       this run had not broken. Measured: a run reported "at /login" for an
//       error recorded forty minutes earlier by a different run.
//
//       That is #671's recorded remedy, in a new place: "read `MAX(logID)`
//       BEFORE `exec()` and ask only about rows after it." A run must report
//       what IT caused, or the report is about somebody else.
$beforeRun = 0;
mysqli_report(MYSQLI_REPORT_OFF);
$markRow = @$db->query('SELECT COALESCE(MAX(`errorID`), 0) AS `high` FROM `tblErrors`');
if ($markRow !== false) {
    $beforeRun = (int) ($markRow->fetch_assoc()['high'] ?? 0);
    $markRow->free();
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

echo "Portal up on 127.0.0.1:{$port}; checking which pages answer\n";
$pages = [];
$skipped = [];
foreach ($candidates as $path => $what) {
    [$status, $body] = $get($path);
    if ($status === 200 && trim($body) !== '') {
        $pages[] = $path;
        printf("  %-30s %d  %s\n", $path, $status, $what);
    } else {
        $skipped[$path] = $status;
        printf("  %-30s %d  SKIPPED (%s)\n", $path, $status, $what);
    }
}
if (count($pages) === 0) {
    refuse('not one page answered 200, so there is nothing to look at. '
        . 'This is a failure, not a clean run');
}

// 🔢 A QUICK PASS, for when the point is to check the audit rather than the
//    portal. The full run is 445 pages at five widths — over 2,200 renders and
//    the best part of half an hour — which is right for a release gate and
//    wrong for "does my change to this script work".
//
//    ⚠️ IT SAYS SO IN THE OUTPUT, EVERY TIME. A shortened run that reads like
//       a full one is the quiet pass this whole script exists to stop, and a
//       reader coming to the log later has no way to tell otherwise.
$limit = (int) (getenv('VISUAL_AUDIT_LIMIT') !== false ? getenv('VISUAL_AUDIT_LIMIT') : 0);
if ($limit > 0 && count($pages) > $limit) {
    $allPages = count($pages);
    $pages = array_slice($pages, 0, $limit);
    echo "⚠️  QUICK PASS: {$limit} of {$allPages} pages, because VISUAL_AUDIT_LIMIT is set.\n"
        . "    This is NOT the release audit and must not be reported as one.\n";
}

$out = getenv('VISUAL_AUDIT_OUT') !== false
    ? (string) getenv('VISUAL_AUDIT_OUT')
    : $child . $sep . 'screenshots';

echo "\nLooking at " . count($pages) . " page(s) in Edge, at five widths\n";
echo str_repeat('-', 72) . "\n";

$cmd = sprintf(
    'node %s --base=%s --pages=%s --out=%s --port=%d%s --keep-screenshots 2>&1',
    escapeshellarg($harness),
    escapeshellarg('http://127.0.0.1:' . $port),
    escapeshellarg(implode(',', $pages)),
    escapeshellarg($out),
    // A port of this run's own, so two audits can run side by side and a
    // leftover browser from an earlier run is never attached to by mistake.
    9400 + (getmypid() % 150),
    // The session, when there is one. `escapeshellarg` because the value is a
    // session id the portal minted, not something typed here — and a shell
    // argument built by concatenation is how a value with a quote in it stops
    // being one argument.
    $cookie !== null ? ' --cookie=' . escapeshellarg($cookie) : ''
);

// ⏱️ A DEADLINE THAT SCALES WITH THE WORK, because a fixed one stopped being
//    honest the moment the page list grew. `layout-audit.mjs` defaults to 600
//    seconds, which was ample for FOUR pages; with the sign-in it is 445, each
//    rendered at five widths, and the run was CUT OFF at roughly 284 — the
//    report then said nothing about the rest, and a reader could easily have
//    taken "no findings" for the pages it never reached.
//
//    Four seconds a page is generous against the measured rate and still bounds
//    a run that has genuinely hung, which is what a deadline is for.
$cmd .= ' --deadline=' . (count($pages) * 4000 + 60000);
$lines = [];
$code  = 0;
exec($cmd, $lines, $code);
echo implode("\n", $lines) . "\n";

echo str_repeat('-', 72) . "\n";
if (count($skipped) > 0) {
    // 📋 WHY A PAGE WAS NOT LOOKED AT, split into the two kinds — because they
    //    are not the same news and one of them is a failure.
    //    A page that needs an ARGUMENT cannot answer on its own.
    //
    //    🚨 THIS USED TO BE A HAND-WRITTEN LIST OF THREE ADDRESSES, and
    //       widening the audit from 4 distinct pages to 50 turned it into a
    //       wolf-crier: fifteen pages that have never been able to answer
    //       without an argument were all reported as "used to answer and now
    //       do not", and the run failed because of it. `/calendar/event` needs
    //       an event, `/photos/album` needs an album, `/projects/pledge` needs
    //       a project. None is broken; none was ever in the old list, because
    //       the old list only had to cover four pages.
    //
    //       So it judges by WHAT THE PORTAL SAID rather than by a list
    //       somebody keeps:
    //         * 400 — it wanted an argument it did not get
    //         * 403 — it wants a token this audit does not have
    //         * 404 — it was asked about something that is not there, because
    //                 this is an empty portal with no fixture data
    //       Each of those is "cannot answer without something", not "broken".
    //
    //       **A 500, or no answer at all, IS a failure** and still fails the
    //       run: that is a page that threw, which is exactly what this audit
    //       is for. An address with no page behind it is caught elsewhere, by
    //       `/admin/check` and `check_webroot_shadowing.py`, so nothing is
    //       lost by not treating a 404 as one here.
    // `/error.php` is the one address that answers with a status BY DESIGN —
    // it returns whatever it is given, and 500 is its default when nothing
    // asks for anything else. It is not a page that threw, and treating it as
    // one would make every run fail for ever.
    $answersWithAStatus = ['/error.php'];

    $needsSomething = [];
    $reallyBroken   = [];
    foreach ($skipped as $path => $status) {
        if (in_array($path, $answersWithAStatus, true) === true) {
            $needsSomething[] = $path . ' (' . $status . ', which is what it is for)';
            continue;
        }
        if (in_array((int) $status, [400, 403, 404], true) === true) {
            $needsSomething[] = $path . ' (' . $status . ')';
            continue;
        }
        $reallyBroken[] = $path . ' (' . $status . ')';
    }
    if (count($needsSomething) > 0) {
        echo "\nNot looked at, because each needs an argument, a token or data this\n"
            . "audit does not have — not because anything is broken:\n   "
            . implode(', ', $needsSomething) . "\n";
    }
    if (count($reallyBroken) > 0) {
        echo "\n❗ THESE ANSWERED WITH AN ERROR, WHICH IS A FAILURE: "
            . implode(', ', $reallyBroken) . "\n"
            . "   A page that throws is exactly what this audit exists to find.\n";
        $code = $code === 0 ? 1 : $code;
    }
}
echo "Screenshots: {$out}\n";
if (getenv('VISUAL_AUDIT_OUT') === false) {
    echo "  ⚠️  That folder is inside the temporary copy and is about to be DELETED.\n"
        . "      Set VISUAL_AUDIT_OUT to a real path to keep them.\n";
}
// ⚠️ SAY WHAT THIS RUN ACTUALLY DID. The line here used to read "this pass
//    covered SIGNED-OUT pages only" unconditionally — true before the sign-in
//    was built and FALSE the moment it worked, on a script whose whole purpose
//    is catching pages that quietly do the wrong thing.
if ($cookie !== null) {
    echo "\nThis pass covered BOTH signed-out pages and pages behind the sign-in,\n"
        . "as an administrator. An ordinary member sees fewer pages, and a page\n"
        . "that looks right to an administrator may not to them — that is not\n"
        . "covered here.\n";
} else {
    echo "\nThis pass covered SIGNED-OUT pages only, because it could not sign in.\n"
        . "The owner's checklist covers pages behind a sign-in as well, so this\n"
        . "run does NOT meet it.\n";
}

// 🔎 Anything the portal itself logged is a finding in its own right.
//
// ⚠️ TWO PLACES, because php-errors.log CANNOT SEE A FATAL. The portal sets its
//    own handlers in bootstrap.php: an uncaught exception goes to `tblErrors`
//    and is SWALLOWED, so PHP never writes error_log at all. Measured during
//    the independent check: a planted RuntimeException put two rows in
//    tblErrors, left php-errors.log non-existent, and the run exited 0. The
//    file catches warnings, notices and deprecations -- precisely not the 500s.
$errLog = $child . $sep . 'php-errors.log';
if (is_file($errLog) === true && trim((string) file_get_contents($errLog)) !== '') {
    echo "\nPHP ITSELF LOGGED SOMETHING while these pages were fetched\n"
        . "(warnings, notices and deprecations — a fatal does NOT appear here):\n";
    foreach (array_slice(array_filter(explode("\n", (string) file_get_contents($errLog))), 0, 12) as $line) {
        // Trimmed, because a warning's text can carry a value with it.
        echo '  ' . substr(trim($line), 0, 160) . "\n";
    }
    $code = $code === 0 ? 1 : $code;
}

// Reporting must never be what kills the run. mysqli throws by default here.
mysqli_report(MYSQLI_REPORT_OFF);
$errRows = @$db->query('SELECT COUNT(*) AS n FROM tblErrors WHERE errorID > ' . $beforeRun);
$errCount = $errRows !== false ? (int) ($errRows->fetch_assoc()['n'] ?? 0) : 0;
if ($errCount > 0) {
    echo "\n❗ THE PORTAL RECORDED {$errCount} ERROR(S) IN tblErrors while these pages\n"
        . "   were fetched. That is where an uncaught exception goes, so this is\n"
        . "   where a 500 shows up. Look at them:\n";
    // Column names READ FROM THE SCHEMA, not guessed. An earlier version
    // invented `errorType` and `errorMessage`, which do not exist, so this
    // block threw an uncaught mysqli exception and the script died with 255
    // at the very moment it was trying to report somebody else's fatal.
    $recent = $db->query(
        'SELECT errorPlatform, errorSeverity, errorCode, errorTitle, requestURL '
        . 'FROM tblErrors WHERE errorID > ' . $beforeRun . ' ORDER BY errorID DESC LIMIT 5'
    );
    if ($recent !== false) {
        while (($row = $recent->fetch_assoc()) !== null) {
            echo '   • ' . substr((string) ($row['errorPlatform'] ?? '?'), 0, 14)
                . ' ' . substr((string) ($row['errorSeverity'] ?? ''), 0, 10)
                . ' ' . substr((string) ($row['errorCode'] ?? ''), 0, 20)
                . "\n     " . substr((string) ($row['errorTitle'] ?? ''), 0, 120)
                . "\n     at " . substr((string) ($row['requestURL'] ?? '?'), 0, 80) . "\n";
        }
    }
    $code = $code === 0 ? 1 : $code;
}

exit($code);
