<?php
// Path: tools/page-head-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The #569 head tags, read off real pages over HTTP 🔗
 * -----------------------------------------------------------------------------
 * Serves a copy of the portal with PHP's own web server and FETCHES pages, so
 * what is checked is what a browser — or a chat app drawing a preview card —
 * would really receive.
 *
 * WHY THIS EXISTS BESIDE THE AUDIT CHECK
 * `tools/audit-checks/check_page_head_tags.py` reads the source and proves that
 * every page which writes its own <head> requires head-meta.php. It cannot
 * prove that the tags come OUT, nor that they say the right thing: a page could
 * require the file inside a branch that never runs, or the file could throw, or
 * the canonical address could be built wrongly. Only fetching a page settles
 * that. The two together are the point; neither alone is enough.
 *
 * WHAT IT CHECKS
 *   A. Pages that print their own <head> — the sign-in page, the public forms —
 *      really carry a description and a preview card.
 *   B. Pages that use the shared header carry them too.
 *   C. The canonical rule: with an address SET, canonical and og:url use it;
 *      with NO address set, both are left out altogether, because
 *      PortalAddress would otherwise fall back to a host name the visitor
 *      chose. That is a correctness rule, not a nicety — see head-meta.php.
 *   D. A description written by a page is used in place of the fallback, and
 *      the fallback names the page rather than repeating one sentence.
 *   E. PLANTED FAULTS: with the require taken out of a page, this test must
 *      FAIL. A test that cannot see the fault it exists to catch proves
 *      nothing, and this project has shipped three such checks already.
 *   F. The browser-tab icon on a page that draws its own <head> (#680).
 *   G. /api-docs, on both ways in, and the kiosk screen — the pages that draw
 *      their own <head> outside web/_apps' ordinary pattern — with a planted
 *      fault on /api-docs. Since #821 also the three other exempt SCREENS
 *      (the projector display, the asset tag page, the confidence monitor),
 *      each of which must say out loud that it has no icon, with a planted
 *      fault on one of them.
 *   H. /openapi.json names the INSTALLATION's product, never one written into
 *      the description file (#821), with a planted fault.
 *
 * WHAT IT CANNOT DO
 *   - It does not judge how a preview card LOOKS, only what the page says.
 *   - It does not check og:image's picture really loads; it checks the tag is
 *     only written when a raster file is on disk.
 *   - It signs nobody in. Pages behind the sign-in are covered by part B
 *     through a page that is public and uses the shared header.
 *   - TOKEN-GATED PAGES ARE NOT COVERED, and they are a real gap: accepting
 *     an invitation, resetting a password, confirming an erasure request and
 *     unsubscribing all need a record seeded before they will draw their HTML,
 *     which needs a real user and organisation row because of the foreign
 *     keys. Each of those pages DOES require head-meta.php — the audit check
 *     proves that much — but nothing here proves the tags come out of them.
 *   - MariaDB: not covered here.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/page-head-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9670). The database must already
 *         hold `web/_sql/full_schema.sql`. Needs PHP's curl extension and
 *         `proc_open()`.
 * Exit:   0 only when every check ran and passed; 1 otherwise, including every
 *         refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
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
$port   = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9670;

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
$child = sys_get_temp_dir() . $sep . 'page-head-selftest-' . bin2hex(random_bytes(6));
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
    "<?php\n// Written by tools/page-head-selftest.php for ONE run; deleted with its folder.\nreturn "
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
// Written by tools/page-head-selftest.php for ONE run (deleted with its folder). Test only.
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

echo "=== A. Pages that print their own <head> carry a description and a preview card ===\n";

// `site.url` is set first, so the canonical and og:url tags are expected. The
// rule that they vanish WITHOUT it is part C.
$setSetting('site.url', 'https://portal.example.test');

// 🚪 The public visitor form REFUSES to draw unless its own switch is on:
//    `visitors/public-form.php` answers 404 with a line of plain text when
//    `visitors.public_capture_enabled` is not '1', and full_schema.sql seeds
//    it '0'. Without this the A04 check below fails on a page that is working
//    perfectly — which is exactly the mistake recorded in the A05 note
//    further down, made a second time in the same file. A test has to put the
//    portal into the state it means to test.
$setSetting('visitors.public_capture_enabled', '1');

$ownHeadPages = [
    'A01' => ['/login', 'the sign-in page'],
    'A02' => ['/forgot-password', 'the forgotten-password page'],
    'A03' => ['/prayer-requests/anonymous', 'the anonymous prayer-request form'],
    'A04' => ['/visit', 'the public visitor form'],
];
foreach ($ownHeadPages as $id => [$path, $words]) {
    $html = $get($path);
    if ($html === '') {
        bad($id, "{$words} ({$path}) could not be fetched at all");
        continue;
    }
    $desc = $tag($html, 'name', 'description');
    $ogt  = $tag($html, 'property', 'og:title');
    $ogu  = $tag($html, 'property', 'og:url');
    $tw   = $tag($html, 'name', 'twitter:card');
    $miss = [];
    if ($desc === null || trim($desc) === '') {
        $miss[] = 'description';
    }
    if ($ogt === null || trim($ogt) === '') {
        $miss[] = 'og:title';
    }
    if ($ogu === null || trim($ogu) === '') {
        $miss[] = 'og:url';
    }
    if ($tw !== 'summary') {
        $miss[] = 'twitter:card=summary (got ' . var_export($tw, true) . ')';
    }
    if ($miss === []) {
        ok($id, "{$words} carries a description and a preview card");
    } else {
        bad($id, "{$words} ({$path}) is missing: " . implode(', ', $miss));
    }
}

// 🧾 A05 — a bad link gets a real page, with the tags like any other.
//
// WORTH KNOWING HOW THIS CHECK CHANGED, because it is a small lesson in its
// own right. It began as "does /unsubscribe carry a preview card?" and FAILED.
// The page was innocent: asked without a token it answered HTTP 400 with one
// line of plain text and no HTML at all, so there was no <head> to put
// anything in. The fault was in this test, which assumed every public address
// returns a page. It was then INVERTED, to pin that plain-text behaviour.
//
// Then #569 fixed the behaviour itself — that bare line of text was exactly
// the "looks unfinished" problem, on the shape of page a stranger is MOST
// likely to meet, since the link arrives in an e-mail. So the check is now
// the right way round again, and asserts the thing originally wanted.
//
// The lesson: a test that pins current behaviour has to be re-read whenever
// that behaviour is deliberately changed, or it quietly defends the old fault.
$html = $get('/unsubscribe');
$desc = $tag($html, 'name', 'description');
if (str_contains($html, '<html') === true && $desc !== null && trim($desc) !== '') {
    ok('A05', '/unsubscribe with a broken link answers a real page, with a description');
} else {
    bad('A05', '/unsubscribe with a broken link should answer a proper page carrying the head tags', substr($html, 0, 300));
}

echo "\n=== B. A page using the shared header carries them too ===\n";
$html = $get('/help');
if ($html === '') {
    bad('B01', 'the help page could not be fetched');
} else {
    $desc = $tag($html, 'name', 'description');
    if ($desc !== null && trim($desc) !== '') {
        ok('B01', 'the help page, which uses header.php, carries a description');
    } else {
        bad('B01', 'the help page has no description, so header.php is not reaching head-meta.php');
    }
}

echo "\n=== C. The canonical rule: an address that a visitor could choose is never used ===\n";
$html = $get('/login');
$can  = $tag($html, 'canonical', '');
if ($can !== null && str_starts_with($can, 'https://portal.example.test') === true) {
    ok('C01', 'with site.url set, canonical uses that address (' . $can . ')');
} else {
    bad('C01', 'with site.url set, canonical should use it', 'got: ' . var_export($can, true));
}

$ogu = $tag($html, 'property', 'og:url');
if ($ogu === $can) {
    ok('C02', 'og:url and canonical agree');
} else {
    bad('C02', 'og:url and canonical disagree', 'canonical=' . var_export($can, true) . ' og:url=' . var_export($ogu, true));
}

// Now take the address away. PortalAddress would fall back to the Host header,
// which whoever sends the request chooses, so both tags must disappear.
$clearSetting('site.url');
$html = $get('/login');
$can  = $tag($html, 'canonical', '');
$ogu  = $tag($html, 'property', 'og:url');
$desc = $tag($html, 'name', 'description');
if ($can === null && $ogu === null) {
    ok('C03', 'with no address set, canonical and og:url are left out altogether');
} else {
    bad(
        'C03',
        'with no address set, canonical and og:url must be absent — a visitor chooses the host name',
        'canonical=' . var_export($can, true) . ' og:url=' . var_export($ogu, true)
    );
}
if ($desc !== null && trim($desc) !== '') {
    ok('C04', 'the description is still written when there is no address (it needs none)');
} else {
    bad('C04', 'the description disappeared along with the canonical address; it should not');
}

$setSetting('site.url', 'https://portal.example.test');

echo "\n=== D. A page's own description is used; the fallback names the page ===\n";
$html = $get('/prayer-requests/anonymous');
$desc = $tag($html, 'name', 'description');
if ($desc !== null && str_contains($desc, 'prayer request') === true) {
    ok('D01', 'the prayer-request form uses its own wording: "' . $desc . '"');
} else {
    bad('D01', 'the prayer-request form is not using its own description', 'got: ' . var_export($desc, true));
}

$htmlA = $get('/login');
$htmlB = $get('/help');
$descA = $tag($htmlA, 'name', 'description');
$descB = $tag($htmlB, 'name', 'description');
if ($descA !== null && $descB !== null && $descA !== $descB) {
    ok('D02', 'two different pages have two different descriptions');
} else {
    bad('D02', 'two pages share one description, which tells a reader nothing', 'A=' . var_export($descA, true) . ' B=' . var_export($descB, true));
}

echo "\n=== E. Planted faults — this test must be able to SEE one ===\n";
// The require is taken out of the COPY, never the repository.
$plantFile = $child . $sep . 'web' . $sep . '_apps' . $sep . 'prayer-requests' . $sep . 'anonymous.php';
$before    = (string) file_get_contents($plantFile);
$after     = preg_replace('#^.*head-meta\.php.*$#m', '', $before);
if ($after === $before || $after === null) {
    bad('E01', 'could not plant the fault: no head-meta.php require found in the copied page');
} else {
    file_put_contents($plantFile, $after);
    $html = $get('/prayer-requests/anonymous');
    $desc = $tag($html, 'name', 'description');
    if ($desc === null) {
        ok('E01', 'with the require taken out, the description is gone — this test can see the fault');
    } else {
        bad('E01', 'with the require taken out, a description was STILL found, so this test proves nothing', 'got: ' . var_export($desc, true));
    }
    file_put_contents($plantFile, $before);
    $html = $get('/prayer-requests/anonymous');
    if ($tag($html, 'name', 'description') !== null) {
        ok('E02', 'and the page is whole again once the fault is taken out');
    } else {
        bad('E02', 'the page did not recover after the planted fault was removed');
    }
}

echo "\n=== F. The browser-tab icon, on a page that draws its own <head> (#680) ===\n";
// -----------------------------------------------------------------------------
// WHY THIS IS HERE. With no `<link rel="icon">` a browser falls back to
// `/favicon.ico` by convention. There is no such file in the web root and
// nothing answers that address, so the request 404s. Until 30 October 2026 the
// icon links were written by `header.php`, which a page drawing its own
// `<head>` never reaches — and that was live on the SIGN-IN PAGE, which is the
// first page any visitor sees, because `/` redirects to `/login`.
//
// It is checked over real HTTP rather than by reading the template, because the
// question is what a browser actually receives. And the file the link POINTS AT
// is checked on disk, because a link to a missing picture is the same 404 with
// an extra step — which is the whole fault being fixed.
$signIn = $get('/login');
if ($signIn === '') {
    bad('F01', 'the sign-in page could not be fetched at all, so nothing here was checked');
} else {
    preg_match('#<link[^>]*\brel\s*=\s*"icon"[^>]*>#i', $signIn, $iconTag);
    $hasIcon = $iconTag !== [];
    if ($hasIcon === true) {
        ok('F01', 'the sign-in page links a browser-tab icon: ' . trim($iconTag[0]));
    } else {
        bad('F01', 'the sign-in page links NO icon, so every visitor\'s browser asks for /favicon.ico and gets a 404', 'no <link rel="icon"> in the page it served');
    }

    // F02: FOLLOW the link. Asking the server for it, rather than looking for
    //      the file on disk, is deliberate twice over: it is what a browser
    //      actually does, and the first version of this check reached for
    //      PORTAL_WEBROOT, a constant that exists inside the portal and not in
    //      this test's own process — so it died rather than reporting
    //      anything. A link pointing at a missing picture is the same 404 the
    //      whole fix is about, one step later.
    //
    //      WHAT IT ASSUMES: a root-relative href. `tblSites.faviconPath` is a
    //      free-text field, so an organisation that put a WHOLE address there
    //      would make this fetch `http://127.0.0.1:port/https://…` and go red
    //      on a portal that works perfectly. No fixture does that, and the
    //      admin form turns an empty value into NULL rather than '', but if
    //      this check ever fails with a nonsense address in the message, that
    //      is the reason — not a missing icon.
    $status = static function (string $path) use ($port): int {
        $ch = curl_init('http://127.0.0.1:' . $port . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_NOBODY => true]);
        curl_exec($ch);

        return (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    };
    if ($hasIcon === true && preg_match('#href\s*=\s*"([^"]+)"#i', $iconTag[0], $href) === 1) {
        $iconStatus = $status($href[1]);
        if ($iconStatus === 200) {
            ok('F02', 'and the picture it names really is served: ' . $href[1]);
        } else {
            bad('F02', 'the icon link points at something the server will not give, which is the same 404 one step later', $href[1] . ' answered HTTP ' . $iconStatus);
        }
    } else {
        bad('F02', 'the icon link has no href to follow');
    }

    // F03: /favicon.ico really does 404 — the premise of the whole fix. If a
    //      file were ever added there this check would go red, which is the
    //      right outcome: the note above would then be out of date.
    $fallbackStatus = $status('/favicon.ico');
    if ($fallbackStatus === 404) {
        ok('F03', 'and /favicon.ico is still unanswered (404), which is why the link above has to be there');
    } else {
        bad('F03', 'something now answers /favicon.ico, so the reason for this section has changed and its note is stale', 'HTTP ' . $fallbackStatus);
    }

    // F04: AN ORDINARY PAGE MUST NOT HAVE LOST IT. The icon links moved OUT of
    //      header.php, which is where every ordinary page got them, and into
    //      head-meta.php, which header.php requires. That is one require away
    //      from being a regression for every page in the portal rather than a
    //      fix for a few, so it is checked rather than assumed — and checked on
    //      a page that goes through the shared header, not through its own head.
    $ordinary = $get('/help');
    if ($ordinary === '') {
        bad('F04', 'an ordinary page could not be fetched, so this half was not checked');
    } elseif (preg_match('#<link[^>]*\brel\s*=\s*"icon"[^>]*>#i', $ordinary) === 1) {
        ok('F04', 'an ordinary page, through the shared header, still carries the icon too');
    } else {
        bad('F04', 'an ordinary page has LOST its icon: moving the links into head-meta.php broke the ordinary path');
    }
}

echo "\n=== G. /api-docs and the kiosk, which draw their own <head> outside the router's pages (#569) ===\n";
// -----------------------------------------------------------------------------
// WHY THIS IS HERE. `/api-docs` is answered by the web server directly and
// draws its own <head>; until 7 November 2026 it carried no description, no
// <h1> and NO ICON LINK, so the browser fell back to /favicon.ico, got a 404,
// and the browser audit reported it as "a JavaScript error at 320px". The
// static check (check_page_head_tags.py) now reads the web root as well, but
// it proves only that the require is THERE; this proves the tags come OUT,
// on BOTH ways in — through the router (`/api-docs`) and straight from the
// web server (`/api-docs/index.php`, which the test router serves directly
// with nothing loaded, as Apache does).
//
// The kiosk is on the static check's EXEMPT list (its address is never
// shared), so nothing else here looks at it: G05 pins its hand-written
// description and its deliberately EMPTY icon, which is what stops the same
// /favicon.ico 404 on a kiosk tablet.
//
// WHAT IT CANNOT DO: say whether the browser reports a console error. That
// needs a browser; `tools/layout-audit.mjs` is the tool for it. What it CAN
// say is the cause that error had — a page with no `rel="icon"` link.
$iconOn = static fn (string $html): bool
    => preg_match('#<link[^>]*\brel\s*=\s*"icon"[^>]*>#i', $html) === 1;
foreach (['G01' => '/api-docs', 'G02' => '/api-docs/index.php'] as $id => $path) {
    $html = $get($path);
    if ($html === '' || str_contains($html, 'id="swagger-ui"') === false) {
        bad($id, "{$path} did not answer the API documentation page at all", substr($html, 0, 300));
        continue;
    }
    $miss = [];
    $desc = $tag($html, 'name', 'description');
    if ($desc === null || trim($desc) === '') {
        $miss[] = 'a description';
    }
    if ($iconOn($html) === false) {
        $miss[] = 'an icon link (so the browser asks /favicon.ico and gets a 404)';
    }
    if (preg_match('#<h1\b[^>]*>\s*\S#i', $html) !== 1) {
        $miss[] = 'an <h1>';
    }
    if ($tag($html, 'property', 'og:title') === null) {
        $miss[] = 'a preview card (site.url is set, so one is expected)';
    }
    // ONE canonical address whichever way the page was reached, and never a
    // `.php` one (#569 round 1: `/api-docs/index.php` gave itself
    // `…/api-docs/index.php` before the page named its own canonical path).
    $ogUrl = (string) ($tag($html, 'property', 'og:url') ?? '');
    if (preg_match('#/api-docs$#', $ogUrl) !== 1) {
        $miss[] = 'og:url ending exactly /api-docs (got "' . $ogUrl . '")';
    }
    if (preg_match('#<link[^>]*\brel\s*=\s*"canonical"[^>]*href\s*=\s*"([^"]*)"#i', $html, $cm) !== 1
        || preg_match('#/api-docs$#', $cm[1]) !== 1) {
        $miss[] = 'a canonical link ending exactly /api-docs';
    }
    if ($miss === []) {
        ok($id, "{$path} carries a description, an icon link, an <h1>, a preview card and the one canonical address /api-docs");
    } else {
        bad($id, "{$path} is missing: " . implode('; ', $miss));
    }
}

// G03/G04: PLANT the fault on the direct path. Without the require, the
// description and the icon must both be gone — or G02 proves nothing.
//
// The require is REPLACED by the one variable the page still needs from it
// (`$siteName`, which head-meta.php works out and the <title> and the heading
// use), rather than deleted. Deleted, the page would die on an undefined
// variable half way through, the tags would be "missing" because NOTHING was
// printed, and Z01 would go red for a reason that is not the fault being
// planted. A plant has to leave the page working and remove only the thing
// under test.
$plantDocs  = $child . $sep . 'web' . $sep . 'public_html' . $sep . 'api-docs' . $sep . 'index.php';
$docsBefore = (string) file_get_contents($plantDocs);
$docsAfter  = preg_replace('#^require PORTAL_CORE[^\n]*head-meta\.php[^\n]*$#m', "\$siteName = 'Portal';", $docsBefore);
if ($docsAfter === null || $docsAfter === $docsBefore) {
    bad('G03', 'could not plant the fault: no head-meta.php require found in the copied /api-docs page');
} else {
    file_put_contents($plantDocs, $docsAfter);
    $html = $get('/api-docs/index.php');
    if (str_contains($html, 'id="swagger-ui"') === false) {
        bad('G03', 'the planted /api-docs page did not draw at all, so the plant broke more than the tags', substr($html, 0, 300));
    } elseif ($tag($html, 'name', 'description') === null && $iconOn($html) === false) {
        ok('G03', 'with the require taken out of /api-docs, the description and the icon are gone — G02 can see the fault');
    } else {
        bad('G03', 'with the require taken out of /api-docs, a description or an icon was STILL found, so G02 proves nothing');
    }
    file_put_contents($plantDocs, $docsBefore);
    $html = $get('/api-docs/index.php');
    if ($tag($html, 'name', 'description') !== null && $iconOn($html) === true) {
        ok('G04', 'and /api-docs is whole again once the fault is taken out');
    } else {
        bad('G04', '/api-docs did not recover after the planted fault was removed');
    }
}

// G05: the kiosk's "unavailable" screen — what a request with no token gets.
$html = $get('/assets/kiosk');
$desc = $tag($html, 'name', 'description');
if ($html !== '' && $desc !== null && trim($desc) !== '' && preg_match('#<link[^>]*\brel="icon"\s+href="data:,"#i', $html) === 1) {
    ok('G05', 'the kiosk screen carries a description and an explicitly empty icon, so no /favicon.ico request is made');
} else {
    bad('G05', 'the kiosk screen is missing its description or its empty icon link', substr($html, 0, 400));
}

// -----------------------------------------------------------------------------
// G06-G10: THE OTHER THREE EXEMPT SCREENS (#821).
//
// WHY. The projector display (`worship/display`), the asset tag page
// (`a/{token}`, drawn by assets/tag.php) and the confidence monitor
// (`service-plans/confidence`) are on the static check's EXEMPT list, like the
// kiosk, and drew their own <head> with no icon link — so a browser asked for
// /favicon.ico on every load and got a 404 (F03 pins that the address is
// unanswered). That was MEASURED in a real browser before the fix, not
// inferred: on 9 November 2026 tools/layout-audit.mjs reported "Failed to load
// resource ... 404 — at /favicon.ico" for each of the three, one run per
// screen. Each now carries the kiosk's explicit empty icon and a one-line
// description. These checks prove that is what comes OUT, over HTTP.
//
// Each screen draws only for a real record, so the test makes one of each and
// removes them again at the end. The confidence monitor needs a signed-in
// person and the Service Plans app switched on, which ships switched OFF
// (`service_plans.enabled` is seeded '0'), so both are arranged here and the
// switch is put back to whatever it was.
$g8 = ['displayPlan' => 982101, 'plan' => 982102, 'asset' => 982103, 'user' => 982104];
$displayToken = bin2hex(random_bytes(32));
$tagToken     = bin2hex(random_bytes(16));
$g8Password   = bin2hex(random_bytes(12)) . 'Aa1!';
$g8User       = 'selftest-pagehead-' . bin2hex(random_bytes(3));

$readSetting = static function (string $key) use ($db): ?string {
    $stmt = $db->prepare('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) === true ? (string) $row['settingValue'] : null;
};

/** Remove every record G06-G10 made. Safe to call twice. */
$g8Tidy = static function () use ($db, $g8): void {
    $db->query('DELETE FROM tblAssetAudit WHERE assetID = ' . $g8['asset']);
    $db->query('DELETE FROM tblAssets WHERE assetID = ' . $g8['asset']);
    $db->query('DELETE FROM tblServicePlans WHERE planID = ' . $g8['displayPlan']);
    $db->query('DELETE FROM tblServicePlan WHERE planID = ' . $g8['plan']);
    // Signing in writes rows in other tables that point at the account (the
    // activity log among them), and not every one of those links removes its
    // rows with the account. So every table whose link points at tblUsers is
    // asked, by the schema itself rather than by a list typed here, and the
    // test person's rows are removed from it first. Names come from
    // information_schema, never from a request, so building them into the
    // statement is safe.
    $links = $db->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers'"
    );
    foreach ($links->fetch_all(MYSQLI_ASSOC) as $link) {
        $db->query('DELETE FROM `' . str_replace('`', '', (string) $link['TABLE_NAME']) . '` WHERE `'
            . str_replace('`', '', (string) $link['COLUMN_NAME']) . '` = ' . $g8['user']);
    }
    $db->query('DELETE FROM tblUsers WHERE userID = ' . $g8['user']);
};

$servicePlansWas = $readSetting('service_plans.enabled');
$g8Tidy();
$stmt = $db->prepare('INSERT INTO tblServicePlans (planID, name, siteID, displayToken, isActive) VALUES (?, \'Page-head display plan\', 1, ?, 1)');
$stmt->bind_param('is', $g8['displayPlan'], $displayToken);
$stmt->execute();
$stmt = $db->prepare('INSERT INTO tblServicePlan (planID, siteID, title, serviceDate) VALUES (?, 1, \'Page-head confidence plan\', CURDATE())');
$stmt->bind_param('i', $g8['plan']);
$stmt->execute();
$stmt = $db->prepare('INSERT INTO tblAssets (assetID, siteID, name, publicToken, publicPageEnabled, isConfidential, isDeleted) VALUES (?, 1, \'Page-head projector\', ?, 1, 0, 0)');
$stmt->bind_param('is', $g8['asset'], $tagToken);
$stmt->execute();
$stmt = $db->prepare('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, \'Page-head Member\', ?, 1, 0, 0)');
$g8Email = $g8User . '@selftest.invalid';
$stmt->bind_param('is', $g8['user'], $g8Email);
$stmt->execute();
$stmt = $db->prepare('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, 0, 0)');
$stmt->bind_param('i', $g8['user']);
$stmt->execute();
$g8Hash = password_hash($g8Password, PASSWORD_DEFAULT);
$stmt = $db->prepare('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)');
$stmt->bind_param('iss', $g8['user'], $g8User, $g8Hash);
$stmt->execute();
$stmt->close();
$setSetting('service_plans.enabled', 'true');

/** Fetch with a cookie jar, so a sign-in carries over. */
$g8Jar   = $child . $sep . 'g8-cookies.txt';
$getAsMe = static function (string $path, array $post = []) use ($port, $g8Jar): string {
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEJAR      => $g8Jar,
        CURLOPT_COOKIEFILE     => $g8Jar,
    ]);
    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    return is_string($body) === true ? $body : '';
};

/** The screen carries a description AND exactly the empty icon, and no other icon link. */
$emptyIconOnly = static function (string $html) use ($tag): string {
    $miss = [];
    $desc = $tag($html, 'name', 'description');
    if ($desc === null || trim($desc) === '') {
        $miss[] = 'a description';
    }
    $icons = preg_match_all('#<link[^>]*\brel\s*=\s*"icon"[^>]*>#i', $html, $all);
    if ($icons !== 1 || preg_match('#\bhref\s*=\s*"data:,"#i', $all[0][0] ?? '') !== 1) {
        $miss[] = 'exactly one icon link, the empty `data:,` one (found ' . (int) $icons . ')';
    }
    return implode('; ', $miss);
};

$screens = [
    'G06' => ['/worship/display?t=' . $displayToken, 'the projector display', 'Page-head display plan', false],
    'G07' => ['/a/' . $tagToken, 'the asset tag page', 'Page-head projector', false],
    'G08' => ['/service-plans/confidence?id=' . $g8['plan'], 'the confidence monitor', 'Page-head confidence plan', true],
];
// Sign in once, for G08. The form token comes from the sign-in page itself.
$signInPage = $getAsMe('/login');
preg_match('#name="csrf_token"\s+value="([^"]+)"#', $signInPage, $csrfMatch);
$getAsMe('/login', ['csrf_token' => $csrfMatch[1] ?? '', 'username' => $g8User, 'password' => $g8Password]);

foreach ($screens as $id => [$path, $words, $marker, $signedIn]) {
    // The two token pages are fetched SIGNED OUT on purpose: a signed-in
    // administrator is redirected from the tag page to the internal item page,
    // which uses the ordinary header and so would prove nothing here.
    $html = $signedIn === true ? $getAsMe($path) : $get($path);
    if ($html === '' || str_contains($html, $marker) === false) {
        // Not the screen at all — a refusal, a sign-in page, an app switched
        // off. Said plainly, so a fixture fault never reads as a page fault.
        bad($id, "{$words} ({$path}) did not draw the screen, so nothing about its head was checked", substr($html, 0, 300));
        continue;
    }
    $missing = $emptyIconOnly($html);
    if ($missing === '') {
        ok($id, "{$words} carries a description and an explicitly empty icon, so no /favicon.ico request is made");
    } else {
        bad($id, "{$words} is missing: {$missing}");
    }
}

// G09/G10: PLANT the fault on one screen. With the icon line taken out of the
// copied projector display, G06's question must come back with an answer — or
// G06-G08 prove nothing. Only the icon line is removed, so the page still
// draws (a plant that broke the page would be "caught" for the wrong reason).
$plantShow   = $child . $sep . 'web' . $sep . '_apps' . $sep . 'worship' . $sep . 'display.php';
$showBefore  = (string) file_get_contents($plantShow);
$showAfter   = preg_replace('#^\s*<link rel="icon" href="data:,">\s*$\n#m', '', $showBefore);
if ($showAfter === null || $showAfter === $showBefore) {
    bad('G09', 'could not plant the fault: no empty icon line found in the copied projector display');
} else {
    file_put_contents($plantShow, $showAfter);
    $html = $get('/worship/display?t=' . $displayToken);
    if (str_contains($html, 'Page-head display plan') === false) {
        bad('G09', 'the planted projector display did not draw at all, so the plant broke more than the icon', substr($html, 0, 300));
    } elseif ($emptyIconOnly($html) !== '') {
        ok('G09', 'with the icon line taken out of the projector display, the check sees it — G06 can see the fault');
    } else {
        bad('G09', 'with the icon line taken out, the projector display STILL passed, so G06 proves nothing');
    }
    file_put_contents($plantShow, $showBefore);
    $html = $get('/worship/display?t=' . $displayToken);
    if ($emptyIconOnly($html) === '') {
        ok('G10', 'and the projector display is whole again once the fault is taken out');
    } else {
        bad('G10', 'the projector display did not recover after the planted fault was removed');
    }
}
$g8Tidy();
if ($servicePlansWas === null) {
    $clearSetting('service_plans.enabled');
} else {
    $setSetting('service_plans.enabled', $servicePlansWas);
}

echo "\n=== H. /openapi.json names the installation's product, never a built-in one (#821) ===\n";
// -----------------------------------------------------------------------------
// WHY. The product name is chosen at install (#296) and read through
// Site::productName(). `web/_core/api-spec.json` is served ONLY by
// `web/public_html/openapi.php`, which replaces the title and the contact's
// name with the installation's product and publisher (#307). Until #821 the
// file itself still said "WebMS Intra REST API" and named the publishing
// company — never served, but what anybody reading the file took the API to
// be called, and what a second reader of the file would have shown.
//
// H01 reads the FILE: no product name from brand-defaults.php in the title or
// the contact. The names come from that file, never from a list typed here, so
// a new brand added there is covered without touching this test.
// H02/H03 read the SERVED description with a made-up product and publisher,
// so a title that merely happened to match the default could not pass.
// H04/H05 plant the fault: an openapi.php that stops replacing the title.
$presets   = (array) (require $repo . $sep . 'web' . $sep . '_core' . $sep . 'brand-defaults.php');
$brandBits = [];
foreach ($presets as $preset) {
    foreach (['name', 'publisher'] as $field) {
        $value = trim((string) ($preset[$field] ?? ''));
        if ($value !== '') {
            $brandBits[$value] = true;
        }
    }
}
$specRaw = (string) file_get_contents($repo . $sep . 'web' . $sep . '_core' . $sep . 'api-spec.json');
$specArr = json_decode($specRaw, true);
$infoText = is_array($specArr) === true
    ? (string) ($specArr['info']['title'] ?? '') . ' | ' . (string) ($specArr['info']['contact']['name'] ?? '')
    : '';
$foundBits = array_values(array_filter(
    array_keys($brandBits),
    static fn (string $bit): bool => stripos($infoText, $bit) !== false
));
if ($brandBits === [] || $infoText === '') {
    bad('H01', 'could not read the brand names or the description file, so nothing was checked');
} elseif ($foundBits === []) {
    ok('H01', 'api-spec.json names none of the ' . count($brandBits) . ' product or publisher names in brand-defaults.php in its title or contact');
} else {
    bad('H01', 'api-spec.json writes a product or company name into its title or contact, which openapi.php is meant to supply', 'found: ' . implode(', ', $foundBits));
}

$nameWas      = $readSetting('product.name');
$publisherWas = $readSetting('product.publisher');
$plantedName  = 'Planted Product ' . bin2hex(random_bytes(3));
$plantedPub   = 'Planted Publisher ' . bin2hex(random_bytes(3));
$setSetting('product.name', $plantedName);
$setSetting('product.publisher', $plantedPub);

$served = json_decode($get('/openapi.json'), true);
$title  = is_array($served) === true ? (string) ($served['info']['title'] ?? '') : '';
$who    = is_array($served) === true ? (string) ($served['info']['contact']['name'] ?? '') : '';
if ($title === $plantedName . ' REST API') {
    ok('H02', 'the served title follows the installation\'s product name: "' . $title . '"');
} else {
    bad('H02', 'the served title does not follow the installation\'s product name', 'expected "' . $plantedName . ' REST API", got "' . $title . '"');
}
if ($who === $plantedPub) {
    ok('H03', 'the served contact names the installation\'s publisher: "' . $who . '"');
} else {
    bad('H03', 'the served contact does not name the installation\'s publisher', 'expected "' . $plantedPub . '", got "' . $who . '"');
}

$plantSpec  = $child . $sep . 'web' . $sep . 'public_html' . $sep . 'openapi.php';
$specBefore = (string) file_get_contents($plantSpec);
$specAfter  = preg_replace('#^\$spec\[\'info\'\]\[\'title\'\] = [^\n]*$#m', '// planted: title left as the file says', $specBefore);
if ($specAfter === null || $specAfter === $specBefore) {
    bad('H04', 'could not plant the fault: no title replacement found in the copied openapi.php');
} else {
    file_put_contents($plantSpec, $specAfter);
    $planted = json_decode($get('/openapi.json'), true);
    $pTitle  = is_array($planted) === true ? (string) ($planted['info']['title'] ?? '') : '';
    if ($pTitle !== '' && $pTitle !== $plantedName . ' REST API') {
        ok('H04', 'with the replacement taken out of openapi.php, the served title is "' . $pTitle . '" — H02 can see the fault');
    } else {
        bad('H04', 'with the replacement taken out, the title was still right (or nothing was served), so H02 proves nothing', 'got "' . $pTitle . '"');
    }
    file_put_contents($plantSpec, $specBefore);
    $again = json_decode($get('/openapi.json'), true);
    if (is_array($again) === true && (string) ($again['info']['title'] ?? '') === $plantedName . ' REST API') {
        ok('H05', 'and /openapi.json is whole again once the fault is taken out');
    } else {
        bad('H05', '/openapi.json did not recover after the planted fault was removed');
    }
}

// Put the two installation settings back exactly as they were: they are
// seeded rows, so deleting them would change the database for the next test.
foreach (['product.name' => $nameWas, 'product.publisher' => $publisherWas] as $key => $was) {
    if ($was === null) {
        $clearSetting($key);
    } else {
        $setSetting($key, $was);
    }
}

echo "\n=== Z. Clean-up ===\n";
$clearSetting('site.url');
$clearSetting('visitors.public_capture_enabled');
$log = $child . $sep . 'php-errors.log';
$errors = is_file($log) === true ? trim((string) file_get_contents($log)) : '';
// A page that died half-drawn still returns a body, so a fatal can hide behind
// a passing tag check. The server's own log is the only place it shows.
if ($errors === '') {
    ok('Z01', 'no page hit a fatal or parse error during the whole run');
} else {
    bad('Z01', 'the server logged errors while these pages were fetched', substr($errors, 0, 1200));
}

echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
