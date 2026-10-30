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

// 📄 The pages a signed-OUT visitor can reach. Each is asked for over HTTP
//    FIRST: a page that does not answer 200 is left out and SAID SO, because
//    photographing an error page and calling it clean is exactly the kind of
//    quiet pass this project keeps finding.
$candidates = [
    '/'                           => 'the front door',
    '/login'                      => 'the sign-in page',
    '/forgot-password'            => 'asking for a password reset',
    '/help'                       => 'the help index',
    '/visit'                      => 'the visitor page',
    '/prayer-requests/anonymous'  => 'sending a prayer request without signing in',
    '/unsubscribe'                => 'unsubscribing from e-mails',
    '/error.php'                  => 'the stand-alone error page',
];

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

$out = getenv('VISUAL_AUDIT_OUT') !== false
    ? (string) getenv('VISUAL_AUDIT_OUT')
    : $child . $sep . 'screenshots';

echo "\nLooking at " . count($pages) . " page(s) in Edge, at five widths\n";
echo str_repeat('-', 72) . "\n";

$cmd = sprintf(
    'node %s --base=%s --pages=%s --out=%s --port=%d --keep-screenshots 2>&1',
    escapeshellarg($harness),
    escapeshellarg('http://127.0.0.1:' . $port),
    escapeshellarg(implode(',', $pages)),
    escapeshellarg($out),
    // A port of this run's own, so two audits can run side by side and a
    // leftover browser from an earlier run is never attached to by mistake.
    9400 + (getmypid() % 150)
);
$lines = [];
$code  = 0;
exec($cmd, $lines, $code);
echo implode("\n", $lines) . "\n";

echo str_repeat('-', 72) . "\n";
if (count($skipped) > 0) {
    echo "NOT looked at, because they did not answer 200: "
        . implode(', ', array_map(
            static fn (string $p, int $s): string => $p . ' (' . $s . ')',
            array_keys($skipped),
            array_values($skipped)
        )) . "\n";
    // ❗ A page that answers 500 is a FAILURE, not a page to pass over. An
    //    earlier version only refused when NOT ONE page answered, so a fatal
    //    in a single page printed "SKIPPED" and the run still exited 0 --
    //    the quiet pass this script's own comment says it exists to avoid.
    //    The three below CANNOT answer 200 in a default install and are not
    //    failures: /visit needs `visitors.public_capture_enabled`,
    //    /unsubscribe needs a token, and error.php returns the status it is
    //    given. Anything else means a page that used to work has stopped.
    $expected = ['/visit', '/unsubscribe', '/error.php'];
    $unexpected = array_diff(array_keys($skipped), $expected);
    if (count($unexpected) > 0) {
        echo "\n❗ THESE USED TO ANSWER AND NOW DO NOT: " . implode(', ', $unexpected) . "\n"
            . "   That is a failure of this audit, not a page to skip over.\n";
        $code = $code === 0 ? 1 : $code;
    }
}
echo "Screenshots: {$out}\n";
if (getenv('VISUAL_AUDIT_OUT') === false) {
    echo "  ⚠️  That folder is inside the temporary copy and is about to be DELETED.\n"
        . "      Set VISUAL_AUDIT_OUT to a real path to keep them.\n";
}
echo "\nThis pass covered SIGNED-OUT pages only. The owner's checklist covers\n"
    . "pages behind a sign-in as well; that needs a session and is not done yet.\n";

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
$errRows = @$db->query('SELECT COUNT(*) AS n FROM tblErrors');
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
        . 'FROM tblErrors ORDER BY errorID DESC LIMIT 5'
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
