<?php
// Path: tools/public-website-tick-selftest.php
/**
 * -----------------------------------------------------------------------------
 * "Show on the public website" for events, over real HTTP 🌐🧪 (#493 P1)
 * -----------------------------------------------------------------------------
 * The owner's decisions D-057 (nothing public until somebody ticks it), D-077
 * (who may tick it), D-078 (a switch AND optional dates), D-080 and D-081 (how
 * copied-in events get there, the installation capping the organisation).
 *
 * It drives the REAL pages — the Manage Events form and its handler, the events
 * API (an API key AND a signed-in session), the public submission form, the
 * public door's events feed (`/w/{name}/events.json`, #493 P4; until P4b the
 * countdown feed `/widget/countdown.json`, deleted with the widget, D-084),
 * `/sitemap.xml`, and the copied-in
 * rule's card on /admin/calendar/feeds with its handler — and reads every
 * result back out of the database rather than believing a message.
 *
 * WHAT IT COVERS
 *   C  THE LIVE EXPOSURE THIS PACKAGE CLOSES: the door's events feed shows a
 *      published, public event ONLY while it is ticked and today is inside its
 *      dates (both ends inclusive), never a members-only, draft, deleted or
 *      other organisation's one.
 *   M  /sitemap.xml lists a ticked event and not an unticked one.
 *   F  the form: an administrator of the organisation can tick and set dates,
 *      and so can an administrator made on Admin → Users with the portal-wide
 *      `isAdmin` flag who belongs here (round 1); a Save that sends no website
 *      fields leaves them alone (#677), and so does a publisher's untouched
 *      Save; a posted "0" is off; bad dates are refused; a repeating series
 *      made with the box ticked carries it to every date, and says so.
 *      THE FORM'S REFUSAL IS DRIVEN ONLY IN A PLANTED WORLD (P25), and that is
 *      a fact about P1, not a gap in this test: every account that can open
 *      Manage Events is an administrator, and every administrator may publish,
 *      so no real account reaches it. P25 makes the permission say no to
 *      everybody and requires the page to draw the state as words and the
 *      handler to refuse and record a hand-made change. (Until round 4 this
 *      said a flag-holder was refused and a Website Editor could change it
 *      through the form. Neither is so: the editor is K1, asked of the method,
 *      because an editor cannot open the form.)
 *   A  the events API: an API key can never change it (403, recorded) but may
 *      send the stored values back; a signed-in administrator can; a bad date
 *      is 422.
 *   B  the public submission form ignores the fields whatever it is sent.
 *   E  the e-mail to the person who suggested an event (#859, D-134): a
 *      visitor suggests one on the real form and an administrator approves it
 *      on the real moderation page; exactly one message to their address is
 *      in the send log, with the right words; a second press sends none; a
 *      rejection, no address, a bad address and a signed-in member's
 *      suggestion send none; a route that THROWS still approves.
 *   K  who may publish, asked of the real `PublicWebsite::mayPublish()` in the
 *      copy (a separate process): an administrator made on Admin → Users with
 *      the portal-wide flag counts where they BELONG, and nowhere else.
 *   R  the copied-in rule: who gets which form (the stricter rule too, so the
 *      page's own gate is what refuses), a looser organisation choice refused,
 *      the installation's choice capping the organisation's at READ time too,
 *      an unreadable rule counting as the stricter, and the door feed AND the
 *      sitemap following the rule for a copied-in event.
 *   V  what the calendar's page SAYS: every "on the public website" badge,
 *      label and approval sentence is true under the rule in force.
 *   S  the noticeboard's foyer screen (#493 P2b, D-086): no secret, a bad one
 *      or a replaced one answers a bare 404; the setup page is for the
 *      noticeboard's managers; a link shows only ITS organisation's posters,
 *      public ones only unless the installation permits a choice AND the
 *      organisation chose every poster; who may save each choice; the
 *      headers that keep the secret address out of other websites' logs and
 *      search engines; poster dates on the organisation's own calendar.
 *   W  Admin → Public website (#493 P3; D-082, D-085, D-093, D-094, D-095):
 *      who may open it (both global administrators and a Website Set-up
 *      holder; NOT an ordinary administrator, a flag administrator, a member,
 *      a Website Editor or a holder in another organisation; nobody by role
 *      once the permission is emptied); the save handler and the check button
 *      asking the same gate; every bad main website, framing website and
 *      public address refused with nothing written and recorded; the framing
 *      list read from the organisation's own row only, and checked again on
 *      read; one organisation's values never reaching another's; a public
 *      address another organisation is APPROVED for refused without naming
 *      it, and one it has only asked for accepted beside it (D-121); the
 *      installation's framing switch at save AND at read; the general editor's
 *      lock; "Check my set-up" (POST only, the form token, its limit, nothing
 *      ticked when nothing is set, a loopback main website unreachable, the
 *      door row never a tick); D-093 on the Roles and Users pages; and the
 *      menu link and the dashboard tile following the gate.
 *   P  the plants: each guard taken out of a COPY of the portal, and the
 *      scenario that should catch it re-run and required to go red.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - Nothing here judges whether a page's words are TRUE; it pins what the
 *     database holds and which controls a page draws.
 *   - It runs ONE engine at a time and one web worker, so two people changing
 *     the switch at once is not exercised (only CHANGED values are written,
 *     so the last change wins; a non-publisher's unchanged value is never
 *     written at all).
 *   - The door's feed is cached for a minute by browsers and anything in
 *     between; curl here caches nothing, so that minute is not tested.
 *   - The series-repattern half (a date the pattern ADDS is off the website)
 *     is check S18w of tools/event-repeat-pages-selftest.php, not here.
 *   - OVER HTTP, the set-up check can only be shown a refused main website
 *     (W11, a loopback address): SafeFetch refuses every loopback address,
 *     and this test reaches nothing outside the machine. So the other answers
 *     are driven IN-PROCESS (W21): a small TLS server of this test's own, a
 *     certificate this test makes, SafeFetch's `$testResolverOverride` naming
 *     it, and a scratch copy of SafeFetch told to trust that certificate and
 *     to record the options it is handed — the real SafeFetch code otherwise,
 *     the real wording function, real redirects, a real certificate failure.
 *     The wording itself is also asked directly for the cheapest cases (W17).
 *     W22 runs the REAL `checkSetUp()` (it has no test hook) against that
 *     recording copy, which is what pins the overall deadline on the path the
 *     portal really takes; W23 runs it again in a PHP started with curl_init
 *     switched off, which is the only way the "no curl" words are reached.
 *   - Two saves of one organisation's public address at the SAME moment are
 *     not driven (one web worker); the wording for that clash is read, not run.
 *   - Section W does not run the Users page's script, so the locked role
 *     box's hidden carrier being switched on is NOT driven (P86 says so); the
 *     handler's refusal is what is proved. Nothing here reaches a website
 *     outside the machine; real answers are driven in-process (W21, W22)
 *     against the test's own TLS server, and over HTTP only a refused
 *     loopback address (W11).
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         [SELFTEST_WEB_PORT=9493] php tools/public-website-tick-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql` (with
 *         migration 262). Needs PHP's curl and openssl extensions and
 *         `proc_open()`. It REFUSES rather than skipping when anything is missing.
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
const PWT_EXPECTED_CHECKS = 316; // + four in round 3 of #885 (G18-G21) and three plants (PG16-PG18); + seven in section G and six plants in round 2 of #885 (G11-G17, PG10-PG15); + ten in section G (#885, #853, #854, #855: G1-G10) and nine plants PG1-PG9 on the 277 before: then 240, then Z1 (#493 P4b: every read of the door's feed answered), then #859's E0-E13 with E8b (15) and P120-P123 (4), then #859 round 2's E14-E22 with E19b (10) and P124-P130 (7)

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
foreach (['curl_init', 'proc_open', 'openssl_pkey_new'] as $needed) {
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
    'SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}
$hasColumn = (int) ($db->query(
    "SELECT COUNT(*) AS `n` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() "
    . "AND `TABLE_NAME` = 'tblEvents' AND `COLUMN_NAME` IN ('showOnWebsite', 'websiteFrom', 'websiteUntil')"
)->fetch_assoc()['n'] ?? 0);
$hasRule = (int) ($db->query(
    "SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `settingKey` = 'public.events.copiedRule' AND `siteID` IS NULL"
)->fetch_assoc()['n'] ?? 0);
if ($hasColumn !== 3 || $hasRule !== 1) {
    refuse('this database was not built from the current install script (migration 258 is missing)');
}
// Section W needs Admin → Public website's table (#493 P3, migration 262).
if ((int) ($db->query("SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblPublicHosts'")->fetch_assoc()['n'] ?? 0) !== 1) {
    refuse('this database was not built from the current install script (migration 262 is missing)');
}
// Section S needs the foyer screen's table (#493 P2b, migration 261).
if ((int) ($db->query("SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblNoticeboardFoyer'")->fetch_assoc()['n'] ?? 0) !== 1) {
    refuse('this database was not built from the current install script (migration 261 is missing)');
}

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'pwt-http-' . bin2hex(random_bytes(6));
$state['child'] = $child;
register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_dir($state['child']) === true) {
        // A recursive delete of a folder THIS run made, under the system temp
        // folder, whose name holds random bytes.
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
    "<?php\n// Written by tools/public-website-tick-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/public-website-tick-selftest.php for ONE run. Test only.
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

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9400 + random_int(0, 40);
$jar  = $child . $sep . 'cookies.txt';

$startServer = static function () use (&$state, $child, $sep, $port): void {
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
};

/** One request: status, Location and body. Redirects are NOT followed: they are facts checked here. */
$http = static function (string $method, string $path, array|string $post = [], array $headers = [], bool $withJar = true) use ($port, $jar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    $opts   = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($withJar === true) {
        $opts[CURLOPT_COOKIEJAR]  = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    curl_setopt_array($handle, $opts);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, is_array($post) === true ? http_build_query($post) : $post);
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
        // Section S reads the foyer screen's response headers (#493 P2b).
        'head'     => $head,
    ];
};

$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
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

// ── The organisations ───────────────────────────────────────────────────────
$siteOne = (int) ($db->query('SELECT MIN(`siteID`) AS `s` FROM `tblSites`')->fetch_assoc()['s'] ?? 0);
if ($siteOne !== 1) {
    refuse('this test reads the countdown and the sitemap as a signed-out visitor, who is put in organisation 1; the lowest organisation here is ' . $siteOne);
}
$zoneOne = (string) ($db->query("SELECT `timezone` FROM `tblSites` WHERE `siteID` = {$siteOne}")->fetch_assoc()['timezone'] ?? '');
try {
    $clockOne = new DateTimeZone($zoneOne);
} catch (Throwable $e) {
    $clockOne = new DateTimeZone('UTC');
}
// "Today" on the ORGANISATION's calendar, which is what the website's dates
// are judged against (the column comments say so; D-078).
$today     = (new DateTimeImmutable('now', $clockOne))->format('Y-m-d');
$yesterday = (new DateTimeImmutable('now', $clockOne))->modify('-1 day')->format('Y-m-d');
$tomorrow  = (new DateTimeImmutable('now', $clockOne))->modify('+1 day')->format('Y-m-d');

$db->query("DELETE FROM `tblSettings` WHERE `siteID` IN (SELECT `siteID` FROM `tblSites` WHERE `siteKey` = 'selftest-pwt-two')");
$db->query("DELETE FROM `tblEvents` WHERE `siteID` IN (SELECT `siteID` FROM `tblSites` WHERE `siteKey` = 'selftest-pwt-two')");
$db->query("DELETE FROM `tblSites` WHERE `siteKey` = 'selftest-pwt-two'");
$db->query("INSERT INTO `tblSites` (`siteKey`, `siteName`, `timezone`, `isActive`) VALUES ('selftest-pwt-two', 'Selftest PWT Two', 'UTC', 1)");
$siteTwo = (int) $db->insert_id;

/** Remove a person and everything naming them, children first. */
$removeMember = static function (int $who) use ($db): void {
    $rs = $db->query(
        "SELECT `TABLE_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE` WHERE `TABLE_SCHEMA` = DATABASE() "
        . "AND `REFERENCED_TABLE_NAME` = 'tblUsers' AND `COLUMN_NAME` = 'userID'"
    );
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . "` WHERE `userID` = {$who}");
        } catch (Throwable $e) {
            // A table of a different shape; whether the person goes is what matters.
        }
    }
    try {
        $db->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
    } catch (Throwable $e) {
        echo "  ! could not remove test person {$who}; remove it by hand.\n";
    }
};

// ── The people. Each holds ONE ground and nothing else (#616's lesson). ─────
//    orgadmin       a site administrator of organisation 1               → may publish
//    flagadmin      the portal-wide `isAdmin` flag (what Admin → Users sets),
//                   an ACTIVE member of organisation 1                     → may publish
//                   (round 1's first finding: this was refused, falsely)
//    flagelsewhere  the same flag, a member of organisation TWO only       → may NOT
//    flaglapsed     the same flag, organisation 1 membership switched OFF  → may NOT
//    editor         no flag at all, the Website Editor role               → may publish,
//                   through the capability alone (cannot open the form)
//    siteroot       organisation 1's global administrator (isSiteRootAdmin)
//    root           the installation's global administrator
//    member         a plain member (the public submission form)
$people = [];
foreach ([
    'orgadmin'      => ['isAdmin' => 0, 'isRoot' => 0, 'siteAdmin' => 1, 'siteRoot' => 0, 'site' => 'one', 'active' => 1],
    'flagadmin'     => ['isAdmin' => 1, 'isRoot' => 0, 'siteAdmin' => 0, 'siteRoot' => 0, 'site' => 'one', 'active' => 1],
    'flagelsewhere' => ['isAdmin' => 1, 'isRoot' => 0, 'siteAdmin' => 0, 'siteRoot' => 0, 'site' => 'two', 'active' => 1],
    'flaglapsed'    => ['isAdmin' => 1, 'isRoot' => 0, 'siteAdmin' => 0, 'siteRoot' => 0, 'site' => 'one', 'active' => 0],
    // Round 2: the flag on an account that is itself SWITCHED OFF, with an
    // ACTIVE membership here — so the account test, not the membership test,
    // is the one in its way.
    'flaginactive'  => ['isAdmin' => 1, 'isRoot' => 0, 'siteAdmin' => 0, 'siteRoot' => 0, 'site' => 'one', 'active' => 1, 'account' => 0],
    'editor'        => ['isAdmin' => 0, 'isRoot' => 0, 'siteAdmin' => 0, 'siteRoot' => 0, 'site' => 'one', 'active' => 1],
    'siteroot'      => ['isAdmin' => 0, 'isRoot' => 0, 'siteAdmin' => 0, 'siteRoot' => 1, 'site' => 'one', 'active' => 1],
    'root'          => ['isAdmin' => 0, 'isRoot' => 1, 'siteAdmin' => 0, 'siteRoot' => 0, 'site' => 'one', 'active' => 1],
    'member'        => ['isAdmin' => 0, 'isRoot' => 0, 'siteAdmin' => 0, 'siteRoot' => 0, 'site' => 'one', 'active' => 1],
    // #493 P3 (section W): the Website Set-up role holder of organisation 1,
    // and a member of organisation 1 who holds that role only in organisation 2.
    'setup'         => ['isAdmin' => 0, 'isRoot' => 0, 'siteAdmin' => 0, 'siteRoot' => 0, 'site' => 'one', 'active' => 1],
    'setupelsewhere' => ['isAdmin' => 0, 'isRoot' => 0, 'siteAdmin' => 0, 'siteRoot' => 0, 'site' => 'one', 'active' => 1],
] as $role => $flags) {
    $email = "pwt.{$role}@selftest.invalid";
    $old   = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
    $old->bind_param('s', $email);
    $old->execute();
    foreach ($old->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $removeMember((int) $row['userID']);
    }
    $old->close();
    $ins = $db->prepare(
        'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $name    = 'Selftest PWT ' . $role;
    $account = (int) ($flags['account'] ?? 1);
    $ins->bind_param('ssiii', $name, $email, $account, $flags['isAdmin'], $flags['isRoot']);
    $ins->execute();
    $id = (int) $db->insert_id;
    $ins->close();
    $memberOf = $flags['site'] === 'one' ? $siteOne : $siteTwo;
    $db->query("INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES "
        . "({$memberOf}, {$id}, {$flags['active']}, {$flags['siteAdmin']}, {$flags['siteRoot']})");
    $password = 'Pwt' . bin2hex(random_bytes(8)) . '!9x';
    $hash     = password_hash($password, PASSWORD_DEFAULT);
    $login    = 'selftest-pwt-' . $role;
    $db->query("DELETE FROM `tblLocalAccounts` WHERE `username` = '{$login}'");
    $acc = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
    $acc->bind_param('iss', $id, $login, $hash);
    $acc->execute();
    $acc->close();
    $people[$role] = ['id' => $id, 'login' => $login, 'password' => $password];
}
$editorRole = (int) ($db->query("SELECT `roleID` FROM `tblRoles` WHERE `siteID` = {$siteOne} AND `roleKey` = 'website_editor'")->fetch_assoc()['roleID'] ?? 0);
if ($editorRole <= 0) {
    refuse('organisation 1 has no Website Editor role — migration 258 did not seed it');
}
$db->query("INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES ({$people['editor']['id']}, {$editorRole}, {$siteOne})");

// #493 P3 (section W): the Website Set-up role (migration 262), held in
// organisation 1 by `setup`; organisation 2 is made by this test, so it is
// given the role here and `setupelsewhere` (a member of BOTH, so the site gate
// lets them in to organisation 1) holds it THERE only.
$setupRole = (int) ($db->query("SELECT `roleID` FROM `tblRoles` WHERE `siteID` = {$siteOne} AND `roleKey` = 'website_setup'")->fetch_assoc()['roleID'] ?? 0);
if ($setupRole <= 0) {
    refuse('organisation 1 has no Website Set-up role — migration 262 did not seed it');
}
$db->query("INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES ({$people['setup']['id']}, {$setupRole}, {$siteOne})");
$db->query("INSERT INTO `tblRoles` (`siteID`, `roleKey`, `roleName`, `description`, `isStandard`) VALUES ({$siteTwo}, 'website_setup', 'Website Set-up', 'test copy', 1)");
$setupRoleTwo = (int) $db->insert_id;
$db->query("INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES ({$siteTwo}, {$people['setupelsewhere']['id']}, 1, 0, 0)");
$db->query("INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES ({$people['setupelsewhere']['id']}, {$setupRoleTwo}, {$siteTwo})");

// One API key for the events API.
$apiKey    = 'wbms_' . bin2hex(random_bytes(16));
$apiPrefix = substr($apiKey, 0, 12);
$apiHash   = hash('sha256', $apiKey);
$ak = $db->prepare("INSERT INTO `tblApiKeys` (`siteID`, `name`, `keyHash`, `keyPrefix`, `scopes`, `isActive`, `createdByID`) VALUES (?, 'Selftest PWT key', ?, ?, 'events:read,events:write', 1, NULL)");
$ak->bind_param('iss', $siteOne, $apiHash, $apiPrefix);
$ak->execute();
$apiKeyId = (int) $db->insert_id;
$ak->close();

// 🧮 WHO MAY PUBLISH, asked of the REAL `PublicWebsite::mayPublish()` in the
//    COPY of the portal, in a separate PHP process — so a plant made in that
//    copy is what answers, and nothing here keeps a second copy of the rule
//    (#647). Some of these people cannot open any page that asks (a Website
//    Editor who is not an administrator; a flag-holder who belongs elsewhere,
//    whom the members-only gate stops first), so the method is asked directly.
file_put_contents($child . $sep . 'ask-may-publish.php', <<<'ASK'
<?php
// Written by tools/public-website-tick-selftest.php for ONE run. Test only.
declare(strict_types=1);
$core = __DIR__ . '/web/_core/';
define('PORTAL_CORE', rtrim($core, '/'));
foreach (['App.php', 'Site.php', 'Logger.php', 'Roles.php', 'InstallationSettings.php', 'Capabilities.php', 'EventVisibility.php', 'PublicWebsite.php'] as $f) {
    require_once $core . $f;
}
$c = require __DIR__ . '/web/_auth_keys/auth_creds.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name'], (int) $c['db_port']);
$mysqli->set_charset('utf8mb4');
$site = (int) $argv[1];
\Portal\Core\Site::init($mysqli, ['site' => ['timezoneKeptAt' => '']], $site);
$out = [];
foreach (array_slice($argv, 2) as $id) {
    $out[$id] = \Portal\Core\PublicWebsite::mayPublish($mysqli, (int) $id, $site);
}
echo json_encode($out);
ASK);
file_put_contents($child . $sep . 'ask-capabilities.php', <<<'ASK'
<?php
// Written by tools/public-website-tick-selftest.php for ONE run. Test only.
declare(strict_types=1);
$core = __DIR__ . '/web/_core/';
define('PORTAL_CORE', rtrim($core, '/'));
foreach (['Roles.php', 'InstallationSettings.php', 'Capabilities.php'] as $f) {
    require_once $core . $f;
}
$out = [];
foreach (\Portal\Core\Capabilities::keys() as $key) {
    $out[$key] = \Portal\Core\Capabilities::fallsOpenForGlobalAdmin($key);
}
echo json_encode($out);
ASK);
$mayPublish = static function (array $roles) use ($child, $sep, $people, $siteOne): array {
    $ids = array_map(static fn (string $r): string => (string) ($r === 'nobody' ? 0 : $people[$r]['id']), $roles);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child . $sep . 'ask-may-publish.php') . ' ' . $siteOne . ' '
        . implode(' ', array_map('escapeshellarg', $ids)) . ' 2>&1';
    $raw = (string) shell_exec($cmd);
    $got = json_decode($raw, true);
    if (is_array($got) === false) {
        return ['could not ask' => $raw];
    }
    $named = [];
    foreach ($roles as $i => $r) {
        $named[$r] = $got[$ids[$i]] ?? null;
    }
    return $named;
};

$signIn = static function (string $role) use ($http, $tokenOn, $people, $jar): void {
    @unlink($jar);
    $page  = $http('GET', '/login');
    $token = $tokenOn($page['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => $people[$role]['login'], 'password' => $people[$role]['password']]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse("signing in as {$role} did not answer a redirect (HTTP {$in['status']})");
    }
};

// ── Database helpers ────────────────────────────────────────────────────────
/** One event's website columns, and its name, or null. */
$ev = static function (int $id) use ($db): ?array {
    $r = $db->query("SELECT `eventName`, `showOnWebsite`, `websiteFrom`, `websiteUntil`, `isPublic`, `status`, `updatedAt` FROM `tblEvents` WHERE `eventID` = {$id}")->fetch_assoc();
    return $r === null ? null : $r;
};
$evByName = static function (string $name) use ($db): ?array {
    $stmt = $db->prepare('SELECT `eventID`, `showOnWebsite`, `websiteFrom`, `websiteUntil` FROM `tblEvents` WHERE `eventName` = ? ORDER BY `eventID`');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows === [] ? null : $rows;
};
/** Rows of one activity type, for one person, whose words mention $needle. */
$logged = static function (string $type, int $userId, string $needle) use ($db): int {
    $like = '%' . $needle . '%';
    $stmt = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` = ? AND `userID` = ? AND `activityDescription` LIKE ?');
    $stmt->bind_param('sis', $type, $userId, $like);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $n;
};
/** A plain own event, straight into the database. */
$makeEvent = static function (string $name, int $site, string $start, array $set = []) use ($db): int {
    $slug = 'pwt-' . bin2hex(random_bytes(5));
    $zone = 'UTC';
    $stmt = $db->prepare("INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, `isPublic`) VALUES (?, ?, ?, ?, ?, 'published', 1)");
    $stmt->bind_param('issss', $site, $name, $slug, $start, $zone);
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
/** The installation's copied-in rule, and organisation 1's own row (null = none). */
$setRule = static function (string $installation, ?string $own) use ($db, $siteOne): void {
    $u = $db->prepare("UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = 'public.events.copiedRule' AND `siteID` IS NULL");
    $u->bind_param('s', $installation);
    $u->execute();
    $u->close();
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'public.events.copiedRule' AND `siteID` = {$siteOne}");
    if ($own !== null) {
        $i = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, 'public.events.copiedRule', ?, 'event', 0)");
        $i->bind_param('is', $siteOne, $own);
        $i->execute();
        $i->close();
    }
};
$ruleRow = static function (?int $site) use ($db): ?string {
    $where = $site === null ? '`siteID` IS NULL' : '`siteID` = ' . (int) $site;
    $r = $db->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'public.events.copiedRule' AND {$where}")->fetch_assoc();
    return $r === null ? null : (string) $r['settingValue'];
};
$ruleWas = $ruleRow(null);

// Anything an earlier run left behind.
$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'PWT %'");
$db->query("DELETE FROM `tblExternalEventApprovals` WHERE `snapTitle` LIKE 'PWT approval %'");
$db->query("DELETE FROM `tblExternalFeedRules` WHERE `name` = 'PWT rule'");
$db->query("DELETE FROM `tblExternalFeeds` WHERE `name` = 'PWT outside calendar'");
$db->query("DELETE FROM `tblExternalFeeds` WHERE `name` LIKE 'PWT K %'");

$startServer();
$waitForServer();

/**
 * The FIRST event on organisation 1's public door feed (`/w/{name}/events.json`,
 * #493 P4) — its event number, or 0 when the feed lists nothing. Asked as
 * nobody, exactly as another website asks.
 *
 * #493 P4b: until then this read the old countdown widget's feed,
 * `/widget/countdown.json`, which P4b deleted with the widget (D-084, migration
 * 264); the door's events feed asks the same rule (`EventVisibility::where()`
 * in "website" mode with `PublicWebsite::copiedRuleFor()`, on the
 * organisation's own date), so every check below keeps its meaning. The
 * variable keeps its old name so the checks read as they did.
 *
 * THE DOOR IS OPENED FOR THE ONE REQUEST AND CLOSED AGAIN: a name row for
 * organisation 1 and the portal's own address (the /w/ form answers nothing
 * while a visitor could choose the host, PortalAddress), both put back
 * exactly as they were — so section W, which tests the public address and
 * `site.url` itself, never meets a door this helper left open.
 *
 * A FEED THAT DID NOT ANSWER is NOT "no event": every "not shown" check below
 * would pass on a broken door. So each read that is not a 200 JSON answer is
 * COUNTED, and check Z1 at the end requires the count to be nothing.
 */
$doorReadsFailed = 0;
$doorReads       = 0;
$countdownNext = static function () use ($http, $db, $port, &$doorReadsFailed, &$doorReads): int {
    $wasUrl = $db->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'site.url' AND `siteID` IS NULL")->fetch_assoc();
    $wasDoor = $db->query('SELECT `publicName`, `changedAtUtc` FROM `tblPublicDoorNames` WHERE `siteID` = 1')->fetch_assoc();
    $name = bin2hex(random_bytes(12));
    $db->query('DELETE FROM `tblPublicDoorNames` WHERE `siteID` = 1');
    $db->query("INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES (1, '{$name}', UTC_TIMESTAMP())");
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'site.url', 'http://127.0.0.1:{$port}', '', 0) "
        . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)');
    try {
        $r = $http('GET', '/w/' . $name . '/events.json?limit=1', [], [], false);
    } finally {
        $db->query('DELETE FROM `tblPublicDoorNames` WHERE `siteID` = 1');
        if ($wasDoor !== null) {
            $put = $db->prepare('INSERT INTO `tblPublicDoorNames` (`siteID`, `publicName`, `changedAtUtc`) VALUES (1, ?, ?)');
            $put->bind_param('ss', $wasDoor['publicName'], $wasDoor['changedAtUtc']);
            $put->execute();
            $put->close();
        }
        if ($wasUrl === null) {
            $db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'site.url' AND `siteID` IS NULL");
        } else {
            $put = $db->prepare("UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = 'site.url' AND `siteID` IS NULL");
            $put->bind_param('s', $wasUrl['settingValue']);
            $put->execute();
            $put->close();
        }
    }
    $doorReads++;
    $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || is_array($j) === false || ($j['format'] ?? null) !== 'webms-public-events' || is_array($j['events'] ?? null) === false) {
        $doorReadsFailed++;
        return 0;
    }
    $slug = (string) ($j['events'][0]['id'] ?? '');
    if ($slug === '') {
        return 0;
    }
    $find = $db->prepare('SELECT `eventID` FROM `tblEvents` WHERE `siteID` = 1 AND `eventSlug` = ? LIMIT 1');
    $find->bind_param('s', $slug);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();
    return (int) ($row['eventID'] ?? 0);
};
$sitemapHas = static function (string $slug) use ($http): bool {
    $r = $http('GET', '/sitemap.xml', [], [], false);
    return $r['status'] === 200 && str_contains($r['body'], '/e/' . rawurlencode($slug) . '<');
};

// =============================================================================
echo "C. The public door's events feed (the countdown's feed until #493 P4b) — the live exposure this package closes\n";
// =============================================================================
// Tomorrow at 10:00 on organisation 1's clock is the soonest thing this test
// makes, and nothing else in the database is ticked, so whatever the feed
// names is this event or nothing.
$cStart = $tomorrow . ' 10:00:00';
$cEvent = $makeEvent('PWT countdown', $siteOne, $cStart);
$db->query("UPDATE `tblEvents` SET `timezone` = '" . $db->real_escape_string($clockOne->getName()) . "' WHERE `eventID` = {$cEvent}");
$cSlug = (string) $db->query("SELECT `eventSlug` FROM `tblEvents` WHERE `eventID` = {$cEvent}")->fetch_assoc()['eventSlug'];

$scnUnticked = static function () use ($setEv, $cEvent, $countdownNext): bool {
    $setEv($cEvent, ['showOnWebsite' => '0', 'websiteFrom' => null, 'websiteUntil' => null, 'isPublic' => '1', 'status' => 'published', 'isDeleted' => '0']);
    return $countdownNext() !== $cEvent;
};
$scnUntilYesterday = static function () use ($setEv, $cEvent, $countdownNext, $yesterday): bool {
    $setEv($cEvent, ['showOnWebsite' => '1', 'websiteFrom' => null, 'websiteUntil' => $yesterday, 'isPublic' => '1', 'status' => 'published', 'isDeleted' => '0']);
    return $countdownNext() !== $cEvent;
};
check('C1 a published, PUBLIC event that nobody ticked is NOT on the door\'s events feed (until #493 it was on the countdown)', $scnUnticked(), true);
$setEv($cEvent, ['showOnWebsite' => '1']);
check('C2 the same event, ticked, IS on the door\'s events feed', $countdownNext(), $cEvent);
$setEv($cEvent, ['websiteFrom' => $tomorrow]);
check('C3 ticked but showing only from tomorrow: not yet', $countdownNext() !== $cEvent, true);
check('C4 ticked but showing only until yesterday: no longer', $scnUntilYesterday(), true);
$setEv($cEvent, ['websiteFrom' => $today, 'websiteUntil' => $today]);
check('C5 ticked, from today until today: shown — both ends are inclusive', $countdownNext(), $cEvent);
$setEv($cEvent, ['websiteFrom' => null, 'websiteUntil' => null, 'isPublic' => '0']);
check('C6 ticked but members-only: not shown — the switch means nothing without "Public"', $countdownNext() !== $cEvent, true);
$setEv($cEvent, ['isPublic' => '1', 'status' => 'draft']);
check('C7 ticked but a draft: not shown', $countdownNext() !== $cEvent, true);
$setEv($cEvent, ['status' => 'published', 'isDeleted' => '1']);
check('C8 ticked but deleted: not shown', $countdownNext() !== $cEvent, true);
$setEv($cEvent, ['isDeleted' => '0', 'showOnWebsite' => '0']);
$other = $makeEvent('PWT other organisation', $siteTwo, $tomorrow . ' 08:00:00', ['showOnWebsite' => '1']);
check('C9 another organisation\'s ticked event never reaches this organisation\'s door feed', $countdownNext() !== $other, true);

// =============================================================================
echo "M. The sitemap\n";
// =============================================================================
// ⚠️ THE SITEMAP ONLY EXISTS WHILE `site.allowIndexing` IS 'true' — otherwise
//    it answers 404 to everybody. The first version of this section did not
//    set it, and M1 ("not listed") passed on a 404: a check that cannot fail.
//    So the switch is turned on for this section (and put back), and M0
//    asserts the premise — the sitemap really answered — before M1 means
//    anything.
$indexWas = $db->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'site.allowIndexing' AND `siteID` IS NULL")->fetch_assoc();
$db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'site.allowIndexing', 'true', 'false', 0) "
    . "ON DUPLICATE KEY UPDATE `settingValue` = 'true'");
$indexOwn = $db->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'site.allowIndexing' AND `siteID` = {$siteOne}")->fetch_assoc();
$db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'site.allowIndexing' AND `siteID` = {$siteOne}");
check('M0 the premise: /sitemap.xml answers 200 for this section', $http('GET', '/sitemap.xml', [], [], false)['status'], 200);
$setEv($cEvent, ['showOnWebsite' => '0']);
check('M1 an unticked public event is not listed in /sitemap.xml', $sitemapHas($cSlug), false);
$setEv($cEvent, ['showOnWebsite' => '1']);
check('M2 ticked, it is listed', $sitemapHas($cSlug), true);
$setEv($cEvent, ['showOnWebsite' => '0']);
// The indexing switch stays on until the tidy-up: sections D and R read the sitemap too.

// =============================================================================
echo "D. Whose \"today\" — the website's dates follow the ORGANISATION's calendar\n";
// =============================================================================
// ⚠️ WHY THIS SECTION EXISTS (round 3 of the check, 8 November 2026). All
//    three public readers — the door's events feed (the countdown until P4b), the sitemap and, since D-089, the
//    event page's search-engine markup — take "today" from `date('Y-m-d')`, which is the
//    organisation's date only because start-up sets PHP's zone to
//    `Site::timezone()`. Every check above judges the dates against
//    organisation 1's "today" — and organisation 1 is seeded as UTC, the zone
//    the runners use, so here the organisation's today and UTC's were always
//    the same day and nothing above could tell a reader using the
//    organisation's calendar from one using UTC's (#549's trap: a fixture that
//    cannot tell two zones apart proves nothing about which one the code
//    uses). So this section moves organisation 1, for a few seconds, to a zone
//    whose date is NOT UTC's right now, ASSERTS that premise first (D0), and
//    dates the event to each of the two days in turn. P23, P24 and P38 plant
//    `gmdate()` in each reader and require this to go red.
//
//    WHICH ZONE: Pacific/Kiritimati is UTC+14 and Pacific/Pago_Pago is UTC-11,
//    neither with clock changes. From 10:00 UTC Kiritimati is already on
//    tomorrow; before 11:00 UTC Pago Pago is still on yesterday. So at the
//    moment of choosing one of the two ALWAYS differs, which means a premise
//    checked only then can never fail. The hazard is the clock moving DURING
//    the requests, and one boundary matters: UTC midnight after Kiritimati was
//    chosen, when UTC's date catches up with it and a reader taking "today"
//    from UTC would pass D1-D4. So $inZoneApart checks the premise again AFTER
//    the requests, on the same zone, and D0 fails when either day moved.
//    (Round 4 of the check: this paragraph claimed a crossing "fails the
//    premise honestly" before the code did anything of the kind.)
$zonesApart = static function (): array {
    $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $zone   = new DateTimeZone((int) $utcNow->format('G') >= 10 ? 'Pacific/Kiritimati' : 'Pacific/Pago_Pago');
    return [
        'zone'     => $zone->getName(),
        'orgToday' => $utcNow->setTimezone($zone)->format('Y-m-d'),
        'utcToday' => $utcNow->format('Y-m-d'),
    ];
};
/**
 * Run $probe with organisation 1 on a zone whose date is not UTC's, and ALWAYS
 * put organisation 1's zone back. Answers a sentence instead of the probe's
 * result when the premise does not hold before the requests, or no longer
 * holds after them, so every check using it fails loudly.
 */
$inZoneApart = static function (callable $probe) use ($db, $siteOne, $zoneOne, $zonesApart): mixed {
    $z = $zonesApart();
    if ($z['orgToday'] === $z['utcToday']) {
        return 'the premise failed: ' . $z['zone'] . ' and UTC are on the same day (' . $z['utcToday'] . ')';
    }
    $db->query("UPDATE `tblSites` SET `timezone` = '" . $db->real_escape_string($z['zone']) . "' WHERE `siteID` = {$siteOne}");
    try {
        $result = $probe($z);
    } finally {
        $db->query("UPDATE `tblSites` SET `timezone` = '" . $db->real_escape_string($zoneOne) . "' WHERE `siteID` = {$siteOne}");
    }
    // The premise again, on the SAME zone: did either day move while the
    // requests ran? This is the check that can fail (see "WHICH ZONE").
    $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($after->format('Y-m-d') !== $z['utcToday'] || $after->setTimezone(new DateTimeZone($z['zone']))->format('Y-m-d') !== $z['orgToday']) {
        return 'the premise failed: a day changed while the requests ran (UTC ' . $z['utcToday'] . ', then ' . $after->format('Y-m-d')
            . '); run the test again';
    }
    return $result;
};
/**
 * The ticked, public, published event dated to ONE day only — first the
 * organisation's today, then UTC's today — read on the door's events feed, in the
 * sitemap and in the event page's search-engine markup each time. Puts the event back off the website afterwards.
 */
$scnWhoseToday = static function () use ($inZoneApart, $setEv, $cEvent, $countdownNext, $sitemapHas, $cSlug, $http): mixed {
    // D-089: the event's own page is the third reader — its search-engine
    // markup follows the same website dates, on the same calendar.
    $markup = static function () use ($http, $cSlug): bool {
        return str_contains($http('GET', '/calendar/event?slug=' . rawurlencode($cSlug), [], [], false)['body'], 'application/ld+json');
    };
    return $inZoneApart(static function (array $z) use ($setEv, $cEvent, $countdownNext, $sitemapHas, $cSlug, $markup): array {
        $setEv($cEvent, ['showOnWebsite' => '1', 'websiteFrom' => $z['orgToday'], 'websiteUntil' => $z['orgToday'],
            'isPublic' => '1', 'status' => 'published', 'isDeleted' => '0']);
        $org = ['countdown' => $countdownNext() === $cEvent, 'sitemap' => $sitemapHas($cSlug), 'page' => $markup()];
        $setEv($cEvent, ['websiteFrom' => $z['utcToday'], 'websiteUntil' => $z['utcToday']]);
        $utc = ['countdown' => $countdownNext() === $cEvent, 'sitemap' => $sitemapHas($cSlug), 'page' => $markup()];
        $setEv($cEvent, ['showOnWebsite' => '0', 'websiteFrom' => null, 'websiteUntil' => null]);
        return ['org' => $org, 'utc' => $utc];
    });
};
$dr = $scnWhoseToday();
check('D0 the premise held from before the first request to after the last: the organisation\'s day and UTC\'s were different, and neither moved',
    is_array($dr) === true ? true : $dr, true);
check('D1 dated to the ORGANISATION\'s today only: on the door\'s events feed', is_array($dr) === true ? $dr['org']['countdown'] : $dr, true);
check('D2 dated to the ORGANISATION\'s today only: in the sitemap', is_array($dr) === true ? $dr['org']['sitemap'] : $dr, true);
check('D3 dated to UTC\'s today only, which is not the organisation\'s: NOT on the door\'s events feed', is_array($dr) === true ? $dr['utc']['countdown'] : $dr, false);
check('D4 dated to UTC\'s today only, which is not the organisation\'s: NOT in the sitemap', is_array($dr) === true ? $dr['utc']['sitemap'] : $dr, false);
check('D5 dated to the ORGANISATION\'s today only: the event\'s page carries search-engine markup (D-089)', is_array($dr) === true ? $dr['org']['page'] : $dr, true);
check('D6 dated to UTC\'s today only, which is not the organisation\'s: the event\'s page carries NO search-engine markup (D-089)', is_array($dr) === true ? $dr['utc']['page'] : $dr, false);

// =============================================================================
echo "F. The Manage Events form\n";
// =============================================================================
$formPost = static function (string $role, array $fields) use ($signIn, $http, $tokenOn): array {
    $signIn($role);
    $token = $tokenOn($http('GET', '/calendar/manage')['body']);
    return $http('POST', '/calendar/manage/save', ['csrf_token' => $token] + $fields);
};
$base = [
    'status' => 'published', 'isPublic' => '1', 'startDateTime' => '2030-06-01T10:00', 'endDateTime' => '2030-06-01T11:00',
    'timezone' => 'UTC',
];

$signIn('orgadmin');
$page = $http('GET', '/calendar/manage')['body'];
check('F1 an administrator of the organisation is drawn the switch, the dates and the marker',
    [str_contains($page, 'name="showOnWebsite"'), str_contains($page, 'name="websiteFrom"'), str_contains($page, 'name="websiteFields"')],
    [true, true, true]);

$r = $formPost('orgadmin', $base + ['action' => 'create', 'eventName' => 'PWT form ticked', 'websiteFields' => '1', 'showOnWebsite' => '1',
    'websiteFrom' => '2030-05-01', 'websiteUntil' => '2030-06-01']);
$fRow = $evByName('PWT form ticked');
$fId  = (int) ($fRow[0]['eventID'] ?? 0);
check('F2 ticked with dates on the form, it is stored ON with those dates',
    [$r['status'], (int) ($fRow[0]['showOnWebsite'] ?? -1), $fRow[0]['websiteFrom'] ?? null, $fRow[0]['websiteUntil'] ?? null],
    [302, 1, '2030-05-01', '2030-06-01']);
check('F2b and the tick is in the activity log, naming the administrator', $logged('EventShownOnWebsite', $people['orgadmin']['id'], '#' . $fId . ' '), 1);

$formPost('orgadmin', $base + ['action' => 'create', 'eventName' => 'PWT form unticked', 'websiteFields' => '1']);
$uRow = $evByName('PWT form unticked');
// `array_key_exists`, never `?? 'x'`: a stored NULL is exactly the answer
// wanted here, and `??` would turn it into the stand-in (it did, in the first
// version of this check).
$col = static fn (?array $row, string $key): mixed => (is_array($row) === true && array_key_exists($key, $row) === true) ? $row[$key] : 'missing';
check('F3 a new event left unticked is stored OFF, with no dates', [(int) ($uRow[0]['showOnWebsite'] ?? -1), $col($uRow[0] ?? null, 'websiteFrom')], [0, null]);

$signIn('flagadmin');
$editPage = $http('GET', '/calendar/manage?edit=' . $fId)['body'];
check('F4 an administrator made on Admin → Users (the portal-wide flag, a member here) IS drawn the switch, the dates and the marker',
    [str_contains($editPage, 'name="showOnWebsite"'), str_contains($editPage, 'name="websiteFields"')], [true, true]);

$scnUntouched = static function () use ($formPost, $base, $fId, $ev): bool {
    $formPost('flagadmin', $base + ['action' => 'update', 'eventID' => (string) $fId, 'eventName' => 'PWT form ticked renamed']);
    $row = $ev($fId);
    $ok  = $row !== null && $row['eventName'] === 'PWT form ticked renamed' && (int) $row['showOnWebsite'] === 1
        && $row['websiteFrom'] === '2030-05-01' && $row['websiteUntil'] === '2030-06-01';
    // Put the name back for the next run of this scenario.
    $GLOBALS['db']->query("UPDATE `tblEvents` SET `eventName` = 'PWT form ticked' WHERE `eventID` = {$fId}");
    return $ok;
};
check('F5 a Save that sends no website fields at all changes the event and leaves the switch and dates exactly as they were (#677)', $scnUntouched(), true);
check('F5b ...and records no refusal', $logged('EventWebsiteChangeRefused', $people['flagadmin']['id'], '#' . $fId . ' '), 0);

// 3(d) of round 1: a publisher's UNTOUCHED Save, posted exactly as the browser
// would post the page it was drawn — the boxes as the page FILLED them. If the
// page drew a stored day as empty, this Save would quietly clear it.
$scnUntouchedForm = static function () use ($signIn, $http, $tokenOn, $base, $fId, $ev): bool {
    $signIn('orgadmin');
    $page  = $http('GET', '/calendar/manage?edit=' . $fId)['body'];
    $token = $tokenOn($page);
    $value = static function (string $id) use ($page): ?string {
        return preg_match('/<input[^>]*id="' . preg_quote($id, '/') . '"[^>]*value="([^"]*)"/s', $page, $m) === 1
            ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    };
    $fields = $base + ['csrf_token' => $token, 'action' => 'update', 'eventID' => (string) $fId, 'eventName' => 'PWT form ticked',
        'websiteFields' => '1', 'websiteFrom' => (string) $value('websiteFrom-edit'), 'websiteUntil' => (string) $value('websiteUntil-edit')];
    if (preg_match('/<input[^>]*name="showOnWebsite"[^>]*id="showOnWebsite-edit"[^>]*>/s', $page, $box) === 1 && str_contains($box[0], ' checked') === true) {
        $fields['showOnWebsite'] = '1';
    }
    $http('POST', '/calendar/manage/save', $fields);
    $row = $ev($fId);
    return $row !== null && (int) $row['showOnWebsite'] === 1 && $row['websiteFrom'] === '2030-05-01' && $row['websiteUntil'] === '2030-06-01';
};
check('F5c a publisher\'s untouched Save, posted exactly as the page drew it, keeps the switch AND both dates', $scnUntouchedForm(), true);

$formPost('flagadmin', $base + ['action' => 'update', 'eventID' => (string) $fId, 'eventName' => 'PWT form ticked', 'websiteFields' => '1',
    'websiteFrom' => '2030-05-01', 'websiteUntil' => '2030-06-01']);
check('F6 that flag-holding administrator CAN take it off the website (round 1: this was refused, falsely)', (int) ($ev($fId)['showOnWebsite'] ?? -1), 0);
check('F6b ...recorded as taken off the website, naming them', $logged('EventHiddenFromWebsite', $people['flagadmin']['id'], '#' . $fId . ' '), 1);

$formPost('flagadmin', $base + ['action' => 'update', 'eventID' => (string) $fId, 'eventName' => 'PWT form ticked', 'websiteFields' => '1',
    'showOnWebsite' => '1', 'websiteFrom' => '2030-05-01', 'websiteUntil' => '2030-06-01']);
check('F7 ...and put it back on, recorded as shown', [(int) ($ev($fId)['showOnWebsite'] ?? -1), $logged('EventShownOnWebsite', $people['flagadmin']['id'], '#' . $fId . ' ')], [1, 1]);

$formPost('orgadmin', $base + ['action' => 'update', 'eventID' => (string) $fId, 'eventName' => 'PWT form ticked', 'websiteFields' => '1',
    'websiteFrom' => '', 'websiteUntil' => '']);
$eRow = $ev($fId);
check('F8 an administrator switches it off and clears both dates',
    [(int) ($eRow['showOnWebsite'] ?? -1), $col($eRow, 'websiteFrom'), $col($eRow, 'websiteUntil')], [0, null, null]);

// Round 4: the box is read by its VALUE. A posted "0" beside the marker —
// what a hidden "0" placed before the box sends — must be OFF; reading
// presence, as round 3 found the handler did, stored it ON. P26 plants that.
$scnZeroIsOff = static function () use ($formPost, $base, $fId, $ev): bool {
    $formPost('orgadmin', $base + ['action' => 'update', 'eventID' => (string) $fId, 'eventName' => 'PWT form ticked', 'websiteFields' => '1',
        'showOnWebsite' => '1']);
    $on = (int) ($ev($fId)['showOnWebsite'] ?? -1) === 1;
    $formPost('orgadmin', $base + ['action' => 'update', 'eventID' => (string) $fId, 'eventName' => 'PWT form ticked', 'websiteFields' => '1',
        'showOnWebsite' => '0']);
    return $on === true && (int) ($ev($fId)['showOnWebsite'] ?? -1) === 0;
};
check('F8b a posted showOnWebsite of "0" beside the marker is OFF, as a hidden "0" before the box would send', $scnZeroIsOff(), true);

// Round 4: the form's REFUSAL, in a world where the permission says no to
// everybody (P25). See the header, section F, for why only a planted world
// can reach it in package P1.
$scnFormRefuses = static function () use ($formPost, $signIn, $http, $base, $fId, $ev, $setEv, $logged, $people): array {
    $setEv($fId, ['showOnWebsite' => '0', 'websiteFrom' => null, 'websiteUntil' => null]);
    $before = $logged('EventWebsiteChangeRefused', $people['orgadmin']['id'], '#' . $fId . ' ');
    $signIn('orgadmin');
    $page = $http('GET', '/calendar/manage?edit=' . $fId)['body'];
    $words = str_contains($page, 'name="showOnWebsite"') === false && str_contains($page, 'Not ticked to show on the public website') === true;
    $formPost('orgadmin', $base + ['action' => 'update', 'eventID' => (string) $fId, 'eventName' => 'PWT form ticked', 'websiteFields' => '1',
        'showOnWebsite' => '1']);
    return [
        'drawnAsWords' => $words,
        'unchanged'    => (int) ($ev($fId)['showOnWebsite'] ?? -1) === 0,
        'recorded'     => $logged('EventWebsiteChangeRefused', $people['orgadmin']['id'], '#' . $fId . ' ') === $before + 1,
    ];
};

// ── K. Who may publish, asked of the real method in the copy ─────────────────
$scnWhoMay = static function () use ($mayPublish): array {
    return $mayPublish(['orgadmin', 'flagadmin', 'flagelsewhere', 'flaglapsed', 'flaginactive', 'editor', 'member', 'root', 'siteroot', 'nobody']);
};
$whoMay = $scnWhoMay();
check('K1 who may publish: a site administrator, the flag-holder who belongs here, the Website Editor (by role alone), the global administrator, '
    . 'the organisation\'s global administrator — and NOT the flag-holder who belongs elsewhere, the one whose membership is off, the one whose ACCOUNT is off, '
    . 'a plain member, or nobody (an API key)',
    $whoMay,
    ['orgadmin' => true, 'flagadmin' => true, 'flagelsewhere' => false, 'flaglapsed' => false, 'flaginactive' => false, 'editor' => true, 'member' => false,
        'root' => true, 'siteroot' => true, 'nobody' => false]);

$formPost('orgadmin', $base + ['action' => 'update', 'eventID' => (string) $fId, 'eventName' => 'PWT form ticked', 'websiteFields' => '1',
    'showOnWebsite' => '1', 'websiteFrom' => '2030-06-02', 'websiteUntil' => '2030-06-01']);
check('F9 a last day before the first is refused, nothing saved', (int) ($ev($fId)['showOnWebsite'] ?? -1), 0);
$formPost('orgadmin', $base + ['action' => 'update', 'eventID' => (string) $fId, 'eventName' => 'PWT form ticked', 'websiteFields' => '1',
    'showOnWebsite' => '1', 'websiteFrom' => '2030-02-31']);
check('F10 an impossible date is refused, nothing saved', (int) ($ev($fId)['showOnWebsite'] ?? -1), 0);

$formPost('orgadmin', $base + ['action' => 'create', 'eventName' => 'PWT form series', 'websiteFields' => '1', 'showOnWebsite' => '1',
    'repeatFrequency' => 'weekly', 'repeatCount' => '3']);
$sRows = $evByName('PWT form series') ?? [];
check('F11 a repeating series made with the box ticked carries it to every date (the person chose it for the series in that request)',
    [count($sRows), count(array_filter($sRows, static fn (array $x): bool => (int) $x['showOnWebsite'] === 1))], [3, 3]);
check('F11b ...and the log says so, naming the other dates', $logged('EventShownOnWebsite', $people['orgadmin']['id'], 'other dates of its series'), 1);

// =============================================================================
echo "A. The events API\n";
// =============================================================================
$api = static function (string $path, array $body, ?string $token = null) use ($http, $apiKey): array {
    $headers = ['Content-Type: application/json'];
    if ($token === null) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        return $http('POST', $path, (string) json_encode($body), $headers, false);
    }
    $headers[] = 'X-CSRF-TOKEN: ' . $token;
    return $http('POST', $path, (string) json_encode($body), $headers);
};
$keyBase = ['startDateTime' => '2030-07-01T10:00:00', 'status' => 'published'];

$scnKeyCreate = static function () use ($api, $keyBase, $evByName): bool {
    $GLOBALS['db']->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT key ticked'");
    $r = $api('/api/events/create', $keyBase + ['eventName' => 'PWT key ticked', 'showOnWebsite' => true]);
    return $r['status'] === 403 && $evByName('PWT key ticked') === null;
};
check('A1 an API key asking to create an event ON the website is refused 403 and nothing is created', $scnKeyCreate(), true);
$r = $api('/api/events/create', $keyBase + ['eventName' => 'PWT key dated', 'websiteFrom' => '2030-06-01']);
check('A2 ...and so is a key setting only a website date', [$r['status'], $evByName('PWT key dated')], [403, null]);
$r = $api('/api/events/create', $keyBase + ['eventName' => 'PWT key plain', 'showOnWebsite' => false]);
$kRow = $evByName('PWT key plain');
check('A3 a key sending the default (off) is not a change: created, and OFF', [$r['status'], (int) ($kRow[0]['showOnWebsite'] ?? -1)], [201, 0]);
$kId = (int) ($kRow[0]['eventID'] ?? 0);

$aId = $makeEvent('PWT api ticked', $siteOne, '2030-07-02 10:00:00', ['showOnWebsite' => '1', 'websiteFrom' => '2030-06-01']);
$r = $api('/api/events/update?id=' . $aId, ['eventID' => $aId, 'showOnWebsite' => false]);
check('A4 a key switching a ticked event off is refused 403, and it stays on', [$r['status'], (int) ($ev($aId)['showOnWebsite'] ?? -1)], [403, 1]);
check('A4b ...and the refusal is recorded', (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` = 'EventWebsiteChangeRefused' AND `activityDescription` LIKE '%#{$aId} %API key%'")->fetch_assoc()['n'] ?? 0) >= 1, true);
$db->query("UPDATE `tblEvents` SET `updatedAt` = '2020-01-01 00:00:00' WHERE `eventID` = {$aId}");
$scnKeySameBack = static function () use ($api, $aId, $ev): bool {
    $r = $api('/api/events/update?id=' . $aId, ['eventID' => $aId, 'showOnWebsite' => true, 'websiteFrom' => '2030-06-01', 'websiteUntil' => null]);
    $row = $ev($aId);
    return $r['status'] === 200 && (int) $row['showOnWebsite'] === 1 && $row['updatedAt'] === '2020-01-01 00:00:00';
};
check('A5 a key sending the STORED values back is answered 200 and changes nothing, not even the last-changed time', $scnKeySameBack(), true);
$r = $api('/api/events/update?id=' . $aId, ['eventID' => $aId, 'websiteUntil' => 'next week']);
// A value that is not a date is judged BEFORE the permission, so even a key
// gets the 422 that names the real problem rather than a 403.
check('A6 an unreadable date is 422, even from a key', $r['status'], 422);

$signIn('orgadmin');
$sessToken = $tokenOn($http('GET', '/calendar/manage')['body']);
$r = $api('/api/events/update?id=' . $aId, ['eventID' => $aId, 'showOnWebsite' => false], $sessToken);
check('A7 a signed-in administrator CAN switch it off through the API', [$r['status'], (int) ($ev($aId)['showOnWebsite'] ?? -1)], [200, 0]);
check('A7b ...recorded as taken off the website, naming them', $logged('EventHiddenFromWebsite', $people['orgadmin']['id'], '#' . $aId . ' '), 1);
$meta = json_decode($r['body'], true)['meta']['csrfToken'] ?? $sessToken;
$r = $api('/api/events/update?id=' . $aId, ['eventID' => $aId, 'websiteUntil' => '2030-13-40'], (string) $meta);
check('A8 a session caller sending an impossible date gets 422', $r['status'], 422);

// =============================================================================
echo "B. The public submission form\n";
// =============================================================================
$scnSubmission = static function () use ($signIn, $http, $tokenOn, $evByName): bool {
    $GLOBALS['db']->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT submitted'");
    $signIn('member');
    $token = $tokenOn($http('GET', '/calendar/submit')['body']);
    $http('POST', '/calendar/submit-save', [
        'csrf_token' => $token, 'eventName' => 'PWT submitted', 'startDateTime' => '2030-08-01T10:00',
        'showOnWebsite' => '1', 'websiteFields' => '1', 'websiteFrom' => '2030-01-01', 'websiteUntil' => '2030-12-31',
    ]);
    $row = $evByName('PWT submitted');
    return $row !== null && (int) $row[0]['showOnWebsite'] === 0 && $row[0]['websiteFrom'] === null && $row[0]['websiteUntil'] === null;
};
check('B1 a submission posting the website fields is stored OFF with no dates, whatever it sent', $scnSubmission(), true);

// =============================================================================
echo "R. How copied-in events reach the website (D-080, D-081)\n";
// =============================================================================
$setRule('event', null);
$rulePost = static function (string $role, string $action, string $rule) use ($signIn, $http, $tokenOn): array {
    $signIn($role);
    $token = $tokenOn($http('GET', '/admin/calendar/feeds')['body']);
    return $http('POST', '/admin/calendar/feeds/website-rule', ['csrf_token' => $token, 'action' => $action, 'rule' => $rule]);
};
$signIn('orgadmin');
$feedsPage = $http('GET', '/admin/calendar/feeds')['body'];
check('R1 an organisation\'s ordinary administrator sees the rule in force and is given no form for it',
    [str_contains($feedsPage, 'data-rule-in-force="event"'), str_contains($feedsPage, 'name="rule"')], [true, false]);
$r = $rulePost('orgadmin', 'organisation', 'calendar');
check('R1b ...and a post from them is refused: nothing stored', $ruleRow($siteOne), null);
// The STRICTER rule as well: the save-time cap would refuse the looser one
// anyway, so only this shows the page's own gate is what refused it.
$scnStricterByAdmin = static function () use ($setRule, $rulePost, $ruleRow, $siteOne): bool {
    $setRule('calendar', null);
    $rulePost('orgadmin', 'organisation', 'event');
    $kept = $ruleRow($siteOne) === null;
    $setRule('event', null);
    return $kept;
};
check('R1c ...even for the STRICTER rule, which the cap would allow: nothing stored', $scnStricterByAdmin(), true);

$scnLooserRefused = static function () use ($setRule, $rulePost, $ruleRow, $siteOne): bool {
    $setRule('event', null);
    $rulePost('siteroot', 'organisation', 'calendar');
    return $ruleRow($siteOne) === null;
};
check('R2 the organisation\'s global administrator choosing the LOOSER rule while the installation chose "one tick per event" is REFUSED', $scnLooserRefused(), true);
check('R2b ...and the refusal is recorded, naming them', $logged('WebsiteCopiedRuleRefused', $people['siteroot']['id'], 'never a looser one') >= 1, true);
$rulePost('siteroot', 'installation', 'calendar');
check('R3 they cannot change the INSTALLATION\'s rule', $ruleRow(null), 'event');
$rulePost('root', 'installation', 'calendar');
check('R4 the installation\'s global administrator can', $ruleRow(null), 'calendar');
$rulePost('siteroot', 'organisation', 'event');
check('R5 with the installation loose, the organisation may hold itself to "one tick per event"', $ruleRow($siteOne), 'event');
$rulePost('siteroot', 'organisation', 'inherit');
check('R6 "follow the installation" deletes the organisation\'s own row', $ruleRow($siteOne), null);

// A copied-in event, public with the website box ticked by its CALENDAR, the
// soonest thing on the door's events feed when the rule lets it through.
$db->query("INSERT INTO `tblExternalFeeds` (`siteID`, `name`, `url`, `audienceLevel`, `websiteOptIn`, `isActive`) "
    . "VALUES ({$siteOne}, 'PWT outside calendar', 'https://calendar.selftest.invalid/pwt.ics', 'public', 1, 1)");
$feedId = (int) $db->insert_id;
$impId  = $makeEvent('PWT copied in', $siteOne, $tomorrow . ' 09:00:00');
$db->query("UPDATE `tblEvents` SET `timezone` = '" . $db->real_escape_string($clockOne->getName()) . "', `externalFeedID` = {$feedId}, "
    . "`importLevel` = 'public', `importDetail` = 'full', `importWebsite` = 1, `importSource` = 'calendar', `importRecheckAt` = NULL, "
    . "`isPublic` = 0 WHERE `eventID` = {$impId}");
$setEv($cEvent, ['showOnWebsite' => '1', 'websiteFrom' => null, 'websiteUntil' => null]);
$scnCalendarRule = static function () use ($setRule, $countdownNext, $impId): bool {
    $setRule('calendar', null);
    return $countdownNext() === $impId;
};
$scnEventRule = static function () use ($setRule, $countdownNext, $cEvent): bool {
    $setRule('event', null);
    return $countdownNext() === $cEvent;
};
$scnReaderCaps = static function () use ($setRule, $countdownNext, $cEvent): bool {
    // An organisation row saying "calendar" under an installation saying
    // "event" can only be written by SQL or a restore — the page refuses it —
    // and the READER must still hold the organisation down.
    $setRule('event', 'calendar');
    return $countdownNext() === $cEvent;
};
check('R7 under "one box per calendar" the calendar-ticked copied-in event is on the door\'s events feed', $scnCalendarRule(), true);
check('R8 under "one tick per event" it is not; the organisation\'s own ticked event is', $scnEventRule(), true);
check('R9 an organisation row looser than the installation is held down when READ too', $scnReaderCaps(), true);
$db->query("UPDATE `tblEvents` SET `importSource` = 'date' WHERE `eventID` = {$impId}");
$setRule('event', null);
check('R10 under "one tick per event", a copied-in event whose "yes" came from a choice about that date IS on the door\'s events feed', $countdownNext(), $impId);
$db->query("UPDATE `tblEvents` SET `importSource` = 'calendar' WHERE `eventID` = {$impId}");

// Round 9 of the check: a NEW choice's website question is answered by nobody.
// Rounds 4 to 9 each found an inferred starting tick wrong in a new direction,
// so the form now asks Yes / No with neither chosen, under either rule. The
// page needs an outside-calendar identity to draw a choice at all.
$db->query("UPDATE `tblEvents` SET `externalUidHash` = UNHEX(SHA2('pwt-copied-in@selftest', 256)) WHERE `eventID` = {$impId}");
$questionIn = static function (int $status, string $body): ?string {
    if ($status !== 200 || preg_match('/<fieldset[^>]*id="choice-website"[^>]*>(.*?)<\/fieldset>/s', $body, $m) !== 1) {
        return null; // the page did not ask the question at all: the check fails, never passes
    }
    $yes = preg_match('/<input[^>]*id="choice-website-yes"[^>]*\bchecked\b/s', $m[1]) === 1;
    $no  = preg_match('/<input[^>]*id="choice-website-no"[^>]*\bchecked\b/s', $m[1]) === 1;
    if (str_contains($m[1], 'id="choice-website-yes"') === false || str_contains($m[1], 'id="choice-website-no"') === false) {
        return 'missing an answer';
    }
    return $yes ? 'yes' : ($no ? 'no' : 'unanswered');
};
$choiceQuestion = static function () use ($signIn, $http, $impId, $questionIn): ?string {
    $signIn('orgadmin');
    $r = $http('GET', '/admin/calendar/feeds/choice?event=' . $impId . '&scope=date');
    return $questionIn($r['status'], $r['body']);
};
$scnChoiceBox = static function () use ($setRule, $choiceQuestion): array {
    $setRule('event', null);
    $strict = $choiceQuestion();
    $setRule('calendar', null);
    $loose = $choiceQuestion();
    $setRule('event', null);
    return ['event' => $strict, 'calendar' => $loose];
};
check('R16 a NEW choice asks "show these dates on the public website?" as Yes / No with neither chosen, under either rule',
    $scnChoiceBox(), ['event' => 'unanswered', 'calendar' => 'unanswered']);

// Round 6 of the check: saving the rule through its own form re-works every
// outside calendar it governs AT ONCE. Left to each calendar's next refresh,
// one whose outside file had not changed kept its old answer for about a day.
// The copied-in event is given a stored answer no resolve would write
// ('waiting', website 0); a re-work replaces it from the calendar's own box.
$scnRuleSaveReworks = static function () use ($db, $setRule, $rulePost, $impId): array {
    $setRule('event', null);
    $db->query("UPDATE `tblEvents` SET `importSource` = 'waiting', `importWebsite` = 0 WHERE `eventID` = {$impId}");
    $rulePost('root', 'installation', 'calendar');
    $row = $db->query("SELECT `importSource`, `importWebsite` FROM `tblEvents` WHERE `eventID` = {$impId}")->fetch_assoc();
    $setRule('event', null);
    $db->query("UPDATE `tblEvents` SET `importSource` = 'calendar', `importWebsite` = 1 WHERE `eventID` = {$impId}");
    return ['source' => $row['importSource'] ?? null, 'website' => (int) ($row['importWebsite'] ?? -1)];
};
check('R17 saving the rule re-works the calendars it governs at once: a copied-in event left in a state no resolve writes is re-worked from its calendar\'s box',
    $scnRuleSaveReworks(), ['source' => 'calendar', 'website' => 1]);
// Round 7: a save that changes NOTHING re-works too — that is what makes the
// page's "save the rule again to retry them" true.
$scnSameSaveReworks = static function () use ($db, $setRule, $rulePost, $impId): array {
    $setRule('calendar', null);
    $db->query("UPDATE `tblEvents` SET `importSource` = 'waiting', `importWebsite` = 0 WHERE `eventID` = {$impId}");
    $rulePost('root', 'installation', 'calendar');
    $row = $db->query("SELECT `importSource`, `importWebsite` FROM `tblEvents` WHERE `eventID` = {$impId}")->fetch_assoc();
    $setRule('event', null);
    $db->query("UPDATE `tblEvents` SET `importSource` = 'calendar', `importWebsite` = 1 WHERE `eventID` = {$impId}");
    return ['source' => $row['importSource'] ?? null, 'website' => (int) ($row['importWebsite'] ?? -1)];
};
check('R17b saving the SAME rule again re-works too, so "save the rule again to retry them" can work',
    $scnSameSaveReworks(), ['source' => 'calendar', 'website' => 1]);
// Round 9: an Everyone choice that does not answer the website question is
// REFUSED and saves nothing; answering it (No here) saves. Saving re-works this
// calendar, so the event's stored answer is put back for the checks after.
$scnNewChoiceAnswer = static function () use ($db, $signIn, $http, $tokenOn, $impId, $feedId): array {
    $url   = '/admin/calendar/feeds/choice?event=' . $impId . '&scope=date';
    $count = static fn (): int => (int) $db->query("SELECT COUNT(*) AS `n` FROM `tblExternalEventChoices` WHERE `feedID` = {$feedId}")->fetch_assoc()['n'];
    $before = $count();
    $signIn('orgadmin');
    $page = $http('GET', $url)['body'];
    $http('POST', $url, ['csrf_token' => $tokenOn($page), 'action' => 'save', 'audienceLevel' => 'public', 'detailLevel' => 'basic']);
    $unanswered = $count() - $before;
    $page = $http('GET', $url)['body'];
    $http('POST', $url, ['csrf_token' => $tokenOn($page), 'action' => 'save', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'websiteOptIn' => '0']);
    $answered = $count() - $before;
    $db->query("DELETE FROM `tblExternalEventChoices` WHERE `feedID` = {$feedId}");
    $db->query("UPDATE `tblEvents` SET `importLevel` = 'public', `importDetail` = 'full', `importWebsite` = 1, `importSource` = 'calendar', "
        . "`importSourceID` = NULL, `importRecheckAt` = NULL WHERE `eventID` = {$impId}");
    return ['saved without an answer' => $unanswered, 'saved with "No"' => $answered];
};
check('R16b an Everyone choice that does not answer the website question saves nothing; answering it saves',
    $scnNewChoiceAnswer(), ['saved without an answer' => 0, 'saved with "No"' => 1]);
// Round 10: the form redrawn after ANY OTHER refusal kept the question
// unanswered only if it was drawn from what was really sent. It used to be
// drawn from the tidied post, where nothing sent had become "No" — so "No"
// was shown chosen, and switching to Everyone and saving stored a "No" nobody
// gave. Here: "Selected groups and people" with nobody ticked (the script
// disables the website answers there, so none is sent) is refused, and the
// redrawn question must still be unanswered.
$scnRedrawUnanswered = static function () use ($db, $signIn, $http, $tokenOn, $impId, $feedId, $questionIn): array {
    $url   = '/admin/calendar/feeds/choice?event=' . $impId . '&scope=date';
    $count = static fn (): int => (int) $db->query("SELECT COUNT(*) AS `n` FROM `tblExternalEventChoices` WHERE `feedID` = {$feedId}")->fetch_assoc()['n'];
    $before = $count();
    $signIn('orgadmin');
    $page = $http('GET', $url)['body'];
    $r = $http('POST', $url, ['csrf_token' => $tokenOn($page), 'action' => 'save', 'drawnAsNew' => '1', 'audienceLevel' => 'groups', 'detailLevel' => 'basic']);
    return ['refused' => $r['status'] === 200 && str_contains($r['body'], 'Choose at least one group or person.'),
            'saved' => $count() - $before, 'question' => $questionIn($r['status'], $r['body'])];
};
check('R16c after another refusal (no group ticked) the redrawn form still asks the website question with neither answer chosen',
    $scnRedrawUnanswered(), ['refused' => true, 'saved' => 0, 'question' => 'unanswered']);
// Round 10: another administrator saves a choice (with "Yes") while this page
// is open as a NEW choice. An unanswered Everyone save from the stale page
// used to find their choice, skip the refusal and write "No" over it. The
// form says it was drawn as new, so the save is refused, theirs is kept, and
// the page says what happened.
$scnRaceKeepsTheirs = static function () use ($db, $signIn, $http, $tokenOn, $impId, $feedId): array {
    $url = '/admin/calendar/feeds/choice?event=' . $impId . '&scope=date';
    $signIn('orgadmin');
    $page  = $http('GET', $url)['body'];
    $drawn = str_contains($page, 'name="drawnAsNew" value="1"');
    $http('POST', $url, ['csrf_token' => $tokenOn($page), 'action' => 'save', 'drawnAsNew' => '1', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'websiteOptIn' => '1']);
    $fresh = $http('GET', $url)['body'];
    $r = $http('POST', $url, ['csrf_token' => $tokenOn($fresh), 'action' => 'save', 'drawnAsNew' => '1', 'audienceLevel' => 'public', 'detailLevel' => 'basic']);
    $row = $db->query("SELECT `websiteOptIn` FROM `tblExternalEventChoices` WHERE `feedID` = {$feedId}")->fetch_assoc();
    $db->query("DELETE FROM `tblExternalEventChoices` WHERE `feedID` = {$feedId}");
    $db->query("UPDATE `tblEvents` SET `importLevel` = 'public', `importDetail` = 'full', `importWebsite` = 1, `importSource` = 'calendar', "
        . "`importSourceID` = NULL, `importRecheckAt` = NULL WHERE `eventID` = {$impId}");
    return ['drawn as new' => $drawn, 'their answer kept' => (int) ($row['websiteOptIn'] ?? -1),
            'told' => $r['status'] === 200 && str_contains($r['body'], 'A choice for this was saved from somewhere else while this page was open')];
};
check('R16d a page drawn as a NEW choice cannot write "No" over a choice another administrator saved meanwhile: refused, theirs kept, and it says why',
    $scnRaceKeepsTheirs(), ['drawn as new' => true, 'their answer kept' => 1, 'told' => true]);
// Round 11: the same stale page, but its first save is ALSO wrong in another
// way (a group audience with nobody ticked). It used to be refused for that,
// redrawn as the OTHER choice's checkbox with the marker gone and the box
// unticked, so the next save wrote "No" over the "Yes". A page drawn as new
// now never saves over a choice that has appeared since, whatever else its
// post says.
$scnRaceAfterRefusal = static function () use ($db, $signIn, $http, $tokenOn, $impId, $feedId): array {
    $url = '/admin/calendar/feeds/choice?event=' . $impId . '&scope=date';
    $signIn('orgadmin');
    $page = $http('GET', $url)['body'];
    $http('POST', $url, ['csrf_token' => $tokenOn($page), 'action' => 'save', 'drawnAsNew' => '1', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'websiteOptIn' => '1']);
    $fresh = $http('GET', $url)['body'];
    $r = $http('POST', $url, ['csrf_token' => $tokenOn($fresh), 'action' => 'save', 'drawnAsNew' => '1', 'audienceLevel' => 'groups', 'detailLevel' => 'basic']);
    $row = $db->query("SELECT `websiteOptIn` FROM `tblExternalEventChoices` WHERE `feedID` = {$feedId}")->fetch_assoc();
    $db->query("DELETE FROM `tblExternalEventChoices` WHERE `feedID` = {$feedId}");
    $db->query("UPDATE `tblEvents` SET `importLevel` = 'public', `importDetail` = 'full', `importWebsite` = 1, `importSource` = 'calendar', "
        . "`importSourceID` = NULL, `importRecheckAt` = NULL WHERE `eventID` = {$impId}");
    // What the page is redrawn AS (round 14): the saved choice — its audience
    // and its ticked box — not what was typed. A message alone is not the fix:
    // drawn from what was typed, the next Save would write over the choice.
    $shown = preg_match('/<input[^>]*name="audienceLevel"[^>]*id="choice-level-([a-z]+)"[^>]*\bchecked\b/', $r['body'], $lm) === 1 ? $lm[1] : 'none';
    $box   = preg_match('/<input[^>]*id="choice-website"[^>]*>/', $r['body'], $bm) === 1 ? (preg_match('/\bchecked\b/', $bm[0]) === 1 ? 'ticked' : 'unticked') : 'no box';
    return ['their answer kept' => (int) ($row['websiteOptIn'] ?? -1),
            'told' => $r['status'] === 200 && str_contains($r['body'], 'A choice for this was saved from somewhere else while this page was open'),
            'audience shown' => $shown, 'box' => $box];
};
check('R16e the stale NEW-choice page is refused for the newer choice even when its post is wrong in another way too (round 11)',
    $scnRaceAfterRefusal(), ['their answer kept' => 1, 'told' => true, 'audience shown' => 'public', 'box' => 'ticked']);
// Round 11: a SAVED choice with its website box ticked, switched to a group
// audience (the script disables the box, so nothing is sent) and refused for
// ticking nobody, used to come back with the box UNTICKED, so going back to
// Everyone and saving turned the stored Yes into No. The redraw now takes an
// unsent answer from the saved choice.
$scnExistingRedraw = static function () use ($db, $signIn, $http, $tokenOn, $impId, $feedId): array {
    $url = '/admin/calendar/feeds/choice?event=' . $impId . '&scope=date';
    $signIn('orgadmin');
    $page = $http('GET', $url)['body'];
    $http('POST', $url, ['csrf_token' => $tokenOn($page), 'action' => 'save', 'drawnAsNew' => '1', 'audienceLevel' => 'public', 'detailLevel' => 'basic', 'websiteOptIn' => '1']);
    $fresh = $http('GET', $url)['body'];
    $r = $http('POST', $url, ['csrf_token' => $tokenOn($fresh), 'action' => 'save', 'audienceLevel' => 'groups', 'detailLevel' => 'basic']);
    $box = preg_match('/<input[^>]*id="choice-website"[^>]*>/', $r['body'], $m) === 1 ? (preg_match('/\bchecked\b/', $m[0]) === 1 ? 'ticked' : 'unticked') : 'no box';
    $row = $db->query("SELECT `websiteOptIn` FROM `tblExternalEventChoices` WHERE `feedID` = {$feedId}")->fetch_assoc();
    $db->query("DELETE FROM `tblExternalEventChoices` WHERE `feedID` = {$feedId}");
    $db->query("UPDATE `tblEvents` SET `importLevel` = 'public', `importDetail` = 'full', `importWebsite` = 1, `importSource` = 'calendar', "
        . "`importSourceID` = NULL, `importRecheckAt` = NULL WHERE `eventID` = {$impId}");
    return ['refused' => $r['status'] === 200 && str_contains($r['body'], 'Choose at least one group or person.'),
            'box' => $box, 'stored' => (int) ($row['websiteOptIn'] ?? -1)];
};
check('R16f a saved choice\'s ticked website box stays ticked when the form comes back after a refusal made while the box was switched off (round 11)',
    $scnExistingRedraw(), ['refused' => true, 'box' => 'ticked', 'stored' => 1]);
// The calendar's own "Also show on the public website" box does NOTHING under
// "one tick per event", and its settings page must say so rather than read as
// working (#671: a control that changes nothing is a lie with a Save button).
$scnInert = static function () use ($signIn, $setRule, $http, $feedId): array {
    $signIn('orgadmin');
    $setRule('event', null);
    $under = str_contains($http('GET', '/admin/calendar/feeds/edit?id=' . $feedId)['body'], 'data-website-box-inert="1"');
    $setRule('calendar', null);
    $notUnder = str_contains($http('GET', '/admin/calendar/feeds/edit?id=' . $feedId)['body'], 'data-website-box-inert="1"');
    $setRule('event', null);
    return [$under, $notUnder];
};
check('R11 the calendar\'s settings page says its website box does nothing under "one tick per event", and not under the looser rule',
    $scnInert(), [true, false]);

// The SITEMAP follows the rule too (round 1, 3a): the calendar-ticked copied-in
// event is listed under "one box per calendar" and not under "one tick per event".
$impSlug = (string) $db->query("SELECT `eventSlug` FROM `tblEvents` WHERE `eventID` = {$impId}")->fetch_assoc()['eventSlug'];
$scnSitemapRule = static function () use ($setRule, $sitemapHas, $impSlug): bool {
    $setRule('calendar', null);
    $loose = $sitemapHas($impSlug);
    $setRule('event', null);
    $strict = $sitemapHas($impSlug);
    return $loose === true && $strict === false;
};
check('R12 the sitemap lists the calendar-ticked copied-in event under "one box per calendar" and NOT under "one tick per event"', $scnSitemapRule(), true);

// A stored rule the portal cannot read counts as the STRICTER (round 1, 3b),
// at the installation's level and at the organisation's.
$scnGarbageRule = static function () use ($setRule, $countdownNext, $cEvent): bool {
    $setRule('Calendar', null);
    $a = $countdownNext() === $cEvent;
    $setRule('calendar', 'whatever');
    $b = $countdownNext() === $cEvent;
    $setRule('event', null);
    return $a === true && $b === true;
};
check('R13 an unreadable rule ("Calendar", "whatever") counts as "one tick per event", at either level', $scnGarbageRule(), true);

// The event's OWN PAGE (round 2): its search-engine markup (`application/ld+json`)
// for a copied-in event follows the same rule. Read signed out, as a search
// engine reads it. The portal's OWN events are asked about in R14b below
// (D-089, #850: their markup needs the tick too).
$markupOf = static function () use ($http, $impSlug): array {
    $r = $http('GET', '/calendar/event?slug=' . rawurlencode($impSlug), [], [], false);
    return [$r['status'], str_contains($r['body'], 'application/ld+json')];
};
$scnMarkupRule = static function () use ($setRule, $markupOf, $db, $impId): array {
    $db->query("UPDATE `tblEvents` SET `importSource` = 'calendar' WHERE `eventID` = {$impId}");
    $setRule('calendar', null);
    $loose = $markupOf();
    $setRule('event', null);
    $strict = $markupOf();
    $db->query("UPDATE `tblEvents` SET `importSource` = 'date' WHERE `eventID` = {$impId}");
    $byChoice = $markupOf();
    $db->query("UPDATE `tblEvents` SET `importSource` = 'calendar' WHERE `eventID` = {$impId}");
    return ['calendar box, looser rule' => $loose, 'calendar box, stricter rule' => $strict, 'date choice, stricter rule' => $byChoice];
};
check('R14 the event page gives a calendar-ticked copied-in event search-engine markup under "one box per calendar", NOT under "one tick per event", '
    . 'and does under "one tick per event" when the yes came from a choice about that date',
    $scnMarkupRule(),
    ['calendar box, looser rule' => [200, true], 'calendar box, stricter rule' => [200, false], 'date choice, stricter rule' => [200, true]]);
// D-089 (#850): an organisation's OWN event's page carries search-engine
// markup only while the event is on the public website — "Public" AND the
// website box AND today inside its dates — asked of the same rule the
// door's events feed and the sitemap use. Until D-089 it followed "Public" alone, so
// the first reading here (Public, box off) is the one that used to be wrong.
// The page itself is reachable in all three: only the markup changes.
$ownMarkupOf = static function () use ($http, $cSlug): array {
    $r = $http('GET', '/calendar/event?slug=' . rawurlencode($cSlug), [], [], false);
    return [$r['status'], str_contains($r['body'], 'application/ld+json')];
};
$scnOwnMarkup = static function () use ($db, $setEv, $cEvent, $ownMarkupOf): array {
    $was = $db->query("SELECT `showOnWebsite`, `websiteFrom`, `websiteUntil`, `isPublic`, `status`, `isDeleted` FROM `tblEvents` WHERE `eventID` = {$cEvent}")->fetch_assoc();
    $setEv($cEvent, ['showOnWebsite' => '0', 'websiteFrom' => null, 'websiteUntil' => null, 'isPublic' => '1', 'status' => 'published', 'isDeleted' => '0']);
    $off = $ownMarkupOf();
    $setEv($cEvent, ['showOnWebsite' => '1']);
    $on = $ownMarkupOf();
    $setEv($cEvent, ['websiteFrom' => '2099-01-01']);
    $notYet = $ownMarkupOf();
    $setEv($cEvent, $was);
    return ['Public, box off' => $off, 'Public, box on' => $on, 'box on, dates not yet' => $notYet];
};
check('R14b an own event\'s page gives search-engine markup only while it is on the public website: not with "Public" alone, yes with the box ticked, not before its "from" date (D-089)',
    $scnOwnMarkup(),
    ['Public, box off' => [200, false], 'Public, box on' => [200, true], 'box on, dates not yet' => [200, false]]);

// The Roles page's "nobody holds any of these roles" warning (round 2), drawn
// for EVERY permission and judged against the registry's own answer about
// global administrators, never against a sentence written for another state.
$capList = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child . $sep . 'ask-capabilities.php') . ' 2>&1'), true);
$scnRolesPage = static function () use ($signIn, $http, $capList): array {
    $signIn('root');
    $page = $http('GET', '/admin/roles')['body'];
    $out  = [];
    foreach ((array) $capList as $key => $fallsOpen) {
        if (preg_match('/<div[^>]*data-no-role-holder="' . preg_quote((string) $key, '/') . '"[^>]*>(.*?)<\/div>/s', $page, $m) !== 1) {
            $out[$key] = 'no warning drawn';
            continue;
        }
        $words = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) ?? '';
        $right = $fallsOpen === true
            ? str_contains($words, 'nobody opens this door through a role. Administrators of this organisation, and global administrators, still can.') && str_contains($words, 'nobody can open this door') === false
            : str_contains($words, 'nobody can open this door — not even a global administrator.') && str_contains($words, 'still can') === false;
        $wrong = str_contains($words, 'until a role is named here again') || str_contains($words, 'change it back');
        $out[$key] = ($right === true && $wrong === false) ? 'right' : 'WRONG: ' . $words;
    }
    return $out;
};
$rolesSeen = $scnRolesPage();
$rolesWant = [];
foreach ((array) $capList as $key => $unused) {
    // `website.publish` and `website.setup` are the two this world gives a
    // holder (the Website Editor; and, since #493 P3, the Website Set-up holder
    // of section W). Section W's W16 takes that holder away for a moment and
    // reads `website.setup`'s own sentence, which differs from every other's.
    $rolesWant[$key] = in_array($key, ['website.publish', 'website.setup'], true) === true ? 'no warning drawn' : 'right';
}
check('R15 the Roles page\'s "nobody holds these roles" warning, for EVERY permission, says a global administrator still gets in exactly where one does',
    [is_array($capList) && count($capList) > 10 && array_key_exists('erasure.review', $capList), $rolesSeen], [true, $rolesWant]);

// ── V. What the pages SAY about the website (round 1, finding 2) ────────────
// One rule row and two waiting approval rows on the same calendar, all with the
// website box ticked: one asked for by a RULE, one by a CHOICE about the date.
$db->query("INSERT INTO `tblExternalFeedRules` (`siteID`, `feedID`, `name`, `audienceLevel`, `websiteOptIn`, `isActive`, `createdAt`) "
    . "VALUES ({$siteOne}, {$feedId}, 'PWT rule', 'public', 1, 1, UTC_TIMESTAMP())");
$pwtRule = (int) $db->insert_id;
foreach (['rule' => $pwtRule, 'choice' => 999001] as $origin => $originId) {
    $db->query("INSERT INTO `tblExternalEventApprovals` (`siteID`, `feedID`, `eventID`, `origin`, `originID`, `requestHash`, `contentHash`, `status`, `reason`, "
        . "`requestedLevel`, `requestedDetail`, `requestedWebsite`, `requestedAudienceSummary`, `snapTitle`, `snapStart`, `snapTimezone`, `createdAt`) VALUES "
        . "({$siteOne}, {$feedId}, {$impId}, '{$origin}', {$originId}, REPEAT('a', 64), REPEAT('b', 64), 'pending', 'new_match', 'public', 'full', 1, '', "
        . "'PWT approval {$origin}', '2030-01-01 10:00:00', 'UTC', UTC_TIMESTAMP())");
}
$rowWords = static function (string $text, string $title): string {
    $at = strpos($text, $title);
    if ($at === false) {
        return '';
    }
    $next = strpos($text, 'PWT approval ', $at + strlen($title));
    return substr($text, $at, ($next === false ? 600 : $next - $at));
};
$viewWords = static function () use ($db, $signIn, $http, $feedId, $rowWords): array {
    // ⚠️ Round 6: saving the rule now re-works every outside calendar at once,
    //    and these two approval rows are made by hand with placeholder
    //    fingerprints, so any re-work of this calendar — a plant posting the
    //    rule form included — rightly marks them superseded and the page lists
    //    neither. P18 went red for that reason alone. They are put back to
    //    waiting before every read, so each read is of the rows it is about.
    $db->query("UPDATE `tblExternalEventApprovals` SET `status` = 'pending' WHERE `snapTitle` LIKE 'PWT approval %'");
    $signIn('orgadmin');
    $page = $http('GET', '/admin/calendar/feeds/view?id=' . $feedId)['body'];
    $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($page), ENT_QUOTES, 'UTF-8')) ?? '';
    return [
        'event badge on'     => str_contains($page, 'data-website-badge="on"'),
        'event badge inert'  => str_contains($page, 'data-website-badge="inert"'),
        'rule badge on'      => str_contains($page, 'data-rule-website-badge="on"'),
        'rule badge inert'   => str_contains($page, 'data-rule-website-badge="inert"'),
        'label says on'      => str_contains($text, 'also on the public website'),
        'label says inert'   => str_contains($text, 'website box ticked, but it does nothing'),
        // Each approval row's own words: from its title up to the NEXT row's
        // title, so one row's sentence can never be read as another's (the
        // first version of this read 400 characters on and found the choice
        // row's words under the rule row).
        'rule approval on'   => str_contains($rowWords($text, 'PWT approval rule'), 'and on the public website'),
        'choice approval on' => str_contains($rowWords($text, 'PWT approval choice'), 'and on the public website'),
    ];
};
$scnWordsStrict = static function () use ($setRule, $viewWords): array {
    $setRule('event', null);
    return $viewWords();
};
$db->query("UPDATE `tblEvents` SET `importSource` = 'calendar' WHERE `eventID` = {$impId}");
check('V1 under "one tick per event" the calendar page claims the website for NOTHING that is not on it: the calendar-ticked event, the rule and the '
    . 'calendar\'s own label all say their box does nothing, a rule\'s waiting request does not promise the website, and a CHOICE\'s request still does',
    $scnWordsStrict(),
    ['event badge on' => false, 'event badge inert' => true, 'rule badge on' => false, 'rule badge inert' => true,
        'label says on' => false, 'label says inert' => true, 'rule approval on' => false, 'choice approval on' => true]);
$setRule('calendar', null);
check('V2 under "one box per calendar" every one of them says it IS on the public website',
    $viewWords(),
    ['event badge on' => true, 'event badge inert' => false, 'rule badge on' => true, 'rule badge inert' => false,
        'label says on' => true, 'label says inert' => false, 'rule approval on' => true, 'choice approval on' => true]);
$setRule('event', null);

// =============================================================================
echo "N. A new event starts members-only (D-079)\n";
// =============================================================================
// Every way a NEW event is made must start it with "Public" unticked; events
// that already exist keep what they have (migration 259 changes the column's
// starting value and nothing stored).
$publicOf = static function (string $name) use ($db): ?int {
    $stmt = $db->prepare('SELECT `isPublic` FROM `tblEvents` WHERE `eventName` = ? ORDER BY `eventID` DESC LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row === null ? null : (int) $row['isPublic'];
};
$colDefault = $db->query("SELECT `COLUMN_DEFAULT` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() "
    . "AND `TABLE_NAME` = 'tblEvents' AND `COLUMN_NAME` = 'isPublic'")->fetch_assoc();
check('N1 the column starts at 0 on a fresh install (the install script; migration 259 does the same for an upgrade)', trim((string) ($colDefault['COLUMN_DEFAULT'] ?? ''), "'"), '0');
$scnFormBox = static function () use ($signIn, $http): ?bool {
    $signIn('orgadmin');
    $page = $http('GET', '/calendar/manage')['body'];
    if (preg_match('/<input[^>]*id="isPublic-new"[^>]*>/', $page, $m) !== 1) {
        return null; // the form was not drawn: the check fails rather than passing
    }
    return preg_match('/\bchecked\b/', $m[0]) === 1;
};
check('N2 the Manage Events form starts a NEW event with "Public" unticked', $scnFormBox(), false);
$scnApiDefault = static function () use ($api, $keyBase, $publicOf, $db): array {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT N api default'");
    $r = $api('/api/events/create', $keyBase + ['eventName' => 'PWT N api default']);
    return [$r['status'], $publicOf('PWT N api default')];
};
check('N3 the events API treats a missing isPublic as false: created, members-only', $scnApiDefault(), [201, 0]);
// The CSV import posts a file, which the shared $http (form fields only)
// cannot, so this sends its own two requests with the same cookie jar.
$scnImportDefault = static function () use ($signIn, $http, $tokenOn, $publicOf, $db, $port, $jar, $child, $sep): ?int {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT N import default'");
    $signIn('orgadmin');
    $csv = $child . $sep . 'n-import.csv';
    file_put_contents($csv, "eventName,startDateTime,endDateTime,status\n\"PWT N import default\",2030-08-01 10:00:00,2030-08-01 11:00:00,published\n");
    $page = $http('GET', '/calendar/manage/import')['body'];
    $handle = curl_init('http://127.0.0.1:' . $port . '/calendar/manage/import');
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => ['csrf_token' => $tokenOn($page), 'action' => 'preview', 'csv_file' => new CURLFile($csv, 'text/csv', 'n.csv')]]);
    $preview = (string) curl_exec($handle);
    curl_close($handle);
    $token = $tokenOn($preview) !== '' ? $tokenOn($preview) : $tokenOn($page);
    $http('POST', '/calendar/manage/import', ['csrf_token' => $token, 'action' => 'confirm']);
    return $publicOf('PWT N import default');
};
check('N4 the CSV import treats a missing isPublic column as members-only', $scnImportDefault(), 0);
// D-092: approving an event a member of the public suggested keeps it
// members-only (Approve used to set isPublic = 1 with no "Public" choice shown).
$scnApprove = static function () use ($makeEvent, $siteOne, $signIn, $http, $tokenOn, $db): array {
    $id = $makeEvent('PWT N suggested', $siteOne, '2030-09-01 10:00:00', ['status' => 'draft', 'isPublic' => '0', 'submissionStatus' => 'pending']);
    $signIn('orgadmin');
    $page = $http('GET', '/admin/calendar/moderation')['body'];
    $http('POST', '/admin/calendar/moderate', ['csrf_token' => $tokenOn($page), 'eventID' => (string) $id, 'action' => 'approve']);
    $row = $db->query("SELECT `status`, `isPublic`, `submissionStatus` FROM `tblEvents` WHERE `eventID` = {$id}")->fetch_assoc();
    $db->query("DELETE FROM `tblEvents` WHERE `eventID` = {$id}");
    return [$row['submissionStatus'] ?? null, $row['status'] ?? null, (int) ($row['isPublic'] ?? -1)];
};
check('N5 approving a suggested event publishes it to members only: approved, published, isPublic still 0 (D-092)', $scnApprove(), ['approved', 'published', 0]);

// =============================================================================
echo "E. The e-mail to the person who suggested an event (#859, D-134)\n";
// =============================================================================
// D-134: when an administrator approves a suggestion, the person who made it
// is e-mailed once, at the address they gave, and their address is used for
// nothing else. Rejecting sends nothing; a suggestion with no usable address
// sends nothing; a broken mail route still approves.
//
// HOW WHAT WAS SENT IS READ, two ways, because each sees what the other cannot:
//   - THE SEND LOG (`tblEmailLog`), the portal's own record: the mail route
//     for these checks is the SMTP mailbox with nothing filled in, so every
//     attempt is written there as "not set up" with its subject and its
//     recipient, and no connection is made to anything (the same arrangement
//     as part T of tools/time-zones-selftest.php). That is what "exactly one
//     e-mail to that address" is counted from.
//   - THE WORDS, AND THE HTML THEY BECOME. The send log keeps no body, so the
//     copy's `Mailer::send()` is given one added line, just after it has
//     worked out the HTML it will send, that writes what it was HANDED and
//     that HTML to a file of this run's own. It changes nothing the method
//     does. It stays in until the end of the plants for this section, because
//     plants P124/P125 read the HTML too (round 1 of #859's check: a stranger's
//     typing was sent as HTML, and only the RENDERED body shows that).
//   - ONE MESSAGE IS KEPT for real (E19): an SMTP route set up to a port
//     nothing listens on, so the message is kept in the mail queue and its
//     stored HTML read back from `tblMailQueue`.
$mailerPath  = $child . $sep . 'web' . $sep . '_core' . $sep . 'Mailer.php';
$mailerWas   = (string) file_get_contents($mailerPath);
$capturePath = $child . $sep . 'mail-capture.jsonl';
$sendAfter   = "        \$plainBody = \$looksLikeHtml === true\n            ? self::htmlToPlain(\$body)\n            : \$body;\n";
check('E0 the copy\'s Mailer::send() can be watched (the line after its HTML is worked out was found exactly once)', substr_count($mailerWas, $sendAfter), 1);
file_put_contents($mailerPath, str_replace($sendAfter, $sendAfter
    . '        @file_put_contents(' . var_export($capturePath, true) . ', json_encode([\'to\' => $to, \'subject\' => $subj, \'body\' => $body, \'options\' => $options, \'html\' => $htmlBody]) . "\n", FILE_APPEND);' . "\n",
    $mailerWas));

/** The installation-wide mail route, read and written as a row (no page sets it to "nothing filled in"). */
$mailSetting = static function (string $key, ?string $value = null, int $sensitive = 0) use ($db): string {
    if ($value !== null) {
        $u = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ?, `isSensitive` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
        $u->bind_param('sis', $value, $sensitive, $key);
        $u->execute();
        $u->close();
    }
    $r = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
    $r->bind_param('s', $key);
    $r->execute();
    $got = (string) ($r->get_result()->fetch_assoc()['settingValue'] ?? '');
    $r->close();
    return $got;
};
// Every installation-wide mail row as it was, so all of them go back however
// many this section changes (round 1: E19 sets the whole SMTP route up).
$mailRowsWas = $db->query("SELECT `settingKey`, `settingValue`, `isSensitive` FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` LIKE 'mail.%'")->fetch_all(MYSQLI_ASSOC);
$putMailBack = static function () use ($db, $mailRowsWas): void {
    foreach ($mailRowsWas as $was) {
        $u = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ?, `isSensitive` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
        $v = (string) $was['settingValue'];
        $f = (int) $was['isSensitive'];
        $k = (string) $was['settingKey'];
        $u->bind_param('sis', $v, $f, $k);
        $u->execute();
        $u->close();
    }
};
/** The route every check here uses unless it says otherwise: SMTP with nothing filled in, so nothing connects anywhere. */
$routeNotSetUp = static function () use ($putMailBack, $mailSetting): void {
    $putMailBack();
    $mailSetting('mail.provider', 'smtp');
    $mailSetting('mail.smtp.host', '');
};
$approvedSubject = 'Your event suggestion has been approved';

/** Rows in the send log for one address and the approval subject. */
$sentTo = static function (string $address) use ($db, $approvedSubject): int {
    $s = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblEmailLog` WHERE `toRecipients` = ? AND `subject` = ?');
    $s->bind_param('ss', $address, $approvedSubject);
    $s->execute();
    $n = (int) ($s->get_result()->fetch_assoc()['n'] ?? 0);
    $s->close();
    return $n;
};
/** How many messages have been watched so far: a scenario counts only what comes after its own start. */
$capturedSoFar = static fn (): int => is_file($capturePath) === true ? count(file($capturePath) ?: []) : 0;
/** What Mailer::send() was handed for one address, in order, from line $from on. */
$handedTo = static function (string $address, int $from = 0) use ($capturePath): array {
    $out = [];
    foreach (array_slice(is_file($capturePath) === true ? (file($capturePath, FILE_IGNORE_NEW_LINES) ?: []) : [], $from) as $line) {
        $one = json_decode($line, true);
        if (is_array($one) === true && ($one['to'] ?? null) === $address) {
            $out[] = $one;
        }
    }
    return $out;
};
/** The moderation page's result message, read by loading the page the handler sent us back to. */
$moderationSays = static function () use ($http): ?string {
    $page = $http('GET', '/admin/calendar/moderation')['body'];
    return preg_match('/<div class="alert alert-[a-z]+" data-moderation-result>([^<]*)<\/div>/', $page, $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
};
/** Press Approve or Reject on the moderation page as the organisation's administrator. */
$moderate = static function (int $id, string $action) use ($signIn, $http, $tokenOn): array {
    $signIn('orgadmin');
    $page = $http('GET', '/admin/calendar/moderation')['body'];
    return $http('POST', '/admin/calendar/moderate', ['csrf_token' => $tokenOn($page), 'eventID' => (string) $id, 'action' => $action]);
};
$wentBack = static fn (array $answer): bool => $answer['status'] === 302 && str_contains($answer['location'], 'admin/calendar/moderation');

/**
 * The whole journey, over HTTP: a visitor who is NOT signed in suggests an
 * event on the real form, then an administrator presses the given buttons.
 *
 * @param list<string> $presses 'approve' or 'reject', in order.
 */
$scnSuggestion = static function (string $name, string $address, array $presses, string $route = 'smtp-empty', string $submitterName = 'Pat Suggester')
    use ($db, $http, $tokenOn, $jar, $moderate, $wentBack, $sentTo, $handedTo, $capturedSoFar, $moderationSays, $mailSetting, $routeNotSetUp): array {
    // A clean start, so a run under a plant counts only its own messages.
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = '" . $db->real_escape_string($name) . "'");
    $db->query("DELETE FROM `tblEmailLog` WHERE `toRecipients` = '" . $db->real_escape_string($address) . "'");
    $from = $capturedSoFar();
    // 'smtp-closed': the CALLER has set the route up already (E19), so it is
    // left exactly as it is; the other two are set here.
    if ($route !== 'smtp-closed') {
        $routeNotSetUp();
    }
    if ($route === 'ms365-empty') {
        $mailSetting('mail.provider', 'ms365');
    }
    @unlink($jar); // signed out: this is the visitor the form's promise is made to
    $form = $http('GET', '/calendar/submit')['body'];
    $http('POST', '/calendar/submit-save', [
        'csrf_token' => $tokenOn($form), 'eventName' => $name, 'startDateTime' => '2030-08-03T10:30',
        'submitterName' => $submitterName, 'submitterEmail' => $address,
    ]);
    $s = $db->prepare('SELECT `eventID`, `submitterEmail` FROM `tblEvents` WHERE `eventName` = ?');
    $s->bind_param('s', $name);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    if ($row === null) {
        return ['the suggestion was not stored' => true];
    }
    $id = (int) $row['eventID'];
    $answers = [];
    $said    = [];
    foreach ($presses as $press) {
        $answers[] = $wentBack($moderate($id, $press));
        $said[]    = $moderationSays();
    }
    $state  = $db->query("SELECT `submissionStatus`, `status`, `isPublic` FROM `tblEvents` WHERE `eventID` = {$id}")->fetch_assoc();
    $handed = $handedTo($address, $from);
    return [
        'stored address'   => (string) $row['submitterEmail'] === $address,
        'went back'        => $answers,
        'state'            => [(string) $state['submissionStatus'], (string) $state['status'], (int) $state['isPublic']],
        'send log rows'    => $sentTo($address),
        'handed to Mailer' => count($handed),
        'first message'    => $handed[0] ?? null,
        'page said'        => $said,
        'id'               => $id,
    ];
};

$e1 = $scnSuggestion('PWT E approved', 'pat.suggester@selftest.invalid', ['approve']);
check('E1 a visitor\'s suggestion approved over HTTP: stored with their address, approved, published, still members-only (D-092), and the page went back to the queue',
    [$e1['stored address'] ?? null, $e1['went back'] ?? null, $e1['state'] ?? null], [true, [true], ['approved', 'published', 0]]);
check('E2 ...EXACTLY ONE message to that address is in the send log, under the approval subject', $e1['send log rows'] ?? null, 1);
$msg  = $e1['first message'] ?? [];
$body = (string) ($msg['body'] ?? '');
check('E3 ...and the message handed to Mailer: to that address alone, the approval subject (which names nothing), in the EVENT\'s organisation\'s name for the footer (#683)',
    [$e1['handed to Mailer'] ?? null, $msg['to'] ?? null, $msg['subject'] ?? null, $msg['options']['siteId'] ?? null],
    [1, 'pat.suggester@selftest.invalid', $approvedSubject, $siteOne]);
check('E4 ...whose words greet them, name the event and its date and time on the event\'s own clock, say it was approved, and say plainly it is shown to members only (D-092)',
    [
        str_starts_with($body, "Hello Pat Suggester,\n\n"),
        str_contains($body, '"PWT E approved", on Saturday 3 August 2030 at 10:30'),
        str_contains($body, 'has approved it, and it is now on their calendar.'),
        str_contains($body, '\'s members, who see it when they sign in. It will not appear on the calendar for anybody who is not signed in unless '),
        str_contains($body, 'We will not send you any other e-mail about this suggestion.'),
        str_contains($body, 'pat.suggester@selftest.invalid'),
    ],
    [true, true, true, true, true, false]);
check('E5 ...the moderation page says what became of the e-mail, in the words for one that did NOT go because this route is not set up (never "sent" of a message that was not)',
    $e1['page said'] ?? null, ['Approved, but the e-mail telling the person who suggested it could not be sent. Admin, Integrations, Email shows whether e-mail is set up.']);
check('E6 ...and the activity log records the e-mail\'s outcome for that event WITHOUT the address',
    [
        (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` = 'EventSuggesterTold' AND `activityDescription` LIKE 'Event #" . (int) ($e1['id'] ?? 0) . ":%'")->fetch_assoc()['n'] ?? 0),
        (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` IN ('EventSuggesterTold', 'EventModerated') AND `activityDescription` LIKE '%pat.suggester@%'")->fetch_assoc()['n'] ?? 0),
    ],
    [1, 0]);

$scnTwice = static fn (): array => $scnSuggestion('PWT E twice', 'twice.suggester@selftest.invalid', ['approve', 'approve']);
$e7 = $scnTwice();
check('E7 approving the same suggestion TWICE (a double click, or a stale page) sends ONE e-mail: one send-log row, one message handed to Mailer',
    [$e7['went back'] ?? null, $e7['send log rows'] ?? null, $e7['handed to Mailer'] ?? null], [[true, true], 1, 1]);

$e8 = $scnSuggestion('PWT E rejected', 'rejected.suggester@selftest.invalid', ['reject']);
check('E8 REJECTING a suggestion sends nothing (D-134 covers approval only): rejected, nothing in the send log, nothing handed to Mailer, and the page says so',
    [$e8['state'][0] ?? null, $e8['send log rows'] ?? null, $e8['handed to Mailer'] ?? null, $e8['page said'] ?? null],
    ['rejected', 0, 0, ['Rejected. Nothing was sent to the person who suggested it.']]);
$e8b = (static function () use ($moderate, $wentBack, $sentTo, $handedTo, $db, $e8): array {
    $id = (int) ($e8['id'] ?? 0);
    $back = $wentBack($moderate($id, 'approve'));
    $state = (string) ($db->query("SELECT `submissionStatus` FROM `tblEvents` WHERE `eventID` = {$id}")->fetch_assoc()['submissionStatus'] ?? '');
    return [$back, $state, $sentTo('rejected.suggester@selftest.invalid'), count($handedTo('rejected.suggester@selftest.invalid'))];
})();
check('E8b ...and pressing Approve on it afterwards (a stale page) neither approves it nor sends anything', $e8b, [true, 'rejected', 0, 0]);

/** A suggestion with no usable address, made straight in the database (the form refuses both). */
$scnNoAddress = static function (?string $stored) use ($makeEvent, $siteOne, $moderate, $wentBack, $moderationSays, $db, $capturePath, $mailSetting): array {
    $mailSetting('mail.provider', 'smtp');
    $mailSetting('mail.smtp.host', '');
    $set = ['status' => 'draft', 'isPublic' => '0', 'submissionStatus' => 'pending', 'submitterName' => 'No Address'];
    if ($stored !== null) {
        $set['submitterEmail'] = $stored;
    }
    $id     = $makeEvent('PWT E no address', $siteOne, '2030-08-04 10:00:00', $set);
    $before = is_file($capturePath) === true ? count(file($capturePath) ?: []) : 0;
    $logBefore = (int) ($db->query('SELECT COUNT(*) AS `n` FROM `tblEmailLog`')->fetch_assoc()['n'] ?? 0);
    $back   = $wentBack($moderate($id, 'approve'));
    $said   = $moderationSays();
    $after  = is_file($capturePath) === true ? count(file($capturePath) ?: []) : 0;
    $logAfter = (int) ($db->query('SELECT COUNT(*) AS `n` FROM `tblEmailLog`')->fetch_assoc()['n'] ?? 0);
    $state  = (string) ($db->query("SELECT `submissionStatus` FROM `tblEvents` WHERE `eventID` = {$id}")->fetch_assoc()['submissionStatus'] ?? '');
    $db->query("DELETE FROM `tblEvents` WHERE `eventID` = {$id}");
    return [$back, $state, $after - $before, $logAfter - $logBefore, $said];
};
$noneWords = 'Approved. Nobody was e-mailed: the suggestion holds no e-mail address to write to.';
check('E9 a suggestion holding NO address is approved and nothing is sent or logged', $scnNoAddress(null), [true, 'approved', 0, 0, $noneWords]);
check('E10 one holding something that is NOT an address is approved and nothing is sent or logged', $scnNoAddress('not-an-address'), [true, 'approved', 0, 0, $noneWords]);

// A member who was SIGNED IN gives no address: the form stores none, and they
// are not e-mailed (they were never told they would be).
$e11 = (static function () use ($signIn, $http, $tokenOn, $db, $moderate, $wentBack, $capturePath): array {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT E member'");
    $signIn('member');
    $http('POST', '/calendar/submit-save', ['csrf_token' => $tokenOn($http('GET', '/calendar/submit')['body']), 'eventName' => 'PWT E member', 'startDateTime' => '2030-08-05T10:00',
        'submitterEmail' => 'smuggled@selftest.invalid']);
    $row = $db->query("SELECT `eventID`, `submitterEmail` FROM `tblEvents` WHERE `eventName` = 'PWT E member'")->fetch_assoc();
    $before = is_file($capturePath) === true ? count(file($capturePath) ?: []) : 0;
    $back = $row === null ? null : $wentBack($moderate((int) $row['eventID'], 'approve'));
    $after = is_file($capturePath) === true ? count(file($capturePath) ?: []) : 0;
    return [$row === null ? 'not stored' : $row['submitterEmail'], $back, $after - $before];
})();
check('E11 a SIGNED-IN member\'s suggestion stores no address (even one posted by hand) and its approval e-mails nobody', $e11, [null, true, 0]);

// THE BROKEN ROUTE. The Microsoft 365 route with nothing set up THROWS, which
// is how this portal ships. The approval must still be stored, the page must
// still go back to the queue (not a 500), and the page says the e-mail failed.
$scnBroken = static fn (): array => $scnSuggestion('PWT E broken route', 'broken.route@selftest.invalid', ['approve'], 'ms365-empty');
$e12 = $scnBroken();
check('E12 with a mail route that THROWS, the suggestion is still approved, the page still goes back to the queue, Mailer WAS asked (so the throw was met), and the page says the e-mail could not be sent',
    [$e12['state'][0] ?? null, $e12['went back'] ?? null, $e12['handed to Mailer'] ?? null, $e12['page said'] ?? null],
    ['approved', [true], 1, ['Approved, but the e-mail telling the person who suggested it could not be sent. Admin, Integrations, Email shows whether e-mail is set up.']]);

// The words when an administrator had ALREADY ticked "Public" on the
// suggestion before approving it: the members-only sentence would be untrue.
$e13 = (static function () use ($makeEvent, $siteOne, $moderate, $handedTo, $db, $mailSetting): array {
    $mailSetting('mail.provider', 'smtp');
    $mailSetting('mail.smtp.host', '');
    $id = $makeEvent('PWT E public', $siteOne, '2030-08-06 00:00:00', ['status' => 'draft', 'isPublic' => '1', 'isAllDay' => '1', 'submissionStatus' => 'pending',
        'submitterName' => '', 'submitterEmail' => 'public.suggester@selftest.invalid']);
    $moderate($id, 'approve');
    $db->query("DELETE FROM `tblEvents` WHERE `eventID` = {$id}");
    $body = (string) (($handedTo('public.suggester@selftest.invalid')[0] ?? [])['body'] ?? '');
    return [str_starts_with($body, "Hello,\n\n"), str_contains($body, 'on Tuesday 6 August 2030. '), str_contains($body, 'Anyone can see it on the calendar, without signing in.'),
        str_contains($body, 'members')];
})();
check('E13 one already ticked "Public" (and all day, with no name given) is told anyone can see it — never "members only" — with the date and no time, and a plain "Hello,"', $e13, [true, true, true, false]);


// ── ROUND 1 OF #859's CHECK: four things it found nothing pinned ─────────────

// (1) A STRANGER'S TYPING MUST NEVER BE SENT AS HTML. Mailer guesses "HTML or
//     text" from the body, and before round 2 a body holding `<Supper>`, an
//     `<a href=…>` or an `<img src=…>` was sent AS WRITTEN — a working link or
//     a tracking image from the organisation's own mailbox. Each input goes in
//     through the real form; what Mailer was handed AND the HTML it built are
//     read. "Escaped" means the raw markup is absent and its escaped form is
//     present; the branded footer and the line breaks must be there too.
$htmlSafe = static function (array $run, array $raw, array $escaped): array {
    $m    = $run['first message'] ?? [];
    $html = (string) ($m['html'] ?? '');
    $out  = ['handed once' => $run['handed to Mailer'] ?? null, 'told it is text' => ($m['options']['bodyIsText'] ?? null)];
    foreach ($raw as $piece) {
        $out['raw absent: ' . $piece] = $html !== '' && str_contains($html, $piece) === false;
    }
    foreach ($escaped as $piece) {
        $out['escaped present: ' . $piece] = str_contains($html, $piece);
    }
    $out['footer'] = str_contains($html, '<!-- Footer -->');
    $out['line breaks'] = str_contains($html, '<br />');
    return $out;
};
$wantSafe = static function (array $raw, array $escaped): array {
    $out = ['handed once' => 1, 'told it is text' => true];
    foreach ($raw as $piece) {
        $out['raw absent: ' . $piece] = true;
    }
    foreach ($escaped as $piece) {
        $out['escaped present: ' . $piece] = true;
    }
    return $out + ['footer' => true, 'line breaks' => true];
};
$fishRaw = ['<Supper>'];
$fishEsc = ['Fish &amp; Chips &lt;Supper&gt;'];
check('E14 an event named "Fish & Chips <Supper>" is sent as TEXT: escaped, with the footer and the line breaks',
    $htmlSafe($scnSuggestion('PWT E Fish & Chips <Supper>', 'fish.suggester@selftest.invalid', ['approve']), $fishRaw, $fishEsc), $wantSafe($fishRaw, $fishEsc));
$linkName = 'Pat <a href="https://evil.example/login">Confirm your membership</a>';
$linkRaw  = ['<a href="https://evil.example/login">'];
$linkEsc  = ['Hello Pat &lt;a href=&quot;https://evil.example/login&quot;&gt;Confirm your membership&lt;/a&gt;,'];
$scnLinkName = static fn (): array => $scnSuggestion('PWT E link in name', 'link.suggester@selftest.invalid', ['approve'], 'smtp-empty', $linkName);
check('E15 a suggester\'s NAME carrying a working link is sent as text: no live link, the name shown escaped, footer and line breaks kept',
    $htmlSafe($scnLinkName(), $linkRaw, $linkEsc), $wantSafe($linkRaw, $linkEsc));
$imgRaw = ['<img src="https://tracker.example/p.gif">'];
$imgEsc = ['&lt;img src=&quot;https://tracker.example/p.gif&quot;&gt;Coffee'];
check('E16 an event name carrying a tracking image is sent as text: no image, shown escaped',
    $htmlSafe($scnSuggestion('PWT E <img src="https://tracker.example/p.gif">Coffee', 'img.suggester@selftest.invalid', ['approve']), $imgRaw, $imgEsc), $wantSafe($imgRaw, $imgEsc));
$ltRaw = [];
$ltEsc = ['Hello Sam &lt; Alex,'];
check('E17 an ordinary "<" in a name is shown escaped, as written',
    $htmlSafe($scnSuggestion('PWT E ordinary lt', 'lt.suggester@selftest.invalid', ['approve'], 'smtp-empty', 'Sam < Alex'), $ltRaw, $ltEsc), $wantSafe($ltRaw, $ltEsc));

// (2) A DELETED SUGGESTION. A pending suggestion can be deleted on Manage
//     Events; it used to stay on the moderation list, and Approve published it
//     and told the suggester it was "now on their calendar".
$scnDeleted = static function () use ($makeEvent, $siteOne, $moderate, $wentBack, $moderationSays, $sentTo, $db, $http, $routeNotSetUp): array {
    $routeNotSetUp();
    $db->query("DELETE FROM `tblEmailLog` WHERE `toRecipients` = 'deleted.suggester@selftest.invalid'");
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT E deleted suggestion'");
    $id = $makeEvent('PWT E deleted suggestion', $siteOne, '2030-08-07 10:00:00', ['status' => 'draft', 'isPublic' => '0', 'submissionStatus' => 'pending',
        'submitterName' => 'Del Eted', 'submitterEmail' => 'deleted.suggester@selftest.invalid', 'isDeleted' => '1']);
    $back = $wentBack($moderate($id, 'approve'));
    $said = $moderationSays();
    $listed = str_contains($http('GET', '/admin/calendar/moderation?status=pending')['body'], 'PWT E deleted suggestion');
    $state = $db->query("SELECT `submissionStatus`, `status` FROM `tblEvents` WHERE `eventID` = {$id}")->fetch_assoc();
    return ['listed' => $listed, 'went back' => $back, 'state' => [(string) $state['submissionStatus'], (string) $state['status']],
        'send log rows' => $sentTo('deleted.suggester@selftest.invalid'), 'page said' => $said];
};
check('E18 a DELETED pending suggestion is not on the moderation list, and pressing Approve on it (a stale page) approves nothing, publishes nothing and e-mails nobody',
    $scnDeleted(), ['listed' => false, 'went back' => true, 'state' => ['pending', 'draft'], 'send log rows' => 0,
        'page said' => 'Nothing changed: that suggestion has already been decided, or has been deleted. Nobody was e-mailed.']);

// (4) "NEVER SAY SENT ABOUT A KEPT MESSAGE". A message really KEPT: the SMTP
//     route set up in full, pointed at a port on this machine nothing listens
//     on, so the attempt cannot connect and the message is kept in the mail
//     queue. Its stored HTML is read back from the queue as well.
$closedPort = (static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = (string) stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($name, (int) strrpos($name, ':') + 1);
})();
$keyText = (string) file_get_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key');
$routeClosedPort = static function () use ($routeNotSetUp, $mailSetting, $closedPort, $keyText): void {
    $routeNotSetUp();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $mailSetting('mail.provider', 'smtp');
    $mailSetting('mail.smtp.host', '127.0.0.1');
    $mailSetting('mail.smtp.port', (string) $closedPort);
    $mailSetting('mail.smtp.security', 'starttls');
    $mailSetting('mail.smtp.username', 'pwt-mailbox');
    // Sealed exactly as the e-mail page seals it, and marked secret, or the route reads "not set up".
    $mailSetting('mail.smtp.password', base64_encode($nonce . sodium_crypto_secretbox('pwt-password', $nonce, sodium_hex2bin(hash('sha256', $keyText)))), 1);
    $mailSetting('mail.defaultFromAddress', 'office@pwt.selftest.invalid');
    $mailSetting('mail.defaultFromName', 'PWT Office');
    $mailSetting('mail.defaultReplyTo', '');
};
$scnKept = static function () use ($scnSuggestion, $routeClosedPort, $db, $linkName, $keyText): array {
    $db->query("DELETE FROM `tblMailQueue` WHERE `toRecipients` = 'kept.suggester@selftest.invalid'");
    $routeClosedPort();
    $run = $scnSuggestion('PWT E kept', 'kept.suggester@selftest.invalid', ['approve'], 'smtp-closed', $linkName);
    $rows = $db->query("SELECT `bodyHtml` FROM `tblMailQueue` WHERE `toRecipients` = 'kept.suggester@selftest.invalid'")->fetch_all(MYSQLI_ASSOC);
    $db->query("DELETE FROM `tblMailQueue` WHERE `toRecipients` = 'kept.suggester@selftest.invalid'");
    // The queue SEALS a waiting body at rest (MailQueue::seal(), the
    // installation's key), so it is opened here the way Auth::decrypt() does.
    $bin  = (string) base64_decode((string) ($rows[0]['bodyHtml'] ?? ''), true);
    $html = strlen($bin) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        ? (string) sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), sodium_hex2bin(hash('sha256', $keyText)))
        : '';
    return ['page said' => $run['page said'] ?? null, 'kept in the queue' => count($rows),
        'stored html escaped' => $html !== '' && str_contains($html, '<a href="https://evil.example') === false
            && str_contains($html, 'Hello Pat &lt;a href=&quot;https://evil.example/login&quot;&gt;') && str_contains($html, '<!-- Footer -->')];
};
$e19 = $scnKept();
check('E19 a message really KEPT in the mail queue is described as kept, never as sent, and is kept once',
    [$e19['page said'] ?? null, $e19['kept in the queue'] ?? null],
    [['Approved. The e-mail telling the person who suggested it is kept in the mail queue to be tried again; Admin, Integrations, Email shows whether anything is holding it up.'], 1]);
check('E19b ...and the HTML stored in the queue is the escaped text with the footer, not the stranger\'s link', $e19['stored html escaped'] ?? null, true);

// ...and every outcome's sentence, asked of the REAL `approvedWords()` in the
// copy, so a sentence that claimed "e-mailed" for a kept or failed message
// would show here even where no route can reach it.
file_put_contents($child . $sep . 'ask-told-words.php', <<<'ASK'
<?php
// Written by tools/public-website-tick-selftest.php for ONE run. Test only.
declare(strict_types=1);
require_once __DIR__ . '/web/_core/EventSuggestions.php';
use Portal\Core\EventSuggestions as S;
$out = [];
foreach ([S::TOLD_SENT, S::TOLD_WAITING, S::TOLD_NOT_SENT, S::TOLD_NO_ADDRESS] as $o) {
    $out[$o] = S::approvedWords($o);
}
echo json_encode($out);
ASK);
$told = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child . $sep . 'ask-told-words.php') . ' 2>&1'), true);
check('E20 approvedWords(): only a SENT message is called "e-mailed"; a kept one is "kept", a failed one "could not be sent", and no address "Nobody was e-mailed"',
    is_array($told) === true ? array_map(static fn (string $w): array => [str_contains($w, 'has been e-mailed'), str_contains($w, 'kept in the mail queue'),
        str_contains($w, 'could not be sent'), str_contains($w, 'Nobody was e-mailed')], $told) : $told,
    ['sent' => [true, false, false, false], 'waiting' => [false, true, false, false], 'not-sent' => [false, false, true, false], 'no-address' => [false, false, false, true]]);

// (3) "ON THE EVENT'S OWN CLOCK" WAS PINNED BY NOTHING: every fixture's event
//     shared the organisation's zone, so a conversion to Site::timezone() gave
//     the same hour. The organisation is put on Europe/London for these two
//     (and put back), so PHP's own clock in the request is London's too.
$zoneOneWas = (string) ($db->query("SELECT `timezone` FROM `tblSites` WHERE `siteID` = {$siteOne}")->fetch_assoc()['timezone'] ?? 'UTC');
$scnClock = static function (string $zone, string $reading) use ($makeEvent, $siteOne, $moderate, $handedTo, $capturedSoFar, $db, $routeNotSetUp, $zoneOneWas): string {
    $routeNotSetUp();
    $db->query("UPDATE `tblSites` SET `timezone` = 'Europe/London' WHERE `siteID` = {$siteOne}");
    $address = 'clock.suggester@selftest.invalid';
    $from = $capturedSoFar();
    $id = $makeEvent('PWT E clock', $siteOne, $reading, ['timezone' => $zone, 'status' => 'draft', 'isPublic' => '0', 'submissionStatus' => 'pending',
        'submitterName' => 'Clo Ck', 'submitterEmail' => $address]);
    $moderate($id, 'approve');
    $db->query("DELETE FROM `tblEvents` WHERE `eventID` = {$id}");
    $z = $db->prepare('UPDATE `tblSites` SET `timezone` = ? WHERE `siteID` = ?');
    $z->bind_param('si', $zoneOneWas, $siteOne);
    $z->execute();
    $z->close();
    return (string) (($handedTo($address, $from)[0] ?? [])['body'] ?? '');
};
$scnNewYork = static fn (): bool => str_contains($scnClock('America/New_York', '2030-08-03 10:30:00'), 'on Saturday 3 August 2030 at 10:30. ');
$scnGap     = static fn (): bool => str_contains($scnClock('Europe/London', '2030-03-31 01:30:00'), 'on Sunday 31 March 2030 at 01:30. ');
check('E21 an event on New York\'s clock, in an organisation on London\'s, is told at 10:30 — its OWN reading, not converted to the organisation\'s clock', $scnNewYork(), true);
check('E22 an event at 01:30 on the morning London\'s clocks go forward (an hour that does not exist) is told at 01:30, as stored, not moved to 02:30', $scnGap(), true);

// The mail rows go back as they were. The words-watching line stays in the
// copy's Mailer until the end of this section's plants (they read the HTML).
$putMailBack();

// =============================================================================
echo "Q. Noticeboard posters on the public website (#493 P2a)\n";
// =============================================================================
$poster = static function (string $title, int $site, array $set = []) use ($db): int {
    $stmt = $db->prepare("INSERT INTO `tblNoticeboardPosters` (`siteID`, `title`) VALUES (?, ?)");
    $stmt->bind_param('is', $site, $title);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    foreach ($set as $col => $val) {
        $u = $db->prepare('UPDATE `tblNoticeboardPosters` SET `' . $col . '` = ? WHERE `posterID` = ?');
        $u->bind_param('si', $val, $id);
        $u->execute();
        $u->close();
    }
    return $id;
};
$posterRow = static function (int $id) use ($db): ?array {
    return $db->query("SELECT `showOnWebsite`, `websiteFrom`, `websiteUntil`, `isDeleted` FROM `tblNoticeboardPosters` WHERE `posterID` = {$id}")->fetch_assoc();
};
$colDefault = $db->query("SELECT `COLUMN_DEFAULT` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() "
    . "AND `TABLE_NAME` = 'tblNoticeboardPosters' AND `COLUMN_NAME` = 'showOnWebsite'")->fetch_assoc();
check('Q1 a poster starts OFF the public website (the column\'s default)', trim((string) ($colDefault['COLUMN_DEFAULT'] ?? ''), "'"), '0');

// The ONE rule, asked in the test's own child process like every other rule
// read here, so a plant in the copy changes the answer.
$tdy  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
$qOn      = $poster('PWT Q on', $siteOne, ['showOnWebsite' => '1']);
$qOff     = $poster('PWT Q off', $siteOne);
$qLater   = $poster('PWT Q later', $siteOne, ['showOnWebsite' => '1', 'websiteFrom' => '2099-01-01']);
$qEarlier = $poster('PWT Q earlier', $siteOne, ['showOnWebsite' => '1', 'websiteUntil' => '2000-01-01']);
$qGone    = $poster('PWT Q deleted', $siteOne, ['showOnWebsite' => '1', 'isDeleted' => '1']);
$qOther   = $poster('PWT Q other organisation', $siteTwo, ['showOnWebsite' => '1']);
// Both days count (D-078): a poster whose first or last day IS today is on.
$qFromTdy  = $poster('PWT Q from today', $siteOne, ['showOnWebsite' => '1', 'websiteFrom' => $tdy]);
$qUntilTdy = $poster('PWT Q until today', $siteOne, ['showOnWebsite' => '1', 'websiteUntil' => $tdy]);
$scnPosterRule = static function () use ($child, $sep, $siteOne, $tdy): mixed {
    $probe = $child . $sep . 'q-poster-rule.php';
    file_put_contents($probe, '<?php declare(strict_types=1);'
        . 'require ' . var_export($child . $sep . 'web' . $sep . '_core' . $sep . 'PublicWebsite.php', true) . ';'
        . '$cfg = require ' . var_export($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', true) . ';'
        . '$db = new mysqli($cfg["db_host"], $cfg["db_user"], $cfg["db_pass"], $cfg["db_name"], (int) $cfg["db_port"]);'
        . '$r = \\Portal\\Core\\PublicWebsite::posterWhere("p", ' . var_export($tdy, true) . ');'
        . '$st = $db->prepare("SELECT p.title FROM tblNoticeboardPosters p WHERE p.siteID = ? AND p.title LIKE \'PWT Q %\'" . $r["sql"] . " ORDER BY p.title");'
        . '$st->bind_param("i" . $r["types"], ...array_merge([' . $siteOne . '], $r["params"]));'
        . '$st->execute(); $o = []; foreach ($st->get_result()->fetch_all() as $x) { $o[] = $x[0]; } echo json_encode($o);');
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
    $got = json_decode($raw, true);
    return is_array($got) === true ? $got : ['could not ask' => $raw];
};
// The organisation is the CALLER's `siteID = ?` (the rule does not carry it);
// the other organisation's ticked poster checks that the caller's filter is
// applied alongside the rule, not that the rule itself filters.
check('Q2 the ONE poster rule lets through only ticked, undeleted posters inside their dates, BOTH days counting (and the caller\'s organisation filter keeps the other organisation\'s out)',
    $scnPosterRule(), ['PWT Q from today', 'PWT Q on', 'PWT Q until today']);

$scnPosterPage = static function () use ($signIn, $http, $tokenOn, $posterRow, $qOff, $db): array {
    $signIn('orgadmin');
    $page = $http('GET', '/noticeboard/website')['body'];
    $drawn = str_contains($page, 'id="show-' . $qOff . '"');
    $r = $http('POST', '/noticeboard/website', ['csrf_token' => $tokenOn($page), 'posterID' => (string) $qOff, 'websiteFields' => '1',
        'showOnWebsite' => '1', 'websiteFrom' => '', 'websiteUntil' => '']);
    $row = $posterRow($qOff);
    $logged = (int) $db->query("SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` = 'PosterShownOnWebsite' AND `activityDescription` LIKE '%#{$qOff} %'")->fetch_assoc()['n'];
    $db->query("UPDATE `tblNoticeboardPosters` SET `showOnWebsite` = 0 WHERE `posterID` = {$qOff}");
    return ['drawn' => $drawn, 'status' => $r['status'], 'stored' => (int) ($row['showOnWebsite'] ?? -1), 'recorded' => $logged > 0];
};
check('Q3 somebody who may publish ticks a poster on Noticeboard, Public website: stored ON and recorded',
    $scnPosterPage(), ['drawn' => true, 'status' => 302, 'stored' => 1, 'recorded' => true]);

$scnPosterRefused = static function () use ($signIn, $http, $tokenOn, $posterRow, $qOff, $db): array {
    $signIn('member');
    $page = $http('GET', '/noticeboard/website')['body'];
    $drawn = str_contains($page, 'id="show-' . $qOff . '"');
    $http('POST', '/noticeboard/website', ['csrf_token' => $tokenOn($page), 'posterID' => (string) $qOff, 'websiteFields' => '1', 'showOnWebsite' => '1']);
    $row = $posterRow($qOff);
    $logged = (int) $db->query("SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` = 'PosterWebsiteChangeRefused' AND `activityDescription` LIKE '%#{$qOff} %'")->fetch_assoc()['n'];
    return ['controls drawn' => $drawn, 'stored' => (int) ($row['showOnWebsite'] ?? -1), 'refusal recorded' => $logged > 0];
};
check('Q4 a member who may not publish is drawn no controls, and a change they post anyway is refused, unchanged and recorded',
    $scnPosterRefused(), ['controls drawn' => false, 'stored' => 0, 'refusal recorded' => true]);
// The one person this page newly lets in: a Website Editor who is NOT an
// administrator (D-077). A page that asked "is an administrator" instead of
// the permission would refuse them while Q3 and Q4 stayed green.
$scnPosterEditor = static function () use ($signIn, $http, $tokenOn, $posterRow, $qOff, $db): array {
    $signIn('editor');
    $page = $http('GET', '/noticeboard/website')['body'];
    $board = $http('GET', '/noticeboard')['body'];
    $http('POST', '/noticeboard/website', ['csrf_token' => $tokenOn($page), 'posterID' => (string) $qOff, 'websiteFields' => '1', 'showOnWebsite' => '1']);
    $row = $posterRow($qOff);
    $db->query("UPDATE `tblNoticeboardPosters` SET `showOnWebsite` = 0 WHERE `posterID` = {$qOff}");
    return ['controls drawn' => str_contains($page, 'id="show-' . $qOff . '"'), 'linked from the board' => str_contains($board, 'noticeboard/website'),
        'stored' => (int) ($row['showOnWebsite'] ?? -1)];
};
check('Q3b a Website Editor who is not an administrator is drawn the controls, linked to the page from the board, and can tick a poster',
    $scnPosterEditor(), ['controls drawn' => true, 'linked from the board' => true, 'stored' => 1]);
$scnPosterForeign = static function () use ($signIn, $http, $tokenOn, $posterRow, $qOther): array {
    $signIn('orgadmin');
    $page = $http('GET', '/noticeboard/website')['body'];
    $http('POST', '/noticeboard/website', ['csrf_token' => $tokenOn($page), 'posterID' => (string) $qOther, 'websiteFields' => '1', 'showOnWebsite' => '0']);
    $after = $http('GET', '/noticeboard/website')['body'];
    return ['other organisation\'s poster unchanged' => (int) ($posterRow($qOther)['showOnWebsite'] ?? -1), 'told' => str_contains($after, 'could not be found on this noticeboard')];
};
check('Q8 another organisation\'s poster cannot be changed by posting its number: unchanged, and the page says it was not found',
    $scnPosterForeign(), ['other organisation\'s poster unchanged' => 1, 'told' => true]);

$scnPosterDates = static function () use ($signIn, $http, $tokenOn, $posterRow, $qOff): array {
    $signIn('orgadmin');
    $page = $http('GET', '/noticeboard/website')['body'];
    $http('POST', '/noticeboard/website', ['csrf_token' => $tokenOn($page), 'posterID' => (string) $qOff, 'websiteFields' => '1',
        'showOnWebsite' => '1', 'websiteFrom' => '2031-05-02', 'websiteUntil' => '2031-05-01']);
    $row = $posterRow($qOff);
    return [(int) ($row['showOnWebsite'] ?? -1), $row['websiteFrom'] ?? null];
};
check('Q5 a last day before the first is refused and nothing is written', $scnPosterDates(), [0, null]);

// The board's own editor rewrites the whole board: the website fields must
// survive it, and a poster it creates must start off the website.
$scnEditorKeeps = static function () use ($signIn, $http, $tokenOn, $api, $posterRow, $qOn, $db): array {
    $signIn('orgadmin');
    $token = $tokenOn($http('GET', '/noticeboard')['body']);
    $keep = [];
    $res = $db->query("SELECT `posterID`, `title` FROM `tblNoticeboardPosters` WHERE `siteID` = {$GLOBALS['siteOne']} AND `isDeleted` = 0");
    while ($row = $res->fetch_assoc()) {
        $keep[] = ['id' => 'p' . (int) $row['posterID'], 'title' => (string) $row['title']];
    }
    $keep[] = ['id' => 'pnew1', 'title' => 'PWT Q made by the editor'];
    $r = $api('/api/noticeboard/save', ['posters' => $keep], $token);
    $made = $db->query("SELECT `showOnWebsite` FROM `tblNoticeboardPosters` WHERE `title` = 'PWT Q made by the editor' ORDER BY `posterID` DESC LIMIT 1")->fetch_assoc();
    return ['status' => $r['status'], 'ticked poster kept on' => (int) ($posterRow($qOn)['showOnWebsite'] ?? -1),
        'new poster' => (int) ($made['showOnWebsite'] ?? -1)];
};
check('Q6 a save from the board\'s own editor keeps a ticked poster ON, and a poster it creates starts OFF',
    $scnEditorKeeps(), ['status' => 200, 'ticked poster kept on' => 1, 'new poster' => 0]);
// Round 1: deleting a ticked poster on the board, then a save that names its
// id again (an editor tab opened before the deletion), must NOT bring it back
// onto the public website. Deleting takes it off.
$scnDeleteRevive = static function () use ($signIn, $http, $tokenOn, $api, $posterRow, $poster, $siteOne, $db): array {
    $rev = $poster('PWT Q revived', $siteOne, ['showOnWebsite' => '1']);
    $signIn('orgadmin');
    $all = static function () use ($db, $siteOne): array {
        $out = [];
        $res = $db->query("SELECT `posterID`, `title` FROM `tblNoticeboardPosters` WHERE `siteID` = {$siteOne} AND `isDeleted` = 0");
        while ($row = $res->fetch_assoc()) {
            $out[] = ['id' => 'p' . (int) $row['posterID'], 'title' => (string) $row['title']];
        }
        return $out;
    };
    $without = array_values(array_filter($all(), static fn (array $x): bool => $x['id'] !== 'p' . $rev));
    $token = $tokenOn($http('GET', '/noticeboard')['body']);
    $api('/api/noticeboard/save', ['posters' => $without], $token);
    $afterDelete = $posterRow($rev);
    $token = $tokenOn($http('GET', '/noticeboard')['body']);
    $api('/api/noticeboard/save', ['posters' => array_merge($all(), [['id' => 'p' . $rev, 'title' => 'PWT Q revived']])], $token);
    $afterRevive = $posterRow($rev);
    return ['deleted' => (int) ($afterDelete['isDeleted'] ?? -1), 'off when deleted' => (int) ($afterDelete['showOnWebsite'] ?? -1),
        'back on the board' => (int) ($afterRevive['isDeleted'] ?? -1), 'still off the website' => (int) ($afterRevive['showOnWebsite'] ?? -1)];
};
check('Q7 deleting a ticked poster on the board takes it off the website, and a save that brings it back leaves it OFF',
    $scnDeleteRevive(), ['deleted' => 1, 'off when deleted' => 0, 'back on the board' => 0, 'still off the website' => 0]);

// =============================================================================
echo "S. The foyer screen: a secret link, and what it shows (#493 P2b, D-086)\n";
// =============================================================================
// Each scenario builds its own posters (titles 'PWT S …'), so it gives the same
// answer when a plant re-runs it after section Q has emptied the board.
$foyerSet = static function (string $permitted, ?string $ownOne) use ($db, $siteOne): void {
    $db->query("UPDATE `tblSettings` SET `settingValue` = '" . $db->real_escape_string($permitted) . "' WHERE `settingKey` = 'noticeboard.foyer.permitted' AND `siteID` IS NULL");
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'noticeboard.foyer.shows' AND `siteID` = {$siteOne}");
    if ($ownOne !== null) {
        $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES ({$siteOne}, 'noticeboard.foyer.shows', '"
            . $db->real_escape_string($ownOne) . "', 'public', 0)");
    }
};
$foyerWorld = static function () use ($db, $poster, $siteOne, $siteTwo): void {
    $db->query("DELETE FROM `tblNoticeboardPosters` WHERE `title` LIKE 'PWT S %'");
    $poster('PWT S on', $siteOne, ['showOnWebsite' => '1']);
    $poster('PWT S off', $siteOne);
    $poster('PWT S deleted', $siteOne, ['showOnWebsite' => '1', 'isDeleted' => '1']);
    $poster('PWT S two on', $siteTwo, ['showOnWebsite' => '1']);
    $poster('PWT S two off', $siteTwo);
};
$foyerToken = static function (int $site) use ($db): ?string {
    $row = $db->query("SELECT `token` FROM `tblNoticeboardFoyer` WHERE `siteID` = {$site}")->fetch_assoc();
    return $row === null ? null : (string) $row['token'];
};
/** The 'PWT S …' titles a foyer address shows, sorted, or its status when it is not 200. */
$foyerShows = static function (string $k) use ($http): array|int {
    $r = $http('GET', '/noticeboard/foyer?token=' . rawurlencode($k), [], [], false);
    if ($r['status'] !== 200) {
        return $r['status'];
    }
    preg_match_all('/>(PWT S [^<]+)</', $r['body'], $m);
    $titles = array_values(array_unique(array_map(static fn (string $t): string => html_entity_decode($t, ENT_QUOTES, 'UTF-8'), $m[1])));
    sort($titles);
    return $titles;
};
$foyerPost = static function (string $role, array $fields) use ($signIn, $http, $tokenOn): array {
    $signIn($role);
    $page = $http('GET', '/noticeboard/foyer/setup');
    $r = $http('POST', '/noticeboard/foyer/setup', ['csrf_token' => $tokenOn($page['body'])] + $fields);
    return ['page' => $page, 'post' => $r];
};
$ownShows = static function () use ($db, $siteOne): ?string {
    $row = $db->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'noticeboard.foyer.shows' AND `siteID` = {$siteOne}")->fetch_assoc();
    return $row === null ? null : (string) $row['settingValue'];
};
$permittedNow = static function () use ($db): ?string {
    $row = $db->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'noticeboard.foyer.permitted' AND `siteID` IS NULL")->fetch_assoc();
    return $row === null ? null : (string) $row['settingValue'];
};
$db->query("DELETE FROM `tblNoticeboardFoyer` WHERE `siteID` IN ({$siteOne}, {$siteTwo})");
$foyerSet('public', null);
$foyerWorld();

// S1: nothing is shown without a real secret, and the refusal says nothing.
$scnFoyerNoKey = static function () use ($http): array {
    $out = [];
    foreach (['none' => '/noticeboard/foyer', 'malformed' => '/noticeboard/foyer?token=abc', 'array' => '/noticeboard/foyer?token[]=x',
        'unknown' => '/noticeboard/foyer?token=' . str_repeat('a', 48)] as $name => $path) {
        $r = $http('GET', $path, [], [], false);
        $out[$name] = [$r['status'], trim($r['body'])];
    }
    return $out;
};
check('S1 the foyer screen with no secret, a malformed one, an array, or an unknown one answers a plain 404 that says nothing more',
    $scnFoyerNoKey(), ['none' => [404, 'Not found.'], 'malformed' => [404, 'Not found.'], 'array' => [404, 'Not found.'], 'unknown' => [404, 'Not found.']]);

// S2: the setup page is for whoever manages the noticeboard.
$scnFoyerSetupGate = static function () use ($signIn, $http): array {
    $signIn('member');
    $member = $http('GET', '/noticeboard/foyer/setup');
    $signIn('orgadmin');
    $admin = $http('GET', '/noticeboard/foyer/setup');
    return ['member' => $member['status'], 'administrator' => $admin['status'], 'says no link yet' => str_contains($admin['body'], 'There is no foyer screen link yet')];
};
check('S2 a plain member is refused the setup page (403); an administrator of the organisation opens it and is told there is no link yet',
    $scnFoyerSetupGate(), ['member' => 403, 'administrator' => 200, 'says no link yet' => true]);

$scnFoyerMake = static function () use ($foyerPost, $http, $foyerToken, $siteOne, $logged, $people): array {
    $r = $foyerPost('orgadmin', ['action' => 'replace']);
    $token = $foyerToken($siteOne);
    $page = $http('GET', '/noticeboard/foyer/setup')['body'];
    return ['status' => $r['post']['status'], 'token shape' => $token !== null && preg_match('/^[a-f0-9]{48}$/', $token) === 1,
        'link on the page' => $token !== null && str_contains($page, 'noticeboard/foyer?token=' . $token),
        'QR drawn into the page' => str_contains($page, 'src="data:image/'),
        'recorded' => $logged('NoticeboardFoyerLinkMade', (int) $people['orgadmin']['id'], 'organisation ' . $siteOne) > 0];
};
check('S3 an administrator makes the link: a 48-character secret is stored, the page shows the link and draws its QR code into the page, and it is recorded',
    $scnFoyerMake(), ['status' => 302, 'token shape' => true, 'link on the page' => true, 'QR drawn into the page' => true, 'recorded' => true]);
$tokOne = (string) $foyerToken($siteOne);

$scnFoyerPublic = static function () use ($foyerSet, $foyerWorld, $foyerShows, $foyerToken, $siteOne): array|int {
    $foyerSet('public', null);
    $foyerWorld();
    return $foyerShows((string) $foyerToken($siteOne));
};
check('S4 by default the screen shows ONLY this organisation\'s posters on the public website (not an unticked, deleted or other organisation\'s one)',
    $scnFoyerPublic(), ['PWT S on']);

$scnFoyerHeaders = static function () use ($http, $foyerToken, $siteOne): array {
    $r = $http('GET', '/noticeboard/foyer?token=' . (string) $foyerToken($siteOne), [], [], false);
    $csp = preg_match('/^Content-Security-Policy:\s*(.+)$/mi', $r['head'], $m) === 1 ? trim($m[1]) : '';
    $cspNonce = preg_match("/script-src 'nonce-([^']+)'/", $csp, $n) === 1 ? $n[1] : '';
    $scriptNonce = preg_match('/<script nonce="([^"]+)"/', $r['body'], $sm) === 1 ? $sm[1] : '';
    return [
        'no referrer'      => preg_match('/^Referrer-Policy:\s*no-referrer\s*$/mi', $r['head']) === 1,
        'not indexed'      => preg_match('/^X-Robots-Tag:\s*noindex, nofollow\s*$/mi', $r['head']) === 1,
        // `private` as well as `no-store`: the portal's service worker ignores
        // no-store on a signed-out page and refuses only private (round 3).
        'not stored'       => preg_match('/^Cache-Control:\s*no-store, private\s*$/mi', $r['head']) === 1,
        'script by nonce'  => $cspNonce !== '' && $cspNonce === $scriptNonce,
        'nothing else'     => str_contains($csp, "default-src 'none'"),
        // Round 1: a poster's own picture, video or Canva design must still be
        // allowed, and the reload's own question to the page (connect-src).
        'posters allowed'  => str_contains($csp, "img-src 'self' https: data:") && str_contains($csp, "media-src 'self' https:")
            && str_contains($csp, 'frame-src https://www.canva.com') && str_contains($csp, "connect-src 'self'"),
        'robots meta'      => str_contains($r['body'], '<meta name="robots" content="noindex, nofollow">'),
    ];
};
check('S5 the screen tells the browser to send no referrer (the secret is in its address), not to index or store it, and to run only its own script',
    $scnFoyerHeaders(), ['no referrer' => true, 'not indexed' => true, 'not stored' => true, 'script by nonce' => true, 'nothing else' => true, 'posters allowed' => true, 'robots meta' => true]);

$scnFoyerReplace = static function () use ($foyerPost, $foyerToken, $foyerShows, $siteOne, $logged, $people): array {
    $old = (string) $foyerToken($siteOne);
    $foyerPost('orgadmin', ['action' => 'replace']);
    $new = (string) $foyerToken($siteOne);
    return ['changed' => $old !== $new, 'old link' => $foyerShows($old), 'new link' => $foyerShows($new),
        'recorded' => $logged('NoticeboardFoyerLinkReplaced', (int) $people['orgadmin']['id'], 'organisation ' . $siteOne) > 0];
};
check('S6 making a new link stops the old one at once (404) while the new one works, and it is recorded',
    $scnFoyerReplace(), ['changed' => true, 'old link' => 404, 'new link' => ['PWT S on'], 'recorded' => true]);

// S7: one organisation's link shows that organisation's posters and no other's.
$scnFoyerTwo = static function () use ($db, $foyerWorld, $foyerSet, $foyerShows, $foyerToken, $siteOne, $siteTwo): array {
    $foyerSet('public', null);
    $foyerWorld();
    $two = bin2hex(random_bytes(24));
    $db->query("INSERT INTO `tblNoticeboardFoyer` (`siteID`, `token`, `createdAt`) VALUES ({$siteTwo}, '{$two}', CURRENT_TIMESTAMP) "
        . "ON DUPLICATE KEY UPDATE `token` = VALUES(`token`)");
    $out = ['one' => $foyerShows((string) $foyerToken($siteOne)), 'two' => $foyerShows($two)];
    $db->query("UPDATE `tblSites` SET `isActive` = 0 WHERE `siteID` = {$siteTwo}");
    $out['two, organisation switched off'] = $foyerShows($two);
    $db->query("UPDATE `tblSites` SET `isActive` = 1 WHERE `siteID` = {$siteTwo}");
    return $out;
};
check('S7 each organisation\'s link shows only its own posters, and a switched-off organisation\'s link answers 404',
    $scnFoyerTwo(), ['one' => ['PWT S on'], 'two' => ['PWT S two on'], 'two, organisation switched off' => 404]);

// S8: "every poster" only when the installation permits it AND the organisation chose it.
$scnFoyerTiers = static function () use ($foyerSet, $foyerWorld, $foyerShows, $foyerToken, $siteOne): array {
    $foyerWorld();
    $k = (string) $foyerToken($siteOne);
    $out = [];
    foreach (['installation public, organisation all' => ['public', 'all'], 'installation either, nothing chosen' => ['either', null],
        'installation either, organisation all' => ['either', 'all'], 'installation either, organisation garbage' => ['either', 'everything']] as $name => [$perm, $own]) {
        $foyerSet($perm, $own);
        $out[$name] = $foyerShows($k);
    }
    $foyerSet('public', null);
    return $out;
};
check('S8 every poster (never a deleted one) only where the installation permits a choice AND the organisation chose it; the stricter wins, and an unknown value counts as public',
    $scnFoyerTiers(), [
        'installation public, organisation all' => ['PWT S on'],
        'installation either, nothing chosen' => ['PWT S on'],
        'installation either, organisation all' => ['PWT S off', 'PWT S on'],
        'installation either, organisation garbage' => ['PWT S on'],
    ]);

// S9: who may save which choice, read back from the database.
$scnFoyerWho = static function () use ($foyerSet, $foyerPost, $ownShows, $permittedNow, $logged, $people, $siteOne): array {
    $out = [];
    $foyerSet('public', null);
    $foyerPost('orgadmin', ['action' => 'installation', 'value' => 'either']);
    $out['administrator sets the installation'] = $permittedNow();
    $foyerPost('siteroot', ['action' => 'installation', 'value' => 'either']);
    $out['organisation global administrator sets the installation'] = $permittedNow();
    $foyerPost('siteroot', ['action' => 'organisation', 'value' => 'all']);
    $out['organisation choice beyond what is permitted'] = $ownShows();
    $foyerPost('root', ['action' => 'installation', 'value' => 'either']);
    $out['installation global administrator sets the installation'] = $permittedNow();
    $foyerPost('orgadmin', ['action' => 'organisation', 'value' => 'all']);
    $out['administrator chooses for the organisation'] = $ownShows();
    $foyerPost('siteroot', ['action' => 'organisation', 'value' => 'all']);
    $out['organisation global administrator chooses'] = $ownShows();
    $out['refusal recorded'] = $logged('NoticeboardFoyerChoiceRefused', (int) $people['orgadmin']['id'], 'organisation ' . $siteOne) > 0;
    $foyerSet('public', null);
    return $out;
};
check('S9 only a global administrator of the installation sets what it permits; only the organisation\'s global administrator chooses for it, and only within that; refusals are recorded',
    $scnFoyerWho(), [
        'administrator sets the installation' => 'public',
        'organisation global administrator sets the installation' => 'public',
        'organisation choice beyond what is permitted' => null,
        'installation global administrator sets the installation' => 'either',
        'administrator chooses for the organisation' => null,
        'organisation global administrator chooses' => 'all',
        'refusal recorded' => true,
    ]);

// S10: "today" is the TOKEN's organisation's date.
$scnFoyerToday = static function () use ($inZoneApart, $db, $poster, $foyerSet, $foyerShows, $foyerToken, $siteOne): mixed {
    return $inZoneApart(static function (array $z) use ($db, $poster, $foyerSet, $foyerShows, $foyerToken, $siteOne): array|int {
        $foyerSet('public', null);
        $db->query("DELETE FROM `tblNoticeboardPosters` WHERE `title` LIKE 'PWT S %'");
        $poster('PWT S org today', $siteOne, ['showOnWebsite' => '1', 'websiteFrom' => $z['orgToday'], 'websiteUntil' => $z['orgToday']]);
        $poster('PWT S utc today', $siteOne, ['showOnWebsite' => '1', 'websiteFrom' => $z['utcToday'], 'websiteUntil' => $z['utcToday']]);
        return $foyerShows((string) $foyerToken($siteOne));
    });
};
check('S10 a poster\'s website dates are judged on the organisation\'s own calendar, not UTC\'s', $scnFoyerToday(), ['PWT S org today']);
// S11 (round 1): the Noticeboard's on/off switch is the switch of the
// organisation the SECRET names. The router's own test reads the address's
// organisation, which for a signed-out screen is always organisation 1.
$switchOff = static function (int $site, bool $off) use ($db): void {
    if ($off === true) {
        $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES ({$site}, 'noticeboard.enabled', '0', '1', 0) "
            . "ON DUPLICATE KEY UPDATE `settingValue` = '0'");
    } else {
        $db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'noticeboard.enabled' AND `siteID` = {$site}");
    }
};
$scnFoyerSwitch = static function () use ($db, $foyerWorld, $foyerSet, $foyerShows, $foyerToken, $switchOff, $siteOne, $siteTwo, $signIn, $http): array {
    $foyerSet('public', null);
    $foyerWorld();
    $two = bin2hex(random_bytes(24));
    $db->query("INSERT INTO `tblNoticeboardFoyer` (`siteID`, `token`, `createdAt`) VALUES ({$siteTwo}, '{$two}', CURRENT_TIMESTAMP) "
        . "ON DUPLICATE KEY UPDATE `token` = VALUES(`token`)");
    $one = (string) $foyerToken($siteOne);
    $out = [];
    try {
        $switchOff($siteTwo, true);
        $out['two switched off: two'] = $foyerShows($two);
        $out['two switched off: one'] = $foyerShows($one);
        $switchOff($siteTwo, false);
        $switchOff($siteOne, true);
        $out['one switched off: two'] = $foyerShows($two);
        $out['one switched off: one'] = $foyerShows($one);
        // Round 2: the router still applies the switch to the noticeboard's
        // OTHER pages — only the foyer screen is let through.
        $signIn('orgadmin');
        $out['one switched off: setup page'] = $http('GET', '/noticeboard/foyer/setup')['status'];
    } finally {
        $switchOff($siteOne, false);
        $switchOff($siteTwo, false);
    }
    return $out;
};
check('S11 an organisation that switches the Noticeboard off stops ITS screen (404) and nobody else\'s — including when it is the organisation a signed-out screen\'s address resolves to — and the Noticeboard\'s other pages stay switched off',
    $scnFoyerSwitch(), ['two switched off: two' => 404, 'two switched off: one' => ['PWT S on'], 'one switched off: two' => ['PWT S two on'], 'one switched off: one' => 404,
        'one switched off: setup page' => 403]);
// The list of pages the router lets through is pinned whole, so a page added
// to it is a deliberate, visible change (round 2: it was pinned by nothing).
$scnRouteList = static function () use ($child, $sep): string {
    $probe = $child . $sep . 's-routes.php';
    file_put_contents($probe, '<?php declare(strict_types=1); require ' . var_export($child . $sep . 'web' . $sep . '_core' . $sep . 'AppRegistry.php', true) . ';'
        . 'echo json_encode(\\Portal\\Core\\AppRegistry::ROUTES_JUDGING_THEIR_OWN_ORGANISATION);');
    return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1'));
};
check('S11b the router lets exactly one page decide its own organisation\'s switch: the foyer screen', $scnRouteList(), '{"noticeboard\\/foyer":"noticeboard"}');

// S12 (round 1): the link's secret is blanked by the portal's own scrubber
// before an address is written to the error log, an alert e-mail or the
// outside error service — asked of the COPY's Logger with the address the
// setup page really shows.
$scnFoyerScrubbed = static function () use ($signIn, $http, $child, $sep, $foyerToken, $siteOne): array {
    $signIn('orgadmin');
    $page = $http('GET', '/noticeboard/foyer/setup')['body'];
    $token = (string) $foyerToken($siteOne);
    if (preg_match('#<code id="foyer-link"[^>]*>([^<]+)</code>#', $page, $m) !== 1) {
        return ['link found' => false];
    }
    $link = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    $path = (string) (parse_url($link, PHP_URL_PATH) ?? '') . '?' . (string) (parse_url($link, PHP_URL_QUERY) ?? '');
    $probe = $child . $sep . 's-scrub.php';
    file_put_contents($probe, '<?php declare(strict_types=1); require ' . var_export($child . $sep . 'web' . $sep . '_core' . $sep . 'Logger.php', true) . ';'
        . 'echo \\Portal\\Core\\Logger::withoutJobToken(' . var_export($path, true) . ');');
    $recorded = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
    // The screen must still OPEN at that address, or a renamed parameter on
    // one side only would pass the half above.
    $opens = $http('GET', $path, [], [], false)['status'];
    return ['link found' => true, 'secret in link' => $token !== '' && str_contains($link, $token),
        'secret left in what is recorded' => $token !== '' && str_contains($recorded, $token), 'link opens the screen' => $opens];
};
check('S12 the link the setup page shows opens the screen, and the portal\'s log scrubber removes its secret from anything it records',
    $scnFoyerScrubbed(), ['link found' => true, 'secret in link' => true, 'secret left in what is recorded' => false, 'link opens the screen' => 200]);

// S13 (round 1): the screen never reloads blindly. NOT DRIVEN in a browser —
// nothing here runs the page's script — so this reads the script as text:
// the reload is asked of the address first and retried, and the old blind
// reload is gone.
$scnFoyerReload = static function () use ($http, $foyerToken, $siteOne): array {
    $ok   = $http('GET', '/noticeboard/foyer?token=' . (string) $foyerToken($siteOne), [], [], false);
    $gone = $http('GET', '/noticeboard/foyer?token=' . str_repeat('b', 48), [], [], false);
    $body = $ok['body'];
    $mark = '/^X-Portal-Foyer:\s*1\s*$/mi';
    return ['marks its 200' => preg_match($mark, $ok['head']) === 1, 'marks its 404' => $gone['status'] === 404 && preg_match($mark, $gone['head']) === 1,
        'asks first' => str_contains($body, "fetch(window.location.href, { cache: 'no-store', credentials: 'same-origin' })"),
        'reloads only on its own 200 or 404' => str_contains($body, "if (r.headers.get('X-Portal-Foyer') === '1' && (r.status === 200 || r.status === 404)) {"),
        'retries on anything else' => substr_count($body, 'setTimeout(tryReload, 60 * 1000);') === 2,
        'no blind reload' => str_contains($body, 'setTimeout(function () { window.location.reload(); }') === false];
};
check('S13 (script read as text, not run) the screen marks its own answers, asks its address before reloading, reloads only on a 200 or 404 it gave itself, and otherwise keeps playing and asks again',
    $scnFoyerReload(), ['marks its 200' => true, 'marks its 404' => true, 'asks first' => true, 'reloads only on its own 200 or 404' => true, 'retries on anything else' => true, 'no blind reload' => true]);
$foyerWorld();

// =============================================================================
echo "W. Admin → Public website, and who may hand the job out (#493 P3; D-085, D-093, D-094, D-095)\n";
// =============================================================================
// Every result is read back from the database (or from the REAL readers in the
// COPY of the portal, through `ask-setup.php`, so a plant made in the copy is
// what answers). The organisation-level keys are cleared before each scenario,
// so a plant re-running one gets the same starting world.
file_put_contents($child . $sep . 'ask-setup.php', <<<'ASK'
<?php
// Written by tools/public-website-tick-selftest.php for ONE run. Test only.
declare(strict_types=1);
$core = __DIR__ . '/web/_core/';
define('PORTAL_CORE', rtrim($core, '/'));
foreach (['App.php', 'Site.php', 'Logger.php', 'Roles.php', 'InstallationSettings.php', 'Capabilities.php', 'EventVisibility.php',
    'PublicWebsite.php', 'PortalAddress.php', 'SafeFetch.php', 'PublicWebsiteSetup.php'] as $f) {
    require_once $core . $f;
}
$c = require __DIR__ . '/web/_auth_keys/auth_creds.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name'], (int) $c['db_port']);
$mysqli->set_charset('utf8mb4');
$site = (int) $argv[1];
\Portal\Core\Site::init($mysqli, ['site' => ['timezoneKeptAt' => '']], $site);
echo json_encode([
    'frameAncestors' => \Portal\Core\PublicWebsiteSetup::frameAncestorsFor($mysqli, $site),
    'website'        => \Portal\Core\PublicWebsiteSetup::websiteAddressFor($mysqli, $site),
    'publicHost'     => \Portal\Core\PublicWebsiteSetup::publicHostFor($mysqli, $site),
    'permitted'      => \Portal\Core\PublicWebsiteSetup::framingPermitted($mysqli),
    'locked'         => [
        \Portal\Core\PublicWebsite::lockedInGeneralEditor('public.website.address'),
        \Portal\Core\PublicWebsite::lockedInGeneralEditor('public.frame.origins'),
        \Portal\Core\PublicWebsite::lockedInGeneralEditor('PUBLIC.FRAME.PERMITTED'),
    ],
    'pure'           => [
        'empty'  => \Portal\Core\PublicWebsiteSetup::frameAncestorsValue([]),
        'two'    => \Portal\Core\PublicWebsiteSetup::frameAncestorsValue(['https://a.example.org', 'https://b.example.org:8443']),
        'nasty'  => \Portal\Core\PublicWebsiteSetup::frameAncestorsValue(['*', "https://a.example.org; script-src *", "https://x.example.org\nX: y", "'self'", 'http://h.example.org']),
    ],
    // What the set-up check SAYS for answers that are cheapest to state
    // directly (W21 drives real answers through a real TLS server). Since
    // round 3 the verdict names no cause: a 200 answered; curl missing could
    // not be checked; ANYTHING else is one sentence that is always true, plus
    // the last status where there is one — including a stalled or oversized
    // answer, which used to have causes of their own. "No curl" is NOT asked
    // here: it is driven through the real path in a PHP without curl (W23).
    'verdicts'       => [
        'nothing'    => \Portal\Core\PublicWebsiteSetup::mainWebsiteVerdict(['ok' => false, 'status' => 0, 'capped' => false]),
        'ok'         => \Portal\Core\PublicWebsiteSetup::mainWebsiteVerdict(['ok' => true, 'status' => 200, 'capped' => false]),
        'big page'   => \Portal\Core\PublicWebsiteSetup::mainWebsiteVerdict(['ok' => false, 'status' => 200, 'capped' => true]),
        'forbidden'  => \Portal\Core\PublicWebsiteSetup::mainWebsiteVerdict(['ok' => false, 'status' => 403, 'capped' => false]),
        'too slow'   => \Portal\Core\PublicWebsiteSetup::mainWebsiteVerdict(['ok' => false, 'status' => 200, 'capped' => false,
            'message' => 'The other server took too long to answer.']),
        'too big'    => \Portal\Core\PublicWebsiteSetup::mainWebsiteVerdict(['ok' => false, 'status' => 404, 'capped' => true,
            'message' => 'The file is bigger than the size limit, so it was not read.']),
    ] + (static function (): array {
        // Rounds 6-9: EVERY status SafeFetch can return, not a sample — every
        // number from 0 to 999, because SafeFetch's header reader accepts any
        // three digits, leading zeros included (measured: 999 in round 8,
        // which one large website sends automated visitors; 42 and 99 in round
        // 9, from `HTTP/1.1 099` or a header line that looks like a status).
        // A rule singling out any one status cannot pass. The function is
        // pure, so asking all 1,000 costs nothing.
        $out = [];
        foreach (range(0, 999) as $code) {
            $out['HTTP ' . $code] = \Portal\Core\PublicWebsiteSetup::mainWebsiteVerdict(['ok' => false, 'status' => $code, 'capped' => false]);
        }
        return $out;
    })(),
]);
ASK);
$askSetup = static function (int $site) use ($child, $sep): array {
    // The reader's own log lines go to a file, not into the answer: a hand edit
    // it drops is logged by design, and that line is not part of the JSON.
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('error_log=' . $child . $sep . 'ask-setup.log') . ' '
        . escapeshellarg($child . $sep . 'ask-setup.php') . ' ' . $site . ' 2>&1');
    $got = json_decode($raw, true);
    return is_array($got) === true ? $got : ['could not ask' => $raw];
};

/** One organisation-level (or installation, $site null) settings row, or null. */
$pwRow = static function (?int $site, string $key) use ($db): ?string {
    if ($site === null) {
        $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
        $stmt->bind_param('s', $key);
    } else {
        $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
        $stmt->bind_param('si', $key, $site);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row === null ? null : (string) $row['settingValue'];
};
$pwHost = static function (int $site) use ($db): ?string {
    $row = $db->query("SELECT `hostName` FROM `tblPublicHosts` WHERE `siteID` = {$site}")->fetch_assoc();
    return $row === null ? null : (string) $row['hostName'];
};
/** Clear everything this section writes, so each scenario starts from the same world. */
$pwClean = static function () use ($db, $siteOne, $siteTwo): void {
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` IN ('public.website.address', 'public.frame.origins') AND (`siteID` IN ({$siteOne}, {$siteTwo}) OR `siteID` IS NULL)");
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'public.frame.permitted' AND `siteID` IS NOT NULL");
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'public.frame.permitted', 'yes', 'yes', 0) "
        . "ON DUPLICATE KEY UPDATE `settingValue` = 'yes'");
    $db->query("DELETE FROM `tblPublicHosts` WHERE `siteID` IN ({$siteOne}, {$siteTwo})");
    $db->query("DELETE FROM `tblApiRateLimits` WHERE `bucket` LIKE 'pwsetup:%'");
    $db->query("DELETE FROM `tblCapabilityMap` WHERE `siteID` = {$siteOne} AND `capabilityKey` IN ('website.setup', 'website.publish')");
};
/** A fresh form token for whoever is signed in (every page carries it in the header). */
$freshToken = static function () use ($http, $tokenOn): string {
    return $tokenOn($http('GET', '/')['body']);
};
/** Sign in, then post one form to Admin → Public website's save handler. */
$pwPost = static function (string $role, string $action, ?string $value, string $path = '/admin/public-website/save') use ($signIn, $http, $freshToken): array {
    $signIn($role);
    $fields = ['csrf_token' => $freshToken(), 'action' => $action];
    if ($value !== null) {
        $fields['value'] = $value;
    }
    return $http('POST', $path, $fields);
};
$recorded = static function (string $type, int $userId, string $needle) use ($logged): int {
    return $logged($type, $userId, $needle);
};

$pwClean();

// W1: who may open the page (D-085). Only the two global administrators and a
//     holder of the permission in THIS organisation; an ordinary administrator
//     (by either flag), a plain member, a Website EDITOR (a different
//     permission) and a holder in another organisation are refused.
$scnPwGate = static function () use ($signIn, $http): array {
    $out = [];
    foreach (['root', 'siteroot', 'setup', 'orgadmin', 'flagadmin', 'member', 'editor', 'setupelsewhere'] as $who) {
        $signIn($who);
        $out[$who] = $http('GET', '/admin/public-website')['status'];
    }
    return $out;
};
check('W1 Admin → Public website opens for the installation\'s and the organisation\'s global administrators and a Website Set-up holder, and refuses an ordinary administrator, a flag administrator, a member, a Website Editor and a holder in another organisation',
    $scnPwGate(), ['root' => 200, 'siteroot' => 200, 'setup' => 200, 'orgadmin' => 403, 'flagadmin' => 403, 'member' => 403, 'editor' => 403, 'setupelsewhere' => 403]);

// W2: the permission emptied (a deliberate "nobody, by role") — the holder is
//     refused, the organisation's global administrator still gets in.
$scnPwEmptied = static function () use ($db, $siteOne, $signIn, $http, $pwClean): array {
    $db->query("INSERT INTO `tblCapabilityMap` (`siteID`, `capabilityKey`, `decidedAtUtc`) VALUES ({$siteOne}, 'website.setup', UTC_TIMESTAMP())");
    $signIn('setup');
    $holder = $http('GET', '/admin/public-website')['status'];
    $signIn('siteroot');
    $global = $http('GET', '/admin/public-website')['status'];
    $pwClean();
    return ['holder' => $holder, 'organisation global administrator' => $global];
};
check('W2 with "Set up the public website" emptied, the role holder is refused and the organisation\'s global administrator still gets in',
    $scnPwEmptied(), ['holder' => 403, 'organisation global administrator' => 200]);

// W3: the handler asks the same gate; a refused post writes nothing and is recorded.
$scnPwSaveGate = static function () use ($pwPost, $pwRow, $siteOne, $recorded, $people, $http, $signIn, $freshToken, $pwClean): array {
    $pwClean();
    $save  = $pwPost('orgadmin', 'website', 'https://www.pwt-orgadmin.example.org');
    $signIn('member');
    $check = $http('POST', '/admin/public-website/check', ['csrf_token' => $freshToken()]);
    return [
        'save answered'  => $save['status'],
        'nothing stored' => $pwRow($siteOne, 'public.website.address'),
        'recorded'       => $recorded('PublicWebsiteSetupRefused', $people['orgadmin']['id'], 'Admin, Public website') >= 1,
        'check answered' => $check['status'],
    ];
};
check('W3 the save handler and the check button refuse an ordinary administrator and a member (403), store nothing, and record the refusal',
    $scnPwSaveGate(), ['save answered' => 403, 'nothing stored' => null, 'recorded' => true, 'check answered' => 403]);

// W4: the main website — a good one stored tidied, http stored with a warning,
//     bad ones refused with nothing written and recorded, empty deletes.
$scnPwWebsite = static function () use ($pwPost, $pwRow, $siteOne, $recorded, $people, $signIn, $http, $pwClean): array {
    $pwClean();
    $out = [];
    $pwPost('setup', 'website', '  HTTPS://WWW.PWT-Church.example.org/ ');
    $out['tidied'] = $pwRow($siteOne, 'public.website.address');
    $pwPost('setup', 'website', 'http://plain.pwt.example.org');
    $page = $http('GET', '/admin/public-website')['body'];
    $out['http stored'] = $pwRow($siteOne, 'public.website.address');
    $out['http warned'] = str_contains($page, 'begins http://');
    foreach (['a path' => 'https://pwt.example.org/church', 'ftp' => 'ftp://pwt.example.org', 'a user' => 'https://me@pwt.example.org',
        'a space' => 'https://pwt example.org', 'a query' => 'https://pwt.example.org/?a=1'] as $name => $bad) {
        $before = $pwRow($siteOne, 'public.website.address');
        $pwPost('setup', 'website', $bad);
        $out['refused: ' . $name] = $pwRow($siteOne, 'public.website.address') === $before;
    }
    $out['refusals recorded'] = $recorded('PublicWebsiteSetupRefused', $people['setup']['id'], 'main website') >= 5;
    $out['missing field refused'] = $pwPost('setup', 'website', null)['status'] === 302 && $pwRow($siteOne, 'public.website.address') === 'http://plain.pwt.example.org';
    $pwPost('setup', 'website', '');
    $out['empty deletes'] = $pwRow($siteOne, 'public.website.address');
    $out['save recorded'] = $recorded('PublicWebsiteAddressSaved', $people['setup']['id'], 'main website') >= 1;
    return $out;
};
check('W4 the main website: stored tidied, http accepted with a warning, a path, ftp, a user name, a space or a query refused with nothing written and recorded, a post with no value changes nothing, and empty deletes it',
    $scnPwWebsite(), ['tidied' => 'https://www.pwt-church.example.org', 'http stored' => 'http://plain.pwt.example.org', 'http warned' => true,
        'refused: a path' => true, 'refused: ftp' => true, 'refused: a user' => true, 'refused: a space' => true, 'refused: a query' => true,
        'refusals recorded' => true, 'missing field refused' => true, 'empty deletes' => null, 'save recorded' => true]);

// W5: the framing list (D-082). One bad line refuses the WHOLE list, naming it.
$scnPwFrames = static function () use ($pwPost, $pwRow, $siteOne, $recorded, $people, $signIn, $http, $pwClean): array {
    $pwClean();
    $out = [];
    $pwPost('setup', 'frame_origins', "https://A.pwt.example.org/\nhttps://b.pwt.example.org:8443\n\nhttps://a.pwt.example.org\nb2.pwt.example.org");
    $out['stored, tidied, duplicate collapsed'] = $pwRow($siteOne, 'public.frame.origins');
    $good = $out['stored, tidied, duplicate collapsed'];
    $bad = [
        'http'      => 'http://x.pwt.example.org',
        'wildcard'  => 'https://*.pwt.example.org',
        'bare star' => '*',
        'keyword'   => "'self'",
        'bare none' => 'none',
        'a path'    => 'https://x.pwt.example.org/page',
        'a query'   => 'https://x.pwt.example.org?a=1',
        'a number'  => 'https://192.0.2.4',
        'a short number' => 'https://127.1.2',
        'a hex number'   => 'https://0x7f.1',
        'data'      => 'data:text/html,x',
        'semicolon' => 'https://x.pwt.example.org; script-src *',
    ];
    foreach ($bad as $name => $line) {
        $r = $pwPost('setup', 'frame_origins', "https://ok.pwt.example.org\n" . $line);
        $out['refused: ' . $name] = $pwRow($siteOne, 'public.frame.origins') === $good;
    }
    $page = $http('GET', '/admin/public-website')['body'];
    $out['says which line'] = str_contains($page, 'Line 2');
    $many = [];
    for ($i = 1; $i <= 21; $i++) {
        $many[] = 'https://s' . $i . '.pwt.example.org';
    }
    $pwPost('setup', 'frame_origins', implode("\n", $many));
    $out['21 refused'] = $pwRow($siteOne, 'public.frame.origins') === $good;
    $pwPost('setup', 'frame_origins', implode("\n", array_slice($many, 0, 20)));
    $out['20 accepted'] = substr_count((string) $pwRow($siteOne, 'public.frame.origins'), "\n") + 1;
    $out['refusals recorded'] = $recorded('PublicWebsiteSetupRefused', $people['setup']['id'], 'framing list') >= 11;
    $pwPost('setup', 'frame_origins', '');
    $out['empty deletes'] = $pwRow($siteOne, 'public.frame.origins');
    return $out;
};
$wFramesWant = ['stored, tidied, duplicate collapsed' => "https://a.pwt.example.org\nhttps://b.pwt.example.org:8443\nhttps://b2.pwt.example.org"];
foreach (['http', 'wildcard', 'bare star', 'keyword', 'bare none', 'a path', 'a query', 'a number', 'a short number', 'a hex number', 'data', 'semicolon'] as $name) {
    $wFramesWant['refused: ' . $name] = true;
}
$wFramesWant += ['says which line' => true, '21 refused' => true, '20 accepted' => 20, 'refusals recorded' => true, 'empty deletes' => null];
check('W5 the framing list: tidied with duplicates collapsed; one bad line (http, a wildcard, a keyword, a path, a query, a number, data:, a ";") refuses the whole list naming the line; 21 refused and 20 accepted; empty deletes it',
    $scnPwFrames(), $wFramesWant);

// W6: organisation only. An INSTALLATION row of the list is ignored; the
//     reader never lets anything a hand edit stored into the header; the pure
//     builder answers 'none' for nothing and never passes a dangerous value.
$scnPwOrgOnly = static function () use ($db, $siteOne, $askSetup, $pwClean): array {
    $pwClean();
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'public.frame.origins', 'https://installation.pwt.example.org', '', 0)");
    $a = $askSetup($siteOne);
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES ({$siteOne}, 'public.frame.origins', "
        . "'https://ok.pwt.example.org\n*\nhttp://plain.pwt.example.org\nhttps://x.pwt.example.org; script-src *', '', 0)");
    $b = $askSetup($siteOne);
    $pwClean();
    return ['installation row ignored' => $a['frameAncestors'] ?? $a, 'hand edit filtered' => $b['frameAncestors'] ?? $b, 'pure' => $b['pure'] ?? null];
};
check('W6 an installation row of the framing list is ignored, a hand-edited list keeps only its valid lines, and the pure builder gives \'none\' for nothing and never a "*", ";", quote or line break',
    $scnPwOrgOnly(), ['installation row ignored' => "'none'", 'hand edit filtered' => 'https://ok.pwt.example.org',
        'pure' => ['empty' => "'none'", 'two' => 'https://a.example.org https://b.example.org:8443', 'nasty' => "'none'"]]);

// W7: one organisation's values never reach another's.
$scnPwOtherOrg = static function () use ($db, $siteOne, $siteTwo, $askSetup, $signIn, $http, $pwClean): array {
    $pwClean();
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES "
        . "({$siteTwo}, 'public.website.address', 'https://two.pwt.example.org', '', 0), ({$siteTwo}, 'public.frame.origins', 'https://twoframe.pwt.example.org', '', 0)");
    $db->query("INSERT INTO `tblPublicHosts` (`siteID`, `hostName`, `changedAtUtc`) VALUES ({$siteTwo}, 'events.two.pwt.example.org', UTC_TIMESTAMP())");
    $one = $askSetup($siteOne);
    $two = $askSetup($siteTwo);
    $signIn('siteroot');
    $page = $http('GET', '/admin/public-website')['body'];
    return [
        'one' => [$one['website'] ?? null, $one['frameAncestors'] ?? null, $one['publicHost'] ?? null],
        'two' => [$two['website'] ?? null, $two['frameAncestors'] ?? null, $two['publicHost'] ?? null],
        'one\'s page shows two\'s values' => str_contains($page, 'two.pwt.example.org'),
    ];
};
check('W7 organisation 2\'s main website, framing list and public address never reach organisation 1, by the readers or on its page',
    $scnPwOtherOrg(), ['one' => ['', "'none'", ''], 'two' => ['https://two.pwt.example.org', 'https://twoframe.pwt.example.org', 'events.two.pwt.example.org'],
        'one\'s page shows two\'s values' => false]);

// W8: the public address (D-095). Stored lower case; bad values refused; the
//     portal's own address refused; empty deletes. D-121 (#895): a name
//     another organisation has only ASKED for is accepted beside theirs, and
//     the card says another organisation asked too; a name another
//     organisation is APPROVED for is refused as taken, without naming it.
//     (Until #895 any name another organisation held was refused, approved
//     or not, by a unique key on the name.)
/**
 * #493 P4a (D-101): put the installation's `site.url` back exactly as it was —
 * DELETED when there was no row, never left as a new empty-string row (round
 * 7 of P4a's check: an empty row is not the same state as no row).
 */
$pwPutSiteUrlBack = static function (?array $was) use ($db): void {
    if ($was === null) {
        $db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'site.url' AND `siteID` IS NULL");
        return;
    }
    $restore = (string) $was['settingValue'];
    $st = $db->prepare("UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = 'site.url' AND `siteID` IS NULL");
    $st->bind_param('s', $restore);
    $st->execute();
    $st->close();
};
$scnPwHost = static function () use ($db, $siteOne, $siteTwo, $pwPost, $pwHost, $recorded, $people, $signIn, $http, $pwClean, $pwPutSiteUrlBack): array {
    $pwClean();
    $out = [];
    // #493 P4a (D-101): a public address cannot be saved while the portal's
    // own address is unset, so it is set for the whole scenario and put back.
    $siteUrl = $db->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'site.url' AND `siteID` IS NULL")->fetch_assoc();
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'site.url', 'https://portal.pwt.example.org', '', 0) "
        . "ON DUPLICATE KEY UPDATE `settingValue` = 'https://portal.pwt.example.org'");
    $pwPost('setup', 'public_host', 'Events.PWT-One.example.org');
    $out['stored'] = $pwHost($siteOne);
    foreach (['a scheme' => 'https://e.pwt.example.org', 'a port' => 'e.pwt.example.org:8443', 'a path' => 'e.pwt.example.org/x',
        'a user' => 'me@e.pwt.example.org', 'no dot' => 'localhost', 'a number' => '192.0.2.9', 'a short number' => '127.1',
        'a hex number' => '0x7f.0.0.1', 'non-ascii' => 'évents.pwt.example.org'] as $name => $bad) {
        $pwPost('setup', 'public_host', $bad);
        $out['refused: ' . $name] = $pwHost($siteOne) === 'events.pwt-one.example.org';
    }
    $db->query("INSERT INTO `tblPublicHosts` (`siteID`, `hostName`, `changedAtUtc`) VALUES ({$siteTwo}, 'shared.pwt.example.org', UTC_TIMESTAMP())");
    $pwPost('setup', 'public_host', 'SHARED.pwt.example.org');
    $page = $http('GET', '/admin/public-website')['body'];
    $out['another organisation\'s request: accepted beside it'] = $pwHost($siteOne);
    $out['another organisation\'s request: the clash is said'] = str_contains($page, 'Another organisation on this portal has asked for the same address');
    $out['another organisation\'s request: named nowhere'] = str_contains($page, 'Selftest PWT Two') === false;
    $pwPost('setup', 'public_host', 'events.pwt-one.example.org');
    $db->query("UPDATE `tblPublicHosts` SET `approvedAtUtc` = UTC_TIMESTAMP() WHERE `siteID` = {$siteTwo}");
    $pwPost('setup', 'public_host', 'SHARED.pwt.example.org');
    $page = $http('GET', '/admin/public-website')['body'];
    $out['another organisation\'s approved one refused'] = $pwHost($siteOne);
    $out['the refusal says so'] = str_contains($page, 'That public address is taken');
    $out['the refusal names nobody'] = str_contains($page, 'Selftest PWT Two') === false;
    $pwPost('setup', 'public_host', 'portal.pwt.example.org');
    $out['the portal\'s own address refused'] = $pwHost($siteOne);
    $out['refusals recorded'] = $recorded('PublicWebsiteSetupRefused', $people['setup']['id'], 'public address') >= 9;
    $pwPost('setup', 'public_host', '');
    $out['empty deletes'] = $pwHost($siteOne);
    $out['organisation 2 untouched'] = $pwHost($siteTwo);
    $pwPutSiteUrlBack($siteUrl);
    return $out;
};
$wHostWant = ['stored' => 'events.pwt-one.example.org'];
foreach (['a scheme', 'a port', 'a path', 'a user', 'no dot', 'a number', 'a short number', 'a hex number', 'non-ascii'] as $name) {
    $wHostWant['refused: ' . $name] = true;
}
$wHostWant += ['another organisation\'s request: accepted beside it' => 'shared.pwt.example.org',
    'another organisation\'s request: the clash is said' => true, 'another organisation\'s request: named nowhere' => true,
    'another organisation\'s approved one refused' => 'events.pwt-one.example.org', 'the refusal says so' => true, 'the refusal names nobody' => true,
    'the portal\'s own address refused' => 'events.pwt-one.example.org', 'refusals recorded' => true, 'empty deletes' => null,
    'organisation 2 untouched' => 'shared.pwt.example.org'];
check('W8 the public address: stored lower case; a scheme, port, path, user, single label, number or non-ASCII name refused; one another organisation only ASKED for accepted beside it, and one it is APPROVED for refused as taken, neither naming it (D-121); the portal\'s own address refused; empty deletes',
    $scnPwHost(), $wHostWant);

// W9: the installation's switch (D-094), at save AND at read.
$scnPwSwitch = static function () use ($db, $siteOne, $pwPost, $pwRow, $askSetup, $recorded, $people, $signIn, $http, $pwClean): array {
    $pwClean();
    $out = [];
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES ({$siteOne}, 'public.frame.origins', 'https://kept.pwt.example.org', '', 0)");
    $signIn('siteroot');
    $out['switch drawn for the organisation\'s global administrator'] = str_contains($http('GET', '/admin/public-website')['body'], 'data-installation-switch="1"');
    $pwPost('siteroot', 'frame_permitted', 'no');
    $out['organisation\'s global administrator cannot switch it'] = $pwRow(null, 'public.frame.permitted');
    $signIn('root');
    $out['switch drawn for the installation\'s'] = str_contains($http('GET', '/admin/public-website')['body'], 'data-installation-switch="1"');
    $pwPost('root', 'frame_permitted', 'maybe');
    $out['a made-up value refused'] = $pwRow(null, 'public.frame.permitted');
    $pwPost('root', 'frame_permitted', 'no');
    $out['installation switched off'] = $pwRow(null, 'public.frame.permitted');
    $out['read: none whatever the list says'] = $askSetup($siteOne)['frameAncestors'] ?? null;
    $pwPost('siteroot', 'frame_origins', 'https://new.pwt.example.org');
    $out['a non-empty list refused while off'] = $pwRow($siteOne, 'public.frame.origins');
    $pwPost('siteroot', 'frame_origins', '');
    $out['emptying still allowed'] = $pwRow($siteOne, 'public.frame.origins');
    $out['recorded'] = $recorded('PublicWebsiteFramingSwitchSaved', $people['root']['id'], 'FORBIDS') >= 1
        && $recorded('PublicWebsiteSetupRefused', $people['siteroot']['id'], 'framing switch') >= 1;
    $pwPost('root', 'frame_permitted', 'yes');
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES ({$siteOne}, 'public.frame.origins', 'https://kept.pwt.example.org', '', 0)");
    $out['read: the list again once allowed'] = $askSetup($siteOne)['frameAncestors'] ?? null;
    $db->query("UPDATE `tblSettings` SET `settingValue` = 'perhaps' WHERE `settingKey` = 'public.frame.permitted' AND `siteID` IS NULL");
    $out['read: an unknown stored value counts as off'] = $askSetup($siteOne)['frameAncestors'] ?? null;
    $pwClean();
    return $out;
};
check('W9 the installation\'s framing switch: drawn and saved only for its global administrator, a made-up value refused; while off every list reads \'none\', a non-empty list is refused and emptying is allowed; an unknown stored value counts as off',
    $scnPwSwitch(), ['switch drawn for the organisation\'s global administrator' => false, 'organisation\'s global administrator cannot switch it' => 'yes',
        'switch drawn for the installation\'s' => true, 'a made-up value refused' => 'yes', 'installation switched off' => 'no',
        'read: none whatever the list says' => "'none'", 'a non-empty list refused while off' => 'https://kept.pwt.example.org',
        'emptying still allowed' => null, 'recorded' => true, 'read: the list again once allowed' => 'https://kept.pwt.example.org',
        'read: an unknown stored value counts as off' => "'none'"]);

// W10: the general settings editor refuses all three keys (the lock itself is
//      driven over HTTP by tools/settings-locks-selftest.php, which finds every
//      lock method's keys by reading it; this pins that these three are there).
$scnPwLocked = static function () use ($askSetup, $siteOne): mixed {
    return $askSetup($siteOne)['locked'] ?? null;
};
check('W10 the general settings editor\'s lock covers the main website, the framing list and the installation\'s switch (capitals ignored)', $scnPwLocked(), [true, true, true]);

// W11: "Check my set-up" — POST only, the form token, the door row never a
//      tick, an unreachable (loopback) main website said plainly, nothing
//      ticked when nothing is set, and the result shown once.
$scnPwCheck = static function () use ($db, $siteOne, $signIn, $http, $freshToken, $pwPost, $pwClean): array {
    $pwClean();
    $out = [];
    $states = static function (string $body): array {
        preg_match_all('/data-check-state="([a-z]+)"/', $body, $m);
        return $m[1];
    };
    $signIn('setup');
    $get = $http('GET', '/admin/public-website/check');
    $out['a GET runs nothing'] = [$get['status'], $states($http('GET', '/admin/public-website')['body'])];
    $http('POST', '/admin/public-website/check', ['csrf_token' => 'not-the-token']);
    $out['no token, nothing run'] = $states($http('GET', '/admin/public-website')['body']);
    $http('POST', '/admin/public-website/check', ['csrf_token' => $freshToken()]);
    $page = $http('GET', '/admin/public-website')['body'];
    $s = $states($page);
    $out['nothing set: no row reads fine'] = in_array('ok', $s, true);
    // #493 P4a: the door exists now, so the row says whether it is on and
    // that this check does not fetch it — still 'untested', never a tick.
    $out['the door row cannot be tested'] = str_contains($page, 'Whether your website really shows it is not tested by this check')
        && in_array('untested', $s, true);
    $out['shown once'] = $states($http('GET', '/admin/public-website')['body']);
    $pwPost('setup', 'website', 'https://127.0.0.1');
    $http('POST', '/admin/public-website/check', ['csrf_token' => $freshToken()]);
    $page = $http('GET', '/admin/public-website')['body'];
    $out['a loopback main website is unreachable'] = str_contains($page, 'https://127.0.0.1: This check did not get a normal page from your main website.');
    $out['the door is still not ticked'] = preg_match('/data-check-state="ok"[^>]*>\s*<span[^>]*>[^<]*<\/span>\s*<strong>The public door/', $page) === 1;
    $pwClean();
    return $out;
};
check('W11 "Check my set-up": a GET and a post without the form token run nothing; with nothing set no row reads "Fine"; the door row says it cannot be tested; the result is shown once; a loopback main website is reported unreachable and the door is never ticked',
    $scnPwCheck(), ['a GET runs nothing' => [302, []], 'no token, nothing run' => [], 'nothing set: no row reads fine' => false,
        'the door row cannot be tested' => true, 'shown once' => [], 'a loopback main website is unreachable' => true, 'the door is still not ticked' => false]);

// W12: the check's limit — six in ten minutes for one person, the seventh refused.
$scnPwCheckLimit = static function () use ($signIn, $http, $freshToken, $pwClean): array {
    $pwClean();
    $signIn('setup');
    $shown = 0;
    for ($i = 0; $i < 7; $i++) {
        $http('POST', '/admin/public-website/check', ['csrf_token' => $freshToken()]);
        if (str_contains($http('GET', '/admin/public-website')['body'], 'data-setup-check="1"') === true) {
            $shown++;
        }
    }
    $pwClean();
    return ['checks that ran' => $shown];
};
check('W12 "Check my set-up" runs six times in ten minutes for one person and refuses the seventh', $scnPwCheckLimit(), ['checks that ran' => 6]);

// W13: D-093 on the Roles page. An ordinary administrator may NOT change which
//      roles open "Set up the public website", nor forget that choice, nor
//      delete a role that opens it — and may still change another permission.
//      The organisation's global administrator may.
$scnPwRolesPage = static function () use ($db, $siteOne, $signIn, $http, $freshToken, $recorded, $people, $pwClean): array {
    $pwClean();
    $out = [];
    $mapped = static function (string $cap) use ($db, $siteOne): ?string {
        $row = $db->query("SELECT GROUP_CONCAT(r.`roleKey` ORDER BY r.`roleKey`) AS `k`, COUNT(m.`mapID`) AS `n` FROM `tblCapabilityMap` m "
            . "LEFT JOIN `tblCapabilityMapRole` r ON r.`mapID` = m.`mapID` WHERE m.`siteID` = {$siteOne} AND m.`capabilityKey` = '{$cap}'")->fetch_assoc();
        return (int) ($row['n'] ?? 0) === 0 ? null : (string) ($row['k'] ?? '');
    };
    $db->query("DELETE FROM `tblRoles` WHERE `siteID` = {$siteOne} AND `roleKey` = 'pwt_custom'");
    $db->query("INSERT INTO `tblRoles` (`siteID`, `roleKey`, `roleName`, `isStandard`) VALUES ({$siteOne}, 'pwt_custom', 'PWT custom', 0)");
    $customId = (int) $db->insert_id;
    $signIn('orgadmin');
    $page = $http('GET', '/admin/roles')['body'];
    $out['page shows the reason to an ordinary administrator'] = str_contains($page, 'Only a global administrator of this organisation');
    $http('POST', '/admin/roles/save', ['csrf_token' => $freshToken(), 'action' => 'capability', 'capability' => 'website.setup', 'roleKeys' => ['website_setup', 'member_role_x']]);
    $http('POST', '/admin/roles/save', ['csrf_token' => $freshToken(), 'action' => 'capability', 'capability' => 'website.setup', 'roleKeys' => ['volunteer']]);
    $out['administrator re-points it'] = $mapped('website.setup');
    $http('POST', '/admin/roles/save', ['csrf_token' => $freshToken(), 'action' => 'capability', 'capability' => 'website.setup', 'confirm_empty' => '1']);
    $out['administrator empties it'] = $mapped('website.setup');
    $http('POST', '/admin/roles/save', ['csrf_token' => $freshToken(), 'action' => 'capability', 'capability' => 'website.publish', 'roleKeys' => ['website_editor', 'volunteer']]);
    $out['administrator still re-points another permission'] = $mapped('website.publish');
    $out['refusals recorded'] = $recorded('PublicWebsiteSetupHandOutRefused', $people['orgadmin']['id'], 'Roles page') >= 2;
    $signIn('siteroot');
    $http('POST', '/admin/roles/save', ['csrf_token' => $freshToken(), 'action' => 'capability', 'capability' => 'website.setup', 'roleKeys' => ['website_setup', 'pwt_custom']]);
    $out['organisation global administrator re-points it'] = $mapped('website.setup');
    $signIn('orgadmin');
    $http('POST', '/admin/roles/save', ['csrf_token' => $freshToken(), 'action' => 'delete', 'roleID' => (string) $customId]);
    $out['administrator deletes a role that opens it'] = (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblRoles` WHERE `roleID` = {$customId}")->fetch_assoc()['n'] ?? 0);
    $http('POST', '/admin/roles/save', ['csrf_token' => $freshToken(), 'action' => 'capability_reset', 'capability' => 'website.setup']);
    $out['administrator forgets the choice'] = $mapped('website.setup');
    $signIn('siteroot');
    $http('POST', '/admin/roles/save', ['csrf_token' => $freshToken(), 'action' => 'capability_reset', 'capability' => 'website.setup']);
    $out['organisation global administrator forgets it'] = $mapped('website.setup');
    $db->query("DELETE FROM `tblRoles` WHERE `roleID` = {$customId}");
    $pwClean();
    return $out;
};
check('W13 (D-093) on the Roles page an ordinary administrator cannot re-point, empty or forget "Set up the public website", nor delete a role that opens it, and is told why and recorded, but can still re-point another permission; the organisation\'s global administrator can',
    $scnPwRolesPage(), ['page shows the reason to an ordinary administrator' => true, 'administrator re-points it' => null, 'administrator empties it' => null,
        'administrator still re-points another permission' => 'volunteer,website_editor', 'refusals recorded' => true,
        'organisation global administrator re-points it' => 'pwt_custom,website_setup', 'administrator deletes a role that opens it' => 1,
        'administrator forgets the choice' => 'pwt_custom,website_setup', 'organisation global administrator forgets it' => null]);

// W14: D-093 on the Users page. An ordinary administrator cannot give or take
//      away Website Set-up, a save that would is refused WHOLE, and saving a
//      holder's OTHER roles (the locked box carried by its hidden field)
//      leaves it alone. The organisation's global administrator can.
$scnPwUsersPage = static function () use ($db, $siteOne, $signIn, $http, $freshToken, $recorded, $people, $setupRole, $pwClean): array {
    $pwClean();
    $out = [];
    $volunteer = (int) ($db->query("SELECT `roleID` FROM `tblRoles` WHERE `siteID` = {$siteOne} AND `roleKey` = 'volunteer'")->fetch_assoc()['roleID'] ?? 0);
    $holds = static function (string $who, int $role) use ($db, $siteOne, $people): bool {
        return (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblUserRoles` WHERE `userID` = {$people[$who]['id']} AND `roleID` = {$role} AND `siteID` = {$siteOne}")->fetch_assoc()['n'] ?? 0) === 1;
    };
    $signIn('orgadmin');
    $page = $http('GET', '/admin/users')['body'];
    $out['locked box drawn for an ordinary administrator'] = str_contains($page, 'data-role-locked="1"') && str_contains($page, 'portal-role-locked-carry');
    $http('POST', '/admin/users/roles/save', ['csrf_token' => $freshToken(), 'userID' => (string) $people['member']['id'], 'roles' => [(string) $setupRole, (string) $volunteer]]);
    $out['administrator gives it (whole save refused)'] = [$holds('member', $setupRole), $holds('member', $volunteer)];
    $http('POST', '/admin/users/roles/save', ['csrf_token' => $freshToken(), 'userID' => (string) $people['setup']['id'], 'roles' => [(string) $volunteer]]);
    $out['administrator takes it away (whole save refused)'] = [$holds('setup', $setupRole), $holds('setup', $volunteer)];
    $http('POST', '/admin/users/roles/save', ['csrf_token' => $freshToken(), 'userID' => (string) $people['setup']['id'], 'roles' => [(string) $setupRole, (string) $volunteer]]);
    $out['administrator changes a holder\'s other roles'] = [$holds('setup', $setupRole), $holds('setup', $volunteer)];
    $out['refusals recorded'] = $recorded('PublicWebsiteSetupHandOutRefused', $people['orgadmin']['id'], 'Users page') >= 2;
    $signIn('siteroot');
    $out['locked box not drawn for the organisation\'s global administrator'] = str_contains($http('GET', '/admin/users')['body'], 'data-role-locked="1"');
    $http('POST', '/admin/users/roles/save', ['csrf_token' => $freshToken(), 'userID' => (string) $people['member']['id'], 'roles' => [(string) $setupRole]]);
    $out['organisation global administrator gives it'] = $holds('member', $setupRole);
    $http('POST', '/admin/users/roles/save', ['csrf_token' => $freshToken(), 'userID' => (string) $people['member']['id'], 'roles' => []]);
    $out['and takes it away'] = $holds('member', $setupRole);
    $http('POST', '/admin/users/roles/save', ['csrf_token' => $freshToken(), 'userID' => (string) $people['setup']['id'], 'roles' => [(string) $setupRole]]);
    $out['holder put back as they were'] = [$holds('setup', $setupRole), $holds('setup', $volunteer)];
    $pwClean();
    return $out;
};
check('W14 (D-093) on the Users page an ordinary administrator cannot give or take away Website Set-up (the whole save refused and recorded) but can change a holder\'s other roles; the organisation\'s global administrator can give and take it',
    $scnPwUsersPage(), ['locked box drawn for an ordinary administrator' => true, 'administrator gives it (whole save refused)' => [false, false],
        'administrator takes it away (whole save refused)' => [true, false], 'administrator changes a holder\'s other roles' => [true, true],
        'refusals recorded' => true, 'locked box not drawn for the organisation\'s global administrator' => false,
        'organisation global administrator gives it' => true, 'and takes it away' => false, 'holder put back as they were' => [true, false]]);

// W15: the menu entry and the admin dashboard's tile follow the same gate.
$scnPwMenu = static function () use ($signIn, $http): array {
    $out = [];
    foreach (['siteroot', 'setup', 'orgadmin', 'member'] as $who) {
        $signIn($who);
        $home = $http('GET', '/')['body'];
        $out[$who] = substr_count($home, '/admin/public-website"');
    }
    $signIn('siteroot');
    $out['tile for the global administrator'] = str_contains($http('GET', '/admin')['body'], 'data-public-website-tile="1"');
    $signIn('orgadmin');
    $out['tile for an ordinary administrator'] = str_contains($http('GET', '/admin')['body'], 'data-public-website-tile="1"');
    return $out;
};
check('W15 the menu links to Admin → Public website for whoever may open it and nobody else, and the admin dashboard\'s tile likewise',
    $scnPwMenu(), ['siteroot' => 1, 'setup' => 1, 'orgadmin' => 0, 'member' => 0, 'tile for the global administrator' => true, 'tile for an ordinary administrator' => false]);

// W16: with nobody holding Website Set-up, the Roles page's warning for it says
//      what is TRUE of this door — global administrators still get in, an
//      ordinary administrator does not — and not the sentence every other door
//      carries ("Administrators of this organisation … still can").
$scnPwRolesWarning = static function () use ($db, $siteOne, $setupRole, $people, $signIn, $http): string {
    $db->query("DELETE FROM `tblUserRoles` WHERE `userID` = {$people['setup']['id']} AND `roleID` = {$setupRole}");
    $signIn('root');
    $page = $http('GET', '/admin/roles')['body'];
    $db->query("INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES ({$people['setup']['id']}, {$setupRole}, {$siteOne})");
    if (preg_match('/<div[^>]*data-no-role-holder="website\.setup"[^>]*>(.*?)<\/div>/s', $page, $m) !== 1) {
        return 'no warning drawn';
    }
    $words = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) ?? '';
    return str_contains($words, 'Global administrators of this organisation, and of the whole installation, still can; an ordinary administrator cannot.') === true
        && str_contains($words, 'Administrators of this organisation, and global administrators, still can') === false ? 'right' : 'WRONG: ' . $words;
};
check('W16 with nobody holding Website Set-up, the Roles page says global administrators still get in and an ordinary administrator does not', $scnPwRolesWarning(), 'right');

// W17 (round 3, simplified): the wording function, asked directly. A 200
//      answered; no curl could not be checked; EVERYTHING else — nothing at
//      all, a 403, a stalled 200, an oversized 404 — is the ONE sentence that
//      is always true, plus the last status where there is one. The sentence
//      is pinned here as text on purpose: it is what a person reads, and it
//      must never name a cause as fact.
$pwNotNormal = 'This check did not get a normal page from your main website. That can happen, for example, when a website is down, its name does not exist or points at a private or internal address, it sends visitors on to another address this check will not follow, it has a security-certificate problem, it turns away automated visitors, or this server is not allowed to make outside connections. Open it in your own browser to see what your visitors get.';
// Round 7: ONLY 403 and 429 — the website answered and turned the check away
// — are "For information" ('note'). Everything else — a 401 (every visitor is
// asked for a password), a 404, a 5xx — and no answer at all, is "Needs
// attention" ('problem'). The two are written out here, not read from the
// class, so a change to the class's list is a change this test notices.
$pwSaid = static fn (int $status): array => [in_array($status, [403, 429], true) === true ? 'note' : 'problem', $pwNotNormal . ($status > 0 ? ' The last answer this check received was HTTP ' . $status . '.' : '')];
$scnPwVerdicts = static function () use ($askSetup, $siteOne): array {
    $out = [];
    foreach ((array) ($askSetup($siteOne)['verdicts'] ?? []) as $name => $one) {
        $out[$name] = [(string) ($one['state'] ?? ''), (string) ($one['words'] ?? '')];
    }
    return $out;
};
$wVerdictsWant = ['nothing' => $pwSaid(0), 'ok' => ['ok', 'it answered.'], 'big page' => ['ok', 'it answered.'],
    'forbidden' => $pwSaid(403), 'too slow' => $pwSaid(200), 'too big' => $pwSaid(404)];
foreach (range(0, 999) as $code) {
    $wVerdictsWant['HTTP ' . $code] = $pwSaid($code);
}
check('W17 the wording function, asked directly, for every status SafeFetch can return (0-999): a 200 answered — including one this check stopped reading at its own size limit; every other answer, a 200 that stalled, and nothing at all get the one sentence plus the status where there is one — "For information" ONLY for 403 and 429',
    $scnPwVerdicts(), $wVerdictsWant);

// W21 (rounds 2 and 3): the words for REAL answers, driven IN-PROCESS. A TLS
//      server of this test's own answers as a.test (and c.test, whose name its
//      certificate does not cover); b.test points at a port nothing listens
//      on. SafeFetch is the real code, in a scratch copy whose only changes
//      are trusting this test's certificate and recording its options.
$tlsDir = $child . $sep . 'tls';
@mkdir($tlsDir, 0700, true);
$tlsCnf = $tlsDir . $sep . 'tls.cnf';
file_put_contents($tlsCnf, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=a.test\n[v3]\nsubjectAltName=DNS:a.test,DNS:b.test\nbasicConstraints=critical,CA:TRUE\n");
$tlsCfg = ['config' => $tlsCnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3'];
$tlsKey = openssl_pkey_new($tlsCfg);
$tlsCsr = $tlsKey === false ? false : openssl_csr_new(['commonName' => 'a.test'], $tlsKey, $tlsCfg);
$tlsCrt = $tlsCsr === false ? false : openssl_csr_sign($tlsCsr, null, $tlsKey, 2, $tlsCfg, random_int(1, 2000000000));
if ($tlsCrt === false || openssl_x509_export_to_file($tlsCrt, $tlsDir . $sep . 'cert.pem') === false
    || openssl_pkey_export_to_file($tlsKey, $tlsDir . $sep . 'key.pem', null, $tlsCfg) === false) {
    refuse('could not make the test certificate for W21 (PHP\'s openssl extension)');
}
file_put_contents($tlsDir . $sep . 'server.php', <<<'TLS'
<?php
// Written by tools/public-website-tick-selftest.php for ONE run. Test only.
declare(strict_types=1);
$ctx = stream_context_create(['ssl' => ['local_cert' => $argv[2], 'local_pk' => $argv[3], 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:' . $argv[1], $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, "could not listen: {$errstr}\n");
    exit(1);
}
while (true) {
    $c = @stream_socket_accept($server, 60);
    if ($c === false) {
        continue; // a client that refused our certificate, or nobody yet
    }
    $req = (string) fgets($c);
    while (($line = fgets($c)) !== false && trim($line) !== '') {
    }
    $path = explode(' ', $req)[1] ?? '/';
    $answers = [
        '/'           => "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok",
        '/e403'       => "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/e404'       => "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/e500'       => "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/e503'       => "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/r-nxdomain' => "HTTP/1.1 301 Moved\r\nLocation: https://no-such-name-p3.invalid/\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/r-port'     => "HTTP/1.1 301 Moved\r\nLocation: https://a.test:8443/\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/r-dead'     => "HTTP/1.1 301 Moved\r\nLocation: https://b.test/\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/r-http'     => "HTTP/1.1 301 Moved\r\nLocation: http://a.test/\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/r-loop'     => "HTTP/1.1 302 Found\r\nLocation: /r-loop\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/r-noloc'    => "HTTP/1.1 300 Multiple Choices\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/drop'       => "HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\nab",
        // Round 3's cases: a two-step chain ending at http:// or at a missing
        // name, a 301 whose body is cut off, a 300 WITH an address, a 304.
        '/r-two-http'    => "HTTP/1.1 301 Moved\r\nLocation: /r-http\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/r-two-missing' => "HTTP/1.1 301 Moved\r\nLocation: /r-nxdomain\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/r-cut'         => "HTTP/1.1 301 Moved\r\nLocation: /\r\nContent-Length: 100\r\nConnection: close\r\n\r\nab",
        '/r-300-loc'     => "HTTP/1.1 300 Multiple Choices\r\nLocation: /\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        '/n304'          => "HTTP/1.1 304 Not Modified\r\nConnection: close\r\n\r\n",
    ];
    @fwrite($c, $answers[$path] ?? $answers['/']);
    @fclose($c);
}
TLS);
$tlsPort = $port + 57;
$deadPort = $port + 58;
$tlsProc = proc_open([PHP_BINARY, $tlsDir . $sep . 'server.php', (string) $tlsPort, $tlsDir . $sep . 'cert.pem', $tlsDir . $sep . 'key.pem'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $tlsDir . $sep . 'server.log', 'a'], 2 => ['file', $tlsDir . $sep . 'server.log', 'a']], $tlsPipes);
register_shutdown_function(static function () use (&$tlsProc): void {
    if (is_resource($tlsProc) === true) {
        proc_terminate($tlsProc);
        proc_close($tlsProc);
    }
});
for ($i = 0; $i < 40; $i++) {
    $probeSocket = @fsockopen('127.0.0.1', $tlsPort, $eno, $estr, 0.25);
    if ($probeSocket !== false) {
        fclose($probeSocket);
        break;
    }
    usleep(150000);
}
// The scratch copy of SafeFetch: the real file, told to trust this test's certificate.
@mkdir($tlsDir . $sep . 'core', 0700, true);
$realSafeFetch = (string) file_get_contents($child . $sep . 'web' . $sep . '_core' . $sep . 'SafeFetch.php');
$trustLine = 'CURLOPT_SSL_VERIFYPEER    => true,';
if (substr_count($realSafeFetch, $trustLine) !== 1) {
    refuse('W21 could not find SafeFetch\'s certificate-checking line to point at the test certificate');
}
// It also RECORDS the options each call is handed, which is how W22 sees what
// the REAL checkSetUp() passes (round 3: a hook replacing the fetch let the
// real path differ from what the test saw).
$recordLine = '$o = self::options($opts);';
if (substr_count($realSafeFetch, $recordLine) !== 1) {
    refuse('W22 could not find the first line of SafeFetch::get() to record its options');
}
file_put_contents($tlsDir . $sep . 'core' . $sep . 'SafeFetch.php', str_replace([$trustLine, $recordLine], [
    $trustLine . ' CURLOPT_CAINFO => ' . var_export($tlsDir . $sep . 'cert.pem', true) . ',',
    '$GLOBALS[\'pwtSafeFetchCalls\'][] = [\'url\' => $url, \'opts\' => $opts]; ' . $recordLine,
], $realSafeFetch));
file_put_contents($child . $sep . 'ask-live.php', <<<'ASK'
<?php
// Written by tools/public-website-tick-selftest.php for ONE run. Test only.
declare(strict_types=1);
// SafeFetch from the scratch copy; every other class (SafeFetch asks Site for
// its user agent) from the copy of web/.
spl_autoload_register(static function (string $class): void {
    $short = str_replace('Portal\\Core\\', '', $class);
    $file  = $short === 'SafeFetch' ? __DIR__ . '/tls/core/SafeFetch.php' : __DIR__ . '/web/_core/' . $short . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});
use Portal\Core\SafeFetch;
$tls  = (int) $argv[1];
$dead = (int) $argv[2];
SafeFetch::$testResolverOverride = [
    'a.test' => ['ips' => ['127.0.0.1'], 'port' => $tls],
    'b.test' => ['ips' => ['127.0.0.1'], 'port' => $dead],
    'c.test' => ['ips' => ['127.0.0.1'], 'port' => $tls],
];
$out = [];
foreach (['ok' => 'https://a.test/', 'forbidden' => 'https://a.test/e403', 'no site here (404)' => 'https://a.test/e404', 'failing (500)' => 'https://a.test/e500',
    'down (503)' => 'https://a.test/e503', 'to a missing name' => 'https://a.test/r-nxdomain',
    'to an unusual port' => 'https://a.test/r-port', 'to a dead address' => 'https://a.test/r-dead', 'to plain http' => 'https://a.test/r-http',
    'round in circles' => 'https://a.test/r-loop', '300 with no address' => 'https://a.test/r-noloc', 'cut off' => 'https://a.test/drop',
    'certificate not for this name' => 'https://c.test/', 'two steps to plain http' => 'https://a.test/r-two-http',
    'two steps to a missing name' => 'https://a.test/r-two-missing', '301 cut off' => 'https://a.test/r-cut',
    '300 with an address' => 'https://a.test/r-300-loc', '304' => 'https://a.test/n304'] as $name => $url) {
    $got = SafeFetch::get($url, \Portal\Core\PublicWebsiteSetup::mainWebsiteFetchOptions());
    $v   = \Portal\Core\PublicWebsiteSetup::mainWebsiteVerdict($got);
    $out[$name] = ['status' => $got['status'], 'state' => $v['state'], 'words' => $v['words']];
}
echo json_encode($out);
ASK);
$scnPwLive = static function () use ($child, $sep, $tlsPort, $deadPort): array {
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('error_log=' . $child . $sep . 'ask-live.log') . ' '
        . escapeshellarg($child . $sep . 'ask-live.php') . ' ' . $tlsPort . ' ' . $deadPort . ' 2>&1');
    $got = json_decode($raw, true);
    if (is_array($got) === false) {
        return ['could not ask' => $raw];
    }
    $out = [];
    foreach ($got as $name => $one) {
        $out[$name] = [(int) $one['status'], (string) $one['state'], (string) $one['words']];
    }
    return $out;
};
// The status each answer leaves is SafeFetch's own (measured): after a
// redirect, a failure further on reports the FAILING step's status — 0 for a
// dead address or a certificate failure — which is exactly why the words name
// no cause.
$wLive = ['ok' => [200, 'ok', 'it answered.']];
foreach (['forbidden' => 403, 'no site here (404)' => 404, 'failing (500)' => 500, 'down (503)' => 503, 'to a missing name' => 301, 'to an unusual port' => 301, 'to a dead address' => 0, 'to plain http' => 301,
    'round in circles' => 302, '300 with no address' => 300, 'cut off' => 200, 'certificate not for this name' => 0,
    'two steps to plain http' => 301, 'two steps to a missing name' => 301, '301 cut off' => 301, '300 with an address' => 300, '304' => 304] as $name => $status) {
    $wLive[$name] = array_merge([$status], $pwSaid($status));
}
check('W21 (in-process, the real SafeFetch and a real TLS server) a complete 200 answered; every other real answer — a 403, a 404, a 500, a 503, a redirect to a missing name, an unusual port, a dead address or plain http, two-step chains, a redirect loop, a 300 with or without an address, a 304, a cut-off 200 or 301, a certificate for another name — gets the one sentence and the last status, and none names a cause',
    $scnPwLive(), $wLive);

// W22 (rounds 2 and 3): the REAL checkSetUp() — no hook — run in-process
//      against the recording copy of SafeFetch, so the options it records are
//      the options the real path uses. Also: a main website at another port is
//      not fetched and says why, and one stored as http://…:443 is checked,
//      because 443 IS the standard https port.
file_put_contents($child . $sep . 'ask-check.php', <<<'ASK'
<?php
// Written by tools/public-website-tick-selftest.php for ONE run. Test only.
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $short = str_replace('Portal\\Core\\', '', $class);
    $file  = $short === 'SafeFetch' ? __DIR__ . '/tls/core/SafeFetch.php' : __DIR__ . '/web/_core/' . $short . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});
define('PORTAL_CORE', __DIR__ . '/web/_core');
$c = require __DIR__ . '/web/_auth_keys/auth_creds.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name'], (int) $c['db_port']);
$mysqli->set_charset('utf8mb4');
$site = (int) $argv[1];
\Portal\Core\Site::init($mysqli, ['site' => ['timezoneKeptAt' => '']], $site);
\Portal\Core\SafeFetch::$testResolverOverride = ['a.test' => ['ips' => ['127.0.0.1'], 'port' => (int) $argv[2]]];
$GLOBALS['pwtSafeFetchCalls'] = [];
$before = microtime(true);
try {
    $rows = \Portal\Core\PublicWebsiteSetup::checkSetUp($mysqli, $site);
} catch (\Throwable $e) {
    echo json_encode(['error' => get_class($e) . ': ' . $e->getMessage()]);
    exit;
}
$calls = $GLOBALS['pwtSafeFetchCalls'];
$first = $calls[0]['opts'] ?? [];
echo json_encode([
    'fetches'        => count($calls),
    'url'            => $calls[0]['url'] ?? null,
    'deadline ahead' => isset($first['deadline']) === true ? (int) round((float) $first['deadline'] - $before) : 'none',
    'step limit'     => $first['timeoutSeconds'] ?? null,
    'redirect limit' => $first['maxRedirects'] ?? null,
    'https only'     => $first['httpsOnly'] ?? null,
    'main rows'      => array_values(array_map(static fn (array $r): string => $r['state'] . ': ' . $r['words'],
        array_filter($rows, static fn (array $r): bool => $r['label'] === 'Your main website'))),
]);
ASK);
$scnPwCheckOptions = static function () use ($db, $siteOne, $child, $sep, $tlsPort, $pwClean): array {
    $ask = static function (string $website) use ($db, $child, $sep, $siteOne, $tlsPort): array {
        $db->query("DELETE FROM `tblSettings` WHERE `siteID` = {$siteOne} AND `settingKey` = 'public.website.address'");
        $st = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, 'public.website.address', ?, '', 0)");
        $st->bind_param('is', $siteOne, $website);
        $st->execute();
        $st->close();
        $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('error_log=' . $child . $sep . 'ask-check.log') . ' '
            . escapeshellarg($child . $sep . 'ask-check.php') . ' ' . $siteOne . ' ' . $tlsPort . ' 2>&1');
        $got = json_decode($raw, true);
        return is_array($got) === true ? $got : ['could not ask' => $raw];
    };
    $pwClean();
    $plain    = $ask('https://a.test');
    $withPort = $ask('https://a.test:8443');
    $http443  = $ask('http://a.test:443');
    $pwClean();
    return [
        'fetched' => [$plain['fetches'] ?? $plain, $plain['url'] ?? null],
        'overall deadline, seconds ahead' => $plain['deadline ahead'] ?? 'none',
        'each step' => $plain['step limit'] ?? null, 'redirects' => $plain['redirect limit'] ?? null, 'https only' => $plain['https only'] ?? null,
        'what it says' => $plain['main rows'] ?? null,
        'at another port' => [$withPort['fetches'] ?? $withPort, $withPort['main rows'] ?? null],
        'http at 443' => [$http443['fetches'] ?? $http443, $http443['url'] ?? null, ($http443['main rows'] ?? [])[0] ?? null],
    ];
};
check('W22 the REAL checkSetUp() hands SafeFetch an overall deadline 15 seconds ahead, 5 seconds a step, at most 3 redirects, https only; a main website at another port is not fetched and says so; one stored as http://…:443 is checked at the standard https port',
    $scnPwCheckOptions(), [
        'fetched' => [1, 'https://a.test/'], 'overall deadline, seconds ahead' => 15, 'each step' => 5, 'redirects' => 3, 'https only' => true,
        'what it says' => ['ok: https://a.test: it answered.'],
        'at another port' => [0, ['untested: https://a.test:8443: it uses port 8443, which is not the standard https port, so this check cannot reach it. Open it in your own browser to see what your visitors get.']],
        'http at 443' => [1, 'https://a.test/', 'ok: http://a.test:443: it answered.']]);

// W23 (round 4): "could not be checked on this server" through the REAL path
//      — the real checkSetUp() in a PHP whose curl_init is switched off
//      (`-d disable_functions=curl_init`), exactly as a host without curl
//      would run it. The verdict has no test parameter any more, so this is
//      the only way to reach that branch, and it is the way the portal does.
$scnPwNoCurl = static function () use ($db, $siteOne, $child, $sep, $tlsPort, $pwClean): array {
    $pwClean();
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES ({$siteOne}, 'public.website.address', 'https://a.test', '', 0)");
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=curl_init -d ' . escapeshellarg('error_log=' . $child . $sep . 'ask-check.log') . ' '
        . escapeshellarg($child . $sep . 'ask-check.php') . ' ' . $siteOne . ' ' . $tlsPort . ' 2>&1');
    $pwClean();
    $got = json_decode($raw, true);
    return is_array($got) === true ? ['what it says' => $got['main rows'] ?? null] : ['could not ask' => $raw];
};
check('W23 on a server without curl (curl_init switched off), the real check says the main website could not be checked on this server',
    $scnPwNoCurl(), ['what it says' => ['untested: https://a.test: it could not be checked on this server, because this server cannot fetch outside addresses (PHP\'s curl extension is not installed or is switched off on this server). Open it in your own browser to see what your visitors get.']]);

// W18 (round 1, item 3): a framing list a HAND EDIT spoiled — what the PAGE
//      shows in its box and what the CHECK reports. Only the valid line may
//      appear, and nothing else may be called valid.
$scnPwHandEdited = static function () use ($db, $siteOne, $signIn, $http, $freshToken, $pwClean): array {
    $pwClean();
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES ({$siteOne}, 'public.frame.origins', "
        . "'https://ok.pwt.example.org\n*\nhttps://x.pwt.example.org; script-src *\nhttp://plain.pwt.example.org', '', 0)");
    $signIn('siteroot');
    $page = $http('GET', '/admin/public-website')['body'];
    $box  = preg_match('#<textarea[^>]*id="pw-origins"[^>]*>(.*?)</textarea>#s', $page, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    $http('POST', '/admin/public-website/check', ['csrf_token' => $freshToken()]);
    $result = $http('GET', '/admin/public-website')['body'];
    preg_match_all('/<strong>Websites allowed to frame your pages:<\/strong>\s*([^<]*)</', $result, $rows);
    $pwClean();
    return [
        'the page\'s box' => $box,
        'what the check says about framing' => array_map(static fn (string $w): string => trim(html_entity_decode($w, ENT_QUOTES, 'UTF-8')), $rows[1]),
        'a spoiled line shown anywhere' => str_contains($page . $result, 'script-src') || str_contains($page . $result, 'plain.pwt.example.org'),
    ];
};
check('W18 with a hand-edited framing list holding "*", a ";" line and an http line, the page\'s box shows only the valid website and the check calls only that one valid',
    $scnPwHandEdited(), ['the page\'s box' => 'https://ok.pwt.example.org',
        'what the check says about framing' => ['https://ok.pwt.example.org is a valid https website and is allowed.'], 'a spoiled line shown anywhere' => false]);

// W19 (round 1, item 4): the INSERT branch of the public address. An
//      organisation with NO address choosing a name another organisation is
//      APPROVED for (D-121, #895: only an approved name is anybody's alone) is
//      refused, the other's row is untouched, and nothing is stored for this
//      one. And a row a hand edit SPOILED (unreadable, so the page shows
//      none) is replaced by an UPDATE, not refused as a clash.
$scnPwHostInsert = static function () use ($db, $siteOne, $siteTwo, $pwPost, $pwHost, $signIn, $http, $pwClean, $pwPutSiteUrlBack): array {
    $pwClean();
    // #493 P4a (D-101): set the portal's own address for the scenario (a public
    // address cannot be saved without it), and put it back at the end.
    $siteUrl = $db->query("SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'site.url' AND `siteID` IS NULL")->fetch_assoc();
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'site.url', 'https://portal.pwt.example.org', '', 0) "
        . "ON DUPLICATE KEY UPDATE `settingValue` = 'https://portal.pwt.example.org'");
    $db->query("INSERT INTO `tblPublicHosts` (`siteID`, `hostName`, `changedAtUtc`, `approvedAtUtc`) VALUES ({$siteTwo}, 'shared.pwt.example.org', '2020-01-01 00:00:00', UTC_TIMESTAMP())");
    $pwPost('setup', 'public_host', 'shared.pwt.example.org');
    $page  = $http('GET', '/admin/public-website')['body'];
    $two   = $db->query("SELECT `hostName`, `changedAtUtc` FROM `tblPublicHosts` WHERE `siteID` = {$siteTwo}")->fetch_assoc();
    $out = [
        'this organisation' => $pwHost($siteOne),
        'the other organisation\'s row' => $two === null ? null : [(string) $two['hostName'], (string) $two['changedAtUtc']],
        'said plainly' => str_contains($page, 'That public address is taken'),
    ];
    // Whatever organisation 1 holds now is replaced by the spoiled row — under
    // plant P91 it already holds a name, and a plain INSERT would stop the run.
    $db->query("DELETE FROM `tblPublicHosts` WHERE `siteID` = {$siteOne}");
    $db->query("INSERT INTO `tblPublicHosts` (`siteID`, `hostName`, `changedAtUtc`) VALUES ({$siteOne}, 'NOT A HOST!', UTC_TIMESTAMP())");
    $signIn('setup');
    $out['a spoiled row is shown as none'] = str_contains($http('GET', '/admin/public-website')['body'], 'NOT A HOST') === false;
    $pwPost('setup', 'public_host', 'events.fresh.pwt.example.org');
    $out['a spoiled row is replaced'] = $pwHost($siteOne);
    $pwPutSiteUrlBack($siteUrl);
    $pwClean();
    return $out;
};
check('W19 an organisation with no public address choosing one another organisation is APPROVED for is refused, leaving the other\'s row untouched and storing nothing; a row a hand edit spoiled is shown as none and replaced',
    $scnPwHostInsert(), ['this organisation' => null, 'the other organisation\'s row' => ['shared.pwt.example.org', '2020-01-01 00:00:00'],
        'said plainly' => true, 'a spoiled row is shown as none' => true, 'a spoiled row is replaced' => 'events.fresh.pwt.example.org']);

// W20 (rounds 1 and 2, item 1): the general settings editor names the RIGHT
//      page for each locked key — the copied-in rule at Admin, Outside
//      calendars; this package's three at Admin, Public website — in its link,
//      in the words it shows when there is no link (who REALLY can change it,
//      per key), in its on-screen refusal, and in what it records when it
//      refuses a change, a delete and an add.
$scnPwEditorPage = static function () use ($db, $siteOne, $signIn, $http, $freshToken, $people, $pwRow, $pwClean): array {
    $pwClean();
    $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES ({$siteOne}, 'public.website.address', 'https://www.pwt-editor.example.org', '', 0)");
    /** What a person sees beside each key on /settings: the linked page, or the words with no link. */
    $beside = static function (string $page, string $key): string {
        // ANCHORED TO THE KEY'S OWN ROW (round 3): each row carries
        // data-setting-key, so the row is the text from that attribute to the
        // next row's; a link further down belongs to somebody else.
        $start = strpos($page, 'data-setting-key="' . $key . '"');
        if ($start === false) {
            return 'no row';
        }
        $next = strpos($page, 'data-setting-key="', $start + 1);
        $row  = $next === false ? substr($page, $start) : substr($page, $start, $next - $start);
        if (preg_match('/<(a|span)\b[^>]*data-locked-page="([a-z\/\-]+)"[^>]*>(.*?)<\/\1>/s', $row, $m) !== 1) {
            return 'nothing drawn';
        }
        return ($m[1] === 'a' ? 'link to ' : 'no link: ') . $m[2] . ' — ' . trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
    };
    $out = [];
    $signIn('root');
    $page = $http('GET', '/settings')['body'];
    $out['installation\'s global administrator'] = [$beside($page, 'public.frame.permitted'), $beside($page, 'public.events.copiedRule'), $beside($page, 'public.website.address')];
    $signIn('siteroot');
    $page = $http('GET', '/settings')['body'];
    $out['organisation\'s global administrator'] = [$beside($page, 'public.frame.permitted'), $beside($page, 'public.website.address')];
    $signIn('orgadmin');
    $page = $http('GET', '/settings')['body'];
    $out['ordinary administrator'] = [$beside($page, 'public.frame.permitted'), $beside($page, 'public.website.address')];

    $signIn('root');
    $id = (int) ($db->query("SELECT `settingID` FROM `tblSettings` WHERE `settingKey` = 'public.frame.permitted' AND `siteID` IS NULL")->fetch_assoc()['settingID'] ?? 0);
    $http('POST', '/settings/save', ['csrf_token' => $freshToken(), 'settingID' => (string) $id, 'settingKey' => 'public.frame.permitted', 'settingValue' => 'no']);
    $out['the refusal on screen'] = str_contains($http('GET', '/settings')['body'], 'This setting is changed only at Admin, Public website, which checks every value and records every change. Nothing was changed.');
    $http('POST', '/settings', ['csrf_token' => $freshToken(), 'action' => 'delete', 'settingID' => (string) $id]);
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'public.website.address' AND `siteID` = {$siteOne}");
    $http('POST', '/settings/save', ['csrf_token' => $freshToken(), 'settingID' => '0', 'settingKey' => 'public.website.address', 'settingValue' => 'https://www.pwt-added.example.org']);
    $logged = $db->query("SELECT `activityType`, `activityDescription` FROM `tblActivityLogs` WHERE `userID` = {$people['root']['id']} "
        . "AND `activityType` IN ('SettingsUpdateRefused', 'SettingsDeleteRefused') AND (`activityDescription` LIKE '%public.frame.permitted%' "
        . "OR `activityDescription` LIKE '%public.website.address%') ORDER BY `logID`")->fetch_all(MYSQLI_ASSOC);
    $out['refusals recorded'] = array_map(static fn (array $r): string => (string) $r['activityType']
        . (str_contains((string) $r['activityDescription'], 'Admin, Public website') === true && str_contains((string) $r['activityDescription'], 'Outside calendars') === false
            ? ' naming Admin, Public website' : ' naming the WRONG page'), $logged);
    $out['nothing changed or added'] = [$pwRow(null, 'public.frame.permitted'),
        (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `settingKey` = 'public.website.address'")->fetch_assoc()['n'] ?? 0)];
    $pwClean();
    return $out;
};
$wSet = 'admin/public-website — ';
check('W20 the settings editor names the right page per key — a link where the viewer can change it, and otherwise who really can (the installation\'s global administrator for the framing switch; a global administrator or a "Set up the public website" holder for the rest) — and its on-screen refusal and its recorded change, delete and add refusals all name Admin, Public website',
    $scnPwEditorPage(), [
        'installation\'s global administrator' => ['link to ' . $wSet . 'Changed at Admin, Public website', 'link to admin/calendar/feeds — Changed at Admin, Outside calendars',
            'link to ' . $wSet . 'Changed at Admin, Public website'],
        'organisation\'s global administrator' => ['no link: ' . $wSet . 'Changed by the installation\'s global administrator, at Admin, Public website',
            'link to ' . $wSet . 'Changed at Admin, Public website'],
        'ordinary administrator' => ['no link: ' . $wSet . 'Changed by the installation\'s global administrator, at Admin, Public website',
            'no link: ' . $wSet . 'Changed by a global administrator, or somebody given "Set up the public website", at Admin, Public website'],
        'the refusal on screen' => true,
        'refusals recorded' => ['SettingsUpdateRefused naming Admin, Public website', 'SettingsDeleteRefused naming Admin, Public website',
            'SettingsUpdateRefused naming Admin, Public website'],
        'nothing changed or added' => ['yes', 0]]);
$pwClean();

// =============================================================================
echo "G. A category or type of another organisation; the website badge; a choice for every date; an approval under the other rule (#885, #853, #854, #855)\n";
// =============================================================================
// ── #885: a category or event type must be THIS organisation's ───────────────
// The events API bound `(int)` of whatever was sent: a number nobody has made
// the database refuse its link (a bare 500), and ANOTHER organisation's
// category was STORED. The Manage Events form and a series' bulk edit did the
// same from a posted number. `Events::whyCategoryOrTypeRefused()` decides now,
// with the SAME words for "another organisation's" and "nobody's".
$db->query("DELETE FROM `tblEventCategories` WHERE `categorySlug` LIKE 'pwt-k-%'");
$db->query("DELETE FROM `tblEventTypes` WHERE `typeSlug` LIKE 'pwt-k-%'");
$db->query("INSERT INTO `tblEventCategories` (`siteID`, `categoryName`, `categorySlug`) VALUES ({$siteOne}, 'PWT K own', 'pwt-k-own')");
$kOwnCat = (int) $db->insert_id;
$db->query("INSERT INTO `tblEventCategories` (`siteID`, `categoryName`, `categorySlug`) VALUES ({$siteTwo}, 'PWT K other', 'pwt-k-other')");
$kOtherCat = (int) $db->insert_id;
$db->query("INSERT INTO `tblEventTypes` (`siteID`, `typeName`, `typeSlug`) VALUES ({$siteTwo}, 'PWT K other type', 'pwt-k-other-type')");
$kOtherType = (int) $db->insert_id;
$kNobodysCat = (int) ($db->query('SELECT MAX(`categoryID`) AS `m` FROM `tblEventCategories`')->fetch_assoc()['m'] ?? 0) + 1000;
$apiWords = static fn (array $r): string => (string) (json_decode($r['body'], true)['message'] ?? '');

$scnApiOtherCategory = static function () use ($api, $keyBase, $evByName, $kOtherCat, $kNobodysCat, $apiWords): array {
    $GLOBALS['db']->query("DELETE FROM `tblEvents` WHERE `eventName` IN ('PWT K nobodys', 'PWT K others')");
    $nobodys = $api('/api/events/create', $keyBase + ['eventName' => 'PWT K nobodys', 'categoryID' => $kNobodysCat]);
    $others  = $api('/api/events/create', $keyBase + ['eventName' => 'PWT K others', 'categoryID' => $kOtherCat]);
    return [
        'no such category'                => [$nobodys['status'], $evByName('PWT K nobodys')],
        'another organisation\'s category' => [$others['status'], $evByName('PWT K others')],
        'the same words for both'         => $apiWords($nobodys) !== '' && $apiWords($nobodys) === $apiWords($others),
    ];
};
check('G1 the events API refuses a category nobody has AND another organisation\'s with 422, creates nothing, and says the same thing for both (#885: a 500, and the other organisation\'s was stored)',
    $scnApiOtherCategory(),
    ['no such category' => [422, null], 'another organisation\'s category' => [422, null], 'the same words for both' => true]);
$r = $api('/api/events/create', $keyBase + ['eventName' => 'PWT K own', 'categoryID' => $kOwnCat]);
$kOwnRow = $db->query("SELECT `categoryID` FROM `tblEvents` WHERE `eventName` = 'PWT K own'")->fetch_assoc();
check('G2 ...and still stores the organisation\'s OWN category (the refusal does not over-reach)', [$r['status'], (int) ($kOwnRow['categoryID'] ?? 0)], [201, $kOwnCat]);
$kOwnId = (int) ($db->query("SELECT `eventID` FROM `tblEvents` WHERE `eventName` = 'PWT K own'")->fetch_assoc()['eventID'] ?? 0);
$scnApiUpdateOtherType = static function () use ($api, $kOwnId, $kOtherType, $db): array {
    $r   = $api('/api/events/update?id=' . $kOwnId, ['eventID' => $kOwnId, 'typeID' => $kOtherType]);
    $bad = $api('/api/events/update?id=' . $kOwnId, ['eventID' => $kOwnId, 'categoryID' => 'abc']);
    $row = $db->query("SELECT `typeID` FROM `tblEvents` WHERE `eventID` = {$kOwnId}")->fetch_assoc();
    return ['another organisation\'s type' => $r['status'], 'not a number' => $bad['status'],
        'stored type' => is_array($row) === true && array_key_exists('typeID', $row) === true ? $row['typeID'] : 'missing'];
};
check('G3 the events API\'s UPDATE refuses another organisation\'s event type (422, nothing stored), and a category that is not a number',
    $scnApiUpdateOtherType(), ['another organisation\'s type' => 422, 'not a number' => 422, 'stored type' => null]);
$scnFormOtherCategory = static function () use ($formPost, $base, $evByName, $kOtherCat): ?array {
    $GLOBALS['db']->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT K form other'");
    $formPost('orgadmin', $base + ['action' => 'create', 'eventName' => 'PWT K form other', 'categoryID' => (string) $kOtherCat]);
    return $evByName('PWT K form other');
};
check('G4 the Manage Events form saves nothing for another organisation\'s category (#885: it was stored)', $scnFormOtherCategory(), null);
// A series' bulk edit: one date of an own series, and another organisation's
// category set on it through the bulk box.
$db->query("DELETE FROM `tblEventSeries` WHERE `seriesSlug` = 'pwt-k-series'");
$db->query("INSERT INTO `tblEventSeries` (`siteID`, `seriesName`, `seriesSlug`) VALUES ({$siteOne}, 'PWT K series', 'pwt-k-series')");
$kSeries = (int) $db->insert_id;
$kSeriesEv = $makeEvent('PWT K series date', $siteOne, '2030-09-01 10:00:00', ['seriesID' => (string) $kSeries]);
$scnBulkOtherCategory = static function () use ($signIn, $http, $tokenOn, $kSeries, $kSeriesEv, $kOtherCat, $db): mixed {
    $db->query("UPDATE `tblEvents` SET `categoryID` = NULL WHERE `eventID` = {$kSeriesEv}");
    $signIn('orgadmin');
    $url   = '/calendar/manage/series-edit?seriesID=' . $kSeries;
    $token = $tokenOn($http('GET', $url)['body']);
    $http('POST', $url, ['csrf_token' => $token, 'seriesID' => (string) $kSeries, 'eventIDs' => [(string) $kSeriesEv], 'bulkCategoryID' => (string) $kOtherCat]);
    $row = $db->query("SELECT `categoryID` FROM `tblEvents` WHERE `eventID` = {$kSeriesEv}")->fetch_assoc();
    return is_array($row) === true && array_key_exists('categoryID', $row) === true ? $row['categoryID'] : 'missing';
};
check('G5 a series\' bulk edit does not set another organisation\'s category on its dates (#885)', $scnBulkOtherCategory(), null);
$scnSubmitOtherCategory = static function () use ($signIn, $http, $tokenOn, $evByName, $kOtherCat): array {
    $GLOBALS['db']->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT K submitted'");
    $signIn('member');
    $token = $tokenOn($http('GET', '/calendar/submit')['body']);
    $r = $http('POST', '/calendar/submit-save', ['csrf_token' => $token, 'eventName' => 'PWT K submitted', 'startDateTime' => '2030-08-02T10:00',
        'categoryID' => (string) $kOtherCat]);
    return ['stored' => $evByName('PWT K submitted'), 'sent back with a reason' => str_contains($r['location'], 'err=category')];
};
check('G6 the public suggestion form stores nothing for another organisation\'s category, and says why (#885)',
    $scnSubmitOtherCategory(), ['stored' => null, 'sent back with a reason' => true]);

// ── #885, the other direction: the organisation's OWN category and type are
// still accepted by every writer. Round 1 pinned only the refusal, so a writer
// that asked the rule with its two arguments swapped (or the category against
// the TYPES table) refused the organisation's own numbers with every check
// green (the independent check planted four). So the organisation gets an own
// TYPE whose number is not one of its category numbers, and an own category
// whose number is not one of its type numbers: a swapped argument then refuses.
// EXPLICIT numbers, far above anything seeded: a loop over insert numbers
// could not promise it (the first run found the own category's number, 9,
// was also one of the organisation's seeded TYPE numbers, so a swapped
// argument still passed and four plants stayed green).
$kOwnCat2 = 970885;
$kOwnType = 980885;
$db->query("DELETE FROM `tblEventCategories` WHERE `categoryID` = {$kOwnCat2}");
$db->query("DELETE FROM `tblEventTypes` WHERE `typeID` = {$kOwnType}");
$db->query("INSERT INTO `tblEventCategories` (`categoryID`, `siteID`, `categoryName`, `categorySlug`) VALUES ({$kOwnCat2}, {$siteOne}, 'PWT K own two', 'pwt-k-own-two')");
$db->query("INSERT INTO `tblEventTypes` (`typeID`, `siteID`, `typeName`, `typeSlug`) VALUES ({$kOwnType}, {$siteOne}, 'PWT K own type', 'pwt-k-own-type')");
$kTypeIsACategory = (int) $db->query("SELECT COUNT(*) AS `n` FROM `tblEventCategories` WHERE `categoryID` = {$kOwnType}")->fetch_assoc()['n'];
$kCatIsAType      = (int) $db->query("SELECT COUNT(*) AS `n` FROM `tblEventTypes` WHERE `typeID` = {$kOwnCat2}")->fetch_assoc()['n'];
check('G11 premise: the organisation\'s own category and own type have numbers that are not each other\'s, so a swapped argument would refuse one of them',
    [$kTypeIsACategory, $kCatIsAType], [0, 0]);
$scnApiCreateOwnBoth = static function () use ($api, $keyBase, $db, $kOwnCat2, $kOwnType): array {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT K own both'");
    $r   = $api('/api/events/create', $keyBase + ['eventName' => 'PWT K own both', 'categoryID' => $kOwnCat2, 'typeID' => (string) $kOwnType]);
    $row = $db->query("SELECT `categoryID`, `typeID` FROM `tblEvents` WHERE `eventName` = 'PWT K own both'")->fetch_assoc();
    return [$r['status'], (int) ($row['categoryID'] ?? 0), (int) ($row['typeID'] ?? 0)];
};
check('G12 the events API creates an event with the organisation\'s own category AND own type (the type sent as text), storing both',
    $scnApiCreateOwnBoth(), [201, $kOwnCat2, $kOwnType]);
$scnApiUpdateOwn = static function () use ($api, $kOwnId, $kOwnCat2, $kOwnType, $db): array {
    $db->query("UPDATE `tblEvents` SET `categoryID` = NULL, `typeID` = NULL WHERE `eventID` = {$kOwnId}");
    $a   = $api('/api/events/update?id=' . $kOwnId, ['eventID' => $kOwnId, 'typeID' => $kOwnType]);
    $b   = $api('/api/events/update?id=' . $kOwnId, ['eventID' => $kOwnId, 'categoryID' => $kOwnCat2]);
    $row = $db->query("SELECT `categoryID`, `typeID` FROM `tblEvents` WHERE `eventID` = {$kOwnId}")->fetch_assoc();
    return [$a['status'], $b['status'], (int) ($row['categoryID'] ?? 0), (int) ($row['typeID'] ?? 0)];
};
check('G13 the events API\'s UPDATE stores the organisation\'s own type, and then its own category',
    $scnApiUpdateOwn(), [200, 200, $kOwnCat2, $kOwnType]);
$scnApiNotANumber = static function () use ($api, $keyBase, $evByName, $apiWords): array {
    $GLOBALS['db']->query("DELETE FROM `tblEvents` WHERE `eventName` IN ('PWT K abc', 'PWT K minus')");
    $abc   = $api('/api/events/create', $keyBase + ['eventName' => 'PWT K abc', 'categoryID' => 'abc']);
    $minus = $api('/api/events/create', $keyBase + ['eventName' => 'PWT K minus', 'typeID' => -5]);
    return [$abc['status'], $evByName('PWT K abc'), $minus['status'], $evByName('PWT K minus'),
        str_contains($apiWords($minus), 'a whole number above 0')];
};
check('G14 the events API refuses a category that is not a number and a type below 1 with 422, creates nothing, and says a number above 0 is wanted',
    $scnApiNotANumber(), [422, null, 422, null, true]);
$scnFormOwnBoth = static function () use ($formPost, $base, $db, $kOwnCat2, $kOwnType): array {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT K form own'");
    $formPost('orgadmin', $base + ['action' => 'create', 'eventName' => 'PWT K form own', 'categoryID' => (string) $kOwnCat2, 'typeID' => (string) $kOwnType]);
    $row = $db->query("SELECT `categoryID`, `typeID` FROM `tblEvents` WHERE `eventName` = 'PWT K form own'")->fetch_assoc();
    return [(int) ($row['categoryID'] ?? 0), (int) ($row['typeID'] ?? 0)];
};
check('G15 the Manage Events form stores the organisation\'s own category and own type', $scnFormOwnBoth(), [$kOwnCat2, $kOwnType]);
$scnBulkOwn = static function () use ($signIn, $http, $tokenOn, $kSeries, $kSeriesEv, $kOwnCat2, $kOwnType, $db): array {
    $db->query("UPDATE `tblEvents` SET `categoryID` = NULL, `typeID` = NULL WHERE `eventID` = {$kSeriesEv}");
    $signIn('orgadmin');
    $url  = '/calendar/manage/series-edit?seriesID=' . $kSeries;
    $post = static fn (array $fields) => $http('POST', $url, ['csrf_token' => $tokenOn($http('GET', $url)['body']), 'seriesID' => (string) $kSeries,
        'eventIDs' => [(string) $kSeriesEv]] + $fields);
    $post(['bulkCategoryID' => (string) $kOwnCat2, 'bulkTypeID' => (string) $kOwnType]);
    $set  = $db->query("SELECT `categoryID`, `typeID` FROM `tblEvents` WHERE `eventID` = {$kSeriesEv}")->fetch_assoc();
    $post(['bulkCategoryID' => '0', 'bulkTypeID' => '0']);
    $none = $db->query("SELECT `categoryID`, `typeID` FROM `tblEvents` WHERE `eventID` = {$kSeriesEv}")->fetch_assoc();
    // `array_key_exists`, never `?? 'missing'`: a stored NULL is the answer wanted.
    $col = static fn (?array $row, string $k): mixed => (is_array($row) === true && array_key_exists($k, $row) === true) ? $row[$k] : 'missing';
    return ['set' => [(int) ($set['categoryID'] ?? 0), (int) ($set['typeID'] ?? 0)], 'None' => [$col($none, 'categoryID'), $col($none, 'typeID')]];
};
check('G16 a series\' bulk edit sets the organisation\'s own category and type on its dates, and "— None —" (0) clears both',
    $scnBulkOwn(), ['set' => [$kOwnCat2, $kOwnType], 'None' => [null, null]]);
$scnSubmitOwn = static function () use ($signIn, $http, $tokenOn, $db, $kOwnCat2): int {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT K submitted own'");
    $signIn('member');
    $token = $tokenOn($http('GET', '/calendar/submit')['body']);
    $http('POST', '/calendar/submit-save', ['csrf_token' => $token, 'eventName' => 'PWT K submitted own', 'startDateTime' => '2030-08-03T10:00',
        'categoryID' => (string) $kOwnCat2]);
    return (int) ($db->query("SELECT `categoryID` FROM `tblEvents` WHERE `eventName` = 'PWT K submitted own'")->fetch_assoc()['categoryID'] ?? 0);
};
check('G17 the public suggestion form stores the organisation\'s own category', $scnSubmitOwn(), $kOwnCat2);

// ── #885 round 3: ANOTHER organisation's event TYPE, for every writer ──────
// Round 2 pinned the other organisation's type on the update API only, so a
// writer that stopped passing the type to the rule (null in its place) left
// every check green. One case per writer, each with an otherwise valid post.
$scnCreateOtherType = static function () use ($api, $keyBase, $evByName, $kOtherType): array {
    $GLOBALS['db']->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT K other type api'");
    $r = $api('/api/events/create', $keyBase + ['eventName' => 'PWT K other type api', 'typeID' => $kOtherType]);
    return [$r['status'], $evByName('PWT K other type api')];
};
check('G18 the events API refuses another organisation\'s event TYPE on create with 422 and creates nothing', $scnCreateOtherType(), [422, null]);
$scnFormOtherType = static function () use ($formPost, $base, $evByName, $kOtherType): ?array {
    $GLOBALS['db']->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT K form other type'");
    $formPost('orgadmin', $base + ['action' => 'create', 'eventName' => 'PWT K form other type', 'typeID' => (string) $kOtherType]);
    return $evByName('PWT K form other type');
};
check('G19 the Manage Events form saves nothing for another organisation\'s event TYPE', $scnFormOtherType(), null);
$scnBulkOtherType = static function () use ($signIn, $http, $tokenOn, $kSeries, $kSeriesEv, $kOtherType, $db): mixed {
    $db->query("UPDATE `tblEvents` SET `typeID` = NULL WHERE `eventID` = {$kSeriesEv}");
    $signIn('orgadmin');
    $url   = '/calendar/manage/series-edit?seriesID=' . $kSeries;
    $token = $tokenOn($http('GET', $url)['body']);
    $http('POST', $url, ['csrf_token' => $token, 'seriesID' => (string) $kSeries, 'eventIDs' => [(string) $kSeriesEv], 'bulkTypeID' => (string) $kOtherType]);
    $row = $db->query("SELECT `typeID` FROM `tblEvents` WHERE `eventID` = {$kSeriesEv}")->fetch_assoc();
    return is_array($row) === true && array_key_exists('typeID', $row) === true ? $row['typeID'] : 'missing';
};
check('G20 a series\' bulk edit does not set another organisation\'s event TYPE on its dates', $scnBulkOtherType(), null);
// The public suggestion form has NO type field and its INSERT names no type
// column, so there is no rule call to plant against; what can be pinned is
// that a posted type of another organisation is not stored.
$scnSubmitOtherType = static function () use ($signIn, $http, $tokenOn, $db, $kOtherType): mixed {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'PWT K submitted other type'");
    $signIn('member');
    $token = $tokenOn($http('GET', '/calendar/submit')['body']);
    $http('POST', '/calendar/submit-save', ['csrf_token' => $token, 'eventName' => 'PWT K submitted other type', 'startDateTime' => '2030-08-04T10:00',
        'typeID' => (string) $kOtherType]);
    $row = $db->query("SELECT `typeID` FROM `tblEvents` WHERE `eventName` = 'PWT K submitted other type'")->fetch_assoc();
    return is_array($row) === true && array_key_exists('typeID', $row) === true ? $row['typeID'] : 'missing';
};
check('G21 the public suggestion form, sent another organisation\'s event TYPE, stores the suggestion with NO type (it has no type field and stores none)',
    $scnSubmitOtherType(), null);

// ── #853: the "On the public website" badge asks the website's own rule ──────
// Under "one box per calendar" the calendar-ticked copied-in event IS on the
// website (V2). Paused, or with its stored answer run out, the website leaves
// it out (R7's door read, and the sitemap) — and the badge used to say "On the
// public website" all the same.
$impRowBadge = static function () use ($signIn, $http, $feedId, $impId): string {
    $signIn('orgadmin');
    $page = $http('GET', '/admin/calendar/feeds/view?id=' . $feedId)['body'];
    $at   = strpos($page, 'id="event-' . $impId . '"');
    if ($at === false) {
        return 'row not drawn';
    }
    $row = substr($page, $at, 4000);
    foreach (['on', 'inert', 'held'] as $kind) {
        if (str_contains($row, 'data-website-badge="' . $kind . '"') === true) {
            return $kind;
        }
    }
    return 'no badge';
};
$scnBadgeFollowsWebsite = static function () use ($db, $setRule, $countdownNext, $impRowBadge, $impId, $feedId): array {
    $setRule('calendar', null);
    $db->query("UPDATE `tblEvents` SET `importSource` = 'calendar', `importWebsite` = 1, `importLevel` = 'public', `importRecheckAt` = NULL WHERE `eventID` = {$impId}");
    $out = ['switched on' => [$countdownNext() === $impId, $impRowBadge()]];
    $db->query("UPDATE `tblExternalFeeds` SET `isActive` = 0 WHERE `feedID` = {$feedId}");
    $out['paused'] = [$countdownNext() === $impId, $impRowBadge()];
    $db->query("UPDATE `tblExternalFeeds` SET `isActive` = 1 WHERE `feedID` = {$feedId}");
    $db->query("UPDATE `tblEvents` SET `importRecheckAt` = '2020-01-01 00:00:00' WHERE `eventID` = {$impId}");
    $out['answer run out'] = [$countdownNext() === $impId, $impRowBadge()];
    $db->query("UPDATE `tblEvents` SET `importRecheckAt` = NULL WHERE `eventID` = {$impId}");
    $setRule('event', null);
    return $out;
};
check('G7 the event\'s website badge says "on" exactly when the website shows it: not while its calendar is paused, not once its stored answer has run out — it says why instead (#853)',
    $scnBadgeFollowsWebsite(),
    ['switched on' => [true, 'on'], 'paused' => [false, 'held'], 'answer run out' => [false, 'held']]);

// ── #854: a NEW choice for every date starts with nothing chosen ─────────────
// A calendar at "Selected groups and people" with nobody on its list (so its
// dates are seen by administrators only), a repeating event of two dates, and
// one date opened to Members by a choice of its own. The choice form for EVERY
// date used to start from the OPENED date's Members, so a Save pressed
// without touching anything made every date of the series Members.
// (A calendar itself cannot be "Only administrators": its column has no such
// value. And not Everyone for the opened date: a new Everyone choice is
// already refused until its website question is answered, so it could not
// show this fault on an untouched Save.)
$db->query("INSERT INTO `tblExternalFeeds` (`siteID`, `name`, `url`, `audienceLevel`, `websiteOptIn`, `isActive`) "
    . "VALUES ({$siteOne}, 'PWT K hidden calendar', 'https://calendar.selftest.invalid/pwt-k.ics', 'groups', 0, 1)");
$kFeed = (int) $db->insert_id;
$kDateA = $makeEvent('PWT K series A', $siteOne, '2030-10-01 10:00:00');
$kDateB = $makeEvent('PWT K series B', $siteOne, '2030-10-08 10:00:00');
foreach ([$kDateA => ['20301001T100000Z', 'members', 'date'], $kDateB => ['20301008T100000Z', 'groups', 'calendar']] as $kId => [$kKey, $kLevel, $kSource]) {
    $db->query("UPDATE `tblEvents` SET `externalFeedID` = {$kFeed}, `externalUidHash` = UNHEX(SHA2('pwt-k-series@selftest', 256)), "
        . "`externalRecurrenceKey` = '{$kKey}', `importLevel` = '{$kLevel}', `importDetail` = 'full', `importWebsite` = 0, `importSource` = '{$kSource}', "
        . "`importRecheckAt` = NULL, `isPublic` = 0 WHERE `eventID` = {$kId}");
}
/** The form exactly as a browser would send it untouched: its hidden fields, and only the radios and boxes the page drew ticked. */
$untouchedPost = static function (string $html): array {
    $fields = [];
    if (preg_match('#<form[^>]*id="choice-form"[^>]*>(.*?)</form>#s', $html, $form) !== 1) {
        return $fields;
    }
    preg_match_all('#<input\b[^>]*>#s', $form[1], $inputs);
    foreach ($inputs[0] as $input) {
        $name  = preg_match('/\bname="([^"]+)"/', $input, $n) === 1 ? html_entity_decode($n[1], ENT_QUOTES, 'UTF-8') : '';
        $value = preg_match('/\bvalue="([^"]*)"/', $input, $v) === 1 ? html_entity_decode($v[1], ENT_QUOTES, 'UTF-8') : '';
        $type  = preg_match('/\btype="([^"]+)"/', $input, $t) === 1 ? $t[1] : 'text';
        if ($name === '' || (in_array($type, ['radio', 'checkbox'], true) === true && preg_match('/\bchecked\b/', $input) !== 1)) {
            continue;
        }
        if (str_ends_with($name, '[]') === true) {
            $fields[substr($name, 0, -2)][] = $value;
        } else {
            $fields[$name] = $value;
        }
    }
    return $fields;
};
$scnSeriesUntouched = static function () use ($db, $signIn, $http, $kDateA, $kDateB, $kFeed, $untouchedPost): array {
    // The opened date's own Members, and the other date's calendar answer, set
    // HERE as well as put back below: a re-work of every calendar (G10, or a
    // plant posting the rule) puts the opened date back to its calendar's
    // answer, since no choice of its own is really stored.
    $db->query("DELETE FROM `tblExternalEventChoices` WHERE `feedID` = {$kFeed}");
    $db->query("UPDATE `tblEvents` SET `importLevel` = 'members', `importDetail` = 'full', `importSource` = 'date' WHERE `eventID` = {$kDateA}");
    $db->query("UPDATE `tblEvents` SET `importLevel` = 'groups', `importSource` = 'calendar' WHERE `eventID` = {$kDateB}");
    $url = '/admin/calendar/feeds/choice?event=' . $kDateA . '&scope=series';
    $signIn('orgadmin');
    $page = $http('GET', $url);
    $chosen = preg_match('/<input[^>]*name="audienceLevel"[^>]*\bchecked\b/s', $page['body']) === 1
        || preg_match('/<input[^>]*name="detailLevel"[^>]*\bchecked\b/s', $page['body']) === 1;
    $post = $untouchedPost($page['body']);
    $r    = $http('POST', $url, $post);
    $out  = [
        'page drawn'          => $page['status'] === 200 && $post !== [],
        'anything pre-chosen' => $chosen,
        'choices saved'       => (int) $db->query("SELECT COUNT(*) AS `n` FROM `tblExternalEventChoices` WHERE `feedID` = {$kFeed}")->fetch_assoc()['n'],
        'the other date'      => (string) $db->query("SELECT `importLevel` FROM `tblEvents` WHERE `eventID` = {$kDateB}")->fetch_assoc()['importLevel'],
        'told to choose'      => $r['status'] === 200 && str_contains($r['body'], 'Choose who can see it.'),
    ];
    $db->query("DELETE FROM `tblExternalEventChoices` WHERE `feedID` = {$kFeed}");
    $db->query("UPDATE `tblEvents` SET `importLevel` = 'members', `importSource` = 'date' WHERE `eventID` = {$kDateA}");
    $db->query("UPDATE `tblEvents` SET `importLevel` = 'groups', `importSource` = 'calendar' WHERE `eventID` = {$kDateB}");
    return $out;
};
check('G8 a NEW choice for every date starts with no audience and no detail chosen, so a Save pressed without touching anything saves nothing and opens no date (#854)',
    $scnSeriesUntouched(),
    ['page drawn' => true, 'anything pre-chosen' => false, 'choices saved' => 0, 'the other date' => 'groups', 'told to choose' => true]);
$scnDateStillPrefilled = static function () use ($signIn, $http, $kDateA): string {
    $signIn('orgadmin');
    $page = $http('GET', '/admin/calendar/feeds/choice?event=' . $kDateA . '&scope=date')['body'];
    return preg_match('/<input[^>]*name="audienceLevel"[^>]*id="choice-level-([a-z]+)"[^>]*\bchecked\b/s', $page, $m) === 1 ? $m[1] : 'none';
};
check('G9 a new choice for ONE date still starts from that date\'s own audience (it is what the date shows now, so an untouched Save cannot widen it)',
    $scnDateStillPrefilled(), 'members');

// ── #855: an approval request made under one copied-in rule ─────────────────
// Under "one box per calendar", a rule ticking the website and opening a
// Members calendar's event to Everyone files a request that COUNTS the
// website. The rule is then tightened to "one tick per event" and — standing
// for a re-work that FAILED for this calendar — nothing is re-worked. An
// administrator approving the old request, from a page describing its
// website box as doing nothing, used to store an approval counting the
// website, reused to publish if the rule was loosened again.
$db->query("INSERT INTO `tblExternalFeeds` (`siteID`, `name`, `url`, `audienceLevel`, `websiteOptIn`, `isActive`) "
    . "VALUES ({$siteOne}, 'PWT K members calendar', 'https://calendar.selftest.invalid/pwt-k2.ics', 'members', 0, 1)");
$kFeed2 = (int) $db->insert_id;
$kEv855 = $makeEvent('PWT K855 open evening', $siteOne, '2030-11-01 19:00:00');
$db->query("UPDATE `tblEvents` SET `externalFeedID` = {$kFeed2}, `externalUidHash` = UNHEX(SHA2('pwt-k855@selftest', 256)), `externalRecurrenceKey` = '', "
    . "`importLevel` = 'members', `importDetail` = 'basic', `importWebsite` = 0, `importSource` = 'calendar', `importRecheckAt` = NULL, `isPublic` = 0 WHERE `eventID` = {$kEv855}");
$db->query("INSERT INTO `tblExternalFeedRules` (`siteID`, `feedID`, `name`, `audienceLevel`, `detailLevel`, `websiteOptIn`, `isActive`, `createdAt`) "
    . "VALUES ({$siteOne}, {$kFeed2}, 'PWT K rule', 'public', 'full', 1, 1, UTC_TIMESTAMP())");
$kRule = (int) $db->insert_id;
$db->query("INSERT INTO `tblExternalRuleConditions` (`ruleID`, `isException`, `matchField`, `matchType`, `matchValue`) VALUES ({$kRule}, 0, 'title', 'contains', 'pwt k855')");
$scnStaleApproval = static function () use ($db, $setRule, $rulePost, $signIn, $http, $tokenOn, $kFeed2, $kEv855): array {
    // Filed under the looser rule: saving the rule re-works every calendar.
    $setRule('event', null);
    $rulePost('root', 'installation', 'calendar');
    $req = $db->query("SELECT `approvalID`, `requestHash`, `contentHash`, `requestedWebsite` FROM `tblExternalEventApprovals` "
        . "WHERE `eventID` = {$kEv855} AND `status` = 'pending' ORDER BY `approvalID` DESC LIMIT 1")->fetch_assoc();
    $out = ['a waiting request counting the website' => (int) ($req['requestedWebsite'] ?? -1)];
    // Tightened, and the re-work "failed": the stored rule changes, nothing else.
    $setRule('event', null);
    $signIn('orgadmin');
    $token = $tokenOn($http('GET', '/admin/calendar/feeds/view?id=' . $kFeed2)['body']);
    $requestHash = (string) ($req['requestHash'] ?? '');
    $contentHash = (string) ($req['contentHash'] ?? '');
    $http('POST', '/admin/calendar/feeds/approvals/act', ['csrf_token' => $token, 'back' => 'feed', 'feed' => (string) $kFeed2, 'decision' => 'approved',
        'approvalID' => (string) ($req['approvalID'] ?? 0), 'requestHash' => $requestHash, 'contentHash' => $contentHash]);
    $out['that request approved'] = (string) ($db->query('SELECT `status` FROM `tblExternalEventApprovals` WHERE `approvalID` = ' . (int) ($req['approvalID'] ?? 0))->fetch_assoc()['status'] ?? 'gone') === 'approved';
    $out['any approval counting the website'] = (int) $db->query("SELECT COUNT(*) AS `n` FROM `tblExternalEventApprovals` WHERE `eventID` = {$kEv855} AND `status` = 'approved' AND `requestedWebsite` = 1")->fetch_assoc()['n'];
    $page = (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($http('GET', '/admin/calendar/feeds/view?id=' . $kFeed2)['body']), ENT_QUOTES, 'UTF-8'));
    $out['told it had changed'] = str_contains($page, 'had changed since you opened the page');
    $db->query("DELETE FROM `tblExternalEventApprovals` WHERE `eventID` = {$kEv855}");
    $db->query("UPDATE `tblEvents` SET `importLevel` = 'members', `importWebsite` = 0, `importSource` = 'calendar', `importSourceID` = NULL WHERE `eventID` = {$kEv855}");
    return $out;
};
check('G10 an approval request made under "one box per calendar", left waiting when the rule was tightened, cannot be approved as if it still counted the website: nothing is approved, and the page says it changed (#855)',
    $scnStaleApproval(),
    ['a waiting request counting the website' => 1, 'that request approved' => false, 'any approval counting the website' => 0, 'told it had changed' => true]);

// =============================================================================
echo "P. The plants: each guard taken out of a COPY, and its scenario goes red\n";
// =============================================================================
$plant = static function (string $relPath, string|array $from, string|array $to, callable $scenario) use ($child, $sep, $startServer, $waitForServer): array {
    // One edit, or several to the same file (a list of `from` and a list of
    // `to`), each of which must match EXACTLY ONCE or nothing is planted.
    $path   = $child . $sep . 'web' . $sep . str_replace('/', $sep, $relPath);
    $before = (string) file_get_contents($path);
    $after  = $before;
    foreach ((array) $from as $i => $one) {
        if (substr_count($after, $one) !== 1) {
            return ['applied' => false, 'result' => null];
        }
        $after = str_replace($one, ((array) $to)[$i], $after);
    }
    file_put_contents($path, $after);
    $startServer();
    $waitForServer();
    $result = $scenario();
    file_put_contents($path, $before);
    $startServer();
    $waitForServer();
    return ['applied' => true, 'result' => $result];
};

$setEv($cEvent, ['showOnWebsite' => '0']);
$p = $plant('_core/EventVisibility.php', "'.isPublic = 1 AND ', \$e, '.showOnWebsite = 1',", "'.isPublic = 1',", $scnUnticked);
check('P1 with the switch dropped from the website rule, the unticked event reaches the door\'s events feed again', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/EventVisibility.php', "' AND (', \$e, '.websiteUntil IS NULL OR ', self::add(\$e . '.websiteUntil >= ?', 's', [\$today]), ') )',", "' )',", $scnUntilYesterday);
check('P2 with the "until" date dropped, an event past its last day is shown', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicWebsite.php', 'return Capabilities::allows($db, $userId, $siteId, Capabilities::WEBSITE_PUBLISH, false);', 'return true;',
    static fn (): bool => ($scnWhoMay()['member'] ?? null) === false);
check('P3 with the permission check gone, a plain member may publish', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicWebsite.php', '&& Site::isOwnOrganisation($userId, $siteId, $db) === true) {', ') {',
    static fn (): bool => ($scnWhoMay()['flagelsewhere'] ?? null) === false && ($scnWhoMay()['flaglapsed'] ?? null) === false);
check('P3b with "belongs to this organisation" gone, the flag-holder from elsewhere (or with membership off) may publish', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicWebsite.php', 'if (self::holdsPortalWideAdminFlag($db, $userId) === true && Site::isOwnOrganisation($userId, $siteId, $db) === true) {',
    'if (false) {', static fn (): bool => ($scnWhoMay()['flagadmin'] ?? null) === true);
check('P3c with the flag ground gone, an administrator made on Admin → Users is falsely refused (round 1\'s finding)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/events/api/create.php', "PublicWebsite::mayPublish(App::db(), (int) (\$websiteActor ?? 0), Site::id())", 'true', $scnKeyCreate);
check('P4 with the permission check gone from the API, a key puts an event on the website', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicWebsite.php', "\$changes = self::changeWords(\$was, \$wanted);\n        if (\$changes === []) {",
    "\$changes = self::changeWords(\$was, \$wanted);\n        if (false) {", $scnKeySameBack);
check('P5 with "only a CHANGE is judged" gone, a key sending the stored values back is refused', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/manage/_event_form.php', "value=\"<?php echo htmlspecialchars(\$websiteUntil, ENT_QUOTES, 'UTF-8'); ?>\"", 'value=""', $scnUntouchedForm);
check('P5b with the form drawing the stored last day as empty, a publisher\'s untouched Save clears it', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/manage/save.php', "if (array_key_exists('websiteFields', \$_POST) === true) {", 'if (true) {', $scnUntouched);
check('P6 with the marker test gone, an untouched Save reads as "switched off"', [$p['applied'], $p['result']], [true, false]);
$p = $plant(
    '_apps/calendar/submit-save.php',
    ["    . ' locationName, categoryID, status, isPublic, '", "    . 'VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, \"draft\", 0, '"],
    ["    . ' locationName, categoryID, status, isPublic, showOnWebsite, '",
        "    . 'VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, \"draft\", 0, ' . (int) (\$_POST['showOnWebsite'] ?? 0) . ', '"],
    $scnSubmission
);
check('P7 with the submission form copying the posted switch, a submission reaches the website switch', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicWebsite.php', "if (\$posted === EventVisibility::COPIED_BY_CALENDAR && self::isLooser(\$installation) === false) {", 'if (false) {', $scnLooserRefused);
check('P8 with the save-time cap gone, an organisation stores the looser rule', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicWebsite.php', "        if (self::isLooser(\$installation) === false) {\n            return EventVisibility::COPIED_BY_EVENT;\n        }", '', $scnReaderCaps);
check('P9 with the READ-time cap gone, the looser organisation row puts the copied-in event on the door\'s events feed', [$p['applied'], $p['result']], [true, false]);
// #493 P4b: the countdown's feed was deleted (D-084); the door's events feed,
// which these checks now read, is planted instead.
$p = $plant('_core/PublicDoor.php', "PublicWebsite::copiedRuleFor(\$db, \$siteId));\n        \$full", "EventVisibility::COPIED_BY_CALENDAR);\n        \$full", $scnEventRule);
check('P10 with the public door no longer passing the organisation\'s rule, "one tick per event" stops applying there', [$p['applied'], $p['result']], [true, false]);
$setEv($cEvent, ['showOnWebsite' => '1']);
$p = $plant('public_html/sitemap.php', 'PublicWebsite::copiedRuleFor($db, $siteId)', 'EventVisibility::COPIED_BY_CALENDAR', $scnSitemapRule);
check('P12 with the sitemap passing the looser rule, it lists the copied-in event under "one tick per event"', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/admin/calendar/feeds-website-rule.php', 'PublicWebsite::saveRuleForOrganisation($mysqli, $siteId, $posted, App::isSiteRootAdmin())',
    'PublicWebsite::saveRuleForOrganisation($mysqli, $siteId, $posted, true)', $scnStricterByAdmin);
check('P13 with the handler\'s "organisation\'s global administrator" gate gone, an ordinary administrator stores the organisation\'s rule', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicWebsite.php', 'return $stored === EventVisibility::COPIED_BY_CALENDAR;', 'return $stored !== EventVisibility::COPIED_BY_EVENT;', $scnGarbageRule);
check('P14 with "anything not exactly \'event\' is looser", an unreadable rule lets the copied-in event onto the door\'s events feed', [$p['applied'], $p['result']], [true, false]);
// #853: the badge asks the website's own rule (`onWebsite`) now, so "no
// longer asking the rule" is the badge on the website box alone.
$p = $plant('_apps/admin/calendar/feeds-view.php',
    "<?php if ((int) \$ev['onWebsite'] === 1): ?>",
    "<?php if ((int) \$ev['importWebsite'] === 1): ?>",
    static fn (): bool => ($scnWordsStrict()['event badge on'] ?? null) === false);
check('P15 with the event badge no longer asking the rule, it claims the website under "one tick per event"', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/FeedAdmin.php', "\$label .= EventVisibility::copiedTickCounts('calendar', \$copiedRule) === true", "\$label .= true",
    static fn (): bool => ($scnWordsStrict()['label says on'] ?? null) === false);
check('P16 with the calendar label no longer asking the rule, it says "also on the public website" under "one tick per event"', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/admin/calendar/feeds-view.php',
    "<?php if ((int) \$rule['websiteOptIn'] === 1 && EventVisibility::copiedTickCounts('rule', \$copiedRule) === true): ?>",
    "<?php if ((int) \$rule['websiteOptIn'] === 1): ?>",
    static fn (): bool => ($scnWordsStrict()['rule badge on'] ?? null) === false);
check('P17 with the rule badge no longer asking the rule, it claims the website under "one tick per event"', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/admin/calendar/_approval-rows.php',
    "? (EventVisibility::copiedTickCounts((string) \$row['origin'] === 'choice' ? 'date' : 'rule', \$copiedRule) === true",
    '? (true',
    static fn (): bool => ($scnWordsStrict()['rule approval on'] ?? null) === false);
check('P18 with the approval row no longer asking the rule, a rule\'s request promises the website under "one tick per event"', [$p['applied'], $p['result']], [true, false]);
// Since D-089 the page asks the website rule itself, so "no longer requiring
// the rule" means handing it the looser rule whatever the organisation chose
// (before D-089 this plant put back the old line, the markup on
// `importWebsite` alone, which no longer exists).
$p = $plant('_apps/calendar/event.php',
    "\$websiteRule = EventVisibility::where('e', EventVisibility::MODE_WEBSITE, 0, \$websiteToday, PublicWebsite::copiedRuleFor(\$mysqli, \$siteId));",
    "\$websiteRule = EventVisibility::where('e', EventVisibility::MODE_WEBSITE, 0, \$websiteToday, EventVisibility::COPIED_BY_CALENDAR);",
    static fn (): bool => ($scnMarkupRule()['calendar box, stricter rule'][1] ?? null) === false);
check('P19 with the event page no longer requiring the rule, the calendar box puts the markup out under "one tick per event"', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/admin/roles/index.php', '<?php $capFallsOpen = Capabilities::fallsOpenForGlobalAdmin($capKey); ?>', '<?php $capFallsOpen = true; ?>',
    static fn (): bool => ($scnRolesPage()['erasure.review'] ?? null) === 'right');
check('P20 with the warning no longer asking the registry, the erasure review says a global administrator still gets in', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicWebsite.php', "'SELECT 1 FROM `tblUsers` WHERE `userID` = ? AND `isActive` = 1 AND `isAdmin` = 1'", "'SELECT 1 FROM `tblUsers` WHERE `userID` = ? AND `isAdmin` = 1'",
    static fn (): bool => ($scnWhoMay()['flaginactive'] ?? null) === false);
check('P21 (measured, expected to stay GREEN) with "active account" dropped from the flag test alone, the switched-off account is STILL refused — Site::isOwnOrganisation() asks for an active account too',
    [$p['applied'], $p['result']], [true, true]);
$p = $plant('_apps/admin/calendar/feeds-edit.php', "EventVisibility::copiedTickCounts('calendar', \\Portal\\Core\\PublicWebsite::copiedRuleFor(\$mysqli, \$siteId)) === false",
    'false', static fn (): bool => $scnInert() === [true, false]);
check('P22 with the calendar settings page no longer asking the rule, it stops saying the box does nothing', [$p['applied'], $p['result']], [true, false]);
// Section D's three readers, each made to take "today" from UTC instead of the
// organisation's calendar. Each scenario answers true only when the event
// dated to the organisation's today IS shown and the one dated to UTC's is NOT.
$scnWhoseTodayIn = static function (string $reader) use ($scnWhoseToday): mixed {
    $r = $scnWhoseToday();
    return is_array($r) === true ? ($r['org'][$reader] === true && $r['utc'][$reader] === false) : $r;
};
$p = $plant('_core/PublicDoor.php', "return (new \\DateTimeImmutable('now', new \\DateTimeZone(Site::timezone(\$siteId))))->format('Y-m-d');",
    "return gmdate('Y-m-d');", static fn (): mixed => $scnWhoseTodayIn('countdown'));
check('P23 with the public door taking "today" from UTC, the dates are judged on the wrong calendar', [$p['applied'], $p['result']], [true, false]);
$p = $plant('public_html/sitemap.php', "\$today      = date('Y-m-d');", "\$today      = gmdate('Y-m-d');",
    static fn (): mixed => $scnWhoseTodayIn('sitemap'));
check('P24 with the sitemap taking "today" from UTC, the dates are judged on the wrong calendar', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/event.php', "\$websiteToday = date('Y-m-d');", "\$websiteToday = gmdate('Y-m-d');",
    static fn (): mixed => $scnWhoseTodayIn('page'));
check('P38 with the event page taking "today" from UTC, its markup follows the website dates on the wrong calendar (D-089)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/PublicWebsite.php', 'public static function mayPublish(\mysqli $db, int $userId, int $siteId): bool',
    'public static function mayPublish(\mysqli $db, int $userId, int $siteId): bool { return false; }' . "\n"
    . '    private static function mayPublishAsShipped(\mysqli $db, int $userId, int $siteId): bool', $scnFormRefuses);
check('P25 (a planted world) with the permission saying no to everybody, the form draws the state as words and its handler REFUSES and records a hand-made change',
    [$p['applied'], $p['result']], [true, ['drawnAsWords' => true, 'unchanged' => true, 'recorded' => true]]);
$p = $plant('_apps/calendar/manage/save.php',
    "\$websiteArrived['showOnWebsite'] = array_key_exists('showOnWebsite', \$_POST) === true ? \$_POST['showOnWebsite'] : '0';",
    "\$websiteArrived['showOnWebsite'] = isset(\$_POST['showOnWebsite']) === true ? '1' : '0';", $scnZeroIsOff);
check('P26 with the box read by PRESENCE again (round 3\'s line), a posted "0" is stored ON', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/admin/calendar/feeds-choice.php',
    "} elseif (\$choiceNow === null && \$post['audienceLevel'] === 'public' && \$websiteAnswered === false) {",
    "} elseif (false) {", $scnNewChoiceAnswer);
check('P27 with the choice form\'s refusal taken out, an Everyone choice that never answered the website question is saved',
    [$p['applied'], $p['result']['saved without an answer'] ?? null], [true, 1]);
$p = $plant('_apps/admin/calendar/feeds-choice.php',
    "\$typed   = ['websiteOptIn' => \$websiteRedraw] + \$post;",
    "\$typed   = \$post;", $scnRedrawUnanswered);
check('P30 with the redraw taken from the tidied post again (round 10\'s fault), the question comes back with "No" chosen',
    [$p['applied'], $p['result']['question'] ?? null], [true, 'no']);
// P31 takes out BOTH asks (round 12): the page's own, and the one it hands to
// FeedAdmin::saveChoice() under the lock ('mustBeNew'). With only the page's
// taken out the lock's ask still keeps the Yes — measured, P31 went red for
// that reason — and that layer alone is pinned in tools/feed-admin-selftest.php
// (W30, called directly), since one worker can never race two saves.
$p = $plant('_apps/admin/calendar/feeds-choice.php',
    ["if (\$drawnAsNew === true && \$choiceNow !== null) {", "['mustBeNew' => \$drawnAsNew] + \$post"],
    ["if (false) {", "\$post"], $scnRaceKeepsTheirs);
check('P31 with "drawn as new" ignored again, the stale page writes "No" over the other administrator\'s "Yes"',
    [$p['applied'], $p['result']['their answer kept'] ?? null], [true, 0]);
// P32 takes out the page's first ask AND its ask when the page is drawn
// again (round 13): the post is refused for "no group ticked", and the page
// comes back as the newer choice's form with nothing said. P34 takes out only
// the first ask — which is where a real race leaves it, the newer choice being
// saved after that ask ran — and the ask at drawing time still catches it.
$p = $plant('_apps/admin/calendar/feeds-choice.php',
    ["if (\$drawnAsNew === true && \$choiceNow !== null) {", "if (\$typed !== null && \$existing !== null && (\$drawnAsNew ?? false) === true) {"],
    ["if (false) {", "if (false) {"], $scnRaceAfterRefusal);
check('P32 with "drawn as new" ignored again, the stale page with a second fault is refused for the wrong reason and is not told about the newer choice',
    [$p['applied'], $p['result']['told'] ?? null], [true, false]);
$p = $plant('_apps/admin/calendar/feeds-choice.php',
    "if (\$drawnAsNew === true && \$choiceNow !== null) {",
    "if (false) {", $scnRaceAfterRefusal);
check('P34 with only the first ask taken out (where a race leaves it), the ask at drawing time still says a choice appeared, keeps it, and draws THAT choice (round 13)',
    [$p['applied'], $p['result']['told'] ?? null, $p['result']['their answer kept'] ?? null, $p['result']['audience shown'] ?? null, $p['result']['box'] ?? null],
    [true, true, 1, 'public', 'ticked']);
// D-089: the event page's markup decision put back to "Public alone" for the
// portal's own events — the box-off reading then carries markup again.
// #968 round 2: the page now names its columns and no longer selects
// `isPublic`, which nothing on it reads since D-089. The old decision READ it,
// so putting the old decision back means putting the column back in the list
// too — without that the planted line reads null, never sends markup, and this
// plant cannot do what it exists to do (measured: P37 went red on the correct page).
$p = $plant('_apps/calendar/event.php',
    ["\$jsonLdAllowed = \$onWebsite->get_result()->fetch_row() !== null && (\$isImported === false || \$canSeeFull === true);",
     "'`e`.`status`, `e`.`isFeatured`, "],
    ["\$jsonLdAllowed = (\$isImported === false) ? ((\$event['isPublic'] ?? null) === 1 || (\$event['isPublic'] ?? null) === '1') : (\$onWebsite->get_result()->fetch_row() !== null && \$canSeeFull === true);",
     "'`e`.`status`, `e`.`isPublic`, `e`.`isFeatured`, "],
    $scnOwnMarkup);
check('P37 with an own event\'s markup following "Public" alone again (before D-089), the page sends markup with the website box off',
    [$p['applied'], $p['result']['Public, box off'] ?? null], [true, [200, true]]);
// D-079: each way a new event is made, put back to "public unless told otherwise".
$p = $plant('_apps/calendar/manage/_event_form.php',
    "<?php echo (isset(\$ev['isPublic']) === true && ((string) \$ev['isPublic'] === '1')) ? 'checked' : ''; ?>",
    "<?php echo (isset(\$ev['isPublic']) === false || (string) \$ev['isPublic'] === '1') ? 'checked' : ''; ?>", $scnFormBox);
check('P39 with the form ticking "Public" when the value is missing again, a new event starts public', [$p['applied'], $p['result']], [true, true]);
$p = $plant('_apps/events/api/create.php',
    "\$isPublic     = isset(\$body['isPublic']) === true && (bool) \$body['isPublic'] === true ? 1 : 0;",
    "\$isPublic     = isset(\$body['isPublic']) === false || (bool) \$body['isPublic'] === true ? 1 : 0;", $scnApiDefault);
check('P40 with the API treating a missing isPublic as true again, the new event is public', [$p['applied'], $p['result'][1] ?? null], [true, 1]);
$p = $plant('_apps/calendar/manage/import.php',
    "'isPublic'      => in_array(\$isPubStr,  ['1', 'true', 'yes', 'y'], true) ? 1 : 0,",
    "'isPublic'      => in_array(\$isPubStr,  ['', '1', 'true', 'yes', 'y'], true) ? 1 : 0,", $scnImportDefault);
check('P41 with the CSV import treating an empty cell as public again, the imported event is public', [$p['applied'], $p['result']], [true, 1]);
$p = $plant('_apps/admin/calendar/moderate.php', "'UPDATE `tblEvents` SET `status` = \"published\", '", "'UPDATE `tblEvents` SET `status` = \"published\", `isPublic` = 1, '", $scnApprove);
check('P42 with Approve setting isPublic = 1 again, an approved suggestion is public with nobody choosing it', [$p['applied'], $p['result'][2] ?? null], [true, 1]);
// #859 (D-134): the approval e-mail. Each plant takes one guard out of the
// COPY and re-runs the section E scenario that should catch it. The send log
// is what they read: the words-watching line in the copy's Mailer was taken
// out at the end of section E, so 'handed to Mailer' is not asked here.
$p = $plant('_apps/admin/calendar/moderate.php',
    'EventSuggestions::tellSuggesterApproved($mysqli, $eventId, $siteId)',
    'EventSuggestions::TOLD_NO_ADDRESS', static fn (): array => $scnSuggestion('PWT E approved', 'pat.suggester@selftest.invalid', ['approve']));
check('P120 with the send taken out of Approve, the suggestion is approved and NOTHING is in the send log (E2 goes red)',
    [$p['applied'], $p['result']['state'][0] ?? null, $p['result']['send log rows'] ?? null], [true, 'approved', 0]);
$p = $plant('_apps/admin/calendar/moderate.php', 'if ($affected > 0) {', 'if (true) {', $scnTwice);
check('P121 with the send no longer limited to the request that CHANGED the row, approving twice sends TWO e-mails (E7 goes red)',
    [$p['applied'], $p['result']['send log rows'] ?? null], [true, 2]);
$p = $plant('_core/EventSuggestions.php',
    "        } catch (\\Throwable \$e) {\n            Logger::errorPlatform('Mailer', 'Warning', 'SUGGESTION_MAIL_FAIL'",
    "        } catch (\\DivisionByZeroError \$e) {\n            Logger::errorPlatform('Mailer', 'Warning', 'SUGGESTION_MAIL_FAIL'", $scnBroken);
check('P122 with the send\'s own catch narrowed so a throwing route escapes, the approval is still stored (it came first) but the page is no longer sent back to the queue (E12 goes red)',
    [$p['applied'], $p['result']['state'][0] ?? null, $p['result']['went back'] ?? null], [true, 'approved', [false]]);
$p = $plant('_core/EventSuggestions.php',
    "if (\$row === null || \$address === '' || filter_var(\$address, FILTER_VALIDATE_EMAIL) === false) {",
    "if (\$row === null || \$address === '') {", static fn (): array => $scnNoAddress('not-an-address'));
check('P123 with the address no longer checked, something that is not an address is handed to the mail route (E10 goes red)',
    [$p['applied'], $p['result'][3] ?? null], [true, 1]);
// #859 round 2: each finding of round 1's check, planted back in the copy.
$p = $plant('_core/EventSuggestions.php', ", ['siteId' => \$siteId, 'bodyIsText' => true]);", ", ['siteId' => \$siteId]);", $scnLinkName);
check('P124 with the e-mail no longer marked as plain text, a stranger\'s link in their name goes out as a WORKING link with no footer (E15 goes red)',
    [$p['applied'], str_contains((string) (($p['result']['first message'] ?? [])['html'] ?? ''), '<a href="https://evil.example/login">')], [true, true]);
$p = $plant('_core/Mailer.php', "\$contentHtml = (\$contentIsText === false && preg_match('/<[a-zA-Z][^>]*>/', \$content) === 1)",
    "\$contentHtml = (preg_match('/<[a-zA-Z][^>]*>/', \$content) === 1)", $scnLinkName);
check('P125 with the branded wrapper still guessing (only send() told), the link is still sent live: BOTH halves of the option are needed',
    [$p['applied'], str_contains((string) (($p['result']['first message'] ?? [])['html'] ?? ''), '<a href="https://evil.example/login">')], [true, true]);
$p = $plant('_apps/admin/calendar/moderate.php',
    "        . 'WHERE `eventID` = ? AND `siteID` = ? AND `submissionStatus` = \"pending\" AND `isDeleted` = 0'\n    );\n} else {",
    "        . 'WHERE `eventID` = ? AND `siteID` = ? AND `submissionStatus` = \"pending\"'\n    );\n} else {", $scnDeleted);
check('P126 with Approve no longer refusing a deleted suggestion, pressing it publishes a row nobody can see (E18 goes red)',
    [$p['applied'], $p['result']['state'] ?? null], [true, ['approved', 'published']]);
$p = $plant('_apps/admin/calendar/moderation.php', "AND `e`.`submissionStatus` = ? AND `e`.`isDeleted` = 0 '", "AND `e`.`submissionStatus` = ? '", $scnDeleted);
check('P127 with the moderation list no longer leaving deleted suggestions out, one is listed with live buttons (E18 goes red)',
    [$p['applied'], $p['result']['listed'] ?? null], [true, true]);
$whenLine = "\$when = I18n::formatClockDateTime(\$startReading, \$isAllDay === true ? 'l j F Y' : 'l j F Y \\a\\t H:i');";
$p = $plant('_core/EventSuggestions.php', $whenLine,
    "\$when = (new \\DateTimeImmutable(\$startReading, new \\DateTimeZone('UTC')))->setTimezone(new \\DateTimeZone(Site::timezone()))->format(\$isAllDay === true ? 'l j F Y' : 'l j F Y \\a\\t H:i');", $scnNewYork);
check('P128 with the time converted to the organisation\'s clock, the New York event is told the wrong hour (E21 goes red)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/EventSuggestions.php', $whenLine,
    "\$when = date(\$isAllDay === true ? 'l j F Y' : 'l j F Y \\a\\t H:i', (int) strtotime(\$startReading));", $scnGap);
check('P129 with the reading put through strtotime() and date(), the event in the hour that does not exist is told 02:30 (E22 goes red)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/EventSuggestions.php', '(Mailer::lastIsWaiting() === true ? self::TOLD_WAITING : self::TOLD_SENT)', 'self::TOLD_SENT', $scnKept);
check('P130 with a kept message counted as sent, the page tells the administrator a message still in the queue "has been e-mailed" (E19 goes red)',
    [$p['applied'], $p['result']['page said'] ?? null], [true, ['Approved. The person who suggested it has been e-mailed to say so.']]);
// The words-watching line comes out of the copy's Mailer now, and every mail
// row goes back as it was.
file_put_contents($mailerPath, $mailerWas);
$putMailBack();
// #493 P2a: the poster rule, the page's permission, and the editor's save.
$p = $plant('_core/PublicWebsite.php', "'sql'    => \" AND {\$a}.isDeleted = 0 AND {\$a}.showOnWebsite = 1\"", "'sql'    => \" AND {\$a}.isDeleted = 0\"", $scnPosterRule);
check('P43 with the switch dropped from the poster rule, an unticked poster reaches the public website', [$p['applied'], in_array('PWT Q off', (array) $p['result'], true)], [true, true]);
$p = $plant('_apps/noticeboard/website.php',
    "\$judged = PublicWebsite::judgePosterFields(\$arrived, \$row, PublicWebsite::mayPublish(\$mysqli, \$userId, \$siteId));",
    "\$judged = PublicWebsite::judgePosterFields(\$arrived, \$row, true);", $scnPosterRefused);
check('P44 with the page no longer asking the permission when it saves, a member\'s posted change is stored', [$p['applied'], $p['result']['stored'] ?? null], [true, 1]);
$db->query("UPDATE `tblNoticeboardPosters` SET `showOnWebsite` = 0 WHERE `posterID` = {$qOff}");
$p = $plant('_apps/noticeboard/api/save.php', "'sortOrder=VALUES(sortOrder), updatedByID=VALUES(updatedByID), isDeleted=0'",
    "'sortOrder=VALUES(sortOrder), updatedByID=VALUES(updatedByID), isDeleted=0, showOnWebsite=0'", $scnEditorKeeps);
check('P45 with the editor\'s save writing the switch, a ticked poster is taken off by an unrelated save', [$p['applied'], $p['result']['ticked poster kept on'] ?? null], [true, 0]);
$db->query("UPDATE `tblNoticeboardPosters` SET `showOnWebsite` = 1 WHERE `posterID` = {$qOn}");
$p = $plant('_apps/noticeboard/api/save.php',
    ["\"UPDATE tblNoticeboardPosters SET isDeleted = 1, showOnWebsite = 0 WHERE siteID = ? AND posterID NOT IN (\$in)\""],
    ["\"UPDATE tblNoticeboardPosters SET isDeleted = 1 WHERE siteID = ? AND posterID NOT IN (\$in)\""], $scnDeleteRevive);
check('P46 with deleting no longer taking a poster off the website, a revived poster comes back ON with nobody ticking it', [$p['applied'], $p['result']['still off the website'] ?? null], [true, 1]);
// Round 3: deleting the LAST poster on the board (an empty board saved) runs
// the soft delete's OTHER branch, which must clear the switch too. Run last,
// because it empties organisation one's board and the plants above need it.
$scnLastPoster = static function () use ($signIn, $http, $tokenOn, $api, $posterRow, $poster, $siteOne, $db): array {
    $db->query("UPDATE `tblNoticeboardPosters` SET `isDeleted` = 1 WHERE `siteID` = {$siteOne}");
    $last = $poster('PWT Q last poster', $siteOne, ['showOnWebsite' => '1']);
    $signIn('orgadmin');
    $api('/api/noticeboard/save', ['posters' => []], $tokenOn($http('GET', '/noticeboard')['body']));
    $row = $posterRow($last);
    return ['deleted' => (int) ($row['isDeleted'] ?? -1), 'off' => (int) ($row['showOnWebsite'] ?? -1)];
};
check('Q7b deleting the LAST poster on the board (the soft delete\'s other branch) takes it off the website too',
    $scnLastPoster(), ['deleted' => 1, 'off' => 0]);
$p = $plant('_apps/noticeboard/api/save.php',
    "'UPDATE tblNoticeboardPosters SET isDeleted = 1, showOnWebsite = 0 WHERE siteID = ?'",
    "'UPDATE tblNoticeboardPosters SET isDeleted = 1 WHERE siteID = ?'", $scnLastPoster);
check('P47 with that branch no longer clearing the switch, the last poster deleted keeps it', [$p['applied'], $p['result']['off'] ?? null], [true, 1]);
// Section Q's posters go, organisation two's included (its foreign key would
// otherwise stop the organisation being removed at the end, round 1).
$db->query("DELETE FROM `tblNoticeboardPosters` WHERE `title` LIKE 'PWT Q %'");
// Round 15 (a hardening it suggested): only the page's FIRST ask taken out,
// with the plain stale post — the hand-off ('mustBeNew') must then stop the
// save under the lock and keep the other administrator's Yes. Round 12
// measured this; P31 now takes out both, so without P36 the hand-off was
// pinned only by P31's text matching.
$p = $plant('_apps/admin/calendar/feeds-choice.php',
    "if (\$drawnAsNew === true && \$choiceNow !== null) {",
    "if (false) {", $scnRaceKeepsTheirs);
check('P36 with only the page\'s first ask taken out, the save is refused under the lock and the other administrator\'s "Yes" is kept (the mustBeNew hand-off)',
    [$p['applied'], $p['result']['their answer kept'] ?? null, $p['result']['told'] ?? null], [true, 1, true]);
// Round 14: the draw-time ask's message without its `$typed = null` — the
// page says a choice appeared and then draws what was typed as that choice's
// form, so the next Save would write over it. The AUDIENCE is what shows it
// here: the typed "groups" instead of the saved choice's "Everyone". (In a
// real race the box would come back unticked too; under this plant the choice
// already exists when the handler first reads it, so round 11's redraw rule
// rightly takes the box from the stored Yes — measured: 'ticked'.)
$p = $plant('_apps/admin/calendar/feeds-choice.php',
    ["if (\$drawnAsNew === true && \$choiceNow !== null) {", "    \$refusal = FeedAdmin::CHOICE_APPEARED;\n    \$typed   = null;\n}"],
    ["if (false) {", "    \$refusal = FeedAdmin::CHOICE_APPEARED;\n}"], $scnRaceAfterRefusal);
check('P35 with the draw-time ask keeping what was typed (round 14\'s fault), the page draws the typed audience as the saved choice',
    [$p['applied'], $p['result']['audience shown'] ?? null], [true, 'groups']);
$p = $plant('_apps/admin/calendar/feeds-choice.php',
    "\$websiteRedraw = ((int) \$choiceNow['websiteOptIn'] === 1) ? '1' : '0';",
    "\$websiteRedraw = '';", $scnExistingRedraw);
check('P33 with an unsent answer redrawn as nothing again (round 11\'s fault), the saved choice\'s ticked box comes back unticked',
    [$p['applied'], $p['result']['box'] ?? null], [true, 'unticked']);
$p = $plant('_apps/admin/calendar/feeds-website-rule.php',
    "\$reworked = FeedAdmin::reworkCalendars(\$mysqli, \$action === 'installation' ? null : \$siteId);",
    "\$reworked = ['done' => 0, 'failed' => 0];", $scnRuleSaveReworks);
check('P28 with the re-work taken out of the rule\'s save, the copied-in event keeps its old stored answer', [$p['applied'], $p['result']['source'] ?? null], [true, 'waiting']);
$p = $plant('_apps/admin/calendar/feeds-website-rule.php',
    "\$reworkAfter = \$outcome['saved'] === true;",
    "\$reworkAfter = \$outcome['saved'] === true && \$outcome['change'] !== '';", $scnSameSaveReworks);
check('P29 with the re-work run only when the rule CHANGED (round 6\'s condition), saving the same rule again re-works nothing', [$p['applied'], $p['result']['source'] ?? null], [true, 'waiting']);
// #493 P2b: the foyer screen's guards, each taken out of the copy.
$p = $plant('_core/NoticeboardFoyer.php', "if (\$shows === 'all') {", 'if (true) {', $scnFoyerPublic);
check('P48 with the foyer screen always taking the "every poster" branch, it shows an unticked poster by default', [$p['applied'], $p['result'] === ['PWT S on']], [true, false]);
$p = $plant('_core/NoticeboardFoyer.php', "if (self::permitted(\$db) !== 'either') {\n                return 'public';\n            }", '', $scnFoyerTiers);
check('P49 with the installation\'s limit no longer asked, an organisation\'s stored "all" shows every poster where only public posters are permitted',
    [$p['applied'], $p['result']['installation public, organisation all'] ?? null], [true, ['PWT S off', 'PWT S on']]);
$p = $plant('_core/NoticeboardFoyer.php', "if (\$value === 'all' && self::permitted(\$db) !== 'either') {", 'if (false) {', $scnFoyerWho);
check('P50 with the save no longer holding the organisation to what is permitted, its global administrator stores "all" beyond it',
    [$p['applied'], $p['result']['organisation choice beyond what is permitted'] ?? null], [true, 'all']);
$p = $plant('_apps/noticeboard/foyer-setup.php', 'NoticeboardFoyer::saveForOrganisation($mysqli, $siteId, $value, App::isSiteRootAdmin())',
    'NoticeboardFoyer::saveForOrganisation($mysqli, $siteId, $value, true)', $scnFoyerWho);
check('P51 with the page no longer asking for the organisation\'s global administrator, an ordinary administrator chooses for it',
    [$p['applied'], $p['result']['administrator chooses for the organisation'] ?? null], [true, 'all']);
$p = $plant('_apps/noticeboard/foyer-setup.php', 'NoticeboardFoyer::saveForInstallation($mysqli, $value, App::isRootAdmin())',
    'NoticeboardFoyer::saveForInstallation($mysqli, $value, true)', $scnFoyerWho);
check('P52 with the page no longer asking for the installation\'s global administrator, an organisation\'s administrator changes what the installation permits',
    [$p['applied'], $p['result']['administrator sets the installation'] ?? null], [true, 'either']);
$p = $plant('_apps/noticeboard/foyer-setup.php', 'if (App::isSiteAdmin() === false) {', 'if (false) {', $scnFoyerSetupGate);
check('P53 with the setup page\'s gate gone, a plain member opens it', [$p['applied'], $p['result']['member'] ?? null], [true, 200]);
$p = $plant('_apps/noticeboard/foyer.php', "header('Referrer-Policy: no-referrer');", '', static fn (): bool => ($scnFoyerHeaders()['no referrer'] ?? null) === true);
check('P54 with the no-referrer header gone, the screen would tell other websites its secret address', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/NoticeboardFoyer.php', '`token` = VALUES(`token`), `createdAt` = VALUES(`createdAt`)', '`createdAt` = VALUES(`createdAt`)', $scnFoyerReplace);
check('P55 with "make a new link" keeping the stored secret, the old link still works', [$p['applied'], $p['result']['old link'] ?? null], [true, ['PWT S on']]);
$p = $plant('_core/NoticeboardFoyer.php', "WHERE f.`token` = ? AND s.`isActive` = 1'", "WHERE f.`token` = ?'", $scnFoyerTwo);
check('P56 with "the organisation is switched on" dropped, a switched-off organisation\'s screen still shows its posters',
    [$p['applied'], $p['result']['two, organisation switched off'] ?? null], [true, ['PWT S two on']]);
$p = $plant('_core/NoticeboardFoyer.php', "'FROM `tblNoticeboardPosters` p WHERE p.`siteID` = ?'", "'FROM `tblNoticeboardPosters` p WHERE (p.`siteID` = ? OR 1 = 1)'", $scnFoyerTwo);
check('P57 with the organisation filter dropped from the poster list, one organisation\'s screen shows another\'s posters',
    [$p['applied'], $p['result']['one'] ?? null], [true, ['PWT S on', 'PWT S two on']]);
$p = $plant('_core/NoticeboardFoyer.php', 'new \DateTimeZone(Site::timezone($siteId))', "new \\DateTimeZone('UTC')", $scnFoyerToday);
check('P58 with "today" taken from UTC, the poster dates are judged on the wrong calendar', [$p['applied'], $p['result']], [true, ['PWT S utc today']]);
$p = $plant('_core/NoticeboardFoyer.php', "if (is_string(\$token) === false || preg_match(self::TOKEN_PATTERN, \$token) !== 1) {", 'if (is_string($token) === false) {', $scnFoyerNoKey);
check('P59 (measured, expected to stay GREEN) with the shape test dropped, a malformed secret is still refused — the lookup finds nothing',
    [$p['applied'], $p['result']['malformed'] ?? null], [true, [404, 'Not found.']]);
$p = $plant('_apps/noticeboard/foyer.php', "if (\$siteId === null || AppRegistry::isEnabledForSite('noticeboard', \$siteId) === false) {", 'if ($siteId === null) {', $scnFoyerSwitch);
check('P60 with the page no longer asking its own organisation\'s switch, a switched-off organisation\'s screen keeps playing',
    [$p['applied'], $p['result']['two switched off: two'] ?? null], [true, ['PWT S two on']]);
$p = $plant('_core/Router.php', "isset(AppRegistry::ROUTES_JUDGING_THEIR_OWN_ORGANISATION[\$path]) === false\n            && ", '', $scnFoyerSwitch);
check('P61 with the router applying the address\'s switch again, organisation 1 switching off stops another organisation\'s screen',
    [$p['applied'], $p['result']['one switched off: two'] ?? null], [true, 403]);
$p = $plant('_apps/noticeboard/foyer.php', "\$_GET['token'] ?? null", "\$_GET['k'] ?? null", $scnFoyerScrubbed);
check('P62 with the screen reading a parameter the setup page does not write, the link it shows no longer opens the screen',
    [$p['applied'], $p['result']['link opens the screen'] ?? null], [true, 404]);
$p = $plant('_apps/noticeboard/foyer-setup.php', "'noticeboard/foyer?token=' . \$token", "'noticeboard/foyer?k=' . \$token", $scnFoyerScrubbed);
check('P63 with the link\'s parameter renamed away from "token", the secret survives into what the log scrubber records',
    [$p['applied'], $p['result']['secret left in what is recorded'] ?? null], [true, true]);
$p = $plant('_apps/noticeboard/foyer.php', "header('Cache-Control: no-store, private');", "header('Cache-Control: no-store');",
    static fn (): bool => ($scnFoyerHeaders()['not stored'] ?? null) === true);
check('P65 with "private" dropped, the portal\'s service worker may keep a copy of the screen (round 3)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/noticeboard/foyer.php', "header('X-Portal-Foyer: 1');", '', $scnFoyerReload);
check('P64 with the page no longer marking its answers, its own 404 cannot be told from anybody else\'s (round 2)',
    [$p['applied'], $p['result']['marks its 404'] ?? null], [true, false]);
// #493 P3: Admin → Public website's guards, each taken out of the copy. The
// value each check expects is the value UNDER THE FAULT.
$p = $plant('_core/PublicWebsiteSetup.php', 'if (self::isGlobalAdministratorOf($db, $userId, $siteId) === true) {
            return true;
        }

        return Capabilities::allows(', 'if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === true) {
            return true;
        }

        return Capabilities::allows(', $scnPwGate);
check('P66 with "a global administrator" widened to any administrator of the organisation, an ordinary administrator opens Admin → Public website',
    [$p['applied'], $p['result']['orgadmin'] ?? null], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', 'return Capabilities::allows($db, $userId, $siteId, Capabilities::WEBSITE_SETUP, false);', 'return true;', $scnPwGate);
check('P67 with the permission no longer asked, a plain member opens it', [$p['applied'], $p['result']['member'] ?? null], [true, 200]);
$p = $plant('_core/PublicWebsiteSetup.php', "'AND s.`isSiteRootAdmin` = 1))'", "'AND (s.`isSiteRootAdmin` = 1 OR s.`isSiteAdmin` = 1)))'", $scnPwGate);
check('P68 with an organisation\'s ordinary administrator counted as its global administrator, they open it', [$p['applied'], $p['result']['orgadmin'] ?? null], [true, 200]);
$p = $plant('_apps/admin/public-website/index.php', 'if (PublicWebsiteSetup::maySetUp($mysqli, $userId, $siteId) === false) {', 'if (false) {', $scnPwGate);
check('P69 with the page\'s own gate gone, a member opens it', [$p['applied'], $p['result']['member'] ?? null], [true, 200]);
$p = $plant('_apps/admin/public-website/save.php', 'if (PublicWebsiteSetup::maySetUp($mysqli, $userId, $siteId) === false) {', 'if (false) {', $scnPwSaveGate);
check('P70 with the save handler\'s gate gone, an ordinary administrator stores a main website', [$p['applied'], $p['result']['nothing stored'] ?? null],
    [true, 'https://www.pwt-orgadmin.example.org']);
$p = $plant('_apps/admin/public-website/check.php', 'if (PublicWebsiteSetup::maySetUp($mysqli, $userId, $siteId) === false) {', 'if (false) {', $scnPwSaveGate);
check('P71 with the check button\'s gate gone, a member runs it', [$p['applied'], $p['result']['check answered'] ?? null], [true, 302]);
$p = $plant('_apps/admin/public-website/check.php', "if (Auth::verifyCsrf(\$_POST['csrf_token'] ?? '') === false) {", 'if (false) {', $scnPwCheck);
check('P72 with the check button\'s form token no longer asked, a post with a wrong token runs it', [$p['applied'], ($p['result']['no token, nothing run'] ?? []) !== []], [true, true]);
$p = $plant('_core/PublicWebsiteSetup.php', "\$rows[] = ['label' => 'The public door', 'state' => 'untested',", "\$rows[] = ['label' => 'The public door', 'state' => 'ok',", $scnPwCheck);
check('P73 with the door row ticked, the check would claim the public door works', [$p['applied'], $p['result']['the door is still not ticked'] ?? null], [true, true]);
$p = $plant('_apps/admin/public-website/check.php',
    'if (RateLimiter::tooMany($bucket, PublicWebsiteSetup::CHECK_LIMIT, PublicWebsiteSetup::CHECK_WINDOW_SECONDS) === true) {', 'if (false) {', $scnPwCheckLimit);
check('P74 with the limit gone, the check runs a seventh time', [$p['applied'], $p['result']['checks that ran'] ?? null], [true, 7]);
$p = $plant('_core/PublicWebsiteSetup.php', ["&& strtolower(\$m[1]) !== 'https') {", "if (str_starts_with(\$checked['address'], 'https://') === false) {"],
    ["&& false) {", 'if (false) {'], $scnPwFrames);
check('P75 with "https only" gone, a plain-http framing website is stored', [$p['applied'], $p['result']['refused: http'] ?? null], [true, false]);
$p = $plant('_core/PublicWebsiteSetup.php', 'if (count($origins) > self::MAX_FRAME_ORIGINS) {', 'if (false) {', $scnPwFrames);
check('P76 with the cap of twenty gone, twenty-one framing websites are stored', [$p['applied'], $p['result']['21 refused'] ?? null], [true, false]);
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$value !== '' && \$permitted !== null && \$permitted !== 'yes') {", 'if (false) {', $scnPwSwitch);
check('P77 with the installation\'s switch no longer asked at save, a list is stored while framing is forbidden',
    [$p['applied'], $p['result']['a non-empty list refused while off'] ?? null], [true, 'https://new.pwt.example.org']);
$p = $plant('_core/PublicWebsiteSetup.php', "        if (self::framingPermitted(\$db) === false) {\n            return [];\n        }\n\n        return self::frameOriginsStored(", "        return self::frameOriginsStored(", $scnPwSwitch);
check('P78 with the installation\'s switch no longer asked at read, a list is in force while framing is forbidden',
    [$p['applied'], $p['result']['read: none whatever the list says'] ?? null], [true, 'https://kept.pwt.example.org']);
$p = $plant('_core/PublicWebsiteSetup.php', "'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ? LIMIT 1' . (\$lock === true ? ' FOR UPDATE' : '')",
    "'SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND (`siteID` = ? OR `siteID` IS NULL) ORDER BY `siteID` IS NULL LIMIT 1' . (\$lock === true ? ' FOR UPDATE' : '')", $scnPwOrgOnly);
check('P79 with an installation row read as the organisation\'s, the installation\'s framing list is in force for an organisation that chose none',
    [$p['applied'], $p['result']['installation row ignored'] ?? null], [true, 'https://installation.pwt.example.org']);
$p = $plant('_core/PublicWebsiteSetup.php', 'if (in_array($host, self::hostsMembersUse($db, $requestHost), true) === true) {', 'if (false) {', $scnPwHost);
check('P80 with "an address members sign in at" no longer refused, the portal\'s own address is stored as the public address',
    [$p['applied'], $p['result']['the portal\'s own address refused'] ?? null], [true, 'portal.pwt.example.org']);
$p = $plant('_apps/admin/public-website/save.php', 'PublicWebsiteSetup::saveFramingPermitted($mysqli, $value, App::isRootAdmin(), $actorId)',
    'PublicWebsiteSetup::saveFramingPermitted($mysqli, $value, true, $actorId)', $scnPwSwitch);
check('P81 with the page no longer asking for the installation\'s global administrator, an organisation\'s global administrator forbids framing for everybody',
    [$p['applied'], $p['result']['organisation\'s global administrator cannot switch it'] ?? null], [true, 'no']);
$p = $plant('_core/PublicWebsiteSetup.php', "return self::mayHandOut(\$db, \$actorId, \$siteId) === true ? '' : self::HAND_OUT_REFUSED;", "return '';", $scnPwRolesPage);
check('P82 (D-093) with the Roles page\'s rule gone, an ordinary administrator re-points "Set up the public website" at a role they could hold',
    [$p['applied'], $p['result']['administrator re-points it'] ?? null], [true, 'volunteer']);
$p = $plant('_apps/admin/roles/save.php', "if (PublicWebsiteSetup::whyRoleChangeRefused(\$mysqli, (int) (\$actorId ?? 0), \$siteId, [(string) \$roleKeyRow['roleKey']]) !== '') {",
    'if (false) {', $scnPwRolesPage);
check('P83 (D-093) with the delete branch\'s rule gone, an ordinary administrator deletes a role that opens it', [$p['applied'], $p['result']['administrator deletes a role that opens it'] ?? null], [true, 0]);
$p = $plant('_apps/admin/roles/save.php', "if (PublicWebsiteSetup::whyMappingChangeRefused(\$mysqli, (int) (\$actorId ?? 0), \$siteId, \$capability) !== '') {\n        \$refuseHandOut('Roles page, back to the role it came with');",
    "if (false) {\n        \$refuseHandOut('Roles page, back to the role it came with');", $scnPwRolesPage);
check('P84 (D-093) with "back to the role it came with" no longer asking, an ordinary administrator forgets the global administrator\'s choice',
    [$p['applied'], $p['result']['administrator forgets the choice'] ?? null], [true, null]);
$p = $plant('_apps/admin/users/roles-save.php', "if (\$handOutRefused !== '') {", 'if (false) {', $scnPwUsersPage);
check('P85 (D-093) with the Users page\'s rule gone, an ordinary administrator gives somebody Website Set-up',
    [$p['applied'], $p['result']['administrator gives it (whole save refused)'] ?? null], [true, [true, true]]);
$p = $plant('_apps/admin/users/index.php', "        carry.disabled = held.indexOf(carry.value) === -1;\n", '', $scnPwUsersPage);
check('P86 (measured, expected to stay GREEN) with the locked box\'s carrier never switched on, the browser would send no answer for it — nothing here runs the page\'s script, so only the handler\'s refusal is proved',
    [$p['applied'], $p['result']['administrator changes a holder\'s other roles'] ?? null], [true, [true, true]]);
// #493 P4a added D-087's two test-copy keys to the list (on a second line);
// this plant still takes out exactly the three P3 keys it is about. #882
// (D-129) added the no-end-time key to the same second line.
$p = $plant('_core/PublicWebsite.php', "public const LOCKED_KEYS = [self::RULE_KEY, self::WEBSITE_ADDRESS_KEY, self::FRAME_ORIGINS_KEY, self::FRAME_PERMITTED_KEY,\n        self::DOOR_TEST_PERMITTED_KEY, self::DOOR_TEST_OPEN_KEY, self::NO_END_LISTING_KEY];",
    'public const LOCKED_KEYS = [self::RULE_KEY, self::DOOR_TEST_PERMITTED_KEY, self::DOOR_TEST_OPEN_KEY, self::NO_END_LISTING_KEY];', $scnPwLocked);
check('P87 with the three keys taken out of the editor lock, the general settings editor would store them', [$p['applied'], $p['result']], [true, [false, false, false]]);
$p = $plant('_core/templates/nav.php', '<?php if (App::isAdmin() !== true && $navMaySetUpWebsite === true): ?>', '<?php if (App::isAdmin() !== true): ?>', $scnPwMenu);
check('P88 with the menu link no longer asking the gate, a member is shown a way to a page that refuses them', [$p['applied'], $p['result']['member'] ?? null], [true, 1]);
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$one['problem'] !== '' || \$one['origin'] !== trim(\$line)) {\n                \$dropped++;\n                continue;\n            }",
    '', $scnPwHandEdited);
check('P89 with the stored list no longer checked again on read, the page and the check would show a hand edit\'s spoiled lines as valid entries (round 1: this plant used to stay green, because it only asked the header builder)',
    [$p['applied'], count($p['result']['what the check says about framing'] ?? []) > 1], [true, true]);
// #493 P4a (D-101) added the two approval columns to this INSERT, so the
// plant names the statement as it now stands.
// D-121 (#895): P91 used to plant an UPSERT here, which took another
// organisation's name through the old unique key on the name. Migration 276
// replaced that key (a name is unique only once APPROVED, and a request is
// never approved), so an upsert can no longer meet another organisation's row
// and the plant could not fail; the "never upsert" rule is kept in the code
// for a database that has not yet run 276, which this test cannot build. The
// rule that now stops a taken name is the save's own refusal, planted here.
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$others['approvedSiteId'] !== null) {\n                    return self::refusedAndRecorded('public address',",
    "if (false) {\n                    return self::refusedAndRecorded('public address',", $scnPwHostInsert);
check('P91 with the save no longer refusing a name another organisation is APPROVED for, choosing it stores a request for it (W19 catches it)', [$p['applied'], $p['result']['this organisation'] ?? null], [true, 'shared.pwt.example.org']);
$p = $plant('_core/PublicWebsiteSetup.php', "\$stmt = \$was !== '' || self::hasHostRow(\$db, \$siteId) === true", "\$stmt = \$was !== ''", $scnPwHostInsert);
check('P92 with a spoiled row not looked for, replacing it is refused as a clash and the spoiled row stays', [$p['applied'], $p['result']['a spoiled row is replaced'] ?? null], [true, 'NOT A HOST!']);
$p = $plant('_core/PublicWebsite.php', "        return ['route' => 'admin/public-website', 'name' => 'Admin, Public website',\n            'who' => 'a global administrator,",
    "        return ['route' => 'admin/calendar/feeds', 'name' => 'Admin, Outside calendars',\n            'who' => 'a global administrator,", $scnPwEditorPage);
check('P93 with every public-website key sent to Admin, Outside calendars, the settings editor names the wrong page again',
    [$p['applied'], $p['result']['installation\'s global administrator'][2] ?? null], [true, 'link to admin/calendar/feeds — Changed at Admin, Outside calendars']);
$p = $plant('_core/PublicWebsiteSetup.php', "            'deadline'              => microtime(true) + self::CHECK_DEADLINE_SECONDS,\n", '', $scnPwCheckOptions);
check('P95 with the overall deadline no longer in the options, the check would give no 15-second limit', [$p['applied'], $p['result']['overall deadline, seconds ahead'] ?? 'missing'], [true, 'none']);
$p = $plant('_core/PublicWebsiteSetup.php', "SafeFetch::get('https://' . \$host . '/', self::mainWebsiteFetchOptions())", "SafeFetch::get('https://' . \$host . '/', ['httpsOnly' => true])", $scnPwCheckOptions);
check('P98 (round 3\'s plant A) with the real call handed other options, the deadline is gone from the REAL path — W22 records what SafeFetch is really given. (Plant A as first written, a default closure ignoring the options, cannot be written any more: there is no hook and no closure.)',
    [$p['applied'], $p['result']['overall deadline, seconds ahead'] ?? 'missing'], [true, 'none']);
$p = $plant('_core/PublicWebsiteSetup.php', "        if (function_exists('curl_init') === false) {", "        if (false) {", $scnPwNoCurl);
check('P99 (rounds 3 and 4: plants B and C) with the no-curl branch never taken, a server without curl is told its website gave no normal page — W23 drives the real path, so this now goes red. (Plant C as first written, a test parameter defaulted to "curl is here", cannot be written any more: the parameter is gone.)',
    [$p['applied'], $p['result']['what it says'][0] ?? null], [true, 'problem: https://a.test: ' . $pwNotNormal]);
$p = $plant('_core/PublicWebsiteSetup.php', "            . (\$status > 0 ? ' The last answer this check received was HTTP ' . \$status . '.' : '')];", "];", $scnPwLive);
check('P96 with the status fact dropped, a 403 is no longer reported as one', [$p['applied'], $p['result']['forbidden'] ?? null], [true, [403, 'note', $pwNotNormal]]);
// A plant that could not be applied is a failure OF THE PLANT, said as such —
// never a red (or green) result standing in for the scenario (round 7).
$plantCheck = static function (string $label, array $p, callable $pick, mixed $want): void {
    if ($p['applied'] !== true) {
        check('PLANT COULD NOT BE APPLIED (its text was not found exactly once): ' . $label, false, true);
        return;
    }
    check($label, $pick($p['result']), $want);
};
$pwStateRule = "in_array(\$status, self::TURNED_AWAY_STATUSES, true) === true ? 'note' : 'problem'";
$p = $plant('_core/PublicWebsiteSetup.php', $pwStateRule, "'problem'", $scnPwLive);
$plantCheck('P100 (round 4) with every non-200 marked "Needs attention", a website that DID answer with a 403 is shown as a problem rather than for information',
    $p, static fn (array $r): mixed => $r['forbidden'][1] ?? null, 'problem');
$p = $plant('_core/PublicWebsiteSetup.php', $pwStateRule, "\$status >= 400 ? 'note' : 'problem'", $scnPwLive);
$plantCheck('P101 (round 5) with the line back at "400 or more", a website whose server answers 503 — it IS down — is shown for information rather than as needing attention',
    $p, static fn (array $r): mixed => $r['down (503)'][1] ?? null, 'note');
$p = $plant('_core/PublicWebsiteSetup.php', $pwStateRule, "(\$status >= 400 && \$status <= 499) ? 'note' : 'problem'", $scnPwLive);
$plantCheck('P102 (round 6) with the line at "400-499", a 404 — often a lapsed or misconfigured domain — is shown for information rather than as needing attention',
    $p, static fn (array $r): mixed => $r['no site here (404)'][1] ?? null, 'note');
$p = $plant('_core/PublicWebsiteSetup.php', $pwStateRule, "(\$status >= 400 && \$status !== 500 && \$status !== 503) ? 'note' : 'problem'", $scnPwVerdicts);
$plantCheck('P103 (round 6, the checker\'s plant) with only 500 and 503 kept as "Needs attention", every other 5xx — 502 and 504, the commonest proxy "down" answers, among them — would read "For information"; W17 asks every status',
    $p, static fn (array $r): mixed => [$r['HTTP 502'][0] ?? null, $r['HTTP 504'][0] ?? null], ['note', 'note']);
$p = $plant('_core/PublicWebsiteSetup.php', 'public const TURNED_AWAY_STATUSES = [403, 429];', 'public const TURNED_AWAY_STATUSES = [403, 404, 429];', $scnPwVerdicts);
$plantCheck('P104 (round 6) with 404 added to the "turned away" list, W17 notices the list changed', $p, static fn (array $r): mixed => $r['HTTP 404'][0] ?? null, 'note');
$p = $plant('_core/PublicWebsiteSetup.php', 'public const TURNED_AWAY_STATUSES = [403, 429];', 'public const TURNED_AWAY_STATUSES = [401, 403, 429];', $scnPwVerdicts);
$plantCheck('P105 (round 7) with 401 back in the "turned away" list, a website asking every visitor for a password would read "For information"', $p,
    static fn (array $r): mixed => $r['HTTP 401'][0] ?? null, 'note');
$p = $plant('_core/PublicWebsiteSetup.php', "        \$status = (int) (\$got['status'] ?? 0);\n        if (function_exists('curl_init') === false) {",
    "        \$status = (int) (\$got['status'] ?? 0);\n        if (\$status === 451 || \$status === 501) {\n            return ['state' => 'note', 'words' => self::NOT_A_NORMAL_PAGE . ' The last answer this check received was HTTP ' . \$status . '.'];\n        }\n        if (function_exists('curl_init') === false) {",
    $scnPwVerdicts);
$plantCheck('P106 (round 7, the checker\'s plant) with 451 and 501 singled out as "For information", W17 — which asks every status — notices', $p,
    static fn (array $r): mixed => [$r['HTTP 451'][0] ?? null, $r['HTTP 501'][0] ?? null], ['note', 'note']);
$p = $plant('_core/PublicWebsiteSetup.php', "        \$status = (int) (\$got['status'] ?? 0);\n        if (function_exists('curl_init') === false) {",
    "        \$status = (int) (\$got['status'] ?? 0);\n        if (\$status >= 601) {\n            return ['state' => 'note', 'words' => self::NOT_A_NORMAL_PAGE . ' The last answer this check received was HTTP ' . \$status . '.'];\n        }\n        if (function_exists('curl_init') === false) {",
    $scnPwVerdicts);
$plantCheck('P107 (round 8, the checker\'s plant) with every status from 601 up called "For information", W17 — which now asks up to 999 — notices', $p,
    static fn (array $r): mixed => [$r['HTTP 601'][0] ?? null, $r['HTTP 999'][0] ?? null], ['note', 'note']);
$p = $plant('_core/PublicWebsiteSetup.php', "        \$status = (int) (\$got['status'] ?? 0);\n        if (function_exists('curl_init') === false) {",
    "        \$status = (int) (\$got['status'] ?? 0);\n        if (\$status > 0 && \$status < 100) {\n            return ['state' => 'note', 'words' => self::NOT_A_NORMAL_PAGE . ' The last answer this check received was HTTP ' . \$status . '.'];\n        }\n        if (function_exists('curl_init') === false) {",
    $scnPwVerdicts);
$plantCheck('P108 (round 9, the checker\'s plant) with every status from 1 to 99 called "For information", W17 — which now asks from 0 — notices', $p,
    static fn (array $r): mixed => [$r['HTTP 1'][0] ?? null, $r['HTTP 42'][0] ?? null, $r['HTTP 99'][0] ?? null], ['note', 'note', 'note']);
$p = $plant('_core/PublicWebsiteSetup.php', "if (\$status === 200 && ((\$got['ok'] ?? false) === true || (\$got['capped'] ?? false) === true)) {", 'if ($status === 200) {', $scnPwLive);
check('P97 with any 200 counted as answered, a 200 whose connection was cut off is said to have answered', [$p['applied'], $p['result']['cut off'] ?? null], [true, [200, 'ok', 'it answered.']]);
$p = $plant('_apps/admin/roles/index.php', '<?php if ($capFallsOpen === true && $capKey === Capabilities::WEBSITE_SETUP): ?>', '<?php if (false): ?>', $scnPwRolesWarning);
check('P90 with Website Set-up given the usual sentence, the Roles page tells an ordinary administrator they still get in, which is untrue for this door',
    [$p['applied'], str_starts_with((string) $p['result'], 'WRONG')], [true, true]);
$foyerWorld();
$setEv($cEvent, ['showOnWebsite' => '0']);
// ── Section G's guards (#885, #853, #854, #855) ─────────────────────────────
$kApiWant = ['no such category' => [422, null], 'another organisation\'s category' => [422, null], 'the same words for both' => true];
$p = $plant('_apps/events/api/create.php', '$taxonomyRefusal = Events::whyCategoryOrTypeRefused(App::db(), Site::id(), $categoryId, $typeId);',
    '$taxonomyRefusal = null;', static fn (): bool => $scnApiOtherCategory() === $kApiWant);
check('PG1 with the events API no longer asking whose category it is, another organisation\'s is stored and a missing one is a 500 again (#885)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/Events.php', "'SELECT 1 FROM `tblEventCategories` WHERE `categoryID` = ? AND `siteID` = ? LIMIT 1'",
    "'SELECT 1 FROM `tblEventCategories` WHERE `categoryID` = ? AND ? > 0 LIMIT 1'", static fn (): bool => $scnApiOtherCategory() === $kApiWant);
check('PG2 with the ONE rule asking only whether the category exists, another organisation\'s is accepted and the two answers differ (#885)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/events/api/update.php', "        if (\$taxonomyRefusal !== null) {\n            ApiResponse::error(\$taxonomyRefusal . ' Nothing was changed.', 422);",
    "        if (false) {\n            ApiResponse::error(\$taxonomyRefusal . ' Nothing was changed.', 422);",
    static fn (): bool => ($scnApiUpdateOtherType()['another organisation\'s type'] ?? null) === 422);
check('PG3 with the update\'s refusal gone, another organisation\'s event type is stored (#885)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/manage/save.php', 'if ($taxonomyRefusal !== null) {', 'if (false) {', static fn (): bool => $scnFormOtherCategory() === null);
check('PG4 with the form\'s refusal gone, the Manage Events form stores another organisation\'s category (#885)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/manage/series-edit.php', 'if ($bulkTaxonomyRefusal !== null) {', 'if (false) {', static fn (): bool => $scnBulkOtherCategory() === null);
check('PG5 with the bulk edit\'s refusal gone, a series\' dates take another organisation\'s category (#885)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/submit-save.php', 'if ($categoryArg === false || Events::whyCategoryOrTypeRefused($mysqli, $siteId, $categoryArg, null) !== null) {',
    'if ($categoryArg === false) {', static fn (): bool => ($scnSubmitOtherCategory()['stored'] ?? 'x') === null);
check('PG6 with the suggestion form no longer asking whose category it is, a suggestion stores another organisation\'s (#885)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/admin/calendar/feeds-view.php', "<?php if ((int) \$ev['onWebsite'] === 1): ?>",
    "<?php if ((int) \$ev['importWebsite'] === 1 && EventVisibility::copiedTickCounts(\$ev['importSource'] === null ? null : (string) \$ev['importSource'], \$copiedRule) === true): ?>",
    static fn (): bool => ($scnBadgeFollowsWebsite()['paused'][1] ?? null) === 'held' && ($scnBadgeFollowsWebsite()['answer run out'][1] ?? null) === 'held');
check('PG7 with the badge back on the website box and the tick rule alone (the code before #853), a paused calendar\'s event and a stale one say "On the public website"', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/admin/calendar/feeds-choice.php', "\$newForEveryDate = (\$scope === 'series');", '$newForEveryDate = false;',
    static fn (): bool => ($scnSeriesUntouched()['the other date'] ?? null) === 'groups');
check('PG8 with a new choice for every date pre-filled from the opened date again (the code before #854), an untouched Save opens every date to Members', [$p['applied'], $p['result']], [true, false]);
// (decide() passes no after-resolve step since #855 round 4, so the call ends
// `}, null, true);` again; the plant turns its `$resolveFirst` off.)
$p = $plant('_core/FeedAdmin.php', '}, null, true);', '}, null, false);',
    static fn (): bool => ($scnStaleApproval()['that request approved'] ?? null) === false);
check('PG9 with the decision no longer re-working the calendar first (the code before #855), the request made under the looser rule is approved as if it still counted the website', [$p['applied'], $p['result']], [true, false]);
// Round 2 of #885's check: a writer that refuses the organisation's OWN
// numbers, by swapping the rule's two arguments.
$p = $plant('_apps/events/api/create.php', 'Events::whyCategoryOrTypeRefused(App::db(), Site::id(), $categoryId, $typeId)',
    'Events::whyCategoryOrTypeRefused(App::db(), Site::id(), $typeId, $categoryId)', static fn (): bool => $scnApiCreateOwnBoth() === [201, $kOwnCat2, $kOwnType]);
check('PG10 with the events API asking the rule with its arguments swapped, its own category and type are refused (G12 catches it)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/events/api/update.php', "            \$col === 'categoryID' ? \$value : null,\n            \$col === 'typeID' ? \$value : null",
    "            \$value,\n            \$value", static fn (): bool => $scnApiUpdateOwn() === [200, 200, $kOwnCat2, $kOwnType]);
check('PG11 with the update passing its one number as BOTH arguments, its own type is refused (G13 catches it)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/manage/save.php', '\Portal\Core\Events::whyCategoryOrTypeRefused($mysqli, Site::id(), $categoryID, $typeID)',
    '\Portal\Core\Events::whyCategoryOrTypeRefused($mysqli, Site::id(), $typeID, $categoryID)', static fn (): bool => $scnFormOwnBoth() === [$kOwnCat2, $kOwnType]);
check('PG12 with the Manage Events form testing the category against the TYPES (arguments swapped), its own category is refused (G15 catches it)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/manage/series-edit.php', '\Portal\Core\Events::whyCategoryOrTypeRefused($mysqli, $siteId, $catVal, $typeVal)',
    '\Portal\Core\Events::whyCategoryOrTypeRefused($mysqli, $siteId, $typeVal, $catVal)',
    static fn (): bool => $scnBulkOwn() === ['set' => [$kOwnCat2, $kOwnType], 'None' => [null, null]]);
check('PG13 with the bulk edit testing the category against the TYPES, its own category is refused (G16 catches it)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/submit-save.php', 'Events::whyCategoryOrTypeRefused($mysqli, $siteId, $categoryArg, null)',
    'Events::whyCategoryOrTypeRefused($mysqli, $siteId, null, $categoryArg)', static fn (): bool => $scnSubmitOwn() === $kOwnCat2);
check('PG14 with the suggestion form testing the category against the TYPES, its own category is refused (G17 catches it)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_core/Events.php', "        if (\$raw === null || \$raw === '' || \$raw === 0 || \$raw === '0') {",
    "        if (\$raw === null || \$raw === '' || \$raw === 0) {",
    static fn (): bool => $scnBulkOwn() === ['set' => [$kOwnCat2, $kOwnType], 'None' => [null, null]]);
check('PG15 with "0" no longer read as none, the bulk edit\'s "— None —" is refused instead of clearing (G16 catches it)', [$p['applied'], $p['result']], [true, false]);
// Round 3 of #885's check: a writer that stops passing the TYPE to the rule.
$p = $plant('_apps/events/api/create.php', 'Events::whyCategoryOrTypeRefused(App::db(), Site::id(), $categoryId, $typeId)',
    'Events::whyCategoryOrTypeRefused(App::db(), Site::id(), $categoryId, null)', static fn (): bool => $scnCreateOtherType() === [422, null]);
check('PG16 with the events API no longer passing the type to the rule, another organisation\'s type is stored on create (G18 catches it)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/manage/save.php', '\Portal\Core\Events::whyCategoryOrTypeRefused($mysqli, Site::id(), $categoryID, $typeID)',
    '\Portal\Core\Events::whyCategoryOrTypeRefused($mysqli, Site::id(), $categoryID, null)', static fn (): bool => $scnFormOtherType() === null);
check('PG17 with the Manage Events form no longer passing the type, another organisation\'s type is stored (G19 catches it)', [$p['applied'], $p['result']], [true, false]);
$p = $plant('_apps/calendar/manage/series-edit.php', '\Portal\Core\Events::whyCategoryOrTypeRefused($mysqli, $siteId, $catVal, $typeVal)',
    '\Portal\Core\Events::whyCategoryOrTypeRefused($mysqli, $siteId, $catVal, null)', static fn (): bool => $scnBulkOtherType() === null);
check('PG18 with the bulk edit no longer passing the type, another organisation\'s type is set on the series\' dates (G20 catches it)', [$p['applied'], $p['result']], [true, false]);

check('P11 with every plant removed again, the unticked event is off the door\'s events feed once more', $scnUnticked(), true);

// 🚪 #493 P4b: every read of the door's events feed must have ANSWERED. A read
//    that did not is counted as "no event" by $countdownNext, so without this
//    check every "not shown" check above would pass on a broken door.
check('Z1 every one of the ' . $doorReads . ' reads of the public door\'s events feed answered 200 in the events format (none of the "not shown" checks passed on a door that answered nothing)',
    [$doorReads > 20, $doorReadsFailed], [true, 0]);

// 🧾 The server's own error log. A page that throws part-way can still answer
//    200, so the log is read as well.
$serverLog = (string) @file_get_contents($child . $sep . 'php-errors.log');
$ours      = [];
// #493 P3: W18 and W19 plant a hand-spoiled framing list and public address on
// purpose, and the readers' job is to drop them AND say so in this log — so
// exactly those two notices are expected and set aside. Anything else naming
// PublicWebsite still fails this check.
$expectedNotices = ['[WebMS-Intra] PublicWebsiteSetup: organisation #' . $siteOne . '\'s stored framing list held ',
    '[WebMS-Intra] PublicWebsiteSetup: organisation #' . $siteOne . '\'s stored public address is not one this page would accept'];
$seenNotices = [];
foreach (preg_split('/\R/', $serverLog) ?: [] as $line) {
    $deliberate = false;
    foreach ($expectedNotices as $i => $notice) {
        if (str_contains($line, $notice) === true) {
            $deliberate = true;
            $seenNotices[$i] = true;
        }
    }
    if ($deliberate === true) {
        continue;
    }
    if (stripos($line, 'Fatal') !== false || stripos($line, 'PublicWebsite') !== false || stripos($line, 'Uncaught') !== false) {
        $ours[] = $line;
    }
}
ksort($seenNotices);
check('Z1 the server logged no fatal error and nothing about PublicWebsite beyond the two hand-edit notices section W causes on purpose — and it logged BOTH of them',
    [$ours, array_keys($seenNotices)], [[], [0, 1]]);

// ── Tidy up the rows this test made ─────────────────────────────────────────
if ($indexWas === null) {
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` = 'site.allowIndexing' AND `siteID` IS NULL");
} else {
    $iw = $db->prepare("UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = 'site.allowIndexing' AND `siteID` IS NULL");
    $iwValue = (string) $indexWas['settingValue'];
    $iw->bind_param('s', $iwValue);
    $iw->execute();
    $iw->close();
}
if ($indexOwn !== null) {
    $io = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, 'site.allowIndexing', ?, 'false', 0)");
    $ioValue = (string) $indexOwn['settingValue'];
    $io->bind_param('is', $siteOne, $ioValue);
    $io->execute();
    $io->close();
}

// Section W's rows (#493 P3): settings, public addresses, the check's limit,
// capability choices and the test's own custom role.
$pwClean();
$db->query("DELETE FROM `tblRoles` WHERE `siteID` = {$siteOne} AND `roleKey` = 'pwt_custom'");
$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'PWT %' OR `siteID` = {$siteTwo}");
// Section E's send-log rows (#859): only this test's own made-up addresses.
$db->query("DELETE FROM `tblEmailLog` WHERE (`toRecipients` LIKE '%@selftest.invalid' OR `toRecipients` = 'not-an-address') AND `subject` = 'Your event suggestion has been approved'");
$db->query("DELETE FROM `tblExternalEventApprovals` WHERE `snapTitle` LIKE 'PWT approval %'");
$db->query("DELETE FROM `tblExternalFeedRules` WHERE `name` = 'PWT rule'");
$db->query("DELETE FROM `tblExternalFeeds` WHERE `name` = 'PWT outside calendar'");
// Section G's rows (#885, #854, #855): its events went with every 'PWT %' event above.
$db->query("DELETE FROM `tblExternalEventApprovals` WHERE `feedID` IN (SELECT `feedID` FROM `tblExternalFeeds` WHERE `name` LIKE 'PWT K %')");
$db->query("DELETE FROM `tblExternalEventChoices` WHERE `feedID` IN (SELECT `feedID` FROM `tblExternalFeeds` WHERE `name` LIKE 'PWT K %')");
$db->query("DELETE FROM `tblExternalFeeds` WHERE `name` LIKE 'PWT K %'");
$db->query("DELETE FROM `tblEventSeries` WHERE `seriesSlug` = 'pwt-k-series'");
$db->query("DELETE FROM `tblEventCategories` WHERE `categorySlug` LIKE 'pwt-k-%'");
$db->query("DELETE FROM `tblEventTypes` WHERE `typeSlug` LIKE 'pwt-k-%'");
$setRule($ruleWas ?? 'event', null);
$db->query("DELETE FROM `tblSettings` WHERE `siteID` = {$siteTwo}");
// Section S's rows (#493 P2b).
$db->query("DELETE FROM `tblNoticeboardPosters` WHERE `title` LIKE 'PWT S %'");
$db->query("DELETE FROM `tblNoticeboardFoyer` WHERE `siteID` IN ({$siteOne}, {$siteTwo})");
$foyerSet('public', null);
try {
    $db->query("DELETE FROM `tblSites` WHERE `siteID` = {$siteTwo}");
} catch (Throwable $e) {
    echo "  ! could not remove the second organisation ({$siteTwo}); it is harmless in a selftest database.\n";
}
$db->query("DELETE FROM `tblApiKeys` WHERE `keyID` = {$apiKeyId}");
foreach ($people as $person) {
    $removeMember((int) $person['id']);
}

$total = $passed + $failed;
echo "\n{$total} checks: {$passed} passed, {$failed} failed (expected " . PWT_EXPECTED_CHECKS . ").\n";
echo "It drives the REAL pages over HTTP and reads every result back from the database; one engine, one web worker.\n";
exit($failed === 0 && $total === PWT_EXPECTED_CHECKS ? 0 : 1);
