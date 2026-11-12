<?php
// Path: tools/uploads-and-media-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Uploads that write no deprecation, and media that can be sent in pieces 📤🎞️
 * -----------------------------------------------------------------------------
 * Two faults, #1003 and #880, tested together because the second feeds on the
 * first: the poster video whose pieces section N asks for is the one section U
 * really uploaded.
 *
 * #1003 — SIX UPLOAD HANDLERS CALLED `finfo_close()`, which PHP 8.5 deprecates.
 *   The call did nothing (since PHP 8.1 the object frees itself) but every
 *   upload through those handlers wrote "Function finfo_close() is deprecated"
 *   into the portal's error log. `php -l` cannot see a deprecation — it exits
 *   0 — so the only proof is to RUN each handler, which this does over HTTP
 *   with a real multipart upload, and then read both places a notice lands:
 *   `tblErrors` (the portal's own error handler) and the server's PHP error
 *   log. Each handler is also given a file of the WRONG type, so the test
 *   shows the type check still refuses what it refused before — removing the
 *   close must not change what the bytes are judged to be.
 *
 *   **Section P is what makes section U worth anything**: it puts the
 *   `finfo_close()` call BACK into each handler, in a copy, and requires the
 *   deprecation to be FOUND. A check that "no deprecation was recorded" passes
 *   just as well when the recording is broken, the server's error log points
 *   nowhere, or the handler never reached the line — so each handler's
 *   clean result is only believed because the same upload, with the call put
 *   back, is caught.
 *
 * #880 — THE MEDIA ROUTES SENT FILES WHOLE, so Safari would not play a poster
 *   video. Every route that streams media now asks `Portal\Core\ByteRange`,
 *   and this drives three of them over HTTP — `/noticeboard/media`,
 *   `/recordings/stream` and `/recordings/podcast-media` (the public door's
 *   media is driven by `tools/public-door-selftest.php`, which already builds
 *   the door). For each: no `Range:`, the first two bytes (what Safari asks
 *   first), a middle piece, an open-ended piece, the last N bytes, an end past
 *   the file, a piece starting past the end (416), and a malformed header —
 *   the STATUS, `Accept-Ranges`, `Content-Range`, `Content-Length` and the
 *   EXACT bytes. And each route's access checks are asked FIRST, with a
 *   `Range:` header on the request, to show a refusal still sends no byte and
 *   no range header.
 *
 *   Section R asks `ByteRange::resolve()` directly about the shapes no route
 *   needs a file for (several pieces, `If-Range`, an empty file, a 25-digit
 *   number).
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It does not use Safari, or any browser. It shows the answers are the
 *     ones RFC 9110 describes; it cannot show Safari then plays the video.
 *   - It runs PHP's built-in server, not Apache, so it says nothing about a
 *     host's output compression or buffering in front of PHP.
 *   - It cannot test `web/_apps/venues/invoice-save.php`, the seventh caller,
 *     because that file was being changed by another package and was left
 *     alone (it is the one PENDING entry in
 *     `tools/audit-checks/check_no_deprecated_php_functions.py`).
 *   - One worker (`PHP_CLI_SERVER_WORKERS` unset): nothing here is about two
 *     requests at once.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_media SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/uploads-and-media-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         curl, `proc_open()` and fileinfo. It REFUSES rather than skipping
 *         when anything is missing, because a skipped test reads exactly like
 *         a passing one.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1003
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/880
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null];

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
foreach (['curl_init', 'proc_open', 'finfo_open'] as $needed) {
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

// =============================================================================
echo "R. ByteRange::resolve(), asked directly\n";
// =============================================================================
// The shapes that need no route: they are the helper's own decisions, and the
// routes below only show the helper is the thing answering.
require $repo . $sep . 'web' . $sep . '_core' . $sep . 'ByteRange.php';
$r = static fn ($h, $ifr, int $size): array => array_values(\Portal\Core\ByteRange::resolve($h, $ifr, $size));
check('R01 no header: the whole file, 200', $r(null, null, 10), [200, 0, 9, 10]);
check('R02 a header that is not text (an array) is no header', $r(['x'], null, 10), [200, 0, 9, 10]);
check('R03 bytes=0-1, Safari\'s first question: the first two bytes, 206', $r('bytes=0-1', null, 10), [206, 0, 1, 2]);
check('R04 the unit ignores capitals and allows spaces', $r(' Bytes = 2 - 4 ', null, 10), [206, 2, 4, 3]);
check('R05 bytes=4-: from byte 4 to the end', $r('bytes=4-', null, 10), [206, 4, 9, 6]);
check('R06 bytes=-3 is the LAST three bytes (the old copies read it as bytes 0 to 3)', $r('bytes=-3', null, 10), [206, 7, 9, 3]);
check('R07 a suffix longer than the file is the whole file, as a piece', $r('bytes=-50', null, 10), [206, 0, 9, 10]);
check('R08 an end past the file is shortened to the last byte (the old copies did not)', $r('bytes=5-999', null, 10), [206, 5, 9, 5]);
check('R09 a start AT the size is unsatisfiable, 416', $r('bytes=10-', null, 10), [416, -1, -1, 0]);
check('R10 bytes=-0 asks for nothing: unsatisfiable', $r('bytes=-0', null, 10), [416, -1, -1, 0]);
check('R11 an empty file cannot satisfy any piece, start or suffix', [$r('bytes=0-', null, 0), $r('bytes=-1', null, 0)],
    [[416, -1, -1, 0], [416, -1, -1, 0]]);
check('R12 an empty file with no header is an ordinary 200 of nothing', $r(null, null, 0), [200, 0, -1, 0]);
check('R13 bytes=9-3 is malformed, not unsatisfiable: ignored, whole file', $r('bytes=9-3', null, 10), [200, 0, 9, 10]);
check('R14 several pieces are ignored (no multipart answer): whole file', $r('bytes=0-1,4-5', null, 10), [200, 0, 9, 10]);
check('R15 another unit, no "=", a lone "-", letters, a sign: all ignored', [
    $r('items=0-1', null, 10), $r('bytes 0-1', null, 10), $r('bytes=-', null, 10), $r('bytes=a-b', null, 10), $r('bytes=+1-2', null, 10),
], array_fill(0, 5, [200, 0, 9, 10]));
check('R16 If-Range present: we send no version marker, so the whole file', $r('bytes=0-1', '"abc"', 10), [200, 0, 9, 10]);
check('R17 a 25-digit start is past any file: 416, not a wrapped-round number', $r('bytes=' . str_repeat('9', 25) . '-', null, 10),
    [416, -1, -1, 0]);
check('R18 a 25-digit end is "to the end"', $r('bytes=3-' . str_repeat('9', 25), null, 10), [206, 3, 9, 7]);
// The two boundaries round 2 of the check found pinned by nothing: `>=`
// against `>` on the shortening (bytes=0-<size> would then promise one byte
// more than it sends — #880's fault 2 back), and `<` against `<=` on the
// malformed test (a one-byte piece would then get the whole file).
check('R19 bytes=0-9 on a 10-byte file: exactly the file, as a piece', $r('bytes=0-9', null, 10), [206, 0, 9, 10]);
check('R20 bytes=0-10 on a 10-byte file: the end AT the size is shortened too', $r('bytes=0-10', null, 10), [206, 0, 9, 10]);
check('R21 bytes=0-0: one byte, the first', $r('bytes=0-0', null, 10), [206, 0, 0, 1]);
check('R22 bytes=9-9: one byte, the last', $r('bytes=9-9', null, 10), [206, 9, 9, 1]);
check('R23 leading zeros are legal and count for nothing: twenty zeros then 5 is byte 5, 000-001 is bytes 0-1, -0000 asks for nothing', [
    $r('bytes=' . str_repeat('0', 20) . '5-', null, 10), $r('bytes=000-001', null, 10), $r('bytes=-0000', null, 10),
], [[206, 5, 9, 5], [206, 0, 1, 2], [416, -1, -1, 0]]);

// =============================================================================
echo "M. ByteRange::send() and output buffers already open when it is called\n";
// =============================================================================
// `send()` ends every open output buffer before it sends anything, as
// `CsvExporter::download()` does, and DISCARDS what was in them. Three things
// to pin, each found unpinned by a round of the independent check:
//   M1  memory: with buffers open, every chunk was kept until the request
//       ended — a 42 MB peak for a 20 MB file. TWO buffers are opened, so a
//       loop shortened to a single `if` leaves one behind and goes red.
//   M2  what is sent: anything a page printed before calling send() is
//       DISCARDED, not sent. Flushing it instead (`ob_end_flush()`) puts that
//       text in front of the file's first byte: a whole answer that is not the
//       file, and a piece whose bytes are off by the junk's length.
//   M3  a buffer PHP will NOT remove (`ob_start(null, 0, 0)`): `ob_end_clean()`
//       answers false for ever, so the loop must stop rather than spin. Run
//       under a time limit, so a missing `break` FAILS instead of hanging this
//       test.
// Each runs in a CHILD process: send() writes to standard output, which here
// is a pipe this test reads (or /dev/null for the 20 MB one).
$probe = sys_get_temp_dir() . $sep . 'byterange-probe-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($probe, '<?php
declare(strict_types=1);
require ' . var_export($repo . $sep . 'web' . $sep . '_core' . $sep . 'ByteRange.php', true) . ';
$mode = $argv[1];
$file = $argv[2];
if (($argv[3] ?? "") !== "") { $_SERVER["HTTP_RANGE"] = $argv[3]; }
if ($mode === "peak") { ob_start(); ob_start(); }
if ($mode === "junk") { ob_start(); echo "JUNK PRINTED BEFORE SEND"; }
if ($mode === "stuck") { ob_start(null, 0, 0); echo "HELD"; }
$sent = \\Portal\\Core\\ByteRange::send($file);
$left = ob_get_level();
$status = http_response_code();
if ($mode !== "stuck") { while (ob_get_level() > 0) { ob_end_clean(); } }
fwrite(STDERR, json_encode(["sent" => $sent, "status" => $status, "buffersLeft" => $left,
    "peakMb" => round(memory_get_peak_usage(true) / 1048576, 1)]));
');
/**
 * Run the probe; answers [stdout, the facts it reported, timed out?]. The
 * child is KILLED at the deadline, so a loop that never ends is a red check.
 */
$runProbe = static function (string $mode, string $file, string $range = '', bool $keepOut = true, float $deadline = 20.0) use ($probe): array {
    $proc = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=256M', $probe, $mode, $file, $range],
        [0 => ['file', '/dev/null', 'r'], 1 => $keepOut === true ? ['pipe', 'w'] : ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (is_resource($proc) === false) {
        return ['', null, false];
    }
    $out = ''; $err = ''; $start = microtime(true); $timedOut = false;
    foreach ($pipes as $p) {
        stream_set_blocking($p, false);
    }
    while (true) {
        foreach ($pipes as $k => $p) {
            $chunk = (string) fread($p, 1048576);
            $k === 1 ? $out .= $chunk : $err .= $chunk;
        }
        if (proc_get_status($proc)['running'] === false) {
            foreach ($pipes as $k => $p) {
                $chunk = (string) stream_get_contents($p);
                $k === 1 ? $out .= $chunk : $err .= $chunk;
            }
            break;
        }
        if (microtime(true) - $start > $deadline) {
            proc_terminate($proc, 9);
            $timedOut = true;
            break;
        }
        usleep(20000);
    }
    foreach ($pipes as $p) {
        fclose($p);
    }
    proc_close($proc);
    return [$out, json_decode($err, true), $timedOut];
};
$bigFile = sys_get_temp_dir() . $sep . 'byterange-big-' . bin2hex(random_bytes(4)) . '.bin';
$bh = fopen($bigFile, 'wb');
for ($i = 0; $i < 20; $i++) {
    fwrite($bh, random_bytes(1048576));
}
fclose($bh);
$smallBytes = random_bytes(1000);
$smallFile  = sys_get_temp_dir() . $sep . 'byterange-small-' . bin2hex(random_bytes(4)) . '.bin';
file_put_contents($smallFile, $smallBytes);

[, $peak] = $runProbe('peak', $bigFile, '', false);
check('M1 sending a 20 MB file with TWO buffers open: both ended, and memory stays under 8 MB (it was 42 MB)',
    [is_array($peak) === true ? $peak['buffersLeft'] : 'no answer', is_array($peak) === true && $peak['peakMb'] < 8],
    [0, true]);
[$jOut, $jFacts] = $runProbe('junk', $smallFile);
[$jrOut, $jrFacts] = $runProbe('junk', $smallFile, 'bytes=10-19');
check('M2 text a page printed before send() is DISCARDED: the whole answer is exactly the file, and a piece is exactly that piece with a 206',
    [is_array($jFacts) === true ? $jFacts['status'] : 'no answer', $jOut === $smallBytes,
        is_array($jrFacts) === true ? $jrFacts['status'] : 'no answer', $jrOut === substr($smallBytes, 10, 10)],
    [200, true, 206, true]);
[, $sFacts, $sTimedOut] = $runProbe('stuck', $smallFile, '', true, 10.0);
check('M3 a buffer PHP will not remove: send() still finishes (within 10 seconds), answers, and leaves that one buffer',
    [$sTimedOut, is_array($sFacts) === true ? [$sFacts['sent'], $sFacts['buffersLeft']] : 'no answer'],
    [false, [true, 1]]);
@unlink($probe);
@unlink($bigFile);
@unlink($smallFile);

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'uploads-media-' . bin2hex(random_bytes(6));
$state['child'] = $child;
register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_dir($state['child']) === true) {
        // A folder THIS run made, under the system temp folder, random name.
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
    $rel = substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    // Nobody's real uploads come along: the copy starts with none.
    if (str_starts_with($rel, $sep . '_uploads') === true) {
        continue;
    }
    $to = $child . $sep . 'web' . $rel;
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
    "<?php\n// Written by tools/uploads-and-media-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
$router = <<<'ROUTER'
<?php
// Written by tools/uploads-and-media-selftest.php for ONE run. Test only.
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

$port     = 8960 + random_int(0, 30);
$jar      = $child . $sep . 'cookies.txt';
$phpLog   = $child . $sep . 'php-errors.log';

/** Start (or restart) the server against the child tree. */
$startServer = static function () use (&$state, $child, $sep, $port, $phpLog): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
        usleep(400000);
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = 'prod';
    $state['proc'] = proc_open(
        [
            PHP_BINARY,
            // E_ALL, said on the command line as well as by the portal's own
            // start-up, so a deprecation cannot be hidden by either.
            '-d', 'error_reporting=' . E_ALL,
            '-d', 'session.save_path=' . $child . DIRECTORY_SEPARATOR . 'sessions',
            '-d', 'error_log=' . $phpLog,
            '-d', 'log_errors=1', '-d', 'display_errors=0',
            '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=24M',
            '-d', 'max_execution_time=120',
            '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
            '-S', '127.0.0.1:' . $port,
            '-t', $child . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html',
            $child . DIRECTORY_SEPARATOR . 'router.php',
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
 * One request. `$post` is either form fields (an array of strings) or, when
 * it holds a CURLFile, a multipart upload. Returns status, lower-cased
 * headers, the Location and the body.
 */
$http = static function (string $method, string $path, array $post = [], array $headers = [], bool $useJar = true) use ($port, $jar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($useJar === true) {
        $opts[CURLOPT_COOKIEJAR]  = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($method === 'POST') {
        $multipart = false;
        foreach ($post as $v) {
            if ($v instanceof CURLFile) {
                $multipart = true;
            }
        }
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $multipart === true ? $post : http_build_query($post);
    }
    curl_setopt_array($handle, $opts);
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'headers' => [], 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $size  = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $named = [];
    foreach (preg_split('/\r?\n/', trim(substr((string) $raw, 0, $size))) ?: [] as $i => $line) {
        if ($i === 0 || str_contains($line, ':') === false) {
            continue;
        }
        [$k, $v] = explode(':', $line, 2);
        $named[strtolower(trim($k))] = trim($v);
    }
    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'headers'  => $named,
        'location' => $named['location'] ?? '',
        'body'     => substr((string) $raw, $size),
    ];
};

$tokenOn = static function (string $html): string {
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    return '';
};

$waitForServer = static function () use ($http): void {
    for ($i = 0; $i < 80; $i++) {
        if ($http('GET', '/login', [], [], false)['status'] > 0) {
            return;
        }
        usleep(250000);
    }
    refuse('the test server never answered');
};

$startServer();
$waitForServer();

// ── The world ───────────────────────────────────────────────────────────────
$siteId = (int) ($db->query('SELECT MIN(siteID) AS s FROM tblSites')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation');
}
$tag   = 'um' . bin2hex(random_bytes(4));
$email = $tag . '@selftest.invalid';
$ins = $db->prepare(
    'INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin, isRootAdmin, createdAt) '
    . "VALUES ('Uploads Media Admin', ?, 1, 1, 1, NOW())"
);
$ins->bind_param('s', $email);
$ins->execute();
$userId = (int) $db->insert_id;
$ins->close();
$db->query("INSERT IGNORE INTO tblUserSites (siteID, userID, isSiteAdmin, isSiteRootAdmin, isActive) VALUES ({$siteId}, {$userId}, 1, 1, 1)");
$password   = 'Um' . bin2hex(random_bytes(8)) . '!9x';
$signInName = 'selftest-' . $tag;
$hash = password_hash($password, PASSWORD_DEFAULT);
$acc = $db->prepare('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)');
$acc->bind_param('iss', $userId, $signInName, $hash);
$acc->execute();
$acc->close();

$signIn = static function () use ($http, $tokenOn, $signInName, $password, $jar): void {
    @unlink($jar);
    $page  = $http('GET', '/login');
    $token = $tokenOn($page['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => $signInName, 'password' => $password]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse('signing the test administrator in did not redirect (status ' . $in['status'] . ')');
    }
};
/** A fresh form token: every accepted post replaces it (#768). */
$freshToken = static function (string $page = '/') use ($http, $tokenOn): string {
    $t = $tokenOn($http('GET', $page)['body']);
    if ($t === '') {
        refuse("no form token on {$page}");
    }
    return $t;
};

// A venue, an agreement and an asset for the three handlers that attach a
// file to something. Only the columns the schema insists on.
$db->query("INSERT INTO tblVenues (siteID, venueName, countryCode, timezone) VALUES ({$siteId}, 'UM venue {$tag}', 'GB', 'Europe/London')");
$venueId = (int) $db->insert_id;
$db->query("INSERT INTO tblVenueAgreements (siteID, venueID, title, currency) VALUES ({$siteId}, {$venueId}, 'UM agreement {$tag}', 'GBP')");
$agreementId = (int) $db->insert_id;
$db->query("INSERT INTO tblAssets (siteID, name, currency, publicToken) VALUES ({$siteId}, 'UM asset {$tag}', 'GBP', '" . bin2hex(random_bytes(16)) . "')");
$assetId = (int) $db->insert_id;

// The files. Each type is decided by fileinfo from these BYTES, never by the
// name, so each is built to be what it says.
$files = $child . $sep . 'files';
mkdir($files, 0700);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
// A video longer than three of ByteRange's 64 KiB chunks, so a piece crosses
// chunk boundaries. Starts with an MP4 `ftyp` box so fileinfo calls it
// video/mp4; the rest is random, so no two pieces look alike.
$video = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . random_bytes(200000);
$pdf   = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
// The WRONG type, for every handler: gzip bytes, which no handler accepts,
// wearing an allowed name. (Plain text was the first choice and was wrong:
// three handlers rightly ACCEPT text/plain, so it proved nothing there.)
$fake  = gzencode('this is not a picture, a PDF or a spreadsheet');
file_put_contents($files . $sep . 'pic.png', $png);
file_put_contents($files . $sep . 'clip.mp4', $video);
file_put_contents($files . $sep . 'doc.pdf', $pdf);
file_put_contents($files . $sep . 'fake.png', $fake);
file_put_contents($files . $sep . 'fake.pdf', $fake);
file_put_contents($files . $sep . 'sheet.csv', "Date,Hours,Status\n2026-01-05,9-5,booked\n");
file_put_contents($files . $sep . 'fake.csv', $fake);
$fileOf = static fn (string $name, string $type) => new CURLFile($files . $sep . $name, $type, $name);

/** Deprecation notices recorded since `$fromErrorId`: in tblErrors, and in the server's PHP log. */
$errorMark = static fn (): int => (int) ($db->query('SELECT COALESCE(MAX(errorID), 0) AS m FROM tblErrors')->fetch_assoc()['m'] ?? 0);
$logMark   = static fn (): int => is_file($phpLog) === true ? (int) filesize($phpLog) : 0;
$deprecations = static function (int $fromErrorId, int $fromLogByte) use ($db, $phpLog): array {
    $rows = [];
    $rs = $db->query(
        'SELECT CONCAT(COALESCE(errorTitle, \'\'), \' \', COALESCE(errorDetail, \'\')) AS t FROM tblErrors '
        . "WHERE errorID > {$fromErrorId}"
    );
    while ($row = $rs->fetch_assoc()) {
        if (stripos((string) $row['t'], 'deprecat') !== false) {
            $rows[] = 'tblErrors: ' . substr((string) $row['t'], 0, 160);
        }
    }
    clearstatcache(true, $phpLog);
    if (is_file($phpLog) === true) {
        $tail = (string) file_get_contents($phpLog, false, null, $fromLogByte);
        foreach (preg_split('/\r?\n/', $tail) ?: [] as $line) {
            if (stripos($line, 'deprecat') !== false) {
                $rows[] = 'php log: ' . substr($line, 0, 160);
            }
        }
    }
    return $rows;
};
/** The flash message on a page the handler redirected to. */
$flashOn = static function (string $html): string {
    if (preg_match('#<div class="alert alert-[a-z]+[^"]*"[^>]*>(.*?)</div>#s', $html, $m) !== 1) {
        return '';
    }
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) ?? '');
};

/**
 * The six upload handlers, each as (good upload, wrong-type upload, how to
 * tell it was accepted). Used by section U on the real code and by section P
 * with the call put back.
 */
$uploads = [
    'noticeboard/api/upload' => [
        'good' => static function () use ($http, $freshToken, $fileOf): array {
            $t = $freshToken('/noticeboard');
            return $http('POST', '/api/noticeboard/upload', ['file' => $fileOf('clip.mp4', 'video/mp4')], ['X-CSRF-Token: ' . $t]);
        },
        'bad' => static function () use ($http, $freshToken, $fileOf): array {
            $t = $freshToken('/noticeboard');
            return $http('POST', '/api/noticeboard/upload', ['file' => $fileOf('fake.png', 'image/png')], ['X-CSRF-Token: ' . $t]);
        },
        'accepted' => static fn (array $r): bool => $r['status'] === 201,
        'refused'  => static fn (array $r): bool => $r['status'] === 400 && str_contains($r['body'], 'Unsupported file type'),
    ],
    'documents/api/create' => [
        'good' => static function () use ($http, $freshToken, $fileOf): array {
            $t = $freshToken('/documents');
            return $http('POST', '/api/documents/create', ['file' => $fileOf('doc.pdf', 'application/pdf'), 'title' => 'UM doc'], ['X-CSRF-Token: ' . $t]);
        },
        'bad' => static function () use ($http, $freshToken, $fileOf): array {
            $t = $freshToken('/documents');
            return $http('POST', '/api/documents/create', ['file' => $fileOf('fake.pdf', 'application/pdf'), 'title' => 'UM doc bad'], ['X-CSRF-Token: ' . $t]);
        },
        'accepted' => static fn (array $r): bool => $r['status'] === 201 || $r['status'] === 200,
        'refused'  => static fn (array $r): bool => $r['status'] === 400 && str_contains($r['body'], 'Unsupported or unrecognized file type'),
    ],
    'photos/upload' => [
        'good' => static function () use ($http, $freshToken, $fileOf): array {
            $t = $freshToken('/photos/upload');
            return $http('POST', '/photos/upload', ['csrf_token' => $t, 'photo' => $fileOf('pic.png', 'image/png'), 'visibility' => 'inherit']);
        },
        'bad' => static function () use ($http, $freshToken, $fileOf): array {
            $t = $freshToken('/photos/upload');
            return $http('POST', '/photos/upload', ['csrf_token' => $t, 'photo' => $fileOf('fake.png', 'image/png'), 'visibility' => 'inherit']);
        },
        'accepted' => static fn (array $r): bool => $r['status'] === 302 && str_ends_with($r['location'], '/photos'),
        'refused'  => static fn (array $r): bool => $r['status'] === 302 && str_ends_with($r['location'], '/photos/upload'),
    ],
    'venues/agreement-files' => [
        'good' => static function () use ($http, $freshToken, $fileOf, $agreementId): array {
            $t = $freshToken('/venues/agreements');
            return $http('POST', '/venues/agreement-files', ['csrf_token' => $t, 'agreementID' => (string) $agreementId,
                'action' => 'upload', 'title' => 'UM file', 'file' => $fileOf('doc.pdf', 'application/pdf')]);
        },
        'bad' => static function () use ($http, $freshToken, $fileOf, $agreementId): array {
            $t = $freshToken('/venues/agreements');
            return $http('POST', '/venues/agreement-files', ['csrf_token' => $t, 'agreementID' => (string) $agreementId,
                'action' => 'upload', 'title' => 'UM file bad', 'file' => $fileOf('fake.pdf', 'application/pdf')]);
        },
        'accepted' => null, // judged by the flash message, below
        'refused'  => null,
    ],
    'assets/resource-save' => [
        'good' => static function () use ($http, $freshToken, $fileOf, $assetId): array {
            $t = $freshToken('/assets');
            return $http('POST', '/assets/resource-save', ['csrf_token' => $t, 'assetID' => (string) $assetId, 'action' => 'add',
                'resourceType' => 'manual', 'title' => 'UM manual', 'file' => $fileOf('doc.pdf', 'application/pdf')]);
        },
        'bad' => static function () use ($http, $freshToken, $fileOf, $assetId): array {
            $t = $freshToken('/assets');
            return $http('POST', '/assets/resource-save', ['csrf_token' => $t, 'assetID' => (string) $assetId, 'action' => 'add',
                'resourceType' => 'manual', 'title' => 'UM manual bad', 'file' => $fileOf('fake.png', 'image/png')]);
        },
        'accepted' => null,
        'refused'  => null,
    ],
    'venues/import' => [
        'good' => static function () use ($http, $freshToken, $fileOf, $venueId): array {
            $t = $freshToken('/venues/import');
            return $http('POST', '/venues/import', ['csrf_token' => $t, 'action' => 'upload', 'venue' => (string) $venueId,
                'import_file' => $fileOf('sheet.csv', 'text/csv')]);
        },
        'bad' => static function () use ($http, $freshToken, $fileOf, $venueId): array {
            $t = $freshToken('/venues/import');
            return $http('POST', '/venues/import', ['csrf_token' => $t, 'action' => 'upload', 'venue' => (string) $venueId,
                'import_file' => $fileOf('fake.csv', 'text/csv')]);
        },
        'accepted' => null,
        'refused'  => null,
    ],
];

/** Where each handler's own message lands, for the handlers that answer with a redirect and a flash. */
$flashAfter = static function (array $r) use ($http, $flashOn): string {
    if ($r['status'] !== 302 && $r['status'] !== 303) {
        return '(no redirect: ' . $r['status'] . ')';
    }
    $to = (string) parse_url($r['location'], PHP_URL_PATH) . ((string) parse_url($r['location'], PHP_URL_QUERY) !== '' ? '?' . parse_url($r['location'], PHP_URL_QUERY) : '');
    return $flashOn($http('GET', $to)['body']);
};

$signIn();

// =============================================================================
echo "U. Each upload handler runs with no deprecation, and still judges the type (#1003)\n";
// =============================================================================
$typeRefusalWords = [
    'venues/agreement-files' => 'Unsupported file type',
    'assets/resource-save'   => 'Unsupported file type',
    'venues/import'          => 'Unsupported file',
];
$uploadedVideo = '';
/**
 * Deprecations that are NOT this package's and are tracked elsewhere, by the
 * handler that reaches them. Each is printed, never silently passed, and the
 * note says when it has gone so the line can be deleted.
 *
 * #1038: `Venues::parseWorkbook()` calls `fgetcsv()` without its `escape`
 * argument, which PHP 8.4 and 8.5 deprecate. Found by THIS test on its first
 * run; `Venues.php` was being changed by another package, so it is left.
 */
$knownElsewhere = ['venues/import' => ['fgetcsv(): the $escape parameter must be provided', '#1038']];
foreach ($uploads as $name => $u) {
    $e0 = $errorMark();
    $l0 = $logMark();
    // ⚠️ Read each answer's message BEFORE the next request. A redirecting
    //    handler keeps ONE message in the session, and the page that hands
    //    out the next form token prints and clears it — the first version of
    //    this loop sent both uploads first and read the wrong one's message.
    $good = ($u['good'])();
    $goodFlash = $u['accepted'] === null ? $flashAfter($good) : '';
    $bad  = ($u['bad'])();
    $badFlash  = $u['accepted'] === null ? $flashAfter($bad) : '';
    if ($u['accepted'] !== null) {
        $goodOk = ($u['accepted'])($good);
        $badOk  = ($u['refused'])($bad);
        if ($name === 'noticeboard/api/upload') {
            $json = json_decode($good['body'], true);
            $url  = (string) ($json['data']['url'] ?? '');
            if (preg_match('/f=([a-f0-9]{32}\.mp4)/', $url, $m) === 1) {
                $uploadedVideo = $m[1];
            }
        }
    } else {
        $goodOk = $goodFlash !== '' && str_contains($goodFlash, $typeRefusalWords[$name]) === false
            && str_contains($goodFlash, 'Invalid or expired form token') === false && str_contains($goodFlash, 'upload failed') === false;
        $badOk  = str_contains($badFlash, $typeRefusalWords[$name]);
    }
    if ($goodOk === false || $badOk === false) {
        echo "      (the good upload answered {$good['status']} {$goodFlash}; the wrong one answered {$bad['status']} {$badFlash})\n";
    }
    check("U {$name}: a file of the right type is accepted", $goodOk, true);
    check("U {$name}: a file whose bytes are the wrong type is still refused", $badOk, true);
    $found  = $deprecations($e0, $l0);
    $finfo  = array_values(array_filter($found, static fn (string $l): bool => stripos($l, 'finfo') !== false));
    $others = array_values(array_filter($found, static fn (string $l): bool => stripos($l, 'finfo') === false));
    check("U {$name}: NO finfo deprecation was recorded, in tblErrors or the PHP log", $finfo, []);
    if (isset($knownElsewhere[$name]) === true) {
        [$words, $issue] = $knownElsewhere[$name];
        $unknown = array_values(array_filter($others, static fn (string $l): bool => str_contains($l, $words) === false));
        check("U {$name}: and no OTHER deprecation either, apart from {$issue}'s", $unknown, []);
        echo count($others) > 0
            ? "      note: {$issue} still writes " . count($others) . " deprecation line(s) here, as expected until it is fixed\n"
            : "      note: {$issue}'s deprecation has GONE — delete its line from \$knownElsewhere\n";
    } else {
        check("U {$name}: and no OTHER deprecation either", $others, []);
    }
}

// What the type check decided, read back: the stored type of each accepted file.
$stored = static function (string $sql) use ($db): array {
    $out = [];
    $rs = $db->query($sql);
    while ($row = $rs->fetch_row()) {
        $out[] = (string) $row[0];
    }
    return $out;
};
check('U+ only the accepted files were stored, each with the type fileinfo found in its bytes (video, picture, three PDFs)', [
    $stored("SELECT mimeType FROM tblNoticeboardUploads WHERE createdByID = {$userId} ORDER BY uploadID"),
    $stored("SELECT mimeType FROM tblPhoto WHERE uploadedByUserID = {$userId} ORDER BY photoID"),
    $stored("SELECT mimeType FROM tblDocuments WHERE title LIKE 'UM doc%' ORDER BY documentID"),
    $stored("SELECT mimeType FROM tblVenueAgreementFiles WHERE agreementID = {$agreementId} ORDER BY fileID"),
    $stored("SELECT mimeType FROM tblAssetResources WHERE assetID = {$assetId} ORDER BY resourceID"),
], [['video/mp4'], ['image/png'], ['application/pdf'], ['application/pdf'], ['application/pdf']]);
check('U++ the upload test found the poster video section N needs', preg_match('/^[a-f0-9]{32}\.mp4$/', $uploadedVideo), 1);

// =============================================================================
// The range matrix, used for every route below.
// =============================================================================
/**
 * Ask `$path` for each piece and compare status, the three range headers and
 * the EXACT bytes with what `$whole` says they must be.
 */
$rangeMatrix = static function (string $label, string $path, string $whole, bool $signedIn) use ($http): void {
    $n   = strlen($whole);
    $ask = static fn (?string $range) => $http('GET', $path, [], $range === null ? [] : ['Range: ' . $range], $signedIn);
    $see = static fn (array $r): array => [
        $r['status'], $r['headers']['accept-ranges'] ?? '', $r['headers']['content-range'] ?? '',
        $r['headers']['content-length'] ?? '', $r['body'] === '' ? '' : hash('sha256', $r['body']),
    ];
    $want = static fn (int $status, string $cr, string $bytes): array => [$status, 'bytes', $cr, (string) strlen($bytes), $bytes === '' ? '' : hash('sha256', $bytes)];
    $mid = intdiv($n, 3);
    check("{$label}: no Range: 200, the whole file, Accept-Ranges: bytes", $see($ask(null)), $want(200, '', $whole));
    check("{$label}: bytes=0-1 (Safari's first question): 206, the first two bytes", $see($ask('bytes=0-1')),
        $want(206, 'bytes 0-1/' . $n, substr($whole, 0, 2)));
    check("{$label}: a middle piece crossing chunk boundaries", $see($ask('bytes=' . $mid . '-' . ($mid + 100000))),
        $want(206, 'bytes ' . $mid . '-' . ($mid + 100000) . '/' . $n, substr($whole, $mid, 100001)));
    check("{$label}: an open-ended piece, to the end", $see($ask('bytes=' . ($n - 1000) . '-')),
        $want(206, 'bytes ' . ($n - 1000) . '-' . ($n - 1) . '/' . $n, substr($whole, $n - 1000)));
    check("{$label}: a suffix, the LAST 500 bytes", $see($ask('bytes=-500')),
        $want(206, 'bytes ' . ($n - 500) . '-' . ($n - 1) . '/' . $n, substr($whole, -500)));
    check("{$label}: an end past the file is shortened to the last byte", $see($ask('bytes=10-' . ($n + 5000))),
        $want(206, 'bytes 10-' . ($n - 1) . '/' . $n, substr($whole, 10)));
    check("{$label}: bytes=0-<last byte> and bytes=0-<size>: both the whole file as one piece, Content-Length the size",
        [$see($ask('bytes=0-' . ($n - 1))), $see($ask('bytes=0-' . $n))],
        [$want(206, 'bytes 0-' . ($n - 1) . '/' . $n, $whole), $want(206, 'bytes 0-' . ($n - 1) . '/' . $n, $whole)]);
    $past = $ask('bytes=' . $n . '-');
    check("{$label}: a piece starting past the end: 416, Content-Range: bytes */size, no body, never kept",
        [$past['status'], $past['headers']['accept-ranges'] ?? '', $past['headers']['content-range'] ?? '', $past['body'],
            $past['headers']['cache-control'] ?? ''],
        [416, 'bytes', 'bytes */' . $n, '', 'no-store']);
    check("{$label}: a malformed header (bytes=9-3) is ignored: 200, the whole file", $see($ask('bytes=9-3')), $want(200, '', $whole));
};

// =============================================================================
echo "N. /noticeboard/media sends a poster video in pieces (#880)\n";
// =============================================================================
$rangeMatrix('N', '/noticeboard/media?f=' . $uploadedVideo, $video, false);
$nType = $http('GET', '/noticeboard/media?f=' . $uploadedVideo, [], ['Range: bytes=0-1'], false);
check('N: the piece still carries the type, nosniff and the year-long cache', [
    $nType['headers']['content-type'] ?? '', $nType['headers']['x-content-type-options'] ?? '', $nType['headers']['cache-control'] ?? '',
], ['video/mp4', 'nosniff', 'public, max-age=31536000, immutable']);
// Access first: a name of the right SHAPE that is no upload, asked for with a
// Range header, is the ordinary 404 — no byte, and no range header saying a
// file is there.
$nobody = $http('GET', '/noticeboard/media?f=' . str_repeat('e', 32) . '.mp4', [], ['Range: bytes=0-1'], false);
$badShape = $http('GET', '/noticeboard/media?f=../../_auth_keys/enc.key', [], ['Range: bytes=0-1'], false);
check('N: refusals come FIRST — an unknown name and a bad name are 404 with no Accept-Ranges or Content-Range', [
    $nobody['status'], $nobody['headers']['accept-ranges'] ?? '', $nobody['headers']['content-range'] ?? '',
    $badShape['status'], $badShape['headers']['accept-ranges'] ?? '', $badShape['headers']['content-range'] ?? '',
], [404, '', '', 404, '', '']);

// =============================================================================
echo "S. /recordings/stream and /recordings/podcast-media (#880)\n";
// =============================================================================
$recDir = $child . $sep . 'web' . $sep . '_uploads' . $sep . 'recordings';
@mkdir($recDir, 0755, true);
$recName = 'um-' . $tag . '.mp4';
file_put_contents($recDir . $sep . $recName, $video);
$db->query("INSERT INTO tblRecording (siteID, title, filePath, mimeType, isPublished) VALUES ({$siteId}, 'UM rec', '{$recName}', 'video/mp4', 1)");
$recId = (int) $db->insert_id;
$db->query("INSERT INTO tblRecording (siteID, title, filePath, mimeType, isPublished) VALUES ({$siteId}, 'UM rec draft', '{$recName}', 'video/mp4', 0)");
$draftId = (int) $db->insert_id;
$podToken = bin2hex(random_bytes(16));
$db->query("DELETE FROM tblSettings WHERE settingKey = 'recordings.podcast_token' AND siteID = {$siteId}");
$db->query("INSERT INTO tblSettings (siteID, settingKey, settingValue, isSensitive) VALUES ({$siteId}, 'recordings.podcast_token', '{$podToken}', 0)");

$rangeMatrix('S stream', '/recordings/stream?id=' . $recId, $video, true);
$rangeMatrix('S podcast', '/recordings/podcast-media?id=' . $recId . '&token=' . $podToken, $video, false);

$outStream = $http('GET', '/recordings/stream?id=' . $recId, [], ['Range: bytes=0-1'], false);
$wrongTok  = $http('GET', '/recordings/podcast-media?id=' . $recId . '&token=' . str_repeat('0', 32), [], ['Range: bytes=0-1'], false);
$draftPod  = $http('GET', '/recordings/podcast-media?id=' . $draftId . '&token=' . $podToken, [], ['Range: bytes=0-1'], false);
$draftStr  = $http('GET', '/recordings/stream?id=' . $draftId, [], ['Range: bytes=0-1'], true);
$seeRefusal = static fn (array $r): array => [$r['status'], $r['headers']['accept-ranges'] ?? '', $r['headers']['content-range'] ?? '',
    str_contains($r['body'], substr($video, 20, 32))];
check('S: refusals come FIRST, with a Range header asked — signed out (sent to sign in), a wrong podcast token (403), an unpublished recording on either route (404): none sends a byte or a range header', [
    [$outStream['status'] === 302 || $outStream['status'] === 303, $outStream['headers']['content-range'] ?? '', str_contains($outStream['body'], substr($video, 20, 32))],
    $seeRefusal($wrongTok), $seeRefusal($draftPod), $seeRefusal($draftStr),
], [[true, '', false], [403, '', '', false], [404, '', '', false], [404, '', '', false]]);
check('S: the stream\'s 416 is a 416 now (it used to come back as a 404 with the 416\'s Content-Range on it)',
    $http('GET', '/recordings/stream?id=' . $recId, [], ['Range: bytes=' . strlen($video) . '-'], true)['status'], 416);

// =============================================================================
echo "P. The deprecation is FOUND when the call is put back (proves section U can fail)\n";
// =============================================================================
// For each handler: put `finfo_close($finfo);` back straight after the line
// that asks fileinfo, in the COPY, restart, upload the good file, and require
// the deprecation to be found. Then put the file back.
$handlerFile = [
    'noticeboard/api/upload' => 'noticeboard/api/upload.php',
    'documents/api/create'   => 'documents/api/create.php',
    'photos/upload'          => 'photos/upload.php',
    'venues/agreement-files' => 'venues/agreement-files.php',
    'assets/resource-save'   => 'assets/resource-save.php',
    'venues/import'          => 'venues/import.php',
];
foreach ($handlerFile as $name => $rel) {
    $path   = $child . $sep . 'web' . $sep . '_apps' . $sep . str_replace('/', $sep, $rel);
    $before = (string) file_get_contents($path);
    // The line that sets $sniffedMime from fileinfo, wherever it is.
    $after = preg_replace('/^(\s*)(\$sniffedMime\s*=[^\n]*finfo_(?:buffer|file)\([^\n]*\n)/m', '$1$2$1if ($finfo !== false) { finfo_close($finfo); }' . "\n", $before, 1, $count);
    if ($count !== 1) {
        check("P {$name}: the plant could be applied", $count, 1);
        continue;
    }
    file_put_contents($path, (string) $after);
    $startServer();
    $waitForServer();
    $signIn();
    $e0 = $errorMark();
    $l0 = $logMark();
    ($uploads[$name]['good'])();
    $found = $deprecations($e0, $l0);
    check("P {$name}: with finfo_close() put back, the deprecation IS recorded", count($found) > 0
        && str_contains(implode(' ', $found), 'finfo_close'), true);
    file_put_contents($path, $before);
}
$startServer();
$waitForServer();

// ── Tidy up the rows this test made ─────────────────────────────────────────
// Children first; the database is a throwaway one, so a failure here is said
// rather than fatal.
try {
    $db->query("DELETE FROM tblNoticeboardUploads WHERE createdByID = {$userId}");
    $db->query("DELETE FROM tblPhoto WHERE uploadedByUserID = {$userId}");
    $db->query("DELETE FROM tblDocuments WHERE title LIKE 'UM doc%'");
    $db->query("DELETE FROM tblVenueAgreementFiles WHERE agreementID = {$agreementId}");
    $db->query("DELETE FROM tblVenueAgreements WHERE agreementID = {$agreementId}");
    $db->query("DELETE FROM tblAssetResources WHERE assetID = {$assetId}");
    $db->query("DELETE FROM tblRecording WHERE recordingID IN ({$recId}, {$draftId})");
    $db->query("DELETE FROM tblSettings WHERE settingKey = 'recordings.podcast_token' AND siteID = {$siteId}");
} catch (Throwable $e) {
    echo '  ! could not tidy every row this test made: ' . $e->getMessage() . "\n";
}

echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "It drives six upload handlers and three media routes over HTTP on PHP " . PHP_VERSION . ".\n";
echo "It does NOT use Safari or Apache, and it cannot test venues/invoice-save.php,\n";
echo "which still calls finfo_close() (see check_no_deprecated_php_functions.py).\n";
exit($failed === 0 ? 0 : 1);
